# General Store, Hatchery Control Center and Stone Block

## General Store

Build one General Store from the home Build menu at Town Hall 1. Select its red matchbox stall and choose **Open General Store**. The wallet shows the Shiny your keeper has earned, including Shiny from yard mushrooms.

Choose a resource and a delivery size. The store sells 10% and 50% of storage capacity when there is room, plus the exact remaining space. The offer shows the delivered amount and Shiny price. An accepted order credits the keeper’s existing resources immediately.

The same shop can finish paid construction, building upgrades, fortification, Academy research, Locker discovery and Monster Lab research. Each listed job has its own current price. The final five minutes of those jobs are free. For longer jobs the price is the smaller of the remaining seconds divided by 180, rounded up, and the square root of 0.8 times the remaining seconds, rounded down. Purchases recheck the job, balance and quoted cost with the keeper row locked. A second tab or retried click cannot charge the same purchase twice. Repair remains free through the home yard’s Repair Base button.

### Store departments

| Department | Purchase | Earned Shiny | What changes |
| --- | --- | ---: | --- |
| Supplies | Resource delivery | Quoted per order | Receive a 10%, 50% or fill order. An open cavern adds bone, coal, sulfur and magma to the resource selector. |
| Supplies | Reduce a job by one or two hours | 20 / 40 | Subtract the selected time from a job with at least that much time remaining. |
| Workers | Extra worker | 250 / 500 / 1,000 / 2,000 | Permanently hire workers two through five. The completed Town Hall must support that worker. Material hiring also remains available in Workers. |
| Workers | Sharper tools | 225 | Building upgrades started during the next seven days take 20% less time. |
| Workers or Inferno | More Yardage | 50 per tier number | Add land around all four edges. Surface yards support six tiers. Caverns support five. |
| Workers or Inferno | Bulk block upgrade | Per eligible block and tier | Promote fully repaired, idle blocks together. Each block costs 3 for stone, 6 for metal, 10 for gold and 15 for black diamond, plus any skipped steps. The cavern sells the first two upgrades. |
| Resources | Improved packing skills | 50 per tier number | Each of ten permanent tiers adds 10% of base resource capacity to both banks. A cavern opened later inherits purchased packing tiers. |
| Resources | Production Overdrive | 200 | Double surface harvester production for 12 hours, up to each harvester’s own bin capacity. |
| Hatchery or Inferno | Hatchery / Incubator Overdrive | 30 / 50 / 100 | Hatch at 4×, 6× or 10× speed for one hour. Eggs already in production keep their progress. The three grades share a limit of one active grade per realm. |
| Hatchery | Locker Overdrive | 60 | Apply 4× speed to active and new surface species discoveries for four hours. |
| Hatchery or Inferno | Housing / Compound Expansion | 375 | Gain 25% reserve space for 24 hours. When it expires, stationed and queued troops stay. Free space is required for additional orders. |
| Combat or Inferno | Tower Overdrive | 600 | Towers in the selected realm deal 25% more damage for 24 hours. |
| Combat | Monster Dust | 500 | Surface monsters deal 25% more damage for 23 hours and 30 minutes. This uses the archived server duration of 84,600 seconds. The source store description gives one hour. |
| Combat | Beast Mode | 500 | Surface monsters absorb 30% of damage from armor-sensitive hits for 24 hours. |
| Combat | Hyper Speed | 500 | Surface monsters move and attack 50% faster for 24 hours. |
| Protection or Inferno | Day / Holiday / Ultimate protection | 32 / 250 / 1,100 | Shield the selected home against ranked invaders for one, seven or 28 days. Invading a rival from that realm breaks its shield. |
| Champions | Feed / evolve / heal | Quoted for the active champion | Buy a full meal while digesting, purchase remaining meals for an instant evolution, or restore missing health. Stage-six bonus meals cost double during digestion. |
| Bunkers | Station defenders | Per species and quantity | Buy defenders directly into an eligible bunker, up to its remaining space. They fight, survive, return and suffer casualties under normal garrison rules. |

### Expanded yards

More Yardage keeps the original center and all existing structures, saved layouts and decorations in place. It compounds the original 32-tile width by 10% per tier, rounding the result up to an even tile count. Surface widths are 32, 36, 40, 44, 48, 52 and 58 tiles. Underground widths stop at 52. Surface and underground purchases are independent. A rival sees the same boundaries that the defender uses for building. The Flinger, Catapult, incoming waves, ground pathfinding, worker movement and deployment previews use that battlefield’s dimensions.

Timed benefits use real elapsed seconds. Hatching resumes at normal speed for the portion of an egg that extends past the overdrive deadline. Invasions carry each side’s active combat bonuses into the battle clock, so effects can expire during combat. Bots use the online world clock, including pauses when the world is empty. Reopening the game restores purchased tiers and active deadlines from the existing save.

## Hatchery Control Center

Build the center at Town Hall 3 after completing three working level-2 Hatcheries. It costs 40,000 each of twigs, pebbles and putty. Select the dish in the yard to see the active egg and waiting orders for each hatchery. **Hatch or cancel orders** opens the regular hatchery controls. New orders go to the first available lane, and each egg still needs its normal goo and housing.

**Finish working queues** shows the number of monsters and a single Shiny price. It completes the paid orders assigned to functioning hatcheries in one action. The price uses four times the original time-price calculation over the sum of remaining work, without the General Store’s free window. Orders in disabled hatcheries stay in the ordinary queue. The standard completion code credits the units and hatch achievements.

## Stone Block

Town Hall 3 unlocks a solid stone block with 3,600 HP. It costs 20 pebbles in the local economy and has one level. It blocks ground paths and attracts wall-breaking monsters. Destroying it contributes no building destruction or territory capture credit. Free home repair restores it with the rest of the yard.

The original client records are YARD_PROPS.as buildings 12, 16 and 18, STORE.GetTimeCost, STORE.Variables and BUILDING16.as. Costs and construction times use the remake’s existing resource and time scaling. Each structure has a new HD illustration generated from its own archived sprite reference. The Store uses earned local currency. Store rules also reference the archived storeItems data, STORE.ProcessPurchases, CHAMPIONCAGE, ChampionBase and MONSTERBUNKERPOPUP. The reconstruction uses the game’s existing housing, tile, material and construction systems.

## Surface Town Hall progression

Town Hall upgrades use an available worker and the yard’s existing twigs and pebbles. Fully repair the hall before starting. It keeps its completed level and appearance while the worker is busy. At completion the new maximum HP and building allowances apply. The underground Under Hall has its own six-level progression.

| Target surface level | Twigs | Pebbles | Time at normal speed | Completed HP |
| ---: | ---: | ---: | ---: | ---: |
| 6 | 75,600 | 75,600 | 19 min 12 s | 33,921 |
| 7 | 113,400 | 113,400 | 28 min 48 s | 50,882 |
| 8 | 144,200 | 144,200 | 38 min 24 s | 67,843 |
| 9 | 186,800 | 186,800 | 57 min 36 s | 84,803 |
| 10 | 250,000 | 250,000 | 1 h 7 min 12 s | 101,764 |

Levels 1 through 5 retain the existing local prices, timers and HP. For levels 6 through 10, building 14’s original material prices are divided by 100 and its build seconds by 300. HP uses the original ratio to the archived level-five HP of 94,000, multiplied by the local level-five HP of 15,943 and rounded to the nearest whole number. Two level-four Storage Silos give a home 270,000 capacity per resource, enough for the final upgrade without purchased packing or territory bonuses.

Late halls unlock higher counts of producers, Hatcheries, Storage Silos, blocks, traps, and selected military facilities. Each allowance uses the greater of the existing local allowance and the archived count for that completed hall. For example, level 6 allows three Railguns, level 8 allows a fourth Monster Bunker, and level 9 allows a sixth Silo. The upper levels also grow the overall inventory ceiling so the unlocked structures can fit in the build roster and the Yard Planner. More Yardage provides additional physical building space. Bots use the same worker jobs, prices, completed levels, storage and quantity checks.
