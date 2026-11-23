<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$checks=0;
function verify(bool $ok,string $label):void{global $checks;if(!$ok){throw new RuntimeException('FAIL: '.$label);}++$checks;}
function reject(callable $work,string $label):void{try{$work();}catch(RuntimeException){verify(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
function rawState(int $id):string{$q=Db::pdo()->prepare('SELECT state_json FROM bases WHERE user_id=?');$q->execute([$id]);return (string)$q->fetchColumn();}
function at(array $base,int $id,float $x,float $y):array{$nodes=Planner::positions($base);foreach($nodes as &$node){if($node['id']===$id){$node['x']=$x;$node['y']=$y;}}unset($node);return $nodes;}
function bld(int $id,string $type,float $x,float $y,int $level=1):array{$hp=Game::buildingStats($type,$level)['max_hp'];return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0,'fortification'=>0,'fortify_ready_at'=>0,'fortify_to'=>null,'fortify_paid'=>null];}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE')?:'backyard_planner_test','username'=>getenv('MYSQL_TEST_USER')?:'backyard_test','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1,'player_grace_seconds'=>1800];
if(!preg_match('/_test$/',$config['database'])){throw new RuntimeException('Use a disposable database ending in _test.');}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$pdo->beginTransaction();
try{
    $id=Game::register('Planner '.bin2hex(random_bytes(4)),'planner-test-password')['id'];
    $base=Game::loadBase($id);$owner=Game::publicBase($id,$base);
    verify(!$owner['planner']['available']&&$owner['planner']['slots']===[],'new yard receives no free Planner or layout');
    reject(fn()=>Game::mutate($id,'build',['type'=>'planner','x'=>4,'y'=>4]),'Planner construction requires Town Hall three');
    reject(fn()=>Game::mutate($id,'plannerApply',['positions'=>Planner::positions($base),'revision'=>Planner::revision($base)]),'template action requires completed living Planner');
    $base['buildings'][0]['level']=3;$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],20000);Game::saveBase($id,$base);
    $before=Game::loadBase($id);$result=Game::mutate($id,'build',['type'=>'planner','x'=>4,'y'=>4]);$planner=end($result['base']['buildings']);$plannerId=$planner['id'];
    verify($result['base']['resources']['twig']===$before['resources']['twig']-2500&&$result['base']['resources']['pebble']===$before['resources']['pebble']-2500,'real Planner construction charges both resource costs');
    verify($planner['level']===0&&$planner['hp']==0&&$planner['ready_at']>=time()+143,'Planner construction uses paid timer without early availability');
    verify(!$result['base']['planner']['available']&&$result['base']['construction']['busy']===1,'Planner is normal worker construction');
    reject(fn()=>Game::mutate($id,'build',['type'=>'planner','x'=>28,'y'=>4]),'only one Planner per yard');
    $base=Game::loadBase($id);$base['buildings'][count($base['buildings'])-1]['ready_at']=time()-1;Game::saveBase($id,$base);
    $result=Game::snapshot($id);verify($result['base']['planner']['available']&&$result['base']['construction']['busy']===0,'completed Planner becomes usable and frees worker');
    reject(fn()=>Game::mutate($id,'upgrade',['id'=>$plannerId]),'Planner has one source level');

    // Deterministic geometry fixture with damaged armor, actual garrison and a legacy paid job.
    $base=Game::loadBase($id);$base['buildings']=[bld(1,'townhall',16,16,3),bld(2,'planner',5,5),bld(3,'sniper',8,18),bld(4,'sniper',24,18),bld(5,'bunker',25,25),bld(6,'trap',4,25),bld(7,'flinger',15,5)];
    $base['next_building_id']=8;$base['buildings'][2]['hp']-=123;$base['buildings'][2]['fortification']=3;
    $base['buildings'][3]['fortify_ready_at']=time()+3600;$base['buildings'][3]['fortify_to']=2;$base['buildings'][3]['fortify_paid']=Catalog::fortificationStats('sniper',2)['cost'];
    $base['garrison']=[5=>['pokey'=>2]];$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],30000);$base['shield_until']=0;$base['last_tick']=time();Game::saveBase($id,$base);
    $positions=at($base,3,9,18);$revision=Planner::revision($base);$before=Game::loadBase($id);
    $saved=Game::mutate($id,'plannerSave',['slot'=>1,'name'=>'  Guard line 🐲  ','positions'=>$positions,'revision'=>$revision,'resources'=>['twig'=>999999]]);
    verify($saved['base']['planner']['slots'][0]['name']==='Guard line 🐲'&&$saved['base']['planner']['slots'][0]['slot']===1,'real save returns a named owner template');
    verify(Game::loadBase($id)['buildings']===$before['buildings'],'save persists a draft without moving any real building');
    verify(Game::loadBase($id)['resources']===$before['resources'],'save does not trust forged top-level resource data');
    $raw=json_decode(rawState($id),true);verify(array_keys($raw['planner']['slots'][1])===['name','positions','savedAt'],'database template stores geometry only');
    $scout=Game::visit($id,$id+999999);
    verify(!array_key_exists('planner',$scout)&&!array_key_exists('construction',$scout),'rival scouting hides templates and worker controls');
    verify(!in_array('trap',array_column($scout['buildings'],'type'),true),'Planner does not expose hidden defensive trap positions');
    $owner=Game::visit($id,$id);verify(isset($owner['planner'],$owner['construction']),'owner visit retains private controls');
    $applied=Game::mutate($id,'plannerApply',['positions'=>$positions,'revision'=>$revision]);$after=Game::loadBase($id);
    verify((float)$after['buildings'][2]['x']===9.0,'real apply changes selected building coordinate');
    foreach($before['buildings'] as $i=>$building){$expected=$building;$actual=$after['buildings'][$i];unset($expected['x'],$expected['y'],$actual['x'],$actual['y']);verify($expected===$actual,'atomic apply preserves building identity health armor jobs and stored payload '.$i);}
    foreach(['army','research','garrison','workers','resources'] as $field){verify($before[$field]===$after[$field],'atomic apply preserves owned '.$field);}
    verify($applied['base']['planner']['revision']!==$revision,'client receives updated revision after application');
    $raw=rawState($id);
    reject(fn()=>Game::mutate($id,'plannerApply',['positions'=>Planner::positions($after),'revision'=>$revision]),'stale revision cannot overwrite another completed arrangement');
    verify(rawState($id)===$raw,'failed stale mutation makes no database write');
    $locked=at($after,4,25,18);$raw=rawState($id);
    reject(fn()=>Game::mutate($id,'plannerApply',['positions'=>$locked,'revision'=>Planner::revision($after)]),'active fortification stays pinned under real transaction');
    verify(rawState($id)===$raw,'pinned-job rejection preserves paid timer and all state');
    $injected=Planner::positions($after);$injected[0]['hp']=999999;$raw=rawState($id);
    reject(fn()=>Game::mutate($id,'plannerApply',['positions'=>$injected,'revision'=>Planner::revision($after)]),'building health injection rejected');
    verify(rawState($id)===$raw,'injection cannot heal a real building');

    // Save an independent second slot, delete it, and retain the first across a later build.
    Game::mutate($id,'plannerSave',['slot'=>2,'name'=>'Reserve','positions'=>Planner::positions($after),'revision'=>Planner::revision($after)]);
    $before=Game::loadBase($id);$first=$before['planner']['slots'][1];
    Game::mutate($id,'plannerDelete',['slot'=>2,'revision'=>Planner::revision($before)]);$after=Game::loadBase($id);
    verify($after['planner']['slots'][1]===$first&&!isset($after['planner']['slots'][2]),'delete persists only requested slot removal');
    $after['buildings'][3]['fortify_ready_at']=time()-1;Game::saveBase($id,$after);Game::snapshot($id);
    $current=Game::loadBase($id);$oldRevision=Planner::revision($current);
    [$wallX,$wallY]=Bots::placements($id,'wall',0,$current,Catalog::buildings()['wall'])[0];
    Game::mutate($id,'build',['type'=>'wall','x'=>$wallX,'y'=>$wallY]);$expanded=Game::loadBase($id);
    verify($expanded['planner']['slots'][1]===$first,'new construction never rewrites a saved layout');
    verify(count(Planner::positions($expanded))===8&&count($first['positions'])===7,'new building becomes a real required inventory entry');
    reject(fn()=>Game::mutate($id,'plannerApply',['positions'=>$first['positions'],'revision'=>Planner::revision($expanded)]),'old template cannot remove the new building');
    reject(fn()=>Game::mutate($id,'plannerApply',['positions'=>Planner::positions($expanded),'revision'=>$oldRevision]),'building addition invalidates previously open draft');

    $foe=Game::register('Planner Foe '.bin2hex(random_bytes(3)),'planner-test-password')['id'];
    $q=$pdo->prepare("INSERT INTO battles(attacker_id,defender_id,status,state_json,created_at,updated_at) VALUES(?,?,'active','{}',?,?)");$q->execute([$foe,$id,time(),time()]);
    foreach(['plannerSave','plannerDelete','plannerApply'] as $action){
        $raw=rawState($id);reject(fn()=>Game::mutate($id,$action,['slot'=>1,'name'=>'Blocked','positions'=>Planner::positions($expanded),'revision'=>Planner::revision($expanded)]),'active battle guard '.$action);
        verify(rawState($id)===$raw,'battle guard commits no Planner change '.$action);
    }
    $pdo->rollBack();echo "Yard Planner integration: $checks checks passed; temporary data rolled back.\n";
}catch(Throwable $error){if($pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,(string)$error."\n");exit(1);}
