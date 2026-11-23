<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$db=(string)getenv('MYSQL_TEST_DATABASE');
if(PHP_SAPI!=='cli'||!str_ends_with($db,'_test')){fwrite(STDERR,"Choose an empty disposable *_test database.\n");exit(2);}
$config=['database'=>$db,'host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$pdo=Db::connect($config);if($pdo->query('SHOW TABLES')->fetch())throw new RuntimeException('Choose an empty disposable database.');
Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$count=0;
function check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);++$count;}
function blocked(callable $op,string $text):void{try{$op();}catch(RuntimeException $e){check(str_contains($e->getMessage(),$text),$e->getMessage());return;}throw new RuntimeException('Missing guard: '.$text);}
function setupRealm(int $id):array{
    $home=Game::loadBase($id);foreach($home['buildings'] as &$b)if($b['type']==='townhall')$b['level']=2;unset($b);
    if(Game::isBot($id)){check(InfernoRealm::enterBot($home,time(),$id),'Bot pays entrance');Game::saveBase($id,$home);}
    else{Game::saveBase($id,$home);InfernoRealm::act($id,['action'=>'enter','requestId'=>bin2hex(random_bytes(12))]);}
    return Game::loadBase($id);
}
function realmOrder(int $id,string $op):array{$v=InfernoRealm::view($id);return InfernoRealm::act($id,['action'=>$op,'requestId'=>bin2hex(random_bytes(12)),'realmRevision'=>$v['revision']]);}
function ageCombat(int $id,bool $bot=false):void{
    $p=Db::pdo();$q=$p->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);$s=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $s['created_at']-=310;if($bot)$s['_activity_clock']['elapsed']=310;
    $p->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($s,JSON_THROW_ON_ERROR),$id]);
}
$pdo->beginTransaction();
try{
    $alice=Game::register('MagmaKeeper','disposable-test-password')['id'];$bob=Game::register('CavernKeeper','disposable-test-password')['id'];$visitor=Game::register('NotUnderground','disposable-test-password')['id'];
    Game::seedBots($pdo,2);$bots=$pdo->query('SELECT id FROM users WHERE is_bot=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);[$bot1,$bot2]=array_map('intval',$bots);
    $ab=setupRealm($alice);$bb=setupRealm($bob);$b1=setupRealm($bot1);setupRealm($bot2);
    blocked(fn()=>InfernoRivals::opponents($visitor),'entrance');
    $list=InfernoRivals::opponents($alice);check($list['total']===3&&count($list['items'])===3,'Directory includes only other paid owners');
    check(InfernoRivals::opponents($alice,1,'','players')['total']===1,'Player-only search');check(InfernoRivals::opponents($alice,1,'','bots')['total']===2,'Bot-only search');
    check(InfernoRivals::opponents($alice,1,'cavernkeep')['total']===1,'Name search');
    check(InfernoRivals::botTarget($bot1,2,[],false)===$bot2,'Admin restrictions prevent bot targeting a player');
    check(InfernoRivals::botTarget($bot1,2,[$bot2=>true],false)===null,'Busy exclusions hold');
    $scout=InfernoRivals::visit($alice,$bob);check($scout['infernoRival']['canAttack']&&$scout['army']===[]&&!isset($scout['resources'],$scout['buildings'][0]['stored']),'Scouting reveals geometry, protects stockpile and army');
    blocked(fn()=>InfernoRivals::visit($alice,$visitor),'not opened');blocked(fn()=>InfernoRivals::visit($alice,$alice),'another');
    // An unstaffed target provides the offensive win, loot and repair case. Compound defense is covered separately.
    $bb['infernoRealm']['army']['spurtz']=0;Game::saveBase($bob,$bb);
    $req=['requestId'=>str_repeat('x',24),'previousId'=>null];
    $ab['infernoRealm']['resources']['bone']-=100;$ab['truces'][$bob]=['state'=>'accepted','expires'=>time()+1000];Game::saveBase($alice,$ab);
    blocked(fn()=>BattleService::startInfernoRival($alice,$bob,$req),'truce');unset($ab['truces'][$bob]);Game::saveBase($alice,$ab);
    $rr=BattleService::startInfernoRival($alice,$bob,$req);$id=$rr['id'];
    check($rr['target']['kind']==='infernoRival'&&$rr['defender']['id']===$bob&&!isset($rr['remaining']['pokey']),'Starts ranked underground PvP');
    check(BattleService::startInfernoRival($alice,$bob,$req)['id']===$id,'Retry is idempotent');
    blocked(fn()=>BattleService::startInfernoRival($alice,$bot1,$req),'another cavern');
    blocked(fn()=>BattleService::startInferno($bob,201,['requestId'=>str_repeat('q',24),'previousId'=>null]),'battle');
    blocked(fn()=>realmOrder($bob,'repair'),'current battle');blocked(fn()=>BattleService::deploy($bob,$id,'spurtz',2,2),'cannot control');
    $view=BattleService::poll($bob,$id);check($view['defender']['id']===$bob&&$view['remaining']===[]&&$view['infernoStores']['resources']===$bb['infernoRealm']['resources']&&$view['infernoStores']['resources']!==$rr['infernoStores']['resources'],'Defender can watch but cannot read attacker wallet');
    $dep=BattleService::deploy($alice,$id,['spurtz'=>8],7,15);check(count($dep['units'])===8,'Native Flinger debited');ageCombat($id);
    $end=BattleService::poll($alice,$id);$r=$end['result'];check($end['status']==='finished'&&$r['infernoRival']&&$r['attackerWon'],'Completed attack with actual creatures');
    $newA=Game::loadBase($alice);$newB=Game::loadBase($bob);
    check(Game::user($alice)['rank_points']===25&&Game::user($bob)['rank_points']===-15,'Both shared ladder scores settle');
    check(array_sum($r['loot'])>0&&array_keys($r['loot'])===['bone','coal','sulfur','magma'],'Only native loot changes hands');
    foreach($r['loot'] as $k=>$v){check($newA['infernoRealm']['resources'][$k]===$ab['infernoRealm']['resources'][$k]+$v&&$newB['infernoRealm']['resources'][$k]===$bb['infernoRealm']['resources'][$k]-$v,'Native resource conserved');}
    check($newA['army']===$ab['army']&&$newB['army']===$bb['army']&&$newA['shield_until']===$ab['shield_until']&&$newB['shield_until']===$bb['shield_until'],'Surface troops and shields unchanged');
    check(array_column($newB['buildings'],'hp')===array_column($bb['buildings'],'hp')&&array_column($newB['infernoRealm']['buildings'],'hp')!=array_column($bb['infernoRealm']['buildings'],'hp'),'Only cavern HP damaged');
    check(!InfernoRivals::visit($alice,$bob)['infernoRival']['canAttack']&&$newB['infernoRealm']['shield_until']>time(),'Defender receives separate cavern shield');
    blocked(fn()=>BattleService::startInfernoRival($alice,$bob,['requestId'=>str_repeat('y',24),'previousId'=>$id]),'shield');
    $repair=realmOrder($bob,'repair');check(!array_filter($repair['base']['buildings'],fn($b)=>$b['hp']<$b['max_hp'])&&$repair['base']['resources']===$newB['infernoRealm']['resources'],'Free repair restores rival invasion damage');
    check(BattleService::startInfernoRival($alice,$bob,$req)['result']==$r&&count(Game::reports($bob)['items'])===1,'Settled retries cannot rerank or duplicate reports');
    blocked(fn()=>BattleService::startInfernoRival($bot1,$bot2),'pause');
    WorldActivity::pulse($alice,'underground-qa',str_repeat('t',32));$raid=BattleService::startInfernoRival($bot1,$bot2);
    check($raid['attacker']['is_bot']&&count($raid['units'])>0&&Game::loadBase($bot1)['infernoRealm']['army']['spurtz']<$b1['infernoRealm']['army']['spurtz'],'Online bot launches native monsters automatically');
    WorldActivity::logout($alice,'underground-qa');ageCombat($raid['id'],true);$paused=BattleService::poll($bot1,$raid['id']);
    check((float)$paused['elapsed']===(float)$raid['elapsed']&&$paused['status']==='active','Offline bot raid freezes');
    WorldActivity::pulse($alice,'underground-qa',str_repeat('t',32));$out=BattleService::poll($bot1,$raid['id']);check($out['status']==='finished'&&$out['result']['infernoRival'],'Bot raid resumes and settles');
    check(Game::user($bot1)['wins']+Game::user($bot1)['losses']===1&&Game::user($bot2)['wins']+Game::user($bot2)['losses']===1,'Bot rivals compete on same ladder');
    check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===5,'No extra accounts created');
}finally{$pdo->rollBack();}
echo "Inferno ranked rivals: $count checks passed.\n";
