# Creature performances

The 27 surface and Inferno monsters each have a new stride and a combat release pose painted from six bearings. North, northeast, east, southeast, south and southwest have their own illustrations. West and northwest reflect the east and northeast paintings. Ground travel and flight alternate between the existing neutral bearing and the new stride. A recent hit, projectile or healing event triggers the release. Existing front-quarter travel and attack sequences still play when the unit faces their painted direction.

Each ordinary species has a four-pose defeat sequence. Gorgo, Drull, Fomor and Korath have a separate sequence for each of their six evolutions. Krallen has one for each of his three power appearances. The 54 defeat sheets contain 216 poses. Each creature reacts, loses its footing or altitude, settles, and briefly holds its final silhouette at the position of the confirmed loss. A guardian plays a retreat sequence. Its care and recovery continue under the existing game rules. The falls use a single camera angle and can reflect to follow the unit's last heading.

The 54 directional action sheets contain 324 additional poses. Each atlas uses 512-pixel cells with transparency. The client requests the new sheets for creatures visible in the current yard or battle and releases the previous scene's unused sheets. Reduced motion retains a stable final defeat pose and the existing reduced-motion movement treatment.

## Creature voices

All 32 species have a distinct synthesized voice with separate arrival and defeat calls. Their pitch, vocal tract resonances, articulation, noise, harmonics and stereo reflections vary by species. The 64 original stereo Ogg Vorbis files are sampled at 44.1 kHz. They ship with the game and use the existing sound switch and volume control. They can be regenerated offline with Python, NumPy, SciPy and FFmpeg using `python3 tools/generate-creature-voices.py` from any directory with the appropriate script path.

Newly confirmed deployments, splits and resurrections can play an arrival call. Defeats and champion retreats can play the loss call. Units of the same species can call as a group in a single poll. The mixer limits creature voices to five at once. Opening an old battle report, repeating a poll or resuming a background tab does not play historical calls.

## Validation

Run `node tests/combat_performances_test.mjs` and `node tests/renderer_test.mjs` from the release directory. The separate `defeats`, `facing-actions` and `voices` directories under `public/assets` each include a file manifest with content hashes. Browser validation covers decoded frame pixels, eight bearings, stereo audio playback and a Wild Monster Invasion resolved by defending towers.
