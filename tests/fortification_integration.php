<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function verify(bool $condition,string $label):void{global $checks;++$checks;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function reject(callable $work,string $label):void{try{$work();}catch(RuntimeException){verify(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
function buildingIndex(array $base,int $id):int{foreach($base['buildings'] as $i=>$b){if((int)$b['id']===$id){return $i;}}throw new RuntimeException('Fixture missing building');}
function addBuilding(array &$base,string $type,int $level=1):int{$id=$base['next_building_id']++;$hp=Game::buildingStats($type,$level)['max_hp'];$base['buildings'][]=['id'=>$id,'type'=>$type,'level'=>$level,'hp'=>$hp,'max_hp'=>$hp,'x'=>3,'y'=>28,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];return $id;}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE')?:'backyard_fortification_test','username'=>getenv('MYSQL_TEST_USER')?:'backyard_test','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1,'player_grace_seconds'=>1800];
if(!str_ends_with($config['database'],'_fortification_test')){throw new RuntimeException('Use a disposable database ending in _fortification_test.');}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
foreach(explode(';',file_get_contents(__DIR__.'/../app/schema.sql')) as $sql){if(trim($sql)!==''){$pdo->exec($sql);}}
$pdo->beginTransaction();
try{
    $id=Game::register('Fortify '.bin2hex(random_bytes(4)),'fortify-test-password')['id'];$base=Game::loadBase($id);$towerId=$base['buildings'][6]['id'];$ti=buildingIndex($base,$towerId);
    verify($base['buildings'][$ti]['fortification']===0,'new yard has no granted fortification');
    reject(fn()=>Game::mutate($id,'fortify',['id'=>$towerId]),'first tier requires Town Hall two');
    foreach([null,[],1.5,'1.5',0,-1,'invalid',999999] as $bad){reject(fn()=>Game::mutate($id,'fortify',['id'=>$bad]),'invalid or foreign building id');}
    $base['buildings'][0]['level']=5;$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],30000);$wallId=addBuilding($base,'wall');Game::saveBase($id,$base);
    reject(fn()=>Game::mutate($id,'fortify',['id'=>$wallId]),'wall cannot inherit tower fortification');
    $cost=Catalog::fortificationStats('sniper',1)['cost'];$before=Game::loadBase($id);$started=Game::mutate($id,'fortify',['id'=>$towerId,'tier'=>4,'cost'=>['twig'=>0],'ready_at'=>1]);$building=$started['base']['buildings'][$ti];
    foreach($cost as $resource=>$amount){verify($started['base']['resources'][$resource]===$before['resources'][$resource]-$amount,'server charges exact original cost '.$resource);}
    verify($building['fortify_to']===1&&$building['fortification']===0&&$building['fortify_ready_at']>=time()+59,'client cannot forge tier or timer');
    verify($building['fortify_paid']===$cost&&$building['fortify']['active']&&!$building['fortify']['canStart'],'owner sees actual paid job and availability');
    foreach(['fortify','move','upgrade'] as $action){reject(fn()=>Game::mutate($id,$action,['id'=>$towerId,'x'=>4,'y'=>4]),'unfinished fortification locks '.$action);}
    $base=Game::loadBase($id);$base['buildings'][$ti]['hp']-=1;Game::saveBase($id,$base);$paidJob=$base['buildings'][$ti];$repaired=Game::mutate($id,'repair',['id'=>$towerId]);$fixed=$repaired['base']['buildings'][$ti];
    verify($fixed['hp']===$fixed['max_hp']&&$fixed['fortify_ready_at']===$paidJob['fortify_ready_at']&&$fixed['fortify_paid']===$paidJob['fortify_paid'],'free HP repair preserves unfinished paid fortification');
    $visit=Game::visit($id,$id+99999);$scout=$visit['buildings'][$ti];verify(!array_key_exists('fortify_paid',$scout)&&!array_key_exists('fortify',$scout),'rival scout cannot read paid cost or private action state');
    verify($scout['fortification']===0&&$scout['fortify_ready_at']===$building['fortify_ready_at'],'scout sees only real protection and construction timer');
    $cancel=Game::mutate($id,'cancelFortify',['id'=>$towerId]);verify($cancel['refund']['credited']===$cost&&$cancel['refund']['overflow']===array_fill_keys(['twig','pebble','putty','goo'],0),'cancellation returns full original cost when storage permits');
    verify($cancel['base']['resources']===$before['resources']&&$cancel['base']['buildings'][$ti]['fortification']===0,'cancellation grants no protection or net resources');
    reject(fn()=>Game::mutate($id,'cancelFortify',['id'=>$towerId]),'repeat cancellation cannot duplicate refund');
    Game::mutate($id,'fortify',['id'=>$towerId]);$base=Game::loadBase($id);$base['resources']['twig']=29990;Game::saveBase($id,$base);$cancel=Game::mutate($id,'cancelFortify',['id'=>$towerId]);
    verify($cancel['refund']['credited']['twig']===10&&$cancel['refund']['overflow']['twig']===$cost['twig']-10,'refund caps at remaining storage and reports overflow');
    $base=Game::loadBase($id);$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],60000);Game::saveBase($id,$base);Game::mutate($id,'fortify',['id'=>$towerId]);$paidBalance=Game::loadBase($id)['resources'];$cancel=Game::mutate($id,'cancelFortify',['id'=>$towerId]);
    verify($cancel['base']['resources']===$paidBalance&&array_sum($cancel['refund']['credited'])===0,'cancellation neither creates credit nor deletes held surplus above capacity');
    $base=Game::loadBase($id);$base['resources']=array_fill_keys(['twig','pebble','putty','goo'],1000000);Game::saveBase($id,$base);Game::mutate($id,'fortify',['id'=>$towerId]);$base=Game::loadBase($id);$base['buildings'][$ti]['fortify_ready_at']=time()-1;$base['buildings'][$ti]['hp']-=123;$hp=$base['buildings'][$ti]['hp'];Game::saveBase($id,$base);
    $snap=Game::snapshot($id);$building=$snap['base']['buildings'][$ti];verify($building['fortification']===1&&$building['hp']===$hp&&$building['level']===1,'real clock completion preserves injury and normal level');
    verify($building['fortify_to']===null&&$building['fortify_paid']===null&&!$building['fortify']['active'],'completed job clears paid and pending state');
    reject(fn()=>Game::mutate($id,'fortify',['id'=>$towerId]),'damaged structure must repair before next tier');
    Game::mutate($id,'repair',['id'=>$towerId]);$base=Game::loadBase($id);verify($base['buildings'][$ti]['fortification']===1,'normal repair preserves earned protection');
    foreach([2,3,4] as $tier){
        $base=Game::loadBase($id);$base['buildings'][0]['level']=$tier;Game::saveBase($id,$base);reject(fn()=>Game::mutate($id,'fortify',['id'=>$towerId]),'exact next Town Hall gate tier '.$tier);
        $base['buildings'][0]['level']=$tier+1;Game::saveBase($id,$base);$job=Game::mutate($id,'fortify',['id'=>$towerId]);verify($job['base']['buildings'][$ti]['fortification']===$tier-1,'old tier retained during next work '.$tier);
        $base=Game::loadBase($id);$base['buildings'][$ti]['fortify_ready_at']=time()-1;Game::saveBase($id,$base);$done=Game::snapshot($id);verify($done['base']['buildings'][$ti]['fortification']===$tier,'tier completes once '.$tier);
    }
    reject(fn()=>Game::mutate($id,'fortify',['id'=>$towerId]),'fifth fortification rejected');
    $upgraded=Game::mutate($id,'upgrade',['id'=>$towerId]);verify($upgraded['base']['buildings'][$ti]['fortification']===4,'normal building upgrade preserves fortification');
    reject(fn()=>Game::mutate($id,'fortify',['id'=>$towerId]),'normal upgrade cannot overlap fortification');
    $base=Game::loadBase($id);$base['buildings'][$ti]['ready_at']=time()-1;Game::saveBase($id,$base);Game::snapshot($id);verify(Game::loadBase($id)['buildings'][$ti]['fortification']===4,'normal upgrade completion retains fortification');
    $foe=Game::register('Fortify Foe '.bin2hex(random_bytes(3)),'fortify-test-password')['id'];$q=$pdo->prepare("INSERT INTO battles(attacker_id,defender_id,status,state_json,created_at,updated_at) VALUES(?,?,'active','{}',?,?)");$q->execute([$id,$foe,time(),time()]);
    foreach(['fortify','cancelFortify'] as $action){reject(fn()=>Game::mutate($id,$action,['id'=>$towerId]),'battle guard '.$action);}
    $pdo->rollBack();echo "Fortification integration: $checks assertions passed; temporary data rolled back.\n";
}catch(Throwable $error){if($pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,(string)$error."\n");exit(1);}
