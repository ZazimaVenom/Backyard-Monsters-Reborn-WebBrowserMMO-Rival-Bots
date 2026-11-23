# Automatic online-world controls — 0.8.1

Entering the game starts a presence heartbeat automatically. Players do not need
to open a command window or start a separate worker. The browser sends only its
random document identifier; PHP supplies the authenticated human identity and
session, and the server chooses how much simulation work to run.

Each game document has its own lease. The client renews it approximately every
five seconds, with only one request in flight. An open background tab continues
renewing when its browser permits timers. Returning to the tab or restoring the
network requests an immediate renewal. Browser suspension or a failed connection
can expire the server's 45-second lease; returning wakes the world again.

Closing a page sends a best-effort, same-origin JSON `worldLeave` request with the
normal CSRF header and `keepalive`. The timeout also covers abrupt browser exits.
A page restored from the back-forward cache receives a new identifier, so a late
departure from the old document cannot remove its renewed presence. Normal logout
also clears the client loop; the server removes the session's leases.

`worldPulse` responses update world status only. They never replace the keeper's
base, inventory or an open Yard Planner draft. Account/lifecycle generations reject
old responses, and an older bootstrap cannot overwrite a confirmed pulse status.
The browser never fabricates victories, losses or rank points.

## Visible status

The sidebar and settings distinguish these states:

- Paused because nobody is online.
- Disabled by world configuration.
- Starting, before a recent completed simulation tick is available.
- Active, with the actual online-player count and shared battle count.
- Reconnecting after a failed heartbeat.
- Retrying after a failed simulation result.

The green active state requires a recent completed server tick as well as online
players. A recent historical heartbeat cannot conceal a current failed connection.

## Live rankings

While the rankings page is visible, successful pulses refresh its existing page
approximately every 15 seconds. Refreshing does not navigate to page one, replace
the table with loading placeholders, or announce a connection error every tick.
Old responses are rejected after a newer page selection, navigation or logout.
The table uses the server's persisted ranks and win/loss records.

## Verification

`node tests/world_activity_ui_test.mjs` exercises the presence lifecycle, hidden
tabs, stale/account responses, connection recovery, back-forward-cache return,
CSRF keepalive departure and truthful status. The additional leaderboard cases in
`node tests/navigation_ui_test.mjs` exercise real main-module rendering and
out-of-order requests. Server scheduling and actual combat settlement are covered
by the separate PHP/database/HTTP suites.
