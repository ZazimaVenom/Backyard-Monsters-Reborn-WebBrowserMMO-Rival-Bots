<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$config=['database'=>getenv('MYSQL_TEST_DATABASE')?:'','host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),
    'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(PHP_SAPI!=='cli'||!str_ends_with($config['database'],'_raider_test')){fwrite(STDERR,"Select a disposable database ending in _raider_test.\n");exit(2);}
$db=Db::connect($config);Db::useConnection($db,$config);if($db->query('SHOW TABLES')->fetch())throw new RuntimeException('An empty test database is required.');
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function testRaid(bool $condition,string $failure):void { global $checks;if(!$condition)throw new RuntimeException($failure);++$checks; }
function advanceRaid(PDO $db,int $id,int $seconds):void{
    $q=$db->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);$s=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $s['created_at']-=$seconds;$db->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($s,JSON_THROW_ON_ERROR),$id]);
}
$owner=Game::register('Raid Keeper','TestPassword-12345')['id'];$friend=Game::register('Vault Keeper','TestPassword-12345')['id'];
foreach([$owner,$friend] as $id){
    $b=Game::loadBase($id);foreach($b['buildings'] as &$f)if($f['type']==='townhall')$f['level']=2;unset($f);
    Game::saveBase($id,$b);InfernoRealm::act($id,['action'=>'enter','requestId'=>bin2hex(random_bytes(12))]);
}
$source=Game::loadBase($owner);$source['infernoRealm']['army']['grokus']=4;$source['infernoRealm']['army']['malphus']=4;Game::saveBase($owner,$source);
$defender=Game::loadBase($friend);foreach($defender['infernoRealm']['buildings'] as &$b){
    if($b['type']==='twig'){$b['stored']=650;$b['x']=9;$b['y']=14;}
    if($b['type']==='sniper')$b['hp']=0;
}unset($b);Game::saveBase($friend,$defender);
$raid=BattleService::startInfernoRival($owner,$friend,['requestId'=>str_repeat('d',20),'previousId'=>null]);$id=$raid['id'];
BattleService::deploy($owner,$id,'malphus',4,14);advanceRaid($db,$id,24);
$state=BattleService::poll($owner,$id);
testRaid((bool)array_filter($state['events'],fn($e)=>$e['type']==='raid_loot'),'A deployed Malphus produces real per-hit loot events');
$settled=BattleService::retreat($owner,$id)['result'];$received=$settled['raiderLoot']['bone']??0;
testRaid($received>0 && Game::loadBase($owner)['infernoRealm']['resources']['bone']-$source['infernoRealm']['resources']['bone']===$settled['loot']['bone'],'Settled native wallet matches the raid report');
$updated=Game::loadBase($friend);$bins=array_column($updated['infernoRealm']['buildings'],null,'type');
testRaid($bins['twig']['stored']<650,'Loot actually left the defender’s harvester');
$saved=Game::loadBase($owner)['infernoRealm']['resources'];BattleService::poll($owner,$id);BattleService::poll($friend,$id);
testRaid(Game::loadBase($owner)['infernoRealm']['resources']===$saved,'Reading the settled report cannot move goods again');
$camp=BattleService::startInferno($owner,201,['requestId'=>str_repeat('e',20),'previousId'=>null]);
BattleService::deploy($owner,$camp['id'],'grokus',3,12);advanceRaid($db,$camp['id'],31);
$campResult=BattleService::retreat($owner,$camp['id'])['result'];
testRaid(array_sum($campResult['raiderLoot']??[])>0,'Grokus recovered supplies from a real Moloch layout');
$after=Game::loadBase($owner)['infernoRealm'];
testRaid(array_sum($campResult['raiderLoot'])===array_sum($after['campaign']['raided'][201]??[])||$campResult['inferno']['firstClear'],'Moloch raid accounts for its finite stage parcel');
echo "Raider loot: $checks database checks passed.\n";
