# Source audit for Monster Lab, Catapult and Project X

This note records the supplied ActionScript client's behavior before implementation of the next remake expansion. Source paths below are relative to `original/backyard-monsters-refitted/`. They are evidence for design decisions, not a claim that every source feature has been implemented. Remake costs, time and world-space distances must be distinguished from these original values.

## Reference files

- `client/scripts/MONSTERLAB.as`: building ID, ten abilities, costs, prerequisites, one study, completion/cancellation and save fields.
- `client/scripts/YARD_PROPS.as`: building 51 (Catapult) and 116 (Monster Lab) definitions, costs, requirements, health and artwork paths.
- `client/scripts/CREATURELOCKER.as`: C11 Project X definitions and standard creature class selection.
- `client/scripts/com/monsters/monsters/creeps/`: standard `Bolt`, `Fink`, `Bandito`, `Fang`, `Eyera`, `Brain`, `ProjectX`, `DAVE`, `Wormzer` and `Teratorn` classes.
- `client/scripts/com/monsters/monsters/components/abilities/`: `Blink`, `AOEDamageOnAttack`, `AOEDamage`, `AOEDamageOnDeath`, `AOEDamageOnAttackOncePerTarget`, `BanditoAOEDamageSpin`, `PoisonOnAttack`, `Invisibility`, `DAVERockets`, `GlavesOnAttack`, `Enrage` and `TemporaryComponent`.
- `client/scripts/com/monsters/monsters/components/statusEffects/`: `DOTEffect` and `CStatusEffect`.
- `client/scripts/com/monsters/monsters/MonsterBase.as`: `poweredUp`, `powerUpLevel`, side-specific owner lookup and invisible defense flags.
- `client/scripts/com/monsters/monsters/creeps/CreepBase.as`: Eye-Ra's jump and `airburst` behavior.
- `client/scripts/Targeting.as`: ground/air/invisible target flags, closest target selection and area falloff.
- `client/scripts/FIREBALL.as`: ricochet target selection and damage halving.
- `client/scripts/com/monsters/effects/ResourceBombs.as`: eleven bombs, costs, family use, launch and targeting modes.
- `client/scripts/com/monsters/effects/ResourceBomb.as`: building modifiers and temporary putty effects.
- `client/scripts/CATAPULTITEM.as` and `CATAPULTPOPUP.as`: family-used controls, affordability and selection debounce.
- `server/public/gamestage/assets/english.json`: visible ability names/descriptions and bomb size names. Runtime code is more specific than some localized descriptions.

## Monster Lab progression

The Lab is building **116**, with three purchasable building levels. Its source costs are:

| Lab level | Twigs | Pebbles | Build/upgrade time | Requirements |
|---|---:|---:|---:|---|
| 1 | 100,000 | 100,000 | 10,800 seconds | Town Hall 5, Locker 3, Academy 2 |
| 2 | 300,000 | 300,000 | 43,200 seconds | Town Hall 6, Locker 4, Academy 3 |
| 3 | 600,000 | 600,000 | 86,400 seconds | Town Hall 7, Locker 4, Academy 4 |

`CanPowerup` requires an unlocked monster, existing upgrade record, current monster Academy level at least the requested ability tier plus one, and Lab level at least the requested tier. All ten listed abilities have three tiers. The original study spends **putty**, not goo. One study occupies the Lab. Upgrading/recycling the facility is blocked while a study runs. A cancelled study refunds its complete original putty cost. Fields `upg`, `upt` and `upl` persist the species, finish time and target tier. Paid instant completion is a separate premium path.

Five of the original fifteen ordinary species have no entry in `MONSTERLAB._powerupProps`. Do not invent powers for those species and call them original Lab abilities.

| Species | Source ID | Standard Lab ability |
|---|---|---|
| Pokey | C1 | None |
| Octo-Ooze | C2 | None |
| Bolt | C3 | Teleportation |
| Fink | C4 | Claws |
| Eye-Ra | C5 | Airburst |
| Ichi | C6 | None |
| Bandito | C7 | Whirlwind |
| Fang | C8 | Venom |
| Brain | C9 | Invisibility |
| Crabatron | C10 | None |
| Project X | C11 | Acid Spores |
| D.A.V.E. | C12 | Rockets |
| Wormzer | C13 | Splash Damage |
| Teratorn | C14 | Ricochet |
| Zafreeti | C15 | None |

The following figures are source values. Hours are real source study hours, not proposed remake times.

| Ability | Tier 1/2/3 putty cost | Tier 1/2/3 study hours | Metadata effect values |
|---|---|---|---|
| Teleportation | 48,000 / 72,000 / 108,000 | 24 / 24 / 24 | 150 / 300 / 450 range |
| Claws | 96,000 / 128,000 / 144,000 | 30 / 30 / 30 | 1 / 2 / 3 additional targets |
| Whirlwind | 1,000,000 / 1,500,000 / 2,000,000 | 32 / 32 / 32 | 1 / 1.5 / 2 metadata |
| Venom | 2,000,000 / 3,000,000 / 4,500,000 | 36 / 36 / 36 | 0.1 / 0.2 / 0.3 |
| Airburst | 3,560,000 / 4,120,000 / 5,120,000 | 48 / 54 / 60 | 0.2 / 0.3 / 0.4 |
| Invisibility | 3,000,000 / 4,500,000 / 6,000,000 | 48 / 48 / 48 | 0 / 4 / 8 cloak-delay metadata |
| Acid Spores | 8,000,000 / 12,000,000 / 18,000,000 | 72 / 72 / 72 | 1 / 2 / 3 metadata |
| Rockets | 15,000,000 / 22,500,000 / 33,750,000 | 144 / 144 / 144 | 140 / 180 / 220 range |
| Splash Damage | 10,000,000 / 15,000,000 / 22,500,000 | 96 / 96 / 96 | 1 / 2 / 3 damage multiplier |
| Ricochet | 12,000,000 / 18,000,000 / 27,000,000 | 120 / 120 / 120 | 1 / 2 / 3 extra bounces |

## Actual ability mechanics

### Bolt: Teleportation

`Bolt` attaches `Blink` only when powered up. Blink tests an existing short path, fewer than `tier × 5` remaining waypoints, and distance at most `tier × 150` to its final waypoint. It traverses the path end over ten fast ticks, reduces graphic alpha and increments `targetableStatus` while blinking. It restores visibility/targetability on arrival. `GameObject.isTargetable` requires a zero status counter, and range target collection checks this even for traps; ongoing damage-over-time does not query targetability. This is a transient approach dash, not free random teleportation on each attack. The separate `rebalance/Boltv2` uses a different attack-triggered implementation and should not silently be combined with the standard branch.

### Fink: Claws

`Fink` attaches `AOEDamageOnAttack(60, flags, tier, 60, false)`. Each attack may hit up to one, two or three additional closest ground monsters/buildings around Fink at normal attack damage, excluding its primary target. Friendly defender flags target invading units; `AOEDamage` explicitly removes building targets for defending monsters attacking units so they do not damage their own yard. The inner and outer radius are both 60, so this ability uses full strength across that radius.

### Bandito: Whirlwind

`BanditoAOEDamageSpin` divides its attack delay by `1 + tier × 0.5`, giving **1.5× / 2× / 2.5× attack cadence**. Its area strike affects enemy ground monsters within radius 60 and excludes the primary target. The constructor does not include building flags. Bandito therefore gains faster attacks against structures, but its extra whirlwind strikes are not building splash. Rotation is animated while fighting a unit.

### Fang: Venom

`PoisonOnAttack` only attaches a `DOTEffect` to a `MonsterBase`, never to a building. Each bite adds an effect at `owner.damage × tier × 0.1`. The same status effect renews by increasing its stack count. `CStatusEffect` deals a pulse every 40 simulation ticks. The refitted `GLOBAL.SIM_STEPS_PER_MS = 0.072` schedules 72 simulation steps per second, so this is approximately 0.556 seconds, not a reliable one-second period derived from display frame rate. This constructor does not set a finite renew/expiry count. Source poison therefore persists until death/removal. A finite duration, stack cap or fixed-second pulse in the remake is an explicit balance/runtime adaptation, not an original constant.

### Eye-Ra: Airburst

Powered Eye-Ra can acquire both ground and flying targets and jumps before exploding. It remains a ground mover, not a flying monster. The source uses `1.1 + tier × 0.1` for 20% / 30% / 40% increased burst damage and larger ground splash radius. Air units receive partial damage. The source's attacking and defending branches are inconsistent: defending air damage uses `(0.1 × tier + 0.1)` times the enhanced damage, while the special attacking-flying-champion branch applies a much smaller multiplier. One defender loop also uses stale temporary coordinates. Those inconsistencies should be documented rather than reproduced as intentional gameplay rules. A symmetric explicit air-damage fraction is a reasonable remake adaptation.

### Brain: Invisibility

The `Invisibility` component cloaks a Brain moving toward a target, reduces its aggro range while cloaked, and starts a reveal timer when it reaches its target. Moving again clears the timer and resumes cloak behavior. `Brain.as` calls `new Invisibility(powerUpLevel())`, so actual delay is **1 / 2 / 3 seconds**, despite the separate Lab metadata stating **0 / 4 / 8**. Ordinary targeting flags exclude invisible targets; the generic system also contains explicit flags capable of hitting them. Booby Traps explicitly use `getOldStyleTargets(-1)`, including the invisible flag, so a cloaked Brain can still trigger and take trap damage. Invisibility is not inherently a universal immunity to existing area effects or damage-over-time.

### Project X: Acid Spores

Standard `ProjectX` attaches `AOEDamageOnDeath` only after Lab unlock. The radius is 60; the burst damage is `owner.damage × (1 + tier × 0.5)`, or **1.5× / 2× / 2.5×**. It affects opposing ground monsters and structures, with linear area falloff, and defenders do not damage their own buildings. It triggers once on death. This standard branch does **not** create a lingering acid pool.

`rebalance/ProjectXv2` is a distinct variant: it has innate death damage, a ten-second acid pool, and damage that increases with attacks. Mixing these innate mechanics into the standard Lab unlock would be a new hybrid design.

### D.A.V.E.: Rockets

`DAVERockets` sets target mode to both ground/air and range to `100 + 40 × tier`, or **140 / 180 / 220**. `DAVE.rangedAttack` fires two missiles at half of ordinary attack damage each. Total direct attack damage is therefore unchanged by simply dividing it into two rockets. The source localized description claims increased damage, but the component's concrete change is range and target access.

### Wormzer: Splash Damage

`AOEDamageOnAttackOncePerTarget` applies area damage when the primary target changes, with radius 100 and multiplier equal to tier. It includes the primary target, so an emergence strike adds **1× / 2× / 3×** normal damage in addition to the normal attack. Other ground enemies/buildings in range receive linear falloff. Subsequent repeated bites on the same target do not trigger another splash. Defender variants target attackers and preserve their own buildings.

### Teratorn: Ricochet

`GlavesOnAttack` gives a projectile one, two or three further bounces. `FIREBALL` halves damage for each bounce. `FindGlaiveTarget` searches buildings within radius 100, excluding traps, decorations, immovable/enemy classes and protected towers; it prefers ordinary structures, falling back to walls when no ordinary structure qualifies. It avoids the current and immediately previous target. The chain is building-only: hits on monsters do not start a building ricochet chain. Defending Teratorns therefore do not bounce into their owner's structures.

## Project X base identity

C11 is Project X; C9 and C10 remain Brain and Crabatron. Its standard definition has base health 800, damage 1,200, speed 0.9, housing/bucket weight 70, tower-priority target group 4, hatch time 1,384 seconds and initial hatch cost 60,000 goo. Its Locker requirement is level 3; original discovery costs 4,096,000 goo and takes 62 hours. The original six Academy levels have damage `[1200,1400,1600,1800,2000,2200]` and health `[800,900,950,1000,1100,1200]`. These source values are references; the remake already scales economy and combat. `MonsterBase.as` explicitly selects defense towers for target group 4; group 6 is the separate monster/bunker hunting mode. `CreepBase.as` also doubles group-4 normal attack damage against a tower. The remake retains its established defense-hunter base damage tuning without this additional original class multiplier.

## Catapult and resource bombs

Catapult is building **51**, class `BUILDING51`, with four source building levels. Original first construction requires Town Hall 3 and Flinger 1, costs 75,000 each of twigs/pebbles/putty, and takes 5,400 seconds. Further levels require Town Hall 4/5/6, cost 128,600 / 257,200 / 514,400 of each of those three resources, and take 10,800 / 21,600 / 43,200 seconds. The ammo catalog only introduces new resource families at Catapult levels 1, 2 and 3.

There are **eleven** named sizes across three families. A player selects a size and clicks a target area. Once any size is launched, **the whole resource family is marked used for that invasion**: at most one twig, one pebble and one putty launch. Affordability and family usage must be enforced by the server, not just greyed-out controls.

| Ammo ID | Name | Cost | Radius | Damage / buff | Required Catapult |
|---|---|---:|---:|---|---:|
| tw0 | Small Twig Missile | 10,000 twigs | 200 | 2,200 building damage | 1 |
| tw1 | Medium Twig Missile | 100,000 twigs | 200 | 7,000 building damage | 1 |
| tw2 | Large Twig Missile | 5,000,000 twigs | 200 | 50,000 building damage | 1 |
| pb0 | Small Pebble Bomb | 10,000 pebbles | 200 | 2,400 building damage | 2 |
| pb1 | Medium Pebble Bomb | 100,000 pebbles | 300 | 9,000 building damage | 2 |
| pb2 | Large Pebble Bomb | 2,000,000 pebbles | 350 | 30,000 building damage | 2 |
| pb3 | Huge Pebble Bomb | 10,000,000 pebbles | 400 | 75,000 building damage | 2 |
| pu0 | Small Putty Rage | 10,000 putty | 150 | 1.2× speed; 0.2 armor strength; 10 seconds | 3 |
| pu1 | Medium Putty Rage | 100,000 putty | 150 | 1.4× speed; 0.4 armor strength; 15 seconds | 3 |
| pu2 | Large Putty Rage | 5,000,000 putty | 300 | 1.8× speed; 0.7 armor strength; 30 seconds | 3 |
| pu3 | Huge Putty Rage | 10,000,000 putty | 500 | 2× speed; 0.9 armor strength; 40 seconds | 3 |

The source popup imposes a one-second delay after selecting fire to avoid an immediate click carrying into the yard. This is a UI selection debounce, not a Flinger reload requirement. Resource-bomb placement is independent of monster placement: targeting buildings directly is intended.

Twig and pebble bombs damage eligible living buildings, not creatures. Traps, decorations, enemy/immovable classes and **Champion Cage (114)** are excluded/immune. Walls take 6% damage, towers 90%, and Storage Silo damage is multiplied by its level. The source distributes the nominal damage across falling particles; the server remake should use deterministic impact timing and explicitly document radius/falloff conventions instead of relying on client particle collisions. Original apparent distance falloff contains an unassigned distance field, so it is not a reliable formula to blindly translate.

Putty Rage collects visible living attacking creeps in its target area and applies a named temporary `Enrage` component. It does not damage buildings. `Enrage` multiplies movement speed, divides attack delay and supplies an armor modifier; it does **not** multiply outgoing attack damage. Existing named putty effects are not stacked/refreshed. The supplied `ArmorPropertyModifier` is patched to compound damage reductions, while the older popup labels `1 - damageMult` as armor. The remake should state its incoming-damage reduction explicitly and compound it predictably with Fomor's aura. Applying the buff only to units present at impact, rather than granting a persistent area aura to future units, matches the source's target collection approach.

## Implementation recommendations

A coherent next release can add Project X, Lab and Catapult, all ten three-tier studies and the eleven ammo choices as one complete gameplay expansion. Continue to scale costs, times and distances for the existing 32-unit world and Town Hall limit, while retaining each ability's distinct behavior and identifying intentional deviations.

Snapshot each side's researched Lab levels when a battle starts. Attacking troops and defending bunker troops must use their respective owner's upgrades. Never acquire new powers midway through an already saved older battle. Reject study/structure changes during active invasion locks. Preserve battle events, ammo use and paid resource deductions under the same database transaction; replays, reloads and duplicate calls must not create extra projectiles, refunds or results.

Use deterministic stable-ID target ordering, bounded chains and a death-trigger guard to prevent duplicated death bursts or runaway recursion. Keep direct targeting rules separate from area effects and ongoing poison. Every new effect needs both actual server damage/buff behavior and matching animation; visual effects alone do not satisfy the mechanic. Bots should pay and wait through the same study queue, preserve food/economy reserves and use the same artillery endpoint/service without direct state grants.


## Confirmed 0.4 implementation adaptations

The implementation follows the standard ten powers and keeps the source distinctions above. World distances, resource costs and timers are scaled in `Catalog.php`. Bolt traverses a legal approach over 0.5 seconds and has a four-second repeat limit. Brain uses the component's actual one/two/three-second reveal delay. Poison uses one accumulated damage value and one-second pulses until death, avoiding an ever-growing list of source effect instances. Project X and Wormzer use linear radial falloff. Ricochet uses a bounded chain with no repeated building anywhere in that chain, a stricter rule than the source's immediate two-target exclusion. Eye-Ra's inconsistent air branches are normalized to 20%/30%/40% of enhanced damage on either side.

Catapult uses three facility levels because only three families are unlocked, a deterministic one-second impact, and full eligible damage within its catalog radius. Walls take 6%, towers 90%, storage multiplies damage by its level, and Cage/traps are excluded. Putty applies to present, visible, living attackers at impact; separate incoming-damage reductions multiply with Fomor and are capped at 95% total. No outgoing-damage multiplier or persistent putty area is added. Spent resources, per-family use, frozen payloads and delayed impacts persist together under the battle transaction.
