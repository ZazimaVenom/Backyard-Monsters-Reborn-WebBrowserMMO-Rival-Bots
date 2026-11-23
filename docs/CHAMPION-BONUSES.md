# Level-six champion bonus meals

Gorgo, Drull and Fomor now earn three bonus-power tiers after reaching evolution
six. Each ready meal consumes actual monsters from the home reserve army and
earns one tier. Stationed bunker monsters and deployed troops cannot be used as
food. At tier three, further meals maintain that tier instead of adding more
power. The feeding cooldown remains 300 base seconds, adjusted by game speed.

| Champion | Food for each bonus or maintenance meal | Main benefits |
|---|---|---|
| Gorgo | 20 Crabatron | Health, damage and movement speed |
| Drull | 30 Fang | Health, damage and movement speed |
| Fomor | 20 Brain | Health, damage, movement speed, range and aura strength |

These preserve the actual shipped recipes. They are the source's non-Map-Room-3
overrides in `CHAMPIONCAGE.setFeedProps`; the smaller static recipes are the MR3
defaults. No inventory is granted or old meal count reinterpreted during upgrade.

The cumulative health and damage increases in the remake are:

| Champion | Tier 1 extra HP / damage | Tier 2 extra HP / damage | Tier 3 extra HP / damage |
|---|---:|---:|---:|
| Gorgo | 1,875 / 25 | 4,125 / 55 | 7,500 / 100 |
| Drull | 375 / 75 | 825 / 165 | 1,500 / 300 |
| Fomor | 250 / 2 | 550 / 4 | 1,000 / 7 |

The numbers are total bonuses at that tier, not amounts stacked on top of all
earlier tiers. Speed, HP, damage and Fomor's range follow the source increases
scaled against each original level-six statistic onto this remake's combat
scale. HP and damage are rounded to integers. Fomor's aura retains the source's
absolute additions: 3%, 6% and 15%, giving 63%, 66% and 75% at evolution six.

When a living champion earns a new health tier, only the increase in maximum HP
is added to current HP. This preserves the existing injury in hit points. A
defeated champion remains at zero until ordinary recovery occurs. A tier-three
maintenance meal provides no healing. This avoids the original feed routine's
repeated cumulative-health grant.

## Hunger, freezing and combat

After a meal becomes due, the champion has the existing 24-hour hunger grace.
Every completed missed day removes one earned bonus tier, down to zero. Evolution
six itself is retained. Health is capped to the new maximum after a tier is
lost, and passive recovery changes rate at the actual decay boundary. Reloading
or polling cannot charge the same hunger step twice. Hunger does not revive a
defeated champion.

Frozen champions retain their bonus tier and paused feeding clock. Thawing
resumes that clock. Freezing still requires a fully recovered, fed champion, now
using its actual bonus maximum HP. Battle-locked champions receive no concurrent
passive healing or hunger changes.

A new invasion snapshots the champion's earned stats once. Feeding, later
hunger or catalog changes cannot change that running battle. Battle settlement
preserves the earned tier and records real injuries without granting duplicate
health or bonus meals. Old saved invasions retain their existing stats.

## Saved-world compatibility

Earned power is stored as `bonus_feeds`, initially zero. Existing evolution
`feeds` progress, saved health, frozen roster and any legacy `bonusFeeds`
extension are preserved but do not grant free bonus power. Upgrade the existing
world with `UPGRADE-WORLD.cmd --apply`; do not rerun setup or reset the database.

The three basic champions' bonus feeding is implemented. Special champions,
original acquisition events, the full evolution artwork and the complete
original animation library remain separate unfinished systems.
