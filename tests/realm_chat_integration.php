<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!str_ends_with((string)getenv('MYSQL_TEST_DATABASE'),'_test'))exit(2);
require_once __DIR__.'/../app/bootstrap.php';
$c=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:''];
$db=Db::connect($c);Db::useConnection($db,$c);$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));if((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn())throw new RuntimeException('Needs an empty disposable schema.');
$n=0;
function checkChat(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);++$n;}
function rejectChat(callable $work):void{try{$work();}catch(RuntimeException){checkChat(true,'Rejected');return;}throw new RuntimeException('Expected rejection');}
$db->beginTransaction();try{
 foreach(['Echo','Moss'] as $name){
  $db->prepare('INSERT INTO users(name,created_at) VALUES(?,0)')->execute([$name]);$id=(int)$db->lastInsertId();
  $db->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,0)')->execute([$id,json_encode(Game::initialBase($id))]);World::assignHome($id);$ids[]=$id;
 }
 [$a,$b]=$ids;$token=bin2hex(random_bytes(16));$send=['room'=>'mr1','op'=>'send','body'=>'Open <gate> now','token'=>$token];
 checkChat(RealmChat::view($a)['room']==='mr1','Starter channel');
 RealmChat::act($a,$send);RealmChat::act($a,$send);
 checkChat(count(RealmChat::view($b)['messages'])===1&&RealmChat::view($b)['messages'][0]['body']==='Open <gate> now','Real sender and replay');
 rejectChat(fn()=>RealmChat::act($a,array_replace($send,['body'=>'Other'])));
 rejectChat(fn()=>RealmChat::act($a,array_replace($send,['token'=>bin2hex(random_bytes(16))])));
 rejectChat(fn()=>RealmChat::act($a,array_replace($send,['room'=>'inferno'])));
 $mute=['op'=>'mute','room'=>'mr1','peer'=>$a];RealmChat::act($b,$mute);
 checkChat(RealmChat::view($b)['messages']===[]&&count(RealmChat::view($a)['messages'])===1,'Per-keeper mute');RealmChat::act($b,array_replace($mute,['op'=>'unmute']));
 checkChat(count(RealmChat::view($b)['messages'])===1,'Unmute restores visible messages');
 $yard=Game::loadBase($b,true);$yard['infernoRealm']=InfernoRealm::initial(time());Game::saveBase($b,$yard);
 $r=RealmChat::act($b,array_replace($send,['room'=>'inferno','body'=>'Cavern','token'=>bin2hex(random_bytes(16))]));
 checkChat($r['room']==='inferno'&&count($r['messages'])===1&&count(RealmChat::view($b,'mr1')['messages'])===1,'Surface and Inferno membership');
 $q=$db->prepare('UPDATE settings SET value=? WHERE name=?');$history=['at'=>time()-2592000,'messages'=>[['id'=>1,'sender'=>$a,'name'=>'Echo','body'=>'Old','at'=>1]],'sequence'=>1];
 $q->execute([json_encode($history),'chat:room:mr1']);checkChat(RealmChat::view($a)['messages']===[],'Thirty-day idle expiration');
 for($i=0;$i<100;$i++)$history['messages'][$i]=['id'=>$i+1,'sender'=>$a,'name'=>'Echo','body'=>(string)$i,'at'=>time()];$history['at']=time();$history['sequence']=100;$q->execute([json_encode($history),'chat:room:mr1']);
 $q->execute(['{}','chat:keeper:'.$a]);$recent=RealmChat::act($a,array_replace($send,['token'=>bin2hex(random_bytes(16))]));
 checkChat(count($recent['messages'])===100&&$recent['messages'][0]['id']===2&&end($recent['messages'])['id']===101,'Bounded history');
 checkChat(RealmChat::text('A  word')==='A word'&&RealmChat::text('shit')==='****','Recovered word filtering');
 rejectChat(fn()=>RealmChat::text(str_repeat('🧡',201)));
 checkChat(RealmChat::text(str_repeat('龍',200))===str_repeat('龍',200),'200 Unicode characters');
 echo "Global chat persistence: $n checks passed.\n";
}finally{$db->rollBack();}
