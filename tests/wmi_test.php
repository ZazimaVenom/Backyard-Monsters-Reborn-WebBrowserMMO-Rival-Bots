<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never { throw new ErrorException($message,0,$severity); });
$checks=0;
function wcheck(bool $v,string $label):void {global $checks;if(!$v)throw new RuntimeException($label);++$checks;}
function wreject(callable $fn):void {try{$fn();}catch(RuntimeException){wcheck(true,'correctly rejected');return;}throw new LogicException('Accepted an invalid event action');}
$expected=[
 1=>[5,9,10,20,10,7,60,48,40,16,30,46,46,60,35,40,75,75,70,71,45,31,66,105,210,91,30,160,40,71,309,340],
 2=>[10,12,16,30,32,17,55,45,48,45,70,68,84,34,96,60,99,110,240,150,90,280,90,210,90,150,110,120,75,152,404,458]
];
$groupCounts=$guardianCounts=$rageCounts=[];
foreach($expected as $event=>$rounds) {
    $groupsTotal=$guardian=$rage=0;
    foreach ($rounds as $index=>$total) {
        $groups=WildMonsterInvasions::groups($event,$index+1);
        wcheck(array_sum(array_column($groups,'count'))===$total,'source roster wave '.($index+1));
        $rotated=WildMonsterInvasions::groups($event,$index+1,1);
        foreach ($groups as $i=>$g) {
            $groupsTotal++;if($g['guardianHP']!==null)$guardian++;if($g['rage'])$rage++;
            $unit=WildMonsterInvasions::attacker($g,'w1',1.0,2.0,.4,0);
            wcheck($unit['hp']>0 && $unit['hp']<=$unit['max_hp'] && $unit['level']===$g['level'] && $unit['team']==='attacker','authoritative combat template');
            wcheck($g['edge']!==$rotated[$i]['edge'] && $g['delay']===$rotated[$i]['delay'],'one common 90° rotation');
        }
    }
    $groupCounts[]=$groupsTotal;$guardianCounts[]=$guardian;$rageCounts[]=$rage;
}
wcheck($groupCounts===[142,248] && $guardianCounts===[9,0] && $rageCounts===[16,111],'complete original source groups');
$now=time();$owner=['name'=>'Test defender','id'=>8];$base=['buildings'=>[['id'=>1,'type'=>'townhall','level'=>1,'hp'=>4000,'max_hp'=>4000,'x'=>16,'y'=>16,'ready_at'=>0]],'garrison'=>[],'army'=>[],'research'=>[],'champions'=>[],'activeChampion'=>null,'lab'=>['levels'=>[]]];$view=WildMonsterInvasions::view($base,$now);
wcheck($view[1]['stage']===1 && $view[2]['deadline']===0 && !isset($base['wmi']),'legacy yard read-only');
$input=['event'=>1,'stage'=>1,'run'=>0];$fresh=WildMonsterInvasions::start($base,$owner,$input,$now,0,[]);
wcheck($fresh['wave']===['octo'=>5] && $fresh['wmiWave']['deadline']===$now+604800,'first private seven-day run');
WildMonsterInvasions::rememberStart($base,$fresh);
$wrong=$input;$wrong['stage']=3;wreject(fn()=>WildMonsterInvasions::start($base,$owner,$wrong,$now,0,[]));
$wrong=['event'=>2,'stage'=>1,'run'=>9];wreject(fn()=>WildMonsterInvasions::start($base,$owner,$wrong,$now,0,[]));
Baiter::advance($fresh,$now+2);wcheck(count($fresh['units'])===0,'countdown');
Baiter::advance($fresh,$now+3);wcheck(count($fresh['units'])===5 && $fresh['remaining']['octo']===0,'correct first pack');
$summon=$fresh;$summon['unitSeq']++;$summon['units'][]=BattleService::makeUnit('spurtz',1,'d1','defender',14,14);Baiter::siren($summon);
wcheck($summon['result']['spawned']===5 && $summon['result']['surviving']===5,'home Spurtz Cannon defenders are not counted in the attacking pack');
$loss=$fresh;$loss['duration']=.25;Baiter::advance($loss,$now+5);wcheck($loss['status']==='finished'&&$loss['result']['outcome']==='overrun','survivor at timeout defeats wave');
$weak=$fresh;
foreach ($weak['buildings'] as &$b) {$b['hp']=$b['max_hp']*.1;}unset($b);
foreach ($weak['units'] as &$u) {$u['hp']=0;}unset($u);
Baiter::advance($weak,$now+5);wcheck($weak['result']['outcome']==='overrun','90% destruction defeats keeper');
foreach($fresh['units'] as &$u){$u['hp']=0;}unset($u);
Baiter::advance($fresh,$now+5);
wcheck($fresh['result']['outcome']==='held'&&$fresh['result']['rankDelta']['defender']===0,'every hostile defeated');
WildMonsterInvasions::settle($base,$fresh,$now);
wcheck($base['wmi'][1]['cleared']===1 && Decorations::storage($base)[0]['tier']===1,'first wave awarded a real storage piece');
$base['decorations'][0]['x']=4;$base['decorations'][0]['y']=3;
foreach([10,20,30,31,32] as $i=>$stage){
    $fixture=$fresh;$fixture['wmiWave']['stage']=$stage;$base['wmi'][1]['cleared']=$stage-1;
    WildMonsterInvasions::settle($base,$fixture,$now);
    wcheck(count($base['decorations'])===1 && Decorations::visible($base)[0]['tier']===$i+2 && $base['decorations'][0]['x']===4,'placed totem upgrades in place');
}
$replay=WildMonsterInvasions::start($base,$owner,['event'=>1,'stage'=>1,'run'=>1],$now+86400,0,[]);
WildMonsterInvasions::rememberStart($base,$replay);wcheck($base['wmi'][1]['run']===2 && $base['wmi'][1]['cleared']===0 && $base['decorations'][0]['tier']===6,'replay preserves highest tier');
$base['wmi'][2]=['run'=>1,'cleared'=>19,'deadline'=>$now+10000];
$round=WildMonsterInvasions::start($base,$owner,['event'=>2,'stage'=>20,'run'=>1],$now,0,[]);
Baiter::advance($round,$now+3);$g=$round['wmiWave']['groups'][1];
if($g['delay']>1){foreach($round['units'] as &$u)$u['hp']=0;unset($u);Baiter::advance($round,$now+4);wcheck($round['wmiWave']['next']===2,'empty field brings next group early');}
$rageWave=WildMonsterInvasions::groups(2,16)[0];$rage=WildMonsterInvasions::attacker($rageWave,'w1',1,1,.4,0);
$normal=BattleService::makeUnit('spurtz',4,'w1','attacker',1,1);
wcheck($rage['enraged'] && $rage['_speed']===$normal['_speed']*2 && $rage['_interval']===$normal['_interval']/2 && $rage['_damage']===60,'exact native event stats and rage modifiers');
$rage['_rageEnd']=0;$round['units']=[$rage];Baiter::advance($round,$now+5);
wcheck(!$round['units'][0]['enraged'] && $round['units'][0]['_speed']===$normal['_speed'],'rage expires');
$base['wmi'][2]['deadline']=$now-1;
wreject(fn()=>WildMonsterInvasions::start($base,$owner,['event'=>2,'stage'=>20,'run'=>1],$now,0,[]));
wcheck(WildMonsterInvasions::view($base,$now)[2]['restart'],'elapsed seven-day run restarts');
echo "Wild Monster Invasions: $checks checks passed.\n";
