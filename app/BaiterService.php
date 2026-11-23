<?php
declare(strict_types=1);

require_once __DIR__.'/Baiter.php';

/** The latest drill belongs to its keeper's locked home row; real invasion reservations take priority. */
final class BaiterService
{
    public static function run(int $keeperId, string $action, array $input): array
    {
        return Db::transaction(static function(PDO $pdo) use ($keeperId, $action, $input): array {
            $base = Game::loadBase($keeperId, true);
            $owner = Game::user($keeperId);
            if ($owner['is_bot']) {
                throw new RuntimeException('Sign in as a player to start a defense drill.');
            }
            $busy = $pdo->prepare("SELECT id FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?) LIMIT 1");
            $busy->execute([$keeperId, $keeperId]);
            if ($busy->fetchColumn()) {
                throw new RuntimeException('Finish the real invasion before using the Wild Monster Baiter.');
            }

            $now = time();
            $defenseBonus = 0.0;
            $network = [];
            if (in_array($action, ['baiterStart','blitzStart','brukkargDefend','horseStart','wmiStart','emergenceStart','molochStart'], true)) {
                $home = World::home($keeperId);
                if ($home) {
                    $network = World::fortificationSupport($keeperId, $home['q'], $home['r']);
                    $defenseBonus = World::combatBonuses(0, $keeperId, $home['q'], $home['r'])['defender'];
                }
            }
            $before = $base['baiterPractice']['revision'] ?? null;
            $previousId = $base['baiterPractice']['id'] ?? null;
            $session = $base['baiterPractice'] ?? null;
            if (in_array($action, ['baiterStart','blitzStart','brukkargDefend','horseStart','wmiStart','emergenceStart','molochStart'], true) && ($session === null || $session['requestId'] !== ($input['requestId'] ?? null))) {
                // Complete any due work in the copy from which this drill will take its defenses.
                $defendingYard = $base;
                Game::accrue($defendingYard, $now);
                $active=AlliancePowers::active($pdo,$keeperId,$now);
                if(isset($active['armament']))$defendingYard['_allianceArmamentEnd']=$active['armament'];
            }
            $wasFinished = ($session['state']['status'] ?? null) === 'finished';
            $response = self::apply($session, $action, $input, $defendingYard ?? $base, $owner, $now, $defenseBonus, $network);
            if ($session['revision'] !== $before || $session['id'] !== $previousId) {
                if(isset($session['state']['molochWave'])){
                    if(!$wasFinished && $session['state']['status']==='finished')MolochSiege::settle($base,$session['state'],$now);
                } elseif (isset($session['state']['emergenceWave'])) {
                    if (!$wasFinished && $session['state']['status']==='finished') { InfernoEmergence::settle($base,$session['state'],$now); }
                } elseif (isset($session['state']['wmiWave'])) {
                    if ($session['id']!==$previousId) { WildMonsterInvasions::rememberStart($base,$session['state']); }
                    if (!$wasFinished && $session['state']['status']==='finished') { WildMonsterInvasions::settle($base,$session['state'],$now); }
                } elseif (isset($session['state']['horseWave'])) {
                    if ($session['id'] !== $previousId) { $base['seasonal']['horseOpened']=true; }
                    if (!$wasFinished && $session['state']['status']==='finished') { MonsterBlitz::settle($base,$session['state']); }
                } elseif (isset($session['state']['blitz'])) {
                    if ($session['id'] !== $previousId && ($now >= (int)($base['blitzDeadline'] ?? 0))) {
                        $base['blitzCleared'] = 0;
                        $base['blitzDeadline'] = $now + 518400;
                    }
                    if (!$wasFinished && $session['state']['status'] === 'finished') {
                        MonsterBlitz::settle($base, $session['state']);
                    }
                } elseif (isset($session['state']['brukkargWave']) && !$wasFinished && $session['state']['status'] === 'finished') {
                    MonsterBlitz::settle($base,$session['state']);
                }
                $base['baiterPractice'] = $session;
                Game::saveBase($keeperId, $base);
            }
            if (isset($session['state']['molochWave'])) { $response['molochSiege']=MolochSiege::view($base); }
            if (isset($session['state']['emergenceWave'])) { $response['emergence']=InfernoEmergence::view($base,$now); }
            if (isset($session['state']['blitz'])) { $response['blitz'] = MonsterBlitz::view($base, $now); }
            if (isset($session['state']['wmiWave'])) { $response['wmi']=WildMonsterInvasions::view($base,$now); }
            return $response;
        });
    }

    /** A session transition affects only the drill. The caller owns the transaction and persistence. */
    public static function apply(?array &$session, string $action, array $input, array $yard, array $owner,
        int $now, float $defenseBonus = 0.0, array $network = []): array
    {
        if (in_array($action, ['baiterStart','blitzStart','brukkargDefend','horseStart','wmiStart','emergenceStart','molochStart'], true)) {
            $request = $input['requestId'] ?? null;
            if (!is_string($request) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $request)) {
                throw new RuntimeException('Refresh the Baiter controls and try again.');
            }
            if($session!==null && $session['requestId']===$request && ($action==='molochStart'||isset($session['state']['molochWave'])) && ($action!=='molochStart'||!isset($session['state']['molochWave'])||($input['stage']??null)!==$session['state']['molochWave']['stage']))throw new RuntimeException('Choose a fresh request for this Hell-Raisers wave.');
            if ($session!==null && $session['requestId']===$request && $action==='emergenceStart'
                && (!isset($session['state']['emergenceWave']) || ($input['stage']??null)!==$session['state']['emergenceWave']['stage'])) {
                throw new RuntimeException('Reopen the matching emergence defense.');
            }
            if ($session!==null && $session['requestId']===$request && $action==='wmiStart') {
                $wave=$session['state']['wmiWave']??null;
                if (!$wave || $wave['event']!==($input['event']??null) || $wave['stage']!==($input['stage']??null) || $wave['requestedRun']!==($input['run']??null)) {
                    throw new RuntimeException('Choose a fresh request for this invasion wave.');
                }
            }
            if ($session === null || $session['requestId'] !== $request) {
                // The same HTTP retry returns the same drill. A delayed older request
                // cannot create another drill after a newer tab has replaced it.
                if (!array_key_exists('previousId', $input) || $input['previousId'] !== ($session['id'] ?? null)) {
                    throw new RuntimeException('Your drill changed. Refresh the Baiter controls.');
                }
                if (($session['state']['status'] ?? null) === 'active') {
                    throw new RuntimeException('A defense drill is already active. Resume it or sound the siren.');
                }
                if($action==='molochStart'){
                    $state=MolochSiege::start($yard,$owner,$input,$now,$defenseBonus,$network);
                }elseif ($action==='emergenceStart') {
                    $state=InfernoEmergence::start($yard,$owner,$input,$now,$defenseBonus,$network);
                } elseif ($action==='wmiStart') {
                    $state=WildMonsterInvasions::start($yard,$owner,$input,$now,$defenseBonus,$network);
                } elseif ($action === 'horseStart') {
                    $state=Seasonal::horseBattle($yard,$owner,$now,$defenseBonus,$network);
                } elseif ($action === 'blitzStart' || $action === 'brukkargDefend') {
                    $stage = $input['stage'] ?? null;
                    if (!is_int($stage)) { throw new RuntimeException('Choose a valid event wave.'); }
                    $state = $action === 'brukkargDefend'
                        ? BrukkargDefense::start($yard, $owner, $stage, $now, $defenseBonus, $network)
                        : MonsterBlitz::start($yard, $owner, $stage, $now, $defenseBonus, $network);
                } else {
                    if (!is_array($input['wave'] ?? null) || !is_string($input['direction'] ?? null)) {
                        throw new RuntimeException('Choose a wave and an approach before starting.');
                    }
                    $state = Baiter::start($yard, $owner, $input['wave'], $input['direction'], $now, $defenseBonus, $network);
                }
                $session = ['id'=>bin2hex(random_bytes(16)), 'requestId'=>$request, 'revision'=>1, 'state'=>$state];
            }
        } else {
            if (!$session || ($input['id'] ?? null) !== $session['id']) {
                throw new RuntimeException('That defense drill is no longer current.');
            }
            $before = $session['state'];
            if ($action === 'baiterPoll') {
                Baiter::advance($session['state'], $now);
            } elseif ($action === 'baiterSiren') {
                Baiter::siren($session['state']);
            } else {
                throw new RuntimeException('Choose a valid Baiter action.');
            }
            if ($session['state'] !== $before) {
                ++$session['revision'];
            }
        }
        return ['session'=>self::summary($session), 'battle'=>Baiter::snapshot($session['state'], $now)];
    }

    public static function summary(?array $session): ?array
    {
        if ($session === null) { return null; }
        $summary = ['id'=>$session['id'], 'status'=>$session['state']['status'], 'revision'=>$session['revision']];
        if (isset($session['state']['blitz'])) { $summary['blitzStage'] = $session['state']['blitz']['stage']; }
        if (isset($session['state']['brukkargWave'])) { $summary['brukkargStage'] = $session['state']['brukkargWave']['stage']; }
        if (isset($session['state']['molochWave'])) { $summary['molochStage']=$session['state']['molochWave']['stage']; }
        if (isset($session['state']['emergenceWave'])) { $summary['emergenceStage']=$session['state']['emergenceWave']['stage']; }
        if (isset($session['state']['horseWave'])) { $summary['horse']=true; }
        if (isset($session['state']['wmiWave'])) {
            $summary['wmiEvent']=$session['state']['wmiWave']['event']; $summary['wmiStage']=$session['state']['wmiWave']['stage'];
        }
        return $summary;
    }
}
