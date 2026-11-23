<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
$db=(string)getenv('MYSQL_TEST_DATABASE');
if(PHP_SAPI!=='cli'||!str_ends_with($db,'_test')){fwrite(STDERR,"Choose an empty disposable *_test database.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>$db,'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$pdo=Db::connect($config);if($pdo->query('SHOW TABLES')->fetch())throw new RuntimeException('An empty database is required.');
Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function opCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function opReject(callable $work,string $text):void{try{$work();}catch(RuntimeException $e){opCheck(str_contains($e->getMessage(),$text),$e->getMessage());return;}throw new RuntimeException("Expected guard $text");}
function opBuilding(string $type,int $id,int $x,int $y,int $level=1):array{$hp=Game::buildingStats($type,$level)['max_hp'];return ['id'=>$id,'x'=>$x,'y'=>$y,'type'=>$type,'level'=>$level,'max_hp'=>$hp,'hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];}
function opBaseAt(int $keeper,int $q,int $r):void{
    $b=Game::loadBase($keeper);$b['buildings'][]=opBuilding('housing',702,10,22,5);$b['buildings'][]=opBuilding('maproom',700,4,28,2);$b['buildings'][]=opBuilding('flinger',701,8,28,5);
    $b['shiny']=5000;$b['shield_until']=0;$b['army']['fink']=50;$b['resources']=array_fill_keys(['twig','pebble','putty','goo'],25000);$b['research']['fink']=4;
    Game::saveBase($keeper,$b);$stmt=Db::pdo()->prepare('UPDATE world_sites SET q=?,r=? WHERE home_user_id=?');$stmt->execute([$q,$r,$keeper]);
}
function opAgeBattle(int $id):void{$p=Db::pdo();$q=$p->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);$s=json_decode($q->fetchColumn(),true);$s['created_at']-=400;$p->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($s),$id]);}
function opFixtureSite(int $q,int $r):int{$p=Db::pdo();$n=time();$p->prepare("INSERT INTO world_sites(kind,q,r,name,level,state_json,revision,updated_at) VALUES('resource',?,?,'Outpost fixture',1,?,1,?)")->execute([$q,$r,json_encode(OutpostYards::initialState($n)),$n]);return (int)$p->lastInsertId();}
function opEdit(int $owner,int $id,array $order):array{
    $r=World::load($id)['state']['mr2Outpost']['revision'];return OutpostYards::edit($owner,['revision'=>$r,'siteId'=>$id]+$order);
}
function opAdvance(int $id):void{$s=World::load($id);$s['state']['last_tick']=time()-3;foreach($s['state']['buildings'] as &$b){if($b['ready_at'])$b['ready_at']=time()-1;if($b['fortify_ready_at']??0)$b['fortify_ready_at']=time()-1;}unset($b);foreach($s['state']['queue'] as &$job)$job['ready_at']=time()-1;unset($job);World::save($s);}
try{
    $owner=Game::register('Outpost Keeper','disposable-test-password')['id'];$peer=Game::register('Frontier Peer','disposable-test-password')['id'];
    opBaseAt($owner,134,12);opBaseAt($peer,136,12);$id=opFixtureSite(135,12);
    $scout=World::site($owner,$id);opCheck(!$scout['outpostYard']['owned']&&$scout['canAttack'],'Real Map Room 2 invasion is available');opCheck(!isset($scout['outpostYard']['army']),'Scouts cannot read private field troops');
    $b=BattleService::startSite($owner,$id);BattleService::deploy($owner,$b['id'],['fink'=>25],16,6);opAgeBattle($b['id']);$b=BattleService::poll($owner,$b['id']);
    opCheck($b['status']==='finished'&&($b['result']['outpostTakeover']??false)&&!$b['result']['captured'],'Real Flinger invasion breaches the native site without charging or transferring');
    opCheck(World::load($id)['owner_id']===null,'Neutral outpost stays neutral before payment');
    $q=World::site($owner,$id)['outpostYard']['takeover'];opCheck($q['price']['each']===500&&$q['eligible'],'Adjacent wild takeover costs 500 of each local material');
    opReject(fn()=>OutpostYards::claim($peer,['siteId'=>$id,'battle'=>$b['id'],'payment'=>'materials']),'no longer available');
    $buy=['siteId'=>$id,'battle'=>$b['id'],'payment'=>'materials'];$bank=Game::loadBase($owner)['resources'];$v=OutpostYards::claim($owner,$buy);OutpostYards::claim($owner,$buy);
    opCheck((int)$bank['twig']-(int)Game::loadBase($owner)['resources']['twig']===500,'Double submit charges exactly once');
    $c=$v['base']['buildings'];opCheck(count($c)===1&&$c[0]['type']==='mr2core'&&$c[0]['hp']===200000&&$v['site']['shield_until']>=time()+43195,'Paid takeover establishes the targetable 200,000 HP headquarters and a 12h shield');
    opCheck(!$v['outpostYard']['busy']&&count($v['outpostYard']['facilities'])>=20,'Workshop exposes native facilities');
    opReject(fn()=>opEdit($owner,$id,['op'=>'build','type'=>'hatchery','x'=>7,'y'=>7]),'Monster Housing');
    opReject(fn()=>opEdit($owner,$id,['op'=>'build','type'=>'magmatower','x'=>7,'y'=>7]),'Hell-raisers');
    $v=opEdit($owner,$id,['op'=>'fortify','building'=>1]);opAdvance($id);$v=World::site($owner,$id);opCheck($v['base']['buildings'][0]['fortification']===1,'Source-priced field headquarters armor persists on its native timer');
    opReject(fn()=>opEdit($peer,$id,['op'=>'repair']),'your own');
    opReject(fn()=>opEdit($owner,$id,['op'=>'build','type'=>'twig','x'=>16,'y'=>16]),'overlaps');
    $rev=$v['outpostYard']['revision'];$v=opEdit($owner,$id,['op'=>'build','type'=>'twig','x'=>6,'y'=>6]);opCheck($v['outpostYard']['workersBusy'],'Paid construction occupies the field worker');
    opReject(fn()=>OutpostYards::edit($owner,['siteId'=>$id,'revision'=>$rev,'op'=>'build','type'=>'twig','x'=>6,'y'=>11]),'changed');
    opAdvance($id);$v=World::site($owner,$id);opCheck($v['base']['buildings'][1]['level']===1,'Timed field construction survives a reload');
    $v=opEdit($owner,$id,['op'=>'move','building'=>$v['base']['buildings'][1]['id'],'x'=>7,'y'=>7]);
    opCheck($v['base']['buildings'][1]['x']==7,'Precise relocation saved');
    $v=opEdit($owner,$id,['op'=>'upgrade','building'=>$v['base']['buildings'][1]['id']]);opAdvance($id);$v=World::site($owner,$id);
    opCheck($v['base']['buildings'][1]['level']===2,'Paid upgrade settles at the desired level');
    $site=World::load($id);$site['state']['last_tick']-=400;$site['state']['buildings'][0]['hp']=0;World::save($site);
    $before=Game::loadBase($owner)['resources'];$v=opEdit($owner,$id,['op'=>'repair']);opCheck($v['base']['buildings'][0]['hp']===200000,'Destroyed field core repairs immediately');
    $v=opEdit($owner,$id,['op'=>'collect']);opCheck(Game::loadBase($owner)['resources']['twig']>$before['twig'],'Produced resources collect into the home bank');
    foreach([1=>113,2=>148,3=>171] as $k=>$count){
        $v=opEdit($owner,$id,['op'=>'kit','kit'=>$k,'payment'=>'shiny']);opCheck(count($v['base']['buildings'])===$count,'Preset '.$k.' installs all '.$count.' buildings');
        $s=World::load($id)['state'];opCheck(count(Planner::validateLayout($s,Planner::positions($s)))===$count,'Preset '.$k.' fits without collision');
    }
    $v=opEdit($owner,$id,['op'=>'saveLayout','slot'=>1]);$v=opEdit($owner,$id,['op'=>'loadLayout','slot'=>1]);opCheck(count($v['outpostYard']['planner']['slots'][0]['positions'])===171,'Planner persists a complete 171-piece layout');
    $v=opEdit($owner,$id,['op'=>'send','type'=>'fink','quantity'=>2]);$bid=array_values(array_filter($v['base']['buildings'],fn($b)=>$b['type']==='bunker'))[0]['id'];
    $v=opEdit($owner,$id,['op'=>'station','type'=>'fink','quantity'=>1,'building'=>$bid]);opCheck($v['base']['garrison'][$bid]['fink']===1,'Local bunker removes a real field troop from housing');
    $v=opEdit($owner,$id,['op'=>'recall','type'=>'fink','quantity'=>1,'building'=>$bid]);$v=opEdit($owner,$id,['op'=>'return','type'=>'fink','quantity'=>1]);opCheck($v['outpostYard']['army']['fink']===1,'Recalling and returning troops preserves both armies');
    $v=opEdit($owner,$id,['op'=>'train','type'=>'fink','quantity'=>2]);$v=opEdit($owner,$id,['op'=>'rush','price'=>$v['outpostYard']['rush']['price']]);opCheck($v['outpostYard']['army']['fink']===3&&!$v['outpostYard']['queue'],'Working Control Center rushes the paid field batch once');
    $home=Game::loadBase($owner);$home['army']['fink']=0;Game::saveBase($owner,$home);$peerBase=Game::loadBase($peer);$peerBase['shield_until']=0;Game::saveBase($peer,$peerBase);
    $v=OutpostYards::launch($owner,$id,World::home($peer)['id']);opCheck($v['remaining']['fink']===3,'Field invasion draws the local army with no home Finks');
    $v=BattleService::deploy($owner,$v['id'],['fink'=>1],2,2);opCheck(World::load($id)['state']['army']['fink']===2&&Game::loadBase($owner)['army']['fink']===0,'Field Flinger debits only the origin yard');BattleService::retreat($owner,$v['id']);
    $s=World::load($id);$s['state']['shield_until']=0;$s['state']['regionInvite']=['recipient'=>99];
    foreach($s['state']['buildings'] as &$building){$building['hp']=$building['type']==='mr2core'?100:0;}unset($building);World::save($s);
    $b=BattleService::startSite($peer,$id);BattleService::deploy($peer,$b['id'],['fink'=>5],2,2);opAgeBattle($b['id']);$b=BattleService::poll($peer,$b['id']);
    opCheck($b['result']['outpostTakeover']&&World::load($id)['owner_id']===$owner,'Real siege on a keeper-held 171-piece outpost leaves ownership intact until payment');
    $fee=World::site($peer,$id)['outpostYard']['takeover']['price']['shiny'];$home=Game::loadBase($peer);$home['shiny']=$fee+100;Game::saveBase($peer,$home);
    $v=OutpostYards::claim($peer,['siteId'=>$id,'battle'=>$b['id'],'payment'=>'shiny']);$capt=World::load($id);
    opCheck($capt['owner_id']===$peer&&count($capt['state']['buildings'])===171&&array_sum($capt['state']['army'])===0&&!isset($capt['state']['regionInvite']),'Enemy takeover preserves structures, clears private troops and voids the previous invitation');
    opCheck(Game::loadBase($peer)['shiny']===100&&!World::site($owner,$id)['outpostYard']['owned'],'Shiny takeover settles both keepers and revokes the old workshop');
    opEdit($peer,$id,['op'=>'repair']);$v=opEdit($peer,$id,['op'=>'kit','kit'=>1,'payment'=>'materials']);
    opCheck($v['outpostYard']['workersBusy']&&count(array_filter($v['base']['buildings'],fn($b)=>$b['ready_at']>time()))===112,'Paid material kit puts its 112 non-core facilities on live construction clocks');
    $held=World::load($id);$wallCount=count(array_filter($held['state']['buildings'],fn($b)=>$b['type']==='wall'));
    $previous=Game::loadBase($peer)['questLog']['blocksBuilt']??0;
    opCheck(!empty(Game::loadBase($peer)['questLog']['outpostKit']) && $wallCount>0,'Paid kit grants original architect badge');
    foreach($held['state']['buildings'] as &$f){if($f['ready_at'])$f['ready_at']=time()-1;}unset($f);
    $held['state']['last_tick']=time()-3;World::save($held);World::site($peer,$id);World::site($peer,$id);
    opCheck((Game::loadBase($peer)['questLog']['blocksBuilt']??0)===min(200,$previous+$wallCount),'Completed material kit Blocks enter the owner journal once through the field yard');
    $abandoned=World::mutate($peer,'worldAbandon',['siteId'=>$id]);opCheck(!$abandoned['outpostYard']['owned']&&$abandoned['base']['buildings'][0]['type']==='townhall','Abandonment returns the site to its unclaimed tribe garrison');
    $bot=Game::register('Regional Bot','disposable-test-password')['id'];opBaseAt($bot,135,13);$pdo->prepare('UPDATE users SET is_bot=1 WHERE id=?')->execute([$bot]);
    $botBase=Game::loadBase($bot);$s=World::load($id);$s['owner_id']=$bot;$s['state']['buildings']=[opBuilding('mr2core',1,16,16)];$s['state']['garrison']=[];$s['state']['next_building_id']=2;World::save($s);
    $order=OutpostYards::botDevelop($bot,World::load($id),$botBase,4);opCheck($order==='build'&&World::load($id)['state']['buildings'][1]['type']==='sniper','A regional bot spends its own treasury to place a defensive tower');
    $s=World::load($id);$s['state']['buildings'][]=opBuilding('flinger',60,5,5,2);$s['state']['army']=['fink'=>7];World::save($s);
    $peerYard=Game::loadBase($peer);$peerYard['shield_until']=0;Game::saveBase($peer,$peerYard);activityFixtureWake($peer);
    $b=OutpostYards::botRaid($bot,[World::load($id)],true);
    opCheck($b!==null&&$b['status']==='active','Active regional bot opens a real Flinger invasion from its own field yard');
    $raw=$pdo->prepare('SELECT state_json FROM battles WHERE id=?');$raw->execute([$b['id']]);$sim=json_decode($raw->fetchColumn(),true);$scheduled=count($sim['botPlan'])>0||count($sim['units'])>0;
    activityFixtureAgeState($sim,20);$pdo->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($sim),$b['id']]);BattleService::poll($bot,$b['id']);
    opCheck($scheduled&&World::load($id)['state']['army']['fink']<7,'Presence-clock simulation debits real bot field soldiers');
    echo "$checks regional outpost checks passed.\n";
}catch(Throwable $e){fwrite(STDERR,$e."\n");exit(1);}
