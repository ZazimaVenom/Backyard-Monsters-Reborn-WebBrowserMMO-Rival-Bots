# Wild Monster Tribes

Open **Invasion → Wild Monster Tribes** and scout the Legionnaire border camp. Select a building to inspect it, or select **Plan an expedition**. A living Flinger and trained monsters or a ready champion are required. Launch monsters onto clear ground with the usual battlefield controls.

A victory clears a camp and opens the next stage. Destroy its Town Hall or at least 50% of its initially intact target buildings within five minutes. All 21 stages can be replayed. The campaign keeps each keeper’s first clear and best destruction percentage. The four tribe Town Hall objectives in the journal specifically require destroying the Hall.

| Chapter | Source camp IDs in route order |
|---|---|
| Border camp | 1 |
| Scouting post | 2, 11, 21, 31 |
| Encampment | 3, 12, 22, 32 |
| Hill fort | 4, 13, 23, 33 |
| Citadel | 5, 14, 24, 34 |
| High keep | 6, 15, 25, 35 |

Within each chapter after the border camp, the route visits Legionnaire, Kozu, Abunakki and Dreadnaut in that order. The popup shows the supplies offered before launch. A first victory delivers its parcel up to the keeper’s storage limit. Excess remains available under **Collect** on the campaign route. Replay wins can improve a personal best. They award no additional parcel.

A campaign attack is a real, saved invasion. Deployed creatures leave the army, ammunition is consumed, and champion injuries return home. Reloading resumes the active battlefield. The keeper receives a battle report, while the home shield, ranked wins, losses and rank points are preserved. Each player has an independent route. Multiple players may attack the same camp simultaneously. No tribe account occupies a position among the 1,000 persistent bots.

## Source and adaptations

The supplied Refitted server’s `server/src/game-data/tribes/v1` contains the tutorial and five Map Room 1 tiers for each tribe. Each campaign template uses that source camp’s supported structures, building levels and relative X/Y positions. The original client’s `com/monsters/ai/TRIBES.as` and the server’s `enums/Tribes.ts` identify the tribes and template tiers.

The 32-tile remake centers and scales each layout, moves overlapping footprints to nearby space, samples large wall groups around the perimeter, and omits unsupported objects. Every reconstructed camp contains a Town Hall. Health, levels and defense behavior follow the remake catalog. The shipped layouts contain 14–83 structures each. They can be inspected before any troops are committed.

The sequential route, first-clear parcels and independent replayable stages are remake progression. Source Map Room 1 chooses a tier by Town Hall level. Parcel amounts are 4% of the source resource quantities rounded and bounded to 50–5,500 per resource, with 100 each for the scouting post whose source template has no resource balances. Source events, tribe counterattacks and Inferno maps have separate rules.

Creature Carnage also opens from this campaign route. Its six-day, ten-yard Vorg hunt has its own score and saved opponents. See [Creature Carnage](CREATURE-CARNAGE.md).
