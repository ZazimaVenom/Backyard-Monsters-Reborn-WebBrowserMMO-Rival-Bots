<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function verify(bool $condition,string $label):void{global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function reject(callable $work,string $label):void{try{$work();}catch(RuntimeException){verify(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
function fixtureBuilding(string $type,int $level=1,int $id=2):array{$hp=Game::buildingStats($type,$level)['max_hp'];return ['id'=>$id,'type'=>$type,'x'=>8,'y'=>8,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>17,'fortification'=>0,'fortify_ready_at'=>0,'fortify_to'=>null,'fortify_paid'=>null];}
function fixtureBase():array{return ['buildings'=>[fixtureBuilding('townhall',5,1)],'resources'=>array_fill_keys(['twig','pebble','putty','goo'],1000000),'army'=>['pokey'=>1],'research'=>['pokey'=>1],'queue'=>[],'last_tick'=>1000,'content_version'=>'0.5.0'];}
$source=json_decode(file_get_contents(__DIR__.'/../docs/ORIGINAL-CATALOG.json'),true,512,JSON_THROW_ON_ERROR);
$original=array_column(array_filter($source['buildings'],static fn(array $b):bool=>!empty($b['can_fortify'])),null,'id');
$eligibleIds=['storage'=>6,'townhall'=>14,'cannon'=>20,'sniper'=>21,'laser'=>23,'tesla'=>25,'aerial'=>115,'railgun'=>118];
verify(array_keys($original)===array_values($eligibleIds),'source marks exactly these eight structures as fortifiable');
$eligible=array_keys($eligibleIds);
foreach(Catalog::buildings() as $type=>$spec){verify($spec['canFortify']===in_array($type,$eligible,true),'exact source eligibility '.$type);}
foreach($eligible as $type){
    for($tier=1;$tier<=4;$tier++){
        $stats=Catalog::fortificationStats($type,$tier);$base=fixtureBase();$building=fixtureBuilding($type);$building['fortification']=$tier-1;$base['buildings'][]=$building;
        $status=Game::fortificationStatus($base,$building,1000,1);
        verify($status['available']&&$status['next']['tier']===$tier,'eligible paid next tier '.$type.' '.$tier);
        $raw=$original[$eligibleIds[$type]]['fortify_costs'][$tier-1];
        $gate=$type==='railgun'?5:$tier+1;
        verify($raw['re']===[[14,1,$tier+4]],'original Town Hall gate '.$type.' '.$tier);
        verify($stats['requiredTownHall']===$gate,'local completed Town Hall gate '.$type.' '.$tier);
        verify($stats['cost']===array_combine(['twig','pebble','putty','goo'],array_map(static fn($key)=>(int)ceil($raw[$key]/100),['r1','r2','r3','r4']))&&$stats['buildTime']===(int)ceil($raw['time']/300),'complete source price and timer '.$type.' '.$tier);
        verify($stats['reduction']===[0.2,0.3,0.4,0.5][$tier-1],'source damage reduction '.$type.' '.$tier);
        verify($stats['cost']['goo']===0&&$stats['cost']['twig']>0&&$stats['cost']['pebble']>0,'cost families '.$type.' '.$tier);
        $base['resources']['twig']=$stats['cost']['twig']-1;
        verify(!Game::fortificationStatus($base,$building,1000,1)['available'],'insufficient funds '.$type.' '.$tier);
        $base=fixtureBase();$base['buildings'][0]['level']=$gate-1;
        verify(!Game::fortificationStatus($base,$building,1000,1)['available'],'Town Hall below gate '.$type.' '.$tier);
    }
}
verify(Catalog::fortificationStats('storage',1)['cost']===['twig'=>594,'pebble'=>297,'putty'=>0,'goo'=>0],'fractional scaled source costs round up');
verify(Catalog::fortificationStats('cannon',1)['buildTime']===27,'source timer scaling');
verify(Catalog::fortificationStats('townhall',4)['cost']['twig']===100000,'final source price remains a substantial paid upgrade');
foreach(['wall','trap','heavytrap','bunker','stronghold','outpost','infernocannon','quaketower','magmatower','spurtzcannon','diamondspurtz'] as $type){reject(fn()=>Catalog::fortificationStats($type,1),'reject noneligible source type '.$type);}
reject(fn()=>Catalog::fortificationStats('cannon',0),'reject tier zero purchase');reject(fn()=>Catalog::fortificationStats('cannon',5),'reject fifth tier');
$base=fixtureBase();$tower=fixtureBuilding('cannon');
foreach([['level',0],['ready_at',1100],['hp',0],['hp',$tower['max_hp']-1],['fortification',4]] as [$key,$value]){$copy=$tower;$copy[$key]=$value;verify(!Game::fortificationStatus($base,$copy,1000,1)['available'],'work gate '.$key.' '.$value);}
$base['buildings'][0]['hp']=0;verify(!Game::fortificationStatus($base,$tower,1000,1)['available'],'dead Town Hall does not authorize a fortification');
$base=fixtureBase();$base['buildings'][0]['ready_at']=1100;verify(!Game::fortificationStatus($base,$tower,1000,1)['available'],'upgrading Town Hall does not authorize a fortification');
$base=fixtureBase();$tower['fortification']=1;$tower['fortify_to']=2;$tower['fortify_ready_at']=1100;$tower['fortify_paid']=Catalog::fortificationStats('cannon',2)['cost'];$tower['hp']-=123;$base['buildings'][]=$tower;
$status=Game::fortificationStatus($base,$tower,1000,1);verify($status['active']&&!$status['available']&&$status['reduction']===0.2,'pending next tier retains completed reduction');
verify($status['cancelRefund']['credited']===array_fill_keys(['twig','pebble','putty','goo'],0),'overcapacity cancellation preview cannot delete surplus');
verify($status['cancelRefund']['potential']===$tower['fortify_paid'],'refund preview uses originally paid cost');
$before=$base;Game::accrueFortifications($base,1099);verify($base===$before,'fortification does not complete early');
Game::accrueFortifications($base,1100);$completed=$base['buildings'][1];
verify($completed['fortification']===2&&$completed['fortify_ready_at']===0&&$completed['fortify_to']===null&&$completed['fortify_paid']===null,'timer completes exactly once');
foreach(['hp','max_hp','level','x','y','stored'] as $key){verify($completed[$key]===$tower[$key],'completion preserves '.$key);}
$once=$base;Game::accrueFortifications($base,1100);verify($base===$once,'repeat completion is idempotent');
$base['buildings'][1]['fortify_ready_at']=1200;$base['buildings'][1]['fortify_to']=3;$base['buildings'][1]['hp']=0;Game::accrueFortifications($base,1200);verify($base['buildings'][1]['hp']===0,'paid completion never revives a destroyed building');
$old=fixtureBase();unset($old['buildings'][0]['fortification'],$old['buildings'][0]['fortify_ready_at'],$old['buildings'][0]['fortify_to'],$old['buildings'][0]['fortify_paid']);$old['buildings'][0]['custom']='preserved';$old['champions']=['gorgo'=>['level'=>6,'hp'=>12345,'feeds'=>23,'bonusFeeds'=>3,'next_feed_at'=>2000,'last_heal_at'=>1000]];$old['activeChampion']='gorgo';
$new=Game::normalizeBase($old);verify($new['content_version']==='0.13.0','content advances to v0.13.0');verify(Game::normalizeBase($new)===$new,'normalization idempotent');
foreach($old['buildings'][0] as $key=>$value){verify($new['buildings'][0][$key]===$value,'migration retains old building field '.$key);}
verify($new['buildings'][0]['fortification']===0&&$new['buildings'][0]['fortify_to']===null,'migration grants no fortification');
verify($new['champions']['gorgo']['bonus_feeds']===0&&$new['champions']['gorgo']['bonusFeeds']===3,'legacy counter grants no bonus power');
echo "Fortification progression: $checks assertions passed.\n";
