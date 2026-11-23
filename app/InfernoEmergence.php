<?php
declare(strict_types=1);

/** INFERNO_EMERGENCE_EVENT and INFERNO_PORTAL_ATTACK, played at a keeper's own surface entrance. */
final class InfernoEmergence
{
    private const INTERMISSION = 43200;
    private const WAVES = [
        // Species, signed maximum (positive rounds up, negative down), original spawn spread.
        1=>[['spurtz',25,400],['spurtz',25,400],['zagnoid',-50,500],['malphus',-15,300]],
        2=>[['spurtz',45,400],['zagnoid',60,500],['malphus',-25,300],['balthazar',-10,500]],
        3=>[['spurtz',65,400],['zagnoid',60,500],['sabnox',-15,300],['balthazar',-15,400],['valgos',10,600]],
        4=>[['zagnoid',65,500],['grokus',-30,300],['malphus',40,500],['balthazar',-20,500],['sabnox',-20,350]],
        5=>[['zagnoid',70,600],['sabnox',-30,300],['balthazar',20,500],['grokus',30,300],['kingwormzer',-25,700]],
    ];

    public static function groups(int $stage,int $level): array
    {
        $scale=$level<=30 ? max(.01,$level/60-.25) : min(1,($level-30)/16+.25);
        $groups=[];
        foreach (self::WAVES[$stage] ?? [] as [$species,$max,$spread]) {
            $quantity=$max>0 ? (int)ceil($max*$scale) : (int)floor(-$max*$scale);
            if ($quantity>0) { $groups[]=[$species,$quantity,$spread]; }
        }
        return $groups;
    }

    public static function view(array $base,int $now): array
    {
        $save=$base['emergence'] ?? [];
        $stage=(int)($save['stage'] ?? 0);
        $pending=!empty($save['pending']);
        $resumes=(int)($save['raisedAt'] ?? 0)+self::INTERMISSION;
        $health=0.;$capacity=0.;
        foreach ($base['buildings'] as $b) { $health+=$b['hp'];$capacity+=$b['max_hp']; }
        $ready=$capacity>0 && $health>.1*$capacity;
        $allowed=Game::livingLevel($base,'townhall')>=5 || $stage>0;
        $next=$pending ? $stage : min(5,$stage+1);
        $reason='';
        if ($stage===5 && !$pending) { $reason='All five earthquakes have passed.'; }
        elseif (!$allowed) { $reason='A working level 5 Town Hall opens Inferno Emergence.'; }
        elseif (!$ready) { $reason='Repair your yard above 10% health to face the next attack.'; }
        elseif ($stage>0 && !$pending && $now<$resumes) { $reason='The next earthquake is still gathering strength.'; }
        $groups=self::groups($next,Seasonal::yardProgression($base)['level']);
        $wave=[];
        foreach ($groups as [$type,$qty]) { $wave[$type]=($wave[$type]??0)+$qty; }
        return ['stage'=>$stage,'pending'=>$pending,'next'=>$next,'ready'=>$reason==='','reason'=>$reason,
            'nextAt'=>$stage>0 && !$pending && $stage<5 ? $resumes : 0,
            'complete'=>$stage===5 && !$pending,'wave'=>$wave,'last'=>$save['last']??null];
    }

    public static function raisePortal(array &$base,array $input,int $now): void
    {
        $prior=$input['previousStage']??null;
        if (!is_int($prior) || $prior<0 || $prior>4) { throw new RuntimeException('Select a valid earthquake.'); }
        $view=self::view($base,$now);
        if ($view['stage']===$prior+1 && $view['pending']) { return; }
        if ($view['stage']!==$prior || $view['pending']) { throw new RuntimeException('The entrance has changed. Reopen the event.'); }
        if (!$view['ready']) { throw new RuntimeException($view['reason']); }
        if (($base['baiterPractice']['state']['status']??null)==='active') { throw new RuntimeException('Finish the current defense first.'); }
        $base['emergence']['stage']=$prior+1;
        $base['emergence']['pending']=true;
        $base['emergence']['raisedAt']=$now;
    }

    public static function start(array $base,array $owner,array $input,int $now,float $defense,array $network): array
    {
        $view=self::view($base,$now);
        if (!$view['pending'] || ($input['stage']??null)!==$view['stage']) { throw new RuntimeException('There is no matching ambush at the entrance.'); }
        if (!$view['ready']) { throw new RuntimeException($view['reason']); }
        $progress=Seasonal::yardProgression($base);
        $s=Baiter::defenseState($base,$owner,$view['wave'],'tl',$now,$defense,$network,true);
        $s['formatVersion']=max(8,$s['formatVersion']);
        $s['target']=['kind'=>'emergence','name'=>'Inferno Emergence'];
        $s['attacker']['name']='Moloch';
        $s['emergenceWave']=['stage'=>$view['stage'],'groups'=>self::groups($view['stage'],$progress['level']),
            'power'=>self::power($progress['points']),'seed'=>random_int(1,2147483646)];
        $s['_approach']=[['x'=>.5,'y'=>14.3]];
        $s['remaining']=$view['wave'];
        return $s;
    }

    public static function power(int $points): float
    {
        foreach ([50000000=>.9,15000000=>.8,6000000=>.7,3000000=>.6,1000000=>.5] as $threshold=>$value) {
            if ($points>$threshold) { return $value; }
        }
        return .4;
    }

    public static function advance(array &$s,int $now): void
    {
        if ($s['status']!=='active'||$now<$s['launch_at']) { return; }
        if (!$s['launched']) {
            $s['launched']=true;
            $wave=$s['emergenceWave'];
            $rand=new Random\Randomizer(new Random\Engine\Mt19937($wave['seed']));
            $bearing=deg2rad(186);
            foreach ($wave['groups'] as [$type,$count,$spread]) {
                // Source positions lie outside the yard. Project them onto its western walkable boundary.
                $margin=YardSpace::margin($s);$radius=800+$margin*50+$spread*.5;
                for ($i=0;$i<$count;$i++) {
                    $angle=deg2rad($rand->getInt(0,359));$offset=$rand->getInt(0,intdiv($spread,2));
                    $x=max(.5-$margin,min(31.5+$margin,16+(cos($bearing)*$radius+cos($angle)*$offset)/50));
                    $y=max(.5-$margin,min(31.5+$margin,16+(sin($bearing)*$radius+sin($angle)*$offset)/50));
                    $id='w'.(++$s['unitSeq']);
                    $u=BattleService::makeUnit($type,1,$id,'attacker',$x,$y);
                    $u['hp']=$u['max_hp']=max(1,(int)round($u['max_hp']*$wave['power']));
                    $u['_damage']=max(1,(int)round($u['_damage']*$wave['power']));
                    $s['units'][]=$u;
                    BattleEngine::event($s,'deploy',['x'=>$x,'y'=>$y,'sourceId'=>$id,'kind'=>$type,'team'=>'attacker']);
                }
            }
            $s['remaining']=array_fill_keys(array_keys($s['wave']),0);
        }
        BattleEngine::advance($s,min((float)($now-$s['launch_at']),$s['elapsed']+2.0));
        if ($s['status']==='finished') {
            $held=$s['result']['reason']==='army_defeated' && BattleEngine::captureProgress($s)<90;
            Baiter::setResult($s,$s['result']['reason'],$held?'held':'overrun');
        }
    }

    public static function settle(array &$base,array $s,int $now): void
    {
        $stage=$s['emergenceWave']['stage'];
        if (empty($base['emergence']['pending']) || $base['emergence']['stage']!==$stage) { return; }
        MonsterBlitz::applyDamage($base,$s);
        WildMonsterInvasions::applyDefenderLosses($base,$s,$now);
        $base['emergence']['pending']=false;
        $base['emergence']['last']=['stage'=>$stage,'outcome'=>$s['result']['outcome']];
    }

    public static function freePassage(array $base): bool
    {
        return ($base['emergence']['stage']??0)===5 && empty($base['emergence']['pending']);
    }
}
