<?php
declare(strict_types=1);
require_once __DIR__.'/AllianceWars.php';
require_once __DIR__.'/QuestSocial.php';
require_once __DIR__.'/AlliancePowers.php';

final class Alliances
{
    public const CAPACITY = 50;
    private const PAGE_SIZE = 8;
    private const CHARTERS = ['Bramblethorn Pact','Cinderwing Company','Moonfen Keepers','Ironbark Circle'];
    private const BOT_SEATS = 8;

    private static function query(PDO $db, string $sql, array $args = []): PDOStatement
    {
        $query = $db->prepare($sql);
        $query->execute($args);
        return $query;
    }

    private static function id(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if ((!is_int($value) && !is_string($value)) || !ctype_digit((string)$value)
                || ($id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])) === false) {
            throw new RuntimeException('Select a valid alliance or keeper.');
        }
        return $id;
    }

    public static function validatedText(mixed $value, int $min, int $max): string
    {
        if (!is_string($value) || !preg_match('//u', $value) || preg_match('/[\p{C}]/u', $value)) {
            throw new RuntimeException('Enter plain text on one line.');
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        $length = preg_match_all('/./us', $value);
        if ($length < $min || $length > $max) {
            throw new RuntimeException("Use $min to $max characters.");
        }
        return $value;
    }

    private static function add(PDO $db, int $group, int $user,bool $founder=false): void
    {
        $count = (int)self::query($db, 'SELECT COUNT(*) FROM alliance_members WHERE alliance_id=?', [$group])->fetchColumn();
        if ($count >= self::CAPACITY) { throw new RuntimeException('This alliance has reached its 50-keeper limit.'); }
        self::query($db, 'INSERT INTO alliance_members (user_id,alliance_id,joined_at) VALUES (?,?,?)', [$user,$group,time()]);
        self::query($db, 'DELETE FROM alliance_requests WHERE user_id=?', [$user]);
        QuestSocial::joined($user);
        AllianceFeed::announce($db,$group,$user,$founder?'created':'joined',$founder?'founded the alliance.':'joined the alliance.');
    }

    /** Only the presence-gated scheduler calls this on a claimed bot turn. */
    public static function botTurn(int $id): void
    {
        AlliancePowers::botTurn($id);
        if($id%8!==1||!WorldActivity::clock()['online']){return;}
        Db::transaction(static function(PDO $db) use ($id):void {
            if((int)self::query($db,'SELECT is_bot FROM users WHERE id=? FOR UPDATE',[$id])->fetchColumn()!==1){return;}
            if(self::query($db,'SELECT alliance_id FROM alliance_members WHERE user_id=?',[$id])->fetchColumn()){return;}
            $slot=intdiv($id,8)%count(self::CHARTERS);
            $group=self::query($db,'SELECT id FROM alliances WHERE bot_charter=? FOR UPDATE',[$slot])->fetchColumn();
            $founder=!$group;
            if(!$group){
                $baseName=self::CHARTERS[$slot];$name=$baseName;
                for($suffix=2;self::query($db,'SELECT id FROM alliances WHERE name=?',[$name])->fetchColumn();$suffix++){
                    $name=$baseName.' '.$suffix;
                }
                try {
                    self::query($db,'INSERT INTO alliances(name,description,leader_id,bot_charter,created_at) VALUES(?,?,?,?,?)',
                        [$name,'A bot-led clan. We consider join requests and war challenges while keepers are online.',$id,$slot,time()]);
                } catch(PDOException $error) {
                    if((int)($error->errorInfo[1]??0)===1062){return;}
                    throw $error;
                }
                $group=(int)$db->lastInsertId();AlliancePowers::seed($db,$group,time());
            } else {
                $seats=(int)self::query($db,'SELECT COUNT(*) FROM alliance_members m JOIN users u ON u.id=m.user_id WHERE m.alliance_id=? AND u.is_bot=1',[$group])->fetchColumn();
                $count=(int)self::query($db,'SELECT COUNT(*) FROM alliance_members WHERE alliance_id=?',[$group])->fetchColumn();
                if($seats>=self::BOT_SEATS||$count>=self::CAPACITY){return;}
            }
            self::add($db,(int)$group,$id,$founder);
            self::query($db,'UPDATE alliances SET revision=revision+1 WHERE id=?',[$group]);
        });
    }

    /** Bounded diplomacy during the next available bot turn, including a clan leader's reply. */
    public static function botDiplomacy(): void
    {
        if(!WorldActivity::clock()['online']){return;}
        $db=Db::pdo();
        $war=self::query($db,"SELECT w.id,w.challenger_id,w.opponent_id,a.leader_id FROM alliance_wars w
            JOIN alliances a ON a.id=w.opponent_id JOIN users leader ON leader.id=a.leader_id AND leader.is_bot=1
            WHERE a.bot_charter IS NOT NULL AND w.challenger_id IS NOT NULL AND w.state='pending' AND w.ends_at>?
            ORDER BY w.created_at,w.id LIMIT 1",[time()])->fetch();
        if($war){
            try{
                $op=AllianceWars::active($db,(int)$war['challenger_id'],time())||AllianceWars::active($db,(int)$war['opponent_id'],time())
                    ?'warDecline':'warAccept';
                AllianceWars::manage((int)$war['leader_id'],$op,(int)$war['opponent_id'],(int)$war['challenger_id'],(int)$war['id'],null);
            }catch(PDOException $e){throw $e;}catch(RuntimeException){/* A concurrent player or expiry resolved the offer. */}
        }
        $r=self::query($db,"SELECT r.id,r.alliance_id,r.user_id FROM alliance_requests r JOIN alliances a ON a.id=r.alliance_id
            JOIN users leader ON leader.id=a.leader_id AND leader.is_bot=1
            WHERE r.kind='request' AND a.bot_charter IS NOT NULL ORDER BY r.created_at,r.id LIMIT 1",[])->fetch();
        if(!$r){return;}
        Db::transaction(static function(PDO $db) use($r):void{
            $target=(int)$r['user_id'];$group=(int)$r['alliance_id'];
            self::query($db,'SELECT id FROM users WHERE id=? FOR UPDATE',[$target])->fetchColumn();
            $charter=self::query($db,'SELECT bot_charter FROM alliances WHERE id=? FOR UPDATE',[$group])->fetch();
            $request=self::query($db,"SELECT id FROM alliance_requests WHERE id=? AND kind='request' FOR UPDATE",[$r['id']])->fetchColumn();
            if(!$charter||$charter['bot_charter']===null||!$request){return;}
            $total=(int)self::query($db,'SELECT COUNT(*) FROM alliance_members WHERE alliance_id=?',[$group])->fetchColumn();
            $existing=self::query($db,'SELECT alliance_id FROM alliance_members WHERE user_id=?',[$target])->fetchColumn();
            if($total>=self::CAPACITY||$existing){self::query($db,'DELETE FROM alliance_requests WHERE id=?',[$request]);return;}
            self::add($db,$group,$target);
            self::query($db,'UPDATE alliances SET revision=revision+1 WHERE id=?',[$group]);
        });
    }

    /** Every roster writer takes keeper locks in ascending order, then the alliance row. */
    public static function manage(int $actor, array $input): array
    {
        $op = $input['op'] ?? '';
        if($op==='message'){
            $group=self::id($input,'allianceId');AllianceFeed::message($actor,$group,$input);return self::browse($actor,['allianceId'=>$group]);
        }
        if(in_array($op,['powerCharge','powerActivate'],true)){
            $group=self::id($input,'allianceId');AlliancePowers::manage($actor,$group,$input);return self::browse($actor,['allianceId'=>$group]);
        }
        if (in_array($op,['stance','warOffer','warAccept','warWithdraw','warDecline'],true)) {
            $own=self::id($input,'allianceId');$other=self::id($input,'otherId');
            $warId=in_array($op,['warAccept','warWithdraw','warDecline'],true)?self::id($input,'warId'):0;
            AllianceWars::manage($actor,$op,$own,$other,$warId,is_string($input['stance']??null)?$input['stance']:null);
            return self::browse($actor,['allianceId'=>$other]);
        }
        if (!in_array($op,['create','edit','request','invite','accept','decline','withdraw','kick','transfer','leave'],true)) {
            throw new RuntimeException('Unknown alliance action.');
        }
        $group = $op==='create' ? 0 : self::id($input,'allianceId');
        $target = in_array($op,['invite','accept','decline','withdraw','kick','transfer'],true) ? self::id($input,'keeperId') : $actor;
        $view = Db::transaction(static function(PDO $db) use ($actor,$target,$group,$input,$op): int {
            $keepers = [];
            foreach (array_unique([min($actor,$target),max($actor,$target)]) as $id) {
                $row = self::query($db, 'SELECT id,is_bot FROM users WHERE id=? FOR UPDATE', [$id])->fetch();
                if (!$row) { throw new RuntimeException('That keeper could not be found.'); }
                $keepers[$id] = $row;
            }
            if ((int)$keepers[$actor]['is_bot']) { throw new RuntimeException('Sign in as a player to manage an alliance.'); }
            if ($op==='create') {
                if (self::query($db,'SELECT user_id FROM alliance_members WHERE user_id=?',[$actor])->fetchColumn()) {
                    throw new RuntimeException('Leave your current alliance before founding another.');
                }
                $name = self::validatedText($input['name']??null,3,30);
                $description = self::validatedText($input['description']??'',0,200);
                try {
                    self::query($db,'INSERT INTO alliances(name,description,leader_id,created_at) VALUES(?,?,?,?)',[$name,$description,$actor,time()]);
                } catch (PDOException $error) {
                    if ((int)($error->errorInfo[1]??0)===1062) {throw new RuntimeException('That alliance name is already taken.');}
                    throw $error;
                }
                $newId = (int)$db->lastInsertId();AlliancePowers::seed($db,$newId,time());
                self::add($db,$newId,$actor,true);
                return $newId;
            }
            $alliance = self::query($db,'SELECT leader_id,revision FROM alliances WHERE id=? FOR UPDATE',[$group])->fetch();
            if (!$alliance) { throw new RuntimeException('That alliance has disbanded.'); }
            $actorGroup = (int)self::query($db,'SELECT alliance_id FROM alliance_members WHERE user_id=?',[$actor])->fetchColumn();
            $targetGroup = $target===$actor ? $actorGroup :
                (int)self::query($db,'SELECT alliance_id FROM alliance_members WHERE user_id=?',[$target])->fetchColumn();
            $leader = (int)$alliance['leader_id']===$actor && $actorGroup===$group;
            if (in_array($op,['edit','invite','kick','transfer'],true) && !$leader) {
                throw new RuntimeException('Only the alliance leader can do that.');
            }
            if (in_array($op,['edit','kick','transfer','leave'],true)
                    && (int)$alliance['revision']!==self::id($input,'revision')) {
                throw new RuntimeException('The roster changed. Refresh the alliance and review your choice.');
            }
            switch ($op) {
                case 'edit':
                    $description = self::validatedText($input['description']??'',0,200);
                    self::query($db,'UPDATE alliances SET description=? WHERE id=?',[$description,$group]);
                    break;
                case 'request':
                case 'invite':
                    if ($targetGroup) { throw new RuntimeException('This keeper already belongs to an alliance.'); }
                    if ($op==='invite' && (int)$keepers[$target]['is_bot']) {
                        self::add($db,$group,$target);
                        break;
                    }
                    if ((int)self::query($db,'SELECT COUNT(*) FROM alliance_members WHERE alliance_id=?',[$group])->fetchColumn()>=self::CAPACITY) {
                        throw new RuntimeException('This alliance has reached its 50-keeper limit.');
                    }
                    $previous = self::query($db,'SELECT kind FROM alliance_requests WHERE alliance_id=? AND user_id=?',[$group,$target])->fetchColumn();
                    if ($previous && $op!==$previous) {throw new RuntimeException('A matching invitation or request is already waiting. Open the inbox to answer it.');}
                    if (!$previous) {
                        self::query($db,'INSERT INTO alliance_requests(alliance_id,user_id,kind,created_at) VALUES(?,?,?,?)',[$group,$target,$op,time()]);
                    }
                    return $group;
                case 'withdraw':
                case 'accept':
                case 'decline':
                    $request = self::query($db,'SELECT id,kind FROM alliance_requests WHERE alliance_id=? AND user_id=? FOR UPDATE',[$group,$target])->fetch();
                    if (!$request || (int)$request['id']!==self::id($input,'requestId')) {throw new RuntimeException('That request is no longer pending.');}
                    $originator = $request['kind']==='request' ? $actor===$target : $leader;
                    $recipient = $request['kind']==='request' ? $leader : $actor===$target;
                    if (!(($op==='withdraw' && $originator) || ($op!=='withdraw' && $recipient))) {throw new RuntimeException('Only the keepers involved can answer this request.');}
                    if ($op==='accept') {
                        if ($targetGroup) {throw new RuntimeException('This keeper already belongs to an alliance.');}
                        self::add($db,$group,$target);
                        break;
                    }
                    self::query($db,'DELETE FROM alliance_requests WHERE id=?',[$request['id']]);
                    return $group;
                case 'kick':
                case 'transfer':
                    if ($actor===$target || $targetGroup!==$group) {throw new RuntimeException('Choose another member of this alliance.');}
                    if ($op==='transfer') {
                        if ((int)$keepers[$target]['is_bot']) {throw new RuntimeException('Choose a player who can manage the alliance.');}
                        self::query($db,'UPDATE alliances SET leader_id=? WHERE id=?',[$target,$group]);
                        AllianceFeed::announce($db,$group,$target,'promoted','became the alliance leader.');
                    } else {
                        AllianceFeed::announce($db,$group,$target,'kicked','was removed from the alliance.');
                        QuestSocial::joined($target);
                        self::query($db,'DELETE FROM alliance_members WHERE user_id=? AND alliance_id=?',[$target,$group]);
                    }
                    break;
                case 'leave':
                    if ($actorGroup!==$group) {throw new RuntimeException('You do not belong to this alliance.');}
                    if ($leader) {
                        if ((int)self::query($db,'SELECT COUNT(*) FROM alliance_members WHERE alliance_id=?',[$group])->fetchColumn()>1) {
                            throw new RuntimeException('Transfer leadership to another player or remove the remaining members before leaving.');
                        }
                        if (AllianceWars::active($db,$group,time())) {throw new RuntimeException('An alliance cannot disband during an active war.');}
                        QuestSocial::joined($actor);
                        self::query($db,'DELETE FROM settings WHERE name IN (?,?)',['alliance-power-'.$group,'alliance-feed-'.$group]);
                        self::query($db,'DELETE FROM alliances WHERE id=?',[$group]);
                        return 0;
                    }
                    AllianceFeed::announce($db,$group,$actor,'left','left the alliance.');
                    QuestSocial::joined($actor);
                    self::query($db,'DELETE FROM alliance_members WHERE user_id=? AND alliance_id=?',[$actor,$group]);
                    break;
            }
            self::query($db,'UPDATE alliances SET revision=revision+1 WHERE id=?',[$group]);
            return $group;
        });
        return self::browse($actor,$view ? ['allianceId'=>$view] : []);
    }

    public static function browse(int $actor, array $params): array
    {
        if (($params['feedOnly']??null)==='1') { return AllianceFeed::poll($actor,self::id($params,'allianceId')); }
        $search = self::validatedText($params['search']??'',0,40);
        $keeperSearch = self::validatedText($params['keeperSearch']??'',0,40);
        $page = isset($params['page']) ? self::id($params,'page') : 1;
        $warPage = isset($params['warPage']) ? self::id($params,'warPage') : 1;
        $focus = isset($params['allianceId']) ? self::id($params,'allianceId') : null;
        return Db::transaction(static function(PDO $db) use ($actor,$search,$keeperSearch,$page,$focus,$warPage): array {
            $membership = (int)self::query($db,'SELECT alliance_id FROM alliance_members WHERE user_id=?',[$actor])->fetchColumn();
            $total = (int)self::query($db,'SELECT COUNT(*) FROM alliances WHERE LOCATE(?,name)>0',[$search])->fetchColumn();
            $pages = max(1,(int)ceil($total/self::PAGE_SIZE));
            $page = min($page,$pages);
            $ranked = 'SELECT a.id,a.name,a.description,a.leader_id,u.name AS leader_name,u.is_bot AS bot_led,a.revision,
                COUNT(m.user_id) AS members,COALESCE(SUM(k.rank_points),0) AS points,
                ROW_NUMBER() OVER (ORDER BY COALESCE(SUM(k.rank_points),0) DESC,a.id) AS standing
                FROM alliances a JOIN users u ON u.id=a.leader_id
                LEFT JOIN alliance_members m ON m.alliance_id=a.id LEFT JOIN users k ON k.id=m.user_id
                GROUP BY a.id,a.name,a.description,a.leader_id,u.name,u.is_bot,a.revision';
            $list = self::query($db,"SELECT * FROM ($ranked) league WHERE LOCATE(?,name)>0 ORDER BY standing LIMIT ".self::PAGE_SIZE.' OFFSET '.(($page-1)*self::PAGE_SIZE),[$search])->fetchAll();
            $focus ??= $membership;
            $detail = $focus ? self::query($db,"SELECT * FROM ($ranked) league WHERE id=?",[$focus])->fetch() : false;
            $roster=[];$suggestions=[];
            $leader = $detail && $membership===$focus && (int)$detail['leader_id']===$actor;
            if ($detail) {
                $roster = self::query($db,'SELECT u.id,u.name,u.is_bot,u.rank_points,m.joined_at
                    FROM alliance_members m JOIN users u ON u.id=m.user_id WHERE m.alliance_id=?
                    ORDER BY (u.id=?) DESC,u.rank_points DESC,u.id',[$focus,$detail['leader_id']])->fetchAll();
                if ($leader && (int)$detail['members']<self::CAPACITY) {
                    $suggestions = self::query($db,'SELECT u.id,u.name,u.is_bot,u.rank_points FROM users u
                        WHERE NOT EXISTS (SELECT 1 FROM alliance_members m WHERE m.user_id=u.id)
                        AND NOT EXISTS (SELECT 1 FROM alliance_requests r WHERE r.user_id=u.id AND r.alliance_id=?)
                        AND LOCATE(?,u.name)>0 ORDER BY u.is_bot,u.rank_points DESC,u.id LIMIT 12',[$focus,$keeperSearch])->fetchAll();
                }
            }
            $inbox = self::query($db,'SELECT r.id,r.alliance_id,r.user_id,r.kind,a.name AS alliance_name,u.name AS keeper_name
                FROM alliance_requests r JOIN alliances a ON a.id=r.alliance_id JOIN users u ON u.id=r.user_id
                WHERE r.user_id=? OR a.leader_id=? ORDER BY r.created_at,r.id',[$actor,$actor])->fetchAll();
            return ['feed'=>$membership && $membership===(int)$focus?AllianceFeed::read($db,$membership):[],'powers'=>$membership && $membership===(int)$focus?AlliancePowers::board($db,$actor,$membership,time()):null,'mine'=>$membership?:null,'groups'=>$list,'detail'=>$detail?:null,'roster'=>$roster,'suggestions'=>$suggestions,
                'diplomacy'=>AllianceWars::board($db,$membership,(int)$focus,$actor,$warPage),'inbox'=>$inbox,'page'=>$page,'pages'=>$pages,'total'=>$total,'capacity'=>self::CAPACITY];
        });
    }
}
