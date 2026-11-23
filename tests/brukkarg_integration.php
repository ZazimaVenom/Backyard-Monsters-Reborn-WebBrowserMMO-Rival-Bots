<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$config=['database'=>getenv('MYSQL_TEST_DATABASE')?:'','host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),
    'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(PHP_SAPI!=='cli' || !str_ends_with($config['database'],'_brukkarg_test')){fwrite(STDERR,"Choose a disposable MYSQL_TEST_DATABASE ending in _brukkarg_test.\n");exit(2);}
$db=Db::connect($config);Db::useConnection($db,$config);
if($db->query('SHOW TABLES')->fetch()){throw new RuntimeException('The Brukkarg test requires a new schema.');}
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$n=0;
function bc(bool $value,string $why):void {global $n;if(!$value)throw new RuntimeException($why);++$n;}
function denyWar(callable $fn,string $word):void{try{$fn();}catch(RuntimeException $e){bc(str_contains($e->getMessage(),$word),$e->getMessage());return;}throw new RuntimeException('Expected denial '.$word);}
function startWar(int $owner,int $stage,?int $previous,string $key):array{return BattleService::startTribe($owner,$stage,['previousId'=>$previous,'requestId'=>str_pad($key,20,'x')]);}
function woundCamp(int $id,bool $hall):void{
    $db=Db::pdo();$q=$db->prepare('SELECT state_json FROM battles WHERE id=?');$q->execute([$id]);$s=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    foreach($s['buildings'] as &$b){if(($b['type']==='townhall')===$hall){$b['hp']=0;}}unset($b);
    $db->prepare('UPDATE battles SET state_json=? WHERE id=?')->execute([json_encode($s,JSON_THROW_ON_ERROR),$id]);
}
$owner=Game::register('Brukkarg keeper','war-test-password')['id'];$other=Game::register('Brukkarg rival','war-test-password')['id'];
$base=Game::loadBase($owner);
bc(Brukkarg::nextWave($base)===null && count(Tribes::listing($owner)['brukkarg']['camps'])===5,'Town Hall level gate');
denyWar(fn()=>Tribes::visit($owner,901),'level-five');
foreach($base['buildings'] as &$b){if($b['type']==='townhall'){$b['level']=5;$b['hp']=$b['max_hp']=Game::buildingStats('townhall',5)['max_hp'];}}unset($b);
Game::saveBase($owner,$base);$previous=null;
for($stage=901;$stage<=905;$stage++){
    $base=Game::loadBase($owner);
    denyWar(fn()=>Tribes::visit($owner,$stage),'five waves');
    for($w=($stage-901)*5+1;$w<=($stage-900)*5;$w++){
        bc(Brukkarg::nextWave($base)===$w && Brukkarg::defended($base,$w),'Only next defense wave awards progress');
        bc(!Brukkarg::defended($base,$w),'Replay cannot award a defense point');
        if($w===24){
            bc(Brukkarg::score($base)===4024 && isset($base['brukkargWar']['mirror']['buildings']),'Copy unlocks at 4024');
            $base['buildings'][0]['x']+=.25;
            bc($base['brukkargWar']['mirror']['buildings'][0]['x']!==$base['buildings'][0]['x'],'Copy is not a live alias');
        }
    }
    Game::saveBase($owner,$base);
    bc(Brukkarg::nextWave($base)===null,'Must attack after five waves');
    $scout=Tribes::visit($owner,$stage);
    bc($scout['tribe']['event'] && $scout['tribe']['stage']===$stage && count($scout['buildings'])>4,'Scout returns event layout');
    if($stage===905){bc($scout['buildings'][0]['x']==$base['brukkargWar']['mirror']['buildings'][0]['x'],'Final camp uses private immutable copy');}
    if ($stage===901){
        $block=$base;$block['baiterPractice']=['state'=>['status'=>'active']];Game::saveBase($owner,$block);
        denyWar(fn()=>startWar($owner,$stage,$previous,'blocked'),'home defense');
        Game::saveBase($owner,$base);
        $attempt=startWar($owner,$stage,$previous,'failed');$previous=$attempt['id'];
        woundCamp($previous,false);
        $loss=BattleService::retreat($owner,$previous);
        bc(!$loss['result']['attackerWon'] && $loss['result']['destruction']>=50,'A standing Hall holds despite 50% destruction');
        $held=Tribes::visit($owner,$stage);
        bc(isset($held['tribe']['damageUntil']) && count(array_filter($held['buildings'],fn($b)=>$b['hp']===0))>0,'Failed attack persists camp damage');
        $temp=Game::loadBase($owner);
        $after=Brukkarg::camp($temp,$stage,$held['tribe']['damageUntil']);
        bc(!isset($after['damageUntil']) && !array_filter($after['eventYard']['buildings'],fn($b)=>$b['hp']<=0),'Camp returns to full health after 24h');
    }
    $old=$previous;$fight=startWar($owner,$stage,$old,'wave-'.$stage);$previous=$fight['id'];
    bc(startWar($owner,$stage,$old,'wave-'.$stage)['id']===$previous,'Retry returns one battle');
    bc($fight['target']['event']==='brukkarg' && $fight['target']['sourceId']===$stage,'Event target uses its original source ID');
    if($stage===905){BattleService::deploy($owner,$previous,['pokey'=>1],1,1);}
    woundCamp($previous,true);
    $win=BattleService::retreat($owner,$previous);
    bc($win['result']['attackerWon'] && $win['result']['campaign']['firstClear'],'Hall destroyed yields actual settlement');
    bc(startWar($owner,$stage,$old,'wave-'.$stage)['result']['campaign']==$win['result']['campaign'],'Late retry returns same receipt');
    denyWar(fn()=>startWar($owner,$stage,$previous,'retry-'.$stage),'already cleared');
    $rank=SpurtzRewards::status(Game::loadBase($owner));
    bc($rank['cleared']===$stage-900 && $rank['limit']===[0,0,1,2,2][$stage-901],'Source cannon threshold');
}
$final=Tribes::listing($owner);
bc($final['cleared']===0 && $final['brukkarg']['score']===5025 && $final['brukkarg']['cleared']===5 && $final['brukkarg']['nextWave']===null,'Dedicated permanent event completion');
bc(Game::user($owner)['rank_points']===0 && Game::user($owner)['wins']===0,'Event leaves shared rankings unchanged');
bc(Tribes::listing($other)['brukkarg']['score']===0,'Other keeper has an independent war');
bc(!isset(Game::visit($owner,$other)['brukkargWar']),'Visitors cannot inspect private war details');
echo "Brukkarg expedition: $n checks passed.\n";
