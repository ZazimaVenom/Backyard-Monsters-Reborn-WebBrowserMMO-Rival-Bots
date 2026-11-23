# Progression and defenses in 0.2

The 0.2 content update adds a Monster Flinger, Monster Locker, Monster Juicer, Laser Tower, Monster Bunker and Booby Trap. New species are Bolt, Eye-Ra, Fang, Wormzer, Teratorn and Zafreeti. These systems are an adaptation of the supplied source for the remake's smaller world and faster local economy. The original Flash game's complete balance and content have not been reproduced.

Existing worlds keep their accounts, ranks, resources, geometry, armies, research and timers. Content normalization is idempotent and preserves extension fields. The original six species remain unlocked; the six new species start locked. New keepers and newly seeded bots start with a Flinger. Existing keepers and bots construct one through the normal paid building action. No migration inserts buildings into an existing yard.

## Training and unlocking

A completed, living Monster Locker unlocks one species at a time. Town Hall and Locker levels are checked before the cost is paid. Completion persists when the world next advances. Training and academy research both require the species to be unlocked; the original Town Hall requirements still apply. Unlock and training timers use the configured game speed.

| New species | Source key | Town Hall / Locker | Unlock goo / putty | Unlock time | Combat role |
| --- | --- | --- | --- | --- | --- |
| Bolt | C3 | 1 / 1 | 2,000 / 0 | 45 s | Fast ground resource raider |
| Eye-Ra | C5 | 2 / 2 | 6,000 / 500 | 90 s | Explosive wall attacker |
| Fang | C8 | 3 / 3 | 8,000 / 1,000 | 120 s | Fragile heavy hitter |
| Wormzer | C13 | 3 / 3 | 10,000 / 1,500 | 150 s | Burrows underneath walls |
| Teratorn | C14 | 4 / 4 | 14,000 / 2,000 | 180 s | Flying ranged attacker |
| Zafreeti | C15 | 4 / 4 | 18,000 / 3,000 | 210 s | Flying support healer |

Canceling an unfinished training job returns its recorded paid goo cost, capped by storage. Legacy 0.1 jobs use their unchanged catalog cost. Only that hatchery lane's later timers move forward; other hatcheries keep their schedules. A completed job cannot be canceled or refunded. Pending training already occupies housing space.

A completed, living Juicer recycles owned reserve monsters for 30% of their hatch cost. Garrison monsters must first be returned to housing. A full goo store rejects recycling without consuming the monsters; a partly full store caps the credit and reports any overflow. Repeated hatch-and-recycle operations cannot generate resources.

## Defensive garrisons and traps

A completed, living Bunker holds 100 monster-space units per level. Stationing monsters removes them from the reserve army and frees the corresponding housing space. Returning them checks total housing, including unfinished training jobs. Each bunker has a separate garrison. Invalid quantities, missing units, foreign building references, destroyed buildings and unfinished upgrades cannot transfer units.

Bunker defenders are actual server-controlled combat units. Surviving defenders return to their saved garrison; casualties remain lost. The combat service settles these changes once. Garrison units do not count as attacker survivors or appear in the invading army.

Armed and unfinished Booby Traps are concealed from foreign visits and listing counts. The owner can see them. A triggered trap becomes visible rubble; a free home repair restores and conceals it. Trap deployment exclusion and destruction scoring are handled by the combat engine, so hidden locations cannot be discovered by testing deployment positions.

Laser and Cannon Towers attack ground units. Sniper and Tesla Towers can attack ground and flying units. The 0.2–0.10 rules used 100 housing spaces per level. New invasions now use the original per-species weights and tier capacities described in `FLINGER.md`. Existing invasions finish under their saved rules.

## Source grounding and deliberate changes

The supplied `server/src/game-data/stats/monsterStats.ts` identifies the species and original HP, damage, movement, storage and special roles. `client/scripts/YARD_PROPS.as` defines the Flinger, Monster Locker, Juicer, Bunker, Laser Tower and Booby Trap. The Laser's source `attackType: 1` is ground-only, while Sniper and Tesla use the both-target category.

Original unlock costs and long timers are reduced for this local remake. Combat coordinates, building HP, range, monster speed and some unit costs are adapted. The Juicer uses the source's characteristic 30% refund relationship. New animation assets and effects do not reproduce the original frame-by-frame Flash animation rigs.

## API additions

All mutations remain authenticated JSON POST requests with the session's `X-CSRF-Token`. Responses keep the usual `{ok,data}` envelope. Snapshot and mutation data include `unlocked`, `unlockQueue`, and `garrison`.

| Action | Payload | Behavior |
| --- | --- | --- |
| `unlock` | `{type}` | Pay for one species unlock timer |
| `garrison` | `{id,type,quantity}` | Move owned reserves into the selected bunker |
| `ungarrison` | `{id,type,quantity}` | Return bunker units if housing allows |
| `juice` | `{type,quantity}` | Consume reserves for a capped partial goo refund |
| `cancelTrain` | `{id}` | Remove an unfinished job and refund its paid goo |
| `deploy` | `{battleId,troops:{type:quantity},x,y}` | Launch one capacity-checked bucket at a selected position |

Legacy `deploy` with one `type` remains supported. Refund operations return `{action,goo,potential,overflow}` metadata. The server validates costs, housing, quantities, ownership, progression and active battle locks; the browser never writes army totals or unlock completion directly.
