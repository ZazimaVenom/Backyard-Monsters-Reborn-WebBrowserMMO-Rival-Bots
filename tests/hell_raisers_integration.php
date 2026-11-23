<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$config=['database'=>getenv('MYSQL_TEST_DATABASE')?:'','host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(PHP_SAPI!=='cli'||!str_ends_with($config['database'],'_hell_test')){fwrite(STDERR,"Set MYSQL_TEST_DATABASE to a disposable empty database ending in _hell_test.\n");exit(2);}
$db=Db::connect($config);Db::useConnection($db,$config);
if($db->query('SHOW TABLES')->fetch())throw new RuntimeException('Use an empty test schema.');
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$checks=0;
function yes(bool $value,string $why):void{global $checks;if(!$value)throw new RuntimeException($why);++$checks;}
function refuses(callable $call,string $msg):void{try{$call();}catch(RuntimeException $e){yes(str_contains($e->getMessage(),$msg),$e->getMessage());return;}throw new RuntimeException('Expected rejection: '.$msg);}
$id=Game::register('Moloch hunter','disposable-passphrase')['id'];$owner=Game::loadBase($id);
yes(!Tribes::listing($id)['hellraisers']['mapReady'],'Map Room is a playable gate');
refuses(fn()=>Tribes::visit($id,1214),'Map Room');
$map=$owner['buildings'][0];$map['id']=$owner['next_building_id']++;$map['type']='maproom';$map['x']=5;$map['y']=20;
$owner['buildings'][]=$map;Game::saveBase($id,$owner);$shield=$owner['shield_until'];
$event=Tribes::listing($id)['hellraisers'];
yes(count($event['camps'])===21&&$event['phase']==='available'&&count($event['prizes'])===9,'Native Moloch layouts and functional store catalog');
foreach($event['camps'] as $card){$yard=Tribes::visit($id,$card['stage']);yes($yard['tribe']['xp']===$card['xp']&&$yard['tribe']['sourceId']===$card['stage']-1000,'Scout native source and XP');}
yes(!isset(Game::loadBase($id)['hellRaisers']),'Scouting has no timer or side effects');
function launch(int $id,?int $prev,string $token,int $run=1):array{return BattleService::startTribe($id,1214,['previousId'=>$prev,'requestId'=>str_pad($token,21,'x'),'run'=>$run]);}
refuses(fn()=>launch($id,null,'incorrect',2),'run changed');
$b=launch($id,null,'unlaunched');$first=$b['id'];yes($b['target']['event']==='hellraisers'&&$b['target']['sourceId']===214,'Dedicated event target');
yes(launch($id,null,'unlaunched')['id']===$first,'Duplicate requests return the same battle');
$empty=BattleService::retreat($id,$first);yes($empty['result']['campaign']['xp']===0,'No XP for viewing and surrendering');
$b=launch($id,$first,'engaged');$fight=$b['id'];BattleService::deploy($id,$fight,['pokey'=>1],1.0,1.0);
$result=BattleService::retreat($id,$fight)['result'];$expected=count($b['buildings']);$state=Game::loadBase($id);
yes($result['campaign']['xp']===$expected&&$state['hellRaisers']['balance']===$expected,'A deployed battle pays its actual frozen foundation count');
yes($result['rankDelta']===['attacker'=>0,'defender'=>0]&&$state['shield_until']===$shield,'PvE preserves rank and home shield');
yes(launch($id,$first,'engaged')['result']['campaign']===$result['campaign'],'Reopened finished receipt never pays twice');
$started=$state['hellRaisers']['started'];$end=$started+HellRaisers::DURATION;
yes(HellRaisers::view($state,$end-1)['phase']==='active'&&HellRaisers::view($state,$end)['phase']==='shop','Four-day interval boundary');
yes(HellRaisers::view($state,$end+86400)['phase']==='replay','Store closing boundary');
refuses(fn()=>HellRaisers::begin($state,1214,1,$end+30),'ended');
refuses(fn()=>HellRaisers::buy($state,'rezghul',1,$end+86400),'closed');
refuses(fn()=>HellRaisers::purchase($id,'rezghul',1),'Earn more');
refuses(fn()=>HellRaisers::purchase($id,'korath2',1),'previous prize');
$state['hellRaisers']['balance']=50000;Game::saveBase($id,$state);
foreach(['rezghul'=>999,'vorg'=>999,'slimeattikus'=>9999,'korath1'=>999,'korath2'=>9999,'korath3'=>999,'spurtz1'=>9999,'spurtz2'=>999,'spurtz3'=>9999] as $prize=>$price) {
    $before=Game::loadBase($id)['hellRaisers']['balance'];$purchase=HellRaisers::purchase($id,$prize,1);
    yes($purchase['prize']['owned']&&$purchase['event']['balance']===$before-$price,'Native payable prize '.$prize);
    yes(HellRaisers::purchase($id,$prize,1)['event']['balance']===$before-$price,'Idempotent purchase '.$prize);
}
$state=Game::loadBase($id);$balance=$state['hellRaisers']['balance'];
yes(Brukkarg::score($state)===0&&MonsterMadness::view($state,time())['score']===0,'Store never fabricates scores in other events');
yes(SpurtzRewards::status($state)['type']==='diamondspurtz'&&MonsterMadness::power($state)===3&&in_array('rezghul',$state['unlocked'],true),'Permanent real gameplay entitlements');
$replay=HellRaisers::begin($state,1214,2,$end+86400);
yes($state['hellRaisers']['run']===2&&$state['hellRaisers']['score']===0&&$state['hellRaisers']['best']===$expected&&$state['hellRaisers']['balance']===$balance,'Replay starts fresh and preserves purchases and XP');
// The map's health threshold is independent of the number of buildings or whether its Hall fell.
foreach ([11=>false,10=>true] as $remainingPercent=>$shouldWin) {
    $when=$state['hellRaisers']['started'];
    $fake=['id'=>1000+$remainingPercent,'target'=>['event'=>'hellraisers','run'=>2,'sourceId'=>214],
        'attacker'=>['id'=>$id],'created_at'=>$when,'units'=>[['type'=>'pokey','team'=>'attacker']],
        'buildings'=>[['type'=>'townhall','hp'=>0,'max_hp'=>100],['type'=>'sniper','hp'=>$remainingPercent*10,'max_hp'=>900],['type'=>'wall','hp'=>500,'max_hp'=>500]],
        'result'=>['destruction'=>50,'attackerWon'=>true]];
    HellRaisers::settle($state,$fake,$when+10);
    yes($fake['result']['attackerWon']===$shouldWin&&$fake['result']['destruction']===round(100.0-$remainingPercent,2),'90% non-wall health rule');
}
echo "Hell Raisers: $checks checks passed.\n";
