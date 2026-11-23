<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$checks = 0;
function checkDefense(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($description); }
    ++$checks;
}
function defenseBuilding(string $type, int $id, float $x, float $y, int $level = 1, array $overrides = []): array
{
    $hp = Game::buildingStats($type, $level)['max_hp'];
    $building = $overrides + ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$level,
        'max_hp'=>$hp,'hp'=>$hp,'ready_at'=>0,'fortification'=>0];
    return BattleService::freezeBuilding($building, Catalog::buildings()[$type], 0, 0, 1000);
}
function invader(string $id, string $type, float $x, float $y, string $movement = 'ground'): array
{
    return ['id'=>$id, 'type'=>$type, 'x'=>$x, 'y'=>$y, 'team'=>'attacker', 'movement'=>$movement,
        'hp'=>20000, 'max_hp'=>20000,'targetId'=>null,'state'=>'walk', '_cooldown'=>0,
        '_speed'=>0, '_range'=>0.1,'_damage'=>0,'_target'=>'any','_interval'=>1,
        '_path'=>[], '_repath'=>0, '_obstacleVersion'=>-1];
}
function arena(array $building, array $units): array
{
    return ['unitSeq'=>20,'id'=>1,'formatVersion'=>7,'status'=>'active','elapsed'=>0.0,'duration'=>300,
        'buildings'=>[defenseBuilding('townhall',1,25,25),$building],'units'=>$units,
        'remaining'=>['pokey'=>1],'events'=>[],'eventSeq'=>0,'obstacleVersion'=>0,
        'attacker'=>['id'=>1],'defender'=>['id'=>2],'result'=>null];
}
$tower=defenseBuilding('railgun',2,8,8);
$units=[invader('first','ichi',10,8),invader('behind','ichi',24,8.49),invader('miss','ichi',15,8.52),invader('reverse','ichi',5,8),invader('fly','teratorn',12,8,'air')];
$battle=arena($tower,$units);
BattleEngine::advance($battle,.25);
checkDefense(array_column($battle['units'],'hp') == [19600,19600,20000,20000,20000], 'Rail pierces aligned ground targets beyond acquisition, excluding reverse, off-line and flying targets');
$shot=array_values(array_filter($battle['events'],fn($e)=>$e['type']==='shot'))[0];
checkDefense($shot['endX']==32 && $shot['endY']==8, 'Beam ends at the yard edge');
$tower['hp'] /=2; $b=arena($tower,$units);BattleEngine::advance($b,.25);
checkDefense($b['units'][0]['hp']==19700,'Damaged Railgun fires at three-quarters strength');
$tower['jarHp']=1500;$b=arena($tower,$units);BattleEngine::advance($b,.25);
checkDefense($b['buildings'][1]['jarHp']==600 && $b['units'][0]['hp']==20000,'Jar absorbs a triple-strength rail strike');
$b=arena(defenseBuilding('quaketower',2,8,8),[invader('inner','ichi',8,8),invader('edge','ichi',12,8),invader('fly','teratorn',10,8,'air')]);
BattleEngine::advance($b,3);checkDefense($b['units'][0]['hp']==20000 && isset($b['buildings'][1]['quakeImpactAt']),'Quake winds up before landing');
$dead=$b;$dead['buildings'][1]['hp']=0;BattleEngine::advance($dead,4.75);checkDefense($dead['units'][0]['hp']==20000,'Destroyed ram cannot finish its wind-up');
$b=json_decode(json_encode($b,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);BattleEngine::advance($b,4.5);
checkDefense($b['units'][0]['hp']==18900 && abs($b['units'][1]['hp']-(20000-1100/3))<.001 && $b['units'][2]['hp']==20000,'Saved quake damages a radius with one-third minimum');
$b=arena(defenseBuilding('infernocannon',2,8,8),[invader('main','ichi',11,8),invader('near','ichi',11.65,8),invader('fly','teratorn',11,8,'air')]);BattleEngine::advance($b,.25);
checkDefense($b['units'][0]['hp']==20000 && count($b['_defenseShells'])===1,'Launched Blast shell travels before damaging the target');
$b['buildings'][1]['hp']=0;BattleEngine::advance($b,1);checkDefense(array_column($b['units'],'hp')==[19980,19996,20000],'Airborne shell survives its tower and damages ground units with one-fifth minimum');
$b=arena(defenseBuilding('magmatower',2,8,8),[invader('main','ichi',10,8)]);BattleEngine::advance($b,1.5);
checkDefense($b['units'][0]['hp']==20000-540-90 && $b['units'][0]['_flameDps']==90 && $b['units'][0]['burning'],'Repeated molten shots apply one one-second flame timer');
$b['buildings'][1]['hp']=0;BattleEngine::advance($b,2.5);checkDefense($b['units'][0]['hp']==20000-540-180,'Existing flame survives the tower');
$b=arena(defenseBuilding('magmatower',2,8,8),[invader('fly','teratorn',10,8,'air')]);BattleEngine::advance($b,.5);checkDefense($b['units'][0]['hp']==19820 && empty($b['units'][0]['burning']),'Airborne Magma target takes direct damage only');
foreach(['railgun'=>8,'quaketower'=>6,'infernocannon'=>7,'magmatower'=>6] as $type=>$max){
    $c=Catalog::buildings()[$type];
    checkDefense($c['maxLevel']==$max && $c['siteOnly']===($type!=='railgun'),"$type catalog separation");
    foreach($c['levels'] as $level=>$row){
        $f=defenseBuilding($type,2,8,8,$level);
        checkDefense($f['_damage']==$row['damage'] && $f['_range']==$row['range'] && $f['hp']==$row['hp'],"$type level $level freezes original stats");
    }
}
checkDefense(Game::upgradeCost('railgun',7)['twig']===270000,'Railgun upgrade uses the source price curve');

foreach (['spurtzcannon'=>10,'diamondspurtz'=>15] as $type=>$shots) {
    $b=arena(defenseBuilding($type,2,8,8),[invader('a','ichi',11,8),invader('b','ichi',11,9)]);
    $b['attacker']['id']=1;
    BattleEngine::advance($b,ceil($shots/2)*.25);
    $bullets=array_values(array_filter($b['events'],fn($e)=>$e['kind']===$type && $e['type']==='shot'));
    checkDefense(count($bullets)===$shots,"$type first burst size");
    checkDefense(count(array_unique(array_column($bullets,'tx')))>1,"$type scatters eggs");
    $restored=json_decode(json_encode($b,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
    $b['buildings'][1]['hp']=0;$restored['buildings'][1]['hp']=0;
    BattleEngine::advance($b,2.75);BattleEngine::advance($restored,2.75);
    $living=array_values(array_filter($b['units'],fn($u)=>$u['team']==='defender'));
    checkDefense(count($living)>0 && $living[0]['type']==='spurtz' && empty($living[0]['_bunkerId']),"$type hatches temporary fighting defenders");
    checkDefense($b['units'][0]['hp']===$restored['units'][0]['hp'] && array_column($b['events'],'type')===array_column($restored['events'],'type'),"$type save/reload is deterministic");
    BattleEngine::advance($b,8.5);
    checkDefense(count($b['units'])===2,"$type temporary Spurtz expire and never join the garrison");
}
$base=['buildings'=>[]];checkDefense(SpurtzRewards::status($base)['limit']===0,'Unplayed expeditions have no cannons');
SpurtzRewards::award($base,3);checkDefense(SpurtzRewards::status($base)['limit']===1,'Third clear grants one cannon');
SpurtzRewards::award($base,4);$c=defenseBuilding('spurtzcannon',42,13,6,2);$c['hp']/=2;$c['ready_at']=9123;$c['upgrade_to']=3;$base['buildings'][]=$c;
SpurtzRewards::award($base,5);SpurtzRewards::award($base,3);$converted=$base['buildings'][0];
checkDefense($converted['type']==='diamondspurtz' && $converted['hp']==12100 && $converted['max_hp']==24200 && $converted['ready_at']===9123 && $converted['upgrade_to']===3,'Conversion preserves life fraction and existing paid upgrade');
checkDefense(SpurtzRewards::status($base)===['cleared'=>5,'limit'=>2,'type'=>'diamondspurtz'],'Old result cannot downgrade rewards');
echo "Expanded towers and reward cannons: $checks checks passed.\n";
