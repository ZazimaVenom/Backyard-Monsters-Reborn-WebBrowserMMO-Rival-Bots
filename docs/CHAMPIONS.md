# Champions in 0.6.0

The remake includes Gorgo, Drull and Fomor as persistent individuals. A champion is
separate from ordinary monster inventory: it cannot be hatched repeatedly, put in
a bunker or recycled in the Juicer. One champion can be active at a time. Its
health, evolution, feeding progress and frozen state are saved with its owner's
yard, including for bots.

## Raise and evolve

1. Reach Town Hall level 4, then build a **Champion Cage**. A new or existing world
   receives no free Cage or champion during installation or upgrade.
2. Choose Gorgo, Drull or Fomor in the Cage. Raising is immediate, costs no extra
   resources and creates a level-one champion at full health. This follows the
   supplied client's `RaiseGuard` behavior; there is no invented hatching queue.
3. Once its feeding timer expires, provide the complete displayed meal from the
   reserve army. Feeding checks every ingredient before consuming any monsters.
   Queued monsters and bunker garrisons do not count toward a meal.
4. Complete 3, 6, 9, 12 and 15 meals to advance through evolution levels 2–6.
   Evolution resets the feed count and restores the new maximum health. An
   ordinary meal does not heal the champion.

At the default game speed a champion digests for five minutes between meals.
This is an intentional local-remake adaptation of the original 23-hour interval.
The configured game speed shortens digestion and recovery, but the hunger grace
period remains 24 real hours. After that grace period, each missed day removes
one accumulated meal, down to zero; evolution levels never regress. Repeated
page loads cannot apply the same hunger interval twice.

| Current level | Gorgo's meal | Drull's meal | Fomor's meal | Meals to evolve |
| --- | --- | --- | --- | --- |
| 1 | 15 Octo-Ooze | 30 Pokey | 20 Bolt | 3 |
| 2 | 10 Octo-Ooze + 5 Ichi | 20 Pokey + 15 Fink | 20 Bolt + 2 Brain | 6 |
| 3 | 20 Ichi | 50 Bandito | 40 Bolt + 5 Brain | 9 |
| 4 | 10 Ichi + 10 Crabatron | 10 Bandito + 15 Fang | 30 Bolt + 10 Brain | 12 |
| 5 | 20 Crabatron | 30 Fang | 20 Brain | 15 |
| 6 | 20 Crabatron | 30 Fang | 20 Brain | Three bonus tiers, then maintenance |

The recipes follow the supplied non-Map-Room-3 source branch. At evolution six, each new meal earns one of three food-bonus tiers. The new tier increases current health only by the increase in maximum health, preserving existing injuries; a defeated champion stays at zero until passive recovery. At tier three, maintenance meals preserve power and grant no instant healing. Overdue hunger removes one bonus tier per 24-hour step, while evolution stays at six. See `CHAMPION-BONUSES.md` for the exact adapted statistics, timing and migration rules. Old maintenance meals do not retroactively earn bonuses.

## Keep and switch champions

Build a **Champion Chamber** after completing the Cage. The complete roster is
limited to one individual of each type, three total. Without a living Chamber,
you can raise only your first champion.

Freeze the active champion before raising or thawing another. Freezing requires
an active champion at full health, a still-running feeding timer and living,
completed Cage and Chamber buildings. If its feeding timer is already due, feed
it first. The Chamber pauses its remaining feeding timer; frozen champions do
not recover health or lose feeding progress or bonus tiers while away.

Thawing requires an empty active slot, a living Cage, and a completed **fully
repaired** Chamber. It restores the paused timer without granting health. All
three champions may briefly be frozen while switching between them; this avoids
trapping a player with an active champion and two occupied frozen slots. Total
roster capacity remains three. No champion changes are permitted while the yard
is involved in an invasion.

## Health and battlefield roles

At rest, the active champion recovers linearly over the following full-recovery
times at normal speed. Partial injuries require proportionally less time.

| Evolution level | 1 | 2 | 3 | 4 | 5 | 6 |
| --- | --- | --- | --- | --- | --- | --- |
| Full recovery | 3 min | 6 min | 12 min | 24 min | 48 min | 96 min |

Recovery continues through elapsed time when the game is reopened. It is paused
while the champion is locked into a battle or frozen in the Chamber. A defeated
champion remains owned at zero health and begins recovering after settlement.
Battle results save deployed attacker and defending champion health once;
an undeployed attacker champion is not injured by ordinary troop losses.

- **Gorgo** is a slow, durable melee tank. It targets ground enemies.
- **Drull** is a fast, high-damage melee attacker. It targets ground enemies.
- **Fomor** is a ranged support champion, grounded at levels 1–2 and flying from
  level 3. Its six-unit aura affects nearby allies, excluding itself: movement
  and attack speed are multiplied by `1 + 2 × aura strength`, while incoming
  damage is reduced by the aura strength. Baseline strength rises from 10% to 60%; level-six bonus tiers add 3%, 6% or 15% cumulatively. Fomor
  does not heal allies; the aura ends when allies leave its radius or Fomor dies.

A healthy-enough active champion with a living, completed Cage can be selected
and deployed once at a legal clicked point during an invasion. It uses its own
launch control, independent of the Flinger bucket and reload. A defender's active
champion guards the yard from the Cage. These rules use the same authoritative
battle simulation for human players and bots.

The combat numbers and recovery times are scaled for this remake's smaller yard
economy. They are not a literal copy of all original champion statistics. Full
source references and remaining fidelity differences are in
`CHAMPION-SOURCE.md` and `PARITY.md`.

## Brain and Crabatron

The feeding system also adds the source's **Brain (C9)** and **Crabatron (C10)**.
Project X is C11 and is not one of these two additions. Both require Town Hall 3
and Monster Locker 3, use the existing paid discovery queue and are hatched with
goo after discovery. Both start locked with zero inventory in existing worlds.

| Monster | Discovery | Hatch | Housing | Base role |
| --- | --- | --- | --- | --- |
| Brain | 10,000 goo + 1,500 putty; 150 sec | 4,000 goo; 90 sec | 30 | Fast resource hunter; 600 HP, 100 damage |
| Crabatron | 14,000 goo + 2,000 putty; 180 sec | 6,000 goo; 120 sec | 40 | Armored defense hunter; 4,000 HP, 100 damage |

Existing research, garrison, training cancellation and recycling rules apply to
these two ordinary species. Champions remain outside those inventory systems.

## Verification

See `VALIDATION.md` for current pure, database and browser checks, including earned bonus meals, hunger boundaries, frozen individuals and battle settlement. `tests/README.md` explains the isolated test databases; never point regression suites at a player world.
