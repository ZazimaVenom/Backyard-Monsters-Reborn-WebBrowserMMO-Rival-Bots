# Chaos devices in invasions

Research a device in the Chaos Lab, then finish a Factory order. An operational Factory supplies one prepared device to the next invasion. Its level is taken from your yard when the battle begins. Starting or scouting costs no device. A successful activation removes one item from the Factory and uses the invasion’s single siege slot. An unused device stays in inventory, including when you retreat. Active devices and the receipt for the activation survive a reload.

| Device | Target and effect |
|---|---|
| Marilyn Monstroe (Decoy) | Choose **Place Decoy**, then click clear ground. The lure draws nearby defending monsters and champions, including creatures released by a nearby bunker. When its fuse ends it damages defenders and nearby buildings. Ground defenders still navigate walls. Blast damage decreases with distance and respects armor and Fortification bonuses. |
| Candy Jars | Choose **Aim Jars** and click a cluster of ordinary defensive towers. Every touched tower receives a separate glass shell. Its shots strike the shell until its own fire breaks the glass. A jar over a tower with no eligible enemy remains intact. |
| Loot-O-Tron 5000 (Vacuum) | Choose **Attach Vacuum** to connect the hose to a standing keeper Town Hall. The hose pumps while it survives. Towers able to target fliers can shoot at the hose when they have no eligible troop target. The device stops when the timer ends, the hose breaks or the Hall falls. Actual transfers appear in the report. |

Your opponent sees a device once it is deployed. A click rejected for its position or target leaves your device in storage. Retreating ends any remaining device effect. Normal Flinger buckets, champions, Catapult shots, battle ranking and repair rules still apply. For a world territory or wild tribe expedition you can use the Decoy or Jars. The Vacuum needs a real local stockpile. Remake territory sites pay ongoing income into the owner’s economy, and tribal supplies are a one-time campaign reward. Neither has a bank for a hose to drain.

A bot that has prepared a device can use it on its first launched wave. Its Jars target the largest available tower group, its Decoy seeks a guarded bunker or tower, and its Vacuum attaches to a living keeper Hall. It pays from its own inventory and keeps a device when no legal target exists.

## Source correspondence

The supplied ActionScript files `com/monsters/siege/SiegeWeapons.as`, `weapons/Decoy.as`, `weapons/Jars.as`, `weapons/Vacuum.as`, `weapons/VacuumHose.as`, `BTOWER.as` and `SIEGEWEAPONPOPUP.as` define the devices’ activation types, tiers, targeting and effects. All ten original duration/range tiers are represented. The original radius in pixels maps to the remake at 80 pixels per yard tile. Decoys require 30 source pixels of clear footprint space. Buildings in 65% of the blast radius are eligible for damage, while defending creatures throughout the radius can be hit. The airborne hose attaches directly without a ground click.

The remake scales Decoy damage to 35% of the source stat, shell and hose HP to 12%, and Vacuum throughput to 1/500 of the source rate. Tower attacks use their ordinary remake shot damage to break glass. Quarter-second battle steps determine time. The Vacuum awards its earned pumping budget at settlement, distributed across available stocks and limited by the keeper's free storage. The defender loses exactly the resources delivered. Battles already active before this content update keep their original loadouts. The defense drill uses the Baiter’s independent practice rules.
