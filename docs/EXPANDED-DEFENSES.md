# Railgun, Inferno defenses and Brukkarg rewards

The surface Railgun unlocks at Town Hall 5. Its targeting circle marks the distance at which it can acquire a ground monster. The actual bolt travels through all eligible attackers along the same line, up to the edge of the yard. Placing towers along a narrow approach makes the extra reach useful.

| Defense | Levels | How it fights |
| --- | ---: | --- |
| Railgun | 8 | Fires a piercing ground shot every four seconds. Its line extends past the acquired target, with a half-tile hit radius. A damaged gun loses up to half its strength. |
| Inferno Blast Tower | 7 | Lobs a traveling shell into a ground formation. Damage drops toward the edge of the blast, to one-fifth strength. |
| Inferno Quake Tower | 6 | Lifts its ram for 4.25 seconds. The impact strikes all eligible ground attackers then in range. Damage falls with distance, down to one-third strength. Destroying the tower during its wind-up stops the impact. |
| Inferno Magma Tower | 6 | Tracks ground and air invaders with molten shots. A surviving ground target burns once a second at half the tower’s base damage. Repeated burning hits preserve the first flame and its timer. |
| Spurtz Cannon | 5 | Fires 10–50 eggs per volley as the cannon levels up. It cycles through up to ten nearby ground targets, scattering eggs farther at longer range. Impacts can hatch short-lived defending Spurtz. |
| Black Diamond Spurtz Cannon | 5 | The final Brukkarg reward. It gains armor, higher damage and five more eggs per volley at every level. |

## Placement and progression

Town Hall 5 allows two surface Railguns. Town Hall 6 and higher allow three. Construction, upgrades and the four fortification tiers use the normal workers and resources. Bots can add a Railgun when their home reaches Town Hall 5. Guardians can build a second. The cavern and Moloch layouts use the native Inferno defenses. The fifth Inferno Emergence defense unlocks the Magma and Quake Towers at home. Completing Moloch’s ten-wave siege also unlocks Magma Towers at home and in Frontier outposts.

The Brukkarg expedition awards one cannon at three cleared yards and a second at four. The fifth clear changes the earned design to Black Diamond. The home Build menu then offers the appropriate cannon. Placement costs no resources and completes immediately, including while workers are occupied. Later upgrades cost materials and worker time. The two-cannon limit includes both variants. Previously placed guns retain their positions, level, health percentage and outstanding paid upgrade during conversion. Hatchlings fight only in the current battle and never enter a keeper’s housing or garrison.

## Source and local adaptations

The defense data comes from the supplied client’s YARD_PROPS.as and INFERNOYARDPROPS.as. Surface IDs are 118, 136 and 137. Inferno IDs are 129, 130 and 132. Inferno ID 20 also represents the Blast Tower. Building 128 is the Compound. Its housed monsters can intercept invaders as described in [Inferno](INFERNO.md#compound-interception). The individual HD illustrations were generated using the corresponding original sprite frames as design references.

BUILDING118.as, INFERNOQUAKETOWER.as, INFERNO_CANNON_TOWER.as, INFERNO_MAGMA_TOWER.as, SpurtzCannon.as, BlackSpurtzCannon.as, Targeting.as and FlameEffect.as supply the distinct firing rules. The server’s BrukkargConfig.ts defines the original award thresholds and quantity limits.

The local yard uses forty source pixels per tile. Construction prices use one hundredth of the original resources, and construction time uses one three-hundredth of the original seconds. The surface Town Hall reaches level 10. The PHP engine advances in quarter-second steps. It batches two five-frame egg shots into a step and persists their individual landing times. Deterministic seeded spread, hatch outcomes and a short two-and-a-half to four-and-a-half second lifetime let a saved battle resume consistently. Eggs use the level’s archived splash radius. The Flash version derived their impact footprint from the rendered projectile. The browser animates the confirmed beam, in-flight shells, lifting ram, expanding impact rings, flame marks and hatching eggs.
