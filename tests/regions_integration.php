<?php
declare(strict_types=1);
require_once __DIR__.'/../bin/upgrade.php';
$config=['database'=>getenv('MYSQL_TEST_DATABASE')?:'','host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(PHP_SAPI!=='cli'||!str_ends_with($config['database'],'_region_test')){fwrite(STDERR,"Select an empty database ending in _region_test.\n");exit(2);}
$db=Db::connect($config);Db::useConnection($db,$config);
if($db->query('SHOW TABLES')->fetch())throw new RuntimeException('Use an empty test database.');
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
Db::transaction(function($pdo){Game::seedBots($pdo,3);World::initialize($pdo);});
$checks=0;
function yes(bool $v,string $why): void{global $checks;if(!$v)throw new RuntimeException($why);++$checks;}
function denied(callable $fn,string $msg): void{try{$fn();}catch(RuntimeException $e){yes(str_contains($e->getMessage(),$msg),$e->getMessage());return;}throw new RuntimeException('Accepted: '.$msg);}
function params(int $id,string $op,array $args=[]): array{$v=Regions::overview($id);return $args+['op'=>$op,'estateKey'=>$v['estateKey'],'revision'=>$v['revision'],'request'=>bin2hex(random_bytes(16))];}
function change(int $id,string $op,array $args=[]): array{return Regions::act($id,params($id,$op,$args));}
function roomLevel(int $id,int $level): void{$b=Game::loadBase($id);$f=$b['buildings'][0];$f['type']='maproom';$f['id']=$b['next_building_id']++;$f['level']=$level;$f['x']=5;$f['y']=5;$b['buildings'][]=$f;Game::saveBase($id,$b);}
function resetCooldown(int $id): void{$b=Game::loadBase($id);$b['regionTravel']['after']=0;Game::saveBase($id,$b);}
function treasury(int $id,int $materials,int $shiny): void{$b=Game::loadBase($id);$b['resources']=array_fill_keys(['twig','pebble','putty','goo'],$materials);$b['shiny']=$shiny;$b['last_tick']=time();Game::saveBase($id,$b);}
function give(int $id,string $kind,int $region): array{global $db;$low=($region-1)*128;$high=$low+63;$row=World::load((int)$db->query("SELECT id FROM world_sites WHERE owner_id IS NULL AND kind='$kind' AND q BETWEEN $low AND $high LIMIT 1")->fetchColumn());$row['owner_id']=$id;World::save($row);return $row;}
$a=Game::register('Regional tester','local-only-password')['id'];$b=Game::register('Visiting friend','local-only-password')['id'];$c=Game::register('Third keeper','local-only-password')['id'];
$original=World::home($a);$baseBefore=Game::loadBase($a);
yes(Regions::overview($a)['homeRegion']===1 && array_column(Regions::overview($a)['regions'],'id')===[1,2,3],'Legacy map retained and just two new regions offered.');
denied(fn()=>World::map($a,['region'=>500]),'available regions');
denied(fn()=>change($a,'relocate',['region'=>2]),'Map Room');
foreach([2,3] as $region){$m=World::map($a,['region'=>$region]);yes($m['region']===$region&&$m['home']['region']===1&&$m['center']===['q'=>32,'r'=>32],'Browse independent remote map.');foreach($m['sites'] as $s)yes($s['q']<64&&$s['r']<64&&!$s['reachable']&&$s['region']===$region,'Local coordinates and no remote reach.');}
yes((int)$db->query("SELECT COUNT(*) FROM world_sites WHERE kind='resource' AND q BETWEEN 128 AND 191")->fetchColumn()===256,'Frontier starts with 256 field yards.');
$native=World::load((int)$db->query('SELECT id FROM world_sites WHERE q BETWEEN 128 AND 191 LIMIT 1')->fetchColumn());yes(OutpostYards::is($native),'Frontier native gameplay layout.');
yes((int)$db->query("SELECT COUNT(*) FROM world_sites WHERE kind='stronghold' AND q BETWEEN 256 AND 319")->fetchColumn()===16,'Highlands contains its own 16 strongholds.');
foreach([$a,$b,$c] as $id)roomLevel($id,3);roomLevel(3,3);
yes(!Regions::botTurn(3,Game::loadBase(3),4),'A browsed region does not cause bots to populate it.');
$cmd=params($a,'relocate',['region'=>3]);$v=Regions::act($a,$cmd);$home=World::home($a);
yes($home['id']===$original['id']&&$v['homeRegion']===3 && min($v['x'],$v['y'],63-$v['x'],63-$v['y'])>=3,'Arrived at a safe inland sector retaining permanent marker.');
yes($v['estate']===6&&World::fortificationSupport($a,$home['q'],$home['r'])['reduction']===.75,'Six owned sector defenders provide maximum combat damage reduction.');
yes(Regions::act($a,$cmd)['revision']===$v['revision'],'Retried free move preserves destination.');
denied(fn()=>Regions::act($a,array_replace($cmd,['request'=>bin2hex(random_bytes(16))])),'position changed');
yes(Game::loadBase($a)['army']===$baseBefore['army'] && Game::user($a)['rank_points']===0,'Army and rank travel intact.');
$targets=World::candidates($a,24,124);yes(count($targets)>0 && Regions::id($targets[0]['q'])===3,'Bot and human target selection query finds native regional holdings.');
$battle=BattleService::startSite($a,$targets[0]['id']);yes($battle['status']==='active','Real regional territory invasion.');
denied(fn()=>change($a,'relocate',['region'=>3]),'active invasions');BattleService::retreat($a,$battle['id']);
denied(fn()=>change($b,'near',['friend'=>$a]),'accepted friend');
Neighbors::act($a,['kind'=>'request','keeper'=>$b]);Neighbors::act($b,['kind'=>'request','keeper'=>$a]);$friendHome=change($b,'near',['friend'=>$a]);$distance=World::distance($v['x'],$v['y'],$friendHome['x'],$friendHome['y']);
yes($friendHome['homeRegion']===3 && $distance>=7 && $distance<=28,'Nearby accepted friend receives an independent defense sector.');
yes(Regions::botTurn(3,Game::loadBase(3),4)&&Regions::id(World::home(3)['q'])===3,'Eligible persistent bot settles with players using the real relocation path.');
$holding=give($a,'resource',3);$oldRing=Game::loadBase($a)['regionTravel']['ring'];$same=change($a,'relocate',['region'=>3]);
yes(World::load($holding['id'])['owner_id']===$a && $same['estate']===7,'Intra-region move preserves captured holdings and replaces six defenders.');
yes(count(array_intersect($oldRing,Game::loadBase($a)['regionTravel']['ring']))===0,'Independent defense ring at new position.');
denied(fn()=>change($a,'relocate',['region'=>2,'estateKey'=>'stale']),'territories changed');
$v=change($a,'relocate',['region'=>2]);yes($v['homeRegion']===2 && $v['estate']===0 && World::load($holding['id'])['owner_id']===null,'Changing regions actually releases captured territory.');
yes(abs(($v['cooldown']-time())-86400)<2,'Exact 24-hour Frontier cooldown.');
denied(fn()=>change($a,'relocate',['region'=>3]),'cooldown');resetCooldown($a);
$post=give($a,'resource',2);$other=give($a,'resource',2);denied(fn()=>change($a,'relocate',['region'=>2]),'Abandon');
denied(fn()=>change($c,'own',['site'=>$post['id'],'payment'=>'shiny']),'your own');
$mark=Regions::bookmark($a,['op'=>'save','region'=>3,'q'=>0,'r'=>63,'label'=>'Last ridge']);
yes($mark['bookmarks']===[['region'=>3,'x'=>0,'y'=>63,'label'=>'Last ridge']]&&!Regions::overview($b)['bookmarks'],'Boundary bookmark saved privately.');
Regions::bookmark($a,['op'=>'save','region'=>3,'q'=>0,'r'=>63,'label'=>'North ridge']);
yes(count(Regions::overview($a)['bookmarks'])===1,'Idempotent coordinate bookmark.');
denied(fn()=>Regions::bookmark($a,['op'=>'save','region'=>3,'q'=>0,'r'=>64]),'cell');
Regions::bookmark($a,['op'=>'remove','region'=>3,'q'=>0,'r'=>63]);yes(!Regions::overview($a)['bookmarks'],'Bookmark removed.');
change($a,'invite',['friend'=>$b,'site'=>$post['id']]);$sent=Regions::overview($a)['sent'][0];
change($a,'withdraw',['site'=>$post['id'],'offer'=>$sent['token']]);yes(!Regions::overview($b)['invites'] && !Regions::overview($a)['sent'],'Sender can withdraw an outstanding offer without consuming the outpost.');
$inv=change($a,'invite',['friend'=>$b,'site'=>$post['id']]);$offers=Regions::overview($b)['invites'];yes(count($offers)===1 && $offers[0]['sender']===$a,'Recipient receives position and authenticated sender.');
denied(fn()=>change($a,'own',['site'=>$post['id'],'payment'=>'shiny']),'reserved');
denied(fn()=>change($c,'accept',['offer'=>$offers[0]['token']]),'answered');
change($b,'decline',['offer'=>$offers[0]['token']]);yes(!Regions::overview($b)['invites']&&World::load($post['id'])['owner_id']===$a,'Declining returns the site to the sender.');
change($a,'invite',['friend'=>$b,'site'=>$post['id']]);$offer=Regions::overview($b)['invites'][0];treasury($b,0,1199);
denied(fn()=>change($b,'accept',['offer'=>$offer['token'],'payment'=>'shiny']),'1200 Shiny');
treasury($b,16000,1200);$cmd=params($b,'accept',['offer'=>$offer['token'],'payment'=>'resources']);
$v=Regions::act($b,$cmd);yes($v['homeRegion']===2 && $v['x']===Regions::local($post['q']) && $v['y']===$post['r'] && !$v['invites'] && $v['estate']===0,'Friend accepts real location, surrenders old defenders and clears offer.');
yes(Game::loadBase($b)['resources']['goo']===6000 && Game::loadBase($b)['shiny']===1200,'Shared treasury charged exactly 10,000 of each material.');
Regions::act($b,$cmd);yes(Game::loadBase($b)['resources']['goo']===6000,'Replayed invitation payment is idempotent.');
denied(fn()=>World::load($post['id']),'does not exist');yes(count(Regions::overview($a)['outposts'])===1,'Surrender consumes the exact donor outpost.');
$under=World::load($other['id']);$under['state']['mr2Outpost']['claim']=['at'=>time(),'battle'=>42,'attacker'=>$c];World::save($under);
denied(fn()=>change($a,'own',['site'=>$other['id'],'payment'=>'shiny']),'breached');
$under['state']['mr2Outpost']['claim']=null;World::save($under);
treasury($a,0,1500);$cmd=params($a,'own',['site'=>$other['id'],'payment'=>'shiny']);Regions::act($a,$cmd);
yes(Game::loadBase($a)['shiny']===0&&World::home($a)['q']===$other['q']&&World::home($a)['r']===$other['r'],'Own outpost costs 1,500 Shiny and places real home on its former coordinates.');
$now=World::home($a);Db::transaction(fn($pdo)=>World::initialize($pdo));yes(World::home($a)===$now,'Legacy upgrader does not change regional homes or duplicate markers.');
$d=Game::register('Later keeper','local-only-password')['id'];yes(Regions::overview($d)['homeRegion']===1,'New registration still uses original region when available.');
yes((int)$db->query('SELECT COUNT(DISTINCT q,r) FROM world_sites')->fetchColumn()===(int)$db->query('SELECT COUNT(*) FROM world_sites')->fetchColumn(),'All occupied cells are unique after paid and free migration.');
// Run the actual CLI upgrader against the populated layout. Two legitimate Frontier markers have been consumed.
$db->exec("INSERT INTO settings(name,value) VALUES('schema_version','3'),('content_version','0.12.0'),('realmchat:test:1','retained player history')");
$dir=sys_get_temp_dir().'/bym-region-upgrade-'.bin2hex(random_bytes(6));
function regionRows(PDO $db):array{return $db->query('SELECT id,kind,q,r,name,level,owner_id,home_user_id,revision,updated_at,SHA2(state_json,256) AS content FROM world_sites WHERE q>=128 ORDER BY id')->fetchAll();}
$regionsBefore=regionRows($db);$preMap=$db->query('SELECT COUNT(*) FROM world_sites')->fetchColumn();
try{
    $plan=WorldUpgrade::run($db,false,$dir);
    yes(!$plan['world_update']&&$plan['settings_update']&&$plan['world_sites']===(int)$preMap,'Version-only check accepts regional populations and consumed outposts.');
    $up=WorldUpgrade::run($db,true,$dir);
    yes($up['applied']&&$up['world_sites']===(int)$preMap&&is_file($up['backup_sql'])&&regionRows($db)===$regionsBefore,'Real upgrade preserves every regional row, layout and owner exactly.');
    yes($db->query("SELECT value FROM settings WHERE name='realmchat:test:1'")->fetchColumn()==='retained player history','Upgrade preserves existing player settings.');
    $check=WorldUpgrade::run($db,false,$dir);$again=WorldUpgrade::run($db,true,$dir);
    yes(!$check['world_update']&&!$again['applied']&&!$again['backup']&&regionRows($db)===$regionsBefore,'Current-version check and reapply are idempotent after physical migration.');
    // A missing fixed Home Valley anchor still triggers repair even when newer regions have hundreds of resource sites.
    $db->exec("DELETE FROM world_sites WHERE q=1 AND r=1 AND kind='resource'");
    yes(WorldUpgrade::run($db,false,$dir)['world_update'],'Missing original anchor detected independently of other regions.');
    $repair=WorldUpgrade::run($db,true,$dir);
    yes($repair['applied']&&$repair['world_sites']===(int)$preMap&&World::hasHomeValleyAnchors($db)&&regionRows($db)===$regionsBefore,'Repair adds only the missing original anchor and preserves frontier handovers and Highlands defenders.');
    yes(!WorldUpgrade::run($db,true,$dir)['applied'],'A repeated repair creates no assets or backups.');
}finally{
    foreach(glob($dir.'/world-before-*')?:[] as $backup)unlink($backup);
    if(is_dir($dir))rmdir($dir);
}
echo "PASS $checks regional Map Room checks.\n";
