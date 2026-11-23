<?php
declare(strict_types=1);

/** Map Room 2 uses a separately built field yard with a shared home treasury. */
final class OutpostYards
{
    private const MATERIALS=['twig','pebble','putty','goo'];

    public static function is(array $site): bool
    {
        return $site['kind']==='resource' && isset($site['state']['mr2Outpost']);
    }

    public static function initialState(int $now): array
    {
        $state=World::initialState('resource',1,$now);
        $state['buildings'][0]=self::building('townhall',1,16,16,1);
        $state['mr2Outpost']=['revision'=>1,'claim'=>null];
        return $state;
    }

    private static function building(string $type,int $id,float $x,float $y,int $level): array
    {
        $hp=Game::buildingStats($type,max(1,$level))['max_hp'];
        return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];
    }

    private static function catalog():array
    {
        static $rows;
        return $rows??=json_decode(file_get_contents(__DIR__.'/outpost-facilities.json'),true,512,JSON_THROW_ON_ERROR);
    }

    public static function price(string $type,int $level): array
    {
        $row=self::catalog()[$type]['tiers'][$level-1]??null;
        if(!$row || $level>(Catalog::buildings()[$type]['maxLevel']??0))throw new RuntimeException('This facility has reached its final outpost level.');
        return self::scale($row);
    }

    private static function scale(array $row):array
    {
        return ['cost'=>array_combine(self::MATERIALS,array_map(static fn($n)=>(int)ceil($n/100),array_slice($row,0,4))),
            'seconds'=>max(1,(int)ceil($row[4]/(60*Game::speed())))];
    }

    private static function requirement(array $yard,array $home,array $rules):string
    {
        foreach($rules as [$type,$count,$level]){
            $structures=$type==='townhall'?$home['buildings']:$yard['buildings'];
            $ready=count(array_filter($structures,fn($b)=>$b['type']===$type&&$b['level']>=$level&&$b['ready_at']<=time()&&$b['hp']>0));
            if($ready<$count)return $count.' working '.(Catalog::buildings()[$type]['name']??$type).' at level '.$level.' required.';
        }
        return '';
    }

    private static function available(array $yard,array $home,string $type,int $level):string
    {
        if($type==='magmatower'&&!MolochSiege::outpostMagma($home))return 'Complete all ten Hell-raisers waves to unlock field Magma Towers.';
        return self::requirement($yard,$home,self::catalog()[$type]['requires'][$level-1]??[]);
    }

    private static function workerBusy(array $b):bool
    {
        return max((int)$b['ready_at'],(int)($b['fortify_ready_at']??0))>time();
    }

    private static function pay(array &$base,array $cost): void
    {
        foreach($cost as $k=>$n)if(($base['resources'][$k]??0)<$n)throw new RuntimeException('Your home treasury needs more '.ucfirst($k).'.');
        foreach($cost as $k=>$n)$base['resources'][$k]-=$n;
    }

    public static function accrue(array &$state,int $now): void
    {
        $last=(int)$state['last_tick'];$elapsed=$now-$last;
        if($elapsed<=0)return;
        if(!$state['queue'] && !array_filter($state['buildings'],static function($b){
            $spec=Catalog::buildings()[$b['type']];
            return $b['ready_at'] || ($b['fortify_ready_at']??0) || (isset($spec['production'])&&$b['hp']>0&&$b['stored']<$spec['capacity']*$b['level']);
        }))return;
        foreach($state['buildings'] as &$b){
            if(($b['fortify_ready_at']??0)>0&&$b['fortify_ready_at']<=$now){
                $b['fortification']=$b['fortify_to'];$b['fortify_to']=null;$b['fortify_ready_at']=0;
            }
            $before=(int)$b['level'];$ready=(int)$b['ready_at'];$spec=Catalog::buildings()[$b['type']];$health=$b['hp']>0;
            if($ready && $ready<=$now){
                $b['level']=(int)$b['upgrade_to'];$b['max_hp']=Game::buildingStats($b['type'],$b['level'])['max_hp'];
                $b['hp']=$b['max_hp'];$b['ready_at']=0;$b['upgrade_to']=null;
                if (!empty($b['journalProject']) && $before===0) { Quests::fieldProject($state,$b); }
            }
            if(isset($spec['production']) && $b['level']>0){
                $start=max($last,$now-259200);$old=$ready>0?max(0,min($now,$ready)-$start):$now-$start;
                $new=$ready>0?max(0,$now-max($ready,$start)):0;
                $gain=($old*$before*(int)$health+$new*$b['level'])*$spec['production']*Game::speed();
                $b['stored']=min($spec['capacity']*$b['level'],$b['stored']+$gain);
            }
        }unset($b);
        foreach($state['queue']??[] as $i=>$job){
            if($job['ready_at']>$now)continue;
            $t=$job['type'];$state['army'][$t]=(int)($state['army'][$t]??0)+$job['quantity'];unset($state['queue'][$i]);
        }
        $state['queue']=array_values($state['queue']??[]);$state['last_tick']=$now;
    }

    private static function quote(array $site,int $id): array
    {
        $home=World::home($id);$wild=$site['owner_id']===null;
        $value=MonsterMadness::value(1);
        if(!$wild){
            $value=0;
            foreach($site['state']['buildings'] as $b){
                foreach(array_slice(self::catalog()[$b['type']]['tiers']??[],0,$b['level']) as $r)$value+=array_sum(array_slice($r,0,4));
            }
            $value=max(1,$value);
        }
        $fee=$wild?1000000:(int)min(65000000,max(1000000,round((log($value)*15820570.7-227080916.9)/250000)*250000));
        if(World::distance($home['q'],$home['r'],$site['q'],$site['r'])===1)$fee=(int)ceil($fee/2);
        $fee=AlliancePowers::cost($fee,isset(AlliancePowers::active(Db::pdo(),$id,time())['conquest']));
        return ['each'=>(int)ceil($fee/1000),'shiny'=>(int)ceil(pow(sqrt($fee/2),.75)*4),'value'=>$value];
    }

    public static function board(array $site,array $home,int $id,bool $busy): array
    {
        $s=$site['state'];$own=$site['owner_id']===$id;$claim=$s['mr2Outpost']['claim']??null;
        $data=['owned'=>$own,'revision'=>$s['mr2Outpost']['revision'],'busy'=>$busy,'takeover'=>null];
        if($claim && !$own){
            $allowed=($claim['attacker']===$id || ($claim['at']??0)+300<=time()) && World::reachable($id,$site) && Game::livingLevel($home,'maproom')>=2;
            $data['takeover']=['eligible'=>$allowed,'battle'=>$claim['battle'],'price'=>self::quote($site,$id)];
        }
        if(!$own)return $data;
        $data['facilities']=[];$c=Catalog::buildings();
        foreach(self::catalog() as $type=>$spec){
            if($type==='mr2core'||!isset($c[$type]))continue;
            $count=count(array_filter($s['buildings'],fn($b)=>$b['type']===$type));
            $data['facilities'][$type]=['count'=>$count,'limit'=>$spec['limit'],'reason'=>self::available($s,$home,$type,1),'max'=>min(count($spec['tiers']),$c[$type]['maxLevel'])]+self::price($type,1);
        }
        $data['upgrades']=[];
        foreach($s['buildings'] as $b)if($b['level'] && isset($data['facilities'][$b['type']]) && $b['level']<$data['facilities'][$b['type']]['max'])$data['upgrades'][$b['id']]=self::price($b['type'],$b['level']+1);
        $data['armor']=[];
        foreach($s['buildings'] as $b){
            $tier=(int)($b['fortification']??0);
            $offer=self::catalog()[$b['type']]['armor'][$tier]??null;
            if($offer!==null)$data['armor'][$b['id']]=['tier'=>$tier+1,'reason'=>self::requirement($s,$home,$offer['requires'])]+self::scale($offer['cost']);
        }
        $data['research']=$home['research'];$data['unlocked']=$home['unlocked'];$data['army']=array_filter($s['army']);$data['homeArmy']=array_filter($home['army']);
        $data['capacity']=Game::housingCapacity($s);$data['used']=Game::housingUsed($s);$data['queue']=$s['queue'];$data['planner']=Planner::status($s);
        $data['workersBusy']=(bool)array_filter($s['buildings'],[self::class,'workerBusy']);
        $data['kits']=self::kitPrices();
        $data['rush']=Game::livingLevel($s,'hatcherycontrol')>0&&$s['queue']?self::rushOffer($s,time()):null;
        $data['targets']=Game::livingLevel($s,'flinger')>0&&array_sum($s['army'])>0?self::nearbyTargets($site):[];
        return $data;
    }

    public static function defeated(array &$site,array &$battle,int $now):void
    {
        $site['state']['mr2Outpost']['claim']=['at'=>$now,'battle'=>$battle['id'],'attacker'=>$battle['attacker']['id'],'value'=>$battle['_madnessValue']??0];
        $site['state']['mr2Outpost']['revision']++;$site['state']['shield_until']=0;
        $battle['result']['captured']=false;$battle['result']['outpostTakeover']=true;
        World::save($site);
    }

    public static function claim(int $id,array $in):array
    {
        $siteId=self::number($in['siteId']??null);
        $seen=World::load($siteId);$before=$seen['owner_id'];$battle=self::number($in['battle']??null);
        if(!self::is($seen))throw new RuntimeException('Choose a regional outpost.');
        Db::transaction(function()use($id,$siteId,$before,$battle,$in){
            $owners=array_values(array_unique(array_filter([$id,$before])));sort($owners,SORT_NUMERIC);$bases=[];$now=time();
            foreach($owners as $owner){$bases[$owner]=Game::loadBase($owner,true);Game::accrue($bases[$owner],$now);}
            $s=World::load($siteId,true);$ledger=$s['state']['mr2Outpost']??[];$claim=$ledger['claim']??null;
            if($s['owner_id']===$id && ($ledger['claimedBattle']??0)===$battle)return;
            if($s['owner_id']!==$before||$before===$id||!$claim||$claim['battle']!==$battle||($claim['attacker']!==$id&&($claim['at']??0)+300>$now))throw new RuntimeException('Refresh the outpost. This conquest is no longer available.');
            if(World::ownerBusy($id)||($before && World::ownerBusy($before))||!World::reachable($id,$s)||Game::livingLevel($bases[$id],'maproom')<2)throw new RuntimeException('Finish active battles and check your Map Room reach before claiming this cell.');
            Truces::guard($bases[$id],(int)$before,$now);
            if(count(World::holdings($id))>=Game::livingLevel($bases[$id],'maproom')*2)throw new RuntimeException('Your holding capacity is full.');
            $price=self::quote($s,$id);self::charge($bases[$id],$in['payment']??'',array_fill_keys(self::MATERIALS,$price['each']),$price['shiny']);
            if($before!==null){
                self::accrue($s['state'],$now);Quests::collectFieldProjects($bases[$before],$s['state']);
                foreach($s['state']['buildings'] as &$facility){unset($facility['journalProject']);}unset($facility);
            }
            if($before===null){
                $s['state']=self::initialState($now);$s['state']['buildings']=[self::building('mr2core',1,16,16,1)];$s['state']['next_building_id']=2;
            }
            $state=&$s['state'];unset($state['regionInvite']);
            $state['garrison']=[];$state['army']=[];$state['queue']=[];$state['planner']=[];$state['resources']=array_fill_keys(self::MATERIALS,0);
            foreach($state['buildings'] as &$b){$b['stored']=0;}unset($b);
            $state['mr2Outpost']=['revision'=>(int)$ledger['revision']+1,'claim'=>null,'claimedBattle'=>$battle];
            $s['owner_id']=$id;$state['last_tick']=$now;$state['shield_until']=$now+43200;$state['repair_at']=0;
            Quests::outpostCaptured($bases[$id],$before!==null);
            MonsterMadness::capture($bases[$id],$siteId,$price['value'],$now);
            World::save($s);
            foreach($owners as $owner){World::syncBonuses($owner,$bases[$owner]);Game::saveBase($owner,$bases[$owner]);}
        });
        return World::site($id,$siteId);
    }

    private static function rushOffer(array $state,int $now):array
    {
        $seconds=0;foreach($state['queue'] as $job)$seconds+=min($job['duration']??PHP_INT_MAX,max(0,$job['ready_at']-$now));
        return ['price'=>4*YardUtilities::timePrice($seconds,false)];
    }

    private static function nearbyTargets(array $site):array
    {
        $range=2+AlliancePowers::rangeBonus($site['owner_id']);
        $q=Db::pdo()->prepare('SELECT s.id,s.q,s.r,COALESCE(u.name,s.name) AS name FROM world_sites s LEFT JOIN users u ON u.id=s.home_user_id WHERE (s.owner_id IS NULL OR s.owner_id<>?) AND s.q BETWEEN ? AND ? AND s.r BETWEEN ? AND ? ORDER BY s.id');
        $q->execute([$site['owner_id'],$site['q']-$range,$site['q']+$range,$site['r']-$range,$site['r']+$range]);$rows=[];
        foreach($q as $r)if(World::distance($site['q'],$site['r'],(int)$r['q'],(int)$r['r'])<=$range)$rows[]=['id'=>(int)$r['id'],'q'=>Regions::local((int)$r['q']),'r'=>(int)$r['r'],'name'=>$r['name']];
        return $rows;
    }

    public static function launch(int $keeper,int $origin,int $target):array
    {
        $site=World::load($target);
        return BattleService::startField($keeper,$origin,$site);
    }

    /** Scheduled bot turns use the same inventory, worker and takeover transactions as the workshop. */
    public static function botTakeover(int $id):bool
    {
        $q=Db::pdo()->prepare("SELECT id,target_id FROM battles WHERE attacker_id=? AND target_kind='resource' AND status='finished' ORDER BY id DESC LIMIT 1");$q->execute([$id]);$last=$q->fetch();
        if(!$last)return false;
        $site=World::load((int)$last['target_id']);
        if(!self::is($site)||($site['state']['mr2Outpost']['claim']['battle']??0)!==(int)$last['id'])return false;
        try{self::claim($id,['siteId'=>$site['id'],'battle'=>$last['id'],'payment'=>'materials']);return true;}
        catch(PDOException $e){throw $e;}catch(RuntimeException){return false;}
    }

    public static function botRaid(int $id,array $holdings,bool $allowHuman):?array
    {
        foreach($holdings as $site){
            if(!self::is($site)||array_sum($site['state']['army'])<3||Game::livingLevel($site['state'],'flinger')<1)continue;
            foreach(array_slice(self::nearbyTargets($site),0,8) as $candidate){
                $target=World::load($candidate['id']);
                if($target['owner_id']!==null&&!$allowHuman&&!Game::isBot($target['owner_id']))continue;
                try{return BattleService::botAttackFromField($id,$site['id'],$target);}
                catch(PDOException $e){throw $e;}catch(RuntimeException){}
            }
        }
        return null;
    }

    public static function botDevelop(int $id,array $site,array $home,int $seed):?string
    {
        $s=$site['state'];$buildings=$s['buildings'];$in=['siteId'=>$site['id'],'revision'=>$s['mr2Outpost']['revision']];
        $types=array_column($buildings,'type');$orders=[];
        if(!empty($s['mr2Outpost']['claim'])||array_filter($buildings,fn($b)=>$b['level']>0&&$b['hp']<$b['max_hp']))$orders[]=['op'=>'repair'];
        if(array_sum(array_column($buildings,'stored'))>=200 && array_filter(self::MATERIALS,fn($r)=>$home['resources'][$r]<Game::capacity($home)))$orders[]=['op'=>'collect'];
        if(!array_filter($buildings,fn($b)=>$b['ready_at']>time())){
            foreach(['sniper','housing','hatchery','bunker','twig','pebble','goo','putty','flinger','cannon','trap','laser'] as $t){
                if(in_array($t,$types,true))continue;
                $spots=[[10,12],[21,11],[13,22],[22,22],[22,16],[9,20],[6,6],[24,6],[6,25],[25,26],[17,6],[16,26],[10,5],[5,16]];
                foreach($spots as $i=>$unused){[$x,$y]=$spots[($i+$seed)%count($spots)];try{Game::validPosition($s,$t,$x,$y);$orders[]=['op'=>'build','type'=>$t,'x'=>$x,'y'=>$y];break;}catch(RuntimeException){}}
                break;
            }
        }
        foreach($buildings as $b)if($b['type']==='bunker'&&($s['army']['pokey']??0)>0)$orders[]=['op'=>'station','type'=>'pokey','quantity'=>1,'building'=>$b['id']];
        if(Game::livingLevel($s,'hatchery')>0)$orders[]=['op'=>'train','type'=>'pokey','quantity'=>2];
        if(($home['army']['pokey']??0)>0)$orders[]=['op'=>'send','type'=>'pokey','quantity'=>1];
        foreach($orders as $order){try{self::edit($id,$in+$order);return $order['op'];}catch(PDOException $e){throw $e;}catch(RuntimeException){}}
        return null;
    }

    private static function charge(array &$home,mixed $method,array $cost,int $shiny):void
    {
        if($method==='materials'){self::pay($home,$cost);return;}
        if($method!=='shiny'||($home['shiny']??0)<$shiny)throw new RuntimeException('Choose a payment you can afford.');
        $home['shiny']-=$shiny;
    }

    private static function number(mixed $v,int $max=PHP_INT_MAX):int
    {
        $n=filter_var($v,FILTER_VALIDATE_INT);if($n===false||$n<1||$n>$max)throw new RuntimeException('Choose a valid quantity or target.');
        return $n;
    }

    private static function kitPrices():array
    {
        return [
            1=>['name'=>'Regular Kit','count'=>113,'cost'=>['twig'=>12000,'pebble'=>12000,'putty'=>6000],'shiny'=>420],
            2=>['name'=>'Mega Kit','count'=>148,'cost'=>['twig'=>50000,'pebble'=>50000,'putty'=>25000],'shiny'=>800],
            3=>['name'=>'Ultra Kit','count'=>171,'cost'=>['twig'=>200000,'pebble'=>200000,'putty'=>100000],'shiny'=>1500]
        ];
    }

    public static function edit(int $id,array $in):array
    {
        $siteId=self::number($in['siteId']??null);$revision=self::number($in['revision']??null);
        Db::transaction(function()use($id,$siteId,$revision,$in){
            $home=Game::loadBase($id,true);$now=time();Game::accrue($home,$now);$site=World::load($siteId,true);
            if(!self::is($site)||$site['owner_id']!==$id)throw new RuntimeException('You can only develop your own Map Room 2 outpost.');
            if(World::ownerBusy($id))throw new RuntimeException('Finish your current battle before changing the outpost.');
            World::refreshSite($site,$now);$s=&$site['state'];
            if($revision!==$s['mr2Outpost']['revision'])throw new RuntimeException('Your outpost changed. Reopen the yard before repeating that order.');
            $op=$in['op']??'';
            if(($s['mr2Outpost']['claim']??null) && $op!=='repair')throw new RuntimeException('Repair the breached outpost before developing it.');
            if($op==='repair'){
                foreach($s['buildings'] as &$b){if($b['level']>0){$b['hp']=$b['max_hp'];if(Catalog::isTrap($b['type']))$b['spent']=false;}}unset($b);
                $s['mr2Outpost']['claim']=null;
            }elseif($op==='rush'){
                $rate=self::rushOffer($s,$now);
                if(Game::livingLevel($s,'hatcherycontrol')<1||!$s['queue']||filter_var($in['price']??null,FILTER_VALIDATE_INT)===false||(int)$in['price']<$rate['price'])throw new RuntimeException('Refresh the hatchery offer.');
                self::charge($home,'shiny',[],$rate['price']);
                foreach($s['queue'] as $job)$s['army'][$job['type']]=($s['army'][$job['type']]??0)+$job['quantity'];
                $s['queue']=[];
            }elseif($op==='collect'){
                foreach($s['buildings'] as &$b){$r=Catalog::buildings()[$b['type']]['resource']??null;if(!$r)continue;
                    $take=min((int)$b['stored'],max(0,(int)(Game::capacity($home)-$home['resources'][$r])));$b['stored']-=$take;$home['resources'][$r]+=$take;
                }unset($b);
            }elseif($op==='fortify'){
                if(array_filter($s['buildings'],[self::class,'workerBusy']))throw new RuntimeException('The outpost worker is busy.');
                $bid=self::number($in['building']??0);$index=array_search($bid,array_column($s['buildings'],'id'),true);
                if($index===false)throw new RuntimeException('Select an outpost building to fortify.');
                $b=&$s['buildings'][$index];$tier=(int)($b['fortification']??0);$row=self::catalog()[$b['type']]['armor'][$tier]??null;
                if(!$row||!$b['level']||$b['hp']<$b['max_hp'])throw new RuntimeException('Choose a fully repaired building with an available armor tier.');
                if($reason=self::requirement($s,$home,$row['requires']))throw new RuntimeException($reason);
                $offer=self::scale($row['cost']);self::pay($home,$offer['cost']);$b['outpostArmor']=true;
                $b['fortify_to']=$tier+1;$b['fortify_ready_at']=$now+$offer['seconds'];unset($b);
            }elseif(in_array($op,['build','move','upgrade'],true)){
                self::construction($s,$home,$in,$now);
            }elseif(in_array($op,['train','send','return','station','recall','juice'],true)){
                self::troops($s,$home,$in,$now);
            }elseif($op==='saveLayout'||$op==='loadLayout'){
                $status=Planner::status($s,$now);$slot=self::number($in['slot']??1,2);
                $positions=$op==='saveLayout'?$status['positions']:($s['planner']['slots'][$slot]['positions']??null);
                if($positions===null)throw new RuntimeException('Save this outpost layout first.');
                Planner::mutateBase($s,$op==='saveLayout'?'plannerSave':'plannerApply',['revision'=>$status['revision'],'slot'=>$slot,'name'=>'Outpost plan '.$slot,'positions'=>$positions],$now);
            }elseif($op==='kit'){
                if(!empty($s['queue'])||array_sum($s['army'])>0||array_filter($s['garrison'],static fn($v)=>array_sum($v)>0))throw new RuntimeException('Move out troops and finish hatching before replacing this yard.');
                $k=self::number($in['kit']??0,3);$price=self::kitPrices()[$k];$method=$in['payment']??'';self::charge($home,$method,$price['cost'],$price['shiny']);
                $kit=json_decode(file_get_contents(__DIR__.'/outpost-kits.json'),true,512,JSON_THROW_ON_ERROR)[$k-1];
                $s['buildings']=[];$s['planner']=[];$s['garrison']=[];$next=(int)$s['next_building_id'];
                foreach($kit as [$type,$x,$y,$level]){
                    $b=self::building($type,$next++,$x,$y,$level);
                    if($method==='materials'&&$type!=='mr2core'){
                        $b['level']=0;$b['upgrade_to']=$level;$b['ready_at']=$now+max(1,self::price($type,min(count(self::catalog()[$type]['tiers']),$level))['seconds']);
                    }
                    Quests::fieldProject($s,$b);$s['buildings'][]=$b;
                }
                $s['next_building_id']=$next;Quests::outpostKit($home);
            }else throw new RuntimeException('Choose an available field-yard action.');
            $s['last_tick']=$now;Quests::collectFieldProjects($home,$s);++$s['mr2Outpost']['revision'];World::save($site);Game::saveBase($id,$home);
        });
        return World::site($id,$siteId);
    }

    private static function construction(array &$s,array &$home,array $in,int $now):void
    {
        $op=$in['op'];$id=$op==='build'?0:self::number($in['building']??0);$index=null;
        if($id){foreach($s['buildings'] as $k=>$b)if($b['id']===$id){$index=$k;break;}if($index===null)throw new RuntimeException('This building has left the yard.');}
        $type=$op==='build'?($in['type']??''):$s['buildings'][$index]['type'];
        if(!is_string($type)||$type==='mr2core'||!isset(self::catalog()[$type]))throw new RuntimeException('Choose a movable outpost facility.');
        if($id && self::workerBusy($s['buildings'][$index]))throw new RuntimeException('Wait for this building to finish.');
        if($op!=='upgrade'){
            foreach(['x','y'] as $axis)if(!isset($in[$axis])||!is_numeric($in[$axis])||!is_finite((float)$in[$axis]))throw new RuntimeException('Click a finite point on the yard.');
            $x=round((float)$in['x'],2);$y=round((float)$in['y'],2);Game::validPosition($s,$type,$x,$y,$id?:null);
            if($op==='move'){$s['buildings'][$index]['x']=$x;$s['buildings'][$index]['y']=$y;return;}
        }
        if(array_filter($s['buildings'],[self::class,'workerBusy']))throw new RuntimeException('The outpost worker is busy.');
        $level=$id?$s['buildings'][$index]['level']+1:1;$job=self::price($type,$level);
        if($reason=self::available($s,$home,$type,$level))throw new RuntimeException($reason);
        if(!$id && (count($s['buildings'])>=220||count(array_filter($s['buildings'],fn($b)=>$b['type']===$type))>=self::catalog()[$type]['limit']))throw new RuntimeException('Outpost facility limit reached.');
        if($id && $s['buildings'][$index]['hp']<$s['buildings'][$index]['max_hp'])throw new RuntimeException('Repair this facility before upgrading it.');
        self::pay($home,$job['cost']);
        if(!$id){$index=count($s['buildings']);$s['buildings'][]=self::building($type,$s['next_building_id']++,$x,$y,0);Quests::fieldProject($s,$s['buildings'][$index]);}
        $s['buildings'][$index]['upgrade_to']=$level;$s['buildings'][$index]['ready_at']=$now+$job['seconds'];
    }

    private static function troops(array &$s,array &$home,array $in,int $now):void
    {
        $op=$in['op'];$t=$in['type']??'';$q=self::number($in['quantity']??1,200);$m=is_string($t)?(Catalog::monsters()[$t]??null):null;
        if(!$m||($m['origin']??'surface')==='summon')throw new RuntimeException('Choose an available species.');
        $s['army'][$t]=(int)($s['army'][$t]??0);$home['army'][$t]=(int)($home['army'][$t]??0);
        if($op==='train'){
            if(!in_array($t,$home['unlocked'],true)||($m['origin']??'surface')!=='surface')throw new RuntimeException('Discover this surface species in your home Locker first.');
            $lanes=array_fill_keys(array_column(YardUtilities::hatcheries($s,$now),'id'),$now);
            if(!$lanes)throw new RuntimeException('A working outpost Hatchery is required.');
            if(count($s['queue'])+$q>100)throw new RuntimeException('The field hatching queue holds up to 100 monsters.');
            self::reserve($s,$m,$q);self::pay($home,['goo'=>$m['cost']*$q]);
            foreach($s['queue'] as $job){
                $lane=$job['lane']??array_key_first($lanes);
                if(isset($lanes[$lane]))$lanes[$lane]=max($lanes[$lane],$job['ready_at']);
            }
            $work=max(1,(int)ceil($m['trainTime']/Game::speed()));
            for($i=0;$i<$q;$i++){
                asort($lanes);$lane=array_key_first($lanes);$start=$lanes[$lane];$seconds=StorePowers::workTime($home,$start,$work);
                $lanes[$lane]+=$seconds;$s['queue'][]=['id'=>$s['next_queue_id']++,'type'=>$t,'quantity'=>1,'paid_goo'=>$m['cost'],'lane'=>$lane,'duration'=>$seconds,'started_at'=>$start,'ready_at'=>$lanes[$lane]];
            }
            return;
        }
        if($op==='send'||$op==='return'){
            if($op==='send'){$src=&$home;$dst=&$s;}else{$src=&$s;$dst=&$home;}
            if($src['army'][$t]<$q)throw new RuntimeException('There are fewer ready troops than the amount selected.');
            self::reserve($dst,$m,$q);$src['army'][$t]-=$q;$dst['army'][$t]+=$q;return;
        }
        if($op==='juice'){
            if(Game::livingLevel($s,'juicer')<1||$s['army'][$t]<$q)throw new RuntimeException('A working Juicer and ready troops are required.');
            $credit=min(max(0,Game::capacity($home)-(int)$home['resources']['goo']),floor($m['cost']*$q*Catalog::all()['juicer']['refundRate']));
            if($credit<=0)throw new RuntimeException('Goo storage is full. Spend Goo before recycling monsters.');
            $s['army'][$t]-=$q;$home['resources']['goo']+=$credit;Quests::juiced($home,$q);return;
        }
        $bunker=self::number($in['building']??0);$selected=null;
        foreach($s['buildings'] as $b)if($b['id']===$bunker&&$b['type']==='bunker'&&$b['hp']>0&&$b['ready_at']<=$now&&$b['level']>0)$selected=$b;
        if(!$selected)throw new RuntimeException('Select a working outpost bunker.');
        $stock=(int)($s['garrison'][$bunker][$t]??0);
        if($op==='station'){
            if($s['army'][$t]<$q||Game::bunkerUsed($s,$bunker)+$q*$m['size']>Game::bunkerCapacity($s,$bunker))throw new RuntimeException('Check your army and the bunker capacity.');
            $s['army'][$t]-=$q;$s['garrison'][$bunker][$t]=$stock+$q;
        }else{
            if($stock<$q)throw new RuntimeException('Too few defenders are stationed here.');
            self::reserve($s,$m,$q);$s['army'][$t]+=$q;$s['garrison'][$bunker][$t]=$stock-$q;
        }
    }

    private static function reserve(array $s,array $spec,int $count):void
    {
        if(Game::housingUsed($s)+$count*$spec['size']>Game::housingCapacity($s))throw new RuntimeException('The destination does not have enough monster housing.');
    }
}
