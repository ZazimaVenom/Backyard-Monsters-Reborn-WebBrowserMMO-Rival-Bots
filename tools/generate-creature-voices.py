"""Build the original battle creature calls. Runtime only needs the resulting Ogg files."""
from pathlib import Path
import hashlib
import json
import math
import subprocess
import tempfile
import wave
import zlib

import numpy as np
from scipy import signal

DEST = Path(__file__).resolve().parents[1] / 'public/assets/voices'
RATE = 44100

# Each voice has its own register, duration, rhythm, vocal tract and harmonic source.
# Resonances represent mouth/throat sizes rather than dialogue or human words.
# Format: family, fundamental, call length, number of syllables, formant multiplier, vibrato Hz.
PROFILES = {
    'pokey': ('peep', 308, .48, 2, 1.18, 9),
    'octo': ('bubble', 177, .70, 3, .91, 4),
    'bolt': ('chirr', 429, .43, 3, 1.13, 11),
    'fink': ('chirr', 242, .65, 2, .88, 7),
    'eyera': ('bubble', 347, .53, 1, 1.23, 10),
    'ichi': ('growl', 111, .72, 2, .81, 8),
    'bandito': ('growl', 69, .82, 1, .74, 4),
    'fang': ('hiss', 222, .54, 3, 1.07, 13),
    'brain': ('pulse', 201, .68, 3, 1.16, 5),
    'crabatron': ('shell', 128, .69, 3, .89, 7),
    'projectx': ('reed', 204, .65, 2, .99, 6),
    'dave': ('machine', 84, .86, 2, .76, 7),
    'wormzer': ('rasp', 94, .79, 2, .86, 5),
    'teratorn': ('wing', 301, .70, 2, 1.22, 13),
    'zafreeti': ('spectral', 269, .84, 2, 1.19, 4),
    'vorg': ('wing', 188, .63, 3, 1.03, 10),
    'slimeattikus': ('bubble', 76, .90, 2, .72, 4),
    'slimeattikusmini': ('peep', 482, .41, 2, 1.29, 13),
    'rezghul': ('spectral', 112, .94, 2, .78, 4),
    'spurtz': ('flame', 255, .45, 3, 1.11, 10),
    'zagnoid': ('bubble', 128, .75, 2, 1.05, 7),
    'malphus': ('shell', 159, .75, 2, .83, 9),
    'valgos': ('growl', 92, .82, 3, .87, 10),
    'balthazar': ('reed', 295, .76, 2, .88, 6),
    'grokus': ('reed', 123, .88, 3, .91, 5),
    'sabnox': ('hiss', 147, .82, 2, .88, 8),
    'kingwormzer': ('machine', 57, 1.04, 3, .69, 4),
    'gorgo': ('growl', 56, 1.04, 2, .65, 5),
    'drull': ('rasp', 124, 1.00, 2, 1.06, 9),
    'fomor': ('spectral', 151, 1.07, 3, .93, 6),
    'korath': ('stone', 52, 1.11, 2, .67, 4),
    'krallen': ('rasp', 165, .82, 2, .96, 11),
}
# Lower formant, upper formant, breathiness, pulse roughness and voice coloration.
FAMILIES = {
    'peep': (920, 2480, .06, .03), 'bubble': (470, 1230, .10, .06),
    'chirr': (1240, 3160, .23, .30), 'growl': (385, 970, .22, .32),
    'hiss': (1030, 2920, .74, .09), 'pulse': (735, 2100, .07, .19),
    'shell': (565, 2260, .24, .26), 'reed': (635, 1820, .18, .11),
    'machine': (405, 1470, .08, .46), 'rasp': (580, 1750, .39, .37),
    'wing': (810, 2540, .20, .14), 'spectral': (605, 1940, .27, .04),
    'flame': (770, 2900, .59, .18), 'stone': (320, 1125, .24, .41),
}

def resonator(samples, frequency, width):
    b, a = signal.iirpeak(frequency, frequency / width, fs=RATE)
    return signal.lfilter(b, a, samples)

def synthesize(key, variant):
    family, f0, seconds, syllables, tract, vibrato = PROFILES[key]
    f1, f2, breath, rough = FAMILIES[family]
    falling = variant == 'defeat'
    seed = zlib.crc32((key+variant).encode())
    rng = np.random.default_rng(seed)
    duration = seconds * (1.09 if falling else 1)
    t = np.arange(round(duration * RATE)) / RATE
    x = t / duration
    contour = np.interp(x, [0, .14, .42, .76, 1],
                        [1.16, 1.37, .87, .65, .48] if falling else [.80, 1.19, .96, 1.24, .91])
    syllable_count = max(1, syllables - int(falling))
    phase = np.minimum(x * syllable_count, syllable_count - .00001)
    phrase = phase % 1
    attack = .017 if family in ('chirr','peep','shell','machine') else .042
    pulse = np.minimum(1, phrase * duration / syllable_count / attack)
    pulse *= np.maximum(0, 1 - np.maximum(0, (phrase - (.70 if falling else .66)) / .34))
    pulse *= (1 - .19*phase/syllable_count) if falling else (.77 + .23*phase/syllable_count)
    random_walk = np.interp(x, np.linspace(0,1,35),rng.uniform(-1,1,35))
    freq = f0 * contour * (1 + .042*np.sin(t*2*math.pi*vibrato) + .019*random_walk)
    phi = np.cumsum(freq) / RATE
    voice = signal.sawtooth(2*np.pi*phi,width=.27+.12*np.sin(2*np.pi*x))
    voice *= 1 - rough*.52 + rough*(.34*np.sin(np.pi*phi) + .18*np.sin(2*np.pi*phi*.341))
    noise = rng.normal(0,1,len(t))
    high = signal.sosfilt(signal.butter(2, [600,8500],fs=RATE,btype='bandpass',output='sos'),noise)
    stream = voice*(1 - .32*breath) + high*breath*(.52+.48*np.maximum(0,np.sin(2*np.pi*phi)))
    color_open = 4.5*resonator(stream,f1*tract,120) + 3.6*resonator(stream,f2*tract,260)
    color_closed = 5*resonator(stream,f1*tract*.76,95) + 3*resonator(stream,f2*tract*.82,190)
    openness = np.sin(np.pi*phrase)**.8 * (1-.40*x if falling else 1)
    y = .40*voice + .63*(openness*color_open + (1-openness)*color_closed)
    if family == 'bubble':
        y += .40*np.sin(2*np.pi*(phi*.52 + .41*np.sin(2*np.pi*t*(12+tract*4))))**3
    elif family in ('rasp','growl','stone'):
        y += (.36*np.sin(np.pi*phi) + .20*np.sin(2*np.pi*phi*.252)) * (1+.35*np.sin(2*np.pi*t*(19+vibrato)))
    elif family in ('chirr','wing','shell'):
        y *= .57 + .43*(.5+.5*np.sin(2*np.pi*t*(22+3*vibrato)))**2
        y += .24*resonator(high,f2*tract,650) * np.exp(-phrase*(9 if family=='shell' else 6))
    elif family in ('hiss','flame'):
        y += high*(.22+.32*np.sin(np.pi*phrase))
    elif family == 'machine':
        y += .36*np.sin(2*np.pi*phi*1.501 + 1.8*np.sin(2*np.pi*phi*2.02))
    elif family in ('pulse','spectral','reed'):
        y += (.26*np.sin(2*np.pi*phi*1.50) + .16*np.sin(2*np.pi*phi*2.02)) * (.7+.3*openness)
    y *= pulse*np.minimum(1,t/.009)*np.minimum(1,np.maximum(0,duration-t)/.047)
    left = np.pad(y,(0,round(.058*RATE)))
    right = left.copy()
    for channel,delay,level in ((left,.031,.08),(right,.019,.075),(right,.049,.035)):
        d=round(RATE*delay); channel[d:d+len(y)] += level*y
    stereo = np.column_stack([left,right])
    stereo = signal.sosfilt(signal.butter(2,55,fs=RATE,btype='highpass',output='sos'),stereo,axis=0)
    stereo = np.tanh(stereo*1.32)
    return stereo*.77/max(np.max(np.abs(stereo)), .001)

def main():
    DEST.mkdir(exist_ok=True)
    catalog=[]
    with tempfile.TemporaryDirectory() as work:
        pcm=Path(work)/'input.wav'
        for species,profile in PROFILES.items():
            for variant in ('call','defeat'):
                audio=synthesize(species,variant)
                with wave.open(str(pcm),'wb') as w:
                    w.setnchannels(2);w.setsampwidth(2);w.setframerate(RATE)
                    w.writeframes((audio*32767).astype('<i2').tobytes())
                file=DEST/f'{species}-{variant}.ogg'
                subprocess.run(['ffmpeg','-nostdin','-y','-loglevel','error','-i',str(pcm),'-c:a','libvorbis','-q:a','5','-map_metadata','-1',str(file)],check=True)
                catalog.append({'file':file.name,'species':species,'cue':variant,'family':profile[0],
                                'seconds':round(len(audio)/RATE,4),'bytes':file.stat().st_size,
                                'sha256':hashlib.sha256(file.read_bytes()).hexdigest()})
    (DEST/'manifest.json').write_text(json.dumps({'sampleRate':RATE,'channels':2,'files':catalog},indent=2)+'\n')

if __name__=='__main__':
    main()
