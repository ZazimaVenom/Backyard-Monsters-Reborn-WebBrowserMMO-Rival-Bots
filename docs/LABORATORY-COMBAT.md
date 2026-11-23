# Laboratory powers and artillery — 0.4.0

The Monster Lab's ten researched powers are applied by the PHP battle simulation. Neither the browser nor a bot submits damage, targeting results, status durations or victories. A new invasion snapshots the attacker's completed Laboratory tiers and creates defending bunker templates with the defender's completed tiers. Research finished later does not alter that invasion. Existing version-three battles receive neither new powers nor Catapult controls.

## Researched monster powers

| Species | Implemented battle behavior |
|---|---|
| Bolt | Blinks toward a legal endpoint on its current movement path. Tier ranges are 3 / 6 / 9 yard units; the dash lasts 0.5 seconds and has a four-second reuse interval. During the dash, new targeting, splash collections and proximity traps cannot select it. Existing venom still ticks. |
| Fink | Strikes 1 / 2 / 3 additional nearest ground enemies or buildings within 1.5 yard units, excluding the primary target. Each extra hit deals the normal strike damage. Defending Finks cannot damage their own buildings. |
| Bandito | Attacks at 1.5 / 2 / 2.5 times its ordinary cadence and sweeps other ground enemies within 1.5 units. The sweep does not damage neighboring buildings or airborne monsters. |
| Fang | Each hit on a monster adds 10% / 20% / 30% of its strike damage to a persistent venom pulse. Venom ticks once per second, stacks numerically, and ends when the victim dies. It does not poison buildings. |
| Eye-Ra | Retains ground movement, gains airborne target acquisition, and explodes with 20% / 30% / 40% more ground damage and radius. Airborne enemies receive that tier's fraction of the enhanced damage, with the normal explosion distance falloff. It is still consumed by the explosion. |
| Brain | Cloaks while moving and stays cloaked for 1 / 2 / 3 seconds after arriving. Ordinary towers and monsters cannot acquire it while cloaked; proximity traps and nearby splash can still damage it. Its cloaked enemy-acquisition radius is one yard unit. |
| Project X | Without research it is a defense-priority ordinary monster. Research adds a single death burst with 1.5 / 2 / 2.5 times its strike damage and linear falloff over 1.5 units. The burst hits enemy ground monsters and, for invaders, enemy structures. No lingering acid pool is created. |
| D.A.V.E. | Uses two rockets per strike, each carrying half the strike damage. Researched range is 3.5 / 4.5 / 5.5 units, and rockets can target ground or airborne enemies. |
| Wormzer | Adds a radial burst on its first strike against each new primary target, independent of whether it had to burrow again. Damage is 1 / 2 / 3 times its strike damage with linear falloff over 2.5 units, in addition to the ordinary strike if the target survives. |
| Teratorn | Its fireball bounces to 1 / 2 / 3 additional buildings within 2.5 units of the previous target. Damage halves at each bounce; non-wall structures are preferred, and a building is never revisited in the same chain. |

The original source's targeting groups and the existing remake's weighted building preferences are retained for ordinary attacks. Project X uses the same defense preference as Octo-Ooze, Ichi and Crabatron; it is not a global monster hunter. Pokey, Octo-Ooze, Ichi, Crabatron and Zafreeti have no additional Monster Lab entry.

Research ranges and costs are scaled to this remake's 32-unit yard. Blink reuse timing, one-second venom pulses, bounded ricochet chains, and deterministic area damage are deliberate adaptations. Source references and distinctions between the original and rebalance branches are in `SOURCE-V04.md`.

## Catapult controls

A completed, living Catapult is snapshotted when an invasion starts. Level 1 enables Twig Fireballs, level 2 adds Pebble Bombs, and level 3 adds Putty Rage. The ammunition menu contains three twig tiers, four pebble tiers and four putty tiers. **Each resource family can be fired once per invasion**, regardless of the tier chosen. The resource cost is deducted only when a legal shot is accepted, in the same transaction that consumes its family slot.

Catapult aim accepts any finite point inside the yard, including an occupied building footprint. It has no Flinger bucket cost or reload dependency. The authoritative impact occurs one second after launch. The invasion remains active while a paid projectile is in flight; ending the invasion waits for that short flight. Shots are rejected if less than one second remains before the battle deadline.

Twig and pebble projectiles damage buildings in their blast area. Walls take 6% damage, towers 90%, and storage damage is multiplied by its building level. Champion Cages and hidden traps are excluded, and these damage bombs do not hurt monsters. Their effects are deterministic area impacts rather than the source's many scattered falling particles.

Putty boosts living, visible attacking monsters that are inside its radius when it lands, including an active champion. It does not affect defenders, submerged or blinking monsters, cloaked Brains, or later arrivals. It changes movement speed, attack cadence and incoming damage resistance, **not outgoing damage per strike or health**. Tier durations are 10 / 15 / 30 / 40 seconds. The speed multipliers are 1.2 / 1.4 / 1.8 / 2, and damage reductions are 20% / 40% / 70% / 90%.

Putty and Fomor speed multipliers combine. Their damage reductions combine multiplicatively, with an explicit 95% overall reduction ceiling. The attack loop preserves fractional cooldowns and allows multiple strikes in a simulation step so Bandito, Fomor and Putty do not silently lose their advertised cadence. A bounded maximum of 32 strikes per quarter-second step exceeds all valid combinations while guarding malformed saved state. Expired buffs stop affecting movement, attacks and damage; they do not permanently rewrite a monster's base statistics.

## Autonomous use

A bot with a ready Catapult plans at most three shots. It selects an affordable tier costing no more than 20% of its current resource stock per family, plans damage shots at one and three seconds, and plans Putty at twelve seconds near its surviving attacking monsters. All shots pass the same facility, family-use, coordinate, deadline and payment validation as player shots. A shot is skipped if it becomes invalid or unaffordable, or if Putty has no eligible attackers. Plans do not deduct resources in advance, bypass cooldowns, or grant free effects.

## Verification

`tests/lab_combat.php` passed 48 checks of actual simulated effects, including all ten powers, airborne Eye-Ra acquisition on both sides, persistent movement events, cloak/trap distinctions, adjacent Wormzer retargeting, combined boost cadence, poison stacks, delayed artillery and a 200-monster Project X death-burst chain. The existing 72 pure combat checks also pass.

`tests/lab_combat_integration.php` passed 30 MySQL checks for ownership, legal and rejected aim, resource payment, family limits, hidden payloads/balances, queued impact, attacker and defender research snapshots, Putty, reports, legacy battles, and duplicate settlement. The existing 84 MySQL combat checks also pass. Tests use disposable data and roll account/battle changes back; see `tests/README.md` before running them.
