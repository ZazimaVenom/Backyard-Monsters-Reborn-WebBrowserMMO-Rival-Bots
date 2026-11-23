<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Catalog.php';
require_once dirname(__DIR__).'/app/Game.php';
require_once dirname(__DIR__).'/app/Bots.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never { throw new ErrorException($message,0,$severity,$file,$line); });
$checks=0;
function checkWorldBot(bool $truth,string $message):void { global $checks;++$checks;if(!$truth){throw new RuntimeException($message);} }
$now=time();
$base=['buildings'=>[
    ['id'=>1,'type'=>'townhall','x'=>16,'y'=>16,'level'=>3,'hp'=>100,'max_hp'=>100,'ready_at'=>0],
    ['id'=>2,'type'=>'flinger','x'=>8,'y'=>8,'level'=>2,'hp'=>100,'max_hp'=>100,'ready_at'=>0],
    ['id'=>3,'type'=>'maproom','x'=>24,'y'=>24,'level'=>1,'hp'=>100,'max_hp'=>100,'ready_at'=>0],
], 'army'=>['pokey'=>50,'dave'=>1],'resources'=>['twig'=>10000,'pebble'=>10000,'putty'=>10000,'goo'=>10000],'queue'=>[]];
$state=['ready_at'=>0,'repair_at'=>0,'upgrade_to'=>null,'shield_until'=>0,'buildings'=>[['id'=>10,'type'=>'bunker','hp'=>100,'max_hp'=>100]],'garrison'=>['10'=>[]]];
$neutral=['id'=>10001,'kind'=>'resource','name'=>'Neutral territory','level'=>1,'owner_id'=>null,'owner'=>null,'state'=>$state,'reachable'=>true,'busy'=>false];
$enemy=$neutral; $enemy['id']=10002;$enemy['owner_id']=2000;$enemy['owner']=['id'=>2000,'is_bot'=>true];
$human=$enemy;$human['id']=10003;$human['owner_id']=3000;$human['owner']=['id'=>3000,'is_bot'=>false];
$fort=$neutral;$fort['id']=10004;$fort['kind']='stronghold';
$settings=['human_target_percent'=>0];
$choicesSeen=[];
for($id=1;$id<=1000;$id++){
    $seed=Bots::seed($id,54321);
    $candidates=[$neutral,$enemy,$human,$fort];
    $choices=Bots::territoryChoices($id,$base,[],$candidates,$seed,$settings,[],$now);
    checkWorldBot(count($choices)===3,'Human exclusion preserves neutral and bot territory candidates');
    checkWorldBot($choices===Bots::territoryChoices($id,$base,[],$candidates,$seed,$settings,[],$now),'Territory preference persists for identical ID and turn seed');
    checkWorldBot(($choices[0]['owner_id'] ?? null)!==$id,'A bot never chooses itself');
    $choicesSeen[empty($choices[0]['owner_id'])?'neutral':'enemy']=true;
    checkWorldBot((Bots::buildPlan(Bots::profile($id))['maproom'] ?? 0)===1,'Each of1000 identities has exactly one paid Map Room goal');
}
checkWorldBot(count($choicesSeen)===2,'Deterministic preferences select both neutral and enemy bot sites');
$blocked=$base;$blocked['buildings'][2]['hp']=0;
checkWorldBot(Bots::territoryChoices(1,$blocked,[],[$neutral],0,$settings,[],$now)===[],'Destroyed Map Room cannot raid territories');
$blocked['buildings'][2]['hp']=100;$blocked['buildings'][2]['ready_at']=$now+60;
checkWorldBot(Bots::territoryChoices(1,$blocked,[],[$neutral],0,$settings,[],$now)===[],'Unfinished Map Room cannot raid territories');
checkWorldBot(Bots::territoryChoices(1,$base,[$neutral,$enemy],[$fort],0,$settings,[],$now)===[],'Map Room1 holds at most two sites');
$expanded=$base;$expanded['buildings'][2]['level']=3;
checkWorldBot(count(Bots::territoryChoices(1,$expanded,array_fill(0,5,$neutral),[$fort],0,$settings,[],$now))===1,'Earned Map Room3 has six holdings');
checkWorldBot(Bots::territoryChoices(1,$expanded,array_fill(0,6,$neutral),[$fort],0,$settings,[],$now)===[],'Map Room3 still has a finite holding limit');
$own=$enemy;$own['owner_id']=1;$own['owner']['id']=1;
$home=$neutral;$home['kind']='home';
$far=$neutral;$far['reachable']=false;
$shielded=$neutral;$shielded['state']['shield_until']=$now+1;
$busy=$neutral;$busy['busy']=true;
checkWorldBot(Bots::territoryChoices(1,$base,[],[$own,$home,$far,$shielded,$busy],0,$settings,[],$now)===[],'Own, home, distant, shielded and occupied sites are excluded');
checkWorldBot(Bots::territoryChoices(1,$base,[],[$enemy],0,$settings,[2000=>true],$now)===[],'Newly busy owners in the scheduler cache cannot be targeted');
checkWorldBot(Bots::territoryChoices(1,$base,[],[$neutral],0,$settings,[1=>true],$now)===[],'A busy attacker cannot start another territory invasion');
checkWorldBot(count(Bots::territoryChoices(1,$base,[],[$human],0,['human_target_percent'=>50],[],$now))===1,'Configured human targeting can include human territory');
$many=[];for($i=0;$i<70;$i++){$site=$neutral;$site['id']+=100+$i;$many[]=$site;}
$bounded=Bots::territoryChoices(1,$base,[],$many,18,$settings,[],$now);
checkWorldBot(count($bounded)===4,'A turn retries at most four territory targets');
checkWorldBot(max(array_column($bounded,'id'))<10149,'Planner never examines more than48 supplied candidates');
$empty=$base;$empty['army']=[];
checkWorldBot(Bots::territoryChoices(1,$empty,[],[$neutral],0,$settings,[],$now)===[],'No invented army opens a territory attack');

$owned=$enemy;$owned['owner_id']=1;
$neutralStats=['garrisonCapacity'=>100,'maxLevel'=>5,'repairCost'=>['twig'=>50],'upgradeCost'=>['twig'=>500]];
checkWorldBot(Bots::siteManagementPlan($base,$neutral,$neutralStats,'guardian',$now)===null,'Bots never manage unowned neutral territories');
$busyOwned=$owned;$busyOwned['busy']=true;
checkWorldBot(Bots::siteManagementPlan($base,$busyOwned,$neutralStats,'guardian',$now)===null,'Busy owned territories cannot be managed by the planner');
$stats=['garrisonCapacity'=>100,'maxLevel'=>5,'repairCost'=>['twig'=>50,'pebble'=>50],'upgradeCost'=>['twig'=>500,'pebble'=>500,'putty'=>100,'goo'=>200]];
$plan=Bots::siteManagementPlan($base,$owned,$stats,'guardian',$now);
checkWorldBot($plan===['action'=>'worldGarrison','input'=>['siteId'=>10002,'type'=>'pokey','quantity'=>3]],'An owned empty outpost receives actual bounded troops');
$baseSnapshot=$base;$siteSnapshot=$owned;
Bots::siteManagementPlan($base,$owned,$stats,'guardian',$now);
checkWorldBot($base===$baseSnapshot && $owned===$siteSnapshot,'Pure planning cannot grant, spend, heal or transfer anything');
$owned['state']['garrison']['10']=['pokey'=>10];
$plan=Bots::siteManagementPlan($base,$owned,$stats,'guardian',$now);
checkWorldBot($plan['action']==='worldUpgrade','Full garrisons permit ordinary paid site upgrades');
$poor=$base;$poor['resources']['twig']=499;
checkWorldBot(Bots::siteManagementPlan($poor,$owned,$stats,'guardian',$now)===null,'Site upgrade requires the full saved price');
$maxed=$owned;$maxed['level']=5;
checkWorldBot(Bots::siteManagementPlan($base,$maxed,$stats,'guardian',$now)===null,'Maximum-level sites cannot upgrade again');
foreach(['ready_at','repair_at'] as $timer){
    $waiting=$owned;$waiting['state'][$timer]=$now+1;
    checkWorldBot(Bots::siteManagementPlan($base,$waiting,$stats,'guardian',$now)===null,'Site management respects '.$timer);
}
$waiting=$owned;$waiting['state']['upgrade_to']=2;
checkWorldBot(Bots::siteManagementPlan($base,$waiting,$stats,'guardian',$now)===null,'A pending upgrade is never overwritten');
$owned['state']['buildings'][0]['hp']=90;
checkWorldBot(Bots::siteManagementPlan($base,$owned,$stats,'guardian',$now)['action']==='worldRepair','Defensive repair takes priority over stocking and upgrades');
$poor['resources']['twig']=49;
checkWorldBot(Bots::siteManagementPlan($poor,$owned,$stats,'guardian',$now)===null,'Damaged sites receive no free repair or distracting upgrade');
$owned['state']['buildings'][0]['hp']=100;$owned['state']['garrison']['10']=[];
$food=$base;$food['activeChampion']='drull';$food['champions']=['drull'=>['level'=>1,'frozen'=>false]];$food['army']=['pokey'=>30];
$noUpgrade=$stats;$noUpgrade['maxLevel']=1;
checkWorldBot(Bots::siteManagementPlan($food,$owned,$noUpgrade,'guardian',$now)===null,'Every owned champion meal ingredient is reserved from site garrisons');
$food['army']['pokey']=38;
checkWorldBot(Bots::siteManagementPlan($food,$owned,$noUpgrade,'guardian',$now)===null,'Reserved food does not falsely count as an80-space offensive reserve');
$food['army']['pokey']=41;
checkWorldBot(Bots::siteManagementPlan($food,$owned,$noUpgrade,'guardian',$now)['input']['quantity']===3,'Only true surplus beyond food and80-space offence can reinforce a site');
$owned['state']['garrison']['10']=['pokey'=>10];$food['army']=['pokey'=>20];$food['resources']['goo']=2699;
checkWorldBot(Bots::siteManagementPlan($food,$owned,$stats,'guardian',$now)===null,'Optional site costs preserve actual goo for missing champion food');
$food['resources']['goo']=2700;
checkWorldBot(Bots::siteManagementPlan($food,$owned,$stats,'guardian',$now)['action']==='worldUpgrade','Site investment resumes when food reserve and complete price are available');
if(isset(Catalog::buildings()['maproom'])){
    checkWorldBot(Catalog::buildings()['maproom']['unlockLevel']===3,'Map Room requires Town Hall3');
    for($id=1;$id<=1000;$id++){
        checkWorldBot(count(Bots::placements($id,'maproom',0,$base,Catalog::buildings()['maproom']))>0,'Every starter profile has legal Map Room placement');
    }
}
echo "PASS {$checks} territory bot planning checks across1000 persistent IDs.\n";
