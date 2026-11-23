<?php
declare(strict_types=1);

/** IC3 and IC6 get the source resource-target group's 0.5 + 1.5 loot per damage. */
final class RaiderLoot
{
    private const TYPES=['twig','pebble','putty','goo'];
    private const NATIVE=['bone','coal','sulfur','magma'];

    public static function seed(array &$battle,array $party,array $defense):void
    {
        if (empty($party['army']['malphus']) && empty($party['army']['grokus'])) { return; }
        $target=$battle['target'];$kind=$target['kind'];$cavern=in_array($kind,['inferno','infernoRival'],true);
        $camp=in_array($kind,['inferno','tribe'],true);
        if (isset($target['event']) || (!$camp && !in_array($kind,['home','infernoRival'],true) && empty($target['mr2Outpost']))) { return; }
        $types=$cavern?self::NATIVE:self::TYPES;$vault=[];$bins=[];
        if ($camp) {
            $progress=$cavern?($party['campaign']??[]):($party['tribeCampaign']??[]);
            if (isset($progress['cleared'][$target['stage']])) { return; }
            foreach ($types as $r) { $vault[$r]=max(0,($target['reward'][$r]??0)-($progress['raided'][$target['stage']][$r]??0)); }
        } else {
            foreach ($types as $r) { $vault[$r]=max(0,(int)floor(($defense['resources'][$r]??0)/10)); }
            foreach ($defense['buildings'] as $b) {
                $i=array_search($b['type'],self::TYPES,true);
                if ($i!==false && $b['hp']>0 && $b['level']>0 && ($b['stored']??0)>=1) {
                    $bins[$b['id']]=['resource'=>$types[$i],'remaining'=>(int)floor($b['stored']),'taken'=>0];
                }
            }
        }
        $battle['_raiderLoot']=['camp'=>$camp,'types'=>$types,'vault'=>$vault,'taken'=>array_fill_keys($types,0),'bins'=>$bins,'hits'=>0];
    }

    public static function available(array $bag,array $building):bool
    {
        $i=array_search($building['type'],self::TYPES,true);
        if ($i!==false) {
            return $bag['camp']?$bag['vault'][$bag['types'][$i]]>0:!empty($bag['bins'][$building['id']]['remaining']);
        }
        return in_array($building['type'],['storage','townhall','mr2core'],true) && array_sum($bag['vault'])>0;
    }

    /** Reserve the currency and quantity earned by a direct hit. */
    public static function hit(array &$s,array $unit,array $building,float $damage):bool
    {
        if (!isset($s['_raiderLoot']) || !in_array($unit['type'],['malphus','grokus'],true) || $unit['team']!=='attacker' || $damage<=0) { return false; }
        $type=$building['type'];$id=$building['id'];$bag=&$s['_raiderLoot'];$i=array_search($type,self::TYPES,true);
        $amount=(int)floor(2*$damage);
        if ($i!==false && !$bag['camp']) {
            if (empty($bag['bins'][$id]['remaining'])) { return false; }
            $bin=&$bag['bins'][$id];$resource=$bin['resource'];$amount=min($amount,$bin['remaining']);$bin['remaining']-=$amount;$bin['taken']+=$amount;
        } else {
            if ($i!==false) { $resource=$bag['types'][$i]; }
            elseif (in_array($type,['storage','townhall','mr2core'],true)) {
                $open=array_keys(array_filter($bag['vault']));if (!$open) { return false; }
                $resource=$open[abs(crc32($unit['id'].'/'.$id.'/'.$bag['hits']))%count($open)];
            } else { return false; }
            $amount=min($amount,$bag['vault'][$resource]);$bag['vault'][$resource]-=$amount;$bag['taken'][$resource]+=$amount;
        }
        if ($amount<=0) { return false; }
        ++$bag['hits'];
        BattleEngine::event($s,'raid_loot',['sourceId'=>$unit['id'],'targetId'=>$id,'x'=>$building['x'],'y'=>$building['y'],'kind'=>$resource,'amount'=>$amount,'team'=>'attacker']);
        return !self::available($bag,$building);
    }

    /** Debit only what actually reaches the attacker's storage while both owners are locked. */
    public static function transfer(array &$s,array &$attacker,array &$defender,int $cap):void
    {
        $bag=$s['_raiderLoot']??null;if (!$bag || !$bag['hits']) { return; }
        $received=[];
        foreach ($bag['taken'] as $r=>$held) {
            $available=max(0,$bag['vault'][$r]+$held-($s['result']['loot'][$r]??0));
            $moved=min($available,$held,(int)floor($defender['resources'][$r]??0),max(0,(int)floor($cap-$attacker['resources'][$r])));
            if ($moved>0) { $attacker['resources'][$r]+=$moved;$defender['resources'][$r]-=$moved;$received[$r]=$moved; }
        }
        foreach ($defender['buildings'] as &$building) {
            $bin=$bag['bins'][$building['id']]??null;if (!$bin || !$bin['taken']) { continue; }
            $r=$bin['resource'];$moved=min($bin['taken'],(int)floor($building['stored']??0),max(0,(int)floor($cap-$attacker['resources'][$r])));
            if ($moved>0) { $attacker['resources'][$r]+=$moved;$building['stored']-=$moved;$received[$r]=($received[$r]??0)+$moved; }
        }unset($building);
        self::report($s,$received);
    }

    /** A successful hit can take part of the stage's finite prize even if the force retreats. */
    public static function campaign(array &$s,array &$yard,string $progressKey,int $cap):void
    {
        $bag=$s['_raiderLoot']??null;if (!$bag || !$bag['hits']) { return; }
        $stage=$s['target']['stage'];$progress=&$yard[$progressKey];
        if (isset($progress['cleared'][$stage])) { return; }
        $received=[];
        foreach ($bag['taken'] as $r=>$held) {
            $left=max(0,$s['target']['reward'][$r]-($progress['raided'][$stage][$r]??0));
            $moved=min($left,$held,max(0,(int)floor($cap-$yard['resources'][$r])));
            if ($moved>0) {
                $yard['resources'][$r]+=$moved;$progress['raided'][$stage][$r]=($progress['raided'][$stage][$r]??0)+$moved;$received[$r]=$moved;
            }
        }
        if ($received) { $s['result']['raiderLoot']=$received; }
    }

    private static function report(array &$s,array $received):void
    {
        if ($received) {
            $s['result']['raiderLoot']=$received;
            foreach ($received as $r=>$n) { $s['result']['loot'][$r]=($s['result']['loot'][$r]??0)+$n; }
        }
    }
}
