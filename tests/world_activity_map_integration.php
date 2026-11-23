<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE')?:'','username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(PHP_SAPI!=='cli'||!str_ends_with($config['database'],'_test')){throw new RuntimeException('Use an explicitly disposable *_test CLI database.');}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$checks=0;
function mapActivityCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function mapActivityRaw(int $id):string{$q=Db::pdo()->prepare('SELECT state_json FROM bases WHERE user_id=?');$q->execute([$id]);return (string)$q->fetchColumn();}
$pdo->beginTransaction();
try{
    $now=time();$epoch=$now-200;$users=[];$suffix=bin2hex(random_bytes(5));
    $insert=$pdo->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,?,?)');
    foreach(['viewer'=>false,'human'=>false,'bot'=>true] as $name=>$bot){
        $insert->execute(['Activity map '.$name.' '.$suffix,(int)$bot,$epoch]);$users[$name]=(int)$pdo->lastInsertId();
    }
    $pdo->prepare('DELETE FROM settings WHERE name=?')->execute([WorldActivity::SETTING]);
    WorldActivity::initialize($pdo,$epoch);
    WorldActivity::pulse($users['viewer'],'map-fixture-session',str_repeat('m',32),$now-150);
    // Observe beyond the expired lease. A future observation keeps the fixture
    // clock deterministic while the normal projection still handles all timers.
    $gate=WorldActivity::claimTick($now+60);
    mapActivityCheck(!$gate['allowed']&&$gate['pausedSeconds']===215,'expired presence produces an inactive world clock for the map fixture');
    $baseInsert=$pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');
    $homeInsert=$pdo->prepare("INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES('home',?,30,?,?,?,1,NULL,1,?)");
    $homes=[];$x=30;
    foreach($users as $name=>$id){
        $base=Game::initialBase($id,$name==='bot');$base['shield_until']=$now-20;$base['last_tick']=$epoch;
        $base['_activity_clock']=['epoch'=>$epoch,'pausedSeconds'=>0];
        $baseInsert->execute([$id,json_encode($base,JSON_THROW_ON_ERROR),$now]);
        $homeInsert->execute([$x++,$id,$id,'Activity map '.$name,$now]);$homes[$name]=(int)$pdo->lastInsertId();
    }
    $botBefore=mapActivityRaw($users['bot']);$humanBefore=mapActivityRaw($users['human']);
    $map=World::map($users['viewer'],['q'=>31,'r'=>30,'radius'=>3]);$markers=array_column($map['sites'],null,'homeUserId');
    $expected=Game::loadBase($users['bot'])['shield_until'];
    mapActivityCheck($markers[$users['bot']]['shield_until']===$expected&&$expected>$now,'map marker shows the same paused bot shield as the authoritative base');
    mapActivityCheck($markers[$users['human']]['shield_until']===$now-20,'human map shield retains its original real-time expiry');
    mapActivityCheck(mapActivityRaw($users['bot'])===$botBefore&&mapActivityRaw($users['human'])===$humanBefore,'bulk map shield projection does not rewrite another keeper base');
    $detail=World::site($users['viewer'],$homes['bot']);
    mapActivityCheck($detail['site']['shield_until']===$expected&&!$detail['canAttack']&&$detail['attackReason']==='This backyard is protected.','map marker and detailed invasion eligibility agree on the paused bot shield');
    echo "World activity map integration: $checks checks passed.\n";
}finally{if($pdo->inTransaction()){$pdo->rollBack();}}
