<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$config=['database'=>getenv('MYSQL_TEST_DATABASE')?:'','host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1',
    'port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root',
    'password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if (PHP_SAPI!=='cli' || !str_ends_with($config['database'],'_questjournal_test')) {
    fwrite(STDERR,"Select a disposable MYSQL_TEST_DATABASE ending in _questjournal_test.\n");exit(2);
}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) { throw new RuntimeException('An empty disposable world is required.'); }
$checks=0;
function journalCheck(bool $result,string $why):void {
    global $checks;if (!$result) { throw new RuntimeException($why); }++$checks;
}
function journalDeny(callable $fn,string $reason):void {
    try { $fn(); } catch (RuntimeException $e) { journalCheck(str_contains($e->getMessage(),$reason),$e->getMessage());return; }
    throw new RuntimeException('Expected denial: '.$reason);
}
function journalState(int $owner,string $goal):array {
    return array_column(Game::snapshot($owner)['base']['quests']['entries'],null,'id')[$goal];
}
function journalClaim(int $id,string $goal):array {
    return Game::mutate($id,'questClaim',['quest'=>$goal])['questClaim'];
}
$owner=Game::register('Journal Keeper','TestPassword-12345')['id'];
$token=Game::snapshot($owner)['base']['quests']['invite'];
journalCheck($token!=='' && $token===QuestSocial::token($owner),'Stable referral on an older installation without auth_salt');
$other=Game::register('First Arrival','TestPassword-12345',$token)['id'];
Game::register('Ordinary Arrival','TestPassword-12345','9.'.str_repeat('a',32));
journalDeny(fn()=>Game::register('First Arrival','TestPassword-12345',$token),'already taken');
Game::login('First Arrival','TestPassword-12345');
journalCheck(journalState($owner,'INVITE5')['progress']===1,'Only successful referred registration counts');
$start=Game::loadBase($owner)['shiny']??0;$a=journalClaim($owner,'INVITE1');
journalCheck($a['shiny']===25 && journalClaim($owner,'INVITE1')['alreadyClaimed'] && Game::loadBase($owner)['shiny']===$start+25,'Referral rewards are paid once');
Neighbors::act($owner,['kind'=>'request','keeper'=>$other]);Neighbors::act($other,['kind'=>'request','keeper'=>$owner]);
for ($i=1;$i<=5;++$i) {
    // Simulate the next calendar day of the same friendship. Each actual gift must be sent and accepted through its normal actions.
    $pdo->exec('UPDATE keeper_gifts SET sent_at=0');
    Neighbors::act($owner,['kind'=>'sendGift','keeper'=>$other]);
    $gift=$pdo->query('SELECT id,resource FROM keeper_gifts ORDER BY id DESC LIMIT 1')->fetch();$id=(int)$gift['id'];
    journalCheck(journalState($owner,'GA1')['progress']===$i-1,'An unopened gift gives no credit');
    if ($i===1) {
        $receiver=Game::loadBase($other);$receiver['resources'][$gift['resource']]=Game::capacity($receiver)-1;Game::saveBase($other,$receiver);
        $opened=Neighbors::act($other,['kind'=>'openGift','gift'=>$id]);
        journalCheck($opened['credited']===1 && $opened['remaining']>0 && journalState($owner,'GA1')['progress']===0,'Partial delivery gives no journal credit');
        $receiver=Game::loadBase($other);$receiver['resources'][$gift['resource']]=0;Game::saveBase($other,$receiver);
    }
    Neighbors::act($other,['kind'=>'openGift','gift'=>$id]);Neighbors::act($other,['kind'=>'openGift','gift'=>$id]);
    journalCheck(journalState($owner,'GA1')['progress']===$i,'Each collected sack is credited to its sender once');
}
journalCheck(journalState($other,'GA1')['progress']===0 && journalClaim($owner,'GA1')['resources']['twig']===100,'Five completed sacks release the sender’s gift award');
Alliances::manage($owner,['op'=>'create','name'=>'Journal Guard','description'=>'']);
$alliance=(int)$pdo->query('SELECT id FROM alliances LIMIT 1')->fetchColumn();Alliances::manage($owner,['op'=>'leave','allianceId'=>$alliance,'revision'=>(int)$pdo->query('SELECT revision FROM alliances LIMIT 1')->fetchColumn()]);
journalCheck(journalState($owner,'A14')['status']==='earned','Founding an alliance earns the badge permanently');
journalDeny(fn()=>journalClaim($owner,'IT1'),'Complete');
$home=Game::loadBase($owner);foreach ($home['buildings'] as &$facility) { if ($facility['type']==='townhall') { $facility['level']=2; } }unset($facility);Game::saveBase($owner,$home);
InfernoRealm::act($owner,['action'=>'enter','requestId'=>'journal_entry_001']);
$native=Game::loadBase($owner);$balances=$native['infernoRealm']['resources'];$bank=$native['resources'];
$received=journalClaim($owner,'IT1');$post=Game::loadBase($owner);
journalCheck($received['destination']==='inferno' && $post['resources']===$bank && $post['infernoRealm']['resources']['bone']===$balances['bone']+200 && $post['infernoRealm']['resources']['coal']===$balances['coal']+200,'Native award credits the underground bank exclusively');
journalCheck($post['infernoRealm']['revision']===$native['infernoRealm']['revision']+1 && journalClaim($owner,'IT1')['alreadyClaimed'],'Native award increments revision once');
$r=&$post['infernoRealm'];$r['unlocked'][]='zagnoid';$r['army']['spurtz']=400;$r['counterattack']=['state'=>['status'=>'active']];Game::saveBase($owner,$post);
journalDeny(fn()=>journalClaim($owner,'IUC2'),'counterattack');unset($r['counterattack']);Game::saveBase($owner,$post);
journalDeny(fn()=>journalClaim($owner,'IUC2'),'Compound');$r['army']['spurtz']=0;Game::saveBase($owner,$post);
$soldiers=journalClaim($owner,'IUC2');$home=Game::loadBase($owner);$realm=&$home['infernoRealm'];
journalCheck($soldiers['monsters']===['zagnoid'=>10] && $realm['army']['zagnoid']===10 && empty($home['army']['zagnoid']) && $realm['monsterRevision']===$r['monsterRevision']+1,'Specified native reward species joins its Compound only');
foreach ($realm['buildings'] as &$f) { if ($f['type']==='twig') { $f['stored']=102; } }unset($f);
$realm['campaign']['pending']=['sulfur'=>6000];Game::saveBase($owner,$home);
InfernoRealm::act($owner,['action'=>'collect','realmRevision'=>$realm['revision'],'requestId'=>'journal_harvest_01']);
journalCheck(journalState($owner,'IBK1')['status']==='ready' && journalState($owner,'IBK2')['progress']<2000,'Native harvest uses actual credited bins, excludes campaign loot');
echo "Expanded journal: $checks database checks passed.\n";
