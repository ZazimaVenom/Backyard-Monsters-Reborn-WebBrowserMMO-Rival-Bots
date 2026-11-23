# Inferno

There are two ways to open a permanent entrance. For early access, select **Inferno** with a working level 2 surface Town Hall and pay 1,800 twigs, 1,600 pebbles and 600 putty. From Town Hall 5 you can also open the cavern for free by resolving the fifth earthquake defense in [Inferno Emergence](INFERNO-EMERGENCE.md). The cavern starts with an Under Hall, four harvesters, a Sharpshooter, a Flinger, an Incubator, a Compound and eight Spurtz.

## Build and hatch underground

The upper resource bar shows bone, coal, sulfur and magma while you are in the cavern. Click **Collect cavern supplies** to move the harvesters’ stored output into your vault. Production continues between visits, up to each building’s bin capacity. Resource Pods add 12,000 per level to the starting 12,000 capacity for each currency.

Use **Construct a building** and click its exact location on the cavern floor. Click a completed building to move or upgrade it. The cavern has one construction worker, a 70-structure limit and building-specific limits. The Under Hall reaches level 6. The Sharpshooter, Blast Tower, Quake Tower, Magma Tower and Inferno Blocks defend this yard. The advanced towers use their own level-specific damage, cost, timing and Under Hall requirements. A building’s services pause during construction or upgrade.

**Manage Inferno monsters** opens the Incubator, Locker and Academy orders. Incubators hatch in parallel. Each completed Compound level provides 400 housing space. Discover the eight native species and raise their combat levels using magma. Only the underground roster deploys on Moloch expeditions. The surface army, champion, siege inventory and artillery stay at home. See [Monster expansion](MONSTER-EXPANSION.md) for species, abilities and economy details.

## Invade Moloch

Open **Moloch expeditions**, scout a camp, inspect its structures, then choose **Invade this cavern**. Load the Flinger and click open ground. The ordinary combat simulation resolves individual attacks, defense fire, obstacles, movement and the result. Deployed monsters leave the underground army. Reloading resumes the active raid. Destroy the Under Hall or at least half of the initially standing target buildings to win. A win and a loss both produce a battle report. Campaign attacks preserve surface protection and ladder points.

| Route | Recovered camp IDs | Access in this release |
|---|---|---|
| Descent | 201–213 | 201 opens at entry. Each victory opens the following camp. |
| Outer caverns | 214–220 | 214 opens after the first descent victory. |
| Deep caverns | 221–227 | Continue from 220. |
| Molten core | 228–234 | Continue from 227. |

Each victory records the keeper’s best destruction score. Only the first clear earns the displayed supplies. Overflow remains pending until **Collect cavern supplies** has room to transfer it. Every camp is replayable. Each keeper has an independent campaign. The camps consume zero positions among the 1,000 persistent ladder bots.

## Ascend native monsters into your surface army

Clear all 13 Descent camps, 201 through 213. At a surface Monster Housing building, select **Ascend Inferno monsters**. The cavern’s **Ascend monsters to my backyard** button returns to the main yard and opens the same portal. Add the species and quantities you want, check the resulting housing space, then confirm. Returning to the cavern shows the corresponding reduction in Compound reserves.

Ascension is one-way, immediate and free. Only ready native monsters in your own Compound can travel. Eggs, champions, summoned creatures and surface species are ineligible. Each creature retains its original species and keeper research level. Existing surface troops and queued hatch orders count toward the destination space limit. Refreshing or retrying a transfer cannot duplicate it.

Ascended creatures appear in the surface army dock, can launch from the ordinary Flinger and can defend or return from a surface bunker. During an invasion, each deployed creature leaves surface inventory once. Undeployed creatures remain in the backyard. Hatch and research more of the species in Inferno.

## Scout and invade other keepers

Open **Underground rivals** from the cavern or the Invasion page. Filter to players or bots, search for a keeper by name and visit their actual underground yard. The directory contains keepers who have opened the entrance. You can inspect defenses before committing a monster. The opponent’s wallet, hatch orders and reserve army remain private.

A ranked cavern invasion uses the same shared ladder as a surface raid. The winner gains 25 points and the loser loses 15. Damage determines the amount taken from the defender’s four underground currencies, up to 10% of each balance and available attacker storage. Both keepers receive a battle report. The defender’s underground structures keep their damage and can be repaired immediately for free. A separate cavern shield lasts two minutes after the battle, or 30 minutes after a bot invades a human cavern. Launching a ranked cavern attack ends the attacker's cavern shield. The surface yard, army, bank and shield retain their independent state. Truces and the one-active-invasion-per-keeper rule apply across both realms.

Persistent bots can pay the same entrance fee, use their own native army and acquire rank points through underground attacks. Bot-driven invasions use the shared human-presence clock and resume when a keeper returns.

## Defend and repair

**Defend my cavern** invites a Moloch counterattack against the player’s actual underground layout. Each of eight progressively stronger waves uses native species. Defeating a wave opens the following wave. The final wave is repeatable. Combat uses the defender’s saved building positions, levels and current health. Reopening Inferno resumes an active counterattack. **End counterattack** withdraws the wave and applies damage already suffered. The result updates the cavern once and preserves surface defenses. After damage, click **Repair cavern · Free** for an immediate full repair.

## Source and adaptations

The supplied Refitted server’s `src/game-data/tribes/inferno/molochTribes.ts` contains the 34 original camp IDs and structure positions. `MAPROOM_INFERNO.as`, `INFERNOYARDPROPS.as` and the supplied localization identify the realm and native structures. The local 32-tile layouts center and scale supported source buildings, resolve footprint collisions and sample larger walls. Camps contain 13–78 structures. The first descent has one defending Sharpshooter and an opening that the eight starter Spurtz can clear.

The entrance purchase, initial settlement, two progression routes, first-clear parcels, compact economy, and eight opt-in defensive waves are remake rules. The separate Emergence event follows the five source portal stages and 12-hour intervals, with newly painted scenes and reconstructed camera movement. The supplied `INFERNOPORTAL.as`, `INFERNO_ASCENSION_POPUP.as`, `HOUSING.as` and `controllers/inferno/infernoMonsters.ts` supply the one-way native roster transfer and housing rules. `WMBASE.CheckDescentProgress` starts at one and counts destroyed camps, while `MAPROOM_DESCENT.DescentPassed` requires 14. The completed 13-camp Descent route enables Ascension here. Campaign combat uses the same server-side clock, movement and defenses as the other remake battlefields. No Flash runtime is required.

For the domain integration test, create an empty disposable database whose name ends in `_test`, then run `php tests/inferno_realm_integration.php` or `php tests/inferno_rivals_integration.php` or `php tests/inferno_ascension_integration.php` with `MYSQL_TEST_DATABASE` set to that database. Standard test host, port and credential environment variables are supported.

### Autonomous underground keepers

A persistent bot establishes its surface yard for at least four turns before considering an entrance. Subsequent eligible turns pay the same entrance price as a player. Caverns collect their own bones, coal, sulfur and magma. The cavern worker, Incubators, Compound capacity, discovery, research, defense upgrades and Flinger all use the normal player rules. Identity and personality affect building locations, defense priorities and monster choices. Native troops grow from the eight starter Spurtz and paid hatchery queues.

Once a cavern has troops and a working Flinger, its keeper periodically samples the indexed list of open caverns, respecting the server’s human-target setting, battle capacity, shared attack cooldown, truces, shields and active battles. Successful raids use the normal battlefield, later deployment waves, defensive fire, real losses, underground loot and shared keeper rankings. A fresh human presence lease wakes both layers. Empty-server time pauses bot production, construction, discovery, research, incubation, combat and protection timers.

The disposable database test in `tests/inferno_bots_integration.php` advances clocks without granting completed projects, resources, combat results or rank. Two freshly seeded bots pay for entrances, create different yards, discover and hatch native species, pause/resume and fight a ranked native battle.

### Compound interception

A working Compound assigns a portion of the existing native army to intercept invasions and Moloch counterattacks. Its six detection radii are 12.5, 13.25, 14, 14.75, 15.5 and 16.25 tiles. Balthazar and Sabnox can launch against airborne targets. Other residents leave when a visible ground enemy approaches. Interceptors use the keeper’s researched statistics, appear as ordinary animated combat units, choose the approaching target and can be killed. Multiple Compounds share the available army and their existing 400-space-per-level capacity. Queued eggs stay out of the battle.

Only a new battle freezes residents. A fallen interceptor leaves the native army once at settlement. Destruction of its home Compound uses the earlier client’s complete-loss rule for remaining assigned residents. The source’s alternate Map Room 3 injury rule depended on persistent individual HP. Surviving units return to the local count-based army. Repairing a Compound restores the facility; lost residents must hatch again. The battle result lists casualties, and both the ranked report and the Moloch result can be reopened safely.

The original behavior comes from entry 128 in `INFERNOYARDPROPS.as` and the dispatch, targeting and destruction paths in `HOUSINGBUNKER.as`. Historic saves without a resident snapshot keep their previous combat rules.
