<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
if(!str_ends_with((string)getenv('MYSQL_TEST_DATABASE'),'_construction_test')){fwrite(STDERR,"Use a disposable database ending in _construction_test.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER'),'password'=>getenv('MYSQL_TEST_PASSWORD'),'game_speed'=>1];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
if(($argv[1]??'')==='--child'){
    file_put_contents($argv[5],(string)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
    try{$result=Game::mutate((int)$argv[2],$argv[3],json_decode($argv[4],true,32,JSON_THROW_ON_ERROR));echo json_encode(['ok'=>true,'construction'=>$result['base']['construction']]);}
    catch(Throwable $error){echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);}exit;
}
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;$users=[];$children=[];
function check(bool $ok,string $message):void{global $checks;++$checks;if(!$ok){throw new RuntimeException('FAIL: '.$message);}echo "PASS $message\n";}
function keeper(int $workers=1):int{
    global $users;$id=(int)Game::register('ConstructionRace '.bin2hex(random_bytes(3)),'construction-test-password')['id'];$users[]=$id;$base=Game::loadBase($id);$base['workers']=$workers;$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],1000000);$base['buildings'][0]['level']=5;$base['buildings'][0]['max_hp']=Game::buildingStats('townhall',5)['max_hp'];$base['buildings'][0]['hp']=$base['buildings'][0]['max_hp'];Game::saveBase($id,$base);return $id;
}
function spawn(int $id,string $action,array $input):int{
    global $children;$file=tempnam(sys_get_temp_dir(),'construction-race-');$pipes=[];$command=[PHP_BINARY];
    if($ini=php_ini_loaded_file()){$command[]='-c';$command[]=$ini;}else{
        $command[]='-n';$command[]='-d';$command[]='extension_dir='.ini_get('extension_dir');
        foreach(['pdo','mysqlnd','pdo_mysql','mbstring','ctype'] as $extension){if(is_file(ini_get('extension_dir').'/'.$extension.'.so')){$command[]='-d';$command[]='extension='.$extension.'.so';}}
    }
    $command=array_merge($command,[__FILE__,'--child',(string)$id,$action,json_encode($input,JSON_THROW_ON_ERROR),$file]);
    $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc)){throw new RuntimeException('Cannot start construction race worker.');}fclose($pipes[0]);$children[]=['proc'=>$proc,'pipes'=>$pipes,'file'=>$file,'done'=>false];return count($children)-1;
}
function waiting(int $index):void{
    global $children;$child=$children[$index];$deadline=microtime(true)+6;
    while(microtime(true)<$deadline){if(!proc_get_status($child['proc'])['running']){throw new RuntimeException('Worker exited before lock: '.stream_get_contents($child['pipes'][1]).stream_get_contents($child['pipes'][2]));}$id=(int)file_get_contents($child['file']);if($id){$q=Db::pdo()->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');$q->execute([$id]);$info=(string)$q->fetchColumn();if(str_contains($info,'bases')&&str_contains($info,'FOR UPDATE')){return;}}usleep(20000);}
    throw new RuntimeException('Worker did not reach its expected home-row lock.');
}
function result(int $index):array{
    global $children;$child=&$children[$index];$out=stream_get_contents($child['pipes'][1]);$err=stream_get_contents($child['pipes'][2]);fclose($child['pipes'][1]);fclose($child['pipes'][2]);$exit=proc_close($child['proc']);$child['done']=true;unlink($child['file']);if($exit!==0){throw new RuntimeException('Construction race worker failed: '.$err.$out);}return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
function race(int $id,string $actionA,array $inputA,string $actionB,array $inputB):array{
    $pdo=Db::pdo();$pdo->beginTransaction();$q=$pdo->prepare('SELECT user_id FROM bases WHERE user_id=? FOR UPDATE');$q->execute([$id]);$one=spawn($id,$actionA,$inputA);$two=spawn($id,$actionB,$inputB);waiting($one);waiting($two);$pdo->commit();return [result($one),result($two)];
}
try{
    $id=keeper();$before=Game::loadBase($id);[$a,$b]=race($id,'build',['type'=>'storage','x'=>28,'y'=>28],'build',['type'=>'storage','x'=>28,'y'=>4]);$after=Game::loadBase($id);
    check((int)$a['ok']+(int)$b['ok']===1,'two simultaneous builds cannot overbook one worker');
    check(count($after['buildings'])===count($before['buildings'])+1&&Construction::status($after)['busy']===1,'one race winner creates exactly one paid construction job');
    foreach(Catalog::buildings()['storage']['cost'] as $resource=>$amount){check($after['resources'][$resource]===$before['resources'][$resource]-$amount,'losing build charges no extra '.$resource);}
    check($after['next_building_id']===$before['next_building_id']+1,'losing build does not consume a building identity');

    $id=keeper();$before=Game::loadBase($id);$tower=(int)$before['buildings'][6]['id'];$hatch=(int)$before['buildings'][1]['id'];[$a,$b]=race($id,'fortify',['id'=>$tower],'upgrade',['id'=>$hatch]);$after=Game::loadBase($id);
    check((int)$a['ok']+(int)$b['ok']===1&&Construction::status($after)['busy']===1,'fortification and ordinary upgrade compete for the same worker');
    $cost=$a['ok']?Catalog::fortificationStats('sniper',1)['cost']:Game::upgradeCost('hatchery',1);
    foreach($cost as $resource=>$amount){check($after['resources'][$resource]===$before['resources'][$resource]-$amount,'mixed-job loser charges no extra '.$resource);}

    $id=keeper(4);$before=Game::loadBase($id);[$a,$b]=race($id,'hireWorker',[],'hireWorker',[]);$after=Game::loadBase($id);
    check((int)$a['ok']+(int)$b['ok']===1&&$after['workers']===5,'concurrent final-worker purchases cannot exceed five');
    foreach(['twig'=>180000,'pebble'=>180000,'putty'=>35000,'goo'=>0] as $resource=>$amount){check($after['resources'][$resource]===$before['resources'][$resource]-$amount,'final worker charged exactly once '.$resource);}
    $oldBuildings=$before['buildings'];$newBuildings=$after['buildings'];foreach($oldBuildings as &$building){unset($building['stored']);}unset($building);foreach($newBuildings as &$building){unset($building['stored']);}unset($building);
    check($newBuildings===$oldBuildings&&$after['army']===$before['army'],'worker race preserves layout, health, timers and army while normal production accrues');
    echo "Construction concurrency: $checks checks passed.\n";
}finally{
    if($pdo->inTransaction()){$pdo->rollBack();}
    foreach($children as &$child){if(!$child['done']){proc_terminate($child['proc']);foreach($child['pipes'] as $pipe){if(is_resource($pipe)){fclose($pipe);}}proc_close($child['proc']);@unlink($child['file']);}}unset($child);
    if($users){$list=implode(',',array_map('intval',$users));$pdo->exec('DELETE FROM reports WHERE user_id IN ('.$list.')');$pdo->exec('DELETE FROM battles WHERE attacker_id IN ('.$list.') OR defender_id IN ('.$list.')');$pdo->exec('DELETE FROM world_sites WHERE owner_id IN ('.$list.') OR home_user_id IN ('.$list.')');$pdo->exec('DELETE FROM bases WHERE user_id IN ('.$list.')');$pdo->exec('DELETE FROM users WHERE id IN ('.$list.')');}
}
