<?php
declare(strict_types=1);

/** Shared construction capacity. Existing paid timers are the authoritative jobs. */
final class Construction
{
    public const MAX_WORKERS = 5;

    public static function normalizeWorkers(mixed $workers): int
    {
        return is_int($workers) ? max(1, min(self::MAX_WORKERS, $workers)) : 1;
    }

    /** No database access or progression: this is safe for planning and owner previews. */
    public static function status(array $base, ?int $now = null): array
    {
        $now ??= time();
        $capacity = self::normalizeWorkers($base['workers'] ?? 1);
        $jobs = [];
        $townHall = 0;
        foreach ($base['buildings'] ?? [] as $building) {
            $ready = (int)($building['ready_at'] ?? 0);
            $fortify = (int)($building['fortify_ready_at'] ?? 0);
            if (($building['type'] ?? '') === 'townhall' && (float)($building['hp'] ?? 0) > 0 && $ready <= $now) {
                $townHall = max($townHall, (int)($building['level'] ?? 0));
            }
            if ($ready <= $now && $fortify <= $now) { continue; }
            $id = (int)($building['id'] ?? 0);
            $job = ['buildingId' => $id, 'type' => (string)($building['type'] ?? ''),
                'kind' => $fortify > $now && $fortify >= $ready ? 'fortify' : ((int)($building['level'] ?? 0) < 1 ? 'build' : 'upgrade'),
                'readyAt' => max($ready, $fortify)];
            // A legacy record with two timers still occupies one worker until both finish.
            if (!isset($jobs[$id]) || $job['readyAt'] > $jobs[$id]['readyAt']) { $jobs[$id] = $job; }
        }
        foreach ($base['mushrooms']['items'] ?? [] as $item) {
            if ($item['readyAt']>$now) {
                $jobs[] = ['buildingId'=>-$item['id'],'type'=>'mushroom','kind'=>'pick','readyAt'=>$item['readyAt']];
            }
        }
        foreach ($base['seasonal']['pumpkins'] ?? [] as $item) {
            if ($item['readyAt']>$now) {
                $jobs[] = ['buildingId'=>-1000000-$item['id'],'type'=>'pumpkin','kind'=>'pick','readyAt'=>$item['readyAt']];
            }
        }
        $jobs = array_values($jobs);
        usort($jobs, static fn(array $a, array $b): int => ($a['readyAt'] <=> $b['readyAt']) ?: ($a['buildingId'] <=> $b['buildingId']));
        $prices = [2 => [10000, 10000, 1000, 0], 3 => [30000, 30000, 5000, 0],
            4 => [90000, 90000, 15000, 0], 5 => [180000, 180000, 35000, 0]];
        $target = min(self::MAX_WORKERS, $capacity + 1);
        $cost = array_combine(['twig', 'pebble', 'putty', 'goo'], $capacity < self::MAX_WORKERS ? $prices[$target] : [0, 0, 0, 0]);
        $reason = '';
        if ($capacity >= self::MAX_WORKERS) { $reason = 'You already have all five construction workers.'; }
        elseif ($townHall < $target) { $reason = 'A completed, living Town Hall at level '.$target.' is required to hire this worker.'; }
        else {
            foreach ($cost as $resource => $amount) {
                if ((float)($base['resources'][$resource] ?? 0) < $amount) {
                    $reason = 'Not enough '.$resource.' to hire this worker.';
                    break;
                }
            }
        }
        $busy = count($jobs);
        return ['capacity' => $capacity, 'busy' => $busy, 'available' => max(0, $capacity - $busy),
            'overCapacity' => $busy > $capacity, 'jobs' => $jobs, 'max' => self::MAX_WORKERS,
            'nextHire' => ['target' => $target, 'cost' => $cost, 'requiredTownHall' => $target,
                'available' => $reason === '', 'reason' => $reason]];
    }

    public static function assertAvailable(array $base, int $now): void
    {
        $status = self::status($base, $now);
        if ($status['available'] < 1) {
            throw new RuntimeException('All construction workers are busy. Wait for a job to finish or hire another worker.');
        }
    }

    /** Caller holds the same home-row lock used for every building purchase. */
    public static function hire(array &$base, int $now): void
    {
        $next = self::status($base, $now)['nextHire'];
        if (!$next['available']) { throw new RuntimeException($next['reason']); }
        foreach ($next['cost'] as $resource => $amount) { $base['resources'][$resource] -= $amount; }
        $base['workers'] = $next['target'];
    }
}
