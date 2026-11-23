<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';

$db=['database'=>getenv('MYSQL_TEST_DATABASE') ?: '', 'host'=>getenv('MYSQL_TEST_HOST') ?: '127.0.0.1',
    'port'=>(int)(getenv('MYSQL_TEST_PORT') ?: 3306),'username'=>getenv('MYSQL_TEST_USER') ?: 'root',
    'password'=>getenv('MYSQL_TEST_PASSWORD') ?: '', 'game_speed'=>1, 'bots'=>['budget_seconds'=>20,'max_active_battles'=>1]];
if (PHP_SAPI!=='cli'||!str_ends_with($db['database'],'_siegebots_test')) { fwrite(STDERR,"Use an empty MYSQL_TEST_DATABASE ending in _siegebots_test.\n");exit(2); }
$pdo=Db::connect($db);Db::useConnection($pdo,$db);
if ($pdo->query('SHOW TABLES')->fetch()) { throw new RuntimeException('Use an empty disposable database.'); }
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$checks=0;
function checkBotSiege(bool $value,string $message):void {global $checks;if(!$value){throw new RuntimeException($message);}++$checks;}
function turnBotSiege(int $id):array {
    Db::pdo()->prepare('UPDATE users SET next_turn_at=0 WHERE id=?')->execute([$id]);
    activityFixtureReleaseCadence();
    $result=Bots::tick(1,20);
    checkBotSiege(!$result['errors'] && $result['processed']===1,'Exactly one clean online keeper turn');
    return $result;
}
function completeBotJob(int $id,string $type):void {
    $base=Game::loadBase($id);
    foreach ($base['buildings'] as &$building) { if ($building['type']===$type && $building['ready_at']>0) { $building['ready_at']=time()-1; } }
    unset($building);Game::saveBase($id,$base);
}
function botBuilding(array $base,string $type):?array {
    foreach ($base['buildings'] as $building) { if ($building['type']===$type) { return $building; } }return null;
}
Game::seedBots($pdo,1000);World::initialize($pdo);
$pdo->exec("INSERT INTO settings(name,value) VALUES('schema_version','3') ON DUPLICATE KEY UPDATE value='3'");
$pdo->exec('UPDATE users SET next_turn_at='.(time()+86400).' WHERE is_bot=1');
foreach (['builder','collector','guardian','raider'] as $profile) {
    for($hall=1;$hall<=5;$hall++) {
        $plan=Bots::buildPlan($profile,$hall);
        checkBotSiege(isset($plan['siegelab'],$plan['siegefactory'])===($hall>=4),'Mature-yard gate preserves early construction');
    }
}
$types=[];
foreach($pdo->query('SELECT user_id,state_json FROM bases') as $row) {
    $base=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);$id=(int)$row['user_id'];
    checkBotSiege(!isset($base['siege']) && !botBuilding($base,'siegefactory') && !botBuilding($base,'siegelab'),'No free equipment or facilities for a seeded identity');
    $types[Bots::siegeDevice($id)]=$id;
}
checkBotSiege(count($types)===3,'All three specialties appear among the 1,000 identities');
$keeper=(int)$types['jars'];$target=$keeper===1?2:1;
$base=Game::loadBase($keeper);$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],120000);
$base['ai']['last_attack_at']=time();$base['army']['pokey']=50;
foreach ($base['buildings'] as &$building) {
    if($building['type']==='townhall'){$building['level']=5;$building['hp']=$building['max_hp']=Game::buildingStats('townhall',5)['max_hp'];}
}unset($building);
$prerequisites=['locker','maproom','championcage','planner',...array_fill(0,Bots::buildPlan(Bots::profile($keeper),5)['railgun'],'railgun')];
foreach($prerequisites as $type) {
    [$x,$y]=Bots::placements($keeper,$type,0,$base,Catalog::buildings()[$type])[0];
    $hp=Game::buildingStats($type,1)['max_hp'];
    $base['buildings'][]=['id'=>$base['next_building_id']++,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>1,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'stored'=>0];
}
Game::saveBase($keeper,$base);
$pdo->prepare('UPDATE users SET next_turn_at=0 WHERE id=?')->execute([$keeper]);
$idle=Bots::tick(1,20);
checkBotSiege($idle['status']==='paused' && !isset(Game::loadBase($keeper)['siege']),'Empty-server bot neither builds nor researches');
activityFixtureWake();
$turn=turnBotSiege($keeper);$after=Game::loadBase($keeper);
checkBotSiege($turn['built']===1 && botBuilding($after,'siegelab')['level']===0 && botBuilding($after,'siegelab')['ready_at']>time(),'Bot starts a real paid Lab');
checkBotSiege($after['resources']['twig']<=$base['resources']['twig']-6000 && !isset($after['siege']['levels']),'Lab construction consumes resources and grants no knowledge');
completeBotJob($keeper,'siegelab');$before=Game::loadBase($keeper);
$turn=turnBotSiege($keeper);$after=Game::loadBase($keeper);
checkBotSiege(botBuilding($after,'siegefactory')['level']===0 && botBuilding($after,'siegefactory')['ready_at']>time(),'Bot starts a real Factory once the Lab completes');
checkBotSiege($turn['siege_researched']===1 && $after['siege']['research']['type']==='jars','Online bot studies its preferred device');
$research=SiegeWorkshop::price('jars','research',1);
checkBotSiege($after['resources']['twig']<=$before['resources']['twig']-15000-$research['cost']['twig'] && $after['siege']['research']['paid']===$research['cost'],'Factory construction and queued research charge real prices');
completeBotJob($keeper,'siegefactory');$after=Game::loadBase($keeper);$after['siege']['research']['ready_at']=time()-1;Game::saveBase($keeper,$after);
$turn=turnBotSiege($keeper);$after=Game::loadBase($keeper);
checkBotSiege($turn['siege_built']===1 && $after['siege']['levels']['jars']===1 && $after['siege']['build']['type']==='jars','Bot queues paid manufacturing through ordinary action');
checkBotSiege($after['siege']['build']['paid']===SiegeWorkshop::price('jars','build',1)['cost'] && SiegeWorkshop::loadout($after)===null,'Manufacturing grants no stock before completion');
$now=time();
$shift=$after;WorldActivity::stamp($shift,['epoch'=>1,'pausedSeconds'=>0]);$old=$shift['siege']['build']['ready_at'];
WorldActivity::shiftBase($shift,['epoch'=>1,'pausedSeconds'=>900]);
checkBotSiege($shift['siege']['build']['ready_at']===$old+900,'An empty interval moves the paid job, with no offline catch-up');
$after['siege']['build']['ready_at']=$now-1;Game::saveBase($keeper,$after);Game::snapshot($keeper);
checkBotSiege(SiegeWorkshop::loadout(Game::loadBase($keeper))===['type'=>'jars','level'=>1],'A qualified bot finishes a single real device');
$beforeRank=Game::user($keeper)['rank_points'];
$battle=BattleService::botAttack($keeper,$target);
$after=Game::loadBase($keeper);
checkBotSiege($battle['siege']['used']===true && !$after['siege']['inventory']['jars'] && !empty($battle['siege']['active']['targets']),'A live raid deploys the paid device on a real enemy tower in the opening wave');
BattleService::poll($keeper,$battle['id']);
checkBotSiege(Game::loadBase($keeper)['siege']['inventory']['jars']===0 && Game::user($keeper)['rank_points']===$beforeRank,'Polling does not consume twice or fabricate ranks');
echo "Bot Chaos integration: $checks checks passed.\n";
