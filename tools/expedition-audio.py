"""Compose the 0.11 score and interaction sounds using the game's original instruments."""
from importlib.util import spec_from_file_location, module_from_spec
from pathlib import Path
import json
import subprocess
import tempfile
import numpy as np

spec = spec_from_file_location("instruments", Path(__file__).with_name("audio-compose.py"))
music = module_from_spec(spec)
spec.loader.exec_module(music)


def expedition():
    # Six-eight footfalls, flute calls across the stereo field, and an answering wooden bar.
    beat = 0.31
    score = music.Track(24 * 6 * beat)
    harmony = [(45, 52, 60, 64), (48, 55, 62, 67), (43, 50, 59, 62), (40, 47, 56, 62)]
    calls = [(76, 79, 81), (79, 83, 81), (74, 78, 79), (76, 74, 71),
             (81, 84, 83), (79, 76, 74), (78, 79, 74), (71, 74, 80)]
    for bar in range(24):
        at = bar * 6 * beat
        root, fifth, third, top = harmony[bar % 4]
        notes = calls[bar % 8]
        for pos, pitch in [(0,root), (3, fifth)]:
            score.note('bass', pitch, at+pos*beat, 2.5*beat, .14)
            score.add(music.drum('kick', .13), at+pos*beat, -.15)
        for pos,pitch in enumerate((third, fifth+12, top, third+7, top, fifth+12)):
            score.note('pluck',pitch,at+pos*beat,beat*1.65,.071,-.42)
        for pos,pitch in zip((0,2,3.5),notes):
            score.note('flute' if bar%2==0 else 'marimba',pitch,at+pos*beat,beat*(2 if pos==0 else 1.6),.10,.35)
        score.add(music.drum('wood',.12), at+4.5*beat, .55)
        if 8<=bar<20:
            score.note('pad',third-12,at,6.3*beat,.07,-.25)
            score.add(music.drum('hat',.13),at+5.5*beat,-.55)
        if bar%8==7:
            for pos,step in [(4,0),(5,3),(5.5,7)]:
                score.note('bell',root+24+step,at+pos*beat,beat*1.5,.05,.5)
    return score.finish(.29,.80)


def guard():
    # A deliberate four-beat watch signal leaves room for tower reports and monster calls.
    beat=.54
    score=music.Track(16*4*beat)
    roots=(38,38,34,36,38,43,34,33)
    for bar in range(16):
        root=roots[bar%8]
        at=bar*4*beat
        for pos,pitch in [(0,root),(1.5,root+12),(2.75,root+7)]:
            score.note('bass',pitch,at+pos*beat,.65,.17,-.13)
            score.add(music.drum('kick',.12),at+pos*beat,.12)
        for pos in (0,1,2.5,3.5):
            score.note('marimba',root+24+(7 if pos in (1,3.5) else 0),at+pos*beat,.5,.076,-.40)
        score.note('pad',root+19,at,4*beat+.3,.079,.4)
        if bar%4 in (1,2):
            for pos,step in [(0,24),(1.25,27),(2,31),(3.5,29)]:
                score.note('flute',root+step,at+pos*beat,beat*1.05,.087,.28)
        if bar>=6:
            for pos in (1,3):
                score.add(music.drum('snare',.105),at+pos*beat,-.23)
        if bar%4==3:
            score.note('bell',root+31,at+3*beat,1.2,.052,.55)
    return score.finish(.30,.80)


def cue(gift):
    duration = 1.15 if gift else 1.8
    score=music.Track(duration)
    if gift:
        for offset in (0,.036,.075,.11):
            score.add(music.drum('hat',.12),offset,-.22,wrap=False)
        notes=[(.14,74),(.25,81),(.45,86)]
    else:
        notes=[(.015,62),(.16,69),(.31,74),(.46,78),(.73,81),(.74,74),(.75,66)]
    for i,(offset,note) in enumerate(notes):
        sound=music.voice('bell' if i%2 else 'marimba',note,duration-offset,.21)
        score.add(sound,offset,-.32 if i%2 else .32,wrap=False)
    data=score.data*music.envelope(len(score.data),.008,.22)[:,None]
    return .64*data/np.max(np.abs(data))


if __name__ == '__main__':
    manifest_path=music.OUT/'manifest.json'
    manifest=json.loads(manifest_path.read_text())
    files=manifest['files']
    with tempfile.TemporaryDirectory() as directory:
        for name,title,composer,loop in [('expedition','Beyond the Bramble',expedition,True),('defense','Night Watch',guard,True),
                                        ('quest','Quest reward',lambda:cue(False),False),('sack','Mystery sack',lambda:cue(True),False)]:
            data=composer()
            path=music.OUT/(name+('.ogg' if loop else '.wav'))
            wav=Path(directory)/(name+'.wav') if loop else path
            music.write_wav(wav,data)
            if loop:
                subprocess.run(['ffmpeg','-v','error','-y','-i',str(wav),'-c:a','libvorbis','-q:a','5','-metadata','title='+title,str(path)],check=True)
            files[:]=[row for row in files if row['file']!=path.name]
            files.append(dict(file=path.name,duration=round(len(data)/music.RATE,3),loop=loop,
                              samplePeak=round(float(np.max(np.abs(data))),4),rmsDbfs=round(float(10*np.log10(np.mean(data**2))),2)))
    manifest_path.write_text(json.dumps(manifest,indent=2)+'\n')
