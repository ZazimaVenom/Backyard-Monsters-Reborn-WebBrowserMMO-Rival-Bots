# Keeper truces

Open **Invasion → Truces** to view offers and active agreements. The button shows how many offers await your reply. Choose **Truce** beside any rival, or visit their backyard and select **Truce with …**. Sending or receiving an offer costs no resources or ranking points.

A player recipient can accept or decline. The sender can withdraw a pending offer. Acceptance starts 14 real days of mutual protection for both backyards and all currently owned resource outposts, strongholds and Fortifications. An accepted agreement lasts until its displayed local date and time. The server checks the expiration on every new attack, even if no one was online when it expired. You can offer another truce after expiry, withdrawal or rejection.

Both sides can keep scouting, fighting other keepers and leading wild expeditions. A pending offer allows both sides to invade. If an invasion between the pair is already underway, finish it before accepting. An alliance war can continue against other members. Only the two individual keepers receive a private agreement.

An offer to a bot gets an immediate response during the player's request. Raider bots decline. Builder, guardian and collector bots accept. Bots leave existing agreements in place and choose other rivals when planning home and territory raids. They do not schedule diplomacy work while the world sleeps.

## Implementation and original reference

The supplied Refitted server implements reciprocal pending/active checks in `controllers/mail/requestTruce.ts` and recipient-only decisions in `services/mail/handleTruceResponse.ts`. Its acceptance duration is 1,209,600 seconds. The remake uses a dedicated Invasion panel with outgoing, incoming and active lists, an option to withdraw a pending offer, and explicit replies for bot recipients. No additional account or mailbox setup is required.

Each pair stores the same request identity and expiry under both existing yard records. The combat and diplomacy services lock participant homes in ascending keeper-ID order. This serializes truce acceptance against both home raids and invasions of owned territory. A duplicate reply retains the original expiry. A late reply for a replaced offer cannot accept a newer one. Untouched worlds and existing battles require no truce migration.
