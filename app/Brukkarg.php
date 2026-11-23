<?php
declare(strict_types=1);

/** Five private event opponents share the regular expedition battle engine. */
final class Brukkarg
{
    public static function contains(int $stage):bool {return $stage>=901 && $stage<=905;}

    private static function rows():array
    {
        static $rows;
        if ($rows===null) {
            $rows=[];
            $source=json_decode(file_get_contents(__DIR__.'/brukkarg-layouts.json'),true,512,JSON_THROW_ON_ERROR);
            foreach ($source as $row) { $rows[$row['sourceId']]=$row; }
            $rows[905]=['sourceId'=>905,'tribe'=>'Brukkarg','tier'=>5,'buildings'=>[],'reward'=>array_fill_keys(['twig','pebble','putty','goo'],0)];
        }
        return $rows;
    }

    private static function name(int $id):string
    {
        return [901=>'Brukkarg Outpost',902=>'Brukkarg Barracks',903=>'Brukkarg Arsenal',904=>'Brukkarg Stronghold',905=>'Brukkarg Mirror Yard'][$id];
    }
    public static function score(array $base):int
    {
        return 1000*count($base['brukkargWar']['cleared']??[])+(int)($base['brukkargWar']['waves']??0);
    }
    public static function nextWave(array $base):?int
    {
        $waves=(int)($base['brukkargWar']['waves']??0);
        $clears=count($base['brukkargWar']['cleared']??[]);
        if (Game::townhallLevel($base)<5 || $waves>=25 || $waves>=5*($clears+1)) {return null;}
        return $waves+1;
    }
    public static function defended(array &$base,int $wave):bool
    {
        if (self::nextWave($base)!==$wave) {return false;}
        $base['brukkargWar']['waves']=$wave;
        if (self::score($base)>=4024 && !isset($base['brukkargWar']['mirror'])) {
            $base['brukkargWar']['mirror']=self::mirror($base);
        }
        return true;
    }
    public static function route(array $base):array
    {
        $eligible=Game::townhallLevel($base)>=5;
        $cards=[];
        foreach(self::rows() as $stage=>$row) {
            $record=$base['brukkargWar']['cleared'][$stage]??null;
            $cards[]=['stage'=>$stage,'name'=>self::name($stage),'tier'=>'Brukkarg War','tribe'=>'Brukkarg','event'=>true,'record'=>$record,
                'reward'=>$row['reward'],'open'=>$eligible && ($stage===901 || isset($base['brukkargWar']['cleared'][$stage-1])) && (int)($base['brukkargWar']['waves']??0)>=5*($stage-900)];
        }
        return ['eligible'=>$eligible,'camps'=>$cards,'cleared'=>count($base['brukkargWar']['cleared']??[]),
            'waves'=>(int)($base['brukkargWar']['waves']??0),'nextWave'=>self::nextWave($base),'score'=>self::score($base)];
    }

    public static function camp(array $base,int $stage,int $now):array
    {
        EventCalendar::checkStart($base,'brukkarg',$now);
        if (!self::contains($stage)) {throw new RuntimeException('Choose a Brukkarg expedition.');}
        $card=self::route($base)['camps'][$stage-901];
        if (!$card['open']) {throw new RuntimeException(Game::townhallLevel($base)<5?'Brukkarg War requires a level-five home Town Hall.':'Defend five waves per Brukkarg yard and clear the preceding yard first.');}
        $row=self::rows()[$stage];
        $yard=$stage===905?($base['brukkargWar']['mirror']??null):Tribes::yard($row);
        if (!isset($yard['buildings'])) {throw new RuntimeException('Your final Brukkarg mirror has not been unlocked.');}
        $damage=$base['brukkargWar']['damage'][$stage]??null;
        $until=(int)($damage['firstAt']??0)+86400;
        if ($damage && $until>$now) {
            foreach ($yard['buildings'] as &$b) {
                if (isset($damage['hp'][$b['id']])) {$b['hp']=min($b['max_hp'],max(0,$damage['hp'][$b['id']]));}
            }
            unset($b);
            $card['damageUntil']=$until;
        }
        return $card+$row+['eventYard'=>$yard];
    }

    private static function mirror(array $base):array
    {
        $buildings=[];
        foreach ($base['buildings'] as $b) {
            if ($b['level']<1) {continue;}
            $buildings[]=['id'=>(int)$b['id'],'type'=>$b['type'],'x'=>$b['x'],'y'=>$b['y'],'level'=>(int)$b['level'],
                'hp'=>$b['max_hp'],'max_hp'=>$b['max_hp'],'ready_at'=>0,'upgrade_to'=>null,'stored'=>0,'fortification'=>(int)($b['fortification']??0)];
        }
        return ['buildings'=>$buildings,'garrison'=>[],'research'=>[]];
    }

    public static function settle(array &$base,array &$result,array $target,array $buildings,int $startedAt,int $now):void
    {
        $stage=(int)$target['stage'];
        $won=!empty($result['hallDestroyed']);
        $first=$won && !isset($base['brukkargWar']['cleared'][$stage]);
        $result['attackerWon']=$won;
        if (!$won) {$result['winnerId']=null;}
        $result['target']=$target;
        $result['rankDelta']=['attacker'=>0,'defender'=>0];
        $result['campaign']=['event'=>'brukkarg','firstClear'=>$first,'nextStage'=>$first && $stage<905 ? $stage+1:null,'pending'=>[]];
        if (!$won && !isset($base['brukkargWar']['cleared'][$stage])) {
            $hp=[];
            foreach ($buildings as $b) { if ($b['hp']<$b['max_hp']) {$hp[(int)$b['id']]=(float)$b['hp'];} }
            $previous=$base['brukkargWar']['damage'][$stage]['firstAt']??0;
            $anchor=$previous>0 && $previous+86400>$startedAt ? $previous : $startedAt;
            if ($hp && $now<$anchor+86400) { $base['brukkargWar']['damage'][$stage]=['firstAt'=>$anchor,'hp'=>$hp]; }
            else { unset($base['brukkargWar']['damage'][$stage]); }
        }
        if (!$won) {return;}
        unset($base['brukkargWar']['damage'][$stage]);
        $record=$base['brukkargWar']['cleared'][$stage]??['firstAt'=>$now,'best'=>0];
        $record['best']=max($record['best'],$result['destruction']);
        $base['brukkargWar']['cleared'][$stage]=$record;
        if (!$first) {return;}
        $count=count($base['brukkargWar']['cleared']);
        $result['campaign']['spurtz']=SpurtzRewards::award($base,$count);
        $progress=Tribes::progress($base);
        foreach($target['reward'] as $resource=>$amount){$progress['pending'][$resource]=($progress['pending'][$resource]??0)+$amount;}
        $base['tribeCampaign']=$progress;
        $result['loot']=Tribes::transferSupplies($base);
        $result['campaign']['pending']=$base['tribeCampaign']['pending'];
    }
}
