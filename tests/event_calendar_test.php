<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$n=0;
function verifyDate(bool $yes,string $why):void{global $n;if(!$yes)throw new RuntimeException($why);$n++;}
function calendarRow(string $key,string $start,int $now):array{
    foreach(EventCalendar::dates($now) as $r)if($r['key']===$key&&$r['start']===strtotime($start.' UTC'))return $r;
    throw new RuntimeException('Missing '.$key.' '.$start);
}
foreach([2026,2027,2028] as $year){
 foreach([1=>'wmi1',3=>'wmi2',5=>'wmi1',7=>'wmi2',9=>'wmi1',11=>'wmi2'] as $month=>$key){
    $s=sprintf('%04d-%02d-10',$year,$month);$begin=strtotime($s.' UTC');
    foreach([[-7*86400-1,'upcoming'],[-7*86400,'countdown'],[-1,'countdown'],[0,'active'],[7*86400-1,'active'],[7*86400,'finished']] as [$delta,$phase]){
      verifyDate(calendarRow($key,$s,$begin+$delta)['phase']===$phase,'Wrong WMI boundary.');
    }
    $s=sprintf('%04d-%02d-24',$year,$month);$begin=strtotime($s.' UTC');
    foreach([[0,'active'],[5*86400,'closing'],[7*86400,'finished']] as [$delta,$phase])verifyDate(calendarRow('brukkarg',$s,$begin+$delta)['phase']===$phase,'Wrong Brukkarg closeout.');
 }
}
foreach(['2026-12-28','2028-02-28'] as $s){
 $b=strtotime($s.' UTC');foreach(['blitz','carnage'] as $k)foreach([[-86400,'countdown'],[0,'active'],[518399,'active'],[518400,'finished']] as [$delta,$phase])verifyDate(calendarRow($k,$s,$b+$delta)['phase']===$phase,'Weekly round error.');
}
echo "World event calendar: $n source date checks passed.\n";
