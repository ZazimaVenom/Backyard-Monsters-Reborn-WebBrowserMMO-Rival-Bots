<?php
declare(strict_types=1);
/** Destructive only inside the caller's explicitly disposable *_bots_test DB. */
if(PHP_SAPI!=='cli' || getenv('BYM_BOTS_TEST_RESET')!=='1'){fwrite(STDERR,"Set BYM_BOTS_TEST_RESET=1 and BYM_BOTS_TEST_* for a disposable *_bots_test database.\n");exit(2);}
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never {throw new ErrorException($message,0,$severity,$file,$line);});
$database=getenv('BYM_BOTS_TEST_DATABASE') ?: 'world_bots_test';
if(!preg_match('/^[a-zA-Z0-9_]+_bots_test$/D',$database)){throw new RuntimeException('Disposable database must end in _bots_test.');}
$config=['host'=>getenv('BYM_BOTS_TEST_HOST') ?: '127.0.0.1','port'=>(int)(getenv('BYM_BOTS_TEST_PORT') ?: 3306),'database'=>$database,'username'=>getenv('BYM_BOTS_TEST_USER') ?: 'root','password'=>getenv('BYM_BOTS_TEST_PASSWORD') ?: '','game_speed'=>20,'bots'=>['human_target_percent'=>0,'budget_seconds'=>20,'max_active_battles'=>1]];
$admin=Db::connect($config,false);$admin->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4');
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');foreach(['alliance_war_battles','alliance_wars','alliance_stances','alliance_requests','alliance_members','alliances','keeper_mail','keeper_gifts','keeper_friends','reports','battles','world_sites','bases','users','settings','auth_attempts'] as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec(file_get_contents(dirname(__DIR__).'/app/schema.sql'));
$checks=0;
function verifyWorldBot(bool $truth,string $message):void {global $checks;++$checks;if(!$truth){throw new RuntimeException('FAIL: '.$message);}}
function botBuilding(array &$base,int $id,string $type,int $level):void {
    $point=Bots::placements($id,$type,0,$base,Catalog::buildings()[$type])[0] ?? null;
    if(!$point){throw new RuntimeException('Fixture has no free '.$type.' space for bot '.$id);}
    $hp=Game::buildingStats($type,$level)['max_hp'];
    $base['buildings'][]=['id'=>$base['next_building_id']++,'type'=>$type,'x'=>$point[0],'y'=>$point[1],'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
}
function worldBotFixture(int $id,bool $room):array {
    $base=Game::initialBase($id,true);
    $base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$base['queue']=[];$base['unlocked']=array_keys(Catalog::monsters());$base['unlockQueue']=[];
    $base['research']=array_fill_keys(array_keys(Catalog::monsters()),6);$base['researchQueue']=[];$base['last_tick']=time()+3600;
    $base['resources']=['twig'=>0,'pebble'=>0,'putty'=>0,'goo'=>0];$base['shield_until']=time()+3600;$base['ai']=['turns'=>0,'last_attack_at'=>time()];
    // Twelve D.A.V.E.s exceed the level-three bucket, exercising persisted follow-up launches.
    foreach($base['buildings'] as &$building){$building['level']=match($building['type']){'townhall'=>3,'housing'=>5,'flinger'=>3,default=>1};$building['max_hp']=Game::buildingStats($building['type'],$building['level'])['max_hp'];$building['hp']=$building['max_hp'];$building['stored']=0;}unset($building);
    botBuilding($base,$id,'locker',4);if($room){botBuilding($base,$id,'maproom',3);}
    return $base;
}
function siteRow(PDO $pdo,int $id):array {$q=$pdo->prepare('SELECT * FROM world_sites WHERE id=?');$q->execute([$id]);$site=$q->fetch(PDO::FETCH_ASSOC);$site['state']=json_decode($site['state_json'],true,512,JSON_THROW_ON_ERROR);return $site;}
function saveSiteFixture(PDO $pdo,array $site):void {$q=$pdo->prepare('UPDATE world_sites SET owner_id=?,level=?,state_json=?,revision=revision+1 WHERE id=?');$q->execute([$site['owner_id'],$site['level'],json_encode($site['state'],JSON_THROW_ON_ERROR),$site['id']]);}
function finishBotBattle(PDO $pdo,int $attackerId,int $battleId):array {
    $snapshot=[];
    for($step=1;$step<=12;$step++){
        $q=$pdo->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$battleId]);$state=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        if($state['status']!=='active'){return BattleService::poll($attackerId,$battleId);}
        activityFixtureWake();
        activityFixtureAgeState($state,(int)ceil((float)$state['elapsed'])+30);
        $q=$pdo->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($state,JSON_THROW_ON_ERROR),$battleId]);
        $snapshot=BattleService::poll($attackerId,$battleId);
        if($snapshot['status']!=='active'){return $snapshot;}
    }
    throw new RuntimeException('Prepared actual bot invasion failed to settle in its normal duration.');
}
Game::seedBots($pdo,1000);World::initialize($pdo);
$pdo->exec("INSERT INTO settings(name,value) VALUES('schema_version','2') ON DUPLICATE KEY UPDATE value=VALUES(value)");
verifyWorldBot((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===1000,'Neutral world sites create no additional accounts');
verifyWorldBot((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='home'")->fetchColumn()===1000,'Every persistent bot has one map home');
verifyWorldBot((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind IN ('resource','stronghold')")->fetchColumn()===320,'Neutral territory population is separate from bots');
$pdo->exec('UPDATE users SET next_turn_at='.(time()+86400).' WHERE is_bot=1');
$mapPrice=Catalog::buildings()['maproom']['cost'];$builder=worldBotFixture(1,false);$builder['resources']=$mapPrice;Game::saveBase(1,$builder);
$identity=Game::user(1);$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id=1');$turn=activityFixtureTick(1,20);$built=Game::loadBase(1);
$rooms=array_values(array_filter($built['buildings'],static fn(array $b):bool=>$b['type']==='maproom'));
verifyWorldBot($turn['errors']===[] && $turn['built']===1 && count($rooms)===1,'A normal bot turn constructs its paid Map Room');
verifyWorldBot($rooms[0]['level']===0 && $rooms[0]['ready_at']>time(),'Map Room has normal paid construction timer');
foreach($mapPrice as $resource=>$price){verifyWorldBot($builder['resources'][$resource]-$built['resources'][$resource]===$price,'Map Room charges exact '.$resource.' cost');}
verifyWorldBot(Game::user(1)===$identity,'Map Room progression preserves identity and ranking');
$poor=worldBotFixture(4,false);Game::saveBase(4,$poor);$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id=4');$poorTurn=activityFixtureTick(1,20);
verifyWorldBot($poorTurn['built']===0 && $poorTurn['territory_attacks']===0,'Poor bots receive no free Map Room or territory permission');
$roomUpgrade=worldBotFixture(7,true);
foreach($roomUpgrade['buildings'] as &$building){if($building['type']==='maproom'){$building['level']=1;$building['max_hp']=Game::buildingStats('maproom',1)['max_hp'];$building['hp']=$building['max_hp'];}}unset($building);
$roomUpgrade['resources']=Game::upgradeCost('maproom',1);$roomUpgrade['ai']['turns']=2;Game::saveBase(7,$roomUpgrade);$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id=7');$upgradeTurn=activityFixtureTick(1,20);$roomAfter=Game::loadBase(7);
$roomAfterBuilding=array_values(array_filter($roomAfter['buildings'],static fn(array $building):bool=>$building['type']==='maproom'))[0];
verifyWorldBot($upgradeTurn['upgraded']===1 && $roomAfterBuilding['level']===1 && $roomAfterBuilding['upgrade_to']===2 && $roomAfterBuilding['ready_at']>time(),'Bot saves and pays for an ordinary timed Map Room upgrade');
foreach($roomUpgrade['resources'] as $resource=>$cost){verifyWorldBot($roomUpgrade['resources'][$resource]-$roomAfter['resources'][$resource]===$cost,'Map Room upgrade pays exact '.$resource.' cost');}


// A scheduler decision, not a test-only call, launches the first neutral invasion.
$attacker=2;$army=worldBotFixture($attacker,true);$army['army']['dave']=12;$army['army']['pokey']=30;$army['ai']['last_attack_at']=0;Game::saveBase($attacker,$army);
$candidates=World::candidates($attacker,24,42);verifyWorldBot(count($candidates)>0 && count($candidates)<=24,'Bot candidate query has a bounded reachable set');
$chance=match(Bots::profile($attacker)){'raider'=>35,'guardian'=>12,'builder'=>15,default=>18};$due=time()-1;
while(true){$seed=Bots::seed($attacker,$due);if($seed%100<$chance && (($seed>>13)%100)<65){break;}--$due;}
$pdo->exec('UPDATE users SET next_turn_at='.$due.' WHERE id='.$attacker);$raid=activityFixtureTick(1,20);
verifyWorldBot($raid['errors']===[] && $raid['territory_attacks']===1 && $raid['attacks']===1,'Persistent scheduler launches a reachable neutral territory invasion');
$battle=$pdo->query("SELECT * FROM battles WHERE status='active' AND attacker_id=2")->fetch(PDO::FETCH_ASSOC);$battleId=(int)$battle['id'];$siteId=(int)$battle['target_id'];
verifyWorldBot($battle['defender_id']===null && $battle['target_kind']!=='home','Neutral battles use SQL NULL defender rather than creating an NPC user');
$raw=json_decode($battle['state_json'],true,512,JSON_THROW_ON_ERROR);
verifyWorldBot(count($raw['units'])>0 && count($raw['botPlan'])>0,'Territory invasion uses real initial units and persisted later buckets');
verifyWorldBot(Game::loadBase(2)['army']['dave']<12,'Territory launch consumes actual saved monsters');
$blocked=worldBotFixture(5,true);$blocked['army']['dave']=12;$blocked['ai']['last_attack_at']=0;Game::saveBase(5,$blocked);$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id=5');$cap=activityFixtureTick(1,20);
verifyWorldBot($cap['attacks']===0 && $cap['active_battles']===1,'Global active battle cap includes neutral territory invasions');
$result=finishBotBattle($pdo,2,$battleId);
verifyWorldBot($result['result']['captured']===true,'Prepared bot army captures territory through actual simulated damage');
$captured=siteRow($pdo,$siteId);verifyWorldBot((int)$captured['owner_id']===2,'Captured bot territory persists in world_sites');
verifyWorldBot((int)Game::user(2)['rank_points']===25,'Neutral capture awards normal victory points');
$rank=Game::user(2);BattleService::poll(2,$battleId);verifyWorldBot(Game::user(2)===$rank,'Repeating bot battle poll cannot duplicate capture/ranking');
verifyWorldBot((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===1001,'Conquest keeps the original thousand bots plus one online fixture human');

// Site management shares the player actions, stock and cost checks.
foreach($captured['state']['buildings'] as &$building){$building['hp']=$building['max_hp'];$building['ready_at']=0;}unset($building);
$captured['state']['ready_at']=0;$captured['state']['repair_at']=0;$captured['state']['upgrade_to']=null;$captured['state']['garrison']=[];saveSiteFixture($pdo,$captured);
$owner=Game::loadBase(2);$owner['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$owner['army']['pokey']=41;$owner['resources']=['twig'=>0,'pebble'=>0,'putty'=>0,'goo'=>0];$owner['activeChampion']='drull';$owner['champions']=['drull'=>['level'=>1,'frozen'=>false,'hp'=>100,'feeds'=>0,'bonus_feeds'=>0,'next_feed_at'=>time()+3600,'last_heal_at'=>time()+3600,'last_hunger_at'=>time()+3600,'battle_id'=>0]];$owner['last_tick']=time()+3600;Game::saveBase(2,$owner);
$stats=World::siteStats($captured['kind'],(int)$captured['level']);$plan=Bots::siteManagementPlan($owner,$captured,$stats,'guardian',time());
verifyWorldBot($plan!==null && $plan['action']==='worldGarrison' && $plan['input']['quantity']===3,'Site planner keeps champion food plus80-space offence while staffing');
World::mutate(2,$plan['action'],$plan['input']);$stocked=siteRow($pdo,$siteId);
$garrisonCount=0;foreach($stocked['state']['garrison'] as $troops){$garrisonCount+=(int)($troops['pokey'] ?? 0);}
verifyWorldBot(Game::loadBase(2)['army']['pokey']===38 && $garrisonCount===3,'Site garrison moves three existing monsters without duplication');
$owner=Game::loadBase(2);$owner['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$owner['activeChampion']=null;$owner['champions']=[];$owner['resources']=$stats['upgradeCost'];$owner['last_tick']=time()+3600;Game::saveBase(2,$owner);
$plan=Bots::siteManagementPlan($owner,$stocked,$stats,'builder',time());verifyWorldBot($plan['action']==='worldUpgrade','A funded bot plans a paid site upgrade');
World::mutate(2,$plan['action'],$plan['input']);$upgraded=siteRow($pdo,$siteId);$paid=Game::loadBase(2);
foreach($stats['upgradeCost'] as $resource=>$cost){verifyWorldBot(abs((float)$paid['resources'][$resource])<0.01,'Site upgrade spends exact '.$resource.' balance');}
verifyWorldBot((int)$upgraded['level']===(int)$stocked['level'] && (int)$upgraded['state']['upgrade_to']===(int)$stocked['level']+1 && (int)$upgraded['state']['ready_at']>time(),'Paid site upgrade waits for its timer');
$upgraded['state']['ready_at']=0;$upgraded['state']['upgrade_to']=null;$upgraded['state']['buildings'][1]['hp']=1;saveSiteFixture($pdo,$upgraded);
$stats['repairCost']=World::repairCost($upgraded);
$owner=Game::loadBase(2);$owner['resources']=$stats['repairCost'];$owner['last_tick']=time()+3600;Game::saveBase(2,$owner);
$plan=Bots::siteManagementPlan($owner,$upgraded,$stats,'builder',time());verifyWorldBot($plan['action']==='worldRepair','Bot plans actual paid defensive repair');World::mutate(2,$plan['action'],$plan['input']);
foreach($stats['repairCost'] as $resource=>$cost){verifyWorldBot(abs((float)Game::loadBase(2)['resources'][$resource])<0.01,'Site repair spends exact '.$resource.' balance');}

// Enemy territory uses the same real defender identity but does not raid its home.
$enemyAttacker=3;$enemyArmy=worldBotFixture(3,true);$enemyArmy['army']['dave']=12;$enemyArmy['army']['pokey']=30;Game::saveBase(3,$enemyArmy);
$enemyTargets=World::candidates(3,24,84);$enemySite=null;
foreach($enemyTargets as $candidate){if($candidate['kind']==='resource' && empty($candidate['owner_id'])){$enemySite=siteRow($pdo,(int)$candidate['id']);break;}}
if(!$enemySite){throw new RuntimeException('No suitable enemy territory fixture within reach.');}
$enemySite['owner_id']=2;$enemySite['state']['shield_until']=0;saveSiteFixture($pdo,$enemySite);
$homeBefore=Game::loadBase(2);$homeBefore['last_tick']=time()+3600;Game::saveBase(2,$homeBefore);
activityFixtureWake();
$enemyStart=BattleService::botAttackSite(3,(int)$enemySite['id'],['pokey'=>30]);
verifyWorldBot((int)$enemyStart['target']['ownerId']===2,'Enemy bot territory battle preserves real defending account');
$busyContext=World::candidates(1,24,0);verifyWorldBot(count(array_filter($busyContext,static fn(array $site):bool=>(int)($site['owner_id'] ?? 0)===2))===0,'Owner-wide active combat excludes other territory owned by the defender');
$enemyResult=finishBotBattle($pdo,3,(int)$enemyStart['id']);verifyWorldBot($enemyResult['result']['captured']===true && (int)siteRow($pdo,(int)$enemySite['id'])['owner_id']===3,'A real bot army conquers another bot territory');
$homeAfter=Game::loadBase(2);verifyWorldBot($homeAfter['buildings']===$homeBefore['buildings'] && $homeAfter['army']===$homeBefore['army'],'Territory defense never damages or consumes the owner home buildings/army');
foreach($homeBefore['resources'] as $resource=>$balance){verifyWorldBot($homeAfter['resources'][$resource]>=$balance,'Territory defense never loots home '.$resource.'; ordinary elapsed outpost income is allowed');}
verifyWorldBot(Game::loadBase(3)['army']['pokey']===30,'Territory buckets preserve every explicitly reserved meal monster');
verifyWorldBot((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000,'Both neutral and enemy conquests keep exactly1000 persistent bots');
echo "PASS {$checks} world bot database integration checks.\n";

if(getenv('BYM_BOTS_WORLD_LOAD_TEST')==='1'){
    $identities=$pdo->query('SELECT id,name,rank_points,wins,losses FROM users WHERE is_bot=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$beforeHash=hash('sha256',json_encode($identities,JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();
    foreach($identities as $identity){$id=(int)$identity['id'];$base=worldBotFixture($id,false);$base['resources']=$mapPrice;Game::saveBase($id,$base);}
    $pdo->exec('UPDATE users SET next_turn_at=0 WHERE is_bot=1');$pdo->commit();
    $started=microtime(true);$processed=0;$builtCount=0;$errors=[];$attacks=0;$batches=[];
    for($i=0;$i<20 && $processed<1000;$i++){$turn=activityFixtureTick(100,20);$processed+=$turn['processed'];$builtCount+=$turn['built'];$errors=array_merge($errors,$turn['errors']);$attacks+=$turn['attacks'];$batches[]=['processed'=>$turn['processed'],'built'=>$turn['built'],'elapsed_ms'=>$turn['elapsed_ms']];}
    $paidCount=0;$pendingCount=0;$freeCompleted=0;
    foreach($pdo->query('SELECT b.state_json FROM bases b JOIN users u ON u.id=b.user_id WHERE u.is_bot=1 ORDER BY b.user_id')->fetchAll(PDO::FETCH_ASSOC) as $row){$base=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);$rooms=array_values(array_filter($base['buildings'],static fn(array $b):bool=>$b['type']==='maproom'));if(count($rooms)===1){$room=$rooms[0];if((int)$room['level']===0 && (int)$room['ready_at']>0){++$pendingCount;}else{++$freeCompleted;}}if(array_sum($base['resources'])==0){++$paidCount;}}
    $afterHash=hash('sha256',json_encode($pdo->query('SELECT id,name,rank_points,wins,losses FROM users WHERE is_bot=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
    verifyWorldBot($processed===1000 && $builtCount===1000 && $paidCount===1000 && $pendingCount===1000,'All1000 prepared bots pay for and queue their own Map Room');
    verifyWorldBot(!$errors && $attacks===0 && $freeCompleted===0,'Population test has no scheduler errors, premature completions or raids');
    verifyWorldBot(hash_equals($beforeHash,$afterHash),'All1000 bot IDs, names, ranks, wins and losses survive the workload unchanged');
    echo json_encode(['fixture'=>'1000 prepared Town Hall3 yards with completed Locker, exact Map Room price, empty armies and no existing Map Room','processed'=>$processed,'paid_maprooms'=>$paidCount,'queued_maprooms'=>$pendingCount,'early_completions'=>$freeCompleted,'attacks'=>$attacks,'errors'=>$errors,'identities_and_ranks_preserved'=>hash_equals($beforeHash,$afterHash),'elapsed_seconds'=>round(microtime(true)-$started,3),'batches'=>$batches],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}
