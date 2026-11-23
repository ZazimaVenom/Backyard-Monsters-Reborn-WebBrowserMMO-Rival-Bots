<?php
declare(strict_types=1);

/** The cavern Compound houses real native stock. Only a new defense snapshots it. */
final class InfernoCompound
{
    public const RANGES=[1=>12.5,13.25,14.0,14.75,15.5,16.25];

    public static function seed(array &$battle,array $realm):void
    {
        $rooms=[];$free=[];
        foreach($battle['buildings'] as $i=>$building){
            if($building['type']!=='housing')continue;
            $level=(int)$building['level'];$radius=self::RANGES[max(1,min(6,$level))];
            $battle['buildings'][$i]['compoundRange']=$radius;
            if($level<=0||$building['hp']<=0||empty($building['_ready']))continue;
            $id=(int)$building['id'];$rooms[$id]=['held'=>[],'lost'=>[],'seq'=>0,'next'=>0.0];
            $free[$id]=InfernoMonsters::compoundCapacity($level);
        }
        if(!$rooms)return;
        $battle['_compoundResearch']=$realm['research']??[];
        $catalog=MonsterExpansion::catalog();
        // Place larger species first and balance remaining space among real, ready houses.
        $types=array_keys(MonsterExpansion::INFERNO_LOCKERS);
        usort($types,fn($a,$b)=>$catalog[$b]['size']<=>$catalog[$a]['size']);
        foreach($types as $type){
            $size=$catalog[$type]['size'];
            for($n=0;$n<(int)($realm['army'][$type]??0);++$n){
                $room=array_search(max($free),$free,true);
                if($free[$room]<$size)break;
                $free[$room]-=$size;$rooms[$room]['held'][$type]=($rooms[$room]['held'][$type]??0)+1;
            }
        }
        $battle['_compounds']=$rooms;
    }

    public static function tick(array &$battle):void
    {
        if(empty($battle['_compounds']))return;
        foreach($battle['buildings'] as $b){
            $id=$b['id'];
            if(!isset($battle['_compounds'][$id])||$b['hp']<=0)continue;
            $room=&$battle['_compounds'][$id];
            if(!$room['held']||$room['next']>$battle['elapsed']){unset($room);continue;}
            $room['next']=$battle['elapsed']+.75; // 30 original frames at 40 fps.
            $ground=[];$flyers=[];
            foreach($battle['units'] as $i=>$unit){
                if($unit['team']!=='attacker'||$unit['hp']<=0||!BattleEngine::canHit('both',$unit))continue;
                $distance=hypot($unit['x']-$b['x'],$unit['y']-$b['y']);
                if($distance>$b['compoundRange'])continue;
                if(($unit['movement']??'ground')==='air')$flyers[$i]=$distance;else $ground[$i]=$distance;
            }
            asort($ground);asort($flyers);
            foreach(array_slice(array_keys($flyers),0,3) as $i){
                foreach(['balthazar','sabnox'] as $type){
                    if(empty($room['held'][$type]))continue;
                    self::dispatch($battle,$room,$b,$type,$battle['units'][$i]);break;
                }
            }
            if($ground){
                $nearest=$battle['units'][array_key_first($ground)];
                foreach($room['held'] as $type=>$count){
                    for($i=0;$i<$count;++$i)self::dispatch($battle,$room,$b,$type,$nearest);
                }
            }
            unset($room);
        }
    }

    private static function dispatch(array &$battle,array &$room,array $house,string $type,array $target):void
    {
        --$room['held'][$type];if($room['held'][$type]===0)unset($room['held'][$type]);
        $n=++$room['seq'];$angle=atan2($target['y']-$house['y'],$target['x']-$house['x'])+($n%5-2)*.12;
        [$x,$y]=BattleEngine::spawnPosition($battle,$house,$angle);
        $unit=BattleService::makeUnit($type,(int)($battle['_compoundResearch'][$type]??1),'ic'.$house['id'].'-'.$n,'defender',$x,$y);
        $unit['compoundId']=$house['id'];$unit['targetId']=$target['id'];$unit['_intercept']=$target['id'];
        $battle['units'][]=$unit;
        BattleEngine::event($battle,'deploy',['x'=>$x,'y'=>$y,'sourceId'=>$unit['id'],'targetId'=>$target['id'],'kind'=>$type,'team'=>'defender','compound'=>$house['id']]);
    }

    public static function destroyed(array &$battle,int $id):void
    {
        if(!isset($battle['_compounds'][$id]))return;
        $room=&$battle['_compounds'][$id];
        $room['lost']=$room['held'];$room['held']=[];
        foreach($battle['units'] as $i=>$resident){
            if(($resident['compoundId']??null)===$id && $resident['hp']>0){BattleEngine::hurtUnit($battle,$i,$resident['hp'],'compound',true);}
        }
    }

    /** The caller's existing single-settlement transaction removes actual fatalities only. */
    public static function settle(array &$battle,array &$realm):void
    {
        if(empty($battle['_compounds'])||isset($battle['result']['compoundLosses']))return;
        $lost=[];
        foreach($battle['_compounds'] as $room){
            foreach($room['lost'] as $type=>$count)$lost[$type]=($lost[$type]??0)+$count;
        }
        foreach($battle['units'] as $unit){
            if(isset($unit['compoundId']) && $unit['team']==='defender' && $unit['hp']<=0)$lost[$unit['type']]=($lost[$unit['type']]??0)+1;
        }
        foreach($lost as $type=>&$count){$count=min($count,(int)($realm['army'][$type]??0));$realm['army'][$type]-=$count;}unset($count);
        $battle['result']['compoundLosses']=$lost;
    }
}
