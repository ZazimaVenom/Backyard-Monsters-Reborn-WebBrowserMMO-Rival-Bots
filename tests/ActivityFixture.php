<?php
declare(strict_types=1);
/** Explicit fixture clock controls; never load this file from the application. */
function activityFixtureGuard(): void
{
    if (PHP_SAPI !== 'cli' || !str_ends_with((string)Db::pdo()->query('SELECT DATABASE()')->fetchColumn(), '_test')) {
        throw new RuntimeException('Activity fixtures require a disposable *_test CLI database.');
    }
}
function activityFixtureWake(?int $humanId = null): int
{
    activityFixtureGuard();
    if ($humanId === null) {
        $humanId = (int)Db::pdo()->query('SELECT id FROM users WHERE is_bot=0 ORDER BY id LIMIT 1')->fetchColumn();
        if (!$humanId) { $humanId = (int)Game::register('Activity Fixture Human', 'disposable-activity-fixture-password')['id']; }
    }
    WorldActivity::pulse($humanId, 'activity-fixture-session', str_repeat('a', 32));
    return $humanId;
}
function activityFixtureReleaseCadence(): void
{
    activityFixtureGuard();
    $q = Db::pdo()->prepare("UPDATE settings SET value=JSON_SET(value,'$.last_dispatch_at',NULL) WHERE name=?");
    $q->execute([WorldActivity::SETTING]);
}
function activityFixtureTick(?int $limit = null, ?float $budget = null): array
{
    activityFixtureWake();
    activityFixtureReleaseCadence();
    return Bots::tick($limit, $budget);
}
function activityFixtureAgeState(array &$state, int $seconds): void
{
    activityFixtureGuard();
    $state['created_at'] = time() - $seconds;
    if (!empty($state['attacker']['is_bot'])) {
        $clock = WorldActivity::clock();
        // Only the test's time source moves. The engine still performs every
        // attack, casualty and settlement; neither elapsed nor ranks are granted.
        $state['_activity_clock'] = ['epoch'=>$clock['epoch'], 'activeSeconds'=>$clock['activeSeconds'] - $seconds, 'elapsed'=>0.0];
    }
}
