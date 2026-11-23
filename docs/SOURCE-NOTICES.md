# Source and asset notices

This independent, unofficial remake was developed with the user-supplied `backyard-monsters-refitted.7z` archive as a gameplay and catalog reference. Backyard Monsters is the original Kixeye game. The supplied preservation project credits the Backyard Monsters Refitted team; its server package names React1-X as author.

## Supplied license discrepancy

The archive's root `LICENSE` contains **PolyForm Noncommercial License 1.0.0**. Its README and `server/package.json` instead describe GPL-3.0 licensing. These declarations conflict. This package preserves the supplied root license verbatim as `UPSTREAM-LICENSE.txt`; it does not resolve that discrepancy or relicense the upstream material under GPL merely because the README says GPL.

Retain these notices and the supplied license with redistributed upstream-derived material. The original game's branding and the original art, audio and other game materials may involve rights separate from the preservation project's software license. This package makes no assertion that the upstream license grants rights the upstream authors do not hold.

## Reference catalog

`ORIGINAL-CATALOG.json` is extracted from these supplied source files:

- `client/scripts/YARD_PROPS.as` — building definitions, costs, upgrade statistics, prerequisites, and sprite offsets.
- `server/src/game-data/stats/monsterStats.ts` — ordinary and Inferno monster statistics.
- `server/src/game-data/stats/monsterKeys.ts` — monster identifiers and names.
- `server/public/gamestage/assets/english.json` — display-name mappings.

Combat and feature analysis used `ATTACK.as`, `DROPZONE.as`, `GLOBAL.as`, `BTOWER.as`, `com/monsters/monsters/MonsterBase.as`, and `server/src/app.routes.ts`. Paths in the reference catalog identify files in the original archive and are not necessarily files included in this remake.

## New assets and engine

The browser/PHP implementation and newly generated illustrations are remake work. Original asset files served as visual reference where documented; newly generated art should not be described as original Kixeye art, a direct restoration, or official artwork. See the asset manifest, where supplied, for the actual generated assets and generation notes.

The requested Digimon Dawn/Dusk direction is an aesthetic reference for smooth, stylized monster-game presentation. This project is not an official Digimon product and does not claim affiliation with its creators.

See `PARITY.md` for the distinction between the current playable scope and the unfinished complete-game request.
