# Bot workers and Yard Planner

The same 1,000 persistent keepers now use the shared home construction capacity.
Building, upgrading and fortifying require a free purchased worker; the scheduler
checks availability before choosing those actions, and `Game::mutate` checks again
under the home-row lock before charging. Existing paid jobs above a keeper's new
capacity finish normally. Bots never cancel, complete or reschedule them to make
room. Repairs, training, research, champion care and territorial operations remain
independent of home construction workers.

## Paid capacity and development

Every keeper starts with the same one base worker as a player. Further workers are
bought through the ordinary `hireWorker` action at the same Town Hall gates and
resource prices. Builders and guardians aim for up to five, collectors up to four
and raiders up to three. Hiring is considered periodically, or when all workers
are busy; it requires the price plus the next home investment, missing champion
food and at least 1,000 goo for ordinary army development. No paid worker is
granted during installation or upgrade.

The Yard Planner is a normal one-per-home construction goal after the Flinger,
Locker, Map Room and eligible Champion Cage. It must be paid for, occupy a worker
and complete before it can be used. Existing yards receive no inserted building.
An occupied worker slot skips construction choices without skipping the rest of
the bot's turn. Existing ordinary expansion and Fortification campaigns continue.

## Bounded layout improvement

Once in each identity-stable eleven-turn cycle, a keeper with a completed living
Planner may consider improving its tower coverage. A successful rearrangement
records a persistent fifteen-minute cooldown. This avoids layout changes on every
worker tick or immediately after a restart.

The search examines at most four idle firing towers. Each considers eight points
beside its two highest-priority structures and up to five normal safe placement
candidates. Guardians favor their Town Hall, collectors their resources, raiders
their army infrastructure and builders their specialist facilities. The score
rewards protection and overlapping coverage, with a small approach gradient to
help poorly positioned defenses find useful space. A score improvement of at
least 0.5 is required. Stable identity tie-breaks retain distinct preferences.

Only one tower moves in an accepted plan. The final full layout passes the same
`Planner::validateLayout` used by players, then `plannerApply` rechecks its current
geometry revision and battle eligibility under the ordinary home-row lock.
Construction and fortification positions stay locked. If the existing layout is
invalid, no tower can move, or no candidate meaningfully improves protection, the
saved yard remains unchanged. There is no global packing, teleporting army,
building deletion, repair, tier grant or free resource reward.

Layout slots belong to the keeper and are not overwritten by autonomous planning.
The normal saved building IDs, HP, armor, garrison keys, stored resources and paid
timers survive rearrangement. Bot identity, ranks and win/loss records are also
unchanged. Combat still uses its ordinary authoritative snapshots.

## Observability and verification

Worker heartbeat totals add `workers_hired` and `layouts_improved`. A successful
layout also stores `ai.last_layout_at` and `ai.last_layout_turn`; these are bounded
scheduler memories, not a second construction queue.

`tests/bots_construction_planner_test.php` covers all 1,000 identities, deterministic
plans, profile diversity, shared final-layout validation, strict score improvement,
locked jobs, real record preservation and purchase reserves.
`tests/bots_construction_integration_test.php` exercises actual database-backed bot
turns, paid worker and Planner purchases, overloaded legacy jobs, independent
hatching/garrison work, persistent layout application and cooldown, active-battle
exclusion, and unchanged population/rank identities. See `VALIDATION.md` for the
engines and counts actually run for the release.

The new pure suite passed 23,023 assertions, and the four existing pure bot suites
passed another 46,395. MySQL 8.0.44 and MariaDB 10.4.32 each passed 48 new scheduler
database assertions plus all 11,345 existing bot assertions. No old bot fixture
needed a free worker or other compatibility grant. The optional prepared-population
performance workloads were not rerun for this milestone.

This bounded local search improves available coverage; it does not claim globally
optimal defenses or original player AI. Worker resource purchases and autonomous
bot layouts are explicit remake adaptations.
