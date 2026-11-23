<?php
declare(strict_types=1);
require_once __DIR__.'/InfernoAscension.php';
require_once __DIR__.'/CreatureCarnage.php';

require_once __DIR__.'/Quests.php';
require_once __DIR__.'/EventCalendar.php';
require_once __DIR__.'/GameLanguage.php';
require_once __DIR__.'/QuestSocial.php';
require_once __DIR__.'/Decorations.php';
require_once __DIR__.'/YardMessages.php';
require_once __DIR__.'/Mushrooms.php';
require_once __DIR__.'/Seasonal.php';
require_once __DIR__.'/Truces.php';
require_once __DIR__.'/Construction.php';
require_once __DIR__.'/Planner.php';
require_once __DIR__.'/WorldActivity.php';
require_once __DIR__.'/BaiterService.php';

final class Game
{
    public static function configured(): bool { return is_file(dirname(__DIR__).'/var/config.php'); }
    public static function isBot(int $id): bool
    {
        $q=Db::pdo()->prepare('SELECT is_bot FROM users WHERE id=?');$q->execute([$id]);return (int)$q->fetchColumn()===1;
    }
    public static function speed(): float { return max(0.25,min(20,(float)(Db::config()['game_speed']??1))); }
    public static function user(int $id): array
    {
        $q=Db::pdo()->prepare('SELECT id,name,is_bot,rank_points,wins,losses,created_at FROM users WHERE id=?'); $q->execute([$id]);
        $u=$q->fetch(); if (!$u) { throw new RuntimeException('That keeper could not be found.'); }
        foreach (['id','rank_points','wins','losses','created_at'] as $k) { $u[$k]=(int)$u[$k]; }
        $u['is_bot']=(bool)$u['is_bot']; return $u;
    }
    /** Pure, idempotent content migration: never changes geometry, balances or existing timers. */
    public static function normalizeBase(array $base): array
    {
        $legacy=['pokey','octo','fink','ichi','bandito','dave'];
        $base['unlocked']=array_values(array_unique(array_merge($legacy,$base['unlocked']??[])));
        $base['unlockQueue']=$base['unlockQueue']??[];
        $base['garrison']=$base['garrison']??[];
        foreach(Catalog::homeMonsters() as $type=>$spec){
            if(!array_key_exists($type,$base['army'])){$base['army'][$type]=0;}
            if(!array_key_exists($type,$base['research'])){$base['research'][$type]=1;}
        }
        foreach($base['buildings'] as &$building){
            $building+=['fortification'=>0,'fortify_ready_at'=>0,'fortify_to'=>null,'fortify_paid'=>null];
        }unset($building);
        $base['researchQueue']=$base['researchQueue']??[];
        $base['champions']=$base['champions']??[];
        $base['activeChampion']=$base['activeChampion']??null;
        foreach ($base['champions'] as $type=>&$champion) {
            if (!is_array($champion)||!isset(Catalog::champions()[$type])) { continue; }
            $champion+=['level'=>1,'hp'=>0,'feeds'=>0,'next_feed_at'=>0,
                'last_heal_at'=>(int)($base['last_tick']??0),'battle_id'=>0,'frozen'=>false,'paused_feed_seconds'=>0,'bonus_feeds'=>0];
            $champion+=['last_hunger_at'=>(int)$champion['next_feed_at']];
        } unset($champion);
        $base['lab']=$base['lab']??[];
        $base['lab']+=['levels'=>[],'queue'=>null];
        foreach(Catalog::labAbilities() as $type=>$spec){if(!array_key_exists($type,$base['lab']['levels'])){$base['lab']['levels'][$type]=0;}}
        $base['world']=$base['world']??[];
        $base['world']+=['resourcePerSecond'=>0.0,'capacity'=>0,'holdings'=>0,'pending'=>[]];
        $base['workers']=Construction::normalizeWorkers($base['workers']??1);
        $base['planner']=$base['planner']??['slots'=>[]];
        if(is_array($base['planner'])){$base['planner']+=['slots'=>[]];}
        $base['content_version']='0.13.0';
        return $base;
    }
    /** Highest completed, living building of this type. Upgrade timers temporarily disable its service. */
    public static function livingLevel(array $base,string $type): int
    {
        $level=0;$now=time();
        foreach($base['buildings'] as $building){
            if($building['type']===$type&&$building['hp']>0&&($building['ready_at']??0)<=$now){$level=max($level,(int)$building['level']);}
        }
        return $level;
    }
    /** Returns the one combat-ready individual without changing its persistent health. */
    public static function readyChampion(array $base,?int $now=null): ?array
    {
        $now=$now??time();$type=$base['activeChampion']??null;
        if (!is_string($type)||!isset(Catalog::champions()[$type],$base['champions'][$type])) { return null; }
        $champion=$base['champions'][$type];
        if (!empty($champion['frozen'])||(int)($champion['battle_id']??0)>0||($champion['hp']??0)<=0) { return null; }
        $cage=false;
        foreach ($base['buildings'] as $building) {
            if ($building['type']==='championcage'&&$building['level']>0&&$building['hp']>0&&(int)($building['ready_at']??0)<=$now) { $cage=true;break; }
        }
        if (!$cage) { return null; }
        $stats=Catalog::championStats($type,(int)$champion['level'],(int)($champion['bonus_feeds']??0));
        if($type==='korath'){MonsterMadness::freeze($stats,$base);}
        return array_merge($champion,['type'=>$type,'max_hp'=>$stats['hp'],'stats'=>$stats]);
    }

    /** Actual next recipe, including the three earned level-six bonus meals. */
    public static function championRecipe(string $type,int $level,int $bonusFeeds=0): array
    {
        $spec=Catalog::champions()[$type]??null;
        if(!$spec||$level<1||$level>6){throw new RuntimeException('Choose a valid champion evolution.');}
        return $level===6?$spec['bonusRecipes'][max(1,min(3,$bonusFeeds+1))]:$spec['feedRecipes'][$level];
    }

    /** Public effective stats are calculated once here; stored records stay small and authoritative. */
    public static function championRoster(array $base): array
    {
        $roster=$base['champions']??[];
        foreach($roster as $type=>&$champion){
            if(!is_array($champion)||!isset(Catalog::champions()[$type])){continue;}
            $stats=Catalog::championStats($type,(int)$champion['level'],(int)($champion['bonus_feeds']??0));
            if($type==='korath'){MonsterMadness::freeze($stats,$base);}
            $champion['stats']=$stats;$champion['max_hp']=$stats['hp'];
        }unset($champion);
        return $roster;
    }

    /** Pure clock step with exact bonus-decay boundaries and no repeated healing or hunger. */
    public static function accrueChampions(array &$base,int $now,float $speed=1): void
    {
        $type=$base['activeChampion']??null;
        if(!is_string($type)||!isset(Catalog::champions()[$type],$base['champions'][$type])){return;}
        $champion=&$base['champions'][$type];
        if(!empty($champion['frozen'])||(int)($champion['battle_id']??0)>0){unset($champion);return;}
        $spec=Catalog::champions()[$type];$level=(int)$champion['level'];
        $last=(int)($champion['last_heal_at']??$now);
        if($now<$last){unset($champion);return;}
        $champion['bonus_feeds']=max(0,min(3,(int)($champion['bonus_feeds']??0)));
        $feedAt=(int)$champion['next_feed_at'];$hungerAt=max($feedAt,(int)($champion['last_hunger_at']??$feedAt));
        $interval=(int)$spec['hungerGrace'];$missed=(int)floor(max(0,$now-$hungerAt)/$interval);
        $cursor=$last;$rate=max(0.25,min(20,$speed));
        $heal=static function(int $until)use(&$champion,&$cursor,$type,$level,$rate):void{
            $stats=Catalog::championStats($type,$level,(int)$champion['bonus_feeds']);
            $elapsed=max(0,$until-$cursor);
            $champion['hp']=min((float)$stats['hp'],max(0,(float)$champion['hp'])+$elapsed*$stats['hp']*$rate/$stats['recoverySeconds']);
            $cursor=max($cursor,$until);
        };
        if($level===6&&$missed>0){
            // At most three stat changes even after years away. Old elapsed time cannot
            // continue healing at a stronger tier after its actual hunger deadline.
            $decays=min($missed,(int)$champion['bonus_feeds']);
            for($i=1;$i<=$decays;$i++){
                $at=$hungerAt+$i*$interval;$heal(max($cursor,$at));
                --$champion['bonus_feeds'];
                $max=Catalog::championStats($type,$level,(int)$champion['bonus_feeds'])['hp'];
                $champion['hp']=min((float)$max,max(0,(float)$champion['hp']));
            }
        }
        $heal($now);$champion['last_heal_at']=$now;
        if($missed>0){
            if($level<6){$champion['feeds']=max(0,(int)$champion['feeds']-$missed);}
            $champion['last_hunger_at']=$hungerAt+$missed*$interval;
        }
        unset($champion);
    }

    /** Complete a paid Lab job once; construction damage does not erase an already funded study. */
    public static function accrueLab(array &$base,int $now): void
    {
        $job=$base['lab']['queue']??null;
        if(!is_array($job)||!isset($job['type'],$job['target_level'],$job['ready_at'])){return;}
        $type=$job['type'];$level=(int)$job['target_level'];
        if(!is_string($type)||!isset(Catalog::labAbilities()[$type])||$level<1||$level>3||(int)$job['ready_at']<1||(int)$job['ready_at']>$now){return;}
        $base['lab']['levels'][$type]=max((int)($base['lab']['levels'][$type]??0),$level);
        $base['lab']['queue']=null;
    }

    public static function bunkerCapacity(array $base,int $buildingId): int
    {
        foreach($base['buildings'] as $building){
            if((int)$building['id']===$buildingId&&$building['type']==='bunker'){return max(0,(int)$building['level'])*(int)Catalog::buildings()['bunker']['capacity'];}
        }
        return 0;
    }
    public static function bunkerUsed(array $base,int $buildingId): int
    {
        $used=0;$catalog=Catalog::monsters();
        foreach(($base['garrison'][(string)$buildingId]??[]) as $type=>$quantity){$used+=(int)$quantity*(int)($catalog[$type]['size']??0);}
        return $used;
    }
    public static function loadBase(int $id,bool $lock=false): array
    {
        $q=Db::pdo()->prepare('SELECT state_json FROM bases WHERE user_id=?'.($lock?' FOR UPDATE':'')); $q->execute([$id]);
        $json=$q->fetchColumn(); if ($json===false) { throw new RuntimeException('That yard could not be found.'); }
        $base=self::normalizeBase(json_decode($json,true,512,JSON_THROW_ON_ERROR));
        if(self::isBot($id)){WorldActivity::shiftBase($base,WorldActivity::clock());}
        return $base;
    }
    public static function saveBase(int $id,array $base): void
    {
        Quests::observe($base);
        if (!empty($base['calendarRounds'])) EventCalendar::record(Db::pdo(),$id,$base,time());
        $q=Db::pdo()->prepare('UPDATE bases SET state_json=?,updated_at=? WHERE user_id=?');
        $q->execute([json_encode($base,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),time(),$id]);
    }
    public static function capacity(array $b): int
    {
        $total=30000+max(0,(int)($b['world']['capacity']??0));
        foreach ($b['buildings'] as $building) {
            if ($building['type']==='storage' && $building['level']>0) { $total+=30000*$building['level']; }
        }
        return StorePowers::materialCapacity($b,$total);
    }
    public static function housingCapacity(array $b,?int $now=null): int
    {
        $total=0;
        foreach ($b['buildings'] as $building) { if ($building['type']==='housing' && $building['level']>0) { $total+=400*$building['level']; } }
        return StorePowers::reserveCapacity($b,$total,$now??time());
    }
    public static function housingUsed(array $b): int
    {
        $catalog=Catalog::monsters();$total=0;
        foreach ($b['army'] as $type=>$count) { $total+=(int)$count*($catalog[$type]['size']??0); }
        foreach ($b['queue'] as $job) { $total+=(int)($job['quantity']??1)*($catalog[$job['type']]['size']??0); }
        return $total;
    }
    public static function townhallLevel(array $b): int
    {
        return TownHall::level($b);
    }
    /** Pure completion: fortification changes resistance only, never HP, level, capacity or geometry. */
    public static function accrueFortifications(array &$base,int $now): void
    {
        $catalog=Catalog::buildings();
        foreach($base['buildings'] as &$building){
            $ready=(int)($building['fortify_ready_at']??0);$target=(int)($building['fortify_to']??0);
            if($ready>0&&$ready<=$now&&!empty($catalog[$building['type']]['canFortify'])&&$target>=1&&$target<=4){
                $building['fortification']=max(max(0,min(4,(int)($building['fortification']??0))),$target);
                $building['fortify_ready_at']=0;$building['fortify_to']=null;$building['fortify_paid']=null;
            }
        }unset($building);
    }

    public static function fortificationStatus(array $base,array $building,?int $now=null,?float $speed=null): array
    {
        $now=$now??time();$speed=$speed??(self::configured()?self::speed():1.0);
        $eligible=!empty(Catalog::buildings()[$building['type']]['canFortify']);
        $level=$eligible?max(0,min(4,(int)($building['fortification']??0))):0;
        $ready=(int)($building['fortify_ready_at']??0);$active=$ready>$now;
        $next=$eligible&&$level<4?Catalog::fortificationStats($building['type'],$level+1):null;
        if($next!==null){$next+=['tier'=>$level+1,'duration'=>max(1,(int)ceil($next['buildTime']/max(0.25,min(20,$speed))))];}
        $reason='';
        if(!$eligible){$reason='This building cannot be fortified.';}
        elseif($active){$reason='This building is already being fortified.';}
        elseif($level>=4){$reason='This building has all four fortification tiers.';}
        elseif((int)($building['level']??0)<1||(int)($building['ready_at']??0)>$now){$reason='Complete construction or upgrading before fortifying.';}
        elseif((float)$building['hp']<(float)$building['max_hp']||(float)$building['hp']<=0){$reason='Fully repair this building before fortifying it.';}
        elseif(Construction::status($base,$now)['available']<1){$reason='All construction workers are busy. Wait for a job to finish or hire another worker.';}
        else{
            $hall=0;
            foreach($base['buildings'] as $candidate){
                if($candidate['type']==='townhall'&&$candidate['hp']>0&&(int)($candidate['ready_at']??0)<=$now){$hall=max($hall,(int)$candidate['level']);}
            }
            if($hall<$next['requiredTownHall']){$reason='A completed, living Town Hall at level '.$next['requiredTownHall'].' is required.';}
            else{foreach($next['cost'] as $resource=>$cost){if(($base['resources'][$resource]??0)<$cost){$reason='Not enough '.$resource.' to fortify this building.';break;}}}
        }
        $refund=null;
        if($active){
            $paid=is_array($building['fortify_paid']??null)?$building['fortify_paid']:[];$potential=[];$credited=[];$overflow=[];
            foreach(['twig','pebble','putty','goo'] as $resource){
                $potential[$resource]=max(0,(int)($paid[$resource]??0));
                $credited[$resource]=(int)max(0,min($potential[$resource],self::capacity($base)-(float)($base['resources'][$resource]??0)));
                $overflow[$resource]=$potential[$resource]-$credited[$resource];
            }
            $refund=['potential'=>$potential,'credited'=>$credited,'overflow'=>$overflow];
        }
        return ['eligible'=>$eligible,'available'=>$reason==='','canStart'=>$reason==='','reason'=>$reason,'level'=>$level,'maxLevel'=>4,
            'active'=>$active,'ready_at'=>$active?$ready:0,'reduction'=>Catalog::fortificationReduction($level),'next'=>$next,
            'pending'=>$active?['readyAt'=>$ready,'to'=>(int)$building['fortify_to']]:null,'cancelRefund'=>$refund];
    }

    public static function fortificationState(array $base,int $buildingId,?int $now=null): array
    {
        foreach($base['buildings'] as $building){if((int)$building['id']===$buildingId){return self::fortificationStatus($base,$building,$now);}}
        throw new RuntimeException('That building is not in your yard.');
    }

    public static function buildingStats(string $type,int $level,bool $inferno=false): array
    {
        $c=Catalog::buildings()[$type]??null; if (!$c) { throw new RuntimeException('Unknown building.'); }
        if($type==='townhall'&&!$inferno&&$level>5)return ['max_hp'=>$c['levels'][min($level,TownHall::MAX_LEVEL)]['hp'],'damage'=>0];
        if (in_array($type,['baiter','siegelab','siegefactory'],true)) {
            return ['max_hp'=>$c['levels'][max(1, min($level, $c['maxLevel']))]['hp'], 'damage'=>0];
        }
        if (isset($c['defenseBehavior'])) {
            $tier = $c['levels'][max(1,min($level,$c['maxLevel']))];
            return ['max_hp'=>$tier['hp'],'damage'=>$tier['damage']];
        }
        if ($type === 'aerial') {
            $index = max(0, min($level, $c['maxLevel']) - 1);
            return ['max_hp'=>$c['healthByLevel'][$index], 'damage'=>$c['shotDamage'][$index]];
        }
        return ['max_hp'=>(int)round($c['hp']*pow(1.35,max(0,$level-1))),'damage'=>(int)round(($c['damage']??0)*pow(1.25,max(0,$level-1)))];
    }
    public static function accrue(array &$b,int $now): void
    {
        InfernoAscension::syncResearch($b,$now);
        $b=self::normalizeBase($b);
        $last=(int)($b['last_tick']??$now);$end=max($last,$now);$start=max($last,$end-72*3600);$catalog=Catalog::buildings();$speed=self::speed();
        foreach ($b['buildings'] as &$building) {
            $ready=(int)($building['ready_at']??0);$level=(int)$building['level'];
            $c=$catalog[$building['type']]??null;
            if (!$c) { continue; }
            $oldLevel=$level;
            if ($ready>0 && $ready<=$now) {
                $building['level']=(int)($building['upgrade_to']??max(1,$level));
                $building['max_hp']=self::buildingStats($building['type'],$building['level'])['max_hp'];
                $building['hp']=$building['max_hp']; $building['ready_at']=0; $building['upgrade_to']=null;
                $level=$building['level'];
                if ($oldLevel===0 && $level>0) { Quests::constructed($b,$building['type']); }
                YardMessages::receive($b,'news',$c['name'].' is ready at level '.$level.'.',$ready);
            }
            if (isset($c['production']) && $building['hp']>0 && $level>0) {
                $beforeSeconds=$ready>0&&$ready<=$now?max(0,min($end,$ready)-$start):max(0,$end-$start);
                $afterSeconds=$ready>0&&$ready<=$now?max(0,$end-max($start,$ready)):0;
                $split=$start+$beforeSeconds;
                $boostEnd=StorePowers::expiry($b,'POD');
                $extra=max(0,min($split,$boostEnd)-$start)*$oldLevel+max(0,min($end,$boostEnd)-$split)*$level;
                $production=($beforeSeconds*$oldLevel+$afterSeconds*$level+$extra)*$c['production']*$speed;
                $building['stored']=min((float)($c['capacity']*$level),(float)($building['stored']??0)+$production);
            }
        } unset($building);
        YardMessages::prune($b,$now);
        Mushrooms::accrue($b,$now);
        Seasonal::accrue($b,$now);
        KrallenHunt::accrue($b,$now,$speed);
        self::accrueFortifications($b,$now);
        $pending=[];
        foreach ($b['queue'] as $job) {
            if ((int)$job['ready_at']<=$now) { $b['army'][$job['type']]=(int)($b['army'][$job['type']]??0)+(int)($job['quantity']??1); Quests::hatched($b,$job['type'],(int)($job['quantity']??1)); }
            else { $pending[]=$job; }
        } $b['queue']=$pending;
        $pending=[];
        foreach (($b['researchQueue']??[]) as $job) {
            if ((int)$job['ready_at']<=$now) { $b['research'][$job['type']]=(int)$job['target_level']; }
            else { $pending[]=$job; }
        } $b['researchQueue']=$pending;
        $pending=[];
        foreach($b['unlockQueue'] as $job){
            if((int)$job['ready_at']<=$now){if(!in_array($job['type'],$b['unlocked'],true)){$b['unlocked'][]=$job['type'];}}
            else{$pending[]=$job;}
        }
        $b['unlockQueue']=$pending;self::accrueLab($b,$now);self::accrueChampions($b,$now,$speed);
        SiegeWorkshop::accrue($b,$now);
        World::accrueIncome($b,$start,$end,$speed);$b['last_tick']=$end;
    }
    /** Free home recovery changes HP only; paid work and lost troops stay untouched. */
    public static function repairHomeStatus(array $base): array
    {
        $damaged=0;
        foreach($base['buildings']??[] as $building){if(self::canRepairHomeBuilding($building)){++$damaged;}}
        return ['damaged'=>$damaged,'available'=>$damaged>0,'reason'=>$damaged>0?'':'Your base is fully repaired.','free'=>true,'instant'=>true];
    }
    /** Pure shared operation for keeper buttons and the ordinary online bot turn. */
    public static function repairHome(array &$base,?int $buildingId=null,?array &$restoredIds=null): int
    {
        $repaired=0;
        foreach($base['buildings'] as &$building){
            if(($buildingId===null||(int)$building['id']===$buildingId)&&self::canRepairHomeBuilding($building)){
                if (is_array($restoredIds)) { $restoredIds[] = (int)$building['id']; }
                $building['hp']=$building['max_hp'];++$repaired;
            }
        }unset($building);
        return $repaired;
    }
    private static function canRepairHomeBuilding(array $building): bool
    {
        // Level zero means a new paid construction, not an invasion casualty.
        // Existing upgrades/fortification retain their own deadlines and gates.
        $maximum=(float)($building['max_hp']??0);$health=(float)($building['hp']??$maximum);
        return (int)($building['level']??0)>0&&is_finite($maximum)&&is_finite($health)&&$maximum>0&&$health<$maximum;
    }
    public static function publicBase(int $id,array $b,bool $ownerView=true): array
    {
        $b=self::normalizeBase($b);
        $buildings=YardUtilities::hatcheryWork($b,time());
        if(!$ownerView){$buildings=array_values(array_filter($buildings,[Catalog::class,'visibleToRivals']));}
        foreach($buildings as &$building){
            if($ownerView){$building['fortify']=self::fortificationStatus($b,$building);}
            else{unset($building['fortify_paid'],$building['fortify']);}
        }unset($building);
        $home=World::home($id);
        $network=$home?World::fortificationSupport($id,$home['q'],$home['r']):['count'=>0,'reduction'=>0.0,'siteIds'=>[]];
        $result=['owner'=>self::user($id),'buildings'=>$buildings,'yardExtent'=>YardSpace::extent($b),'resources'=>array_map(fn($n)=>(int)floor($n),$b['resources']),'capacity'=>self::capacity($b),'housingCapacity'=>self::housingCapacity($b),'housingUsed'=>self::housingUsed($b),'shield_until'=>(int)($b['shield_until']??0),'updated_at'=>(int)$b['last_tick'],'army'=>$b['army'],'research'=>$b['research'],'unlocked'=>$b['unlocked'],'unlockQueue'=>$b['unlockQueue'],'garrison'=>$b['garrison'],'champions'=>self::championRoster($b),'activeChampion'=>$b['activeChampion'],'lab'=>$b['lab'],'world'=>$b['world'],'networkDefense'=>$network,'content_version'=>$b['content_version']];
        $productionBoost=StorePowers::expiry($b,'POD');
        if($productionBoost>time()){$result['productionOverdriveUntil']=$productionBoost;}
        if($pet=KrallenHunt::ready($b,time())){
            $result['krallenCompanion']=array_intersect_key($pet,array_flip(['type','level','power','hp','max_hp']));
        }
        $result['signs']=YardMessages::signs($b);
        $result['decorations']=Decorations::visible($b);
        $result['mushrooms'] = Mushrooms::view($b,$ownerView);
        $result['seasonal'] = Seasonal::view($b,$ownerView);
        if ($ownerView) {
            $result['emergence']=InfernoEmergence::view($b,time());
            $result['molochSiege']=MolochSiege::view($b);
            $result['infernoPortal']=isset($b['infernoRealm']) || $result['emergence']['complete'] ? 'open' : 'closed';
            $result['vorgEventActive']=CreatureCarnage::discoveryBlocked($b,time());
            $result['monsterMadness']=MonsterMadness::view($b,time());
            $result['krallenHunt']=KrallenHunt::view($b,time(),self::speed());
            $result['infernoFood']=[];
            if(isset($b['infernoRealm'])){
                $foodRealm=$b['infernoRealm'];InfernoMonsters::accrue($foodRealm,time(),self::speed());$result['infernoFood']=$foodRealm['army'];
            }
            $result['brukkargReward']=SpurtzRewards::status($b);
            $result['utilities']=YardUtilities::view($b,time());
            $result['shiny'] = (int)($b['shiny']??0);
            $result['radio']=YardMessages::radio($b);
            $result['decorationStorage']=Decorations::storage($b);
            $result['decorationUnlocks']=Decorations::unlocks($b,$result['owner']);
            $result['truceOffers'] = count(array_filter($b['truces'] ?? [], static fn(array $r): bool => $r['state'] === 'requested' && $r['from'] !== $id));
            $questViewer=$result['owner'];
            if (!$questViewer['is_bot']) {
                $questViewer['giftsAccepted']=QuestSocial::acceptedGifts($id);
                $questViewer['allied']=QuestSocial::isMember($id) ? 1 : 0;
                if (isset($b['infernoRealm'])) { InfernoRealm::accrue($b['infernoRealm'],time()); }
            }
            $result['quests'] = Quests::view($b,$questViewer);
            if (!$questViewer['is_bot']) { $result['quests']['invite']=QuestSocial::token($id); }
            $result['siege'] = SiegeWorkshop::view($b,time(),self::speed());
            $result['wmi'] = WildMonsterInvasions::view($b,time());
            $result['blitz'] = MonsterBlitz::view($b, time());
            $result['baiter'] = Baiter::options($b, time());
            $result['baiter']['session'] = BaiterService::summary($b['baiterPractice'] ?? null);
        }
        if($ownerView){$result['construction']=Construction::status($b);$result['planner']=Planner::status($b);$result['repair']=self::repairHomeStatus($b);}
        return $result;
    }
    public static function initialBase(int $id,bool $bot=false): array
    {
        $now=time(); $level=$bot?1+($id%3):1;
        $layout=[['townhall',16,16],['hatchery',12,12],['housing',21,17],['twig',10,20],['pebble',20,23],['goo',23,11],['sniper',9,13],['flinger',7,7]];
        if ($bot && $level>=2) { $layout[]=['cannon',19,9];$layout[]=['putty',10,25];$layout[]=['storage',24,23]; }
        if ($bot && $level>=3) { $layout[]=['academy',6,23];$layout[]=['tesla',16,24];$layout[]=['sniper',23,5]; }
        $b=['buildings'=>[],'resources'=>['twig'=>12000,'pebble'=>12000,'putty'=>6000,'goo'=>24000],'army'=>['pokey'=>24,'octo'=>4,'fink'=>4,'ichi'=>0,'bandito'=>0,'dave'=>0],'queue'=>[],'research'=>array_fill_keys(array_keys(Catalog::homeMonsters()),1),'researchQueue'=>[],'last_tick'=>$now,'shield_until'=>$bot?0:$now+(int)(Db::config()['player_grace_seconds']??1800),'next_building_id'=>1,'next_queue_id'=>1];
        foreach ($layout as [$type,$x,$y]) {
            if ($bot) {
                for ($r=0;$r<($id%4);$r++) { [$x,$y]=[32-$y,$x]; }
                if ($id%2===0) { $x=32-$x; }
                $x+=($id%3-1)*0.35;$y+=(intdiv($id,3)%3-1)*0.35;
            }
            $bl=$type==='townhall'?$level:1;
            $stats=self::buildingStats($type,$bl);
            $b['buildings'][]=['id'=>$b['next_building_id']++,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$bl,'hp'=>$stats['max_hp'],'max_hp'=>$stats['max_hp'],'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
        }
        if ($bot && $level>=2) { $b['army']['pokey']=14;$b['army']['ichi']=1;$b['army']['bandito']=3; }
        if ($bot) { $b['personality']=['builder','raider','guardian','hoarder'][$id%4];$b['bot_turns']=0; }
        return self::normalizeBase($b);
    }
    public static function seedBots(PDO $pdo,int $count=1000): void
    {
        $adjectives=['Amber','Ashen','Azure','Bramble','Copper','Crystal','Dusk','Ember','Fern','Frost','Golden','Granite','Iron','Ivory','Jade','Lunar','Moss','Neon','Obsidian','Onyx','Opal','Quartz','Ruby','Rust','Saffron','Scarlet','Silver','Solar','Storm','Violet','Wild','Willow'];
        $nouns=['Badger','Basilisk','Beetle','Chimera','Claw','Drake','Fang','Fen','Finch','Fox','Gargoyle','Grove','Hawk','Hive','Horn','Jackal','Lynx','Mantis','Maw','Mole','Moth','Owl','Pangolin','Raptor','Raven','Scarab','Serpent','Spider','Stag','Talon','Toad','Wyrm'];
        $insert=$pdo->prepare('INSERT INTO users(name,password_hash,is_bot,rank_points,wins,losses,next_turn_at,created_at) VALUES(?,NULL,1,0,0,0,?,?)');
        $baseInsert=$pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$now=time();$clock=WorldActivity::clock();
        for ($i=0;$i<$count;$i++) {
            $name=$adjectives[intdiv($i,32)%32].' '.$nouns[$i%32].' '.sprintf('%03d',$i+1);
            $insert->execute([$name,$now+($i*13)%121,$now]);$id=(int)$pdo->lastInsertId();
            $base=self::initialBase($id,true);WorldActivity::stamp($base,$clock);
            $baseInsert->execute([$id,json_encode($base,JSON_THROW_ON_ERROR),$now]);
        }
    }
    private static function validateName(string $name): string
    {
        $name=trim($name);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]{2,23}$/D',$name)) { throw new RuntimeException('Choose a 3–24 character name using letters, numbers, spaces, - or _.'); }
        return $name;
    }
    private static function authThrottle(string $name): void
    {
        $ip=$_SERVER['REMOTE_ADDR']??'cli';$salt=Db::config()['auth_salt']??'';
        $buckets=[hash('sha256','ip|'.$ip.'|'.$salt)=>60,hash('sha256','name|'.$ip.'|'.strtolower(trim($name)).'|'.$salt)=>20];
        ksort($buckets);
        Db::transaction(function(PDO $pdo) use($buckets) {
            $now=time();
            foreach($buckets as $key=>$limit){
                $q=$pdo->prepare('INSERT IGNORE INTO auth_attempts(identity_hash,attempts,window_start) VALUES(?,0,?)');$q->execute([$key,$now]);
                $q=$pdo->prepare('SELECT attempts,window_start FROM auth_attempts WHERE identity_hash=? FOR UPDATE');$q->execute([$key]);$row=$q->fetch();
                if($now-(int)$row['window_start']>=900){$q=$pdo->prepare('UPDATE auth_attempts SET attempts=1,window_start=? WHERE identity_hash=?');$q->execute([$now,$key]);}
                else{
                    if((int)$row['attempts']>=$limit){throw new RuntimeException('Too many account or sign-in attempts. Please wait 15 minutes.');}
                    $q=$pdo->prepare('UPDATE auth_attempts SET attempts=attempts+1 WHERE identity_hash=?');$q->execute([$key]);
                }
            }
            if(random_int(1,100)===1){$pdo->exec('DELETE FROM auth_attempts WHERE window_start < '.($now-86400));}
        });
    }
    public static function register(string $name,string $password,string $invite=''): array
    {
        $name=self::validateName($name);self::authThrottle($name);
        if (strlen($password)<10 || strlen($password)>200) { throw new RuntimeException('Use a password between 10 and 200 characters.'); }
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $id=Db::transaction(function(PDO $pdo) use($name,$hash,$invite) {
            $q=$pdo->prepare('SELECT id FROM users WHERE name=?');$q->execute([$name]);if($q->fetchColumn()) { throw new RuntimeException('That keeper name is already taken.'); }
            $q=$pdo->prepare('INSERT INTO users(name,password_hash,is_bot,rank_points,wins,losses,next_turn_at,created_at) VALUES(?,?,0,0,0,0,0,?)');$q->execute([$name,$hash,time()]);$id=(int)$pdo->lastInsertId();
            $q=$pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)');$q->execute([$id,json_encode(self::initialBase($id),JSON_THROW_ON_ERROR),time()]);World::assignHome($id);
            if ($invite!=='') { QuestSocial::registration($pdo,$id,$invite); }
            return $id;
        });
        self::signIn($id);return self::user($id);
    }
    public static function login(string $name,string $password): array
    {
        self::authThrottle($name);$q=Db::pdo()->prepare('SELECT id,password_hash,is_bot FROM users WHERE name=?');$q->execute([trim($name)]);$u=$q->fetch();
        $hash=$u['password_hash']??'$2y$10$wHodnW4M8OayicwjGUEapeFhW5vY8f4.EHFjQJuYgyV9N5i6UkKpq';
        if (!password_verify($password,$hash) || !$u || $u['is_bot']) { throw new RuntimeException('Name or password is incorrect.'); }
        self::signIn((int)$u['id']);return self::user((int)$u['id']);
    }
    private static function signIn(int $id): void
    {
        if (PHP_SAPI!=='cli') { session_regenerate_id(true); }
        $_SESSION['user_id']=$id;$_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    public static function requireUser(): int
    {
        $id=(int)($_SESSION['user_id']??0);if ($id<=0) { throw new RuntimeException('Please sign in to continue.'); }return $id;
    }
    public static function snapshot(?int $id): array
    {
        $common=['user'=>null,'csrf'=>$_SESSION['csrf']??'','catalog'=>Catalog::all(),'serverTime'=>time(),'world'=>self::worldSummary(),'speed'=>self::speed()];
        $common['catalog']['speed']=self::speed();
        $common['catalog']['decorations']=Decorations::catalog();
        $common['catalog']=GameLanguage::displayCatalog($common['catalog']);
        if (!$id) { return $common; }
        $b=Db::transaction(function() use($id){$b=self::loadBase($id,true);Mushrooms::ensure($b,$id,time());self::accrue($b,time());Seasonal::observe($b,time());self::saveBase($id,$b);return $b;});
        $q=Db::pdo()->prepare("SELECT id,attacker_id,defender_id,status FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?) ORDER BY id DESC LIMIT 1");$q->execute([$id,$id]);$battle=$q->fetch();
        if($battle){foreach(['id','attacker_id','defender_id'] as $k){$battle[$k]=(int)$battle[$k];}}
        return array_merge($common,['user'=>self::user($id),'base'=>self::publicBase($id,$b),'army'=>$b['army'],'queue'=>$b['queue'],'research'=>$b['research'],'researchQueue'=>$b['researchQueue']??[],'unlocked'=>$b['unlocked'],'unlockQueue'=>$b['unlockQueue'],'garrison'=>$b['garrison'],'champions'=>self::championRoster($b),'activeChampion'=>$b['activeChampion'],'lab'=>$b['lab'],'activeBattle'=>$battle?:null]);
    }
    public static function worldSummary(): array
    {
        $q=Db::pdo()->query('SELECT SUM(is_bot=1) bots,SUM(is_bot=0) players FROM users');$r=$q->fetch();
        $activity=WorldActivity::status();$last=$activity['lastTick']??null;
        return ['bots'=>(int)$r['bots'],'players'=>(int)$r['players'],'keepers'=>(int)$r['bots']+(int)$r['players'],
            'worker_last_tick'=>$last,'worker_running'=>$activity['mode']==='active'&&$last!==null&&time()-(int)$last<30,'activity'=>$activity];
    }
    public static function visit(int $id,?int $viewerId=null): array
    {
        $b=Db::transaction(function() use($id){$b=self::loadBase($id,true);Mushrooms::ensure($b,$id,time());self::accrue($b,time());self::saveBase($id,$b);return $b;});
        return self::publicBase($id,$b,$viewerId===$id) + ['truceUntil'=>Truces::activeUntil($b, (int)$viewerId, time()),
            'warEligible'=>$viewerId!==null&&$viewerId!==$id&&AllianceWars::qualify(Db::pdo(),$viewerId,$id,time())!==null];
    }
    public static function listing(string $kind,int $viewerId,int $page=1,string $search='',string $filter='all'): array
    {
        $limit=24;$search=substr(trim($search),0,40);$params=[];$where=[];
        if($kind==='opponents'){$where[]='u.id<>?';$params[]=$viewerId;}
        if($filter==='bots'){$where[]='u.is_bot=1';}elseif($filter==='players'){$where[]='u.is_bot=0';}elseif($filter!=='all'){throw new RuntimeException('Choose all keepers, players or bots.');}
        if($search!==''){$where[]='u.name LIKE ?';$params[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%';}
        $sqlWhere=$where?' WHERE '.implode(' AND ',$where):'';
        $q=Db::pdo()->prepare('SELECT COUNT(*) FROM users u'.$sqlWhere);$q->execute($params);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/$limit));$page=max(1,min($page,$pages));$offset=($page-1)*$limit;
        $q=Db::pdo()->prepare('SELECT u.id,u.name,u.is_bot,u.rank_points,u.wins,u.losses,b.state_json FROM users u JOIN bases b ON b.user_id=u.id'.$sqlWhere.' ORDER BY u.rank_points DESC,u.id ASC LIMIT '.$limit.' OFFSET '.$offset);$q->execute($params);$items=[];
        $clock=WorldActivity::clock();
        foreach($q->fetchAll() as $i=>$u){
            $b=json_decode($u['state_json'],true,512,JSON_THROW_ON_ERROR);unset($u['state_json']);
            foreach(['id','rank_points','wins','losses'] as $k){$u[$k]=(int)$u[$k];}$u['is_bot']=(bool)$u['is_bot'];
            if($u['is_bot']){WorldActivity::shiftBase($b,$clock);}
            if ($kind === 'opponents') { $u['truceUntil'] = Truces::activeUntil($b, $viewerId, time()); }
            $u['townhall']=self::townhallLevel($b);$u['buildings']=count(array_filter($b['buildings'],[Catalog::class,'visibleToRivals']));$u['army']=array_sum($b['army']);$u['shield_until']=(int)($b['shield_until']??0);
            if($search===''&&$filter==='all'){$u['position']=$offset+$i+1;}else{$r=Db::pdo()->prepare('SELECT COUNT(*) FROM users WHERE rank_points>? OR (rank_points=? AND id<?)');$r->execute([$u['rank_points'],$u['rank_points'],$u['id']]);$u['position']=1+(int)$r->fetchColumn();}
            $items[]=$u;
        }
        return ['items'=>$items,'page'=>$page,'total'=>$total,'pages'=>$pages,'perPage'=>$limit];
    }
    public static function reports(int $id): array
    {
        $q=Db::pdo()->prepare('SELECT id,battle_id,title,body_json,created_at FROM reports WHERE user_id=? ORDER BY id DESC LIMIT 100');$q->execute([$id]);$items=[];
        foreach($q->fetchAll() as $r){$r['id']=(int)$r['id'];$r['battle_id']=$r['battle_id']===null?null:(int)$r['battle_id'];$r['created_at']=(int)$r['created_at'];$r['body']=json_decode($r['body_json'],true);unset($r['body_json']);$items[]=$r;}return ['items'=>$items];
    }
    private static function spend(array &$b,array $cost): void
    {
        foreach($cost as $key=>$value){if(($b['resources'][$key]??0)<$value){throw new RuntimeException('Not enough '.$key.'. Collect resources or wait for your factories.');}}
        foreach($cost as $key=>$value){$b['resources'][$key]-=$value;}
    }
    public static function upgradeCost(string $type,int $currentLevel): array
    {
        $spec = Catalog::buildings()[$type];
        if ((isset($spec['defenseBehavior']) || in_array($type,['baiter','siegelab','townhall'],true)) && isset($spec['levels'][$currentLevel + 1])) {
            return $spec['levels'][$currentLevel + 1]['cost'];
        }
        return array_map(fn($n)=>(int)ceil($n*pow(1.85,$currentLevel)), $spec['cost']);
    }
    public static function validPosition(array $b,string $type,float $x,float $y,?int $skip=null): void
    {
        if(!is_finite($x)||!is_finite($y)){throw new RuntimeException('Choose a valid position.');}
        $catalog=Catalog::buildings();$size=$catalog[$type]['size'];$half=$size/2;$epsilon=1e-9;
        if(!YardSpace::contains($b,$x,$y,1+$half-$epsilon)){throw new RuntimeException('Place buildings within the yard boundary.');}
        if (Seasonal::overlaps($b,$x,$y,$size)) { throw new RuntimeException('Finish the yard encounter or choose another spot.'); }
        if (Mushrooms::overlaps($b,$x,$y,$size)) { throw new RuntimeException('Pick the mushroom before building on this spot.'); }
        if (class_exists('Decorations') && Decorations::overlaps($b,$x,$y,$size)) { throw new RuntimeException('Move the decoration before building on this spot.'); }
        foreach($b['buildings'] as $other){if((int)$other['id']===$skip){continue;}$gap=($size+$catalog[$other['type']]['size'])/2+0.15;if(abs($x-$other['x'])<$gap-$epsilon&&abs($y-$other['y'])<$gap-$epsilon){throw new RuntimeException('That space overlaps another building.');}}
    }
    public static function mutate(int $id,string $action,array $input): array
    {
        return Db::transaction(function(PDO $pdo) use($id,$action,$input){
            $b=self::loadBase($id,true);$now=time();self::accrue($b,$now);
            $q=$pdo->prepare("SELECT id FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?) LIMIT 1");$q->execute([$id,$id]);if($q->fetchColumn()){throw new RuntimeException('Your yard is involved in a battle. Finish the battle before making changes.');}
            if ((isset($b['baiterPractice']['state']['molochWave']) || isset($b['baiterPractice']['state']['wmiWave']) || isset($b['baiterPractice']['state']['emergenceWave'])) && $b['baiterPractice']['state']['status']==='active'
                && in_array($action,['build','upgrade','move','repair','repairBase','fortify','cancelFortify','plannerApply','garrison','ungarrison','championFreeze','championThaw','championFeed','yardPurchase'],true)) {
                throw new RuntimeException('Finish or surrender the current invasion wave before changing your defenses.');
            }
            $catalog=Catalog::buildings();$monsters=Catalog::homeMonsters();$speed=self::speed();
            if(in_array($action,['juice','garrison','ungarrison'],true)){
                // Reserve and bunker ownership are checked at transfer time, including native troops bought from the Store.
                $monsters=Catalog::monsters();
            }
            if ($action==='emergenceRaise') { InfernoEmergence::raisePortal($b,$input,$now); }
            elseif ($action==='seasonalAction') { $seasonResult=Seasonal::act($b,$input,$now); }
            elseif ($action==='yardPurchase') { YardUtilities::purchase($b,$input,$now); }
            elseif(in_array($action,['siegeResearch','siegeBuild','siegeCancel'],true)){
                $siegeResult=SiegeWorkshop::act($b,$action,$input,$now,$speed);
            }elseif($action==='mushroomPick'){
                $mushroomPick = Mushrooms::start($b,$id,$input['id']??null,$now);
            }elseif(in_array($action,['decorationBuy','decorationMove','decorationStore','decorationPlace'],true)){
                Decorations::mutate($b,self::user($id),$action,$input);
            }elseif(in_array($action,['radioTune','radioRead'],true)){
                YardMessages::radioAction($b,$action,$input);
            }elseif($action==='questClaim'){
                $questId=$input['quest']??null;
                $viewer=self::user($id);
                if (in_array($questId,['GA1','GA2','GA3'],true)) { $viewer['giftsAccepted']=QuestSocial::acceptedGifts($id); }
                if (isset($b['infernoRealm']) && is_string($questId) && str_starts_with($questId,'I') && !str_starts_with($questId,'INVITE')) {
                    InfernoRealm::accrue($b['infernoRealm'],$now);
                }
                $questClaim=Quests::claim($b,$viewer,$questId,self::capacity($b));
            }elseif($action==='repairBase'){
                $ids=[];
                $repairResult=['action'=>'repairBase','repaired'=>self::repairHome($b,null,$ids),'buildingIds'=>$ids];
            }elseif($action==='hireWorker'){
                Construction::hire($b,$now);
            }elseif(in_array($action,['plannerSave','plannerDelete','plannerApply'],true)){
                Planner::mutateBase($b,$action,$input,$now);
            }elseif(in_array($action,['championRaise','championFeed','championFreeze','championThaw'],true)){
                self::mutateChampion($b,$action,$input,$now,$speed);
            }elseif($action==='fortify'||$action==='cancelFortify'){
                $buildingId=self::idInput($input);$index=null;
                foreach($b['buildings'] as $i=>$building){if((int)$building['id']===$buildingId){$index=$i;break;}}
                if($index===null){throw new RuntimeException('That building is not in your yard.');}
                $building=&$b['buildings'][$index];$status=self::fortificationStatus($b,$building,$now,$speed);
                if($action==='fortify'){
                    if(!$status['available']){throw new RuntimeException($status['reason']);}
                    Construction::assertAvailable($b,$now);
                    $next=$status['next'];self::spend($b,$next['cost']);
                    $building['fortify_to']=$next['tier'];$building['fortify_ready_at']=$now+$next['duration'];$building['fortify_paid']=$next['cost'];
                }else{
                    if(!$status['active']){throw new RuntimeException('There is no unfinished fortification to cancel.');}
                    $refund=['action'=>'cancelFortify']+$status['cancelRefund'];
                    foreach($refund['credited'] as $resource=>$amount){$b['resources'][$resource]+=$amount;}
                    $building['fortify_ready_at']=0;$building['fortify_to']=null;$building['fortify_paid']=null;
                }
                unset($building);
            }elseif($action==='labResearch'){
                $type=self::typeInput($input);$spec=Catalog::labAbilities()[$type]??null;
                if(!$spec){throw new RuntimeException('This species has no Monster Lab ability.');}
                if($b['lab']['queue']!==null){throw new RuntimeException('The Monster Lab is already researching an ability.');}
                if(!in_array($type,$b['unlocked'],true)){throw new RuntimeException('Unlock this species in the Monster Locker first.');}
                $tier=(int)($b['lab']['levels'][$type]??0)+1;
                if($tier>$spec['maxLevel']){throw new RuntimeException('This ability has reached its highest tier.');}
                $stats=Catalog::labStats($type,$tier);
                if(self::livingLevel($b,'lab')<$stats['requiredLabLevel']){throw new RuntimeException('A completed, living Monster Lab at level '.$stats['requiredLabLevel'].' is required.');}
                if((int)($b['research'][$type]??1)<$stats['requiredMonsterLevel']){throw new RuntimeException('Train this species to level '.$stats['requiredMonsterLevel'].' in the Monster Academy first.');}
                self::spend($b,$stats['cost']);
                $b['lab']['queue']=['type'=>$type,'target_level'=>$tier,'started_at'=>$now,'ready_at'=>$now+max(1,(int)ceil($stats['researchTime']/$speed)),'paid_putty'=>(int)$stats['cost']['putty']];
            }elseif($action==='labCancel'){
                $job=$b['lab']['queue'];
                if(!is_array($job)){throw new RuntimeException('There is no unfinished Monster Lab study to cancel.');}
                $potential=max(0,(int)($job['paid_putty']??0));
                $credited=max(0,min($potential,self::capacity($b)-(int)$b['resources']['putty']));
                $b['resources']['putty']+=$credited;$b['lab']['queue']=null;
                $refund=['action'=>'labCancel','putty'=>$credited,'potential'=>$potential,'overflow'=>$potential-$credited];
            }elseif($action==='collect'){
                $cap=self::capacity($b);$collected=['twig'=>0,'pebble'=>0,'putty'=>0,'goo'=>0];
                foreach($b['buildings'] as &$building){$c=$catalog[$building['type']];if(!isset($c['resource'])){continue;}$r=$c['resource'];$take=min((int)floor($building['stored']??0),max(0,$cap-$b['resources'][$r]));$b['resources'][$r]+=$take;$building['stored']=max(0,($building['stored']??0)-$take);$collected[$r]+=$take;}unset($building);
                Quests::harvested($b,$collected);
                Seasonal::credit($b,(int)ceil(array_sum($collected)/2));
            }elseif($action==='build'){
                $type=self::typeInput($input);if(!isset($catalog[$type])||$type==='townhall'||(!empty($catalog[$type]['siteOnly']) && (empty($catalog[$type]['emergenceReward']) || (!InfernoEmergence::freePassage($b) && !($type==='magmatower' && MolochSiege::homeMagmaLimit($b)>0))))){throw new RuntimeException('Choose an available building.');}$c=$catalog[$type];
                $townHallLevel=self::townhallLevel($b);
                if($townHallLevel<$c['unlockLevel']){throw new RuntimeException('Upgrade your Town Hall to level '.$c['unlockLevel'].' first.');}
                if (in_array($type,['baiter','railgun'],true) && self::livingLevel($b, 'townhall') < $c['unlockLevel']) {
                    throw new RuntimeException('A working Town Hall level '.$c['unlockLevel'].' is required.');
                }
                if(isset($c['requiresBuilding'])&&self::livingLevel($b,$c['requiresBuilding'])<1){throw new RuntimeException('Build or repair a '.$catalog[$c['requiresBuilding']]['name'].' first.');}
                $buildingLimit=TownHall::buildingLimit($townHallLevel);
                if(count($b['buildings'])>=$buildingLimit){throw new RuntimeException('Your yard has reached its '.$buildingLimit.'-building limit.');}
                if($type==='hatcherycontrol' && count(YardUtilities::hatcheries($b,$now,2))<3){throw new RuntimeException('Three working level 2 Hatcheries are required.');}
                $rewardBuilding=!empty($c['brukkargReward']);
                if ($rewardBuilding) { SpurtzRewards::assertBuild($b,$type); }
                $counts=array_count_values(array_column($b['buildings'],'type'));
                $limit=TownHall::quantity($c,$townHallLevel);
                if($type==='magmatower')$limit=max($limit,MolochSiege::homeMagmaLimit($b));
                if (($counts[$type] ?? 0) >= $limit) {
                    throw new RuntimeException('You have reached the limit for this building.');
                }
                if (!$rewardBuilding) { Construction::assertAvailable($b,$now); }
                $x=self::coordinate($input,'x');$y=self::coordinate($input,'y');self::validPosition($b,$type,$x,$y);self::spend($b,$c['cost']);
                $b['buildings'][]=['id'=>$b['next_building_id']++,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$rewardBuilding?1:0,'hp'=>$rewardBuilding?$c['hp']:0,'max_hp'=>$c['hp'],'ready_at'=>$rewardBuilding?0:$now+max(1,(int)ceil($c['buildTime']/$speed)),'upgrade_to'=>$rewardBuilding?null:1,'stored'=>0];
            }elseif(in_array($action,['move','upgrade','repair'],true)){
                $rawId=$input['id']??null;if(!is_int($rawId)&&!is_string($rawId)){throw new RuntimeException('Choose a valid building.');}$buildingId=filter_var($rawId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($buildingId===false){throw new RuntimeException('Choose a valid building.');}$index=null;foreach($b['buildings'] as $i=>$v){if((int)$v['id']===$buildingId){$index=$i;break;}}if($index===null){throw new RuntimeException('That building is not in your yard.');}
                $building=&$b['buildings'][$index];$c=$catalog[$building['type']];
                if($action!=='repair'&&$building['ready_at']>0){throw new RuntimeException('This building is still under construction.');}
                if($action!=='repair'&&(int)($building['fortify_ready_at']??0)>$now){throw new RuntimeException('Finish or cancel fortification before changing this building.');}
                if($action==='move'){$x=self::coordinate($input,'x');$y=self::coordinate($input,'y');self::validPosition($b,$building['type'],$x,$y,$buildingId);$building['x']=$x;$building['y']=$y;}
                elseif($action==='upgrade'){
                    if($building['type']==='lab'&&$b['lab']['queue']!==null){throw new RuntimeException('Finish or cancel the current Lab study before upgrading the building.');}
                    $level=(int)$building['level'];if($level>=$c['maxLevel']){throw new RuntimeException('This building is already at its highest level.');}
                    if($building['hp']<$building['max_hp']){throw new RuntimeException('Repair the building before upgrading it.');}
                    $duration = $building['type']==='townhall' && $level>=5 ? $c['levels'][$level+1]['buildTime'] : $c['buildTime']*pow(1.6,$level);
                    if (!empty($c['brukkargReward']) || !empty($c['emergenceReward']) || in_array($building['type'],['siegelab','railgun'],true)) {
                        if ($building['type']==='siegelab' && !empty($b['siege']['research'])) { throw new RuntimeException('Finish or cancel the device study before upgrading your Chaos Lab.'); }
                        $next=$c['levels'][$level+1];
                        $hallGate=!empty($c['emergenceReward']) ? min(5,$next['townHall']) : $next['townHall'];
                        if (self::livingLevel($b,'townhall')<$hallGate) { throw new RuntimeException('A working Town Hall level '.$hallGate.' is required.'); }
                        $duration=$next['buildTime'];
                    } elseif ($building['type'] === 'baiter') {
                        $next = $c['levels'][$level + 1];
                        if (self::livingLevel($b, 'townhall') < $next['townHall'] || self::livingLevel($b, 'locker') < $next['locker']) {
                            throw new RuntimeException('This upgrade requires a working Town Hall level '.$next['townHall'].' and Monster Locker level '.$next['locker'].'.');
                        }
                        $duration = $next['buildTime'];
                    } elseif($building['type']!=='townhall'&&$level>=self::townhallLevel($b)){throw new RuntimeException('Upgrade your Town Hall before upgrading this building again.');}
                    Construction::assertAvailable($b,$now);
                    self::spend($b,self::upgradeCost($building['type'],$level));$building['upgrade_to']=$level+1;
                    $headStart=StorePowers::expiry($b,'BST')>$now?.8:1;
                    $building['ready_at']=$now+max(1,(int)ceil($duration*$headStart/$speed));
                }else{
                    if((int)$building['level']<1){throw new RuntimeException('Complete this building’s construction before repairing it.');}
                    unset($building);
                    $ids=[];
                    $repairResult=['action'=>'repair','repaired'=>self::repairHome($b,$buildingId,$ids),'buildingIds'=>$ids];
                }unset($building);
            }elseif($action==='train'){
                $type=self::typeInput($input);$rawQuantity=$input['quantity']??1;$quantity=(is_int($rawQuantity)||is_string($rawQuantity))?filter_var($rawQuantity,FILTER_VALIDATE_INT):false;if(!isset($monsters[$type])||$quantity===false||$quantity<1||$quantity>30){throw new RuntimeException('Choose a monster and a quantity from 1 to 30.');}$c=$monsters[$type];
                $c=($c['levels'][(int)($b['research'][$type]??1)]??[])+$c;
                if(!in_array($type,$b['unlocked'],true)){throw new RuntimeException('Unlock this species in the Monster Locker before hatching it.');}
                if(self::townhallLevel($b)<$c['unlockLevel']){throw new RuntimeException('This monster needs Town Hall level '.$c['unlockLevel'].'.');}
                $lanes=[];foreach($b['buildings'] as $building){if($building['type']==='hatchery'&&$building['level']>0&&$building['hp']>0&&($building['ready_at']??0)<=$now){$lanes[$building['id']]=$now;}}
                if(!$lanes){throw new RuntimeException('Build or repair a Hatchery to produce monsters.');}
                if(count($b['queue'])+$quantity>100){throw new RuntimeException('The training queue can hold up to 100 monsters.');}
                if(self::housingUsed($b)+$quantity*$c['size']>self::housingCapacity($b)){throw new RuntimeException('Build or upgrade Monster Housing to make room for this army.');}
                self::spend($b,['goo'=>$c['cost']*$quantity]);
                foreach($b['queue'] as $job){$lane=(int)($job['lane']??array_key_first($lanes));if(isset($lanes[$lane])){$lanes[$lane]=max($lanes[$lane],(int)$job['ready_at']);}}
                $work=max(1,(int)ceil($c['trainTime']/$speed));
                for($i=0;$i<$quantity;$i++){
                    asort($lanes);$lane=array_key_first($lanes);$started=$lanes[$lane];
                    $duration=StorePowers::workTime($b,$started,$work);$lanes[$lane]+=$duration;
                    $b['queue'][]=['id'=>$b['next_queue_id']++,'type'=>$type,'quantity'=>1,'lane'=>$lane,'started_at'=>$started,
                        'duration'=>$duration,'paid_goo'=>$c['cost'],'ready_at'=>$lanes[$lane]]+($duration===$work?[]:['work'=>$work]);
                }
            }elseif($action==='unlock'){
                $type=self::typeInput($input);$c=$monsters[$type]??null;
                if(!$c){throw new RuntimeException('Choose a known monster species.');}
                if(in_array($type,$b['unlocked'],true)){throw new RuntimeException('This species is already unlocked.');}
                if($b['unlockQueue']){throw new RuntimeException('The Monster Locker is already unlocking a species.');}
                if($type==='vorg'&&CreatureCarnage::discoveryBlocked($b,$now)){throw new RuntimeException('Finish your current Creature Carnage run or wait for its deadline before buying Vorg discovery.');}
                $requiredHall=$c['unlockHall']??$c['unlockLevel'];
                if(self::townhallLevel($b)<$requiredHall){throw new RuntimeException('This species needs Town Hall level '.$requiredHall.'.');}
                if(self::livingLevel($b,'locker')<(int)$c['lockerLevel']){throw new RuntimeException('A completed, living Monster Locker at level '.$c['lockerLevel'].' is required.');}
                self::spend($b,$c['unlockCost']);
                $b['unlockQueue'][]=['type'=>$type,'ready_at'=>$now+StorePowers::workTime($b,$now,max(1,(int)ceil($c['unlockTime']/$speed)),true)];
            }elseif($action==='juice'){
                $type=self::typeInput($input);$quantity=self::quantityInput($input,200);$c=$monsters[$type]??null;
                if(!$c){throw new RuntimeException('Choose a known monster species.');}
                if(self::livingLevel($b,'juicer')<1){throw new RuntimeException('Build or repair a Monster Juicer first.');}
                if(($b['army'][$type]??0)<$quantity){throw new RuntimeException('There are not enough owned monsters of this species to recycle.');}
                $potential=(int)floor($c['cost']*$quantity*(float)$catalog['juicer']['refundRate']);
                $credited=max(0,min($potential,self::capacity($b)-(int)$b['resources']['goo']));
                if($credited===0){throw new RuntimeException('Goo storage is full. Spend goo before recycling monsters.');}
                $b['army'][$type]-=$quantity;$b['resources']['goo']+=$credited;
                Quests::juiced($b,$quantity);
                $refund=['action'=>'juice','goo'=>$credited,'potential'=>$potential,'overflow'=>$potential-$credited];
            }elseif($action==='garrison'||$action==='ungarrison'){
                $buildingId=self::idInput($input);$type=self::typeInput($input);$quantity=self::quantityInput($input,200);$c=$monsters[$type]??null;
                if(!$c){throw new RuntimeException('Choose a known monster species.');}
                $bunker=null;foreach($b['buildings'] as $building){if((int)$building['id']===$buildingId&&$building['type']==='bunker'){$bunker=$building;break;}}
                if(!$bunker){throw new RuntimeException('Choose a Monster Bunker in your own yard.');}
                if($bunker['level']<1||$bunker['hp']<=0||($bunker['ready_at']??0)>$now){throw new RuntimeException('Complete or repair this Monster Bunker before transferring monsters.');}
                $stored=$b['garrison'][(string)$buildingId]??[];
                if($action==='garrison'){
                    if(($b['army'][$type]??0)<$quantity){throw new RuntimeException('There are not enough owned monsters to station in this bunker.');}
                    if(self::bunkerUsed($b,$buildingId)+$quantity*$c['size']>self::bunkerCapacity($b,$buildingId)){throw new RuntimeException('This bunker does not have enough garrison capacity.');}
                    $b['army'][$type]-=$quantity;$stored[$type]=(int)($stored[$type]??0)+$quantity;
                }else{
                    if(($stored[$type]??0)<$quantity){throw new RuntimeException('This bunker does not contain that many monsters.');}
                    if(self::housingUsed($b)+$quantity*$c['size']>self::housingCapacity($b)){throw new RuntimeException('Your housing is full. Make room before returning garrison monsters.');}
                    $stored[$type]-=$quantity;$b['army'][$type]=(int)($b['army'][$type]??0)+$quantity;
                }
                $b['garrison'][(string)$buildingId]=array_filter($stored,fn($count)=>$count>0);
            }elseif($action==='cancelTrain'){
                $jobId=self::idInput($input);$index=null;
                foreach($b['queue'] as $i=>$job){if((int)$job['id']===$jobId){$index=$i;break;}}
                if($index===null){throw new RuntimeException('That training job has completed or is not in your queue.');}
                $job=$b['queue'][$index];$spec=$monsters[$job['type']];
                $potential=(int)($job['paid_goo']??$spec['cost']*(int)($job['quantity']??1));
                $credited=max(0,min($potential,self::capacity($b)-(int)$b['resources']['goo']));
                array_splice($b['queue'],$index,1);$b['resources']['goo']+=$credited;
                // Close only the cancelled lane's gap. Other hatcheries keep their timers.
                $duration=max(1,(int)($job['duration']??ceil($spec['trainTime']/$speed)));
                $slot=max($now,(int)($job['started_at']??($job['ready_at']-$duration)));
                foreach($b['queue'] as &$queued){
                    if(($queued['lane']??null)===($job['lane']??null)&&(int)$queued['ready_at']>(int)$job['ready_at']){
                        $work=max(1,(int)($queued['work']??$queued['duration']??ceil($monsters[$queued['type']]['trainTime']/$speed)));
                        $queuedDuration=StorePowers::workTime($b,$slot,$work);if($queuedDuration!==$work)$queued['work']=$work;
                        $queued['started_at']=$slot;$queued['duration']=$queuedDuration;$queued['ready_at']=$slot+$queuedDuration;$slot=$queued['ready_at'];
                    }
                }unset($queued);
                $refund=['action'=>'cancelTrain','goo'=>$credited,'potential'=>$potential,'overflow'=>$potential-$credited];
            }elseif($action==='research'){
                $type=self::typeInput($input);if(!isset($monsters[$type])){throw new RuntimeException('Choose a monster to research.');}
                if(!in_array($type,$b['unlocked'],true)){throw new RuntimeException('Unlock this species before researching its upgrades.');}
                if(!empty($b['researchQueue'])){throw new RuntimeException('The academy is already researching another upgrade.');}
                $academy=0;foreach($b['buildings'] as $building){if($building['type']==='academy'&&$building['hp']>0&&($building['ready_at']??0)<=$now){$academy=max($academy,$building['level']);}}
                if(!$academy){throw new RuntimeException('Build or repair a Monster Academy first.');}
                $level=(int)($b['research'][$type]??1);if($level>=6){throw new RuntimeException('This monster has reached its highest research level.');}
                if($academy<$level){throw new RuntimeException('Upgrade your Monster Academy to level '.$level.' first.');}
                if(self::townhallLevel($b)<$monsters[$type]['unlockLevel']){throw new RuntimeException('Upgrade the Town Hall to unlock this monster first.');}
                $study=$monsters[$type]['levels'][$level]['nextStudy']??['cost'=>['goo'=>$monsters[$type]['cost']*4*$level,'putty'=>300*$level],'seconds'=>120*$level];
                self::spend($b,$study['cost']);$b['researchQueue'][]=['type'=>$type,'target_level'=>$level+1,'ready_at'=>$now+max(1,(int)ceil($study['seconds']/$speed))];
            }else{throw new RuntimeException('Unknown yard action.');}
            Seasonal::observe($b,$now);
            self::saveBase($id,$b);return ['base'=>self::publicBase($id,$b),'army'=>$b['army'],'queue'=>$b['queue'],'research'=>$b['research'],'researchQueue'=>$b['researchQueue'],'unlocked'=>$b['unlocked'],'unlockQueue'=>$b['unlockQueue'],'garrison'=>$b['garrison'],'champions'=>self::championRoster($b),'activeChampion'=>$b['activeChampion'],'lab'=>$b['lab'],'collected'=>$collected??null,'refund'=>$refund??null,'repairResult'=>$repairResult??null,'siegeResult'=>$siegeResult??null,'questClaim'=>$questClaim??null,'mushroomPick'=>$mushroomPick??null,'seasonResult'=>$seasonResult??null];
        });
    }
    private static function mutateChampion(array &$base,string $action,array $input,int $now,float $speed): void
    {
        if (self::livingLevel($base,'championcage')<1) { throw new RuntimeException('Build or repair a Champion Cage first.'); }
        $catalog=Catalog::champions();
        if ($action==='championRaise') {
            $type=self::typeInput($input);
            if (!isset($catalog[$type])) { throw new RuntimeException('Choose a known champion.'); }
            if($type==='korath'&&!MonsterMadness::power($base)){throw new RuntimeException('Earn Korath by capturing resource outposts in Monster Madness.');}
            if ($base['activeChampion']!==null) { throw new RuntimeException('Freeze your active champion before raising another.'); }
            if (isset($base['champions'][$type])) { throw new RuntimeException('You already own this champion. Thaw it from the Champion Chamber.'); }
            $capacity=self::livingLevel($base,'championchamber')>0?count($catalog):1;
            if (count($base['champions'])>=$capacity) { throw new RuntimeException('Build or repair a Champion Chamber to keep additional champions.'); }
            $stats=Catalog::championStats($type,1);
            $next=$now+max(1,(int)ceil($catalog[$type]['feedInterval']/$speed));
            $base['champions'][$type]=['level'=>1,'hp'=>$stats['hp'],'feeds'=>0,'next_feed_at'=>$next,
                'last_heal_at'=>$now,'last_hunger_at'=>$next,'battle_id'=>0,'frozen'=>false,'paused_feed_seconds'=>0,'bonus_feeds'=>0];
            $base['activeChampion']=$type;
            return;
        }
        if ($action==='championThaw') {
            $type=self::typeInput($input);
            if (!isset($catalog[$type],$base['champions'][$type])||empty($base['champions'][$type]['frozen'])) { throw new RuntimeException('Choose a frozen champion that you own.'); }
            if ($base['activeChampion']!==null) { throw new RuntimeException('Freeze your active champion before thawing another.'); }
            $healthyChamber=false;
            foreach ($base['buildings'] as $building) {
                if ($building['type']==='championchamber'&&$building['level']>0&&$building['hp']>0&&$building['hp']>=$building['max_hp']&&(int)($building['ready_at']??0)<=$now) { $healthyChamber=true;break; }
            }
            if (!$healthyChamber) { throw new RuntimeException('Fully repair and complete the Champion Chamber before thawing a champion.'); }
            $champion=&$base['champions'][$type];
            $champion['next_feed_at']=$now+max(0,(int)$champion['paused_feed_seconds']);
            $champion['last_hunger_at']=$champion['next_feed_at'];
            $champion['paused_feed_seconds']=0;$champion['last_heal_at']=$now;$champion['frozen']=false;
            $base['activeChampion']=$type;unset($champion);return;
        }
        $type=$base['activeChampion'];
        if (!is_string($type)||!isset($catalog[$type],$base['champions'][$type])||!empty($base['champions'][$type]['frozen'])) { throw new RuntimeException('Raise or thaw a champion first.'); }
        $champion=&$base['champions'][$type];$spec=$catalog[$type];
        if ((int)$champion['battle_id']>0) { throw new RuntimeException('This champion is still involved in a battle.'); }
        $level=(int)$champion['level'];$stats=Catalog::championStats($type,$level,(int)($champion['bonus_feeds']??0));
        if ($action==='championFreeze') {
            if (self::livingLevel($base,'championchamber')<1) { throw new RuntimeException('Build or repair a Champion Chamber first.'); }
            if ($champion['hp']<$stats['hp']) { throw new RuntimeException('Let this champion fully recover before freezing it.'); }
            if ((int)$champion['next_feed_at']<$now) { throw new RuntimeException('Feed this champion before freezing it.'); }
            $frozen=count(array_filter($base['champions'],static fn(array $row):bool=>!empty($row['frozen'])));
            if ($frozen>=count($catalog)) { throw new RuntimeException('The Champion Chamber is full.'); }
            $champion['paused_feed_seconds']=max(0,(int)$champion['next_feed_at']-$now);
            $champion['frozen']=true;$champion['last_heal_at']=$now;$base['activeChampion']=null;
            unset($champion);return;
        }
        if ((int)$champion['next_feed_at']>$now) { throw new RuntimeException('This champion is still digesting its last meal.'); }
        $recipe=self::championRecipe($type,$level,(int)($champion['bonus_feeds']??0));
        if($type==='korath'){
            if(empty($base['infernoRealm'])){throw new RuntimeException('Korath feeds on monsters raised in your Inferno Compound.');}
            InfernoMonsters::accrue($base['infernoRealm'],$now,$speed);
            $food=&$base['infernoRealm']['army'];
        }else{$food=&$base['army'];}
        foreach ($recipe as $monster=>$quantity) {
            if (($food[$monster]??0)<$quantity) { throw new RuntimeException('This meal needs '.$quantity.' '.Catalog::monsters()[$monster]['name'].' from your reserve army.'); }
        }
        foreach ($recipe as $monster=>$quantity) { $food[$monster]-=$quantity; }
        if($type==='korath'){++$base['infernoRealm']['monsterRevision'];}
        unset($food,$champion);
        self::finishChampionMeal($base,$type,$now,$speed);
    }

    /** A validated reserve-army meal and a paid full meal share the same growth and digestion rules. */
    public static function finishChampionMeal(array &$base,string $type,int $now,float $speed):void
    {
        $champion=&$base['champions'][$type];$level=(int)$champion['level'];
        $stats=Catalog::championStats($type,$level,(int)($champion['bonus_feeds']??0));
        $spec=Catalog::champions()[$type];
        if($level===6){
            $oldMax=$stats['hp'];$champion['bonus_feeds']=min(3,(int)($champion['bonus_feeds']??0)+1);
            $newMax=Catalog::championStats($type,6,(int)$champion['bonus_feeds'])['hp'];
            if($champion['hp']>0){$champion['hp']=min((float)$newMax,(float)$champion['hp']+max(0,$newMax-$oldMax));}
        }
        if ($level<$spec['maxLevel']) { $champion['feeds']++; }
        if ($level<$spec['maxLevel']&&$champion['feeds']>=$spec['feedsRequired'][$level]) {
            $champion['level']++;$champion['feeds']=0;$champion['bonus_feeds']=0;
            $champion['hp']=Catalog::championStats($type,(int)$champion['level'])['hp'];
        }
        $champion['next_feed_at']=$now+max(1,(int)ceil($spec['feedInterval']/$speed));
        $champion['last_hunger_at']=$champion['next_feed_at'];$champion['last_heal_at']=$now;
        unset($champion);
    }

    private static function idInput(array $input): int
    {
        $raw=$input['id']??null;
        if(!is_int($raw)&&!is_string($raw)){throw new RuntimeException('Choose a valid item.');}
        $id=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false){throw new RuntimeException('Choose a valid item.');}return $id;
    }
    private static function quantityInput(array $input,int $maximum): int
    {
        $raw=$input['quantity']??1;
        if(!is_int($raw)&&!is_string($raw)){throw new RuntimeException('Choose a whole-number quantity.');}
        $quantity=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$maximum]]);
        if($quantity===false){throw new RuntimeException('Choose a quantity from 1 to '.$maximum.'.');}return $quantity;
    }
    private static function typeInput(array $input): string
    {
        $type=$input['type']??'';if(!is_string($type)){throw new RuntimeException('Choose a valid species or building.');}return $type;
    }
    private static function coordinate(array $data,string $key): float
    {
        if(!isset($data[$key])||!is_numeric($data[$key])){throw new RuntimeException('Choose a valid yard coordinate.');}return round((float)$data[$key],2);
    }
}
