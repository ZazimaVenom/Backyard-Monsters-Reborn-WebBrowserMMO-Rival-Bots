<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$db=['database'=>getenv('MYSQL_TEST_DATABASE') ?: '', 'host'=>getenv('MYSQL_TEST_HOST') ?: '127.0.0.1',
    'port'=>(int)(getenv('MYSQL_TEST_PORT') ?: 3306),'username'=>getenv('MYSQL_TEST_USER') ?: 'root',
    'password'=>getenv('MYSQL_TEST_PASSWORD') ?: '', 'game_speed'=>1];
if (PHP_SAPI!=='cli' || !str_ends_with($db['database'],'_workshop_test')) {
    fwrite(STDERR,"Select an empty disposable MYSQL_TEST_DATABASE ending in _workshop_test.\n");exit(2);
}
$pdo=Db::connect($db);Db::useConnection($pdo,$db);
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0) { throw new RuntimeException('Use an empty test world.'); }
$checks=0;
function checkWorkshop(bool $value,string $why):void {
    global $checks;if(!$value){throw new RuntimeException($why);}++$checks;
}
function rejectWorkshop(callable $fn,string $reason):void {
    try{$fn();}catch(RuntimeException $e){checkWorkshop(str_contains($e->getMessage(),$reason),'Unexpected reason: '.$e->getMessage());return;}
    throw new RuntimeException('Expected rejection '.$reason);
}
function changeWorkshop(int $id,callable $edit):void {
    $base=Game::loadBase($id,true);$edit($base);Game::saveBase($id,$base);
}
$pdo->beginTransaction();
try{
    $pdo->prepare('INSERT INTO users(name,created_at) VALUES(?,?)')->execute(['Workshop Keeper',time()]);
    $id=(int)$pdo->lastInsertId();$base=Game::initialBase($id);
    $base['resources']=array_fill_keys(['twig','pebble','putty','goo'],500000);
    $pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($base,JSON_THROW_ON_ERROR),time()]);
    World::assignHome($id);$lab=['type'=>'siegelab','x'=>3,'y'=>22];$factory=['type'=>'siegefactory','x'=>3,'y'=>27];
    rejectWorkshop(fn()=>Game::mutate($id,'build',$lab),'Town Hall');
    changeWorkshop($id,function(&$b){$b['buildings'][0]['level']=5;});
    rejectWorkshop(fn()=>Game::mutate($id,'build',$factory),'Chaos Lab');
    $started=Game::mutate($id,'build',$lab);
    $labId=end($started['base']['buildings'])['id'];
    checkWorkshop($started['base']['resources']['twig']===494000,'Paid first lab cost');
    changeWorkshop($id,function(&$b)use($labId){foreach($b['buildings'] as &$f){if($f['id']===$labId){$f['ready_at']=time()-1;}}});
    $built=Game::mutate($id,'build',$factory);
    $factoryId=end($built['base']['buildings'])['id'];
    changeWorkshop($id,function(&$b)use($factoryId){foreach($b['buildings'] as &$f){if($f['id']===$factoryId){$f['ready_at']=time()-1;}}});
    Game::snapshot($id);
    rejectWorkshop(fn()=>Game::mutate($id,'build',$factory),'limit');
    $study=Game::mutate($id,'siegeResearch',['type'=>'jars','revision'=>0]);
    checkWorkshop($study['base']['siege']['research']['type']==='jars' && $study['siegeResult']['level']===1,'Study persisted from the game API');
    rejectWorkshop(fn()=>Game::mutate($id,'upgrade',['id'=>$labId]),'cancel the device study');
    rejectWorkshop(fn()=>Game::mutate($id,'siegeResearch',['type'=>'jars','revision'=>0]),'changed');
    changeWorkshop($id,function(&$b){$b['siege']['research']['ready_at']=time()-1;});
    Game::snapshot($id);
    $order=Game::mutate($id,'siegeBuild',['type'=>'jars','revision'=>0]);
    checkWorkshop($order['base']['siege']['devices']['jars']['level']===1 && $order['base']['siege']['build']['type']==='jars','Research completion opens matching production');
    rejectWorkshop(fn()=>Game::mutate($id,'siegeResearch',['type'=>'jars','revision'=>2]),'level 2');
    changeWorkshop($id,function(&$b){$b['siege']['build']['ready_at']=time()-1;});
    $private=Game::snapshot($id)['base'];
    checkWorkshop($private['siege']['loadout']===['type'=>'jars','level'=>1],'A completed device persists over reload');
    checkWorkshop(!isset(Game::publicBase($id,Game::loadBase($id),false)['siege']),'Rivals cannot inspect research or armed device');
    rejectWorkshop(fn()=>Game::mutate($id,'questClaim',['quest'=>'SW6']),'Factory slot');
    $up=Game::mutate($id,'upgrade',['id'=>$labId]);
    checkWorkshop(end($up['base']['buildings'])['type']==='siegefactory','Factory retains identity');
    $old=Game::loadBase($id);
    $ready=array_column($old['buildings'],null,'id')[$labId];
    checkWorkshop($ready['upgrade_to']===2 && $ready['ready_at']>=time()+214 && $ready['ready_at']<=time()+217,'Level two uses 216 base seconds');
    changeWorkshop($id,function(&$b)use($labId){foreach($b['buildings'] as &$f){if($f['id']===$labId){$f['level']=5;$f['hp']=$f['max_hp']=7060;$f['ready_at']=0;$f['upgrade_to']=null;}}});
    $before=Game::loadBase($id)['resources'];
    $up=Game::mutate($id,'upgrade',['id'=>$labId]);
    checkWorkshop($before['twig']-$up['base']['resources']['twig']===40000,'Lab reaches level 6 above local Hall cap at exact tier price');
    checkWorkshop(Game::user($id)['rank_points']===0,'Production and research do not affect ranked points');
    echo "Chaos workshop database integration: $checks checks passed.\n";
}finally{$pdo->rollBack();}
