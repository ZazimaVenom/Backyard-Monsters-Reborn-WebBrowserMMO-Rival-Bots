# Free home-base repair — 0.8.2

After an invasion has finished, return to **My backyard** and press **Repair base**.
The button shows how many existing buildings are damaged. One click restores them
all immediately, for free, including destroyed buildings, defensive towers, walls,
and spent traps. The yard redraws from the saved server result, so ruined-building
art and damage indicators clear with the repaired health.

There is no resource charge, repair timer or worker requirement. A completely
empty resource bank can repair a whole home. Individual building repair uses the
same free rule. Pressing repair again after completion cannot grant extra health,
resources or rewards.

## What remains intact

Repairs change existing building HP to its saved maximum. Building positions, IDs,
levels, earned fortification, factory contents, resource balances, saved layouts,
queues, worker purchases, shields and ranks remain intact. Normal elapsed-time
production may accrue when the server reads the home, as it does for other actions.

A level-zero building is new construction and still needs its paid build time.
Existing buildings under upgrade or fortification can recover their current HP,
but their paid job and service restrictions continue until its timer completes.
Repairing buildings does not replace lost army or bunker monsters or heal champions.
Owned map territories retain their existing separate repair actions and costs.

## Invasion protection

Only the signed-in keeper can repair their home. The endpoint uses the normal
POST/JSON/CSRF rules and obtains the home lock before checking for an active battle.
Repairs are rejected while that keeper participates in any active invasion. This
includes attacks on owned territories. The UI hides the home control in rival
visits, territory views, battles and Yard Planner drafts. Duplicate requests are
safe; a repeat repair on a healthy home does nothing.

## Bots

Bots automatically use this same full-home repair on their next scheduled active
turn, before continuing development. They need no resources for home repairs and
cannot heal during an active invasion. They keep the online-only behavior from
0.8.1: no player presence means no autonomous turns, including repairs. When a
player returns, due bot turns resume normally; there is no population reset.

## Updating

Use the 0.8.1-to-0.8.2 patch only over 0.8.1. Keep the existing database and private
var folder, stop Apache and any optional worker, leave MySQL running, then run
UPGRADE-WORLD.cmd --check followed by UPGRADE-WORLD.cmd --apply. Restart Apache and
refresh the game. The migration preserves existing damage; repairs happen only
when a player requests them or an online bot takes its next turn. Setup remains a
local database-only installer and should not be rerun for an existing world.

See VALIDATION.md for checks actually run and the runtime verification limit in
this release. Tests are supplied for execution with PHP 8.2+ and a disposable
MySQL/MariaDB schema; never run destructive fixtures on the saved game database.
