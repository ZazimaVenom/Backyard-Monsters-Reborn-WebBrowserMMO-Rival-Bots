<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
ini_set('display_errors','1');
if(!preg_match('/_test$/',(string)getenv('MYSQL_TEST_DATABASE'))){fwrite(STDERR,"Use MYSQL_TEST_* with a disposable test database ending in _test.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER'),'password'=>getenv('MYSQL_TEST_PASSWORD'),'game_speed'=>1];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
if(($argv[1]??'')==='--child'){
    file_put_contents($argv[5],(string)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
    try{$result=match($argv[2]){'start'=>BattleService::startSite((int)$argv[3],(int)$argv[4]),'home'=>BattleService::start((int)$argv[3],(int)$argv[4]),'poll'=>BattleService::poll((int)$argv[3],(int)$argv[4])};echo json_encode(['ok'=>true,'result'=>$result]);}
    catch(Throwable $error){echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);}exit;
}
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;$users=[];$sites=[];$children=[];
function nrCheck(bool $ok,string $message):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$checks;echo "PASS $message\n";}
function nrPlayer(int $q,int $r):int{
    global $users,$sites;$p=Db::pdo();$stmt=$p->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,0,?)');$name='NetworkRaceQA '.bin2hex(random_bytes(5));$stmt->execute([$name,time()]);$id=(int)$p->lastInsertId();$users[]=$id;
    $b=Game::initialBase($id);$b['shield_until']=0;$b['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$b['army']['fink']=5;$b['buildings']=[];
    foreach(['townhall','flinger','maproom'] as $i=>$type){$hp=Game::buildingStats($type,3)['max_hp'];$b['buildings'][]=['id'=>$i+1,'type'=>$type,'x'=>8+$i*8,'y'=>16,'level'=>3,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];}
    $stmt=$p->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$stmt->execute([$id,json_encode($b),time()]);
    $stmt=$p->prepare("INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES('home',?,?,?,?,?,1,NULL,1,?)");$stmt->execute([$q,$r,$id,$id,$name,time()]);$sites[]=(int)$p->lastInsertId();return $id;
}
function nrSite(int $q,int $r,?int $owner=null):int{global $sites;$stmt=Db::pdo()->prepare("INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES('fortification',?,?,?,NULL,'Network concurrency site',1,?,1,?)");$stmt->execute([$q,$r,$owner,json_encode(World::initialState('fortification',1)),time()]);$id=(int)Db::pdo()->lastInsertId();$sites[]=$id;return $id;}
function nrSpawn(string $action,int $user,int $target):int{
    global $children;$file=tempnam(sys_get_temp_dir(),'network-race-');$pipes=[];$command=[PHP_BINARY];
    if($ini=php_ini_loaded_file()){$command[]='-c';$command[]=$ini;}else{
        $command[]='-n';$command[]='-d';$command[]='extension_dir='.ini_get('extension_dir');
        foreach(['pdo','mysqlnd','pdo_mysql','mbstring','ctype'] as $extension){if(is_file(ini_get('extension_dir').'/'.$extension.'.so')){$command[]='-d';$command[]='extension='.$extension.'.so';}}
    }
    $command=array_merge($command,[__FILE__,'--child',$action,(string)$user,(string)$target,$file]);$proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc)){throw new RuntimeException('Cannot start concurrency test worker.');}fclose($pipes[0]);$children[]=['proc'=>$proc,'pipes'=>$pipes,'file'=>$file,'done'=>false];return count($children)-1;
}
function nrWaiting(int $index,string $table):void{
    global $children;$child=$children[$index];$deadline=microtime(true)+6;
    while(microtime(true)<$deadline){if(!proc_get_status($child['proc'])['running']){throw new RuntimeException('Worker exited before lock: '.stream_get_contents($child['pipes'][1]).stream_get_contents($child['pipes'][2]));}$id=(int)file_get_contents($child['file']);if($id){$q=Db::pdo()->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');$q->execute([$id]);$info=(string)$q->fetchColumn();if(str_contains($info,$table)&&str_contains($info,'FOR UPDATE')){return;}}usleep(20000);}
    throw new RuntimeException('Worker did not reach its expected '.$table.' lock.');
}
function nrResult(int $index):array{
    global $children;$child=&$children[$index];$out=stream_get_contents($child['pipes'][1]);$err=stream_get_contents($child['pipes'][2]);fclose($child['pipes'][1]);fclose($child['pipes'][2]);$exit=proc_close($child['proc']);$child['done']=true;unlink($child['file']);
    if($exit!==0){throw new RuntimeException('Concurrency worker failed: '.$err.$out);}return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
try{
    $a=nrPlayer(48,48);$b=nrPlayer(49,48);$d=nrPlayer(50,48);$site=nrSite(48,49);
    $pdo->beginTransaction();$q=$pdo->prepare('SELECT id FROM world_sites WHERE id=? FOR UPDATE');$q->execute([$site]);
    $one=nrSpawn('start',$a,$site);$two=nrSpawn('start',$b,$site);nrWaiting($one,'world_sites');nrWaiting($two,'world_sites');$pdo->commit();$x=nrResult($one);$y=nrResult($two);
    nrCheck((int)$x['ok']+(int)$y['ok']===1,'two concurrent attackers cannot reserve the same neutral Fortification');
    $winner=$x['ok']?$x['result']:$y['result'];$attacker=(int)$winner['attacker']['id'];$battle=(int)$winner['id'];
    $q=$pdo->prepare("SELECT COUNT(*) FROM battles WHERE target_id=? AND target_kind='fortification' AND status='active'");$q->execute([$site]);nrCheck((int)$q->fetchColumn()===1,'neutral Fortification race commits exactly one active battle row');
    nrCheck(Game::loadBase($a)['army']['fink']===5&&Game::loadBase($b)['army']['fink']===5,'a rejected concurrent start cannot consume either army');

    $q=$pdo->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$battle]);$s=json_decode($q->fetchColumn(),true);foreach($s['buildings'] as &$building){if(!$building['_untargetable']){$building['hp']=0;}}unset($building);$s['created_at']=time()-1;$q=$pdo->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($s),$battle]);
    $pdo->beginTransaction();$q=$pdo->prepare('SELECT user_id FROM bases WHERE user_id=? FOR UPDATE');$q->execute([$attacker]);$one=nrSpawn('poll',$attacker,$battle);$two=nrSpawn('poll',$attacker,$battle);nrWaiting($one,'bases');nrWaiting($two,'bases');$pdo->commit();$x=nrResult($one);$y=nrResult($two);
    nrCheck($x['ok']&&$y['ok']&&$x['result']['result']['captured']&&$y['result']['result']['captured'],'concurrent finishing polls both observe the same completed capture');
    nrCheck(Game::user($attacker)['rank_points']===25&&Game::user($attacker)['wins']===1,'concurrent settlement awards victory and rank only once');
    $q=$pdo->prepare('SELECT COUNT(*) FROM reports WHERE battle_id=?');$q->execute([$battle]);nrCheck((int)$q->fetchColumn()===1&&World::load($site)['owner_id']===$attacker,'concurrent settlement writes one report and one ownership transfer');

    $changed=nrSite(49,49);$pdo->beginTransaction();$q=$pdo->prepare('SELECT id FROM world_sites WHERE id=? FOR UPDATE');$q->execute([$changed]);$child=nrSpawn('start',$a,$changed);nrWaiting($child,'world_sites');$q=$pdo->prepare('UPDATE world_sites SET owner_id=?,revision=revision+1 WHERE id=?');$q->execute([$d,$changed]);$pdo->commit();$result=nrResult($child);
    nrCheck(!$result['ok']&&str_contains($result['error'],'changed'),'Fortification owner changing between candidate read and site lock is rejected without locking an unplanned owner');
    $q=$pdo->prepare('SELECT COUNT(*) FROM battles WHERE target_id=?');$q->execute([$changed]);nrCheck((int)$q->fetchColumn()===0,'ownership race leaves no orphan invasion or consumed inventory');

    $owned=nrSite(50,49,$d);$pdo->beginTransaction();$q=$pdo->prepare('SELECT user_id FROM bases WHERE user_id=? FOR UPDATE');$q->execute([$d]);$one=nrSpawn('start',$a,$owned);$two=nrSpawn('home',$b,$d);nrWaiting($one,'bases');nrWaiting($two,'bases');$pdo->commit();$x=nrResult($one);$y=nrResult($two);
    nrCheck((int)$x['ok']+(int)$y['ok']===1,'Fortification attack racing a home invasion respects owner-wide exclusivity');
    $q=$pdo->prepare("SELECT COUNT(*) FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?)");$q->execute([$d,$d]);nrCheck((int)$q->fetchColumn()===1,'owner-wide race commits exactly one battle involving the defender');
    $winner=$x['ok']?$x['result']:$y['result'];BattleService::retreat((int)$winner['attacker']['id'],(int)$winner['id']);
    $base=Game::loadBase($d);$base['shield_until']=0;Game::saveBase($d,$base);$support=nrSite(51,48,$d);$before=World::fortificationSupport($d,50,48);
    $pdo->beginTransaction();$q=$pdo->prepare('SELECT user_id FROM bases WHERE user_id=? FOR UPDATE');$q->execute([$d]);
    $child=nrSpawn('home',$a,$d);nrWaiting($child,'bases');
    World::mutate($d,'worldAbandon',['siteId'=>$support]);$pdo->commit();$result=nrResult($child);$current=World::fortificationSupport($d,50,48);
    nrCheck($result['ok']&&$current['count']===$before['count']-1,'real abandonment finishes under the owner lock before a waiting home invasion snapshots support');
    nrCheck($result['result']['networkDefense']==$current&&!in_array($support,$result['result']['networkDefense']['siteIds'],true),'waiting invasion reads committed Fortification ownership after obtaining participant locks');
    $snapshot=$result['result']['networkDefense'];$battle=(int)$result['result']['id'];
    $q=$pdo->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$battle]);$state=json_decode($q->fetchColumn(),true);
    nrCheck($state['formatVersion']===7&&(float)$state['buildings'][0]['_networkReduction']===(float)$snapshot['reduction'],'network race freezes the authoritative per-building value in format seven');
    $done=BattleService::retreat($a,$battle);nrCheck($done['result']['networkDefense']==$snapshot,'concurrent ownership boundary is retained in the historical battle result');
    echo "Network combat concurrency: $checks checks passed.\n";
}finally{
    if($pdo->inTransaction()){$pdo->rollBack();}
    foreach($children as &$child){if(!$child['done']){proc_terminate($child['proc']);foreach($child['pipes'] as $pipe){if(is_resource($pipe)){fclose($pipe);}}proc_close($child['proc']);@unlink($child['file']);}}unset($child);
    if($users){$list=implode(',',array_map('intval',$users));$pdo->exec('DELETE FROM reports WHERE user_id IN ('.$list.')');$pdo->exec('DELETE FROM battles WHERE attacker_id IN ('.$list.') OR defender_id IN ('.$list.')');$pdo->exec('DELETE FROM world_sites WHERE id IN ('.implode(',',array_map('intval',$sites)).')');$pdo->exec('DELETE FROM bases WHERE user_id IN ('.$list.')');$pdo->exec('DELETE FROM users WHERE id IN ('.$list.')');}
}
