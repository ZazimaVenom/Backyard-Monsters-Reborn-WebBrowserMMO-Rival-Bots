<?php
declare(strict_types=1);
require_once __DIR__.'/TownHall.php';
require_once __DIR__.'/Flinger.php';
require_once __DIR__.'/ExpandedDefenses.php';
require_once __DIR__.'/SpurtzRewards.php';
require_once __DIR__.'/YardUtilities.php';
require_once __DIR__.'/MonsterExpansion.php';
require_once __DIR__.'/MonsterMadness.php';
require_once __DIR__.'/KrallenHunt.php';
require_once __DIR__.'/InfernoMonsters.php';

require_once __DIR__.'/SiegeWorkshop.php';

final class Catalog
{
    public static function buildings(): array
    {
        static $catalog;
        if ($catalog !== null) { return $catalog; }
        $rows = [
            'radiotower' => ['Radio Tower','Tune in to local attack alerts and news about your yard.',20,20,20,0,1,3400,1.6,1,1],
            'townhall' => ['Town Hall','The heart of your yard. Upgrade it to unlock advanced monsters and defenses.',2400,1600,0,0,90,4800,3.4,1,5],
            'hatchery' => ['Hatchery','Hatch monsters from goo. Every hatchery adds a parallel training lane.',600,400,0,250,25,1800,2.4,1,5],
            'housing' => ['Monster Housing','A home for your army. Each level holds more monster housing space.',600,600,0,0,25,2200,2.4,1,5],
            'twig' => ['Twig Snapper','Harvests twigs while you are away. Collect before its bin fills.',250,120,0,0,15,1200,2.0,1,5],
            'pebble' => ['Pebble Shiner','Quarries the pebbles needed for your buildings and fortifications.',120,250,0,0,15,1400,2.0,1,5],
            'putty' => ['Putty Squisher','Refines putty for advanced construction and powerful upgrades.',700,700,0,0,30,1600,2.2,2,5],
            'goo' => ['Goo Factory','Produces the goo that brings your monster army to life.',350,350,0,0,20,1400,2.2,1,5],
            'storage' => ['Storage Silo','Expands the protected capacity of all four resources.',700,900,0,0,25,2400,2.2,1,5],
            'sniper' => ['Sniper Tower','A long-range defense that picks off individual attackers.',650,800,0,0,30,2000,1.8,1,5],
            'cannon' => ['Cannon Tower','Lobs explosive shells at tightly packed invading monsters.',1100,1500,250,0,45,2800,2.0,2,5],
            'tesla' => ['Tesla Tower','Crackling arcs strike enemies within its short, deadly radius.',2200,2500,800,0,60,3400,2.2,3,5],
            'wall' => ['Fortification','Durable walls obstruct ground attackers and redirect their paths.',80,130,0,0,5,1800,0.8,1,5],
            'academy' => ['Monster Academy','Research permanent upgrades for each species in your army.',1800,1600,300,500,50,2400,2.6,2,5],
            'baiter' => ['Wild Monster Baiter','Lure a custom wave of wild monsters to test a copy of your defenses. Sound the siren to end the drill.',250,250,150,0,60,1000,2.6,4,7],
            'flinger' => ['Monster Flinger','Launch a bucket of monsters into an enemy yard. Upgrades increase bucket capacity.',1000,1000,0,0,30,2000,2.6,1,5],
            'locker' => ['Monster Locker','Unlock new species before hatching them. Higher levels reveal advanced monsters.',1500,1500,0,500,45,2000,2.6,1,5],
            'juicer' => ['Monster Juicer','Recycle spare monsters into 30% of their original goo cost.',800,1000,0,300,35,1800,2.2,2,5],
            'laser' => ['Laser Tower','A focused long-range beam tears through ground attackers. Flying monsters can bypass it.',3500,4000,1000,0,90,3200,2.2,3,5],
            'aerial' => ['Aerial Defense Tower','Tracks flying attackers and fires a volley of flak. Nearby flyers take splash damage.',2150,2800,625,0,60,3000,2.2,4,5],
            'heavytrap' => ['Heavy Trap','Hidden until a Crabatron, Project X, D.A.V.E. or ground champion steps on it. The blast also hits nearby flyers at half strength.',500,500,500,0,5,10,1.0,4,1],
            'bunker' => ['Monster Bunker','Station monsters in a defensive garrison. They emerge when invaders approach.',1800,2200,400,0,60,3500,2.8,2,5],
            'trap' => ['Booby Trap','A concealed explosive for ground attackers. Repair it to rearm after detonation.',250,300,50,0,10,200,1.0,2,5],
            'lab' => ['Monster Lab','Research special monster abilities. Each study uses putty and unlocks a new battlefield tactic.',5000,5000,1500,0,120,3600,2.8,3,3],
            'catapult' => ['Catapult','Launch resource bombs during invasions: burning twigs, crushing pebbles and empowering putty.',4000,4500,1000,0,100,4000,2.8,3,4],
            'championcage' => ['Champion Cage','Raise a unique champion, feed it monsters and lead it into battle. Injured champions recover here.',6000,6000,2000,0,120,6000,3.2,4,5],
            'championchamber' => ['Champion Chamber','Freeze a healthy, fed champion and raise another. Thaw a stored champion when the chamber is fully repaired.',8000,9000,3000,0,150,8000,3.2,4,5],
            'maproom' => ['Map Room','Plan territory invasions. Each level supports two resource outposts or strongholds and six separate Fortification sites.',6000,6000,2000,0,120,4500,3.0,3,3],
            'planner' => ['Yard Planner','Design your whole backyard before moving a single building. Save two layouts and apply them together.',2500,2500,0,0,144,3200,2.6,3,1],
            'mr2core' => ['Regional Outpost','Your field headquarters. The core anchors this yard and takes damage during invasions.',0,0,0,0,0,200000,3.4,1,1],
            'outpost' => ['Resource Outpost','A captured territory that supplies all four resources and expands storage.',0,0,0,0,0,1,3.0,1,5],
            'stronghold' => ['Stronghold','A fortified territory that strengthens nearby attacking monsters and defending towers.',0,0,0,0,0,6500,3.4,1,3],
            'outpostdefender' => ['Outpost Defender','The targetable core of a Fortification site. Capture the site to protect adjacent homes and territories owned by the same keeper.',0,0,0,0,0,5000,3.0,1,3],
        ];
        $result = [];
        foreach ($rows as $key => $r) {
            $result[$key] = ['name'=>$r[0],'description'=>$r[1],'cost'=>['twig'=>$r[2],'pebble'=>$r[3],'putty'=>$r[4],'goo'=>$r[5]],'buildTime'=>$r[6],'hp'=>$r[7],'size'=>$r[8],'unlockLevel'=>$r[9],'maxLevel'=>$r[10],'art'=>$key.'.png','canFortify'=>in_array($key,['storage','townhall','cannon','sniper','laser','tesla','aerial'],true)];
        }
        foreach (['twig'=>5,'pebble'=>5,'putty'=>3,'goo'=>7] as $type=>$rate) {
            $result[$type] += ['production'=>$rate,'resource'=>$type,'capacity'=>4000];
        }
        $result['radiotower'] += ['originalId'=>113,'limitByTownHall'=>[0,1,1,1,1,1]];
        $result['storage']['capacity'] = 30000;
        $result['housing']['capacity'] = 400;
        $result['sniper'] += ['damage'=>85,'range'=>8.0,'attackInterval'=>1.1,'splash'=>0,'targetMode'=>'both'];
        $result['cannon'] += ['damage'=>180,'range'=>7.0,'attackInterval'=>2.3,'splash'=>1.8,'targetMode'=>'ground'];
        $result['tesla'] += ['damage'=>140,'range'=>4.8,'attackInterval'=>0.8,'splash'=>1.0,'targetMode'=>'both'];
        $result['flinger'] += ['capacityByLevel'=>Flinger::CAPACITIES,'cooldown'=>5];
        $result['locker']['unlockSlots']=1;
        $result['juicer']['refundRate']=0.30;
        $result['laser'] += ['damage'=>220,'range'=>8.5,'attackInterval'=>1.4,'splash'=>0,'targetMode'=>'ground'];
        $result['aerial'] += [
            'originalId'=>115, 'limitByTownHall'=>[0,0,0,0,1,2],
            'damage'=>100, 'range'=>7.5, 'splash'=>1.8, 'targetMode'=>'air',
            'attackInterval'=>0.25, 'reload'=>2.0,
            'volleys'=>[4,4,6,8,10], 'ranges'=>[7.5,8,8.5,9,9.5],
            'splashes'=>[1.8,1.85,1.9,1.95,2], 'shotDamage'=>[100,125,125,125,150],
            'healthByLevel'=>[3000,4400,6000,9600,12000],
        ];
        $result['heavytrap'] += [
            'originalId'=>117, 'limitByTownHall'=>[0,0,0,0,4,6], 'damage'=>4000,
            'range'=>0.7, 'splash'=>3.0, 'targetMode'=>'ground',
            'triggerTypes'=>['crabatron','projectx','dave','gorgo','drull','fomor'],
        ];
        $result['bunker'] += ['capacity'=>100,'range'=>6];
        $result['trap'] += ['damage'=>1200,'range'=>1.25,'splash'=>2.3,'targetMode'=>'ground'];
        $result['championchamber']['requiresBuilding']='championcage';
        $result['catapult']['requiresBuilding']='flinger';
        $result['maproom']['holdingCapacity']=2;
        $result['planner']['originalId']=10;
        $result['baiter'] += ['originalId'=>19, 'requiresBuilding'=>'locker', 'limitByTownHall'=>[0,0,0,0,1,1]];
        // Source resource prices /100 and durations /300. Hall gates above 5 share the local maximum.
        $baiterLevels = [
            [250,250,150,60,1000,4,1], [10000,10000,5000,120,1500,4,2],
            [20000,20000,10000,240,2250,4,3], [40000,40000,20000,480,3375,5,4],
            [60000,60000,40000,960,5000,5,4], [100000,100000,60000,1920,7500,5,4],
            [160000,160000,100000,3840,12000,5,4],
        ];
        foreach ($baiterLevels as $index => [$twigs,$pebbles,$putty,$seconds,$hp,$hall,$locker]) {
            $result['baiter']['levels'][$index + 1] = [
                'cost'=>['twig'=>$twigs,'pebble'=>$pebbles,'putty'=>$putty,'goo'=>0],
                'buildTime'=>$seconds, 'hp'=>$hp, 'townHall'=>$hall, 'locker'=>$locker,
            ];
        }
        $result['mr2core']+=['siteOnly'=>true,'originalId'=>112];
        $result['outpost']+=['siteOnly'=>true,'untargetable'=>true];
        $result['stronghold']+=['siteOnly'=>true,'damage'=>160,'range'=>8.0,'attackInterval'=>1.6,'splash'=>1.2,'targetMode'=>'both'];
        $result['outpostdefender']+=['siteOnly'=>true,'originalId'=>140];
        $catalog=$result + SiegeWorkshop::buildings() + ExpandedDefenses::buildings() + YardUtilities::buildings();
        TownHall::apply($catalog);
        return $catalog;
    }

    public static function isWall(string $type): bool
    {
        return $type === 'wall' || $type === 'stonewall';
    }

    public static function isTrap(string $type): bool
    {
        return $type === 'trap' || $type === 'heavytrap';
    }

    public static function visibleToRivals(array $building): bool
    {
        return !self::isTrap($building['type']) || (($building['level'] ?? 0) > 0 && $building['hp'] <= 0);
    }

    /** Source-eligible structures; costs /100 and durations /300 fit this economy. */
    public static function fortificationStats(string $type,int $tier): array
    {
        $rows=[
            'storage'=>[[59375,29687,0,60750],[118750,59375,0,91120],[637500,518750,0,136680],[1475000,1237500,0,205030]],
            'townhall'=>[[500000,100000,50000,14400],[1000000,1000000,500000,57600],[5000000,5000000,2000000,172800],[10000000,10000000,5000000,345600]],
            'cannon'=>[[50000,37500,12500,8100],[250000,187500,62500,24300],[1250000,937500,312500,72900],[6250000,4687500,1562500,172800]],
            'sniper'=>[[37500,50000,12500,18000],[187500,250000,62500,43200],[937500,1250000,312500,86400],[4687500,6250000,1562500,172800]],
            'railgun'=>[[2000000,2400000,1600000,43200],[2600000,3320000,1880000,86400],[4480000,4776000,2184000,172800],[9664000,9996800,4331200,345600]],
            'laser'=>[[500000,250000,100000,18000],[1000000,500000,200000,86400],[2000000,1000000,400000,172800],[4000000,2000000,800000,259200]],
            'aerial'=>[[215000,280000,62500,18000],[850000,1200000,250000,86400],[2750000,3400000,750000,172800],[5750000,5200000,1250000,345600]],
            'tesla'=>[[187500,250000,62500,18000],[750000,1000000,250000,86400],[2250000,3000000,750000,172800],[5250000,5000000,1250000,345600]],
        ];
        if(!isset($rows[$type])||$tier<1||$tier>4){throw new RuntimeException('Choose an eligible building and fortification tier.');}
        [$twig,$pebble,$putty,$seconds]=$rows[$type][$tier-1];
        return ['cost'=>['twig'=>(int)ceil($twig/100),'pebble'=>(int)ceil($pebble/100),'putty'=>(int)ceil($putty/100),'goo'=>0],
            'buildTime'=>(int)ceil($seconds/300),'requiredTownHall'=>$type==='railgun'?5:$tier+1,'reduction'=>self::fortificationReduction($tier)];
    }

    public static function fortificationReduction(int $tier): float
    {
        return [0.0,0.2,0.3,0.4,0.5][max(0,min(4,$tier))];
    }

    public static function homeMonsters(): array
    {
        static $rows;
        return $rows ??= array_filter(self::monsters(),static fn(array $spec):bool=>($spec['origin']??'surface')==='surface');
    }

    public static function monsters(): array
    {
        static $catalog;
        if ($catalog!==null) { return $catalog; }
        $result = [
            'pokey'=>['name'=>'Pokey','description'=>'A nimble, spiky all-rounder. Cheap to hatch and strong in a pack.','cost'=>250,'trainTime'=>15,'hp'=>200,'damage'=>60,'speed'=>1.2,'range'=>0.6,'size'=>10,'unlockLevel'=>1,'target'=>'any','attackInterval'=>1.0,'art'=>'pokey.png'],
            'octo'=>['name'=>'Octo-Ooze','description'=>'A tough tower hunter. Soaks up punishment while your swarm closes in.','cost'=>500,'trainTime'=>20,'hp'=>1000,'damage'=>35,'speed'=>1.0,'range'=>0.7,'size'=>10,'unlockLevel'=>1,'target'=>'defense','attackInterval'=>1.1,'art'=>'octo.png'],
            'fink'=>['name'=>'Fink','description'=>'A hard-hitting raider with a fragile frame. Keep it behind a tank.','cost'=>1500,'trainTime'=>45,'hp'=>200,'damage'=>300,'speed'=>1.3,'range'=>0.7,'size'=>20,'unlockLevel'=>1,'target'=>'any','attackInterval'=>1.15,'art'=>'fink.png'],
            'ichi'=>['name'=>'Ichi','description'=>'An armored bruiser built to crack fortified yards.','cost'=>2200,'trainTime'=>60,'hp'=>2500,'damage'=>130,'speed'=>0.65,'range'=>0.8,'size'=>40,'unlockLevel'=>2,'target'=>'defense','attackInterval'=>1.2,'art'=>'ichi.png'],
            'bandito'=>['name'=>'Bandito','description'=>'A fast resource raider. Heads for factories and storage first.','cost'=>1200,'trainTime'=>35,'hp'=>550,'damage'=>120,'speed'=>1.8,'range'=>0.65,'size'=>15,'unlockLevel'=>2,'target'=>'resource','attackInterval'=>0.8,'art'=>'bandito.png'],
            'dave'=>['name'=>'D.A.V.E.','description'=>'A towering siege monster that brings devastating strength to your army.','cost'=>6000,'trainTime'=>120,'hp'=>5000,'damage'=>450,'speed'=>0.55,'range'=>1.2,'size'=>100,'unlockLevel'=>3,'target'=>'any','attackInterval'=>1.8,'art'=>'dave.png'],
            'bolt'=>['name'=>'Bolt','description'=>'A lightning-fast resource raider. Fragile, cheap and quick to hatch.','cost'=>350,'trainTime'=>23,'hp'=>150,'damage'=>35,'speed'=>2.5,'range'=>0.65,'size'=>15,'unlockLevel'=>1,'lockerLevel'=>1,'unlockCost'=>['goo'=>2000,'putty'=>0],'unlockTime'=>45,'target'=>'resource','attackInterval'=>0.65,'art'=>'bolt.png'],
            'eyera'=>['name'=>'Eye-Ra','description'=>'A living bomb that rushes fortifications and explodes in a wide blast.','cost'=>5000,'trainTime'=>90,'hp'=>600,'damage'=>2400,'speed'=>1.5,'range'=>0.8,'size'=>60,'unlockLevel'=>2,'lockerLevel'=>2,'unlockCost'=>['goo'=>6000,'putty'=>500],'unlockTime'=>90,'target'=>'wall','attackInterval'=>1.0,'ability'=>'explode','splash'=>2.4,'art'=>'eyera.png'],
            'fang'=>['name'=>'Fang','description'=>'A ferocious heavy hitter. A dangerous strike hides behind a fragile body.','cost'=>3500,'trainTime'=>75,'hp'=>400,'damage'=>600,'speed'=>1.1,'range'=>0.75,'size'=>30,'unlockLevel'=>3,'lockerLevel'=>3,'unlockCost'=>['goo'=>8000,'putty'=>1000],'unlockTime'=>120,'target'=>'any','attackInterval'=>1.1,'art'=>'fang.png'],
            'wormzer'=>['name'=>'Wormzer','description'=>'Burrows underneath walls, then emerges to tear into the enemy yard.','cost'=>4500,'trainTime'=>90,'hp'=>900,'damage'=>300,'speed'=>1.8,'range'=>0.8,'size'=>70,'unlockLevel'=>3,'lockerLevel'=>3,'unlockCost'=>['goo'=>10000,'putty'=>1500],'unlockTime'=>150,'target'=>'any','attackInterval'=>1.1,'movement'=>'burrow','art'=>'wormzer.png'],
            'teratorn'=>['name'=>'Teratorn','description'=>'An airborne ranged attacker that flies over walls and ground-only defenses.','cost'=>6000,'trainTime'=>120,'hp'=>1600,'damage'=>250,'speed'=>1.3,'range'=>3.0,'size'=>70,'unlockLevel'=>4,'lockerLevel'=>4,'unlockCost'=>['goo'=>14000,'putty'=>2000],'unlockTime'=>180,'target'=>'any','attackInterval'=>1.6,'movement'=>'air','art'=>'teratorn.png'],
            'zafreeti'=>['name'=>'Zafreeti','description'=>'A floating healer that restores nearby allies. Bring attackers to protect it.','cost'=>8000,'trainTime'=>150,'hp'=>4000,'damage'=>0,'speed'=>0.85,'range'=>4.0,'size'=>150,'unlockLevel'=>4,'lockerLevel'=>4,'unlockCost'=>['goo'=>18000,'putty'=>3000],'unlockTime'=>210,'target'=>'ally','attackInterval'=>1.1,'movement'=>'air','ability'=>'heal','heal'=>180,'art'=>'zafreeti.png'],
        ];
        $result['brain']=['name'=>'Brain','description'=>'A nimble, calculating resource hunter. Brain monsters also nourish Fomor as it evolves.','cost'=>4000,'trainTime'=>90,'hp'=>600,'damage'=>100,'speed'=>2.0,'range'=>0.7,'size'=>30,'unlockLevel'=>3,'lockerLevel'=>3,'unlockCost'=>['goo'=>10000,'putty'=>1500],'unlockTime'=>150,'target'=>'resource','attackInterval'=>1.0,'art'=>'brain.png'];
        $result['crabatron']=['name'=>'Crabatron','description'=>'A heavy armored tower hunter. Its tough shell makes it useful in battle and as food for Gorgo.','cost'=>6000,'trainTime'=>120,'hp'=>4000,'damage'=>100,'speed'=>1.0,'range'=>0.8,'size'=>40,'unlockLevel'=>3,'lockerLevel'=>3,'unlockCost'=>['goo'=>14000,'putty'=>2000],'unlockTime'=>180,'target'=>'defense','attackInterval'=>1.2,'art'=>'crabatron.png'];
        $result['projectx']=['name'=>'Project X','description'=>'A powerful mutant that prioritizes defensive buildings. Study Acid Spores to turn its defeat into a final burst.','cost'=>6500,'trainTime'=>120,'hp'=>3200,'damage'=>260,'speed'=>1.0,'range'=>0.85,'size'=>60,'unlockLevel'=>4,'lockerLevel'=>4,'unlockCost'=>['goo'=>16000,'putty'=>2500],'unlockTime'=>180,'target'=>'defense','attackInterval'=>1.2,'art'=>'projectx.png'];
        foreach ($result as &$monster) {
            $monster += ['movement'=>'ground','ability'=>null,'lockerLevel'=>0,'unlockCost'=>['goo'=>0,'putty'=>0],'unlockTime'=>0];
        } unset($monster);
        return $catalog=$result+MonsterExpansion::catalog();
    }

    /** Champions are persistent individuals, never hatchery inventory or bunker food. */
    public static function champions(): array
    {
        static $catalog;
        if($catalog!==null){return $catalog;}
        $common = ['maxLevel'=>6,'feedInterval'=>300,'hungerGrace'=>86400,
            'feedsRequired'=>[1=>3,2=>6,3=>9,4=>12,5=>15],
            'recoverySeconds'=>[180,360,720,1440,2880,5760]];
        $rows = [
            'gorgo'=>[
                'name'=>'Gorgo','description'=>'An armored champion that absorbs heavy punishment while your swarm advances.','art'=>'gorgo.png',
                'feedRecipes'=>[1=>['octo'=>15],2=>['octo'=>10,'ichi'=>5],3=>['ichi'=>20],4=>['ichi'=>10,'crabatron'=>10],5=>['crabatron'=>20]],
                'hp'=>[8000,12000,16000,20000,24000,30000], 'damage'=>[180,230,280,340,410,500],
                'speed'=>[0.75,0.8,0.9,1.0,1.1,1.2], 'range'=>[0.8,0.85,0.9,1.0,1.05,1.1], 'attackInterval'=>1.4,
            ],
            'drull'=>[
                'name'=>'Drull','description'=>'A fast, ferocious melee champion that tears through buildings and defending monsters.','art'=>'drull.png',
                'feedRecipes'=>[1=>['pokey'=>30],2=>['pokey'=>20,'fink'=>15],3=>['bandito'=>50],4=>['bandito'=>10,'fang'=>15],5=>['fang'=>30]],
                'hp'=>[3000,4000,5500,6500,7500,9000], 'damage'=>[500,650,800,1000,1200,1500],
                'speed'=>[1.4,1.5,1.65,1.75,1.85,1.95], 'range'=>[0.8,0.85,0.9,1.0,1.15,1.2], 'attackInterval'=>1.0,
            ],
            'fomor'=>[
                'name'=>'Fomor','description'=>'A ranged champion whose aura strengthens nearby allies. It takes flight at evolution level three.','art'=>'fomor.png',
                'feedRecipes'=>[1=>['bolt'=>20],2=>['bolt'=>20,'brain'=>2],3=>['bolt'=>40,'brain'=>5],4=>['bolt'=>30,'brain'=>10],5=>['brain'=>20]],
                'hp'=>[3500,4500,5500,6500,7500,10000], 'damage'=>[45,50,60,65,70,80],
                'speed'=>[0.9,1.0,1.15,1.25,1.35,1.45], 'range'=>[3.0,3.0,3.8,4.0,4.2,4.4], 'attackInterval'=>1.4,
            ],
        ];
        $result=[];
        foreach ($rows as $type=>$row) {
            $levels=[];
            for ($level=1;$level<=6;$level++) {
                $i=$level-1;
                $levels[$level]=['hp'=>$row['hp'][$i],'damage'=>$row['damage'][$i],'speed'=>$row['speed'][$i],
                    'range'=>$row['range'][$i],'attackInterval'=>$row['attackInterval'],
                    'movement'=>$type==='fomor'&&$level>=3?'air':'ground','target'=>'any','targetMode'=>$type==='fomor'?'both':'ground',
                    'auraRadius'=>$type==='fomor'?6.0:0.0,'auraBuff'=>$type==='fomor'?$level/10:0.0];
            }
            $row['feedRecipes'][6]=$row['feedRecipes'][5];
            // Match the shipped non-MR3 override recipes, not the smaller MR3 defaults.
            $source=[
                'gorgo'=>['hp'=>200000,'damage'=>3000,'speed'=>2.0,'range'=>1,'healthBonus'=>[12500,27500,50000],'damageBonus'=>[150,330,600]],
                'drull'=>['hp'=>60000,'damage'=>8000,'speed'=>3.6,'range'=>1,'healthBonus'=>[2500,5500,10000],'damageBonus'=>[400,880,1600]],
                'fomor'=>['hp'=>40000,'damage'=>120,'speed'=>2.3,'range'=>210,'healthBonus'=>[1000,2200,4000],'damageBonus'=>[3,6,10]],
            ][$type];
            $bonuses=[];$recipes=[];
            for($tier=1;$tier<=3;$tier++){
                $i=$tier-1;
                $bonuses[$tier]=['hp'=>(int)round($levels[6]['hp']*$source['healthBonus'][$i]/$source['hp']),
                    'damage'=>(int)round($levels[6]['damage']*$source['damageBonus'][$i]/$source['damage']),
                    'speed'=>$levels[6]['speed']*[0.1,0.2,0.4][$i]/$source['speed'],
                    'range'=>$type==='fomor'?$levels[6]['range']*[3,6,10][$i]/$source['range']:0.0,
                    'auraBuff'=>$type==='fomor'?[0.03,0.06,0.15][$i]:0.0];
                $recipes[$tier]=$row['feedRecipes'][6];
            }
            $result[$type]=$common+['name'=>$row['name'],'description'=>$row['description'],'art'=>$row['art'],
                'feedRecipes'=>$row['feedRecipes'],'levels'=>$levels,'maxBonusFeeds'=>3,'bonusRecipes'=>$recipes,'bonuses'=>$bonuses];
        }
        $result['korath']=MonsterMadness::champion($common);
        return $catalog=$result;
    }

    public static function championStats(string $type,int $level,int $bonusFeeds=0): array
    {
        $spec=self::champions()[$type]??null;
        if (!$spec||$level<1||$level>$spec['maxLevel']) { throw new RuntimeException('Choose a valid champion evolution.'); }
        $tier=$level===6?max(0,min(3,$bonusFeeds)):0;$stats=$spec['levels'][$level];
        if($tier>0){foreach($spec['bonuses'][$tier] as $key=>$amount){$stats[$key]+=$amount;}}
        return ['type'=>$type,'name'=>$spec['name'],'art'=>$spec['art'],'level'=>$level,'bonus_feeds'=>$tier,
            'recoverySeconds'=>$spec['recoverySeconds'][$level-1]]+$stats;
    }

    /** Source Monster Lab powers, scaled to the remake's world and economy. */
    public static function labAbilities(): array
    {
        $rows = [
            'bolt'=>['Blink','Blink ahead while advancing toward a target. Teleportation does not damage buildings.','blink',1500,90],
            'fink'=>['Extra Claws','Strike additional nearby enemies or buildings with each attack.','multistrike',2000,120],
            'bandito'=>['Whirlwind','Attack faster and strike nearby ground enemies with each attack.','whirlwind',2500,150],
            'fang'=>['Venom','Poison defending monsters with stacking damage over time. Buildings cannot be poisoned.','venom',3000,180],
            'eyera'=>['Airburst','Strengthen the final explosion, widen its ground blast and catch nearby airborne enemies.','airburst',4000,210],
            'brain'=>['Cloak','Travel cloaked, then remain concealed briefly after reaching a target. Splash damage can still hit a cloaked monster.','cloak',4000,210],
            'projectx'=>['Acid Spores','Burst on defeat, damaging nearby ground enemies and hostile buildings.','deathburst',5000,240],
            'dave'=>['Rocket Barrage','Replace melee strikes with a pair of rockets that can reach ground and airborne enemies.','rockets',7000,360],
            'wormzer'=>['Seismic Emergence','Damage nearby ground enemies and buildings when emerging at a new target.','emerge',5500,270],
            'teratorn'=>['Ricochet','Bounce fireballs through additional nearby buildings, halving damage with every bounce.','ricochet',6000,300],
        ];
        $result=[];
        foreach($rows as $type=>$row){
            $levels=[];
            for($tier=1;$tier<=3;$tier++){
                $effect=match($type){
                    'bolt'=>['blinkRange'=>[3.0,6.0,9.0][$tier-1],'blinkDuration'=>0.5,'blinkCooldown'=>4.0],
                    'fink'=>['extraTargets'=>$tier,'extraRadius'=>1.5],
                    'bandito'=>['attackSpeed'=>1+$tier*0.5,'splashRadius'=>1.5],
                    'fang'=>['venomFraction'=>$tier/10,'venomInterval'=>1.0],
                    'eyera'=>['airburstBonus'=>[0.2,0.3,0.4][$tier-1]],
                    'brain'=>['cloakDelay'=>(float)$tier],
                    'projectx'=>['deathMultiplier'=>1+$tier*0.5,'deathRadius'=>1.5],
                    'dave'=>['rocketRange'=>[3.5,4.5,5.5][$tier-1]],
                    'wormzer'=>['emergeMultiplier'=>$tier,'emergeRadius'=>2.5],
                    'teratorn'=>['bounces'=>$tier,'bounceRadius'=>2.5],
                };
                $effectText=match($type){
                    'bolt'=>'Blink up to '.(int)$effect['blinkRange'].' yard units; four-second recovery.',
                    'fink'=>'Hit '.$tier.' additional nearby target'.($tier===1?'':'s').'.',
                    'bandito'=>'Attack '.(int)round($effect['attackSpeed']*100).'% as fast; splash nearby ground enemies.',
                    'fang'=>'Each hit adds '.($tier*10).'% of its damage as poison each second.',
                    'eyera'=>'Explosion damage and ground radius +'.(int)round($effect['airburstBonus']*100).'%; partial airborne damage.',
                    'brain'=>'Stay cloaked while moving and for '.$tier.' second'.($tier===1?'':'s').' after arrival.',
                    'projectx'=>'Death burst deals '.(int)round($effect['deathMultiplier']*100).'% attack damage in a 1.5-unit radius.',
                    'dave'=>'Fire paired rockets up to '.$effect['rocketRange'].' yard units away.',
                    'wormzer'=>'Emergence deals '.($tier*100).'% attack damage within 2.5 yard units.',
                    'teratorn'=>'Bounce to '.$tier.' additional building'.($tier===1?'':'s').'; each bounce deals half damage.',
                };
                $levels[$tier]=['effect'=>$effect,'effectText'=>$effectText,'cost'=>['putty'=>(int)ceil($row[3]*[1,1.5,2.25][$tier-1])],
                    'researchTime'=>$row[4]*$tier,'requiredLabLevel'=>$tier,'requiredMonsterLevel'=>$tier+1];
            }
            $result[$type]=['name'=>$row[0],'description'=>$row[1],'kind'=>$row[2],'maxLevel'=>3,'levels'=>$levels];
        }
        return $result;
    }

    public static function labStats(string $type,int $level): array
    {
        $spec=self::labAbilities()[$type]??null;
        if(!$spec||$level<1||$level>$spec['maxLevel']){throw new RuntimeException('Choose a valid Monster Lab ability tier.');}
        return ['type'=>$type,'level'=>$level,'name'=>$spec['name'],'description'=>$spec['description'],'kind'=>$spec['kind']]+$spec['levels'][$level];
    }

    /** Each resource family can be used once per invasion, regardless of the selected tier. */
    public static function catapultBombs(): array
    {
        $families=[
            'twig'=>['name'=>'Twig Fireball','description'=>'Burn enemy buildings in the blast area.','resource'=>'twig','requiredLevel'=>1,
                'costs'=>[1000,5000,20000],'damage'=>[300,700,1600],'radii'=>[4.0,4.0,4.0]],
            'pebble'=>['name'=>'Pebble Bomb','description'=>'Crush enemy buildings with a heavy area blast.','resource'=>'pebble','requiredLevel'=>2,
                'costs'=>[1000,5000,15000,30000],'damage'=>[350,900,1800,3000],'radii'=>[4.0,5.0,6.0,7.0]],
            'putty'=>['name'=>'Putty Rage','description'=>'Temporarily strengthen attackers already inside the impact area. Later arrivals are not affected.','resource'=>'putty','requiredLevel'=>3,
                'costs'=>[1000,5000,15000,30000],'damage'=>[0,0,0,0],'radii'=>[3.0,3.0,5.0,7.0]],
        ];
        $result=[];
        foreach($families as $type=>$family){
            $levels=[];
            foreach($family['costs'] as $index=>$cost){
                $levels[$index+1]=['cost'=>$cost,'damage'=>$family['damage'][$index],'radius'=>$family['radii'][$index],
                    'duration'=>$type==='putty'?[10,15,30,40][$index]:0,
                    'speed'=>$type==='putty'?[1.2,1.4,1.8,2.0][$index]:1.0,
                    'armor'=>$type==='putty'?[0.2,0.4,0.7,0.9][$index]:0.0];
            }
            $result[$type]=['name'=>$family['name'],'description'=>$family['description'],'resource'=>$family['resource'],
                'requiredLevel'=>$family['requiredLevel'],'levels'=>$levels];
        }
        return $result;
    }

    public static function all(): array
    {
        return ['buildings'=>self::buildings(),'monsters'=>self::monsters(),'champions'=>self::champions(),'labAbilities'=>self::labAbilities(),'catapultBombs'=>self::catapultBombs(),'worldSize'=>32,'maxBuildings'=>100,'maxMonsterLevel'=>6,'ranking'=>['win'=>25,'loss'=>15],'version'=>'0.13.1'];
    }
}
