<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !str_ends_with((string)getenv('MYSQL_TEST_DATABASE'), '_powershop_test')) {
    fwrite(STDERR, "Select an empty disposable MYSQL_TEST_DATABASE ending in _powershop_test.\n");
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
function expectStore(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks;
}
function expectRejection(callable $action, string $reason): void
{
    try { $action(); }
    catch (RuntimeException $error) {
        expectStore(str_contains($error->getMessage(), $reason), 'Unexpected rejection: '.$error->getMessage());
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
function priceOffer(int $id,string $code):array
{
    $view=Game::snapshot($id)['base']['utilities'];$offer=((array)$view['powers'])[$code];
    return ['kind'=>'power','key'=>$code,'price'=>$offer['price'],'revision'=>$view['revision']];
}
function placeOrder(int $id,string $code):array
{
    return Game::mutate($id,'yardPurchase',priceOffer($id,$code))['base'];
}

$pdo->beginTransaction();
try{
    $now=time();$id=addKeeper('Store Keeper');$peer=addKeeper('Store Defender');
    foreach([$id,$peer] as $keeper){
        $b=Game::loadBase($keeper);$b['shiny']=9500;$b['buildings'][0]['level']=5;
        $b['buildings'][2]['level']=4;$b['infernoRealm']=InfernoRealm::initial($now);
        addFixtureBuilding($b,'generalstore',4,12);
        $bunker=addFixtureBuilding($b,'bunker',4,16,3);
        addFixtureBuilding($b,'championcage',4,24);
        addFixtureBuilding($b,'locker',9,27,3);
        Game::saveBase($keeper,$b);
    }
    $view=Game::visit($id,$peer);
    expectStore(!isset($view['utilities'],$view['shiny']),'A visitor cannot read the wallet or owner quotes.');
    $receipt=priceOffer($id,'HOD3');$before=Game::loadBase($id);
    $first=Game::mutate($id,'yardPurchase',$receipt)['base'];
    expectStore($first['shiny']===9400,'Real purchase debits earned Shiny once.');
    expectRejection(fn()=>Game::mutate($id,'yardPurchase',$receipt),'purchase changed');
    expectRejection(fn()=>placeOrder($id,'HOD'),'Already active');
    $queued=Game::mutate($id,'train',['type'=>'octo','quantity'=>2])['queue'];
    $work=(int)Catalog::monsters()['octo']['trainTime'];
    expectStore(count($queued)===2 && $queued[0]['duration']===(int)ceil($work/10) && $queued[1]['started_at']===$queued[0]['ready_at'],'Hatchery builds new discounted jobs on the same lane.');
    $remaining=Game::mutate($id,'cancelTrain',['id'=>$queued[0]['id']])['queue'];
    expectStore(count($remaining)===1 && $remaining[0]['ready_at']<=$queued[1]['ready_at'],'Cancelling an accelerated order closes the lane gap.');
    placeOrder($id,'CLOD');
    $research=Game::mutate($id,'unlock',['type'=>'bolt'])['unlockQueue'][0];
    expectStore($research['ready_at']<=time()+(int)ceil(Catalog::monsters()['bolt']['unlockTime']/4),'New Locker study is accelerated.');
    placeOrder($id,'BST');$yard=Game::loadBase($id);$victim=$yard['buildings'][2];
    $up=Game::mutate($id,'upgrade',['id'=>$victim['id']])['base']['buildings'][2];
    $maxSeconds=(int)ceil(Catalog::buildings()['housing']['buildTime']*pow(1.6,$victim['level'])*.8);
    expectStore($up['ready_at']>time() && $up['ready_at']<=time()+$maxSeconds,'Sharper tools influences an ordinary worker upgrade.');
    $b=Game::loadBase($id);$due=time()+8000;$b['buildings'][2]['ready_at']=$due;Game::saveBase($id,$b);
    $s=Game::snapshot($id)['base']['utilities'];
    $cut=['kind'=>'twohours','key'=>'ready_at.'.$victim['id'].'.'.$due,'price'=>40,'revision'=>$s['revision']];
    $short=Game::mutate($id,'yardPurchase',$cut)['base'];
    expectStore($short['buildings'][2]['ready_at']===$due-7200,'Two-hour worker adjustment removes exactly 7,200 seconds.');
    expectRejection(fn()=>Game::mutate($id,'yardPurchase',$cut),'purchase changed');
    $raised=Game::mutate($id,'championRaise',['type'=>'gorgo']);$army=$raised['army'];
    $fed=placeOrder($id,'IFD.gorgo.1.0.0');
    expectStore(Game::loadBase($id)['champions']['gorgo']['feeds']===1 && $fed['army']===$army,'Shiny feeding bypasses digestion and reserve-unit costs.');
    $evolved=placeOrder($id,'IEV.gorgo.1.1.0');
    expectStore(Game::loadBase($id)['champions']['gorgo']['level']===2 && $fed['shiny']-$evolved['shiny']===104,'Immediate evolution prices the remaining meals and persists.');
    $b=Game::loadBase($id);$b['champions']['gorgo']['hp']=2;$b['champions']['gorgo']['last_heal_at']=time();Game::saveBase($id,$b);
    placeOrder($id,'IHE.gorgo.2.0.0');
    expectStore(Game::loadBase($id)['champions']['gorgo']['hp']===Catalog::championStats('gorgo',2)['hp'],'Instant recovery restores the active champion to full health.');
    $s=Game::snapshot($id)['base']['utilities'];$old=Game::loadBase($id)['shiny'];
    $garrison=['kind'=>'bunker','key'=>$bunker.'.spurtz','revision'=>$s['revision'],'price'=>6,'amount'=>2];
    $delivered=Game::mutate($id,'yardPurchase',$garrison)['base'];
    expectStore($delivered['garrison'][$bunker]['spurtz']===2 && $delivered['shiny']===$old-6,'Cavern bunker defenders cost their source tariff on the surface.');
    $restored=Game::mutate($id,'ungarrison',['id'=>$bunker,'type'=>'spurtz','quantity'=>1]);
    expectStore($restored['army']['spurtz']===1,'Purchased native bunker units can transfer to housing.');
    placeOrder($id,'HOD3I');
    $b=Game::loadBase($id);$r=$b['infernoRealm'];
    $native=InfernoRealm::act($id,['action'=>'infernoTrain','type'=>'spurtz','quantity'=>1,'requestId'=>'overdrive_order_native_01',
        'realmRevision'=>$r['revision'],'monsterRevision'=>$r['monsterRevision']]);
    $egg=$native['monsters']['queue'][0];
    expectStore($egg['duration']===StorePowers::workTime($r,$egg['start'],$egg['work']??$egg['duration']),'Native Incubator uses its own purchased multiplier.');
    $old=Game::loadBase($id)['infernoRealm']['resources']['magma'];$s=Game::snapshot($id)['base']['utilities'];
    $offer=((array)$s['offers'])['magma.10'];
    Game::mutate($id,'yardPurchase',['kind'=>'delivery','key'=>'magma.10','revision'=>$s['revision']]+$offer);
    expectStore(Game::loadBase($id)['infernoRealm']['resources']['magma']===$old+$offer['amount'],'Inferno delivery reaches only the existing native resource bank.');
    // Expansion leaves all original logical coordinates stable and becomes visible to a visitor and defender.
    $original=Planner::positions(Game::loadBase($peer));
    foreach([36,40,44] as $size){$yard=placeOrder($peer,'ENL');expectStore($yard['yardExtent']===$size,'Expansion increases the actual defender extent.');}
    expectStore($original===Planner::positions(Game::loadBase($peer)) && Game::visit($peer,$id)['yardExtent']===44,'Existing slots, occupants and the visiting scene retain yard coordinates.');
    $new=Game::mutate($peer,'build',['type'=>'wall','x'=>-4,'y'=>16])['base'];
    $outerWall=end($new['buildings']);
    expectStore($outerWall['type']==='wall' && $outerWall['x']===-4.0,'Construct on formerly inaccessible land.');
    $layout=Planner::positions(Game::loadBase($peer));
    expectStore(count(Planner::validateLayout(Game::loadBase($peer),$layout))===count($layout),'Saved layouts accept an outer-ring structure.');
    expectRejection(fn()=>Game::mutate($id,'move',['id'=>1,'x'=>-4,'y'=>16]),'yard boundary');
    placeOrder($id,'ENLI');placeOrder($id,'ENLI');
    $native=Game::loadBase($id)['infernoRealm'];$sniper=end($native['buildings']);
    $nativeView=InfernoRealm::act($id,['action'=>'move','id'=>$sniper['id'],'x'=>0,'y'=>18,'realmRevision'=>$native['revision'],'requestId'=>'more_yard_native_move01']);
    expectStore($nativeView['base']['yardExtent']===40 && end($nativeView['base']['buildings'])['x']==0 && Game::loadBase($id)['infernoRealm']['yardageLevel']===2,'Native expansion has independently priced tiers and legal outer-ring operations.');
    placeOrder($id,'PRO1');expectRejection(fn()=>BattleService::start($peer,$id),'protected');
    foreach(['MOD','MSOD','MDOD'] as $k)placeOrder($id,$k);
    placeOrder($peer,'TOD');$fight=BattleService::start($id,$peer);
    expectStore(Game::loadBase($id)['shield_until']===0,'Attacking another keeper removes purchased protection.');
    $fightId=$fight['id'];$saved=$pdo->query('SELECT state_json FROM battles WHERE id='.(int)$fightId)->fetchColumn();
    $snapshot=json_decode($saved,true,512,JSON_THROW_ON_ERROR);
    expectStore(isset($snapshot['_storePowers']['attacker']['MOD'],$snapshot['_storePowers']['defender']['TOD']) && !isset($fight['_storePowers']),'Both owners’ active effects travel into the real ranked battle.');
    expectStore($fight['yardExtent']===44 && YardSpace::extent($snapshot)===44,'Ranked combat freezes the defender’s expanded area.');
    $deployed=BattleService::deploy($id,$fightId,'octo',-4.0,8.0);
    expectStore($deployed['units'][0]['x']<0,'Ranked Flinger accepts a legal landing on purchased land.');
    expectRejection(fn()=>placeOrder($id,'BIP'),'involved in a battle');
    BattleService::retreat($id,$fightId);
    echo "Store purchases, combat and persistence: $checks checks passed.\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();}
