<?php
declare(strict_types=1);

/** Opt-in Moloch reprisals damage only the keeper's underground settlement. */
final class InfernoDefense
{
    private const WAVES=[
        ['spurtz'=>3],['spurtz'=>5,'zagnoid'=>2],['zagnoid'=>5,'malphus'=>1],
        ['spurtz'=>8,'valgos'=>3],['balthazar'=>3,'zagnoid'=>5],['valgos'=>5,'grokus'=>2],
        ['sabnox'=>3,'zagnoid'=>7],['kingwormzer'=>2,'sabnox'=>3,'grokus'=>2],
    ];

    public static function active(array $realm):bool
    {
        return ($realm['counterattack']['state']['status']??null)==='active';
    }

    public static function summary(array $realm):array
    {
        $session=$realm['counterattack']??[];
        return ['id'=>$session['id']??null,'status'=>$session['state']['status']??null,
            'cleared'=>$realm['repelled']??0,'wave'=>min(count(self::WAVES),($realm['repelled']??0)+1)];
    }

    public static function run(int $ownerId,array $input):array
    {
        return Db::transaction(static function(PDO $pdo)use($ownerId,$input):array{
            $base=Game::loadBase($ownerId,true);
            if(!isset($base['infernoRealm']))throw new RuntimeException('Open your Inferno entrance first.');
            $realm=&$base['infernoRealm'];$now=time();
            InfernoRealm::accrue($realm,$now);
            $owner=Game::user($ownerId);$op=$input['action']??null;
            if($owner['is_bot'])throw new RuntimeException('Only player keepers can summon a Moloch counterattack.');
            if($op==='start'){
                $key=$input['requestId']??null;
                if(!is_string($key)||!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$key))throw new RuntimeException('Refresh your counterattack controls.');
                if(($realm['counterattack']['requestId']??null)!==$key){
                    if(!array_key_exists('previousId',$input)||$input['previousId']!==($realm['counterattack']['id']??null))throw new RuntimeException('Your counterattack changed. Reopen the cavern.');
                    if(self::active($realm))throw new RuntimeException('A Moloch counterattack is already active.');
                    $busy=$pdo->prepare("SELECT id FROM battles WHERE status='active' AND (attacker_id=? OR defender_id=?) LIMIT 1");
                    $busy->execute([$ownerId,$ownerId]);if($busy->fetchColumn())throw new RuntimeException('Finish the current invasion before calling a counterattack.');
                    if(array_filter($realm['buildings'],fn($b)=>$b['ready_at']>0))throw new RuntimeException('Let the cavern worker finish before calling a counterattack.');
                    if(Game::livingLevel($realm,'townhall')<1)throw new RuntimeException('Repair your Under Hall before calling a counterattack.');
                    $index=self::summary($realm)['wave']-1;
                    $wave=self::WAVES[$index];
                    $state=Baiter::defenseState($realm,$owner,$wave,['tl','tr','br','bl'][$index%4],$now,0,[]);
                    InfernoCompound::seed($state,$realm);
                    $state['attacker']['name']='Moloch';$state['target']=['kind'=>'inferno','name'=>'Moloch counterattack','counterattack'=>true];
                    $realm['counterattack']=['requestId'=>$key,'id'=>bin2hex(random_bytes(16)),'state'=>$state,'wave'=>$index+1,'settled'=>false];
                    ++$realm['revision'];
                }
            }else{
                if(!in_array($op,['poll','end'],true)||!isset($realm['counterattack'])||($input['id']??null)!==$realm['counterattack']['id'])throw new RuntimeException('That counterattack is no longer current.');
            }
            $session=&$realm['counterattack'];$s=&$session['state'];
            if($op!=='start'&&$s['status']==='active'){
                Baiter::advance($s,$now);
                if($op==='end'&&$s['status']==='active')Baiter::siren($s);
            }
            if($s['status']==='finished'&&!$session['settled']){
                InfernoCompound::settle($s,$realm);
                $hp=array_column($s['buildings'],'hp','id');
                foreach($realm['buildings'] as &$b)if(isset($hp[$b['id']]))$b['hp']=max(0,min($b['max_hp'],(float)$hp[$b['id']]));
                unset($b);
                if($s['result']['outcome']==='held')$realm['repelled']=max($realm['repelled']??0,$session['wave']);
                $session['settled']=true;++$realm['revision'];
            }
            Game::saveBase($ownerId,$base);
            $battle=Baiter::snapshot($s,$now);$battle['infernoDefense']=true;
            return ['counterattack'=>self::summary($realm),'battle'=>$battle];
        });
    }
}
