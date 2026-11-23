<?php
declare(strict_types=1);

ini_set('display_errors',PHP_SAPI==='cli'?'stderr':'0');
error_reporting(E_ALL);
date_default_timezone_set('UTC');
require_once __DIR__.'/Db.php';
require_once __DIR__.'/Catalog.php';
require_once __DIR__.'/Game.php';
require_once __DIR__.'/World.php';
if (is_file(__DIR__.'/BattleService.php')) { require_once __DIR__.'/BattleService.php'; }
if (is_file(__DIR__.'/Bots.php')) { require_once __DIR__.'/Bots.php'; }
require_once __DIR__.'/Tribes.php';
require_once __DIR__.'/Alliances.php';
require_once __DIR__.'/Neighbors.php';
require_once __DIR__.'/RealmChat.php';
require_once __DIR__.'/Truces.php';
require_once __DIR__.'/InfernoRealm.php';
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: no-store');
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    $sessionDirectory=dirname(__DIR__).'/var/sessions';
    if(!is_dir($sessionDirectory)&&!mkdir($sessionDirectory,0700,true)&&!is_dir($sessionDirectory)){http_response_code(503);exit('PHP needs write permission for the private var/sessions folder.');}
    session_save_path($sessionDirectory);
    session_name('backyard_reborn');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
    if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) { http_response_code(503);exit('The local session store is unavailable. Check permissions on var/sessions.'); }
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
}
