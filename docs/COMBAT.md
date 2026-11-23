# Invasions

An ordinary invasion lasts up to 300 seconds. A qualifying alliance war battle lasts 420 seconds and receives a 25% Flinger capacity bonus. New invasions require a completed, living Flinger. Its five levels hold 250, 850, 1,500, 2,500 or 3,500 bucket space and reload for five seconds after each launch. Each monster has its own launch weight. See `FLINGER.md` for the capacity and scatter rules. A bucket may mix species. PHP validates the whole bucket, existing trained inventory, capacity, reload time, and the clicked coordinate before consuming any monsters. Invalid buckets have no partial debit. Deployed monsters are consumed immediately; undeployed monsters remain at home. A home invasion can deploy up to 200 ordinary attackers. An eligible Cage champion and an earned Krallen each have their own deployment slot.

Existing invasions remain playable until completion with their saved deployment rules. Champions cannot be added to an already-started legacy battle. The API accepts `troops: {species: quantity}` or the legacy `type: species` form for one monster. Battle snapshots include capacity, cooldown and the next launch time in battle seconds. New fights also freeze species weights, Flinger level and drop-zone scale.

The PHP simulator advances in fixed 0.25-second steps using server time. Browser timestamps, damage claims, destroyed-building lists, and victory claims are never accepted. A battle survives refreshes and disconnected browsers. The background worker advances unattended battles; polling catches up only to actual server time.

## Monsters and defenses

Ground units use half-unit occupancy cells and A* paths, with no diagonal corner cutting. Live structures obstruct movement. Units breach walls when a sealed enclosure prevents a route to a preferred target. Hidden traps are excluded from routing, deployment exclusion, and building targeting, so those checks cannot reveal their locations.

Octo-Ooze and Ichi prefer defenses, including Bunkers. Bolt, Bandito and Brain prefer resources. Crabatron is a ground tank that prefers defenses. Fang is a ground damage dealer. Eye-ra prioritizes walls, detonates once with damage falling across its blast radius, and is consumed. An Eye-ra stationed in a Bunker instead engages enemy units and detonates on contact. Wormzer moves beneath obstacles, cannot be targeted while submerged, and emerges to attack. Teratorn flies over obstacles and attacks at range. Zafreeti flies and heals living friendly units; it cannot damage buildings or revive dead monsters. These are base abilities, not Monster Lab powers.

Units have explicit attacker and defender teams. Invaders fight nearby hostile garrisons; defenders seek invading units and never attack their own yard. Air and submerged states affect target eligibility. Sniper and Tesla towers can target ground and air. Cannon and Laser towers target ground units. Cannons deal splash damage; Tesla attacks also affect nearby eligible targets. Laser fires a single-target ground beam in this implementation.

A Bunker releases its actual saved garrison when attackers approach. Spawn positions are searched outside live structures, including adjacent buildings. Released defenders fight as individual units. Dead defenders are deducted from the saved garrison exactly once at settlement, while survivors retain their species counts. An unreleased garrison is lost if its Bunker is newly destroyed during that invasion; a later unrelated raid cannot erase survivors in an existing ruin. Surviving monsters stationed at a destroyed Bunker become usable again when it is repaired.

Booby Traps are hidden from visitors and attackers, including while under construction. Their owner can see them. A nearby ground attacker triggers one explosion; flying and submerged units do not trigger the trap. The spent trap becomes visible, persists with zero HP, and is rearmed through the normal repair action. Traps do not count toward the destruction percentage.

## Champions

Gorgo is a durable ground tank, Drull is a faster melee damage dealer, and Fomor is a ranged support champion. The selected, nonfrozen champion must have positive health and a completed living Champion Cage at invasion start. A healthy champion can invade with no ordinary troops, but the Flinger building is still required. Champion deployment is independent of bucket capacity and reload: select the champion and click a legal point in the same clear-ground deployment area. It can be deployed once. The server rejects another player's commands, invalid positions, unavailable champions, and repeat deployment without consuming inventory.

An active defending champion emerges beside its completed living Cage when an invasion starts. It fights invading units and never attacks its own buildings. A ruined or unfinished Cage does not release a champion. Gorgo and Drull cannot strike airborne enemies. Fomor is grounded at evolution levels one and two; from level three it flies and bypasses ground-only defenses.

Fomor empowers other friendly units within six logical world units. Its evolution-level buff ranges from 0.1 to 0.6: movement and attack speed multiply by `1 + 2 × buff`, while incoming damage multiplies by `1 − buff`. The effect excludes Fomor itself and enemy units; it does not heal. Buffs end on leaving range or champion defeat, cannot compound across repeated polling, and do not let Eye-ra survive self-destruction. Aura visuals use the server's `auraRange`, `auraBuff`, `buffed`, and `buffSource` fields. Ordinary units retain the existing combat timing; boosted attacks carry fractional timing so a small speed buff has a measurable effect at the quarter-second simulation rate.

Both eligible champion records are assigned to the battle at its start. Their passive recovery pauses while assigned, including an attacker held in reserve. Settlement writes exact remaining combat health, clears the assignment, and starts recovery at settlement time. An undeployed champion keeps its initial health. At zero health a champion retreats and remains owned; it can recover instead of being permanently deleted. Repeated settlement cannot change health or award another result. Each report includes deployed status and remaining health for participating champions. An available undeployed champion prevents an early army-defeated result.

Bots deploy their selected champion once at a valid edge. They can reserve owned monsters for the next feeding recipe; these monsters are not consumed by the automatic launch plan and do not keep an otherwise defeated raid waiting for the full timer. All normal buckets retain their capacity and reload rules.

At evolution six, feeding can earn three food-bonus tiers. Monster Madness unlocks Korath and its two combat powers. Weekly loot standings can award Krallen, who launches and returns with its own health alongside the Cage champion. Champion health, damage, distances and recovery timing use the local scale. [Bonus meals](CHAMPION-BONUSES.md) · [Korath](MONSTER-MADNESS.md) · [Krallen](HUNT-FOR-KRALLEN.md)

## Results and persistence

The attacker wins if the Town Hall is newly destroyed or at least 50% of the non-wall, non-trap structures that stood at invasion start are newly destroyed. An invasion ends when all eligible structures are destroyed, all deployed attackers and reserves are exhausted, the timer expires, or the attacker chooses End invasion. Ending preserves a victory already earned; ending below both thresholds is a loss. Surviving defenders never count as surviving attackers. Old ruins cannot generate a zero-damage victory.

The winner receives 25 rank points and the loser loses 15; scores may be negative. Loot is transferred rather than minted: up to 10% of each defender resource, scaled by new destruction and limited by attacker storage capacity. Building damage persists and must be repaired. If an upgrade finishes during battle, untouched buildings keep their full upgraded health; otherwise only damage actually dealt carries into the new health pool. Destroyed buildings stay destroyed. Defenders receive 120 seconds of protection afterward; a human attacked by a bot receives 30 minutes. Starting an invasion removes the attacker's shield.

Both participants' base rows are locked in a stable order. Accounts cannot start overlapping invasions or edit either involved yard during combat. Settlement writes two reports and updates resources, garrison casualties, champion injuries, wins, losses, and rankings in the same transaction. Repeated requests after completion cannot award the result or consume casualties again.

Bot raids use actual trained inventory and a saved deployment plan. Each bucket obeys the same capacity and five-second reload as human launches. Worker catch-up advances the simulation to each scheduled launch before deploying that bucket, then continues to current server time. Bots cannot instantly deploy an entire army or bypass reload by repeatedly polling.

The renderer receives unit `team`, `movement`, and `state`, along with movement snapshots and monotonic event IDs. Events include deployment, shots, hits, destruction, deaths, healing, explosions, trap activation, and emergence, and champion retreat. Internal stats, paths, untriggered trap locations, and bot deployment plans are excluded from attacker snapshots.

## Remaining original-game differences

Version 0.4 adds ten Monster Lab powers, Project X and resource-funded Catapult attacks. See `LABORATORY-COMBAT.md` for their authoritative rules, research snapshots, delayed impacts and source adaptations.

Alliance wars use seven-minute battles. A Frontier outpost can train and launch its own army. The local simulation scales distances, combat balance, Flinger capacity and several timings. The source comparison lists the remaining differences, and the performance guide shows how the ordinary monsters and champions move, strike and fall. [Source comparison](PARITY.md) · [Frontier outposts](REGIONAL-OUTPOSTS.md) · [Creature performances](CREATURE-PERFORMANCES.md)

## Validation

Run `php tests/combat_engine.php`, `php tests/combat_specials.php`, and `php tests/combat_champions.php` for deterministic simulation checks. Run `php tests/combat_integration.php`, `php tests/combat_v2_integration.php`, and `php tests/combat_champions_integration.php` with `MYSQL_TEST_HOST`, `MYSQL_TEST_PORT`, `MYSQL_TEST_DATABASE`, `MYSQL_TEST_USER`, and `MYSQL_TEST_PASSWORD` pointing to a disposable MySQL database. Integration checks create missing schema and roll back temporary participant data.
