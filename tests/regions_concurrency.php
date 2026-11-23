<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

$config=['database'=>getenv('MYSQL_TEST_DATABASE')?:'','host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
if(PHP_SAPI!=='cli'||!str_ends_with($config['database'],'_region_test')){fwrite(STDERR,"Select an empty database ending in _region_test.\n");exit(2);}
$pdo=Db::connect($config);Db::useConnection($pdo,$config);
if(($argv[1]??'')==='child'){
    $user=(int)$argv[2];$in=json_decode($argv[3],true,512,JSON_THROW_ON_ERROR);
    file_put_contents($argv[4],(string)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
    try{$result=Regions::act($user,$in);echo json_encode(['ok'=>true,'revision'=>$result['revision']]);}
    catch(RuntimeException $error){echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);}
    exit;
}
if($pdo->query('SHOW TABLES')->fetch())throw new RuntimeException('Use an empty test schema.');
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));Db::transaction(fn($db)=>World::initialize($db));
$keepers=[];
foreach(['North sector','South sector','West sector'] as $name){
    $id=Game::register($name,'local-only-password')['id'];$b=Game::loadBase($id);$map=$b['buildings'][0];
    $map['type']='maproom';$map['id']=$b['next_building_id']++;$map['x']=6;$map['y']=8;$map['level']=3;$b['buildings'][]=$map;$b['shiny']=3000;
    Game::saveBase($id,$b);$keepers[]=$id;
}
function command(int $who,string $op,array $data): array{
    $v=Regions::overview($who);return $data+['op'=>$op,'request'=>bin2hex(random_bytes(16)),'revision'=>$v['revision'],'estateKey'=>$v['estateKey']];
}
function race(array $actions): array{
    $db=Db::pdo();$jobs=[];$db->beginTransaction();
    try{
        $db->query("SELECT value FROM settings WHERE name='world_allocator' FOR UPDATE")->fetch();
        foreach($actions as [$id,$in]){
            $file=tempnam(sys_get_temp_dir(),'region-race-');
            $php=[PHP_BINARY];if($ini=php_ini_loaded_file()){$php[]='-c';$php[]=$ini;}
            $proc=proc_open([...$php,__FILE__,'child',(string)$id,json_encode($in),$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($proc))throw new RuntimeException('Could not start a region worker.');
            fclose($pipes[0]);$jobs[]=[$proc,$pipes,$file];
        }
        $deadline=microtime(true)+8;
        do{
            $ready=true;
            foreach($jobs as [$proc,$pipes,$file]){
                $conn=(int)file_get_contents($file);
                if(!$conn){$ready=false;break;}
                $q=$db->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');$q->execute([$conn]);
                if(!str_contains((string)$q->fetchColumn(),'FOR UPDATE')){$ready=false;break;}
            }
            if(!$ready)usleep(15000);
        }while(!$ready&&microtime(true)<$deadline);
        if(!$ready)throw new RuntimeException('Timed out before regional workers reached the shared lock.');
        $db->commit();$results=[];
        foreach($jobs as [$proc,$pipes,$file]){
            $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
            if($error)throw new RuntimeException($error);
            $results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        }
        return $results;
    }finally{
        if($db->inTransaction())$db->rollBack();
        foreach($jobs as [$proc,$pipes,$file]){if(proc_get_status($proc)['running'])proc_terminate($proc);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);unlink($file);}
    }
}
$checks=0;
function yes(bool $value,string $label): void{global $checks;if(!$value)throw new RuntimeException($label);++$checks;}
[$a,$b,$c]=$keepers;
$r=race([[$a,command($a,'relocate',['region'=>3])],[$b,command($b,'relocate',['region'=>3])]]);
yes($r[0]['ok']&&$r[1]['ok'],'Two simultaneous settlers both arrived.');$ha=World::home($a);$hb=World::home($b);
yes(Regions::id($ha['q'])===3&&Regions::id($hb['q'])===3&&World::distance($ha['q'],$ha['r'],$hb['q'],$hb['r'])>=7,'Contending home sectors remain distinct and spaced.');
yes(count(World::fortifications($a))===6&&count(World::fortifications($b))===6,'Both settlers retained their own six defense sites.');
$r=race([[$a,command($a,'relocate',['region'=>3])],[$a,command($a,'relocate',['region'=>2])]]);
yes((int)$r[0]['ok']+(int)$r[1]['ok']===1,'One of two different moves from the same old revision wins.');
Regions::act($c,command($c,'relocate',['region'=>2]));
$site=World::load((int)$pdo->query("SELECT id FROM world_sites WHERE q BETWEEN 128 AND 191 AND kind='resource' LIMIT 1")->fetchColumn());$site['owner_id']=$c;World::save($site);
Neighbors::act($b,['kind'=>'request','keeper'=>$c]);Neighbors::act($c,['kind'=>'request','keeper'=>$b]);
Regions::act($c,command($c,'invite',['site'=>$site['id'],'friend'=>$b]));$offer=Regions::overview($b)['invites'][0];
$pay=command($b,'accept',['offer'=>$offer['token'],'payment'=>'shiny']);
$r=race([[$b,$pay],[$b,$pay]]);yes($r[0]['ok']&&$r[1]['ok']&&$r[0]['revision']===$r[1]['revision'],'Concurrent duplicate invite acceptance returns the same arrival.');
yes(Game::loadBase($b)['shiny']===1800&&World::home($b)['q']===$site['q']&&World::home($b)['r']===$site['r'],'One 1,200 Shiny charge and one consumed outpost.');
yes((int)$pdo->query('SELECT COUNT(*) FROM world_sites WHERE id='.$site['id'])->fetchColumn()===0&&!Regions::overview($c)['outposts'],'Surrendered site cannot be spent again.');
echo "PASS $checks concurrent region checks.\n";
