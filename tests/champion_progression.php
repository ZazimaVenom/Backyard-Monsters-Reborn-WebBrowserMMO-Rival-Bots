<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function verify(bool $condition,string $label):void{global $checks;$checks++;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function buildingsPreserved(array $old,array $new):bool{if(count($old)!==count($new)){return false;}foreach($old as $i=>$building){if(array_intersect_key($new[$i],$building)!==$building||$new[$i]['fortification']!==0||$new[$i]['fortify_ready_at']!==0||$new[$i]['fortify_to']!==null||$new[$i]['fortify_paid']!==null){return false;}}return true;}
$old=['buildings'=>[['id'=>1,'type'=>'townhall','level'=>4,'hp'=>6000,'max_hp'=>6000,'x'=>16,'y'=>16,'ready_at'=>0]],'resources'=>['twig'=>1234,'pebble'=>987,'goo'=>6543,'putty'=>42],'army'=>['pokey'=>17,'octo'=>3,'fink'=>2,'ichi'=>1,'bandito'=>4,'dave'=>0],'research'=>['pokey'=>3,'octo'=>2,'fink'=>1,'ichi'=>1,'bandito'=>1,'dave'=>1],'queue'=>[],'researchQueue'=>[],'unlocked'=>['pokey','octo','fink','ichi','bandito','dave','bolt'],'unlockQueue'=>[['type'=>'eyera','ready_at'=>9999999999]],'garrison'=>['8'=>['octo'=>5]],'last_tick'=>10000,'shield_until'=>8888,'next_building_id'=>77,'next_queue_id'=>99,'content_version'=>'0.2.0','custom_extension'=>['x'=>'must survive']];
$b=Game::normalizeBase($old);
verify(Game::normalizeBase($b)===$b,'content normalization is idempotent');
foreach(['buildings','resources','queue','researchQueue','unlocked','unlockQueue','garrison','last_tick','shield_until','next_building_id','next_queue_id','custom_extension'] as $key){verify($key==='buildings'?buildingsPreserved($old[$key],$b[$key]):$old[$key]===$b[$key],'preserve existing '.$key);}
verify($b['champions']===[]&&$b['activeChampion']===null,'migration never grants champion');
verify($b['content_version']==='0.13.1','content version is 0.13.1');
foreach(['brain','crabatron'] as $type){verify($b['army'][$type]===0&&!in_array($type,$b['unlocked'],true),'new species locked with no free inventory '.$type);}
$champions=Catalog::champions();verify(!array_diff(['gorgo','drull','fomor'],array_keys($champions)),'three foundation champions available');
verify(count(Catalog::homeMonsters())>=15&&!isset(Catalog::monsters()['gorgo']),'champions separate from ordinary army catalog');
foreach($champions as $type=>$spec){
    verify(count($spec['levels'])===6,'six evolution levels '.$type);
    verify($spec['feedRecipes'][6]===$spec['feedRecipes'][5],'maintenance meal uses last recipe '.$type);
    verify($spec['feedsRequired']===[1=>3,2=>6,3=>9,4=>12,5=>15],'source feeding thresholds '.$type);
    foreach($spec['feedRecipes'] as $recipe){foreach($recipe as $monster=>$count){verify(isset(Catalog::monsters()[$monster])&&$count>0,'recipe uses playable species '.$type.'/'.$monster);}}
    verify(Catalog::championStats($type,6)['hp']>Catalog::championStats($type,1)['hp'],'evolution increases health '.$type);
}
verify(Catalog::championStats('fomor',2)['movement']==='ground'&&Catalog::championStats('fomor',3)['movement']==='air','Fomor begins flying at evolution3');
verify(Catalog::championStats('fomor',6)['auraBuff']===0.6&&Catalog::championStats('fomor',6)['auraRadius']===6.0,'Fomor aura magnitude and radius');
verify(Catalog::championStats('drull',1)['targetMode']==='ground'&&Catalog::championStats('fomor',1)['targetMode']==='both','ground melee and ranged anti-air');
$b['champions']=['gorgo'=>['level'=>1,'hp'=>0,'feeds'=>2,'next_feed_at'=>10000,'last_heal_at'=>10000,'last_hunger_at'=>10000,'battle_id'=>0,'frozen'=>false,'paused_feed_seconds'=>0,'bonus_feeds'=>0,'extension'=>'preserve']];$b['activeChampion']='gorgo';
verify(Game::normalizeBase($b)===$b,'champion extension and exact values preserved');
Game::accrueChampions($b,10090,1);verify(abs($b['champions']['gorgo']['hp']-4000)<0.001,'half recovery interval restores half health');
$once=$b;Game::accrueChampions($b,10090,1);verify($b===$once,'repeat same clock never heals twice');
Game::accrueChampions($b,10180,1);verify($b['champions']['gorgo']['hp']===8000.0,'recovery caps at full health');
$once=$b;Game::accrueChampions($b,10000,1);verify($b===$once,'clock regression does not subtract health or reset timer');
$b['champions']['gorgo']['hp']=0;$b['champions']['gorgo']['last_heal_at']=20000;Game::accrueChampions($b,20045,2);verify(abs($b['champions']['gorgo']['hp']-4000)<0.001,'configured speed scales recovery');
$b['champions']['gorgo']['battle_id']=77;$once=$b;Game::accrueChampions($b,999999,20);verify($b===$once,'battle locked champion receives no passive recovery or hunger updates');
$b['champions']['gorgo']['battle_id']=0;$b['champions']['gorgo']['frozen']=true;$once=$b;Game::accrueChampions($b,999999,20);verify($b===$once,'frozen clocks remain paused');
$b['champions']['gorgo']['frozen']=false;$b['champions']['gorgo']['next_feed_at']=50000;$b['champions']['gorgo']['last_hunger_at']=50000;$b['champions']['gorgo']['feeds']=2;
Game::accrueChampions($b,50000+86399,1);verify($b['champions']['gorgo']['feeds']===2,'one day hunger grace protects progress');
Game::accrueChampions($b,50000+86400,1);verify($b['champions']['gorgo']['feeds']===1,'first missed day removes one feed');
$once=$b;Game::accrueChampions($b,50000+86400,1);verify($b===$once,'repeat hunger step does not charge twice');
Game::accrueChampions($b,50000+86400*20,1);verify($b['champions']['gorgo']['feeds']===0&&$b['champions']['gorgo']['level']===1,'long absence clamps feeds without losing evolution');
verify(Game::readyChampion($b)===null,'combat readiness requires living cage');
$b['buildings'][]=['id'=>2,'type'=>'championcage','level'=>1,'hp'=>6000,'max_hp'=>6000,'x'=>4,'y'=>4,'ready_at'=>0];
$ready=Game::readyChampion($b);verify($ready!==null&&$ready['type']==='gorgo'&&$ready['max_hp']===8000,'combat readiness returns catalog maxhealth');
$b['buildings'][1]['hp']=0;verify(Game::readyChampion($b)===null,'destroyed cage blocks champion deployment');$b['buildings'][1]['hp']=6000;$b['buildings'][1]['ready_at']=time()+300;verify(Game::readyChampion($b)===null,'unfinished cage blocks champion deployment');
$b['buildings'][1]['ready_at']=0;$b['champions']['gorgo']['battle_id']=77;verify(Game::readyChampion($b)===null,'already locked champion is not available twice');
echo "Champion progression: $checks assertions passed.\n";
