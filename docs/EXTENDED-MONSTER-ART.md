# Extended monster art and animation

Each of these twelve original species received its own newly generated full-body painting. The surface monsters use the source game’s C16, C17 and C19 portraits and a frame of C18’s original Slimeattikus Mini animation. The eight Inferno monsters use the corresponding IC1–IC8 portraits. The new illustrations retain transparent backgrounds and their full generated resolution. All face to the right or three-quarter right at rest.

| Original ID | Species | Production cutout | Pixels |
| --- | --- | --- | --- |
| C16 | Vorg | `vorg.png` | 1536 × 1024 |
| C17 | Slimeattikus | `slimeattikus.png` | 1536 × 1024 |
| C18 | Slimeattikus Mini | `slimeattikusmini.png` | 1536 × 1024 |
| C19 | Rezghul | `rezghul.png` | 1536 × 1024 |
| IC1 | Spurtz | `spurtz.png` | 1312 × 1199 |
| IC2 | Zagnoid | `zagnoid.png` | 1254 × 1254 |
| IC3 | Malphus | `malphus.png` | 1312 × 1199 |
| IC4 | Valgos | `valgos.png` | 1536 × 1024 |
| IC5 | Balthazar | `balthazar.png` | 1536 × 1024 |
| IC6 | Grokus | `grokus.png` | 1254 × 1254 |
| IC7 | Sabnox | `sabnox.png` | 1536 × 1024 |
| IC8 | King Wormzer | `kingwormzer.png` | 1312 × 1199 |

## In game

The Hatchery, reference cards, troop picker and battlefield share the same production art. Smaller and larger species retain distinct sizes in the yard. Slimeattikus Mini appears only when its parent splits. The transparent HD originals supply inventory portraits and a stable loading fallback. Each species also has a separately painted directional sheet. For live movement, Canvas builds up to eight cached deformed poses from a selected view. The wing tips lag the shoulders through upstroke and downstroke. Slimes compress and stretch, tentacles and spectral tails undulate, and the flame crown sways above a stable core. The stride deformations are procedural. The six illustrated directions, two mirrored bearings and reduced-motion behavior are described in `MONSTER-DIRECTIONS.md`.

The battle renderer uses the server’s movement, team, heading and attack events. Balthazar flies low. Burrowed monsters leave a trail below the surface. Slime separation, resurrection, Spurtz’s scorch, healing and Sabnox’s magma shot use the reported event locations and targets. A resurrected ally retains its silhouette with a desaturated finish.

## Individual generation directions

Each production image was commissioned separately with its own visual reference. The following common art direction preceded the individual character instructions.

Make one professionally illustrated HD full-body monster cutout for an isometric creature strategy game, using the supplied old game picture as character anatomy and color reference. Redraw from scratch in polished hand-painted 2.5D monster-collecting RPG style, crisp confident ink contours with precise sculpted two-to-three-tone shading, smooth gradients selectively inside forms, satin material highlights from upper left. New smooth HD cel illustration in the spirit of 2000s handheld monster RPGs, not literal pixel art. Preserve the recognizable silhouette and markings. Three-quarter isometric view from slightly above, in an alert ready-to-act neutral pose, FACE toward SCREEN RIGHT. Precisely one creature. Its ENTIRE body from tips to tail visible and centered with about 10% empty margin. True transparent alpha outside creature, NO floor or cast ground shadow, no scenic elements, decorative frame, UI or text. Do not paint a colored background. Strong silhouette and quiet internal detail that reads at 50 pixels.

### Vorg

Vorg. Preserve the original wonderful outlandish silhouette: a small gray-green multi-eyed biting face on a plump tapered spiny dark blue/teal abdomen ringed in creamy yellow tiger bands, six thin dark angular pointed spider legs and four LARGE rich-purple translucent vanes/wings sweeping broadly up to either side. Gold concentric bands and richly veined plum wings. It is a winged creepy-crawly healer, no humanoid features.

### Slimeattikus

Slimeattikus. A broad SINGLE glossy acid-lime green sludge monster, heavy asymmetrical domed body dripping cohesive goo, tiny irregular warty crown, two menacing bright orange inset eyes. TWO DISTINCT gaping, side-by-side mouths of irregular ivory needle teeth across the lower face, just as in reference. Draw rounded gelatin folds, oozing little lobes for locomotion, some amber light inside the slime. No skeleton, no legs and no arms.

### Slimeattikus Mini

Mini Slimeattikus, the tiny offspring of the original bog slime. The attached ultra-low-res frame is anatomical reference only. A single squat little mound of shiny chartreuse gelatin, barely larger than a slime droplet and no tall neck, one small dark-red mouth with off-white fangs and two tiny orange stalkless eyes; three rounded puddle-lobes along its low base, one small budding globule on top. Make the compact, simple baby form easy to distinguish from its two-mouthed warty parent.

### Rezghul

Rezghul, the floating undead monster. Preserve the angular rust red-bronze gloating sharklike head, monumental protruding grin of pointed ivory teeth, broad reddish shoulder armor, long forearms with large claws out, the small central glowing teal belly orb and wispy turquoise spectral lower body which curls to a tapered ectoplasmic tail. NO feet, no legs. Deep shadow below the heavy brow and turquoise light cast on the claws, like a shaman of a monster tribe.

### Spurtz

Spurtz, an elemental inferno fireball creature: its full body is ONE floating vermilion/orange flame, a rounded fiercely grinning ember core with pale yellow cartoon eyes and toothy ivory grin at the front, long bright-gold flames arch upwards into asymmetrical flamelike pointed crown. Face toward the right with side flames swept backward to left. HAS NO LIMBS, wings, feet, or other appendages. Choose elegant clear graphic contours, ember light confined to body.

### Zagnoid

Zagnoid. Match original distinctive mustard-gold slug: broad tapered tail curling on the ground, very long thin crooked upright neck, tiny wizened dragon-slug head with several flexible stalks ending in round red bulbs, small rusty red claw hands on two short spindly forearms. No hind legs: the lower body is one naturally coiled low slug body. Tarnished yellow ochre skin with orange folded undersides, lively predatory posture.

### Malphus

Malphus, squat insectoid raider. Broad hard leafy/sea-green chitin carapace and deep violet shoulder mantle over low predatory face, two striking long downward curved ivory tusks, many widely spread crablike walking legs, short curved front pincers, coppery reddish segmented lower abdomen revealed between the legs. Keep pose balance, low stance and exact significant colors from reference. No wings or human anatomy.

### Valgos

Valgos, a short and wide amphibious armored beast. Dominate silhouette with its enormous cavernous wide open burgundy mouth, ivory blunt tusks at upper and lower corners, and several orange bead-eyes across the blue-green face ridge. Turquoise spiny bumpy back, amber sides and belly, powerful squat claw feet planted apart, solid low salamander body. Show the creature at an engaging three-quarter angle with its open mouth clearly readable.

### Balthazar

Balthazar, a flying bat-skull monster. A thick ridged brown bone dragonlike bat skull, large cavernous screaming maw and irregular ivory teeth, two broad reddish-brown leathery bat wings with cream talons at tips, pointed bony eye ridge. No human rider; head forms most of the compact creature. Dramatic open winged silhouette with one wing forward and one trailing. Transparent margins beyond tips.

### Grokus

Grokus. A heavily built dark sea-green octopus monster with rounded head, small batlike side fins, angular brow above its violet lower face and a regal cluster of three vivid faceted ruby-red crystals rising as a crown. Low, thick curling tentacles fan along the floor in balanced arabesques, violet undersides and individual suction cups, carefully separated shapes. Smooth nacre highlights over green skin, deep navy creases. No manufactured crown, no legs.

### Sabnox

Sabnox. Long sinuous plum-purple armored serpent/worm, lifting upper torso in a sweeping curved S; its back is crested with dark scarlet finlike ridge, face with hooked tusks and two long tapered whisker-feelers, two tiny curved hook forearms. Tail tapers gracefully back to floor at left. Purple banded chest and dark glossy plates, off-white mouth detail. A large ground worm, no legs nor wings.

### King Wormzer

King Wormzer, colossal regal worm commander. Corpulent bronze-brown segmented worm abdomen resting in a thick curled tail on the ground, squat torso and a broad head in ornate gunmetal armor with one tall bright red helmet crest. Pale upturned tusks. Both tiny forearms carry hefty fantasy blast gauntlets with distinct bronze rims, out front, as in the source. Emphasize royal helmet silhouette, soft belly/metal texture contrast and old game personality, facing right. No legs.

The central art manifest records image dimensions, SHA-256 checksums and measured alpha bounds for every file.
