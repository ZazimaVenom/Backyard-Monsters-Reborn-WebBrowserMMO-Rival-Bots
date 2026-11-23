<?php
declare(strict_types=1);

/** First-clear reward state is written under the same home row lock as the expedition result. */
final class SpurtzRewards
{
    public static function status(array $base): array
    {
        $cleared=min(5,max(0,(int)($base['brukkargClears']??0),(int)($base['hellRaisersCannon']??0)));
        return ['cleared'=>$cleared,'limit'=>$cleared>=4?2:($cleared===3?1:0),'type'=>$cleared===5?'diamondspurtz':'spurtzcannon'];
    }

    public static function award(array &$base,int $clearedCount,bool $fromEventStore=false): array
    {
        $source=$fromEventStore?'hellRaisersCannon':'brukkargClears';
        $base[$source]=max((int)($base[$source]??0),min(5,$clearedCount));
        $reward=self::status($base);
        if ($reward['type']==='diamondspurtz') {
            foreach ($base['buildings'] as &$building) {
                if ($building['type']!=='spurtzcannon') { continue; }
                $fraction=min(1,max(0,$building['hp']/max(1,$building['max_hp'])));
                $building['type']='diamondspurtz';
                $building['max_hp']=Game::buildingStats('diamondspurtz',max(1,(int)$building['level']))['max_hp'];
                $building['hp']=$fraction>0?max(1,(int)round($fraction*$building['max_hp'])):0;
            }
            unset($building);
        }
        return $reward;
    }

    public static function assertBuild(array $base,string $type): void
    {
        $reward=self::status($base);
        if (!$reward['limit'] || $type!==$reward['type']) {
            throw new RuntimeException('Earn this cannon by clearing the Brukkarg War expedition.');
        }
        $count=0;
        foreach ($base['buildings'] as $building) {
            if (in_array($building['type'],['spurtzcannon','diamondspurtz'],true)) { ++$count; }
        }
        if ($count >= $reward['limit']) { throw new RuntimeException('You have placed all the cannons earned in Brukkarg War.'); }
    }
}
