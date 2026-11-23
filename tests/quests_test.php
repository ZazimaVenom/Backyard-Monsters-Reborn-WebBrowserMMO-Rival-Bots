<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$checks = 0;
function expectQuest(bool $ok, string $message): void {
    global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks;
}
function blockedQuest(callable $op): void {
    try { $op(); } catch (RuntimeException) { expectQuest(true,'blocked'); return; }
    throw new RuntimeException('Expected a denied quest claim.');
}
function indexedQuestView(array $base, array $keeper): array {
    return array_column(Quests::view($base,$keeper)['entries'],null,'id');
}
$keeper = ['wins'=>0,'is_bot'=>false];
$base = ['buildings'=>[], 'resources'=>array_fill_keys(['twig','pebble','putty','goo'],0),'army'=>[],'queue'=>[]];
$base['buildings'][]=['type'=>'townhall','level'=>1];
$base['buildings'][]=['type'=>'housing','level'=>1];
$base['buildings'][]=['type'=>'hatchery','level'=>0,'upgrade_to'=>1,'hp'=>0];
$base['unlocked']=['pokey','octo','dave','fink','bandito','ichi'];
$entries = indexedQuestView($base,$keeper);
expectQuest(count($entries)===148,'The complete journal loads');
expectQuest($entries['C0']['status']==='ready' && $entries['CR2']['status']==='active','Existing structures count, unpaid completion does not');
expectQuest($entries['UC2']['status']==='active','Free starting species alone cannot earn Locker rewards');
$base['buildings'][]=['type'=>'locker','level'=>1];
expectQuest(indexedQuestView($base,$keeper)['UC2']['status']==='ready','A completed Locker certifies its starting species');
Quests::observe($base);
$one = $base;
Quests::observe($base);
expectQuest($one===$base,'Saving twice cannot increase milestone progress');
$base['buildings'][0]['level']=5;
Quests::observe($base);
$base['buildings'][0]['level']=1;
expectQuest(indexedQuestView($base,$keeper)['C16']['status']==='ready','Milestones survive changing source data');
Quests::hatched($base,'pokey',2); Quests::hatched($base,'invented',10);
expectQuest(indexedQuestView($base,$keeper)['RB-hatch']['progress']===2,'Only actual catalog species advance hatch progress');
Quests::harvested($base,['goo'=>62,'twig'=>40]);
Quests::harvested($base,['twig'=>70]);
expectQuest(indexedQuestView($base,$keeper)['BK2']['progress']===102,'Harvest record is a single collection high-water mark');
Quests::battleKills($base,[['hp'=>0,'champion'=>true,'team'=>'defender'],['hp'=>0,'team'=>'attacker']],'defender');
expectQuest(indexedQuestView($base,$keeper)['D1']['progress']===0,'Champions retreat and dead allies do not count as enemy kills');
Quests::battleKills($base,[['hp'=>0,'team'=>'defender']],'defender');
expectQuest(indexedQuestView($base,$keeper)['D1']['status']==='ready','Enemy monster credited at settlement');
$base['tribeCampaign']['cleared']=[1=>['best'=>54.5]];
expectQuest(indexedQuestView($base,$keeper)['WM1']['status']==='active','Camp clear without destroying its Hall does not credit tribe quest');
$base['tribeCampaign']['hallVictories']=['Legionnaire'=>true];
expectQuest(indexedQuestView($base,$keeper)['WM1']['status']==='ready','Confirmed tribe Town Hall destruction credits original quest');
Quests::juiced($base,8); Quests::juiced($base,2);
expectQuest(indexedQuestView($base,$keeper)['BL1']['status']==='ready','Juicer totals retain completed batches');
$base['resources']['pebble']=29926;
blockedQuest(fn()=>Quests::claim($base,$keeper,'C0',30000));
expectQuest(indexedQuestView($base,$keeper)['C0']['missing']===['pebble'=>1],'Exact resource capacity needed');
$base['resources']['pebble']=29925;
$first=Quests::claim($base,$keeper,'C0',30000);
expectQuest(!$first['alreadyClaimed'] && $base['resources']['pebble']===30000,'Exact room pays full award');
expectQuest(Quests::claim($base,$keeper,'C0',30000)['alreadyClaimed'] && $base['resources']['pebble']===30000,'Retry pays no second award');
$base['army']=['pokey'=>40];
$before=$base['army']; blockedQuest(fn()=>Quests::claim($base,$keeper,'UC2',30000));
expectQuest($before===$base['army'] && !isset($base['questLog']['claimed']['UC2']),'Full housing cannot lose reward');
$base['army']=[];
$base['queue']=[['type'=>'dave','quantity'=>4]];
expectQuest(indexedQuestView($base,$keeper)['UC2']['missing']['housing']>0,'Queued eggs reserve housing');
$base['queue']=[];
Quests::claim($base,$keeper,'UC2',30000);
expectQuest($base['army']['octo']===10 && !in_array('brain',$base['unlocked'],true),'Real specified creatures received');
blockedQuest(fn()=>Quests::claim($base,$keeper,'A1',30000)); blockedQuest(fn()=>Quests::claim($base,$keeper,[],30000)); blockedQuest(fn()=>Quests::claim($base,$keeper,'RB-field',30000));
$bot=$base; $bot['bot_turns']=0; unset($bot['questLog']);$before=$bot;
Quests::observe($bot); Quests::hatched($bot,'pokey',2);Quests::juiced($bot,10);Quests::harvested($bot,['goo'=>400]);Quests::battleKills($bot,[['hp'=>0,'team'=>'attacker']],'attacker');
expectQuest($bot===$before,'Bot activity never mutates journal state'); blockedQuest(fn()=>Quests::claim($bot,$keeper,'C0',30000));
expectQuest(!Quests::view($base,['is_bot'=>true])['entries'],'Bot views omit objectives');

$complete=$base; $complete['army']=[]; $complete['questLog']=[];
$complete['buildings']=[];
foreach (Catalog::buildings() as $key=>$building) { $complete['buildings'][]=['type'=>$key,'level'=>$building['maxLevel']]; }
$complete['mushrooms']=['picked'=>200,'golden'=>50];
$complete['resources']=array_fill_keys(['twig','pebble','putty','goo'],300000);
$complete['unlocked']=array_keys(Catalog::monsters());
$complete['siege']['levels']=array_fill_keys(array_keys(SiegeWorkshop::DEVICES),10);
foreach($complete['buildings'] as &$facility){$facility['hp']=$facility['max_hp']=5000;}unset($facility);
$complete['research']=['pokey'=>6]; $complete['lab']=['levels'=>['pokey'=>1]]; $complete['world']=['holdings'=>4];
$complete['champions']=array_fill_keys(['gorgo','drull','fomor'],['level'=>6]);
$complete['tribeCampaign']['hallVictories']=array_fill_keys(['Legionnaire','Kozu','Abunakki','Dreadnaut'],true);
Quests::hatched($complete,'pokey',15);Quests::harvested($complete,['goo'=>50000]);Quests::juiced($complete,5000);Quests::battleKills($complete,[['hp'=>0,'team'=>'attacker']],'attacker');
$complete['infernoRealm']=InfernoRealm::initial(time());
$realm=&$complete['infernoRealm']; $realm['buildings']=[];
foreach (InfernoRealm::catalog() as $type=>$spec) {
    $realm['buildings'][]=['id'=>count($realm['buildings'])+1,'type'=>$type,'level'=>$spec['maxLevel'],'ready_at'=>0,'hp'=>1000,'max_hp'=>1000];
}
$realm['unlocked']=array_keys(MonsterExpansion::INFERNO_LOCKERS);
$realm['bestQuestHarvest']=50000; $realm['campaign']['cleared']=array_fill_keys(range(201,213),100);
$realm['resources']=array_fill_keys(array_keys($realm['resources']),0); unset($realm);
$proof=['buildings'=>[]];$field=['mr2Outpost'=>[]];$new=['type'=>'wall','level'=>0];Quests::fieldProject($field,$new);
Quests::collectFieldProjects($proof,$field);expectQuest(empty($proof['questLog']['blocksBuilt']),'Unfinished outpost projects cannot grant construction credit');
$new['level']=1;Quests::fieldProject($field,$new);Quests::collectFieldProjects($proof,$field);Quests::collectFieldProjects($proof,$field);
expectQuest($proof['questLog']['blocksBuilt']===1 && !isset($new['journalProject']),'Each completed field construction receipt is paid once');
$complete['questLog']['referrals']=10;
Quests::constructed($complete,'wall',200);Quests::constructed($complete,'heavytrap',8);
Quests::outpostCaptured($complete,false);Quests::outpostKit($complete);
for ($n=0;$n<5;++$n) { Quests::outpostCaptured($complete,true); }
$keeper['wins']=1; $keeper['allied']=true; $keeper['giftsAccepted']=50;
$entries=indexedQuestView($complete,$keeper);
foreach($entries as $id=>$entry) {
    expectQuest($entry['target']>0 && $entry['progress']===$entry['target'],'Reachable objective '.$id);
    if ($entry['group']==='Achievements') { expectQuest($entry['status']==='earned' && !$entry['resources'] && !$entry['monsters'],'Permanent achievement '.$id);continue; }
    $copy=$complete;$copy['resources']=array_fill_keys(array_keys($copy['resources']),0);
    Quests::observe($complete);
    $copy['questLog']=$complete['questLog'];
    $award=Quests::claim($copy,$keeper,$id,Game::capacity($copy));
    expectQuest(!$award['alreadyClaimed'] && ($award['resources']||$award['monsters']||$award['device']||$award['shiny']),'Reachable collectable award '.$id);
    expectQuest(Quests::claim($copy,$keeper,$id,Game::capacity($copy))['alreadyClaimed'],'Idempotent award '.$id);
}
echo "Quest journal: $checks checks passed.\n";
