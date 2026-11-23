<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$config=['database'=>getenv('MYSQL_TEST_DATABASE')?:'','host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(PHP_SAPI!=='cli'||!str_ends_with($config['database'],'_moloch_test')){fwrite(STDERR,"Select an empty database ending in _moloch_test.\n");exit(2);}
$db=Db::connect($config);Db::useConnection($db,$config);
if($db->query('SHOW TABLES')->fetch())throw new RuntimeException('Use an empty test schema.');
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$checks=0;
function yes(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);++$checks;}
function deny(callable $act,string $words):void{try{$act();}catch(RuntimeException $e){yes(str_contains($e->getMessage(),$words),$e->getMessage());return;}throw new RuntimeException('Accepted '.$words);}
function start(int $id,int $stage,?string $prev):array{return BaiterService::run($id,'molochStart',['stage'=>$stage,'previousId'=>$prev,'requestId'=>bin2hex(random_bytes(16))]);}
function finish(int $id,string $battle,bool $trainingTowers):array{
    // Advance only the clock. The victory fixture also supplies full-board, accelerated test tower fire.
    $b=Game::loadBase($id);$s=&$b['baiterPractice']['state'];$s['launch_at']=time()-400;
    if($trainingTowers)foreach($s['buildings'] as &$tower){if($tower['type']==='sniper'){$tower['_range']=100;$tower['_damage']=100000;$tower['_interval']=.1;$tower['_targetMode']='both';}}unset($tower);
    Game::saveBase($id,$b);
    for($i=0;$i<190;$i++){$r=BaiterService::run($id,'baiterPoll',['id'=>$battle]);if($r['battle']['status']==='finished')return $r;}
    throw new RuntimeException('Simulation did not finish.');
}
$a=Game::register('Surface defender','local-only-password')['id'];$rival=Game::register('Remote defender','local-only-password')['id'];
$orig=Game::loadBase($a);$shield=$orig['shield_until'];
yes(MolochSiege::view($orig)['cleared']===0&&!MolochSiege::homeMagmaLimit($orig)&&!MolochSiege::outpostMagma($orig),'New keeper starts without the award.');
yes(!isset(Game::publicBase($a,$orig,false)['molochSiege']),'Remote yards hide internal event state.');
deny(fn()=>start($a,10,null),'next wave');deny(fn()=>Game::mutate($a,'build',['type'=>'magmatower','x'=>27,'y'=>28]),'available building');
$cmd=['stage'=>1,'previousId'=>null,'requestId'=>'request-example-wave-one'];$x=BaiterService::run($a,'molochStart',$cmd);$battle=$x['session']['id'];
yes($x['battle']['molochStage']===1 && $x['battle']['target']['kind']==='moloch-siege' && count($x['battle']['entries'])===2,'Real campaign reserves its formations and compass entries.');
yes(BaiterService::run($a,'molochStart',$cmd)['session']['id']===$battle,'HTTP retry reopens the exact saved fight.');
deny(fn()=>BaiterService::run($a,'molochStart',array_replace($cmd,['stage'=>3])),'fresh request');
deny(fn()=>Game::mutate($a,'repairBase',[]),'current invasion wave');
deny(fn()=>BattleService::start($a,$rival),'Moloch');
$x=BaiterService::run($a,'baiterSiren',['id'=>$battle]);yes($x['battle']['result']['outcome']==='dismissed'&&$x['molochSiege']['cleared']===0,'Siren forfeits and preserves the next wave.');
$b=Game::loadBase($a);foreach($b['buildings'] as &$building){if($building['type']==='townhall')$building['level']=3;elseif($building['type']==='sniper')$building['hp']=0;}unset($building);Game::saveBase($a,$b);
$x=start($a,1,$battle);$battle=$x['session']['id'];$end=finish($a,$battle,false);
yes($end['battle']['result']['outcome']==='overrun'&&$end['molochSiege']['cleared']===0,'A depleted real defense loses and must retry the same wave.');
$b=Game::loadBase($a);$damage=Game::repairHomeStatus($b);yes($damage['available'],'A finished attack leaves persisted damage.');$cash=$b['resources'];
$repaired=Game::mutate($a,'repairBase',[]);$b=Game::loadBase($a);
yes(!Game::repairHomeStatus($b)['available']&&$b['resources']['goo']===$cash['goo'],'Free instant home recovery is usable between defenses.');
$army=$b['army'];$types=[];$priorCount=0;
for($stage=1;$stage<=10;$stage++){
    if(Game::repairHomeStatus(Game::loadBase($a))['available'])Game::mutate($a,'repairBase',[]);
    $profile=MolochSiege::view(Game::loadBase($a));$count=array_sum($profile['wave']);
    yes($profile['stage']===$stage&&$count>$priorCount,'Reconstructed waves grow across stages.');$priorCount=$count;
    $x=start($a,$stage,$battle);$battle=$x['session']['id'];
    $end=finish($a,$battle,true);$view=$end['molochSiege'];$units=array_values(array_filter($end['battle']['units'],fn($u)=>$u['team']==='attacker'));
    foreach($units as $u)$types[$u['type']]=true;
    yes($end['battle']['result']['outcome']==='held'&&$view['cleared']===$stage,'Timed reinforcement groups all released and wave '.$stage.' held.');
    yes(count($units)===$count&&$end['battle']['result']['defeated']===$count,'Every planned monster physically spawned and was defeated.');
    $repeat=BaiterService::run($a,'baiterPoll',['id'=>$battle]);yes(json_encode($repeat['molochSiege'])===json_encode($view),'Settlement and reward replay idempotence.');
    yes($view['homeMagmaLimit']===($stage===10?2:0)&&$view['outpostMagma']===($stage===10),'Both rewards follow the final successful defense.');
}
$b=Game::loadBase($a);yes(count($types)===8&&$b['army']===$army&&$b['shield_until']===$shield&&Game::user($a)['rank_points']===0,'Eight Inferno species, unaffected PvP inventory, shield and rank.');
deny(fn()=>start($a,10,$battle),'All ten');
yes(!isset($b['hellRaisers'],$b['emergence']),'Separate from Event 7 and the five-stage portal.');
if(Game::repairHomeStatus($b)['available'])Game::mutate($a,'repairBase',[]);$b=Game::loadBase($a);$b['resources']=array_fill_keys(['twig','pebble','putty','goo'],30000);Game::saveBase($a,$b);
foreach([1,2] as $num){
    $b=Game::loadBase($a);$spots=Bots::placements($a,'magmatower',0,$b,Catalog::buildings()['magmatower']);[$px,$py]=$spots[0];
    Game::mutate($a,'build',['type'=>'magmatower','x'=>$px,'y'=>$py]);
    $b=Game::loadBase($a);foreach($b['buildings'] as &$f){if($f['type']==='magmatower'&&$f['ready_at']>0)$f['ready_at']=time()-2;}unset($f);Game::accrue($b,time());Game::saveBase($a,$b);
    yes(Game::livingLevel($b,'magmatower')===1,'Reward creates an actual functioning paid tower.');
}
deny(fn()=>Game::mutate($a,'build',['type'=>'magmatower','x'=>28,'y'=>26]),'limit');
$edge=['buildings'=>[['type'=>'townhall','hp'=>10,'max_hp'=>100],['type'=>'wall','hp'=>0,'max_hp'=>500],['type'=>'trap','hp'=>0,'max_hp'=>50,'triggered'=>true]]];
yes(abs(MolochSiege::yardDamage($edge)-90)<.0001,'Exact 90% foundation scoring ignores walls and fired traps.');
$edge['buildings'][2]['triggered']=false;$edge['buildings'][2]['hp']=50;
yes(abs(MolochSiege::yardDamage($edge)-60)<.0001,'An intact unfired trap contributes to foundation health.');
$b=Game::loadBase($a);$b['resources']=array_fill_keys(['twig','pebble','putty','goo'],30000);Game::saveBase($a,$b);
$state=OutpostYards::initialState(time());$core=$b['buildings'][0];$core['type']='mr2core';$core['id']=1;$core['level']=1;$core['x']=$core['y']=16;$core['hp']=$core['max_hp']=Game::buildingStats('mr2core',1)['max_hp'];$state['buildings']=[$core];$state['next_building_id']=2;
$db->prepare("INSERT INTO world_sites(kind,q,r,owner_id,name,state_json,updated_at) VALUES('resource',129,17,?,'Siege award field',?,?)")->execute([$a,json_encode($state),time()]);$site=(int)$db->lastInsertId();
foreach([[7,7],[22,22]] as [$px,$py]){
    $state=World::load($site)['state'];$rev=$state['mr2Outpost']['revision'];
    OutpostYards::edit($a,['op'=>'build','type'=>'magmatower','revision'=>$rev,'siteId'=>$site,'x'=>$px,'y'=>$py]);
    $s=World::load($site);$s['state']['last_tick']=time()-2;foreach($s['state']['buildings'] as &$f)if($f['ready_at']>0)$f['ready_at']=time()-1;unset($f);
    World::refreshSite($s,time());World::save($s);
}
$state=World::load($site)['state'];yes(count(array_filter($state['buildings'],fn($f)=>$f['type']==='magmatower'&&$f['level']===1))===2,'The same earned award places two native working field towers.');
deny(fn()=>OutpostYards::edit($a,['op'=>'build','type'=>'magmatower','revision'=>$state['mr2Outpost']['revision'],'siteId'=>$site,'x'=>7,'y'=>22]),'limit');
$def=Game::loadBase($rival);$def['shield_until']=0;Game::saveBase($rival,$def);$an=start($rival,1,null);
$incoming=BattleService::start($a,$rival);$ended=Game::loadBase($rival);
yes($incoming['status']==='active' && $ended['baiterPractice']['state']['result']['outcome']==='dismissed'&&MolochSiege::view($ended)['cleared']===0,'Incoming real invasion settles the active siege before taking its home snapshot.');
BattleService::retreat($a,$incoming['id']);
echo "PASS $checks Moloch siege checks.\n";
