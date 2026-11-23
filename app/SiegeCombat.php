<?php
declare(strict_types=1);

/** A selected device is frozen in the invasion. Actual activation debits the keeper under the battle locks. */
final class SiegeCombat
{
    private const DURATION = [
        'decoy'=>[10,11,12,12,14,15,17,18,19,21], 'vacuum'=>[30,35,40,45,50,60,65,70,80,85],
        'jars'=>[0,0,0,0,0,0,0,0,0,0]
    ];
    private const RADIUS = ['decoy'=>[250,270,290,310,320,350,380,410,440,480], 'jars'=>[200,210,235,335,360,370,380,390,400,410]];
    private const DAMAGE = [1000,1500,2500,3500,4500,6500,9000,12500,17000,23500];
    private const DURABILITY = ['vacuum'=>[6000,7200,7800,9400,10300,11200,14600,20500,29000,44200],
        'jars'=>[2000,3000,4500,7000,11000,13500,16500,20500,25500,31500]];
    private const RATE = [25000,38153,58225,88856,135604,206945,292002,380324,495361,593977];
    private const TOWERS = ['sniper','cannon','tesla','laser','aerial','railgun','quaketower','magmatower','infernocannon','spurtzcannon','diamondspurtz'];

    public static function load(array $weapon, string $targetKind): array
    {
        $type = $weapon['type']; $level = max(1,min(10,(int)$weapon['level'])); $tier = $level-1;
        return [
            'type'=>$type,'level'=>$level,'radius'=>(self::RADIUS[$type][$tier]??0)/80,
            'duration'=>self::DURATION[$type][$tier],'used'=>false,'active'=>null,
            'reason'=>$type==='vacuum' && $targetKind!=='home'?'This camp or territory has no independent stockpile to siphon.':'',
            '_damage'=>$type==='decoy'?self::DAMAGE[$tier]*0.35:0,
            '_durability'=>(self::DURABILITY[$type][$tier]??0)*0.12,
            '_rate'=>$type==='vacuum'?self::RATE[$tier]/500:0,'_siphon'=>0.0
        ];
    }

    private static function distanceFromFoundation(array $b, float $x, float $y): float
    {
        $half=$b['_size']/2;
        return hypot(max(0,abs($b['x']-$x)-$half),max(0,abs($b['y']-$y)-$half));
    }

    public static function fire(array &$s,array &$base,string $type,float $x,float $y,mixed $request): void
    {
        if (!is_string($request)||!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$request)) { throw new RuntimeException('Select the siege device again.'); }
        if (!isset($s['siege']) || $s['siege']['type']!==$type) { throw new RuntimeException('No matching siege device was prepared for this invasion.'); }
        $weapon=&$s['siege'];
        if ($weapon['used']) {
            if ($weapon['_request']===$request) { return; }
            throw new RuntimeException('A siege device has already been activated in this invasion.');
        }
        if ($weapon['reason']!=='') { throw new RuntimeException($weapon['reason']); }
        if (($base['siege']['inventory'][$type]??0)<1) { throw new RuntimeException('This siege device is no longer in your inventory.'); }
        if (!YardSpace::contains($s,$x,$y,.4)) { throw new RuntimeException('Aim inside the battlefield.'); }
        $active=['x'=>$x,'y'=>$y,'startedAt'=>$s['elapsed'],'endsAt'=>$weapon['duration']>0?$s['elapsed']+$weapon['duration']:null];
        if ($type==='decoy') {
            if (!self::clearDecoy($s,$x,$y)) { throw new RuntimeException('Place the Decoy on clear ground.'); }
        } elseif ($type==='jars') {
            $active['targets']=[];
            foreach ($s['buildings'] as &$b) {
                if ($b['hp']<=0 || !in_array($b['type'],self::TOWERS,true) || self::distanceFromFoundation($b,$x,$y)>$weapon['radius']) { continue; }
                $active['targets'][]=$b['id'];
                $b['jarHp']=$b['jarMaxHp']=$weapon['_durability'];
            }
            unset($b);
            if (!$active['targets']) { throw new RuntimeException('Aim the Jars close to a living defense tower.'); }
        } else {
            $target=null;
            foreach ($s['buildings'] as $b) { if ($b['type']==='townhall'&&$b['hp']>0) { $target=$b;break; } }
            if (!$target) { throw new RuntimeException('The Vacuum needs a standing Town Hall.'); }
            $active['targetId']=$target['id']; $active['x']=$target['x']; $active['y']=$target['y'];
            $active['hp']=$active['maxHp']=$weapon['_durability'];
        }
        --$base['siege']['inventory'][$type];
        $weapon['used']=true; $weapon['active']=$active; $weapon['_request']=$request;
        $weapon['firedAt']=$s['elapsed'];
        self::event($s,'siege_launch',$active);
    }

    private static function clearDecoy(array $s,float $x,float $y):bool
    {
        if (!YardSpace::contains($s,$x,$y,.4)) { return false; }
        foreach ($s['buildings'] as $building) {
            if ($building['hp']>0 && !Catalog::isTrap($building['type']) && !($building['_untargetable']??false)
                && self::distanceFromFoundation($building,$x,$y)<0.375) { return false; }
        }
        return true;
    }

    /** Called on the first launched bot wave, under the same participant locks and online clock. */
    public static function botDeploy(array &$s,array &$base):void
    {
        if (empty($s['attacker']['is_bot']) || !isset($s['siege']) || $s['siege']['used'] || isset($s['siege']['_botTried'])) { return; }
        $s['siege']['_botTried']=true;
        $weapon=$s['siege']; $type=$weapon['type'];
        if ($weapon['reason'] || empty($base['siege']['inventory'][$type])) { return; }
        $live=array_values(array_filter($s['buildings'],static fn(array $b):bool=>$b['hp']>0));
        $towers=array_values(array_filter($live,static fn(array $b):bool=>in_array($b['type'],self::TOWERS,true)));
        $point=null;
        if ($type==='vacuum') {
            foreach ($live as $b) { if ($b['type']==='townhall') { $point=[$b['x'],$b['y']];break; } }
        } elseif ($type==='jars') {
            $best=0;
            foreach ($towers as $candidate) {
                $count=0;
                foreach ($towers as $b) { if (self::distanceFromFoundation($b,$candidate['x'],$candidate['y'])<=$weapon['radius']) { ++$count; } }
                if ($count>$best) { $best=$count; $point=[$candidate['x'],$candidate['y']]; }
            }
        } else {
            // Release guarded bunkers first, otherwise place the charge beside a tower or the Hall.
            $targets=array_merge(array_filter($live,static fn(array $b):bool=>$b['type']==='bunker'),$towers,
                array_filter($live,static fn(array $b):bool=>$b['type']==='townhall'));
            foreach ($targets as $b) {
                $offset=$b['_size']/2+0.6;
                foreach ([[1,0],[0,1],[-1,0],[0,-1],[1,1],[-1,1],[1,-1],[-1,-1]] as [$dx,$dy]) {
                    $x=$b['x']+$dx*$offset; $y=$b['y']+$dy*$offset;
                    if (self::clearDecoy($s,$x,$y)) { $point=[$x,$y];break 2; }
                }
            }
        }
        if ($point!==null) {
            self::fire($s,$base,$type,$point[0],$point[1],'bot-wave-device-'.(int)$s['id']);
        }
    }

    private static function event(array &$s,string $kind,array $where):void
    {
        BattleEngine::event($s,$kind,['kind'=>$s['siege']['type'],'x'=>$where['x'],'y'=>$where['y'],'radius'=>$s['siege']['radius'],'team'=>'attacker']);
    }

    public static function decoyNear(array $s, array $target, bool $foundation=false): ?array
    {
        $weapon=$s['siege']??null;
        if (($weapon['type']??null)!=='decoy'||!$weapon['active']) { return null; }
        $lure=$weapon['active'];
        $distance=$foundation?self::distanceFromFoundation($target,$lure['x'],$lure['y']):hypot($target['x']-$lure['x'],$target['y']-$lure['y']);
        return $distance<=$weapon['radius']?$lure+['id'=>'siege-decoy','_size'=>0]:null;
    }

    public static function tick(array &$s):void
    {
        $w=&$s['siege']; $a=&$w['active'];
        if (!$a) { return; }
        if ($w['type']==='jars') {
            $targets=[];
            foreach ($s['buildings'] as &$b) {
                if (!isset($b['jarHp'])) { continue; }
                if ($b['hp']>0 && $b['jarHp']>0) { $targets[]=$b['id'];continue; }
                unset($b['jarHp'],$b['jarMaxHp']);
            }
            unset($b);
            $a['targets']=$targets;
            if (!$targets) { self::end($s); }
        } elseif ($w['type']==='vacuum') {
            $alive=false;
            foreach ($s['buildings'] as $b) { if ($b['id']===$a['targetId']) { $alive=$b['hp']>0;break; } }
            if ($alive && $a['hp']>0) { $w['_siphon']+=$w['_rate']*BattleEngine::STEP; }
            if (!$alive||$a['hp']<=0||$s['elapsed']>=$a['endsAt']) { self::end($s); }
        } elseif ($s['elapsed']>=$a['endsAt']) {
            self::event($s,'siege_blast',$a);
            foreach ($s['buildings'] as $i=>$b) {
                $distance=hypot($b['x']-$a['x'],$b['y']-$a['y']);
                if ($b['hp']>0 && $distance<$w['radius']*0.65) { BattleEngine::hurtBuilding($s,$i,$w['_damage']*(1-$distance/$w['radius'])); }
            }
            foreach ($s['units'] as $i=>$unit) {
                $distance=hypot($unit['x']-$a['x'],$unit['y']-$a['y']);
                if ($unit['team']==='defender' && $unit['hp']>0 && $distance<$w['radius']) {
                    BattleEngine::hurtUnit($s,$i,$w['_damage']*(1-$distance/$w['radius']),'decoy');
                }
            }
            self::end($s);
        }
    }

    public static function absorbJar(array &$s,array &$tower,float $damage):bool
    {
        if (!isset($tower['jarHp'])) { return false; }
        $tower['jarHp']=max(0.0,$tower['jarHp']-$damage);
        if ($tower['jarHp']<=0) {
            self::event($s,'siege_break',$tower);
            unset($tower['jarHp'],$tower['jarMaxHp']);
        }
        return true;
    }

    public static function shootHose(array &$s,array &$tower):void
    {
        if (($s['siege']['type']??'')!=='vacuum'||empty($s['siege']['active'])||($tower['_targetMode']??'both')==='ground') { return; }
        $active=&$s['siege']['active'];
        if (hypot($tower['x']-$active['x'],$tower['y']-$active['y'])>$tower['_range']) { return; }
        $damage=$tower['_damage']; $tower['_cooldown']=$tower['_interval'];
        if ($tower['type']==='magmatower') { $damage *= 0.5+0.5*min(1,$tower['hp']/$tower['max_hp']); }
        if ($tower['type']==='aerial') {
            $damage*=0.5+0.5*min(1,$tower['hp']/$tower['max_hp']);
            if (++$tower['_shotsFired']>=$tower['_volley']) { $tower['_shotsFired']=0;$tower['_cooldown']=$tower['_reload']; }
        }
        $active['hp']=max(0.0,$active['hp']-$damage);
        BattleEngine::event($s,'shot',['x'=>$tower['x'],'y'=>$tower['y'],'tx'=>$active['x'],'ty'=>$active['y'],
            'sourceId'=>$tower['id'],'targetId'=>'siege-vacuum','amount'=>$damage,'kind'=>$tower['type'],'team'=>'defender']);
        if ($active['hp']<=0) { self::event($s,'siege_break',$active); self::end($s); }
    }

    public static function end(array &$s):void
    {
        if (empty($s['siege']['active'])) { return; }
        $w=&$s['siege'];
        if ($w['type']==='jars') {
            foreach ($s['buildings'] as &$building) { unset($building['jarHp'],$building['jarMaxHp']); }
            unset($building);
        }
        self::event($s,'siege_end',$w['active']);
        $w['active']=null; $w['endedAt']=$s['elapsed'];
    }

    /** Resolve the exact escrowed pumping time against stocks still present and space at home. */
    public static function settleLoot(array &$s,array &$attacker,array &$defender):void
    {
        $budget=(int)floor($s['siege']['_siphon']??0);
        if ($budget<=0) { return; }
        $cap=Game::capacity($attacker); $received=[];
        $available=[];
        foreach (['twig','pebble','putty','goo'] as $r) {
            $available[$r]=max(0,min((int)floor($defender['resources'][$r]),(int)floor($cap-$attacker['resources'][$r])));
            $received[$r]=0;
        }
        // Even sharing is deterministic. Redistribute any remainder among stocks with room.
        while ($budget>0 && ($open=array_filter($available))) {
            $share=max(1,intdiv($budget,count($open)));
            foreach ($open as $r=>$amount) {
                $moved=min($budget,$share,$amount);$received[$r]+=$moved;$available[$r]-=$moved;$budget-=$moved;
            }
        }
        foreach ($received as $r=>$amount) {
            $attacker['resources'][$r]+=$amount; $defender['resources'][$r]-=$amount; $s['result']['loot'][$r]+=$amount;
        }
        $s['result']['siege']['siphoned']=$received;
    }

    public static function view(array $weapon):array
    {
        return array_filter($weapon,static fn(string $key):bool=>!str_starts_with($key,'_'),ARRAY_FILTER_USE_KEY);
    }
}
