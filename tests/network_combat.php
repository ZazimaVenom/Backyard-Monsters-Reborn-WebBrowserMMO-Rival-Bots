<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Catalog.php';
require_once __DIR__.'/../app/Game.php';
require_once __DIR__.'/../app/BattleService.php';

$checks=0;
function ncCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function ncNear(float $a,float $b):bool{return abs($a-$b)<0.00001;}
function ncBuilding(int $id,string $type,float $x,float $y,float $network=0,float $fortify=0):array{
    $spec=Catalog::buildings()[$type];
    return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>1,'hp'=>100000.0,'max_hp'=>100000.0,
        '_fortificationReduction'=>$fortify,'_networkReduction'=>$network,'networkReduction'=>$network,'damageReduction'=>1-(1-$network)*(1-$fortify),
        '_initial_hp'=>100000.0,'_size'=>$spec['size'],'_damage'=>0.0,'_range'=>$spec['range']??0,'_interval'=>$spec['attackInterval']??1,
        '_cooldown'=>0.0,'_ready'=>true,'_untargetable'=>$spec['untargetable']??false,'_targetMode'=>$spec['targetMode']??'both'];
}
function ncUnit(string $type,float $x=8.5,float $y=10,int $lab=0,string $team='attacker',string $id='u1'):array{
    $u=(new ReflectionMethod(BattleService::class,'makeUnit'))->invoke(null,$type,1,$id,$team,$x,$y,null,$lab);
    $u['_damage']=100.0;$u['_speed']=0.0;return $u;
}
function ncArena(array $buildings,array $units=[],int $format=7):array{
    return ['id'=>1,'formatVersion'=>$format,'status'=>'active','elapsed'=>0.0,'duration'=>300,'buildings'=>$buildings,'units'=>$units,
        'events'=>[],'eventSeq'=>0,'obstacleVersion'=>0,'remaining'=>['pokey'=>1],'attacker'=>['id'=>1],'defender'=>['id'=>2],'result'=>null,
        'networkDefense'=>['count'=>6,'reduction'=>.75,'siteIds'=>[11,12,13,14,15,16]]];
}
function ncDamage(array $s,int $index):float{return 100000.0-(float)$s['buildings'][$index]['hp'];}
function ncEvents(array $s,string $type):array{return array_values(array_filter($s['events'],static fn($e)=>$e['type']===$type));}

foreach([0,.07,.13,.23,.35,.5,.75] as $count=>$network){foreach([0,.2,.5] as $fortify){
    $s=ncArena([ncBuilding(1,'sniper',10,10,$network,$fortify)],[ncUnit('pokey')]);BattleEngine::advance($s,.25);$expected=100*(1-$network)*(1-$fortify);
    ncCheck(ncNear(ncDamage($s,0),$expected),"$count neighboring cells and $fortify Fortify reduce the actual strike multiplicatively");
    $hit=ncEvents($s,'hit');ncCheck(count($hit)===1&&ncNear((float)$hit[0]['amount'],$expected),"$count cells and $fortify Fortify report the once-mitigated damage");
    ncCheck($s['buildings'][0]['max_hp']===100000.0&&$s['buildings'][0]['_initial_hp']===100000.0,"$count cells and $fortify Fortify never inflate health or the capture denominator");
}}

// Every real damage route converges on the same per-building calculation.
foreach(['pokey'=>0,'fink'=>3,'dave'=>3,'wormzer'=>3,'eyera'=>3,'teratorn'=>3] as $type=>$lab){
    $x=$type==='teratorn'?7:8.5;$plain=ncArena([ncBuilding(1,'sniper',10,10),ncBuilding(2,'storage',10,11.4)],[ncUnit($type,$x,10,$lab)]);$plain['units'][0]['targetId']=1;
    $network=$plain;foreach($network['buildings'] as &$b){$b['_networkReduction']=.75;$b['_fortificationReduction']=.5;}unset($b);
    BattleEngine::advance($plain,.25);BattleEngine::advance($network,.25);
    ncCheck(ncDamage($plain,0)>0&&ncNear(ncDamage($network,0),ncDamage($plain,0)*.125),"$type primary damage applies network and Fortify exactly once");
    if(in_array($type,['fink','wormzer','eyera','teratorn'],true)){ncCheck(ncDamage($plain,1)>0&&ncNear(ncDamage($network,1),ncDamage($plain,1)*.125),"$type secondary damage uses the victim building protection exactly once");}
}
$s=ncArena([ncBuilding(1,'sniper',10,10,.75,.5),ncBuilding(2,'storage',12,10,.75,.2),ncBuilding(3,'townhall',14,10,.75,.3)],[ncUnit('teratorn',7,10,3)]);BattleEngine::advance($s,.25);
ncCheck(ncNear(ncDamage($s,0),12.5)&&ncNear(ncDamage($s,1),10)&&ncNear(ncDamage($s,2),4.375),'ricochet halves raw damage before each victim independently applies both protections');
$u=ncUnit('projectx',8.5,10,3);$u['hp']=1;$plain=ncArena([ncBuilding(1,'sniper',10,10)],[$u]);$network=$plain;$network['buildings'][0]['_networkReduction']=.75;$network['buildings'][0]['_fortificationReduction']=.5;
$hurtUnit=new ReflectionMethod(BattleEngine::class,'hurtUnit');$hurtUnit->invokeArgs(null,[&$plain,0,100.0,'test']);$hurtUnit->invokeArgs(null,[&$network,0,100.0,'test']);
ncCheck(ncDamage($plain,0)>0&&ncNear(ncDamage($network,0),ncDamage($plain,0)*.125),'Project X death burst applies both protections exactly once');
ncCheck(count(ncEvents($network,'acid'))===1&&count(ncEvents($network,'death'))===1,'network protection cannot duplicate a death burst or source death');
foreach(['twig','pebble'] as $resource){
    $buildings=[ncBuilding(1,'townhall',10,10,.75,.5),ncBuilding(2,'storage',10,10,.75,.5),ncBuilding(3,'sniper',10,10,.75,.5),ncBuilding(4,'wall',10,10,.75),ncBuilding(5,'championcage',10,10,.75),ncBuilding(6,'outpost',10,10,.75)];
    $buildings[1]['level']=2;$buildings[2]['_damage']=100;$s=ncArena($buildings);$s['catapult']=['pending'=>[['type'=>$resource,'level'=>1,'x'=>10,'y'=>10,'impactAt'=>1,'radius'=>5,'_bomb'=>['damage'=>1000]]]];
    BattleEngine::advance($s,.75);ncCheck(ncDamage($s,0)===0.0,"$resource network hit waits for its server deadline");BattleEngine::advance($s,1);
    ncCheck(ncNear(ncDamage($s,0),125)&&ncNear(ncDamage($s,1),250)&&ncNear(ncDamage($s,2),112.5),"$resource retains storage and tower multipliers before network and Fortify");
    ncCheck(ncNear(ncDamage($s,3),15)&&ncDamage($s,4)===0.0&&ncDamage($s,5)===0.0,"$resource protects walls while retaining Cage and untargetable core exclusions");
    $before=array_column($s['buildings'],'hp');BattleEngine::advance($s,3);ncCheck(array_column($s['buildings'],'hp')===$before,"$resource cannot repeat a resolved network impact");
}

// Building protection never leaks into monsters, or creates duplicate presentation cues.
$a=ncUnit('pokey');$d=ncUnit('pokey',9,10,0,'defender','d1');$d['_damage']=0;
$s=ncArena([ncBuilding(1,'townhall',25,25,.75,.5)],[$a,$d]);BattleEngine::advance($s,.25);
ncCheck(ncNear($d['hp']-$s['units'][1]['hp'],100),'a defending monster receives full damage regardless of its yard network');
$strikes=ncEvents($s,'strike');$strike=array_values(array_filter($strikes,fn($e)=>$e['sourceId']==='u1'));
ncCheck(count($strike)===1&&$strike[0]['targetId']==='d1'&&$strike[0]['kind']==='pokey'&&$strike[0]['team']==='attacker'&&$strike[0]['t']===.25&&$strike[0]['id']>0&&!array_key_exists('amount',$strike[0]),'confirmed melee unit attacks emit one timestamped actor cue without a second damage amount');
ncCheck(isset($strike[0]['x'],$strike[0]['y'],$strike[0]['tx'],$strike[0]['ty'])&&count(array_filter(ncEvents($s,'hit'),fn($e)=>($e['targetId']??null)==='d1'))===1,'unit melee keeps one damage event alongside its coordinate-complete actor cue');
BattleEngine::advance($s,.5);ncCheck(count(ncEvents($s,'strike'))===count($strikes),'cooldown ticks do not invent extra melee attack cues');
$s=ncArena([ncBuilding(1,'townhall',25,25,.75)],[ncUnit('dave',8.5,10,3),$d]);BattleEngine::advance($s,.25);ncCheck(count(ncEvents($s,'shot'))>0&&count(array_filter(ncEvents($s,'strike'),fn($e)=>$e['sourceId']==='u1'))===0,'ranged unit attacks retain their shot cue without a duplicate strike');
$s=ncArena([ncBuilding(1,'sniper',10,10,.75)],[ncUnit('pokey')]);BattleEngine::advance($s,.25);ncCheck(count(ncEvents($s,'hit'))===1&&ncEvents($s,'strike')===[],'melee building attacks retain their existing hit cue without duplicate strike');
$plain=ncArena([ncBuilding(1,'sniper',10,10)],[ncUnit('pokey')]);$plain['buildings'][0]['_damage']=100;$armored=$plain;$armored['buildings'][0]['_networkReduction']=.75;BattleEngine::advance($plain,.25);BattleEngine::advance($armored,.25);
ncCheck($plain['units'][0]['hp']<$a['hp']&&$armored['units'][0]['hp']===$plain['units'][0]['hp'],'network support changes no outgoing tower damage against invading monsters');

foreach(range(0,6) as $format){
    $s=ncArena([ncBuilding(1,'sniper',10,10,.75,.5)],[ncUnit('pokey')],$format);BattleEngine::advance($s,.25);
    ncCheck(ncNear(ncDamage($s,0),$format>=6?50:100),"legacy format $format ignores network fields and preserves its previous Fortify behavior");
    $s=ncArena([ncBuilding(1,'townhall',25,25,.75)],[$a,$d],$format);BattleEngine::advance($s,.25);ncCheck(ncEvents($s,'strike')===[],"legacy format $format preserves the existing melee event stream");
    $public=(new ReflectionMethod(BattleService::class,'snapshot'))->invoke(null,$s,1);ncCheck($public['networkDefense']===null&&!isset($public['buildings'][0]['networkReduction'],$public['buildings'][0]['damageReduction']),"legacy format $format does not advertise unapplied network protection");
}
$s=ncArena([ncBuilding(1,'sniper',10,10,.75)],[ncUnit('pokey')]);unset($s['buildings'][0]['_networkReduction']);BattleEngine::advance($s,.25);ncCheck(ncNear(ncDamage($s,0),100),'public network labels cannot invent combat protection without its frozen private value');
$s=ncArena([ncBuilding(1,'sniper',10,10,.75,.5)],[ncUnit('pokey')]);$s['buildings'][0]['fortify_paid']=['twig'=>12345];$public=(new ReflectionMethod(BattleService::class,'snapshot'))->invoke(null,$s,1);
ncCheck($public['networkDefense']===$s['networkDefense']&&$public['buildings'][0]['networkReduction']===.75&&$public['buildings'][0]['damageReduction']===.875,'public format seven exposes frozen contributing cells and total building protection');
ncCheck(!isset($public['buildings'][0]['fortify_paid'])&&array_filter(array_keys($public['buildings'][0]),fn($k)=>str_starts_with($k,'_'))===[],'public snapshots expose no private armor values or paid resource history');

$core=ncBuilding(1,'outpostdefender',10,10);$attack=ncArena([$core],[ncUnit('pokey')]);BattleEngine::advance($attack,.25);
ncCheck(ncNear(ncDamage($attack,0),100)&&$attack['units'][0]['targetId']===1,'ordinary invading monsters acquire and damage the targetable Fortification core');
ncCheck(!BattleEngine::legalDeployment(ncArena([$core]),10,10),'Fortification core retains a real deployment exclusion footprint');
$core['hp']=10000;$s=ncArena([$core]);$s['defender']['id']=0;$s['target']=['kind'=>'fortification','id'=>123,'captureThreshold'=>90];$s['networkDefense']=['count'=>0,'reduction'=>0.0,'siteIds'=>[]];
ncCheck(!$core['_untargetable']&&ncNear(BattleEngine::captureProgress($s),90),'targetable Fortification core contributes its full health to the exact capture threshold');
BattleEngine::finish($s,'retreat');ncCheck($s['result']['captured']&&!$s['result']['attackerWon']&&$s['result']['captureProgress']===90.0&&$s['result']['rankDelta']['defender']===0,'ninety-percent Fortification capture remains independent of ordinary victory and neutral ranking');
ncCheck($s['result']['networkDefense']===$s['networkDefense'],'finished results retain the original frozen network defense');
$public=(new ReflectionMethod(BattleService::class,'snapshot'))->invoke(null,$s,1);ncCheck($public['captureProgress']===90.0,'public Fortification battle snapshot exposes capture progress');
$s['status']='active';$s['buildings'][0]['hp']=10001;BattleEngine::finish($s,'retreat');ncCheck(!$s['result']['captured'],'damage just below ninety percent does not capture a Fortification');
$current=$core;$current['hp']=99999;ncCheck(BattleEngine::settledHealth($core,$current)===10000.0,'network changes never heal settled invasion damage');
$s=ncArena([ncBuilding(1,'sniper',10,10,.75,.5),ncBuilding(2,'storage',12,10,.75,.2)],[ncUnit('teratorn',7,10,3)]);$a=$s;$b=$s;BattleEngine::advance($a,8);for($i=1;$i<=32;$i++){BattleEngine::advance($b,$i*.25);}ncCheck($a===$b,'network combat remains deterministic across polling cadence');
echo "Network combat: $checks checks passed.\n";
