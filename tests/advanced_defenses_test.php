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
    return ['id'=>1,'formatVersion'=>7,'status'=>'active','elapsed'=>0.0,'duration'=>300,
        'buildings'=>[defenseBuilding('townhall',1,25,25),$building],'units'=>$units,
        'remaining'=>['pokey'=>1],'events'=>[],'eventSeq'=>0,'obstacleVersion'=>0,
        'attacker'=>['id'=>1],'defender'=>['id'=>2],'result'=>null];
}
function shots(array $state): array
{
    return array_values(array_filter($state['events'], fn($event)=>$event['type']==='shot' && $event['kind']==='aerial'));
}

$tower = defenseBuilding('aerial',2,8,8);
$targets = [invader('a','teratorn',12,8,'air'), invader('b','teratorn',12,9,'air'), invader('c','ichi',12,8)];
$battle = arena($tower, $targets);
BattleEngine::advance($battle, 1.0);
checkDefense(array_column(shots($battle),'targetId') === ['a','b','a','b'], 'Flak volley cycles through eligible flying targets');
checkDefense($battle['units'][0]['hp'] == 19700 && $battle['units'][1]['hp'] == 19700, 'Direct and splash damage use different strengths');
checkDefense($battle['units'][2]['hp'] == 20000, 'Ground invader ignores direct and splash flak');
$restored = json_decode(json_encode($battle, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
BattleEngine::advance($restored, 2.75);
checkDefense(count(shots($restored)) === 4, 'Reload survives saving and reopening the battle');
BattleEngine::advance($restored, 3.0);
checkDefense(count(shots($restored)) === 5 && shots($restored)[4]['targetId'] === 'a', 'A new volley starts after reload');
foreach ([1=>4,2=>4,3=>6,4=>8,5=>10] as $level=>$count) {
    $upgraded = defenseBuilding('aerial',2,8,8,$level);
    checkDefense($upgraded['_volley'] === $count, "Volleys scale at level $level");
}
$weak = arena(defenseBuilding('aerial',2,8,8,1,['hp'=>1500]), $targets);
BattleEngine::advance($weak, 0.25);
checkDefense(shots($weak)[0]['amount'] == 75, 'A half-health tower fires at three quarters strength');
foreach ([['ready_at'=>1300], ['fortify_ready_at'=>1300], ['hp'=>0]] as $work) {
    $busy = arena(defenseBuilding('aerial',2,8,8,1,$work), $targets);
    BattleEngine::advance($busy, 1.0);
    checkDefense(!shots($busy), 'Unavailable tower cannot shoot');
}
$trap = defenseBuilding('heavytrap',3,8,8);
$light = invader('a','pokey',8,8);
$flyer = invader('b','teratorn',8,8,'air');
$battle = arena($trap, [$light,$flyer]);
checkDefense(BattleEngine::legalDeployment($battle,8,8), 'Hidden trap cannot be detected by perimeter probes');
BattleEngine::advance($battle, 1.0);
checkDefense($battle['buildings'][1]['hp'] > 0 && $battle['units'][0]['targetId'] === 1, 'Light attackers pass over the hidden trap');
foreach (['crabatron','projectx','dave','drull','gorgo','fomor'] as $type) {
    $heavy = arena($trap, [invader('a',$type,8,8), invader('b','pokey',11,8), $flyer]);
    BattleEngine::advance($heavy, 0.25);
    checkDefense($heavy['buildings'][1]['hp'] == 0 && $heavy['units'][0]['hp'] == 16000, "$type on the ground trips the pressure plate");
    checkDefense($heavy['units'][1]['hp'] == 18000 && $heavy['units'][2]['hp'] == 18000, 'Edge splash and overhead splash each deal half damage');
    checkDefense(BattleEngine::destruction($heavy) == 0 && BattleEngine::captureProgress($heavy) == 0, 'Trap expenditure earns no capture or destruction credit');
    BattleEngine::advance($heavy, 1.0);
    checkDefense(count(array_filter($heavy['events'],fn($event)=>$event['type']==='trap')) === 1, 'A spent trap fires only once');
}
$airChampion = arena($trap,[invader('g','fomor',8,8,'air')]);
BattleEngine::advance($airChampion, 0.25);
checkDefense($airChampion['buildings'][1]['hp'] > 0, 'Flying Fomor cannot trigger the pressure plate');
$unfinished = defenseBuilding('heavytrap',3,8,8,0,['ready_at'=>1040,'hp'=>0]);
$yard = ['buildings'=>[$unfinished,$heavy['buildings'][1]],'resources'=>[]];
checkDefense(!Catalog::visibleToRivals($unfinished) && Catalog::visibleToRivals($heavy['buildings'][1]), 'Visitors see spent traps, construction stays hidden');
checkDefense(Game::repairHome($yard) === 1 && !Catalog::visibleToRivals($yard['buildings'][1]), 'Free home repair rearms and conceals the spent trap');
checkDefense($yard['buildings'][0] === $unfinished, 'Repair preserves the new construction site');
echo "Advanced defense rules: $checks checks passed.\n";
