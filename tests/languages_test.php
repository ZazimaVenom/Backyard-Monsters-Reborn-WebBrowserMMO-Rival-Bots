<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
$n=0;function localeCheck(bool $pass,string $why):void{global $n;if(!$pass)throw new RuntimeException($why);$n++;}
foreach(array_keys(GameLanguage::NAMES) as $code){
 $_COOKIE['yard_language']=$code;
 localeCheck(GameLanguage::code()===$code,'Locale');$guide=GameLanguage::guide();$ui=GameLanguage::shell();
 localeCheck(count($ui['strings'])>=100&&$guide['language']===$code,'Language dispatch');
 $c=Catalog::all();$local=GameLanguage::displayCatalog($c);
 localeCheck(array_keys($c['buildings'])===array_keys($local['buildings'])&&$c['monsters']['pokey']['damage']===$local['monsters']['pokey']['damage'],'Stable game rules');
 $ids=array_column($guide['entries'],'id');localeCheck(count($ids)===count(array_unique($ids)),'Unique entries');
 $groups=array_count_values(array_column($guide['entries'],'section'));localeCheck($groups['Bestiary']===32&&$groups['Decorations']===84&&$groups['Training manual']===7,'Playable catalog coverage');
 foreach($guide['entries'] as $row){
   localeCheck($row['name']!==''&&!preg_match('/#[\w.]+#|<\/?[a-z][^>]*>/i',$row['name'].' '.$row['description']),'Plain source text');
   localeCheck(is_file(__DIR__.'/../public/assets/'.$row['image']),'Guide artwork '.$row['image']);
 }
}
$_COOKIE['yard_language']=['fr'];localeCheck(GameLanguage::code()==='en','Invalid preference');
$_COOKIE['yard_language']='../en';localeCheck(GameLanguage::code()==='en','Unknown preference');
echo "Localized field guide: $n checks passed.\n";
