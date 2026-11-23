# Regional outposts

A Frontier region uses Map Room 2 rules. Its 256 tribe cells can become persistent player or bot yards. The workshop opens from the scouted yard after a keeper takes ownership. Existing Home Valley holdings continue to use their saved territory rules.

## Conquest

1. Use a working level 2 Map Room and a Flinger. Select a tribe or rival outpost within reach, then deploy troops on clear ground in the ordinary invasion view.
2. Destroy at least 90% of eligible maximum building health. The regional headquarters counts toward the threshold. Walls and traps are excluded. Rank points and battle reports settle when combat ends.
3. Visit the breached cell to pay the takeover fee in materials or earned Shiny. A winner has first claim for five minutes. Other eligible keepers can then pay the posted fee until the holder repairs or another keeper claims the cell.
4. Taking a tribe cell establishes a targetable, 200,000-HP Regional Outpost, source building 112. Taking a rival yard preserves the buildings and their damage. The new keeper receives 12 hours of protection, and the prior keeper loses the field army, garrison, stored production, invitations and saved plans at that cell.

The wild takeover costs 1,000 of each local resource. Adjacency to the home yard halves the fee, and the alliance Conquest power applies its 25% discount. A rival takeover uses the source logarithmic cost curve and the value of its constructed facilities. The site and affected keepers are locked together while charging, transferring and recording rewards. Repeating a successful payment cannot charge twice.

## Running a yard

- One local worker builds or upgrades production, housing, hatcheries, utilities, towers, bunkers, walls and traps. Select a facility on the canvas to move or upgrade it. The native headquarters stays fixed. Source prerequisites and building limits apply. Catalog-supported maximum levels cap source price tables.
- The home treasury funds orders. Each field factory produces its own materials into local storage. **Collect production** transfers what fits at home. **Repair outpost for free** restores finished structures, rearms spent traps and cancels an outstanding breach immediately.
- Eligible buildings and the headquarters support four paid armor tiers. Each takes worker time and reduces incoming damage once complete. Finishing all ten Hell-raisers defense waves unlocks up to two local Magma Towers per yard.
- The Hatchery uses the keeper’s surface Locker discoveries and Academy research. Independent hatchery lanes consume Goo and housing. The Hatchery Control Center can finish queued orders with earned Shiny. The Juicer returns Goo to home storage. Ordinary troops can transfer between home and field housing. Each bunker holds its own defenders.
- A working field Flinger can attack other backyards or territories within two hexes, with the active alliance range bonus. The field army, local Flinger capacity and local Catapult supply the invasion. The home treasury pays for Catapult shots. Monster research and lab abilities follow the keeper. Undeployed monsters stay at the outpost.
- The Yard Planner saves two independent layouts. Kits replace current facilities and stored production after confirmation. Move troops out before replacement. The Regular, Mega and Ultra kits preserve the three source inventories of 113, 148 and 171 pieces. Placement is fitted to the playable 32-cell field. A Shiny purchase finishes immediately. Material purchases run the individual construction timers.
- Regional bots use the online-world scheduler to claim and develop field yards. They pay ordinary costs for buildings and troops, repair, collect, station defenders and launch invasions from stocked field Flingers. Field queues and building timers held by bots respect the world activity clock.

## Balance and source mapping

The local economy scales source building and armor material amounts by 100, and construction times by 60 before applying the world's configured speed. Takeover and kit resource amounts use a 1,000 divisor. Kit Shiny prices remain 420, 800 and 1,500. Full-resource and full-Shiny payment options are implemented. Partial Shiny top-ups, social gift delivery, and original display-only offer variants are outside this implementation.

The building rows come from `OUTPOST_YARD_PROPS.as`, and kit inventories from `popup_prefab.as`. This release gives building 112 a generated intact portrait, damaged structure and destroyed ruin. The original class marks the Academy and Town Hall as blocked for construction at these sites. The keeper's home research is used directly. Neutral tribe layouts use the local combat catalog and garrison.

Run `tests/regional_outposts_integration.php` with the documented disposable `MYSQL_TEST_*` settings to exercise takeover, a rival conquest, the field economy, paid prefabs, armor, planner geometry, deployment and bot construction.
