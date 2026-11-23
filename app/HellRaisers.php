<?php
declare(strict_types=1);

/** Replayable event 7. Surface troops raid Moloch's wild Inferno yards. */
final class HellRaisers
{
    public const DURATION = 345600;
    public const SHOP_GRACE = 86400;
    private const OFFSET = 1000;
    // 214–234 are the source's 21 independently attackable Inferno wild tribes.
    private const FIRST = 214;
    private const LAST = 234;
    // EventStoreDisplayGrid.Populate supplies these prices after loading prize definitions.
    private const PRIZES = [
        'rezghul'=>['Rezghul',999,'monster','rezghul',1],
        'vorg'=>['Vorg',999,'monster','vorg',1],
        'slimeattikus'=>['Slimeattikus',9999,'monster','slimeattikus',1],
        'spurtz1'=>['Spurtz Cannon',9999,'cannon','spurtzcannon',1],
        'spurtz2'=>['Second Spurtz Cannon',999,'cannon','spurtzcannon',2],
        'spurtz3'=>['Black Diamond upgrade',9999,'cannon','diamondspurtz',3],
        'korath1'=>['Korath',999,'champion','korath',1],
        'korath2'=>['Korath’s fireballs',9999,'champion','korath',2],
        'korath3'=>['Korath’s seismic stomp',999,'champion','korath',3],
    ];

    public static function contains(int $stage): bool
    {
        return $stage >= self::OFFSET+self::FIRST && $stage <= self::OFFSET+self::LAST;
    }

    private static function ledger(array $base): array
    {
        return ($base['hellRaisers']??[]) + ['run'=>0,'started'=>0,'score'=>0,'balance'=>0,'best'=>0,'yards'=>[],'lastBattle'=>0];
    }

    private static function phase(array $event,int $now): string
    {
        if (!$event['run']) return 'available';
        $ends=$event['started']+self::DURATION;
        if ($now<$ends) return 'active';
        return $now<$ends+self::SHOP_GRACE ? 'shop' : 'replay';
    }

    private static function card(array $camp,array $event,int $run): array
    {
        $id=$camp['sourceId'];
        return ['stage'=>self::OFFSET+$id,'sourceId'=>$id,'name'=>$camp['name'],'tier'=>$camp['route'],'tribe'=>'Moloch',
            'event'=>'hellraisers','reward'=>[],'xp'=>count($camp['buildings']),'run'=>$run,
            'best'=>$run===$event['run']?($event['yards'][$id]['best']??null):null];
    }

    private static function prize(array $base,string $id,int $balance,bool $shop): array
    {
        [$name,$cost,$kind,$art,$level]=self::PRIZES[$id];
        $power=match($kind) {
            'monster'=>in_array($art,$base['unlocked']??[],true)?1:0,
            'cannon'=>max(0,SpurtzRewards::status($base)['cleared']-2),
            'champion'=>MonsterMadness::power($base),
        };
        $owned=$power>=$level;
        $blocked=($level>$power+1);
        $studying=$kind==='monster'&&in_array($art,array_column($base['unlockQueue']??[],'type'),true);
        return compact('id','name','cost','art','owned','blocked','studying')+['canBuy'=>$shop&&!$owned&&!$blocked&&!$studying&&$balance>=$cost];
    }

    public static function view(array $base,int $now): array
    {
        $event=self::ledger($base);
        $phase=self::phase($event,$now);
        $map=Game::livingLevel($base,'maproom')>0;
        $shop=$event['run']>0&&in_array($phase,['active','shop'],true);
        $run=$event['run']+(in_array($phase,['available','replay'],true)?1:0);
        $camps=[];
        if ($map&&$phase!=='shop') {
            foreach (InfernoRealm::campCatalog() as $id=>$camp) {
                if ($id>=self::FIRST&&$id<=self::LAST) $camps[]=self::card($camp,$event,$run);
            }
        }
        $prizes=[];
        foreach (array_keys(self::PRIZES) as $id) $prizes[]=self::prize($base,$id,$event['balance'],$shop);
        $ends=$event['started']?$event['started']+self::DURATION:0;
        return ['eventId'=>7,'phase'=>$phase,'mapReady'=>$map,'shopOpen'=>$shop,'ends'=>$ends,'shopEnds'=>$ends?$ends+self::SHOP_GRACE:0,
            'run'=>$run,'balance'=>$event['balance'],'score'=>$event['score'],'best'=>$event['best'],'camps'=>$camps,'prizes'=>$prizes];
    }

    public static function camp(array $base,int $stage,int $now): array
    {
        if (!self::contains($stage)) throw new RuntimeException('Choose a Moloch yard from Hell Raisers.');
        if (Game::livingLevel($base,'maproom')<1) throw new RuntimeException('Build or repair your Map Room before hunting Moloch.');
        $event=self::ledger($base);
        $phase=self::phase($event,$now);
        if ($phase==='shop') throw new RuntimeException('This hunt has ended. The next replay opens when the Event Store closes.');
        $camp=InfernoRealm::campCatalog()[$stage-self::OFFSET];
        $run=$event['run']+($phase==='active'?0:1);
        return self::card($camp,$event,$run)+['open'=>true,'eventYard'=>InfernoRealm::campYard($camp)];
    }

    public static function begin(array &$base,int $stage,mixed $expectedRun,int $now): array
    {
        $camp=self::camp($base,$stage,$now);
        if ($camp['run']!==$expectedRun) throw new RuntimeException('Your Hell Raisers run changed. Scout this yard again.');
        $event=self::ledger($base);
        if ($event['run']!==$expectedRun) {
            $event['run']=$expectedRun;
            $event['started']=$now;
            $event['score']=0;
            $event['yards']=[];
            $base['hellRaisers']=$event;
        }
        $camp['endsAt']=$event['started']+self::DURATION;
        return $camp;
    }

    /** Count the frozen foundations like HellRaisers.getBasesWorthInXP, once per committed battle. */
    public static function settle(array &$base,array &$battle,int $finished): void
    {
        $event=self::ledger($base);
        $target=$battle['target'];
        $result=&$battle['result'];
        $damage=BattleEngine::captureProgress($battle);
        $result['attackerWon']=$damage>=World::CAPTURE_THRESHOLD;
        $result['winnerId']=$result['attackerWon']?$battle['attacker']['id']:null;
        $result['destruction']=round($damage,2);
        $active=$event['run']===$target['run']&&$battle['created_at']>=$event['started']&&$finished<=$event['started']+self::DURATION;
        $fought=!empty($result['destruction'])||!empty($battle['catapult']['shots'])||!empty($battle['siege']['used']);
        foreach ($battle['units'] as $unit) {
            if (($unit['team']??'attacker')==='attacker'&&empty($unit['summoned'])) {$fought=true;break;}
        }
        $xp=0;
        if ($active&&$fought&&$battle['id']>$event['lastBattle']) {
            $xp=count($battle['buildings']);
            $event['score']+=$xp;
            $event['balance']+=$xp;
            $event['best']=max($event['score'],$event['best']);
            $source=$target['sourceId'];
            $record=$event['yards'][$source]??['attempts'=>0,'wins'=>0,'best'=>0];
            ++$record['attempts'];
            $record['wins']+=(int)$result['attackerWon'];
            $record['best']=max($record['best'],$result['destruction']);
            $event['yards'][$source]=$record;
            $event['lastBattle']=$battle['id'];
            $base['hellRaisers']=$event;
        }
        $result['target']=$target;
        $result['rankDelta']=['attacker'=>0,'defender'=>0];
        $result['campaign']=['event'=>'hellraisers','xp'=>$xp,'balance'=>$event['balance'],'score'=>$event['score'],'expired'=>!$active];
    }

    public static function buy(array &$base,string $id,int $run,int $now): array
    {
        $event=self::ledger($base);
        if (!isset(self::PRIZES[$id])) throw new RuntimeException('Choose an available Event Store prize.');
        if (!$event['run']||$run!==$event['run']||!in_array(self::phase($event,$now),['active','shop'],true)) {
            throw new RuntimeException('This Event Store has closed. Open your current hunt.');
        }
        $prize=self::prize($base,$id,$event['balance'],true);
        if ($prize['owned']) return $prize;
        if ($prize['blocked']) throw new RuntimeException('Unlock the previous prize first.');
        if ($prize['studying']) throw new RuntimeException('Your Monster Locker is already discovering this monster.');
        if (!$prize['canBuy']) throw new RuntimeException('Earn more event XP by attacking Moloch yards.');
        [, $cost,$kind,$art,$level]=self::PRIZES[$id];
        switch ($kind) {
            case 'monster': $base['unlocked'][]=$art; break;
            case 'cannon': SpurtzRewards::award($base,$level+2,true); break;
            case 'champion': $base['hellRaisersKorath']=$level; break;
        }
        $event['balance']-=$cost;
        $base['hellRaisers']=$event;
        return self::prize($base,$id,$event['balance'],true);
    }

    public static function purchase(int $keeper,string $id,int $run): array
    {
        return Db::transaction(static function() use ($keeper,$id,$run):array {
            $base=Game::loadBase($keeper,true);
            $prize=self::buy($base,$id,$run,time());
            Game::saveBase($keeper,$base);
            return ['prize'=>$prize,'event'=>self::view($base,time())];
        });
    }
}
