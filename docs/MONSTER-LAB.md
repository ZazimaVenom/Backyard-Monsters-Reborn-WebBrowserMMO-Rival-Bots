# Monster Lab and Project X — 0.4.0

The Monster Lab adds the ten ordinary-monster powers defined by the supplied
`MONSTERLAB.as` client. These are separate from Academy levels. Academy training
raises a species' normal statistics; a Lab study permanently unlocks or improves
its special combat behavior. Champions do not use Lab research.

## Research an ability

1. Reach Town Hall level 3 and construct a **Monster Lab**. It costs 5,000 twigs,
   5,000 pebbles and 1,500 putty and takes 120 seconds at normal game speed.
2. Discover the species in the Monster Locker if it is not already available.
3. Train that species in the Monster Academy. Ability tier 1 needs monster level
   2; tier 2 needs level 3; tier 3 needs level 4.
4. Upgrade the Lab to at least the desired ability tier. The building has three
   levels, and only one Lab may be built in a yard.
5. Start the study and pay its displayed putty cost. The Lab has one shared queue.
   The ability becomes available only after the saved completion time.

Starting a study requires a completed, living Lab. A paid study continues if the
building later takes damage; repairs are required before starting another.
Finish or cancel the study before upgrading the Lab itself. Research and
cancellation are blocked while the yard is involved in an invasion.

Cancel an unfinished study to refund its recorded putty payment. The ordinary
resource storage limit applies: any portion that does not fit is discarded and
reported as overflow. A completed or already-cancelled study cannot be refunded.
Changing game speed affects newly started study durations, not the completion
stamp of an existing job.

## Available studies

The following costs and study times are deliberate remake economy tuning. The
source uses much larger putty prices and day-long studies. All three tiers must
be learned in sequence; clients cannot select a later tier directly.

| Species | Ability | Tier 1 / 2 / 3 putty | Tier 1 / 2 / 3 seconds |
|---|---|---|---|
| Bolt | Blink | 1,500 / 2,250 / 3,375 | 90 / 180 / 270 |
| Fink | Extra Claws | 2,000 / 3,000 / 4,500 | 120 / 240 / 360 |
| Bandito | Whirlwind | 2,500 / 3,750 / 5,625 | 150 / 300 / 450 |
| Fang | Venom | 3,000 / 4,500 / 6,750 | 180 / 360 / 540 |
| Eye-Ra | Airburst | 4,000 / 6,000 / 9,000 | 210 / 420 / 630 |
| Brain | Cloak | 4,000 / 6,000 / 9,000 | 210 / 420 / 630 |
| Project X | Acid Spores | 5,000 / 7,500 / 11,250 | 240 / 480 / 720 |
| D.A.V.E. | Rocket Barrage | 7,000 / 10,500 / 15,750 | 360 / 720 / 1,080 |
| Wormzer | Seismic Emergence | 5,500 / 8,250 / 12,375 | 270 / 540 / 810 |
| Teratorn | Ricochet | 6,000 / 9,000 / 13,500 | 300 / 600 / 900 |

Pokey, Octo-Ooze, Ichi, Crabatron and Zafreeti have no invented Lab power. Their
ordinary Academy upgrades and native combat behaviors remain available. The
field guide and Lab interface describe the implemented powers. Brain stays
cloaked while moving and for one, two or three seconds after arriving, then
becomes visible; this follows its actual source component rather than the
conflicting older Lab description. Bandito's extra sweep strikes nearby ground
monsters even when its primary attack is against a building. Combat snapshots
learned powers when a battle starts; finishing a study afterward does not change
units already committed to that invasion.

## Project X

Project X is the original **C11** species. It starts locked with zero inventory
when installing or upgrading. It requires Town Hall level 4 and Monster Locker
level 4. Discovery costs 16,000 goo and 2,500 putty and takes 180 seconds; each
monster then costs 6,500 goo and takes 120 seconds to hatch. Its housing weight is
60, base health is 3,200, damage 260, speed 1 and melee range 0.85. Those values
are remake tuning.

Project X prioritizes defensive buildings and responds to nearby enemy monsters,
like the source's other tower hunters. It uses normal training lanes, Academy
research, Lab studies, bunker
transfers, recycling and battle inventory. Learning Acid Spores does not grant
free monsters. Its death effect is a burst, not a lasting acid pool; a separate
source variant contains pool behavior, described in the source audit.

## Persistent state and upgrades

Each yard saves `lab.levels` and one optional `lab.queue`. A pending job records
its species, target tier, start/completion timestamps and exact paid putty. The
normalizer adds missing tier-zero entries and an empty queue without accruing
anything, resetting existing values or removing extension fields. Existing
players and all persistent bots retain their identities, armies, resources,
champions, buildings, rankings and earlier queues.

The PHP actions run under the same base row lock and invasion guard as ordinary
yard actions. Starting a study checks every prerequisite before spending putty.
Completion is idempotent, and an older completed job cannot downgrade a species
that already has a higher ability tier.

## Verification

`tests/lab_progression.php` passed 172 assertions covering migration, all ten
three-tier definitions, source prerequisites, the eleven resource-bomb entries,
queue timing/idempotence, and unknown extension preservation.

`tests/lab_integration.php` passed 134 assertions through the actual MySQL
services: paid construction, all thirty ability tiers, discovery and Academy
gates, single-queue enforcement, storage-capped cancellation, completion after
Lab damage, upgrade restrictions, game-speed timing, active-battle guards, and
Project X discovery/training/garrison/recycling conservation. The previous backend
44, progression 56 and champion 86 integration assertions also passed. Test data
is rolled back; schema creation itself is not rolled back. Use only a disposable
test database and follow `tests/README.md`.
