<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Catalog.php';
require_once __DIR__.'/../app/Game.php';
require_once __DIR__.'/../app/BattleService.php';
$checks=0;
function lcCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function lcNear(float $a,float $b):bool{return abs($a-$b)<0.001;}
function lcBuilding(int $id,string $type,float $x,float $y,float $hp=100000):array{$c=Catalog::buildings()[$type];return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>1,'hp'=>$hp,'max_hp'=>$hp,'_initial_hp'=>$hp,'_size'=>$c['size'],'_damage'=>$c['damage']??0,'_range'=>$c['range']??0,'_interval'=>$c['attackInterval']??1,'_cooldown'=>0,'_ready'=>true,'_targetMode'=>$c['targetMode']??'both','_splash'=>$c['splash']??0];}
function lcUnit(string $id,string $type,float $x,float $y,string $team='attacker',int $lab=0):array{return (new ReflectionMethod(BattleService::class,'makeUnit'))->invoke(null,$type,1,$id,$team,$x,$y,null,$lab);}
function lcArena(array $buildings,array $units):array{return ['id'=>1,'formatVersion'=>4,'status'=>'active','elapsed'=>0.0,'duration'=>300,'buildings'=>$buildings,'units'=>$units,'events'=>[],'eventSeq'=>0,'obstacleVersion'=>0,'remaining'=>['pokey'=>1],'attacker'=>['id'=>1],'defender'=>['id'=>2],'result'=>null];}
function lcEvents(array $s,string $type):array{return array_values(array_filter($s['events'],static fn(array $e):bool=>$e['type']===$type));}
function lcPending(array &$s,string $type,int $tier,float $x,float $y,float $at=1):void{$bomb=Catalog::catapultBombs()[$type]['levels'][$tier];$s['catapult']=['pending'=>[['type'=>$type,'level'=>$tier,'x'=>$x,'y'=>$y,'radius'=>$bomb['radius'],'impactAt'=>$at,'_bomb'=>$bomb]]];}

// Bolt traverses its saved legal path endpoint while briefly untargetable; the persisted event stream matters.
$bolt=lcUnit('u1','bolt',4,4,'attacker',1);$tower=lcBuilding(2,'sniper',4,6);$tower['_damage']=100;
$s=lcArena([lcBuilding(1,'townhall',8,4),$tower],[$bolt]);BattleEngine::advance($s,.25);
lcCheck($s['units'][0]['blinking']&&$s['units'][0]['hp']===$bolt['hp'],'Blink starts during approach and prevents targeted tower damage');
lcCheck(count(lcEvents($s,'blink'))===1,'Blink event persists in the authoritative effect stream');
$landing=$s['units'][0]['_blinkTo'];BattleEngine::advance($s,.75);
lcCheck(!$s['units'][0]['blinking']&&hypot($s['units'][0]['x']-$landing[0],$s['units'][0]['y']-$landing[1])<.1,'Blink completes at its path endpoint');
$bolt=lcUnit('u1','bolt',4,4,'attacker',1);$bolt['blinking']=true;$bolt['_blinkStart']=0;$bolt['_blinkUntil']=2;$bolt['_blinkFrom']=[4,4];$bolt['_blinkTo']=[4,4];
$s=lcArena([lcBuilding(1,'townhall',24,24),lcBuilding(2,'trap',4,4)],[$bolt]);BattleEngine::advance($s,.25);
lcCheck($s['buildings'][1]['hp']>0&&$s['units'][0]['hp']===$bolt['hp'],'Blink avoids new trap targeting during its short untargetable interval');

// Fink hits exactly its additional nearest ground targets, not flying targets or the primary twice.
$f=lcUnit('u1','fink',8,8,'attacker',2);$f['_damage']=100;$f['_speed']=0;
$d1=lcUnit('d1','ichi',8.3,8,'defender');$d2=lcUnit('d2','ichi',8.5,8.5,'defender');$air=lcUnit('d3','teratorn',8.3,7.7,'defender');
foreach([&$d1,&$d2,&$air] as &$v){$v['_damage']=0;$v['_speed']=0;}unset($v);
$s=lcArena([lcBuilding(1,'townhall',24,24),lcBuilding(2,'housing',9.9,8)],[$f,$d1,$d2,$air]);BattleEngine::advance($s,.25);
lcCheck(lcNear($s['units'][1]['hp'],$d1['hp']-100)&&lcNear($s['units'][2]['hp'],$d2['hp']-100)&&lcNear($s['buildings'][1]['hp'],99900),'Fink adds two full-damage targets without duplicating its primary');
lcCheck($s['units'][3]['hp']===$air['hp']&&count(lcEvents($s,'multistrike'))===2,'Fink extra attacks exclude airborne enemies and emit separate effects');

// Bandito attacks faster and sweeps nearby ground enemies, with no collateral building splash.
$u=lcUnit('u1','bandito',8,10);$u['_damage']=100;$u['_speed']=0;$fast=lcUnit('u1','bandito',8,10,'attacker',3);$fast['_damage']=100;$fast['_speed']=0;
$a=lcArena([lcBuilding(1,'townhall',10,10),lcBuilding(2,'housing',10,11.5)],[$u]);$b=lcArena($a['buildings'],[$fast]);BattleEngine::advance($a,4);BattleEngine::advance($b,4);
lcCheck($b['buildings'][0]['hp']<$a['buildings'][0]['hp'],'Whirlwind increases real attack cadence');
lcCheck($b['buildings'][1]['hp']===100000.0,'Whirlwind does not add splash damage to neighboring buildings');
$fast['x']=8;$fast['y']=8;$s=lcArena([lcBuilding(1,'townhall',24,24)],[$fast,$d1,$d2,$air]);BattleEngine::advance($s,.25);
lcCheck(lcNear($s['units'][2]['hp'],$d2['hp']-100)&&$s['units'][3]['hp']===$air['hp'],'Whirlwind sweeps enemy ground units but not fliers');

// Venom stacks per hit, persists after the attacker dies, and never poisons buildings.
$fang=lcUnit('u1','fang',8,8,'attacker',2);$fang['_damage']=100;$fang['_speed']=0;
$s=lcArena([lcBuilding(1,'townhall',24,24)],[$fang,$d1]);BattleEngine::advance($s,.25);$s['units'][0]['hp']=0;BattleEngine::advance($s,1.25);
lcCheck(lcNear($s['units'][1]['hp'],$d1['hp']-120)&&$s['units'][1]['poisoned'],'Venom applies a delayed pulse after Fang has died');
lcCheck(count(lcEvents($s,'venom'))===1,'Venom has a bounded numeric stack and one application effect per hit');
$fang['y']=10;$s=lcArena([lcBuilding(1,'townhall',10,10)],[$fang]);BattleEngine::advance($s,.25);$s['units'][0]['hp']=0;BattleEngine::advance($s,2);
lcCheck(lcNear($s['buildings'][0]['hp'],99900)&&!lcEvents($s,'venom'),'Venom never applies damage-over-time to a building');

// Airburst stays grounded, improves the blast and adds partial damage against airborne enemies.
$eye=lcUnit('u1','eyera',8,8,'attacker',1);$eye['_damage']=100;$air['x']=8;$air['y']=8;
$s=lcArena([lcBuilding(1,'townhall',24,24),lcBuilding(2,'wall',9,8)],[$eye,$air]);BattleEngine::advance($s,.25);
lcCheck($s['units'][0]['movement']==='ground'&&$s['units'][0]['hp']===0.0,'Airburst remains a grounded, consumed explosive');
lcCheck(lcNear($s['units'][1]['hp'],$air['hp']-24)&&lcNear(lcEvents($s,'explode')[0]['radius'],2.88),'Airburst expands the ground blast and applies its researched partial air damage');

// Brain cloak protects acquisition, but proximity traps still reveal their physical damage.
$brain=lcUnit('u1','brain',4,4,'attacker',1);$brain['cloaked']=false;$brain['_damage']=0;
$s=lcArena([lcBuilding(1,'townhall',24,24),$tower],[$brain]);BattleEngine::advance($s,.25);
lcCheck($s['units'][0]['cloaked']&&$s['units'][0]['hp']===$brain['hp'],'Moving Brain cloaks before towers choose a target');
lcCheck(count(lcEvents($s,'cloak'))===1,'Movement cloak transition persists in the authoritative event stream');
$brain=lcUnit('u1','brain',8,10,'attacker',1);$brain['_speed']=0;$brain['_damage']=0;$tower=lcBuilding(2,'sniper',12,10);$tower['_damage']=100;
$s=lcArena([lcBuilding(1,'townhall',10,10),$tower],[$brain]);BattleEngine::advance($s,1);
lcCheck($s['units'][0]['cloaked']&&$s['units'][0]['hp']===$brain['hp'],'Brain retains its one-second arrival cloak while attacking');
BattleEngine::advance($s,1.25);lcCheck(!$s['units'][0]['cloaked']&&$s['units'][0]['hp']<$brain['hp'],'Brain becomes targetable after its arrival delay');
$brain=lcUnit('u1','brain',4,4,'attacker',3);$trap=lcBuilding(2,'trap',4,4);$trap['_damage']=100;
$s=lcArena([lcBuilding(1,'townhall',24,24),$trap],[$brain]);BattleEngine::advance($s,.25);
lcCheck($s['buildings'][1]['hp']===0.0&&lcNear($s['units'][0]['hp'],$brain['hp']-100),'A cloaked Brain still triggers and takes damage from a proximity trap');

// Project X only bursts after laboratory research, and defensive bursts cannot harm their own yard.
$x=lcUnit('u1','projectx',8,10,'attacker',1);$x['_damage']=100;$x['_speed']=0;$x['hp']=1;
$tower=lcBuilding(1,'sniper',10,10);$tower['_damage']=100;$s=lcArena([$tower,lcBuilding(2,'townhall',24,24)],[$x]);BattleEngine::advance($s,.25);
lcCheck($s['units'][0]['hp']===0.0&&count(lcEvents($s,'acid'))===1&&lcNear($s['buildings'][0]['hp'],99960),'Researched Project X bursts once on death and damages a nearby structure');
$x=lcUnit('d1','projectx',8.3,8,'defender',1);$x['_damage']=100;$x['_speed']=0;$x['hp']=1;$killer=lcUnit('u1','ichi',8,8);$killer['_damage']=1000;$killer['_speed']=0;
$s=lcArena([lcBuilding(1,'townhall',10,8)],[$killer,$x]);BattleEngine::advance($s,.25);
lcCheck($s['buildings'][0]['hp']===100000.0&&lcNear($s['units'][0]['hp'],$killer['hp']-120),'Defensive Project X bursts hit enemies but never its own buildings');
$x=lcUnit('u1','projectx',8,10);$x['hp']=1;$x['_damage']=0;$x['_speed']=0;$s=lcArena([$tower,lcBuilding(2,'townhall',24,24)],[$x]);BattleEngine::advance($s,.25);
lcCheck(!lcEvents($s,'acid'),'Unresearched Project X has no free death-burst power');
$x=lcUnit('u1','projectx',4,4);$x['_speed']=0;$s=lcArena([lcBuilding(1,'townhall',7,4),lcBuilding(2,'bunker',12,4)],[$x]);BattleEngine::advance($s,.25);
lcCheck($s['units'][0]['targetId']===2,'Project X prioritizes defenses over nearer ordinary buildings');

// DAVE rockets reach airborne units and split the original attack damage into two projectiles.
$dave=lcUnit('u1','dave',8,8,'attacker',1);$dave['_damage']=100;$dave['_speed']=0;$air['x']=11;$air['y']=8;$air['_damage']=0;$air['_speed']=0;$air['_cooldown']=99;
$s=lcArena([lcBuilding(1,'townhall',24,24)],[$dave,$air]);BattleEngine::advance($s,.25);
lcCheck(lcNear($s['units'][1]['hp'],$air['hp']-100)&&count(lcEvents($s,'shot'))===2,'DAVE launches two half-damage rockets at an airborne opponent');
lcCheck(lcEvents($s,'shot')[0]['projectile']==='rocket'&&lcEvents($s,'shot')[1]['volley']===1,'DAVE rocket volleys have distinct renderer metadata');

// Wormzer gets one radial eruption for each new primary target, with distance falloff.
$worm=lcUnit('u1','wormzer',8,10,'attacker',3);$worm['_damage']=100;$worm['_speed']=0;
$s=lcArena([lcBuilding(1,'townhall',10,10),lcBuilding(2,'housing',11.5,10)],[$worm]);BattleEngine::advance($s,.25);
lcCheck($s['buildings'][0]['hp']<99900&&$s['buildings'][1]['hp']<100000&&count(lcEvents($s,'emerge_splash'))===1,'Wormzer emergence damages its primary and nearby structures with falloff');
BattleEngine::advance($s,3);lcCheck(count(lcEvents($s,'emerge_splash'))===1,'Wormzer does not repeatedly erupt while attacking the same target');

// Ricochets halve their damage and never bounce back into already visited structures.
$tera=lcUnit('u1','teratorn',7,10,'attacker',3);$tera['_damage']=100;$tera['_speed']=0;
$s=lcArena([lcBuilding(1,'townhall',10,10),lcBuilding(2,'housing',12,10),lcBuilding(3,'storage',14,10),lcBuilding(4,'hatchery',16,10)],[$tera]);BattleEngine::advance($s,.25);
lcCheck(lcNear($s['buildings'][0]['hp'],99900)&&lcNear($s['buildings'][1]['hp'],99950)&&lcNear($s['buildings'][2]['hp'],99975)&&lcNear($s['buildings'][3]['hp'],99987.5),'Teratorn chains three successively halved building ricochets');
lcCheck(count(lcEvents($s,'ricochet'))===3,'Ricochet effects identify each additional hit');

// Artillery impacts are delayed, authoritative, buildings-only and use source-specific resistances.
$buildings=[lcBuilding(1,'townhall',10,10),lcBuilding(2,'wall',10,10),lcBuilding(3,'sniper',10,10),lcBuilding(4,'storage',10,10),lcBuilding(5,'championcage',10,10),lcBuilding(6,'trap',10,10)];$buildings[2]['_range']=0;$buildings[3]['level']=2;
$u=lcUnit('u1','ichi',7,7);$u['_damage']=0;$u['_speed']=0;$s=lcArena($buildings,[$u]);lcPending($s,'twig',1,10,10);BattleEngine::advance($s,.75);
lcCheck($s['buildings'][0]['hp']===100000.0&&count($s['catapult']['pending'])===1,'Catapult damage waits for its server impact time');
BattleEngine::advance($s,1);$damage=Catalog::catapultBombs()['twig']['levels'][1]['damage'];
lcCheck(lcNear($s['buildings'][0]['hp'],100000-$damage)&&lcNear($s['buildings'][1]['hp'],100000-$damage*.06)&&lcNear($s['buildings'][2]['hp'],100000-$damage*.9)&&lcNear($s['buildings'][3]['hp'],100000-$damage*2),'Catapult applies ordinary, wall, tower and storage damage factors');
lcCheck($s['buildings'][4]['hp']===100000.0&&$s['buildings'][5]['hp']===100000.0&&$s['units'][0]['hp']===$u['hp'],'Resource damage bombs spare Cage, traps and monsters');
$health=array_column($s['buildings'],'hp');BattleEngine::advance($s,2);
lcCheck(array_column($s['buildings'],'hp')===$health&&count(lcEvents($s,'catapult_impact'))===1,'A resolved Catapult projectile cannot apply damage twice');

// Putty changes speed and incoming damage only, without healing or augmenting raw strike damage.
$u=lcUnit('u1','ichi',8.5,10);$u['_damage']=100;$u['_speed']=0;$u['hp']=1000;$tower=lcBuilding(1,'sniper',10,10);$tower['_damage']=100;$tower['_cooldown']=1;
$s=lcArena([$tower,lcBuilding(2,'townhall',24,24)],[$u]);lcPending($s,'putty',1,8,10);BattleEngine::advance($s,1);
lcCheck($s['units'][0]['boosted']&&lcNear($s['units'][0]['hp'],920),'Putty reduces incoming damage and never heals its recipient');
$hits=array_filter(lcEvents($s,'hit'),static fn(array $e):bool=>($e['sourceId']??'')==='u1');lcCheck(count($hits)>0&&array_reduce($hits,static fn(bool $ok,array $e):bool=>$ok&&$e['amount']===100.0,true),'Putty does not add outgoing strike damage');
$s['buildings'][0]['_damage']=0;BattleEngine::advance($s,11);lcCheck(!$s['units'][0]['boosted']&&lcNear($s['units'][0]['_auraSpeed'],1),'Putty expires at the authoritative deadline without permanent stat stacking');
$brain=lcUnit('u1','brain',4,4,'attacker',1);$brain['_damage']=0;$brain['_speed']=0;$d=lcUnit('d1','ichi',4,4,'defender');$d['_damage']=0;$d['_speed']=0;
$s=lcArena([lcBuilding(1,'townhall',24,24)],[$brain,$d]);lcPending($s,'putty',1,4,4,.25);BattleEngine::advance($s,.25);
lcCheck(!$s['units'][0]['boosted']&&!$s['units'][1]['boosted'],'Putty excludes cloaked attackers and defending monsters');

$u=lcUnit('u1','pokey',8,10);$u['_damage']=0;$u['_speed']=0;$u['hp']=1;$s=lcArena([lcBuilding(1,'sniper',10,10),lcBuilding(2,'townhall',24,24)],[$u]);$s['remaining']=['pokey'=>0];lcPending($s,'pebble',1,10,10);
BattleEngine::advance($s,.25);lcCheck($s['status']==='active'&&$s['units'][0]['hp']===0.0,'An in-flight paid projectile keeps a defeated army battle open until impact');
BattleEngine::advance($s,1);lcCheck($s['status']==='finished'&&count(lcEvents($s,'catapult_impact'))===1,'The final paid projectile resolves before defeat settlement');

$units=[];$n=0;foreach(Catalog::labAbilities() as $type=>$row){$u=lcUnit('u'.(++$n),$type,4+($n%4),4+intdiv($n,4),'attacker',3);$units[]=$u;}
$initial=lcArena([lcBuilding(1,'townhall',20,20),lcBuilding(2,'sniper',13,13),lcBuilding(3,'bunker',17,17)],$units);lcPending($initial,'putty',2,5,5);$a=$initial;$b=$initial;BattleEngine::advance($a,10);for($i=1;$i<=40;$i++){BattleEngine::advance($b,$i*.25);}
lcCheck($a===$b,'Laboratory powers and delayed artillery are deterministic across polling cadence');
// A new primary target triggers Wormzer's power even when no intervening burrow movement is needed.
$worm=lcUnit('u1','wormzer',8,10,'attacker',3);$worm['_damage']=100;$worm['_speed']=0;
$s=lcArena([lcBuilding(1,'townhall',10,10,150),lcBuilding(2,'housing',10,10.5,10000)],[$worm]);BattleEngine::advance($s,.25);
BattleEngine::advance($s,1.5);
lcCheck(count(lcEvents($s,'emerge_splash'))===2&&count(lcEvents($s,'emerge'))===1,'Wormzer applies a fresh burst to an adjacent new target without reburrowing');

// Stacked speed boosts must not be silently capped at one strike per simulation tick.
$bandito=lcUnit('u1','bandito',8,10,'attacker',3);$bandito['_damage']=100;$bandito['_speed']=0;$bandito['_puttyUntil']=10;$bandito['_puttySpeed']=2;$bandito['_puttyArmor']=.9;
$fomor=(new ReflectionMethod(BattleService::class,'makeChampionUnit'))->invoke(null,['type'=>'fomor','level'=>6,'hp'=>10000],'ca','attacker',5.0,10.0);$fomor['_damage']=0;$fomor['_speed']=0;
$s=lcArena([lcBuilding(1,'townhall',10,10)],[$bandito,$fomor]);BattleEngine::advance($s,4);
$strikes=array_values(array_filter(lcEvents($s,'hit'),static fn(array $e):bool=>($e['sourceId']??'')==='u1'));
lcCheck(count($strikes)>=51&&count($strikes)<=53,'Bandito, Fomor and Putty stack their real attack cadence beyond one strike per tick');
lcCheck(array_reduce($strikes,static fn(bool $ok,array $e):bool=>$ok&&$e['amount']===100.0,true),'Stacked attack-speed bonuses do not multiply per-strike damage');
$target=lcUnit('u1','ichi',8,10);$target['_speed']=0;$target['_damage']=0;$target['_puttyUntil']=10;$target['_puttySpeed']=2;$target['_puttyArmor']=.9;
$tower=lcBuilding(1,'sniper',10,10);$tower['_damage']=100;$tower['_range']=3;
$s=lcArena([$tower,lcBuilding(2,'townhall',24,24)],[$target,$fomor]);BattleEngine::advance($s,.25);
lcCheck(lcNear($s['units'][0]['hp'],$target['hp']-5),'Stacked Fomor and Putty armor is multiplicative and bounded to 95% reduction');

$fang=lcUnit('u1','fang',8,8,'attacker',2);$fang['_damage']=100;$fang['_speed']=0;
$s=lcArena([lcBuilding(1,'townhall',24,24)],[$fang,$d1]);BattleEngine::advance($s,2.5);
lcCheck(lcNear($s['units'][1]['_venom'],40)&&lcNear($s['units'][1]['hp'],$d1['hp']-260),'Repeated Fang strikes stack actual periodic venom damage without a growing effect list');

// Alternating defensive and offensive Project X bursts can chain without duplicate deaths or recursion loops.
$s=lcArena([lcBuilding(1,'townhall',30,30)],[]);
for($i=0;$i<200;$i++){$u=lcUnit('x'.$i,'projectx',16,16,$i%2===0?'attacker':'defender',3);$u['hp']=1;$u['_damage']=100;$s['units'][]=$u;}
(new ReflectionMethod(BattleEngine::class,'hurtUnit'))->invokeArgs(null,[&$s,0,100.0,'test']);
$deaths=lcEvents($s,'death');
lcCheck(count($deaths)===200&&count(lcEvents($s,'acid'))===200&&count(array_unique(array_column($deaths,'targetId')))===200&&!array_filter($s['units'],fn($u)=>$u['hp']>0),'Two hundred chained Project X bursts produce one death and one burst per monster');

// Airburst grants actual airborne target acquisition to both raiders and garrison defenders.
foreach(['attacker','defender'] as $team){
    $eye=lcUnit('u1','eyera',8,8,$team,1);$eye['_damage']=100;$eye['_speed']=0;$air=lcUnit('air','teratorn',8.3,8,$team==='attacker'?'defender':'attacker');$air['_damage']=0;$air['_speed']=0;
    $s=lcArena([lcBuilding(1,'townhall',24,24)],[$eye,$air]);BattleEngine::advance($s,.25);
    lcCheck($s['units'][0]['hp']===0.0&&$s['units'][1]['hp']<$air['hp'],'Researched '.$team.' Eye-Ra acquires and attacks an airborne enemy');
}
echo "Laboratory combat: $checks checks passed.\n";
