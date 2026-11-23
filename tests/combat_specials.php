<?php
declare(strict_types=1);
require_once __DIR__.'/../app/BattleEngine.php';
require_once __DIR__.'/../app/Catalog.php';
$count=0;
function expect(bool $ok,string $message):void{global$count;if(!$ok){throw new RuntimeException('FAIL: '.$message);}++$count;echo "PASS $message\n";}
function prop(int$id,string$type,float$x,float$y,float$hp=10000):array{$c=Catalog::buildings()[$type];return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>1,'hp'=>$hp,'max_hp'=>$hp,'_initial_hp'=>$hp,'_size'=>$c['size'],'_damage'=>$c['damage']??0,'_range'=>$c['range']??0,'_interval'=>$c['attackInterval']??1,'_cooldown'=>0,'_ready'=>true,'_targetMode'=>$c['targetMode']??'both','_splash'=>$c['splash']??0];}
function creature(string$id,string$type,float$x,float$y,string$team='attacker'):array{$c=Catalog::monsters()[$type];return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'team'=>$team,'movement'=>$c['movement']??'ground','hp'=>$c['hp'],'max_hp'=>$c['hp'],'state'=>($c['movement']??'')==='burrow'?'burrow':'walk','targetId'=>null,'_damage'=>max(0,$c['damage']),'_speed'=>$c['speed'],'_range'=>$c['range'],'_interval'=>$c['attackInterval'],'_target'=>$c['target'],'_ability'=>$c['ability']??'','_heal'=>$c['heal']??0,'_splash'=>$c['splash']??0,'_submerged'=>($c['movement']??'')==='burrow','_cooldown'=>0,'_repath'=>0,'_path'=>[],'_obstacleVersion'=>-1];}
function arena(array$buildings,array$units):array{return ['id'=>1,'status'=>'active','elapsed'=>0.0,'duration'=>300,'buildings'=>$buildings,'units'=>$units,'events'=>[],'eventSeq'=>0,'obstacleVersion'=>0,'remaining'=>['pokey'=>1],'attacker'=>['id'=>1],'defender'=>['id'=>2],'result'=>null];}

$s=arena([prop(1,'townhall',16,16),prop(2,'wall',12,16,500)],[creature('u1','eyera',10,16)]);BattleEngine::advance($s,5);
expect($s['units'][0]['hp']===0.0&&$s['buildings'][1]['hp']===0.0,'Eye-ra reaches a wall, explodes, and is consumed');
expect(count(array_filter($s['events'],fn($e)=>$e['type']==='explode'))===1,'Eye-ra emits exactly one explosion event');

$healer=creature('u1','zafreeti',8,8);$patient=creature('u2','ichi',9,8);$patient['hp']=100;$patient['_speed']=0;$patient['_damage']=0;
$s=arena([prop(1,'townhall',16,16)],[$healer,$patient]);BattleEngine::advance($s,1);
expect($s['units'][1]['hp']>100&&$s['buildings'][0]['hp']==10000,'Zafreeti heals friendly units and never damages a building');
expect(count(array_filter($s['events'],fn($e)=>$e['type']==='heal'))>0,'healing produces renderer events');
$s=arena([prop(1,'townhall',10,10)],[$healer]);BattleEngine::advance($s,10);
expect($s['buildings'][0]['hp']==10000,'a lone healer cannot turn healing power into building damage');

$air=creature('u1','teratorn',8,16);$air['_speed']=3;$air['_range']=0.7;
$s=arena([prop(1,'townhall',16,16),prop(2,'wall',11,16,10000)],[$air]);BattleEngine::advance($s,2);
expect($s['units'][0]['x']>12&&$s['buildings'][1]['hp']==10000,'flying movement crosses a fortification without attacking it');
$worm=creature('u1','wormzer',8,16);$worm['_speed']=3;
$s=arena([prop(1,'townhall',16,16),prop(2,'wall',11,16,10000),prop(3,'sniper',13,21)],[$worm]);BattleEngine::advance($s,1);
expect($s['units'][0]['hp']===$worm['hp']&&$s['units'][0]['state']==='burrow','submerged Wormzer bypasses ground routing and cannot be shot');
BattleEngine::advance($s,4);
expect(count(array_filter($s['events'],fn($e)=>$e['type']==='emerge'))>=1,'Wormzer emits emergence when it reaches an attack target');

$cannon=prop(1,'cannon',16,16);$air=creature('u1','teratorn',15,13);$air['_damage']=0;$air['_speed']=0;
$s=arena([$cannon,prop(2,'townhall',24,24)],[$air]);BattleEngine::advance($s,1);
expect($s['units'][0]['hp']===$air['hp'],'ground-only defenses cannot hit flying units');
$laser=prop(1,'laser',16,16);$s=arena([$laser,prop(2,'townhall',24,24)],[$air]);BattleEngine::advance($s,0.25);
expect($s['units'][0]['hp']===$air['hp'],'Laser Tower preserves original ground-only targeting');
$ground=creature('u1','ichi',15,13);$ground['_damage']=0;$ground['_speed']=0;$s=arena([$laser,prop(2,'townhall',24,24)],[$ground]);BattleEngine::advance($s,0.25);
expect($s['units'][0]['hp']<$ground['hp'],'Laser Tower fires a damaging beam at ground attackers');
$s=arena([prop(1,'tesla',16,16),prop(2,'townhall',24,24)],[$air]);BattleEngine::advance($s,0.25);
expect($s['units'][0]['hp']<$air['hp'],'both-mode Tesla defense can target flying attackers');

$trap=prop(2,'trap',8,8);$s=arena([prop(1,'townhall',16,16),$trap],[creature('u1','pokey',8,8)]);
expect(BattleEngine::legalDeployment($s,8,8),'hidden traps do not leak through deployment exclusion checks');
BattleEngine::advance($s,0.25);
expect($s['buildings'][1]['hp']===0.0&&$s['units'][0]['hp']===0.0,'ground proximity triggers and consumes a single-use trap');
BattleEngine::advance($s,1);
expect(count(array_filter($s['events'],fn($e)=>$e['type']==='trap'))===1&&BattleEngine::destruction($s)===0.0,'spent traps never trigger twice or count toward victory');
$s=arena([prop(1,'townhall',16,16),$trap],[creature('u1','teratorn',8,8),creature('u2','wormzer',8,8)]);BattleEngine::advance($s,0.25);
expect($s['buildings'][1]['hp']==10000,'flying and submerged monsters do not trigger ground traps');

$bunker=prop(2,'bunker',10,16);$defender=creature('d1','fink',10,16,'defender');$defender['_bunkerId']=2;
$attacker=creature('u1','pokey',7,16);$attacker['_speed']=0;$attacker['_damage']=0;
$s=arena([prop(1,'townhall',24,24),$bunker],[$attacker]);$s['_garrisonTemplates']=['2'=>[$defender]];$s['remaining']=['pokey'=>0];BattleEngine::advance($s,8);
expect(count(array_filter($s['units'],fn($u)=>$u['team']==='defender'))===1,'bunker releases its real garrison once when invaders approach');
expect($s['units'][0]['hp']===0.0&&$s['status']==='finished'&&!$s['result']['attackerWon'],'defender troops fight invaders and never count as surviving attackers');
$defender=creature('d1','pokey',8.5,8,'defender');$defender['_speed']=0;$defender['_damage']=0;$attacker=creature('u1','fang',8,8);$attacker['_damage']=1000;
$s=arena([prop(1,'townhall',24,24)],[$attacker,$defender]);BattleEngine::advance($s,0.25);
expect($s['units'][1]['hp']===0.0,'attacking ground monsters can kill a defending garrison unit');
expect(count(array_filter($s['events'],fn($e)=>$e['type']==='hit'))===1,'a melee unit strike emits one damage impact instead of duplicate damage badges');

$eye=creature('d1','eyera',8.5,8,'defender');$attack=creature('u1','pokey',8,8);$attack['_speed']=0;$attack['_damage']=0;
$s=arena([prop(1,'townhall',24,24)],[$attack,$eye]);BattleEngine::advance($s,0.25);
expect($s['units'][0]['hp']===0.0&&$s['units'][1]['hp']===0.0&&count(array_filter($s['events'],fn($e)=>$e['type']==='explode'))===1,'stationed Eye-ra attacks an enemy unit and self-destructs');
$octo=creature('u1','octo',8,8);$octo['_speed']=0;$s=arena([prop(1,'townhall',11,8),prop(2,'bunker',8,13)],[$octo]);BattleEngine::advance($s,0.25);
expect($s['units'][0]['targetId']===2,'tower-hunting monsters treat a bunker as a defensive target');

// Hidden traps must not become building targets, even at the attacker's exact position.
$s=arena([prop(1,'townhall',20,20),prop(2,'trap',8,8)],[creature('u1','teratorn',8,8)]);BattleEngine::advance($s,1);
expect($s['units'][0]['targetId']===1,'hidden traps cannot be detected through unit target selection');
echo "Combat 0.2 specials: $count checks passed.\n";
