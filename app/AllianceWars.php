<?php
declare(strict_types=1);
require_once __DIR__.'/AllianceFeed.php';

final class AllianceWars
{
    public const DURATION = 12*3600;
    private const OFFER_TIME = 24*3600;

    private static function query(PDO $db,string $sql,array $args): PDOStatement
    {
        $q=$db->prepare($sql);$q->execute($args);return $q;
    }

    public static function active(PDO $db,int $group,int $now): bool
    {
        return (bool)self::query($db,"SELECT id FROM alliance_wars WHERE (challenger_id=? OR opponent_id=?) AND state='active' AND ends_at>? LIMIT 1",[$group,$group,$now])->fetchColumn();
    }

    /** Hold both alliance rows in ascending order before deciding an offer or changing flags. */
    public static function manage(int $actor,string $op,int $own,int $other,int $warId,?string $stance): void
    {
        if($own===$other){throw new RuntimeException('Choose a different alliance.');}
        Db::transaction(static function(PDO $db) use ($actor,$op,$own,$other,$warId,$stance):void {
            $groups=[];
            foreach([min($own,$other),max($own,$other)] as $id) {
                $row=self::query($db,'SELECT name,leader_id FROM alliances WHERE id=? FOR UPDATE',[$id])->fetch();
                if(!$row){throw new RuntimeException('That alliance has disbanded.');}
                $groups[$id]=$row;
            }
            if((int)$groups[$own]['leader_id']!==$actor){throw new RuntimeException('Only your alliance leader can manage diplomacy.');}
            $now=time();
            if($op==='stance'){
                if(!in_array($stance,['ally','foe','neutral'],true)){throw new RuntimeException('Choose Ally, Neutral or Foe.');}
                $previous=self::query($db,'SELECT stance FROM alliance_stances WHERE alliance_id=? AND target_id=?',[$own,$other])->fetchColumn()?:'neutral';
                if ($previous===$stance) { return; }
                if($stance==='neutral'){
                    self::query($db,'DELETE FROM alliance_stances WHERE alliance_id=? AND target_id=?',[$own,$other]);
                }else{
                    self::query($db,'INSERT INTO alliance_stances(alliance_id,target_id,stance) VALUES(?,?,?) ON DUPLICATE KEY UPDATE stance=VALUES(stance)',[$own,$other,$stance]);
                }
                AllianceFeed::announce($db,$own,$actor,'relationship','flagged '.$groups[$other]['name'].' as '.$stance.'.');
                return;
            }
            if($op==='warOffer'){
                if(self::active($db,$own,$now)||self::active($db,$other,$now)){throw new RuntimeException('An alliance in this pair is already fighting a war.');}
                $offer=self::query($db,"SELECT id FROM alliance_wars WHERE state='pending' AND ends_at>? AND ((challenger_id=? AND opponent_id=?) OR (challenger_id=? AND opponent_id=?))",[$now,$own,$other,$other,$own])->fetchColumn();
                if($offer){throw new RuntimeException('A challenge is already pending between these alliances.');}
                self::query($db,"INSERT INTO alliance_wars(challenger_id,opponent_id,challenger_name,opponent_name,state,created_at,ends_at) VALUES(?,?,?,?,'pending',?,?)",[$own,$other,$groups[$own]['name'],$groups[$other]['name'],$now,$now+self::OFFER_TIME]);
                return;
            }
            $w=self::query($db,'SELECT challenger_id,opponent_id,state,ends_at FROM alliance_wars WHERE id=? FOR UPDATE',[$warId])->fetch();
            if(!$w||$w['state']!=='pending'||(int)$w['ends_at']<=$now){throw new RuntimeException('That challenge is no longer pending.');}
            $from=(int)$w['challenger_id'];$to=(int)$w['opponent_id'];
            $withdraw=$op==='warWithdraw';
            if(($withdraw?$from:$to)!==$own||($withdraw?$to:$from)!==$other){throw new RuntimeException('Only the leader receiving a challenge can answer it. The sender may withdraw it.');}
            if($withdraw||$op==='warDecline'){
                self::query($db,'UPDATE alliance_wars SET state=? WHERE id=?',[$withdraw?'withdrawn':'declined',$warId]);
                return;
            }
            if($op!=='warAccept'){throw new RuntimeException('Choose a valid war action.');}
            if(self::active($db,$own,$now)||self::active($db,$other,$now)){throw new RuntimeException('One of these alliances is already fighting a war.');}
            $rosters=[];
            foreach([$from,$to] as $id){
                $rosters[]=array_map('intval',self::query($db,'SELECT user_id FROM alliance_members WHERE alliance_id=? ORDER BY user_id',[$id])->fetchAll(PDO::FETCH_COLUMN));
            }
            self::query($db,"UPDATE alliance_wars SET state='active',starts_at=?,ends_at=?,rosters=? WHERE id=?",[$now,$now+self::DURATION,json_encode($rosters,JSON_THROW_ON_ERROR),$warId]);
        });
    }

    /** A settled war point needs the marker written by a newly started qualifying invasion. */
    public static function qualify(PDO $db,int $attacker,int $defender,int $now): ?array
    {
        if($defender<=0||$defender===$attacker){return null;}
        $war=self::query($db,"SELECT w.id,w.challenger_id,w.opponent_id,w.ends_at,w.rosters,a.alliance_id AS attacking
            FROM alliance_members a JOIN alliance_members d ON d.user_id=? AND d.alliance_id<>a.alliance_id
            JOIN alliance_wars w ON ((w.challenger_id=a.alliance_id AND w.opponent_id=d.alliance_id)
              OR (w.opponent_id=a.alliance_id AND w.challenger_id=d.alliance_id))
            WHERE a.user_id=? AND w.state='active' AND w.starts_at<=? AND w.ends_at>? LIMIT 1",[$defender,$attacker,$now,$now])->fetch();
        if(!$war){return null;}
        $side=(int)$war['attacking']===(int)$war['challenger_id']?0:1;
        $rosters=json_decode($war['rosters'],true,512,JSON_THROW_ON_ERROR);
        if(!in_array($attacker,$rosters[$side],true)||!in_array($defender,$rosters[1-$side],true)){return null;}
        return ['id'=>(int)$war['id'],'attackerSide'=>$side,'endsAt'=>(int)$war['ends_at']];
    }

    /** Called only inside server battle settlement. A database retry cannot register a second point. */
    public static function credit(PDO $db,array $battle,int $now): ?array
    {
        $mark=$battle['allianceWar']??null;
        if(!$mark||$now>=$mark['endsAt']||$battle['status']!=='finished'||$battle['defender']['id']<=0){return null;}
        $deployed=false;
        foreach($battle['units'] as $u){if(($u['team']??'attacker')==='attacker'){$deployed=true;break;}}
        if(!$deployed){return null;}
        $side=$battle['result']['attackerWon']?$mark['attackerSide']:1-$mark['attackerSide'];
        // The battle row and the regular win/loss update are locked in the enclosing transaction.
        self::query($db,"INSERT INTO alliance_war_battles(battle_id,war_id,winner_side,finished_at)
            SELECT ?,id,?,? FROM alliance_wars WHERE id=? AND state='active' AND starts_at<=? AND ends_at>?
            ON DUPLICATE KEY UPDATE battle_id=VALUES(battle_id)",[$battle['id'],$side,$now,$mark['id'],$battle['created_at'],$now]);
        $saved=self::query($db,'SELECT war_id,winner_side FROM alliance_war_battles WHERE battle_id=?',[$battle['id']])->fetch();
        return $saved?['id'=>(int)$saved['war_id'],'winnerSide'=>(int)$saved['winner_side'],'points'=>1]:null;
    }

    /** Offer real opposing keepers first when a combat-ready bot already has a scheduled home raid. */
    public static function enemyHomes(PDO $db,int $actor,bool $allowHuman,int $pivot,int $now): array
    {
        $w=self::query($db,"SELECT w.challenger_id,w.opponent_id,w.rosters,m.alliance_id FROM alliance_members m
            JOIN alliance_wars w ON w.challenger_id=m.alliance_id OR w.opponent_id=m.alliance_id
            WHERE m.user_id=? AND w.state='active' AND w.starts_at<=? AND w.ends_at>? LIMIT 1",[$actor,$now,$now])->fetch();
        if(!$w){return [];}
        $from=(int)$w['alliance_id']===(int)$w['challenger_id'];
        $sides=json_decode($w['rosters'],true,512,JSON_THROW_ON_ERROR);
        if(!in_array($actor,$sides[$from?0:1],true)){return [];}
        $opponents=$sides[$from?1:0];
        if(!$opponents){return [];}
        $marks=implode(',',array_fill(0,count($opponents),'?'));
        $sql="SELECT u.id,u.is_bot,b.state_json FROM alliance_members m JOIN users u ON u.id=m.user_id JOIN bases b ON b.user_id=u.id
              WHERE m.alliance_id=? AND u.id IN ($marks)".($allowHuman?'':' AND u.is_bot=1').' ORDER BY (u.id>=?) DESC,u.id LIMIT 16';
        return self::query($db,$sql,array_merge([$from?$w['opponent_id']:$w['challenger_id']],$opponents,[$pivot]))->fetchAll();
    }

    public static function board(PDO $db,int $memberGroup,int $focus,int $actor,int $page): array
    {
        $now=time();
        $involves=$focus?:$memberGroup;
        $leaderId=$memberGroup?(int)self::query($db,'SELECT leader_id FROM alliances WHERE id=?',[$memberGroup])->fetchColumn():0;
        $stance=($memberGroup&&$focus&&$memberGroup!==$focus)
            ?self::query($db,'SELECT stance FROM alliance_stances WHERE alliance_id=? AND target_id=?',[$memberGroup,$focus])->fetchColumn():false;
        $result=['canLead'=>$actor===$leaderId,'stance'=>$stance?:'neutral','wars'=>[],'now'=>$now];
        if(!$involves){return $result;}
        $total=(int)self::query($db,'SELECT COUNT(*) FROM alliance_wars WHERE challenger_id=? OR opponent_id=?',[$involves,$involves])->fetchColumn();
        $result['pages']=max(1,(int)ceil($total/12));
        $result['page']=$page=min($page,$result['pages']);
        $result['canChallenge']=false;
        if($memberGroup&&$memberGroup!==$involves&&$actor===$leaderId&&!self::active($db,$memberGroup,$now)&&!self::active($db,$involves,$now)){
            $pending=self::query($db,"SELECT id FROM alliance_wars WHERE state='pending' AND ends_at>? AND ((challenger_id=? AND opponent_id=?) OR (challenger_id=? AND opponent_id=?)) LIMIT 1",[$now,$memberGroup,$involves,$involves,$memberGroup])->fetchColumn();
            $result['canChallenge']=!$pending;
        }
        // Current engagements first, with all earlier results available through history pages.
        $rows=self::query($db,"SELECT w.id,w.challenger_id,w.opponent_id,w.challenger_name,w.opponent_name,w.state,w.ends_at,w.starts_at,w.rosters,
                (SELECT COUNT(*) FROM alliance_war_battles b WHERE b.war_id=w.id AND b.winner_side=0) AS from_score,
                (SELECT COUNT(*) FROM alliance_war_battles b WHERE b.war_id=w.id AND b.winner_side=1) AS to_score
            FROM alliance_wars w WHERE w.challenger_id=? OR w.opponent_id=?
            ORDER BY (w.state='active' AND w.ends_at>?) DESC, (w.state='pending' AND w.ends_at>?) DESC,w.id DESC LIMIT 12 OFFSET ".(($page-1)*12),[$involves,$involves,$now,$now])->fetchAll();
        foreach($rows as $w){
            $state=$w['state'];
            if($state==='pending'&&(!$w['challenger_id']||!$w['opponent_id'])){$state='withdrawn';}
            if((int)$w['ends_at']<=$now){$state=match($state){'pending'=>'expired','active'=>'finished',default=>$state};}
            $targets=[];
            if($state==='active'&&in_array($memberGroup,[(int)$w['challenger_id'],(int)$w['opponent_id']],true)){
                $side=$memberGroup===(int)$w['challenger_id']?0:1;
                $rosters=json_decode($w['rosters'],true,512,JSON_THROW_ON_ERROR);
                if(in_array($actor,$rosters[$side],true)){
                    $clock=WorldActivity::clock();
                    $viewerBusy=(bool)self::query($db,"SELECT id FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?) LIMIT 1",[$actor,$actor])->fetchColumn();
                    $enemies=self::query($db,"SELECT u.id,u.name,u.is_bot,b.state_json,
                        EXISTS(SELECT 1 FROM battles x WHERE x.status='active' AND (x.attacker_id=u.id OR x.defender_id=u.id)) AS busy
                        FROM alliance_members m JOIN users u ON u.id=m.user_id JOIN bases b ON b.user_id=u.id
                        WHERE m.alliance_id=? ORDER BY u.name",[$side===0?$w['opponent_id']:$w['challenger_id']])->fetchAll();
                    foreach($enemies as $enemy){
                        if(!in_array((int)$enemy['id'],$rosters[1-$side],true)){continue;}
                        $yard=json_decode($enemy['state_json'],true,512,JSON_THROW_ON_ERROR);
                        if((bool)$enemy['is_bot']){WorldActivity::shiftBase($yard,$clock);}
                        $blocked=$viewerBusy?'Your battle is active':((bool)$enemy['busy']?'In battle':'');
                        if(!$blocked && Truces::activeUntil($yard,$actor,$now)){$blocked='Truce';}
                        if(!$blocked && ($yard['shield_until']??0)>$now){$blocked='Shielded';}
                        $targets[]=['id'=>(int)$enemy['id'],'name'=>$enemy['name'],'is_bot'=>(bool)$enemy['is_bot'],'blocked'=>$blocked];
                    }
                }
            }
            unset($w['rosters']);$w['state']=$state;$w['targets']=$targets;
            $result['wars'][]=$w;
        }
        return $result;
    }
}
