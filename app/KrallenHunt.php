<?php
declare(strict_types=1);

/** The independent G5 event guardian: shared UTC rounds, weekly loan, and separate health/raid reservation. */
final class KrallenHunt
{
    public const WEEK=604800;
    public const AWARD=250000;
    public const UPGRADE=750000;
    public const RETAINED_UPGRADE=500000;
    private const RESOURCES=['twig','pebble','putty','goo'];

    public static function weekEnd(int $now):int
    {
        // 1970-01-05 00:00 UTC is the first Monday after the Unix epoch.
        return 345600+(int)floor(($now-345600)/self::WEEK+1)*self::WEEK;
    }
    public static function eligible(array $base):bool
    {
        return Game::townhallLevel($base)>=5&&Game::livingLevel($base,'maproom')>=2;
    }
    public static function stats(int $level,int $power):array
    {
        $i=max(1,min(5,$level))-1;
        return ['hp'=>(int)round([50000,52000,54000,58000,62000][$i]*.15),'damage'=>(int)round([800,850,900,1000,1200][$i]*.12),
            'speed'=>[2.2,2.3,2.4,2.5,2.6][$i],'range'=>[35,45,55,60,65][$i]/80,'healtime'=>[180,360,720,1440,2880][$i],
            'lootBuff'=>$power>=2?[.20,.22,.24,.27,.30][$i]:0.0,'lootRadius'=>[250,275,300,325,350][$i]/80];
    }
    private static function blank(int $now):array
    {
        return ['ends'=>self::weekEnd($now),'loot'=>0,'wins'=>0,'power'=>0,'hp'=>0.0,'lastHeal'=>$now,'battle'=>0,'previous'=>null];
    }

    private static function close(array &$h,int $loot):void
    {
        $won=$loot>=self::AWARD;$kept=$h['wins']>=5;
        $oldHp=self::stats(max(1,$h['wins']),$h['power'])['hp'];
        $oldPower=$h['power'];
        if($won){
            $h['power']=$loot>=($kept?self::RETAINED_UPGRADE:self::UPGRADE)?2:1;
            $h['wins']=min(5,$h['wins']+1);
        }elseif($kept){$h['power']=1;}
        else{$h['wins']=0;$h['power']=0;}
        $max=self::stats(max(1,$h['wins']),$h['power'])['hp'];
        $h['hp']=$h['power']>0?($oldPower>0?min($max,$h['hp']+$max-$oldHp):$max):0.0;
    }

    private static function recover(array &$h,int $until,float $speed):void
    {
        if($h['power']>0&&empty($h['battle'])){
            $stats=self::stats($h['wins'],$h['power']);$elapsed=max(0,$until-$h['lastHeal']);
            $h['hp']=min($stats['hp'],$h['hp']+$elapsed*$speed*$stats['hp']/$stats['healtime']);
        }
        $h['lastHeal']=max($h['lastHeal'],$until);
    }
    public static function accrue(array &$base,int $now,float $speed=1):void
    {
        if(!isset($base['krallenHunt'])){return;}
        $h=&$base['krallenHunt'];$end=self::weekEnd($now);
        if($h['ends']<$end){
            self::recover($h,$h['ends'],$speed);
            $h['previous']=['loot'=>$h['loot'],'end'=>$h['ends']];self::close($h,$h['loot']);
            if($h['ends']+self::WEEK<$end){self::close($h,0);}
            $h['ends']=$end;$h['loot']=0;
        }
        self::recover($h,$now,$speed);
    }

    public static function credit(array &$base,array $loot,int $now,float $speed=1):?array
    {
        $amount=array_sum(array_map(static fn(string $type):int=>max(0,(int)($loot[$type]??0)),self::RESOURCES));
        if(!$amount){return null;}
        $base['krallenHunt']??=self::blank($now);self::accrue($base,$now,$speed);
        $h=&$base['krallenHunt'];$h['loot']+=$amount;
        return ['added'=>$amount,'loot'=>$h['loot'],'ends'=>$h['ends']];
    }
    public static function view(array $base,int $now,float $speed=1):array
    {
        self::accrue($base,$now,$speed);
        $h=$base['krallenHunt']??self::blank($now);$s=self::stats($h['wins'],$h['power']);
        return ['eligible'=>self::eligible($base),'power'=>$h['power'],'level'=>$h['wins'],'retained'=>$h['wins']>=5,
            'loot'=>$h['loot'],'ends'=>$h['ends'],'hp'=>$h['hp'],'max_hp'=>$h['power']?$s['hp']:0,'previous'=>$h['previous'],
            'award'=>self::AWARD,'upgrade'=>$h['wins']>=5?self::RETAINED_UPGRADE:self::UPGRADE,'battle'=>$h['battle']];
    }

    /** Complete target groups before moving to the next; distance breaks ties within each group. */
    public static function targetGroup(array $building,array $garrisons):int
    {
        if($building['type']==='storage'||in_array($building['type'],self::RESOURCES,true)){return 0;}
        if(($building['_damage']??0)>0&&empty($building['jarHp'])){return 1;}
        if($building['type']==='bunker'&&!empty($garrisons[(string)$building['id']])){return 2;}
        return in_array($building['type'],['wall','stonewall'],true)||!empty($building['jarHp'])?4:3;
    }

    public static function ready(array $base,int $now):?array
    {
        if(empty($base['krallenHunt']['power'])||!empty($base['krallenHunt']['battle'])||$base['krallenHunt']['hp']<=0||Game::livingLevel($base,'championcage')<1){return null;}
        $h=$base['krallenHunt'];$stats=self::stats($h['wins'],$h['power']);
        return ['type'=>'krallen','level'=>$h['wins'],'power'=>$h['power'],'hp'=>$h['hp'],'max_hp'=>$stats['hp'],
            'available'=>true,'deployed'=>false,'_stats'=>$stats];
    }

    public static function deploy(array &$s,string $team,float $x,float $y):void
    {
        $key=$team==='attacker'?'krallen':'defenderKrallen';
        $c=$s[$key]??null;
        if(!$c||!$c['available']||$c['deployed']){throw new RuntimeException('Krallen is unavailable for another launch.');}
        if($team==='attacker'&&!BattleEngine::legalDeployment($s,$x,$y)){throw new RuntimeException('Choose clear ground for Krallen.');}
        $st=$c['_stats'];$id=$team==='attacker'?'ka':'kd';
        $u=['id'=>$id,'type'=>'krallen','champion'=>true,'krallenPower'=>$c['power'],'team'=>$team,'level'=>$c['level'],'movement'=>'ground',
            'x'=>$x,'y'=>$y,'hp'=>$c['hp'],'max_hp'=>$c['max_hp'],'state'=>'walk','targetId'=>null,'_damage'=>$st['damage']*(1+($s['_worldBonuses'][$team]??0)),
            '_speed'=>$st['speed'],'_range'=>$st['range'],'_foundationPadding'=>0.2,'_interval'=>1.0,'_target'=>'krallen','_targetMode'=>'ground','_ability'=>'','_submerged'=>false,
            'lootRadius'=>$st['lootBuff']>0?$st['lootRadius']:0,'_initial_hp'=>$c['hp'],'_heal'=>0,'_splash'=>0,'_cooldown'=>0.0,'_repath'=>0.0,'_path'=>[],'_obstacleVersion'=>-1,'_bunkerId'=>null];
        $s['units'][]=$u;$s[$key]['_index']=array_key_last($s['units']);$s[$key]['available']=false;$s[$key]['deployed']=true;
        BattleEngine::event($s,'deploy',['x'=>$x,'y'=>$y,'sourceId'=>$id,'kind'=>'krallen','team'=>$team,'champion'=>true]);
    }
    public static function snapshot(array $s,string $key):?array
    {
        $c=$s[$key]??null;if(!$c){return null;}
        if(isset($c['_index'])){$c['hp']=$s['units'][$c['_index']]['hp'];}
        unset($c['_stats'],$c['_index']);if($s['status']!=='active'){$c['available']=false;}return $c;
    }
    public static function release(array &$base,array &$s,string $key,int $now):void
    {
        if(!isset($s[$key])||($base['krallenHunt']['battle']??0)!==$s['id']){return;}
        $c=self::snapshot($s,$key);$h=&$base['krallenHunt'];
        if($h['power']>0){$max=self::stats($h['wins'],$h['power'])['hp'];$h['hp']=min($max,max(0,$c['hp']/$c['max_hp']*$max));}
        $h['battle']=0;$h['lastHeal']=$now;$s['result'][$key]=$c;
    }

    /** Only credit actual damage to lootable foundations. The settlement can transfer only the rival's real balance. */
    public static function damage(array &$s,array $u,array $building,float $amount):void
    {
        if(!isset($s['krallen']['_index'])||$u['team']!=='attacker'||$amount<=0){return;}
        $type=$building['type'];if($type!=='storage'&&!in_array($type,self::RESOURCES,true)){return;}
        $unit=$s['units'][$s['krallen']['_index']];if($unit['hp']<=0){return;}
        $stats=$s['krallen']['_stats'];
        if($u['id']===$unit['id']){$bonus=$type==='storage'?2.0:1.0;}
        elseif($stats['lootBuff']>0&&hypot($unit['x']-$u['x'],$unit['y']-$u['y'])<=$stats['lootRadius']){$bonus=$stats['lootBuff'];}
        else{return;}
        $s['_krallenDamage'][$type]=($s['_krallenDamage'][$type]??0)+$amount*$bonus;
    }
    public static function multiplier(array $s,string $resource):float
    {
        $extra=($s['_krallenDamage'][$resource]??0)+($s['_krallenDamage']['storage']??0);
        if(!$extra){return 1.0;}
        $total=0.0;foreach($s['buildings'] as $b){if($b['type']===$resource||$b['type']==='storage'){$total+=max(0,(float)($b['_initial_hp']??$b['max_hp']));}}
        return 1+min(2,$extra/max(1,$total));
    }
}
