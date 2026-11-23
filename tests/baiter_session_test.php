<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
set_error_handler(static fn(int $n,string $m,string $f,int $l) => throw new ErrorException($m,0,$n,$f,$l));

$checks=0;
function checkSession(bool $ok,string $label):void {
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    ++$checks;
}
$yard=['buildings'=>[], 'army'=>['pokey'=>2], 'garrison'=>[], 'champions'=>[], 'activeChampion'=>null];
foreach ([['townhall',16,16],['baiter',10,15],['sniper',12,10],['trap',6,14]] as $i=>[$type,$x,$y]) {
    $hp=Game::buildingStats($type,1)['max_hp'];
    $yard['buildings'][]=['id'=>$i+1,'type'=>$type,'x'=>$x,'y'=>$y,'hp'=>$hp,'max_hp'=>$hp,'level'=>1,'ready_at'=>0];
}
$before=$yard;
$keeper=['id'=>7,'name'=>'Test Keeper'];
$session=null;
$call=static function(string $action,array $input,int $now=1000) use (&$session,$yard,$keeper):array {
    return BaiterService::apply($session,$action,$input,$yard,$keeper,$now);
};
$reject=static function(string $action,array $input) use($call,&$session):void {
    $before=$session;
    try { $call($action,$input); }
    catch(RuntimeException $e) { checkSession($before===$session,'Rejected '.$action.' mutated the session');return; }
    throw new RuntimeException('Accepted invalid '.$action);
};
$open=['requestId'=>str_repeat('a',16),'previousId'=>null,'wave'=>['pokey'=>3],'direction'=>'tr'];
$reject('baiterPoll',['id'=>'someone-else']);
$reject('baiterStart',array_diff_key($open,['previousId'=>1]));
$reject('baiterStart',['requestId'=>[]]+$open);
$reject('baiterStart',['wave'=>['fake'=>100]]+$open);
$one=$call('baiterStart',$open);
$id=$one['session']['id'];$key=['id'=>$id];
checkSession($one['session']===['id'=>$id,'status'=>'active','revision'=>1],'New session summary');
checkSession($one['battle']['defender']['id']===7&&$one['battle']['training']===true,'Private combat snapshot');
checkSession($call('baiterStart',$open)===$one,'Immediate retry duplicated a start');
checkSession($call('baiterPoll',$key)===$one,'Poll advanced a countdown early');
$reject('baiterStart',['requestId'=>str_repeat('b',16),'previousId'=>$id]+$open);
$reject('baiterSiren',['id'=>str_repeat('0',32)]);
$launched=$call('baiterPoll',$key,1004);
checkSession($launched['session']['revision']>1 && $launched['battle']['elapsed']>0,'A valid poll advances the frozen wave');
checkSession($call('baiterPoll',$key,1004)===$launched,'Same-clock polling advanced the wave twice');
$oneEnd=$call('baiterSiren',$key,1005);
checkSession($oneEnd['battle']['result']['outcome']==='dismissed','Siren stops current wave');
checkSession($call('baiterSiren',$key,1005)===$oneEnd,'Siren retry changed a result');
checkSession($call('baiterStart',$open,1005)===$oneEnd,'A delayed start relaunched a completed wave');
$two=$call('baiterStart',['requestId'=>str_repeat('b',16),'previousId'=>$id]+$open,1007);
checkSession($two['session']['id']!==$id && $two['session']['revision']===1,'New wave failed to replace completed wave');
$reject('baiterStart',$open);
$reject('baiterPoll',$key);
$reject('baiterSiren',$key);
checkSession($yard===$before,'Practice must not change real army, buildings or health');
$spec=Catalog::buildings()['baiter'];
checkSession($spec['maxLevel']===7 && $spec['limitByTownHall'][4]===1,'Source Baiter maximums');
foreach ([1=>[250,150,60,1000,4,1],2=>[10000,5000,120,1500,4,2],3=>[20000,10000,240,2250,4,3],4=>[40000,20000,480,3375,5,4],5=>[60000,40000,960,5000,5,4],6=>[100000,60000,1920,7500,5,4],7=>[160000,100000,3840,12000,5,4]] as $level=>[$both,$putty,$seconds,$health,$hall,$locker]) {
    $row=$spec['levels'][$level];
    checkSession(Game::upgradeCost('baiter',$level-1)===['twig'=>$both,'pebble'=>$both,'putty'=>$putty,'goo'=>0],"Level $level price");
    checkSession($row['buildTime']===$seconds && Game::buildingStats('baiter',$level)['max_hp']===$health,"Level $level time and health");
    checkSession($row['townHall']===$hall && $row['locker']===$locker,"Level $level construction requirements");
}
echo "Baiter session and progression: $checks checks passed.\n";
