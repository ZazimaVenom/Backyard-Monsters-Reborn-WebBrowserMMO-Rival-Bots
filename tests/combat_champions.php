<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Catalog.php';
require_once __DIR__.'/../app/BattleEngine.php';
$checks=0;
function cpCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function cpBuilding(int $id,string $type,float $x,float $y,float $hp=100000):array{
    $c=Catalog::buildings()[$type];
    return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>1,'hp'=>$hp,'max_hp'=>$hp,'_initial_hp'=>$hp,'_size'=>$c['size'],'_damage'=>$c['damage']??0,'_range'=>$c['range']??0,'_interval'=>$c['attackInterval']??1,'_cooldown'=>0,'_ready'=>true,'_targetMode'=>$c['targetMode']??'both','_splash'=>$c['splash']??0];
}
function cpUnit(string $id,string $type,float $x,float $y,string $team='attacker',int $level=1):array{
    $champion=isset(Catalog::champions()[$type]);$c=$champion?Catalog::championStats($type,$level):Catalog::monsters()[$type];
    return ['id'=>$id,'type'=>$type,'team'=>$team,'champion'=>$champion,'level'=>$level,'movement'=>$c['movement']??'ground','x'=>$x,'y'=>$y,'hp'=>(float)$c['hp'],'max_hp'=>(float)$c['hp'],'state'=>'walk','targetId'=>null,'auraRange'=>$c['auraRadius']??0,'auraBuff'=>$c['auraBuff']??0,'_damage'=>$c['damage'],'_speed'=>$c['speed'],'_range'=>$c['range'],'_interval'=>$c['attackInterval'],'_target'=>$c['target'],'_targetMode'=>$c['targetMode']??'ground','_ability'=>$c['ability']??'','_heal'=>$c['heal']??0,'_splash'=>$c['splash']??0,'_submerged'=>false,'_cooldown'=>0,'_repath'=>0,'_path'=>[],'_obstacleVersion'=>-1];
}
function cpArena(array $buildings,array $units):array{return ['id'=>1,'status'=>'active','elapsed'=>0.0,'duration'=>300,'buildings'=>$buildings,'units'=>$units,'events'=>[],'eventSeq'=>0,'obstacleVersion'=>0,'remaining'=>['pokey'=>1],'attacker'=>['id'=>1],'defender'=>['id'=>2],'result'=>null];}

// Compare actual travel, attacks and incoming damage rather than just the catalog entries.
$runner=cpUnit('u1','teratorn',4,4);$runner['_range']=0.5;$runner['_targetMode']='both';
$fomor=cpUnit('ca','fomor',5,4);$fomor['_speed']=0;$fomor['_damage']=0;
$control=cpArena([cpBuilding(1,'townhall',24,4)],[$runner]);$aura=cpArena($control['buildings'],[$runner,$fomor]);
BattleEngine::advance($control,1);BattleEngine::advance($aura,1);
cpCheck($aura['units'][0]['x']>$control['units'][0]['x']+0.2,'Fomor increases nearby allied movement speed');
cpCheck($aura['units'][0]['buffed']&&$aura['units'][0]['buffSource']==='ca'&&!$aura['units'][1]['buffed'],'aura marks its allied source and excludes the champion itself');
$attacker=cpUnit('u1','pokey',8,10);$attacker['_speed']=0;$fomor['x']=7;$fomor['y']=10;
$control=cpArena([cpBuilding(1,'townhall',10,10)],[$attacker]);$aura=cpArena($control['buildings'],[$attacker,$fomor]);
BattleEngine::advance($control,5);BattleEngine::advance($aura,5);
cpCheck($aura['buildings'][0]['hp']<$control['buildings'][0]['hp'],'Fomor increases actual allied attack cadence');

$tower=cpBuilding(1,'sniper',10,10);$tower['_damage']=100;$tower['_range']=3;
$target=cpUnit('u1','ichi',8,10);$target['_speed']=0;$target['_damage']=0;$fomor['x']=5;$fomor['y']=10;
$s=cpArena([$tower,cpBuilding(2,'townhall',24,24)],[$target,$fomor]);BattleEngine::advance($s,.25);
cpCheck(abs($s['units'][0]['hp']-($target['hp']-90))<.001,'level-one aura reduces actual incoming damage by ten percent');
$fomor['x']=1;$s=cpArena([$tower,cpBuilding(2,'townhall',24,24)],[$target,$fomor]);BattleEngine::advance($s,.25);
cpCheck($s['units'][0]['hp']===$target['hp']-100&&!$s['units'][0]['buffed'],'out-of-range allies lose armor and their aura marker');
$fomor['x']=8;$fomor['y']=10;$target['x']=5;$s=cpArena([$tower,cpBuilding(2,'townhall',24,24)],[$target,$fomor]);BattleEngine::advance($s,.25);
cpCheck($s['units'][1]['hp']===$fomor['hp']-100,'Fomor does not reduce incoming damage to itself');
$fomor['team']='defender';$fomor['x']=5;$fomor['_range']=0;$target['x']=8;
$s=cpArena([$tower,cpBuilding(2,'townhall',24,24)],[$target,$fomor]);BattleEngine::advance($s,.25);
cpCheck(!$s['units'][0]['buffed']&&$s['units'][0]['hp']===$target['hp']-100,'enemy champions cannot empower attacking monsters');

$fomor=cpUnit('ca','fomor',8,8);$fomor['_damage']=0;$fomor['_speed']=0;$target=cpUnit('u1','ichi',9,8);$target['hp']=100;$target['_damage']=0;$target['_speed']=0;
$s=cpArena([cpBuilding(1,'townhall',24,24)],[$fomor,$target]);BattleEngine::advance($s,5);
cpCheck($s['units'][1]['hp']===100&&count(array_filter($s['events'],fn($e)=>$e['type']==='heal'))===0,'Fomor aura never turns into healing');
$s['units'][0]['x']=25;BattleEngine::advance($s,5.25);
cpCheck(!$s['units'][1]['buffed']&&$s['units'][1]['buffSource']===null,'moving outside the aura clears prior buff state');
$s['units'][0]['x']=8;BattleEngine::advance($s,5.5);$s['units'][0]['hp']=0;BattleEngine::advance($s,5.75);
cpCheck(!$s['units'][1]['buffed'],'a defeated champion cannot keep buffing allies');

$fomor=cpUnit('ca','fomor',10,10);$fomor['_speed']=0;$fomor['_damage']=0;$fomor['hp']=1;
$ally=cpUnit('u1','ichi',14,10);$ally['_speed']=0;$ally['_damage']=0;
$t1=cpBuilding(1,'sniper',10,12);$t1['_range']=2.5;$t1['_damage']=100;
$t2=cpBuilding(2,'sniper',14,12);$t2['_range']=2.5;$t2['_damage']=100;
$s=cpArena([$t1,$t2,cpBuilding(3,'townhall',24,24)],[$fomor,$ally]);BattleEngine::advance($s,.25);
cpCheck($s['units'][0]['hp']===0.0&&$s['units'][1]['hp']===$ally['hp']-100,'champion defeat removes mitigation before a later tower in the same tick');
cpCheck(count(array_filter($s['events'],fn($e)=>$e['type']==='champion_retreat'))===1,'champion defeat emits a retreat event instead of deleting the individual');

$cannon=cpBuilding(1,'cannon',10,10);$cannon['_damage']=100;
$ground=cpUnit('ca','fomor',8,10,'attacker',2);$ground['_speed']=0;$ground['_damage']=0;
$air=cpUnit('ca','fomor',8,10,'attacker',3);$air['_speed']=0;$air['_damage']=0;
$s=cpArena([$cannon,cpBuilding(2,'townhall',24,24)],[$ground]);BattleEngine::advance($s,.25);
cpCheck($s['units'][0]['hp']<$ground['hp'],'early Fomor evolution remains vulnerable to ground-only defenses');
$s=cpArena([$cannon,cpBuilding(2,'townhall',24,24)],[$air]);BattleEngine::advance($s,.25);
cpCheck($s['units'][0]['hp']===$air['hp'],'level-three Fomor takes flight and bypasses ground-only fire');
foreach(['gorgo','drull'] as $type){
    $defender=cpUnit('cd',$type,8,8,'defender');$defender['_speed']=0;$flying=cpUnit('u1','teratorn',8.2,8);$flying['_damage']=0;$flying['_speed']=0;
    $s=cpArena([cpBuilding(1,'townhall',24,24)],[$defender,$flying]);BattleEngine::advance($s,.25);
    cpCheck($s['units'][1]['hp']===$flying['hp'],ucfirst($type).' melee cannot strike an airborne enemy');
}
$defender=cpUnit('cd','drull',8,8,'defender');$target=cpUnit('u1','pokey',8.2,8);$target['_speed']=0;$target['_damage']=0;
$s=cpArena([cpBuilding(1,'townhall',24,24)],[$defender,$target]);$s['remaining']=['pokey'=>0];BattleEngine::advance($s,.25);
cpCheck($s['units'][1]['hp']===0.0&&$s['result']['reason']==='army_defeated'&&$s['buildings'][0]['hp']===100000.0,'defending Drull fights invading units and leaves its own buildings intact');
$s=cpArena([cpBuilding(1,'townhall',24,24)],[$defender,$target]);$s['remaining']=['pokey'=>0];$s['champion']=['available'=>true,'deployed'=>false];BattleEngine::advance($s,.25);
cpCheck($s['status']==='active','a deployable champion prevents premature defeat when regular attackers die');
$s=cpArena([cpBuilding(1,'townhall',24,24)],[$defender,$target]);$s['remaining']=['pokey'=>30];$s['_botReserved']=['pokey'=>30];BattleEngine::advance($s,.25);
cpCheck($s['status']==='finished','reserved champion food does not keep a defeated bot invasion open');

$eye=cpUnit('u1','eyera',8,8);$fomor=cpUnit('ca','fomor',8,7,'attacker',6);$fomor['_damage']=0;$fomor['_speed']=0;
$s=cpArena([cpBuilding(1,'wall',9,8,1000),cpBuilding(2,'townhall',24,24)],[$eye,$fomor]);BattleEngine::advance($s,.25);
cpCheck($s['units'][0]['hp']===0.0,'aura armor cannot let a self-destructing Eye-ra survive its own explosion');

$units=[cpUnit('ca','fomor',4,4,'attacker',3),cpUnit('u1','crabatron',5,4),cpUnit('u2','brain',4,5),cpUnit('cd','gorgo',20,20,'defender')];
$base=cpArena([cpBuilding(1,'townhall',24,24),cpBuilding(2,'cannon',16,16)],$units);$a=$base;$b=$base;
BattleEngine::advance($a,20);for($i=1;$i<=80;$i++){BattleEngine::advance($b,$i*.25);}
cpCheck($a===$b,'champion combat remains deterministic across different polling cadence');
$brain=cpUnit('u1','brain',8,8);$brain['_speed']=0;$crab=cpUnit('u1','crabatron',8,8);$crab['_speed']=0;
$buildings=[cpBuilding(1,'townhall',11,8),cpBuilding(2,'storage',8,14),cpBuilding(3,'sniper',14,8)];
$s=cpArena($buildings,[$brain]);BattleEngine::advance($s,.25);cpCheck($s['units'][0]['targetId']===2,'Brain prioritizes resource storage');
$s=cpArena($buildings,[$crab]);BattleEngine::advance($s,.25);cpCheck($s['units'][0]['targetId']===3,'Crabatron prioritizes defensive towers');

echo "Champion combat: $checks checks passed.\n";
