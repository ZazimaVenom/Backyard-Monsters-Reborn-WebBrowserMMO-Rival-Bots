# Monster Madness, Wrath of the Champion and Korath

Capture a resource outpost through the World Map to begin a four-day Monster Madness run. Victory must transfer ownership through the map’s 90% capture rule. Each distinct outpost contributes its pre-invasion tier’s value once in a run. Capturing the same site again in that run can restore ownership but earns no additional score. The Champion Hall shows the current score, time remaining and permanent powers.

| Score in one run | Permanent reward |
|---:|---|
| 42,000,000 | Raise Korath in a working Champion Cage. |
| 94,500,000 | Breath of Fire becomes usable at evolution 4. |
| 160,125,000 | Fists of Doom becomes usable at evolution 5. |

Later runs start with a new score and a fresh list of eligible sites. A keeper retains their best score and highest earned reward. A victory report carries the points actually credited. Reopening a finished battle cannot pay them again. World Map transfers, scores and earned powers are saved under the same transaction. Previously saved invasions have no Monster Madness value frozen at launch and retain their original settlement rules.

## Champion care and battle

Korath can lead one invasion or defend its home at its current health, using the same Cage, Chamber, recovery, freezing, hunger and deployment rules as the other persistent champions. It occupies a Champion slot. The Chamber holds the four available champions, allowing a complete roster to be switched without discarding an individual.

Korath eats ready monsters from the Inferno Compound. Feeding consumes 20 Spurtz and 10 Zagnoid per meal at evolution 1, 10 Zagnoid and 2 Sabnox at evolution 2, 6 Sabnox at evolution 3, 10 Sabnox at evolution 4 and 3 King Wormzers at evolution 5. The five evolution thresholds are 3, 6, 9, 12 and 15 meals. The three level-six bonus tiers and subsequent maintenance meals each consume 3 King Wormzers. Combat snapshots freeze the champion's earned power, evolution, health and bonus stats.

A claw strike burns an enemy monster for 10% of Korath's attack damage each second. The FlameEffect persists until defeat. Repeated attacks refresh the same effect without stacking, including an existing Magma Tower flame. Breath of Fire lets Korath target flyers, dealing one-quarter direct damage plus the burn. Fists of Doom replaces the strike after three melee hits with a ground shock. Its full-damage radius is 1.5 times melee reach, decreasing to zero at 2.5 times reach. The defending champion cannot damage its own buildings.

## Source and local balance

MonsterMadnessConfig.ts, scoreOutpostTakeover.ts and korathRewards.ts provide the three goals, one-outpost-per-run rule, score cap and monotonic rewards. EventCalendarConfig.ts sets the four-day duration. Local runs start on a keeper’s first qualifying capture after expiry. The recovered server uses a scheduled shared event calendar instead. The outpost pricing function from tribeTakeoverValue.ts receives equivalent original levels 28, 32, 36, 40 and 44 for the local five outpost tiers. This retains the original value range on the remake’s shorter map progression. Resource outposts owned by a rival use the same tier valuation, since this world stores their tier and structures in place of the original empire-value field.

Korath uses CHAMPIONCAGE.as's non-MR3 diet, native speed/feeding progression, scaled health (15%) and damage (12%), and its local cohort’s shorter care timers. Korath.as supplies power and evolution gates, airborne damage, burning and attack-count stomp. The original FlameEffect and CStatusEffect classes define the single periodic fire effect. Melee reach converts at 80 original pixels per tile. The local engine applies projectiles and shock at their simulation strike rather than replaying the Flash timeline frame by frame.


## Event identities in the recovered source

WrathOfTheChampion.as implements the Flash interface for Monster Madness, replayable event 6. It reads MonsterMadness.points and builds WOTCQuota instances from the same three score goals. KorathRewards.sync applies one permanent Korath reward with a value from 1 to 3. The recovered server registers MONSTER_MADNESS under the same event ID and credits outpost transfers. The local Hall displays both source names and uses this one score across the three rewards.

CHAMPIONCAGE.as defines five source champion identities. G1 Gorgo, G2 Drull, G3 Fomor and G4 Korath are the four basic champions available in the remake. G5 Krallen is a special second guardian tied to the distinct Hunt for Krallen (King of the Hill, event 4). KrallenConfig.ts and krallenRound.ts specify seven-day shared rounds, surface attack loot, Town Hall 6 and Map Room 2 or 3. A round awards the temporary champion at 250 million loot. A score of 750 million qualifies for an upgraded award. After five consecutive wins the keeper retains Krallen, with a 500 million loot target for its weekly upgrade. Its five combat forms and the special guardian slot belong to that separate event. The playable weekly contest, its local requirements, and companion combat are described in [Hunt for Krallen](HUNT-FOR-KRALLEN.md).
