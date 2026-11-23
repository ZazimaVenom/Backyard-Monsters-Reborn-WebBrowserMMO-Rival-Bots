<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Construction.php';
$checks=0;
function verify(bool $condition,string $label):void{global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function reject(callable $work,string $label):void{try{$work();}catch(RuntimeException){verify(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
function yard(int $workers=1,int $hall=5):array{return ['workers'=>$workers,'resources'=>['twig'=>1000000,'pebble'=>1000000,'putty'=>1000000,'goo'=>1000000],'buildings'=>[['id'=>1,'type'=>'townhall','level'=>$hall,'hp'=>1000,'ready_at'=>0]],'army'=>['pokey'=>19],'custom'=>['preserve'=>true]];}
function job(int $id,int $ready=0,int $fortify=0,int $level=1):array{return ['id'=>$id,'type'=>'sniper','level'=>$level,'hp'=>700,'ready_at'=>$ready,'fortify_ready_at'=>$fortify,'fortify_paid'=>['twig'=>500],'x'=>8,'y'=>10];}
foreach([null,'5',5.0,[],false,0,1,2,3,4,5,6,-4] as $value){$expected=is_int($value)?max(1,min(5,$value)):1;verify(Construction::normalizeWorkers($value)===$expected,'bounded purchased worker value '.json_encode($value));}
$base=yard();unset($base['workers']);$before=$base;$status=Construction::status($base,1000);
verify($status['capacity']===1&&$status['busy']===0&&$status['available']===1&&!$status['overCapacity'],'legacy yard defaults to one available worker');
verify($base===$before,'status is read only');verify($status['max']===5,'source worker cap');
$base=yard(2);$base['buildings'][]=job(2,1100,0,0);$base['buildings'][]=job(3,1200);$base['buildings'][]=job(4,0,1300);$base['buildings'][]=job(5,900,1000);
$status=Construction::status($base,1000);verify($status['busy']===3&&$status['available']===0&&$status['overCapacity'],'grandfathered overflow reports every ongoing building');
verify(array_column($status['jobs'],'kind')===['build','upgrade','fortify'],'construction kinds distinguish real saved timers');
verify(array_column($status['jobs'],'buildingId')===[2,3,4],'jobs ordered by completion');
reject(fn()=>Construction::assertAvailable($base,1000),'overflow rejects new work');
$before=$base;Construction::status($base,2000);verify($base===$before,'status does not complete or rewrite legacy work');
verify(Construction::status($base,1099)['busy']===3,'slot occupied before exact completion');
verify(Construction::status($base,1100)['busy']===2&&!Construction::status($base,1100)['overCapacity'],'first exact completion still leaves all workers busy');
reject(fn()=>Construction::assertAvailable($base,1199),'last full-capacity instant rejects');Construction::assertAvailable($base,1200);verify(Construction::status($base,1200)['available']===1,'exact completion frees one slot without accrual');
verify(Construction::status($base,1300)['available']===2,'all completed slots free');
$base=yard();$base['buildings'][]=job(2,1200,1300);$base['buildings'][]=job(2,1250,0);$status=Construction::status($base,1000);
verify($status['busy']===1&&$status['jobs'][0]['readyAt']===1300&&$status['jobs'][0]['kind']==='fortify','distinct building counted once through later legacy timer');
$base['buildings'][1]['ready_at']=1400;$status=Construction::status($base,1000);verify($status['jobs'][0]['kind']==='upgrade'&&$status['jobs'][0]['readyAt']===1400,'longer normal timer remains occupied');
$base=yard(1,1);verify(!Construction::status($base,1000)['nextHire']['available'],'second worker needs living Town Hall two');$before=$base;reject(function()use(&$base){Construction::hire($base,1000);},'below-gate purchase rejected');verify($base===$before,'failed gate leaves all state unchanged');
foreach([2=>[10000,10000,1000,0],3=>[30000,30000,5000,0],4=>[90000,90000,15000,0],5=>[180000,180000,35000,0]] as $target=>$price){
    $base=yard($target-1,$target);$base['buildings'][]=job(2,1400);$before=$base;$next=Construction::status($base,1000)['nextHire'];$cost=array_combine(['twig','pebble','putty','goo'],$price);
    verify($next['target']===$target&&$next['requiredTownHall']===$target&&$next['cost']===$cost&&$next['available'],'exact source-cap/resource-adapted hire '.$target);
    Construction::hire($base,1000);verify($base['workers']===$target,'one permanent worker added '.$target);
    foreach($cost as $resource=>$amount){verify($base['resources'][$resource]===$before['resources'][$resource]-$amount,'exact hire charge '.$target.' '.$resource);}
    foreach(['buildings','army','custom'] as $key){verify($base[$key]===$before[$key],'hire preserves '.$key.' '.$target);}
    $base=yard($target-1,$target-1);reject(function()use(&$base){Construction::hire($base,1000);},'each next worker has exact Town Hall gate '.$target);
    foreach(['twig','pebble','putty'] as $resource){$base=yard($target-1,$target);$base['resources'][$resource]=$cost[$resource]-1;$before=$base;reject(function()use(&$base){Construction::hire($base,1000);},'insufficient '.$resource.' hire '.$target);verify($base===$before,'failed payment is atomic '.$target.' '.$resource);}
}
$base=yard(5);$before=$base;verify(!Construction::status($base,1000)['nextHire']['available'],'maximum worker action unavailable');reject(function()use(&$base){Construction::hire($base,1000);},'sixth worker rejected');verify($base===$before,'no maximum purchase charge');
foreach([['hp',0],['ready_at',1001]] as [$field,$value]){$base=yard(1,2);$base['buildings'][0][$field]=$value;reject(function()use(&$base){Construction::hire($base,1000);},'dead or upgrading Town Hall does not authorize hires '.$field);}
$base=yard(1,2);$base['buildings'][0]['ready_at']=1000;verify(Construction::status($base,1000)['nextHire']['available'],'completed Town Hall boundary accepted');
$base=yard();$base['buildings'][]=job(2,0,1200);$paid=$base['buildings'][1]['fortify_paid'];$base['buildings'][1]['fortify_ready_at']=0;$base['buildings'][1]['fortify_to']=null;verify(Construction::status($base,1000)['available']===1,'cancelled fortification immediately frees slot');verify($base['buildings'][1]['fortify_paid']===$paid,'occupancy derives only from timers, never mutates refund data');
$base=yard();$base['queue']=[['ready_at'=>9999]];$base['researchQueue']=[['ready_at'=>9999]];$base['lab']=['queue'=>['ready_at'=>9999]];verify(Construction::status($base,1000)['available']===1,'training research and Lab work do not consume construction workers');
echo "Construction rules: $checks assertions passed.\n";
