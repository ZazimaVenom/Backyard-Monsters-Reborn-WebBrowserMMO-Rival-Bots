<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
set_error_handler(static function(int $severity,string $message): never { throw new ErrorException($message,0,$severity); });
$checks=0;
function assertWave(bool $condition,string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
$owner=['id'=>7,'name'=>'Event Keeper'];
$yard=['buildings'=>[['id'=>1,'type'=>'townhall','level'=>5,'hp'=>10000000,'max_hp'=>10000000,'ready_at'=>0,'x'=>16,'y'=>16]],
    'army'=>[], 'research'=>[], 'garrison'=>[], 'champions'=>[], 'activeChampion'=>null, 'lab'=>['levels'=>[]]];
$waves=BrukkargDefense::waves();
assertWave(count($waves)===25 && array_sum(array_map('count',$waves))===150,'All source waves and timed groups');
$totals=array_map(static fn(array $wave): int=>array_sum(array_column($wave,2)),$waves);
assertWave(array_sum($totals)===2610 && $totals[0]===100,'All original group quantities');
assertWave($waves[1]===[[0,'spurtz',20,'t'],[3,'eyera',10,'t'],[3,'eyera',10,'b']],'Source delays and opposed directions');
$now=1000;
for ($stage=1;$stage<=25;++$stage) {
    $state=BrukkargDefense::start($yard,$owner,$stage,$now,0,[]);
    assertWave(count($state['brukkargWave']['groups'])===count($waves[$stage-1]) && array_sum($state['remaining'])===$totals[$stage-1],'Pending source groups');
    $saved=$state;Baiter::advance($state,$now+2);assertWave($state===$saved,'No launch during countdown');
    $until=max(array_column($waves[$stage-1],0));
    for ($sec=0;$sec<=$until;++$sec) {
        Baiter::advance($state,$now+3+$sec);
        $expected=0;
        foreach ($waves[$stage-1] as $g) if($g[0]<=$sec) $expected+=$g[2];
        assertWave($state['unitSeq']===$expected,'Wave '.$stage.' spawn count at second '.$sec);
        // Retire each arriving batch to test the empty-field waits independently
        // from tower strength. The next advance still uses the real combat engine.
        foreach($state['units'] as &$unit){
            assertWave($unit['level']===6,'Monster evolution from the source event');
            if (isset(Catalog::labAbilities()[$unit['type']])) assertWave($unit['labLevel']===3,'Original combat power');
            if ($unit['type']==='krallen') assertWave($unit['champion'] && $unit['max_hp']===62000.0,'Event-only Krallen');
            $unit['hp']=0;$unit['state']='dead';
        }unset($unit);
    }
    Baiter::advance($state,$now+4+$until);
    assertWave($state['status']==='finished' && $state['result']['outcome']==='held','Defender holds with yard intact');
    $view=Baiter::snapshot($state,$now+4+$until);
    assertWave($view['brukkargStage']===$stage && count($view['entries'])===count(array_unique(array_column($waves[$stage-1],3))),'All approach markers persist');
    assertWave(!isset($view['blitzStage'],$view['brukkargWave']),'Independent event state and private schedules');
    MonsterBlitz::settle($yard,$state);
    assertWave($yard['brukkargWar']['waves']===$stage && !isset($yard['blitzCleared']),'Exactly one defense point');
    if ($stage===24) assertWave(Brukkarg::score($yard)===4024 && isset($yard['brukkargWar']['mirror']),'Mirror unlocks at real threshold');
    if ($stage%5===0) {
        assertWave(Brukkarg::nextWave($yard)===null,'Pause after five waves for offense');
        $yard['brukkargWar']['cleared'][900+intdiv($stage,5)]=['best'=>100];
    }
}
assertWave(Brukkarg::score($yard)===5025 && Brukkarg::nextWave($yard)===null,'Full event sequence');
assertWave(!isset(Catalog::champions()['krallen'],Catalog::monsters()['krallen']),'Event guardian stays out of player production');
echo "Brukkarg defense: $checks assertions passed across 25 waves and 2,610 actual event monsters.\n";
