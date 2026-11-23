<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$db=(string)getenv('MYSQL_TEST_DATABASE');
if(PHP_SAPI!=='cli'||!str_ends_with($db,'_test')){fwrite(STDERR,"Choose an empty disposable *_test database.\n");exit(2);}
$config=['database'=>$db,'host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$pdo=Db::connect($config);if($pdo->query('SHOW TABLES')->fetch())throw new RuntimeException('An empty database is required.');
Db::useConnection($pdo,$config);$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$n=0;
function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);++$n;}
function deny(callable $f,string $why):void{try{$f();}catch(RuntimeException $e){check(str_contains($e->getMessage(),$why),$e->getMessage());return;}throw new RuntimeException('Expected guard '.$why);}
$pdo->beginTransaction();
try{
    $owner=Game::register('Native Keeper','disposable-test-password')['id'];$peer=Game::register('Surface Keeper','disposable-test-password')['id'];
    $req=['requestId'=>str_repeat('a',24),'previousId'=>null,'troops'=>['spurtz'=>2,'zagnoid'=>1]];
    check(!InfernoAscension::view($owner)['opened'],'Portal locked before cavern');deny(fn()=>InfernoAscension::transfer($owner,$req),'13 Moloch');
    $b=Game::loadBase($owner);foreach($b['buildings'] as &$building){if($building['type']==='townhall')$building['level']=2;}unset($building);Game::saveBase($owner,$b);
    InfernoRealm::act($owner,['action'=>'enter','requestId'=>str_repeat('k',24)]);
    $b=Game::loadBase($owner);$r=&$b['infernoRealm'];$r['army']['zagnoid']=2;$r['research']['spurtz']=3;
    foreach(range(201,212) as $id)$r['campaign']['cleared'][$id]=100;
    Game::saveBase($owner,$b);check(InfernoAscension::view($owner)['cleared']===12,'Tracks original 13 Descent camps');
    deny(fn()=>InfernoAscension::transfer($owner,$req),'13 Moloch');
    $r['campaign']['cleared'][213]=100;
    $b['army']=array_fill_keys(array_keys($b['army']),0);$b['army']['pokey']=1;
    $b['queue']=[['id'=>88,'type'=>'pokey','quantity'=>500,'ready_at'=>time()+10000,'start_at'=>time(),'lane'=>2]];Game::saveBase($owner,$b);
    deny(fn()=>InfernoAscension::transfer($owner,$req),'surface monster housing');check(Game::loadBase($owner)['infernoRealm']['army']['spurtz']===8,'Pending hatch orders reserve capacity and rejected transfer is atomic');
    $b['queue']=[];Game::saveBase($owner,$b);
    $s=InfernoAscension::view($owner);check($s['available']&&count($s['species'])===8&&$s['species']['spurtz']['id']==='IC1','Only original native species eligible');
    foreach([['pokey'=>1],['slimeattikusmini'=>1],['spurtz'=>0],['spurtz'=>1.5],['spurtz'=>'2'],['spurtz'=>-1]] as $troops)deny(fn()=>InfernoAscension::transfer($owner,array_merge($req,['troops'=>$troops])),'native species');
    deny(fn()=>InfernoAscension::transfer($owner,array_merge($req,['troops'=>['spurtz'=>9]])),'Compound');deny(fn()=>InfernoAscension::transfer($peer,$req),'Descent');
    $after=InfernoAscension::transfer($owner,$req);$committed=Game::loadBase($owner);$slots=2*$s['species']['spurtz']['space']+$s['species']['zagnoid']['space'];
    check($after['used']===$s['used']+$slots&&$after['last']['space']===$slots,'Surface housing charges exact original cStorage');
    check($committed['army']['spurtz']===2&&$committed['infernoRealm']['army']['spurtz']===6&&$committed['army']['zagnoid']===1&&$committed['infernoRealm']['army']['zagnoid']===1,'Immediate departure and arrival conserved across both species');
    check($committed['infernoRealm']['resources']===$r['resources']&&$committed['resources']===$b['resources']&&$committed['research']['spurtz']===3,'Free transfer preserves current native species upgrade');
    check(InfernoAscension::transfer($owner,$req)['last']===$after['last']&&Game::snapshot($owner)['army']['spurtz']===2,'Repeated transfer and reload cannot clone troops');
    deny(fn()=>InfernoAscension::transfer($owner,array_merge($req,['troops'=>['spurtz'=>1]])),'different order');
    deny(fn()=>InfernoAscension::transfer($owner,array_merge($req,['requestId'=>str_repeat('b',24)])),'orders changed');
    $later=['troops'=>['spurtz'=>1],'requestId'=>str_repeat('b',24),'previousId'=>$req['requestId']];
    $committed['infernoRealm']['study']=['type'=>'spurtz','ready_at'=>time()-1,'level'=>4,'paid'=>1];Game::saveBase($owner,$committed);
    $d=Game::loadBase($peer);$d['shield_until']=0;Game::saveBase($peer,$d);
    $battle=BattleService::start($owner,$peer);$id=$battle['id'];check($battle['remaining']['spurtz']===2&&$battle['target']['kind']==='home','Ascended soldiers appear in regular surface Flinger');
    deny(fn()=>InfernoAscension::transfer($owner,$later),'current battle');
    $deployed=BattleService::deploy($owner,$id,['spurtz'=>1],2,2);$u=$deployed['units'][0];
    check($u['type']==='spurtz'&&$u['max_hp']==Catalog::monsters()['spurtz']['levels'][4]['hp'],'Real surface unit uses the completed native research tier');
    BattleService::retreat($owner,$id);$afterRaid=Game::loadBase($owner);
    check($afterRaid['army']['spurtz']===1&&$afterRaid['infernoRealm']['army']['spurtz']===6&&$afterRaid['army']['zagnoid']===1,'Only launched ascended soldiers consumed and unlaunched stay at surface');
    $next=InfernoAscension::transfer($owner,$later);check($next['totals']['spurtz']===3&&$next['species']['spurtz']['surface']===2,'Later independent transfer adds exactly one');
    deny(fn()=>InfernoAscension::transfer($owner,$req),'orders changed');
    $fresh=Game::loadBase($owner);$fresh['buildings'][]=['id'=>700,'type'=>'bunker','level'=>1,'x'=>3,'y'=>5,'hp'=>2500,'max_hp'=>2500,'ready_at'=>0,'upgrade_to'=>null,'stored'=>0];Game::saveBase($owner,$fresh);
    Game::mutate($owner,'garrison',['type'=>'spurtz','id'=>700,'quantity'=>1]);check(Game::loadBase($owner)['garrison'][700]['spurtz']===1,'Owned transferred soldier can defend in surface bunker');
    Game::mutate($owner,'ungarrison',['type'=>'spurtz','id'=>700,'quantity'=>1]);check(Game::loadBase($owner)['army']['spurtz']===2,'Bunker return restores same species to surface housing');
}finally{$pdo->rollBack();}
echo "Inferno Ascension: $n checks passed.\n";
