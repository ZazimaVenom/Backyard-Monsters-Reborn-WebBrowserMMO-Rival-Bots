<?php
declare(strict_types=1);
require_once __DIR__.'/../app/Catalog.php';
require_once __DIR__.'/../app/Planner.php';

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: '.$label); }
    ++$checks;
}
function rejected(callable $operation, string $label): void {
    try { $operation(); } catch (RuntimeException) { check(true, $label); return; }
    throw new RuntimeException('FAIL: accepted '.$label);
}
function pb(int $id, string $type, float $x, float $y): array {
    return ['id'=>$id, 'type'=>$type, 'x'=>$x, 'y'=>$y, 'level'=>1, 'hp'=>1234,
        'max_hp'=>3200, 'ready_at'=>0, 'upgrade_to'=>null, 'stored'=>42.25,
        'fortification'=>3, 'fortify_ready_at'=>0, 'fortify_to'=>null, 'fortify_paid'=>null,
        'custom'=>['do'=>'not lose']];
}
function yard(): array {
    return ['buildings'=>[pb(7,'townhall',16,16),pb(3,'planner',5,5),pb(11,'sniper',8,18),pb(20,'sniper',24,18),pb(30,'bunker',25,25),pb(31,'trap',4,25)],
        'resources'=>['twig'=>12345,'pebble'=>23456,'putty'=>3456,'goo'=>45678],
        'army'=>['pokey'=>19],'queue'=>[['type'=>'pokey','ready_at'=>2000]],
        'research'=>['pokey'=>2],'garrison'=>[30=>['pokey'=>4]],'workers'=>1,
        'planner'=>['slots'=>[],'custom'=>'retained'],'content_version'=>'0.8.0'];
}
function operation(array &$base, string $action, array $input = [], int $now = 1000): void {
    Planner::mutateBase($base, $action, $input + ['revision'=>Planner::revision($base)], $now);
}
function nodesAt(array $base, int $id, float $x, float $y): array {
    $nodes = Planner::positions($base);
    foreach ($nodes as &$node) { if ($node['id'] === $id) { $node['x']=$x; $node['y']=$y; } }
    unset($node);
    return $nodes;
}

$base = yard();
$status = Planner::status($base,1000);
check($status['available'] && $status['reason'] === '' && $status['maxSlots'] === 2, 'source two-slot planner is available');
check($status['slots'] === [] && $status['lockedIds'] === [], 'new state has no granted templates or artificial work locks');
check(array_column($status['positions'],'id') === [3,7,11,20,30,31], 'positions have stable numeric ID order');
check(array_keys($status['positions'][0]) === ['id','type','x','y'], 'positions expose only geometry');
check(strlen($status['revision']) === 64, 'geometry revision is a SHA-256 fingerprint');
check(Planner::validateLayout($base,$status['positions'],1000) === $status['positions'], 'valid unchanged arrangement accepted');
$before=$base; operation($base,'plannerApply',['positions'=>$status['positions']]);
check($base === $before, 'unchanged application preserves every field exactly');

$fingerprint=Planner::revision($base);
$copy=$base; $copy['resources']['twig']+=100; $copy['buildings'][2]['hp']=0; $copy['buildings'][2]['level']=4;
$copy['buildings'][2]['stored']=25; $copy['buildings'][2]['fortification']=4; $copy['buildings'][2]['ready_at']=5000;
check(Planner::revision($copy)===$fingerprint,'health, production, levels and clocks do not create stale geometry');
$copy=$base; $copy['buildings']=array_reverse($copy['buildings']);
check(Planner::revision($copy)===$fingerprint,'saved record ordering does not invalidate a draft');
$copy=$base; foreach($copy['buildings'] as &$building){$building['x']=(int)$building['x'];$building['y']=(int)$building['y'];}unset($building);
check(Planner::revision($copy)===$fingerprint,'numeric storage representation does not invalidate geometry');
$copy=$base; $copy['buildings'][2]['x']+=1;
check(Planner::revision($copy)!==$fingerprint,'one changed position invalidates an old draft');
$copy=$base; $copy['buildings'][]=pb(99,'wall',3,10);
check(Planner::revision($copy)!==$fingerprint,'new inventory invalidates an old draft');
$copy=$base; $copy['buildings'][2]['type']='cannon';
check(Planner::revision($copy)!==$fingerprint,'type and footprint changes invalidate geometry');

foreach ([['level',0],['hp',0],['ready_at',1001],['fortify_ready_at',1001]] as [$key,$value]) {
    $copy=yard(); $copy['buildings'][1][$key]=$value;
    check(!Planner::status($copy,1000)['available'],'unavailable Planner gate '.$key);
    foreach(['plannerSave','plannerApply','plannerDelete'] as $action) {
        $old=$copy;
        rejected(function()use(&$copy,$action){operation($copy,$action,['slot'=>1,'name'=>'Draft','positions'=>Planner::positions($copy)]);},'all planner mutations require usable facility '.$key.' '.$action);
        check($copy===$old,'facility rejection never changes saved data '.$key.' '.$action);
    }
}
$copy=yard(); array_splice($copy['buildings'],1,1);
check(!Planner::status($copy,1000)['available'],'missing Planner is unavailable');
$copy=yard(); $copy['buildings'][1]['hp']=1;
check(Planner::status($copy,1000)['available'],'living damaged Planner remains usable without healing');

$base=yard(); $nodes=Planner::positions($base);
foreach($nodes as &$node){if($node['id']===11){$node['x']=24.0;}elseif($node['id']===20){$node['x']=8.0;}}unset($node);
$old=$base; operation($base,'plannerApply',['positions'=>$nodes]);
check($base['buildings'][2]['x']===24.0 && $base['buildings'][3]['x']===8.0,'atomic swap does not collide with stale old footprints');
foreach($old['buildings'] as $index=>$building){unset($building['x'],$building['y']);$after=$base['buildings'][$index];unset($after['x'],$after['y']);check($building===$after,'apply preserves all nongeometry building fields '.$index);}
foreach($old as $key=>$value){if($key!=='buildings'){check($base[$key]===$value,'apply preserves yard field '.$key);}}
check(Planner::revision($base)!==Planner::revision($old),'successful move advances derived geometry revision');

foreach (['ready_at','fortify_ready_at'] as $clock) {
    $base=yard(); $base['buildings'][2][$clock]=2000;
    check(Planner::lockedIds($base,1000)===[11],'future clock locks building '.$clock);
    $invalid=nodesAt($base,11,9,18); $before=$base;
    rejected(function()use(&$base,$invalid){operation($base,'plannerApply',['positions'=>$invalid]);},'cannot move paid job '.$clock);
    check($base===$before,'rejected paid-job move preserves timer and payment '.$clock);
    $valid=nodesAt($base,20,25,18); operation($base,'plannerApply',['positions'=>$valid]);
    check($base['buildings'][2]===$before['buildings'][2] && $base['buildings'][3]['x']===25.0,'other buildings can move around a pinned job '.$clock);
    check(Planner::lockedIds($base,2000)===[],'exact deadline releases the position lock '.$clock);
    check(Planner::validateLayout($base,nodesAt($base,11,9,18),2000)!==[],'completed timestamp accepts moving without writing timer '.$clock);
}

$base=yard(); $baseline=Planner::positions($base);
$bad=[];
$bad['missing building']=array_slice($baseline,1);
$bad['unplaced building']=[];
$bad['object instead of list']=['nodes'=>$baseline];
$bad['null']=null;
$bad['scalar']='nodes';
$bad['duplicate ID']=$baseline; $bad['duplicate ID'][1]=$baseline[0];
$bad['foreign ID']=$baseline; $bad['foreign ID'][0]['id']=123456;
$bad['wrong type']=$baseline; $bad['wrong type'][0]['type']='wall';
$bad['missing type']=$baseline; unset($bad['missing type'][0]['type']);
$bad['forged health']=$baseline; $bad['forged health'][0]['hp']=999999;
$bad['boundary']=$baseline; $bad['boundary'][0]['x']=1;
$bad['overlap']=$baseline; $bad['overlap'][0]['x']=16; $bad['overlap'][0]['y']=16;
foreach([null,[],true,1.5,'1.5','x',0,-1] as $index=>$id){$bad['invalid id '.$index]=$baseline;$bad['invalid id '.$index][0]['id']=$id;}
foreach([null,[],true,'5','NaN',INF,-INF,NAN] as $index=>$coordinate){$bad['invalid coordinate '.$index]=$baseline;$bad['invalid coordinate '.$index][0]['x']=$coordinate;}
foreach($bad as $label=>$invalid){
    $copy=$base; rejected(function()use(&$copy,$invalid){operation($copy,'plannerApply',['positions'=>$invalid]);},$label);
    check($copy===$base,'atomic invalid-input rejection '.$label);
}
$copy=yard();$boundary=nodesAt($copy,3,2.3,2.3);
check(Planner::validateLayout($copy,$boundary,1000)!==[],'exact footprint inside left/top boundary accepted');
$copy=yard();$boundary=nodesAt($copy,3,29.7,2.3);
check(Planner::validateLayout($copy,$boundary,1000)!==[],'exact footprint inside right boundary accepted');
$copy=yard();$gap=nodesAt($copy,11,21.8,18); // sniper centers need 1.8 + .15 units.
check(Planner::validateLayout($copy,$gap,1000)!==[],'clearance above footprint gap accepted');
$gap=nodesAt($copy,11,22.1,18);
rejected(fn()=>Planner::validateLayout($copy,$gap,1000),'strict overlap with neighboring footprint rejected');

$base=yard();$nodes=nodesAt($base,11,9,18);$before=$base;
operation($base,'plannerSave',['slot'=>1,'name'=>'  Guard line  ','positions'=>$nodes]);
check($base['buildings']===$before['buildings'],'save leaves the actual backyard unchanged');
check($base['planner']['slots'][1]['name']==='Guard line' && $base['planner']['slots'][1]['savedAt']===1000,'save stores trimmed name and server timestamp');
check(array_keys($base['planner']['slots'][1])===['name','positions','savedAt'],'saved record contains no live building stats');
check($base['planner']['custom']==='retained','save preserves unrelated planner state');
check(Planner::revision($base)===Planner::revision($before),'saving does not alter geometry revision');
$slot1=$base['planner']['slots'][1];
operation($base,'plannerSave',['slot'=>2,'name'=>'防御の庭 🐲','positions'=>Planner::positions($base)]);
check($base['planner']['slots'][1]===$slot1 && count($base['planner']['slots'])===2,'second named layout preserves first');
check(array_column(Planner::status($base,1000)['slots'],'slot')===[1,2],'status exposes both occupied slots in order');
operation($base,'plannerSave',['slot'=>2,'name'=>str_repeat('庭',40),'positions'=>Planner::positions($base)]);
check($base['planner']['slots'][2]['name']===str_repeat('庭',40),'forty multibyte characters work without mbstring');
foreach(['',"  \t ",str_repeat('庭',41),"invalid\nname", "invalid\0name", "\xC3\x28",[],null] as $index=>$name){
    $copy=$base;$before=$copy;
    rejected(function()use(&$copy,$name){operation($copy,'plannerSave',['slot'=>2,'name'=>$name,'positions'=>Planner::positions($copy)]);},'invalid name '.$index);
    check($copy===$before,'invalid name cannot overwrite existing template '.$index);
}
foreach([0,3,-1,[],null,1.5,'1.5'] as $index=>$slot){
    foreach(['plannerSave','plannerDelete'] as $action){$copy=$base;$before=$copy;
        rejected(function()use(&$copy,$slot,$action){operation($copy,$action,['slot'=>$slot,'name'=>'Draft','positions'=>Planner::positions($copy)]);},'invalid slot '.$index.' '.$action);
        check($copy===$before,'invalid slot is atomic '.$index.' '.$action);
    }
}
$before=$base; operation($base,'plannerDelete',['slot'=>2]);
check(isset($base['planner']['slots'][1]) && !isset($base['planner']['slots'][2]),'deletion affects requested slot only');
check($base['buildings']===$before['buildings'],'deleting a template never deletes a real building');
rejected(function()use(&$base){operation($base,'plannerDelete',['slot'=>2]);},'empty slot deletion rejected');

foreach(['plannerSave','plannerDelete','plannerApply'] as $action){
    foreach([null,[],false,'',str_repeat('a',64),strtoupper(Planner::revision($base))] as $index=>$revision){
        $copy=$base;$before=$copy;
        rejected(function()use(&$copy,$action,$revision){Planner::mutateBase($copy,$action,['slot'=>1,'name'=>'Changed','positions'=>Planner::positions($copy),'revision'=>$revision],1000);},'invalid or stale revision '.$action.' '.$index);
        check($copy===$before,'stale revision leaves all state intact '.$action.' '.$index);
    }
}
$before=$base; rejected(function()use(&$base){operation($base,'plannerGrant',['positions'=>Planner::positions($base)]);},'unknown planner action');
check($base===$before,'unknown action cannot mutate the yard');

// Saved slots are historical geometry; new real buildings are not silently added or removed.
$base['buildings'][]=pb(99,'wall',3,10);
$status=Planner::status($base,1000);
check(count($status['slots'][0]['positions'])===6 && count($status['positions'])===7,'old layout and current inventory remain distinct');
rejected(function()use(&$base,$status){operation($base,'plannerApply',['positions'=>$status['slots'][0]['positions']]);},'old layout with a newly unplaced building cannot erase it');
echo "Yard Planner pure rules: $checks checks passed.\n";
