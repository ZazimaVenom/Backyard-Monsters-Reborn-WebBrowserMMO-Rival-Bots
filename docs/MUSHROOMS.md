# Yard mushrooms

Click a mushroom in your home yard, or open **Mushrooms** beside the worker controls. Pick a cap to give a free worker a two-second harvesting job. The patch shows the result, your Shiny balance and your lifetime forage totals. Visit a keeper or bot to see the mushrooms growing in that yard. Only the owner can send a worker or see the wallet.

A new yard, including a yard first opened after an upgrade, has up to 20 starter mushrooms in five clusters. Once the patch falls below ten, one mushroom regrows per four hours and 48 minutes until there are ten. Elapsed time counts for human yards while the player is away. Growth stops at a full patch and skips blocked ground. Mushrooms occupy yard space, and picking one frees its tile for construction or decoration. Five visible cap forms have the same chance to contain a golden find.

One in four newly spawned mushrooms is golden on average. A golden find contains 3 Shiny two thirds of the time or 8 Shiny one third of the time. Shiny stays in your local yard wallet and can buy decorations. The server sets the hidden contents when the mushroom spawns. A worker can collect them only once, including with multiple tabs open. Harvests are free and do not change league rank. The **Foraging** section of the Quest Journal tracks six original milestones.

Bot keepers pick mushrooms with spare workers during ordinary online turns. Bot regrowth and outstanding harvest work pause with the shared activity clock while the server is empty.

## Implementation and reference

The supplied Flash client defines starter clusters, five frames, the 17,280-second growth interval, ten-mushroom regrowth cap and Shiny distribution in `MUSHROOMS.as`. `BMUSHROOM.as` provides worker shaking and collection. This edition fits clusters to free space on the local yard grid, uses two seconds of real time for the shake, and assigns hidden contents server-side. The original six objectives M1–M6 and award ratios come from `QUESTS.as`. Resource awards follow the local journal's one-tenth economy scale.

The home yard JSON stores private contents, stable IDs, worker deadlines, a growth clock and the most recent harvest. Scouting exposes only mushroom appearance and coordinates. Picking and settling use the existing locked home transaction shared with building work and other wallet changes.

Run `php tests/mushrooms_integration.php` against an empty disposable `MYSQL_TEST_DATABASE` ending in `_mushrooms_test`. The test includes separate concurrent PHP processes. Standard MySQL host, port, user and password test variables apply.
