<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/WorldActivity.php';
$checks=0;
function check(bool $ok,string $label):void{global $checks;++$checks;if(!$ok){throw new RuntimeException('FAIL: '.$label);}}
function reject(callable $work,string $label):void{try{$work();}catch(RuntimeException|TypeError){check(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE')?:'backyard_activity_test','username'=>getenv('MYSQL_TEST_USER')?:'backyard_test','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(!str_ends_with($config['database'],'_activity_test')){throw new RuntimeException('Use an explicitly disposable *_activity_test database.');}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
foreach(explode(';',file_get_contents(__DIR__.'/../app/schema.sql'))as$sql){if(trim($sql)!==''){$pdo->exec($sql);}}
$pdo->beginTransaction();
try{
    $pdo->prepare('DELETE FROM settings WHERE name=?')->execute([WorldActivity::SETTING]);
    $insert=$pdo->prepare('INSERT INTO users(name,password_hash,is_bot,created_at) VALUES(?,NULL,?,?)');$suffix=bin2hex(random_bytes(4));$now=time();
    $insert->execute(['Activity A '.$suffix,0,$now]);$a=(int)$pdo->lastInsertId();$insert->execute(['Activity B '.$suffix,0,$now]);$b=(int)$pdo->lastInsertId();$insert->execute(['Activity Bot '.$suffix,1,$now]);$bot=(int)$pdo->lastInsertId();
    check(WorldActivity::clock($now)['epoch']===0,'uninitialized activity reads have no clock effect');
    check(WorldActivity::status($now)['mode']==='waiting','uninitialized status waits for a human');
    check((int)$pdo->query("SELECT COUNT(*) FROM settings WHERE name='world_activity_v1'")->fetchColumn()===0,'status and clock are read-only even when missing');
    WorldActivity::initialize($pdo,$now-100);
    $q=$pdo->prepare('SELECT value FROM settings WHERE name=?');$q->execute([WorldActivity::SETTING]);$initialized=(string)$q->fetchColumn();
    WorldActivity::initialize($pdo,$now);$q->execute([WorldActivity::SETTING]);check($q->fetchColumn()===$initialized,'repeated initialization preserves the exact existing runtime record');
    $cold=WorldActivity::clock($now);check(!$cold['online']&&$cold['activeSeconds']===0&&$cold['pausedSeconds']===100,'time before the first human arrives is inactive from the install epoch');
    check(WorldActivity::status($now)['lastTick']===null,'initialization dispatches no bots or completed work');
    $pdo->prepare('DELETE FROM settings WHERE name=?')->execute([WorldActivity::SETTING]);
    reject(fn()=>WorldActivity::pulse($bot,'session-a',str_repeat('a',32),$now),'bots cannot keep themselves online');
    reject(fn()=>WorldActivity::pulse(9999999,'session-a',str_repeat('a',32),$now),'unknown accounts cannot create presence');
    foreach(['','short','<script>'.str_repeat('x',20),str_repeat('a',97)]as$bad){reject(fn()=>WorldActivity::pulse($a,'session-a',$bad,$now),'invalid tab identifier rejected');}
    reject(fn()=>WorldActivity::pulse($a,'',str_repeat('a',32),$now),'missing PHP session rejected');
    $s=WorldActivity::pulse($a,'session-a',str_repeat('a',32),$now);
    check($s['mode']==='active'&&$s['onlinePlayers']===1&&$s['onlineTabs']===1,'one human heartbeat wakes the world');
    check($s['leaseSeconds']===45&&$s['pulseSeconds']===5,'browser receives documented lease and cadence');
    $s=WorldActivity::pulse($a,'session-a','12345678-abcd-4321-9876-aabbccddeeff',$now+5);
    check($s['onlinePlayers']===1&&$s['onlineTabs']===2,'two tab leases still count one human');
    $s=WorldActivity::pulse($b,'session-b',str_repeat('a',32),$now+10);
    check($s['onlinePlayers']===2&&$s['onlineTabs']===3,'same tab ID in another session has independent presence');
    $s=WorldActivity::leave($a,'session-a',str_repeat('a',32),$now+12);
    check($s['onlinePlayers']===2&&$s['onlineTabs']===2,'closing one tab keeps other tabs and players active');
    $s=WorldActivity::leave($a,'session-a',str_repeat('b',32),$now+13);
    check($s['onlineTabs']===2,'unknown tab departure cannot evict another lease');
    $s=WorldActivity::logout($a,'session-a',$now+15);
    check($s['onlinePlayers']===1&&$s['onlineTabs']===1,'logout removes only the same user session');
    $clock=WorldActivity::clock($now+100);
    check(!$clock['online']&&$clock['activeSeconds']===55&&$clock['pausedSeconds']===45,'lost connection expires at exact last heartbeat plus TTL');
    $s=WorldActivity::status($now+100);check($s['mode']==='waiting'&&$s['onlinePlayers']===0,'expired players never keep CLI scheduler awake');
    $q=$pdo->prepare('SELECT value FROM settings WHERE name=?');$q->execute([WorldActivity::SETTING]);$rawBefore=(string)$q->fetchColumn();
    WorldActivity::clock($now+200);WorldActivity::status($now+200);$q->execute([WorldActivity::SETTING]);check($q->fetchColumn()===$rawBefore,'clock projections write no settings or locks during game transactions');
    $gate=WorldActivity::claimTick($now+200);check(!$gate['allowed']&&$gate['reason']==='waiting','scheduled worker receives an authoritative no-player gate');
    $s=WorldActivity::pulse($a,'new-session',str_repeat('c',32),$now+300);
    $gate=WorldActivity::claimTick($now+300);check($gate['allowed']&&$gate['reason']==='active'&&$gate['activeSeconds']===55,'returning human resumes clock and immediately permits work');
    check(WorldActivity::claimTick($now+300)['reason']==='cooldown','same-time duplicate tick is throttled');
    check(WorldActivity::claimTick($now+304)['reason']==='cooldown','many players cannot multiply simulation cadence');
    check(WorldActivity::claimTick($now+305)['allowed'],'global cadence permits next real turn');
    WorldActivity::recordTick(['status'=>'ok','processed'=>25,'attacks'=>2,'battles_finished'=>1,'errors'=>[['message'=>'SECRET SQL PASSWORD']],'private'=>'secret'],$now+306);
    $s=WorldActivity::status($now+306);check($s['lastTick']===$now+306&&$s['lastResult']['processed']===25&&$s['lastResult']['battles_finished']===1,'completed work exposes real bounded activity counters');
    check($s['lastResult']['errorCount']===1&&!str_contains(json_encode($s),'SECRET')&&!isset($s['lastResult']['errors'],$s['leases']),'public status contains no internal diagnostics or session leases');
    $q->execute([WorldActivity::SETTING]);$raw=(string)$q->fetchColumn();check(!str_contains($raw,'new-session')&&!str_contains($raw,str_repeat('c',32)),'stored presence hashes session and client identifiers');
    WorldActivity::pulse($a,'another-session',str_repeat('d',32),$now+310);$s=WorldActivity::logout($a,'new-session',$now+311);
    check($s['onlinePlayers']===1&&$s['onlineTabs']===1,'logging out one device does not remove another session for the same human');
    $s=WorldActivity::logout($a,'another-session',$now+312);check($s['mode']==='waiting'&&$s['onlineTabs']===0,'last normal logout pauses immediately without waiting for TTL');
    check(!WorldActivity::claimTick($now+313)['allowed'],'manual worker cannot override the last logout');
    $clock=WorldActivity::clock($now+1000);check($clock['activeSeconds']===67&&$clock['pausedSeconds']===933,'offline time after logout never becomes simulated time');
    WorldActivity::pulse($a,'tab-limit',str_repeat('e',32),$now+1000);
    for($i=1;$i<WorldActivity::MAX_SESSION_LEASES;$i++){WorldActivity::pulse($a,'tab-limit',str_pad((string)$i,32,'0',STR_PAD_LEFT),$now+1000);}
    reject(fn()=>WorldActivity::pulse($a,'tab-limit',str_repeat('f',32),$now+1000),'per-session tab cap bounds presence memory');
    check(WorldActivity::status($now+1000)['onlineTabs']===16,'tab cap rejection preserves existing leases');
    check(WorldActivity::pulse($a,'tab-limit',str_repeat('e',32),$now+1001)['onlineTabs']===16,'an existing tab renews successfully at the cap');
    $disabled=$config;$disabled['bots']=['enabled'=>false];Db::useConnection($pdo,$disabled);
    check(WorldActivity::status($now+1001)['mode']==='disabled'&&WorldActivity::claimTick($now+1001)['reason']==='disabled','explicit world-disable setting cannot be bypassed by player presence');
    Db::useConnection($pdo,$config);
    $pdo->rollBack();echo "World activity integration: $checks assertions passed; temporary data rolled back.\n";
}catch(Throwable$error){if($pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,(string)$error."\n");exit(1);}
