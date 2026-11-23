<?php
declare(strict_types=1);
$name=getenv('MYSQL_TEST_DATABASE')?:'';
if(PHP_SAPI!=='cli'||!preg_match('/^[a-z0-9_]+_clan_test$/iD',$name)){fwrite(STDERR,"Select an empty disposable MYSQL_TEST_DATABASE ending in _clan_test.\n");exit(2);}
$fixture=sys_get_temp_dir().'/bym-clans-'.bin2hex(random_bytes(8));mkdir($fixture,0700);mkdir($fixture.'/app',0700);mkdir($fixture.'/var',0700);
register_shutdown_function(static function()use($fixture):void{
    foreach(['app','var'] as $dir){foreach(new FilesystemIterator($fixture.'/'.$dir) as $f){if($f->isFile()){unlink($f->getPathname());}}rmdir($fixture.'/'.$dir);}rmdir($fixture);
});
foreach(new FilesystemIterator(__DIR__.'/../app') as $f){if($f->isFile()){copy($f->getPathname(),$fixture.'/app/'.$f->getFilename());}}
require $fixture.'/app/Installer.php';
require __DIR__.'/ActivityFixture.php';
$cfg=['database'=>$name,'host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','create_database'=>false];
Installer::install($cfg);$db=Db::pdo();
$checks=0;
function clanCheck(bool $ok,string $label):void{global $checks;if(!$ok){throw new RuntimeException($label);}++$checks;}
function clanCount(string $table):int{return (int)Db::pdo()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
function dueClanTurn(int $id):void{
    $db=Db::pdo();$db->exec('UPDATE users SET next_turn_at='.(time()+86400).' WHERE is_bot=1');
    $db->prepare('UPDATE users SET next_turn_at=1 WHERE id=?')->execute([$id]);activityFixtureReleaseCadence();
    $r=Bots::tick(1,5);clanCheck($r['processed']===1&&empty($r['errors']),'claimed online turn: '.json_encode($r['errors']??[]));
}
clanCheck(clanCount('users')===1000&&clanCount('bases')===1000&&clanCount('alliances')===0,'actual fresh installation has 1000 bots, 0 alliances and 0 players');
$identities=$db->query('SELECT id,name,is_bot FROM users ORDER BY id')->fetchAll();
$offline=Bots::tick(100,5);clanCheck($offline['status']==='paused'&&!clanCount('alliances'),'offline worker cannot found a clan');
$human=Game::register('Clan Test Keeper','disposable-fixture-password')['id'];
$visit=Alliances::browse($human,[]);clanCheck($visit['total']===0&&clanCount('alliances')===0,'directory read cannot found a clan');
$own=Alliances::manage($human,['op'=>'create','name'=>'Bramblethorn Pact'])['mine'];
activityFixtureWake($human);
foreach([1,9,17,25] as $leader){dueClanTurn($leader);}
$clans=$db->query('SELECT id,leader_id,name,bot_charter FROM alliances WHERE bot_charter IS NOT NULL ORDER BY bot_charter')->fetchAll();
clanCheck(count($clans)===4&&count(array_unique(array_column($clans,'leader_id')))===4,'four real due bot turns founded four distinct clans');
clanCheck($clans[0]['name']==='Bramblethorn Pact 2','player-chosen charter name is preserved');
$enemy=(int)$clans[0]['id'];$rival=(int)$clans[0]['leader_id'];
$detail=Alliances::browse($human,['allianceId'=>$enemy]);clanCheck((bool)$detail['detail']['bot_led'],'visible bot leadership');
for($id=1;$id<=1000;$id+=8){Alliances::botTurn($id);}
clanCheck(clanCount('alliance_members')===33,'autonomous membership capped at 32 bots plus one human');
clanCheck((int)$db->query('SELECT COUNT(*) FROM users u WHERE u.is_bot=1 AND NOT EXISTS (SELECT 1 FROM alliance_members m WHERE m.user_id=u.id)')->fetchColumn()===968,'968 existing bots remain available for player recruitment');
clanCheck($db->query('SELECT id,name,is_bot FROM users WHERE is_bot=1 ORDER BY id')->fetchAll()===$identities,'clan growth preserves every existing bot identity');
clanCheck((int)$db->query('SELECT COUNT(*) FROM users WHERE rank_points<>0')->fetchColumn()===0,'no ranks awarded for founding or membership');
$soloOffer=Alliances::manage($human,['op'=>'warOffer','allianceId'=>$own,'otherId'=>$enemy])['diplomacy']['wars'][0];
clanCheck($soloOffer['state']==='pending','human challenge is an offer until the AI actually acts');
WorldActivity::logout($human,'activity-fixture-session');Bots::tick(100,5);Alliances::botDiplomacy();
clanCheck(Alliances::browse($human,['allianceId'=>$enemy])['diplomacy']['wars'][0]['state']==='pending','offline diplomacy and read leave offer pending');
activityFixtureWake($human);dueClanTurn(41);
$war=Alliances::browse($human,['allianceId'=>$enemy])['diplomacy']['wars'][0];
clanCheck($war['state']==='active'&&count($war['targets'])===8,'next online bot turn accepts real solo challenge with eight eligible rivals');
// The initial bot turns may already have started genuine raids. Select a free war target.
$free=array_values(array_filter($war['targets'],fn($k)=>$k['blocked']!=='In battle'));
clanCheck(count($free)>0,'an opposing clan has an available keeper');
$rival=(int)$free[0]['id'];
$base=Game::loadBase($rival);$base['shield_until']=0;Game::saveBase($rival,$base);
$battle=BattleService::start($human,$rival);BattleService::deploy($human,$battle['id'],'pokey',1,1);$settled=BattleService::retreat($human,$battle['id']);
clanCheck($settled['result']['allianceWar']['id']===(int)$war['id']&&clanCount('alliance_war_battles')===1,'real solo player deployment scores one battle for the opposing bot clan');
$viewer=Game::register('Curious Keeper','disposable-fixture-password')['id'];Alliances::manage($viewer,['op'=>'request','allianceId'=>$enemy]);
clanCheck(!Alliances::browse($viewer,[])['mine'],'request to bot clan waits for normal AI work');
dueClanTurn(73);clanCheck(Alliances::browse($viewer,[])['mine']===$enemy,'online clan accepts human join request');
clanCheck(!AllianceWars::qualify($db,$human,$viewer,time()),'late joiner to a bot clan is outside the war roster');
echo "$checks fresh-install bot-clan checks passed.\n";
