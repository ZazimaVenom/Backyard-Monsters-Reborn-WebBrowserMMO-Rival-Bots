<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never { throw new ErrorException($message,0,$severity); });
$n=0;
function ec(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException($label);++$n;}
function refused(callable $f):void {try{$f();}catch(RuntimeException){ec(true,'rejected');return;}throw new LogicException('Expected rejection');}
foreach ([1=>115,140,165,175,175] as $stage=>$total) {
    ec(array_sum(array_column(InfernoEmergence::groups($stage,100),1))===$total,'maximum source wave '.$stage);
}
ec(InfernoEmergence::groups(1,0)===[['spurtz',1,400],['spurtz',1,400]],'two independent low-level Spurtz groups');
ec(array_column(InfernoEmergence::groups(1,30),1)===[7,7,12,3],'source floor and ceil');
ec(InfernoEmergence::power(1000000)===.4 && InfernoEmergence::power(1000001)===.5 && InfernoEmergence::power(50000001)===.9,'source XP boundaries');
$now=time();$b=['buildings'=>[],'garrison'=>[],'army'=>[],'research'=>[],'champions'=>[],'activeChampion'=>null,'lab'=>['levels'=>[]]];
ec(!InfernoEmergence::view($b,$now)['ready'],'unupgraded keeper');
$b['buildings']=[['type'=>'townhall','id'=>1,'level'=>5,'x'=>16,'y'=>16,'hp'=>10,'max_hp'=>100,'ready_at'=>0]];
ec(!InfernoEmergence::view($b,$now)['ready'],'ten percent exactly');
$b['buildings'][0]['hp']=11;
for ($stage=1;$stage<=5;$stage++) {
    ec(InfernoEmergence::view($b,$now)['ready'],'ready at twelve-hour boundary');
    $previous=['previousStage'=>$stage-1];
    InfernoEmergence::raisePortal($b,$previous,$now);
    $raised=$b;InfernoEmergence::raisePortal($b,$previous,$now+1);ec($b===$raised,'identical quake retry');
    $s=InfernoEmergence::start($b,['id'=>1,'name'=>'Test'],['stage'=>$stage],$now,0,[]);
    ec($s['target']['kind']==='emergence' && !$s['launched'],'authentic countdown');
    InfernoEmergence::advance($s,$now+2);ec(count($s['units'])===0,'quiet until launch');
    InfernoEmergence::advance($s,$now+3);
    $units=array_values(array_filter($s['units'],fn($u)=>$u['team']==='attacker'));
    ec(count($units)===array_sum($s['wave']) && $s['launched'],'every group arrives together');
    foreach($units as $u){ec($u['x']<5 && $u['y']>5 && $u['y']<25 && $u['hp']>0,'western spread');}
    $s['result']=['outcome'=>'overrun'];InfernoEmergence::settle($b,$s,$now);
    $finished=$b;InfernoEmergence::settle($b,$s,$now);ec($b===$finished,'once-only settlement');
    if ($stage<5) {
        ec(!InfernoEmergence::view($b,$now+43199)['ready'],'real intermission');
        refused(fn()=>InfernoEmergence::raisePortal($b,['previousStage'=>$stage],$now));
    } else { ec(InfernoEmergence::freePassage($b) && InfernoEmergence::view($b,$now)['complete'],'fifth portal opens'); }
    $now+=43200;
}
echo "Inferno Emergence: $n checks passed.\n";
