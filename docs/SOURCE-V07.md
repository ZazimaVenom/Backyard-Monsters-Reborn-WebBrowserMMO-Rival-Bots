# Source audit: Map Room 3 Fortification network

Version 0.7 adds capturable Fortification sites to the persistent map. These are
separate from the individual-building fortification tiers introduced in 0.6.
The supplied `backyard-monsters-refitted` source is the reference below; paths
are relative to that archive. Its client is ActionScript and its refitted server
is TypeScript. The remake executes combat, ownership and progression on PHP.

## Identity, placement and capture

`server/src/enums/EnumYardType.ts` identifies the map Fortification as **104**.
`client/scripts/OutpostDefender.as:10` identifies its central building as **140**.
The building is a targetable, immobile core, not a firing tower. Its site is defended
by ordinary towers and monsters. `YARD_PROPS.as:7893–7963` defines its core data:

| Core level | Original HP | Original repair seconds |
|---|---:|---:|
| 1 | 8,800 | 1,920 |
| 2 | 42,000 | 7,680 |
| 3 | 200,000 | 30,720 |
| 4 | 400,000 | 86,400 |
| 5 | 600,000 | 345,600 |

Its five nominal construction costs are all five units of each resource and one
second. These placeholder-like values do not establish the remake's economy.
The original image data specifies a 32-frame animation. `OutpostDefender.as:14–40`
selects self, enemy, ally or neutral light animation from the relationship value.

`server/src/services/maproom/v3/generateCells.ts:77–95,113–134` generates six
separate Fortification bases around each Stronghold and Resource Outpost. These
are existing world locations to attack, not buildings placed on empty map hexes.
`findFreeSector.ts` and `tribeSaveV3.ts` also reserve/generate six defender positions
around a player's home. `getHexNeighborOffsets.ts` uses the same odd-row hex layout
as the remake. `MapRoom3Config.ts:146` selects defender challenge levels from the
parent type and level. `game-data/tribes/v3/defenders.ts` provides challenge levels
5 through 50: challenge 5/10 uses core level 1, 15/20 level 2, 25/30 level 3,
35/40 level 4, and 45/50 level 5. Challenge level and core level are different values.

`controllers/base/save/baseSave.ts:216` and
`services/maproom/v3/takeoverCellMR3.ts:44–113` capture an MR3 site when an attack
ends with at least 90% damage. Ownership transfers for that cell alone; its parent
and neighboring forts keep their owners. The old owner's outpost list loses that
cell, the new owner's list gains it, and the captured save clears resources,
monsters, attacks and recorded damage. The returned `fortified`/`weakened` popup
data is descriptive; the actual protection is recomputed from neighbor ownership.
The remake uses its existing server-authoritative destruction threshold and site
capture, repair, protection and garrison lifecycle.

## Protection rule

`config/MapRoom3Config.ts:144` defines this exact table, indexed by the number of
adjacent forts owned by the same keeper as the attacked base:

| Owned neighboring forts | Incoming building damage reduction |
|---|---:|
| 0 | 0% |
| 1 | 7% |
| 2 | 13% |
| 3 | 23% |
| 4 | 35% |
| 5 | 50% |
| 6 | 75% |

`controllers/base/load/baseLoad.ts:287–305` queries the six neighbor coordinates,
Fortification type, matching owner, world and map version. It does **not** filter
by the fort's building HP, repair timer or upgrade timer. A captured fort therefore
counts while repairing or upgrading. A fort owned by another keeper never counts.
The refitted server requires a nonzero owner on the attacked parent: neutral sites
do not obtain protection merely because neighboring forts are also neutral.

`services/maproom/v3/getDefenderCoords.ts:6–9` permits Player, Resource and Stronghold
parents. A Fortification cannot protect another Fortification. The client
`MapRoom3Cell.as:442–458` also tests parent type and matching user/tribe identity.
There is no alliance-wide armor share in the server ownership query.

`config/MapRoom3Config.ts:95–98` gives attack range to Resource Outposts and
Strongholds only; `controllers/maproom/v3/cells/playerCell.ts:61–69` leaves forts
at range zero. Forts provide no resource production, storage or damage bonus.
Their level changes the defenses to overcome, not their contribution to the
neighbor-count table.

`client/scripts/com/monsters/baseBuffs/buffs/BuildingDefenseBuff.as:17–25` applies
the armor to every `BFOUNDATION` building, not monsters. `BFOUNDATION.as:499–513`
applies completed individual-building fortification first, then network armor.
The two reductions multiply: damage × (1 − building reduction) ×
(1 − network reduction). Four building tiers plus six owned neighbor forts reduce
incoming damage by 87.5%, rather than adding the percentages beyond 100%. Maximum
HP and tower damage do not increase. Untargetable objects remain untargetable.

New format-seven invasions freeze the network protection when battle begins,
alongside the existing building fortification snapshot. A neighboring capture
changes later invasions, not the rules halfway through an active battle. All
building damage routes share the reduction. Earlier stored formats retain their
original snapshots and behavior.

## Bounded map adaptation and preservation

The existing 64 × 64 world already contains 1,000 persistent bot homes, player
homes, 256 Resource Outposts and 64 Strongholds. It cannot fit a disjoint six-cell
ring around every existing home and territory. Version 0.7 therefore generates
neutral forts only on currently empty neighboring cells of the 320 original
Resource/Stronghold sites, after preserving and allocating existing homes. It
does not consume the final 512 cells reserved for later accounts. If an existing
world already has fewer vacancies, no new forts are generated. Shared neighbor locations
are single capturable sites. Some rings are partial; existing homes and owned
territories are never moved to create room. Automatic home rings are not added.
An existing home that happens to be adjacent to a generated fort can still obtain
the source protection by owning it. One fort can protect multiple adjacent bases
belonging to the same keeper. Fort ownership has a separate allowance of six per
living Map Room level; Resource Outposts and Strongholds retain their combined
allowance of two per level. This separation is a remake balance choice, allowing
a keeper to hold a parent and its entire available ring.

The remake uses three paid fort-site levels. The passive core has 5,000 / 6,750 /
9,113 HP, with a Sniper Tower, Cannon Tower, Bunker and four walls. Neutral bunker
defenders scale with the site level. Upgrading from level 1 costs 4,000 twigs,
4,000 pebbles and 1,000 putty for 150 seconds; level 2 costs 8,000 / 8,000 / 2,000
for 300 seconds. Maximum repair cost is 500 twigs, 500 pebbles and 100 putty per
site level, proportional to damage, with a 60-second-per-level timer. Game speed
scales timers. Captures retain the established 300-second shield and
300/game-speed-second rebuild. These layouts, costs, timers and HP curves are
adapted to the existing remake economy; they are not the source's five core HP
levels or ten challenge templates. Higher fort levels improve their own defenses
and garrison capacity, never the network percentage contributed by one fort.

This is a one-time additive generation, recorded by
`settings.world_fortifications_version = 1`. The marker and new sites commit in
the same transaction as the upgrade. Further starts or upgrades cannot regenerate
captured forts. Site IDs, coordinates, owner, level, state JSON, revision and clocks
from an existing world are checked for exact preservation. SQL schema remains 2.
New neutral sites are world records, not extra player or bot accounts.

The provided source's home relocation path deletes every fort in the departing
home ring, including other keepers' captured forts (`removeDefenders.ts:9–18`).
The remake does not implement that relocation lifecycle or automatically create
home rings, so this destructive source behavior is not introduced here.

## Remaining source work

Full original MR3 world generation, home relocation and its ring lifecycle remain
unfinished. The current fixed map and scaled progression are explicit adaptations.
The original global worker capacity is also still outstanding: `QUEUE.as:31–76`
starts with one worker, with up to five in a main yard through the premium system;
non-main yards have one. `CanDo` and `Add` reject a new job when every worker is
busy. This is shared concurrent capacity, not a pending first-in-first-out job
queue. Existing remake buildings continue to work in parallel; changing that
requires preserving already-paid concurrent timers and a separate progression
and animated-worker pass.
