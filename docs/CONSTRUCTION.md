# Construction workers

Every home yard starts with one construction worker. Building, upgrading and
individual-building fortification share this capacity. A new job begins only
when a worker is free; this is concurrent capacity, not a waiting job queue.
Hatching, research, Lab studies, collecting resources, moving idle buildings and
instant free home repairs remain independent. Territory work retains its own site
job limit.

The worker panel shows active jobs and their completion times. Completing a job
or cancelling unfinished fortification frees its slot. Cancelling fortification
still uses its existing storage-capped refund rules. Workers are cosmetic yard
characters, never combat units, defenders or targetable structures.

## Hiring

Workers are permanent. Hire them one at a time with building materials in **Workers** or earned Shiny in the General Store. Up to five can work in one home yard. Both routes require a completed, living Town Hall at the listed level.

| Worker | Town Hall | Twigs | Pebbles | Putty | Alternative Shiny price |
|---|---:|---:|---:|---:|---:|
| Second | 2 | 10,000 | 10,000 | 1,000 | 250 |
| Third | 3 | 30,000 | 30,000 | 5,000 | 500 |
| Fourth | 4 | 90,000 | 90,000 | 15,000 | 1,000 |
| Fifth | 5 | 180,000 | 180,000 | 35,000 | 2,000 |

The original `QUEUE.as` starts with one worker and adds up to five through the
premium `BEW` purchase. The material prices are a local alternative to the earned-Shiny purchase. Hiring can finish while other
workers are busy and immediately makes the new capacity available. Bots pay
through the same action and receive no free workers.

## Existing worlds

Older yards receive one worker without losing or delaying any already-paid work.
If more jobs were active before upgrading, all keep their original timers and
finish normally. The panel reports this temporary excess. New jobs wait until
the active count falls below purchased capacity, or another worker is hired.
The game derives worker usage from existing building timers and never maintains
a separate reservation list that could become stale.

Purchases and capacity checks run under the same home-row database lock.
Simultaneous requests cannot overbook the last free worker or purchase a sixth.
Worker purchase details, active-job summaries and saved Planner layouts are
owner-only information and are excluded from rival visit responses.

See `tests/construction_test.php`, `construction_integration.php` and
`construction_concurrency.php` for pure rules, real database actions and forced
multi-process purchase races. The release verification record reports the
engines actually exercised.
