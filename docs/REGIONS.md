# Regional Map Rooms

Open **World map → Regions & relocation** to explore Home Valley, the Frontier or the Highlands. You can scout a destination before your Map Room is ready to move. In the regional dialog, name the current map position to bookmark it. **View** returns to that region and cell. Each keeper can save 24 positions.

| Destination | Required living Map Room to move | What you find |
| --- | ---: | --- |
| Home Valley | 2 | The original 64 × 64 local world, with existing keeper homes, holdings and forts. |
| Frontier | 2 | 256 native tribe outposts per new region. The owner builds and defends a field yard. |
| Highlands | 3 | 48 resource outposts, 16 strongholds and neutral forts. A settling keeper receives six adjacent Outpost Defender sites. |

The original map-room services use larger worlds. This edition uses 64 × 64 regions with their own local coordinates. A new numbered Frontier becomes available at 2,500 homes in its predecessor. A new Highlands region becomes available at 32. If Home Valley fills, registration assigns a free Frontier cell. The fresh installer seeds the 1,000 bot homes in Home Valley with zero rank points.

## Moving a backyard

Use **Move here** or **Find new sector** to review a free relocation. Your home retains its marker ID, buildings, monsters, resource balances, construction, protection and rank. Finish active invasions first. Changing worlds or accepting an outpost invitation requires leaving your alliance. A random relocation from the Frontier also requires leaving any alliance and abandoning your outposts.

In the Highlands the allocator chooses a sector at least three cells from the boundary and seven hexes from the nearest home. Its six neighboring cells become real Fortification sites, granting 75% damage reduction to home buildings while you own all six. Rivals can invade these sites. A move within the region preserves your conquests, replaces your home defenders and leaves any defender captured by someone else under their ownership. Moving across regions releases your territories and their stationed troops. Your confirmation shows the number of holdings affected.

**Relocate near a friend** lists accepted friends who live in the Highlands or Home Valley. Highlands landings are between seven and 28 hexes from the friend. Home Valley uses its original dense layout, and the closest available free cell within 28 hexes is offered. If the region cannot place the home safely, your existing position and treasury stay intact.

## Moving onto a Frontier outpost

| Action | Cost in each of twigs, pebbles, putty and goo | Alternative Shiny cost |
| --- | ---: | ---: |
| Move onto your own Frontier outpost | 30,000 | 1,500 |
| Accept a friend's outpost invitation | 10,000 | 1,200 |

Choose one payment method. Prices in materials are one-thousandth of the source client’s values to fit the local economy. The destination outpost is consumed and the main backyard occupies its exact hex. Other holdings survive a move onto your own outpost within the same region. Accepting a friend's invitation releases your existing holdings, including those in that region. A successful Frontier arrival starts a 24-hour relocation cooldown.

Select your Frontier outpost and an accepted friend, then **Offer this outpost**. The owner can withdraw an unanswered invitation. The recipient reviews the location and price in **Outpost invitations**, and can decline for free. Ownership and friendship are checked again when they accept.

## Persistent rivals

While a player is online, eligible bots without an alliance or territorial holdings can settle in a player-inhabited region using the same Map Room requirements and allocation rules. Up to six bots per human home, capped at 24 per region, may join by this route. A bot cannot settle in an empty destination solely because someone browsed it. The existing online scheduler controls subsequent home and territory invasions. All regional keepers remain on the same rankings.

## Source references

The relevant reference files are `controllers/maproom/v2/migrateBase.ts`, `services/maproom/v2/migrateInvite.ts`, `controllers/maproom/v2/saveBookmarks.ts`, `services/maproom/v3/findFreeSector.ts` and `services/maproom/v3/joinNewWorldMap.ts` under the supplied server source, and `PopupRelocateMe.as` in the supplied Flash client. Regional density, scaling and bot settlement parameters are tuned for this local server.
