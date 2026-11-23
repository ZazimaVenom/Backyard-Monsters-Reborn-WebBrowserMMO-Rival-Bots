# Fortify your buildings

Fortification is a separate upgrade track for the Town Hall, Storage Silo,
Sniper Tower, Cannon Tower, Laser Tower, Tesla Tower, Aerial Defense Tower and Railgun. Select one of these
buildings in your own backyard to see its current protection, the next tier's
price, Town Hall requirement and completion time.

| Tier | Incoming building damage reduced | Required completed Town Hall |
|---|---:|---:|
| 1 | 20% | 2 |
| 2 | 30% | 3 |
| 3 | 40% | 4 |
| 4 | 50% | 5 |

A Railgun requires Town Hall 5 for each of its four fortification tiers.

Each purchase advances exactly one tier. It deducts actual resources immediately
and records a persistent timer. The building must be completed and fully
repaired, and the Town Hall must be alive and finished. A free home repair can restore damage during a paid job. Moving or upgrading the
building requires the current fortification job to finish or be cancelled. A keeper involved in an invasion cannot modify the
yard. Other buildings can still be worked on in parallel.

Completed protection remains active while a later tier is being built. Towers
being fortified do not fire when an invasion starts during that work. Damage
reduction changes neither maximum HP nor the building's normal level; completing
a tier does not heal damage or revive a destroyed structure. Ordinary repairs
and building upgrades retain earned fortification. Storage capacity is unchanged.

You can cancel an unfinished job. The refund uses the cost actually paid when
that job started, with each resource capped by current free storage. The result
reports credited resources and any overflow. Cancellation grants no tier and
cannot be repeated for a second refund. Already-held surplus above capacity is
never deleted to make room for a refund.

Battle protection and tower readiness are captured when an invasion starts.
They remain fixed for that entire invasion, including if a home job completes
while combat is running. The next invasion uses the completed tier and current
readiness. This matches the remake's existing treatment of building upgrade
snapshots; it is an explicit adaptation. Old saved invasions receive no new
fortification retroactively.

## Source and adaptation

The original seven structures and the Railgun, four tiers, 20/30/40/50% damage reduction, tower
downtime, resource spending and full-cost cancellation follow the supplied Flash
source (`BFOUNDATION.as`, `BASE.as`, `BTOWER.as` and the building definitions in
`YARD_PROPS.as`). The exact extracted costs are retained in
`ORIGINAL-CATALOG.json`.

This local game's fortification prices divide the source resource amounts by
100 and round up. Base construction times divide source seconds by 300 and
round up; the configured game speed then applies normally. Original Town Hall
gates 5/6/7/8 are mapped to this remake's 2/3/4/5 progression. Requiring a fully
repaired building before starting avoids the source's coupled repair/worker
queue behavior. Each home fortification occupies one of the keeper’s hired workers until the project completes or is canceled. Other available workers can take separate projects. [Construction workers](CONSTRUCTION.md)

Fortifying a building is separate from the original Map Room's six-neighbor
Fortification-cell network. See `FORTIFICATION-NETWORK.md` for the world-map network.

Saved worlds gain no free protection. Existing buildings start at tier zero
unless a fortification was actually purchased in this release. Accounts, bot
identities, ranks, armies, layouts, HP and existing timers remain intact.
