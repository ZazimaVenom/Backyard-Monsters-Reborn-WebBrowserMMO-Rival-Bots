<?php
declare(strict_types=1);

/** The two private seven-day defenses and their permanent, placeable victory totems. */
final class WildMonsterInvasions
{
    private const DAYS = 604800;
    private const MILESTONES = [1,10,20,30,31,32];
    private const EDGE = ['r','b','l','t'];
    private const SPECIES = [
        'C1'=>'pokey','C2'=>'octo','C3'=>'bolt','C4'=>'fink','C5'=>'eyera','C6'=>'ichi','C7'=>'bandito',
        'C8'=>'fang','C9'=>'brain','C10'=>'crabatron','C11'=>'projectx','C12'=>'dave','C13'=>'wormzer','C14'=>'teratorn','C15'=>'zafreeti',
        'IC1'=>'spurtz','IC2'=>'zagnoid','IC3'=>'malphus','IC4'=>'valgos','IC5'=>'balthazar','IC6'=>'grokus','IC7'=>'sabnox','IC8'=>'kingwormzer',
    ];
    // InvasionMonsterStats uses its own pre-rebalance Inferno health and damage tiers.
    private const NATIVE = [
        'spurtz'=>[[400,425,450,475,510,550],[120,130,140,150,160,175]],
        'zagnoid'=>[[1500,1620,1760,1900,2150,2300],[80,85,90,95,100,110]],
        'malphus'=>[[450,470,500,540,580,620],[100,105,110,120,130,140]],
        'valgos'=>[[715,730,750,765,780,800],[490,530,580,645,700,775]],
        'balthazar'=>[[800,840,880,920,965,1125],[400,430,460,490,520,560]],
        'grokus'=>[[1600,1750,1900,2100,2300,2500],[400,425,450,475,500,550]],
        'sabnox'=>[[920,960,1000,1050,1100,1200],[500,525,550,575,600,650]],
        'kingwormzer'=>[[2500,2600,2700,2900,3100,3400],[900,960,1030,1120,1220,1350]],
    ];
    private const GUARDIANS = [1=>'gorgo',2=>'drull',3=>'fomor'];
    private const GUARDIAN_HEALTH = [
        'gorgo'=>[40000,80000,120000,140000,160000,200000], 'drull'=>[12000,20000,36000,42000,52000,60000],
        'fomor'=>[15000,17500,20000,22500,25000,40000],
    ];

    public static function groups(int $event, int $stage, int $rotation=0): array
    {
        static $waves;
        $waves ??= json_decode(file_get_contents(__DIR__.'/wmi-waves.json'),true,512,JSON_THROW_ON_ERROR);
        $round=$waves[$event][$stage-1] ?? null;
        if ($round===null) { throw new RuntimeException('Choose a valid Wild Monster Invasion wave.'); }
        $groups=[]; $delay=0;
        foreach ($round as $entry) {
            if (is_int($entry)) { $delay+=$entry; continue; }
            $guardian=$entry['type']==='guardian';
            $pack=$entry['wave'][0] ?? null;
            $angle=$guardian ? $entry['angle'] : $pack[4];
            $groups[]=['type'=>$guardian ? self::GUARDIANS[$entry['guardianID']] : self::SPECIES[$pack[0]],
                'count'=>$guardian ? 1 : $pack[2], 'edge'=>self::EDGE[(intdiv($angle,90)+$rotation)%4],
                'delay'=>$delay,'level'=>$entry['level'],'powerup'=>$entry['powerup']??0,'rage'=>$entry['rage']??0,
                'guardianHP'=>$entry['health']??null];
            $delay=0;
        }
        return $groups;
    }

    public static function view(array $base, int $now): array
    {
        $events=[];
        foreach ([1=>121,2=>131] as $event=>$item) {
            $run=$base['wmi'][$event]??[];
            $deadline=(int)($run['deadline']??0); $cleared=(int)($run['cleared']??0);
            $restart=$cleared===32 || ($deadline>0 && $now>=$deadline);
            $stage=$restart ? 1 : $cleared+1;
            $tier=0;
            foreach ($base['decorations']??[] as $piece) {
                if ($piece['item']===$item) { $tier=max($tier,(int)($piece['tier']??1)); }
            }
            $events[$event]=['cleared'=>$cleared,'stage'=>$stage,'deadline'=>$deadline,'restart'=>$restart,
                'run'=>(int)($run['run']??0),'tier'=>$tier,'item'=>$item,'nextGroups'=>self::groups($event,$stage)];
        }
        return $events;
    }

    public static function start(array $yard,array $owner,array $input,int $now,float $defense,array $network): array
    {
        $event=$input['event']??null; $stage=$input['stage']??null;
        if (!is_int($event) || !in_array($event,[1,2],true) || !is_int($stage)) { throw new RuntimeException('Choose a Wild Monster Invasion wave.'); }
        EventCalendar::checkStart($yard,'wmi'.$event,$now);
        $view=self::view($yard,$now)[$event];
        if ($view['stage']!==$stage || $view['run']!==($input['run']??null)) { throw new RuntimeException('This run has changed. Open the invasion calendar again.'); }
        if (Game::livingLevel($yard,'townhall')<1) { throw new RuntimeException('Repair your Town Hall before defending this wave.'); }
        $groups=self::groups($event,$stage,random_int(0,3)); $wave=[]; $approaches=[];
        foreach ($groups as $g) { $wave[$g['type']]=($wave[$g['type']]??0)+$g['count']; }
        $s=Baiter::defenseState($yard,$owner,$wave,$groups[0]['edge'],$now,$defense,$network,true);
        foreach (array_unique(array_column($groups,'edge')) as $edge) { $approaches[$edge]=Baiter::entryPoints($s,$edge,true); }
        $points=Seasonal::view($yard,true,$now)['horse']['points'];
        $scale=.4;
        foreach ([1000000,3000000,6000000,15000000,50000000] as $threshold) { if ($points>$threshold) { $scale+=.1; } }
        $new=$view['deadline']===0 || $view['restart'];
        $s['wmiWave']=['event'=>$event,'stage'=>$stage,'run'=>$view['run']+($new?1:0),'deadline'=>$new?$now+self::DAYS:$view['deadline'],
            'requestedRun'=>$view['run'],'groups'=>$groups,'approaches'=>$approaches,'next'=>0,'due'=>0,'lastRelease'=>-1,'scale'=>round($scale,1)];
        $s['target']=['kind'=>'wmi','name'=>'Wild Monster Invasion '.($event===1?'I':'II')];
        $s['remaining']=$wave;
        $s['duration']=max($s['duration'],array_sum(array_column($groups,'delay'))+120);
        return $s;
    }

    public static function rememberStart(array &$base,array $s): void
    {
        $wave=$s['wmiWave']; $event=$wave['event'];
        if (($base['wmi'][$event]['run']??0)!==$wave['run']) {
            $base['wmi'][$event]=['run'=>$wave['run'],'deadline'=>$wave['deadline'],'cleared'=>0];
        }
    }

    public static function attacker(array $g,string $id,float $x,float $y,float $scale,float $elapsed): array
    {
        $type=$g['type']; $level=$g['level'];
        if ($g['guardianHP']!==null) {
            $stats=Catalog::championStats($type,$level);
            $ratio=min(1.0,$g['guardianHP']/self::GUARDIAN_HEALTH[$type][$level-1]);
            return BattleService::makeChampionUnit(['type'=>$type,'level'=>$level,'hp'=>$stats['hp']*$ratio],$id,'attacker',$x,$y);
        }
        $u=BattleService::makeUnit($type,$level,$id,'attacker',$x,$y,null,$g['powerup']);
        $native=self::NATIVE[$type]??null;
        if ($native && $g['powerup']>0) { $u['invasionPower']=$g['powerup']; }
        if ($type==='balthazar') { $u['_targetMode']=$g['powerup']>0?'both':'ground'; }
        $u['hp']=$u['max_hp']=max(1,(int)(($native[0][$level-1]??$u['max_hp'])*$scale));
        $u['_damage']=(int)(($native[1][$level-1]??$u['_damage'])*$scale);
        $u['_heal']*=$scale;
        if ($g['rage']>0) {
            $u['enraged']=true; $u['_rageEnd']=$elapsed+$g['rage'];
            $u['_speed']*=2; $u['_interval']/=2;
        }
        return $u;
    }

    public static function advance(array &$s, int $now): void
    {
        if ($s['status']!=='active'||$now<$s['launch_at']) { return; }
        $w=&$s['wmiWave']; $s['launched']=true;
        $limit=min((float)($now-$s['launch_at']),$s['elapsed']+2.0,(float)$s['duration']);
        while ($s['status']==='active') {
            $elapsed=$s['elapsed'];
            $alive=false;
            foreach ($s['units'] as &$unit) {
                if (!empty($unit['enraged']) && $elapsed >= $unit['_rageEnd']) {
                    $unit['_speed']/=2; $unit['_interval']*=2; $unit['enraged']=false;
                }
                if ($unit['team']==='attacker' && $unit['hp']>0) { $alive=true; }
            }
            unset($unit);
            $g=$w['groups'][$w['next']]??null;
            if ($g && ($elapsed >= $w['due'] || !$alive) && $elapsed >= $w['lastRelease']+1) {
                $points=$w['approaches'][$g['edge']];
                for ($i=0;$i<$g['count'];++$i) {
                    $p=$points[$s['unitSeq']%count($points)]; $id='w'.(++$s['unitSeq']);
                    $unit=self::attacker($g,$id,$p['x'],$p['y'],$w['scale'],$elapsed);
                    $s['units'][]=$unit;
                    BattleEngine::event($s,'deploy',['sourceId'=>$id,'kind'=>$g['type'],'x'=>$p['x'],'y'=>$p['y'],'team'=>'attacker','champion'=>!empty($unit['champion'])]);
                }
                $s['remaining'][$g['type']]-=$g['count']; $w['lastRelease']=$elapsed;
                ++$w['next']; $w['due']=$elapsed+($w['groups'][$w['next']]['delay']??0);
            }
            if ($elapsed+BattleEngine::STEP>$limit) { break; }
            BattleEngine::advance($s,$elapsed+BattleEngine::STEP);
        }
        if ($s['status']==='finished') {
            $damage=BattleEngine::captureProgress($s);
            $held=$damage<90 && $w['next']===count($w['groups']) && !array_filter($s['units'],fn($u)=>$u['team']==='attacker' && $u['hp']>0);
            Baiter::setResult($s,$s['result']['reason'],$held?'held':'overrun');
            $s['result']['destruction']=round($damage,2);
        }
    }

    public static function applyDefenderLosses(array &$base,array $s,int $now): void
    {
        BattleService::settleGarrison($s,$base);
        foreach ($s['units'] as $u) {
            if ($u['team']!=='defender'||empty($u['champion'])||empty($base['champions'][$u['type']])||!empty($base['champions'][$u['type']]['battle_id'])) { continue; }
            $loss=max(0,$u['_initial_hp']-$u['hp']);
            if ($loss>0) {
                $owned=&$base['champions'][$u['type']]; $owned['hp']=max(0,$owned['hp']-$loss); $owned['last_heal_at']=$now; unset($owned);
            }
        }
    }

    public static function settle(array &$base,array $s,int $now): void
    {
        MonsterBlitz::applyDamage($base,$s);
        self::applyDefenderLosses($base,$s,$now);
        $w=$s['wmiWave']; $event=$w['event']; $run=$base['wmi'][$event]??null;
        if ($s['result']['outcome']!=='held' || !$run || $w['run']!==$run['run'] || $w['stage']!==$run['cleared']+1) { return; }
        $stage=$w['stage']; $base['wmi'][$event]['cleared']=$stage;
        $milestone=array_search($stage,self::MILESTONES,true);
        if ($milestone!==false) { self::award($base,$event,$milestone+1); }
    }

    public static function award(array &$base,int $event,int $tier): void
    {
        $item=$event===1?121:131;
        $base['decorations']??=[];
        foreach ($base['decorations'] as &$piece) {
            if ($piece['item']===$item) { $piece['tier']=max($tier,$piece['tier']??1); return; }
        }
        unset($piece);
        $id=$base['decorations']?max(array_column($base['decorations'],'id'))+1:1;
        $base['decorations'][]=['id'=>$id,'item'=>$item,'tier'=>$tier,'receipt'=>'wmi-victory-totem-'.$event];
    }

    /** A real invasion takes precedence over a private event, even if its owner left the server. */
    public static function endForRaid(array &$base,int $now): void
    {
        if (($base['baiterPractice']['state']['status']??null)!=='active' || !isset($base['baiterPractice']['state']['wmiWave'])) { return; }
        $session=&$base['baiterPractice'];
        Baiter::siren($session['state']);
        self::settle($base,$session['state'],$now);
        ++$session['revision'];
    }
}
