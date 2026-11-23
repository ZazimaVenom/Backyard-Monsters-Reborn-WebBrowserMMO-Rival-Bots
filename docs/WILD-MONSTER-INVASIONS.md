# Wild Monster Invasions

In your home yard, open **Yard events → Campaigns and weekly hunts → Wild Monster Invasions**. Choose Invasion I for surface packs and wild champions, or Invasion II for Inferno monsters. Each campaign contains thirty regular waves and two bonus waves. The board previews each incoming group, its size, level, relative direction, power and enrage time.

**Defend wave 1** opens a personal seven-day run. You can play both campaigns in the same week, one wave at a time. A working Town Hall is sufficient. Your own standing towers, traps, Bunker garrisons and available champion defend your yard. You can reload or leave and then resume the active wave. Defeat all the attackers after the final group arrives while retaining more than 10% of the eligible yard’s maximum health. A live attacker at timeout, 90% yard damage, or sounding the siren costs the wave. You can retry the same stage during the run.

Building damage, spent traps, fallen Bunker defenders and defending champion injuries carry back home. **Repair Base** restores damaged buildings and rearms traps instantly for free. Hatching replaces lost defenders, and the defending champion uses its normal recovery. Weekly standings, ordinary raid rank and player army reserves are independent of the event. A real home raid interrupts an open wave, applies the damage already dealt and takes precedence. Leaving an event open gives no protection from rivals.

## Victory totems

| Wave cleared | Permanent totem tier |
| --- | --- |
| 1 | 1 |
| 10 | 2 |
| 20 | 3 |
| 30 | 4 |
| 31, first bonus wave | 5 |
| 32, second bonus wave | 6 |

The first milestone awards an actual Victory Totem Pole to **Decorations → Storage**. Each campaign has its own source item and its own individually illustrated transparent sculpture. Place it on a free tile. Milestones upgrade the same piece, in storage or in the yard. The piece’s inspection panel gives its tier, and illuminated markers above the sculpture show it to visitors. The highest tier survives a new run. Previously owned totems retain their identity and placement.

At the end of seven days, the next launch starts a fresh run at wave one. A wave launched before the deadline can still finish. After the final bonus wave you can replay immediately. Rerunning an older wave keeps your existing higher-tier reward.

## Source and implementation

`app/wmi-waves.json` contains the two source wave tables from `SPECIALEVENT_WM1.as` and `SPECIALEVENT.as`. The first has 142 groups, including nine guardian appearances. The second has 248 groups. Thirty-two is the wave count for each event including its two bonus waves. The largest waves contain 340 attackers in Invasion I and 458 in Invasion II. The simulation reads the actual spawn entries, including groups omitted from the original preview copy.

Each wave rotates the four source bearings together once and freezes the selected approaches, using the remake’s isometric boundary on its 32-tile map. Groups retain their relative delays. The next group can arrive early when all previous attackers have fallen. The source checks reinforcements once per second. The remake uses the same cadence and advances combat on the shared quarter-second battle clock.

The event’s eight original Inferno health and damage tables come from `InvasionMonsterStats.as`. `WMATTACK.SpawnB` scales wild units by yard progress at the original thresholds from 0.4 to 0.9. Surface strains and champions use the remake’s combat units. Source laboratory levels, guardian species/evolutions and current-health fractions, native species behavior, and the original timed enrage speed and attack-delay multipliers are frozen into the wave. Colored rings identify enraged attackers. Earned totem source IDs are 121 and 131, with milestones from the special-event scripts and `BTOTEM.as`.

The supplied calendar places the two seven-day events in different months of a four-month cycle. The local release starts each clock on its keeper’s first defense. Travel distance, original camera jumps, champion stat normalization and art use the local canvas presentation. Home-row locking, request receipts and session revisions keep two tabs from starting competing waves or awarding the same result twice.
