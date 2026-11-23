<?php
declare(strict_types=1);

/** Original Compound-to-main-Housing ascension, committed on the keeper's single locked home row. */
final class InfernoAscension
{
    private static function cleared(array $r):int
    {
        $count=0;foreach(range(201,213) as $stage)if(isset($r['campaign']['cleared'][$stage]))++$count;
        return $count;
    }

    /** Source upgrades belong to the keeper and continue to apply to ascended members of the same species. */
    public static function syncResearch(array &$base,int $now):void
    {
        if(empty($base['infernoRealm']['ascension']['totals']))return;
        $r=&$base['infernoRealm'];$study=$r['study']??null;
        if($study&&$study['ready_at']<=$now){InfernoMonsters::accrue($r,$now);}
        foreach($r['ascension']['totals'] as $type=>$count){
            if(isset(MonsterExpansion::INFERNO_LOCKERS[$type]))$base['research'][$type]=(int)($r['research'][$type]??1);
        }
    }

    private static function snapshot(array $base,int $now):array
    {
        $r=$base['infernoRealm']??[];$cleared=self::cleared($r);
        $used=Game::housingUsed($base);$capacity=Game::housingCapacity($base);$catalog=Catalog::monsters();$species=[];
        foreach(array_keys(MonsterExpansion::INFERNO_LOCKERS) as $type){
            $spec=$catalog[$type];$species[$type]=['id'=>$spec['originalId'],'name'=>$spec['name'],'art'=>$spec['art'],'space'=>$spec['size'],
                'level'=>$r['research'][$type]??1,'cavern'=>$r['army'][$type]??0,'surface'=>$base['army'][$type]??0];
        }
        return ['opened'=>!empty($r),'available'=>$cleared===13,'cleared'=>$cleared,'used'=>$used,'capacity'=>$capacity,'species'=>$species,
            'last'=>$r['ascension']['last']??null,'totals'=>$r['ascension']['totals']??[]];
    }

    public static function view(int $keeper):array
    {
        $base=Game::loadBase($keeper);$now=time();Game::accrue($base,$now);
        if(isset($base['infernoRealm']))InfernoRealm::accrue($base['infernoRealm'],$now);
        return self::snapshot($base,$now);
    }

    public static function transfer(int $keeper,array $input):array
    {
        $request=$input['requestId']??null;$troops=$input['troops']??null;
        if(!is_string($request)||!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$request)||!is_array($troops)||!$troops||count($troops)>count(MonsterExpansion::INFERNO_LOCKERS)){
            throw new RuntimeException('Choose native monsters for Ascension.');
        }
        foreach($troops as $type=>$quantity){
            if(!is_string($type)||!isset(MonsterExpansion::INFERNO_LOCKERS[$type])||!is_int($quantity)||$quantity<1||$quantity>100000){
                throw new RuntimeException('Choose native species and positive whole quantities.');
            }
        }
        ksort($troops);
        return Db::transaction(static function(PDO $pdo)use($keeper,$input,$request,$troops):array{
            $base=Game::loadBase($keeper,true);$now=time();
            if(!isset($base['infernoRealm'])||self::cleared($base['infernoRealm'])!==13)throw new RuntimeException('Clear all 13 Moloch Descent camps before using Ascension.');
            $r=&$base['infernoRealm'];$prior=$r['ascension']['last']??null;
            if(($prior['requestId']??null)===$request){
                if($prior['troops']!==$troops)throw new RuntimeException('That transfer request belongs to a different order.');
                return self::snapshot($base,$now);
            }
            if(!array_key_exists('previousId',$input)||$input['previousId']!==($prior['requestId']??null))throw new RuntimeException('Your Ascension orders changed. Reopen the portal.');
            $busy=$pdo->prepare("SELECT id FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?) LIMIT 1");$busy->execute([$keeper,$keeper]);
            if($busy->fetchColumn()||InfernoDefense::active($r))throw new RuntimeException('Finish the current battle before transferring monsters.');
            Game::accrue($base,$now);InfernoRealm::accrue($r,$now);$space=0;$catalog=Catalog::monsters();
            foreach($troops as $type=>$quantity){
                if(($r['army'][$type]??0)<$quantity)throw new RuntimeException('Your Compound does not have that many ready '.$catalog[$type]['name'].'.');
                $space+=$catalog[$type]['size']*$quantity;
            }
            if(Game::housingUsed($base)+$space>Game::housingCapacity($base))throw new RuntimeException('Free up more surface monster housing before transferring this group.');
            foreach($troops as $type=>$quantity){
                $r['army'][$type]-=$quantity;$base['army'][$type]=($base['army'][$type]??0)+$quantity;
                $r['ascension']['totals'][$type]=($r['ascension']['totals'][$type]??0)+$quantity;
            }
            $r['ascension']['last']=['requestId'=>$request,'at'=>$now,'troops'=>$troops,'space'=>$space];
            ++$r['revision'];self::syncResearch($base,$now);Game::saveBase($keeper,$base);
            return self::snapshot($base,$now);
        });
    }
}
