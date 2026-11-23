<?php
declare(strict_types=1);

/** Ten northern defense waves from MonsterBlitzkrieg.as. The first number is seconds after launch. */
final class MonsterBlitz
{
    private const WAVES = [
        [[0,'octo',8]],
        [[0,'octo',10],[1,'bolt',5]],
        [[0,'octo',5],[1,'pokey',10]],
        [[0,'pokey',20],[1,'bolt',15]],
        [[0,'slimeattikus',3],[0,'fink',5]],
        [[0,'octo',6],[2,'fink',8]],
        [[0,'ichi',10],[5,'bolt',50]],
        [[0,'pokey',40],[2,'fink',8]],
        [[0,'octo',10],[1,'pokey',10],[1,'fink',10],[6,'bolt',10]],
        [[0,'slimeattikus',8]],
    ];

    public static function view(array $base, int $now): array
    {
        $cleared = max(0, min(count(self::WAVES), (int)($base['blitzCleared'] ?? 0)));
        $hall = Game::livingLevel($base, 'townhall');
        $reason = '';
        if ($cleared === count(self::WAVES)) {
            $reason = 'All ten waves are complete.';
        } elseif ($hall < 3) {
            $reason = 'A working level 3 Town Hall opens Monster Blitzkrieg.';
        } elseif ((int)($base['blitzDeadline'] ?? 0) > 0 && $now >= $base['blitzDeadline']) {
            $reason = 'This six-day run has ended. Start a new run from wave one.';
        }
        return [
            'cleared'=>$cleared, 'total'=>count(self::WAVES), 'reason'=>$reason,
            'eligible'=>$reason === '', 'restart'=>$cleared < 10 && $hall >= 3 && isset($base['blitzDeadline']) && $now >= $base['blitzDeadline'],
            'deadline'=>(int)($base['blitzDeadline'] ?? 0), 'rosters'=>array_map([self::class,'counts'], self::WAVES),
        ];
    }

    public static function counts(array $groups): array
    {
        $wave = [];
        foreach ($groups as [, $type, $count]) { $wave[$type] = ($wave[$type] ?? 0) + $count; }
        return $wave;
    }

    public static function start(array $yard, array $owner, int $stage, int $now, float $defense, array $network): array
    {
        EventCalendar::checkStart($yard,'blitz',$now);
        $status = self::view($yard, $now);
        $expected = $status['restart'] ? 1 : $status['cleared'] + 1;
        if ((!$status['eligible'] && !$status['restart']) || $stage !== $expected || !isset(self::WAVES[$stage - 1])) {
            throw new RuntimeException($status['reason'] ?: 'Refresh the event to see your next wave.');
        }
        $groups = self::WAVES[$stage - 1];
        $state = Baiter::defenseState($yard, $owner, self::counts($groups), 't', $now, $defense, $network);
        $state['formatVersion'] = max(8, $state['formatVersion']);
        $state['blitz'] = ['stage'=>$stage, 'groups'=>$groups, 'next'=>0];
        $state['target'] = ['kind'=>'blitz','name'=>'Monster Blitzkrieg'];
        // Hold the combat session open between arriving groups, even if the field is temporarily empty.
        $state['remaining'] = $state['wave'];
        return $state;
    }

    public static function advance(array &$s, int $now): void
    {
        if ($s['status'] !== 'active' || $now < $s['launch_at']) { return; }
        $limit = min((float)($now - $s['launch_at']), $s['elapsed'] + 2.0, (float)$s['duration']);
        $s['launched'] = true;
        $brukkarg = isset($s['brukkargWave']);
        $moloch=isset($s['molochWave']);
        $event = $moloch?'molochWave':($brukkarg ? 'brukkargWave' : (isset($s['horseWave']) ? 'horseWave' : 'blitz'));
        while ($s['status'] === 'active') {
            $next = $s[$event]['groups'][$s[$event]['next']] ?? null;
            $until = $next === null ? $limit : min($limit, (float)$next[0]);
            BattleEngine::advance($s, $until);
            if ($s['status'] !== 'active' || $next === null || $s['elapsed'] < $next[0]) { break; }
            [, $type, $quantity] = $next;
            $points = ($brukkarg || $moloch) ? $s[$event]['approaches'][$next[3]] : $s['_approach'];
            for ($i = 0; $i < $quantity; ++$i) {
                $point = $points[$s['unitSeq'] % count($points)];
                $id = 'w'.(++$s['unitSeq']);
                $unit = $brukkarg ? BrukkargDefense::attacker($type,$id,$point['x'],$point['y'])
                    : BattleService::makeUnit($type,1,$id,'attacker',$point['x'],$point['y']);
                if ($event==='horseWave') {
                    $power=$s['horseWave']['power'];
                    $unit['hp']=$unit['max_hp']=max(1,(int)($unit['max_hp']*$power));
                    $unit['_damage']=(int)($unit['_damage']*$power);
                }
                $s['units'][] = $unit;
                BattleEngine::event($s,'deploy',['x'=>$point['x'],'y'=>$point['y'],'sourceId'=>$id,'kind'=>$type,'team'=>'attacker','champion'=>!empty($unit['champion'])]);
            }
            $s['remaining'][$type] -= $quantity;
            ++$s[$event]['next'];
        }
        if ($s['status'] === 'finished') {
            $damage = $moloch?MolochSiege::yardDamage($s):BattleEngine::captureProgress($s);
            $cleared=!$moloch || ($s[$event]['next']===count($s[$event]['groups']) && !array_filter($s['units'],static fn($u)=>$u['team']==='attacker'&&$u['hp']>0));
            Baiter::setResult($s, $s['result']['reason'], $damage < 90.0 && $cleared ? 'held' : 'overrun');
            $s['result']['destruction'] = round($damage, 2);
        }
    }

    /** Called with the home row locked, on the single transition to finished. */
    public static function settle(array &$base, array $s): void
    {
        self::applyDamage($base,$s);
        self::progress($base,$s);
    }

    public static function applyDamage(array &$base,array $s): void
    {
        // An event affects only buildings that participated, and only health actually lost.
        $damage = [];
        foreach ($s['buildings'] as $building) {
            $loss = max(0.0, (float)$building['_initial_hp'] - (float)$building['hp']);
            if ($loss > 0) { $damage[$building['id']] = $loss / ($building['_armamentScale'] ?? 1); }
        }
        foreach ($base['buildings'] as &$home) {
            if (isset($damage[$home['id']])) { $home['hp'] = max(0, $home['hp'] - $damage[$home['id']]); }
        }
        unset($home);
    }

    private static function progress(array &$base,array $s): void
    {
        if (isset($s['horseWave'])) { return; }
        if (isset($s['brukkargWave'])) {
            if ($s['result']['outcome'] === 'held') { Brukkarg::defended($base, $s['brukkargWave']['stage']); }
            return;
        }
        $stage = $s['blitz']['stage'];
        if ($s['result']['outcome'] !== 'held' || $stage !== (int)($base['blitzCleared'] ?? 0) + 1) { return; }
        $base['blitzCleared'] = $stage;
        if ($stage === count(self::WAVES)) {
            if (!in_array('slimeattikus', $base['unlocked'], true)) { $base['unlocked'][] = 'slimeattikus'; }
            $base['research']['slimeattikus'] = max(1, (int)($base['research']['slimeattikus'] ?? 1));
        }
    }
}
