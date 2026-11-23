<?php
declare(strict_types=1);

require_once __DIR__.'/../app/bootstrap.php';
$database = (string)getenv('MYSQL_TEST_DATABASE');
if (PHP_SAPI !== 'cli' || !str_ends_with($database,'_test')) {
    fwrite(STDERR,"Choose an empty disposable MYSQL_TEST_DATABASE ending in _test.\n");
    exit(2);
}
$config = ['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),
    'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','database'=>$database,'game_speed'=>1];
$pdo = Db::connect($config);
if ($pdo->query("SHOW TABLES")->fetch()) { throw new RuntimeException('The tribe test needs a fresh database.'); }
Db::useConnection($pdo,$config);
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$n=0;
function tribeCheck(bool $ok, string $label): void { global $n; if(!$ok) throw new RuntimeException($label); ++$n; }
function tribeDeny(callable $fn,string $why):void{
    try {$fn();} catch (RuntimeException $e){tribeCheck(str_contains($e->getMessage(),$why),$e->getMessage());return;}
    throw new RuntimeException("Expected rejection: ".$why);
}
function fixtureAgeBattle(int $id):void{
    $pdo=Db::pdo();
    $q=$pdo->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);
    $state=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $state['created_at']-=BattleEngine::DURATION+1;
    $q=$pdo->prepare('UPDATE battles SET state_json=? WHERE id=?');$q->execute([json_encode($state,JSON_THROW_ON_ERROR),$id]);
}
$pdo->beginTransaction();
try {
    $owner=Game::register('Tribe keeper','test-campaign-password')['id'];
    $other=Game::register('Other keeper','test-campaign-password')['id'];
    $cards=Tribes::listing($owner);
    tribeCheck(count($cards['camps'])===21 && count(array_filter($cards['camps'],fn($c)=>$c['open']))===1,'Fresh route opens only the border camp');
    foreach(Tribes::catalog() as $row){
        $yard=Tribes::yard($row);
        $buildings=$yard['buildings'];
        tribeCheck(count($buildings)>=5 && count($buildings)<=90 && count(array_filter($buildings,fn($b)=>$b['type']==='townhall'))===1,'Camp structure bounds');
        foreach($buildings as $b){
            tribeCheck($b['x']>=2 && $b['x']<=30 && $b['y']>=2 && $b['y']<=30 && $b['hp']>0,'Building bounds and health');
        }
    }
    tribeDeny(fn()=>Tribes::visit($owner,2),'previous');
    $camp=Tribes::visit($owner,1);
    tribeCheck($camp['tribe']['tribe']==='Legionnaire'&&$camp['previousId']===null,'Scout receives first camp');
    $req=['requestId'=>str_repeat('a',24),'previousId'=>null];
    tribeDeny(fn()=>BattleService::startTribe($owner,2,$req),'previous');
    $before=Game::loadBase($owner);$before['resources']['twig']=Game::capacity($before);Game::saveBase($owner,$before);
    $battle=BattleService::startTribe($owner,1,$req);$id=$battle['id'];
    tribeCheck($battle['target']['kind']==='tribe'&&$battle['target']['sourceId']===1&&$battle['defender']['id']===0,'Virtual target starts');
    tribeCheck(BattleService::startTribe($owner,1,$req)['id']===$id,'Duplicate start is idempotent');
    tribeDeny(fn()=>BattleService::startTribe($owner,2,$req),'another camp');
    tribeDeny(fn()=>BattleService::poll($other,$id),'cannot control');
    tribeDeny(fn()=>BattleService::start($other,$owner),'already in a battle');
    tribeCheck((int)Game::snapshot($owner)['activeBattle']['id']===$id,'Reload resumes campaign');
    tribeCheck(!isset(Game::visit($owner,$other)['tribeCampaign']),'Private route');
    // Both keepers may independently attack the very same NPC camp at the same time.
    $otherBattle=BattleService::startTribe($other,1,$req);
    $otherLoss=BattleService::retreat($other,$otherBattle['id']);
    tribeCheck(!$otherLoss['result']['attackerWon'] && $otherLoss['result']['rankDelta']['attacker']===0,'An unsuccessful expedition is unranked');
    tribeCheck(Tribes::listing($other)['cleared']===0,'Loss never unlocks the next stage');
    $deployed=BattleService::deploy($owner,$id,['pokey'=>20],2,2);
    tribeCheck(count($deployed['units'])===20&&Game::loadBase($owner)['army']['pokey']===$before['army']['pokey']-20,'Real Flinger and army debit');
    fixtureAgeBattle($id);
    $win=BattleService::poll($owner,$id);
    tribeCheck($win['result']['attackerWon'] && $win['result']['campaign']['firstClear'] && $win['result']['campaign']['nextStage']===2,'Actual attackers win and unlock camp');
    $completed=Game::loadBase($owner);
    tribeCheck($completed['shield_until']===$before['shield_until'] && Game::user($owner)['rank_points']===0,'Home shield and ladder preserved');
    tribeCheck($completed['tribeCampaign']['pending']['twig']===$camp['tribe']['reward']['twig'],'Reward overflow retained');
    tribeCheck(!empty($completed['tribeCampaign']['hallVictories']['Legionnaire']) === !empty($win['result']['hallDestroyed']),'Town Hall victory follows simulated damage');
    tribeCheck(BattleService::startTribe($owner,1,$req)['result']==$win['result'],'Late retry returns settled result');
    tribeCheck(count(Game::reports($owner)['items'])===1 && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===2,'One report, no hidden NPC accounts');
    tribeCheck(Tribes::visit($owner,2)['tribe']['open'] && Tribes::listing($other)['cleared']===0,'Unlock is account-specific');
    tribeCheck(Tribes::collect($owner)['collected']['twig']===0,'Full storage preserves supplies');
    $completed['resources']['twig']-=1000;Game::saveBase($owner,$completed);
    tribeCheck(Tribes::collect($owner)['collected']['twig']===$camp['tribe']['reward']['twig'],'Full overflow delivered later');
    tribeCheck(array_sum(Tribes::collect($owner)['collected'])===0,'Double collection is harmless');
    $replay=BattleService::startTribe($owner,1,['requestId'=>str_repeat('b',24),'previousId'=>$id]);
    tribeDeny(fn()=>BattleService::startTribe($owner,1,$req),'changed');
    BattleService::deploy($owner,$replay['id'],['pokey'=>4,'fink'=>4,'octo'=>4],2,2);
    fixtureAgeBattle($replay['id']);
    $end=BattleService::poll($owner,$replay['id'])['result'];
    tribeCheck($end['attackerWon']&&!$end['campaign']['firstClear']&&array_sum($end['loot'])===0,'A won replay pays no second parcel');
    tribeCheck(Tribes::listing($owner)['cleared']===1,'Replay preserves progression');
} finally { $pdo->rollBack(); }
echo "Tribes: $n checks passed.\n";
