<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!str_ends_with((string)getenv('MYSQL_TEST_DATABASE'),'_test'))exit(2);
require_once __DIR__.'/../app/bootstrap.php';
$c=['host'=>getenv('MYSQL_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('MYSQL_TEST_PORT')?:3306),'database'=>getenv('MYSQL_TEST_DATABASE'),'username'=>getenv('MYSQL_TEST_USER')?:'root','password'=>getenv('MYSQL_TEST_PASSWORD')?:''];
$db=Db::connect($c);Db::useConnection($db,$c);$db->exec(file_get_contents(__DIR__.'/../app/schema.sql'));if((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn())throw new RuntimeException('Needs an empty disposable schema.');
$n=0;
function demandCalendar(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;}
function rejectCalendar(callable $action):void{try{$action();}catch(RuntimeException){demandCalendar(true,'Rejected');return;}throw new RuntimeException('Request should have failed');}
$db->beginTransaction();try{
 $begin=strtotime('2026-11-16 UTC');$now=$begin+600;
 $db->exec("INSERT INTO users(name,created_at) VALUES('Festival Keeper',0)");$id=(int)$db->lastInsertId();
 $yard=Game::initialBase($id);$yard['blitzCleared']=4;$yard['blitzDeadline']=$now+100;$yard['wmi'][2]=['run'=>5,'deadline'=>$now+400,'cleared'=>3];
 foreach($yard['buildings'] as &$b)if($b['type']==='townhall')$b['level']=3;unset($b);
 $db->prepare('INSERT INTO bases(user_id,state_json,updated_at) VALUES(?,?,?)')->execute([$id,json_encode($yard),$now]);World::assignHome($id);
 $join=['op'=>'join','key'=>'blitz','start'=>$begin,'revision'=>0];
 rejectCalendar(fn()=>EventCalendar::act($id,$join,$begin-1));
 EventCalendar::act($id,$join,$now);EventCalendar::act($id,$join,$now);
 $yard=Game::loadBase($id,true);demandCalendar($yard['blitzCleared']===0&&$yard['blitzDeadline']===$begin+518400,'Official time window');
 demandCalendar($yard['calendarRounds']['blitz']['personal']['blitzCleared']===4,'Private progress preserved');
 $s=MonsterBlitz::start($yard,['id'=>$id,'name'=>'Festival Keeper'],1,$now,0,[]);
 $s['status']='finished';$s['result']=['outcome'=>'held'];MonsterBlitz::settle($yard,$s);EventCalendar::record($db,$id,$yard,$now);
 EventCalendar::record($db,$id,$yard,$now);Game::saveBase($id,$yard);
 $scores=EventCalendar::leaders($id,'blitz',$begin);demandCalendar($scores['score']===1&&count($scores['leaders'])===1&&(int)$scores['leaders'][0]['score']===1,'Actual defense outcome counted once');
 $leave=['op'=>'leave','key'=>'blitz','start'=>$begin,'revision'=>1];EventCalendar::act($id,$leave,$now);
 $yard=Game::loadBase($id);demandCalendar($yard['blitzCleared']===4&&$yard['blitzDeadline']===$now+100,'Personal campaign restored');
 rejectCalendar(fn()=>EventCalendar::act($id,$join,$now));
 $join['revision']=2;EventCalendar::act($id,$join,$now);$yard=Game::loadBase($id);demandCalendar($yard['blitzCleared']===1,'Shared score and stage resumed');rejectCalendar(fn()=>EventCalendar::checkStart($yard,'blitz',$begin+518400));
 $end=$begin+518400;$yard['blitzCleared']=2;EventCalendar::record($db,$id,$yard,$end);
 demandCalendar(EventCalendar::leaders($id,'blitz',$begin)['score']===1,'Late clear cannot raise final score');
 $wstart=strtotime('2026-11-10 UTC');EventCalendar::act($id,['op'=>'join','key'=>'wmi2','start'=>$wstart,'revision'=>0],$now);
 $yard=Game::loadBase($id,true);demandCalendar($yard['wmi'][2]['run']===$wstart&&$yard['blitzCleared']===1,'Overlapping weekly and monthly campaigns');
 $yard['baiterPractice']=['state'=>['status'=>'active']];Game::saveBase($id,$yard);
 rejectCalendar(fn()=>EventCalendar::act($id,['op'=>'leave','key'=>'wmi2','start'=>$wstart,'revision'=>1],$now));
 demandCalendar((int)$db->query('SELECT rank_points FROM users LIMIT 1')->fetchColumn()===0,'Round points independent of ranked PvP');
 echo "World calendar persistence: $n checks passed.\n";
}finally{$db->rollBack();}
