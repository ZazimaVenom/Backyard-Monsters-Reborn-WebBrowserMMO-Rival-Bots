<?php
declare(strict_types=1);

/** Persistent source-sized channel windows; old schema-3 saves acquire them on first use. */
final class RealmChat
{
    private const HISTORY=100;
    private const IDLE_TTL=2592000;

    private static function read(PDO $p,string $key,bool $lock=false): array
    {
        if($lock){$p->prepare("INSERT INTO settings(name,value) VALUES(?,'{}') ON DUPLICATE KEY UPDATE name=VALUES(name)")->execute([$key]);}
        $q=$p->prepare('SELECT value FROM settings WHERE name=?'.($lock?' FOR UPDATE':''));
        $q->execute([$key]);
        return json_decode($q->fetchColumn()?:'{}',true,32,JSON_THROW_ON_ERROR);
    }

    private static function put(PDO $p,string $key,array $data):void
    {
        $p->prepare('UPDATE settings SET value=? WHERE name=?')->execute([json_encode($data,JSON_THROW_ON_ERROR),$key]);
    }

    private static function channels(int $id):array
    {
        $yard=Game::loadBase($id);
        $home=World::home($id);
        $surface=Game::livingLevel($yard,'maproom')>0&&$home?'mr'.Regions::versionForQ((int)$home['q']):'mr1';
        return empty($yard['infernoRealm'])?[$surface]:[$surface,'inferno'];
    }

    public static function text(mixed $body): string
    {
        if(!is_string($body)||!preg_match('//u',$body)){throw new RuntimeException('Enter a chat message.');}
        $body=trim(preg_replace('/[\p{Cc}\p{Z}\s]+/u',' ',$body)??'');
        if($body===''||preg_match_all('/./us',$body)>200){throw new RuntimeException('Use 1 to 200 characters.');}
        static $filters;
        $filters??=json_decode(file_get_contents(__DIR__.'/chat-filters.json'),true,32,JSON_THROW_ON_ERROR);
        foreach($filters as $pattern){
            $clean=preg_replace_callback('~'.$pattern.'~i',static fn($m)=>preg_replace('/\S/u','*',$m[0]),$body);
            if($clean!==null){$body=$clean;}
        }
        return $body;
    }

    private static function result(string $room,array $channels,array $history,array $prefs,int $now):array
    {
        $muted=$prefs['muted']??[];
        $messages=($history['at']??0)>$now-self::IDLE_TTL?($history['messages']??[]):[];
        $messages=array_values(array_filter($messages,static fn($m)=>!isset($muted[$m['sender']])));
        return ['room'=>$room,'channels'=>$channels,'messages'=>$messages,'muted'=>$muted];
    }

    public static function view(int $id,string $requested=''):array
    {
        $p=Db::pdo();$channels=self::channels($id);
        $room=in_array($requested,$channels,true)?$requested:$channels[0];
        return self::result($room,$channels,self::read($p,'chat:room:'.$room),self::read($p,'chat:keeper:'.$id),time());
    }

    public static function act(int $id,array $input):array
    {
        return Db::transaction(static function(PDO $p)use($id,$input):array{
            $channels=self::channels($id);$room=$input['room']??'';
            if(!in_array($room,$channels,true)){throw new RuntimeException('Your chat channel has changed. Reopen chat.');}
            $now=time();$prefsKey='chat:keeper:'.$id;$prefs=self::read($p,$prefsKey,true);
            $roomKey='chat:room:'.$room;$op=$input['op']??'';
            if($op==='mute'||$op==='unmute'){
                $peer=$input['peer']??0;
                if(!is_int($peer)||$peer<=0||$peer===$id){throw new RuntimeException('Choose another keeper.');}
                if($op==='mute'){$u=Game::user($peer);$prefs['muted'][$peer]=$u['name'];}
                else{unset($prefs['muted'][$peer]);}
                self::put($p,$prefsKey,$prefs);
                return self::result($room,$channels,self::read($p,$roomKey),$prefs,$now);
            }
            if($op!=='send'){throw new RuntimeException('Choose a chat action.');}
            $body=self::text($input['body']??null);
            $token=$input['token']??'';
            if(!is_string($token)||!preg_match('/^[a-f0-9]{32}$/D',$token)){throw new RuntimeException('Reopen the chat composer.');}
            $signature=hash('sha256',$room."\n".$body);
            $history=self::read($p,$roomKey,true);
            if(isset($prefs['receipts'][$token])){
                if($prefs['receipts'][$token]!==$signature){throw new RuntimeException('This message reference has already been used.');}
                return self::result($room,$channels,$history,$prefs,$now);
            }
            $ms=(int)floor(microtime(true)*1000);
            if($ms-($prefs['sentMs']??0)<500){throw new RuntimeException('Wait a moment before sending another message.');}
            if(($history['at']??0)<=$now-self::IDLE_TTL){$history['messages']=[];}
            $sequence=($history['sequence']??0)+1;
            $history['sequence']=$sequence;$history['at']=$now;
            $history['messages'][]=['id'=>$sequence,'sender'=>$id,'name'=>Game::user($id)['name'],'body'=>$body,'at'=>$now];
            $history['messages']=array_slice($history['messages'],-self::HISTORY);
            $prefs['sentMs']=$ms;$prefs['receipts'][$token]=$signature;
            $prefs['receipts']=array_slice($prefs['receipts'],-100,null,true);
            self::put($p,$prefsKey,$prefs);self::put($p,$roomKey,$history);
            return self::result($room,$channels,$history,$prefs,$now);
        });
    }
}
