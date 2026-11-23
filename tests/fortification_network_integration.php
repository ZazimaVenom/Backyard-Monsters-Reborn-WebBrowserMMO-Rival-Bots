<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
if(getenv('BYM_NETWORK_TEST_RESET')!=='1'){fwrite(STDERR,"Set BYM_NETWORK_TEST_RESET=1 for the disposable *_network_test database.\n");exit(2);}
$checks=0;
function networkDbCheck(bool $condition,string $label):void{global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function networkDbReject(callable $work,string $part,string $label):void{try{$work();}catch(RuntimeException $error){networkDbCheck(str_contains($error->getMessage(),$part),$label.' ('.$error->getMessage().')');return;}throw new RuntimeException('FAIL: accepted '.$label);}
$database=getenv('BYM_NETWORK_TEST_DATABASE')?:'backyard_v07_network_test';
if(!preg_match('/^[A-Za-z0-9_]+_network_test$/D',$database)){throw new RuntimeException('Unsafe test database name.');}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:33078),'database'=>$database,'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1,'player_grace_seconds'=>1800];
$server=Db::connect($config,false);$server->exec('DROP DATABASE IF EXISTS `'.$database.'`');$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo=Db::connect($config);Db::useConnection($pdo,$config);
try{
    foreach(explode(';',file_get_contents(__DIR__.'/../app/schema.sql')) as $sql){if(trim($sql)!==''){$pdo->exec($sql);}}
    $pdo->exec("INSERT INTO settings(name,value) VALUES('schema_version','2'),('content_version','0.7.0')");
    Db::transaction(static function(PDO $pdo):void{Game::seedBots($pdo,1000);World::initialize($pdo);});
    foreach(['home'=>1000,'resource'=>256,'stronghold'=>64,'fortification'=>1270] as $kind=>$expected){networkDbCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='$kind'")->fetchColumn()===$expected,'fresh '.$kind.' count');}
    networkDbCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000,'exactly 1000 persistent bots');
    networkDbCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=0 OR rank_points<>0')->fetchColumn()===0,'setup creates no account and everyone starts at zero');
    networkDbCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='fortification' AND owner_id IS NOT NULL")->fetchColumn()===0,'network starts neutral');
    networkDbCheck($pdo->query("SELECT value FROM settings WHERE name='world_fortifications_version'")->fetchColumn()===World::FORTIFICATIONS_VERSION,'seeding marker committed');
    networkDbCheck(4096-(int)$pdo->query('SELECT COUNT(*) FROM world_sites')->fetchColumn()>=World::REGISTRATION_RESERVE,'registration reserve retained');
    $sitesBefore=$pdo->query('SELECT * FROM world_sites ORDER BY id')->fetchAll();$basesBefore=$pdo->query('SELECT user_id,SHA2(state_json,256) digest FROM bases ORDER BY user_id')->fetchAll();
    Db::transaction(fn(PDO $pdo)=>World::initialize($pdo));networkDbCheck($sitesBefore===$pdo->query('SELECT * FROM world_sites ORDER BY id')->fetchAll(),'second initialization preserves all sites byte-for-byte');networkDbCheck($basesBefore===$pdo->query('SELECT user_id,SHA2(state_json,256) digest FROM bases ORDER BY user_id')->fetchAll(),'initializer leaves all saved bases unchanged');
    $registered=Game::register('Network Tester','correct-local-password');networkDbCheck(World::home($registered['id'])!==null,'new player registers after network seeding');networkDbCheck((int)$pdo->query("SELECT COUNT(*) FROM world_sites WHERE kind='fortification'")->fetchColumn()===1270,'registration cannot remove a Fortification');

    // Choose a real complete ring without moving any existing home or terrain.
    $fortRows=$pdo->query("SELECT id,kind,owner_id,q,r FROM world_sites WHERE kind='fortification' ORDER BY id")->fetchAll();$index=[];foreach($fortRows as $row){$index[$row['q'].','.$row['r']]=$row;}
    $anchor=null;$ring=[];
    foreach($pdo->query("SELECT id,q,r FROM world_sites WHERE kind='resource' ORDER BY id")->fetchAll() as $row){$candidate=[];foreach(World::neighbors((int)$row['q'],(int)$row['r']) as $neighbor){if(isset($index[$neighbor['q'].','.$neighbor['r']])){$candidate[]=(int)$index[$neighbor['q'].','.$neighbor['r']]['id'];}}if(count($candidate)===6){$anchor=World::load((int)$row['id']);$ring=$candidate;break;}}
    networkDbCheck($anchor!==null&&count($ring)===6,'existing map has a complete neutral ring');
    $zero=['count'=>0,'reduction'=>0.0,'siteIds'=>[]];networkDbCheck(World::fortificationSupport(null,$anchor['q'],$anchor['r'])===$zero,'neutral parent gets no protection');
    $base=Game::loadBase(1);$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],100000);$hp=Game::buildingStats('maproom',1)['max_hp'];$roomId=$base['next_building_id']++;$base['buildings'][]=['id'=>$roomId,'type'=>'maproom','x'=>26,'y'=>27,'level'=>1,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];Game::saveBase(1,$base);
    $anchor['owner_id']=1;World::save($anchor);
    foreach($ring as $siteId){$site=World::load($siteId);$site['owner_id']=1;World::save($site);}
    $support=World::fortificationSupport(1,$anchor['q'],$anchor['r']);networkDbCheck($support['count']===6&&$support['reduction']===.75,'six real same-owner neighbors grant75 percent');
    networkDbCheck(World::fortificationSupport(2,$anchor['q'],$anchor['r'])===$zero,'center owner must match requested defender');
    networkDbCheck(count(World::holdings(1))===1&&count(World::fortifications(1))===6,'separate saved ownership classes');
    World::syncBonuses(1,$base);Game::saveBase(1,$base);networkDbCheck($base['world']['holdings']===1&&$base['world']['capacity']===2000&&$base['world']['resourcePerSecond']===.05,'owned Fortifications do not leak into economy cache');
    $working=World::load($ring[0]);$working['state']['buildings'][0]['hp']=0;$working['state']['repair_at']=time()+1000;$working['state']['ready_at']=time()+2000;$working['state']['upgrade_to']=2;World::save($working);
    networkDbCheck(World::fortificationSupport(1,$anchor['q'],$anchor['r'])===$support,'destroyed rebuilding and upgrading cells still protect by ownership');
    networkDbCheck(World::fortificationSupport(1,$working['q'],$working['r'])===$zero,'Fortification target cannot receive network armor');
    $working['state']=World::initialState('fortification',1,time());$working['state']['garrison']=[];World::save($working);
    $map=World::map(1,['q'=>$anchor['q'],'r'=>$anchor['r'],'radius'=>3]);$marker=array_values(array_filter($map['sites'],fn($site)=>$site['id']===$anchor['id']))[0];
    networkDbCheck($marker['networkDefense']===$support&&$map['totals']['fortification']===1270,'batched map summary exposes actual network');
    networkDbCheck($map['holdingLimit']===2&&$map['fortificationLimit']===6&&count($map['fortifications'])===6,'map exposes separate capacities');
    $view=World::site(1,$anchor['id']);networkDbCheck($view['base']['networkDefense']===$support&&$view['fortificationLimit']===6&&$view['fortificationCount']===6,'visited parent and panel expose actual protection and limit');
    $fortView=World::site(1,$ring[0]);networkDbCheck($fortView['base']['networkDefense']===$zero&&$fortView['site']['range']===0,'visited Fortification is not itself protected and grants zero reach');
    $farView=World::map(1,['q'=>63-$anchor['q'],'r'=>63-$anchor['r'],'radius'=>1]);networkDbCheck($farView['holdings'][0]['networkDefense']===$support,'offscreen owned parent support uses owned-cell batch');

    $fortCandidates=World::candidates(1,48,0,'fortification');networkDbCheck(count($fortCandidates)>0&&count($fortCandidates)<=48,'bounded network candidate discovery');
    foreach($fortCandidates as $candidate){networkDbCheck($candidate['kind']==='fortification'&&count($candidate['adjacentSites'])<=6,'network candidates include at most six eligible parent projections');foreach($candidate['adjacentSites'] as $parent){networkDbCheck(in_array($parent['kind'],['home','resource','stronghold'],true)&&World::distance($candidate['q'],$candidate['r'],$parent['q'],$parent['r'])===1&&!isset($parent['state']),'candidate parent projection is accurate and private');}}
    foreach(World::candidates(1,48,0) as $candidate){networkDbCheck(in_array($candidate['kind'],['resource','stronghold'],true),'ordinary candidate pool not crowded by network cells');}
    networkDbReject(fn()=>World::assertAttack(1,Game::loadBase(1),$fortCandidates[0]),'Fortification limit','six-cell cap authoritative');
    $otherAnchor=World::load((int)$pdo->query("SELECT id FROM world_sites WHERE kind='resource' AND owner_id IS NULL ORDER BY id LIMIT 1")->fetchColumn());$otherAnchor['owner_id']=1;World::save($otherAnchor);
    $ordinary=World::candidates(1,1,0)[0];networkDbReject(fn()=>World::assertAttack(1,Game::loadBase(1),$ordinary),'holding limit','ordinary two-site cap unchanged');
    World::mutate(1,'worldAbandon',['siteId'=>$ring[5]]);networkDbCheck(World::fortificationSupport(1,$anchor['q'],$anchor['r'])['reduction']===.50,'abandon immediately removes one support cell');World::assertAttack(1,Game::loadBase(1),$fortCandidates[0]);networkDbCheck(true,'full ordinary holding capacity does not block a network campaign');
    networkDbReject(fn()=>Game::mutate(1,'build',['type'=>'outpostdefender','x'=>25,'y'=>5]),'available building','site-only core cannot be constructed at home');
    $base=Game::loadBase(1);foreach($base['buildings'] as &$building){if($building['id']===$roomId){$building['hp']=0;}}unset($building);networkDbReject(fn()=>World::assertAttack(1,$base,$fortCandidates[0]),'Map Room','destroyed Map Room blocks network invasions');
    foreach([2=>12,3=>18] as $level=>$limit){$base=Game::loadBase(1);foreach($base['buildings'] as &$building){if($building['id']===$roomId){$building['level']=$level;$building['hp']=$building['max_hp']=Game::buildingStats('maproom',$level)['max_hp'];}}unset($building);Game::saveBase(1,$base);networkDbCheck(World::map(1,['radius'=>1])['fortificationLimit']===$limit,'Map Room level scales separate network cap');}

    // Paid stationing, return, upgrades and repairs reuse normal territory actions.
    $before=Game::loadBase(1);World::mutate(1,'worldGarrison',['siteId'=>$ring[0],'type'=>'pokey','quantity'=>3]);networkDbCheck(Game::loadBase(1)['army']['pokey']===$before['army']['pokey']-3,'garrison deducts actual army');World::mutate(1,'worldUngarrison',['siteId'=>$ring[0],'type'=>'pokey','quantity'=>2]);networkDbCheck(Game::loadBase(1)['army']['pokey']===$before['army']['pokey']-1,'ungarrison returns only saved monsters');
    networkDbReject(fn()=>World::mutate(2,'worldRepair',['siteId'=>$ring[0]]),'you own','nonowner cannot repair Fortification');
    $before=Game::loadBase(1);World::mutate(1,'worldUpgrade',['siteId'=>$ring[0]]);$owned=World::load($ring[0]);networkDbCheck($owned['state']['upgrade_to']===2&&abs($before['resources']['twig']-Game::loadBase(1)['resources']['twig']-4000)<1,'upgrade pays real resources and persists timer');
    networkDbCheck(World::fortificationSupport(1,$anchor['q'],$anchor['r'])['count']===5,'upgrade neither loses nor increases network strength');networkDbReject(fn()=>World::mutate(1,'worldGarrison',['siteId'=>$ring[0],'type'=>'pokey','quantity'=>1]),'finish upgrading','work blocks stationing');
    $owned['state']['ready_at']=time()-1;World::save($owned);$view=World::site(1,$ring[0]);networkDbCheck($view['site']['level']===2&&$view['management']['garrisonCapacity']===200,'timed upgrade expands real bunker and defenses');
    $owned=World::load($ring[0]);$owned['state']['buildings'][0]['hp']=0;World::save($owned);$quote=World::repairCost($owned);$before=Game::loadBase(1);World::mutate(1,'worldRepair',['siteId'=>$ring[0]]);$owned=World::load($ring[0]);networkDbCheck($owned['state']['repair_at']>time()&&abs($before['resources']['twig']-Game::loadBase(1)['resources']['twig']-$quote['twig'])<1,'repair charges proportional quote and takes time');
    $owned['state']['repair_at']=time()-1;World::save($owned);networkDbCheck(World::site(1,$ring[0])['base']['buildings'][0]['hp']===6750,'repair completion persists targetable core HP');
    Db::transaction(static function() use($ring):void{$bases=[1=>Game::loadBase(1,true),2=>Game::loadBase(2,true)];$site=World::load($ring[0],true);World::capture($site,2,$bases,time());foreach($bases as $id=>$base){Game::saveBase($id,$base);}});
    networkDbCheck(World::load($ring[0])['owner_id']===2&&World::load($anchor['id'])['owner_id']===1,'capture transfers only the Fortification');networkDbCheck(World::fortificationSupport(1,$anchor['q'],$anchor['r'])['count']===4,'capturing a cell removes prior-owner support');networkDbCheck(World::load($ring[0])['state']['garrison']===[]&&World::load($ring[0])['state']['repair_at']>time(),'capture clears stationed defenders and starts rebuild');
    networkDbCheck((int)$pdo->query('SELECT COUNT(*) FROM users WHERE rank_points<>0')->fetchColumn()===0,'world ownership actions do not fabricate ranking rewards');

    // A level-three Stronghold sees hundreds of cells. A fixed q/r LIMIT160
    // used to omit its entire support ring before the seed was even applied.
    $stronghold=World::load((int)$pdo->query("SELECT id FROM world_sites WHERE kind='stronghold' AND q=28 AND r=28")->fetchColumn());$stronghold['owner_id']=1;$stronghold['level']=3;$stronghold['state']=World::initialState('stronghold',3,time());World::save($stronghold);
    $nearIds=[];$allInRange=[];
    foreach($pdo->query("SELECT id,q,r FROM world_sites WHERE kind='fortification' AND (owner_id IS NULL OR owner_id<>1) AND q BETWEEN 8 AND 48 AND r BETWEEN 8 AND 48 ORDER BY q,r")->fetchAll() as $row){$row['id']=(int)$row['id'];$row['q']=(int)$row['q'];$row['r']=(int)$row['r'];$allInRange[]=$row;if(World::distance(28,28,$row['q'],$row['r'])===1){$nearIds[]=$row['id'];}}
    networkDbCheck(count($allInRange)>400&&count($nearIds)===6,'dense long-range fixture has six actual support neighbors');
    networkDbCheck(array_intersect($nearIds,array_column(array_slice($allInRange,0,160),'id'))===[],'fixture reproduces the old fixed-prefix blind spot');
    $held=World::holdings(1);$sourceCount=count($held)+1;$sourceIndex=array_search($stronghold['id'],array_column($held,'id'),true)+1;$seen=[];$windows=[];
    for($turn=0;$turn<64;$turn++){
        $choices=World::candidates(1,24,$sourceIndex+$sourceCount*$turn,'fortification');$ids=array_column($choices,'id');
        networkDbCheck(count($choices)<=24&&array_diff($nearIds,$ids)===[],'every chosen stronghold window retains its six eligible adjacent cells');
        foreach($choices as $candidate){$seen[$candidate['id']]=true;networkDbCheck(World::distance(28,28,$candidate['q'],$candidate['r'])<=20,'seeded network scouting remains inside the chosen reach source');}
        $windows[]=implode(',',$ids);
    }
    networkDbCheck(count(array_unique($windows))>16&&count($seen)>160,'seeded windows explore beyond the old fixed 160-row prefix');

    // Marker prevents future implicit regeneration even if a fixture cell is removed.
    $deleted=(int)$pdo->query("SELECT id FROM world_sites WHERE kind='fortification' AND owner_id IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();$pdo->prepare('DELETE FROM world_sites WHERE id=?')->execute([$deleted]);
    $fingerprint=$pdo->query('SELECT id,owner_id,q,r,revision,SHA2(state_json,256) digest FROM world_sites ORDER BY id')->fetchAll();Db::transaction(fn(PDO $pdo)=>World::initialize($pdo));networkDbCheck($fingerprint===$pdo->query('SELECT id,owner_id,q,r,revision,SHA2(state_json,256) digest FROM world_sites ORDER BY id')->fetchAll(),'marker prevents reseeding and preserves captures upgrades and homes');
    echo "Fortification network database tests passed: {$checks} assertions on ".$pdo->query('SELECT VERSION()')->fetchColumn().".\n";
}finally{$server->exec('DROP DATABASE IF EXISTS `'.$database.'`');}
