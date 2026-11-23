# World map and territories

The Map Room adds a persistent, finite conquest map alongside ordinary backyard invasions. A fresh installation creates exactly **1,000 bot accounts**, 256 neutral resource outposts, 64 neutral strongholds, 1,270 neutral Fortifications and one home marker for each bot. Neutral territories are database sites, not extra accounts. Setup creates no human keeper; players register from the game afterward.

Each region uses a 64 × 64 odd-row hex grid. Each home and territory occupies a unique cell. Home positions stay fixed across restarts and upgrades. Registration allocates a free cell in the same transaction as the account and yard. A full Home Valley directs new arrivals to a Frontier region. The Map Room view returns a bounded radius, at most 12 hexes, with no resource balances or garrison inventories in public markers.

Frontier yards have a separate breach, paid takeover, construction and free repair system. The territory rules below cover Home Valley and the Highlands. [Regional travel and invitations](REGIONS.md) · [Building and defending a Frontier outpost](REGIONAL-OUTPOSTS.md)

## Reaching and holding territories in Home Valley and the Highlands

Build a Map Room at Town Hall level 3. It costs 6,000 twigs, 6,000 pebbles and 2,000 putty, and takes 120 seconds at normal speed. One Map Room is allowed per backyard. Its three levels support 2, 4 and 6 holdings. Existing yards receive no free Map Room during an upgrade.

A territory invasion requires a completed, living Map Room and Flinger, ready monsters or a healthy active champion, a free holding slot, an unprotected target and no active battle involving either keeper or the target. The home reaches 10 hexes. Any owned outpost or stronghold can extend reach; there is no connected-territory requirement. Ordinary home invasions from the directory remain available regardless of strategic map distance.

| Resource outpost level | Each resource per second | Added home storage | Reach |
| --- | ---: | ---: | ---: |
| 1 | 0.05 | 2,000 | 2 |
| 2 | 0.10 | 4,000 | 3 |
| 3 | 0.20 | 8,000 | 4 |
| 4 | 0.40 | 16,000 | 5 |
| 5 | 0.80 | 32,000 | 6 |

Outposts automatically bank all four resources at the listed rate; game speed multiplies production. The storage bonus applies to each resource. Income accrues over at most 72 offline hours, using the same clock as the rest of the economy. Paid upgrade completion splits the income interval at the exact completion time. Captures and abandonment first accrue the old owner's old rate, then recompute ownership bonuses. Losing storage never destroys an existing surplus: further automatic income pauses until that resource falls below the new cap.

| Stronghold level | Damage bonus | Reach |
| --- | ---: | ---: |
| 1 | 10% | 10 |
| 2 | 20% | 15 |
| 3 | 30% | 20 |

A stronghold within range of the battle's target hex adds its bonus to that owner's attacking monster damage or defending tower damage. Bonuses from multiple strongholds add together. A battle snapshots them once; repeated polling does not compound them. Battles created before this release receive no retroactive bonus.

## Combat and capture

Territory battles use the same animated combat, deployment, towers, bunker defenders, champions and artillery controls as backyard invasions. A resource outpost's central structure has one hit point and is untargetable decoration. Its surrounding defenses determine the outcome. A stronghold core is a targetable defending tower. An owned site's bunker defenders use the owner's monster research and Lab abilities; a home champion never automatically moves to defend a territory.

Capture requires **90% HP-weighted damage** across eligible structures. Walls, traps and the untargetable resource core are excluded. This is separate from ordinary victory, which still uses the existing 50% destruction/Town Hall rule. An invasion can win its ordinary battle without capturing the territory. The HUD and report show capture progress separately.

A successful capture transfers ownership once, preserves the site's level and fixed layout, clears the previous garrison, and starts a full rebuild. Rebuilding takes 300 seconds divided by game speed; a five-minute shield remains in real time. Captured defenders are never added to the attacker's army. Failed invasions persist damage and garrison casualties on the site. They do not damage, loot or deploy the home defender's yard. Highlands territory battles award no bank-resource loot. Frontier outposts use a separate handover fee. A ranked battle awards +25 to the winner and deducts 15 from the loser. A neutral territory has no account or rank entry.

## Managing holdings

Owners can upgrade, repair, transfer actual ordinary monsters to or from the bunker, and abandon a site. All actions use server-side ownership and battle checks. Management is blocked while that owner is involved in a battle. Upgrades and repairs are paid, timed jobs; an active job blocks transfers and another job. These sites use fixed defensive layouts rather than individual building placement.

Resource outposts have five levels and strongholds have three. The UI displays current benefits, the next level's benefits, resource costs and timer before purchase. Repairs charge a fraction of the level's repair price according to missing structure HP. Bunker capacity is 100 housing per defensive level, capped at 300 in these layouts. Garrisoning deducts ready home monsters; withdrawing requires free home housing and adds back exactly those stationed monsters. Champions cannot be garrisoned.

Abandonment has a confirmation in the UI. It clears ownership and the entire garrison without refund, cancels unfinished upgrades, and applies the same protected rebuild as capture. Neutral sites have defenders only when initially generated; captured or abandoned garrisons stay empty until a new owner stations actual monsters. Restarting the server does not respawn free defenders or reset ownership.

## Persistence and release boundaries

SQL schema 2 adds `world_sites` and explicit battle targets. Ownership, rank, reports, casualties and cached economic bonuses settle in one transaction. Combat and management lock real home bases in ascending ID order, then the site and battle. Ownership is rechecked after locks. Neutral defenders never load or save user ID zero.

The application refuses an older schema with an actionable upgrade message. Follow the upgrade instructions; do not reinstall over a saved world. The upgrader backs up all saved tables before additive DDL. MySQL and MariaDB DDL can commit independently, so interrupted schema changes are resumable rather than described as fully rollbackable.

The regions use local economy rates, five resource outpost tiers, three stronghold tiers and limits tied to Map Room level. The Frontier uses native buildable field yards. The Highlands use the resource, stronghold and defender systems described here.

## Verification

`tests/world_test.php` exercises hex geometry, layouts, capacities, income boundaries, extension preservation and timer idempotence without a database. `tests/world_integration.php` requires `BYM_WORLD_TEST_RESET=1` and a disposable database ending in `_world_test`; it verifies seeding, stable markers, paid progression, private map responses, ownership, inventory conservation, income, schema guards and registration into a free Frontier region when the legacy map fills. Combat, bot, installer, upgrade and HTTP suites cover their corresponding integration paths.


## Neighboring Fortification network

Fortifications are separate capturable cells with an Outpost Defender core. They use the same real battle, 90% capture, rebuild, shield, paid management and garrison routes, but their ownership capacity is independently 6/12/18 at Map Room levels 1/2/3. They do not count as ordinary holdings and add no reach, income or storage. Adjacent same-owner cells reduce building damage for homes/resource outposts/strongholds by 7/13/23/35/50/75%; the Fortification itself receives no network reduction. Core health is targetable and counts toward capture, unlike the decorative resource core.

Existing maps gain only vacant neighboring cells around territory anchors, once, while retaining saved positions and reserving registration space. Full six-cell rings are not guaranteed. See `FORTIFICATION-NETWORK.md` for management and `SOURCE-V07.md` for explicit source adaptations.
