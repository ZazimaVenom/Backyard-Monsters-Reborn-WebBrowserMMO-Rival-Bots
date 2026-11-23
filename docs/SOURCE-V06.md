# Source audit: building fortification and champion food powers

Version 0.6 closes two combat/progression gaps: four tiers of individual-building
fortification and three food-bonus tiers for evolution-six Gorgo, Drull and Fomor.
These are separate from the Map Room 3 Fortification cells and their six-neighbor
defender network. Those map cells remain unfinished.

Source paths below are relative to the supplied `backyard-monsters-refitted`
archive. The source client is ActionScript; the remake applies the rules on the
PHP server, including costs, clocks, damage and persistence.

## Individual-building fortification

`client/scripts/YARD_PROPS.as` defines `can_fortify` and four `fortify_costs`
entries for Storage Silo (6), Town Hall (14), Cannon Tower (20), Sniper Tower (21),
Laser Tower (23), Tesla Tower (25), Aerial Defense Tower (115) and Railgun (118).
The latter two structures are not yet implemented, so the six existing structures
are the v0.6 eligibility list. Walls, resource producers and monster facilities do
not acquire fortification merely because they are buildings. Quake/Magma definitions
contain cost data without the same eligibility flag; they are not used here.

The complete original per-building resource/time tables are preserved in
`ORIGINAL-CATALOG.json`. The remake uses those exact six tables with resource
costs divided by 100 and rounded upward, and duration divided by 300 and rounded
upward. Game speed then applies to the duration. Original Town Hall prerequisites
5/6/7/8 become 2/3/4/5 to fit the existing five-level remake progression.

| Completed fortification | Original and remake incoming damage reduction |
|---|---:|
| None | 0% |
| Tier 1 | 20% |
| Tier 2 | 30% |
| Tier 3 | 40% |
| Tier 4 | 50% |

`BFOUNDATION.as:502–513` reduces incoming damage by `(tier × 10 + 10)%` for a
positive tier, before the separate generic armor multiplier. It does not increase
maximum HP. `FortifyB` charges resources when work begins; `Fortified` increments
the completed tier once. `FortifyCancelC` refunds the full cost and does not grant
the next tier. `BTOWER.as:160` disables firing while construction, upgrade or
fortification work is active. The previous completed tier still reduces damage.

`BASE.CanFortify` rejects unsupported structures, a missing prerequisite Town Hall,
the maximum tier, conflicting construction/upgrade/fortification and unaffordable
costs. Source `QUEUE.CanDo` also consumes a worker shared with construction; the
source defaults to one worker, with additional workers in its premium system.
The remake continues to allow parallel work on different buildings. It requires
the selected building to be completed and fully repaired, rejects conflicting
actions/battles, stores the actual paid cost, and reports any cancellation refund
that cannot fit current storage. Finishing fortification does not repair damage.
These scheduling, repair and capped-refund choices are explicit adaptations.

New format-six invasions snapshot completed protection and tower readiness once.
All building damage routes use the same reduction, including direct attacks,
explosions, special abilities and Catapult impacts. Untargetable cores stay
untargetable. Older saved battle formats keep their existing damage rules.

## Evolution-six champion meals

`client/scripts/CHAMPIONCAGE.as:45–175` defines the three basic champions' bonus
tables. `FeedGuardian`, around lines 684–781, consumes a normal monster recipe,
increments `_foodBonus`, and clamps it to three. A meal at tier three maintains
that tier. These are cumulative tier values, not three bonuses that should be
added together.

| Champion | Source HP bonus, tiers 1 / 2 / 3 | Source damage bonus | Source speed bonus | Other source bonuses |
|---|---|---|---|---|
| Gorgo | 12,500 / 27,500 / 50,000 | 150 / 330 / 600 | 0.1 / 0.2 / 0.4 | None |
| Drull | 2,500 / 5,500 / 10,000 | 400 / 880 / 1,600 | 0.1 / 0.2 / 0.4 | None |
| Fomor | 1,000 / 2,200 / 4,000 | 3 / 6 / 10 | 0.1 / 0.2 / 0.4 | Range +3 / +6 / +10; aura +0.03 / +0.06 / +0.15 |

`ChampionBase.as:123–155` and `1246–1277` apply the chosen tier to health, movement,
damage, range and aura. The remake scales health/damage/speed/range increments by
their ratio to the source level-six baseline onto its existing level-six stats;
HP and damage increments are rounded. Fomor's aura increments retain their original
absolute values. Level-five or younger champions ignore bonus tiers.

`CHAMPIONCAGE.setFeedProps:537–565` replaces the smaller default recipe **when not
in Map Room 3**. The existing remake uses that non-MR3 branch and retains it:

| Champion | Monsters consumed per bonus or maintenance meal |
|---|---:|
| Gorgo | 20 Crabatron |
| Drull | 30 Fang |
| Fomor | 20 Brain |

The source defines `bonusFeedTime` as 24 hours, but the implemented bonus-feed path
sets the ordinary `feedTime` of 23 hours. The remake consistently keeps its existing
300-base-second cooldown and 24-hour hunger grace. Game speed changes the cooldown,
not the real-time hunger grace.

On a newly earned tier, the remake adds only the increase in maximum HP to a living
champion, retaining its absolute injury. A zero-health champion stays at zero from
the bonus action, and a tier-three maintenance meal grants no extra health. This
deliberately avoids the source path that adds the full cumulative HP bonus again
on repeated feeding. Ordinary passive recovery remains a separate clocked system.

`ChampionBase.as:1092–1108` reduces level-six food power after the overdue meal plus
`CHAMPIONCAGE.STARVETIMER` (24 hours), preserving evolution. Its frame/catch-up code
subtracts an elapsed argument and uses an inconsistent HP cap. The remake instead
removes one earned tier per complete grace interval, caps health to the resulting
maximum, and computes passive recovery across the actual tier-change boundaries.
Neither normalization nor hunger removal revives a defeated champion.

The source exports the food bonus as `fb` (`ChampionBase.as:1189/1206`). The Chamber
preserves it while frozen and restores paused feed time on thaw
(`CHAMPIONCHAMBER.as:126–130,175–189`). The remake stores earned tiers in
`bonus_feeds`, preserves frozen and battle-locked state, and snapshots combat stats
once. Historical `bonusFeeds` maintenance counters are retained for compatibility
but do not grant these newly implemented powers.

## Compatibility and limits

Schema remains 2. A content upgrade to 0.6 adds only zero/null fortification fields
and zero earned food tiers. It grants no armor, monster meals, resources, healing,
research, territory ownership or rank. Existing home/site geometry, garrisons,
upgrade timers, revisions, champions, rank history and active battle bytes survive.
`UPGRADING.md` describes the streamed JSON/SQL backups and restoration procedure.

This release does not implement MR3 defender rings, Inferno, remaining special
champions, the original global worker/premium economy, or full original animation
parity. See `PARITY.md` for the broader remaining scope.
