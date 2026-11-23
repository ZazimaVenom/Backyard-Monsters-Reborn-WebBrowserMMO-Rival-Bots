<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
ini_set('display_errors','1');
if (!getenv('MYSQL_TEST_DATABASE')) { fwrite(STDERR,"Run with MYSQL_TEST_* variables pointing at a disposable test database.\n"); exit(2); }
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER'),'password'=>getenv('MYSQL_TEST_PASSWORD'),'game_speed'=>1];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$checks=0;
function verify(bool $truth,string $message):void{global $checks;if(!$truth){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function rejected(callable $action,string $message):void{try{$action();}catch(RuntimeException $e){verify(true,$message);return;}throw new RuntimeException('FAIL expected rejection: '.$message);}
function participant(bool $bot=false):int{
 $p=Db::pdo();$q=$p->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,?,?)');$q->execute(['CombatQA '.bin2hex(random_bytes(5)),(int)$bot,time()]);$id=(int)$p->lastInsertId();$base=Game::initialBase($id,$bot);$base['shield_until']=0;$base['buildings']=[['id'=>1,'type'=>'townhall','x'=>16,'y'=>16,'level'=>1,'hp'=>500,'max_hp'=>500,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0]];$base['buildings'][]=['id'=>90,'type'=>'flinger','x'=>4,'y'=>4,'level'=>1,'hp'=>500,'max_hp'=>500,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];$base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$base['army']['fink']=1;$q=$p->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$q->execute([$id,json_encode($base),time()]);return $id;
}
function ageBattle(int $id,int $seconds):void{
 $p=Db::pdo();$q=$p->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);$s=json_decode($q->fetchColumn(),true);activityFixtureAgeState($s,$seconds);$q=$p->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($s),$id]);
}
$pdo->beginTransaction();
try{
 $a=participant();activityFixtureWake($a);$d=participant(true);$other=participant();
 verify(Game::user($a)['rank_points']===0&&Game::user($d)['rank_points']===0,'new human and bot rankings start at zero');
 $battle=BattleService::start($a,$d);$id=$battle['id'];
 verify($battle['duration']===300&&$battle['status']==='active','invasion starts with source-derived 300-second timer');
 rejected(fn()=>BattleService::deploy($other,$id,'fink',1,1),'another account cannot deploy in an owned invasion');
 rejected(fn()=>BattleService::poll($other,$id),'another account cannot read a private battle');
 rejected(fn()=>BattleService::deploy($a,$id,'fink',16,16),'deployment on a building rejected');
 verify(Game::loadBase($a)['army']['fink']===1,'rejected deployment cannot consume a trained monster');
 rejected(fn()=>Game::mutate($d,'collect',[]),'defender yard mutations are blocked during combat');
 rejected(fn()=>BattleService::start($other,$d),'two attackers cannot claim the same defender');
 $battle=BattleService::deploy($a,$id,'fink',16,10);
 verify(count($battle['units'])===1&&Game::loadBase($a)['army']['fink']===0,'legal placement consumes exactly one existing monster');
 rejected(fn()=>BattleService::deploy($a,$id,'fink',16,10),'empty army cannot mint another deployed monster');
 verify(BattleService::poll($d,$id)['id']===$id,'defender can watch the authoritative battle');
 ageBattle($id,310);$finished=BattleService::poll($a,$id);
 verify($finished['status']==='finished'&&$finished['result']['attackerWon'],'server simulation settles victory without a client outcome');
 verify(Game::user($a)['rank_points']===25&&Game::user($d)['rank_points']===-15,'settlement adds winner points and subtracts loser points');
 verify(Game::loadBase($d)['buildings'][0]['hp']==0,'defender building damage survives battle settlement');
 verify(Game::loadBase($a)['resources']['twig']===13200&&Game::loadBase($d)['resources']['twig']===10800,'loot transfers conserved resources between persistent bases');
 $before=Game::user($a);BattleService::poll($a,$id);BattleService::retreat($a,$id);
 verify(Game::user($a)===$before,'duplicate polls and retreat cannot pay ranking rewards twice');
 $q=$pdo->prepare('SELECT COUNT(*) FROM reports WHERE battle_id=?');$q->execute([$id]);verify((int)$q->fetchColumn()===2,'one report is written for each participant, exactly once');
 rejected(fn()=>BattleService::start($other,$d),'post-invasion shield blocks immediate repeat targeting');
 $bot=participant(true);$human=participant();$auto=BattleService::botAttack($bot,$human);
 verify(count($auto['units'])===1&&(float)$auto['elapsed']===0.0,'bot raids deploy real trained monsters and begin at zero active elapsed time');
 ageBattle($auto['id'],310);$auto=BattleService::poll($human,$auto['id']);
 verify(Game::loadBase($human)['shield_until']>=time()+1798,'human attacked by a bot receives 30-minute recovery protection');
 rejected(fn()=>BattleService::botAttack($other,$a),'human account cannot invoke automatic bot deployment');
 // End a still-active invasion after one of two structures has been destroyed.
 $endAttacker=participant();$endDefender=participant(true);$endBase=Game::loadBase($endDefender);
 $endBase['buildings'][0]['x']=24;$endBase['buildings'][0]['y']=24;$endBase['buildings'][0]['hp']=10000;$endBase['buildings'][0]['max_hp']=10000;
 $endBase['buildings']=[$endBase['buildings'][0]];$endBase['buildings'][]=['id'=>2,'type'=>'storage','x'=>16,'y'=>16,'level'=>1,'hp'=>100,'max_hp'=>100,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
 Game::saveBase($endDefender,$endBase);$ending=BattleService::start($endAttacker,$endDefender);
 BattleService::deploy($endAttacker,$ending['id'],'fink',16,10);ageBattle($ending['id'],8);$progress=BattleService::poll($endAttacker,$ending['id']);
 verify($progress['status']==='active'&&(float)$progress['destruction']===50.0,'end-invasion regression reaches victory threshold before the battle ends');
 $ended=BattleService::retreat($endAttacker,$ending['id']);
 verify($ended['result']['attackerWon']&&Game::user($endAttacker)['rank_points']===25&&Game::user($endDefender)['rank_points']===-15,'ending after earned victory settles winning ranks');
 $endRank=Game::user($endAttacker)['rank_points'];BattleService::retreat($endAttacker,$ending['id']);
 verify(Game::user($endAttacker)['rank_points']===$endRank,'repeated end-invasion requests cannot duplicate an earned win');
 echo "Combat database integration: $checks checks passed.\n";
}finally{if($pdo->inTransaction()){$pdo->rollBack();}}
