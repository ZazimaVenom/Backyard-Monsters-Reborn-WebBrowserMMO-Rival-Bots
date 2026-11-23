<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function siegeCheck(bool $ok,string $message): void {
    global $checks; if (!$ok) { throw new RuntimeException($message); }++$checks;
}
function siegeReject(callable $fn,string $text): void {
    try { $fn(); } catch (RuntimeException $e) { siegeCheck(str_contains($e->getMessage(),$text),'Unexpected rejection: '.$e->getMessage());return; }
    throw new RuntimeException('Expected rejection: '.$text);
}
function siegeHome(int $lab=10):array {
    return ['buildings'=>[
        ['type'=>'townhall','level'=>5,'hp'=>10000,'max_hp'=>10000],
        ['type'=>'siegelab','level'=>$lab,'hp'=>2000,'max_hp'=>2000],
        ['type'=>'siegefactory','level'=>1,'hp'=>2000,'max_hp'=>2000],
    ], 'resources'=>array_fill_keys(['twig','pebble','putty','goo'],30000),'army'=>[],'queue'=>[],'last_tick'=>1000];
}
function advanceSiege(array &$b,int $now): void { SiegeWorkshop::accrue($b,$now);$b['last_tick']=$now; }
$empty=['buildings'=>[],'resources'=>array_fill_keys(['twig','pebble','putty','goo'],0),'army'=>[],'queue'=>[]];
$copy=$empty;
siegeCheck(SiegeWorkshop::loadout($empty)===null,'Old saves start without a device');
SiegeWorkshop::accrue($empty,2000);SiegeWorkshop::view($empty,2000,1);
siegeCheck($copy===$empty,'Reading legacy home grants no levels, stock, timers or fields');
siegeReject(fn()=>SiegeWorkshop::act($empty,'siegeResearch',['type'=>'jars','revision'=>0],2000,1),'Lab');
$buildings=Catalog::buildings();
foreach(['siegelab'=>10,'siegefactory'=>1] as $key=>$levels){
    siegeCheck($buildings[$key]['maxLevel']===$levels && count($buildings[$key]['levels'])===$levels,'Building level count '.$key);
    siegeCheck($buildings[$key]['limitByTownHall'][5]===1,'One facility per yard');
    for($level=1;$level<=$levels;$level++){
        $tier=$buildings[$key]['levels'][$level];
        siegeCheck($tier['hp']===Game::buildingStats($key,$level)['max_hp'],'Source-scaled health '.$key.' '.$level);
        if($level>1){siegeCheck(Game::upgradeCost($key,$level-1)===$tier['cost'],'Exact upcoming building cost');}
    }
}
foreach (SiegeWorkshop::DEVICES as $type=>$_) {
    $base=siegeHome();
    siegeReject(fn()=>SiegeWorkshop::act($base,'siegeBuild',['type'=>$type,'revision'=>0],1000,1),'Unlock');
    for($level=1;$level<=10;$level++) {
        $base['resources']=array_fill_keys(['twig','pebble','putty','goo'],900000);
        $now=$base['last_tick'];$rev=(int)($base['siege']['revision']['research']??0);
        $req=['type'=>$type,'revision'=>$rev];
        $cost=SiegeWorkshop::price($type,'research',$level);
        $result=SiegeWorkshop::act($base,'siegeResearch',$req,$now,2);
        siegeCheck($result['level']===$level && $base['siege']['research']['paid']===$cost['cost'],'Paid research at correct tier');
        siegeCheck($result['ready_at']===$now+(int)ceil($cost['seconds']/2),'Speed scaling');
        siegeReject(fn()=>SiegeWorkshop::act($base,'siegeResearch',$req,$now,2),'changed');
        advanceSiege($base,$result['ready_at']-1);siegeCheck(($base['siege']['levels'][$type]??0)===$level-1,'Research cannot complete early');
        advanceSiege($base,$result['ready_at']);siegeCheck($base['siege']['levels'][$type]===$level,'Research completes exactly at deadline');
        $rev=(int)($base['siege']['revision']['build']??0);
        $req=['type'=>$type,'revision'=>$rev];
        $buildPrice=SiegeWorkshop::price($type,'build',$level);
        $order=SiegeWorkshop::act($base,'siegeBuild',$req,$base['last_tick'],1);
        siegeCheck($base['siege']['build']['paid']===$buildPrice['cost'],'Finished research tier sets the production cost');
        siegeCheck(SiegeWorkshop::loadout($base)===null,'Factory job is not a usable device');
        advanceSiege($base,$order['ready_at']);
        siegeCheck(SiegeWorkshop::loadout($base)===['type'=>$type,'level'=>$level],'Finished production exposes correct loadout');
        siegeReject(fn()=>SiegeWorkshop::act($base,'siegeBuild',['type'=>$type,'revision'=>$base['siege']['revision']['build']],$base['last_tick'],1),'prepared');
        $base['siege']['inventory'][$type]=0;
    }
    siegeReject(fn()=>SiegeWorkshop::act($base,'siegeResearch',['type'=>$type,'revision'=>$base['siege']['revision']['research']],$base['last_tick'],1),'Maximum');
}
$b=siegeHome(1);$b['siege']=['levels'=>['decoy'=>1]];
siegeReject(fn()=>SiegeWorkshop::act($b,'siegeResearch',['type'=>'decoy','revision'=>0],1000,1),'level 2');
$b['buildings'][1]['hp']=999;
siegeReject(fn()=>SiegeWorkshop::act($b,'siegeResearch',['type'=>'jars','revision'=>0],1000,1),'half health');
$b['buildings'][1]['hp']=1000;
$job=SiegeWorkshop::act($b,'siegeResearch',['type'=>'jars','revision'=>0],1000,1);
$order=SiegeWorkshop::act($b,'siegeBuild',['type'=>'decoy','revision'=>0],1000,1);
siegeCheck($b['siege']['research'] && $b['siege']['build'],'Factory and Lab have independent slots');
$b['buildings'][2]['hp']=0;$ready=$order['ready_at'];advanceSiege($b,1100);
siegeCheck($b['siege']['build']['ready_at']===$ready+100 && !isset($b['siege']['inventory']),'Destroyed building pauses job');
advanceSiege($b,1100);siegeCheck($b['siege']['build']['ready_at']===$ready+100,'Repeated sync retains deadline');
$b['buildings'][2]['hp']=1000;$cost=$b['siege']['build']['paid'];
$b['resources']=array_fill_keys(['twig','pebble','putty','goo'],29997);
$refund=SiegeWorkshop::act($b,'siegeCancel',['lane'=>'build','revision'=>1],1100,1);
foreach($cost as $r=>$paid){
    siegeCheck($refund['credited'][$r]===min(3,$paid)&&$refund['overflow'][$r]===max(0,$paid-3),'Capped refund matches actual payment');
}
siegeReject(fn()=>SiegeWorkshop::act($b,'siegeCancel',['lane'=>'build','revision'=>1],1100,1),'changed');
$b['siege']['inventory']['decoy']=1;$b['buildings'][2]['hp']=999;siegeCheck(SiegeWorkshop::loadout($b)===null,'Damaged Factory cannot prepare a loadout');
$b['buildings'][2]['hp']=1000;siegeCheck(SiegeWorkshop::loadout($b)['type']==='decoy','Repairing Factory restores access to paid stock');
$b['siege']['inventory']=[];$b['siege']['levels']['jars']=1;
$deviceAward=Quests::claim($b,['is_bot'=>false],'SW6',Game::capacity($b));
siegeCheck($deviceAward['device']==='jars' && SiegeWorkshop::loadout($b)===['type'=>'jars','level'=>1],'Journal provides one researched device in vacant slot');
siegeReject(fn()=>Quests::claim($b,['is_bot'=>false],'SW4',Game::capacity($b)),'slot');
$b['siege']['inventory']['jars']=0;$before=$b['resources'];
$repeat=Quests::claim($b,['is_bot'=>false],'SW6',Game::capacity($b));siegeCheck($repeat['alreadyClaimed'] && $before===$b['resources'] && !SiegeWorkshop::loadout($b),'Claim retry after use cannot duplicate a device');
echo "Chaos workshop: $checks checks passed.\n";
