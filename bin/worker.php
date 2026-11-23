<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Bots.php';
if (is_file(dirname(__DIR__) . '/app/BattleService.php')) {
    require_once dirname(__DIR__) . '/app/BattleService.php';
}
$options = getopt('', ['interval:', 'limit:', 'iterations:', 'quiet', 'help']);
if (isset($options['help'])) {
    echo "Persistent bot-world worker.\nUsage: php bin/worker.php [--interval=5] [--limit=25] [--iterations=1] [--quiet]\nOptional companion to the automatic browser heartbeat. Bots run only while a human player is online. Ctrl+C stops safely between ticks.\n";
    exit(0);
}
try {
    if (!Game::configured()) { throw new RuntimeException('Install this local world through setup.php before starting the worker.'); }
    World::assertSchema();
} catch (Throwable $error) {
    fwrite(STDERR, 'World worker cannot start: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
$interval = max(3, min(60, (int)($options['interval'] ?? 5)));
$limit = isset($options['limit']) ? max(1, min(100, (int)$options['limit'])) : null;
$iterations = max(0, (int)($options['iterations'] ?? 0));
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}
if (!isset($options['quiet'])) {
    echo "Backyard Monsters — presence-aware world worker\n";
    echo "Tick interval: {$interval}s. Press Ctrl+C to stop.\n";
}
$count = 0;
$failures = 0;
while ($running) {
    $started = microtime(true);
    try {
        $summary = Bots::tick($limit);
        $failures = 0;
        if (!isset($options['quiet'])) {
            echo gmdate('Y-m-d H:i:s'), ' UTC ', json_encode($summary, JSON_UNESCAPED_SLASHES), PHP_EOL;
        }
    } catch (Throwable $e) {
        ++$failures;
        fwrite(STDERR, gmdate('Y-m-d H:i:s') . ' UTC World tick failed: ' . $e->getMessage() . PHP_EOL);
        if ($failures >= 5) {
            fwrite(STDERR, "Stopped after five consecutive failures; check database availability and configuration.\n");
            exit(1);
        }
    }
    ++$count;
    if ($iterations > 0 && $count >= $iterations) {
        break;
    }
    $until = $started + $interval;
    while ($running && microtime(true) < $until) {
        usleep(200000);
    }
}
if (!isset($options['quiet'])) {
    echo "World worker stopped. All completed turns are saved.\n";
}
