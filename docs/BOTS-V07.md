# Persistent bots and the strategic Fortification network

Version 0.7 extends the existing saved bot AI. It does not recreate the population, reset ranks, replace bases, give away territories, or manufacture troops. All 1,000 bot identities keep their existing profiles and progression. The network is a separate map feature from per-building Fortify armor introduced in 0.6.

## Decisions and bounded work

`Bots::networkTurn(id, turn)` schedules a network opportunity every third guardian turn or every fourth other-profile turn. The phase is derived from the persistent bot ID. The other eight or nine turns in each twelve-turn period retain ordinary territory priorities. On network turns, an empty, unaffordable or ineligible network plan falls through to ordinary goals. One turn performs at most one successful territory-management action and starts at most one battle.

The scheduler continues to read only active battles and the due batch. It adds one owned-Fortification lookup, with at most eighteen valid owned cells, and uses `World::candidates(id, 24, seed, 'fortification')` only during an eligible network attack opportunity. The explicit kind filter keeps dense Fortification cells out of the existing resource/stronghold scouting window. For network scouting, World retains eligible cells adjacent to the chosen owned reach source before the final 24-candidate limit. Its remaining SQL coordinate order cycles by seed before the 160-row bound, so dense map regions do not permanently hide cells beyond a fixed prefix. World supplies at most six adjacent home/resource/stronghold projections per candidate through one spatially bounded lookup; bots do not load battle history, all bases, or all world state to score them. The planner examines at most 48 supplied candidates and retries at most four selected targets. Each management group examines at most six owned sites, rotating across the saved set.

`Bots::networkChoices` selects cells that would protect an owned adjacent home/ordinary holding or remove an enemy owner's support from its own adjacent eligible parent. Neutral cells with no owned neighbor have no strategic value. Capturing a Fortification beside an enemy-owned Fortification is not treated as network disruption: Fortifications themselves receive no network protection. Exact odd-row adjacency comes from the production `World::distance` implementation.

Guardians prefer owned-neighbor protection. Raiders prefer disrupting enemy support. Builders and collectors retain an ID-derived preference between those aims. Ties are stable for the same bot and site across worker restarts and turn changes. Shared cells supporting several eligible parents gain greater value. Human-owned cells follow the existing human-target percentage, and busy owners, unreachable cells, shields, construction/rebuilding timers and the separate ownership allowance are excluded before combat rechecks them under locks.

Ordinary holdings remain capped at twice the living Map Room level. Fortifications use six times that level, capped at eighteen. Filling the ordinary holding allowance does not prevent a Fortification invasion. Owning Fortifications never supplies reach, income or storage, and they never enter the ordinary holding list.

## Real combat and paid management

The scheduler calls `BattleService::botAttackSite` with the saved army and the normal champion-food reservation. Units are actually consumed and deployed through persisted timed buckets; defenses and garrisons fight normally. Ownership is awarded only by shared combat's 90-percent health-weighted capture rule. Starting a battle grants nothing. Captures use the same shield, rebuilding job and ranked result as player captures.

Removing an enemy support cell affects future battles against its parent while leaving the parent's ownership intact. The current battle's network snapshot remains the combat service's responsibility. The AI does not perform free support removal, direct capture, or simultaneous ownership transfer of a surrounding ring. This is a bounded tactical opportunity, not a persisted multi-battle siege script.

Repairs, upgrades and garrisons reuse `Bots::siteManagementPlan` and `World::mutate`. Local Fortification defenses have three levels; higher levels do not increase their adjacency percentage. Damaged buildings are repaired before upgrading or adding troops. Work pays the normal price and waits for the normal timer.

Before optional network repairs or upgrades, `Bots::networkHomeReserve` protects the component-wise larger cost of the next eligible missing home construction and the next eligible home armor investment. This keeps one next home investment funded, including when the bot is still saving toward it. Home construction and armor already run before network management. The normal champion-food goo reserve applies on top. Garrison transfers keep the actual next meal ingredients and a separate eighty housing of offensive monsters at home; reserved meal troops cannot masquerade as that offensive reserve. No action grants free monsters or refunds.

The heartbeat adds `network_attacks`, `network_upgraded`, `network_repaired` and `network_garrisoned`. These are subsets of the existing territory attack/management totals; summing both would double-count work.

## Verification

Run the pure tests without a database:

```sh
php tests/bots_network_planner_test.php
php tests/bots_planner_test.php
php tests/bots_world_planner_test.php
php tests/bots_fortification_planner_test.php
```

The new suite exercises the real production planners over all 1,000 stable IDs, cadence and fallback boundaries, separate capacity, exact adjacency, owner matching, target eligibility, human policy, bounded candidates, costs, timers, home armor/building budgets and meal/offensive reserves. The existing pure suites retain 29,128, 5,031 and 6,195 checks respectively; the new network planner adds 6,041 checks (46,395 total). The separate old integration fixture's original 320-site assertion now counts resource/stronghold anchors explicitly.

The integration suite is destructive only in an explicitly opted-in disposable database whose name ends in `_bots_test`:

```sh
BYM_BOTS_TEST_RESET=1 BYM_BOTS_TEST_HOST=127.0.0.1 BYM_BOTS_TEST_PORT=3306 BYM_BOTS_TEST_DATABASE=network_bots_test php tests/bots_network_integration_test.php
```

It seeds the real 1,000-account population and network, then prepares explicitly identified army/ownership fixtures. The real scheduler must launch a neutral Fortification invasion despite a full ordinary holding allowance; actual battle simulation must consume troops and earn capture. It also exercises shared paid upgrade, repair and garrison actions, real enemy support capture without parent transfer, and identity preservation. Accelerated battle timestamps advance normal simulation without changing damage or declaring a result. These prepared fixtures verify rules and persistence; they do not claim that all 1,000 bots organically reached this stage or measure autonomous long-run population balance.

Verified with PHP 8.4.27 on MySQL 8.0.44 and MariaDB 10.4.32: the bot database suites pass 119 core, 50 ordinary-world, 11,109 individual-armor/champion and 67 strategic-network checks on each engine. The database runs are serialized, each in disposable databases; the existing optional 1,000-yard armor benchmark was not repeated for this change.

After the dense-map scouting correction, the 67-check bot network integration suite and the 1,839-assertion World network integration suite passed again on both database engines. The latter includes all six neighbors of a level-three stronghold at (28, 28) across 64 selected-source seeds and verifies that scouting visits more than the original 160-cell prefix.
