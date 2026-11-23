<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Db.php';
require_once __DIR__.'/../app/Alliances.php';
$name = getenv('MYSQL_TEST_DATABASE') ?: '';
if (PHP_SAPI!=='cli' || !preg_match('/^[a-z0-9_]+_alliance_test$/iD',$name)) {
    fwrite(STDERR,"Choose an empty disposable MYSQL_TEST_DATABASE ending in _alliance_test.\n"); exit(2);
}
$config=['database'=>$name,'host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:''];
$db=Db::connect($config);Db::useConnection($db,$config);
if (($argv[1]??'')==='race') {
    echo "ready\n";flush();fgets(STDIN);
    try {Alliances::manage((int)$argv[2],json_decode($argv[3],true,512,JSON_THROW_ON_ERROR));echo "joined\n";}
    catch (PDOException $e) {throw $e;}
    catch (RuntimeException $e) {echo "rejected ".$e->getMessage()."\n";}
    exit;
}
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if ($db->query('SELECT COUNT(*) FROM users')->fetchColumn()!=0) { throw new RuntimeException('Use an empty disposable schema.'); }
$checks=0;
function checkAlliance(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);++$checks;}
function rejectAlliance(callable $work,string $message):void {
    try {$work();}catch(PDOException $e){throw $e;}catch(RuntimeException $e){checkAlliance(str_contains($e->getMessage(),$message),'Expected '.$message.'; got '.$e->getMessage());return;}
    throw new RuntimeException('Expected rejection containing '.$message);
}
function keeper(string $name,int $points=0,int $bot=0):int {
    $db=Db::pdo();$db->prepare('INSERT INTO users(name,rank_points,is_bot,created_at) VALUES (?,?,?,?)')->execute([$name,$points,$bot,time()]); return (int)$db->lastInsertId();
}
function exchange(array $row,string $op):array {return ['op'=>$op,'allianceId'=>(int)$row['alliance_id'],'keeperId'=>(int)$row['user_id'],'requestId'=>(int)$row['id']];}
function rosterAction(array $v,string $op,?int $id=null):array {return ['op'=>$op,'allianceId'=>(int)$v['detail']['id'],'revision'=>(int)$v['detail']['revision'],'keeperId'=>$id];}

/** Two independent PHP processes start their real database writes behind the same barrier. */
function raceAlliances(array $moves):array {
    $workers=[];
    foreach($moves as [$id,$move]){
        $pipes=[];$cmd=[PHP_BINARY];
        if($ini=php_ini_loaded_file()){$cmd[]='-c';$cmd[]=$ini;}
        array_push($cmd,__FILE__,'race',(string)$id,json_encode($move,JSON_THROW_ON_ERROR));
        $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('Cannot launch the concurrency check.');
        $workers[]=[$proc,$pipes];
    }
    foreach($workers as [, $pipes])checkAlliance(trim((string)fgets($pipes[1]))==='ready','race participant ready');
    foreach($workers as [, $pipes]){fwrite($pipes[0],"start\n");fclose($pipes[0]);}
    $out=[];
    foreach($workers as [$proc,$pipes]){
        $out[]=trim(stream_get_contents($pipes[1]));$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        checkAlliance(proc_close($proc)===0 && $errors==='','race subprocess failed '.$errors);
    }
    return $out;
}

$alpha=keeper('Amber',25);$beta=keeper('Birch',-15);$cora=keeper('Cora',50);$rival=keeper('Dorian',18);
$bot=keeper('Copper bot',40,1);$free=keeper('Elm',7);
$create=['op'=>'create','name'=>'  Fern   Watch  ','description'=>'<garden & stone>'];
$a=Alliances::manage($alpha,$create);$aid=$a['mine'];
$opening=AllianceFeed::poll($alpha,$aid);
checkAlliance($opening['member'] && count($opening['feed'])===1 && $opening['feed'][0]['kind']==='created','Real founding emits exactly one private shout');
$outside=AllianceFeed::poll($free,$aid);
checkAlliance(!$outside['member'] && !$outside['feed'] && !Alliances::browse($free,['allianceId'=>$aid])['feed'],'Outsiders receive no private history');
$msg=['op'=>'message','allianceId'=>$aid,'message'=>'<hello> & welcome','key'=>str_repeat('a',32),'after'=>1];
rejectAlliance(fn()=>Alliances::manage($free,$msg),'current members');
$posted=Alliances::manage($alpha,$msg)['feed'];
checkAlliance(count($posted)===2 && $posted[1]['body']==='<hello> & welcome' && !isset($posted[1]['key']),'Member posts plain text and retains private receipt');
checkAlliance(Alliances::manage($alpha,$msg)['feed']===$posted,'Retry posts exactly once');
rejectAlliance(fn()=>Alliances::manage($alpha,array_replace($msg,['message'=>'changed'])),'message changed');
for($i=0;$i<53;++$i){$msg['key']=md5('message-'.$i);$msg['message']='Dispatch '.$i;$msg['after']=$i+2;AllianceFeed::message($alpha,$aid,$msg);}
$recent=AllianceFeed::poll($alpha,$aid)['feed'];
checkAlliance(count($recent)===50 && $recent[0]['body']==='Dispatch 3' && end($recent)['body']==='Dispatch 52','Feed retains the newest 50 messages');
rejectAlliance(fn()=>Alliances::manage($alpha,array_replace($msg,['key'=>str_repeat('c',32),'after'=>0])),'Refresh the alliance feed');

checkAlliance($a['detail']['name']==='Fern Watch' && $a['detail']['description']==='<garden & stone>','escaped-on-render player text survives storage');
checkAlliance((int)$a['detail']['points']===25 && count($a['roster'])===1,'founder appears once with real rank');
rejectAlliance(fn()=>Alliances::manage($alpha,$create),'Leave your current');
rejectAlliance(fn()=>Alliances::manage($bot,$create),'Sign in as a player');
rejectAlliance(fn()=>Alliances::manage($cora,$create),'already taken');
rejectAlliance(fn()=>Alliances::manage($cora,['op'=>'create','name'=>str_repeat('x',31)]),'3 to 30');
rejectAlliance(fn()=>Alliances::manage($cora,['op'=>'create','name'=>"line\nbreak"]),'plain text');
$b=Alliances::manage($rival,['op'=>'create','name'=>'Mist Vale']);$bid=$b['mine'];
$i=Alliances::manage($alpha,['op'=>'invite','allianceId'=>$aid,'keeperId'=>$beta]);
$invite=$i['inbox'][0];
checkAlliance(count(Alliances::manage($alpha,['op'=>'invite','allianceId'=>$aid,'keeperId'=>$beta])['inbox'])===1,'repeated invite creates one open exchange');
checkAlliance(count(Alliances::browse($free,[])['inbox'])===0,'unrelated player cannot see private offers');
rejectAlliance(fn()=>Alliances::manage($free,exchange($invite,'accept')),'Only the keepers');
rejectAlliance(fn()=>Alliances::manage($alpha,exchange($invite,'accept')),'Only the keepers');
$accepted=Alliances::manage($beta,exchange($invite,'accept'));
checkAlliance($accepted['mine']===$aid && (int)$accepted['detail']['points']===10 && !$accepted['inbox'],'accepted invite contributes negative rank');
rejectAlliance(fn()=>Alliances::manage($beta,exchange($invite,'accept')),'no longer pending');
$req=Alliances::manage($cora,['op'=>'request','allianceId'=>$aid])['inbox'][0];
rejectAlliance(fn()=>Alliances::manage($cora,exchange($req,'accept')),'Only the keepers');
checkAlliance(count(Alliances::manage($alpha,exchange($req,'decline'))['inbox'])===0,'leader can decline');
$req2=Alliances::manage($cora,['op'=>'request','allianceId'=>$aid])['inbox'][0];
rejectAlliance(fn()=>Alliances::manage($alpha,exchange($req,'accept')),'no longer pending');
checkAlliance(count(Alliances::manage($cora,exchange($req2,'withdraw'))['inbox'])===0,'requester can withdraw');
$req=Alliances::manage($cora,['op'=>'request','allianceId'=>$aid])['inbox'][0];
Alliances::manage($cora,['op'=>'request','allianceId'=>$bid]);
$a=Alliances::manage($alpha,exchange($req,'accept'));
checkAlliance($a['mine']===$aid && count($a['roster'])===3 && !Alliances::browse($cora,[])['inbox'],'joining clears all other invites');
rejectAlliance(fn()=>Alliances::manage($beta,['op'=>'invite','keeperId'=>$bot,'allianceId'=>$aid]),'Only the alliance leader');
rejectAlliance(fn()=>Alliances::manage($alpha,rosterAction($a,'leave')),'Transfer leadership');
$a=Alliances::manage($alpha,['op'=>'invite','keeperId'=>$bot,'allianceId'=>$aid]);
checkAlliance((int)$a['detail']['points']===100 && (int)$a['detail']['members']===4,'real bot joins single-player roster and adds rank');
rejectAlliance(fn()=>Alliances::manage($alpha,rosterAction($a,'transfer',$bot)),'Choose a player');
$stale=rosterAction($a,'kick',$bot);
$a=Alliances::manage($alpha,rosterAction($a,'transfer',$cora));
checkAlliance((int)$a['detail']['leader_id']===$cora && count($a['roster'])===4,'leadership transferred without losing a member');
rejectAlliance(fn()=>Alliances::manage($alpha,rosterAction($a,'transfer',$beta)),'Only the alliance leader');
rejectAlliance(fn()=>Alliances::manage($cora,$stale),'roster changed');
$a=Alliances::manage($cora,rosterAction($a,'kick',$bot));
checkAlliance((int)$a['detail']['points']===60 && (int)$a['detail']['members']===3,'removal recomputes standings');
$a=Alliances::manage($alpha,rosterAction($a,'leave'));
checkAlliance($a['mine']===null && (int)$a['detail']['members']===2,'former leader may leave');
$search=Alliances::browse($free,['search'=>'ist']);
checkAlliance($search['total']===1 && (int)$search['groups'][0]['standing']===2,'filter retains global rank');
checkAlliance(Alliances::browse($free,['search'=>'%'])['total']===0,'literal wildcard search');
$db->prepare('UPDATE users SET rank_points=200 WHERE id=?')->execute([$rival]);
checkAlliance((int)Alliances::browse($rival,[])['detail']['standing']===1,'standings reflect later battles live');
$recruit=fn(int $g,int $u):array=>['op'=>'invite','allianceId'=>$g,'keeperId'=>$u];
$out=raceAlliances([[$cora,$recruit($aid,$bot)],[$rival,$recruit($bid,$bot)]]);
checkAlliance(count(array_filter($out,fn($x)=>$x==='joined'))===1,'only one alliance acquires a contested bot');
$pick=Alliances::browse($cora,[]);$room=49-(int)$pick['detail']['members'];
for($n=0;$n<$room;$n++)Alliances::manage($cora,$recruit($aid,keeper('Fixture bot '.$n,0,1)));
$out=raceAlliances([[$cora,$recruit($aid,keeper('Last bot A',0,1))],[$cora,$recruit($aid,keeper('Last bot B',0,1))]]);
checkAlliance(count(array_filter($out,fn($x)=>$x==='joined'))===1 && (int)Alliances::browse($cora,[])['detail']['members']===50,'final slot cannot overfill');
$single=Alliances::manage($free,['op'=>'create','name'=>'Last Garden']);$sid=$single['mine'];
Alliances::manage($alpha,['op'=>'request','allianceId'=>$sid]);
$gone=Alliances::manage($free,rosterAction($single,'leave'));
checkAlliance($gone['mine']===null && Alliances::browse($alpha,['allianceId'=>$sid])['detail']===null && !Alliances::browse($alpha,[])['inbox'],'last leader disbands, pending requests cleared');
echo $checks." alliance integration checks passed.\n";
