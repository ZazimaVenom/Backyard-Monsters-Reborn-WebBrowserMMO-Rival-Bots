# Wild Monster Baiter

Build a Monster Locker and reach Town Hall 4. Open **My backyard → Build something** and place a Wild Monster Baiter. Its first level costs 250 twigs, 250 pebbles and 150 putty, and occupies a worker for 60 seconds at normal speed. Each keeper can build one.

Select the completed building and choose **Set up defense drill**. Add monsters up to the displayed musk budget, pick an approach and choose **Call wild monsters**. After the three-second countdown, the pack enters from that side and the yard fights automatically. Use **Sound the siren** to end the drill early. Return to the building to resume an active drill or view its most recent result.

A drill copies the defenses you have when you call the wave. This includes existing damage, towers undergoing work, earned armor, network protection, researched bunker monsters, and your champion if it can defend. Moving a tower in the live yard changes the next drill. Each result shows the wild monsters defeated and the percentage of the practice yard destroyed. Practice damage, casualties and rewards stay out of your live progression. A real invasion takes priority.

| Baiter level | Required Town Hall | Required Monster Locker | Musk capacity | Approach choices |
|---:|---:|---:|---:|---:|
| 1 | 4 | 1 | 600 | 4 |
| 2 | 4 | 2 | 900 | 4 |
| 3 | 4 | 3 | 1,200 | 8 |
| 4 | 5 | 4 | 1,500 | 8 |
| 5 | 5 | 4 | 2,100 | 8 |
| 6 | 5 | 4 | 3,200 | 8 |
| 7 | 5 | 4 | 4,800 | 8 |

Musk is a budget for each call. Pick up to 48 wild monsters. The browser remembers the selection and approach for this keeper when local storage is available. Use **Clear wave** to start a new composition. The wave uses level-one attackers, so running the same composition from the same edge lets you compare layouts. If the approach has no legal entry tiles, choose another side. The animated entry marker shows the point the server selected.

| Cost per monster | Species |
|---:|---|
| 10 | Pokey, Octo-Ooze |
| 15 | Bolt |
| 20 | Fink, Ichi, Bandito |
| 30 | Fang, Brain |
| 40 | Crabatron |
| 60 | Eye-Ra |
| 70 | Project X, Wormzer, Teratorn |
| 160 | D.A.V.E. |

## Source behavior and remake choices

The supplied Flash client’s building 19 supplies the aerosol-can design and seven building levels. `MONSTERBAITER.as` and `MONSTERBAITERPOPUP.as` supply the musk queue, original C1–C14 roster, creature storage costs, direction unlock, countdown and scare-away control. The local economy scales build prices and work times and maps the upper Town Hall requirements to level 5.

The remake runs practice on a private snapshot of your home using the same PHP combat engine as ranked invasions. It stores the latest drill with the keeper and advances it in short steps as the client polls. The 48-unit limit bounds the work per request on local servers. The new illustration and four animation poses follow the source can’s silver dome, arched handle and spray nozzle.
