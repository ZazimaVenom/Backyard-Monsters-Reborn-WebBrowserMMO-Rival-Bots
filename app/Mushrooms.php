<?php
declare(strict_types=1);

final class Mushrooms
{
    public const INTERVAL = 17280;
    public const PICK_SECONDS = 2;
    private const SIZE = 0.65;

    public static function ensure(array &$base, int $owner, int $now): void
    {
        if (isset($base['mushrooms'])) { return; }
        $base['mushrooms'] = ['owner'=>$owner,'growthAt'=>$now,'nextId'=>1,'picked'=>0,'golden'=>0,'items'=>[],'lastPick'=>null];
        for ($i=0; $i<20; $i++) { self::spawn($base); }
    }

    private static function spawn(array &$base): bool
    {
        $patch = &$base['mushrooms'];
        $id = $patch['nextId'];
        // Four mushrooms to a cluster around the current yard edge.
        $rotation = crc32('yard-'.$patch['owner']) % 360;
        $catalog = Catalog::buildings();
        for ($try=0; $try<80; $try++) {
            $seed = hash('sha256', $patch['owner'].'/'.$id.'/'.$try, true);
            $angle = deg2rad($rotation + intdiv($id-1,4)*72 + (ord($seed[0])/255-.5)*38 + intdiv($try,4)*41);
            $radius = 12.5 + YardSpace::margin($base) + ord($seed[1])/255*2.5;
            $x = round(16+cos($angle)*$radius,1);
            $y = round(16+sin($angle)*$radius,1);
            if (self::overlaps($base,$x,$y,self::SIZE) || (class_exists('Decorations') && Decorations::overlaps($base,$x,$y,self::SIZE))) { continue; }
            foreach ($base['buildings'] ?? [] as $building) {
                $gap = (self::SIZE+($catalog[$building['type']]['size'] ?? 3))/2 + .15;
                if (abs($building['x']-$x)<$gap && abs($building['y']-$y)<$gap) { continue 2; }
            }
            // Hidden until collection, the find survives saves, visits and retries.
            $payout=random_int(0,3)===0 ? (random_int(0,2)===0 ? 8 : 3) : 0;
            $patch['items'][] = ['payout'=>$payout,'id'=>$id,'variant'=>1+ord($seed[2])%5,'x'=>$x,'y'=>$y,'readyAt'=>0];
            $patch['nextId']++;
            return true;
        }
        return false;
    }

    public static function overlaps(array $base, float $x, float $y, float $size): bool
    {
        $gap = ($size+self::SIZE)/2 + .15;
        foreach ($base['mushrooms']['items'] ?? [] as $item) {
            if (abs($x-$item['x'])<$gap && abs($y-$item['y'])<$gap) { return true; }
        }
        return false;
    }

    public static function start(array &$base, int $owner, mixed $id, int $now): array
    {
        self::ensure($base,$owner,$now);
        if (!is_int($id) || $id<1 || $id>=$base['mushrooms']['nextId']) { throw new RuntimeException('Choose a mushroom from your own yard.'); }
        foreach ($base['mushrooms']['items'] as $index=>$item) {
            if ($item['id']!==$id) { continue; }
            if (!$item['readyAt']) {
                Construction::assertAvailable($base,$now);
                $item['readyAt'] = $now+self::PICK_SECONDS;
                $base['mushrooms']['items'][$index] = $item;
            }
            return ['id'=>$id,'readyAt'=>$item['readyAt'],'status'=>'picking'];
        }
        return ['id'=>$id,'readyAt'=>0,'status'=>'picked'];
    }

    private static function grow(array &$base, int $until): void
    {
        $patch = &$base['mushrooms'];
        $elapsed = intdiv(max(0,$until-$patch['growthAt']),self::INTERVAL);
        if (!$elapsed) { return; }
        $patch['growthAt'] += $elapsed*self::INTERVAL;
        $number = min($elapsed,10-count($patch['items']));
        for ($i=0; $i<$number; $i++) {
            if (!self::spawn($base)) { break; }
        }
    }

    public static function accrue(array &$base, int $now): void
    {
        if (!isset($base['mushrooms'])) { return; }
        $patch = &$base['mushrooms'];
        $due = array_filter($patch['items'],static fn($item)=>$item['readyAt']>0 && $item['readyAt']<=$now);
        uasort($due, static fn($a,$b)=>[$a['readyAt'],$a['id']]<=>[$b['readyAt'],$b['id']]);
        foreach ($due as $index=>$item) {
            self::grow($base,$item['readyAt']-1);
            unset($patch['items'][$index]);
            $shiny = (int)$item['payout'];
            ++$patch['picked'];
            if ($shiny>0) {
                ++$patch['golden'];
                $base['shiny'] = (int)($base['shiny']??0)+$shiny;
            }
            $patch['lastPick'] = ['id'=>$item['id'],'shiny'=>$shiny,'at'=>$item['readyAt']];
        }
        if ($due) { $patch['items'] = array_values($patch['items']); }
        self::grow($base,$now);
    }

    public static function view(array $base, bool $owner): array
    {
        $patch=$base['mushrooms']??[];
        $view=['items'=>[]];
        foreach ($patch['items']??[] as $item) {
            $row=['id'=>$item['id'],'variant'=>$item['variant'],'x'=>$item['x'],'y'=>$item['y']];
            if ($owner) { $row['readyAt']=$item['readyAt']; }
            $view['items'][]=$row;
        }
        if ($owner) {
            $view+=['picked'=>$patch['picked']??0,'golden'=>$patch['golden']??0,'lastPick'=>$patch['lastPick']??null,
                'nextGrowthAt'=>count($view['items'])<10 && isset($patch['growthAt']) ? $patch['growthAt']+self::INTERVAL : null];
        }
        return $view;
    }
}
