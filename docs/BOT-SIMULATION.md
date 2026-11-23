# Free home repair update — 0.8.2

On each online due turn, a bot uses the same free `repairBase` home action as a
player to restore every damaged existing building and rearm spent traps. It can
recover even with no resources. An active invasion blocks the action. New
construction and paid upgrade/fortification timers remain intact; lost army or
garrison troops and champion injuries are not regenerated. Territory repairs
continue to use their existing cost and timing rules. The online presence gate
from 0.8.1 remains authoritative: this does not wake an empty world.

# The 1,000-bot world

The installer creates exactly 1,000 saved bot accounts, each with its own name, base, resource balance, monster army, construction and training queues, and zero ranking points. They use the same `users` and `bases` tables as people, appear in the shared rankings and invasion list, and remain after the server or worker restarts. Ranking changes come from resolved battles; the scheduler does not invent wins or points. Upgrading an existing world preserves the same bot IDs, names, points, records, bases, resources, and queues. It never reseeds the population or resets rankings.

## Automatic activity in 0.8.1

After `setup.php`, register or sign in on the separate game login screen. Keep
the signed-in game open: its authenticated, CSRF-protected activity pulses drive
the bots automatically about every five seconds. **Normal XAMPP play needs no
manual worker command or extra window.** Anonymous visitors, the login screen and
bot accounts do not establish human presence.

Each browser tab has its own lease, attached to the authenticated PHP session.
Closing a tab sends a best-effort leave request; logout removes the session's
leases. Other tabs, sessions and players keep the world active independently.
If the browser crashes, disconnects or suspends its pulses, its lease expires
45 seconds after the last successful pulse. Background tabs continue sending
pulses when the browser permits them; a suspended tab is not guaranteed to stay
online. Returning to the game renews presence.

With no online humans, the scheduler performs no bot decisions or autonomous
battle progression. Bot production, construction, training, discovery, research,
champion care, shields, territory jobs and raid cooldowns exclude that paused
interval. Bot-origin battles use the same active-time clock. Human base
progression and human-origin invasions retain wall-clock behavior, including a
human attack against a bot. Saved accounts, armies, jobs and invasions remain
intact. The first player resumes the world without replaying offline bot turns
or awarding catch-up rank points.

The game shows online activity and the latest completed scheduler work. Rankings
refresh while their page is visible. Points move only when actual battles settle:
the winner gains 25 and the loser loses 15. Fresh bots need time to develop,
produce troops, launch attacks and resolve combat; activity pulses are not wins.

## Optional worker

The existing CLI worker remains available in the project directory:

```console
php bin/worker.php
```

On Windows, `START-WORLD.cmd` uses the same PHP discovery as `START-LOCAL.cmd`: an explicit `PHP_EXE`, then PHP found beside or above the project (including a containing XAMPP installation), then PHP on PATH. You can explicitly provide its location before starting:

```bat
set "PHP_EXE=D:\tools\php\php.exe"
START-WORLD.cmd
```

For a normal game folder beneath XAMPP's `htdocs`, `START-XAMPP.cmd` finds its `php\php.exe` automatically. The older `bin/start-worker.cmd` entry point forwards to `START-WORLD.cmd` and accepts `BYM_PHP` as an optional alias. PHP version/PDO checks run before the worker starts. Windows launchers have been reviewed statically, but need an execution check on Windows.

The optional worker uses the same presence gate, five-second shared dispatch cadence and database lock as browser pulses. It pauses when no human is online, even if its window remains open. Closing it does not stop browser-driven activity. Opening several worker windows or several game tabs does not multiply bot turns. This process is separate from the construction workers hired inside a yard.

For cron or an existing supervisor, `php bin/tick.php --limit=25 --budget=4` attempts one bounded tick and exits. It also requires online human presence and shares the cadence; it cannot force an offline turn. Both CLI entry points refuse web execution. The game's authenticated pulse endpoint is the normal browser driver, not an anonymous worker endpoint.

## How the bots play

Each ID deterministically receives a builder, raider, guardian, or collector personality. Its layout rotation, placement candidates, monster preferences, preferred champion, and staggered schedule are also seeded from its persistent ID. A turn can collect produced resources, repair damage or rearm a spent trap, construct or upgrade a building, unlock and hatch monsters, station a bunker crew, recycle a small surplus, research a species or Lab ability, raise or feed a champion, and decide whether to invade.

Construction, upgrades, repairs, species unlocks, garrison transfers, Juicer recycling, research, and training call the same `Game::mutate` methods as player actions. Bots must afford costs, obey unlocks, wait for queues, fit their housing, and use legal building footprints. They have no infinite battle army: an invasion deploys monsters from their saved stock.

The four personalities pursue different building counts and army compositions. Placements leave movement routes and interrupted defensive walls. A bot remembers its turn count, most recent decision, and last invasion time in its own base state.

The expanded plans include Monster Flingers, Monster Lockers, Monster Juicers, Laser Towers, Monster Bunkers, Booby Traps, Monster Labs, Catapults and Map Rooms. Existing bots without a Flinger make its paid construction their first expansion priority and cannot raid before it finishes. Fresh worlds give bots the same starter Flinger as new human yards. Damaged Flingers receive repair priority.

Bots upgrade their Town Hall and Locker to unlock Bolt, Eye-Ra, Fang, Wormzer, Teratorn, Zafreeti, Brain, Crabatron and Project X through the normal paid timers. Training favors unlocked species that are missing from the current army, including queued troops when deciding what is already represented. Bunker staffing transfers real monsters out of the army and preserves an offensive reserve. A Juicer is used only to free enough housing for a missing unlocked species or an incomplete champion meal, with a bounded surplus recycle; the normal refund and storage limits apply.

An invasion needs a completed, living Flinger and at least 80 housing of deployable troops with an offensive monster present, or a healthy Gorgo/Drull champion. This allows a heavy siege monster to form a valid attack force while excluding healer-only raids. Fomor waits for an offensive escort. The combat service persists a bucket plan and launches it according to the same bucket capacity and cooldown as human attacks. Later buckets are consumed from the saved army only when actually launched. The scheduler never bypasses the Flinger cooldown.

## Champions

At Town Hall level 4, bots save for and build a Champion Cage and subsequently a Champion Chamber. Cage construction pays the same costs and timers as player yards, while home repairs are free and instant; unfinished or destroyed Cages cannot grant a champion. Each ID prefers Gorgo, Drull or Fomor deterministically, with all three represented across the 1,000-bot population. Raising the first champion is immediate and free once the Cage is complete, matching the player action and source raising rule. Bot scripts do not directly create champion records, set levels, or refill health.

Bots feed their active champion through `championFeed`, consuming the actual monster recipe and respecting its cooldown and evolution thresholds. At level 6 the same recipe earns up to three bonus tiers; further maintenance meals keep the champion fed without adding more tiers. The next meal receives priority for species discoveries and training, even when a recipe needs more than the older 42-monster training target. Each decision trains at most six food monsters, counts already queued troops, and checks goo, housing and unlocks. Housing upgrades receive priority when the next recipe plus an 80-space reserve will not fit.

Owned meal ingredients are reserved from automated Flinger deployment, bunker transfers and recycling. The battle service validates the reservation against actual stock; it does not copy the reserved troops or remove them from the persistent army. This lets a bot continue feeding and evolving while its other troops fight. A full yard can recycle non-reserved surplus to make space through the normal Juicer rules. The planner never consumes a reserved recipe to make room for another recipe ingredient.

An active champion waits until at least 65% of its maximum health before the bot starts another raid. Recovery follows the normal rules using online world time for bots, and its health is locked while a battle runs. Once ready, the selected champion deploys once at a valid edge position; it has the same separate deployment and offensive/defensive combat behavior as a human champion. Injuries persist after settlement. A healthy Gorgo or Drull may raid without ordinary troops; Fomor needs at least 80 housing of offensive troops beyond its reserved meal. An existing stored champion can be thawed when no champion is active and its Chamber is fully repaired. Bots currently maintain one chosen active champion rather than automatically rotating a three-champion collection.

Eligible bots choose rivals from both people and other bots. Human targeting is a configurable fraction of targeting decisions, with protected accounts excluded. Candidates are within two Town Hall levels. New human yards have a starter shield, and a human defender gains a further shield after a bot assault. Bot attacks use the exact same authoritative combat engine, placement validation, damage, loot, and settlement rules as player attacks. Bot-origin battles progress against the online world clock and can be viewed through the regular battle interface. Human-origin invasions retain real server time.

## Monster Lab and Catapult progression

Every personality includes a Monster Lab and Catapult in its construction plan. Both unlock at Town Hall 3 and must be paid for, placed legally and completed before use. The Academy, Lab and Catapult use a bounded fallback search for clear space when their preferred layout ring is crowded; this never moves or removes an existing structure. Bots upgrade the Academy and Lab toward the tiers needed for their studies, and develop their Catapult through its normal levels. A Lab cannot be upgraded while it is studying an ability.

The ten source-backed powers belong to Bolt, Fink, Bandito, Fang, Eye-Ra, Brain, Project X, D.A.V.E., Wormzer and Teratorn. Bots study these through the same `labResearch` action as humans: the species must be unlocked, Lab level must cover the requested tier, the monster's Academy level must meet the prerequisite, and the full putty price must be available. A single saved study timer runs at a time. Bots do not insert learned levels or skip a research queue.

Study selection favors unfinished lower tiers and species represented in the bot's army, training queue or bunker garrisons. Every fifth decision, the Academy prioritizes a missing prerequisite for a useful Lab study before ordinary stat growth. Feeding and food training run first. Optional Academy research must leave enough goo to hatch the next champion meal's ingredients that are not already owned or queued. Thus a larger future food recipe does not lose its budget to optional research. Lab studies spend putty, separate from food goo.

Project X joins ordinary discovery, training and bunker choices after its Town Hall 4 / Locker 4 unlock. Its death-burst ability requires earned Lab research; the bot planner does not grant it innately. Learned powers are snapshotted into attacking troops and defensive garrisons by the same battle service used for people.

An eligible bot with a completed Catapult can include a saved artillery plan in an invasion. The battle service selects at most one twig, one pebble and one putty shot, each priced at no more than 20% of that resource's current stock when the plan is formed. Planned shots are not free and do not reserve or spend resources early: the normal Catapult action validates and charges the actual balance at launch. Invalid or unaffordable scheduled shots are skipped. A resource family cannot be fired twice in the same invasion.

The bounded plan schedules damage shots against visible defenses or storage and a later putty support shot around living attackers. Hidden traps are never read to choose an aim point. Putty is skipped if no attacking monsters remain. This is a deterministic remake tactic, not a claim to reproduce an original player AI. Champion food reserves remain in the army, independent of artillery resources and the Flinger plan.

## Persistent territory campaigns (0.5)

All 1,000 existing bot IDs participate in the same strategic map as players. Their home markers and captured territory persist in `world_sites`. Neutral resource outposts and strongholds are site records with defensive troops, never extra user accounts. The scheduler does not add bots, reseed identities, reset ranks or award points outside settled combat.

At Town Hall 3, every personality saves for one Map Room through the normal paid construction action. It also develops that building through its three levels, for two, four or six holdings. A Map Room must finish and remain alive before territory attacks are eligible. A destroyed Map Room receives repair priority after the Flinger and Champion Cage. Its placement uses the same bounded fallback search as the other large facilities, preserving existing geometry.

A bot with an eligible army, available holding capacity, a ready Map Room and an expired raid cooldown considers territory first on 65% of its eligible raid decisions. The other decisions retain ordinary home invasions; a failed territory search also falls back to the existing home directory strategy. Territory choices obey home/outpost/stronghold reach, shields, live owner-wide battle restrictions and the global active-battle ceiling. The configured human-target fraction applies to human-owned territory as well as home raids.

The world service supplies at most 24 spatially eligible site candidates for a decision, using the turn seed to rotate choices. The planner considers at most 48 supplied rows and retries at most four candidates if a target changes before locks are acquired. It alternates neutral expansion and enemy-owned conquest; raiders and guardians sometimes prefer strongholds. It does not inspect hidden traps or opponent home balances to select targets. These are bounded policy choices, not a complete strategic search over every player and territory.

Territory battles use `BattleService::botAttackSite`, the same authoritative simulation and capture gate as player invasions. The existing bucket deployment, cooldowns, paid Catapult ammunition, earned Lab powers and champion behavior all apply. The bot supplies its actual saved army and reserves its champion meal from all buckets. A successful ordinary invasion does not automatically capture a site: territory requires the separate 90% eligible maximum-HP damage threshold. A neutral defender has no account to debit. Enemy territory defense does not consume or damage its owner's home yard or loot the owner's bank.

After ordinary home progression and champion care, each bot checks only its own holdings and performs at most one successful management action per turn. It rotates their order rather than always serving the first outpost. Damaged defenses get paid repair priority. Healthy sites receive up to three owned troops per turn, within the real bunker capacity, while retaining all champion meal ingredients and at least 80 housing of offensive troops beyond that meal. Garrison transfers remove those troops from the home army. The planner then invests in ordinary paid site upgrades; no upgrade or repair is granted by editing JSON, skipping a timer or inventing income. Optional site spending preserves goo still needed to hatch the next champion meal.

All territory mutations call the same `World::mutate` actions as the player UI and are validated again under locks. Resource outposts earn their normal territory income and storage contribution through the normal cached world economy. Stronghold combat bonuses are snapshotted by the battle service. The bot has no separate income multiplier, free territory repairs, resource injection, free garrison reinforcement or rank reset. Automatic abandonment and garrison withdrawal are intentionally absent from the current strategy; those player actions remain available through the normal management interface.

`territory_attacks`, `sites_upgraded`, `sites_repaired` and `sites_garrisoned` appear in scheduler results and optional worker diagnostics. The garrison counter counts monsters transferred; upgrade and repair counters count completed decisions that started paid work. Territory attacks also increment the existing `attacks` total. A neutral defender never occupies account ID 0 in the scheduler's busy-account set; the battle service separately excludes the active neutral site.

## Scheduling and load

The default scheduler processes up to 25 due bots per accepted tick within a four-second budget. All browser and CLI entry points share a five-second dispatch interval. Up to 55% of the time budget is reserved for bounded battle advancement, with at most 20 online-world seconds advanced per selected battle in a pulse; remaining time serves due bot turns. Each bot is scheduled roughly every 90–300 active seconds; the installer may explicitly configure a 60-second lower bound. Seeds stagger initial turns rather than scheduling all 1,000 immediately. There is a cap on simultaneous active battles. Missed turns are rescheduled, not replayed in an unlimited loop.

Base-building decisions process only due accounts; raid selection reads small candidate sets. The scheduler never renders bots and never simulates all 1,000 combat armies on every page request. Due turn times are claimed before work begins. A MySQL advisory lock allows only one world scheduler per database, so accidentally opening two worker windows does not double the bot turns. Regular base transactions and the battle service protect account balances and prevent overlapping attacks.

The shared activity status records online human/tab counts, the last accepted dispatch and bounded completed-work counters, including `battles_advanced` and `battles_finished`. It does not expose session or tab tokens. The optional CLI worker also writes `var/world-status.json` for local operational diagnostics. That file alone is no longer a test of whether the world is active: browser pulses can advance the world without a worker process. The UI uses shared activity status, not the CLI file's age.

Presence and active-time accounting use private database settings rather than a new account or presence table. Bot base/site deadlines and scheduler due times receive each cumulative paused interval once. Existing bot-origin battles anchor their saved elapsed state instead of retroactively simulating time before the activity clock. Fresh installation and upgrade initialize an empty sleeping epoch, preventing time before the first player from becoming a burst of bot progress. The read-only status/clock projection does not dispatch simulation work.

Optional settings in the private `var/config.php` array:

```php
'bots' => [
    'enabled' => true,
    'batch_size' => 25,
    'turn_min' => 90,
    'turn_max' => 300,
    'attack_cooldown' => 900,
    'max_active_battles' => 48,
    'human_target_percent' => 15,
    'budget_seconds' => 4,
],
```

`interval_min` and `interval_max` are accepted aliases for the turn limits. The scheduler clamps settings to bounded ranges. Setting `enabled` to `false` disables automatic bot dispatch even while players are online; it does not erase saved work. The no-player gate applies to every worker and one-shot tick. Combat and economy balancing are defined centrally in `app/Catalog.php`.

## Current boundary

These bots are autonomous opponents for the remake's implemented base-building, champion, Lab, invasion and persistent territory systems. Their deterministic strategy is remake AI, not a port of the original Flash game's player behavior. The implemented Fortification network is documented below. Alliances, event campaigns, social systems and remaining original-game parity still require separate implementation. Champion feeding/recovery timers and raid thresholds are remake tuning, described with the rest of the champion implementation.

## Verification

The 0.8.1 activity suites cover pure clock/deadline behavior, real authenticated human leases, multiple tabs and sessions, expiry, disabled/offline dispatch, idempotent initialization and concurrent requests. `tests/world_activity_test.php` needs no database; `tests/world_activity_integration.php` and `tests/world_activity_concurrency.php` require a guarded disposable `_activity_test` database. The bot-activity suites additionally exercise real rank-changing combat and paused battle clocks. See `VALIDATION.md` and `../tests/README.md` for current commands, evidence and limits.

`php tests/bots_planner_test.php` validates deterministic personalities, varied placements, yard boundaries, collision clearance, and catalog coverage across 1,000 bot IDs without a database.

`tests/bots_integration_test.php` requires an explicitly disposable MySQL database whose name ends in `_bots_test`, plus `BYM_BOTS_TEST_RESET=1`. It recreates tables in that test database, seeds all 1,000 bots, and exercises the actual economy and battle services. Set `BYM_BOTS_TEST_HOST`, `BYM_BOTS_TEST_PORT`, `BYM_BOTS_TEST_DATABASE`, `BYM_BOTS_TEST_USER`, and `BYM_BOTS_TEST_PASSWORD` for a test account allowed to create/reset only that disposable database. Never point tests at a game database. `BYM_BOTS_LOAD_TEST=1` additionally executes a turn for the full population with raid targets shielded to isolate scheduling and economy load. `BYM_BOTS_LAB_LOAD_TEST=1` adds a separate prepared 1,000-yard Lab workload with exact putty budgets, earned Academy prerequisites and protected targets. It verifies paid queues and unchanged identities/ranks. Both options operate only inside the guarded disposable test database, replacing test fixtures there.

Validation in the development runtime uses PHP 8.4.27 and MySQL 8.0.44. The historical 0.3 planner passed 28,088 checks, including all three persistent champion preferences, source recipes, bounded/affordable food training, queued-food accounting, reserved ingredients, maximum-level maintenance and recovery-aware raids. The historical 0.3 MySQL integration passed 79 checks, including paid Cage construction, raising, feeding and evolution through player APIs, cooldowns, injuries, reserved meals, actual champion raids, and preservation of exactly 1,000 bot identities. A developed-yard regression also checks legal Cage placement when the preferred inner ring is occupied. See `VALIDATION.md` for the complete release verification.

The historical 0.2 full-population run completed 1,000 bot turns and 998 new constructions without errors in 17.643 seconds. This measures economy/decision work in the local test runtime, not a guarantee for a particular server or simultaneous battle load. Current-release population measurements, when run, are listed separately in `VALIDATION.md`.

The 0.3 ordinary population batch completed 1,000 turns and 998 paid constructions with zero errors in 16.658 seconds. A separate developed-yard fixture completed 1,000 turns, 1,000 paid Cage constructions and 2,692 queued food/army monsters in 11.323 seconds. Every Cage cost and all unchanged identities/ranks were verified; unfinished Cages granted no champions. Both fixtures protected targets to isolate economy decisions. They do not measure 1,000 simultaneous battles or long-term organic progression.

The 0.4 pure planner passed 29,128 checks, adding specialist construction coverage, all fifteen ordinary species, three-tier Lab prerequisites and queue gates, normal affordability, Project X eligibility, champion food goo reservations, and crowded-yard specialist placement. The MySQL suite passed 119 checks, including actual paid Lab/Catapult construction, queued research and completion, food-aware Academy preparation, Project X deployment with its earned power, all three paid artillery families, actual putty effects, repeat-poll idempotence and poor-bot ammunition budgets.

The 0.4 ordinary population run completed 1,000 turns and 998 constructions with zero errors in 21.354 seconds. The separate prepared Lab workload completed 1,000 turns and 1,000 paid studies in 9.733 seconds. All 1,000 putty charges and saved queues were verified, with zero early ability grants, raids or errors, and an unchanged hash of every bot ID, name, ranking and win/loss record. These isolated local fixtures do not measure concurrent invasions, organic progression speed or long-term economy balance.

The v0.5 additions have separate tests: `php tests/bots_world_planner_test.php` covers bounded choices, both neutral and enemy preferences, human targeting, range/busy/shield/holding gates, paid management decisions, champion meal reservations and Map Room placement for 1,000 IDs. `tests/bots_world_integration_test.php` uses the same guarded disposable `_bots_test` database settings as the existing integration suite. It checks actual scheduler territory launches, real simulated neutral/enemy capture, stock transfers, paid site timers and unchanged home defenses. `BYM_BOTS_WORLD_LOAD_TEST=1` adds a prepared 1,000-yard workload with exact Map Room budgets and verifies every charge, queue, identity and ranking. This fixture measures bounded economy work, not 1,000 simultaneous battles or long-term organic progression.

The v0.5 planner regression passes 29,128 existing checks plus 5,031 territory-specific checks. Both MySQL 8.0.44 and exact MariaDB 10.4.32 pass the 119 existing bot integration assertions and 50 new territory assertions. The latter exercise actual scheduler-led neutral conquest, actual enemy-bot territory conquest, paid Map Room construction and upgrades, normal site upgrade/repair prices, real garrison transfers, active-battle limits, owner-wide exclusions and preserved reserved troops.

The prepared 1,000-Map-Room workload completed in 5.936 seconds on MariaDB 10.4.32 and 8.922 seconds on MySQL 8.0.44 in the development runtime. Each run verified 1,000 exact resource charges, 1,000 persisted construction timers, zero premature completions, zero raids, zero scheduler errors, and an unchanged hash of all IDs, names, points and win/loss records. These are isolated local measurements, not server-performance guarantees or organic long-term balance measurements.


## Fortification campaigns (0.7)

Bots contest useful Fortifications through the normal battle engine and pay for local upgrades, repairs and real garrisons through the same actions as human keepers. Network turns share the existing bounded scheduler and preserve ordinary expansion, champion food, offensive troops and home construction/armor reserves. The separate capacity does not increase income or strategic reach. The detailed target scoring, scheduling adaptations and test limits are in `BOTS-V07.md`.
