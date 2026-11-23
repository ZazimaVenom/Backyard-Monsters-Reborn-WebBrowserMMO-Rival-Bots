<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/InfernoRealm.php';
$config=['database'=>getenv('MYSQL_TEST_DATABASE')?:'', 'host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),
    'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(!str_ends_with($config['database'],'_monsters_test')){fwrite(STDERR,"Use a disposable database ending in _monsters_test.\n");exit(2);}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);++$checks;}
function deny(callable $work):void{try{$work();}catch(RuntimeException){check(true,'rejected');return;}throw new LogicException('Unexpected acceptance');}
$pdo->beginTransaction();
try{
    $id=Game::register('Cavern '.bin2hex(random_bytes(4)),'monsters-test-secret')['id'];
    $b=Game::loadBase($id);check(!isset($b['army']['spurtz'],$b['army']['slimeattikusmini'],$b['infernoRealm'])&&$b['army']['vorg']===0,'Fresh yard');
    $b['buildings'][0]['level']=4;$b['queue']=[];$b['army']=array_fill_keys(array_keys(Catalog::homeMonsters()),0);
    foreach(['locker','academy','storage','housing'] as $type){$b['buildings'][]=['type'=>$type,'id'=>$b['next_building_id']++,'x'=>4,'y'=>4,'level'=>5,'hp'=>9999,'max_hp'=>9999,'ready_at'=>0,'stored'=>0];}
    $b['resources']=array_fill_keys(['twig','pebble','putty','goo'],125000);Game::saveBase($id,$b);
    foreach(['spurtz','slimeattikusmini'] as $foreign){foreach(['train','research','unlock','juice'] as $action)deny(fn()=>Game::mutate($id,$action,['type'=>$foreign,'quantity'=>1]));}
    deny(fn()=>Game::mutate($id,'unlock',['type'=>'slimeattikus']));deny(fn()=>Game::mutate($id,'unlock',['type'=>'vorg']));
    $b['buildings'][0]['level']=5;Game::saveBase($id,$b);$r=Game::mutate($id,'unlock',['type'=>'vorg']);
    check($r['base']['resources']['goo']===119000,'Paid Vorg research');$b=Game::loadBase($id);$b['unlockQueue'][0]['ready_at']=time()-1;Game::saveBase($id,$b);
    $r=Game::mutate($id,'train',['type'=>'vorg','quantity'=>1]);check($r['queue'][0]['paid_goo']===1000&&$r['army']['vorg']===0,'Purchased waiting egg');
    $b=Game::loadBase($id);$b['queue'][0]['ready_at']=time()-1;Game::saveBase($id,$b);$r=Game::mutate($id,'research',['type'=>'vorg']);check($r['army']['vorg']===1&&$r['researchQueue'][0]['target_level']===2,'Hatched and studying');
    $b=Game::loadBase($id);$b['researchQueue'][0]['ready_at']=time()-1;Game::saveBase($id,$b);$r=Game::mutate($id,'train',['type'=>'vorg','quantity'=>1]);
    check($r['queue'][0]['paid_goo']===1563 && $r['research']['vorg']===2,'Second tier recipe dynamically priced');
    $r=InfernoRealm::act($id,['action'=>'enter','requestId'=>str_repeat('a',20)]);$meta=['action'=>'infernoTrain','requestId'=>str_repeat('b',20),'realmRevision'=>$r['revision'],'monsterRevision'=>$r['monsters']['revision'],'type'=>'spurtz','quantity'=>2];
    $first=InfernoRealm::act($id,$meta);$again=InfernoRealm::act($id,$meta);check($again['base']['resources']===$first['base']['resources']&&count($again['monsters']['queue'])===2,'HTTP service replay charges once');
    $b=Game::loadBase($id);foreach($b['infernoRealm']['queue'] as &$q){$q['ready_at']=time()-1;}unset($q);Game::saveBase($id,$b);
    $r=InfernoRealm::view($id);check($r['monsters']['army']['spurtz']===10&&count($r['monsters']['queue'])===0&&Game::loadBase($id)['army']['vorg']===1,'Realm timers persist independently');
    $pdo->rollBack();echo "Monster expansion database: $checks checks passed.\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
