<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !str_ends_with((string)getenv('MYSQL_TEST_DATABASE'), '_repair_test')) {
    fwrite(STDERR, "Use MYSQL_TEST_* with an empty disposable database ending in _repair_test.\n");
    exit(2);
}
require_once __DIR__.'/../app/bootstrap.php';
$config = ['host'=>getenv('MYSQL_TEST_HOST') ?: '127.0.0.1', 'port'=>(int)(getenv('MYSQL_TEST_PORT') ?: 3306),
    'database'=>getenv('MYSQL_TEST_DATABASE'), 'username'=>getenv('MYSQL_TEST_USER') ?: 'root',
    'password'=>getenv('MYSQL_TEST_PASSWORD') ?: '', 'game_speed'=>1];
$pdo = Db::connect($config);
Db::useConnection($pdo, $config);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=8');
if (($argv[1] ?? '') === '--child') {
    $request = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($argv[3], (string)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
    try {
        $result = $request['action'] === 'attack'
            ? BattleService::start($request['user'], $request['input']['targetId'])
            : Game::mutate($request['user'], $request['action'], $request['input']);
        echo json_encode(['ok'=>true, 'result'=>$result], JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        echo json_encode(['ok'=>false, 'error'=>$error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
    throw new RuntimeException('Home repair concurrency requires an empty disposable database.');
}
$checks = 0;
$users = [];
$children = [];
function repairRaceCheck(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: '.$message); }
    ++$checks;
    echo "PASS $message\n";
}
function repairRaceKeeper(): int
{
    global $users;
    $pdo = Db::pdo();
    $q = $pdo->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,0,?)');
    $q->execute(['RepairRace '.bin2hex(random_bytes(5)), time()]);
    $id = (int)$pdo->lastInsertId();
    $users[] = $id;
    $base = Game::initialBase($id);
    $base['buildings'] = [];
    foreach ([['townhall',16,16], ['flinger',4,4], ['sniper',24,18], ['wall',3,12], ['trap',26,8], ['wall',3,15]] as $index=>[$type,$x,$y]) {
        $base['buildings'][] = ['id'=>$index+1, 'type'=>$type, 'x'=>$x, 'y'=>$y, 'level'=>$index===5?0:1,
            'hp'=>$index===5?0:1, 'max_hp'=>Game::buildingStats($type,1)['max_hp'],
            'ready_at'=>$index===5?time()+3600:0, 'upgrade_to'=>$index===5?1:null, 'stored'=>0,
            'fortification'=>0, 'fortify_ready_at'=>0, 'fortify_to'=>null, 'fortify_paid'=>null];
    }
    $base['buildings'][2]['fortification'] = 1;
    $base['buildings'][2]['fortify_ready_at'] = time() + 1800;
    $base['buildings'][2]['fortify_to'] = 2;
    $base['buildings'][2]['fortify_paid'] = ['twig'=>100, 'pebble'=>100, 'putty'=>50, 'goo'=>0];
    $base['next_building_id'] = 7;
    $base['resources'] = array_fill_keys(['twig','pebble','putty','goo'], 0);
    $base['army'] = array_fill_keys(array_keys(Catalog::monsters()), 0);
    $base['army']['pokey'] = 12;
    $base['shield_until'] = 0;
    $base['last_tick'] = time() + 7200;
    $q = $pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');
    $q->execute([$id, json_encode($base, JSON_THROW_ON_ERROR), time()]);
    World::assignHome($id);
    return $id;
}
function repairRaceLock(int $id): void
{
    $pdo = Db::pdo();
    $pdo->beginTransaction();
    $q = $pdo->prepare('SELECT user_id FROM bases WHERE user_id=? FOR UPDATE');
    $q->execute([$id]);
}
function repairRaceSpawn(int $id, string $action, array $input = []): int
{
    global $children;
    $request = tempnam(sys_get_temp_dir(), 'repair-request-');
    $ready = tempnam(sys_get_temp_dir(), 'repair-ready-');
    file_put_contents($request, json_encode(['user'=>$id, 'action'=>$action, 'input'=>$input], JSON_THROW_ON_ERROR));
    $command = [PHP_BINARY];
    if ($ini = php_ini_loaded_file()) { $command[] = '-c'; $command[] = $ini; }
    else {
        $command = array_merge($command, ['-n', '-d', 'extension_dir='.ini_get('extension_dir')]);
        foreach (['pdo','mysqlnd','pdo_mysql','mbstring','ctype'] as $extension) {
            if (is_file(ini_get('extension_dir').'/'.$extension.'.so')) {
                $command[] = '-d'; $command[] = 'extension='.$extension.'.so';
            }
        }
    }
    $command = array_merge($command, [__FILE__, '--child', $request, $ready]);
    $pipes = [];
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start repair concurrency process.'); }
    fclose($pipes[0]);
    $children[] = ['process'=>$process, 'pipes'=>$pipes, 'request'=>$request, 'ready'=>$ready, 'done'=>false];
    return count($children) - 1;
}
function repairRaceWaiting(int $index): void
{
    global $children;
    $child = $children[$index];
    $deadline = microtime(true) + 6;
    while (microtime(true) < $deadline) {
        if (!proc_get_status($child['process'])['running']) {
            throw new RuntimeException('Repair child exited before its row lock: '.stream_get_contents($child['pipes'][1]).stream_get_contents($child['pipes'][2]));
        }
        $connection = (int)file_get_contents($child['ready']);
        if ($connection) {
            $q = Db::pdo()->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');
            $q->execute([$connection]);
            $info = (string)$q->fetchColumn();
            if (str_contains($info, 'bases') && str_contains($info, 'FOR UPDATE')) { return; }
        }
        usleep(20000);
    }
    throw new RuntimeException('Repair child did not reach the expected home-row lock.');
}
function repairRaceResult(int $index): array
{
    global $children;
    $child = &$children[$index];
    $out = stream_get_contents($child['pipes'][1]);
    $err = stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $exit = proc_close($child['process']);
    $child['done'] = true;
    unlink($child['request']);
    unlink($child['ready']);
    if ($exit !== 0) { throw new RuntimeException('Repair child process failed: '.$err.$out); }
    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

try {
    $keeper = repairRaceKeeper();
    $attacker = repairRaceKeeper();
    // Persist quest observations for this synthetic yard before measuring the repair alone.
    Game::saveBase($keeper, Game::loadBase($keeper));
    $before = Game::loadBase($keeper);
    $expected = $before;
    foreach ($expected['buildings'] as &$building) {
        if ($building['level'] > 0) { $building['hp'] = $building['max_hp']; }
    }
    unset($building);

    // Both separate PHP requests wait at the real InnoDB home lock before release.
    repairRaceLock($keeper);
    $one = repairRaceSpawn($keeper, 'repairBase');
    $two = repairRaceSpawn($keeper, 'repairBase');
    repairRaceWaiting($one);
    repairRaceWaiting($two);
    $pdo->commit();
    $first = repairRaceResult($one);
    $second = repairRaceResult($two);
    repairRaceCheck($first['ok'] && $second['ok'], 'two simultaneous repair clicks both complete successfully');
    $counts = [$first['result']['repairResult']['repaired'], $second['result']['repairResult']['repaired']];
    sort($counts);
    repairRaceCheck($counts === [0,5], 'exactly one concurrent request repairs each damaged building');
    repairRaceCheck(Game::loadBase($keeper) === $expected, 'concurrent requests preserve zero funds, paid timers, new construction, armor, troops and geometry');
    repairRaceCheck(Game::user($keeper)['rank_points'] === 0, 'simultaneous repair cannot create ranking rewards');

    // Repair commits first. A waiting invasion must snapshot the healed HP.
    $base = Game::loadBase($keeper);
    $base['buildings'][0]['hp'] = 100;
    Game::saveBase($keeper, $base);
    repairRaceLock($keeper);
    $child = repairRaceSpawn($attacker, 'attack', ['targetId'=>$keeper]);
    repairRaceWaiting($child);
    $fixed = Game::mutate($keeper, 'repairBase', []);
    $pdo->commit();
    $result = repairRaceResult($child);
    repairRaceCheck($fixed['repairResult']['repaired'] === 1 && $result['ok'], 'invasion resumes after the committed repair');
    $battle = $result['result'];
    $hall = array_values(array_filter($battle['buildings'], fn($building)=>(int)$building['id']===1))[0];
    repairRaceCheck($hall['hp'] === $hall['max_hp'], 'repair-first invasion snapshots full authoritative health');
    $reserved = Game::loadBase($keeper);
    $child = repairRaceSpawn($keeper, 'repairBase');
    $blocked = repairRaceResult($child);
    repairRaceCheck(!$blocked['ok'] && str_contains($blocked['error'], 'battle'), 'repair is rejected once the invasion owns the home');
    repairRaceCheck(Game::loadBase($keeper) === $reserved, 'rejected active-battle request leaves the reserved base intact');
    BattleService::retreat($attacker, (int)$battle['id']);

    // Reverse the same serialization point: a real invasion commits while repair waits.
    $base = Game::loadBase($keeper);
    $base['shield_until'] = 0;
    $base['buildings'][0]['hp'] = 123;
    Game::saveBase($keeper, $base);
    repairRaceLock($keeper);
    $child = repairRaceSpawn($keeper, 'repairBase');
    repairRaceWaiting($child);
    $battle = BattleService::start($attacker, $keeper);
    $pdo->commit();
    $blocked = repairRaceResult($child);
    repairRaceCheck(!$blocked['ok'] && str_contains($blocked['error'], 'battle'), 'waiting repair rechecks the active invasion after obtaining the home lock');
    $hall = array_values(array_filter($battle['buildings'], fn($building)=>(int)$building['id']===1))[0];
    repairRaceCheck((float)$hall['hp'] === 123.0 && Game::loadBase($keeper)['buildings'][0]['hp'] === 123, 'battle-first ordering preserves the same damaged HP in home and invasion');
    $q = $pdo->prepare("SELECT COUNT(*) FROM battles WHERE status='active' AND defender_id=?");
    $q->execute([$keeper]);
    repairRaceCheck((int)$q->fetchColumn() === 1, 'race leaves exactly one valid invasion reservation');
    BattleService::retreat($attacker, (int)$battle['id']);
    $result = Game::mutate($keeper, 'repairBase', []);
    $recovered = Game::loadBase($keeper);
    BattleService::poll($attacker, (int)$battle['id']);
    repairRaceCheck($result['repairResult']['repaired'] === 1 && Game::loadBase($keeper) === $recovered, 'repair succeeds after settlement and a late poll cannot restore stale damage');
    echo "Home repair concurrency: $checks checks passed.\n";
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    foreach ($children as &$child) {
        if (!$child['done']) {
            proc_terminate($child['process']);
            foreach ($child['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_close($child['process']);
            @unlink($child['request']);
            @unlink($child['ready']);
        }
    }
    unset($child);
    if ($users) {
        $ids = implode(',', array_map('intval', $users));
        $pdo->exec('DELETE FROM reports WHERE user_id IN ('.$ids.')');
        $pdo->exec('DELETE FROM battles WHERE attacker_id IN ('.$ids.') OR defender_id IN ('.$ids.')');
        $pdo->exec('DELETE FROM world_sites WHERE home_user_id IN ('.$ids.')');
        $pdo->exec('DELETE FROM bases WHERE user_id IN ('.$ids.')');
        $pdo->exec('DELETE FROM users WHERE id IN ('.$ids.')');
    }
}
