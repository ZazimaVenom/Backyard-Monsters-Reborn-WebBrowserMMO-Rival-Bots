# Asset prompts and validation — v0.7

This expansion adds eleven individually generated four-pose combat/casting sheets (44 poses) and the separately documented Fortification core. Each monster's existing static and movement/emergence artwork was visually inspected before its generation turn, then supplied as visual references. The sheets were generated sequentially, one species at a time. Each result was visually reviewed for identity, pose progression, facing and complete silhouettes. Five sheets received one targeted layout refinement each: Bandito, Bolt, Fang, Zafreeti and Brain.

All eleven final sheets are native **1254 × 1254 RGBA PNGs**, using equal **627 × 627 cells** in row-major order. Every final PNG was copied byte for byte from its generated output. No pixel editing, resizing, cropping, optimization or manual background removal was used. Read-only Pillow inspection measured alpha, bounds and hashes.

The complete catalog now contains **82 PNGs: 46 static assets and 36 four-pose sheets** (18 movement/emergence and 18 combat/casting). All 70 previous PNGs retain their original SHA-256 hashes. Total PNG bytes: **121,164,448**. Full machine-readable evidence is in `qa/assets-v07-validation.json` at the project workspace root; the runtime catalog is `public/assets/art/manifest.json` version 7.

## Facing and actions

| Asset | Native facing | Action | Refinements |
| --- | --- | --- | --- |
| `fink-attack.png` | right | sickle-arm slash | 0 |
| `bandito-attack.png` | left | heavy claw swipe | 1 |
| `bolt-attack.png` | left | neck-lunge bite | 1 |
| `eyera-attack.png` | right | explosion charge and release | 0 |
| `fang-attack.png` | left | lunging bite | 1 |
| `wormzer-attack.png` | right | emerged head-lunge bite | 0 |
| `teratorn-attack.png` | right | aerial mouth strike | 0 |
| `zafreeti-attack.png` | left | healing cast | 1 |
| `brain-attack.png` | left | grasping claw lunge | 1 |
| `crabatron-attack.png` | left | heavy pincer snap | 0 |
| `projectx-attack.png` | right | tentacle lash | 0 |

The ordinary sequence is anticipation → strike → recoil → recovery. Eye-Ra uses charge → swell → pre-burst → burst and is displayed only on an authoritative explosion; frame 3 is the terminal burst. Zafreeti uses prepare → gather → heal → recover and is displayed on healing events. Project X stays alive and intact in all four melee poses; the renderer adds separate acid-death particles only when the server confirms its researched death effect.

## Alpha and padding validation

All 82 PNGs decode. All new attack cells have distinct RGBA hashes and visually distinct poses. Every new sheet has zero alpha values above 1 within the four-pixel-wide horizontal and vertical divider strips. The minimum visible-silhouette cell margin (alpha greater than 1) is **26 px**; minimum solid-silhouette margin (alpha at least 128) is **30 px**. Native generation leaves some alpha-value-1 trace specks outside silhouettes, which are retained unchanged. The artwork has real transparency, not a painted checkerboard.

These are four poses from one consistent view for each action. This expands animation coverage without claiming full original directional, hurt, death, evolution-skin, sound or music parity.

The Fortification core is `outpostdefender.png`; its exact prompts, source paths and separate visual inspection are in [ASSET-PROMPT-OUTPOSTDEFENDER-V07.md](ASSET-PROMPT-OUTPOSTDEFENDER-V07.md). It is a static non-firing territory core with renderer-driven beacon glow.

## Exact generation prompts

Each call used the built-in image generator with `transparent_background: true`. The reference paths below are the exact local images supplied. Generated source paths identify the unmodified native outputs.

### fink-attack.png

References inspected and supplied:

- `/workspace/scratch/d551342b3e26/remake/public/assets/art/fink.png`
- `/workspace/scratch/d551342b3e26/remake/public/assets/art/fink-walk.png`

Initial native output: `generated_images/exec-d11dfd00-bb7f-4139-aed0-c25d60767afd.png`.

```text
Use case: stylized-concept. Create a polished FOUR-FRAME ATTACK sprite sheet for the EXACT Fink in reference image 1; image 2 is its existing movement/camera identity guide, not the desired action. Keep the golden segmented beetle shell, tall gold skeletal head with purple eyes and long pink tongue, four olive rear walking legs and TWO large turquoise sickle forelimbs. Same elevated three-quarter isometric camera, screen-RIGHT facing. Four genuinely new articulated slash poses in row-major order: anticipation with both sickle arms raised and head drawn back; decisive forward slash with the nearer turquoise blade extended toward right and body leaning; recoil with that blade lowered across the front and the rear legs braced; recovery with forelimbs resetting and head rising. Not walking frames or rotated duplicates. Exactly 2 rows by 2 columns of equal square cells on one square true-transparent RGBA image, no visible grid. Every complete sprite must fit inside a CENTERED 70 percent width and height box in its own cell, leaving at least 70 pixels clear transparent safety margin on ALL four cell edges, including both central dividers. Use generous space; do not enlarge the creature to fill its quadrant. Same scale, anchor, anatomy, lighting and design in all frames. Highest-quality smooth HD hand-painted monster-tamer RPG art: crisp dark contours, rich gold armor, polished cyan blades, refined purple/pink soft tissue, warm upper-left highlights. No ground, shadow, scenery, external slash effects, gore, blur, motion lines, text, labels, border or watermark. Exactly four separated reusable attack frames.
```

Final native output copied to `public/assets/art/fink-attack.png`: `generated_images/exec-d11dfd00-bb7f-4139-aed0-c25d60767afd.png`.

### bandito-attack.png

References inspected and supplied:

- `/workspace/scratch/d551342b3e26/remake/public/assets/art/bandito.png`
- `/workspace/scratch/d551342b3e26/remake/public/assets/art/bandito-walk.png`

Initial native output: `generated_images/exec-7ed70fa5-9e07-46ce-b4a1-ceb74751b183.png`.

```text
Use case: stylized-concept. Create a premium FOUR-FRAME ATTACK sprite sheet of the EXACT Bandito monster in reference image 1. Image 2 supplies existing locomotion/camera identity, NOT the action. Preserve stocky muscular lime-green speckled body, ivory belly and claws, two huge upward ivory tusks framing the face, orange eyes, purple dorsal spikes and thick curled tail. Same elevated three-quarter isometric view, native screen-LEFT facing. Four newly drawn sequential poses row-major: top-left anticipation with nearer forearm raised, weight pulled back and tusked head angled forward; top-right forceful open-clawed swiping strike toward screen-left, arm extended and torso twisting slightly; bottom-left follow-through with striking arm low-forward and head dipping while rear feet remain planted; bottom-right recovery drawing the arm back beneath the shoulder into ready stance. Must look like a clear claw attack rather than walking or copied rotations. Identical anatomy/scale/camera/upper-left light in all cells. Exactly four complete creatures on a square true-transparent RGBA canvas with invisible2x2 equal square grid. Keep each WHOLE silhouette within centered70percent of its cell width and height, leaving generous transparent safety margin at least60pixels on all four cell edges and central dividers; no cropped tusks, spikes, tail or claws. Smooth HD hand-painted monster-tamer RPG finish, lush green planes, purple accents, polished ivory tusks, crisp readable contour and refined dimensional shading matching references. No ground or shadow, dust, scenery, weapons, blur, motion lines, gore, floating effects, text, labels, numbers, grid or watermark.
```

Refinement 1, using the initial generated sheet as the visual reference:

```text
Precise padding-only correction to this existing four-pose Bandito attack sheet. Preserve ALL four distinct claw-swipe poses, same native screen-LEFT facing, exact creature identity, green spotted body, purple spikes, ivory tusks/claws, camera, shading and row-major order. Keep square genuine-transparent RGBA canvas and exactly2x2 equalcells. In each cell uniformly reduce the WHOLE creature to80percent of its current size and recenter it, leaving at least70pixels empty transparent margin on every side. Especially top-right extendedclaw currently approaches centralverticaldivider: make its full silhouette comfortably contained in its own quadrant. Do not crop, stretch, rotate, change anatomy, redesign poses, add backgrounds/shadows/effects/grid/text or alter colors. Padding and safe whole-sprite placement are the ONLY intended change.
```

Final native output copied to `public/assets/art/bandito-attack.png`: `generated_images/exec-70f2bfa6-5dd2-41d2-82d9-f660f5fc3225.png`.

### bolt-attack.png

References inspected and supplied:

- `/workspace/scratch/d551342b3e26/remake/public/assets/art/bolt.png`
- `/workspace/scratch/d551342b3e26/remake/public/assets/art/bolt-walk.png`

Initial native output: `generated_images/exec-feef03a1-f6e2-47e2-85b5-7f53f131cb1a.png`.

```text
Use case: stylized-concept. Produce a premium FOUR-FRAME ATTACK sheet for the EXACT Bolt creature from referenceimage1, with referenceimage2 as current camera/identity guidance. Preserve the rust-red armored upright insect head, two enormous glossy purple bulging eyes, ivory teeth, long red forked tongue, blue-black segmented centipede-like rear body with ivory dorsal spikes and many small red hooked legs, curled rust-red frontsegment. Native screen-LEFT elevatedthree-quarter isometric view. Row-major attackcycle: anticipation head/necktower pulls back into a tight S curve with jaws opening; strike head thrusts forward and down toward screen-left with jaws wide and frontbody uncoils slightly; followthrough jaws snap partlyclosed and neckbows low while rearbody remains braced; recovery headrises, neckcoilsback and tonguecurls toward mouth. Four CLEAR new articulatedbiteframes, not walkingposes or duplicate rotations. Sameanatomy, body scale, rootposition and warmupperleftlight throughout. True-transparent RGBA squarecanvas with exactly2x2equalcells, no visiblegrid. Keep eachentirecreature includingtongue and rearspikes within centered70percentofcell; at least70pixels cleartransparentpadding on ALL edges ofeverycell. Do not crop or let the wide lunge cross the divider. Smooth HD hand-painted monster-tamer RPG illustration matchingreferences, crispcontour, rich rust/copper armor, blue-black shine, polished purpleeyes, refinedivoryteeth. No scenery, ground, shadow, attackeffects, projectile, blur, motionlines, gore, text, label, number or watermark.
```

Refinement 1, using the initial generated sheet as the visual reference:

```text
Edit this 2x2 Bolt attack sheet ONLY to fix sprite layout and clipping risk. Keep the same four distinct neck-and-jaw attack poses, same red armored insect/centipede identity, purple eyes, ivory spikes, forked tongue, colors, camera and LEFT facing. In EACH equal square quadrant, reduce the entire creature uniformly to75percentofcurrent size and place it at cell center. At least80pixels genuine empty transparency must separate EVERY visible part from EVERY cell boundary. The top-right tongue currently crosses left into the neighboring cell: contain its WHOLE pose entirely in its own top-rightcell with generouspadding. Do not crop or cut the tongue; reduce and recenter wholepose. The bottom-left tongue and top-left horns also needclearspace. Preserveexactlyfourposeson squareRGBAtransparentcanvas, no gridlines, background, ground, shadows, effects, letters or numbers. No redesignornewframes; layoutpaddingonly.
```

Final native output copied to `public/assets/art/bolt-attack.png`: `generated_images/exec-208cb8be-1f30-4506-85b3-2745493755ba.png`.

### eyera-attack.png

References inspected and supplied:

- `/workspace/scratch/d551342b3e26/remake/public/assets/art/eyera.png`
- `/workspace/scratch/d551342b3e26/remake/public/assets/art/eyera-walk.png`

Initial native output: `generated_images/exec-c5e4f777-0a48-4517-812f-e0308c836e33.png`.

```text
Create one production game animation sprite sheet for the EXACT Eye-Ra creature in the two supplied reference images: fleshy red low squat slime body with little rounded tendril feet and small fanged mouth, enormous glossy cream white eyeball with orange amber iris and black pupil. Keep exactly its identity, anatomy, colors, detailed smooth HD hand-painted monster-tamer RPG finish and upper-left light. Native view faces RIGHT in the same elevated three-quarter camera as reference. Square image with an invisible equal 2 by 2 grid, FOUR different animation poses read top-left, top-right, bottom-left, bottom-right. Every pose including effects must fit a centered 70 percent box within its own equal square cell, with at least 80 pixels of fully transparent empty margin to every cell edge; no crossing center dividers. Same camera, lighting, body anchor and approximate scale throughout. This is an explosion charge/release sequence, NOT walking: top-left creature crouches low contracting tendrils, pupil small and gaze intent; top-right creature swells upward and tenses with a warm amber internal glow; bottom-left creature tightly compresses with braced tendrils and bright amber iris, last intact anticipation pose; bottom-right a compact stylized amber-orange magical explosion puff with white-hot center and a few red-orange curls recalling the creature silhouette, no intact creature and no gore. Final burst stays small and entirely inside the cell. Polished clear silhouettes, distinct body deformation across first three frames. Completely transparent real RGBA background, not a checkerboard. No floor, no cast ground shadows, no text, no labels, no grid lines, no border, no watermark. Preserve crisp artwork and generous empty alpha padding.
```

Final native output copied to `public/assets/art/eyera-attack.png`: `generated_images/exec-c5e4f777-0a48-4517-812f-e0308c836e33.png`.

### fang-attack.png

References inspected and supplied:

- `/workspace/scratch/d551342b3e26/remake/public/assets/art/fang.png`
- `/workspace/scratch/d551342b3e26/remake/public/assets/art/fang-walk.png`

Initial native output: `generated_images/exec-94b7103f-0afe-40c3-ad59-ab20eb21dbae.png`.

```text
Create one production game attack animation sheet for the EXACT Fang creature in the supplied references. Preserve its vivid red round head/body, cream eyes with tiny black pupils, vast fanged mouth, SIX silver segmented pointed spider legs, orange segmented scorpion tail and golden stinger, small red dorsal bumps. Same smooth HD hand-painted monster-tamer RPG finish, upper-left highlights, native LEFT-facing elevated three-quarter view. One square transparent PNG with invisible equal 2x2 cells; four progressive attack poses in row-major reading order. Put each entire complete creature in only the centered 65 percent box of its cell. At least 80 pixels clear transparent margin from all cell edges, including stinger and leg tips. Four true different attack poses, not walking: anticipation crouch with tail tightly curled upward and mouth preparing; strike as body lunges LEFT, jaws open widest and front two silver legs thrust LEFT while tail whips forward; follow-through with lowered head, closed biting jaw and tail lowering; recovery with body rising back, legs settling and tail curling to ready position. Keep all six legs and full tail visible in every cell without crossing divider or being clipped. Stable body anchor, same creature size, same camera, consistent lighting. Every point and extremity must be fully inside cell with generous alpha margin. No targets, no blood, no effects, no lines, no shadows on ground, no floor, no scenery, no text, no grid, no checkerboard. True transparent RGBA background. Highest quality crisp detailed painterly surfaces and controlled silhouette.
```

Refinement 1, using the initial generated sheet as the visual reference:

```text
Refine this exact four-pose Fang attack sprite sheet only for layout padding. Preserve every creature, its distinct attack pose, facing LEFT, anatomy, colors, high-quality painting, and transparent RGBA background. IMPORTANT each complete sprite is too large. Re-render each whole creature at 75 percent of its current size, centered in its own equal square quadrant of the same square canvas. The complete creature including every silver leg and golden stinger must sit inside the central 75 percent of its cell with at least 70 pixels empty transparent margin on all four sides. The upper-right leftmost leg must not touch or cross the center divider. All four sprites entirely separate, no overlapping quadrant boundaries. Keep true transparent background with no scenery, no shadows, no labels, no lines or grids, no new effects. Do not crop limbs. Output the corrected full 2x2 square sheet.
```

Final native output copied to `public/assets/art/fang-attack.png`: `generated_images/exec-06740b49-61f0-4c0c-9dcf-ff275d650ce0.png`.

### wormzer-attack.png

References inspected and supplied:

- `/workspace/scratch/d551342b3e26/remake/public/assets/art/wormzer.png`
- `/workspace/scratch/d551342b3e26/remake/public/assets/art/wormzer-emerge.png`

Initial native output: `generated_images/exec-b8a2b8ec-4161-41a5-b89b-a55957902d5c.png`.

```text
Paint a production-quality four-pose attack sheet for the exact Wormzer monster from these reference assets. Preserve the huge purple segmented worm body tapering to a curled tail at the LEFT, huge head pointing RIGHT, wrinkled purple fleshy facial folds, small orange angry eyes, top ivory-gold horn, enormous circular fanged mouth, three large ivory-gold lower face spikes. Smooth detailed HD hand-painted monster-tamer RPG finish matching references; upper-left light and fixed elevated three-quarter view facing RIGHT. ONE square sheet, invisible equal 2x2 cells, FOUR distinct progressive attack poses row-major. Each complete creature must occupy only the central 65 percent area of its own cell, at least 80 pixels of fully transparent padding on all four sides of every cell; no divider crossings. Each pose fully above ground: 1 pull long body back in compressed coil and partly close mouth, preparing; 2 powerful forward head lunge RIGHT with mouth fully expanded in a bite, body elongating while tail stays folded; 3 jaws snap mostly closed with interlocking golden teeth and compressed neck in biting follow-through; 4 recoil head upward and pull body back, mouth half open to recover. Clear dynamic body deformation, not four idle or movement copies. Same creature size and stable center anchor, full tail and every horn entirely inside cells. No dirt, rocks, ground, shadows on ground, background, labels, lines, motion effects, checkerboard or watermarks. Entire background true RGBA transparency. Crisp complete silhouettes, consistent anatomy in all four poses.
```

Final native output copied to `public/assets/art/wormzer-attack.png`: `generated_images/exec-b8a2b8ec-4161-41a5-b89b-a55957902d5c.png`.

### teratorn-attack.png

References inspected and supplied:

- `/workspace/scratch/d551342b3e26/remake/public/assets/art/teratorn.png`
- `/workspace/scratch/d551342b3e26/remake/public/assets/art/teratorn-fly.png`

Initial native output: `generated_images/exec-a74ba13a-1e83-4501-a16e-77a3b724b15a.png`.

```text
Create an excellent four-pose attack animation sprite sheet for the EXACT Teratorn flying monster in these reference images. Preserve green segmented reptile-insect body and curled tail, gold plated belly and golden beaklike tooth-filled snout, giant glossy black-purple eyes with gold rims, teal crest and two teal antennae, four translucent lavender-gold veined insect wings, four tiny green clawed limbs. Match smooth HD hand-painted monster-tamer RPG quality and elevated three-quarter RIGHT-facing view with upper-left lighting. Square output invisible equal 2x2 cells, each complete creature centered in its own square, with body about 40 percent of cell and total wingspan strictly within central 65 percent cell width. Very generous transparent margins on ALL sides: wings and tail must never approach cell divider. Four different attack poses in row-major order: 1 hovering coiled anticipation, wings drawn high and mouth gathering a small gold light; 2 body lunges RIGHT and head thrusts forward, wings swept back and open jaw sends a tiny compact amber puff just outside mouth; 3 head pulled back from recoil with wings low braking and mouth closing, foreclaws tucked; 4 recover upright hovering with wings halfway raised and mouth relaxed, golden light faded. More than a flap cycle: clear head thrust, body pitch and jaw action. Keep scale and body-center anchor consistent. No large detached projectiles or broad glow. Four wings fully visible in each pose, uncut silhouettes. True transparent RGBA background, no ground shadows, no scenery, no checkerboard, no lines, no grid, no text or watermarks.
```

Final native output copied to `public/assets/art/teratorn-attack.png`: `generated_images/exec-a74ba13a-1e83-4501-a16e-77a3b724b15a.png`.

### zafreeti-attack.png

References inspected and supplied:

- `/workspace/scratch/d551342b3e26/remake/public/assets/art/zafreeti.png`
- `/workspace/scratch/d551342b3e26/remake/public/assets/art/zafreeti-fly.png`

Initial native output: `generated_images/exec-66956f1b-9fd4-4b9b-800f-954118d1bfd0.png`.

```text
Create a four-pose HEALING CAST animation sprite sheet for the exact Zafreeti floating creature shown by these references. Exact identity: huge oval bronze armored shell with curved bronze dorsal spikes, brilliant cyan faceted crystal ridge down back and crystal blade-like tail, two large round cyan glowing side chambers, small purple masked face with narrow gold eyes and many flowing purple beard tentacles, six thin purple legs with cyan bracelets. Preserve anatomy and materials. Smooth premium HD hand-painted monster-tamer RPG artwork, fixed elevated three-quarter LEFT-facing view, upper-left highlights. Equal invisible 2x2 grid in one square transparent PNG. Each whole creature including tiny magical light must be centered inside its own cell and take up only central 65 percent, with at least 80 pixels empty alpha margin on every side. Four progressive cast poses, not idle floating: top-left legs and face tentacles draw inward preparing, cyan chambers dim; top-right two front legs extend gently LEFT and face tentacles fan outward, gathering a small soft mint-cyan light between them; bottom-left healing release with front legs extended LEFT, body leaning subtly toward recipient, soft small mint-cyan magical motes around extended tips and chambers brightest; bottom-right recovery with legs drawing inward, tentacles curling down, chambers returning to normal. Same scale, position anchor and lighting. Benevolent healing energy, no attack blast, no target, no beam crossing cell boundaries. Compact transparent glow with no solid background, no medical cross symbol. No terrain, ground shadows, text, labels, visible grid, checkerboard, watermarks or scenery. Real RGBA transparent background, clean edges and full visible extremities.
```

Refinement 1, using the initial generated sheet as the visual reference:

```text
Refine the exact supplied four-pose Zafreeti healing-cast sheet for spacing only. Preserve the precise creature design, LEFT facing, distinctive four poses and gentle cyan healing light. Keep square canvas and equal invisible 2x2 quadrants. Re-render every entire sprite, including ALL its glows and motes, at 72 percent of current size and center that entire visible silhouette in its own quadrant. Every visible pixel including aura must have at least 85 pixels fully transparent margin from all four edges of its own cell. All aura is small and concentrated at leg tips, with no haze near any edge. No pixels outside own quadrant. Keep all full legs, crystals, shell and tentacles, do not crop. Highest quality same smooth painted artwork. Background fully transparent RGBA, no checkerboard, no lines, text, grid, ground or shadows.
```

Final native output copied to `public/assets/art/zafreeti-attack.png`: `generated_images/exec-516775db-8630-49d0-9e42-dda3c0798ca4.png`.

### brain-attack.png

References inspected and supplied:

- `/workspace/scratch/d551342b3e26/remake/public/assets/art/brain.png`
- `/workspace/scratch/d551342b3e26/remake/public/assets/art/brain-walk.png`

Initial native output: `generated_images/exec-5abf047e-c665-4a1f-b4e6-335a0703fd4d.png`.

```text
Create one four-frame attack sprite sheet for EXACT Brain monster in the supplied reference assets. Keep giant glossy purple convoluted brain, blue-violet angular armored housing with tall swept side blades, golden bird-skull facial mask with fierce orange eyes, four green walking legs with purple claws plus two long green front grabbing pincers. Same vivid smooth HD hand-painted monster-tamer RPG finish, upper-left lighting, same elevated three-quarter camera and native LEFT-facing direction. Square canvas with invisible equal 2x2 grid. Four complete poses row-major, all consistently scaled and centered in central 65 percent of each cell with at least 80 pixels transparent margin to every cell edge including long pincers. Actual attack sequence: 1 torso lifts slightly and front pincers curl inward and upward preparing to strike; 2 torso lunges LEFT with both green pincers snapping outward toward LEFT, mask lowered aggressively; 3 front pincers close tightly inward in grabbing follow-through while rear legs brace and brain pitches forward; 4 front limbs relax to half-open ready position and body settles back, completing recovery. Clear action difference from walking, same limb count, exact identity and materials, complete uncropped feet and side blades. Do not add psychic projectiles, no spells, no glow, no targets, no blood. Transparent true RGBA background, no terrain, ground shadows, linework overlays, motion trails, text, labels, grids, checkerboard or watermarks. Keep excellent detailed painting and crisp silhouettes with wide empty margins.
```

Refinement 1, using the initial generated sheet as the visual reference:

```text
Correct spacing of this exact Brain four-pose attack sheet. Preserve each individual pose, LEFT facing, giant purple brain, gold face, blue armor and six green limbs, exact colors and polished rendering. Keep square 2x2 equal-cell layout with transparent RGBA background. Re-render ALL four entire creatures at 74 percent of current size, and center their total silhouette inside their own square cell. In particular the second pose's extended leftward claws must not approach or cross the center divider. Require at least 80 pixels empty transparency on ALL four edges of EACH cell, including every claw tip. Do not clip, delete, or truncate any limbs. No new backgrounds, effects, shadows, text, lines, grid or checkerboard. Whole complete sprites with generous clear padding and no overlap.
```

Final native output copied to `public/assets/art/brain-attack.png`: `generated_images/exec-3893caf8-1555-4e6e-80e9-e68aff1d7a12.png`.

### crabatron-attack.png

References inspected and supplied:

- `/workspace/scratch/d551342b3e26/remake/public/assets/art/crabatron.png`
- `/workspace/scratch/d551342b3e26/remake/public/assets/art/crabatron-walk.png`

Initial native output: `generated_images/exec-6fd0cbd0-8c7b-483c-b2b9-a1099a936e2c.png`.

```text
Create a premium game attack animation sheet for the EXACT Crabatron in these reference images: jagged orange-gold spiny crab shell, four small cyan face eyes and two cyan eyes on orange stalks, toothy dark red mouth, six purple segmented walking legs and two massive violet armored front pincers. Preserve its precise identity, colors, anatomy, smooth detailed HD hand-painted monster-tamer RPG rendering and upper-left light. Same fixed elevated three-quarter camera with front LEFT. One square canvas with invisible equal 2x2 cells. Four complete distinct poses in row-major order, each centered with full silhouette inside central 62 percent of its cell, leaving at least 90 pixels fully transparent padding on all sides. Make deliberate forceful pincer attack, not walk cycle: 1 rear legs brace, body rises slightly and the large near-side claw lifts open beside face ready to strike; 2 body leans LEFT and near claw swings down-forward LEFT open wide, other claw braces near chest; 3 large claw tightly snapped closed and body crouched in follow-through; 4 claw draws back half-open and body returns to ready height. Keep both eyestalks, all legs and both claws visible, full uncut limbs, same scale. Stable creature-center anchor; pincer swing must remain inside the cell. No target, no debris, no particles, no motion lines, no ground shadows, no terrain, no text, no grid, no checkerboard and no watermarks. Entire background genuine RGBA transparency. Clear readable pose progression and exquisite material highlights.
```

Final native output copied to `public/assets/art/crabatron-attack.png`: `generated_images/exec-6fd0cbd0-8c7b-483c-b2b9-a1099a936e2c.png`.

### projectx-attack.png

References inspected and supplied:

- `/workspace/scratch/d551342b3e26/remake/public/assets/art/projectx.png`
- `/workspace/scratch/d551342b3e26/remake/public/assets/art/projectx-walk.png`

Initial native output: `generated_images/exec-b6f30306-ac94-4939-a0c4-d7c03e23618a.png`.

```text
Create an exquisite four-pose MELEE attack animation sheet for the EXACT Project X monster from the supplied references. Identity must match: lime-green bumpy rounded base, six thick glossy violet segmented tentacles ending in ivory-gold hooks, tall sinewy green eyestalk with paired ivory spines, three magenta beads beneath a thorny green collar, single large cream-white eyeball with orange iris and black pupil looking RIGHT. Smooth HD hand-painted monster-tamer RPG quality and fixed elevated three-quarter RIGHT-facing camera, upper-left lighting. Square image invisible equal 2x2 cells. Four complete poses row-major. Every entire creature must occupy ONLY the central 60 percent area of its own square cell with at least 100 pixels fully transparent margin from each cell edge, including all hooked tentacle tips. Four clear progressive tentacle attack poses, not a walk: 1 front RIGHT purple tentacle coils tightly up beside body, eyestalk leans slightly back in anticipation; 2 front RIGHT tentacle whips forward RIGHT in a compact strong horizontal lash, eye follows attack and remaining five tentacles brace; 3 attacking tentacle sweeps down and curls inward after impact while eyestalk bends slightly forward; 4 tentacle draws back toward ready position, eyestalk straightens and body settles. Keep creature entirely alive and intact in ALL poses. No death burst or dissolution, no acid puddle, no beams or magic, no targets or hit effects. Same six tentacles, same scale and body-center anchor. Full visible uncut silhouette, no limb extending into another cell. Real transparent RGBA background with no ground, shadow, scenery, text, labels, lines, checkerboard or watermark.
```

Final native output copied to `public/assets/art/projectx-attack.png`: `generated_images/exec-b6f30306-ac94-4939-a0c4-d7c03e23618a.png`.
