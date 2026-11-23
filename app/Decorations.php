<?php
declare(strict_types=1);

/** Cosmetics live outside the combat/building model. Storage keeps each purchased instance. */
final class Decorations
{
    public static function catalog(): array
    {
        static $items;
        return $items ??= json_decode(file_get_contents(__DIR__.'/decoration-items.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function visible(array $base): array
    {
        $placed = [];
        foreach ($base['decorations'] ?? [] as $piece) {
            if (isset($piece['x'], $piece['y'])) {
                $placed[] = array_intersect_key($piece,array_flip(['id','item','x','y','tier']));
            }
        }
        return $placed;
    }

    public static function storage(array $base): array
    {
        $stored = [];
        foreach ($base['decorations'] ?? [] as $piece) {
            if (!isset($piece['x'], $piece['y'])) { $stored[] = array_intersect_key($piece,array_flip(['id','item','tier'])); }
        }
        return $stored;
    }

    public static function overlaps(array $base, float $x, float $y, float $size, ?int $ignoreId=null): bool
    {
        $items = self::catalog();
        foreach ($base['decorations'] ?? [] as $piece) {
            if (!isset($piece['x'], $piece['y']) || $piece['id'] === $ignoreId) { continue; }
            $gap = ($size + $items[$piece['item']]['size']) / 2 + .15;
            if (abs($x-$piece['x']) < $gap-1e-9 && abs($y-$piece['y']) < $gap-1e-9) { return true; }
        }
        return (class_exists('YardMessages') && YardMessages::overlaps($base,$x,$y,$size))
            || (class_exists('Seasonal') && Seasonal::overlaps($base,$x,$y,$size));
    }

    public static function coordinates(array $base, array $input, float $size, ?int $ignore): array
    {
        foreach (['x','y'] as $axis) {
            $value = $input[$axis] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite($value) || floor($value*2) != $value*2) {
                throw new RuntimeException('Choose a half-tile location in your backyard.');
            }
        }
        $x=$input['x']; $y=$input['y'];
        if(!YardSpace::contains($base,$x,$y,1+$size/2-1e-9))throw new RuntimeException('Choose a place inside the yard boundary.');
        $buildings=Catalog::buildings();
        foreach ($base['buildings'] as $b) {
            $gap=($size+$buildings[$b['type']]['size'])/2+.15;
            if (abs($x-$b['x'])<$gap-1e-9 && abs($y-$b['y'])<$gap-1e-9) { throw new RuntimeException('A building already occupies that space.'); }
        }
        if (self::overlaps($base,$x,$y,$size,$ignore) || (class_exists('Mushrooms') && Mushrooms::overlaps($base,$x,$y,$size))) {
            throw new RuntimeException('That spot is already occupied. Choose open ground.');
        }
        return [$x,$y];
    }

    public static function unlocks(array $base, array $owner): array
    {
        $owned=array_column($base['decorations']??[],'item');
        return [121=>in_array(121,$owned,true), 131=>in_array(131,$owned,true), 135=>in_array('dave',$base['unlocked']??[],true)];
    }

    public static function mutate(array &$base, array $owner, string $action, array $input): void
    {
        $items = self::catalog();
        $pieces = $base['decorations'] ?? [];
        if ($action !== 'decorationBuy') {
            $id=$input['id']??null;
            if (!is_int($id) || $id<=0) { throw new RuntimeException('Choose a decoration from your own yard.'); }
            foreach ($pieces as $index=>$piece) {
                if ($piece['id']!==$id) { continue; }
                if ($action==='decorationStore') { unset($piece['x'],$piece['y']); }
                elseif ($action==='decorationMove' || $action==='decorationPlace') {
                    if ($action==='decorationMove' && !isset($piece['x'])) { throw new RuntimeException('This decoration has returned to storage.'); }
                    if ($action==='decorationPlace' && isset($piece['x'])) {
                        if ($piece['x']==($input['x']??null) && $piece['y']==($input['y']??null)) { return; }
                        throw new RuntimeException('This decoration has already been placed.');
                    }
                    [$piece['x'],$piece['y']]=self::coordinates($base,$input,$items[$piece['item']]['size'],$id);
                } else { throw new RuntimeException('Unknown decoration action.'); }
                $base['decorations'][$index]=$piece;
                return;
            }
            throw new RuntimeException('This decoration is no longer available.');
        }

        $item=$input['item']??null; $receipt=$input['receipt']??null; $currency=$input['currency']??null;
        if (!is_int($item) || !isset($items[$item]) || !is_string($receipt) || !preg_match('/^[a-z0-9-]{20,64}$/D',$receipt)) {
            throw new RuntimeException('Choose a decoration from the yard shop.');
        }
        foreach ($pieces as $piece) {
            if ($piece['receipt']!==$receipt) { continue; }
            if ($piece['item']!==$item) { throw new RuntimeException('This purchase reference has already been used.'); }
            return;
        }
        $spec=$items[$item]; $unlocks=self::unlocks($base,$owner);
        if (isset($unlocks[$item]) && !$unlocks[$item]) { throw new RuntimeException('Earn this keepsake before placing it.'); }
        $limit=$spec['limit']?: (isset($unlocks[$item])?1:0);
        if ($limit && count(array_filter($pieces,fn($p)=>$p['item']===$item)) >= $limit) { throw new RuntimeException('You already own the maximum of this decoration. Check your storage.'); }
        [$x,$y]=self::coordinates($base,$input,$spec['size'],null);
        if ($spec['shiny']>0) {
            if ($currency==='shiny') {
                if (($base['shiny']??0)<$spec['shiny']) { throw new RuntimeException('You need more Shiny for this decoration.'); }
                $base['shiny']-=$spec['shiny'];
            } elseif ($currency==='materials') {
                foreach ($spec['materials'] as $resource=>$amount) {
                    if (($base['resources'][$resource]??0)<$amount) { throw new RuntimeException('You need more '.ucfirst($resource).' for this decoration.'); }
                }
                foreach ($spec['materials'] as $resource=>$amount) { $base['resources'][$resource]-=$amount; }
            } else { throw new RuntimeException('Select a payment method.'); }
        }
        $next=$pieces?max(array_column($pieces,'id'))+1:1;
        $base['decorations'][]=['id'=>$next,'item'=>$item,'x'=>$x,'y'=>$y,'receipt'=>$receipt];
    }
}
