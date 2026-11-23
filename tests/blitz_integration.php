<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !str_ends_with((string)getenv('MYSQL_TEST_DATABASE'), '_test')) {
    fwrite(STDERR,"Select an empty disposable MYSQL_TEST_DATABASE ending in _test.\n"); exit(2);
}
require_once __DIR__.'/../app/bootstrap.php';
$config = ['host'=>getenv('MYSQL_TEST_HOST') ?: '127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT') ?: 3306),
    'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER') ?: 'root','password'=>getenv('MYSQL_TEST_PASSWORD') ?: '','game_speed'=>1];
$pdo = Db::connect($config);
Db::useConnection($pdo,$config);
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) { throw new RuntimeException('Expected a disposable empty world.'); }
$checks=0;
function checkEvent(bool $ok, string $label):void {
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    ++$checks;
}
$pdo->beginTransaction();
try {
    $pdo->exec("INSERT INTO users(name,created_at) VALUES ('Wave Keeper',0)");
    $id=(int)$pdo->lastInsertId();$home=Game::initialBase($id);
    foreach ($home['buildings'] as &$b) { if ($b['type']==='townhall') { $b['level']=3; } }unset($b);
    $pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($home,JSON_THROW_ON_ERROR),time()]);
    World::assignHome($id);
    $request=['stage'=>1,'previousId'=>null,'requestId'=>'first-local-blitz-request'];
    $started=BaiterService::run($id,'blitzStart',$request);
    checkEvent($started['battle']['blitzStage']===1 && $started['battle']['target']['kind']==='blitz','first event really started');
    $round=$started['session']['id'];$deadline=$started['blitz']['deadline'];
    checkEvent($deadline>=time()+518395,'six-day personal deadline');
    $again=BaiterService::run($id,'blitzStart',$request);
    checkEvent($round===$again['session']['id'] && $deadline===$again['blitz']['deadline'],'retry reopens same event');
    $yard=Game::loadBase($id,true);
    $other=Game::publicBase($id,$yard,false);
    checkEvent(!isset($other['blitz'],$other['baiterPractice']),'rival cannot read event controls');
    $yard['baiterPractice']['state']['launch_at']-=360;
    Game::saveBase($id,$yard);
    for ($i=0;$i<180;++$i) {
        $end=BaiterService::run($id,'baiterPoll',['id'=>$round]);
        if ($end['battle']['status']==='finished') { break; }
    }
    checkEvent($end['battle']['status']==='finished','real event simulation settles');
    $finished=Game::loadBase($id,true);
    $before=array_column($home['buildings'],'hp','id');$after=array_column($finished['buildings'],'hp','id');
    checkEvent($before!==$after,'event physically damaged the home yard');
    $again=BaiterService::run($id,'baiterPoll',['id'=>$round]);
    checkEvent($after===array_column(Game::loadBase($id,true)['buildings'],'hp','id') && $again['blitz']===$end['blitz'],'repeated settlement does not apply damage or reward twice');
    $user=Game::user($id);
    checkEvent($user['rank_points']===0 && (int)$user['wins']===0 && (int)$user['losses']===0,'event independent of competitive standings');
    $repaired=Game::mutate($id,'repairBase',[]);
    checkEvent($repaired['repairResult']['repaired']>0,'normal public free repair restores event damage');
    $stage=$again['blitz']['cleared']+1;
    $next=BaiterService::run($id,'blitzStart',['stage'=>$stage,'previousId'=>$round,'requestId'=>'second-local-blitz-request']);
    $ended=BaiterService::run($id,'baiterSiren',['id'=>$next['session']['id']]);
    checkEvent($ended['battle']['result']['outcome']==='dismissed' && $ended['blitz']['cleared']===$again['blitz']['cleared'],'siren never advances event');
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
echo "Monster Blitzkrieg persistence: $checks checks passed.\n";
