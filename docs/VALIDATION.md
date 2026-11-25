# Verification of release 0.13.1

This Rehatched branding and client synchronization update was checked on Linux with PHP 8.4.27, Node.js 24.22.3 and an isolated MariaDB 11.8.9 instance.

| Scenario | Verified result |
| --- | --- |
| Browser source | All 205 PHP files passed syntax validation. 64 independent PHP/JavaScript suites passed, including all 110 navigation UI checks. The navigation harness now accepts the source archive's Windows line endings. |
| Session and setup identity | Twelve real HTTP checks passed in a temporary application copy. Fresh setup uses `backyard_rehatched`; legacy authenticated sessions retain their user and CSRF token while receiving the current cookie. Current cookies take precedence, invalid legacy IDs are rejected, and page/header titles and cache versions match the release. Run `python tests/branding_http_test.py` with PHP 8.2+ on PATH, or set `PHP_EXE`. |
| New installation | Installer pure configuration/diagnostic checks passed (50), followed by 39 database checks covering setup, 1,000 initial bots, human registration, saved configuration, rejected nonempty databases and recovery after a failed seed. |
| Existing worlds | The upgrade suite passed 1,354 checks, including the explicit 0.13.0 → 0.13.1 path. All 1,001 saved base payloads retain every field except the content-version stamp; users, map sites, battles, reports, authentication records and the selected database name remain unchanged. Repeat apply is a no-op. The suite also verifies earlier supported upgrades, rollback behavior and SQL backup restoration. |
| Bot regressions | Six database suites passed: 48 activity checks, 55 construction/planner checks, 11,109 fortification/champion-bonus checks, 119 general bot checks, 67 network checks and 50 world checks. They use disposable schemas and preserve the normal game source. |
| Browser/client pack | `python tests/rehatched_sync_test.py` checks every browser/client asset path, SHA-256 digest, manifest entry and release marker. See `REHATCHED-UPDATE.txt` for the final pack counts and `Backyard-Monsters-Client-Source/BUILD-VALIDATION.txt` for .NET build/publish coverage. |

The database runs use Linux MariaDB, not the native Windows XAMPP Control Panel. Windows GUI execution, Windows prerequisite installation and live multiplayer sessions were not exercised here. The earlier release records below retain their original test dates and scope; their claims are not a substitute for this release's checks.

## Previous release records

# Verification of release 0.13.0

| Scenario | What ran |
| --- | --- |
| Fresh local installation | Apache, PHP 8.2.32 and MariaDB 10.4.32 installed the world using a loopback connection, root and an empty database password. A rejected login and a successful probe were exercised. The installer created 1,000 distinct bot keepers, 1,000 yards, zero human accounts and zero starting ranks. Registration happened afterward in the game. |
| Online and offline activity | With normal clocks and browser heartbeats a bot won an autonomous raid after 43.4 seconds, appeared in the visible rankings, and four bot clans formed. With the last player offline, a worker tick did no work. Bot ranks, battles and memberships stayed still during the idle interval. A returning tab woke the world. |
| Existing installation | A world from the published 0.12.0 package acquired a keeper and an in-flight raid. The CLI preview, apply and repeated apply preserved all 1,001 saved yards, identities, rank records, map allocation and the saved battle. The same keeper resumed the fight. The updater produced a restorable SQL backup and JSON audit. The populated-region regression also exercises a claimed Frontier cell, an occupied Highlands, a subsequent migration and repeated no-op apply. |
| Database matrix | Eighty database-backed suites passed on PHP 8.2.32/MariaDB 10.4.32 and again on PHP 8.4.24/MariaDB 11.8.6. This includes competing relocations, active field invasions, Inferno raider-loot settlement, world chat and keeper/bot Hall upgrades. A separate large-history exercise included 67,373,870 bytes of prior history and a measured 10 MiB peak for applying the upgrade. |
| Map and combat | Chromium moved a keeper into another region, captured a headquarters, placed a building on the field canvas and purchased the 113-piece kit. Database checks also exercised the 148- and 171-piece kits and deployed real creatures from field inventory. All three HQ damage appearances loaded over HTTP. |
| Progression and events | A real canvas purchase queued a Hall 5 → 6 upgrade for 75,600 twigs and pebbles each. Real database jobs reached Hall 10 for both a keeper and a bot. A keeper added three land expansions and placed a building outside the old yard edge. Hell-Raisers #3 survived reload and awarded functioning home and field Magma Towers. A joined weekly Blitzkrieg defense credited standings once and leaving restored the private campaign. |
| Social and language | Chromium tested localized sign-in, switching languages without changing a keeper name, searching the French catalog, and browsing on mobile. Two players exchanged realm and alliance messages. Muting, unmuting, browser reload and preserving an unsent alliance draft were exercised. A referred registration, earned Shiny and Inferno quest payouts persisted. |
| Art and sound | Chromium decoded 108 new action and defeat sheets and validated 432 bearing/action selections and 216 distinct defeat frames. A real Wild Monster Invasion played four defeat poses and one loss voice for an Octo, and repeated updates stayed silent. All 64 stereo creature voices decoded. All 35 standard music/effect files passed float-sample decoding with zero clipped samples. |
| Producer machinery | All four producer types and the Hatchery/Incubator in both realms were drawn at five tiers apiece. Chromium saw paid eggs start and cancellation stop the hatchery mechanisms. Frame comparisons across all 50 appearances found motion inside their registered component patches and zero changed pixels outside. |

These runs used Linux. Native Windows XAMPP Control Panel behavior, Windows filesystem ACLs, and a production server with many simultaneous human connections remain unverified. [Original game comparison](PARITY.md)

## Previous release records

# Verification of release 0.12.0

| Feature | Observed in this release |
| --- | --- |
| Setup and active population | On a fresh Apache installation, the local setup form probed invalid and valid database credentials and created 1,000 distinct bots with saved yards. No player was created by setup. A human registered from the game and joined the same zero-start leaderboard. During one unaccelerated run a bot earned 25 rank points after 37.8 seconds. Four clans formed. Sign-out froze the rankings, active bot battles and clan memberships. A fresh login woke the same world. |
| Shipped upgrade path | The previously published 0.11.0 world acquired a human keeper and an in-progress deployed raid. The real check, apply and repeat-apply operations preserved its 1,001 yards, identities, rank records, map ownership and battle state. The yard comparison permits only the content version and new default roster entries. The same browser session finished the old raid and used the new mushroom worker. SQL and JSON backup files were produced. |
| Data volume | A separate test used 67,373,870 bytes of existing battle and report history. The updater passed 1,047 assertions per stack with an 8 MiB peak for the apply subprocess. |
| Underground | A fresh settlement hatched, upgraded and raided independently of surface resources. Eight starting Spurtz cleared the first of the 34 original camp layouts. Two browser sessions completed a ranked cavern invasion with chosen-coordinate deployment, spectating, reload, +25/−15, loot and free repair. Bots funded their own entrances, grew armies and fought ranked raids during online turns. The Compound released native defenders and settled losses once. After 13 Descent clears, a real Ascension transfer moved a Spurtz into surface Housing and deployed it in a surface raid. The closed and open portal artwork loaded successfully when a keeper entered and returned through the live canvas entrance. |
| Defenses, appearance and yard work | Tested the advanced tower families, bought working utilities and placed signs for another keeper. Purchased a decoration, placed it, stored it and placed it again using the live browser. Ordinary mushrooms and Halloween mini pumpkins use real workers and reload-safe harvest receipts. A qualified keeper opened the gift horse, restored the session after reload, saw the waves arrive and repaired the resulting damage for free. |
| Events and champions | Brukkarg, Monster Blitzkrieg and Creature Carnage ran through the normal battle system, and verified rewards can be used afterward. Separate canvas clicks sent Gorgo and Krallen into the same ranked raid, restored both after reload, and settled injuries. Mobile controls worked at 390px. Alliance Armament, Conquest and Declare War were exercised through the authenticated gameplay controls. |
| Visuals and audio | Native Chromium exercised 216 combinations of ordinary/Inferno species and heading, plus animation and reduced motion. It drew four different frames for each of 26 playable champion appearances. Special-event music was decoded and played on a real Web Audio context, looped and crossfaded to the home theme. All 34 shipped audio files decoded with zero clipped samples. |

All 172 distributed PHP files and all 36 top-level frontend JavaScript modules passed syntax checks. The 41 pure PHP suites and 23 JavaScript suites passed. The release passed 68 database suites on each stack, totaling 20,527 numbered assertions per stack plus both truce lock-order scenarios. This includes 75 Creature Carnage checks, 61 Hell Raisers checks and 17 Wild Monster Invasion checks. A separate rules suite checked both invasions' original group and direction schedules. Chromium exercised the 999-XP Rezghul purchase after thirteen successful attack sessions, and verified the remainder persisted into a replay. The 90% combined damage requirement was checked on both sides of the threshold.

An actual paid Town Hall upgrade kept the old building appearance during construction and displayed the new appearance on completion. A second player hit the upgraded Hall with Catapult ammunition at its selected tile, and the defender restored its full shape with free home repair. A paid Under Hall upgrade similarly survived the return trip to the surface. The release includes 219 level-specific WebP files for 36 upgradeable surface building types and 16 native building types. Both realms use the same 16 advanced tower illustrations. Chromium 150 fetched and drew all 219 on demand without an HTTP or JavaScript error. Each grade differed visually within its facility line. Damage and repair followed the grade silhouette, and the Wild Monster Baiter and Quake Tower retained it through their moving frames.

On the same 1,400 × 920 Chromium fresh-yard scenario, image response bodies fell from 57,538,618 bytes before the asset-loading pass to 9,331,244 bytes. The final run loaded the visible yard and troop art, then requested other appearances as they came into view. Twenty-six guardian appearances passed all 104 frame checks on this build.

The two database stacks were PHP 8.2.32 with MariaDB 10.4.32 and PHP 8.4.24 with MariaDB 11.8.6. Apache used the defaults in the Windows XAMPP guide, including port 3306 and the local root account with an empty password. These runs used Linux. A native Windows XAMPP Control Panel installation and Windows filesystem permissions were not exercised.

See [the source comparison](PARITY.md) for historical rules and presentation still outside this release.

---

## Historical verification records

# 0.11.0 verification

| Scenario | Observed result |
|---|---|
| Database-only installation | The local HTTP installer successfully tested its connection and installed a fresh world with 1,000 distinct bot accounts and 1,000 saved yards. No human account was created. Registration was completed afterward in the game. Invalid credentials produced a useful setup error. |
| XAMPP settings | Apache 2.4.68, PHP 8.2.32/PDO MySQL and MariaDB 10.4.32 ran the installer and gameplay on Linux. Tests used 127.0.0.1, port 3306, root and an empty password. The Windows XAMPP Control Panel, Windows filesystem permissions and batch launchers still require native Windows verification. |
| Existing 0.10.0 world | The published 0.10.0 ZIP was installed and a human keeper started a ranked raid. Checking, applying and repeating the upgrade preserved 1,001 identities, standings, world assignments and the saved battle bytes. The same keeper resumed and finished the old invasion. Both backup formats were created. |
| Online bot behavior | With normal clocks and an unmodified fresh world, browser presence produced the first bot victory after 32.5 seconds in one run. The winner appeared at the top of the ordinary ranking table with 25 points. Bot schedules, ranks and battle states stayed still through a 15-second empty-server interval. Returning resumed the same world. In the final build a second run produced four bot-led clans within 10.7 seconds and a first win at 39.1 seconds. Timing varies by world. |
| Real gameplay in Chromium | Desktop and mobile sessions registered two keepers, visited bases, opened the quest journal, collected an award, joined an alliance, transferred leadership, exchanged a mystery sack and read private mail. The first Wild Monster Tribe raid accepted a canvas click, resumed after reloading and returned home. |
| War and home repair | Two leaders accepted a war. The scoreboard advanced once for a ranked fight. The defender's damaged home recovered immediately via Repair base on mobile. Its four resource balances stayed identical. Both players could still visit after accepting a 14-day truce, and the game blocked further invasions between them. |
| Chaos equipment | Browser tests assembled and deployed the Chaos devices against actual game yards. Decoy and Candy Jars used ground clicks; the Loot-O-Tron attached to a keeper's Town Hall. Saved fights resumed with the spent device recorded. Advanced test fixtures supplied prerequisites and moved construction/research deadlines forward; separate tests measured fresh-world bot progression at normal speed. |
| Campaign rules | The 21 camps derive from the supplied tutorial and four tribe template sets. A fresh keeper cleared the opening camp with normal Flinger deployment. Tests used server-clock fixtures for combat timing and verified first-clear rewards, replays, partial storage and independence from ranked player/bot invasions. |
| Assets and sound | SHA-256 checks cover every deployed entry in the art manifest. All 32 shipped audio files decoded to stereo PCM at their documented durations. None of the decoded samples clipped. |
| Existing-world migration | A separate upgrade with 1,001 homes, 305 saved battles and 305 reports processed over 67 MB of history under a 128 MiB limit. The SQL backup restored the original tables. Previous release checks below are historical records. |

The full rules pass ran 77,726 PHP assertions across 32 database-independent programs and 989 JavaScript assertions across 15 programs. The release also passed its PHP and JavaScript syntax checks. All 47 database programs passed on PHP 8.2.32 with MariaDB 10.4.32 and on PHP 8.4.24 with MariaDB 11.8.6. Each stack passed 17,739 database checks, including two truce race scenarios. Browser and upgrade checks also exercised the running application.

The bot workshop integration started with 1,000 empty Chaos inventories. A scheduled eligible bot built and financed both facilities, researched and manufactured Candy Jars, and consumed one device to encase a tower in a real bot-versus-bot invasion. A separate fresh install started with zero alliances. Real online bot turns founded four rival clans. An offline offer stayed pending until an active turn could accept it. A human player could score in the resulting alliance war through normal combat.

The original game's remaining content is listed in `PARITY.md`. Its counts distinguish original reference definitions, playable types in this build and reserved slots.

# Verification record — 0.10.0 Wild Monster Baiter

| Check | Observed result |
|---|---|
| PHP | PHP 8.4.27 passed 76,072 assertions in 28 suites. All 90 PHP files passed syntax checks. The new suites include 48 checks of actual wave combat and 42 of practice sessions and paid building progression. |
| Wave controls and combat | The tests exercised 14 species, musk budgets, all eight approaches, three-second countdown, towers including anti-air fire, traps, bunker defenders, champion participation and the siren. A repeated start/siren or stale request from another tab preserved the current session. |
| Frontend | 899 assertions passed across 11 Node suites, including 492 renderer checks, 89 navigation checks and 41 presence checks. All 15 shipped JavaScript modules passed syntax checks. Coverage includes retained draft selections, siren retreat, duplicate and stale replies, reload, account changes, and real invasion priority. |
| Drawing | The production PHP engine supplied snapshots to the shipping JavaScript renderer on native Skia Canvas. Its eight direction markers appeared at the expected projected entry points. All four nozzle poses produced different pixels. The test yard held one Pokey and an 11-monster wave caused 54.5% practice damage. A separate active wave ended on the siren. The original home payload remained identical. |
| Upgrade compatibility | Frozen 0.9.0 and current PHP code ran in separate processes. All 1,001 home fixtures preserved every field except the content version. Two saved invasions, including one with a mid-volley Aerial Defense Tower and spent Heavy Trap, produced identical states through settlement. |
| Installer | A clean local PHP HTTP test verified the redirect, 0.10.0 footer, database-only form, XAMPP defaults and missing-driver diagnostic. |
| Assets | The game contains 90 PNGs and 28 audio files. The new art includes a transparent 1,254-pixel-square building portrait and four spray poses. Both new 44.1 kHz stereo WAVs decoded with zero clipped samples. |

Native Windows XAMPP installation, database-backed combat, SQL upgrade/restore and live browser interaction were unavailable here. The environment supplied a PHP CLI but lacked PDO MySQL and a running database server. The separate installer diagnostic suite needs the PDO exception class. `tests/baiter_integration.php` supplies the database-backed scenario for an empty disposable `_baiter_test` schema. The frontend checks exercise browser code with fixtures, and the Skia run exercises actual raster drawing using the game’s assets.

Earlier records below describe their respective versions.

# Verification record — 0.9.0 yard defenses

| Check | Observed result |
|---|---|
| Advanced defenses | PHP 8.4.27 passed 44 checks of tower bursts, damage scaling, reload, air and ground targets, trap triggers, falloff, eligibility during construction, hidden positions and rearming. The existing combat suites passed 389 checks. |
| Bots and repair | Five PHP bot planning suites passed 69,474 checks across 1,000 IDs. Free home repair passed 44 checks including both new building types. |
| Saved homes and invasions | The old code exported 1,001 home payloads. The new code preserved every field except the content version and normalized each home a second time with identical results. A saved format-seven invasion matched the old engine at all six checkpoints, including settlement. |
| Renderer and controls | 841 checks passed in 11 Node suites. All 14 browser modules passed syntax checks. A separate run sent real PHP combat snapshots through the client renderer with a recording Canvas. Three flak shots and the Heavy Trap blast produced their effects and audio cues. Repeating the poll produced no duplicate effect. |
| Artwork and audio | Both new 1,254 × 1,254 RGBA illustrations decoded. The two original stereo WAV cues decoded at 44.1 kHz, with zero clipped samples. The full game contains 88 PNGs and 26 audio files. |
| Installer | A PHP HTTP smoke test verified the local setup redirect, database-only fields, XAMPP connection defaults and the missing-driver diagnostic. Database creation requires PDO MySQL and a running database. |

PHP syntax checks passed for all 85 distributed PHP files. The corrected planning tests use a fixed time to exercise Map Room readiness at the same instant throughout each decision.

This environment supplied a PHP CLI but lacked PDO MySQL and a database server. The standalone installer diagnostic suite stopped at its PDO exception fixture. Database-backed construction, bot scheduling, settlement, upgrade/restore and Windows XAMPP installation were not executed for this release. The included `advanced_defenses_integration.php` exercises paid construction through battle settlement and free trap rearming on a disposable database. The Chromium launch was blocked by a runtime socket restriction. The client results above come from executing the shipping JavaScript with UI and Canvas fixtures.

Earlier records below describe their respective versions.

# Verification record — 0.8.2 free home repair

This release changes home recovery only. All 86 PNG assets and 24 audio files
are unchanged from 0.8.1. The existing online-only bot scheduler and combat lock
ordering are retained.

**PHP, PDO MySQL, MySQL/MariaDB and an Apache/PHP runtime were not available in
this authoring environment.** No new PHP lint, pure PHP, database, updater, HTTP
or live-browser pass is claimed for 0.8.2. The configured signed package mirror
was unavailable, so the missing runtimes could not be installed. No package
signature checks were bypassed. The prior release records below are historical
and do not establish that the new server code has passed those checks.

The backend and updater changes received a separate source review. The repair
operation is inside the existing authenticated owner transaction, uses the same
home lock and active-battle rejection as other yard changes, and modifies eligible
building HP only. Bot recovery calls that same action on an ordinary online turn.
The updater changes the supported target version without invoking repair or
rewriting saved activity clocks. These are source-level findings, not runtime
verification.

New PHP tests are provided for HP-only recovery across home building types,
zero-resource and repeat repairs, construction and fortification preservation,
owner-only status, real invasion damage/settlement, online/offline bot behavior,
and separate-process repair/invasion contention. Existing construction,
fortification, bot and upgrader fixtures were adjusted for the new behavior.
All remain unexecuted in this environment; commands and disposable-database
guards are in `tests/README.md`.

The frontend passed **821 Node assertions across 11 suites**: repair 41,
navigation/main integration 57, renderer 447, champions 29, fortification 31,
laboratory 36, Planner 46, territories 26, workers 16, world activity 40 and world
map 52. All **14 browser JavaScript modules** passed Node module syntax checks.
The new coverage exercises the actual main.js snapshot/render path, duplicate
clicks, paid jobs, busy/foreign/battle views, failed requests, and stale overlapping
Collect/bootstrap responses. The tests use VM/DOM stubs; they do not replace a
live PHP/browser run. A separate review caught and resolved overlapping-response
races before these final checks. No new screenshot or live browser execution is
claimed.

Packaging checks passed: the full release contains 296 files and the patch has
45 changed/new files. Applying the patch to the frozen 0.8.1 baseline produces
the same file bytes as the full release. ZIP CRCs, duplicate/path checks and
extracted hashes passed; all 86 PNG and 24 audio hashes match the baseline.
Only `var/.htaccess` is shipped in the full private directory, and no private
`var` files are included in the patch. None of the 83 PHP files is represented
as syntax-tested in this release.

---

**Historical verification follows.** Every older result belongs to its named
release and is not a new 0.8.2 result.

# Verification record — 0.8.1 automatic online bot activity

This is an activity and compatibility hotfix. The 86 PNG assets and 24 audio files
are unchanged from 0.8.0. It does not claim additional original-game parity.
Database suites use PHP 8.4.27 with MySQL 8.0.44 and MariaDB 10.4.32 in isolated
schemas. Accelerated clock fixtures never inject combat damage or ranking points.

| Check | Observed result |
|---|---|
| Presence and paused timers | 152 pure checks; 39 integration and 25 forced process-race checks on each engine. Covers multi-tab/session leases, exact 45-second expiry, shared dispatch, cold first login, timer preservation, logout and private public status. |
| Genuine bot ranking progression | 48 new integration checks on each engine. Twelve invasions from the real 1,000-bot seed used unmodified starter armies and defenses; all settled through the combat engine with ordinary +25/-15 points, wins/losses and reports. All 1,000 identities remain unchanged. Test clocks were accelerated, so this is not a first-win latency benchmark. |
| Bot combat clock | 18 pure checks; integration covers bounded progression, old-battle adoption, offline polling without mutation and repeat-poll award idempotence. Human-origin raids retain their wall-time behavior. |
| Existing bot gameplay | All five existing suites pass 11,393 checks on each engine. Their fixture human now opens a real presence lease. No optional full-population performance benchmark was repeated. |
| Map shield projection | Five integration checks on each engine. Bot home markers match paused authoritative shields; human expiry remains unchanged; bulk map reads do not rewrite bases. |
| Invasion concurrency repair | A forced MariaDB race reproduced overlapping site/home reservations after the new clock reads. Acquiring every participant base lock before any consistent read fixed it. Both engines then passed 185 core/network combat checks, including ten world and fourteen network process-race checks; no assertions were weakened. The prior five map-clock checks also pass. |
| Existing-world upgrade | 298 checks per engine. Preserves all existing clock metadata and settings verbatim, 1,001 complete base payloads except content version, all 1,000 bots, purchased workers, named layouts, paid jobs and the seven tables. Missing activity clock initialization is paused and transaction-safe; check/no-op paths remain read-only. |
| Bounded-memory upgrade | 1,047 MySQL checks under 128 MiB. Check/no-op peaked at 4 MiB and apply at 6 MiB; 67,373,870 history bytes, 305 battles, 305 reports and 2,591 map sites preserved. All seven tables restored exactly from the SQL backup. |
| Frontend | 765 Node assertions across ten suites, including 40 activity lifecycle and 42 navigation/ranking checks. Covers hidden tabs, page departure, reconnect, BFCache return, stale/account responses, CSRF keepalive and rankings refresh without losing page selection. |
| Apache HTTP | 822 checks passed through Linux Apache/PHP 8.2/exact MariaDB 10.4.32: all 568 prior checks plus 254 activity checks. Real authenticated HTTP pulses performed 42 persistent AI turns without a CLI worker. Multiple players/tabs, CSRF, invalid methods, logout, last departure, expiry/reconnect, cadence, privacy and zero final leases are covered. |
| Remaining database regressions | Fifteen suites pass 3,225 checks per engine: backend/progression/champions/Lab/fortification, combat extensions, persistent world/network, installer, workers and Planner, including worker/Planner process races. The installer now has 39 database checks and still creates no human account. |
| Syntax and packaging | All 80 distributed PHP files and 13 browser JavaScript modules pass syntax checks. The full ZIP contains 290 files; the 63-file patch overlays frozen 0.8.0 byte-for-byte to the same release. CRC/hash checks pass; the patch includes no private var files. All 86 artwork hashes match 0.8.0. |
| Pure gameplay regressions | Normalization 47; champions 76; Lab 172; fortification 217; champion bonuses 105; world 2,147; network 2,738; combat 389; workers 104; Planner 226; installer 48. |

The live browser used the existing QA06 keeper at normal 1× game speed, with no
CLI world worker. It displayed “Bots active · 1 online” and real ongoing battles.
The leaderboard refreshed while retaining page 2; rows changed without navigation.
During the observation, Rust Jackal 752 moved from 3 wins / 0 losses / 75 points to
3 wins / 1 loss / 60 points. Closing the only v0.8.1 game document and reopening
showed the paused bootstrap state, then automatically returned to active with the
same signed-in account. The existing world retained exactly 1,000 bots and its
prior QA accounts, workers and layouts. This was an existing advanced test world,
not a fresh-install speed benchmark. No screenshot or private world/configuration data is included in the ZIPs. Native Windows XAMPP/CMD execution, broad device coverage,
long-duration balance and production-scale concurrent load remain unverified.

---

The records below are historical release evidence, not claims of new work in
this hotfix.

# Verification record — 0.8.0 Yard Planner and construction crew

Database tests passed with PHP 8.4.27 on MySQL 8.0.44 and MariaDB 10.4.32. The HTTP test used Linux Apache/PHP 8.2/MariaDB 10.4 in an htdocs-style subdirectory. Destructive tests used disposable schemas; the existing preview world was upgraded and preserved. Counts below do not add duplicate reruns.

| Check | Observed result |
|---|---|
| Construction workers | 104 pure checks, plus 110 integration and 18 real-process race checks on each engine. Build, upgrade and fortify share capacity; hires spend real resources; legacy excess jobs retain exact timers; concurrent requests cannot purchase or reserve the same slot twice. |
| Yard Planner | 226 pure checks, plus 47 integration and 17 forced-process race checks on each engine. Includes complete inventory validation, atomic swaps, exact boundaries, pinned jobs, stale geometry revisions, private slots, damaged buildings, garrisons and invasion/build races. |
| Bot planning | 69,418 pure checks: 46,395 existing and 23,023 new construction/layout checks, covering all 1,000 stable IDs. |
| Bot database | 11,393 checks on each engine: 48 new scheduler checks plus 11,345 earlier bot checks. Covers paid hiring, development/meal reserves, legacy excess work, independent hatching/garrisons and bounded paid-Planner improvements without resetting injuries or timers. No optional population performance benchmark was repeated. |
| Existing progression | Pure normalization 47, champion 76, Lab 172, building fortification 217 and champion bonus 105 checks passed. Database checks total 447 per engine: backend 44, ordinary progression 56, champions 87, Lab 134, building fortification 47 and champion bonus 79. The Lab fixture now completes its first paid building before assigning the sole worker to the Catapult. |
| Existing combat | 389 pure checks and 252 database checks per engine passed across ordinary combat, special monsters, champions, Lab/artillery, territories, individual armor and Fortification networks, including the existing forced-process world/network races. Saved combat format remains seven. |
| Existing world | 2,147 world and 2,738 network pure checks passed; 641 world and 1,839 network database checks passed on each engine. Coordinates, ownership limits, support values and 1,000-bot identity behavior remain covered. |
| Installer | 48 pure and 38 database checks per engine passed. Setup remains local-only and database-only, defaults to XAMPP root/blank credentials, creates exactly 1,000 zero-rank bots and no human account, and retains separate game registration, diagnostics, rollback and retry. |
| Existing-world upgrade | 247 checks per engine passed, including an actual frozen-v0.7 fixture with three simultaneous paid building jobs, damage/armor, injured and frozen champions, owned Fortifications and an active format-seven invasion. Existing workers/layouts survive no-op upgrades. All 2,591 existing map sites stay intact; zero new sites are added to an already-v0.7 world. |
| Bounded-memory upgrade | 1,046 MySQL checks passed under a 128 MiB PHP limit, with 67,373,870 history bytes across 305 battles and 305 reports. Apply peaked at 6 MiB; check/no-op at 4 MiB. All seven original tables were restored exactly from the SQL backup. The fixture retains 2,591 map sites without new Fortification generation. |
| Frontend | 714 Node assertions passed: renderer 447, navigation 31, planner 46, workers 16, champions 29, fortification 31, Lab 36, map 52 and territories 26. Coverage includes atomic draft edits, private storage, pinned work arriving during a draft, Undo, navigation cleanup, pending-write controls, stale bootstrap isolation, preserved drag offsets and bounded worker paths/action sheets. |
| Syntax | All 72 distributed PHP files and 12 browser JavaScript modules passed syntax checks. |
| Apache HTTP | 568 checks passed through real endpoints: database-only setup, separate registration, persistent bot counts, conquest, ranks, garrisons, fortification/champion regressions, paid Planner construction, busy-worker rejection, paid hires, slot saving/deleting, atomic swaps, stale/invalid/pinned layouts, rival privacy and logout/login persistence. No HTTP 500 response or PHP diagnostic leak was observed. |
| Artwork | All 86 PNGs decoded and passed hash/dimension checks: 48 static illustrations and 38 four-pose sheets, totaling 126,418,825 bytes. All 82 previous assets retain their hashes. The Planner, worker portrait, walking sheet and hammering sheet were generated separately; worker sheets are 1,254 × 1,254 RGBA, with 627-pixel cells, transparent dividers and at least 58 pixels of visible cell margin. The 24 existing audio files are unchanged. |

## Live browser verification

The existing signed-in QA06 keeper was used at normal 1× game speed. A real worker purchase changed capacity from one to two and deducted 10,000 twigs, 10,000 pebbles and 1,000 putty. Building the Yard Planner deducted 2,500 twigs and 2,500 pebbles, displayed its real 144-second construction timer and occupied one worker. Completion released the worker and enabled the editor without any fixture clock adjustment.

Two layouts, “Garden guard” and “Open clearing”, were saved through the UI. Storing a building showed 22/23 placed and disabled Save/Apply; Undo restored 23/23. Moving the Planner in the draft, saving the second slot and applying it changed the live yard with unchanged displayed resources, rank and army. Both named slots and the applied arrangement survived a real reload. Loading the old layout then cancelling restored the applied yard; reopening showed a clean draft. The final screenshot shows the two persisted layouts and the moved Planner. No keeper or bot identities were reset, and preview speed remained one.

No application-origin warning/error appeared in the retrieved console entries; extension metadata errors were present. The browser pass verifies the described interactions, not every animation frame or all construction actions. Broader busy-job, pinning, invasion and concurrency cases are covered by the HTTP/module/database suites above.

## Preservation and limits

SQL schema stays at two and saved invasion formats remain unchanged. The additive upgrade supplies one standard worker and empty Planner storage only where absent. It grants no free Yard Planner or extra worker, and never cancels already-paid jobs that exceed the new capacity. Home layouts, account hashes, ranks, bot identities, armies, injuries, earned powers, territory state and history remain preserved. Player and bot layout changes use the same authoritative geometry checks. Saved slot names and worker-purchase details are private to their owner.

Source references and explicit adaptations are in `SOURCE-V08.md`, `CONSTRUCTION.md` and `YARD-PLANNER.md`. The local resource economy replaces the original premium-currency worker purchase. The planner is home-yard-only, has two slots and uses temporary draft storage; this release does not claim Inferno planning or every source editor tool. Worker paths are cosmetic and never determine server completion. Specific race tests establish only the covered locking behavior.

Native Windows XAMPP/CMD execution remains untested; the Linux Apache/MariaDB result is not a native Windows test. Actual mobile devices, broad browser coverage, long-duration balance and general production concurrency remain unverified. Full original species/buildings, directional/hurt/death/evolution artwork, Inferno, alliances, events and the other systems in `PARITY.md` remain unfinished.

---

The records below are historical. The v0.8 results above supersede only checks explicitly rerun for this release.

# Verification record — 0.7.0 Fortification network and monster actions

Database suites passed with PHP 8.4.27 on MySQL 8.0.44 and MariaDB 10.4.32. Destructive tests used explicitly disposable schemas. Counts identify individual suites or non-overlapping groups; reruns and duplicated logs are not additional assertions. The existing preview world was preserved.

| Check | Observed result |
|---|---|
| World rules | 2,147 existing world assertions and 2,738 Fortification-network assertions passed. Covers odd-row neighbors, owner matching, the exact 0/7/13/23/35/50/75% table, separate capture limits, zero fort income/storage/range, bounded generation and preserved map positions. |
| World database | 641 existing world assertions and the final 1,839 network assertions passed on each engine. Includes one-time generation, 1,000 persistent bots, registration capacity, ownership/management and dense-map scouting beyond the former 160-cell prefix. |
| Pure progression | Normalization 47, champion progression 76, Lab progression 172, individual-building fortification 216 and champion bonus progression 105 assertions passed. |
| Progression database | 447 assertions passed on each engine: backend 44, ordinary progression 56, champions 87, Lab 134, building fortification 47 and champion bonus progression 79. The 79 champion progression checks are counted here only. |
| Combat rules | 389 pure checks passed: network 124, engine 26, specials 23, champions 23, champion bonuses 70, Lab 48, world 29 and individual-building fortification 46. |
| Combat database | 252 assertions passed on each engine: network integration 31, actual-process network races 14, engine 24, specials 26, champions 34, Lab 30, world 46, world races 10 and individual-fortification/champion-clock integration 37. Covers frozen format-seven support, multiplicative armor, all building-damage routes, capture/settlement, owner-wide exclusions, actual abandonment races and unchanged older battle formats. |
| Bot planning | 46,395 checks passed across the existing planner 29,128, ordinary-world planner 5,031, individual-armor/champion planner 6,195 and new network planner 6,041. All 1,000 stable bot IDs are exercised. |
| Bot database | 11,345 checks passed on each engine: core 119, ordinary world 50, individual armor/champion bonuses 11,109 and strategic network 67. Covers paid actions, inventory and meal reserves, real capture, neutral/enemy targets, separate ownership capacity and continued ordinary development. The optional prepared 1,000-yard performance benchmark was not repeated for this release. |
| Installer | 48 pure assertions and 38 database assertions on each engine passed. Setup remains database-only, creates exactly 1,000 zero-rank bots and no human account, and retains separate game registration, diagnostics, rollback and retry. |
| Existing-world upgrade | 189 assertions passed on each engine, covering schema-1 upgrades, frozen v0.5/v0.6 fixtures, exact existing-site/history preservation, paid fortification jobs, earned champion powers, partial-generation rollback, one-time marker, no-op reruns and SQL restoration. |
| Bounded-memory upgrade | 1,046 MySQL assertions passed with 67,442,381 history bytes across 306 battles and 306 reports under a 128 MiB PHP limit. Apply peaked at 6 MiB; check/no-op at 4 MiB. All seven original tables restored exactly. The fixture retained its 1,321 old sites and added 1,270 neutral forts, reporting 2,591 actual sites. |
| Frontend and syntax | Node checks passed: renderer 417, navigation 21, champions 29, building fortification/network display 31, Lab 36, world map 52 and territory controls 26. All 62 distributed PHP files and ten browser JavaScript modules passed syntax checks. |
| Apache HTTP | 437 checks passed through Linux Apache/PHP 8.2/MariaDB 10.4 in an htdocs-style subdirectory. Real HTTP setup, separate registration, conquest, 100% capture, ranks, Fortification support during rebuild/upgrade, actual army/garrison transfers, completed paid upgrades/repairs, abandonment, champion/fortification regressions, CSRF/method/type guards and private-directory protection passed. No HTTP 500 responses or PHP diagnostic leaks were observed. |
| Artwork | All 82 PNGs decoded and passed hash/dimension validation: 46 static illustrations, 18 movement sheets and 18 attack/cast sheets, totaling 121,164,448 bytes. All 70 earlier assets retain their hashes. Eleven new four-pose action sheets and one Outpost Defender illustration were generated individually. New sheets are 1,254 × 1,254 RGBA with 627 × 627 cells; dividers contain no pixels above alpha 1, with at least 26 pixels of visible cell margin. |

## Live browser verification

The real UI captured Fortification 09-56 at 100% destruction and awarded +25 rank points (25 → 50). The selected army included Pokey, Fink, Bandito, Bolt, Eye-Ra, Fang and Zafreeti; Gorgo remained at home. The map showed 1/18 Fortifications separately from 0/6 ordinary holdings. Assigning two actual Pokeys changed the home inventory from 13 to 11 and showed 20/100 garrison housing. A paid level-two site upgrade deducted 4,000 twigs, 4,000 pebbles and 1,000 putty and displayed an eight-second timer at the temporary 20× preview speed. A home Sniper Tower with completed tier-three armor displayed 7% network protection and 40% building armor combining to 44.2% damage reduction.

No application-origin warnings or errors were captured; observed metadata errors came from the browser extension. Preview speed was restored to 1×. A final browser reload was blocked by the browser's URL policy, so level-two completion was not visually confirmed in that session; completed upgrades were verified separately through the HTTP suite. This pass does not claim individual live inspection of every new animation.

## Preservation, source contract and limits

SQL schema remains 2. Existing accounts, password hashes, ranks, armies, base layouts, earned armor/food powers, paid jobs, owned territories, coordinates, revisions and saved invasions survive the upgrade. New Fortification sites are neutral world records, not additional bot accounts. They are generated once on vacant neighbors of existing Resource Outposts and Strongholds; existing yards never move. The bounded 64 × 64 map can have partial rings, and automatic home rings are not added. Incidental same-owner home adjacency still provides protection. Exact source rules and explicit economy/map adaptations are documented in `SOURCE-V07.md` and `NETWORK-COMBAT.md`.

The first combined MySQL combat batch rejected the champion progression fixture's database suffix after the other 252 checks passed. The separate run with the required suffix passed all 79 progression assertions; these are included in the progression total above. Specific child-process race tests establish the covered locking behavior, not general production-load certification.

Native Windows XAMPP/CMD execution remains untested. The Linux Apache/PHP 8.2/MariaDB 10.4 HTTP run is not a native Windows test. Actual mobile hardware, broad browser coverage, long-duration balance and full production concurrency remain unverified. The current playable roster has movement and action sheets, but full original directional/hurt/death/evolution sets, remaining species/buildings, Inferno, alliances, events, the complete original world-map lifecycle and other systems listed in `PARITY.md` remain unfinished.

---

The records below are historical. The v0.7 results above supersede only checks explicitly rerun for this release; older results do not establish completion of new work.

# Verification record — 0.6.0 fortification and champion bonuses

Current database results use PHP 8.4.27 with MySQL 8.0.44 and MariaDB 10.4.32. Destructive suites used explicitly disposable schemas. Each count below identifies one suite or a non-overlapping group of suites; reruns and duplicate logs are not added to the counts.

| Check | Observed result |
|---|---|
| Fortification progression | 215 pure assertions and 47 database assertions on each engine passed. Covers the six eligible building types, exact four-tier costs/timers/gates, real payment, prior protection during work, capped cancellation refunds, ordinary-action locks and completion without healing. |
| Champion bonus progression | 105 pure assertions and 79 database assertions on each engine passed. Covers three earned evolution-six tiers for all three champions, actual monster meals, cooldowns, cumulative source-scaled powers, injury preservation, zero-HP behavior, maintenance, hunger boundaries, frozen rotation and old maintenance-count migration. |
| Existing progression regressions | 44 backend, 56 ordinary progression, 87 champion and 134 Lab database assertions passed on each engine. These counts exclude the two new progression suites above. |
| Combat rules | 265 pure checks passed across engine (26), specials (23), champions (23), Lab (48), world (29), fortification (46) and champion bonuses (70). |
| Combat database | 207 assertions passed on each engine: existing engine (24), specials (26), champions (34), Lab (30), world (46), forced world races (10), and new fortification/bonus integration (37). New coverage includes frozen tier/stats, all building-damage routes, tower work state, real injuries, duplicate settlement and legacy formats. |
| Offline battle clocks | Included in the 37 new combat database assertions: deployed and unused champions retain their feeding/hunger clocks after two-day saved battles, the actual reservation time determines the pause, repeated settlement cannot extend it again, and format-5 timing remains unchanged. |
| Installer regression | 48 pure assertions and 38 database assertions on each engine passed. Database-only setup, separate in-game registration, diagnostics, rollback and retry remain covered. A stale 0.5.0 version expectation found in the initial MariaDB regression run was corrected; the standalone MariaDB installer rerun passed. |
| Existing-world upgrade | 136 preservation/backup/restore/resume assertions passed on each engine, including the actual frozen 0.5.0 schema, owned territories and active format-5 battle fixture. |
| Bounded-memory upgrade | 1,045 MySQL assertions passed with 67,541,303 bytes of battle/report history under a 128 MiB PHP limit. Upgrade peak memory was 6 MiB; dry-run and no-op peaks were 4 MiB. |
| Frontend | 276 renderer, 29 champion, 24 fortification, 36 Lab, 20 territory and 42 world-map Node checks passed. All 55 distributed PHP files and ten browser JavaScript modules passed syntax checks. |
| Bot database | 11,109 new fortification/champion-bonus assertions passed on each engine, plus 119 existing bot and 50 territory-bot assertions on each. The three optional population-workload assertions are reported separately below. All 40,354 planner checks also passed: 29,128 existing, 5,031 territory and 6,195 fortification/bonus-meal checks. |
| Prepared bot population | Three workload assertions passed on each engine: all 1,000 prepared bots paid for and queued their own fortification, with zero errors, raids or early tier grants and unchanged identity/rank hashes. The workload took 10.263 seconds on MySQL and 6.826 seconds on MariaDB. It supplies prerequisites and funds; it does not measure organic progression or simultaneous invasions. |
| Apache HTTP | 358 checks passed through Apache 2.4/PHP 8.2/MariaDB 10.4 in an htdocs-style subdirectory. Actual setup created exactly 1,000 zero-rank bots and no player; separate game registration added one human/base. Real endpoints covered 100% territorial capture, rank wins/losses, paid fortification/cancellation, all Gorgo bonus tiers, maintenance, hunger, frozen persistence, method/CSRF/type guards and private-directory protection. No HTTP 500 responses or PHP diagnostics were observed. |
| Live browser | Passed with an isolated QA06 keeper in the preserved preview world: all three Gorgo bonus meals, 37,500 maximum HP/600 damage, freeze/thaw preservation, paid fortification, displayed 20/30/40% completed protection, exact cancellation refund, and automatic closure of a refund review when work finishes. A real bot-yard invasion launched Pokey/Octo-Ooze/Ichi/D.A.V.E. and a bonus-three Gorgo by clicking clear ground; towers retaliated, the battle reached 100% destruction, and settlement awarded +25 rank with 36,520/37,500 champion HP recorded in the result. No application console warnings/errors appeared. Preview speed was temporarily 20 and restored afterward; no real keeper or bot identities were reset. |
| Artwork | All 70 PNGs decoded, matched hashes and passed dimensional validation: 45 static illustrations, 18 movement sheets and seven new attack sheets, totaling 107,714,439 bytes. All 63 earlier images are unchanged. The seven new sheets are true-alpha 1,254 × 1,254 images with 627 × 627 cells; no solid pixels cross cell dividers. These are one-view attack actions, not complete directional/hurt/death/evolution sets. |

## Preservation and restoration

The updater leaves SQL schema 2 unchanged when upgrading a 0.5.0 world. Historical schema-1 upgrades remain covered separately. The actual 0.5.0 fixture retains territory ownership, coordinates, levels, state and revisions, player/bot identities, armies, ranks, queued work, champion injuries and timers, active battles, reports and authentication records. New building fortification and earned champion bonus tiers start at zero; the old `bonusFeeds` maintenance field cannot grant free powers.

The larger memory fixture contains 1,001 accounts, including 1,000 bots, 1,321 world sites, 308 battles and 308 reports. It verifies all seven original tables and restores them from the streamed SQL backup. JSON backups and fingerprints stream by row, while base normalization uses eight-row batches. The fixture establishes bounded memory for this saved-history workload, not production disaster-recovery certification.

## Source contract and limits

Source values were checked against the supplied Flash source. Fomor's 3/6/10 bonus range increases attack reach; its aura radius remains separate and fixed. Individual-building Fortify reduces incoming damage by 20/30/40/50%, without increasing maximum HP. This milestone deliberately adapts economy, timers and Town Hall gates to the remake; the exact source references and remaining differences are in `SOURCE-V06.md`.

Native Windows XAMPP/CMD execution, actual mobile hardware, broad browser coverage, long-duration balance and general production concurrency remain unverified. A successful Linux MariaDB run is not a native Windows test. Full original directional/attack/hurt/death/evolution artwork, Inferno, alliances, events, MR3 fortification cells and the other systems in `PARITY.md` remain unfinished.

---

The records below are historical. The v0.6 results above supersede only the checks explicitly rerun for this release; pending v0.6 work is not represented as completed by an older result.

# Verification record — 0.5.0 persistent conquest

Tested with PHP 8.4.27/MySQL 8.0.44 and an Apache 2.4.71/PHP 8.2.32 CGI stack using MariaDB 10.4.32. All schemas used for destructive tests were disposable. The existing preview world was upgraded in place, not reset.

| Check | Observed result |
|---|---|
| Syntax | 46 distributed PHP files and nine browser JavaScript modules passed syntax checks. |
| World rules | 2,147 pure assertions passed, including hex geometry and exact economy boundaries. |
| World database | 337 MariaDB assertions passed: 1,000 zero-rank bots, zero humans at setup, 320 sites, stable homes, paid construction/management, private bounded map responses, conservation and full-map registration rollback. |
| Existing progression | 320 MariaDB assertions passed across backend, ordinary progression, champions and Lab integration. Pure normalization/champion/Lab suites also passed 47/76/172 assertions. |
| Combat | 149 pure checks passed. MySQL and MariaDB each passed 170 database assertions, including exact capture threshold, independent rank outcome, owner-home preservation, neutral opponents, real garrison casualties, legacy combat and forced parallel races. |
| Bot planning | 34,159 planner checks passed across the 1,000 identities. |
| Bot database | 169 bot integration assertions passed on each database engine, covering actual paid territorial actions, neutral/enemy capture, inventories, reserves and existing home raids. |
| Prepared bot population | 1,000 paid Map Rooms queued in 5.936 seconds on MariaDB and 8.922 seconds on MySQL. Zero errors, raids or early completions; identity/name/rank/win/loss hashes were unchanged. This prepared workload tests scheduling/economy, not organic progression or 1,000 simultaneous raids. |
| Installer | 48 pure and 38 MariaDB assertions passed. Database-only setup, separate in-game registration, connection diagnostics, retries and existing-world preservation remain intact. |
| Apache HTTP | 246 checks passed on PHP 8.2/MariaDB: real setup, map and invasion endpoints, 100% territory capture, ranks, duplicate settlement, paid upgrades/repairs, actual garrison transfers, abandonment, CSRF/method/type guards and private-directory protection. Zero PHP warnings or HTTP 500 responses. |
| Frontend | 188 renderer, 42 world-map, 20 territory-control, 36 Lab UI and 21 champion UI checks passed. Covers new sheets, facing, real site IDs, bounded map requests, stale-response isolation, safe action routing, server eligibility and troop-transfer payloads. |
| Artwork | All 63 PNGs decoded, matched hashes and passed dimensional checks. Seven new images are true-alpha 1,254 × 1,254; four new sheets use 627 × 627 cells with no solid pixels crossing dividers. Total art: 99,841,981 bytes; 45 static illustrations and 18 animation sheets. |
| Audio | Existing two Ogg music loops and 22 WAV effects retained; no audio files changed in this release. |

## Upgrade verification

The updater passed 76 preservation/restore/resume assertions on each database engine. A MySQL-specific hexadecimal-value coercion issue was found and fixed with explicit text conversion in SQL backups.

The existing larger preview world was upgraded under a 128 MiB PHP limit: all 1,008 accounts, including 1,000 bots, were retained and 1,328 map sites were initialized. Upgrade peak memory was 4 MiB. A separate 67,504,825-byte battle/report-history fixture passed 1,042 assertions, including all preserved rows and full SQL restoration; upgrade peak memory was 6 MiB. History, fingerprints, JSON and SQL backups stream row by row, with base normalization in eight-row batches. No raised PHP memory limit is needed for these fixtures.

## Integrated browser verification

The desktop browser pass used an advanced synthetic keeper in the upgraded preview world. The actual UI launched four monsters in one Flinger bucket and a Project X in a second bucket at separately clicked ground positions. The bucket-capacity check rejected an overfilled selection without consuming it. Animated combat reached 100% destruction, captured a neutral resource outpost, awarded 25 rank points, and added its income and 2,000 storage per resource.

Management moved three actual Pokeys from the army into the garrison, returned one, and completed a paid level-2 upgrade while preserving the remaining two defenders. The automatically refreshed panel displayed increased income, 4,000 storage and expanded reach. This preview used the documented 20x speed setting; normal defaults remain unchanged. Map refresh, zoom, home centering, holdings selection, a bot-home visit and return-home navigation were exercised. No application-origin console warnings/errors, broken visible images or horizontal page overflow were observed at the 1,348-pixel desktop viewport. A nested map-toolbar layout defect found during this pass was corrected.

The disposable browser sign-in route and advanced fixture are excluded from both downloads; the preview speed configuration was restored afterward.

## Limits

Native Windows XAMPP/CMD execution was unavailable. PHP 8.2/Apache/MariaDB behavior was exercised on Linux; the Windows launchers were inspected for CRLF, quoted portable paths and prerequisite discovery. Actual mobile hardware, broad browser coverage, long-duration balance and production concurrency remain unverified. The forced race tests establish specific locking behavior, not general high-load certification.

The original game's full directional/attack/hurt/death/evolution artwork, Inferno, alliances, events, Fortification cells, map variants and other systems listed in `PARITY.md` remain unfinished. This is a tested development milestone, not a claim of complete original-game parity.

---

The records below are historical. Later results above supersede older verification limits where the same platform or subsystem was subsequently tested.


# Verification record — 0.4.1 XAMPP setup fix

The hotfix was tested with **MariaDB 10.4.32**, the version bundled with XAMPP 8.2.12, and a real **Apache 2.4.71 / PHP 8.2.32 CGI** stack on Linux. Game content version remains 0.4.0 and schema version remains 1.

| Hotfix check | Observed result |
|---|---|
| Installer regression | 48 pure checks and 34 real MariaDB assertions passed. |
| PHP HTTP installation | 68 checks passed against MariaDB 10.4.32 through a subdirectory URL matching an htdocs installation. |
| Apache installation | 101 real HTTP checks passed. No PHP fatal errors, warnings or HTTP 500 responses. |
| World creation | Exactly 1,000 bots and saved bases; zero human accounts; zero initial rank, wins and losses. No player-account fields or installer sign-in. |
| Separate registration | Normal in-game registration created the first human and starter yard afterward; login and rankings worked while retaining all 1,000 bots. |
| Connection handling | Default root/blank connection and read-only probe passed. Rejected backyard credentials produced actionable guidance. Submitted database passwords were not reflected into HTML. |
| Preservation and retry | Reopening setup preserved account hashes. Populated and unrelated schemas were refused. A deliberately failed seed rolled back and could be retried without partial/duplicate bots. |
| Apache routing/access | Root index/setup redirects worked below the project folder; public CSS returned 200. Private schema, configuration, worker, tests and tools returned 403. |
| Syntax and launchers | All 37 PHP files passed syntax checks. Four changed CMD files use CRLF and quoted paths; PHP discovery walks parent folders instead of requiring a fixed drive. |

Native Windows XAMPP and CMD execution were not available in this Linux workspace and are not claimed as tested. No user database was reset. Gameplay and generated assets were unchanged by the hotfix; earlier gameplay verification follows.

# Previous gameplay verification — 0.4.0

Verified in the supplied Linux workspace with PHP 8.4.27, PDO MySQL, MySQL 8.0.44, Node and a Chrome browser preview. These are development checks, not certification of complete original-game parity or production-scale support.

| Check | Observed result |
|---|---|
| Final syntax | All 34 distributed PHP files and seven browser JavaScript modules passed syntax checks. |
| Existing backend | 44 assertions passed: accounts, initial ranks, 1,000 bots, placement, costs, queues, housing, research, directory filters, locks and malformed input. |
| Legacy normalization | 47 assertions passed: existing layouts, resources, armies and research preserved; missing defaults added idempotently. |
| Ordinary progression | 56 MySQL assertions passed: paid discoveries, unlock gates, cancellation/refunds, hatchery lanes, Juicer capacity, garrisons, traps, repairs and legacy Flinger construction. |
| Champion progression | 76 pure and 86 MySQL assertions passed: recipes, all six levels, atomic meals, maintenance, hunger/recovery, frozen rotation, Chamber requirements, battle locks and persistent injuries. |
| Monster Lab | 172 pure and 134 MySQL assertions passed: ten abilities with three tiers each, paid research, Academy/facility gates, one queue, cancellation and capacity-limited refunds, idempotent completion, Project X progression, malformed input and preservation of existing Lab extensions. |
| Pure combat | 120 checks passed: 72 existing combat checks and 48 Lab/Catapult checks. Covers all ten abilities, ground/air interactions, cloaking, blinking, poison, death effects, ricochets, timed artillery impacts, damage exclusions, putty buffs, champions and legacy behavior. |
| Combat with MySQL | 114 checks passed: 84 existing checks and 30 new checks. Covers ownership, snapshotted research, paid Catapult use, one shot per family, legal targets, independent Flinger controls, bot artillery, legacy battles, persistent damage and duplicate-settlement protection. |
| Bot planning | 29,128 checks passed across the 1,000 persistent identities, including specialist progression, Lab prerequisites, food reservations, affordable training and varied construction. |
| Bot integration | 119 MySQL checks passed: real paid building/research actions, champion care, Project X, Lab progression, food reserves, authoritative raids and preservation of all 1,000 bots. |
| Ordinary population batch | 1,000 due turns, 998 constructions and zero errors in 21.354 seconds. Protected targets isolate economy and scheduling work. |
| Prepared Lab population batch | 1,000 developed yards began 1,000 paid studies in 9.733 seconds. Every cost and queue was verified, with zero early ability grants, attacks or errors; identities and ranks were unchanged. Supplied prerequisites and protected targets make this a scheduling/economy fixture, not an organic progression or simultaneous-raid benchmark. |
| Large mixed battle | 172 units: 150 ordinary attackers across all 15 species, two level-six Fomors and 20 bunker defenders. All ten Lab powers were tier three. Against 81 structures including 72 walls, with all three Catapult families launched, the fixture reached 100% destruction after nine simulated seconds. PHP simulation took 974.794 ms, peak memory 4 MiB, 260 retained events and 200,847 bytes of final state. |
| Real HTTP API | 288 scenario plus 693 response assertions passed across 316 connections. Actual localhost setup created exactly 1,000 zero-rank bots. All 30 Lab studies and all 11 Catapult ammunition variants used real endpoints, alongside Project X discovery/hatching, auth/CSRF/method/type/ownership checks, refunds, resource payments, impacts, legacy behavior and settlement idempotence. Zero PHP warnings, fatals, notices or HTTP 500 responses; the expected pre-install bootstrap returned 503. |
| Existing-world upgrade | 57 assertions passed: direct upgrades from 0.1/0.2/0.3, private pre-upgrade snapshots, rollback, idempotence and version rejection. All 1,000 identities, ranks, layouts, champion injuries/timers, Lab levels/queues/extensions and existing battle/report/authentication records were preserved. |
| Browser modules | 157 renderer, 36 Lab UI and 21 champion UI checks passed, including animation sheets, facing, ability effects, Catapult targeting, queue/refund displays, deployment state and champion care. |
| Artwork | 56 PNGs: 42 static illustrations and 14 four-pose sheets. Hashes, image decoding and dimensions passed validation. New assets were generated and reviewed individually; object/sheet transparency and pose boundaries were checked. |
| Audio | 24 files: two Ogg music loops and 22 WAV effects, totaling 6,189,699 bytes. All decoded successfully with zero clipped samples. |

The large-battle fixture deliberately predeploys armies and launches all three artillery families together. Its timing excludes MySQL, network, browser rendering, image decoding and audio. It is one deterministic stress fixture, not a concurrency or sustained-world benchmark.

The final Catapult requirement for a completed, living Flinger was added after the HTTP run and is covered by the 172 pure Lab/catalog checks. New-world HTTP fixtures already contained a Flinger. The unchanged launcher readiness helper previously passed six checks and an actual PHP HTTP smoke test; that historical result is not a fresh native Windows run.

## Actual browser checks in this release

- Opened the real local PHP/MySQL game with the preserved 1,000-bot world and the expanded catalog.
- Began and completed Bolt's first paid Lab study. Queued Fink's tier-two study, then cancelled it and observed the refund. Putty moved from 300,000 to 298,500 overall, retaining only Bolt's 1,500-putty charge.
- Searched for and scouted the saved QA04 Sparring yard with 19 structures.
- Prepared a seven-monster mixed Flinger bucket using 345/400 capacity. Selecting and aiming Catapult ammunition preserved that bucket.
- Fired tier-four pebbles, tier-three twigs and tier-four putty, paying 30,000 pebbles, 20,000 twigs and 30,000 putty. Each used family became unavailable for another shot.
- Clicked clear ground to deploy the retained mixed bucket. Project X and D.A.V.E. animation, Brain cloaking and putty effect rings were visible in the running invasion.
- Finished with a displayed 79% destruction victory, +25 rank points and 23,670 of each resource in loot. The saved report remained accessible, and 117 undeployed monsters remained in the army.
- At a 1,363-pixel viewport, document width was 1,348 pixels: no horizontal overflow or broken document images. No application-origin browser warnings or errors were observed; unrelated browser-extension errors were excluded.

Browser checks used synthetic accounts, prepared prerequisite stock/research and a local-only game speed of 20. The world worker was paused during the UI sequence; autonomous behavior was verified separately through the MySQL bot tests. Fixture routes, credentials, installed configuration, sessions, logs, backups and databases are excluded from the release ZIP. Distributed regression tests use explicitly disposable data and schemas.

## Remaining verification limits

Native Windows `.cmd` execution, actual mobile-device interaction, MariaDB, other PHP versions and broad browser compatibility remain unverified. Concurrent multi-human load, long-duration bot/economy balance and restoration into a separate live world require further testing. Upgrade snapshots were validated as readable and complete; they are not a production disaster-recovery service.

Complete original gameplay, exact balance, all directional/attack/hurt/death/evolution artwork, champion post-maximum food bonuses, strategic world-map systems and exhaustive music/sound coverage remain unfinished. The source-derived Lab/Catapult behavior includes documented timing, scale and economy adaptations. See `PARITY.md`, `MONSTER-LAB.md`, `LABORATORY-COMBAT.md` and `CHAMPIONS.md` for the implemented scope and remaining differences.
