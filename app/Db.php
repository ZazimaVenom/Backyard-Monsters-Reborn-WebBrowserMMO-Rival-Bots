<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $connection = null;
    private static ?array $configuration = null;

    public static function config(): array
    {
        if (self::$configuration === null) {
            $file = dirname(__DIR__).'/var/config.php';
            if (!is_file($file)) { throw new RuntimeException('The game has not been installed. Open setup.php on this computer.'); }
            $config = require $file;
            if (!is_array($config)) { throw new RuntimeException('Invalid configuration.'); }
            self::$configuration = $config;
        }
        return self::$configuration;
    }

    public static function connect(array $config, bool $withDatabase = true): PDO
    {
        $database = $config['database'] ?? 'backyard_rehatched';
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $database)) { throw new InvalidArgumentException('Use letters, numbers and underscores for the database name.'); }
        if (!empty($config['socket'])) {
            $dsn = 'mysql:unix_socket='.$config['socket'];
        } else {
            $host = (string)($config['host'] ?? '127.0.0.1');
            if (str_contains($host,';') || str_contains($host,"\0")) { throw new InvalidArgumentException('Invalid database host.'); }
            $dsn = 'mysql:host='.$host.';port='.(int)($config['port'] ?? 3306);
        }
        if ($withDatabase) { $dsn .= ';dbname='.$database; }
        $dsn .= ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string)($config['username']??''), (string)($config['password']??''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$connection === null) { self::$connection = self::connect(self::config()); }
        return self::$connection;
    }

    public static function useConnection(PDO $pdo, array $config = []): void
    {
        self::$connection = $pdo;
        self::$configuration = $config + ['game_speed'=>1,'bot_count'=>1000];
    }

    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) { return $work($pdo); }
        for ($attempt=0; $attempt<3; $attempt++) {
            $pdo->beginTransaction();
            try { $value = $work($pdo); $pdo->commit(); return $value; }
            catch (Throwable $error) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                if ($error instanceof PDOException && in_array((int)($error->errorInfo[1]??0), [1020,1205,1213],true) && $attempt<2) { usleep(20000*($attempt+1)); continue; }
                throw $error;
            }
        }
        throw new RuntimeException('The world is busy. Please retry.');
    }
}
