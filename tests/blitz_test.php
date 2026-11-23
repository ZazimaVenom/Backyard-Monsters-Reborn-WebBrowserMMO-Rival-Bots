<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
set_error_handler(static function(int $severity, string $message): never { throw new ErrorException($message,0,$severity); });
$checks = 0;
function verifyBlitz(bool $value, string $label): void {
    global $checks;
    if (!$value) { throw new RuntimeException($label); }
    ++$checks;
}
$now = time();
$base = ['buildings'=>[['id'=>1,'type'=>'townhall','level'=>3,'hp'=>4000,'max_hp'=>4000,'ready_at'=>0,'x'=>16,'y'=>16]],
    'army'=>[], 'research'=>[], 'garrison'=>[], 'champions'=>[], 'activeChampion'=>null, 'lab'=>['levels'=>[]]];
$owner = ['id'=>3,'name'=>'Blitz test'];
$spec = Catalog::buildings()['cannon'];
foreach ([[5,5],[7,9],[9,7],[12,8],[7,12],[10,11]] as $i=>[$x,$y]) {
    $base['buildings'][]=['id'=>80+$i,'type'=>'cannon','x'=>$x,'y'=>$y,'level'=>5,'hp'=>5000,'max_hp'=>5000,'ready_at'=>0];
}
$base['unlocked'] = [];
verifyBlitz(MonsterBlitz::view($base,$now)['eligible'],'works without a Baiter');
$rosters = MonsterBlitz::view($base,$now)['rosters'];
verifyBlitz(array_map('array_sum',$rosters) === [8,15,15,35,8,14,60,48,40,8], 'original quantities for ten waves');
foreach ($rosters as $i=>$roster) {
    $stage = $i+1;
    $state = MonsterBlitz::start($base,$owner,$stage,$now,0,[]);
    verifyBlitz($state['wave']===$roster && $state['direction']==='t','server selects northern roster');
    $copy = $state;
    Baiter::advance($state,$now+2);
    verifyBlitz($state===$copy,'three second arrival signal');
    Baiter::advance($state,$now+3);
    verifyBlitz($state['launched'] && count($state['units'])>0,'first batch reaches yard');
    $initial = $state;
    $elapsed = 3;
    while ($state['status']==='active' && $elapsed < 350) {
        Baiter::advance($state,$now + ++$elapsed);
    }
    verifyBlitz($state['status']==='finished','complete battle before time limit');
    $view = Baiter::snapshot($state,$now+$elapsed);
    verifyBlitz($view['blitzStage']===$stage && $view['result']['rankDelta']===['attacker'=>0,'defender'=>0], 'player standings unaffected');
    verifyBlitz($state['result']['outcome']==='held','fixture defenses held wave '.$stage);
    if ($stage===7) {
        verifyBlitz($initial['remaining']['bolt']===50,'50 Bolt delayed at launch');
        Baiter::advance($initial,$now+5);Baiter::advance($initial,$now+7);
        verifyBlitz($initial['remaining']['bolt']===50,'second batch stays pending until fifth combat second');
        Baiter::advance($initial,$now+8);
        verifyBlitz($initial['remaining']['bolt']===0,'all 50 arrive five seconds after first batch');
    }
    MonsterBlitz::settle($base,$state);
    verifyBlitz($base['blitzCleared']===$stage,'permanent clear');
    foreach ($base['buildings'] as &$b) { $b['hp']=$b['max_hp']; } unset($b);
}
verifyBlitz($base['unlocked']===['slimeattikus'] && $base['research']['slimeattikus']===1,'all ten victories award one real hatchery unlock');
verifyBlitz(!MonsterBlitz::view($base,$now)['eligible'],'completed event locked');
$base['blitzCleared']=4;$base['blitzDeadline']=$now-1;
verifyBlitz(MonsterBlitz::view($base,$now)['restart'],'expired run may restart at one');
try { MonsterBlitz::start($base,$owner,5,$now,0,[]);throw new LogicException('accepted wrong stage'); } catch (RuntimeException) {++$checks;}
echo "Monster Blitzkrieg: $checks assertions passed.\n";
