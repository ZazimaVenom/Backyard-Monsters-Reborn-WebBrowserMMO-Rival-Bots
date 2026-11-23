<?php
declare(strict_types=1);

final class Neighbors
{
    private static function rows(string $sql, array $params): array
    {
        $query = Db::pdo()->prepare($sql);
        $query->execute($params);
        return $query->fetchAll();
    }

    private static function update(PDO $pdo, string $sql, array $params): void
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
    }

    private static function number(array $input, string $name): int
    {
        $number = filter_var($input[$name] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($number === false) { throw new RuntimeException('Choose a valid keeper or item.'); }
        return $number;
    }

    public static function overview(int $owner, string $search = ''): array
    {
        $search = trim($search);
        $friends = self::rows(
            'SELECT f.id AS link_id,u.id,u.name,f.requested_by,f.accepted,
              (SELECT MAX(g.sent_at)+86400 FROM keeper_gifts g WHERE g.sender_id=? AND g.recipient_id=u.id) AS gift_after
             FROM keeper_friends f JOIN users u ON u.id=IF(f.low_id=?,f.high_id,f.low_id)
             WHERE f.low_id=? OR f.high_id=? ORDER BY f.accepted,u.name', [$owner,$owner,$owner,$owner]);
        $gifts = self::rows(
            'SELECT g.id,u.name,g.remaining,g.resource FROM keeper_gifts g JOIN users u ON u.id=g.sender_id
             WHERE g.recipient_id=? AND g.claimed_at IS NULL ORDER BY g.id', [$owner]);
        // Keep the contents of unopened mystery sacks hidden.
        foreach ($gifts as &$gift) {
            if ($gift['remaining'] === null) { unset($gift['resource']); }
        }
        unset($gift);
        $matches = [];
        if (strlen($search) >= 2) {
            $term = strtr(substr($search,0,64),['!'=>'!!','%'=>'!%','_'=>'!_']);
            $pattern = (strlen($term) >= 3 ? '%' : '').$term.'%';
            $matches = self::rows("SELECT id,name FROM users WHERE is_bot=0 AND id<>? AND name LIKE ? ESCAPE '!' ORDER BY name LIMIT 25", [$owner,$pattern]);
        }
        $threads = self::rows(
            'SELECT u.id,u.name,t.last_id,t.unread FROM (
               SELECT IF(sender_id=?,recipient_id,sender_id) AS keeper,MAX(id) AS last_id,
                SUM(IF(recipient_id=? AND read_at IS NULL,1,0)) AS unread
               FROM keeper_mail WHERE sender_id=? OR recipient_id=? GROUP BY keeper
             ) t JOIN users u ON u.id=t.keeper ORDER BY t.last_id DESC', [$owner,$owner,$owner,$owner]);
        return ['owner'=>$owner,'friends'=>$friends,'gifts'=>$gifts,'threads'=>$threads,'matches'=>$matches,'now'=>time()];
    }

    public static function thread(int $owner, int $other, ?int $before): array
    {
        $params = [$owner,$other,$other,$owner];
        $where = '';
        if ($before !== null) { $where = ' AND id<?'; $params[]=$before; }
        $rows = self::rows('SELECT id,sender_id,body,sent_at FROM keeper_mail
            WHERE ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?))'.$where.' ORDER BY id DESC LIMIT 31', $params);
        $older = count($rows) > 30;
        if ($older) { array_pop($rows); }
        return ['messages'=>array_reverse($rows),'older'=>$older];
    }

    public static function act(int $owner, array $input): array
    {
        return Db::transaction(static function (PDO $pdo) use ($owner,$input): array {
            $kind = $input['kind'] ?? '';
            if ($kind === 'openGift') { return self::claim($pdo,$owner,self::number($input,'gift')); }
            $other = self::number($input,'keeper');
            if ($owner === $other) { throw new RuntimeException('Choose another keeper.'); }
            $low = min($owner,$other); $high = max($owner,$other);
            // Lock the pair before evaluating friend counts, recipient capacity or gift cooldown.
            $keepers = self::rows('SELECT id,is_bot FROM users WHERE id IN (?,?) ORDER BY id FOR UPDATE',[$low,$high]);
            if (count($keepers)!==2 || $keepers[0]['is_bot'] || $keepers[1]['is_bot']) {
                throw new RuntimeException('Choose a player keeper for friends, gifts or mail.');
            }
            $pair = self::rows('SELECT * FROM keeper_friends WHERE low_id=? AND high_id=? FOR UPDATE',[$low,$high])[0] ?? null;
            if ($kind === 'request' || $kind === 'accept') {
                if ($kind==='accept' && (!$pair || (int)$pair['id']!==self::number($input,'link') || (int)$pair['requested_by']===$owner)) {
                    throw new RuntimeException('That incoming invitation has changed.');
                }
                if ($pair && ($pair['accepted'] || (int)$pair['requested_by']===$owner)) { return []; }
                foreach ([$owner,$other] as $id) {
                    $n=self::rows('SELECT COUNT(*) AS n FROM keeper_friends WHERE (low_id=? OR high_id=?) AND accepted=1',[$id,$id])[0]['n'];
                    if ((int)$n>=500) { throw new RuntimeException('A keeper has reached the 500-friend limit.'); }
                }
                if ($pair) { self::update($pdo,'UPDATE keeper_friends SET accepted=1 WHERE id=?',[$pair['id']]); }
                else {
                    $pending=self::rows('SELECT COUNT(*) AS n FROM keeper_friends WHERE requested_by=? AND accepted=0',[$owner])[0]['n'];
                    if ((int)$pending>=50) { throw new RuntimeException('You have 50 unanswered invitations.'); }
                    self::update($pdo,'INSERT INTO keeper_friends(low_id,high_id,requested_by) VALUES(?,?,?)',[$low,$high,$owner]);
                }
                return [];
            }
            if ($kind==='remove') {
                self::update($pdo,'DELETE FROM keeper_friends WHERE low_id=? AND high_id=? AND id=?',[$low,$high,self::number($input,'link')]);
                return [];
            }
            if ($kind==='read') {
                self::update($pdo,'UPDATE keeper_mail SET read_at=? WHERE sender_id=? AND recipient_id=? AND id<=? AND read_at IS NULL',
                    [time(),$other,$owner,self::number($input,'through')]);
                return [];
            }
            if (!$pair || !$pair['accepted']) { throw new RuntimeException('Become friends before sending gifts or mail.'); }
            if ($kind==='sendGift') {
                $now=time();
                $last=self::rows('SELECT MAX(sent_at) AS latest FROM keeper_gifts WHERE sender_id=? AND recipient_id=?',[$owner,$other])[0]['latest'];
                if ($last!==null && (int)$last+86400>$now) { throw new RuntimeException('You can send this friend one mystery sack every 24 hours.'); }
                $waiting=self::rows('SELECT COUNT(*) AS n FROM keeper_gifts WHERE recipient_id=? AND claimed_at IS NULL',[$other])[0]['n'];
                if ((int)$waiting>=20) { throw new RuntimeException('This friend already has 20 unopened sacks.'); }
                $rate=random_int(1,4)===4 ? random_int(700,1200) : random_int(200,400);
                $resources=['twig','pebble','putty','goo'];
                self::update($pdo,'INSERT INTO keeper_gifts(sender_id,recipient_id,sent_at,resource,basis_points) VALUES(?,?,?,?,?)',
                    [$owner,$other,$now,$resources[random_int(0,3)],$rate]);
                return [];
            }
            if ($kind==='sendMail') {
                $key=$input['token']??null; $message=$input['body']??null;
                if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D',$key) || !is_string($message)) {
                    throw new RuntimeException('Enter a message and try again.');
                }
                $message=trim($message);
                if (!preg_match('/^.{1,1200}$/usD',$message)) { throw new RuntimeException('Write between 1 and 1,200 characters.'); }
                $sent=self::rows('SELECT recipient_id,body FROM keeper_mail WHERE sender_id=? AND client_key=?',[$owner,$key])[0]??null;
                if ($sent) {
                    if ((int)$sent['recipient_id']!==$other || $sent['body']!==$message) { throw new RuntimeException('This message changed. Open the thread and send again.'); }
                } else {
                    self::update($pdo,'INSERT INTO keeper_mail(sender_id,recipient_id,client_key,body,sent_at) VALUES(?,?,?,?,?)',[$owner,$other,$key,$message,time()]);
                }
                return [];
            }
            throw new RuntimeException('Unknown neighbor action.');
        });
    }

    private static function claim(PDO $pdo, int $owner, int $gift): array
    {
        $base=Game::loadBase($owner,true);
        $row=self::rows('SELECT * FROM keeper_gifts WHERE id=? AND recipient_id=? FOR UPDATE',[$gift,$owner])[0]??null;
        if (!$row) { throw new RuntimeException('That mystery sack is unavailable.'); }
        if ($row['claimed_at']!==null) { return ['resource'=>$row['resource'],'credited'=>0,'remaining'=>0]; }
        if (self::rows("SELECT id FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?) LIMIT 1",[$owner,$owner])) {
            throw new RuntimeException('Finish the battle before opening gifts.');
        }
        $capacity=Game::capacity($base);
        $resource=$row['resource'];
        $amount=$row['remaining']===null ? max(1,intdiv($capacity*(int)$row['basis_points'],10000)) : (int)$row['remaining'];
        $credited=min($amount,max(0,$capacity-(int)ceil($base['resources'][$resource])));
        $remaining=$amount-$credited;
        $base['resources'][$resource]+=$credited;
        self::update($pdo,'UPDATE keeper_gifts SET remaining=?,claimed_at=? WHERE id=?',[$remaining,$remaining===0?time():null,$gift]);
        if ($credited>0) { Game::saveBase($owner,$base); }
        return ['resource'=>$resource,'credited'=>$credited,'remaining'=>$remaining];
    }
}
