<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Catalog.php';
require_once dirname(__DIR__) . '/app/Bots.php';
require_once dirname(__DIR__) . '/app/Game.php';
$checks = 0;
function expectBot(bool $condition, string $message): void {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
}
$profiles = [];
$layoutSignatures = [];
$championChoices=[];
$townhall = ['id'=>1,'type'=>'townhall','x'=>16.0,'y'=>16.0,'level'=>1,'size'=>3.4];
foreach (range(1, 1000) as $id) {
    $profile = Bots::profile($id);
    $profiles[$profile] = ($profiles[$profile] ?? 0) + 1;
    expectBot(Bots::profile($id) === $profile, 'Personality must persist by bot ID');
    $championChoices[Bots::championChoice($id)]=true;
    expectBot(Bots::championChoice($id)===Bots::championChoice($id),'Champion preference persists by ID');
    $base = ['buildings'=>[$townhall]];
    $points = Bots::placements($id, 'twig', 0, $base, Catalog::buildings()['twig']);
    expectBot(count($points) > 0, 'Every bot needs a legal expansion candidate');
    $layoutSignatures[json_encode($points)] = true;
    foreach ($points as [$x, $y]) {
        expectBot($x >= 2 && $y >= 2 && $x <= 30 && $y <= 30, 'Placement must stay inside yard');
        expectBot(abs($x - 16) >= 2.95 || abs($y - 16) >= 2.95, 'Placement cannot overlap Town Hall');
    }
    $monsters = Bots::monsterChoices($profile, Bots::seed($id, 100));
    expectBot(count(array_unique($monsters)) === count(Catalog::homeMonsters()), 'Every surface species is eligible for home bot planning');
    foreach ($monsters as $type) {
        expectBot(isset(Catalog::monsters()[$type]), 'Planner monster must exist');
    }
}
expectBot(count($profiles) === 4, 'All four personalities must occur');
expectBot(count($championChoices)===3,'The persistent population uses all three champions');
expectBot(count($layoutSignatures) >= 250, 'Bot layouts must vary across IDs');
expectBot(Bots::buildPlan('guardian')['wall'] > Bots::buildPlan('builder')['wall'], 'Guardians should invest in walls');
expectBot(Bots::buildPlan('raider')['housing'] > Bots::buildPlan('builder')['housing'], 'Raiders should invest in armies');
expectBot(Bots::townhall(['buildings'=>[['type'=>'townhall','level'=>3]]]) === 3, 'Town Hall matching must use level');
foreach (['builder','raider','guardian','collector'] as $profile) {
    $plan=Bots::buildPlan($profile);
    $early = Bots::buildPlan($profile, 3);
    $mature = Bots::buildPlan($profile, 4);
    expectBot($early['aerial'] === 0 && $early['heavytrap'] === 0, 'Young bots spend on available construction');
    expectBot($mature['aerial'] === 1 && $mature['heavytrap'] <= 4, 'A mature bot respects its first specialist defense limits');
    expectBot(array_search('planner', array_keys($plan), true) < array_search('aerial', array_keys($plan), true), 'Core facilities take priority over air defense');
    expectBot(array_key_first($plan)==='flinger','Every personality prioritizes a missing Flinger');
    foreach (['flinger','locker','juicer','laser','bunker','trap','championcage','championchamber','lab','catapult','aerial','heavytrap'] as $type) {
        expectBot(($plan[$type] ?? 0)>0,'Every personality builds the expanded catalog');
    }
}
$flinger=['id'=>2,'type'=>'flinger','x'=>10,'y'=>10,'level'=>1,'hp'=>100,'max_hp'=>100,'ready_at'=>0];
$heavy=['buildings'=>[$townhall,$flinger],'army'=>['dave'=>1],'queue'=>[],'unlocked'=>['pokey','octo','fink','ichi','bandito','dave']];
expectBot(Bots::raidReady($heavy),'One heavy offensive monster is an eligible army');
$heavy['buildings'][1]['hp']=0;
expectBot(!Bots::raidReady($heavy),'Destroyed Flinger prevents raids');
$heavy['buildings'][1]['hp']=100;$heavy['buildings'][1]['ready_at']=time()+60;
expectBot(!Bots::raidReady($heavy),'Unfinished Flinger prevents raids');
$heavy['buildings'][1]['ready_at']=0;$heavy['buildings'][1]['level']=2;$heavy['army']=['zafreeti'=>10];
expectBot(!Bots::raidReady($heavy),'Healer-only armies cannot start bot invasions');
$locked=['buildings'=>[array_merge($townhall,['level'=>5]),$flinger],'army'=>['pokey'=>25],'queue'=>[],'unlocked'=>['pokey','octo','fink','ichi','bandito','dave']];
expectBot(!in_array('bolt',Bots::trainingChoices($locked,'raider',42),true),'Locked new species cannot be trained');
$locked['unlocked'][]='bolt';
expectBot(in_array('bolt',Bots::trainingChoices($locked,'raider',42),true),'Earned unlock enters training choices');
expectBot(Bots::trainingChoices($locked,'raider',42)[0]!=='pokey','Training favors absent species over a surplus');
$bunker=['id'=>3,'type'=>'bunker','x'=>20,'y'=>20,'level'=>1,'hp'=>100,'max_hp'=>100,'ready_at'=>0];
$garrisonBase=['buildings'=>[$townhall,$flinger,$bunker],'army'=>['pokey'=>20,'ichi'=>1],'queue'=>[],'garrison'=>[]];
$garrison=Bots::garrisonPlan($garrisonBase,'guardian');
expectBot($garrison!==null && $garrison['id']===3,'An empty bunker gets a legal owned-troop plan');
expectBot($garrison['quantity']<=($garrisonBase['army'][$garrison['type']] ?? 0),'Garrison cannot invent monsters');
$garrisonBase['army']=['pokey'=>8];
expectBot(Bots::garrisonPlan($garrisonBase,'guardian')===null,'Bunker allocation preserves offensive reserve');
$garrisonBase['army']=['pokey'=>20];$garrisonBase['garrison']=['3'=>['pokey'=>10]];
expectBot(Bots::garrisonPlan($garrisonBase,'guardian')===null,'Full bunker cannot overfill');
$housing=['id'=>4,'type'=>'housing','level'=>1,'hp'=>100,'ready_at'=>0];
$juicer=['id'=>5,'type'=>'juicer','level'=>1,'hp'=>100,'ready_at'=>0];
$juicing=['buildings'=>[$townhall,$flinger,$housing,$juicer],'army'=>['pokey'=>40],'queue'=>[],'unlocked'=>['pokey','octo','fink','bolt']];
expectBot(Bots::juicePlan($juicing,'builder',8)===['type'=>'pokey','quantity'=>1],'Full housing permits a small surplus recycle');
$juicing['army']['pokey']=20;
expectBot(Bots::juicePlan($juicing,'builder',8)===null,'Spare housing does not waste troops in Juicer');
$cage=['id'=>6,'type'=>'championcage','level'=>1,'hp'=>100,'max_hp'=>100,'ready_at'=>0];
$chamber=['id'=>7,'type'=>'championchamber','level'=>1,'hp'=>100,'max_hp'=>100,'ready_at'=>0];
$hatchery=['id'=>8,'type'=>'hatchery','level'=>1,'hp'=>100,'max_hp'=>100,'ready_at'=>0];
$championBase=['buildings'=>[array_merge($townhall,['level'=>4]),$flinger,$housing,$cage,$chamber,$hatchery,$juicer,$bunker],'resources'=>['goo'=>100000],'army'=>['pokey'=>40],'queue'=>[],'garrison'=>[],'unlocked'=>array_keys(Catalog::monsters()),'champions'=>[],'activeChampion'=>null];
expectBot(Bots::championPlan($championBase,8,time())===['action'=>'championRaise','input'=>['type'=>Bots::championChoice(8)]],'Completed Cage produces normal raise action');
$championBase['buildings'][3]['hp']=0;
expectBot(Bots::championPlan($championBase,8,time())===null,'Ruined Cage cannot raise a champion');
$championBase['buildings'][3]['hp']=100;$championBase['buildings'][3]['ready_at']=time()+30;
expectBot(Bots::championPlan($championBase,8,time())===null,'Unfinished Cage cannot raise a champion');
$championBase['buildings'][3]['ready_at']=0;
$championBase['activeChampion']='drull';
$championBase['champions']['drull']=['level'=>1,'hp'=>Catalog::championStats('drull',1)['hp'],'feeds'=>0,'next_feed_at'=>0,'battle_id'=>0,'frozen'=>false];
expectBot(Bots::championRecipe($championBase)===['pokey'=>30],'Drull preserves source recipe');
expectBot(Bots::championReserve($championBase)===['pokey'=>30],'Owned meal is reserved from raids');
expectBot(Bots::championPlan($championBase,8,time())===['action'=>'championFeed','input'=>[]],'Complete meal uses paid player feed action');
$championBase['champions']['drull']['next_feed_at']=time()+300;
expectBot(Bots::championPlan($championBase,8,time())===null,'Feeding cannot bypass cooldown');
$championBase['champions']['drull']['next_feed_at']=0;$championBase['army']['pokey']=29;
expectBot(Bots::championPlan($championBase,8,time())===null,'Incomplete recipe cannot feed');
expectBot(Bots::championReserve($championBase)===['pokey'=>29],'Reserved meal cannot exceed actual owned stock');
expectBot(Bots::championTrainingPlan($championBase)===['type'=>'pokey','quantity'=>1],'Only the actual food deficit is trained');
$championBase['queue']=[['type'=>'pokey','quantity'=>1]];
expectBot(Bots::championTrainingPlan($championBase)===null,'Queued food counts once without duplicate training');
$championBase['queue']=[];$championBase['army']['pokey']=20;
expectBot(Bots::championTrainingPlan($championBase)===['type'=>'pokey','quantity'=>6],'A turn trains bounded food batches');
$championBase['resources']['goo']=250;
expectBot(Bots::championTrainingPlan($championBase)===['type'=>'pokey','quantity'=>1],'Food plan obeys actual goo balance');
$championBase['resources']['goo']=0;
expectBot(Bots::championTrainingPlan($championBase)===null,'No free champion food for poor bots');
$championBase['resources']['goo']=100000;$championBase['army']['pokey']=30;
expectBot(Bots::garrisonPlan($championBase,'guardian')===null,'Garrison cannot take a complete reserved meal');
$championBase['army']['pokey']=40;$championBase['champions']['drull']['level']=3;
expectBot(Bots::championRecipe($championBase)===['bandito'=>50],'Large level3 recipe exceeds former42unit army ceiling');
expectBot(Bots::juicePlan($championBase,'builder',8)===['type'=>'pokey','quantity'=>2],'Full housing frees space for a large next meal');
$championBase['army']=['bandito'=>10];
expectBot(Bots::championTrainingPlan($championBase)===['type'=>'bandito','quantity'=>6],'Food training is independent of generic army variety');
$championBase['unlocked']=array_values(array_diff($championBase['unlocked'],['bandito']));
expectBot(Bots::championTrainingPlan($championBase)===null,'Food training never bypasses species unlock');
$championBase['unlocked']=array_keys(Catalog::monsters());
$championBase['champions']['drull']['level']=1;$championBase['champions']['drull']['hp']=Catalog::championStats('drull',1)['hp'];$championBase['army']=['pokey'=>30];
expectBot(Bots::raidReady($championBase),'Healthy offensive champion can raid while preserving all food');
$championBase['champions']['drull']['hp']=Catalog::championStats('drull',1)['hp']*0.64;
expectBot(!Bots::raidReady($championBase),'Injured champion waits for at least65percent recovery');
$championBase['champions']['drull']['hp']=Catalog::championStats('drull',1)['hp'];$championBase['champions']['drull']['battle_id']=1;
expectBot(!Bots::raidReady($championBase),'Locked champion never raids twice');
$championBase['champions']['drull']['battle_id']=0;$championBase['champions']['drull']['level']=6;
expectBot(Bots::championRecipe($championBase)===['fang'=>30],'Maximum-level champion keeps maintenance recipe');
$championBase['activeChampion']=null;$championBase['champions']['drull']['frozen']=true;
expectBot(Bots::championPlan($championBase,8,time())===['action'=>'championThaw','input'=>['type'=>'drull']],'Existing stored champion is thawed without replacing evolution');
$championBase['buildings'][4]['hp']=90;
expectBot(Bots::championPlan($championBase,8,time())===null,'Damaged Chamber cannot thaw');
$championBase['buildings'][4]['hp']=100;$championBase['activeChampion']='fomor';
$championBase['champions']['fomor']=['level'=>1,'hp'=>Catalog::championStats('fomor',1)['hp'],'feeds'=>0,'next_feed_at'=>0,'battle_id'=>0,'frozen'=>false];
$championBase['army']=['bolt'=>20];
expectBot(!Bots::raidReady($championBase),'Fomor does not raid without an escort after reserving food');
$championBase['army']['dave']=1;
expectBot(Bots::raidReady($championBase),'Fomor raids with an actual offensive escort');
$developed=Game::initialBase(8,true);
$lockerPoint=Bots::placements(8,'locker',0,$developed,Catalog::buildings()['locker'])[0];
$developed['buildings'][]=['id'=>99,'type'=>'locker','x'=>$lockerPoint[0],'y'=>$lockerPoint[1],'level'=>4];
$cagePoints=Bots::placements(8,'championcage',0,$developed,Catalog::buildings()['championcage']);
expectBot(count($cagePoints)>0,'Developed yard finds champion facility space beyond a blocked preferred ring');
foreach($cagePoints as [$x,$y]){
    $clear=true;
    foreach($developed['buildings'] as $building){
        $distance=(Catalog::buildings()['championcage']['size']+Catalog::buildings()[$building['type']]['size'])/2+0.25;
        if(abs($x-$building['x'])<$distance && abs($y-$building['y'])<$distance){$clear=false;break;}
    }
    expectBot($clear,'Fallback Cage candidates leave existing saved structures clear');
}
// Lab studies never bypass ordinary discoveries, Academy levels or paid costs.
$lab=['id'=>9,'type'=>'lab','level'=>1,'hp'=>100,'max_hp'=>100,'ready_at'=>0];
$academy=['id'=>10,'type'=>'academy','level'=>3,'hp'=>100,'max_hp'=>100,'ready_at'=>0];
$labBase=['buildings'=>[array_merge($townhall,['level'=>4]),$lab,$academy],
    'resources'=>['twig'=>0,'pebble'=>0,'putty'=>100000,'goo'=>100000],
    'army'=>['fink'=>10],'queue'=>[],'garrison'=>[],'research'=>['fink'=>1],
    'researchQueue'=>[],'lab'=>['levels'=>[],'queue'=>null],'unlocked'=>['fink']];
expectBot(Bots::labResearchPlan($labBase,'builder',4)===null,'A Lab does not bypass Academy species prerequisite');
expectBot(Bots::academyResearchPlan($labBase,'builder',4)===['type'=>'fink'],'Academy prepares a missing Lab prerequisite');
$labBase['research']['fink']=2;
expectBot(Bots::labResearchPlan($labBase,'builder',4)===['type'=>'fink'],'Eligible Lab study uses the regular paid action');
$labBase['resources']['putty']=Catalog::labStats('fink',1)['cost']['putty']-1;
expectBot(Bots::labResearchPlan($labBase,'builder',4)===null,'Lab study needs the full actual putty balance');
$labBase['resources']['putty']=100000;$labBase['lab']['queue']=['type'=>'fink','target_level'=>1,'ready_at'=>time()+60];
expectBot(Bots::labResearchPlan($labBase,'builder',4)===null,'A running Lab queue cannot be duplicated');
$labBase['lab']['queue']=null;$labBase['buildings'][1]['hp']=0;
expectBot(Bots::labResearchPlan($labBase,'builder',4)===null,'A destroyed Lab cannot start a study');
$labBase['buildings'][1]['hp']=100;$labBase['buildings'][1]['ready_at']=time()+60;
expectBot(Bots::labResearchPlan($labBase,'builder',4)===null,'An unfinished Lab cannot start a study');
$labBase['buildings'][1]['ready_at']=0;$labBase['lab']['levels']['fink']=1;$labBase['research']['fink']=3;
expectBot(Bots::labResearchPlan($labBase,'builder',4)===null,'Second-tier study requires an upgraded Lab');
$labBase['buildings'][1]['level']=2;
expectBot(Bots::labResearchPlan($labBase,'builder',4)===['type'=>'fink'],'Earned Lab and Academy levels open tier2');
$labBase['lab']['levels']['fink']=2;$labBase['research']['fink']=4;$labBase['buildings'][1]['level']=3;
expectBot(Bots::labResearchPlan($labBase,'builder',4)===['type'=>'fink'],'A third tier remains reachable through normal requirements');
$labBase['lab']['levels']['fink']=3;
expectBot(Bots::labResearchPlan($labBase,'builder',4)===null,'Maximum Lab tiers are not researched repeatedly');
$labBase['lab']['levels']['fink']=0;$labBase['unlocked']=[];
expectBot(Bots::labResearchPlan($labBase,'builder',4)===null,'Locked species cannot receive Lab powers');
$labBase['unlocked']=['pokey'];$labBase['research']=['pokey'=>6];
expectBot(Bots::labResearchPlan($labBase,'builder',4)===null,'Species without a source ability never invent a Lab power');
$labBase['unlocked']=['projectx'];$labBase['research']=['projectx'=>2];
expectBot(Bots::labResearchPlan($labBase,'raider',4)===['type'=>'projectx'],'Project X participates in Lab progression after unlock');
$labBase['buildings'][0]['level']=3;
expectBot(Bots::labResearchPlan($labBase,'raider',4)===null,'Project X respects its Town Hall gate');
$labBase['buildings'][0]['level']=4;$labBase['researchQueue']=[['type'=>'projectx','target_level'=>3]];
expectBot(Bots::academyResearchPlan($labBase,'raider',4)===null,'Academy queue is not overwritten by Lab preparation');
$labBase['researchQueue']=[];
$labBase['activeChampion']='drull';$labBase['champions']=['drull'=>['level'=>1,'frozen'=>false]];
$labBase['army']=['pokey'=>20];$labBase['queue']=[['type'=>'pokey','quantity'=>2]];
expectBot(Bots::championFoodGooReserve($labBase)===2000,'Food budget counts owned and already-paid queued ingredients once');
$researchCost=Catalog::monsters()['projectx']['cost']*4*2;
$labBase['resources']['goo']=$researchCost+1999;
expectBot(Bots::academyResearchPlan($labBase,'raider',4)===null,'Optional Academy research preserves the next champion meal budget');
$labBase['resources']['goo']=$researchCost+2000;
expectBot(Bots::academyResearchPlan($labBase,'raider',4)===['type'=>'projectx'],'Research resumes when the meal reserve remains affordable');
$labBase['champions']['drull']['frozen']=true;
expectBot(Bots::championFoodGooReserve($labBase)===0,'Frozen champions do not hoard a food budget');
foreach(['lab','catapult','aerial','heavytrap'] as $facility){
    $points=Bots::placements(8,$facility,0,$developed,Catalog::buildings()[$facility]);
    expectBot(count($points)>0,'Developed yard finds space for '.$facility);
    foreach($points as [$x,$y]){
        $clear=true;
        foreach($developed['buildings'] as $building){
            $distance=(Catalog::buildings()[$facility]['size']+Catalog::buildings()[$building['type']]['size'])/2+0.25;
            if(abs($x-$building['x'])<$distance && abs($y-$building['y'])<$distance){$clear=false;break;}
        }
        expectBot($clear,'Specialist facility placement preserves existing buildings');
    }
}
echo "PASS {$checks} bot planning checks; ", count($layoutSignatures), " layout variations across 1000 IDs.\n";
