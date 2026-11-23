<?php
declare(strict_types=1);

// This suite creates real users and invasions only in an empty disposable DB.
if (PHP_SAPI !== 'cli' || !str_ends_with((string)getenv('MYSQL_TEST_DATABASE'), '_repair_test')) {
    fwrite(STDERR, "Use MYSQL_TEST_* with an empty disposable database ending in _repair_test.\n");
    exit(2);
}
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
$config = ['host'=>getenv('MYSQL_TEST_HOST') ?: '127.0.0.1', 'port'=>(int)(getenv('MYSQL_TEST_PORT') ?: 3306),
    'database'=>getenv('MYSQL_TEST_DATABASE'), 'username'=>getenv('MYSQL_TEST_USER') ?: 'root',
    'password'=>getenv('MYSQL_TEST_PASSWORD') ?: '', 'game_speed'=>1,
    'bots'=>['enabled'=>true, 'human_target_percent'=>0]];
$pdo = Db::connect($config);
Db::useConnection($pdo, $config);
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
    throw new RuntimeException('Home repair integration requires an empty disposable database.');
}
$checks = 0;
function repairCheck(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: '.$message); }
    ++$checks;
    echo "PASS $message\n";
}
function repairReject(callable $work, string $message, string $contains = ''): void
{
    try { $work(); }
    catch (RuntimeException $error) {
        repairCheck($contains === '' || str_contains(strtolower($error->getMessage()), $contains), $message);
        return;
    }
    throw new RuntimeException('FAIL: accepted '.$message);
}
function repairKeeper(bool $bot = false): int
{
    $pdo = Db::pdo();
    $q = $pdo->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,?,?)');
    $q->execute(['RepairQA '.bin2hex(random_bytes(5)), (int)$bot, time()]);
    $id = (int)$pdo->lastInsertId();
    $base = Game::initialBase($id, $bot);
    $base['shield_until'] = 0;
    // Freeze unrelated accrual while exercising the real transactional service.
    $base['last_tick'] = time() + 3600;
    $q = $pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');
    $q->execute([$id, json_encode($base, JSON_THROW_ON_ERROR), time()]);
    World::assignHome($id);
    return $id;
}
function repairBuilding(int $id, string $type, int $level, int $x, int $y, int $hp): array
{
    return ['id'=>$id, 'type'=>$type, 'level'=>$level, 'x'=>$x, 'y'=>$y, 'hp'=>$hp,
        'max_hp'=>Game::buildingStats($type, max(1, $level))['max_hp'], 'ready_at'=>0,
        'upgrade_to'=>null, 'stored'=>0, 'fortification'=>0, 'fortify_ready_at'=>0,
        'fortify_to'=>null, 'fortify_paid'=>null];
}
function repairRaw(int $id): string
{
    $q = Db::pdo()->prepare('SELECT state_json FROM bases WHERE user_id=?');
    $q->execute([$id]);
    return (string)$q->fetchColumn();
}

$pdo->beginTransaction();
try {
    // Reset only the disposable fixture's presence state; all changes roll back.
    $pdo->exec("DELETE FROM settings WHERE name IN ('world_activity_v1','bots_schedule_clock')");
    WorldActivity::initialize($pdo);
    $keeper = repairKeeper();
    $other = repairKeeper();
    $now = time();
    $base = Game::loadBase($keeper);
    $base['resources'] = array_fill_keys(['twig','pebble','putty','goo'], 0);
    $base['shield_until'] = $now + 1200;
    $base['buildings'][0]['hp'] = 1;
    $base['buildings'][0]['fortification'] = 1;
    $base['buildings'][0]['fortify_to'] = 2;
    $base['buildings'][0]['fortify_ready_at'] = $now + 1800;
    $base['buildings'][0]['fortify_paid'] = ['twig'=>321, 'pebble'=>456, 'putty'=>78, 'goo'=>0];
    $base['buildings'][1]['hp'] = 7;
    $base['buildings'][1]['ready_at'] = $now + 2400;
    $base['buildings'][1]['upgrade_to'] = 2;
    $base['buildings'][6]['hp'] = 0;
    $base['buildings'][] = repairBuilding(9, 'wall', 1, 3, 3, 0);
    $base['buildings'][] = repairBuilding(10, 'trap', 1, 3, 8, 0);
    $base['buildings'][] = repairBuilding(11, 'storage', 1, 27, 27, 5);
    $base['buildings'][] = repairBuilding(12, 'bunker', 1, 26, 6, 1000);
    $newBuilding = repairBuilding(13, 'wall', 0, 3, 12, 0);
    $newBuilding['ready_at'] = $now + 3000;
    $newBuilding['upgrade_to'] = 1;
    $base['buildings'][] = $newBuilding;
    $base['next_building_id'] = 14;
    $base['garrison'] = ['12'=>['pokey'=>3]];
    $base['queue'] = [['id'=>1, 'type'=>'pokey', 'quantity'=>2, 'ready_at'=>$now+3600]];
    $base['researchQueue'] = [['type'=>'pokey', 'target_level'=>2, 'ready_at'=>$now+4000]];
    $base['unlockQueue'] = [['type'=>'bolt', 'ready_at'=>$now+4500]];
    $base['lab']['queue'] = ['type'=>'fink', 'target_level'=>1, 'started_at'=>$now, 'ready_at'=>$now+5000, 'paid_putty'=>55];
    $base['champions']['gorgo'] = ['level'=>2, 'hp'=>123, 'feeds'=>1, 'next_feed_at'=>$now+700,
        'last_heal_at'=>$now+3600, 'last_hunger_at'=>$now+700, 'battle_id'=>0,
        'frozen'=>true, 'paused_feed_seconds'=>42, 'bonus_feeds'=>0];
    $base['planner']['slots'] = ['1'=>['name'=>'Keep layout', 'positions'=>Planner::positions($base), 'savedAt'=>$now]];
    Game::saveBase($keeper, $base);
    $before = Game::loadBase($keeper);
    $otherBefore = repairRaw($other);
    $userBefore = Game::user($keeper);
    $snapshot = Game::snapshot($keeper);
    repairCheck($snapshot['base']['repair']['damaged'] === 7 && $snapshot['base']['repair']['available']
        && $snapshot['base']['repair']['free'] && $snapshot['base']['repair']['instant'], 'owner sees seven completed structures eligible for free instant repair');
    repairCheck(Game::loadBase($keeper)['buildings'] === $before['buildings'], 'reading the home does not silently repair invasion damage');
    repairCheck(!isset(Game::visit($keeper, $other)['repair']), 'repair controls are owner-only');

    // Take the complete comparison after the first visit initializes the yard's flora.
    $before=Game::loadBase($keeper);
    $result = Game::mutate($keeper, 'repairBase', ['userId'=>$other, 'targetId'=>$other, 'cost'=>999999]);
    $after = Game::loadBase($keeper);
    repairCheck($result['repairResult']['action'] === 'repairBase' && $result['repairResult']['repaired'] === 7 && $result['repairResult']['buildingIds'] === array_values(array_map(static fn(array $b): int => (int)$b['id'],array_filter($before['buildings'],static fn(array $b): bool => $b['level'] > 0 && $b['hp'] < $b['max_hp']))), 'bulk action repairs all eligible buildings even with zero funds');
    repairCheck($result['base']['repair']['damaged'] === 0 && !$result['base']['repair']['available'], 'returned snapshot is immediately healthy');
    $expected = $before;
    foreach ($expected['buildings'] as &$building) {
        if ($building['level'] > 0 && $building['hp'] < $building['max_hp']) { $building['hp'] = $building['max_hp']; }
    }
    unset($building);
    repairCheck($after === $expected, 'only HP changes: resources, storage, IDs, coordinates, levels, armor, queues, troops, champions, shields and saved layout stay intact');
    repairCheck($after['buildings'][12] === $newBuilding, 'unbuilt level-zero structure keeps its paid construction timer and zero HP');
    repairCheck(Game::user($keeper) === $userBefore && repairRaw($other) === $otherBefore, 'repair neither changes ranks nor accepts another owner from its payload');
    repairCheck(!in_array(10, array_column(Game::visit($keeper, $other)['buildings'], 'id'), true), 'repaired spent trap is armed and hidden from visitors again');
    $again = Game::mutate($keeper, 'repairBase', []);
    repairCheck($again['repairResult']['repaired'] === 0 && Game::loadBase($keeper) === $after, 'repeated button press succeeds without charging or changing anything');
    repairReject(fn()=>Game::mutate($keeper, 'repair', ['id'=>13]), 'single repair cannot finish initial construction', 'construction');
    repairReject(fn()=>Game::mutate($keeper, 'repair', ['id'=>99999]), 'single repair cannot reference an unowned building');
    $after['buildings'][0]['hp'] = 2;
    $after['buildings'][1]['hp'] = 3;
    Game::saveBase($keeper, $after);
    Game::mutate($keeper, 'repair', ['id'=>1]);
    Game::mutate($keeper, 'repair', ['id'=>2]);
    repairCheck(Game::loadBase($keeper) === $expected, 'single repairs are also free and preserve paid upgrade and fortification jobs');
    repairCheck(Game::mutate($keeper, 'repair', ['id'=>1])['repairResult']['repaired'] === 0, 'repeated single repair is a harmless no-op');

    // Real deployment and server simulation, changing only the test's time source.
    $attacker = repairKeeper();
    $defender = repairKeeper();
    $attackBase = Game::loadBase($attacker);
    $attackBase['army'] = array_fill_keys(array_keys(Catalog::monsters()), 0);
    $attackBase['army']['fink'] = 1;
    Game::saveBase($attacker, $attackBase);
    $defense = Game::loadBase($defender);
    $defense['resources'] = array_fill_keys(['twig','pebble','putty','goo'], 0);
    $defense['buildings'] = [repairBuilding(1,'townhall',1,16,16,500), repairBuilding(2,'flinger',1,4,4,500)];
    foreach ($defense['buildings'] as &$building) { $building['max_hp'] = 500; }
    unset($building);
    Game::saveBase($defender, $defense);
    $battle = BattleService::start($attacker, $defender);
    $reserved = repairRaw($defender);
    repairReject(fn()=>Game::mutate($defender, 'repairBase', []), 'defender cannot heal during a real invasion', 'battle');
    repairReject(fn()=>Game::mutate($attacker, 'repairBase', []), 'attacker cannot change home during a real invasion', 'battle');
    repairCheck(repairRaw($defender) === $reserved, 'rejected battle-time repair leaves defender state untouched');
    BattleService::deploy($attacker, $battle['id'], 'fink', 16, 10);
    $q = $pdo->prepare('SELECT state_json FROM battles WHERE id=?');
    $q->execute([$battle['id']]);
    $state = json_decode($q->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $state['created_at'] = time() - 310;
    $q = $pdo->prepare('UPDATE battles SET state_json=? WHERE id=?');
    $q->execute([json_encode($state, JSON_THROW_ON_ERROR), $battle['id']]);
    $finished = BattleService::poll($attacker, $battle['id']);
    $damaged = Game::loadBase($defender);
    repairCheck($finished['status'] === 'finished' && $finished['result']['attackerWon'] && $damaged['buildings'][0]['hp'] == 0, 'real invasion settlement persists destroyed home buildings');
    $settledUser = Game::user($defender);
    $healed = Game::mutate($defender, 'repairBase', []);
    $recovered = Game::loadBase($defender);
    repairCheck($healed['repairResult']['repaired'] > 0 && $healed['base']['repair']['damaged'] === 0, 'post-invasion button fully restores real combat damage');
    repairCheck($recovered['resources'] === $damaged['resources'] && $recovered['shield_until'] === $damaged['shield_until']
        && Game::user($defender) === $settledUser, 'repair preserves settled loot, recovery shield and rank result');
    BattleService::poll($attacker, $battle['id']);
    BattleService::retreat($attacker, $battle['id']);
    repairCheck(Game::loadBase($defender) === $recovered, 'late finished-battle polls and retreat cannot overwrite a repaired home');

    // The ordinary bot dispatcher must repair only when human presence allows it.
    $bot = repairKeeper(true);
    $botBase = Game::loadBase($bot);
    $botBase['resources'] = array_fill_keys(['twig','pebble','putty','goo'], 0);
    $botBase['army'] = array_fill_keys(array_keys(Catalog::monsters()), 0);
    foreach ($botBase['buildings'] as &$building) { $building['hp'] = 0; $building['stored'] = 0; }
    unset($building);
    WorldActivity::stamp($botBase, WorldActivity::clock());
    Game::saveBase($bot, $botBase);
    $asleep = repairRaw($bot);
    $paused = Bots::tick(1, 3);
    repairCheck($paused['status'] === 'paused' && $paused['processed'] === 0 && repairRaw($bot) === $asleep, 'no players online means damaged bots remain unchanged');
    activityFixtureWake($keeper);
    activityFixtureReleaseCadence();
    $tick = Bots::tick(1, 3);
    $botAfter = Game::loadBase($bot);
    repairCheck($tick['errors'] === [] && $tick['processed'] === 1 && $tick['repaired'] === count($botBase['buildings']), 'next online bot turn repairs the whole home through the normal free action');
    repairCheck(Game::repairHomeStatus($botAfter)['damaged'] === 0 && $botAfter['resources'] === $botBase['resources']
        && $botAfter['army'] === $botBase['army'] && Game::user($bot)['rank_points'] === 0, 'bot recovery needs no funds and does not grant troops or ranking points');

    $botAfter['buildings'][0]['hp'] = 1;
    Game::saveBase($bot, $botAfter);
    $botBattle = BattleService::start($other, $bot);
    $q = $pdo->prepare('UPDATE users SET next_turn_at=0 WHERE id=?');
    $q->execute([$bot]);
    activityFixtureReleaseCadence();
    $busyTick = Bots::tick(1, 3);
    repairCheck($busyTick['errors'] === [] && $busyTick['processed'] === 1 && $busyTick['repaired'] === 0
        && Game::loadBase($bot)['buildings'][0]['hp'] === 1, 'an online bot does not repair while its home is reserved by an invasion');
    BattleService::retreat($other, $botBattle['id']);
    $settledBot = Game::user($bot);
    $q->execute([$bot]);
    activityFixtureReleaseCadence();
    $recoveryTick = Bots::tick(1, 3);
    $botAfter = Game::loadBase($bot);
    repairCheck($recoveryTick['errors'] === [] && $recoveryTick['repaired'] === 1
        && Game::repairHomeStatus($botAfter)['damaged'] === 0 && Game::user($bot) === $settledBot,
        'bot repairs on its next due turn after settlement without changing the battle result');
    WorldActivity::logout($keeper, 'activity-fixture-session');
    $botAfter['buildings'][0]['hp'] = 0;
    Game::saveBase($bot, $botAfter);
    $q = $pdo->prepare('UPDATE users SET next_turn_at=0 WHERE id=?');
    $q->execute([$bot]);
    $asleep = repairRaw($bot);
    $paused = Bots::tick(1, 3);
    repairCheck($paused['status'] === 'paused' && repairRaw($bot) === $asleep, 'bots stop repairing again when the final human leaves');
    echo "Home repair integration: $checks checks passed; temporary data rolled back.\n";
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
