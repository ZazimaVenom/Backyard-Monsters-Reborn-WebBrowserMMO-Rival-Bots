<?php
declare(strict_types=1);

final class Truces
{
    public const DURATION = 14 * 24 * 60 * 60;

    public static function activeUntil(array $base, int $peer, int $now): int
    {
        $agreement = $base['truces'][$peer] ?? [];
        $expiry = (int)($agreement['expires'] ?? 0);
        return ($agreement['state'] ?? '') === 'accepted' && $expiry > $now ? $expiry : 0;
    }

    public static function guard(array $base, int $peer, int $now): void
    {
        if ($peer > 0 && self::activeUntil($base, $peer, $now)) {
            throw new RuntimeException('Your 14-day truce protects both keepers and their territories.');
        }
    }

    /** Read only diplomacy. Estate clocks, stockpiles and other private yard data stay untouched. */
    private static function records(PDO $db, int $owner, bool $lock = false): array
    {
        $q = $db->prepare("SELECT JSON_EXTRACT(state_json,'$.truces') FROM bases WHERE user_id=?".($lock ? ' FOR UPDATE' : ''));
        $q->execute([$owner]);
        $value = $q->fetchColumn();
        if ($value === false) { throw new RuntimeException('That keeper’s yard could not be found.'); }
        return $value === null ? [] : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function view(int $owner, int $selected = 0): array
    {
        $db = Db::pdo();
        $records = self::records($db, $owner);
        $now = time();
        $visible = array_filter($records, static fn(array $r): bool =>
            $r['state'] === 'requested' || ($r['state'] === 'accepted' && $r['expires'] > $now));
        if ($selected > 0 && $selected !== $owner) { $visible[$selected] = $records[$selected] ?? []; }
        $keepers = [];
        if ($visible) {
            $marks = implode(',', array_fill(0, count($visible), '?'));
            $q = $db->prepare("SELECT id,name,is_bot FROM users WHERE id IN ($marks) ORDER BY name,id");
            $q->execute(array_keys($visible));
            foreach ($q as $row) {
                $id = (int)$row['id'];
                $agreement = $visible[$id];
                $status = $agreement['state'] ?? 'none';
                if ($status === 'accepted' && $agreement['expires'] <= $now) { $status = 'expired'; }
                $keepers[] = ['keeper'=>$id,'name'=>$row['name'],'isBot'=>(bool)$row['is_bot'],'status'=>$status,
                    'token'=>$agreement['token'] ?? '', 'outgoing'=>($agreement['from'] ?? 0) === $owner,
                    'expires'=>(int)($agreement['expires'] ?? 0)];
            }
        }
        if ($selected > 0 && !in_array($selected, array_column($keepers, 'keeper'), true)) {
            throw new RuntimeException('Choose another existing keeper.');
        }
        return ['owner'=>$owner, 'selected'=>$selected, 'keepers'=>$keepers, 'now'=>$now];
    }

    public static function act(int $owner, array $input): array
    {
        $peer = filter_var($input['keeper'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $kind = $input['kind'] ?? '';
        $token = $input['token'] ?? null;
        $previous = $input['previous'] ?? '';
        if ((!is_int($input['keeper'] ?? null) && !is_string($input['keeper'] ?? null)) || !$peer || $peer === $owner || !in_array($kind, ['request','accept','decline','withdraw'], true)
            || !is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)
            || !is_string($previous) || ($previous !== '' && !preg_match('/^[a-f0-9]{32}$/D', $previous))) {
            throw new RuntimeException('Choose a valid keeper and truce request.');
        }
        Db::transaction(static function(PDO $db) use ($owner,$peer,$kind,$token,$previous): void {
            $ids = [$owner,$peer];
            sort($ids, SORT_NUMERIC);
            $all = [];
            foreach ($ids as $id) { $all[$id] = self::records($db, $id, true); }
            $record = $all[$owner][$peer] ?? null;
            if ($record != ($all[$peer][$owner] ?? null)) { throw new RuntimeException('The two yards have conflicting truce records.'); }
            $now = time();
            if ($kind === 'request') {
                if (($record['token'] ?? '') === $token && $record['from'] === $owner) { return; }
                if (($record['token'] ?? '') !== $previous || $token === $previous || ($record['state'] ?? '') === 'requested'
                        || self::activeUntil(['truces'=>$all[$owner]], $peer, $now)) {
                    throw new RuntimeException('This agreement changed. Open Truces to see its current status.');
                }
                $q = $db->prepare('SELECT is_bot FROM users WHERE id=?');
                $q->execute([$peer]);
                $status = !(bool)$q->fetchColumn() ? 'requested' : (Bots::profile($peer) === 'raider' ? 'declined' : 'accepted');
                $record = ['token'=>$token,'from'=>$owner,'state'=>$status,'created'=>$now,'expires'=>0];
            } else {
                if (($record['token'] ?? '') !== $token) { throw new RuntimeException('This truce request has been replaced.'); }
                if (($record['from'] === $owner) !== ($kind === 'withdraw')) {
                    throw new RuntimeException('Only the sender can withdraw. Only the recipient can answer.');
                }
                $status = ['accept'=>'accepted','decline'=>'declined','withdraw'=>'withdrawn'][$kind];
                if ($record['state'] === $status) { return; }
                if ($record['state'] !== 'requested') { throw new RuntimeException('Only a pending offer can be changed.'); }
                $record['state'] = $status;
            }
            if ($record['state'] === 'accepted') {
                // All invasion starts lock these same two bases before reserving their battle.
                $active = $db->prepare("SELECT id FROM battles WHERE status='active' AND ((attacker_id=? AND defender_id=?) OR (attacker_id=? AND defender_id=?)) LIMIT 1");
                $active->execute([$owner,$peer,$peer,$owner]);
                if ($active->fetchColumn()) { throw new RuntimeException('Finish the invasion between these two keepers before accepting a truce.'); }
                $record['expires'] = $now + self::DURATION;
            }
            $write = $db->prepare("UPDATE bases SET state_json=JSON_SET(state_json,'$.truces',JSON_EXTRACT(?, '$')), updated_at=? WHERE user_id=?");
            foreach ($ids as $id) {
                $other = $id === $owner ? $peer : $owner;
                $all[$id][$other] = $record;
                $write->execute([json_encode((object)$all[$id], JSON_THROW_ON_ERROR),$now,$id]);
            }
        });
        return self::view($owner, $peer);
    }
}
