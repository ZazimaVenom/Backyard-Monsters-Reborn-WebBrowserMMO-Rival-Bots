<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function kcheck(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);++$checks;}
$values=[];for($tier=1;$tier<=5;++$tier){$values[]=MonsterMadness::value($tier);}
kcheck($values===array_values(array_unique($values)) && $values[4]>3000000 && $values[0]>1700000,'Five distinct tiers on original empire value scale');
$b=[];$now=2000000;$value=$values[4];
for($i=1;$i<=60;++$i){
    $before=MonsterMadness::view($b,$now);$result=MonsterMadness::capture($b,$i,$value,$now);
    $m=MonsterMadness::view($b,$now);$score=min($i*$value,160125000);
    kcheck($m['score']===$score&&$m['power']===count(array_filter(MonsterMadness::GOALS,fn($g)=>$score>=$g)),'Sequential earned thresholds');
    kcheck(MonsterMadness::capture($b,$i,$value,$now)===null,'Recaptured outpost excluded');
    if($before['power']<3){kcheck($result['earned']===($before['power']<$m['power']),'Earned reward receipt');}
}
$other=[];MonsterMadness::capture($other,1,42000000,$now);kcheck(MonsterMadness::view($other,$now+345600)['score']===0,'Four-day cutoff');
MonsterMadness::capture($other,1,10,$now+345600);$m=MonsterMadness::view($other,$now+345600);
kcheck($m['score']===10&&$m['sites']===1&&$m['power']===1&&$m['best']===42000000,'New run preserves all-time power and permits old site again');
function kstate(int $level,int $power,string $opponent):array{
    $yard=['buildings'=>[['type'=>'championcage','level'=>1,'ready_at'=>0,'hp'=>100]],'champions'=>['korath'=>['level'=>$level,'hp'=>1000,'bonus_feeds'=>0,'frozen'=>false,'battle_id'=>0]],
        'activeChampion'=>'korath','monsterMadness'=>['power'=>$power]];
    $frozen=BattleService::readyChampion($yard,100);$construct=new ReflectionMethod(BattleService::class,'makeChampionUnit');
    $c=$construct->invoke(null,$frozen,'ca','attacker',16,16);
    $v=BattleService::makeUnit($opponent,1,'d1','defender',16,16);$v['_damage']=0;$v['_speed']=0;$v['hp']=$v['max_hp']=900000;
    $b=['type'=>'townhall','id'=>1,'x'=>28,'y'=>28,'level'=>1,'ready_at'=>0,'hp'=>100000,'max_hp'=>100000];
    return ['id'=>1,'status'=>'active','formatVersion'=>7,'elapsed'=>0,'duration'=>300,'attacker'=>['id'=>1],'defender'=>['id'=>2],
        'buildings'=>[BattleService::freezeBuilding($b,Catalog::buildings()['townhall'],0,0,1)],
        'units'=>[$c,$v],'unitSeq'=>0,'eventSeq'=>0,'events'=>[],'remaining'=>['pokey'=>1],'obstacleVersion'=>0,'_garrisonTemplates'=>[],'result'=>null];
}
foreach([[3,2,false],[4,1,false],[4,2,true],[6,3,true]] as [$level,$power,$enabled]){
    $s=kstate($level,$power,'teratorn');BattleEngine::advance($s,.25);$damage=$s['units'][0]['_damage'];
    kcheck($s['units'][1]['hp']==900000-($enabled?$damage/4:0),'Airborne fireball requires power 2 and evolution 4');
    kcheck(in_array('magma',array_column($s['events'],'projectile'),true)===$enabled,'Fireball projectile cue');
    if($enabled){BattleEngine::advance($s,1.25);kcheck($s['units'][1]['hp']==900000-$damage/4-0.1*$damage,'Non-stacking burn ticks on living monster');}
}
foreach([[4,3,0],[5,2,0],[5,3,1],[6,3,1]] as [$level,$power,$waves]){
    $s=kstate($level,$power,'ichi');BattleEngine::advance($s,6.5);
    kcheck(count(array_filter($s['events'],fn($e)=>$e['type']==='korath_stomp'))===$waves,'Fists of Doom requires three melee strikes and earned evolution/power');
}
kcheck(Game::championRecipe('korath',1)===['spurtz'=>20,'zagnoid'=>10]&&Game::championRecipe('korath',6,3)===['kingwormzer'=>3],'Non-MR3 Inferno diet');
echo "Monster Madness: $checks checks passed.\n";
