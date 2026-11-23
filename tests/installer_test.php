<?php
declare(strict_types=1);
/**
 * Default: pure diagnostics/configuration checks; no database or installed files touched.
 * Optional database run: MYSQL_TEST_DATABASE must end in _installer_test and
 * BYM_INSTALLER_TEST_RESET=1 must explicitly allow recreating that disposable database.
 * MYSQL_TEST_HOST/PORT/USER/PASSWORD/SOCKET select its local server. A temporary copy
 * of the application isolates generated config and installation locks from a player world.
 */
require_once __DIR__.'/../app/Installer.php';
$checks=0;
function installerCheck(bool $ok,string $label): void {global $checks;++$checks;if(!$ok){throw new RuntimeException('FAIL: '.$label);}}
function installerReject(callable $work,string $label): Throwable {try{$work();}catch(Throwable $error){installerCheck(true,$label);return $error;}throw new RuntimeException('FAIL: accepted '.$label);}
function installerError(int $number): PDOException {$e=new PDOException('SECRET_PASSWORD SELECT private_data FROM users');$e->errorInfo=['HY000',$number,'SECRET_PASSWORD'];return $e;}
function installerConfig(): array {
    $config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE')?:'backyard_installer_test','username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:''];
    if(getenv('MYSQL_TEST_SOCKET')){$config['socket']=getenv('MYSQL_TEST_SOCKET');}
    return $config;
}
function installerRemoveTree(string $path): void {
    if(!is_dir($path)){return;}
    foreach(new FilesystemIterator($path) as $entry){if($entry->isDir()&&!$entry->isLink()){installerRemoveTree($entry->getPathname());}else{unlink($entry->getPathname());}}
    rmdir($path);
}

try{
    if(in_array('--integration-child',$argv,true)){
        if(getenv('BYM_INSTALLER_TEST_CHILD')!=='1'||getenv('BYM_INSTALLER_TEST_RESET')!=='1'){throw new RuntimeException('Integration child is test-runner only.');}
        $config=installerConfig();
        if(!preg_match('/^[A-Za-z0-9_]+_installer_test$/D',$config['database'])){throw new RuntimeException('Use an explicitly disposable *_installer_test database.');}
        $root=dirname(__DIR__);$configPath=$root.'/var/config.php';$server=Db::connect($config,false);
        $beforeFiles=glob($root.'/var/*');
        $probe=Installer::probe($config);
        installerCheck($probe['connected']===true&&$probe['server_supported']===true,'supported real server connects');
        installerCheck($probe['database_exists']===false&&$probe['database_empty']===null,'probe reports missing database');
        installerCheck(glob($root.'/var/*')===$beforeFiles&&!is_file($configPath),'probe writes no files');
        $q=$server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([$config['database']]);
        installerCheck((int)$q->fetchColumn()===0,'probe does not create a database');
        $error=installerReject(fn()=>Installer::install($config+['create_database'=>false]),'missing database rejected without creation permission request');
        installerCheck(Installer::diagnostic($error)['code']==='database_missing'&&!is_file($configPath),'missing database has actionable diagnostic and no config');

        // Reproduce an interrupted first installation: a few known tables exist, all empty.
        $server->exec('CREATE DATABASE `'.$config['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo=Db::connect($config);
        $schema=array_values(array_filter(array_map('trim',explode(';',file_get_contents($root.'/app/schema.sql')))));
        foreach(array_slice($schema,0,2) as $sql){$pdo->exec($sql);}
        $probe=Installer::probe($config);
        installerCheck($probe['database_exists']===true&&$probe['database_empty']===true&&$probe['can_initialize']===true,'empty partial schema can be retried');
        $_SESSION=['csrf'=>'installer-test-sentinel'];$sessionBefore=$_SESSION;
        Installer::install($config+['create_database'=>false,'keeper'=>'Ignored keeper','keeper_password'=>'never-used-secret']);
        $pdo=Db::pdo();
        installerCheck($_SESSION===$sessionBefore,'install does not sign in or change the session');
        installerCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=0')->fetchColumn()===0,'installer creates no human account');
        installerCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000,'installer creates exactly 1000 persistent bots');
        installerCheck((int)$pdo->query('SELECT COUNT(*) FROM bases')->fetchColumn()===1000,'every bot has a saved base');
        installerCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE rank_points<>0 OR wins<>0 OR losses<>0')->fetchColumn()===0,'every bot begins with zero rank wins and losses');
        installerCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE password_hash IS NOT NULL')->fetchColumn()===0,'bots receive no human login passwords');
        installerCheck((int)$pdo->query('SELECT COUNT(DISTINCT name) FROM users')->fetchColumn()===1000,'bot identities are distinct');
        installerCheck($pdo->query("SELECT value FROM settings WHERE name='schema_version'")->fetchColumn()==='3','fresh schema is version 3');
        installerCheck($pdo->query("SELECT value FROM settings WHERE name='content_version'")->fetchColumn()==='0.13.0','fresh content is version 0.13.0');
        $activity=json_decode((string)$pdo->query("SELECT value FROM settings WHERE name='world_activity_v1'")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        installerCheck($activity['epoch']>0&&$activity['active_seconds']===0&&$activity['leases']===[]&&$activity['last_dispatch_at']===null,'installation creates a paused clock without signing in or dispatching bots');
        installerCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='home'")->fetchColumn()===1000,'all bots have persistent home map markers');
        installerCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='resource' AND owner_id IS NULL")->fetchColumn()===256,'fresh world has256 neutral resource sites');
        installerCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='stronghold' AND owner_id IS NULL")->fetchColumn()===64,'fresh world has64 neutral strongholds');
        installerCheck(is_file($configPath),'private configuration finalized');
        $saved=require $configPath;
        installerCheck($saved['bot_count']===1000&&$saved['game_speed']===1&&strlen($saved['auth_salt'])===64,'normal world settings saved');
        installerCheck(!isset($saved['keeper'],$saved['keeper_password'],$saved['create_database']),'keeper inputs and installer flag not written to config');
        $snapshot=$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();
        installerReject(fn()=>Installer::install($config+['create_database'=>true]),'configured world cannot install twice');
        unlink($configPath);
        installerReject(fn()=>Installer::install($config+['create_database'=>true]),'populated database cannot be overwritten without config');
        installerCheck(!is_file($configPath)&&$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll()===$snapshot,'rejected reinstall preserves all bot records');
        $probe=Installer::probe($config);
        installerCheck($probe['database_empty']===false&&$probe['can_initialize']===false,'probe identifies installed data');
        // Normal in-game registration remains available after world-only installation.
        $keeper=Game::register('Installer Test Keeper','local-test-registration-password');
        installerCheck($keeper['is_bot']===false&&$keeper['rank_points']===0,'first human can register normally at zero rank');
        installerCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000&&(int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=0')->fetchColumn()===1,'registration leaves exactly 1000 bots');
        installerCheck(count(Game::loadBase($keeper['id'])['buildings'])>0,'registered human receives a real base');
        installerCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='home'")->fetchColumn()===1001,'normal registration assigns one additional persistent home marker');

        // Any unrelated table, even empty, is protected; users need not exist for rejection.
        $pdo=null;$server->exec('DROP DATABASE `'.$config['database'].'`');$server->exec('CREATE DATABASE `'.$config['database'].'`');
        $pdo=Db::connect($config);$pdo->exec('CREATE TABLE unrelated_private_table (id INT PRIMARY KEY) ENGINE=InnoDB');
        installerReject(fn()=>Installer::install($config),'unrelated empty tables are never adopted');
        installerCheck($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)===['unrelated_private_table'],'unrelated schema remains unchanged');
        $pdo->exec('DROP TABLE unrelated_private_table');
        foreach($schema as $sql){$pdo->exec($sql);}
        $pdo->exec("INSERT INTO settings(name,value) VALUES('preserve_me','existing-world-data')");
        installerReject(fn()=>Installer::install($config),'non-user populated tables also block install');
        installerCheck($pdo->query("SELECT value FROM settings WHERE name='preserve_me'")->fetchColumn()==='existing-world-data','existing settings are preserved');
        $pdo->exec("DELETE FROM settings WHERE name='preserve_me'");
        // A seed failure rolls back accounts; remaining empty schema is safely retryable.
        $pdo->exec("CREATE TRIGGER installer_fail_seed BEFORE INSERT ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intentional installer test failure'");
        installerReject(fn()=>Installer::install($config),'seed failure aborts install');
        installerCheck((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM bases')->fetchColumn()===0,'failed seed leaves no partial bot population');
        installerCheck(!is_file($configPath)&&glob($root.'/var/config-*')===[],'failed seed removes uncommitted config');
        $pdo->exec('DROP TRIGGER installer_fail_seed');
        Installer::install($config);
        $pdo=Db::pdo();
        installerCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000&&(int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=0')->fetchColumn()===0,'retry creates only the complete 1000-bot population');
        echo "Installer database integration: $checks assertions passed on ".$probe['server_family'].' '.$probe['server_version'].".\n";
        exit(0);
    }

    foreach([1045=>'credentials',1698=>'credentials',2002=>'connection',2003=>'connection',1049=>'database_missing',1044=>'permissions',1142=>'permissions',1143=>'permissions',1227=>'permissions',1064=>'schema',2006=>'disconnected',2013=>'disconnected',9999=>'database'] as $number=>$code){
        $diagnostic=Installer::diagnostic(installerError($number));
        installerCheck($diagnostic['code']===$code,'driver '.$number.' categorized');
        installerCheck(!str_contains(json_encode($diagnostic),'SECRET_PASSWORD')&&!str_contains(json_encode($diagnostic),'SELECT private_data'),'driver '.$number.' cannot leak raw details');
    }
    installerCheck(str_contains(Installer::diagnostic(installerError(1045))['message'],'root'),'credential advice names local XAMPP root');
    installerCheck(str_contains(Installer::diagnostic(installerError(2002))['message'],'XAMPP Control Panel'),'connection advice names service control');
    installerCheck(!str_contains(json_encode(Installer::diagnostic(new RuntimeException('SECRET_PASSWORD'))),'SECRET_PASSWORD'),'non-driver exception also cannot leak details');
    $validation=Installer::diagnostic(new SetupValidationException('Choose an empty database; existing worlds are never overwritten.'));
    installerCheck($validation['code']==='validation'&&str_contains($validation['message'],'empty database'),'explicit safe setup validation remains actionable');
    $validate=new ReflectionMethod(Installer::class,'connectionConfig');
    $defaults=$validate->invoke(null,[]);
    installerCheck($defaults['username']==='root'&&$defaults['password']===''&&$defaults['host']==='127.0.0.1'&&$defaults['port']===3306,'defaults match ordinary local XAMPP');
    installerCheck($validate->invoke(null,['database'=>'by_test','username'=>' myuser ','port'=>'3307'])['username']==='myuser','valid custom connection accepted');
    foreach([['database'=>'bad; DROP'],['database'=>'bad\nname'],['database'=>[]],['host'=>'localhost;dbname=other'],['host'=>''],['port'=>0],['port'=>65536],['port'=>'abc'],['username'=>''],['password'=>[]],['socket'=>'a;host=remote']] as $bad){
        installerReject(fn()=>$validate->invoke(null,$bad),'invalid configuration rejected before connecting');
    }
    $version=new ReflectionMethod(Installer::class,'serverVersion');
    foreach(['10.4.32-MariaDB'=>'10.4.32','5.5.5-10.4.32-MariaDB'=>'10.4.32','10.11.6-MariaDB-0+deb12u1'=>'10.11.6'] as $raw=>$expected){installerCheck($version->invoke(null,$raw,'MariaDB')===$expected,'MariaDB version parsed without compatibility-prefix confusion');}
    installerCheck($version->invoke(null,'8.0.44','MySQL')==='8.0.44','MySQL version parsed');
    $method=new ReflectionMethod(Installer::class,'install');
    installerCheck($method->getNumberOfParameters()===1&&(string)$method->getReturnType()==='void','installer has no account-creation parameters or account return value');
    echo "Installer pure checks: $checks assertions passed.\n";
    if(getenv('BYM_INSTALLER_TEST_RESET')!=='1'){echo "Database integration skipped; opt in only with a disposable *_installer_test database.\n";exit(0);}
    $config=installerConfig();
    if(!preg_match('/^[A-Za-z0-9_]+_installer_test$/D',$config['database'])){throw new RuntimeException('Use an explicitly disposable *_installer_test database.');}
    $sandbox=sys_get_temp_dir().'/bym-installer-test-'.bin2hex(random_bytes(8));$server=Db::connect($config,false);
    mkdir($sandbox,0700);mkdir($sandbox.'/app',0700);mkdir($sandbox.'/var',0700);mkdir($sandbox.'/tests',0700);
    try{
        foreach(new FilesystemIterator(__DIR__.'/../app') as $entry){if($entry->isFile()){copy($entry->getPathname(),$sandbox.'/app/'.$entry->getFilename());}}
        copy(__FILE__,$sandbox.'/tests/installer_test.php');
        $server->exec('DROP DATABASE IF EXISTS `'.$config['database'].'`');
        $environment=getenv();$environment['BYM_INSTALLER_TEST_CHILD']='1';
        $command=[PHP_BINARY];
        if(php_ini_loaded_file()){$command[]='-c';$command[]=php_ini_loaded_file();}
        else{
            // Preserve authoring runtimes started with -n and explicitly loaded modules.
            $command[]='-n';$extensionDirectory=(string)ini_get('extension_dir');
            foreach(['pdo','mysqlnd','pdo_mysql','mbstring','ctype','fileinfo'] as $extension){
                foreach([$extension.'.so','php_'.$extension.'.dll'] as $filename){
                    $path=$extensionDirectory.DIRECTORY_SEPARATOR.$filename;
                    if(extension_loaded($extension)&&is_file($path)){$command[]='-d';$command[]='extension='.$path;break;}
                }
            }
        }
        $command[]=$sandbox.'/tests/installer_test.php';$command[]='--integration-child';
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);
        if(!is_resource($process)){throw new RuntimeException('Could not launch isolated installer test.');}
        fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
        echo $stdout;if($stderr!==''){fwrite(STDERR,$stderr);}installerCheck($status===0,'isolated database suite');
    }finally{$server->exec('DROP DATABASE IF EXISTS `'.$config['database'].'`');installerRemoveTree($sandbox);}
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
