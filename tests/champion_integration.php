<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function verify(bool $condition,string $label):void{global $checks;$checks++;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function reject(callable $work,string $label):void{try{$work();}catch(RuntimeException){verify(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
function addBuilding(array &$base,string $type,int $level=1,float $x=4,float $y=4):int{$id=$base['next_building_id']++;$hp=Game::buildingStats($type,$level)['max_hp'];$base['buildings'][]=['id'=>$id,'type'=>$type,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'x'=>$x,'y'=>$y,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];return $id;}
function buildingIndex(array $base,int $id):int{foreach($base['buildings'] as $index=>$b){if((int)$b['id']===$id){return $index;}}throw new RuntimeException('Fixture building missing');}
function due(int $id,string $type):array{$base=Game::loadBase($id);$base['champions'][$type]['next_feed_at']=time()-1;$base['champions'][$type]['last_hunger_at']=time()-1;Game::saveBase($id,$base);return $base;}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE')?:'backyard_test','username'=>getenv('MYSQL_TEST_USER')?:'backyard_test','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1,'player_grace_seconds'=>1800];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
foreach(explode(';',file_get_contents(__DIR__.'/../app/schema.sql')) as $sql){if(trim($sql)!==''){$pdo->exec($sql);}}
$pdo->beginTransaction();
try{
    $user=Game::register('Champion '.bin2hex(random_bytes(4)),'test-champion-password');$id=$user['id'];$base=Game::loadBase($id);
    verify($base['champions']===[]&&$base['activeChampion']===null,'new keeper receives no free champion');
    reject(fn()=>Game::mutate($id,'championRaise',['type'=>'gorgo']),'raising without cage');
    reject(fn()=>Game::mutate($id,'build',['type'=>'championcage','x'=>3,'y'=>28]),'cage townhall gate');
    $base['buildings'][0]['level']=4;$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],30000);Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'build',['type'=>'championchamber','x'=>3,'y'=>28]),'chamber requires living cage');
    $cageBuild=Game::mutate($id,'build',['type'=>'championcage','x'=>3,'y'=>28]);$cage=end($cageBuild['base']['buildings']);$cageId=$cage['id'];
    verify($cageBuild['base']['resources']['twig']===24000&&$cageBuild['base']['resources']['putty']===28000,'cage construction charges catalog price');
    reject(fn()=>Game::mutate($id,'championRaise',['type'=>'gorgo']),'unfinished cage');
    reject(fn()=>Game::mutate($id,'build',['type'=>'championcage','x'=>28,'y'=>3]),'only one cage');
    $base=Game::loadBase($id);$ci=buildingIndex($base,$cageId);$base['buildings'][$ci]['ready_at']=time()-1;Game::saveBase($id,$base);$state=Game::snapshot($id);
    verify(Game::livingLevel(Game::loadBase($id),'championcage')===1,'cage completes through normal timer');
    reject(fn()=>Game::mutate($id,'championRaise',['type'=>'unknown']),'unknown champion');
    reject(fn()=>Game::mutate($id,'championRaise',['type'=>[]]),'malformed champion type');
    $before=$state['base']['resources'];$raised=Game::mutate($id,'championRaise',['type'=>'gorgo']);
    verify($raised['activeChampion']==='gorgo'&&count($raised['champions'])===1,'raise selects unique active champion');
    verify($raised['champions']['gorgo']['hp']===8000&&$raised['champions']['gorgo']['level']===1,'raise starts fullhealth levelone');
    verify($raised['champions']['gorgo']['next_feed_at']>=time()+299,'raised champion begins fed');
    verify($raised['base']['resources']===$before,'source raise has no invented acquisition cost');
    reject(fn()=>Game::mutate($id,'championRaise',['type'=>'drull']),'second active champion');
    reject(fn()=>Game::mutate($id,'championFeed',[]),'feeding during digestion');
    reject(fn()=>Game::mutate($id,'championFreeze',[]),'freeze without chamber');
    // Source recipes consume only reserve monsters, atomically.
    $base=due($id,'gorgo');$base['army']['octo']=14;$base['garrison']=['999'=>['octo'=>50]];Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'championFeed',[]),'meal cannot consume garrison units');
    verify(Game::loadBase($id)['army']['octo']===14,'failed meal consumes no partial inventory');
    $base['army']['octo']=45;$base['champions']['gorgo']['hp']=2000;$base['champions']['gorgo']['last_heal_at']=time();Game::saveBase($id,$base);
    $fed=Game::mutate($id,'championFeed',[]);verify($fed['army']['octo']===30&&$fed['champions']['gorgo']['feeds']===1,'feed consumes fifteen Octo-Ooze once');
    verify($fed['champions']['gorgo']['hp']<8000,'ordinary feed does not restore full health');
    reject(fn()=>Game::mutate($id,'championFeed',[]),'replayed meal cannot consume twice');
    due($id,'gorgo');Game::mutate($id,'championFeed',[]);due($id,'gorgo');$grown=Game::mutate($id,'championFeed',[]);
    verify($grown['champions']['gorgo']['level']===2&&$grown['champions']['gorgo']['feeds']===0,'third feed evolves and resets progress');
    verify($grown['champions']['gorgo']['hp']===12000&&$grown['army']['octo']===0,'evolution restores newmaxhp without minting food');
    $base=due($id,'gorgo');$base['army']['octo']=10;$base['army']['ichi']=4;Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'championFeed',[]),'mixed recipe requires every component');
    $unchanged=Game::loadBase($id);verify($unchanged['army']['octo']===10&&$unchanged['army']['ichi']===4,'partial recipe rejection is atomic');
    // Build a chamber, freeze, raise another, then cycle all three without a capacity deadlock.
    $chamberBuild=Game::mutate($id,'build',['type'=>'championchamber','x'=>28,'y'=>3]);$chamber=end($chamberBuild['base']['buildings']);$chamberId=$chamber['id'];
    reject(fn()=>Game::mutate($id,'championFreeze',[]),'unfinished chamber');
    $base=Game::loadBase($id);$chi=buildingIndex($base,$chamberId);$base['buildings'][$chi]['ready_at']=time()-1;Game::saveBase($id,$base);Game::snapshot($id);
    reject(fn()=>Game::mutate($id,'championFreeze',[]),'hungry champion must be fed before freeze');
    $base=Game::loadBase($id);$base['champions']['gorgo']['next_feed_at']=time()+200;$base['champions']['gorgo']['last_hunger_at']=time()+200;$base['champions']['gorgo']['hp']=100;$base['champions']['gorgo']['last_heal_at']=time();Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'championFreeze',[]),'injured champion cannot freeze');
    $base['champions']['gorgo']['hp']=12000;Game::saveBase($id,$base);$frozen=Game::mutate($id,'championFreeze',[]);
    verify($frozen['activeChampion']===null&&$frozen['champions']['gorgo']['frozen']===true,'freeze clears active without deleting champion');
    verify($frozen['champions']['gorgo']['paused_feed_seconds']>=199,'freeze stores remaining feeding timer');
    reject(fn()=>Game::mutate($id,'championRaise',['type'=>'gorgo']),'cannot duplicate owned frozen champion');
    Game::mutate($id,'championRaise',['type'=>'drull']);
    reject(fn()=>Game::mutate($id,'championThaw',['type'=>'gorgo']),'thaw requires empty active slot');
    Game::mutate($id,'championFreeze',[]);Game::mutate($id,'championRaise',['type'=>'fomor']);Game::mutate($id,'championFreeze',[]);
    $allFrozen=Game::loadBase($id);verify(count($allFrozen['champions'])===3&&$allFrozen['activeChampion']===null,'all three can temporarily freeze to switch');
    verify(count(array_filter($allFrozen['champions'],fn($c)=>$c['frozen']))===3,'roster is preserved during three-way switching');
    reject(fn()=>Game::mutate($id,'championRaise',['type'=>'fomor']),'three-roster duplicate raise');
    $base=$allFrozen;$base['buildings'][$chi]['hp']-=1;Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'championThaw',['type'=>'gorgo']),'thaw requires fully repaired chamber');
    $base['buildings'][$chi]['hp']=$base['buildings'][$chi]['max_hp'];$base['buildings'][$ci]['hp']=0;Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'championThaw',['type'=>'gorgo']),'thaw requires living cage');
    $base['buildings'][$ci]['hp']=$base['buildings'][$ci]['max_hp'];$paused=$base['champions']['gorgo']['paused_feed_seconds'];Game::saveBase($id,$base);
    $thawed=Game::mutate($id,'championThaw',['type'=>'gorgo']);
    verify($thawed['activeChampion']==='gorgo'&&!$thawed['champions']['gorgo']['frozen'],'thaw restores chosen individual');
    verify(abs($thawed['champions']['gorgo']['next_feed_at']-time()-$paused)<=1,'thaw resumes paused feeding timer');
    verify($thawed['champions']['gorgo']['level']===2&&abs($thawed['champions']['gorgo']['hp']-12000)<0.001,'thaw preserves evolution and health');
    foreach(['drull','fomor','gorgo'] as $type){Game::mutate($id,'championFreeze',[]);$cycled=Game::mutate($id,'championThaw',['type'=>$type]);verify($cycled['activeChampion']===$type,'cycle all champions '.$type);}
    // Level-six meals now earn bonus tiers; tier-three maintenance grants no healing.
    $base=Game::loadBase($id);$base['champions']['gorgo']['level']=6;$base['champions']['gorgo']['hp']=5000;$base['champions']['gorgo']['feeds']=0;$base['champions']['gorgo']['last_heal_at']=time();$base['army']['crabatron']=20;Game::saveBase($id,$base);due($id,'gorgo');
    $meal=Game::mutate($id,'championFeed',[]);verify($meal['army']['crabatron']===0&&$meal['champions']['gorgo']['level']===6&&$meal['champions']['gorgo']['feeds']===0,'maxlevel maintenance consumes recipe without fake evolution');
    verify($meal['champions']['gorgo']['bonus_feeds']===1&&abs($meal['champions']['gorgo']['hp']-6875)<20&&$meal['champions']['gorgo']['next_feed_at']>time(),'first bonus meal earns tier one and only its 1875 HP increase');
    $base=due($id,'gorgo');$base['champions']['gorgo']['bonus_feeds']=3;$base['champions']['gorgo']['hp']=5000;$base['champions']['gorgo']['last_heal_at']=time();$base['army']['crabatron']=20;Game::saveBase($id,$base);$maintenance=Game::mutate($id,'championFeed',[]);
    verify($maintenance['champions']['gorgo']['bonus_feeds']===3&&abs($maintenance['champions']['gorgo']['hp']-5000)<20&&$maintenance['army']['crabatron']===0,'tier-three maintenance retains bonus and consumes exact meal without healing grant');
    // Every recipe and final threshold is exercised through the same locked mutation.
    foreach (array_intersect_key(Catalog::champions(), array_flip(['gorgo','drull','fomor'])) as $type=>$spec) {
        for ($level=1;$level<6;$level++) {
            $base=Game::loadBase($id);$base['activeChampion']=$type;
            foreach ($base['champions'] as $key=>&$record) { $record['frozen']=$key!==$type; } unset($record);
            $base['champions'][$type]['level']=$level;$base['champions'][$type]['hp']=1;
            $base['champions'][$type]['feeds']=$spec['feedsRequired'][$level]-1;
            $base['champions'][$type]['next_feed_at']=time()-1;$base['champions'][$type]['last_hunger_at']=time()-1;
            $base['champions'][$type]['last_heal_at']=time();
            $base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);
            foreach ($spec['feedRecipes'][$level] as $food=>$amount) { $base['army'][$food]=$amount; }
            Game::saveBase($id,$base);$evolved=Game::mutate($id,'championFeed',[]);
            verify($evolved['champions'][$type]['level']===$level+1&&$evolved['champions'][$type]['feeds']===0,'recipe evolves '.$type.' to '.($level+1));
            verify($evolved['champions'][$type]['hp']===Catalog::championStats($type,$level+1)['hp']&&array_sum($evolved['army'])===0,'evolution consumes exact meal and restores health '.$type.' '.$level);
        }
    }
    $base=Game::loadBase($id);$base['activeChampion']='gorgo';foreach ($base['champions'] as $key=>&$record) { $record['frozen']=$key!=='gorgo'; } unset($record);Game::saveBase($id,$base);
    // New normal species follow existing Locker and hatchery rules.
    reject(fn()=>Game::mutate($id,'train',['type'=>'brain','quantity'=>1]),'Brain starts locked');
    $base=Game::loadBase($id);addBuilding($base,'locker',3,27,27);$base['resources']['goo']=30000;$base['resources']['putty']=30000;Game::saveBase($id,$base);
    $discovery=Game::mutate($id,'unlock',['type'=>'brain']);verify($discovery['unlockQueue'][0]['type']==='brain','Brain uses persistent discovery queue');
    $base=Game::loadBase($id);$base['unlockQueue'][0]['ready_at']=time()-1;$base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);Game::saveBase($id,$base);
    $trained=Game::mutate($id,'train',['type'=>'brain','quantity'=>1]);verify($trained['queue'][0]['type']==='brain','discovered Brain hatches normally');
    $base=Game::loadBase($id);$base['queue'][0]['ready_at']=time()-1;Game::saveBase($id,$base);$snap=Game::snapshot($id);verify($snap['army']['brain']===1,'Brain training persists finished monster');
    verify($snap['champions']===$snap['base']['champions']&&$snap['activeChampion']===$snap['base']['activeChampion'],'bootstrap includes champion state at root and base');
    $visit=Game::visit($id,$id+999999);verify($visit['activeChampion']==='gorgo'&&count($visit['champions'])===3,'visits expose visible champion roster');
    // Battle mutation guard remains authoritative for every champion action.
    $foe=Game::register('Champion Foe '.bin2hex(random_bytes(3)),'test-champion-password');$foeId=$foe['id'];
    $stmt=$pdo->prepare("INSERT INTO battles(attacker_id,defender_id,status,state_json,created_at,updated_at) VALUES(?,?,'active','{}',?,?)");$stmt->execute([$id,$foeId,time(),time()]);
    foreach(['championRaise','championFeed','championFreeze','championThaw'] as $action){reject(fn()=>Game::mutate($id,$action,['type'=>'gorgo']),'battle locks '.$action);}
    $pdo->rollBack();echo "Champion integration: $checks assertions passed; temporary data rolled back.\n";
}catch(Throwable $error){if($pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,(string)$error."\n");exit(1);}
