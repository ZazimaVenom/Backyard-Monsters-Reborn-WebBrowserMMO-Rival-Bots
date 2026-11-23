<?php
declare(strict_types=1);
require_once __DIR__.'/../app/WorldActivity.php';
$checks = 0;
function check(bool $ok, string $label): void { global $checks; ++$checks; if (!$ok) { throw new RuntimeException('FAIL: '.$label); } }
function lease(array &$state, string $key, int $user, int $expires): void {
    $state['leases'][hash('sha256', $key)] = ['user_id'=>$user, 'session'=>hash('sha256', 'session-'.$user), 'expires_at'=>$expires];
}
function clockAt(int $paused, int $epoch=1000): array { return ['epoch'=>$epoch,'pausedSeconds'=>$paused]; }

$state = WorldActivity::project(null, 1000);
check($state['active_seconds'] === 0 && $state['leases'] === [], 'a new world begins asleep');
$state = WorldActivity::project($state, 1100);
check($state['active_seconds'] === 0 && $state['observed_at'] === 1100, 'an empty world earns no active time');
lease($state, 'tab-a', 1, 1145);
$state = WorldActivity::project($state, 1120);
check($state['active_seconds'] === 20 && count($state['leases']) === 1, 'one connected player advances the shared clock');
lease($state, 'tab-b', 1, 1165);
$state = WorldActivity::project($state, 1146);
check($state['active_seconds'] === 46 && count($state['leases']) === 1, 'second tab keeps one player online after first lease expires');
lease($state, 'tab-c', 2, 1191);
$state = WorldActivity::project($state, 1170);
check($state['active_seconds'] === 70 && count($state['leases']) === 1, 'another player keeps time running independently');
$state = WorldActivity::project($state, 100000);
check($state['active_seconds'] === 91 && $state['leases'] === [], 'a long absence stops exactly at the last lease expiry');
check(WorldActivity::project($state, 100000) === $state, 'repeat projection never counts an interval twice');
check(WorldActivity::project($state, 99000) === $state, 'a backward system-clock adjustment never rewinds time');
lease($state, 'returning-tab', 3, 100045);
$state = WorldActivity::project($state, 100005);
check($state['active_seconds'] === 96, 'returning player resumes without offline catch-up');
$state['leases'] = [];
$state = WorldActivity::project($state, 100200);
check($state['active_seconds'] === 96, 'explicit last-tab departure pauses immediately');

$malformed = WorldActivity::project(null, 2000);
$malformed['leases']['bad-key'] = ['user_id'=>1, 'session'=>hash('sha256','a'), 'expires_at'=>999999];
$malformed['leases'][hash('sha256','b')] = ['user_id'=>0, 'session'=>hash('sha256','b'), 'expires_at'=>999999];
$malformed['leases'][hash('sha256','c')] = ['user_id'=>2, 'session'=>'raw-secret-session', 'expires_at'=>999999];
check(WorldActivity::project($malformed, 2010)['active_seconds'] === 0, 'invalid stored leases cannot keep the world active');
lease($malformed,'valid',2,999999);
check(WorldActivity::project($malformed,999999)['active_seconds']===45,'a corrupt far-future lease is capped to one real TTL');

// Resource-producing time, paid deadlines, hunger and cooldowns all exclude the
// same inactive interval. Amounts, injuries and relative durations never change.
$base = [
    'last_tick'=>1000,'shield_until'=>1300,'resources'=>['twig'=>123.75],'army'=>['pokey'=>7],
    'buildings'=>[['id'=>8,'type'=>'sniper','level'=>2,'hp'=>31,'ready_at'=>1200,'fortify_ready_at'=>1400,'fortify_paid'=>['twig'=>90]],
        ['id'=>9,'type'=>'wall','hp'=>0,'ready_at'=>0,'fortify_ready_at'=>null]],
    'queue'=>[['id'=>3,'type'=>'pokey','started_at'=>1010,'ready_at'=>1020,'duration'=>10,'paid_goo'=>50]],
    'researchQueue'=>[['type'=>'fink','ready_at'=>1500]],'unlockQueue'=>[['type'=>'brain','ready_at'=>1600]],
    'lab'=>['levels'=>['fink'=>2],'queue'=>['type'=>'dave','started_at'=>1005,'ready_at'=>1800,'paid_putty'=>3000]],
    'champions'=>['gorgo'=>['next_feed_at'=>1700,'last_heal_at'=>1000,'last_hunger_at'=>1700,'hp'=>12,'frozen'=>false,'battle_id'=>5,'paused_feed_seconds'=>42],
        'fomor'=>['next_feed_at'=>1900,'last_heal_at'=>1000,'last_hunger_at'=>1900,'hp'=>0,'frozen'=>true,'paused_feed_seconds'=>321]],
    'world'=>['resourcePerSecond'=>1.25,'capacity'=>8000,'pending'=>[['ready_at'=>1800,'capacity'=>16000]]],
    'ai'=>['last_attack_at'=>900,'last_layout_at'=>950,'last_turn_at'=>995,'turns'=>3],
    'planner'=>['slots'=>[1=>['name'=>'Keep','savedAt'=>1000,'positions'=>[['id'=>8,'type'=>'sniper','x'=>3,'y'=>4]]]]],
    'custom'=>['created_at'=>123,'ready_at'=>456],
];
$original = $base;
check(WorldActivity::shiftBase($base,['epoch'=>0,'pausedSeconds'=>99999])===0 && $base===$original,'uninitialized presence leaves legacy records byte-equivalent');
check(WorldActivity::shiftBase($base,clockAt(100))===100,'legacy record consumes only the cumulative inactive interval');
check($base['last_tick']===1100 && $base['shield_until']===1400,'production cursor and protection shift together');
check($base['buildings'][0]['ready_at']===1300 && $base['buildings'][0]['fortify_ready_at']===1500,'construction and fortification keep their remaining time');
check($base['buildings'][1]['ready_at']===0 && $base['buildings'][1]['fortify_ready_at']===null,'inactive timer sentinels remain zero or null');
check($base['queue'][0]['started_at']===1110 && $base['queue'][0]['ready_at']===1120 && $base['queue'][0]['duration']===10,'queued hatching moves both absolute clocks but not its duration');
check($base['researchQueue'][0]['ready_at']===1600 && $base['unlockQueue'][0]['ready_at']===1700,'research and unlock deadlines are paused');
check($base['lab']['queue']['started_at']===1105 && $base['lab']['queue']['ready_at']===1900,'Lab start and finish clocks are paused');
foreach (['gorgo','fomor'] as $type) {
    foreach (['next_feed_at','last_heal_at','last_hunger_at'] as $key) { check($base['champions'][$type][$key]===$original['champions'][$type][$key]+100,'champion '.$type.' pauses '.$key); }
    foreach (['hp','frozen','paused_feed_seconds'] as $key) { check($base['champions'][$type][$key]===$original['champions'][$type][$key],'champion '.$type.' preserves '.$key); }
}
check($base['world']['pending'][0]['ready_at']===1900,'pending territory income changes keep their remaining time');
check($base['ai']['last_attack_at']===1000 && $base['ai']['last_layout_at']===1050,'bot raid and layout cooldowns exclude inactive time');
check($base['ai']['last_turn_at']===995 && $base['ai']['turns']===3,'historical last-turn time and turn count stay unchanged');
foreach (['resources','army','planner','custom'] as $key) { check($base[$key]===$original[$key],'clock sync never mutates '.$key); }
check($base['buildings'][0]['hp']===31 && $base['buildings'][0]['fortify_paid']===['twig'=>90],'clock sync preserves injuries and paid prices');
$once=$base;
check(WorldActivity::shiftBase($base,clockAt(100))===0 && $base===$once,'repeated base reads cannot shift a timer twice');
check(WorldActivity::shiftBase($base,clockAt(105))===5 && $base['last_tick']===1105,'later read applies only the new five paused seconds');
$oldEpoch=$base;
check(WorldActivity::shiftBase($base,clockAt(1000,2000))===0 && $base['last_tick']===$oldEpoch['last_tick'],'a replacement clock epoch reanchors without duplicating old pause time');
$new=['ready_at'=>3000];WorldActivity::stamp($new,clockAt(800));
check(WorldActivity::shiftSite($new,clockAt(805))===5 && $new['ready_at']===3005,'new bot-owned sites receive only future paused time');

$site=['last_tick'=>1100,'ready_at'=>1400,'repair_at'=>1450,'shield_until'=>1600,'upgrade_to'=>3,
    'garrison'=>['pokey'=>3],'buildings'=>[['id'=>1,'hp'=>24,'ready_at'=>0,'fortify_ready_at'=>1500]],'custom'=>'preserve'];
check(WorldActivity::shiftSite($site,clockAt(50))===50,'legacy bot territory consumes its paused interval');
foreach (['last_tick'=>1150,'ready_at'=>1450,'repair_at'=>1500,'shield_until'=>1650] as $key=>$expected) { check($site[$key]===$expected,'territory clock '.$key.' stays paused'); }
check($site['upgrade_to']===3 && $site['garrison']===['pokey'=>3] && $site['buildings'][0]['hp']===24 && $site['custom']==='preserve','site clock sync preserves ownership-independent state');
check($site['buildings'][0]['fortify_ready_at']===1550 && $site['buildings'][0]['ready_at']===0,'site buildings pause only active absolute deadlines');
$once=$site;check(WorldActivity::shiftSite($site,clockAt(50))===0&&$site===$once,'site synchronization is idempotent');

// Repeated connected/disconnected intervals must partition wall time exactly.
$state=WorldActivity::project(null,1000);$expected=0;
for($i=0;$i<100;$i++){
    $start=1000+$i*100;$state=WorldActivity::project($state,$start);lease($state,'property-'.$i,$i%3+1,$start+45);
    $state=WorldActivity::project($state,$start+100);$expected+=45;
    check($state['active_seconds']===$expected&&$state['observed_at']-$state['epoch']-$state['active_seconds']===($i+1)*55,'online/offline partition cycle '.$i);
}
echo "World activity pure: $checks assertions passed.\n";
