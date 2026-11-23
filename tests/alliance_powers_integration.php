<?php
declare(strict_types=1);

require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/ActivityFixture.php';

$name=getenv('MYSQL_TEST_DATABASE')?:'';
if(PHP_SAPI!=='cli'||!preg_match('/^[a-z0-9_]+_powers_test$/iD',$name)) {
    fwrite(STDERR,"Select an empty MYSQL_TEST_DATABASE ending in _powers_test.\n");exit(2);
}
$config=['database'=>$name,'host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),
    'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$db=Db::connect($config);Db::useConnection($db,$config);
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if($db->query('SELECT id FROM users LIMIT 1')->fetchColumn())throw new RuntimeException('Use a fresh world.');
$checks=0;
function checkPower(bool $yes,string $label):void{
    global $checks;if(!$yes)throw new RuntimeException($label);++$checks;
}
function rejectPower(callable $action,string $part):void{
    try{$action();}catch(PDOException $e){throw $e;}catch(RuntimeException $e){checkPower(str_contains($e->getMessage(),$part),$e->getMessage());return;}
    throw new RuntimeException('Expected rejection of '.$part);
}
function powerUser(string $name):int{
    $id=(int)Game::register($name,'disposable-power-fixture')['id'];
    $yard=Game::loadBase($id);$yard['shield_until']=0;$yard['shiny']=100;$yard['army']['pokey']=20;
    $yard['buildings']=[];
    foreach(['townhall','flinger','wall','sniper','trap','maproom'] as $i=>$type){
        $yard['buildings'][]=['id'=>$i+1,'type'=>$type,'x'=>6+($i%3)*7,'y'=>6+intdiv($i,3)*7,'level'=>1,'hp'=>$type==='sniper'?300:500,
            'max_hp'=>500,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
    }
    Game::saveBase($id,$yard);return $id;
}
function setPowerClock(int $group,string $type,int $end,bool $active=false):void{
    $db=Db::pdo();$name='alliance-power-'.$group;
    $q=$db->prepare('SELECT value FROM settings WHERE name=?');$q->execute([$name]);$rows=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $rows[$type]['end']=$end;$rows[$type]['active']=$active;
    $db->prepare('UPDATE settings SET value=? WHERE name=?')->execute([json_encode($rows,JSON_THROW_ON_ERROR),$name]);
}
function powerOrder(int $group,string $type,string $op,int $rev,int $hours=1):array{
    return compact('op','type','hours')+['allianceId'=>$group,'powerRevision'=>$rev,'requestId'=>bin2hex(random_bytes(12))];
}
function savedPowerBattle(int $id):array{
    $q=Db::pdo()->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);return json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
}

World::initialize($db);
$a=powerUser('Alder Leader');$b=powerUser('Oak Leader');$m=powerUser('Larch Keeper');$bot=powerUser('Ash Captain');
$ag=Alliances::manage($a,['op'=>'create','name'=>'Alder pack'])['mine'];
$bg=Alliances::manage($b,['op'=>'create','name'=>'Oak pack'])['mine'];
$cg=Alliances::manage($bot,['op'=>'create','name'=>'Ash pack'])['mine'];
Alliances::manage($a,['op'=>'invite','allianceId'=>$ag,'keeperId'=>$m]);
$offer=Alliances::browse($m,[])['inbox'][0];
Alliances::manage($m,['op'=>'accept','allianceId'=>$ag,'keeperId'=>$m,'requestId'=>(int)$offer['id']]);

$board=Alliances::browse($m,['allianceId'=>$ag])['powers'];
checkPower(count($board['rows'])===3&&!$board['leader']&&$board['price']===20&&$board['shiny']===100,'Members see their balance and three timers.');
foreach(['armament'=>[43200,604800],'conquest'=>[21600,432000],'declarewar'=>[43200,604800]] as $key=>[$run,$charge]){
    $r=$board['rows'][$key];checkPower($r['run']===$run&&$r['recharge']===$charge&&$r['status']==='charging'&&$r['hours']===$charge/3600,'Fresh charging duration for '.$key);
}
checkPower(Alliances::browse($b,['allianceId'=>$ag])['powers']===null,'Only members see power controls.');
$order=powerOrder($ag,'armament','powerCharge',0,2);
rejectPower(fn()=>Alliances::manage($b,$order),'Join this alliance');
rejectPower(fn()=>Alliances::manage($m,powerOrder($ag,'armament','powerActivate',0)),'current alliance leader');
rejectPower(fn()=>Alliances::manage($a,powerOrder($ag,'armament','powerActivate',0)),'still charging');
rejectPower(fn()=>Alliances::manage($m,powerOrder($ag,'armament','powerCharge',0,6)),'more Shiny');
$paid=Alliances::manage($m,$order)['powers'];$changed=$paid['rows']['armament'];
checkPower($paid['shiny']===60&&$changed['revision']===1&&$changed['end']===$board['rows']['armament']['end']-7200&&$changed['last']['by']==='Larch Keeper','Real member pays two hours from personal Shiny.');
checkPower(Alliances::manage($m,$order)['powers']['shiny']===60,'Exact replay charges once.');
rejectPower(fn()=>Alliances::manage($m,powerOrder($ag,'armament','powerCharge',0)),'changed');
rejectPower(fn()=>Alliances::manage($m,array_merge($order,['hours'=>1])),'different power-up order');
setPowerClock($ag,'armament',time()+30);
$ready=Alliances::manage($m,powerOrder($ag,'armament','powerCharge',1,10))['powers'];
checkPower($ready['shiny']===40&&$ready['rows']['armament']['status']==='ready'&&$ready['rows']['armament']['last']['cost']===20,'Only the remaining partial hour is billed.');
rejectPower(fn()=>Alliances::manage($m,powerOrder($ag,'armament','powerCharge',2)),'fully charged');
$running=Alliances::manage($a,powerOrder($ag,'armament','powerActivate',2))['powers'];
$end=$running['rows']['armament']['end'];
checkPower($running['rows']['armament']['status']==='running'&&abs($end-time()-43200)<=1,'Leader activates a full twelve-hour window.');
rejectPower(fn()=>Alliances::manage($m,powerOrder($ag,'armament','powerCharge',3)),'already running');
checkPower(AlliancePowers::board($db,$m,$ag,$end+3600)['rows']['armament']['end']===$end+604800,'Recharge anchored to activation expiry.');
checkPower(AlliancePowers::board($db,$m,$ag,$end+604801)['rows']['armament']['status']==='ready','Old timers become ready while world is offline.');
checkPower(!AlliancePowers::active($db,$m,$end),'Expired powers ignored by new matches.');

$home=World::home($b);$distant=['q'=>$home['q']+($home['q']>31?-12:12),'r'=>$home['r']];
checkPower(!World::reachable($b,$distant),'Twelve cells outside ordinary home reach.');
setPowerClock($bg,'declarewar',time()-1);setPowerClock($bg,'conquest',time()-1);
Alliances::manage($b,powerOrder($bg,'declarewar','powerActivate',0));
Alliances::manage($b,powerOrder($bg,'conquest','powerActivate',0));
checkPower(World::reachable($b,$distant)&&World::map($b,['radius'=>1])['home']['range']===12,'Power extends legal reach and map range by two cells.');
checkPower(AlliancePowers::cost(0,true)===0&&AlliancePowers::cost(7,true)===6,'Conquest preserves free captures and rounds paid fees up.');
$site=World::candidates($b,2,911,'resource')[0];
$siteAttack=BattleService::startSite($b,(int)$site['id']);
checkPower($siteAttack['alliancePowers']['captureCost']===0,'Real neutral territory charge is zero.');
BattleService::retreat($b,$siteAttack['id']);
$war=Alliances::manage($a,['op'=>'warOffer','allianceId'=>$ag,'otherId'=>$bg])['diplomacy']['wars'][0]['id'];
Alliances::manage($b,['op'=>'warAccept','allianceId'=>$bg,'otherId'=>$ag,'warId'=>(int)$war]);
$inv=BattleService::start($b,$m);$id=$inv['id'];$s=savedPowerBattle($id);$bs=array_column($s['buildings'],null,'type');
checkPower($inv['alliancePowers']['defense']['armament']===$end&&$inv['duration']===420&&$inv['flinger']['capacity']===312&&$s['allianceWar']['id']==$war,'Both Declare War paths stack once; Armament follows membership.');
checkPower($bs['townhall']['hp']===500&&$bs['sniper']['max_hp']===750&&$bs['sniper']['hp']===450&&$bs['wall']['hp']===750,'Armored normal/canonical full and wounded health.');
checkPower($bs['trap']['_damage']===1500&&$bs['trap']['hp']===500,'Armed booby trap boosts explosion only.');
setPowerClock($ag,'armament',time()-1,true);setPowerClock($bg,'declarewar',time()-1,true);
$polled=BattleService::poll($m,$id);
checkPower($polled['alliancePowers']===$inv['alliancePowers']&&$polled['duration']===420,'Expiry leaves an existing invasion frozen.');
BattleService::deploy($b,$id,'pokey',2,2);
$s=savedPowerBattle($id);
foreach($s['buildings'] as &$building)if($building['type']==='sniper')$building['hp']=300;
unset($building);$db->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($s,JSON_THROW_ON_ERROR),$id]);
$done=BattleService::retreat($b,$id);
$after=array_column(Game::loadBase($m)['buildings'],null,'type');
checkPower($done['result']['alliancePowers']===$inv['alliancePowers']&&$after['sniper']['hp']==200&&$after['sniper']['max_hp']==500,'Real settlement translates damaged temporary armor to normal health.');
BattleService::retreat($b,$id);checkPower(array_column(Game::loadBase($m)['buildings'],null,'type')['sniper']['hp']==200,'Repeating settlement cannot apply duplicate wounds.');
checkPower(!World::reachable($b,$distant),'World returns to original range on expiry.');

$local=Game::loadBase($m);$local['_allianceArmamentEnd']=time()+200;
$practice=Baiter::defenseState($local,Game::user($m),['pokey'=>1],'t',time(),0.0,[]);
$practice['blitz']=['stage'=>1];$practice['result']=['outcome'=>'breached'];
foreach($practice['buildings'] as &$building)if($building['type']==='sniper')$building['hp']-=75;
unset($building);MonsterBlitz::settle($local,$practice);
checkPower(array_column($local['buildings'],null,'type')['sniper']['hp']==150,'Wild event settlement honors temporary armor scale.');

$db->prepare('UPDATE users SET is_bot=1 WHERE id=?')->execute([$bot]);
$db->prepare('UPDATE alliances SET bot_charter=3 WHERE id=?')->execute([$cg]);
setPowerClock($cg,'conquest',time()-1);
AlliancePowers::botTurn($bot);
checkPower(!AlliancePowers::active($db,$bot,time()),'Bot charter waits when no players are online.');
activityFixtureWake($a);AlliancePowers::botTurn($bot);
checkPower(isset(AlliancePowers::active($db,$bot,time())['conquest'])&&Game::loadBase($bot)['shiny']===100,'A real online charter leader activates a free, naturally charged power.');
echo "$checks alliance power integration checks passed.\n";
