<?php
declare(strict_types=1);
require_once __DIR__.'/../app/local-request.php';
$cases=['127.0.0.1'=>true,'127.0.0.2'=>true,'::1'=>true,'0:0:0:0:0:0:0:1'=>true,'::ffff:127.0.0.1'=>true,'::ffff:7f00:1'=>true,
    '::ffff:192.168.0.3'=>false,'192.168.1.1'=>false,'::'=>false,'0.0.0.0'=>false,'localhost'=>false,''=>false];
foreach($cases as $peer=>$expected){if(backyardLocalPeer($peer)!==$expected){throw new RuntimeException('Unexpected socket classification');}}
echo count($cases)." setup socket checks passed.\n";
