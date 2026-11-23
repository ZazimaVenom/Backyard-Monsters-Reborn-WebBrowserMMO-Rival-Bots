<?php
declare(strict_types=1);

/** Indexed membership in the existing settings keyspace avoids walking every surface yard. */
final class InfernoRivals
{
    private const PREFIX='inferno-member-';

    public static function enroll(int $id):void
    {
        $q=Db::pdo()->prepare('INSERT IGNORE INTO settings(name,value) VALUES(?,?)');
        $q->execute([self::PREFIX.$id,(string)$id]);
    }

    private static function members(string $where):string
    {
        return " FROM settings s JOIN users u ON u.id=CAST(s.value AS UNSIGNED) WHERE s.name LIKE 'inferno-member-%' AND u.id<>? ".$where;
    }

    public static function opponents(int $id,int $page=1,string $search='',string $filter='all'):array
    {
        $home=Game::loadBase($id);if(!isset($home['infernoRealm']))throw new RuntimeException('Open your Inferno entrance first.');
        $pdo=Db::pdo();$params=[$id];$where=match($filter){'bots'=>' AND u.is_bot=1','players'=>' AND u.is_bot=0',default=>''};
        preg_match('/^.{0,48}/us',trim($search),$match);$search=$match[0]??'';if($search!==''){$where.=' AND LOCATE(?,u.name)>0';$params[]=$search;}
        $from=self::members($where);$q=$pdo->prepare('SELECT COUNT(*)'.$from);$q->execute($params);$total=(int)$q->fetchColumn();
        $pages=max(1,(int)ceil($total/10));$page=max(1,min($page,$pages));$offset=10*($page-1);
        $q=$pdo->prepare('SELECT u.id,u.name,u.is_bot,u.rank_points'.$from." ORDER BY u.rank_points DESC,u.id LIMIT 10 OFFSET $offset");$q->execute($params);
        $items=[];foreach($q->fetchAll() as $user){
            $other=Game::loadBase((int)$user['id']);if(!isset($other['infernoRealm']))continue;
            $realm=$other['infernoRealm'];$now=time();
            $items[]=['id'=>(int)$user['id'],'name'=>$user['name'],'is_bot'=>(bool)$user['is_bot'],'rank_points'=>(int)$user['rank_points'],
                'hall'=>Game::livingLevel($realm,'townhall'),'buildings'=>count($realm['buildings']),'shieldUntil'=>(int)($realm['shield_until']??0),
                'canAttack'=>self::open($home,$realm,(int)$user['id'],$now)];
        }
        return compact('items','page','pages','total','filter');
    }

    private static function open(array $home,array $defender,int $peer,int $now):bool
    {
        return ($defender['shield_until']??0)<=$now&&!InfernoDefense::active($defender)&&!Truces::activeUntil($home,$peer,$now)
            &&(bool)array_filter($defender['buildings'],fn($b)=>$b['type']!=='wall'&&$b['hp']>0);
    }

    public static function visit(int $viewer,int $peer):array
    {
        if($viewer===$peer)throw new RuntimeException('Choose another keeper’s cavern.');
        $home=Game::loadBase($viewer);if(!isset($home['infernoRealm']))throw new RuntimeException('Open your Inferno entrance first.');
        $d=Game::loadBase($peer);$r=$d['infernoRealm']??throw new RuntimeException('This keeper has not opened an Inferno entrance.');
        $user=Game::user($peer);$now=time();InfernoRealm::accrue($r,$now);
        $buildings=array_values(array_filter(YardUtilities::hatcheryWork($r,$now),[Catalog::class,'visibleToRivals']));
        foreach($buildings as &$b){unset($b['stored'],$b['upgrade_to']);}unset($b);
        return ['realm'=>'inferno','yardExtent'=>YardSpace::extent($r),'infernoRival'=>['id'=>$peer,'canAttack'=>self::open($home,$r,$peer,$now),
            'shieldUntil'=>(int)($r['shield_until']??0),'truceUntil'=>Truces::activeUntil($home,$peer,$now),
            'previousId'=>$home['infernoRealm']['rankedRaid']['id']??null],
            'owner'=>$user,'army'=>[],'buildings'=>$buildings];
    }

    public static function botTarget(int $attackerId,int $seed,array $busy,bool $allowHuman=true):?int
    {
        $home=Game::loadBase($attackerId);if(!isset($home['infernoRealm']))return null;
        $pdo=Db::pdo();$seed=$seed&0x7fffffff;
        foreach(!$allowHuman?[' AND u.is_bot=1']:($seed%4===0?[' AND u.is_bot=0','']:['']) as $where){
            $from=self::members($where);$count=$pdo->prepare('SELECT COUNT(*)'.$from);$count->execute([$attackerId]);$total=(int)$count->fetchColumn();
            if(!$total)continue;
            $offset=$seed%$total;
            // Sample up to eight actual member IDs, wrapping at the end of the index.
            $first=min(8,$total-$offset);$segments=[[$offset,$first]];
            if($first<min(8,$total))$segments[]=[0,min(8,$total)-$first];
            foreach($segments as [$fromIndex,$limit]){
                $q=$pdo->prepare('SELECT u.id'.$from." ORDER BY u.id LIMIT $limit OFFSET $fromIndex");$q->execute([$attackerId]);
                foreach($q->fetchAll(PDO::FETCH_COLUMN) as $candidate){
                    $peer=(int)$candidate;if(isset($busy[$peer]))continue;
                    $opponent=Game::loadBase($peer);if(isset($opponent['infernoRealm'])&&self::open($home,$opponent['infernoRealm'],$peer,time()))return $peer;
                }
            }
        }
        return null;
    }

    public static function retry(array $r,int $peer,array $request):?int
    {
        $key=$request['requestId']??null;
        if(!is_string($key)||!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$key))throw new RuntimeException('Reopen this cavern before invading.');
        $prior=$r['rankedRaid']??[];
        if(($prior['request']??null)===$key){
            if($peer!==$prior['peer'])throw new RuntimeException('That request belongs to another cavern.');
            return $prior['id'];
        }
        if(!array_key_exists('previousId',$request)||$request['previousId']!==($prior['id']??null))throw new RuntimeException('Your last cavern invasion changed. Scout again.');
        return null;
    }

    public static function settle(array &$attacker,array &$defender,array &$result,array $target,int $now,bool $botOnHuman):void
    {
        $wallet=&$attacker['resources'];$vault=&$defender['resources'];$cap=InfernoRealm::capacity($attacker);
        $fraction=min(.10,$result['destruction']/1000);$result['loot']=[];
        foreach(array_keys($wallet) as $resource){
            $amount=min((int)floor($vault[$resource]*$fraction),max(0,(int)floor($cap-$wallet[$resource])));
            $wallet[$resource]+=$amount;$vault[$resource]-=$amount;$result['loot'][$resource]=$amount;
        }
        $result['target']=$target;$result['infernoRival']=true;$defender['shield_until']=$now+($botOnHuman?1800:120);
        ++$attacker['revision'];++$defender['revision'];
    }
}
