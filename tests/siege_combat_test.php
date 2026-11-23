<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$checks=0;
function siegeCheck(bool $ok,string $reason):void { global $checks; if (!$ok) { throw new RuntimeException($reason); } ++$checks; }
function siegeArena(string $type,array $rows):array{
    $s=['id'=>1,'formatVersion'=>7,'status'=>'active','elapsed'=>0.0,'duration'=>300,'attacker'=>['id'=>1],'defender'=>['id'=>2],
        'units'=>[],'events'=>[],'eventSeq'=>0,'remaining'=>['pokey'=>3],'obstacleVersion'=>0,'_garrisonTemplates'=>[],
        'siege'=>SiegeCombat::load(['type'=>$type,'level'=>1],'home'),'buildings'=>[],'result'=>null];
    foreach($rows as $i=>[$kind,$x,$y]){
        $hp=Game::buildingStats($kind,1)['max_hp'];
        $b=['type'=>$kind,'x'=>$x,'y'=>$y,'id'=>$i+1,'hp'=>$hp,'max_hp'=>$hp,'level'=>1];
        $s['buildings'][]=BattleService::freezeBuilding($b,Catalog::buildings()[$kind],0,0,1);
    }
    return $s;
}
function siegeFixtureFire(array &$s,float $x,float $y):void{
    $type=$s['siege']['type'];$base=['siege'=>['inventory'=>[$type=>1]]];$key=str_repeat('k',20);
    SiegeCombat::fire($s,$base,$type,$x,$y,$key);
    SiegeCombat::fire($s,$base,$type,$x,$y,$key);
    siegeCheck($base['siege']['inventory'][$type]===0,'Duplicate never debits again');
}
foreach(['decoy','vacuum','jars'] as $type){
    $previous=0;
    for($level=1;$level<=10;++$level){
        $s=SiegeCombat::load(compact('type','level'),'home');$stats=SiegeCombat::view($s);
        siegeCheck($stats['level']===$level&&$stats['type']===$type&&!$stats['used']&&!isset($stats['_damage'],$stats['_request']),'Frozen public tier');
        $value=$type==='decoy'?$s['_damage']:$s['_durability'];siegeCheck($value>$previous,'Device scales across all tiers');$previous=$value;
    }
}
$s=siegeArena('decoy',[['townhall',24,24],['bunker',12,10]]);
$s['_garrisonTemplates'][2]=[BattleService::makeUnit('pokey',1,'d1','defender',12,10,2)];
siegeFixtureFire($s,14,10); BattleEngine::advance($s,1.0);
siegeCheck(count($s['units'])===1&&$s['units'][0]['team']==='defender'&&$s['units'][0]['targetId']==='siege-decoy','Decoy opens the bunker and attracts its defender without an attacking unit nearby');
$save=json_decode(json_encode($s,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
BattleEngine::advance($s,10.0); BattleEngine::advance($save,10.0);
siegeCheck($s['buildings'][1]['hp']<$s['buildings'][1]['max_hp']&&$s['units'][0]['hp']<$s['units'][0]['max_hp'],'Decoy applies distance-based damage to structures and defenders');
siegeCheck($s['buildings'][0]['hp']===$s['buildings'][0]['max_hp']&&$s['siege']['active']===null,'Out-of-range building remains whole and fuse resolves');
siegeCheck($s==$save,'Reloading mid-fuse reproduces outcome');
$j=siegeArena('jars',[['townhall',25,25],['sniper',10,10],['cannon',13,10],['tesla',21,10]]);
$soldier=BattleService::makeUnit('ichi',1,'a1','attacker',10,13);$soldier['_speed']=0;$soldier['_damage']=0;$j['units'][]=$soldier;
siegeFixtureFire($j,11,10);
siegeCheck($j['siege']['active']['targets']===[2,3]&&!isset($j['buildings'][3]['jarHp']),'Jars encase only towers touched by the area');
$hp=$soldier['hp'];BattleEngine::advance($j,.25);
siegeCheck($j['units'][0]['hp']===$hp&&$j['buildings'][1]['jarHp']<$j['buildings'][1]['jarMaxHp'],'Tower shot hits its own glass');
BattleEngine::advance($j,9);
siegeCheck($j['units'][0]['hp']<$hp&&$j['siege']['active']===null,'Towers shatter jars and resume attacking');
$v=siegeArena('vacuum',[['townhall',16,16],['sniper',18,16],['laser',12,16]]);
siegeFixtureFire($v,1,1);
siegeCheck($v['siege']['active']['targetId']===1&&$v['siege']['active']['x']===16,'Vacuum auto-anchors to living Hall');
BattleEngine::advance($v,20);$shots=array_filter($v['events'],fn($e)=>($e['targetId']??'')==='siege-vacuum');
siegeCheck(count($shots)>0 && array_unique(array_column($shots,'kind'))===['sniper'],'Only anti-air-capable towers fire on hose');
siegeCheck($v['siege']['active']===null&&$v['siege']['_siphon']>0&&$v['siege']['_siphon']<1000,'Hose stops pumping when destroyed');
$v=siegeArena('vacuum',[['townhall',16,16]]);siegeFixtureFire($v,1,1);BattleEngine::advance($v,32);
siegeCheck(abs($v['siege']['_siphon']-1500)<.01&&$v['siege']['active']===null,'Undefended hose reaches duration cap');
foreach(['resource','fortification','stronghold','tribe'] as $kind){
    $v['siege']=SiegeCombat::load(['type'=>'vacuum','level'=>1],$kind);$base=['siege'=>['inventory'=>['vacuum'=>1]]];
    try{SiegeCombat::fire($v,$base,'vacuum',16,16,str_repeat('u',22));throw new LogicException('Virtual resource target accepted');}
    catch(RuntimeException $e){siegeCheck($base['siege']['inventory']['vacuum']===1,'Stockless territory retains its device');}
}
foreach (['decoy','vacuum','jars'] as $type) {
    $s=siegeArena($type,[['townhall',24,24],['bunker',9,10],['sniper',15,16],['cannon',17,16],['laser',4,5]]);
    $s['attacker']['is_bot']=true;
    $base=['siege'=>['inventory'=>[$type=>1]]];
    SiegeCombat::botDeploy($s,$base);
    $active=$s['siege']['active'];
    siegeCheck($base['siege']['inventory'][$type]===0 && $s['siege']['used'],'Bot commits an actual device');
    siegeCheck(match($type){'jars'=>count($active['targets'])===2,'decoy'=>hypot($active['x']-9,$active['y']-10)<3,'vacuum'=>$active['targetId']===1},'Bot chooses a useful legal target');
    SiegeCombat::botDeploy($s,$base);
    siegeCheck(count(array_filter($s['events'],fn($e)=>$e['type']==='siege_launch'))===1,'Bot deployment cannot duplicate a charge across waves');
}
echo "Siege combat: $checks checks passed.\n";
