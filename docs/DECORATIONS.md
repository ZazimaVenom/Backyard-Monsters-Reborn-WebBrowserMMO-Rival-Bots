# Yard decorations

Open **Decorations** in your home yard. Search by name or browse the collection. Choose **Twigs and pebbles** or **Shiny** and select the price on the piece you want. Click a clear half-tile position to complete the purchase. Cancelling before that click is free.

Click a placed piece to move it or return it to storage. In the **Storage** collection, select **Place stored** to put the same piece back without paying again. Visitors can inspect placed decorations. During an invasion or a Baiter drill the battlefield displays the arrangement captured at the start. Flags ripple in the wind and respect reduced-motion preferences.

The catalog contains all 84 decoration entries in the supplied client, including 23 flags, 12 species effigies, two versions of the Victory Totem Pole and six collectible Mini-Pumpkins per keeper. Each entry has an individual transparent WebP. The art index records the dimensions, digest and origin of every illustration in `public/assets/decorations/manifest.json`.

## Economy and local event adaptations

Paid pieces use the original first-tier Shiny prices. Shiny can be earned from yard mushrooms. The local material alternative costs four twigs and two pebbles for each Shiny in the original price. Defending wave one of each Wild Monster Invasion awards its own victory totem to storage. It upgrades in place at waves 10, 20, 30, 31 and 32. Unlocking D.A.V.E. in the Monster Locker awards access to the Golden D.A.V.E. Statue. These three keepsakes are free and limited to one each. The Golden Big Gulp uses its listed catalog price. The original promotional redemptions and retired Facebook events are represented by these local shop and achievement rules.

The server validates currency, position, boundaries, the complete decoration footprint and collisions against buildings, underground traps, other decorations and mushrooms under the owner’s base-row lock. Purchase receipts prevent retries from charging twice. Stored pieces, receipts, achievement eligibility and Shiny balances stay private. Decorations occupy building/planner space but never affect unit routing, deployment legality, targeting, destruction, rank, loot or repair. Existing saves default to an empty collection. Finished battle reports keep their recorded positions after the defender redecorates.

## Source and verification

`docs/ORIGINAL-CATALOG.json` supplies the original names, numeric IDs, prices, dimensions and Mini-Pumpkin quantity from `YARD_PROPS.as`. `BUILDINGOPTIONSPOPUP.as` changes the decoration recycle control to **Add to storage**. Footprints convert 50 source units to one yard tile. The two identically named source totems have separate identities and illustrations. The stylized warning posts use individually carved reliefs of their respective remake monsters.

Run `tests/decorations_integration.php` with the disposable MySQL test setup used by the database suites. It covers material and Shiny purchases, validation, collection limits, storage, visits, battle snapshots, repair and old report playback. The navigation and renderer suites cover the shared client.
