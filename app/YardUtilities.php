<?php
declare(strict_types=1);
require_once __DIR__.'/StorePowers.php';

/** Time-savings quotes are recomputed under the keeper's home mutex. */
final class YardUtilities
{
    public static function buildings(): array
    {
        $rows=[
            'generalstore'=>[12,'General Store','Spend earned Shiny on deliveries, overdrives, protection, champion care and time savings.',11,8,0,1,4000,2.2,1,[0,1,1,1,1,1]],
            'hatcherycontrol'=>[16,'Hatchery Control Center','Manage the shared hatchery queue and finish the orders assigned to working hatcheries together.',40000,40000,40000,300,64000,2.4,3,[0,0,0,1,1,1]],
            'stonewall'=>[18,'Stone Block','A reinforced stone block obstructs ground troops and attracts wall-breaking monsters.',0,20,0,1,3600,.8,3,[0,0,0,20,40,60]],
        ];
        $catalog=[];
        foreach($rows as $type=>[$id,$name,$description,$twig,$pebble,$putty,$time,$hp,$size,$hall,$limits]) {
            $catalog[$type]=['originalId'=>$id,'name'=>$name,'description'=>$description,'cost'=>compact('twig','pebble','putty')+['goo'=>0],
                'buildTime'=>$time,'hp'=>$hp,'size'=>$size,'unlockLevel'=>$hall,'maxLevel'=>1,
                'limitByTownHall'=>$limits,'art'=>$type.'.png','canFortify'=>false];
        }
        return $catalog;
    }

    public static function hatcheries(array $base,int $now,int $level=1): array
    {
        return array_values(array_filter($base['buildings'],static fn(array $b):bool=>
            $b['type']==='hatchery' && $b['level']>=$level && $b['hp']>0 && ($b['ready_at']??0)<=$now));
    }

    /** Annotate only currently operating egg lanes. No reserve or species data leaves the yard. */
    public static function hatcheryWork(array $yard,int $now): array
    {
        $buildings=$yard['buildings'];
        if(empty($yard['queue'])){return $buildings;}
        $working=self::hatcheries($yard,$now);
        if(!$working){return $buildings;}
        $lanes=[];
        $first=$working[0]['id'];
        foreach($yard['queue'] as $job){
            $end=(int)$job['ready_at'];
            if($end<=$now)continue;
            $start=(int)($job['started_at']??$job['start']??($end-($job['duration']??max(1,$end-$now))));
            $lanes[$job['lane']??$first][]=[$start,$end];
        }
        foreach($buildings as &$building){
            if($building['type']!=='hatchery'||empty($lanes[$building['id']])||$building['hp']<=0||($building['ready_at']??0)>$now)continue;
            $intervals=$lanes[$building['id']];sort($intervals);
            $until=$now;
            foreach($intervals as [$start,$end]){
                if($start>$until)break;
                $until=max($until,$end);
            }
            if($until<=$now)continue;
            $building['hatchingUntil']=$until;
            foreach(['HOD3'=>5,'HOD2'=>3,'HOD'=>2] as $code=>$steps){
                $expiry=StorePowers::expiry($yard,$code);
                if($expiry>$now){$building['hatchDrive']=['steps'=>$steps,'until'=>$expiry];break;}
            }
        }
        unset($building);
        return $buildings;
    }

    private static function working(array $base,string $type,int $now):bool
    {
        foreach($base['buildings'] as $building){
            if($building['type']===$type && $building['level']>0 && $building['hp']>0 && $building['ready_at']<=$now){return true;}
        }
        return false;
    }

    public static function timePrice(int $seconds,bool $freeWindow=true):int
    {
        if ($seconds<=0 || ($freeWindow && $seconds<=300)) { return 0; }
        return (int)min(ceil($seconds/180),floor(sqrt(.8*$seconds)));
    }

    private static function jobs(array $base,int $now):array
    {
        $jobs=[];$catalog=Catalog::buildings();$monsters=Catalog::homeMonsters();
        foreach($base['buildings'] as $b){
            foreach(['ready_at'=>'Construction','fortify_ready_at'=>'Fortification'] as $field=>$name){
                $due=(int)($b[$field]??0);
                if($due>$now){$jobs[$field.'.'.$b['id'].'.'.$due]=['kind'=>$field,'id'=>$b['id'],'due'=>$due,
                    'name'=>$name.' · '.$catalog[$b['type']]['name'],'price'=>self::timePrice($due-$now)];}
            }
        }
        foreach(['researchQueue'=>'Academy','unlockQueue'=>'Locker','lab'=>'Monster Lab'] as $kind=>$label){
            $queue=$kind==='lab'?array_filter([$base['lab']['queue']??null]):($base[$kind]??[]);
            foreach($queue as $job){
                $due=(int)$job['ready_at'];$type=$job['type'];if($due<=$now){continue;}
                $key=$kind.'.'.$type.'.'.$due;
                $jobs[$key]=['kind'=>$kind,'type'=>$type,'due'=>$due,'name'=>$label.' · '.($monsters[$type]['name']??$type),'price'=>self::timePrice($due-$now)];
            }
        }
        return $jobs;
    }

    private static function hatchQuote(array $base,int $now):array
    {
        $lanes=array_fill_keys(array_column(self::hatcheries($base,$now),'id'),true);
        $jobs=[];$seconds=0;$quantity=0;
        foreach($base['queue'] as $job){
            $lane=$job['lane']??array_key_first($lanes);
            if(!isset($lanes[$lane]) || $job['ready_at']<=$now){continue;}
            $quantityInOrder=max(1,(int)($job['quantity']??1));
            $duration=(int)($job['duration']??0);
            if ($duration<1) {
                $duration=isset($job['started_at']) ? $job['ready_at']-$job['started_at']
                    : (int)ceil((Catalog::homeMonsters()[$job['type']]['trainTime']??1)*$quantityInOrder/Game::speed());
            }
            $duration=max(1,$duration);
            $seconds+=min($duration,$job['ready_at']-$now);
            $jobs[(int)$job['id']]=(int)$job['ready_at'];
            $quantity+=$quantityInOrder;
        }
        return ['key'=>hash('sha256',json_encode($jobs,JSON_THROW_ON_ERROR)),'jobs'=>$jobs,'quantity'=>$quantity,'lanes'=>count($lanes),
            'price'=>4*self::timePrice($seconds,false)];
    }

    private static function deliveries(array $base,bool $native=false):array
    {
        $offers=[];$cap=$native?InfernoRealm::capacity($base):Game::capacity($base);
        foreach($native?['bone','coal','sulfur','magma']:['twig','pebble','putty','goo'] as $type){
            $room=max(0,$cap-(int)ceil($base['resources'][$type]??0));
            foreach(['10'=>(int)floor($cap*.1),'50'=>(int)floor($cap*.5),'fill'=>$room] as $size=>$amount){
                if($amount<1 || $amount>$room){continue;}
                $offers[$type.'.'.$size]=['resource'=>$type,'amount'=>$amount,'price'=>(int)ceil(pow(sqrt($amount/2),.75))];
            }
        }
        if(!$native && isset($base['infernoRealm']))$offers+=self::deliveries($base['infernoRealm'],true);
        return $offers;
    }

    public static function view(array $base,int $now):array
    {
        $store=self::working($base,'generalstore',$now);$control=self::working($base,'hatcherycontrol',$now);
        $hatch=$control?self::hatchQuote($base,$now):null;
        if($hatch!==null){unset($hatch['jobs']);}
        return ['store'=>$store,'control'=>$control,'revision'=>(int)($base['storeRevision']??0),
            'jobs'=>$store?(object)self::jobs($base,$now):(object)[],'offers'=>$store?(object)self::deliveries($base):(object)[],
            'powers'=>$store?(object)StorePowers::offers($base,$now):(object)[],
            'bunkers'=>$store?(object)StorePowers::bunkerOffers($base,$now):(object)[],'hatch'=>$hatch,'qualifyingHatcheries'=>count(self::hatcheries($base,$now,2))];
    }

    /** The caller has accrued the base and checked for an active battle. */
    public static function purchase(array &$base,array $input,int $now): void
    {
        $kind=$input['kind']??null;$key=$input['key']??null;
        if(!is_string($key) || !is_int($input['revision']??null) || !is_int($input['price']??null) || $input['price']<0){throw new RuntimeException('Reopen this offer for a current quote.');}
        if($input['revision']!==($base['storeRevision']??0)){throw new RuntimeException('A purchase changed this yard. Reopen the offer.');}
        if(!self::working($base,$kind==='hatch'?'hatcherycontrol':'generalstore',$now)){throw new RuntimeException('Build or repair the selected facility first.');}
        if(isset($base['infernoRealm']) && (
            ($kind==='power' && (str_ends_with($key,'I') || str_contains($key,'I@'))) ||
            ($kind==='delivery' && in_array(explode('.',$key)[0],['bone','coal','sulfur','magma'],true))
        )){
            if(InfernoDefense::active($base['infernoRealm']))throw new RuntimeException('Resolve the Moloch counterattack before changing your cavern.');
            InfernoRealm::accrue($base['infernoRealm'],$now);
        }
        if($kind==='hatch'){
            $offer=self::hatchQuote($base,$now);
            if(!$offer['quantity'] || $key!==$offer['key']){throw new RuntimeException('The hatchery orders changed. Reopen the queue.');}
        }elseif(in_array($kind,['time','hour','twohours'],true)){
            $offer=self::jobs($base,$now)[$key]??null;
            if($offer && $kind!=='time'){
                $hours=$kind==='hour'?1:2;
                if($offer['due']-$now<3600*$hours)throw new RuntimeException('That job has less than the selected time remaining.');
                $offer['price']=20*$hours;$offer['target']=$offer['due']-3600*$hours;
            }
        }elseif($kind==='power'){
            $offer=StorePowers::offers($base,$now)[$key]??null;
            if(!empty($offer['reason']))throw new RuntimeException($offer['reason']);
        }elseif($kind==='bunker'){
            $offer=is_int($input['amount']??null)?StorePowers::bunkerQuote($base,$key,$input['amount'],$now):null;
        }elseif($kind==='delivery'){
            $offer=self::deliveries($base)[$key]??null;
            if(!$offer || ($input['amount']??null)!==$offer['amount']){throw new RuntimeException('The delivery changed. Reopen the store.');}
        }else{throw new RuntimeException('Select a store item.');}
        if(!$offer || $offer['price']>$input['price']){throw new RuntimeException('That offer changed or the work already finished.');}
        if((int)($base['shiny']??0)<$offer['price']){throw new RuntimeException('You need more Shiny for this purchase.');}
        $base['shiny']=(int)($base['shiny']??0)-$offer['price'];
        $base['storeRevision']=(int)($base['storeRevision']??0)+1;
        if($kind==='power'){StorePowers::activate($base,$key,$now);return;}
        if($kind==='bunker'){
            $id=$offer['bunker'];$type=$offer['type'];
            $base['garrison'][$id][$type]=(int)($base['garrison'][$id][$type]??0)+$offer['quantity'];
            return;
        }
        if($kind==='delivery'){
            $r=$offer['resource'];
            if(in_array($r,['bone','coal','sulfur','magma'],true)){
                $base['infernoRealm']['resources'][$r]+=$offer['amount'];++$base['infernoRealm']['revision'];
            }else{$base['resources'][$r]+=$offer['amount'];}
            return;
        }
        $target=$offer['target']??$now;
        if($kind==='hatch'){
            foreach($base['queue'] as &$job){if(isset($offer['jobs'][$job['id']])){$job['ready_at']=$now;}}unset($job);
        }elseif(isset($offer['id'])){
            foreach($base['buildings'] as &$building){if($building['id']===$offer['id']){$building[$offer['kind']]=$target;break;}}unset($building);
        }elseif($offer['kind']==='lab'){
            $base['lab']['queue']['ready_at']=$target;
        }else{
            foreach($base[$offer['kind']] as &$job){
                if($job['type']===$offer['type'] && $job['ready_at']===$offer['due']){$job['ready_at']=$target;break;}
            }unset($job);
        }
        Game::accrue($base,$now);
    }
}
