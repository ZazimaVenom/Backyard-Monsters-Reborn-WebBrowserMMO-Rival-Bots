<?php
declare(strict_types=1);
require_once __DIR__.'/MonsterExpansion.php';

final class InfernoMonsters
{
    public static function initial():array
    {
        return ['unlocked'=>['spurtz'],'army'=>array_fill_keys(array_keys(MonsterExpansion::INFERNO_LOCKERS),0),
            'research'=>['spurtz'=>1],'queue'=>[],'study'=>null,'discovery'=>null,'nextEgg'=>1,'monsterRevision'=>0];
    }

    public static function accrue(array &$realm,int $now,float $speed=1):void
    {
        $realm+=self::initial();$waiting=[];
        foreach($realm['queue'] as $job){
            if($job['ready_at']>$now){$waiting[]=$job;continue;}
            $realm['army'][$job['type']]=($realm['army'][$job['type']]??0)+1;
        }
        $realm['queue']=$waiting;
        foreach(['study','discovery'] as $lane){
            $job=$realm[$lane];
            if(!$job||$job['ready_at']>$now){continue;}
            $type=$job['type'];
            $realm['research'][$type]=max($realm['research'][$type]??0,$job['level']);
            if($lane==='discovery'&&!in_array($type,$realm['unlocked'],true)){$realm['unlocked'][]=$type;}
            $realm[$lane]=null;
        }
    }

    public static function compoundCapacity(int $level):int { return 400*max(0,$level); }

    private static function facilities(array $realm,int $now):array
    {
        $levels=['locker'=>0,'academy'=>0,'hatchery'=>0,'housing'=>0];$lanes=[];
        foreach($realm['buildings'] as $b){
            $type=$b['type'];
            if(!isset($levels[$type])||$b['level']<=0||$b['hp']<=0||($b['ready_at']??0)>$now){continue;}
            if($type==='housing'){$levels['housing']+=self::compoundCapacity((int)$b['level']);continue;}
            $levels[$type]=max($levels[$type],(int)$b['level']);
            if($type==='hatchery'){$lanes[$b['id']]=$now;}
        }
        foreach($realm['queue'] as $q){if(isset($lanes[$q['lane']])){$lanes[$q['lane']]=max($lanes[$q['lane']],$q['ready_at']);}}
        $levels['housing']=StorePowers::reserveCapacity($realm,$levels['housing'],$now);
        return [$levels,$lanes];
    }

    private static function option(array $cost,int $duration,float $speed,?string $reason,array $wallet):array
    {
        foreach($cost as $currency=>$amount){
            if(!$reason&&($wallet[$currency]??0)<$amount){$reason='More '.ucfirst($currency).' needed';}
        }
        return ['cost'=>$cost,'seconds'=>max(1,(int)ceil($duration/max(0.25,$speed))),'reason'=>$reason];
    }

    public static function space(array $realm,int $now):array
    {
        [$facilities]=self::facilities($realm,$now);
        return ['capacity'=>$facilities['housing'],'used'=>self::occupied($realm)];
    }

    private static function occupied(array $realm):int
    {
        $used=0;$catalog=MonsterExpansion::catalog();
        foreach($realm['army'] as $type=>$count){$used+=(int)($catalog[$type]['size']??0)*$count;}
        foreach($realm['queue'] as $job){$used+=$catalog[$job['type']]['size'];}
        return $used;
    }

    public static function view(array $realm,int $now=0,float $speed=1):array
    {
        $now=$now?:time();self::accrue($realm,$now,$speed);[$facilities]=self::facilities($realm,$now);
        $catalog=MonsterExpansion::catalog();$used=self::occupied($realm);$options=[];
        foreach(MonsterExpansion::INFERNO_LOCKERS as $type=>$locker){
            $spec=$catalog[$type];$unlocked=in_array($type,$realm['unlocked'],true);
            $level=min(6,max(1,$realm['research'][$type]??1));$tier=$spec['levels'][$level];
            $trainReason=match(true){!$unlocked=>'Discover this species first',!$facilities['hatchery']=>'Build a working Incubator',
                count($realm['queue'])>=100=>'The Incubators are full',$used+$spec['size']>$facilities['housing']=>'More Compound space needed',default=>null};
            $unlockReason=match(true){$unlocked=>'Discovered',!empty($realm['discovery'])=>'Discovery in progress',
                $facilities['locker']<$locker=>'Inferno Locker level '.$locker.' required',default=>null};
            $studyReason=match(true){!$unlocked=>'Discover this species first',$level>=6=>'Maximum level',!empty($realm['study'])=>'Research in progress',
                $facilities['academy']<$level=>'Infernal Academy level '.$level.' required',default=>null};
            $options[$type]=['name'=>$spec['name'],'description'=>$spec['description'],'art'=>$spec['art'],'unlocked'=>$unlocked,
                'level'=>$level,'size'=>$spec['size'],'hp'=>$tier['hp'],'damage'=>$tier['damage'],
                'train'=>self::option(['magma'=>$tier['cost']],$tier['trainTime'],$speed,$trainReason,$realm['resources']),
                'unlock'=>self::option($spec['unlockCost'],$spec['unlockTime'],$speed,$unlockReason,$realm['resources']),
                'research'=>self::option($tier['nextStudy']['cost']??[],$tier['nextStudy']['seconds']??1,$speed,$studyReason,$realm['resources'])];
        }
        return array_intersect_key($realm,array_flip(['army','research','unlocked','queue','study','discovery']))+
            ['revision'=>$realm['monsterRevision'],'used'=>$used,'capacity'=>$facilities['housing'],'options'=>$options];
    }

    public static function act(array &$realm,string $action,array $input,int $now,float $speed=1):void
    {
        self::accrue($realm,$now,$speed);
        if(($input['monsterRevision']??null)!==$realm['monsterRevision']){throw new RuntimeException('These monster orders changed. Refresh the cavern and try again.');}
        if($action==='infernoCancel'){
            self::cancel($realm,$input,$now);++$realm['monsterRevision'];return;
        }
        $lane=['infernoTrain'=>'train','infernoResearch'=>'research','infernoUnlock'=>'unlock'][$action]??null;
        $type=$input['type']??null;
        if(!$lane||!is_string($type)||!isset(MonsterExpansion::INFERNO_LOCKERS[$type])){throw new RuntimeException('Choose an Inferno species.');}
        $view=self::view($realm,$now,$speed);$species=$view['options'][$type];$option=$species[$lane];
        if($option['reason']){throw new RuntimeException($option['reason'].'.');}
        $quantity=$lane==='train'?($input['quantity']??1):1;
        if(!is_int($quantity)||$quantity<1||$quantity>30){throw new RuntimeException('Choose a quantity from 1 to 30.');}
        if($lane==='train'&&(count($realm['queue'])+$quantity>100||$view['used']+$quantity*$species['size']>$view['capacity'])){throw new RuntimeException('This order exceeds the Incubator queue or Compound capacity.');}
        $cost=$option['cost']['magma']*$quantity;
        if($realm['resources']['magma']<$cost){throw new RuntimeException('More Magma is needed for this order.');}
        $realm['resources']['magma']-=$cost;
        if($lane==='train'){
            [, $lanes]=self::facilities($realm,$now);
            for($i=0;$i<$quantity;++$i){
                asort($lanes);$id=array_key_first($lanes);$start=$lanes[$id];$work=$option['seconds'];
                $duration=StorePowers::workTime($realm,$start,$work);$lanes[$id]+=$duration;
                $realm['queue'][]=['id'=>$realm['nextEgg']++,'lane'=>$id,'type'=>$type,'start'=>$start,'duration'=>$duration,
                    'ready_at'=>$lanes[$id],'paid'=>$option['cost']['magma']]+($duration===$work?[]:['work'=>$work]);
            }
        }else{
            $field=$lane==='unlock'?'discovery':'study';
            $realm[$field]=['type'=>$type,'level'=>$field==='discovery'?1:$species['level']+1,'ready_at'=>$now+$option['seconds'],'paid'=>$cost];
        }
        ++$realm['monsterRevision'];
    }

    private static function cancel(array &$realm,array $input,int $now):void
    {
        $lane=$input['lane']??null;
        if(in_array($lane,['study','discovery'],true)){
            $job=$realm[$lane]??throw new RuntimeException('That monster study has finished or was cancelled.');
            $realm[$lane]=null;
        }elseif($lane==='train'){
            $index=array_search($input['id']??null,array_column($realm['queue'],'id'),true);
            if($index===false){throw new RuntimeException('That egg has hatched or was cancelled.');}
            $job=$realm['queue'][$index];array_splice($realm['queue'],$index,1);$next=max($now,$job['start']);
            foreach($realm['queue'] as &$egg){
                if($egg['lane']===$job['lane']&&$egg['start']>=$job['ready_at']){
                    $egg['start']=$next;$work=$egg['work']??$egg['duration'];
                    $egg['duration']=StorePowers::workTime($realm,$next,$work);if($egg['duration']!==$work)$egg['work']=$work;
                    $next+=$egg['duration'];$egg['ready_at']=$next;
                }
            }unset($egg);
        }else{throw new RuntimeException('Choose a pending monster order.');}
        $credit=max(0,min($job['paid'],InfernoRealm::capacity($realm)-$realm['resources']['magma']));
        $realm['resources']['magma']+=$credit;
    }
}
