<?php
declare(strict_types=1);

require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/Baiter.php';
set_error_handler(static function(int $severity, string $message): never { throw new ErrorException($message, 0, $severity); });

$checks = 0;
function verifyDrill(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks;
}
function rejectsDrill(callable $fn, string $message): void {
    try { $fn(); } catch (RuntimeException $e) { verifyDrill(true, $message); return; }
    throw new RuntimeException($message);
}
function drillBuilding(int $id, string $type, float $x, float $y, int $level = 1): array {
    return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$level,'hp'=>2000,'max_hp'=>2000,'ready_at'=>0,'fortification'=>0,'fortify_ready_at'=>0];
}
$now = 1700000000;
$owner = ['id'=>24,'name'=>'Drill Keeper','password_hash'=>'must never be copied','rank_points'=>75];
$yard = ['buildings'=>[drillBuilding(1,'townhall',20,16), drillBuilding(2,'baiter',16,16,1),
    drillBuilding(3,'aerial',3,16), drillBuilding(4,'heavytrap',0.5,14.5), drillBuilding(5,'bunker',3,18),
    drillBuilding(6,'championcage',10,10)],
    'activeChampion'=>'gorgo','champions'=>['gorgo'=>['level'=>2,'hp'=>600,'bonus_feeds'=>0,'battle_id'=>0]],
    'garrison'=>['5'=>['pokey'=>2]], 'army'=>['pokey'=>5], 'lab'=>['levels'=>[]], 'research'=>['pokey'=>3], 'resources'=>['goo'=>0]];
$original=$yard;
$opts=Baiter::options($yard,$now);
verifyDrill($opts['available'] && $opts['level']===1 && $opts['capacity']===600 && count($opts['directions'])===4,'level-one capacity and approaches');
verifyDrill(count($opts['costs'])===14 && $opts['costs']['dave']===160 && !isset($opts['costs']['zafreeti']), '14 source wild types and cStorage cost');
foreach ([['level',0],['ready_at',$now+1],['hp',0]] as [$field,$value]) {
    $blocked=$yard;$blocked['buildings'][1][$field]=$value;
    verifyDrill(!Baiter::options($blocked,$now)['available'], 'unfinished, upgrading or ruined Baiter unavailable');
    rejectsDrill(fn()=>Baiter::start($blocked,$owner,['pokey'=>1],'tl',$now), 'unready Baiter refused');
}
$missing=$yard;unset($missing['buildings'][1]);
verifyDrill(!Baiter::options($missing,$now)['available'],'no building unavailable');
foreach ([[],['dave'=>4],['pokey'=>49],['pokey'=>-1],['pokey'=>true],['octo'=>'2'],['gorgo'=>1],['zafreeti'=>1],['pokey'=>0]] as $bad) {
    rejectsDrill(fn()=>Baiter::start($yard,$owner,$bad,'tl',$now),'invalid selection rejected');
}
rejectsDrill(fn()=>Baiter::start($yard,$owner,['pokey'=>1],'t',$now),'level-one cardinal approach rejected');
$levelThree=$yard;$levelThree['buildings'][1]['level']=3;
verifyDrill(Baiter::options($levelThree,$now)['capacity']===1200,'source level-three budget');
$entries=[];
foreach (['tl','tr','br','bl','t','r','b','l'] as $direction) {
    $test=Baiter::start($levelThree,$owner,['pokey'=>1],$direction,$now);
    $view=Baiter::snapshot($test,$now);
    $entries[]=implode(',',$view['entry']);
    verifyDrill($view['direction']===$direction && BattleEngine::legalDeployment($test,$view['entry']['x'],$view['entry']['y']), 'chosen approach has a legal entry');
}
verifyDrill(count(array_unique($entries))===8,'all eight approaches enter different parts of the isometric yard');

$session=Baiter::start($yard,$owner,['teratorn'=>1,'dave'=>1],'tl',$now,0.1,['count'=>1,'reduction'=>0.07,'siteIds'=>[9]]);
$view=Baiter::snapshot($session,$now);
verifyDrill($view['training'] && $view['status']==='active' && $view['id']<0 && $view['countdown']===3,'countdown with separate renderer identity');
verifyDrill(array_column($view['units'],'type')===['gorgo'] && $view['units'][0]['hp']==600,'eligible defending champion enters at saved health');
verifyDrill($session['_garrisonTemplates'][5][0]['level']===3 && count($session['_garrisonTemplates'][5])===2,'actual garrison and earned research are copied');
verifyDrill($view['networkDefense']['reduction']===0.07 && $view['buildings'][2]['damageReduction']>0,'network defense frozen in the drill');
verifyDrill(!str_contains(json_encode($view),'password_hash') && !isset($view['buildings'][2]['_volley']), 'snapshot excludes private state');
$pre=$session;Baiter::advance($session,$now+2);
verifyDrill($session===$pre && Baiter::snapshot($session,$now+2)['countdown']===1,'server countdown prevents early spawns');
$cancelled=$session;Baiter::siren($cancelled);
verifyDrill($cancelled['result']['outcome']==='dismissed' && $cancelled['result']['spawned']===0 && !$cancelled['launched'], 'siren can cancel before arrival');
Baiter::advance($session,$now+3);
verifyDrill($session['elapsed']==0 && $session['launched'] && $session['unitSeq']===2, 'wave appears exactly at countdown end');
verifyDrill($session['units'][1]['type']==='dave' && $session['units'][2]['type']==='teratorn','canonical mixed wave enters chosen edge');
$once=$session;Baiter::advance($session,$now+3);
verifyDrill($once===$session,'immediate duplicate poll does not add more attackers');
$reloaded=json_decode(json_encode($session,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
Baiter::advance($session,$now+400);Baiter::advance($reloaded,$now+400);
verifyDrill($session['elapsed']<=2 && $session==$reloaded,'bounded catch-up and same combat after serialized resume');
$events=$session['events'];
$airRaid=Baiter::start($yard,$owner,['teratorn'=>2],'tl',$now);
Baiter::advance($airRaid,$now+4);
verifyDrill(count(array_filter($airRaid['events'],fn($e)=>$e['type']==='shot'&&$e['kind']==='aerial'))>0,'actual Aerial Defense Tower fires at wild flyers');
verifyDrill(count(array_filter($events,fn($e)=>$e['type']==='trap'&&$e['kind']==='heavytrap'))===1,'heavy species triggers the copied Heavy Trap');
verifyDrill(count(array_filter($session['units'],fn($u)=>$u['team']==='defender'&&!empty($u['_bunkerId'])))===2,'real bunker releases its garrison');
$scared=$session;Baiter::siren($scared);$done=$scared;Baiter::advance($scared,$now+901);Baiter::siren($scared);
verifyDrill($scared===$done && $scared['result']['spawned']===2,'siren and repeat polling are terminal and count only attackers');
for ($i=0;$i<152 && $session['status']==='active';++$i) { Baiter::advance($session,$now+500); }
verifyDrill($session['status']==='finished' && in_array($session['result']['outcome'],['held','overrun'],true),'mixed wave reaches a natural result');
verifyDrill($session['result']['rankDelta']===['attacker'=>0,'defender'=>0] && $session['result']['loot']===[],'no practice rewards');
verifyDrill($session['result']['defeated']+$session['result']['surviving']===2,'result accounts for the selected wild force');
verifyDrill($original===$yard,'copied drill does not touch real buildings, army, champions, resources or research');
echo "Wild Monster Baiter combat: $checks checks passed.\n";
