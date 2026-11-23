# Fortification network combat

Version 0.7 adds independently capturable map Fortifications. Their targetable,
non-firing core is the Outpost Defender (source building 140). Owning one protects
the same keeper's immediately neighboring home, resource outpost, and Stronghold.
Fortifications do not protect other Fortifications. Neutral sites have no owner
and receive no network protection.

| Adjacent same-owner Fortifications | Incoming building damage reduced |
| --- | --- |
| 0 | 0% |
| 1 | 7% |
| 2 | 13% |
| 3 | 23% |
| 4 | 35% |
| 5 | 50% |
| 6 | 75% |

Only six immediate odd-row hex neighbors count. A site's level, damaged core,
ongoing upgrade, or rebuilding timer does not remove its ownership-based support.
Enemy and neutral Fortifications do not count. Capturing or abandoning a cell
changes support for future invasions.

## Authoritative battle snapshot

New invasions use battle format **7**. After taking the existing ordered home-base
locks, the server reads the real defender's neighboring ownership once and saves
`networkDefense: {count, reduction, siteIds}`. Ordinary directory attacks on home
yards and invasions of owned resource outposts/Strongholds use the same rule.
Fortification targets explicitly receive a zero-support snapshot. A missing home
map marker also receives zero support.

The battle retains this snapshot through deployment, elapsed-time simulation,
polling, retreat, settlement, and historical reports. An ownership change outside
the active invasion cannot alter the armor already saved in its combat state.
Normal ownership actions use the same owner-home locks and refuse changes while
that keeper is fighting. Target ownership/revision changes between the initial
probe and row lock still reject the invasion before creating a battle.

Each defending building saves private `_networkReduction` and completed
`_fortificationReduction` values. Damage is:

```text
incoming damage × (1 − completed Fortify reduction) × (1 − network reduction)
```

Tier-four individual Fortify plus six supporting cells leaves 12.5% of incoming
damage. Public building snapshots expose `networkReduction` and combined
`damageReduction`; private simulation values and `fortify_paid` resource history
are omitted. Maximum HP, initial HP, stored resources, and monster/Champion health
are never increased or repaired by network support.

The single building-damage calculation applies each reduction once for ordinary
strikes, Fink additional targets, D.A.V.E. rockets, Wormzer emergence, Eye-Ra
explosions, Teratorn ricochets, Project X death bursts, and twig/pebble Catapult
impacts. Catapult storage/tower/wall factors remain intact; ricochet halves raw
damage before applying each new victim's armor. Walls receive network protection.
Existing target exclusions remain unchanged. Monster and Champion damage, auras,
Putty effects, healing, outgoing tower damage, and Laboratory powers retain their
own existing rules.

Saved formats **0–6** ignore network fields. Format 6 retains its previous paid
Fortify mechanics. Older battle formats retain their previous mechanics and event
streams. Public legacy snapshots expose `networkDefense: null` and omit new
building protection labels, so the interface cannot advertise unapplied armor.

## Capture and settlement

Fortifications use the existing independent **90% maximum-health damage** capture
threshold. Their core participates because it is targetable. Walls and traps
remain outside the capture denominator. The network changes damage received, not
that denominator. Damage saved by earlier invasions still counts.

Capture transfers only the attacked cell, removes its old garrison, and schedules
the existing 300-second shield and site repair. It never transfers neighboring
territories or their Fortifications. Released garrison casualties persist after a
failed attack. Neither army receives duplicated troops from a captured garrison.
The previous owner's home buildings, resources, queues, and Champion remain at
home. Fortifications add no resource income, capacity, ordinary holding, or reach.

Victory and ranking retain the ordinary rules: destroying the Town Hall or at
least half of eligible buildings earns a win; a winner gains 25 rank points and a
loser loses 15. Capture is a separate result, so damaging one eligible core by
exactly 90% can capture a Fortification while still losing the ordinary battle.
Neutral defenders have no account, rank update, or report. Real players and bots
use identical transactions. Repeat and concurrent settlement cannot duplicate
ownership, rewards, reports, or troop losses. Champion battle-clock handling from
format 6 remains unchanged.

## Animation event

Format 7 additionally emits an amount-free `strike` event when a melee actor
actually hits an enemy unit. It includes `sourceId`, `targetId`, `x`, `y`, `tx`,
`ty`, `kind`, `team`, and the normal monotonic event `id` and server timestamp `t`.
This drives the attacker's animation while the existing victim `hit` event
supplies the single damage indication. Building melee and ranged attacks already
identify their actors and do not receive an extra cue. Cooldown ticks and saved
legacy formats do not emit the new event.

## Source and adaptation

The exact reduction table comes from
`server/src/config/MapRoom3Config.ts:144`. Source ownership-based counting is in
`server/src/controllers/base/load/baseLoad.ts:287–305`; allowed protected kinds
are in `server/src/services/maproom/v3/getDefenderCoords.ts:6–9`.
`client/scripts/com/monsters/baseBuffs/buffs/BuildingDefenseBuff.as:17–25` applies
the buff to buildings; `client/scripts/BFOUNDATION.as:499–513` applies individual
Fortify and network armor multiplicatively. These are paths inside the supplied
`original/backyard-monsters-refitted` source tree. See [SOURCE-V07.md](SOURCE-V07.md)
for the broader audit.

The compact 64×64 world, preserved occupied coordinates, neutral cell placement,
separate Map Room ownership cap, local defense layout, and economy timing are
remake adaptations. The network's adjacency rule, target exclusions, reduction
table, building-only effect, and multiplicative armor composition follow the
supplied source. This is an incremental implementation, not a claim that every
original system has been restored.

## Regression coverage

- `tests/network_combat.php`: real simulation damage routes, all seven support
  levels, multiplicative armor, unchanged health, legacy formats 0–6, public
  privacy, capture boundaries, event behavior, and deterministic polling.
- `tests/network_combat_integration.php`: persisted home and territory snapshots,
  damaged/upgrading support, independent owned/neutral Fortification capture,
  real rank/report/garrison settlement, immutable history, and idempotency.
- `tests/network_combat_concurrency.php`: competing neutral reservations,
  concurrent capture settlement, changed target ownership, owner-wide exclusion,
  and abandonment racing a home invasion's support snapshot.

Integration and concurrency tests require `MYSQL_TEST_*` credentials for a
disposable database whose name ends in `_test`. Run database suites serially;
concurrency tests create their own child workers and remove their fixtures.
