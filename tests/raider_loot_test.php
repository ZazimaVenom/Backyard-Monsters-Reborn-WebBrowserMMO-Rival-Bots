<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$checks=0;
function lootCheck(bool $ok,string $reason):void{global $checks;if(!$ok)throw new RuntimeException($reason);++$checks;}
function lootBattle(string $kind):array{
    return ['target'=>['kind'=>$kind],'events'=>[],'elapsed'=>2.0,'eventSeq'=>0,'result'=>['loot'=>[]]];
}
$realm=InfernoRealm::initial(100);$realm['army']['malphus']=1;
$victim=InfernoRealm::initial(100);$bean=['type'=>'twig','id'=>2,'level'=>1,'hp'=>40,'stored'=>74,'x'=>12,'y'=>12];$vault=['type'=>'townhall','id'=>1,'x'=>15,'y'=>13];
$victim['resources']=['bone'=>1000,'coal'=>0,'sulfur'=>0,'magma'=>0];$victim['buildings']=[$bean];
$u=['id'=>'raider','type'=>'malphus','team'=>'attacker'];$s=lootBattle('infernoRival');RaiderLoot::seed($s,$realm,$victim);
RaiderLoot::hit($s,array_replace($u,['team'=>'defender']),$bean,32);
RaiderLoot::hit($s,array_replace($u,['type'=>'spurtz']),$bean,32);
RaiderLoot::hit($s,$u,['type'=>'sniper','id'=>3],32);
lootCheck(!$s['events'],'Defenders, ordinary species and defense structures cannot claim resources');
lootCheck(!RaiderLoot::hit($s,$u,$bean,25)&&$s['events'][0]['amount']===50,'Malphus reserves twice actual HP damage');
lootCheck(RaiderLoot::hit($s,array_replace($u,['type'=>'grokus']),$bean,40) && $s['events'][1]['amount']===24 && !RaiderLoot::available($s['_raiderLoot'],$bean),'Grokus cannot overdraw a producer and retargets when empty');
RaiderLoot::hit($s,$u,$vault,1000);lootCheck(end($s['events'])['amount']===100,'Town Hall and pods share the bounded underground vault');
$s['result']['loot']['bone']=30;$victim['resources']['bone']-=30;$realm['resources']['bone']=11900;
RaiderLoot::transfer($s,$realm,$victim,12000);
lootCheck($s['result']['raiderLoot']===['bone'=>100] && $s['result']['loot']['bone']===130 && $victim['resources']['bone']===900 && $victim['buildings'][0]['stored']===44,'Settlement honors both bank limit and free storage and leaves surplus in original bin');
$camp=lootBattle('inferno');$camp['target']+=['stage'=>201,'reward'=>['bone'=>60,'coal'=>42,'sulfur'=>32,'magma'=>20]];
$campaign=InfernoRealm::initial(time());$campaign['army']['grokus']=1;$campaign['resources']=array_fill_keys(array_keys($campaign['resources']),0);
RaiderLoot::seed($camp,$campaign,[]);RaiderLoot::hit($camp,$u,$bean,18);RaiderLoot::campaign($camp,$campaign,'campaign',12000);
$camp['result']+=['attackerWon'=>false,'destruction'=>0];InfernoRealm::finish($campaign,$camp['result'],$camp['target']);
lootCheck($camp['result']['loot']['bone']===36 && $campaign['campaign']['raided'][201]['bone']===36,'Paid damage survives a retreat');
$second=lootBattle('inferno');$second['target']=$camp['target'];RaiderLoot::seed($second,$campaign,[]);RaiderLoot::hit($second,$u,$bean,90);
lootCheck($second['events'][0]['amount']===24,'Repeat invasion sees only the unpaid remainder');
RaiderLoot::campaign($second,$campaign,'campaign',12000);$second['result']+=['attackerWon'=>true,'destruction'=>100];
InfernoRealm::finish($campaign,$second['result'],$second['target']);lootCheck($campaign['resources']===$camp['target']['reward'] && $second['result']['loot']['bone']===24,'Victory and repeated hits share one finite stage prize');
$third=lootBattle('inferno');$third['target']=$camp['target'];RaiderLoot::seed($third,$campaign,[]);lootCheck(!isset($third['_raiderLoot']),'Cleared camp can never generate duplicate prizes');
echo "Raider loot: $checks checks passed.\n";
