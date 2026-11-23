<?php
declare(strict_types=1);

final class MonsterMadness
{
    public const GOALS=[42000000,94500000,160125000];
    public const WINDOW=345600;

    /** Convert the local five outpost tiers to the source takeover/empire-value curve. */
    public static function value(int $level):int
    {
        $equivalentLevel=28+4*(max(1,min(5,$level))-1);
        $takeover=max(1000000,round(($equivalentLevel*562500-14750000)/250000)*250000);
        return (int)round(exp(($takeover+227080916.9)/15820570.7));
    }

    public static function power(array $base):int
    {
        return min(3,max(0,(int)($base['monsterMadness']['power']??0),(int)($base['hellRaisersKorath']??0)));
    }

    public static function view(array $base,int $now):array
    {
        $m=$base['monsterMadness']??[];$power=max(0,min(3,(int)($m['power']??0)));
        $end=(int)($m['ends']??0);$running=$end>$now;
        return ['power'=>self::power($base),'score'=>$power===3?self::GOALS[2]:($running?(int)$m['score']:0),'goals'=>self::GOALS,'ends'=>$running?$end:0,
            'sites'=>$running?count($m['sites']):0,'best'=>(int)($m['best']??0)];
    }

    /** Called exactly at verified resource-site ownership transfer, under the same site and base locks. */
    public static function capture(array &$base,int $site,int $value,int $now):?array
    {
        $m=$base['monsterMadness']??['power'=>0,'best'=>0,'ends'=>0];
        if($site<1||$value<=0||$m['power']>=3){return null;}
        if($m['ends']<=$now){$m['ends']=$now+self::WINDOW;$m['score']=0;$m['sites']=[];}
        if(isset($m['sites'][$site])){return null;}
        $old=$m['score'];$power=$m['power'];$m['sites'][$site]=true;
        $m['score']=min(self::GOALS[2],$old+$value);
        $m['best']=max($m['best'],$m['score']);
        $m['power']=max($power,count(array_filter(self::GOALS,fn(int $goal):bool=>$m['score']>=$goal)));
        $base['monsterMadness']=$m;
        return ['added'=>$m['score']-$old,'score'=>$m['score'],'power'=>$m['power'],'earned'=>$power<$m['power']];
    }

    public static function champion(array $common):array
    {
        $levels=[];$bonuses=[];
        foreach([28000,62000,96000,120000,144000,175000] as $i=>$nativeHealth){
            $levels[$i+1]=['hp'=>(int)round($nativeHealth*0.15),'damage'=>(int)round([2000,2400,3000,3800,5000,6500][$i]*0.12),
                'speed'=>[1.4,1.6,1.8,2,2.3,2.5][$i],'range'=>[35,45,55,60,65,65][$i]/80,'attackInterval'=>($i<2?72:80)/40,
                'movement'=>'ground','target'=>'any','targetMode'=>'ground','power'=>1,'auraRadius'=>0.0,'auraBuff'=>0.0];
        }
        foreach([1,2,3] as $tier){
            $i=$tier-1;$bonuses[$tier]=['hp'=>(int)round([1000,2200,4000][$i]*0.15),'damage'=>(int)round([300,600,1000][$i]*0.12),
                'speed'=>[0.1,0.2,0.4][$i],'range'=>0.0,'auraBuff'=>0.0];
        }
        return $common+['name'=>'Korath','description'=>'A ground champion whose burning claws, anti-air fireballs and seismic stomp grow stronger through Monster Madness.',
            'art'=>'korath.png','levels'=>$levels,'maxBonusFeeds'=>3,'bonuses'=>$bonuses,
            'feedRecipes'=>[1=>['spurtz'=>20,'zagnoid'=>10],2=>['zagnoid'=>10,'sabnox'=>2],3=>['sabnox'=>6],4=>['sabnox'=>10],5=>['kingwormzer'=>3],6=>['kingwormzer'=>3]],
            'bonusRecipes'=>array_fill(1,3,['kingwormzer'=>3])];
    }

    public static function freeze(array &$stats,array $base):void
    {
        $stats['power']=max(1,self::power($base));
        $stats['targetMode']=$stats['level']>=4&&$stats['power']>=2?'both':'ground';
    }
}
