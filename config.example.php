<?php
// Reference only: for a NEW world, public/setup.php creates private var/config.php.
// For an existing world, preserve var/config.php and run the updater; do not rerun setup.
return [
    'host'=>'127.0.0.1','port'=>3306,'database'=>'backyard_rehatched',
    // Default local XAMPP: root with no password, unless you changed it.
    'username'=>'root','password'=>'','socket'=>'',
    'game_speed'=>1,'bot_count'=>1000,
    'player_grace_seconds'=>1800,
    // Signed-in browser pulses activate bots automatically; a CLI worker is optional.
    // Bot clocks pause when no humans are online; lost connections expire after 45 seconds.
    // Turn intervals count active world time. Browser and CLI share the same gate.
    'bots'=>['interval_min'=>60,'interval_max'=>300],
    'installed_at'=>0,
];
