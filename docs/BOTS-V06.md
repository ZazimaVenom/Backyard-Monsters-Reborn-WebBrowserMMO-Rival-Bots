# Bot fortification and champion bonus meals — 0.6

The same 1,000 persistent bot identities now purchase per-building fortification and maintain level-six champion bonuses through the ordinary player services. These decisions extend the remake's existing bot AI; they are not a port of an original playerbot system.

## Paid fortification

A guardian considers armor every third turn; the other profiles consider it every fourth turn. The other turns continue the normal construction and upgrade plan. The planner spreads the first protection tier before deepening existing armor, prioritizing the Town Hall, defensive towers and storage. Collector profiles give storage equal preference with towers, with stable building-ID tie breaking.

Only the six source-supported home structures qualify: Town Hall, Storage Silo, Sniper Tower, Cannon Tower, Laser Tower and Tesla Tower. Bots use the same eligibility, completed living Town Hall gate, price and timer as players. The planner requires a functioning Flinger, a fully repaired completed target and no competing build/upgrade/fortification operation on that building. It never treats a planned tier as earned resistance.

A turn starts at most one home construction investment: a fortification, ordinary construction or upgrade. This scheduling policy does not introduce a new global builder restriction; different buildings retain the release's existing parallel construction behavior. Repairs and the normal economy/monster decisions remain separate actions.

The mutation is `Game::mutate($id, 'fortify', ['id' => $buildingId])`. It deducts the real cost once and leaves a persistent timer. Bot code never directly writes fortification tiers, refunds, completion times, maximum health or free resources. Unfinished armor is not automatically canceled or replaced by upgrades. The existing worker heartbeat adds a `fortified` counter for successfully queued jobs.

## Level-six champion care

All three champions use `Game::championRecipe` for their next meal, so bots and players use the same actual shipped recipes: 20 Crabatron for Gorgo, 30 Fang for Drull, or 20 Brain for Fomor at level six. These preserve the previous release's MR3-derived quantities. Each eligible meal raises the earned food bonus toward tier three; meals at tier three maintain it without creating a fourth tier.

Bots respect the normal digestion timer, living Cage requirement, frozen state and active-battle lock. They train missing ingredients in bounded paid batches, counting already-owned and already-queued food once. The next meal is reserved from invasion deployment, home and territory garrisons, and recycling. Its missing goo cost remains protected from optional fortification spending and other existing optional research/site budgets.

Reserved meal troops do not count as the separate 80-housing offensive reserve required when stocking a home or territory bunker, or recycling monsters. A healthy active Gorgo or Drull can still lead a normal champion raid; Fomor requires an actual offensive escort. The existing 65% health threshold uses the champion's current earned bonus maximum health.

Fortification or feeding does not award rank points, create replacement accounts, reseed bots or move saved map homes. Rank still changes only through resolved battles.

## Verification

Run the pure planner suite without a database:

```sh
php tests/bots_fortification_planner_test.php
```

The integration suite uses the same explicit disposable-schema guard as the other bot tests: `BYM_BOTS_TEST_RESET=1`, a database name ending in `_bots_test`, and the `BYM_BOTS_TEST_*` connection variables. Never point it at an installed realm.

```sh
php tests/bots_fortification_integration_test.php
```

Set `BYM_BOTS_FORTIFICATION_LOAD_TEST=1` to include the optional prepared 1,000-bot workload. It gives every disposable fixture a completed Town Hall/Flinger, the exact first-tier price and an appropriate armor-turn counter, then executes the real bounded scheduler. The test verifies paid queued jobs, no early tiers or raids, no errors, and an unchanged hash of IDs, names, bot flags, ranks, wins and losses. This is a scheduling/economy workload; it does not measure organic progression or 1,000 simultaneous battles.

The new pure suite passed 6,195 checks across the 1,000 saved identity seeds; existing bot planner suites also passed 29,128 and 5,031 checks. The new database suite passed 11,109 assertions, plus three optional workload assertions, on both MySQL 8.0.44 and MariaDB 10.4.32. The existing bot and territory integration suites passed another 119 and 50 assertions on each engine.

The prepared 1,000-bot workload queued and paid for every fortification in 10.263 seconds on MySQL and 6.826 seconds on MariaDB. Both runs reported zero errors, premature completed tiers or raids, and unchanged identity/ranking hashes. These measurements are from the Linux validation environment; they do not establish native Windows execution or production concurrency.

See `VALIDATION.md` for the full measured environment and results, and `FORTIFICATION.md` / `CHAMPION-BONUSES.md` for the shared gameplay rules.
