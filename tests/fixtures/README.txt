schema-v1.sql is copied byte-for-byte from release 0.4.1.
SHA-256: 95188038a38ee43346f4301cc0d4c0bfde14a34a1ce0cf65a6ea7f7cc0723292

base-v04-human.json and base-v04-bot.json were exported by that release's actual Game::initialBase and Catalog classes (IDs 1 and 4).
Only last_tick and shield_until were fixed to historical timestamps for repeatable tests.
No current v0.5 normalizer generated these two starting-state fixtures.


schema-v2.sql is copied byte-for-byte from release 0.5.0.
world-v05.json was exported using the frozen release 0.5.0 Game, World and
BattleService classes against an isolated MariaDB fixture. It includes two
developed saved bases, owned resource/stronghold rows, a paid future site
upgrade, and an actual active territory invasion with two deployed Pokeys.
The legacy bonusFeeds=3 counter is deliberately present on level-six Gorgos,
including a frozen zero-health individual, to prove migration grants no new
food powers or healing. No current v0.6 normalizer generated these records.

SHA-256 schema-v2.sql: b87c9ac2e1de61fe4aaf9d2604f2837f91201a5bfd95b1b6ef0c3d57358f82c7
SHA-256 world-v05.json: 3ad1aceb31b5314308dd4f9468945e76684d162ea9592a5526ead67ec839719e

world-v06.json was exported by frozen release 0.6.0 Game, World and BattleService
classes against an isolated MariaDB fixture. It contains two developed bases,
actual paid first-tier fortification completion and a paid second-tier job,
earned champion bonus_feeds of 2 and 3, owned resource/stronghold rows, a paid
future site upgrade, and an actual format-six territory invasion with two
deployed Pokeys and frozen per-building armor. The second champion remains
frozen at zero HP. No current v0.7 normalizer generated these records.
Authoring helper: qa/export-v06-upgrade-fixture.php (not shipped with the game).

SHA-256 world-v06.json: 020871a0f4c14ab3c60d2da3e2e65f3f5c1476f95b4ffffa6bbcb7ccf02a0126

world-v07.json was exported by frozen release 0.7.0 Game, World and BattleService
classes using a disposable full 1,000-bot world. It contains two developed bases
with actual paid parallel fortification, hatchery-upgrade and wall-construction
jobs; injured/frozen champions and earned armor; damaged resource buildings;
owned resource/stronghold/Fortification rows; and an active format-seven invasion
with deployed Pokeys and frozen 7% neighboring Fortification protection.
Paid job completion dates were moved to fixed future timestamps after purchase
so test execution cannot silently complete them. No v0.8 classes generated it.
The full source world had 2,591 sites and the completed generation marker.
Authoring helper: qa/export-v07-upgrade-fixture.php (not shipped with the game).

SHA-256 world-v07.json: fedafe8184d977847256a3f0953dbc3f7cadde310b423b2d4f8fff988ea92936
