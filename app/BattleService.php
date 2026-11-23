<?php
declare(strict_types=1);
require_once __DIR__.'/BattleEngine.php';
require_once __DIR__.'/AllianceWars.php';
require_once __DIR__.'/AlliancePowers.php';

/** Transactional bridge between persistent yards and the authoritative battle engine. */
final class BattleService
{
    /** Existing directory raids intentionally keep their unrestricted strategic range. */
    public static function start(int $attackerId, int $defenderId): array
    {
        if ($attackerId <= 0 || $defenderId <= 0 || $attackerId === $defenderId) {
            throw new RuntimeException('Choose another yard to invade.');
        }
        return self::startTarget($attackerId, $defenderId, null);
    }

    public static function startSite(int $attackerId, int $siteId): array
    {
        if($attackerId<=0 || $siteId<=0){throw new RuntimeException('Choose an existing world site to invade.');}
        return self::startTarget($attackerId, null, $siteId);
    }

    public static function startField(int $keeper,int $origin,array $target):array
    {
        return self::startTarget($keeper,$target['kind']==='home'?$target['home_user_id']:null,$target['kind']==='home'?null:$target['id'],null,['fieldOrigin'=>$origin]);
    }

    public static function startTribe(int $attackerId, int $stage, array $request): array
    {
        return self::startTarget($attackerId, null, null, $stage, $request);
    }

    public static function startInferno(int $attackerId,int $stage,array $request):array
    {
        return self::startTarget($attackerId,null,null,$stage,$request,true);
    }

    public static function startInfernoRival(int $attackerId,int $defenderId,array $request=[]):array
    {
        if($attackerId<=0||$defenderId<=0||$attackerId===$defenderId)throw new RuntimeException('Choose another keeper’s cavern.');
        return self::startTarget($attackerId,$defenderId,null,null,$request,true);
    }

    private static function startTarget(int $attackerId, ?int $homeDefenderId, ?int $siteId, ?int $stage=null, array $request=[],bool $inferno=false): array
    {
        // Probe outside the transaction so waiting for locks cannot freeze an old ownership snapshot.
        $candidate=$siteId===null?null:World::load($siteId);
        return Db::transaction(static function (PDO $pdo) use ($attackerId, $homeDefenderId, $siteId, $candidate, $stage, $request, $inferno): array {
            if($candidate!==null && !in_array($candidate['kind'],['resource','stronghold','fortification'],true)){
                throw new RuntimeException('Use the home-yard invasion action for this keeper.');
            }
            $defenderId=$candidate===null?(int)$homeDefenderId:(int)($candidate['owner_id']??0);
            if($defenderId===$attackerId){throw new RuntimeException('You already own this site.');}
            // All combat and management paths lock real home rows before any site or battle.
            $bases = self::lockBases($attackerId, $defenderId);
            $site=$siteId===null?null:World::load($siteId,true);
            if($site!==null && ((int)($site['owner_id']??0)!==$defenderId || (int)$site['revision']!==(int)$candidate['revision'])){
                throw new RuntimeException('This site changed while your invasion was starting. Scout it again.');
            }
            $now = time();
            Truces::guard($bases[$attackerId], $defenderId, $now);
            $attacker = self::user($attackerId);
            $a =& $bases[$attackerId];
            $cavernRaid=$inferno && $stage===null;
            if($cavernRaid){
                if(!isset($a['infernoRealm'],$bases[$defenderId]['infernoRealm']))throw new RuntimeException('Both keepers need an open Inferno entrance.');
                if($attacker['is_bot']){
                    if(!WorldActivity::clock()['online'])throw new RuntimeException('Bot invasions pause while no players are online.');
                }else{
                    $existing=InfernoRivals::retry($a['infernoRealm'],$defenderId,$request);
                    if($existing!==null){
                        $prior=$pdo->prepare("SELECT state_json FROM battles WHERE id=? AND attacker_id=? AND defender_id=? AND target_kind='infernoRival'");
                        $prior->execute([$existing,$attackerId,$defenderId]);
                        return self::snapshot(json_decode($prior->fetchColumn(),true,512,JSON_THROW_ON_ERROR),$attackerId);
                    }
                }
                if(InfernoDefense::active($a['infernoRealm'])||InfernoDefense::active($bases[$defenderId]['infernoRealm']))throw new RuntimeException('A Moloch counterattack is active in one of these caverns.');
            }
            if ($stage !== null) {
                if ($attacker['is_bot']) { throw new RuntimeException('Only a player can lead a wild expedition.'); }
                if ($inferno && !isset($a['infernoRealm'])) { throw new RuntimeException('Open your Inferno entrance first.'); }
                $existing = $inferno ? InfernoRealm::retry($a['infernoRealm'],$stage,$request) : Tribes::retryId($a,$request,$stage);
                if ($existing !== null) {
                    $prior = $pdo->prepare("SELECT state_json FROM battles WHERE id=? AND attacker_id=? AND target_kind=?");
                    $prior->execute([$existing,$attackerId,$inferno?'inferno':'tribe']);
                    return self::snapshot(json_decode($prior->fetchColumn(),true,512,JSON_THROW_ON_ERROR),$attackerId);
                }
                if ($inferno && InfernoDefense::active($a['infernoRealm'])) { throw new RuntimeException('Resolve the current Moloch counterattack before invading.'); }
                if(!$inferno&&HellRaisers::contains($stage)){
                    Game::accrue($a,$now);
                    $camp=HellRaisers::begin($a,$stage,$request['run']??null,$now);
                }elseif(!$inferno&&CreatureCarnage::contains($stage)){
                    Game::accrue($a,$now);
                    $camp=CreatureCarnage::begin($a,$stage,$request['run']??null,$now);
                }else{
                    $camp=$inferno?InfernoRealm::stage($a['infernoRealm'],$stage):Tribes::stage($a,$stage);
                }
                if (!$inferno && Brukkarg::contains($stage)) {
                    if (isset($a['brukkargWar']['cleared'][$stage])) {throw new RuntimeException('You already cleared this Brukkarg camp.');}
                    if (($a['baiterPractice']['state']['status']??null)==='active') {throw new RuntimeException('Resolve your current home defense first.');}
                }
            }
            if(isset($a['baiterPractice']['state']['molochWave']) && $a['baiterPractice']['state']['status']==='active'){
                throw new RuntimeException('Finish or surrender Moloch’s siege before launching an invasion.');
            }
            if (isset($a['baiterPractice']['state']['emergenceWave']) && $a['baiterPractice']['state']['status']==='active') {
                throw new RuntimeException('Resolve the active Inferno Emergence defense before launching an invasion.');
            }
            if (isset($a['baiterPractice']['state']['wmiWave']) && $a['baiterPractice']['state']['status']==='active') {
                throw new RuntimeException('Finish or surrender your Wild Monster Invasion before attacking.');
            }
            $defender = $defenderId>0?self::user($defenderId):['id'=>0,'name'=>$camp['name']??$site['name'],'is_bot'=>false,'neutral'=>true];
            $busy = $pdo->prepare("SELECT id FROM battles WHERE status='active' AND (attacker_id IN (?,?) OR defender_id IN (?,?)) LIMIT 1");
            $busy->execute([$attackerId, $defenderId, $attackerId, $defenderId]);
            if ($busy->fetch()) {
                throw new RuntimeException('One of these keepers is already in a battle. Finish that invasion first.');
            }
            Game::accrue($a, $now);
            $origin=null;
            if(isset($request['fieldOrigin'])&&!$inferno&&$stage===null){
                $origin=World::load((int)$request['fieldOrigin'],true);$dest=$site??World::home($defenderId);
                if(!OutpostYards::is($origin)||$origin['owner_id']!==$attackerId||!$dest||World::distance($dest['q'],$dest['r'],$origin['q'],$origin['r'])>2+AlliancePowers::rangeBonus($attackerId))throw new RuntimeException('Choose a nearby target within the reach of your own regional outpost.');
                if(!empty($origin['state']['mr2Outpost']['claim']))throw new RuntimeException('Repair your breached outpost before launching.');
                World::refreshSite($origin,$now);$party=$origin['state'];$party['research']=$a['research'];$party['lab']=$a['lab'];
                $origin['state']['shield_until']=0;World::save($origin);
            }elseif ($inferno) {
                $party =& $a['infernoRealm'];
                InfernoRealm::accrue($party,$now);
            } else {
                $party =& $a;
            }
            if ($stage !== null) {
                $d = $inferno ? InfernoRealm::campYard($camp) : Tribes::yard($camp);
                $target = ['kind'=>$inferno?'inferno':'tribe','id'=>null,'stage'=>$stage,'sourceId'=>$camp['sourceId'],'tribe'=>$camp['tribe']??'Moloch',
                    'name'=>$camp['name'],'reward'=>$camp['reward'],'q'=>null,'r'=>null,'ownerId'=>null,'captureThreshold'=>null];
                if (!$inferno && HellRaisers::contains($stage)) {$target+=['event'=>'hellraisers','run'=>$camp['run'],'endsAt'=>$camp['endsAt']];}
                if (!$inferno && Brukkarg::contains($stage)) {$target['event']='brukkarg';}
                if (!$inferno && CreatureCarnage::contains($stage)) {$target+=['event'=>'carnage','run'=>$camp['run'],'endsAt'=>$camp['endsAt']];}
            } elseif($cavernRaid){
                $d =& $bases[$defenderId]['infernoRealm'];InfernoRealm::accrue($d,$now);
                if(($d['shield_until']??0)>$now)throw new RuntimeException('This cavern is protected by a shield.');
                $target=['kind'=>'infernoRival','realm'=>'inferno','id'=>null,'name'=>$defender['name'].'’s cavern','q'=>null,'r'=>null,'ownerId'=>$defenderId,'captureThreshold'=>null];
            } elseif($site===null){
                $d =& $bases[$defenderId];
                Game::accrue($d, $now);
                WildMonsterInvasions::endForRaid($d,$now);
                if(($d['baiterPractice']['state']['status']??null)==='active' && isset($d['baiterPractice']['state']['molochWave'])){
                    $defense=&$d['baiterPractice'];Baiter::siren($defense['state']);MolochSiege::settle($d,$defense['state'],$now);++$defense['revision'];unset($defense);
                }
                if (($d['baiterPractice']['state']['status']??null)==='active' && isset($d['baiterPractice']['state']['emergenceWave'])) {
                    $defense=&$d['baiterPractice'];
                    Baiter::siren($defense['state']); InfernoEmergence::settle($d,$defense['state'],$now); ++$defense['revision']; unset($defense);
                }
                if((int)($d['shield_until']??0)>$now){throw new RuntimeException('This yard is protected by a shield. Choose another opponent.');}
                $home=World::home($defenderId);
                $target=['kind'=>'home','id'=>$home['id']??null,'name'=>$defender['name'],'q'=>$home['q']??null,'r'=>$home['r']??null,'ownerId'=>$defenderId,'captureThreshold'=>null];
            }else{
                $upgrading=(int)($site['state']['ready_at']??0)>0&&(int)$site['state']['ready_at']<=$now&&($site['state']['upgrade_to']??null)!==null;
                if($upgrading&&$defenderId>0){Game::accrue($bases[$defenderId],$now);}
                World::refreshSite($site,$now);
                World::assertAttack($attackerId,$a,$site,$origin===null?null:$party);
                $active=$pdo->prepare("SELECT id FROM battles WHERE status='active' AND target_kind=? AND target_id=? LIMIT 1");
                $active->execute([$site['kind'],$siteId]);
                if($active->fetch()){throw new RuntimeException('This site is already under attack.');}
                $d=$site['state'];
                if($defenderId>0){$d['research']=$bases[$defenderId]['research']??[];$d['lab']=$bases[$defenderId]['lab']??[];}
                $target=['kind'=>$site['kind'],'id'=>$siteId,'name'=>$site['name'],'q'=>(int)$site['q'],'r'=>(int)$site['r'],'ownerId'=>$defenderId?:null,'captureThreshold'=>90,'mr2Outpost'=>OutpostYards::is($site)];
            }
            $bonuses=$target['q']===null?['attacker'=>0.0,'defender'=>0.0]:World::combatBonuses($attackerId,$defenderId?:null,(int)$target['q'],(int)$target['r']);
            // Ownership changes take the same owner-home lock. Read support only
            // after these locks, then freeze it for this entire invasion.
            $networkDefense=$target['kind']==='fortification'||$target['q']===null||$defenderId<=0?['count'=>0,'reduction'=>0.0,'siteIds'=>[]]:World::fortificationSupport($defenderId,(int)$target['q'],(int)$target['r']);
            $attackingChampion=$inferno?null:self::readyChampion($party);
            $defendingChampion=!$inferno && $site===null && $stage===null?self::readyChampion($d):null;
            $krallen=$inferno||$origin!==null?null:KrallenHunt::ready($a,$now);
            if (array_sum($party['army']) < 1 && $attackingChampion===null && $krallen===null) {
                throw new RuntimeException('Hatch monsters or raise a healthy active champion before invading another yard.');
            }
            $flingerLevel=0;
            foreach($party['buildings'] as $building){if($building['type']==='flinger'&&$building['hp']>0&&$building['level']>0&&(int)($building['ready_at']??0)<=$now){$flingerLevel=max($flingerLevel,(int)$building['level']);}}
            if($flingerLevel<1){throw new RuntimeException('Build or repair a Flinger before starting a new invasion.');}
            $alive = array_filter($d['buildings'], static fn(array $b): bool => !in_array($b['type'],['wall','stonewall','trap','heavytrap'],true) && !(Catalog::buildings()[$b['type']]['untargetable']??false) && $b['hp'] > 0);
            if (!$alive) {
                throw new RuntimeException('This yard is rebuilding after an invasion. Choose another opponent.');
            }
            $offense=$inferno?[]:AlliancePowers::active($pdo,$attackerId,$now);
            $defense=$inferno?[]:AlliancePowers::active($pdo,$defenderId,$now);
            $catalog = Catalog::buildings();
            $buildings = [];
            foreach (YardUtilities::hatcheryWork($d,$now) as $b) {
                $spec = $catalog[$b['type']] ?? null;
                if ($spec === null) {
                    continue;
                }
                if($cavernRaid)unset($b['stored']);
                $frozen=self::freezeBuilding($b,$spec,$bonuses['defender'],$networkDefense['reduction'],$now);
                if(isset($defense['armament']))AlliancePowers::arm($frozen);
                $buildings[]=$frozen;
            }
            $s = [
                'id' => 0, 'status' => 'active', 'created_at' => $now,
                'elapsed' => 0.0, 'duration' => BattleEngine::DURATION,
                'attacker' => $attacker, 'defender' => $defender,
                'signs' => YardMessages::signs($d), 'decorations' => Decorations::visible($d), 'buildings' => $buildings, 'units' => [], 'events' => [], 'eventSeq' => 0,
                'unitSeq' => 0, 'obstacleVersion' => 0, 'result' => null,
                'remaining' => $party['army'], 'research' => $party['research'] ?? [],
                'settled' => false, 'formatVersion'=>7, 'target'=>$target, '_worldBonuses'=>$bonuses, 'networkDefense'=>$networkDefense,
                '_labLevels'=>$party['lab']['levels']??[],
                'catapult'=>!$inferno && Game::livingLevel($party,'catapult')>0?['level'=>Game::livingLevel($party,'catapult'),'used'=>['twig'=>false,'pebble'=>false,'putty'=>false],'resources'=>array_intersect_key($a['resources'],array_flip(['twig','pebble','putty'])),'pending'=>[],'shots'=>[]]:null,
                'champion'=>$attackingChampion, 'defenderChampion'=>$defendingChampion,
                'flinger'=>Flinger::loadout($flingerLevel),
                '_garrisonTemplates'=>[], 'botPlan'=>[],
            ];
            if($origin!==null)$s['fieldOrigin']=(int)$origin['id'];
            if($krallen!==null){$s['krallen']=$krallen;}
            if($target['kind']==='home'){
                if(KrallenHunt::eligible($a)){$s['_krallenQualifies']=true;}
                $guard=KrallenHunt::ready($d,$now);
                if($guard!==null){
                    foreach($s['buildings'] as $cage){
                        if($cage['type']==='championcage'&&$cage['hp']>0&&$cage['_ready']){
                            $s['defenderKrallen']=$guard;[$qx,$qy]=BattleEngine::spawnPosition($s,$cage,1.8);
                            KrallenHunt::deploy($s,'defender',$qx,$qy);break;
                        }
                    }
                }
            }
            if(($site['kind']??null)==='resource'){$s['_madnessValue']=MonsterMadness::value((int)$site['level']);}
            if ($cavernRaid) { $s['_infernoDefenderStores']=['resources'=>$d['resources'],'capacity'=>InfernoRealm::capacity($d)]; }
            if ($inferno) { $s['infernoStores']=['resources'=>$party['resources'],'capacity'=>InfernoRealm::capacity($party)]; }
            $war = $inferno?null:AllianceWars::qualify($pdo,$attackerId,$defenderId,$now);
            if ($war!==null)$s['allianceWar']=$war;
            if($offense||$defense){
                $s['alliancePowers']=['offense'=>$offense,'defense'=>$defense];
                if($site!==null&&!OutpostYards::is($site)&&isset($offense['conquest']))$s['alliancePowers']['captureCost']=AlliancePowers::cost(0,true);
            }
            if ($war !== null || isset($offense['declarewar'])) {
                $s['duration'] = 420;
                $s['flinger']['capacity'] += intdiv($s['flinger']['capacity'],4);
            }
            if(in_array($target['event']??null,['carnage','hellraisers'],true))$s['duration']=min($s['duration'],max(1,$target['endsAt']-$now));
            if ($attacker['is_bot']) {
                self::activityElapsed($s, WorldActivity::clock(), $now);
            }
            $device=$inferno||$origin!==null?null:SiegeWorkshop::loadout($a);
            if ($device!==null) { $s['siege']=SiegeCombat::load($device,$target['kind']); }
            $s['yardExtent']=YardSpace::extent($d);
            StorePowers::combat($s,$party,$site!==null && $defenderId>0?$bases[$defenderId]:$d,$now);
            self::seedHomeDefense($s, $d);
            if($cavernRaid)InfernoCompound::seed($s,$d);
            RaiderLoot::seed($s,$party,$d);
            $stmt = $pdo->prepare("INSERT INTO battles(attacker_id,defender_id,target_kind,target_id,status,state_json,created_at,updated_at) VALUES(?,?,?,?,'active',?,?,?)");
            $stmt->execute([$attackerId, $defenderId ?: null, $target['kind'], $target['id'], self::encode($s), $now, $now]);
            $s['id'] = (int)$pdo->lastInsertId();
            foreach([[$attackerId,'krallen'],[$defenderId,'defenderKrallen']] as [$ownerId,$key]){
                if(isset($s[$key])){$bases[$ownerId]['krallenHunt']['battle']=$s['id'];$bases[$ownerId]['krallenHunt']['lastHeal']=$now;}
            }
            foreach([[$attackerId,'champion'],[$defenderId,'defenderChampion']] as [$ownerId,$key]){
                if($s[$key]!==null){$type=$s[$key]['type'];$bases[$ownerId]['champions'][$type]['battle_id']=$s['id'];$bases[$ownerId]['champions'][$type]['last_heal_at']=$now;}
            }
            if ($cavernRaid) {
                $party['rankedRaid']=['id'=>$s['id'],'request'=>$request['requestId']??null,'peer'=>$defenderId];
                $party['shield_until']=0;++$party['revision'];
                if($attacker['is_bot']){
                    self::planBot($s,$bases,$attackerId,[]);
                    if($s['status']==='finished')self::settle($s,$bases,$site);
                }
            } elseif ($inferno) {
                $party['campaign']['stage']=$stage;
                $party['campaign']['request']=$request['requestId'];
                $party['campaign']['battleId']=$s['id'];
                ++$party['revision'];
            } elseif ($stage !== null) {
                Tribes::rememberStart($a,$s['id'],$stage,$request['requestId']);
            } else {
                $a['shield_until'] = 0;
            }
            Game::saveBase($attackerId, $a);
            $received=$defenderId>0 && YardMessages::receive($bases[$defenderId],'attacks',$attacker['name'].' invaded '.$target['name'].'.',$now,(int)$s['id']);
            if ($defenderId>0 && $site===null) {Game::saveBase($defenderId, $bases[$defenderId]);} elseif ($site!==null) {
                if ($received && !$upgrading) {Game::saveBase($defenderId,$bases[$defenderId]);}
                World::save($site);
                if($upgrading&&$defenderId>0){World::syncBonuses($defenderId,$bases[$defenderId]);Game::saveBase($defenderId,$bases[$defenderId]);}
            }
            self::save($s);
            return self::snapshot($s,$attackerId);
        });
    }

    /** Instantiate defenders from saved yards, including a disposable yard copied for training. */
    public static function seedHomeDefense(array &$state, array $yard): void
    {
        $sequence = 0;
        $catalog = Catalog::monsters();
        foreach ($state['buildings'] as $building) {
            if ($building['type'] !== 'bunker' || $building['hp'] <= 0 || !$building['_ready']) {
                continue;
            }
            $bunkerId = (int)$building['id'];
            foreach ($yard['garrison'][(string)$bunkerId] ?? [] as $type => $quantity) {
                if (!isset($catalog[$type])) { continue; }
                for ($i = 0; $i < min(200, (int)$quantity); ++$i) {
                    $state['_garrisonTemplates'][(string)$bunkerId][] = self::makeUnit(
                        $type, (int)($yard['research'][$type] ?? 1), 'd'.(++$sequence), 'defender',
                        (float)$building['x'], (float)$building['y'], $bunkerId, (int)($yard['lab']['levels'][$type] ?? 0)
                    );
                }
            }
        }
        if ($state['defenderChampion'] === null) { return; }
        foreach ($state['buildings'] as $building) {
            if ($building['type'] !== 'championcage' || $building['hp'] <= 0 || !$building['_ready']) {
                continue;
            }
            [$x, $y] = BattleEngine::spawnPosition($state, $building, 0.0);
            $unit = self::makeChampionUnit($state['defenderChampion'], 'cd', 'defender', $x, $y);
            $state['units'][] = $unit;
            $state['defenderChampion']['deployed'] = true;
            $state['defenderChampion']['available'] = false;
            BattleEngine::event($state, 'deploy', ['x'=>$x, 'y'=>$y, 'sourceId'=>$unit['id'], 'kind'=>$unit['type'], 'team'=>'defender', 'champion'=>true]);
            break;
        }
    }

    /** Freeze the defender's current work, level, health and defenses for one invasion. */
    public static function freezeBuilding(array $building, array $spec, float $defenseBonus, float $networkReduction, int $now): array
    {
        $level = max(1, (int)$building['level']);
        $building['_initial_hp'] = (float)$building['hp'];
        $building['_size'] = (float)$spec['size'];
        $building['_damage'] = (float)($spec['damage'] ?? 0) * pow(1.25, $level - 1) * (1 + (in_array($building['type'],['trap','heavytrap','bunker'],true)?0:$defenseBonus));
        $building['_untargetable'] = (bool)($spec['untargetable'] ?? false);
        $building['_range'] = (float)($spec['range'] ?? 0);
        $building['_interval'] = (float)($spec['attackInterval'] ?? 1.0);
        $building['_cooldown'] = 0.0;
        $building['_targetMode']=$spec['targetMode']??'both';
        $building['_splash']=(float)($spec['splash']??0);
        if (isset($spec['defenseBehavior'])) {
            $stats = $spec['levels'][min($level,$spec['maxLevel'])];
            $building['_behavior'] = $spec['defenseBehavior'];
            $building['_damage'] = $stats['damage'] * (1 + $defenseBonus);
            $building['_range'] = $stats['range'];
            $building['_interval'] = $stats['attackInterval'];
            $building['_splash'] = $stats['splash'];
        } elseif ($building['type'] === 'aerial') {
            $index = min($level, $spec['maxLevel']) - 1;
            $building['_damage'] = $spec['shotDamage'][$index] * (1 + $defenseBonus);
            $building['_range'] = $spec['ranges'][$index];
            $building['_splash'] = $spec['splashes'][$index];
            $building['_volley'] = $spec['volleys'][$index];
            $building['_reload'] = $spec['reload'];
            $building['_shotsFired'] = 0;
        } elseif ($building['type'] === 'heavytrap') {
            $building['_triggerTypes'] = $spec['triggerTypes'];
        }
        $building['_released']=false;
        $building['fortification']=(($spec['canFortify']??false)||($building['outpostArmor']??false))?max(0,min(4,(int)($building['fortification']??0))):0;
        $building['fortificationReduction']=Catalog::fortificationReduction($building['fortification']);
        $building['_fortificationReduction']=$building['fortificationReduction'];
        $building['networkReduction']=$networkReduction;
        $building['_networkReduction']=$building['networkReduction'];
        $building['damageReduction']=1-(1-$building['fortificationReduction'])*(1-$building['networkReduction']);
        $building['fortifying']=(int)($building['fortify_ready_at']??0)>$now;
        $building['_ready'] = (int)($building['ready_at'] ?? 0) <= $now && !$building['fortifying'];
        return $building;
    }

    public static function deploy(int $attackerId, int $battleId, string|array $type, float $x, float $y): array
    {
        return self::change($battleId, $attackerId, true, static function (array &$s, array &$bases) use ($attackerId, $type, $x, $y): void {
            if (in_array($s['target']['kind']??'',['inferno','infernoRival'],true)) {
                self::deployBucket($s,$bases[$attackerId]['infernoRealm'],$type,$x,$y);
                ++$bases[$attackerId]['infernoRealm']['revision'];
            } else {
                self::deploySurface($s,$bases[$attackerId],$type,$x,$y);
            }
        });
    }

    /** Champions are selected before combat and deploy once without occupying a Flinger bucket. */
    public static function deployChampion(int $attackerId,int $battleId,float $x,float $y,bool $krallen=false):array
    {
        return self::change($battleId,$attackerId,true,static function(array &$s,array &$bases)use($attackerId,$x,$y,$krallen):void{
            if($krallen){
                if(($bases[$attackerId]['krallenHunt']['battle']??0)!==$s['id']){throw new RuntimeException('Krallen is not assigned to this invasion.');}
                KrallenHunt::deploy($s,'attacker',$x,$y);return;
            }
            self::deployChampionInto($s,$bases[$attackerId],$x,$y);
        });
    }

    public static function siege(int $attackerId,int $battleId,string $type,float $x,float $y,mixed $request):array
    {
        return self::change($battleId,$attackerId,true,static function(array &$state,array &$bases) use($attackerId,$type,$x,$y,$request):void {
            SiegeCombat::fire($state,$bases[$attackerId],$type,$x,$y,$request);
        });
    }

    /** Each resource family can be launched once; the service owns costs, payloads and impact time. */
    public static function catapult(int $attackerId,int $battleId,string $type,int $level,float $x,float $y):array
    {
        return self::change($battleId,$attackerId,true,static function(array &$s,array &$bases)use($attackerId,$type,$level,$x,$y):void{
            self::catapultInto($s,$bases[$attackerId],$type,$level,$x,$y);
        });
    }

    private static function catapultInto(array &$s,array &$base,string $type,int $level,float $x,float $y):void
    {
        $facility=$s['catapult']??null;$families=Catalog::catapultBombs();$family=$families[$type]??null;
        if((int)($s['formatVersion']??0)<4||!is_array($facility)||($facility['level']??0)<1){throw new RuntimeException('This invasion has no ready Catapult. Build or repair one before the next invasion.');}
        if($family===null||!isset($family['levels'][$level])){throw new RuntimeException('Choose a valid Catapult resource and ammunition tier.');}
        if((int)$facility['level']<(int)$family['requiredLevel']){throw new RuntimeException('Upgrade your Catapult to unlock this ammunition family.');}
        if($facility['used'][$type]??false){throw new RuntimeException('That resource family has already been fired in this invasion.');}
        if(!YardSpace::contains($s,$x,$y,.4)){throw new RuntimeException('Aim the Catapult inside the enemy yard. Buildings may be targeted directly.');}
        if((float)$s['elapsed']+1.0>(float)$s['duration']+0.00001){throw new RuntimeException('There is not enough invasion time left for that projectile to land.');}
        $bomb=$family['levels'][$level];$cost=(int)$bomb['cost'];$resource=$family['resource'];
        if((float)($base['resources'][$resource]??0)<$cost){throw new RuntimeException('Not enough '.$resource.' for that Catapult shot.');}
        $base['resources'][$resource]-=$cost;$s['catapult']['used'][$type]=true;
        $s['catapult']['pending'][]=['type'=>$type,'level'=>$level,'x'=>round($x,4),'y'=>round($y,4),'impactAt'=>(float)$s['elapsed']+1.0,'radius'=>(float)$bomb['radius'],'_bomb'=>$bomb];
        $s['catapult']['shots'][]=['type'=>$type,'level'=>$level,'x'=>round($x,4),'y'=>round($y,4),'at'=>(float)$s['elapsed'],'cost'=>$cost];
        self::syncCatapultResources($s,$base);
        BattleEngine::event($s,'catapult_launch',['x'=>1.0-YardSpace::margin($s),'y'=>31.0+YardSpace::margin($s),'tx'=>round($x,4),'ty'=>round($y,4),'kind'=>$type,'level'=>$level,'radius'=>(float)$bomb['radius'],'impactAt'=>(float)$s['elapsed']+1.0,'team'=>'attacker']);
    }

    private static function syncCatapultResources(array &$s,array $base):void
    {
        if(!is_array($s['catapult']??null)){return;}
        foreach(['twig','pebble','putty'] as $type){$s['catapult']['resources'][$type]=(float)($base['resources'][$type]??0);}
    }

    /** Bots budget at most 20% of each saved resource and never buy a shot until it actually launches. */
    private static function botArtilleryPlan(array $s,array $base):array
    {
        if(!is_array($s['catapult']??null)){return [];}
        $plan=[];$target=null;$best=-INF;
        foreach($s['buildings'] as $b){
            if($b['hp']<=0||($b['_untargetable']??false)||in_array($b['type'],['wall','stonewall','trap','heavytrap','championcage'],true)){continue;}
            $score=($b['_damage']??0)>0?5:($b['type']==='storage'?4:1);
            foreach($s['buildings'] as $near){if($near['hp']>0&&!($near['_untargetable']??false)&&!in_array($near['type'],['wall','stonewall','trap','heavytrap','championcage'],true)&&hypot($near['x']-$b['x'],$near['y']-$b['y'])<=4){$score++;}}
            if($score>$best){$best=$score;$target=$b;}
        }
        foreach(Catalog::catapultBombs() as $type=>$family){
            if((int)$s['catapult']['level']<(int)$family['requiredLevel']||($type!=='putty'&&$target===null)){continue;}
            $chosen=0;$budget=(float)($base['resources'][$family['resource']]??0)*0.2;
            foreach($family['levels'] as $level=>$bomb){if((int)$bomb['cost']<=$budget){$chosen=(int)$level;}}
            if(!$chosen){continue;}
            $plan[]=['type'=>$type,'level'=>$chosen,'at'=>$type==='twig'?1.0:($type==='pebble'?3.0:12.0),'x'=>(float)($target['x']??16),'y'=>(float)($target['y']??16)];
        }
        usort($plan,static fn(array $a,array $b):int=>$a['at']<=>$b['at']);return $plan;
    }

    private static function botArtilleryShot(array &$s,array &$base,array $shot):void
    {
        if($shot['type']==='putty'){
            $living=array_values(array_filter($s['units'],static fn(array $u):bool=>$u['team']==='attacker'&&$u['hp']>0&&!($u['cloaked']??false)&&!($u['blinking']??false)&&!($u['_submerged']??false)));
            if(!$living){return;}
            $margin=YardSpace::margin($s);
            $shot['x']=max(0.4-$margin,min(31.6+$margin,array_sum(array_column($living,'x'))/count($living)));$shot['y']=max(0.4-$margin,min(31.6+$margin,array_sum(array_column($living,'y'))/count($living)));
        }
        try{self::catapultInto($s,$base,$shot['type'],(int)$shot['level'],(float)$shot['x'],(float)$shot['y']);}
        catch(RuntimeException){/* A bounded planned shot is skipped if its saved cost can no longer be paid. */}
    }

    public static function poll(int $viewerId, int $battleId): array
    {
        return self::change($battleId, $viewerId, false, null);
    }

    public static function retreat(int $attackerId, int $battleId): array
    {
        return self::change($battleId, $attackerId, true, static function (array &$s, array &$bases): void {
            if(!empty($s['catapult']['pending'])){throw new RuntimeException('Wait for the Catapult projectile to land before ending this invasion.');}
            BattleEngine::finish($s, 'retreat');
        });
    }

    /** The presence-gated scheduler creates real raids; no browser route exposes this method. */
    public static function botAttack(int $attackerId, int $defenderId,array $reserve=[]): array
    {
        if (!WorldActivity::clock()['online']) { throw new RuntimeException('Bot invasions pause while no players are online.'); }
        if (!self::user($attackerId)['is_bot']) {
            throw new RuntimeException('Automated deployment is reserved for world bots.');
        }
        return self::scheduleBot(self::start($attackerId, $defenderId),$attackerId,$reserve);
    }

    public static function botAttackSite(int $attackerId,int $siteId,array $reserve=[]):array
    {
        if (!WorldActivity::clock()['online']) { throw new RuntimeException('Bot invasions pause while no players are online.'); }
        if(!self::user($attackerId)['is_bot']){throw new RuntimeException('Automated deployment is reserved for world bots.');}
        return self::scheduleBot(self::startSite($attackerId,$siteId),$attackerId,$reserve);
    }

    public static function botAttackFromField(int $id,int $origin,array $target):array
    {
        if(!WorldActivity::clock()['online'] || !self::user($id)['is_bot'])throw new RuntimeException('Bot field invasions require an active world.');
        return self::scheduleBot(self::startField($id,$origin,$target),$id,[]);
    }

    private static function scheduleBot(array $started,int $attackerId,array $reserve):array
    {
        return self::change((int)$started['id'], $attackerId, true, static function (array &$s, array &$bases) use ($attackerId,$reserve): void {
            self::planBot($s,$bases,$attackerId,$reserve);
        });
    }

    private static function planBot(array &$s,array &$bases,int $attackerId,array $reserve):void
    {
        $edge = $attackerId % 4;
        if (!empty($s['champion']['available']) && empty($s['champion']['deployed'])) {
            $championSlots = Flinger::botSlots($s, $edge, 0, true);
            if ($championSlots) {
                self::deployChampionInto($s, $bases[$attackerId], ...$championSlots[0]);
            }
        }
        if(!empty($s['krallen']['available'])){
            $spots=Flinger::botSlots($s,($edge+1)%4,0,true);
            if($spots){KrallenHunt::deploy($s,'attacker',...$spots[0]);}
        }
        $capacity = (int)$s['flinger']['capacity'];
        $slots = Flinger::botSlots($s, $edge, $capacity);
        if (!$slots && $capacity > 800) {
            // A lighter bucket uses the minimum drop radius and may fit between defenses.
            $capacity = 800;
            $slots = Flinger::botSlots($s, $edge, $capacity);
        }
        if (!$slots && empty($s['champion']['deployed']) && empty($s['krallen']['deployed'])) {
            BattleEngine::finish($s, 'retreat');
            return;
        }
        $plan=[];$bucket=[];$weight=0;$count=0;$s['_botReserved']=[];$catalog=Catalog::monsters();
        $roster=($s['target']['kind']??'')==='infernoRival'
            ? ['grokus','spurtz','valgos','malphus','zagnoid','balthazar','sabnox','kingwormzer']
            : ['ichi','octo','crabatron','projectx','slimeattikus','rezghul','dave','wormzer','vorg','zafreeti','teratorn','eyera','pokey','bolt','brain','bandito','fang','fink'];
        foreach($roster as $type){
            if (!$slots || !isset($catalog[$type])) { continue; }
            $space = (int)($s['flinger']['weights'][$type] ?? $catalog[$type]['size']);
            if ($space > $capacity) { continue; }
            
            $held=is_int($reserve[$type]??null)?max(0,min((int)($s['remaining'][$type]??0),$reserve[$type])):0;
            if($held){$s['_botReserved'][$type]=$held;}
            for($i=0;$i<max(0,(int)($s['remaining'][$type]??0)-$held)&&$count<(int)($s['flinger']['unitLimit']??Flinger::INVASION_LIMIT);$i++){
                if($weight+$space>$capacity){[$x,$y]=$slots[count($plan)%count($slots)];$plan[]=['troops'=>$bucket,'x'=>$x,'y'=>$y];$bucket=[];$weight=0;}
                $bucket[$type]=($bucket[$type]??0)+1;$weight+=$space;++$count;
            }
        }
        if($bucket){[$x,$y]=$slots[count($plan)%count($slots)];$plan[]=['troops'=>$bucket,'x'=>$x,'y'=>$y];}
        $s['botPlan']=$plan;$s['_botArtilleryPlan']=self::botArtilleryPlan($s,$bases[$attackerId]);
        self::advanceTo($s,$bases,(float)$s['elapsed']);
    }

    /** Advance the oldest due battles, bounded so a thousand bots do not monopolize a worker. */
    public static function tickActive(int $limit = 10, ?float $budgetSeconds = null, ?float $maxAdvanceSeconds = null, bool $presenceRequired = false): array
    {
        $limit = max(1, min(100, $limit));
        $deadline = $budgetSeconds === null ? INF : microtime(true) + max(0.01, min(20.0, $budgetSeconds));
        $rows = Db::pdo()->query("SELECT id,attacker_id FROM battles WHERE status='active' ORDER BY updated_at,id LIMIT ".$limit)->fetchAll();
        $updated = 0;
        $finished = 0;
        $errors = [];
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) { break; }
            if ($presenceRequired && !WorldActivity::clock()['online']) { break; }
            try {
                $snapshot = self::change((int)$row['id'], (int)$row['attacker_id'], false, null, $maxAdvanceSeconds);
                ++$updated;
                if ($snapshot['status'] === 'finished') {
                    ++$finished;
                }
            } catch (Throwable $e) {
                $errors[] = ['id' => (int)$row['id'], 'error' => $e instanceof PDOException ? 'Database error' : $e->getMessage()];
                error_log('Battle tick '.$row['id'].': '.$e->getMessage());
            }
        }
        return ['updated' => $updated, 'finished' => $finished, 'errors' => $errors];
    }

    public static function tick(int $limit = 10): array
    {
        return self::tickActive($limit);
    }

    private static function change(int $battleId, int $viewerId, bool $attackerOnly, ?callable $action, ?float $maxAdvanceSeconds = null): array
    {
        if ($battleId <= 0 || $viewerId <= 0) {
            throw new RuntimeException('Choose an existing battle.');
        }
        // Read immutable participant IDs before opening the write transaction.
        $q = Db::pdo()->prepare('SELECT attacker_id,defender_id,target_kind,target_id FROM battles WHERE id=?');
        $q->execute([$battleId]);
        $meta = $q->fetch();
        if (!$meta) {throw new RuntimeException('That battle does not exist.');}
        $attackerId = (int)$meta['attacker_id'];
        $defenderId = (int)$meta['defender_id'];
        if ($viewerId !== $attackerId && ($attackerOnly || $viewerId !== $defenderId)) {
            throw new RuntimeException('You cannot control or view this battle.');
        }
        return Db::transaction(static function (PDO $pdo) use ($battleId, $viewerId, $action, $meta, $attackerId, $defenderId, $maxAdvanceSeconds): array {
            $bases = self::lockBases($attackerId, $defenderId);
            $site=$meta['target_id']!==null && ($meta['target_kind']??'home')!=='home'?World::load((int)$meta['target_id'],true):null;
            $q = $pdo->prepare('SELECT state_json FROM battles WHERE id=? FOR UPDATE');
            $q->execute([$battleId]);
            $s = json_decode((string)$q->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
            if ($s['status'] === 'active') {
                if($site!==null && (int)($site['owner_id']??0)!==$defenderId){throw new RuntimeException('The active invasion target has changed owner.');}
                $clock = WorldActivity::clock();
                if (!empty($s['attacker']['is_bot']) && !$clock['online']) {
                    // A read must not deploy a pending bucket or settle a bot
                    // raid at its current timestamp while the world is idle.
                    return self::snapshot($s, $viewerId);
                }
                $targetElapsed = self::activityElapsed($s, $clock);
                if ($maxAdvanceSeconds !== null) {
                    $targetElapsed = min($targetElapsed, (float)$s['elapsed'] + max(0.25, min(60.0, $maxAdvanceSeconds)));
                }
                self::advanceTo($s,$bases,$targetElapsed);
                if ($action !== null && $s['status'] === 'active') {
                    $action($s, $bases);
                }
                if ($s['status'] === 'finished') {
                    self::settle($s, $bases, $site);
                }
                self::syncCatapultResources($s,$bases[$attackerId]);
                Game::saveBase($attackerId, $bases[$attackerId]);
                if($defenderId>0 && ($site===null || ($s['result']['captured']??false))){Game::saveBase($defenderId, $bases[$defenderId]);}
                self::save($s);
            }
            // Finished actions are idempotent: no extra troops or ranking changes.
            return self::snapshot($s,$viewerId);
        });
    }

    /** Bot invasions spend online-world seconds; human invasions retain real-time rules. */
    public static function activityElapsed(array &$state, array $clock, ?int $now = null): float
    {
        $now ??= time();
        $elapsed = max(0.0, (float)($state['elapsed'] ?? 0));
        if (empty($state['attacker']['is_bot'])) {
            return max($elapsed, (float)max(0, $now - (int)$state['created_at']));
        }
        $active = max(0, (int)$clock['activeSeconds']);
        $epoch = (int)$clock['epoch'];
        $anchor = $state['_activity_clock'] ?? null;
        if (!is_array($anchor) || (int)($anchor['epoch'] ?? 0) !== $epoch
            || !isset($anchor['activeSeconds'], $anchor['elapsed'])) {
            // Preserve the actual saved invasion, including its deployed army.
            // Older offline wall time never becomes unplayed combat on adoption.
            $state['_activity_clock'] = ['epoch'=>$epoch, 'activeSeconds'=>$active, 'elapsed'=>$elapsed];
            return $elapsed;
        }
        if (empty($clock['online'])) { return $elapsed; }
        return max($elapsed, (float)$anchor['elapsed'] + max(0, $active - (int)$anchor['activeSeconds']));
    }

    private static function deploySurface(array &$s,array &$home,string|array $selection,float $x,float $y):void
    {
        if(empty($s['fieldOrigin'])){self::deployBucket($s,$home,$selection,$x,$y);return;}
        $origin=World::load($s['fieldOrigin'],true);
        if(!OutpostYards::is($origin)||$origin['owner_id']!==$s['attacker']['id'])throw new RuntimeException('Your outpost is unavailable.');
        self::deployBucket($s,$origin['state'],$selection,$x,$y);World::save($origin);
    }

    /** Validate the complete bucket before consuming any inventory or changing cooldown. */
    private static function deployBucket(array &$s,array &$base,string|array $selection,float $x,float $y):void
    {
        $troops=is_string($selection)?[$selection=>1]:$selection;$catalog=Catalog::monsters();
        if(!$troops || count($troops)>count($catalog)){throw new RuntimeException('Choose at least one monster for the Flinger.');}
        $flinger=$s['flinger']??['capacity'=>200000,'cooldown'=>0,'readyAt'=>0];
        if((float)$s['elapsed']+0.00001<(float)$flinger['readyAt']){throw new RuntimeException('The Flinger is reloading. Wait for its five-second cooldown.');}
        $weight=0;$count=0;
        foreach($troops as $type=>$quantity){
            if(!is_string($type)||!isset($catalog[$type])||($catalog[$type]['origin']??'')==='summon'||!is_int($quantity)||$quantity<1||$quantity>200){throw new RuntimeException('Choose valid monster species and whole quantities for the bucket.');}
            if($quantity>(int)($s['remaining'][$type]??0)||$quantity>(int)($base['army'][$type]??0)){throw new RuntimeException('The bucket contains more monsters than your trained army has available.');}
            $weight+=(int)($flinger['weights'][$type] ?? $catalog[$type]['size'])*$quantity;$count+=$quantity;
        }
        if($weight>(int)$flinger['capacity']){throw new RuntimeException('That bucket exceeds the Flinger’s monster-space capacity.');}
        $already=count(array_filter($s['units'],static fn(array $u):bool=>($u['team']??'attacker')==='attacker'&&!($u['champion']??false)&&empty($u['summoned'])));
        if ($already + $count > (int)($flinger['unitLimit'] ?? Flinger::INVASION_LIMIT)) {
            throw new RuntimeException('This invasion allows up to 200 ordinary monster deployments in total.');
        }
        if (!Flinger::legalAim($s, $x, $y, $weight)) {
            throw new RuntimeException('Aim the whole landing zone on clear ground, away from buildings.');
        }
        $offset=0;
        foreach($troops as $type=>$quantity){
            for($n=0;$n<$quantity;$n++){
                [$px, $py] = Flinger::landing($s, $weight, $x, $y, $offset);
                $unit=self::makeUnit($type,(int)($s['research'][$type]??1),'u'.(++$s['unitSeq']),'attacker',$px,$py,null,(int)($s['formatVersion']??0)>=4?(int)($s['_labLevels'][$type]??0):0);
                $unit['_damage']*=1+(float)($s['_worldBonuses']['attacker']??0);
                --$s['remaining'][$type];--$base['army'][$type];$s['units'][]=$unit;++$offset;
                BattleEngine::event($s,'deploy',['x'=>$unit['x'],'y'=>$unit['y'],'sourceId'=>$unit['id'],'kind'=>$type,'team'=>'attacker']);
            }
        }
        if(isset($s['flinger'])){$s['flinger']['readyAt']=(float)$s['elapsed']+(float)$flinger['cooldown'];}
    }

    /** Snapshot eligibility once under the base lock; later healing never creates a new combat participant. */
    public static function readyChampion(array $base, ?int $now=null):?array
    {
        $owned=Game::readyChampion($base,$now);if($owned===null){return null;}
        return ['type'=>$owned['type'],'level'=>(int)$owned['level'],'bonus_feeds'=>(int)($owned['stats']['bonus_feeds']??0),
            'hp'=>min((float)$owned['max_hp'],(float)$owned['hp']),'max_hp'=>(float)$owned['max_hp'],
            'combatStats'=>$owned['stats'],'deployed'=>false,'available'=>true];
    }

    private static function deployChampionInto(array &$s,array &$base,float $x,float $y):void
    {
        $champion=$s['champion']??null;
        if(!$champion||!($champion['available']??false)||($champion['deployed']??false)){throw new RuntimeException('Your selected champion is not available for another deployment.');}
        $owned=$base['champions'][$champion['type']]??null;
        if(!$owned||(int)($owned['battle_id']??0)!==(int)$s['id']||($owned['frozen']??false)||($base['activeChampion']??null)!==$champion['type']){throw new RuntimeException('This champion is not assigned to this invasion.');}
        if(!BattleEngine::legalDeployment($s,$x,$y)){throw new RuntimeException('Deploy your champion on clear ground outside the buildings’ protected perimeter.');}
        $unit=self::makeChampionUnit($champion,'ca','attacker',$x,$y);
        $unit['_damage']*=1+(float)($s['_worldBonuses']['attacker']??0);
        $s['units'][]=$unit;$s['champion']['deployed']=true;$s['champion']['available']=false;
        BattleEngine::event($s,'deploy',['x'=>$x,'y'=>$y,'sourceId'=>$unit['id'],'kind'=>$unit['type'],'team'=>'attacker','champion'=>true]);
    }

    public static function makeChampionUnit(array $champion,string $id,string $team,float $x,float $y):array
    {
        $type=$champion['type'];$level=(int)$champion['level'];
        // Legacy snapshots have no combatStats and retain the old unbonused
        // values. Never consult the current owned champion during deployment.
        $stats=$champion['combatStats']??Catalog::championStats($type,$level);
        $movement=$stats['movement']??'ground';
        $unit=[
            'id'=>$id,'type'=>$type,'team'=>$team,'champion'=>true,'level'=>$level,'bonus_feeds'=>isset($champion['combatStats'])?(int)($champion['bonus_feeds']??0):0,'movement'=>$movement,'x'=>round($x,4),'y'=>round($y,4),
            'hp'=>(float)$champion['hp'],'max_hp'=>(float)$stats['hp'],'targetId'=>null,'state'=>$team==='defender'?'idle':'walk',
            'auraRange'=>(float)($stats['auraRadius']??0),'auraBuff'=>(float)($stats['auraBuff']??0),
            '_initial_hp'=>(float)$champion['hp'],'_damage'=>(float)$stats['damage'],'_speed'=>(float)$stats['speed'],'_range'=>(float)$stats['range'],
            '_interval'=>(float)$stats['attackInterval'],'_target'=>$stats['target']??'any','_targetMode'=>$stats['targetMode']??($type==='fomor'?'both':'ground'),
            '_ability'=>'','_heal'=>0,'_splash'=>0,'_submerged'=>false,'_bunkerId'=>null,
            '_cooldown'=>0.0,'_repath'=>0.0,'_path'=>[],'_obstacleVersion'=>-1,
        ];
        if($type==='korath'){$unit['power']=$stats['power']??1;$unit['_korathHits']=0;}
        return $unit;
    }

    public static function makeUnit(string $type,int $level,string $id,string $team,float $x,float $y,?int $bunkerId=null,int $labLevel=0):array
    {
        $spec=Catalog::monsters()[$type];$level=max(1,$level);$multiplier=pow(1.12,$level-1);$health=(float)$spec['hp']*$multiplier;$movement=$spec['movement']??'ground';
        $labLevel=max(0,min(3,$labLevel));$lab=$labLevel>0&&isset(Catalog::labAbilities()[$type])?Catalog::labStats($type,$labLevel):null;
        $effect=$lab['effect']??[];
        $unit=[
            'id'=>$id,'type'=>$type,'team'=>$team,'movement'=>$movement,'labLevel'=>$lab?$labLevel:0,'labAbility'=>$lab['kind']??null,'cloaked'=>isset($effect['cloakDelay']),'blinking'=>false,'poisoned'=>false,'boosted'=>false,'_lab'=>$effect,'_cloakMoving'=>isset($effect['cloakDelay']),'x'=>round($x,4),'y'=>round($y,4),
            'hp'=>$health,'max_hp'=>$health,'level'=>$level,'targetId'=>null,'state'=>$movement==='burrow'?'burrow':'walk',
            '_damage'=>max(0,(float)$spec['damage'])*$multiplier,'_speed'=>(float)$spec['speed'],'_range'=>(float)($effect['rocketRange']??$spec['range']),
            '_interval'=>(float)($spec['attackInterval']??1.0),'_target'=>(string)$spec['target'],
            '_ability'=>$spec['ability']??'','_heal'=>(float)($spec['heal']??0)*$multiplier,'_splash'=>(float)($spec['splash']??0),
            '_targetMode'=>(isset($effect['rocketRange'])||isset($effect['airburstBonus']))?'both':($spec['targetMode']??($movement==='air'?'both':'ground')),'_submerged'=>$movement==='burrow','_bunkerId'=>$bunkerId,
            '_cooldown'=>0.0,'_repath'=>0.0,'_path'=>[],'_obstacleVersion'=>-1,
        ];
        return isset($spec['originalId'])?MonsterExpansion::freeze($unit,$spec):$unit;
    }

    /** Persisted bot buckets are applied at their scheduled battle time, even after an offline catch-up. */
    private static function advanceTo(array &$s,array &$bases,float $targetElapsed):void
    {
        $targetElapsed=min((float)$s['duration'],max((float)$s['elapsed'],$targetElapsed));
        while($s['status']==='active'){
            $bucketAt=!empty($s['botPlan'])?(float)($s['flinger']['readyAt']??$s['elapsed']):INF;
            $shotAt=!empty($s['_botArtilleryPlan'])?(float)$s['_botArtilleryPlan'][0]['at']:INF;
            $nextAt=min($bucketAt,$shotAt);
            if($nextAt>$targetElapsed+0.00001){break;}
            BattleEngine::advance($s,max((float)$s['elapsed'],$nextAt));
            if($s['status']!=='active'){break;}
            if($bucketAt<=$shotAt){
                $bucket=array_shift($s['botPlan']); $keeper=(int)$s['attacker']['id'];
                if(($s['target']['kind']??'')==='infernoRival'){
                    self::deployBucket($s,$bases[$keeper]['infernoRealm'],$bucket['troops'],(float)$bucket['x'],(float)$bucket['y']);
                    ++$bases[$keeper]['infernoRealm']['revision'];
                }else self::deploySurface($s,$bases[$keeper],$bucket['troops'],(float)$bucket['x'],(float)$bucket['y']);
                if (isset($s['siege'])) { SiegeCombat::botDeploy($s,$bases[$keeper]); }
            }
            else{$shot=array_shift($s['_botArtilleryPlan']);self::botArtilleryShot($s,$bases[(int)$s['attacker']['id']],$shot);}
        }
        BattleEngine::advance($s,$targetElapsed);
    }

    private static function settle(array &$s, array &$bases, ?array &$site=null): void
    {
        if ($s['settled']) {
            return;
        }
        $pdo = Db::pdo();
        $now = time();
        $attackerId = (int)$s['attacker']['id'];
        $defenderId = (int)$s['defender']['id'];
        $a =& $bases[$attackerId];
        $isTribe = ($s['target']['kind'] ?? 'home') === 'tribe';
        $isInferno = ($s['target']['kind'] ?? 'home') === 'inferno';
        $cavernRaid = ($s['target']['kind']??'')==='infernoRival';
        $npcCamp = $isTribe || $isInferno;
        if ($site!==null) { $d =& $site['state']; }
        elseif ($cavernRaid) { $d =& $bases[$defenderId]['infernoRealm']; }
        elseif (!$npcCamp) { $d =& $bases[$defenderId]; }
        // Home loads have already excluded paused time for bots; humans retain wall time.
        Game::accrue($a, $now);
        if($cavernRaid){InfernoRealm::accrue($d,$now);InfernoRealm::accrue($a['infernoRealm'],$now);}
        elseif($site===null && !$npcCamp){Game::accrue($d, $now);}
        Quests::battleKills($a,$s['units'],'defender');
        if($site===null && !$npcCamp && !$cavernRaid){Quests::battleKills($d,$s['units'],'attacker');}
        KrallenHunt::release($a,$s,'krallen',$now);
        if(isset($s['defenderKrallen'])){KrallenHunt::release($d,$s,'defenderKrallen',$now);}
        $championResults=[];
        foreach([[$attackerId,'champion','attacker'],[$defenderId,'defenderChampion','defender']] as [$ownerId,$key,$team]){
            $champion=$s[$key]??null;if(!$champion||($site!==null&&$team==='defender')){continue;}$type=$champion['type'];
            if(!isset($bases[$ownerId]['champions'][$type])){continue;}
            $owned=&$bases[$ownerId]['champions'][$type];
            if((int)($owned['battle_id']??0)!==(int)$s['id']){unset($owned);continue;}
            $hp=(float)$champion['hp'];
            foreach($s['units'] as $unit){if(($unit['champion']??false)&&$unit['team']===$team&&$unit['type']===$type){$hp=(float)$unit['hp'];break;}}
            if((int)($s['formatVersion']??0)>=6){
                // Reservation freezes champion clocks even if nobody polls the battle
                // for days. Use the actual reservation timestamp, not battle elapsed.
                $lockedSeconds=max(0,$now-(int)($owned['last_heal_at']??$now));
                $owned['next_feed_at']=(int)($owned['next_feed_at']??0)+$lockedSeconds;
                $owned['last_hunger_at']=(int)($owned['last_hunger_at']??0)+$lockedSeconds;
            }
            $owned['hp']=min((float)$champion['max_hp'],max(0.0,$hp));$owned['battle_id']=0;$owned['last_heal_at']=$now;
            $s[$key]['hp']=$owned['hp'];$s[$key]['available']=false;
            $championResults[$team]=['type'=>$type,'level'=>(int)$champion['level'],'bonus_feeds'=>(int)($champion['bonus_feeds']??0),'deployed'=>(bool)$champion['deployed'],'hp'=>$owned['hp'],'max_hp'=>(float)$champion['max_hp'],'retreated'=>$owned['hp']<=0];
            unset($owned);
        }
        if($championResults){$s['result']['champions']=$championResults;}
        if (!$npcCamp) { self::settleDefenses($s,$d); }
        if(!empty($s['catapult']['shots'])){$s['result']['artillery']=$s['catapult']['shots'];}
        if (!empty($s['siege']['used'])) {
            $s['result']['siege']=['type'=>$s['siege']['type'],'level'=>$s['siege']['level'],'firedAt'=>$s['siege']['firedAt']];
        }
        if($cavernRaid){
            InfernoCompound::settle($s,$d);
            InfernoRivals::settle($a['infernoRealm'],$d,$s['result'],$s['target'],$now,$s['attacker']['is_bot']&&!$s['defender']['is_bot']);
            RaiderLoot::transfer($s,$a['infernoRealm'],$d,InfernoRealm::capacity($a['infernoRealm']));
            $s['_infernoDefenderStores']=['resources'=>$d['resources'],'capacity'=>InfernoRealm::capacity($d)];
            $s['infernoStores']=['resources'=>$a['infernoRealm']['resources'],'capacity'=>InfernoRealm::capacity($a['infernoRealm'])];
        } elseif ($isInferno) {
            InfernoRealm::accrue($a['infernoRealm'],$now);
            RaiderLoot::campaign($s,$a['infernoRealm'],'campaign',InfernoRealm::capacity($a['infernoRealm']));
            InfernoRealm::finish($a['infernoRealm'],$s['result'],$s['target']);
            $s['infernoStores']=['resources'=>$a['infernoRealm']['resources'],'capacity'=>InfernoRealm::capacity($a['infernoRealm'])];
        } elseif ($isTribe) {
            foreach ($s['buildings'] as $building) {
                if ($building['type']==='townhall' && $building['hp']<=0 && $building['_initial_hp']>0) {
                    $s['result']['hallDestroyed'] = true;
                    break;
                }
            }
            if (($s['target']['event']??null)==='brukkarg') {
                Brukkarg::settle($a,$s['result'],$s['target'],$s['buildings'],(int)$s['created_at'],$now);
            } elseif(($s['target']['event']??null)==='hellraisers'){
                HellRaisers::settle($a,$s,min($now,(int)$s['created_at']+(int)ceil($s['elapsed'])));
            } elseif(($s['target']['event']??null)==='carnage'){
                CreatureCarnage::settle($a,$s['result'],$s['target'],$s['buildings'],(int)$s['created_at'],min($now,(int)$s['created_at']+(int)ceil($s['elapsed'])));
            } else {RaiderLoot::campaign($s,$a,'tribeCampaign',Game::capacity($a));Tribes::finish($a,$s['result'],$s['target'],$now);}
        } elseif($site===null){
            $fraction = min(0.10, (float)$s['result']['destruction'] / 1000);
            // Use normal storage capacity; surplus loot stays in the defender's storage.
            $public = Game::publicBase($attackerId, $a);
            $capacity = $public['capacity'] ?? 30000;
            foreach (['twig','pebble','putty','goo'] as $resource) {
                $cap = is_array($capacity) ? (float)($capacity[$resource] ?? 30000) : (float)$capacity;
                $amount = min((int)floor((float)$d['resources'][$resource] * $fraction * KrallenHunt::multiplier($s,$resource)), max(0, (int)floor($cap - (float)$a['resources'][$resource])));
                $d['resources'][$resource] -= $amount;
                $a['resources'][$resource] += $amount;
                $s['result']['loot'][$resource] = $amount;
            }
            RaiderLoot::transfer($s,$a,$d,Game::capacity($a));
            SiegeCombat::settleLoot($s,$a,$d);
            Seasonal::credit($a,(int)array_sum($s['result']['loot']));
            if(!empty($s['_krallenQualifies'])){
                $receipt=KrallenHunt::credit($a,$s['result']['loot'],$now,Game::speed());
                if($receipt!==null){$s['result']['krallenHunt']=$receipt;}
            }
            $d['shield_until'] = $now + ($s['attacker']['is_bot'] && !$s['defender']['is_bot'] ? 1800 : 120);
        }else{
            if(OutpostYards::is($site)){RaiderLoot::transfer($s,$a,$d,Game::capacity($a));}
            // Territory rewards ownership. An outpost defense never raids its owner's home bank.
            $s['result']['captureProgress']=BattleEngine::captureProgress($s);
            $s['result']['captured']=$s['result']['captureProgress']>=90.0;
            $s['result']['target']=$s['target'];
            $s['result']['previousOwner']=$defenderId>0?$s['defender']:null;
            if($s['result']['captured'] && OutpostYards::is($site)){
                OutpostYards::defeated($site,$s,$now);
            }elseif($s['result']['captured']){
                // Accrue the losing owner's old cached rate before the ownership switch.
                if($defenderId>0){Game::accrue($bases[$defenderId],$now);}
                if(!empty($s['_madnessValue'])&&$site['kind']==='resource'&&(int)$site['owner_id']!==$attackerId){
                    $score=MonsterMadness::capture($a,(int)$site['id'],(int)$s['_madnessValue'],$now);
                    if($score!==null){$s['result']['monsterMadness']=$score;}
                }
                World::capture($site,$attackerId,$bases,$now);
            }else{
                $site['state']['shield_until']=$now+120;
                World::save($site);
            }
            $s['result']['newOwner']=$s['result']['captured']?$s['attacker']:$s['result']['previousOwner'];
        }
        $attackerWon = (bool)$s['result']['attackerWon'];
        if (!$npcCamp) {
            $winner = $attackerWon ? $attackerId : $defenderId;
            $loser = $attackerWon ? $defenderId : $attackerId;
            $q = $pdo->prepare('UPDATE users SET rank_points=rank_points+25,wins=wins+1 WHERE id=?');
            if($winner>0){$q->execute([$winner]);}
            $q = $pdo->prepare('UPDATE users SET rank_points=rank_points-15,losses=losses+1 WHERE id=?');
            if($loser>0){$q->execute([$loser]);}
            $war = AllianceWars::credit($pdo,$s,$now);
            if ($war !== null) {$s['result']['allianceWar'] = $war;}
        }
        if(isset($s['alliancePowers']))$s['result']['alliancePowers']=$s['alliancePowers'];
        $body = $s['result'] + ['attacker' => $s['attacker'], 'defender' => $s['defender'], 'duration' => $s['elapsed'], 'deployed' => count(array_filter($s['units'],static fn(array $u):bool=>($u['team']??'attacker')==='attacker'&&empty($u['summoned'])))];
        $q = $pdo->prepare('INSERT INTO reports(user_id,battle_id,title,body_json,created_at) VALUES(?,?,?,?,?)');
        $location=$cavernRaid||$site!==null?$s['target']['name']:$s['defender']['name'];
        $q->execute([$attackerId, $s['id'], ($attackerWon ? 'Victory at ' : 'Defeat at ').$location, self::encode($body + ['role' => 'attacker']), $now]);
        if($defenderId>0){$q->execute([$defenderId, $s['id'], ($cavernRaid?'Cavern: ':($site!==null?$s['target']['name'].': ':'')).($attackerWon ? 'Yard invaded by ' : 'Defense held against ').$s['attacker']['name'], self::encode($body + ['role' => 'defender']), $now]);}
        $s['settled'] = true;
        $s['settled_at'] = $now;
    }

    private static function settleDefenses(array $s, array &$d): void
    {
        $health = [];
        foreach ($s['buildings'] as $b) {
            // A construction that had no HP at invasion start was not a combat target.
            // Do not erase it if its queued construction finished during the battle.
            if ((float)($b['_initial_hp'] ?? 0) > 0) {
                $health[(int)$b['id']] = $b;
            }
        }
        foreach ($d['buildings'] as &$b) {
            if (array_key_exists((int)$b['id'], $health)) {
                $b['hp'] = BattleEngine::settledHealth($health[(int)$b['id']],$b);
            }
        }
        unset($b);
        self::settleGarrison($s,$d);
    }

    public static function settleGarrison(array $s,array &$d): void
    {
        // Each dead released defender is removed once; surviving garrisons retain their species counts.
        foreach($s['units'] as $unit){
            if(($unit['team']??'attacker')!=='defender'||$unit['hp']>0||empty($unit['_bunkerId'])){continue;}
            $bunker=(string)$unit['_bunkerId'];$type=$unit['type'];$d['garrison'][$bunker][$type]=max(0,(int)($d['garrison'][$bunker][$type]??0)-1);
        }
        foreach($s['buildings'] as $building){
            if($building['type']==='bunker'&&$building['hp']<=0&&(float)($building['_initial_hp']??0)>0&&!($building['_released']??false)){ $d['garrison'][(string)$building['id']]=[]; }
        }
    }

    public static function snapshot(array $s,int $viewerId): array
    {
        $format=(int)($s['formatVersion']??0);
        $clean = static function (array $row) use($format): array {
            if($format<7){unset($row['networkReduction'],$row['damageReduction']);}
            return array_filter($row, static fn($key): bool => !str_starts_with((string)$key, '_') && $key!=='fortify_paid', ARRAY_FILTER_USE_KEY);
        };
        $cavernDefender=($s['target']['kind']??'')==='infernoRival'&&$viewerId===(int)$s['defender']['id'];
        $stores=$cavernDefender?($s['_infernoDefenderStores']??null):($viewerId===(int)$s['attacker']['id']?($s['infernoStores']??null):null);
        return [
            'id' => (int)$s['id'], 'status' => $s['status'], 'elapsed' => $s['elapsed'], 'duration' => $s['duration'],
            'buildings' => array_values(array_map($clean,array_filter($s['buildings'],static fn(array $b):bool=>$viewerId===(int)$s['defender']['id']||Catalog::visibleToRivals($b)))), 'units' => array_map($clean, $s['units']),
            'signs' => $s['signs'] ?? [], 'decorations' => $s['decorations'] ?? [], 'events' => $s['events'], 'result' => $s['result'], 'remaining' => $cavernDefender?[]:$s['remaining'],
            'champion'=>self::championSnapshot($s,'champion','attacker'), 'defenderChampion'=>self::championSnapshot($s,'defenderChampion','defender'),
            'attacker' => $s['attacker'], 'defender' => $s['defender'],
            'deploymentPadding' => BattleEngine::DEPLOYMENT_PADDING,'yardExtent'=>YardSpace::extent($s),
            'catapult'=>self::catapultSnapshot($s,$viewerId),
            'flinger'=>$s['flinger']??['capacity'=>200000,'cooldown'=>0,'readyAt'=>0,'legacy'=>true],
            'destruction' => ($s['target']['event']??null)==='hellraisers'?round(BattleEngine::captureProgress($s),2):BattleEngine::destruction($s), 'serverTime' => time(),
            'target'=>$s['target']??null,
            'networkDefense'=>$format>=7?($s['networkDefense']??['count'=>0,'reduction'=>0.0,'siteIds'=>[]]):null,
            'captureProgress'=>in_array($s['target']['kind']??'home',['resource','stronghold','fortification'],true)?BattleEngine::captureProgress($s):null,
        ] + (isset($s['alliancePowers'])?['alliancePowers'=>$s['alliancePowers']]:[]) + (isset($s['krallen'])?['krallen'=>KrallenHunt::snapshot($s,'krallen')]:[]) + (isset($s['defenderKrallen'])?['defenderKrallen'=>KrallenHunt::snapshot($s,'defenderKrallen')]:[]) + ($stores===null?[]:['infernoStores'=>$stores]) + (isset($s['siege']) && ($viewerId===(int)$s['attacker']['id'] || $s['siege']['used'])?['siege'=>SiegeCombat::view($s['siege'])]:[]);
    }

    private static function catapultSnapshot(array $s,int $viewerId):?array
    {
        if((int)($s['formatVersion']??0)<4||!is_array($s['catapult']??null)){return null;}
        $row=$s['catapult'];$row['pending']=array_map(static fn(array $shot):array=>array_filter($shot,static fn($key):bool=>!str_starts_with((string)$key,'_'),ARRAY_FILTER_USE_KEY),$row['pending']);
        if($viewerId!==(int)$s['attacker']['id']){unset($row['resources']);}
        return $row;
    }

    private static function championSnapshot(array $s,string $key,string $team):?array
    {
        $champion=$s[$key]??null;if(!$champion){return null;}
        foreach($s['units'] as $unit){if(($unit['champion']??false)&&$unit['team']===$team&&$unit['type']===$champion['type']){$champion['hp']=max(0.0,(float)$unit['hp']);break;}}
        if($s['status']!=='active'){$champion['available']=false;}
        return $champion;
    }

    private static function lockBases(int $a, int $d): array
    {
        $ids = array_values(array_unique(array_filter([$a,$d],static fn(int $id):bool=>$id>0)));
        sort($ids, SORT_NUMERIC);
        // Acquire every participant mutex before Game::loadBase performs any
        // consistent identity/activity reads. Under REPEATABLE READ, projecting
        // the first yard while waiting for the second could freeze a snapshot
        // that misses a newly committed invasion or territory ownership change.
        $lock = Db::pdo()->prepare('SELECT user_id FROM bases WHERE user_id=? FOR UPDATE');
        foreach ($ids as $id) {
            $lock->execute([$id]);
            if ($lock->fetchColumn() === false) { throw new RuntimeException('That yard could not be found.'); }
        }
        $bases = [];
        foreach ($ids as $id) {
            $bases[$id] = Game::loadBase($id, true);
        }
        return $bases;
    }

    private static function user(int $id): array
    {
        $q = Db::pdo()->prepare('SELECT id,name,is_bot,rank_points FROM users WHERE id=?');
        $q->execute([$id]);
        $user = $q->fetch();
        if (!$user) {
            throw new RuntimeException('That player or bot no longer exists.');
        }
        return ['id' => (int)$user['id'], 'name' => $user['name'], 'is_bot' => (bool)$user['is_bot'], 'rank_points' => (int)$user['rank_points']];
    }

    private static function save(array $s): void
    {
        $q = Db::pdo()->prepare('UPDATE battles SET status=?,state_json=?,updated_at=? WHERE id=?');
        $q->execute([$s['status'], self::encode($s), time(), $s['id']]);
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
