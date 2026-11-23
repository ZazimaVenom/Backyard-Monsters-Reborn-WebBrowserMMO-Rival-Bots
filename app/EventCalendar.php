<?php
declare(strict_types=1);

/** UTC source rotation. Private replays and official rounds keep separate progress. */
final class EventCalendar
{
    private const DAY=86400;
    private const EVENTS=[
        'wmi1'=>['Wild Monster Invasion I',7,32],
        'wmi2'=>['Wild Monster Invasion II',7,32],
        'brukkarg'=>['Brukkarg War',5,5025],
        'carnage'=>['Creature Carnage',6,10],
        'blitz'=>['Monster Blitzkrieg',6,10],
    ];

    /** One month of history, through a complete four-month cycle ahead. */
    public static function dates(int $now):array
    {
        $date=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('UTC'));
        $month=$date->modify('first day of this month')->setTime(0,0);
        $week=$date->modify('monday this week')->setTime(0,0)->getTimestamp();
        $result=[];
        for($offset=-1;$offset<=4;$offset++){
            $m=$month->modify(sprintf('%+d months',$offset));
            $index=((int)$m->format('Y')*12+(int)$m->format('n')-1)%4;
            if($index!==0 && $index!==2)continue;
            foreach([10=>$index===0?'wmi1':'wmi2',24=>'brukkarg'] as $day=>$key){
                $result[]=self::round($key,$m->getTimestamp()+($day-1)*self::DAY,$now);
            }
        }
        foreach([-1,0,1,2] as $offset){
            foreach(['carnage','blitz'] as $key)$result[]=self::round($key,$week+$offset*7*self::DAY,$now);
        }
        usort($result,static fn($a,$b)=>[$a['start'],$a['key']]<=>[$b['start'],$b['key']]);
        return $result;
    }

    private static function round(string $key,int $start,int $now):array
    {
        [$name,$days,$max]=self::EVENTS[$key];
        $weekly=in_array($key,['blitz','carnage'],true);
        $end=$start+$days*self::DAY;
        $announce=$start-($weekly?1:7)*self::DAY;
        $close=$end+($key==='brukkarg'?2*self::DAY:0);
        $phase=$now<$announce?'upcoming':($now<$start?'countdown':($now<$end?'active':($now<$close?'closing':'finished')));
        return ['key'=>$key,'name'=>$name,'start'=>$start,'end'=>$end,'announce'=>$announce,'close'=>$close,'max'=>$max,'phase'=>$phase];
    }

    private static function eligibility(array $base,string $key):string
    {
        $level=Game::livingLevel($base,'townhall');
        return match($key){
            'brukkarg'=>$level>=5?'':'Requires a working level 5 Town Hall.',
            'blitz'=>$level>=3&&$level<=4?'':'For keepers with a working level 3 or 4 Town Hall.',
            'carnage'=>$level>=2&&$level<=4?'':'For keepers with a working level 2–4 Town Hall.',
            default=>$level>0?'':'Repair your Town Hall to enter.',
        };
    }

    private static function fields(string $key):array
    {
        return match($key){
            'carnage'=>['creatureCarnage'],'brukkarg'=>['brukkargWar'],'blitz'=>['blitzDeadline','blitzCleared'],
            'wmi1','wmi2'=>['wmi'],default=>throw new RuntimeException('Choose a calendar event.'),
        };
    }

    /** Extract only the selected campaign. Both Wild Monster Invasions share a parent field. */
    private static function exchange(array &$yard,string $key,array $incoming):array
    {
        $out=[];
        foreach(self::fields($key) as $field){
            if($field==='wmi'){
                $id=$key==='wmi1'?1:2;
                if(isset($yard[$field][$id]))$out[$field]=$yard[$field][$id];
                if(isset($incoming[$field]))$yard[$field][$id]=$incoming[$field];else unset($yard[$field][$id]);
                if(empty($yard[$field]))unset($yard[$field]);
            }else{
                if(array_key_exists($field,$yard))$out[$field]=$yard[$field];
                if(array_key_exists($field,$incoming))$yard[$field]=$incoming[$field];else unset($yard[$field]);
            }
        }
        return $out;
    }

    private static function initial(string $key,array $round):array
    {
        return match($key){
            'carnage'=>['creatureCarnage'=>['run'=>$round['start'],'started'=>$round['start'],'cleared'=>[],'supplied'=>[]]],
            'blitz'=>['blitzDeadline'=>$round['end'],'blitzCleared'=>0],
            'wmi1','wmi2'=>['wmi'=>['run'=>$round['start'],'deadline'=>$round['end'],'cleared'=>0]],
            default=>[],
        };
    }

    public static function enrolled(array $yard,string $key):bool
    {
        return !empty($yard['calendarRounds'][$key]['playing']);
    }
    public static function checkStart(array $yard,string $key,int $now):void
    {
        if(!self::enrolled($yard,$key))return;
        $run=$yard['calendarRounds'][$key];
        if($now>=$run['end']||$now<$run['start'])throw new RuntimeException('This official round has ended. Return to the event calendar to resume your private campaign.');
        if(self::score($yard,$key)>=self::EVENTS[$key][2])throw new RuntimeException('You have completed this official round.');
    }

    private static function score(array $yard,string $key):int
    {
        return match($key){
            'blitz'=>(int)($yard['blitzCleared']??0),'carnage'=>count($yard['creatureCarnage']['cleared']??[]),
            'brukkarg'=>Brukkarg::score($yard),'wmi1','wmi2'=>(int)($yard['wmi'][$key==='wmi1'?1:2]['cleared']??0),
        };
    }
    private static function prefix(string $key,int $start):string{return 'eventRound:'.$key.':'.$start.':';}

    /** Called for an actual changed home under its existing write transaction. */
    public static function record(PDO $pdo,int $owner,array &$yard,int $now):void
    {
        foreach($yard['calendarRounds']??[] as $key=>$run){
            if(empty($run['playing'])||$now<$run['start']||$now>=$run['end'])continue;
            $score=min(self::EVENTS[$key][2],self::score($yard,$key));
            if($score<=($run['posted']??0))continue;
            $stmt=$pdo->prepare('INSERT INTO settings(name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=GREATEST(CAST(value AS UNSIGNED),CAST(VALUES(value) AS UNSIGNED))');
            $stmt->execute([self::prefix($key,$run['start']).$owner,$score]);
            $yard['calendarRounds'][$key]['posted']=$score;
        }
    }

    public static function act(int $owner,array $input,?int $clock=null):array
    {
        return Db::transaction(static function(PDO $pdo)use($owner,$input,$clock){
            $key=$input['key']??null;$op=$input['op']??null;$start=$input['start']??null;$now=$clock??time();
            if(!is_string($key)||!isset(self::EVENTS[$key])||!is_int($start)||!in_array($op,['join','leave'],true))throw new RuntimeException('Choose a listed event and round.');
            $yard=Game::loadBase($owner,true);$run=$yard['calendarRounds'][$key]??null;
            if($run && $run['start']===$start && $run['playing']===($op==='join'))return self::summary($yard,$now);
            $revision=(int)($run['revision']??0);
            if(($input['revision']??null)!==$revision)throw new RuntimeException('Another tab changed this round. Reopen the calendar.');
            $busy=$pdo->prepare("SELECT id FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?) LIMIT 1");$busy->execute([$owner,$owner]);
            if($busy->fetchColumn() || ($yard['baiterPractice']['state']['status']??null)==='active')throw new RuntimeException('Finish your current battle before changing rounds.');
            if($op==='leave'){
                if(!$run||empty($run['playing'])||$run['start']!==$start)throw new RuntimeException('This event changed. Reopen the calendar.');
                $run['scheduled']=self::exchange($yard,$key,$run['personal']);
                unset($run['personal']);$run['playing']=false;
            }else{
                $round=null;foreach(self::dates($now) as $row)if($row['key']===$key&&$row['start']===$start&&$row['phase']==='active')$round=$row;
                if(!$round)throw new RuntimeException('This official event is outside its playing window.');
                if($error=self::eligibility($yard,$key))throw new RuntimeException($error);
                if($run && $run['start']!==$start && $run['playing'])self::exchange($yard,$key,$run['personal']);
                if(!$run||$run['start']!==$start)$run=['start'=>$start,'end'=>$round['end'],'posted'=>0];
                $run['personal']=self::exchange($yard,$key,$run['scheduled']??self::initial($key,$round));
                unset($run['scheduled']);$run['playing']=true;
            }
            $run['revision']=$revision+1;$yard['calendarRounds'][$key]=$run;Game::saveBase($owner,$yard);
            return self::summary($yard,$now);
        });
    }

    public static function summary(array $yard,int $now):array
    {
        $rounds=self::dates($now);$joined=[];
        foreach($yard['calendarRounds']??[] as $key=>$run)if($run['playing']){
            $joined[$key]=$run['start'];
            if(!array_filter($rounds,fn($r)=>$key===$r['key'] && $run['start']===$r['start']))$rounds[]=self::round($key,$run['start'],$now);
        }
        foreach($rounds as &$r){
            $run=$yard['calendarRounds'][$r['key']]??null;
            $r['joined']=($joined[$r['key']]??0)===$r['start'];$r['score']=($run['start']??0)===$r['start']?($run['posted']??0):0;
            $r['revision']=(int)($run['revision']??0);
            $r['gate']=self::eligibility($yard,$r['key']);
        }unset($r);
        return ['rounds'=>$rounds,'now'=>$now];
    }

    public static function leaders(int $owner,string $key,int $start):array
    {
        if(!isset(self::EVENTS[$key])||$start<1)throw new RuntimeException('Choose a calendar round.');
        $prefix=self::prefix($key,$start);$pdo=Db::pdo();
        $stmt=$pdo->prepare('SELECT u.id,u.name,CAST(s.value AS UNSIGNED) AS score FROM settings s JOIN users u ON u.id=CAST(SUBSTRING(s.name,?) AS UNSIGNED) WHERE s.name LIKE ? ORDER BY score DESC,u.id LIMIT 50');
        $stmt->execute([strlen($prefix)+1,$prefix.'%']);
        $mine=$pdo->prepare('SELECT value FROM settings WHERE name=?');$mine->execute([$prefix.$owner]);
        return ['leaders'=>$stmt->fetchAll(),'score'=>(int)$mine->fetchColumn()];
    }
}
