<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
if(PHP_SAPI!=='cli'||!str_ends_with((string)getenv('MYSQL_TEST_DATABASE'),'_test')){fwrite(STDERR,"Use a disposable MYSQL_TEST_DATABASE ending in _test.\n");exit(2);}
$config=['database'=>getenv('MYSQL_TEST_DATABASE'),'host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$db=Db::connect($config);Db::useConnection($db,$config);$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));$checks=0;
function yc(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);++$checks;}
function yr(callable $fn,string $why):void{try{$fn();}catch(RuntimeException $e){yc(str_contains($e->getMessage(),$why),$e->getMessage());return;}throw new RuntimeException('Unexpected success: '.$why);}
function ykeeper():int{$db=Db::pdo();$db->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,0,?)')->execute(['Post '.bin2hex(random_bytes(4)),time()]);$id=(int)$db->lastInsertId();$b=Game::initialBase($id,false);$b['shield_until']=0;$db->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($b),time()]);return $id;}
function yspot(int $id,float $size):array{$b=Game::loadBase($id);for($x=3;$x<29;$x+=1)for($y=3;$y<29;$y+=1){try{return Decorations::coordinates($b,compact('x','y'),$size,null);}catch(RuntimeException $e){}}throw new RuntimeException('No test space');}
function finished(int $id,string $type):void{$b=Game::loadBase($id);foreach($b['buildings'] as &$v)if($v['type']===$type&&$v['ready_at']>0)$v['ready_at']=time()-1;unset($v);Game::saveBase($id,$b);Game::snapshot($id);}
$db->beginTransaction();
try{
    $a=ykeeper();$d=ykeeper();$other=ykeeper();$yard=Game::snapshot($d)['base'];$wallet=Game::snapshot($a)['base']['resources'];[$x,$y]=yspot($d,.8);
    $token=bin2hex(random_bytes(16));$post=['op'=>'post','yard'=>$d,'token'=>$token,'text'=>'<i>Good fight</i>', 'x'=>$x,'y'=>$y];
    yr(fn()=>YardMessages::changeSign($d,$post),'another keeper');yr(fn()=>YardMessages::changeSign($a,['text'=>' ']+$post),'180');
    $result=YardMessages::changeSign($a,$post);$s=$result['signs'][0];
    yc($s['sender']===$a&&$s['text']===$post['text']&&$s['expires']-$s['created']===172800,'Sender, literal note, exact 48 hour expiry');
    foreach(YardMessages::SIGN_COST as $k=>$amount)yc(Game::loadBase($a)['resources'][$k]==$wallet[$k]-$amount,'Charged author '.$k);
    yc(YardMessages::changeSign($a,$post)==$result,'Retry never charges or places again');
    yr(fn()=>YardMessages::changeSign($other,['op'=>'edit']+$post),'sender');yr(fn()=>YardMessages::changeSign($other,['op'=>'remove']+$post),'owner');
    $edit=YardMessages::changeSign($a,['op'=>'edit','text'=>'See you next raid!']+$post);yc($edit['signs'][0]['expires']===$s['expires'],'Edit preserves expiry');
    yc(Game::visit($d,$other)['signs'][0]['text']==='See you next raid!','Scouting shows public message');
    yr(fn()=>Game::mutate($d,'build',['type'=>'wall','x'=>$x,'y'=>$y]),'decoration');
    $copy=Game::loadBase($d);yc(count(YardMessages::signs($copy,$s['expires']-1))===1&&YardMessages::signs($copy,$s['expires'])===[],'Exact expiry boundary');YardMessages::prune($copy,$s['expires']);yc($copy['yardSigns']===[],'Expired yard space reclaimed');
    yr(fn()=>Game::mutate($d,'radioTune',['attacks'=>true,'news'=>true]),'Radio Tower');
    [$tx,$ty]=yspot($d,1.6);$built=Game::mutate($d,'build',['type'=>'radiotower','x'=>$tx,'y'=>$ty])['base'];yc(!$built['radio']['ready'],'Construction uses real worker');
    finished($d,'radiotower');$tuned=Game::mutate($d,'radioTune',['attacks'=>true,'news'=>true])['base']['radio'];yc($tuned['ready']&&$tuned['attacks']&&$tuned['news']&&$tuned['items']===[],'Independent optional channels and empty receiver');
    yr(fn()=>Game::mutate($d,'build',['type'=>'radiotower','x'=>26,'y'=>26]),'limit');
    [$wx,$wy]=yspot($d,.8);Game::mutate($d,'build',['type'=>'wall','x'=>$wx,'y'=>$wy]);finished($d,'wall');
    $r=Game::snapshot($d)['base']['radio'];yc($r['unread']===1&&$r['items'][0]['category']==='news','Real construction creates one broadcast');yc(Game::snapshot($d)['base']['radio']['unread']===1,'Refreshing does not replay news');
    $b=BattleService::start($a,$d);$r=Game::publicBase($d,Game::loadBase($d))['radio'];yc($r['unread']===2&&$r['items'][0]['battle']===$b['id'],'Live attack links to its real report');
    yc(count($b['signs'])===1&&!isset(Game::visit($d,$other)['radio']),'Battle captures sign, private receiver stays private');
    BattleService::retreat($a,$b['id']);$read=Game::mutate($d,'radioRead',['through'=>1])['base']['radio'];yc($read['unread']===1,'Read cursor preserves newer arrivals');
    Game::mutate($d,'radioTune',['attacks'=>false,'news'=>false]);$copy=Game::loadBase($d);yc(!YardMessages::receive($copy,'attacks','test',time(),9),'Disabled category ignores future events');
    $removed=YardMessages::changeSign($d,['op'=>'remove']+$post);yc($removed['signs']===[]&&YardMessages::changeSign($a,$post)['signs']===[],'Host removes without author retry resurrecting it');
    yc(BattleService::poll($a,$b['id'])['signs'][0]['id']===$token,'Older battle retains historic sign');
    echo "Visitor signs and radio database: $checks checks passed.\n";
}finally{$db->rollBack();}
