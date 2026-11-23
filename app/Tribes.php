<?php
declare(strict_types=1);
require_once __DIR__.'/Brukkarg.php';
require_once __DIR__.'/CreatureCarnage.php';
require_once __DIR__.'/HellRaisers.php';

/** Private expedition progress. Wild camps have no keeper account or map cell. */
final class Tribes
{
    private const TIER_NAMES = ['Border camp', 'Scouting post', 'Encampment', 'Hill fort', 'Citadel', 'High keep'];
    private const RESOURCES = ['twig','pebble','putty','goo'];

    public static function catalog(): array
    {
        static $catalog;
        if ($catalog === null) {
            $catalog = [];
            $rows = json_decode(file_get_contents(__DIR__.'/tribe-layouts.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($rows as $index => $row) {
                $stage = $index + 1;
                $catalog[$stage] = $row + ['stage'=>$stage, 'name'=>$row['tribe'].' '.strtolower(self::TIER_NAMES[$row['tier']])];
            }
        }
        return $catalog;
    }

    public static function progress(array $base): array
    {
        return ($base['tribeCampaign'] ?? []) + ['cleared'=>[], 'pending'=>[], 'previousId'=>null, 'requestId'=>null];
    }

    public static function stage(array $base, int $stage): array
    {
        if (HellRaisers::contains($stage)) {return HellRaisers::camp($base,$stage,time());}
        if (CreatureCarnage::contains($stage)) {return CreatureCarnage::camp($base,$stage,time());}
        if (Brukkarg::contains($stage)) {return Brukkarg::camp($base,$stage,time());}
        $row = self::catalog()[$stage] ?? null;
        if (!$row) { throw new RuntimeException('Choose a wild camp from the campaign.'); }
        if ($stage > 1 && !isset(self::progress($base)['cleared'][$stage-1])) {
            throw new RuntimeException('Clear the previous wild camp to open this route.');
        }
        return $row;
    }

    private static function card(array $row, array $progress): array
    {
        $stage = $row['stage'];
        return [
            'stage'=>$stage, 'name'=>$row['name'], 'tribe'=>$row['tribe'], 'tier'=>self::TIER_NAMES[$row['tier']],
            'reward'=>$row['reward'], 'record'=>$progress['cleared'][$stage] ?? null,
            'open'=>$stage===1 || isset($progress['cleared'][$stage-1])
        ];
    }

    public static function listing(int $id): array
    {
        return self::overview(Game::loadBase($id));
    }

    private static function overview(array $base): array
    {
        $progress = self::progress($base);
        return [
            'hellraisers'=>HellRaisers::view($base,time()), 'carnage'=>CreatureCarnage::route($base,time()), 'brukkarg'=>Brukkarg::route($base),
            'camps'=>array_values(array_map(static fn(array $row):array=>self::card($row,$progress),self::catalog())),
            'cleared'=>count($progress['cleared']), 'previousId'=>$progress['previousId'],
            'pending'=>(object)$progress['pending']
        ];
    }

    public static function yard(array $row): array
    {
        if (isset($row['eventYard'])) {return $row['eventYard'];}
        $buildings = $garrison = [];
        $catalog = Catalog::buildings();
        foreach ($row['buildings'] as $index => [$type,$x,$y,$rawLevel]) {
            $level = min((int)$rawLevel, $catalog[$type]['maxLevel']);
            $hp = Game::buildingStats($type, $level)['max_hp'];
            $id = $index+1;
            $buildings[] = ['id'=>$id, 'type'=>$type, 'x'=>$x, 'y'=>$y, 'level'=>$level, 'hp'=>$hp, 'max_hp'=>$hp,
                'ready_at'=>0, 'upgrade_to'=>null, 'stored'=>0, 'fortification'=>0];
            if ($type==='bunker' && $row['tribe']!=='Brukkarg') {
                $troop = ['Legionnaire'=>'pokey','Kozu'=>'fink','Abunakki'=>'octo','Dreadnaut'=>'bandito'][$row['tribe']];
                $garrison[$id] = [$troop=>max(1, $row['tier'])];
            }
        }
        return ['buildings'=>$buildings, 'garrison'=>$garrison,'research'=>[]];
    }

    public static function visit(int $id, int $stage): array
    {
        $base = Game::loadBase($id);
        $row = self::stage($base,$stage);
        $card = (Brukkarg::contains($stage)||CreatureCarnage::contains($stage)||HellRaisers::contains($stage))?array_intersect_key($row,array_flip(['stage','name','tier','tribe','event','record','reward','open','damageUntil','run','requirement','xp','best','sourceId','supplied'])):self::card($row,self::progress($base));
        $yard = self::yard($row);
        return [
            'owner'=>['id'=>0, 'name'=>$row['name'], 'is_bot'=>false],
            'buildings'=>array_values(array_filter($yard['buildings'],[Catalog::class,'visibleToRivals'])),
            'army'=>[], 'shield_until'=>0, 'tribe'=>$card, 'previousId'=>self::progress($base)['previousId']
        ];
    }

    /** Called under the attacker's home mutex before reserving a new battle. */
    public static function retryId(array $base, array $request, int $stage): ?int
    {
        $token = $request['requestId'] ?? null;
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$token)) {
            throw new RuntimeException('Reopen the wild camp before starting this invasion.');
        }
        $progress = self::progress($base);
        if ($token === $progress['requestId']) {
            if ($stage !== ($progress['lastStage'] ?? null)) { throw new RuntimeException('This command belongs to another camp.'); }
            return $progress['previousId'];
        }
        if (!array_key_exists('previousId',$request) || $request['previousId'] !== $progress['previousId']) {
            throw new RuntimeException('Your expedition changed. Reopen the wild camp.');
        }
        return null;
    }

    public static function rememberStart(array &$base, int $id, int $stage, string $request): void
    {
        $progress = self::progress($base);
        $progress['previousId'] = $id;
        $progress['lastStage'] = $stage;
        $progress['requestId'] = $request;
        $base['tribeCampaign'] = $progress;
    }

    /** Settlement awards one parcel per camp. Overflow stays with the keeper until collected. */
    public static function finish(array &$base, array &$result, array $target, int $now): void
    {
        $progress = self::progress($base);
        $stage = $target['stage'];
        $won = (bool)$result['attackerWon'];
        $firstClear = $won && !isset($progress['cleared'][$stage]);
        $result['target'] = $target;
        $result['rankDelta'] = ['attacker'=>0, 'defender'=>0];
        if (!$won) { $result['winnerId'] = null; }
        if ($won && ($result['hallDestroyed'] ?? false)) {
            $progress['hallVictories'][$target['tribe']] = true;
        }
        if ($won) {
            $old = $progress['cleared'][$stage] ?? ['firstAt'=>$now, 'best'=>0];
            $old['best'] = max($old['best'], $result['destruction']);
            $progress['cleared'][$stage] = $old;
        }
        $result['campaign'] = ['firstClear'=>$firstClear, 'nextStage'=>$firstClear && isset(self::catalog()[$stage+1])?$stage+1:null];
        if ($firstClear) {
            foreach (self::RESOURCES as $resource) {
                $progress['pending'][$resource] = ($progress['pending'][$resource] ?? 0) + max(0,$target['reward'][$resource]-($progress['raided'][$stage][$resource]??0));
            }
        }
        if ($firstClear) { unset($progress['raided'][$stage]); }
        $base['tribeCampaign'] = $progress;
        if ($firstClear) { $result['loot'] = self::transferSupplies($base); }
        foreach ($result['raiderLoot']??[] as $r=>$amount) { $result['loot'][$r]=($result['loot'][$r]??0)+$amount; }
        $result['campaign']['pending'] = $base['tribeCampaign']['pending'];
    }

    public static function transferSupplies(array &$base): array
    {
        $capacity = Game::capacity($base);
        $progress = self::progress($base);
        $moved = [];
        foreach (self::RESOURCES as $resource) {
            $pending = (int)($progress['pending'][$resource] ?? 0);
            $free = max(0, (int)floor($capacity-$base['resources'][$resource]));
            $moved[$resource] = min($pending, $free);
            $base['resources'][$resource] += $moved[$resource];
            $progress['pending'][$resource] = $pending - $moved[$resource];
        }
        $base['tribeCampaign'] = $progress;
        return $moved;
    }

    public static function collect(int $id): array
    {
        return Db::transaction(static function() use ($id): array {
            $base = Game::loadBase($id,true);
            // Collecting uses the same bank and current effective capacity as other home actions.
            Game::accrue($base,time());
            $moved = self::transferSupplies($base);
            Game::saveBase($id,$base);
            return self::overview($base) + ['collected'=>$moved];
        });
    }
}
