<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli'||!str_ends_with((string)getenv('MYSQL_TEST_DATABASE'),'_test')) {fwrite(STDERR,"Use a disposable MYSQL_TEST_DATABASE ending in _test.\n");exit(2);}
require_once __DIR__.'/../app/bootstrap.php';
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$db=Db::connect($config);Db::useConnection($db,$config);$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$n=0;
function wi(bool $ok,string $message):void{global $n;if(!$ok)throw new RuntimeException($message);++$n;}
function wblocked(callable $fn,string $text):void{try{$fn();}catch(RuntimeException $e){wi(str_contains($e->getMessage(),$text),$e->getMessage());return;}throw new LogicException('Accepted blocked action');}
function wkeeper(string $name):int{
    $db=Db::pdo();$db->prepare('INSERT INTO users (name,created_at) VALUES (?,0)')->execute([$name]);
    $id=(int)$db->lastInsertId();$b=Game::initialBase($id);$b['shield_until']=0;
    $db->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($b),time()]);World::assignHome($id);return $id;
}
function wrequest(int $event,int $stage,int $run,?string $previous):array{return ['event'=>$event,'stage'=>$stage,'run'=>$run,'previousId'=>$previous,'requestId'=>bin2hex(random_bytes(16))];}
$db->beginTransaction();
try{
    $a=wkeeper('Event Defender');$rival=wkeeper('Rival Keeper');$now=time();
    $b=Game::loadBase($a,true);$army=$b['army'];$starting=$b['resources'];
    // Equal real towers on all four approaches keep this live simulation independent of its random rotation.
    foreach ([[4,4],[28,28],[4,28],[28,4],[10,10],[10,22],[22,10],[22,22]] as $i=>[$x,$y]) {
        $b['buildings'][]=['id'=>$i+60,'type'=>'cannon','x'=>$x,'y'=>$y,'level'=>5,'hp'=>5000,'max_hp'=>5000,'ready_at'=>0];
    }
    Game::saveBase($a,$b);
    wi(!isset(Game::visit($a,$rival)['wmi']),'private calendar hidden on scout');
    $request=wrequest(1,1,0,null);$first=BaiterService::run($a,'wmiStart',$request);$id=$first['session']['id'];
    wi($first['wmi'][1]['run']===1 && $first['wmi'][1]['deadline']>=$now+604800 && $first['wmi'][2]['deadline']===0,'two independent personal calendars');
    wi($first['battle']['wmiEvent']===1 && $first['battle']['wmiStage']===1 && $first['battle']['wave']===['octo'=>5],'first live wave');
    wi(BaiterService::run($a,'wmiStart',$request)['session']['id']===$id,'lost start response reopens same wave');
    wblocked(fn()=>BaiterService::run($a,'wmiStart',array_replace($request,['event'=>2])),'fresh');
    wblocked(fn()=>Game::mutate($a,'repairBase',[]),'wave');
    wblocked(fn()=>BattleService::start($a,$rival),'before attacking');
    $b=Game::loadBase($a,true);$b['baiterPractice']['state']['launch_at']-=600;Game::saveBase($a,$b);
    for($i=0;$i<160;$i++){ $done=BaiterService::run($a,'baiterPoll',['id'=>$id]); if($done['battle']['status']==='finished')break;}
    wi($done['battle']['result']['outcome']==='held' && $done['wmi'][1]['cleared']===1 && $done['wmi'][1]['tier']===1,'working towers defeat real units and earn totem');
    $b=Game::loadBase($a,true);$end=$b;$stored=Decorations::storage($b);
    wi(count($stored)===1 && $stored[0]['item']===121 && $stored[0]['tier']===1 && $b['army']===$army,'custom wave consumes no player attacking stock');
    BaiterService::run($a,'baiterPoll',['id'=>$id]);wi(Game::loadBase($a,true)===$end,'poll and read do not duplicate awards, damage or champion losses');
    wblocked(fn()=>BaiterService::run($a,'wmiStart',wrequest(1,3,1,$id)),'changed');
    $next=BaiterService::run($a,'wmiStart',wrequest(2,1,0,$id));$second=$next['session']['id'];
    wi(count($next['battle']['wave'])===1 && $next['battle']['wave']['spurtz']===10,'Inferno wave defends the surface directly');
    $b=Game::loadBase($a,true);$home=$b['buildings'][0];$b['baiterPractice']['state']['buildings'][0]['hp']-=225;Game::saveBase($a,$b);
    $raid=BattleService::start($rival,$a);
    $b=Game::loadBase($a,true);wi($b['baiterPractice']['state']['result']['outcome']==='dismissed' && $b['wmi'][2]['cleared']===0,'actual raid interrupts a dormant event; no shield or progression');
    wi((float)$b['buildings'][0]['hp']===(float)$home['hp']-225 && (float)$raid['buildings'][0]['hp']===(float)$b['buildings'][0]['hp'],'real invasion begins with settled event damage');
    BattleService::retreat($rival,$raid['id']);
    $repaired=Game::mutate($a,'repairBase',[]);wi($repaired['repairResult']['repaired']>0&&(float)$repaired['base']['buildings'][0]['hp']===(float)$home['max_hp'],'single free repair cleans up after the wave');
    $next=BaiterService::run($a,'wmiStart',wrequest(2,1,1,$second));$second=$next['session']['id'];
    $stop=BaiterService::run($a,'baiterSiren',['id'=>$second]);wi($stop['battle']['result']['outcome']==='dismissed'&&$stop['wmi'][2]['cleared']===0,'siren allows wave retry');
    $b=Game::loadBase($a,true);$b['wmi'][1]['deadline']=$now-1;Game::saveBase($a,$b);
    $repeat=BaiterService::run($a,'wmiStart',wrequest(1,1,1,$second));
    wi($repeat['wmi'][1]['run']===2 && $repeat['wmi'][1]['cleared']===0 && $repeat['wmi'][1]['tier']===1,'expired run restarts independently and keeps earned inventory');
    echo "Wild Monster Invasions database: $n checks passed.\n";
}finally{$db->rollBack();}
