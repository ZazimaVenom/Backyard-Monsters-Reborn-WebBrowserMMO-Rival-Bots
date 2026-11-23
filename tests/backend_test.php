<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$assertions=0;
function check(bool $condition,string $label): void { global $assertions;$assertions++;if(!$condition){throw new RuntimeException('FAIL: '.$label);} }
function rejects(callable $work,string $label): void { try{$work();}catch(RuntimeException){check(true,$label);return;}throw new RuntimeException('FAIL: did not reject '.$label); }
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE')?:'backyard_test','username'=>getenv('MYSQL_TEST_USER')?:'backyard_test','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1,'player_grace_seconds'=>1800];
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
foreach(explode(';',file_get_contents(__DIR__.'/../app/schema.sql')) as $sql){if(trim($sql)!==''){$pdo->exec($sql);}}
$pdo->beginTransaction();
try{
    $suffix=bin2hex(random_bytes(4));$name='Test '.$suffix;
    $user=Game::register($name,'correct-local-password');$id=$user['id'];
    check($user['rank_points']===0,'human starts at zero rank');
    check($_SESSION['user_id']===$id,'registration signs in');
    $b=Game::loadBase($id);check(Game::housingUsed($b)<=Game::housingCapacity($b),'starter army fits housing');check(count($b['buildings'])===8,'starter has eight functional buildings including flinger');
    rejects(fn()=>Game::register($name,'correct-local-password'),'duplicate keeper');
    rejects(fn()=>Game::login($name,'wrong-password'),'incorrect password');
    check(Game::login($name,'correct-local-password')['id']===$id,'password hash verifies');
    rejects(fn()=>Game::mutate($id,'build',['type'=>'sniper','x'=>16,'y'=>16]),'building overlap');
    rejects(fn()=>Game::mutate($id,'build',['type'=>'sniper','x'=>32,'y'=>2]),'building outside yard');
    rejects(fn()=>Game::mutate($id,'build',['type'=>'townhall','x'=>5,'y'=>5]),'duplicate town hall');
    rejects(fn()=>Game::mutate($id,'build',['type'=>'tesla','x'=>5,'y'=>5]),'locked advanced building');
    $before=Game::loadBase($id)['resources'];$built=Game::mutate($id,'build',['type'=>'wall','x'=>5,'y'=>5]);$new=end($built['base']['buildings']);
    check($new['level']===0&&$new['ready_at']>time(),'construction has real timer');check($built['base']['resources']['pebble']===$before['pebble']-130,'construction deducts cost once');
    $b=Game::loadBase($id);$index=array_key_last($b['buildings']);$b['buildings'][$index]['ready_at']=time()-1;Game::saveBase($id,$b);$snap=Game::snapshot($id);check(end($snap['base']['buildings'])['level']===1,'construction completion persists');
    rejects(fn()=>Game::mutate($id,'move',['id'=>1,'x'=>9,'y'=>13]),'move collision');Game::mutate($id,'move',['id'=>$new['id'],'x'=>4,'y'=>6]);check((float)end(Game::loadBase($id)['buildings'])['x']===4.0,'valid move persists');
    $b=Game::loadBase($id);$b['resources']['goo']=0;Game::saveBase($id,$b);rejects(fn()=>Game::mutate($id,'train',['type'=>'pokey','quantity'=>1]),'insufficient training resources');
    $b['resources']['goo']=24000;Game::saveBase($id,$b);$trained=Game::mutate($id,'train',['type'=>'pokey','quantity'=>2]);check(count($trained['queue'])===2,'hatchery queues individual units');check($trained['queue'][1]['ready_at']>$trained['queue'][0]['ready_at'],'single hatchery serializes training');
    rejects(fn()=>Game::mutate($id,'train',['type'=>'pokey','quantity'=>30]),'housing includes queued army');
    $b=Game::loadBase($id);foreach($b['queue'] as &$q){$q['ready_at']=time()-1;}unset($q);Game::saveBase($id,$b);$snap=Game::snapshot($id);check($snap['army']['pokey']===26&&count($snap['queue'])===0,'hatching accrues exactly once');check(Game::snapshot($id)['army']['pokey']===26,'repeated poll does not double-hatch');
    $b=Game::loadBase($id);$b['last_tick']=time()-100;$before=$b['resources']['twig'];Game::saveBase($id,$b);$collected=Game::mutate($id,'collect',[]);check($collected['base']['resources']['twig']>=$before+500,'factories accrue and collect');
    $b=Game::loadBase($id);$b['resources']['twig']=Game::capacity($b);foreach($b['buildings'] as &$v){if($v['type']==='twig'){$v['stored']=1000;}}unset($v);Game::saveBase($id,$b);$r=Game::mutate($id,'collect',[]);check($r['base']['resources']['twig']===Game::capacity($b),'storage cap enforced');
    $b=Game::loadBase($id);$b['buildings'][0]['hp']=1;Game::saveBase($id,$b);rejects(fn()=>Game::mutate($id,'upgrade',['id'=>1]),'damaged building upgrade');$r=Game::mutate($id,'repair',['id'=>1]);check($r['base']['buildings'][0]['hp']===$r['base']['buildings'][0]['max_hp'],'repair restores hp');
    $r=Game::mutate($id,'upgrade',['id'=>1]);check($r['base']['buildings'][0]['upgrade_to']===2,'townhall upgrade target');
    // The upgraded production rate must never be applied retroactively.
    $sample=Game::initialBase($id);$sample['last_tick']=1000;$sample['buildings']=[['id'=>1,'type'=>'twig','x'=>1,'y'=>1,'level'=>1,'hp'=>1200,'max_hp'=>1200,'ready_at'=>1050,'upgrade_to'=>2,'stored'=>0]];Game::accrue($sample,1100);check($sample['buildings'][0]['stored']===750.0,'upgrade rate split at completion');
    $sample['last_tick']=1000;$sample['buildings'][0]=['id'=>1,'type'=>'twig','x'=>1,'y'=>1,'level'=>0,'hp'=>0,'max_hp'=>1200,'ready_at'=>1050,'upgrade_to'=>1,'stored'=>0];Game::accrue($sample,1100);check($sample['buildings'][0]['stored']===250.0,'new producer starts only at completion');
    $botsBefore=(int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn();
    if($botsBefore===0){
        Game::seedBots($pdo,1000);check((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1')->fetchColumn()===1000,'exactly 1000 persistent bots');
        check((int)$pdo->query('SELECT COUNT(DISTINCT name) FROM users WHERE is_bot=1')->fetchColumn()===1000,'all bot names unique');
        check((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_bot=1 AND rank_points<>0')->fetchColumn()===0,'all bot ranks zero');
        check((int)$pdo->query('SELECT COUNT(*) FROM bases b JOIN users u ON u.id=b.user_id WHERE u.is_bot=1')->fetchColumn()===1000,'each bot has persistent base');
    }
    $ranking=Game::listing('rankings',$id);check($ranking['total']>=1001,'ranking includes human and bots');check(count($ranking['items'])===24,'ranking pagination');check(Game::listing('rankings',$id,9999)['page']===$ranking['pages'],'pagination clamps bounds');
    $opponents=Game::listing('opponents',$id);check(!in_array($id,array_column($opponents['items'],'id'),true),'cannot attack self from list');
    $filtered=Game::listing('opponents',$id,1,'','bots');check($filtered['total']===1000,'database bot filtering counts all pages');check(count(array_filter($filtered['items'],fn($u)=>!$u['is_bot']))===0,'bot filter returns only bots');$filtered=Game::listing('rankings',$id,1,'','players');check(count(array_filter($filtered['items'],fn($u)=>$u['is_bot']))===0,'player filter excludes bots');rejects(fn()=>Game::mutate($id,'move',['id'=>true,'x'=>5,'y'=>5]),'boolean building ID');rejects(fn()=>Game::mutate($id,'train',['type'=>'pokey','quantity'=>true]),'boolean training quantity');
    $private=Game::visit($id);check(!isset($private['password_hash']),'public visit excludes credentials');
    $q=$pdo->prepare("INSERT INTO battles(attacker_id,defender_id,status,state_json,created_at,updated_at) VALUES(?,?,'active','{}',?,?)");$q->execute([$id,$opponents['items'][0]['id'],time(),time()]);
    rejects(fn()=>Game::mutate($id,'collect',[]),'active battle blocks mutations');
    $pdo->rollBack();echo "Backend integration: $assertions assertions passed. Test data rolled back.\n";
}catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,(string)$e."\n");exit(1);}
