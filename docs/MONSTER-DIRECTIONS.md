# Monster facing and movement

The 27 ordinary surface and Inferno species each have a separate HD turnaround sheet. Each sheet uses that species’ production illustration as its identity reference. The six newly painted views are packed into a 1,536 × 1,024 transparent WebP with six 512 × 512 cells. Frames retain a common scale and footline so turning in place does not lift the body off the ground.

| Camera direction | Paint used |
| --- | --- |
| North · directly away | Rear view, top left |
| Northeast · away and right | Rear three-quarter, top middle |
| East · right | Profile, top right |
| Southeast · toward and right | Front three-quarter, bottom left |
| South · toward the player | Straight front, bottom middle |
| Southwest · toward and left | Independently painted front three-quarter, bottom right |
| West · left | Reflected east profile |
| Northwest · away and left | Reflected northeast rear three-quarter |

The renderer measures movement after isometric projection. It tracks the heading between snapshots, retains it while a creature stands still, and turns a stationary attacker or healer toward its actual target. Home-yard creatures use the tangent of their walking path. Incoming groups, retreating creatures and survivors use the same camera orientation. Burrowing still hides submerged bodies.

The front three-quarter direction retains the existing four-pose walks, wingbeats and strikes for species with those animated sequences. Every ordinary species also has a separate opposing stride and an attack or cast release painted independently at all six bearings. At other angles the neutral painting alternates with the stride. A confirmed attack or heal triggers the release. Neutral silhouettes retain the body, leg, wing, flame or tentacle motion appropriate to the creature. Reduced motion freezes the gait while retaining the correct facing. The event bus, combat timing and server coordinates remain authoritative.

Only species belonging to the current scene load their directional images. The older neutral art and motion sheets remain available during loading or a failed image request. The shared cutout cache holds up to 128 poses. Every direction was inspected against its species reference. Octo received a second transparency pass to remove its backdrop. Project X's frames were trimmed around their painted contours to remove the generator’s diffuse exterior glow.

The central art manifest covers the neutral turnaround sheets. The new [directional action manifest](../public/assets/facing-actions/manifest.json) records the layout and checksum of the 54 action sheets. The [creature performance notes](CREATURE-PERFORMANCES.md) cover the 54 additional defeat sheets, guardian retreats and voices. Run `node tests/monster_directions_test.mjs` to check all 216 facing selections and the renderer’s action priority and fallback.
