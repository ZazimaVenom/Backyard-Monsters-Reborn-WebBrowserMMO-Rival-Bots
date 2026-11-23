<?php
declare(strict_types=1);

/** Surface building 14. Late tiers reuse the archived economy curves in local units. */
final class TownHall
{
    public const MAX_LEVEL=10;
    private const LEGACY_LIMITS=[
        'townhall'=>1,'academy'=>1,'hatchery'=>3,'housing'=>4,'storage'=>4,'twig'=>3,'pebble'=>3,'putty'=>3,'goo'=>3,
        'sniper'=>8,'cannon'=>6,'tesla'=>4,'wall'=>60,'flinger'=>1,'locker'=>1,'juicer'=>1,'laser'=>4,'bunker'=>3,'trap'=>12,
        'championcage'=>1,'championchamber'=>1,'lab'=>1,'catapult'=>1,'maproom'=>1,'planner'=>1,
    ];
    // Source quantities indexed by completed Town Hall, from levels six through ten.
    private const UPPER_LIMITS=[
        'twig'=>[6,6,6,6,6],'pebble'=>[6,6,6,6,6],'putty'=>[6,6,6,6,6],'goo'=>[6,6,6,6,6],
        'storage'=>[5,5,5,6,6],'hatchery'=>[5,5,5,5,5],'housing'=>[3,3,4,4,4],'academy'=>[2,2,2,2,2],
        'bunker'=>[2,3,4,4,4],'sniper'=>[6,6,6,6,6],'laser'=>[3,3,3,3,3],'tesla'=>[3,3,3,3,3],
        'wall'=>[220,280,300,340,400],'stonewall'=>[70,90,90,90,90],'trap'=>[35,42,50,60,75],
        'heavytrap'=>[8,10,12,15,18],'railgun'=>[3,3,3,3,3],
    ];

    public static function apply(array &$catalog):void
    {
        $hall=&$catalog['townhall'];
        $hall['maxLevel']=self::MAX_LEVEL;
        $previousHp=(int)round($hall['hp']*pow(1.35,4));
        foreach([
            6=>[7560000,345600,200000],7=>[11340000,518400,300000],8=>[14420000,691200,400000],
            9=>[18680000,1036800,500000],10=>[25000000,1209600,600000],
        ] as $tier=>[$resource,$seconds,$hp]){
            $material=intdiv($resource,100);
            $hall['levels'][$tier]=['townHall'=>$tier-1,'cost'=>['twig'=>$material,'pebble'=>$material,'putty'=>0,'goo'=>0],
                'buildTime'=>(int)ceil($seconds/300),'hp'=>(int)round($previousHp*$hp/94000)];
        }
        unset($hall);
        foreach($catalog as $type=>&$building){
            if(!empty($building['siteOnly'])||!empty($building['brukkargReward']))continue;
            $limits=$building['limitByTownHall']??array_fill(0,6,self::LEGACY_LIMITS[$type]??10);
            $previous=$limits[5]??end($limits);
            for($level=6;$level<=self::MAX_LEVEL;++$level){
                $previous=$limits[$level]=max($previous,self::UPPER_LIMITS[$type][$level-6]??$previous);
            }
            $building['limitByTownHall']=$limits;
        }unset($building);
    }

    public static function level(array $base):int
    {
        foreach($base['buildings']??[] as $building){
            if($building['type']==='townhall')return max(1,(int)$building['level']);
        }
        return 1;
    }

    public static function quantity(array $building,int $level):int
    {
        $limits=$building['limitByTownHall']??[];
        return $limits ? (int)($limits[min(self::MAX_LEVEL,max(0,$level))]??end($limits)) : 10;
    }

    public static function buildingLimit(int $level):int
    {
        if($level<=5)return 100;
        $total=0;
        foreach(Catalog::buildings() as $spec){
            if(empty($spec['siteOnly']) || !empty($spec['emergenceReward']))$total+=self::quantity($spec,$level);
        }
        return $total;
    }
}
