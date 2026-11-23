<?php
declare(strict_types=1);

/** Standard-yard monster bucket rules. An invasion freezes its own loadout at launch. */
final class Flinger
{
    public const INVASION_LIMIT = 200;
    public const CAPACITIES = [250, 850, 1500, 2500, 3500];
    public const WEIGHTS = [
        'pokey'=>7, 'octo'=>10, 'bolt'=>15, 'fink'=>20, 'eyera'=>60,
        'ichi'=>20, 'bandito'=>20, 'fang'=>30, 'brain'=>30, 'crabatron'=>40,
        'projectx'=>70, 'dave'=>160, 'wormzer'=>70, 'teratorn'=>70, 'zafreeti'=>200,
        'vorg'=>60, 'slimeattikus'=>40, 'slimeattikusmini'=>40, 'rezghul'=>250,
        'spurtz'=>15, 'zagnoid'=>15, 'malphus'=>15, 'valgos'=>30, 'balthazar'=>40, 'grokus'=>50, 'sabnox'=>80, 'kingwormzer'=>100,
    ];

    public static function loadout(int $level): array
    {
        $level = max(1, min(count(self::CAPACITIES), $level));
        return [
            'level'=>$level, 'capacity'=>self::CAPACITIES[$level - 1], 'weights'=>self::WEIGHTS,
            'unitLimit'=>self::INVASION_LIMIT, 'pixelsPerTile'=>80, 'cooldown'=>5.0, 'readyAt'=>0.0,
        ];
    }

    /** A source diameter in ground pixels becomes a clearance radius on the compact yard. */
    public static function clearance(array $flinger, int $weight): float
    {
        return max(200, intdiv($weight, 4)) / (2 * $flinger['pixelsPerTile']);
    }

    public static function legalAim(array $state, float $x, float $y, int $weight): bool
    {
        if (!isset($state['flinger']['weights'])) {
            return BattleEngine::legalDeployment($state, $x, $y);
        }
        if (!YardSpace::contains($state,$x,$y,.4)) {
            return false;
        }
        $radius = self::clearance($state['flinger'], $weight);
        foreach ($state['buildings'] as $building) {
            if ($building['hp'] <= 0 || Catalog::isTrap($building['type']) || !empty($building['_untargetable'])) {
                continue;
            }
            // The original checks projected ovals. The remake's solid foundations are square.
            $half = ($building['_size'] ?? 2) / 2;
            $dx = max(0.0, abs($building['x'] - $x) - $half);
            $dy = max(0.0, abs($building['y'] - $y) - $half);
            if ($dx * $dx + $dy * $dy < $radius * $radius) {
                return false;
            }
        }
        return true;
    }

    public static function landing(array $state, int $weight, float $x, float $y, int $index): array
    {
        if (!isset($state['flinger']['weights'])) {
            // Existing invasions keep their compact spiral and exclusion checks.
            $angle = $index * 2.399963;
            $radius = min(0.4, sqrt($index) * 0.07);
            $px = $x + cos($angle) * $radius;
            $py = $y + sin($angle) * $radius;
            return BattleEngine::legalDeployment($state, $px, $py) ? [$px, $py] : [$x, $y];
        }
        $diameter = max(200, intdiv($weight, 4));
        $scale = $state['flinger']['pixelsPerTile'];
        $seed = $state['id'].'/'.$state['unitSeq'].'/';
        for ($attempt = 0; $attempt < 12; ++$attempt) {
            // Independent angle and linear radial draws in the original projected ground plane.
            // Use stable per-unit samples so a retried transaction makes the same launch.
            $sample = unpack('Nangle/Nradius', hash('sha256', $seed.$attempt, true));
            $angle = $sample['angle'] / 4294967296 * 2 * M_PI;
            $radius = floor($sample['radius'] / 4294967296 * intdiv($diameter, 2) / 2) / $scale;
            $across = sin($angle) * $radius;
            $down = cos($angle) * $radius * 2;
            $px = $x + ($across + $down) / sqrt(2);
            $py = $y + ($down - $across) / sqrt(2);
            if (YardSpace::contains($state,$px,$py,.4)) {
                return [$px, $py];
            }
        }
        return [$x, $y];
    }

    /** Try every side before searching interior clearings in a densely defended yard. */
    public static function botSlots(array $state, int $edge, int $weight, bool $champion = false): array
    {
        $positions = [];$margin=YardSpace::margin($state);$end=32+$margin;
        for ($side = 0; $side < 4; ++$side) {
            for ($along = 1-$margin; $along < $end; ++$along) {
                [$x, $y] = match (($edge + $side) % 4) {
                    0 => [$along, 0.65-$margin], 1 => [31.35+$margin, $along],
                    2 => [$along, 31.35+$margin], default => [0.65-$margin, $along],
                };
                if ($champion ? BattleEngine::legalDeployment($state, $x, $y) : self::legalAim($state, $x, $y, $weight)) {
                    $positions[] = [$x, $y];
                }
            }
            if ($positions) { return $positions; }
        }
        for ($y = 1-$margin; $y < $end; ++$y) {
            for ($x = 1-$margin; $x < $end; ++$x) {
                if ($champion ? BattleEngine::legalDeployment($state, $x, $y) : self::legalAim($state, $x, $y, $weight)) {
                    return [[$x, $y]];
                }
            }
        }
        return [];
    }
}
