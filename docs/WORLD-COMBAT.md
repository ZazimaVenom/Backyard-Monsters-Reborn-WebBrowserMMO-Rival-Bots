# World-site combat (v0.5)

Territory invasions use the same authoritative fixed-step battle engine, `battles` table, monster inventory, Flinger buckets, five-second reload, champions, Laboratory abilities, Catapult payloads, animated event stream and worker as home invasions. There is no random capture shortcut or second battle implementation.

## Targets and access

`BattleService::startSite(int $attackerId, int $siteId)` starts a resource-outpost or stronghold invasion. `botAttackSite(int $attackerId, int $siteId, array $reserve = [])` calls it and schedules the existing paid bot buckets, champion and bounded artillery plan. The usual `deploy`, `deployChampion`, `catapult`, `poll`, `retreat` and `tickActive` methods then operate on the returned battle ID.

World validates a living completed Map Room and Flinger, available army/champion, holding capacity, server-calculated odd-row hex reach, nonself ownership, site shield and reconstruction/upgrade timers. The combat service also checks owner-wide active battles and the exact neutral target. Home markers use the existing home invasion action. Home directory invasions intentionally remain available without Map Room or strategic range checks; new home battles can still receive target-location Stronghold bonuses.

SQL `defender_id` is NULL for a neutral site. Its client display identity is `{id:0,name,is_bot:false,neutral:true}`. Zero is never an account, base, report recipient or authorized viewer. Real previous participants retain battle access after ownership changes; unrelated users cannot poll or control it.

New snapshots add:

```text
target: {kind, id, name, q, r, ownerId, captureThreshold}
captureProgress: number | null
```

Kinds are `home`, `resource`, `stronghold`; ownerId is null for neutral. Territory threshold is 90; home threshold/progress are null. A historical pre-v5 battle has target null. Capture progress is unrounded in the authoritative payload so rounding cannot award premature capture; the UI may format it for display.

## Capture is distinct from ordinary victory

Capture uses `100 × (sum(maximum HP) − sum(remaining HP)) / sum(maximum HP)` for eligible site structures. Walls, traps and the invulnerable one-HP resource core are excluded. Persisted damage from earlier invasions counts against maximum HP. Capture requires at least 90 percent when combat finishes. Ordinary victory retains its existing Town Hall / 50-percent destroyed-building rules, and ranks remain +25 to the ordinary winner and −15 to the ordinary loser. This deliberately allows a normal victory without capture and, in an unusual uneven-health layout, capture without a normal victory. A neutral defense has no account to receive its win; the actual attacker can still lose 15 points.

The resource core cannot be targeted, damaged by any ordinary/Lab/explosion/artillery route, obstruct ground paths or deployment, or keep an otherwise destroyed yard active. Stronghold cores are eligible targets and real firing towers.

Site results add `captured`, `captureProgress`, `target`, `previousOwner`, and `newOwner`. Owner values are historical participant objects or null. These are saved into reports along with actual deployed units, champion outcomes and paid artillery shots. Loot remains zero: attacking an outpost never raids its owner's home bank.

## Transaction and persistence guarantees

Every write locks real participating home bases in ascending user-ID order, then the target site, then the battle. Start probes candidate ownership outside the write transaction and rechecks both owner and revision under the site lock. A change is rejected cleanly instead of adding an out-of-order owner lock. Battle metadata is likewise probed before the write transaction, keeping repeatable-read snapshots from becoming stale while waiting for base locks. Normalized owner-base locks serialize ownership changes and bonus calculations.

Both captures and failures persist damage and dead released garrison units to the **site**. They never substitute the owner's home buildings or home champion. Failed defenses leave the owner home save untouched. On capture, the former owner's normal economy is accrued using its old cached income before World transfers ownership and synchronizes both caches. This normal clock progression is distinct from raid damage or loot; home building damage, champion damage and shields are never applied by a site defense. Unknown base extensions are preserved.

Capture removes all former site garrison monsters without transferring them into either army, keeps layout/level, grants a five-minute site shield and schedules full repair. A failed invasion retains surviving garrison and damage and grants a two-minute site shield. Rank changes, reports, site ownership, bonus changes and the settled flag commit together. Subsequent polls/actions cannot settle again.

Stronghold bonuses are additive from owned towers whose range includes the target hex. Their fractions are frozen at start. The attacker multiplier is applied once when an ordinary monster or attacking champion is created; the defender multiplier is applied once to actual tower damage. Garrison-monster damage, traps, healing, Catapult ammunition and persistent Academy/Lab levels receive no tower bonus. Existing saved battles acquire no new bonus.

## World helper boundary

BattleService and World agree on these helpers:

- `load($siteId, $lock=false)` returns typed row fields plus decoded `state`; `home($ownerId)` returns the home marker or null.
- `refreshSite(&$site,$now)` completes due site timers; `assertAttack($attackerId,$homeBase,$site)` validates authoritative world gates.
- `combatBonuses($attackerId,$defenderId,$q,$r)` returns fractional `attacker` and `defender` values.
- `save($site)` persists site state and advances its revision.
- `capture(&$site,$attackerId,&$bases,$now)` clears garrison, transfers/protects/repairs the site, saves it and updates the passed homes' bonus caches. The caller accrues and saves real home bases.
- `syncBonuses($ownerId,&$homeBase)` rebuilds cached world income/capacity after an ownership or due-level change.

Site state contains Game-compatible buildings, garrison, research and Laboratory fields. Neutral garrisons use site template research. Owned-site garrisons snapshot the actual owner's Academy and Lab levels. Catalog `outpost.untargetable` becomes private battle-building `_untargetable` metadata. New territory battles use format 5; home battles preserve format 4, and all earlier saved formats remain readable.

## Verification

`tests/world_combat.php` checks threshold boundaries, maximum-HP weighting, independent victory, neutral result semantics, untargetable-core path/target/damage/completion behavior, stronghold fire and frozen bonuses without a database.

`tests/world_combat_integration.php` uses a disposable database to check actual capture, ownership/cache/report idempotence, inventory costs, site-only settlement, private access, historical access, garrison casualties, Academy/Lab snapshots, stronghold buffs, champion/Catapult persistence, world gates, legacy home behavior and bot scheduling/reserves.

`tests/world_combat_concurrency.php` runs independent PHP workers against the same disposable database. Explicit row-lock barriers force competing neutral starts, duplicate settling polls, ownership changes between read/lock and site-versus-home owner races. It asserts one battle/transfer/reward/report as appropriate, then removes its own fixtures.

Run these alongside the existing combat, champion and Laboratory suites. Never run integration tests against a player world, and serialize database runtime wrappers when they share a data directory.

Verified release checks: 29 new pure checks and all 120 existing pure engine/special/champion/Laboratory checks passed. The new integration (46) and explicit parallel-connection races (10), plus all existing home/special/champion/Laboratory integration checks (114), passed on both MySQL 8.0.44 and MariaDB 10.4.32. Each database run used only the disposable `combat_v05_test` schema; both runtime wrappers shut down cleanly. The parallel-worker harness forwards the active PHP extension configuration so the isolated local PHP runtime and ordinary configured PHP installations both run the same workers.
