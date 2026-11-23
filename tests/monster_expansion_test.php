<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/InfernoRealm.php';
$checks=0;
function mcheck(bool $ok,string $why):void {global $checks;if(!$ok)throw new RuntimeException($why);++$checks;}
function mrejection(callable $action):void {try{$action();}catch(RuntimeException){mcheck(true,'rejection');return;}throw new LogicException('Invalid action was accepted');}
function arena(string $structure='townhall'):array{
    $b=['type'=>$structure,'id'=>1,'x'=>16,'y'=>16,'level'=>1,'hp'=>100000,'max_hp'=>100000,'ready_at'=>0];
    return ['id'=>1,'status'=>'active','formatVersion'=>7,'elapsed'=>0,'duration'=>300,'attacker'=>['id'=>1],'defender'=>['id'=>2],
        'buildings'=>[BattleService::freezeBuilding($b,Catalog::buildings()[$structure],0,0,1)],'units'=>[],'unitSeq'=>0,'eventSeq'=>0,
        'events'=>[],'remaining'=>['pokey'=>1],'obstacleVersion'=>0,'_garrisonTemplates'=>[],'result'=>null];
}
function creature(string $kind,int $level=1,string $side='attacker',float $x=15,float $y=16):array {
    static $n=0;return BattleService::makeUnit($kind,$level,'t'.++$n,$side,$x,$y);
}
$src=json_decode(file_get_contents(__DIR__.'/../docs/ORIGINAL-CATALOG.json'),true)['monsters'];$specs=MonsterExpansion::catalog();
mcheck(count(Catalog::monsters())===27 && count(Catalog::homeMonsters())===18 && count($specs)===12,'Native roster');
foreach($specs as $type=>$spec){
    $s=$src[$spec['originalId']];
    mcheck($s['name']===$spec['name']&&$spec['size']===$s['props']['cStorage'][0]&&Flinger::WEIGHTS[$type]===$s['props']['bucket'][0],'Native weight and name '.$type);
    for($i=0;$i<6;++$i){
        $u=creature($type,$i+1);foreach(['hp'=>'health','_damage'=>'damage','_speed'=>'speed'] as $field=>$key){
            $table=$s['props'][$key];mcheck($u[$field]==max(0,$table[min($i,count($table)-1)]),"$type tier $i $key");
        }
    }
}
foreach([1=>2,2=>2,3=>3,4=>3,5=>4,6=>5] as $level=>$count){
    $s=arena();$s['units'][]=creature('slimeattikus',$level,'defender');$s['units'][0]['_bunkerId']=11;
    BattleEngine::hurtUnit($s,0,20000,'test');BattleEngine::hurtUnit($s,0,20000,'test');
    $children=array_slice($s['units'],1);mcheck($s['unitSeq']===0,'Temporary summons do not advance the paid deployment or scripted wave counter');mcheck(count($children)===$count,'Death split at level '.$level);
    foreach($children as $u){mcheck($u['team']==='defender'&&$u['type']==='slimeattikusmini'&&$u['summoned']&&$u['_bunkerId']===null,'Disposable children');}
    mcheck(count(array_unique(array_column($s['units'],'id')))===1+$count,'Unique child IDs');
}
$s=arena();$s['units']=[creature('rezghul',6),creature('pokey',3),creature('ichi',2),creature('vorg'),creature('teratorn'),creature('fink',1,'defender'),creature('slimeattikusmini')];
foreach([1,2,3,4,5,6] as $i)BattleEngine::hurtUnit($s,$i,100000,'test');
$legacy=$s;BattleEngine::advance($s,.25);$copy=json_decode(json_encode($legacy),true);BattleEngine::advance($copy,.25);
mcheck($s==$copy,'Deterministic across serialized state');$z=array_values(array_filter($s['units'],fn($u)=>!empty($u['zombie'])));
mcheck(array_column($z,'type')===['pokey','ichi'],'Raise eligible friendly ground squad only');
mcheck($z[0]['hp']==$s['units'][1]['max_hp']*1.5&&$z[0]['_damage']==$s['units'][1]['_damage']*1.5&&$z[0]['_speed']==$s['units'][1]['_speed']*.75,'Earned zombie multipliers');
foreach(array_keys($s['units']) as $i){if(!empty($s['units'][$i]['zombie']))BattleEngine::hurtUnit($s,$i,100000,'test');}
BattleEngine::advance($s,9);mcheck(count(array_filter($s['units'],fn($u)=>!empty($u['zombie'])))===2,'Bodies and zombies cannot be raised twice');
foreach(['zagnoid'=>['sniper',160],'sabnox'=>['sniper',1400],'valgos'=>['wall',980],'balthazar'=>['townhall',600]] as $type=>[$building,$hit]){
    $s=arena($building);$s['units'][]=creature($type);BattleEngine::advance($s,.25);
    mcheck($s['buildings'][0]['hp']==100000-$hit,"$type building damage");
    if($type==='sabnox')mcheck(in_array('magma',array_column($s['events'],'projectile'),true),'Magma projectile');
}
$s=arena('cannon');$s['units']=[creature('balthazar'),creature('ichi',5,'defender')];$hp=$s['units'][1]['hp'];BattleEngine::advance($s,.25);
mcheck($s['units'][1]['hp']==$hp-1800&&$s['units'][0]['hp']<3200,'Low flyer hunts defenders and remains vulnerable to ground attacks');
$s=arena();$s['units']=[creature('kingwormzer'),creature('ichi',1,'defender',16.2,16)];
$s['units'][0]['_ignoreEnemyUntil']=1000;BattleEngine::advance($s,.25);
mcheck($s['units'][1]['hp']===2500.0&&$s['buildings'][0]['hp']<100000-1200 && in_array('emerge_splash',array_column($s['events'],'type')),'King shock enters buildings only');
$hp=$s['buildings'][0]['hp'];BattleEngine::advance($s,1.25);mcheck($s['buildings'][0]['hp']==$hp-1200,'No second shock on unchanged target');
$s=arena();$s['units']=[creature('vorg',6),creature('ichi'),creature('zafreeti')];$s['units'][1]['hp']=100;
BattleEngine::advance($s,.25);mcheck($s['units'][1]['hp']>=210 && in_array('heal',array_column($s['events'],'type')),'Vorg heals its side');
$s=arena();$s['units']=[creature('spurtz')];BattleEngine::hurtUnit($s,0,500,'test');mcheck(in_array('scorch',array_column($s['events'],'type'))&&$s['buildings'][0]['hp']===100000,'Cosmetic Spurtz scorch');
$realm=InfernoRealm::initial(1000);$realm['resources']['magma']=11000;$realm['buildings'][]=['id'=>101,'type'=>'locker','level'=>4,'hp'=>100,'ready_at'=>0];$realm['buildings'][]=['id'=>102,'type'=>'academy','level'=>5,'hp'=>100,'ready_at'=>0];
$rev=fn($r)=>['monsterRevision'=>$r['monsterRevision']];
InfernoMonsters::act($realm,'infernoTrain',$rev($realm)+['type'=>'spurtz','quantity'=>3],1000);
mcheck(count($realm['queue'])===3&&$realm['resources']['magma']===11000-3*42,'Realm batch purchased in magma');
mrejection(function()use(&$realm){InfernoMonsters::act($realm,'infernoTrain',['type'=>'spurtz','monsterRevision'=>0],1000);});
$firstDue=$realm['queue'][0]['ready_at'];InfernoMonsters::act($realm,'infernoCancel',$rev($realm)+['lane'=>'train','id'=>1],1000);
mcheck($realm['queue'][0]['ready_at']===$firstDue&&$realm['resources']['magma']===11000-2*42,'Refund and compact same lane');InfernoMonsters::accrue($realm,2000);InfernoMonsters::accrue($realm,2000);mcheck($realm['army']['spurtz']===10,'Saved hatching exactly once');
InfernoMonsters::act($realm,'infernoUnlock',$rev($realm)+['type'=>'zagnoid'],2000);InfernoMonsters::accrue($realm,3000);
mcheck(in_array('zagnoid',$realm['unlocked'],true)&&$realm['army']['zagnoid']===0,'Paid discovery without free units');
InfernoMonsters::act($realm,'infernoResearch',$rev($realm)+['type'=>'zagnoid'],3000);InfernoMonsters::accrue($realm,4000);
mcheck($realm['research']['zagnoid']===2&&InfernoMonsters::view($realm,4000)['options']['zagnoid']['hp']===1820,'Researched source tier applied');
mrejection(function()use(&$realm){InfernoMonsters::act($realm,'infernoTrain',['type'=>'slimeattikusmini','monsterRevision'=>$realm['monsterRevision']],4000);});
mrejection(function()use(&$realm){InfernoMonsters::act($realm,'infernoTrain',['type'=>'spurtz','quantity'=>30,'monsterRevision'=>$realm['monsterRevision']],4000);});
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/world-v05.json'),true);$state=json_decode($fixture['active_battle']['state_json'],true);
foreach([1=>'a54c621fde3f6af7a10c9e554755124d9faac8007b859260138cd3a94c16c16d',10=>'3b418f68340532aec732c562d076dafb0804ca68595be2fff79d6108600ed559',
    25=>'bf8839dba49afd3f08c0ebe0d0a66b7779565cbac729b9bbd29e97bf0b061f57',60=>'48aa9c744fc2a628388349ea787944f5fbb91a9c580c10be8e296a7a6dfdf57b',
    120=>'dac7c46a93d8356f82aba9388476ee1ebbffbb07f4ffca0d6742bc87a44ec907',300=>'34bc35449cb76379fdfe8f57db0ca986fa007e1bcc5ee757f93dd7f51a840742'] as $t=>$sha){
    BattleEngine::advance($state,$t);mcheck(hash('sha256',json_encode($state))===$sha,'Saved legacy battle matches 0.11.0 engine at '.$t.' seconds');
}
echo "Monster expansion: $checks checks passed.\n";
