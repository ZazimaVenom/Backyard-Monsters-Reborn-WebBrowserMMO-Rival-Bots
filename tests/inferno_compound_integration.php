<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
if(PHP_SAPI!=='cli'||!str_ends_with((string)getenv('MYSQL_TEST_DATABASE'),'_test')){fwrite(STDERR,"Use a disposable MYSQL_TEST_DATABASE ending in _test.\n");exit(2);}
$config=['database'=>getenv('MYSQL_TEST_DATABASE'),'host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$db=Db::connect($config);Db::useConnection($db,$config);$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function cc(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);++$checks;}
function cave(string $name):int{$id=Game::register($name,'disposable-keeper-password')['id'];$home=Game::loadBase($id);foreach($home['buildings'] as &$b)if($b['type']==='townhall')$b['level']=2;unset($b);Game::saveBase($id,$home);InfernoRealm::act($id,['action'=>'enter','requestId'=>bin2hex(random_bytes(12))]);return $id;}
function advanceClock(int $id):void{$db=Db::pdo();$q=$db->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);$state=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);$state['created_at']-=315;$db->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($state,JSON_THROW_ON_ERROR),$id]);}
$db->beginTransaction();
try{
    $a=cave('Compound Raider');$d=cave('Compound Resident');
    $before=Game::loadBase($d);$army=$before['infernoRealm']['army'];$request=['requestId'=>bin2hex(random_bytes(16)),'previousId'=>null];
    $start=BattleService::startInfernoRival($a,$d,$request);$id=$start['id'];
    cc(!isset($start['_compounds'],$start['_compoundResearch']),'Undispatched native roster stays private');
    $room=array_values(array_filter($start['buildings'],fn($b)=>$b['type']==='housing'))[0];
    cc($room['compoundRange']===12.5,'Working original-tier facility frozen');
    BattleService::deploy($a,$id,['spurtz'=>8],7,15);advanceClock($id);$end=BattleService::poll($d,$id);
    $residents=array_values(array_filter($end['units'],fn($u)=>isset($u['compoundId'])));
    $dead=count(array_filter($residents,fn($u)=>$u['hp']<=0));$loss=$end['result']['compoundLosses']['spurtz']??0;
    cc($end['status']==='finished'&&count($residents)===8&&$dead>0,'Real ranked combat dispatches finite real stock and produces casualties');
    cc($loss===$dead,'Casualties match the released residents that died');
    $after=Game::loadBase($d);cc($after['infernoRealm']['army']['spurtz']===$army['spurtz']-$dead&&$after['army']===$before['army'],'Real native stock reduced, surface stock untouched');
    cc(BattleService::startInfernoRival($a,$d,$request)['result']==$end['result']&&Game::loadBase($d)['infernoRealm']['army']===$after['infernoRealm']['army'],'Report retry cannot duplicate losses');
    $d=cave('Moloch Warden');$initial=Game::loadBase($d)['infernoRealm']['army'];$started=InfernoDefense::run($d,['action'=>'start','requestId'=>bin2hex(random_bytes(12)),'previousId'=>null]);$token=$started['counterattack']['id'];
    $home=Game::loadBase($d);$home['infernoRealm']['counterattack']['state']['launch_at']-=400;Game::saveBase($d,$home);
    for($i=0;$i<60;++$i){$last=InfernoDefense::run($d,['action'=>'poll','id'=>$token]);if($last['battle']['status']==='finished')break;}
    cc($last['battle']['status']==='finished'&&isset($last['battle']['result']['compoundLosses']),'Moloch counterattack returns the settled native ledger');
    $guards=array_filter($last['battle']['units'],fn($u)=>isset($u['compoundId']));cc(count($guards)>0,'Real defenders also intercept the inbound Moloch wave');
    $n=$last['battle']['result']['compoundLosses']['spurtz']??0;$final=Game::loadBase($d)['infernoRealm']['army'];cc($final['spurtz']===$initial['spurtz']-$n,'Native counterattack settlement preserves all survivors');
    InfernoDefense::run($d,['action'=>'poll','id'=>$token]);cc(Game::loadBase($d)['infernoRealm']['army']===$final,'Counterattack replay is idempotent');
    echo "Compound ranked and Moloch database: $checks checks passed.\n";
}finally{$db->rollBack();}
