<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function verify(bool $condition,string $label):void{global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function near(float $a,float $b,string $label):void{verify(abs($a-$b)<0.00001,$label);}
function championBase(string $type='gorgo',int $bonus=3,int $level=6):array{return ['activeChampion'=>$type,'champions'=>[$type=>['level'=>$level,'bonus_feeds'=>$bonus,'hp'=>100,'feeds'=>2,'next_feed_at'=>1000,'last_hunger_at'=>1000,'last_heal_at'=>1000,'battle_id'=>0,'frozen'=>false,'paused_feed_seconds'=>0]],'buildings'=>[['type'=>'championcage','level'=>1,'hp'=>100,'ready_at'=>0]]];}
$recipes=['gorgo'=>['crabatron'=>20],'drull'=>['fang'=>30],'fomor'=>['brain'=>20],'korath'=>['kingwormzer'=>3]];
foreach(Catalog::champions() as $type=>$spec){
    verify($spec['maxBonusFeeds']===3,'three bonus tiers '.$type);
    $base=Catalog::championStats($type,6,0);
    for($tier=1;$tier<=3;$tier++){
        $stats=Catalog::championStats($type,6,$tier);
        verify($stats['hp']>$base['hp']&&$stats['damage']>$base['damage']&&$stats['speed']>$base['speed'],'earned tier increases actual stats '.$type.' '.$tier);
        verify($stats['bonus_feeds']===$tier,'effective tier recorded '.$type.' '.$tier);
        verify(Game::championRecipe($type,6,$tier-1)===$recipes[$type],'actual shipped meal preserved '.$type.' '.$tier);
        foreach($spec['bonuses'][$tier] as $key=>$amount){near($stats[$key],$base[$key]+$amount,'cumulative not compounding bonus '.$type.' '.$tier.' '.$key);}
    }
    verify(Game::championRecipe($type,6,3)===$recipes[$type],'tier-three maintenance recipe '.$type);
    verify(Catalog::championStats($type,6,99)===Catalog::championStats($type,6,3),'bonus cap '.$type);
    verify(Catalog::championStats($type,5,3)===Catalog::championStats($type,5,0),'bonuses do not leak below level six '.$type);
    $fixture=championBase($type);$ready=Game::readyChampion($fixture,1000);
    verify($ready!==null&&$ready['stats']===Catalog::championStats($type,6,3)&&$ready['max_hp']===$ready['stats']['hp'],'ready champion carries earned stats '.$type);
    $roster=Game::championRoster($fixture);verify($roster[$type]['max_hp']===$ready['max_hp']&&$roster[$type]['stats']===$ready['stats'],'public roster shows same stats '.$type);
}
verify(Catalog::championStats('gorgo',6,3)['hp']===37500&&Catalog::championStats('gorgo',6,3)['damage']===600,'Gorgo source-relative final bonus');
verify(Catalog::championStats('drull',6,3)['hp']===10500&&Catalog::championStats('drull',6,3)['damage']===1800,'Drull source-relative final bonus');
near(Catalog::championStats('fomor',6,3)['auraBuff'],0.75,'Fomor final source aura');
near(Catalog::championStats('fomor',6,3)['range'],4.4+4.4*10/210,'Fomor ranged bonus scales to world geometry');
$b=championBase();Game::accrueChampions($b,1000+86400-1,1);verify($b['champions']['gorgo']['bonus_feeds']===3,'bonus survives until exact hunger boundary');
Game::accrueChampions($b,1000+86400,1);verify($b['champions']['gorgo']['bonus_feeds']===2,'first missed meal day removes one tier');
verify($b['champions']['gorgo']['hp']===34125.0,'hunger clamps to new maximum');$once=$b;Game::accrueChampions($b,1000+86400,1);verify($b===$once,'same hunger boundary cannot charge twice');
Game::accrueChampions($b,1000+3*86400,1);verify($b['champions']['gorgo']['bonus_feeds']===0&&$b['champions']['gorgo']['level']===6,'hunger removes earned tiers without de-evolution');
Game::accrueChampions($b,1000+900*86400,1);verify($b['champions']['gorgo']['bonus_feeds']===0&&$b['champions']['gorgo']['hp']===30000.0,'long absence is bounded and clamps correctly');
$b=championBase();$boundary=1000+86400;$b['champions']['gorgo']['last_heal_at']=$boundary-2;$b['champions']['gorgo']['hp']=100;
Game::accrueChampions($b,$boundary+2,1);near($b['champions']['gorgo']['hp'],100+2*37500/5760+2*34125/5760,'recovery changes rate at actual tier-decay time');
$b=championBase();$b['champions']['gorgo']['last_heal_at']=$boundary;$b['champions']['gorgo']['hp']=0;Game::accrueChampions($b,$boundary,1);verify($b['champions']['gorgo']['hp']===0.0,'hunger alone never revives defeated champion');
foreach(['frozen','battle_id'] as $key){$b=championBase();$b['champions']['gorgo'][$key]=$key==='frozen'?true:17;$once=$b;Game::accrueChampions($b,1000000,20);verify($b===$once,'paused champion preserves bonus and clocks '.$key);}
$b=championBase();$b['champions']['gorgo']['last_heal_at']=2000;$once=$b;Game::accrueChampions($b,1500,1);verify($b===$once,'clock regression never repeats recovery');
echo "Champion bonus progression: $checks assertions passed.\n";
