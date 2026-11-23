<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!Game::configured()) { header('Location: setup.php'); exit; }
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="<?=GameLanguage::code()?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#142c28"><meta name="description" content="Build a monster haven. Raise an army. Conquer a living backyard.">
<title>Backyard Monsters · Reborn</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/game.css?v=0.13.0">
<link rel="stylesheet" href="assets/field-guide.css?v=0.13.0">
<link rel="stylesheet" href="assets/event-calendar.css?v=0.13.0">
<link rel="stylesheet" href="assets/alliances.css?v=0.13.0">
<link rel="stylesheet" href="assets/quests.css?v=0.13.0">
<link rel="stylesheet" href="assets/tribes.css?v=0.13.0">
<link rel="stylesheet" href="assets/neighbors.css?v=0.13.0">
<link rel="stylesheet" href="assets/siege-workshop.css?v=0.13.0">
<link rel="stylesheet" href="assets/mushrooms.css?v=0.13.0"><link rel="stylesheet" href="assets/inferno.css?v=0.13.0">
<link rel="stylesheet" href="assets/decorations.css?v=0.13.0">
</head>
<body>
<div id="boot" class="boot"><div class="boot-mark">B<span>M</span></div><h1>BACKYARD MONSTERS</h1><p>Waking up your backyard…</p><div class="loading-line"></div></div>
<div id="app" hidden>
<header class="topbar">
<a class="brand" href="#yard" aria-label="Backyard Monsters home"><span class="brand-emblem">B<span>M</span></span><span>BACKYARD<br><b>MONSTERS</b><small>R E B O R N</small></span></a>
<div id="resources" class="resources"></div>
<div class="profile"><span class="avatar" data-icon="monster"></span><div><b id="player-name">Keeper</b><span id="player-rank">0 rank points</span></div><button id="menu-button" class="icon-button" data-icon="settings" aria-label="Settings"></button></div>
</header>
<div class="app-body">
<aside class="sidebar">
<div class="realm-label"><i></i> THE WILD BACKYARD</div>
<nav aria-label="Main navigation">
<button class="nav-item active" data-page="yard"><span data-icon="home"></span>My backyard</button>
<button class="nav-item" data-command="champions"><span data-icon="monster"></span>Champions</button>
<button class="nav-item" data-command="lab"><span data-icon="flask"></span>Monster Lab<span class="nav-new">NEW</span></button>
<button class="nav-item" data-page="world"><span data-icon="world"></span>World map<span class="nav-new">NEW</span></button>
<button class="nav-item" data-inferno-open><span data-icon="flame"></span>Inferno</button>
<button class="nav-item" data-page="invasion"><span data-icon="swords"></span>Invasion<span class="nav-dot"></span></button>
<button class="nav-item" data-page="rankings"><span data-icon="trophy"></span>Rankings</button>
<button class="nav-item" data-quest-open><span data-icon="book"></span>Quests <span id="quest-ready-count" hidden></span></button>
<button class="nav-item" data-command="alliances"><span data-icon="shield"></span>Alliances</button>
<button class="nav-item" data-realm-chat><span data-icon="mail"></span>Realm chat</button>
<button class="nav-item" data-command="neighbors"><span data-icon="mail"></span>Neighbors</button>
<button class="nav-item" data-page="reports"><span data-icon="scroll"></span>Battle reports<span id="report-count"></span></button>
</nav>
<div class="sidebar-world"><div class="world-glyph" data-icon="world"></div><b>A living world</b><p><strong id="bot-count">1,000</strong> persistent rivals.<br>One backyard to call yours.</p><div class="world-status" id="world-status" role="status"><i></i> Connecting to world</div><p class="world-activity-detail" id="world-activity-detail">Bots wake automatically when you enter the game.</p></div>
<div class="sidebar-bottom"><button id="audio-toggle" class="quiet-button"><span data-icon="music"></span><span>Sound off</span></button><button id="help-button" class="quiet-button"><span data-icon="help"></span>Field guide</button><span id="yard-language-slot"></span><span class="version-label">LOCAL REALM · DEVELOPMENT BUILD</span></div>
</aside>
<main>
<section id="page-yard" class="page yard-page">
<div class="page-heading"><div><span class="eyebrow" id="yard-eyebrow">YOUR LITTLE EMPIRE</span><h1 id="yard-title">My backyard <span class="level-tag" id="hall-level">LV 1</span></h1></div><div class="heading-actions"><button id="return-home" class="button subtle" hidden><span data-icon="home"></span>Return home</button><button id="collect-all" class="button subtle"><span data-icon="collect"></span>Collect resources</button><button id="build-open" class="button primary"><span data-icon="plus"></span>Build something</button></div></div>
<div id="yard-utilities" class="yard-utilities"><button class="button subtle small" data-decor-action="open"><span data-icon="leaf"></span>Decorations</button><button class="button subtle small" data-baiter="season"><span data-icon="gift"></span>Yard events</button><button class="button subtle small" data-baiter="blitz"><span data-icon="shield"></span>Monster Blitzkrieg</button><button id="worker-open" class="worker-chip" data-worker="open" aria-label="Manage construction workers"></button><button id="repair-base" class="repair-base" data-command="repair-base" type="button" aria-label="Repair base for free" disabled hidden></button><button type="button" id="mushrooms-open" class="button subtle small mushroom-open"><span class="mushroom-art" aria-hidden="true"></span>Mushrooms</button><button id="planner-open" class="button subtle small" data-planner="open"><span data-icon="move"></span>Yard Planner<span class="nav-new">NEW</span></button></div>
<div id="planner-toolbar" class="planner-toolbar" hidden></div>
<div class="yard-workspace">
<div class="yard-stage" id="yard-stage"><canvas id="yard-canvas" aria-label="Interactive isometric backyard. Select buildings to upgrade or move them." tabindex="0"></canvas>
<div class="stage-top"><span class="stage-badge" id="stage-badge"><i></i> Home sweet home</span><span id="stage-hint">Click a building to manage it</span></div>
<div class="battle-banner" id="battle-banner" hidden><div><span class="eyebrow" id="battle-banner-label">INVASION IN PROGRESS</span><b id="battle-target"></b></div><span id="battle-timer">5:00</span><span id="battle-destruction">0% destroyed</span><button id="retreat" class="button danger">End invasion</button></div>
<div class="placement-banner" id="placement-banner" hidden><span id="placement-message"></span><button class="button small subtle" id="cancel-placement">Cancel <kbd>Esc</kbd></button></div>
<div class="stage-bottom"><span><i class="pulse-dot"></i><span id="yard-weather">A good day to grow something wild.</span></span><div><button class="icon-button" id="zoom-out" data-icon="minus" aria-label="Zoom out"></button><button class="icon-button" id="zoom-reset" data-icon="focus" aria-label="Reset camera"></button><button class="icon-button" id="zoom-in" data-icon="plus" aria-label="Zoom in"></button></div></div>
<div class="drill-countdown" id="drill-countdown" role="status" aria-live="polite" hidden><span>Wild pack arriving</span><b id="drill-countdown-number"></b></div>
<div class="battle-result" id="battle-result" hidden></div>
</div>
<aside id="yard-panel" class="yard-panel"></aside>
</div>
<section id="planner-inventory" class="planner-inventory" aria-label="Draft building storage" hidden></section>
<div class="army-dock"><div class="army-intro"><span class="eyebrow" id="army-eyebrow">READY FOR TROUBLE</span><h2>Your monsters</h2><p id="army-count">Preparing the hatchery…</p></div><div class="monster-list" id="army-list"></div><button class="hatchery-open" id="hatchery-open"><span data-icon="egg"></span><b>Hatchery</b><small>Grow your army</small><span data-icon="chevron"></span></button></div>
</section>
<section id="page-world" class="page world-page" hidden>
<div class="page-heading"><div><span class="eyebrow">BEYOND THE BACKYARD</span><h1>A world worth claiming</h1><p>Find your foothold. Grow your reach. Give your monsters an empire.</p></div><span class="pill"><i class="pulse-dot"></i> Persistent territories</span></div>
<div class="world-map-layout"><div class="world-map-main"><div class="world-map-toolbar" id="world-map-controls"></div><div class="world-map-stage"><canvas id="world-map-canvas" tabindex="0" aria-label="World territory map. Drag to pan, scroll to zoom, or select a site in the list below."></canvas><div class="world-map-caption">Drag to explore · Select a territory to scout</div></div><div id="world-map-status" class="world-map-status" role="status" aria-live="polite"></div></div><aside class="world-map-aside"><div id="world-map-info"></div><div class="world-holdings-heading"><span class="eyebrow">YOUR OUTER EMPIRE</span><h2>Territories you hold</h2></div><div id="world-map-holdings"></div></aside></div>
<details class="world-visible-sites"><summary>Territories in this view</summary><div id="world-map-sites"></div></details>
</section>
<section id="page-invasion" class="page listing-page" hidden><div class="page-heading"><div><span class="eyebrow">BEYOND YOUR FENCE</span><h1>Find your next rival</h1><p>Scout a backyard. Choose your moment. Send in the monsters.</p></div><button class="button subtle" data-truce-open="0"><span data-icon="shield"></span><span id="truce-button-label">Truces</span></button><button class="button subtle" data-inferno-command="rivals"><span data-icon="flame"></span>Underground rivals</button><button class="button primary" data-tribes-open><span data-icon="flag"></span>Wild Monster Tribes</button></div><div class="invasion-hero"><div><span class="eyebrow">THE BACKYARD IS ALIVE</span><h2>Small monsters.<br>Big ambitions.</h2><p>Every rival has a persistent base and a growing army.<br>Visit first to plan your attack.</p></div><img loading="lazy" decoding="async" src="assets/portraits/pokey.webp" alt="Pokey monster" class="hero-monster"><div class="hero-stats"><b>1,000</b><span>BOT RIVALS</span><div></div><b>+25 / −15</b><span>WIN / LOSS POINTS</span></div></div><div class="list-toolbar"><div class="tabs"><button class="active" data-rival-filter="all">All backyards</button><button data-rival-filter="players">Players</button><button data-rival-filter="bots">Bots</button></div><label class="search"><span data-icon="search"></span><input id="rival-search" placeholder="Search a keeper…" aria-label="Search opponents"></label></div><div id="rivals-list" class="rivals-grid"></div><div class="pagination" id="rival-pagination"></div></section>
<section id="page-rankings" class="page listing-page" hidden><div class="page-heading"><div><span class="eyebrow">EARN YOUR PLACE</span><h1>The backyard league</h1><p>Players and bots. One leaderboard. Everyone starts at zero.</p><p class="ranking-live-status" id="ranking-live-status">Rankings update automatically while you play.</p></div><span class="pill" data-icon="trophy"></span></div><div class="ranking-summary"><div><span>YOUR RANK POINTS</span><b id="rank-score">0</b></div><div><span>WIN A BATTLE</span><b class="positive">+25</b></div><div><span>LOSE A BATTLE</span><b>−15</b></div><p>A successful defense counts as a win.<br>Rank points can fall below zero.</p></div><div class="ranking-table"><div class="ranking-row table-head"><span>RANK</span><span>KEEPER</span><span>WINS</span><span>LOSSES</span><span>POINTS</span><span></span></div><div id="rankings-list"></div></div><div class="pagination" id="ranking-pagination"></div></section>
<section id="page-reports" class="page listing-page" hidden><div class="page-heading"><div><span class="eyebrow">TALES FROM THE FRONT YARD</span><h1>Battle reports</h1><p>Every attack and defense, recorded by your realm.</p></div><button id="refresh-reports" class="button subtle" data-icon="refresh" aria-label="Refresh reports"></button></div><div id="reports-list"></div></section>
</main></div>
</div>
<div id="modal" class="modal" hidden><div class="modal-backdrop"></div><section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-title"><button class="modal-close icon-button" id="modal-close" data-icon="close" aria-label="Close dialog"></button><div id="modal-body"></div></section></div>
<div id="toasts" class="toasts" role="status" aria-live="polite"></div>
<script type="application/json" id="yard-language-data"><?=json_encode(GameLanguage::shell(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script>
<script src="assets/localize.js?v=0.13.0"></script>
<script type="module" src="assets/main.js?v=0.13.0"></script>
</body></html>
