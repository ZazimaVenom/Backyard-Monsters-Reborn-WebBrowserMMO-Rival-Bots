<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function verify(bool $condition,string $label):void{global $checks;$checks++;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function reject(callable $work,string $label):void{try{$work();}catch(RuntimeException){verify(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
function buildingsPreserved(array $old,array $new):bool{if(count($old)!==count($new)){return false;}foreach($old as $i=>$building){if(array_intersect_key($new[$i],$building)!==$building||$new[$i]['fortification']!==0||$new[$i]['fortify_ready_at']!==0||$new[$i]['fortify_to']!==null||$new[$i]['fortify_paid']!==null){return false;}}return true;}
$old=['buildings'=>[['id'=>1,'type'=>'townhall','level'=>4,'hp'=>1234,'max_hp'=>6000,'x'=>16,'y'=>16,'ready_at'=>0]],'resources'=>['twig'=>1234,'pebble'=>987,'goo'=>6543,'putty'=>42],'army'=>['pokey'=>17,'octo'=>3,'fink'=>2,'ichi'=>1,'bandito'=>4,'dave'=>0],'research'=>['pokey'=>3,'octo'=>2,'fink'=>1,'ichi'=>1,'bandito'=>1,'dave'=>1],'queue'=>[['id'=>8,'type'=>'pokey','ready_at'=>10000000000]],'researchQueue'=>[['type'=>'fink','target_level'=>2,'ready_at'=>9999999999]],'unlocked'=>['pokey','octo','fink','ichi','bandito','dave','bolt'],'unlockQueue'=>[],'garrison'=>['8'=>['octo'=>5]],'last_tick'=>10000,'shield_until'=>8888,'next_building_id'=>77,'next_queue_id'=>99,'champions'=>[],'activeChampion'=>null,'content_version'=>'0.3.0','custom_extension'=>['keep'=>true]];
$b=Game::normalizeBase($old);
verify(Game::normalizeBase($b)===$b,'normalization remains idempotent');
foreach(['buildings','resources','queue','researchQueue','unlocked','unlockQueue','garrison','last_tick','shield_until','next_building_id','next_queue_id','custom_extension','champions','activeChampion'] as $key){verify($key==='buildings'?buildingsPreserved($old[$key],$b[$key]):$old[$key]===$b[$key],'preserve existing '.$key);}
verify($b['content_version']==='0.13.0','0.13.0 content version recorded');
verify($b['army']['projectx']===0&&!in_array('projectx',$b['unlocked'],true),'Project X starts locked with zero stock');
verify($b['lab']['queue']===null&&array_sum($b['lab']['levels'])===0&&count($b['lab']['levels'])===10,'no free lab research or queue');
verify(count(Catalog::buildings())>=33&&count(Catalog::homeMonsters())>=15,'catalog preserves existing structures and species');
verify(Catalog::buildings()['lab']['maxLevel']===3&&Catalog::buildings()['catapult']['maxLevel']===4,'facility maximum levels follow source');
verify(Catalog::buildings()['catapult']['requiresBuilding']==='flinger','Catapult retains source Flinger prerequisite');
foreach(['pokey','octo','ichi','crabatron','zafreeti','gorgo'] as $type){verify(!isset(Catalog::labAbilities()[$type]),'no invented Lab ability for '.$type);}
foreach(Catalog::labAbilities() as $type=>$spec){
    verify(isset(Catalog::monsters()[$type])&&count($spec['levels'])===3,'three tiers for playable ordinary species '.$type);
    $lastCost=0;$lastTime=0;
    foreach($spec['levels'] as $tier=>$row){
        $stats=Catalog::labStats($type,$tier);
        verify($stats['requiredLabLevel']===$tier&&$stats['requiredMonsterLevel']===$tier+1,'source prerequisite levels '.$type.'/'.$tier);
        verify($stats['cost']['putty']>$lastCost&&$stats['researchTime']>$lastTime&&$stats['cost']['putty']<=30000,'bounded rising cost and time '.$type.'/'.$tier);
        verify($stats['effect']===$row['effect']&&$stats['type']===$type&&$stats['level']===$tier,'stable effect contract '.$type.'/'.$tier);
        $lastCost=$stats['cost']['putty'];$lastTime=$stats['researchTime'];
    }
}
reject(fn()=>Catalog::labStats('pokey',1),'unsupported species');
reject(fn()=>Catalog::labStats('bolt',0),'zero research tier');
reject(fn()=>Catalog::labStats('bolt',4),'excess research tier');
verify(Catalog::labStats('brain',3)['effect']['cloakDelay']===3.0,'Brain follows actual component delay');
verify(Catalog::labStats('projectx',3)['effect']['deathMultiplier']===2.5,'Project X final burst multiplier');
verify(Catalog::labStats('dave',1)['effect']['rocketRange']===3.5,'DAVE range scales to yard units');
verify(Catalog::labStats('teratorn',3)['effect']['bounces']===3,'Teratorn has three additional bounces at highest tier');
$b['lab']['levels']['bolt']=2;$b['lab']['levels']['future_extension']=17;
$b['lab']['queue']=['type'=>'fink','target_level'=>1,'started_at'=>123,'ready_at'=>200,'paid_putty'=>2000,'extension'=>'retain'];
$b['lab']['custom_extension']=['keep'=>'yes'];
$before=$b;verify(Game::normalizeBase($b)===$before,'preserve all Lab extensions and exact paid queue');
Game::accrueLab($b,199);verify($b===$before,'future study remains pending');
Game::accrueLab($b,200);verify($b['lab']['levels']['fink']===1&&$b['lab']['queue']===null,'paid study completes at exact boundary');
verify($b['resources']===$before['resources']&&$b['army']===$before['army'],'completion does not mint resources or monsters');
verify($b['lab']['custom_extension']===$before['lab']['custom_extension']&&$b['lab']['levels']['future_extension']===17,'completion preserves unrelated extensions');
$once=$b;Game::accrueLab($b,200);Game::accrueLab($b,100);verify($b===$once,'same/regressed clock never completes twice');
$b['lab']['queue']=['type'=>'bolt','target_level'=>1,'ready_at'=>200,'paid_putty'=>1500];Game::accrueLab($b,201);verify($b['lab']['levels']['bolt']===2,'late lower-tier queue cannot downgrade learned ability');
$b['lab']['queue']=['type'=>'future_monster','target_level'=>1,'ready_at'=>100,'paid_putty'=>100];$unknown=$b;Game::accrueLab($b,1000);verify($b===$unknown,'unknown future queue remains preserved');
$bombs=Catalog::catapultBombs();verify(array_keys($bombs)===['twig','pebble','putty'],'three resource bomb families');
foreach($bombs as $type=>$family){
    verify($family['resource']===$type&&$family['requiredLevel']===array_search($type,['twig','pebble','putty'],true)+1,'source family gate '.$type);
    verify(count($family['levels'])===($type==='twig'?3:4),'correct source tier count '.$type);
    foreach($family['levels'] as $tier=>$bomb){
        verify($bomb['cost']>0&&$bomb['radius']>0&&$bomb['cost']<=30000,'bounded payable bomb '.$type.'/'.$tier);
        verify($type==='putty'?($bomb['damage']===0&&$bomb['speed']>1&&$bomb['armor']>0&&$bomb['armor']<1&&$bomb['duration']>0):($bomb['damage']>0&&$bomb['duration']===0),'damage versus empowerment semantics '.$type.'/'.$tier);
    }
}
verify(Catalog::all()['labAbilities']===Catalog::labAbilities()&&Catalog::all()['catapultBombs']===$bombs,'bootstrap catalog exposes abilities and bombs');
echo "Lab progression: $checks assertions passed.\n";
