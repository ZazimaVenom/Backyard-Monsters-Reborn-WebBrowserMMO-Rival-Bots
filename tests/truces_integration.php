<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

if (PHP_SAPI !== 'cli' || !str_ends_with((string)getenv('MYSQL_TEST_DATABASE'), '_test')) {
    fwrite(STDERR, "Use an empty disposable MYSQL_TEST_DATABASE ending in _test.\n"); exit(2);
}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),
    'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER')?:'root',
    'password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$db=Db::connect($config);Db::useConnection($db,$config);
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$checks=0;
function trCheck(bool $ok,string $message): void {
    global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks;
}
function trReject(callable $fn,string $contains): void {
    try { $fn(); } catch (RuntimeException $e) { trCheck(stripos($e->getMessage(),$contains)!==false,$e->getMessage()); return; }
    throw new RuntimeException('Expected rejection: '.$contains);
}
function trKeeper(bool $bot=false): int {
    $db=Db::pdo();$name='Truce '.bin2hex(random_bytes(5));
    $db->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,?,?)')->execute([$name,(int)$bot,time()]);
    $id=(int)$db->lastInsertId();$base=Game::initialBase($id,$bot);$base['shield_until']=0;
    $db->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($base),time()]);
    return $id;
}
function trData(int $id): array {
    $q=Db::pdo()->prepare('SELECT state_json FROM bases WHERE user_id=?');$q->execute([$id]);
    return json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
}
function trAction(int $from,int $to,string $kind,string $token,string $previous=''): array {
    $result=Truces::act($from,['keeper'=>$to,'kind'=>$kind,'token'=>$token,'previous'=>$previous]);
    return array_column($result['keepers'],null,'keeper')[$to];
}
$db->beginTransaction();
try {
    $a=trKeeper();$b=trKeeper();$c=trKeeper();$token=bin2hex(random_bytes(16));
    $old=trData($b);
    trCheck(Truces::view($a)['keepers']===[],'Fresh world starts without agreements');
    trReject(fn()=>trAction($a,$a,'request',$token),'valid keeper');
    $offer=trAction($a,$b,'request',$token);
    trCheck($offer['status']==='requested'&&$offer['outgoing'],'Sender sees pending offer');
    trCheck(!Truces::view($b)['keepers'][0]['outgoing']&&Truces::view($c)['keepers']===[],'Offer visible only to receiver and sender');
    trCheck(trAction($a,$b,'request',$token)===$offer,'Duplicate request returns same agreement');
    trReject(fn()=>trAction($b,$a,'request',bin2hex(random_bytes(16))),'changed');
    trReject(fn()=>trAction($a,$b,'accept',$token),'recipient');trReject(fn()=>trAction($b,$a,'withdraw',$token),'sender');
    $battle=BattleService::start($a,$b);
    trReject(fn()=>trAction($b,$a,'accept',$token),'finish the invasion');
    BattleService::retreat($a,$battle['id']);
    $yard=Game::loadBase($b);$yard['shield_until']=0;Game::saveBase($b,$yard);
    $pending=trAction($a,$b,'withdraw',$token);
    trCheck($pending['status']==='withdrawn'&&Truces::view($b)['keepers']===[],'Withdrawal clears both pending lists');
    trReject(fn()=>trAction($b,$a,'accept',$token),'pending');
    $second=bin2hex(random_bytes(16));trAction($b,$a,'request',$second,$token);
    trCheck(trAction($a,$b,'decline',$second)['status']==='declined','Only receiver can decline');
    $third=bin2hex(random_bytes(16));trAction($a,$b,'request',$third,$second);
    trReject(fn()=>trAction($b,$a,'accept',$token),'replaced');
    $now=time();$before=[trData($a),trData($b)];
    $accepted=trAction($b,$a,'accept',$third);
    trCheck($accepted['expires']>=$now+Truces::DURATION&&$accepted['expires']<=time()+Truces::DURATION,'Fourteen real days begin at acceptance');
    trCheck(trAction($b,$a,'accept',$third)===$accepted,'Accept retry never renews the expiry');
    $after=[trData($a),trData($b)];
    trCheck($after[0]['truces'][$b]===$after[1]['truces'][$a],'Both sides store the same agreement');
    foreach ([0,1] as $index) {unset($after[$index]['truces'],$before[$index]['truces']);}
    trCheck($before===$after,'Diplomacy writes preserve every other yard field');
    trCheck(Truces::activeUntil(trData($a),$b,$accepted['expires']-1)>0&&Truces::activeUntil(trData($a),$b,$accepted['expires'])===0,'Expiry uses the exact instant');
    trReject(fn()=>trAction($a,$b,'withdraw',$third),'pending');
    trReject(fn()=>BattleService::start($a,$b),'truce');trReject(fn()=>BattleService::start($b,$a),'truce');
    $siteQ=$db->prepare("INSERT INTO world_sites(kind,q,r,owner_id,name,state_json,updated_at) VALUES('resource',6,6,?,'Truce territory',?,?)");
    $siteQ->execute([$b,json_encode(World::initialState('resource',1)),time()]);$site=(int)$db->lastInsertId();
    trReject(fn()=>BattleService::startSite($a,$site),'truce');
    trCheck(Game::visit($b,$a)['truceUntil']===$accepted['expires'] && Game::visit($b,$c)['truceUntil']===0,'Visits succeed; no third-party agreement disclosure');
    $listing=Game::listing('opponents',$a,1,'','all');$other=array_column($listing['items'],null,'id');
    trCheck($other[$b]['truceUntil']===$accepted['expires'] && $other[$c]['truceUntil']===0,'Invasion directory distinguishes protected and eligible keepers');
    $seen=[];
    for ($i=0; $i<30 && count($seen)<2; ++$i) {
        $bot=trKeeper(true);$profile=Bots::profile($bot);$agrees=$profile!=='raider';
        $result=trAction($a,$bot,'request',bin2hex(random_bytes(16)));
        trCheck($result['status']===($agrees?'accepted':'declined'),'Bot reply follows persistent personality');
        $seen[(int)$agrees]=true;
    }
    trCheck(count($seen)===2,'Both bot response types observed on real accounts');
    trCheck(Truces::view($c)['keepers']===[] && !isset(trData($c)['truces']),'Untouched keeper needs no saved-state migration');
    echo "Truces database: $checks checks passed.\n";
} finally { $db->rollBack(); }
