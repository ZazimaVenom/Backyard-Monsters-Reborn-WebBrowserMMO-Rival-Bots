<?php
declare(strict_types=1);
require_once __DIR__.'/Truces.php';
require_once __DIR__.'/InfernoBots.php';
require_once __DIR__.'/Alliances.php';

/**
 * Bounded, persistent world AI. Never runs 1,000 simulations inside a page view.
 * Mutations use the same economy and combat services as human players.
 */
final class Bots
{
    private const PROFILES = ['builder', 'raider', 'guardian', 'collector'];
    private const HOME_TOWERS = ['sniper', 'cannon', 'tesla', 'laser', 'aerial'];

    public static function settings(): array
    {
        $config = Db::config();
        $values = is_array($config['bots'] ?? null) ? $config['bots'] : [];
        return [
            'enabled' => (bool)($values['enabled'] ?? true),
            'batch_size' => max(1, min(100, (int)($values['batch_size'] ?? 25))),
            'turn_min' => max(30, min(3600, (int)($values['turn_min'] ?? $values['interval_min'] ?? 90))),
            'turn_max' => max(60, min(7200, (int)($values['turn_max'] ?? $values['interval_max'] ?? 300))),
            'attack_cooldown' => max(180, min(86400, (int)($values['attack_cooldown'] ?? 900))),
            'max_active_battles' => max(1, min(128, (int)($values['max_active_battles'] ?? 48))),
            'human_target_percent' => max(0, min(50, (int)($values['human_target_percent'] ?? 15))),
            'budget_seconds' => max(1.0, min(20.0, (float)($values['budget_seconds'] ?? 4.0))),
        ];
    }

    /**
     * Safe to invoke from a signed-in activity pulse, cron or the resident worker. An advisory lock serializes
     * all instances for this database. Due times are claimed before each turn so
     * a terminated worker cannot repeatedly execute the same due turn.
     */
    public static function tick(?int $limit = null, ?float $budget = null): array
    {
        $settings = self::settings();
        $start = microtime(true);
        $result = [
            'status' => 'ok', 'started_at' => time(), 'last_tick' => time(),
            'processed' => 0, 'built' => 0, 'upgraded' => 0, 'trained' => 0,
            'repaired' => 0, 'fortified' => 0, 'researched' => 0, 'lab_researched' => 0, 'unlocks' => 0, 'garrisoned' => 0, 'juiced' => 0, 'champions_raised' => 0, 'champions_fed' => 0, 'champions_thawed' => 0, 'attacks' => 0, 'territory_attacks' => 0, 'sites_upgraded' => 0, 'sites_repaired' => 0, 'sites_garrisoned' => 0,
            'network_attacks' => 0, 'network_upgraded' => 0, 'network_repaired' => 0, 'network_garrisoned' => 0,
            'workers_hired' => 0, 'layouts_improved' => 0, 'siege_researched'=>0, 'siege_built'=>0,
            'inferno_opened'=>0,'inferno_built'=>0,'inferno_upgraded'=>0,'inferno_trained'=>0,'inferno_researched'=>0,'inferno_unlocks'=>0,'inferno_repaired'=>0,'inferno_attacks'=>0,
            'battles_advanced' => 0, 'battles_finished' => 0, 'errors' => [],
        ];
        $db = Db::pdo();
        $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
        $lock = 'bym:world:' . substr(hash('sha256', $database), 0, 32);
        $stmt = $db->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$lock]);
        if ((int)$stmt->fetchColumn() !== 1) {
            return ['status' => 'busy', 'processed' => 0, 'last_tick' => time()];
        }
        try {
            $gate = WorldActivity::claimTick();
            $result['online_players'] = (int)$gate['onlinePlayers'];
            $result['activity_reason'] = $gate['reason'];
            if (!$gate['allowed']) {
                $result['status'] = $gate['reason'] === 'cooldown' ? 'idle' : 'paused';
                return $result;
            }
            self::shiftSchedule($gate);
            $limit = max(1, min(100, $limit ?? $settings['batch_size']));
            $budget = max(0.25, min(20.0, $budget ?? $settings['budget_seconds']));
            // Reserve part of this bounded pulse for due keepers. A backlog of
            // old battles must not starve every bot's building/hatching turn.
            if (class_exists('BattleService')) {
                $advanced = BattleService::tickActive(max(5, min(100, $settings['max_active_battles'])), $budget * 0.55, 20.0, true);
                $result['battles_advanced'] = (int)($advanced['processed'] ?? $advanced['advanced'] ?? $advanced['updated'] ?? (array_is_list($advanced) ? count($advanced) : 0));
                $result['battles_finished'] = (int)($advanced['finished'] ?? 0);
                foreach (array_slice($advanced['errors'] ?? [], 0, 10) as $error) {
                    $result['errors'][] = ['battle_id'=>(int)($error['id'] ?? 0), 'message'=>substr((string)($error['error'] ?? 'Battle progression failed'), 0, 180)];
                }
            }
            if ($settings['enabled']) {
                $now = time();
                $stmt = $db->prepare('SELECT id, next_turn_at FROM users WHERE is_bot = 1 AND next_turn_at <= ? ORDER BY next_turn_at, id LIMIT ' . $limit);
                $stmt->execute([$now]);
                $due = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $busy = self::busyUsers($db);
                $active = (int)$db->query("SELECT COUNT(*) FROM battles WHERE status = 'active'")->fetchColumn();
                $maxId = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM users')->fetchColumn();
                $diplomacyDone = false;
                foreach ($due as $row) {
                    if (microtime(true) - $start >= $budget) {
                        break;
                    }
                    if (!WorldActivity::clock()['online']) {
                        $result['status'] = 'paused';
                        $result['activity_reason'] = 'waiting';
                        break;
                    }
                    $id = (int)$row['id'];
                    $seed = self::seed($id, (int)$row['next_turn_at']);
                    $minimum = $settings['turn_min'];
                    $maximum = max($minimum, $settings['turn_max']);
                    $next = $now + $minimum + ($seed % ($maximum - $minimum + 1));
                    $claim = $db->prepare('UPDATE users SET next_turn_at = ? WHERE id = ? AND is_bot = 1 AND next_turn_at = ?');
                    $claim->execute([$next, $id, (int)$row['next_turn_at']]);
                    if ($claim->rowCount() !== 1) {
                        continue;
                    }
                    ++$result['processed'];
                    if (isset($busy[$id])) {
                        continue;
                    }
                    try {
                        if (!$diplomacyDone) {
                            Alliances::botDiplomacy();
                            $diplomacyDone = true;
                        }
                        Alliances::botTurn($id);
                        $turn = self::takeTurn($id, $seed, $settings, $busy, $active, $maxId);
                        foreach (['built', 'upgraded', 'trained', 'repaired', 'fortified', 'researched', 'lab_researched', 'unlocks', 'garrisoned', 'juiced', 'champions_raised', 'champions_fed', 'champions_thawed', 'attacks', 'territory_attacks', 'sites_upgraded', 'sites_repaired', 'sites_garrisoned', 'network_attacks', 'network_upgraded', 'network_repaired', 'network_garrisoned', 'workers_hired', 'layouts_improved', 'siege_researched', 'siege_built','inferno_opened','inferno_built','inferno_upgraded','inferno_trained','inferno_researched','inferno_unlocks','inferno_repaired','inferno_attacks'] as $counter) {
                            $result[$counter] += $turn[$counter] ?? 0;
                        }
                        if (($turn['attacks'] ?? 0) > 0) {
                            ++$active;
                            $busy[$id] = true;
                            if ((int)($turn['target_id'] ?? 0)>0) { $busy[(int)$turn['target_id']] = true; }
                        }
                    } catch (Throwable $e) {
                        // A failed bot turn does not prevent other bots from progressing.
                        // Store a bounded, non-sensitive diagnosis, without a stack trace.
                        $result['errors'][] = ['bot_id' => $id, 'message' => substr($e->getMessage(), 0, 180)];
                        $result['errors'] = array_slice($result['errors'], -10);
                    }
                }
            } else {
                $result['status'] = 'paused';
            }
            $result['total_bots'] = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_bot = 1')->fetchColumn();
            $dueCount = $db->prepare('SELECT COUNT(*) FROM users WHERE is_bot = 1 AND next_turn_at <= ?');
            $dueCount->execute([time()]);
            $result['due_bots'] = (int)$dueCount->fetchColumn();
            $result['active_battles'] = (int)$db->query("SELECT COUNT(*) FROM battles WHERE status = 'active'")->fetchColumn();
            $result['last_tick'] = time();
            $result['elapsed_ms'] = (int)round((microtime(true) - $start) * 1000);
            self::writeHeartbeat($result);
            return $result;
        } finally {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$lock]);
        }
    }

    /** Shift only outstanding scheduler deadlines, once per observed idle span. */
    private static function shiftSchedule(array $clock): void
    {
        Db::transaction(static function (PDO $db) use ($clock): void {
            $q = $db->prepare("SELECT value FROM settings WHERE name='bots_schedule_clock'");
            $q->execute();
            $stored = json_decode((string)($q->fetchColumn() ?: ''), true);
            $paused = max(0, (int)$clock['pausedSeconds']);
            if (!is_array($stored) || (int)($stored['epoch'] ?? 0) === (int)$clock['epoch']) {
                // A cold installed world already has an activity epoch, but no
                // dispatcher anchor until a player first opens the game.
                $delta = max(0, $paused - (int)($stored['pausedSeconds'] ?? 0));
                if ($delta > 0) {
                    $q = $db->prepare('UPDATE users SET next_turn_at=next_turn_at+? WHERE is_bot=1 AND next_turn_at>0');
                    $q->execute([$delta]);
                }
            }
            $q = $db->prepare('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
            $q->execute(['bots_schedule_clock', json_encode(['epoch'=>(int)$clock['epoch'], 'pausedSeconds'=>$paused], JSON_THROW_ON_ERROR)]);
        });
    }

    private static function busyUsers(PDO $db): array
    {
        $rows = $db->query("SELECT attacker_id, defender_id FROM battles WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        $busy = [];
        foreach ($rows as $row) {
            $busy[(int)$row['attacker_id']] = true;
            if ((int)($row['defender_id'] ?? 0)>0) { $busy[(int)$row['defender_id']] = true; }
        }
        return $busy;
    }

    private static function takeTurn(int $id, int $seed, array $settings, array $busy, int $active, int $maxId): array
    {
        $result = ['built'=>0, 'upgraded'=>0, 'trained'=>0, 'repaired'=>0, 'fortified'=>0, 'researched'=>0, 'lab_researched'=>0, 'unlocks'=>0, 'garrisoned'=>0, 'juiced'=>0, 'champions_raised'=>0, 'champions_fed'=>0, 'champions_thawed'=>0, 'attacks'=>0, 'territory_attacks'=>0, 'sites_upgraded'=>0, 'sites_repaired'=>0, 'sites_garrisoned'=>0, 'workers_hired'=>0, 'layouts_improved'=>0, 'siege_researched'=>0, 'siege_built'=>0];
        self::attempt($id, 'collect', []);
        $base = Game::loadBase($id);
        $profile = self::profile($id);
        $turnNumber = (int)($base['ai']['turns'] ?? 0) + 1;
        $lastAction = 'saving resources';
        if(Regions::botTurn($id,$base,$turnNumber)){
            $base=Game::loadBase($id);
            $lastAction='settling in a populated region';
        }
        $catalog = self::catalog();
        $counts = array_count_values(array_column($base['buildings'], 'type'));
        $missingFlinger = !isset($counts['flinger']);

        // The same free home recovery used by players restores every damaged
        // completed structure, including spent traps, once this online turn runs.
        // It does not finish paid work or restore lost monsters/champion health.
        $repair=Game::repairHomeStatus($base);$repairResponse=null;
        if($repair['available']&&self::attempt($id,'repairBase',[],$repairResponse)){
            $result['repaired']=(int)($repairResponse['repairResult']['repaired']??0);
            $lastAction='repairing the backyard';
            $base=Game::loadBase($id);
        }

        // More workers are paid permanent capacity, never a seeded privilege.
        // Keep enough for the next home investment and food before buying one.
        $construction=Construction::status($base,time());
        $hireCadence=match($profile){'builder'=>6,'guardian'=>8,'collector'=>9,default=>12};
        if (!$missingFlinger && ($turnNumber%$hireCadence===0 || $construction['available']===0)) {
            $hire=self::workerHirePlan($base,$profile,time());
            if($hire!==null && self::attempt($id,'hireWorker',$hire)){
                ++$result['workers_hired'];$lastAction='hiring another construction worker';
                $base=Game::loadBase($id);
            }
        }

        // Layouts are a measured occasional improvement, not a per-tick shuffle.
        // The shared Planner applies only real building coordinates under lock.
        if (!$missingFlinger && self::layoutTurn($id,$turnNumber) && time()-(int)($base['ai']['last_layout_at'] ?? 0)>=900) {
            $layout=self::layoutPlan($id,$base,time());
            if($layout!==null && self::attempt($id,'plannerApply',$layout)){
                ++$result['layouts_improved'];$lastAction='improving defensive coverage';
                $base=Game::loadBase($id);
            }
        }

        // Armor is an occasional paid investment, leaving most turns for growth.
        // The normal service owns eligibility and its timer; a planned tier never
        // becomes a direct state grant, and the next champion meal stays funded.
        $armorCadence=$profile==='guardian' ? 3 : 4;
        if (!$missingFlinger && Construction::status($base,time())['available']>0 && $turnNumber%$armorCadence===0) {
            $fortification=self::fortificationPlan($base,$profile,time());
            if ($fortification!==null && self::attempt($id,'fortify',$fortification)) {
                ++$result['fortified']; $lastAction='fortifying a backyard structure';
                $base=Game::loadBase($id);
            }
        }

        // Existing 0.1 bots receive no free structure during migration. They save
        // toward and construct their Flinger before spending on optional growth.
        $plan = $missingFlinger ? ['flinger'=>1] : self::buildPlan($profile, self::townhall($base));
        if (!$missingFlinger && Construction::status($base,time())['available']>0 && $result['fortified']===0 && $turnNumber % 3 === 0) {
            $strategicId = self::strategicUpgrade($base);
            if ($strategicId !== null && self::attempt($id, 'upgrade', ['id'=>$strategicId])) {
                ++$result['upgraded'];
                $lastAction = 'upgrading monster infrastructure';
                $base = Game::loadBase($id);
            }
        }
        if (Construction::status($base,time())['available']>0 && $result['upgraded'] === 0 && $result['fortified']===0) {
            foreach ($plan as $type=>$desiredCount) {
                if (($counts[$type] ?? 0) >= $desiredCount || !isset($catalog['buildings'][$type])) { continue; }
                $definition = $catalog['buildings'][$type];
                if (!self::affordable($base, $definition['cost'] ?? []) || (int)($definition['unlockLevel'] ?? 1) > self::townhall($base)) { continue; }
                if (isset($definition['requiresBuilding']) && self::livingLevel($base,$definition['requiresBuilding'])<1) { continue; }
                foreach (self::placements($id, $type, $counts[$type] ?? 0, $base, $definition) as [$x,$y]) {
                    if (self::attempt($id, 'build', ['type'=>$type,'x'=>$x,'y'=>$y])) {
                        ++$result['built'];
                        $lastAction = 'building '.($definition['name'] ?? $type);
                        $base = Game::loadBase($id);
                        $counts[$type] = ($counts[$type] ?? 0)+1;
                        break 2;
                    }
                }
            }
        }
        if (!$missingFlinger && Construction::status($base,time())['available']>0 && $result['built'] === 0 && $result['upgraded'] === 0 && $result['fortified']===0) {
            $buildings = $base['buildings'];
            usort($buildings, static function (array $a,array $b) use($profile): int {
                $priority = static fn(array $x): int =>
                    ($x['type']==='townhall' ? -10 : 0)+
                    (($profile==='guardian' && (in_array($x['type'], self::HOME_TOWERS, true) || $x['type'] === 'bunker')) ? -4 : 0)+
                    (($profile==='collector' && in_array($x['type'],['twig','pebble','putty','goo','storage'],true)) ? -4 : 0)+
                    (int)($x['level'] ?? 1);
                return $priority($a) <=> $priority($b);
            });
            foreach ($buildings as $building) {
                if ((int)($building['ready_at'] ?? 0)>time() || (int)($building['upgrade_to'] ?? 0)>0 || (int)($building['fortify_ready_at'] ?? 0)>time() || (int)($building['fortify_to'] ?? 0)>0) { continue; }
                if ($building['type']==='lab' && !empty($base['lab']['queue'])) { continue; }
                if ($building['type']==='siegelab' && !empty($base['siege']['research'])) { continue; }
                $definition = $catalog['buildings'][$building['type']] ?? [];
                if ((int)($building['level'] ?? 1)>=(int)($definition['maxLevel'] ?? 5)) { continue; }
                if (!self::affordable($base,Game::upgradeCost($building['type'],(int)$building['level']))) { continue; }
                if (self::attempt($id,'upgrade',['id'=>(int)$building['id']])) {
                    ++$result['upgraded']; $lastAction='upgrading '.($definition['name'] ?? $building['type']);
                    $base=Game::loadBase($id); break;
                }
            }
        }

        // Preserve funds for a missing Flinger. No direct state edit grants a bot
        // an unlock, garrison, training result, refund, or finished building.
        if (!$missingFlinger || isset($counts['flinger'])) {
            $champion=self::championPlan($base,$id,time());
            if($champion!==null && self::attempt($id,$champion['action'],$champion['input'])){
                $counter=match($champion['action']){'championRaise'=>'champions_raised','championThaw'=>'champions_thawed',default=>'champions_fed'};
                ++$result[$counter];
                $lastAction=match($champion['action']){'championRaise'=>'raising a champion','championThaw'=>'awakening a stored champion',default=>'feeding a champion'};
                $base=Game::loadBase($id);
            }
            if (empty($base['unlockQueue']) && self::livingLevel($base,'locker')>0) {
                $discoveryChoices=array_values(array_unique(array_merge(array_keys(self::championRecipe($base)),self::monsterChoices($profile,$seed+19))));
                foreach ($discoveryChoices as $type) {
                    $definition=$catalog['monsters'][$type];
                    if (self::unlocked($base,$type) || (int)($definition['unlockHall'] ?? $definition['unlockLevel'] ?? 1)>self::townhall($base)) { continue; }
                    if ((int)($definition['lockerLevel'] ?? 0)>self::livingLevel($base,'locker')) { continue; }
                    if (!self::affordable($base,$definition['unlockCost'] ?? [])) { continue; }
                    if (self::attempt($id,'unlock',['type'=>$type])) {
                        ++$result['unlocks']; $lastAction='unlocking '.$definition['name'];
                        $base=Game::loadBase($id); break;
                    }
                }
            }
            $garrison=self::garrisonPlan($base,$profile);
            if ($garrison!==null && self::attempt($id,'garrison',$garrison)) {
                $result['garrisoned']+=$garrison['quantity']; $lastAction='reinforcing a bunker';
                $base=Game::loadBase($id);
            }
            $juice=self::juicePlan($base,$profile,$seed);
            if ($juice!==null && self::attempt($id,'juice',$juice)) {
                $result['juiced']+=$juice['quantity']; $lastAction='recycling surplus monsters';
                $base=Game::loadBase($id);
            }
            $armyCount=array_sum(array_map('intval',$base['army'] ?? []));
            $queuedCount=array_sum(array_map(static fn(array $job): int=>(int)($job['quantity'] ?? 1),$base['queue'] ?? []));
            $food=self::championTrainingPlan($base);
            if($food!==null && self::attempt($id,'train',$food)){
                $result['trained']+=$food['quantity'];$lastAction='hatching food for a champion';
                $base=Game::loadBase($id);
            } elseif ($armyCount+$queuedCount<42) {
                foreach (self::trainingChoices($base,$profile,$seed) as $monster) {
                    $definition=$catalog['monsters'][$monster];
                    $definition=($definition['levels'][(int)($base['research'][$monster]??1)]??[])+$definition;
                    $quantity=$definition['size']>=80 ? 1 : min(3,42-$armyCount-$queuedCount);
                    $quantity=min($quantity,(int)floor(($base['resources']['goo'] ?? 0)/max(1,$definition['cost'])),(int)floor((Game::housingCapacity($base)-Game::housingUsed($base))/max(1,$definition['size'])));
                    if ($quantity>0 && self::attempt($id,'train',['type'=>$monster,'quantity'=>$quantity])) {
                        $result['trained']+=$quantity; $lastAction='hatching '.$definition['name'];
                        $base=Game::loadBase($id); break;
                    }
                }
            }
            // Care and food queues come first. Lab studies spend real putty;
            // Academy prerequisites preserve goo needed for the next meal.
            $lab=self::labResearchPlan($base,$profile,$seed);
            if ($lab!==null && self::attempt($id,'labResearch',$lab)) {
                ++$result['lab_researched']; $lastAction='studying '.Catalog::labAbilities()[$lab['type']]['name'];
                $base=Game::loadBase($id);
            }
            if ($turnNumber%5===0) {
                $research=self::academyResearchPlan($base,$profile,$seed+47);
                if ($research!==null && self::attempt($id,'research',$research)) {
                    ++$result['researched']; $lastAction='researching '.$research['type'];
                    $base=Game::loadBase($id);
                }
            }
        }

        // One purchase per mature bot turn. The normal service rechecks funds, storage,
        // building health, job limits and revision under the keeper's home lock.
        if (!$missingFlinger && self::townhall($base)>=4 && isset($counts['siegelab'])) {
            $order=self::siegeOrder($base,$id,time());
            if ($order && self::attempt($id,$order['action'],$order['input'])) {
                $research=$order['action']==='siegeResearch';
                ++$result[$research?'siege_researched':'siege_built'];
                $lastAction=($research?'studying ':'manufacturing ').SiegeWorkshop::DEVICES[$order['input']['type']][0];
                $base=Game::loadBase($id);
            }
        }

        // Network land has its own capacity and no income or reach. On an
        // occasional, identity-stable turn it gets the first management slot;
        // the remaining turns continue to prefer ordinary productive holdings.
        $holdings=class_exists('World') ? World::holdings($id) : [];
        $marker=World::home($id);
        if($marker&&Regions::versionForQ($marker['q'])===2&&OutpostYards::botTakeover($id)){
            $base=Game::loadBase($id);$holdings=World::holdings($id);$lastAction='claiming a regional outpost';
        }
        $fortifications=class_exists('World') ? World::fortifications($id) : [];
        $networkTurn=self::networkTurn($id,$turnNumber);
        $managementGroups=$networkTurn ? [$fortifications,$holdings] : [$holdings];
        foreach ($managementGroups as $sites) {
            if (!$sites) { continue; }
            $offset=$seed%count($sites);
            $ordered=array_merge(array_slice($sites,$offset),array_slice($sites,0,$offset));
            foreach (array_slice($ordered,0,6) as $site) {
                if(OutpostYards::is($site)){
                    $work=OutpostYards::botDevelop($id,$site,$base,$seed);
                    if($work!==null){
                        $counter=match($work){'repair'=>'sites_repaired','station'=>'sites_garrisoned',default=>'sites_upgraded'};
                        ++$result[$counter];$lastAction='developing a regional outpost';$base=Game::loadBase($id);break 2;
                    }
                    continue;
                }
                $stats=World::siteStats((string)$site['kind'],(int)$site['level']);
                $stats['repairCost']=World::repairCost($site);
                $management=self::siteManagementPlan($base,$site,$stats,$profile,time());
                if ($management!==null && self::worldAttempt($id,$management['action'],$management['input'])) {
                    $counter=match($management['action']){'worldRepair'=>'sites_repaired','worldGarrison'=>'sites_garrisoned',default=>'sites_upgraded'};
                    $result[$counter]+=$management['action']==='worldGarrison' ? $management['input']['quantity'] : 1;
                    if($site['kind']==='fortification'){
                        $networkCounter=match($management['action']){'worldRepair'=>'network_repaired','worldGarrison'=>'network_garrisoned',default=>'network_upgraded'};
                        $result[$networkCounter]=($result[$networkCounter] ?? 0)+($management['action']==='worldGarrison' ? $management['input']['quantity'] : 1);
                    }
                    $lastAction=match($management['action']){'worldRepair'=>'repairing a territory defense','worldGarrison'=>'stationing troops at a territory',default=>'upgrading an owned territory'};
                    $base=Game::loadBase($id);
                    break 2;
                }
            }
        }

        $result+=InfernoBots::economy($id,$turnNumber,$seed);
        $lastAttack=(int)($base['ai']['last_attack_at'] ?? 0);
        if($active<$settings['max_active_battles'] && time()-$lastAttack>=$settings['attack_cooldown']){
            $peer=InfernoBots::raid($id,$turnNumber,$seed,$settings,$busy);
            if($peer!==null){$result['attacks']=1;$result['inferno_attacks']=1;$result['target_id']=$peer;$lastAction='launching a native Inferno invasion';}
        }
        if(!$result['attacks']&&$holdings&&$seed%7===0&&$active<$settings['max_active_battles']&&time()-$lastAttack>=$settings['attack_cooldown']){
            $battle=OutpostYards::botRaid($id,$holdings,intdiv($seed,7)%100<$settings['human_target_percent']);
            if($battle!==null){
                ++$result['attacks'];if($battle['target']['kind']!=='home')++$result['territory_attacks'];
                $result['target_id']=(int)($battle['target']['ownerId']??0);$lastAction='launching troops from a regional outpost';
            }
        }
        $attackChance=match($profile){'raider'=>35,'guardian'=>12,'builder'=>15,default=>18};
        if (!$result['attacks'] && class_exists('BattleService') && $active<$settings['max_active_battles'] && self::raidReady($base) && time()-$lastAttack>=$settings['attack_cooldown'] && ($seed%100)<$attackChance) {
            // A full resource allowance does not close the separate network
            // campaign. A failed or empty network plan falls through normally.
            if(class_exists('World') && $networkTurn && self::livingLevel($base,'maproom')>0 && count($fortifications)<min(3,self::livingLevel($base,'maproom'))*6){
                $networkCandidates=World::candidates($id,24,$seed,'fortification');
                foreach(self::networkChoices($id,$base,$fortifications,$networkCandidates,$seed,$settings,$busy,time()) as $target){
                    try{
                        $battle=BattleService::botAttackSite($id,(int)$target['id'],self::championReserve($base));
                        ++$result['attacks'];++$result['territory_attacks'];$result['network_attacks']=1;
                        $result['target_id']=(int)($battle['target']['ownerId'] ?? $target['owner_id'] ?? 0);
                        $lastAction='invading '.$target['name'].' for network defense';
                        break;
                    }catch(PDOException $e){throw $e;}
                    catch(RuntimeException | InvalidArgumentException $e){
                        // Shared combat rechecks capacity, ownership and range.
                    }
                }
            }
            // Keep ordinary home invasions in the mix. A valid Map Room opens
            // territory campaigns; it never grants an army or bypasses range.
            if ($result['attacks']===0 && class_exists('World') && self::livingLevel($base,'maproom')>0 && count($holdings)<self::livingLevel($base,'maproom')*2 && (($seed>>13)%100)<65) {
                $candidates=World::candidates($id,24,$seed);
                foreach(self::territoryChoices($id,$base,$holdings,$candidates,$seed,$settings,$busy,time()) as $target) {
                    try {
                        $battle=BattleService::botAttackSite($id,(int)$target['id'],self::championReserve($base));
                        ++$result['attacks']; ++$result['territory_attacks'];
                        $result['target_id']=(int)($battle['target']['ownerId'] ?? $target['owner_id'] ?? 0);
                        $lastAction='invading '.$target['name'].' for territory';
                        break;
                    } catch(PDOException $e) { throw $e; }
                    catch(RuntimeException | InvalidArgumentException $e) {
                        // Range, ownership and busy state are checked again under locks.
                    }
                }
            }
            $targetId=$result['attacks']===0 ? self::findTarget($id,$base,$seed,$settings,$busy,$maxId) : null;
            if ($targetId!==null) {
                try {
                    BattleService::botAttack($id,$targetId,self::championReserve($base));
                    ++$result['attacks']; $result['target_id']=$targetId;
                    $lastAction='launching an invasion with the Flinger';
                } catch(PDOException $e) { throw $e; }
                catch(RuntimeException | InvalidArgumentException $e) {
                    // Target eligibility can change between candidate selection and locks.
                }
            }
        }
        if ($result['attacks']===0 && $turnNumber%7===0) {
            Mushrooms::ensure($base,$id,time());
            foreach ($base['mushrooms']['items'] as $item) {
                if ($item['readyAt']) { continue; }
                if (self::attempt($id,'mushroomPick',['id'=>$item['id']])) { $lastAction='picking a yard mushroom'; }
                break;
            }
        }
        self::remember($id,$profile,$turnNumber,$lastAction,$result['attacks']>0,$result['layouts_improved']>0);
        return $result;
    }

    /** Pure, bounded preference policy over already spatially filtered sites. */
    public static function territoryChoices(int $id,array $base,array $holdings,array $candidates,int $seed,array $settings,array $busy,int $now): array
    {
        $room=self::livingLevel($base,'maproom',$now);
        if($room<1 || count($holdings)>=min(3,$room)*2 || !self::raidReady($base,$now) || isset($busy[$id])){return [];}
        $humanAllowed=(($seed>>5)%100)<(int)($settings['human_target_percent'] ?? 15);
        $enemyFirst=(($seed>>9)%100)<45;
        $strongholdFirst=in_array(self::profile($id),['raider','guardian'],true) && (($seed>>17)%3)===0;
        $eligible=[];
        foreach(array_slice($candidates,0,48) as $site){
            $owner=(int)($site['owner_id'] ?? $site['owner']['id'] ?? 0);
            if (Truces::activeUntil($base, $owner, $now)) { continue; }
            if(!in_array($site['kind'] ?? '',['resource','stronghold'],true) || $owner===$id || !($site['reachable'] ?? false) || !empty($site['busy']) || ($owner>0 && isset($busy[$owner]))){continue;}
            if((int)($site['state']['shield_until'] ?? $site['shield_until'] ?? 0)>$now){continue;}
            if($owner>0 && empty($site['owner']['is_bot']) && !$humanAllowed){continue;}
            $eligible[]=$site;
        }
        usort($eligible,static function(array $a,array $b) use($seed,$enemyFirst,$strongholdFirst):int{
            $score=static function(array $site) use($seed,$enemyFirst,$strongholdFirst):array{
                $enemy=(int)($site['owner_id'] ?? $site['owner']['id'] ?? 0)>0;
                return [$enemy===$enemyFirst ? 0 : 1,($site['kind']==='stronghold')===$strongholdFirst ? 0 : 1,self::seed((int)$site['id'],$seed)];
            };
            return $score($a)<=>$score($b);
        });
        return array_slice($eligible,0,4);
    }

    /** Uses public costs and real army stock; execution remains in World::mutate. */
    public static function siteManagementPlan(array $base,array $site,array $stats,string $profile,int $now): ?array
    {
        $state=$site['state'] ?? [];
        if(!in_array($site['kind'] ?? '',['resource','stronghold','fortification'],true) || (int)($site['owner_id'] ?? 0)<1 || !empty($site['busy']) || (int)($state['ready_at'] ?? 0)>$now || (int)($state['repair_at'] ?? 0)>$now || !empty($state['upgrade_to'])){return null;}
        $budget=$base;
        if($site['kind']==='fortification'){
            foreach(self::networkHomeReserve($base,$profile,$now) as $resource=>$amount){$budget['resources'][$resource]=max(0,(float)($base['resources'][$resource] ?? 0)-$amount);}
        }
        $input=['siteId'=>(int)$site['id']];
        foreach($state['buildings'] ?? [] as $building){
            if((float)($building['hp'] ?? 0)<(float)($building['max_hp'] ?? 0)){
                return self::siteAffordable($budget,$stats['repairCost'] ?? []) ? ['action'=>'worldRepair','input'=>$input] : null;
            }
        }
        $capacity=max(0,(int)($stats['garrisonCapacity'] ?? 0));
        $used=0;$catalog=Catalog::monsters();
        foreach($state['garrison'] ?? [] as $bunker){foreach($bunker as $type=>$quantity){$used+=max(0,(int)$quantity)*(int)($catalog[$type]['size'] ?? 0);}}
        $target=(int)floor($capacity*($profile==='guardian' ? 1.0 : 0.75));
        $reserve=self::championReserve($base);
        foreach(['crabatron','projectx','ichi','eyera','octo','fang','fink','pokey','teratorn','dave','bolt','bandito','wormzer','brain','zafreeti'] as $type){
            $available=max(0,(int)($base['army'][$type] ?? 0)-(int)($reserve[$type] ?? 0));
            $quantity=min(3,$available,(int)floor(max(0,$target-$used)/max(1,(int)($catalog[$type]['size'] ?? 0))));
            while($quantity>0){
                $remaining=$base;$remaining['army'][$type]-=$quantity;
                // A reserved champion meal does not count as the raid reserve.
                foreach($reserve as $food=>$count){$remaining['army'][$food]=max(0,(int)($remaining['army'][$food] ?? 0)-$count);}
                if(self::armyReserve($remaining)>=80){return ['action'=>'worldGarrison','input'=>$input+['type'=>$type,'quantity'=>$quantity]];}
                --$quantity;
            }
        }
        if((int)$site['level']<(int)($stats['maxLevel'] ?? 1) && !empty($stats['upgradeCost']) && self::siteAffordable($budget,$stats['upgradeCost'])){
            return ['action'=>'worldUpgrade','input'=>$input];
        }
        return null;
    }

    /** Separate network turns never consume every ordinary land/home campaign. */
    public static function networkTurn(int $id,int $turn): bool
    {
        $cadence=self::profile($id)==='guardian' ? 3 : 4;
        return ($turn+self::seed($id,140))%$cadence===0;
    }

    /** Useful adjacent ownership, not Fortification density, selects conquests. */
    public static function networkChoices(int $id,array $base,array $fortifications,array $candidates,int $seed,array $settings,array $busy,int $now): array
    {
        $room=min(3,self::livingLevel($base,'maproom',$now));
        if($room<1 || count($fortifications)>=$room*6 || !self::raidReady($base,$now) || isset($busy[$id])){return [];}
        $humanAllowed=(($seed>>5)%100)<(int)($settings['human_target_percent'] ?? 15);
        $enemyFirst=self::profile($id)==='raider' || (self::profile($id)!=='guardian' && self::seed($id,104)%3===0);
        $eligible=[];
        foreach(array_slice($candidates,0,48) as $site){
            $owner=(int)($site['owner_id'] ?? $site['owner']['id'] ?? 0);
            if (Truces::activeUntil($base, $owner, $now)) { continue; }
            $state=$site['state'] ?? [];
            if(($site['kind'] ?? '')!=='fortification' || $owner===$id || !($site['reachable'] ?? false) || !empty($site['busy']) || ($owner>0 && isset($busy[$owner]))){continue;}
            if((int)($state['shield_until'] ?? $site['shield_until'] ?? 0)>$now || (int)($state['ready_at'] ?? $site['ready_at'] ?? 0)>$now || (int)($state['repair_at'] ?? $site['repair_at'] ?? 0)>$now || !empty($state['upgrade_to'])){continue;}
            if($owner>0 && empty($site['owner']['is_bot']) && !$humanAllowed){continue;}
            $ownBenefit=0;$enemyBenefit=0;$seen=[];
            foreach(array_slice($site['adjacentSites'] ?? [],0,6) as $parent){
                if(isset($seen[(int)$parent['id']]) || !in_array($parent['kind'] ?? '',['home','resource','stronghold'],true)){continue;}
                $seen[(int)$parent['id']]=true;
                if(World::distance((int)$site['q'],(int)$site['r'],(int)$parent['q'],(int)$parent['r'])!==1){continue;}
                $parentOwner=(int)($parent['owner_id'] ?? 0);$weight=$parent['kind']==='resource' ? 2 : 3;
                if($parentOwner===$id){$ownBenefit+=$weight;}
                elseif($owner>0 && $parentOwner===$owner){$enemyBenefit+=$weight;}
            }
            if($ownBenefit===0 && $enemyBenefit===0){continue;}
            $priority=($enemyFirst ? $enemyBenefit : $ownBenefit)*4+($enemyFirst ? $ownBenefit : $enemyBenefit);
            $eligible[]=['site'=>$site,'score'=>$priority];
        }
        // The same bot keeps its strategic preference and site tie-break across
        // restarts; the worker seed only chooses its bounded scouting window.
        usort($eligible,static fn(array $a,array $b):int=>[-$a['score'],self::seed((int)$a['site']['id'],$id)]<=>[-$b['score'],self::seed((int)$b['site']['id'],$id)]);
        return array_column(array_slice($eligible,0,4),'site');
    }

    /** Reserve one next home construction/armor investment before optional land work. */
    public static function networkHomeReserve(array $base,string $profile,int $now): array
    {
        $reserve=array_fill_keys(['twig','pebble','putty','goo'],0);$catalog=Catalog::buildings();
        $counts=array_count_values(array_column($base['buildings'] ?? [],'type'));
        foreach(self::buildPlan($profile, self::townhall($base)) as $type=>$desired){
            $definition=$catalog[$type] ?? [];
            if(($counts[$type] ?? 0)>=$desired || (int)($definition['unlockLevel'] ?? 1)>self::townhall($base)){continue;}
            foreach($definition['cost'] ?? [] as $resource=>$amount){$reserve[$resource]=max($reserve[$resource] ?? 0,(int)$amount);}
            break;
        }
        // Ask the existing armor planner which eligible investment comes next,
        // even while saving for it. This copy is only a budget calculation.
        $potential=$base;$potential['resources']=array_fill_keys(['twig','pebble','putty','goo'],PHP_INT_MAX);
        $armor=self::fortificationPlan($potential,$profile,$now);
        if($armor!==null){
            foreach($base['buildings'] as $building){
                if((int)$building['id']!==$armor['id']){continue;}
                foreach(Game::fortificationStatus($base,$building,$now,1.0)['next']['cost'] as $resource=>$amount){$reserve[$resource]=max($reserve[$resource] ?? 0,(int)$amount);}
                break;
            }
        }
        return $reserve;
    }

    private static function siteAffordable(array $base,array $cost): bool
    {
        return self::affordable($base,$cost) && (float)($base['resources']['goo'] ?? 0)-(float)($cost['goo'] ?? 0)>=self::championFoodGooReserve($base);
    }

    private static function worldAttempt(int $id,string $action,array $input): bool
    {
        try { World::mutate($id,$action,$input);return true; }
        catch(PDOException $e){throw $e;}
        catch(RuntimeException | InvalidArgumentException $e){return false;}
    }

    /** Pure hiring intent; every purchase still goes through Game::mutate. */
    public static function workerHirePlan(array $base,string $profile,int $now): ?array
    {
        if(self::livingLevel($base,'flinger',$now)<1){return null;}
        $status=Construction::status($base,$now);$hire=$status['nextHire'];
        $desired=match($profile){'builder','guardian'=>5,'collector'=>4,default=>3};
        if($status['capacity']>=$desired || !is_array($hire) || empty($hire['available'])){return null;}
        $reserve=self::networkHomeReserve($base,$profile,$now);
        $reserve['goo']=max($reserve['goo'],self::championFoodGooReserve($base),1000);
        foreach($hire['cost'] as $resource=>$cost){
            if((float)($base['resources'][$resource] ?? 0)-$cost<($reserve[$resource] ?? 0)){return null;}
        }
        return [];
    }

    /** Occasional identity-stable planning plus a persisted fifteen-minute cooldown. */
    public static function layoutTurn(int $id,int $turn): bool
    {
        return ($turn+self::seed($id,10))%11===0;
    }

    /**
     * Bounded, deterministic local improvement. Up to four idle towers consider
     * points beside two important structures and five ordinary safe placements.
     * Only the best meaningful improvement is sent to the authoritative Planner;
     * at most one real building moves, and a busy/invalid yard is left untouched.
     */
    public static function layoutPlan(int $id,array $base,int $now): ?array
    {
        if(!class_exists('Planner')){return null;}
        $status=Planner::status($base,$now);
        if(!$status['available'] || count($base['buildings'] ?? [])>100){return null;}
        try{$positions=Planner::validateLayout($base,$status['positions'],$now);}
        catch(RuntimeException | InvalidArgumentException $e){return null;}
        $profile=self::profile($id);$catalog=Catalog::buildings();
        $locked=array_fill_keys($status['lockedIds'],true);$towers=[];$targets=[];$indices=[];
        foreach($base['buildings'] as $index=>$building){
            $indices[(int)$building['id']]=$index;
            if(in_array($building['type'], self::HOME_TOWERS, true) && !isset($locked[(int)$building['id']]) && (int)$building['level']>0 && (float)$building['hp']>0){$towers[]=$building;}
            $weight=self::layoutImportance($building['type'],$profile);
            if($weight>=2){$targets[]=['building'=>$building,'weight'=>$weight];}
        }
        usort($towers,static fn(array $a,array $b):int=>self::seed((int)$a['id'],$id)<=>self::seed((int)$b['id'],$id));
        usort($targets,static fn(array $a,array $b):int=>[-$a['weight'],self::seed((int)$a['building']['id'],$id)]<=>[-$b['weight'],self::seed((int)$b['building']['id'],$id)]);
        $bestScore=self::layoutScore($base,$profile,$now);$originalScore=$bestScore;$best=null;
        foreach(array_slice($towers,0,4) as $tower){
            $size=(float)$catalog[$tower['type']]['size'];$points=[];
            $phase=self::seed($id,(int)$tower['id'])%8;
            foreach(array_slice($targets,0,2) as $target){
                $other=$target['building'];$gap=($size+(float)$catalog[$other['type']]['size'])/2+0.7;
                for($direction=0;$direction<4;$direction++){
                    $angle=($phase+$direction*2)*M_PI/4;
                    // Diagonal center spacing must also clear square footprints.
                    $radius=$gap/max(abs(cos($angle)),abs(sin($angle)));
                    $points[]=[round(((float)$other['x']+cos($angle)*$radius)*2)/2,round(((float)$other['y']+sin($angle)*$radius)*2)/2];
                }
            }
            $without=$base;$without['buildings']=array_values(array_filter($base['buildings'],static fn(array $b):bool=>(int)$b['id']!==(int)$tower['id']));
            $points=array_merge($points,self::placements($id,$tower['type'],(int)$tower['id'],$without,$catalog[$tower['type']]));
            foreach($points as [$x,$y]){
                if(!self::layoutPointClear($base,(int)$tower['id'],$tower['type'],$x,$y,$catalog)){continue;}
                $candidate=$base;$candidate['buildings'][$indices[(int)$tower['id']]]['x']=$x;$candidate['buildings'][$indices[(int)$tower['id']]]['y']=$y;
                $score=self::layoutScore($candidate,$profile,$now);
                if($score>$bestScore+0.00001){$bestScore=$score;$best=$candidate;}
            }
        }
        if($best===null || $bestScore-$originalScore<0.5){return null;}
        try{$positions=Planner::validateLayout($base,Planner::positions($best),$now);}
        catch(RuntimeException | InvalidArgumentException $e){return null;}
        return ['positions'=>$positions,'revision'=>$status['revision']];
    }

    /** Weighted protection rewards overlapping coverage, not movement by itself. */
    public static function layoutScore(array $base,string $profile,int $now): float
    {
        $catalog=Catalog::buildings();$towers=[];
        foreach($base['buildings'] ?? [] as $building){
            if(!in_array($building['type'], self::HOME_TOWERS, true) || (int)($building['level'] ?? 0)<1 || (float)($building['hp'] ?? 0)<=0 || (int)($building['ready_at'] ?? 0)>$now || (int)($building['fortify_ready_at'] ?? 0)>$now){continue;}
            $towers[]=$building;
        }
        $score=0.0;
        foreach($base['buildings'] ?? [] as $target){
            $weight=self::layoutImportance($target['type'],$profile);if($weight<=0){continue;}
            $coverage = ['ground'=>[], 'air'=>[]];
            foreach ($towers as $tower) {
                if ((int)$tower['id'] === (int)$target['id']) { continue; }
                $spec = $catalog[$tower['type']];
                $distance = hypot($tower['x'] - $target['x'], $tower['y'] - $target['y']);
                $levelIndex = min((int)$tower['level'], (int)$spec['maxLevel']) - 1;
                $range = $spec['ranges'][$levelIndex] ?? $spec['range'];
                $strength = max(0.0, min(1.0, 1.0 - ($distance - $range) / 6));
                foreach (['ground', 'air'] as $movement) {
                    if ($spec['targetMode'] === 'both' || $spec['targetMode'] === $movement) {
                        $coverage[$movement][] = $strength;
                    }
                }
            }
            // A field of air towers alone cannot cover a ground approach.
            foreach (['ground'=>0.65, 'air'=>0.35] as $movement => $share) {
                $strengths = $coverage[$movement];
                rsort($strengths, SORT_NUMERIC);
                $score += $weight * $share * (($strengths[0] ?? 0) + 0.45 * ($strengths[1] ?? 0));
            }
        }
        return round($score,6);
    }

    private static function layoutImportance(string $type,string $profile): float
    {
        if($type==='townhall'){return $profile==='guardian'?7.0:5.0;}
        if(in_array($type,['storage','twig','pebble','putty','goo'],true)){return $profile==='collector'?4.0:1.5;}
        if(in_array($type,['flinger','hatchery','championcage','housing'],true)){return $profile==='raider'?4.0:2.0;}
        if(in_array($type,['lab','academy','maproom','planner'],true)){return $profile==='builder'?3.0:1.5;}
        return in_array($type, self::HOME_TOWERS, true) || $type === 'bunker' ? 1.0 : 0.0;
    }

    private static function layoutPointClear(array $base,int $skip,string $type,float $x,float $y,array $catalog): bool
    {
        $size=(float)$catalog[$type]['size'];$half=$size/2;
        if(!YardSpace::contains($base,$x,$y,1+$half)){return false;}
        foreach($base['buildings'] as $building){
            if((int)$building['id']===$skip){continue;}
            $gap=($size+(float)$catalog[$building['type']]['size'])/2+0.15;
            if(abs($x-(float)$building['x'])<$gap && abs($y-(float)$building['y'])<$gap){return false;}
        }
        return true;
    }

    private static function livingLevel(array $base,string $type,?int $now = null): int
    {
        $now ??= time();
        $level=0;
        foreach($base['buildings'] ?? [] as $building){
            if($building['type']===$type && (float)($building['hp'] ?? 0)>0 && (int)($building['ready_at'] ?? 0)<=$now){
                $level=max($level,(int)($building['level'] ?? 0));
            }
        }
        return $level;
    }

    private static function unlocked(array $base,string $type): bool
    {
        return in_array($type,$base['unlocked'] ?? ['pokey','octo','fink','ichi','bandito','dave'],true);
    }

    private static function strategicUpgrade(array $base): ?int
    {
        // Champions are a separate progression goal even when every species
        // available at the current Town Hall has already been discovered.
        $wantedLocker=1; $wantedTownhall=max(self::townhall($base),min(4,self::townhall($base)+1));
        foreach(Catalog::homeMonsters() as $type=>$definition){
            if(self::unlocked($base,$type)){continue;}
            if((int)($definition['unlockHall'] ?? $definition['unlockLevel'] ?? 1)<=self::townhall($base)){
                $wantedLocker=max($wantedLocker,(int)($definition['lockerLevel'] ?? 1));
            } else {
                $wantedTownhall=max($wantedTownhall,min(self::townhall($base)+1,(int)($definition['unlockHall'] ?? $definition['unlockLevel'])));
            }
        }
        $types=[];
        $hall=self::townhall($base);
        if($hall>=5 && $hall<TownHall::MAX_LEVEL){
            $wantedTownhall=$hall+1;
            if(Game::capacity($base)<max(Game::upgradeCost('townhall',$hall)))$types[]='storage';
        }
        $foodSpace=0;
        foreach(self::championRecipe($base) as $type=>$quantity){$foodSpace+=(int)$quantity*(int)(Catalog::monsters()[$type]['size'] ?? 0);}
        if($foodSpace>0 && Game::housingCapacity($base)<$foodSpace+80){$types[]='housing';}
        if(self::livingLevel($base,'locker')<$wantedLocker){$types[]='locker';}
        if(self::townhall($base)<$wantedTownhall){$types[]='townhall';}
        if(self::livingLevel($base,'flinger')<min(2,self::townhall($base))){$types[]='flinger';}
        if(self::livingLevel($base,'maproom')<min(3,self::townhall($base))){$types[]='maproom';}
        // Every Lab tier needs an Academy level that can teach the matching
        // species prerequisite. Construction itself still uses normal plans.
        $wantedLab=1;
        foreach(Catalog::labAbilities() as $type=>$ability){
            if(self::unlocked($base,$type)){$wantedLab=max($wantedLab,min(3,(int)($base['lab']['levels'][$type] ?? 0)+1));}
        }
        if(self::livingLevel($base,'academy')<min($wantedLab,self::townhall($base))){$types[]='academy';}
        if(empty($base['lab']['queue']) && self::livingLevel($base,'lab')<min($wantedLab,self::townhall($base))){$types[]='lab';}
        if(self::livingLevel($base,'catapult')<min(4,self::townhall($base))){$types[]='catapult';}
        if (self::townhall($base)>=5 && self::livingLevel($base,'railgun')>0) { $types[]='railgun'; }
        $deviceLevel=max([0,...array_values($base['siege']['levels']??[])]);
        $labLevel=self::livingLevel($base,'siegelab');
        if (self::livingLevel($base,'siegefactory')>0 && empty($base['siege']['research']) && $labLevel>0 && $labLevel<10 && $deviceLevel>=$labLevel) {
            $types[]='siegelab';
        }
        foreach($types as $type){
            foreach($base['buildings'] as $building){
                if($building['type']!==$type || (int)$building['level']<1 || (int)($building['ready_at'] ?? 0)>time() || (int)($building['fortify_ready_at'] ?? 0)>time() || !empty($building['fortify_to']) || (float)$building['hp']<(float)$building['max_hp']){continue;}
                if((int)$building['level']>=(int)Catalog::buildings()[$type]['maxLevel']){continue;}
                if (in_array($type,['siegelab','railgun'],true)) {
                    if (Catalog::buildings()[$type]['levels'][(int)$building['level']+1]['townHall']>self::townhall($base)) { continue; }
                } elseif($type!=='townhall' && (int)$building['level']>=self::townhall($base)){continue;}
                if(self::affordable($base,Game::upgradeCost($type,(int)$building['level']))){return (int)$building['id'];}
            }
        }
        return null;
    }

    /** Pure armor choice. The service rechecks the paid action under its base lock. */
    public static function fortificationPlan(array $base,string $profile,int $now): ?array
    {
        if(self::livingLevel($base,'flinger',$now)<1 || Construction::status($base,$now)['available']<1){return null;}
        $catalog=Catalog::buildings();$eligible=[];
        foreach($base['buildings'] ?? [] as $building){
            $type=(string)($building['type'] ?? '');$spec=$catalog[$type] ?? [];
            if(empty($spec['canFortify']) || !empty($spec['siteOnly']) || (int)($building['level'] ?? 0)<1){continue;}
            if((float)($building['hp'] ?? 0)<=0 || (float)$building['hp']<(float)($building['max_hp'] ?? 0)){continue;}
            if((int)($building['ready_at'] ?? 0)>$now || !empty($building['upgrade_to']) || (int)($building['fortify_ready_at'] ?? 0)>$now || !empty($building['fortify_to'])){continue;}
            $tier=max(0,(int)($building['fortification'] ?? 0))+1;
            if($tier>4){continue;}
            $status=Game::fortificationStatus($base,$building,$now,1.0);
            if(empty($status['available']) || !self::siteAffordable($base,$status['next']['cost'])){continue;}
            // Spread the first protection layer before deepening it. Within a
            // tier defend the central objective, then towers, then the bank.
            $priority=$type==='townhall' ? 0 : ($type==='storage' ? 2 : 1);
            if($profile==='collector' && $type==='storage'){$priority=1;}
            $eligible[]=['id'=>(int)$building['id'],'tier'=>$tier,'priority'=>$priority];
        }
        usort($eligible,static fn(array $a,array $b):int=>[$a['tier'],$a['priority'],$a['id']]<=>[$b['tier'],$b['priority'],$b['id']]);
        return $eligible ? ['id'=>$eligible[0]['id']] : null;
    }

    /** Each saved bot ID keeps its own preferred champion across worker restarts. */
    public static function championChoice(int $id): string
    {
        return ['gorgo','drull','fomor'][self::seed($id,3003)%3];
    }

    /** The next meal is retained even during its cooldown, never invented. */
    public static function championRecipe(array $base): array
    {
        $type=$base['activeChampion'] ?? null;
        $champion=is_string($type)?($base['champions'][$type] ?? null):null;
        if(!is_array($champion) || !empty($champion['frozen'])){return [];}
        $definition=Catalog::champions()[$type] ?? null;
        if(!$definition){return [];}
        $level=(int)($champion['level'] ?? 1);
        return Game::championRecipe($type,$level,(int)($champion['bonus_feeds'] ?? 0));
    }

    /** Owned food is excluded from automated deployment and bunker transfers. */
    public static function championReserve(array $base): array
    {
        $reserve=[];
        foreach(self::championRecipe($base) as $type=>$quantity){
            $owned=max(0,(int)($base['army'][$type] ?? 0));
            if($owned>0){$reserve[$type]=min($owned,(int)$quantity);}
        }
        return $reserve;
    }

    /** A normal mutation plan: raising has the same source-derived rules as players. */
    public static function championPlan(array $base,int $id,int $now): ?array
    {
        if(self::livingLevel($base,'championcage',$now)<1){return null;}
        $active=$base['activeChampion'] ?? null;
        if(!is_string($active) || !isset($base['champions'][$active])){
            $preferred=self::championChoice($id);
            // Resume an existing frozen champion before acquiring another. This
            // preserves its evolution rather than replacing it with a fresh one.
            $roster=$base['champions'] ?? [];
            $types=array_values(array_unique(array_merge([$preferred],array_keys($roster))));
            foreach($types as $type){
                if(!empty($roster[$type]['frozen']) && self::livingLevel($base,'championchamber',$now)>0){
                    foreach($base['buildings'] as $building){
                        if($building['type']==='championchamber' && $building['hp']>=$building['max_hp'] && ($building['ready_at'] ?? 0)<=$now){
                            return ['action'=>'championThaw','input'=>['type'=>$type]];
                        }
                    }
                }
            }
            if(!$roster && self::townhall($base)>=4){return ['action'=>'championRaise','input'=>['type'=>$preferred]];}
            return null;
        }
        $champion=$base['champions'][$active];
        if(!empty($champion['frozen']) || !empty($champion['battle_id']) || (int)($champion['next_feed_at'] ?? 0)>$now){return null;}
        $recipe=self::championRecipe($base);
        if(!$recipe){return null;}
        foreach($recipe as $type=>$quantity){if((int)($base['army'][$type] ?? 0)<$quantity){return null;}}
        return ['action'=>'championFeed','input'=>[]];
    }

    /** Train recipe deficits before variety; queues already paid for count once. */
    public static function championTrainingPlan(array $base): ?array
    {
        if(self::livingLevel($base,'hatchery')<1 || self::livingLevel($base,'championcage')<1){return null;}
        $counts=$base['army'] ?? [];
        foreach($base['queue'] ?? [] as $job){$counts[$job['type']]=($counts[$job['type']] ?? 0)+(int)($job['quantity'] ?? 1);}
        $free=max(0,Game::housingCapacity($base)-Game::housingUsed($base));
        foreach(self::championRecipe($base) as $type=>$required){
            $definition=Catalog::monsters()[$type] ?? null;
            if(!$definition || !self::unlocked($base,$type) || (int)$definition['unlockLevel']>self::townhall($base)){continue;}
            $missing=max(0,(int)$required-(int)($counts[$type] ?? 0));
            $quantity=min(6,$missing,(int)floor($free/max(1,(int)$definition['size'])),(int)floor(($base['resources']['goo'] ?? 0)/max(1,(int)$definition['cost'])));
            if($quantity>0){return ['type'=>$type,'quantity'=>$quantity];}
        }
        return null;
    }

    /** A species' earned power follows it into raids and bunker defense. */
    public static function labResearchPlan(array $base,string $profile,int $seed): ?array
    {
        $lab=self::livingLevel($base,'lab');
        if($lab<1 || !empty($base['lab']['queue'])){return null;}
        foreach(self::labChoices($base,$profile,$seed) as $type){
            $next=(int)($base['lab']['levels'][$type] ?? 0)+1;
            if($next>3){continue;}
            $study=Catalog::labStats($type,$next);
            if($lab<(int)$study['requiredLabLevel'] || (int)($base['research'][$type] ?? 1)<(int)$study['requiredMonsterLevel']){continue;}
            if(self::affordable($base,$study['cost'])){return ['type'=>$type];}
        }
        return null;
    }

    /** Prefer useful unfinished Lab prerequisites before ordinary stat growth. */
    public static function academyResearchPlan(array $base,string $profile,int $seed): ?array
    {
        $academy=self::livingLevel($base,'academy');
        if($academy<1 || !empty($base['researchQueue'])){return null;}
        $priority=[];
        if(self::livingLevel($base,'lab')>0){
            foreach(self::labChoices($base,$profile,$seed) as $type){
                $next=(int)($base['lab']['levels'][$type] ?? 0)+1;
                if($next<=3 && (int)($base['research'][$type] ?? 1)<(int)Catalog::labStats($type,$next)['requiredMonsterLevel']){$priority[]=$type;}
            }
        }
        $choices=array_values(array_unique(array_merge($priority,self::trainingChoices($base,$profile,$seed))));
        $reservedGoo=self::championFoodGooReserve($base);
        foreach($choices as $type){
            $spec=Catalog::monsters()[$type] ?? null;$level=(int)($base['research'][$type] ?? 1);
            if(!$spec || !self::unlocked($base,$type) || (int)$spec['unlockLevel']>self::townhall($base) || $level>=6 || $academy<$level){continue;}
            $cost=$spec['levels'][$level]['nextStudy']['cost']??['goo'=>(int)$spec['cost']*4*$level,'putty'=>300*$level];
            if(self::affordable($base,$cost) && (int)($base['resources']['goo'] ?? 0)-$cost['goo'] >= $reservedGoo){return ['type'=>$type];}
        }
        return null;
    }

    /** Keep enough unspent goo for missing meal ingredients not already queued. */
    public static function championFoodGooReserve(array $base): int
    {
        $counts=$base['army'] ?? [];$cost=0;
        foreach($base['queue'] ?? [] as $job){$counts[$job['type']]=($counts[$job['type']] ?? 0)+(int)($job['quantity'] ?? 1);}
        foreach(self::championRecipe($base) as $type=>$quantity){
            $cost+=max(0,(int)$quantity-(int)($counts[$type] ?? 0))*(int)(Catalog::monsters()[$type]['cost'] ?? 0);
        }
        return $cost;
    }

    /** Spread first-tier studies before deepening powers, favoring owned troops. */
    private static function labChoices(array $base,string $profile,int $seed): array
    {
        $choices=array_values(array_filter(self::monsterChoices($profile,$seed),static fn(string $type):bool=>isset(Catalog::labAbilities()[$type]) && self::unlocked($base,$type) && (int)Catalog::monsters()[$type]['unlockLevel']<=self::townhall($base)));
        $counts=$base['army'] ?? [];
        foreach($base['queue'] ?? [] as $job){$counts[$job['type']]=($counts[$job['type']] ?? 0)+(int)($job['quantity'] ?? 1);}
        foreach($base['garrison'] ?? [] as $garrison){foreach($garrison as $type=>$quantity){$counts[$type]=($counts[$type] ?? 0)+(int)$quantity;}}
        usort($choices,static fn(string $a,string $b):int=>(int)($base['lab']['levels'][$a] ?? 0)<=>(int)($base['lab']['levels'][$b] ?? 0) ?: (int)($counts[$b] ?? 0)<=>(int)($counts[$a] ?? 0));
        return $choices;
    }

    /** True for real deployable armies; an injured champion waits for recovery. */
    public static function raidReady(array $base, ?int $now = null): bool
    {
        $now ??= time();
        $level=self::livingLevel($base,'flinger',$now);
        if($level<1){return false;}
        $flinger = Flinger::loadout($level);
        if(KrallenHunt::ready($base,$now)!==null){return true;}
        $space=0; $offensive=false;$reserve=self::championReserve($base);
        foreach($base['army'] ?? [] as $type=>$quantity){
            $definition=Catalog::monsters()[$type] ?? null;
            $quantity=max(0,(int)$quantity-(int)($reserve[$type] ?? 0));
            if (!$definition || $quantity < 1 || ($flinger['weights'][$type] ?? $definition['size']) > $flinger['capacity']) { continue; }
            $space+=(int)$quantity*(int)$definition['size'];
            if(($definition['ability'] ?? '')!=='heal' && (float)$definition['damage']>0){$offensive=true;}
        }
        $type=$base['activeChampion'] ?? null;
        $champion=is_string($type)?($base['champions'][$type] ?? null):null;
        if(is_array($champion) && empty($champion['frozen']) && self::livingLevel($base,'championcage',$now)>0){
            $stats=Catalog::championStats($type,(int)$champion['level'],(int)($champion['bonus_feeds'] ?? 0));
            if(!empty($champion['battle_id']) || (float)$champion['hp']<(float)$stats['hp']*0.65){return false;}
            // Fomor strengthens a swarm; Gorgo and Drull can lead solo raids.
            if($type!=='fomor'){return true;}
        }
        return $space>=80 && $offensive;
    }

    /** Favor underrepresented unlocked species while preserving profile tie-breaks. */
    public static function trainingChoices(array $base,string $profile,int $seed): array
    {
        $choices=array_values(array_filter(self::monsterChoices($profile,$seed),static fn(string $type): bool=>self::unlocked($base,$type) && (int)(Catalog::monsters()[$type]['unlockLevel'] ?? 1)<=self::townhall($base)));
        $counts=$base['army'] ?? [];
        foreach($base['queue'] ?? [] as $job){$counts[$job['type']]=($counts[$job['type']] ?? 0)+(int)($job['quantity'] ?? 1);}
        $recipe=self::championRecipe($base);
        usort($choices,static function(string $a,string $b) use($counts,$recipe):int{
            $aMissing=max(0,(int)($recipe[$a] ?? 0)-(int)($counts[$a] ?? 0));
            $bMissing=max(0,(int)($recipe[$b] ?? 0)-(int)($counts[$b] ?? 0));
            return ($bMissing>0?1:0)<=>($aMissing>0?1:0) ?: (int)($counts[$a] ?? 0)<=>(int)($counts[$b] ?? 0);
        });
        return $choices;
    }

    /** Transfer only owned troops, preserving at least80 housing of offensive reserve. */
    public static function garrisonPlan(array $base,string $profile): ?array
    {
        $catalog=Catalog::monsters();
        $preferences=['crabatron','projectx','ichi','eyera','zafreeti','slimeattikus','vorg','rezghul','octo','fang','fink','pokey','teratorn','dave','bolt','bandito','wormzer','brain'];
        $reserve=self::championReserve($base);
        foreach($base['buildings'] ?? [] as $building){
            if($building['type']!=='bunker' || $building['level']<1 || $building['hp']<=0 || ($building['ready_at'] ?? 0)>time()){continue;}
            $capacity=(int)(Catalog::buildings()['bunker']['capacity'] ?? 100)*(int)$building['level'];
            $used=0;
            foreach(($base['garrison'][(string)$building['id']] ?? []) as $type=>$qty){$used+=(int)$qty*(int)($catalog[$type]['size'] ?? 0);}
            $target=(int)floor($capacity*($profile==='guardian'?1.0:0.75));
            foreach($preferences as $type){
                $available=max(0,(int)($base['army'][$type] ?? 0)-(int)($reserve[$type] ?? 0));
                if(!isset($catalog[$type]) || $available<1){continue;}
                $quantity=min(3,$available,(int)floor(max(0,$target-$used)/max(1,$catalog[$type]['size'])));
                while($quantity>0){
                    $remaining=$base; $remaining['army'][$type]-=$quantity;
                    foreach($reserve as $food=>$count){$remaining['army'][$food]=max(0,(int)($remaining['army'][$food] ?? 0)-$count);}
                    if(self::armyReserve($remaining)>=80){return ['id'=>(int)$building['id'],'type'=>$type,'quantity'=>$quantity];}
                    --$quantity;
                }
            }
        }
        return null;
    }

    private static function armyReserve(array $base): int
    {
        $space=0;
        foreach($base['army'] ?? [] as $type=>$quantity){
            $definition=Catalog::monsters()[$type] ?? null;
            if($definition && ($definition['ability'] ?? '')!=='heal' && ($definition['damage'] ?? 0)>0){$space+=(int)$quantity*(int)$definition['size'];}
        }
        return $space;
    }

    /** Recycle a small excess only when it makes room for a missing unlocked species. */
    public static function juicePlan(array $base,string $profile,int $seed): ?array
    {
        if(self::livingLevel($base,'juicer')<1){return null;}
        $missing=false;$smallest=PHP_INT_MAX;$counts=$base['army'] ?? [];
        foreach($base['queue'] ?? [] as $job){$counts[$job['type']]=($counts[$job['type']] ?? 0)+(int)($job['quantity'] ?? 1);}
        $recipe=self::championRecipe($base);$reserve=self::championReserve($base);
        foreach($recipe as $type=>$quantity){
            if(self::unlocked($base,$type) && ($counts[$type] ?? 0)<$quantity){$missing=true;$smallest=min($smallest,(int)Catalog::monsters()[$type]['size']);}
        }
        $feeding=$missing;
        foreach(self::trainingChoices($base,$profile,$seed) as $type){
            if(!$feeding && ($counts[$type] ?? 0)===0){$missing=true;$smallest=min($smallest,(int)Catalog::monsters()[$type]['size']);}
        }
        $free=Game::housingCapacity($base)-Game::housingUsed($base);
        if(!$missing || $free>=$smallest){return null;}
        $candidates=$feeding?array_keys(Catalog::homeMonsters()):['pokey','octo','fink','bandito'];
        usort($candidates,static fn(string $a,string $b):int=>(int)Catalog::monsters()[$a]['cost']<=>(int)Catalog::monsters()[$b]['cost']);
        foreach($candidates as $type){
            $owned=(int)($base['army'][$type] ?? 0);
            $protected=max((int)($reserve[$type] ?? 0),$feeding?0:6);
            if($owned<=max($protected,$feeding?0:8)){continue;}
            $quantity=max(1,(int)ceil(($smallest-$free)/max(1,(int)Catalog::monsters()[$type]['size'])));
            if($quantity>8 || $owned-$quantity<$protected){continue;}
            $remaining=$base; $remaining['army'][$type]-=$quantity;
            foreach($reserve as $food=>$count){$remaining['army'][$food]=max(0,(int)($remaining['army'][$food] ?? 0)-$count);}
            if(self::armyReserve($remaining)>=80){return ['type'=>$type,'quantity'=>$quantity];}
        }
        return null;
    }

    /** Catalog adapters are intentionally tiny; all balance lives in Catalog. */
    private static function catalog(): array
    {
        return ['buildings' => Catalog::buildings(), 'monsters' => Catalog::monsters()];
    }

    private static function affordable(array $base, array $cost): bool
    {
        foreach ($cost as $resource => $amount) {
            if (($base['resources'][$resource] ?? 0) < $amount) {
                return false;
            }
        }
        return true;
    }

    private static function attempt(int $id, string $action, array $input, ?array &$response = null): bool
    {
        try {
            $response=Game::mutate($id, $action, $input);
            return true;
        } catch (PDOException $e) {
            throw $e;
        } catch (RuntimeException | InvalidArgumentException $e) {
            return false;
        }
    }

    private static function remember(int $id, string $profile, int $turn, string $action, bool $attacked, bool $planned=false): void
    {
        Db::transaction(static function () use ($id, $profile, $turn, $action, $attacked, $planned): void {
            $base = Game::loadBase($id, true);
            $base['ai'] = array_merge($base['ai'] ?? [], [
                'profile' => $profile, 'turns' => $turn,
                'last_action' => $action, 'last_turn_at' => time(),
            ]);
            if ($attacked) {
                $base['ai']['last_attack_at'] = time();
            }
            if ($planned) {
                $base['ai']['last_layout_at'] = time();
                $base['ai']['last_layout_turn'] = $turn;
            }
            Game::saveBase($id, $base);
        });
    }

    private static function findTarget(int $id, array $base, int $seed, array $settings, array $busy, int $maxId): ?int
    {
        $db = Db::pdo();
        $humanFirst = (($seed >> 5) % 100) < $settings['human_target_percent'];
        $groups = $humanFirst ? [0, 1] : [1];
        $pivot = 1 + ($seed % max(1, $maxId));
        $clock = WorldActivity::clock();
        foreach (self::targetRows($db,$id,$humanFirst,$pivot,$groups) as $row) {
            $isBot=(bool)$row['is_bot'];
            $targetId = (int)$row['id'];
            if (isset($busy[$targetId]) || Truces::activeUntil($base, $targetId, time())) {
                continue;
            }
            $target = json_decode((string)$row['state_json'], true);
            if (!is_array($target)) {
                continue;
            }
            if ($isBot) { WorldActivity::shiftBase($target, $clock); }
            if ((int)($target['shield_until'] ?? 0) > time()) {
                continue;
            }
            // Do not repeatedly farm newly registered or far weaker players.
            if (abs(self::townhall($base) - self::townhall($target)) > 2) {
                continue;
            }
            if (count(array_filter($target['buildings'] ?? [], [Catalog::class, 'visibleToRivals'])) < 3) {
                continue;
            }
            return $targetId;
        }
        return null;
    }

    private static function targetRows(PDO $db,int $id,bool $humanFirst,int $pivot,array $groups): Generator
    {
        yield from AllianceWars::enemyHomes($db,$id,$humanFirst,$pivot,time());
        foreach ($groups as $isBot) {
            $stmt = $db->prepare('SELECT u.id, u.is_bot, b.state_json FROM users u JOIN bases b ON b.user_id = u.id WHERE u.is_bot = ? AND u.id <> ? AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(b.state_json, \'$.shield_until\')) AS UNSIGNED), 0) <= ? ORDER BY (u.id >= ?) DESC, u.id LIMIT 16');
            $stmt->execute([$isBot, $id, time(), $pivot]);
            yield from $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    public static function profile(int $id): string
    {
        return self::PROFILES[self::seed($id, 731) % count(self::PROFILES)];
    }

    public static function seed(int $id, int $turn): int
    {
        return (int)hexdec(substr(hash('sha256', $id . ':' . $turn), 0, 7));
    }

    public static function townhall(array $base): int
    {
        $level = 1;
        foreach ($base['buildings'] ?? [] as $building) {
            if ($building['type'] === 'townhall') {
                $level = max($level, (int)($building['level'] ?? 1));
            }
        }
        return $level;
    }

    public static function siegeDevice(int $id): string
    {
        return match(self::profile($id)) {
            'raider'=>'decoy','guardian'=>'jars','collector'=>'vacuum',
            default=>['decoy','vacuum','jars'][intdiv($id,4)%3],
        };
    }

    public static function siegeOrder(array $base,int $id,int $now): ?array
    {
        if (self::townhall($base)<4 || self::livingLevel($base,'flinger',$now)<1 || self::livingLevel($base,'siegelab',$now)<1) {
            return null;
        }
        $type=self::siegeDevice($id);
        $options=SiegeWorkshop::view($base,$now,1);
        $device=$options['devices'][$type];
        foreach (['build'=>'siegeBuild','research'=>'siegeResearch'] as $lane=>$action) {
            if ($device[$lane]['reason']!=='') { continue; }
            if ($lane==='research' && $device['level']>0 && self::livingLevel($base,'siegefactory',$now)<1) { continue; }
            return ['action'=>$action,'input'=>['type'=>$type,'revision'=>$options['revision'][$lane]]];
        }
        return null;
    }

    public static function buildPlan(string $profile, ?int $townHall = null): array
    {
        $essential=['flinger'=>1,'locker'=>1,'maproom'=>1,'championcage'=>1,'planner'=>1];
        if ($townHall===null || $townHall>=5) { $essential+=['railgun'=>$profile==='guardian'?2:1]; }
        if ($townHall===null || $townHall>=4) { $essential+=['siegelab'=>1,'siegefactory'=>1]; }
        $common=['twig'=>2,'pebble'=>2,'goo'=>2,'housing'=>2,'storage'=>2,'hatchery'=>1,'championchamber'=>1,'sniper'=>2,'putty'=>1,'bunker'=>1,'juicer'=>1,'cannon'=>2,'academy'=>1,'lab'=>1,'catapult'=>1,'laser'=>1,'tesla'=>1,'aerial'=>1,'heavytrap'=>2,'trap'=>4,'wall'=>16];
        $plan = $essential + match($profile){
            'raider'=>['goo'=>3,'housing'=>3,'hatchery'=>1,'bunker'=>1,'juicer'=>1]+$common,
            'guardian'=>['bunker'=>2,'sniper'=>3,'laser'=>2,'cannon'=>3,'aerial'=>2,'heavytrap'=>4,'trap'=>8,'wall'=>20]+$common,
            'collector'=>['twig'=>3,'pebble'=>3,'putty'=>2,'goo'=>3,'storage'=>3,'juicer'=>1]+$common,
            default=>$common,
        };
        if($townHall!==null && $townHall>=6){
            $plan['storage']=max($plan['storage'],3);$plan['twig']=max($plan['twig'],4);$plan['pebble']=max($plan['pebble'],4);
            if($profile==='guardian')$plan['railgun']=3;
        }
        if ($townHall !== null) {
            $catalog = Catalog::buildings();
            foreach (['aerial', 'heavytrap'] as $type) {
                $limit=TownHall::quantity($catalog[$type],$townHall);
                $plan[$type] = min($plan[$type], $limit);
            }
        }
        return $plan;
    }

    public static function monsterChoices(string $profile,int $seed): array
    {
        $pool=match($profile){
            'raider'=>['fink','bandito','bolt','wormzer','fang','pokey','teratorn','ichi','octo','dave','projectx','zafreeti','eyera','brain','crabatron'],
            'guardian'=>['crabatron','ichi','eyera','octo','zafreeti','fang','pokey','dave','projectx','teratorn','fink','bolt','bandito','wormzer','brain'],
            'collector'=>['pokey','brain','octo','bandito','bolt','eyera','fink','ichi','wormzer','fang','zafreeti','teratorn','projectx','dave','crabatron'],
            default=>['pokey','fink','bolt','octo','eyera','ichi','fang','bandito','wormzer','dave','projectx','teratorn','zafreeti','brain','crabatron'],
        };
        $pool=array_values(array_filter($pool,static fn(string $type): bool=>isset(Catalog::monsters()[$type])));
        if(!$pool){return [];}
        $offset=$seed%count($pool);
        return array_merge(array_slice($pool,$offset),array_slice($pool,0,$offset),['slimeattikus','vorg','rezghul']);
    }

    /** Deterministic, distinct layouts with clear paths and per-type zoning. */
    public static function placements(int $id, string $type, int $ordinal, array $base, array $definition): array
    {
        $seed = self::seed($id, 937);
        $rotation = ($seed % 4) * M_PI_2;
        $size = (float)($definition['size'] ?? 2);
        $candidates = [];
        $definitions = Catalog::buildings();
        $stretchX = 0.88 + (($seed >> 8) % 9) * 0.03;
        $stretchY = 0.88 + (($seed >> 12) % 9) * 0.03;
        $centerX = 16 + ((($seed >> 17) % 5) - 2) * 0.25;
        $centerY = 16 + ((($seed >> 20) % 5) - 2) * 0.25;
        if ($type === 'wall') {
            // Short interrupted outer sections rather than a sealed gridlock ring.
            for ($i = 0; $i < 36; ++$i) {
                $k = ($i + $ordinal * 3 + ($seed % 7)) % 40;
                if ($k % 10 === 0 || $k % 10 === 9) {
                    continue;
                }
                $side = intdiv($k, 10);
                $offset = 7 + ($k % 10) * 2;
                [$x, $y] = match ($side) {0 => [$offset, 7], 1 => [25, $offset], 2 => [32 - $offset, 25], default => [7, 32 - $offset]};
                $candidates[] = [(float)$x, (float)$y];
            }
        } else {
            $defense = in_array($type, self::HOME_TOWERS, true) || in_array($type, ['bunker', 'trap', 'championcage'], true);
            // Place pressure plates along approaches to the inner defenses.
            $radius = $type === 'heavytrap' ? 8.5 : ($defense ? 6.5 : 10.5);
            for ($i = 0; $i < 32; ++$i) {
                $angle = $rotation + (($i + $ordinal * 3 + ($seed % 13)) % 16) * M_PI / 8;
                $r = $radius + ($i >= 16 ? -3.0 : 0.0);
                $x = round(($centerX + cos($angle) * $r * $stretchX) * 2) / 2;
                $y = round(($centerY + sin($angle) * $r * $stretchY) * 2) / 2;
                $candidates[] = [$x, $y];
            }
            // Try the preferred ring first. Any structure can outgrow its ring
            // as large facilities fill a yard, even when another corner is free.
            $margin=YardSpace::margin($base);$span=14+$margin;
            for($row=0;$row<$span;$row++){
                for($column=0;$column<$span;$column++){
                    $candidates[]=[3.0-$margin+(($column+($seed%$span))%$span)*2,3.0-$margin+(($row+(intdiv($seed,$span)%$span))%$span)*2];
                }
            }
        }
        $safe = [];
        foreach ($candidates as [$x, $y]) {
            if (!YardSpace::contains($base,$x,$y,1+$size/2)) {
                continue;
            }
            $clear = true;
            foreach ($base['buildings'] ?? [] as $building) {
                $otherSize = (float)($building['size'] ?? $definitions[$building['type']]['size'] ?? 3);
                // Deliberately conservative pre-filter. Game::mutate performs the
                // authoritative footprint and world boundary validation again.
                if (abs($x - (float)$building['x']) < ($size + $otherSize) / 2 + 0.25 && abs($y - (float)$building['y']) < ($size + $otherSize) / 2 + 0.25) {
                    $clear = false;
                    break;
                }
            }
            if ($clear && !Mushrooms::overlaps($base,$x,$y,$size) && !(class_exists('Decorations') && Decorations::overlaps($base,$x,$y,$size))) {
                $safe[] = [$x, $y];
                if(count($safe)>=5){break;}
            }
        }
        return array_slice($safe, 0, 5);
    }

    private static function writeHeartbeat(array $summary): void
    {
        WorldActivity::recordTick($summary);
        $stmt = Db::pdo()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        $stmt->execute(['worker_last_tick', (string)$summary['last_tick']]);
        $path = dirname(__DIR__) . '/var/world-status.json';
        $temporary = $path . '.tmp.' . getmypid();
        $encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $encoded . "\n", LOCK_EX) !== false) {
            if (!rename($temporary, $path)) {
                @unlink($temporary);
            }
        }
    }
}
