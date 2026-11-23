"""Rasterize original yard pennants and carved monster effigies at 2× resolution."""
from pathlib import Path
import math
from PIL import Image, ImageDraw, ImageOps, ImageFilter, ImageChops
import numpy as np

ROOT=Path(__file__).resolve().parents[1]/'public/assets'
OUT=ROOT/'decorations'
W,H=320,205
WHITE=(239,236,213); RED=(194,43,52); NAVY=(32,61,109); GOLD=(248,194,65)
GREEN=(43,132,86); BLACK=(43,49,53); BLUE=(40,107,171)
def star(d,x,y,r,c,n=5):
    points=[]
    for i in range(n*2):
        a=i*math.pi/n-math.pi/2; size=r if i%2==0 else r*.41
        points.append((x+math.cos(a)*size,y+math.sin(a)*size))
    d.polygon(points,fill=c)
def flag(item):
    im=Image.new('RGB',(W,H),NAVY);d=ImageDraw.Draw(im)
    def bands(cols,vertical=False):
        for i,c in enumerate(cols):
            if vertical:d.rectangle((i*W/len(cols),0,(i+1)*W/len(cols),H),fill=c)
            else:d.rectangle((0,i*H/len(cols),W,(i+1)*H/len(cols)),fill=c)
    def union(x,y,w,h):
        def line(a,b,c,v):d.line((a,b),fill=c,width=max(1,int(v*h)))
        for a,b in [((x,y),(x+w,y+h)),((x+w,y),(x,y+h))]:line(a,b,WHITE,.22)
        for a,b in [((x,y),(x+w,y+h)),((x+w,y),(x,y+h))]:line(a,b,RED,.07)
        d.rectangle((x+w*.37,y,x+w*.63,y+h),fill=WHITE);d.rectangle((x,y+h*.32,x+w,y+h*.68),fill=WHITE)
        d.rectangle((x+w*.425,y,x+w*.575,y+h),fill=RED);d.rectangle((x,y+h*.395,x+w,y+h*.605),fill=RED)
    def cross(bg,fill,inner=None):
        bands([bg]);d.rectangle((94,0,142,H),fill=fill);d.rectangle((0,H*.39,W,H*.61),fill=fill)
        if inner:d.rectangle((106,0,130,H),fill=inner);d.rectangle((0,H*.445,W,H*.555),fill=inner)
    def cres(x,y,r,color,bg):
        d.ellipse((x-r,y-r,x+r,y+r),fill=color);d.ellipse((x-r*.46,y-r*.9,x+r*1.04,y+r*.63),fill=bg)
    if item==28:
        bands([RED if i%2==0 else WHITE for i in range(13)]);d.rectangle((0,0,138,H*7/13),fill=NAVY)
        for y in range(9):
            for x in range(6-y%2):star(d,12+x*23+(y%2)*12,7+y*12,4.8,WHITE)
    elif item in [29,30,38]:
        if item==29:union(0,0,W,H)
        else:
            union(0,0,W//2,H//2)
            if item==30:star(d,76,153,24,WHITE,7)
            for x,y,r in [(241,46,13),(201,92,11),(280,85,12),(241,153,15)]+([(258,109,6)] if item==30 else []):
                if item==38:star(d,x,y,r,WHITE);star(d,x,y,r*.77,RED)
                else:star(d,x,y,r,WHITE,7)
    elif item==31:
        bands([GREEN]);d.polygon([(23,102),(160,16),(297,102),(160,190)],fill=GOLD);d.ellipse((107,49,213,155),fill=NAVY)
        d.arc((82,88,222,170),200,327,fill=WHITE,width=7)
        for i in range(15):star(d,131+i%6*11+(i//6)*2,103+(i*13)%35,2.4,WHITE)
    elif item==32:
        for i in range(12):
            a=i*math.pi/6;star(d,160+58*math.cos(a),102+58*math.sin(a),10,GOLD)
    elif item==33:bands([NAVY,WHITE,RED],True)
    elif item==34:bands([RED,WHITE])
    elif item==35:bands([GREEN,WHITE,RED],True)
    elif item==36:
        bands([RED if i%2==0 else WHITE for i in range(14)]);d.rectangle((0,0,151,H*8/14),fill=NAVY)
        cres(56,56,38,GOLD,NAVY);star(d,96,52,30,GOLD,14)
    elif item==37:bands([RED,WHITE,NAVY])
    elif item==39:cross(RED,WHITE,NAVY)
    elif item==40:bands([WHITE,RED])
    elif item==41:cross(BLUE,GOLD)
    elif item==42:bands([RED]);cres(120,103,52,WHITE,RED);star(d,180,96,24,WHITE)
    elif item==43:
        bands([RED,WHITE,WHITE,RED],True)
        leaf=[(160,38),(172,64),(180,56),(177,87),(196,72),(198,83),(218,80),(211,99),(219,105),(183,127),(188,138),(164,135),(162,167),(157,167),(157,135),(132,138),(137,127),(101,105),(109,99),(102,80),(122,83),(124,72),(144,87),(141,56),(148,64)]
        d.polygon(leaf,fill=RED)
    elif item==44:cross(RED,WHITE)
    elif item==45:bands([BLACK,RED,GOLD])
    elif item==46:
        bands([NAVY,RED]);d.polygon([(0,0),(164,102),(0,H)],fill=WHITE)
        for x,y in [(14,20),(14,185),(143,103)]:star(d,x,y,10,GOLD)
        for i in range(8):
            a=i*math.pi/4;d.line((55,102,55+38*math.cos(a),102+38*math.sin(a)),fill=GOLD,width=5)
        d.ellipse((36,83,74,121),fill=GOLD)
    elif item==47:
        bands([RED,WHITE]);cres(46,54,34,WHITE,RED)
        for i in range(5):
            a=i*2*math.pi/5-math.pi/2;star(d,87+23*math.cos(a),51+23*math.sin(a),8,WHITE)
    elif item==48:bands([RED,WHITE,RED])
    elif item==49:
        bands([BLACK])
        for a,b in [((96,164),(222,93)),((97,92),(223,164))]:
            d.line((a,b),fill=WHITE,width=10)
            for x,y in [a,b]:
                for o in (-4,4):d.ellipse((x-6+o,y-7,x+6+o,y+7),fill=WHITE)
        d.ellipse((117,35,204,118),fill=WHITE);d.rounded_rectangle((132,86,190,128),6,fill=WHITE)
        for x in [141,178]:d.ellipse((x-11,75,x+11,95),fill=BLACK)
        d.polygon([(160,88),(153,103),(167,103)],fill=BLACK)
        for x in [147,159,171]:d.line((x,115,x,131),fill=BLACK,width=3)
    else:
        bands([WHITE]);d.ellipse((99,39,222,162),outline=(50,121,98),width=13);d.line((160,45,160,158),fill=GREEN,width=12);d.line((160,103,117,145),fill=GREEN,width=12);d.line((160,103,201,144),fill=GREEN,width=12)
    return im
def miniature(item):
    im=Image.new('RGBA',(476,564));d=ImageDraw.Draw(im)
    def poly(points,c):d.polygon(points,fill=c)
    poly([(18,513),(77,481),(139,512),(77,548)],'#273e39');poly([(18,502),(76,472),(139,500),(77,535)],'#7e9581')
    poly([(18,502),(77,531),(77,544),(18,516)],'#4f6357');poly([(77,531),(139,500),(139,514),(77,544)],'#45564c')
    poly([(29,500),(75,478),(127,501),(76,524)],'#b9be9d');poly([(49,495),(76,481),(108,496),(78,510)],'#7a836e')
    for x,col in [(65,'#412e26'),(68,'#865b3d'),(72,'#af8055'),(75,'#c39c6b'),(78,'#907249'),(82,'#61452f')]:
        d.line((x,69,x,492),fill=col,width=5)
    for y in [93,110,309,319,467,475]:
        d.rounded_rectangle((62,y,86,y+5),radius=2,fill='#67513c');d.line((65,y+1,80,y+1),fill='#e4c986',width=2)
    d.ellipse((59,59,89,87),fill='#704831');d.ellipse((61,56,87,82),fill='#c49b56');d.ellipse((65,58,78,68),fill='#ffe6a3')
    f=np.asarray(flag(item));Y,X=np.indices((340,370));u=(X-5)/344
    wave=10*np.sin(u*8-.55)+5*np.sin(u*15+.7)
    top=47+u*41+wave; ht=201*(1-.045*np.sin(u*7))
    v=(Y-top)/ht; mask=(u>=0)&(u<=1)&(v>=0)&(v<=1)
    tone=.93+.13*np.cos(u*8-.65)+.058*np.cos(u*15)+.06*(1-v)
    tone+=np.where(np.mod(Y,4)==0,-.024,0);tone+=np.where(np.mod(X,4)==1,.013,0)
    tone-=np.where((v<.019)|(v>.975)|(u>.988),.10,0)
    tone+=np.where(((v>.021)&(v<.028))|((v>.956)&(v<.966)),.10,0)
    rgb=np.clip(f[np.clip((v*(H-1)).astype(int),0,H-1),np.clip((u*(W-1)).astype(int),0,W-1)]*tone[:,:,None],0,255)
    cloth=Image.fromarray(np.dstack((rgb.astype('uint8'),mask.astype('uint8')*255)),'RGBA')
    im.alpha_composite(cloth,(77,54));return im
def effigy(item,species):
    im=Image.new('RGBA',(420,500));d=ImageDraw.Draw(im)
    rock=[(55,431),(139,389),(267,386),(357,430),(327,459),(187,485),(73,455)]
    d.polygon(rock,fill='#394c43');d.polygon([(55,430),(140,390),(266,386),(357,430),(190,460)],fill='#91967a')
    d.line([(61,429),(143,392),(264,389)],fill='#d0c399',width=4)
    d.polygon([(178,433),(181,184),(211,159),(240,184),(231,427),(211,446)],fill='#50372f')
    d.polygon([(184,187),(210,163),(209,436),(180,427)],fill='#976d48')
    for x in [187,198,215,224]:d.line([(x,221),(x-4,287),(x+2,372),(x-4,421)],fill='#6c503b',width=3)
    colors=[('#3e5762','#8db3c0'),('#4f5341','#b1b679'),('#624735','#b89258'),('#5c4542','#bb8b76')];dark,light=colors[item%4]
    badge=[(87,158),(142,98),(238,86),(307,119),(335,190),(313,278),(214,336),(111,289),(74,227)]
    d.polygon([(x,y+9) for x,y in badge],fill='#302d25');d.polygon(badge,fill=dark);d.line(badge+[badge[0]],fill=light,width=6,joint='curve')
    src=Image.open(ROOT/'art'/f'{species}.png').convert('RGBA');src=src.crop(src.getchannel('A').getbbox())
    # Relief repeats the individually illustrated species' silhouette, carved into a distinct colored patina.
    src=src.crop((0,0,src.width,round(src.height*.78)));src.thumbnail((234,225),Image.Resampling.LANCZOS)
    gray=ImageOps.grayscale(src);emboss=gray.filter(ImageFilter.EMBOSS);relief=Image.blend(gray,emboss,.29)
    face=ImageOps.colorize(relief,'#3a3c32',light,mid='#949578').convert('RGBA');face.putalpha(src.getchannel('A'))
    x=(420-face.width)//2;y=102+(216-face.height)//2
    shadow=Image.new('RGBA',face.size,'#292d28');shadow.putalpha(face.getchannel('A'));im.alpha_composite(shadow,(x+4,y+5));im.alpha_composite(face,(x,y))
    for x,y in [(109,169),(300,167),(211,312)]:
        d.ellipse((x-6,y-6,x+6,y+6),fill='#4b4935');d.ellipse((x-4,y-5,x+4,y+3),fill='#d5bc78');d.point((x-2,y-3),fill='#fff1aa')
    for k in range(5):d.line((177,340+k*4,237,328+k*4),fill=['#c2a575','#8b7451'][k%2],width=4)
    return im
def save(im,i):
    im=im.crop(im.getbbox());im.thumbnail((512,512),Image.Resampling.LANCZOS)
    im.save(OUT/f'{i}.webp','WEBP',quality=95,method=6,exact=True)
if __name__=='__main__':
    OUT.mkdir(exist_ok=True)
    for i in range(28,51):save(miniature(i),i)
    for i,key in enumerate(['pokey','octo','bolt','bandito','brain','crabatron','dave','eyera','fang','fink','ichi','projectx'],74):save(effigy(i,key),i)
