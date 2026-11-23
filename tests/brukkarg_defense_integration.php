<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$config=['database'=>getenv('MYSQL_TEST_DATABASE')?:'','host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1',
    'port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if (PHP_SAPI!=='cli' || !str_ends_with($config['database'],'_test')) {fwrite(STDERR,"Use an empty disposable database ending in _test.\n");exit(2);}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
if ($pdo->query('SHOW TABLES')->fetch()) throw new RuntimeException('The defense test requires an empty database.');
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$checks=0;
function verifyWarDefense(bool $good,string $why):void {global $checks;if(!$good)throw new RuntimeException($why);++$checks;}
$dbUser=Game::register('Countermarch Keeper','brukkarg-test-password');$id=$dbUser['id'];
$base=Game::loadBase($id);
foreach ($base['buildings'] as &$b) {
    if ($b['type']==='townhall') { $b['level']=5;$b['hp']=$b['max_hp']=Game::buildingStats('townhall',5)['max_hp']; }
}
unset($b);Game::saveBase($id,$base);
$start=['stage'=>1,'previousId'=>null,'requestId'=>'first-brukkarg-defense'];
$opening=BaiterService::run($id,'brukkargDefend',$start);
$session=$opening['session']['id'];
verifyWarDefense($opening['battle']['wave']===['octo'=>50,'ichi'=>50] && $opening['session']['brukkargStage']===1,'Server selected the original roster');
verifyWarDefense(BaiterService::run($id,'brukkargDefend',$start)['session']['id']===$session,'Idempotent start');
$copy=Game::loadBase($id);
verifyWarDefense(Brukkarg::score($copy)===0 && !isset($copy['blitzDeadline'],$copy['blitzCleared']),'Independent calendar and score');
// Test the committed end of a real combat wave at the point immediately after
// tower hits. The engine makes the outcome and performs the settlement.
$copy['baiterPractice']['state']['launch_at']-=5;Game::saveBase($id,$copy);
$poll=BaiterService::run($id,'baiterPoll',['id'=>$session]);verifyWarDefense(count($poll['battle']['units'])===100,'Timed attackers persisted');
$copy=Game::loadBase($id);$state=&$copy['baiterPractice']['state'];
foreach ($state['units'] as &$u) { $u['hp']=0; $u['state']='dead'; }unset($u);
$state['launch_at']-=5;$state['buildings'][0]['hp']-=150;
$building=$state['buildings'][0]['id'];unset($state);Game::saveBase($id,$copy);
$done=BaiterService::run($id,'baiterPoll',['id'=>$session]);
verifyWarDefense($done['battle']['result']['outcome']==='held','Combat confirms a held wave');
$copy=Game::loadBase($id);$hp=array_column($copy['buildings'],'hp','id');
verifyWarDefense(Brukkarg::score($copy)===1 && $hp[$building]===array_column($base['buildings'],'hp','id')[$building]-150,'Damage and one earned point committed to the same home');
BaiterService::run($id,'baiterPoll',['id'=>$session]);$again=Game::loadBase($id);
verifyWarDefense($hp===array_column($again['buildings'],'hp','id') && Brukkarg::score($again)===1,'Duplicate poll does not repeat damage or award');
$repair=Game::mutate($id,'repairBase',[]);verifyWarDefense($repair['repairResult']['repaired']>0,'Free home repair applies to event damage');
$next=BaiterService::run($id,'brukkargDefend',['stage'=>2,'requestId'=>'second-brukkarg-defense','previousId'=>$session]);
$session=$next['session']['id'];
verifyWarDefense(count($next['battle']['entries'])===2,'Opposite deployment directions survive persistence');
$stopped=BaiterService::run($id,'baiterSiren',['id'=>$session]);
verifyWarDefense($stopped['battle']['result']['outcome']==='dismissed' && Brukkarg::score(Game::loadBase($id))===1,'Siren awards no score');
verifyWarDefense(Game::user($id)['rank_points']===0 && Game::user($id)['wins']===0,'Raid points are independent of Brukkarg events');
echo "Brukkarg defensive event persistence: $checks checks passed.\n";
