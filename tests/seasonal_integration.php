<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$config=['database'=>getenv('MYSQL_TEST_DATABASE') ?: '','host'=>getenv('MYSQL_TEST_HOST') ?: '127.0.0.1',
    'port'=>(int)(getenv('MYSQL_TEST_PORT') ?: 3306),'username'=>getenv('MYSQL_TEST_USER') ?: 'root','password'=>getenv('MYSQL_TEST_PASSWORD') ?: '','game_speed'=>1];
if (PHP_SAPI!=='cli' || !str_ends_with($config['database'],'_seasonal_test')) { fwrite(STDERR,"Select an empty disposable database ending in _seasonal_test.\n"); exit(2); }
$db=Db::connect($config);Db::useConnection($db,$config);
if (($argv[1]??'')==='--claim') {
    echo json_encode(Game::mutate((int)$argv[2],'seasonalAction',['op'=>'giantOpen','id'=>(int)$argv[3]])['seasonResult'],JSON_THROW_ON_ERROR);
    exit;
}
if ($db->query('SHOW TABLES')->fetchColumn()!==false) {throw new RuntimeException('Select a new, empty seasonal test database.');}
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$checks=0;
function checkSeason(bool $value,string $message):void {global $checks;if (!$value)throw new RuntimeException($message);$checks++;}
function rejectSeason(callable $f,string $message):void {
    try {$f();} catch(RuntimeException $e) {checkSeason(str_contains(strtolower($e->getMessage()),$message),$e->getMessage());return;}
    throw new RuntimeException('Expected rejection '.$message);
}
function createSeason(string $name):int {
    $db=Db::pdo();$db->prepare('INSERT INTO users(name,created_at) VALUES(?,?)')->execute([$name,time()]);
    $id=(int)$db->lastInsertId();$base=Game::initialBase($id);
    $db->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($base,JSON_THROW_ON_ERROR),time()]);
    World::assignHome($id); return $id;
}
function objectOf(array $view,int $type):array {return array_values(array_filter($view['objects'],fn($o)=>$o['type']===$type));}
$id=createSeason('October keeper');$other=createSeason('Autumn visitor');
$base=Game::loadBase($id);$date=gmmktime(0,0,0,10,30,2030);
$blank=$base;
Seasonal::observe($blank,$date-1);
checkSeason(!isset($blank['seasonal']),'Ordinary low level legacy snapshot has no new saved fields.');
$bot=$base;$bot['bot_turns']=15;$before=$bot;Seasonal::observe($bot,$date);
checkSeason($before===$bot && Seasonal::view($bot,false,$date)['objects']===[],'Bots never gain human-only encounters or progress while idle.');
Seasonal::observe($base,$date);
$v=Seasonal::view($base,true,$date);$objects=objectOf($v,53);$giant=objectOf($v,54)[0];
checkSeason($v['calendarActive'] && !$v['replayActive'] && $v['until']===$date+259200,'Annual UTC three-day calendar');
checkSeason(count($objects)>=4 && count($objects)<=5 && count(array_unique(array_column($objects,'id')))===count($objects),'Four or five distinct daily spawns');
checkSeason(count(objectOf(Seasonal::view($base,false,$date),54))===1 && !isset(Seasonal::view($base,false,$date)['lastPick']),'Visitors see objects only');
checkSeason(!array_key_exists('resource',$objects[0]) && !array_key_exists('trick',$objects[0]),'Private predetermined outcome never exposed');
$again=$base;Seasonal::observe($again,$date+1);checkSeason($again===$base,'Daily refresh does not reroll objects');
$reserved=true;
$clear=true;
foreach ($objects as $obj) {
    $reserved=$reserved && Seasonal::overlaps($base,$obj['x'],$obj['y'],.8,$date);
    foreach ($base['buildings'] as $building) {
        $gap=(Catalog::buildings()[$building['type']]['size']+.65)/2+.15;
        if (abs($building['x']-$obj['x'])<$gap && abs($building['y']-$obj['y'])<$gap) { $clear=false; }
    }
}
checkSeason($reserved && $clear,'Each patch reserves clear terrain and leaves existing structures in place');
$next=$base;Seasonal::observe($next,$date+86400);
checkSeason(!array_intersect(array_column($objects,'id'),array_column(objectOf(Seasonal::view($next,true,$date+86400),53),'id')),'Rotten mini-pumpkins removed at 24h');
Seasonal::observe($next,$date+259200);
checkSeason(Seasonal::view($next,true,$date+259200)['objects']===[],'Calendar closes at exact UTC boundary');
$base['decorations'][]=['id'=>1,'item'=>111,'receipt'=>'previous-decoration'];
$claim=Seasonal::act($base,['op'=>'giantOpen','id'=>$giant['id']],$date);
checkSeason($claim['decorations']===[110=>1] && $base['shiny']===100,'Existing one mini decoration suppresses duplicate type bundle');
checkSeason(Seasonal::act($base,['op'=>'giantOpen','id'=>$giant['id']],$date)['alreadyClaimed']===true,'Giant is claim-once');
$replayAt=$date+10*86400;
Seasonal::act($base,['op'=>'halloweenReplay'],$replayAt);
checkSeason(!objectOf(Seasonal::view($base,true,$replayAt),54) && Seasonal::view($base,true,$replayAt)['bagClaimed'],'Same calendar and replay cannot pay twice');
$final=$replayAt+3*86400;
rejectSeason(fn()=>Seasonal::act($base,['op'=>'halloweenReplay'],$final),'finished');
$year2=gmmktime(0,0,0,10,30,2031);Seasonal::observe($base,$year2);
$second=Seasonal::act($base,['op'=>'giantOpen','id'=>203154],$year2);
checkSeason($second['shiny']===100 && $second['decorations']===[] && $base['shiny']===200,'Next year allows another 100 Shiny while preserving owned ornaments');
rejectSeason(fn()=>Seasonal::act($base,['op'=>'giantOpen','id'=>203054],$year2),'this halloween');

// Put two deterministic scheduled jobs through the same persistent mutation and reload routes as the browser.
$r=Game::mutate($id,'seasonalAction',['op'=>'halloweenReplay']);$v=$r['base']['seasonal'];
$bag=objectOf($v,54)[0];$children=[];
for($i=0;$i<2;$i++) {
    $process=proc_open([PHP_BINARY,__FILE__,'--claim',(string)$id,(string)$bag['id']],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $children[]=[$process,$pipes];
}
$rewards=0;$duplicates=0;
foreach($children as [$process,$pipes]) {
    $text=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    checkSeason(proc_close($process)===0,'Concurrent bag: '.$stderr);$body=json_decode($text,true,512,JSON_THROW_ON_ERROR);$rewards+=$body['shiny'];$duplicates+=(int)$body['alreadyClaimed'];
}
$saved=Game::loadBase($id);
checkSeason($rewards===100 && $duplicates===1 && $saved['shiny']===100 && count($saved['decorations'])===7,'Concurrent requests paid a single 100-Shiny, seven-ornament bag');
checkSeason(Game::snapshot($id)['base']['seasonal']['bagClaimed'],'Bag disappears across reload');
$public=Game::visit($id,$other)['seasonal'];checkSeason(array_keys($public)===['objects'] && !isset($public['objects'][0]['readyAt']),'Visitor cannot see treasure or work state');
$mini=$saved['seasonal']['pumpkins'][0]['id'];
$saved['resources']['putty']=0;$saved['workers']=1;$saved['seasonal']['pumpkins'][0]['trick']=false;
$saved['seasonal']['pumpkins'][0]['resource']='putty';$saved['seasonal']['pumpkins'][0]['bp']=750;
Game::saveBase($id,$saved);
$pick=Game::mutate($id,'seasonalAction',['op'=>'pumpkinPick','id'=>$mini])['seasonResult'];
$retry=Game::mutate($id,'seasonalAction',['op'=>'pumpkinPick','id'=>$mini])['seasonResult'];
checkSeason($retry===$pick && $retry['kind']==='picking','Worker reservation and retry are idempotent');
$saved=Game::loadBase($id);
checkSeason(Construction::status($saved,time())['available']===0,'Pumpkin and construction use a shared worker');
rejectSeason(fn()=>Game::mutate($id,'seasonalAction',['op'=>'pumpkinPick','id'=>$saved['seasonal']['pumpkins'][1]['id']]),'workers');
$saved['seasonal']['pumpkins'][0]['readyAt']=time()-1;Game::saveBase($id,$saved);
$treated=Game::snapshot($id)['base']['seasonal']['lastPick'];
$saved=Game::loadBase($id);
checkSeason($treated['kind']==='treat' && $treated['amount']===intdiv(Game::capacity($saved)*750,10000) && (int)$saved['resources']['putty']===$treated['amount'],'Boosted treat credits exactly once');
Game::snapshot($id);checkSeason((int)Game::loadBase($id)['resources']['putty']===$treated['amount'],'No extra payment on page reload');
$saved['seasonal']['pumpkins'][0]['trick']=true;$saved['seasonal']['pumpkins'][0]['readyAt']=time()-1;
$hp=array_column($saved['buildings'],'hp','id');Game::saveBase($id,$saved);
checkSeason(Game::snapshot($id)['base']['seasonal']['lastPick']['kind']==='trick' && array_column(Game::loadBase($id)['buildings'],'hp','id')==$hp,'Twig trick leaves no lasting building damage');
checkSeason(Game::mutate($id,'seasonalAction',['op'=>'pumpkinPick','id'=>$mini])['seasonResult']['kind']==='gone','Late harvest retry remains harmless');
Game::mutate($other,'seasonalAction',['op'=>'halloweenReplay']);
rejectSeason(fn()=>Game::mutate($other,'seasonalAction',['op'=>'pumpkinPick','id'=>PHP_INT_MAX]),'choose a pumpkin');

$saved=Game::loadBase($id);$view=Seasonal::view($saved,true);$threshold=$view['horse']['points'];
Seasonal::credit($saved,2000000-$threshold);Seasonal::observe($saved,time());
checkSeason(!Seasonal::view($saved,true)['horse']['encountered'],'Horse requires strictly more than 2,000,000 points');
Seasonal::credit($saved,1);Game::saveBase($id,$saved);$horse=Game::snapshot($id)['base']['seasonal'];
checkSeason(count(objectOf($horse,27))===1 && $horse['horse']['points']===2000001 && $horse['horse']['level']>=9,'Source point/level threshold spawns one northern encounter');
$request=['requestId'=>'open-trojan-horse-01','previousId'=>null];$session=BaiterService::run($id,'horseStart',$request);
checkSeason($session['session']['horse'] && $session['battle']['horse'] && array_sum($session['battle']['wave'])===51 && !isset($session['battle']['wave']['eyera']),'51 troops and no Eye-ra');
checkSeason(!objectOf(Game::snapshot($id)['base']['seasonal'],27) && BaiterService::run($id,'horseStart',$request)['session']['id']===$session['session']['id'],'Horse opens once; dropped response resumes same session');
$stored=Game::loadBase($id);$battle=&$stored['baiterPractice']['state'];
checkSeason($battle['horseWave']['power']===.4 && $battle['horseWave']['groups'][0]==[0,'pokey',1],'Original weak tier and first arrival');
$battle['launch_at']=time()-70;
for ($i=0;$i<26 && $battle['status']==='active';$i++) { Baiter::advance($battle,time()); }
checkSeason($battle['unitSeq']===51,'Engine releases all 51 actual combat units after elapsed time');
$first=$battle['units'][0];$template=BattleService::makeUnit('pokey',1,'t','attacker',1,1);
checkSeason($first['max_hp']===(int)($template['max_hp']*.4) && $first['_damage']===(int)($template['_damage']*.4),'Trojan power changes unit health and damage');
unset($battle);$fresh=Game::loadBase($id);$homeId=$fresh['buildings'][0]['id'];$homeHp=$fresh['buildings'][0]['hp'];
foreach($fresh['baiterPractice']['state']['buildings'] as &$tower){if ($tower['id']===$homeId)$tower['hp']-=45;}unset($tower);Game::saveBase($id,$fresh);
$end=BaiterService::run($id,'baiterSiren',['id'=>$session['session']['id']]);
checkSeason($end['battle']['result']['outcome']==='dismissed' && Game::loadBase($id)['buildings'][0]['hp']==$homeHp-45,'Siren settles real defense damage');
BaiterService::run($id,'baiterPoll',['id'=>$session['session']['id']]);
checkSeason(Game::loadBase($id)['buildings'][0]['hp']==$homeHp-45,'Repeated poll cannot apply damage twice');
rejectSeason(fn()=>BaiterService::run($id,'horseStart',['requestId'=>'second-horse-request','previousId'=>$session['session']['id']]),'no unopened');
checkSeason(Game::mutate($id,'repairBase',[])['repairResult']['repaired']>=1 && Game::loadBase($id)['buildings'][0]['hp']==$homeHp,'Free repair button restores horse damage');
echo "$checks seasonal checks passed.\n";
