# Hunt for Krallen

From your home yard choose **Yard events → Campaigns and weekly hunts → King of the Hill**. This opens the Hunt for Krallen panel in the Champion Hall. It shows the current round, qualifying loot, the last round’s recorded score and your companion. Each shared round closes on Monday at 00:00 UTC. Reach Town Hall 5 and complete Map Room level 2, then raid other players’ and bots’ surface home yards. The actual Twig, Pebbles, Putty and Goo transferred into your stores count toward the hunt. Rank points continue to use their ordinary win/loss rules.

| Weekly result | Companion for the following week |
| --- | --- |
| Below 250,000 loot, before five consecutive wins | The loan ends and the win streak resets |
| At least 250,000 loot | Standard Krallen, plus one win toward permanent ownership |
| At least 750,000 loot | Upgraded Krallen, plus one win |
| Five consecutive winning weeks | Permanent level-five Krallen |
| After retention | Standard Krallen on an empty week. At least 500,000 loot earns upgraded power for the following week |

Round results settle on the next server visit or eligible battle. Skipping an intervening week breaks a temporary streak. A keeper who crosses a target can see that score immediately and receives the companion at the weekly boundary. Existing saves start with no Hunt rewards. No local clock change, reinstall, job or database migration is required. World speed affects the companion's health recovery. The weekly UTC clock runs independently.

## Fighting alongside your champion

A living Champion Cage makes a healthy Krallen available. During a surface invasion choose **Deploy Krallen**, then select clear ground. Your raised champion and Krallen each have an independent deployment slot, and both can fight in the same battle. At home Krallen guards its Cage automatically. Krallen returns with its own injuries, gradually recovers and can accompany your raised champion again. Visits show a healthy companion beside its Cage. In the Inferno, both the roster and resource economy remain underground ones.

| Consecutive wins | Health | Damage per strike | Speed | Upgraded nearby ally loot bonus |
| --- | ---: | ---: | ---: | ---: |
| 1 | 7,500 | 96 | 2.2 | 20% |
| 2 | 7,800 | 102 | 2.3 | 22% |
| 3 | 8,100 | 108 | 2.4 | 24% |
| 4 | 8,700 | 120 | 2.5 | 27% |
| 5 | 9,300 | 144 | 2.6 | 30% |

Krallen prioritizes harvesters and Silos, then towers and occupied bunkers. Its own damage can double harvester loot and triple Silo loot. Upgraded Krallen also boosts nearby allies' direct hits. The battle service weights extra loot by actual damage to each resource's foundations. A defended Silo, an early retreat or full attacker storage can reduce the amount captured. Loot is debited and deposited in one settlement. Eligible bots earn the same weekly rewards from real online raids and deploy earned companions automatically.

## Recovered rules and local adaptations

The original replayable event is **Champion King of the Hill**, event 4. `client/scripts/com/monsters/replayableEvents/looting/KingOfTheHill.as` registers a seven-day duration and sends the event button directly to the Champion Cage’s Krallen tab. The recovered server’s EventCalendarConfig.ts disables scheduled broadcasts for event 4, while the separate weekly loot service implements its scoring and rewards.

The companion rules also come from the original client’s CHAMPIONCAGE.as, Krallen.as, ProximityLootBuff.as and the recovered server’s KrallenConfig.ts, krallenRound.ts, krallenLoot.ts and krallenSettlement.ts. G5 is a special additional guardian. Its five combat stat levels are set by wins, and its sprite appearance is set separately by weekly power.

The original contest requires Town Hall 6 and Map Room V2 or V3. This build uses its top Town Hall level and a working level-two or better Map Room. The source award, boost and retained boost quotas of 250 million, 750 million and 500 million have been divided by 1,000 for the local economy. The contest is available immediately on local installation. The original first-launch countdown and broadcast promotion are omitted. Solely ranked surface home fights transfer the ordinary four-resource balance. All loot from a battle is credited to its settlement week, including a fight that spans Monday. The source instead saves deltas throughout a running fight.

Health and damage use the same 15% and 12% local scaling as the other source champions, reach uses 80 source pixels per tile, and Cage recovery is shortened with the other local champion timers. The Flash client applies loot on each hit. The PHP battle engine records weighted direct hits and transfers at settlement. The local event has no third weekly reward tier: the recovered source clamps Krallen's active power at two. The separate Brukkarg defense wave can field a scripted enemy Krallen without granting a personal loan.
