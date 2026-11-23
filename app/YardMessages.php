<?php
declare(strict_types=1);

final class YardMessages
{
    public const LIFETIME=172800;
    public const SIGN_COST=['twig'=>1000,'pebble'=>1000,'putty'=>1000,'goo'=>1000];
    public const SIZE=.8;

    public static function signs(array $base,?int $now=null):array
    {
        $now??=time();
        return array_values(array_filter($base['yardSigns']??[],static fn($s)=>$s['expires']>$now));
    }
    public static function overlaps(array $base,float $x,float $y,float $size):bool
    {
        $gap=($size+self::SIZE)/2+.15;
        foreach(self::signs($base) as $s){if(abs($x-$s['x'])<$gap-1e-9&&abs($y-$s['y'])<$gap-1e-9)return true;}
        return false;
    }
    public static function prune(array &$base,int $now):void
    {
        if(isset($base['yardSigns']))$base['yardSigns']=self::signs($base,$now);
    }
    private static function text(mixed $text):string
    {
        if(!is_string($text)||!preg_match('//u',$text))throw new RuntimeException('Enter a sign message.');
        $text=trim(preg_replace('/[\p{Cc}\p{Z}\s]+/u',' ',$text)??'');
        if($text===''||preg_match_all('/./us',$text)>180)throw new RuntimeException('Write a sign message of 1 to 180 characters.');
        return $text;
    }

    public static function changeSign(int $viewer,array $input):array
    {
        $peer=$input['yard']??null;$op=$input['op']??null;
        if(!is_int($peer)||$peer<=0||!in_array($op,['post','edit','remove'],true)){throw new RuntimeException('Choose a sign in a real keeper’s home yard.');}
        if($op==='post'&&$viewer===$peer)throw new RuntimeException('Visit another keeper to leave a sign.');
        return Db::transaction(static function(PDO $pdo)use($viewer,$peer,$op,$input){
            $locked=[];$ids=array_unique([$viewer,$peer]);sort($ids,SORT_NUMERIC);
            foreach($ids as $id)$locked[$id]=Game::loadBase($id,true);
            $yard=&$locked[$peer];$mine=&$locked[$viewer];$now=time();self::prune($yard,$now);
            $token=$input['token']??null;
            if(!is_string($token)||!preg_match('/^[a-f0-9]{32}$/D',$token)){throw new RuntimeException('Reopen the sign before making changes.');}
            if($op==='post'){
                if(isset($mine['yardSignReceipts'][$token])){
                    if($mine['yardSignReceipts'][$token]!==$peer)throw new RuntimeException('Sign purchase reference already used.');
                    return ['yard'=>$peer,'signs'=>self::signs($yard)];
                }
                $busy=$pdo->prepare("SELECT id FROM battles WHERE status='active' AND (attacker_id IN (?,?) OR defender_id IN (?,?)) LIMIT 1");
                $busy->execute([$peer,$viewer,$peer,$viewer]);if($busy->fetchColumn())throw new RuntimeException('Wait until both keepers have finished fighting.');
                $text=self::text($input['text']??null);
                // A sign rests above hidden trap tiles. The sender only receives checks against public scenery.
                $public=$yard;$public['buildings']=array_filter($yard['buildings'],[Catalog::class,'visibleToRivals']);
                [$x,$y]=Decorations::coordinates($public,$input,self::SIZE,null);
                Game::accrue($mine,$now);
                foreach(self::SIGN_COST as $key=>$amount)if(($mine['resources'][$key]??0)<$amount)throw new RuntimeException('A sign costs 1,000 of each yard resource.');
                foreach(self::SIGN_COST as $key=>$amount)$mine['resources'][$key]-=$amount;
                $name=Game::user($viewer)['name'];$mine['yardSignReceipts'][$token]=$peer;
                $yard['yardSigns'][]=['id'=>$token,'sender'=>$viewer,'name'=>$name,'text'=>$text,'x'=>$x,'y'=>$y,'created'=>$now,'expires'=>$now+self::LIFETIME];
            }else{
                $found=false;
                foreach($yard['yardSigns']??[] as $i=>$sign){
                    if($sign['id']!==$token)continue;
                    if($op==='edit'){
                        if($sign['sender']!==$viewer)throw new RuntimeException('Only the sender can edit this message.');
                        $yard['yardSigns'][$i]['text']=self::text($input['text']??null);
                    }else{
                        if($viewer!==$peer&&$sign['sender']!==$viewer)throw new RuntimeException('Only the yard owner or sender can remove this sign.');
                        array_splice($yard['yardSigns'],$i,1);
                    }
                    $found=true;break;
                }
                if(!$found&&$op==='edit')throw new RuntimeException('That sign has expired or been removed.');
            }
            foreach($locked as $id=>$b)Game::saveBase($id,$b);
            return ['yard'=>$peer,'signs'=>self::signs($yard)];
        });
    }

    public static function radio(array $base):array
    {
        $r=$base['radio']??[];
        $items=array_reverse($r['items']??[]);
        $read=$r['read']??0;
        return ['ready'=>Game::livingLevel($base,'radiotower')>0,'attacks'=>(bool)($r['attacks']??false),'news'=>(bool)($r['news']??false),'items'=>$items,'read'=>$read,'unread'=>count(array_filter($items,fn($m)=>$m['id']>$read))];
    }
    public static function radioAction(array &$base,string $action,array $input):void
    {
        if(Game::livingLevel($base,'radiotower')<1)throw new RuntimeException('Build or repair a Radio Tower to access the receiver.');
        if($action==='radioRead'){
            $last=$input['through']??null;
            if(!is_int($last)||$last<0||$last>($base['radio']['seq']??0))throw new RuntimeException('Refresh the receiver.');
            $base['radio']['read']=max($base['radio']['read']??0,$last);return;
        }
        if(!is_bool($input['attacks']??null)||!is_bool($input['news']??null))throw new RuntimeException('Choose both radio preferences.');
        $base['radio']['attacks']=$input['attacks'];$base['radio']['news']=$input['news'];
    }
    public static function receive(array &$base,string $category,string $message,int $when,?int $battle=null):bool
    {
        if(empty($base['radio'][$category])||Game::livingLevel($base,'radiotower')<1)return false;
        $seq=($base['radio']['seq']??0)+1;$base['radio']['seq']=$seq;
        $base['radio']['items'][]=['id'=>$seq,'category'=>$category,'message'=>$message,'at'=>$when,'battle'=>$battle];
        $base['radio']['items']=array_slice($base['radio']['items'],-50);
        return true;
    }
}
