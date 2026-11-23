<?php
declare(strict_types=1);

/** Social journal evidence comes from completed transactions on this server. */
final class QuestSocial
{
    public static function token(int $owner):string
    {
        $salt=Db::config()['auth_salt']??'';
        if ($salt==='') {
            // Older installations may lack an install salt. A human keeper's private password hash is stable across sign-ins.
            $q=Db::pdo()->prepare('SELECT password_hash FROM users WHERE id=? AND is_bot=0');
            $q->execute([$owner]);$salt=(string)$q->fetchColumn();
        }
        if ($salt==='') { return ''; }
        return $owner.'.'.substr(hash_hmac('sha256','yard-referral/'.$owner,$salt),0,32);
    }

    public static function isMember(int $id):bool
    {
        $q=Db::pdo()->prepare('SELECT 1 FROM alliance_members WHERE user_id=?');$q->execute([$id]);
        return (bool)$q->fetchColumn();
    }

    public static function acceptedGifts(int $sender):int
    {
        $q=Db::pdo()->prepare('SELECT COUNT(*) FROM (SELECT id FROM keeper_gifts WHERE sender_id=? AND claimed_at IS NOT NULL LIMIT 50) AS opened');
        $q->execute([$sender]);return (int)$q->fetchColumn();
    }

    public static function joined(int $user):void
    {
        $pdo=Db::pdo();
        $q=$pdo->prepare('SELECT b.state_json FROM bases b JOIN users u ON u.id=b.user_id WHERE u.id=? AND u.is_bot=0 FOR UPDATE');
        $q->execute([$user]);$state=$q->fetchColumn();
        if ($state===false) { return; }
        $home=json_decode($state,true,512,JSON_THROW_ON_ERROR);
        if (empty($home['questLog']['best']['allied'])) {
            $home['questLog']['best']['allied']=1;
            $q=$pdo->prepare('UPDATE bases SET state_json=?,updated_at=? WHERE user_id=?');
            $q->execute([json_encode($home,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),time(),$user]);
        }
    }

    public static function registration(PDO $db,int $newUser,string $invite):void
    {
        if (!preg_match('/^([1-9][0-9]{0,17})\.[a-f0-9]{32}$/D',$invite,$m)) { return; }
        $owner=(int)$m[1];
        if ($owner===$newUser || !hash_equals(self::token($owner),$invite)) { return; }
        $q=$db->prepare('SELECT is_bot FROM users WHERE id=?');$q->execute([$owner]);$bot=$q->fetchColumn();
        if ($bot===false || (int)$bot!==0) { return; }
        $base=Game::loadBase($owner,true);
        $count=(int)($base['questLog']['referrals']??0);
        if ($count<10) {
            $base['questLog']['referrals']=$count+1;
            Game::saveBase($owner,$base);
        }
    }
}
