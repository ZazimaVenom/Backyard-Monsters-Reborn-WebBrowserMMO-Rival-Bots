<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$yard=['buildings'=>[['id'=>6,'type'=>'hatchery','hp'=>100,'level'=>1,'ready_at'=>0],['id'=>7,'type'=>'hatchery','hp'=>80,'level'=>2,'ready_at'=>0]],'queue'=>[
    ['lane'=>7,'started_at'=>80,'ready_at'=>110],['lane'=>7,'start'=>110,'ready_at'=>130],
    ['lane'=>7,'start'=>150,'ready_at'=>160],['duration'=>50,'ready_at'=>125]
],'storeTimers'=>['HOD2'=>120]];
function hatchMotionCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$view=YardUtilities::hatcheryWork($yard,100);
hatchMotionCheck($view[0]['hatchingUntil']===125 && $view[1]['hatchingUntil']===130,'coalesced contiguous windows, legacy eggs and both lane formats');
hatchMotionCheck($view[1]['hatchDrive']===['steps'=>3,'until'=>120],'overdrive follows a leased multiplier');
$view=YardUtilities::hatcheryWork($yard,135);hatchMotionCheck(!isset($view[0]['hatchingUntil'],$view[1]['hatchingUntil']),'completed and future jobs stay still');
$view=YardUtilities::hatcheryWork($yard,155);hatchMotionCheck($view[1]['hatchingUntil']===160 && !isset($view[1]['hatchDrive']),'next actual egg activates with expired boost');
$yard['buildings'][1]['hp']=0;$view=YardUtilities::hatcheryWork($yard,105);hatchMotionCheck(!isset($view[1]['hatchingUntil']),'destroyed hatchery');
$yard['queue']=[];hatchMotionCheck(YardUtilities::hatcheryWork($yard,105)===$yard['buildings'],'cancellation restores exact old view');
echo "Hatchery machinery lane projection: 6 checks passed.\n";
