<?php
declare(strict_types=1);
require_once __DIR__.'/../app/BattleEngine.php';
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
    ++$checks;
    echo "PASS $message\n";
}
function building(int $id, string $type, float $x, float $y, float $hp = 1000, float $size = 2, float $damage = 0, float $range = 7): array {
    return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>1,'hp'=>$hp,'max_hp'=>$hp,'_size'=>$size,'_damage'=>$damage,'_range'=>$range,'_interval'=>1.0,'_cooldown'=>0.0,'_ready'=>true];
}
function unit(string $id, float $x, float $y, float $hp = 1000, float $damage = 100, string $target = 'any'): array {
    return ['id'=>$id,'type'=>'pokey','x'=>$x,'y'=>$y,'hp'=>$hp,'max_hp'=>$hp,'state'=>'walk','targetId'=>null,'_damage'=>$damage,'_speed'=>2.0,'_range'=>0.7,'_interval'=>1.0,'_target'=>$target,'_cooldown'=>0.0,'_repath'=>0.0,'_path'=>[],'_obstacleVersion'=>-1];
}
function state(array $buildings, array $units = []): array {
    return ['id'=>1,'status'=>'active','elapsed'=>0.0,'duration'=>300,'buildings'=>$buildings,'units'=>$units,'events'=>[],'eventSeq'=>0,'obstacleVersion'=>0,'remaining'=>['pokey'=>0],'attacker'=>['id'=>1],'defender'=>['id'=>2],'result'=>null];
}
$s = state([building(1,'townhall',16,16,1000,3.4)]);
check(!BattleEngine::legalDeployment($s,16,16), 'deployment cannot overlap a building');
check(!BattleEngine::legalDeployment($s,18.8,16), 'deployment respects exclusion perimeter');
check(BattleEngine::legalDeployment($s,1,1), 'clear ground deployment accepted');
check(!BattleEngine::legalDeployment($s,NAN,1) && !BattleEngine::legalDeployment($s,33,1), 'nonfinite and out-of-yard coordinates rejected');

$s = state([building(1,'townhall',16,16,1000)], [unit('u1',10,16,1000,250)]);
BattleEngine::advance($s,30);
check($s['status']==='finished' && $s['result']['attackerWon'], 'a monster walks to and destroys a town hall');
check($s['result']['rankDelta']===['attacker'=>25,'defender'=>-15], 'win and loss deltas are signed and explicit');
check(count(array_filter($s['events'],fn($e)=>$e['type']==='destroy'))===1, 'destruction event emitted once');
$finished=$s;
BattleEngine::advance($s,300);
check($s===$finished, 'finished simulation is idempotent');

$s = state([building(1,'sniper',16,16,10000,2,300,12),building(2,'townhall',24,24,10000)], [unit('u1',10,16,200,10)]);
BattleEngine::advance($s,3);
check($s['units'][0]['hp']===0.0 && $s['status']==='finished' && !$s['result']['attackerWon'], 'a defense fires and defeats an exposed army');
check(count(array_filter($s['events'],fn($e)=>$e['type']==='shot' && $e['kind']==='sniper'))>=1, 'tower projectile events feed browser animation');

$s = state([building(1,'cannon',16,16,10000,2,100,12),building(2,'townhall',24,24,10000)], [unit('u1',10,16),unit('u2',10.4,16.1)]);
BattleEngine::advance($s,0.25);
check($s['units'][0]['hp']<1000 && $s['units'][1]['hp']<1000, 'cannon splash damages clustered invaders');

$base = state([building(1,'townhall',16,16,10000),building(2,'wall',9,16,1800,0.8)], [unit('u1',4,16)]);
$a=$base;$b=$base;
BattleEngine::advance($a,20);
for($i=1;$i<=80;$i++){BattleEngine::advance($b,$i*0.25);}
check($a===$b, 'simulation is deterministic across polling cadence');

// An impassable wall ring forces the monster to attack a wall before the heart of the base.
$walls=[building(1,'townhall',16,16,1000)];$id=2;
for($i=12;$i<=20;$i++){
    $walls[]=building($id++,'wall',$i+0.5,12.5,150,1.0);
    $walls[]=building($id++,'wall',$i+0.5,20.5,150,1.0);
}
for($i=13;$i<20;$i++){
    $walls[]=building($id++,'wall',12.5,$i+0.5,150,1.0);
    $walls[]=building($id++,'wall',20.5,$i+0.5,150,1.0);
}
$s=state($walls,[unit('u1',6,16,10000,300)]);
BattleEngine::advance($s,50);
$destroyed=array_values(array_filter($s['events'],fn($e)=>$e['type']==='destroy'));
check(count($destroyed)>0 && $destroyed[0]['kind']==='wall', 'sealed enclosure is breached instead of walking through walls');
check($s['result']['attackerWon']??false, 'wall breach allows the invasion to reach the town hall');

$s=state([building(1,'townhall',16,16,10000)]);
$s['remaining']=['pokey'=>10];
BattleEngine::advance($s,50000);
check($s['elapsed']===300.0 && $s['result']['reason']==='time_limit', 'battle deadline enforced despite undeployed reserves');
$s=state([building(1,'townhall',16,16,10000)]);
BattleEngine::finish($s,'retreat');
check(!$s['result']['attackerWon'] && $s['result']['rankDelta']['attacker']===-15, 'ending an invasion with no destruction remains an attacker loss');
// End-invasion must use exactly the same earned-victory thresholds as the timer.
$targets=[building(1,'townhall',24,24,10000),building(2,'storage',10,10,1000),building(3,'goo',15,10,1000),building(4,'twig',20,10,1000)];
$targets[1]['hp']=0;$targets[2]['hp']=0;$targets[3]['hp']=0;
$s=state($targets);BattleEngine::finish($s,'retreat');
check($s['result']['attackerWon'] && $s['result']['destruction']===75.0 && $s['result']['rankDelta']['attacker']===25, 'ending after 75 percent destruction preserves earned victory');
$targets[3]['hp']=1000;$s=state($targets);BattleEngine::finish($s,'retreat');
check($s['result']['attackerWon'] && $s['result']['destruction']===50.0, 'ending at exactly 50 percent destruction wins');
$targets[2]['hp']=1000;$s=state($targets);BattleEngine::finish($s,'retreat');
check(!$s['result']['attackerWon'] && $s['result']['destruction']===25.0, 'ending below the destruction threshold remains a loss');
$targets[0]['hp']=0;$targets[1]['hp']=1000;$s=state($targets);BattleEngine::finish($s,'retreat');
check($s['result']['attackerWon'] && $s['result']['destruction']===25.0, 'ending after Town Hall destruction wins below 50 percent');
$ruin=building(1,'townhall',16,16,1000);$ruin['hp']=0;$ruin['_initial_hp']=0;
$s=state([$ruin,building(2,'storage',24,24,1000)]);$s['remaining']=['pokey'=>10];
BattleEngine::advance($s,300);
check(!$s['result']['attackerWon'] && $s['result']['destruction']===0.0, 'old ruins cannot award a zero-damage victory');
$old=building(1,'hatchery',16,16,1800);$old['_initial_hp']=1800;
$upgraded=$old;$upgraded['level']=2;$upgraded['max_hp']=2430;$upgraded['hp']=2430;
check(BattleEngine::settledHealth($old,$upgraded)===2430.0,'an untouched upgrade completed during battle retains full new health');
$old['hp']=1200;
check(BattleEngine::settledHealth($old,$upgraded)===1830.0,'a completed upgrade carries only actual invasion damage into its new health');
$old['hp']=0;
check(BattleEngine::settledHealth($old,$upgraded)===0.0,'a destroyed building stays destroyed even when its upgrade completes');
$old['_initial_hp']=0;
check(BattleEngine::settledHealth($old,$upgraded)===2430.0,'previously unbuilt construction is not destroyed by an old zero-health snapshot');
$old['_initial_hp']=1800;$old['hp']=1200;$current=$old;
check(BattleEngine::settledHealth($old,$current)===1200.0,'ordinary buildings preserve exact combat health without an upgrade');
echo "Combat engine: $checks checks passed.\n";
