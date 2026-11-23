<?php
declare(strict_types=1);

/** Tile coordinates keep the original center at (16,16) as More Yardage opens new land on all four sides. */
final class YardSpace
{
    public const STANDARD = 32;
    public static function extent(array $yard):int
    {
        if(isset($yard['yardExtent']))return max(self::STANDARD,min(58,(int)$yard['yardExtent']));
        $tier=min(6,max(0,(int)($yard['yardageLevel']??0)));
        return $tier===0?self::STANDARD:2*(int)ceil(16*pow(1.1,$tier));
    }
    public static function margin(array $yard):int { return intdiv(self::extent($yard)-self::STANDARD,2); }

    public static function contains(array $yard,float $x,float $y,float $inset):bool
    {
        $margin=self::margin($yard);
        return is_finite($x) && is_finite($y) && min($x,$y)>=$inset-$margin && max($x,$y)<=self::STANDARD+$margin-$inset;
    }
}
