<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/InfernoRealm.php';
require_once __DIR__.'/ActivityFixture.php';
$config=['database'=>getenv('MYSQL_TEST_DATABASE')?:'', 'host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),
    'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(!str_ends_with($config['database'],'_madness_test')){fwrite(STDERR,"Use a disposable database ending in _madness_test.\n");exit(2);}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function mcheck(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);++$checks;}
function denied(callable $work):void{try{$work();}catch(RuntimeException){mcheck(true,'rejected');return;}throw new LogicException('Expected rejection');}
function facility(int $id,string $type,int $level,float $x,float $y):array{
    $hp=Game::buildingStats($type,$level)['max_hp'];
    return ['id'=>$id,'type'=>$type,'level'=>$level,'x'=>$x,'y'=>$y,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'stored'=>0,'upgrade_to'=>null];
}
$pdo->beginTransaction();
try{
    $id=Game::register('Madness '.bin2hex(random_bytes(4)),'monster-madness-test-password')['id'];activityFixtureWake($id);
    $p=Game::loadBase($id);$p['buildings']=[facility(1,'townhall',5,16,16),facility(2,'flinger',3,4,4),facility(3,'maproom',3,8,8),facility(4,'championcage',1,12,24)];
    $p['army']['fink']=20;$p['next_building_id']=5;Game::saveBase($id,$p);
    denied(fn()=>Game::mutate($id,'championRaise',['type'=>'korath']));
    $home=World::home($id);$offset=$home['q']>32?-1:1;
    for($tier=1;$tier<=3;++$tier){
        $p=Game::loadBase($id);$priorPower=$tier-1;$p['monsterMadness']=['score'=>MonsterMadness::GOALS[$tier-1]-100,'best'=>MonsterMadness::GOALS[$tier-1]-100,
            'power'=>$priorPower,'sites'=>[],'ends'=>time()+3600];
        Game::saveBase($id,$p);
        $yard=World::initialState('resource',1);$yard['garrison']=[];$yard['buildings']=[facility(1,'outpost',1,16,16),facility(2,'storage',1,22,22)];
        $yard['buildings'][1]['hp']=$yard['buildings'][1]['max_hp']=100;
        $q=$pdo->prepare("INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES('resource',?,?,NULL,NULL,'Cavern test outpost',1,?,1,?)");
        $q->execute([$home['q']+$tier*$offset,$home['r'],json_encode($yard),time()]);$site=(int)$pdo->lastInsertId();
        $battle=BattleService::startSite($id,$site);$bid=$battle['id'];BattleService::deploy($id,$bid,'fink',20,18);
        $q=$pdo->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$bid]);$battleState=json_decode($q->fetchColumn(),true);
        activityFixtureAgeState($battleState,310);$pdo->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($battleState),$bid]);
        $done=BattleService::poll($id,$bid);
        mcheck($done['result']['captured']&&World::load($site)['owner_id']===$id&&$done['result']['monsterMadness']['earned']&&$done['result']['monsterMadness']['power']===$tier,'Actual takeover crosses configured threshold '.$tier);
        $before=Game::loadBase($id)['monsterMadness'];BattleService::poll($id,$bid);
        mcheck(Game::loadBase($id)['monsterMadness']===$before,'Report replay is idempotent');
        if($tier===1){
            $r=Game::mutate($id,'championRaise',['type'=>'korath']);mcheck($r['base']['activeChampion']==='korath'&&$r['base']['champions']['korath']['level']===1,'Earned individual in cage');
        }
    }
    $p=Game::loadBase($id);$p['champions']['korath']['next_feed_at']=time()-1;Game::saveBase($id,$p);
    denied(fn()=>Game::mutate($id,'championFeed',[]));
    $p['infernoRealm']=InfernoRealm::initial(time());$p['infernoRealm']['army']['spurtz']=22;$p['infernoRealm']['army']['zagnoid']=12;Game::saveBase($id,$p);
    $r=Game::mutate($id,'championFeed',[]);$p=Game::loadBase($id);
    mcheck($r['champions']['korath']['feeds']===1&&$p['infernoRealm']['army']['spurtz']===2&&$p['infernoRealm']['army']['zagnoid']===2&&!isset($p['army']['spurtz']),'Inferno food debit and persistent cage progress');
    mcheck($r['base']['infernoFood']['spurtz']===2&&$r['base']['monsterMadness']['power']===3,'Private care snapshot shows the real pantry and power');
    // Exercise each shipped diet, including three separate bonus feeds and maintenance.
    for($evolution=1;$evolution<=5;++$evolution){
        $p=Game::loadBase($id);$c=&$p['champions']['korath'];$c['next_feed_at']=time()-1;
        $c['feeds']=Catalog::champions()['korath']['feedsRequired'][$evolution]-1;unset($c);
        $recipe=Game::championRecipe('korath',$evolution);
        $p['infernoRealm']['army']=array_replace(array_fill_keys(array_keys(MonsterExpansion::INFERNO_LOCKERS),0),$recipe);Game::saveBase($id,$p);
        $r=Game::mutate($id,'championFeed',[]);mcheck($r['champions']['korath']['level']===$evolution+1&&array_sum($r['base']['infernoFood'])===0,'Exact Inferno diet evolves Korath');
    }
    foreach([1,2,3,3] as $bonus){
        $p=Game::loadBase($id);$p['champions']['korath']['next_feed_at']=time()-1;$p['infernoRealm']['army']['kingwormzer']=3;Game::saveBase($id,$p);
        $r=Game::mutate($id,'championFeed',[]);mcheck($r['champions']['korath']['bonus_feeds']===$bonus&&$r['base']['infernoFood']['kingwormzer']===0,'Exact bonus diet pays at tier '.$bonus);
    }
    $pdo->rollBack();echo "Monster Madness database: $checks checks passed.\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
