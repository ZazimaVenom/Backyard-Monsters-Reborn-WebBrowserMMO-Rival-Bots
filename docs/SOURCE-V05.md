# Source audit: Map Room, Resource Outposts and Strongholds

This expansion follows the **Map Room 3** systems found in the supplied source. It is a scaled local-world adaptation, not a full port of every Map Room version. Paths below are relative to `original/backyard-monsters-refitted/` in the supplied source archive. The remake's live rules are in `World.php`, `BattleEngine.php`, `BattleService.php` and the world documentation.

## Exact original identities

| Original content | Building ID | Primary source | Relevant behavior |
|---|---:|---|---|
| Map Room | 11 | `client/scripts/BUILDING11.as`, `YARD_PROPS.as` | One per yard; map progression is distinct from ordinary building statistics. |
| Stronghold | 138 | `client/scripts/GuardTower.as` | Targetable, immobile tower/core; Tesla components attack ground and air. |
| Resource Outpost | 139 | `client/scripts/ResourceOutpost.as`, `YARD_PROPS.as` | Untargetable core with 1 HP. Surrounding defenses are the invasion targets. |
| Outpost Defender | 140 | `client/scripts/OutpostDefender.as` | A separate fortification-cell core. **Not included in v0.5.** |

Building IDs are not map-cell enums. `server/src/enums/EnumYardType.ts` defines PLAYER=101, RESOURCE=102, STRONGHOLD=103 and FORTIFICATION=104, with MAIN_YARD=0 and OUTPOST=1 for yard categories. The remake uses readable `home`, `resource` and `stronghold` values for persisted sites and `maproom`, `outpost` and `stronghold` building keys.

New structure illustrations were generated using the source identities and established remake art direction. Relevant original visual references are `server/public/assets/buildings/maproom/top.1.png`, `guardtower/top.v2.1.png` and `resourceoutpost/top.v2.1.png`. The new PNGs do not reproduce the full original animation-frame library.

## Map geometry and scale

`server/src/enums/MapRoom.ts` defines MR2 as 800 × 800 with a 2,500-player capacity, and MR3 as 500 × 500 with a 1,000-player capacity. MR2's square, wrapping coordinates are a different system from MR3's odd-row offset hex grid.

`server/src/services/maproom/v3/getHexNeighborOffsets.ts` supplies odd-r neighbors and cube-distance conversion. With `q` as column and `r` as row, convert to cube coordinates using `x = q - (r - (r & 1)) / 2`, `z = r`, `y = -x-z`. Hex distance is the maximum absolute difference between the three cube components. The six neighbors differ by row parity. `controllers/maproom/v3/getCells.ts` bounds source viewport requests and constructs one-based cell IDs.

The v0.5 map is **64 × 64, finite and nonwrapping**, with the existing 1,000 persistent bots, actual registered players, 256 Resource Outposts and 64 Strongholds. This intentionally changes density and scale, including the source configuration's minimum seven-hex home spacing. Neutral sites are persisted map records, never additional bot or player accounts. Home positions and ownership come from MySQL; the browser only shades terrain and renders the records it receives. No random client markers stand in for keepers.

## Reach and economy

Original values below come from `server/src/config/MapRoom3Config.ts`; automatic banking is implemented in `server/src/controllers/base/load/baseLoad.ts`.

| System | Source MR3 | v0.5 adaptation |
|---|---|---|
| Home reach | 10 hexes | 10 hexes for territory invasions |
| Resource Outpost levels | 10 / 20 / 30 / 40 / 50 | 1 / 2 / 3 / 4 / 5 |
| Resource reach | 2 / 3 / 4 / 5 / 6 | Same distances |
| Production, each resource/sec | 1 / 2 / 5 / 11 / 23 | 0.05 / 0.1 / 0.2 / 0.4 / 0.8, affected by game speed |
| Added storage, each resource | 175,000 / 350,000 / 750,000 / 2,000,000 / 4,000,000 | 2,000 / 4,000 / 8,000 / 16,000 / 32,000 |
| Stronghold levels | 30 / 40 / 50 | 1 / 2 / 3 |
| Stronghold reach | 10 / 15 / 20 | Same distances |
| Stronghold damage bonus | 10 / 20 / 30% | Same percentages, snapshotted for territory combat |
| Map Room requirements | Original initial Town Hall 1; later map upgrades require Town Hall 6 | Paid ordinary construction at Town Hall 3; holdings limits 2 / 4 / 6 |

The original outpost banks **all four** ordinary resources at the same rate, rather than producing one selected resource. This is retained with scaled production/storage. An ownership or rate change must accrue the old rate first; new holdings cannot inherit an earlier owner's unearned income. Losing capacity must not erase an already-held surplus.

Source Stronghold bonuses sum only when the target is in an owned Stronghold's range. The attacker receives monster damage and the defender receives tower damage. The source's `if (distance && ...)` check mistakenly omits distance zero; the remake uses explicit numeric distance handling. Bonuses must be applied once from the battle-start snapshot, not compounded by repeated polls.

The accessible map's “Within reach” label is **geographical distance only**. A living completed Map Room, holding capacity, Flinger, available army/champion, shield, ownership and active-battle checks remain server requirements. The `worldSite` response supplies final attack availability. Existing home-yard invasion pages deliberately keep their established rules without strategic range restrictions.

## Capture is not ordinary destruction

`client/scripts/ATTACK.as`, `CalculateBaseDamagePercent`, measures `100 - currentHealth / maximumHealth × 100`, excluding walls and with questionable trap/expired-item conditions in the supplied code. It does not simply count destroyed buildings.

`server/src/controllers/base/save/baseSave.ts` invokes `services/maproom/v3/takeoverCellMR3.ts` after an attack ends with at least **90% damage** against a Resource, Stronghold or Fortification site. The transfer removes the previous owner's holding, assigns the attacker, updates persisted cell/base ownership and clears saved resources/monsters/attacks. There is no separate MR3 resource purchase in that path.

The remake therefore carries separate ordinary `destruction` and territory `captureProgress` metrics. Capture uses 90% **HP-weighted** damage, explicitly excluding walls, traps and the untargetable resource core. Existing ordinary victory remains Town Hall destruction or 50% counted-building destruction. Winning a battle does not by itself mean a site was captured. The browser displays server results and does not calculate or grant ownership.

The five-minute post-capture shield, timed full repair, paid upgrades/repairs, scaled defensive layouts and holding limit are remake tuning. Site garrison casualties and damage belong to the site, not the owner's home base. Capturing or abandoning a site clears its old garrison without granting those monsters to the new owner. Neutral/site invasions do not raid an owner's home resource balance. Rank remains the remake's +25 / −15 rule; neutral terrain has no keeper account to receive rank.

MR2 is deliberately separate: `services/maproom/v2/takeoverCell.ts` implements an explicit paid claim after 90% damage with twelve-hour protection. Tribal claims clear their buildings for player construction. That MR2 buildable-yard behavior is not represented by v0.5's fixed site layouts and paid level upgrades.

## Deferred source systems and trust boundaries

The six-neighbor Fortification network is not implemented. `getDefenderCoords.ts` and `DEFENDER_DAMAGE_REDUCTION` give same-owner surrounding defender cells cumulative incoming-damage reductions of 0 / 7 / 13 / 23 / 35 / 50 / 75%. A garrison or generic site upgrade is not this network and must not be labeled as Outpost Defender parity.

Full original tribe layouts/species, MR1/MR2 progression, Inferno, alliances, friends, relocation, world migration and all original map art/animation remain separate parity work. A “My backyard” camera button centers the existing persisted home; it does not relocate the yard.

The source MR3 `validateRange` is a TODO that trusts the client, and original monster transfers accept client-authored army structures. Those are not behaviors to preserve. The remake uses authenticated delta actions, server range/capacity checks, row locks and idempotent settlement. Neutral targets remain ownerless records; no code should load/save keeper zero as a real account.

See `PARITY.md` for the overall completion boundary and `RENDERER.md` for the actual delivered animation states and map interface tests.
