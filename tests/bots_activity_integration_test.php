<?php
declare(strict_types=1);
/** Starts from the real 1,000-bot seed. Only test clocks/due dates are accelerated. */
if (PHP_SAPI!=='cli' || getenv('BYM_BOTS_TEST_RESET')!=='1') { fwrite(STDERR,"Use BYM_BOTS_TEST_RESET=1 with a disposable *_bots_test database.\n"); exit(2); }
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/WorldActivity.php';
require_once __DIR__.'/ActivityFixture.php';
$database=getenv('BYM_BOTS_TEST_DATABASE')?:'backyard_activity_bots_test';
if(!preg_match('/^[A-Za-z0-9_]+_bots_test$/D',$database)){throw new RuntimeException('Expected disposable *_bots_test schema.');}
$config=['host'=>getenv('BYM_BOTS_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('BYM_BOTS_TEST_PORT')?:3306),'database'=>$database,'username'=>getenv('BYM_BOTS_TEST_USER')?:'root','password'=>getenv('BYM_BOTS_TEST_PASSWORD')?:'', 'game_speed'=>1, 'bots'=>['human_target_percent'=>0,'budget_seconds'=>20,'max_active_battles'=>12]];
$server=Db::connect($config,false);$server->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4');$pdo=Db::connect($config);Db::useConnection($pdo,$config);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');foreach(['alliance_war_battles','alliance_wars','alliance_stances','alliance_requests','alliance_members','alliances','keeper_mail','keeper_gifts','keeper_friends','reports','battles','world_sites','bases','users','settings','auth_attempts'] as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}$pdo->exec('SET FOREIGN_KEY_CHECKS=1');$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$checks=0;
function activeBotCheck(bool $value,string $label):void{global$checks;++$checks;if(!$value){throw new RuntimeException('FAIL: '.$label);}}
function activeBotReject(callable $call,string $label):void{try{$call();}catch(RuntimeException){activeBotCheck(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
function activeBotIdentity(PDO $pdo):string{return hash('sha256',json_encode($pdo->query('SELECT id,name,is_bot FROM users WHERE is_bot=1 ORDER BY id')->fetchAll(),JSON_THROW_ON_ERROR));}
function activeBotRows(PDO $pdo):array{return $pdo->query('SELECT id,rank_points,wins,losses,next_turn_at FROM users WHERE is_bot=1 ORDER BY id')->fetchAll();}
function activeBotState(PDO $pdo,int$id):array{$q=$pdo->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);return json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);}
function activeBotSave(PDO $pdo,int$id,array$state):void{$q=$pdo->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($state,JSON_THROW_ON_ERROR),$id]);}
Game::seedBots($pdo,1000);World::initialize($pdo);$pdo->exec("INSERT INTO settings(name,value) VALUES('schema_version','2') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$identity=activeBotIdentity($pdo);$unstarted=activeBotRows($pdo);
activeBotCheck(count($unstarted)===1000,'the actual seed has exactly 1,000 persistent bot accounts');
activeBotCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE rank_points<>0 OR wins<>0 OR losses<>0')->fetchColumn()===0,'every bot begins at zero without fabricated wins');
$baseBefore=$pdo->query('SELECT state_json FROM bases WHERE user_id=1')->fetchColumn();$paused=Bots::tick(100,20);
activeBotCheck($paused['status']==='paused' && $paused['processed']===0 && $paused['battles_advanced']===0,'anonymous world cannot run turns or combat');
activeBotCheck(activeBotRows($pdo)===$unstarted && $pdo->query('SELECT state_json FROM bases WHERE user_id=1')->fetchColumn()===$baseBefore,'idle scheduler preserves rankings deadlines and saved base bytes');
$human=Game::register('Activity Test Keeper','disposable-activity-password');$humanId=(int)$human['id'];
activeBotReject(fn()=>BattleService::botAttack(1,2),'automated raid cannot bypass the zero-player gate');
activeBotCheck(Bots::tick(1,20)['processed']===0,'account existence alone does not mean somebody is online');
activityFixtureWake($humanId);$online=WorldActivity::status();
activeBotCheck($online['onlinePlayers']===1 && $online['mode']==='active','real human presence wakes the world');
// Due-time acceleration does not alter starter armies, buildings, damage or resources.
$pdo->exec('UPDATE users SET next_turn_at=0 WHERE is_bot=1');activityFixtureReleaseCadence();
$first=Bots::tick(100,20);
activeBotCheck($first['processed']>0 && $first['processed']<=100,'one online pulse handles only its bounded due batch');
activeBotCheck($first['built']>0 && $first['trained']>0,'ordinary seeded bots spend real resources to build and hatch');
activeBotCheck($first['attacks']>0 && $first['errors']===[],'unmodified seeded armies launch actual invasions without errors');
$battles=$pdo->query('SELECT id,attacker_id,defender_id FROM battles ORDER BY id')->fetchAll();
activeBotCheck(count($battles)>0 && count($battles)<=12,'configured simultaneous invasion cap is respected');
activeBotCheck((int)$pdo->query('SELECT COUNT(*) FROM battles b JOIN users a ON a.id=b.attacker_id JOIN users d ON d.id=b.defender_id WHERE a.is_bot<>1 OR d.is_bot<>1')->fetchColumn()===0,'organic test raids are real bot-versus-bot battles');
$firstBattle=$battles[0];$battleId=(int)$firstBattle['id'];$attackerId=(int)$firstBattle['attacker_id'];$firstState=activeBotState($pdo,$battleId);
activeBotCheck(count($firstState['units'])>0 && isset($firstState['_activity_clock']),'bot invasion deploys actual units and stores the active-time anchor');
activeBotCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE rank_points<>0')->fetchColumn()===0,'starting an invasion alone never awards rank points');
$afterFirst=activeBotRows($pdo);$again=Bots::tick(100,20);
activeBotCheck($again['status']==='idle' && $again['processed']===0 && $again['battles_advanced']===0,'another client cannot multiply simulation cadence');
activeBotCheck(activeBotRows($pdo)===$afterFirst,'cooldown request changes no rank or bot due time');
WorldActivity::logout($humanId,'activity-fixture-session');$beforePause=activeBotState($pdo,$battleId);$paused=Bots::tick(100,20);
activeBotCheck(WorldActivity::status()['onlinePlayers']===0 && $paused['status']==='paused','last human logout pauses the scheduler immediately');
activeBotCheck($paused['processed']===0 && $paused['battles_advanced']===0 && activeBotState($pdo,$battleId)===$beforePause,'no-player tick leaves persisted invasion unchanged');
$raw=$beforePause;$raw['created_at']=time()-86400;activeBotSave($pdo,$battleId,$raw);$offlinePoll=BattleService::poll($attackerId,$battleId);
activeBotCheck($offlinePoll['elapsed']===$beforePause['elapsed'] && $offlinePoll['status']==='active','one day of wall time cannot advance a paused bot invasion');
activeBotCheck(activeBotState($pdo,$battleId)===$raw,'polling a paused invasion cannot deploy units or change saved combat');
activeBotCheck((int)$pdo->query('SELECT COUNT(*) FROM reports')->fetchColumn()===0,'offline bot world produces no ranking settlements or reports');
activeBotReject(fn()=>BattleService::botAttack(900,901),'new autonomous raids are rejected after logout');
activityFixtureWake($humanId);
$pdo->exec('UPDATE users SET next_turn_at='.(time()+86400).' WHERE is_bot=1');
// Adopt one pre-update saved bot invasion without replaying its offline day.
$legacy=activeBotState($pdo,$battleId);unset($legacy['_activity_clock']);$legacy['created_at']=time()-86400;activeBotSave($pdo,$battleId,$legacy);
$adopted=BattleService::poll($attackerId,$battleId);
activeBotCheck($adopted['elapsed']===$legacy['elapsed'] && $adopted['status']==='active','legacy invasion resumes from saved elapsed without offline catch-up');
foreach($battles as $battle){$state=activeBotState($pdo,(int)$battle['id']);activityFixtureAgeState($state,310);activeBotSave($pdo,(int)$battle['id'],$state);}
$bounded=activityFixtureTick(1,20);$boundedState=activeBotState($pdo,$battleId);
activeBotCheck((float)$boundedState['elapsed']<=20.001,'one pulse does not simulate an entire overdue invasion');
activeBotCheck($bounded['battles_advanced']>0 && $bounded['processed']===0,'online pulse progresses saved combat without fabricating due turns');
$finished=(int)$bounded['battles_finished'];$passes=1;
while((int)$pdo->query("SELECT COUNT(*) FROM battles WHERE status='active'")->fetchColumn()>0 && $passes<20){$result=activityFixtureTick(1,20);activeBotCheck($result['errors']===[],'bounded combat pass '.($passes+1).' has no errors');$finished+=(int)$result['battles_finished'];++$passes;}
activeBotCheck((int)$pdo->query("SELECT COUNT(*) FROM battles WHERE status='active'")->fetchColumn()===0,'ordinary battle engine settles every initial organic raid');
activeBotCheck($finished===count($battles),'scheduler reports each completed invasion once');
activeBotCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1 AND rank_points>0')->fetchColumn()>0,'real bot victories climb the combined rankings from zero');
activeBotCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1 AND rank_points<0')->fetchColumn()>0,'real bot defeats lose rank points');
activeBotCheck((int)$pdo->query('SELECT SUM(wins) FROM users WHERE is_bot=1')->fetchColumn()===count($battles) && (int)$pdo->query('SELECT SUM(losses) FROM users WHERE is_bot=1')->fetchColumn()===count($battles),'every organic bot raid creates exactly one win and one loss');
activeBotCheck((int)$pdo->query('SELECT SUM(rank_points) FROM users WHERE is_bot=1')->fetchColumn()===count($battles)*10,'only normal +25 victory / -15 defeat settlement changes ranks');
activeBotCheck((int)$pdo->query('SELECT COUNT(*) FROM reports')->fetchColumn()===count($battles)*2,'both bots receive ordinary persisted combat reports');
activeBotCheck(activeBotIdentity($pdo)===$identity && (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000,'activity preserves all 1,000 original bot identities');
$ranksBefore=activeBotRows($pdo);BattleService::poll($attackerId,$battleId);BattleService::poll($attackerId,$battleId);
activeBotCheck(activeBotRows($pdo)===$ranksBefore,'repeated completed battle polls cannot duplicate rank awards');
activeBotCheck(Game::user($humanId)['rank_points']===0,'watching the world does not grant the human free rank points');
activeBotCheck(WorldActivity::status()['lastResult']['battles_advanced']>=1,'public activity status shows observed real simulation work');
// A raw shield timestamp may look expired after downtime. Target selection must
// project its paused deadline before deciding this rival can be attacked.
$now=time();$clockEpoch=$now-120;
$q=$pdo->prepare("UPDATE settings SET value=JSON_SET(value,'$.epoch',?,'$.observed_at',?,'$.active_seconds',60) WHERE name=?");$q->execute([$clockEpoch,$now,WorldActivity::SETTING]);
$pdo->exec('UPDATE bases SET state_json=JSON_SET(state_json,\'$.shield_until\','.($now+86400).')');
$targetBase=Game::loadBase(2);$targetBase['shield_until']=$now-30;$targetBase['_activity_clock']=['epoch'=>$clockEpoch,'pausedSeconds'=>0];
$q=$pdo->prepare('UPDATE bases SET state_json=? WHERE user_id=2');$q->execute([json_encode($targetBase,JSON_THROW_ON_ERROR)]);
$findTarget=new ReflectionMethod(Bots::class,'findTarget');
activeBotCheck($findTarget->invoke(null,1,Game::loadBase(1),0,Bots::settings(),[],1001)===null,'bot target selection respects paused shields before launching a raid');
// Database-only scheduler clock test: a single cumulative idle span shifts all
// positive bot deadlines once, preserving zero-due and human deadlines.
$pdo->exec('UPDATE users SET next_turn_at=1234 WHERE is_bot=1');$pdo->exec('UPDATE users SET next_turn_at=0 WHERE id=1');
$q=$pdo->prepare('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');$q->execute(['bots_schedule_clock',json_encode(['epoch'=>700,'pausedSeconds'=>10])]);
$shift=new ReflectionMethod(Bots::class,'shiftSchedule');$shift->invoke(null,['epoch'=>700,'pausedSeconds'=>70]);
activeBotCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1 AND next_turn_at=1294')->fetchColumn()===999,'newly observed idle time shifts every positive bot deadline once');
activeBotCheck((int)$pdo->query('SELECT next_turn_at FROM users WHERE id=1')->fetchColumn()===0 && (int)$pdo->query('SELECT next_turn_at FROM users WHERE id='.$humanId)->fetchColumn()===0,'zero-due markers and human deadlines are preserved');
$shift->invoke(null,['epoch'=>700,'pausedSeconds'=>70]);activeBotCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1 AND next_turn_at=1294')->fetchColumn()===999,'same idle span cannot be applied twice');
$shift->invoke(null,['epoch'=>701,'pausedSeconds'=>900]);activeBotCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1 AND next_turn_at=1294')->fetchColumn()===999,'clock epoch reset cannot replay unrelated past idle time');
$pdo->exec("DELETE FROM settings WHERE name='bots_schedule_clock'");$shift->invoke(null,['epoch'=>702,'pausedSeconds'=>86400]);
activeBotCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1 AND next_turn_at=87694')->fetchColumn()===999,'first online dispatch preserves fresh turn staggering after a cold offline day');
$config['bots']['enabled']=false;Db::useConnection($pdo,$config);$disabled=Bots::tick(100,20);
activeBotCheck($disabled['status']==='paused' && $disabled['activity_reason']==='disabled' && $disabled['processed']===0,'explicit disabled configuration stays inactive even with a human online');
$battleSeconds=[];$attackerWins=0;
foreach($battles as$battle){$state=activeBotState($pdo,(int)$battle['id']);$battleSeconds[]=(float)$state['elapsed'];$attackerWins+=(int)$state['result']['attackerWon'];}
echo json_encode(['suite'=>'bot_activity','assertions'=>$checks,'bots'=>1000,'organic_battles'=>count($battles),'settled'=>$finished,'combat_passes'=>$passes,'first_battle_seconds'=>min($battleSeconds),'last_battle_seconds'=>max($battleSeconds),'attacker_victories'=>$attackerWins,'server'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'time_fixture'=>'only due dates and elapsed online combat clocks accelerated; initial armies/buildings/resources unchanged'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
