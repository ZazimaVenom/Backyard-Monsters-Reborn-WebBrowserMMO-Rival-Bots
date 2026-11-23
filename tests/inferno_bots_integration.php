<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
if(PHP_SAPI!=='cli'||!str_ends_with((string)getenv('MYSQL_TEST_DATABASE'),'_test')){fwrite(STDERR,"Use an empty disposable MYSQL_TEST_DATABASE ending in _test.\n");exit(2);}
$config=['database'=>getenv('MYSQL_TEST_DATABASE'),'host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>4,'bots'=>['max_active_battles'=>2,'human_target_percent'=>0,'budget_seconds'=>20]];
$db=Db::connect($config);Db::useConnection($db,$config);
if($db->query('SHOW TABLES')->fetch()){throw new RuntimeException('Use an empty disposable database.');}
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));WorldActivity::initialize($db);Game::seedBots($db,2);
$checks=0;
function ic(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException($message);}++$checks;}
function record(int $id):array{$q=Db::pdo()->prepare('SELECT state_json FROM bases WHERE user_id=?');$q->execute([$id]);return json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);}
function turn(int $id):array {
    $db=Db::pdo();$db->exec('UPDATE users SET next_turn_at=2147000000 WHERE is_bot=1');
    $db->prepare('UPDATE users SET next_turn_at=0 WHERE id=?')->execute([$id]);activityFixtureReleaseCadence();
    $result=Bots::tick(1,20);ic($result['processed']===1&&!$result['errors'],'One clean scheduled bot turn: '.json_encode($result['errors']));
    return $result;
}
function ageCavern(int $id,int $seconds):void {
    $b=Game::loadBase($id);$b['last_tick']-=$seconds;
    foreach($b['buildings'] as &$v){foreach(['ready_at','fortify_ready_at'] as $key){if(($v[$key]??0)>0)$v[$key]-=$seconds;}}unset($v);
    foreach(['queue','researchQueue','unlockQueue'] as $lane){foreach($b[$lane]??[] as $index=>$job){foreach(['started_at','ready_at'] as $key){if(isset($job[$key]))$b[$lane][$index][$key]-=$seconds;}}}
    if(!isset($b['infernoRealm'])){Game::saveBase($id,$b);return;}$r=&$b['infernoRealm'];
    $r['last_tick']-=$seconds;
    foreach($r['buildings'] as &$v){if($v['ready_at']>0)$v['ready_at']-=$seconds;}unset($v);
    foreach($r['queue'] as &$egg){$egg['start']-=$seconds;$egg['ready_at']-=$seconds;}unset($egg);
    foreach(['study','discovery'] as $lane){if(isset($r[$lane]))$r[$lane]['ready_at']-=$seconds;}
    // Only pass time. The production, payment, construction and hatching services do all the work.
    Game::saveBase($id,$b);
}
$a=1;$d=2;
foreach([$a,$d] as $id){$b=Game::loadBase($id);$b['ai']['last_attack_at']=time();Game::saveBase($id,$b);}
ic(Bots::tick(2,20)['status']==='paused'&&!isset(record($a)['infernoRealm']),'No player, no bot cavern');
$human=activityFixtureWake();$tallies=[];
for($i=1;$i<=19;++$i){
    foreach([$a,$d] as $id){
        ageCavern($id,360);$r=turn($id);foreach($r as $key=>$amount)if(str_starts_with($key,'inferno_'))$tallies[$key]=($tallies[$key]??0)+$amount;
        if($i<=4)ic(!isset(record($id)['infernoRealm']),'Surface opening turns stay on the surface');
    }
}
ic($tallies['inferno_opened']===2&&$tallies['inferno_built']>=4&&$tallies['inferno_trained']>=4,'Both bots open, construct and hatch organically: '.json_encode($tallies));
$ra=record($a)['infernoRealm'];$rd=record($d)['infernoRealm'];
$lockA=array_values(array_filter($ra['buildings'],fn($b)=>$b['type']==='locker'))[0];$lockD=array_values(array_filter($rd['buildings'],fn($b)=>$b['type']==='locker'))[0];
ic($lockA['level']>=1&&[$lockA['x'],$lockA['y']]!==[$lockD['x'],$lockD['y']],'Completed lockers have different paid layouts');
ic(count($ra['unlocked'])>=2&&count($rd['unlocked'])>=2&&array_sum($ra['army'])>8,'Real incubators and discovery exceed starter kit');
ic($tallies['inferno_upgraded']>0&&$tallies['inferno_unlocks']>0,'AI upgraded buildings and discovered species through ordinary orders');
foreach($ra['queue'] as $egg)ic($egg['paid']>0&&$egg['ready_at']>$egg['start'],'New egg includes actual cost and start/finish');
$before=record($a);WorldActivity::leave($human,'activity-fixture-session',str_repeat('a',32));
ic(Bots::tick(1,20)['status']==='paused'&&InfernoBots::economy($a,25,2)===[]&&record($a)===$before,'Empty-server tick and direct economy leave the entire saved base untouched');
$clock=WorldActivity::clock();$synthetic=$before;$r=&$synthetic['infernoRealm'];$r['shield_until']=time()+90;
$beforeShift=$r;WorldActivity::stamp($synthetic,$clock);$clock['pausedSeconds']+=600;
ic(WorldActivity::shiftBase($synthetic,$clock)===600&&$r['last_tick']===$beforeShift['last_tick']+600&&$r['shield_until']===$beforeShift['shield_until']+600,'Offline interval shifts production and shield together');
foreach($r['queue'] as $i=>$egg){ic($egg['start']===$beforeShift['queue'][$i]['start']+600&&$egg['ready_at']===$beforeShift['queue'][$i]['ready_at']+600,'Paused incubator preserves remaining time');}
ic(WorldActivity::shiftBase($synthetic,$clock)===0,'Same offline interval cannot shift twice');
activityFixtureWake($human);$aHome=Game::loadBase($a);$aHome['ai']['last_attack_at']=0;Game::saveBase($a,$aHome);
$battle=null;
for($i=0;$i<5&&!$battle;++$i){
    // Keep surface raid chance out of the way so this fixture specifically observes the assigned cavern turn.
    $b=Game::loadBase($a);$next=$b['ai']['turns']+1;$b['ai']['last_attack_at']=(($next+$a)%4===0)?0:time();Game::saveBase($a,$b);
    ageCavern($a,360);$tick=turn($a);
    if($tick['inferno_attacks']>0){$battle=$db->query("SELECT * FROM battles WHERE target_kind='infernoRival' ORDER BY id DESC LIMIT 1")->fetch();}
}
ic((bool)$battle,'Normal resumed scheduler launches a ranked cavern raid');
$state=json_decode($battle['state_json'],true,512,JSON_THROW_ON_ERROR);$id=(int)$battle['id'];
ic($state['attacker']['id']===$a&&$state['defender']['id']===$d&&count($state['units'])>0,'Bot launches real native units into the other persistent cavern');
$native=MonsterExpansion::INFERNO_LOCKERS;
foreach($state['units'] as $unit){ic(isset($native[$unit['type']]),'Only Inferno species deployed');}
$surface=record($d)['buildings'];$shot=false;$final=null;
for($s=10;$s<=330;$s+=10){
    $q=$db->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);$state=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    activityFixtureAgeState($state,$s);$db->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($state),$id]);
    $next=BattleService::poll($a,$id);
    foreach($next['events'] as $event)if(in_array($event['type'],['shot','blast','bolt','chain','rocket','burst','tower'],true))$shot=true;
    if($next['status']==='finished'){$final=$next;break;}
}
ic($final!==null&&$final['result']['infernoRival'],'Battle simulation settles the autonomous raid');
ic($shot,'A cavern defense fires in combat');$u=Game::user($a);$v=Game::user($d);
ic($u['wins']+$v['wins']===1&&$u['losses']+$v['losses']===1&&$u['rank_points']+$v['rank_points']>0,'Cavern battle changes the shared rankings');
ic(record($d)['buildings']===$surface&&record($d)['infernoRealm']['shield_until']>time(),'Only underground yard takes raid damage');
echo "Inferno autonomous economy and ranked raids: $checks checks passed.\n";
