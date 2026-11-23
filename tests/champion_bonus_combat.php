<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Catalog.php';
require_once __DIR__.'/../app/Game.php';
require_once __DIR__.'/../app/BattleService.php';
$checks=0;
function cbCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function cbNear(float $a,float $b):bool{return abs($a-$b)<0.00001;}
function cbBuilding(int $id,string $type,float $x=10,float $y=10):array{
    $spec=Catalog::buildings()[$type];return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>1,'hp'=>100000.0,'max_hp'=>100000.0,
        '_initial_hp'=>100000.0,'_size'=>$spec['size'],'_damage'=>0,'_range'=>$spec['range']??0,'_interval'=>$spec['attackInterval']??1,'_cooldown'=>0,'_ready'=>true,'_targetMode'=>$spec['targetMode']??'both'];
}
function cbArena(array $buildings,array $units):array{return ['id'=>1,'formatVersion'=>6,'status'=>'active','elapsed'=>0.0,'duration'=>300,'buildings'=>$buildings,'units'=>$units,'events'=>[],'eventSeq'=>0,'obstacleVersion'=>0,'remaining'=>['pokey'=>1],'attacker'=>['id'=>1],'defender'=>['id'=>2],'result'=>null];}
function cbSnapshot(string $type,int $bonus,int $level=6):array{
    $stats=Catalog::championStats($type,$level,$bonus);
    $base=['activeChampion'=>$type,'champions'=>[$type=>['level'=>$level,'bonus_feeds'=>$bonus,'hp'=>$stats['hp']-123,'battle_id'=>0,'frozen'=>false]],'buildings'=>[['type'=>'championcage','level'=>1,'hp'=>100,'ready_at'=>0]]];
    return (new ReflectionMethod(BattleService::class,'readyChampion'))->invoke(null,$base);
}
function cbUnit(array $champion,string $id='ca',string $team='attacker',float $x=8.5,float $y=10):array{return (new ReflectionMethod(BattleService::class,'makeChampionUnit'))->invoke(null,$champion,$id,$team,$x,$y);}

foreach(['gorgo','drull','fomor'] as $type){
    $base=Catalog::championStats($type,6);
    foreach([0,1,2,3] as $tier){
        $snapshot=cbSnapshot($type,$tier);$unit=cbUnit($snapshot);$unit['_speed']=0;
        $stats=Catalog::championStats($type,6,$tier);
        cbCheck($snapshot['bonus_feeds']===$tier&&cbNear($unit['hp'],$stats['hp']-123)&&cbNear($unit['max_hp'],$stats['hp']),"$type tier $tier enters battle with its actual injury and bonus health");
        $s=cbArena([cbBuilding(1,'sniper')],[$unit]);BattleEngine::advance($s,.25);
        cbCheck(cbNear(100000-$s['buildings'][0]['hp'],$stats['damage']),"$type tier $tier uses its effective damage in a real strike");
        cbCheck(cbNear($unit['_speed'],0)&&cbNear($unit['_range'],$stats['range'])&&cbNear($unit['auraBuff'],$stats['auraBuff']),"$type tier $tier uses frozen range and aura values");
        $fort=cbArena([cbBuilding(1,'sniper')],[$unit]);$fort['buildings'][0]['_fortificationReduction']=.5;BattleEngine::advance($fort,.25);
        cbCheck(cbNear(100000-$fort['buildings'][0]['hp'],$stats['damage']*.5),"$type tier $tier cannot bypass a fortified building");
    }
    $plain=cbUnit(cbSnapshot($type,0),'ca','attacker',4,10);$fed=cbUnit(cbSnapshot($type,3),'ca','attacker',4,10);
    $a=cbArena([cbBuilding(1,'townhall',24,10)],[$plain]);$b=cbArena($a['buildings'],[$fed]);BattleEngine::advance($a,1);BattleEngine::advance($b,1);
    cbCheck($b['units'][0]['x']>$a['units'][0]['x'],"$type earned speed bonus increases actual travel toward a distant target");
    $legacy=['type'=>$type,'level'=>6,'hp'=>$base['hp']-123,'max_hp'=>$base['hp'],'bonus_feeds'=>3];$unit=cbUnit($legacy);
    cbCheck($unit['bonus_feeds']===0&&cbNear($unit['_damage'],$base['damage'])&&cbNear($unit['max_hp'],$base['hp']),"legacy $type snapshot gets no bonus from a new-looking maintenance field");
    $premature=cbSnapshot($type,3,5);$unit=cbUnit($premature);
    cbCheck($premature['bonus_feeds']===0&&$unit['bonus_feeds']===0&&cbNear($unit['_damage'],Catalog::championStats($type,5)['damage']),"$type before evolution six cannot display or use food bonus powers");

    // A later base change must not affect the champion already committed to an invasion.
    $snapshot=cbSnapshot($type,3);$s=cbArena([cbBuilding(1,'sniper',24,24)],[]);$s['champion']=$snapshot;$s['_worldBonuses']=['attacker'=>.3];
    $owned=['activeChampion'=>$type,'champions'=>[$type=>['level'=>6,'bonus_feeds'=>0,'hp'=>1,'battle_id'=>1,'frozen'=>false]]];
    (new ReflectionMethod(BattleService::class,'deployChampionInto'))->invokeArgs(null,[&$s,&$owned,1.0,1.0]);
    $stats=Catalog::championStats($type,6,3);
    cbCheck(cbNear($s['units'][0]['hp'],$snapshot['hp'])&&cbNear($s['units'][0]['_damage'],$stats['damage']*1.3)&&$s['units'][0]['bonus_feeds']===3,"$type delayed deployment uses its saved tier and applies Stronghold damage once");
    $damage=$s['units'][0]['_damage'];BattleEngine::advance($s,2);BattleEngine::advance($s,3);
    cbCheck($s['units'][0]['_damage']===$damage,"$type bonus damage never compounds across polls");
}

// Earned Fomor powers affect actual allies, not only a comparison panel.
$make=new ReflectionMethod(BattleService::class,'makeUnit');$ally=$make->invoke(null,'ichi',1,'u1','attacker',8.0,10.0,null,0);$ally['_damage']=0;$ally['_speed']=0;
$tower=cbBuilding(1,'sniper');$tower['_damage']=100;$tower['_range']=3;
foreach([0=>40,1=>37,2=>34,3=>25] as $tier=>$expectedHit){
    $fomor=cbUnit(cbSnapshot('fomor',$tier),'ca','attacker',5,10);$fomor['_damage']=0;$fomor['_speed']=0;
    $s=cbArena([$tower,cbBuilding(2,'townhall',24,24)],[$ally,$fomor]);BattleEngine::advance($s,.25);
    cbCheck(cbNear($ally['hp']-$s['units'][0]['hp'],$expectedHit),"Fomor food tier $tier changes actual allied damage resistance");
}
$runner=$make->invoke(null,'teratorn',1,'u1','attacker',4.0,4.0,null,0);$runner['_range']=.5;
$plain=cbUnit(cbSnapshot('fomor',0),'ca','attacker',5,4);$plain['_damage']=0;$plain['_speed']=0;
$fed=cbUnit(cbSnapshot('fomor',3),'ca','attacker',5,4);$fed['_damage']=0;$fed['_speed']=0;
$a=cbArena([cbBuilding(1,'townhall',24,4)],[$runner,$plain]);$b=cbArena($a['buildings'],[$runner,$fed]);BattleEngine::advance($a,1);BattleEngine::advance($b,1);
cbCheck($b['units'][0]['x']>$a['units'][0]['x'],'fed Fomor increases actual allied travel speed beyond its baseline evolution-six aura');

$normal=cbUnit(cbSnapshot('fomor',0),'ca','attacker',4.5,10);$normal['_speed']=0;
$fed=cbUnit(cbSnapshot('fomor',3),'ca','attacker',4.5,10);$fed['_speed']=0;
$building=cbBuilding(1,'sniper',10,10);$a=cbArena([$building],[$normal]);$b=cbArena([$building],[$fed]);BattleEngine::advance($a,.25);BattleEngine::advance($b,.25);
cbCheck($a['buildings'][0]['hp']===100000.0&&$b['buildings'][0]['hp']<100000,'Fomor bonus attack range reaches a target outside baseline range');

$gorgo=cbUnit(cbSnapshot('gorgo',3));$drull=cbUnit(cbSnapshot('drull',3),'cd','defender',20,20);$fomor=cbUnit(cbSnapshot('fomor',3),'c2','attacker',7,10);
$s=cbArena([cbBuilding(1,'townhall',24,24),cbBuilding(2,'sniper',16,16)],[$gorgo,$drull,$fomor]);$s['buildings'][0]['_fortificationReduction']=.5;
$a=$s;$b=$s;BattleEngine::advance($a,20);for($step=1;$step<=80;$step++){BattleEngine::advance($b,$step*.25);}
cbCheck($a===$b,'bonus champions and fortified targets remain deterministic across polling cadence');
echo "Champion bonus combat: $checks checks passed.\n";
