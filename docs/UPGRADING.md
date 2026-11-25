# Upgrade an existing world to 0.13.1

Keep your existing database and private `var` folder. **Do not run setup again, delete
that database, or replace `var/config.php`.** Setup is only for a new world and never
creates a player account.

Version 0.13.1 continues to use SQL schema 3. The 0.13.0 → 0.13.1 update changes branding, bundled client assets and the content version. Keep the database name stored in `var/config.php`; do not rename a populated database to match the new setup default. Rebuild the Windows client from this complete package so it contains the same 0.13.1 assets as the web game.

An installed 0.12.0 world already has the alliance, diplomacy, mail, friend and gift tables. The updater supports installed 0.1–0.13.0 worlds, including the 0.4.1 XAMPP repair. Existing 0.7, 0.8 and 0.9 maps keep every site and their completed Fortification marker.
Earlier maps missing the marker receive neutral Fortification sites on available
neighboring hexes once; older
schema-1 worlds also receive the original map migration. It preserves every
account, password hash, rank, win/loss record, bot, home layout, resource balance,
army, research queue, champion food bonus, individual-building fortification, paid
fortification job, owned territory, site upgrade, garrison, map coordinate and saved invasion. Version 0.8 adds one
standard worker and two empty layout slots only when their fields are absent.
Previously paid simultaneous build, upgrade and fortification jobs keep their exact
timers and finish normally, even while their count exceeds the worker capacity.
Only new jobs wait for capacity. No extra workers or Yard Planner building are granted.
Existing purchased workers and saved layout fields remain unchanged. Existing invasions retain their
saved combat rules. A saved active invasion does not block an offline upgrade; it
is preserved exactly, without cancellation. The busy-world check rejects a running
world-worker process, not paid building timers. Apache/the HTTP server must remain
stopped throughout the migration.

The automatic activity introduced in 0.8.1 still uses human presence to pause automatic bot activity when nobody
is online. Applying the upgrade does not start the simulation, create online
sessions, catch up old bot turns, or shift saved deadlines. An absent world activity
clock receives an empty paused epoch in the upgrade transaction, so the wait before
the first login cannot become bot catch-up time. Per-base/site/battle anchors are
initialized lazily during normal gameplay. Existing private `world_activity_v1`
and `bots_schedule_clock` settings, base/site/battle clock anchors, and custom
saved fields are preserved exactly by the updater. No presence table or account
migration is required. A stale saved presence lease expires through normal runtime
TTL handling; the updater does not rewrite it.

The 0.12.0 → 0.13.0 update keeps the existing SQL schema at version 3. New gameplay records are created through normal play: the Inferno entrance can be earned or purchased, event progress and companion loans must be earned, and bought decoration, siege and alliance inventories retain their owners. Old-format fights keep their frozen state and finish through the normal battle service. First installation and updating are separate entry points.

Opening a Frontier or Highlands adds a separate region to schema 3. The updater checks the original Home Valley resource and stronghold anchors separately, and preserves regional site ownership, converted outposts and relocated homes. A healthy map is skipped during initialization. The verifier streams saved communication and event settings alongside the other backed-up records.

Version 0.10.0 adds the Wild Monster Baiter to the construction catalog. Build it in your existing yard after the upgrade.

Version 0.9.0 made the Aerial Defense Tower and Heavy Trap available through ordinary paid home construction. Existing yard layouts, saved invasions and damage retain their saved state during upgrade.

Version 0.8.2 introduced free, instant home-building repair during normal play. **The
updater does not repair any building.** Damaged and destroyed home structures,
active invasions, unfinished construction and paid upgrade/fortification work
remain exactly as saved. After the invasion is finished, a player presses
**Repair base** at home; bots use the same free repair during their next eligible
online turn. Neither installing the release nor rerunning this updater grants
health, resources, troops or rank points.

## XAMPP

1. Stop Apache in the XAMPP Control Panel and stop the world worker. **Leave MySQL
   running.** Copy the existing project folder somewhere private as an application
   backup. An additional SQL export through phpMyAdmin is recommended.
2. Extract the new release and copy the existing `var` contents into its `var`
   folder. Preserve `config.php`, sessions and backups. Alternatively, replace the
   application files in place while leaving `var` intact. Keep the previous release
   until you verify the upgraded game.
3. Double-click `UPGRADE-WORLD.cmd` to run a read-only check. It finds PHP from a
   containing XAMPP installation, including portable installations on other drives.
   You can also open a command prompt in the project folder and run:

   ```console
   UPGRADE-WORLD.cmd --check
   ```

4. With Apache and the worker still stopped, apply the upgrade:

   ```console
   UPGRADE-WORLD.cmd --apply
   ```

5. Check that the command says **Upgrade complete**, then restart Apache and use
   your normal localhost URL. Sign in with your existing account. The game
   automatically runs the bots while a signed-in human keeps the game open;
   a separate world-worker window is not required.

The updater does not start another HTTP server or database. To select PHP explicitly,
set `PHP_EXE` to your `php.exe` path. If using the separate PHP development-server
launcher, stop it during the upgrade and restart `START-LOCAL.cmd` afterward.

On Linux/macOS, or with PHP already on PATH, the equivalent commands are:

```console
php bin/upgrade.php --check
php bin/upgrade.php --apply
```

The `Update-from-0.12.0` ZIP is for an existing 0.12.0 installation. Older versions
should use the full release, retain their private `var` folder, and run the same
upgrader. Do not apply a version-specific changed-file ZIP to a different baseline.

## What the updater does

The check validates the installed tables, all saved base JSON, matching user/base
identities and supported versions, then reports the existing player/bot/base/site counts and whether one-time
Fortification generation is needed. `world_sites` and `fortifications` are actual
counts, not a fixed expectation of 1,321 sites.
It writes no files or database rows.

Apply writes **both a JSON audit snapshot and a restorable SQL backup** to private
`var/backups/` before issuing any schema changes. Each backup is fully flushed and
renamed into place first; the completed report includes paths and SHA-256 checksums.
The snapshots include every game table present before the migration and its table
definition, including a partially created `world_sites` table if resuming.

For older schema-1 installations only, additive SQL steps allow neutral battle defenders,
add target columns/indexes, and create `world_sites`. Schema-2 worlds receive nine additional tables for alliances, war scoring, friendships, sacks and correspondence. MySQL and MariaDB commit DDL implicitly. **These schema
steps cannot be rolled back as one transaction.** If a later step fails, already
completed schema additions remain; the updater reports this clearly. Each step
checks the current schema, so running `--apply` again resumes without adding duplicate
columns or resetting data. Keep the first pre-upgrade backup as well as any later
retry backups.

History verification and both backup formats stream ordered rows with database
client buffering disabled. Base normalization uses small batches. The updater does
not load an entire battle/report history or construct a whole backup string in RAM,
and does not increase PHP's memory limit.

After the schema is ready, one data transaction normalizes missing compatibility
fields and initializes the map only where records are missing: 256 Resource Outposts,
64 Strongholds and one home marker for each account. It then adds neutral forts
only to unoccupied neighboring cells of Resource Outposts and Strongholds, after
all existing homes are allocated, without consuming the final 512 free map cells.
An already denser world receives no new forts. Some rings
are partial; the updater does not move existing yards or add automatic home rings.
The completion marker `world_fortifications_version = 1` commits with these rows
and prevents later regeneration. The apply report gives the actual number of new
forts and total map sites. It verifies every pre-existing
site row, including ownership, level, full state, coordinates and revision, alongside
preserved account/battle data, then records content `0.13.0` and schema `3`. A failure in this stage rolls back
its data changes and leaves the old completion version. No accounts are seeded or
replaced by the updater: an existing population of 1,000 bots remains 1,000.

Existing earned fortification tiers, champion food powers and paid timers remain
unchanged. No new tiers, powers, Map Rooms, Yard Planners, extra workers, territory ownership, monsters, resources
or rank points are granted. New Fortification sites are neutral world records,
not additional bots or player accounts. For older missing fields only, building
fortification fields start at zero/null.
New champion `bonus_feeds` starts at zero, even if an old `bonusFeeds` maintenance
counter is present; the old field is preserved without turning earlier meals into
unearned power. Existing zero-health champions stay at zero, and frozen or active-battle
state is preserved. Old worlds missing strategic income/capacity receive zero defaults.
Buy a Map Room at Town Hall level 3 to start conquest. Older missing Lab/champion/monster compatibility fields are added
without completing timers, healing champions, advancing research, or accruing elapsed
production. Existing custom saved-state fields remain intact.

A current world with the completed generation marker is a no-op: rerunning `--apply` makes no changes and creates no
redundant backup. If the application detects schema 1, it asks you to run this updater
instead of querying map columns that do not exist.

## Recovery

Backups contain private account hashes, base data and authentication records. Keep
`var` private; Apache access is denied by the shipped protection files. On POSIX
systems the updater uses directory mode `0700` and file mode `0600`. On Windows,
restrict access to the project/backup directory to your account.

The generated `.sql` file deliberately contains no `DROP DATABASE` or `DROP TABLE`.
To restore, stop the game and worker, create a **new empty database** in phpMyAdmin,
select it, and import the SQL file. Use the matching previous application release
and update its private config to the restored database. Verify it before replacing
or removing anything. The `.json` file is for recovery/audit tooling; do not import
it as SQL. A folder copy alone does not back up MySQL.

The updater refuses unsupported/newer versions, malformed base JSON, mismatched
accounts/bases, missing/non-InnoDB tables, mixed application versions, and a busy
scheduler. Stop all HTTP requests and workers before applying: the scheduler lock
cannot stop a separate old application that is still serving requests.

## Regression coverage

`tests/upgrade_test.php` uses the exact shipped schema-1 fixture from 0.4.1, with
1,000 bots and one human. It checks direct compatibility from old content versions,
private backups, byte-for-byte SQL restoration, persistent map initialization,
account/rank/base/battle preservation, no free buildings or accrued resources,
idempotence, malformed-data rejection, and safe resumption after an intentionally
failed post-DDL map initialization. The test requires a disposable database whose
name ends in `_upgrade_test` and explicit `BYM_UPGRADE_TEST_RESET=1`.

The updater also accepts 0.8.0, 0.8.1, 0.8.2, 0.9.0, 0.10.0, 0.11.0 and 0.12.0 directly. The regression fixtures check existing worker
capacity, both named Yard Planner layouts, paid jobs, rank records, private activity
leases, scheduler baselines, and base/site/battle clock metadata. It verifies that
check, apply and repeated apply never dispatch bots or advance saved clocks. An
intentional settings corruption triggers a full data rollback. Both backup formats
retain the private clock state, and SQL restoration reproduces all seven original
tables exactly. The 0.9.0 baseline case additionally verifies destroyed and damaged
human/bot buildings remain unrepaired, all paid timers remain unchanged, no bot
turn or saved invasion advances, and all 1,001 complete bases retain every field
except content version. See `VALIDATION.md` for current release execution results and platform coverage.

Historical v0.8.1 verification: **298 upgrade assertions passed on both MySQL 8.0.44 and MariaDB
10.4.32**. Its MySQL large-history run passed **1,047 assertions** with
**67,373,870 history bytes** across 305 battles and 305 reports under a **128 MiB**
PHP limit. Check and no-op peaked at **4 MiB**; apply peaked at **6 MiB**. All
**2,591** original map sites survived, no Fortifications were added, and the SQL
backup restored all seven original tables exactly.

Historical v0.8 verification: **247 assertions passed on both MySQL 8.0.44 and
MariaDB 10.4.32**. Its 128 MiB large-history run passed **1,046 assertions** with
**67,373,870 history bytes**, a **6 MiB** apply-process peak, all **2,591** map sites
preserved, no new Fortification sites, and exact SQL restoration of all seven tables.
Those figures describe the earlier release; the hotfix adds clock preservation tests.

The v0.7 updater passed **189 assertions on both MySQL 8.0.44 and MariaDB
10.4.32**. The suite loads records exported through the actual frozen v0.6
release, including paid second-tier fortification work, completed building armor,
earned champion food bonuses, owned sites, and a deployed format-six invasion.
It checks the old map/history byte-for-byte, permits only new neutral fort rows,
exercises an intentional generation failure with complete data rollback, and
verifies idempotence and SQL restoration of all seven original tables.

`tests/upgrade_memory_test.php` uses at least 64 MiB of saved battle/report history,
1,000 bots, one human, owned territories and an active format-seven invasion under a
128 MiB PHP limit. It verifies streaming backups, expected normalization of the
full population, preservation of every pre-existing map row, zero fort regeneration
for the completed v0.7 map, and exact SQL restoration of all seven original tables. It requires
explicit `BYM_UPGRADE_MEMORY_TEST_RESET=1` and isolated
`*_memory_upgrade_test` databases.

The v0.7 MySQL run passed **1,046 assertions** with **67,442,381 history bytes**
across 306 battles and 306 reports. Check and no-op processes peaked at **4 MiB**;
apply peaked at **6 MiB**. It preserved all 1,321 original sites, added 1,270 neutral
forts, reported 2,591 total sites, and restored all seven original tables exactly
from the SQL backup. These counts describe the test fixture; other saved worlds
receive the number of forts that fit their actual vacant neighboring hexes.

Historical v0.6 verification: 136 upgrade assertions passed on both MySQL 8.0.44
and MariaDB 10.4.32. Its large-history suite passed 1,045 assertions with 67,541,303
history bytes, a 6 MiB apply-process peak and exact SQL restoration of all seven
original tables. These are prior-release results, not claims about the expanded
v0.7 suite. Current release results are recorded in `VALIDATION.md` after testing.

Historical v0.5 verification: the updater passed 76 assertions on each database
engine. Its separate schema-1 history fixture passed 1,042 assertions with
67,504,825 history bytes and 6 MiB apply-process peak memory. Those older numbers
are not the results of the expanded v0.7 suite.

See `VALIDATION.md` for the remaining release test results.
Native Windows CMD execution must be verified on Windows; the launcher uses the
same portable PHP discovery as the XAMPP launchers.
