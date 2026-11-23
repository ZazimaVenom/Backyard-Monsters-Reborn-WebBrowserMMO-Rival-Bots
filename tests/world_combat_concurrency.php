<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
ini_set('display_errors','1');
if(!getenv('MYSQL_TEST_DATABASE')){fwrite(STDERR,"Use MYSQL_TEST_* with a disposable test database.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER'),'password'=>getenv('MYSQL_TEST_PASSWORD'),'game_speed'=>1];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
if(($argv[1]??'')==='--child'){
    file_put_contents($argv[5],(string)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
    try{$result=match($argv[2]){'start'=>BattleService::startSite((int)$argv[3],(int)$argv[4]),'home'=>BattleService::start((int)$argv[3],(int)$argv[4]),'poll'=>BattleService::poll((int)$argv[3],(int)$argv[4])};echo json_encode(['ok'=>true,'result'=>$result]);}
    catch(Throwable $error){echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);}exit;
}
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;$users=[];$sites=[];$children=[];
function wrCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function wrPlayer(int $q,int $r):int{
    global $users,$sites;$p=Db::pdo();$stmt=$p->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,0,?)');$name='WorldRaceQA '.bin2hex(random_bytes(5));$stmt->execute([$name,time()]);$id=(int)$p->lastInsertId();$users[]=$id;
    $b=Game::initialBase($id);$b['shield_until']=0;$b['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$b['army']['fink']=5;$b['buildings']=[];
    foreach(['townhall','flinger','maproom'] as $i=>$type){$hp=Game::buildingStats($type,3)['max_hp'];$b['buildings'][]=['id'=>$i+1,'type'=>$type,'x'=>8+$i*8,'y'=>16,'level'=>3,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];}
    $stmt=$p->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$stmt->execute([$id,json_encode($b),time()]);
    $stmt=$p->prepare("INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES('home',?,?,?,?,?,1,NULL,1,?)");$stmt->execute([$q,$r,$id,$id,$name,time()]);$sites[]=(int)$p->lastInsertId();return $id;
}
function wrSite(int $q,int $r,?int $owner=null):int{global $sites;$stmt=Db::pdo()->prepare("INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES('resource',?,?,?,NULL,'Concurrency site',1,?,1,?)");$stmt->execute([$q,$r,$owner,json_encode(World::initialState('resource',1)),time()]);$id=(int)Db::pdo()->lastInsertId();$sites[]=$id;return $id;}
function wrSpawn(string $action,int $user,int $target):int{
    global $children;$file=tempnam(sys_get_temp_dir(),'world-race-');$pipes=[];$command=[PHP_BINARY];
    if($ini=php_ini_loaded_file()){$command[]='-c';$command[]=$ini;}else{
        $command[]='-n';$command[]='-d';$command[]='extension_dir='.ini_get('extension_dir');
        foreach(['pdo','mysqlnd','pdo_mysql','mbstring','ctype'] as $extension){if(is_file(ini_get('extension_dir').'/'.$extension.'.so')){$command[]='-d';$command[]='extension='.$extension.'.so';}}
    }
    $command=array_merge($command,[__FILE__,'--child',$action,(string)$user,(string)$target,$file]);$proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc)){throw new RuntimeException('Cannot start concurrency test worker.');}fclose($pipes[0]);$children[]=['proc'=>$proc,'pipes'=>$pipes,'file'=>$file,'done'=>false];return count($children)-1;
}
function wrWaiting(int $index,string $table):void{
    global $children;$child=$children[$index];$deadline=microtime(true)+6;
    while(microtime(true)<$deadline){if(!proc_get_status($child['proc'])['running']){throw new RuntimeException('Worker exited before lock: '.stream_get_contents($child['pipes'][1]).stream_get_contents($child['pipes'][2]));}$id=(int)file_get_contents($child['file']);if($id){$q=Db::pdo()->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');$q->execute([$id]);$info=(string)$q->fetchColumn();if(str_contains($info,$table)&&str_contains($info,'FOR UPDATE')){return;}}usleep(20000);}
    throw new RuntimeException('Worker did not reach its expected '.$table.' lock.');
}
function wrResult(int $index):array{
    global $children;$child=&$children[$index];$out=stream_get_contents($child['pipes'][1]);$err=stream_get_contents($child['pipes'][2]);fclose($child['pipes'][1]);fclose($child['pipes'][2]);$exit=proc_close($child['proc']);$child['done']=true;unlink($child['file']);
    if($exit!==0){throw new RuntimeException('Concurrency worker failed: '.$err.$out);}return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
try{
    $a=wrPlayer(48,48);$b=wrPlayer(49,48);$d=wrPlayer(50,48);$site=wrSite(48,49);
    $pdo->beginTransaction();$q=$pdo->prepare('SELECT id FROM world_sites WHERE id=? FOR UPDATE');$q->execute([$site]);
    $one=wrSpawn('start',$a,$site);$two=wrSpawn('start',$b,$site);wrWaiting($one,'world_sites');wrWaiting($two,'world_sites');$pdo->commit();$x=wrResult($one);$y=wrResult($two);
    wrCheck((int)$x['ok']+(int)$y['ok']===1,'two concurrent attackers cannot reserve the same neutral site');
    $winner=$x['ok']?$x['result']:$y['result'];$attacker=(int)$winner['attacker']['id'];$battle=(int)$winner['id'];
    $q=$pdo->prepare("SELECT COUNT(*) FROM battles WHERE target_id=? AND target_kind='resource' AND status='active'");$q->execute([$site]);wrCheck((int)$q->fetchColumn()===1,'neutral-site race commits exactly one active battle row');
    wrCheck(Game::loadBase($a)['army']['fink']===5&&Game::loadBase($b)['army']['fink']===5,'a rejected concurrent start cannot consume either army');

    $q=$pdo->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$battle]);$s=json_decode($q->fetchColumn(),true);foreach($s['buildings'] as &$building){if(!$building['_untargetable']){$building['hp']=0;}}unset($building);$s['created_at']=time()-1;$q=$pdo->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($s),$battle]);
    $pdo->beginTransaction();$q=$pdo->prepare('SELECT user_id FROM bases WHERE user_id=? FOR UPDATE');$q->execute([$attacker]);$one=wrSpawn('poll',$attacker,$battle);$two=wrSpawn('poll',$attacker,$battle);wrWaiting($one,'bases');wrWaiting($two,'bases');$pdo->commit();$x=wrResult($one);$y=wrResult($two);
    wrCheck($x['ok']&&$y['ok']&&$x['result']['result']['captured']&&$y['result']['result']['captured'],'concurrent finishing polls both observe the same completed capture');
    wrCheck(Game::user($attacker)['rank_points']===25&&Game::user($attacker)['wins']===1,'concurrent settlement awards victory and rank only once');
    $q=$pdo->prepare('SELECT COUNT(*) FROM reports WHERE battle_id=?');$q->execute([$battle]);wrCheck((int)$q->fetchColumn()===1&&World::load($site)['owner_id']===$attacker,'concurrent settlement writes one report and one ownership transfer');

    $changed=wrSite(49,49);$pdo->beginTransaction();$q=$pdo->prepare('SELECT id FROM world_sites WHERE id=? FOR UPDATE');$q->execute([$changed]);$child=wrSpawn('start',$a,$changed);wrWaiting($child,'world_sites');$q=$pdo->prepare('UPDATE world_sites SET owner_id=?,revision=revision+1 WHERE id=?');$q->execute([$d,$changed]);$pdo->commit();$result=wrResult($child);
    wrCheck(!$result['ok']&&str_contains($result['error'],'changed'),'owner changing between candidate read and site lock is rejected without locking an unplanned owner');
    $q=$pdo->prepare('SELECT COUNT(*) FROM battles WHERE target_id=?');$q->execute([$changed]);wrCheck((int)$q->fetchColumn()===0,'ownership race leaves no orphan invasion or consumed inventory');

    $owned=wrSite(50,49,$d);$pdo->beginTransaction();$q=$pdo->prepare('SELECT user_id FROM bases WHERE user_id=? FOR UPDATE');$q->execute([$d]);$one=wrSpawn('start',$a,$owned);$two=wrSpawn('home',$b,$d);wrWaiting($one,'bases');wrWaiting($two,'bases');$pdo->commit();$x=wrResult($one);$y=wrResult($two);
    wrCheck((int)$x['ok']+(int)$y['ok']===1,'site attack racing a home invasion respects owner-wide exclusivity');
    $q=$pdo->prepare("SELECT COUNT(*) FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?)");$q->execute([$d,$d]);wrCheck((int)$q->fetchColumn()===1,'owner-wide race commits exactly one battle involving the defender');
    echo "World combat concurrency: $checks checks passed.\n";
}finally{
    if($pdo->inTransaction()){$pdo->rollBack();}
    foreach($children as &$child){if(!$child['done']){proc_terminate($child['proc']);foreach($child['pipes'] as $pipe){if(is_resource($pipe)){fclose($pipe);}}proc_close($child['proc']);@unlink($child['file']);}}unset($child);
    if($users){$list=implode(',',array_map('intval',$users));$pdo->exec('DELETE FROM reports WHERE user_id IN ('.$list.')');$pdo->exec('DELETE FROM battles WHERE attacker_id IN ('.$list.') OR defender_id IN ('.$list.')');$pdo->exec('DELETE FROM world_sites WHERE id IN ('.implode(',',array_map('intval',$sites)).')');$pdo->exec('DELETE FROM bases WHERE user_id IN ('.$list.')');$pdo->exec('DELETE FROM users WHERE id IN ('.$list.')');}
}
