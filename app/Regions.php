<?php
declare(strict_types=1);
require_once __DIR__.'/OutpostYards.php';

/** The legacy world keeps its original coordinates. Each additional region has its own 64×64 hex board. */
final class Regions
{
    private const STRIDE = 128;
    private const MOVE_WAIT = 86400;

    public static function id(int $q): int { return intdiv($q, self::STRIDE) + 1; }
    public static function left(int $q): int { return $q - $q % self::STRIDE; }
    public static function right(int $q): int { return self::left($q) + 63; }
    public static function local(int $q): int { return $q % self::STRIDE; }
    public static function versionForQ(int $q): int { return self::version(self::id($q)); }
    private static function version(int $id): int { return $id % 2 ? 3 : 2; }
    public static function name(int $id): string
    {
        return $id === 1 ? 'Home Valley' : (self::version($id) === 2 ? 'Frontier' : 'Highlands').' '.intdiv($id,2);
    }

    private static function get(string $sql, array $values=[]): array
    {
        $s=Db::pdo()->prepare($sql);$s->execute($values);return $s->fetchAll();
    }
    private static function write(string $sql, array $values): void
    {
        $s=Db::pdo()->prepare($sql);$s->execute($values);
    }
    private static function validInt(mixed $value): int
    {
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
        if($id===false) throw new RuntimeException('Select a valid map destination.');
        return $id;
    }

    /** Only one unopened destination of each edition is offered, regardless of caller input. */
    private static function directory(): array
    {
        $known=[1=>true];
        foreach(self::get("SELECT name FROM settings WHERE name LIKE 'mapregion:%'") as $row){
            $id=(int)substr($row['name'],10);
            if($id>1)$known[$id]=true;
        }
        foreach([2,3] as $id){
            while(isset($known[$id]))$id+=2;
            if($id>3){
                $offset=($id-3)*self::STRIDE;
                $pop=self::get("SELECT COUNT(*) AS n FROM world_sites WHERE kind='home' AND q BETWEEN ? AND ?",[$offset,$offset+63])[0]['n'];
                if((int)$pop<(self::version($id)===2?2500:32)) continue;
            }
            $known[$id]=false;
        }
        ksort($known,SORT_NUMERIC);
        return $known;
    }
    public static function checked(mixed $value): int
    {
        $id=self::validInt($value);
        if(!array_key_exists($id,self::directory())) throw new RuntimeException('Refresh the list of available regions.');
        return $id;
    }

    public static function open(int $region): void
    {
        if($region===1 || self::get('SELECT value FROM settings WHERE name=?',['mapregion:'.$region]))return;
        Db::transaction(static function()use($region):void{
            self::allocator();
            self::checked($region);
            self::seed($region);
        });
    }
    private static function allocator(): void
    {
        self::get("SELECT value FROM settings WHERE name='world_allocator' FOR UPDATE");
    }
    private static function seed(int $id): void
    {
        if($id===1 || self::get('SELECT value FROM settings WHERE name=? FOR UPDATE',['mapregion:'.$id]))return;
        $legacy=self::version($id)===2;
        $now=time();$offset=($id-1)*self::STRIDE;
        $add=Db::pdo()->prepare('INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES(?,?,?,NULL,NULL,?,1,?,1,?)');
        $states=[];$rows=[];
        for($y=0;$y<8;++$y)for($x=0;$x<8;++$x){
            if($legacy){
                // Four neutral tribe outposts in each eight-cell district.
                foreach([[1,1],[5,2],[2,5],[6,6]] as [$dx,$dy]){$rows[]=[$x*8+$dx,$y*8+$dy,'resource'];}
            }else{
                // Leave intervening space for the six defenders around an arriving home.
                $dx=(crc32('sector-q-'.$id.'-'.$x.'-'.$y)%3)+2;
                $dy=(crc32('sector-r-'.$id.'-'.$x.'-'.$y)%3)+2;
                $rows[]=[$x*8+$dx,$y*8+$dy,($x%2===0&&$y%2===0)?'stronghold':'resource'];
            }
        }
        $occupied=[];
        foreach($rows as [$x,$y,$kind]){
            $occupied[$x.','.$y]=true;
            if(!isset($states[$kind]))$states[$kind]=json_encode($legacy?OutpostYards::initialState($now):World::initialState($kind,1,$now),JSON_THROW_ON_ERROR);
            $type=$legacy?'Tribe Outpost':($kind==='resource'?'Wild Resource Outpost':'Wild Stronghold');
            $add->execute([$kind,$x+$offset,$y,$type.' '.$x.' · '.$y,$states[$kind],$now]);
        }
        if(!$legacy){
            $state=json_encode(World::initialState('fortification',1,$now),JSON_THROW_ON_ERROR);
            foreach($rows as [$x,$y])foreach(World::neighbors($x,$y) as $n){
                $k=$n['q'].','.$n['r'];if(isset($occupied[$k]))continue;
                $occupied[$k]=true;
                $add->execute(['fortification',$offset+$n['q'],$n['r'],'Wild Fortification '.$n['q'].' · '.$n['r'],$state,$now]);
            }
        }
        self::write('INSERT INTO settings(name,value) VALUES(?,?)',['mapregion:'.$id,(string)$now]);
    }

    private static function estate(int $keeper): array
    {
        $rows=self::get("SELECT id,kind,q,r FROM world_sites WHERE owner_id=? AND kind<>'home' ORDER BY id",[$keeper]);
        return array_map(static fn($r)=>['id'=>(int)$r['id'],'kind'=>$r['kind'],'q'=>(int)$r['q'],'r'=>(int)$r['r']],$rows);
    }
    private static function signature(array $sites): string { return hash('sha256',implode(',',array_column($sites,'id'))); }
    private static function friendRows(int $keeper): array
    {
        return self::get('SELECT u.id,u.name,h.q,h.r FROM keeper_friends f JOIN users u ON u.id=IF(f.low_id=?,f.high_id,f.low_id) JOIN world_sites h ON h.home_user_id=u.id WHERE (f.low_id=? OR f.high_id=?) AND f.accepted=1 ORDER BY u.name',[$keeper,$keeper,$keeper]);
    }
    private static function friends(int $a,int $b): bool
    {
        return (bool)self::get('SELECT id FROM keeper_friends WHERE low_id=? AND high_id=? AND accepted=1 FOR UPDATE',[min($a,$b),max($a,$b)]);
    }
    public static function bookmark(int $keeper,array $input): array
    {
        $region=self::checked($input['region']??null);
        $numbers=[];
        foreach(['q','r'] as $key){
            $v=filter_var($input[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>63]]);
            if($v===false)throw new RuntimeException('Choose a cell on the regional map.');
            $numbers[]=$v;
        }
        [$x,$y]=$numbers;$key="$region,$x,$y";$label=$input['label']??'';
        if(!is_string($label)||strlen($label)>100||preg_match('/[[:cntrl:]]/u',$label))throw new RuntimeException('Use a short name for this bookmark.');
        Db::transaction(static function()use($keeper,$region,$x,$y,$key,$label,$input):void{
            $base=Game::loadBase($keeper,true);
            $marks=$base['regionTravel']['bookmarks']??[];
            if(($input['op']??'')==='remove'){unset($marks[$key]);}
            elseif(($input['op']??'')==='save'){
                if(count($marks)>=24 && !isset($marks[$key]))throw new RuntimeException('Remove a bookmark before adding another.');
                $marks[$key]=['region'=>$region,'x'=>$x,'y'=>$y,'label'=>trim($label)?:self::name($region)." · $x, $y"];
            }else{throw new RuntimeException('Choose a bookmark action.');}
            $base['regionTravel']['bookmarks']=$marks;Game::saveBase($keeper,$base);
        });
        return self::overview($keeper);
    }

    public static function overview(int $keeper): array
    {
        $home=World::home($keeper);if(!$home)throw new RuntimeException('The home yard has no map position.');
        $base=Game::loadBase($keeper);$estate=self::estate($keeper);$regions=[];
        $pop=[];foreach(self::get("SELECT FLOOR(q/128)+1 AS region,COUNT(*) AS n FROM world_sites WHERE kind='home' GROUP BY FLOOR(q/128)") as $r)$pop[(int)$r['region']]=(int)$r['n'];
        foreach(self::directory() as $id=>$opened)$regions[]=['id'=>$id,'name'=>self::name($id),'mapRoom'=>self::version($id),'keepers'=>$pop[$id]??0,'settled'=>$opened];
        $own=array_values(array_filter($estate,fn($row)=>$row['kind']==='resource'&& self::versionForQ($row['q'])===2));
        $contacts=array_map(static fn($s)=>['id'=>(int)$s['id'],'name'=>$s['name'],'region'=>self::id((int)$s['q'])],self::friendRows($keeper));
        $offers=[];$reservations=[];
        foreach($own as $row){$s=World::load($row['id']);if($hold=$s['state']['regionInvite']??null)$reservations[]=['site'=>$s['id'],'recipient'=>$hold['recipient'],'token'=>$hold['token']];}
        foreach($base['regionTravel']['offers']??[] as $offer){
            $offers[]=array_intersect_key($offer,array_flip(['token','site','sender','senderName','region','x','y']));
        }
        return ['regions'=>$regions,'homeRegion'=>self::id($home['q']),'x'=>self::local($home['q']),'y'=>$home['r'],'revision'=>$home['revision'],
            'level'=>Game::livingLevel($base,'maproom'),'cooldown'=>(int)($base['regionTravel']['after']??0),'now'=>time(),
            'estate'=>count($estate),'estateKey'=>self::signature($estate),'outposts'=>array_map(static fn($s)=>['id'=>$s['id'],'x'=>self::local($s['q']),'y'=>$s['r']],$own),
            'friends'=>$contacts,'invites'=>$offers,'sent'=>$reservations,'bookmarks'=>array_values($base['regionTravel']['bookmarks']??[]),'price'=>['own'=>['resource'=>30000,'shiny'=>1500],'offer'=>['resource'=>10000,'shiny'=>1200]]];
    }

    private static function idle(int $keeper): void
    {
        if(self::get("SELECT id FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?) LIMIT 1",[$keeper,$keeper]))throw new RuntimeException('Finish active invasions before relocating.');
    }
    private static function ensureAllianceFree(int $keeper): void
    {
        if(self::get('SELECT user_id FROM alliance_members WHERE user_id=? FOR UPDATE',[$keeper]))throw new RuntimeException('Leave your alliance before migrating to a different world or accepting an outpost invitation.');
    }
    private static function pay(array &$base,bool $invited,string $method): void
    {
        if(!in_array($method,['resources','shiny'],true))throw new RuntimeException('Choose how to pay for relocation.');
        if($method==='shiny'){
            $cost=$invited?1200:1500;
            if(($base['shiny']??0)<$cost)throw new RuntimeException("This move costs $cost Shiny.");
            $base['shiny']-=$cost;return;
        }
        $cost=$invited?10000:30000;
        foreach(['twig','pebble','putty','goo'] as $k){
            if($base['resources'][$k]<$cost)throw new RuntimeException("This move requires ".number_format($cost)." of each resource.");
            $base['resources'][$k]-=$cost;
        }
    }
    private static function freePosition(int $region,?array $friend=null): array
    {
        $offset=($region-1)*self::STRIDE;
        $old=$region===1;$advanced=!$old && self::version($region)===3;
        $occupied=[];$homes=[];
        foreach(self::get('SELECT q,r,kind FROM world_sites WHERE q BETWEEN ? AND ? FOR UPDATE',[$offset,$offset+63]) as $r){
            $x=(int)$r['q']-$offset;$y=(int)$r['r'];$occupied[$x+64*$y]=true;
            if($r['kind']==='home')$homes[]=[$x,$y];
        }
        $best=null;$distance=PHP_INT_MAX;$start=random_int(0,4095);
        for($n=0;$n<4096;++$n){
            $cell=($start+1031*$n)%4096;$x=$cell%64;$y=intdiv($cell,64);
            if(isset($occupied[$cell]))continue;
            if($advanced){
                if(min($x,$y,63-$x,63-$y)<3)continue;
                foreach(World::neighbors($x,$y) as $v)if(isset($occupied[$v['q']+64*$v['r']]))continue 2;
                foreach($homes as [$hx,$hy])if(World::distance($x,$y,$hx,$hy)<7)continue 2;
            }
            if($friend===null)return [$offset+$x,$y];
            $d=World::distance($offset+$x,$y,(int)$friend['q'],(int)$friend['r']);
            if($d<$distance){$distance=$d;$best=[$offset+$x,$y];}
            if($distance===($advanced?7:1))break;
        }
        if($best!==null&&$distance<=28)return $best;
        throw new RuntimeException($friend?'No suitable sector is available near that friend.':'This region has no suitable landing sector. Choose a different region.');
    }

    /** Registration calls this under the same allocator lock as relocation. */
    public static function overflowPosition(): array
    {
        foreach(self::directory() as $region=>$opened){
            if(self::version($region)!==2)continue;
            self::seed($region);
            $left=($region-1)*self::STRIDE;
            $population=(int)self::get("SELECT COUNT(*) AS n FROM world_sites WHERE kind='home' AND q BETWEEN ? AND ?",[$left,$left+63])[0]['n'];
            if($population<2500)return self::freePosition($region);
        }
        throw new RuntimeException('No regional sector is available for registration.');
    }

    /** Called by the online bot scheduler. Established holdings and alliances keep a bot at home. */
    public static function botTurn(int $keeper,array $base,int $turn): bool
    {
        $level=Game::livingLevel($base,'maproom');
        if($level<2 || ($turn+$keeper)%7!==0 || $keeper%3!==0)return false;
        $home=World::home($keeper);
        if(!$home || self::id($home['q'])!==1 || self::estate($keeper))return false;
        $destinations=self::get("SELECT FLOOR(s.q/128)+1 AS region,SUM(u.is_bot=0) AS humans,SUM(u.is_bot=1) AS bots
            FROM world_sites s JOIN users u ON u.id=s.home_user_id WHERE s.q>=128 GROUP BY FLOOR(s.q/128) HAVING humans>0 ORDER BY bots,region");
        foreach($destinations as $d){
            $region=(int)$d['region'];
            if(self::version($region)>$level || (int)$d['bots']>=min(24,(int)$d['humans']*6))continue;
            try{
                self::act($keeper,['op'=>'relocate','region'=>$region,'request'=>bin2hex(random_bytes(16)),'revision'=>$home['revision'],'estateKey'=>self::signature([])]);
                return true;
            }catch(PDOException $error){throw $error;}
            catch(RuntimeException){return false;}
        }
        return false;
    }

    private static function clearSite(array $site,int $now): void
    {
        $site['state']=self::versionForQ($site['q'])===2 && $site['kind']==='resource' ? OutpostYards::initialState($now) : World::initialState($site['kind'],1,$now);
        $site['owner_id']=null;$site['level']=1;World::save($site);
    }
    private static function installRing(int $keeper,int $q,int $r): array
    {
        $ids=[];$now=time();$state=json_encode(World::initialState('fortification',1,$now),JSON_THROW_ON_ERROR);
        $pdo=Db::pdo();
        $add=$pdo->prepare("INSERT INTO world_sites(kind,q,r,owner_id,home_user_id,name,level,state_json,revision,updated_at) VALUES('fortification',?,?,?,NULL,?,1,?,1,?)");
        foreach(World::neighbors($q,$r) as $n){
            $add->execute([$n['q'],$n['r'],$keeper,'Home Defender '.self::local($n['q']).' · '.$n['r'],$state,$now]);
            $ids[]=(int)$pdo->lastInsertId();
        }
        return $ids;
    }

    public static function act(int $me,array $in): array
    {
        $op=$in['op']??'';
        if(!in_array($op,['relocate','near','own','invite','accept','decline','withdraw'],true))throw new RuntimeException('Choose a regional map action.');
        $request=$in['request']??'';
        if(!is_string($request)||!preg_match('/^[a-f0-9]{32}$/D',$request))throw new RuntimeException('Refresh the regional Map Room before trying again.');
        // Invitation IDs are opaque. Only the recipient can look up its sender and site.
        $pre=Game::loadBase($me);$offer=null;$other=0;
        if($op==='near'||$op==='invite')$other=self::validInt($in['friend']??null);
        if($op==='withdraw'){
            $s=World::load(self::validInt($in['site']??null));
            if($s['owner_id']===$me)$other=(int)($s['state']['regionInvite']['recipient']??0);
        }
        if($op==='accept'||$op==='decline'){
            $token=$in['offer']??'';if(!is_string($token))throw new RuntimeException('Choose an invitation.');
            $offer=$pre['regionTravel']['offers'][$token]??null;
            if($offer)$other=$offer['sender'];
        }
        if($other===$me)throw new RuntimeException('Choose another keeper.');
        Db::transaction(static function()use($me,$in,$op,$request,$other,$offer):void{
            $ids=$other?[$me,$other]:[$me];sort($ids,SORT_NUMERIC);$bases=[];
            foreach($ids as $id)$bases[$id]=Game::loadBase($id,true);
            $b=&$bases[$me];$now=time();
            if(in_array($request,$b['regionTravel']['receipts']??[],true))return;
            self::allocator();
            $homeRow=self::get('SELECT * FROM world_sites WHERE home_user_id=? FOR UPDATE',[$me])[0]??null;
            if(!$homeRow)throw new RuntimeException('The keeper has no map home.');
            $home=World::load((int)$homeRow['id'],true);
            if((int)($in['revision']??-1)!==$home['revision'])throw new RuntimeException('Your map position changed. Refresh the regions list.');
            $old=self::id($home['q']);$site=null;$friend=null;$region=$old;$accept=$op==='accept';
            if($op==='accept'||$op==='decline'){
                if(!$offer || ($b['regionTravel']['offers'][$offer['token']]??null)!==$offer)throw new RuntimeException('That invitation has already been answered.');
                $exists=self::get('SELECT id FROM world_sites WHERE id=? FOR UPDATE',[$offer['site']]);
                $site=$exists?World::load($offer['site'],true):null;
            }
            if($op==='decline'){
                unset($b['regionTravel']['offers'][$offer['token']]);
                if(($site['state']['regionInvite']['token']??null)===$offer['token']){unset($site['state']['regionInvite']);World::save($site);}
            }elseif($op==='withdraw'){
                $site=World::load(self::validInt($in['site']??null),true);$entry=$site['state']['regionInvite']??null;
                if(!$other || $site['owner_id']!==$me || !$entry || $entry['recipient']!==$other || $entry['token']!==($in['offer']??null))throw new RuntimeException('That invitation has changed.');
                unset($bases[$other]['regionTravel']['offers'][$entry['token']],$site['state']['regionInvite']);
                World::save($site);Game::saveBase($other,$bases[$other]);
            }elseif($op==='invite'){
                if(!self::friends($me,$other)) throw new RuntimeException('Select an accepted friend.');
                $site=World::load(self::validInt($in['site']??null),true);
                if($site['owner_id']!==$me||$site['kind']!=='resource'||self::versionForQ($site['q'])!==2)throw new RuntimeException('Choose one of your frontier outposts.');
                if(!empty($site['state']['regionInvite']))throw new RuntimeException('That outpost already has an unanswered invitation.');
                $entry=['token'=>$request,'site'=>$site['id'],'sender'=>$me,'senderName'=>$home['name'],'region'=>self::id($site['q']),'x'=>self::local($site['q']),'y'=>$site['r']];
                $bases[$other]['regionTravel']['offers'][$request]=$entry;
                $site['state']['regionInvite']=['token'=>$request,'recipient'=>$other];World::save($site);Game::saveBase($other,$bases[$other]);
            }else{
                if($op==='near'){
                    if(!self::friends($me,$other))throw new RuntimeException('Move near an accepted friend.');
                    $friend=World::home($other);$region=self::id($friend['q']);
                    if(self::version($region)!==3)throw new RuntimeException('Your friend is in a Frontier region. Ask for an outpost invitation.');
                }elseif($accept){
                    if(!$site||!self::friends($me,$other)||$site['owner_id']!==$other||($site['state']['regionInvite']['token']??null)!==$offer['token'])throw new RuntimeException('This invitation is no longer available. Decline it to clear your list.');
                    $region=self::id($site['q']);self::idle($other);
                }elseif($op==='own'){
                    $site=World::load(self::validInt($in['site']??null),true);
                    if($site['owner_id']!==$me||$site['kind']!=='resource'||self::versionForQ($site['q'])!==2)throw new RuntimeException('Choose your own frontier outpost.');
                    if(!empty($site['state']['regionInvite']))throw new RuntimeException('That outpost is reserved for an invited friend.');
                    $region=self::id($site['q']);
                }else{$region=self::checked($in['region']??null);}
                if(Game::livingLevel($b,'maproom')<($region===1?2:self::version($region)))throw new RuntimeException('Upgrade or repair the Map Room to enter this region.');
                if(($b['regionTravel']['after']??0)>$now)throw new RuntimeException('Your previous relocation is still on its 24-hour cooldown.');
                self::idle($me);
                if(($b['baiterPractice']['state']['status']??null)==='active')throw new RuntimeException('Finish the current home defense before relocating.');
                if($old!==$region || $accept || ($op==='relocate'&&self::version($old)===2))self::ensureAllianceFree($me);
                $estate=self::estate($me);$leaving=($old!==$region||$accept);
                if($op==='relocate'&&self::version($old)===2 && $estate)throw new RuntimeException('Abandon your Frontier outposts before a random relocation.');
                if(!hash_equals(self::signature($estate),is_string($in['estateKey']??null)?$in['estateKey']:''))throw new RuntimeException('Your territories changed. Review the current relocation terms.');
                $ring=$b['regionTravel']['ring']??[];
                if($site) {
                    if(!empty($site['state']['mr2Outpost']['claim']))throw new RuntimeException('Repair the breached destination outpost before relocating.');
                    if(($site['state']['ready_at']??0)>$now || ($site['state']['repair_at']??0)>$now || self::get("SELECT id FROM battles WHERE status='active' AND target_kind<>'home' AND target_id=? LIMIT 1",[$site['id']]))throw new RuntimeException('The destination outpost is busy.');
                    Game::accrue($b,$now);self::pay($b,$accept,(string)($in['payment']??''));
                    if($accept)Game::accrue($bases[$other],$now);
                    $x=$site['q'];$y=$site['r'];
                }else{self::seed($region);[$x,$y]=self::freePosition($region,$friend);}
                Game::accrue($b,$now);
                foreach($estate as $owned){
                    if($site && $owned['id']===$site['id'])continue;
                    $item=World::load($owned['id'],true);
                    if(in_array($item['id'],$ring,true)){self::write('DELETE FROM world_sites WHERE id=?',[$item['id']]);}
                    elseif($leaving){self::clearSite($item,$now);}
                }
                if($site){
                    // The surrendered field yard is consumed. Historical battles retain their frozen snapshots.
                    self::write('DELETE FROM world_sites WHERE id=?',[$site['id']]);
                    if($accept)unset($b['regionTravel']['offers'][$offer['token']]);
                }
                self::write('UPDATE world_sites SET q=?,r=?,revision=revision+1,updated_at=? WHERE id=?',[$x,$y,$now,$home['id']]);
                $b['regionTravel']['ring']=($region!==1&&self::version($region)===3)?self::installRing($me,$x,$y):[];
                $b['regionTravel']['after']=self::version($region)===2?$now+self::MOVE_WAIT:0;
                World::syncBonuses($me,$b);
                if($accept){World::syncBonuses($other,$bases[$other]);Game::saveBase($other,$bases[$other]);}
            }
            $history=$b['regionTravel']['receipts']??[];$history[]=$request;$b['regionTravel']['receipts']=array_slice($history,-16);Game::saveBase($me,$b);
        });
        return self::overview($me);
    }
}
