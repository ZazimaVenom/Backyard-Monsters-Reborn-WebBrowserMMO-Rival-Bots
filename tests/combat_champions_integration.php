<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
ini_set('display_errors','1');
if(!getenv('MYSQL_TEST_DATABASE')){fwrite(STDERR,"Use a disposable database with MYSQL_TEST_* variables.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER'),'password'=>getenv('MYSQL_TEST_PASSWORD'),'game_speed'=>1];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function ciCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function ciReject(callable $action,string $message):void{try{$action();}catch(RuntimeException){ciCheck(true,$message);return;}throw new RuntimeException('FAIL expected rejection: '.$message);}
function ciBuilding(int $id,string $type,float $x,float $y):array{$hp=Game::buildingStats($type,1)['max_hp'];return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>1,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];}
function ciPlayer(?string $champion='gorgo',bool $bot=false,int $level=1):int{
    $q=Db::pdo()->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,?,?)');$q->execute(['ChampionQA '.bin2hex(random_bytes(5)),(int)$bot,time()]);$id=(int)Db::pdo()->lastInsertId();
    $b=Game::initialBase($id,$bot);$b['shield_until']=0;$b['buildings']=[ciBuilding(1,'townhall',24,24),ciBuilding(2,'flinger',4,4),ciBuilding(3,'championcage',15,15)];$b['buildings'][0]['level']=4;$b['buildings'][0]['hp']=100000;$b['buildings'][0]['max_hp']=100000;
    $b['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$b['activeChampion']=$champion;$b['champions']=[];
    if($champion!==null){$b['champions'][$champion]=['level'=>$level,'hp'=>Catalog::championStats($champion,$level)['hp'],'feeds'=>0,'next_feed_at'=>time()+300,'last_heal_at'=>time(),'last_hunger_at'=>time()+300,'battle_id'=>0,'frozen'=>false,'paused_feed_seconds'=>0];}
    $q=Db::pdo()->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$q->execute([$id,json_encode($b),time()]);return $id;
}
function ciState(int $id):array{$q=Db::pdo()->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);return json_decode($q->fetchColumn(),true);}
function ciSave(int $id,array $state):void{$q=Db::pdo()->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($state),$id]);}
function ciAge(int $id,int $seconds):void{$state=ciState($id);activityFixtureAgeState($state,$seconds);ciSave($id,$state);}
$pdo->beginTransaction();
try{
    $a=ciPlayer('gorgo');activityFixtureWake($a);$d=ciPlayer('drull',true);$other=ciPlayer();
    $injured=Game::loadBase($a);$injured['champions']['gorgo']['hp']=4000;$injured['champions']['gorgo']['last_heal_at']=time()+60;Game::saveBase($a,$injured);
    $battle=BattleService::start($a,$d);$id=$battle['id'];
    ciCheck($battle['champion']['type']==='gorgo'&&$battle['champion']['available']&&array_sum($battle['remaining'])===0,'healthy champion can begin an invasion without ordinary troops');
    ciCheck(Game::loadBase($a)['champions']['gorgo']['battle_id']===$id&&Game::loadBase($d)['champions']['drull']['battle_id']===$id,'starting the battle locks both selected champion records');
    ciCheck($battle['defenderChampion']['deployed']&&!$battle['defenderChampion']['available']&&count($battle['units'])===1&&$battle['units'][0]['team']==='defender','defending champion spawns once beside its completed cage');
    $defenderUnit=$battle['units'][0];ciCheck(abs($defenderUnit['x']-15)>1.6||abs($defenderUnit['y']-15)>1.6,'defending champion emerges outside the cage footprint');
    $base=Game::loadBase($a);$hp=$base['champions']['gorgo']['hp'];Game::accrue($base,time()+3600);
    ciCheck($base['champions']['gorgo']['hp']===$hp,'ordinary base accrual cannot heal a battle-locked champion');
    ciReject(fn()=>BattleService::deployChampion($other,$id,1,1),'unrelated account cannot deploy the selected champion');
    ciReject(fn()=>BattleService::deployChampion($d,$id,1,1),'defender cannot control the attacker champion');
    ciReject(fn()=>BattleService::deploy($a,$id,['gorgo'=>1],1,1),'champions cannot be smuggled through the ordinary monster bucket');
    ciReject(fn()=>BattleService::deployChampion($a,$id,15,15),'champion deployment rejects a building footprint');
    ciReject(fn()=>BattleService::deployChampion($a,$id,NAN,1),'champion deployment rejects nonfinite coordinates');
    ciCheck(!ciState($id)['champion']['deployed']&&count(ciState($id)['units'])===1,'invalid champion deployment is atomic');
    $army=Game::loadBase($a)['army'];$deployed=BattleService::deployChampion($a,$id,1,1);
    ciCheck($deployed['champion']['deployed']&&!$deployed['champion']['available']&&count($deployed['units'])===2,'valid clicked ground deploys the selected champion once');
    ciCheck(Game::loadBase($a)['army']===$army&&(float)$deployed['flinger']['readyAt']===0.0,'champion deployment spends neither normal troops nor Flinger cooldown');
    ciReject(fn()=>BattleService::deployChampion($a,$id,2,1),'a second champion deployment is rejected');
    ciReject(fn()=>Game::mutate($a,'championFreeze',[]),'active battle blocks champion roster changes');
    $raw=ciState($id);foreach($raw['units'] as &$u){if($u['team']==='attacker'){$u['hp']=835;}else{$u['hp']=0;$u['state']='dead';}}unset($u);ciSave($id,$raw);
    $ended=BattleService::retreat($a,$id);$ac=Game::loadBase($a)['champions']['gorgo'];$dc=Game::loadBase($d)['champions']['drull'];
    ciCheck((float)$ac['hp']===835.0&&$ac['battle_id']===0&&$ac['last_heal_at']>=time()-1,'attacking champion keeps exact remaining health and resumes recovery after settlement');
    ciCheck((float)$dc['hp']===0.0&&$dc['battle_id']===0&&Game::loadBase($d)['activeChampion']==='drull','defeated champion stays owned at zero health instead of being deleted');
    ciCheck($ended['result']['champions']['defender']['retreated']&&(float)$ended['result']['champions']['attacker']['hp']===835.0,'battle report records both champion outcomes');
    $rank=Game::user($a)['rank_points'];BattleService::poll($a,$id);BattleService::retreat($a,$id);
    ciCheck(Game::loadBase($a)['champions']['gorgo']===$ac&&Game::loadBase($d)['champions']['drull']===$dc&&Game::user($a)['rank_points']===$rank,'duplicate settlement cannot heal, injure or reward champions again');
    $recover=Game::loadBase($d);Game::accrueChampions($recover,$dc['last_heal_at']+90,1);
    ciCheck(abs($recover['champions']['drull']['hp']-1500)<.001,'zero-health champion recovers linearly after its battle lock clears');

    $hold=ciPlayer('fomor');$victim=ciPlayer(null,true);$held=Game::loadBase($hold);$held['champions']['fomor']['hp']=777;$held['champions']['fomor']['last_heal_at']=time()+60;Game::saveBase($hold,$held);
    $unused=BattleService::start($hold,$victim);BattleService::retreat($hold,$unused['id']);$held=Game::loadBase($hold)['champions']['fomor'];
    ciCheck((float)$held['hp']===777.0&&$held['battle_id']===0,'undeployed selected champion returns with unchanged health');

    $mixed=ciPlayer('drull');$target=ciPlayer(null,true);$b=Game::loadBase($mixed);$b['army']['pokey']=2;Game::saveBase($mixed,$b);$mb=BattleService::start($mixed,$target);
    $normal=BattleService::deploy($mixed,$mb['id'],'pokey',1,1);$withChampion=BattleService::deployChampion($mixed,$mb['id'],1,2);
    ciCheck((float)$withChampion['flinger']['readyAt']===(float)$normal['flinger']['readyAt']&&count($withChampion['units'])===2,'champion launches during a normal bucket reload without changing its timer');
    BattleService::retreat($mixed,$mb['id']);

    $unready=ciPlayer('gorgo');$enemy=ciPlayer(null,true);$b=Game::loadBase($unready);$b['champions']['gorgo']['hp']=0;$b['champions']['gorgo']['last_heal_at']=time()+60;Game::saveBase($unready,$b);
    ciReject(fn()=>BattleService::start($unready,$enemy),'zero-health champion alone cannot begin an invasion');
    $b['army']['pokey']=1;Game::saveBase($unready,$b);$noChamp=BattleService::start($unready,$enemy);
    ciCheck($noChamp['champion']===null,'zero-health champion is absent from the available battle roster');
    ciReject(fn()=>BattleService::deployChampion($unready,$noChamp['id'],1,1),'an unavailable champion cannot be manufactured during combat');BattleService::retreat($unready,$noChamp['id']);

    $frozen=ciPlayer('gorgo');$enemy=ciPlayer(null,true);$b=Game::loadBase($frozen);$b['champions']['gorgo']['frozen']=true;Game::saveBase($frozen,$b);
    ciReject(fn()=>BattleService::start($frozen,$enemy),'a frozen champion cannot invade');
    $ruined=ciPlayer('gorgo');$enemy=ciPlayer(null,true);$b=Game::loadBase($ruined);$b['buildings'][2]['hp']=0;Game::saveBase($ruined,$b);
    ciReject(fn()=>BattleService::start($ruined,$enemy),'a ruined cage cannot supply an invading champion');
    $attacker=ciPlayer(null);$b=Game::loadBase($attacker);$b['army']['pokey']=1;Game::saveBase($attacker,$b);$r=BattleService::start($attacker,$ruined);
    ciCheck($r['defenderChampion']===null&&count($r['units'])===0,'a ruined cage does not spawn a defender champion');BattleService::retreat($attacker,$r['id']);

    $bot=ciPlayer('gorgo',true);$enemy=ciPlayer(null);$b=Game::loadBase($bot);$b['army']['octo']=15;$b['army']['pokey']=3;Game::saveBase($bot,$b);
    $auto=BattleService::botAttack($bot,$enemy,['octo'=>15]);
    ciCheck(count(array_filter($auto['units'],fn($u)=>$u['champion']??false))===1&&$auto['champion']['deployed'],'bot deploys its real selected champion once at a legal edge');
    ciCheck(Game::loadBase($bot)['army']['octo']===15&&Game::loadBase($bot)['army']['pokey']===0,'bot preserves paid champion food while deploying other troops');
    ciAge($auto['id'],5);$polled=BattleService::poll($bot,$auto['id']);
    ciCheck(count(array_filter($polled['units'],fn($u)=>$u['champion']??false))===1,'bot catch-up cannot duplicate its champion');BattleService::retreat($bot,$auto['id']);

    $airA=ciPlayer('fomor',false,3);$airD=ciPlayer(null,true);$flight=BattleService::start($airA,$airD);$flight=BattleService::deployChampion($airA,$flight['id'],1,1);
    ciCheck($flight['units'][0]['movement']==='air'&&(float)$flight['units'][0]['auraRange']===6.0&&(float)$flight['units'][0]['auraBuff']===.3,'server snapshots evolved Fomor flight and aura stats');BattleService::retreat($airA,$flight['id']);

    $legacyA=ciPlayer(null);$legacyD=ciPlayer(null,true);$b=Game::loadBase($legacyA);$b['army']['pokey']=1;Game::saveBase($legacyA,$b);$legacy=BattleService::start($legacyA,$legacyD);$raw=ciState($legacy['id']);unset($raw['champion'],$raw['defenderChampion']);$raw['formatVersion']=2;ciSave($legacy['id'],$raw);
    $old=BattleService::deploy($legacyA,$legacy['id'],'pokey',1,1);ciCheck($old['champion']===null&&count($old['units'])===1,'existing version-two battles keep their ordinary deployment contract');
    ciReject(fn()=>BattleService::deployChampion($legacyA,$legacy['id'],1,1),'legacy battle cannot acquire a champion mid-invasion');BattleService::retreat($legacyA,$legacy['id']);
    echo "Champion combat database: $checks checks passed.\n";
}finally{if($pdo->inTransaction()){$pdo->rollBack();}}
