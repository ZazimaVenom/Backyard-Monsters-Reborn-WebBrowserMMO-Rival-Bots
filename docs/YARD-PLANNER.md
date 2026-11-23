# Yard Planner

Build one Yard Planner after reaching Town Hall level 3. It costs 2,500 twigs and
2,500 pebbles, takes 144 base seconds, and occupies a construction worker like
other buildings. Its single completed level provides two named home-layout slots.
The building must be completed and alive before saving, deleting or applying a
layout. These resource prices, health and timing are scaled for the remake;
original building ID 10 also has two default slots and a Town Hall 3 gate.

## Drafts and saved layouts

Planning edits a draft. Moving a building to the planner palette does not remove
the real building, disable its defenses, refund its cost or free its army. Cancel
returns to the actual yard. All current buildings must be placed before a draft
can be saved or applied.

Save stores a name and geometry in one of two slots. It does not move the actual
yard. Apply validates the complete arrangement and moves all its buildings in one
transaction, so two buildings can exchange positions. A name may contain up to
40 UTF-8 characters; no extra PHP extension is required for this validation.

An older saved layout may omit buildings constructed since it was saved. Loading
reconciles the saved IDs against the current yard and puts new buildings in the
draft's palette. Place every missing building before saving or applying. A saved
layout can never create an obsolete building or remove a newly built one.

Buildings with active construction, upgrading or fortification remain pinned to
their current positions. Other buildings can be rearranged around them. Planner
application never repairs buildings, finishes their paid work or changes their
level, individual armor, stored production, garrison or persistent identity.

## Concurrent play and privacy

The server checks every building ID and type, the complete inventory, the yard
boundary, footprints and separation. It uses the same placement clearance as
ordinary building movement, with a tiny floating-point tolerance at exact edges.
Overlapping layouts and incomplete drafts are rejected without writing changes.

Each draft carries a fingerprint of the yard's building inventory and geometry.
Another tab moving or adding a building invalidates the draft; passive production,
health or timers do not. A newly started job still pins its building when Apply
is checked. Reopen the planner after an inventory or geometry conflict.

Planner writes share the ordinary home-row lock and active-battle guard. An attack
starting after a successful application sees the complete new arrangement. If the
attack reserves the yard first, planner changes are blocked until it finishes.
Saved templates and worker purchase controls are owner-only data; rival scouting
continues to hide armed traps.

## Source and adaptation

The supplied source uses `com/monsters/baseplanner/BasePlanner.as` for building ID
10, two save slots and save/load/delete flows. `BaseTemplateNode.as` stores ID,
type, x and y. `BASE.as::applyTemplate` resolves all nodes before replacing old
footprints, permitting swaps. The remake retains those behaviors with server-side
ownership, collision and stale-draft validation. The source's decoration recycling,
premium additions and outpost planner behavior are not introduced by this release.

The planner currently edits the home yard. Existing strategic sites retain their
paid fixed-layout upgrades. Bots use the same complete-layout validator and
transactional apply path as players when making bounded defensive improvements.

## Verification

`tests/planner_test.php` exercises pure validation and exact non-geometry state
preservation. `tests/planner_integration.php` covers real paid construction,
persistent slots, private responses, jobs, garrisons and battle guards.
`tests/planner_concurrency.php` starts independent PHP processes and forces the
relevant home-row lock order for two drafts, construction and invasions. See
`VALIDATION.md` for the actual executed results and platform limits.
