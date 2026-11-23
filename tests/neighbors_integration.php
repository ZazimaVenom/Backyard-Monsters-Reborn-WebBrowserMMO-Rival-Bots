<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || !str_ends_with((string)getenv('MYSQL_TEST_DATABASE'),'_neighbors_test')) {
    fwrite(STDERR,"Select an empty disposable MYSQL_TEST_DATABASE ending in _neighbors_test.\n");
    exit(2);
}
require __DIR__.'/../app/bootstrap.php';
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),
    'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:''];
$pdo=Db::connect($config);
Db::useConnection($pdo,$config);
$pdo->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0) { throw new RuntimeException('Use an empty test world.'); }
$count=0;
function checkNeighbor(bool $ok,string $why):void {
    global $count;
    if (!$ok) { throw new LogicException($why); }
    $count++;
}
function rejectNeighbor(callable $method,string $why):void {
    try { $method(); } catch (RuntimeException $error) { checkNeighbor(str_contains($error->getMessage(),$why),$error->getMessage());return; }
    throw new LogicException('Expected refusal: '.$why);
}
$pdo->beginTransaction();
try {
    foreach (['Amber Scout','Bramble Scout','Copper Scout','Bot Scout'] as $i=>$name) {
        $pdo->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,?,?)')->execute([$name,(int)($i===3),time()]);
        $ids[]=$id=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode(Game::initialBase($id,$i===3)),time()]);
    }
    [$amy,$bob,$cam,$bot]=$ids;
    $act=static fn(int $me,string $kind,int $other,array $more=[])=>Neighbors::act($me,['kind'=>$kind,'keeper'=>$other]+$more);
    checkNeighbor(count(Neighbors::overview($amy,'Sco')['matches'])===2,'Search omits self and bot users.');
    rejectNeighbor(fn()=>$act($amy,'request',$bot),'player keeper');
    rejectNeighbor(fn()=>$act($amy,'sendGift',$bob),'Become friends');
    $act($amy,'request',$bob);$act($amy,'request',$bob);
    $link=Neighbors::overview($bob)['friends'][0]['link_id'];
    rejectNeighbor(fn()=>$act($amy,'accept',$bob,['link'=>$link]),'incoming invitation');
    $act($bob,'request',$amy);
    checkNeighbor((int)Neighbors::overview($amy)['friends'][0]['accepted']===1,'Crossed requests create one friendship.');
    $act($amy,'sendGift',$bob);
    rejectNeighbor(fn()=>$act($amy,'sendGift',$bob),'24 hours');
    $gift=Neighbors::overview($bob)['gifts'][0];
    checkNeighbor(!array_key_exists('resource',$gift),'Unopened sack hides its draw.');
    rejectNeighbor(fn()=>Neighbors::act($cam,['kind'=>'openGift','gift'=>$gift['id']]),'unavailable');
    $bank=Game::loadBase($bob);$cap=Game::capacity($bank);$bank['resources']=array_fill_keys(['twig','pebble','putty','goo'],$cap-3);
    Game::saveBase($bob,$bank);
    $open=fn()=>Neighbors::act($bob,['kind'=>'openGift','gift'=>$gift['id']]);
    $first=$open();
    checkNeighbor($first['credited']===3 && $first['remaining']>0,'Full storage preserves unpaid gift balance.');
    checkNeighbor($open()['credited']===0,'Retry while full issues no excess currency.');
    $bank=Game::loadBase($bob);$bank['resources'][$first['resource']]=0;Game::saveBase($bob,$bank);
    checkNeighbor($open()['credited']===$first['remaining'] && $open()['credited']===0,'Remainder is credited exactly once.');
    checkNeighbor(Neighbors::overview($bob)['gifts']===[],'Collected gift disappears.');
    $message=['token'=>str_repeat('a',32),'body'=>'Meet at the Map Room.'];
    $act($amy,'sendMail',$bob,$message);$act($amy,'sendMail',$bob,$message);
    $thread=Neighbors::thread($bob,$amy,null)['messages'];
    checkNeighbor(count($thread)===1 && $thread[0]['body']===$message['body'],'Mail retry is idempotent.');
    rejectNeighbor(fn()=>$act($amy,'sendMail',$bob,array_merge($message,['body'=>'different'])),'message changed');
    checkNeighbor(Neighbors::thread($cam,$amy,null)['messages']===[],'Another keeper cannot see private mail.');
    checkNeighbor((int)Neighbors::overview($bob)['threads'][0]['unread']===1,'Unread mail is marked for recipient.');
    $act($bob,'read',$amy,['through'=>$thread[0]['id']]);
    checkNeighbor((int)Neighbors::overview($bob)['threads'][0]['unread']===0,'Opened messages update the counter.');
    for($i=1;$i<=31;$i++){$act($amy,'sendMail',$bob,['token'=>sprintf('%032x',$i),'body'=>'Page '.$i]);}
    $page=Neighbors::thread($bob,$amy,null);
    $older=Neighbors::thread($bob,$amy,(int)$page['messages'][0]['id']);
    checkNeighbor($page['older'] && count($page['messages'])===30 && count($older['messages'])===2 && !$older['older'],'Conversation pages preserve all messages.');
    $act($bob,'remove',$amy,['link'=>$link]);
    rejectNeighbor(fn()=>$act($amy,'sendMail',$bob,$message),'Become friends');
    $act($amy,'request',$bob);$new=Neighbors::overview($bob)['friends'][0];
    $act($bob,'remove',$amy,['link'=>$link]);
    $act($bob,'accept',$amy,['link'=>$new['link_id']]);
    rejectNeighbor(fn()=>$act($amy,'sendGift',$bob),'24 hours');
    checkNeighbor(count(Neighbors::overview($bob)['friends'])===1,'A delayed removal cannot erase a later invitation.');
    echo "Neighbor integration: $count checks passed.\n";
}finally{$pdo->rollBack();}
