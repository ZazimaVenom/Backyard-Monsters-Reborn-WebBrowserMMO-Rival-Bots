<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Catalog.php';
require_once __DIR__.'/../app/Game.php';
require_once __DIR__.'/../app/BattleService.php';
$checks=0;
function wcCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function wcNear(float $a,float $b):bool{return abs($a-$b)<0.000001;}
function wcBuilding(int $id,string $type,float $hp,float $max,float $x=16,float $y=16):array{return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>1,'hp'=>$hp,'max_hp'=>$max,'_initial_hp'=>$hp,'_size'=>2,'_damage'=>0,'_range'=>7,'_interval'=>1,'_cooldown'=>0,'_ready'=>true,'_untargetable'=>$type==='outpost'];}
function wcUnit(string $type='pokey',float $x=8,float $y=16,int $lab=0):array{return (new ReflectionMethod(BattleService::class,'makeUnit'))->invoke(null,$type,1,'u1','attacker',$x,$y,null,$lab);}
function wcState(array $buildings,array $units=[]):array{return ['id'=>1,'formatVersion'=>5,'status'=>'active','elapsed'=>0.0,'duration'=>300,'buildings'=>$buildings,'units'=>$units,'unitSeq'=>0,'events'=>[],'eventSeq'=>0,'obstacleVersion'=>0,'remaining'=>['pokey'=>1],'attacker'=>['id'=>1],'defender'=>['id'=>0,'neutral'=>true],'result'=>null,'target'=>['kind'=>'resource','id'=>12,'name'=>'Site','q'=>8,'r'=>9,'ownerId'=>null,'captureThreshold'=>90]];}

$s=wcState([wcBuilding(1,'outpost',1,1),wcBuilding(2,'sniper',100,1000),wcBuilding(3,'wall',1000000,1000000),wcBuilding(4,'trap',1000000,1000000)]);
wcCheck(wcNear(BattleEngine::captureProgress($s),90),'capture ignores wall, trap and untargetable core maximum health');
BattleEngine::finish($s,'retreat');
wcCheck($s['result']['captured']&&!$s['result']['attackerWon'],'90 percent health capture remains independent of ordinary destroyed-building victory');
wcCheck($s['result']['winnerId']===null&&$s['result']['rankDelta']===['attacker'=>-15,'defender'=>0],'neutral defense has no phantom winning account or rank change');
wcCheck($s['result']['target']===$s['target'],'strategic result retains immutable target metadata');
$s=wcState([wcBuilding(1,'sniper',100.001,1000)]);BattleEngine::finish($s,'retreat');
wcCheck(!$s['result']['captured']&&$s['result']['captureProgress']<90,'rounding cannot turn damage below 90 percent into capture');
$s=wcState([wcBuilding(1,'sniper',9000,10000),wcBuilding(2,'bunker',0,100),wcBuilding(3,'storage',0,100)]);
$s['buildings'][1]['_initial_hp']=100;$s['buildings'][2]['_initial_hp']=100;BattleEngine::finish($s,'retreat');
wcCheck($s['result']['attackerWon']&&!$s['result']['captured'],'ordinary victory at two destroyed buildings does not capture the healthy large tower');
$s=wcState([wcBuilding(1,'sniper',50,1000)]);
wcCheck(wcNear(BattleEngine::captureProgress($s),95),'capture denominator is maximum HP rather than damaged HP at invasion start');
$s['buildings'][0]['hp']=0;$s['buildings'][0]['_initial_hp']=0;
wcCheck(wcNear(BattleEngine::captureProgress($s),100),'persistent ruins remain eligible health damage in a later attack');
wcCheck(BattleEngine::captureProgress(wcState([wcBuilding(1,'outpost',1,1)]))===0.0,'an empty site cannot fabricate capture progress');
$s=wcState([wcBuilding(1,'sniper',3000,1000)]);
wcCheck(BattleEngine::captureProgress($s)===0.0,'over-maximum health cannot make strategic damage negative');
$s['buildings'][0]['hp']=-100;
wcCheck(BattleEngine::captureProgress($s)===100.0,'negative health cannot exceed complete destruction');

$core=wcBuilding(1,'outpost',1,1,12,16);$core['_size']=8;
$s=wcState([$core,wcBuilding(2,'sniper',100,100,20,16)],[wcUnit()]);
wcCheck(BattleEngine::legalDeployment($s,12,16),'resource core does not create an invisible deployment barrier');
$path=(new ReflectionMethod(BattleEngine::class,'path'))->invoke(null,$s,$s['units'][0],$s['buildings'][1]);
wcCheck(count(array_filter($path,static fn($p)=>$p[0]>9&&$p[0]<15&&abs($p[1]-16)<1))>0,'ground path passes through the untargetable core footprint');
$s['units'][0]['targetId']=1;$s['units'][0]['_damage']=1000;BattleEngine::advance($s,30);
wcCheck($s['buildings'][0]['hp']===1.0&&$s['buildings'][1]['hp']===0.0,'a stale core target is replaced by a real defense and the core remains intact');
wcCheck($s['status']==='finished'&&$s['result']['reason']==='yard_destroyed'&&$s['result']['captured'],'living resource core cannot block battle completion');
wcCheck($s['result']['destruction']===100.0,'untargetable core is absent from ordinary destruction count too');
$finished=$s;BattleEngine::advance($s,300);BattleEngine::finish($s,'retreat');
wcCheck($finished===$s,'finished strategic simulation is idempotent');

// All damage routes must preserve core health, including extras and delayed artillery.
foreach(['fink'=>2,'teratorn'=>2,'wormzer'=>2,'eyera'=>1,'projectx'=>2] as $type=>$lab){
    $core=wcBuilding(1,'outpost',1,1,10,16);$s=wcState([$core,wcBuilding(2,'sniper',10000,10000,12,16)],[wcUnit($type,9,16,$lab)]);
    $s['units'][0]['_damage']=10000;BattleEngine::advance($s,2);
    wcCheck($s['buildings'][0]['hp']===1.0,$type.' special attacks cannot damage the untargetable core');
}
$core=wcBuilding(1,'outpost',1,1);$s=wcState([$core,wcBuilding(2,'sniper',1000,1000,17,16)]);
$s['catapult']=['pending'=>[['type'=>'twig','level'=>1,'x'=>16,'y'=>16,'impactAt'=>1,'radius'=>5,'_bomb'=>['damage'=>1000]]]];
BattleEngine::advance($s,1);
wcCheck($s['buildings'][0]['hp']===1.0&&$s['buildings'][1]['hp']<1000,'Catapult hits the defense while preserving its neighboring untargetable core');

$strong=wcBuilding(1,'stronghold',100000,100000);$strong['_damage']=100;$strong['_range']=10;
$s=wcState([$strong],[wcUnit('pokey',10,16)]);$s['target']['kind']='stronghold';$before=$s['units'][0]['hp'];BattleEngine::advance($s,.25);
wcCheck($s['units'][0]['hp']<$before,'Stronghold core operates as a real defending tower');
$s['buildings'][0]['hp']=10000;
wcCheck(wcNear(BattleEngine::captureProgress($s),90),'Stronghold core maximum HP contributes to capture');

// Stronghold bonus is frozen and applied only when a monster is created, not every poll.
$s=wcState([wcBuilding(1,'sniper',100000,100000,24,24)]);$s['remaining']=['pokey'=>2];$s['research']=[];$s['_worldBonuses']=['attacker'=>.3,'defender'=>.2];
$base=['army'=>['pokey'=>2]];$deploy=new ReflectionMethod(BattleService::class,'deployBucket');$deploy->invokeArgs(null,[&$s,&$base,'pokey',1.0,1.0]);
$expected=Catalog::monsters()['pokey']['damage']*1.3;
wcCheck(wcNear($s['units'][0]['_damage'],$expected)&&$base['army']['pokey']===1,'paid deployed monster receives the frozen additive Stronghold damage multiplier once');
BattleEngine::advance($s,2);$once=$s['units'][0]['_damage'];BattleEngine::advance($s,3);
wcCheck(wcNear($s['units'][0]['_damage'],$once),'polling does not compound the frozen attacking bonus');
$legacy=wcState([wcBuilding(1,'sniper',100000,100000,24,24)]);$legacy['formatVersion']=4;unset($legacy['target']);$legacy['research']=[];$base=['army'=>['pokey'=>1]];
$deploy->invokeArgs(null,[&$legacy,&$base,'pokey',1.0,1.0]);
wcCheck(wcNear($legacy['units'][0]['_damage'],Catalog::monsters()['pokey']['damage']),'saved v4 battles receive no new strategic bonus');
BattleEngine::finish($legacy,'retreat');
wcCheck(!array_key_exists('captured',$legacy['result']),'legacy home result format retains its original victory semantics');
echo "World combat: $checks checks passed.\n";
