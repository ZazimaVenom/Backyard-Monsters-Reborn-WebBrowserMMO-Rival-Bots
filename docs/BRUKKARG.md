# Brukkarg War

Open **Invasions → Wild Monster Tribes → Brukkarg War**. A level-five home Town Hall opens the first defense. Five successful defenses open an enemy yard. Defend and attack in order until you have beaten 25 waves and five camps.

| After the defense | Expedition | First-clear cannon reward |
| --- | --- | --- |
| Wave 5 | 901 · Brukkarg Outpost | Opens the next round |
| Wave 10 | 902 · Brukkarg Barracks | Opens the next round |
| Wave 15 | 903 · Brukkarg Arsenal | One Spurtz Cannon |
| Wave 20 | 904 · Brukkarg Stronghold | A second Spurtz Cannon |
| Wave 25 | 905 · Brukkarg Mirror Yard | Two Black Diamond cannons |

Scout before attacking. Launch your own trained monsters with the Flinger, and bring down the enemy Town Hall. An unfinished attack leaves its damage in the private camp until 24 hours after the first damaging attempt. Scouting shows that damage. A first victory gives a permanent clear and awards supplies within storage capacity. Excess supplies can be collected through the campaign route. League rank, home shields and other keepers' camps stay separate.

Each successful defense adds one event point and each cleared yard adds 1,000. At 4,024 points the enemy copies the completed buildings in your home yard, including their levels and positions. This copy stays fixed if you subsequently move a building. Defending wave 25 opens the mirror for invasion at 4,025 points. Conquering it completes the event at 5,025 points. Existing ordinary Spurtz Cannons convert to their Black Diamond version.

The first four isometric layouts project the structures in the supplied server's `game-data/tribes/events/brukkarg.ts` onto the local grid. Target identity, campaign order, qualifying Town Hall, five-wave gates, scoreboard, copy timing, Hall-only victory, 24-hour partial damage and reward thresholds follow `BrukkargWarEvent.as`, `AttackDefend.as`, `BrukkargConfig.ts` and the reference event services. The local version makes the war available on demand. Source event resource reserves use the local expedition award scale, and densely repeated structures are sampled to fit.

Run `php tests/brukkarg_integration.php` on an empty disposable MySQL database ending in `_brukkarg_test`. It exercises the ordinary saved battle start, deployment, settlement, reports, retry, health and award paths.

## Home defense

Choose **Defend wave** on the Brukkarg War panel. A three-second countdown marks every incoming approach on the canvas. One rotation per battle preserves the original formation of the source directions. Later groups arrive after their individual delays, including the 30-second second assault in wave 11. Across the campaign the 150 incoming groups contain 2,610 monsters. They carry source evolution six and tier-three combat powers where available. Wild champion groups include Gorgo, Drull, Fomor, Korath and Krallen. Krallen's source level-six event request uses the final entry in its five-tier stat table.

Keep damage to the yard below 90% to earn the defense point. After the battle, open the home yard and press **Repair base** for immediate free repairs. Leaving the field retains the current round for **Resume wave**. The siren forfeits the round, including its point, and damage already dealt is retained. Finished rounds persist once under the home row lock, including retries after a lost connection.

Run `php tests/brukkarg_defense_test.php` to verify schedules, arrivals, four-way formations, guardian tiers and score gates. Run `php tests/brukkarg_defense_integration.php` on its own disposable empty database ending in `_test` to check the saved battle, duplicate requests, damage settlement, free repairs and forfeiture.
