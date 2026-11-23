<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Catalog.php';
require_once __DIR__.'/../app/Game.php';
require_once __DIR__.'/../app/BattleService.php';

$checks=0;
function fcCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function fcNear(float $a,float $b):bool{return abs($a-$b)<0.00001;}
function fcBuilding(int $id,string $type,float $x,float $y,int $tier=0):array{
    $spec=Catalog::buildings()[$type];
    return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>1,'hp'=>100000.0,'max_hp'=>100000.0,'fortification'=>$tier,
        'fortificationReduction'=>[0,.2,.3,.4,.5][$tier],'_fortificationReduction'=>[0,.2,.3,.4,.5][$tier],
        '_initial_hp'=>100000.0,'_size'=>$spec['size'],'_damage'=>0.0,'_range'=>$spec['range']??0,'_interval'=>$spec['attackInterval']??1,
        '_cooldown'=>0.0,'_ready'=>true,'_untargetable'=>$spec['untargetable']??false,'_targetMode'=>$spec['targetMode']??'both'];
}
function fcUnit(string $type,float $x=8.5,float $y=10,int $lab=0):array{
    $u=(new ReflectionMethod(BattleService::class,'makeUnit'))->invoke(null,$type,1,'u1','attacker',$x,$y,null,$lab);
    $u['_damage']=100.0;$u['_speed']=0.0;return $u;
}
function fcArena(array $buildings,array $units=[],int $format=6):array{
    return ['id'=>1,'formatVersion'=>$format,'status'=>'active','elapsed'=>0.0,'duration'=>300,'buildings'=>$buildings,'units'=>$units,
        'events'=>[],'eventSeq'=>0,'obstacleVersion'=>0,'remaining'=>['pokey'=>1],'attacker'=>['id'=>1],'defender'=>['id'=>2],'result'=>null];
}
function fcDamage(array $state,int $index):float{return 100000.0-(float)$state['buildings'][$index]['hp'];}

// Real simulation strikes, with full-health denominators unchanged at every tier.
foreach([0,20,30,40,50] as $tier=>$percent){
    $s=fcArena([fcBuilding(1,'sniper',10,10,$tier)],[fcUnit('pokey')]);BattleEngine::advance($s,.25);
    fcCheck(fcNear(fcDamage($s,0),100-$percent),"tier $tier reduces actual ordinary strike damage by $percent percent");
    fcCheck($s['buildings'][0]['max_hp']===100000.0&&$s['buildings'][0]['_initial_hp']===100000.0,"tier $tier preserves health and capture denominators");
    $hit=array_values(array_filter($s['events'],fn($event)=>$event['type']==='hit'&&($event['targetId']??null)===1));
    fcCheck(count($hit)===1&&fcNear((float)$hit[0]['amount'],100-$percent),"tier $tier shows the mitigated hit rather than unearned damage");
}

// Primary, extra, projectile, emergence, explosion and ricochet hits all use the same once-only reduction.
foreach(['pokey'=>0,'fink'=>3,'dave'=>3,'wormzer'=>3,'eyera'=>3,'teratorn'=>3] as $type=>$lab){
    $x=$type==='teratorn'?7.0:8.5;$control=fcArena([fcBuilding(1,'sniper',10,10),fcBuilding(2,'storage',10,11.4)],[fcUnit($type,$x,10,$lab)]);$control['units'][0]['targetId']=1;
    $armored=$control;foreach($armored['buildings'] as &$building){$building['fortification']=4;$building['_fortificationReduction']=.5;}unset($building);
    BattleEngine::advance($control,.25);BattleEngine::advance($armored,.25);
    fcCheck(fcDamage($control,0)>0&&fcNear(fcDamage($armored,0),fcDamage($control,0)*.5),"$type primary damage receives fortification exactly once");
    if(in_array($type,['fink','wormzer','eyera','teratorn'],true)){
        fcCheck(fcDamage($control,1)>0&&fcNear(fcDamage($armored,1),fcDamage($control,1)*.5),"$type secondary building damage respects its own protection");
    }
}

// Different towers retain separate tiers. Ricochet attenuation does not compound earlier armor.
$s=fcArena([fcBuilding(1,'sniper',10,10,4),fcBuilding(2,'storage',12,10,1),fcBuilding(3,'townhall',14,10,2)],[fcUnit('teratorn',7,10,3)]);
BattleEngine::advance($s,.25);
fcCheck(fcNear(fcDamage($s,0),50)&&fcNear(fcDamage($s,1),40)&&fcNear(fcDamage($s,2),17.5),'each ricochet halves raw damage before applying the new target armor once');

// A researched Project X burst is actual death-triggered damage, not a manual HP adjustment.
$u=fcUnit('projectx',8.5,10,3);$u['hp']=1;
$control=fcArena([fcBuilding(1,'sniper',10,10)],[$u]);$armored=$control;$armored['buildings'][0]['_fortificationReduction']=.5;
$hurtUnit=new ReflectionMethod(BattleEngine::class,'hurtUnit');
$hurtUnit->invokeArgs(null,[&$control,0,100.0,'test']);$hurtUnit->invokeArgs(null,[&$armored,0,100.0,'test']);
fcCheck(fcDamage($control,0)>0&&fcNear(fcDamage($armored,0),fcDamage($control,0)*.5),'Project X death burst respects building protection exactly once');
fcCheck(count(array_filter($armored['events'],fn($event)=>$event['type']==='acid'))===1&&count(array_filter($armored['events'],fn($event)=>$event['type']==='death'))===1,'fortified burst victims cannot duplicate the source death');

// The Catapult's storage/tower/wall factors remain independent of fortification.
foreach(['twig','pebble'] as $resource){
    $buildings=[fcBuilding(1,'townhall',10,10,4),fcBuilding(2,'storage',10,10,4),fcBuilding(3,'sniper',10,10,4),fcBuilding(4,'wall',10,10),fcBuilding(5,'championcage',10,10),fcBuilding(6,'outpost',10,10)];
    $buildings[1]['level']=2;$buildings[2]['_damage']=100;
    $s=fcArena($buildings);$s['catapult']=['pending'=>[['type'=>$resource,'level'=>1,'x'=>10,'y'=>10,'impactAt'=>1,'radius'=>5,'_bomb'=>['damage'=>1000]]]];
    BattleEngine::advance($s,.75);fcCheck(fcDamage($s,0)===0.0,"$resource impact waits for its saved server deadline");
    BattleEngine::advance($s,1);
    fcCheck(fcNear(fcDamage($s,0),500)&&fcNear(fcDamage($s,1),1000)&&fcNear(fcDamage($s,2),450),"$resource applies armor after ordinary, storage and tower factors");
    fcCheck(fcNear(fcDamage($s,3),60)&&fcDamage($s,4)===0.0&&fcDamage($s,5)===0.0,"$resource retains wall resistance and Cage/core exclusions");
    $before=array_column($s['buildings'],'hp');BattleEngine::advance($s,3);
    fcCheck(array_column($s['buildings'],'hp')===$before,"$resource cannot damage fortified targets twice after impact");
}

// Old saved snapshots ignore new base data even if a tier-like field exists.
foreach([0,2,3,4,5] as $format){
    $b=fcBuilding(1,'sniper',10,10,4);$s=fcArena([$b],[fcUnit('pokey')],$format);BattleEngine::advance($s,.25);
    fcCheck(fcNear(fcDamage($s,0),100),"legacy format $format receives no retroactive fortification reduction");
}
$s=fcArena([fcBuilding(1,'sniper',10,10,4)],[fcUnit('pokey')]);unset($s['buildings'][0]['_fortificationReduction']);BattleEngine::advance($s,.25);
fcCheck(fcNear(fcDamage($s,0),100),'a tier label cannot invent protection without its frozen combat value');

// Readiness freezes for this invasion, while previously completed armor remains effective.
$tower=fcBuilding(1,'sniper',10,10,2);$tower['_damage']=100;$tower['_ready']=false;$tower['fortifying']=true;$tower['fortify_ready_at']=1;
$u=fcUnit('pokey');$s=fcArena([$tower],[$u]);BattleEngine::advance($s,3);
fcCheck($s['units'][0]['hp']===$u['hp']&&fcDamage($s,0)>0&&fcNear(fcDamage($s,0),210),'a fortifying tower retains old protection but cannot fire when its external timer passes');
$s['buildings'][0]['hp']=10000;
fcCheck(fcNear(BattleEngine::captureProgress($s),90),'fortification does not change the ninety-percent health capture threshold');
$battleBuilding=$s['buildings'][0];$current=$battleBuilding;$current['fortification']=3;$current['hp']=99999;
fcCheck(fcNear(BattleEngine::settledHealth($battleBuilding,$current),10000),'fortification completion during combat cannot repair invasion damage');

$s=fcArena([fcBuilding(1,'sniper',10,10,4),fcBuilding(2,'storage',12,10,1)],[fcUnit('teratorn',7,10,3)]);
$a=$s;$b=$s;BattleEngine::advance($a,8);for($step=1;$step<=32;$step++){BattleEngine::advance($b,$step*.25);}
fcCheck($a===$b,'fortified combat remains deterministic across polling cadence');
echo "Fortification combat: $checks checks passed.\n";
