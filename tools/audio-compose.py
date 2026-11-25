#!/usr/bin/env python3
"""Compose the game's original soundtrack and effects from deterministic oscillators.

Requires Python 3, NumPy, SciPy and ffmpeg with libvorbis. No samples or external
music are used. Run from any directory; outputs are relative to this source file.
"""
from __future__ import annotations
import argparse, json, math, pathlib, shutil, subprocess, tempfile, wave
import numpy as np
from scipy.signal import butter, sosfilt

RATE = 44100
ROOT = pathlib.Path(__file__).resolve().parents[1]
OUT = ROOT / 'public' / 'assets' / 'audio'
RNG = np.random.default_rng(821740)


def hz(midi: float) -> float:
    return 440.0 * 2.0 ** ((midi - 69) / 12)


def envelope(n: int, attack: float = .015, release: float = .10) -> np.ndarray:
    a = np.ones(n)
    na, nr = min(n, int(attack * RATE)), min(n, int(release * RATE))
    if na: a[:na] *= np.sin(np.linspace(0, np.pi/2, na)) ** 2
    if nr: a[-nr:] *= np.cos(np.linspace(0, np.pi/2, nr)) ** 2
    return a


def filtered_noise(n: int, cutoff: float, high: bool = False) -> np.ndarray:
    return sosfilt(butter(2, cutoff, btype='highpass' if high else 'lowpass', fs=RATE, output='sos'), RNG.normal(size=n))


def voice(kind: str, note: float, duration: float, gain: float = 1) -> np.ndarray:
    n = max(4, int(duration * RATE)); t = np.arange(n) / RATE; f = hz(note)
    if kind == 'marimba':
        # Wooden bars have softly inharmonic, rapidly fading upper partials.
        x = (np.sin(2*np.pi*f*t)*np.exp(-t/1.1) + .34*np.sin(2*np.pi*f*3.98*t)*np.exp(-t/.12)
             + .13*np.sin(2*np.pi*f*9.1*t)*np.exp(-t/.045))
        x *= envelope(n,.002,.12)
    elif kind == 'pluck':
        x = sum(np.sin(2*np.pi*f*k*t+.008*np.sin(2*np.pi*4.4*t))/k**1.8 * np.exp(-t*(1.6+k*.7)) for k in range(1,7))
        x *= envelope(n,.005,.10)
    elif kind == 'flute':
        phase = 2*np.pi*f*t + .055*np.sin(2*np.pi*5.2*t)*np.minimum(t/.20,1)
        x = (np.sin(phase) + .16*np.sin(phase*2) + .035*np.sin(phase*3))
        breath = filtered_noise(n,2200)*.035
        x = (x+breath) * envelope(n,.065,.14) * (.9+.1*np.exp(-t))
    elif kind == 'pad':
        x = sum((np.sin(2*np.pi*f*k*1.0017*t) + np.sin(2*np.pi*f*k*.9983*t))*.5/k**2.3 for k in range(1,5))
        x *= envelope(n,.40,.65) * (.85+.15*np.sin(2*np.pi*.21*t))
    elif kind == 'bass':
        x = (np.sin(2*np.pi*f*t) + .20*np.sin(4*np.pi*f*t)) * envelope(n,.015,.12) * np.exp(-t*.9)
    elif kind == 'bell':
        x = sum(g*np.sin(2*np.pi*f*k*t)*np.exp(-t/d) for k,g,d in [(1,1,.8),(2,.36,.5),(3.01,.12,.25),(5.02,.06,.12)])
        x *= envelope(n,.003,.12)
    else: raise ValueError(kind)
    return np.asarray(x*gain, dtype=np.float64)


def drum(kind: str, gain: float = 1) -> np.ndarray:
    duration = {'kick':.35,'snare':.19,'hat':.09,'wood':.15}[kind]
    n=int(RATE*duration); t=np.arange(n)/RATE
    if kind=='kick':
        f = 50 + 115*np.exp(-t*35)
        x=np.sin(2*np.pi*np.cumsum(f)/RATE)*np.exp(-t*16) + filtered_noise(n,1100)*.12*np.exp(-t*70)
    elif kind=='snare':
        x=filtered_noise(n,1900,True)*np.exp(-t*28)*.55 + np.sin(2*np.pi*180*t)*np.exp(-t*33)*.20
    elif kind=='hat': x=filtered_noise(n,6500,True)*np.exp(-t*55)*.40
    else: x=(np.sin(2*np.pi*710*t)+.35*np.sin(2*np.pi*1030*t))*np.exp(-t*55)
    return x*envelope(n,.001,.02)*gain


class Track:
    def __init__(self,duration: float):
        self.data=np.zeros((round(duration*RATE),2),dtype=np.float64)
        self.duration=duration
    def add(self, signal: np.ndarray, start: float, pan: float = 0, wrap: bool=True):
        # Equal-power panning and a circular timeline create seamless loop tails.
        stereo=np.stack([signal*math.sqrt((1-pan)/2),signal*math.sqrt((1+pan)/2)],axis=-1)
        at=round(start*RATE); total=len(self.data)
        if wrap:
            for pos in range(0,len(stereo),total):
                part=stereo[pos:pos+total]; idx=(at+pos)%total; first=min(len(part),total-idx)
                self.data[idx:idx+first]+=part[:first]
                self.data[:len(part)-first]+=part[first:]
        elif at<total:
            self.data[max(at,0):min(at+len(stereo),total)]+=stereo[max(0,-at):min(len(stereo),total-at)]
    def note(self,kind,note,start,duration,gain=.1,pan=0):
        self.add(voice(kind,note,duration,gain),start,pan)
    def finish(self,room=.15,peak=.88):
        dry=self.data.copy()
        # Sparse stereo reflections; circular convolution preserves music loops.
        for delay,gain in [(.079,.36),(.131,.28),(.223,.20),(.347,.13),(.509,.08)]:
            taps=np.roll(dry,round(delay*RATE),axis=0)
            taps=taps[:,::-1] if int(delay*1000)%2 else taps
            self.data+=sosfilt(butter(1,4600,fs=RATE,output='sos'),taps,axis=0)*gain*room
        self.data=np.tanh(self.data*1.1)
        self.data*=peak/max(.001,float(np.max(np.abs(self.data))))
        return self.data


def woodland():
    """24 bars at 90 BPM: 64 seconds, D-minor warmth with a handwritten motif."""
    beat=60/90; track=Track(96*beat)
    chords=[(50,[62,65,69,72]),(46,[62,65,69,70]),(53,[60,64,69,72]),(48,[60,64,67,74]),
            (50,[62,65,69,76]),(43,[62,65,67,70]),(46,[62,65,69,70]),(45,[61,64,67,69])]
    # Beat offset, MIDI pitch, held beats. Three phrases receive distinct endings.
    melody=[[(.5,74,.7),(1.5,77,.9),(3,76,.6)],[(0,74,1.4),(2,69,.8),(3,72,.8)],
            [(.5,72,.9),(2,76,.6),(3,77,.8)],[(0,79,1.2),(1.75,76,.7),(3,74,.8)],
            [(0,77,.8),(1.25,81,.9),(2.5,79,.8)],[(.5,77,.8),(2,74,1.3)],
            [(0,72,.7),(1,74,.7),(2,77,1.1)],[(0,76,.8),(1.5,73,.8),(3,69,.7)]]
    for bar in range(24):
        root,chord=chords[bar%8]; when=bar*4*beat; section=bar//8
        for k,note in enumerate(chord): track.note('pad',note-12,when,4*beat+.75,.036,(-.6,-.2,.2,.6)[k])
        track.note('bass',root-12,when,1.9*beat,.145,-.08)
        track.note('bass',root-5,when+2*beat,1.7*beat,.105,.08)
        arpeggio=[0,2,1,3,1,2,0,2]
        for pulse,k in enumerate(arpeggio):
            swing=.055*beat if pulse%2 else 0
            note=chord[k]+(12 if section==1 and pulse in (3,7) else 0)
            track.note('marimba',note,when+pulse*.5*beat+swing,1.25,.075 if pulse%2==0 else .045,-.35)
        if bar%8 not in (0,) or section>0:
            for offset,note,hold in melody[bar%8]:
                if section==2 and bar%8==6: note+=12
                track.note('flute',note,when+offset*beat,hold*beat,.072,.27)
        if section>0 or bar>3:
            for p in [1,3]: track.add(drum('wood',.038),when+p*beat,.55)
            for p in np.arange(.5,4,1): track.add(drum('hat',.055),when+p*beat,-.5)
        # Delicate, sparse high plucks leave room for game sound effects.
        if bar%2==1:
            track.note('pluck',chord[1]+12,when+3.5*beat,1.3,.038,.65)
    return track.finish(.35,.85)


def battle():
    """24 bars at 120 BPM: 48 seconds. A rhythmic answer to the woodland theme."""
    beat=.5; track=Track(96*beat)
    chords=[(38,[62,65,69]),(34,[62,65,70]),(41,[60,65,69]),(36,[60,64,67]),
            (38,[62,65,69]),(31,[62,67,70]),(34,[62,65,70]),(33,[61,64,69])]
    riffs=[[74,77,76,74,69,72,74,77],[74,70,74,77,81,77,74,70],
           [72,77,81,79,77,76,72,69],[72,76,79,76,74,72,67,72],
           [74,77,81,84,81,79,77,74],[74,79,82,81,79,77,74,70],
           [74,77,81,77,74,70,74,77],[73,76,81,76,73,69,73,76]]
    for bar in range(24):
        root,chord=chords[bar%8]; when=bar*4*beat; section=bar//8
        for i,note in enumerate(chord): track.note('pad',note-12,when,4*beat+.7,.029,[-.65,0,.65][i])
        for pulse in range(8):
            n=root if pulse%4<2 else root+7
            track.note('bass',n,when+pulse*.5*beat,.30,.13 if pulse%2==0 else .085,-.05)
            ar=chord[(pulse+bar)%3]
            track.note('pluck',ar,when+pulse*.5*beat,.48,.068,-.4)
            track.add(drum('hat',.13 if pulse%2==0 else .085),when+pulse*.5*beat,.42)
        for p in [0,2]: track.add(drum('kick',.15),when+p*beat)
        for p in [1,3]: track.add(drum('snare',.13),when+p*beat,-.12)
        for pulse,note in enumerate(riffs[bar%8]):
            if section==0 and bar<2 and pulse%2: continue
            if section==2 and bar%8<4: note+=12
            track.note('marimba',note,when+pulse*.5*beat,.61,.085,.22)
        if section==1 or section==2 and bar%2==0:
            for offset,note in [(0,riffs[bar%8][0]),(1.5,riffs[bar%8][3]),(3,riffs[bar%8][6])]:
                track.note('flute',note,when+offset*beat,.55,.063,-.18)
        if bar%4==3:
            for i in range(4): track.add(drum('wood',.045+i*.006),when+(3+i*.25)*beat,.6-i*.3)
    return track.finish(.20,.86)


def effect(name):
    durations={'build':.75,'collect':.95,'hatch':1.35,'hit':.25,'shot':.31,'victory':2.75,'defeat':2.6,'explode':1.1,'heal':.8,'burrow':.85,'laser':.5,'fling':.65,'champion':1.6,'champion-retreat':1.4,'feed':.9,'evolve':3.2,'research':1.35,'catapult':.95,'catapult-impact':1.4,'boost':1.15,'blink':.48,'venom':.6,'aerial':.48,'heavytrap':1.08,'baiter':.98,'siren':1.36}
    tr=Track(durations[name]); n=len(tr.data); t=np.arange(n)/RATE
    def add(v,start=0,pan=0):tr.add(v,start,pan,False)
    if name=='build':
        for i,note in enumerate([48,55,60]):
            add(drum('wood',.34),i*.13,(-.25,.2,0)[i]);add(voice('marimba',note,.34,.28),i*.13)
        add(voice('bell',79,.33,.10),.35,.3)
    elif name=='collect':
        for i,note in enumerate([79,84,88]):add(voice('bell',note,.7,.22),i*.075,(i-1)*.3)
    elif name=='hatch':
        f=160+720*(np.minimum(t,.8)/.8)**2
        x=np.sin(2*np.pi*np.cumsum(f)/RATE+.4*np.sin(2*np.pi*15*t))*np.exp(-((t-.5)/.27)**2)*.25
        add(x*envelope(n,.03,.15));add(drum('wood',.35),.66)
        for i,note in enumerate([74,81,86]):add(voice('bell',note,.50,.19),.68+i*.09,(i-1)*.35)
    elif name=='hit':
        x=filtered_noise(n,3000)*np.exp(-t*35)*.45+np.sin(2*np.pi*np.cumsum(90+180*np.exp(-t*40))/RATE)*np.exp(-t*25)*.32
        add(x*envelope(n,.001,.035))
    elif name=='shot':
        f=360+1700*np.exp(-t*15)
        x=(np.sin(2*np.pi*np.cumsum(f)/RATE)*.5+filtered_noise(n,4500)*.20)*np.exp(-t*13)
        add(x*envelope(n,.002,.06))
    elif name=='explode':
        rumble=filtered_noise(n,750)*np.exp(-t*5)*.9
        crack=filtered_noise(n,4300,True)*np.exp(-t*32)*.25
        body=np.sin(2*np.pi*np.cumsum(42+100*np.exp(-t*14))/RATE)*np.exp(-t*7)*.6
        add((rumble+crack+body)*envelope(n,.002,.15))
    elif name=='heal':
        for i,note in enumerate([86,93,98]):add(voice('bell',note,.55,.18),i*.07,(i-1)*.35)
        add(voice('flute',81,.7,.11))
    elif name=='burrow':
        grit=filtered_noise(n,1350)*(.35+.25*np.sin(2*np.pi*17*t))*np.exp(-t*3)
        low=np.sin(2*np.pi*(75*t+5*np.sin(t*9)))*np.exp(-t*5)*.2
        add((grit+low)*envelope(n,.025,.18))
    elif name=='laser':
        phase=2*np.pi*np.cumsum(1100+1800*np.exp(-t*12))/RATE
        add((np.sin(phase)+.3*np.sin(phase*1.51))*np.exp(-t*8)*envelope(n,.003,.06)*.5)
    elif name=='fling':
        swish=filtered_noise(n,1800)*np.sin(np.pi*np.minimum(t/.5,1))**2
        twang=np.sin(2*np.pi*np.cumsum(100+420*np.exp(-t*13))/RATE)*np.exp(-t*7)*.35
        add((swish+twang)*envelope(n,.008,.12));add(drum('wood',.25),.02)
    elif name=='champion':
        # A low resonant call resolves into a small, confident ascending motif.
        f=90+48*np.exp(-t*4)+5*np.sin(2*np.pi*4*t)
        roar=(np.sin(2*np.pi*np.cumsum(f)/RATE)+.24*filtered_noise(n,900))*np.exp(-t*3)*.28
        add(roar*envelope(n,.025,.3))
        for i,note in enumerate([50,57,62,69]):add(voice('marimba',note,.9,.23),.13+i*.12,(i-1.5)*.17)
        add(voice('bell',81,.8,.10),.56)
    elif name=='champion-retreat':
        for i,note in enumerate([69,64,57]):add(voice('flute',note,.75,.24),i*.18,(1-i)*.2)
        add(voice('bell',81,.55,.11),.65)
    elif name=='feed':
        for i,note in enumerate([48,52,55]):
            add(drum('wood',.27),i*.14,(-.2,.2,0)[i]);add(voice('marimba',note,.31,.20),i*.14)
        add(voice('bell',76,.45,.15),.39)
    elif name=='evolve':
        for i,note in enumerate([60,64,67,72,76,79,84]):
            add(voice('bell',note,1.5,.19),i*.12,(i-3)*.13)
            add(voice('flute',note,1.1,.06),i*.12)
        for note in [48,55,60,64,67]:add(voice('pad',note,2.3,.13),.7)
    elif name=='research':
        # Glassy laboratory bubbles resolve into a warm discovery motif.
        for i,note in enumerate([72,79,76,84]):
            add(voice('bell',note,.7,.18),i*.13,(i-1.5)*.25)
        bubbles=np.sin(2*np.pi*(310*t+36*np.sin(t*18)))*np.exp(-t*7)*.12
        add(bubbles*envelope(n,.01,.15))
    elif name=='catapult':
        # Tensioning timber, rope release, then the departing payload's air rush.
        creak=(np.sin(2*np.pi*83*t+.6*np.sin(2*np.pi*23*t))+.15*filtered_noise(n,800))*np.exp(-t*8)*.28
        rush=filtered_noise(n,2100)*np.sin(np.pi*np.minimum(t/.75,1))**2*.45
        add((creak+rush)*envelope(n,.025,.12))
        add(drum('wood',.4),.04,-.2);add(drum('wood',.2),.16,.2)
    elif name=='catapult-impact':
        body=np.sin(2*np.pi*np.cumsum(38+92*np.exp(-t*16))/RATE)*np.exp(-t*6)*.5
        debris=filtered_noise(n,1700)*np.exp(-t*4.5)*.65
        snap=filtered_noise(n,5100,True)*np.exp(-t*31)*.18
        add((body+debris+snap)*envelope(n,.002,.2))
        for i in range(4):add(drum('wood',.13/(i+1)),.13+i*.11,(i%2-.5)*.8)
    elif name=='boost':
        for i,note in enumerate([76,81,88,93]):add(voice('bell',note,.7,.18),i*.08,(i-1.5)*.25)
        add(voice('flute',69,.9,.11))
    elif name=='blink':
        phase=2*np.pi*np.cumsum(370+3300*np.exp(-t*22))/RATE
        snap=(np.sin(phase)+.15*filtered_noise(n,4200))*np.exp(-t*15)*.4
        add(snap*envelope(n,.001,.07));add(voice('bell',93,.25,.08),.08,.3)
    elif name=='venom':
        hiss=filtered_noise(n,3300,True)*np.exp(-t*10)*.21
        bubble=np.sin(2*np.pi*np.cumsum(130+390*np.exp(-t*11))/RATE)*np.exp(-t*9)*.3
        add((hiss+bubble)*envelope(n,.006,.12))
    elif name == 'aerial':
        # A compressed-air launch chirp rises into a tiny metallic burst.
        sweep = np.cumsum(650 + 2600 * np.minimum(t / .14, 1)) * (2*np.pi/RATE)
        chirp = (np.sin(sweep) + .24*np.sin(2*sweep)) * np.exp(-15*t)
        add(chirp * envelope(n, .003, .05) * .36, pan=-.18)
        for hz, pan in [(1480, -.16), (1970, .24)]:
            chime = np.sin(2*np.pi*hz*t + .9*np.sin(2*np.pi*390*t))
            add(chime * np.exp(-42*t) * envelope(n, .001, .06) * .32, .27, pan)
    elif name == 'heavytrap':
        # The latch clicks, the plate strikes, then its steel frame resonates.
        for pitch, onset, weight, pan in [(790,.0,.3,-.2), (590,.033,.22,.23)]:
            click = np.sin(2*np.pi*pitch*t + 2.1*np.sin(2*np.pi*960*t))
            add(click * np.exp(-67*t) * envelope(n, .001, .035) * weight, onset, pan)
        impact_phase = 2*np.pi*np.cumsum(48 + 86*np.exp(-18*t))/RATE
        add(np.sin(impact_phase)*np.exp(-7*t)*envelope(n,.001,.08)*.64, .058)
        for pitch, decay, pan in [(135,7,-.22), (301,9,.23), (485,13,-.08), (713,18,.18)]:
            mode = np.sin(2*np.pi*pitch*t + .18*np.sin(2*np.pi*33*t))
            add(mode*np.exp(-decay*t)*envelope(n,.003,.09)*.16, .06, pan)
    elif name == 'baiter':
        # A brass valve opens, followed by a short two-stage puff from the can.
        # Local seed makes this cue reproducible both alone and in the whole score.
        rng = np.random.default_rng(19019)
        for onset, length, cutoff, pan in [(.07, .22, 3700, -.2), (.32, .56, 2300, .18)]:
            size = round(length * RATE)
            air = sosfilt(butter(2, [620, cutoff], btype='bandpass', fs=RATE, output='sos'), rng.normal(size=size))
            add(air * envelope(size, .035, .15) * np.exp(-np.linspace(0, 2.1, size)) * .46, onset, pan)
        add(drum('wood', .25))
        add(np.sin(2*np.pi*365*t)*np.exp(-22*t)*envelope(n,.005,.09)*.24,.31)
    elif name == 'siren':
        # Two descending rotary horn calls, with slight rotor flutter and a gentle release.
        for onset, length, start_hz, end_hz, pan in [(0, .55, 480, 315, -.2), (.55, .75, 408, 223, .2)]:
            size = round(length * RATE)
            u = np.arange(size) / size
            pitch = start_hz + (end_hz-start_hz)*(.5-.5*np.cos(np.pi*u))
            phase = 2*np.pi*np.cumsum(pitch)/RATE
            horn = np.sin(phase) + .3*np.sin(2*phase) + .17*np.sin(3*phase)
            tremolo = .9+.1*np.sin(2*np.pi*13*u*length)
            add(horn * tremolo * envelope(size,.06,.19)*.32, onset, pan)
    elif name=='victory':
        for i,note in enumerate([72,76,79,84,88]):
            add(voice('bell',note,1.35,.22),i*.15,(i-2)*.18)
            add(voice('flute',note,1.0,.07),i*.15)
        for note in [60,64,67,72]:add(voice('pad',note,1.75,.15),.72)
    elif name=='defeat':
        for i,note in enumerate([69,65,62,57]):add(voice('marimba',note,1.4,.32),i*.24,(1.5-i)*.15)
        for note in [38,50,53,57]:add(voice('pad',note,1.9,.13),.50)
    x=tr.data
    # Effects end in silence, without the circular reverb used by looping music.
    for delay,gain in [(.047,.14),(.101,.08)]:
        k=round(delay*RATE);x[k:]+=tr.data[:-k].copy()*gain
    x*=envelope(n,.002,.06)[:,None]
    x=np.tanh(x)
    x*=.79/max(.001,float(np.max(np.abs(x))))
    return x


def write_wav(path,data):
    pcm=np.round(np.clip(data,-1,1)*32767).astype('<i2')
    with wave.open(str(path),'wb') as f:
        f.setnchannels(2);f.setsampwidth(2);f.setframerate(RATE);f.writeframes(pcm.tobytes())


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--wav-music',action='store_true',help='Keep uncompressed soundtrack WAV masters in addition to Ogg.');args=parser.parse_args()
    OUT.mkdir(parents=True,exist_ok=True)
    ffmpeg=shutil.which('ffmpeg')
    if not ffmpeg:raise SystemExit('ffmpeg with libvorbis is required to encode the soundtrack.')
    manifest={'generation':'Original deterministic synthesis; no sampled or third-party audio.','sampleRate':RATE,'channels':2,'files':[]}
    def log_file(name,data,loop):
        manifest['files'].append({'file':name,'duration':round(len(data)/RATE,3),'loop':loop,'samplePeak':round(float(np.max(np.abs(data))),4),'rmsDbfs':round(float(20*np.log10(np.sqrt(np.mean(data**2))+1e-12)),2)})
        print(name,manifest['files'][-1],flush=True)
    with tempfile.TemporaryDirectory(prefix='backyard-audio-') as td:
        for name,composer in [('theme',woodland),('battle',battle)]:
            data=composer();temp=pathlib.Path(td)/(name+'.wav');write_wav(temp,data)
            subprocess.run([ffmpeg,'-hide_banner','-loglevel','error','-y','-i',str(temp),'-c:a','libvorbis','-q:a','5','-metadata','title='+('Verdant Home' if name=='theme' else 'The Yard Awakens'),'-metadata','artist=Backyard Rehatched original synthesized score',str(OUT/(name+'.ogg'))],check=True)
            if args.wav_music:shutil.copy2(temp,OUT/(name+'.wav'))
            log_file(name+'.ogg',data,True)
        for name in ['build','collect','hatch','hit','shot','victory','defeat','explode','heal','burrow','laser','fling','champion','champion-retreat','feed','evolve','research','catapult','catapult-impact','boost','blink','venom','aerial','heavytrap','baiter','siren']:
            data=effect(name);write_wav(OUT/(name+'.wav'),data);log_file(name+'.wav',data,False)
    (OUT/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')

if __name__=='__main__':main()
