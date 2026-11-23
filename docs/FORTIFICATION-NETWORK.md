# Fortification network

Version 0.7 adds capturable **Fortification sites** to the persistent hex world. These are separate from the individual building **Fortify** work introduced in 0.6. Each site has an Outpost Defender core, towers, walls and a Monster Bunker. The core is targetable and does not fire; the towers and stationed monsters defend it in the normal animated invasion.

## Capture and ownership

Build or repair a Map Room and Flinger, hatch an army, then select a reachable Fortification on the world map. Invade using your produced monsters and normal deployment controls. Destroy at least **90% of eligible maximum building health** to capture it. Walls, traps and untargetable structures such as the Resource Outpost core are excluded from that calculation; the targetable Outpost Defender core counts. This is the same territory capture rule as resource outposts and strongholds. Rank rewards follow the ordinary battle result separately.

Capture transfers that site alone. Neighboring homes, outposts, strongholds and Fortifications retain their owners. Captured sites lose their former stationed defenders, receive a 300-second shield and rebuild on the normal game-speed clock. Abandoning a site clears ownership and garrison without a monster refund. Sites remain on the map.

Each living Map Room level permits two ordinary holdings and **six separate Fortifications**:

| Map Room level | Resource outposts / strongholds | Fortification sites |
| --- | ---: | ---: |
| 1 | 2 | 6 |
| 2 | 4 | 12 |
| 3 | 6 | 18 |

Reaching the ordinary holding limit does not prevent Fortification invasions. The Fortification limit does not consume ordinary holding slots. A destroyed or unfinished Map Room cannot start either campaign. Owned sites remain owned if the Map Room is damaged.

Fortifications produce **no resources**, add **no storage**, provide **no attack range** and grant **no outgoing damage bonus**. Reach still comes from your home, resource outposts and strongholds. Bots follow these same rules and spend their own resources and monsters.

## Adjacent protection

A home, resource outpost or stronghold gains damage reduction from Fortification hexes directly next to it **owned by the same keeper**. Hex adjacency uses the map's alternating rows, not a square radius. Neutral sites have no owner and receive no network protection. Fortifications do not protect other Fortifications.

| Same-owner neighboring Fortifications | Incoming building damage reduced |
| ---: | ---: |
| 0 | 0% |
| 1 | 7% |
| 2 | 13% |
| 3 | 23% |
| 4 | 35% |
| 5 | 50% |
| 6 | 75% |

Protection depends on the cell's ownership. A damaged, upgrading or rebuilding Fortification still contributes. Upgrading it strengthens its own fixed defenses and bunker; it does not change the table above. A single owned cell can protect several eligible adjacent sites owned by that keeper. A Fortification that happens to border a home also protects that home.

The server snapshots protection at invasion start. Capturing or abandoning a supporting cell changes **future invasions**. A battle already in progress keeps its saved network. New format-7 battles apply network reduction to incoming building damage from the shared damage calculation, including direct attacks, splash, chain, death bursts and Catapult damage. Monsters and champions receive no network armor. Earlier saved battle formats retain their original behavior.

Network protection multiplies with completed individual building Fortify armor. For example, six cells reduce damage to 25%; tier-four individual Fortify reduces that remaining damage to 50%, leaving **12.5% of the original damage**. Neither system adds health or heals buildings. See [NETWORK-COMBAT.md](NETWORK-COMBAT.md) and [FORTIFICATION.md](FORTIFICATION.md).

## Maintaining a site

Owned sites support paid upgrades, proportional repair, garrison transfers and abandonment through the territory panel. All troops come from the owner's ready army and return only when room is available in home housing. Rebuilding or upgrading blocks transfers. Repair the bunker before stationing monsters.

The compact remake has three Fortification levels. Its core starts at 5,000 HP, using the normal 1.35× per-level building health scale; towers and walls use their normal catalog stats. Bunker capacity is 100 / 200 / 300 housing.

| Current level | Upgrade twigs | Upgrade pebbles | Upgrade putty | Upgrade seconds | Full repair twigs / pebbles / putty | Repair seconds |
| ---: | ---: | ---: | ---: | ---: | --- | ---: |
| 1 | 4,000 | 4,000 | 1,000 | 150 | 500 / 500 / 100 | 60 |
| 2 | 8,000 | 8,000 | 2,000 | 300 | 1,000 / 1,000 / 200 | 120 |
| 3 | — | — | — | — | 1,500 / 1,500 / 300 | 180 |

These actions cost no goo. Repair costs scale with total damage across the site's buildings, rounded up per resource. Work times are divided by the configured game speed. Ownership support continues while the work completes.

## Saved worlds and map space

The map remains **64×64**. The installer creates the 320 fixed resource / stronghold sites and places every existing player's and bot's home first. Only then does it generate neutral Fortifications in vacant neighboring hexes of those 320 territory sites. Coordinates shared by several potential rings create one cell. Occupied hexes of every kind are preserved. The source's larger world also places complete rings around homes; this compact preserved map cannot do that for 1,000 bots without moving homes.

Generation is deterministic, reserves at least **512 vacant cells** for future registration and runs once, recorded by `settings.world_fortifications_version=1`. A fresh world with exactly 1,000 bots has **1,270 neutral Fortifications** and **1,506 vacant coordinates** before player registration. Existing worlds can have different counts and incomplete rings because saved occupancy takes precedence. A crowded world can receive fewer or no new cells safely. Later registrations and initializer calls do not refill gaps or add new rings.

The network uses existing schema version 2 tables; no new database schema is required. Existing installations use the preservation upgrade described in [UPGRADING.md](UPGRADING.md). Do not reinstall or rerun setup on an existing save. Setup remains a local database-only installation with 1,000 persistent bots; players create their accounts afterward.

## Source and adaptations

The original MR3 Fortification cell kind is **104**, with Outpost Defender building **140**. Exact adjacency, the seven-value reduction table, eligible parent kinds, ownership-only support, passive targetable core and isolated 90% capture are source-derived. Source challenge templates use five core levels and very different health / repair scales. The remake's three fixed-defense levels, costs, 18-site maximum, local map size and reserved registration space are deliberate economy and persistence adaptations. See [SOURCE-V07.md](SOURCE-V07.md) for source locations and the parity boundary.

## Validation

`tests/fortification_network.php` checks parity geometry, support rules, deterministic bounded seeding, the 1,000-home occupancy plan, passive core identity, fixed layouts, upgrades and repairs without a database.

`tests/fortification_network_integration.php` runs only with `BYM_NETWORK_TEST_RESET=1` on a separately named `*_network_test` database. It checks installation, saved-row preservation, registration, ownership and support, public views, separate limits, candidates, real garrison conservation, paid work, capture, abandonment and the one-time marker. A dense level-three Stronghold regression retains all six eligible adjacent support cells in every scouting window while successive seeded windows explore beyond a fixed 160-row prefix. `MYSQL_TEST_HOST`, `MYSQL_TEST_PORT`, `MYSQL_TEST_USER` and `MYSQL_TEST_PASSWORD` select the test server; `BYM_NETWORK_TEST_DATABASE` selects the disposable database.

Verified with PHP 8.4.27: **2,738 pure assertions** and **1,839 database assertions** on both MySQL 8.0.44 and MariaDB 10.4.32. Existing World coverage also passed 2,147 pure assertions and 641 database assertions on each engine before the Fortification-only scouting refinement; the ordinary candidate branch was unchanged by that refinement.
