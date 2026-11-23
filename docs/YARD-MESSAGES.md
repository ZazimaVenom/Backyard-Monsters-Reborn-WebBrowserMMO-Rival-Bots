# Visitor signs and the Radio Tower

**Simple Sign** is available from **Yard signs** when visiting another player or bot’s surface backyard. Write up to 180 characters and click open ground on the map. The sender pays 1,000 each of twigs, pebbles, putty and goo. The sender can edit or remove the sign. The host can remove it at any time. All visitors can read it, and editing preserves the original 48-hour deadline. A sign can also be opened from the yard canvas. Historical battles retain their frozen signs.

The original visitor item is building 52 in `YARD_PROPS.as`. `BUILDING52.as` supplies its 40-unit footprint. `SIGNS.as`, `SIGNPOPUP.as` and `BEXPIRABLE.as` define message creation, sender edits and expiry. The local price follows the remake’s 100-to-1 facility resource conversion. The placement check uses visible geometry so scouting with a sign cannot reveal hidden traps. Receipts and sender/recipient row locks make a retried placement charge once.

**Radio Tower** is a buildable Town Hall level 1 surface facility. Construction uses one worker and costs 20 twigs, 20 pebbles and 20 putty. It has 3,400 health at its single level. Select the working tower to open the receiver and opt into either channel. Attack alerts identify a real incoming invasion and link to its report. Construction news announces a real finished building or upgrade. Read progress stays with the owner and the receiver keeps the newest 50 broadcasts. A destroyed tower resumes reception after free repair.

Radio building 113 and the separate attack and news preferences come from the supplied client’s `RADIO.as` and `RADIOSETTINGSPOPUP.as`. This installation delivers both channels through a private local inbox. It requires no email account, provider, background sender or external credentials.
