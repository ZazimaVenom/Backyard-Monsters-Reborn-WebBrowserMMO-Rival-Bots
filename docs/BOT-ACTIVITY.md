# Online bot activity — 0.8.1

The 1,000 saved bots now receive bounded simulation work automatically while at
least one signed-in human has a live game page. A player does not need to start a
separate worker. The worker remains optional and follows exactly the same online
gate as browser activity. Signing out removes that session's presence; closing a
page sends a best-effort departure. An abruptly closed or suspended browser can
take up to 45 seconds to expire. Other players and other live tabs keep the world
active.

The original scheduling gap was that no normal browser request ran the bot
scheduler. Without the optional command-line worker, saved bots never took turns
or resolved their autonomous invasions. Rankings also needed a refresh to display
new results. See `ONLINE-WORLD-UI.md` for the automatic heartbeat and ranking-page
refresh behavior.

## Real activity and rankings

Accepted world pulses share one database lock and one five-second dispatch
cadence. More players or tabs do not multiply that cadence. Each pulse advances a
bounded set of invasions and then spends its remaining budget on due bot turns.
Combat receives at most 55% of the configured time budget and at most 20 seconds
of saved invasion progression per battle in one pulse, so a combat backlog cannot
consume every construction and hatching turn. The limit is checked between
battles; a single battle transaction completes atomically.

Bots continue to buy buildings, hatch monsters, research, manage their territory,
and launch invasions through the same economy and combat services as players.
The scheduler does not award ranking points. A settled battle grants its winner
25 points and subtracts 15 from its loser, with normal reports, casualties and
loot. An invasion that has just started has not earned any ranking points. The
first changes therefore follow actual completed battles rather than login.

Fresh installations stagger the first bot decisions across the first 120 online
seconds. Existing worlds keep their saved schedule and all 1,000 identities,
names, bases, armies, queues and current rankings. Existing combat and development
cooldowns still determine when each bot can next act; logging in does not reset
them or grant troops.

## Pausing and resuming

The world clock records elapsed time covered by authenticated human presence.
Bot-owned production, construction, training, research, champion maintenance,
shields and AI cooldowns retain their remaining durations across an idle period.
Positive scheduler deadlines shift once by the same cumulative paused duration.
A newly installed world left empty for a day keeps its initial stagger when the
first player arrives. Zero due markers are preserved, and repeat observations do
not apply the same pause twice.

Bot-origin invasions store an active-world clock anchor in their existing battle
state. When nobody is online they preserve their elapsed combat, deployed units,
remaining troops and results. A direct poll returns that saved snapshot without
deploying another bucket or saving a settlement. Resuming a pre-update bot battle
adopts its saved elapsed value; old offline wall time is never replayed as combat.
Human-origin invasions retain their normal real-time combat rules.

The scheduler checks current presence before each new bot turn and before starting
another autonomous invasion. A transaction already running when a player leaves
may finish; subsequent work observes the empty world and stops. Bot targeting
projects a rival bot's paused shield deadline before choosing it, so an old raw
timestamp does not make a protected rival look attackable.

The persisted clock and scheduler metadata live in existing settings/state JSON;
no duplicate bot population or replacement ranking table is created. The global
clock read used while base/site/battle rows are locked does not acquire another
lock or write settings. `bots.enabled=false` keeps autonomous scheduling disabled
even when players are online. A legacy worker heartbeat does not substitute for
live human presence.

## Verification

`tests/bot_activity_combat_test.php` verifies 18 pure clock cases: saved battle
adoption, active-only progression, idle time, resume, clock epoch changes,
malformed legacy anchors, no rewinds and unchanged human wall-time rules.

`tests/bots_activity_integration_test.php` uses an explicitly disposable
`*_bots_test` database with `BYM_BOTS_TEST_RESET=1`. It seeds the real 1,000-bot
world, changes only due dates and test clock anchors for the organic combat
portion, and leaves starter armies, buildings and resources untouched. It checks
anonymous inactivity, real human presence, shared cadence, logout, offline
polling, bounded combat, legacy adoption, genuine rank awards and their
idempotence, identity preservation, paused shields, scheduler clock shifts and
the explicit disabled configuration.

The shared `tests/ActivityFixture.php` requires a CLI disposable test database.
It opens an actual human presence lease, releases the dispatch cooldown only in
that guarded fixture, and can age a battle's online clock without changing its
elapsed combat, army or results. There is no production bypass for presence.
The five existing bot integration suites now use this same explicit fixture.

The organic test completed 12 bot-versus-bot invasions on both MySQL 8.0.44 and
MariaDB 10.4.32, producing normal wins, losses and ranking changes from zero while
preserving all 1,000 accounts. These clocks are accelerated integration fixtures,
not a long-duration balance test or a guarantee of first-win timing on a
particular server. Exact release assertion counts are recorded in `VALIDATION.md`.
