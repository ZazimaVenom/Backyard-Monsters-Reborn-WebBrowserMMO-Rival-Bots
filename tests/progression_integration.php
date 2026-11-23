<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function verify(bool $condition,string $label):void{global $checks;$checks++;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function reject(callable $work,string $label):void{try{$work();}catch(RuntimeException){verify(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
function addBuilding(array &$base,string $type,int $level=1,float $x=4,float $y=4):int{$id=$base['next_building_id']++;$hp=Game::buildingStats($type,$level)['max_hp'];$base['buildings'][]=['id'=>$id,'type'=>$type,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'x'=>$x,'y'=>$y,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];return $id;}
function buildingIndex(array $base,int $id):int{foreach($base['buildings'] as $index=>$b){if((int)$b['id']===$id){return $index;}}throw new RuntimeException('Fixture building missing');}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE')?:'backyard_test','username'=>getenv('MYSQL_TEST_USER')?:'backyard_test','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1,'player_grace_seconds'=>1800];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
foreach(explode(';',file_get_contents(__DIR__.'/../app/schema.sql')) as $sql){if(trim($sql)!==''){$pdo->exec($sql);}}
$pdo->beginTransaction();
try{
    $user=Game::register('Progress '.bin2hex(random_bytes(4)),'test-progression-password');$id=$user['id'];$base=Game::loadBase($id);
    verify(Game::livingLevel($base,'flinger')===1,'new keeper starts with a living flinger');
    verify(count($base['unlocked'])===6,'new keeper has legacy species, not new free unlocks');
    reject(fn()=>Game::mutate($id,'train',['type'=>'bolt','quantity'=>1]),'locked monster training');
    reject(fn()=>Game::mutate($id,'research',['type'=>'bolt']),'locked monster research');
    reject(fn()=>Game::mutate($id,'unlock',['type'=>'bolt']),'unlock without locker');
    $locker=addBuilding($base,'locker',1,4,25);$lockerIndex=buildingIndex($base,$locker);$base['buildings'][$lockerIndex]['hp']=0;Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'unlock',['type'=>'bolt']),'destroyed locker unlock');
    $base['buildings'][$lockerIndex]['hp']=$base['buildings'][$lockerIndex]['max_hp'];$base['buildings'][$lockerIndex]['ready_at']=time()+999;Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'unlock',['type'=>'bolt']),'unfinished locker unlock');
    $base['buildings'][$lockerIndex]['ready_at']=0;Game::saveBase($id,$base);
    $beforeGoo=$base['resources']['goo'];$result=Game::mutate($id,'unlock',['type'=>'bolt']);
    verify($result['base']['resources']['goo']===$beforeGoo-Catalog::monsters()['bolt']['unlockCost']['goo'],'unlock charges exact goo once');
    verify(count($result['unlockQueue'])===1&&$result['unlockQueue'][0]['ready_at']>time(),'unlock is a persistent timer');
    reject(fn()=>Game::mutate($id,'unlock',['type'=>'eyera']),'only one unlock job');
    reject(fn()=>Game::mutate($id,'train',['type'=>'bolt','quantity'=>1]),'unlocking monster remains locked until timer');
    $base=Game::loadBase($id);$base['unlockQueue'][0]['ready_at']=time()-1;Game::saveBase($id,$base);$state=Game::snapshot($id);
    verify(in_array('bolt',$state['unlocked'],true)&&$state['unlockQueue']===[],'completed unlock persisted');
    verify(count(array_filter(Game::snapshot($id)['unlocked'],fn($type)=>$type==='bolt'))===1,'unlock completion idempotent');
    reject(fn()=>Game::mutate($id,'unlock',['type'=>'bolt']),'duplicate unlock');
    reject(fn()=>Game::mutate($id,'unlock',['type'=>'eyera']),'townhall gate');
    $base=Game::loadBase($id);$base['buildings'][0]['level']=2;Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'unlock',['type'=>'eyera']),'locker level gate');
    // Queue cancellation refunds actual costs, leaves no monster, and closes one lane's gap.
    $first=Game::mutate($id,'train',['type'=>'bolt','quantity'=>1]);$firstJob=end($first['queue']);$second=Game::mutate($id,'train',['type'=>'pokey','quantity'=>1]);$secondJob=end($second['queue']);$gooBefore=$second['base']['resources']['goo'];
    $cancelled=Game::mutate($id,'cancelTrain',['id'=>$firstJob['id']]);
    verify($cancelled['refund']['goo']===350&&$cancelled['base']['resources']['goo']===$gooBefore+350,'unfinished cancellation refunds exact paid amount');
    verify(count($cancelled['queue'])===1&&$cancelled['queue'][0]['id']===$secondJob['id'],'cancellation removes only selected job');
    verify($cancelled['queue'][0]['ready_at']<$secondJob['ready_at'],'cancellation closes training lane gap');
    verify($cancelled['army']['bolt']===0,'cancellation does not mint a monster');
    reject(fn()=>Game::mutate($id,'cancelTrain',['id'=>$firstJob['id']]),'replayed cancellation');
    reject(fn()=>Game::mutate($id,'cancelTrain',['id'=>true]),'boolean queue ID');
    $base=Game::loadBase($id);$base['queue'][0]['ready_at']=time()-1;Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'cancelTrain',['id'=>$secondJob['id']]),'already completed job cannot refund');
    $state=Game::snapshot($id);verify($state['army']['pokey']===25,'completed job hatches rather than refunds');
    // Full storage caps refunds; a legacy queue without new paid/timing fields still cancels.
    $base=Game::loadBase($id);$base['queue']=[['id'=>500,'type'=>'pokey','quantity'=>1,'lane'=>2,'ready_at'=>time()+30]];$base['resources']['goo']=Game::capacity($base)-25;Game::saveBase($id,$base);
    $cancelled=Game::mutate($id,'cancelTrain',['id'=>500]);verify($cancelled['refund']['goo']===25&&$cancelled['refund']['overflow']===225,'legacy cancellation caps storage without minting');
    // Juicing consumes only owned reserves; failed actions preserve army and goo.
    reject(fn()=>Game::mutate($id,'juice',['type'=>'pokey','quantity'=>1]),'juicing without juicer');
    $base=Game::loadBase($id);$juicer=addBuilding($base,'juicer',1,4,10);$ji=buildingIndex($base,$juicer);$base['buildings'][$ji]['hp']=0;Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'juice',['type'=>'pokey','quantity'=>1]),'destroyed juicer');
    $base['buildings'][$ji]['hp']=$base['buildings'][$ji]['max_hp'];Game::saveBase($id,$base);
    $armyBefore=$base['army']['pokey'];reject(fn()=>Game::mutate($id,'juice',['type'=>'pokey','quantity'=>1]),'full goo store preserves monster');verify(Game::loadBase($id)['army']['pokey']===$armyBefore,'full-store rejection does not consume monster');
    $base['resources']['goo']=1000;Game::saveBase($id,$base);$juiced=Game::mutate($id,'juice',['type'=>'pokey','quantity'=>2]);
    verify($juiced['army']['pokey']===$armyBefore-2&&$juiced['refund']['goo']===150,'juicing consumes exactly two and returns thirty percent');
    verify($juiced['base']['resources']['goo']===1150,'juicer refund persisted');
    reject(fn()=>Game::mutate($id,'juice',['type'=>'pokey','quantity'=>999]),'juicing quantity limit');
    reject(fn()=>Game::mutate($id,'juice',['type'=>'dave','quantity'=>1]),'juicing unowned unit');
    reject(fn()=>Game::mutate($id,'juice',['type'=>'pokey','quantity'=>-1]),'negative juicing quantity');
    $base=Game::loadBase($id);$base['resources']['goo']=Game::capacity($base)-10;Game::saveBase($id,$base);$juiced=Game::mutate($id,'juice',['type'=>'pokey','quantity'=>1]);verify($juiced['refund']['goo']===10&&$juiced['refund']['overflow']===65,'partial juicer refund respects resource cap');
    // Bunker transfers conserve every unit and have separate space from housing.
    $base=Game::loadBase($id);$bunker=addBuilding($base,'bunker',1,28,27);$bi=buildingIndex($base,$bunker);$base['resources']['goo']=10000;Game::saveBase($id,$base);$before=$base['army']['pokey'];$housingBefore=Game::housingUsed($base);
    $garrisoned=Game::mutate($id,'garrison',['id'=>$bunker,'type'=>'pokey','quantity'=>5]);
    verify($garrisoned['army']['pokey']===$before-5&&$garrisoned['garrison'][$bunker]['pokey']===5,'garrison transfers, never copies');
    $base=Game::loadBase($id);verify(Game::bunkerUsed($base,$bunker)===50&&Game::bunkerCapacity($base,$bunker)===100,'bunker capacity uses housing-size units');
    verify(Game::housingUsed($base)===$housingBefore-50,'garrison does not consume army housing');
    reject(fn()=>Game::mutate($id,'garrison',['id'=>$bunker,'type'=>'pokey','quantity'=>6]),'garrison capacity overflow');
    reject(fn()=>Game::mutate($id,'garrison',['id'=>1,'type'=>'pokey','quantity'=>1]),'non-bunker target');
    reject(fn()=>Game::mutate($id,'garrison',['id'=>999999,'type'=>'pokey','quantity'=>1]),'foreign or nonexistent bunker');
    reject(fn()=>Game::mutate($id,'ungarrison',['id'=>$bunker,'type'=>'pokey','quantity'=>6]),'return more than bunker owns');
    $returned=Game::mutate($id,'ungarrison',['id'=>$bunker,'type'=>'pokey','quantity'=>2]);verify($returned['army']['pokey']===$before-3&&$returned['garrison'][$bunker]['pokey']===3,'ungarrison conserves monsters');
    $base=Game::loadBase($id);$base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);$base['army']['pokey']=40;Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'ungarrison',['id'=>$bunker,'type'=>'pokey','quantity'=>1]),'ungarrison checks full housing');
    $base['army']['pokey']=39;$base['queue']=[['id'=>600,'type'=>'pokey','quantity'=>1,'lane'=>2,'ready_at'=>time()+30]];Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'ungarrison',['id'=>$bunker,'type'=>'pokey','quantity'=>1]),'ungarrison counts queued housing');
    $base['queue']=[];$base['army']['pokey']=30;$base['buildings'][$bi]['hp']=0;Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'garrison',['id'=>$bunker,'type'=>'pokey','quantity'=>1]),'destroyed bunker station');
    reject(fn()=>Game::mutate($id,'ungarrison',['id'=>$bunker,'type'=>'pokey','quantity'=>1]),'destroyed bunker return');
    $base['buildings'][$bi]['hp']=$base['buildings'][$bi]['max_hp'];$base['buildings'][$bi]['ready_at']=time()+100;Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'garrison',['id'=>$bunker,'type'=>'pokey','quantity'=>1]),'upgrading bunker station');
    $base['buildings'][$bi]['ready_at']=0;Game::saveBase($id,$base);
    // Hidden traps are only visible to their owner until triggered; repair rearms.
    $base=Game::loadBase($id);$trap=addBuilding($base,'trap',1,26,4);$ti=buildingIndex($base,$trap);Game::saveBase($id,$base);
    verify(in_array($trap,array_column(Game::visit($id,$id)['buildings'],'id'),true),'owner sees own trap');
    verify(!in_array($trap,array_column(Game::visit($id,$id+99999)['buildings'],'id'),true),'foreign visit hides armed trap');
    $base['buildings'][$ti]['level']=0;$base['buildings'][$ti]['hp']=0;$base['buildings'][$ti]['ready_at']=time()+100;$base['buildings'][$ti]['upgrade_to']=1;Game::saveBase($id,$base);verify(!in_array($trap,array_column(Game::visit($id,$id+99999)['buildings'],'id'),true),'unfinished trap remains concealed');$base['buildings'][$ti]['level']=1;$base['buildings'][$ti]['ready_at']=0;$base['buildings'][$ti]['upgrade_to']=null;
    $base['buildings'][$ti]['hp']=0;Game::saveBase($id,$base);verify(in_array($trap,array_column(Game::visit($id,$id+99999)['buildings'],'id'),true),'triggered trap rubble visible');
    $base['resources']['twig']=10000;$base['resources']['pebble']=10000;$base['resources']['putty']=10000;Game::saveBase($id,$base);Game::mutate($id,'repair',['id'=>$trap]);verify(!in_array($trap,array_column(Game::visit($id,$id+99999)['buildings'],'id'),true),'repair rearms and conceals trap');
    // Old content normalizes without a reset, including existing queues and extra extension data.
    $legacy=Game::initialBase($id);$legacy['buildings']=array_values(array_filter($legacy['buildings'],fn($b)=>$b['type']!=='flinger'));$legacy['resources']['twig']=4321;$legacy['custom_extension']=['marker'=>'preserved'];
    unset($legacy['unlocked'],$legacy['unlockQueue'],$legacy['garrison'],$legacy['content_version']);foreach(['bolt','eyera','fang','wormzer','teratorn','zafreeti'] as $type){unset($legacy['army'][$type],$legacy['research'][$type]);}
    Game::saveBase($id,$legacy);$old=Game::loadBase($id);verify($old['resources']===$legacy['resources']&&$old['buildings']===$legacy['buildings']&&$old['custom_extension']===$legacy['custom_extension'],'old world resources layouts and unknown fields preserved');
    verify(Game::livingLevel($old,'flinger')===0,'old world is not given a free flinger');
    $paid=Game::mutate($id,'build',['type'=>'flinger','x'=>7,'y'=>7]);verify($paid['base']['resources']['twig']===3321,'legacy keeper can construct flinger through normal economy');
    $pdo->rollBack();echo "Progression integration: $checks assertions passed; temporary data rolled back.\n";
}catch(Throwable $error){if($pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,(string)$error."\n");exit(1);}
