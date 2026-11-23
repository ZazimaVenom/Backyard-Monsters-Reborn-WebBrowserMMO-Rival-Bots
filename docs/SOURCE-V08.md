# Source audit: Yard Planner and construction workers

This release uses the supplied `backyard-monsters-refitted` ActionScript client
as its gameplay reference. Paths below are relative to that archive. The remake
runs authoritative progression on PHP; it does not execute the Flash client.
SQL schema stays at 2 and combat snapshots remain format 7.

## Yard Planner

`client/scripts/YARD_PROPS.as:1064–1118` defines building **10**, the Yard Planner.
It is a single-level special building, limited to one per home, requiring Town
Hall level 3. Its original price is 250,000 twigs and 250,000 pebbles, its build
time is 43,200 seconds, and it has 16,000 HP. The remake keeps the identity,
one-building limit and Town Hall gate; its 2,500/2,500 resource price, 144-second
base duration, 3,200 HP and 2.6-tile footprint are explicit economy/scale
adaptations. No Planner is inserted into an existing yard during an upgrade.

`com/monsters/baseplanner/BasePlanner.as:16–24` defines **two** default layout
slots. Its `setup()` starts from the current yard and disables template saving
for outposts. `BasePlannerService.as` sends named templates to `savetemplate`,
loads `gettemplates`, and requests `deletetemplate`. The supplied TypeScript
controllers in `server/src/controllers/yardplanner/` retain those templates per
keeper. The remake likewise keeps two private named home layouts; it does not
add territory construction or outpost-template storage.

`BaseTemplateNode.as` exports a node's ID, building type and coordinates.
`BASE.as:5119–5150` builds a template from current building identities, excludes
mushroom type 7, and excludes enemy objects from template saves.
`PlannerTemplate.as:59–177` reconciles templates against the current yard:
existing IDs become placed nodes, new buildings enter the temporary inventory,
and missing buildings do not create replacements. The original also handles
stored decorations, which this milestone does not implement.

`BasePlannerPopup.as:132–143` prevents Apply while a non-decoration/non-misc
building remains in the planner inventory. This inventory is a draft-editing
surface, not permanent storage for real towers or production buildings.
The remake's draft palette follows that distinction. Every current building
must be placed before saving or applying. Loading a saved template matches
current IDs and types; newly built structures need placement, and obsolete
nodes are reported rather than recreated.

`BASE.as:5034–5080` resolves destinations before moving buildings, clears all old
footprints, then installs all new positions and rebuilds pathing costs. That
ordering permits swaps which would fail as a series of individual move calls.
The remake validates the complete final arrangement under the owner's home-row
transaction and applies coordinates atomically. IDs, levels, HP, stored
resources, paid timers, armor and bunker identities remain authoritative.
Requests cannot supply replacements for those properties.

The remake adds server-side boundary/collision validation, exact inventory
matching, bounded UTF-8 names, duplicate/foreign-ID rejection and a geometry
revision token. These prevent stale tabs from replacing a newer layout or a
changed building inventory. Current build, upgrade and fortify jobs lock their
building positions. A revision depends on current IDs, types, coordinates and
footprints, not passive resource production. Normal active-battle exclusion
continues to apply. These are deliberate safeguards around the source feature,
not claims that the upstream template endpoint validates the same constraints.

## Construction workers

`client/scripts/QUEUE.as:31–76` creates **one** worker by default. Main yards add
purchased `STORE._storeData.BEW.q`; non-main yards have one worker.
`QUEUE.CanDo()` searches for an inactive worker and rejects further work when
all are occupied. `QUEUE.Add()` assigns a worker to a building, and `Remove()`
frees that worker. It is shared concurrent capacity, **not** a pending FIFO queue.
`STORE.as:1175,1762` caps the main-yard workforce at five. The `BEW` purchase path
at `STORE.as:1990` calls `QUEUE.Spawn(1)` and uses the source premium currency.

`BFOUNDATION.as:2155,2251` gates fortification and upgrades through `CanDo()`;
construction and saved active work attach through `QUEUE.Add()` at lines
3155–3185. `WORKERS.as` supplies visual worker spawning, assignment, task movement
and completion feedback. In the remake, owned-home worker visuals describe the
server's actual building jobs. Workers are not combat units or rival scouting
information.

The remake retains the one-to-five capacity but replaces premium worker
purchases with ordinary resources:

| Worker total | Required Town Hall | Twigs | Pebbles | Putty |
|---|---:|---:|---:|---:|
| 2 | 2 | 10,000 | 10,000 | 1,000 |
| 3 | 3 | 30,000 | 30,000 | 5,000 |
| 4 | 4 | 90,000 | 90,000 | 15,000 |
| 5 | 5 | 180,000 | 180,000 | 35,000 |

These permanent hires, prices and gates are local-remake adaptations. Hiring
charges the actual stored resources under the same home-row lock as building
jobs. Construction, upgrading and individual fortification consume capacity.
Research, hatching, gathering, moving and instant home repair
remain independent. Home repair became free in 0.8.2 as a requested remake adaptation. Territory jobs retain their existing single-site rules;
this milestone does not add source worker management to every yard type.
Fortification cancellation releases its capacity immediately.

## Existing worlds and deviations

Older remakes allowed unlimited concurrent building jobs. Normalization adds
`workers: 1` and `planner: {slots: []}` only when absent. It never cancels,
reschedules, completes, refunds or converts already-paid timers into extra
workers. If three old jobs exist, all three still finish; new jobs wait until
occupancy drops below the keeper's purchased capacity. One building with two
legacy timers occupies one capacity unit until both expire.

This preservation differs deliberately from the original loading path in
`BFOUNDATION.as:3162–3182`, which can recycle construction or cancel a saved
upgrade/fortification when worker assignment fails. Applying that behavior to
existing remake saves would destroy paid progress, so it is not copied.

The updater preserves existing worker counts and private layout fields exactly,
retains every map row and the completed Fortification generation marker, and
keeps active format-seven battles byte-for-byte unchanged. Existing versions
missing older map content still use the established additive migration. No
extra bots, map ownership, rank points, Planner buildings or purchased workers
are granted. Frozen-release fixtures and the streamed SQL restoration tests
cover these guarantees.

Remaining source work includes permanent decoration storage, complete source
placement categories, outpost construction/templates, the premium store and
worker economy, full original worker animation/pathing, Inferno, and the other
systems tracked in `PARITY.md`.
