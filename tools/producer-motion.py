"""Render local machinery loops from the existing HD paintings. Run from any folder.

Each region is registered to its own tier illustration. Only the machinery patch
is packaged. The renderer keeps the remainder of the original full-resolution art.
"""
from concurrent.futures import ProcessPoolExecutor
from pathlib import Path
from PIL import Image
from scipy.ndimage import map_coordinates
import numpy as np
import json
import math
import sys

ASSETS = Path(__file__).resolve().parents[1] / 'public/assets'
OUTPUT = ASSETS / 'producer-motion'
SOURCE_FRAMES = {'twig':34,'pebble':26,'putty':26,'goo':26,'inferno-twig':47,'inferno-pebble':47,'inferno-putty':51,'inferno-goo':49,'hatchery':30,'inferno-hatchery':150}

# Centers in the alpha-cropped painting, in tier order. An A/B pair identifies
# separate driven components in the same machine.
CENTERS = {
    'hatchery': ([(.55,.21),(.55,.21),(.45,.22),(.47,.22),(.48,.20)],[]),
    'inferno-hatchery': ([(.486,.472),(.47,.459),(.448,.46),(.465,.44),(.463,.445)],[]),
    'twig': ([(.622,.643),(.613,.65),(.485,.658),(.565,.645),(.504,.636)],[]),
    'pebble': ([(.281,.435),(.35,.452),(.364,.469),(.389,.465),(.39,.473)],[]),
    'putty': ([(.531,.435),(.532,.44),(.556,.377),(.56,.38),(.541,.348)],
              [(.159,.621),(.161,.631),(.189,.631),(.209,.618),(.251,.617)]),
    'goo': ([(.39,.432),(.39,.425),(.38,.418),(.373,.411),(.353,.411)],
            [(.18,.499),(.19,.51),(.166,.518),(.178,.51),(.210,.517)]),
    'inferno-twig': ([(.458,.436),(.435,.427),(.444,.411),(.469,.408),(.481,.448)],
                     [(.627,.376),(.622,.38),(.606,.376),(.622,.39),(.63,.397)]),
    'inferno-pebble': ([(.568,.135),(.57,.141),(.533,.149),(.563,.158),(.563,.152)],
                       [(.568,.481),(.569,.473),(.537,.474),(.543,.481),(.554,.49)]),
    'inferno-putty': ([(.428,.461),(.43,.462),(.409,.476),(.401,.466),(.367,.469)],
                      [(.703,.345),(.679,.347),(.651,.39),(.66,.407),(.65,.427)]),
    'inferno-goo': ([(.539,.222),(.55,.222),(.539,.209),(.56,.214),(.57,.218)],
                    [(.21,.61),(.224,.609),(.244,.609),(.253,.606),(.264,.59)]),
}


def displacement(family, i, x, y, t):
    ax, ay = CENTERS[family][0][i]
    second = CENTERS[family][1]
    bx, by = second[i] if second else (ax,ay)
    dx, dy = np.zeros_like(x), np.zeros_like(y)
    phase = 2*np.pi*t

    def window(cx, cy, rx, ry):
        r = ((x-cx)/rx)**2 + ((y-cy)/ry)**2
        w = np.clip((1-r)*3,0,1)
        return w*w*(3-2*w)

    def wheel(cx,cy,rx,ry,revolutions):
        # Rotate just the painted toothed rim; its stationary hub is preserved.
        u, v = (x-cx)/rx,(y-cy)/ry
        r = np.hypot(u,v)
        wt = window(cx,cy,rx,ry)*np.clip((r-.36)*4,0,1)
        # The visible spindle and its fork stay still. Successive teeth catch
        # the highlight as the rim advances through a fraction of a tooth.
        if family=='twig':
            fork=np.clip(u*7,0,1)*np.clip((.37-np.abs(v))/.12,0,1)
            wt*=1-fork
        angle = (.095 if family=='twig' else .18)*np.sin(phase*(5 if family=='twig' else 3))*revolutions
        dx[:] += (u*np.cos(angle)-v*np.sin(angle)-u)*rx*wt
        dy[:] += (u*np.sin(angle)+v*np.cos(angle)-v)*ry*wt

    def liquid(cx,cy,rx,ry):
        wt = window(cx,cy,rx,ry)
        dx[:] += .010*(np.sin(phase*2+8*(y-cy)/ry)-np.sin(8*(y-cy)/ry))*wt
        dy[:] += .006*(np.sin(phase*2+10*(x-cx)/rx)-np.sin(10*(x-cx)/rx))*wt

    if family=='hatchery':
        # The upper shell lifts above the lit nesting tray. Keep the pipe fixed.
        cover = window(ax,ay,.345,.197)
        dy -= .044*(1-np.cos(phase))*cover
        liquid(ax,ay+.24,.197,.064)
    elif family=='inferno-hatchery':
        # BUILDING13 wraps the primary mechanism after 30 source frames. The
        # native secondary strip continues through 50, reuniting after 150.
        cradle = window(ax,ay,.203,.138)
        dx += (x-ax)*.068*np.sin(5*phase)*cradle
        dy += (y-ay)*.115*np.sin(5*phase)*cradle
        dy += .017*(1-np.cos(3*phase))*window(ax-.079,ay-.022,.073,.118)
    elif family=='twig':
        wheel(ax,ay,.10,.106,2)
    elif family=='pebble':
        wheel(ax,ay,.078,.085,1)
        drum = window(ax+.255,ay-.036,.147,.143)
        dx += .012*np.sin(phase*3)*drum
        dy += .014*(np.cos(phase*3)-1)*drum
    elif family=='putty':
        dy += -.049*(1-np.cos(phase))*window(ax,ay-.066,.174,.263)
        liquid(ax,ay+.154,.185,.061)
        wheel(bx,by,.068,.085,-1)
    elif family=='goo':
        liquid(ax,ay,.177,.098)
        liquid(ax+.302,ay+.145,.13,.061)
        wheel(bx,by,.071,.061,1)
        dx += .017*np.sin(phase)*window(ax+.066,.075,.18,.12)
    elif family=='inferno-twig':
        for cx,cy,sgn in [(ax,ay,1),(bx,by,-1)]:
            weight = window(cx,cy,.117,.111)
            dy += .030*sgn*(np.sin(phase*3+(x-cx)*38)-np.sin((x-cx)*38))*weight
            dx += .008*np.sin(phase*3)*weight
        claw = window(.937,.398,.086,.23)
        dx += .020*np.sin(phase)*claw
        dy += .028*(1-np.cos(phase))*claw
    elif family=='inferno-pebble':
        wheel(ax,ay,.069,.066,1)
        dy += -.073*(1-np.cos(phase))*window(bx,by-.035,.104,.267)
    elif family=='inferno-putty':
        liquid(ax,ay,.162,.073)
        liquid(bx,by,.132,.064)
        dy += .017*np.sin(phase*2)*window(ax,ay-.098,.063,.119)
        dx += .026*np.sin(phase)*window(.113,.106,.119,.129)
    elif family=='inferno-goo':
        # The pumpjack pivots at its fixed trunnion. The horse head travels
        # farthest; the connecting rod follows the same stroke into the well.
        wt = np.clip((.61-y)/.18,0,1)*np.clip((x-.005)/.09,0,1)*np.clip((.98-x)/.14,0,1)
        dy -= (ax-x)*.102*(1-np.cos(phase))*wt
        dy -= .012*(1-np.cos(phase))*window(bx,by-.153,.07,.175)
        liquid(bx,by,.145,.061)
    return dx,dy


def render(job):
    realm,type_,level = job
    family = ('inferno-' if realm=='inferno' else '')+type_
    key = family if level==1 else f'{realm}-{type_}-{level}'
    path = ASSETS/('art' if realm=='inferno' else 'atlas')/(family+'.webp') if level==1 else ASSETS/'building-tiers'/(key+'.webp')
    image = Image.open(path).convert('RGBA')
    left,top,right,bottom=image.getchannel('A').point(lambda p: 255 if p>14 else 0).getbbox()
    sw,sh=right-left,bottom-top
    # Locate the exact changing patch across this tier's whole source cycle.
    w,h=270,round(270*sh/sw)
    yy,xx=np.mgrid[0:h,0:w]
    x,y=xx/w,yy/h
    active=np.zeros(x.shape,dtype=bool)
    for t in (.037,.121,.263,.387,.516,.773):
        dx,dy=displacement(family,level-1,x,y,t)
        active|=(np.abs(dx)+np.abs(dy)>1e-7)
    iy,ix=np.where(active)
    sx=max(0,ix.min()-2);ex=min(w,ix.max()+3);sy=max(0,iy.min()-2);ey=min(h,iy.max()+3)
    # Exact pixel registration in the native full-size painting.
    box=(int(left+sx/w*sw),int(top+sy/h*sh),math.ceil(left+ex/w*sw),math.ceil(top+ey/h*sh))
    sx,sy,ex,ey=box
    tw=max(2,round((ex-sx)*350/sw));th=max(2,round((ey-sy)*350/sw))
    yy,xx=np.mgrid[0:th,0:tw]
    x=(sx+(xx+.5)*(ex-sx)/tw-left)/sw
    y=(sy+(yy+.5)*(ey-sy)/th-top)/sh
    original=np.asarray(image,dtype=np.float32)
    original[:,:,:3]*=original[:,:,3:4]/255.
    n=SOURCE_FRAMES[family]
    atlas=Image.new('RGBA',(8*tw,math.ceil(n/8)*th))
    for f in range(n):
        dx,dy=displacement(family,level-1,x,y,f/n)
        coords=[top+(y+dy)*sh-.5,left+(x+dx)*sw-.5]
        sampled=np.stack([map_coordinates(original[:,:,i],coords,order=1,mode='constant',cval=0,prefilter=False) for i in range(4)],axis=2)
        sampled[:,:,:3]*=255/np.maximum(sampled[:,:,3:4],.001)
        cell=Image.fromarray(np.rint(np.clip(sampled,0,255)).astype('uint8'),'RGBA')
        atlas.paste(cell,(f%8*tw,f//8*th))
    atlas.save(OUTPUT/(key+'.webp'),quality=83,method=2,alpha_quality=92)
    return key,[sx,sy,ex-sx,ey-sy,tw,th,n]


if __name__=='__main__':
    OUTPUT.mkdir(exist_ok=True)
    jobs=[(realm,kind,level) for realm in ('surface','inferno') for kind in ('twig','pebble','putty','goo','hatchery') for level in range(1,6)]
    if len(sys.argv)>1:
        jobs=[job for job in jobs if job[1] in sys.argv[1:]]
    with ProcessPoolExecutor(max_workers=4) as pool:
        rows=dict(pool.map(render,jobs))
    data=ASSETS/'producer-frames.js'
    if len(sys.argv)>1 and data.exists():
        old=json.loads(data.read_text().split('= ',1)[1].strip().rstrip(';'))
        rows=old|rows
    data.write_text('// Generated native-paint registrations: source x/y/w/h, frame width/height, source frame count.\nexport const producerFrames = '+json.dumps(rows,indent=2)+';\n')
