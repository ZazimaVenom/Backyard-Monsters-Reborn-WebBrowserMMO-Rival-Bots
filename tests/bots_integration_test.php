<?php
declare(strict_types=1);
/** Destructive ONLY inside an explicitly authorized disposable *_bots_test DB. */
if (PHP_SAPI !== 'cli' || getenv('BYM_BOTS_TEST_RESET') !== '1') {
    fwrite(STDERR, "Set BYM_BOTS_TEST_RESET=1 and BYM_BOTS_TEST_* connection variables for a disposable *_bots_test database.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
$database = getenv('BYM_BOTS_TEST_DATABASE') ?: 'backyard_bots_test';
if (!preg_match('/^[a-zA-Z0-9_]+_bots_test$/D', $database)) { throw new RuntimeException('The disposable database must end in _bots_test.'); }
$config = [
    'host'=>getenv('BYM_BOTS_TEST_HOST') ?: '127.0.0.1',
    'port'=>(int)(getenv('BYM_BOTS_TEST_PORT') ?: 3306),
    'database'=>$database,
    'username'=>getenv('BYM_BOTS_TEST_USER') ?: 'root',
    'password'=>getenv('BYM_BOTS_TEST_PASSWORD') ?: '',
    'game_speed'=>20,
    'bots'=>['human_target_percent'=>50,'budget_seconds'=>20],
];
$admin = Db::connect($config, false);
$admin->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4');
$pdo = Db::connect($config);
Db::useConnection($pdo, $config);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['alliance_war_battles','alliance_wars','alliance_stances','alliance_requests','alliance_members','alliances','keeper_mail','keeper_gifts','keeper_friends','reports','battles','world_sites','bases','users','settings','auth_attempts'] as $table) { $pdo->exec('DROP TABLE IF EXISTS `'.$table.'`'); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec(file_get_contents(dirname(__DIR__).'/app/schema.sql'));
$count = 0;
function verifyBot(bool $truth, string $why): void {
    global $count;
    ++$count;
    if (!$truth) { throw new RuntimeException('FAIL: '.$why); }
}
Game::seedBots($pdo, 1000);
World::initialize($pdo);
$pdo->exec("INSERT INTO settings(name,value) VALUES('schema_version','2') ON DUPLICATE KEY UPDATE value=VALUES(value)");
verifyBot((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn() === 1000, 'exactly1000 persistent bots');
verifyBot((int)$pdo->query('SELECT COUNT(DISTINCT name) FROM users WHERE is_bot=1')->fetchColumn() === 1000, 'unique bot identities');
verifyBot((int)$pdo->query('SELECT COUNT(*) FROM users WHERE rank_points<>0')->fetchColumn() === 0, 'all bots begin at zero points');
verifyBot((int)$pdo->query('SELECT COUNT(*) FROM bases')->fetchColumn() === 1000, 'one saved base per bot');
$human = Game::register('Bot Test Human', 'disposable-password-456');
$humanId = $human['id'];
verifyBot(Game::loadBase($humanId)['shield_until'] > time(), 'new human grace shield');
$pdo->exec('UPDATE users SET next_turn_at='. (time()+86400).' WHERE is_bot=1');
$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id<=3');
$before = [];
for ($id=1; $id<=3; ++$id) {
    $base=Game::loadBase($id);
    $base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);
    Game::saveBase($id,$base);
    $before[$id]=$base;
}
// A second worker cannot act while the scheduler's database lock is held.
$second=Db::connect($config);
$lock='bym:world:'.substr(hash('sha256',$database),0,32);
$q=$second->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
verifyBot((int)$q->fetchColumn()===1,'test owns scheduler lock');
$busy=activityFixtureTick(3,20);
verifyBot($busy['status']==='busy' && $busy['processed']===0,'overlapping scheduler does not execute turns');
$q=$second->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);
$summary=activityFixtureTick(3,20);
verifyBot($summary['processed']===3,'bounded due turns executed');
verifyBot($summary['built']===3,'bots construct their own buildings');
verifyBot($summary['trained']>0,'bots hatch real costed monsters');
verifyBot(count($summary['errors'])===0,'normal turns have no errors');
for ($id=1; $id<=3; ++$id) {
    $after=Game::loadBase($id);
    verifyBot(count($after['buildings'])>count($before[$id]['buildings']),'constructed building persisted');
    verifyBot($after['ai']['turns']===1,'bot turn memory persisted');
    verifyBot($after['resources']['twig']<$before[$id]['resources']['twig'],'construction spends real resources');
    verifyBot(min($after['resources'])>=0,'resource balances never negative');
    $q=$pdo->prepare('SELECT next_turn_at FROM users WHERE id=?');$q->execute([$id]);
    verifyBot((int)$q->fetchColumn()>time(),'turn rescheduled after claim');
}
verifyBot(Game::worldSummary()['worker_running']===true,'heartbeat reaches game world summary');
$empty=Game::loadBase(4);$empty['resources']=array_fill_keys(['twig','pebble','putty','goo'],0);$empty['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$empty['last_tick']=time();
foreach ($empty['buildings'] as &$building) {$building['stored']=0;}unset($building);
Game::saveBase(4,$empty);$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id=4');
$turn=activityFixtureTick(1,20);$emptyAfter=Game::loadBase(4);
verifyBot($turn['built']===0 && $turn['trained']===0,'poor bot receives no free buildings or army');
verifyBot(count($emptyAfter['buildings'])===count($empty['buildings']),'unaffordable construction rejected');
verifyBot(min($emptyAfter['resources'])>=0,'unaffordable turn cannot overdraft');
// Force a reproducible raid decision while shielding all other candidates.
$attacker=5;
$pdo->exec('UPDATE bases SET state_json=JSON_SET(state_json,\'$.shield_until\','.(time()+3600).') WHERE user_id<>'.$attacker);
$profile=Bots::profile($attacker);
$chance=match($profile){'raider'=>35,'guardian'=>12,'builder'=>15,default=>18};
$due=time()-1;
while (true) {
    $seed=Bots::seed($attacker,$due);
    if ($seed%100<$chance && (($seed>>5)%100)<50) { break; }
    --$due;
}
$pdo->exec('UPDATE users SET next_turn_at='.$due.' WHERE id='.$attacker);
$protected=activityFixtureTick(1,20);
verifyBot($protected['attacks']===0,'shielded human is excluded from raids');
$humanBase=Game::loadBase($humanId);$humanBase['shield_until']=0;Game::saveBase($humanId,$humanBase);
$pdo->exec('UPDATE users SET next_turn_at='.$due.' WHERE id='.$attacker);
$raid=activityFixtureTick(1,20);
verifyBot($raid['attacks']===1,'eligible bot invades an unshielded human');
$battle=$pdo->query("SELECT * FROM battles WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
verifyBot((int)$battle['attacker_id']===$attacker && (int)$battle['defender_id']===$humanId,'saved invasion uses actual account IDs');
$state=json_decode($battle['state_json'],true);
verifyBot(count($state['units'])>0,'bot raid deploys real units');
$firstWeight=0;foreach($state['units'] as $unit){if(($unit['team'] ?? 'attacker')==='attacker'){$firstWeight+=$state['flinger']['weights'][$unit['type']];}}
verifyBot($firstWeight<=$state['flinger']['capacity'],'automated initial deployment obeys Flinger bucket capacity');
verifyBot(count($state['botPlan'] ?? [])>0,'remaining bot buckets are persisted rather than launched at once');
verifyBot(array_sum(Game::loadBase($attacker)['army'])<32,'deployments consume saved stock');
$follow=activityFixtureTick(1,20);
verifyBot($follow['battles_advanced']>=1,'worker progresses active battles');
$continued=json_decode($pdo->query('SELECT state_json FROM battles WHERE id='.(int)$battle['id'])->fetchColumn(),true);
verifyBot($continued['unitSeq']===$state['unitSeq'],'immediate worker tick respects Flinger cooldown');
// Explicit surrender exercises idempotent settlement of this automated raid.
BattleService::retreat($attacker,(int)$battle['id']);
$rankBefore=Game::user($attacker)['rank_points'];
BattleService::poll($attacker,(int)$battle['id']);
verifyBot(Game::user($attacker)['rank_points']===$rankBefore,'settled battle cannot award points twice');
verifyBot(Game::loadBase($humanId)['shield_until']>=time()+1790,'human gets30minute shield after bot assault');
// A legacy bot must pay for its Flinger without changing account identity/rating.
$pdo->exec('UPDATE users SET next_turn_at='.(time()+86400).' WHERE is_bot=1');
$legacyId=6;
$legacy=Game::loadBase($legacyId);
$legacy['buildings']=array_values(array_filter($legacy['buildings'],static fn(array $b): bool=>$b['type']!=='flinger'));
unset($legacy['unlocked'],$legacy['unlockQueue'],$legacy['garrison']);
$legacy['last_tick']=time()+60;
foreach($legacy['buildings'] as &$building){$building['stored']=0;}unset($building);
Game::saveBase($legacyId,$legacy);
$pdo->exec('UPDATE users SET rank_points=137,wins=4,losses=2,next_turn_at=0 WHERE id='.$legacyId);
$identity=Game::user($legacyId);
$beforeLegacy=Game::loadBase($legacyId);
verifyBot(count(array_filter($beforeLegacy['buildings'],static fn(array $b): bool=>$b['type']==='flinger'))===0,'old base normalization inserts no free Flinger');
$legacyTurn=activityFixtureTick(1,20);
$afterLegacy=Game::loadBase($legacyId);
$flingers=array_values(array_filter($afterLegacy['buildings'],static fn(array $b): bool=>$b['type']==='flinger'));
verifyBot(count($flingers)===1 && $flingers[0]['level']===0 && $flingers[0]['ready_at']>time(),'legacy bot starts paid timed Flinger construction');
verifyBot($legacyTurn['built']===1 && $legacyTurn['attacks']===0,'unfinished Flinger cannot bypass raid requirement');
verifyBot($beforeLegacy['resources']['twig']-$afterLegacy['resources']['twig']===Catalog::buildings()['flinger']['cost']['twig'],'legacy Flinger charges normal construction cost');
verifyBot(Game::user($legacyId)===$identity,'bot identity and existing rank/win/loss values preserved');
verifyBot((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000,'upgrade never reseeds or duplicates persistent bots');
// Advanced fixtures exercise decisions through regular unlock/garrison/repair APIs.
$advancedId=7;
$advanced=Game::loadBase($advancedId);
$advanced['resources']=['twig'=>100000,'pebble'=>100000,'putty'=>100000,'goo'=>100000];
$advanced['last_tick']=time()+60;
foreach($advanced['buildings'] as &$building){
    if($building['type']==='townhall'){$building['level']=5;}
    $building['max_hp']=Game::buildingStats($building['type'],$building['level'])['max_hp'];$building['hp']=$building['max_hp'];$building['stored']=0;
}unset($building);
foreach(['locker'=>4,'bunker'=>1,'trap'=>1,'juicer'=>1] as $type=>$level){
    $def=Catalog::buildings()[$type];
    $point=Bots::placements($advancedId,$type,0,$advanced,$def)[0] ?? null;
    verifyBot($point!==null,'test has legal space for advanced infrastructure');
    $stats=Game::buildingStats($type,$level);
    $advanced['buildings'][]=['id'=>$advanced['next_building_id']++,'type'=>$type,'x'=>$point[0],'y'=>$point[1],'level'=>$level,'hp'=>$type==='trap'?0:$stats['max_hp'],'max_hp'=>$stats['max_hp'],'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
}
$advanced['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$advanced['army']['pokey']=20;$advanced['army']['ichi']=2;
$advanced['unlocked']=['pokey','octo','fink','ichi','bandito','dave'];$advanced['unlockQueue']=[];$advanced['garrison']=[];$advanced['ai']=['turns'=>0,'last_attack_at'=>time()];
Game::saveBase($advancedId,$advanced);$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$advancedId);
$advancedTurn=activityFixtureTick(1,20);$afterAdvanced=Game::loadBase($advancedId);
verifyBot($advancedTurn['unlocks']===1 && count($afterAdvanced['unlockQueue'])===1,'bot pays for a queued new-species unlock');
verifyBot(!in_array($afterAdvanced['unlockQueue'][0]['type'],$afterAdvanced['unlocked'],true),'unlock timer cannot be bypassed');
verifyBot($advancedTurn['garrisoned']>0 && count($afterAdvanced['garrison'])>0,'bot staffs bunker from its real army');
$armedTraps=array_filter($afterAdvanced['buildings'],static fn(array $b): bool=>$b['type']==='trap' && $b['hp']===$b['max_hp']);
verifyBot(count($armedTraps)===1 && $advancedTurn['repaired']===1,'bot freely rearms spent trap through home recovery');
verifyBot($afterAdvanced['resources']['putty']<$advanced['resources']['putty'],'advanced decisions spend resources');
// Champion decisions retain normal player prerequisites and saved populations.
$pdo->exec('UPDATE users SET next_turn_at='.(time()+86400).' WHERE is_bot=1');
function championBotFixture(int $id,bool $withCage):array{
    $base=Game::initialBase($id,true);
    $base['resources']=['twig'=>100000,'pebble'=>100000,'putty'=>100000,'goo'=>100000];
    $base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$base['queue']=[];
    $base['unlocked']=array_keys(Catalog::monsters());$base['unlockQueue']=[];
    $base['champions']=[];$base['activeChampion']=null;$base['last_tick']=time()+60;
    $base['ai']=['turns'=>0,'last_attack_at'=>time()];
    foreach($base['buildings'] as &$building){
        $building['level']=match($building['type']){'townhall'=>4,'housing'=>3,'flinger'=>2,default=>1};
        $building['max_hp']=Game::buildingStats($building['type'],$building['level'])['max_hp'];
        $building['hp']=$building['max_hp'];$building['stored']=0;
    }unset($building);
    foreach(['locker'=>4,'maproom'=>1]+($withCage?['championcage'=>1]:[]) as $type=>$level){
        $point=Bots::placements($id,$type,0,$base,Catalog::buildings()[$type])[0] ?? null;
        if(!$point){throw new RuntimeException('Champion fixture has no free placement');}
        $stats=Game::buildingStats($type,$level);
        $base['buildings'][]=['id'=>$base['next_building_id']++,'type'=>$type,'x'=>$point[0],'y'=>$point[1],'level'=>$level,'hp'=>$stats['max_hp'],'max_hp'=>$stats['max_hp'],'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
    }
    return $base;
}
$cageBuilderId=8;$cageBuilder=championBotFixture($cageBuilderId,false);
Game::saveBase($cageBuilderId,$cageBuilder);$builderIdentity=Game::user($cageBuilderId);
$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$cageBuilderId);
$cageTurn=activityFixtureTick(1,20);$afterCage=Game::loadBase($cageBuilderId);
$cages=array_values(array_filter($afterCage['buildings'],static fn(array $building):bool=>$building['type']==='championcage'));
verifyBot($cageTurn['built']===1 && count($cages)===1,'TownHall4 bot starts one Champion Cage');
verifyBot($cages[0]['level']===0 && $cages[0]['ready_at']>time(),'Cage construction waits for normal timer');
verifyBot($cageBuilder['resources']['twig']-$afterCage['resources']['twig']===Catalog::buildings()['championcage']['cost']['twig'],'Cage charges normal resource cost');
verifyBot($afterCage['champions']===[] && $cageTurn['champions_raised']===0,'Unfinished Cage grants no champion');
verifyBot(Game::user($cageBuilderId)===$builderIdentity,'Champion construction preserves bot identity and ranking');
$championId=9;$championBase=championBotFixture($championId,true);
Game::saveBase($championId,$championBase);$championIdentity=Game::user($championId);
$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$championId);
$raiseTurn=activityFixtureTick(1,20);$raised=Game::loadBase($championId);$chosen=Bots::championChoice($championId);
verifyBot($raiseTurn['champions_raised']===1 && $raised['activeChampion']===$chosen,'Completed Cage raises deterministic champion through player action');
verifyBot(count($raised['champions'])===1 && $raised['champions'][$chosen]['level']===1,'Exactly one level1 champion persists');
verifyBot($raised['champions'][$chosen]['hp']===Catalog::championStats($chosen,1)['hp'],'Fresh champion has catalog health');
verifyBot(Game::user($championId)===$championIdentity,'Raising cannot award ranking points');
$recipe=Catalog::champions()[$chosen]['feedRecipes'][1];
$raised['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$raised['queue']=[];
foreach($recipe as $type=>$quantity){$raised['army'][$type]=$quantity;}
$raised['champions'][$chosen]['next_feed_at']=0;$raised['champions'][$chosen]['last_hunger_at']=time();
Game::saveBase($championId,$raised);$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$championId);
$fedTurn=activityFixtureTick(1,20);$fed=Game::loadBase($championId);
verifyBot($fedTurn['champions_fed']===1 && $fed['champions'][$chosen]['feeds']===1,'Bot feeds exact source recipe through normal mutation');
foreach($recipe as $type=>$quantity){verifyBot($fed['army'][$type]===0,'Champion meal consumes owned '.$type.' monsters');}
verifyBot($fed['champions'][$chosen]['next_feed_at']>time(),'Champion feed cooldown persists');
verifyBot($fedTurn['trained']>0 && count($fed['queue'])>0,'Bot pays to prepare the next meal');
$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$championId);
$cooldownTurn=activityFixtureTick(1,20);$cooldown=Game::loadBase($championId);
verifyBot($cooldownTurn['champions_fed']===0 && $cooldown['champions'][$chosen]['feeds']===1,'Bot cannot skip feed cooldown');
$cooldown['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$cooldown['queue']=[];
foreach($recipe as $type=>$quantity){$cooldown['army'][$type]=$quantity;}
$cooldown['champions'][$chosen]['feeds']=Catalog::champions()[$chosen]['feedsRequired'][1]-1;
$cooldown['champions'][$chosen]['next_feed_at']=0;$cooldown['champions'][$chosen]['last_hunger_at']=time();
Game::saveBase($championId,$cooldown);$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$championId);
$evolvedTurn=activityFixtureTick(1,20);$evolved=Game::loadBase($championId);
verifyBot($evolvedTurn['champions_fed']===1 && $evolved['champions'][$chosen]['level']===2 && $evolved['champions'][$chosen]['feeds']===0,'Bot earns evolution through the normal final meal');
// Only an eligible target remains, and a reproducible seed requests a raid.
$pdo->exec('UPDATE bases SET state_json=JSON_SET(state_json,\'$.shield_until\','.(time()+3600).') WHERE user_id<>'.$championId);
$humanBase=Game::loadBase($humanId);$humanBase['shield_until']=0;
foreach($humanBase['buildings'] as &$building){if($building['type']==='townhall'){$building['level']=3;}}unset($building);
Game::saveBase($humanId,$humanBase);
$profile=Bots::profile($championId);$chance=match($profile){'raider'=>35,'guardian'=>12,'builder'=>15,default=>18};
$due=time()-1;
while(true){$seed=Bots::seed($championId,$due);if($seed%100<$chance && (($seed>>5)%100)<50 && (($seed>>13)%100)>=65){break;}--$due;}
$evolved['champions'][$chosen]['hp']=1;$evolved['champions'][$chosen]['last_heal_at']=time();
$evolved['champions'][$chosen]['next_feed_at']=time()+3600;$evolved['ai']['last_attack_at']=0;
$evolved['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$evolved['army']['dave']=2;$evolved['queue']=[];
Game::saveBase($championId,$evolved);$pdo->exec('UPDATE users SET next_turn_at='.$due.' WHERE id='.$championId);
$injuredTurn=activityFixtureTick(1,20);
verifyBot($injuredTurn['attacks']===0,'Injured champion waits instead of repeated suicide raids');
$ready=Game::loadBase($championId);$ready['champions'][$chosen]['hp']=Catalog::championStats($chosen,2)['hp'];
$ready['champions'][$chosen]['last_heal_at']=time();$ready['champions'][$chosen]['next_feed_at']=time()+3600;
$ready['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$ready['queue']=[];$ready['army']['dave']=2;
foreach(Catalog::champions()[$chosen]['feedRecipes'][2] as $type=>$quantity){$ready['army'][$type]=$quantity;}
$reserve=Bots::championReserve($ready);$ready['ai']['last_attack_at']=0;
Game::saveBase($championId,$ready);$pdo->exec('UPDATE users SET next_turn_at='.$due.' WHERE id='.$championId);
$championRaid=activityFixtureTick(1,20);
verifyBot($championRaid['attacks']===1,'Recovered champion launches a real raid with its escort');
$championBattle=$pdo->query("SELECT * FROM battles WHERE status='active' AND attacker_id=".$championId.' ORDER BY id DESC LIMIT 1')->fetch();
$championState=json_decode($championBattle['state_json'],true);
$championUnits=array_values(array_filter($championState['units'],static fn(array $unit):bool=>!empty($unit['champion']) && $unit['team']==='attacker'));
verifyBot(count($championUnits)===1 && $championUnits[0]['type']===$chosen,'Automated attack deploys exactly one selected champion');
$duringRaid=Game::loadBase($championId);
foreach($reserve as $type=>$quantity){verifyBot($duringRaid['army'][$type]>=$quantity,'Bot raid preserves its earned '.$type.' meal');}
verifyBot($duringRaid['champions'][$chosen]['battle_id']===(int)$championBattle['id'],'Champion health is locked while its invasion runs');
BattleService::retreat($championId,(int)$championBattle['id']);$settledChampion=Game::loadBase($championId);
verifyBot($settledChampion['champions'][$chosen]['battle_id']===0 && $settledChampion['champions'][$chosen]['hp']>=0,'Settlement releases surviving persistent champion for recovery');
verifyBot((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000,'Champion progression and raids preserve exactly1000 bots');
// v0.4 specialist construction and studies use ordinary player mutations.
$pdo->exec('UPDATE users SET next_turn_at='.(time()+86400).' WHERE is_bot=1');
$specialistId=10;$specialist=Game::initialBase($specialistId,true);
$specialist['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$specialist['queue']=[];
$specialist['unlocked']=array_keys(Catalog::monsters());$specialist['unlockQueue']=[];
$specialist['research']=array_fill_keys(array_keys(Catalog::monsters()),1);$specialist['researchQueue']=[];
$specialist['lab']=['levels'=>array_fill_keys(array_keys(Catalog::labAbilities()),0),'queue'=>null];
$specialist['last_tick']=time()+60;$specialist['ai']=['turns'=>0,'last_attack_at'=>time()];
foreach(Bots::buildPlan(Bots::profile($specialistId)) as $type=>$desired){
    if($type==='lab'){break;}
    $owned=count(array_filter($specialist['buildings'],static fn(array $building):bool=>$building['type']===$type));
    for($ordinal=$owned;$ordinal<$desired;$ordinal++){
        $point=Bots::placements($specialistId,$type,$ordinal,$specialist,Catalog::buildings()[$type])[0] ?? null;
        if(!$point){throw new RuntimeException('Specialist fixture needs a legal '.$type.' position');}
        $stats=Game::buildingStats($type,1);
        $specialist['buildings'][]=['id'=>$specialist['next_building_id']++,'type'=>$type,'x'=>$point[0],'y'=>$point[1],'level'=>1,'hp'=>$stats['max_hp'],'max_hp'=>$stats['max_hp'],'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
    }
}
foreach($specialist['buildings'] as &$building){
    $building['level']=match($building['type']){'townhall','locker'=>4,'flinger'=>2,default=>(int)$building['level']};
    $building['max_hp']=Game::buildingStats($building['type'],$building['level'])['max_hp'];$building['hp']=$building['max_hp'];$building['stored']=0;
}unset($building);
$specialist['resources']=Catalog::buildings()['lab']['cost'];
Game::saveBase($specialistId,$specialist);$specialistIdentity=Game::user($specialistId);
$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$specialistId);
$labBuildTurn=activityFixtureTick(1,20);$labBuildingBase=Game::loadBase($specialistId);
$labs=array_values(array_filter($labBuildingBase['buildings'],static fn(array $building):bool=>$building['type']==='lab'));
verifyBot($labBuildTurn['built']===1 && count($labs)===1,'A developed bot starts its own paid Monster Lab');
verifyBot($labs[0]['level']===0 && $labs[0]['ready_at']>time(),'Bot Lab construction waits for its timer');
verifyBot(array_sum($labBuildingBase['resources'])===0,'Lab construction consumes exactly its affordable resource budget');
verifyBot(array_sum($labBuildingBase['lab']['levels'])===0 && $labBuildingBase['lab']['queue']===null,'Unfinished bot Lab grants no abilities or study queue');
foreach($labBuildingBase['buildings'] as &$building){if($building['type']==='lab'){$building['ready_at']=time()-1;}}unset($building);
$labBuildingBase['resources']=Catalog::buildings()['catapult']['cost'];$labBuildingBase['last_tick']=time()+60;
Game::saveBase($specialistId,$labBuildingBase);
$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$specialistId);
$catapultBuildTurn=activityFixtureTick(1,20);$catapultBase=Game::loadBase($specialistId);
$catapults=array_values(array_filter($catapultBase['buildings'],static fn(array $building):bool=>$building['type']==='catapult'));
verifyBot($catapultBuildTurn['built']===1 && count($catapults)===1,'The next developed bot turn constructs a real Catapult');
verifyBot($catapults[0]['level']===0 && $catapults[0]['ready_at']>time(),'Bot Catapult construction is timed');
verifyBot(array_sum($catapultBase['resources'])===0,'Catapult construction pays its ordinary costs');
foreach($catapultBase['buildings'] as &$building){if($building['type']==='catapult'){$building['ready_at']=time()-1;}}unset($building);
$catapultBase['research']['fink']=2;$catapultBase['resources']=['twig'=>0,'pebble'=>0,'putty'=>Catalog::labStats('fink',1)['cost']['putty'],'goo'=>0];
$catapultBase['last_tick']=time()+60;
Game::saveBase($specialistId,$catapultBase);$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$specialistId);
$studyTurn=activityFixtureTick(1,20);$studying=Game::loadBase($specialistId);
verifyBot($studyTurn['lab_researched']===1 && $studying['lab']['queue']['type']==='fink','Bot starts an eligible Lab study through the player action');
verifyBot($studying['lab']['queue']['target_level']===1 && $studying['lab']['levels']['fink']===0,'Starting a study does not grant its ability early');
verifyBot($studying['lab']['queue']['paid_putty']===Catalog::labStats('fink',1)['cost']['putty'] && $studying['resources']['putty']===0,'Bot study consumes the exact catalog putty price');
verifyBot($studying['lab']['queue']['ready_at']>time(),'Bot Lab study respects the research timer');
$studyQueue=$studying['lab']['queue'];$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$specialistId);
$duplicateStudyTurn=activityFixtureTick(1,20);$unchangedStudy=Game::loadBase($specialistId);
verifyBot($duplicateStudyTurn['lab_researched']===0 && $unchangedStudy['lab']['queue']===$studyQueue,'Another bot turn cannot duplicate or accelerate an in-flight study');
$unchangedStudy['lab']['queue']['ready_at']=time()-1;Game::saveBase($specialistId,$unchangedStudy);
Game::mutate($specialistId,'collect',[]);$learned=Game::loadBase($specialistId);
verifyBot($learned['lab']['levels']['fink']===1 && $learned['lab']['queue']===null,'Elapsed study grants its earned tier once');
Game::mutate($specialistId,'collect',[]);$learnedAgain=Game::loadBase($specialistId);
verifyBot($learnedAgain['lab']['levels']===$learned['lab']['levels'],'Repeated accrual never duplicates Lab progress');
verifyBot(Game::user($specialistId)===$specialistIdentity,'Lab and Catapult progression preserve bot identity and rank');
// A food-aware Academy decision saves enough goo for the next champion meal.
$learnedAgain['ai']['turns']=4;$learnedAgain['researchQueue']=[];$learnedAgain['queue']=[];
$learnedAgain['resources']=['twig'=>0,'pebble'=>0,'putty'=>600,'goo'=>100000];$learnedAgain['last_tick']=time()+60;
foreach($learnedAgain['buildings'] as &$building){if($building['type']==='academy'){$building['level']=2;$building['max_hp']=Game::buildingStats('academy',2)['max_hp'];$building['hp']=$building['max_hp'];}}unset($building);
$labPrerequisite=Bots::academyResearchPlan($learnedAgain,Bots::profile($specialistId),17);
verifyBot($labPrerequisite!==null && isset(Catalog::labAbilities()[$labPrerequisite['type']]),'Academy planner prepares a real missing Lab prerequisite');
$learnedAgain['resources']['goo']=Bots::championFoodGooReserve($learnedAgain);
Game::saveBase($specialistId,$learnedAgain);$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$specialistId);
$foodTurn=activityFixtureTick(1,20);$foodOnly=Game::loadBase($specialistId);
verifyBot($foodTurn['researched']===0 && $foodOnly['researchQueue']===[],'Bot cannot spend champion meal goo on optional Academy research');
verifyBot($foodTurn['trained']>0,'The protected goo is used for actual champion food training');
$foodOnly['resources']['goo']+=100000;$foodOnly['resources']['putty']=10000;$foodOnly['ai']['turns']=9;$foodOnly['last_tick']=time()+60;
Game::saveBase($specialistId,$foodOnly);$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$specialistId);
$academyTurn=activityFixtureTick(1,20);$academyStudying=Game::loadBase($specialistId);
verifyBot($academyTurn['researched']===1 && count($academyStudying['researchQueue'])===1,'Surplus resources fund a timed Academy prerequisite');
verifyBot($academyStudying['resources']['goo']>=Bots::championFoodGooReserve($academyStudying),'Academy research leaves the remaining meal budget intact');
verifyBot((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000,'Specialist progression preserves the original1000 bot accounts');
// Automated artillery uses a saved plan and the same paid launch path as humans.
$artilleryId=11;$artilleryBase=Game::initialBase($artilleryId,true);
$artilleryBase['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$artilleryBase['army']['projectx']=4;$artilleryBase['army']['fink']=12;
$artilleryBase['queue']=[];$artilleryBase['unlocked']=array_keys(Catalog::monsters());$artilleryBase['unlockQueue']=[];
$artilleryBase['research']=array_fill_keys(array_keys(Catalog::monsters()),2);$artilleryBase['researchQueue']=[];
$artilleryBase['lab']=['levels'=>array_fill_keys(array_keys(Catalog::labAbilities()),0),'queue'=>null];
$artilleryBase['lab']['levels']['projectx']=1;$artilleryBase['lab']['levels']['fink']=1;
$artilleryBase['resources']=['twig'=>100000,'pebble'=>100000,'putty'=>100000,'goo'=>100000];$artilleryBase['last_tick']=time()+60;
foreach($artilleryBase['buildings'] as &$building){
    $building['level']=match($building['type']){'townhall'=>4,'housing','flinger'=>3,default=>(int)$building['level']};
    $building['max_hp']=Game::buildingStats($building['type'],$building['level'])['max_hp'];$building['hp']=$building['max_hp'];$building['stored']=0;
}unset($building);
foreach(['lab'=>3,'catapult'=>4] as $type=>$level){
    $point=Bots::placements($artilleryId,$type,0,$artilleryBase,Catalog::buildings()[$type])[0] ?? null;
    if(!$point){throw new RuntimeException('Artillery fixture needs a legal '.$type.' position');}
    $hp=Game::buildingStats($type,$level)['max_hp'];
    $artilleryBase['buildings'][]=['id'=>$artilleryBase['next_building_id']++,'type'=>$type,'x'=>$point[0],'y'=>$point[1],'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
}
$artilleryTarget=Game::initialBase($humanId,false);$artilleryTarget['shield_until']=0;$artilleryTarget['last_tick']=time()+60;
foreach($artilleryTarget['buildings'] as &$building){$building['level']=5;$building['max_hp']=Game::buildingStats($building['type'],5)['max_hp'];$building['hp']=$building['max_hp'];}unset($building);
Game::saveBase($artilleryId,$artilleryBase);Game::saveBase($humanId,$artilleryTarget);
$artilleryIdentity=Game::user($artilleryId);activityFixtureWake($humanId);$artilleryStart=BattleService::botAttack($artilleryId,$humanId);
$artilleryBattleId=(int)$artilleryStart['id'];
$rawArtillery=json_decode($pdo->query('SELECT state_json FROM battles WHERE id='.$artilleryBattleId)->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
$deployedProjectX=array_values(array_filter($rawArtillery['units'],static fn(array $unit):bool=>$unit['type']==='projectx' && $unit['team']==='attacker'));
verifyBot(count($deployedProjectX)===4,'Bot Flinger deploys its real Project X stock');
verifyBot(count(array_filter($deployedProjectX,static fn(array $unit):bool=>$unit['labLevel']===1 && $unit['labAbility']==='deathburst'))===4,'Bot Project X receives only its earned snapshotted Lab power');
verifyBot(count($rawArtillery['_botArtilleryPlan'])+count($rawArtillery['catapult']['shots'])===3,'A ready wealthy bot saves at most one shot for each resource family');
$planFamilies=array_merge($rawArtillery['_botArtilleryPlan'],$rawArtillery['catapult']['shots']);
foreach($planFamilies as $shot){
    $price=Catalog::catapultBombs()[$shot['type']]['levels'][$shot['level']]['cost'];
    verifyBot($price<=$artilleryBase['resources'][$shot['type']]*0.2,'Automated '.$shot['type'].' ammunition stays within its20percent economy budget');
}
activityFixtureWake($humanId);
activityFixtureAgeState($rawArtillery,14);
$stmt=$pdo->prepare('UPDATE battles SET state_json=? WHERE id=?');$stmt->execute([json_encode($rawArtillery,JSON_THROW_ON_ERROR),$artilleryBattleId]);
$artilleryAfter=BattleService::poll($artilleryId,$artilleryBattleId);
verifyBot($artilleryAfter['status']==='active','Artillery test advances a real ongoing invasion');
verifyBot(count($artilleryAfter['catapult']['shots'])===3 && count(array_unique(array_column($artilleryAfter['catapult']['shots'],'type')))===3,'Scheduled bot artillery fires each family exactly once');
verifyBot($artilleryAfter['catapult']['pending']===[],'Scheduled projectiles actually land before the report snapshot');
$shotCost=['twig'=>0,'pebble'=>0,'putty'=>0];
foreach($artilleryAfter['catapult']['shots'] as $shot){$shotCost[$shot['type']]+=$shot['cost'];}
$paidArtillery=Game::loadBase($artilleryId);
foreach($shotCost as $resource=>$spent){verifyBot($artilleryBase['resources'][$resource]-$paidArtillery['resources'][$resource]===$spent,'Bot '.$resource.' shots charge only actual saved resources');}
verifyBot(count(array_filter($artilleryAfter['units'],static fn(array $unit):bool=>$unit['team']==='attacker' && !empty($unit['boosted'])))>0,'Scheduled putty applies a real temporary buff to living attackers');
$artilleryRepeat=BattleService::poll($artilleryId,$artilleryBattleId);
verifyBot($artilleryRepeat['catapult']['shots']==$artilleryAfter['catapult']['shots'],'Repeated world polling cannot duplicate bot artillery');
verifyBot(Game::loadBase($artilleryId)['resources']===$paidArtillery['resources'],'Repeated polling cannot spend artillery costs twice');
verifyBot(Game::user($artilleryId)===$artilleryIdentity,'Artillery does not invent ranking changes before settlement');
$artilleryResult=BattleService::retreat($artilleryId,$artilleryBattleId);
verifyBot(count($artilleryResult['result']['artillery'])===3,'Saved bot battle result records the actual fired ammunition');
$artilleryBase['resources']=['twig'=>100,'pebble'=>100,'putty'=>100,'goo'=>0];$artilleryBase['last_tick']=time()+60;
Game::saveBase($artilleryId,$artilleryBase);Game::saveBase($humanId,$artilleryTarget);
activityFixtureWake($humanId);
$poorArtillery=BattleService::botAttack($artilleryId,$humanId);
$poorArtilleryRaw=json_decode($pdo->query('SELECT state_json FROM battles WHERE id='.(int)$poorArtillery['id'])->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
verifyBot($poorArtilleryRaw['_botArtilleryPlan']===[] && $poorArtilleryRaw['catapult']['shots']===[],'A poor bot does not manufacture or overspend Catapult ammunition');
BattleService::retreat($artilleryId,(int)$poorArtillery['id']);
verifyBot((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000,'Lab-powered raids and paid artillery keep exactly1000 bot identities');
echo "PASS {$count} MySQL bot integration checks, including1000 saved bots, paid champion and specialist progression, food reserves and authoritative raids.\n";

if (getenv('BYM_BOTS_LOAD_TEST') === '1') {
    // Exercise the whole persistent population without a flood of new battles.
    $pdo->exec('UPDATE bases SET state_json=JSON_SET(state_json,\'$.shield_until\','.(time()+3600).')');
    $pdo->exec('UPDATE users SET next_turn_at=0 WHERE is_bot=1');
    $start=microtime(true);$processed=0;$built=0;$errors=0;
    for($batch=0;$batch<20 && $processed<1000;++$batch){
        $result=activityFixtureTick(100,20);
        $processed+=$result['processed'];$built+=$result['built'];$errors+=count($result['errors']);
    }
    verifyBot($processed===1000,'full-population load run visits all1000 bots once');
    verifyBot($errors===0,'full-population turns complete without errors');
    echo 'PASS population load run:1000 bot turns, '.$built.' constructions, no errors; '.round(microtime(true)-$start,3).' seconds in this local test runtime.'.PHP_EOL;
}

if (getenv('BYM_BOTS_LAB_LOAD_TEST') === '1') {
    // Prepared yards isolate scheduler + paid research work. This is deliberately
    // not an organic progression, concurrent raid or long-duration balance run.
    $identityRows=$pdo->query('SELECT id,name,rank_points,wins,losses FROM users WHERE is_bot=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $identityHash=hash('sha256',json_encode($identityRows,JSON_THROW_ON_ERROR));
    $studyPrice=(int)Catalog::labStats('fink',1)['cost']['putty'];
    $pdo->beginTransaction();
    try {
        foreach($identityRows as $identityRow){
            $id=(int)$identityRow['id'];$base=Game::initialBase($id,true);
            $base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$base['queue']=[];
            $base['unlocked']=array_keys(Catalog::monsters());$base['unlockQueue']=[];
            $base['research']=array_fill_keys(array_keys(Catalog::monsters()),1);$base['research']['fink']=2;$base['researchQueue']=[];
            $base['lab']=['levels'=>array_fill_keys(array_keys(Catalog::labAbilities()),0),'queue'=>null];
            $base['resources']=['twig'=>0,'pebble'=>0,'putty'=>$studyPrice,'goo'=>0];
            $base['shield_until']=time()+3600;$base['last_tick']=time()+3600;$base['ai']=['turns'=>0,'last_attack_at'=>time()];
            foreach($base['buildings'] as &$building){
                if($building['type']==='townhall'){$building['level']=3;}
                $building['max_hp']=Game::buildingStats($building['type'],$building['level'])['max_hp'];$building['hp']=$building['max_hp'];$building['stored']=0;
            }unset($building);
            foreach(['academy','lab'] as $type){
                if(array_filter($base['buildings'],static fn(array $building):bool=>$building['type']===$type)){continue;}
                $point=Bots::placements($id,$type,0,$base,Catalog::buildings()[$type])[0] ?? null;
                if(!$point){throw new RuntimeException('Population fixture has no legal '.$type.' space for bot '.$id);}
                $hp=Game::buildingStats($type,1)['max_hp'];
                $base['buildings'][]=['id'=>$base['next_building_id']++,'type'=>$type,'x'=>$point[0],'y'=>$point[1],'level'=>1,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
            }
            Game::saveBase($id,$base);
        }
        $pdo->exec('UPDATE users SET next_turn_at=0 WHERE is_bot=1');$pdo->commit();
    } catch(Throwable $error){$pdo->rollBack();throw $error;}
    $start=microtime(true);$processed=0;$studies=0;$errors=0;$attacks=0;$batches=[];
    for($batch=0;$batch<20 && $processed<1000;++$batch){
        $result=activityFixtureTick(100,20);
        $processed+=$result['processed'];$studies+=$result['lab_researched'];$errors+=count($result['errors']);$attacks+=$result['attacks'];
        $batches[]=['processed'=>$result['processed'],'studies'=>$result['lab_researched'],'elapsed_ms'=>$result['elapsed_ms']];
    }
    $elapsed=round(microtime(true)-$start,3);
    $paid=0;$queued=0;$earnedEarly=0;
    foreach($pdo->query('SELECT b.state_json FROM bases b JOIN users u ON u.id=b.user_id WHERE u.is_bot=1')->fetchAll(PDO::FETCH_ASSOC) as $row){
        $base=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);
        if($base['resources']['putty']===0 && ($base['lab']['queue']['paid_putty'] ?? -1)===$studyPrice){++$paid;}
        if(($base['lab']['queue']['type'] ?? '')==='fink' && ($base['lab']['queue']['target_level'] ?? 0)===1){++$queued;}
        $earnedEarly+=array_sum($base['lab']['levels']);
    }
    $afterHash=hash('sha256',json_encode($pdo->query('SELECT id,name,rank_points,wins,losses FROM users WHERE is_bot=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
    verifyBot($processed===1000 && $studies===1000,'prepared population starts1000 paid Lab studies in1000 turns');
    verifyBot($errors===0 && $attacks===0,'isolated Lab population has no scheduler errors or raids');
    verifyBot($paid===1000 && $queued===1000 && $earnedEarly===0,'every bot pays exact study cost and waits for completion');
    verifyBot(hash_equals($identityHash,$afterHash),'population Lab workload preserves every saved identity and ranking');
    echo json_encode(['fixture'=>'1000 prepared Town Hall3 yards with Lab1, Academy prerequisite, exact putty budget and protected targets','processed'=>$processed,'lab_studies'=>$studies,'paid_cost_verified'=>$paid,'queues_verified'=>$queued,'early_ability_grants'=>$earnedEarly,'attacks'=>$attacks,'errors'=>$errors,'identities_and_ranks_preserved'=>hash_equals($identityHash,$afterHash),'elapsed_seconds'=>$elapsed,'batches'=>$batches],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}
