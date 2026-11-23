<?php
declare(strict_types=1);
require_once __DIR__.'/../app/BattleService.php';

$checks = 0;
function checkFling(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks;
}

$reference = json_decode(file_get_contents(__DIR__.'/../docs/ORIGINAL-CATALOG.json'), true, 512, JSON_THROW_ON_ERROR);
$original = array_column($reference['buildings'], null, 'id')[5]['capacity'];
for ($level = 1; $level <= 5; ++$level) {
    checkFling(Flinger::loadout($level)['capacity'] === $original[$level - 1], "Level $level capacity");
}
$species = ['pokey','octo','bolt','fink','eyera','ichi','bandito','fang','brain','crabatron','projectx','dave','wormzer','teratorn','zafreeti'];
foreach ($species as $index => $type) {
    checkFling(Flinger::WEIGHTS[$type] === $reference['monsters']['C'.($index + 1)]['props']['bucket'][0], "$type reference bucket weight");
}
checkFling(!array_diff_key(Catalog::monsters(), Flinger::WEIGHTS), 'All playable ordinary monsters have a Flinger weight');

$building = ['id'=>1,'type'=>'wall','x'=>16,'y'=>16,'hp'=>100,'_size'=>2];
$state = ['id'=>414,'elapsed'=>0.0,'unitSeq'=>0,'events'=>[],'units'=>[],'eventSeq'=>0,
    'flinger'=>Flinger::loadout(5),'buildings'=>[$building], 'remaining'=>['pokey'=>100,'dave'=>20]];
checkFling(Flinger::clearance($state['flinger'], 7) === 1.25, 'Minimum source drop diameter');
checkFling(Flinger::clearance($state['flinger'], 800) === 1.25 && Flinger::clearance($state['flinger'], 3500) === 5.46875, 'Large loads expand the drop ring');
checkFling(Flinger::legalAim($state, 18.25, 16, 800) && !Flinger::legalAim($state, 18.249, 16, 800), 'Tangent vs overlapping drop circle');
checkFling(!Flinger::legalAim($state, 19.123, 16, 3500) && Flinger::legalAim($state, 19.123, 16, 7), 'Room for one unit can be too tight for a full bucket');
foreach ([NAN, INF, -1, 32] as $bad) {
    checkFling(!Flinger::legalAim($state, $bad, 10, 7), 'Reject invalid coordinates');
}
foreach ([['type'=>'wall','hp'=>0], ['type'=>'trap'], ['type'=>'heavytrap'], ['_untargetable'=>true]] as $nonblocking) {
    $nonblockingState = $state;
    $nonblockingState['buildings'] = [$nonblocking + $building];
    checkFling(Flinger::legalAim($nonblockingState, 16, 16, 7), 'Trap, ruin or untargetable structure permits a drop');
}

$positions = [];
foreach ([[12,4],[0.4,0.4],[31.6,31.6]] as [$x,$y]) {
    checkFling(Flinger::legalAim($state, $x, $y, 3200), 'Fixture center is legal');
    for ($seq = 0; $seq < 100; ++$seq) {
        $state['unitSeq'] = $seq;
        [$px,$py] = Flinger::landing($state, 3200, $x, $y, $seq);
        checkFling($px >= 0.4 && $py >= 0.4 && $px <= 31.6 && $py <= 31.6, 'Land inside the map');
        checkFling(hypot($px - $x, $py - $y) <= Flinger::clearance($state['flinger'], 3200), 'Land within visible drop radius');
        checkFling([$px,$py] === Flinger::landing($state, 3200, $x, $y, $seq), 'Reloaded sequence produces the same landing');
        if ($x === 12) { $positions[] = sprintf('%.3f/%.3f', $px, $py); }
    }
}
checkFling(count(array_unique($positions)) > 90, 'Scatter produces individual landings inside the chosen area');

$state['unitSeq'] = 0;
$state['flinger'] = Flinger::loadout(1);
$base = ['army'=>$state['remaining']];
$launch = new ReflectionMethod(BattleService::class, 'deployBucket');
$before = [$state,$base];
foreach ([[['pokey'=>13,'dave'=>1],2,2],[['pokey'=>1],16,16]] as [$troops,$x,$y]) {
    try {
        $launch->invokeArgs(null, [&$state,&$base,$troops,$x,$y]);
        throw new LogicException('Invalid load unexpectedly launched');
    } catch (RuntimeException $error) {
        checkFling([$state,$base] === $before, 'Failed load leaves cooldown, events and both inventories untouched');
    }
}
$launch->invokeArgs(null, [&$state,&$base,['pokey'=>12,'dave'=>1],3.123,3.456]);
checkFling(count($state['units']) === 13 && $state['remaining'] === ['pokey'=>88,'dave'=>19] && $base['army'] === $state['remaining'], 'Legal mixed load debits its exact units');
checkFling($state['flinger']['readyAt'] === 5.0, 'One five-second reload for one mixed launch');
checkFling(count(array_unique(array_column($state['units'], 'x'))) > 9, 'Actual deployment uses distinct landed coordinates');
$legacy = $before[0];
$legacy['flinger'] = ['capacity'=>100,'cooldown'=>5.0,'readyAt'=>0.0];
$legacy['buildings'][0]['hp'] = 0;
$base = $before[1];
checkFling(!Flinger::legalAim($legacy, 16, 16, 20), 'Legacy saved battle retains ruin exclusion');
$launch->invokeArgs(null, [&$legacy,&$base,['dave'=>1],3.123,3.456]);
checkFling($legacy['units'][0]['x'] === 3.123 && $legacy['units'][0]['y'] === 3.456, 'Legacy DAVE still fits its old bucket and first unit lands at center');
unset($legacy['flinger']);
$legacy['elapsed'] = 5.0;
$launch->invokeArgs(null, [&$legacy,&$base,['dave'=>2],2,2]);
checkFling(count($legacy['units']) === 3 && !isset($legacy['flinger']), 'Earliest saved invasions keep unlimited bucket and legacy spacing');
$blockedEdge = $state;
$blockedEdge['buildings'] = [];
for ($ix=1; $ix<32; $ix+=2) {
    $blockedEdge['buildings'][] = ['type'=>'wall','x'=>$ix,'y'=>1,'_size'=>2,'hp'=>500];
}
$slots = Flinger::botSlots($blockedEdge, 0, 250);
checkFling(count($slots)>1 && $slots[0][1] > 1.25, 'Bots try another border when their preferred one is occupied');
foreach ($slots as [$x,$y]) { checkFling(Flinger::legalAim($blockedEdge, $x, $y, 250), 'Bot picks legally clear ground'); }
echo "Flinger: $checks checks passed.\n";
