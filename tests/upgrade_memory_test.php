<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bin/upgrade.php';

// The upgrader must work with normal PHP/XAMPP memory limits even after months
// of persistent bot battles. This test never targets an installed player world.
ini_set('memory_limit','128M');
$database=getenv('BYM_UPGRADE_MEMORY_TEST_DATABASE')?:'backyard_memory_upgrade_test';
if(getenv('BYM_UPGRADE_MEMORY_TEST_RESET')!=='1'||!preg_match('/^[A-Za-z0-9_]{1,35}_memory_upgrade_test$/D',$database)){
    fwrite(STDERR,"Set BYM_UPGRADE_MEMORY_TEST_RESET=1 for a disposable *_memory_upgrade_test schema.\n");exit(2);
}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:getenv('BYM_UPGRADE_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:getenv('BYM_UPGRADE_TEST_PORT')?:33078),
    'database'=>$database,'username'=>getenv('MYSQL_TEST_USER')?:getenv('BYM_UPGRADE_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:getenv('BYM_UPGRADE_TEST_PASSWORD')?:'','game_speed'=>1];
if(($argv[1]??'')==='--upgrade-child'){
    $pdo=Db::connect($config);Db::useConnection($pdo,$config);
    $report=WorldUpgrade::run($pdo,($argv[2]??'')==='apply',(string)($argv[3]??''));
    echo json_encode(['report'=>$report,'peak_bytes'=>memory_get_peak_usage(true),'memory_limit'=>ini_get('memory_limit')],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),"\n";exit;
}
$checks=0;
function memoryCheck(bool $condition,string $message):void{global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$message);}}

/** Hash every original column without downloading the large JSON into PHP. */
function memoryDigest(PDO $pdo,string $table,?int $maximumSiteId=null):array
{
    $columns=match($table){
        'users'=>'id,name,password_hash,is_bot,rank_points,wins,losses,next_turn_at,created_at',
        'bases'=>'user_id,SHA2(state_json,256) AS state_digest,OCTET_LENGTH(state_json) AS bytes,updated_at',
        'battles'=>'id,attacker_id,defender_id,target_kind,target_id,status,SHA2(state_json,256) AS state_digest,OCTET_LENGTH(state_json) AS bytes,created_at,updated_at',
        'reports'=>'id,user_id,battle_id,title,SHA2(body_json,256) AS body_digest,OCTET_LENGTH(body_json) AS bytes,created_at',
        'world_sites'=>'id,kind,q,r,owner_id,home_user_id,name,level,SHA2(state_json,256) AS state_digest,OCTET_LENGTH(state_json) AS bytes,revision,updated_at',
        'settings'=>'name,value',
        'auth_attempts'=>'identity_hash,attempts,window_start',
    };
    $key=match($table){'bases'=>'user_id','settings'=>'name','auth_attempts'=>'identity_hash',default=>'id'};
    $where=$table==='world_sites'&&$maximumSiteId!==null?' WHERE id<='.(int)$maximumSiteId:'';
    $statement=$pdo->query('SELECT '.$columns.' FROM `'.$table.'`'.$where.' ORDER BY `'.$key.'`');$hash=hash_init('sha256');$count=0;
    while($row=$statement->fetch(PDO::FETCH_ASSOC)){hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");++$count;}
    $statement->closeCursor();return ['rows'=>$count,'sha256'=>hash_final($hash)];
}

/** Spawn the actual updater in a clean 128MiB PHP process, preserving extension settings. */
function memoryUpgrade(string $mode,string $directory):array
{
    $command=[PHP_BINARY];
    if($ini=php_ini_loaded_file()){$command[]='-c';$command[]=$ini;}
    else{
        $command[]='-n';$command[]='-d';$command[]='extension_dir='.ini_get('extension_dir');
        foreach(['pdo','mysqlnd','pdo_mysql','mbstring','ctype'] as $extension){$file=ini_get('extension_dir').DIRECTORY_SEPARATOR.$extension.(DIRECTORY_SEPARATOR==='\\'?'.dll':'.so');if(is_file($file)){$command[]='-d';$command[]='extension='.basename($file);}}
    }
    $command=array_merge($command,['-d','memory_limit=128M','-d','display_errors=stderr',__FILE__,'--upgrade-child',$mode,$directory.'/backups']);
    $stdout=$directory.'/child-'.bin2hex(random_bytes(4)).'.out';$stderr=$stdout.'.err';$pipes=[];
    $process=proc_open($command,[0=>['pipe','r'],1=>['file',$stdout,'w'],2=>['file',$stderr,'w']],$pipes);
    if(!is_resource($process)){throw new RuntimeException('Cannot launch the limited-memory upgrade process.');}fclose($pipes[0]);$exit=proc_close($process);
    $output=file_get_contents($stdout);$errors=file_get_contents($stderr);unlink($stdout);unlink($stderr);
    if($exit!==0){throw new RuntimeException('Limited-memory updater exited '.$exit.': '.substr($errors.$output,0,16000));}
    memoryCheck(trim($errors)==='','limited-memory upgrade has no PHP warnings or errors');
    $result=json_decode($output,true,512,JSON_THROW_ON_ERROR);memoryCheck($result['memory_limit']==='128M','actual child is constrained to 128MiB');memoryCheck($result['peak_bytes']<128*1024*1024,'actual child stays below memory limit');return $result;
}

/** SQL backups contain static DDL and one hex-encoded INSERT per line. */
function memoryRestore(PDO $pdo,string $file):void
{
    $handle=fopen($file,'rb');if($handle===false){throw new RuntimeException('Cannot read the SQL backup.');}$sql='';
    try{
        while(($line=fgets($handle))!==false){
            if($sql===''&&(trim($line)===''||str_starts_with(ltrim($line),'--'))){continue;}
            $sql.=$line;
            if(str_ends_with(rtrim($line),';')){$pdo->exec($sql);$sql='';}
        }
        if(trim($sql)!==''){throw new RuntimeException('Incomplete statement at the end of the SQL backup.');}
    }finally{fclose($handle);}
}

$restoreDatabase=$database.'_restore';$admin=Db::connect($config,false);$temporary=sys_get_temp_dir().'/bym-memory-upgrade-'.bin2hex(random_bytes(6));mkdir($temporary,0700,true);
$tables=['users','bases','battles','reports','settings','auth_attempts','world_sites'];$pdo=null;$restored=null;
try{
    foreach([$database,$restoreDatabase] as $name){$admin->exec('DROP DATABASE IF EXISTS `'.$name.'`');$admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
    $pdo=Db::connect($config);Db::useConnection($pdo,$config);
    foreach(explode(';',file_get_contents(__DIR__.'/fixtures/schema-v2.sql')) as $sql){if(trim($sql)!==''){$pdo->exec($sql);}}
    $pdo->exec("INSERT INTO settings(name,value) VALUES('schema_version','2'),('content_version','0.7.0'),('world_created_at','1700000000'),('custom_history_marker','preserve this setting')");
    $pdo->prepare('INSERT INTO users(name,password_hash,is_bot,rank_points,wins,losses,next_turn_at,created_at) VALUES(?,?,0,275,12,3,0,1700000000)')->execute(['Memory Fixture Keeper',password_hash('fixture-only-password',PASSWORD_DEFAULT)]);
    $human=(int)$pdo->lastInsertId();$humanJson=trim(file_get_contents(__DIR__.'/fixtures/base-v04-human.json'));
    $pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,1700000000)')->execute([$human,$humanJson]);
    Game::seedBots($pdo,1000);
    $update=$pdo->prepare('UPDATE bases SET state_json=?,updated_at=1700000000 WHERE user_id=?');$last=0;
    do{
        $chunk=$pdo->query('SELECT user_id,state_json FROM bases WHERE user_id>'.(int)$last.' ORDER BY user_id LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        foreach($chunk as $row){$base=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);unset($base['world'],$base['workers'],$base['planner']);$base['content_version']='0.7.0';$base['memory_fixture_extension']=['preserve'=>'old unknown field','old_tick'=>1700000000];$update->execute([json_encode($base,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$row['user_id']]);$last=(int)$row['user_id'];}
    }while($chunk);unset($chunk,$base);
    $update->execute([trim(file_get_contents(__DIR__.'/fixtures/base-v04-bot.json')),4]);
    $v5=json_decode(file_get_contents(__DIR__.'/fixtures/world-v07.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($v5['bases'] as $id=>$old){$update->execute([json_encode($old,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),(int)$id]);}
    World::initialize($pdo);
    // A v0.7 world already completed Fortification generation. Preserve every row
    // and its marker; the v0.13.1 upgrade must not add or recreate any site.
    foreach($v5['sites'] as $row){$statement=$pdo->prepare('UPDATE world_sites SET kind=?,q=?,r=?,owner_id=?,home_user_id=?,name=?,level=?,state_json=?,revision=?,updated_at=? WHERE id=?');$statement->execute([$row['kind'],$row['q'],$row['r'],$row['owner_id'],$row['home_user_id'],$row['name'],$row['level'],$row['state_json'],$row['revision'],$row['updated_at'],$row['id']]);}
    $pdo->prepare('INSERT INTO auth_attempts(identity_hash,attempts,window_start) VALUES(?,3,1700000000)')->execute([hash('sha256','memory-fixture-throttle')]);
    // Realistic nested combat events provide large valid history JSON rather than
    // one artificial huge row. UTF-8, quotes, slashes and newlines also exercise backup escaping.
    $events=[];
    for($i=1;$i<=1200;++$i){$events[]=['id'=>$i,'time'=>$i*.25,'type'=>$i%5===0?'projectile':'hit','x'=>($i%128)/4,'y'=>(intdiv($i,128)%128)/4,'sourceId'=>'u'.($i%150),'targetId'=>$i%24+1,'kind'=>'fink','amount'=>23.5,'note'=>"Dusk β 'quoted' / path\\segment\ncombat"];}
    $battleTemplate=json_decode($v5['active_battle']['state_json'],true,512,JSON_THROW_ON_ERROR);$battleTemplate['events']=$events;$battleTemplate['status']='finished';$battleTemplate['settled']=true;$battleTemplate['elapsed']=300;$battleTemplate['result']=['attackerWon'=>true,'destruction'=>85.5,'loot'=>['twig'=>100,'pebble'=>100,'putty'=>50,'goo'=>25]];
    $reportTemplate=['role'=>'attacker','rankDelta'=>['attacker'=>25,'defender'=>-15],'target'=>'Old backyard','combatHighlights'=>array_slice($events,0,64),'unknown_extension'=>['keep'=>'historical report bytes']];unset($events);
    $sampleBattle=json_encode($battleTemplate,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$sampleReport=json_encode($reportTemplate,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$rows=(int)ceil(64*1024*1024/(strlen($sampleBattle)+strlen($sampleReport)))+1;
    $battleInsert=$pdo->prepare('INSERT INTO battles(attacker_id,defender_id,target_kind,target_id,status,state_json,created_at,updated_at) VALUES(?,?,?,?,?, ?,1700000000,1700000300)');
    $reportInsert=$pdo->prepare('INSERT INTO reports(user_id,battle_id,title,body_json,created_at) VALUES(?,?,?,?,1700000300)');
    $pdo->beginTransaction();
    for($i=1;$i<=$rows;++$i){
        $battleTemplate['id']=$i;$battleTemplate['historySequence']=$i;$battleTemplate['status']=$i===$rows?'active':'finished';$battleTemplate['settled']=$i!==$rows;
        $battleInsert->execute([$human,2,$battleTemplate['target']['kind'],$battleTemplate['target']['id'],$battleTemplate['status'],json_encode($battleTemplate,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);$battleId=(int)$pdo->lastInsertId();$reportTemplate['historySequence']=$i;
        $reportInsert->execute([$human,$battleId,'Historic invasion '.$i,json_encode($reportTemplate,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);
    }
    $pdo->commit();unset($battleTemplate,$reportTemplate,$sampleBattle,$sampleReport,$humanJson);
    $historyBytes=(int)$pdo->query('SELECT (SELECT SUM(OCTET_LENGTH(state_json)) FROM battles)+(SELECT SUM(OCTET_LENGTH(body_json)) FROM reports)')->fetchColumn();
    memoryCheck($historyBytes>=64*1024*1024,'fixture has at least 64MiB of historical battle and report JSON');memoryCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000,'fixture has exactly 1000 persistent bots');
    $maximumOldSiteId=(int)$pdo->query('SELECT MAX(id) FROM world_sites')->fetchColumn();
    $before=[];foreach($tables as $table){$before[$table]=memoryDigest($pdo,$table);}
    $expectedBaseHash=[];$last=0;
    do{$chunk=$pdo->query('SELECT user_id,state_json FROM bases WHERE user_id>'.(int)$last.' ORDER BY user_id LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);foreach($chunk as $row){$base=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);$expectedBaseHash[(int)$row['user_id']]=hash('sha256',json_encode(Game::normalizeBase($base),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));$last=(int)$row['user_id'];}}while($chunk);unset($chunk,$base);
    $check=memoryUpgrade('check',$temporary);memoryCheck(!$check['report']['applied']&&$check['report']['changed_bases']===1001,'large-history dry run detects all legacy yards');foreach($tables as $table){memoryCheck(memoryDigest($pdo,$table)===$before[$table],'dry run preserves '.$table);}
    $apply=memoryUpgrade('apply',$temporary);$report=$apply['report'];memoryCheck($report['applied']&&$report['changed_bases']===1001,'limited-memory apply upgrades all 1001 yards');
    memoryCheck($report['target_version']==='0.13.1'&&$pdo->query("SELECT value FROM settings WHERE name='content_version'")->fetchColumn()==='0.13.1','limited-memory upgrade records the exact hotfix version');
    foreach(['users','battles','reports','auth_attempts','world_sites'] as $table){memoryCheck(memoryDigest($pdo,$table,$maximumOldSiteId)===$before[$table],'apply preserves every original '.$table.' byte and field');}
    $statement=$pdo->query('SELECT user_id,SHA2(state_json,256) AS digest FROM bases ORDER BY user_id');while($row=$statement->fetch(PDO::FETCH_ASSOC)){memoryCheck($expectedBaseHash[(int)$row['user_id']]===$row['digest'],'base '.$row['user_id'].' receives only expected normalization');}$statement->closeCursor();unset($expectedBaseHash);
    memoryCheck((int)$pdo->query('SELECT COUNT(*) FROM world_sites')->fetchColumn()===$before['world_sites']['rows']&&$report['fortifications_added']===0,'large-history upgrade preserves every old map site without generating new forts');memoryCheck($pdo->query("SELECT value FROM settings WHERE name='world_fortifications_version'")->fetchColumn()==='1','large-history completed Fortification marker remains unchanged');memoryCheck($pdo->query("SELECT value FROM settings WHERE name='schema_version'")->fetchColumn()==='3','schema 3 is recorded after the additive upgrade');
    foreach(['backup'=>'backup_sha256','backup_sql'=>'backup_sql_sha256'] as $fileKey=>$hashKey){memoryCheck(is_file($report[$fileKey])&&hash_file('sha256',$report[$fileKey])===$report[$hashKey],'complete '.$fileKey.' has matching SHA256');if(DIRECTORY_SEPARATOR==='/'){memoryCheck((fileperms($report[$fileKey])&0777)===0600,$fileKey.' is private');}}
    memoryCheck(filesize($report['backup'])>$historyBytes,'JSON backup contains all large history');memoryCheck(filesize($report['backup_sql'])>$historyBytes*2,'SQL backup contains complete hex-encoded history');
    $handle=fopen($report['backup'],'rb');$prefix=fread($handle,512);fseek($handle,-128,SEEK_END);$tail=fread($handle,128);fclose($handle);memoryCheck(str_contains($prefix,'backyard-rehatched-world-backup-v2')&&str_ends_with(rtrim($tail),'}'),'streamed JSON backup has complete format header and closing object');
    $again=memoryUpgrade('apply',$temporary);memoryCheck(!$again['report']['applied']&&$again['report']['changed_bases']===0,'large-history second apply is a no-op');
    $restoreConfig=$config;$restoreConfig['database']=$restoreDatabase;$restored=Db::connect($restoreConfig);memoryRestore($restored,$report['backup_sql']);
    foreach($tables as $table){memoryCheck(memoryDigest($restored,$table)===$before[$table],'SQL backup restores exact original '.$table.' rows');}
    memoryCheck($restored->query("SELECT value FROM settings WHERE name='schema_version'")->fetchColumn()==='2','restore retains actual original schema2');memoryCheck($restored->query("SELECT value FROM settings WHERE name='content_version'")->fetchColumn()==='0.7.0','backup restores actual v0.7 content version');
    echo json_encode(['test'=>'upgrade_memory','assertions'=>$checks,'history_bytes'=>$historyBytes,'history_battles'=>$rows,'history_reports'=>$rows,'check_peak_bytes'=>$check['peak_bytes'],'apply_peak_bytes'=>$apply['peak_bytes'],'noop_peak_bytes'=>$again['peak_bytes'],'parent_peak_bytes'=>memory_get_peak_usage(true),'php_limit'=>'128M','server_version'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'restored_all_original_tables'=>true,'source_content'=>'0.7.0','target_content'=>$report['target_version'],'source_schema'=>'2','target_schema'=>$report['target_schema'],'original_map_sites'=>$before['world_sites']['rows'],'map_sites'=>$report['world_sites'],'fortifications_added'=>$report['fortifications_added']],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),"\n";
}finally{
    if($pdo instanceof PDO&&$pdo->inTransaction()){$pdo->rollBack();}if($restored instanceof PDO&&$restored->inTransaction()){$restored->rollBack();}
    foreach([$database,$restoreDatabase] as $name){$admin->exec('DROP DATABASE IF EXISTS `'.$name.'`');}
    foreach(glob($temporary.'/backups/*')?:[] as $file){if(is_file($file)){unlink($file);}}if(is_dir($temporary.'/backups')){rmdir($temporary.'/backups');}
    foreach(glob($temporary.'/*')?:[] as $file){if(is_file($file)){unlink($file);}}if(is_dir($temporary)){rmdir($temporary);}
}
