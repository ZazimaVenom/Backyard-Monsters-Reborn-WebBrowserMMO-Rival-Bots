<?php
declare(strict_types=1);
require_once __DIR__.'/AlliancePowers.php';
require_once __DIR__.'/Regions.php';
require_once __DIR__.'/Truces.php';
require_once __DIR__.'/OutpostYards.php';

final class WorldUpgradeRequiredException extends RuntimeException {}

/** Persistent odd-row hex territories. All writes take home-base locks before site locks. */
final class World
{
    public const WIDTH=64;
    public const HEIGHT=64;
    public const CAPTURE_THRESHOLD=90;
    public const FORTIFICATIONS_VERSION='1';
    public const REGISTRATION_RESERVE=512;
    public const NETWORK_REDUCTION=[0.0,0.07,0.13,0.23,0.35,0.50,0.75];

    public static function assertSchema(): void
    {
        $pdo=Db::pdo();
        try{
            $version=$pdo->query("SELECT value FROM settings WHERE name='schema_version'")->fetchColumn();
            if($version!=='3'){throw new WorldUpgradeRequiredException('This saved world needs the world upgrade. Stop the server and worker, then run php bin/upgrade.php --check followed by --apply. Do not rerun setup.');}
            $tables=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('world_sites','alliances','alliance_members','alliance_requests','keeper_friends','keeper_gifts','keeper_mail','alliance_stances','alliance_wars','alliance_war_battles')")->fetchColumn();
            $columns=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='battles' AND COLUMN_NAME IN ('target_kind','target_id')")->fetchColumn();
            if($tables!==10||$columns!==2){throw new WorldUpgradeRequiredException('The world schema upgrade is incomplete. Stop the server and worker, then rerun php bin/upgrade.php --apply. Existing data will be preserved.');}
        }catch(PDOException $error){
            if((int)($error->errorInfo[1]??0)===1146){throw new WorldUpgradeRequiredException('The installed world is missing required tables. Stop the web server and worker, then run php bin/upgrade.php --check.',0,$error);}
            // Connection, server and temporary resource errors use the standard database error path.
            throw $error;
        }
    }

    public static function distance(int $q1,int $r1,int $q2,int $r2): int
    {
        $x1=$q1-intdiv($r1-($r1&1),2);$x2=$q2-intdiv($r2-($r2&1),2);
        return max(abs($x1-$x2),abs($r1-$r2),abs(($x1+$r1)-($x2+$r2)));
    }

    /** In-bounds neighbors in the same odd-row coordinate system as distance(). */
    public static function neighbors(int $q,int $r): array
    {
        $offset=$r&1;$result=[];
        foreach([[-1,0],[1,0],[$offset-1,-1],[$offset,-1],[$offset-1,1],[$offset,1]] as [$dq,$dr]){
            $x=$q+$dq;$y=$r+$dr;if($x>=Regions::left($q)&&$x<=Regions::right($q)&&$y>=0&&$y<self::HEIGHT){$result[]=['q'=>$x,'r'=>$y];}
        }
        return $result;
    }

    /** Pure deterministic migration plan. Occupied coordinates are never replaced. */
    public static function fortificationPositions(array $occupied,int $reserve=self::REGISTRATION_RESERVE): array
    {
        $available=max(0,self::WIDTH*self::HEIGHT-count($occupied)-max(0,$reserve));$positions=[];
        foreach($occupied as $key=>$site){
            if(!in_array($site['kind']??null,['resource','stronghold'],true)){continue;}
            [$q,$r]=array_map('intval',explode(',',(string)$key));
            foreach(self::neighbors($q,$r) as $neighbor){$key=$neighbor['q'].','.$neighbor['r'];if(!isset($occupied[$key])){$positions[$key]=$neighbor;}}
        }
        $positions=array_values($positions);usort($positions,fn($a,$b)=>($a['r']<=>$b['r'])?:($a['q']<=>$b['q']));
        return array_slice($positions,0,$available);
    }

    /** Pure source rule: only homes/resource outposts/strongholds receive support. */
    public static function fortificationSupportFromSites(?int $ownerId,int $q,int $r,string $kind,array $fortifications): array
    {
        $index=[];foreach($fortifications as $site){$index[$site['q'].','.$site['r']]=$site;}
        return self::supportFromIndex($ownerId,$q,$r,$kind,$index);
    }

    private static function supportFromIndex(?int $ownerId,int $q,int $r,string $kind,array $index): array
    {
        $ids=[];
        if($ownerId!==null&&$ownerId>0&&in_array($kind,['home','resource','stronghold'],true)){
            foreach(self::neighbors($q,$r) as $neighbor){$site=$index[$neighbor['q'].','.$neighbor['r']]??null;
                if($site!==null&&$site['kind']==='fortification'&&(int)($site['owner_id']??0)===$ownerId){$ids[]=(int)$site['id'];}
            }
        }
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);$count=count($ids);
        return ['count'=>$count,'reduction'=>self::NETWORK_REDUCTION[$count],'siteIds'=>$ids];
    }

    /** Caller locks the real defending owner's home before taking a battle snapshot. */
    public static function fortificationSupport(?int $ownerId,int $q,int $r): array
    {
        if($ownerId===null||$ownerId<=0){return ['count'=>0,'reduction'=>0.0,'siteIds'=>[]];}
        $query=Db::pdo()->prepare('SELECT id,kind,owner_id,q,r FROM world_sites WHERE q BETWEEN ? AND ? AND r BETWEEN ? AND ?');
        $query->execute([max(Regions::left($q),$q-1),min(Regions::right($q),$q+1),max(0,$r-1),min(63,$r+1)]);$rows=$query->fetchAll();$kind='';
        foreach($rows as $site){if((int)$site['q']===$q&&(int)$site['r']===$r&&(int)($site['owner_id']??0)===$ownerId){$kind=$site['kind'];break;}}
        return self::fortificationSupportFromSites($ownerId,$q,$r,$kind,$rows);
    }

    public static function siteStats(string $kind,int $level): array
    {
        if(!in_array($kind,['resource','stronghold','fortification'],true)){throw new RuntimeException('Choose a resource outpost, stronghold or Fortification site.');}
        $max=$kind==='resource'?5:3;
        if($level<1||$level>$max){throw new RuntimeException('Invalid territory level.');}
        $cost=match($kind){'resource'=>3000,'fortification'=>4000,default=>6000};$mult=2**($level-1);
        return ['resourcePerSecond'=>$kind==='resource'?[0.05,0.1,0.2,0.4,0.8][$level-1]:0.0,
            'capacity'=>$kind==='resource'?[2000,4000,8000,16000,32000][$level-1]:0,
            'range'=>match($kind){'resource'=>$level+1,'stronghold'=>[10,15,20][$level-1],default=>0},
            'damageBonus'=>$kind==='stronghold'?$level/10:0.0,'maxLevel'=>$max,
            'upgradeCost'=>['twig'=>$cost*$mult,'pebble'=>$cost*$mult,'putty'=>(int)($cost*$mult/4),'goo'=>0],
            'upgradeTime'=>match($kind){'resource'=>120,'fortification'=>150,default=>180}*$level,
            'repairCost'=>['twig'=>500*$level,'pebble'=>500*$level,'putty'=>100*$level,'goo'=>0],
            'repairTime'=>60*$level,'garrisonCapacity'=>100*min(3,$level)];
    }

    /** Pure fixed layout; neutral defenders are site monsters, never additional accounts. */
    public static function initialState(string $kind,int $level,int $now=0): array
    {
        $now=$now?:time();self::siteStats($kind,$level);$core=match($kind){'resource'=>'outpost','fortification'=>'outpostdefender',default=>'stronghold'};
        $layout=[[$core,16,16],['sniper',10,12],['cannon',21,11],['bunker',13,22],['storage',22,22]];
        if($kind==='stronghold'){$layout[]=['tesla',22,16];$layout[]=['sniper',9,20];}
        if($kind==='fortification'){$layout=[[$core,16,16],['sniper',10,12],['cannon',22,12],['bunker',13,22],['wall',15,25],['wall',16,25],['wall',17,25],['wall',18,25]];}
        $state=['buildings'=>[],'resources'=>array_fill_keys(['twig','pebble','putty','goo'],0),'army'=>[],
            'queue'=>[],'research'=>array_fill_keys(array_keys(Catalog::monsters()),min(3,$level)),
            'researchQueue'=>[],'unlocked'=>array_keys(Catalog::monsters()),'unlockQueue'=>[],
            'garrison'=>[],'champions'=>[],'activeChampion'=>null,'lab'=>['levels'=>[],'queue'=>null],
            'last_tick'=>$now,'shield_until'=>0,'ready_at'=>0,'upgrade_to'=>null,'repair_at'=>0,'next_building_id'=>1,'next_queue_id'=>1];
        foreach($layout as [$type,$x,$y]){
            $bl=$type===$core?$level:min(3,$level);$hp=$type==='outpost'?1:Game::buildingStats($type,$bl)['max_hp'];$id=$state['next_building_id']++;
            $state['buildings'][]=['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$bl,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
            if($type==='bunker'){$state['garrison'][(string)$id]=$kind==='resource'?['octo'=>2*$bl,'fink'=>2*$bl]:['octo'=>2*$bl,'fink'=>4*$bl];}
        }
        return Game::normalizeBase($state);
    }

    private static function homeValleyAnchors(): Generator
    {
        for($by=0;$by<8;++$by)for($bx=0;$bx<8;++$bx){
            foreach([['resource',1,1],['resource',5,2],['resource',2,5],['resource',6,6],['stronghold',4,4]] as [$kind,$dx,$dy]){
                yield [$kind,$bx*8+$dx,$by*8+$dy];
            }
        }
    }

    /** Only the original valley has mandatory historical anchors. Frontier outposts can be consumed. */
    public static function hasHomeValleyAnchors(PDO $pdo): bool
    {
        $map=[];
        foreach($pdo->query('SELECT kind,q,r FROM world_sites WHERE q BETWEEN 0 AND 63 AND r BETWEEN 0 AND 63') as $site){
            $map[$site['q'].','.$site['r']]=$site['kind'];
        }
        foreach(self::homeValleyAnchors() as [$kind,$q,$r]){
            if(($map[$q.','.$r]??null)!==$kind)return false;
        }
        return true;
    }

    /** Idempotent initializer uses the caller's PDO/transaction and never changes home bases. */
    public static function initialize(PDO $pdo): void
    {
        $set=$pdo->prepare("INSERT IGNORE INTO settings(name,value) VALUES('world_allocator','0')");$set->execute();
        $pdo->query("SELECT value FROM settings WHERE name='world_allocator' FOR UPDATE")->fetchColumn();
        $occupied=[];
        foreach($pdo->query('SELECT kind,q,r FROM world_sites WHERE q BETWEEN 0 AND 63 AND r BETWEEN 0 AND 63') as $row){
            $occupied[$row['q'].','.$row['r']]=$row;
        }
        $insert=$pdo->prepare('INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES(?,?,?,?,?,?,?, ?,1,?)');
        $now=time();$ordinal=0;
        foreach(self::homeValleyAnchors() as [$kind,$q,$r]){
            $key=$q.','.$r;++$ordinal;
            if(isset($occupied[$key])){
                if($occupied[$key]['kind']!==$kind)throw new RuntimeException('A saved map position conflicts with the territory layout. No home markers were moved.');
                continue;
            }
            $name=($kind==='resource'?'Wild Resource Outpost ':'Wild Stronghold ').sprintf('%03d',$ordinal);
            $insert->execute([$kind,$q,$r,null,null,$name,1,self::encode(self::initialState($kind,1,$now)),$now]);$occupied[$key]=['kind'=>$kind];
        }
        $cursor=(int)$pdo->query("SELECT value FROM settings WHERE name='world_allocator'")->fetchColumn();
        $missing=$pdo->query('SELECT u.id,u.name FROM users u LEFT JOIN world_sites s ON s.home_user_id=u.id WHERE s.id IS NULL ORDER BY u.id')->fetchAll();
        foreach($missing as $user){
            if(count($occupied)<4096){
                [$q,$r,$cursor]=self::freePosition($occupied,$cursor);
                $occupied[$q.','.$r]=['kind'=>'home'];
            }else{
                [$q,$r]=Regions::overflowPosition();
            }
            $insert->execute(['home',$q,$r,(int)$user['id'],(int)$user['id'],$user['name'],1,null,$now]);
        }
        $q=$pdo->prepare("UPDATE settings SET value=? WHERE name='world_allocator'");$q->execute([(string)$cursor]);
        // The allocator lock serializes installation, upgrades and registration.
        // Seed only once, after all existing homes have their preserved coordinates.
        $version=$pdo->query("SELECT value FROM settings WHERE name='world_fortifications_version'")->fetchColumn();
        if($version!==self::FORTIFICATIONS_VERSION){
            foreach(self::fortificationPositions($occupied) as $position){
                $q=$position['q'];$r=$position['r'];$name=sprintf('Wild Fortification %02d-%02d',$q,$r);
                $insert->execute(['fortification',$q,$r,null,null,$name,1,self::encode(self::initialState('fortification',1,$now)),$now]);
            }
            $set=$pdo->prepare("INSERT INTO settings(name,value) VALUES('world_fortifications_version',?) ON DUPLICATE KEY UPDATE value=VALUES(value)");$set->execute([self::FORTIFICATIONS_VERSION]);
        }
    }

    private static function freePosition(array $occupied,int $cursor): array
    {
        for($i=0;$i<self::WIDTH*self::HEIGHT;$i++){$n=($cursor+$i)%4096;$cell=($n*1031+2080)%4096;$q=$cell%64;$r=intdiv($cell,64);if(!isset($occupied[$q.','.$r])){return [$q,$r,($n+1)%4096];}}
        throw new RuntimeException('This local map is full. A new keeper cannot be registered until map space becomes available.');
    }

    public static function assignHome(int $userId): array
    {
        $pdo=Db::pdo();$old=self::home($userId);if($old){return $old;}
        $pdo->exec("INSERT IGNORE INTO settings(name,value) VALUES('world_allocator','0')");
        $cursor=(int)$pdo->query("SELECT value FROM settings WHERE name='world_allocator' FOR UPDATE")->fetchColumn();
        $occupied=[];foreach($pdo->query('SELECT q,r FROM world_sites WHERE q BETWEEN 0 AND 63')->fetchAll() as $row){$occupied[$row['q'].','.$row['r']]=true;}
        // Registration may already have a repeatable-read snapshot. Recheck the chosen
        // cell with a current locking read after the allocator lock, never rely on it.
        $check=$pdo->prepare('SELECT id FROM world_sites WHERE q=? AND r=? FOR UPDATE');
        do{
            if(count($occupied)>=4096){[$q,$r]=Regions::overflowPosition();break;}
            [$q,$r,$cursor]=self::freePosition($occupied,$cursor);$check->execute([$q,$r]);$taken=$check->fetchColumn();if($taken!==false){$occupied[$q.','.$r]=true;}}while($taken!==false);
        $user=Game::user($userId);
        $insert=$pdo->prepare("INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES('home',?,?,?,?,?,1,NULL,1,?)");$insert->execute([$q,$r,$userId,$userId,$user['name'],time()]);
        $stmt=$pdo->prepare("UPDATE settings SET value=? WHERE name='world_allocator'");$stmt->execute([(string)$cursor]);return self::home($userId);
    }

    public static function load(int $siteId,bool $lock=false): array
    {
        $q=Db::pdo()->prepare('SELECT * FROM world_sites WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$siteId]);$row=$q->fetch();if(!$row){throw new RuntimeException('That map site does not exist.');}return self::decode($row);
    }
    public static function home(int $userId): ?array
    {
        $q=Db::pdo()->prepare("SELECT * FROM world_sites WHERE kind='home' AND home_user_id=?");$q->execute([$userId]);$row=$q->fetch();return $row?self::decode($row):null;
    }
    public static function holdings(int $ownerId): array
    {
        $q=Db::pdo()->prepare("SELECT * FROM world_sites WHERE owner_id=? AND kind IN ('resource','stronghold') ORDER BY id");$q->execute([$ownerId]);$rows=array_map([self::class,'decode'],$q->fetchAll());foreach($rows as &$row){self::refreshSite($row,time());}unset($row);return $rows;
    }
    public static function fortifications(int $ownerId): array
    {
        $q=Db::pdo()->prepare("SELECT * FROM world_sites WHERE owner_id=? AND kind='fortification' ORDER BY id");$q->execute([$ownerId]);$rows=array_map([self::class,'decode'],$q->fetchAll());foreach($rows as &$row){self::refreshSite($row,time());}unset($row);return $rows;
    }
    public static function save(array $site): void
    {
        if($site['kind']==='home'){throw new RuntimeException('Home map markers cannot be transferred or edited.');}
        if($site['owner_id']!==null&&Game::isBot((int)$site['owner_id'])){
            $clock=WorldActivity::clock();
            if(($clock['epoch']??0)>0){
                if((int)($site['state']['_activity_owner']??0)!==(int)$site['owner_id']){WorldActivity::stamp($site['state'],$clock);}
                $site['state']['_activity_owner']=(int)$site['owner_id'];
            }
        }else{unset($site['state']['_activity_clock'],$site['state']['_activity_owner']);}
        $q=Db::pdo()->prepare('UPDATE world_sites SET owner_id=?,level=?,state_json=?,revision=revision+1,updated_at=? WHERE id=?');
        $q->execute([$site['owner_id'],$site['level'],self::encode($site['state']),time(),$site['id']]);
    }
    private static function decode(array $row): array
    {
        foreach(['id','q','r','level','revision','updated_at'] as $key){$row[$key]=(int)$row[$key];}
        foreach(['owner_id','home_user_id'] as $key){$row[$key]=$row[$key]===null?null:(int)$row[$key];}
        $row['state']=$row['state_json']===null?null:json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);unset($row['state_json']);
        if($row['kind']!=='home'&&$row['owner_id']!==null&&is_array($row['state'])&&Game::isBot($row['owner_id'])){
            $clock=WorldActivity::clock();WorldActivity::shiftSite($row['state'],$clock);
            if(($clock['epoch']??0)>0){$row['state']['_activity_owner']=$row['owner_id'];}
        }
        return $row;
    }

    /** Pure completion step; income schedules are handled by the home base clock. */
    public static function refreshSite(array &$site,int $now): void
    {
        if($site['kind']==='home'){return;}
        if(OutpostYards::is($site)){if($site['owner_id']!==null)OutpostYards::accrue($site['state'],$now);return;}
        $state=&$site['state'];
        if((int)($state['ready_at']??0)>0&&(int)$state['ready_at']<=$now&&$state['upgrade_to']!==null){
            $site['level']=(int)$state['upgrade_to'];$state['ready_at']=0;$state['upgrade_to']=null;
            foreach($state['buildings'] as &$building){$level=in_array($building['type'],['outpost','stronghold','outpostdefender'],true)?$site['level']:min(3,$site['level']);$building['level']=$level;$building['max_hp']=$building['type']==='outpost'?1:Game::buildingStats($building['type'],$level)['max_hp'];$building['hp']=$building['max_hp'];}unset($building);
        }
        if((int)($state['repair_at']??0)>0&&(int)$state['repair_at']<=$now){foreach($state['buildings'] as &$building){$building['hp']=$building['max_hp'];if(Catalog::isTrap($building['type'])){$building['spent']=false;}}unset($building);$state['repair_at']=0;}
        unset($state);
    }

    public static function syncBonuses(int $ownerId,array &$homeBase): void
    {
        $rate=0.0;$capacity=0;$events=[];$sites=self::holdings($ownerId);$now=time();
        foreach($sites as $site){
            if(OutpostYards::is($site))continue;
            $level=$site['level'];$ready=(int)($site['state']['ready_at']??0);$next=$site['state']['upgrade_to']??null;
            if($next!==null&&$ready>0&&$ready<=$now){$level=(int)$next;}
            $stats=self::siteStats($site['kind'],$level);$rate+=$stats['resourcePerSecond'];$capacity+=$stats['capacity'];
            if($next!==null&&$ready>$now){$future=self::siteStats($site['kind'],(int)$next);$events[]=['ready_at'=>$ready,'rateDelta'=>$future['resourcePerSecond']-$stats['resourcePerSecond'],'capacityDelta'=>$future['capacity']-$stats['capacity']];}
        }
        usort($events,fn($a,$b)=>$a['ready_at']<=>$b['ready_at']);$pending=[];$futureRate=$rate;$futureCapacity=$capacity;
        foreach($events as $event){$futureRate+=$event['rateDelta'];$futureCapacity+=$event['capacityDelta'];$pending[]=['ready_at'=>$event['ready_at'],'resourcePerSecond'=>$futureRate,'capacity'=>$futureCapacity];}
        $homeBase['world']=array_replace($homeBase['world']??[],['resourcePerSecond'=>$rate,'capacity'=>$capacity,'holdings'=>count($sites),'pending'=>$pending]);
    }

    /** Pure, bounded passive income with exact scheduled upgrade boundaries. */
    public static function accrueIncome(array &$base,int $start,int $end,float $speed): void
    {
        $world=&$base['world'];$pending=$world['pending']??[];usort($pending,fn($a,$b)=>(int)$a['ready_at']<=>(int)$b['ready_at']);$cursor=$start;$remaining=[];
        $credit=static function(int $seconds) use(&$base,&$world,$speed):void{
            $income=max(0,$seconds)*max(0,(float)$world['resourcePerSecond'])*$speed;$cap=Game::capacity($base);
            foreach(['twig','pebble','putty','goo'] as $resource){$base['resources'][$resource]+=min($income,max(0,$cap-$base['resources'][$resource]));}
        };
        foreach($pending as $event){$at=(int)$event['ready_at'];if($at>$end){$remaining[]=$event;continue;}if($at>$cursor){$credit($at-$cursor);$cursor=$at;}$world['resourcePerSecond']=max(0,(float)$event['resourcePerSecond']);$world['capacity']=max(0,(int)$event['capacity']);}
        $credit(max(0,$end-$cursor));$world['pending']=$remaining;unset($world);
    }

    private static function rangeSources(int $owner,array $home,array $holdings):array
    {
        $bonus=AlliancePowers::rangeBonus($owner);
        $sources=[['q'=>$home['q'],'r'=>$home['r'],'range'=>10+$bonus]];
        foreach($holdings as $site)$sources[]=['q'=>$site['q'],'r'=>$site['r'],'range'=>self::siteStats($site['kind'],$site['level'])['range']+$bonus];
        return $sources;
    }
    public static function reachable(int $ownerId,array $target): bool
    {
        $home=self::home($ownerId);if(!$home)return false;
        foreach(self::rangeSources($ownerId,$home,self::holdings($ownerId)) as $source){
            if(self::distance($source['q'],$source['r'],$target['q'],$target['r'])<=$source['range'])return true;
        }
        return false;
    }

    public static function assertAttack(int $attackerId,array $attackerBase,array $site,?array $fieldParty=null): void
    {
        Truces::guard($attackerBase, (int)($site['owner_id'] ?? 0), time());
        if($site['kind']==='home'){throw new RuntimeException('Use the ordinary invasion action for home backyards.');}
        if($site['owner_id']===$attackerId){throw new RuntimeException('You already own this territory.');}
        $level=Game::livingLevel($attackerBase,'maproom');if($level<1){throw new RuntimeException('Build or repair a Map Room before invading territories.');}
        if(OutpostYards::is($site)){
            if($level<2)throw new RuntimeException('Upgrade your Map Room to level 2 before invading regional outposts.');
            if(!empty($site['state']['mr2Outpost']['claim']))throw new RuntimeException('The outpost was breached. Open its takeover offer.');
        }
        if(Game::livingLevel($fieldParty??$attackerBase,'flinger')<1){throw new RuntimeException('Build or repair a Flinger before invading territories.');}
        if($site['kind']==='fortification'){
            if(count(self::fortifications($attackerId))>=$level*6){throw new RuntimeException('Your Map Room Fortification limit is full. Upgrade it or abandon a Fortification site before invading.');}
        }elseif(count(self::holdings($attackerId))>=$level*2){throw new RuntimeException('Your Map Room holding limit is full. Upgrade it or abandon a territory before invading.');}
        if(!self::reachable($attackerId,$site)){throw new RuntimeException('This territory is outside the reach of your home and holdings.');}
        if((int)($site['state']['shield_until']??0)>time()){throw new RuntimeException('This territory is protected while its defenses rebuild.');}
        if((int)($site['state']['repair_at']??0)>time()||(int)($site['state']['ready_at']??0)>time()){throw new RuntimeException('This territory is still upgrading or rebuilding.');}
        $q=Db::pdo()->prepare("SELECT id FROM battles WHERE status='active' AND target_kind<>'home' AND target_id=? LIMIT 1");$q->execute([$site['id']]);if($q->fetchColumn()){throw new RuntimeException('This territory is already under attack.');}
        if(array_sum(($fieldParty??$attackerBase)['army'])<1&&Game::readyChampion($attackerBase)===null&&KrallenHunt::ready($attackerBase,time())===null){throw new RuntimeException('Hatch monsters or raise a healthy champion before invading.');}
    }

    /** Caller accrued both real owners using their old incomes, and holds their base/site locks. */
    public static function capture(array &$site,int $attackerId,array &$bases,int $now): void
    {
        $old=$site['owner_id'];$site['owner_id']=$attackerId;$site['state']['garrison']=[];$site['state']['shield_until']=$now+300;
        $site['state']['ready_at']=0;$site['state']['upgrade_to']=null;$site['state']['repair_at']=$now+max(1,(int)ceil(300/Game::speed()));
        self::save($site);if($old!==null&&isset($bases[$old])){self::syncBonuses($old,$bases[$old]);}self::syncBonuses($attackerId,$bases[$attackerId]);
    }

    public static function combatBonuses(int $attackerId,?int $defenderId,int $q,int $r): array
    {
        $result=['attacker'=>0.0,'defender'=>0.0];
        foreach(['attacker'=>$attackerId,'defender'=>$defenderId] as $side=>$owner){if(!$owner){continue;}foreach(self::holdings($owner) as $site){if($site['kind']!=='stronghold'){continue;}self::refreshSite($site,time());$stats=self::siteStats('stronghold',$site['level']);if(self::distance($q,$r,$site['q'],$site['r'])<=$stats['range']){$result[$side]+=$stats['damageBonus'];}}}
        return $result;
    }

    public static function map(int $viewerId,array $input=[]): array
    {
        $base=self::touchOwner($viewerId);$home=self::home($viewerId);if(!$home){throw new RuntimeException('Your home is missing from the map. Run the world upgrade to initialize it.');}
        $homeRegion=Regions::id($home['q']);$region=isset($input['region'])?Regions::checked($input['region']):$homeRegion;
        if($region!==$homeRegion)Regions::open($region);
        $origin=($region-1)*128;
        $q=$origin+(array_key_exists('q',$input)?self::integer($input['q'],0,63,'map column'):($region===$homeRegion?Regions::local($home['q']):32));
        $r=array_key_exists('r',$input)?self::integer($input['r'],0,63,'map row'):($region===$homeRegion?$home['r']:32);
        $radius=array_key_exists('radius',$input)?max(1,min(12,self::integer($input['radius'],-100000,100000,'map radius'))):8;
        $holdings=self::holdings($viewerId);$fortifications=self::fortifications($viewerId);$sources=self::rangeSources($viewerId,$home,$holdings);
        $network=self::networkIndex($q,$r,$radius+1,$fortifications);
        $owners=self::owners();$busy=self::busy();
        $stmt=Db::pdo()->prepare('SELECT * FROM world_sites WHERE q BETWEEN ? AND ? AND r BETWEEN ? AND ? ORDER BY r,q LIMIT 625');
        $stmt->execute([max(Regions::left($q),$q-$radius),min(Regions::right($q),$q+$radius),max(0,$r-$radius),min(63,$r+$radius)]);$sites=[];
        foreach($stmt->fetchAll() as $raw){$site=self::decode($raw);if(self::distance($q,$r,$site['q'],$site['r'])<=$radius){self::refreshSite($site,time());$sites[]=self::summary($site,$viewerId,$sources,$owners,$busy,$network);}}
        $homeIds=array_values(array_filter(array_column($sites,'homeUserId'),fn($id)=>$id!==null));
        if($homeIds){
            $query=Db::pdo()->prepare("SELECT user_id,COALESCE(JSON_EXTRACT(state_json,'$.shield_until'),0) AS shield_until,JSON_EXTRACT(state_json,'$._activity_clock') AS activity_clock FROM bases WHERE user_id IN (".implode(',',array_fill(0,count($homeIds),'?')).')');$query->execute($homeIds);$shields=[];$clock=WorldActivity::clock();
            foreach($query->fetchAll() as $row){
                $ownerId=(int)$row['user_id'];$projected=['shield_until'=>(int)$row['shield_until']];
                if($owners[$ownerId]['is_bot']??false){
                    $projected['_activity_clock']=json_decode((string)($row['activity_clock']??'null'),true,512,JSON_THROW_ON_ERROR);
                    WorldActivity::shiftBase($projected,$clock);
                }
                $shields[$ownerId]=(int)$projected['shield_until'];
            }
            foreach($sites as &$summary){if($summary['kind']==='home'){$summary['shield_until']=$shields[$summary['homeUserId']]??0;}}unset($summary);
        }
        $level=Game::livingLevel($base,'maproom');$owner=$owners[$viewerId];
        $totals=['resource'=>0,'stronghold'=>0,'fortification'=>0];
        $counts=Db::pdo()->prepare("SELECT kind,COUNT(*) AS n FROM world_sites WHERE q BETWEEN ? AND ? AND kind<>'home' GROUP BY kind");
        $counts->execute([$origin,$origin+63]);foreach($counts as $item)$totals[$item['kind']]=(int)$item['n'];
        return ['region'=>$region,'regionName'=>Regions::name($region),'width'=>64,'height'=>64,'layout'=>'odd-r','center'=>['q'=>Regions::local($q),'r'=>$r],
            'home'=>['id'=>$home['id'],'region'=>$homeRegion,'q'=>Regions::local($home['q']),'r'=>$home['r'],'range'=>$sources[0]['range'],'name'=>$owner['name'],'is_bot'=>$owner['is_bot'],'networkDefense'=>self::supportFromIndex($viewerId,$home['q'],$home['r'],'home',$network)],
            'mapRoomLevel'=>$level,'holdingLimit'=>$level*2,'holdings'=>array_map(fn($s)=>self::summary($s,$viewerId,$sources,$owners,$busy,$network),$holdings),
            'fortificationLimit'=>$level*6,'fortifications'=>array_map(fn($s)=>self::summary($s,$viewerId,$sources,$owners,$busy,$network),$fortifications),
            'bonuses'=>['resourcePerSecond'=>(float)$base['world']['resourcePerSecond'],'capacity'=>(int)$base['world']['capacity']],
            'sites'=>$sites,'totals'=>$totals,'serverTime'=>time()];
    }

    public static function site(int $viewerId,int $siteId): array
    {
        $homeBase=self::touchOwner($viewerId);$site=self::touchSite($siteId);$owners=self::owners();$busy=self::busy();
        $summary=self::summary($site,$viewerId,null,$owners,$busy);
        $limits=['fortificationLimit'=>Game::livingLevel($homeBase,'maproom')*6,'fortificationCount'=>count(self::fortifications($viewerId))];
        if($site['kind']==='home'){
            $base=Game::visit((int)$site['home_user_id'],$viewerId);
            $summary['shield_until']=(int)$base['shield_until'];$summary['level']=Game::townhallLevel($base);
            $reason=$site['owner_id']===$viewerId?'This is your home backyard.':($summary['busy']||isset($busy['users'][$viewerId])?'One of these keepers is in a battle.':((int)$base['shield_until']>time()?'This backyard is protected.':''));
            if ($reason === '' && ($base['truceUntil'] ?? 0) > time()) { $reason = 'A truce protects both keepers.'; }
            if($reason===''&&Game::livingLevel($homeBase,'flinger')<1){$reason='Build or repair a Flinger before invading.';}
            if($reason===''&&array_sum($homeBase['army'])<1&&Game::readyChampion($homeBase)===null){$reason='Hatch monsters or raise a healthy champion before invading.';}
            if($reason===''&&!array_filter($base['buildings'],fn($building)=>!in_array($building['type'],['wall','stonewall','trap','heavytrap'],true)&&$building['hp']>0)){$reason='This backyard is rebuilding after an invasion.';}
            return $limits+['site'=>$summary,'base'=>$base,'canAttack'=>$reason==='','attackReason'=>$reason,
                'captureThreshold'=>null,'management'=>null,'serverTime'=>time()];
        }
        $reason='';
        try{self::assertAttack($viewerId,$homeBase,$site);if(isset($busy['users'][$viewerId])||$summary['busy']){throw new RuntimeException('One of these keepers is already in a battle.');}}
        catch(RuntimeException $error){$reason=$error->getMessage();}
        return $limits+['site'=>$summary,'base'=>self::publicBase($site,$viewerId,$owners,$summary['networkDefense']),'canAttack'=>$reason==='','attackReason'=>$reason,'captureThreshold'=>90,
            'outpostYard'=>OutpostYards::is($site)?OutpostYards::board($site,$homeBase,$viewerId,$summary['busy']||isset($busy['users'][$viewerId])):null,
            'management'=>!OutpostYards::is($site)&&$site['owner_id']===$viewerId?self::management($site,$homeBase,isset($busy['users'][$viewerId])):null,'serverTime'=>time()];
    }

    public static function candidates(int $ownerId,int $limit=24,int $seed=0,?string $kind=null): array
    {
        if($kind!==null&&!in_array($kind,['resource','stronghold','fortification'],true)){throw new RuntimeException('Choose a known territory kind.');}
        $limit=max(1,min(48,$limit));$home=self::home($ownerId);if(!$home){return [];}$holdings=self::holdings($ownerId);
        $sources=self::rangeSources($ownerId,$home,$holdings);
        // One seeded reach source per turn keeps scheduler work bounded and lets a
        // persistent bot explore all of its territory over successive turns.
        $source=$sources[abs($seed%count($sources))];$range=$source['range'];$busy=self::busy();
        $filter=$kind===null?"s.kind IN ('resource','stronghold')":"s.kind=?";$parameters=[max(Regions::left($source['q']),$source['q']-$range),min(Regions::right($source['q']),$source['q']+$range),max(0,$source['r']-$range),min(63,$source['r']+$range)];if($kind!==null){$parameters[]=$kind;}$parameters[]=$ownerId;
        $order='s.q,s.r';
        if($kind==='fortification'){
            // Dense long-range windows can contain more than 160 cells. Keep
            // this owned source's actual support ring, then rotate coordinate
            // ordering before LIMIT so every other column can be scouted later.
            $adjacent=[];foreach(self::neighbors($source['q'],$source['r']) as $neighbor){$adjacent[]='(s.q=? AND s.r=?)';$parameters[]=$neighbor['q'];$parameters[]=$neighbor['r'];}
            $order='CASE WHEN '.implode(' OR ',$adjacent).' THEN 0 ELSE 1 END,MOD(s.q+?,64),MOD(s.r+?,64),s.id';
            $parameters[]=abs($seed%64);$parameters[]=abs(intdiv($seed,64)%64);
        }
        $stmt=Db::pdo()->prepare("SELECT s.*,u.name AS owner_name,u.is_bot AS owner_bot FROM world_sites s LEFT JOIN users u ON u.id=s.owner_id WHERE s.q BETWEEN ? AND ? AND s.r BETWEEN ? AND ? AND $filter AND (s.owner_id IS NULL OR s.owner_id<>?) ORDER BY $order LIMIT 160");
        $stmt->execute($parameters);$result=[];$network=self::networkIndex($source['q'],$source['r'],$range+1);$parents=[];
        if($kind==='fortification'){
            $query=Db::pdo()->prepare("SELECT id,kind,q,r,owner_id FROM world_sites WHERE kind IN ('home','resource','stronghold') AND q BETWEEN ? AND ? AND r BETWEEN ? AND ?");
            $query->execute([max(Regions::left($source['q']),$source['q']-$range-1),min(Regions::right($source['q']),$source['q']+$range+1),max(0,$source['r']-$range-1),min(63,$source['r']+$range+1)]);
            foreach($query->fetchAll() as $parent){foreach(['id','q','r'] as $field){$parent[$field]=(int)$parent[$field];}$parent['owner_id']=$parent['owner_id']===null?null:(int)$parent['owner_id'];$parents[$parent['q'].','.$parent['r']]=$parent;}
        }
        foreach($stmt->fetchAll() as $row){$owners=$row['owner_id']===null?[]:[(int)$row['owner_id']=>['id'=>(int)$row['owner_id'],'name'=>$row['owner_name'],'is_bot'=>(bool)$row['owner_bot']]];unset($row['owner_name'],$row['owner_bot']);$site=self::decode($row);self::refreshSite($site,time());$summary=self::summary($site,$ownerId,[$source],$owners,$busy,$network);
            if(!$summary['reachable']||$summary['busy']||$summary['shield_until']>time()||$summary['ready_at']>time()){continue;}
            $site+=['owner'=>$summary['owner'],'busy'=>false,'reachable'=>true,'networkDefense'=>$summary['networkDefense']];
            if($kind==='fortification'){$site['adjacentSites']=[];foreach(self::neighbors($site['q'],$site['r']) as $neighbor){$parent=$parents[$neighbor['q'].','.$neighbor['r']]??null;if($parent!==null){$site['adjacentSites'][]=$parent;}}}
            $result[]=$site;
        }
        if($kind==='fortification'){
            $adjacent=[];$rest=[];foreach($result as $site){if(self::distance($source['q'],$source['r'],$site['q'],$site['r'])===1){$adjacent[]=$site;}else{$rest[]=$site;}}
            if($rest){$offset=abs($seed%count($rest));$rest=array_merge(array_slice($rest,$offset),array_slice($rest,0,$offset));}
            $result=array_merge($adjacent,$rest);
        }elseif($result){$offset=abs($seed)%count($result);$result=array_merge(array_slice($result,$offset),array_slice($result,0,$offset));}
        return array_slice($result,0,$limit);
    }

    public static function mutate(int $ownerId,string $action,array $input): array
    {
        if(!in_array($action,['worldUpgrade','worldRepair','worldGarrison','worldUngarrison','worldAbandon'],true)){throw new RuntimeException('Choose an available territory action.');}
        $siteId=self::integer($input['siteId']??null,1,PHP_INT_MAX,'territory');
        Db::transaction(static function() use($ownerId,$action,$input,$siteId):void{
            $base=Game::loadBase($ownerId,true);$now=time();Game::accrue($base,$now);$site=self::load($siteId,true);
            if($site['kind']==='home'||$site['owner_id']!==$ownerId){throw new RuntimeException('You can manage only territories you own.');}
            if(self::ownerBusy($ownerId)){throw new RuntimeException('Finish your active battle before managing territories.');}
            self::refreshSite($site,$now);$state=&$site['state'];$stats=self::siteStats($site['kind'],$site['level']);
            if(OutpostYards::is($site)){
                if($action!=='worldAbandon')throw new RuntimeException('Use the regional outpost workshop for this yard.');
                Quests::collectFieldProjects($base,$state);$site['owner_id']=null;$state=OutpostYards::initialState($now);$state['shield_until']=$now+300;
            }elseif($action==='worldAbandon'){
                $site['owner_id']=null;$state['garrison']=[];$state['shield_until']=$now+300;$state['ready_at']=0;$state['upgrade_to']=null;$state['repair_at']=$now+max(1,(int)ceil(300/Game::speed()));
            }else{
                if((int)$state['ready_at']>$now||(int)$state['repair_at']>$now){throw new RuntimeException('Wait for this territory to finish upgrading or rebuilding.');}
                if($action==='worldUpgrade'){
                    if($site['level']>=$stats['maxLevel']){throw new RuntimeException('This territory is already at its highest level.');}
                    if(self::damaged($state)){throw new RuntimeException('Repair every defense before upgrading this territory.');}
                    self::spend($base,$stats['upgradeCost']);$state['upgrade_to']=$site['level']+1;$state['ready_at']=$now+max(1,(int)ceil($stats['upgradeTime']/Game::speed()));
                }elseif($action==='worldRepair'){
                    if(!self::damaged($state)){throw new RuntimeException('This territory is already fully repaired.');}
                    self::spend($base,self::repairCost($site));$state['repair_at']=$now+max(1,(int)ceil($stats['repairTime']/Game::speed()));
                }else{
                    $type=$input['type']??null;if(!is_string($type)||!isset(Catalog::monsters()[$type])){throw new RuntimeException('Choose a known ordinary monster.');}
                    $quantity=self::integer($input['quantity']??null,1,200,'monster quantity');$size=Catalog::monsters()[$type]['size'];$bunker=null;
                    foreach($state['buildings'] as $building){if($building['type']==='bunker'&&$building['hp']>0&&$building['level']>0){$bunker=$building;break;}}
                    if(!$bunker){throw new RuntimeException('Repair this territory bunker before transferring monsters.');}
                    $key=(string)$bunker['id'];$stored=$state['garrison'][$key]??[];
                    if($action==='worldGarrison'){
                        if(($base['army'][$type]??0)<$quantity){throw new RuntimeException('You do not have enough ready monsters of this species.');}
                        if(Game::bunkerUsed($state,(int)$bunker['id'])+$quantity*$size>Game::bunkerCapacity($state,(int)$bunker['id'])){throw new RuntimeException('This territory bunker does not have enough space.');}
                        $base['army'][$type]-=$quantity;$stored[$type]=(int)($stored[$type]??0)+$quantity;
                    }else{
                        if(($stored[$type]??0)<$quantity){throw new RuntimeException('This territory does not have that many stationed monsters.');}
                        if(Game::housingUsed($base)+$quantity*$size>Game::housingCapacity($base)){throw new RuntimeException('Your home housing is full. Make room before returning these monsters.');}
                        $stored[$type]-=$quantity;$base['army'][$type]+=$quantity;
                    }
                    $state['garrison'][$key]=array_filter($stored,fn($n)=>$n>0);
                }
            }
            unset($state);self::save($site);self::syncBonuses($ownerId,$base);Game::saveBase($ownerId,$base);
        });
        return self::site($ownerId,$siteId);
    }

    private static function touchOwner(int $ownerId): array
    {
        return Db::transaction(static function() use($ownerId):array{
            $base=Game::loadBase($ownerId,true);Game::accrue($base,time());Game::saveBase($ownerId,$base);return $base;
        });
    }
    private static function touchSite(int $siteId): array
    {
        $candidate=self::load($siteId);if($candidate['kind']==='home'){return $candidate;}
        return Db::transaction(static function() use($candidate,$siteId):array{
            $owner=$candidate['owner_id'];$base=$owner!==null?Game::loadBase($owner,true):null;$site=self::load($siteId,true);
            if($site['owner_id']!==$owner){throw new RuntimeException('This territory changed hands. Select it again to refresh.');}
            if($base!==null){Game::accrue($base,time());}
            $before=$site;self::refreshSite($site,time());
            if($base!==null && OutpostYards::is($site)){Quests::collectFieldProjects($base,$site['state']);}
            if($site!==$before){self::save($site);if($base!==null&&!OutpostYards::is($site)){self::syncBonuses($owner,$base);}}
            if($base!==null){Game::saveBase($owner,$base);}return $site;
        });
    }
    private static function summary(array $site,int $viewerId,?array $sources=null,?array $owners=null,?array $busy=null,?array $network=null): array
    {
        $owners??=self::owners();$busy??=self::busy();$reachable=$sources===null?self::reachable($viewerId,$site):false;
        foreach($sources??[] as $source){if(self::distance($source['q'],$source['r'],$site['q'],$site['r'])<=$source['range']){$reachable=true;break;}}
        $row=['id'=>$site['id'],'kind'=>$site['kind'],'region'=>Regions::id($site['q']),'q'=>Regions::local($site['q']),'r'=>$site['r'],'name'=>$site['kind']==='home'?($owners[$site['owner_id']]['name']??$site['name']):$site['name'],
            'level'=>$site['level'],'owner'=>$site['owner_id']===null?null:($owners[$site['owner_id']]??null),'homeUserId'=>$site['home_user_id'],
            'owned'=>$site['owner_id']===$viewerId,'reachable'=>$reachable,'shield_until'=>(int)($site['state']['shield_until']??0),
            'busy'=>isset($busy['users'][$site['owner_id']??0])||isset($busy['sites'][$site['id']]),
            'ready_at'=>max((int)($site['state']['ready_at']??0),(int)($site['state']['repair_at']??0)),
            'networkDefense'=>$network===null?self::fortificationSupport($site['owner_id'],$site['q'],$site['r']):self::supportFromIndex($site['owner_id'],$site['q'],$site['r'],$site['kind'],$network)];
        if(OutpostYards::is($site))$row+=['mr2Outpost'=>true,'resourcePerSecond'=>0,'capacity'=>0];
        if($site['kind']!=='home'){$row+=array_intersect_key(self::siteStats($site['kind'],$site['level']),array_flip(['range','resourcePerSecond','capacity','damageBonus']));}
        return $row;
    }
    private static function publicBase(array $site,int $viewerId,array $owners,array $networkDefense): array
    {
        $state=$site['state'];$owner=$site['owner_id']===null?['id'=>0,'name'=>$site['name'],'is_bot'=>false,'rank_points'=>0,'neutral'=>true]:($owners[$site['owner_id']]+['rank_points'=>0]);
        $buildings=YardUtilities::hatcheryWork($state,time());
        if($site['owner_id']!==$viewerId){$buildings=array_values(array_filter($buildings,[Catalog::class,'visibleToRivals']));}
        return (OutpostYards::is($site)&&$site['owner_id']===$viewerId?['garrison'=>$state['garrison'],'army'=>$state['army'],'housingCapacity'=>Game::housingCapacity($state),'housingUsed'=>Game::housingUsed($state)]:[])+['owner'=>$owner,'buildings'=>$buildings,'resources'=>array_fill_keys(['twig','pebble','putty','goo'],0),'capacity'=>0,
            'housingCapacity'=>0,'housingUsed'=>0,'shield_until'=>(int)$state['shield_until'],'updated_at'=>$site['updated_at'],'army'=>[],
            'research'=>[],'unlocked'=>[],'unlockQueue'=>[],'garrison'=>[],'champions'=>[],'activeChampion'=>null,'lab'=>['levels'=>[],'queue'=>null],'networkDefense'=>$networkDefense,'content_version'=>Catalog::all()['version']];
    }
    private static function management(array $site,array $base,bool $busy): array
    {
        $stats=self::siteStats($site['kind'],$site['level']);$state=$site['state'];$working=max((int)$state['ready_at'],(int)$state['repair_at'])>time();$damaged=self::damaged($state);
        $upgradeReason=$busy?'Finish your active battle first.':($working?'Work is already in progress.':($site['level']>=$stats['maxLevel']?'Maximum level reached.':($damaged?'Repair the defenses first.':(!self::affordable($base,$stats['upgradeCost'])?'Not enough resources.':''))));
        $repairCost=self::repairCost($site);$repairReason=$busy?'Finish your active battle first.':($working?'Work is already in progress.':(!$damaged?'Defenses are fully repaired.':(!self::affordable($base,$repairCost)?'Not enough resources.':'')));
        $garrison=[];$capacity=0;$used=0;$live=false;
        foreach($state['buildings'] as $building){if($building['type']!=='bunker'){continue;}$capacity+=Game::bunkerCapacity($state,$building['id']);$used+=Game::bunkerUsed($state,$building['id']);$live=$live||$building['hp']>0;foreach($state['garrison'][(string)$building['id']]??[] as $type=>$count){$garrison[$type]=($garrison[$type]??0)+$count;}}
        return ['upgrade'=>['cost'=>$stats['upgradeCost'],'duration'=>(int)ceil($stats['upgradeTime']/Game::speed()),'maxLevel'=>$stats['maxLevel'],'available'=>$upgradeReason==='','reason'=>$upgradeReason,
                'benefits'=>$site['level']<$stats['maxLevel']?array_intersect_key(self::siteStats($site['kind'],$site['level']+1),array_flip(['resourcePerSecond','capacity','range','damageBonus','garrisonCapacity'])):null],
            'repair'=>['cost'=>$repairCost,'duration'=>(int)ceil($stats['repairTime']/Game::speed()),'available'=>$repairReason==='','reason'=>$repairReason],
            'garrison'=>$garrison,'garrisonCapacity'=>$capacity,'garrisonUsed'=>$used,'army'=>$base['army'],'ready_at'=>max((int)$state['ready_at'],(int)$state['repair_at']),
            'busy'=>$busy,'canGarrison'=>!$busy&&!$working&&$live,'canUngarrison'=>!$busy&&!$working&&$live,
            'reason'=>$busy?'Finish your active battle first.':($working?'Wait for work to finish.':(!$live?'Repair the bunker first.':'')),
            'benefits'=>array_intersect_key($stats,array_flip(['resourcePerSecond','capacity','range','damageBonus','garrisonCapacity']))];
    }
    /** One small projection for all visible support, plus owned cells for offscreen holdings. */
    private static function networkIndex(int $q,int $r,int $radius,array $owned=[]): array
    {
        $query=Db::pdo()->prepare("SELECT id,kind,owner_id,q,r FROM world_sites WHERE kind='fortification' AND q BETWEEN ? AND ? AND r BETWEEN ? AND ?");
        $query->execute([max(Regions::left($q),$q-$radius),min(Regions::right($q),$q+$radius),max(0,$r-$radius),min(63,$r+$radius)]);$index=[];
        foreach($query->fetchAll() as $site){$index[$site['q'].','.$site['r']]=$site;}
        foreach($owned as $site){$index[$site['q'].','.$site['r']]=array_intersect_key($site,array_flip(['id','kind','owner_id','q','r']));}
        return $index;
    }
    private static function owners(): array
    {
        $result=[];foreach(Db::pdo()->query('SELECT id,name,is_bot FROM users')->fetchAll() as $row){$result[(int)$row['id']]=['id'=>(int)$row['id'],'name'=>$row['name'],'is_bot'=>(bool)$row['is_bot']];}return $result;
    }
    private static function busy(): array
    {
        $result=['users'=>[],'sites'=>[]];foreach(Db::pdo()->query("SELECT attacker_id,defender_id,target_kind,target_id FROM battles WHERE status='active'")->fetchAll() as $row){$result['users'][(int)$row['attacker_id']]=true;if($row['defender_id']!==null){$result['users'][(int)$row['defender_id']]=true;}if($row['target_kind']!=='home'){$result['sites'][(int)$row['target_id']]=true;}}return $result;
    }
    private static function damaged(array $state): bool {foreach($state['buildings'] as $building){if($building['hp']<$building['max_hp']){return true;}}return false;}
    public static function repairCost(array $site): array
    {
        $max=0.0;$hp=0.0;foreach($site['state']['buildings'] as $building){$max+=$building['max_hp'];$hp+=max(0,$building['hp']);}
        $fraction=$max>0?max(0,1-$hp/$max):0;return array_map(fn($cost)=>(int)ceil($cost*$fraction),self::siteStats($site['kind'],$site['level'])['repairCost']);
    }
    private static function affordable(array $base,array $cost): bool {foreach($cost as $resource=>$amount){if(($base['resources'][$resource]??0)<$amount){return false;}}return true;}
    private static function spend(array &$base,array $cost): void {if(!self::affordable($base,$cost)){throw new RuntimeException('Not enough resources for this territory action.');}foreach($cost as $resource=>$amount){$base['resources'][$resource]-=$amount;}}
    private static function integer(mixed $value,int $min,int $max,string $label): int
    {
        if(!is_int($value)&&!is_string($value)){throw new RuntimeException('Choose a valid '.$label.'.');}$number=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);if($number===false){throw new RuntimeException('Choose a valid '.$label.'.');}return $number;
    }

    public static function ownerBusy(int $ownerId): bool
    {
        $q=Db::pdo()->prepare("SELECT id FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?) LIMIT 1");$q->execute([$ownerId,$ownerId]);return(bool)$q->fetchColumn();
    }
    private static function siteBusy(array $site): bool
    {
        if($site['owner_id']!==null&&self::ownerBusy($site['owner_id'])){return true;}
        $q=Db::pdo()->prepare("SELECT id FROM battles WHERE status='active' AND target_kind<>'home' AND target_id=? LIMIT 1");$q->execute([$site['id']]);return(bool)$q->fetchColumn();
    }
    private static function encode(array $data): string{return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
}
