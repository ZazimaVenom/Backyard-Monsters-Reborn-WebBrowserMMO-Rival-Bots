# Hell Raisers

Open **Hell Raisers** from the World Map toolbar or Yard events. The same route appears under **Invasions → Wild Monster Tribes**. Build or repair the surface Map Room to scout Moloch’s yards. Use your regular surface army, Flinger and combat controls. The first committed attack starts a four-day hunt for your keeper. Opening the route or inspecting a camp leaves the clock stopped.

You may attack any of 21 Moloch outposts and revisit the same target. Each begins at full health. Moloch loses the yard at 90% combined damage to the non-wall buildings, following the source Map Room 3 wild-tribe victory rule. After a battle in which you deployed a monster or attacked with siege equipment, you receive one event XP per foundation on the field, including walls. A fought defeat also earns XP. An empty retreat earns zero. Each finished battle pays once and leaves the keeper’s ranking and shield alone.

Open the Event Store on the route to spend XP. The purchase immediately unlocks its normal Hatchery, construction or champion feature. Existing rewards from other events count toward prerequisites. An unlocked monster or champion still uses the usual training or raising procedures.

| Permanent prize | Event XP | Prerequisite |
|---|---:|---|
| Rezghul | 999 | |
| Vorg | 999 | |
| Slimeattikus | 9,999 | |
| Spurtz Cannon | 9,999 | |
| Second Spurtz Cannon | 999 | First cannon |
| Black Diamond conversion | 9,999 | Second cannon |
| Korath | 999 | |
| Korath fireball ability | 9,999 | Korath |
| Korath seismic stomp ability | 999 | Korath fireball ability |

After the hunt closes, the store remains open for 24 hours. A new four-day replay becomes available after that. Each new run starts at zero score. Unspent XP, purchased unlocks and your best previous score persist. The route displays each yard’s XP before you commit troops.

## Source and local reconstruction

The attacking replay in `com/monsters/replayableEvents/attacking/hellRaisers/HellRaisers.as` identifies event 7. It requires the home build view and Map Room 3, counts instances of `BFOUNDATION` after an attack, and reports both wins and losses. `ReplayableEvent.as` and `server/src/config/EventCalendarConfig.ts` specify four days. English messages identify the targets as Moloch’s Inferno yards in the New World Map and advertise a 24-hour store closeout. The named 48-hour constant in the client handler conflicts with that player-facing copy. The local release follows the advertised 24 hours.

The supplied code uses a placeholder tribe ID, `uint("derp")`. Its event calendar entry is disabled and its server has no Hell Raisers target list, terrain placements or store settlement. The local hunt presents the source’s regular 21 Moloch wild yards (IDs 214–234 from `molochTribes.ts`) as above-ground expedition targets reached through a working local Map Room. They are separate from the underground expedition and its progression. A player-started replay, spendable server-verified XP, the empty-attack check and persistent unspent balance make this sequence playable on a local server.

The prices come from the overrides actually loaded by `EventStoreDisplayGrid.Populate`. Reward IDs and dependencies come from `EventStorePrizeData.as`. Two display-only totems have no corresponding reward ID. The Flash purchase handler returns for these, so the playable store offers the nine prizes with defined grants. The older “Hell-Raisers” event 3 advertisements describe ten defensive waves and two Magma Towers. Those messages belong to a different event and do not define event 7’s rules.

## Event 3 · Moloch’s siege

From the home yard, open **Yard events → Campaigns and weekly hunts → Hell-Raisers · Moloch’s siege**. Inspect the next attack’s species, numbers and approach directions and select **Defend wave**. During the attack, defenses, bunkers, the active champion, armor and adjacent map fortifications participate. Reloading or returning home leaves the current encounter available to resume.

Hold all reinforcing groups with less than 90% damage to score one point. Walls and fired traps are excluded from the foundation-health calculation. Each completed attack saves damage and defender casualties. Sounding the siren, losing the yard or running out of time with survivors leaves the same wave available. Between waves, **Repair Base** restores buildings immediately for free. A genuine rival home attack takes priority and settles a concurrent siege as a withdrawal. PvP rank and shield remain separate from event scoring.

The tenth successful defense unlocks a two-tower allowance in the main yard and in each buildable Frontier outpost. Construction uses the normal treasury, placement, worker, level and repair rules. In the main yard the event also provides a way to access Magma Towers before completing all five Inferno Emergence stages. The event 7 store and XP keep their own progress.

The recovered `fp_event3*` English messages supply Moloch, ten defensive waves and two main-yard Magma Towers. `MonsterInvasion.endWave` supplies the score and damage boundary. `UnlockMagmaTowerInOutposts.as` applies a quantity reward in an outpost. The original wave table and the original value assigned to the outpost reward were unavailable in both archives. This release reconstructs ten formations from the eight native Inferno species, uses the two-tower Frontier catalog limit, and allows local replay attempts until completion. Individual timings, counts and approaches are reconstruction choices.
