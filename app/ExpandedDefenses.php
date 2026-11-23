<?php
declare(strict_types=1);

final class ExpandedDefenses
{
    public const QUAKE_WINDUP = 4.25;
    private const SPEEDS = ['infernocannon'=>[5,6,7,8,8,8,8],'magmatower'=>[14,15,16,17,18,19]];
    private const DEFINITIONS = [
        'spurtzcannon'=>[136,'Spurtz Cannon','A Brukkarg war trophy. Scatters volleys of living eggs. Some hatch into short-lived Spurtz that defend your yard.',[]],
        'diamondspurtz'=>[137,'Black Diamond Spurtz Cannon','The final Brukkarg war reward. Its armored coil fires larger volleys of living eggs.',[]],
        'railgun'=>[118,'Railgun','Punches a straight line through ground invaders. A target within its aiming range can expose monsters far behind it.',[0,0,0,0,0,2]],
        'quaketower'=>[129,'Quake Tower','Raises a heavy ram and drops it on the cavern floor. Ground monsters near the tower take the strongest shock.',[0,0,0,2,2,4,4]],
        'infernocannon'=>[130,'Blast Tower','Bombards ground invaders. A shell hits hardest at the center of its blast.',[0,2,3,3,4,4,6]],
        'magmatower'=>[132,'Magma Tower','Fires molten bolts at ground and air invaders. A direct hit sets a ground monster alight.',[0,0,0,1,2,2,3]],
    ];

    public static function buildings(): array
    {
        static $result;
        if ($result !== null) { return $result; }
        $result = [];
        foreach (require __DIR__.'/defense-tiers.php' as $type=>$rows) {
            [$id,$name,$description,$limits] = self::DEFINITIONS[$type];
            $reward = $type==='spurtzcannon' || $type==='diamondspurtz';
            $inferno = !($type==='railgun' || $reward);
            $levels = [];
            foreach ($rows as $index=>[$hp,$damage,$range,$rate,$splash,$r1,$r2,$r3,$r4,$seconds,$hall]) {
                $prices = array_map(static fn(int $price): int => (int)ceil($price / 100), [$r1,$r2,$r3,$r4]);
                $levels[$index + 1] = [
                    'hp'=>$hp,'damage'=>$damage,'range'=>$range / 40,'splash'=>$splash / 40,'attackInterval'=>$rate / 40,
                    'cost'=>array_combine(['twig','pebble','putty','goo'],$prices),'buildTime'=>(int)ceil($seconds / 300),
                    'townHall'=>$inferno ? $hall : min(5,$hall),
                ];
            }
            $result[$type] = $levels[1] + [
                'name'=>$name,'description'=>$description,'originalId'=>$id,'size'=>2.2,'art'=>$type.'.png',
                'levels'=>$levels,'ranges'=>array_column($levels,'range'),'maxLevel'=>count($levels),
                'limitByTownHall'=>$limits,'unlockLevel'=>$levels[1]['townHall'],'targetMode'=>$type==='magmatower'?'both':'ground',
                'emergenceReward'=>in_array($type,['magmatower','quaketower'],true),
                'brukkargReward'=>$reward,'canFortify'=>!$inferno && !$reward,'siteOnly'=>$inferno,'infernoOnly'=>$inferno,'defenseBehavior'=>$type,
            ];
        }
        return $result;
    }

    public static function fire(array &$state,array &$tower,?int $target): void
    {
        $type = $tower['_behavior'];
        $damage = (float)$tower['_damage'];
        if ($type === 'spurtzcannon' || $type === 'diamondspurtz') {
            self::fireSpurtz($state,$tower);
            return;
        }
        if ($type === 'quaketower') {
            if (!isset($tower['quakeImpactAt'])) {
                if ($target === null) { return; }
                $tower['quakeImpactAt'] = $state['elapsed'] + self::QUAKE_WINDUP;
                self::event($state,$tower,'quake_charge');
                return;
            }
            if ($state['elapsed'] < $tower['quakeImpactAt']) { return; }
            unset($tower['quakeImpactAt']);
            $tower['_cooldown'] = $tower['_interval'];
            if (isset($tower['jarHp']) && SiegeCombat::absorbJar($state,$tower,3 * $damage)) { return; }
            self::event($state,$tower,'quake_impact');
            self::areaDamage($state,$tower,$tower['x'],$tower['y'],$tower['_range'],$damage,1/3);
            return;
        }
        if ($target === null) {
            if (!empty($state['siege']['active'])) { SiegeCombat::shootHose($state,$tower); }
            return;
        }
        $damage *= self::healthFactor($tower);
        $tower['_cooldown'] = $tower['_interval'];
        if (isset($tower['jarHp']) && SiegeCombat::absorbJar($state,$tower,$damage * ($type === 'railgun' ? 3 : 1))) { return; }
        $unit = $state['units'][$target];
        $shot = ['targetId'=>$unit['id'],'tx'=>$unit['x'],'ty'=>$unit['y'],'amount'=>$damage];
        if ($type === 'railgun') {
            $dx = $unit['x'] - $tower['x'];
            $dy = $unit['y'] - $tower['y'];
            $distance = hypot($dx,$dy);
            $dx = $distance > 0 ? $dx / $distance : 1;
            $dy = $distance > 0 ? $dy / $distance : 0;
            // The archived rail trace consists of fifty 32-pixel segments and has a 20-pixel hit radius.
            $length = min(40.0,self::edgeDistance($state,$tower['x'],$dx),self::edgeDistance($state,$tower['y'],$dy));
            $shot += ['endX'=>$tower['x'] + $dx * $length,'endY'=>$tower['y'] + $dy * $length];
            self::event($state,$tower,'shot',$shot);
            foreach ($state['units'] as $i=>$other) {
                if (!self::groundAttacker($other)) { continue; }
                $x = $other['x'] - $tower['x'];
                $y = $other['y'] - $tower['y'];
                $along = $x * $dx + $y * $dy;
                if ($along >= 0 && $along <= $length + 0.00001 && abs($x * $dy - $y * $dx) <= .5) {
                    BattleEngine::hurtUnit($state,$i,$damage,$type);
                }
            }
            return;
        }
        $speed = self::SPEEDS[$type][max(0,min(count(self::SPEEDS[$type]),$tower['level'])-1)];
        $travel = max(BattleEngine::STEP, hypot($unit['x'] - $tower['x'],$unit['y'] - $tower['y']) / $speed);
        $state['_defenseShells'][] = $shot + ['type'=>$type,'impactAt'=>$state['elapsed']+$travel,'splash'=>$tower['_splash'],
            'sourceId'=>$tower['id'],'flameDps'=>$tower['_damage'] * .5];
        self::event($state,$tower,'shot',$shot + ['travel'=>$travel]);
    }

    private static function fireSpurtz(array &$state,array &$tower): void
    {
        $distances=[];
        foreach ($state['units'] as $i=>$unit) {
            if (!self::groundAttacker($unit,false)) { continue; }
            $distance=hypot($unit['x']-$tower['x'],$unit['y']-$tower['y']);
            if ($distance<=$tower['_range']) { $distances[$i]=$distance; }
        }
        if (!$distances) { return; }
        asort($distances,SORT_NUMERIC);
        $targets=array_slice(array_keys($distances),0,10);
        $volley=10*min(5,max(1,(int)$tower['level']))+($tower['type']==='diamondspurtz'?5:0);
        $remaining=(int)($tower['_roundsLeft']??$volley);
        // Five 40-Hz Flash frames per shot. Two rounds fit in one fixed server step.
        $shots=min(2,$remaining);
        for ($j=0;$j<$shots;$j++) {
            $index=$targets[($volley-$remaining)%count($targets)];
            $unit=$state['units'][$index];
            $tower['_shotSequence']=(int)($tower['_shotSequence']??0)+1;
            --$remaining;
            $damage=$tower['_damage'] * self::healthFactor($tower);
            if (isset($tower['jarHp']) && SiegeCombat::absorbJar($state,$tower,$damage*.25)) { continue; }
            $seed=($state['id']??0).'/'.$tower['id'].'/'.$tower['_shotSequence'];
            $spread=.2*$distances[$index];
            $margin=YardSpace::margin($state);
            $x=max(.25-$margin,min(31.75+$margin,$unit['x']+(2*self::fraction($seed.'/x')-1)*$spread));
            $y=max(.25-$margin,min(31.75+$margin,$unit['y']+(2*self::fraction($seed.'/y')-1)*$spread));
            $travel=max(BattleEngine::STEP,hypot($x-$tower['x'],$y-$tower['y'])/(10+$tower['level']));
            $shot=['tx'=>$x,'ty'=>$y,'amount'=>$damage,'travel'=>$travel,'volley'=>$j];
            $state['_defenseShells'][]=$shot+['type'=>$tower['type'],'sourceId'=>$tower['id'],'fixed'=>true,
                'splash'=>$tower['_splash'],'impactAt'=>$state['elapsed']+$travel+$j*.125,
                'hatch'=>self::fraction($seed.'/egg')>.5,'lifetime'=>2.5+2*self::fraction($seed.'/life')];
            self::event($state,$tower,'shot',$shot);
        }
        $tower['_roundsLeft']=$remaining?:$volley;
        $tower['_cooldown']=$remaining?BattleEngine::STEP:$tower['_interval'];
    }

    private static function fraction(string $seed): float
    {
        return hexdec(substr(hash('sha256',$seed),0,6))/16777216;
    }

    public static function expireHatchlings(array &$state): void
    {
        $live=false;
        foreach ($state['units'] as $i=>$unit) {
            if (!isset($unit['_eggExpires'])) { continue; }
            if ($state['elapsed'] < $unit['_eggExpires'] && $unit['hp']>0) { $live=true; continue; }
            if ($unit['hp']>0) { BattleEngine::event($state,'death',['x'=>$unit['x'],'y'=>$unit['y'],
                'targetId'=>$unit['id'],'team'=>'defender','kind'=>'spurtz']); }
            unset($state['units'][$i]);
        }
        $state['units']=array_values($state['units']);
        $state['_hasHatchlings']=$live;
    }

    /** Projectiles already released remain live after the firing building falls. */
    public static function landShots(array &$state): void
    {
        $pending = [];
        $unitIds = array_column($state['units'],'id');
        foreach ($state['_defenseShells'] as $shell) {
            if ($shell['impactAt'] > $state['elapsed'] + .00001) { $pending[]=$shell; continue; }
            if (!empty($shell['fixed'])) {
                $x=$shell['tx']; $y=$shell['ty'];
                self::areaDamage($state,['type'=>$shell['type']],$x,$y,$shell['splash'],$shell['amount'],.2);
                BattleEngine::event($state,'shell_impact',['x'=>$x,'y'=>$y,'kind'=>$shell['type'],'sourceId'=>$shell['sourceId'],
                    'radius'=>$shell['splash'],'team'=>'defender']);
                if ($shell['hatch']) {
                    $id='e'.(++$state['unitSeq']);
                    $egg=BattleService::makeUnit('spurtz',1,$id,'defender',$x,$y);
                    $egg['_eggExpires']=$state['elapsed']+$shell['lifetime'];
                    $state['units'][]=$egg;
                    $state['_hasHatchlings']=true;
                    BattleEngine::event($state,'emerge',['x'=>$x,'y'=>$y,'sourceId'=>$id,'kind'=>'spurtz','team'=>'defender']);
                }
                continue;
            }
            $target = array_search($shell['targetId'],$unitIds,true);
            $unit = $target === false ? null : $state['units'][$target];
            $x = $unit['x'] ?? $shell['tx'];
            $y = $unit['y'] ?? $shell['ty'];
            BattleEngine::event($state,'shell_impact',['x'=>$x,'y'=>$y,'kind'=>$shell['type'],'sourceId'=>$shell['sourceId'],
                'targetId'=>$shell['targetId'],'radius'=>$shell['splash'],'team'=>'defender']);
            if ($shell['type'] === 'infernocannon') {
                self::areaDamage($state,['type'=>$shell['type']],$x,$y,$shell['splash'],$shell['amount'],.2);
            } elseif ($unit && $unit['hp']>0 && BattleEngine::canHit('both',$unit,true)) {
                BattleEngine::hurtUnit($state,$target,$shell['amount'],'magmatower');
                if ($state['units'][$target]['hp'] > 0 && self::groundAttacker($unit,false) && !isset($unit['_flameDps'])) {
                    // Renew reuses the existing component and its cadence in MonsterBase.addStatusEffect.
                    $state['units'][$target]['_flameDps'] = $shell['flameDps'];
                    $state['units'][$target]['_flameNext'] = $state['elapsed'] + 1;
                    $state['units'][$target]['burning'] = true;
                }
            }
        }
        $state['_defenseShells'] = $pending;
    }

    private static function healthFactor(array $tower): float
    {
        return .5 + .5 * min(1,$tower['hp'] / max(1,$tower['max_hp']));
    }

    private static function edgeDistance(array $state,float $position,float $direction): float
    {
        if ($direction === 0.0) { return INF; }
        $margin=YardSpace::margin($state);
        return $direction > 0 ? (32+$margin-$position)/$direction : (-$margin-$position)/$direction;
    }

    private static function groundAttacker(array $unit,bool $splash=true): bool
    {
        return $unit['team'] === 'attacker' && $unit['hp'] > 0 && BattleEngine::canHit('ground',$unit,$splash);
    }

    private static function areaDamage(array &$state,array $tower,float $x,float $y,float $radius,float $damage,float $minimum): void
    {
        foreach ($state['units'] as $i=>$unit) {
            if (!self::groundAttacker($unit)) { continue; }
            $distance = hypot($unit['x'] - $x,$unit['y'] - $y);
            if ($distance <= $radius) {
                BattleEngine::hurtUnit($state,$i,$damage * max($minimum,1 - $distance / $radius),$tower['type']);
            }
        }
    }

    private static function event(array &$state,array $tower,string $event,array $extra=[]): void
    {
        BattleEngine::event($state,$event,$extra + ['x'=>$tower['x'],'y'=>$tower['y'],'sourceId'=>$tower['id'],
            'kind'=>$tower['type'],'team'=>'defender','radius'=>$tower['_range']]);
    }
}
