# Monster roster

The game contains all 27 conventional species represented in the recovered creature definitions. Eighteen are obtainable through the surface Hatchery, eight are produced in the Inferno Incubator and one appears only in combat. Champions use a separate roster.

| Source | Species | Acquisition and battlefield role |
|---|---|---|
| C16 | Vorg | Win Creature Carnage at Town Hall 2–4, spend 999 XP in the Hell Raisers store, or research Vorg at Town Hall 5 with Locker 2. Flying healer. Excludes other healers from its own healing. |
| C17 | Slimeattikus | Monster Blitzkrieg reward, Hell Raisers prize for 9,999 XP, or paid discovery at Town Hall 5 and Locker 3. A defeat produces 2, 2, 3, 3, 4 or 5 Minis by evolution. |
| C18 | Slimeattikus Mini | Temporary child of Slimeattikus. It inherits its parent’s level and fights for the same side. |
| C19 | Rezghul | Hell Raisers prize for 999 XP or paid discovery at Town Hall 5 and Locker 3. Its ranged attacks accompany a separate ability that raises nearby friendly ground troops. |
| IC1 | Spurtz | First underground species. Charges structures and leaves a cosmetic scorch mark when defeated. |
| IC2 | Zagnoid | Inferno Locker 1. Seeks defenses and deals double damage to towers. |
| IC3 | Malphus | Inferno Locker 2. Leaps over walls to raid producers and storage. |
| IC4 | Valgos | Inferno Locker 2. Burrows and deals double damage to walls. |
| IC5 | Balthazar | Inferno Locker 3. Flies low, hunts defenders and occupied bunkers, and deals triple damage to monsters. Both ground and anti-air defenses can acquire it. |
| IC6 | Grokus | Inferno Locker 3. A slow resource raider with a large health pool. |
| IC7 | Sabnox | Inferno Locker 3. Fires ranged magma, can hit airborne defenders, and deals double damage to towers. |
| IC8 | King Wormzer | Inferno Locker 4. Burrows and deals a building-only shock on the first strike against a changed target. |

Health, damage, healing, speed, housing, Flinger weights, split counts and resurrection multipliers use the recovered level tables. A short table repeats its last defined value. The combat engine converts source ranged distances at 80 pixels per yard tile. Rezghul targets a corpse within 3.75 tiles and raises eligible creatures within 0.625 tiles of that corpse. Raised troops have 75% movement and attack speed. Their health and damage multipliers increase from 100% to 150% with the caster’s level. A corpse can be raised once. Champions, flyers, healers, Rezghul, Minis and previously summoned creatures are excluded. The original bunker casualty remains a casualty. Each battle permits up to 200 temporary summons independently of manual deployment slots.

## Production and research

Each new species supports six tiers. Inferno has its own wallet, eight-species roster, incubator lanes, locker and academy. Eggs reserve Compound space immediately. Cancelling an egg refunds its paid Magma up to underground storage capacity and advances later eggs on that lane. Discovery and academy jobs also support cancellation. Accepted orders and their timestamps persist across restarts. Existing surface yards receive no discoveries or stock. Mature bots can discover, hatch, research, station and launch the new surface species through their normal paid actions.

The Flash economy uses multi-million-resource prices and multi-day jobs. The local economy divides original underground hatch charges by 12, surface hatch charges by 16, underground discovery and study costs by 200, surface discovery charges by 64, and surface study charges by 400. Surface discovery and academy work additionally cost Putty at the local progression rates. Hatch time is divided by four. Discovery and research time are divided by 600 before the configured world speed applies. The local advanced Locker recipes give continued access to event species outside the recovered promotion schedule. Malphus and Grokus prioritize filled producers, stores and core buildings. Their direct attacks reserve two units of loot per point of actual building health removed. `MonsterBase.as` sets the base loot property to 0.5 and `CreepBase.as` adds 1.5 for resource seekers. `BFOUNDATION.as`, `BRESOURCE.as` and `BSTORAGE.as` supply the hit and debit behavior.

The local economy uses the production building’s actual bin first when that building is hit. A storage or Hall hit selects a nonempty currency through a repeatable battle draw. Earned goods settle into the attacking keeper’s storage, including when retreating. Full storage leaves the unpaid amount at its origin. Player and bot vaults share the raid’s ten-percent bank limit across ordinary destruction loot and these hits. Field raids draw from the target outpost’s own stores. Moloch and ordinary tribe camps maintain a finite parcel per keeper and stage. Successful hits can extract part on an early retreat, and first-clear awards deduct amounts previously delivered. Promotion stages keep their separate event payouts. The local bank moves exactly the amount received, while the Flash storage code discounts what reaches the attacker by yard type. Animation runs on the remake’s fixed simulation clock.

The reference files are CREATURELOCKER.as, CreepBase.as, Targeting.as, the individual creep classes, DeathSplit.as, RezghulResurrectAttack.as, Zombiefy.as and AOEDamageOnAttackOncePerTarget.as. The source inventory is in docs/ORIGINAL-CATALOG.json. Tests compare every new species’ native values and replay an existing saved invasion at six timestamps against hashes from the unmodified 0.11.0 engine.
