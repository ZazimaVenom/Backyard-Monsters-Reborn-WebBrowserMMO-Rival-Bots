<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function networkCheck(bool $condition,string $label):void{global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function networkReject(callable $work,string $label):void{try{$work();}catch(RuntimeException){networkCheck(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
function networkKeys(array $rows):array{$keys=array_map(fn($row)=>$row['q'].','.$row['r'],$rows);sort($keys);return $keys;}

networkCheck(networkKeys(World::neighbors(10,10))===['10,11','10,9','11,10','9,10','9,11','9,9'],'even-row six neighbors');
networkCheck(networkKeys(World::neighbors(10,11))===['10,10','10,12','11,10','11,11','11,12','9,11'],'odd-row six neighbors');
foreach([[0,0],[63,0],[0,63],[63,63],[32,32],[32,33]] as [$q,$r]){
    $neighbors=World::neighbors($q,$r);networkCheck(count($neighbors)<=6,'finite six-neighbor bound');
    foreach($neighbors as $neighbor){networkCheck(World::distance($q,$r,$neighbor['q'],$neighbor['r'])===1,'exact odd-row distance');networkCheck(in_array($q.','.$r,networkKeys(World::neighbors($neighbor['q'],$neighbor['r'])),true),'reciprocal neighbor geometry');}
}
$zero=['count'=>0,'reduction'=>0.0,'siteIds'=>[]];$ring=[];
foreach(World::neighbors(20,21) as $i=>$position){$ring[]=$position+['id'=>$i+11,'kind'=>'fortification','owner_id'=>7,'state'=>['repair_at'=>9999999999,'ready_at'=>9999999999,'buildings'=>[['hp'=>0]]]];}
foreach(['home','resource','stronghold'] as $kind){
    foreach([0.0,.07,.13,.23,.35,.50,.75] as $count=>$expected){$support=World::fortificationSupportFromSites(7,20,21,$kind,array_slice($ring,0,$count));networkCheck($support['count']===$count&&$support['reduction']===$expected&&count($support['siteIds'])===$count,'exact source reduction table for '.$kind);}
}
networkCheck(World::fortificationSupportFromSites(null,20,21,'resource',$ring)===$zero,'neutral territory gets no support');
networkCheck(World::fortificationSupportFromSites(0,20,21,'home',$ring)===$zero,'zero owner gets no support');
networkCheck(World::fortificationSupportFromSites(8,20,21,'home',$ring)===$zero,'another owner cannot use the network');
networkCheck(World::fortificationSupportFromSites(7,20,21,'fortification',$ring)===$zero,'Fortifications never protect Fortifications');
networkCheck(World::fortificationSupportFromSites(7,20,21,'unknown',$ring)===$zero,'unknown site kind gets no support');
$mixed=$ring;$mixed[0]['owner_id']=8;$mixed[1]['owner_id']=null;$mixed[2]['kind']='stronghold';$mixed[]=['id'=>99,'kind'=>'fortification','owner_id'=>7,'q'=>22,'r'=>21];
networkCheck(World::fortificationSupportFromSites(7,20,21,'home',$mixed)['count']===3,'ownership kind and exact distance independently filter support');
networkCheck(World::fortificationSupportFromSites(7,20,21,'home',array_merge($ring,$ring))['count']===6,'duplicate rows cannot exceed six cells');
networkCheck(World::fortificationSupportFromSites(7,20,21,'home',array_reverse($ring))['siteIds']===range(11,16),'stable public support IDs');

$occupied=[];
for($by=0;$by<8;$by++){for($bx=0;$bx<8;$bx++){foreach([['resource',1,1],['resource',5,2],['resource',2,5],['resource',6,6],['stronghold',4,4]] as [$kind,$dx,$dy]){$occupied[($bx*8+$dx).','.($by*8+$dy)]=['kind'=>$kind];}}}
$cursor=0;
for($i=0;$i<1000;$i++){do{$cell=($cursor*1031+2080)%4096;$key=($cell%64).','.intdiv($cell,64);++$cursor;}while(isset($occupied[$key]));$occupied[$key]=['kind'=>'home'];}
$before=$occupied;$positions=World::fortificationPositions($occupied);
networkCheck($occupied===$before,'migration plan does not mutate existing sites');
networkCheck(count($positions)===1270,'deterministic 1000-bot map gets 1270 neutral cells');
networkCheck(4096-count($occupied)-count($positions)===1506,'1000-bot map retains 1506 registration positions');
networkCheck(World::fortificationPositions(array_reverse($occupied,true))===$positions,'seeding independent of row fetch order');
networkCheck(count(networkKeys($positions))===count(array_unique(networkKeys($positions))),'shared parent rings deduplicate cells');
foreach($positions as $position){$key=$position['q'].','.$position['r'];networkCheck(!isset($occupied[$key]),'seeding preserves occupied home and territory coordinates');$parent=false;foreach(World::neighbors($position['q'],$position['r']) as $neighbor){if(in_array($occupied[$neighbor['q'].','.$neighbor['r']]['kind']??null,['resource','stronghold'],true)){$parent=true;}}networkCheck($parent,'every generated cell borders a territory anchor');}
networkCheck(World::fortificationPositions(['20,21'=>['kind'=>'home']])===[],'no automatic home ring added');
networkCheck(count(World::fortificationPositions($occupied,4096-count($occupied)-3))===3,'registration reserve strictly bounds insertion');
networkCheck(World::fortificationPositions($occupied,4096)===[],'crowded map admits zero safely');
networkCheck(World::fortificationPositions($occupied,4096-count($occupied)-3)===array_slice($positions,0,3),'capacity truncation deterministic');

$catalog=Catalog::buildings();$core=$catalog['outpostdefender'];
networkCheck($core['siteOnly']&&$core['originalId']===140&&!($core['untargetable']??false),'source core identity is targetable and site-only');
networkCheck(!isset($core['damage'],$core['range'])&&!$core['canFortify'],'core has no invented attack or individual fortification');
$fortifiable = array_keys(array_filter($catalog, static fn(array $item): bool => $item['canFortify']));
sort($fortifiable);
networkCheck($fortifiable === ['aerial','cannon','laser','railgun','sniper','storage','tesla','townhall'], 'all eight eligible home building types support individual fortification');
for($level=1;$level<=3;$level++){
    $stats=World::siteStats('fortification',$level);$state=World::initialState('fortification',$level,1000);
    networkCheck($stats['range']===0&&$stats['capacity']===0&&$stats['resourcePerSecond']===0.0&&$stats['damageBonus']===0.0,'network cells grant no reach economy or outgoing damage');
    networkCheck($stats['maxLevel']===3&&$stats['garrisonCapacity']===100*$level,'three adapted fixed-defense levels');
    networkCheck($state['buildings'][0]['type']==='outpostdefender'&&$state['buildings'][0]['hp']===Game::buildingStats('outpostdefender',$level)['max_hp'],'targetable core has scaled HP');
    networkCheck($state['resources']===array_fill_keys(['twig','pebble','putty','goo'],0),'neutral sites contain no free resource stockpile');
    foreach($state['buildings'] as $i=>$building){
        if($building['type']==='bunker'){networkCheck(Game::bunkerUsed($state,$building['id'])===Game::bunkerCapacity($state,$building['id']),'real neutral garrison fits its bunker');}
        foreach(array_slice($state['buildings'],$i+1) as $other){$gap=($catalog[$building['type']]['size']+$catalog[$other['type']]['size'])/2+.15;networkCheck(abs($building['x']-$other['x'])>=$gap||abs($building['y']-$other['y'])>=$gap,'fixed defenses do not overlap');}
    }
}
networkReject(fn()=>World::siteStats('fortification',0),'zero fort level');networkReject(fn()=>World::siteStats('fortification',4),'fort level overflow');
$site=['kind'=>'fortification','level'=>1,'state'=>World::initialState('fortification',1,1000)];$site['state']['ready_at']=1100;$site['state']['upgrade_to']=2;
World::refreshSite($site,1099);networkCheck($site['level']===1,'fort upgrade cannot complete early');World::refreshSite($site,1100);
networkCheck($site['level']===2&&$site['state']['buildings'][0]['hp']===6750,'fort upgrade completes core level with adapted HP');
$site['state']['buildings'][0]['hp']=0;$quote=World::repairCost($site);networkCheck($quote['twig']>0&&$quote['twig']<World::siteStats('fortification',2)['repairCost']['twig'],'fort repair quote charges damage proportion');
$site['state']['repair_at']=1200;World::refreshSite($site,1200);networkCheck($site['state']['buildings'][0]['hp']===6750,'fort timed repair restores core');
echo "Fortification network pure tests passed: {$checks} assertions.\n";
