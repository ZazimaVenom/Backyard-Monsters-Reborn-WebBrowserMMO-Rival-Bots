<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';
$config=['database'=>getenv('MYSQL_TEST_DATABASE')?:'','host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),
    'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(!str_ends_with($config['database'],'_krallen_test')){fwrite(STDERR,"Use a disposable database ending in _krallen_test.\n");exit(2);}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function check(bool $pass,string $why):void{global $checks;if(!$pass){throw new RuntimeException($why);}++$checks;}
function denied(callable $work):void{try{$work();}catch(RuntimeException){check(true,'rejected');return;}throw new LogicException('Expected rejection');}
function building(int $id,string $type,int $level,int $x,int $y):array{
    $hp=Game::buildingStats($type,$level)['max_hp'];
    return ['id'=>$id,'type'=>$type,'level'=>$level,'x'=>$x,'y'=>$y,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'stored'=>0,'upgrade_to'=>null];
}
function yard(int $id,bool $attacker):array{
    $b=Game::loadBase($id);$b['buildings']=[building(1,'townhall',5,16,16)];
    if($attacker){$b['buildings']=array_merge($b['buildings'],[building(2,'flinger',3,4,4),building(3,'maproom',2,8,8),building(4,'championcage',1,12,24)]);
        $b['army']['fink']=4;
    }else{$b['buildings'][0]['hp']=$b['buildings'][0]['max_hp']=12;}
    $b['shield_until']=0;$b['next_building_id']=5;$b['resources']=array_fill_keys(['twig','pebble','putty','goo'],$attacker?100:10000);
    Game::saveBase($id,$b);return $b;
}
function raw(int $bid):array{global $pdo;$q=$pdo->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$bid]);return json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);}
function change(int $bid,array $s):void{global $pdo;$pdo->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($s,JSON_THROW_ON_ERROR),$bid]);}
function week(int $id,int $pastLoot):array{
    $now=time();$p=Game::loadBase($id);unset($p['krallenHunt']);KrallenHunt::credit($p,['twig'=>$pastLoot],$now-KrallenHunt::WEEK);
    Game::saveBase($id,$p);return $p;
}
$pdo->beginTransaction();
try{
    $id=Game::register('Hunt '.bin2hex(random_bytes(3)),'weekly-krallen-test-password')['id'];
    $peer=Game::register('Quarry '.bin2hex(random_bytes(3)),'weekly-krallen-test-password')['id'];activityFixtureWake($id);
    $p=yard($id,true);yard($peer,false);$p['unlocked'][]='fink';
    KrallenHunt::credit($p,['twig'=>KrallenHunt::AWARD-1],time());Game::saveBase($id,$p);
    $battle=BattleService::start($id,$peer);$bid=$battle['id'];check(!isset($battle['krallen'])&&!empty(raw($bid)['_krallenQualifies']),'Before award, eligible real raid carries no guardian');
    BattleService::deploy($id,$bid,'fink',21,16);$s=raw($bid);activityFixtureAgeState($s,310);change($bid,$s);
    $done=BattleService::poll($id,$bid);$receipt=$done['result']['krallenHunt'];$wallet=Game::loadBase($id)['resources'];
    check($done['result']['attackerWon']&&$receipt['added']===4000&&$receipt['loot']===KrallenHunt::AWARD+3999,'Real battle earns four-resource weekly loot once');
    foreach(['twig','pebble','putty','goo'] as $r){check((int)$wallet[$r]===1100&&(int)Game::loadBase($peer)['resources'][$r]===9000,'Equal and opposite currency transfer');}
    BattleService::poll($id,$bid);check(Game::loadBase($id)['krallenHunt']['loot']===$receipt['loot'],'Repoll never repeats loot');
    $p=Game::loadBase($id);$p['krallenHunt']['ends']-=KrallenHunt::WEEK;Game::saveBase($id,$p);
    Game::mutate($id,'championRaise',['type'=>'gorgo']);
    $p=Game::loadBase($id);check($p['krallenHunt']['power']===1&&$p['activeChampion']==='gorgo','Finishing a live week gives companion alongside a raised champion');
    $p['army']=array_fill_keys(array_keys($p['army']),0);Game::saveBase($id,$p);
    $d=yard($peer,false);$d['buildings']=[building(1,'townhall',5,16,16),building(2,'championcage',1,21,21),building(3,'sniper',3,23,23),building(4,'storage',2,12,16)];Game::saveBase($peer,$d);
    week($peer,KrallenHunt::UPGRADE);
    $before=Game::loadBase($id)['army'];$b=BattleService::start($id,$peer);$bid=$b['id'];
    check($b['champion']['type']==='gorgo'&&$b['krallen']['available']&&$b['defenderKrallen']['deployed'],'Independent attack and home-defense reservations');
    check(array_column($b['units'],'type')===['krallen']&&$b['units'][0]['krallenPower']===2,'Upgraded defending Krallen automatically guards the cage');
    denied(fn()=>BattleService::deployChampion($id,$bid,16,16,true));
    denied(fn()=>BattleService::deployChampion($peer,$bid,6,6,true));
    $b=BattleService::deployChampion($id,$bid,7,16);$b=BattleService::deployChampion($id,$bid,7,15,true);
    $byId=array_column($b['units'],null,'id');
    check($byId['ka']['type']==='krallen'&&$byId['ka']['krallenPower']===1&&$byId['ca']['type']==='gorgo'&&$byId['kd']['type']==='krallen','Both champions on one live field');
    check($b['champion']['deployed']&&$b['krallen']['deployed']&&Game::loadBase($id)['army']===$before,'Separate launch slots leave Flinger inventory intact');
    denied(fn()=>BattleService::deployChampion($id,$bid,7,14,true));
    $s=raw($bid);activityFixtureAgeState($s,16);change($bid,$s);
    BattleService::poll($id,$bid);$done=BattleService::retreat($id,$bid);$p=Game::loadBase($id);$d=Game::loadBase($peer);
    check($p['krallenHunt']['battle']===0&&$p['champions']['gorgo']['battle_id']===0&&$d['krallenHunt']['battle']===0,'All reservations released independently');
    $report=$done['result'];check($p['activeChampion']==='gorgo'&&$report['champions']['attacker']['deployed']&&$report['krallen']['deployed']&&$report['defenderKrallen']['deployed'],'Result includes all guardians');
    check($report['krallen']['hp']==$p['krallenHunt']['hp']&&$report['champions']['attacker']['hp']==$p['champions']['gorgo']['hp']&&$report['defenderKrallen']['hp']==$d['krallenHunt']['hp'],'Distinct HP persisted after fighting');
    check($report['defenderKrallen']['hp']<$report['defenderKrallen']['max_hp']||$report['krallen']['hp']<$report['krallen']['max_hp']||$report['champions']['attacker']['hp']<$report['champions']['attacker']['max_hp'],'Combat generated real injury');
    $rival=Game::publicBase($id,$p,false);check(!isset($rival['krallenHunt'])&&isset($rival['krallenCompanion']['power'])&&!isset($rival['krallenCompanion']['loot']),'Visit exposes guardian appearance while keeping score private');
    // A previously open battle has no frozen eligibility marker and cannot earn scores retroactively.
    $p=yard($id,true);unset($p['krallenHunt'],$p['champions']['gorgo']);$p['activeChampion']=null;Game::saveBase($id,$p);
    $d=yard($peer,false);unset($d['krallenHunt']);Game::saveBase($peer,$d);
    $b=BattleService::start($id,$peer);$bid=$b['id'];$s=raw($bid);unset($s['_krallenQualifies']);change($bid,$s);
    BattleService::deploy($id,$bid,'fink',21,16);$s=raw($bid);activityFixtureAgeState($s,310);change($bid,$s);$done=BattleService::poll($id,$bid);
    check(array_sum($done['result']['loot'])>0&&!isset($done['result']['krallenHunt'])&&!isset(Game::loadBase($id)['krallenHunt']),'Legacy fight settles normal loot with no retroactive Hunt score');
    // A real bot invades with just its earned loan and loots a fully exposed Silo.
    $p=yard($id,true);$p['army']=array_fill_keys(array_keys($p['army']),0);Game::saveBase($id,$p);week($id,KrallenHunt::UPGRADE);
    $d=yard($peer,false);$d['buildings'][]=building(2,'storage',1,12,16);$d['buildings'][1]['hp']=$d['buildings'][1]['max_hp']=48;Game::saveBase($peer,$d);
    $pdo->prepare('UPDATE users SET is_bot=1 WHERE id=?')->execute([$id]);activityFixtureWake($peer);
    $b=BattleService::botAttack($id,$peer);$bid=$b['id'];
    check($b['krallen']['deployed']&&$b['units'][0]['type']==='krallen'&&array_sum($b['remaining'])===0,'Online bot automatically deploys earned companion without manufactured troops');
    $s=raw($bid);activityFixtureAgeState($s,310);change($bid,$s);$done=BattleService::poll($peer,$bid);
    check($done['result']['attackerWon']&&$done['result']['krallenHunt']['added']===12000,'Real Krallen strikes a Silo first, tripling transferred loot');
    check($done['result']['loot']['twig']===3000&&(int)Game::loadBase($peer)['resources']['twig']===7000&&(int)Game::loadBase($id)['resources']['twig']===3100,'Boosted loot debited and deposited exactly');
    $pdo->rollBack();echo "Hunt for Krallen database: $checks checks passed.\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
