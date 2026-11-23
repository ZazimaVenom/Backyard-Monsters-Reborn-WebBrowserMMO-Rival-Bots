<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !str_ends_with((string)getenv('MYSQL_TEST_DATABASE'), '_upperhall_test')) {
    fwrite(STDERR, "Select an empty disposable MYSQL_TEST_DATABASE ending in _upperhall_test.\n");
    exit(2);
}
require_once __DIR__.'/../app/bootstrap.php';
$config = ['host'=>getenv('MYSQL_TEST_HOST') ?: '127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT') ?: 3306),
    'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER') ?: 'root',
    'password'=>getenv('MYSQL_TEST_PASSWORD') ?: '','game_speed'=>1];
$pdo = Db::connect($config);
Db::useConnection($pdo, $config);
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) {
    throw new RuntimeException('Use an empty disposable database.');
}

$checks = 0;
function checkHall(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks;
}
function expectRejection(callable $action, string $reason): void
{
    try { $action(); }
    catch (RuntimeException $error) {
        checkHall(str_contains($error->getMessage(), $reason), 'Unexpected rejection: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Missing rejection: '.$reason);
}
function addKeeper(string $name): int
{
    $pdo = Db::pdo();
    $pdo->prepare('INSERT INTO users(name,created_at) VALUES(?,?)')->execute([$name,time()]);
    $id = (int)$pdo->lastInsertId();
    $base = Game::initialBase($id);
    Mushrooms::ensure($base,$id,time());
    $base['mushrooms']['items']=[];
    $base['resources'] = array_fill_keys(['twig','pebble','putty','goo'], 160000);
    $base['army']['dave'] = 1;
    $base['shield_until'] = 0;
    $pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')
        ->execute([$id,json_encode($base, JSON_THROW_ON_ERROR),time()]);
    World::assignHome($id);
    return $id;
}
function addFixtureBuilding(array &$yard,string $type,float $x,float $y,int $level=1):int
{
    $id=$yard['next_building_id']++;$hp=Game::buildingStats($type,$level)['max_hp'];
    $yard['buildings'][]=compact('id','type','x','y','level','hp')+['max_hp'=>$hp,'ready_at'=>0,'stored'=>0,'upgrade_to'=>null];
    return $id;
}
$pdo->beginTransaction();
try{
    $id=addKeeper('Upper Hall Keeper');$yard=Game::loadBase($id);$hall=&$yard['buildings'][0];
    $hall['level']=5;$hall['hp']=$hall['max_hp']=Game::buildingStats('townhall',5)['max_hp'];
    $hallId=$hall['id'];unset($hall);$yard['shiny']=8000;
    addFixtureBuilding($yard,'storage',5,14,4);addFixtureBuilding($yard,'storage',24,27,4);
    addFixtureBuilding($yard,'generalstore',28,5);
    Game::saveBase($id,$yard);$cap=Game::capacity($yard);
    $tiers=Catalog::buildings()['townhall']['levels'];
    checkHall($cap===270000 && InfernoRealm::catalog()['townhall']['maxLevel']===6,'Highest costs fit two unboosted level-four Silos. The Under Hall retains its own cap.');
    for($old=1;$old<=5;++$old){
        checkHall(Game::buildingStats('townhall',$old)['max_hp']===(int)round(4800*pow(1.35,$old-1)),'Existing Town Hall health is preserved.');
        if($old<5)checkHall(Game::upgradeCost('townhall',$old)['twig']===(int)ceil(2400*pow(1.85,$old)),'Purchased classic levels keep their original prices.');
    }
    foreach($tiers as $level=>$tier){
        $b=Game::loadBase($id);
        $before=$b['buildings'][0];
        $b['resources']=array_fill_keys(['twig','pebble','putty','goo'],$cap);Game::saveBase($id,$b);
        $start=time();$home=Game::mutate($id,'upgrade',['id'=>$hallId])['base'];$pending=$home['buildings'][0];
        checkHall($pending['level']===$level-1&&$pending['upgrade_to']===$level&&$pending['hp']===$before['hp'],'An upgrade keeps the saved completed health and level until due.');
        checkHall($pending['ready_at']>=$start+$tier['buildTime']&&$pending['ready_at']<=time()+$tier['buildTime'],'Queue deadline uses source seconds divided by 300.');
        checkHall($home['resources']['twig']===$cap-$tier['cost']['twig'] && $home['resources']['pebble']===$cap-$tier['cost']['pebble']
            && $home['resources']['putty']===$cap && $home['resources']['goo']===$cap,'Charge twigs and pebbles once with the home locked.');
        expectRejection(fn()=>Game::mutate($id,'upgrade',['id'=>$hallId]),'under construction');
        $key='ready_at.'.$hallId.'.'.$pending['ready_at'];$job=((array)$home['utilities']['jobs'])[$key];
        $completed=Game::mutate($id,'yardPurchase',['kind'=>'time','key'=>$key,'price'=>$job['price'],'revision'=>$home['utilities']['revision']])['base'];
        checkHall($completed['buildings'][0]['level']===$level && $completed['buildings'][0]['hp']===$tier['hp'] && $completed['buildings'][0]['max_hp']===$tier['hp'],'Finishing via Store executes the normal upgrade completion once.');
        $damaged=Game::loadBase($id);$damaged['buildings'][0]['hp']-=1300;Game::saveBase($id,$damaged);
        if($level<10)expectRejection(fn()=>Game::mutate($id,'upgrade',['id'=>$hallId]),'Repair');
        $repaired=Game::mutate($id,'repairBase',[])['base'];
        checkHall($repaired['buildings'][0]['hp']===$tier['hp']&&$repaired['resources']===$completed['resources'],'Free repair after damage restores fully upgraded HP.');
    }
    expectRejection(fn()=>Game::mutate($id,'upgrade',['id'=>$hallId]),'highest level');
    $native=Game::buildingStats('townhall',6,true)['max_hp'];
    checkHall($native===(int)round(4800*pow(1.35,5)) && $native!==$tiers[6]['hp'],'Existing Inferno level-six HP is independent of surface upgrades.');
    $catalog=Catalog::buildings();foreach($catalog as $spec){
        if(!empty($spec['siteOnly']) || !empty($spec['brukkargReward']))continue;
        $last=TownHall::quantity($spec,5);
        foreach(range(6,10) as $lvl){
            $count=TownHall::quantity($spec,$lvl);checkHall($count>=$last,$spec['name'].' retains or increases its building allowance.');$last=$count;
        }
    }
    $desired=Bots::buildPlan('guardian',8);
    checkHall($desired['railgun']===3&&$desired['storage']>=3&&$desired['twig']>=4,'Late bots plan enough storage and production along with unlocked defenses.');
    $botId=addKeeper('High Hall Bot');$pdo->prepare('UPDATE users SET is_bot=1 WHERE id=?')->execute([$botId]);
    $b=Game::loadBase($botId);$b['buildings'][0]['level']=9;$b['buildings'][0]['hp']=$b['buildings'][0]['max_hp']=$tiers[9]['hp'];
    $b['resources']=array_fill_keys(['twig','pebble','putty','goo'],270000);
    addFixtureBuilding($b,'storage',5,14,4);addFixtureBuilding($b,'storage',24,27,4);addFixtureBuilding($b,'locker',27,5,5);
    $b['unlocked']=array_keys(Catalog::homeMonsters());$b['workers']=1;$b['ai']['turns']=2;$b['ai']['last_attack_at']=time();
    Game::saveBase($botId,$b);
    // Run the normal bot decision and its paid mutation. World-clock dispatch has its own concurrency suite.
    $turn=(new ReflectionMethod(Bots::class,'takeTurn'))->invoke(null,$botId,161,Bots::settings(),[],0,$botId);
    $new=Game::loadBase($botId);
    checkHall($turn['upgraded']===1 && $new['buildings'][0]['upgrade_to']===10 && $new['buildings'][0]['level']===9,'A persistent late-game bot starts a real timed upgrade to level ten.');
    checkHall($new['resources']['twig']<=$b['resources']['twig']-250000,'The bot pays the same final-tier price.');
    $attacker=addKeeper('High Hall Raider');$fight=BattleService::start($attacker,$id);
    $building=array_values(array_filter($fight['buildings'],fn($v)=>$v['type']==='townhall'))[0];
    checkHall($building['level']===10 && $building['max_hp']===$tiers[10]['hp'],'A ranked invasion freezes level-ten defenses at the correct HP.');
    BattleService::retreat($attacker,$fight['id']);
    echo "Upper Town Hall progression: $checks checks passed.\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();}
