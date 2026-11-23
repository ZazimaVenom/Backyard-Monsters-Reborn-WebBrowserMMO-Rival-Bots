<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
ini_set('display_errors','1');
if(!preg_match('/_test$/',(string)getenv('MYSQL_TEST_DATABASE'))){fwrite(STDERR,"Use MYSQL_TEST_* with a disposable database ending in _test.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER'),'password'=>getenv('MYSQL_TEST_PASSWORD'),'game_speed'=>1];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function niCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function niReject(callable $action,string $message,string $reason):void{try{$action();}catch(RuntimeException $error){niCheck(str_contains($error->getMessage(),$reason),$message);return;}throw new RuntimeException('FAIL expected rejection: '.$message);}
function niBuilding(int $id,string $type,float $x,float $y,int $level=3):array{$hp=Game::buildingStats($type,$level)['max_hp'];return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];}
function niPlayer(int $q,int $r,bool $bot=false):int{
    $p=Db::pdo();$name='NetworkCombatQA '.bin2hex(random_bytes(5));$stmt=$p->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,?,?)');$stmt->execute([$name,(int)$bot,time()]);$id=(int)$p->lastInsertId();
    $b=Game::initialBase($id,$bot);$b['shield_until']=0;$b['buildings']=[niBuilding(1,'townhall',24,24),niBuilding(2,'flinger',4,4),niBuilding(3,'maproom',8,8),niBuilding(4,'sniper',16,16),niBuilding(5,'championcage',24,8)];
    $b['buildings'][3]['fortification']=4;$b['buildings'][3]['fortify_paid']=['twig'=>12345];$b['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$b['army']['fink']=10;$b['resources']=array_fill_keys(['twig','pebble','putty','goo'],20000);
    $b['research']['fink']=3;$b['lab']['levels']['fink']=2;$b['extensions']['networkSentinel']=['unknown'=>'preserved'];
    $b['activeChampion']='drull';$b['champions']['drull']=['level'=>1,'hp'=>777,'feeds'=>0,'next_feed_at'=>time()+300,'last_heal_at'=>time()+3600,'last_hunger_at'=>time()+3600,'battle_id'=>0,'frozen'=>false,'paused_feed_seconds'=>0];
    $stmt=$p->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$stmt->execute([$id,json_encode($b),time()]);
    $stmt=$p->prepare("INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES('home',?,?,?,?,?,1,NULL,1,?)");$stmt->execute([$q,$r,$id,$id,$name,time()]);return $id;
}
function niSite(int $q,int $r,?int $owner=null,string $kind='fortification'):int{$stmt=Db::pdo()->prepare('INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES(?,?,?,?,NULL,?,1,?,1,?)');$stmt->execute([$kind,$q,$r,$owner,'Network combat site',json_encode(World::initialState($kind,1)),time()]);return (int)Db::pdo()->lastInsertId();}
function niState(int $id):array{$q=Db::pdo()->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);return json_decode($q->fetchColumn(),true);}
function niSave(int $id,array $s):void{$q=Db::pdo()->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($s),$id]);}
function niClearShield(int $owner):void{$b=Game::loadBase($owner);$b['shield_until']=0;Game::saveBase($owner,$b);}
function niRawBase(int $owner):string{$q=Db::pdo()->prepare('SELECT state_json FROM bases WHERE user_id=?');$q->execute([$owner]);return $q->fetchColumn();}
$pdo->beginTransaction();
try{
    $a=niPlayer(30,30);$d=niPlayer(34,30,true);$other=niPlayer(29,30);$forts=[];foreach(World::neighbors(34,30) as $cell){$forts[]=niSite($cell['q'],$cell['r'],$d);}
    $damaged=World::load($forts[0]);foreach($damaged['state']['buildings'] as &$b){$b['hp']=0;}unset($b);$damaged['state']['repair_at']=time()+300;World::save($damaged);
    $upgrading=World::load($forts[1]);$upgrading['state']['ready_at']=time()+300;$upgrading['state']['upgrade_to']=2;World::save($upgrading);
    $before=Game::loadBase($d);$battle=BattleService::start($a,$d);$id=$battle['id'];$raw=niState($id);
    niCheck($raw['formatVersion']===7&&$battle['networkDefense']===['count'=>6,'reduction'=>.75,'siteIds'=>$forts],'real home invasion freezes six actual adjacent owned cells, including damaged and upgrading cells');
    $tower=$raw['buildings'][3];niCheck($tower['_networkReduction']===.75&&$tower['_fortificationReduction']===.5&&$tower['damageReduction']===.875,'completed Fortify and map network combine multiplicatively in authoritative home snapshot');
    niCheck($tower['hp']===$before['buildings'][3]['hp']&&$tower['max_hp']===$before['buildings'][3]['max_hp'],'snapshot network support does not heal or inflate actual home building health');
    niCheck($battle['buildings'][3]['networkReduction']===.75&&!isset($battle['buildings'][3]['_networkReduction'],$battle['buildings'][3]['fortify_paid']),'public home battle exposes useful protection without private fields or payment history');
    niCheck($raw['units'][0]['champion']&&(float)$raw['units'][0]['max_hp']===(float)Catalog::championStats('drull',1)['hp']&&(float)$raw['units'][0]['hp']===777.0,'network support does not inflate defending Champion health');
    niReject(fn()=>World::mutate($d,'worldAbandon',['siteId'=>$forts[2]]),'defending keeper cannot abandon a supporting cell while its home battle is active','active battle');
    // Simulate an external maintenance change after start: even this cannot rewrite frozen combat.
    $changed=World::load($forts[2]);$changed['owner_id']=$other;World::save($changed);$live=World::fortificationSupport($d,34,30);niCheck($live['count']===5&&$live['reduction']===.5,'live world ownership now has five supporting cells');
    $poll=BattleService::poll($a,$id);niCheck($poll['networkDefense']===$battle['networkDefense']&&niState($id)['buildings'][3]['_networkReduction']===.75,'polling keeps the battle network snapshot after world ownership changes');
    $raw=niState($id);$raw['buildings'][3]['hp']-=20;niSave($id,$raw);$done=BattleService::retreat($a,$id);
    niCheck($done['result']['networkDefense']===$battle['networkDefense']&&Game::loadBase($d)['buildings'][3]['hp']===$tower['hp']-20,'settlement preserves actual damage and the historical network snapshot');
    $q=$pdo->prepare('SELECT body_json FROM reports WHERE battle_id=?');$q->execute([$id]);$reports=$q->fetchAll(PDO::FETCH_COLUMN);niCheck(count($reports)===2&&json_decode($reports[0],true)['networkDefense']===$battle['networkDefense'],'both real home participants receive reports retaining historical network defense');
    niClearShield($d);$battle=BattleService::start($a,$d);niCheck($battle['networkDefense']['count']===5&&$battle['networkDefense']['reduction']===.5,'next invasion samples changed ownership rather than reusing an earlier network');BattleService::retreat($a,$battle['id']);

    $owner=Game::loadBase($d);$owner['champions']['drull']['last_heal_at']=time()+3600;$owner['champions']['drull']['last_hunger_at']=time()+3600;Game::saveBase($d,$owner);
    $resource=niSite(36,30,$d,'resource');$stronghold=niSite(36,31,$d,'stronghold');$strongFort=niSite(36,32,$d);
    foreach([$resource,$stronghold] as $target){$battle=BattleService::startSite($a,$target);$state=niState($battle['id']);niCheck($battle['networkDefense']['count']===1&&$battle['networkDefense']['reduction']===.07&&$state['buildings'][1]['_networkReduction']===.07,'owned '.$battle['target']['kind'].' defense freezes neighboring Fortification support');BattleService::retreat($a,$battle['id']);}
    // A Fortification with same-owner Fortification neighbors still gets no network armor.
    $target=$forts[3];$homeBefore=Game::loadBase($d);$rawHome=niRawBase($d);$battle=BattleService::startSite($a,$target);$id=$battle['id'];$raw=niState($id);
    niCheck($battle['target']['kind']==='fortification'&&$battle['networkDefense']['count']===0&&(float)$battle['networkDefense']['reduction']===0.0&&$battle['networkDefense']['siteIds']===[],'Fortification itself receives no network armor from adjacent friendly Fortifications');
    niCheck($raw['buildings'][0]['type']==='outpostdefender'&&!$raw['buildings'][0]['_untargetable']&&(float)$raw['buildings'][0]['_damage']===0.0,'Fortification core is targetable and does not fire');
    niCheck($raw['_garrisonTemplates']['4'][2]['labLevel']===2&&$raw['_garrisonTemplates']['4'][2]['level']===3,'owned Fortification garrison uses its real keeper Academy and Laboratory levels');
    niCheck(niRawBase($d)===$rawHome&&$battle['defenderChampion']===null,'Fortification defense preserves home save and leaves its Champion at home');
    $raw['buildings'][1]['hp']-=30;$dead=$raw['_garrisonTemplates']['4'][0];$dead['hp']=0;$raw['units'][]=$dead;$raw['buildings'][3]['_released']=true;niSave($id,$raw);$done=BattleService::retreat($a,$id);$site=World::load($target);
    niCheck(!$done['result']['captured']&&$site['owner_id']===$d&&$site['state']['garrison']['4']['octo']===1&&$site['state']['buildings'][1]['hp']===$raw['buildings'][1]['hp'],'failed Fortification attack persists real garrison casualties and structure damage without changing ownership');
    niCheck(niRawBase($d)===$rawHome&&array_sum($done['result']['loot'])===0,'failed Fortification defense cannot loot or mutate the keeper home');

    $site['state']['shield_until']=0;World::save($site);$battle=BattleService::startSite($a,$target);$id=$battle['id'];$raw=niState($id);$ranks=[Game::user($a)['rank_points'],Game::user($d)['rank_points']];$ownerArmy=Game::loadBase($d)['army'];$attackerArmy=Game::loadBase($a)['army'];
    foreach($raw['buildings'] as &$building){$building['hp']=0;}unset($building);niSave($id,$raw);$done=BattleService::retreat($a,$id);$site=World::load($target);$afterOwner=Game::loadBase($d);
    niCheck($done['result']['captured']&&$done['result']['previousOwner']['id']===$d&&$done['result']['newOwner']['id']===$a&&$site['owner_id']===$a,'real owned Fortification settlement transfers exactly the attacked site and records both historical owners');
    niCheck(World::load($resource)['owner_id']===$d&&World::load($stronghold)['owner_id']===$d&&World::load($strongFort)['owner_id']===$d,'Fortification capture does not transfer any neighboring territory or Fortification');
    niCheck($site['state']['garrison']===[]&&$site['state']['shield_until']>=time()+298&&$site['state']['repair_at']>time(),'capture clears old garrison and schedules the same shield and recovery as other territory');
    niCheck(Game::loadBase($a)['army']===$attackerArmy&&$afterOwner['army']===$ownerArmy&&$afterOwner['buildings']===$homeBefore['buildings']&&$afterOwner['champions']===$homeBefore['champions']&&$afterOwner['extensions']===$homeBefore['extensions'],'capturing a Fortification cannot duplicate troops or change former owner home buildings, Champion, or extension data');
    niCheck(Game::user($a)['rank_points']===$ranks[0]+25&&Game::user($d)['rank_points']===$ranks[1]-15&&array_sum($done['result']['loot'])===0,'owned Fortification victory uses ordinary signed ranks with no home-bank loot');
    niCheck(Game::loadBase($a)['world']['holdings']===0&&Game::loadBase($a)['world']['capacity']===0&&(float)Game::loadBase($a)['world']['resourcePerSecond']===0.0,'captured Fortification adds no resource holding, income, or capacity');
    $q=$pdo->prepare('SELECT COUNT(*) FROM reports WHERE battle_id=?');$q->execute([$id]);niCheck((int)$q->fetchColumn()===2,'owned Fortification produces one report for each real participant');
    $before=[World::load($target),Game::user($a),Game::user($d),niRawBase($a),niRawBase($d)];BattleService::poll($a,$id);BattleService::retreat($a,$id);niCheck([World::load($target),Game::user($a),Game::user($d),niRawBase($a),niRawBase($d)]===$before,'repeated capture polls cannot duplicate ownership, ranks, troops, or rewards');

    $neutral=niSite(30,31);niSite(31,31,$d);$site=World::load($neutral);$site['state']['buildings']=[niBuilding(1,'outpostdefender',16,16,1)];$site['state']['garrison']=[];World::save($site);$battle=BattleService::startSite($a,$neutral);$raw=niState($battle['id']);
    niCheck($battle['networkDefense']['count']===0&&$battle['defender']['id']===0&&$battle['captureProgress']===0.0,'neutral Fortification has a display-only defender and no network armor');
    $raw['buildings'][0]['hp']=$raw['buildings'][0]['max_hp']*.1;niSave($battle['id'],$raw);$points=Game::user($a)['rank_points'];$done=BattleService::retreat($a,$battle['id']);
    niCheck($done['result']['captured']&&!$done['result']['attackerWon']&&$done['result']['captureProgress']===90.0&&World::load($neutral)['owner_id']===$a&&Game::user($a)['rank_points']===$points-15,'exactly ninety percent core damage captures independently while ordinary victory ranking stays unchanged');
    $q=$pdo->prepare('SELECT COUNT(*) FROM reports WHERE battle_id=?');$q->execute([$battle['id']]);niCheck((int)$q->fetchColumn()===1&&$done['result']['rankDelta']['defender']===0,'neutral Fortification capture never creates an account or second report');

    niClearShield($d);$battle=BattleService::start($a,$d);$raw=niState($battle['id']);$raw['formatVersion']=6;niSave($battle['id'],$raw);$legacy=BattleService::poll($a,$battle['id']);
    niCheck($legacy['networkDefense']===null&&!isset($legacy['buildings'][3]['networkReduction'],$legacy['buildings'][3]['damageReduction']),'reloaded format-six battle ignores new network labels in its public snapshot');BattleService::retreat($a,$battle['id']);
    echo "Network combat integration: $checks checks passed.\n";
}finally{if($pdo->inTransaction()){$pdo->rollBack();}}
