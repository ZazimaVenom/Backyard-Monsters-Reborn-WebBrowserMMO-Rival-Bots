<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !str_ends_with((string)getenv('MYSQL_TEST_DATABASE'), '_utility_test')) {
    fwrite(STDERR, "Select an empty disposable MYSQL_TEST_DATABASE ending in _utility_test.\n");
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
    $id=addKeeper('Utility test keeper');
    $build=static fn(string $type,int $x,int $y):array=>Game::mutate($id,'build',compact('type','x','y'))['base'];
    $base=$build('generalstore',3,5);$shop=end($base['buildings']);
    expectDefense($base['resources']['twig']===159989 && $base['resources']['pebble']===159992,'General Store charges the scaled source cost');
    forceWorkDue($id,$shop['id'],'ready_at');
    $yard=Game::loadBase($id);$yard['buildings'][0]['level']=3;$yard['shiny']=50;$yard['resources']['goo']=650;Game::saveBase($id,$yard);
    expectRejection(fn()=>$build('hatcherycontrol',4,26),'Three working');
    $store=Game::snapshot($id)['base']['utilities'];$offers=(array)$store['offers'];$offer=$offers['goo.10'];
    $receipt=['kind'=>'delivery','key'=>'goo.10','price'=>$offer['price'],'amount'=>$offer['amount'],'revision'=>$store['revision']];
    $delivered=Game::mutate($id,'yardPurchase',$receipt)['base'];
    expectDefense($delivered['shiny']===50-$offer['price'] && $delivered['resources']['goo']===650+$offer['amount'],'Deliveries use earned local currency and survive a fresh snapshot');
    expectRejection(fn()=>Game::mutate($id,'yardPurchase',$receipt),'purchase changed');
    $yard=Game::loadBase($id);$yard['shiny']=500;$yard['buildings'][1]['level']=2;$yard['buildings'][2]['level']=5;
    foreach([[14,4],[26,22]] as [$x,$y]){$new=$yard['buildings'][1];$new['id']=$yard['next_building_id']++;$new['x']=$x;$new['y']=$y;$yard['buildings'][]=$new;}
    Game::saveBase($id,$yard);
    $base=$build('hatcherycontrol',4,26);$hcc=end($base['buildings']);
    expectDefense($hcc['type']==='hatcherycontrol' && $hcc['level']===0 && $hcc['ready_at']>time()+290,'Three qualified hatcheries unlock the original five-minute construction');
    forceWorkDue($id,$hcc['id'],'ready_at');
    $before=Game::snapshot($id)['base']['army']['pokey'];
    $state=Game::mutate($id,'train',['type'=>'pokey','quantity'=>7]);
    $u=$state['base']['utilities'];$q=$u['hatch'];
    expectDefense($q['quantity']===7 && $q['lanes']===3 && count(array_unique(array_column($state['queue'],'lane')))===3,'Usual training action uses all three working lanes');
    $finish=['kind'=>'hatch','key'=>$q['key'],'price'=>$q['price'],'revision'=>$u['revision']];
    $complete=Game::mutate($id,'yardPurchase',$finish);
    expectDefense(empty($complete['queue']) && $complete['army']['pokey']===$before+7 && $complete['base']['shiny']===500-$q['price'],'Control Center completes paid queue exactly once');
    expectRejection(fn()=>Game::mutate($id,'yardPurchase',$finish),'purchase changed');
    $stone=$build('stonewall',3,16);$block=end($stone['buildings']);forceWorkDue($id,$block['id'],'ready_at');
    $yard=Game::loadBase($id);$wall=end($yard['buildings']);
    expectDefense($wall['hp']===3600 && $wall['type']==='stonewall','Stone Block constructs with original HP');
    echo "Yard utility database integration: $checks checks passed.\n";
} finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
