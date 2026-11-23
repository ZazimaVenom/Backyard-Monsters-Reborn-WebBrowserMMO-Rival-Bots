<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !str_ends_with((string)getenv('MYSQL_TEST_DATABASE'), '_defense_test')) {
    fwrite(STDERR, "Select an empty disposable MYSQL_TEST_DATABASE ending in _defense_test.\n");
    exit(2);
}
require_once __DIR__.'/../app/bootstrap.php';
$config = ['host'=>getenv('MYSQL_TEST_HOST') ?: '127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT') ?: 3306),
    'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER') ?: 'root',
    'password'=>getenv('MYSQL_TEST_PASSWORD') ?: '','game_speed'=>1];
$pdo = Db::connect($config);
Db::useConnection($pdo, $config);
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) {
    throw new RuntimeException('Use an empty disposable database.');
}

$checks = 0;
function expectDefense(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks;
}
function expectRejection(callable $action, string $reason): void
{
    try { $action(); }
    catch (RuntimeException $error) {
        expectDefense(str_contains($error->getMessage(), $reason), 'Unexpected rejection: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Missing rejection: '.$reason);
}
function addKeeper(string $name): int
{
    $pdo = Db::pdo();
    $pdo->prepare('INSERT INTO users(name,created_at) VALUES(?,?)')->execute([$name,time()]);
    $id = (int)$pdo->lastInsertId();
    $base = Game::initialBase($id);
    // This fixed battle scene needs clear ground at its scripted trap and deployment positions.
    Mushrooms::ensure($base,$id,time()); $base['mushrooms']['items']=[];
    $base['resources'] = array_fill_keys(['twig','pebble','putty','goo'], 160000);
    $base['army']['dave'] = 1;
    $base['shield_until'] = 0;
    $pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')
        ->execute([$id,json_encode($base, JSON_THROW_ON_ERROR),time()]);
    World::assignHome($id);
    return $id;
}
function forceWorkDue(int $owner, int $id, string $field): void
{
    $yard = Game::loadBase($owner);
    foreach ($yard['buildings'] as &$building) {
        if ($building['id'] == $id) { $building[$field] = time()-1; }
    }
    unset($building);
    Game::saveBase($owner, $yard);
    Game::snapshot($owner);
}
$pdo->beginTransaction();
try {
    $defender = addKeeper('Defense fixture');
    $attacker = addKeeper('Attack fixture');
    $towerRequest = ['type'=>'aerial','x'=>3,'y'=>5];
    expectRejection(fn()=>Game::mutate($defender,'build',$towerRequest), 'Town Hall');
    $yard = Game::loadBase($defender);
    $yard['buildings'][0]['level'] = 4;
    Game::saveBase($defender,$yard);
    $placed = Game::mutate($defender,'build',$towerRequest)['base'];
    $towerId = (int)end($placed['buildings'])['id'];
    expectDefense($placed['resources']['twig'] === 157850 && end($placed['buildings'])['level'] === 0, 'Paid Aerial Tower construction starts');
    expectRejection(fn()=>Game::mutate($defender,'build',['type'=>'aerial','x'=>4,'y'=>26]), 'limit');
    forceWorkDue($defender,$towerId,'ready_at');
    $placed = Game::mutate($defender,'build',['type'=>'heavytrap','x'=>3,'y'=>12])['base'];
    $trapId = (int)end($placed['buildings'])['id'];
    expectDefense(!in_array($trapId,array_column(Game::visit($defender,$attacker)['buildings'],'id'),true), 'Unfinished Heavy Trap is private');
    forceWorkDue($defender,$trapId,'ready_at');
    expectDefense(!in_array($trapId,array_column(Game::visit($defender,$attacker)['buildings'],'id'),true), 'Armed Heavy Trap stays hidden from the visitor');
    Game::mutate($defender,'upgrade',['id'=>$towerId]);
    forceWorkDue($defender,$towerId,'ready_at');
    Game::mutate($defender,'fortify',['id'=>$towerId]);
    forceWorkDue($defender,$towerId,'fortify_ready_at');
    $battle = BattleService::start($attacker,$defender);
    expectDefense(!in_array($trapId,array_column($battle['buildings'],'id'),true), 'Invasion scouting hides armed trap');
    $tower = array_column($battle['buildings'],null,'id')[$towerId];
    expectDefense($tower['level'] === 2 && $tower['fortification'] === 1 && $tower['max_hp'] == 4400, 'Upgrade and paid fortification survive raid launch');
    // Aim this fixture's first scattered landing onto the known pressure plate.
    $launchState = ['id'=>$battle['id'], 'unitSeq'=>0, 'flinger'=>$battle['flinger']];
    [$landX, $landY] = Flinger::landing($launchState, $battle['flinger']['weights']['dave'], 3, 12, 0);
    BattleService::deploy($attacker, $battle['id'], 'dave', 3 + (3 - $landX), 12 + (12 - $landY));
    // Advance the fixture clock past the first simulation step.
    $q = $pdo->prepare('SELECT state_json FROM battles WHERE id=?');
    $q->execute([$battle['id']]);
    $raw = json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $raw['created_at'] -= 2;
    $pdo->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($raw,JSON_THROW_ON_ERROR),$battle['id']]);
    $battle = BattleService::poll($attacker,$battle['id']);
    $visible = array_column($battle['buildings'],null,'id');
    expectDefense(isset($visible[$trapId]) && $visible[$trapId]['hp'] == 0, 'Deployed D.A.V.E. detonates and reveals trap');
    BattleService::retreat($attacker,$battle['id']);
    $yard = Game::loadBase($defender);
    expectDefense(array_column($yard['buildings'],'hp','id')[$trapId] == 0, 'Settlement persists the spent trap');
    $repaired = Game::mutate($defender,'repairBase',[]);
    expectDefense($repaired['repairResult']['repaired'] >= 1, 'Free home repair restores the Heavy Trap');
    expectDefense(!in_array($trapId,array_column(Game::visit($defender,$attacker)['buildings'],'id'),true), 'Repaired Heavy Trap is armed and concealed');
    echo "Advanced defenses database integration: $checks checks passed.\n";
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
