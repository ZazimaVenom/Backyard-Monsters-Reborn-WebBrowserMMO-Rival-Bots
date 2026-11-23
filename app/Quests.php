<?php
declare(strict_types=1);

/** Permanent keeper milestones. All claims run under the normal home-row transaction. */
final class Quests
{
    private const RESOURCES = ['twig','pebble','putty','goo'];

    public static function book(): array
    {
        static $entries;
        return $entries ??= (require __DIR__.'/quest-book.php') + (require __DIR__.'/quest-expansion.php');
    }

    /** Only completed, authoritative game events call these counters. */
    public static function hatched(array &$base, string $type, int $amount): void
    {
        if ($amount > 0 && !isset($base['bot_turns']) && isset(Catalog::monsters()[$type])) {
            $base['questLog']['hatched'] = min(3, ($base['questLog']['hatched'] ?? 0) + $amount);
        }
    }

    public static function harvested(array &$base, array $collected): void
    {
        if (isset($base['bot_turns'])) { return; }
        $amount = 0;
        foreach (self::RESOURCES as $type) { $amount += max(0, (int)($collected[$type] ?? 0)); }
        if ($amount > 0) {
            $base['questLog']['bestHarvest'] = min(50000, max($base['questLog']['bestHarvest'] ?? 0, $amount));
        }
    }

    public static function juiced(array &$base, int $amount): void
    {
        if ($amount > 0 && !isset($base['bot_turns'])) {
            $base['questLog']['juiced'] = min(5000, ($base['questLog']['juiced'] ?? 0) + $amount);
        }
    }

    private const BUILD_MILESTONES=['wall'=>['blocksBuilt',200],'heavytrap'=>['heavyTrapsBuilt',8]];

    /** First construction counts once; repairs, upgrades and moves never enter this path. */
    public static function constructed(array &$home,string $type,int $count=1):void
    {
        if (isset($home['bot_turns']) || !isset(self::BUILD_MILESTONES[$type])) { return; }
        [$metric,$limit]=self::BUILD_MILESTONES[$type];
        $home['questLog'][$metric]=min($limit,($home['questLog'][$metric]??0)+$count);
    }

    public static function fieldProject(array &$field,array &$facility):void
    {
        $type=$facility['type'];
        if (!isset(self::BUILD_MILESTONES[$type])) { return; }
        if ($facility['level']<=0) { $facility['journalProject']=true;return; }
        $field['mr2Outpost']['completedProjects'][$type]=min(self::BUILD_MILESTONES[$type][1],($field['mr2Outpost']['completedProjects'][$type]??0)+1);
        unset($facility['journalProject']);
    }

    public static function collectFieldProjects(array &$home,array &$field):void
    {
        foreach ($field['mr2Outpost']['completedProjects']??[] as $type=>$count) { self::constructed($home,$type,$count); }
        unset($field['mr2Outpost']['completedProjects']);
    }

    public static function outpostCaptured(array &$home,bool $rival):void
    {
        if (isset($home['bot_turns'])) { return; }
        if ($rival) { $home['questLog']['rivalOutposts']=min(5,($home['questLog']['rivalOutposts']??0)+1); }
        else { $home['questLog']['wildOutpost']=1; }
    }

    public static function outpostKit(array &$home):void
    {
        if (!isset($home['bot_turns'])) { $home['questLog']['outpostKit']=1; }
    }

    public static function battleKills(array &$base, array $units, string $enemyTeam): void
    {
        if (isset($base['bot_turns']) || !empty($base['questLog']['battleKills'])) { return; }
        foreach ($units as $unit) {
            if (($unit['team'] ?? 'attacker') === $enemyTeam && (float)$unit['hp'] <= 0 && empty($unit['champion'])) {
                $base['questLog']['battleKills'] = 1;
                break;
            }
        }
    }

    /** Record personal bests as part of the same save that changed the underlying yard. */
    public static function observe(array &$base): void
    {
        if (isset($base['bot_turns'])) { return; }
        $metrics = [];
        foreach ($base['buildings'] as $building) {
            $level = (int)$building['level'];
            if ($level < 1) { continue; }
            $key = 'building.'.$building['type'];
            $metrics[$key] = max($metrics[$key] ?? 0, $level);
            $metrics['highestLevel'] = max($metrics['highestLevel'] ?? 0, $level);
        }
        $harvesters = [];
        foreach (self::RESOURCES as $type) { $harvesters[] = $metrics['building.'.$type] ?? 0; }
        $metrics['harvester'] = max($harvesters);
        $metrics['harvesterSet'] = min($harvesters);
        $metrics['stockpile'] = min(array_map(static fn(string $r): int => (int)($base['resources'][$r] ?? 0), self::RESOURCES));
        $metrics['holdings'] = (int)($base['world']['holdings'] ?? 0);
        $metrics['academy'] = max([0, ...array_values($base['research'] ?? [])]);
        $metrics['mushroomsPicked'] = (int)($base['mushrooms']['picked']??0);
        $metrics['goldenMushrooms'] = (int)($base['mushrooms']['golden']??0);
        $metrics['labStudy'] = max([0, ...array_values($base['lab']['levels'] ?? [])]);
        if (($metrics['building.locker'] ?? 0) > 0) {
            foreach ($base['unlocked'] ?? [] as $species) { $metrics['locker.'.$species] = 1; }
        }
        $starters = ['pokey','octo','fink','ichi','bandito','dave'];
        $metrics['newSpecies'] = count(array_diff($base['unlocked'] ?? [], $starters)) > 0 ? 1 : 0;
        $metrics['championsEvolved'] = 0;
        foreach (['gorgo','drull','fomor'] as $species) {
            $level = (int)($base['champions'][$species]['level'] ?? 0);
            $metrics['champion.'.$species] = $level;
            if ($level >= 6) { ++$metrics['championsEvolved']; }
        }
        foreach (['Legionnaire','Kozu','Abunakki','Dreadnaut'] as $tribe) {
            if (!empty($base['tribeCampaign']['hallVictories'][$tribe])) { $metrics['tribe.'.$tribe] = 1; }
        }
        foreach ($base['siege']['levels'] ?? [] as $device=>$level) { $metrics['siege.'.$device] = (int)$level; }
        if (isset($base['infernoRealm'])) {
            $realm = $base['infernoRealm'];
            foreach ($realm['buildings'] as $building) {
                $key = 'cavern.building.'.$building['type'];
                $metrics[$key] = max($metrics[$key] ?? 0, (int)$building['level']);
            }
            $metrics['cavern.harvesterSet'] = min(array_map(
                static fn(string $type): int => $metrics['cavern.building.'.$type] ?? 0, self::RESOURCES
            ));
            foreach ($realm['unlocked'] ?? [] as $type) { $metrics['cavern.locker.'.$type] = 1; }
            $metrics['cavern.bestHarvest'] = $realm['bestQuestHarvest'] ?? 0;
            $metrics['cavern.descent'] = count(array_intersect(range(201,213),array_keys($realm['campaign']['cleared'] ?? [])));
            $goals = 0;
            foreach (self::book() as $id=>$entry) {
                $metric = $entry[3];
                if ($entry[0] !== 'Achievements' && str_starts_with($metric,'cavern.')
                    && max($metrics[$metric] ?? 0,$base['questLog']['best'][$metric] ?? 0) >= $entry[4]) {
                    ++$goals;
                }
            }
            $metrics['cavern.goals'] = $goals;
        }
        // Only persist metrics used by shipped objectives, clamped at the highest shipped target.
        static $limits;
        if ($limits === null) {
            $limits = [];
            foreach (self::book() as $entry) { $limits[$entry[3]] = max($limits[$entry[3]] ?? 0, $entry[4]); }
        }
        foreach ($metrics as $key => $amount) {
            $value = min($limits[$key] ?? 0, $amount);
            if ($value > ($base['questLog']['best'][$key] ?? 0)) {
                $base['questLog']['best'][$key] = $value;
            }
        }
    }

    private static function progress(array $base, array $keeper, string $metric): int
    {
        if (in_array($metric,['wins','giftsAccepted','allied'],true)) {
            return max(0, (int)($keeper[$metric] ?? 0), (int)($base['questLog']['best'][$metric] ?? 0));
        }
        return max(0, (int)($base['questLog']['best'][$metric] ?? $base['questLog'][$metric] ?? 0));
    }

    private static function missingSpace(array $resources, int $capacity, int $freeHousing, array $award, array $troops, array $species): array
    {
        $missing = [];
        foreach ($award as $type => $amount) {
            $space = max(0, (int)floor($capacity - ($resources[$type] ?? 0)));
            if ($space < $amount) { $missing[$type] = $amount - $space; }
        }
        $needed = 0;
        foreach ($troops as $type => $count) { $needed += $species[$type]['size'] * $count; }
        if ($freeHousing < $needed) { $missing['housing'] = $needed - $freeHousing; }
        return $missing;
    }

    public static function view(array $base, array $keeper): array
    {
        $entries = [];
        $counts = ['ready'=>0, 'claimed'=>0, 'completed'=>0, 'earned'=>0];
        if (!empty($keeper['is_bot'])) { return ['entries'=>[], 'counts'=>$counts]; }
        // A just-completed construction can be returned by the caller before the next reload.
        self::observe($base);
        $capacity = Game::capacity($base);
        $freeHousing = max(0, Game::housingCapacity($base) - Game::housingUsed($base));
        $species = Catalog::monsters();
        $realm = $base['infernoRealm'] ?? null;
        $nativeRoom = $realm ? InfernoMonsters::space($realm,time()) : ['used'=>0,'capacity'=>0];
        foreach (self::book() as $id=>$entry) {
            [$group,$title,$instruction,$metric,$target,$resources,$monsters]=$entry;
            $device=$entry[7]??null; $factory=''; $shiny=$entry[8]??0;
            $underground = $group !== 'Achievements' && str_starts_with($metric,'cavern.');
            $destination = $underground ? 'inferno' : 'surface';
            $progress = min($target, self::progress($base, $keeper, $metric));
            $claimed = isset($base['questLog']['claimed'][$id]);
            $status = $claimed ? 'claimed' : 'active';
            $missing = [];
            if ($claimed || $progress >= $target) {
                ++$counts['completed'];
                if (!$claimed) { $status = $group === 'Achievements' ? 'earned' : 'ready'; }
                if ($status === 'ready') {
                    $missing = $underground
                        ? self::missingSpace($realm['resources'] ?? [], $realm ? InfernoRealm::capacity($realm) : 0,
                            max(0,$nativeRoom['capacity']-$nativeRoom['used']), $resources,$monsters,$species)
                        : self::missingSpace($base['resources'],$capacity,$freeHousing,$resources,$monsters,$species);
                    if ($underground && $realm && InfernoDefense::active($realm)) { $factory='Finish the cavern counterattack to collect this reward.'; }
                    if ($device!==null) { $factory=SiegeWorkshop::awardRestriction($base); }
                }
                ++$counts[$status];
            }
            $entries[] = compact('id','group','title','instruction','target','progress','status','resources','monsters','missing','device','factory','shiny','destination');
        }
        return ['entries'=>$entries, 'counts'=>$counts];
    }

    public static function claim(array &$base, array $keeper, mixed $id, int $capacity): array
    {
        if (!empty($keeper['is_bot']) || isset($base['bot_turns'])) { throw new RuntimeException('Sign in as a keeper to collect a quest award.'); }
        $entry = is_string($id) ? self::book()[$id] ?? null : null;
        if (!$entry || $entry[0] === 'Achievements') { throw new RuntimeException('Choose a reward from your quest journal.'); }

        [$group,$title,$instruction,$metric,$target,$resources,$monsters] = $entry;
        if (isset($base['questLog']['claimed'][$id])) {
            return ['id'=>$id, 'alreadyClaimed'=>true];
        }
        $underground = str_starts_with($metric,'cavern.');
        if ($underground && isset($base['infernoRealm']) && InfernoDefense::active($base['infernoRealm'])) {
            throw new RuntimeException('Finish the cavern counterattack to collect this reward.');
        }
        self::observe($base);
        if (self::progress($base, $keeper, $metric) < $target) { throw new RuntimeException('Complete the quest objective before collecting its reward.'); }
        $freeHousing = max(0, Game::housingCapacity($base) - Game::housingUsed($base));
        if ($underground) {
            if (!isset($base['infernoRealm'])) { throw new RuntimeException('Open your Inferno cavern before collecting the award.'); }
            $destination = &$base['infernoRealm'];
            $room=InfernoMonsters::space($destination,time());
            $freeHousing=max(0,$room['capacity']-$room['used']);
            $capacity=InfernoRealm::capacity($destination);
        } else {
            $destination = &$base;
        }
        $missing = self::missingSpace($destination['resources'], $capacity, $freeHousing, $resources, $monsters, Catalog::monsters());
        if ($missing) {
            throw new RuntimeException('Make room for the full reward in your '.(isset($missing['housing']) ? ($underground ? 'Compound' : 'Monster Housing') : ($underground ? 'underground resource storage' : 'resource storage')).'. Your award stays in the journal.');
        }
        $device=$entry[7]??null;
        if ($device !== null) {
            $reason=SiegeWorkshop::awardRestriction($base);
            if ($reason!=='') { throw new RuntimeException($reason); }
            $base['siege']['inventory'][$device]=1;
        }
        foreach ($resources as $type => $amount) { $destination['resources'][$type] += $amount; }
        foreach ($monsters as $species => $count) { $destination['army'][$species] = ($destination['army'][$species] ?? 0) + $count; }
        if ($underground) { ++$destination['revision']; if ($monsters) { ++$destination['monsterRevision']; } }
        $shiny=$entry[8]??0;
        if ($shiny) { $base['shiny']=($base['shiny']??0)+$shiny; }
        $base['questLog']['claimed'][$id] = true;
        return ['id'=>$id, 'alreadyClaimed'=>false, 'resources'=>$resources, 'monsters'=>$monsters, 'device'=>$device,'shiny'=>$shiny,'destination'=>$underground?'inferno':'surface'];
    }
}
