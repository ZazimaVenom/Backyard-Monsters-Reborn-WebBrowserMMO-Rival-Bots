<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

/** Only explicit, reviewed setup guidance belongs in this exception's message. */
final class SetupValidationException extends RuntimeException {}

final class Installer
{
    private const TABLES = ['users','bases','battles','reports','settings','auth_attempts','world_sites','alliances','alliance_members','alliance_requests','keeper_friends','keeper_gifts','keeper_mail','alliance_stances','alliance_wars','alliance_war_battles'];

    /** Read-only connection check. It never creates a database, tables, files or accounts. */
    public static function probe(array $config): array
    {
        $config=self::connectionConfig($config);
        $pdo=Db::connect($config,false);
        $version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $family=stripos($version,'MariaDB')!==false?'MariaDB':'MySQL';
        $numeric=self::serverVersion($version,$family);
        $supported=$numeric!==null&&version_compare($numeric,$family==='MariaDB'?'10.4.0':'8.0.0','>=');
        $exists=true;$empty=null;
        try{$pdo->exec('USE `'.$config['database'].'`');}
        catch(PDOException $error){
            if(self::driverCode($error)!==1049){throw $error;}
            $exists=false;
        }
        if($exists){$empty=self::databaseIsEmpty($pdo);}
        $canInitialize=$supported&&(!$exists||$empty===true);
        return [
            'connected'=>true,'server_version'=>$version,'server_family'=>$family,'server_supported'=>$supported,
            'database'=>$config['database'],'database_exists'=>$exists,'database_empty'=>$empty,
            'can_initialize'=>$canInitialize,
            'message'=>!$supported?'The connection works, but this server version is unsupported. Use MySQL 8 or newer, or MariaDB 10.4 or newer.':
                (!$exists?'Connection successful. The database does not exist yet; leave Create database selected to create it.':
                ($empty?'Connection successful. The database is empty and ready for this world.':'Connection successful. This database already contains data or unrelated tables. Choose a different empty database; existing data will not be overwritten.')),
        ];
    }

    /** Safe UI diagnostics: never include raw driver messages, queries, passwords or DSNs. */
    public static function diagnostic(Throwable $error): array
    {
        if($error instanceof SetupValidationException){return ['code'=>'validation','title'=>'Setup needs attention','message'=>$error->getMessage()];}
        $number=$error instanceof PDOException?self::driverCode($error):0;
        return match($number){
            1045,1698=>['code'=>'credentials','title'=>'Database login was rejected','message'=>'Check the database username and password. A fresh local XAMPP installation normally uses username root with an empty password. Use your configured password if you changed it. The game does not create a database login named backyard.'],
            2002,2003=>['code'=>'connection','title'=>'The database server could not be reached','message'=>'Start MySQL in the XAMPP Control Panel. For a local XAMPP server, use host 127.0.0.1 and the MySQL port shown in the panel (usually 3306). If MySQL will not stay running, check its XAMPP log and whether another database service is using that port.'],
            1049=>['code'=>'database_missing','title'=>'The selected database does not exist','message'=>'Select Create database if it does not exist, or create an empty database in phpMyAdmin and enter its exact name. Existing worlds must keep their current database and private configuration.'],
            1044,1142,1143,1227=>['code'=>'permissions','title'=>'The database login lacks permission','message'=>'Use a database login allowed to create tables and read/write this game database. Creating a new database also requires CREATE permission. Alternatively, create an empty database yourself in phpMyAdmin, grant the login access, then clear Create database before installing.'],
            1064=>['code'=>'schema','title'=>'The server rejected the database schema','message'=>'Use MySQL 8 or newer, or MariaDB 10.4 or newer (including the MariaDB supplied by XAMPP 8.2.12). Keep the existing database intact and check the local PHP error log if a supported server still rejects the schema.'],
            2006,2013=>['code'=>'disconnected','title'=>'The database connection was interrupted','message'=>'Check that MySQL is still running in the XAMPP Control Panel, then retry. An interrupted attempt never resets an existing world.'],
            default=>['code'=>'database','title'=>'Setup could not complete','message'=>'Check the local database service and PHP error log for details. Confirm the host, port, database name and login, then test the connection again. Existing worlds are not overwritten.'],
        };
    }

    /** Install only the world and its bots. Human registration happens in the game. */
    public static function install(array $config): void
    {
        $directory=dirname(__DIR__).'/var';
        if(!is_dir($directory)||!is_writable($directory)){throw new SetupValidationException('The var folder must be writable by PHP.');}
        $lock=fopen($directory.'/install.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){throw new SetupValidationException('Another installation is running. Please wait.');}
        $temp=null;$committed=false;
        try{
            if(is_file($directory.'/config.php')){throw new SetupValidationException('This world is already installed.');}
            $createDatabase=!empty($config['create_database']);
            $config=self::connectionConfig($config);
            $config['game_speed']=1;$config['bot_count']=1000;$config['player_grace_seconds']=1800;
            $config['bots']=['interval_min'=>60,'interval_max'=>300];$config['installed_at']=time();$config['auth_salt']=bin2hex(random_bytes(32));
            $pdo=Db::connect($config,false);
            $serverVersion=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $serverFamily=stripos($serverVersion,'MariaDB')!==false?'MariaDB':'MySQL';
            $numericVersion=self::serverVersion($serverVersion,$serverFamily);
            if($numericVersion===null||version_compare($numericVersion,$serverFamily==='MariaDB'?'10.4.0':'8.0.0','<')){throw new SetupValidationException('Use MySQL 8 or newer, or MariaDB 10.4 or newer, including the MariaDB supplied by XAMPP 8.2.12.');}
            if($createDatabase){$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$config['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
            $pdo->exec('USE `'.$config['database'].'`');
            if(!self::databaseIsEmpty($pdo)){throw new SetupValidationException('This database already contains data or unrelated tables. Choose an empty database; existing worlds are never overwritten.');}
            $schema=file_get_contents(__DIR__.'/schema.sql');if($schema===false){throw new SetupValidationException('The database schema is missing.');}
            foreach(explode(';',$schema) as $statement){if(trim($statement)!==''){$pdo->exec($statement);}}
            Db::useConnection($pdo,$config);
            $temp=tempnam($directory,'config-');if($temp===false){throw new SetupValidationException('Unable to prepare the configuration file.');}
            $content="<?php\n// Private generated configuration. Keep this file outside the web root.\nreturn ".var_export($config,true).";\n";
            if(file_put_contents($temp,$content,LOCK_EX)===false){throw new SetupValidationException('Unable to write the configuration file.');}chmod($temp,0600);
            Db::transaction(function(PDO $pdo): void {
                // The bot clock starts paused, including the time before the
                // first keeper signs in. Setup still creates no player account.
                WorldActivity::initialize($pdo);
                Game::seedBots($pdo,1000);
                World::initialize($pdo);
                $q=$pdo->prepare('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');$q->execute(['schema_version','3']);$q->execute(['content_version',Catalog::all()['version']]);$q->execute(['world_created_at',(string)time()]);
            });
            $committed=true;
            if(!rename($temp,$directory.'/config.php')){throw new SetupValidationException('World created, but configuration could not be finalized. Rename the private var/'.basename($temp).' file to var/config.php before retrying.');}$temp=null;
        }finally{if(!$committed&&$temp!==null&&is_file($temp)){unlink($temp);}flock($lock,LOCK_UN);fclose($lock);}
    }

    private static function connectionConfig(array $input): array
    {
        foreach(['host','database','username','password','socket'] as $key){
            if(isset($input[$key])&&!is_string($input[$key])){throw new SetupValidationException('Enter a valid database '.$key.'.');}
        }
        $database=trim($input['database']??'backyard_rehatched');
        if(!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$database)){throw new SetupValidationException('Use only letters, numbers and underscores in the database name.');}
        $host=trim($input['host']??'127.0.0.1');
        if($host===''||strlen($host)>255||preg_match('/[;\x00-\x20\x7f]/',$host)){throw new SetupValidationException('Enter a database host, such as 127.0.0.1.');}
        $port=filter_var($input['port']??3306,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        if($port===false){throw new SetupValidationException('Enter a database port between 1 and 65535.');}
        $username=trim($input['username']??'root');
        if($username===''||strlen($username)>128||preg_match('/[\x00-\x1f\x7f]/',$username)){throw new SetupValidationException('Enter the database username; a fresh local XAMPP installation normally uses root.');}
        $config=['host'=>$host,'port'=>$port,'database'=>$database,'username'=>$username,'password'=>$input['password']??''];
        if(!empty($input['socket'])){
            if(preg_match('/[;\x00-\x1f\x7f]/',$input['socket'])){throw new SetupValidationException('Invalid database socket path.');}
            $config['socket']=$input['socket'];
        }
        return $config;
    }

    /** Empty tables left by an interrupted schema creation may be retried safely. */
    private static function databaseIsEmpty(PDO $pdo): bool
    {
        foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
            if(!in_array($table,self::TABLES,true)){return false;}
            if((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()>0){return false;}
        }
        return true;
    }

    private static function driverCode(PDOException $error): int
    {
        return (int)($error->errorInfo[1]??(is_numeric($error->getCode())?$error->getCode():0));
    }

    private static function serverVersion(string $version,string $family): ?string
    {
        if($family==='MariaDB'){
            $prefix=substr($version,0,(int)stripos($version,'MariaDB'));
            if(preg_match_all('/\d+\.\d+\.\d+/',$prefix,$matches)){return $matches[0][count($matches[0])-1];}
        }
        return preg_match('/\d+\.\d+\.\d+/',$version,$m)?$m[0]:null;
    }
}
