<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
if(!preg_match('/_test$/',(string)getenv('MYSQL_TEST_DATABASE'))){fwrite(STDERR,"Use MYSQL_TEST_* with a disposable database ending in _test.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER'),'password'=>getenv('MYSQL_TEST_PASSWORD'),'game_speed'=>1];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function fiCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function fiNear(float $a,float $b):bool{return abs($a-$b)<.00001;}
function fiReject(callable $action,string $message):void{try{$action();}catch(RuntimeException){fiCheck(true,$message);return;}throw new RuntimeException('FAIL expected rejection: '.$message);}
function fiBuilding(int $id,string $type,float $x,float $y,int $tier=0):array{
    $hp=Game::buildingStats($type,5)['max_hp'];
    return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>5,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0,
        'fortification'=>$tier,'fortify_ready_at'=>0,'fortify_to'=>null,'fortify_paid'=>null];
}
function fiPlayer(?string $champion=null,int $bonus=0,bool $bot=false):int{
    $q=Db::pdo()->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,?,?)');$q->execute(['FortBattleQA '.bin2hex(random_bytes(5)),(int)$bot,time()]);$id=(int)Db::pdo()->lastInsertId();
    $b=Game::initialBase($id,$bot);$b['shield_until']=0;
    $b['buildings']=[fiBuilding(1,'townhall',24,24,4),fiBuilding(2,'flinger',4,4),fiBuilding(3,'championcage',24,8),fiBuilding(4,'sniper',16,16,2),fiBuilding(5,'storage',8,24,1),fiBuilding(6,'wall',12,24)];
    $b['resources']=array_fill_keys(['twig','pebble','putty','goo'],300000);$b['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$b['army']['pokey']=5;
    $b['champions']=[];$b['activeChampion']=$champion;
    if($champion!==null){$stats=Catalog::championStats($champion,6,$bonus);$b['champions'][$champion]=['level'=>6,'hp'=>$stats['hp']-123,'feeds'=>15,'bonus_feeds'=>$bonus,'next_feed_at'=>time()+300,'last_heal_at'=>time()+3600,'last_hunger_at'=>time()+300,'battle_id'=>0,'frozen'=>false,'paused_feed_seconds'=>0];}
    $q=Db::pdo()->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$q->execute([$id,json_encode($b,JSON_THROW_ON_ERROR),time()]);return $id;
}
function fiState(int $id):array{$q=Db::pdo()->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);return json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);}
function fiSave(int $id,array $s):void{$q=Db::pdo()->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($s,JSON_THROW_ON_ERROR),$id]);}
function fiById(array $rows,int $id):array{foreach($rows as $row){if((int)$row['id']===$id){return $row;}}throw new RuntimeException('Missing fixture row');}

$pdo->beginTransaction();
try{
    $a=fiPlayer('gorgo',3);$d=fiPlayer('drull',2,true);$other=fiPlayer();
    $b=Game::loadBase($d);$b['buildings'][3]['fortify_ready_at']=time()+30;$b['buildings'][3]['fortify_to']=3;$b['buildings'][3]['fortify_paid']=['twig'=>1234,'pebble'=>5678,'putty'=>90,'goo'=>0];
    // Malformed ineligible fortification cannot manufacture armor.
    $b['buildings'][5]['fortification']=4;Game::saveBase($d,$b);
    $beforeHp=$b['buildings'][3]['hp'];$battle=BattleService::start($a,$d);$id=$battle['id'];$raw=fiState($id);
    fiCheck($raw['formatVersion']===7,'new backyard battles use frozen fortification, bonus and network format seven');
    $tower=fiById($raw['buildings'],4);$publicTower=fiById($battle['buildings'],4);
    fiCheck($tower['fortification']===2&&fiNear($tower['_fortificationReduction'],.3)&&$tower['fortifying']&&!$tower['_ready'],'starting snapshots prior fortification while the next tier disables tower fire');
    fiCheck(fiNear($tower['hp'],$beforeHp)&&fiNear($tower['max_hp'],$beforeHp),'battle preparation does not inflate or heal fortified building HP');
    fiCheck($publicTower['fortification']===2&&fiNear($publicTower['fortificationReduction'],.3)&&$publicTower['fortifying'],'viewer receives truthful frozen fortification and work state');
    fiCheck(!isset($publicTower['_fortificationReduction'])&&!array_key_exists('fortify_paid',$publicTower),'private combat values and paid cancellation ledger are absent from browser snapshots');
    fiCheck(fiById($raw['buildings'],6)['fortification']===0&&fiNear(fiById($raw['buildings'],6)['_fortificationReduction'],0),'ineligible saved wall fields cannot grant fortification');
    $astats=Catalog::championStats('gorgo',6,3);$dstats=Catalog::championStats('drull',6,2);
    fiCheck($battle['champion']['bonus_feeds']===3&&fiNear($battle['champion']['combatStats']['damage'],$astats['damage'])&&fiNear($battle['champion']['hp'],$astats['hp']-123),'attacker champion snapshots earned food stats and actual injury');
    fiCheck($battle['defenderChampion']['bonus_feeds']===2&&$battle['defenderChampion']['deployed'],'defending champion uses its own earned bonus tier');
    $defender=$raw['units'][0];
    fiCheck(fiNear($defender['_damage'],$dstats['damage'])&&fiNear($defender['max_hp'],$dstats['hp'])&&fiNear($defender['hp'],$dstats['hp']-123),'automatic home defense uses the correct bonus health and damage once');
    fiReject(fn()=>Game::mutate($d,'cancelFortify',['id'=>4]),'active invasion blocks cancellation of the frozen defensive work');
    fiReject(fn()=>Game::mutate($a,'championFeed',[]),'active invasion cannot buy new champion powers');
    fiReject(fn()=>BattleService::deployChampion($other,$id,1,1),'unrelated player cannot deploy the empowered champion');
    $deployed=BattleService::deployChampion($a,$id,1,1);$raw=fiState($id);
    $attacking=array_values(array_filter($raw['units'],fn($unit)=>$unit['team']==='attacker'&&($unit['champion']??false)))[0];
    fiCheck(fiNear($attacking['_damage'],$astats['damage'])&&fiNear($attacking['_speed'],$astats['speed'])&&$attacking['bonus_feeds']===3,'clicked deployment consumes the saved effective champion stats');
    fiReject(fn()=>BattleService::deployChampion($a,$id,2,1),'earned powers cannot create a second champion deployment');

    // Complete the paid base work through a normal read while combat remains active.
    $b=Game::loadBase($d);$b['buildings'][3]['fortify_ready_at']=time()-1;Game::saveBase($d,$b);Game::visit($d,$d);
    $live=Game::loadBase($d);fiCheck($live['buildings'][3]['fortification']===3&&fiNear($live['buildings'][3]['hp'],$beforeHp),'actual fortify completion preserves the base HP during an invasion');
    $polled=BattleService::poll($a,$id);$frozen=fiById(fiState($id)['buildings'],4);
    fiCheck($frozen['fortification']===2&&fiNear($frozen['_fortificationReduction'],.3)&&!$frozen['_ready'],'polling cannot refresh armor or enable a tower halfway through the battle');
    $raw=fiState($id);foreach($raw['buildings'] as &$building){if($building['id']===4){$building['hp']=350.0;}}unset($building);
    foreach($raw['units'] as &$unit){if($unit['champion']??false){$unit['hp']=$unit['team']==='attacker'?777.0:0.0;}}unset($unit);fiSave($id,$raw);
    $finished=BattleService::retreat($a,$id);$ac=Game::loadBase($a)['champions']['gorgo'];$dc=Game::loadBase($d)['champions']['drull'];$defense=Game::loadBase($d)['buildings'][3];
    fiCheck($defense['fortification']===3&&fiNear($defense['hp'],350),'settlement retains completed armor but persists actual battle damage without repair');
    fiCheck($ac['bonus_feeds']===3&&fiNear($ac['hp'],777)&&$ac['battle_id']===0,'attacking champion retains earned powers and exact injuries after battle');
    fiCheck($dc['bonus_feeds']===2&&fiNear($dc['hp'],0)&&$dc['battle_id']===0,'defeated champion retains earned powers at zero health');
    fiCheck($finished['result']['champions']['attacker']['bonus_feeds']===3&&fiNear($finished['result']['champions']['attacker']['max_hp'],$astats['hp']),'report records the actual historical bonus tier and health ceiling');
    $rank=Game::user($a)['rank_points'];BattleService::poll($a,$id);BattleService::retreat($a,$id);
    fiCheck(Game::loadBase($a)['champions']['gorgo']===$ac&&Game::loadBase($d)['champions']['drull']===$dc&&Game::user($a)['rank_points']===$rank,'duplicate settlement cannot heal champions, change tiers or repeat rank rewards');

    // A job already due when a new invasion starts is effective before its snapshot.
    $a2=fiPlayer();$d2=fiPlayer();$b=Game::loadBase($d2);$b['buildings'][3]['fortify_ready_at']=time()-1;$b['buildings'][3]['fortify_to']=3;$b['buildings'][3]['fortify_paid']=['twig'=>1];$b['buildings'][3]['hp']-=71;Game::saveBase($d2,$b);
    $battle=BattleService::start($a2,$d2);$raw=fiState($battle['id']);$tower=fiById($raw['buildings'],4);
    fiCheck($tower['fortification']===3&&fiNear($tower['_fortificationReduction'],.4)&&$tower['_ready']&&!$tower['fortifying'],'work completed before invasion supplies the new tier and restores firing');
    fiCheck(fiNear($tower['hp'],$b['buildings'][3]['hp']),'completing a due fortification job grants no free repairs at battle start');BattleService::retreat($a2,$battle['id']);

    // The champion's actual reservation timestamp controls paused clocks, even
    // when settlement occurs days later and battle created_at differs from it.
    $pauseA=fiPlayer('fomor',3);$pauseD=fiPlayer('gorgo',2);$battle=BattleService::start($pauseA,$pauseD);$pauseId=$battle['id'];
    BattleService::deployChampion($pauseA,$pauseId,1,1);
    $lockAt=time()-2*86400;
    foreach([[$pauseA,'fomor'],[$pauseD,'gorgo']] as [$owner,$type]){
        $base=Game::loadBase($owner);$base['champions'][$type]['last_heal_at']=$lockAt;
        $base['champions'][$type]['next_feed_at']=$lockAt+300;$base['champions'][$type]['last_hunger_at']=$lockAt+120;Game::saveBase($owner,$base);
    }
    $raw=fiState($pauseId);foreach($raw['units'] as &$unit){if($unit['champion']??false){$unit['hp']=$unit['team']==='attacker'?777.0:999.0;}}unset($unit);fiSave($pauseId,$raw);
    BattleService::retreat($pauseA,$pauseId);$pausedA=Game::loadBase($pauseA)['champions']['fomor'];$pausedD=Game::loadBase($pauseD)['champions']['gorgo'];
    foreach([[$pausedA,3,777.0,'attacker'],[$pausedD,2,999.0,'defender']] as [$champion,$tier,$hp,$team]){
        fiCheck($champion['next_feed_at']===$champion['last_heal_at']+300&&$champion['last_hunger_at']===$champion['last_heal_at']+120,"offline deployed $team resumes both clocks from its actual reservation timestamp");
        fiCheck($champion['bonus_feeds']===$tier&&fiNear($champion['hp'],$hp),"offline deployed $team retains bonus tiers and battle injury without recovery catch-up");
    }
    BattleService::poll($pauseA,$pauseId);BattleService::retreat($pauseA,$pauseId);
    fiCheck(Game::loadBase($pauseA)['champions']['fomor']===$pausedA&&Game::loadBase($pauseD)['champions']['gorgo']===$pausedD,'repeated settlement cannot extend the paused meal or hunger clocks twice');
    Game::visit($pauseA,$pauseA);Game::visit($pauseD,$pauseD);
    fiCheck(Game::loadBase($pauseA)['champions']['fomor']['bonus_feeds']===3&&Game::loadBase($pauseD)['champions']['gorgo']['bonus_feeds']===2,'the next ordinary yard read cannot retroactively starve either reserved champion');

    $unusedA=fiPlayer('drull',3);$unusedD=fiPlayer();$battle=BattleService::start($unusedA,$unusedD);$unusedId=$battle['id'];
    $base=Game::loadBase($unusedA);$initial=$base['champions']['drull'];$lockAt=time()-2*86400;
    $base['champions']['drull']['last_heal_at']=$lockAt;$base['champions']['drull']['next_feed_at']=$lockAt+300;$base['champions']['drull']['last_hunger_at']=$lockAt+300;Game::saveBase($unusedA,$base);
    $raw=fiState($unusedId);$raw['created_at']=time()-4*86400;fiSave($unusedId,$raw);
    $timedOut=BattleService::poll($unusedA,$unusedId);$released=Game::loadBase($unusedA)['champions']['drull'];
    fiCheck($timedOut['status']==='finished'&&!$timedOut['result']['champions']['attacker']['deployed'],'offline timeout releases an unused reserved champion');
    fiCheck($released['next_feed_at']===$released['last_heal_at']+300&&$released['last_hunger_at']===$released['last_heal_at']+300,'unused reservation pauses for its actual lock duration rather than a different battle created_at');
    fiCheck($released['bonus_feeds']===3&&fiNear($released['hp'],$initial['hp']),'unused champion retains its earned bonuses and original injury after offline settlement');
    Game::visit($unusedA,$unusedA);fiCheck(Game::loadBase($unusedA)['champions']['drull']['bonus_feeds']===3,'unused reserved champion keeps its bonus on the next ordinary yard read');

    // Construct a genuine old-shape saved snapshot while its base has new earned powers.
    $oldA=fiPlayer('fomor',3);$oldD=fiPlayer();$battle=BattleService::start($oldA,$oldD);$id=$battle['id'];$raw=fiState($id);$raw['formatVersion']=5;
    $oldStats=Catalog::championStats('fomor',6);unset($raw['champion']['combatStats'],$raw['champion']['bonus_feeds']);$raw['champion']['hp']=$oldStats['hp']-123;$raw['champion']['max_hp']=$oldStats['hp'];
    foreach($raw['buildings'] as &$building){unset($building['_fortificationReduction'],$building['fortification'],$building['fortificationReduction'],$building['fortifying'],$building['fortify_ready_at'],$building['fortify_to'],$building['fortify_paid']);}unset($building);fiSave($id,$raw);
    BattleService::deployChampion($oldA,$id,1,1);$raw=fiState($id);$unit=$raw['units'][0];
    fiCheck($unit['bonus_feeds']===0&&fiNear($unit['_damage'],$oldStats['damage'])&&fiNear($unit['max_hp'],$oldStats['hp'])&&fiNear($unit['auraBuff'],.6),'legacy delayed champion deployment never reads newly earned home powers');
    $before=$raw['buildings'][0]['hp'];(new ReflectionMethod(BattleEngine::class,'hurtBuilding'))->invokeArgs(null,[&$raw,0,100.0]);
    fiCheck(fiNear($before-$raw['buildings'][0]['hp'],100),'legacy saved invasion receives no new defense from a fortified home');
    $base=Game::loadBase($oldA);$legacyClock=time()-2*86400;$base['champions']['fomor']['last_heal_at']=$legacyClock;$base['champions']['fomor']['next_feed_at']=$legacyClock+300;$base['champions']['fomor']['last_hunger_at']=$legacyClock+300;Game::saveBase($oldA,$base);
    fiSave($id,$raw);BattleService::retreat($oldA,$id);
    fiCheck(Game::loadBase($oldA)['champions']['fomor']['bonus_feeds']===3,'settling an old invasion does not erase newly stored progression fields');
    $legacy=Game::loadBase($oldA)['champions']['fomor'];
    fiCheck($legacy['next_feed_at']===$legacyClock+300&&$legacy['last_hunger_at']===$legacyClock+300,'format five settlement retains its original unshifted timer behavior');

    echo "Fortification and champion bonus combat database: $checks checks passed.\n";
}finally{if($pdo->inTransaction()){$pdo->rollBack();}}
