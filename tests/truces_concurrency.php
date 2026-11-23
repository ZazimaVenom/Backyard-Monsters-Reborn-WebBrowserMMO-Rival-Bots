<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

if (PHP_SAPI !== 'cli'||!str_ends_with((string)getenv('MYSQL_TEST_DATABASE'),'_test')) {
    fwrite(STDERR,"Use an empty disposable MYSQL_TEST_DATABASE ending in _test.\n");exit(2);
}
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),
    'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:''];
$db=Db::connect($config);Db::useConnection($db,$config);
function tcAction(string $kind,int $from,int $to,string $token): void {
    if($kind==='attack'){BattleService::start($from,$to);return;}
    Truces::act($from,['keeper'=>$to,'kind'=>$kind,'token'=>$token]);
}
if (($argv[1]??'')==='--child') {
    file_put_contents($argv[6],(string)$db->query('SELECT CONNECTION_ID()')->fetchColumn());
    try{tcAction($argv[2],(int)$argv[3],(int)$argv[4],$argv[5]);echo 'success';}
    catch(RuntimeException $e){echo $e->getMessage();}
    exit;
}
$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
function tcKeeper(PDO $db): int {
    $db->prepare('INSERT INTO users(name,created_at) VALUES(?,?)')->execute(['Race '.bin2hex(random_bytes(4)),time()]);
    $id=(int)$db->lastInsertId();$base=Game::initialBase($id);$base['shield_until']=0;
    $db->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($base),time()]);
    return $id;
}
foreach (['accept','attack'] as $first) {
    $a=tcKeeper($db);$b=tcKeeper($db);$token=bin2hex(random_bytes(16));tcAction('request',$a,$b,$token);
    $db->beginTransaction();$mark=tempnam(sys_get_temp_dir(),'truce-race');$proc=null;
    try {
        $db->prepare('SELECT user_id FROM bases WHERE user_id=? FOR UPDATE')->execute([$a]);
        $cmd=[PHP_BINARY];if($ini=php_ini_loaded_file()){array_push($cmd,'-c',$ini);}
        array_push($cmd,__FILE__,'--child',$first==='accept'?'attack':'accept',(string)($first==='accept'?$a:$b),(string)($first==='accept'?$b:$a),$token,$mark);
        $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);
        $deadline=microtime(true)+8;$blocked=false;
        while(microtime(true)<$deadline && !$blocked){
            $child=(int)file_get_contents($mark);
            if ($child) {
                $q=$db->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=?');$q->execute([$child]);
                $blocked=str_contains(strtolower((string)$q->fetchColumn()),'for update');
            }
            if(!$blocked){usleep(20000);}
        }
        if(!$blocked){throw new RuntimeException('Child did not reach the participant base lock.');}
        tcAction($first,$first==='accept'?$b:$a,$first==='accept'?$a:$b,$token);
        $db->commit();
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);$proc=null;
        $expected=$first==='accept'?'truce':'Finish the invasion';
        if(!str_contains($out,$expected)||$err!==''){throw new RuntimeException('Lock race: '.$out.' '.$err);}
        $q=$db->prepare("SELECT COUNT(*) FROM battles WHERE attacker_id=? AND defender_id=? AND status='active'");$q->execute([$a,$b]);
        $offers=Truces::view($a,$b);
        if((int)$q->fetchColumn()!==($first==='accept'?0:1) || $offers['keepers'][0]['status']!==($first==='accept'?'accepted':'requested')){
            throw new RuntimeException('Incompatible combat and truce were both committed.');
        }
    } finally {
        if($db->inTransaction()){$db->rollBack();}
        if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}
        unlink($mark);
    }
}
echo "Truce concurrency: both lock orders passed on independent database connections.\n";
