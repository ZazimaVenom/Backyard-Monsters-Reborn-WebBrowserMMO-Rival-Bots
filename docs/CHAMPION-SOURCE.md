# Champion systems: supplied-source reference

This document records the supplied ActionScript/TypeScript source, not a claim that every rule below is implemented in the remake. All paths below are relative to the supplied `backyard-monsters-refitted` source tree. Costs, time, coordinates and combat statistics use original-game units; the PHP remake uses its own documented balance and world scale.

## Playable guardians in 0.12

Gorgo, Drull and Fomor can be raised in the Champion Cage. Korath becomes available by earning Monster Madness rewards. Krallen accompanies the active champion as a separate weekly contest reward. The care, map objectives and local balance for the two event species are described in [Monster Madness](MONSTER-MADNESS.md) and [Hunt for Krallen](HUNT-FOR-KRALLEN.md).

## Exact identity mapping

| ID | Name | Source role |
|---|---|---|
| G1 | Gorgo | Slow, high-health ground melee champion |
| G2 | Drull | Faster, high-damage ground melee champion |
| G3 | Fomor | Ranged champion with a nearby-friendly movement/attack-speed and damage-resistance aura; ground at levels 1–2, flying from level 3 |
| G4 | Korath | Monster Madness champion with permanent fire and ground-shock powers |
| G5 | Krallen | Independent weekly companion with resource targeting and upgraded loot aura |
| C9 | Brain | Resource-priority ground monster; invisibility is a Monster Lab power, not an innate base ability |
| C10 | Crabatron | Slow, high-health defense-priority ground monster |
| C11 | Project X | High-damage, relatively fragile defense-priority ground monster; death explosion is a Monster Lab power |
| 114 | Champion Cage | Holds the active basic champion |
| 119 | Champion Chamber | Preserves frozen basic champions and allows changing the active species |

The ordinary monster mapping is explicit in `client/scripts/CREATURELOCKER.as:300`, `:325`, `:349`, and `server/src/game-data/stats/monsterStats.ts:242`, `:267`, `:291`. C9 is **not** Project X and C10 is **not** Brain.

## Buildings and raising

`client/scripts/YARD_PROPS.as:5995` defines Cage 114 as unique from Town Hall level 4. Its one level costs 500,000 twigs, 500,000 pebbles and 250,000 putty, takes 86,400 seconds, has 10,000 HP and a 1,080-second repair time. Its original footprint is not simply the full image rectangle; `CHAMPIONCAGE.as` defines an open pen with several pathing rectangles.

`client/scripts/YARD_PROPS.as:6523` defines Chamber 119 as unique from Town Hall level 4, with an additional completed Cage requirement. Its one level has the same resource/build-time cost, 16,000 HP and 3,600-second repair time.

`client/scripts/CHAMPIONSELECTPOPUP.as`, `RaiseGuard`, directly spawns the chosen basic champion at level 1, full health, zero feeds and zero food bonus. It has no separate resource payment or hatching timer. A type already frozen cannot be raised again. `CHAMPIONCAGE.CanTrainGuardian` limits normal raising to basic champions whose powerLevel is positive: Gorgo, Drull and Fomor. Korath and Krallen have separate acquisition behavior.

There is one active **basic** champion; special champions have distinct logic. The Chamber stores one record per champion type, rather than a level-scaled generic creature capacity. With three basic types, this means at most the other two are frozen when one is active. A new basic champion may be raised after freezing the current one.

## Evolution and feeding

Primary references are `client/scripts/CHAMPIONCAGE.as`, `_guardians`, `setFeedProps`, and `FeedGuardian`; `client/scripts/CHAMPIONCAGEPOPUP.as` governs when the normal feed button is available. The server's `championStats.ts` mirrors numeric champion statistics but does not include the monster-food recipes, so the client is required to recover those.

There are six evolution levels. Reaching the next level requires 3, 6, 9, 12, then 15 feed actions. Each normal feed consumes the recipe's **healthy housed** monsters immediately. It increments the feed count and sets the next feed time 23 hours ahead. The first raised champion also starts with a 23-hour feed timer. When the required count is reached, evolution occurs immediately, feed count resets to zero, the next feed timer is set, the champion's combat stats change, and health becomes the new maximum. See `ChampionBase.as:731`, `levelSet`.

The supplied source has two recipe sets. `setFeedProps` replaces the default recipe whenever the yard is **not** Map Room 3.

| Champion | Evolution level | Non-MR3 food per feed | MR3 food per feed |
|---|---:|---|---|
| Gorgo | 1 | 15 Octo-Ooze | 8 Octo-Ooze |
| Gorgo | 2 | 10 Octo-Ooze + 5 Ichi | 5 Octo-Ooze + 3 Ichi |
| Gorgo | 3 | 20 Ichi | 20 Ichi |
| Gorgo | 4 | 10 Ichi + 10 Crabatron | 5 Ichi + 5 Crabatron |
| Gorgo | 5 | 20 Crabatron | 10 Crabatron |
| Drull | 1 | 30 Pokey | 15 Pokey |
| Drull | 2 | 20 Pokey + 15 Fink | 10 Pokey + 8 Fink |
| Drull | 3 | 50 Bandito | 25 Bandito |
| Drull | 4 | 10 Bandito + 15 Fang | 5 Bandito + 18 Fang |
| Drull | 5 | 30 Fang | 15 Fang |
| Fomor | 1 | 20 Bolt | 10 Bolt |
| Fomor | 2 | 20 Bolt + 2 Brain | 10 Bolt + 1 Brain |
| Fomor | 3 | 40 Bolt + 5 Brain | 20 Bolt + 3 Brain |
| Fomor | 4 | 30 Bolt + 10 Brain | 15 Bolt + 5 Brain |
| Fomor | 5 | 20 Brain | 10 Brain |

At level 6, further feeds increase food bonus up to three tiers. The non-MR3 bonus recipe is 20 Crabatron for Gorgo, 30 Fang for Drull, and 20 Brain for Fomor. MR3 uses half those quantities. Food bonuses are distinct from six evolution levels. The source's bonusFeedTime is 24 hours; ordinary feedTime is 23 hours and some client paths still use feedTime when setting the next bonus timer. A remake should document its chosen consistent timer rather than silently claim bug-for-bug equivalence.

The source also includes Shiny feed/evolve shortcuts. They are a separate premium economy, not required to express the ordinary-monster food loop.

## Health, persistence and the Chamber

`server/src/schemas/ChampionSchema.ts` stores type (`t`), health (`hp`), evolution level (`l`), next-feed timestamp (`ft`), feed count (`fd`), food bonus (`fb`), power level (`pl`), status and optional name. `ChampionBase.as` exports the same state. The remake should validate this authoritatively rather than copying the original client-save trust model.

`ChampionBase.tickBPen`, around line 1044, passively heals a champion in its pen. Full-health recovery times by evolution level are 3,600, 7,200, 14,400, 28,800, 57,600 and 115,200 seconds. The frame implementation grants chunks every five seconds. A champion whose battlefield HP reaches zero retreats instead of being permanently deleted; when constructed from a nonpositive saved HP, the original class clamps to 1 HP. A remake may instead represent a defeated/injured champion explicitly and recover from zero, but that is an adaptation.

`CHAMPIONCHAMBER.FreezeGuardian` requires the champion to be at full HP and its feed timer not already overdue. It changes status to frozen, stores `feedTime - now` as remaining time, removes the active champion, and saves. `ThawGuardian` requires the Chamber itself to be fully repaired and no current active champion. It restores the remaining feed timer relative to now and the saved level/food bonus/HP. The original does not add a switch cooldown. There is no healing benefit from freezing an injured champion because freezing is rejected while injured.

At 24 hours beyond the next-feed timer, the original starvation code reduces unfinished feed progress below level 6, or food bonus at level 6, without reducing evolution level. Frozen feeding timers are paused. If a release omits starvation for offline-friendly play, describe that explicitly as remake behavior.

`server/src/controllers/base/save/handlers/championHandler.ts` only allows attacker-reported defender health to decrease. The PHP server should do better: calculate both sides' champion damage from its own battle state, lock their persistent state, settle once, and avoid concurrent/offline recovery erasing battle damage.

## Original combat statistics

Arrays are levels 1 through 6, from `CHAMPIONCAGE.as` and `server/src/game-data/stats/championStats.ts`. The source's actual champion movement property divides the listed speed by two, and original coordinates/frame ticks are different from remake grid units.

| Champion | HP | Damage | Listed speed | Range | Flinger bucket size |
|---|---|---|---|---|---:|
| Gorgo | 40000,80000,120000,140000,160000,200000 | 1000,1200,1500,2000,2500,3000 | 1,1.2,1.4,1.6,1.8,2 | 35,45,55,65,70,70 | 240 |
| Drull | 12000,20000,36000,42000,52000,60000 | 3000,3600,4200,5500,6500,8000 | 2,2.2,2.5,2.8,3.2,3.6 | 35,45,55,65,85,90 | 180 |
| Fomor | 15000,17500,20000,22500,25000,40000 | 70,80,90,100,110,120 | 1.2,1.4,2,2.1,2.2,2.3 | 140,140,180,190,200,210 | 200 |

Gorgo and Drull use ground melee behavior. Fomor uses ranged attacks, with ground movement for the first two levels and flying from the third. `Fomor.as` sets attack delay to 8 source ticks; `ChampionBase` starts with 56. Thus the small listed Fomor damage is not directly comparable as per-second damage.

`ATTACK.as:520` spawns a champion from the Flinger bucket at the clicked/scattered point with saved health and level, marks that champion as flung, and prevents producing unlimited copies. In non-MR3 mode it contributes its bucket size to flung space. A remake should enforce at most one deployment of the active champion per invasion and persist its injury at settlement, including retreat and timeout paths.

### Fomor's aura

This is **not a healing spell**. `client/scripts/com/monsters/monsters/champions/Fomor.as` attaches `AOEEnrage(250, 1 + 2 * buff, buff)`. Buff values are 0.1,0.2,0.3,0.4,0.5,0.6 by evolution level. The three maximum-level food bonus tiers add 0.03,0.06,0.15.

`components/abilities/AOEEnrage.as` selects nearby friendly ground, flying and invisible monsters, excludes the owner, and periodically removes effects when targets leave radius or the owner leaves battle. `components/abilities/Enrage.as` multiplies movement speed, divides attack delay by the same multiplier, and adds armor through `components/modifiers/ArmorPropertyModifier.as`. With no other armor, incoming damage is multiplied by `1 - buff`; multiple armor effects use compounded reductions. The source uses a pink glow to communicate the aura.

## Ordinary-monster support

| Species | Original level-1 HP / damage / listed speed | Housing / bucket | Targeting | Extra source behavior |
|---|---|---|---|---|
| Brain C9 | 600 / 100 / 2 | 30 / 30 | Resource buildings | `creeps/Brain.as` only adds Invisibility when poweredUp by Monster Lab |
| Crabatron C10 | 4000 / 100 / 1 | 40 / 40 | Defense towers | Uses normal ground creature behavior |
| Project X C11 | 800 / 1200 / 0.9 | 70 / 70 | Defense towers | `creeps/ProjectX.as` only adds death-area damage when poweredUp |

`MonsterBase.as:991` decodes preferred target groups: 2 walls, 3 lootable resource buildings, 4 defense towers/occupied bunkers, 6 monsters/bunkers, 1 general nearest eligible structure. Target fallback matters: a specialized monster still attacks other eligible buildings after its preferred targets are exhausted.

## Visual references for newly generated replacements

Reference files exist below `server/public/assets/`; they are design references, not assets to copy into the remake.

- Cage: `buildings/monstercage/top.1.png` and `top.1.v2.png` (closed/open).
- Chamber: `buildings/champchamber/top.3.png`, `top.3.damaged.png`, `top.3.destroyed.png`.
- Gorgo: `popups/guardian_select_gorgo.png`; `monsters/G1_L1-250.png` through `G1_L6-250.png`; sprite sheets `monsters/ape_1.png` through `ape_6.png`.
- Drull: `popups/guardian_select_drull.png`; `monsters/G2_L1-250.png` through `G2_L6-250.png`; sprite sheets `monsters/dragon_1.png` through `dragon_6.png`.
- Fomor: `popups/guardian_select_fomor.png`; `monsters/G3_L1-250.png` through `G3_L6-250.png`; sprite sheets `monsters/fly_1.png` through `fly_6.png`.
- Brain: `monsters/C9-150.png`, `monsters/brain.png`, `monsters/brain.v2.png`.
- Crabatron: `monsters/C10-150.png`, `monsters/crabatron.png`.
- Project X: `monsters/C11-150.png`, `monsters/projectx.png`.

`client/scripts/SPRITES.as:53` maps the three champion sprite families to their six levels. Each evolution has an independent original sheet; scaling one new image six times should not be described as six separately illustrated evolution forms.

## Later source work identified

`client/scripts/QUESTS.as` contains construction/monster/attack/good/evil quest groups, building-level and accumulated-action requirements, resource/monster rewards, and champion hatch/evolve checks. `ACHIEVEMENTS.as` includes individual and all-three fully evolved champion achievements. These require persistent authoritative progress and exactly-once reward claims; reading their definitions does not implement them.

World maps are a larger storage/ownership expansion, not merely a new opponent list. MR2 takeover (`server/src/controllers/maproom/v2/takeoverCell.ts`) requires at least 90% damage and range validation, updates both old/new owners' holdings, creates an outpost, and gives 12-hour protection. MR3 (`services/maproom/v3/takeoverCellMR3.ts`, `config/MapRoom3Config.ts`) uses hex cells, resource outposts, strongholds, range influence and fortifications with different rewards. Those systems are deferred beyond this champion-focused expansion.
