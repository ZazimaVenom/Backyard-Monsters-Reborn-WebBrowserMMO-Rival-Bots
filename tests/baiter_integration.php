<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !str_ends_with((string)getenv('MYSQL_TEST_DATABASE'), '_baiter_test')) {
    fwrite(STDERR, "Select an empty disposable MYSQL_TEST_DATABASE ending in _baiter_test.\n");
    exit(2);
}
require_once __DIR__.'/../app/bootstrap.php';
$config = ['host'=>getenv('MYSQL_TEST_HOST') ?: '127.0.0.1', 'port'=>(int)(getenv('MYSQL_TEST_PORT') ?: 3306),
    'database'=>getenv('MYSQL_TEST_DATABASE'), 'username'=>getenv('MYSQL_TEST_USER') ?: 'root',
    'password'=>getenv('MYSQL_TEST_PASSWORD') ?: '', 'game_speed'=>1];
$pdo = Db::connect($config);
Db::useConnection($pdo,$config);
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) { throw new RuntimeException('The test database must be empty.'); }
$checks=0;
function assertBaiter(bool $ok,string $label):void {
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    ++$checks;
}
function blockBaiter(callable $callback,string $reason):void {
    try { $callback(); }
    catch (RuntimeException $error) { assertBaiter(str_contains($error->getMessage(),$reason),$error->getMessage());return; }
    throw new RuntimeException('Expected rejection: '.$reason);
}
function newHome(string $name):int {
    $pdo=Db::pdo();
    $pdo->prepare('INSERT INTO users(name,created_at) VALUES(?,?)')->execute([$name,time()]);
    $id=(int)$pdo->lastInsertId();
    $base=Game::initialBase($id);
    $base['resources']=array_fill_keys(['twig','pebble','putty','goo'],300000);
    $base['shield_until']=0;
    $pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($base,JSON_THROW_ON_ERROR),time()]);
    World::assignHome($id);
    return $id;
}
function fixtureBuilding(int $owner,int $id,array $changes):void {
    $base=Game::loadBase($owner);
    foreach ($base['buildings'] as &$building) {
        if ($building['id']===$id) { $building=array_replace($building,$changes); break; }
    }
    unset($building);
    Game::saveBase($owner,$base);
}
$pdo->beginTransaction();
try {
    $owner=newHome('Musk Keeper');
    $other=newHome('Musk Rival');
    $start=['requestId'=>str_repeat('A',16),'previousId'=>null,'wave'=>['pokey'=>2],'direction'=>'tl'];
    $build=['type'=>'baiter','x'=>4,'y'=>18];
    blockBaiter(fn()=>BaiterService::run($owner,'baiterStart',$start),'Build');
    blockBaiter(fn()=>Game::mutate($owner,'build',$build),'Town Hall');
    fixtureBuilding($owner,1,['level'=>4]);
    blockBaiter(fn()=>Game::mutate($owner,'build',$build),'Locker');
    $locker=Game::mutate($owner,'build',['type'=>'locker','x'=>3,'y'=>3])['base'];
    $lockerId=end($locker['buildings'])['id'];
    fixtureBuilding($owner,$lockerId,['ready_at'=>time()-1]);
    $built=Game::mutate($owner,'build',$build)['base'];
    $baiterId=end($built['buildings'])['id'];
    assertBaiter(end($built['buildings'])['level']===0&&!$built['baiter']['available'],'Construction did not occupy a real job');
    blockBaiter(fn()=>Game::mutate($owner,'build',$build),'limit');
    fixtureBuilding($owner,$baiterId,['ready_at'=>time()-1]);
    $ready=Game::snapshot($owner)['base']['baiter'];
    assertBaiter($ready['available']&&$ready['capacity']===600,'Completed facility allows practice');
    blockBaiter(fn()=>Game::mutate($owner,'upgrade',['id'=>$baiterId]),'Locker level 2');
    fixtureBuilding($owner,$lockerId,['level'=>2]);
    $bank=Game::loadBase($owner)['resources'];
    $up=Game::mutate($owner,'upgrade',['id'=>$baiterId])['base'];
    $queued=array_column($up['buildings'],null,'id')[$baiterId];
    assertBaiter($queued['upgrade_to']===2 && $queued['ready_at']>=time()+115 && $up['resources']['twig']===(int)$bank['twig']-10000,'Level two uses source price and timer');
    blockBaiter(fn()=>BaiterService::run($owner,'baiterStart',$start),'upgrade');
    fixtureBuilding($owner,$baiterId,['ready_at'=>time()-1]);
    Game::snapshot($owner);
    $before=Game::loadBase($owner);
    $first=BaiterService::run($owner,'baiterStart',$start);
    $key=['id'=>$first['session']['id']];
    assertBaiter($first['session']['status']==='active'&&$first['battle']['training'],'Drill did not start');
    assertBaiter(BaiterService::run($owner,'baiterStart',$start)['session']===$first['session'],'Duplicate start');
    $after=Game::loadBase($owner);
    unset($after['baiterPractice']);
    assertBaiter($before===$after,'A practice start mutated real home data');
    $mine=Game::snapshot($owner);
    assertBaiter($mine['base']['baiter']['session']===$first['session'] && $mine['activeBattle']===null,'Reload must return only the private drill summary');
    $rival=Game::visit($owner,$other);
    assertBaiter(!isset($rival['baiter'])&&!isset($rival['baiterPractice'])&&!str_contains(json_encode($rival),$key['id']),'Drill or ID leaked to rival');
    blockBaiter(fn()=>BaiterService::run($other,'baiterPoll',$key),'no longer current');
    $invasion=BattleService::start($other,$owner);
    blockBaiter(fn()=>BaiterService::run($owner,'baiterPoll',$key),'real invasion');
    blockBaiter(fn()=>BaiterService::run($owner,'baiterSiren',$key),'real invasion');
    BattleService::retreat($other,$invasion['id']);
    $statsAfterInvasion=Game::user($owner);
    $reportCount=(int)$pdo->query('SELECT COUNT(*) FROM reports')->fetchColumn();
    $battles=(int)$pdo->query('SELECT COUNT(*) FROM battles')->fetchColumn();
    $home=Game::loadBase($owner);
    $home['baiterPractice']['state']['launch_at']=time()-3;
    Game::saveBase($owner,$home);
    $poll=BaiterService::run($owner,'baiterPoll',$key);
    assertBaiter($poll['battle']['elapsed']>0,'Wave did not resume after real invasion');
    $end=BaiterService::run($owner,'baiterSiren',$key);
    assertBaiter($end['battle']['result']['outcome']==='dismissed','Siren failed');
    assertBaiter(BaiterService::run($owner,'baiterSiren',$key)['session']===$end['session'],'Siren duplicate rewrote drill');
    $new=BaiterService::run($owner,'baiterStart',['previousId'=>$key['id'],'requestId'=>str_repeat('B',16)]+$start);
    blockBaiter(fn()=>BaiterService::run($owner,'baiterSiren',$key),'no longer current');
    blockBaiter(fn()=>BaiterService::run($owner,'baiterStart',$start),'changed');
    assertBaiter(Game::user($owner)===$statsAfterInvasion,'Training changed rank or wins');
    assertBaiter((int)$pdo->query('SELECT COUNT(*) FROM reports')->fetchColumn()===$reportCount && (int)$pdo->query('SELECT COUNT(*) FROM battles')->fetchColumn()===$battles,'Training created an invasion or report');
    assertBaiter((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===2,'Training created an account');
    $current=Game::loadBase($owner);
    unset($home['baiterPractice'],$current['baiterPractice']);
    assertBaiter($current===$home,'Polling or ending practice changed the real yard');
    echo "Baiter database integration: $checks checks passed.\n";
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
