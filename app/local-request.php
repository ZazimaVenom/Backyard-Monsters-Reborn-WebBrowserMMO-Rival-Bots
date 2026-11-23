<?php
declare(strict_types=1);

/** Check the socket peer, including Apache's IPv4-mapped IPv6 representation on dual-stack hosts. */
function backyardLocalPeer(string $address): bool
{
    $binary=inet_pton($address);
    if($binary===false){return false;}
    if(strlen($binary)===4){return ord($binary[0])===127;}
    if($binary===inet_pton('::1')){return true;}
    return substr($binary,0,12)===str_repeat("\0",10)."\xff\xff" && ord($binary[12])===127;
}
