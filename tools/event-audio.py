"""Compose the original 45-second surface event score, Alarm at the Gate.

Reuses the release's oscillator ensemble. Run after audio-compose.py,
expedition-audio.py and inferno-audio.py when rebuilding all music.
"""
from pathlib import Path
import importlib.util
import json
import subprocess
import tempfile
import numpy as np

spec = importlib.util.spec_from_file_location("instruments", Path(__file__).with_name("audio-compose.py"))
music = importlib.util.module_from_spec(spec)
spec.loader.exec_module(music)


def signal_horn(note, duration):
    """A short hand-stopped brass call with a small attack scoop."""
    t = np.arange(round(duration * music.RATE)) / music.RATE
    hz = music.hz(note)
    pitch = hz * (1 - .018*np.exp(-t/.032) + .0022*np.sin(2*np.pi*4.8*t))
    phase = 2*np.pi*np.cumsum(pitch) / music.RATE
    wave = sum(np.sin(partial*phase) * np.exp(-t * (.3 + .12*partial)) / partial**1.25
               for partial in range(1, 8))
    return wave * music.envelope(len(t), .045, .12)


def compose():
    beat = 60 / 128
    track = music.Track(24*4*beat)
    # Eight-bar signal, lower answering phrase, then the full return.
    progression = [38, 38, 34, 36, 38, 41, 36, 33]
    calls = {
        0: [(0, 62, .75), (.875, 65, .45), (1.5, 69, 1.35)],
        2: [(0, 65, .6), (1, 62, 1.25), (2.75, 60, .72)],
        4: [(.5, 62, .6), (1.25, 67, .65), (2, 69, 1.35)],
        6: [(0, 67, .7), (1.5, 64, 1.4)],
        7: [(1.5, 61, .7), (2.5, 64, .95)],
    }
    for bar in range(24):
        section, phrase = divmod(bar, 8)
        root = progression[phrase]
        start = bar*4*beat
        for onset, offset in [(0, 0), (1.5, 7), (2.5, 12), (3.5, 7)]:
            track.note('bass', root+offset, start+onset*beat, .68*beat, .17, -.04)
        for pulse in (0, 1.5, 2.5):
            track.add(music.drum('kick', .16 if pulse == 0 else .10), start+pulse*beat)
        for pulse in (1, 3):
            track.add(music.drum('wood', .091), start+pulse*beat, .3)
            if section != 1 or phrase >= 4:
                track.add(music.drum('snare', .08), start+pulse*beat, -.2)
        for step in ([0, 3, 4, 6] if section == 1 else [0, 2, 3, 4, 5, 7]):
            interval = [0, 7, 12, 7, 0, 12, 7, 3][step]
            track.note('pluck', root+12+interval, start+step*.5*beat, .55*beat, .092, -.42)
        for onset, pitch, length in calls.get(phrase, []):
            if section == 1:
                track.note('flute', pitch-5, start+onset*beat, length*beat, .116, .32)
            else:
                track.add(signal_horn(pitch, length*beat)*.12, start+onset*beat, .21)
                if section == 2:
                    track.note('marimba', pitch+12, start+(onset+.09)*beat, .49*beat, .048, -.36)
        if section == 1 or phrase >= 4:
            track.note('pad', root+12, start, 4.15*beat, .061, -.19)
            track.note('pad', root+19, start+.04, 4.1*beat, .037, .4)
        if phrase == 7:
            for pulse, gain in [(3, .064), (3.5, .079), (3.75, .098)]:
                track.add(music.drum('snare', gain), start+pulse*beat, -.19)
    return track.finish(.22, .79)


def earthquake():
    """A three-second subterranean rumble with four independently placed rock impacts."""
    t = np.arange(3 * music.RATE) / music.RATE
    rng = np.random.default_rng(127)
    channels = []
    for pan in (0, 1):
        noise = rng.normal(0, 1, len(t))
        low = np.convolve(noise, np.ones(240) / 240, 'same')
        grit = np.convolve(noise, np.ones(19) / 19, 'same')
        rumble = (2.5*low + .08*np.sin(2*np.pi*(38*t+2*t*t))) * np.maximum(0,np.sin(np.pi*t/3))**.75
        for at, gain in ((.52,.35), (1.13,.29), (1.76,.38), (2.26,.21)):
            delay = t - at - .008 * pan
            z = np.maximum(0,delay)
            crack = grit + .5*np.sin(2*np.pi*(48*z+1.9*(1-np.exp(-20*z))))
            rumble += (delay>=0) * gain * np.exp(-8*z) * crack
        channels.append(rumble)
    audio = np.stack(channels,axis=1)
    return audio / np.max(np.abs(audio)) * .68


def write_quake():
    data = earthquake()
    music.write_wav(music.OUT/'emergence-quake.wav',data)
    manifest = music.OUT/'manifest.json'
    obj = json.loads(manifest.read_text())
    entry = {'file':'emergence-quake.wav','duration':3,'loop':False,'samplePeak':.68,
             'rmsDbfs':round(float(10*np.log10(np.mean(data**2))),2)}
    obj['files'] = [row for row in obj['files'] if row['file']!=entry['file']] + [entry]
    manifest.write_text(json.dumps(obj,indent=2)+'\n')


if __name__ == "__main__":
    data = compose()
    with tempfile.TemporaryDirectory() as tmp:
        source = Path(tmp)/'event.wav'
        music.write_wav(source, data)
        subprocess.run(['ffmpeg','-loglevel','error','-y','-i',str(source),'-c:a','libvorbis','-q:a','5',
                        '-metadata','title=Alarm at the Gate','-metadata','artist=Backyard Rehatched',
                        str(music.OUT/'event.ogg')],check=True)
    manifest = music.OUT/'manifest.json'
    content = json.loads(manifest.read_text())
    entry = {'file':'event.ogg','duration':round(len(data)/music.RATE,3),'loop':True,
             'samplePeak':round(float(np.max(np.abs(data))),4),
             'rmsDbfs':round(float(10*np.log10(np.mean(data**2))),2)}
    content['files'] = [row for row in content['files'] if row['file']!='event.ogg']+[entry]
    manifest.write_text(json.dumps(content,indent=2)+'\n')

    write_quake()
