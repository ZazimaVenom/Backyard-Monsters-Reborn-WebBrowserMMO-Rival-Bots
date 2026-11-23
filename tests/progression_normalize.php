<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function verify(bool $condition,string $label):void{global $checks;$checks++;if(!$condition){throw new RuntimeException('FAIL: '.$label);}}
function buildingsPreserved(array $old,array $new):bool{if(count($old)!==count($new)){return false;}foreach($old as $i=>$building){if(array_intersect_key($new[$i],$building)!==$building||$new[$i]['fortification']!==0||$new[$i]['fortify_ready_at']!==0||$new[$i]['fortify_to']!==null||$new[$i]['fortify_paid']!==null){return false;}}return true;}
$old=['buildings'=>[['id'=>1,'type'=>'townhall','level'=>2,'hp'=>1,'max_hp'=>6480,'x'=>16,'y'=>16,'ready_at'=>0]],'resources'=>['twig'=>1234,'pebble'=>987,'goo'=>6543,'putty'=>42],'army'=>['pokey'=>17,'octo'=>3,'fink'=>2,'ichi'=>1,'bandito'=>4,'dave'=>0],'research'=>['pokey'=>3,'octo'=>2,'fink'=>1,'ichi'=>1,'bandito'=>1,'dave'=>1],'queue'=>[['id'=>5,'type'=>'pokey','quantity'=>1,'lane'=>2,'ready_at'=>9999999999]],'researchQueue'=>[['type'=>'fink','target_level'=>2,'ready_at'=>9999999998]],'last_tick'=>12345,'shield_until'=>8888,'next_building_id'=>77,'next_queue_id'=>99,'custom_extension'=>['x'=>'must survive']];
$normalized=Game::normalizeBase($old);
verify(Game::normalizeBase($normalized)===$normalized,'normalization is idempotent');
foreach(['buildings','resources','queue','researchQueue','last_tick','shield_until','next_building_id','next_queue_id','custom_extension'] as $key){verify($key==='buildings'?buildingsPreserved($old[$key],$normalized[$key]):$old[$key]===$normalized[$key],'preserve '.$key);}
foreach($old['army'] as $type=>$quantity){verify($normalized['army'][$type]===$quantity,'preserve old army '.$type);verify($normalized['research'][$type]===$old['research'][$type],'preserve old research '.$type);}
verify(count($normalized['unlocked'])===6,'grandfather original six species');
foreach(['bolt','eyera','fang','wormzer','teratorn','zafreeti'] as $type){verify($normalized['army'][$type]===0,'new species starts with no free units '.$type);verify(!in_array($type,$normalized['unlocked'],true),'new species starts locked '.$type);}
verify($normalized['unlockQueue']===[]&&$normalized['garrison']===[],'new queues and garrison initialized');
verify(count(array_filter($normalized['buildings'],fn($b)=>$b['type']==='flinger'))===0,'migration never inserts a free flinger');
verify($normalized['content_version']==='0.13.0','base content version recorded');
$custom=$normalized;$custom['unlocked'][]='bolt';$custom['unlocked'][]='future_extension_monster';$custom['garrison']=['45'=>['pokey'=>5]];$custom['unlockQueue']=[['type'=>'eyera','ready_at'=>9999999999]];
verify(Game::normalizeBase($custom)===$custom,'future and current progression state preserved');
$buildings=Catalog::buildings();$monsters=Catalog::monsters();
verify(count($buildings)>=33&&count(Catalog::homeMonsters())>=15,'previous structures and surface species remain available');
verify($buildings['laser']['targetMode']==='ground','laser preserves source ground-target role');
verify($buildings['sniper']['targetMode']==='both'&&$buildings['tesla']['targetMode']==='both','anti-air defenses remain');
verify($buildings['flinger']['capacityByLevel']===[250,850,1500,2500,3500]&&$buildings['flinger']['cooldown']===5,'flinger launch contract');
verify($monsters['eyera']['ability']==='explode'&&$monsters['eyera']['target']==='wall','Eye-Ra explosion role');
verify($monsters['wormzer']['movement']==='burrow'&&$monsters['teratorn']['movement']==='air','burrow and flying roles');
verify($monsters['zafreeti']['ability']==='heal'&&$monsters['zafreeti']['damage']===0&&$monsters['zafreeti']['heal']>0,'healer cannot become ordinary damage dealer');
verify($buildings['juicer']['refundRate']>0&&$buildings['juicer']['refundRate']<1,'juicing cannot print goo through hatch/recycle loop');
echo "Progression normalization: $checks assertions passed.\n";
