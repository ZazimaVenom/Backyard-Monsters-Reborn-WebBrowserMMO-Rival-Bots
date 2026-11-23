<?php
declare(strict_types=1);

final class SiegeWorkshop
{
    public const DEVICES = [
        'decoy'=>['Marilyn Monstroe','Lure nearby defending monsters to a decoy that explodes when its fuse burns out.'],
        'vacuum'=>['Loot-O-Tron 5000','Attach a vacuum to the enemy Town Hall to siphon supplies until it breaks or runs out of time.'],
        'jars'=>['Candy Jars','Enclose nearby defensive towers in glass. Towers must break free before they can resume firing.'],
    ];
    private const RESOURCES = ['twig','pebble','putty','goo'];
    private const FACILITIES = ['research'=>'siegelab','build'=>'siegefactory'];

    public static function buildings(): array
    {
        static $specs;
        if ($specs !== null) { return $specs; }
        $specs = [];
        $rows = [
            'siegelab'=>[134,'Chaos Lab','Research and improve three Chaos devices. Each Lab level opens the corresponding research tier.',
                [6000,12000,18000,24000,30000,40000,50000,60000,75000,90000],
                [144,216,288,432,576,720,864,1008,1152,1440],
                [2000,2880,3840,5220,7060,8640,10400,12000,14400,16800]],
            'siegefactory'=>[133,'Chaos Factory','Build and store one researched Chaos device for your next invasion.',
                [15000],[288],[2000]]
        ];
        foreach ($rows as $type=>[$id,$name,$description,$prices,$seconds,$health]) {
            $levels=[];
            foreach ($prices as $index=>$amount) {
                $levels[$index+1]=['cost'=>['twig'=>$amount,'pebble'=>$amount,'putty'=>0,'goo'=>0],
                    'buildTime'=>$seconds[$index],'hp'=>$health[$index],'townHall'=>$index<5?4:5];
            }
            $specs[$type] = ['name'=>$name,'description'=>$description,'originalId'=>$id,'size'=>2.6,'unlockLevel'=>4,
                'maxLevel'=>count($levels),'art'=>$type.'.png','canFortify'=>false,'levels'=>$levels,'limitByTownHall'=>[0,0,0,0,1,1]]+$levels[1];
        }
        $specs['siegefactory']['requiresBuilding']='siegelab';
        return $specs;
    }

    public static function price(string $type, string $lane, int $level): array
    {
        static $tiers;
        $tiers ??= require __DIR__.'/siege-tiers.php';
        if (!isset($tiers[$type][$lane][$level])) { throw new RuntimeException('Choose a valid Chaos device and level.'); }
        [$twig,$pebble,$putty,$goo,$seconds]=$tiers[$type][$lane][$level];
        return ['cost'=>compact('twig','pebble','putty','goo'),'seconds'=>$seconds];
    }

    private static function facility(array $base,string $type): ?array
    {
        foreach ($base['buildings'] as $building) {
            if ($building['type']===$type && (int)$building['level']>0) { return $building; }
        }
        return null;
    }

    private static function level(array $base,string $type): int
    {
        $facility=self::facility($base,$type);
        if (!$facility || (int)($facility['ready_at']??0)>0 || (float)$facility['hp'] < (float)$facility['max_hp']/2) { return 0; }
        return (int)$facility['level'];
    }

    public static function loadout(array $base): ?array
    {
        if (empty($base['siege']['inventory']) || !self::level($base,'siegefactory')) { return null; }
        foreach (self::DEVICES as $type=>$_) {
            $level=(int)($base['siege']['levels'][$type]??0);
            if (!empty($base['siege']['inventory'][$type]) && $level>0 && $level<=10) { return compact('type','level'); }
        }
        return null;
    }

    public static function awardRestriction(array $base): string
    {
        if (!self::level($base,'siegefactory')) { return 'Complete or repair the Chaos Factory to collect your device.'; }
        if (!empty($base['siege']['build']) || array_sum($base['siege']['inventory']??[])>0) {
            return 'Free the Chaos Factory slot to collect your device.';
        }
        return '';
    }

    public static function accrue(array &$base,int $now): void
    {
        if (empty($base['siege'])) { return; }
        $last=(int)($base['last_tick']??$now);
        foreach (self::FACILITIES as $lane=>$buildingType) {
            $job=$base['siege'][$lane]??null;
            if (!$job) { continue; }
            $facility=self::facility($base,$buildingType);
            if (!$facility || (float)$facility['hp']<=0) {
                $base['siege'][$lane]['ready_at']+=max(0,$now-max($last,$job['started_at']));
                continue;
            }
            if ($now < $job['ready_at']) { continue; }
            if ($lane==='research') { $base['siege']['levels'][$job['type']]=$job['level']; }
            else { $base['siege']['inventory'][$job['type']]=1; }
            $base['siege'][$lane]=null;
            ++$base['siege']['revision'][$lane];
        }
    }

    private static function restriction(array $base, string $lane, string $type, int $level): string
    {
        $facility=self::level($base,self::FACILITIES[$lane]);
        if (!$facility) {
            return 'Complete the '.($lane==='research'?'Chaos Lab':'Chaos Factory').' and keep it at half health or better.';
        }
        if (!empty($base['siege'][$lane])) { return 'Finish or cancel the current '.($lane==='research'?'study':'factory order').'.'; }
        if ($lane==='research') {
            if ($level > 10) { return 'Maximum research level.'; }
            if ($facility < $level) { return 'Upgrade the Chaos Lab to level '.$level.'.'; }
        } else {
            if ($level < 1) { return 'Unlock this device in the Chaos Lab.'; }
            if (array_sum($base['siege']['inventory']??[])>0) { return 'Use the prepared device to free the factory slot.'; }
        }
        return '';
    }

    public static function view(array $base,int $now,float $speed): array
    {
        $siege=$base['siege']??[];
        $out=['revision'=>['research'=>(int)($siege['revision']['research']??0),'build'=>(int)($siege['revision']['build']??0)],
            'research'=>null,'build'=>null,'devices'=>[], 'loadout'=>self::loadout($base)];
        foreach (self::FACILITIES as $lane=>$buildingType) {
            $job=$siege[$lane]??null;
            if ($job) {
                $facility=self::facility($base,$buildingType);
                $out[$lane]=['type'=>$job['type'],'level'=>$job['level'],'ready_at'=>$job['ready_at'],
                    'remaining'=>max(0,$job['ready_at']-$now),'paused'=>!$facility || $facility['hp']<=0];
                $capacity=Game::capacity($base);
                foreach ($job['paid'] as $r=>$paid) {
                    $free=max(0,(int)floor($capacity-($base['resources'][$r]??0)));
                    $out[$lane]['overflow'][$r]=max(0,$paid-$free);
                }
            }
        }
        foreach (self::DEVICES as $type=>[$name,$description]) {
            $level=(int)($siege['levels'][$type]??0);
            $device=['name'=>$name,'description'=>$description,'level'=>$level,'quantity'=>(int)($siege['inventory'][$type]??0)];
            foreach (self::FACILITIES as $lane=>$_) {
                $target=$level+($lane==='research'?1:0);
                $option=['level'=>$target,'reason'=>self::restriction($base,$lane,$type,$target),'cost'=>[], 'seconds'=>0];
                if ($target>=1 && $target<=10) {
                    $price=self::price($type,$lane,$target);
                    $option['cost']=$price['cost'];$option['seconds']=max(1,(int)ceil($price['seconds']/$speed));
                    if ($option['reason']==='') {
                        foreach ($price['cost'] as $r=>$value) {
                            if ($value>($base['resources'][$r]??0)) { $option['reason']='Gather the listed resources.';break; }
                        }
                    }
                }
                $device[$lane]=$option;
            }
            $out['devices'][$type]=$device;
        }
        return $out;
    }

    public static function act(array &$base,string $action,array $input,int $now,float $speed): array
    {
        $cancel=$action==='siegeCancel';
        $lane=$cancel?($input['lane']??null):match($action){'siegeBuild'=>'build','siegeResearch'=>'research',default=>null};
        if (!is_string($lane)||!isset(self::FACILITIES[$lane])) { throw new RuntimeException('Choose a valid Chaos workshop order.'); }
        $expected=$input['revision']??null;
        $revision=(int)($base['siege']['revision'][$lane]??0);
        if (!is_int($expected)||$expected!==$revision) { throw new RuntimeException('This workshop order changed. Check its current status.'); }
        if ($cancel) {
            $job=$base['siege'][$lane]??null;
            if (!$job) { throw new RuntimeException('There is no active order to cancel.'); }
            $capacity=Game::capacity($base);$credited=$overflow=[];
            foreach ($job['paid'] as $resource=>$amount) {
                $free=max(0,(int)floor($capacity-$base['resources'][$resource]));
                $credited[$resource]=min($amount,$free);$overflow[$resource]=$amount-$credited[$resource];
                $base['resources'][$resource]+=$credited[$resource];
            }
            $base['siege'][$lane]=null;$base['siege']['revision'][$lane]=$revision+1;
            return ['cancelled'=>true,'credited'=>$credited,'overflow'=>$overflow];
        }
        $type=$input['type']??null;
        if (!is_string($type)||!isset(self::DEVICES[$type])) { throw new RuntimeException('Choose a known Chaos device.'); }
        $level=(int)($base['siege']['levels'][$type]??0)+($lane==='research'?1:0);
        $reason=self::restriction($base,$lane,$type,$level);
        if ($reason!=='') { throw new RuntimeException($reason); }
        $price=self::price($type,$lane,$level);
        foreach ($price['cost'] as $resource=>$amount) {
            if (($base['resources'][$resource]??0)<$amount) { throw new RuntimeException('Gather the resources shown for this order.'); }
        }
        foreach ($price['cost'] as $resource=>$amount) { $base['resources'][$resource]-=$amount; }
        $ready=$now+max(1,(int)ceil($price['seconds']/$speed));
        $base['siege'][$lane]=['type'=>$type,'level'=>$level,'started_at'=>$now,'ready_at'=>$ready,'paid'=>$price['cost']];
        $base['siege']['revision'][$lane]=$revision+1;
        return ['cancelled'=>false,'type'=>$type,'level'=>$level,'ready_at'=>$ready];
    }
}
