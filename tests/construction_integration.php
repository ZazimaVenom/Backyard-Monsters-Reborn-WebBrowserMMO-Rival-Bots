<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function verify(bool $condition,string $label):void{global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function reject(callable $work,string $label):void{try{$work();}catch(RuntimeException){verify(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
function indexOf(array $base,int $id):int{foreach($base['buildings'] as $i=>$building){if((int)$building['id']===$id){return $i;}}throw new RuntimeException('Fixture missing building');}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE')?:'backyard_construction_test','username'=>getenv('MYSQL_TEST_USER')?:'backyard_test','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(!str_ends_with($config['database'],'_construction_test')){throw new RuntimeException('Use a disposable database ending in _construction_test.');}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
foreach(explode(';',file_get_contents(__DIR__.'/../app/schema.sql')) as $sql){if(trim($sql)!==''){$pdo->exec($sql);}}
$pdo->beginTransaction();
try{
    $id=Game::register('Workers '.bin2hex(random_bytes(4)),'construction-test-password')['id'];
    $base=Game::loadBase($id);verify($base['workers']===1,'new registered yard receives one source-default worker');verify($base['planner']===['slots'=>[]],'new yard has no saved layouts or free planner');
    $base['buildings'][0]['level']=5;$base['buildings'][0]['max_hp']=Game::buildingStats('townhall',5)['max_hp'];$base['buildings'][0]['hp']=$base['buildings'][0]['max_hp'];$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],1000000);Game::saveBase($id,$base);
    $towerId=(int)$base['buildings'][6]['id'];$hatchId=(int)$base['buildings'][1]['id'];$housingId=(int)$base['buildings'][2]['id'];
    $started=Game::mutate($id,'fortify',['id'=>$towerId]);verify($started['base']['construction']['busy']===1&&$started['base']['construction']['available']===0,'paid fortification occupies the shared worker');
    $before=Game::loadBase($id);
    foreach([['build',['type'=>'planner','x'=>28,'y'=>28]],['upgrade',['id'=>$hatchId]],['fortify',['id'=>1]]] as [$action,$input]){reject(fn()=>Game::mutate($id,$action,$input),'shared worker blocks '.$action);$after=Game::loadBase($id);verify($after['resources']===$before['resources']&&$after['buildings']===$before['buildings'],'rejected '.$action.' neither charges nor mutates work');}
    $moved=Game::mutate($id,'move',['id'=>$housingId,'x'=>27,'y'=>17]);verify($moved['base']['buildings'][2]['x']===27.0&&$moved['base']['construction']['busy']===1,'moving idle building is independent of worker capacity');
    $base=Game::loadBase($id);$base['buildings'][2]['hp']-=100;Game::saveBase($id,$base);$fixed=Game::mutate($id,'repair',['id'=>$housingId]);verify($fixed['base']['buildings'][2]['hp']===$fixed['base']['buildings'][2]['max_hp']&&$fixed['base']['construction']['busy']===1,'instant free repair does not require another worker');
    $trained=Game::mutate($id,'train',['type'=>'pokey','quantity'=>1]);verify(count($trained['queue'])===1&&$trained['base']['construction']['busy']===1,'hatchery queue has independent capacity');
    $collected=Game::mutate($id,'collect',[]);verify($collected['base']['construction']['busy']===1,'resource collection does not consume a worker');
    $before=Game::loadBase($id);$hire=Game::mutate($id,'hireWorker',['workers'=>5,'cost'=>0]);
    verify($hire['base']['construction']['capacity']===2&&$hire['base']['construction']['available']===1,'server buys exactly next worker and immediately makes capacity available');
    foreach(['twig'=>10000,'pebble'=>10000,'putty'=>1000,'goo'=>0] as $resource=>$amount){verify(Game::loadBase($id)['resources'][$resource]===$before['resources'][$resource]-$amount,'exact authoritative hire charge '.$resource);}
    $upgrading=Game::mutate($id,'upgrade',['id'=>$hatchId]);verify($upgrading['base']['construction']['busy']===2,'second worker permits concurrent building upgrade');
    reject(fn()=>Game::mutate($id,'build',['type'=>'planner','x'=>28,'y'=>28]),'third concurrent job rejected with two paid workers');
    $cancel=Game::mutate($id,'cancelFortify',['id'=>$towerId]);verify($cancel['base']['construction']['available']===1&&$cancel['base']['construction']['busy']===1,'cancelling fortification immediately releases its occupied worker');
    reject(fn()=>Game::mutate($id,'cancelFortify',['id'=>$towerId]),'second cancellation cannot release or refund twice');
    $before=Game::loadBase($id);$built=Game::mutate($id,'build',['type'=>'planner','x'=>28,'y'=>28]);$planner=$built['base']['buildings'][count($built['base']['buildings'])-1];$plannerId=(int)$planner['id'];
    verify($planner['type']==='planner'&&$planner['level']===0&&$planner['ready_at']>=time()+143,'paid Planner starts proper construction timer');
    verify($built['base']['resources']['twig']===$before['resources']['twig']-2500&&$built['base']['resources']['pebble']===$before['resources']['pebble']-2500,'Planner uses real resource purchase');
    verify(!$built['base']['planner']['available'],'unfinished Planner cannot enable saved layouts');
    reject(fn()=>Game::mutate($id,'build',['type'=>'planner','x'=>28,'y'=>4]),'only one Planner may be owned including pending building');
    $base=Game::loadBase($id);$base['workers']=1;$ti=indexOf($base,$towerId);$base['buildings'][$ti]['fortify_to']=1;$base['buildings'][$ti]['fortify_ready_at']=time()+2000;$base['buildings'][$ti]['fortify_paid']=Catalog::fortificationStats('sniper',1)['cost'];$base['buildings'][$ti]['hp']-=13;$legacy=$base['buildings'];Game::saveBase($id,$base);
    $snapshot=Game::snapshot($id);$status=$snapshot['base']['construction'];verify($status['capacity']===1&&$status['busy']===3&&$status['overCapacity'],'legacy paid parallel timers remain over purchased capacity');
    $after=Game::loadBase($id);foreach($legacy as $i=>$building){foreach(['ready_at','fortify_ready_at','fortify_paid','fortify_to','hp','x','y'] as $key){verify(($after['buildings'][$i][$key]??null)===($building[$key]??null),'legacy timer/injury/geometry preserved '.$i.' '.$key);}}
    reject(fn()=>Game::mutate($id,'upgrade',['id'=>$housingId]),'legacy overload blocks only new work');
    $base=Game::loadBase($id);$base['buildings'][indexOf($base,$hatchId)]['ready_at']=time()-1;$base['buildings'][indexOf($base,$plannerId)]['ready_at']=time()-1;Game::saveBase($id,$base);$snapshot=Game::snapshot($id);
    verify($snapshot['base']['construction']['busy']===1&&$snapshot['base']['construction']['available']===0&&!$snapshot['base']['construction']['overCapacity'],'legacy jobs finish normally without buying more workers');
    verify($snapshot['base']['planner']['available'],'completed paid Planner enables planning');
    Game::mutate($id,'cancelFortify',['id'=>$towerId]);verify(Game::snapshot($id)['base']['construction']['available']===1,'remaining legacy cancellation frees original capacity');
    $visit=Game::visit($id,$id+9999);verify(!array_key_exists('construction',$visit)&&!array_key_exists('planner',$visit),'visitors cannot read worker purchases or private layout slots');
    $owned=Game::snapshot($id)['base'];verify(isset($owned['construction'],$owned['planner']),'owner receives authoritative construction and planner states');
    for($target=2;$target<=5;$target++){$result=Game::mutate($id,'hireWorker',[]);verify($result['base']['construction']['capacity']===$target,'paid progression reaches worker '.$target);}
    $before=Game::loadBase($id);reject(fn()=>Game::mutate($id,'hireWorker',[]),'sixth worker cannot be purchased');verify(Game::loadBase($id)['resources']===$before['resources'],'maximum capacity rejection charges nothing');
    $edge=Game::mutate($id,'build',['type'=>'wall','x'=>1.4,'y'=>1.4]);verify($edge['base']['construction']['busy']===1,'exact nominal footprint boundary tolerates floating point roundoff');
    Game::mutate($id,'build',['type'=>'wall','x'=>2.35,'y'=>1.4]);verify(Game::snapshot($id)['base']['construction']['busy']===2,'exact separation boundary matches planner geometry');
    reject(fn()=>Game::mutate($id,'build',['type'=>'wall','x'=>1.39,'y'=>3]),'visible yard boundary overflow still rejected');
    reject(fn()=>Game::mutate($id,'build',['type'=>'wall','x'=>3.29,'y'=>1.4]),'visible footprint overlap still rejected');
    $foe=Game::register('Workers Foe '.bin2hex(random_bytes(3)),'construction-test-password')['id'];$q=$pdo->prepare("INSERT INTO battles(attacker_id,defender_id,status,state_json,created_at,updated_at) VALUES(?,?,'active','{}',?,?)");$q->execute([$id,$foe,time(),time()]);
    foreach(['hireWorker','build','upgrade','fortify'] as $action){reject(fn()=>Game::mutate($id,$action,['type'=>'wall','id'=>$towerId,'x'=>3,'y'=>3]),'active battle guard applies to '.$action);}
    $pdo->rollBack();echo "Construction integration: $checks assertions passed; temporary data rolled back.\n";
}catch(Throwable $error){if($pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,(string)$error."\n");exit(1);}
