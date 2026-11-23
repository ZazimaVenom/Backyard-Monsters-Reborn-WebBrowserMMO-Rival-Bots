# Building upgrade artwork

Every reachable building upgrade has a painted appearance. The 36 upgradeable surface building types use 154 post-level-one files. The 16 native Inferno facility types use another 70. The 224 files contain 208 distinct paintings because the three native tower families retain the same upgraded exteriors when deployed above ground.

The existing HD cutout supplies level one. Higher levels are individually framed transparent WebP assets under `public/assets/building-tiers/`, named `<realm>-<type>-<level>.webp`. These paintings supplement the source identity and the currently shipped local game balance.

## Inferno facilities and extended surface upgrades

| Facility | Filename type | Realm | Local maximum | Original building-art family |
| --- | --- | --- | ---: | --- |
| Surface Town Hall | `townhall` | Surface | 10 | `buildings/townhall/` |
| Under Hall | `townhall` | Inferno | 6 | `buildings/itownhall/` |
| Bone Cruncher | `twig` | Inferno | 5 | `buildings/iboneharvester/` |
| Coal Extractor | `pebble` | Inferno | 5 | `buildings/icoalproducer/` |
| Sulfur Swirler | `putty` | Inferno | 5 | `buildings/isulpherproducer/` |
| Magma Pump | `goo` | Inferno | 5 | `buildings/imagmaproducer/` |
| Resource Pod | `storage` | Inferno | 5 | `buildings/istoragesilo/` |
| Incubator | `hatchery` | Inferno | 5 | `buildings/ihatchery/` |
| Compound | `housing` | Inferno | 6 | `buildings/ihousingbunker/` |
| Inferno Flinger | `flinger` | Inferno | 5 | `buildings/flinger/` |
| Inferno Block | `wall` | Inferno | 5 | `buildings/iwalls/` |
| Sharpshooter | `sniper` | Inferno | 5 | `buildings/isnipertower/` |
| Inferno Monster Locker | `locker` | Inferno | 5 | `buildings/imonsterlab/` |
| Infernal Academy | `academy` | Inferno | 5 | `buildings/iacademy/` |
| Blast Tower | `infernocannon` | Inferno and surface | 7 | `buildings/icannontower/` |
| Quake Tower | `quaketower` | Inferno and surface | 6 | `buildings/iquaketower/` |
| Magma Tower | `magmatower` | Inferno and surface | 6 | `buildings/imagmatower/` |
| Wild Monster Baiter | `baiter` | Surface | 7 | `buildings/monsterbaiter/` |
| Chaos Lab | `siegelab` | Surface | 10 | `buildings/siegelab/` |
| Railgun | `railgun` | Surface | 8 | `buildings/railguntower/` |

## Other surface buildings

The following 29 surface families supply 107 upgrade illustrations. The nine surface Town Hall upgrades are listed above. Each entry includes every tier from level two to the local maximum shown.

| Local maximum | Facilities |
| ---: | --- |
| 5 | Hatchery, Monster Housing, Twig Snapper, Pebble Shiner, Putty Squisher, Goo Factory, Storage Silo, Sniper Tower, Cannon Tower, Tesla Tower, Fortification, Monster Academy, Monster Flinger, Monster Locker, Monster Juicer, Laser Tower, Aerial Defense Tower, Monster Bunker, Booby Trap, Champion Cage, Champion Chamber, Resource Outpost, Spurtz Cannon and Black Diamond Spurtz Cannon |
| 4 | Catapult |
| 3 | Monster Lab, Map Room, Stronghold and Outpost Defender |

## Visual interpretation

A separate painting sheet was commissioned for each building family, with additional sheets for long progressions. Each sheet used the current production illustration alongside the recovered original top/animation references from that building’s own source-art family. Source families often reuse one or a few visible milestones across many mechanical levels. The additional local appearances are new interpretations that preserve that facility’s purpose, camera angle and materials while making each local upgrade visible.

Each facility evolves through functional changes. The Inferno harvesters gain processing equipment, the Compound gains fortified dens, the Flinger gains stronger arms and winches, and the three advanced towers retain their distinct pile-driver, cannon and heated-crucible silhouettes. The higher surface Chaos Lab levels gain research machinery, the Baiter gains musk-pressure equipment, and the Railgun gains supports and capacitor arrays.

The original per-tier rules are described in [Inferno](INFERNO.md), [Expanded defenses](EXPANDED-DEFENSES.md) and [Chaos Workshop](CHAOS-WORKSHOP.md). The overall motion and fidelity limits are recorded in [the release comparison](PARITY.md).

## Operating machinery

The Twig Snapper, Pebble Shiner, Putty Squisher, Goo Factory and Hatchery each have five local surface paintings. Their Inferno counterparts have five apiece. Each of the 50 illustrations has a separately positioned moving region. The unchanged part of each building continues to use its full-resolution painting. The registered patches in `public/assets/producer-motion/` contain 2,430 frames across the ten families. The build tool regenerates them from the released paintings.

The resource producers follow the 24-frame stage clock and the idle and combat tick rules in BUILDING1–4. Surface resource motors double their rate during Production Overdrive. Full storage, construction, fortification, destruction, planning and reduced motion stop playback. The native artwork tables specify 34 frames for the surface Twig Snapper, 26 for each other surface harvester, and 47, 47, 51 and 49 frames respectively for the underground harvesters.

The Hatchery and Incubator start when a paid egg is on their own lane. Cancelling or completing the last job stops the mechanism. Their source routine in BUILDING13 advances on a different schedule. Its 30-step primary action and the native 50-step secondary action share a 150-step cycle in the Inferno illustration. Purchased hatchery overdrives accelerate the motion. The production schedule, availability and paid costs continue to come from the server.
