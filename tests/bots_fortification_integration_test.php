<?php
declare(strict_types=1);
/** Recreates only an explicitly authorized disposable *_bots_test database. */
if(PHP_SAPI!=='cli'||getenv('BYM_BOTS_TEST_RESET')!=='1'){fwrite(STDERR,"Set BYM_BOTS_TEST_RESET=1 and BYM_BOTS_TEST_* for a disposable *_bots_test database.\n");exit(2);}
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never {throw new ErrorException($message,0,$severity,$file,$line);});
$database=getenv('BYM_BOTS_TEST_DATABASE')?:'fortification_bots_test';
if(!preg_match('/^[a-zA-Z0-9_]+_bots_test$/D',$database)){throw new RuntimeException('Disposable database must end in _bots_test.');}
$config=['host'=>getenv('BYM_BOTS_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('BYM_BOTS_TEST_PORT')?:3306),'database'=>$database,'username'=>getenv('BYM_BOTS_TEST_USER')?:'root','password'=>getenv('BYM_BOTS_TEST_PASSWORD')?:'','game_speed'=>1,'bots'=>['human_target_percent'=>0,'budget_seconds'=>20,'max_active_battles'=>1]];
$admin=Db::connect($config,false);$admin->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4');
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');foreach(['alliance_war_battles','alliance_wars','alliance_stances','alliance_requests','alliance_members','alliances','keeper_mail','keeper_gifts','keeper_friends','reports','battles','world_sites','bases','users','settings','auth_attempts'] as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec(file_get_contents(dirname(__DIR__).'/app/schema.sql'));
$checks=0;
function verifyArmorBot(bool $truth,string $message):void {global $checks;++$checks;if(!$truth){throw new RuntimeException('FAIL: '.$message);}}
function armorBotFixture(int $id,int $tier=0):array {
    $base=Game::initialBase($id,true);$base['buildings']=[];$base['next_building_id']=1;
    foreach([['townhall',5,16,16],['flinger',5,7,7]] as [$type,$level,$x,$y]){
        $hp=Game::buildingStats($type,$level)['max_hp'];$base['buildings'][]=['id'=>$base['next_building_id']++,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0,'fortification'=>$type==='townhall'?$tier:0,'fortify_ready_at'=>0,'fortify_to'=>null,'fortify_paid'=>null];
    }
    $base['resources']=Catalog::fortificationStats('townhall',min(4,$tier+1))['cost'];
    $base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$base['queue']=[];$base['researchQueue']=[];$base['unlocked']=array_keys(Catalog::monsters());$base['unlockQueue']=[];$base['garrison']=[];
    $base['champions']=[];$base['activeChampion']=null;$base['lab']=['levels'=>[],'queue'=>null];$base['last_tick']=time()+86400;$base['shield_until']=time()+86400;
    $cadence=Bots::profile($id)==='guardian'?3:4;$base['ai']=['turns'=>$cadence-1,'last_attack_at'=>time()];
    return Game::normalizeBase($base);
}
function armorBotTurn(PDO $pdo,int $id):array {$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id='.$id);return activityFixtureTick(1,20);}
function armorIdentityHash(PDO $pdo):string {return hash('sha256',json_encode($pdo->query('SELECT id,name,is_bot,rank_points,wins,losses FROM users WHERE is_bot=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));}
Game::seedBots($pdo,1000);World::initialize($pdo);
$pdo->exec("INSERT INTO settings(name,value) VALUES('schema_version','2'),('content_version','0.6.0') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$pdo->exec('UPDATE users SET next_turn_at='.(time()+86400).' WHERE is_bot=1');
$identityHash=armorIdentityHash($pdo);
verifyArmorBot((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000,'The test starts with exactly1000 persistent bots');
verifyArmorBot((int)$pdo->query('SELECT COUNT(*) FROM users WHERE rank_points<>0')->fetchColumn()===0,'Every bot starts at zero rank points');
foreach($pdo->query('SELECT b.state_json FROM bases b JOIN users u ON u.id=b.user_id WHERE u.is_bot=1 ORDER BY b.user_id')->fetchAll(PDO::FETCH_ASSOC) as $row){$base=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);foreach($base['buildings'] as $building){verifyArmorBot((int)($building['fortification']??0)===0 && empty($building['fortify_to']),'Seeding never grants free fortification');}}
for($tier=1;$tier<=4;$tier++){
    $id=$tier;$before=armorBotFixture($id,$tier-1);Game::saveBase($id,$before);$price=Catalog::fortificationStats('townhall',$tier)['cost'];
    $summary=armorBotTurn($pdo,$id);$after=Game::loadBase($id);$building=$after['buildings'][0];
    verifyArmorBot($summary['errors']===[] && $summary['fortified']===1,'Scheduler queues one paid tier'.$tier.' fortification');
    verifyArmorBot($summary['built']===0 && $summary['upgraded']===0,'Armor turn leaves other home construction to later turns');
    verifyArmorBot((int)$building['fortification']===$tier-1 && (int)$building['fortify_to']===$tier && (int)$building['fortify_ready_at']>time(),'Tier'.$tier.' waits for its real timer');
    verifyArmorBot($building['hp']===$before['buildings'][0]['hp'] && $building['max_hp']===$before['buildings'][0]['max_hp'],'Armor purchase changes neither current nor maximum health');
    foreach($price as $resource=>$amount){verifyArmorBot(abs((float)$before['resources'][$resource]-(float)$after['resources'][$resource]-(float)$amount)<0.001,'Tier'.$tier.' spends exact '.$resource.' price');}
    verifyArmorBot($after['army']===$before['army'] && $summary['attacks']===0,'Armor buys no troops or rank outcome');
    $again=armorBotTurn($pdo,$id);$pending=Game::loadBase($id);
    verifyArmorBot($again['fortified']===0 && $pending['buildings'][0]['fortify_ready_at']===$building['fortify_ready_at'],'Repeated scheduler visit cannot duplicate a pending fortification');
    // Controlled clock fixture: normal collect/accrual completes the funded job.
    $pending['buildings'][0]['fortify_ready_at']=time()-1;Game::saveBase($id,$pending);
    Game::mutate($id,'collect',[]);$done=Game::loadBase($id);
    verifyArmorBot((int)$done['buildings'][0]['fortification']===$tier && empty($done['buildings'][0]['fortify_to']),'Game clock completes paid tier'.$tier.' exactly once');
    Game::mutate($id,'collect',[]);$repeat=Game::loadBase($id);
    verifyArmorBot((int)$repeat['buildings'][0]['fortification']===$tier,'Repeated completion grants no extra armor tier');
}
$poor=armorBotFixture(5);$poor['resources']=array_fill_keys(['twig','pebble','putty','goo'],0);Game::saveBase(5,$poor);$summary=armorBotTurn($pdo,5);$poorAfter=Game::loadBase(5);
verifyArmorBot($summary['fortified']===0 && $summary['errors']===[] && $poorAfter['buildings']===$poor['buildings'],'Poor scheduler turn leaves armor and structures unchanged');
$damaged=$poor;$damaged['buildings'][0]['hp']=1;Game::saveBase(5,$damaged);$summary=armorBotTurn($pdo,5);
verifyArmorBot($summary['fortified']===0 && $summary['repaired']===1 && Game::loadBase(5)['buildings'][0]['hp']===Game::loadBase(5)['buildings'][0]['max_hp'],'An unfunded damaged home receives free recovery without granting armor');
$growth=armorBotFixture(6);$growth['ai']['turns']=0;$growth['resources']=Catalog::buildings()['locker']['cost'];Game::saveBase(6,$growth);$summary=armorBotTurn($pdo,6);$growthAfter=Game::loadBase(6);
verifyArmorBot($summary['fortified']===0 && $summary['built']===1 && count($growthAfter['buildings'])===3,'Ordinary growth turns still construct paid infrastructure');

// Bonus meals are actually consumed by the scheduler through Game::mutate.
foreach(['gorgo','drull','fomor'] as $offset=>$type){
    $id=10+$offset;$base=armorBotFixture($id);$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],0);$base['ai']['turns']=0;
    $hp=Game::buildingStats('championcage',1)['max_hp'];$base['buildings'][]=['id'=>$base['next_building_id']++,'type'=>'championcage','x'=>24,'y'=>24,'level'=>1,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
    $base['activeChampion']=$type;$base['champions'][$type]=['level'=>6,'bonus_feeds'=>0,'feeds'=>0,'hp'=>Catalog::championStats($type,6,0)['hp']*0.8,'next_feed_at'=>0,'last_heal_at'=>time()+86400,'last_hunger_at'=>time()+86400,'battle_id'=>0,'frozen'=>false,'paused_feed_seconds'=>0];
    $recipe=Game::championRecipe($type,6,0);$base['army']=array_replace($base['army'],$recipe);Game::saveBase($id,$base);
    for($tier=1;$tier<=3;$tier++){
        $saved=Game::loadBase($id);$saved['champions'][$type]['next_feed_at']=0;$saved['champions'][$type]['last_hunger_at']=time()+86400;$saved['champions'][$type]['last_heal_at']=time()+86400;$saved['army']=array_replace(array_fill_keys(array_keys(Catalog::monsters()),0),$recipe);Game::saveBase($id,$saved);
        $summary=armorBotTurn($pdo,$id);$fed=Game::loadBase($id);
        verifyArmorBot($summary['errors']===[] && $summary['champions_fed']===1 && (int)$fed['champions'][$type]['bonus_feeds']===$tier,$type.' earns bonus tier'.$tier.' through a real meal');
        foreach($recipe as $food=>$quantity){verifyArmorBot($fed['army'][$food]===0,$type.' consumes all '.$quantity.' owned '.$food.' for tier'.$tier);}
        verifyArmorBot((int)$fed['champions'][$type]['next_feed_at']>time(),$type.' bonus tier'.$tier.' leaves a real digestion cooldown');
        $fed['army']=array_replace($fed['army'],$recipe);Game::saveBase($id,$fed);$cooldown=armorBotTurn($pdo,$id);$cool=Game::loadBase($id);
        verifyArmorBot($cooldown['champions_fed']===0 && (int)$cool['champions'][$type]['bonus_feeds']===$tier,$type.' cannot feed again during cooldown');
        foreach($recipe as $food=>$quantity){verifyArmorBot($cool['army'][$food]===$quantity,$type.' cooldown preserves the next actual meal');}
    }
    $top=Game::loadBase($id);$top['champions'][$type]['next_feed_at']=0;$top['champions'][$type]['last_hunger_at']=time()+86400;$top['champions'][$type]['last_heal_at']=time()+86400;Game::saveBase($id,$top);
    $summary=armorBotTurn($pdo,$id);$maintained=Game::loadBase($id);
    verifyArmorBot($summary['champions_fed']===1 && (int)$maintained['champions'][$type]['bonus_feeds']===3,$type.' top-tier maintenance consumes a meal without a fourth bonus');
    $away=$maintained;$away['champions'][$type]['next_feed_at']=0;$away['champions'][$type]['battle_id']=999999;$away['army']=array_replace($away['army'],$recipe);Game::saveBase($id,$away);
    $summary=armorBotTurn($pdo,$id);$stillAway=Game::loadBase($id);
    verifyArmorBot($summary['champions_fed']===0 && $stillAway['army']===$away['army'],$type.' away champion cannot eat or lose reserved food');
    $frozen=$away;$frozen['champions'][$type]['battle_id']=0;$frozen['champions'][$type]['frozen']=true;$frozen['activeChampion']=null;Game::saveBase($id,$frozen);
    $summary=armorBotTurn($pdo,$id);$stillFrozen=Game::loadBase($id);
    verifyArmorBot($summary['champions_fed']===0 && (int)$stillFrozen['champions'][$type]['bonus_feeds']===3,$type.' frozen individual preserves earned bonus without feeding');
}
verifyArmorBot(hash_equals($identityHash,armorIdentityHash($pdo)),'Paid armor and champion bonuses preserve every bot identity and rank');
verifyArmorBot((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===1001,'Only the original thousand bots and the online fixture human exist');
echo "PASS {$checks} fortification and champion-bonus bot database checks.\n";

if(getenv('BYM_BOTS_FORTIFICATION_LOAD_TEST')==='1'){
    $beforeHash=armorIdentityHash($pdo);$pdo->beginTransaction();
    for($id=1;$id<=1000;$id++){Game::saveBase($id,armorBotFixture($id));}
    $pdo->exec('UPDATE users SET next_turn_at=0 WHERE is_bot=1');$pdo->commit();
    $started=microtime(true);$processed=0;$queued=0;$errors=[];$attacks=0;$batches=[];
    for($batch=0;$batch<20 && $processed<1000;$batch++){$turn=activityFixtureTick(100,20);$processed+=$turn['processed'];$queued+=$turn['fortified'];$errors=array_merge($errors,$turn['errors']);$attacks+=$turn['attacks'];$batches[]=['processed'=>$turn['processed'],'fortified'=>$turn['fortified'],'elapsed_ms'=>$turn['elapsed_ms']];}
    $paid=0;$pending=0;$early=0;
    foreach($pdo->query('SELECT b.state_json FROM bases b JOIN users u ON u.id=b.user_id WHERE u.is_bot=1 ORDER BY b.user_id')->fetchAll(PDO::FETCH_ASSOC) as $row){$base=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);$building=$base['buildings'][0];if((int)$building['fortification']===0 && (int)($building['fortify_to']??0)===1 && (int)$building['fortify_ready_at']>0){++$pending;}else{++$early;}if(array_sum($base['resources'])==0){++$paid;}}
    $identitiesPreserved=hash_equals($beforeHash,armorIdentityHash($pdo));
    verifyArmorBot($processed===1000 && $queued===1000 && $paid===1000 && $pending===1000,'All1000 prepared bots pay the full price and queue their own armor');
    verifyArmorBot($errors===[] && $attacks===0 && $early===0,'Population workload has no scheduler errors, premature tiers or raids');
    verifyArmorBot($identitiesPreserved,'All1000 identities and ranking values survive the workload unchanged');
    echo json_encode(['fixture'=>'1000 prepared Town Hall5 yards with completed Flinger, exact first-tier armor price, empty armies and each profile due for armor investment','processed'=>$processed,'paid_fortifications'=>$paid,'queued_fortifications'=>$pending,'early_completions'=>$early,'attacks'=>$attacks,'errors'=>$errors,'identities_and_ranks_preserved'=>$identitiesPreserved,'elapsed_seconds'=>round(microtime(true)-$started,3),'batches'=>$batches],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}
