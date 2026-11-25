# Backyard Monsters · Rehatched

A new HTML5 Canvas / PHP / MySQL implementation built from a study of the supplied Backyard Monsters Refitted source. No Flash, Bun, Redis, Node.js, Composer, or frontend build step is required to run this game.

**0.13.0 adds regional worlds and buildable outpost yards.** Move to a Frontier or Highlands, settle near a friend, or fight for a native field headquarters. The world event calendar runs shared, scored campaigns. The five-stage Inferno Emergence opens a free cavern after the fifth defense resolves. Keepers can read the searchable field guide in six languages, chat by map edition and use earned Shiny to expand their yards. See [the changes](docs/CHANGELOG.md) and [verified gameplay](docs/VALIDATION.md).

The original-game comparison and outstanding systems are in `docs/PARITY.md`.

<img width="3840" height="2083" alt="1" src="https://github.com/user-attachments/assets/5372218e-c162-470e-b38d-6c954efee654" />

<img width="3840" height="2039" alt="5" src="https://github.com/user-attachments/assets/5fbfe4a1-7c7b-4886-8610-d64148f65354" />

<img width="3840" height="2072" alt="2" src="https://github.com/user-attachments/assets/68903e41-c5b6-4ade-8eed-d65a88031bda" />

<img width="3840" height="2079" alt="3" src="https://github.com/user-attachments/assets/95d9a851-5a77-4394-b1c7-4401cc3bc39e" />

<img width="3840" height="2053" alt="4" src="https://github.com/user-attachments/assets/8488798b-ace6-4874-b692-d76cf7d39a54" />

## Playing

| To do this | Where to go |
| --- | --- |
| Grow a yard | Collect the four resources and place buildings. Hire additional workers, discover monsters in the Locker, hatch them and upgrade them in the Academy. A Yard Planner unlocks two saved surface layouts. The surface Town Hall reaches level 10. |
| Visit, invade or repair | Open **Invasion**, filter for players or bots, then choose **Visit** or **Invade**. Load a bucket from your ready army and click a valid spot on the battlefield. Towers, traps and bunkers defend automatically. Back home, **Repair base** restores damaged completed structures instantly and for free. Champions recover separately. |
| Compete for rank and territory | **Rankings** lists every keeper. All start at zero. A ranked victory earns 25 points and a defeat costs 15. Build a Map Room to scout the World Map. Breach territories by destroying 90% of eligible building health. In the Frontier, pay the posted takeover fee to claim a breached outpost, then build, train and launch raids there. |
| Fight campaigns | **Invasion → Wild Monster Tribes** offers 21 original camps. **Brukkarg War** alternates 25 defenses with five raids, including a mirror of the player’s own yard. Select **Creature Carnage** to raid ten original yards and unlock Vorg. The home Monster Blitzkrieg control opens ten defensive waves for Slimeattikus. **Yard events → Wild Monster Invasion** opens either seven-day, 32-wave defense and its six-tier victory totem. **Hell Raisers** opens an XP hunt with nine permanent prizes. **Hell-Raisers · Moloch’s siege** offers ten home defenses and a two-Magma-Tower reward. Enter a shared UTC round from **Yard events → World event calendar** to compete in one of five scheduled campaigns. |
| Open Inferno | Pay for early entry under **Inferno** with a working level-two Town Hall, or reach Town Hall 5 and resolve all five **Inferno Emergence** defenses to open the cavern for free. Build a second yard, collect bone, coal, sulfur and magma, and hatch eight native species. Raid 34 Moloch camps, defend against eight local counterattacks or invade a player or bot cavern. Clear 13 Descent camps to transfer native troops to the surface. |
| Raise a champion | Open **Champions**. Use the Cage and Chamber to raise, feed, evolve and switch your active guardian. Monster Madness awards Korath for distinct conquered resource outposts. Weekly ranked loot earns Krallen, who can deploy alongside your guardian. |
| Improve weapons | Research ten species-specific Monster Lab abilities. The Catapult spends resources on targeted ammunition. The Chaos Lab and Chaos Factory research and manufacture Decoy, Vacuum and Jars. Armies, devices and projectiles follow the live battle clock. |
| Collect rewards and decorate | **Quests** contains 84 overworld quests, all 38 Inferno quests, five local goals and 21 achievements. Pick yard mushrooms for a chance at Shiny. **Decorations** offers 84 illustrated pieces, placement, moves and storage. **Yard events** shows seasonal pumpkins and the Trojan Horse when eligible. |
| Use the shop or Hatchery Control Center | Spend earned Shiny on land, workers, resource deliveries, faster training and production, combat bonuses, protection, bunker defenders or champion care. The HCC gives an overview of paid orders across functioning Hatcheries and can finish their queues. |
| Meet other keepers | **Neighbors** supports human friends, mystery sacks and private messages. Leave a visitor sign, enable local Radio Tower news, form an alliance or negotiate a truce. Use **Realm chat** to talk with keepers in your map edition or the Inferno. Alliance members share a private feed, and leaders can organize wars and activate Armament, Conquest and Declare War. |

The 27 conventional monster definitions include 18 surface hatchlings, eight native underground species and the Slimeattikus Mini summoned in combat. Each committed regular attacker leaves its home army. Undeployed troops stay in their yard. Every fight saves its results and damage. New human keepers receive a 30-minute initial shield.

[Regional travel](docs/REGIONS.md) · [Frontier outposts](docs/REGIONAL-OUTPOSTS.md) · [Inferno](docs/INFERNO.md) · [General Store](docs/YARD-UTILITIES.md) · [Event calendar](docs/WORLD-EVENT-CALENDAR.md) · [Monsters](docs/MONSTER-EXPANSION.md)

## The 1,000 bots

Bots have persistent account/base/army/resource/research/planning records. They make cost-checked construction, layout, upgrade, repair, training, discovery, research, garrison, and recycling choices. They invade eligible humans and other bots using the same combat engine and timed, capacity-checked Flinger buckets. Existing bots build missing Flingers with their saved resources. They also build champion facilities, raise a preferred champion, discover and hatch food species, feed and evolve, reserve meals against spending elsewhere, and wait for injuries to heal before raiding. Their decisions use the same costs and rules as player actions.

**Signed-in browsers automatically run bounded bot work every five seconds while at least one human has the game open.** Separate tabs and players share one scheduler, so opening more tabs does not multiply bot speed. Closing a tab or logging out removes its presence promptly when the leave request reaches the server; a crash, lost connection or suspended browser expires after 45 seconds without a pulse. Other online players keep the world active. The UI shows activity and recent completed work, and rankings refresh while their page is visible.

When the last human leaves, bot construction, training, research, economy, champion timers, territory jobs and bot-origin invasions pause. They resume from their saved progress without an offline catch-up burst. Human base progression and human-origin invasions retain their usual wall-clock behavior. Rank changes still require genuine settled battles: winners gain 25 points and losers lose 15. Fresh bots need time to develop armies and complete raids before ranks change.

`START-XAMPP.cmd`, `START-WORLD.cmd` and `php bin/worker.php` remain optional. They use the same presence gate, lock and five-second cadence as browser pulses; keeping a worker open does not run bots on an empty server. This server process is separate from the construction workers you hire inside the game.

Bots also build paid Map Rooms, conquer reachable neutral or enemy territory, invest in upgrades and repairs, and station their own monsters as defenders. Territory spending preserves planned champion meals and home-defense reserves. Bots also contest useful Fortification cells, improve and staff them through the same paid actions, and can target enemy connections. Advanced bots can settle player-inhabited regions and manage Frontier outposts while a human is online. Bots also pay for additional workers and a Planner, and occasionally improve real tower coverage without overwriting saved plans. See `docs/BOTS-V08.md`.

Advanced bots build Chaos facilities, research and manufacture a siege device with their own resources, and deploy it in a legal raid. Their construction and research pause with the world. Bots also construct Labs and Catapults, complete paid ability studies, train Project X, and spend their own resources on timed artillery during invasions. The scheduler processes bounded batches and holds a MySQL advisory lock to prevent duplicates. Browser pulses require a real signed-in human session and CSRF validation; anonymous visits cannot activate bots. See `docs/BOT-SIMULATION.md` for tuning and scheduling.

## Artwork, animation and audio

The two yards have distinct painted terrain. Troops, buildings, garden pieces and event objects use local HD assets. All 36 upgradeable surface types and 16 native facilities change appearance at every reachable upgrade. The separate tier images keep their completed architecture until a paid upgrade finishes. The renderer loads the textures for the current yard and battle. Inventory thumbnails and WebP terrain and combat textures reduce initial transfers. The original-resolution illustrations stay in the download.

All 27 conventional species have painted directional strides and attacks. Every species and guardian appearance also has a four-pose defeat or retreat sequence. The four ordinary guardian lines use six painted evolution sheets apiece. Each sheet includes an idle stance, a step, an attack wind-up and a strike. Krallen has its own power-dependent appearance. The 27 species and five guardians have distinct arrival and defeat calls. Combat audio accompanies firing, healing, terrain effects and settlement. The six locally composed loops include *The Hollow Below* for underground play and *Alarm at the Gate* for surface event battles. Music begins after the player enables sound.

The art guides list the complete tier ranges, shared tower exteriors, and original animation and event differences. [Building upgrade art](docs/BUILDING-TIERS.md) · [Source comparison](docs/PARITY.md)

The supplied source README labels the project GPL, while its actual LICENSE file contains the PolyForm Noncommercial License. That file is retained verbatim as `docs/UPSTREAM-LICENSE.txt`; `docs/SOURCE-NOTICES.md` records this discrepancy and attribution. No representation is made that the original game’s third-party trademarks/art are licensed by its software license.
