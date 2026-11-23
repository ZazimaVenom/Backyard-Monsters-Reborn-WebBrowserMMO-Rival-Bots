"""Compose the underground settlement's 5/4 score with the game's own synthesized instruments."""
from pathlib import Path
import importlib.util
import json
import subprocess
import tempfile
import numpy as np

spec = importlib.util.spec_from_file_location("instruments", Path(__file__).with_name("audio-compose.py"))
music = importlib.util.module_from_spec(spec)
spec.loader.exec_module(music)

def score():
    beat = .59
    track = music.Track(16 * 5 * beat)
    roots = [38, 38, 39, 34, 38, 41, 36, 33]
    phrase = [(0, 0, 1.7), (2, 7, .9), (3.5, 1, 1.25)]
    for bar in range(16):
        at = bar * 5 * beat
        root = roots[bar % 8]
        track.note('pad', root + 12, at, 5.2 * beat, .067, -.3)
        track.note('pad', root + 19, at, 5.15 * beat, .039, .36)
        for pulse, degree, length in phrase:
            track.note('bass', root + degree, at + pulse * beat, length * beat, .18, -.13)
        for pulse, interval in [(0,12),(1.5,19),(2.5,13),(4,12)]:
            track.note('marimba', root + interval, at + pulse*beat, .85, .073, -.47 if pulse < 2 else .46)
        for pulse in (0, 3):
            track.add(music.drum('kick', .12), at + pulse*beat, -.11)
        if bar % 4 in (1, 2):
            for pulse, interval in [(1, 27),(2.75, 24),(4, 25)]:
                track.note('flute', root + interval, at + pulse*beat, .9, .085, .22)
        if bar >= 8:
            track.add(music.drum('wood', .10), at + 2*beat, .38)
            track.note('bell',root+31, at+4.5*beat, 1.7, .049, -.48)
    return track.finish(.32,.79)

if __name__ == "__main__":
    data = score()
    with tempfile.TemporaryDirectory() as tmp:
        source = Path(tmp)/"cavern.wav"
        music.write_wav(source, data)
        subprocess.run(["ffmpeg","-loglevel","error","-y","-i",str(source),"-c:a","libvorbis","-q:a","5","-metadata","title=The Hollow Below",str(music.OUT/"inferno.ogg")], check=True)
    manifest = music.OUT/"manifest.json"
    contents = json.loads(manifest.read_text())
    contents["files"] = [row for row in contents["files"] if row["file"] != "inferno.ogg"] + [dict(file="inferno.ogg",duration=round(len(data)/music.RATE,3),loop=True,samplePeak=round(float(np.max(np.abs(data))),4),rmsDbfs=round(float(10*np.log10(np.mean(data**2))),2))]
    manifest.write_text(json.dumps(contents,indent=2)+'\n')
