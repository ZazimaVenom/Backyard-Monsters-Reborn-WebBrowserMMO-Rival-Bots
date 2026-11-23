<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
(new ReflectionProperty(Db::class,'configuration'))->setValue(null,['game_speed'=>1,'player_grace_seconds'=>0]);
$checks=0;
function checkStore(bool $condition,string $message):void{
    global $checks;
    if(!$condition)throw new RuntimeException($message);
    ++$checks;
}
function buyPower(array &$b,string $key,int $now):void{
    $offers=StorePowers::offers($b,$now);
    YardUtilities::purchase($b,['kind'=>'power','key'=>$key,'price'=>$offers[$key]['price'],'revision'=>$b['storeRevision']??0],$now);
}
function addStoreFixture(array &$b,string $type,int $level):int{
    $id=$b['next_building_id']++;$hp=Game::buildingStats($type,$level)['max_hp'];
    $b['buildings'][]=['type'=>$type,'id'=>$id,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'x'=>4,'y'=>4,'ready_at'=>0,'stored'=>0,'upgrade_to'=>null];
    return $id;
}
$now=time();$b=Game::initialBase(13);$b['shiny']=16000;
addStoreFixture($b,'generalstore',1);$b['buildings'][0]['level']=5;
$b['infernoRealm']=InfernoRealm::initial($now);$normalCap=Game::capacity($b);$nativeCap=InfernoRealm::capacity($b['infernoRealm']);
buyPower($b,'BIP',$now);
checkStore(Game::capacity($b)===intdiv($normalCap*11,10) && InfernoRealm::capacity($b['infernoRealm'])===intdiv($nativeCap*11,10),'Packing from one purchase applies to the surface and cavern.');
checkStore(StorePowers::offers($b,$now)['BIP']['price']===100,'Second packing upgrade costs 100.');
for($i=1;$i<10;++$i)buyPower($b,'BIP',$now);
checkStore(Game::capacity($b)===2*$normalCap && StorePowers::offers($b,$now)['BIP']['reason']!=='','Packing stops at ten tiers.');
buyPower($b,'BEW',$now);checkStore($b['workers']===2 && StorePowers::offers($b,$now)['BEW']['price']===500,'One permanently hired worker unlocks one parallel job.');
$b['queue']=[
 ['lane'=>2,'type'=>'pokey','id'=>1,'started_at'=>$now-100,'ready_at'=>$now+300,'duration'=>400],
 ['lane'=>2,'type'=>'pokey','id'=>2,'started_at'=>$now+300,'ready_at'=>$now+14700,'duration'=>14400],
 ['lane'=>5,'type'=>'pokey','id'=>3,'started_at'=>$now-40,'ready_at'=>$now+40,'duration'=>80],
];
buyPower($b,'HOD',$now);
checkStore(array_column($b['queue'],'ready_at')===[$now+75,$now+3900,$now+10],'Accelerate unfinished work separately per lane, crossing the expiry boundary at normal speed.');
checkStore(StorePowers::offers($b,$now)['HOD2']['reason']!=='' && StorePowers::offers($b,$now)['HOD2I']['reason']==='','Surface grades exclude each other and leave native grades available.');
checkStore(StorePowers::workTime($b,$now+3590,80)===50 && StorePowers::workTime($b,$now+3600,80)===80,'An egg crossing expiry uses exactly the earned seconds.');
$b['unlockQueue']=[['type'=>'bolt','ready_at'=>$now+81]];buyPower($b,'CLOD',$now);
checkStore($b['unlockQueue'][0]['ready_at']===$now+21,'Locker Overdrive shortens existing species discovery.');
$house=Game::housingCapacity($b,$now);buyPower($b,'EXH',$now);$army=$b['army'];
checkStore(Game::housingCapacity($b,$now)===intdiv($house*5,4) && Game::housingCapacity($b,$now+86400)===$house && $army===$b['army'],'Housing reverts at expiry without deleting troops.');
foreach(['HOD2I','EXHI','TODI','PRO1I'] as $key)buyPower($b,$key,$now);
checkStore(StorePowers::workTime($b['infernoRealm'],$now,30)===5 && InfernoMonsters::space($b['infernoRealm'],$now)['capacity']===500,'Cavern overdrives have independent clocks and native compound capacity.');
checkStore($b['infernoRealm']['shield_until']===$now+86400 && $b['shield_until']<=$now,'A cavern shield affects its own yard.');
buyPower($b,'PRO1',$now);buyPower($b,'PRO2',$now+1);
checkStore($b['shield_until']===$now+1+604800 && StorePowers::offers($b,$now+60)['PRO2']['reason']!=='','A longer shield replaces a shorter plan and the same plan cannot be rebought while active.');
$b['shield_until']=$now+120;
checkStore(StorePowers::offers($b,$now)['PRO1']['reason']==='','Ordinary post-battle shield is independent of old purchases.');
$wallId=addStoreFixture($b,'wall',1);addStoreFixture($b,'wall',2);addStoreFixture($b,'wall',3);
$offers=StorePowers::offers($b,$now);$key=array_values(array_filter(array_keys($offers),fn($k)=>str_starts_with($k,'BLK3@')))[0];
checkStore($offers[$key]['price']===15,'A level-one and level-two wall cost nine and six to raise to metal.');
buyPower($b,$key,$now);
$wall=array_values(array_filter($b['buildings'],fn($w)=>$w['id']===$wallId))[0];
checkStore($wall['level']===3 && $wall['hp']===Game::buildingStats('wall',3)['max_hp'],'A bulk wall upgrade updates live defenses.');
$bunker=addStoreFixture($b,'bunker',3);$bunkerQuotes=StorePowers::bunkerOffers($b,$now)[$bunker]['troops'];
checkStore($bunkerQuotes['crabatron']['price']===14 && $bunkerQuotes['projectx']['price']===24 && $bunkerQuotes['spurtz']['price']===3,'The bunker tariff maps Flash IDs to their proper species.');
checkStore(StorePowers::bunkerQuote($b,$bunker.'.spurtz',10000,$now)===null,'Bunker orders enforce space and quantity.');
$prod=Game::initialBase(1);$prod['last_tick']=$now;$prod['storeTimers']['POD']=$now+4;
foreach($prod['buildings'] as &$factory)if($factory['type']==='twig'){$factory['ready_at']=$now+3;$factory['upgrade_to']=2;}
unset($factory);Game::accrue($prod,$now+10);
$twig=array_values(array_filter($prod['buildings'],fn($v)=>$v['type']==='twig'))[0];
checkStore(abs($twig['stored']-22*Catalog::buildings()['twig']['production'])<.000001,'Production integrates boost expiry and a simultaneous level change without crediting earlier time.');
$unit=BattleService::makeUnit('pokey',1,'u1','attacker',4,4);
$state=['elapsed'=>0,'events'=>[],'eventSeq'=>0,'units'=>[$unit],'buildings'=>[['type'=>'sniper','_damage'=>80],['type'=>'trap','_damage'=>80]]];
StorePowers::combat($state,['storeTimers'=>array_fill_keys(['MOD','MDOD','MSOD'],$now+3)],['storeTimers'=>['TOD'=>$now+3]],$now);
StorePowers::battleTick($state);StorePowers::battleTick($state);
checkStore(abs($state['units'][0]['_damage']-$unit['_damage']*1.25)<.00001 && $state['units'][0]['_speed']===$unit['_speed']*1.5 && $state['buildings'][0]['_damage']===100.0 && $state['buildings'][1]['_damage']===80,'Monster and tower boosts apply once, leave trap damage alone.');
BattleEngine::hurtUnit($state,0,10,'test');
checkStore(abs($unit['hp']-$state['units'][0]['hp']-7)<.0001,'Beast Mode absorbs exactly thirty percent on an ordinary hit.');
$state['elapsed']=3;StorePowers::battleTick($state);
checkStore(abs($state['units'][0]['_damage']-$unit['_damage'])<.00001 && abs($state['units'][0]['_interval']-$unit['_interval'])<.00001 && $state['buildings'][0]['_damage']===80.0,'Boosts expire during an ongoing battle.');
$before=$state['units'][0]['hp'];BattleEngine::hurtUnit($state,0,10,'test');
checkStore(abs($before-$state['units'][0]['hp']-10)<.00001,'Expired armor does not absorb hits.');
$bot=['_activity_clock'=>['epoch'=>1,'pausedSeconds'=>20],'storeTimers'=>['POD'=>$now+7],
    'infernoRealm'=>['buildings'=>[],'storeTimers'=>['HOD'=>$now+9]]];
WorldActivity::shiftBase($bot,['epoch'=>1,'pausedSeconds'=>80]);
checkStore($bot['storeTimers']['POD']===$now+67&&$bot['infernoRealm']['storeTimers']['HOD']===$now+69,'World dormancy freezes purchased clock time for bots in both realms.');
$land=Game::initialBase(1);addStoreFixture($land,'generalstore',1);$land['shiny']=2200;
$original=Planner::positions($land);$land['infernoRealm']=InfernoRealm::initial($now);
foreach([36,40,44,48,52,58] as $extent){buyPower($land,'ENL',$now);checkStore(YardSpace::extent($land)===$extent,'Expanded tiles are rounded symmetrically.');}
checkStore(StorePowers::offers($land,$now)['ENL']['reason']!=='' && Planner::positions($land)===$original && YardSpace::extent($land['infernoRealm'])===32,'Six surface upgrades preserve geometry and the original cavern.');
$pos=Decorations::coordinates($land,['x'=>-5,'y'=>16],.5,null);checkStore($pos===[-5,16],'Decorations and visiting signs accept purchased terrain.');
$extent=['yardExtent'=>58,'buildings'=>[],'flinger'=>Flinger::loadout(1),'id'=>1,'unitSeq'=>0];
checkStore(Flinger::legalAim($extent,-10,-10,7) && !Flinger::legalAim($extent,-13,9,7),'Ordinary deployment distinguishes bought terrain and the true outer edge.');
$points=Baiter::entryPoints($extent,'tl');checkStore($points[0]['x']===-12.5 && abs($points[0]['y']-16)<=.5,'Baited waves arrive at the expanded edge.');
$slots=Flinger::botSlots($extent,1,7);checkStore($slots[0][0]>40,'Bots select legal Flinger slots on the expanded yard boundary.');
$hall=BattleService::freezeBuilding(['type'=>'townhall','id'=>1,'x'=>-5,'y'=>-7,'level'=>1,'hp'=>90,'max_hp'=>90,'ready_at'=>0],Catalog::buildings()['townhall'],0,0,$now);
$state=$extent;$state+=['status'=>'active','elapsed'=>0,'duration'=>300,'events'=>[],'eventSeq'=>0,'obstacleVersion'=>0,'remaining'=>[],'attacker'=>['id'=>1],'defender'=>['id'=>2],'result'=>null];
$state['buildings']=[$hall];$state['units']=[BattleService::makeUnit('dave',1,'u1','attacker',-11,-10)];
BattleEngine::advance($state,30);checkStore($state['result']['attackerWon']===true,'Ground A* reaches a target when the entire path is outside the old map.');
echo "Store powers: $checks checks passed.\n";
