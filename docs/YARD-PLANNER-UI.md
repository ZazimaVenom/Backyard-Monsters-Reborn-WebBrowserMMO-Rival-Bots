# Yard Planner and construction crew — 0.8

The home yard exposes a Workers status chip and a Yard Planner button. Construction capacity comes from the owner's private `base.construction` snapshot. Build and upgrade controls explain when every worker is busy; fortification uses its server-provided availability. Repair, collection, hatching and research retain their independent rules.

The Workers dialog lists actual building IDs, job kinds and server deadlines. Its permanent hire button uses `nextHire` costs and Town Hall requirements and sends `hireWorker {}`. The server checks and spends resources. Five purchased workers is the maximum. Earlier paid jobs above the current capacity remain visible and continue to their existing deadlines.

## Editing a layout

A completed, living paid Yard Planner is required. The editor opens only from the owner's home and clones building identities and geometry into a private browser draft. Drag a building, or select one through the canvas or building selector, choose Move, and click clear ground. Temporary storage permits swaps without removing anything from the live yard. Undo, Reset draft and Cancel are explicit controls; Ctrl/Cmd+Z also undoes outside form fields and dialogs. Escape cancels the active placement first, then the draft.

The overlay shows the yard boundary, footprint grid, optional defense ranges and pinned construction. All current buildings must be placed exactly once before Save or Apply. Coordinates use the same footprint clearance and `1e-9` floating-point tolerance as the server. Invalid layouts remain editable with an explanation. When another session starts work, affected buildings return to their actual positions and become pinned; unrelated edits remain. Undo snapshots also respect new pins.

Two named server slots support Save, Load and Delete. Replacing or deleting an occupied slot has a review dialog. Saving a template leaves the real yard unchanged. Loading matches existing building IDs and types, ignores obsolete identities and puts new buildings into temporary storage. Busy buildings retain their actual positions. Only Apply changes the live layout, after authoritative server validation of every position and the exact geometry revision.

Background refreshes update resources, health, work and slot information without replacing unsaved draft positions or typed names. A changed inventory/geometry revision makes the draft stale and requires an explicit Reset draft. Save, Delete and Apply include the starting revision. Pending writes disable edits and consume Escape/Cancel until the request finishes. Navigation can leave the editor; an epoch, owner and destination guard prevents late responses from reopening it or replacing a newer battlefield. Leaving always restores the live coordinates immediately, including when the next navigation fails. An accepted-state generation also rejects older bootstrap polls after a confirmed write, so a delayed poll cannot undo an applied layout, saved slot or purchased worker in the interface.

## Frontend verification

- `node tests/planner_ui_test.mjs`: 46 checks covering isolated draft swaps, exact payloads, stale revisions, pin reconciliation, undo, names, server responses, write guards and account/navigation isolation.
- `node tests/workers_ui_test.mjs`: 16 checks covering authoritative jobs/costs, hire guards, capacity limits, legacy over-capacity status and home-only controls.
- `node tests/navigation_ui_test.mjs`: 31 checks covering real main-controller navigation promises, including pending planner writes, restoration after canceled drafts and battlefield/account isolation.
- `node tests/renderer_test.mjs`: 447 checks including draft drag input, collision previews, worker routes, stable assignments, pose selection and reduced motion.

These recording-DOM/canvas tests complement browser and HTTP checks. They do not imply completion of every original game system.
