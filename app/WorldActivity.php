<?php
declare(strict_types=1);
require_once __DIR__.'/StorePowers.php';

/** Human presence drives one shared, bounded simulation clock for this world. */
final class WorldActivity
{
    public const SETTING = 'world_activity_v1';
    public const LEASE_SECONDS = 45;
    public const PULSE_SECONDS = 5;
    public const MAX_LEASES = 1024;
    public const MAX_SESSION_LEASES = 16;

    /** Installation/upgrade establishes an asleep epoch without waking any bots. */
    public static function initialize(?PDO $db = null, ?int $now = null): void
    {
        $db ??= Db::pdo();
        $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
        $lock = 'bym:presence:' . substr(hash('sha256', $database), 0, 32);
        $q = $db->prepare('SELECT GET_LOCK(?, 2)'); $q->execute([$lock]);
        if ((int)$q->fetchColumn() !== 1) { throw new RuntimeException('The world is receiving another player heartbeat. Please retry shortly.'); }
        try {
            $q = $db->prepare('INSERT IGNORE INTO settings(name,value) VALUES(?,?)');
            $q->execute([self::SETTING, json_encode(self::project(null, $now ?? time()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        } finally { $q = $db->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]); }
    }

    /** Only a real authenticated human may keep the autonomous world awake. */
    public static function pulse(int $userId, string $sessionId, string $clientId, ?int $now = null): array
    {
        self::assertHuman($userId);
        [$key, $session] = self::identity($userId, $sessionId, $clientId);
        $state = self::mutate(static function (array &$state, int $at) use ($key, $session, $userId): void {
            if (!isset($state['leases'][$key])) {
                $sameSession = count(array_filter($state['leases'], static fn(array $lease): bool => $lease['session'] === $session));
                if (count($state['leases']) >= self::MAX_LEASES || $sameSession >= self::MAX_SESSION_LEASES) {
                    throw new RuntimeException('Too many game tabs are connected. Close another tab and try again.');
                }
            }
            $state['leases'][$key] = ['user_id'=>$userId, 'session'=>$session, 'expires_at'=>$at + self::LEASE_SECONDS];
        }, $now);
        return self::publicStatus($state);
    }

    /** Closing one tab does not disconnect another tab or another signed-in session. */
    public static function leave(int $userId, string $sessionId, string $clientId, ?int $now = null): array
    {
        [$key] = self::identity($userId, $sessionId, $clientId);
        $state = self::mutate(static function (array &$state) use ($key): void { unset($state['leases'][$key]); }, $now);
        return self::publicStatus($state);
    }

    /** A normal sign-out invalidates every tab sharing that PHP login session. */
    public static function logout(int $userId, string $sessionId, ?int $now = null): array
    {
        [, $session] = self::identity($userId, $sessionId, str_repeat('0', 32));
        $state = self::mutate(static function (array &$state) use ($session): void {
            foreach ($state['leases'] as $key=>$lease) { if ($lease['session'] === $session) { unset($state['leases'][$key]); } }
        }, $now);
        return self::publicStatus($state);
    }

    /** Read-only: safe while a caller already holds home/site/battle row locks. */
    public static function clock(?int $now = null): array
    {
        $stored = self::read();
        $clock = self::clockOf(self::project($stored, $now ?? time()));
        if ($stored === null) { $clock['epoch'] = 0; $clock['pausedSeconds'] = 0; }
        return $clock;
    }

    /** Public presence and work counters, with no tab, session or account identifiers. */
    public static function status(?int $now = null): array
    {
        return self::publicStatus(self::project(self::read(), $now ?? time()));
    }

    /** Call only after obtaining Bots' existing world advisory lock. */
    public static function claimTick(?int $now = null): array
    {
        $reason = 'waiting';
        $state = self::mutate(static function (array &$state, int $at) use (&$reason): void {
            if (!self::enabled()) { $reason = 'disabled'; return; }
            if (!$state['leases']) { return; }
            if ($state['last_dispatch_at'] !== null && $at - $state['last_dispatch_at'] < self::PULSE_SECONDS) {
                $reason = 'cooldown'; return;
            }
            $state['last_dispatch_at'] = $at;
            $reason = 'active';
        }, $now);
        return ['allowed'=>$reason === 'active', 'reason'=>$reason] + self::clockOf($state);
    }

    /** Keep only bounded, non-sensitive metrics; a failed tick is visible without leaking SQL. */
    public static function recordTick(array $summary, ?int $now = null): void
    {
        self::mutate(static function (array &$state, int $at) use ($summary): void {
            $result = ['status'=>in_array($summary['status'] ?? '', ['ok','error','failed','paused','busy','idle'], true) ? $summary['status'] : 'ok',
                'last_tick'=>$at, 'errorCount'=>count($summary['errors'] ?? [])];
            foreach (['processed','built','upgraded','trained','repaired','fortified','researched','lab_researched','unlocks','garrisoned','juiced',
                'champions_raised','champions_fed','champions_thawed','attacks','territory_attacks','sites_upgraded','sites_repaired','sites_garrisoned',
                'network_attacks','network_upgraded','network_repaired','network_garrisoned','workers_hired','layouts_improved','battles_advanced',
                'battles_finished','active_battles','due_bots','total_bots','elapsed_ms'] as $key) {
                if (isset($summary[$key]) && is_numeric($summary[$key])) { $result[$key] = max(0, (int)$summary[$key]); }
            }
            $state['last_result'] = $result;
        }, $now);
    }

    /**
     * Pure projection of elapsed online time. Once the last lease expires, the
     * active clock stops exactly there even if nobody calls PHP for several days.
     */
    public static function project(?array $stored, int $now): array
    {
        $now = max(0, $now);
        if ($stored === null) {
            return ['version'=>1, 'epoch'=>$now, 'observed_at'=>$now, 'active_seconds'=>0, 'leases'=>[],
                'last_dispatch_at'=>null, 'last_result'=>null];
        }
        $epoch = max(0, (int)($stored['epoch'] ?? $now));
        $observed = max($epoch, (int)($stored['observed_at'] ?? $epoch));
        // A wall-clock correction must never rewind the already observed world.
        $now = max($observed, $now);
        $active = max(0, min($observed - $epoch, (int)($stored['active_seconds'] ?? 0)));
        $leases = [];
        $until = $observed;
        foreach (array_slice(is_array($stored['leases'] ?? null) ? $stored['leases'] : [], 0, self::MAX_LEASES, true) as $key=>$lease) {
            if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key) || !is_array($lease)
                || !is_int($lease['user_id'] ?? null) || $lease['user_id'] < 1
                || !is_string($lease['session'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $lease['session'])
                || !is_int($lease['expires_at'] ?? null)) { continue; }
            // An ordinary lease may extend only one TTL beyond the stored observation.
            $expires = min($lease['expires_at'], $observed + self::LEASE_SECONDS);
            $until = max($until, $expires);
            if ($expires > $now) { $leases[$key] = ['user_id'=>$lease['user_id'], 'session'=>$lease['session'], 'expires_at'=>$expires]; }
        }
        $active += max(0, min($now, $until) - $observed);
        return ['version'=>1, 'epoch'=>$epoch, 'observed_at'=>$now, 'active_seconds'=>$active, 'leases'=>$leases,
            'last_dispatch_at'=>isset($stored['last_dispatch_at']) ? (int)$stored['last_dispatch_at'] : null,
            'last_result'=>is_array($stored['last_result'] ?? null) ? $stored['last_result'] : null];
    }

    /** Stamp a newly created bot record or newly bot-owned site, without granting old paused time. */
    public static function stamp(array &$record, array $clock): void
    {
        if ((int)($clock['epoch'] ?? 0) <= 0) { return; }
        $record['_activity_clock'] = ['epoch'=>(int)$clock['epoch'], 'pausedSeconds'=>(int)$clock['pausedSeconds']];
    }

    /** Move bot deadlines by only newly observed inactive seconds; all other state stays untouched. */
    public static function shiftBase(array &$base, array $clock): int
    {
        if ((int)($clock['epoch'] ?? 0) <= 0) { return 0; }
        $delta = self::pausedDelta($base, $clock);
        if ($delta > 0) {
            self::shift($base, ['last_tick','shield_until'], $delta);
            StorePowers::shift($base,$delta);
            foreach ($base['buildings'] ?? [] as $i=>$building) { self::shift($base['buildings'][$i], ['ready_at','fortify_ready_at'], $delta); }
            foreach (['queue','researchQueue','unlockQueue'] as $key) {
                foreach ($base[$key] ?? [] as $i=>$job) { self::shift($base[$key][$i], ['started_at','ready_at'], $delta); }
            }
            foreach (['research','build'] as $lane) {
                if (is_array($base['siege'][$lane] ?? null)) { self::shift($base['siege'][$lane], ['started_at','ready_at'], $delta); }
            }
            if (isset($base['krallenHunt'])) { self::shift($base['krallenHunt'], ['lastHeal'], $delta); }
            if (is_array($base['lab']['queue'] ?? null)) { self::shift($base['lab']['queue'], ['started_at','ready_at'], $delta); }
            foreach ($base['champions'] ?? [] as $type=>$champion) {
                if (is_array($champion)) { self::shift($base['champions'][$type], ['next_feed_at','last_heal_at','last_hunger_at'], $delta); }
            }
            if (isset($base['infernoRealm'])) {
                $realm=&$base['infernoRealm'];
                self::shift($realm,['last_tick','shield_until'],$delta);
                StorePowers::shift($realm,$delta);
                foreach($realm['buildings'] as $i=>$building){self::shift($realm['buildings'][$i],['ready_at'],$delta);}
                foreach($realm['queue']??[] as $i=>$egg){self::shift($realm['queue'][$i],['start','ready_at'],$delta);}
                foreach(['study','discovery'] as $lane){if(is_array($realm[$lane]??null)){self::shift($realm[$lane],['ready_at'],$delta);}}
                unset($realm);
            }
            if (isset($base['mushrooms'])) {
                self::shift($base['mushrooms'], ['growthAt'], $delta);
                foreach ($base['mushrooms']['items'] as $i=>$item) { self::shift($base['mushrooms']['items'][$i], ['readyAt'], $delta); }
            }
            foreach ($base['world']['pending'] ?? [] as $i=>$event) { self::shift($base['world']['pending'][$i], ['ready_at'], $delta); }
            if (is_array($base['ai'] ?? null)) { self::shift($base['ai'], ['last_attack_at','last_layout_at'], $delta); }
        }
        self::stamp($base, $clock);
        return $delta;
    }

    /** The caller applies this only while the site's current owner is a bot. */
    public static function shiftSite(array &$state, array $clock): int
    {
        if ((int)($clock['epoch'] ?? 0) <= 0) { return 0; }
        $delta = self::pausedDelta($state, $clock);
        if ($delta > 0) {
            self::shift($state, ['last_tick','shield_until','ready_at','repair_at'], $delta);
            if(isset($state['mr2Outpost']))foreach($state['queue']??[] as $i=>$job)self::shift($state['queue'][$i],['ready_at','started_at'],$delta);
            foreach ($state['buildings'] ?? [] as $i=>$building) { self::shift($state['buildings'][$i], ['ready_at','fortify_ready_at'], $delta); }
        }
        self::stamp($state, $clock);
        return $delta;
    }

    private static function pausedDelta(array $record, array $clock): int
    {
        $anchor = is_array($record['_activity_clock'] ?? null) ? $record['_activity_clock'] : [];
        if ((int)($anchor['epoch'] ?? 0) !== 0 && (int)$anchor['epoch'] !== (int)$clock['epoch']) { return 0; }
        return max(0, (int)$clock['pausedSeconds'] - max(0, (int)($anchor['pausedSeconds'] ?? 0)));
    }

    private static function shift(array &$record, array $keys, int $delta): void
    {
        foreach ($keys as $key) { if (isset($record[$key]) && is_numeric($record[$key]) && $record[$key] > 0) { $record[$key] += $delta; } }
    }

    private static function enabled(): bool { return (bool)(Db::config()['bots']['enabled'] ?? true); }

    private static function publicStatus(array $state): array
    {
        $clock = self::clockOf($state);
        return ['mode'=>!self::enabled() ? 'disabled' : ($clock['online'] ? 'active' : 'waiting'),
            'onlinePlayers'=>$clock['onlinePlayers'], 'onlineTabs'=>$clock['onlineTabs'],
            'leaseSeconds'=>self::LEASE_SECONDS, 'pulseSeconds'=>self::PULSE_SECONDS,
            'lastTick'=>$state['last_result']['last_tick'] ?? null, 'lastResult'=>$state['last_result']];
    }

    private static function clockOf(array $state): array
    {
        $players = array_unique(array_column($state['leases'], 'user_id'));
        return ['online'=>(bool)$players, 'onlinePlayers'=>count($players), 'onlineTabs'=>count($state['leases']),
            'activeSeconds'=>(int)$state['active_seconds'],
            'pausedSeconds'=>max(0, (int)$state['observed_at'] - (int)$state['epoch'] - (int)$state['active_seconds']),
            'epoch'=>(int)$state['epoch'], 'observedAt'=>(int)$state['observed_at']];
    }

    private static function identity(int $userId, string $sessionId, string $clientId): array
    {
        if ($userId < 1 || $sessionId === '' || strlen($sessionId) > 256 || !preg_match('/^[A-Za-z0-9_-]{16,96}$/D', $clientId)) {
            throw new RuntimeException('This game tab has an invalid presence identifier. Refresh the page.');
        }
        $session = hash('sha256', $userId . ':' . $sessionId);
        return [hash('sha256', $session . ':' . $clientId), $session];
    }

    private static function assertHuman(int $userId): void
    {
        $q = Db::pdo()->prepare('SELECT is_bot FROM users WHERE id=?');
        $q->execute([$userId]);
        $bot = $q->fetchColumn();
        if ($bot === false || (int)$bot !== 0) { throw new RuntimeException('Sign in as a player before starting the world simulation.'); }
    }

    private static function read(): ?array
    {
        $q = Db::pdo()->prepare('SELECT value FROM settings WHERE name=?');
        $q->execute([self::SETTING]);
        $value = $q->fetchColumn();
        if ($value === false) { return null; }
        $state = json_decode((string)$value, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($state) || (int)($state['version'] ?? 0) !== 1) { throw new RuntimeException('The world activity record is invalid. Check the local database backup.'); }
        return $state;
    }

    /** This mutex protects tiny presence writes only, never a bot turn or a battle simulation. */
    private static function mutate(callable $change, ?int $now): array
    {
        $db = Db::pdo();
        $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
        $lock = 'bym:presence:' . substr(hash('sha256', $database), 0, 32);
        $q = $db->prepare('SELECT GET_LOCK(?, 2)'); $q->execute([$lock]);
        if ((int)$q->fetchColumn() !== 1) { throw new RuntimeException('The world is receiving another player heartbeat. Please retry shortly.'); }
        try {
            $state = self::project(self::read(), $now ?? time());
            $change($state, $state['observed_at']);
            $q = $db->prepare('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
            $q->execute([self::SETTING, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
            return $state;
        } finally { $q = $db->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]); }
    }
}
