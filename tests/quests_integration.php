<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$config=['database'=>getenv('MYSQL_TEST_DATABASE') ?: '', 'host'=>getenv('MYSQL_TEST_HOST') ?: '127.0.0.1',
    'port'=>(int)(getenv('MYSQL_TEST_PORT') ?: 3306),'username'=>getenv('MYSQL_TEST_USER') ?: 'root',
    'password'=>getenv('MYSQL_TEST_PASSWORD') ?: '', 'game_speed'=>1];
if (PHP_SAPI!=='cli' || !str_ends_with($config['database'],'_quests_test')) {
    fwrite(STDERR,"Select a disposable MYSQL_TEST_DATABASE ending in _quests_test.\n");exit(2);
}
$pdo=Db::connect($config);
Db::useConnection($pdo,$config);
if (($argv[1] ?? '')==='--claim') {
    $result=Game::mutate((int)$argv[2],'questClaim',['quest'=>'C0']);
    echo json_encode($result['questClaim'],JSON_THROW_ON_ERROR);
    exit;
}
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0) {
    throw new RuntimeException('Use an empty disposable world.');
}
$checks=0;
function questCheck(bool $condition,string $message): void {
    global $checks;if (!$condition) { throw new RuntimeException($message); }++$checks;
}
function createQuestKeeper(string $name): int {
    $pdo=Db::pdo();
    $pdo->prepare('INSERT INTO users(name,created_at) VALUES(?,?)')->execute([$name,time()]);
    $id=(int)$pdo->lastInsertId();
    $base=Game::initialBase($id);
    $pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($base,JSON_THROW_ON_ERROR),time()]);
    World::assignHome($id);return $id;
}
function questRead(int $id):array {
    return array_column(Game::snapshot($id)['base']['quests']['entries'],null,'id');
}
$keeper=createQuestKeeper('Field Keeper');
$rival=createQuestKeeper('Second Keeper');
$pdo->beginTransaction();
Game::loadBase($keeper,true); // Both requests must use the same home row when this transaction releases it.
$children=[];
try {
    for ($i=0;$i<2;++$i) {
        $child=proc_open([PHP_BINARY,__FILE__,'--claim',(string)$keeper],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($child)) { throw new RuntimeException('Could not start the separate claim connection.'); }
        $children[]=[$child,$pipes];
    }
    $pdo->commit();
    $paid=$retried=0;
    foreach ($children as [$process,$pipes]) {
        $receipt=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        questCheck(proc_close($process)===0,'Child claim failed: '.$error);
        $receipt=json_decode($receipt,true,512,JSON_THROW_ON_ERROR);$receipt['alreadyClaimed'] ? ++$retried : ++$paid;
    }
} finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
questCheck($paid===1 && $retried===1,'Independent requests did not yield exactly one paid claim');
questCheck((int)Game::loadBase($keeper)['resources']['pebble']===12075 && questRead($keeper)['C0']['status']==='claimed','Persistent claim and matching balance');
questCheck(questRead($rival)['C0']['status']==='ready','Another keeper retains a separate claim');
questCheck(!isset(Game::visit($keeper,$rival)['quests']),'A rival cannot read the private quest ledger');
questCheck(Game::user($keeper)['rank_points']===0,'Quest awards never alter league points');
$bank=Game::loadBase($keeper)['resources'];
try { Game::mutate($keeper,'questClaim',['quest'=>'BK4','resources'=>['twig'=>8500]]);throw new LogicException('Expected denial'); }
catch (RuntimeException $e) { questCheck(str_contains($e->getMessage(),'Complete'),'Unexpected incomplete quest error'); }
questCheck(Game::loadBase($keeper)['resources']===$bank,'Denied claim leaves the bank intact');
$base=Game::loadBase($keeper);
foreach ($base['buildings'] as &$b) { if ($b['type']==='twig') { $b['stored']=171; } }unset($b);
Game::saveBase($keeper,$base);
$collection=Game::mutate($keeper,'collect',[]);
questCheck($collection['collected']['twig']>=171 && questRead($keeper)['BK1']['status']==='ready','Actual collection credits its quest');
$hatched=Game::mutate($keeper,'train',['type'=>'pokey','quantity'=>3]);
questCheck($hatched['base']['quests']['counts']['ready']>0 && questRead($keeper)['RB-hatch']['progress']===0,'Queued monsters do not count');
$base=Game::loadBase($keeper);
foreach ($base['queue'] as &$job) { $job['ready_at']=time()-1; }unset($job);
Game::saveBase($keeper,$base);
questCheck(questRead($keeper)['RB-hatch']['status']==='ready','Accrued monsters persist before journal is opened');
$extra=Game::mutate($keeper,'questClaim',['quest'=>'RB-hatch']);
questCheck($extra['questClaim']['resources']===['goo'=>125] && questRead($keeper)['RB-hatch']['status']==='claimed','Actual award survives reload');
echo "Quest database integration: $checks checks passed.\n";
