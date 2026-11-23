<?php
declare(strict_types=1);

/** Hell-Raisers event 3. The ten timed formations reconstruct the missing event data. */
final class MolochSiege
{
    // Time after release, Inferno species, quantity, approach. Later waves add flankers, siege and support.
    private const FORMATIONS = [
        [[0,'spurtz',3,'l'],[5,'zagnoid',2,'tl']],
        [[0,'zagnoid',4,'tl'],[4,'spurtz',4,'l'],[10,'malphus',1,'tl']],
        [[0,'spurtz',6,'l'],[5,'zagnoid',5,'tr'],[10,'balthazar',1,'l']],
        [[0,'zagnoid',6,'t'],[5,'malphus',5,'t'],[12,'sabnox',3,'br']],
        [[0,'spurtz',8,'bl'],[4,'balthazar',2,'bl'],[10,'zagnoid',6,'r'],[16,'valgos',1,'r']],
        [[0,'grokus',2,'l'],[4,'zagnoid',7,'l'],[12,'sabnox',3,'tr'],[18,'spurtz',8,'tr']],
        [[0,'balthazar',3,'r'],[4,'malphus',5,'r'],[9,'valgos',2,'b'],[15,'grokus',3,'tl'],[22,'zagnoid',8,'tl']],
        [[0,'zagnoid',9,'tr'],[5,'sabnox',5,'tr'],[10,'grokus',4,'bl'],[17,'balthazar',3,'bl'],[23,'kingwormzer',2,'b']],
        [[0,'spurtz',12,'t'],[4,'grokus',5,'t'],[9,'valgos',3,'bl'],[14,'sabnox',5,'br'],[21,'malphus',7,'br'],[27,'kingwormzer',2,'bl']],
        [[0,'grokus',5,'l'],[4,'spurtz',12,'l'],[8,'balthazar',5,'tr'],[12,'sabnox',6,'tr'],[17,'valgos',3,'b'],[22,'zagnoid',12,'b'],[27,'kingwormzer',3,'r'],[32,'malphus',8,'r']],
    ];

    public static function outpostMagma(array $base): bool { return !empty($base['molochSiege']['towerAward']); }
    public static function homeMagmaLimit(array $base): int { return self::outpostMagma($base)?2:0; }

    public static function view(array $base): array
    {
        $save=$base['molochSiege']??[];$held=(int)($save['cleared']??0);$stage=min(10,$held+1);
        $groups=self::FORMATIONS[$stage-1];
        $reason=$held===10?'All ten attacks have been repelled.':(Game::livingLevel($base,'townhall')<1?'Repair your Town Hall before the next defense.':'');
        return ['cleared'=>$held,'stage'=>$stage,'complete'=>$held===10,'eligible'=>$reason==='','reason'=>$reason,
            'wave'=>MonsterBlitz::counts($groups),'edges'=>array_values(array_unique(array_column($groups,3))),
            'homeMagmaLimit'=>self::homeMagmaLimit($base),'outpostMagma'=>self::outpostMagma($base),'last'=>$save['last']??null];
    }

    public static function start(array $base,array $owner,array $input,int $now,float $bonus,array $network): array
    {
        $v=self::view($base);
        if(!$v['eligible'])throw new RuntimeException($v['reason']);
        if(($input['stage']??null)!==$v['stage'])throw new RuntimeException('Refresh Hell-Raisers to defend the next wave.');
        $groups=self::FORMATIONS[$v['stage']-1];$edge=$groups[0][3];
        $s=Baiter::defenseState($base,$owner,$v['wave'],$edge,$now,$bonus,$network,true);
        $s['formatVersion']=max(8,$s['formatVersion']);
        $areas=[];foreach($v['edges'] as $dir)$areas[$dir]=Baiter::entryPoints($s,$dir,true);
        $s['target']=['kind'=>'moloch-siege','name'=>'Hell-Raisers · Moloch’s Siege'];
        $s['attacker']['name']='Moloch';
        $s['molochWave']=['stage'=>$v['stage'],'groups'=>$groups,'next'=>0,'approaches'=>$areas];
        $s['remaining']=$v['wave'];return $s;
    }

    /** The Flash MonsterInvasion scores the remaining non-wall foundations, including unfired traps. */
    public static function yardDamage(array $state): float
    {
        $capacity=0.;$health=0.;
        foreach($state['buildings'] as $building){
            if(in_array($building['type'],['wall','stonewall'],true))continue;
            if(Catalog::isTrap($building['type']) && (!empty($building['triggered'])||!empty($building['spent'])))continue;
            $hp=(float)$building['max_hp'];$capacity+=$hp;$health+=min($hp,max(0,(float)$building['hp']));
        }
        return $capacity>0?max(0,min(100,100*(1-$health/$capacity))):100;
    }

    /** Apply the damage and credit exactly one held wave while the owner's base is locked. */
    public static function settle(array &$base,array $state,int $now): void
    {
        $save=$base['molochSiege']??[];$stage=$state['molochWave']['stage'];
        if(($save['last']['id']??null)===$state['id']||$stage!==($save['cleared']??0)+1)return;
        MonsterBlitz::applyDamage($base,$state);
        WildMonsterInvasions::applyDefenderLosses($base,$state,$now);
        $outcome=$state['result']['outcome'];$damage=round(self::yardDamage($state),2);
        $save['last']=['id'=>$state['id'],'stage'=>$stage,'outcome'=>$outcome,'damage'=>$damage,'defeated'=>$state['result']['defeated']];
        if($outcome==='held'){
            $save['cleared']=$stage;
            if($stage===10)$save['towerAward']=true;
        }
        $base['molochSiege']=$save;
    }
}
