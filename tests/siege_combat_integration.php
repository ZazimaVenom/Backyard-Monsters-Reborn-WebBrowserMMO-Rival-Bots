<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$db=(string)getenv('MYSQL_TEST_DATABASE');
if(PHP_SAPI!=='cli'||!str_ends_with($db,'_test')){fwrite(STDERR,"Use an empty MYSQL_TEST_DATABASE ending in _test.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),
    'database'=>$db,'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$pdo=Db::connect($config);
if ($pdo->query('SHOW TABLES')->fetch()) throw new RuntimeException('Choose an empty disposable database.');
Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$checks=0;
function sgAssert(bool $condition,string $reason):void {global $checks;if(!$condition)throw new RuntimeException($reason);++$checks;}
function sgReject(callable $fn,string $why):void {try{$fn();}catch(RuntimeException $error){sgAssert(str_contains($error->getMessage(),$why),$error->getMessage());return;}throw new RuntimeException('Expected '.$why);}
function sgAge(int $id,int $seconds):void {
    $p=Db::pdo();$q=$p->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);$s=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $s['created_at']=time()-$seconds;$p->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($s,JSON_THROW_ON_ERROR),$id]);
}
function sgEquip(int $attacker,int $defender,string $type):void{
    $a=Game::loadBase($attacker);$a['siege']['inventory']=[$type=>1];$a['siege']['levels'][$type]=1;
    if(!array_filter($a['buildings'],fn($b)=>$b['type']==='siegefactory')){
        $hp=Game::buildingStats('siegefactory',1)['max_hp'];
        $a['buildings'][]=['id'=>$a['next_building_id']++,'type'=>'siegefactory','level'=>1,'hp'=>$hp,'max_hp'=>$hp,'x'=>25,'y'=>25,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
    }
    $d=Game::loadBase($defender);$d['shield_until']=0;Game::saveBase($attacker,$a);Game::saveBase($defender,$d);
}
$pdo->beginTransaction();
try{
    $a=Game::register('Siege keeper','test-password-for-siege')['id'];$d=Game::register('Siege rival','test-password-for-siege')['id'];
    $key=str_repeat('f',20);
    foreach(['jars','decoy','vacuum'] as $device){
        sgEquip($a,$d,$device);
        $beforeA=Game::loadBase($a);$beforeD=Game::loadBase($d);
        $b=BattleService::start($a,$d);$id=$b['id'];
        sgAssert($b['siege']['type']===$device && !$b['siege']['used'] && !isset(BattleService::poll($d,$id)['siege']),'Prepared loadout hidden from defender');
        sgReject(fn()=>BattleService::siege($d,$id,$device,10,10,$key),'cannot control');
        if($device==='jars')sgReject(fn()=>BattleService::siege($a,$id,$device,1,1,$key),'living defense tower');
        if($device==='decoy')sgReject(fn()=>BattleService::siege($a,$id,$device,16,16,$key),'clear ground');
        sgAssert(Game::loadBase($a)['siege']['inventory'][$device]===1,'Rejected activations preserve stock');
        [$x,$y]=match($device){'jars'=>[9,13],'decoy'=>[9,15],default=>[16,16]};
        $fire=BattleService::siege($a,$id,$device,$x,$y,$key);
        sgAssert($fire['siege']['used'] && Game::loadBase($a)['siege']['inventory'][$device]===0,'Real inventory consumed');
        sgAssert(BattleService::siege($a,$id,$device,$x,$y,$key)['siege']['used'],'Network retry safely replays');
        sgReject(fn()=>BattleService::siege($a,$id,$device,$x,$y,str_repeat('g',20)),'already been');
        $r=BattleService::poll($d,$id);
        sgAssert(isset($r['siege']['active']) && !isset($r['siege']['_request']),'Defender sees public deployed effect');
        sgAge($id,12);
        $active=BattleService::poll($a,$id);
        $end=BattleService::retreat($a,$id);$result=$end['result'];
        sgAssert($result['siege']['type']===$device && $result['siege']['level']===1 && $end['siege']['active']===null,'Device logged and cleared');
        if($device==='vacuum'){
            sgAssert(array_sum($result['siege']['siphoned'])>0,'Vacuum transfers real banked resources');
            $afterA=Game::loadBase($a);$afterD=Game::loadBase($d);
            foreach($result['loot'] as $resource=>$count){
                sgAssert($afterA['resources'][$resource]==$beforeA['resources'][$resource]+$count
                    && $afterD['resources'][$resource]==$beforeD['resources'][$resource]-$count,'Each transfer conserves both banks');
            }
        }
        sgAssert(BattleService::retreat($a,$id)['result']==$result,'Settlement retry cannot repay loot');
    }
    sgEquip($a,$d,'vacuum');
    $c=BattleService::startTribe($a,1,['requestId'=>str_repeat('c',24),'previousId'=>null]);
    sgReject(fn()=>BattleService::siege($a,$c['id'],'vacuum',16,16,$key),'no independent stockpile');
    BattleService::retreat($a,$c['id']);
    sgAssert(Game::loadBase($a)['siege']['inventory']['vacuum']===1,'Unfired device returns intact');
    sgAssert(count(Game::reports($d)['items'])===3,'All real defenders retain their three ranked reports');
}finally{$pdo->rollBack();}
echo "Siege live combat: $checks checks passed.\n";
