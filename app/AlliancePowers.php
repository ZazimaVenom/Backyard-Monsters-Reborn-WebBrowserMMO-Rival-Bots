<?php
declare(strict_types=1);
require_once __DIR__.'/AllianceFeed.php';

/** The original three alliance-wide timers, with member-funded charging and leader activation. */
final class AlliancePowers
{
    public const RULES=[
        'armament'=>['Armament',43200,604800],
        'conquest'=>['Conquest',21600,432000],
        'declarewar'=>['Declare War',43200,604800],
    ];
    private const PRICE=20;
    private static function query(PDO $db,string $sql,array $args):PDOStatement
    {
        $q=$db->prepare($sql);$q->execute($args);return $q;
    }
    public static function seed(PDO $db,int $group,int $now):void
    {
        $rows=[];foreach(self::RULES as $type=>$rule)$rows[$type]=['active'=>false,'end'=>$now+$rule[2],'revision'=>0];
        self::query($db,'INSERT IGNORE INTO settings(name,value) VALUES(?,?)',['alliance-power-'.$group,json_encode($rows,JSON_THROW_ON_ERROR)]);
    }
    private static function load(PDO $db,int $group,int $now,bool $lock):array
    {
        $name='alliance-power-'.$group;$sql='SELECT value FROM settings WHERE name=?'.($lock?' FOR UPDATE':'');
        $text=self::query($db,$sql,[$name])->fetchColumn();
        if($text===false){self::seed($db,$group,$now);$text=self::query($db,'SELECT value FROM settings WHERE name=? FOR UPDATE',[$name])->fetchColumn();}
        $rows=json_decode($text,true,512,JSON_THROW_ON_ERROR);
        foreach(self::RULES as $type=>$rule){
            $row=&$rows[$type];
            if($row['active']&&$row['end']<=$now){$row['active']=false;$row['end']+=$rule[2];}
            unset($row);
        }
        return $rows;
    }
    private static function save(PDO $db,int $group,array $rows):void
    {
        self::query($db,'UPDATE settings SET value=? WHERE name=?',[json_encode($rows,JSON_THROW_ON_ERROR),'alliance-power-'.$group]);
    }
    public static function board(PDO $db,int $actor,int $group,int $now):array
    {
        $membership=self::query($db,'SELECT a.leader_id FROM alliance_members m JOIN alliances a ON a.id=m.alliance_id WHERE m.user_id=? AND a.id=?',[$actor,$group])->fetchColumn();
        if(!$membership)return [];
        $balance=(int)self::query($db,"SELECT COALESCE(JSON_EXTRACT(state_json,'$.shiny'),0) FROM bases WHERE user_id=?",[$actor])->fetchColumn();
        $data=[];foreach(self::load($db,$group,$now,false) as $type=>$row){
            [$label,$duration,$recharge]=self::RULES[$type];
            $status=$row['active']?'running':($row['end']>$now?'charging':'ready');
            $data[$type]=['label'=>$label,'status'=>$status,'end'=>$row['end'],'revision'=>$row['revision'],'hours'=>$status==='charging'?(int)ceil(($row['end']-$now)/3600):0,
                'run'=>$duration,'recharge'=>$recharge,'last'=>$row['notice']??null];
        }
        return ['rows'=>$data,'price'=>self::PRICE,'shiny'=>$balance,'leader'=>(int)$membership===$actor,'now'=>$now];
    }
    public static function manage(int $actor,int $group,array $in):void
    {
        $type=$in['type']??null;$op=$in['op']??null;$token=$in['requestId']??null;$rev=$in['powerRevision']??null;
        if(!is_string($type)||!isset(self::RULES[$type])||!in_array($op,['powerCharge','powerActivate'],true)||!is_int($rev)||
            !is_string($token)||!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$token))throw new RuntimeException('Reopen the alliance power-up controls.');
        $hours=$op==='powerCharge'?($in['hours']??null):0;
        if(!is_int($hours)||($op==='powerCharge'&&($hours<1||$hours>100000)))throw new RuntimeException('Choose a positive whole number of recharge hours.');
        Db::transaction(static function(PDO $db)use($actor,$group,$type,$op,$token,$rev,$hours):void{
            $now=time();
            $user=self::query($db,'SELECT id,name,is_bot FROM users WHERE id=? FOR UPDATE',[$actor])->fetch();
            if(!$user||$user['is_bot'])throw new RuntimeException('Sign in as a player to manage alliance power-ups.');
            $base=Game::loadBase($actor,true);
            $leader=self::query($db,'SELECT leader_id FROM alliances WHERE id=? FOR UPDATE',[$group])->fetchColumn();
            $member=self::query($db,'SELECT alliance_id FROM alliance_members WHERE user_id=? FOR UPDATE',[$actor])->fetchColumn();
            if(!$leader||(int)$member!==$group)throw new RuntimeException('Join this alliance before using its power-ups.');
            if($op==='powerActivate'&&(int)$leader!==$actor)throw new RuntimeException('Only the current alliance leader can activate a power-up.');
            $rows=self::load($db,$group,$now,true);$row=&$rows[$type];
            if(($row['lastRequest']??null)===$token){
                if(($row['command']??null)!==[$actor,$op,$hours])throw new RuntimeException('That request belongs to a different power-up order.');
                return;
            }
            if($rev!==$row['revision'])throw new RuntimeException('This power-up changed. Refresh its board before spending Shiny.');
            if($row['active'])throw new RuntimeException('This power-up is already running.');
            if($op==='powerActivate'){
                if($row['end']>$now)throw new RuntimeException('This power-up is still charging.');
                self::activate($row,$type,$user['name'],$now);
            }else{
                if($row['end']<=$now)throw new RuntimeException('This power-up is already fully charged.');
                $bought=min($hours,(int)ceil(($row['end']-$now)/3600));$cost=$bought*self::PRICE;
                if((int)($base['shiny']??0)<$cost)throw new RuntimeException('You need more Shiny for that many recharge hours.');
                $base['shiny']-=$cost;$row['end']=max($now,$row['end']-$bought*3600);++$row['revision'];
                $row['notice']=['kind'=>'charge','by'=>$user['name'],'hours'=>$bought,'cost'=>$cost,'at'=>$now];Game::saveBase($actor,$base);
            }
            $label=self::RULES[$type][0];
            AllianceFeed::announce($db,$group,$actor,$op==='powerActivate'?'powerup_activated':'powerup_purchase',
                $op==='powerActivate'?'activated '.$label.'.':'contributed '.$cost.' Shiny to recharge '.$label.'.');
            $row['lastRequest']=$token;$row['command']=[$actor,$op,$hours];self::save($db,$group,$rows);
        });
    }
    private static function activate(array &$row,string $type,string $leader,int $now):void
    {
        $row['active']=true;$row['end']=$now+self::RULES[$type][1];++$row['revision'];$row['notice']=['kind'=>'activate','by'=>$leader,'at'=>$now];
    }

    /** Read the member's active powers at this instant. A battle keeps its copy through settlement. */
    public static function active(PDO $db,int $owner,int $now):array
    {
        if($owner<=0)return [];
        $text=self::query($db,"SELECT s.value FROM alliance_members m JOIN settings s ON s.name=CONCAT('alliance-power-',m.alliance_id) WHERE m.user_id=?",[$owner])->fetchColumn();
        if($text===false)return [];
        $out=[];foreach(json_decode($text,true,512,JSON_THROW_ON_ERROR) as $type=>$row)if($row['active']&&$row['end']>$now)$out[$type]=$row['end'];
        return $out;
    }

    public static function rangeBonus(int $id):int {return isset(self::active(Db::pdo(),$id,time())['declarewar'])?2:0;}

    /** Captures in the local map cost zero. Apply the source rounding to the actual fee if present. */
    public static function cost(int $baseCost,bool $conquest):int
    {
        return $conquest?(int)ceil(max(0,$baseCost)*.75):max(0,$baseCost);
    }

    public static function arm(array &$building):void
    {
        $type=$building['type'];
        if(Catalog::isTrap($type)){$building['_damage']*=1.25;$building['armament']='trap';return;}
        if(!Catalog::isWall($type) && !in_array($type,['sniper','laser','cannon','aerial','tesla','stronghold','railgun','spurtzcannon','diamondspurtz'],true))return;
        $building['_armamentScale']=1.5;
        foreach(['hp','max_hp','_initial_hp'] as $key)$building[$key]*=1.5;
        $building['armament']='armor';
    }

    public static function botTurn(int $keeper):void
    {
        $db=Db::pdo();$group=self::query($db,'SELECT a.id FROM alliances a JOIN users u ON u.id=a.leader_id WHERE a.leader_id=? AND u.is_bot=1 AND a.bot_charter IS NOT NULL',[$keeper])->fetchColumn();
        if(!$group||!WorldActivity::clock()['online'])return;
        Db::transaction(static function(PDO $db)use($keeper,$group):void{
            $now=time();$info=self::query($db,'SELECT a.leader_id,u.name,u.is_bot FROM alliances a JOIN users u ON u.id=a.leader_id WHERE a.id=? FOR UPDATE',[$group])->fetch();
            if(!$info||!$info['is_bot']||(int)$info['leader_id']!==$keeper||!WorldActivity::clock()['online'])return;
            $rows=self::load($db,(int)$group,$now,true);
            foreach($rows as $type=>&$row){
                if(!$row['active']&&$row['end']<=$now){self::activate($row,$type,$info['name'],$now);self::save($db,(int)$group,$rows);AllianceFeed::announce($db,(int)$group,$keeper,'powerup_activated','activated '.self::RULES[$type][0].'.');break;}
            }unset($row);
        });
    }
}
