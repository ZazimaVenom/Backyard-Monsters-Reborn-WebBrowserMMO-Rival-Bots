<?php
declare(strict_types=1);

/** An underground decision runs inside the same online turn as surface work. */
final class InfernoBots
{
    public static function economy(int $id,int $turn,int $seed):array
    {
        // New keepers establish a surface yard and an army before buying another settlement.
        if ($turn < 5) { return []; }
        return Db::transaction(static function(PDO $pdo) use($id,$turn,$seed):array {
            $home=Game::loadBase($id,true);
            if (!WorldActivity::clock()['online'] || !Game::isBot($id)) { return []; }
            $busy=$pdo->prepare("SELECT id FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?) LIMIT 1");
            $busy->execute([$id,$id]);if($busy->fetchColumn()){return [];}
            $now=time();$out=[];
            if (!isset($home['infernoRealm'])) {
                if (($turn+$id)%3!==0 || !InfernoRealm::enterBot($home,$now,$id)) { return []; }
                $out['inferno_opened']=1;
            }
            $realm=&$home['infernoRealm'];if(InfernoDefense::active($realm)){return [];}
            InfernoRealm::accrue($realm,$now);
            if(array_filter($realm['buildings'],static fn($b)=>$b['level']>0 && $b['hp']<$b['max_hp'])){
                InfernoRealm::botOrder($realm,'repair',[],$now);$out['inferno_repaired']=1;
            }
            InfernoRealm::botOrder($realm,'collect',[],$now);
            $building=self::construction($realm,$id);
            if($building){
                InfernoRealm::botOrder($realm,$building['action'],$building['input'],$now);
                $out[$building['action']==='build'?'inferno_built':'inferno_upgraded']=1;
            }
            self::incubate($realm,$id,$turn,$seed,$now,$out);
            Game::saveBase($id,$home);
            return $out;
        });
    }

    private static function construction(array $realm,int $id):?array
    {
        $counts=[];$byType=[];
        foreach($realm['buildings'] as $b){
            if($b['ready_at']>0){return null;}
            $counts[$b['type']]=($counts[$b['type']]??0)+1;
            if(!isset($byType[$b['type']])||$b['level']<$byType[$b['type']]['level']){$byType[$b['type']]=$b;}
        }
        $catalog=InfernoRealm::catalog();$hall=$byType['townhall']['level'];
        $profile=Bots::profile($id);$defender=$profile==='guardian';$farmer=$profile==='collector';
        $orders=[
            ['locker',1],[$defender?'sniper':'goo',2],['townhall',1,2],
            ['infernocannon',1],['academy',1],['housing',2],['locker',1,2],['flinger',1,2],
            [$farmer?'goo':'housing',3],['storage',1],['sniper',$defender?4:2],
            ['twig',2],['pebble',2],['putty',2],['goo',3],['hatchery',2],
            ['townhall',1,3],['quaketower',1],['locker',1,3],['academy',1,2],
            ['flinger',1,3],['housing',3],['storage',2],['infernocannon',2],
            ['townhall',1,4],['magmatower',1],['locker',1,4],['flinger',1,4],
            ['townhall',1,5],['storage',3],['flinger',1,5],['townhall',1,6],
            ['academy',1,5],['goo',4],['storage',4],['housing',5],['wall',$defender?16:8],
        ];
        foreach($orders as $order){
            [$type,$count]=$order;$level=$order[2]??1;$spec=$catalog[$type]??null;
            if(!$spec){continue;}
            $new=($counts[$type]??0)<min($count,$spec['limit']);
            if(!$new&&($byType[$type]['level']??0)>=$level){continue;}
            $target=$new?1:($byType[$type]['level']+1);$tier=$spec['tiers'][$target]??null;
            if(!$tier||$tier['gate']>$hall||($new&&count($realm['buildings'])>=70)){continue;}
            foreach($tier['cost'] as $currency=>$amount){if($realm['resources'][$currency]<$amount)continue 2;}
            if(!$new){return ['action'=>'upgrade','input'=>['id'=>$byType[$type]['id']]];}
            $pos=self::position($realm,$type,$id,$counts[$type]??0,$catalog);
            if($pos){return ['action'=>'build','input'=>['type'=>$type]+$pos];}
        }
        return null;
    }

    /** Deterministic rings, rotated per identity and building family, keep defenses near the Hall. */
    private static function position(array $realm,string $type,int $id,int $count,array $catalog):?array
    {
        $seed=crc32($id.'/'.$type.'/'.$count);$size=$catalog[$type]['size'];
        $defense=in_array($type,['sniper','infernocannon','quaketower','magmatower','wall'],true);
        $radii=$defense?[4,6.5,9,11.5,13]:[9,11.5,7,13,4.5];
        if($margin=YardSpace::margin($realm))$radii[]=13+$margin;
        foreach($radii as $ring=>$radius){
            for($i=0;$i<24;++$i){
                $angle=deg2rad($seed%360+($i+$ring*.38)*15);
                $x=round((16+$radius*cos($angle))*2)/2;$y=round((16+$radius*sin($angle))*2)/2;
                if(!YardSpace::contains($realm,$x,$y,1+$size/2)){continue;}
                foreach($realm['buildings'] as $other){
                    $gap=($size+$catalog[$other['type']]['size'])/2+.15;
                    if(abs($x-$other['x'])<$gap-1e-5 && abs($y-$other['y'])<$gap-1e-5)continue 2;
                }
                return ['x'=>$x,'y'=>$y];
            }
        }
        return null;
    }

    private static function incubate(array &$realm,int $id,int $turn,int $seed,int $now,array &$out):void
    {
        $view=InfernoMonsters::view($realm,$now,Game::speed());$options=$view['options'];
        $species=array_keys($options);$offset=($id+intdiv($turn,3))%count($species);
        $species=array_merge(array_slice($species,$offset),array_slice($species,0,$offset));
        foreach(['unlock'=>'infernoUnlock','research'=>'infernoResearch','train'=>'infernoTrain'] as $lane=>$op){
            if(($lane==='research'&&$turn%3!==$id%3)||($lane==='train'&&count($realm['queue'])>=8)){continue;}
            // The troop order uses the post-discovery/study wallet and current compound space.
            $view=InfernoMonsters::view($realm,$now,Game::speed());
            foreach($species as $type){
                $spec=$view['options'][$type];$option=$spec[$lane];
                if($option['reason']){continue;}
                $quantity=1;
                if($lane==='train'){
                    $quantity=min(2+$seed%3,8-count($realm['queue']),(int)floor(($view['capacity']-$view['used'])/max(1,$spec['size'])),intdiv((int)$realm['resources']['magma'],max(1,$option['cost']['magma'])));
                    if($quantity<=0){continue;}
                }
                InfernoRealm::botOrder($realm,$op,['type'=>$type,'quantity'=>$quantity,'monsterRevision'=>$realm['monsterRevision']],$now);
                $out[match($lane){'train'=>'inferno_trained','unlock'=>'inferno_unlocks',default=>'inferno_researched'}]=$quantity;
                break;
            }
        }
    }

    public static function raid(int $id,int $turn,int $seed,array $settings,array $busy):?int
    {
        if($turn<7||($turn+$id)%4!==0){return null;}
        $base=Game::loadBase($id);$realm=$base['infernoRealm']??null;
        if(!$realm||array_sum($realm['army'])<4||InfernoDefense::active($realm)||Game::livingLevel($realm,'flinger')<1){return null;}
        $humans=(($seed>>6)%100)<$settings['human_target_percent'];
        $target=InfernoRivals::botTarget($id,$seed,$busy,$humans);
        if(!$target){return null;}
        try{BattleService::startInfernoRival($id,$target,[]);return $target;}
        catch(PDOException $e){throw $e;}
        catch(RuntimeException|InvalidArgumentException $e){return null;} // Shields, truces and competing claims are rechecked under locks.
    }
}
