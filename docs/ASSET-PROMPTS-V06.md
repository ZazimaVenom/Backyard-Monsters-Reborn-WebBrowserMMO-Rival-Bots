# 0.6 individual attack-art generation record

Seven new attack sheets were generated one at a time with the built-in image-generation tool. Each species was given its own prompt and its existing static and movement artwork as identity/camera references. No CLI/API fallback was used. All final PNGs were copied unchanged into `public/assets/art/`; no programmatic image editing, recoloring, cropping or background removal was used.

The seven initial generations were followed, where necessary, by four targeted padding refinements: Octo-Ooze, D.A.V.E., Gorgo and Drull. Those revisions kept the drawn actions while moving complete silhouettes safely inside their equal cells. Only the final seven sheets are shipped. Inspection and alpha/size/hash measurements were diagnostic only.

Each final image is **1,254 × 1,254 RGBA**, with four **627 × 627** cells in row-major order: **anticipation → strike → recoil → recovery**. These are separately drawn attack poses, not transformations of the existing walk sprites. The renderer can mirror the one native direction at runtime; there are no new independently drawn directional variants. D.A.V.E.'s rockets and Fomor's ranged effects remain separate runtime projectiles rather than being baked into the body sheets.

## Delivered actions

| File | Creature | Native facing | Drawn action | Minimum solid-pixel cell margin |
|---|---|---|---|---:|
| `pokey-attack.png` | Pokey | Right | Bite: coil, open-jaw lunge, snap, recover | 33 px |
| `octo-attack.png` | Octo-Ooze | Right | Two-tentacle slam: curl, thrust, follow through, retract | 90 px |
| `ichi-attack.png` | Ichi | Right | Armored headbutt: lift, ram, recoil, reset | 24 px |
| `dave-attack.png` | D.A.V.E. | Left | Mechanical jaw strike: open, lunge, compress, reset | 96 px |
| `gorgo-attack.png` | Gorgo | Right | Heavy forearm slam: raise, strike, brace, recover | 67 px |
| `drull-attack.png` | Drull | Right | Twin-head bite: draw back, lunge, snap, recover | 97 px |
| `fomor-attack.png` | Fomor | Right | Ranged casting posture: inhale, open-jaw cast, recoil, settle | 22 px |

## Validation and limits

All 70 shipped PNGs decoded fully and matched their recorded dimensions and SHA-256 hashes. The previous 63 image files are unchanged. The complete collection contains 45 static images and 25 four-pose sheets: 18 movement/emergence sheets and seven attack sheets. The seven new files total **7,872,458 bytes**; all artwork totals **107,714,439 bytes**.

Every new image has a real alpha channel spanning 0–255. Each of the 28 cells has a distinct byte hash, and visual inspection confirmed the intended body/limb/jaw articulation. Four-pixel bands around both central dividers contain zero solid pixels at alpha ≥128. All solid cell bounds leave at least 22 pixels of margin. Very faint translucent pixels can exist outside those solid bounds; this check does not claim that every nonzero-alpha pixel is 20 pixels from a boundary.

These sheets add attacks for four ordinary monsters and the three basic champions. The remaining ordinary species still use the existing procedural attack presentation. Full hurt/death sets, all viewing directions and the complete champion evolution appearance library remain unfinished. The pose art does not claim frame-for-frame Flash timing parity and does not change server damage or attack cadence.

## Exact prompts and references

### pokey-attack.png

References: `pokey.png`, `pokey-walk.png`.

Initial generation prompt:

```text
Use case: stylized-concept. Create a polished production FOUR-FRAME ATTACK sprite sheet for the EXACT Pokey creature in reference image 1. Reference image 2 establishes its existing game-camera direction and identity across frames, but this is a NEW attack action, not walking. Preserve the round vivid pink-magenta spiked body, purple cone spikes, turquoise eyes with red irises, fierce ivory-toothed mouth and two tiny clawed feet; no arms or redesign. Exactly 2 rows by 2 columns of equal square cells on one square genuine transparent RGBA canvas. Four full-body sprites, row-major action order: top-left anticipation with knees compressed, body drawn slightly back and jaws opening; top-right decisive forward biting lunge with mouth fully open and body thrust toward screen-right; bottom-left bite follow-through with jaw snapped shut and body low, spines tilted naturally with body; bottom-right recovery with body rising and feet settling back under it. Each is a distinctly DRAWN pose, not a rotated or scaled duplicate. Same elevated three-quarter isometric game view, screen-RIGHT facing, same anatomy, size and upper-left lighting in all cells. Keep body center anchored near cell center, with controlled small motion; mouth and all spikes must remain fully inside each cell. Generous 12 percent completely empty transparent padding around each full sprite, at least 30 pixels around the central horizontal and vertical dividers; do not draw a grid. Premium smooth high-definition hand-painted monster-tamer RPG art, crisp dark contour, expressive face, refined dimensional glossy highlights, rich pink/plum/teal palette matching references. No scenery, ground plane, shadow, motion lines, attack effects, external projectile, blur, text, numbers, labels, watermark or extra creatures. Exactly four separate reusable attack frames.
```

### octo-attack.png

References: `octo.png`, `octo-walk.png`.

Initial generation prompt:

```text
Use case: stylized-concept. Draw a production FOUR-FRAME ATTACK sprite sheet of the EXACT Octo-Ooze monster in reference image 1; reference image 2 is its existing locomotion/camera guide only. Preserve the squat blue-turquoise slimy body with small dark circular pores, two long flexible eyestalks ending in large orange-gold eyes with vertical black pupils, tiny ivory upper mouth teeth and soft gray-lavender tentacle feet. Exactly four full-body creatures in a 2 by 2 grid of equal square cells on a square true-transparent RGBA canvas, no visible grid. Row-major action sequence: first anticipation with two front tentacles curling up and eyestalks pulled back; second a forceful double tentacle slam forward toward screen-RIGHT, body compressed and eyes fixed on target; third follow-through with front tentacles flattened forward and rear tentacles braced while eyestalks dip; fourth recovery with tentacles curling back beneath the body and eyestalks rising. New distinctly drawn action poses, not walking, rotating or resizing duplicates. Same elevated three-quarter isometric view, native screen-RIGHT facing, same body scale and limb anatomy, consistent cell anchor and upper-left lighting. Keep all tentacle tips and both eyestalks within each own cell, generous 12 percent empty transparent margin on all sides and at least30pixels around central dividers. Smooth high-definition hand-painted monster-tamer RPG illustration, richly modeled glossy cyan/slate/lavender surfaces, clear dark contour, expressive bright eyes, same refined style as supplied art. No extra tentacles or eyes, no weapon, splash cloud, projectile, ground, shadow, scenery, blur, motion streak, numbers, words, border or watermark. Exactly four attack poses, same view throughout.
```

One targeted refinement of the generated sheet:

```text
Edit this existing 2x2 Octo-Ooze ATTACK sprite sheet only to improve transparent padding. Preserve all four distinct action poses, exact creature identity, smooth painted quality, blue/turquoise/purple/orange palette, same screen-right camera, and row-major pose order. Each of the four equal square cells needs visibly generous clear transparent space on ALL FOUR sides: reduce each entire creature proportionally to about 80 percent of its current size and recenter it within its OWN cell. Crucially the top-right creature's left tentacle currently approaches the central divider: move/scale the whole pose so that there is at least 65 pixels fully transparent space between every solid sprite part and any cell edge. Also leave at least65pixels under the bottom-left long forward tentacles and all outer edges. Do not crop, clip, rotate, stretch, change any anatomy, add poses, or move a sprite into another cell. Exactly four separate creatures on one square genuine transparent RGBA image. No grid lines, backgrounds, ground shadow, new effects, text or labels. All existing attack movement shapes must remain recognizable; padding is the only intended change.
```

### ichi-attack.png

References: `ichi.png`, `ichi-walk.png`.

Initial generation prompt:

```text
Use case: stylized-concept. Create four genuinely DRAWN attack frames for the EXACT Ichi armored monster in reference image1, using referenceimage2 only as camera/identity guidance. Preserve the muscular low quadruped, orange-gold overlapping scales, enormous ivory skull-like faceplate with three horns and paired upward tusks, reddish armored collar, orange eyes, heavy clawed front feet and row of ivory back spikes. Exactly four full creatures on a square true-transparent RGBA canvas arranged in an invisible 2x2 grid of equal cells. Row-major attack: top-left anticipation, front legs braced low, armored head pulled back and horns raised; top-right attack, horns lowered and head thrust forward toward screen-RIGHT in a strong ramming pose, front leg reaching and jaw snarling; bottom-left recoil, body compressed and head rebounding slightly upward after the headbutt, rear feet planted; bottom-right recovery, head lifted halfway to its ready stance and front paws resettling. Clearly distinct limb/head/neck shapes, not four walking poses or rotated copies. SAME elevated three-quarter isometric camera, screen-RIGHT facing, same creature size, silhouette proportions and warm upper-left lighting in every cell. Every horn/claw/tail tip must be fully visible inside its own cell: at least10percent completely transparent padding on every side, keepmaximumfullbodywidthandheight below80percentofcell. Premium smooth high-definition hand-painted monster-tamer RPG finish, crisp dark contour, rich golden scales, polished ivory planes, strong visual readability and detailed refined highlights matching the references. No ground or shadow, dust, impact clouds, energy, motion blur/lines, gore, scenery, text, labels, grid, numbers, borders or watermark. Exactly four usable sequential attack sprites.
```

### dave-attack.png

References: `dave.png`, `dave-walk.png`.

Initial generation prompt:

```text
Use case: stylized-concept. Produce a high-quality FOUR-FRAME ATTACK sprite sheet for the EXACT D.A.V.E. mechanical monster shown in referenceimage1. Referenceimage2 supplies its consistent game-camera direction. Preserve the compact armored tank-monster anatomy: riveted steel skull, red circular glowing eyes, enormous toothed steel lower jaw, tall curved black dorsal horn, steel spikes, twin caterpillar tracks, side cylinders and yellow-black hazard panels. Do NOT turn it into a humanoid or add arms. Square true-transparent RGBA image, exactly2rows×2columns equalcells, four complete sprites in row-major order. Native screen-LEFT facing in allframes, elevated three-quarter isometric camera identical to references. Attackcycle: top-left wind-up, skull pitches slightly up, enormous lowerjaw opens, side mechanisms retract and tracks brace; top-right forceful forward biting/ramming strike, steel jaw drives toward screen-left, mouth opens wide and tank body leans forward; bottom-left recoil, jaw snaps partway closed, side cylinder visibly recoils and front track suspension compresses; bottom-right recovery, skull rises into original ready alignment and mechanisms reset. Distinct newly drawn articulated mechanical poses, not slight rotations or four walking copies. Same design proportions, scale, body anchor and upper-left warm lighting throughout. No projectiles or muzzle flashes baked into sheet; combat renderer supplies them separately. Keep all horn/spike/tread tips fully inside each owncell with at least10percent clear transparentpadding on everyedge, entirecreaturemaximum80percentofcell. Polished smooth HD hand-painted monster-tamer RPG rendering, richly shaded cool gunmetal, warm controlled highlights, sharp readable silhouette, glowing red lenses matching suppliedart. No ground, cast shadow, smoke, dust, impact debris, motionlines, blur, scenery, words, numbers, grid, border or watermark. Exactlyfour attackframes, not a turnaround.
```

One targeted refinement of the generated sheet:

```text
Make one precise padding-only revision to this D.A.V.E. attack sprite sheet. Preserve the four existing distinct drawn mechanical poses, their exact row-major order, steel monster design, red eyes, articulated jaws, tracks, upper-left lighting and screen-LEFT isometric view. Keep exactly2rows and2columns of equal square cells on a square true-transparent RGBA canvas. Reduce ALL four creatures uniformly to about80percentoftheir current size and center each inside its OWN quadrant. Every horn, jaw tip, spike and tread needs at least65pixels completely transparent clear padding from EVERY cell edge. Especially the top-right open-jaw pose: its extended jaw must be well right of the central divider, fully contained in its own cell. Do not redraw into new action, rotate, stretch, crop, add anatomy or swap cells. No backgrounds, ground shadows, effects, gridlines, text or labels. This is a precise layout correction preserving high-quality artwork and attack movement, with increased transparent safety margins only.
```

### gorgo-attack.png

References: `gorgo.png`, `gorgo-walk.png`.

Initial generation prompt:

```text
Use case: stylized-concept. Draw a premium FOUR-FRAME ATTACK sprite sheet for the EXACT Gorgo champion in referenceimage1. The second image defines its current gamecamera/identity only, not the newposes. Preserve enormous blue scaled gorilla-like quadrupedal body, pale blue segmented belly, broad pale blue muzzle, ivory tusks, yellow eyes, purple forehead/dorsal spikes and thick purple claws. No redesign, armor, weapons or extra limbs. Exactly four complete creatures in a square genuine-transparent RGBA canvas, invisible 2x2equalcellgrid, row-major attack sequence. Pose1 anticipation: squatbackandbracehindfeet, lift near heavy forearm high in preparation, eye fixed ahead. Pose2 strike: powerful downward-forward hammering forearm slam toward screen-RIGHT, torso leans into strike, opposite forearm supports thebody. Pose3 recoil: striking forearm low forward with large claws planted, shoulder and head dipping from effort. Pose4 recovery: draw that arm back, shoulders rise to original ready quadrupedal stance. Make the four poses genuinely distinct in limb articulation and torso mechanics while keeping Gorgo's thick champion anatomy and head identity coherent. Same elevated three-quarter isometric view and screen-RIGHT facing throughout, same scale and body anchor, upper-left warm highlights. Every claw, foot and spike fully inside its cell. Keep the whole sprite inside a centered box at most75percent ofcellwidthandheight, leaving generous completely transparent padding onall4edges, especially between cells. Exceptional smooth HD hand-painted monster-tamer RPG finish matchingreferences: saturated cobalt and sapphire scales with cool cyan planes, restrained violet accents, crisp dark silhouette, expressive face and refined broad highlights. No ground, castshadow, dust, impact crater, shockwave, blur, motion streaks, magic effects, text, numbers, gridlines, border or watermark. One direction only; four reusable attackframes.
```

One targeted refinement of the generated sheet:

```text
Precise transparent-padding correction to the supplied Gorgo 2x2 attack sheet. Keep allfour existing attackposes and same row-major order, exact blue scaled gorilla champion identity, purpleclaws/spikes, yelloweyes, pale muzzle, camera, palette and refined painted quality. Preserve its naturally articulated arm-slam motion. In each equal square quadrant, uniformly reduce the whole creature to80percentofitscurrent size and recenter it, without cropping or stretching. Every sprite part must have at least65pixels fully transparent distance to allfourcellboundaries. The bottom-left outstretched claw currently approaches the centralverticaldivider; bring its whole pose safely within its owncell. Keepexactlyfourcreatures ontrue-transparentRGBA squarecanvas, with2columnsand2rows, no visiblegrid. No new effects, ground, shadow, text, border, number or additionalobjects. Only increase padding; do not create new poses or switchfacing.
```

### drull-attack.png

References: `drull.png`, `drull-walk.png`.

Initial generation prompt:

```text
Use case: stylized-concept. Create a beautiful FOUR-FRAME ATTACK sprite sheet for the EXACT Drull champion from referenceimage1. Referenceimage2 is its locomotion/camera identityguide, not desired newaction. Keep exactly TWO red dragon heads on separate long cream-segmented necks, gold flame-shaped headhorns and tailfins, yelloweyes, red scaled heavy hindlegs, two short clawed forearms and one long curvedtail. No wings, extraheads or redesign. True-transparent RGBA squarecanvas, four complete sprites in exactly2rows and2columns ofequalcells, no drawn grid. Row-major attacksequence: first anticipation, both necks pulledback, jaws opening slightly, front clawsraised and hindlegsbraced; second strong twin-head bitingstrike, both longnecks lunge toward screen-RIGHT, jaws spread wide revealingivoryfangs, forearms reachandtail counterbalances; third follow-through, both jaws snapclosed with heads lowforward, torsocompressedandhindlegsplanted; fourth recovery, heads liftback toward readystance, necks curvebacknaturallyandforeclaws settle. Four distinctly newlydrawn poses with expressive neck/jaw articulation, not walkingframesorrotatedcopies. Same elevatedthree-quarter isometric gamecamera, RIGHTfacing, same anatomysize/scale/lighting and consistentbodyanchorineverycell. VERY IMPORTANT generoussafemargins: entirespriteincludingtail, horns andextendedmouths mustfitwithincentered75percentofeachcellwithatleast60pixelscleartransparentpaddingonallfouredges. HighestqualitysmoothHD handpainted monster-tamer RPGart matchingreferences, richcrimsonscales, honeygoldhorns, warmivoryundersides, crispdarkcontour, refinedhighlightsfromupperleft. No flamesorbreath, externalprojectiles, attackeffects, ground, shadow, scenery, blur, speedlines, text, labels, numbers, borderorwatermark. Exactlyfour full-body coherentattackposes.
```

One targeted refinement of the generated sheet:

```text
Correct only the cell spacing of this four-frame Drull attack sprite sheet. The top-right pose's left foot overlaps the central vertical cell divider, which must be fixed. Keep all four existing drawn attack poses and exact two-headed red/gold dragon anatomy, same camera, right-facing direction, rich painted style and row-major sequence. Maintain exactly a 2 by 2 grid of equal square cells. Within EACH of the four cells, uniformly shrink its entire creature to 75 percent of the present size and center the complete silhouette. Leave at least 70 pixels of COMPLETELY EMPTY transparent padding between every horn, tail, foot, jaw and ALL FOUR boundaries of its cell. No pixel or body part may touch or cross either central divider. Do not crop or clip anything: the full creature in each pose must fit inside its own separate quadrant. Preserve genuine transparent RGBA; no visible grid, no ground, shadow, effect, text or new objects. Padding is the only change; do not swap poses, change heads, limbs, expressions or colors.
```

### fomor-attack.png

References: `fomor.png`, `fomor-fly.png`.

Initial generation prompt:

```text
Use case: stylized-concept. Create a production FOUR-FRAME ATTACK sprite sheet of the EXACT Fomor champion from reference image1. Referenceimage2 supplies existing flight/camera identity. Preserve squat powerful purple scaled dragon-like body, broad tusked toad-like face, yellow eyes, lilac bellyplates, ivoryclaws, purplebackspines, two broad violet membranous batwings. No redesign, extra wings/legs, longneck ornewdragonhead. True-transparentRGBA squarecanvas with exactly2rowsand2columnsofequalsquarecells, four full-body sprites in row-major casting action: top-left anticipation, chest draws in, throat expands subtly, jaw begins opening and wings cup upward; top-right strike, head thrusts a little toward screen-RIGHT, mouth wide in a focused spitting/breath-casting posture, wing shoulders push down and foreclaws reach forward; bottom-left recoil, head tips back slightly, mouth partly closes, wings sweep back and legs tuck; bottom-right recovery, throat relaxes, jaw closes and wings return halfway toward readyhover. Four newly drawn distinct body/jaw/wing poses, not a repeated flightloop. Same slightly elevated three-quarter gamecamera, native screen-RIGHT facing, consistent head/body size and central anchor in eachcell, upper-leftwarmhighlights. Leave room for runtimeprojectile: NO giantflame, beam, magiccloud orprojectile bakedintoimage. EXTREMELY IMPORTANT CELL PADDING: makeeachentirecreatureincludingallwingtipscompact, maximum65percentofitscellwidth/height, centeredwithbroadfullytransparentpaddingofatleast90pixelsoneveryedge. No parttouchescentraldividersoroutercanvas. HighqualitysmoothHDhand-paintedmonster-tamerRPGqualitymatchingreference, luminousplum/violetscales, softrosewingmembranes, crispdarkcontoursandcarefuldimensionalhighlights. No groundshadow, scenery, dust, speedlines, blur, text, frame labels, numbers, visiblegrid, borderorwatermark. Exactlyfour individual coherentattackframes.
```

