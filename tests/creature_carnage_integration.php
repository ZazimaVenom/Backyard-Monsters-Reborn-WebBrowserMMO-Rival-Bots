<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$name=getenv('MYSQL_TEST_DATABASE')?:'';
if(PHP_SAPI!=='cli'||!preg_match('/^[a-z0-9_]+_carnage_test$/iD',$name)){fwrite(STDERR,"Select an empty MYSQL_TEST_DATABASE ending in _carnage_test.\n");exit(2);}
$config=['database'=>$name,'host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),
    'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$db=Db::connect($config);Db::useConnection($db,$config);
if(($argv[1]??'')==='race'){
    echo "ready\n";flush();fgets(STDIN);
    try{
        $fight=BattleService::startTribe((int)$argv[2],906,['previousId'=>null,'requestId'=>$argv[3],'run'=>1]);
        echo 'ok '.$fight['id'];
    }catch(PDOException $e){throw $e;}catch(RuntimeException $e){echo 'rejected '.$e->getMessage();}
    exit;
}
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if($db->query('SELECT id FROM users LIMIT 1')->fetchColumn())throw new RuntimeException('Use an empty disposable world.');
$checks=0;
function ccheck(bool $pass,string $label):void{global $checks;if(!$pass)throw new RuntimeException($label);++$checks;}
function cdeny(callable $work,string $text):void{
    try{$work();}catch(PDOException $e){throw $e;}catch(RuntimeException $e){ccheck(str_contains($e->getMessage(),$text),$e->getMessage());return;}throw new RuntimeException('Expected rejection '.$text);
}
function corder(?int $prev,int $run=1):array{return ['previousId'=>$prev,'requestId'=>bin2hex(random_bytes(12)),'run'=>$run];}
function elapsedCarnage(int $id,int $seconds):void{
    $db=Db::pdo();$q=$db->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);$s=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $s['created_at']=time()-$seconds;$db->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($s,JSON_THROW_ON_ERROR),$id]);
}
function positionCarnage(array $snapshot):array{
    $hall=array_column($snapshot['buildings'],null,'type')['townhall'];$best=null;$distance=INF;
    for($x=1.;$x<=31;$x+=1)for($y=1.;$y<=31;$y+=1){
        $d=hypot($x-$hall['x'],$y-$hall['y']);if($d<$distance&&Flinger::legalAim($snapshot,$x,$y,3200)){$distance=$d;$best=[$x,$y];}
    }
    return $best;
}

$id=Game::register('Carnage Keeper','disposable-carnage-password')['id'];
$other=Game::register('Independent Keeper','disposable-carnage-password')['id'];
ccheck(Tribes::listing($id)['carnage']['status']==='locked','Town Hall 1 locked');
$base=Game::loadBase($id);$base['buildings'][0]['level']=2;$hadShield=$base['shield_until'];Game::saveBase($id,$base);
$view=Tribes::listing($id)['carnage'];
ccheck($view['score']===0&&$view['eventId']===1&&count($view['camps'])===10&&$view['status']==='available'&&$view['endsAt']===null,'Original ten-yard hunt offered without starting its clock.');
ccheck(Tribes::visit($id,906)['tribe']['run']===1&&!isset(Game::loadBase($id)['creatureCarnage']),'Scouting never starts the run.');
cdeny(fn()=>BattleService::startTribe($id,907,corder(null)),'next open');
cdeny(fn()=>BattleService::startTribe($id,906,corder(null,0)),'Scout');
cdeny(fn()=>BattleService::startTribe($id,906,corder(null)),'Map Room');
$base['buildings'][0]['level']=4;
foreach($base['buildings'] as &$b)if($b['type']==='flinger')$b['level']=5;
unset($b);
foreach(['maproom','catapult','locker','housing'] as $type)$base['buildings'][]=['id'=>$base['next_building_id']++,'type'=>$type,'x'=>6,'y'=>7,'level'=>5,'hp'=>9000,'max_hp'=>9000,'ready_at'=>0,'stored'=>0];
$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],999999);$base['army']['dave']=300;$base['research']['dave']=6;
Game::saveBase($id,$base);
cdeny(fn()=>Game::mutate($id,'unlock',['type'=>'vorg']),'Town Hall level 5');
$old=$base;$old['unlocked'][]='vorg';
ccheck(CreatureCarnage::route($old,time())['status']==='owned','Paid older unlocks preserved.');
$old=$base;$old['unlockQueue'][]=['type'=>'vorg','ready_at'=>time()+500];
ccheck(CreatureCarnage::route($old,time())['status']==='discovering','Paid queues preserved.');
$old=$base;$old['buildings'][0]['level']=5;ccheck(CreatureCarnage::route($old,time())['status']==='locker','Hall 5 fallback.');
$order=corder(null);$fight=BattleService::startTribe($id,906,$order);$previous=$fight['id'];
ccheck($fight['target']['sourceId']===906&&$fight['target']['event']==='carnage'&&abs($fight['target']['endsAt']-time()-518400)<3,'First attack starts a six-day run.');
ccheck(BattleService::startTribe($id,906,$order)['id']===$previous,'Original start retries once.');
$base=Game::loadBase($id);$base['creatureCarnage']['started']-=1800;Game::saveBase($id,$base);
$hall=array_column($fight['buildings'],null,'type')['townhall'];
BattleService::catapult($id,$previous,'twig',1,$hall['x'],$hall['y']);
elapsedCarnage($previous,3);$loss=BattleService::retreat($id,$previous);
ccheck(!$loss['result']['attackerWon']&&$loss['result']['campaign']['score']===0,'Nonlethal real Town Hall hit awards no point.');
$scout=Tribes::visit($id,906);$wounded=array_column($scout['buildings'],null,'type')['townhall'];
ccheck($wounded['hp']===$hall['hp']-300&&isset($scout['tribe']['damageUntil']),'24h saved damage after paid twig strike');
$fresh=CreatureCarnage::camp(Game::loadBase($id),906,$scout['tribe']['damageUntil']);
ccheck(array_column($fresh['eventYard']['buildings'],null,'type')['townhall']['hp']===$hall['hp'],'True 24h repair');
$deadline=$base['creatureCarnage']['started']+518400;
ccheck(CreatureCarnage::route($base,$deadline)['status']==='waiting'&&CreatureCarnage::route($base,$deadline+86400)['run']===2,'Six days then one weekly rest day.');
$old=$base;$old['buildings'][0]['level']=5;
ccheck(CreatureCarnage::discoveryBlocked($old,time())&&!CreatureCarnage::discoveryBlocked($old,$deadline),'Active run wins over Locker bypass.');

foreach(range(906,915) as $stage){
    $order=corder($previous);$fight=BattleService::startTribe($id,$stage,$order);$previous=$fight['id'];
    ccheck($fight['target']['sourceId']===$stage&&$fight['target']['run']===1,'Correct next original yard.');
    $q=$db->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$previous]);$raw=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    [$x,$y]=positionCarnage($raw);
    $deploy=BattleService::deploy($id,$previous,['dave'=>20],$x,$y);
    ccheck(count($deploy['units'])>=20,'Twenty owned monsters launch on selected legal ground.');
    elapsedCarnage($previous,301);
    $done=BattleService::poll($id,$previous);$result=$done['result'];
    ccheck($done['status']==='finished'&&$result['attackerWon']&&!empty($result['hallDestroyed'])&&$result['campaign']['score']===$stage-905,'Real troops destroyed Town Hall '.$stage.'; '.json_encode($result));
    ccheck(BattleService::startTribe($id,$stage,$order)['result']['campaign']===$result['campaign'],'Late retry returns the same settled reward.');
    if($stage<915)cdeny(fn()=>BattleService::startTribe($id,$stage,corder($previous)),'next open');
}
$home=Game::loadBase($id);$final=Tribes::listing($id);
ccheck($final['carnage']['status']==='complete'&&$final['carnage']['score']===10&&$final['cleared']===0&&$result['campaign']['vorg'],'Dedicated 10-point permanent reward');
ccheck(count(array_keys($home['unlocked'],'vorg',true))===1&&$home['shield_until']===$hadShield&&Game::user($id)['rank_points']===0,'No rank, shield or duplicate unlock side effects');
ccheck(Tribes::listing($other)['carnage']['score']===0&&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===2,'Private progress and no ladder opponents');
$home['army']=array_fill_keys(array_keys(Catalog::homeMonsters()),0);Game::saveBase($id,$home);
$trained=Game::mutate($id,'train',['type'=>'vorg','quantity'=>1]);ccheck($trained['queue'][0]['type']==='vorg'&&$trained['queue'][0]['paid_goo']===1000,'Reward makes an actual hatchable flying healer.');
// Two tabs with distinct commands race for the first stage. Only one may start the six-day clock.
$racer=Game::loadBase($other);$racer['buildings'][0]['level']=3;
$racer['buildings'][]=['id'=>$racer['next_building_id']++,'type'=>'maproom','level'=>1,'hp'=>4000,'max_hp'=>4000,'ready_at'=>0,'stored'=>0,'x'=>3,'y'=>9];Game::saveBase($other,$racer);
$children=[];
foreach([1,2] as $n){
    $args=[PHP_BINARY];if($ini=php_ini_loaded_file()){array_push($args,'-c',$ini);}
    array_push($args,__FILE__,'race',(string)$other,bin2hex(random_bytes(12)));
    $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot start race worker.');
    $children[]=[$process,$pipes];
}
foreach($children as [,$pipes])ccheck(trim(fgets($pipes[1]))==='ready','Independent worker ready');
foreach($children as [,$pipes]){fwrite($pipes[0],"go\n");fclose($pipes[0]);}
$out=[];foreach($children as [$proc,$pipes]){
    $out[]=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    ccheck(proc_close($proc)===0&&$errors==='','Race worker succeeded '.$errors);
}
ccheck(count(array_filter($out,fn($line)=>str_starts_with($line,'ok ')))===1&&count(array_filter($out,fn($line)=>str_contains($line,'expedition changed')))===1,'Racing commands start only one battle and charge timer.');
echo "$checks Creature Carnage integration checks passed.\n";
