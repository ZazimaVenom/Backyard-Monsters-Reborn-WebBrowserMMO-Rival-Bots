<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';

/** Additive schema upgrade. MySQL/MariaDB DDL commits implicitly; completed DDL is resumable. */
final class WorldUpgrade
{
    private const TARGET='0.13.1';
    private const SCHEMA='3';
    private const TABLES=['users'=>'id','bases'=>'user_id','battles'=>'id','reports'=>'id','settings'=>'name','auth_attempts'=>'identity_hash','world_sites'=>'id','alliances'=>'id','alliance_members'=>'user_id','alliance_requests'=>'id','keeper_friends'=>'id','keeper_gifts'=>'id','keeper_mail'=>'id','alliance_stances'=>'id','alliance_wars'=>'id','alliance_war_battles'=>'battle_id'];
    private const ADDITIVE_TABLES=['world_sites','alliances','alliance_members','alliance_requests','keeper_friends','keeper_gifts','keeper_mail','alliance_stances','alliance_wars','alliance_war_battles'];

    public static function run(PDO $pdo,bool $apply=false,?string $backupDirectory=null): array
    {
        if(!method_exists(Game::class,'normalizeBase')||!class_exists('World')||!method_exists(World::class,'initialize')||!class_exists('Construction')||!class_exists('Planner')||!class_exists('WorldActivity')||!method_exists(WorldActivity::class,'initialize')){throw new RuntimeException('This release is incomplete. Install all application files before upgrading. No changes were made.');}
        if($pdo->inTransaction()){throw new RuntimeException('Run the upgrade outside any existing database transaction.');}
        $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if($database===''){throw new RuntimeException('No world database is selected.');}
        $lock='bym:world:'.substr(hash('sha256',$database),0,32);
        $statement=$pdo->prepare('SELECT GET_LOCK(?,0)');$statement->execute([$lock]);
        if((int)$statement->fetchColumn()!==1){throw new RuntimeException('The world worker is busy. Stop Apache/the HTTP server and the world worker, keep MySQL running, then rerun the upgrade.');}
        $backup=null;$ddlStarted=false;
        try{
            $pdo->beginTransaction();
            [$tables,$schemas]=self::snapshot($pdo,$database);
            $settings=$pdo->query("SELECT name,value FROM settings WHERE name IN ('schema_version','content_version','world_fortifications_version')")->fetchAll(PDO::FETCH_KEY_PAIR);
            $schema=(string)($settings['schema_version']??'');
            if(!in_array($schema,['1','2',self::SCHEMA],true)){throw new RuntimeException('Only installed database schema 1, 2 or 3 is supported. No changes were made.');}
            $source=(string)($settings['content_version']??'0.1.0');
            if(version_compare($source,self::TARGET,'>')){throw new RuntimeException('This world uses newer content; refusing to downgrade it.');}
            if(!in_array($source,['0.1.0','0.2.0','0.3.0','0.4.0','0.4.1','0.5.0','0.6.0','0.7.0','0.8.0','0.8.1','0.8.2','0.9.0','0.10.0','0.11.0','0.12.0','0.13.0',self::TARGET],true)){throw new RuntimeException('Unsupported source content version. No changes were made.');}
            if($tables['users']['count']===0||$tables['bases']['count']!==$tables['users']['count']){throw new RuntimeException('An installed world must contain an existing base for every user. Repair the installation before upgrading.');}
            if((int)$pdo->query('SELECT COUNT(*) FROM users u LEFT JOIN bases b ON b.user_id=u.id WHERE b.user_id IS NULL')->fetchColumn()!==0){throw new RuntimeException('User and base identities do not match. Repair the installation before upgrading.');}
            $changes=self::normalizeBases($pdo,false);
            $ddl=self::schemaPlan($pdo,$database,$tables);
            $homes=isset($tables['world_sites'])?(int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='home'")->fetchColumn():0;
            $fortifications=isset($tables['world_sites'])?(int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='fortification'")->fetchColumn():0;
            $fortificationsUpdate=($settings['world_fortifications_version']??'')!=='1';
            $worldUpdate=$homes!==$tables['users']['count']||$fortificationsUpdate||!World::hasHomeValleyAnchors($pdo);
            $bots=(int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn();
            $result=['mode'=>$apply?'apply':'check','source_version'=>$source,'target_version'=>self::TARGET,'source_schema'=>$schema,'target_schema'=>self::SCHEMA,'database'=>$database,'users'=>$tables['users']['count'],'bots'=>$bots,'bases'=>$tables['bases']['count'],'changed_bases'=>$changes,'schema_steps'=>count($ddl),'world_update'=>$worldUpdate,'fortifications_update'=>$fortificationsUpdate,'world_sites'=>$tables['world_sites']['count']??0,'fortifications'=>$fortifications,'settings_update'=>$source!==self::TARGET||$schema!==self::SCHEMA,'applied'=>false,'backup'=>null,'backup_sql'=>null];
            if(!$apply||(!$changes&&!$ddl&&!$worldUpdate&&!$result['settings_update'])){$pdo->rollBack();return $result;}
            $activityDigest=$pdo->prepare('SELECT SHA2(value,256) FROM settings WHERE name=?');
            $activityDigest->execute([WorldActivity::SETTING]);$oldActivity=$activityDigest->fetchColumn();
            $preserveActivity=$oldActivity!==false;
            $savedSettings=self::settingsDigest($pdo,$preserveActivity);
            $backup=self::backup($pdo,$backupDirectory??dirname(__DIR__).'/var/backups',[
                'format'=>'backyard-rehatched-world-backup-v2','created_at'=>gmdate('c'),'database'=>$database,
                'source_version'=>$source,'target_version'=>self::TARGET,'schema_version'=>$schema,
                'table_schemas'=>$schemas,'tables'=>$tables,
            ]);
            // Backups are flushed and finalized before releasing row locks or issuing any DDL.
            // DDL cannot be rolled back on MySQL/MariaDB. Each step tests actual schema state.
            $pdo->commit();
            foreach($ddl as $sql){$ddlStarted=true;$pdo->exec($sql);}
            $pdo->beginTransaction();
            [$locked]=self::snapshot($pdo,$database,$tables);
            foreach($tables as $table=>$before){
                if(!isset($locked[$table])||$before['count']!==$locked[$table]['count']||$before['sha256']!==$locked[$table]['sha256']){
                    throw new RuntimeException('World data changed while preparing the upgrade. Stop all game requests and the worker, then rerun. No base normalization or version update was committed.');
                }
            }
            if(self::normalizeBases($pdo,true)!==$changes){throw new RuntimeException('Base normalization changed unexpectedly. No data changes were committed.');}
            $oldSiteMaximum=isset($tables['world_sites'])?(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM world_sites')->fetchColumn():null;
            if($worldUpdate)World::initialize($pdo);
            if($oldSiteMaximum!==null){
                $after=self::fingerprint($pdo,'world_sites',$tables['world_sites']['columns'],$oldSiteMaximum);
                if($tables['world_sites']['count']!==$after['count']||$tables['world_sites']['sha256']!==$after['sha256']){throw new RuntimeException('Existing territory ownership, coordinates or saved state changed during initialization. Data changes were rolled back.');}
            }
            $homeCount=(int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='home'")->fetchColumn();
            $resourceCount=(int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='resource'")->fetchColumn();
            $strongholdCount=(int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='stronghold'")->fetchColumn();
            if($homeCount!==$tables['users']['count']||!World::hasHomeValleyAnchors($pdo)){throw new RuntimeException('World-map initialization did not produce the expected homes and sites. Data changes were rolled back; rerun after correcting the installation.');}
            $fortificationCount=(int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='fortification'")->fetchColumn();
            $siteCount=(int)$pdo->query('SELECT COUNT(*) FROM world_sites')->fetchColumn();
            if($pdo->query("SELECT value FROM settings WHERE name='world_fortifications_version'")->fetchColumn()!=='1'){throw new RuntimeException('Fortification-map initialization did not complete. Data changes were rolled back; install the complete release and rerun.');}
            if($siteCount!==$homeCount+$resourceCount+$strongholdCount+$fortificationCount){throw new RuntimeException('The saved map contains unsupported site kinds. Data changes were rolled back.');}
            $set=$pdo->prepare('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
            $set->execute(['content_version',self::TARGET]);$set->execute(['schema_version',self::SCHEMA]);
            // Presence leases and activity-clock baselines are private runtime
            // state. Upgrading must neither dispatch bots nor reset that state.
            // A missing empty epoch starts the pause immediately, before the
            // first human opens the upgraded game. Existing JSON is untouched.
            WorldActivity::initialize($pdo);
            if($savedSettings!==self::settingsDigest($pdo,$preserveActivity)){
                if($preserveActivity){
                    $activityDigest->execute([WorldActivity::SETTING]);
                    if($activityDigest->fetchColumn()!==$oldActivity)throw new RuntimeException('Saved setting '.WorldActivity::SETTING.' changed during upgrade. Data changes were rolled back.');
                }
                throw new RuntimeException('Saved settings changed during upgrade. Data changes were rolled back.');
            }
            foreach(['users','battles','reports','auth_attempts','alliances','alliance_members','alliance_requests','keeper_friends','keeper_gifts','keeper_mail','alliance_stances','alliance_wars','alliance_war_battles'] as $table){
                if(!isset($tables[$table])){continue;}
                $after=self::fingerprint($pdo,$table,$tables[$table]['columns']);
                if($tables[$table]['count']!==$after['count']||$tables[$table]['sha256']!==$after['sha256']){throw new RuntimeException('Saved '.$table.' preservation check failed. Data changes were rolled back.');}
            }
            if((int)$pdo->query('SELECT COUNT(*) FROM bases')->fetchColumn()!==$tables['bases']['count']){throw new RuntimeException('Base preservation check failed. Data changes were rolled back.');}
            $pdo->commit();
            return array_replace($result,['world_sites'=>$siteCount,'fortifications'=>$fortificationCount,'fortifications_added'=>$fortificationCount-$fortifications,'applied'=>true,'backup'=>$backup['json'],'backup_sha256'=>hash_file('sha256',$backup['json']),'backup_sql'=>$backup['sql'],'backup_sql_sha256'=>hash_file('sha256',$backup['sql'])]);
        }catch(Throwable $error){
            if($pdo->inTransaction()){$pdo->rollBack();}
            if($backup!==null){fwrite(STDERR,'Pre-upgrade backups remain at '.$backup['json'].' and '.$backup['sql']."\n");}
            if($ddlStarted){throw new RuntimeException('The data transaction was not committed. Completed additive SQL schema steps remain in place; keep the backups and rerun --apply to resume. '.$error->getMessage(),0,$error);}
            throw $error;
        }finally{
            try{$statement=$pdo->prepare('SELECT RELEASE_LOCK(?)');$statement->execute([$lock]);}
            catch(Throwable $lockError){error_log('Upgrade lock release: '.$lockError->getMessage());}
        }
    }

    /** Chat, purchases and player settings may be large. Hash the protected values one row at a time. */
    private static function settingsDigest(PDO $pdo,bool $preserveActivity): string
    {
        $skip=['content_version','schema_version','world_allocator','world_fortifications_version'];
        if(!$preserveActivity)$skip[]=WorldActivity::SETTING;
        $hash=hash_init('sha256');
        foreach(self::rows($pdo,'settings',['name','value']) as $row){
            if(!in_array($row['name'],$skip,true))hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
        }
        return hash_final($hash);
    }

    /** Keep only small schema metadata and incremental digests, never complete history tables. */
    private static function snapshot(PDO $pdo,string $database,array $reference=[]): array
    {
        $tables=[];$schemas=[];
        foreach(self::TABLES as $table=>$key){
            $engine=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');$engine->execute([$database,$table]);$found=$engine->fetchColumn();
            if($found===false&&in_array($table,self::ADDITIVE_TABLES,true)){continue;}
            if(strcasecmp((string)$found,'InnoDB')!==0){throw new RuntimeException('Missing or unsupported table '.$table.'. An installed InnoDB world is required.');}
            $schemas[$table]=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
            $columns=$reference[$table]['columns']??$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN,0);
            $tables[$table]=self::fingerprint($pdo,$table,$columns);
        }
        return [$tables,$schemas];
    }

    private static function fingerprint(PDO $pdo,string $table,array $columns,?int $maximumSiteId=null): array
    {
        $hash=hash_init('sha256');$count=0;
        foreach(self::rows($pdo,$table,$columns,$maximumSiteId) as $row){
            hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");++$count;
        }
        return ['count'=>$count,'sha256'=>hash_final($hash),'columns'=>$columns];
    }

    /** mysqlnd buffering must be disabled as well as avoiding fetchAll. */
    private static function rows(PDO $pdo,string $table,array $columns,?int $maximumSiteId=null): Generator
    {
        $buffered=$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$statement=null;
        try{
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
            $fields=implode(',',array_map(static fn(string $name):string=>'`'.str_replace('`','``',$name).'`',$columns));
            $where=$maximumSiteId!==null&&$table==='world_sites'?' WHERE id<='.(int)$maximumSiteId:'';
            $statement=$pdo->query('SELECT '.$fields.' FROM `'.$table.'`'.$where.' ORDER BY `'.self::TABLES[$table].'` FOR UPDATE');
            while(($row=$statement->fetch(PDO::FETCH_ASSOC))!==false){yield $row;}
        }finally{
            if($statement!==null){$statement->closeCursor();}
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);
        }
    }

    /** Eight-row keyset batches allow writes after each cursor closes with bounded memory. */
    private static function normalizeBases(PDO $pdo,bool $apply): int
    {
        $changed=0;$afterId=0;$now=time();
        $select=$pdo->prepare('SELECT user_id,state_json FROM bases WHERE user_id>? ORDER BY user_id LIMIT 8 FOR UPDATE');
        $update=$apply?$pdo->prepare('UPDATE bases SET state_json=?,updated_at=? WHERE user_id=?'):null;
        do{
            $select->execute([$afterId]);$batch=$select->fetchAll(PDO::FETCH_ASSOC);$select->closeCursor();
            foreach($batch as $row){
                $afterId=(int)$row['user_id'];$base=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);
                if(!is_array($base)){throw new RuntimeException('Invalid saved base for user '.$afterId.'.');}
                if(isset($base['content_version'])&&version_compare((string)$base['content_version'],self::TARGET,'>')){throw new RuntimeException('A saved base uses a newer content version. Refusing to downgrade it.');}
                $normalized=Game::normalizeBase($base);
                if(($normalized['content_version']??null)!==self::TARGET){throw new RuntimeException('The application normalizer does not match this updater. Install the complete release before upgrading.');}
                self::assertPreserved($base,$normalized,$afterId);
                if($normalized!==$base){++$changed;if($update!==null){$update->execute([json_encode($normalized,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$now,$afterId]);}}
            }
        }while(count($batch)===8);
        return $changed;
    }

    private static function schemaPlan(PDO $pdo,string $database,array $tables): array
    {
        $q=$pdo->prepare('SELECT COLUMN_NAME,IS_NULLABLE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'battles\'');$q->execute([$database]);$columns=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$columns[$row['COLUMN_NAME']]=$row;}
        $ddl=[];
        if(!isset($columns['defender_id'])||!preg_match('/^bigint(?:\(\d+\))? unsigned$/i',$columns['defender_id']['COLUMN_TYPE'])){throw new RuntimeException('The saved battle defender column has an unsupported definition. No migration was started.');}
        if(isset($columns['target_kind'])&&($columns['target_kind']['COLUMN_TYPE']!=='varchar(20)'||$columns['target_kind']['IS_NULLABLE']!=='NO')){throw new RuntimeException('The existing battle target-kind column does not match this release. No migration was started.');}
        if(isset($columns['target_id'])&&(!preg_match('/^bigint(?:\(\d+\))? unsigned$/i',$columns['target_id']['COLUMN_TYPE'])||$columns['target_id']['IS_NULLABLE']!=='YES')){throw new RuntimeException('The existing battle target-id column does not match this release. No migration was started.');}
        if(($columns['defender_id']['IS_NULLABLE']??'')!=='YES'){$ddl[]='ALTER TABLE battles MODIFY defender_id BIGINT UNSIGNED NULL';}
        if(!isset($columns['target_kind'])){$ddl[]="ALTER TABLE battles ADD COLUMN target_kind VARCHAR(20) NOT NULL DEFAULT 'home'";}
        if(!isset($columns['target_id'])){$ddl[]='ALTER TABLE battles ADD COLUMN target_id BIGINT UNSIGNED NULL';}
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'battles\' AND INDEX_NAME=\'battle_target\'');$q->execute([$database]);
        if((int)$q->fetchColumn()===0){$ddl[]='ALTER TABLE battles ADD INDEX battle_target (target_kind,target_id,status)';}
        $schema=file_get_contents(dirname(__DIR__).'/app/schema.sql');
        foreach(self::ADDITIVE_TABLES as $table){
            if(isset($tables[$table])){continue;}
            if($schema===false||!preg_match('/CREATE TABLE IF NOT EXISTS '.$table.'\s*\(.*?;\s*/s',$schema,$matches)){throw new RuntimeException('The '.$table.' schema is missing from this release. No changes were made.');}
            $ddl[]=trim($matches[0]);
        }
        if(isset($tables['alliances'])&&!in_array('bot_charter',$tables['alliances']['columns'],true)){
            $ddl[]='ALTER TABLE alliances ADD COLUMN bot_charter TINYINT UNSIGNED NULL, ADD UNIQUE KEY bot_charter_slot (bot_charter)';
        }
        return $ddl;
    }
    private static function assertPreserved(array $before,array $after,int $id): void
    {
        // These are the only fields the release normalizer may introduce/extend.
        // Old worlds receive one standard worker and empty slots, never free hires
        // or a Planner building. Already-paid parallel jobs are immutable below.
        if(!array_key_exists('workers',$before)&&($after['workers']??null)!==1){throw new RuntimeException('Normalizer granted extra workers for user '.$id.'.');}
        if(!array_key_exists('planner',$before)&&($after['planner']??null)!==['slots'=>[]]){throw new RuntimeException('Normalizer granted saved layouts for user '.$id.'.');}
        if(is_array($before['planner']??null)&&!array_key_exists('slots',$before['planner'])&&($after['planner']['slots']??null)!==[]){throw new RuntimeException('Normalizer granted layout slots for user '.$id.'.');}
        $extended=['content_version','buildings','unlocked','unlockQueue','garrison','army','research','champions','lab','world','planner'];
        foreach($before as $key=>$value){
            if(!in_array($key,$extended,true)&&(!array_key_exists($key,$after)||$after[$key]!==$value)){
                throw new RuntimeException('Normalizer changed existing '.$key.' for user '.$id.'. Upgrade cancelled.');
            }
        }
        foreach(['army','research'] as $key){
            foreach(($before[$key]??[]) as $type=>$value){
                if(!array_key_exists($type,$after[$key]??[])||$after[$key][$type]!==$value){throw new RuntimeException('Normalizer changed an existing '.$key.' value for user '.$id.'.');}
            }
        }
        foreach(['unlocked','unlockQueue','garrison'] as $key){
            if(isset($before[$key])&&$key!=='unlocked'&&$before[$key]!==($after[$key]??null)){throw new RuntimeException('Normalizer changed saved '.$key.' for user '.$id.'.');}
        }
        foreach(($before['unlocked']??[]) as $type){if(!in_array($type,$after['unlocked']??[],true)){throw new RuntimeException('Normalizer removed a species unlock for user '.$id.'.');}}
        if(count($before['buildings']??[])!==count($after['buildings']??[])){throw new RuntimeException('Normalizer added or removed a saved building for user '.$id.'.');}
        $fortificationDefaults=['fortification'=>0,'fortify_ready_at'=>0,'fortify_to'=>null,'fortify_paid'=>null];
        foreach(($before['buildings']??[]) as $index=>$building){
            $normalized=$after['buildings'][$index]??null;
            self::assertExistingFields($building,$normalized,'buildings.'.$index,$id);
            foreach(array_diff_key($normalized,$building) as $key=>$value){
                if(!array_key_exists($key,$fortificationDefaults)||$fortificationDefaults[$key]!==$value){throw new RuntimeException('Normalizer granted a fortification or changed building fields for user '.$id.'.');}
            }
        }
        foreach(['champions','lab','world','planner'] as $field){
            if(array_key_exists($field,$before)){self::assertExistingFields($before[$field],$after[$field]??null,$field,$id);}
        }
        foreach(($before['champions']??[]) as $type=>$champion){
            if(is_array($champion)&&!array_key_exists('bonus_feeds',$champion)&&isset(Catalog::champions()[$type])&&($after['champions'][$type]['bonus_feeds']??null)!==0){throw new RuntimeException('Normalizer granted unearned champion food powers for user '.$id.'.');}
        }
    }

    /** Compatibility defaults may be added, but old data and extension fields are immutable here. */
    private static function assertExistingFields(mixed $before,mixed $after,string $path,int $id): void
    {
        if(is_array($before)&&is_array($after)){
            foreach($before as $key=>$value){
                if(!array_key_exists($key,$after)){throw new RuntimeException('Normalizer removed saved '.$path.'.'.$key.' for user '.$id.'.');}
                self::assertExistingFields($value,$after[$key],$path.'.'.$key,$id);
            }
        }elseif($before!==$after){
            throw new RuntimeException('Normalizer changed saved '.$path.' for user '.$id.'.');
        }
    }

    private static function backup(PDO $pdo,string $directory,array $snapshot): array
    {
        $oldMask=umask(0077);
        try{
            if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)){throw new RuntimeException('Cannot create the private backup directory.');}
            $real=realpath($directory);$public=realpath(dirname(__DIR__).'/public');
            $compare=static fn(string $path):string=>DIRECTORY_SEPARATOR==='\\'?strtolower($path):$path;
            if($real===false||($public!==false&&($compare($real)===$compare($public)||str_starts_with($compare($real),$compare($public).DIRECTORY_SEPARATOR)))){throw new RuntimeException('Backups must be outside the public web directory.');}
            if(DIRECTORY_SEPARATOR!=='\\'&&!chmod($real,0700)){throw new RuntimeException('Cannot protect the private backup directory.');}
            $stem=$real.DIRECTORY_SEPARATOR.'world-before-'.self::TARGET.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
            $files=['json'=>$stem.'.json','sql'=>$stem.'.sql'];
            self::writeBackup($files['json'],static function($handle)use($pdo,$snapshot):void{
                self::writeAll($handle,"{\n");
                foreach($snapshot as $key=>$value){
                    if($key==='tables'){continue;}
                    self::writeAll($handle,json_encode($key,JSON_THROW_ON_ERROR).':'.json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).",\n");
                }
                self::writeAll($handle,'"tables":{');$firstTable=true;
                foreach($snapshot['tables'] as $table=>$summary){
                    self::writeAll($handle,($firstTable?'':',')."\n".json_encode($table,JSON_THROW_ON_ERROR).':[');$firstTable=false;$firstRow=true;
                    foreach(self::rows($pdo,$table,$summary['columns']) as $row){
                        self::writeAll($handle,($firstRow?'':',')."\n".json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));$firstRow=false;
                    }
                    self::writeAll($handle,"\n]");
                }
                self::writeAll($handle,"\n}\n}\n");
            });
            self::writeBackup($files['sql'],static function($handle)use($pdo,$snapshot):void{
                self::writeAll($handle,"-- Backyard Monsters Rehatched pre-upgrade backup\n-- Restore only into a NEW EMPTY database selected by your SQL client.\n-- This file deliberately contains no DROP DATABASE or DROP TABLE statements.\n-- Created ".$snapshot['created_at']."; schema ".$snapshot['schema_version']."; content ".$snapshot['source_version']."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET time_zone='+00:00';\n");
                foreach($snapshot['table_schemas'] as $schema){self::writeAll($handle,$schema.";\n\n");}
                self::writeAll($handle,"START TRANSACTION;\n");
                foreach($snapshot['tables'] as $table=>$summary){
                    foreach(self::rows($pdo,$table,$summary['columns']) as $row){
                        $columns=array_map(static fn(string $key):string=>'`'.str_replace('`','``',$key).'`',array_keys($row));
                        // Explicit text conversion avoids MySQL interpreting a hex literal as a large integer.
                        // UTF-8/JSON bytes remain independent of SQL backslash escape modes.
                        $values=array_map(static fn(mixed $value):string=>$value===null?'NULL':"CONVERT(X'".bin2hex((string)$value)."' USING utf8mb4)",array_values($row));
                        self::writeAll($handle,'INSERT INTO `'.$table.'` ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n");
                    }
                }
                self::writeAll($handle,"COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n");
            });
            return $files;
        }finally{umask($oldMask);}
    }

    private static function writeBackup(string $file,callable $writer): void
    {
        $temporary=$file.'.part';$handle=fopen($temporary,'xb');
        if($handle===false){throw new RuntimeException('Cannot create a protected backup file.');}
        try{
            if(DIRECTORY_SEPARATOR!=='\\'&&!chmod($temporary,0600)){throw new RuntimeException('Cannot protect the backup file.');}
            $writer($handle);
            if(!fflush($handle)||(function_exists('fsync')&&!fsync($handle))){throw new RuntimeException('The backup could not be synchronized.');}
            fclose($handle);$handle=null;
            if(!rename($temporary,$file)){throw new RuntimeException('The backup could not be finalized.');}
        }finally{
            if(is_resource($handle)){fclose($handle);}
            if(is_file($temporary)){unlink($temporary);}
        }
    }

    private static function writeAll($handle,string $text): void
    {
        $offset=0;$length=strlen($text);
        while($offset<$length){$written=fwrite($handle,substr($text,$offset));if($written===false||$written===0){throw new RuntimeException('The backup write failed. No migration changes were made.');}$offset+=$written;}
    }
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $options=getopt('',['check','apply','help']);
    if(isset($options['help'])){echo "Backyard Monsters Rehatched 0.13.1 world upgrade\nUsage: php bin/upgrade.php [--check|--apply]\nDefault --check reports needed changes without writing. --apply saves private JSON and SQL backups, adds missing one-worker and empty-planner defaults, and preserves paid concurrent jobs. Schema 3 adds alliance tables. Existing maps and Fortification generation markers are retained; only older maps missing their marker receive one-time neutral sites.\nStop Apache/the HTTP server and world worker before --apply; leave MySQL running. Existing accounts, territories and progress are preserved.\n";exit(0);}
    if(isset($options['check'],$options['apply'])){fwrite(STDERR,"Choose either --check or --apply.\n");exit(2);}
    try{
        if(!Game::configured()){throw new RuntimeException('No installed world was found. New installations should use setup.php.');}
        $report=WorldUpgrade::run(Db::pdo(),isset($options['apply']));
        echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
        if(!$report['applied']&&$report['changed_bases']===0&&!$report['settings_update']&&!$report['schema_steps']&&!$report['world_update']){echo "This world is already current. No files or data were changed.\n";}
        elseif(!isset($options['apply'])){echo "Check complete. Run with --apply after stopping Apache/the HTTP server and the world worker.\n";}
        else{echo "Upgrade complete. Restart Apache/the HTTP server, then sign in with your existing account. Bots run automatically while a human is online.\n";}
    }catch(Throwable $error){fwrite(STDERR,'Upgrade stopped: '.$error->getMessage()."\n");exit(1);}
}
