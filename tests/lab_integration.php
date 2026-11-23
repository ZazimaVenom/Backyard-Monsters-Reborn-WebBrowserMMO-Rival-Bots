<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function verify(bool $condition,string $label):void{global $checks;$checks++;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function reject(callable $work,string $label):void{try{$work();}catch(RuntimeException){verify(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
function addBuilding(array &$base,string $type,int $level,float $x=4,float $y=4):int{$id=$base['next_building_id']++;$hp=Game::buildingStats($type,$level)['max_hp'];$base['buildings'][]=['id'=>$id,'type'=>$type,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'x'=>$x,'y'=>$y,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];return $id;}
function indexOf(array $base,int $id):int{foreach($base['buildings'] as $index=>$building){if((int)$building['id']===$id){return $index;}}throw new RuntimeException('Missing fixture building');}
function finishLab(int $id):array{$base=Game::loadBase($id);$base['lab']['queue']['ready_at']=time()-1;Game::saveBase($id,$base);return Game::snapshot($id);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE')?:'backyard_test','username'=>getenv('MYSQL_TEST_USER')?:'backyard_test','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1,'player_grace_seconds'=>1800];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
foreach(explode(';',file_get_contents(__DIR__.'/../app/schema.sql')) as $sql){if(trim($sql)!==''){$pdo->exec($sql);}}
$pdo->beginTransaction();
try{
    $user=Game::register('Lab '.bin2hex(random_bytes(4)),'test-lab-password');$id=$user['id'];$base=Game::loadBase($id);
    verify($base['lab']['queue']===null&&array_sum($base['lab']['levels'])===0,'new keeper has no free Lab powers');
    verify($base['army']['projectx']===0&&!in_array('projectx',$base['unlocked'],true),'Project X starts unowned and locked');
    reject(fn()=>Game::mutate($id,'labResearch',['type'=>'fink']),'research without facility');
    reject(fn()=>Game::mutate($id,'labCancel',[]),'cancel empty Lab');
    foreach([[],true,2] as $type){reject(fn()=>Game::mutate($id,'build',['type'=>$type,'x'=>3,'y'=>28]),'malformed building type rejected without casting');}
    foreach(['lab','catapult'] as $type){reject(fn()=>Game::mutate($id,'build',['type'=>$type,'x'=>3,'y'=>28]),$type.' town hall gate');}
    $base['buildings'][0]['level']=3;$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],30000);Game::saveBase($id,$base);
    $built=Game::mutate($id,'build',['type'=>'lab','x'=>3,'y'=>28]);$lab=end($built['base']['buildings']);$labId=$lab['id'];
    verify($built['base']['resources']['twig']===25000&&$built['base']['resources']['putty']===28500,'Lab charges its construction price');
    verify($lab['level']===0&&$lab['ready_at']>time(),'Lab uses a persistent construction timer');
    reject(fn()=>Game::mutate($id,'build',['type'=>'lab','x'=>28,'y'=>3]),'one Lab per yard');
    reject(fn()=>Game::mutate($id,'labResearch',['type'=>'fink']),'unfinished Lab cannot research');
    // The starter crew handles one building at a time. Finish the paid Lab
    // before assigning its worker to the Catapult; study still has its own queue.
    $base=Game::loadBase($id);$base['buildings'][indexOf($base,$labId)]['ready_at']=time()-1;Game::saveBase($id,$base);Game::snapshot($id);
    $built=Game::mutate($id,'build',['type'=>'catapult','x'=>28,'y'=>3]);$catapult=end($built['base']['buildings']);$catapultId=$catapult['id'];
    verify($built['base']['resources']['pebble']===20500&&$built['base']['resources']['putty']===27500,'Catapult charges its construction price');
    reject(fn()=>Game::mutate($id,'build',['type'=>'catapult','x'=>4,'y'=>4]),'one Catapult per yard');
    $base=Game::loadBase($id);$li=indexOf($base,$labId);$ci=indexOf($base,$catapultId);$base['buildings'][$li]['ready_at']=time()-1;$base['buildings'][$ci]['ready_at']=time()-1;Game::saveBase($id,$base);Game::snapshot($id);
    verify(Game::livingLevel(Game::loadBase($id),'lab')===1&&Game::livingLevel(Game::loadBase($id),'catapult')===1,'both facilities finish construction normally');
    foreach(['unknown','pokey','gorgo'] as $type){reject(fn()=>Game::mutate($id,'labResearch',['type'=>$type]),'reject unsupported ability '.$type);}
    foreach([[],true,2] as $type){reject(fn()=>Game::mutate($id,'labResearch',['type'=>$type]),'reject malformed Lab type');}
    reject(fn()=>Game::mutate($id,'labResearch',['type'=>'bolt']),'locked species cannot study');
    reject(fn()=>Game::mutate($id,'labResearch',['type'=>'fink']),'species needs Academy level two');
    $base=Game::loadBase($id);$academyId=addBuilding($base,'academy',3,27,27);Game::saveBase($id,$base);
    $academy=Game::mutate($id,'research',['type'=>'fink']);verify($academy['researchQueue'][0]['target_level']===2,'normal Academy research remains available');
    $base=Game::loadBase($id);$base['researchQueue'][0]['ready_at']=time()-1;Game::saveBase($id,$base);Game::snapshot($id);
    $before=Game::loadBase($id)['resources'];$study=Game::mutate($id,'labResearch',['type'=>'fink','level'=>3]);
    verify($study['lab']['queue']['target_level']===1,'server selects next tier and ignores a client-requested skip');
    verify($study['base']['resources']['putty']===$before['putty']-2000&&$study['lab']['levels']['fink']===0,'study charges exact putty without granting early ability');
    verify($study['lab']['queue']['ready_at']-$study['lab']['queue']['started_at']===120&&$study['lab']['queue']['paid_putty']===2000,'timer and refundable paid amount persist');
    reject(fn()=>Game::mutate($id,'labResearch',['type'=>'fink']),'duplicate study request');
    reject(fn()=>Game::mutate($id,'labResearch',['type'=>'dave']),'another species cannot bypass single queue');
    reject(fn()=>Game::mutate($id,'upgrade',['id'=>$labId]),'Lab upgrade blocked during study');
    verify(Game::loadBase($id)['resources']['putty']===$before['putty']-2000,'rejected actions cannot spend additional putty');
    $cancelled=Game::mutate($id,'labCancel',[]);
    verify($cancelled['lab']['queue']===null&&$cancelled['base']['resources']['putty']===$before['putty'],'cancellation restores full paid amount when room exists');
    verify($cancelled['refund']===['action'=>'labCancel','putty'=>2000,'potential'=>2000,'overflow'=>0],'cancellation reports actual credited refund');
    reject(fn()=>Game::mutate($id,'labCancel',[]),'replayed cancellation cannot refund twice');
    $base=Game::loadBase($id);$base['resources']['putty']=1999;Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'labResearch',['type'=>'fink']),'insufficient putty');
    verify(Game::loadBase($id)['resources']['putty']===1999&&Game::loadBase($id)['lab']['queue']===null,'failed payment preserves resources and queue');
    $base['resources']['putty']=30000;Game::saveBase($id,$base);Game::mutate($id,'labResearch',['type'=>'fink']);
    $base=Game::loadBase($id);$base['resources']['putty']=29950;$base['buildings'][$li]['hp']=0;Game::saveBase($id,$base);
    $cancelled=Game::mutate($id,'labCancel',[]);
    verify($cancelled['refund']['putty']===50&&$cancelled['refund']['overflow']===1950&&$cancelled['base']['resources']['putty']===30000,'cancel respects storage cap and reports discarded overflow even with ruined Lab');
    reject(fn()=>Game::mutate($id,'labResearch',['type'=>'fink']),'ruined Lab cannot start a study');
    Game::mutate($id,'repair',['id'=>$labId]);Game::mutate($id,'labResearch',['type'=>'fink']);
    $base=Game::loadBase($id);$base['buildings'][$li]['hp']=0;Game::saveBase($id,$base);$finished=finishLab($id);
    verify($finished['lab']['levels']['fink']===1&&$finished['lab']['queue']===null,'already-funded study completes after damage to the Lab');
    verify(Game::snapshot($id)['lab']===$finished['lab'],'repeat polling does not complete research twice');
    reject(fn()=>Game::mutate($id,'labCancel',[]),'completed study cannot be refunded');
    Game::mutate($id,'repair',['id'=>$labId]);
    reject(fn()=>Game::mutate($id,'labResearch',['type'=>'fink']),'next tier requires Lab upgrade');
    $base=Game::loadBase($id);$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],30000);Game::saveBase($id,$base);
    $upgrade=Game::mutate($id,'upgrade',['id'=>$labId]);verify($upgrade['base']['buildings'][$li]['upgrade_to']===2,'normal paid Lab upgrade starts');
    reject(fn()=>Game::mutate($id,'labResearch',['type'=>'fink']),'Lab unavailable during building upgrade');
    $base=Game::loadBase($id);$base['buildings'][$li]['ready_at']=time()-1;Game::saveBase($id,$base);Game::snapshot($id);
    reject(fn()=>Game::mutate($id,'labResearch',['type'=>'fink']),'tier two still needs species level three');
    $base=Game::loadBase($id);$base['research']['fink']=3;Game::saveBase($id,$base);$second=Game::mutate($id,'labResearch',['type'=>'fink']);
    verify($second['lab']['queue']['target_level']===2&&$second['lab']['queue']['paid_putty']===3000,'tier two has correct stored cost');finishLab($id);
    // Every species and tier is acquired through the same paid, locked action.
    $base=Game::loadBase($id);$base['buildings'][0]['level']=4;$base['buildings'][$li]['level']=3;$base['buildings'][$li]['hp']=Game::buildingStats('lab',3)['max_hp'];$base['buildings'][$li]['max_hp']=$base['buildings'][$li]['hp'];$base['lab']['levels']=array_fill_keys(array_keys(Catalog::labAbilities()),0);$base['lab']['queue']=null;$base['unlocked']=array_keys(Catalog::monsters());$base['research']=array_fill_keys(array_keys(Catalog::monsters()),6);Game::saveBase($id,$base);
    foreach(Catalog::labAbilities() as $type=>$spec){
        for($tier=1;$tier<=3;$tier++){
            $base=Game::loadBase($id);$base['resources']['putty']=30000;Game::saveBase($id,$base);
            $state=Game::mutate($id,'labResearch',['type'=>$type]);$cost=Catalog::labStats($type,$tier)['cost']['putty'];
            verify($state['lab']['queue']['target_level']===$tier&&$state['base']['resources']['putty']===30000-$cost,'atomic paid tier '.$type.'/'.$tier);
            $state=finishLab($id);verify($state['lab']['levels'][$type]===$tier&&$state['lab']['queue']===null,'persistent completed tier '.$type.'/'.$tier);
        }
        reject(fn()=>Game::mutate($id,'labResearch',['type'=>$type]),'highest tier enforced '.$type);
    }
    reject(fn()=>Game::mutate($id,'upgrade',['id'=>$labId]),'Lab maximum level is three');
    // Speed changes affect only new timers, not already-paid schedules.
    $base=Game::loadBase($id);$base['lab']['levels']['bolt']=0;$base['resources']['putty']=30000;Game::saveBase($id,$base);Db::useConnection($pdo,array_replace($config,['game_speed'=>20]));
    $fast=Game::mutate($id,'labResearch',['type'=>'bolt']);verify($fast['lab']['queue']['ready_at']-$fast['lab']['queue']['started_at']===5,'configured game speed scales new research duration');
    Db::useConnection($pdo,$config);verify(Game::snapshot($id)['lab']['queue']['ready_at']===$fast['lab']['queue']['ready_at'],'later speed setting does not rewrite a running study');Game::mutate($id,'labCancel',[]);
    // New ordinary species remains part of all existing inventory systems.
    $base=Game::loadBase($id);$base['unlocked']=array_values(array_diff($base['unlocked'],['projectx']));$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],30000);$base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'train',['type'=>'projectx','quantity'=>1]),'Project X cannot hatch before discovery');
    $base=Game::loadBase($id);$lockerId=addBuilding($base,'locker',3,27,16);Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'unlock',['type'=>'projectx']),'Project X needs level-four Locker');
    $base=Game::loadBase($id);$base['buildings'][indexOf($base,$lockerId)]['level']=4;Game::saveBase($id,$base);
    $unlock=Game::mutate($id,'unlock',['type'=>'projectx']);verify($unlock['unlockQueue'][0]['type']==='projectx'&&$unlock['base']['resources']['goo']===14000,'Project X uses paid persistent discovery');
    $base=Game::loadBase($id);$base['unlockQueue'][0]['ready_at']=time()-1;Game::saveBase($id,$base);$trained=Game::mutate($id,'train',['type'=>'projectx','quantity'=>2]);
    verify(count($trained['queue'])===2&&$trained['base']['resources']['goo']===1000,'discovered Project X uses ordinary hatchery price');
    $base=Game::loadBase($id);foreach($base['queue'] as &$job){$job['ready_at']=time()-1;}unset($job);$juicerId=addBuilding($base,'juicer',1,4,16);$bunkerId=addBuilding($base,'bunker',1,16,28);Game::saveBase($id,$base);$hatched=Game::snapshot($id);
    verify($hatched['army']['projectx']===2&&count($hatched['queue'])===0,'Project X hatches once and persists');
    $garrison=Game::mutate($id,'garrison',['id'=>$bunkerId,'type'=>'projectx','quantity'=>1]);verify($garrison['army']['projectx']===1&&$garrison['garrison'][(string)$bunkerId]['projectx']===1,'Project X uses real bunker inventory');
    $returned=Game::mutate($id,'ungarrison',['id'=>$bunkerId,'type'=>'projectx','quantity'=>1]);verify($returned['army']['projectx']===2,'garrison return conserves Project X stock');
    $juiced=Game::mutate($id,'juice',['type'=>'projectx','quantity'=>1]);verify($juiced['army']['projectx']===1&&$juiced['refund']['goo']===1950,'Project X recycling uses normal thirty-percent refund');
    verify($juiced['lab']===$juiced['base']['lab']&&$hatched['lab']===$hatched['base']['lab'],'action and bootstrap expose consistent Lab state');
    $visitor=Game::visit($id,$id+999999);verify($visitor['lab']['levels']['dave']===3,'visited base retains visible learned abilities');
    // Both new study actions are blocked for either side of an active invasion.
    $foe=Game::register('Lab Foe '.bin2hex(random_bytes(4)),'test-lab-password');$foeId=$foe['id'];
    $stmt=$pdo->prepare("INSERT INTO battles(attacker_id,defender_id,status,state_json,created_at,updated_at) VALUES(?,?,'active','{}',?,?)");$stmt->execute([$id,$foeId,time(),time()]);
    foreach(['labResearch','labCancel'] as $action){reject(fn()=>Game::mutate($id,$action,['type'=>'bolt']),'attacker battle lock '.$action);reject(fn()=>Game::mutate($foeId,$action,['type'=>'fink']),'defender battle lock '.$action);}
    $pdo->rollBack();echo "Lab integration: $checks assertions passed; temporary data rolled back.\n";
}catch(Throwable $error){if($pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,(string)$error."\n");exit(1);}
