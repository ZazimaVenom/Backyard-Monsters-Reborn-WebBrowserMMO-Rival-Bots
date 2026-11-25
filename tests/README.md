# Testing 0.13.1

Run `python tests/rehatched_sync_test.py` to verify matching browser/client assets, hashes and release versions. Python 3 is needed only for these development checks. `python tests/branding_http_test.py` uses PHP 8.2+ and a temporary application copy to exercise new and migrated browser sessions without a database. Set `PHP_EXE` if PHP is outside PATH.

The current results and exact platform coverage are in [the release validation record](../docs/VALIDATION.md). The game is playable with PHP, PDO MySQL and a database. The JavaScript, Python and asset checks below are development tools.

From the game folder, the following commands exercise rules and the Canvas controls without creating a world.

```sh
php tests/languages_test.php
php tests/event_calendar_test.php
node tests/combat_performances_test.mjs
php tests/monster_expansion_test.php
php tests/monster_madness_test.php
php tests/krallen_hunt_test.php
php tests/blitz_test.php
php tests/brukkarg_defense_test.php
php tests/inferno_compound_test.php
node tests/krallen_hunt_ui_test.mjs
node tests/monster_directions_test.mjs
node tests/renderer_test.mjs
node tests/navigation_ui_test.mjs
```

Database suites operate on disposable schemas. Read the guard at the top of each fixture, create the empty database it requests, and configure `MYSQL_TEST_HOST`, `MYSQL_TEST_PORT`, `MYSQL_TEST_DATABASE`, `MYSQL_TEST_USER` and `MYSQL_TEST_PASSWORD`. Some concurrency and upgrade suites require additional explicit flags. The release includes coverage for native production, cavern raids and defenders, bot activity, source events, guardian deployment, alliance powers, decoration purchases and migration. Earlier test descriptions below preserve their original version context.

## Historical 0.11.0 test notes

Version 0.11.0 added guarded database tests for alliances, war scoring, friends, gifts and mail, truces, tribal camps, the quest journal and siege. Run each database suite on its own empty disposable schema with the suffix declared at the top of that suite. The game requires only PHP, PDO MySQL and a running database. Node and Python are developer tools for testing and asset authoring.

Run the standalone rules and UI checks for new features with `php tests/flinger_test.php`, `php tests/quests_test.php`, `php tests/siege_workshop_test.php`, `php tests/siege_combat_test.php`, `node tests/renderer_test.mjs`, and `node tests/navigation_ui_test.mjs`. Release-wide results and platform coverage are recorded in `docs/VALIDATION.md`.

# Wild Monster Baiter in 0.10.0

`php tests/baiter_engine_test.php` exercises wave selection, musk and direction gates,
real battle effects and ending the drill. `php tests/baiter_session_test.php` covers
seven building levels, repeated requests, session reload and stale requests from
another tab. The database integration fixture is `tests/baiter_integration.php`.
Check its connection guard before using a disposable database. The release checks
and runtime limits are in `docs/VALIDATION.md`.

# Advanced yard defenses in 0.9.0

Run `php tests/advanced_defenses_test.php` for tower volleys, airborne targeting,
Heavy Trap trigger species, collateral damage, concealment, repairs and saved combat.
Use `node tests/renderer_test.mjs` and `node tests/navigation_ui_test.mjs` for the
browser rendering and controls. The regression suites use Node only during testing.

For database coverage, run `php tests/advanced_defenses_integration.php` with the
standard `MYSQL_TEST_*` variables and an empty disposable database ending in
`_defense_test`. The script creates tables and rolls back its fixture rows.
See `docs/VALIDATION.md` for the checks run against this release and platform limits.

# Free home repair checks — 0.8.2

The checks below were added for the Repair base button and the shared bot repair
path. PHP and MySQL/MariaDB were unavailable in the 0.8.2 authoring environment,
so the new PHP tests, PHP lint, database suites and updater fixtures **have not
been executed for this release**. Earlier pass counts below are historical.
See `docs/VALIDATION.md` for the frontend checks actually run.

Run the pure rules and UI checks from the project root:

```sh
php tests/home_repair_test.php
node tests/repairs_ui_test.mjs
node tests/navigation_ui_test.mjs
```

On XAMPP, `C:\xampp\php\php.exe tests\home_repair_test.php` can run the pure PHP
suite without a database; substitute the actual XAMPP path if different. It covers
all home building types, zero-resource repair, spent traps, exact HP-only changes,
paid job preservation, new construction and repeat actions.

The integration and concurrency suites require an **empty disposable database**
whose name ends in `_repair_test`. Never use the installed game database. Set
`MYSQL_TEST_HOST`, `MYSQL_TEST_PORT`, `MYSQL_TEST_DATABASE`, `MYSQL_TEST_USER` and
`MYSQL_TEST_PASSWORD`, then run:

```sh
php tests/home_repair_integration.php
php tests/home_repair_concurrency.php
```

Each refuses a database containing users. The integration fixture rolls back its
rows; concurrency fixtures clean up in `finally`. Creating tables is not rolled
back. The tests cover owner-only status, free bulk and individual repair, active
invasion rejection, ordinary bot dispatch with and without human presence, repeat
settlement, and real competing repair/invasion processes. Concurrency additionally
requires `proc_open`, `proc_close`, `proc_get_status`, `proc_terminate` and visibility
of the test account's own connections in `information_schema.PROCESSLIST`.

Existing fortification, construction, bot and upgrade assertions were adjusted
for free home repairs and the 0.8.2 target. The updater fixture includes damaged
human/bot homes, active invasions, paid jobs and saved online clocks; it asserts
migration preserves them rather than performing repair. These PHP fixtures also
remain unexecuted in this release environment.

---

The records and pass counts below describe previous releases only. They are not
verification of the 0.8.2 changes.

# Online bot activity checks — 0.8.1

The production scheduler now requires a genuine authenticated human presence lease.
The existing combat and bot integration fixtures use `ActivityFixture.php` to open
that lease in explicitly disposable test databases. Only this CLI test helper can
release the shared dispatch cooldown or age an invasion's active clock. The game
has no test or anonymous scheduler bypass.

```sh
php tests/world_activity_test.php
php tests/bot_activity_combat_test.php
node tests/world_activity_ui_test.mjs
```

These cover 152 presence/timer checks, 18 battle-clock checks and 40 client lifecycle
checks respectively. The complete ten frontend suites pass 765 checks, including
42 navigation/ranking checks. The earlier per-feature test descriptions below
remain useful; `docs/VALIDATION.md` records this release's actual reruns.

Use `MYSQL_TEST_HOST`, `MYSQL_TEST_PORT`, `MYSQL_TEST_USER`,
`MYSQL_TEST_PASSWORD`, and a disposable `MYSQL_TEST_DATABASE` ending in
`_activity_test` for:

```sh
php tests/world_activity_integration.php
php tests/world_activity_concurrency.php
php tests/world_activity_map_integration.php
```

Presence integration and forced process races pass 39 and 25 checks per database
engine. The race suite uses separate PHP processes to test shared dispatch and
multi-player/session presence. The map suite checks shield projections without
writing the underlying bases or clock. Inspect each test's database guard before
running; these scripts are not for installed game databases.

```sh
php tests/bots_activity_integration_test.php
```

The organic bot suite uses the same `BYM_BOTS_TEST_*` options as the five suites
below, requires `BYM_BOTS_TEST_RESET=1`, and recreates its disposable `_bots_test`
schema. Its 48 assertions include twelve real invasions launched from unmodified
starter armies/defenses, normal +25/-15 settlement, offline freeze and resume,
shared cadence, repeat-poll idempotence, and preservation of all 1,000 identities.
Test clocks are accelerated; damage, troops, resources, wins and points are not
injected. It is not a wall-time performance or long-duration balance benchmark.

---

# Regression tests — 0.8.0

Run commands from the project root with PHP 8.2+ and PDO MySQL. Database tests require disposable local schemas; do not point them at a player world. Some suites recreate their isolated schemas, while others roll back temporary game rows after creating the schema. Read each file's guard before running it. Node.js is required only for the authoring tests, not for the game.

Current observed results and platform limits are recorded in `docs/VALIDATION.md`. Counts there identify individual suites or non-overlapping groups; reruns are not additional assertions. Integrated HTTP/browser fixtures are authoring-only and are not distributed with the game.

## Pure PHP checks

These suites do not require a running database:

```sh
php tests/installer_test.php
php tests/progression_normalize.php
php tests/champion_progression.php
php tests/lab_progression.php
php tests/world_test.php
php tests/fortification_network.php
php tests/fortification_progression.php
php tests/champion_bonus_progression.php
php tests/construction_test.php
php tests/planner_test.php
php tests/combat_engine.php
php tests/combat_specials.php
php tests/combat_champions.php
php tests/lab_combat.php
php tests/world_combat.php
php tests/fortification_combat.php
php tests/champion_bonus_combat.php
php tests/network_combat.php
php tests/bots_planner_test.php
php tests/bots_world_planner_test.php
php tests/bots_fortification_planner_test.php
php tests/bots_network_planner_test.php
php tests/bots_construction_planner_test.php
```

The new construction and Yard Planner suites passed 104 and 226 checks. They cover paid worker gates/costs, distinct job occupancy, grandfathered parallel jobs, exact completion boundaries, geometry-only drafts, swaps, complete inventory validation, locked positions, Unicode names and stale revisions. The progression suites passed 217 fortification and 105 champion-bonus assertions. The eight combat suites passed 389 checks in total, including 46 individual fortification, 70 champion-bonus and 124 network checks. Coverage includes incoming-damage reduction, completed armor during the next job, disabled towers, source-scaled champion powers, independent Fomor aura/attack reach, exact hunger boundaries and old battle formats. All five bot planning suites passed 69,418 checks, including 23,023 construction/layout checks.

## Construction workers and Yard Planner with a database

Use the standard `MYSQL_TEST_*` settings described below. Construction suites
require a disposable database ending in `_construction_test`; Planner suites
require one ending in `_test`. The concurrency suites need CLI `proc_open` and
database access sufficient to observe their own clients in
`information_schema.PROCESSLIST`. They start actual independent PHP processes
and force home-row lock contention, rather than simulating concurrent calls.

```sh
php tests/construction_integration.php
php tests/construction_concurrency.php
php tests/planner_integration.php
php tests/planner_concurrency.php
```

Both MySQL 8.0.44 and MariaDB 10.4.32 passed 110 construction integration,
18 construction-race, 47 Planner integration and 17 Planner-race checks.
Coverage includes real payments, the shared build/upgrade/fortify capacity,
independent hatching/repairs, overload preservation, cancellation, owner-only
responses, persistent layout slots, exact-ID swaps, inventory changes during
planning and both orderings of a layout application racing an invasion. A worker
race cannot overbook the last slot or buy a sixth worker; a layout race cannot
commit a mixture of two arrangements. New construction and layout state is
preserved through the ordinary save paths.

## Progression and combat with MySQL or MariaDB

Set `MYSQL_TEST_HOST`, `MYSQL_TEST_PORT`, `MYSQL_TEST_DATABASE`, `MYSQL_TEST_USER` and `MYSQL_TEST_PASSWORD`. The account must be able to create tables in the selected disposable schema. Schema creation itself is not rolled back.

```sh
php tests/backend_test.php
php tests/progression_integration.php
php tests/champion_integration.php
php tests/lab_integration.php
php tests/combat_integration.php
php tests/combat_v2_integration.php
php tests/combat_champions_integration.php
php tests/lab_combat_integration.php
php tests/world_combat_integration.php
php tests/world_combat_concurrency.php
php tests/fortification_combat_integration.php
php tests/network_combat_integration.php
php tests/network_combat_concurrency.php
```

Use a database ending in `_test` for `fortification_combat_integration.php`. The world combat suites require a disposable schema without preseeded map coordinates; the concurrency suite also needs CLI `proc_open` to run independent PHP clients. The v0.7 combat database group passed 252 assertions on each engine: the existing 207, plus 31 network integration and 14 actual-process network-race checks. These cover persistent injuries and armor, two-day battle-clock pauses, unused champions, actual reservation timestamps, repeated settlement and unchanged format-5 timing. See `docs/VALIDATION.md` for which existing suites were rerun for v0.8.

The building-armor and champion-bonus progression suites require `MYSQL_TEST_DATABASE` to end in `_fortification_test`:

```sh
php tests/fortification_integration.php
php tests/champion_bonus_integration.php
```

They exercise real costs, capacity-limited refunds, paid timers, eligibility/locking, exact army meals, cooldowns, three earned tiers, maintenance, hunger, frozen persistence and preservation of injuries. The fortification suite's 47 assertions and ordinary progression's 56 were rerun on both engines with the new shared worker rules. The Lab fixture now completes its already-paid Lab construction before requesting a Catapult, rather than bypassing the one-worker limit; its 134 assertions remain unchanged. See the current verification record for the complete backend/champion/Lab and champion-bonus database runs.

## Installer and persistent-world fixtures

`installer_test.php` always runs its pure checks. Its optional database suite requires `MYSQL_TEST_*`, a disposable database ending in `_installer_test`, and explicit `BYM_INSTALLER_TEST_RESET=1`. It validates database-only installation of 1,000 bots, separate in-game registration, connection diagnostics, preservation, rollback and retries. The pure suite's 48 checks passed for v0.8; current database and integrated HTTP installation results are recorded in `docs/VALIDATION.md`.

`world_integration.php` recreates only a database ending in `_world_test`. Set `BYM_WORLD_TEST_RESET=1`, `BYM_WORLD_TEST_DATABASE`, and the standard `MYSQL_TEST_*` connection settings. It validates map allocation, resource conservation, paid territory management and overflow registration into a free Frontier cell.

```sh
php tests/world_integration.php
php tests/fortification_network_integration.php
```

The network integration suite requires a disposable `BYM_NETWORK_TEST_DATABASE` ending in `_network_test`. It explicitly recreates that schema and uses the standard `MYSQL_TEST_*` connection settings. It covers one-time neutral seeding, exact adjacency, separate capacities, zero economy/reach, paid management, map projections and dense Fortification scouting.

## Persistent bot integration

All five bot suites require explicit `BYM_BOTS_TEST_RESET=1` and a disposable schema ending in `_bots_test`. Connection options are `BYM_BOTS_TEST_HOST`, `BYM_BOTS_TEST_PORT`, `BYM_BOTS_TEST_DATABASE`, `BYM_BOTS_TEST_USER` and `BYM_BOTS_TEST_PASSWORD`. These suites deliberately rebuild their isolated database.

```sh
php tests/bots_integration_test.php
php tests/bots_world_integration_test.php
php tests/bots_fortification_integration_test.php
php tests/bots_network_integration_test.php
php tests/bots_construction_integration_test.php
```

The five suites passed 11,393 checks on each engine: 119 core, 50 territory, 11,109 individual fortification/champion, 67 strategic network and 48 construction/Planner checks. They verify paid bot armor, real champion food/powers, reserved meals, frozen/battle-locked individuals and all 1,000 persistent identities. New coverage includes actual paid worker and Planner purchases, shared capacity, continued independent development, bounded defensive layout improvements, cooldowns, battle exclusion and preserved building records.

Set `BYM_BOTS_FORTIFICATION_LOAD_TEST=1` to include the optional prepared 1,000-bot armor workload. Its three additional assertions check exact payment and queued jobs, no premature tiers/errors/raids, and preserved identity/rank hashes. It supplies Town Hall/Flinger prerequisites and exact funds; this measures scheduling and economy work, not organic progression or 1,000 simultaneous battles. This optional workload was not repeated for v0.8. The territory suite also has its earlier optional prepared Map Room workload; inspect its header before enabling it.

## Existing-world upgrades and restoration

`upgrade_test.php` requires a disposable schema ending in `_upgrade_test` and explicit `BYM_UPGRADE_TEST_RESET=1`. Connection options are `BYM_UPGRADE_TEST_HOST`, `BYM_UPGRADE_TEST_PORT`, `BYM_UPGRADE_TEST_DATABASE`, `BYM_UPGRADE_TEST_USER` and `BYM_UPGRADE_TEST_PASSWORD`.

```sh
php tests/upgrade_test.php
```

Its v0.8.1 run passed 298 assertions on each engine. The fixture directory retains the historical released schema 1 and 0.4.1 base exports, plus the exact released 0.5.0 schema 2 and worlds exported through frozen 0.5.0, 0.6.0 and 0.7.0 classes. Coverage includes 1,000-bot preservation, content-only migration, unchanged territory ownership/levels/state/revisions, active format-5/6/7 battles, no free fortification or champion bonuses, readable private JSON/SQL backups, exact restoration, no-op reruns, partial Fortification insertion rollback and recovery after an intentional post-DDL failure. The v0.7 fixture retains three already-paid parallel building jobs when the missing worker field defaults to one, receives empty layouts without a free Planner, and generates no new map sites. Existing purchased workers and private Planner fields also survive. Fixture provenance is in `fixtures/README.txt`.

`upgrade_memory_test.php` requires `BYM_UPGRADE_MEMORY_TEST_RESET=1` and `BYM_UPGRADE_MEMORY_TEST_DATABASE` ending in `_memory_upgrade_test`. It uses `MYSQL_TEST_*` connection settings, with `BYM_UPGRADE_TEST_*` fallbacks, and launches clean PHP child processes under a 128 MiB limit.

```sh
php tests/upgrade_memory_test.php
```

The v0.8.1 MySQL run passed 1,047 assertions with 67,373,870 bytes of history, 305 battles, 305 reports and 2,591 original sites from v0.7; migration added no Fortifications. It restored all seven original tables. Upgrade peak memory was 6 MiB; check/no-op peaks were 4 MiB. This is a destructive disposable fixture, never a command to run against an installed realm.

## Browser-module checks

```sh
node tests/renderer_test.mjs
node tests/champion_ui_test.mjs
node tests/fortification_ui_test.mjs
node tests/laboratory_ui_test.mjs
node tests/worldmap_ui_test.mjs
node tests/territories_ui_test.mjs
node tests/navigation_ui_test.mjs
node tests/planner_ui_test.mjs
node tests/workers_ui_test.mjs
```

The nine suites passed 714 checks: 447/29/31/36/52/26/31/46/16 respectively. They cover the 18 monster/champion movement and 18 attack/action sheets, worker movement/tool-use sheets, owner-only construction rendering, authoritative worker gates, isolated draft editing, undo/storage/load/apply, incomplete-layout rejection, navigation cancellation and stale responses. Existing coverage includes facing/combat state, fortification availability/refunds, champion statistics/recipes, preserved modal state, Lab/artillery actions and real territory IDs. These module tests complement the separate real HTTP and live browser checks; they do not emulate every browser or device.

### Regional Map Rooms

Use separate empty databases ending in `_region_test` with `MYSQL_TEST_*` for `php tests/regions_integration.php` and `php tests/regions_concurrency.php`. They cover regional settlement, independent coordinates, real invasions, defender networks, writable bookmarks, exact payments, invitation ownership checks, competing moves and simultaneous retries.

Use an empty `MYSQL_TEST_DATABASE` ending in `_moloch_test` with `php tests/moloch_siege_integration.php` for the independent event 3 defenses, cross-session receipts, property damage and tower awards.
