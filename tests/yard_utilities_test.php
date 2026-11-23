<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

(new ReflectionProperty(Db::class,'configuration'))->setValue(null,['game_speed'=>1,'player_grace_seconds'=>0]);
$checks=0;
function expectUtility(bool $condition,string $message):void
{
    global $checks;
    if(!$condition){throw new RuntimeException($message);}++$checks;
}
function utilityReject(callable $fn,string $reason):void
{
    try{$fn();}catch(RuntimeException $e){expectUtility(str_contains($e->getMessage(),$reason),$e->getMessage());return;}
    throw new RuntimeException('Expected rejection '.$reason);
}
function utilityBuilding(string $type,int $id,int $lvl,int $ready=0):array
{
    $hp=Game::buildingStats($type,max(1,$lvl))['max_hp'];
    return ['type'=>$type,'id'=>$id,'level'=>$lvl,'x'=>5,'y'=>5,'hp'=>$lvl?$hp:0,'max_hp'=>$hp,'ready_at'=>$ready,
        'upgrade_to'=>$ready?$lvl+1:null,'stored'=>0,'fortification'=>0];
}
function buyUtil(array &$base,string $kind,string $key,array $offer,int $now):void
{
    $request=['kind'=>$kind,'key'=>$key,'price'=>$offer['price'],'amount'=>$offer['amount']??0,'revision'=>$base['storeRevision']??0];
    YardUtilities::purchase($base,$request,$now);
}

$now=time();$b=Game::initialBase(1);$b['shiny']=500;$b['buildings'][]=utilityBuilding('generalstore',84,1);
$b['buildings'][]=utilityBuilding('hatcherycontrol',85,1);
$b['buildings'][]=utilityBuilding('storage',86,1,$now+1800);
$b['buildings'][]=utilityBuilding('hatchery',87,2);$b['buildings'][]=utilityBuilding('hatchery',88,2);
$b['buildings'][1]['level']=2;
expectUtility(YardUtilities::view($b,$now)['qualifyingHatcheries']===3,'HCC requires three distinct working level-two hatcheries');
expectUtility(YardUtilities::timePrice(300)===0 && YardUtilities::timePrice(301)===2 && YardUtilities::timePrice(1800)===10,'Original Shiny time-price curve');
$u=YardUtilities::view($b,$now);$job=(array)$u['jobs'];$key=array_key_first($job);
buyUtil($b,'time',$key,$job[$key],$now);
expectUtility($b['shiny']===490 && $b['buildings'][count($b['buildings'])-3]['level']===2,'Worker completion uses the paid current job and accrues normal tier and HP');
$replay=['kind'=>'time','key'=>$key,'price'=>10,'revision'=>0];
utilityReject(function()use(&$b,$replay,$now){YardUtilities::purchase($b,$replay,$now);},'purchase changed');
$u=YardUtilities::view($b,$now);$offers=(array)$u['offers'];$before=$b['resources']['twig'];$offer=$offers['twig.10'];
buyUtil($b,'delivery','twig.10',$offer,$now);
expectUtility($b['resources']['twig']===$before+$offer['amount'] && $b['shiny']===490-$offer['price'],'Resource delivery charges exact frozen offer once');
$b['shiny']=0;$copy=$b;utilityReject(function()use(&$b,$offer,$now){buyUtil($b,'delivery','twig.10',$offer,$now);},'more Shiny');
expectUtility($b===$copy,'Insufficient currency leaves balance, inventory and revision intact');
$b['shiny']=100;
$b['queue']=[
    ['id'=>1,'type'=>'pokey','lane'=>87,'quantity'=>1,'duration'=>20,'ready_at'=>$now+12],
    ['id'=>2,'type'=>'octo','lane'=>87,'quantity'=>1,'duration'=>30,'ready_at'=>$now+42],
    ['id'=>3,'type'=>'fink','lane'=>88,'quantity'=>1,'duration'=>60,'ready_at'=>$now+55],
    ['id'=>4,'type'=>'pokey','lane'=>99,'quantity'=>1,'duration'=>20,'ready_at'=>$now+20],
];
$q=YardUtilities::view($b,$now)['hatch'];
expectUtility($q['quantity']===3 && $q['lanes']===3 && $q['price']===4,'HCC quotes active lane work once, excluding unavailable hatcheries');
$army=$b['army'];buyUtil($b,'hatch',$q['key'],$q,$now);
expectUtility($b['shiny']===96 && array_column($b['queue'],'id')===[4] && $b['army']['pokey']===$army['pokey']+1 && $b['army']['octo']===$army['octo']+1 && $b['army']['fink']===$army['fink']+1,'HCC clears and credits paid orders on each working lane');
$u=YardUtilities::view($b,$now);expectUtility($u['hatch']['quantity']===0,'HCC does not consume orders from offline lane');
$c=Catalog::buildings()['stonewall'];expectUtility($c['hp']===3600 && $c['maxLevel']===1 && $c['cost']['pebble']===20 && Catalog::isWall('stonewall'),'Stone Block uses original health and wall targeting');
$wall=utilityBuilding('stonewall',6,1);$wall['_initial_hp']=$wall['hp'];$wall['hp']=0;
$hall=utilityBuilding('townhall',1,1);
expectUtility(BattleEngine::destruction(['buildings'=>[$wall,$hall]])===0.0 && BattleEngine::captureProgress(['buildings'=>[$wall,$hall]])===0.0,'Breach stone without earning destruction or capture credit');
echo "Yard utility rules: $checks checks passed.\n";
