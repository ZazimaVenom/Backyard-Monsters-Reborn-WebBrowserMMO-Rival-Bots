<?php
declare(strict_types=1);

/** Main-yard encounters. The caller holds the keeper's home row for every write. */
final class Seasonal
{
    private const DAY = 86400;
    private const PUMPKIN_SIZE = .65;
    private const HORSE = 27;
    private const BAG = 54;

    private static function window(array $patch, int $now): array
    {
        $year = (int)gmdate('Y',$now);
        $start = gmmktime(0,0,0,10,30,$year);
        $end = $start + 3*self::DAY;
        $calendar = $now >= $start && $now < $end;
        $replay = !$calendar && $now >= ($patch['replayStart'] ?? 0) && $now < ($patch['replayUntil'] ?? 0);
        if ($replay) { $year = $patch['replayYear']; }
        return ['calendarActive'=>$calendar,'replayActive'=>$replay,'year'=>$year,
            'until'=>$calendar ? $end : ($replay ? $patch['replayUntil'] : 0),
            'replayUsed'=>(int)($patch['replayYear'] ?? 0)===$year];
    }

    private static function scale(): array
    {
        static $data;
        return $data ??= json_decode(file_get_contents(__DIR__.'/yard-experience.json'),true,512,JSON_THROW_ON_ERROR);
    }

    public static function yardProgression(array $base): array
    {
        $data=self::scale();
        $value=0;
        $upgrades=0;
        foreach ($base['buildings'] ?? [] as $building) {
            $level=(int)$building['level'];
            $tiers=$data['value'][$building['type']] ?? [];
            if (!$tiers || $level<1) { continue; }
            $value += $tiers[min($level,count($tiers))-1];
            // BFOUNDATION credits one third of the prior tier on completed upgrades.
            for ($i=0; $i<min($level-1,count($tiers)); $i++) { $upgrades += intdiv($tiers[$i],3); }
        }
        $patch=$base['seasonal'] ?? [];
        $points=max((int)($patch['yardBest'] ?? 0),(int)ceil($value/10)+$upgrades) + (int)($patch['earned'] ?? 0);
        $level=0;
        foreach ($data['levels'] as $threshold) {
            if ($threshold>$points) { break; }
            $level++;
        }
        return ['level'=>$level,'points'=>$points];
    }

    private static function horse(array $base): array
    {
        $patch=$base['seasonal'] ?? [];
        ['level'=>$level,'points'=>$points]=self::yardProgression($base);
        $eligible=$level>=9 && $points>2000000;
        return ['level'=>$level,'points'=>$points,'eligible'=>$eligible,'encountered'=>!empty($patch['horseSeen']),
            'opened'=>!empty($patch['horseOpened'])];
    }

    public static function credit(array &$base, int $points): void
    {
        if ($points>0 && !isset($base['bot_turns'])) {
            $base['seasonal']['earned']=(int)($base['seasonal']['earned'] ?? 0)+$points;
        }
    }

    private static function objects(array $base, int $now): array
    {
        $patch=$base['seasonal'] ?? [];
        $objects=[];
        if (!empty($patch['horseSeen']) && empty($patch['horseOpened'])) {
            $objects[]=['id'=>27,'type'=>self::HORSE,'x'=>14.5,'y'=>-.25,'readyAt'=>0];
        }
        $window=self::window($patch,$now);
        if ($window['until'] && (int)($patch['bagYear'] ?? 0)!==$window['year']) {
            $objects[]=['id'=>$window['year']*100+54,'type'=>self::BAG,'x'=>-.25,'y'=>2.5,'readyAt'=>0];
        }
        foreach ($patch['pumpkins'] ?? [] as $item) {
            if (!$window['until'] || $item['expires']<=$now) { continue; }
            $objects[]=['id'=>$item['id'],'type'=>53,'x'=>$item['x'],'y'=>$item['y'],'readyAt'=>$item['readyAt']];
        }
        return $objects;
    }

    public static function overlaps(array $base, float $x, float $y, float $size, ?int $now=null): bool
    {
        if (!isset($base['seasonal'])) { return false; }
        foreach (self::objects($base,$now ?? time()) as $item) {
            $gap=($size+($item['type']===53 ? self::PUMPKIN_SIZE : 1.8))/2 + .15;
            if (abs($x-$item['x'])<$gap && abs($y-$item['y'])<$gap) { return true; }
        }
        return false;
    }

    private static function spawn(array &$base, int $now): bool
    {
        $catalog=Catalog::buildings();
        // Sample an empty half-tile. Limit the search even in fully built yards.
        $extra=2*YardSpace::margin($base);
        for ($attempt=0;$attempt<512;$attempt++) {
            $x=random_int(3-$extra,61+$extra)/2;
            $y=random_int(3-$extra,61+$extra)/2;
            if (self::overlaps($base,$x,$y,self::PUMPKIN_SIZE,$now) || Mushrooms::overlaps($base,$x,$y,self::PUMPKIN_SIZE)
                || Decorations::overlaps($base,$x,$y,self::PUMPKIN_SIZE)) { continue; }
            foreach ($base['buildings'] ?? [] as $building) {
                $gap=(($catalog[$building['type']]['size'] ?? 3)+self::PUMPKIN_SIZE)/2+.15;
                if (abs($x-$building['x'])<$gap && abs($y-$building['y'])<$gap) { continue 2; }
            }
            $p=&$base['seasonal'];
            $id=$p['nextId']=(int)($p['nextId'] ?? 999)+1;
            $boost=random_int(1,4)===1;
            $p['pumpkins'][]=['id'=>$id,'x'=>$x,'y'=>$y,'expires'=>$now+self::DAY,'readyAt'=>0,
                'trick'=>random_int(0,1)===1,'resource'=>['twig','pebble','putty','goo'][random_int(0,3)],
                'bp'=>$boost?random_int(700,1199):random_int(200,399)];
            return true;
        }
        return false;
    }

    /** Pick jobs and spoilage settle on resuming the yard, including after the event closes. */
    public static function accrue(array &$base, int $now): void
    {
        if (empty($base['seasonal']['pumpkins'])) { return; }
        $patch=&$base['seasonal'];
        $survivors=[];
        $due=[];
        $window=self::window($patch,$now);
        foreach ($patch['pumpkins'] as $item) {
            if ($item['readyAt'] && $item['readyAt']<=$now) { $due[]=$item; }
            elseif ($window['until'] && $item['expires']>$now) { $survivors[]=$item; }
        }
        usort($due,static fn($a,$b)=>[$a['readyAt'],$a['id']]<=>[$b['readyAt'],$b['id']]);
        foreach ($due as $item) {
            $resource=$item['resource'];
            $amount=0;
            if (!$item['trick']) {
                $cap=Game::capacity($base);
                $amount=min(intdiv($cap*$item['bp'],10000),max(0,(int)floor($cap-$base['resources'][$resource])));
                $base['resources'][$resource]+=$amount;
            }
            $patch['lastPick']=['id'=>$item['id'],'kind'=>$item['trick']?'trick':'treat','resource'=>$item['trick']?null:$resource,
                'amount'=>$amount,'at'=>$item['readyAt']];
        }
        $patch['pumpkins']=$survivors;
    }

    /** Only entry to the human keeper's own surface yard creates new events. */
    public static function observe(array &$base, int $now): void
    {
        if (isset($base['bot_turns'])) { return; }
        self::accrue($base,$now);
        $horse=self::horse($base);
        if ($horse['eligible'] && !$horse['encountered']) { $base['seasonal']['horseSeen']=true; }
        $window=self::window($base['seasonal'] ?? [],$now);
        if (isset($base['seasonal']) || $window['until']) {
            $base['seasonal']['yardBest']=$horse['points']-(int)($base['seasonal']['earned'] ?? 0);
        }
        if (!$window['until']) { return; }
        $day=intdiv($now,self::DAY);
        if (($base['seasonal']['growDay'] ?? -1)===$day) { return; }
        $base['seasonal']['growDay']=$day;
        $target=random_int(4,5);
        for ($i=count($base['seasonal']['pumpkins'] ?? []);$i<$target;$i++) {
            if (!self::spawn($base,$now)) { break; }
        }
    }

    public static function view(array $base, bool $owner, ?int $now=null): array
    {
        $now ??= time();
        $bot=isset($base['bot_turns']);
        $objects=$bot ? [] : self::objects($base,$now);
        if (!$owner) {
            foreach ($objects as &$item) { unset($item['readyAt']); } unset($item);
            return ['objects'=>$objects];
        }
        $patch=$base['seasonal'] ?? [];
        $window=self::window($patch,$now);
        return ['objects'=>$objects,'bagClaimed'=>(int)($patch['bagYear'] ?? 0)===$window['year'],
            'lastPick'=>$patch['lastPick'] ?? null,'horse'=>self::horse($base)] + $window;
    }

    public static function act(array &$base, array $input, int $now): array
    {
        if (isset($base['bot_turns'])) { throw new RuntimeException('Visit your own home yard to use this event.'); }
        $op=$input['op'] ?? null;
        self::observe($base,$now);
        $window=self::window($base['seasonal'] ?? [],$now);
        if ($op==='halloweenReplay') {
            if (!$window['until'] && $window['replayUsed']) { throw new RuntimeException('Your Halloween replay for this year has finished.'); }
            if (!$window['until']) {
                $base['seasonal']['replayYear']=$window['year'];
                $base['seasonal']['replayStart']=$now;
                $base['seasonal']['replayUntil']=$now+3*self::DAY;
                self::observe($base,$now);
            }
            return ['kind'=>'replay','until'=>self::window($base['seasonal'],$now)['until']];
        }
        if (!$window['until']) { throw new RuntimeException('Halloween is closed. Open a Halloween replay from your home yard.'); }
        $id=$input['id'] ?? null;
        if ($op==='giantOpen') {
            if ($id!==$window['year']*100+54) { throw new RuntimeException('Choose the giant pumpkin from this Halloween.'); }
            $p=&$base['seasonal'];
            if (($p['bagYear'] ?? 0)===$window['year']) {
                return ['kind'=>'bag','shiny'=>0,'decorations'=>[],'alreadyClaimed'=>true];
            }
            $owned=array_column($base['decorations'] ?? [],'item');
            $next=max([0,...array_column($base['decorations'] ?? [],'id')]);
            $awarded=[];
            foreach ([110=>1,111=>6] as $type=>$number) {
                if (in_array($type,$owned,true)) { continue; }
                for ($i=0;$i<$number;$i++) {
                    $base['decorations'][]=['id'=>++$next,'item'=>$type,'receipt'=>"halloween-{$window['year']}-$type-$i"];
                }
                $awarded[$type]=$number;
            }
            $p['bagYear']=$window['year'];
            $base['shiny']=($base['shiny'] ?? 0)+100;
            return ['kind'=>'bag','shiny'=>100,'decorations'=>$awarded,'alreadyClaimed'=>false];
        }
        if ($op!=='pumpkinPick' || !is_int($id)) { throw new RuntimeException('Choose a pumpkin in your own yard.'); }
        foreach ($base['seasonal']['pumpkins'] ?? [] as $index=>$item) {
            if ($item['id']!==$id) { continue; }
            if (!$item['readyAt']) {
                Construction::assertAvailable($base,$now);
                $base['seasonal']['pumpkins'][$index]['readyAt']=$now+2;
            }
            return ['kind'=>'picking','id'=>$id,'readyAt'=>$base['seasonal']['pumpkins'][$index]['readyAt']];
        }
        if ($id>=1000 && $id<=($base['seasonal']['nextId'] ?? 0)) { return ['kind'=>'gone','id'=>$id]; }
        throw new RuntimeException('Choose a pumpkin in your own yard.');
    }

    public static function horseBattle(array $base,array $owner,int $now,float $defense,array $network): array
    {
        $horse=self::horse($base);
        if (!$horse['encountered'] || $horse['opened']) { throw new RuntimeException('There is no unopened Trojan Horse in this yard.'); }
        $species=[1=>'pokey',2=>'octo',3=>'bolt',4=>'fink',6=>'ichi',7=>'bandito',8=>'fang',9=>'brain',10=>'crabatron',11=>'projectx'];
        $groups=[];
        foreach ([1,...range(20,1100,20)] as $frame) {
            $type=$species[(int)ceil($frame/100)] ?? null;
            if ($type) { $groups[]=[ceil((($frame-1)/30)/BattleEngine::STEP)*BattleEngine::STEP,$type,1]; }
        }
        $state=Baiter::defenseState($base,$owner,MonsterBlitz::counts($groups),'t',$now,$defense,$network,true);
        $state['formatVersion']=max(8,$state['formatVersion']);
        $points=$horse['points'];
        $power=$points>8000000 ? 1 : ($points>5000000 ? .8 : ($points>3000000 ? .6 : .4));
        $state['horseWave']=['groups'=>$groups,'next'=>0,'power'=>$power];
        $state['target']=['kind'=>'horse','name'=>'Trojan Horse'];
        $state['attacker']['name']='Trojan Horse';
        $state['remaining']=$state['wave'];
        $state['launch_at']=$now;
        return $state;
    }
}
