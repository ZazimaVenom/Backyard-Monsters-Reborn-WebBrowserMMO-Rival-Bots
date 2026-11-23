# Browser renderer — 0.8

The Canvas renderer draws authoritative server snapshots over an isometric yard. The browser does not decide damage, Lab powers, resource spending, buffs, aura effects, healing, trap triggers, injury recovery or battle results.

## Champion visuals

Champions load from the separate `catalog.champions` collection. Rendering assets together does not add champions to the normal monster catalog or Hatchery. Brain and Crabatron remain ordinary monsters.

Gorgo, Drull and Fomor use larger body sizes, a gold ground ring and diamond, a species/evolution label, and a persistent health bar. Defending champions retain cyan team accents. This makes a champion distinguishable from both ordinary attackers and bunker defenders, including at full health.

A home or visited yard shows its active champion near a completed, living Cage. Frozen champions, zero-health champions, and champions reserved in another battle do not roam. The displayed health and bonus-adjusted maximum come from the server champion snapshot; the catalog level is only a legacy fallback. A separate `B1`–`B3` suffix identifies earned bonus feeding at evolution six. The renderer does not heal the champion or grant food bonuses. Fomor uses ground movement at levels 1–2 and flying movement from level 3.

A living Fomor's authoritative `auraRange` draws a translucent pink/gold ground ellipse below buildings and units. Allies with `buffed` and `buffSource` have a separate pink ring and small upward indicators. The aura disappears when its owner dies or retreats; the client never grants the buff merely because a sprite appears inside the ellipse. Fomor's projectile is violet, distinct from healing beams and Teratorn's fireballs.

Champions have an independent clicked-ground deployment preview: `setPlacement({mode:'champion',type:'gorgo'})`. It verifies the selected snapshot champion is available, alive and not already deployed, and uses the same boundaries and structure exclusions as ordinary invasion placement. It does not apply Flinger capacity, ordinary monster counts or Flinger cooldown. The server validates the action again. Champion deployment produces gold particles; defeat shows a retreat label rather than implying the champion was permanently killed.

## Other combat visuals

- Air units have elevated sprites, separate ground shadows and subtle wind motion.
- Burrowing units travel as moving earth mounds and dust trails. An authoritative `emerge` event produces a dirt burst and can play Wormzer's emergence sequence.
- Healer events draw a green beam and a positive healing number at the friendly target.
- Eye-Ra detonations and triggered traps use distinct expanding blast rings, sparks or earth/metal debris.
- Laser shots produce a bright beam; Tesla arcs and Teratorn magma projectiles have distinct appearances.
- Bunker defenders have cyan ground rings, sprite edge glow and small shield markers.
- The ordinary Flinger preview supports mixed `troops` buckets, capacity and remaining-army checks, and the five-second server cooldown.

These effects are new implementations. They do not reproduce the complete original Flash frame library or every original special ability.

## Image loading and animation sheets

Building, monster and champion sprites load from their catalog `art` filename in `public/assets/art`. New catalog entries do not require another static preload list. Animation sheets are optional enhancements; the individual sprite remains the fallback. A missing sheet does not stop play.

| File | Layout | Playback |
|---|---|---|
| `pokey-walk.png` | 2 × 2, four equal cells, row-major | Walking poses, 8 frames/second |
| `teratorn-fly.png` | Same | Wing poses, 7 frames/second |
| `wormzer-emerge.png` | Same | Four emergence poses, 0.18 seconds each, once per emergence event |
| `gorgo-walk.png` | Same | Walking poses, 6 frames/second |
| `drull-walk.png` | Same | Walking poses, 8 frames/second |
| `fomor-fly.png` | Same | Wing poses, 6 frames/second; only airborne Fomor |
| `brain-walk.png` | Same | Walking poses, 8 frames/second |
| `crabatron-walk.png` | Same | Walking poses, 6 frames/second |
| `projectx-walk.png` | Same | Walking poses, 7 frames/second |
| `octo-walk.png` | Same | Crawling poses, 6 frames/second |
| `fink-walk.png` | Same | Walking poses, 8 frames/second |
| `ichi-walk.png` | Same | Heavy walking poses, 5 frames/second |
| `bandito-walk.png` | Same | Running poses, 9 frames/second |
| `dave-walk.png` | Same | Tread/chassis locomotion, 5 frames/second |
| `bolt-walk.png` | Same | Running poses, 10 frames/second |
| `fang-walk.png` | Same | Walking poses, 8 frames/second |
| `eyera-walk.png` | Same | Walking poses, 7 frames/second |
| `zafreeti-fly.png` | Same | Levitation poses, 5 frames/second, between confirmed healing casts |

These eighteen movement/emergence sheets use cell dimensions derived from the actual PNG dimensions. The four v0.5 sheets are 1,254 × 1,254 RGBA PNGs with four 627 × 627 cells. A shared alpha bounding box across all four cells preserves pose alignment. The renderer never modifies the source sheets. Reduced-motion mode freezes looped pose changes and removes ambient movement; aura and team indicators remain readable.

Brain, Crabatron, Bandito, D.A.V.E., Bolt, Fang and Zafreeti's static artwork and locomotion sheets face screen-left. Project X, Octo, Fink, Ichi, Eye-Ra and the champion artwork face screen-right. Per-species native-facing handling mirrors their images only when needed to face a movement direction or attack target. An idle creature without a heading retains its native pose. Zafreeti gently levitates with tendril motion; its sheet does not invent wings.

Walking sheets are used while walking, not presented as separate attack frames. Ground-level Fomor uses static art outside a confirmed attack. One new champion illustration/sheet is not six separately illustrated evolution forms. Complete directional movement/attack coverage, general hurt/death/retreat sequences and evolution art remain independent parity requirements; see `PARITY.md` and the art manifest for actual delivered assets.

## Drawn attack and casting sequences (0.7)

The seven existing sheets for Pokey, Octo, Ichi, D.A.V.E., Gorgo, Drull and Fomor are joined by eleven independent action sheets. Each uses four equal row-major cells in a 2 × 2 layout. Loading and shared alpha-bound measurement use actual image dimensions; no per-frame recropping changes character scale between poses. The art manifest records individual native image sizes and generated asset coverage.

| Added sheet | Confirmed trigger | Native facing |
|---|---|---|
| `fink-attack.png` | Melee `hit` or `strike` | Right |
| `bandito-attack.png` | Melee `hit` or `strike` | Left |
| `bolt-attack.png` | Melee `hit` or `strike` | Left |
| `eyera-attack.png` | Terminal `explode` | Right |
| `fang-attack.png` | Melee `hit` or `strike` | Left |
| `wormzer-attack.png` | Surfaced melee `hit` or `strike` | Right |
| `teratorn-attack.png` | Ranged `shot` | Right |
| `zafreeti-attack.png` | Positive `heal` | Left |
| `brain-attack.png` | Melee `hit` or `strike` | Left |
| `crabatron-attack.png` | Melee `hit` or `strike` | Left |
| `projectx-attack.png` | Melee `hit`/`strike`, or researched terminal `acid` | Right |

A new authoritative `hit`, `shot` or format-7 `strike` event for a living supported unit starts one 0.64-second sequence after receipt, provided its battle timestamp is at most one second behind the snapshot and not in the future. `strike` carries source/target positions and IDs but no damage number: the existing target `hit` event remains responsible for that feedback. Idle/attack state alone never loops or invents strikes. Event IDs prevent replay across repeated polls, and multiple events sharing a unit and battle timestamp—such as D.A.V.E.'s twin rockets—share one body attack even if delivered across separate polls. Projectiles, damage numbers, sound, Lab powers and Fomor's aura retain their existing authoritative event/state paths.

Zafreeti's casting sheet requires its own positive healing event and `heal` state. A healing state without that event, zero healing, or an unrelated attack event cannot start casting. Levitation resumes after the cast. Wormzer's confirmed bite takes priority over a remaining emergence effect; returning underground cancels the bite and hides the aboveground body. Teratorn and airborne Fomor resume their flight loops after attacking.

Walking, dying, hiding, retreating, finishing or leaving the invasion stops living-unit action sequences. Four drawn poses replace procedural body wobble while the sequence is active. Reduced motion holds a stable action pose. Missing action sheets independently fall back to the species' existing static/movement presentation. Home wanderers never attack merely because an illustration is loaded. Ground/air altitude, team outlines, target-facing and champion injury bars remain independent of pose choice; action-sheet facing is maintained separately from locomotion orientation.

Eye-Ra and Project X are already dead when the server sends their respective `explode` or researched `acid` event. A separate visual echo therefore draws their action sheet at the confirmed event coordinates, advances through the four poses and fades out within 0.84 seconds. It has no health bar, aura or targetable gameplay body and never changes the dead source's health. It is depth-sorted with the yard. Ordinary death alone cannot invent either special power; retreat, hidden/removed sources and stale events cannot produce an echo. A final-kill event may finish its echo when an active invasion settles, but opening an already finished report does not replay one. Reduced motion holds a static impact pose during the bounded fade. If the sheet is unavailable, the independent confirmed explosion/acid particles remain.

Sprite timelines are removed when their source disappears, expired entries are pruned on snapshot updates, and their shared map is capped at 512 entries. Drawing also checks expiry every frame. Particle events retain their separate 160-entry cap. These are bounded visual timelines, not combat simulators. Their receipt-driven timing is a browser adaptation, not a claim of exact Flash timing.

## Building fortification presentation (0.6)

A saved tier from `fortification` (0–4) produces a reinforced foundation outline and separate `F1`–`F4` shield badge. Hovering or selecting the structure displays its resistance percentage. Fortification does not lengthen, multiply or otherwise invent the real HP bar. Ruins do not display a live armor badge; malformed/out-of-range tiers cannot create one.

At home and on visits, `fortify_ready_at` drives a server-clock countdown and an inactive building appearance. The completed tier remains visible while the next one is queued; the browser never grants `fortify_to` when a local timer expires. Operational light effects stop during work. In combat, the frozen `fortifying` boolean overrides the wall clock: a tower that began the invasion offline remains labeled `FORTIFYING · OFFLINE` even if its home deadline passes. New server state, rather than rendering, determines when an earned tier or active tower becomes available. Reduced motion preserves every tier, resistance and work-status label.

## Network Fortification presentation (0.7)

Selecting or hovering a protected damageable structure adds a cyan isometric foundation outline and `N1`–`N6` badge, separate from its individual `F1`–`F4` armor. The tooltip identifies network resistance and, when armor is also present, the combined reduction. Neither modifies the health bar. Home and visiting yards read `base.networkDefense:{count,reduction,siteIds}`; combat reads the frozen building `networkReduction` and prefers its authoritative `damageReduction` for the combined label. Missing legacy battle fields never inherit the private home bonus. Neighbor IDs or counts alone cannot invent a missing reduction, and the renderer never calculates ownership or adjacency from map geometry.

The server's six-neighbor progression is 7%, 13%, 23%, 35%, 50%, 75%. Home display combines armor and network multiplicatively: `1 − (1 − armor) × (1 − network)`. Source Fortification sites do not receive their own network protection. Untargetable cores and ruins do not display a protection badge. Reduced motion preserves every resistance label.

`outpostdefender.png` is a distinct, non-firing Fortification landmark. Its small blue-green lantern glow is anchored to the generated artwork's cropped geometry. The glow stops while destroyed, constructing or offline; reduced motion keeps a steady light. Unlike the untargetable Resource Outpost core, the Fortification core is targetable and displays its actual health bar. The renderer grants no range, income, attacks or protection from the beacon animation.

## Catapult presentation

`setPlacement({mode:'catapult',type:'twig',level:2,radius:4})` enters artillery aiming. The renderer resolves the actual radius and price from `catalog.catapultBombs[type].levels[level]`; a supplied radius cannot override catalog data. The preview uses an isometric blast ellipse, crosshair, ammunition size/family and resource price. It never draws a building placement ghost. Any finite target from 0.4 through 31.6 on each axis is eligible, including occupied building coordinates. Catapult clicks retain thousandth-unit precision rather than snapping to the construction grid.

Preview availability checks active battle status, an equipped Catapult snapshot, facility level, one use per resource family, exact attacker resource balance, and at least one second remaining for impact. These checks are independent of the Flinger, monster stock, bucket capacity and Flinger reload. A defender snapshot without the attacker's private purse cannot advertise an available shot. The server validates every action again.

`battle.catapult.pending` draws the authoritative radius under the battle sprites and a one-second arcing projectile from the Catapult's launch edge. Twig, pebble and putty use distinct gold, blue and violet colors and impact particles. The renderer keeps the pending indicator until a server snapshot confirms the impact; it does not apply damage when the animation arrives. Reduced motion replaces travelling projectiles with an `Incoming` label and keeps the target outline.

## Monster Lab presentation

The unit fields `labLevel`, `labAbility`, `cloaked`, `blinking`, `poisoned` and `boosted` come from server snapshots. Researched monsters have one to three small tier dots. Cloaked Brain stays translucent with a `CLOAK` label so the keeper can follow its position; this is not targetability logic. Blink uses lower opacity and an explicit `BLINK` marker. Poison has a green droplet. Putty Rage has its own violet ring and lightning indicator, distinct from Fomor's aura. All statuses disappear when the server clears them.

Combat events supply the animation cues:

| Server event | Visual |
|---|---|
| `blink` | Paired cyan portals and a dashed transit trail |
| `cloak` | A brief reveal/concealment outline |
| `multistrike` | Three claw strokes at the extra struck target |
| `whirlwind` | Sweeping arcs around Bandito |
| `venom` | Green venom droplets at the bitten monster |
| `acid` | A single green death burst; no invented lingering acid pool |
| `emerge_splash` | A widening earth shock wave |
| `ricochet` | Curved fireball travel from the previous struck building |
| `shot` with `projectile:'rocket'` | Two distinct offset D.A.V.E. missile paths, using `volley` |
| `explode` | Eye-Ra's authoritative explosion radius; `airburst:true` adds an elevated shock wave and rising trails |
| `catapult_launch` / `catapult_impact` | Resource-family launch/impact cues and impact particles |
| `buff` | A brief putty ring at the affected ally |

Sound callbacks add `catapult`, `catapult-impact`, `boost`, `blink` and `venom`; existing `explode` and `shot` cues cover death bursts and rockets. Event IDs continue to prevent repeated effects/audio across polling. The live effect queue retains at most 160 entries and discards effects after 1.7 seconds. Individual bursts have bounded particle counts; reduced motion removes looping sprite motion and travelling artillery while retaining readable status and impact information.

## Snapshot and event contract

Units expose `team` (`attacker`/`defender`), `movement` (`ground`/`air`/`burrow`), and `state` (`walk`, `attack`, `heal`, `burrow`, `blink`, `idle`, `dead`). Missing team/movement fields retain legacy behavior. Champion units additionally expose `champion:true`, `type`, `level`, `auraRange`, `auraBuff`, and authoritative `hp`/`max_hp`. Buff recipients expose `buffed` and `buffSource`.

The attacker's champion snapshot is `battle.champion:{type,level,hp,max_hp,deployed,available}` or `null` for absent/legacy champions. A normal `deploy` event has `champion:true` for champion arrival. `champion_retreat` identifies the champion through `kind` and `targetId`; it has the same ground coordinates and event timestamp conventions as other events. Sound callbacks use `champion` and `champion-retreat`. Ordinary `shot`/`hit` events remain supported. Event IDs prevent repeating sound and visual effects across polls.

The server removes unrevealed traps from visitor and attacker snapshots. The renderer also excludes explicitly hidden objects from drawing and hit-testing. All traps, including revealed ones, are excluded from invasion placement previews, preventing placement-based trap discovery. Private home buildings never fill in missing battle snapshot data.

For ordinary monsters, the UI may pass `setPlacement({mode:'deploy',type:'pokey',troops:{pokey:3,teratorn:1}})`. `setServerTime(timestamp)` aligns construction countdowns with server time.

## Validation

`node tests/renderer_test.mjs` passes **417 checks**. They cover hidden-trap hit testing, snapshot isolation, ordinary and champion deployment rules, boundaries, bucket capacity and cooldown, unavailable/duplicate/defeated champions, separate catalog loading, pose progression and fallback, reduced motion, evolution-dependent flight, home/frozen/injured/reserved champion visibility, aura projection, buff indicators, target-facing, damage placement, special effect/audio dispatch and deduplication. Brain and Crabatron's new sheets are checked for walking/attack state selection, missing-sheet fallback, reduced motion, and both movement and stationary target-facing with their native-left artwork.

`node tests/champion_ui_test.mjs` separately checks the care interface, covering feed/readiness timers, owned/active/frozen states, injury and Chamber gates, authoritative action feedback, failed actions and background-refresh usability.

The 0.4 checks also exercise all six added walk cycles, their fallback and native-facing behavior, actual sheet-cell cropping, Catapult affordability/family/level/boundary/time gates, occupied-building aim, hidden-purse and legacy safety, catalog-authoritative radius, explanatory ghost labels, pending arcs and reduced-motion markers, status coexistence and clearing, every new ability effect, twin-rocket separation, researched Airburst distinction and reduced motion, ricochet origin, bounded effects, audio deduplication and precise click coordinates.

The 0.6 checks additionally cover seven independent attack sequences, each pose's ordered progression, reduced motion, stable drawn-body geometry, both native-facing directions, static fallback, event/poll/rocket deduplication, late-history rejection, movement/death/retreat/finished-state cleanup, Fomor flight resumption, bounded timeline storage, fortification labels versus real HP, pending-tier isolation, frozen offline towers, invalid tiers, authoritative champion bonus health and bonus-tier labels.

The 0.7 checks add all eleven action mappings, ordered poses, independent fallback and facing, source-only melee cues, casting/flight/emergence priority, heal/attack rejection, death-burst lifecycle and final-kill overlap, event/poll deduplication, hidden/removed/retreating sources, actual isometric depth ordering, bounded queues, network-versus-armor labels, frozen combat reduction, private-home isolation, unchanged health bars, invalid network data and anchored/disabled/reduced-motion Fortification beacons.

These checks exercise rendering behavior with a recording canvas context. They complement browser visual QA; they do not claim pixel-perfect visual verification or full Flash animation parity.

## Territory yard visuals

Map Room, Resource Outpost, Stronghold and Fortification use their own generated building art. The outpost core is an untargetable one-HP landmark: it remains selectable for information but never displays a misleading combat health bar. When selected or in combat, it shows “CAPTURE THE DEFENSES.” The surrounding buildings remain the capture targets. Deployment previews and exclusion overlays also ignore catalog-marked untargetable cores, matching server placement rules. Stronghold remains a damageable structure and its authoritative shots draw electric arcs. The Fortification core is also damageable, but does not fire. Territory structures retain construction/repair presentation; operational effects become static or stop under reduced motion.

The four added locomotion sheets are checked for native orientation, action selection, progression, missing-image fallback and reduced motion. Walking sprites are not relabeled as attack frames. Zafreeti's levitation loop continues between confirmed healing casts, while its healing beam and casting sequence come only from server events. Combat health, damage, ownership and capture progress are never determined by image animation.

## Strategic map module

`createWorldMap({state,api,esc,toast,onVisitHome,onVisitSite})` in `public/assets/worldmap.js` owns the canvas and its accessible companion controls. Required IDs are `world-map-canvas`, `world-map-info`, `world-map-holdings`, `world-map-status`, `world-map-controls` and `world-map-sites`. The root page owns layout/CSS and visits, battles and management dialogs.

The returned controller exposes `open()`, `refresh()`, `setVisible(boolean)`, `resize()`, `destroy()`, `reset()`, `selectSite(id,{center:false})`, `centerHome()`, `panTo(q,r)` and a read-only `snapshot` getter. Call `open()` on world-page entry, `setVisible(false)` on exit, and `reset()` when the account resets. Reads use the existing `api('worldMap', undefined, query)` signature. The first request omits coordinates so the server locates the actual home.

The map draws the server's 64 × 64 odd-r cells, real home/site coordinates, ownership colors, protected/busy status, selected detail and actual holdings. Resources, Strongholds and Fortifications use generated art; home markers use compact symbols to stay legible in dense areas. The reach guide shades geometric home/holding ranges; target rings use the actual returned `reachable` flag. Neither implies that a protected, busy, capacity-limited or otherwise unavailable site may be attacked. The later site response decides that.

Pointer dragging and arrow keys pan; the wheel and + / − zoom; Home centers the persisted backyard; Escape clears selection; Enter visits the selected target. Marker and list clicks only select. An explicit Scout/Visit button calls `onVisitSite(site.id)` or `onVisitHome(site.homeUserId)`. All rendered names are escaped. The accessible site list, filters and holdings buttons provide the same targets without needing to operate the canvas.

Reads are bounded to radius 12 and debounce after pan/zoom. Cells outside the current fetched hex radius are muted, rather than implying that unfetched sites are empty. The active view refreshes every 30 seconds. Request generations and account identities reject late/stale responses. Failed refreshes retain the previous real map with an explicit stale label. Hiding/destroying the map stops periodic reads, debounced work and animation frames. Rendering is event-driven with no decorative animation loop; high-DPI backing resolution is capped at 2×.

`node tests/worldmap_ui_test.mjs` covers all 4,096 cell-center projection round trips, odd/even neighbors, finite boundaries, authoritative IDs/reach, safe markup, actual map art, explicit scouting, keyboard navigation, pan/query bounds, ownership races, stale handling, hidden-page cleanup and cross-account response isolation. Recording-canvas/DOM tests complement live browser checks; they do not claim a completed original world-map port.

## Scouting navigation

Home and territory visits share a navigation generation. A newer destination, page change, home return, account change or battle request invalidates older reads before they can replace the current yard. Invasion planning additionally checks the dialog generation, so a late scout response cannot replace or reopen a newer dialog. These guards cancel stale presentation, not an already submitted server action. `node tests/navigation_ui_test.mjs` passes **31 checks** using deliberately reordered real navigation promises, including home/site races, delayed home refresh, page/account/battle isolation, dialog replacement, Fortification level headings, Resource Outpost-only untargetable health guidance and private draft cleanup.

## Yard Planner and home workers

`setPlanner` supplies pinned building IDs, invalid footprint IDs and grid/range preferences. The renderer displays a separate draft snapshot supplied by `planner.js`; it never mutates authoritative building coordinates. Dragging an eligible building produces one snapped draft move callback. Empty-ground dragging pans; pinned buildings cannot move. Pointer cancellation clears the ghost. Placement previews use the server's boundary and footprint gap with `1e-9` tolerance. Leaving the editor restores the live snapshot immediately. See [YARD-PLANNER-UI.md](YARD-PLANNER-UI.md) for persistence and request guards.

Workers appear only with the owner's private construction snapshot, outside combat, scouting, territories and planning. Purchased capacity determines the visible crew, capped at five; grandfathered excess jobs do not create extra workers. Each worker retains its actual building assignment when a new job changes deadline ordering. A completed deadline stops the work pose, while only the server completes the building.

Workers follow bounded eight-neighbor paths around building footprints, without cutting diagonally through corners. They walk to a clear position beside the active construction, upgrade or reinforcement job and use the hammer loop only after arriving. Free workers return near the Town Hall and idle. Geometry changes reroute them. Workers are decorative and never provide combat targets, damage, speedups or authoritative work completion.

The generated `worker.png`, `worker-walk.png` and `worker-work.png` share right-facing artwork. Action sheets are 2 × 2, four row-major poses, cropped using fixed union bounds to prevent cell clipping or frame-size jitter. Movement and hammer loops are distinct. Reduced motion freezes poses and removes bobbing and impact accents; crew positioning still follows the current job. The generated Yard Planner uses the ordinary building rendering path.

`node tests/renderer_test.mjs` now passes **447 checks**, including worker-only visibility, real assignment counts, obstacle-aware routes, stable job ownership, walk/work selection, reduced motion and planner pointer lifecycle.
