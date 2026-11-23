# Chaos Lab and Chaos Factory

At Town Hall 4, build the **Chaos Lab**, select it and choose **Manage Chaos Lab**. Complete the first study for Marilyn Monstroe, Loot-O-Tron 5000 or Candy Jars to discover that device. Each device has ten research levels. Upgrade the Lab to the target research tier before starting the next study. Lab construction levels 6–10 open at Town Hall 5.

Build the **Chaos Factory** once the Lab is operational. Select a researched device and **Build device**. The Factory holds one completed device in total. The next invasion snapshots the armed device and its level. A device remains in the Factory until launched. Nine journal rewards also supply one complete device at research levels 1, 5 and 10. Claiming one needs a working Factory with an empty production and storage slot.

## Costs and downtime

The first Lab costs 6,000 twigs and 6,000 pebbles and takes 144 base seconds. The Factory costs 15,000 of each and takes 288 base seconds. Every study and device has its own four-resource price and timer. The UI shows the exact price and game-speed-adjusted duration. One Lab job and one Factory job can run together. Building upgrades use a construction worker. Research and manufacturing take place inside the corresponding facilities.

A job starts only at a completed facility with at least half of its maximum health. A destroyed facility pauses outstanding work until repaired. Partially damaged surviving facilities continue an existing order. Finish or cancel ongoing research before upgrading the Lab building. Cancel an active order to return its documented paid resources up to the available storage for each resource. The workshop reports any overflow before cancellation. Device levels, stock, paid orders and their original cost survive reloads and upgrades. Existing keepers gain access through ordinary construction.

## Original source and remake scale

`YARD_PROPS.as` defines original building 133 (Factory, one level) and 134 (Lab, ten levels). `com/monsters/siege/SiegeLab.as`, `SiegeFactory.as`, `SiegeBuilding.as`, and `SiegeBuildingPopup.as` provide the single stored device, one order per facility, half-health access, destroyed-facility pause, research gates and cancellation refund. The device constructors `weapons/Decoy.as`, `weapons/Vacuum.as`, and `weapons/Jars.as` contain 30 research and 30 manufacturing price/time rows. The remake uses those individual rows, with price divided by 100 and time divided by 300, rounded upward. Facility health uses one fifth of the archived level health. The original Inferno Under Hall prerequisite maps to the existing overworld Town Hall for this release. The original Lab dependency for Factory construction and the individual level requirements for research are preserved. The local economy uses resource payments and gameplay timers.

Run `php tests/siege_workshop_test.php` and `node tests/siege_workshop_ui_test.mjs`. The optional `php tests/siege_workshop_integration.php` needs an empty disposable `MYSQL_TEST_DATABASE` ending in `_workshop_test` and the standard `MYSQL_TEST_HOST`, `MYSQL_TEST_PORT`, `MYSQL_TEST_USER` and `MYSQL_TEST_PASSWORD` environment values.

## Persistent bot keepers

Every bot with a qualifying Town Hall prioritizes both paid workshop buildings after its core facilities, using normal placement, construction workers and timers. Its stable specialty follows its profile. Raiders study Marilyn Monstroe, guardians study Candy Jars, collectors study Loot-O-Tron 5000, and builders vary by identity. A turn attempts at most one workshop order. It fills an empty Factory from known technology first, then invests in additional research while production is occupied. Bots use the same prices, half-health rules, full Factory slot and ten-tier technology limits as players. A higher-tier Lab still requires its normal paid upgrade.

Bots make their first tactical deployment alongside a real invading wave. Jars target a group of towers, the decoy seeks clear ground near defending positions, and the vacuum needs a living enemy Town Hall. Without a legal target a prepared device stays in its Factory. The usual online-player gate covers bot research, manufacturing and battle deployment. Empty intervals shift outstanding job deadlines by the exact paused time.

The live `php tests/bots_siege_integration.php` suite takes an empty disposable `MYSQL_TEST_DATABASE` ending in `_siegebots_test`. It verifies the mature bot building both facilities, paid research and production, and a real bot raid using its finished Candy Jars. All 1,000 install-time bot yards start without the facilities, research or stock.
