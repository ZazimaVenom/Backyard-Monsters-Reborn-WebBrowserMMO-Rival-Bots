<?php
declare(strict_types=1);

final class MonsterExpansion
{
    private const ROLES = [
        'vorg'=>['air','ally','An airborne medic that restores injured allies from a distance.'],
        'slimeattikus'=>['ground','any','A fragile, hard-hitting raider. When defeated, it splits into two to five fighting slimes.'],
        'slimeattikusmini'=>['ground','any','A smaller slime that fights wherever its parent falls.'],
        'rezghul'=>['ground','defense','A ranged necromancer that raises fallen allies to fight one more time.'],
        'spurtz'=>['ground','any','A magma-born pack attacker that charges the nearest structure and leaves scorched ground when defeated.'],
        'zagnoid'=>['ground','defense','A fast, tough tower hunter that deals double damage to defenses.'],
        'malphus'=>['jump','resource','A quick raider that leaps over walls to reach harvesters and resource pods.'],
        'valgos'=>['burrow','wall','Burrows beneath the yard and deals double damage to walls.'],
        'balthazar'=>['air','hunt','A low-flying hunter that seeks defending monsters and occupied bunkers. Its talons deal triple damage to monsters.'],
        'grokus'=>['ground','resource','A heavily armored resource hunter that presses through fire to reach producers and pods.'],
        'sabnox'=>['ground','defense','Spits magma from range. Its shots deal double damage to towers.'],
        'kingwormzer'=>['burrow','any','An armored burrower that sends a shock wave through nearby buildings when it attacks a new target.'],
    ];
    public const INFERNO_LOCKERS = ['spurtz'=>1,'zagnoid'=>1,'malphus'=>2,'valgos'=>2,'balthazar'=>3,'grokus'=>3,'sabnox'=>3,'kingwormzer'=>4];

    private static function sources(): array
    {
        static $rows;
        return $rows ??= require __DIR__.'/monster-tiers.php';
    }

    public static function catalog(): array
    {
        static $result;
        if ($result!==null) { return $result; }
        $result=[];
        foreach(self::sources() as $slug=>$row){
            [$movement,$target,$description]=self::ROLES[$slug];
            $origin=isset(self::INFERNO_LOCKERS[$slug])?'inferno':($slug==='slimeattikusmini'?'summon':'surface');
            $levels=[];
            for($tier=1;$tier<=6;++$tier){
                $at=static fn(string $field)=>$row[$field][min($tier,count($row[$field]))-1];
                $levels[$tier]=['hp'=>$at('health'),'damage'=>max(0,$at('damage')),'heal'=>max(0,-$at('damage')),
                    'speed'=>$at('speed'),'range'=>isset($row['range'])?$at('range')/80:0.75,
                    'cost'=>(int)ceil($at('cResource')/($origin==='inferno'?12:16)),
                    'trainTime'=>max(3,(int)ceil($at('cTime')/4))];
                if ($tier<6) {
                    [$amount,$seconds]=$row['trainingCosts'][$tier-1];
                    $levels[$tier]['nextStudy']=['cost'=>$origin==='inferno'?['magma'=>(int)ceil($amount/200)]:['goo'=>(int)ceil($amount/400),'putty'=>300*$tier],
                        'seconds'=>max(5,(int)ceil($seconds/600))];
                }
                foreach(['splits','zombieSpeedMultiplier','zombieHealthMultiplier','zombieDamageMultiplier','resurrectCooldown'] as $prop){
                    if(isset($row[$prop])){$levels[$tier][$prop]=$at($prop);}
                }
            }
            $locker=self::INFERNO_LOCKERS[$slug]??(['vorg'=>2,'slimeattikus'=>3,'rezghul'=>3][$slug]??0);
            $hall=['vorg'=>2,'slimeattikus'=>2,'rezghul'=>5][$slug]??1;
            $result[$slug]=['originalId'=>$row['id'],'origin'=>$origin,'name'=>$row['name'],'description'=>$description,'art'=>$slug.'.png',
                'ability'=>$slug==='vorg'?'heal':null,'movement'=>$movement,'target'=>$target,'attackInterval'=>$slug==='vorg'?0.5:1.0,
                'size'=>$row['cStorage'][0],'lockerLevel'=>$locker,'unlockLevel'=>$hall,'unlockHall'=>in_array($slug,['vorg','slimeattikus'],true)?5:$hall,
                'unlockCost'=>$origin==='inferno'?['magma'=>(int)ceil($row['unlockCost']/200)]:['goo'=>(int)ceil($row['unlockCost']/64),'putty'=>$locker*750],
                'unlockTime'=>max(6,(int)ceil($row['unlockTime']/600)), 'levels'=>$levels]+$levels[1];
        }
        return $result;
    }

    /** Only the new units receive extra snapshot fields. Previously persisted units retain their combat values. */
    public static function freeze(array $unit,array $spec): array
    {
        $level=min(6,max(1,(int)$unit['level']));
        $stats=$spec['levels'][$level];
        $unit['hp']=$unit['max_hp']=(float)$stats['hp'];
        foreach(['damage','speed','range','heal'] as $field){$unit['_'.$field]=(float)$stats[$field];}
        switch($unit['type']){
            case 'vorg': $unit['_antiHeal']=true; break;
            case 'slimeattikus': $unit['_splits']=$stats['splits']; break;
            case 'slimeattikusmini': $unit['summoned']=true; break;
            case 'rezghul':
                $unit['_necromancy']=['cooldown'=>$stats['resurrectCooldown'],'speed'=>$stats['zombieSpeedMultiplier'],
                    'hp'=>$stats['zombieHealthMultiplier'],'damage'=>$stats['zombieDamageMultiplier']];
                break;
            case 'spurtz': $unit['_scorch']=true; break;
            case 'balthazar': $unit['_lowFlight']=true; $unit['_hunt']=true; break;
            case 'valgos': $unit['_wallDamage']=2; break;
            case 'zagnoid': case 'sabnox': $unit['_towerDamage']=2; break;
            case 'kingwormzer': $unit['_kingShock']=true; break;
        }
        if($unit['type']==='sabnox'){$unit['_projectile']='magma';$unit['_targetMode']='both';}
        return $unit;
    }

    private static function spawn(array &$state,array $unit,array $source,string $effect):void
    {
        $unit['summoned']=true;$state['units'][]=$unit;
        $state['_summons']=(int)($state['_summons']??0)+1;
        BattleEngine::event($state,$effect,['x'=>$source['x'],'y'=>$source['y'],'tx'=>$unit['x'],'ty'=>$unit['y'],
            'sourceId'=>$source['id'],'targetId'=>$unit['id'],'kind'=>$unit['type'],'team'=>$unit['team']]);
    }

    /** Never debit or credit inventory for temporary creatures. A bounded population also limits saved battle size. */
    public static function split(array &$state,array $parent):void
    {
        for($i=0;$i<$parent['_splits']&&($state['_summons']??0)<200;++$i){
            $angle=($i+1)*2.399963;
            $margin=YardSpace::margin($state);
            $x=max(0.4-$margin,min(31.6+$margin,$parent['x']+cos($angle)*0.45));
            $y=max(0.4-$margin,min(31.6+$margin,$parent['y']+sin($angle)*0.45));
            $unit=BattleService::makeUnit('slimeattikusmini',$parent['level'],'s'.(1+($state['_summons']??0)),$parent['team'],$x,$y);
            self::spawn($state,$unit,$parent,'split');
        }
    }

    public static function resurrect(array &$state,int $index):void
    {
        $caster=$state['units'][$index];$now=$state['elapsed'];$spell=$caster['_necromancy'];
        if(($caster['_rescan']??0)>$now||($state['_summons']??0)>=200){return;}
        $state['units'][$index]['_rescan']=$now+0.75;
        $bodies=[];$nearest=null;$distance=INF;
        foreach($state['units'] as $i=>$body){
            if($body['hp']>0||$body['team']!==$caster['team']||$body['movement']==='air'||!empty($body['champion'])||!empty($body['summoned'])||!empty($body['_raised'])||
                in_array($body['type'],['vorg','zafreeti','rezghul','slimeattikusmini'],true)){continue;}
            $bodies[$i]=$body;
            $d=hypot($body['x']-$caster['x'],$body['y']-$caster['y']);
            if($d<=3.75&&$d<$distance){$nearest=$body;$distance=$d;}
        }
        if($nearest===null){return;}
        $state['units'][$index]['_rescan']=$now+$spell['cooldown'];
        foreach($bodies as $i=>$body){
            if(($state['_summons']??0)>=200||hypot($body['x']-$nearest['x'],$body['y']-$nearest['y'])>0.625){continue;}
            $state['units'][$i]['_raised']=true;
            $unit=BattleService::makeUnit($body['type'],$body['level'],'z'.(1+($state['_summons']??0)),$body['team'],$body['x'],$body['y'],null,(int)($body['labLevel']??0));
            $unit['hp']=$unit['max_hp']=$body['max_hp']*$spell['hp'];$unit['_speed']=$body['_speed']*$spell['speed'];
            $unit['_interval']=$body['_interval']/$spell['speed'];$unit['_damage']=$body['_damage']*$spell['damage'];
            $unit['zombie']=true;self::spawn($state,$unit,$caster,'resurrect');
        }
    }
}
