<?php
declare(strict_types=1);
// Local launch validation only. This file is outside the HTTP document root.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$mode=$argv[1]??'';
$port=filter_var($argv[2]??'8080',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
if(!in_array($mode,['port-free','ready'],true)||$port===false){fwrite(STDERR,"Usage: php bin/local-check.php port-free|ready [port]\n");exit(2);}
$address='127.0.0.1';
if($mode==='port-free'){
    $socket=@stream_socket_server('tcp://'.$address.':'.$port,$errorNumber,$errorText,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
    if($socket===false){fwrite(STDERR,"Cannot start the local server on $address:$port. The port is occupied or unavailable. Close the existing server or other application using this port, then try again.\n");exit(1);}
    fclose($socket);exit(0);
}
$deadline=microtime(true)+8;
while(microtime(true)<$deadline){
    $connection=@stream_socket_client('tcp://'.$address.':'.$port,$errorNumber,$errorText,.30);
    if($connection!==false){
        stream_set_timeout($connection,1);
        fwrite($connection,"GET /index.php HTTP/1.1\r\nHost: $address:$port\r\nConnection: close\r\n\r\n");
        $response=stream_get_contents($connection,131072);fclose($connection);
        if(is_string($response)){
            $headers=explode("\r\n\r\n",$response,2)[0];
            $installer=preg_match('~^HTTP/1\.[01] 302\b~',$headers)&&preg_match('~^Location:\s*setup\.php\s*$~im',$headers);
            $game=preg_match('~^HTTP/1\.[01] 200\b~',$headers)&&str_contains($response,'<title>Backyard Monsters')&&str_contains($response,'assets/game.css');
            if($installer||$game){echo "Local game server is ready at http://$address:$port/\n";exit(0);}
        }
    }
    usleep(150000);
}
fwrite(STDERR,"The local game server did not become ready. Check the PHP server window for errors; no browser or world worker was started by this launcher.\n");exit(1);
