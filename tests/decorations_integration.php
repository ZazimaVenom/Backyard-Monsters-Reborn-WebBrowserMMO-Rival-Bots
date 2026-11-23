<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';

if (PHP_SAPI!=='cli' || !str_ends_with((string)getenv('MYSQL_TEST_DATABASE'),'_test')) { fwrite(STDERR,"Use a disposable MYSQL_TEST_DATABASE ending in _test.\n");exit(2); }
$config=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:'','game_speed'=>1];
$db=Db::connect($config);Db::useConnection($db,$config);$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));
$checks=0;
function dc(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);++$checks;}
function denied(callable $fn,string $why):void {try{$fn();}catch(RuntimeException $e){dc(str_contains($e->getMessage(),$why),$e->getMessage());return;}throw new RuntimeException('Accepted unexpected action: '.$why);}
function keeper():int {
    $db=Db::pdo();$db->prepare('INSERT INTO users(name,is_bot,created_at) VALUES(?,0,?)')->execute(['Decor '.bin2hex(random_bytes(4)),time()]);
    $id=(int)$db->lastInsertId();$base=Game::initialBase($id,false);$base['shiny']=300;$base['shield_until']=0;
    $db->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($base),time()]);return $id;
}
function purchase(int $item,float $x,float $y,string $currency='materials'):array {return ['item'=>$item,'x'=>$x,'y'=>$y,'currency'=>$currency,'receipt'=>bin2hex(random_bytes(16))];}
function spot(array $base,int $item):array {
    for($x=2;$x<30;$x+=.5)for($y=2;$y<30;$y+=.5){
        $copy=$base;$input=purchase($item,$x,$y);try{Decorations::mutate($copy,['wins'=>9],'decorationBuy',$input);return [$x,$y];}catch(RuntimeException $e){}
    }
    throw new RuntimeException('No feasible test placement.');
}
$db->beginTransaction();
try {
    $a=keeper();$d=keeper();$legacy=Game::loadBase($d);dc(!isset($legacy['decorations']),'Legacy save is unchanged on read');
    dc(count(Decorations::catalog())===84,'Complete source set');
    $yard=Game::snapshot($d)['base'];dc($yard['decorations']==[]&&$yard['decorationStorage']===[],'Empty owned collection');
    [$x,$y]=spot(Game::loadBase($d),28);$input=purchase(28,$x,$y);
    $after=Game::mutate($d,'decorationBuy',$input)['base'];$one=$after['decorations'][0];
    dc($after['resources']['twig']===$yard['resources']['twig']-600 && $after['resources']['pebble']===$yard['resources']['pebble']-300,'Exact material debit');
    dc($one['item']===28 && $one['x']==$x && $one['y']==$y && !isset($one['receipt']),'Safe snapped coordinates');
    $retry=Game::mutate($d,'decorationBuy',$input)['base'];dc($retry['decorations']==$after['decorations']&&$retry['resources']===$after['resources'],'Paid retry is idempotent');
    denied(fn()=>Game::mutate($d,'decorationBuy',purchase(29,$x,$y)),'occupied');
    $hall=$yard['buildings'][0];denied(fn()=>Game::mutate($d,'decorationBuy',purchase(28,$hall['x'],$hall['y'])),'building');
    denied(fn()=>Game::mutate($d,'decorationBuy',purchase(28,2.3,2)),'half-tile');
    denied(fn()=>Game::mutate($d,'decorationBuy',purchase(28,1,1)),'boundary');
    $base=Game::loadBase($d);$base['mushrooms']['items'][]=['id'=>999,'variant'=>1,'x'=>28,'y'=>28,'readyAt'=>0];Game::saveBase($d,$base);
    denied(fn()=>Game::mutate($d,'decorationBuy',purchase(28,28,28)),'occupied');
    denied(fn()=>Game::mutate($d,'build',['type'=>'wall','x'=>$x,'y'=>$y]),'decoration');
    [$x2,$y2]=spot($base,29);$second=Game::mutate($d,'decorationBuy',purchase(29,$x2,$y2,'shiny'))['base'];
    dc($second['shiny']===150&&$second['resources']===$after['resources'],'Shiny and materials use independent balances');
    [$x3,$y3]=spot(Game::loadBase($d),29);
    $moved=Game::mutate($d,'decorationMove',['id'=>$one['id'],'x'=>$x3,'y'=>$y3])['base'];
    dc($moved['decorations'][0]['x']==$x3&&$moved['shiny']===150,'Moving is free');
    $stored=Game::mutate($d,'decorationStore',['id'=>$one['id']])['base'];
    dc(count($stored['decorations'])===1&&$stored['decorationStorage'][0]['id']===$one['id'],'Return to storage retains instance');
    $scout=Game::visit($d,$a);dc(count($scout['decorations'])===1 && !isset($scout['decorationStorage'],$scout['shiny']),'Visitor sees placed art only');
    $p=['id'=>$one['id'],'x'=>$x,'y'=>$y];$placed=Game::mutate($d,'decorationPlace',$p)['base'];$repeat=Game::mutate($d,'decorationPlace',$p)['base'];
    dc($repeat['decorations']==$placed['decorations']&&$placed['resources']===$stored['resources']&&$placed['decorationStorage']===[],'Stored piece can be replaced and retried for free');
    denied(fn()=>Game::mutate($d,'decorationBuy',purchase(121,$x3,$y3)),'Earn');
    $db->prepare('UPDATE users SET wins=1 WHERE id=?')->execute([$d]);
    denied(fn()=>Game::mutate($d,'decorationBuy',purchase(121,$x3,$y3)),'Earn');
    $base=Game::loadBase($d,true);WildMonsterInvasions::award($base,1,1);Game::saveBase($d,$base);
    $totem=end($base['decorations']);$earned=Game::mutate($d,'decorationPlace',['id'=>$totem['id'],'x'=>$x3,'y'=>$y3])['base'];
    dc(count($earned['decorations'])===3&&$earned['shiny']===150,'Earned first victory totem');
    denied(fn()=>Game::mutate($d,'decorationBuy',purchase(121,20,20)),'maximum');
    $battle=BattleService::start($a,$d);dc(count($battle['decorations'])===3&&count($battle['buildings'])<=count($yard['buildings']),'Battle freezes decoration geometry outside combat model');
    denied(fn()=>Game::mutate($d,'decorationStore',['id'=>$one['id']]),'battle');
    BattleService::retreat($a,$battle['id']);$fixed=Game::mutate($d,'repairBase',[])['base'];
    dc($fixed['decorations']==$earned['decorations'],'Settlement and free base repair preserve pieces');
    $old=$battle['decorations'];Game::mutate($d,'decorationStore',['id'=>$one['id']]);dc(BattleService::poll($a,$battle['id'])['decorations']==$old,'Report retains frozen arrangement');
    echo "Decoration database: $checks checks passed.\n";
} finally {$db->rollBack();}
