<?php
declare(strict_types=1);

require_once __DIR__.'/BattleService.php';
require_once __DIR__.'/MonsterBlitz.php';
require_once __DIR__.'/BrukkargDefense.php';
require_once __DIR__.'/WildMonsterInvasions.php';
require_once __DIR__.'/InfernoEmergence.php';
require_once __DIR__.'/MolochSiege.php';

/** A private copy of a keeper's defenses, exercised by an independently selected wild army. */
final class Baiter
{
    // MONSTERBAITERPOPUP offers C1 through C14. Each wild level-one unit costs its source cStorage.
    private const COSTS = [
        'pokey'=>10, 'octo'=>10, 'bolt'=>15, 'fink'=>20, 'eyera'=>60, 'ichi'=>20, 'bandito'=>20,
        'fang'=>30, 'brain'=>30, 'crabatron'=>40, 'projectx'=>70, 'dave'=>160, 'wormzer'=>70, 'teratorn'=>70,
    ];
    private const CAPACITY = [600, 900, 1200, 1500, 2100, 3200, 4800];
    // Screen-relative directions, mapped onto the renderer's isometric yard coordinates.
    private const ENTRY = [
        'tl'=>[0.5,16], 'tr'=>[16,0.5], 'br'=>[31.5,16], 'bl'=>[16,31.5],
        't'=>[0.5,0.5], 'r'=>[31.5,0.5], 'b'=>[31.5,31.5], 'l'=>[0.5,31.5],
    ];
    private const MAX_UNITS = 48;
    private const COUNTDOWN = 3;
    private const SECONDS_PER_POLL = 2;

    public static function options(array $base, int $now): array
    {
        $level = 0;
        $reason = 'Build a Wild Monster Baiter to run a defense drill.';
        foreach ($base['buildings'] as $building) {
            if ($building['type'] !== 'baiter') { continue; }
            $level = max(0, min(count(self::CAPACITY), (int)$building['level']));
            if ($level === 0 || (int)($building['ready_at'] ?? 0) > $now) {
                $reason = 'Finish construction or the current Baiter upgrade first.';
            } elseif ($building['hp'] <= 0) {
                $reason = 'Repair the Wild Monster Baiter first.';
            } else {
                $reason = '';
            }
            break;
        }
        return [
            'available'=>$reason === '', 'reason'=>$reason, 'level'=>$level,
            'capacity'=>$level ? self::CAPACITY[$level - 1] : 0, 'costs'=>self::COSTS,
            'directions'=>array_slice(array_keys(self::ENTRY), 0, $level >= 3 ? 8 : 4),
            'maxUnits'=>self::MAX_UNITS,
        ];
    }

    public static function start(array $base, array $owner, array $wave, string $direction, int $now,
        float $defenseBonus = 0.0, array $network = []): array
    {
        $options = self::options($base, $now);
        if (!$options['available']) { throw new RuntimeException($options['reason']); }
        if (!in_array($direction, $options['directions'], true)) {
            throw new RuntimeException('Choose an approach available at this Baiter level.');
        }
        $wave = self::validateWave($wave, $options['capacity']);
        return self::defenseState($base, $owner, $wave, $direction, $now, $defenseBonus, $network);
    }

    public static function defenseState(array $base, array $owner, array $wave, string $direction,
        int $now, float $defenseBonus, array $network, bool $wildApproach = false): array
    {
        $network += ['count'=>0, 'reduction'=>0.0, 'siteIds'=>[]];
        $catalog = Catalog::buildings();
        $buildings = [];
        foreach (YardUtilities::hatcheryWork($base,$now) as $building) {
            if (isset($catalog[$building['type']])) {
                $buildings[] = BattleService::freezeBuilding($building, $catalog[$building['type']], $defenseBonus, (float)$network['reduction'], $now);
            }
        }
        $state = [
            'id'=>-random_int(1, 2147483647), 'training'=>true, 'status'=>'active', 'created_at'=>$now,
            'launch_at'=>$now + self::COUNTDOWN, 'launched'=>false, 'direction'=>$direction, 'wave'=>$wave,
            'elapsed'=>0.0, 'duration'=>BattleEngine::DURATION, 'formatVersion'=>7,
            'attacker'=>['id'=>0, 'name'=>'Wild monsters', 'is_bot'=>false],
            'defender'=>['id'=>(int)$owner['id'], 'name'=>(string)$owner['name'], 'is_bot'=>false],
            'target'=>['kind'=>'training', 'name'=>'Wild Monster Baiter'],
            'signs'=>YardMessages::signs($base), 'decorations'=>Decorations::visible($base), 'buildings'=>$buildings, 'units'=>[], 'events'=>[], 'eventSeq'=>0, 'unitSeq'=>0, 'obstacleVersion'=>0,
            'remaining'=>[], 'result'=>null, 'networkDefense'=>$network,
            'champion'=>null, 'defenderChampion'=>BattleService::readyChampion($base, $now),
            '_garrisonTemplates'=>[],
        ];
        if(($base['_allianceArmamentEnd']??0)>$now){
            foreach($state['buildings'] as &$building)AlliancePowers::arm($building);
            unset($building);
            $state['alliancePowers']=['offense'=>[],'defense'=>['armament'=>$base['_allianceArmamentEnd']]];
        }
        $state['yardExtent']=YardSpace::extent($base);
        $state['_approach'] = self::entryPoints($state, $direction, $wildApproach);
        StorePowers::combat($state,[],$base,$state['launch_at']);
        BattleService::seedHomeDefense($state, $base);
        return $state;
    }

    private static function validateWave(array $wave, int $capacity): array
    {
        $total = 0;
        $cost = 0;
        foreach ($wave as $type => $quantity) {
            if (!isset(self::COSTS[$type]) || !is_int($quantity) || $quantity < 0 || $quantity > self::MAX_UNITS) {
                throw new RuntimeException('Choose valid wild species and whole monster counts.');
            }
            $total += $quantity;
            $cost += $quantity * self::COSTS[$type];
        }
        if ($total === 0 || $total > self::MAX_UNITS) {
            throw new RuntimeException('Choose between 1 and '.self::MAX_UNITS.' wild monsters.');
        }
        if ($cost > $capacity) { throw new RuntimeException('The selected wave exceeds the musk capacity.'); }
        // Canonical species order keeps the spawn formation independent of JSON object ordering.
        $selected = [];
        foreach (self::COSTS as $type => $unused) {
            if (!empty($wave[$type])) { $selected[$type] = $wave[$type]; }
        }
        return $selected;
    }

    public static function entryPoints(array $state, string $direction, bool $wildApproach = false): array
    {
        [$cx, $cy] = self::ENTRY[$direction];
        $margin=YardSpace::margin($state);$low=.5-$margin;$high=31.5+$margin;
        $cx+=($cx<=>16)*$margin;$cy+=($cy<=>16)*$margin;
        $edge = [];
        for ($n = $low; $n <= $high; $n += 1) {
            foreach ([[$n,$low], [$n,$high], [$low,$n], [$high,$n]] as [$x,$y]) {
                $distance = hypot($x - $cx, $y - $cy);
                if ($distance <= 12 && BattleEngine::legalDeployment($state, $x, $y)) {
                    $edge["$x/$y"] = ['x'=>$x, 'y'=>$y, 'distance'=>$distance];
                }
            }
        }
        // Wild event monsters can arrive at a heavily fortified side. Player
        // Baiter drills continue to require a legal, unblocked deployment point.
        if (!$edge && $wildApproach) { return [['x'=>$cx,'y'=>$cy,'distance'=>0.0]]; }
        if (!$edge) { throw new RuntimeException('That approach is blocked. Select another direction.'); }
        usort($edge, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);
        return array_slice($edge, 0, 5);
    }

    public static function advance(array &$state, int $now): void
    {
        if (isset($state['emergenceWave'])) { InfernoEmergence::advance($state,$now); return; }
        if (isset($state['wmiWave'])) { WildMonsterInvasions::advance($state,$now); return; }
        if (isset($state['molochWave']) || isset($state['horseWave']) || isset($state['blitz']) || isset($state['brukkargWave'])) { MonsterBlitz::advance($state, $now); return; }
        if ($state['status'] !== 'active' || $now < $state['launch_at']) { return; }
        if (!$state['launched']) {
            $points = $state['_approach'];
            foreach ($state['wave'] as $type => $quantity) {
                for ($i = 0; $i < $quantity; ++$i) {
                    $point = $points[$state['unitSeq'] % count($points)];
                    $id = 'w'.(++$state['unitSeq']);
                    $state['units'][] = BattleService::makeUnit($type, 1, $id, 'attacker', $point['x'], $point['y']);
                    BattleEngine::event($state, 'deploy', ['x'=>$point['x'], 'y'=>$point['y'], 'sourceId'=>$id, 'kind'=>$type, 'team'=>'attacker']);
                }
            }
            $state['launched'] = true;
        }
        $target = min($now - $state['launch_at'], $state['elapsed'] + self::SECONDS_PER_POLL);
        BattleEngine::advance($state, $target);
        if ($state['status'] === 'finished') {
            $reason = $state['result']['reason'];
            self::setResult($state, $reason, $state['result']['attackerWon'] ? 'overrun' : 'held');
        }
    }

    public static function siren(array &$state): void
    {
        if ($state['status'] === 'finished') { return; }
        $state['status'] = 'finished';
        self::setResult($state, 'siren', 'dismissed');
    }

    public static function setResult(array &$state, string $reason, string $outcome): void
    {
        $surviving = 0;
        $spawned = 0;
        foreach ($state['units'] as $unit) {
            if ($unit['team'] !== 'attacker') { continue; }
            ++$spawned;
            if ($unit['hp'] > 0) { ++$surviving; }
        }
        $state['result'] = [
            'reason'=>$reason, 'outcome'=>$outcome, 'destruction'=>BattleEngine::destruction($state),
            'spawned'=>$spawned, 'defeated'=>$spawned - $surviving, 'surviving'=>$surviving,
            'rankDelta'=>['attacker'=>0, 'defender'=>0], 'loot'=>[],
        ];
    }

    public static function snapshot(array $state, int $now): array
    {
        $snapshot = BattleService::snapshot($state, (int)$state['defender']['id']);
        if (isset($state['blitz']) || isset($state['brukkargWave'])) {
            if (isset($state['brukkargWave'])) {
                $snapshot['brukkargStage'] = $state['brukkargWave']['stage'];
                $snapshot['entries'] = array_map(static fn(array $points): array => ['x'=>$points[0]['x'],'y'=>$points[0]['y']], $state['brukkargWave']['approaches']);
            } else { $snapshot['blitzStage'] = $state['blitz']['stage']; }
            $snapshot['destruction'] = round(BattleEngine::captureProgress($state), 2);
            if ($snapshot['status'] === 'finished') { $snapshot['result']['destruction'] = $snapshot['destruction']; }
        }
        if (isset($state['wmiWave'])) {
            $w=$state['wmiWave']; $snapshot['wmiEvent']=$w['event']; $snapshot['wmiStage']=$w['stage'];
            $snapshot['entries']=array_map(static fn($p)=>['x'=>$p[0]['x'],'y'=>$p[0]['y']],$w['approaches']);
            $snapshot['destruction']=round(BattleEngine::captureProgress($state),2);
            if ($snapshot['status']==='finished') { $snapshot['result']['destruction']=$snapshot['destruction']; }
        }
        if(isset($state['molochWave'])){
            $wave=$state['molochWave'];$snapshot['molochStage']=$wave['stage'];
            $snapshot['entries']=array_map(static fn($points)=>['x'=>$points[0]['x'],'y'=>$points[0]['y']],$wave['approaches']);
            $snapshot['destruction']=round(MolochSiege::yardDamage($state),2);
            if($snapshot['status']==='finished')$snapshot['result']['destruction']=$snapshot['destruction'];
        }
        if (isset($state['emergenceWave'])) {
            $snapshot['emergenceStage']=$state['emergenceWave']['stage'];
            $snapshot['destruction']=round(BattleEngine::captureProgress($state),2);
            if ($snapshot['status']==='finished') { $snapshot['result']['destruction']=$snapshot['destruction']; }
        }
        if (isset($state['horseWave'])) { $snapshot['horse']=true; }
        $snapshot['training'] = true;
        $snapshot['countdown'] = $state['status'] === 'active' ? max(0, min(self::COUNTDOWN, $state['launch_at'] - $now)) : 0;
        $snapshot['direction'] = $state['direction'];
        $snapshot['entry'] = ['x'=>$state['_approach'][0]['x'], 'y'=>$state['_approach'][0]['y']];
        $snapshot['wave'] = $state['wave'];
        $snapshot['serverTime'] = $now;
        return $snapshot;
    }
}
