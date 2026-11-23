<?php
declare(strict_types=1);
require_once __DIR__.'/YardSpace.php';

/** Source storeItems, CHAMPIONCAGE and MONSTERBUNKERPOPUP. Absent purchases have no effect. */
final class StorePowers
{
    private const ITEMS = [
        'BEW'=>['Extra worker',250,0,'Hire a permanent construction worker. All five workers can take independent jobs.','Workers'],
        'BST'=>['Sharper tools',225,604800,'Start building upgrades with a 20% head start.','Workers'],
        'POD'=>['Production Overdrive',200,43200,'Double surface harvester production for 12 hours.','Resources'],
        'BIP'=>['Improved packing skills',50,0,'Add 10% of base material storage permanently.','Resources'],
        'HOD'=>['Hatchery Overdrive I',30,3600,'Hatch at 4× speed for one hour.','Hatchery'],
        'HOD2'=>['Hatchery Overdrive II',50,3600,'Hatch at 6× speed for one hour.','Hatchery'],
        'HOD3'=>['Hatchery Overdrive III',100,3600,'Hatch at 10× speed for one hour.','Hatchery'],
        'CLOD'=>['Locker Overdrive',60,14400,'Discover new species at 4× speed for four hours.','Hatchery'],
        'EXH'=>['Housing Expansion',375,86400,'Add 25% reserve space for 24 hours. Existing troops may stay after expiry.','Hatchery'],
        'TOD'=>['Tower Overdrive',600,86400,'Towers deal 25% more damage for 24 hours.','Combat'],
        'MOD'=>['Monster Dust',500,84600,'Monsters deal 25% more damage for 23 hours and 30 minutes.','Combat'],
        'MDOD'=>['Beast Mode',500,86400,'Monsters take 30% less damage from hits that allow armor for 24 hours.','Combat'],
        'MSOD'=>['Hyper Speed',500,86400,'Monsters move and attack 50% faster for 24 hours.','Combat'],
        'PRO1'=>['Day protection',32,86400,'Protect your surface home for 24 hours. Attacking a rival breaks protection.','Protection'],
        'PRO2'=>['Holiday protection',250,604800,'Protect your surface home for seven days. Attacking a rival breaks protection.','Protection'],
        'PRO3'=>['Ultimate protection',1100,2419200,'Protect your surface home for 28 days. Attacking a rival breaks protection.','Protection'],
    ];
    private const NATIVE = ['HOD'=>'Inferno Incubator Overdrive I','HOD2'=>'Inferno Incubator Overdrive II','HOD3'=>'Inferno Incubator Overdrive III',
        'EXH'=>'Compound Expansion','TOD'=>'Inferno Tower Overdrive',
        'PRO1'=>'Cavern day protection','PRO2'=>'Cavern holiday protection','PRO3'=>'Cavern ultimate protection'];
    private const RATES = ['HOD'=>4,'HOD2'=>6,'HOD3'=>10];
    private const FOOD = ['gorgo'=>[26,44,75,111,136,136],'drull'=>[26,44,75,105,131,131],
        'fomor'=>[26,45,62,76,96,96],'korath'=>[26,44,75,111,136,96]];
    private const BUNKER = ['octo'=>2,'spurtz'=>3,'ichi'=>5,'eyera'=>16,'bandito'=>8,'fang'=>12,
        'crabatron'=>14,'projectx'=>24,'wormzer'=>24,'dave'=>65,'slimeattikus'=>17,'zagnoid'=>4,
        'balthazar'=>32,'sabnox'=>32,'kingwormzer'=>55];

    public static function expiry(array $yard,string $key):int
    {
        return (int)($yard['storeTimers'][$key]??0);
    }

    public static function materialCapacity(array $yard,int $capacity):int
    {
        return intdiv($capacity*(10+max(0,min(10,(int)($yard['packingLevel']??0)))),10);
    }

    public static function reserveCapacity(array $yard,int $capacity,int $now):int
    {
        return self::expiry($yard,'EXH')>$now?intdiv($capacity*5,4):$capacity;
    }

    public static function offers(array $base,int $now):array
    {
        $offers=[];
        foreach(self::ITEMS as $code=>[$name,$price,$seconds,$description,$group]){
            $reason='';
            if($code==='BIP'){
                $level=min(10,max(0,(int)($base['packingLevel']??0)));
                $price*=min(10,$level+1);
                $name.=' · '.($level===10?'complete':'level '.($level+1));
                if($level>=10)$reason='All ten packing upgrades are complete.';
            }
            if($code==='BEW'){
                $workers=Construction::normalizeWorkers($base['workers']??1);
                $price=[1=>250,2=>500,3=>1000,4=>2000,5=>2000][$workers];
                if($workers>=Construction::MAX_WORKERS)$reason='All five workers have been hired.';
                elseif(Game::livingLevel($base,'townhall')<$workers+1)$reason='A working Town Hall at level '.($workers+1).' is required.';
            }
            $offers[$code]=self::offer($base,$code,$name,$price,$seconds,$description,$group,$reason,$now);
            if(isset($base['infernoRealm'],self::NATIVE[$code])){
                $offers[$code.'I']=self::offer($base['infernoRealm'],$code,self::NATIVE[$code],$price,$seconds,str_replace(['surface home','a rival'],['cavern','a rival cavern'],$description),'Inferno','',$now);
            }
        }
        $offers['ENL']=self::yardage($base,false);
        if(isset($base['infernoRealm']))$offers['ENLI']=self::yardage($base['infernoRealm'],true);
        $offers+=self::wallOffers($base,$now,false);
        if(isset($base['infernoRealm']))$offers+=self::wallOffers($base['infernoRealm'],$now,true);
        $type=$base['activeChampion']??'';
        $champ=$base['champions'][$type]??null;
        if(isset(self::FOOD[$type]) && $champ && !$champ['frozen'] && !$champ['battle_id'] && Game::livingLevel($base,'championcage')>0){
            $level=(int)$champ['level'];$stats=Catalog::championStats($type,$level,(int)$champ['bonus_feeds']);
            $price=self::FOOD[$type][$level-1];$spec=Catalog::champions()[$type];$name=$spec['name'];
            $key=$type.'.'.$level.'.'.$champ['feeds'].'.'.$champ['bonus_feeds'];
            if($level<6 || $champ['bonus_feeds']<3 || $champ['next_feed_at']<=$now){
                $early=$level===6 && $champ['next_feed_at']>$now;
                $offers['IFD.'.$key]=self::care('Feed '.$name,$price*($early?2:1),'Provide one full meal immediately with Shiny.');
            }
            if($level<6){
                $remaining=$spec['feedsRequired'][$level]-$champ['feeds'];
                $offers['IEV.'.$key]=self::care('Evolve '.$name,2*$price*$remaining,'Advance to level '.($level+1).' and recover full health.');
            }
            if($champ['hp']<$stats['hp']){
                $remaining=(int)floor((1-$champ['hp']/$stats['hp'])*$stats['recoverySeconds']/Game::speed());
                $offers['IHE.'.$key]=self::care('Heal '.$name,YardUtilities::timePrice(max(1,$remaining),false),'Restore the active champion to full health.');
            }
        }
        return $offers;
    }

    private static function yardage(array $yard,bool $native):array
    {
        $tier=(int)($yard['yardageLevel']??0);$max=$native?5:6;$next=min($tier+1,$max);
        $current=YardSpace::extent($yard);$after=YardSpace::extent(['yardageLevel'=>$next]);
        return ['name'=>$native?'More Cavern Yardage':'More Yardage','group'=>$native?'Inferno':'Workers','price'=>50*$next,
            'description'=>"Expand your buildable yard from $current × $current to $after × $after tiles.",
            'seconds'=>0,'until'=>0,'reason'=>$tier>=$max?'All yard expansions are complete.':''];
    }

    /** Source STORE.Variables prices each material step on each eligible standing block. */
    private static function wallOffers(array $yard,int $now,bool $native):array
    {
        $tariffs=[2=>3,3=>6,4=>10,5=>15];
        $materials=[2=>'stone',3=>'metal',4=>'gold',5=>'black diamond'];$offers=[];
        foreach($materials as $tier=>$material){
            if($native && $tier>3)break;
            $targets=[];$price=0;
            foreach($yard['buildings'] as $b){
                if($b['type']!=='wall'||$b['level']<1||$b['level']>=$tier||$b['hp']<$b['max_hp']||
                    $b['ready_at']>$now||($b['fortify_ready_at']??0)>$now)continue;
                $targets[$b['id']]=$b['level'];
                for($step=$b['level']+1;$step<=$tier;++$step)$price+=$tariffs[$step];
            }
            if(!$targets)continue;
            $code='BLK'.$tier.($native?'I':'').'@'.substr(hash('sha256',json_encode($targets,JSON_THROW_ON_ERROR)),0,16);
            $offers[$code]=['name'=>($native?'Inferno b':'B').'lock upgrade · '.ucfirst($material),'group'=>$native?'Inferno':'Workers',
                'price'=>$price,'description'=>'Upgrade '.count($targets).' fully repaired, idle '.(count($targets)===1?'block':'blocks').' to level '.$tier.' immediately.',
                'seconds'=>0,'until'=>0,'reason'=>'','tier'=>$tier,'native'=>$native,'walls'=>array_keys($targets)];
        }
        return $offers;
    }

    private static function care(string $name,int $price,string $description):array
    {
        return ['name'=>$name,'price'=>$price,'description'=>$description,'group'=>'Champions','seconds'=>0,'until'=>0,'reason'=>''];
    }

    private static function offer(array $yard,string $code,string $name,int $price,int $seconds,string $description,string $group,string $reason,int $now):array
    {
        $until=self::expiry($yard,$code);
        if(isset(self::RATES[$code]))foreach(self::RATES as $grade=>$rate)$until=max($until,self::expiry($yard,$grade));
        if(str_starts_with($code,'PRO')){
            $until=(int)($yard['shield_until']??0);
            // Paid protection replaces a shorter existing shield; the receipt can never shorten protection.
            $currentPlan=$yard['shieldPlan']??'';
            $sameLease=$currentPlan!=='' && self::expiry($yard,$currentPlan)===$until;
            if($until>$now && ($until>=$now+$seconds || ($sameLease && (self::ITEMS[$currentPlan][2]??0)>=$seconds)))
                $reason='This yard already has this protection or a longer plan.';
        }elseif($until>$now)$reason='Already active.';
        return compact('name','price','description','group','seconds','until','reason');
    }

    public static function activate(array &$base,string $key,int $now):void
    {
        if(in_array($key,['ENL','ENLI'],true)){
            if($key==='ENLI'){$yard=&$base['infernoRealm'];++$yard['revision'];}else{$yard=&$base;}
            $yard['yardageLevel']=(int)($yard['yardageLevel']??0)+1;
            return;
        }
        if(str_starts_with($key,'BLK')){
            $native=str_contains($key,'I@');
            if($native){$yard=&$base['infernoRealm'];++$yard['revision'];}else{$yard=&$base;}
            $offer=self::wallOffers($yard,$now,$native)[$key];
            foreach($yard['buildings'] as &$b){
                if(!in_array($b['id'],$offer['walls'],true))continue;
                $b['level']=$offer['tier'];
                $b['max_hp']=Game::buildingStats('wall',$b['level'])['max_hp'];$b['hp']=$b['max_hp'];
            }unset($b);
            return;
        }
        if(str_contains($key,'.')){
            [$action,$type]=explode('.',$key);
            $champ=&$base['champions'][$type];$level=(int)$champ['level'];
            if($action==='IHE'){
                $champ['hp']=Catalog::championStats($type,$level,$champ['bonus_feeds'])['hp'];$champ['last_heal_at']=$now;
            }else{
                if($action==='IEV')$champ['feeds']=Catalog::champions()[$type]['feedsRequired'][$level]-1;
                Game::finishChampionMeal($base,$type,$now,Game::speed());
            }
            return;
        }
        $native=str_ends_with($key,'I')&&isset(self::NATIVE[substr($key,0,-1)]);
        $code=$native?substr($key,0,-1):$key;
        if($native){
            if(InfernoDefense::active($base['infernoRealm']))throw new RuntimeException('Resolve the Moloch counterattack before changing your cavern.');
            $yard=&$base['infernoRealm'];InfernoRealm::accrue($yard,$now);++$yard['monsterRevision'];++$yard['revision'];
        }else{$yard=&$base;}
        if($code==='BEW'){++$base['workers'];return;}
        if($code==='BIP'){
            $base['packingLevel']=min(10,(int)($base['packingLevel']??0)+1);
            if(isset($base['infernoRealm']))$base['infernoRealm']['packingLevel']=$base['packingLevel'];
            return;
        }
        $until=$now+self::ITEMS[$code][2];
        if(str_starts_with($code,'PRO')){
            $yard['shield_until']=max((int)($yard['shield_until']??0),$until);$yard['shieldPlan']=$code;$yard['storeTimers'][$code]=$yard['shield_until'];
            return;
        }
        $yard['storeTimers'][$code]=$until;
        if(isset(self::RATES[$code]))self::accelerateQueue($yard,$now,$native);
        if($code==='CLOD' && !empty($yard['unlockQueue'])){
            foreach($yard['unlockQueue'] as &$job){
                $job['ready_at']=$now+self::workTime($yard,$now,max(0,$job['ready_at']-$now),true);
            }unset($job);
        }
    }

    /** Convert unfinished work into elapsed seconds, applying only the part inside a purchased window. */
    public static function workTime(array $yard,int $start,int $work,bool $locker=false):int
    {
        foreach($locker?['CLOD'=>4]:self::RATES as $code=>$rate){
            $window=self::expiry($yard,$code)-$start;
            if($window<=0)continue;
            $fast=min($work,$window*$rate);
            return (int)ceil($fast/$rate)+$work-$fast;
        }
        return $work;
    }

    private static function accelerateQueue(array &$yard,int $now,bool $native):void
    {
        $lanes=[];$startKey=$native?'start':'started_at';
        foreach($yard['queue'] as &$job){
            $lane=(int)($job['lane']??0);$oldDue=(int)$job['ready_at'];
            if($oldDue<=$now)continue;
            $duration=max(1,(int)($job['duration']??($oldDue-$now)));
            $oldStart=(int)($job[$startKey]??($oldDue-$duration));
            $work=$oldStart<$now?$oldDue-$now:(int)($job['work']??$duration);
            $start=max($now,$lanes[$lane]??$now);
            $job['work']??=$duration;
            $job[$startKey]=min($oldStart,$start);
            $job['ready_at']=$start+self::workTime($yard,$start,$work);
            $job['duration']=$job['ready_at']-$job[$startKey];
            $lanes[$lane]=$job['ready_at'];
        }unset($job);
    }

    public static function bunkerOffers(array $yard,int $now):array
    {
        $offers=[];
        foreach($yard['buildings'] as $b){
            if($b['type']!=='bunker'||$b['hp']<=0||$b['level']<1||$b['ready_at']>$now)continue;
            $id=(int)$b['id'];$room=Game::bunkerCapacity($yard,$id)-Game::bunkerUsed($yard,$id);
            $troops=[];
            foreach(self::BUNKER as $type=>$price){
                $spec=Catalog::monsters()[$type]??null;
                if($spec && $room>=$spec['size'])$troops[$type]=['name'=>$spec['name'],'price'=>$price,'max'=>intdiv($room,$spec['size'])];
            }
            $offers[$id]=['room'=>$room,'troops'=>$troops];
        }
        return $offers;
    }

    public static function bunkerQuote(array $yard,string $key,int $quantity,int $now):?array
    {
        if($quantity<1 || $quantity>200 || !preg_match('/^(\d+)\.([a-z]+)$/D',$key,$m))return null;
        $offer=self::bunkerOffers($yard,$now)[(int)$m[1]]['troops'][$m[2]]??null;
        if(!$offer || $quantity>$offer['max'])return null;
        return ['price'=>$offer['price']*$quantity,'bunker'=>(int)$m[1],'type'=>$m[2],'quantity'=>$quantity];
    }

    public static function shift(array &$yard,int $delta):void
    {
        if(empty($yard['storeTimers']))return;
        foreach($yard['storeTimers'] as &$until)if($until>0)$until+=$delta;
        unset($until);
    }

    /** Battle snapshots store remaining lease seconds for both teams and use the combat clock thereafter. */
    public static function combat(array &$state,array $attack,array $defense,int $now):void
    {
        foreach(['attacker'=>$attack,'defender'=>$defense] as $team=>$yard){
            foreach(['TOD','MOD','MDOD','MSOD'] as $code){
                $remain=self::expiry($yard,$code)-$now;
                if($remain>0)$state['_storePowers'][$team][$code]=$remain;
            }
        }
    }

    public static function battleTick(array &$state):void
    {
        $elapsed=(float)$state['elapsed'];
        foreach($state['units'] as &$u){
            $buffs=$state['_storePowers'][$u['team']]??[];
            $damage=($buffs['MOD']??0)>$elapsed;$speed=($buffs['MSOD']??0)>$elapsed;
            $color=($damage?0xcccc00:0)|($speed?0xff0000:0)|(($buffs['MDOD']??0)>$elapsed?0x0000ff:0);
            if($color){$u['overdriveColor']=sprintf('%06x',$color);}else{unset($u['overdriveColor']);}
            if($damage!==($u['_storeDamage']??false)){$u['_damage']*=$damage?1.25:.8;$u['_storeDamage']=$damage;}
            if($speed!==($u['_storeSpeed']??false)){$u['_speed']*=$speed?1.5:1/1.5;$u['_interval']*=$speed?1/1.5:1.5;$u['_storeSpeed']=$speed;}
        }unset($u);
        $active=($state['_storePowers']['defender']['TOD']??0)>$elapsed;
        foreach($state['buildings'] as &$b){
            if(($b['_damage']??0)<=0 || Catalog::isTrap($b['type']))continue;
            if($active!==($b['_storeTower']??false)){
                $b['_damage']*=$active?1.25:.8;$b['_storeTower']=$active;
                if($active){$b['towerOverdrive']=true;}else{unset($b['towerOverdrive']);}
            }
        }unset($b);
    }
}
