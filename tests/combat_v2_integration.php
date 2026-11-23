<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';ini_set('display_errors','1');
require_once __DIR__.'/ActivityFixture.php';
if(!getenv('MYSQL_TEST_DATABASE')){fwrite(STDERR,"Use a disposable database with MYSQL_TEST_* variables.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER'),'password'=>getenv('MYSQL_TEST_PASSWORD'),'game_speed'=>1];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function v2check(bool$ok,string$message):void{global$checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function v2reject(callable$action,string$message):void{try{$action();}catch(RuntimeException){v2check(true,$message);return;}throw new RuntimeException('FAIL expected rejection: '.$message);}
function v2building(int$id,string$type,float$x,float$y,int$hp=10000):array{return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>1,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];}
function v2player(bool$bot=false):int{$p=Db::pdo();$q=$p->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,?,?)');$q->execute(['CombatV2 '.bin2hex(random_bytes(5)),(int)$bot,time()]);$id=(int)$p->lastInsertId();$b=Game::initialBase($id,$bot);$b['shield_until']=0;$b['buildings']=[v2building(1,'townhall',24,24,100000),v2building(2,'flinger',4,4)];$b['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$b['army']['pokey']=20;$b['army']['fink']=10;$b['garrison']=[];$q=$p->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$q->execute([$id,json_encode($b),time()]);return$id;}
function v2state(int$id):array{$q=Db::pdo()->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);return json_decode($q->fetchColumn(),true);}
function v2save(int$id,array$s):void{$q=Db::pdo()->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($s),$id]);}
function v2age(int$id,int$seconds):void{$s=v2state($id);activityFixtureAgeState($s,$seconds);v2save($id,$s);}
$pdo->beginTransaction();try{
 $a=v2player();activityFixtureWake($a);$d=v2player(true);$base=Game::loadBase($a);$withFlinger=$base;$base['buildings']=[$base['buildings'][0]];Game::saveBase($a,$base);
 v2reject(fn()=>BattleService::start($a,$d),'new invasion requires a completed living Flinger');Game::saveBase($a,$withFlinger);
 $defense=Game::loadBase($d);$defense['buildings'][]=v2building(3,'trap',10,10);$construction=v2building(4,'trap',14,10);$construction['level']=0;$construction['hp']=0;$construction['ready_at']=time()+60;$construction['upgrade_to']=1;$defense['buildings'][]=$construction;Game::saveBase($d,$defense);
 $battle=BattleService::start($a,$d);$id=$battle['id'];
 v2check($battle['flinger']['capacity']===250&&(float)$battle['flinger']['cooldown']===5.0,'level-one Flinger exposes 250 bucket space and five-second cooldown');
 v2check(count(array_filter($battle['buildings'],fn($b)=>$b['type']==='trap'))===0,'attacker snapshot conceals an untriggered trap');
 v2check(count(array_filter(BattleService::poll($d,$id)['buildings'],fn($b)=>$b['type']==='trap'))===2,'defender snapshot retains its own hidden trap and trap construction');
 $before=Game::loadBase($a)['army'];v2reject(fn()=>BattleService::deploy($a,$id,['pokey'=>20,'fink'=>6],1,1),'an oversized Flinger bucket is rejected');
 v2check(Game::loadBase($a)['army']===$before,'oversized bucket has no partial inventory debit');
 v2reject(fn()=>BattleService::deploy($a,$id,['pokey'=>1,'unknown'=>1],1,1),'unknown species rejects an entire mixed bucket');
 v2reject(fn()=>BattleService::deploy($a,$id,['pokey'=>1.5],1,1),'fractional troop quantities cannot enter a bucket');
 $fired=BattleService::deploy($a,$id,['pokey'=>2,'fink'=>2],1,1);
 v2check(count($fired['units'])===4&&Game::loadBase($a)['army']['pokey']===18&&Game::loadBase($a)['army']['fink']===8,'mixed bucket consumes exact existing species counts');
 $before=Game::loadBase($a)['army'];v2reject(fn()=>BattleService::deploy($a,$id,['pokey'=>1],1,1),'another bucket cannot fire during reload');
 v2check(Game::loadBase($a)['army']===$before,'cooldown rejection does not consume inventory');
 v2age($id,6);BattleService::poll($a,$id);$second=BattleService::deploy($a,$id,'pokey',1,1);
 v2check(count($second['units'])===5&&(float)$second['flinger']['readyAt']>=11,'legacy type input works as one troop after cooldown');BattleService::retreat($a,$id);

 $legacyA=v2player();$legacyD=v2player(true);$legacy=BattleService::start($legacyA,$legacyD);$s=v2state($legacy['id']);unset($s['flinger'],$s['formatVersion']);v2save($legacy['id'],$s);
 $one=BattleService::deploy($legacyA,$legacy['id'],['pokey'=>1],1,1);$two=BattleService::deploy($legacyA,$legacy['id'],['pokey'=>1],1,1);
 v2check(count($two['units'])===2&&($two['flinger']['legacy']??false),'0.1 active battles retain their original uncapped no-cooldown deployment');BattleService::retreat($legacyA,$legacy['id']);

 $bot=v2player(true);$botBase=Game::loadBase($bot);$botBase['army']['fink']=24;Game::saveBase($bot,$botBase);$victim=v2player();$raid=BattleService::botAttack($bot,$victim);$weight=0;foreach($raid['units']as$u){$weight+=$raid['flinger']['weights'][$u['type']];}
 v2check($weight<=250&&!empty(v2state($raid['id'])['botPlan']),'bot raid persists further buckets and respects initial Flinger capacity');
 $seq=v2state($raid['id'])['unitSeq'];BattleService::poll($bot,$raid['id']);v2check(v2state($raid['id'])['unitSeq']===$seq,'immediate bot polling cannot bypass Flinger reload');
 v2age($raid['id'],11);$caught=BattleService::poll($bot,$raid['id']);$deployTimes=array_unique(array_column(array_filter($caught['events'],fn($e)=>$e['type']==='deploy'),'t'));
 v2check(v2state($raid['id'])['unitSeq']>$seq&&in_array(5,$deployTimes)&&in_array(10,$deployTimes),'worker catch-up deploys planned buckets at five-second chronological intervals');BattleService::retreat($bot,$raid['id']);

 $ga=v2player();$gd=v2player(true);$gbase=Game::loadBase($gd);$gbase['buildings']=[v2building(1,'townhall',24,24,100000),v2building(3,'bunker',12,12,10000)];$gbase['garrison']=['3'=>['fink'=>1,'pokey'=>1]];Game::saveBase($gd,$gbase);
 $ab=Game::loadBase($ga);$ab['army']['fang']=1;Game::saveBase($ga,$ab);$gb=BattleService::start($ga,$gd);BattleService::deploy($ga,$gb['id'],['fang'=>1],8,12);$harmless=v2state($gb['id']);$harmless['units'][0]['_damage']=0;$harmless['units'][0]['_speed']=0;$harmless['units'][0]['hp']=100000;v2save($gb['id'],$harmless);
 v2age($gb['id'],1);$gs=BattleService::poll($ga,$gb['id']);v2check(count(array_filter($gs['units'],fn($u)=>$u['team']==='defender'))===2,'saved bunker inventory becomes actual defender battle units');
 // Force one known defender casualty in the test fixture, then settle twice to validate accounting.
 $raw=v2state($gb['id']);foreach($raw['units']as&$unit){if($unit['team']==='defender'&&$unit['type']==='fink'){$unit['hp']=0;$unit['state']='dead';}}unset($unit);v2save($gb['id'],$raw);
 BattleService::retreat($ga,$gb['id']);$garrison=Game::loadBase($gd)['garrison'];v2check(($garrison['3']['fink']??0)===0&&($garrison['3']['pokey']??0)===1,'only dead bunker defenders are removed from persistent garrison');
 BattleService::poll($ga,$gb['id']);v2check(Game::loadBase($gd)['garrison']===$garrison,'duplicate settlement cannot consume garrison survivors');

 $ta=v2player();$td=v2player(true);$tb=Game::loadBase($td);$tb['buildings'][]=v2building(3,'trap',10,10);Game::saveBase($td,$tb);$trapBattle=BattleService::start($ta,$td);BattleService::deploy($ta,$trapBattle['id'],'pokey',10,10);v2age($trapBattle['id'],1);$tripped=BattleService::poll($ta,$trapBattle['id']);
 v2check(count(array_filter($tripped['buildings'],fn($b)=>$b['type']==='trap'&&$b['hp']==0))===1,'triggering reveals a consumed trap to the attacker');BattleService::retreat($ta,$trapBattle['id']);
 $spent=Game::loadBase($td);v2check($spent['buildings'][2]['hp']==0,'triggered trap remains spent in persistent base');Game::mutate($td,'repair',['id'=>3]);
 v2check(Game::loadBase($td)['buildings'][2]['hp']>0,'normal building repair rearms a spent trap');
 $ruinA=v2player();$ruinD=v2player(true);$ruinBase=Game::loadBase($ruinD);$ruinBase['buildings'][]=v2building(3,'bunker',12,12);$ruinBase['buildings'][2]['hp']=0;$ruinBase['garrison']=['3'=>['fink'=>2]];Game::saveBase($ruinD,$ruinBase);
 $ruinBattle=BattleService::start($ruinA,$ruinD);BattleService::retreat($ruinA,$ruinBattle['id']);
 v2check((Game::loadBase($ruinD)['garrison']['3']['fink']??0)===2,'an unrelated later raid cannot erase survivors stationed at an already-ruined bunker');

 $upgradeA=v2player();$upgradeD=v2player(true);$upgradeBase=Game::loadBase($upgradeD);
 foreach([3=>10,4=>16,5=>22]as$buildingId=>$y){$building=v2building($buildingId,'hatchery',10,$y,1800);$building['ready_at']=time()+100;$building['upgrade_to']=2;$upgradeBase['buildings'][]=$building;}Game::saveBase($upgradeD,$upgradeBase);
 $upgradeBattle=BattleService::start($upgradeA,$upgradeD);$upgradeState=v2state($upgradeBattle['id']);
 foreach($upgradeState['buildings']as&$building){if($building['id']===4){$building['hp']=1200;}if($building['id']===5){$building['hp']=0;}}unset($building);v2save($upgradeBattle['id'],$upgradeState);
 $upgradeBase=Game::loadBase($upgradeD);foreach($upgradeBase['buildings']as&$building){if(in_array($building['id'],[3,4,5],true)){$building['ready_at']=time()-1;}}unset($building);Game::saveBase($upgradeD,$upgradeBase);
 BattleService::retreat($upgradeA,$upgradeBattle['id']);$upgraded=array_column(Game::loadBase($upgradeD)['buildings'],null,'id');
 v2check($upgraded[3]['level']===2&&(float)$upgraded[3]['hp']===2430.0,'settlement preserves full HP on an untouched completed upgrade');
 v2check((float)$upgraded[4]['hp']===1830.0,'settlement carries actual damage into an upgraded building without inventing more');
 v2check((float)$upgraded[5]['hp']===0.0,'upgrade completion cannot resurrect a building destroyed during the invasion');
 echo "Combat0.2databaseintegration: $checks checks passed.\n";
}finally{if($pdo->inTransaction()){$pdo->rollBack();}}
