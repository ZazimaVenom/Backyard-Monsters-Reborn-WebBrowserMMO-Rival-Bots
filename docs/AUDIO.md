# Original audio

The soundtrack and effects in this remake are newly composed and synthesized for
this project. No music, voices, samples, or sound effects from Backyard Monsters,
Digimon, or another commercial game are included.

| File in `public/assets/audio` | Use | Length |
| --- | --- | ---: |
| `theme.ogg` | **Verdant Home** — gentle woodland/base loop | 64 seconds |
| `battle.ogg` | **The Yard Awakens** — invasion loop | 48 seconds |
| `expedition.ogg` | **Beyond the Bramble** — Wild Monster Tribe raids | 44.64 seconds |
| `defense.ogg` | **Night Watch** · defense and Baiter drills | 34.56 seconds |
| `event.ogg` | **Alarm at the Gate** · surface event raids and ambushes | 45 seconds |
| `inferno.ogg` | **The Hollow Below** · underground yard and expeditions | 47.2 seconds |
| `emergence-quake.wav` | Cavern earthquake, stereo rock impacts and subterranean rumble | 3 seconds |
| `quest.wav` | Journal award collected | 1.8 seconds |
| `sack.wav` | Mystery sack credited | 1.15 seconds |
| `build.wav` | Construction/placement confirmation | 0.75 seconds |
| `collect.wav` | Resource collection | 0.95 seconds |
| `hatch.wav` | Monster production complete | 1.35 seconds |
| `hit.wav` | Damage impact | 0.25 seconds |
| `baiter.wav` | Baiter latch and musk spray | 0.98 seconds |
| `siren.wav` | Scare away the wild pack | 1.36 seconds |
| `aerial.wav` | Aerial Defense Tower flak shot | 0.48 seconds |
| `heavytrap.wav` | Heavy Trap detonation | 1.08 seconds |
| `shot.wav` | Defensive projectile launch | 0.31 seconds |
| `victory.wav` | Successful invasion | 2.75 seconds |
| `defeat.wav` | Unsuccessful invasion | 2.6 seconds |
| `fling.wav` | Flinger bucket launch | 0.65 seconds |
| `explode.wav` | Eye-Ra / trap explosion | 1.1 seconds |
| `heal.wav` | Zafreeti healing | 0.8 seconds |
| `burrow.wav` | Wormzer emergence | 0.85 seconds |
| `laser.wav` | Laser beam | 0.5 seconds |
| `champion.wav` | Champion arrival / raising / thawing | 1.6 seconds |
| `champion-retreat.wav` | Injured champion returns home | 1.4 seconds |
| `feed.wav` | Champion meal confirmation | 0.9 seconds |
| `evolve.wav` | Champion evolution fanfare | 3.2 seconds |
| `research.wav` | Lab study / breakthrough | 1.35 seconds |
| `catapult.wav` | Siege frame release | 0.95 seconds |
| `catapult-impact.wav` | Resource bomb impact | 1.4 seconds |
| `boost.wav` | Putty empowerment | 1.15 seconds |
| `blink.wav` | Bolt teleport | 0.48 seconds |
| `venom.wav` | Venom / acid effect | 0.6 seconds |

Six stereo music loops and 29 stereo effects ship under `public/assets/audio`. Music is Ogg Vorbis. Effects are 44.1 kHz, 16-bit PCM WAV. The home and battle themes share a melodic motif. Expedition and defense have separate loops. The underground arrangement uses low sustained tones, plucked figures and percussion. Music and effects were synthesized for this project.

Use the Sound control in the game to enable playback after a user gesture. Track changes crossfade over 0.6 seconds. Repeated impacts are rate-limited, and Web Audio allows up to 24 effect voices. The browser remembers the volume setting.

The packaged audio plays directly over HTTP. To reproduce it during development, use Python, NumPy, SciPy and ffmpeg with libvorbis. Run these scripts from the project folder.

```sh
python tools/audio-compose.py
python tools/expedition-audio.py
python tools/inferno-audio.py
python tools/event-audio.py
python tools/generate-creature-voices.py
```

The [soundtrack and effects manifest](../public/assets/audio/manifest.json) records durations and production measurements. A separate collection under `public/assets/voices` supplies each of the 27 ordinary monsters and five guardians with its own arrival and defeat call. These 64 original stereo Ogg recordings use species-specific pitch contours, articulations and resonance models. Newly confirmed battle events trigger them. Repeated snapshots and old reports stay silent. [Voices manifest](../public/assets/voices/manifest.json) · [Creature performances](CREATURE-PERFORMANCES.md)

The source switches to `musicpanic` when the Trojan Horse opens and when wild invaders reach the yard. **Alarm at the Gate** is an original 24-bar response to that cue. The synthesized horn calls, plucked strings and drum figures lead into a lower flute answer, then return with marimba doubling. It accompanies the surface event battlefield, including Wild Monster Invasions, Monster Blitzkrieg, the Trojan Horse, Brukkarg War, Creature Carnage and Hell Raisers. The underground keeps its own score.
