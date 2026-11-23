# Creature Carnage

Open **Invasion → Wild Monster Tribes → Creature Carnage** from your surface backyard. Scout the open yard to inspect its towers, then choose **Plan an expedition**. Scouting leaves your six-day timer untouched. Your first successful battle start begins the run.

## The hunt

Keepers with a level 2–4 Town Hall can enter. Invasions need a working Map Room, Flinger, Monster Housing and at least one hatched monster. This remake’s Map Room becomes available to build at Town Hall 3, so a new level 2 yard can scout while preparing. Your home shield and rank points are preserved. Each launch spends real troops. Owned Champions, Catapult ammunition and siege devices use the regular surface battle rules.

Load your monsters, then click clear ground in the scouted yard to launch. Destroy the Town Hall to score one point and open the next yard. Clearing half the other buildings alone awards zero. Each of the ten yards has its own layout. There are no intermediate monster prizes.

| Score | Outcome |
| --- | --- |
| 0 | The first yard, source ID 906, is open. |
| 1–9 | Each destroyed Town Hall opens the next yard, up to ID 915. |
| 10 | Vorg, an airborne support monster that heals wounded allies, is permanently unlocked. Open the Hatchery, make housing room, and spend goo to hatch it. |

A retreat or failed assault preserves damaged buildings for 24 hours from the first damaging attack. Later attacks in that window do not extend it. An undefeated yard recovers after the window. Completely cleared yards stay cleared for that run. A keeper receives each yard's resource parcel once across all runs. Excess resources wait under **Supplies awaiting storage** on the campaign route.

The event runs for six real days. A keeper who misses the deadline can try again after a one-day break if still eligible. The new run begins at yard 906. A keeper who reaches Town Hall 5 during a run can finish within that run’s deadline. A level 5 keeper outside an active run can instead purchase normal Vorg discovery in a level 2 Monster Locker. Existing players keep a previous Vorg unlock or an already-paid discovery queue.

## Source and local adaptations

The source client’s `com/monsters/replayableEvents/yardCrawl/battletoads/Battletoads.as`, `YardCrawl.as`, and the two Vorg reward classes establish replayable event ID 1, keeper eligibility, the required facilities, yard order and reward. The original server’s `config/EventCalendarConfig.ts` specifies ten points and six days in a weekly cycle. Its `services/events/replayable/eventBases.ts` determines destruction from Town Hall health and uses a 24-hour repair window. The HUD’s score derives from battles settled by the PHP server.

All ten layouts and resource amounts come from `game-data/tribes/events/creatureCarnage.ts`. The 366 source buildings are projected onto the compact 32-tile battlefield with local footprints and building-level limits. The local event begins on demand. Supplies use one twenty-fifth of the source yard stores, rounded up, issued on that yard’s first victory. Each keeper has an independent saved run. The event opponents occupy no keeper accounts, rankings or world cells.
