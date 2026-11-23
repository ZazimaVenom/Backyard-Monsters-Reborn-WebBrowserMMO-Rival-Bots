<?php
declare(strict_types=1);
/** Destructive only in an explicitly opted-in disposable *_bots_test database. */
if(PHP_SAPI!=='cli' || getenv('BYM_BOTS_TEST_RESET')!=='1'){fwrite(STDERR,"Set BYM_BOTS_TEST_RESET=1 and BYM_BOTS_TEST_* for a disposable *_bots_test database.\n");exit(2);}
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never {throw new ErrorException($message,0,$severity,$file,$line);});
$database=getenv('BYM_BOTS_TEST_DATABASE') ?: 'construction_bots_test';
if(!preg_match('/^[a-zA-Z0-9_]+_bots_test$/D',$database)){throw new RuntimeException('Disposable database must end in _bots_test.');}
$config=['host'=>getenv('BYM_BOTS_TEST_HOST') ?: '127.0.0.1','port'=>(int)(getenv('BYM_BOTS_TEST_PORT') ?: 3306),'database'=>$database,'username'=>getenv('BYM_BOTS_TEST_USER') ?: 'root','password'=>getenv('BYM_BOTS_TEST_PASSWORD') ?: '','game_speed'=>20,'bots'=>['human_target_percent'=>0,'budget_seconds'=>20,'max_active_battles'=>1]];
$admin=Db::connect($config,false);$admin->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4');
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');foreach(['alliance_war_battles','alliance_wars','alliance_stances','alliance_requests','alliance_members','alliances','keeper_mail','keeper_gifts','keeper_friends','reports','battles','world_sites','bases','users','settings','auth_attempts'] as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec(file_get_contents(dirname(__DIR__).'/app/schema.sql'));
$checks=0;
function checkWorkerBot(bool $truth,string $message):void {global $checks;++$checks;if(!$truth){throw new RuntimeException('FAIL: '.$message);}}
function workerBotStructure(int $id,string $type,float $x,float $y,int $level=1):array {
    $hp=Game::buildingStats($type,$level)['max_hp'];
    return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0,'fortification'=>0,'fortify_ready_at'=>0,'fortify_to'=>null,'fortify_paid'=>null];
}
function workerBotFixture():array {
    $now=time();return Game::normalizeBase(['buildings'=>[
        workerBotStructure(1,'townhall',16,16,5),workerBotStructure(2,'flinger',7,7,3),
        workerBotStructure(3,'planner',8,12),workerBotStructure(4,'storage',24,24,3),
        workerBotStructure(5,'housing',8,24,3),workerBotStructure(6,'hatchery',24,8,3),
        workerBotStructure(7,'sniper',4,28,2),workerBotStructure(8,'tesla',28,28,2),
        workerBotStructure(9,'cannon',28,4,2),workerBotStructure(10,'wall',4,20),
        workerBotStructure(11,'maproom',16,7,3),workerBotStructure(12,'bunker',16,25)],
        'resources'=>array_fill_keys(['twig','pebble','putty','goo'],0),'army'=>['pokey'=>80],
        'queue'=>[],'research'=>[],'garrison'=>['12'=>['pokey'=>10]],'last_tick'=>$now+86400,
        'workers'=>1,'next_building_id'=>13,'next_queue_id'=>1,'shield_until'=>0,
        'ai'=>['turns'=>0,'last_attack_at'=>$now]]);
}
function workerBotTurn(PDO $pdo,int $id):array {$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$id);return activityFixtureTick(1,20);}
Game::seedBots($pdo,1000);World::initialize($pdo);
$pdo->exec("INSERT INTO settings(name,value) VALUES('schema_version','2') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$pdo->exec('UPDATE users SET next_turn_at='.(time()+86400).' WHERE is_bot=1');
$identity=$pdo->query('SELECT id,name,is_bot,rank_points,wins,losses FROM users WHERE is_bot=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
checkWorkerBot(count($identity)===1000,'Exactly one thousand persistent bot identities remain');
$freeWorkers=0;$freePlanners=0;
foreach($pdo->query('SELECT state_json FROM bases') as $row){$b=json_decode($row['state_json'],true);$freeWorkers+=(int)($b['workers']!==1);$freePlanners+=count(array_filter($b['buildings'],static fn(array $building):bool=>$building['type']==='planner'));}
checkWorkerBot($freeWorkers===0 && $freePlanners===0,'Seeding grants one base worker and no free Planner or paid workers');

// A legacy overloaded yard retains three already-paid jobs, even with one worker.
$legacy=workerBotFixture();foreach([6,7,8] as $index){$legacy['buildings'][$index]['ready_at']=time()+3600;$legacy['buildings'][$index]['upgrade_to']=3;}
Game::saveBase(12,$legacy);$before=Game::loadBase(12);$turn=workerBotTurn($pdo,12);$after=Game::loadBase(12);
checkWorkerBot($turn['errors']===[] && $turn['built']===0 && $turn['upgraded']===0 && $turn['fortified']===0 && $turn['workers_hired']===0,'An unfunded overloaded bot waits without repeated invalid construction or free hires');
checkWorkerBot($after['buildings']===$before['buildings'],'Every old paid concurrent timer and building is preserved');
checkWorkerBot(Construction::status($after)['busy']===3 && Construction::status($after)['overCapacity'],'Bot can display grandfathered occupancy above its purchased worker count');

// A real scheduled hire can increase capacity but never erase excess legacy work.
$hiring=$legacy;$hiring['resources']=array_fill_keys(['twig','pebble','putty','goo'],1000000);
$profile=Bots::profile(20);$reserve=Bots::networkHomeReserve($hiring,$profile,time());$cost=Construction::status($hiring)['nextHire']['cost'];
foreach($cost as $resource=>$amount){$hiring['resources'][$resource]=$amount+max($reserve[$resource],$resource==='goo'?1000:0);}
Game::saveBase(20,$hiring);$before=Game::loadBase(20);$turn=workerBotTurn($pdo,20);$after=Game::loadBase(20);
checkWorkerBot($turn['errors']===[] && $turn['workers_hired']===1 && $after['workers']===2,'Scheduler buys a real second worker through the shared action');
foreach($cost as $resource=>$amount){checkWorkerBot($before['resources'][$resource]-$after['resources'][$resource]===$amount,'Worker costs exact '.$resource.' resources');}
checkWorkerBot($after['buildings']===$before['buildings'] && $after['army']===$before['army'],'A paid hire never completes old work or consumes reserved monsters');
checkWorkerBot($turn['built']===0 && $turn['upgraded']===0 && $turn['fortified']===0 && Construction::status($after)['busy']===3,'Increasing capacity below legacy occupancy still cannot overbook');
$again=workerBotTurn($pdo,20);checkWorkerBot($again['workers_hired']===0 && Game::loadBase(20)['workers']===2,'An unaffordable later hire cannot duplicate the first purchase');

// Shared home capacity does not freeze independent hatching or territory work.
$training=workerBotFixture();$training['buildings'][9]['ready_at']=time()+3600;$training['buildings'][9]['upgrade_to']=2;
$training['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$training['resources']['goo']=250;Game::saveBase(21,$training);
$turn=workerBotTurn($pdo,21);$after=Game::loadBase(21);
checkWorkerBot($turn['errors']===[] && $turn['trained']===1 && count($after['queue'])===1,'Busy construction workers leave normal Hatchery production active');
checkWorkerBot($after['resources']['goo']===0 && $after['queue'][0]['type']==='pokey','Independent hatching spends real goo and queues the monster');
checkWorkerBot($turn['built']===0 && $turn['upgraded']===0 && $turn['fortified']===0,'Training cannot silently acquire an extra construction slot');
$neutral=$pdo->query("SELECT id FROM world_sites WHERE kind='fortification' LIMIT 1")->fetchColumn();$site=World::load((int)$neutral);$site['owner_id']=21;$site['state']['garrison']=[];World::save($site);
$training=Game::loadBase(21);$training['army']['pokey']=11;Game::saveBase(21,$training);
$stats=World::siteStats('fortification',1);$stats['repairCost']=World::repairCost($site);$intent=Bots::siteManagementPlan($training,$site,$stats,Bots::profile(21),time());
checkWorkerBot($intent!==null && $intent['action']==='worldGarrison','Home workers do not block a separate owned-site garrison plan');
World::mutate(21,$intent['action'],$intent['input']);checkWorkerBot(Game::loadBase(21)['army']['pokey']===8,'Separate territorial action transfers only the three real surplus monsters');

// No free Yard Planner: the scheduler pays and occupies one normal worker.
$builder=workerBotFixture();$builder['buildings']=array_values(array_filter($builder['buildings'],static fn(array $b):bool=>$b['type']!=='planner'));
$builder['buildings'][0]['level']=3;$builder['buildings'][0]['max_hp']=Game::buildingStats('townhall',3)['max_hp'];$builder['buildings'][0]['hp']=$builder['buildings'][0]['max_hp'];
$lockerPoint=Bots::placements(22,'locker',0,$builder,Catalog::buildings()['locker'])[0];$builder['buildings'][]=workerBotStructure(13,'locker',$lockerPoint[0],$lockerPoint[1]);$builder['next_building_id']=14;
$builder['resources']=Catalog::buildings()['planner']['cost'];Game::saveBase(22,$builder);$turn=workerBotTurn($pdo,22);$after=Game::loadBase(22);
$planners=array_values(array_filter($after['buildings'],static fn(array $b):bool=>$b['type']==='planner'));
checkWorkerBot($turn['errors']===[] && $turn['built']===1 && count($planners)===1,'Town Hall three bot builds its own paid Yard Planner');
checkWorkerBot(array_sum($after['resources'])===0 && $planners[0]['level']===0 && $planners[0]['ready_at']>time(),'Planner purchase spends exact resources without granting immediate service');
checkWorkerBot(Construction::status($after)['busy']===1 && !Planner::status($after)['available'],'The ordinary worker and facility gates apply to bot Planner construction');

// A prepared bad layout gets one real, validated, persistent improvement.
$plannerId=23;$layout=workerBotFixture();$layout['buildings'][6]['hp']=1;$layout['buildings'][6]['fortification']=3;
$layout['buildings'][9]['ready_at']=time()+3600;$layout['buildings'][9]['upgrade_to']=2;
for($step=1;!Bots::layoutTurn($plannerId,$step);$step++){}$layout['ai']['turns']=$step-1;
Game::saveBase($plannerId,$layout);$before=Game::loadBase($plannerId);$intent=Bots::layoutPlan($plannerId,$before,time());
checkWorkerBot($intent!==null,'Prepared defended yard has a strict scored improvement');
$turn=workerBotTurn($pdo,$plannerId);$after=Game::loadBase($plannerId);
checkWorkerBot($turn['errors']===[] && $turn['layouts_improved']===1,'Scheduler applies a layout using the shared Planner action');
checkWorkerBot(Planner::positions($after)===$intent['positions'],'Saved coordinates exactly match the validated plan');
checkWorkerBot(Bots::layoutScore($after,Bots::profile($plannerId),time())>Bots::layoutScore($before,Bots::profile($plannerId),time()),'Real saved layout improves weighted tower protection');
checkWorkerBot($turn['repaired']===1,'The scheduled turn repairs the damaged sniper before applying its layout');
foreach($before['buildings'] as $index=>$building){
    $changed=$after['buildings'][$index];
    if($building['id']===7){$building['hp']=$building['max_hp'];}
    unset($building['x'],$building['y'],$changed['x'],$changed['y']);
    checkWorkerBot($building===$changed,'Repair and planning preserve every other building field');
}
foreach(['resources','army','garrison','queue','workers','planner'] as $field){checkWorkerBot($after[$field]===$before[$field],'Scheduled planning preserves '.$field);}
checkWorkerBot((int)$after['ai']['last_layout_at']>0 && (int)$after['ai']['last_layout_turn']===$step,'Layout cooldown and turn persist across worker restarts');
$coordinates=Planner::positions($after);$after['ai']['turns']=$step+10;Game::saveBase($plannerId,$after);$turn=workerBotTurn($pdo,$plannerId);
checkWorkerBot($turn['layouts_improved']===0 && Planner::positions(Game::loadBase($plannerId))===$coordinates,'A forced next cadence still respects the persisted fifteen-minute cooldown');

// A developed TH4 guardian has space and funds for each specialist order.
// Give the fixture its ordinary prerequisite buildings in a legal spaced grid.
$defenseId = 28;
$guardian = workerBotFixture();
$guardian['buildings'] = [workerBotStructure(1, 'townhall', 4, 4, 4)];
$guardian['garrison'] = [];
$guardian['army'] = array_fill_keys(array_keys(Catalog::monsters()), 0);
$guardian['workers'] = 2;
$guardian['next_building_id'] = 2;
foreach (Bots::buildPlan(Bots::profile($defenseId), 4) as $type => $desired) {
    if ($type === 'aerial') { break; }
    for ($i = 0; $i < $desired; ++$i) {
        $id = $guardian['next_building_id']++;
        $guardian['buildings'][] = workerBotStructure($id, $type, 4 + (($id - 1) % 5) * 5, 4 + intdiv($id - 1, 5) * 5);
    }
}
$pdo->exec('UPDATE users SET next_turn_at='.(time()+86400).' WHERE is_bot=1');
foreach (['aerial', 'heavytrap'] as $type) {
    $guardian['resources'] = Catalog::buildings()[$type]['cost'];
    Game::saveBase($defenseId, $guardian);
    $turn = workerBotTurn($pdo, $defenseId);
    $guardian = Game::loadBase($defenseId);
    $new = array_values(array_filter($guardian['buildings'], static fn(array $b): bool => $b['type'] === $type));
    checkWorkerBot($turn['errors'] === [] && $turn['built'] === 1 && count($new) === 1, "TH4 guardian builds its own $type");
    checkWorkerBot(array_sum($guardian['resources']) === 0, "The $type uses the guardian's exact saved resource budget");
    if ($type === 'aerial') {
        checkWorkerBot($new[0]['level'] === 0 && $new[0]['ready_at'] > 0, 'The air tower begins as a paid, unfinished worker job');
    }
}
checkWorkerBot(count(array_filter($guardian['buildings'], static fn(array $b): bool => $b['type'] === 'aerial')) === 1, 'The TH4 air tower cap lets the guardian proceed to a trap on its next turn');

// Busy battle participants never rearrange or hire while combat owns their yard.
$attacker=workerBotFixture();Game::saveBase(24,$attacker);$battle=BattleService::start(24,$plannerId);$before=Game::loadBase($plannerId);$turn=workerBotTurn($pdo,$plannerId);$after=Game::loadBase($plannerId);
checkWorkerBot($turn['errors']===[] && $turn['layouts_improved']===0 && $turn['workers_hired']===0,'An active invasion excludes bot construction and layout turns');
checkWorkerBot($after===$before,'Busy participant state remains untouched by the autonomous turn');
checkWorkerBot($pdo->query('SELECT id,name,is_bot,rank_points,wins,losses FROM users WHERE is_bot=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$identity,'Worker and layout progression preserve all thousand identities and earn no free ranks');
echo "PASS {$checks} construction and Yard Planner bot database integration checks.\n";
