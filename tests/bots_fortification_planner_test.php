<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Catalog.php';
require_once dirname(__DIR__).'/app/Game.php';
require_once dirname(__DIR__).'/app/Bots.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never {throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function armorBotCheck(bool $condition,string $message):void {global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$message);}}
function armorBotBuilding(int $id,string $type,int $level=1,int $fortification=0):array {
    $hp=Game::buildingStats($type,$level)['max_hp'];
    return ['id'=>$id,'type'=>$type,'x'=>4+$id*3,'y'=>5,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'fortification'=>$fortification,'fortify_ready_at'=>0,'fortify_to'=>null];
}
$now=time();
$base=['buildings'=>[armorBotBuilding(1,'townhall',5),armorBotBuilding(2,'flinger'),armorBotBuilding(3,'sniper'),armorBotBuilding(4,'storage')],
    'resources'=>array_fill_keys(['twig','pebble','putty','goo'],1000000),'army'=>[],'queue'=>[],'unlocked'=>array_keys(Catalog::monsters()),'champions'=>[],'activeChampion'=>null];
$profiles=[];
for($id=1;$id<=1000;$id++){
    $profile=Bots::profile($id);$profiles[$profile]=true;
    $before=$base;$plan=Bots::fortificationPlan($base,$profile,$now);
    armorBotCheck($plan===['id'=>1],'Every saved identity plans paid Town Hall armor first');
    armorBotCheck($plan===Bots::fortificationPlan($base,$profile,$now),'Armor planning is deterministic across worker restarts');
    armorBotCheck($base===$before,'Planning never grants a tier or edits saved funds');
    $choice=Bots::championChoice($id);$champ=$base;
    $champ['activeChampion']=$choice;$champ['champions'][$choice]=['level'=>6,'bonus_feeds'=>2,'hp'=>100,'next_feed_at'=>$now+300,'frozen'=>false,'battle_id'=>0];
    $expected=match($choice){'gorgo'=>['crabatron'=>20],'drull'=>['fang'=>30],default=>['brain'=>20]};
    $champ['army']=$expected;
    armorBotCheck(Bots::championRecipe($champ)===$expected,'Every persistent identity retains the shipped level-six recipe');
    armorBotCheck(Bots::championReserve($champ)===$expected,'Every identity reserves its actual next bonus/maintenance meal');
    armorBotCheck(Bots::championFoodGooReserve($champ)===0,'An owned meal is never charged twice in the budget');
}
armorBotCheck(count($profiles)===4,'All four persistent profiles use the armor planner');
$blocked=$base;$blocked['resources']=array_fill_keys(['twig','pebble','putty','goo'],0);
armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===null,'An unfunded bot receives no free armor plan');
$blocked=$base;$blocked['buildings'][1]['hp']=0;
armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===null,'Destroyed Flinger recovery takes precedence over optional armor');
$blocked=$base;$blocked['buildings'][1]['ready_at']=$now+1;
armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===null,'An unfinished Flinger cannot unlock optional armor spending');
$blocked=$base;$blocked['buildings'][0]['hp']=0;
armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===null,'A destroyed Town Hall cannot open armor on otherwise healthy towers');
$blocked=$base;$blocked['buildings'][0]['ready_at']=$now+60;
armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===null,'An upgrading Town Hall cannot act as a completed armor prerequisite');
$blocked=$base;$blocked['buildings'][0]['fortification']=1;
armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===['id'=>3],'The first armor tier spreads to defenses before deeper Town Hall investment');
$blocked['buildings'][2]['fortification']=1;
armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===['id'=>4],'Storage receives its first tier before second-tier defenses');
$blocked['buildings'][3]['fortification']=1;
armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===['id'=>1],'After spreading protection the central objective deepens first');
$blocked=$base;$blocked['buildings'][0]['fortification']=1;
armorBotCheck(Bots::fortificationPlan($blocked,'collector',$now)===['id'=>3],'Collector ties remain deterministic by actual building ID');
foreach(['ready_at','fortify_ready_at'] as $timer){
    $blocked=$base;
    foreach($blocked['buildings'] as &$building){if($building['type']!=='flinger'){$building[$timer]=$now+60;}}unset($building);
    armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===null,'A running '.$timer.' timer cannot be duplicated');
}
foreach(['upgrade_to','fortify_to'] as $pending){
    $blocked=$base;
    foreach($blocked['buildings'] as &$building){if($building['type']!=='flinger'){$building[$pending]=2;}}unset($building);
    armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===null,'An unsettled '.$pending.' operation is left to the game clock');
}
$blocked=$base;
foreach($blocked['buildings'] as &$building){if($building['type']!=='flinger'){$building['hp']-=1;}}unset($building);
armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===null,'Damaged structures are repaired before fortifying');
$blocked=$base;
foreach($blocked['buildings'] as &$building){$building['fortification']=4;}unset($building);
armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===null,'Highest earned armor tiers cannot purchase a fifth tier');
foreach(['wall','bunker','trap','heavytrap','hatchery','lab','maproom','outpost','stronghold'] as $type){
    $blocked=$base;$blocked['buildings']=[armorBotBuilding(1,'townhall',5,4),armorBotBuilding(2,'flinger'),armorBotBuilding(3,$type)];
    armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===null,$type.' never acquires unsupported per-building fortification');
}
foreach(['townhall','storage','sniper','cannon','laser','tesla','aerial'] as $type){
    for($tier=1;$tier<=4;$tier++){
        $stats=Catalog::fortificationStats($type,$tier);$target=armorBotBuilding(3,$type,$type==='townhall'?$stats['requiredTownHall']:1,$tier-1);
        $blocked=$base;$blocked['buildings']=[armorBotBuilding(1,'townhall',$stats['requiredTownHall'],4),armorBotBuilding(2,'flinger'),$target];
        $blocked['resources']=$stats['cost'];
        armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===['id'=>3],$type.' tier'.$tier.' uses exact normal price and Town Hall gate');
        foreach($stats['cost'] as $resource=>$cost){
            if($cost<1){continue;}$poor=$blocked;$poor['resources'][$resource]=$cost-1;
            armorBotCheck(Bots::fortificationPlan($poor,'guardian',$now)===null,$type.' tier'.$tier.' requires the entire '.$resource.' price');
        }
        $blocked['buildings'][0]['level']=$stats['requiredTownHall']-1;
        if($type==='townhall'){$blocked['buildings'][2]['level']=$stats['requiredTownHall']-1;}
        armorBotCheck(Bots::fortificationPlan($blocked,'guardian',$now)===null,$type.' tier'.$tier.' cannot bypass progression');
    }
}
foreach(['gorgo'=>['crabatron'=>20],'drull'=>['fang'=>30],'fomor'=>['brain'=>20]] as $type=>$recipe){
    $champ=$base;$champ['buildings'][]=armorBotBuilding(5,'championcage');$champ['buildings'][]=armorBotBuilding(6,'hatchery');$champ['buildings'][]=armorBotBuilding(7,'housing',5);
    $champ['activeChampion']=$type;$champ['army']=$recipe;
    $champ['champions'][$type]=['level'=>6,'bonus_feeds'=>0,'hp'=>Catalog::championStats($type,6)['hp'],'next_feed_at'=>0,'frozen'=>false,'battle_id'=>0];
    for($tier=0;$tier<=3;$tier++){
        $champ['champions'][$type]['bonus_feeds']=$tier;
        armorBotCheck(Bots::championPlan($champ,1,$now)===['action'=>'championFeed','input'=>[]],$type.' bonus'.$tier.' uses the same paid meal action, including top-tier maintenance');
        armorBotCheck(Bots::championTrainingPlan($champ)===null,$type.' complete meals do not cause duplicate training');
    }
    $food=array_key_first($recipe);$count=$recipe[$food];$champ['army'][$food]=$count-2;
    armorBotCheck(Bots::championPlan($champ,1,$now)===null,$type.' incomplete level-six meal cannot feed');
    armorBotCheck(Bots::championReserve($champ)===[$food=>$count-2],$type.' reservation cannot invent missing troops');
    armorBotCheck(Bots::championTrainingPlan($champ)===['type'=>$food,'quantity'=>2],$type.' pays to hatch the real food deficit');
    armorBotCheck(Bots::championFoodGooReserve($champ)===2*Catalog::monsters()[$food]['cost'],$type.' missing-food funds stay protected');
    $champ['queue']=[['type'=>$food,'quantity'=>2]];
    armorBotCheck(Bots::championTrainingPlan($champ)===null && Bots::championFoodGooReserve($champ)===0,$type.' paid queue counts exactly once');
    $champ['queue']=[];$champ['army'][$food]=$count;$champ['champions'][$type]['next_feed_at']=$now+1;
    armorBotCheck(Bots::championPlan($champ,1,$now)===null,$type.' cannot bypass bonus feed cooldown');
    $champ['champions'][$type]['next_feed_at']=0;$champ['champions'][$type]['battle_id']=99;
    armorBotCheck(Bots::championPlan($champ,1,$now)===null,$type.' away in battle cannot be fed');
    $champ['champions'][$type]['battle_id']=0;$champ['champions'][$type]['frozen']=true;
    armorBotCheck(Bots::championPlan($champ,1,$now)===null && Bots::championRecipe($champ)===[],$type.' frozen bonus state does not cause food spending');
    $champ['champions'][$type]['frozen']=false;$champ['buildings'][4]['hp']=0;
    armorBotCheck(Bots::championPlan($champ,1,$now)===null,$type.' needs its living completed Cage');
}
// Meal troops cannot masquerade as the separate 80-housing raiding reserve.
$stock=$base;$stock['activeChampion']='drull';$stock['champions']['drull']=['level'=>6,'bonus_feeds'=>3,'frozen'=>false];
$stock['army']=['fang'=>30,'pokey'=>8];$stock['buildings'][]=armorBotBuilding(5,'bunker',3);
armorBotCheck(Bots::garrisonPlan($stock,'guardian')===null,'A full bonus meal cannot count toward the home garrison offensive reserve');
$stock['army']['pokey']=11;
armorBotCheck(Bots::garrisonPlan($stock,'guardian')===['id'=>5,'type'=>'pokey','quantity'=>3],'Only surplus beyond meal plus80housing enters the home garrison');
$cost=Catalog::fortificationStats('townhall',1)['cost'];$stock['resources']=$cost;$stock['army']=['fang'=>29];
$required=Catalog::monsters()['fang']['cost'];$stock['resources']['goo']=($cost['goo'] ?? 0)+$required-1;
armorBotCheck(Bots::fortificationPlan($stock,'guardian',$now)===null,'Optional armor cannot consume the next missing meal budget');
$stock['resources']['goo']++;
armorBotCheck(Bots::fortificationPlan($stock,'guardian',$now)===['id'=>1],'Armor resumes when its full cost and food reserve are both funded');
echo "PASS {$checks} fortification and bonus-meal planning checks across 1000 persistent IDs.\n";
