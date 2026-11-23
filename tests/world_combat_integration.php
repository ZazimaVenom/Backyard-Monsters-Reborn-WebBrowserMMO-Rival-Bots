<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
ini_set('display_errors','1');
if(!getenv('MYSQL_TEST_DATABASE')){fwrite(STDERR,"Use MYSQL_TEST_* with a disposable test database.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER'),'password'=>getenv('MYSQL_TEST_PASSWORD'),'game_speed'=>1];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function wiCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function wiReject(callable $action,string $message):void{try{$action();}catch(RuntimeException){wiCheck(true,$message);return;}throw new RuntimeException('FAIL expected rejection: '.$message);}
function wiBuilding(int $id,string $type,float $x,float $y,int $level=1):array{$hp=Game::buildingStats($type,$level)['max_hp'];return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];}
function wiPlayer(int $q,int $r,bool $bot=false):int{
    $p=Db::pdo();$stmt=$p->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,?,?)');$name='WorldCombatQA '.bin2hex(random_bytes(5));$stmt->execute([$name,(int)$bot,time()]);$id=(int)$p->lastInsertId();
    $b=Game::initialBase($id,$bot);$b['shield_until']=0;$b['buildings']=[wiBuilding(1,'townhall',24,24,3),wiBuilding(2,'flinger',4,4,3),wiBuilding(3,'maproom',8,8,3),wiBuilding(4,'catapult',8,16,3),wiBuilding(5,'championcage',24,8,3)];
    $b['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$b['army']['fink']=10;$b['army']['dave']=5;$b['resources']=array_fill_keys(['twig','pebble','putty','goo'],20000);
    $b['research']['fink']=3;$b['lab']['levels']['fink']=2;$b['extensions']['combatSentinel']=['unknown'=>'preserved'];
    $b['activeChampion']='drull';$b['champions']['drull']=['level'=>1,'hp'=>Catalog::championStats('drull',1)['hp'],'feeds'=>0,'next_feed_at'=>time()+300,'last_heal_at'=>time()+3600,'last_hunger_at'=>time()+3600,'battle_id'=>0,'frozen'=>false,'paused_feed_seconds'=>0];
    $stmt=$p->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$stmt->execute([$id,json_encode($b),time()]);
    $stmt=$p->prepare("INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES('home',?,?,?,?,?,1,NULL,1,?)");$stmt->execute([$q,$r,$id,$id,$name,time()]);return $id;
}
function wiSite(int $q,int $r,?int $owner=null,string $kind='resource',int $level=1):int{
    $state=World::initialState($kind,$level);$stmt=Db::pdo()->prepare('INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES(?,?,?,?,NULL,?,?,?,1,?)');$stmt->execute([$kind,$q,$r,$owner,'Combat test site',$level,json_encode($state),time()]);return (int)Db::pdo()->lastInsertId();
}
function wiState(int $id):array{$q=Db::pdo()->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);return json_decode($q->fetchColumn(),true);}
function wiSave(int $id,array $s):void{$q=Db::pdo()->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($s),$id]);}
function wiAge(int $id,int $seconds):void{$s=wiState($id);activityFixtureAgeState($s,$seconds);wiSave($id,$s);}
function wiWeaken(int $id):void{$site=World::load($id);$site['state']['garrison']=[];$site['state']['buildings']=[wiBuilding(1,'outpost',16,16),wiBuilding(2,'storage',22,22)];$site['state']['buildings'][1]['hp']=$site['state']['buildings'][1]['max_hp']=100;World::save($site);}
function wiRawBase(int $id):string{$q=Db::pdo()->prepare('SELECT state_json FROM bases WHERE user_id=?');$q->execute([$id]);return $q->fetchColumn();}
$pdo->beginTransaction();
try{
    $a=wiPlayer(10,10);activityFixtureWake($a);$d=wiPlayer(12,10,true);$other=wiPlayer(9,10);$neutral=wiSite(11,10);wiWeaken($neutral);
    $battle=BattleService::startSite($a,$neutral);$id=$battle['id'];$q=$pdo->prepare('SELECT defender_id,target_kind,target_id FROM battles WHERE id=?');$q->execute([$id]);$meta=$q->fetch();
    wiCheck($meta['defender_id']===null&&$meta['target_kind']==='resource'&&(int)$meta['target_id']===$neutral,'neutral target uses SQL NULL and the existing indexed battle table');
    wiCheck($battle['defender']['id']===0&&$battle['defender']['neutral']&&$battle['target']['id']===$neutral,'neutral identity is display-only with explicit strategic target metadata');
    wiCheck($battle['defenderChampion']===null&&count($battle['units'])===0,'neutral site does not inherit an invented home champion');
    wiReject(fn()=>BattleService::startSite($other,$neutral),'active neutral site excludes a competing attacker');
    wiReject(fn()=>BattleService::poll($other,$id),'unrelated viewer cannot read a neutral battle');
    wiReject(fn()=>BattleService::poll(0,$id),'display-only neutral identity cannot authorize battle access');
    $army=Game::loadBase($a)['army'];BattleService::deploy($a,$id,'fink',20,18);
    wiCheck(Game::loadBase($a)['army']['fink']===$army['fink']-1,'world deployment consumes the actual trained home army');
    wiAge($id,310);$done=BattleService::poll($a,$id);$site=World::load($neutral);
    wiCheck($done['result']['captured']&&$done['result']['attackerWon']&&$site['owner_id']===$a,'server simulation captures neutral territory at completed damage');
    wiCheck($site['state']['garrison']===[]&&$site['state']['shield_until']>=time()+298&&$site['state']['repair_at']>time(),'capture clears neutral garrison and schedules separate site recovery');
    wiCheck(array_sum($done['result']['loot'])===0&&Game::user($a)['rank_points']===25,'neutral win grants rank but never creates raid loot');
    wiCheck(Game::loadBase($a)['world']['capacity']===2000&&Game::loadBase($a)['world']['holdings']===1,'capture synchronizes the real owner income and capacity cache');
    $q=$pdo->prepare('SELECT COUNT(*) FROM reports WHERE battle_id=?');$q->execute([$id]);wiCheck((int)$q->fetchColumn()===1,'neutral battle creates only the real attacker report');
    $before=[World::load($neutral),Game::user($a),wiRawBase($a)];BattleService::poll($a,$id);BattleService::retreat($a,$id);
    wiCheck([World::load($neutral),Game::user($a),wiRawBase($a)]===$before,'repeat poll and retreat cannot duplicate ownership, income, rank or reports');
    wiReject(fn()=>BattleService::startSite($a,$neutral),'an owner cannot attack its own captured territory');

    $owned=wiSite(13,10,$d);$owner=Game::loadBase($d);World::syncBonuses($d,$owner);Game::saveBase($d,$owner);$rawHome=wiRawBase($d);$battle=BattleService::startSite($a,$owned);$id=$battle['id'];$raw=wiState($id);
    wiCheck($battle['defender']['id']===$d&&$battle['defenderChampion']===null,'owned site identifies its owner without bringing the home champion');
    wiCheck($raw['_garrisonTemplates']['4'][2]['labLevel']===2&&$raw['_garrisonTemplates']['4'][2]['level']===3,'site garrison snapshots actual owner Academy and Laboratory levels');
    wiCheck(wiRawBase($d)===$rawHome,'starting an outpost defense leaves the owner home save byte-for-byte intact');
    wiReject(fn()=>BattleService::start($other,$d),'site defense excludes a simultaneous invasion of the same owner home');
    wiReject(fn()=>BattleService::startSite($d,wiSite(14,10)),'defending owner cannot start another site battle');
    $raw['buildings'][1]['hp']-=25;$dead=$raw['_garrisonTemplates']['4'][0];$dead['hp']=0;$raw['units'][]=$dead;$raw['buildings'][3]['_released']=true;wiSave($id,$raw);
    $done=BattleService::retreat($a,$id);$site=World::load($owned);
    wiCheck(!$done['result']['captured']&&$site['owner_id']===$d,'failed invasion retains the old territory owner');
    wiCheck($site['state']['buildings'][1]['hp']===$raw['buildings'][1]['hp']&&$site['state']['garrison']['4']['octo']===1,'failed invasion persists only site damage and released garrison casualties');
    wiCheck(wiRawBase($d)===$rawHome,'failed outpost defense cannot damage, shield, loot or alter queues and champion at home');
    wiCheck($done['result']['rankDelta']===['attacker'=>-15,'defender'=>25]&&array_sum($done['result']['loot'])===0,'owned site defense applies the standard signed ranks with zero home-bank loot');

    $site['state']['shield_until']=0;World::save($site);$battle=BattleService::startSite($a,$owned);$id=$battle['id'];$raw=wiState($id);
    foreach($raw['buildings'] as &$building){if(!$building['_untargetable']){$building['hp']=0;}}unset($building);wiSave($id,$raw);
    $beforeOwner=Game::loadBase($d);$ownerArmy=$beforeOwner['army'];$attackerArmy=Game::loadBase($a)['army'];$done=BattleService::retreat($a,$id);$site=World::load($owned);$afterOwner=Game::loadBase($d);
    wiCheck($done['result']['captured']&&$done['result']['previousOwner']['id']===$d&&$done['result']['newOwner']['id']===$a,'capture report preserves prior and new historical owners');
    wiCheck($site['owner_id']===$a&&$site['state']['garrison']===[]&&$afterOwner['army']===$ownerArmy&&Game::loadBase($a)['army']===$attackerArmy,'capture removes old garrison without duplicating it into either home army');
    wiCheck($afterOwner['buildings']===$beforeOwner['buildings']&&$afterOwner['shield_until']===$beforeOwner['shield_until']&&$afterOwner['champions']===$beforeOwner['champions'],'capturing an owned site preserves former owner home geometry, shield and champion');
    wiCheck($afterOwner['world']['holdings']===0&&$afterOwner['world']['capacity']===0&&Game::loadBase($a)['world']['holdings']===2,'capture removes previous owner cache and adds new owner cache atomically');
    wiCheck($afterOwner['extensions']===$beforeOwner['extensions'],'site capture preserves unknown home extension data');
    wiCheck(BattleService::poll($d,$id)['id']===$id,'historical defender can read its battle after losing ownership');
    wiReject(fn()=>BattleService::poll($other,$id),'a later unrelated keeper cannot read the historical battle');

    $buff=wiSite(10,11,$a,'stronghold',2);$enemyBuff=wiSite(12,11,$d,'stronghold',1);$target=wiSite(11,11,$d);$battle=BattleService::startSite($a,$target);$id=$battle['id'];$raw=wiState($id);
    wiCheck(abs($raw['_worldBonuses']['attacker']-.2)<.000001&&abs($raw['_worldBonuses']['defender']-.1)<.000001,'in-range Strongholds snapshot separate attacker and defender bonuses');
    $tower=$raw['buildings'][1];wiCheck(abs($tower['_damage']-Catalog::buildings()['sniper']['damage']*1.1)<.000001,'defender Stronghold multiplies tower damage exactly once');
    $deployed=BattleService::deploy($a,$id,'fink',1,1);$raw=wiState($id);$damage=$raw['units'][0]['_damage'];
    wiCheck(abs($damage-Catalog::monsters()['fink']['damage']*pow(1.12,2)*1.2)<.000001,'attacker Stronghold augments its paid Academy monster damage');
    BattleService::poll($a,$id);wiCheck(wiState($id)['units'][0]['_damage']===$damage,'polling does not compound snapshotted world damage');
    $resources=Game::loadBase($a)['resources'];BattleService::catapult($a,$id,'twig',1,10,12);wiCheck(Game::loadBase($a)['resources']['twig']===$resources['twig']-1000,'world artillery charges the actual attacker home stock');
    BattleService::deployChampion($a,$id,1,2);wiCheck(Game::loadBase($a)['champions']['drull']['battle_id']===$id,'world champion deployment uses the actual persistent attacker champion');
    wiAge($id,2);BattleService::poll($a,$id);$raw=wiState($id);$raw['units'][1]['hp']=777;wiSave($id,$raw);BattleService::retreat($a,$id);
    wiCheck(Game::loadBase($a)['champions']['drull']['battle_id']===0&&(float)Game::loadBase($a)['champions']['drull']['hp']===777.0,'world settlement persists attacking champion health and releases its battle lock');

    $threshold=wiSite(14,11);wiWeaken($threshold);$site=World::load($threshold);$site['state']['buildings'][1]['hp']=$site['state']['buildings'][1]['max_hp']=1000;World::save($site);
    $battle=BattleService::startSite($a,$threshold);$raw=wiState($battle['id']);$raw['buildings'][1]['hp']=100;wiSave($battle['id'],$raw);$points=Game::user($a)['rank_points'];$done=BattleService::retreat($a,$battle['id']);
    wiCheck($done['result']['captured']&&!$done['result']['attackerWon']&&$done['result']['captureProgress']===90.0,'exactly 90 percent HP settles capture separately from an ordinary unearned victory');
    wiCheck(World::load($threshold)['owner_id']===$a&&Game::user($a)['rank_points']===$points-15,'weighted capture commits ownership while retaining ordinary rank rules');
    $partial=wiSite(15,11);$site=World::load($partial);$site['state']['buildings']=[wiBuilding(1,'outpost',16,16),wiBuilding(2,'sniper',10,10),wiBuilding(3,'storage',20,20),wiBuilding(4,'goo',20,10)];
    foreach($site['state']['buildings'] as &$building){if($building['type']==='sniper'){$building['hp']=$building['max_hp']=10000;}elseif($building['type']!=='outpost'){$building['hp']=$building['max_hp']=100;}}unset($building);$site['state']['garrison']=[];World::save($site);
    $battle=BattleService::startSite($a,$partial);$raw=wiState($battle['id']);$raw['buildings'][2]['hp']=0;$raw['buildings'][3]['hp']=0;wiSave($battle['id'],$raw);$done=BattleService::retreat($a,$battle['id']);
    wiCheck($done['result']['attackerWon']&&!$done['result']['captured']&&World::load($partial)['owner_id']===null,'ordinary two-building victory cannot claim a site below its weighted capture threshold');

    $noRoom=wiPlayer(8,10);$base=Game::loadBase($noRoom);$base['buildings']=array_values(array_filter($base['buildings'],fn($b)=>$b['type']!=='maproom'));Game::saveBase($noRoom,$base);$target=wiSite(8,11);
    wiReject(fn()=>BattleService::startSite($noRoom,$target),'missing Map Room is rejected by the authoritative battle service');
    $far=wiSite(62,62);wiReject(fn()=>BattleService::startSite($other,$far),'out-of-range territory is rejected on the server');
    $home=BattleService::start($noRoom,$other);wiCheck($home['target']['kind']==='home'&&$home['captureProgress']===null&&wiState($home['id'])['formatVersion']===7,'existing directory invasion remains available without Map Room and keeps ordinary home rules');BattleService::retreat($noRoom,$home['id']);
    $bot=wiPlayer(7,10,true);$target=wiSite(7,11);wiWeaken($target);$beforeArmy=Game::loadBase($bot)['army']['fink'];$auto=BattleService::botAttackSite($bot,$target,['fink'=>3]);wiAge($auto['id'],310);$tick=BattleService::tickActive(100);$done=BattleService::poll($bot,$auto['id']);
    wiCheck($done['status']==='finished'&&$done['result']['captured']&&$tick['errors']===[],'existing scheduler advances and settles real bot territory invasions');
    wiCheck(Game::loadBase($bot)['army']['fink']>=3,'site bot adapter preserves the existing champion-food reserve contract');
    wiReject(fn()=>BattleService::botAttackSite($other,$far),'human accounts cannot call automated site deployment');
    echo "World combat integration: $checks checks passed.\n";
}finally{if($pdo->inTransaction()){$pdo->rollBack();}}
