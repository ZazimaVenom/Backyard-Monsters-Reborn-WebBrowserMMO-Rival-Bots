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
$options = getopt('', ['limit:', 'budget:', 'help']);
if (isset($options['help'])) {
    echo "Run one bounded persistent-world tick.\nUsage: php bin/tick.php [--limit=25] [--budget=4]\nOptional diagnostic tick. It pauses when no human player is online and shares the browser heartbeat cadence.\n";
    exit(0);
}
try {
    $summary = Bots::tick(isset($options['limit']) ? (int)$options['limit'] : null, isset($options['budget']) ? (float)$options['budget'] : null);
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "World tick failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
