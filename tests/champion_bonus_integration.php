<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function verify(bool $condition,string $label):void{global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function reject(callable $work,string $label):void{try{$work();}catch(RuntimeException){verify(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
function addBuilding(array &$base,string $type):void{$hp=Game::buildingStats($type,1)['max_hp'];$base['buildings'][]=['id'=>$base['next_building_id']++,'type'=>$type,'level'=>1,'hp'=>$hp,'max_hp'=>$hp,'x'=>3,'y'=>28,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];}
function due(int $id,string $type,float $hp):array{$b=Game::loadBase($id);$b['champions'][$type]['next_feed_at']=time()-1;$b['champions'][$type]['last_hunger_at']=time()-1;$b['champions'][$type]['last_heal_at']=time();$b['champions'][$type]['hp']=$hp;Game::saveBase($id,$b);return $b;}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE')?:'backyard_fortification_test','username'=>getenv('MYSQL_TEST_USER')?:'backyard_test','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1,'player_grace_seconds'=>1800];
if(!str_ends_with($config['database'],'_fortification_test')){throw new RuntimeException('Use a disposable database ending in _fortification_test.');}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
foreach(explode(';',file_get_contents(__DIR__.'/../app/schema.sql')) as $sql){if(trim($sql)!==''){$pdo->exec($sql);}}
$pdo->beginTransaction();
try{
    $id=Game::register('Bonus '.bin2hex(random_bytes(4)),'bonus-test-password')['id'];$base=Game::loadBase($id);$base['buildings'][0]['level']=5;addBuilding($base,'championcage');addBuilding($base,'championchamber');Game::saveBase($id,$base);
    foreach(array_intersect_key(Catalog::champions(),array_flip(['gorgo','drull','fomor'])) as $type=>$spec){
        $base=Game::loadBase($id);$base['activeChampion']=null;Game::saveBase($id,$base);Game::mutate($id,'championRaise',['type'=>$type]);$base=Game::loadBase($id);
        verify($base['champions'][$type]['bonus_feeds']===0,'new champion has no earned bonus '.$type);
        $base['champions'][$type]['level']=6;$base['champions'][$type]['bonus_feeds']=0;$base['champions'][$type]['feeds']=0;$base['army']=array_fill_keys(array_keys(Catalog::monsters()),0);Game::saveBase($id,$base);
        for($tier=1;$tier<=3;$tier++){
            $oldStats=Catalog::championStats($type,6,$tier-1);$newStats=Catalog::championStats($type,6,$tier);$base=due($id,$type,1000);$recipe=Game::championRecipe($type,6,$tier-1);
            reject(fn()=>Game::mutate($id,'championFeed',[]),'bonus meal requires real reserve army '.$type.' '.$tier);
            foreach($recipe as $food=>$quantity){$base['army'][$food]=$quantity;}$base['garrison']=['999'=>array_map(fn($n)=>$n*10,$recipe)];Game::saveBase($id,$base);
            $fed=Game::mutate($id,'championFeed',[]);$champion=$fed['champions'][$type];
            verify($champion['bonus_feeds']===$tier&&$champion['level']===6&&$champion['feeds']===0,'meal earns exactly one bonus tier '.$type.' '.$tier);
            verify(array_sum($fed['army'])===0&&$fed['garrison']['999']===array_map(fn($n)=>$n*10,$recipe),'meal consumes only exact reserve units '.$type.' '.$tier);
            verify(abs($champion['hp']-(1000+$newStats['hp']-$oldStats['hp']))<20,'earned health adds only max-HP delta '.$type.' '.$tier);
            verify($champion['max_hp']===$newStats['hp']&&$champion['stats']===$newStats,'public mutation exposes authoritative bonus stats '.$type.' '.$tier);
            reject(fn()=>Game::mutate($id,'championFeed',[]),'digestion prevents duplicate bonus feed '.$type.' '.$tier);
        }
        $base=due($id,$type,1000);foreach(Game::championRecipe($type,6,3) as $food=>$quantity){$base['army'][$food]=$quantity;}Game::saveBase($id,$base);$maintenance=Game::mutate($id,'championFeed',[]);
        verify($maintenance['champions'][$type]['bonus_feeds']===3&&$maintenance['champions'][$type]['hp']<1020,'tier-three maintenance grants no free healing '.$type);
        $base=Game::loadBase($id);$base['champions'][$type]['hp']=Catalog::championStats($type,6,3)['hp'];$base['champions'][$type]['last_heal_at']=time();Game::saveBase($id,$base);$frozen=Game::mutate($id,'championFreeze',[]);
        verify($frozen['champions'][$type]['bonus_feeds']===3&&$frozen['activeChampion']===null,'freeze preserves bonus tier '.$type);
        $thawed=Game::mutate($id,'championThaw',['type'=>$type]);verify($thawed['champions'][$type]['bonus_feeds']===3&&$thawed['champions'][$type]['max_hp']===Catalog::championStats($type,6,3)['hp'],'thaw resumes earned stats '.$type);
        $base=Game::loadBase($id);$base['champions'][$type]['last_hunger_at']=time()-86400*2-1;$base['champions'][$type]['next_feed_at']=$base['champions'][$type]['last_hunger_at'];$base['champions'][$type]['last_heal_at']=time();Game::saveBase($id,$base);
        $snap=Game::snapshot($id);verify($snap['champions'][$type]['bonus_feeds']===1,'persisted hunger removes exact missed-day tiers '.$type);
        verify($snap['champions'][$type]['max_hp']===Catalog::championStats($type,6,1)['hp']&&$snap['champions']===$snap['base']['champions'],'bootstrap consistently reports effective stats '.$type);
        $same=Game::snapshot($id);verify($same['champions'][$type]['bonus_feeds']===1,'repeat snapshot does not repeat hunger '.$type);
        $scout=Game::visit($id,$id+99999);verify($scout['champions'][$type]['max_hp']===$same['champions'][$type]['max_hp'],'visiting displays correct champion maximum '.$type);
    }
    $base=due($id,'fomor',0);$base['champions']['fomor']['bonus_feeds']=0;foreach(Game::championRecipe('fomor',6,0) as $food=>$quantity){$base['army'][$food]=$quantity;}Game::saveBase($id,$base);
    $now=time();$base['champions']['fomor']['last_heal_at']=$now+10;Game::saveBase($id,$base);$fed=Game::mutate($id,'championFeed',[]);verify($fed['champions']['fomor']['hp']===0,'earned tier alone cannot revive zero HP');
    $pdo->rollBack();echo "Champion bonus integration: $checks assertions passed; temporary data rolled back.\n";
}catch(Throwable $error){if($pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,(string)$error."\n");exit(1);}
