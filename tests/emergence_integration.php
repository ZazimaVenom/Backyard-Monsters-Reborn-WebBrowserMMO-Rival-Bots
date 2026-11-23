<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli'||!str_ends_with((string)getenv('MYSQL_TEST_DATABASE'),'_emergence_test')) {fwrite(STDERR,"Use a disposable MYSQL_TEST_DATABASE ending in _emergence_test.\n");exit(2);}
require_once __DIR__.'/../app/bootstrap.php';
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$db=Db::connect($config);Db::useConnection($db,$config);$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$n=0;
function emerCheck(bool $ok,string $message):void{global $n;if(!$ok)throw new RuntimeException($message);++$n;}
function emerBlock(callable $f,string $message):void{try{$f();}catch(RuntimeException $e){emerCheck(str_contains($e->getMessage(),$message),$e->getMessage());return;}throw new LogicException('Expected rejection: '.$message);}
function keeper(string $name):int{
    $db=Db::pdo();$db->prepare('INSERT INTO users(name,created_at) VALUES (?,0)')->execute([$name]);
    $id=(int)$db->lastInsertId();$b=Game::initialBase($id);$b['shield_until']=0;
    $db->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES (?,?,?)')->execute([$id,json_encode($b),time()]);World::assignHome($id);return $id;
}
$db->beginTransaction();
try {
    $a=keeper('Entrance Keeper');$other=keeper('Neighbor');
    emerBlock(fn()=>Game::mutate($a,'emergenceRaise',['previousStage'=>0]),'level 5');
    $b=Game::loadBase($a,true);
    foreach($b['buildings'] as &$building){if($building['type']==='townhall'){$building['level']=5;}}
    unset($building);
    $b['buildings'][]=['id'=>90,'type'=>'cannon','x'=>6,'y'=>14,'level'=>5,'hp'=>5000,'max_hp'=>5000,'ready_at'=>0];
    Game::saveBase($a,$b);$army=$b['army'];
    emerBlock(fn()=>Game::mutate($a,'build',['type'=>'quaketower','x'=>28,'y'=>28]),'available building');
    $previous=null;
    for($stage=1;$stage<=5;$stage++){
        if($stage>1){
            emerBlock(fn()=>Game::mutate($a,'emergenceRaise',['previousStage'=>$stage-1]),'gathering');
            $b=Game::loadBase($a,true);$b['emergence']['raisedAt']-=43200;Game::saveBase($a,$b);
        }
        $raised=Game::mutate($a,'emergenceRaise',['previousStage'=>$stage-1]);
        emerCheck($raised['base']['emergence']['stage']===$stage && $raised['base']['emergence']['pending'],'quake saved');
        $r=['stage'=>$stage,'previousId'=>$previous,'requestId'=>bin2hex(random_bytes(16))];
        $start=BaiterService::run($a,'emergenceStart',$r);$id=$start['session']['id'];
        emerCheck($start['battle']['emergenceStage']===$stage && $start['battle']['countdown']>0,'session starts');
        emerCheck(BaiterService::run($a,'emergenceStart',$r)['session']['id']===$id,'HTTP retry');
        emerBlock(fn()=>BaiterService::run($a,'emergenceStart',array_replace($r,['stage'=>6])),'matching');
        emerBlock(fn()=>Game::mutate($a,'repairBase',[]),'wave');
        if($stage===1){
            $b=Game::loadBase($a,true);$b['baiterPractice']['state']['launch_at']-=600;Game::saveBase($a,$b);
            for($i=0;$i<180;$i++){ $done=BaiterService::run($a,'baiterPoll',['id'=>$id]); if($done['battle']['status']==='finished')break; }
            emerCheck($done['battle']['result']['outcome']==='held','live towers defeat Moloch');
        }elseif($stage===2){
            $b=Game::loadBase($a,true);$before=$b['buildings'][0]['hp'];$b['baiterPractice']['state']['buildings'][0]['hp']-=225;Game::saveBase($a,$b);
            $raid=BattleService::start($other,$a);
            emerCheck((float)$raid['buildings'][0]['hp']===(float)$before-225,'incoming raid settles home damage');
            BattleService::retreat($other,$raid['id']);
            $fixed=Game::mutate($a,'repairBase',[]);emerCheck($fixed['repairResult']['repaired']>0,'free home repair');
        }else{$done=BaiterService::run($a,'baiterSiren',['id'=>$id]);emerCheck($done['battle']['result']['outcome']==='dismissed','siren');}
        $done=BaiterService::run($a,'baiterPoll',['id'=>$id]);
        emerCheck(!$done['emergence']['pending'] && $done['emergence']['complete']===($stage===5),'completed once');
        $previous=$id;
    }
    $b=Game::loadBase($a,true);emerCheck($b['army']===$army,'surface reserves remain intact');
    $empty=$b['resources']=array_fill_keys(['twig','pebble','putty','goo'],0);$b['last_tick']=time();Game::saveBase($a,$b);
    $pass=InfernoRealm::view($a);emerCheck($pass['canEnter'] && $pass['entryCost']===[],'reward unlocks passage');
    $r=['action'=>'enter','requestId'=>bin2hex(random_bytes(16))];
    $realm=InfernoRealm::act($a,$r);emerCheck($realm['opened'] && $realm['cleared']===0,'entered through mature skull');
    $b=Game::loadBase($a,true);emerCheck($b['resources']===$empty,'no entry charge');
    $realm=InfernoRealm::act($a,$r);emerCheck($realm['opened'] && $realm['cleared']===0,'old paid or granted cavern preserved on retry');
    $b['resources']=array_fill_keys(array_keys($empty),14000);Game::saveBase($a,$b);
    $built=Game::mutate($a,'build',['type'=>'quaketower','x'=>28,'y'=>28]);
    emerCheck(in_array('quaketower',array_column($built['base']['buildings'],'type'),true),'surface tower award can be built');
    emerBlock(fn()=>Game::mutate($a,'build',['type'=>'infernocannon','x'=>25,'y'=>27]),'available building');
    echo "Inferno Emergence database: $n checks passed.\n";
} finally { $db->rollBack(); }
