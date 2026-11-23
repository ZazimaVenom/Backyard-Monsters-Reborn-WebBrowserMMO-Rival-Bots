<?php
declare(strict_types=1);
require_once __DIR__.'/Mushrooms.php';

/** Home-yard drafts contain geometry only; the real building records stay authoritative. */
final class Planner
{
    public const MAX_SLOTS = 2;
    public const MAX_NAME_LENGTH = 40;
    private const GEOMETRY_EPSILON = 1e-9;

    /** A stable inventory/geometry fingerprint, independent of production and health clocks. */
    public static function revision(array $base): string
    {
        $catalog = Catalog::buildings();
        $rows = [];
        foreach (self::positions($base) as $node) {
            $rows[] = [$node['id'], $node['type'], (float)$node['x'], (float)$node['y'],
                (float)($catalog[$node['type']]['size'] ?? 0)];
        }
        if(!empty($base['yardageLevel']))$rows[]=['yardExtent'=>YardSpace::extent($base)];
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Source templates store IDs, types and positions, never copies of mutable building stats. */
    public static function positions(array $base): array
    {
        $rows = [];
        foreach ($base['buildings'] ?? [] as $building) {
            $rows[] = ['id'=>(int)$building['id'], 'type'=>$building['type'],
                'x'=>(float)$building['x'], 'y'=>(float)$building['y']];
        }
        usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $rows;
    }

    public static function lockedIds(array $base, ?int $now = null): array
    {
        $now ??= time();
        $ids = [];
        foreach ($base['buildings'] ?? [] as $building) {
            if (($building['type'] ?? '') === 'mr2core' || (int)($building['ready_at'] ?? 0) > $now
                || (int)($building['fortify_ready_at'] ?? 0) > $now) {
                $ids[] = (int)$building['id'];
            }
        }
        sort($ids, SORT_NUMERIC);
        return array_values(array_unique($ids));
    }

    /** Owner-only projection. Callers must not attach this to scouting responses. */
    public static function status(array $base, ?int $now = null): array
    {
        $now ??= time();
        $available = false;
        foreach ($base['buildings'] ?? [] as $building) {
            if ($building['type'] === 'planner' && (int)$building['level'] > 0
                && (float)$building['hp'] > 0 && (int)($building['ready_at'] ?? 0) <= $now
                && (int)($building['fortify_ready_at'] ?? 0) <= $now) {
                $available = true;
                break;
            }
        }
        $slots = [];
        $stored = is_array($base['planner']['slots'] ?? null) ? $base['planner']['slots'] : [];
        for ($slot = 1; $slot <= self::MAX_SLOTS; ++$slot) {
            $record = $stored[$slot] ?? null;
            if (!is_array($record) || !is_string($record['name'] ?? null)
                || !is_array($record['positions'] ?? null)) {
                continue;
            }
            // Old layouts may omit newly built IDs; loading reconciles them into the draft palette.
            $nodes = [];
            foreach (array_slice($record['positions'], 0, isset($base['mr2Outpost']) ? 220 : 100) as $node) {
                if (!is_array($node) || !isset($node['id'], $node['type'], $node['x'], $node['y'])
                    || !is_string($node['type']) || !is_numeric($node['id'])
                    || !is_numeric($node['x']) || !is_numeric($node['y'])
                    || !is_finite((float)$node['x']) || !is_finite((float)$node['y'])) {
                    continue;
                }
                $nodes[] = ['id'=>(int)$node['id'], 'type'=>$node['type'],
                    'x'=>(float)$node['x'], 'y'=>(float)$node['y']];
            }
            $slots[] = ['slot'=>$slot, 'name'=>$record['name'], 'positions'=>$nodes,
                'savedAt'=>(int)($record['savedAt'] ?? 0)];
        }
        return ['available'=>$available,
            'reason'=>$available ? '' : 'Build or repair a Yard Planner to save and apply layouts.',
            'revision'=>self::revision($base), 'maxSlots'=>self::MAX_SLOTS,
            'positions'=>self::positions($base), 'lockedIds'=>self::lockedIds($base, $now), 'slots'=>$slots];
    }

    /**
     * Validate the complete final arrangement, rather than sequential moves, so swaps work.
     * This pure helper is also used by bots. It never grants ownership or changes real records.
     */
    public static function validateLayout(array $base, mixed $positions, ?int $now = null): array
    {
        $now ??= time();
        $buildings = $base['buildings'] ?? [];
        if (!is_array($positions) || !array_is_list($positions)
            || count($positions) !== count($buildings) || count($positions) > (isset($base['mr2Outpost']) ? 220 : TownHall::buildingLimit(TownHall::level($base)))) {
            throw new RuntimeException('Place every building in the layout before saving or applying it.');
        }
        $catalog = Catalog::buildings();
        $owned = [];
        foreach ($buildings as $building) {
            $id = (int)$building['id'];
            if (isset($owned[$id]) || !isset($catalog[$building['type']])) {
                throw new RuntimeException('This yard has an invalid building inventory.');
            }
            $owned[$id] = $building;
        }
        $locked = array_fill_keys(self::lockedIds($base, $now), true);
        $nodes = [];
        foreach ($positions as $node) {
            if (!is_array($node) || count($node) !== 4
                || !array_key_exists('id', $node) || !array_key_exists('type', $node)
                || !array_key_exists('x', $node) || !array_key_exists('y', $node)) {
                throw new RuntimeException('Layout positions contain only a building ID, type, x and y.');
            }
            $id = self::integer($node['id'], 'building ID', PHP_INT_MAX);
            if (!isset($owned[$id]) || isset($nodes[$id])) {
                throw new RuntimeException('Each layout building must belong to this yard and appear exactly once.');
            }
            $building = $owned[$id];
            if (!is_string($node['type']) || $node['type'] !== $building['type']) {
                throw new RuntimeException('A layout cannot change a building type.');
            }
            $x = self::coordinate($node['x']);
            $y = self::coordinate($node['y']);
            $half = (float)$catalog[$building['type']]['size'] / 2;
            if (!YardSpace::contains($base,$x,$y,1+$half-self::GEOMETRY_EPSILON)) {
                throw new RuntimeException('Place every building within the yard boundary.');
            }
            if (Mushrooms::overlaps($base,$x,$y,$half*2) || (class_exists('Decorations') && Decorations::overlaps($base,$x,$y,$half*2))) {
                throw new RuntimeException('Clear the mushrooms or move the decorations under this layout first.');
            }
            if (isset($locked[$id]) && ($x !== (float)$building['x'] || $y !== (float)$building['y'])) {
                throw new RuntimeException('Keep buildings with active construction, upgrading or fortification in place.');
            }
            $nodes[$id] = ['id'=>$id, 'type'=>$building['type'], 'x'=>$x, 'y'=>$y];
        }
        $nodes = array_values($nodes);
        $count = count($nodes);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $a = $nodes[$i];
                $b = $nodes[$j];
                $gap = ((float)$catalog[$a['type']]['size'] + (float)$catalog[$b['type']]['size']) / 2 + 0.15;
                if (abs($a['x'] - $b['x']) < $gap - self::GEOMETRY_EPSILON
                    && abs($a['y'] - $b['y']) < $gap - self::GEOMETRY_EPSILON) {
                    throw new RuntimeException('The layout contains overlapping buildings.');
                }
            }
        }
        usort($nodes, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $nodes;
    }

    /** Caller owns the home-row lock and active-battle guard (Game::mutate). */
    public static function mutateBase(array &$base, string $action, array $input, int $now): void
    {
        if (!in_array($action, ['plannerSave', 'plannerDelete', 'plannerApply'], true)) {
            throw new RuntimeException('Unknown Yard Planner action.');
        }
        $status = self::status($base, $now);
        if (!$status['available']) {
            throw new RuntimeException($status['reason']);
        }
        if (!is_string($input['revision'] ?? null)
            || !hash_equals($status['revision'], $input['revision'])) {
            throw new RuntimeException('Your yard changed while this plan was open. Reopen the planner before applying it.');
        }
        if ($action === 'plannerDelete') {
            $slot = self::integer($input['slot'] ?? null, 'layout slot', self::MAX_SLOTS);
            if (!isset($base['planner']['slots'][$slot])) {
                throw new RuntimeException('That layout slot is empty.');
            }
            unset($base['planner']['slots'][$slot]);
            return;
        }
        $nodes = self::validateLayout($base, $input['positions'] ?? null, $now);
        if ($action === 'plannerSave') {
            $slot = self::integer($input['slot'] ?? null, 'layout slot', self::MAX_SLOTS);
            $name = self::name($input['name'] ?? null);
            if (!is_array($base['planner'] ?? null)) {
                $base['planner'] = [];
            }
            if (!is_array($base['planner']['slots'] ?? null)) {
                $base['planner']['slots'] = [];
            }
            $base['planner']['slots'][$slot] = ['name'=>$name, 'positions'=>$nodes, 'savedAt'=>$now];
            return;
        }
        $positionsById = array_column($nodes, null, 'id');
        foreach ($base['buildings'] as &$building) {
            $node = $positionsById[(int)$building['id']];
            // Preserve scalar representations for unchanged coordinates as well as all other fields.
            if ((float)$building['x'] !== $node['x']) { $building['x'] = $node['x']; }
            if ((float)$building['y'] !== $node['y']) { $building['y'] = $node['y']; }
        }
        unset($building);
    }

    private static function coordinate(mixed $value): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
            throw new RuntimeException('Choose a finite numeric layout position.');
        }
        return (float)$value;
    }

    private static function integer(mixed $value, string $label, int $maximum): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('Choose a valid '.$label.'.');
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>$maximum]]);
        if ($number === false) { throw new RuntimeException('Choose a valid '.$label.'.'); }
        return $number;
    }

    private static function name(mixed $value): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw new RuntimeException('Give the layout a valid name.');
        }
        $name = preg_replace('/^\s+|\s+$/u', '', $value);
        if ($name === '' || preg_match('/[\x00-\x1f\x7f]/', $name)
            || preg_match_all('/./us', $name) > self::MAX_NAME_LENGTH) {
            throw new RuntimeException('Use a layout name between 1 and 40 characters without control characters.');
        }
        return $name;
    }
}
