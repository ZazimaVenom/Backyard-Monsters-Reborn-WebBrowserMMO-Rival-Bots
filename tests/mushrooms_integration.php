<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$config=['database'=>getenv('MYSQL_TEST_DATABASE') ?: '', 'host'=>getenv('MYSQL_TEST_HOST') ?: '127.0.0.1',
    'port'=>(int)(getenv('MYSQL_TEST_PORT') ?: 3306), 'username'=>getenv('MYSQL_TEST_USER') ?: 'root',
    'password'=>getenv('MYSQL_TEST_PASSWORD') ?: '', 'game_speed'=>1];
if (PHP_SAPI!=='cli' || !str_ends_with($config['database'],'_mushrooms_test')) {
    fwrite(STDERR,"Select an empty disposable MYSQL_TEST_DATABASE ending in _mushrooms_test.\n");exit(2);
}
$pdo=Db::connect($config); Db::useConnection($pdo,$config);
if (($argv[1]??'')==='--pick') {
    $receipt=Game::mutate((int)$argv[2],'mushroomPick',['id'=>(int)$argv[3]])['mushroomPick'];
    echo json_encode($receipt,JSON_THROW_ON_ERROR);
    exit;
}
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0) { throw new RuntimeException('Choose an empty mushroom test world.'); }
$checks=0;
function expectMushroom(bool $condition,string $message):void {
    global $checks;if(!$condition){throw new RuntimeException($message);}++$checks;
}
function blockedMushroom(callable $action,string $why):void {
    try {$action();} catch(RuntimeException $e){expectMushroom(str_contains(strtolower($e->getMessage()),$why),$e->getMessage());return;}
    throw new RuntimeException('Expected rejection: '.$why);
}
function mushroomRace(int $id,int $target):array {
    $pdo=Db::pdo(); $children=[];
    $pdo->beginTransaction();Game::loadBase($id,true);
    try {
        for ($i=0;$i<2;$i++) {
            $p=proc_open([PHP_BINARY,__FILE__,'--pick',(string)$id,(string)$target],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($p)){throw new RuntimeException('Could not start pick process.');}
            $children[]=[$p,$pipes];
        }
        $pdo->commit();
        $results=[];
        foreach ($children as [$process,$pipes]) {
            $text=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);
            expectMushroom(proc_close($process)===0,'Child pick failed '.$error);
            $results[]=json_decode($text,true,512,JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {if($pdo->inTransaction())$pdo->rollBack();}
}
function createMushroomKeeper(string $name):int {
    $db=Db::pdo();$db->prepare('INSERT INTO users(name,created_at) VALUES(?,?)')->execute([$name,time()]);$id=(int)$db->lastInsertId();
    $base=Game::initialBase($id);
    $db->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($base,JSON_THROW_ON_ERROR),time()]);
    World::assignHome($id);return $id;
}
$id=createMushroomKeeper('Home Forager');$rival=createMushroomKeeper('Visitor');
$home=Game::snapshot($id)['base'];$patch=$home['mushrooms'];$items=$patch['items'];
expectMushroom(count($items)===20 && count(array_unique(array_column($items,'id')))===20,'20 unique starter mushrooms');
expectMushroom($patch['picked']===0 && $home['shiny']===0,'Legacy wallet and quest counters start empty');
expectMushroom(Game::snapshot($id)['base']['mushrooms']==$patch,'Reload preserves every starter position');
$visit=Game::visit($id,$rival);
expectMushroom(count($visit['mushrooms']['items'])===20 && !array_key_exists('lastPick',$visit['mushrooms']) && !array_key_exists('shiny',$visit) && !array_key_exists('payout',$visit['mushrooms']['items'][0]) && !array_key_exists('payout',$items[0]) && !array_key_exists('readyAt',$visit['mushrooms']['items'][0]),'Visiting does not reveal private harvest state');
$first=$items[0];
$building=$home['buildings'][0];
$footprint=Catalog::buildings()[$building['type']]['size']/2;
$obstacle=array_values(array_filter($items,static fn($item)=>min($item['x'],$item['y'],32-$item['x'],32-$item['y'])>=1+$footprint))[0];
blockedMushroom(fn()=>Game::mutate($id,'move',['id'=>$building['id'],'x'=>$obstacle['x'],'y'=>$obstacle['y']]),'mushroom');
blockedMushroom(fn()=>Game::mutate($id,'mushroomPick',['id'=>10000000]),'choose');
$pair=mushroomRace($id,$first['id']);
expectMushroom($pair[0]===$pair[1] && $pair[0]['status']==='picking','Competing picks reserve one shared deadline');
expectMushroom(Game::snapshot($id)['base']['construction']['busy']===1,'One worker occupied');
blockedMushroom(fn()=>Game::mutate($id,'mushroomPick',['id'=>$items[1]['id']]),'busy');
$base=Game::loadBase($id);$base['mushrooms']['items'][0]['readyAt']=time()-1;Game::saveBase($id,$base);
$duplicate=mushroomRace($id,$first['id']);
expectMushroom($duplicate[0]['status']==='picked' && $duplicate[1]['status']==='picked','Competing settlement retries are idempotent');
$after=Game::snapshot($id)['base'];$receipt=$after['mushrooms']['lastPick'];
expectMushroom(count($after['mushrooms']['items'])===19 && $after['mushrooms']['picked']===1 && $receipt['id']===$first['id'],'One harvested out of 20');
expectMushroom(in_array($receipt['shiny'],[0,3,8],true) && $after['shiny']===$receipt['shiny'] && $after['mushrooms']['golden']===($receipt['shiny']?1:0),'Single authoritative reward');
expectMushroom($after['construction']['available']===1 && Game::user($id)['rank_points']===0,'Worker returned and league rank unchanged');
expectMushroom(Game::snapshot($rival)['base']['mushrooms']['picked']===0,'Rival yard isolated');
foreach ([3,8,0] as $reward) {
    $base=Game::loadBase($id);$beforeShiny=(int)($base['shiny']??0);$beforeGold=$base['mushrooms']['golden'];
    $base['mushrooms']['items'][0]['payout']=$reward;
    $base['mushrooms']['items'][0]['readyAt']=time()-1;
    Game::saveBase($id,$base);
    $reloaded=Game::snapshot($id)['base'];
    expectMushroom($reloaded['mushrooms']['lastPick']['shiny']===$reward && $reloaded['shiny']===$beforeShiny+$reward && $reloaded['mushrooms']['golden']===$beforeGold+($reward?1:0),'Golden and ordinary finds are correctly credited');
}
$base=Game::loadBase($id);$now=time();
$base['mushrooms']['items']=array_slice($base['mushrooms']['items'],0,5);
$base['mushrooms']['growthAt']=$now-2*Mushrooms::INTERVAL-89;Game::saveBase($id,$base);
$grown=Game::snapshot($id)['base']['mushrooms'];
expectMushroom(count($grown['items'])===7 && abs($grown['nextGrowthAt']-($now+Mushrooms::INTERVAL-89))<=1,'Offline growth keeps its interval remainder');
$base=Game::loadBase($id);$base['mushrooms']['growthAt']=$now-90*Mushrooms::INTERVAL;Game::saveBase($id,$base);
expectMushroom(count(Game::snapshot($id)['base']['mushrooms']['items'])===10,'Long offline growth stops at ten');
$base=Game::loadBase($id);$base['mushrooms']['picked']=200;$base['mushrooms']['golden']=50;Game::saveBase($id,$base);
$quests=array_column(Game::snapshot($id)['base']['quests']['entries'],null,'id');
foreach(['M1','M2','M3','M4','M5','M6'] as $quest){expectMushroom($quests[$quest]['status']==='ready','Eligible original quest '.$quest);}
$claim=Game::mutate($id,'questClaim',['quest'=>'M1'])['questClaim'];
expectMushroom($claim['resources']===['twig'=>100,'pebble'=>100,'putty'=>50,'goo'=>50],'Local economy-scaled source quest award');
expectMushroom(Game::mutate($id,'questClaim',['quest'=>'M1'])['questClaim']['alreadyClaimed'],'Quest reward once');
$base['mushrooms']['growthAt']=100;$base['mushrooms']['items'][0]['readyAt']=110;
$base['_activity_clock']=['epoch'=>10,'pausedSeconds'=>1];
WorldActivity::shiftBase($base,['epoch'=>10,'pausedSeconds'=>71]);
expectMushroom($base['mushrooms']['growthAt']===170 && $base['mushrooms']['items'][0]['readyAt']===180,'Paused bot time shifts growth and worker pick together');
echo "Mushroom database integration: $checks checks passed.\n";
