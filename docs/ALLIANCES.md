# Alliances

Open **Alliances** in the sidebar. A keeper can found a new alliance for free or search the directory and request to join an existing one. Each alliance has one leader and up to 50 keepers. Players can belong to one alliance at a time.

- **Found an alliance** asks for a 3–30 character name and an optional description. Names must be unique across the realm. The founder becomes leader.
- **Recruit** lets the leader find unaffiliated keepers by name. A player receives an invitation in the alliance inbox. A bot accepts immediately. Recruited bots continue playing their own bases under the normal online-player activity rules.
- **Inbox** shows your invitations and requests. The recipient can accept or decline. The sender can withdraw a pending offer. When a keeper joins, their outstanding exchanges with every other alliance close.
- **My alliance** shows the current roster, its leader and each keeper’s rank points. The leader can change the description, remove members and give leadership to another player. The former leader stays on the roster as a member. A leader with remaining members must transfer leadership or remove those members before leaving. The last keeper’s departure disbands the alliance and closes outstanding invitations.
- **Alliance feed** opens a private conversation with the 50 newest messages. Player posts support up to 200 characters. It records founding, joins, departures, removals, leadership changes, diplomatic flags, power recharge contributions and activations. Open feeds refresh every five seconds while a member types. After leaving, a keeper loses access to that alliance’s feed. The local HTTP implementation follows the 50-record window and message types in `server/src/services/alliance/allianceMessages.ts` and `server/src/enums/Alliance.ts`.
- **All alliances** orders the realm by the sum of the members’ current rank points. The oldest alliance ID breaks an exact tie. Negative scores, bot results and defended invasions all count. Reopening the directory or searching fetches the latest standings. Searching by name preserves each alliance’s overall position.

Membership changes never transfer a keeper’s buildings, army, resources or personal rank. A stale leadership or removal action must be reviewed against the current roster. Two leaders recruiting the same bot cannot share its points, and concurrent acceptances cannot claim a 51st place.

## Solo play against bot clans

Keep the game open while signed in. As eligible bots take their usual turns, up to four rival clans form: **Bramblethorn Pact**, **Cinderwing Company**, **Moonfen Keepers** and **Ironbark Circle**. If a player already chose one of these names, the clan adds a number. Each clan draws up to eight keepers from the existing 1,000 bots. At least 968 bots remain outside autonomous clans for player recruitment unless players recruit them. Founding and membership award no points.

Found your own alliance, select a **Bot-led** rival from the directory, and send a challenge from its War board. The bot leader answers on the next available online bot turn. Free alliances accept a valid challenge. If another war begins before the offer is processed, the bot declines. Bot clans also process join requests from unaffiliated players when there is room. Clan membership stays saved between sessions. Setup, upgrades and directory views create no clans and cannot answer offers.

Combat-ready bots on a normal home raid prefer eligible opponents from their current war. Usual scheduling, shields, truces, difficulty limits and the configured chance to target a human still apply. Players can also open **Enemy keepers** in the war board to start an ordinary invasion. Busy yards, shields and truces are labeled there.

## Diplomacy and wars

Open another alliance from the directory and select **War board**. Leaders can independently flag another alliance **Ally**, **Neutral** or **Foe**. Flags belong to the alliance that sets them. Changing a flag does not automatically open a war.

**Challenge to war** sends an offer that expires after 24 hours. The other leader can accept or decline, and the challenger can withdraw an unanswered offer. Acceptance records both current rosters and starts a 12-hour window. An alliance can enter one war at a time. The war board shows the deadline in the viewer’s local time, current scores, earlier results and the opposing keepers eligible for a regular invasion. The usual shields, truces, range checks for map sites and online-player gate for bots still apply.

A ranked home invasion or an invasion of keeper-owned territory qualifies when both keepers still belong to their opposing alliances, both were on the rosters at acceptance, and the invasion starts during the war. Its Flinger receives 25% extra capacity, rounded down, and its battle timer is seven minutes. Once a battle starts, its sides and mechanics are fixed. A completed battle with at least one attacking monster or champion deployed awards **one war point** to the winning alliance if it settles before the deadline. A successful defense counts. Individual rank changes continue under the normal game rules. Battle reports and the war board show the score.

Practice drills, tribal expeditions, neutral sites, undeployed retreats and late settlements award no war points. The war board shows a win, loss or draw after the deadline. A leader can transfer leadership during a war. The last keeper must wait until the war ends to disband.

## Alliance Power-ups

Open **Alliances → Power-ups** to see your balance, your alliance’s three timers, their local deadlines and the latest contribution or activation. Each power starts charging when the alliance forms. Any member may spend their own Shiny to remove recharge time at 20 Shiny per hour. The final partial hour costs 20. The server bills only the hours remaining. Once a power is ready, the current leader can activate it for all members. After activation ends, its next recharge begins immediately, including time spent away.

| Power | Active | Recharge | Effect |
| --- | --- | --- | --- |
| Armament | 12 hours | 7 days | Defending surface towers and walls gain 50% health while preserving existing damage. Traps deal 25% extra damage. |
| Conquest | 6 hours | 5 days | Reduces invasion and takeover fees by 25%, rounded up. Local World Map captures cost zero before and after the reduction. |
| Declare War | 12 hours | 7 days | Adds two cells of World Map reach from the home and each holding, 25% Flinger capacity rounded down, and a seven-minute surface invasion timer. |

A battle keeps the powers in force when it begins, including through refresh, expiry or changes in membership. Damage to an armored building carries back in proportion to the building’s normal health. Alliance effects are limited to the surface realm. The Declare War power is independent of formal wars between two alliances. A battle eligible for both receives the time and Flinger bonus once. Formal war scoring still requires a qualifying opponent, deployment and timely settlement. Bot charter leaders activate naturally charged powers on their scheduled turns while a human is online.

## Source and remake rules

The original server’s `config/AllianceConfig.ts`, `enums/Alliance.ts`, `controllers/alliance/{createAlliance,promoteMember,leaveAlliance,kickMember}.ts` and `services/alliance/membership.ts` provide the 50-keeper limit, name length, leader/member roles, invitation/request distinction, and transfer-before-leave rule. The original client exposes the browser, members, suggested keepers and invites in `com/monsters/alliances/tabs`. This release brings these actions to the PHP/Canvas game's sidebar.

The single-world league uses this remake’s shared player/bot battle points. Recruitment of persistent bots, autonomous clans and their responses are local-play additions. This release covers membership and standing. The directional stance names and behavior come from `services/alliance/relationships.ts`. The 12-hour Declare War runtime comes from `config/AllianceConfig.ts`, the 25% bucket bonus from `ATTACK.as` and the seven-minute timer from `GLOBAL.as`. Mutual leader consent, frozen rosters, the one-win scoring ledger, and a fixed challenge expiry are remake competition rules. The three rechargeable powers follow the original server’s `services/alliance/powerups.ts` and the client’s `POWERUPS.as` and alliance base buffs. The original alliance shout feed remains unfinished.

## Saved worlds

SQL schema 3 stores alliances, membership, offers, directional flags, war rosters and uniquely credited battle IDs. The updater adds these tables to existing worlds without assigning any keeper a membership. The normal update backup includes existing alliance rows when resuming an upgrade. The installer creates neither alliances nor player accounts. Only normal active gameplay forms a bot clan.

The optional `tests/alliances_integration.php` suite runs against an empty disposable MySQL/MariaDB schema ending in `_alliance_test`, selected with `MYSQL_TEST_DATABASE`. It covers actual transactions, permissions, invitations, standings and two races using independent PHP processes. The separate `tests/alliance_wars_integration.php` suite uses a disposable database ending in `_war_test` and exercises ranked battle start, deployment and settlement through the game's normal services, including bot combat and competing challenge acceptances. The fresh-install suite `tests/bot_alliances_integration.php` uses an empty `_clan_test` schema and runs the actual installer, worker and solo alliance battle services.

The live `tests/alliance_powers_integration.php` suite uses an empty disposable database ending in `_powers_test` to cover charging, leadership, combat and saved health, World Map reach, and bot activation.
