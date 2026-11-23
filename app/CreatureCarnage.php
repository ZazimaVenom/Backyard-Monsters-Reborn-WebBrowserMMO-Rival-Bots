<?php
declare(strict_types=1);

/** Event 1. Each keeper owns a six-day crawl through the ten original yards. */
final class CreatureCarnage
{
    public const FIRST=906;
    public const LAST=915;
    private const RUN=518400;
    private const WEEK=604800;

    public static function contains(int $stage):bool {return $stage>=self::FIRST && $stage<=self::LAST;}

    private static function layouts():array
    {
        static $yards;
        return $yards??=array_column(json_decode(file_get_contents(__DIR__.'/carnage-layouts.json'),true,512,JSON_THROW_ON_ERROR),null,'sourceId');
    }

    public static function discoveryBlocked(array $base,int $now):bool
    {
        $event=$base['creatureCarnage']??[];
        return !empty($event['started']) && empty($event['completedAt']) && $now<$event['started']+self::RUN;
    }

    private static function troopRequirement(array $base):string
    {
        foreach(['maproom'=>'Map Room','flinger'=>'Flinger','housing'=>'Monster Housing'] as $type=>$label){
            if(Game::livingLevel($base,$type)<1)return 'Build or repair a '.$label.' before invading.';
        }
        return array_sum($base['army']??[])<1?'Hatch monsters before invading.':'';
    }

    public static function route(array $base,int $now):array
    {
        $event=$base['creatureCarnage']??[];
        $earned=!EventCalendar::enrolled($base,'carnage')&&in_array('vorg',$base['unlocked']??[],true);
        $paid=!EventCalendar::enrolled($base,'carnage')&&in_array('vorg',array_column($base['unlockQueue']??[],'type'),true);
        $hall=Game::townhallLevel($base);
        $begin=(int)($event['started']??0);
        $running=$begin>0 && $now<$begin+self::RUN;
        $waiting=$begin>0 && !$running && $now<$begin+self::WEEK;
        $status=isset($event['completedAt'])?'complete':($earned?'owned':($paid?'discovering':($running?'active':($hall>=5?'locker':($hall<2?'locked':($waiting?'waiting':'available'))))));
        $active=$status==='active';
        $current=$active||$status==='complete'||$status==='waiting';
        $records=$current?($event['cleared']??[]):[];
        $score=count($records);$canPlay=$active||$status==='available';
        $run=(int)($event['run']??0)+($status==='available'?1:0);
        $facility=self::troopRequirement($base);
        $cards=[];
        foreach(self::layouts() as $stage=>$row){
            $i=$stage-self::FIRST+1;
            $cards[]=['stage'=>$stage,'name'=>'Carnage yard '.$i,'tier'=>'Creature Carnage','tribe'=>'Creature Carnage','event'=>'carnage','run'=>$run,
                'open'=>$canPlay && $i===$score+1,'record'=>$records[$stage]??null,'supplied'=>isset($event['supplied'][$stage]),'reward'=>$row['reward'],'requirement'=>$facility];
        }
        return ['eventId'=>1,'score'=>$score,'status'=>$status,'run'=>$run,'endsAt'=>$begin?$begin+self::RUN:null,'nextAt'=>$waiting?$begin+self::WEEK:null,
            'total'=>10,'requirement'=>$facility,'camps'=>$cards];
    }

    public static function camp(array $base,int $stage,int $now):array
    {
        $view=self::route($base,$now);
        $card=$view['camps'][$stage-self::FIRST]??null;
        if(!$card||!$card['open'])throw new RuntimeException('Choose the next open Creature Carnage yard from the campaign route.');
        $row=self::layouts()[$stage];$yard=Tribes::yard($row);
        $damage=$base['creatureCarnage']['damage']??null;
        if($view['status']==='active'&&$damage&&$damage['stage']===$stage&&$now<$damage['firstAt']+86400){
            foreach($yard['buildings'] as &$building){
                if(isset($damage['hp'][$building['id']]))$building['hp']=min($building['max_hp'],max(0,$damage['hp'][$building['id']]));
            }
            unset($building);$card['damageUntil']=$damage['firstAt']+86400;
        }
        return $card+$row+['eventYard'=>$yard];
    }

    /** The real battle transaction holds the home mutex. Inspections never start the six-day clock. */
    public static function begin(array &$base,int $stage,mixed $expectedRun,int $now):array
    {
        EventCalendar::checkStart($base,'carnage',$now);
        $camp=self::camp($base,$stage,$now);
        if($expectedRun!==$camp['run'])throw new RuntimeException('This Creature Carnage run changed. Scout the yard again.');
        if($camp['requirement']!=='')throw new RuntimeException($camp['requirement']);
        $event=&$base['creatureCarnage'];
        if(($event['run']??null)!==$expectedRun){
            $event=['run'=>$expectedRun,'started'=>$now,'cleared'=>[],'supplied'=>$event['supplied']??[]];
        }
        $camp['endsAt']=$event['started']+self::RUN;
        return $camp;
    }

    /** Score only a destroyed Town Hall in the right saved run. Damage has one 24-hour window. */
    public static function settle(array &$base,array &$result,array $target,array $buildings,int $started,int $finished):void
    {
        $stage=(int)$target['stage'];$event=&$base['creatureCarnage'];
        $valid=isset($event['started'],$event['run'])&&$event['run']===$target['run']&&$started>=$event['started']&&$finished<=$event['started']+self::RUN
            && $stage===self::FIRST+count($event['cleared']) && empty($event['completedAt']);
        $won=$valid&&!empty($result['hallDestroyed']);
        $result['attackerWon']=$won;$result['rankDelta']=['attacker'=>0,'defender'=>0];$result['target']=$target;
        if(!$won)$result['winnerId']=null;
        $result['campaign']=['event'=>'carnage','firstClear'=>$won,'score'=>count($event['cleared']??[]),'expired'=>!$valid,'nextStage'=>null,'pending'=>[],'vorg'=>false];
        if(!$valid)return;
        if(!$won){
            $damage=$event['damage']??null;
            $first=$damage && $damage['stage']===$stage && $started<$damage['firstAt']+86400?$damage['firstAt']:$started;
            $hp=[];foreach($buildings as $b)if($b['hp']<$b['max_hp'])$hp[(int)$b['id']]=max(0,(float)$b['hp']);
            if($hp&&$finished<$first+86400)$event['damage']=['stage'=>$stage,'firstAt'=>$first,'hp'=>$hp];
            else unset($event['damage']);
            return;
        }
        unset($event['damage']);
        $event['cleared'][$stage]=['firstAt'=>$finished,'best'=>$result['destruction']];
        $result['campaign']['score']=count($event['cleared']);
        $result['campaign']['nextStage']=$stage<self::LAST?$stage+1:null;
        if(!isset($event['supplied'][$stage])){
            $progress=Tribes::progress($base);
            foreach($target['reward'] as $resource=>$amount)$progress['pending'][$resource]=($progress['pending'][$resource]??0)+$amount;
            $base['tribeCampaign']=$progress;$event['supplied'][$stage]=true;
            $result['loot']=Tribes::transferSupplies($base);
            $result['campaign']['pending']=$base['tribeCampaign']['pending'];
        }
        if($stage===self::LAST){
            $event['completedAt']=$finished;
            if(!in_array('vorg',$base['unlocked'],true))$base['unlocked'][]='vorg';
            $result['campaign']['vorg']=true;
        }
    }
}
