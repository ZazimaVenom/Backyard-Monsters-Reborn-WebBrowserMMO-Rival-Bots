<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$checks=0;
function expect(bool $ok,string $why):void{global $checks;if(!$ok){throw new RuntimeException($why);}++$checks;}
function base():array{return ['buildings'=>[['type'=>'townhall','level'=>5,'hp'=>1],['type'=>'maproom','level'=>2,'hp'=>1],['type'=>'championcage','level'=>1,'hp'=>1]]];}
function award(array &$base,int $time,int $amount):void{
    KrallenHunt::credit($base,['twig'=>$amount],$time);KrallenHunt::accrue($base,KrallenHunt::weekEnd($time));
}
$time=strtotime('2026-11-16T00:00:00Z');$week=KrallenHunt::WEEK;
expect(KrallenHunt::weekEnd($time-1)===$time&&KrallenHunt::weekEnd($time)===$time+$week,'Shared Monday boundary');
$yard=base();$view=KrallenHunt::view($yard,$time);
expect($view['eligible']&&!isset($yard['krallenHunt'])&&!$view['power'],'Old yard receives no free companion or edit');
$yard['buildings'][1]['hp']=0;expect(!KrallenHunt::eligible($yard),'Damaged map room');$yard=base();
$yard['buildings'][0]['level']=4;expect(!KrallenHunt::eligible($yard),'Undersized hall');$yard=base();
expect(KrallenHunt::credit($yard,['bone'=>1000,'magma'=>50,'goo'=>-2],$time)===null&&!isset($yard['krallenHunt']),'Inferno and negative amounts ignored');
foreach([[249999,0],[250000,1],[749999,1],[750000,2]] as [$loot,$power]){
    $y=base();$r=KrallenHunt::credit($y,['twig'=>$loot-9,'pebble'=>3,'goo'=>2,'putty'=>4],$time);
    expect($r['loot']===$loot&&!$y['krallenHunt']['power'],'Earn into pending week');
    KrallenHunt::accrue($y,$time+$week-1);expect(!$y['krallenHunt']['power'],'No premature award');
    KrallenHunt::accrue($y,$time+$week);$v=KrallenHunt::view($y,$time+$week);
    expect($v['power']===$power&&$v['loot']===0&&$v['previous']['loot']===$loot&&$v['level']===(int)!!$power,'Weekly threshold');
}
$y=base();for($n=1;$n<=5;$n++){
    award($y,$time+($n-1)*$week,750000);$v=KrallenHunt::view($y,$time+$n*$week);$s=KrallenHunt::stats($n,2);
    expect($v['level']===$n&&$v['retained']===($n===5),'Five consecutive ranks');
    expect($s['hp']===[7500,7800,8100,8700,9300][$n-1]&&$s['damage']===[96,102,108,120,144][$n-1],'G5 native scaled stats');
    expect($s['lootBuff']===[.20,.22,.24,.27,.3][$n-1]&&$s['lootRadius']===[250,275,300,325,350][$n-1]/80,'Upgraded proximity effect');
}
expect($v['upgrade']===500000,'Kept power target');
$y['krallenHunt']['hp']=5000;$y['krallenHunt']['battle']=4;KrallenHunt::accrue($y,$time+5*$week+10000);
expect($y['krallenHunt']['hp']===5000&&KrallenHunt::ready($y,$time)===null,'Reservation locks recovery');
$y['krallenHunt']['battle']=0;KrallenHunt::accrue($y,$time+5*$week+10100);expect($y['krallenHunt']['hp']>5000,'Independent health recovery');
award($y,$time+5*$week,499999);expect($y['krallenHunt']['power']===1,'Retained target just below');
award($y,$time+6*$week,500000);expect($y['krallenHunt']['power']===2,'Retained target inclusive');
KrallenHunt::accrue($y,$time+10*$week);expect($y['krallenHunt']['wins']===5&&$y['krallenHunt']['power']===1,'Permanent guardian survives empty weeks');
$z=base();award($z,$time,750000);KrallenHunt::accrue($z,$time+3*$week);expect(!$z['krallenHunt']['wins']&&!$z['krallenHunt']['power'],'Missed week ends loan');
$z=base();KrallenHunt::credit($z,['goo'=>250000],$time);KrallenHunt::accrue($z,$time+3*$week);expect(!$z['krallenHunt']['wins'],'Winning week plus offline missed week');
$z=base();award($z,$time,750000);$now=$time+$week;
$s=['id'=>8,'status'=>'active','elapsed'=>0,'eventSeq'=>0,'events'=>[],'units'=>[],'krallen'=>KrallenHunt::ready($z,$now),'buildings'=>[['id'=>1,'type'=>'storage','x'=>16,'y'=>16,'hp'=>100,'max_hp'=>100],['id'=>2,'type'=>'twig','x'=>20,'y'=>20,'max_hp'=>50,'hp'=>50]]];
try{KrallenHunt::deploy($s,'attacker',16,16);throw new LogicException('Expected placement rejection');}catch(RuntimeException){expect(empty($s['units']),'Invalid deployment is atomic');}
KrallenHunt::deploy($s,'attacker',5,5);$unit=$s['units'][0];expect($unit['type']==='krallen'&&$unit['krallenPower']===2&&$unit['champion'],'Independent combat snapshot');
$store=$s['buildings'][0];KrallenHunt::damage($s,$unit,$store,50);expect(abs(KrallenHunt::multiplier($s,'goo')-2)<1e-8,'Silo 3x on actual damage share');
$friend=['id'=>'a1','x'=>6,'y'=>5,'team'=>'attacker'];KrallenHunt::damage($s,$friend,$store,10);
expect(abs(KrallenHunt::multiplier($s,'goo')-2.02)<1e-8,'Nearby ally aura');
$friend['x']=30;KrallenHunt::damage($s,$friend,$store,10);expect(abs(KrallenHunt::multiplier($s,'goo')-2.02)<1e-8,'Distant ally receives no aura');
KrallenHunt::damage($s,$unit,$s['buildings'][1],50);expect(abs(KrallenHunt::multiplier($s,'twig')-(1+152/150))<1e-8,'Resource type and silo share denominator');
$s['units'][0]['hp']=0;KrallenHunt::damage($s,$friend,$store,50);expect($s['_krallenDamage']['storage']==102,'Defeated creature stops boosting');
expect(KrallenHunt::multiplier([],'goo')===1.0,'Legacy battles earn standard loot');
foreach([['twig',0,0],['sniper',10,1],['bunker',0,2],['townhall',0,3],['wall',0,4]] as [$type,$damage,$rank]){
    expect(KrallenHunt::targetGroup(['type'=>$type,'_damage'=>$damage,'id'=>3],['3'=>['fink']])===$rank,'Resource-first target order');
}
expect(KrallenHunt::targetGroup(['type'=>'bunker','id'=>5],[])===3,'Empty bunkers have no defense priority');
$s['status']='finished';$z['krallenHunt']['battle']=8;KrallenHunt::release($z,$s,'krallen',$now);expect($z['krallenHunt']['battle']===0&&$z['krallenHunt']['hp']==0&&$s['result']['krallen']['deployed'],'Release on retreat and persist injury');
echo "Hunt for Krallen rules: $checks checks passed.\n";
