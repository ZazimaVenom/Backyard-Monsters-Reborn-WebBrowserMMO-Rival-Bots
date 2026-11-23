<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$db=(string)getenv('MYSQL_TEST_DATABASE');
if(PHP_SAPI!=='cli'||!str_ends_with($db,'_test')){fwrite(STDERR,"Choose a disposable empty MYSQL_TEST_DATABASE ending in _test.\n");exit(2);}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','database'=>$db,'game_speed'=>1];
$pdo=Db::connect($config);if($pdo->query('SHOW TABLES')->fetch())throw new RuntimeException('Use a fresh empty database.');
Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$n=0;
function ck(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;}
function deny(callable $fn,string $part):void{try{$fn();}catch(RuntimeException $e){ck(str_contains($e->getMessage(),$part),$e->getMessage());return;}throw new RuntimeException('Expected rejection for '.$part);}
function key16():string{return bin2hex(random_bytes(12));}
function commandI(int $id,array $in):array{return InfernoRealm::act($id,$in+['realmRevision'=>InfernoRealm::view($id)['revision'],'requestId'=>key16()]);}
function ageRaid(int $id):void{
    $pdo=Db::pdo();$q=$pdo->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);
    $b=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);$b['created_at']-=BattleEngine::DURATION+5;
    $pdo->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($b,JSON_THROW_ON_ERROR),$id]);
}
function ageCavern(int $id,int $seconds):void{
    $b=Game::loadBase($id);$r=&$b['infernoRealm'];$r['last_tick']-=$seconds;
    foreach($r['buildings'] as &$row)if($row['ready_at'])$row['ready_at']-=$seconds;
    unset($row);foreach($r['queue'] as &$egg){$egg['ready_at']-=$seconds;$egg['start']-=$seconds;}unset($egg);Game::saveBase($id,$b);
}
function promote(int $id):array{$b=Game::loadBase($id);foreach($b['buildings'] as &$row)if($row['type']==='townhall')$row['level']=2;unset($row);Game::saveBase($id,$b);return $b;}
$pdo->beginTransaction();
try{
    $id=Game::register('Cavern Keeper','disposable-test-password')['id'];$guest=Game::register('Cavern Guest','disposable-test-password')['id'];
    ck(!InfernoRealm::view($id)['canEnter'],'Level 1 hall cannot open entrance');
    $entrance=['action'=>'enter','requestId'=>key16()];
    deny(fn()=>InfernoRealm::act($id,$entrance),'level 2');
    deny(fn()=>InfernoRealm::visit($id,201),'entrance');deny(fn()=>BattleService::startInferno($id,201,[]),'entrance');
    $home=promote($id);$initial=InfernoRealm::act($id,$entrance);
    ck($initial['opened']&&$initial['revision']===1&&$initial['base']['army']['spurtz']===8&&$initial['capacity']===12000,'Entered equipped cavern');
    ck(count($initial['catalog'])===16&&count($initial['monsters']['options'])===8,'Cavern includes all facilities and native species');
    ck($initial['catalog']['townhall']['maxLevel']===6&&$initial['catalog']['infernocannon']['maxLevel']===7,'Native final defense tier reachable through level 6 Under Hall');
    ck(Game::loadBase($id)['resources']['twig']===$home['resources']['twig']-1800,'Entrance debits surface twigs once');
    ck(InfernoRealm::act($id,$entrance)==$initial,'Reusing entrance request cannot pay twice');
    ck(!array_key_exists('infernoRealm',Game::visit($id,$guest)),'Cavern state is private');
    ck(count($initial['camps'])===34&&count(array_filter($initial['camps'],fn($c)=>$c['open']))===1,'Thirty-four camps and one starting expedition');
    foreach(InfernoRealm::campCatalog() as $camp){
        $yard=InfernoRealm::campYard($camp);
        ck(count($yard['buildings'])>=10&&count($yard['buildings'])<=78,'Mapped camp count');
        ck(count(array_filter($yard['buildings'],fn($b)=>$b['type']==='townhall'))===1,'Unique Under Hall');
        foreach($yard['buildings'] as $b)ck($b['x']>=1&&$b['x']<=31&&$b['y']>=1&&$b['y']<=31&&$b['hp']>0,'Bounded camp structure');
    }
    deny(fn()=>InfernoRealm::visit($id,202),'preceding');
    ck(InfernoRealm::visit($id,201)['infernoCamp']['stage']===201,'Scouted original camp');
    $campBuild=['action'=>'build','type'=>'storage','x'=>5,'y'=>18,'realmRevision'=>1,'requestId'=>key16()];
    deny(fn()=>InfernoRealm::act($id,array_merge($campBuild,['type'=>'railgun'])),'valid Inferno');
    deny(fn()=>InfernoRealm::act($id,array_merge($campBuild,['type'=>'quaketower'])),'Under Hall');
    deny(fn()=>InfernoRealm::act($id,array_merge($campBuild,['x'=>16,'y'=>16])),'overlaps');
    deny(fn()=>InfernoRealm::act($id,array_merge($campBuild,['x'=>7,'y'=>32])),'inside');
    $order=InfernoRealm::act($id,$campBuild);$new=end($order['base']['buildings']);
    ck($new['level']===0&&$new['ready_at']>time()&&$order['base']['resources']['bone']===3750,'Paid site placement');
    ck(InfernoRealm::act($id,$campBuild)['revision']===2,'Building retry is idempotent');
    deny(fn()=>InfernoRealm::act($id,array_merge($campBuild,['requestId'=>key16()])),'changed');
    deny(fn()=>commandI($id,['action'=>'build','type'=>'wall','x'=>7,'y'=>17]),'worker is busy');
    ageCavern($id,90);$ready=InfernoRealm::view($id);
    ck(end($ready['base']['buildings'])['level']===1&&$ready['capacity']===24000,'Offline build and storage completion');
    $moved=commandI($id,['action'=>'move','id'=>$new['id'],'x'=>6,'y'=>19]);ck(end($moved['base']['buildings'])['x']==6,'Cavern building moves precisely');
    $col=commandI($id,['action'=>'collect']);ck($col['base']['resources']['bone']>3750,'Harvest is credited to underground wallet');
    $hall=$initial['base']['buildings'][0]['id'];commandI($id,['action'=>'upgrade','id'=>$hall]);ageCavern($id,200);
    ck(InfernoRealm::view($id)['base']['buildings'][0]['level']===2,'Under Hall upgrade persists');
    $train=commandI($id,['action'=>'infernoTrain','type'=>'spurtz','monsterRevision'=>0]);ck(count($train['monsters']['queue'])===1,'Incubator accepts a paid order');
    ageCavern($id,500);ck(InfernoRealm::view($id)['base']['army']['spurtz']===9,'Trained Spurtz persists');
    $surface=Game::loadBase($id);$launch=['requestId'=>key16(),'previousId'=>null];
    $b=BattleService::startInferno($id,201,$launch);$raid=$b['id'];
    ck($b['remaining']['spurtz']===9&&!isset($b['remaining']['pokey'])&&$b['champion']===null&&$b['catapult']===null&&!isset($b['siege']),'Only local army and equipment enters');
    ck(BattleService::startInferno($id,201,$launch)['id']===$raid,'Repeated launch resumes');
    ck((int)Game::snapshot($id)['activeBattle']['id']===$raid,'Battle resumes after reload');
    deny(fn()=>BattleService::deploy($id,$raid,'pokey',3,12),'trained army');
    deny(fn()=>BattleService::poll($guest,$raid),'cannot control');
    deny(fn()=>commandI($id,['action'=>'repair']),'current battle');
    $deployed=BattleService::deploy($id,$raid,['spurtz'=>8],3,12);
    ck(count($deployed['units'])===8&&Game::loadBase($id)['infernoRealm']['army']['spurtz']===1,'Native deployment consumes exactly eight monsters');
    ageRaid($raid);$won=BattleService::poll($id,$raid);
    ck($won['result']['attackerWon']&&$won['result']['inferno']['firstClear'],'Entry force can actually clear first Moloch camp');
    ck($won['result']['target']['kind']==='inferno'&&$won['result']['loot']['magma']===420,'Cavern reward settles once');
    $after=Game::loadBase($id);ck($after['army']===$surface['army']&&$after['shield_until']===$surface['shield_until']&&Game::user($id)['rank_points']===0,'Surface army shield and rank preserved');
    ck(BattleService::startInferno($id,201,$launch)['result']==$won['result'],'Late retry returns settled reward');
    ck(InfernoRealm::visit($id,214)['infernoCamp']['open']&&InfernoRealm::visit($id,202)['infernoCamp']['open'],'First descent opens both branches');
    $replay=BattleService::startInferno($id,201,['requestId'=>key16(),'previousId'=>$raid]);
    $loss=BattleService::retreat($id,$replay['id']);ck(!$loss['result']['attackerWon']&&!$loss['result']['inferno']['firstClear']&&array_sum($loss['result']['loot'])===0,'Retreat gives no loot');
    $b=Game::loadBase($id);$b['infernoRealm']['army']['spurtz']=8;Game::saveBase($id,$b);
    $again=BattleService::startInferno($id,201,['requestId'=>key16(),'previousId'=>$replay['id']]);
    BattleService::deploy($id,$again['id'],['spurtz'=>8],3,12);ageRaid($again['id']);$r=BattleService::poll($id,$again['id'])['result'];
    ck($r['attackerWon']&&!$r['inferno']['firstClear']&&!array_sum($r['loot']),'Successful replay pays no duplicate loot');
    $start=['action'=>'start','requestId'=>key16(),'previousId'=>null];$wave=InfernoDefense::run($id,$start);$waveId=$wave['counterattack']['id'];
    ck($wave['battle']['infernoDefense']&&InfernoDefense::run($id,$start)['counterattack']['id']===$waveId,'Counterattack starts on the real cavern and retries safely');
    deny(fn()=>commandI($id,['action'=>'repair']),'ongoing');
    deny(fn()=>BattleService::startInferno($id,201,['requestId'=>key16(),'previousId'=>$again['id']]),'counterattack');
    $b=Game::loadBase($id);$b['infernoRealm']['counterattack']['state']['launch_at']-=400;Game::saveBase($id,$b);
    for($i=0;$i<=155&&$wave['battle']['status']==='active';++$i)$wave=InfernoDefense::run($id,['action'=>'poll','id'=>$waveId]);
    ck($wave['battle']['status']==='finished'&&$wave['battle']['result']['spawned']===3,'Real wave reaches settlement');
    $b=Game::loadBase($id);$damaged=array_filter($b['infernoRealm']['buildings'],fn($row)=>$row['hp']<$row['max_hp']);
    ck((bool)$damaged,'Damage survives closing and reopening the cavern');
    $wallet=$b['infernoRealm']['resources'];$repaired=commandI($id,['action'=>'repair']);
    ck($repaired['base']['resources']===$wallet&&!array_filter($repaired['base']['buildings'],fn($row)=>$row['hp']<$row['max_hp']),'Free instant repair restores the cavern');
    InfernoDefense::run($id,['action'=>'end','id'=>$waveId]);
    ck(array_column(InfernoRealm::view($id)['base']['buildings'],'hp')==array_column($repaired['base']['buildings'],'hp'),'Repeating settled defense cannot undo repair');
    ck(count(Game::reports($id)['items'])===3&&(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===2,'Expeditions logged, no hidden ladder actors');
}finally{$pdo->rollBack();}
echo "Inferno realm: $n checks passed.\n";
