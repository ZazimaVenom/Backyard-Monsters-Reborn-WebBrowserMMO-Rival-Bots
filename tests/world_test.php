<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function worldCheck(bool $condition,string $label):void{global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function worldReject(callable $work,string $label):void{try{$work();}catch(RuntimeException){worldCheck(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
for($q=0;$q<64;$q+=3){for($r=0;$r<64;$r+=3){
    worldCheck(World::distance($q,$r,$q,$r)===0,'hex identity');
    worldCheck(World::distance($q,$r,63-$q,63-$r)===World::distance(63-$q,63-$r,$q,$r),'hex symmetry');
    worldCheck(World::distance($q,$r,$q+1,$r)===1,'horizontal hex neighbor');
    worldCheck(World::distance($q,$r,$q,$r+1)===1,'vertical offset neighbor');
}}
worldCheck(World::distance(0,0,63,63)===95,'opposite finite map corners');
foreach(['resource'=>5,'stronghold'=>3] as $kind=>$max){for($level=1;$level<=$max;$level++){
    $stats=World::siteStats($kind,$level);$state=World::initialState($kind,$level,1000);
    worldCheck($stats['maxLevel']===$max,'level caps');worldCheck($stats['range']>0,'positive reach');
    foreach($state['buildings'] as $i=>$b){
        worldCheck($b['hp']===$b['max_hp']&&$b['hp']>0,'complete initial defenses');
        if($b['type']==='bunker'){worldCheck(Game::bunkerUsed($state,$b['id'])<=Game::bunkerCapacity($state,$b['id']),'neutral defenders fit bunker');}
        $size=Catalog::buildings()[$b['type']]['size'];
        foreach(array_slice($state['buildings'],$i+1) as $other){$gap=($size+Catalog::buildings()[$other['type']]['size'])/2+.15;worldCheck(abs($b['x']-$other['x'])>=$gap||abs($b['y']-$other['y'])>=$gap,'fixed layout avoids overlaps');}
    }
    worldCheck($state['activeChampion']===null&&$state['champions']===[],'site has no home champion');
}}
worldCheck(Catalog::buildings()['outpost']['untargetable']===true,'resource core untargetable');
worldCheck(Catalog::buildings()['stronghold']['damage']>0,'stronghold core defends');
worldCheck(Catalog::buildings()['maproom']['maxLevel']===3,'Map Room tiers');
worldReject(fn()=>World::siteStats('home',1),'non territory stats');worldReject(fn()=>World::siteStats('resource',6),'level overflow');
$base=World::initialState('resource',1,1000);$base['world']=['resourcePerSecond'=>.1,'capacity'=>2000,'holdings'=>1,'pending'=>[['ready_at'=>1050,'resourcePerSecond'=>.2,'capacity'=>4000]],'futureExtension'=>'preserved'];$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],0);
World::accrueIncome($base,1000,1100,2);
foreach($base['resources'] as $amount){worldCheck(abs($amount-30)<.00001,'all four incomes split exactly at upgrade');}
worldCheck($base['world']['capacity']===4000&&$base['world']['pending']===[],'upgrade cache advances once');
worldCheck(Game::normalizeBase($base)['world']['futureExtension']==='preserved','world extension preservation');
$before=$base['resources'];World::accrueIncome($base,1100,1100,1);worldCheck($base['resources']===$before,'zero elapsed income');
$base['world']['capacity']=0;$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],100000);World::accrueIncome($base,1100,1200,1);worldCheck($base['resources']['twig']===100000,'capacity loss preserves surplus');
$base['resources']['twig']=Game::capacity($base)-1;World::accrueIncome($base,1200,1300,1);worldCheck($base['resources']['twig']==Game::capacity($base),'income capped without overflow');
$site=['kind'=>'resource','level'=>1,'state'=>World::initialState('resource',1,1000)];$site['state']['ready_at']=1100;$site['state']['upgrade_to']=2;World::refreshSite($site,1099);worldCheck($site['level']===1,'upgrade cannot finish early');World::refreshSite($site,1100);worldCheck($site['level']===2&&$site['state']['ready_at']===0,'upgrade completes');$completed=$site;World::refreshSite($site,1100);worldCheck($completed===$site,'completion is idempotent');
$site['state']['buildings'][1]['hp']=0;$site['state']['repair_at']=1200;$cost=World::repairCost($site);worldCheck($cost['twig']>0&&$cost['twig']<=World::siteStats('resource',2)['repairCost']['twig'],'repair proportional quote');World::refreshSite($site,1200);worldCheck($site['state']['buildings'][1]['hp']===$site['state']['buildings'][1]['max_hp'],'paid repair clock restores defense');
echo "World pure tests passed: {$checks} assertions.\n";
