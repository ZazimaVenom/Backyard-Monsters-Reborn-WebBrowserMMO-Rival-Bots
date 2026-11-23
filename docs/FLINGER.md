# Launching a Flinger bucket

In a new invasion, select your hatched monsters in the army tray. The Flinger shows the selected weight and its capacity. Move the pointer over the enemy yard. A green ring marks a clear drop zone and a red ring marks an unavailable launch. Click the point where you want the center of the swarm to land. The entire bucket launches together. After five seconds of battle time you can fire again. Each ordinary attacker leaves your trained army when the server accepts the launch.

| Flinger level | Space per launch |
|---|---:|
| 1 | 250 |
| 2 | 850 |
| 3 | 1,500 |
| 4 | 2,500 |
| 5 | 3,500 |

| Monster | Bucket space | Monster | Bucket space |
|---|---:|---|---:|
| Pokey | 7 | Brain | 30 |
| Octo-Ooze | 10 | Crabatron | 40 |
| Bolt | 15 | Project X | 70 |
| Fink | 20 | D.A.V.E. | 160 |
| Eye-Ra | 60 | Wormzer | 70 |
| Ichi | 20 | Teratorn | 70 |
| Bandito | 20 | Zafreeti | 200 |
| Fang | 30 | | |

For example, level one can launch a D.A.V.E., an Ichi and ten Pokeys together (160 + 20 + 70 = 250). Housing has its own capacity and species sizes. Drop zones ignore traps, ruined buildings and the untargetable outpost core. Loads above 800 space gradually need more open ground. The preview and server use the same building footprints. Each monster lands separately. Surviving defenders and the shape of the terrain can make two nearby launches play out differently.

Bots pay the same monster weights and use the same cooldown, ground checks and scatter as players. They try another side of the yard when their approach is blocked and can split large loads to fit smaller openings.

## Source and adaptations

The supplied Refitted client lists building 5 capacities in `client/scripts/YARD_PROPS.as`. The fifteen species weights come from the `bucket` property in `server/src/game-data/stats/monsterStats.ts`. The calculations follow the standard-yard branch of `ATTACK.as` in `BucketAdd`, `BucketUpdate` and `Spawn`, and the ground checks in `DROPZONE.as` and `BASE.BuildingOverlap`.

The original derives a drop diameter in pixels using the larger of 200 and the integer bucket weight divided by four. The click supplies the center. Each monster samples a separate angle and a linear radial distance below one quarter of that diameter in the projected ground plane. The remake converts at 80 source pixels per horizontal yard tile, uses its own isometric camera and square building footprints, and resamples off-map landings. Its repeatable per-unit draws allow a transaction retry or saved invasion to produce the same result.

This release uses per-launch capacity for home, territory and expedition battles. The original MR3 cumulative allowance belongs to a different ruleset. A qualifying alliance war or an active Declare War power grants 25% extra space once, rounded down. The remake keeps its existing ceiling of 200 ordinary attackers per invasion and its champion's separate once-per-invasion slot. New rules are frozen when a fight starts. A battle already active before this update retains its original housing weights, compact spiral, previously planned bot launches and ruin exclusion.

A qualifying accepted alliance war increases the normal bucket capacity by 25%, rounded down, and extends that raid to seven minutes.
