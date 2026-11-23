<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

if(!preg_match('/_test$/',(string)getenv('MYSQL_TEST_DATABASE'))){fwrite(STDERR,"Use MYSQL_TEST_* with a disposable database ending in _test.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER'),'password'=>getenv('MYSQL_TEST_PASSWORD'),'game_speed'=>1];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
if(($argv[1]??'')==='--child'){
    $request=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
    file_put_contents($argv[3],(string)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
    try{
        $result=$request['action']==='attack'
            ?BattleService::start($request['user'],$request['input']['targetId'])
            :Game::mutate($request['user'],$request['action'],$request['input']);
        echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
    }catch(Throwable $error){echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
    exit;
}
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;$users=[];$children=[];
function raceCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function racePlayer():int{
    global $users;$pdo=Db::pdo();$q=$pdo->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,0,?)');$q->execute(['PlannerRace '.bin2hex(random_bytes(5)),time()]);$id=(int)$pdo->lastInsertId();$users[]=$id;
    $base=Game::initialBase($id);$base['buildings']=[];
    foreach([['townhall',16,16,3],['planner',5,5,1],['sniper',8,18,1],['sniper',24,18,1],['flinger',16,5,1]] as $index=>[$type,$x,$y,$level]){
        $hp=Game::buildingStats($type,$level)['max_hp'];$base['buildings'][]=['id'=>$index+1,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0,'fortification'=>0,'fortify_ready_at'=>0,'fortify_to'=>null,'fortify_paid'=>null];
    }
    $base['next_building_id']=6;$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],30000);$base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$base['army']['pokey']=12;$base['shield_until']=0;$base['last_tick']=time();
    $q=$pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$q->execute([$id,json_encode($base,JSON_THROW_ON_ERROR),time()]);
    World::assignHome($id);return $id;
}
function racePayload(array $base,int $buildingId,float $x,float $y):array{
    $positions=Planner::positions($base);foreach($positions as &$node){if($node['id']===$buildingId){$node['x']=$x;$node['y']=$y;}}unset($node);
    return ['positions'=>$positions,'revision'=>Planner::revision($base)];
}
function raceLock(int $id):void{$pdo=Db::pdo();$pdo->beginTransaction();$q=$pdo->prepare('SELECT user_id FROM bases WHERE user_id=? FOR UPDATE');$q->execute([$id]);}
function raceSpawn(int $id,string $action,array $input):int{
    global $children;$request=tempnam(sys_get_temp_dir(),'planner-request-');$ready=tempnam(sys_get_temp_dir(),'planner-ready-');
    file_put_contents($request,json_encode(['user'=>$id,'action'=>$action,'input'=>$input],JSON_THROW_ON_ERROR));
    $command=[PHP_BINARY];
    if($ini=php_ini_loaded_file()){$command[]='-c';$command[]=$ini;}else{
        $command[]='-n';$command[]='-d';$command[]='extension_dir='.ini_get('extension_dir');
        foreach(['pdo','mysqlnd','pdo_mysql','mbstring','ctype'] as $extension){if(is_file(ini_get('extension_dir').'/'.$extension.'.so')){$command[]='-d';$command[]='extension='.$extension.'.so';}}
    }
    $command=array_merge($command,[__FILE__,'--child',$request,$ready]);$pipes=[];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)){throw new RuntimeException('Cannot start Planner concurrency process.');}
    fclose($pipes[0]);$children[]=['process'=>$process,'pipes'=>$pipes,'request'=>$request,'ready'=>$ready,'done'=>false];return count($children)-1;
}
function raceWaiting(int $index):void{
    global $children;$child=$children[$index];$deadline=microtime(true)+8;
    while(microtime(true)<$deadline){
        if(!proc_get_status($child['process'])['running']){throw new RuntimeException('Planner child exited before row lock: '.stream_get_contents($child['pipes'][1]).stream_get_contents($child['pipes'][2]));}
        $connection=(int)file_get_contents($child['ready']);
        if($connection){$q=Db::pdo()->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');$q->execute([$connection]);$info=(string)$q->fetchColumn();if(str_contains($info,'bases')&&str_contains($info,'FOR UPDATE')){return;}}
        usleep(20000);
    }
    throw new RuntimeException('Planner child never reached its expected home-row lock.');
}
function raceResult(int $index):array{
    global $children;$child=&$children[$index];$out=stream_get_contents($child['pipes'][1]);$err=stream_get_contents($child['pipes'][2]);fclose($child['pipes'][1]);fclose($child['pipes'][2]);$exit=proc_close($child['process']);$child['done']=true;unlink($child['request']);unlink($child['ready']);
    if($exit!==0){throw new RuntimeException('Planner child process failed: '.$err.$out);}return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
try{
    $keeper=racePlayer();$attacker=racePlayer();
    $base=Game::loadBase($keeper);$onePayload=racePayload($base,3,9,18);$twoPayload=racePayload($base,3,10,18);$beforeResources=$base['resources'];
    raceLock($keeper);$one=raceSpawn($keeper,'plannerApply',$onePayload);$two=raceSpawn($keeper,'plannerApply',$twoPayload);raceWaiting($one);raceWaiting($two);$pdo->commit();
    $x=raceResult($one);$y=raceResult($two);
    raceCheck((int)$x['ok']+(int)$y['ok']===1,'two actual simultaneous applies with one revision commit exactly one arrangement');
    $failed=$x['ok']?$y:$x;$winner=$x['ok']?$onePayload:$twoPayload;$after=Game::loadBase($keeper);
    raceCheck(str_contains($failed['error'],'changed'),'losing simultaneous draft receives stale-yard explanation');
    raceCheck(Planner::positions($after)===$winner['positions'],'concurrent applications cannot create a mixed layout');
    raceCheck($after['resources']===$beforeResources&&count($after['buildings'])===5,'concurrent move creates no resources or buildings');

    // Commit a real build while a draft request is blocked on the same home row.
    $base=Game::loadBase($keeper);$stale=racePayload($base,4,25,18);$count=count($base['buildings']);
    raceLock($keeper);$child=raceSpawn($keeper,'plannerApply',$stale);raceWaiting($child);
    Game::mutate($keeper,'build',['type'=>'wall','x'=>3,'y'=>12]);$pdo->commit();$result=raceResult($child);$after=Game::loadBase($keeper);
    raceCheck(!$result['ok']&&str_contains($result['error'],'changed'),'waiting draft rechecks inventory after a concurrent committed build');
    raceCheck(count($after['buildings'])===$count+1&&$after['buildings'][count($after['buildings'])-1]['type']==='wall','rejected old plan preserves the real newly paid building');
    $wall=end($after['buildings']);raceCheck($wall['level']===0&&$wall['ready_at']>time(),'concurrent plan cannot finish new construction early');

    // A build must validate against a layout moved into its destination while it waited.
    $after['buildings'][count($after['buildings'])-1]['ready_at']=time()-1;Game::saveBase($keeper,$after);Game::snapshot($keeper);$base=Game::loadBase($keeper);
    $payload=racePayload($base,3,11,18);$resources=$base['resources'];$count=count($base['buildings']);
    raceLock($keeper);$child=raceSpawn($keeper,'build',['type'=>'wall','x'=>11,'y'=>18]);raceWaiting($child);
    Game::mutate($keeper,'plannerApply',$payload);$pdo->commit();$result=raceResult($child);$after=Game::loadBase($keeper);
    raceCheck(!$result['ok']&&str_contains($result['error'],'overlaps'),'waiting build sees committed Planner destinations and rejects overlap');
    raceCheck($after['resources']===$resources&&count($after['buildings'])===$count,'rejected build cannot charge or insert after layout race');
    raceCheck((float)$after['buildings'][2]['x']===11.0,'valid Planner move survives the rejected competing build');

    // The invasion waits for the same row, then snapshots the fully committed geometry.
    $base=Game::loadBase($keeper);$payload=racePayload($base,3,12,18);
    raceLock($keeper);$child=raceSpawn($attacker,'attack',['targetId'=>$keeper]);raceWaiting($child);
    Game::mutate($keeper,'plannerApply',$payload);$pdo->commit();$result=raceResult($child);
    raceCheck($result['ok'],'invasion resumes after an atomic Planner application'.($result['ok']?'':': '.$result['error']));
    $battle=$result['result'];$target=array_values(array_filter($battle['buildings'],fn($building)=>(int)$building['id']===3))[0];
    raceCheck((float)$target['x']===12.0&&(float)$target['y']===18.0,'battle snapshots complete committed layout rather than old or mixed coordinates');
    $before=Game::loadBase($keeper);$blocked=racePayload($before,3,13,18);
    $child=raceSpawn($keeper,'plannerApply',$blocked);$result=raceResult($child);
    raceCheck(!$result['ok']&&str_contains($result['error'],'battle'),'layout cannot change while real invasion is active');
    raceCheck(Planner::positions(Game::loadBase($keeper))===Planner::positions($before),'rejected battle-time apply leaves all battle geometry intact');
    BattleService::retreat($attacker,(int)$battle['id']);

    // Reverse the ordering: start a real battle before releasing a waiting layout request.
    $base=Game::loadBase($keeper);$base['shield_until']=0;Game::saveBase($keeper,$base);$payload=racePayload($base,3,13,18);
    raceLock($keeper);$child=raceSpawn($keeper,'plannerApply',$payload);raceWaiting($child);
    $battle=BattleService::start($attacker,$keeper);$pdo->commit();$result=raceResult($child);
    raceCheck(!$result['ok']&&str_contains($result['error'],'battle'),'waiting Planner rechecks active battle after acquiring the home lock');
    $after=Game::loadBase($keeper);raceCheck((float)$after['buildings'][2]['x']===12.0,'battle-first ordering preserves reserved defender positions');
    $q=$pdo->prepare("SELECT COUNT(*) FROM battles WHERE status='active' AND defender_id=?");$q->execute([$keeper]);
    raceCheck((int)$q->fetchColumn()===1,'battle-first race leaves exactly one valid active reservation');
    BattleService::retreat($attacker,(int)$battle['id']);
    echo "Yard Planner concurrency: $checks checks passed.\n";
}finally{
    if($pdo->inTransaction()){$pdo->rollBack();}
    foreach($children as &$child){if(!$child['done']){proc_terminate($child['process']);foreach($child['pipes'] as $pipe){if(is_resource($pipe)){fclose($pipe);}}proc_close($child['process']);@unlink($child['request']);@unlink($child['ready']);}}unset($child);
    if($users){$ids=implode(',',array_map('intval',$users));$pdo->exec('DELETE FROM reports WHERE user_id IN ('.$ids.')');$pdo->exec('DELETE FROM battles WHERE attacker_id IN ('.$ids.') OR defender_id IN ('.$ids.')');$pdo->exec('DELETE FROM world_sites WHERE home_user_id IN ('.$ids.')');$pdo->exec('DELETE FROM bases WHERE user_id IN ('.$ids.')');$pdo->exec('DELETE FROM users WHERE id IN ('.$ids.')');}
}
