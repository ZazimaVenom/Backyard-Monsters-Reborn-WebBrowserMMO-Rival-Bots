<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
$dbName=getenv('MYSQL_TEST_DATABASE')?:'';
if(PHP_SAPI!=='cli'||!preg_match('/^[a-z0-9_]+_war_test$/iD',$dbName)){fwrite(STDERR,"Use a disposable empty MYSQL_TEST_DATABASE ending in _war_test.\n");exit(2);}
$config=['database'=>$dbName,'host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$db=Db::connect($config);Db::useConnection($db,$config);
if(($argv[1]??'')==='accept'){
    $payload=json_decode($argv[3],true,512,JSON_THROW_ON_ERROR);
    echo "ready\n";flush();fgets(STDIN);
    try{Alliances::manage((int)$argv[2],$payload);echo "active\n";}
    catch(PDOException $error){throw $error;}
    catch(RuntimeException $error){echo 'blocked '.$error->getMessage()."\n";}
    exit;
}
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()){throw new RuntimeException('Use an empty disposable schema.');}
$checks=0;
function warCheck(bool $good,string $label):void{global $checks;if(!$good){throw new RuntimeException($label);}++$checks;}
function warBlock(callable $work,string $label):void{
    try{$work();}catch(PDOException $e){throw $e;}catch(RuntimeException $e){warCheck(str_contains($e->getMessage(),$label),$e->getMessage());return;}throw new RuntimeException('Expected rejection: '.$label);
}
function warKeeper(string $name,bool $bot=false):int{
    $db=Db::pdo();$db->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,?,?)')->execute([$name,(int)$bot,time()]);$id=(int)$db->lastInsertId();
    $yard=Game::initialBase($id,$bot);$yard['shield_until']=0;$yard['army']['pokey']=20;
    $yard['buildings']=[];
    foreach(['townhall','flinger'] as $i=>$type){$yard['buildings'][]=['id'=>$i+1,'type'=>$type,'x'=>12+$i*5,'y'=>20,'level'=>1,'hp'=>500,'max_hp'=>500,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];}
    $db->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($yard,JSON_THROW_ON_ERROR),time()]);
    return $id;
}
function releaseWarShield(int $id):void{$b=Game::loadBase($id);$b['shield_until']=0;Game::saveBase($id,$b);}
function warOperation(int $actor,string $op,int $own,int $other,int $war=0,?string $stance=null):array{
    return Alliances::manage($actor,compact('op','stance')+['allianceId'=>$own,'otherId'=>$other,'warId'=>$war]);
}
function warRows(int $actor,int $focus):array{return Alliances::browse($actor,['allianceId'=>$focus])['diplomacy']['wars'];}
$a=warKeeper('Elm Guard');$b=warKeeper('Fern Guard');$c=warKeeper('Third Guard');
$botA=warKeeper('Copper Belle',true);$botB=warKeeper('Silver Bell',true);$late=warKeeper('New Hire',true);
$aa=Alliances::manage($a,['op'=>'create','name'=>'Elm folk'])['mine'];$ab=Alliances::manage($b,['op'=>'create','name'=>'Fern folk'])['mine'];
$ac=Alliances::manage($c,['op'=>'create','name'=>'Third league'])['mine'];
Alliances::manage($a,['op'=>'invite','allianceId'=>$aa,'keeperId'=>$botA]);
Alliances::manage($b,['op'=>'invite','allianceId'=>$ab,'keeperId'=>$botB]);
warOperation($a,'stance',$aa,$ab,0,'ally');
warCheck(Alliances::browse($a,['allianceId'=>$ab])['diplomacy']['stance']==='ally'&&Alliances::browse($b,['allianceId'=>$aa])['diplomacy']['stance']==='neutral','stances are unilateral');
warOperation($a,'stance',$aa,$ab,0,'foe');warOperation($a,'stance',$aa,$ab,0,'neutral');
warCheck((int)$db->query('SELECT COUNT(*) FROM alliance_stances')->fetchColumn()===0,'neutral clears a prior flag');
warBlock(fn()=>warOperation($c,'stance',$aa,$ab,0,'ally'),'Only your alliance leader');
warBlock(fn()=>warOperation($a,'warOffer',$aa,$aa),'different alliance');
$w=(int)warOperation($a,'warOffer',$aa,$ab)['diplomacy']['wars'][0]['id'];
warBlock(fn()=>warOperation($a,'warAccept',$aa,$ab,$w),'receiving a challenge');
warBlock(fn()=>warOperation($b,'warOffer',$ab,$aa),'already pending');
warOperation($a,'warWithdraw',$aa,$ab,$w);
warBlock(fn()=>warOperation($b,'warAccept',$ab,$aa,$w),'no longer pending');
$w=(int)warOperation($a,'warOffer',$aa,$ab)['diplomacy']['wars'][0]['id'];warOperation($b,'warDecline',$ab,$aa,$w);
warCheck(warRows($a,$aa)[0]['state']==='declined','decline is persistent');
$w=(int)warOperation($a,'warOffer',$aa,$ab)['diplomacy']['wars'][0]['id'];
$pre=BattleService::start($a,$botB);BattleService::deploy($a,$pre['id'],['pokey'=>1],2,2);
$start=warOperation($b,'warAccept',$ab,$aa,$w)['diplomacy']['wars'][0];
warCheck($start['state']==='active'&&(int)$start['ends_at']-(int)$start['starts_at']===43200,'war opens for 12 hours only after acceptance');
warCheck(count($start['targets'])===2,'participating enemy roster includes real recruited bot');
warBlock(fn()=>warOperation($b,'warAccept',$ab,$aa,$w),'no longer pending');
warBlock(fn()=>warOperation($a,'warOffer',$aa,$ac),'already fighting');
warCheck($pre['duration']===300&&$pre['flinger']['capacity']===250,'battle begun before acceptance retains regular rules');
$pre=BattleService::retreat($a,$pre['id']);warCheck(!isset($pre['result']['allianceWar']),'prewar deployment cannot score retroactively');
releaseWarShield($botB);
$noop=BattleService::start($a,$botB);warCheck($noop['duration']===420&&$noop['flinger']['capacity']===312,'war freezes seven-minute duration and 25% Flinger space');
$noop=BattleService::retreat($a,$noop['id']);warCheck(!isset($noop['result']['allianceWar']),'empty retreat receives no war score');
releaseWarShield($botB);
$real=BattleService::start($a,$botB);BattleService::deploy($a,$real['id'],['pokey'=>2],4,4);$real=BattleService::retreat($a,$real['id']);
warCheck($real['result']['allianceWar']===['id'=>$w,'winnerSide'=>1,'points'=>1],'real troop deployment credits defending bot alliance');
warCheck(Game::user($botB)['rank_points']===75&&Game::user($a)['rank_points']===-45,'normal ranked battles keep their own independent point totals');
BattleService::poll($botB,$real['id']);BattleService::retreat($a,$real['id']);
$score=warRows($a,$aa)[0];warCheck((int)$score['from_score']===0&&(int)$score['to_score']===1,'repeated poll and retreat cannot duplicate war points');
$report=Game::reports($botB)['items'][0];warCheck($report['body']['allianceWar']['id']===$w,'credit appears in real battle report');
Alliances::manage($a,['op'=>'invite','allianceId'=>$aa,'keeperId'=>$late]);
warCheck(AllianceWars::qualify($db,$late,$botB,time())===null,'late recruitment cannot farm an ongoing war');
warCheck(AllianceWars::qualify($db,$a,$c,time())===null&&AllianceWars::qualify($db,$a,0,time())===null,'neutral, unopposed and nonparticipant battles cannot count');
warBlock(fn()=>BattleService::botAttack($botA,$b),'pause');
activityFixtureWake($a);
$botBattle=BattleService::botAttack($botA,$b);
warCheck($botBattle['units']&&$botBattle['duration']===420,'a real scheduler raid deploys a recruited bot’s monsters');
$botBattle=BattleService::retreat($botA,$botBattle['id']);
warCheck($botBattle['result']['allianceWar']['winnerSide']===1,'bot-origin battle credits its actual winner');
releaseWarShield($botB);$closing=BattleService::start($a,$botB);BattleService::deploy($a,$closing['id'],'pokey',3,3);
$db->prepare('UPDATE alliance_wars SET ends_at=? WHERE id=?')->execute([time(),$w]);
$closing=BattleService::retreat($a,$closing['id']);
warCheck(!isset($closing['result']['allianceWar']),'late settlement cannot change the final score');
$finished=warRows($a,$aa)[0];warCheck($finished['state']==='finished'&&(int)$finished['to_score']===2&&$finished['targets']===[],'war closes lazily with persisted score and no remaining eligible raids');
$cwar=(int)warOperation($a,'warOffer',$aa,$ac)['diplomacy']['wars'][0]['id'];
$db->prepare('UPDATE alliance_wars SET ends_at=? WHERE id=?')->execute([time()-1,$cwar]);
warBlock(fn()=>warOperation($c,'warAccept',$ac,$aa,$cwar),'no longer pending');
warCheck(warRows($c,$ac)[0]['state']==='expired','unanswered offer expires');
// Two simultaneous incoming decisions cannot place B into both wars.
$wa=(int)warOperation($a,'warOffer',$aa,$ab)['diplomacy']['wars'][0]['id'];
$wc=(int)warOperation($c,'warOffer',$ac,$ab)['diplomacy']['wars'][0]['id'];
$peers=[];
foreach([[$aa,$wa],[$ac,$wc]] as [$other,$warId]){
    $cmd=[PHP_BINARY];if($ini=php_ini_loaded_file()){$cmd[]='-c';$cmd[]=$ini;}
    $payload=['op'=>'warAccept','allianceId'=>$ab,'otherId'=>$other,'warId'=>$warId];
    array_push($cmd,__FILE__,'accept',(string)$b,json_encode($payload,JSON_THROW_ON_ERROR));
    $pipes=[];$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p)){throw new RuntimeException('Cannot start concurrent acceptance.');}$peers[]=[$p,$pipes];
}
foreach($peers as [, $pipes]){warCheck(trim(fgets($pipes[1]))==='ready','war acceptance worker ready');}
foreach($peers as [, $pipes]){fwrite($pipes[0],"start\n");fclose($pipes[0]);}
$out=[];
foreach($peers as [$p,$pipes]){
    $out[]=trim(stream_get_contents($pipes[1]));$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    warCheck(proc_close($p)===0&&$errors==='','war acceptance completed '.$errors);
}
warCheck(count(array_filter($out,fn($r)=>$r==='active'))===1&&count(array_filter($out,fn($r)=>str_contains($r,'already fighting')))===1,'overlapping acceptances activate exactly one war');
echo "$checks alliance war integration checks passed.\n";
