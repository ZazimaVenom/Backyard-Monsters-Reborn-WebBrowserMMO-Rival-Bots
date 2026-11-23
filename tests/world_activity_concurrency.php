<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/WorldActivity.php';
$checks=0;
function check(bool $ok,string $label):void{global$checks;++$checks;if(!$ok){throw new RuntimeException('FAIL: '.$label);}}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE')?:'backyard_activity_test','username'=>getenv('MYSQL_TEST_USER')?:'backyard_test','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(!str_ends_with($config['database'],'_activity_test')){throw new RuntimeException('Use an explicitly disposable *_activity_test database.');}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
if(($argv[1]??'')==='--child'){
    try{
        [$script,$mode,$operation,$user,$session,$client,$at,$barrier]=$argv;
        file_put_contents($barrier.'.ready.'.getmypid(),'ready');
        $deadline=microtime(true)+8;while(!is_file($barrier)&&microtime(true)<$deadline){usleep(10000);}
        if(!is_file($barrier)){throw new RuntimeException('Parent race barrier did not open.');}
        $result=match($operation){'pulse'=>WorldActivity::pulse((int)$user,$session,$client,(int)$at),'logout'=>WorldActivity::logout((int)$user,$session,(int)$at),'claim'=>WorldActivity::claimTick((int)$at),default=>throw new RuntimeException('Bad child operation')};
        echo json_encode($result,JSON_THROW_ON_ERROR),PHP_EOL;exit(0);
    }catch(Throwable$error){fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
}
function race(array $jobs,int $at):array{
    global$config,$pdo;
    $barrier=sys_get_temp_dir().'/bym-activity-'.bin2hex(random_bytes(10));$children=[];
    $lock='bym:presence:'.substr(hash('sha256',$config['database']),0,32);
    $q=$pdo->prepare('SELECT GET_LOCK(?,2)');$q->execute([$lock]);check((int)$q->fetchColumn()===1,'parent establishes forced presence race');
    try{
        foreach($jobs as[$operation,$user,$session,$client]){
            $command=[PHP_BINARY];
            if($ini=php_ini_loaded_file()){$command[]='-c';$command[]=$ini;}else{
                $command[]='-n';$command[]='-d';$command[]='extension_dir='.ini_get('extension_dir');
                foreach(['pdo','mysqlnd','pdo_mysql','mbstring','ctype']as$extension){if(is_file(ini_get('extension_dir').'/'.$extension.'.so')){$command[]='-d';$command[]='extension='.$extension.'.so';}}
            }
            $command=array_merge($command,[__FILE__,'--child',$operation,(string)$user,$session,$client,(string)$at,$barrier]);
            $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null);
            if(!is_resource($process)){throw new RuntimeException('Cannot create race child.');}fclose($pipes[0]);$children[]=[$process,$pipes];
        }
        $deadline=microtime(true)+6;while(count(glob($barrier.'.ready.*'))<count($jobs)&&microtime(true)<$deadline){usleep(10000);}
        check(count(glob($barrier.'.ready.*'))===count($jobs),'both real processes reach the race barrier');
        file_put_contents($barrier,'go');usleep(100000);
    }finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
    $results=[];
    try{
        foreach($children as[$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);check($exit===0,'race child completes: '.$err);$results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);}
    }finally{foreach(glob($barrier.'*')as$file){unlink($file);}}
    return$results;
}

$owned=[];$prior=null;
try{
    foreach(explode(';',file_get_contents(__DIR__.'/../app/schema.sql'))as$sql){if(trim($sql)!==''){$pdo->exec($sql);}}
    $q=$pdo->prepare('SELECT value FROM settings WHERE name=?');$q->execute([WorldActivity::SETTING]);$prior=$q->fetchColumn();
    $pdo->prepare('DELETE FROM settings WHERE name=?')->execute([WorldActivity::SETTING]);
    $q=$pdo->prepare('INSERT INTO users(name,password_hash,is_bot,created_at) VALUES(?,NULL,0,?)');$now=time();$suffix=bin2hex(random_bytes(4));
    foreach(['A','B']as$name){$q->execute(['Presence race '.$name.' '.$suffix,$now]);$owned[]=(int)$pdo->lastInsertId();}[$a,$b]=$owned;
    $results=race([['pulse',$a,'session-a',str_repeat('a',32)],['pulse',$b,'session-b',str_repeat('b',32)]],$now);
    $status=WorldActivity::status($now);check($status['onlinePlayers']===2&&$status['onlineTabs']===2,'simultaneous first heartbeats retain both independent players');
    check(WorldActivity::clock($now)['activeSeconds']===0,'same-instant heartbeats cannot create extra world time');
    $results=race([['claim',$a,'session-a',str_repeat('a',32)],['claim',$b,'session-b',str_repeat('b',32)]],$now+5);
    check(count(array_filter($results,fn($r)=>$r['allowed']))===1,'concurrent requests dispatch exactly one shared simulation tick');
    check(count(array_filter($results,fn($r)=>$r['reason']==='cooldown'))===1,'the other scheduler receives the shared cooldown');
    check(WorldActivity::clock($now+5)['activeSeconds']===5,'racing scheduler claims accrue shared time only once');
    race([['logout',$a,'session-a',str_repeat('a',32)],['pulse',$b,'session-b',str_repeat('b',32)]],$now+10);
    $status=WorldActivity::status($now+10);check($status['onlinePlayers']===1&&$status['onlineTabs']===1,'logout and another player heartbeat cannot erase the remaining player');
    check(WorldActivity::clock($now+100)['activeSeconds']===55,'after the final connection expires no race extends activity beyond its TTL');
    $results=race([['claim',$a,'session-a',str_repeat('a',32)],['claim',$b,'session-b',str_repeat('b',32)]],$now+100);
    check(count(array_filter($results,fn($r)=>$r['allowed']))===0,'concurrent CLI and browser dispatch attempts both stop without players');
    check($results[0]['reason']==='waiting'&&$results[1]['reason']==='waiting','empty-world scheduler state is consistently waiting');
    echo "World activity concurrency: $checks assertions passed.\n";
}catch(Throwable$error){fwrite(STDERR,(string)$error.PHP_EOL);exit(1);}
finally{
    if($prior!==null){$q=$pdo->prepare('DELETE FROM settings WHERE name=?');$q->execute([WorldActivity::SETTING]);if($prior!==false){$q=$pdo->prepare('INSERT INTO settings(name,value) VALUES(?,?)');$q->execute([WorldActivity::SETTING,$prior]);}}
    if($owned){$q=$pdo->prepare('DELETE FROM users WHERE id IN ('.implode(',',array_fill(0,count($owned),'?')).')');$q->execute($owned);}
}
