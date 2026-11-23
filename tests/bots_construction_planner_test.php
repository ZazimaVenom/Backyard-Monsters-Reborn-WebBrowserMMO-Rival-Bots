<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Catalog.php';
require_once dirname(__DIR__).'/app/Game.php';
require_once dirname(__DIR__).'/app/Bots.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never {throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function checkConstructionBot(bool $truth,string $message):void {global $checks;++$checks;if(!$truth){throw new RuntimeException('FAIL: '.$message);}}
function constructionBotBuilding(int $id,string $type,float $x,float $y,int $level=1):array {
    $hp=Game::buildingStats($type,$level)['max_hp'];
    return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>17,'fortification'=>0,'fortify_ready_at'=>0,'fortify_to'=>null,'fortify_paid'=>null];
}
$now=time();
$base=Game::normalizeBase(['buildings'=>[
    constructionBotBuilding(1,'townhall',16,16,5),constructionBotBuilding(2,'flinger',7,7,3),
    constructionBotBuilding(3,'planner',8,12),constructionBotBuilding(4,'storage',24,24,3),
    constructionBotBuilding(5,'housing',8,24,3),constructionBotBuilding(6,'hatchery',24,8,3),
    constructionBotBuilding(7,'sniper',4,28,2),constructionBotBuilding(8,'tesla',28,28,2),
    constructionBotBuilding(9,'cannon',28,4,2),constructionBotBuilding(10,'wall',4,20),
    constructionBotBuilding(11,'maproom',16,7,3)],
    'resources'=>array_fill_keys(['twig','pebble','putty','goo'],1000000),'army'=>['pokey'=>80],
    'queue'=>[],'research'=>[],'garrison'=>[],'last_tick'=>$now,'workers'=>1]);
$before=$base;$signatures=[];$profiles=[];
for($id=1;$id<=1000;$id++){
    $profile=Bots::profile($id);$profiles[$profile]=true;
    checkConstructionBot((Bots::buildPlan($profile)['planner'] ?? 0)===1,'Every persistent identity plans exactly one paid Yard Planner');
    checkConstructionBot(Bots::workerHirePlan($base,$profile,$now)===[],'All funded identities can plan a normal paid second worker');
    checkConstructionBot(Bots::workerHirePlan($base,$profile,$now)===Bots::workerHirePlan($base,$profile,$now),'Hiring intent is restart-stable');
    $turns=0;for($turn=1;$turn<=11;$turn++){$turns+=(int)Bots::layoutTurn($id,$turn);}
    checkConstructionBot($turns===1,'Layout attempts leave ten of eleven turns without a layout search');
    $plan=Bots::layoutPlan($id,$base,$now);
    checkConstructionBot($plan!==null,'Prepared poorly covered yard has a genuine defensive improvement for every identity');
    checkConstructionBot($plan===Bots::layoutPlan($id,$base,$now),'Identical saved state yields identical geometry after restart');
    checkConstructionBot($plan['revision']===Planner::revision($base),'Plan carries current geometry revision');
    checkConstructionBot(Planner::validateLayout($base,$plan['positions'],$now)===$plan['positions'],'Every proposed bot layout passes the human validator');
    $applied=$base;Planner::mutateBase($applied,'plannerApply',$plan,$now);
    checkConstructionBot(Bots::layoutScore($applied,$profile,$now)>=Bots::layoutScore($base,$profile,$now)+0.5,'Movement improves actual weighted defensive coverage');
    $changed=0;
    foreach($applied['buildings'] as $index=>$building){
        $old=$base['buildings'][$index];$changed+=(int)($building['x']!=$old['x'] || $building['y']!=$old['y']);
        unset($building['x'],$building['y'],$old['x'],$old['y']);
        checkConstructionBot($building===$old,'Layout preserves building IDs, HP, levels, stored resources and paid fields');
    }
    checkConstructionBot($changed===1,'Bounded bot planning moves at most one real tower');
    $outside=$applied;unset($outside['buildings']);$oldOutside=$base;unset($oldOutside['buildings']);
    checkConstructionBot($outside===$oldOutside,'Layout grants no worker, money, army, rank, slot or completed work');
    checkConstructionBot($base===$before,'Pure planning never mutates its source yard');
    $signatures[json_encode($plan['positions'])]=true;
}
checkConstructionBot(count($profiles)===4 && count($signatures)>=8,'Personalities and identity tie-breaks create diverse useful layouts');

foreach(['hp'=>0,'level'=>0,'ready_at'=>$now+30] as $field=>$value){
    $blocked=$base;$blocked['buildings'][2][$field]=$value;
    checkConstructionBot(Bots::layoutPlan(1,$blocked,$now)===null,'Planner facility gate respects '.$field);
}
$busy=$base;foreach([6,7,8] as $index){$busy['buildings'][$index]['ready_at']=$now+60;$busy['buildings'][$index]['upgrade_to']=3;}
checkConstructionBot(Bots::layoutPlan(1,$busy,$now)===null,'Paid tower construction positions remain locked');
checkConstructionBot(Bots::fortificationPlan($busy,'guardian',$now)===null,'Legacy worker overload blocks new armor planning');
$locked=$base;$locked['buildings'][6]['fortify_ready_at']=$now+60;$locked['buildings'][6]['fortify_to']=1;
$plan=Bots::layoutPlan(1,$locked,$now);
if($plan!==null){$node=array_values(array_filter($plan['positions'],static fn(array $p):bool=>$p['id']===7))[0];checkConstructionBot($node['x']==4 && $node['y']==28,'Planning never moves a fortifying tower');}
$damaged=$base;$damaged['buildings'][6]['hp']=1;$damaged['buildings'][6]['fortification']=3;
$plan=Bots::layoutPlan(3,$damaged,$now);
checkConstructionBot($plan!==null,'Damaged buildings can retain their injuries in a useful safe layout');
Planner::mutateBase($damaged,'plannerApply',$plan,$now);
checkConstructionBot($damaged['buildings'][6]['hp']===1 && $damaged['buildings'][6]['fortification']===3,'Applying a bot layout neither repairs damage nor changes earned armor');
$invalid=$base;$invalid['buildings'][6]['x']=16;$invalid['buildings'][6]['y']=16;
checkConstructionBot(Bots::layoutPlan(1,$invalid,$now)===null,'An already invalid legacy layout is never blindly rearranged');
$nonTowers=$base;$nonTowers['buildings']=array_values(array_filter($base['buildings'],static fn(array $b):bool=>!in_array($b['type'],['sniper','tesla','cannon'],true)));
checkConstructionBot(Bots::layoutPlan(1,$nonTowers,$now)===null,'No invented tower supplies an improvement');

// A distant aerial gun can move to cover the core, while a heavy trap stays in place.
$specialist = $nonTowers;
$specialist['buildings'][] = constructionBotBuilding(20, 'heavytrap', 10, 16);
checkConstructionBot(Bots::layoutPlan(3, $specialist, $now) === null, 'A pressure plate cannot provide anti-air tower coverage');
$specialist['buildings'][] = constructionBotBuilding(21, 'aerial', 28, 28);
$specialistPlan = Bots::layoutPlan(3, $specialist, $now);
checkConstructionBot($specialistPlan !== null, 'The bot Planner can improve a distant aerial tower');
Planner::mutateBase($specialist, 'plannerApply', $specialistPlan, $now);
$plate = array_column($specialist['buildings'], null, 'id')[20];
checkConstructionBot($plate['x'] == 10 && $plate['y'] == 16, 'Repositioning air cover leaves the hidden pressure plate at its intended approach');

$poor=$base;$poor['resources']=array_fill_keys(['twig','pebble','putty','goo'],0);
checkConstructionBot(Bots::workerHirePlan($poor,'builder',$now)===null,'No free worker is granted to an unfunded bot');
$max=$base;$max['workers']=5;checkConstructionBot(Bots::workerHirePlan($max,'builder',$now)===null,'Five purchased workers is a hard limit');
$max['workers']=3;checkConstructionBot(Bots::workerHirePlan($max,'raider',$now)===null,'Raider keeps its normal smaller workforce preference');
$max['workers']=4;checkConstructionBot(Bots::workerHirePlan($max,'collector',$now)===null,'Collector avoids unnecessary fifth worker expense');
foreach(['hp'=>0,'ready_at'=>$now+30,'level'=>1] as $field=>$value){$blocked=$base;$blocked['buildings'][0][$field]=$value;checkConstructionBot(Bots::workerHirePlan($blocked,'builder',$now)===null,'Worker hire requires completed living Town Hall: '.$field);}
$blocked=$base;$blocked['buildings'][1]['hp']=0;checkConstructionBot(Bots::workerHirePlan($blocked,'builder',$now)===null,'Flinger recovery precedes optional worker purchases');
$cost=Construction::status($base,$now)['nextHire']['cost'];$reserve=Bots::networkHomeReserve($base,'builder',$now);
$funded=$base;foreach($cost as $resource=>$amount){$funded['resources'][$resource]=$amount+max($reserve[$resource],$resource==='goo'?1000:0);}
checkConstructionBot(Bots::workerHirePlan($funded,'builder',$now)===[],'Hire preserves exact next-home and minimal army funds');
$funded['resources']['twig']--;checkConstructionBot(Bots::workerHirePlan($funded,'builder',$now)===null,'One twig short of the protected home reserve delays hiring');
$meal=$base;$meal['activeChampion']='drull';$meal['champions']=['drull'=>['level'=>6,'frozen'=>false,'bonus_feeds'=>0]];$meal['army']=[];$meal['resources']['goo']=1;
checkConstructionBot(Bots::workerHirePlan($meal,'guardian',$now)===null,'Hiring policy preserves the next missing champion meal, despite zero goo hire cost');
checkConstructionBot(Construction::status($busy,$now)['busy']===3 && Construction::status($busy,$now)['capacity']===1,'Legacy concurrent jobs remain untouched by bot planning');
echo "PASS {$checks} construction and Yard Planner bot policy checks.\n";
