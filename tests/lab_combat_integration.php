<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
ini_set('display_errors','1');
if(!getenv('MYSQL_TEST_DATABASE')){fwrite(STDERR,"Use a disposable database with MYSQL_TEST_* variables.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER'),'password'=>getenv('MYSQL_TEST_PASSWORD'),'game_speed'=>1];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function liCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function liReject(callable $action,string $message):void{try{$action();}catch(RuntimeException){liCheck(true,$message);return;}throw new RuntimeException('FAIL expected rejection: '.$message);}
function liBuilding(int $id,string $type,float $x,float $y,int $level=1):array{$hp=Game::buildingStats($type,$level)['max_hp'];return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];}
function liPlayer(int $catapult=3,bool $bot=false):int{
    $q=Db::pdo()->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,?,?)');$q->execute(['LabCombatQA '.bin2hex(random_bytes(5)),(int)$bot,time()]);$id=(int)Db::pdo()->lastInsertId();
    $b=Game::initialBase($id,$bot);$b['shield_until']=0;$b['buildings']=[liBuilding(1,'townhall',24,24,4),liBuilding(2,'flinger',4,4,5),liBuilding(3,'lab',16,16,3),liBuilding(4,'storage',25,8,5),liBuilding(5,'bunker',10,10,3),liBuilding(6,'housing',20,8,5)];
    if($catapult){$b['buildings'][]=liBuilding(7,'catapult',20,16,$catapult);}
    foreach($b['buildings'] as &$building){$building['hp']=$building['max_hp']=100000;}unset($building);
    $b['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$b['army']['dave']=2;$b['army']['projectx']=1;$b['army']['brain']=1;$b['army']['pokey']=5;
    $b['research']=array_fill_keys(array_keys(Catalog::monsters()),4);$b['lab']['levels']=array_fill_keys(array_keys(Catalog::labAbilities()),2);
    $b['garrison']['5']=['fink'=>2];$b['resources']=array_fill_keys(['twig','pebble','putty','goo'],50000);
    $q=Db::pdo()->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$q->execute([$id,json_encode($b),time()]);return $id;
}
function liState(int $id):array{$q=Db::pdo()->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);return json_decode($q->fetchColumn(),true);}
function liSave(int $id,array $state):void{$q=Db::pdo()->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($state),$id]);}
function liAge(int $id,int $seconds):void{$state=liState($id);$state['created_at']=time()-$seconds;liSave($id,$state);}
$pdo->beginTransaction();
try{
    $a=liPlayer();$d=liPlayer(0,true);$other=liPlayer();$battle=BattleService::start($a,$d);$id=$battle['id'];
    liCheck($battle['catapult']['level']===3&&!array_filter($battle['catapult']['used'])&&$battle['catapult']['resources']['twig']==50000,'Living Catapult and unspent resource families are snapshotted at invasion start');
    $raw=liState($id);liCheck($raw['formatVersion']===7&&$raw['_labLevels']['dave']===2&&$raw['_garrisonTemplates']['5'][0]['labLevel']===2,'Attacker and defensive garrison Laboratory tiers are snapshotted together');
    $defenderView=BattleService::poll($d,$id);liCheck(!isset($defenderView['catapult']['resources']),'Opponent cannot read private attacker resource balances through artillery controls');
    liReject(fn()=>BattleService::catapult($other,$id,'twig',1,24,24),'Unrelated account cannot launch Catapult ammunition');
    liReject(fn()=>BattleService::catapult($d,$id,'twig',1,24,24),'Defender cannot control attacker Catapult');
    liReject(fn()=>BattleService::catapult($a,$id,'goo',1,24,24),'Unknown resource family is rejected');
    liReject(fn()=>BattleService::catapult($a,$id,'twig',4,24,24),'Unknown ammunition tier is rejected');
    liReject(fn()=>BattleService::catapult($a,$id,'twig',1,NAN,24),'Nonfinite Catapult coordinates are rejected');
    liReject(fn()=>BattleService::catapult($a,$id,'twig',1,32,24),'Out-of-yard Catapult aim is rejected');
    liCheck(Game::loadBase($a)['resources']['twig']==50000&&!liState($id)['catapult']['used']['twig'],'Rejected shots preserve resources and the available resource-family slot');
    $shot=BattleService::catapult($a,$id,'twig',1,24,24);
    liCheck($shot['catapult']['used']['twig']&&Game::loadBase($a)['resources']['twig']==49000&&$shot['catapult']['resources']['twig']==49000,'Legal direct-building aim atomically pays resources and consumes the family slot');
    liCheck(count($shot['catapult']['pending'])===1&&!isset($shot['catapult']['pending'][0]['_bomb'])&&$shot['catapult']['pending'][0]['radius']===4.0,'Pending projectile exposes aim/radius/timing without private damage payloads');
    liCheck((float)$shot['flinger']['readyAt']===0.0&&array_sum($shot['remaining'])===9,'Catapult does not consume a Flinger cooldown or monster inventory');
    liReject(fn()=>BattleService::catapult($a,$id,'twig',2,10,10),'A higher tier cannot bypass one-shot-per-resource-family');
    liReject(fn()=>BattleService::retreat($a,$id),'Ending an invasion waits for an already paid projectile to land');
    liAge($id,2);$landed=BattleService::poll($a,$id);
    liCheck(!$landed['catapult']['pending']&&count(array_filter($landed['events'],fn($e)=>$e['type']==='catapult_impact'))===1,'Polling resolves the scheduled impact exactly once');
    $townhall=array_values(array_filter($landed['buildings'],fn($b)=>$b['type']==='townhall'))[0];liCheck($townhall['hp']===99700.0,'The delayed impact deals server-derived damage to the clicked Town Hall');
    $base=Game::loadBase($a);$base['lab']['levels']['dave']=3;Game::saveBase($a,$base);
    $deployed=BattleService::deploy($a,$id,['dave'=>1,'projectx'=>1],1,1);$dave=array_values(array_filter($deployed['units'],fn($u)=>$u['type']==='dave'))[0];
    liCheck($dave['labLevel']===2&&$dave['labAbility']==='rockets','Later research cannot change the invasion’s snapshotted attacker powers');
    $raw=liState($id);$raw['units'][0]['x']=8;$raw['units'][0]['y']=10;$raw['units'][0]['_speed']=0;liSave($id,$raw);liAge($id,3);$garrison=BattleService::poll($a,$id);
    $defenders=array_values(array_filter($garrison['units'],fn($u)=>$u['team']==='defender'));liCheck(count($defenders)===2&&$defenders[0]['labLevel']===2,'Released bunker defenders receive their owner’s earned Lab abilities');
    $putty=BattleService::catapult($a,$id,'putty',1,8,10);liAge($id,5);$buffed=BattleService::poll($a,$id);
    liCheck(Game::loadBase($a)['resources']['putty']==49000&&count(array_filter($buffed['units'],fn($u)=>$u['team']==='attacker'&&$u['boosted']))>0,'Paid Putty applies temporary boosts to actual attacking monsters at impact');
    $ended=BattleService::retreat($a,$id);$rank=Game::user($a)['rank_points'];$resources=Game::loadBase($a)['resources'];
    liCheck(count($ended['result']['artillery'])===2&&$ended['result']['artillery'][0]['cost']===1000,'Saved battle results record actual paid artillery shots');
    BattleService::catapult($a,$id,'pebble',1,24,24);BattleService::poll($a,$id);
    liCheck(Game::loadBase($a)['resources']===$resources&&Game::user($a)['rank_points']===$rank,'Finished battle retries cannot spend resources or reward points twice');

    $low=liPlayer(1);$target=liPlayer(0,true);$b=BattleService::start($low,$target);liReject(fn()=>BattleService::catapult($low,$b['id'],'pebble',1,10,10),'Catapult facility level gates stronger ammunition families');BattleService::retreat($low,$b['id']);
    $poor=liPlayer(3);$target=liPlayer(0,true);$base=Game::loadBase($poor);$base['resources']['pebble']=999;Game::saveBase($poor,$base);$b=BattleService::start($poor,$target);liReject(fn()=>BattleService::catapult($poor,$b['id'],'pebble',1,10,10),'Unaffordable ammunition cannot be fired');
    liCheck(Game::loadBase($poor)['resources']['pebble']==999&&!liState($b['id'])['catapult']['used']['pebble'],'Failed payment leaves the resource-family slot unused');BattleService::retreat($poor,$b['id']);
    $no=liPlayer(0);$target=liPlayer(0,true);$b=BattleService::start($no,$target);liCheck($b['catapult']===null,'No living Catapult yields no artillery control');liReject(fn()=>BattleService::catapult($no,$b['id'],'twig',1,24,24),'Missing facility cannot launch artillery');BattleService::retreat($no,$b['id']);
    $legacy=liPlayer(3);$target=liPlayer(0,true);$b=BattleService::start($legacy,$target);$raw=liState($b['id']);$raw['formatVersion']=3;unset($raw['catapult'],$raw['_labLevels']);liSave($b['id'],$raw);
    $old=BattleService::deploy($legacy,$b['id'],['dave'=>1],1,1);liCheck($old['catapult']===null&&$old['units'][0]['labLevel']===0,'Legacy version-three battles cannot acquire new Laboratory powers or Catapult controls');liReject(fn()=>BattleService::catapult($legacy,$b['id'],'twig',1,24,24),'Legacy battle rejects new artillery even when its current yard owns a Catapult');BattleService::retreat($legacy,$b['id']);
    $late=liPlayer(3);$target=liPlayer(0,true);$b=BattleService::start($late,$target);$raw=liState($b['id']);$raw['elapsed']=299.25;$raw['created_at']=time()-299;liSave($b['id'],$raw);liReject(fn()=>BattleService::catapult($late,$b['id'],'twig',1,24,24),'A shot cannot be launched without enough time for its impact');BattleService::retreat($late,$b['id']);
    echo "Laboratory combat integration: $checks checks passed.\n";
}finally{if($pdo->inTransaction()){$pdo->rollBack();}}
