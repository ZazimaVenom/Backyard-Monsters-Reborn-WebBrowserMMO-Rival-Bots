import {producerFrames} from './producer-frames.js?v=0.13.0';

const types=new Set(['twig','pebble','putty','goo','hatchery']);

function ticks(frame,quiet,hatchery) {
  if(hatchery)return quiet?Math.floor(frame/2)+Math.floor(frame/7)-Math.floor(frame/14):Math.floor(frame/7);
  // BUILDING1–4 tick every third stage frame in an idle yard and every tenth
  // during a fight. The fallback tenth-frame tick also runs between idle ticks.
  return quiet ? Math.floor(frame/3)+Math.floor(frame/10)-Math.floor(frame/30) : Math.floor(frame/10);
}

export function producerClock(previous,seconds,seed,quiet,boost,count,hatchery=false) {
  seed=Number.isInteger(Number(seed))?Math.abs(Number(seed)):0;
  const frame=Math.floor(seconds*24)+seed%5;
  if (!previous) return {tick:frame,position:hatchery?0:seed%5};
  const advance=ticks(frame,quiet,hatchery)-ticks(previous.tick,quiet,hatchery);
  return {tick:frame,position:(previous.position+Math.max(0,advance)*boost)%count};
}

export class ProducerMotion {
  constructor() {
    this.assets=new Map();
    this.clocks=new Map();
  }

  prepare(buildings,underground,disabled,owner) {
    const wanted=new Set();
    if (!disabled) {
      for(const b of buildings) {
        if(!types.has(b.type)||b.level<1||b.hp<=0||(b.type==='hatchery'&&!b.hatchingUntil))continue;
        const key=b.level===1?(underground?'inferno-':'')+b.type:(underground?'inferno':'surface')+'-'+b.type+'-'+b.level;
        if(producerFrames[key])wanted.add(key);
      }
    }
    if (owner!==this.owner) { this.clocks.clear();this.owner=owner; }
    for (const key of this.assets.keys()) if(!wanted.has(key))this.assets.delete(key);
    for (const key of wanted) {
      const record=this.assets.get(key);
      if(record && (record.retry===0||record.retry>Date.now()))continue;
      const item={image:null,retry:0};this.assets.set(key,item);
      const img=new Image();img.decoding='async';
      img.onload=()=>{if(this.assets.get(key)===item)item.image=img;};
      img.onerror=()=>{item.retry=Date.now()+30000;};
      img.src='assets/producer-motion/'+key+'.webp';
    }
  }

  draw(renderer,b,key,p,width,constructing,fortifying) {
    const data=producerFrames[key],image=this.assets.get(key)?.image;
    const base=renderer.images[key],bounds=renderer.bounds[key];
    if(!data||!image||!base||!bounds)return false;
    const native=key.startsWith('inferno-');
    const hatchery=b.type==='hatchery',now=Date.now()/1000+renderer.serverOffset;
    const capacity=(native?2000:renderer.catalog.buildings[b.type]?.capacity)*b.level;
    const stopped=renderer.reduced || renderer.planner || b.level<1 || b.hp<=0 || constructing || fortifying ||
      (renderer.battle && renderer.battle.status!=='active') || (hatchery ? !(b.hatchingUntil>now) : (Number.isFinite(capacity) && b.stored>=capacity));
    if(stopped){this.clocks.delete(b.id);return false;}
    const boost=hatchery?(b.hatchDrive?.until>now?b.hatchDrive.steps:1):(!native && !renderer.battle && renderer.base?.productionOverdriveUntil>now?2:1);
    const [px,py,pw,ph,fw,fh,count]=data;
    const clock=producerClock(this.clocks.get(b.id),renderer.now,b.id,!renderer.battle,boost,count,hatchery);
    this.clocks.set(b.id,clock);
    const i=clock.position;
    // Draw the fixed painting around its animated rectangle. Painting a patch
    // over the old silhouette would leave a second pump head or smoke trail.
    const scale=width/bounds.w,originX=p.x-width/2-bounds.x*scale,
      originY=p.y+8-(bounds.y+bounds.h)*scale;
    const ctx=renderer.ctx;
    function draw(image,sx,sy,sw,sh,dx,dy,dw=sw,dh=sh) {
      if(sw>0 && sh>0)ctx.drawImage(image,sx,sy,sw,sh,originX+dx*scale,originY+dy*scale,dw*scale,dh*scale);
    }
    const right=px+pw,bottom=py+ph;
    draw(base,0,0,base.width,py,0,0);
    draw(base,0,bottom,base.width,base.height-bottom,0,bottom);
    draw(base,0,py,px,ph,0,py);
    draw(base,right,py,base.width-right,ph,right,py);
    draw(image,(i%8)*fw,Math.floor(i/8)*fh,fw,fh,px,py,pw,ph);
    return true;
  }
}
