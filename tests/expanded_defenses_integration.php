<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !str_ends_with((string)getenv('MYSQL_TEST_DATABASE'), '_expanded_defense_test')) {
    fwrite(STDERR, "Select an empty disposable MYSQL_TEST_DATABASE ending in _expanded_defense_test.\n");
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
    Mushrooms::ensure($base,$id,time());
    $base['mushrooms']['items']=[];
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
    $id=addKeeper('Surface defense research');
    $build=static fn(string $type,int $x,int $y):array=>Game::mutate($id,'build',compact('type','x','y'))['base'];
    expectRejection(fn()=>$build('railgun',3,5),'Town Hall');
    $base=Game::loadBase($id);$base['buildings'][0]['level']=5;Game::saveBase($id,$base);
    foreach (['infernocannon','quaketower','magmatower'] as $type) { expectRejection(fn()=>$build($type,3,5),'available building'); }
    expectRejection(fn()=>$build('diamondspurtz',3,5),'Earn this cannon');
    expectRejection(fn()=>$build('spurtzcannon',3,5),'Earn this cannon');
    $base=$build('railgun',3,5);$rail=end($base['buildings']);
    expectDefense($rail['level']===0 && $base['resources']['twig']===140000 && $base['resources']['pebble']===136000 && $base['resources']['putty']===144000,'Primary home action pays the Railgun price and occupies a worker');
    $yard=Game::loadBase($id);SpurtzRewards::award($yard,3);Game::saveBase($id,$yard);
    $before=$base['resources'];$base=$build('spurtzcannon',4,26);$first=end($base['buildings']);
    expectDefense($base['resources']===$before && $first['level']===1 && $first['hp']===15000 && $first['ready_at']===0,'Earned cannon places immediately for free even when worker is busy');
    expectRejection(fn()=>$build('spurtzcannon',10,4),'all the cannons');
    $yard=Game::loadBase($id);SpurtzRewards::award($yard,4);Game::saveBase($id,$yard);
    $base=$build('spurtzcannon',10,4);$second=end($base['buildings']);
    forceWorkDue($id,$rail['id'],'ready_at');
    $base=Game::mutate($id,'upgrade',['id'=>$first['id']])['base'];
    $upgrading=array_values(array_filter($base['buildings'],fn($b)=>$b['id']===$first['id']))[0];
    expectDefense($upgrading['upgrade_to']===2 && $upgrading['ready_at']>time()+1400,'Cannon upgrade uses level-specific paid time');
    $yard=Game::loadBase($id);SpurtzRewards::award($yard,5);Game::saveBase($id,$yard);
    expectRejection(fn()=>$build('spurtzcannon',27,15),'Earn this cannon');
    expectRejection(fn()=>$build('diamondspurtz',27,15),'all the cannons');
    forceWorkDue($id,$first['id'],'ready_at');
    $saved=Game::snapshot($id)['base'];$earned=array_values(array_filter($saved['buildings'],fn($b)=>$b['type']==='diamondspurtz'));
    expectDefense(count($earned)===2 && $earned[0]['level']===2 && $earned[0]['hp']===24200 && $saved['brukkargReward']['limit']===2,'Both towers survive conversion and the saved paid upgrade finishes as Black Diamond');
    echo "Expanded defenses database integration: $checks checks passed.\n";
} finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
