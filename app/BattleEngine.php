<?php
declare(strict_types=1);
require_once __DIR__.'/Catalog.php';
require_once __DIR__.'/SiegeCombat.php';
require_once __DIR__.'/InfernoCompound.php';
require_once __DIR__.'/RaiderLoot.php';

/** Fixed-step, deterministic world-space combat. No client-authored damage or results. */
final class BattleEngine
{
    public const STEP = 0.25;
    public const DURATION = 300;
    public const DEPLOYMENT_PADDING = 1.25;
    private const CELL = 0.5;

    public static function legalDeployment(array $state, float $x, float $y): bool
    {
        if (!YardSpace::contains($state,$x,$y,.4)) {
            return false;
        }
        foreach ($state['buildings'] as $b) {
            if ((Catalog::isTrap($b['type']) || ($b['_untargetable'] ?? false))) { continue; }
            // Destroyed buildings retain the invasion's placement exclusion zone.
            $half = (float)($b['_size'] ?? 2) / 2 + self::DEPLOYMENT_PADDING;
            if (abs($x - (float)$b['x']) < $half && abs($y - (float)$b['y']) < $half) {
                return false;
            }
        }
        return true;
    }

    /** Advance only to server-selected elapsed time; callers persist the result under a row lock. */
    public static function advance(array &$s, float $targetElapsed): void
    {
        if (($s['status'] ?? 'active') !== 'active') {
            return;
        }
        foreach ($s['units'] as &$unit) {
            $unit += ['team'=>'attacker','movement'=>'ground','_ability'=>'','_heal'=>0,'_splash'=>0];
        } unset($unit);
        $targetElapsed = min((float)$s['duration'], max((float)$s['elapsed'], $targetElapsed));
        $steps = min(1300, (int)floor(($targetElapsed - (float)$s['elapsed'] + 0.00001) / self::STEP));
        for ($step = 0; $step < $steps; ++$step) {
            $s['elapsed'] = round((float)$s['elapsed'] + self::STEP, 3);
            self::step($s);
            if ($s['status'] !== 'active') {
                break;
            }
        }
        // Keep a bounded rolling effect stream. IDs are monotonic for client deduplication.
        if (count($s['events']) > 260) {
            $s['events'] = array_slice($s['events'], -260);
        }
    }

    public static function event(array &$s, string $type, array $detail): void
    {
        $s['events'][] = ['id' => ++$s['eventSeq'], 't' => $s['elapsed'], 'type' => $type] + $detail;
    }

    public static function destruction(array $s): float
    {
        $total = 0;
        $destroyed = 0;
        foreach ($s['buildings'] as $b) {
            if (in_array($b['type'], ['wall','stonewall','trap','heavytrap'], true) || ($b['_untargetable']??false) || (float)($b['_initial_hp'] ?? $b['max_hp'] ?? 1) <= 0) {
                continue;
            }
            ++$total;
            if ((float)$b['hp'] <= 0) {
                ++$destroyed;
            }
        }
        return $total ? round(100 * $destroyed / $total, 1) : 100.0;
    }

    /** Strategic capture uses maximum health, including damage persisted from an earlier invasion. */
    public static function captureProgress(array $s): float
    {
        $maximum=0.0;$remaining=0.0;
        foreach($s['buildings'] as $b){
            if(in_array($b['type'],['wall','stonewall','trap','heavytrap'],true)||($b['_untargetable']??false)){continue;}
            $hp=max(0.0,(float)($b['max_hp']??0));
            $maximum+=$hp;$remaining+=min($hp,max(0.0,(float)$b['hp']));
        }
        // No eligible defense cannot be captured; start also rejects an empty/rebuilding site.
        return $maximum>0?max(0.0,min(100.0,100*($maximum-$remaining)/$maximum)):0.0;
    }

    /** Carry only damage actually dealt into an upgrade completed during the invasion. */
    public static function settledHealth(array $battleBuilding,array $currentBuilding):float
    {
        $maximum=(float)$currentBuilding['max_hp'];
        $scale=(float)($battleBuilding['_armamentScale']??1);
        $initial=(float)($battleBuilding['_initial_hp']??$battleBuilding['max_hp'])/$scale;
        if($initial<=0){return min($maximum,max(0.0,(float)$currentBuilding['hp']));}
        $remaining=max(0.0,(float)$battleBuilding['hp']/$scale);
        if($remaining<=0){return 0.0;}
        $upgraded=(int)$currentBuilding['level']>(int)$battleBuilding['level']||$maximum>(float)$battleBuilding['max_hp']/$scale;
        if($upgraded){return max(0.0,$maximum-max(0.0,$initial-$remaining));}
        return min($maximum,$remaining);
    }

    public static function finish(array &$s, string $reason): void
    {
        if ($s['status'] !== 'active') {
            return;
        }
        if (!empty($s['siege']['active'])) { SiegeCombat::end($s); }
        $townhallDown = false;
        foreach ($s['buildings'] as $b) {
            if ($b['type'] === 'townhall' && (float)($b['_initial_hp'] ?? $b['max_hp'] ?? 1) > 0 && (float)$b['hp'] <= 0) {
                $townhallDown = true;
            }
        }
        $destruction = self::destruction($s);
        // Ending an invasion preserves any victory already earned through damage.
        $won = $townhallDown || (!in_array($s['target']['event']??null,['brukkarg','carnage'],true) && $destruction >= 50);
        $s['status'] = 'finished';
        $s['result'] = [
            'winnerId' => $won ? $s['attacker']['id'] : $s['defender']['id'],
            'attackerWon' => $won,
            'reason' => $reason,
            'destruction' => $destruction,
            'loot' => ['twig' => 0, 'pebble' => 0, 'putty' => 0, 'goo' => 0],
            'rankDelta' => ['attacker' => $won ? 25 : -15, 'defender' => $won ? -15 : 25],
        ];
        if((int)($s['formatVersion']??0)>=7){$s['result']['networkDefense']=$s['networkDefense']??['count'=>0,'reduction'=>0.0,'siteIds'=>[]];}
        if(in_array($s['target']['kind']??'home',['resource','stronghold','fortification'],true)){
            $s['result']['captureProgress']=self::captureProgress($s);
            $s['result']['captured']=$s['result']['captureProgress']>=90.0;
            $s['result']['target']=$s['target'];
            if((int)$s['defender']['id']===0){$s['result']['rankDelta']['defender']=0;$s['result']['winnerId']=$won?$s['attacker']['id']:null;}
        }
    }

    private static function step(array &$s): void
    {
        if (!empty($s['_hasHatchlings'])) { ExpandedDefenses::expireHatchlings($s); }
        if (!empty($s['_defenseShells'])) { ExpandedDefenses::landShots($s); }
        self::resolveCatapult($s);
        if (!empty($s['siege']['active'])) { SiegeCombat::tick($s); }
        self::releaseGarrisons($s);
        if(!empty($s['_compounds']))InfernoCompound::tick($s);
        if(!empty($s['_storePowers']))StorePowers::battleTick($s);
        self::refreshStatuses($s);
        self::refreshAuras($s);
        self::triggerTraps($s);
        foreach (array_keys($s['units']) as $index) {
            if ($s['units'][$index]['hp'] <= 0) { $s['units'][$index]['state'] = 'dead'; continue; }
            $u =& $s['units'][$index];
            if($u['blinking']??false){$u['state']='blink';unset($u);continue;}
            $auraSpeed=(float)($u['_auraSpeed']??1)*(float)($u['_lab']['attackSpeed']??1);
            // Carry boosted fractional timing across attacks; unchanged units retain legacy step timing.
            $u['_cooldown'] = $auraSpeed>1 ? ((float)$u['_cooldown']>0 ? (float)$u['_cooldown']-self::STEP*$auraSpeed : 0.0) : max(0.0,(float)$u['_cooldown']-self::STEP);
            if ($u['team']==='defender' && !empty($s['siege']['active']) && ($decoy=SiegeCombat::decoyNear($s,$u))!==null) {
                $u['targetId']=$decoy['id'];
                if (hypot($u['x']-$decoy['x'],$u['y']-$decoy['y'])>0.45) { self::moveToward($s,$u,$decoy,false); }
                else { $u['state']='idle'; }
                unset($u); continue;
            }
            if(isset($u['_necromancy'])){MonsterExpansion::resurrect($s,$index);}
            if (($u['_ability'] ?? '') === 'heal') { self::heal($s, $index); unset($u); continue; }
            $enemy = self::enemyUnit($s, $u);
            if ($enemy !== null) {
                $victim = $s['units'][$enemy];
                $u['targetId'] = $victim['id'];
                $distance = hypot($u['x'] - $victim['x'], $u['y'] - $victim['y']);
                $reach=(float)$u['_range'];
                if(isset($u['_korathHits'])&&$victim['movement']==='air'&&$u['_targetMode']==='both'){$reach*=2;}
                if ($distance <= $reach + 0.3) {
                    self::emerge($s, $u);
                    if($u['hp']<=0){unset($u);continue;}
                    $u['state'] = 'attack';self::cloak($s,$u,false);
                    $strikes=0;
                    while ($u['_cooldown'] <= 0.0001 && $u['hp']>0 && $s['units'][$enemy]['hp']>0 && ++$strikes<=32) {
                        $u['_cooldown'] += (float)$u['_interval'];
                        if (($u['_ability'] ?? '') === 'explode') { self::explode($s, $index); }
                        else {
                            self::strike($s,$index,$enemy,false);
                        }
                    }
                } else {
                    if (!self::moveToward($s, $u, ['id'=>$victim['id'],'x'=>$victim['x'],'y'=>$victim['y'],'_size'=>0], false)) { $u['_ignoreEnemyUntil'] = $s['elapsed'] + 2; }
                }
                unset($u); continue;
            }
            if ($u['team'] === 'defender') { $u['state']='idle';$u['targetId']=null;self::cloak($s,$u,false);unset($u);continue; }
            $target = is_int($u['targetId'] ?? null) ? self::findBuildingIndex($s, (int)$u['targetId']) : null;
            if ($target === null || $s['buildings'][$target]['hp'] <= 0 || (Catalog::isTrap($s['buildings'][$target]['type']) || ($s['buildings'][$target]['_untargetable']??false))) {
                $target = self::chooseTarget($s, $u);$u['_path']=[];$u['_repath']=0;
            }
            if ($target === null) { $u['state']='idle';self::cloak($s,$u,false);unset($u);continue; }
            $b = $s['buildings'][$target];$u['targetId']=(int)$b['id'];
            if (self::distanceToBuilding((float)$u['x'], (float)$u['y'], $b) <= (float)$u['_range'] + ($u['_foundationPadding']??0.15)) {
                self::emerge($s, $u);if($u['hp']<=0){unset($u);continue;}$u['state']='attack';self::cloak($s,$u,false);
                $strikes=0;
                while ($u['_cooldown'] <= 0.0001 && $u['hp']>0 && $s['buildings'][$target]['hp']>0 && ++$strikes<=32) {
                    $u['_cooldown']+=(float)$u['_interval'];
                    if (($u['_ability'] ?? '') === 'explode') { self::explode($s, $index); }
                    else {
                        self::strike($s,$index,$target,true);
                    }
                }
            } else { self::moveToward($s,$u,$b,true); }
            unset($u);
        }
        self::fireTowers($s);
        self::refreshAuras($s);
        $deployable=0;foreach($s['remaining'] as $type=>$quantity){$deployable+=max(0,(int)$quantity-(int)($s['_botReserved'][$type]??0));}
        $targets = array_filter($s['buildings'], static fn(array $b):bool => !in_array($b['type'],['wall','stonewall','trap','heavytrap'],true) && !($b['_untargetable']??false) && $b['hp']>0);
        $attackers = array_filter($s['units'], static fn(array $u):bool => $u['team']==='attacker');
        $alive = array_filter($attackers, static fn(array $u):bool => $u['hp']>0);
        if (!$targets && empty($s['catapult']['pending'])) { self::finish($s,'yard_destroyed'); }
        elseif ($s['elapsed'] >= $s['duration']) { self::finish($s,'time_limit'); }
        elseif (!$alive && count($attackers)>0 && $deployable===0 && !($s['champion']['available']??false) && empty($s['krallen']['available'])
            && empty($s['catapult']['pending']) && !(($s['siege']['type']??null)==='decoy' && !empty($s['siege']['active']))) {
            self::finish($s,'army_defeated');
        }
    }

    /** Fomor empowers nearby allies; the effect is rebuilt, never compounded or persisted as base stats. */
    private static function refreshAuras(array &$s):void
    {
        $sources=[];$s['_auraSources']=[];
        foreach($s['units'] as $index=>$u){if($u['hp']>0&&($u['auraRange']??0)>0){$sources[]=$u;$s['_auraSources'][]=$index;}}
        foreach($s['units'] as &$u){
            $u['_auraSpeed']=1.0;$u['buffed']=false;$u['buffSource']=null;
            if($u['hp']<=0){continue;}
            foreach($sources as $source){
                if($u['id']===$source['id']||$u['team']!==$source['team']||hypot($source['x']-$u['x'],$source['y']-$u['y'])>(float)$source['auraRange']){continue;}
                $speed=1+2*max(0.0,min(0.9,(float)$source['auraBuff']));
                if($speed>$u['_auraSpeed']){$u['_auraSpeed']=$speed;$u['buffed']=true;$u['buffSource']=$source['id'];}
            }
            if(($u['_puttyUntil']??0)>$s['elapsed']){$u['_auraSpeed']*=max(1.0,(float)($u['_puttySpeed']??1));}
        }unset($u);
    }

    /** Status data is accumulated numerically rather than keeping an unbounded list of poison stacks. */
    private static function refreshStatuses(array &$s):void
    {
        foreach(array_keys($s['units']) as $i){
            $u=&$s['units'][$i];if($u['hp']<=0){unset($u);continue;}
            $u['boosted']=($u['_puttyUntil']??0)>$s['elapsed'];
            if($u['blinking']??false){
                $span=max(0.01,(float)$u['_blinkUntil']-(float)$u['_blinkStart']);$fraction=min(1.0,max(0.0,($s['elapsed']-$u['_blinkStart'])/$span));
                $u['x']=$u['_blinkFrom'][0]+($u['_blinkTo'][0]-$u['_blinkFrom'][0])*$fraction;$u['y']=$u['_blinkFrom'][1]+($u['_blinkTo'][1]-$u['_blinkFrom'][1])*$fraction;
                if($fraction>=1){$u['blinking']=false;$u['state']='walk';$u['_path']=[];$u['_repath']=0;}
            }
            if (isset($u['_flameNext']) && $u['_flameNext'] <= $s['elapsed'] + 0.00001) {
                $u['_flameNext'] += 1;
                self::hurtUnit($s,$i,$u['_flameDps'],$u['_flameSource']??'magmatower');
                if($u['hp']<=0){unset($u);continue;}
            }
            if(($u['_venom']??0)>0 && (float)($u['_venomNext']??INF)<=$s['elapsed']+0.00001){
                $damage=(float)$u['_venom'];$u['_venomNext']=(float)$u['_venomNext']+1.0;unset($u);self::hurtUnit($s,$i,$damage,'venom');
            }else{unset($u);}
        }
    }

    private static function cloak(array &$s,array &$u,bool $moving):void
    {
        if(($u['_lab']['cloakDelay']??0)<=0){return;}
        $before=(bool)($u['cloaked']??false);
        if($moving){$u['cloaked']=true;$u['_cloakMoving']=true;}
        else{
            if($u['_cloakMoving']??false){$u['_cloakUntil']=$s['elapsed']+(float)$u['_lab']['cloakDelay'];$u['_cloakMoving']=false;}
            $u['cloaked']=(float)($u['_cloakUntil']??0)>$s['elapsed'];
        }
        if($before!==$u['cloaked']){self::event($s,'cloak',['x'=>$u['x'],'y'=>$u['y'],'sourceId'=>$u['id'],'kind'=>$u['type'],'team'=>$u['team'],'active'=>$u['cloaked']]);}
    }

    /** Apply one ordinary attack and its separately researched, frozen-in-battle effects. */
    private static function strike(array &$s,int $attacker,int $target,bool $building):void
    {
        $u=$s['units'][$attacker];$victim=$building?$s['buildings'][$target]:$s['units'][$target];
        if($victim['hp']<=0){return;}$damage=(float)$u['_damage'];$lab=$u['_lab']??[];
        $fireball=false;
        if(isset($u['_korathHits'])){
            if($u['power']>=3&&$u['level']>=5&&$u['_korathHits']>=3){
                $s['units'][$attacker]['_korathHits']=0;
                self::radialDamage($s,$u,$damage,2.5*$u['_range'],'korath_stomp',true,true,true,1.5*$u['_range']);
                return;
            }
            $fireball=!$building&&$victim['movement']==='air'&&$u['_targetMode']==='both';
            if($fireball){$damage*=0.25;}
            else{++$s['units'][$attacker]['_korathHits'];}
            if(!$building&&!isset($s['units'][$target]['_flameDps'])){
                $s['units'][$target]['_flameSource']='korath_flame';$s['units'][$target]['_flameDps']=0.1*$u['_damage'];$s['units'][$target]['_flameNext']=$s['elapsed']+1;
                $s['units'][$target]['burning']=true;
            }
        }
        if($building){
            if(($victim['_damage']??0)>0||$victim['type']==='bunker'){$damage*=$u['_towerDamage']??1;}
            if(Catalog::isWall($victim['type'])){$damage*=$u['_wallDamage']??1;}
            if(!empty($u['_kingShock'])&&($u['_shockTarget']??null)!==$victim['id']){
                $s['units'][$attacker]['_shockTarget']=$victim['id'];
                self::radialDamage($s,$u,4*$damage,1.25,'emerge_splash',true,true,false);
                if($s['buildings'][$target]['hp']<=0){return;}
            }
        }elseif(!empty($u['_hunt'])){$damage*=3;}
        if(($lab['emergeMultiplier']??0)>0 && ($s['units'][$attacker]['_lastEmergeTarget']??null)!==$victim['id']){
            $s['units'][$attacker]['_lastEmergeTarget']=$victim['id'];
            self::radialDamage($s,$u,$damage*(float)$lab['emergeMultiplier'],(float)$lab['emergeRadius'],'emerge_splash',true,true);
            if($s['units'][$attacker]['hp']<=0||($building?$s['buildings'][$target]['hp']:$s['units'][$target]['hp'])<=0){return;}
        }
        $rockets=isset($lab['rocketRange']);$shots=$rockets?2:1;
        // Unit victims emit their own damage event. A separate amount-free cue
        // identifies the melee actor without duplicating damage text or shots.
        if(!$building&&!$fireball&&$u['_range']<=1.2&&(int)($s['formatVersion']??0)>=7){self::event($s,'strike',['x'=>$u['x'],'y'=>$u['y'],'tx'=>$victim['x'],'ty'=>$victim['y'],'sourceId'=>$u['id'],'targetId'=>$victim['id'],'kind'=>$u['type'],'team'=>$u['team']]);}
        for($shot=0;$shot<$shots;$shot++){
            if($building||$u['_range']>1.2||$fireball){self::event($s,$u['_range']>1.2||$fireball?'shot':'hit',['x'=>$u['x'],'y'=>$u['y'],'tx'=>$victim['x'],'ty'=>$victim['y'],'sourceId'=>$u['id'],'targetId'=>$victim['id'],'amount'=>$building?self::buildingDamage($s,$victim,$damage/$shots):$damage/$shots,'kind'=>$u['type'],'team'=>$u['team'],'projectile'=>$fireball?'magma':($rockets?'rocket':($u['_projectile']??'normal')),'volley'=>$shot]);}
            if($building){self::hurtBuilding($s,$target,$damage/$shots);}else{self::hurtUnit($s,$target,$damage/$shots,$u['type']);}
        }
        if($building && isset($s['_raiderLoot'])){
            $depleted=RaiderLoot::hit($s,$u,$victim,max(0,(float)$victim['hp']-(float)$s['buildings'][$target]['hp']));
            if ($depleted) { $s['units'][$attacker]['targetId']=null; }
        }
        if($building&&isset($s['krallen']['_index'])){KrallenHunt::damage($s,$u,$victim,(float)$victim['hp']-(float)$s['buildings'][$target]['hp']);}
        if(!$building && ($lab['venomFraction']??0)>0 && $s['units'][$target]['hp']>0){
            $v=&$s['units'][$target];$v['_venom']=(float)($v['_venom']??0)+$damage*(float)$lab['venomFraction'];
            $v['_venomNext']??=$s['elapsed']+1.0;$v['poisoned']=true;
            self::event($s,'venom',['x'=>$v['x'],'y'=>$v['y'],'sourceId'=>$u['id'],'targetId'=>$v['id'],'kind'=>$u['type'],'team'=>$u['team'],'amount'=>$damage*(float)$lab['venomFraction']]);unset($v);
        }
        if(($lab['extraTargets']??0)>0){
            $extra=[];$radius=(float)$lab['extraRadius'];
            foreach($s['units'] as $i=>$v){
                if($v['hp']<=0||$v['team']===$u['team']||(!$building&&$i===$target)||!self::canHit('ground',$v)){continue;}
                $distance=hypot($u['x']-$v['x'],$u['y']-$v['y']);if($distance<=$radius){$extra[]=['building'=>false,'index'=>$i,'distance'=>$distance,'id'=>(string)$v['id']];}
            }
            if($u['team']==='attacker'){foreach($s['buildings'] as $i=>$v){
                if($v['hp']<=0||(Catalog::isTrap($v['type'])||($v['_untargetable']??false))||($building&&$i===$target)){continue;}$distance=self::distanceToBuilding($u['x'],$u['y'],$v);
                if($distance<=$radius){$extra[]=['building'=>true,'index'=>$i,'distance'=>$distance,'id'=>(string)$v['id']];}
            }}
            usort($extra,static fn(array $a,array $b):int=>($a['distance']<=>$b['distance'])?:strcmp($a['id'],$b['id']));
            foreach(array_slice($extra,0,(int)$lab['extraTargets']) as $hit){
                $v=$hit['building']?$s['buildings'][$hit['index']]:$s['units'][$hit['index']];
                self::event($s,'multistrike',['x'=>$u['x'],'y'=>$u['y'],'tx'=>$v['x'],'ty'=>$v['y'],'sourceId'=>$u['id'],'targetId'=>$v['id'],'amount'=>$hit['building']?self::buildingDamage($s,$v,$damage):$damage,'kind'=>$u['type'],'team'=>$u['team']]);
                if($hit['building']){self::hurtBuilding($s,$hit['index'],$damage);}else{self::hurtUnit($s,$hit['index'],$damage,$u['type']);}
            }
        }
        if(($lab['splashRadius']??0)>0){
            $radius=(float)$lab['splashRadius'];self::event($s,'whirlwind',['x'=>$u['x'],'y'=>$u['y'],'sourceId'=>$u['id'],'radius'=>$radius,'kind'=>$u['type'],'team'=>$u['team']]);
            foreach($s['units'] as $i=>$v){if($v['hp']>0&&$v['team']!==$u['team']&&($building||$i!==$target)&&self::canHit('ground',$v,true)&&hypot($v['x']-$u['x'],$v['y']-$u['y'])<=$radius){self::hurtUnit($s,$i,$damage,$u['type']);}}
        }
        if($building && $u['team']==='attacker' && ($lab['bounces']??0)>0){
            $previous=$victim;$seen=[$target=>true];$bounceDamage=$damage;
            for($bounce=0;$bounce<(int)$lab['bounces'];$bounce++){
                $best=null;$score=INF;
                foreach($s['buildings'] as $i=>$v){
                    if(isset($seen[$i])||$v['hp']<=0||(Catalog::isTrap($v['type'])||($v['_untargetable']??false))){continue;}$distance=self::distanceToBuilding($previous['x'],$previous['y'],$v);
                    if($distance>(float)$lab['bounceRadius']){continue;}$candidate=$distance+(Catalog::isWall($v['type'])?1000:0);
                    if($candidate<$score){$score=$candidate;$best=$i;}
                }
                if($best===null){break;}$next=$s['buildings'][$best];$seen[$best]=true;$bounceDamage*=0.5;
                self::event($s,'ricochet',['x'=>$previous['x'],'y'=>$previous['y'],'tx'=>$next['x'],'ty'=>$next['y'],'sourceId'=>$u['id'],'targetId'=>$next['id'],'amount'=>self::buildingDamage($s,$next,$bounceDamage),'kind'=>$u['type'],'team'=>$u['team']]);
                self::hurtBuilding($s,$best,$bounceDamage);$previous=$next;
            }
        }
    }

    private static function radialDamage(array &$s,array $source,float $damage,float $radius,string $event,bool $buildings,bool $falloff,bool $units=true,float $inner=0.0):void
    {
        self::event($s,$event,['x'=>$source['x'],'y'=>$source['y'],'sourceId'=>$source['id'],'amount'=>$damage,'radius'=>$radius,'kind'=>$source['type'],'team'=>$source['team']]);
        if($buildings&&$source['team']==='attacker'){
            foreach($s['buildings'] as $i=>$b){
                if($b['hp']<=0||(Catalog::isTrap($b['type'])||($b['_untargetable']??false))){continue;}$distance=self::distanceToBuilding($source['x'],$source['y'],$b);
                if($distance<=$radius){self::hurtBuilding($s,$i,$damage*($falloff?max(0,1-max(0,$distance-$inner)/($radius-$inner)):1));}
            }
        }
        if(!$units){return;}
        foreach($s['units'] as $i=>$v){
            if($v['hp']<=0||$v['team']===$source['team']||!self::canHit('ground',$v,true)){continue;}$distance=hypot($v['x']-$source['x'],$v['y']-$source['y']);
            if($distance<=$radius){self::hurtUnit($s,$i,$damage*($falloff?max(0,1-max(0,$distance-$inner)/($radius-$inner)):1),$event);}
        }
    }

    /** Server-scheduled impacts use frozen payloads and never trust browser-authored damage. */
    private static function resolveCatapult(array &$s):void
    {
        if(empty($s['catapult']['pending'])){return;}$waiting=[];
        foreach($s['catapult']['pending'] as $shot){
            if((float)$shot['impactAt']>$s['elapsed']+0.00001){$waiting[]=$shot;continue;}
            $bomb=$shot['_bomb'];$radius=(float)$shot['radius'];$type=$shot['type'];
            self::event($s,'catapult_impact',['x'=>$shot['x'],'y'=>$shot['y'],'kind'=>$type,'level'=>$shot['level'],'radius'=>$radius,'amount'=>(float)($bomb['damage']??0),'team'=>'attacker']);
            if($type==='putty'){
                foreach($s['units'] as &$u){
                    if($u['team']!=='attacker'||$u['hp']<=0||($u['cloaked']??false)||($u['blinking']??false)||($u['_submerged']??false)||($u['_puttyUntil']??0)>$s['elapsed']||hypot($u['x']-$shot['x'],$u['y']-$shot['y'])>$radius){continue;}
                    $u['_puttyUntil']=$s['elapsed']+(float)$bomb['duration'];$u['_puttySpeed']=(float)$bomb['speed'];$u['_puttyArmor']=(float)$bomb['armor'];$u['boosted']=true;
                    self::event($s,'buff',['x'=>$u['x'],'y'=>$u['y'],'targetId'=>$u['id'],'kind'=>'putty','team'=>'attacker','duration'=>$bomb['duration'],'speed'=>$bomb['speed'],'armor'=>$bomb['armor']]);
                }unset($u);
            }else{
                foreach($s['buildings'] as $i=>$b){
                    if($b['hp']<=0||($b['_untargetable']??false)||in_array($b['type'],['trap','heavytrap','championcage'],true)||self::distanceToBuilding($shot['x'],$shot['y'],$b)>$radius){continue;}
                    $factor=Catalog::isWall($b['type'])?0.06:($b['type']==='storage'?max(1,(int)$b['level']):(($b['_damage']??0)>0?0.9:1.0));
                    self::hurtBuilding($s,$i,(float)$bomb['damage']*$factor);
                }
            }
        }
        $s['catapult']['pending']=$waiting;
    }

    private static function emerge(array &$s,array &$u):void
    {
        if ($u['movement']==='burrow' && ($u['_submerged']??false)) {
            $u['_submerged']=false;self::event($s,'emerge',['x'=>$u['x'],'y'=>$u['y'],'sourceId'=>$u['id'],'kind'=>$u['type'],'team'=>$u['team']]);
        }
    }

    private static function moveToward(array &$s,array &$u,array $target,bool $breach):bool
    {
        self::cloak($s,$u,true);
        if ($u['movement']!=='ground') {
            $u['state']=$u['movement']==='burrow'?'burrow':'walk';$u['_submerged']=$u['movement']==='burrow';
            $dx=$target['x']-$u['x'];$dy=$target['y']-$u['y'];$length=hypot($dx,$dy);
            if ($length>0) { $step=min($length,(float)$u['_speed']*(float)($u['_auraSpeed']??1)*self::STEP);$u['x']+=$dx/$length*$step;$u['y']+=$dy/$length*$step; }
            return true;
        }
        $u['state']='walk';$u['_repath']-=(float)self::STEP;
        $movingTarget=is_string($target['id']);
        $needsPath=(!$u['_path'] && $u['_repath']<=0)||($u['_obstacleVersion']??-1)!==$s['obstacleVersion']||($u['_pathTarget']??null)!==$target['id']||($movingTarget && $u['_repath']<=0);
        if ($needsPath) {
            $u['_path']=self::path($s,$u,$target);$u['_repath']=$movingTarget?0.75:2.0;$u['_obstacleVersion']=$s['obstacleVersion'];$u['_pathTarget']=$target['id'];
            if (!$u['_path'] && $breach) {
                $wall=self::nearestWall($s,$u);
                if ($wall!==null && $s['buildings'][$wall]['id']!==$target['id']) {
                    $u['targetId']=(int)$s['buildings'][$wall]['id'];$u['_path']=self::path($s,$u,$s['buildings'][$wall]);$u['_pathTarget']=$u['targetId'];
                }
            }
        }
        if(!empty($u['_path']) && ($u['_lab']['blinkRange']??0)>0 && (float)($u['_blinkReadyAt']??0)<=$s['elapsed']){
            $landing=$u['_path'][count($u['_path'])-1];$distance=hypot($landing[0]-$u['x'],$landing[1]-$u['y']);
            if($distance>0.75 && $distance<=(float)$u['_lab']['blinkRange']){
                $u['_blinkStart']=(float)$s['elapsed'];$u['_blinkUntil']=$s['elapsed']+(float)$u['_lab']['blinkDuration'];$u['_blinkReadyAt']=$s['elapsed']+(float)$u['_lab']['blinkCooldown'];
                $u['_blinkFrom']=[$u['x'],$u['y']];$u['_blinkTo']=$landing;$u['blinking']=true;$u['state']='blink';$u['_path']=[];
                self::event($s,'blink',['x'=>$u['x'],'y'=>$u['y'],'tx'=>$landing[0],'ty'=>$landing[1],'sourceId'=>$u['id'],'kind'=>$u['type'],'team'=>$u['team'],'duration'=>(float)$u['_lab']['blinkDuration']]);return true;
            }
        }
        $canMove=(bool)$u['_path'];self::moveUnit($u,(float)$u['_speed']*(float)($u['_auraSpeed']??1)*self::STEP);return $canMove;
    }

    private static function enemyUnit(array $s,array $u):?int
    {
        if (($u['_ignoreEnemyUntil']??0)>$s['elapsed'] || (($u['_target']??'')==='wall'&&$u['team']==='attacker'&&($u['_lab']['airburstBonus']??0)<=0)) { return null; }
        $best=null;$distance=INF;$radius=$u['team']==='defender'||!empty($u['_hunt'])?50:max(4.0,(float)$u['_range']+1);
        if($u['cloaked']??false){$radius=1.0;}
        foreach($s['units'] as $i=>$other){
            if($other['hp']<=0 || $other['team']===$u['team'] || !self::canHit($u['_targetMode']??($u['movement']==='air'?'both':'ground'),$other)){continue;}
            if(isset($u['_intercept']) && $u['_intercept']===$other['id'])return $i;
            $d=hypot($u['x']-$other['x'],$u['y']-$other['y']);if($d<=$radius&&$d<$distance){$distance=$d;$best=$i;}
        }
        return $best;
    }

    public static function canHit(string $mode,array $unit,bool $splash=false):bool
    {
        if(($unit['blinking']??false)||(!$splash&&($unit['cloaked']??false))){return false;}
        if (($unit['_submerged']??false) || ($unit['state']??'')==='burrow') { return false; }
        if(!empty($unit['_lowFlight'])){return in_array($mode,['ground','air','both'],true);}
        $air=($unit['movement']??'ground')==='air';return $mode==='both'||($mode==='air'&&$air)||($mode==='ground'&&!$air);
    }

    private static function heal(array &$s,int $index):void
    {
        $u=&$s['units'][$index];$target=null;$best=INF;$follow=null;$followDistance=INF;
        foreach($s['units'] as $i=>$other){
            if($i===$index||$other['hp']<=0||$other['team']!==$u['team']||($other['_submerged']??false)){continue;}
            $d=hypot($u['x']-$other['x'],$u['y']-$other['y']);
            if(($other['_ability']??'')!=='heal'&&$d<$followDistance){$follow=$i;$followDistance=$d;}
            if($other['hp']<$other['max_hp']&&empty($other['_antiHeal'])&&(!isset($u['_antiHeal'])||$other['type']!=='zafreeti')){$score=$d+($other['hp']/$other['max_hp'])*4;if($score<$best){$target=$i;$best=$score;}}
        }
        if($target===null){$u['state']='idle';$u['targetId']=null;if($follow!==null&&$followDistance>2){$other=$s['units'][$follow];self::moveToward($s,$u,['id'=>$other['id'],'x'=>$other['x'],'y'=>$other['y'],'_size'=>0],false);}return;}
        $other=$s['units'][$target];$u['targetId']=$other['id'];$distance=hypot($u['x']-$other['x'],$u['y']-$other['y']);
        if($distance>(float)$u['_range']){self::moveToward($s,$u,['id'=>$other['id'],'x'=>$other['x'],'y'=>$other['y'],'_size'=>0],false);return;}
        $u['state']='heal';if($u['_cooldown']>0){return;}$u['_cooldown']+=(float)$u['_interval'];
        $amount=min((float)($u['_heal']??0),(float)$other['max_hp']-(float)$other['hp']);$s['units'][$target]['hp']+=$amount;
        if($amount>0){self::event($s,'heal',['x'=>$u['x'],'y'=>$u['y'],'tx'=>$other['x'],'ty'=>$other['y'],'sourceId'=>$u['id'],'targetId'=>$other['id'],'amount'=>$amount,'kind'=>$u['type'],'team'=>$u['team']]);}
    }

    private static function explode(array &$s,int $index):void
    {
        $u=$s['units'][$index];$bonus=(float)($u['_lab']['airburstBonus']??0);$radius=max(0.5,(float)($u['_splash']??2.4))*(1+$bonus);$u['_damage']*=1+$bonus;
        self::event($s,'explode',['x'=>$u['x'],'y'=>$u['y'],'sourceId'=>$u['id'],'amount'=>$u['_damage'],'radius'=>$radius,'kind'=>$u['type'],'team'=>$u['team'],'airburst'=>$bonus>0,'labLevel'=>(int)($u['labLevel']??0)]);
        if($u['team']==='attacker'){
            foreach($s['buildings'] as $i=>$b){if($b['hp']<=0||(Catalog::isTrap($b['type'])||($b['_untargetable']??false))){continue;}$d=self::distanceToBuilding($u['x'],$u['y'],$b);if($d<=$radius){self::hurtBuilding($s,$i,(float)$u['_damage']*(1-0.4*$d/$radius));}}
        }
        foreach($s['units'] as $i=>$other){if($other['hp']<=0||$other['team']===$u['team']||!self::canHit($bonus>0?'both':'ground',$other,true)){continue;}$d=hypot($u['x']-$other['x'],$u['y']-$other['y']);if($d<=$radius){$air=($other['movement']??'ground')==='air';self::hurtUnit($s,$i,(float)$u['_damage']*(1-0.4*$d/$radius)*($air?$bonus:1),'eyera');}}
        self::hurtUnit($s,$index,(float)$u['hp'],'eyera',true);
    }

    public static function hurtBuilding(array &$s,int $index,float $damage):void
    {
        if($s['buildings'][$index]['hp']<=0||($s['buildings'][$index]['_untargetable']??false)){return;}$b=&$s['buildings'][$index];
        // Every building-damage route passes here. Independent paid Fortify and
        // adjacent map support multiply once without changing maximum health.
        // Both values are frozen at start; old saved formats ignore new rules.
        $b['hp']=max(0.0,(float)$b['hp']-self::buildingDamage($s,$b,$damage));
        if($b['hp']<=0){if(isset($s['_compounds'][$b['id']]))InfernoCompound::destroyed($s,(int)$b['id']);self::event($s,'destroy',['x'=>$b['x'],'y'=>$b['y'],'targetId'=>$b['id'],'kind'=>$b['type']]);++$s['obstacleVersion'];}
    }

    /** Shared by health settlement and per-target visual damage amounts. */
    private static function buildingDamage(array $s,array $building,float $damage):float
    {
        $reduction=(int)($s['formatVersion']??0)>=6?max(0.0,min(0.5,(float)($building['_fortificationReduction']??0))):0.0;
        $network=(int)($s['formatVersion']??0)>=7?max(0.0,min(0.75,(float)($building['_networkReduction']??0))):0.0;
        return max(0.0,$damage)*(1-$reduction)*(1-$network);
    }

    public static function hurtUnit(array &$s,int $index,float $damage,string $kind,bool $ignoreArmor=false):void
    {
        if($s['units'][$index]['hp']<=0){return;}$u=&$s['units'][$index];
        // Resolve live sources on every hit: dead or distant Fomor cannot protect later hits.
        $armor=0.0;$putty=0.0;
        $beast=(!$ignoreArmor && ($s['_storePowers'][$u['team']]['MDOD']??0)>$s['elapsed'])?.3:0;
        if(!$ignoreArmor){foreach(($s['_auraSources']??[]) as $sourceIndex){
            $source=$s['units'][$sourceIndex];
            if($source['id']===$u['id']||$source['hp']<=0||$source['team']!==$u['team']||($source['auraRange']??0)<=0){continue;}
            if(hypot($source['x']-$u['x'],$source['y']-$u['y'])<=(float)$source['auraRange']){$armor=max($armor,(float)($source['auraBuff']??0));}
        }
            if(($u['_puttyUntil']??0)>$s['elapsed']){$putty=max(0.0,min(0.9,(float)($u['_puttyArmor']??0)));}
        }
        // Independent reductions multiply, with a 95% overall ceiling. No bonus outgoing damage.
        $reduction=min(0.95,1-(1-min(0.9,max(0.0,$armor)))*(1-$putty)*(1-$beast));
        $damage=max(0.0,$damage)*(1-$reduction);$u['hp']=max(0.0,(float)$u['hp']-$damage);
        self::event($s,'hit',['x'=>$u['x'],'y'=>$u['y'],'targetId'=>$u['id'],'amount'=>$damage,'kind'=>$kind,'team'=>$u['team']]);
        if($u['hp']<=0){
            $u['state']='dead';$u['cloaked']=false;$u['blinking']=false;$u['poisoned']=false;$u['boosted']=false;
            self::event($s,($u['champion']??false)?'champion_retreat':'death',['x'=>$u['x'],'y'=>$u['y'],'targetId'=>$u['id'],'kind'=>$u['type'],'team'=>$u['team']]);
            if(isset($u['_flameDps'])){$u['burning']=false;}
            $dead=$u;unset($u);
            if(!empty($dead['_splits'])){MonsterExpansion::split($s,$dead);}
            if(!empty($dead['_scorch'])){self::event($s,'scorch',['x'=>$dead['x'],'y'=>$dead['y'],'sourceId'=>$dead['id'],'kind'=>$dead['type'],'team'=>$dead['team']]);}
            if(($dead['auraRange']??0)>0){self::refreshAuras($s);}
            if(($dead['_lab']['deathMultiplier']??0)>0){self::radialDamage($s,$dead,(float)$dead['_damage']*(float)$dead['_lab']['deathMultiplier'],(float)$dead['_lab']['deathRadius'],'acid',true,true);}
        }
    }

    private static function releaseGarrisons(array &$s):void
    {
        foreach($s['buildings'] as &$b){
            if($b['type']!=='bunker'||$b['hp']<=0||!($b['_ready']??true)||($b['_released']??false)){continue;}
            $near=null;
            foreach($s['units'] as $u){if($u['team']==='attacker'&&$u['hp']>0&&hypot($u['x']-$b['x'],$u['y']-$b['y'])<=($b['_range']?:6)){$near=$u;break;}}
            if ($near===null && !empty($s['siege']['active'])) { $near=SiegeCombat::decoyNear($s,$b,true); }
            if($near===null){continue;}$b['_released']=true;$templates=$s['_garrisonTemplates'][(string)$b['id']]??[];
            foreach($templates as $i=>$unit){
                $angle=atan2($near['y']-$b['y'],$near['x']-$b['x'])+($i%5-2)*0.16;
                [$unit['x'],$unit['y']]=self::spawnPosition($s,$b,$angle);
                $s['units'][]=$unit;self::event($s,'deploy',['x'=>$unit['x'],'y'=>$unit['y'],'sourceId'=>$unit['id'],'kind'=>$unit['type'],'team'=>'defender']);
            }
        }unset($b);
    }

    public static function spawnPosition(array $s,array $b,float $angle):array
    {
        for($ring=0;$ring<7;$ring++){
            for($sample=0;$sample<24;$sample++){
                $a=$angle+($sample%2===0?1:-1)*ceil($sample/2)*M_PI/12;
                $radius=($b['_size']/2+0.4+$ring*0.5)/max(abs(cos($a)),abs(sin($a)));
                $x=$b['x']+cos($a)*$radius;$y=$b['y']+sin($a)*$radius;
                if(!YardSpace::contains($s,$x,$y,.4)){continue;}$clear=true;
                foreach($s['buildings'] as $other){
                    if($other['hp']<=0||(Catalog::isTrap($other['type'])||($other['_untargetable']??false))){continue;}$half=$other['_size']/2+0.2;
                    if(abs($x-$other['x'])<$half&&abs($y-$other['y'])<$half){$clear=false;break;}
                }
                if($clear){return[$x,$y];}
            }
        }
        // Completely packed yards are searched for the closest unoccupied grid point.
        $best=null;$distance=INF;
        $margin=YardSpace::margin($s);
        for($x=0.75-$margin;$x<32+$margin;$x+=0.5){for($y=0.75-$margin;$y<32+$margin;$y+=0.5){$clear=true;
            foreach($s['buildings'] as $other){if($other['hp']<=0||(Catalog::isTrap($other['type'])||($other['_untargetable']??false))){continue;}$half=$other['_size']/2+0.2;if(abs($x-$other['x'])<$half&&abs($y-$other['y'])<$half){$clear=false;break;}}
            if($clear&&($d=hypot($x-$b['x'],$y-$b['y']))<$distance){$distance=$d;$best=[$x,$y];}
        }}
        return $best??[0.5,0.5];
    }

    private static function triggerTraps(array &$s):void
    {
        foreach ($s['buildings'] as &$trap) {
            if (!Catalog::isTrap($trap['type']) || $trap['hp'] <= 0 || !($trap['_ready'] ?? true)) {
                continue;
            }
            $heavy = $trap['type'] === 'heavytrap';
            $trigger = false;
            foreach ($s['units'] as $unit) {
                if ($unit['team'] !== 'attacker' || $unit['hp'] <= 0 || !self::canHit('ground', $unit, true)) {
                    continue;
                }
                if ($heavy && !in_array($unit['type'], $trap['_triggerTypes'], true)) {
                    continue;
                }
                if (hypot($unit['x'] - $trap['x'], $unit['y'] - $trap['y']) <= ($trap['_range'] ?? 1.25)) {
                    $trigger = true;
                    break;
                }
            }
            if (!$trigger) { continue; }
            $trap['hp'] = 0.0;
            $trap['triggered'] = true;
            $radius = $trap['_splash'] ?? 2.3;
            self::event($s, 'trap', ['x'=>$trap['x'], 'y'=>$trap['y'], 'targetId'=>$trap['id'],
                'amount'=>$trap['_damage'], 'radius'=>$radius, 'kind'=>$trap['type']]);
            foreach ($s['units'] as $index=>$unit) {
                if ($unit['team'] !== 'attacker' || $unit['hp'] <= 0 || !self::canHit($heavy ? 'both' : 'ground', $unit, true)) {
                    continue;
                }
                $distance = hypot($unit['x'] - $trap['x'], $unit['y'] - $trap['y']);
                if ($distance > $radius) { continue; }
                $damage = $trap['_damage'];
                if ($heavy) {
                    $damage *= 1 - 0.5 * $distance / $radius;
                    if ($unit['movement'] === 'air') { $damage *= 0.5; }
                }
                self::hurtUnit($s, $index, (float)$damage, $trap['type']);
            }
        }
        unset($trap);
    }

    private static function fireTowers(array &$s):void
    {
        foreach($s['buildings'] as &$tower){
            if(in_array($tower['type'],['trap','heavytrap','bunker'],true)||$tower['hp']<=0||($tower['_damage']??0)<=0||!($tower['_ready']??true)){continue;}
            $tower['_cooldown']=max(0.0,(float)($tower['_cooldown']??0)-self::STEP);if($tower['_cooldown']>0){continue;}
            $mode = $tower['_targetMode'] ?? 'both';
            $aerial = $tower['type'] === 'aerial';
            $target = null;
            $nearest = INF;
            $distances = [];
            foreach ($s['units'] as $index=>$unit) {
                if ($unit['team'] !== 'attacker' || $unit['hp'] <= 0 || !self::canHit($mode, $unit)) { continue; }
                $distance = hypot($tower['x'] - $unit['x'], $tower['y'] - $unit['y']);
                if ($distance > $tower['_range']) { continue; }
                if ($aerial) { $distances[$index] = $distance; }
                if ($distance < $nearest) { $nearest = $distance; $target = $index; }
            }
            if (isset($tower['_behavior'])) { ExpandedDefenses::fire($s,$tower,$target); continue; }
            if ($target === null) { if (!empty($s['siege']['active'])) { SiegeCombat::shootHose($s,$tower); } continue; }
            $tower['_cooldown'] = (float)$tower['_interval'];
            $damage = (float)$tower['_damage'];
            if ($aerial) {
                asort($distances, SORT_NUMERIC);
                $targets = array_keys($distances);
                $target = $targets[$tower['_shotsFired'] % min(count($targets), $tower['_volley'])];
                $damage *= 0.5 + 0.5 * min(1, $tower['hp'] / $tower['max_hp']);
                if (++$tower['_shotsFired'] >= $tower['_volley']) {
                    $tower['_shotsFired'] = 0;
                    $tower['_cooldown'] = $tower['_reload'];
                }
            }
            if (isset($tower['jarHp']) && SiegeCombat::absorbJar($s,$tower,$damage)) { continue; }
            $victim = $s['units'][$target];
            self::event($s,'shot',['x'=>$tower['x'],'y'=>$tower['y'],'tx'=>$victim['x'],'ty'=>$victim['y'],'sourceId'=>$tower['id'],'targetId'=>$victim['id'],'amount'=>$damage,'kind'=>$tower['type'],'team'=>'defender']);
            $splash=$tower['_splash']??($tower['type']==='cannon'?1.5:($tower['type']==='tesla'?1.1:0));
            foreach($s['units'] as $i=>$u){
                if($u['team']!=='attacker'||$u['hp']<=0||!self::canHit($mode,$u,true)){continue;}
                if($i!==$target&&($splash<=0||hypot($u['x']-$victim['x'],$u['y']-$victim['y'])>$splash)){continue;}
                self::hurtUnit($s,$i,$damage*($i===$target?1:0.5),$tower['type']);
            }
        }unset($tower);
    }

    private static function chooseTarget(array $s, array $u): ?int
    {
        $best = null;
        $distance = INF;
        $wallsOnly=($u['_target']??'')==='wall' && count(array_filter($s['buildings'],static fn(array $b):bool=>Catalog::isWall($b['type'])&&$b['hp']>0))>0;
        foreach ($s['buildings'] as $i => $b) {
            if ($b['hp'] <= 0 || (Catalog::isTrap($b['type']) || ($b['_untargetable'] ?? false)) || ($wallsOnly && !Catalog::isWall($b['type']))) {
                continue;
            }
            $priority = 1.0;
            if ($u['_target'] === 'krallen') {
                $d=KrallenHunt::targetGroup($b,$s['_garrisonTemplates']??[])*100+self::distanceToBuilding($u['x'],$u['y'],$b);
                if($d<$distance){$distance=$d;$best=$i;}
                continue;
            }
            if (Catalog::isWall($b['type'])) {
                $priority = ($u['_target'] ?? '') === 'wall' ? 0.05 : 8.0;

            } elseif ($u['_target'] === 'hunt' && $b['type']==='bunker' && !empty($s['_garrisonTemplates'][(string)$b['id']])) {
                $priority=0.05;
            } elseif ($u['_target'] === 'defense') {
                $priority = (($b['_damage'] ?? 0) > 0 || $b['type']==='bunker') ? 0.25 : 2.0;
            } elseif (in_array($u['_target'], ['resource', 'resources'], true)) {
                $resourceTarget=in_array($b['type'],['twig','pebble','putty','goo','storage'],true);
                if(isset($s['_raiderLoot']) && in_array($u['type'],['malphus','grokus'],true)){$resourceTarget=RaiderLoot::available($s['_raiderLoot'],$b);}
                $priority=$resourceTarget?0.25:2.0;
            }
            $d = self::distanceToBuilding((float)$u['x'], (float)$u['y'], $b) * $priority;
            if ($d < $distance) {
                $distance = $d;
                $best = $i;
            }
        }
        return $best;
    }

    private static function findBuildingIndex(array $s, int $id): ?int
    {
        foreach ($s['buildings'] as $i => $b) {
            if ((int)$b['id'] === $id) {
                return $i;
            }
        }
        return null;
    }

    private static function nearestWall(array $s, array $u): ?int
    {
        $best = null;
        $distance = INF;
        foreach ($s['buildings'] as $i => $b) {
            if (!Catalog::isWall($b['type']) || $b['hp'] <= 0) {
                continue;
            }
            $d = self::distanceToBuilding((float)$u['x'], (float)$u['y'], $b);
            if ($d < $distance) {
                $distance = $d;
                $best = $i;
            }
        }
        return $best;
    }

    private static function distanceToBuilding(float $x, float $y, array $b): float
    {
        $half = (float)$b['_size'] / 2;
        return hypot(max(0.0, abs($x - (float)$b['x']) - $half), max(0.0, abs($y - (float)$b['y']) - $half));
    }

    /** Grid A*; no diagonal corner cutting, all live structures are obstacles. */
    private static function path(array $s, array $u, array $target): array
    {
        $radius=(float)$u['_range']+(($target['_size']??0)>0?($u['_foundationPadding']??0.1):0.1);
        $margin=YardSpace::margin($s);$grid=(int)(YardSpace::extent($s)/self::CELL);
        $blocked = [];
        foreach ($s['buildings'] as $b) {
            if ($b['hp'] <= 0 || (Catalog::isTrap($b['type']) || ($b['_untargetable'] ?? false))) {
                continue;
            }
            $half = (float)$b['_size'] / 2 + 0.12;
            for ($x = max(0, (int)floor(($b['x']+$margin - $half) / self::CELL)); $x <= min($grid - 1, (int)floor(($b['x']+$margin + $half) / self::CELL)); ++$x) {
                for ($y = max(0, (int)floor(($b['y']+$margin - $half) / self::CELL)); $y <= min($grid - 1, (int)floor(($b['y']+$margin + $half) / self::CELL)); ++$y) {
                    if (abs(($x + 0.5) * self::CELL-$margin - $b['x']) < $half && abs(($y + 0.5) * self::CELL-$margin - $b['y']) < $half) {
                        $blocked[$y * $grid + $x] = true;
                    }
                }
            }
        }
        $sx = max(0, min($grid - 1, (int)floor(($u['x']+$margin) / self::CELL)));
        $sy = max(0, min($grid - 1, (int)floor(($u['y']+$margin) / self::CELL)));
        $start = $sy * $grid + $sx;
        unset($blocked[$start]);
        $open = new SplPriorityQueue();
        $open->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $open->insert($start, 0);
        $cost = [$start => 0.0];
        $came = [];
        $closed = [];
        $end = null;
        $visits = 0;
        while (!$open->isEmpty() && ++$visits <= $grid*$grid+104) {
            $node = $open->extract();
            if (isset($closed[$node])) {
                continue;
            }
            $closed[$node] = true;
            $x = $node % $grid;
            $y = intdiv($node, $grid);
            if (self::distanceToBuilding(($x + 0.5) * self::CELL-$margin, ($y + 0.5) * self::CELL-$margin, $target) <= $radius) {
                $end = $node;
                break;
            }
            foreach ([[1,0],[-1,0],[0,1],[0,-1],[1,1],[1,-1],[-1,1],[-1,-1]] as [$dx,$dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx < 0 || $ny < 0 || $nx >= $grid || $ny >= $grid) {
                    continue;
                }
                $next = $ny * $grid + $nx;
                if (isset($blocked[$next]) || isset($closed[$next])) {
                    continue;
                }
                if ($dx !== 0 && $dy !== 0 && (isset($blocked[$y * $grid + $nx]) || isset($blocked[$ny * $grid + $x]))) {
                    continue;
                }
                $newCost = $cost[$node] + ($dx !== 0 && $dy !== 0 ? 1.414214 : 1.0);
                if (!isset($cost[$next]) || $newCost < $cost[$next]) {
                    $cost[$next] = $newCost;
                    $came[$next] = $node;
                    $heuristic = max(0.0, self::distanceToBuilding(($nx + 0.5) * self::CELL-$margin, ($ny + 0.5) * self::CELL-$margin, $target) - (float)$u['_range']) / self::CELL;
                    $open->insert($next, -($newCost + $heuristic));
                }
            }
        }
        if ($end === null) {
            return [];
        }
        $path = [];
        while ($end !== $start) {
            $path[] = [($end % $grid + 0.5) * self::CELL-$margin, (intdiv($end, $grid) + 0.5) * self::CELL-$margin];
            $end = $came[$end];
        }
        if (!$path && self::distanceToBuilding((float)$u['x'], (float)$u['y'], $target) > $radius) {
            $path[] = [($sx + 0.5) * self::CELL-$margin, ($sy + 0.5) * self::CELL-$margin];
        }
        return array_reverse($path);
    }

    private static function moveUnit(array &$u, float $distance): void
    {
        $guard = 0;
        while ($distance > 0 && $u['_path'] && ++$guard < 8) {
            [$x, $y] = $u['_path'][0];
            $dx = $x - $u['x'];
            $dy = $y - $u['y'];
            $length = hypot($dx, $dy);
            if ($length <= $distance + 0.00001) {
                $u['x'] = $x;
                $u['y'] = $y;
                $distance -= $length;
                array_shift($u['_path']);
            } else {
                $u['x'] += $dx / $length * $distance;
                $u['y'] += $dy / $length * $distance;
                $distance = 0;
            }
        }
        $u['x'] = round((float)$u['x'], 5);
        $u['y'] = round((float)$u['y'], 5);
    }
}
