<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$checks=0;
function repairCheck(bool $condition,string $label):void{global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function repairBuilding(int $id,string $type,int $level,float $health,float $maximum,array $extra=[]):array{
    return $extra+['id'=>$id,'type'=>$type,'level'=>$level,'hp'=>$health,'max_hp'=>$maximum,'x'=>2+$id*2,'y'=>16,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0,'fortification'=>0,'fortify_ready_at'=>0,'fortify_to'=>null,'fortify_paid'=>null];
}
$base=[
    'buildings'=>[
        repairBuilding(1,'townhall',3,0,6480,['fortification'=>2]),
        repairBuilding(2,'wall',2,12.5,405),
        repairBuilding(3,'trap',1,0,450),
        repairBuilding(4,'sniper',2,23,3000,['ready_at'=>9999999900,'upgrade_to'=>3]),
        repairBuilding(5,'storage',2,0,4000,['fortify_ready_at'=>9999999800,'fortify_to'=>3,'fortify_paid'=>['twig'=>123,'pebble'=>456,'putty'=>789]]),
        repairBuilding(6,'hatchery',0,0,1800,['ready_at'=>9999999700,'upgrade_to'=>1]),
        repairBuilding(7,'goo',1,1200,1200,['stored'=>321.5]),
    ],
    'resources'=>['twig'=>0,'pebble'=>0,'putty'=>0,'goo'=>0],
    'army'=>['pokey'=>4,'octo'=>0],'garrison'=>['8'=>['fink'=>2]],
    'queue'=>[['type'=>'pokey','ready_at'=>9999999600,'paid_goo'=>120]],
    'researchQueue'=>[['type'=>'pokey','target_level'=>3,'ready_at'=>9999999500]],
    'unlockQueue'=>[['type'=>'bolt','ready_at'=>9999999400]],
    'champions'=>['gorgo'=>['level'=>5,'hp'=>12,'feeds'=>2,'next_feed_at'=>9999999300]],
    'lab'=>['levels'=>['pokey'=>1],'queue'=>['type'=>'fink','target_level'=>2,'ready_at'=>9999999200]],
    'world'=>['holdings'=>1,'capacity'=>5000,'resourcePerSecond'=>1.25,'pending'=>[['ready_at'=>9999999100]]],
    'workers'=>1,'planner'=>['slots'=>[['name'=>'Keep this layout','positions'=>[['id'=>1,'x'=>4,'y'=>16]]]]],
    'shield_until'=>9999999000,'last_tick'=>1700000000,'next_building_id'=>8,'next_queue_id'=>14,
    '_activity_clock'=>['epoch'=>1700000000,'pausedSeconds'=>1234],'custom'=>['preserve'=>'verbatim'],
];
$before=$base;$status=Game::repairHomeStatus($base);
repairCheck($status['damaged']===5&&$status['available']&&$status['free']&&$status['instant'],'status counts destroyed/damaged existing buildings without counting new construction');
repairCheck($base===$before,'repair status is read-only');
repairCheck(Game::repairHome($base)===5,'one free operation repairs the complete damaged home');
$expected=$before;
foreach([0,1,2,3,4] as $index){$expected['buildings'][$index]['hp']=$expected['buildings'][$index]['max_hp'];}
repairCheck($base===$expected,'bulk repair changes exactly HP and preserves every other saved value');
repairCheck($base['buildings'][5]===$before['buildings'][5],'new construction remains unbuilt with its exact paid timer');
repairCheck($base['buildings'][3]['ready_at']===$before['buildings'][3]['ready_at']&&$base['buildings'][3]['upgrade_to']===3,'existing upgrade is healed without completion');
repairCheck($base['buildings'][4]['fortify_ready_at']===$before['buildings'][4]['fortify_ready_at']&&$base['buildings'][4]['fortify_paid']===$before['buildings'][4]['fortify_paid'],'existing reinforcement is healed without changing timer or payment');
repairCheck($base['buildings'][2]['hp']>0,'a spent home trap is rearmed by HP restoration');
repairCheck($base['resources']===$before['resources'],'zero-resource yard owes no repair charge');
repairCheck($base['army']===$before['army']&&$base['garrison']===$before['garrison']&&$base['champions']===$before['champions'],'repairs never replace dead monsters or heal champions');
repairCheck(Game::repairHome($base)===0&&$base===$expected,'repeat bulk repair is an exact no-op');
$status=Game::repairHomeStatus($base);repairCheck(!$status['available']&&$status['damaged']===0&&$status['reason']==='Your base is fully repaired.','healthy home shows explicit no-op status despite new construction');
$single=$before;$expected=$before;$expected['buildings'][3]['hp']=$expected['buildings'][3]['max_hp'];
repairCheck(Game::repairHome($single,4)===1&&$single===$expected,'single repair uses identical HP-only rules on an upgrading building');
repairCheck(Game::repairHome($single,4)===0&&$single===$expected,'single repair is idempotent');
repairCheck(Game::repairHome($single,6)===0&&$single===$expected,'single pure operation cannot finish a level-zero building');
repairCheck(Game::repairHome($single,999)===0&&$single===$expected,'an unrelated ID cannot repair a different building');
$empty=['buildings'=>[],'resources'=>['twig'=>0]];$beforeEmpty=$empty;
repairCheck(Game::repairHome($empty)===0&&$empty===$beforeEmpty&&!Game::repairHomeStatus($empty)['available'],'empty home remains unchanged');
foreach(Catalog::buildings() as $type=>$spec){
    if(!empty($spec['siteOnly'])){continue;}
    $sample=['buildings'=>[repairBuilding(1,$type,1,0,(float)$spec['hp'])]];
    repairCheck(Game::repairHome($sample)===1&&$sample['buildings'][0]['hp']===$sample['buildings'][0]['max_hp'],'every existing home building type can recover: '.$type);
}
echo "Home repair pure rules: $checks checks passed.\n";
