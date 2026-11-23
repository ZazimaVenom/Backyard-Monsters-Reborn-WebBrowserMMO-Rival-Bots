# Backyard Monsters · Reborn

A new HTML5 Canvas / PHP / MySQL implementation built from a study of the supplied Backyard Monsters Refitted source. No Flash, Bun, Redis, Node.js, Composer, or frontend build step is required to run this game.

**0.13.0 adds regional worlds and buildable outpost yards.** Move to a Frontier or Highlands, settle near a friend, or fight for a native field headquarters. The world event calendar runs shared, scored campaigns. The five-stage Inferno Emergence opens a free cavern after the fifth defense resolves. Keepers can read the searchable field guide in six languages, chat by map edition and use earned Shiny to expand their yards. See [the changes](docs/CHANGELOG.md) and [verified gameplay](docs/VALIDATION.md).

The original-game comparison and outstanding systems are in `docs/PARITY.md`.

## XAMPP setup on Windows

1. Extract the game folder into XAMPP's `htdocs`, for example `C:\xampp\htdocs\backyard-monsters`. Keep `app`, `public`, `bin` and `var` together.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/backyard-monsters/public/setup.php`. If you extracted the contents directly into `htdocs`, use `http://localhost/public/setup.php` instead.
4. The default local XAMPP database settings are host **127.0.0.1**, port **3306**, user **root**, and an **empty database password**. If you changed them, use your configured credentials and the MySQL port shown in XAMPP.
5. Select **Test database connection**, then **Install world**. Setup creates the database tables and exactly **1,000 bots at zero rank points**. It does **not** create or request a player account.
6. Select **Open the game**. Register or sign in on the game's own login screen.
7. Keep the signed-in game open. It automatically sends activity pulses and advances bounded bot work. **You do not need to run START-XAMPP.cmd or START-WORLD.cmd.** Those launchers remain available as optional workers and obey the same online-player gate.

PHP 8.2+ and MySQL 8+ or MariaDB 10.4+ are supported. XAMPP calls its MariaDB service “MySQL.” See `docs/XAMPP.md` for troubleshooting. If a connection test used a non-empty database password, enter it again before installing; the page never echoes passwords back.

## Updating an existing world to 0.13.0

The **0.12.0 → 0.13.0 update ZIP** is for an existing 0.12.0 installation. For an older supported installation, use the full 0.13.0 ZIP and the same updater.

1. Stop Apache and any running bot worker. Leave MySQL running.
2. Copy the contents of the ZIP's inner game folder over your installation. Preserve your private `var` folder and existing database.
3. Open a Command Prompt in the game folder and run:

```bat
UPGRADE-WORLD.cmd --check
UPGRADE-WORLD.cmd --apply
```

The first command previews the changes. The second creates a restorable SQL backup and a JSON audit snapshot before updating your world. An existing 0.12.0 database already uses schema 3. The installer is for new worlds. Restart Apache and sign in with your existing keeper. Your saved yard, accounts, bot population and active invasions carry forward.

For other systems use `php bin/upgrade.php --check`, then `php bin/upgrade.php --apply`. See `docs/UPGRADING.md` for exact backup and recovery instructions. MySQL/MariaDB schema changes cannot be rolled back as one transaction; the updater is designed to resume after a partial schema change.
Yards upgrading from 0.1 need to construct a **Flinger** before starting new invasions. New yards start with one; old yards are never overwritten to insert a building. Existing active invasions can finish with their legacy deployment rules.

## Alternative: standalone PHP server on Windows

1. Install/start **PHP 8.2+ with PDO MySQL** and **MySQL 8+ or MariaDB 10.4+**. These services are prerequisites; the ZIP does not bundle a database or PHP runtime.
2. Extract this folder to a writable location. Make sure `php.exe` is on PATH, or set `PHP_EXE` to its full path. A `php/php.exe` next to this README is also detected.
3. Double-click **START-LOCAL.cmd**. It opens the local HTTP server at `http://127.0.0.1:8080/`.
4. The first visit opens **setup.php**. Enter the database connection details. Use an empty database; existing populated worlds are never overwritten. No player account is requested here.
5. Setup creates all tables and **exactly 1,000 distinct bot accounts and saved bases** at **0 rank points**. Open the game afterward to register your player account, which also starts at zero.
6. Enter the game and sign in to activate the bots automatically. After setup, the launcher waits for a key before opening a separate optional worker window; on later starts it opens that window automatically. You may close that worker window; the signed-in browser drives the same bounded simulation. **START-WORLD.cmd** runs the optional worker independently.

Close the two PHP windows to stop the HTTP server and worker. Stop your database cleanly using its own service controls. The scripts do not start or stop the database, do not delete data, and have no fixed drive-letter dependency.

The Windows scripts are included for convenience; PHP/MySQL behavior was validated on Linux. Windows launcher execution still needs a Windows check.

## Start from a terminal

From the project folder:

```sh
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080/setup.php` **on that computer**. After installation, sign in and keep the game open; no second terminal is required. An optional worker can be run with:

```sh
php bin/worker.php
```

On Linux/macOS, `./start-local.sh` starts the HTTP server and optional worker after the first installation. The worker still pauses when no players are online. PHP's built-in server is suitable for local play and development.

## Database preparation

You may use a local database administrator account for the installer or prepare a dedicated empty database and account first. For example, run this yourself in your local database administration tool and choose your own password:

```sql
CREATE DATABASE backyard_reborn CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'backyard'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_YOUR_OWN_PASSWORD';
GRANT ALL PRIVILEGES ON backyard_reborn.* TO 'backyard'@'127.0.0.1';
```

Use those credentials in setup. Uncheck “Create the database” if it already exists and your account has no global create privilege. A dedicated schema account is sufficient after installation.

## Playing

| To do this | Where to go |
| --- | --- |
| Grow a yard | Collect the four resources and place buildings. Hire additional workers, discover monsters in the Locker, hatch them and upgrade them in the Academy. A Yard Planner unlocks two saved surface layouts. The surface Town Hall reaches level 10. |
| Visit, invade or repair | Open **Invasion**, filter for players or bots, then choose **Visit** or **Invade**. Load a bucket from your ready army and click a valid spot on the battlefield. Towers, traps and bunkers defend automatically. Back home, **Repair base** restores damaged completed structures instantly and for free. Champions recover separately. |
| Compete for rank and territory | **Rankings** lists every keeper. All start at zero. A ranked victory earns 25 points and a defeat costs 15. Build a Map Room to scout the World Map. Breach territories by destroying 90% of eligible building health. In the Frontier, pay the posted takeover fee to claim a breached outpost, then build, train and launch raids there. |
| Fight campaigns | **Invasion → Wild Monster Tribes** offers 21 original camps. **Brukkarg War** alternates 25 defenses with five raids, including a mirror of the player’s own yard. Select **Creature Carnage** to raid ten original yards and unlock Vorg. The home Monster Blitzkrieg control opens ten defensive waves for Slimeattikus. **Yard events → Wild Monster Invasion** opens either seven-day, 32-wave defense and its six-tier victory totem. **Hell Raisers** opens an XP hunt with nine permanent prizes. **Hell-Raisers · Moloch’s siege** offers ten home defenses and a two-Magma-Tower reward. Enter a shared UTC round from **Yard events → World event calendar** to compete in one of five scheduled campaigns. |
| Open Inferno | Pay for early entry under **Inferno** with a working level-two Town Hall, or reach Town Hall 5 and resolve all five **Inferno Emergence** defenses to open the cavern for free. Build a second yard, collect bone, coal, sulfur and magma, and hatch eight native species. Raid 34 Moloch camps, defend against eight local counterattacks or invade a player or bot cavern. Clear 13 Descent camps to transfer native troops to the surface. |
| Raise a champion | Open **Champions**. Use the Cage and Chamber to raise, feed, evolve and switch your active guardian. Monster Madness awards Korath for distinct conquered resource outposts. Weekly ranked loot earns Krallen, who can deploy alongside your guardian. |
| Improve weapons | Research ten species-specific Monster Lab abilities. The Catapult spends resources on targeted ammunition. The Chaos Lab and Chaos Factory research and manufacture Decoy, Vacuum and Jars. Armies, devices and projectiles follow the live battle clock. |
| Collect rewards and decorate | **Quests** contains 84 overworld quests, all 38 Inferno quests, five local goals and 21 achievements. Pick yard mushrooms for a chance at Shiny. **Decorations** offers 84 illustrated pieces, placement, moves and storage. **Yard events** shows seasonal pumpkins and the Trojan Horse when eligible. |
| Use the shop or Hatchery Control Center | Spend earned Shiny on land, workers, resource deliveries, faster training and production, combat bonuses, protection, bunker defenders or champion care. The HCC gives an overview of paid orders across functioning Hatcheries and can finish their queues. |
| Meet other keepers | **Neighbors** supports human friends, mystery sacks and private messages. Leave a visitor sign, enable local Radio Tower news, form an alliance or negotiate a truce. Use **Realm chat** to talk with keepers in your map edition or the Inferno. Alliance members share a private feed, and leaders can organize wars and activate Armament, Conquest and Declare War. |

The 27 conventional monster definitions include 18 surface hatchlings, eight native underground species and the Slimeattikus Mini summoned in combat. Each committed regular attacker leaves its home army. Undeployed troops stay in their yard. Every fight saves its results and damage. New human keepers receive a 30-minute initial shield.

[Regional travel](docs/REGIONS.md) · [Frontier outposts](docs/REGIONAL-OUTPOSTS.md) · [Inferno](docs/INFERNO.md) · [General Store](docs/YARD-UTILITIES.md) · [Event calendar](docs/WORLD-EVENT-CALENDAR.md) · [Monsters](docs/MONSTER-EXPANSION.md)

## The 1,000 bots

Bots have persistent account/base/army/resource/research/planning records. They make cost-checked construction, layout, upgrade, repair, training, discovery, research, garrison, and recycling choices. They invade eligible humans and other bots using the same combat engine and timed, capacity-checked Flinger buckets. Existing bots build missing Flingers with their saved resources. They also build champion facilities, raise a preferred champion, discover and hatch food species, feed and evolve, reserve meals against spending elsewhere, and wait for injuries to heal before raiding. Their decisions use the same costs and rules as player actions.

**Signed-in browsers automatically run bounded bot work every five seconds while at least one human has the game open.** Separate tabs and players share one scheduler, so opening more tabs does not multiply bot speed. Closing a tab or logging out removes its presence promptly when the leave request reaches the server; a crash, lost connection or suspended browser expires after 45 seconds without a pulse. Other online players keep the world active. The UI shows activity and recent completed work, and rankings refresh while their page is visible.

When the last human leaves, bot construction, training, research, economy, champion timers, territory jobs and bot-origin invasions pause. They resume from their saved progress without an offline catch-up burst. Human base progression and human-origin invasions retain their usual wall-clock behavior. Rank changes still require genuine settled battles: winners gain 25 points and losers lose 15. Fresh bots need time to develop armies and complete raids before ranks change.

`START-XAMPP.cmd`, `START-WORLD.cmd` and `php bin/worker.php` remain optional. They use the same presence gate, lock and five-second cadence as browser pulses; keeping a worker open does not run bots on an empty server. This server process is separate from the construction workers you hire inside the game.

Bots also build paid Map Rooms, conquer reachable neutral or enemy territory, invest in upgrades and repairs, and station their own monsters as defenders. Territory spending preserves planned champion meals and home-defense reserves. Bots also contest useful Fortification cells, improve and staff them through the same paid actions, and can target enemy connections. Advanced bots can settle player-inhabited regions and manage Frontier outposts while a human is online. Bots also pay for additional workers and a Planner, and occasionally improve real tower coverage without overwriting saved plans. See `docs/BOTS-V08.md`.

Advanced bots build Chaos facilities, research and manufacture a siege device with their own resources, and deploy it in a legal raid. Their construction and research pause with the world. Bots also construct Labs and Catapults, complete paid ability studies, train Project X, and spend their own resources on timed artillery during invasions. The scheduler processes bounded batches and holds a MySQL advisory lock to prevent duplicates. Browser pulses require a real signed-in human session and CSRF validation; anonymous visits cannot activate bots. See `docs/BOT-SIMULATION.md` for tuning and scheduling.

## Files and configuration

- `public/` — the only HTTP document root; HTML, Canvas client, API, local installer, generated art/audio.
- `app/` — PHP domain code, schema, server combat, bot planner, authentication.
- `var/config.php` — generated private database/settings file after setup; never place it in the web root.
- `bin/` — CLI world worker, one-shot tick, readiness checks and non-destructive content upgrade.
- `tests/` — backend, combat, and bot regression tests.
- `docs/` — parity inventory, source catalog, source notices, asset prompts, audio composition and validation evidence.

For Apache/Nginx, point the document root at `public/` and configure PHP normally. The installer accepts only direct loopback requests (`127.0.0.1` or `::1`), rejects forwarded-header impersonation, and locks when a config exists. Install locally before any network deployment. The local start script binds only to loopback.

Configuration includes `game_speed` (default 1), `player_grace_seconds` (default 1800), and bot scheduling options. A higher game speed accelerates build/training/economy, champion feeding cooldowns and recovery for local testing; it does not accelerate the five-minute standard battle or seven-minute war clocks. Keep the configured bot population at 1,000 for the requested realm.

Back up the entire MySQL schema and `var/config.php` together. The initial installer never deletes or resets an existing populated realm. The updater retains schema 3 for 0.12.0 worlds. It migrates earlier schemas when needed and preserves existing maps, keeper identities and saved progression. Older worlds receive missing map/Fortification migrations only when required; no ownership or protection is granted. Existing building armor, champion bonus meals and saved jobs remain intact.

## Artwork, animation and audio

The two yards have distinct painted terrain. Troops, buildings, garden pieces and event objects use local HD assets. All 36 upgradeable surface types and 16 native facilities change appearance at every reachable upgrade. The separate tier images keep their completed architecture until a paid upgrade finishes. The renderer loads the textures for the current yard and battle. Inventory thumbnails and WebP terrain and combat textures reduce initial transfers. The original-resolution illustrations stay in the download.

All 27 conventional species have painted directional strides and attacks. Every species and guardian appearance also has a four-pose defeat or retreat sequence. The four ordinary guardian lines use six painted evolution sheets apiece. Each sheet includes an idle stance, a step, an attack wind-up and a strike. Krallen has its own power-dependent appearance. The 27 species and five guardians have distinct arrival and defeat calls. Combat audio accompanies firing, healing, terrain effects and settlement. The six locally composed loops include *The Hollow Below* for underground play and *Alarm at the Gate* for surface event battles. Music begins after the player enables sound.

The art guides list the complete tier ranges, shared tower exteriors, and original animation and event differences. [Building upgrade art](docs/BUILDING-TIERS.md) · [Source comparison](docs/PARITY.md)

## Verification and source notes

See `docs/VALIDATION.md` for actual checks performed and remaining platform limits. Tests use isolated temporary schemas and are intended for a disposable local database, not a live player world.

The supplied source README labels the project GPL, while its actual LICENSE file contains the PolyForm Noncommercial License. That file is retained verbatim as `docs/UPSTREAM-LICENSE.txt`; `docs/SOURCE-NOTICES.md` records this discrepancy and attribution. No representation is made that the original game’s third-party trademarks/art are licensed by its software license.
