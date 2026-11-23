<?php
declare(strict_types=1);

/** Local display preferences never change persisted identifiers or keeper-created content. */
final class GameLanguage
{
    public const NAMES=['en'=>'English','fr'=>'Français','de'=>'Deutsch','pt'=>'Português','es'=>'Español','tr'=>'Türkçe'];

    public static function code(): string
    {
        $value=$_COOKIE['yard_language']??'en';
        return is_string($value)&&isset(self::NAMES[$value])?$value:'en';
    }

    private static function data(): array
    {
        static $cache=[];
        $code=self::code();
        return $cache[$code]??=json_decode(file_get_contents(__DIR__.'/languages/'.$code.'.json'),true,512,JSON_THROW_ON_ERROR);
    }

    public static function shell(): array
    {
        return ['code'=>self::code(),'names'=>self::NAMES,'strings'=>self::data()['strings']];
    }

    public static function guide(): array
    {
        return ['language'=>self::code(),'entries'=>self::data()['guide']];
    }

    public static function displayCatalog(array $catalog): array
    {
        if(self::code()==='en'){return $catalog;}
        foreach(self::data()['labels'] as $group=>$items){
            foreach($items as $id=>$fields){
                if(isset($catalog[$group][$id])){$catalog[$group][$id]=array_replace($catalog[$group][$id],$fields);}
            }
        }
        return $catalog;
    }
}
