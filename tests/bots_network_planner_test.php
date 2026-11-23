<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Catalog.php';
require_once dirname(__DIR__).'/app/Game.php';
require_once dirname(__DIR__).'/app/World.php';
require_once dirname(__DIR__).'/app/Bots.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never {throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function networkBotCheck(bool $condition,string $message):void {global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$message);}}
function networkBuilding(int $id,string $type,int $level=1):array {
    $hp=Game::buildingStats($type,$level)['max_hp'];
    return ['id'=>$id,'type'=>$type,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'fortification'=>4,'fortify_ready_at'=>0,'fortify_to'=>null];
}
$now=1700000000; // One fixed planning instant covers all readiness and shield checks.
$base=['buildings'=>[networkBuilding(1,'townhall',5),networkBuilding(2,'flinger',5),networkBuilding(3,'maproom')],
    'resources'=>array_fill_keys(['twig','pebble','putty','goo'],1000000),'army'=>['pokey'=>50,'dave'=>1],'queue'=>[],'champions'=>[],'activeChampion'=>null];
$state=World::initialState('fortification',1,$now);$state['garrison']=[];
$neutral=['id'=>10001,'kind'=>'fortification','name'=>'Network cell','q'=>10,'r'=>10,'level'=>1,'owner_id'=>null,'owner'=>null,'state'=>$state,'reachable'=>true,'busy'=>false,'adjacentSites'=>[]];
$enemy=$neutral;$enemy['id']=10002;$enemy['owner_id']=2001;$enemy['owner']=['id'=>2001,'is_bot'=>true];
$enemy['adjacentSites']=[['id'=>20002,'kind'=>'resource','q'=>11,'r'=>10,'owner_id'=>2001]];
$settings=['human_target_percent'=>0];$profiles=[];$preferred=[];
for($id=1;$id<=1000;$id++){
    $profile=Bots::profile($id);$profiles[$profile]=true;$seed=Bots::seed($id,54321);
    $own=$neutral;$own['adjacentSites']=[['id'=>20001,'kind'=>'home','q'=>9,'r'=>10,'owner_id'=>$id]];
    $choices=Bots::networkChoices($id,$base,[],[$own,$enemy,$neutral],$seed,$settings,[],$now);
    networkBotCheck(count($choices)===2,'Every identity recognizes own protection and enemy support, excluding useless land');
    networkBotCheck($choices===Bots::networkChoices($id,$base,[],[$enemy,$own,$neutral],$seed+1,$settings,[],$now),'Stable priorities and site tie-break survive shuffled candidates and a new turn');
    networkBotCheck(Bots::networkChoices($id,$base,array_fill(0,6,$own),[$enemy],$seed,$settings,[],$now)===[],'Every identity respects its separately earned six-Fortification capacity');
    $preferred[$choices[0]['id']]=true;
    $networkTurns=0;for($turn=1;$turn<=12;$turn++){$networkTurns+=(int)Bots::networkTurn($id,$turn);}
    networkBotCheck($networkTurns===($profile==='guardian'?4:3),'Network campaigns leave eight or nine of twelve turns for ordinary goals');
    networkBotCheck(Bots::networkTurn($id,7)===Bots::networkTurn($id,7),'Campaign cadence persists across restart');
    networkBotCheck(Bots::territoryChoices($id,$base,[],[$own],$seed,$settings,[],$now)===[],'Network density never enters ordinary conquest preferences');
}
networkBotCheck(count($profiles)===4 && count($preferred)===2,'All profiles participate with both defense and disruption priorities');
$own=$neutral;$own['adjacentSites']=[['id'=>20001,'kind'=>'home','q'=>9,'r'=>10,'owner_id'=>1]];
$expanded=$base;$expanded['buildings'][2]['level']=3;
networkBotCheck(count(Bots::networkChoices(1,$expanded,array_fill(0,17,$own),[$enemy],0,$settings,[],$now))===1,'Map Room three permits the eighteenth cell');
networkBotCheck(Bots::networkChoices(1,$expanded,array_fill(0,18,$own),[$enemy],0,$settings,[],$now)===[],'Fortification capacity caps at eighteen');
foreach(['hp'=>0,'ready_at'=>$now+1,'level'=>0] as $field=>$value){$blocked=$base;$blocked['buildings'][2][$field]=$value;networkBotCheck(Bots::networkChoices(1,$blocked,[],[$own],0,$settings,[],$now)===[],'Map Room requirement enforces '.$field);}
$blocked=$base;$blocked['army']=[];networkBotCheck(Bots::networkChoices(1,$blocked,[],[$own],0,$settings,[],$now)===[],'No invented army opens a network campaign');
$blocked=$base;$blocked['buildings'][1]['hp']=0;networkBotCheck(Bots::networkChoices(1,$blocked,[],[$own],0,$settings,[],$now)===[],'Network campaigns require a living Flinger');
foreach(['shield_until','ready_at','repair_at'] as $timer){$blocked=$own;$blocked['state'][$timer]=$now+1;networkBotCheck(Bots::networkChoices(1,$base,[],[$blocked],0,$settings,[],$now)===[],'Network invasion respects '.$timer);}
foreach(['busy'=>true,'reachable'=>false,'kind'=>'resource','owner_id'=>1] as $field=>$value){$blocked=$own;$blocked[$field]=$value;networkBotCheck(Bots::networkChoices(1,$base,[],[$blocked],0,$settings,[],$now)===[],'Network invasion rejects '.$field);}
networkBotCheck(Bots::networkChoices(1,$base,[],[$own],0,$settings,[1=>true],$now)===[],'An occupied attacker cannot launch a campaign');
networkBotCheck(Bots::networkChoices(1,$base,[],[$enemy],0,$settings,[2001=>true],$now)===[],'A newly occupied defender is excluded');
$human=$enemy;$human['owner']['is_bot']=false;
networkBotCheck(Bots::networkChoices(1,$base,[],[$human],0,$settings,[],$now)===[],'Human-targeting configuration applies to enemy networks');
networkBotCheck(count(Bots::networkChoices(1,$base,[],[$human],0,['human_target_percent'=>50],[],$now))===1,'Configured human targeting includes real human-owned support');
foreach(['kind'=>'fortification','owner_id'=>2222,'q'=>40] as $field=>$value){$blocked=$enemy;$blocked['adjacentSites'][0][$field]=$value;networkBotCheck(Bots::networkChoices(1,$base,[],[$blocked],0,$settings,[],$now)===[],'Only adjacent same-owner eligible parents confer disruption value: '.$field);}
$many=[];for($i=0;$i<70;$i++){$candidate=$own;$candidate['id']+=100+$i;$many[]=$candidate;}
$choices=Bots::networkChoices(1,$base,[],$many,0,$settings,[],$now);
networkBotCheck(count($choices)===4 && max(array_column($choices,'id'))<10149,'At most forty-eight candidate cells and four retries enter one plan');
$before=$base;$siteBefore=$own;Bots::networkChoices(1,$base,[],[$own],0,$settings,[],$now);
networkBotCheck($base===$before && $own===$siteBefore,'Planning never changes an owner, troop, resource or rank');

// Use normal World prices, layouts and mutation intents, including reserves.
$owned=$own;$owned['owner_id']=1;$stats=World::siteStats('fortification',1);$stats['repairCost']=World::repairCost($owned);
$plan=Bots::siteManagementPlan($base,$owned,$stats,'guardian',$now);
networkBotCheck($plan===['action'=>'worldGarrison','input'=>['siteId'=>$owned['id'],'type'=>'pokey','quantity'=>3]],'Network garrisons plan bounded transfers from real stock');
$food=$base;$food['army']=['fang'=>30,'pokey'=>8];$food['activeChampion']='drull';$food['champions']=['drull'=>['level'=>6,'frozen'=>false]];$noUpgrade=$stats;$noUpgrade['maxLevel']=1;
networkBotCheck(Bots::siteManagementPlan($food,$owned,$noUpgrade,'guardian',$now)===null,'Meal ingredients cannot count toward the separate eighty-space raid reserve');
$food['army']['pokey']=11;
networkBotCheck(Bots::siteManagementPlan($food,$owned,$noUpgrade,'guardian',$now)['input']===['siteId'=>$owned['id'],'type'=>'pokey','quantity'=>3],'Only surplus beyond a full level-six meal and raid reserve enters the network');
$funds=$base;$funds['army']=[];$reserve=Bots::networkHomeReserve($funds,'guardian',$now);
foreach($stats['upgradeCost'] as $resource=>$cost){$funds['resources'][$resource]=$cost+$reserve[$resource];}
networkBotCheck(Bots::siteManagementPlan($funds,$owned,$stats,'guardian',$now)['action']==='worldUpgrade','Paid network upgrade can coexist with the next home construction budget');
$funds['resources']['twig']--;
networkBotCheck(Bots::siteManagementPlan($funds,$owned,$stats,'guardian',$now)===null,'One twig short of home plus network costs delays optional network work');
$funds['resources']['twig']++;
$funds['buildings'][0]['fortification']=0;$armor=Catalog::fortificationStats('townhall',1)['cost'];$reserve=Bots::networkHomeReserve($funds,'guardian',$now);
foreach($armor as $resource=>$cost){networkBotCheck($reserve[$resource]>=$cost,'Home armor preserves '.$resource.' funds before optional land upgrades');}
$poor=$funds;$poor['resources']=array_fill_keys(['twig','pebble','putty','goo'],0);
networkBotCheck(Bots::networkHomeReserve($poor,'guardian',$now)===$reserve,'Home armor saving goal survives an insufficient current balance');
foreach($stats['upgradeCost'] as $resource=>$cost){$funds['resources'][$resource]=$cost+$reserve[$resource];}
networkBotCheck(Bots::siteManagementPlan($funds,$owned,$stats,'guardian',$now)['action']==='worldUpgrade','Network investment resumes after construction and armor reserves are funded');
$funds['army']=['fang'=>29];$funds['activeChampion']='drull';$funds['champions']=['drull'=>['level'=>6,'frozen'=>false]];
networkBotCheck(Bots::siteManagementPlan($funds,$owned,$stats,'guardian',$now)===null,'Optional network spending also preserves missing champion food goo');
$funds['resources']['goo']+=Catalog::monsters()['fang']['cost'];
networkBotCheck(Bots::siteManagementPlan($funds,$owned,$stats,'guardian',$now)['action']==='worldUpgrade','Network upgrade can use the surplus beyond the paid next meal');
$damaged=$owned;$damaged['state']['buildings'][0]['hp']=1;$repair=World::repairCost($damaged);$repairStats=$stats;$repairStats['repairCost']=$repair;
$rich=$base;$rich['army']=[];
networkBotCheck(Bots::siteManagementPlan($rich,$damaged,$repairStats,'guardian',$now)['action']==='worldRepair','Damaged Fortification core is repaired before garrison or upgrades');
foreach(['ready_at','repair_at'] as $timer){$waiting=$owned;$waiting['state'][$timer]=$now+1;networkBotCheck(Bots::siteManagementPlan($rich,$waiting,$stats,'guardian',$now)===null,'Network management respects '.$timer);}
$maxed=$owned;$maxed['level']=3;
networkBotCheck(Bots::siteManagementPlan($rich,$maxed,World::siteStats('fortification',3),'guardian',$now)===null,'Local Fortification defenses have exactly three upgrade levels');
echo "PASS {$checks} strategic-network bot planner checks across 1000 persistent IDs.\n";
