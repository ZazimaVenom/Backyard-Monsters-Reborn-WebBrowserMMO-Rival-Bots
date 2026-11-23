<?php
declare(strict_types=1);

/** Each alliance keeps the source's 50 newest messages and server-authored shouts. Writers hold the alliance row lock. */
final class AllianceFeed
{
    private const LIMIT=50;

    private static function rows(PDO $db,int $group,bool $lock=false):array
    {
        $q=$db->prepare('SELECT value FROM settings WHERE name=?'.($lock?' FOR UPDATE':''));$q->execute(['alliance-feed-'.$group]);
        $value=$q->fetchColumn();return $value===false ? [] : json_decode($value,true,512,JSON_THROW_ON_ERROR);
    }

    public static function read(PDO $db,int $group):array
    {
        $rows=self::rows($db,$group);
        foreach ($rows as &$row) { unset($row['key']); }unset($row);
        return $rows;
    }

    public static function poll(int $user,int $group):array
    {
        return Db::transaction(static function(PDO $db)use($user,$group):array{
            $q=$db->prepare('SELECT alliance_id FROM alliance_members WHERE user_id=?');$q->execute([$user]);
            $member=(int)$q->fetchColumn()===$group;
            return ['member'=>$member,'feed'=>$member?self::read($db,$group):[]];
        });
    }

    private static function store(PDO $db,int $group,array $rows,int $user,string $name,string $kind,string $body,string $key=''):void
    {
        $last=end($rows);
        $rows[]=['id'=>($last['id']??0)+1,'user'=>$user,'name'=>$name,'kind'=>$kind,'body'=>$body,'at'=>time(),'key'=>$key];
        $q=$db->prepare('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
        $q->execute(['alliance-feed-'.$group,json_encode(array_slice($rows,-self::LIMIT),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }

    public static function announce(PDO $db,int $group,int $user,string $kind,string $body):void
    {
        $q=$db->prepare('SELECT name FROM users WHERE id=?');$q->execute([$user]);
        self::store($db,$group,self::rows($db,$group,true),$user,(string)$q->fetchColumn(),$kind,$body);
    }

    public static function message(int $user,int $group,array $input):void
    {
        $body=Alliances::validatedText($input['message']??null,1,200);$key=$input['key']??null;$after=$input['after']??null;
        if (!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key)||!is_int($after)||$after<0) { throw new RuntimeException('Reopen the alliance feed and try again.'); }
        Db::transaction(static function(PDO $db)use($user,$group,$body,$key,$after):void{
            $q=$db->prepare('SELECT id FROM alliances WHERE id=? FOR UPDATE');$q->execute([$group]);
            if (!$q->fetchColumn()) { throw new RuntimeException('This alliance has disbanded.'); }
            $q=$db->prepare('SELECT u.name FROM alliance_members m JOIN users u ON u.id=m.user_id WHERE u.id=? AND m.alliance_id=? AND u.is_bot=0');$q->execute([$user,$group]);
            $name=$q->fetchColumn();
            if ($name===false) { throw new RuntimeException('Only current members can post to this alliance.'); }
            $rows=self::rows($db,$group,true);
            foreach ($rows as $row) {
                if ($row['user']===$user && $row['key']===$key) {
                    if ($row['body']!==$body) { throw new RuntimeException('The submitted message changed.'); }
                    return;
                }
            }
            if ($after<($rows[0]['id']??1)-1 || $after>(end($rows)['id']??0)) { throw new RuntimeException('Refresh the alliance feed and submit your message again.'); }
            self::store($db,$group,$rows,$user,$name,'message',$body,$key);
        });
    }
}
