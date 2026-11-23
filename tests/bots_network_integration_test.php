<?php
declare(strict_types=1);
/** Destructive only in an explicitly opted-in disposable *_bots_test database. */
if(PHP_SAPI!=='cli' || getenv('BYM_BOTS_TEST_RESET')!=='1'){fwrite(STDERR,"Set BYM_BOTS_TEST_RESET=1 and BYM_BOTS_TEST_* for a disposable *_bots_test database.\n");exit(2);}
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never {throw new ErrorException($message,0,$severity,$file,$line);});
$database=getenv('BYM_BOTS_TEST_DATABASE') ?: 'network_bots_test';
if(!preg_match('/^[a-zA-Z0-9_]+_bots_test$/D',$database)){throw new RuntimeException('Disposable database must end in _bots_test.');}
$config=['host'=>getenv('BYM_BOTS_TEST_HOST') ?: '127.0.0.1','port'=>(int)(getenv('BYM_BOTS_TEST_PORT') ?: 3306),'database'=>$database,'username'=>getenv('BYM_BOTS_TEST_USER') ?: 'root','password'=>getenv('BYM_BOTS_TEST_PASSWORD') ?: '','game_speed'=>20,'bots'=>['human_target_percent'=>0,'budget_seconds'=>20,'max_active_battles'=>1]];
$admin=Db::connect($config,false);$admin->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4');
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');foreach(['alliance_war_battles','alliance_wars','alliance_stances','alliance_requests','alliance_members','alliances','keeper_mail','keeper_gifts','keeper_friends','reports','battles','world_sites','bases','users','settings','auth_attempts'] as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec(file_get_contents(dirname(__DIR__).'/app/schema.sql'));
$checks=0;
function networkIntegrationCheck(bool $truth,string $message):void {global $checks;++$checks;if(!$truth){throw new RuntimeException('FAIL: '.$message);}}
function networkAddBuilding(array &$base,int $id,string $type,int $level):void {
    $point=Bots::placements($id,$type,0,$base,Catalog::buildings()[$type])[0] ?? null;
    if(!$point){throw new RuntimeException('Fixture has no free '.$type.' space for bot '.$id);}
    $hp=Game::buildingStats($type,$level)['max_hp'];
    $base['buildings'][]=['id'=>$base['next_building_id']++,'type'=>$type,'x'=>$point[0],'y'=>$point[1],'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0,'fortification'=>0,'fortify_ready_at'=>0,'fortify_to'=>null];
}
function networkFixture(int $id):array {
    $base=Game::initialBase($id,true);$base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$base['queue']=[];$base['unlocked']=array_keys(Catalog::monsters());$base['unlockQueue']=[];
    $base['research']=array_fill_keys(array_keys(Catalog::monsters()),6);$base['researchQueue']=[];$base['last_tick']=time()+86400;
    $base['resources']=array_fill_keys(['twig','pebble','putty','goo'],0);$base['shield_until']=time()+3600;$base['ai']=['turns'=>0,'last_attack_at'=>time()];
    // Twelve D.A.V.E.s exceed the level-three bucket, exercising persisted follow-up launches.
    foreach($base['buildings'] as &$building){$building['level']=match($building['type']){'townhall','housing'=>5,'flinger'=>3,default=>1};$building['max_hp']=Game::buildingStats($building['type'],$building['level'])['max_hp'];$building['hp']=$building['max_hp'];$building['stored']=0;}unset($building);
    networkAddBuilding($base,$id,'locker',4);networkAddBuilding($base,$id,'maproom',3);return $base;
}
function networkSetOwner(int $siteId,?int $owner):array {$site=World::load($siteId);$site['owner_id']=$owner;$site['state']['shield_until']=0;$site['state']['ready_at']=0;$site['state']['repair_at']=0;$site['state']['upgrade_to']=null;World::save($site);return $site;}
function networkFinishBattle(PDO $pdo,int $attacker,int $battleId):array {
    for($step=0;$step<14;$step++){
        $q=$pdo->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$battleId]);$state=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        if($state['status']!=='active'){return BattleService::poll($attacker,$battleId);}
        activityFixtureWake();
        activityFixtureAgeState($state,(int)ceil((float)$state['elapsed'])+30);
        $q=$pdo->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($state,JSON_THROW_ON_ERROR),$battleId]);
        $snapshot=BattleService::poll($attacker,$battleId);if($snapshot['status']!=='active'){return $snapshot;}
    }
    throw new RuntimeException('Prepared actual bot network invasion did not settle within normal battle duration.');
}
Game::seedBots($pdo,1000);World::initialize($pdo);
$pdo->exec("INSERT INTO settings(name,value) VALUES('schema_version','2') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$pdo->exec('UPDATE users SET next_turn_at='.(time()+86400).' WHERE is_bot=1');
$identity=$pdo->query('SELECT id,name,is_bot FROM users WHERE is_bot=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
networkIntegrationCheck(count($identity)===1000,'Network world starts with exactly one thousand persistent bot identities');
networkIntegrationCheck((int)$pdo->query("SELECT COUNT(*) FROM users WHERE rank_points<>0 OR wins<>0 OR losses<>0")->fetchColumn()===0,'Network seeding grants no initial rank points or victories');
networkIntegrationCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='fortification' AND owner_id IS NOT NULL")->fetchColumn()===0,'Seeding never grants free bot network ownership');

// Prepared, paid-gameplay-ready army; only the scheduler selects and launches.
$attacker=2;$base=networkFixture($attacker);$base['army']['dave']=12;$base['ai']['last_attack_at']=0;
for($turn=1;!Bots::networkTurn($attacker,$turn);$turn++){}$base['ai']['turns']=$turn-1;Game::saveBase($attacker,$base);
$candidates=World::candidates($attacker,48,0);
networkIntegrationCheck(count($candidates)>=6 && count(array_filter($candidates,static fn(array $site):bool=>!in_array($site['kind'],['resource','stronghold'],true)))===0,'Default scouting remains bounded ordinary land despite dense network cells');
foreach(array_slice($candidates,0,6) as $site){networkSetOwner((int)$site['id'],$attacker);}
networkIntegrationCheck(count(World::holdings($attacker))===6 && count(World::fortifications($attacker))===0,'Full ordinary allowance is separate from empty Fortification allowance');
$base=Game::loadBase($attacker);World::syncBonuses($attacker,$base);Game::saveBase($attacker,$base);$economyBefore=$base['world'];
$heldIds=array_column(World::holdings($attacker),'id');$chance=match(Bots::profile($attacker)){'raider'=>35,'guardian'=>12,'builder'=>15,default=>18};$due=time()-1;$found=false;
for($attempt=0;$attempt<50000;$attempt++,$due--){
    $seed=Bots::seed($attacker,$due);if($seed%100>=$chance || $seed%7!==0){continue;}
    $cells=World::candidates($attacker,24,$seed,'fortification');
    if(Bots::networkChoices($attacker,$base,[],$cells,$seed,['human_target_percent'=>0],[],time())){$found=true;break;}
}
networkIntegrationCheck($found,'Prepared network turn finds useful bounded reachable cells');
foreach($cells as $cell){networkIntegrationCheck($cell['kind']==='fortification' && count($cell['adjacentSites'])<=6,'Explicit network scouting carries at most six eligible parent projections');}
$pdo->exec('UPDATE users SET next_turn_at='.$due.' WHERE id='.$attacker);$tick=activityFixtureTick(1,20);
networkIntegrationCheck($tick['errors']===[] && $tick['network_attacks']===1 && $tick['attacks']===1 && $tick['territory_attacks']===1,'Real scheduler launches one network invasion even with all six ordinary holdings filled');
$battle=$pdo->query("SELECT * FROM battles WHERE status='active' AND attacker_id=2")->fetch(PDO::FETCH_ASSOC);$battleId=(int)$battle['id'];$siteId=(int)$battle['target_id'];
networkIntegrationCheck($battle['target_kind']==='fortification' && $battle['defender_id']===null,'Neutral network combat creates no imaginary defender account');
$raw=json_decode($battle['state_json'],true,512,JSON_THROW_ON_ERROR);
networkIntegrationCheck(count($raw['units'])>0 && count($raw['botPlan'])>0 && Game::loadBase(2)['army']['dave']<12,'Scheduler consumes real monsters and persists later deployment buckets');
networkIntegrationCheck(World::load($siteId)['owner_id']===null,'Launching combat never grants ownership before damage settles');
networkIntegrationCheck(($raw['networkDefense']['count'] ?? 0)===0,'A Fortification target never gains surrounding network protection');
$settled=networkFinishBattle($pdo,2,$battleId);
networkIntegrationCheck($settled['result']['captured']===true && (int)World::load($siteId)['owner_id']===2,'Actual bot combat earns the ninety-percent network capture');
networkIntegrationCheck((int)Game::user(2)['rank_points']===25,'Network victory uses normal ranked battle rewards');
networkIntegrationCheck(array_column(World::holdings(2),'id')===$heldIds && count(World::fortifications(2))===1,'Capture adds only the independently owned network cell');
$economyAfter=Game::loadBase(2)['world'];
foreach(['resourcePerSecond','capacity','holdings'] as $field){networkIntegrationCheck($economyAfter[$field]===$economyBefore[$field],'Captured Fortification does not alter cached '.$field);}
$captured=World::load($siteId);
networkIntegrationCheck((int)$captured['state']['shield_until']>time() && (int)$captured['state']['repair_at']>time(),'Bot capture receives ordinary timed shield and rebuilding, not instant repairs');
$protected=[];foreach(World::holdings(2) as $parent){if(World::distance($captured['q'],$captured['r'],$parent['q'],$parent['r'])===1){$protected[]=$parent;}}
$home=World::home(2);if(World::distance($captured['q'],$captured['r'],$home['q'],$home['r'])===1){$protected[]=$home;}
networkIntegrationCheck(count($protected)>0,'Captured scheduler choice protects at least one actual owned eligible parent');
foreach($protected as $parent){$support=World::fortificationSupport(2,$parent['q'],$parent['r']);networkIntegrationCheck(in_array($siteId,$support['siteIds'],true) && $support['reduction']>=0.07,'Owned rebuilding cell already supplies the real adjacent protection');}
$rank=Game::user(2);BattleService::poll(2,$battleId);networkIntegrationCheck(Game::user(2)===$rank,'Repeated bot polls cannot duplicate capture rewards');
$stats=World::siteStats('fortification',1);networkIntegrationCheck($stats['range']===0 && $stats['capacity']===0 && $stats['resourcePerSecond']===0.0,'Network ownership never creates reach, storage or income');

// Shared service transfers only existing surplus troops and charges real costs.
foreach($captured['state']['buildings'] as &$building){$building['hp']=$building['max_hp'];$building['ready_at']=0;}unset($building);
$captured['state']['ready_at']=0;$captured['state']['repair_at']=0;$captured['state']['upgrade_to']=null;$captured['state']['garrison']=[];World::save($captured);
$owner=Game::loadBase(2);$owner['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$owner['army']['fang']=30;$owner['army']['pokey']=11;$owner['activeChampion']='drull';$owner['champions']=['drull'=>['level'=>6,'frozen'=>false,'hp'=>100,'feeds'=>0,'bonus_feeds'=>0,'next_feed_at'=>time()+3600,'last_heal_at'=>time()+3600,'last_hunger_at'=>time()+3600,'battle_id'=>0]];$owner['resources']=array_fill_keys(['twig','pebble','putty','goo'],0);$owner['last_tick']=time()+86400;Game::saveBase(2,$owner);
$plan=Bots::siteManagementPlan($owner,$captured,$stats,'guardian',time());networkIntegrationCheck($plan!==null && $plan['action']==='worldGarrison' && $plan['input']['quantity']===3,'Bot network management reserves all thirty Fang meal ingredients and eighty housing of offence');
World::mutate(2,$plan['action'],$plan['input']);$stocked=World::load($siteId);$after=Game::loadBase(2);$troops=0;foreach($stocked['state']['garrison'] as $garrison){$troops+=(int)($garrison['pokey'] ?? 0);}
networkIntegrationCheck($after['army']['fang']===30 && $after['army']['pokey']===8 && $troops===3,'Shared World mutation transfers only three existing Pokeys into the Fortification bunker');
$owner=$after;$owner['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$owner['activeChampion']=null;$owner['champions']=[];$reserve=Bots::networkHomeReserve($owner,'guardian',time());
foreach($stats['upgradeCost'] as $resource=>$cost){$owner['resources'][$resource]=$cost+$reserve[$resource];}$owner['last_tick']=time()+86400;Game::saveBase(2,$owner);
$plan=Bots::siteManagementPlan($owner,$stocked,$stats,'guardian',time());networkIntegrationCheck($plan!==null && $plan['action']==='worldUpgrade','Funded bot purchases local network defense through the shared upgrade intent');
World::mutate(2,$plan['action'],$plan['input']);$upgraded=World::load($siteId);$after=Game::loadBase(2);
foreach($stats['upgradeCost'] as $resource=>$cost){networkIntegrationCheck(abs($after['resources'][$resource]-$reserve[$resource])<0.01,'Network upgrade charges exact '.$resource.' cost and retains the home reserve');}
networkIntegrationCheck($upgraded['level']===1 && $upgraded['state']['upgrade_to']===2 && $upgraded['state']['ready_at']>time(),'Local defense upgrade pays and queues without granting a level early');
$upgraded['state']['ready_at']=0;$upgraded['state']['upgrade_to']=null;$upgraded['state']['buildings'][0]['hp']=1;World::save($upgraded);$repair=World::repairCost($upgraded);$stats['repairCost']=$repair;
$owner=$after;foreach($repair as $resource=>$cost){$owner['resources'][$resource]=$cost+$reserve[$resource];}$owner['last_tick']=time()+86400;Game::saveBase(2,$owner);
$plan=Bots::siteManagementPlan($owner,$upgraded,$stats,'guardian',time());networkIntegrationCheck($plan!==null && $plan['action']==='worldRepair','Damaged core prompts a real paid repair');World::mutate(2,$plan['action'],$plan['input']);$after=Game::loadBase(2);
foreach($repair as $resource=>$cost){networkIntegrationCheck(abs($after['resources'][$resource]-$reserve[$resource])<0.01,'Repair charges exact '.$resource.' without consuming the home reserve');}
networkIntegrationCheck(World::load($siteId)['state']['repair_at']>time(),'Paid network repair remains timed');

// Removing an enemy-owned support cell weakens its parent without transferring it.
$invader=3;$enemyArmy=networkFixture(3);$enemyArmy['army']['dave']=12;$enemyArmy['army']['pokey']=30;Game::saveBase(3,$enemyArmy);
$enemyCandidates=World::candidates(3,48,0,'fortification');$enemyCell=null;$enemyParent=null;
foreach($enemyCandidates as $candidate){if($candidate['owner_id']!==null){continue;}foreach($candidate['adjacentSites'] as $parent){if($parent['kind']!=='home' && $parent['owner_id']===null){$enemyCell=networkSetOwner($candidate['id'],4);$enemyParent=networkSetOwner($parent['id'],4);break 2;}}}
networkIntegrationCheck($enemyCell!==null && $enemyParent!==null,'Prepared enemy defense fixture uses real adjacent map rows');
$beforeSupport=World::fortificationSupport(4,$enemyParent['q'],$enemyParent['r']);
$candidate=$enemyCell+['owner'=>['id'=>4,'is_bot'=>true],'reachable'=>true,'busy'=>false,'adjacentSites'=>[$enemyParent]];
$enemyChoices=Bots::networkChoices(3,$enemyArmy,[],[$candidate],0,['human_target_percent'=>0],[],time());
networkIntegrationCheck(count($enemyChoices)===1 && in_array($enemyCell['id'],$beforeSupport['siteIds'],true),'Bot identifies the actual owned cell sustaining an enemy parent');
activityFixtureWake();
$start=BattleService::botAttackSite(3,$enemyChoices[0]['id'],['pokey'=>30]);networkIntegrationCheck($start['target']['kind']==='fortification' && (int)$start['target']['ownerId']===4,'Enemy support assault uses the real defending keeper');
$result=networkFinishBattle($pdo,3,(int)$start['id']);$afterSupport=World::fortificationSupport(4,$enemyParent['q'],$enemyParent['r']);
networkIntegrationCheck($result['result']['captured']===true && World::load($enemyCell['id'])['owner_id']===3,'Actual shared combat captures an enemy network cell');
networkIntegrationCheck(World::load($enemyParent['id'])['owner_id']===4 && $afterSupport['count']===$beforeSupport['count']-1 && $afterSupport['reduction']<$beforeSupport['reduction'],'Network assault weakens the enemy parent before its separate future invasion');
networkIntegrationCheck(Game::loadBase(3)['army']['pokey']===30,'Scheduled combat bucket reservations retain all explicitly protected meal troops');
networkIntegrationCheck($pdo->query('SELECT id,name,is_bot FROM users WHERE is_bot=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$identity,'Both neutral and enemy network campaigns retain all one thousand persistent bot identities');
echo "PASS {$checks} strategic-network bot database integration checks.\n";
