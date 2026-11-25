<?php
declare(strict_types=1);
require_once __DIR__.'/../app/local-request.php';
if(!backyardLocalPeer($_SERVER['REMOTE_ADDR']??'')){
    http_response_code(403);header('Content-Type: text/plain; charset=utf-8');
    exit('Setup is available only on the server computer through localhost. Forwarded client headers are not accepted.');
}
if(version_compare(PHP_VERSION,'8.2.0','<')){
    http_response_code(503);header('Content-Type: text/plain; charset=utf-8');
    exit('Backyard Monsters Rehatched requires PHP 8.2 or newer. Apache is currently running PHP '.PHP_VERSION.'. Start the Apache service from your PHP 8.2+ XAMPP installation, then reload this page.');
}
require_once __DIR__.'/../app/Installer.php';
$installed=Game::configured();$error=null;$connection=null;$success=false;
$checks=['PHP 8.2 or newer'=>version_compare(PHP_VERSION,'8.2.0','>='),'PDO MySQL extension'=>extension_loaded('pdo_mysql'),'Writable private var folder'=>is_writable(__DIR__.'/../var')];
$values=['host'=>'127.0.0.1','port'=>'3306','database'=>'backyard_rehatched','username'=>'root'];
$createDatabase=true;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&!$installed){
    foreach($values as $key=>$default){if(isset($_POST[$key])&&is_string($_POST[$key])){$values[$key]=substr(trim($_POST[$key]),0,100);}}
    $createDatabase=isset($_POST['create_database']);
    try{
        if(!hash_equals($_SESSION['csrf']??'',is_string($_POST['csrf']??null)?$_POST['csrf']:'')){throw new SetupValidationException('The setup session expired. Refresh this page and try again.');}
        if(in_array(false,$checks,true)){throw new SetupValidationException('Complete the environment checks before connecting to the database.');}
        $port=filter_var($values['port'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        if($port===false){throw new SetupValidationException('Enter a database port between 1 and 65535.');}
        $config=['host'=>$values['host'],'port'=>$port,'database'=>$values['database'],'username'=>$values['username'],'password'=>is_string($_POST['db_password']??null)?$_POST['db_password']:'','create_database'=>$createDatabase];
        $intent=is_string($_POST['intent']??null)?$_POST['intent']:'install';
        if($intent==='test'){$connection=Installer::probe($config);}
        elseif($intent==='install'){@set_time_limit(180);Installer::install($config);$installed=true;$success=true;}
        else{throw new SetupValidationException('Choose Test database connection or Install world.');}
    }catch(Throwable $exception){
        $error=Installer::diagnostic($exception);
        $driver=$exception instanceof PDOException?' (database driver code '.(int)($exception->errorInfo[1]??0).')':'';
        error_log('Backyard setup: '.$error['code'].' — '.$error['title'].$driver);
    }
}
function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Local setup · Backyard Monsters Rehatched</title><link rel="stylesheet" href="assets/setup.css?v=0.13.1"></head>
<body><main class="setup"><div class="eyebrow">BACKYARD MONSTERS / REHATCHED</div>
<h1><?= $installed?'Your world is ready.':'Set up your<br>local world.' ?></h1>
<p class="intro">Database installation and 1,000 persistent bots. No player account is created during setup.</p>
<?php if($installed): ?>
<section class="panel success"><span class="symbol">✓</span><h2><?= $success?'World installed successfully':'This world is already installed' ?></h2>
<p><?= $success?'Created the game tables and 1,000 persistent bots, all starting at 0 rank points.':'Your saved world is preserved. Setup is locked and will not reset it.' ?></p>
<p>Open the game to register or sign in. Player accounts are managed on the game’s login screen.</p>
<a class="primary" href="index.php">Open the game <span>→</span></a>
<div class="worker-note"><h2>Your rivals wake up when you play</h2><p>Leave Apache and MySQL running in XAMPP, enter the game and sign in. All 1,000 persistent bots run automatically while a player has the game open. No separate worker window is required.</p><p class="muted">When the last player leaves, bot activity pauses until someone returns. A disconnected tab expires after 45 seconds.</p></div></section>
<?php else: ?>
<div class="checks"><?php foreach($checks as $label=>$ok): ?><div class="<?= $ok?'pass':'fail' ?>"><span><?= $ok?'✓':'!' ?></span><?= e($label) ?></div><?php endforeach ?></div>
<?php if($error): ?><div class="error" role="alert"><strong><?= e($error['title']) ?></strong><p><?= e($error['message']) ?></p><small>Setup code: <?= e((string)$error['code']) ?></small></div><?php endif ?>
<?php if($connection): ?><div class="connection-result" role="status"><strong>Database connection succeeded</strong><p><?= e($connection['server_family'].' '.$connection['server_version']) ?></p><p><?= e($connection['message']) ?></p><small>This test did not create a database, tables, accounts or configuration. If your database has a password, enter it again before installing.</small></div><?php endif ?>
<form method="post" class="panel"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<h2>Connect your database</h2>
<div class="xampp-note"><strong>Using XAMPP?</strong><p>Start <b>Apache</b> and <b>MySQL</b> in the XAMPP Control Panel. A default local XAMPP installation uses database user <b>root</b> with an <b>empty database password</b>. Use your own database credentials if you changed them.</p></div>
<p class="muted">Supports MySQL 8+ and MariaDB 10.4+, including the MariaDB version bundled with XAMPP.</p>
<div class="form-grid">
<label>Host<input name="host" value="<?= e($values['host']) ?>" required maxlength="100" autocomplete="off"></label>
<label>Port<input name="port" value="<?= e($values['port']) ?>" type="number" min="1" max="65535" required><small>Use the MySQL port shown in XAMPP.</small></label>
<label>Database name<input name="database" value="<?= e($values['database']) ?>" pattern="[a-zA-Z0-9_]+" maxlength="64" required></label>
<label>Database user<input name="username" value="<?= e($values['username']) ?>" required maxlength="100" autocomplete="off"><small>XAMPP default: root</small></label>
<label class="wide">Database password<input type="password" name="db_password" autocomplete="off" placeholder="Leave empty for an unchanged local XAMPP installation"><small>This is the database password, not a player password. It is never filled back into this page.</small></label>
</div>
<label class="checkbox"><input type="checkbox" name="create_database" <?= $createDatabase?'checked':'' ?>>Create the database if it does not exist</label>
<div class="world-spec"><div><strong>1,000</strong><span>persistent bots</span></div><div><strong>0</strong><span>starting rank points</span></div><div><strong>No account</strong><span>required for setup</span></div></div>
<div class="setup-actions"><button class="secondary" type="submit" name="intent" value="test" <?= in_array(false,$checks,true)?'disabled':'' ?>>Test database connection</button><button class="primary" type="submit" name="intent" value="install" <?= in_array(false,$checks,true)?'disabled':'' ?>>Install world <span>→</span></button></div>
<p class="muted fine">Local installation only. Existing populated databases are never overwritten. After installation, setup locks and the game provides its own registration and login screen.</p>
</form><?php endif ?>
<footer>PHP 8.2+ · MySQL / MariaDB · XAMPP local setup · 0.13.1</footer>
</main></body></html>
