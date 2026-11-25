# Install 0.13.1 with XAMPP on Windows

Use XAMPP with PHP 8.2 or newer and its PDO MySQL extension enabled. Apache serves
the game; the XAMPP module labelled **MySQL** may use MariaDB internally.

1. Extract the complete game folder into XAMPP's `htdocs` folder. For example,
   `C:\xampp\htdocs\backyard-monsters\` should contain `app`, `bin`, `public` and
   `var` together. Keep the same structure if XAMPP is on another drive.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/backyard-monsters/public/setup.php`. If you put the game
   files directly in `htdocs`, use `http://localhost/public/setup.php` instead.
   Use your configured Apache port if it is not 80.
4. Use XAMPP’s local database credentials. A fresh installation normally uses these settings.

   | Setting | Value |
   |---|---|
   | Host | `127.0.0.1` |
   | Port | `3306`, or the MySQL port shown by your XAMPP Control Panel |
   | Database | `backyard_rehatched` |
   | Database user | `root` |
   | Database password | Leave blank unless you set a database root password |

   `backyard` is not an account that XAMPP creates automatically. If you created a
   separate database account, use its actual username and password instead.
5. Select **Test database connection**, then **Install world**. Leave database
   creation enabled unless you already created the empty database yourself.
   Setup configures the database and installs **1,000 bots at zero rank points**.
   **Setup does not create a player account or ask for a keeper password.**
6. Enter the game after setup. Player registration and login are on the game's
   separate login screen.
7. Keep the signed-in game open. The browser automatically activates the bots;
   **no separate world-worker window is required**. Activity pulses run about every
   five seconds, with one shared scheduler for all online players and tabs.

Bots pause when nobody is online. Closing a tab or logging out releases its
presence promptly if the request reaches the server; an unexpected crash or
connection loss expires after 45 seconds without a pulse. Another signed-in
player or tab keeps the world active. Bot economy, queues, champion timers,
territory work and bot-origin battles resume without counting the empty-server
interval. Human base progression and human-origin raids keep normal wall-clock
timers. Rankings change only after real battles settle.

`START-XAMPP.cmd` and `START-WORLD.cmd` remain optional. They share the browser's
online-player gate and cadence, so they cannot keep bots running on an empty
server. They do not start or stop Apache or MySQL. The launchers find XAMPP's
`php\php.exe` by searching parent folders; adding PHP to PATH is not necessary
for a normal installation beneath `htdocs`. `START-LOCAL.cmd` is the separate PHP
development-server option and is not needed when Apache is serving the game.

If PHP is stored elsewhere, set `PHP_EXE` to its full path before starting the
worker. An explicit `PHP_EXE` takes precedence, followed by a PHP executable found
beside or above the game folder, then PHP on the system PATH. The launcher checks
PHP's version and PDO MySQL before starting the worker.

A new human keeper starts at zero rank. **Invasion** lists human and bot opponents, and **Rankings** shows both together. The home **Repair base** control appears when completed buildings need repairs. The 0.13.0 regional, Inferno and event content unlocks through normal play on this same installation.

## Updating an installed world to 0.13.1

Keep your database and existing `var` folder. Back up the project and database,
then stop Apache and any running world worker; leave MySQL running.

Use this complete 0.13.1 package. Replace application files while retaining
the existing `var` contents, including `config.php`, sessions and backups.
Keep the configured database name, even when it differs from the new-install
default `backyard_rehatched`. No database rename or fresh setup is required.

Open Command Prompt in the game folder and run:

```console
UPGRADE-WORLD.cmd --check
UPGRADE-WORLD.cmd --apply
```

The updater writes private SQL and JSON backups before changing saved content.
The migrator updates an existing 0.13.0 or 0.12.0 world on schema 3 and upgrades older schema-2 worlds as needed. Existing map sites, paid timers, purchased workers and saved layouts carry forward. Accounts, passwords, ranks and all 1,000
bot identities are preserved. A missing activity clock starts paused; upgrading
does not run bots or catch up old turns. Earlier releases receive only their
missing compatibility migrations, as described in `UPGRADING.md`.

After **Upgrade complete**, restart Apache, then sign in with the existing
account. Bot activity starts automatically. **Do not run setup again, reset
accounts or delete your saved world.**
See `UPGRADING.md` for backup and recovery details.

## If setup reports a problem

- **Database login rejected:** verify the database user and database password.
  For default local XAMPP, use `root` with a blank password. A game login password
  is unrelated to the database password.
- **Cannot reach the database:** check that MySQL is green/running in XAMPP and
  that the setup port matches the port shown there.
- **Database does not exist:** enable creation, or create an empty database with
  that name in phpMyAdmin first.
- **Database permission denied:** use an account allowed to create the schema,
  or prepare the empty schema and grant the selected account access to it.
- **World already installed:** open the game and sign in. Do not delete the
  database or `var/config.php` to retry installation over an existing world.

## If bots appear inactive

- Sign in and keep the game open with Apache and MySQL running. The login screen,
  anonymous visits and an open worker by itself do not count as an online player.
- Check the game's activity status. A disconnected or heavily suspended browser
  can lose its lease; returning to the game renews it. A retry/error indication
  means the last activity request or simulation step did not complete normally.
- Allow time for normal development and combat. A fresh bot has to pay for its
  army and win or lose a real raid before its rank moves; pulses do not award
  points. Rankings refresh automatically while the rankings page is visible.
- If all bots remain disabled, check whether your private `var/config.php` has
  `bots.enabled` set to `false`. Database or PHP errors should be checked in the
  local Apache/PHP error log; reinstalling would not fix those errors.

Native Windows launcher execution has not been verified in this Linux development
environment. The scripts use CRLF line endings, quoted paths and no fixed drive.
