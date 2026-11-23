<?php
declare(strict_types=1);

require_once __DIR__.'/Brukkarg.php';

/** Source Brukkarg attack/defend groups. The keeper selects the next wave only. */
final class BrukkargDefense
{
    private const EDGES = ['t','tr','r','br','b','bl','l','tl'];

    public static function waves(): array
    {
        static $waves;
        return $waves ??= json_decode(file_get_contents(__DIR__.'/brukkarg-waves.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function start(array $yard, array $owner, int $stage, int $now, float $defense, array $network): array
    {
        EventCalendar::checkStart($yard,'brukkarg',$now);
        if ($stage !== Brukkarg::nextWave($yard)) {
            throw new RuntimeException('Open Brukkarg War for the next defense or expedition.');
        }
        if (Game::livingLevel($yard, 'townhall') < 1) {
            throw new RuntimeException('Repair your Town Hall before defending against Brukkarg.');
        }
        $groups = self::waves()[$stage - 1];
        // The Flash event rotates this entire formation once for the encounter.
        // Use one of the eight compass approaches that fit the isometric yard.
        $rotation = random_int(0, count(self::EDGES) - 1);
        foreach ($groups as &$group) {
            $edge = array_search($group[3], self::EDGES, true);
            $group[3] = self::EDGES[($edge + $rotation) % count(self::EDGES)];
        }
        unset($group);
        $direction = $groups[0][3];
        $state = Baiter::defenseState($yard, $owner, MonsterBlitz::counts($groups), $direction, $now, $defense, $network, true);
        $state['formatVersion'] = max(8, $state['formatVersion']);
        $approaches = [$direction => $state['_approach']];
        foreach (array_unique(array_column($groups,3)) as $edge) {
            $approaches[$edge] ??= Baiter::entryPoints($state, $edge, true);
        }
        $state['brukkargWave'] = ['stage'=>$stage,'groups'=>$groups,'next'=>0,'approaches'=>$approaches];
        $state['target'] = ['kind'=>'brukkarg-defense','name'=>'Brukkarg War'];
        $state['attacker']['name'] = 'Brukkarg';
        $state['remaining'] = $state['wave'];
        return $state;
    }

    public static function attacker(string $type, string $id, float $x, float $y): array
    {
        // Every Brukkarg WaveObj uses creature level six and power level three.
        if ($type === 'krallen') {
            // Its source table ends at tier five; GetGuardianProperty clamps the index.
            $stats = ['hp'=>62000,'damage'=>1200,'speed'=>2.6,'range'=>65/80,
                'attackInterval'=>1,'target'=>'resource','movement'=>'ground','targetMode'=>'ground'];
        } elseif (isset(Catalog::champions()[$type])) {
            $stats = Catalog::championStats($type,6);
        } else {
            return BattleService::makeUnit($type,6,$id,'attacker',$x,$y,null,3);
        }
        $champion = ['type'=>$type,'level'=>6,'hp'=>$stats['hp'],'combatStats'=>$stats];
        return BattleService::makeChampionUnit($champion,$id,'attacker',$x,$y);
    }
}
