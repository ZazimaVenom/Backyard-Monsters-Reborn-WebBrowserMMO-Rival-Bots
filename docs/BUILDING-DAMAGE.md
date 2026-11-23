# Building damage and repair

A damaged structure retains its own silhouette. Saved health selects four degrees of soot, chipped surfaces and cracks. Visible fragments accumulate at the foundation as damage increases. Critically damaged structures give off a small smoke plume. These cues appear in your yard, in another keeper’s yard and during live combat. The displayed health bar still shows the exact health value.

At zero health, a completed structure is replaced visually by a painted pile of timber, masonry or machinery. A spent trap has a smaller broken casing. Hidden traps never enter this drawing pass. Newly placed, unfinished structures retain their construction appearance. An already built structure that was destroyed during an upgrade remains a ruin with its work indicator.

**Repair base** restores eligible home buildings immediately. On a successful repair, the server returns the IDs it actually restored. The complete healthy art is visible straight away. A green-gold ring sweeps up the repaired buildings for 850 milliseconds. A single-building repair highlights only that building. An empty or rejected repair produces no repair sound or visual celebration. A fresh page load shows the saved condition directly. Visiting another yard, entering combat, or opening the planner clears lingering repair effects.

Reduced-motion mode keeps the four static wear appearances and three ruin paintings. It suppresses the animated smoke and repair sweep. The visual state has no effect on resource balances, construction deadlines, armor, trap secrecy, combat damage or champion recovery.

## Artwork

The three 1774 × 887 transparent illustrations were generated individually for this build. All use an upper-left light, an elevated isometric view and a low silhouette that remains legible beside an intact structure.

- **Timber** depicts split structural beams, ochre exposed grain, sage roof planks, broken terracotta tiles and an iron hinge.
- **Stone** depicts fractured slate and limestone blocks, a broken crenelation and a chipped roof tile.
- **Machinery** depicts a buckled teal casing, a fractured flywheel, a brass elbow, iron scraps and loose fasteners.

Four reusable wear layers composite through each intact building’s actual alpha mask. Damage patterns are stable across redraws and reloads. A bounded cache avoids repeated canvas work in a damaged yard. Original per-building, per-level destruction and reconstruction sprite sets remain separate source content.
