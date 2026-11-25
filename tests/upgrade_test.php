<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bin/upgrade.php';

// This test is intentionally isolated and destructive only within an explicitly
// named disposable schema. It must never be pointed at an installed player world.
$name=getenv('BYM_UPGRADE_TEST_DATABASE')?:'';
if(getenv('BYM_UPGRADE_TEST_RESET')!=='1'||!preg_match('/^[A-Za-z0-9_]+_upgrade_test$/D',$name)){
    fwrite(STDERR,"Set BYM_UPGRADE_TEST_RESET=1 and BYM_UPGRADE_TEST_DATABASE to a disposable name ending _upgrade_test.\n");exit(2);
}
$config=['host'=>getenv('BYM_UPGRADE_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('BYM_UPGRADE_TEST_PORT')?:3306),'database'=>$name,'username'=>getenv('BYM_UPGRADE_TEST_USER')?:'backyard_test','password'=>getenv('BYM_UPGRADE_TEST_PASSWORD')?:'','game_speed'=>1];
$admin=Db::connect($config,false);$admin->exec('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
$tables=['alliance_war_battles','alliance_wars','alliance_stances','alliance_requests','alliance_members','alliances','keeper_mail','keeper_gifts','keeper_friends','world_sites','auth_attempts','reports','battles','bases','users','settings'];
foreach($tables as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}
foreach(explode(';',file_get_contents(__DIR__.'/fixtures/schema-v1.sql')) as $sql){if(trim($sql)!==''){$pdo->exec($sql);}}
$temporary=sys_get_temp_dir().'/bym-upgrade-test-'.bin2hex(random_bytes(6));
$assertions=0;
function upgradeCheck(bool $condition,string $label): void {global $assertions;++$assertions;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function upgradeReject(callable $fn,string $label): void {try{$fn();}catch(Throwable){upgradeCheck(true,$label);return;}throw new RuntimeException('FAIL: did not reject '.$label);}
/** These three new home species have empty counters until the keeper actually earns them. */
function upgradeExpectedRoster(array $base): array {
    foreach (['vorg','slimeattikus','rezghul'] as $type) {
        $base['army'] += [$type=>0];
        $base['research'] += [$type=>1];
    }
    return $base;
}
function upgradeRows(PDO $pdo,string $table,string $key): array {return $pdo->query('SELECT * FROM `'.$table.'` ORDER BY `'.$key.'`')->fetchAll(PDO::FETCH_ASSOC);}
/** Inspect persistence without the runtime bot clock projection in Game::loadBase. */
function upgradeBase(PDO $pdo,int $id): array {$q=$pdo->prepare('SELECT state_json FROM bases WHERE user_id=?');$q->execute([$id]);return json_decode((string)$q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);}
function upgradeOriginalRows(PDO $pdo,string $table,string $key,array $original): array {
    $rows=upgradeRows($pdo,$table,$key);if(!$original){return $rows;}
    return array_map(static fn($row)=>array_intersect_key($row,$original[0]),$rows);
}
/** Restore one statement at a time so the verification harness also fits 128MiB. */
function upgradeRestoreSql(PDO $pdo,string $file): void {
    $handle=fopen($file,'rb');if($handle===false){throw new RuntimeException('Cannot open the upgrade SQL backup.');}$statement='';
    try{while(($line=fgets($handle))!==false){
        if($statement===''&&(trim($line)===''||str_starts_with(ltrim($line),'--'))){continue;}
        $statement.=$line;
        if(str_ends_with(rtrim($line),';')){$pdo->exec($statement);$statement='';}
    }if(trim($statement)!==''){throw new RuntimeException('Incomplete SQL backup statement.');}}
    finally{fclose($handle);}
}
/** A retry backup can legitimately contain DDL committed before a failing data stage. */
function upgradeFixtureSchema2(PDO $pdo): void {
    foreach (['alliance_war_battles','alliance_wars','alliance_stances','alliance_requests','alliance_members','alliances','keeper_mail','keeper_gifts','keeper_friends'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
    }
    $pdo->exec("UPDATE settings SET value='2' WHERE name='schema_version'");
}
function legacyFixture(array $base,string $version='0.1.0'): array {
    unset($base['world'],$base['workers'],$base['planner']);
    if($version!=='0.4.0'){unset($base['lab'],$base['army']['projectx'],$base['research']['projectx']);}
    if(!in_array($version,['0.3.0','0.4.0'],true)){
        foreach(['champions','activeChampion'] as $key){unset($base[$key]);}
        foreach(['brain','crabatron'] as $key){unset($base['army'][$key],$base['research'][$key]);}
    }
    if($version==='0.1.0'){
        foreach(['content_version','unlocked','unlockQueue','garrison'] as $key){unset($base[$key]);}
        foreach(['bolt','eyera','fang','wormzer','teratorn','zafreeti'] as $key){unset($base['army'][$key],$base['research'][$key]);}
        $base['buildings']=array_values(array_filter($base['buildings'],static fn($b)=>$b['type']!=='flinger'));
    }else{
        $base['content_version']=$version;
    }
    $base['fixture_custom_field']=['preserve'=>true,'note'=>'not part of normalizer'];
    return $base;
}
try{
    $pdo->exec("INSERT INTO settings(name,value) VALUES('schema_version','1'),('world_created_at','1700000000')");
    $insert=$pdo->prepare('INSERT INTO users(name,password_hash,is_bot,rank_points,wins,losses,next_turn_at,created_at) VALUES(?,?,0,-15,3,2,0,?)');$insert->execute(['Upgrade Keeper',password_hash('fixture-password-only',PASSWORD_DEFAULT),time()]);$human=(int)$pdo->lastInsertId();
    $base=json_decode(file_get_contents(__DIR__.'/fixtures/base-v04-human.json'),true,512,JSON_THROW_ON_ERROR);$base['resources']['goo']=12345;$base['army']['pokey']=17;$base['research']['pokey']=3;$base['last_tick']=1700000000;
    $base['queue']=[['id'=>71,'type'=>'pokey','quantity'=>1,'ready_at'=>2000000000]];
    $insert=$pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$insert->execute([$human,json_encode($base,JSON_THROW_ON_ERROR),time()]);
    Game::seedBots($pdo,1000);
    // This exact 0.4 starter bot was exported by the shipped 0.4.1 application,
    // independently of the current normalizer used by newer fixture accounts.
    $pdo->prepare('UPDATE bases SET state_json=? WHERE user_id=4')->execute([trim(file_get_contents(__DIR__.'/fixtures/base-v04-bot.json'))]);
    $pdo->exec('UPDATE users SET rank_points=25,wins=1 WHERE id=2');
    $update=$pdo->prepare('UPDATE bases SET state_json=? WHERE user_id=?');
    foreach(upgradeRows($pdo,'bases','user_id') as $row){$update->execute([json_encode(legacyFixture(json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR),['0.1.0','0.2.0','0.3.0','0.4.0'][((int)$row['user_id']-1)%4]),JSON_THROW_ON_ERROR),$row['user_id']]);}
    // Preserve a developed 0.2 bot, including its own queues and defense data.
    $versionTwo=json_decode($pdo->query('SELECT state_json FROM bases WHERE user_id=2')->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $versionTwo['unlocked'][]='eyera';$versionTwo['army']['eyera']=3;$versionTwo['research']['eyera']=2;
    $versionTwo['unlockQueue']=[['type'=>'fang','ready_at'=>2000000010]];
    $versionTwo['garrison']=['201'=>['pokey'=>2,'octo'=>1]];
    $versionTwo['researchQueue']=[['type'=>'bolt','target_level'=>2,'ready_at'=>2000000030]];
    $update->execute([json_encode($versionTwo,JSON_THROW_ON_ERROR),2]);
    $battleInsert=$pdo->prepare('INSERT INTO battles(attacker_id,defender_id,status,state_json,created_at,updated_at) VALUES(?,?,?,?,?,?)');
    $activeState=['id'=>1,'status'=>'active','elapsed'=>21.5,'legacy_marker'=>'preserve exact active battle bytes','remaining'=>['pokey'=>3],'events'=>[['id'=>1,'type'=>'deploy']]];
    $battleInsert->execute([$human,2,'active',json_encode($activeState,JSON_THROW_ON_ERROR),1700000001,1700000023]);$activeBattle=(int)$pdo->lastInsertId();
    $battleInsert->execute([3,4,'finished','{"fixture":"completed report must survive","result":{"winnerId":3}}',1700000004,1700000050]);$finishedBattle=(int)$pdo->lastInsertId();
    $reportInsert=$pdo->prepare('INSERT INTO reports(user_id,battle_id,title,body_json,created_at) VALUES(?,?,?,?,?)');
    $reportInsert->execute([3,$finishedBattle,'Fixture report','{"role":"attacker","rankDelta":{"attacker":25,"defender":-15}}',1700000050]);
    $pdo->prepare('INSERT INTO auth_attempts(identity_hash,attempts,window_start) VALUES(?,?,?)')->execute([hash('sha256','fixture-identity-only'),2,1700000000]);
    $usersBefore=upgradeRows($pdo,'users','id');$basesBefore=upgradeRows($pdo,'bases','user_id');$settingsBefore=upgradeRows($pdo,'settings','name');
    $battlesBefore=upgradeRows($pdo,'battles','id');$reportsBefore=upgradeRows($pdo,'reports','id');$authBefore=upgradeRows($pdo,'auth_attempts','identity_hash');
    $report=WorldUpgrade::run($pdo,false,$temporary);
    upgradeCheck($report['source_version']==='0.1.0'&&$report['target_version']==='0.13.1','0.1 world is accepted for direct upgrade to 0.13.1');
    upgradeCheck($report['changed_bases']===1001,'dry run finds all old bases');
    upgradeCheck($report['bots']===1000&&$report['users']===1001,'dry run counts existing humans and bots');
    upgradeCheck(!$report['applied']&&!is_dir($temporary),'dry run creates no backup files');
    upgradeCheck(upgradeRows($pdo,'users','id')===$usersBefore&&upgradeRows($pdo,'bases','user_id')===$basesBefore&&upgradeRows($pdo,'settings','name')===$settingsBefore,'dry run changes no rows');
    $pdo->exec("INSERT INTO settings(name,value) VALUES('content_version','0.2.0')");$settingsBefore=upgradeRows($pdo,'settings','name');
    $report=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck($report['source_version']==='0.2.0'&&$report['target_version']==='0.13.1','0.2 world is accepted for upgrade to 0.13.1');
    upgradeCheck($report['applied']&&$report['changed_bases']===1001,'all old bases upgraded in apply');
    upgradeCheck(is_file($report['backup']),'private backup exists before completed upgrade');
    $firstSqlBackup=$report['backup_sql'];
    upgradeCheck(is_file($firstSqlBackup)&&hash_file('sha256',$firstSqlBackup)===$report['backup_sql_sha256'],'restorable SQL backup exists and checksum matches');
    upgradeCheck(!str_contains(file_get_contents($firstSqlBackup),'CREATE TABLE `world_sites`'),'SQL backup precedes new world-site DDL');
    upgradeCheck($report['source_schema']==='1'&&$report['target_schema']==='3'&&$report['schema_steps']===14,'dry planned additive DDL migrated schema 1 to 3');
    upgradeCheck(hash_file('sha256',$report['backup'])===$report['backup_sha256'],'backup checksum matches');
    if(DIRECTORY_SEPARATOR==='/'){upgradeCheck((fileperms($report['backup'])&0777)===0600,'private backup permissions');upgradeCheck((fileperms($temporary)&0777)===0700,'private backup directory permissions');}
    $backup=json_decode(file_get_contents($report['backup']),true,512,JSON_THROW_ON_ERROR);
    upgradeCheck($backup['format']==='backyard-rehatched-world-backup-v2'&&count($backup['table_schemas'])===6,'complete schema-backed backup format');
    upgradeCheck($backup['tables']['users']===$usersBefore&&$backup['tables']['bases']===$basesBefore,'backup contains exact original users and base JSON');
    upgradeCheck($backup['tables']['battles']===$battlesBefore&&$backup['tables']['reports']===$reportsBefore&&$backup['tables']['settings']===$settingsBefore&&$backup['tables']['auth_attempts']===$authBefore,'backup preserves exact battle report settings and throttle rows');
    upgradeCheck(upgradeRows($pdo,'users','id')===$usersBefore,'IDs passwords ranks wins and all account fields preserved');
    upgradeCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000,'no bot reseed or duplication');
    $saved=upgradeBase($pdo,$human);
    upgradeCheck($saved['army']['pokey']===17&&$saved['research']['pokey']===3,'existing army and research preserved');
    upgradeCheck($saved['resources']['goo']===12345&&$saved['last_tick']===1700000000,'upgrade grants no resource accrual');
    upgradeCheck($saved['queue']===$base['queue'],'queued training timer preserved');
    upgradeCheck(!in_array('flinger',array_column($saved['buildings'],'type'),true),'no new building inserted into old layout');
    upgradeCheck($saved['army']['bolt']===0&&!in_array('bolt',$saved['unlocked'],true),'new species added but not granted or unlocked');
    upgradeCheck(count($saved['unlocked'])===6,'legacy six species remain unlocked');
    upgradeCheck($saved['champions']===[]&&$saved['activeChampion']===null,'champion records start empty without free champions');
    upgradeCheck(!array_intersect(['championcage','championchamber'],array_column($saved['buildings'],'type')),'no free champion facilities inserted');
    upgradeCheck($saved['army']['brain']===0&&$saved['army']['crabatron']===0&&!in_array('brain',$saved['unlocked'],true)&&!in_array('crabatron',$saved['unlocked'],true),'new recipe species remain locked and unowned');
    upgradeCheck($saved['army']['projectx']===0&&$saved['research']['projectx']===1&&!in_array('projectx',$saved['unlocked'],true),'Project X remains locked with zero stock');
    upgradeCheck(!array_intersect(['lab','catapult'],array_column($saved['buildings'],'type')),'no free Lab or Catapult inserted');
    upgradeCheck(count($saved['lab']['levels'])===10&&array_sum($saved['lab']['levels'])===0&&$saved['lab']['queue']===null,'all Lab abilities start unresearched with no queue');
    $savedTwo=upgradeBase($pdo,2);
    upgradeCheck($savedTwo['army']['eyera']===3&&$savedTwo['research']['eyera']===2&&in_array('eyera',$savedTwo['unlocked'],true),'0.2 discoveries army and research preserved');
    upgradeCheck($savedTwo['unlockQueue']===$versionTwo['unlockQueue']&&$savedTwo['researchQueue']===$versionTwo['researchQueue']&&$savedTwo['garrison']===$versionTwo['garrison'],'0.2 queues and bunker garrisons preserved');
    upgradeCheck(!in_array('maproom',array_column($saved['buildings'],'type'),true),'no free Map Room granted');
    upgradeCheck((float)($saved['world']['resourcePerSecond']??-1)===0.0&&($saved['world']['capacity']??-1)===0,'new strategic cache grants no production or capacity');
    upgradeCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='home'")->fetchColumn()===1001,'every existing user receives one home marker');
    upgradeCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='resource' AND owner_id IS NULL")->fetchColumn()===256,'256 neutral resource sites are independent of users');
    upgradeCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='stronghold' AND owner_id IS NULL")->fetchColumn()===64,'64 neutral strongholds are independent of users');
    upgradeCheck((int)$pdo->query('SELECT COUNT(*) FROM world_sites')->fetchColumn()===(int)$pdo->query('SELECT COUNT(DISTINCT q,r) FROM world_sites')->fetchColumn(),'map coordinates are unique');
    upgradeCheck($saved['fixture_custom_field']===['preserve'=>true,'note'=>'not part of normalizer'],'unknown extension fields preserved');
    upgradeCheck($pdo->query("SELECT value FROM settings WHERE name='schema_version'")->fetchColumn()==='3','SQL schema advanced to 3 only after map initialization');
    upgradeCheck($pdo->query("SELECT value FROM settings WHERE name='content_version'")->fetchColumn()==='0.13.1','content version advanced to 0.13.1');
    upgradeCheck(upgradeOriginalRows($pdo,'battles','id',$battlesBefore)===$battlesBefore&&upgradeRows($pdo,'reports','id')===$reportsBefore,'active and completed battle records are untouched');
    upgradeCheck(upgradeRows($pdo,'auth_attempts','identity_hash')===$authBefore,'authentication throttle state untouched');
    $upgraded=upgradeRows($pdo,'bases','user_id');$files=glob($temporary.'/*');$sitesAfter=upgradeRows($pdo,'world_sites','id');
    $again=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck(!$again['applied']&&$again['changed_bases']===0,'repeat apply is a no-op');
    upgradeCheck(upgradeRows($pdo,'bases','user_id')===$upgraded&&glob($temporary.'/*')===$files&&upgradeRows($pdo,'world_sites','id')===$sitesAfter,'repeat creates no writes or redundant backup');
    // Existing champion progress and arbitrary extension fields must survive another check/apply.
    $championBase=$savedTwo;
    $championBase['champions']=['gorgo'=>['level'=>3,'hp'=>123.5,'feeds'=>2,'bonus_feeds'=>0,'next_feed_at'=>2000000400,'last_heal_at'=>1700000010,'last_hunger_at'=>1700000010,'battle_id'=>$activeBattle,'frozen'=>false,'paused_feed_seconds'=>0,'fixture_extension'=>['preserve'=>'yes']]];
    $championBase['activeChampion']='gorgo';
    $update->execute([json_encode($championBase,JSON_THROW_ON_ERROR),2]);
    $championBefore=upgradeRows($pdo,'bases','user_id');
    $championReport=WorldUpgrade::run($pdo,true,$temporary);
    $championSaved=upgradeBase($pdo,2);
    upgradeCheck($championSaved['champions']===$championBase['champions']&&$championSaved['activeChampion']==='gorgo','existing champion injury feeding timers battle lock and extension data preserved');
    upgradeCheck(!$championReport['applied']&&upgradeRows($pdo,'bases','user_id')===$championBefore,'current champion world is a no-op upgrade');
    // A 0.3 world can already contain persisted experimental Lab extensions. The
    // updater extends defaults only; it may not finish due studies, spend/refund
    // putty, clamp known levels or erase extension data.
    $labBase=$championBase;
    $labBase['content_version']='0.3.0';
    unset($labBase['army']['projectx'],$labBase['research']['projectx']);
    $labBase['lab']=['levels'=>['bolt'=>2,'dave'=>1,'extension_species'=>7],
        'queue'=>['type'=>'brain','target_level'=>1,'started_at'=>1699999900,'ready_at'=>1700000000,'paid_putty'=>4000,'fixture_extension'=>['keep'=>true]],
        'fixture_extension'=>['history'=>['first','second']]];
    $update->execute([json_encode($labBase,JSON_THROW_ON_ERROR),2]);
    $pdo->exec("UPDATE settings SET value='0.3.0' WHERE name='content_version'");
    $labRowsBefore=upgradeRows($pdo,'bases','user_id');
    $labReport=WorldUpgrade::run($pdo,true,$temporary);
    $labSaved=upgradeBase($pdo,2);
    upgradeCheck($labReport['source_version']==='0.3.0'&&$labReport['target_version']==='0.13.1'&&$labReport['applied'],'0.3 world upgrades directly to 0.13.1');
    upgradeCheck($labSaved['lab']['levels']['bolt']===2&&$labSaved['lab']['levels']['dave']===1&&$labSaved['lab']['levels']['extension_species']===7,'existing Lab levels and extension species remain unchanged');
    upgradeCheck($labSaved['lab']['queue']===$labBase['lab']['queue'],'Lab paid cost due timer and queue extensions are preserved without completion');
    upgradeCheck($labSaved['lab']['fixture_extension']===$labBase['lab']['fixture_extension'],'Lab extension history survives');
    upgradeCheck($labSaved['resources']===$labBase['resources']&&$labSaved['last_tick']===$labBase['last_tick'],'Lab migration grants no accrual or refunds');
    upgradeCheck($labSaved['champions']===$labBase['champions']&&$labSaved['activeChampion']===$labBase['activeChampion'],'0.3 champion injuries and battle reservation survive Lab upgrade');
    upgradeCheck($labSaved['lab']['levels']['teratorn']===0&&$labSaved['army']['projectx']===0,'only missing Lab and Project X defaults are added');
    $labBackup=json_decode(file_get_contents($labReport['backup']),true,512,JSON_THROW_ON_ERROR);
    upgradeCheck($labBackup['tables']['bases']===$labRowsBefore,'0.3 backup retains exact original Lab and champion JSON');
    $labAgain=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck(!$labAgain['applied']&&$labAgain['changed_bases']===0,'extended Lab world is idempotent');
    upgradeCheck(upgradeRows($pdo,'users','id')===$usersBefore&&upgradeOriginalRows($pdo,'battles','id',$battlesBefore)===$battlesBefore&&upgradeRows($pdo,'reports','id')===$reportsBefore&&upgradeRows($pdo,'auth_attempts','identity_hash')===$authBefore,'0.3 upgrade preserves every account battle report and auth record');
    $update->execute([$upgraded[1]['state_json'],2]);
    $update->execute([$basesBefore[0]['state_json'],$human]);
    $beforeReject=upgradeRows($pdo,'bases','user_id');
    upgradeReject(static fn()=>WorldUpgrade::run($pdo,true,dirname(__DIR__).'/public'),'web-root backup location');
    upgradeCheck(upgradeRows($pdo,'bases','user_id')===$beforeReject,'failed backup leaves all bases untouched');
    $update->execute(['{broken',2]);$beforeReject=upgradeRows($pdo,'bases','user_id');
    upgradeReject(static fn()=>WorldUpgrade::run($pdo,true,$temporary),'malformed legacy JSON');
    upgradeCheck(upgradeRows($pdo,'bases','user_id')===$beforeReject,'malformed base prevents partial migration');
    $update->execute([$upgraded[1]['state_json'],2]);
    $other=Db::connect($config);$lock='bym:world:'.substr(hash('sha256',$name),0,32);$q=$other->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
    upgradeReject(static fn()=>WorldUpgrade::run($pdo,true,$temporary),'active world scheduler lock');
    $q=$other->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);$other=null;
    $pdo->exec("UPDATE settings SET value='unexpected' WHERE name='content_version'");
    upgradeReject(static fn()=>WorldUpgrade::run($pdo,true,$temporary),'unrecognized source content');
    $pdo->exec("UPDATE settings SET value='0.14.0' WHERE name='content_version'");
    upgradeReject(static fn()=>WorldUpgrade::run($pdo,true,$temporary),'newer world content');
    upgradeCheck(upgradeRows($pdo,'users','id')===$usersBefore,'all rejection paths preserve accounts');
    // Restore the automatic SQL backup into this disposable empty schema and check byte-for-byte data.
    foreach($tables as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}
    upgradeRestoreSql($pdo,$firstSqlBackup);
    upgradeCheck(upgradeRows($pdo,'users','id')===$usersBefore&&upgradeRows($pdo,'bases','user_id')===$basesBefore,'automatic SQL backup restores exact accounts and base JSON');
    upgradeCheck(upgradeRows($pdo,'battles','id')===$battlesBefore&&upgradeRows($pdo,'reports','id')===$reportsBefore&&upgradeRows($pdo,'auth_attempts','identity_hash')===$authBefore,'automatic SQL backup restores battles reports and throttle records');
    upgradeCheck($pdo->query("SELECT value FROM settings WHERE name='schema_version'")->fetchColumn()==='1','SQL restore returns original schema version');
    // Simulate interrupted additive DDL, then fail map initialization after the remaining DDL.
    $pdo->exec("ALTER TABLE battles ADD COLUMN target_kind VARCHAR(20) NOT NULL DEFAULT 'home'");
    preg_match('/CREATE TABLE IF NOT EXISTS world_sites\s*\(.*?;\s*/s',file_get_contents(dirname(__DIR__).'/app/schema.sql'),$siteCreate);
    $pdo->exec($siteCreate[0]);
    $pdo->exec("CREATE TRIGGER upgrade_fail_map BEFORE INSERT ON world_sites FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intentional upgrade test map failure'");
    $resumable=upgradeReject(static fn()=>WorldUpgrade::run($pdo,true,$temporary),'map initialization failure after additive DDL');
    upgradeCheck($pdo->query("SELECT value FROM settings WHERE name='schema_version'")->fetchColumn()==='1','failed data stage never marks schema2 complete');
    upgradeCheck(upgradeRows($pdo,'bases','user_id')===$basesBefore&&(int)$pdo->query('SELECT COUNT(*) FROM world_sites')->fetchColumn()===0,'failed map initialization rolls back base normalization and map rows');
    upgradeCheck((int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='battles' AND COLUMN_NAME='target_id'")->fetchColumn()===1,'completed DDL remains for safe resume');
    $pdo->exec('DROP TRIGGER upgrade_fail_map');
    $resumed=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck($resumed['applied']&&$resumed['schema_steps']===0&&$resumed['source_schema']==='1','rerun resumes partial DDL without duplicate-column errors');
    upgradeCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind<>'fortification'")->fetchColumn()===1321,'resumed upgrade creates each original site exactly once');
    upgradeCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='fortification'")->fetchColumn()>0&&$pdo->query("SELECT value FROM settings WHERE name='world_fortifications_version'")->fetchColumn()==='1','resumed upgrade generates the fortification network once');
    upgradeCheck(upgradeRows($pdo,'users','id')===$usersBefore&&upgradeOriginalRows($pdo,'battles','id',$battlesBefore)===$battlesBefore,'resumed upgrade preserves account records and legacy battles');
    // Upgrade a real schema-2 world exported by the actual frozen v0.5 classes.
    // Every old territory and active battle byte must survive content-only migration.
    $pdo->exec("DELETE FROM world_sites WHERE kind='fortification'");$pdo->exec("DELETE FROM settings WHERE name='world_fortifications_version'");
    $fixture=json_decode(file_get_contents(__DIR__.'/fixtures/world-v05.json'),true,512,JSON_THROW_ON_ERROR);
    upgradeCheck($fixture['source_version']==='0.5.0'&&$fixture['schema_version']==='2','frozen v0.5 fixture identifies its actual source');
    foreach(upgradeRows($pdo,'bases','user_id') as $row){
        $old=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);unset($old['workers'],$old['planner']);$old['content_version']='0.5.0';
        foreach($old['buildings'] as &$building){foreach(['fortification','fortify_ready_at','fortify_to','fortify_paid'] as $key){unset($building[$key]);}}unset($building);
        foreach($old['champions']??[] as $type=>$champion){unset($old['champions'][$type]['bonus_feeds']);}
        $update->execute([json_encode($old,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$row['user_id']]);
    }
    foreach($fixture['bases'] as $id=>$old){$update->execute([json_encode($old,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),(int)$id]);}
    foreach($fixture['sites'] as $row){$statement=$pdo->prepare('UPDATE world_sites SET kind=?,q=?,r=?,owner_id=?,home_user_id=?,name=?,level=?,state_json=?,revision=?,updated_at=? WHERE id=?');$statement->execute([$row['kind'],$row['q'],$row['r'],$row['owner_id'],$row['home_user_id'],$row['name'],$row['level'],$row['state_json'],$row['revision'],$row['updated_at'],$row['id']]);}
    $row=$fixture['active_battle'];$statement=$pdo->prepare('UPDATE battles SET attacker_id=?,defender_id=?,target_kind=?,target_id=?,status=?,state_json=?,created_at=?,updated_at=? WHERE id=?');$statement->execute([$row['attacker_id'],$row['defender_id'],$row['target_kind'],$row['target_id'],$row['status'],$row['state_json'],$row['created_at'],$row['updated_at'],$row['id']]);
    $pdo->exec("UPDATE settings SET value='0.5.0' WHERE name='content_version'");
    // Recreate the released schema-2 input before checking its additive migration.
    upgradeFixtureSchema2($pdo);
    $v5Before=[];foreach(['users'=>'id','bases'=>'user_id','world_sites'=>'id','battles'=>'id','reports'=>'id','settings'=>'name','auth_attempts'=>'identity_hash'] as $table=>$key){$v5Before[$table]=upgradeRows($pdo,$table,$key);}
    $v5Files=glob($temporary.'/*');$v5Check=WorldUpgrade::run($pdo,false,$temporary);
    upgradeCheck($v5Check['source_version']==='0.5.0'&&$v5Check['target_version']==='0.13.1'&&$v5Check['source_schema']==='2'&&$v5Check['target_schema']==='3','v0.5 upgrades content while adding schema-3 tables');
    upgradeCheck($v5Check['schema_steps']===9&&$v5Check['world_update']&&$v5Check['fortifications_update']&&$v5Check['changed_bases']===1001,'existing v0.5 map needs additive forts alongside schema 3');
    upgradeCheck(glob($temporary.'/*')===$v5Files&&upgradeRows($pdo,'bases','user_id')===$v5Before['bases']&&upgradeRows($pdo,'world_sites','id')===$v5Before['world_sites'],'v0.5 dry run writes no backups base data or territories');
    $v5Apply=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck($v5Apply['applied']&&$v5Apply['schema_steps']===9&&$v5Apply['world_sites']===1321+$v5Apply['fortifications']&&$v5Apply['fortifications']>0,'v0.5 apply reports all original sites plus new neutral forts');
    foreach(['users'=>'id','battles'=>'id','reports'=>'id','auth_attempts'=>'identity_hash'] as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v5Before[$table],'v0.5 content upgrade preserves every '.$table.' byte and field');}
    upgradeCheck($pdo->query("SELECT * FROM world_sites WHERE kind<>'fortification' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)===$v5Before['world_sites'],'v0.5 original map rows remain byte-for-byte unchanged');
    upgradeCheck((int)$pdo->query('SELECT COUNT(*) FROM world_sites')->fetchColumn()<=64*64-512,'fortification generation reserves at least 512 free map cells');
    $v5Human=upgradeBase($pdo,1);$v5Bot=upgradeBase($pdo,2);
    foreach([1=>$v5Human,2=>$v5Bot] as $id=>$normalized){
        $old=$fixture['bases'][(string)$id];
        upgradeCheck($normalized['champions']['gorgo']['bonusFeeds']===3&&$normalized['champions']['gorgo']['bonus_feeds']===0,'v0.5 maintenance counter grants no champion food power for user '.$id);
        upgradeCheck($normalized['champions']['gorgo']['hp']===$old['champions']['gorgo']['hp']&&$normalized['champions']['gorgo']['battle_id']===$old['champions']['gorgo']['battle_id'],'v0.5 injury and battle reservation stay unchanged for user '.$id);
        upgradeCheck($normalized['resources']===$old['resources']&&$normalized['last_tick']===$old['last_tick']&&$normalized['world']===$old['world'],'v0.5 balances clocks and paid strategic bonus timers stay unchanged for user '.$id);
        foreach($normalized['buildings'] as $building){upgradeCheck($building['fortification']===0&&$building['fortify_ready_at']===0&&$building['fortify_to']===null&&$building['fortify_paid']===null,'v0.5 building '.$id.':'.$building['id'].' receives only zero unearned fortification defaults');}
    }
    upgradeCheck((float)$v5Bot['champions']['gorgo']['hp']===0.0&&$v5Bot['champions']['gorgo']['frozen'],'v0.5 frozen zero-health champion is not revived');
    $v5Battle=json_decode($pdo->query('SELECT state_json FROM battles WHERE id='.(int)$fixture['active_battle']['id'])->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    upgradeCheck($v5Battle['formatVersion']===5&&$v5Battle['status']==='active'&&count($v5Battle['units'])>=2&&!isset($v5Battle['buildings'][0]['_fortificationReduction']),'real v0.5 deployed invasion keeps its original combat snapshot');
    $v5Backup=json_decode(file_get_contents($v5Apply['backup']),true,512,JSON_THROW_ON_ERROR);
    foreach($v5Before as $table=>$rows){upgradeCheck($v5Backup['tables'][$table]===$rows,'schema2 JSON backup includes exact old '.$table.' rows');}
    $v5Again=WorldUpgrade::run($pdo,true,$temporary);upgradeCheck(!$v5Again['applied']&&$v5Again['changed_bases']===0,'v0.13.1 repeat after v0.5 upgrade is a no-op');
    foreach($tables as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}
    upgradeRestoreSql($pdo,$v5Apply['backup_sql']);
    foreach(['users'=>'id','bases'=>'user_id','world_sites'=>'id','battles'=>'id','reports'=>'id','settings'=>'name','auth_attempts'=>'identity_hash'] as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v5Before[$table],'v0.5 SQL backup restores exact '.$table.' data');}
    upgradeCheck($pdo->query("SELECT value FROM settings WHERE name='content_version'")->fetchColumn()==='0.5.0'&&$pdo->query("SELECT value FROM settings WHERE name='schema_version'")->fetchColumn()==='2','v0.5 restore retains actual prior schema and content');
    // The actual frozen v0.6 release exported paid work, earned powers and a live
    // format-six invasion. Current code must never synthesize its combat snapshot.
    $fixture6=json_decode(file_get_contents(__DIR__.'/fixtures/world-v06.json'),true,512,JSON_THROW_ON_ERROR);
    upgradeCheck($fixture6['source_version']==='0.6.0'&&$fixture6['schema_version']==='2','frozen v0.6 fixture identifies its actual source');
    foreach(upgradeRows($pdo,'bases','user_id') as $row){$old=Game::normalizeBase(json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR));unset($old['workers'],$old['planner']);$old['content_version']='0.6.0';$update->execute([json_encode($old,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$row['user_id']]);}
    foreach($fixture6['bases'] as $id=>$old){$update->execute([json_encode($old,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),(int)$id]);}
    foreach($fixture6['sites'] as $row){$statement=$pdo->prepare('UPDATE world_sites SET kind=?,q=?,r=?,owner_id=?,home_user_id=?,name=?,level=?,state_json=?,revision=?,updated_at=? WHERE id=?');$statement->execute([$row['kind'],$row['q'],$row['r'],$row['owner_id'],$row['home_user_id'],$row['name'],$row['level'],$row['state_json'],$row['revision'],$row['updated_at'],$row['id']]);}
    $row=$fixture6['active_battle'];$statement=$pdo->prepare('UPDATE battles SET attacker_id=?,defender_id=?,target_kind=?,target_id=?,status=?,state_json=?,created_at=?,updated_at=? WHERE id=?');$statement->execute([$row['attacker_id'],$row['defender_id'],$row['target_kind'],$row['target_id'],$row['status'],$row['state_json'],$row['created_at'],$row['updated_at'],$row['id']]);
    $pdo->exec("UPDATE settings SET value='0.6.0' WHERE name='content_version'");
    $v6Before=[];foreach(['users'=>'id','bases'=>'user_id','world_sites'=>'id','battles'=>'id','reports'=>'id','settings'=>'name','auth_attempts'=>'identity_hash'] as $table=>$key){$v6Before[$table]=upgradeRows($pdo,$table,$key);}
    $v6Files=glob($temporary.'/*');$v6Check=WorldUpgrade::run($pdo,false,$temporary);
    upgradeCheck($v6Check['source_version']==='0.6.0'&&$v6Check['target_version']==='0.13.1'&&$v6Check['schema_steps']===9&&$v6Check['fortifications_update'],'v0.6 receives one-time additive fortification content and nine new tables');
    upgradeCheck($v6Check['world_sites']===1321&&$v6Check['fortifications']===0&&$v6Check['changed_bases']===1001,'v0.6 check reports actual pre-upgrade world population');
    upgradeCheck(glob($temporary.'/*')===$v6Files,'v0.6 check writes no backup');
    foreach(['users'=>'id','bases'=>'user_id','world_sites'=>'id','battles'=>'id','reports'=>'id','settings'=>'name','auth_attempts'=>'identity_hash'] as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v6Before[$table],'v0.6 dry run preserves every '.$table.' row');}
    $pdo->exec('SET @upgrade_fort_attempts=0');
    $pdo->exec("CREATE TRIGGER upgrade_fail_forts BEFORE INSERT ON world_sites FOR EACH ROW BEGIN SET @upgrade_fort_attempts=@upgrade_fort_attempts+1; IF @upgrade_fort_attempts=3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intentional fortification generation failure'; END IF; END");
    upgradeReject(static fn()=>WorldUpgrade::run($pdo,true,$temporary),'fortification generation failure rolls back current-schema migration');
    upgradeCheck((int)$pdo->query('SELECT @upgrade_fort_attempts')->fetchColumn()===3,'rollback fixture fails only after two fort rows were inserted');
    $pdo->exec('DROP TRIGGER upgrade_fail_forts');
    foreach(['users'=>'id','bases'=>'user_id','world_sites'=>'id','battles'=>'id','reports'=>'id','settings'=>'name','auth_attempts'=>'identity_hash'] as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v6Before[$table],'failed v0.6 fortification generation preserves '.$table);}
    $v6Apply=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck($v6Apply['applied']&&$v6Apply['schema_steps']===0&&$v6Apply['fortifications_added']>0&&$v6Apply['world_sites']===1321+$v6Apply['fortifications_added'],'v0.6 apply counts actual newly generated forts');
    foreach(['users'=>'id','battles'=>'id','reports'=>'id','auth_attempts'=>'identity_hash'] as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v6Before[$table],'v0.6 migration preserves all '.$table.' fields and bytes');}
    upgradeCheck($pdo->query("SELECT * FROM world_sites WHERE kind<>'fortification' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)===$v6Before['world_sites'],'v0.6 territory ownership coordinates jobs garrisons revisions and JSON remain exact');
    upgradeCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='fortification' AND owner_id IS NOT NULL")->fetchColumn()===0,'new forts begin neutral without granting any existing keeper ownership');
    upgradeCheck($pdo->query("SELECT value FROM settings WHERE name='world_fortifications_version'")->fetchColumn()==='1','successful generation commits its one-time marker');
    foreach($fixture6['bases'] as $id=>$old){
        $saved6=upgradeBase($pdo,(int)$id);$expected6=$old;$expected6['content_version']='0.13.1';$expected6['workers']=1;$expected6['planner']=['slots'=>[]];
        upgradeCheck($saved6===upgradeExpectedRoster($expected6),'frozen v0.6 base '.$id.' retains all old data with worker, planner and empty new species defaults');
        $hall=array_values(array_filter($saved6['buildings'],static fn($b)=>$b['type']==='townhall'))[0];
        upgradeCheck($hall['fortification']===1&&$hall['fortify_to']===2&&$hall['fortify_ready_at']===2000000600&&is_array($hall['fortify_paid']),'v0.6 completed fortification and fully paid future work survive for '.$id);
        upgradeCheck($saved6['champions']['gorgo']['bonus_feeds']===($id==1?2:3),'v0.6 earned champion powers survive for '.$id);
    }
    $active6=json_decode($fixture6['active_battle']['state_json'],true,512,JSON_THROW_ON_ERROR);
    upgradeCheck($active6['formatVersion']===6&&$active6['status']==='active'&&count($active6['units'])>=2&&array_key_exists('_fortificationReduction',$active6['buildings'][0]),'actual v0.6 fixture contains deployed combat with frozen building armor');
    $v6Backup=json_decode(file_get_contents($v6Apply['backup']),true,512,JSON_THROW_ON_ERROR);
    foreach($v6Before as $table=>$rows){upgradeCheck($v6Backup['tables'][$table]===$rows,'v0.6 JSON backup includes exact original '.$table);}
    $v7Sites=upgradeRows($pdo,'world_sites','id');$v7Files=glob($temporary.'/*');$v6Again=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck(!$v6Again['applied']&&!$v6Again['world_update']&&!$v6Again['fortifications_update']&&$v6Again['world_sites']===$v6Apply['world_sites'],'v0.13.1 repeat recognizes completed generation and reports actual population');
    upgradeCheck(upgradeRows($pdo,'world_sites','id')===$v7Sites&&glob($temporary.'/*')===$v7Files,'v0.13.1 repeat neither regenerates forts nor writes redundant backups');
    foreach($tables as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}
    upgradeRestoreSql($pdo,$v6Apply['backup_sql']);
    foreach(['users'=>'id','bases'=>'user_id','world_sites'=>'id','battles'=>'id','reports'=>'id','settings'=>'name','auth_attempts'=>'identity_hash'] as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v6Before[$table],'v0.6 SQL backup restores exact '.$table);}
    unset($v5Before,$v5Backup,$fixture,$v6Before,$v6Backup,$v7Sites,$backup,$upgraded,$basesBefore,$usersBefore,$battlesBefore,$reportsBefore,$authBefore,$labBackup,$labRowsBefore,$championBefore,$beforeReject,$sitesAfter);
    // Frozen v0.7 exports include real simultaneously paid jobs and a format-seven
    // invasion with an owned neighboring Fortification. No current combat code
    // is used to manufacture its saved battle.
    upgradeFixtureSchema2($pdo);
    $fixture7=json_decode(file_get_contents(__DIR__.'/fixtures/world-v07.json'),true,512,JSON_THROW_ON_ERROR);
    upgradeCheck($fixture7['source_version']==='0.7.0'&&$fixture7['schema_version']==='2'&&$fixture7['fortification_marker']==='1','frozen v0.7 fixture records its actual release and completed map marker');
    foreach(upgradeRows($pdo,'bases','user_id') as $row){$old=Game::normalizeBase(json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR));unset($old['workers'],$old['planner']);$old['content_version']='0.7.0';$update->execute([json_encode($old,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$row['user_id']]);}
    foreach($fixture7['bases'] as $id=>$old){$update->execute([json_encode($old,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),(int)$id]);}
    foreach($fixture7['users'] as $row){$statement=$pdo->prepare('UPDATE users SET name=?,password_hash=?,is_bot=?,rank_points=?,wins=?,losses=?,next_turn_at=?,created_at=? WHERE id=?');$statement->execute([$row['name'],$row['password_hash'],$row['is_bot'],$row['rank_points'],$row['wins'],$row['losses'],$row['next_turn_at'],$row['created_at'],$row['id']]);}
    // Earlier rollback/restore exercises leave harmless AUTO_INCREMENT gaps.
    // Author this fresh v0.7 fixture map with the frozen export's exact IDs.
    $nextSiteId=1+(int)$pdo->query('SELECT MAX(id) FROM world_sites')->fetchColumn();
    $pdo->exec('ALTER TABLE world_sites AUTO_INCREMENT='.$nextSiteId);
    World::initialize($pdo);
    foreach($fixture7['sites'] as $row){$statement=$pdo->prepare('UPDATE world_sites SET kind=?,q=?,r=?,owner_id=?,home_user_id=?,name=?,level=?,state_json=?,revision=?,updated_at=? WHERE id=?');$statement->execute([$row['kind'],$row['q'],$row['r'],$row['owner_id'],$row['home_user_id'],$row['name'],$row['level'],$row['state_json'],$row['revision'],$row['updated_at'],$row['id']]);}
    $fortRecord=array_values(array_filter($fixture7['sites'],static fn($site)=>$site['kind']==='fortification'))[0];
    upgradeCheck((int)$pdo->query('SELECT owner_id FROM world_sites WHERE id='.(int)$fortRecord['id'])->fetchColumn()===2,'v0.7 fixture actually contains the exported owned Fortification');
    $row=$fixture7['active_battle'];$statement=$pdo->prepare('UPDATE battles SET attacker_id=?,defender_id=?,target_kind=?,target_id=?,status=?,state_json=?,created_at=?,updated_at=? WHERE id=?');$statement->execute([$row['attacker_id'],$row['defender_id'],$row['target_kind'],$row['target_id'],$row['status'],$row['state_json'],$row['created_at'],$row['updated_at'],$row['id']]);
    $pdo->exec("UPDATE settings SET value='0.7.0' WHERE name='content_version'");
    $pdo->exec("DELETE FROM settings WHERE name IN ('world_activity_v1','bots_schedule_clock')");
    $v7Before=[];foreach(['users'=>'id','bases'=>'user_id','world_sites'=>'id','battles'=>'id','reports'=>'id','settings'=>'name','auth_attempts'=>'identity_hash'] as $table=>$key){$v7Before[$table]=upgradeRows($pdo,$table,$key);}
    $v7OldFiles=glob($temporary.'/*');$v7Check=WorldUpgrade::run($pdo,false,$temporary);
    upgradeCheck($v7Check['source_version']==='0.7.0'&&$v7Check['target_version']==='0.13.1'&&$v7Check['schema_steps']===9,'v0.7 upgrades to v0.13.1 with nine additive tables');
    upgradeCheck(!$v7Check['world_update']&&!$v7Check['fortifications_update']&&$v7Check['world_sites']===$fixture7['map_sites'],'v0.7 completed map is counted exactly without regeneration');
    upgradeCheck($v7Check['changed_bases']===1001&&$v7Check['bots']===1000,'v0.7 check finds all existing bases and keeps 1000 bots');
    upgradeCheck(glob($temporary.'/*')===$v7OldFiles,'v0.7 check writes no backup');
    foreach(['users'=>'id','bases'=>'user_id','world_sites'=>'id','battles'=>'id','reports'=>'id','settings'=>'name','auth_attempts'=>'identity_hash'] as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v7Before[$table],'v0.7 dry run preserves '.$table);}
    $pdo->exec("CREATE TRIGGER upgrade_fail_workers BEFORE UPDATE ON bases FOR EACH ROW BEGIN IF NEW.user_id=3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intentional worker-default migration failure'; END IF; END");
    upgradeReject(static fn()=>WorldUpgrade::run($pdo,true,$temporary),'failed v0.13.1 normalization rolls back earlier base changes');
    $pdo->exec('DROP TRIGGER upgrade_fail_workers');
    foreach(['users'=>'id','bases'=>'user_id','world_sites'=>'id','battles'=>'id','reports'=>'id','settings'=>'name','auth_attempts'=>'identity_hash'] as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v7Before[$table],'failed v0.7 upgrade preserves '.$table);}
    $v7Apply=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck($v7Apply['applied']&&$v7Apply['changed_bases']===1001&&$v7Apply['fortifications_added']===0&&$v7Apply['world_sites']===$fixture7['map_sites'],'v0.7 apply adds compatibility fields without generating any map cell');
    $coldClock=json_decode((string)$pdo->query("SELECT value FROM settings WHERE name='world_activity_v1'")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    upgradeCheck($coldClock['version']===1&&$coldClock['epoch']>0&&$coldClock['observed_at']===$coldClock['epoch']&&$coldClock['active_seconds']===0&&$coldClock['leases']===[]&&$coldClock['last_dispatch_at']===null&&$coldClock['last_result']===null,'cold upgrade initializes an empty paused epoch without human leases or bot dispatch');
    upgradeCheck((int)$pdo->query("SELECT COUNT(*) FROM settings WHERE name='bots_schedule_clock'")->fetchColumn()===0,'cold upgrade does not initialize a bot scheduler baseline or advance its deadlines');
    foreach(['users'=>'id','world_sites'=>'id','battles'=>'id','reports'=>'id','auth_attempts'=>'identity_hash'] as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v7Before[$table],'v0.7 upgrade retains every '.$table.' byte and field');}
    foreach($fixture7['bases'] as $id=>$old){
        $saved7=upgradeBase($pdo,(int)$id);$expected7=$old;$expected7['content_version']='0.13.1';$expected7['workers']=1;$expected7['planner']=['slots'=>[]];
        upgradeCheck($saved7===upgradeExpectedRoster($expected7),'v0.7 base '.$id.' retains all old data with worker, planner and empty new species defaults');
        $construction=Construction::status($saved7,1900000000);
        upgradeCheck($construction['capacity']===1&&$construction['busy']===3&&$construction['available']===0&&$construction['overCapacity']===true,'three previously paid jobs remain running above one-worker capacity for '.$id);
        upgradeReject(static fn()=>Construction::assertAvailable($saved7,1900000000),'grandfathered work blocks only additional jobs for '.$id);
        upgradeCheck($saved7['buildings']===$old['buildings']&&$saved7['champions']===$old['champions'],'paid timers damage fortification tiers and frozen injured champions remain exact for '.$id);
        upgradeCheck(!in_array('planner',array_column($saved7['buildings'],'type'),true),'no Yard Planner building is inserted for '.$id);
        $later=$saved7;Game::accrue($later,2000001000);
        $done=Construction::status($later,2000001000);
        upgradeCheck($done['capacity']===1&&$done['busy']===0&&$done['available']===1,'all three grandfathered jobs finish normally without hiring workers for '.$id);
    }
    $active7=json_decode($fixture7['active_battle']['state_json'],true,512,JSON_THROW_ON_ERROR);
    upgradeCheck($active7['formatVersion']===7&&$active7['status']==='active'&&count($active7['units'])>=2&&$active7['networkDefense']['count']===1,'actual v0.7 battle retains frozen neighboring protection and deployed units');
    $v7Backup=json_decode(file_get_contents($v7Apply['backup']),true,512,JSON_THROW_ON_ERROR);
    foreach($v7Before as $table=>$rows){upgradeCheck($v7Backup['tables'][$table]===$rows,'v0.7 JSON backup retains exact '.$table);}
    // Purchased capacity and both saved layouts survive later idempotent checks.
    $existing8=upgradeBase($pdo,3);$positions=array_map(static fn($building)=>array_intersect_key($building,array_flip(['id','type','x','y'])),$existing8['buildings']);
    $existing8['workers']=4;$existing8['planner']=['slots'=>[1=>['name'=>'Dusk β defense','savedAt'=>1700000030,'positions'=>$positions],2=>['name'=>'Dawn reserve','savedAt'=>1700000040,'positions'=>$positions]],'extension'=>['preserve'=>'private saved-template field']];
    $update->execute([json_encode($existing8,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),3]);
    $v8Rows=upgradeRows($pdo,'bases','user_id');$v8Sites=upgradeRows($pdo,'world_sites','id');$v8Files=glob($temporary.'/*');$v8Again=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck(!$v8Again['applied']&&$v8Again['changed_bases']===0&&!$v8Again['world_update'],'current v0.13.1 world with purchased workers and saved layouts is a no-op');
    upgradeCheck(upgradeBase($pdo,3)===$existing8,'both named layout slots hired workers and extension fields remain exact');
    upgradeCheck(upgradeRows($pdo,'bases','user_id')===$v8Rows&&upgradeRows($pdo,'world_sites','id')===$v8Sites&&glob($temporary.'/*')===$v8Files,'v0.13.1 no-op makes no row changes or redundant backup');
    unset($v7Backup,$v8Rows,$v8Sites,$existing8,$active7,$positions,$old,$saved7,$later);
    foreach($tables as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}
    upgradeRestoreSql($pdo,$v7Apply['backup_sql']);
    foreach(['users'=>'id','bases'=>'user_id','world_sites'=>'id','battles'=>'id','reports'=>'id','settings'=>'name','auth_attempts'=>'identity_hash'] as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v7Before[$table],'v0.7 SQL backup restores exact '.$table);}
    upgradeCheck($pdo->query("SELECT value FROM settings WHERE name='content_version'")->fetchColumn()==='0.7.0'&&$pdo->query("SELECT value FROM settings WHERE name='world_fortifications_version'")->fetchColumn()==='1','restore keeps the actual v0.7 version and completed map marker');
    upgradeFixtureSchema2($pdo);
    unset($v7Before,$v7Backup,$v8Rows,$v8Sites,$rows,$saved7,$existing8);

    // An 0.8-shaped world may already carry private runtime metadata from a
    // maintenance build. The hotfix must preserve it without projecting time,
    // expiring leases, shifting a deadline or advancing an invasion.
    foreach(upgradeRows($pdo,'bases','user_id') as $row){$old=Game::normalizeBase(json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR));$old['content_version']='0.8.0';$update->execute([json_encode($old,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$row['user_id']]);}
    $existing8=json_decode((string)$pdo->query('SELECT state_json FROM bases WHERE user_id=3')->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $positions=array_map(static fn($building)=>array_intersect_key($building,array_flip(['id','type','x','y'])),$existing8['buildings']);
    $existing8['workers']=4;$existing8['planner']=['slots'=>[1=>['name'=>'Dusk β defense','savedAt'=>1700000030,'positions'=>$positions],2=>['name'=>'Dawn reserve','savedAt'=>1700000040,'positions'=>$positions]],'extension'=>['preserve'=>'private saved-template field']];
    $existing8['_activity_clock']=['epoch'=>1700000000,'pausedSeconds'=>700];
    $existing8['custom_runtime_extension']=['preserve'=>['nested'=>true],'timestamp'=>1700000300];
    $update->execute([json_encode($existing8,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),3]);
    $activity=['version'=>1,'epoch'=>1700000000,'observed_at'=>1700001000,'active_seconds'=>300,'leases'=>[hash('sha256','fixture-tab')=>['user_id'=>1,'session'=>hash('sha256','fixture-session'),'expires_at'=>1700001045]],'last_dispatch_at'=>1700000998,'last_result'=>['status'=>'ok','processed'=>17,'battles_finished'=>2,'last_tick'=>1700000998],'unknown_extension'=>['preserve'=>'private clock extension']];
    $setting=$pdo->prepare('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
    $setting->execute(['content_version','0.8.0']);
    $setting->execute(['world_activity_v1',json_encode($activity,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)]);
    $setting->execute(['bots_schedule_clock',json_encode(['epoch'=>1700000000,'pausedSeconds'=>700],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)]);
    $siteId=(int)$fortRecord['id'];$siteState=json_decode((string)$pdo->query('SELECT state_json FROM world_sites WHERE id='.$siteId)->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $siteState['_activity_clock']=['epoch'=>1700000000,'pausedSeconds'=>689];$siteState['_activity_owner']=2;
    $pdo->prepare('UPDATE world_sites SET state_json=? WHERE id=?')->execute([json_encode($siteState,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$siteId]);
    $battleId=(int)$fixture7['active_battle']['id'];$battleState=json_decode((string)$pdo->query('SELECT state_json FROM battles WHERE id='.$battleId)->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $battleState['_activity_clock']=['epoch'=>1700000000,'activeSeconds'=>275,'elapsed'=>12.5];
    $pdo->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($battleState,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$battleId]);
    $recordKeys=['users'=>'id','bases'=>'user_id','world_sites'=>'id','battles'=>'id','reports'=>'id','settings'=>'name','auth_attempts'=>'identity_hash'];
    $v8Before=[];foreach($recordKeys as $table=>$key){$v8Before[$table]=upgradeRows($pdo,$table,$key);}
    $v8Files=glob($temporary.'/*');$v8Check=WorldUpgrade::run($pdo,false,$temporary);
    upgradeCheck($v8Check['source_version']==='0.8.0'&&$v8Check['target_version']==='0.13.1'&&$v8Check['source_schema']==='2'&&$v8Check['target_schema']==='3'&&$v8Check['schema_steps']===9,'0.8.0 is accepted for the 0.13.1 update with nine additive tables');
    upgradeCheck($v8Check['changed_bases']===1001&&$v8Check['bots']===1000&&!$v8Check['world_update']&&!$v8Check['fortifications_update'],'hotfix changes version metadata without generating bots or map cells');
    foreach($recordKeys as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v8Before[$table],'0.8.0 clock-aware check preserves every '.$table.' row');}
    upgradeCheck(glob($temporary.'/*')===$v8Files,'clock-aware read-only check writes no backup');
    $pdo->exec("CREATE TRIGGER upgrade_fail_clock BEFORE UPDATE ON bases FOR EACH ROW BEGIN IF NEW.user_id=3 THEN UPDATE settings SET value='reset' WHERE name='world_activity_v1'; END IF; END");
    $preservationError='';try{WorldUpgrade::run($pdo,true,$temporary);}catch(Throwable $error){$preservationError=$error->getMessage();}
    $pdo->exec('DROP TRIGGER upgrade_fail_clock');
    upgradeCheck(str_contains($preservationError,'Saved setting world_activity_v1 changed'),'updater explicitly rejects a runtime clock reset during migration');
    foreach($recordKeys as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v8Before[$table],'failed clock-preservation check rolls back '.$table);}
    $v8Apply=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck($v8Apply['applied']&&$v8Apply['changed_bases']===1001&&$v8Apply['fortifications_added']===0&&$v8Apply['world_sites']===$fixture7['map_sites'],'hotfix applies to all existing yards without extra sites');
    foreach(['users','world_sites','battles','reports','auth_attempts'] as $table){upgradeCheck(upgradeRows($pdo,$table,$recordKeys[$table])===$v8Before[$table],'hotfix preserves complete '.$table.' records including ranks timers and clock metadata');}
    $allBaseStateExact=true;$actualBases=upgradeRows($pdo,'bases','user_id');
    foreach($v8Before['bases'] as $index=>$original){$expected=json_decode($original['state_json'],true,512,JSON_THROW_ON_ERROR);$expected['content_version']='0.13.1';$actual=json_decode($actualBases[$index]['state_json'],true,512,JSON_THROW_ON_ERROR);$allBaseStateExact=$allBaseStateExact&&$actual===upgradeExpectedRoster($expected)&&$actualBases[$index]['user_id']===$original['user_id'];}
    upgradeCheck($allBaseStateExact,'all 1001 bases retain every field except the content version, including saved layouts and paid work');
    unset($actualBases,$actual,$expected);
    $expectedSettings=$v8Before['settings'];foreach($expectedSettings as &$savedSetting){if($savedSetting['name']==='schema_version'){$savedSetting['value']='3';}if($savedSetting['name']==='content_version'){$savedSetting['value']='0.13.1';}}unset($savedSetting);
    upgradeCheck(upgradeRows($pdo,'settings','name')===$expectedSettings,'presence leases clock counters scheduler baseline and unknown settings remain byte-for-byte unchanged');
    $v8Backup=json_decode(file_get_contents($v8Apply['backup']),true,512,JSON_THROW_ON_ERROR);
    foreach($recordKeys as $table=>$key){upgradeCheck($v8Backup['tables'][$table]===$v8Before[$table],'hotfix JSON backup preserves exact clock-aware '.$table);}
    unset($v8Backup);
    $v81Digests=[];foreach($recordKeys as $table=>$key){$v81Digests[$table]=hash('sha256',serialize(upgradeRows($pdo,$table,$key)));}
    $v81Files=glob($temporary.'/*');$v81Again=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck(!$v81Again['applied']&&$v81Again['changed_bases']===0&&!$v81Again['settings_update'],'current clock-aware hotfix world is a no-op');
    foreach($recordKeys as $table=>$key){upgradeCheck(hash('sha256',serialize(upgradeRows($pdo,$table,$key)))===$v81Digests[$table],'hotfix no-op preserves complete '.$table.' records');}
    upgradeCheck(glob($temporary.'/*')===$v81Files,'hotfix no-op writes no redundant backup');
    foreach($tables as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}
    upgradeRestoreSql($pdo,$v8Apply['backup_sql']);
    foreach($recordKeys as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$v8Before[$table],'0.8.0 SQL backup restores exact clock-aware '.$table);}
    upgradeFixtureSchema2($pdo);
    // The released 0.10.0 baseline may contain destroyed buildings after a
    // completed raid while another saved invasion remains active. Installing
    // the defense practice must preserve that damage, paid work and bot state.
    // Retain a direct compatibility check for the earlier online-activity release.
    $setting->execute(['content_version','0.8.1']);
    $onlineVersion = WorldUpgrade::run($pdo, false, $temporary);
    upgradeCheck($onlineVersion['source_version'] === '0.8.1' && $onlineVersion['target_version'] === '0.13.1', '0.8.1 supports a direct upgrade');
    $setting->execute(['content_version','0.8.2']);
    $repairVersion = WorldUpgrade::run($pdo, false, $temporary);
    upgradeCheck($repairVersion['source_version'] === '0.8.2' && $repairVersion['target_version'] === '0.13.1', '0.8.2 supports a direct upgrade');
    $setting->execute(['content_version','0.9.0']);
    $defenseVersion = WorldUpgrade::run($pdo, false, $temporary);
    upgradeCheck($defenseVersion['source_version']==='0.9.0'&&$defenseVersion['schema_steps']===9,'0.9.0 supports a direct upgrade');
    foreach(upgradeRows($pdo,'bases','user_id') as $row){$old=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);$old['content_version']='0.10.0';$update->execute([json_encode($old,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$row['user_id']]);}
    $damagedBefore=[];
    foreach([$human,2] as $damagedId){
        $damaged=upgradeBase($pdo,$damagedId);$damaged['buildings'][0]['hp']=0;$damaged['buildings'][1]['hp']=1;
        $damaged['repair_fixture']=['last_invasion'=>'finished','manual_repair_requested'=>false];
        $update->execute([json_encode($damaged,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$damagedId]);$damagedBefore[$damagedId]=$damaged;
    }
    $setting->execute(['content_version','0.10.0']);
    $repairBefore=[];foreach($recordKeys as $table=>$key){$repairBefore[$table]=upgradeRows($pdo,$table,$key);}
    $repairFiles=glob($temporary.'/*');$repairCheck=WorldUpgrade::run($pdo,false,$temporary);
    upgradeCheck($repairCheck['source_version']==='0.10.0'&&$repairCheck['target_version']==='0.13.1'&&$repairCheck['schema_steps']===9&&!$repairCheck['world_update'],'released 0.10.0 accepts the 0.11 update with nine tables and no map changes');
    foreach($recordKeys as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$repairBefore[$table],'0.10.0 0.11 update check preserves every '.$table.' row');}
    upgradeCheck(glob($temporary.'/*')===$repairFiles,'0.11 update check creates no backup or other files');
    $repairApply=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck($repairApply['applied']&&$repairApply['bots']===1000&&$repairApply['changed_bases']===1001&&$repairApply['fortifications_added']===0,'0.11 update changes content version for all existing bases without reseeding or adding map sites');
    foreach($damagedBefore as $damagedId=>$old){
        $expected=$old;$expected['content_version']='0.13.1';$actual=upgradeBase($pdo,$damagedId);
        upgradeCheck($actual===upgradeExpectedRoster($expected),'0.11 update preserves destroyed and damaged buildings, paid jobs and every saved field for keeper '.$damagedId);
        upgradeCheck($actual['buildings'][0]['hp']===0&&$actual['buildings'][1]['hp']===1,'0.11 update does not heal the human or bot before a normal repair action for keeper '.$damagedId);
    }
    $repairExact=true;$actualBases=upgradeRows($pdo,'bases','user_id');
    foreach($repairBefore['bases'] as $index=>$original){$expected=json_decode($original['state_json'],true,512,JSON_THROW_ON_ERROR);$expected['content_version']='0.13.1';$repairExact=$repairExact&&json_decode($actualBases[$index]['state_json'],true,512,JSON_THROW_ON_ERROR)===upgradeExpectedRoster($expected)&&$actualBases[$index]['user_id']===$original['user_id'];}
    upgradeCheck($repairExact,'0.10.0 0.11 update preserves all 1001 complete base payloads except their content version');
    foreach(['users','world_sites','battles','reports','auth_attempts'] as $table){upgradeCheck(upgradeRows($pdo,$table,$recordKeys[$table])===$repairBefore[$table],'0.11 update preserves complete '.$table.' including ranks, active invasions and ownership');}
    $expectedSettings=$repairBefore['settings'];foreach($expectedSettings as &$savedSetting){if($savedSetting['name']==='schema_version'){$savedSetting['value']='3';}if($savedSetting['name']==='content_version'){$savedSetting['value']='0.13.1';}}unset($savedSetting);
    upgradeCheck(upgradeRows($pdo,'settings','name')===$expectedSettings,'0.11 update leaves private presence and bot clocks byte-for-byte intact');
    $repairAfter=[];foreach($recordKeys as $table=>$key){$repairAfter[$table]=upgradeRows($pdo,$table,$key);}
    $repairFiles=glob($temporary.'/*');$repairAgain=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck(!$repairAgain['applied']&&$repairAgain['changed_bases']===0,'repeat 0.11 upgrade is a no-op');
    foreach($recordKeys as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$repairAfter[$table],'0.11 no-op preserves '.$table);}
    upgradeCheck(glob($temporary.'/*')===$repairFiles,'0.11 no-op writes no redundant backup');
    foreach($tables as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}
    upgradeRestoreSql($pdo,$repairApply['backup_sql']);
    foreach($recordKeys as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$repairBefore[$table],'0.10.0 SQL backup restores exact pre-update '.$table);}
    // The immediately previous release must remain accepted after TARGET changes.
    WorldUpgrade::run($pdo,true,$temporary);
    $pdo->exec("UPDATE settings SET value='0.13.0' WHERE name='content_version'");
    $stamp=$pdo->prepare('UPDATE bases SET state_json=? WHERE user_id=?');
    foreach(upgradeRows($pdo,'bases','user_id') as $row){$state=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);$state['content_version']='0.13.0';$stamp->execute([json_encode($state,JSON_THROW_ON_ERROR),$row['user_id']]);}
    $priorRelease=[];foreach($recordKeys as $table=>$key){$priorRelease[$table]=upgradeRows($pdo,$table,$key);}
    $brandingCheck=WorldUpgrade::run($pdo,false,$temporary);
    upgradeCheck($brandingCheck['source_version']==='0.13.0'&&$brandingCheck['target_version']==='0.13.1'&&$brandingCheck['schema_steps']===0,'0.13.0 is accepted for the Rehatched release without schema changes');
    foreach($recordKeys as $table=>$key){upgradeCheck(upgradeRows($pdo,$table,$key)===$priorRelease[$table],'Rehatched preview preserves every '.$table.' row');}
    $brandingApply=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck($brandingApply['applied']&&$brandingApply['bots']===1000&&$brandingApply['database']===$name,'branding update preserves the selected database and all 1,000 bot identities');
    foreach($priorRelease['bases'] as $row){$expected=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);$expected['content_version']='0.13.1';upgradeCheck(upgradeBase($pdo,(int)$row['user_id'])===$expected,'branding update changes only the base content version for keeper '.$row['user_id']);}
    foreach(['users','world_sites','battles','reports','auth_attempts'] as $table){upgradeCheck(upgradeRows($pdo,$table,$recordKeys[$table])===$priorRelease[$table],'branding update preserves complete '.$table.' records');}
    $brandingAgain=WorldUpgrade::run($pdo,true,$temporary);
    upgradeCheck(!$brandingAgain['applied']&&$brandingAgain['changed_bases']===0,'reapplying the branding release is a no-op');
    echo "Upgrade integration: $assertions assertions passed, including 1,000-bot preservation.\n";
}finally{
    if($pdo->inTransaction()){$pdo->rollBack();}
    foreach($tables as $table){$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}
    if(is_dir($temporary)){foreach(glob($temporary.'/*') as $file){if(is_file($file)){unlink($file);}}rmdir($temporary);}
}
