<?php
declare(strict_types=1);
require_once __DIR__.'/InfernoDefense.php';
require_once __DIR__.'/InfernoCompound.php';
require_once __DIR__.'/InfernoRivals.php';

/** The keeper's underground settlement, local economy, and Moloch campaign. */
final class InfernoRealm
{
    private const CURRENCIES = ['bone','coal','sulfur','magma'];
    private const PRODUCERS = ['twig'=>'bone','pebble'=>'coal','putty'=>'sulfur','goo'=>'magma'];
    private const ENTRY = ['twig'=>1800,'pebble'=>1600,'putty'=>600,'goo'=>0];
    private const MAX_STRUCTURES = 70;
    private const SPECS = [
        // Name, required Under Hall, limit, bone/coal/sulfur/magma cost, base construction time.
        'townhall'=>['Under Hall',1,1,900,900,350,0,60],
        'twig'=>['Bone Cruncher',1,4,0,200,0,0,16],'pebble'=>['Coal Extractor',1,4,200,0,0,0,16],
        'putty'=>['Sulfur Swirler',1,4,200,220,0,0,20],'goo'=>['Magma Pump',1,4,230,230,0,0,20],
        'storage'=>['Resource Pod',1,5,550,650,0,0,26],'hatchery'=>['Incubator',1,3,650,450,100,0,30],
        'housing'=>['Compound',1,5,550,420,70,0,24],'flinger'=>['Flinger',1,1,650,680,100,0,25],
        'wall'=>['Inferno Block',1,35,50,70,0,0,5],'sniper'=>['Sharpshooter',1,5,520,600,120,0,34],
        'locker'=>['Inferno Monster Locker',1,1,900,800,170,0,38],'academy'=>['Infernal Academy',2,1,900,800,350,0,45],
        'infernocannon'=>['Blast Tower',2,4,680,720,250,0,45],'quaketower'=>['Quake Tower',3,3,1150,1000,420,0,67],
        'magmatower'=>['Magma Tower',4,2,1550,1500,600,250,80],
    ];
    private const OWN_ART=['townhall','twig','pebble','putty','goo','storage','hatchery','housing','academy','locker','flinger','sniper','cannon','wall'];

    public static function catalog():array
    {
        static $result;
        if($result!==null)return $result;
        $result=[]; $buildings=Catalog::buildings();
        foreach(self::SPECS as $type=>[$name,$gate,$limit,$bone,$coal,$sulfur,$magma,$time]){
            if(!isset($buildings[$type]))continue;
            $spec=$buildings[$type];
            $max=in_array($type,['townhall','housing'],true)?6:(int)$spec['maxLevel'];
            $tiers=[];
            for($level=1;$level<=$max;++$level){
                if(!empty($spec['infernoOnly'])){
                    $tier=$spec['levels'][$level];
                    $tiers[$level]=['cost'=>array_combine(self::CURRENCIES,array_values($tier['cost'])),
                        'gate'=>$tier['townHall'],'time'=>$tier['buildTime']];
                }else{
                    $tiers[$level]=['cost'=>array_map(static fn(int $v):int=>(int)ceil($v*(1.72**($level-1))),compact('bone','coal','sulfur','magma')),
                        'gate'=>$type==='townhall'?max(1,$level-1):max($gate,$level),'time'=>(int)ceil($time*(1.48**($level-1)))];
                }
            }
            $result[$type]=['name'=>$name,'size'=>$spec['size'],'gate'=>$tiers[1]['gate'],'limit'=>$limit,'time'=>$tiers[1]['time'],
                'cost'=>$tiers[1]['cost'],'maxLevel'=>$max,'tiers'=>$tiers,'art'=>self::art($type),'produces'=>self::PRODUCERS[$type]??null];
            if($type==='housing')$result[$type]['interceptionRanges']=InfernoCompound::RANGES;

        }
        return $result;
    }
    public static function art(string $type):string {return in_array($type,self::OWN_ART,true)?'inferno-'.$type:$type;}

    private static function facility(string $type,int $id,float $x,float $y,int $level):array
    {
        $hp=Game::buildingStats($type,max(1,$level),true)['max_hp'];
        return ['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'level'=>$level,'hp'=>$level?$hp:0,'max_hp'=>$hp,
            'ready_at'=>0,'upgrade_to'=>null,'stored'=>0,'infernoArt'=>self::art($type)];
    }

    public static function initial(int $now):array
    {
        $realm=InfernoMonsters::initial();
        $realm['resources']=['bone'=>4300,'coal'=>4100,'sulfur'=>3000,'magma'=>5200];
        $realm['army']['spurtz']=8;
        $realm['directoryMember']=true;
        $realm['last_tick']=$now; $realm['revision']=1; $realm['buildings']=[];
        $realm['campaign']=['cleared'=>[],'pending'=>[],'battleId'=>null];
        $realm['next_building_id']=1;
        foreach([['townhall',16,16],['twig',10,18],['pebble',21,20],['putty',21,14],['goo',16,22],['flinger',8,8],
            ['hatchery',12,12],['housing',20,9],['sniper',10,23]] as [$type,$x,$y]){
            $realm['buildings'][]=self::facility($type,$realm['next_building_id']++,$x,$y,1);
        }
        return $realm;
    }

    /** Called under the bot's normal home-row lock. The human and bot entrance debit is identical. */
    public static function enterBot(array &$home,int $now,int $botId):bool
    {
        if(isset($home['infernoRealm'])||Game::livingLevel($home,'townhall')<2)return false;
        foreach(self::ENTRY as $key=>$cost)if(($home['resources'][$key]??0)<$cost)return false;
        self::spend($home['resources'],self::ENTRY);$home['infernoRealm']=self::initial($now);
        if(!empty($home['packingLevel']))$home['infernoRealm']['packingLevel']=$home['packingLevel'];
        InfernoRivals::enroll($botId);return true;
    }

    /** Bot planner and human controls use the same operations, prices, limits and timers. */
    public static function botOrder(array &$realm,string $op,array $in,int $now):void
    {
        if(InfernoDefense::active($realm))throw new RuntimeException('Resolve the ongoing Moloch counterattack first.');
        self::accrue($realm,$now);
        $in+=['monsterRevision'=>$realm['monsterRevision']];
        self::order($realm,$op,$in,$now);++$realm['revision'];
    }

    public static function capacity(array $r):int
    {
        $capacity=12000; foreach($r['buildings'] as $b){if($b['type']==='storage' && $b['level']>0)$capacity+=12000*$b['level'];}
        return StorePowers::materialCapacity($r,$capacity);
    }

    public static function accrue(array &$r,int $now):void
    {
        $start=min($now,$r['last_tick']);$speed=Game::speed();
        foreach($r['buildings'] as &$b){
            $due=(int)$b['ready_at'];$level=(int)$b['level'];
            $end=$due>0?max($start,min($now,$due)):$now;
            if(isset(self::PRODUCERS[$b['type']])){
                $rate=$b['type']==='goo'?3.0:2.0;
                $b['stored']=min($level*2000,$b['stored']+($b['hp']>0 && $due===0?$rate*$level*($end-$start)*$speed:0));
            }
            if($due>0 && $due<=$now){
                $b['level']=(int)$b['upgrade_to'];$b['upgrade_to']=null;$b['ready_at']=0;
                $b['hp']=$b['max_hp']=Game::buildingStats($b['type'],$b['level'],true)['max_hp'];
                if(isset(self::PRODUCERS[$b['type']]))$b['stored']=min($b['level']*2000,$b['stored']+$rate*$b['level']*($now-$end)*$speed);
            }
        }unset($b);
        InfernoMonsters::accrue($r,$now,$speed);
        $r['last_tick']=$now;
    }

    public static function cost(string $type,int $level):array
    {
        $spec=self::catalog()[$type]??throw new RuntimeException('Choose an available underground structure.');
        return $spec['tiers'][$level]['cost']??throw new RuntimeException('Choose a valid construction tier.');
    }
    private static function spend(array &$wallet,array $cost):void
    {
        foreach($cost as $key=>$amount)if(($wallet[$key]??0)<$amount)throw new RuntimeException('You need more '.$key.' for this order.');
        foreach($cost as $key=>$amount)$wallet[$key]-=$amount;
    }
    public static function campCatalog():array
    {
        static $rows;
        if($rows===null){
            $rows=[];foreach(json_decode(file_get_contents(__DIR__.'/inferno-camps.json'),true,512,JSON_THROW_ON_ERROR) as $row){
                $id=$row['sourceId'];$route=$id<=213?'Descent':($id<=220?'Outer caverns':($id<=227?'Deep caverns':'Molten core'));
                $offset=$id<=213?200:($id<=220?213:($id<=227?220:227));
                $number=$id-$offset;
                $row+=['stage'=>$id,'route'=>$route,'name'=>'Moloch · '.$route.' '.$number,'reward'=>[
                    'bone'=>250+($id-201)*90,'coal'=>260+($id-201)*85,'sulfur'=>175+($id-201)*60,'magma'=>420+($id-201)*110]];
                $rows[$id]=$row;
            }
        }
        return $rows;
    }
    private static function available(array $r,int $id):bool
    {
        return match($id){201=>true,214=>isset($r['campaign']['cleared'][201]),default=>isset($r['campaign']['cleared'][$id-1])};
    }
    public static function stage(array $r,int $stage):array
    {
        $camp=self::campCatalog()[$stage]??throw new RuntimeException('Choose a Moloch camp on the expedition map.');
        if(!self::available($r,$stage))throw new RuntimeException('Clear the preceding cavern to open this route.');
        return $camp;
    }

    public static function campYard(array $camp):array
    {
        $yard=['buildings'=>[],'research'=>[],'garrison'=>[]];$catalog=Catalog::buildings();
        foreach($camp['buildings'] as $i=>[$type,$x,$y,$level]){
            if(!isset($catalog[$type]))continue;
            $max=self::catalog()[$type]['maxLevel']??$catalog[$type]['maxLevel'];
            $yard['buildings'][]=self::facility($type,$i+1,$x,$y,min($level,$max));
        }
        return $yard;
    }

    private static function campCard(array $r,array $camp):array
    {
        $card=array_intersect_key($camp,array_flip(['stage','route','name','reward']));
        $card['open']=self::available($r,$camp['stage']);$card['best']=$r['campaign']['cleared'][$camp['stage']]??null;
        return $card;
    }

    public static function view(int $id):array
    {
        return Db::transaction(static function()use($id):array{
            $b=Game::loadBase($id,true);$now=time();$owner=Game::user($id);
            if(isset($b['infernoRealm'])){
                if(empty($b['infernoRealm']['directoryMember'])){InfernoRivals::enroll($id);$b['infernoRealm']['directoryMember']=true;}
                self::accrue($b['infernoRealm'],$now);
                Game::saveBase($id,$b);
            }
            return self::snapshot($b,$owner,$now);
        });
    }
    private static function snapshot(array $b,array $owner,int $now):array
    {
        if(!isset($b['infernoRealm'])){
            Game::accrue($b,$now);
            if (InfernoEmergence::freePassage($b)) { return ['opened'=>false,'entryCost'=>[],'canEnter'=>true,'emergencePass'=>true]; }
            return ['opened'=>false,'entryCost'=>self::ENTRY,'canEnter'=>Game::livingLevel($b,'townhall')>=2&&!array_filter(self::ENTRY,fn($v,$k)=>($b['resources'][$k]??0)<$v,ARRAY_FILTER_USE_BOTH)];
        }
        $r=$b['infernoRealm'];$progress=$r['campaign'];
        return ['opened'=>true,'revision'=>$r['revision'],'previousId'=>$progress['battleId'],'catalog'=>self::catalog(),
            'counterattack'=>InfernoDefense::summary($r),'monsters'=>InfernoMonsters::view($r,$now,Game::speed()),'capacity'=>self::capacity($r),
            'camps'=>array_values(array_map(fn($camp)=>self::campCard($r,$camp),self::campCatalog())),
            'cleared'=>count($progress['cleared']),'pending'=>$progress['pending'],'now'=>$now,'speed'=>Game::speed(),
            'base'=>['realm'=>'inferno','yardExtent'=>YardSpace::extent($r),'infernoHome'=>true,'infernoPortal'=>'return','owner'=>$owner,'buildings'=>YardUtilities::hatcheryWork($r,$now),'army'=>$r['army'],'resources'=>$r['resources']]];
    }
    public static function visit(int $id,int $stage):array
    {
        $b=Game::loadBase($id);$r=$b['infernoRealm']??throw new RuntimeException('Open your Inferno entrance first.');
        $camp=self::stage($r,$stage);$yard=self::campYard($camp);
        return ['realm'=>'inferno','infernoCamp'=>self::campCard($r,$camp),'owner'=>['id'=>0,'name'=>$camp['name']],
            'buildings'=>array_values(array_filter($yard['buildings'],[Catalog::class,'visibleToRivals'])),'army'=>[],'previousId'=>$r['campaign']['battleId']];
    }

    public static function act(int $id,array $input):array
    {
        return Db::transaction(static function(PDO $pdo)use($id,$input):array{
            $b=Game::loadBase($id,true);$owner=Game::user($id);$now=time();$op=$input['action']??null;
            $token=$input['requestId']??null;
            if(!is_string($op)||!is_string($token)||!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$token))throw new RuntimeException('Refresh the Inferno controls and try again.');
            $current=$b['infernoRealm']??null;
            if(($current['lastRequest']??null)===$token)return self::snapshot($b,$owner,$now);
            $busy=$pdo->prepare("SELECT id FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?) LIMIT 1");$busy->execute([$id,$id]);
            if($busy->fetchColumn())throw new RuntimeException('Finish the current battle before changing your cavern.');
            if($op==='enter'){
                if(!$current){
                    Game::accrue($b,$now);
                    if (!InfernoEmergence::freePassage($b)) {
                        if(Game::livingLevel($b,'townhall')<2)throw new RuntimeException('A ready level 2 Town Hall is required to open the Inferno entrance.');
                        self::spend($b['resources'],self::ENTRY);
                    }
                    $b['infernoRealm']=self::initial($now);
                    if(!empty($b['packingLevel']))$b['infernoRealm']['packingLevel']=$b['packingLevel'];
                    InfernoRivals::enroll($id);
                }
            }else{
                if(!$current)throw new RuntimeException('Open your Inferno entrance first.');
                $r=&$b['infernoRealm'];self::accrue($r,$now);
                if(($input['realmRevision']??null)!==$r['revision'])throw new RuntimeException('Your underground yard changed. Reopen the controls.');
                if(InfernoDefense::active($r))throw new RuntimeException('Resolve the ongoing Moloch counterattack first.');
                self::order($r,$op,$input,$now,true);
                ++$r['revision'];
            }
            $b['infernoRealm']['lastRequest']=$token;Game::saveBase($id,$b);
            return self::snapshot($b,$owner,$now);
        });
    }
    private static function order(array &$r,string $op,array $input,int $now,bool $journal=false):void
    {
        if($op==='repair'){
            foreach($r['buildings'] as &$building)if($building['level']>0)$building['hp']=$building['max_hp'];
            unset($building);
        }elseif(in_array($op,['infernoTrain','infernoUnlock','infernoResearch','infernoCancel'],true))InfernoMonsters::act($r,$op,$input,$now,Game::speed());
        elseif($op==='collect'){
            $banked=0;$cap=self::capacity($r);foreach($r['buildings'] as &$building){
                $resource=self::PRODUCERS[$building['type']]??null;if(!$resource)continue;
                $amount=max(0,min((int)$building['stored'],(int)floor($cap-$r['resources'][$resource])));
                $r['resources'][$resource]+=$amount;$building['stored']-=$amount;$banked+=$amount;
            }unset($building);
            if($journal && $banked>($r['bestQuestHarvest']??0)) { $r['bestQuestHarvest']=min(50000,$banked); }
            self::transferRewards($r);
        }else self::construction($r,$op,$input,$now);
    }
    private static function construction(array &$r,string $op,array $in,int $now):void
    {
        if(!in_array($op,['build','move','upgrade'],true))throw new RuntimeException('Choose an underground yard action.');
        $id=$in['id']??0;$index=null;
        foreach($r['buildings'] as $i=>$b)if($b['id']===$id){$index=$i;break;}
        if($op!=='build'&&$index===null)throw new RuntimeException('That underground building no longer exists.');
        $type=$op==='build'?($in['type']??''):$r['buildings'][$index]['type'];$spec=is_string($type)?self::catalog()[$type]??null:null;
        if(!$spec)throw new RuntimeException('Choose a valid Inferno structure.');
        $level=$op==='build'?1:$r['buildings'][$index]['level']+1;$hall=Game::livingLevel($r,'townhall');
        if($op!=='build' && $r['buildings'][$index]['ready_at']>0)throw new RuntimeException('Wait for the current building work to finish.');
        if($op!=='upgrade'){
            $x=$in['x']??null;$y=$in['y']??null;
            if(!is_numeric($x)||!is_numeric($y))throw new RuntimeException('Select a position in the cavern.');
            $x=(float)$x;$y=(float)$y;$half=$spec['size']/2;
            if(!YardSpace::contains($r,$x,$y,1+$half))throw new RuntimeException('Place the whole structure inside the cavern.');
            foreach($r['buildings'] as $other){
                if($op==='move'&&$other['id']===$id)continue;
                $gap=$half+self::catalog()[$other['type']]['size']/2+.15;
                if(abs($x-$other['x'])<$gap-.00001&&abs($y-$other['y'])<$gap-.00001)throw new RuntimeException('That position overlaps another cavern structure.');
            }
            if($op==='move'){$r['buildings'][$index]['x']=$x;$r['buildings'][$index]['y']=$y;return;}
        }
        if(array_filter($r['buildings'],fn($b)=>$b['ready_at']>0))throw new RuntimeException('Your cavern worker is busy.');
        if($op==='build'){
            if(count($r['buildings'])>=self::MAX_STRUCTURES || count(array_filter($r['buildings'],fn($b)=>$b['type']===$type))>=$spec['limit'])throw new RuntimeException('The cavern building limit has been reached.');
        }
        if($level>$spec['maxLevel'])throw new RuntimeException('This structure is already at its highest level.');
        if($hall<$spec['tiers'][$level]['gate'])throw new RuntimeException('Upgrade your Under Hall before starting this work.');
        self::spend($r['resources'],self::cost($type,$level));
        if($op==='build'){$r['buildings'][]=self::facility($type,$r['next_building_id']++,$x,$y,0);$index=array_key_last($r['buildings']);}
        $r['buildings'][$index]['ready_at']=$now+(int)ceil($spec['tiers'][$level]['time']/Game::speed());
        $r['buildings'][$index]['upgrade_to']=$level;
    }
    public static function retry(array $r,int $stage,array $input):?int
    {
        $token=$input['requestId']??null;
        if(!is_string($token)||!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$token))throw new RuntimeException('Reopen the Moloch camp to invade.');
        $c=$r['campaign'];
        if(($c['request']??null)===$token){
            if($stage!==$c['stage'])throw new RuntimeException('This launch belongs to another camp.');
            return $c['battleId'];
        }
        if(!array_key_exists('previousId',$input)||$input['previousId']!==$c['battleId'])throw new RuntimeException('Your descent changed. Scout again.');
        return null;
    }
    public static function finish(array &$r,array &$result,array $target):void
    {
        $id=$target['stage'];$first=$result['attackerWon']&&!isset($r['campaign']['cleared'][$id]);
        if($result['attackerWon'])$r['campaign']['cleared'][$id]=max($result['destruction'],$r['campaign']['cleared'][$id]??0);
        $result['target']=$target;$result['rankDelta']=['attacker'=>0,'defender'=>0];$result['loot']=array_fill_keys(self::CURRENCIES,0);
        if($first){
            foreach($target['reward'] as $key=>$amount)$r['campaign']['pending'][$key]=($r['campaign']['pending'][$key]??0)+max(0,$amount-($r['campaign']['raided'][$id][$key]??0));
            unset($r['campaign']['raided'][$id]);
            $result['loot']=self::transferRewards($r);
        }
        foreach ($result['raiderLoot']??[] as $key=>$amount) { $result['loot'][$key]+=$amount; }
        $result['inferno']=['firstClear'=>$first,'pending'=>$r['campaign']['pending']];
        ++$r['revision'];
    }
    private static function transferRewards(array &$r):array
    {
        $moved=[];$cap=self::capacity($r);
        foreach(self::CURRENCIES as $key){
            $balance=$r['campaign']['pending'][$key]??0;
            $moved[$key]=min($balance,max(0,(int)floor($cap-$r['resources'][$key])));
            $r['resources'][$key]+=$moved[$key];$r['campaign']['pending'][$key]=$balance-$moved[$key];
        }
        return $moved;
    }
}
