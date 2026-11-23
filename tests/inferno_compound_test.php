<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;function ck(bool $a,string $why):void{global $checks;if(!$a)throw new RuntimeException($why);++$checks;}
function house(int $id,int $level,float $x,bool $online=true):array {return ['id'=>$id,'type'=>'housing','level'=>$level,'hp'=>1000,'max_hp'=>1000,'x'=>$x,'y'=>16,'_ready'=>$online,'_size'=>2,'_initial_hp'=>1000];}
function battle(array $houses):array{return ['buildings'=>$houses,'units'=>[],'events'=>[],'eventSeq'=>0,'elapsed'=>0.0,'obstacleVersion'=>0,'formatVersion'=>9];}
function wild(string $type,float $x,string $id='enemy'):array{return BattleService::makeUnit($type,1,$id,'attacker',$x,16);}
function guards(array $b):array{return array_values(array_filter($b['units'],fn($u)=>isset($u['compoundId'])));}
$realm=['army'=>['spurtz'=>4,'balthazar'=>1,'sabnox'=>1,'pokey'=>99],'research'=>['spurtz'=>3]];
$s=battle([house(8,1,16)]);InfernoCompound::seed($s,$realm);
ck($s['buildings'][0]['compoundRange']===12.5 && array_sum($s['_compounds'][8]['held'])===6,'Level one sightings and native-only escrow');
$s['units'][]=wild('balthazar',31);InfernoCompound::tick($s);ck(guards($s)===[],'Out of range remains indoors');
$s['elapsed']=1.0;$s['units'][0]['x']=24;InfernoCompound::tick($s);$g=guards($s);
ck(count($g)===1 && in_array($g[0]['type'],['balthazar','sabnox'],true),'One true anti-air interceptor responds to one flier');
$s['elapsed']=1.25;InfernoCompound::tick($s);ck(count(guards($s))===1,'30-frame dispatch cadence');
$s['elapsed']=2.0;$s['units'][]=wild('spurtz',26,'ground');InfernoCompound::tick($s);$g=guards($s);
ck(count($g)===6 && count(array_unique(array_column($g,'id')))===6,'Nearest ground target brings out remaining native residents once');
$spec=Catalog::monsters()['spurtz']['levels'][3];$sp=array_values(array_filter($g,fn($u)=>$u['type']==='spurtz'))[0];
ck($sp['level']===3 && $sp['max_hp']==$spec['hp'] && $sp['targetId']==='ground','Interceptor uses owned research tier and actual hostile target');
$s['elapsed']=3;InfernoCompound::tick($s);ck(count(guards($s))===6,'No duplicate spawns');
$s['result']=[];foreach($s['units'] as &$u){if($u['id']===$sp['id'])$u['hp']=0;}unset($u);
$live=$realm;$live['army']['spurtz']++;InfernoCompound::settle($s,$live);
ck($live['army']['spurtz']===4&&$live['army']['pokey']===99&&$s['result']['compoundLosses']===['spurtz'=>1],'Only killed housed unit is removed, concurrent new hatch retained');
InfernoCompound::settle($s,$live);ck($live['army']['spurtz']===4,'Replay settlement cannot charge twice');
$s=battle([house(2,6,12),house(4,3,22),house(6,5,20,false)]);$r=['army'=>['grokus'=>900,'spurtz'=>3]];InfernoCompound::seed($s,$r);
ck($s['buildings'][0]['compoundRange']===16.25&&$s['buildings'][1]['compoundRange']===14.0&&!isset($s['_compounds'][6]),'Source six tiers and inactive Compound exclusion');
$count=0;foreach($s['_compounds'] as $room)$count+=array_sum($room['held']);ck($count<=72,'Bounded resident selection respects actual capacity and overflow');
$n=$s['_compounds'][2]['held']['grokus'];BattleEngine::hurtBuilding($s,0,99999);$s['result']=[];InfernoCompound::settle($s,$r);
ck($r['army']['grokus']===900-$n,'Destroyed Compound loses its own housed stock only');
$legacy=battle([house(1,2,15)]);$legacy['result']=[];$before=$realm;InfernoCompound::tick($legacy);InfernoCompound::settle($legacy,$realm);
ck($before===$realm && $legacy['result']===[],'Legacy battle, ordinary surface Housing, and quiet camps retain original behavior');
echo "Compound interception: $checks checks passed.\n";
