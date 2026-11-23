# Aerial Defense Tower and Heavy Trap

At Town Hall level 4, open **My backyard → Build something**, select a defense and click a free spot in your yard. Both buildings use a construction worker. Work time follows the game speed configured for your world.

| Defense | Twigs | Pebbles | Putty | Base build time at 1× | Allowed at Town Hall 4 / 5 |
|---|---:|---:|---:|---:|---:|
| Aerial Defense Tower | 2,150 | 2,800 | 625 | 60 seconds | 1 / 2 |
| Heavy Trap | 500 | 500 | 500 | 5 seconds | 4 / 6 |

The **Aerial Defense Tower** fires at airborne attackers. Place it near buildings that ground-only towers struggle to defend against flyers. Its shots cycle through nearby air targets and deal splash damage to surrounding flyers. A damaged tower hits less hard. Upgrade it to extend its range and buy more shots per volley, or purchase fortification to help the tower withstand incoming damage.

| Tower level | Shots per volley | Full-health damage per direct hit before bonuses | Range in yard tiles |
|---:|---:|---:|---:|
| 1 | 4 | 100 | 7.5 |
| 2 | 4 | 125 | 8 |
| 3 | 6 | 125 | 8.5 |
| 4 | 8 | 125 | 9 |
| 5 | 10 | 150 | 9.5 |

The Heavy Trap allowance rises to 8, 10, 12, 15 and 18 at surface Town Hall levels 6 through 10.

The **Heavy Trap** is concealed from rivals until it fires. Crabatron, Project X, D.A.V.E. and grounded champions can trip it. A Pokey can walk past safely, but can still be caught in the blast when a heavy monster follows. Damage falls with distance. Nearby flyers take half the damage of a ground target at the same distance. A trap fires once. After the invasion, click **Repair base** to rearm it for free.

Bots can pay to construct both defenses. Mature guardians favor extra traps. A bot uses the same home repair action as a player during an eligible turn while a human is playing.

## Source and local balance

The supplied Flash client identifies these buildings as 115 and 117. `BUILDING115.as` defines the volley sequence, air targeting and the damage multiplier `0.5 + 0.5 × current HP / maximum HP`. `BHEAVYTRAP.as` filters the species that can trigger the trap and computes distance-based damage and reduced air damage. `BTRAP.as`, `BUILDING117.as` and `Targeting.as` provide the trap and target selection context. The corresponding building rows are in `ORIGINAL-CATALOG.json`.

The remake uses the first five tower levels, its own resource/time scale, a fixed simulation step and local yard distances. The Heavy Trap supports the currently playable roster. Its new artwork, sounds and Canvas effects were made for this version.
