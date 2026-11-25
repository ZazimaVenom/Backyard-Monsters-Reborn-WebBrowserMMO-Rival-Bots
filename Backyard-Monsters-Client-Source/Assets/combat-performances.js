import { directionCell } from './monster-directions.js?v=0.13.1';

export const ordinaryVoices = new Set('pokey octo bolt fink eyera ichi bandito fang brain crabatron projectx dave wormzer teratorn zafreeti vorg slimeattikus slimeattikusmini rezghul spurtz zagnoid malphus valgos balthazar grokus sabnox kingwormzer'.split(' '));
const guardians = new Set(['gorgo','drull','fomor','korath','krallen']);
export const voicedSpecies = new Set([...ordinaryVoices,...guardians]);
export const performanceSheets = {};
for (const species of ordinaryVoices) {
  for (const phase of ['stride','strike']) performanceSheets[species+'-'+phase]={file:species+'-'+phase+'.webp',columns:3,rows:2,count:6};
}
const forms = [...ordinaryVoices];
for (const species of guardians) {
  if (species === 'krallen') {
    for (let p=0;p<=2;p++) forms.push(species+'-p'+p);
  } else {
    for (let level=1;level<=6;level++) forms.push(species+'-e'+level);
  }
}
for (const form of forms) performanceSheets[form+'-defeat']={file:form+'-defeat.webp',columns:2,rows:2,count:4};

// Native silhouettes were checked against the finished paintings. Several
// Krallen and Korath forms turn the other way as their anatomy changes.
const leftFacingFalls=new Set(['brain','crabatron','dave','drull','fang','fomor','zafreeti',
  'krallen-p1','krallen-p2','korath-e1','korath-e5','korath-e6']);

export function defeatKey(unit) {
  if (ordinaryVoices.has(unit.type)) return unit.type+'-defeat';
  if (!guardians.has(unit.type)) return null;
  if (unit.type==='krallen') {
    const power=Number.isInteger(unit.krallenPower)?unit.krallenPower:0;
    return 'krallen-p'+Math.max(0,Math.min(2,power))+'-defeat';
  }
  return unit.type+'-e'+Math.max(1,Math.min(6,Number(unit.level)||1))+'-defeat';
}

// Preserve the four-pose original front-quarter sequences when one exists.
// Newly painted heading-specific strides and strikes alternate with the neutral bearing.
export function articulatedFrame(unit, native, facing, images, {walking,strikeAge,reduced,clock}) {
  if (reduced || !native.directional || !facing || !ordinaryVoices.has(unit.type)) return native;
  const cell=directionCell(unit.type,facing);
  const striking=strikeAge>=0 && strikeAge<.57;
  const offset=(Number(String(unit.id).replace(/\D/g,''))||0)*.285;
  const speed=unit.movement==='air'?5.5:4.5;
  const phase=striking ? ((strikeAge>=.13 && strikeAge<.47)?'strike':null)
    : walking && (Math.floor(clock*speed*2+offset)%2)!==0?'stride':null;
  const key=phase && unit.type+'-'+phase;
  return key && images[key] && cell ? {...native,key,frame:cell.frame,flip:cell.flip,painted:true,striking:phase==='strike'} : native;
}

const holdMs=1320;
export function defeatPose(effect, clock, reduced) {
  const age=clock-effect.start;
  if(age<0 || age>=holdMs)return null;
  const stage=reduced?3:age<190?0:age<430?1:age<730?2:3;
  const alpha=reduced ? .80 : age<970?1:(holdMs-age)/(holdMs-970);
  return {frame:stage,alpha,flip:effect.flip};
}

export class CombatPerformances {
  constructor() { this.fallen=new Map(); this.seenDeaths=new Set(); this.lastSeen=0; }
  observe(previous,battle,visible,facings,clock,voice,hidden) {
    if(!battle || !previous || previous.id!==battle.id) {
      this.fallen.clear();this.seenDeaths.clear();this.lastSeen=battle?.events?.at(-1)?.id||0;return;
    }
    const last=Math.max(this.lastSeen,previous.events?.at(-1)?.id || 0);
    this.lastSeen=Math.max(last,battle.events?.at(-1)?.id||0);
    if(battle.elapsed<previous.elapsed)return;
    const live=previous.status==='active' && !hidden && battle.elapsed-previous.elapsed<=3.5;
    // A rolled event buffer may omit a defeat in a large wave. A positive-to-zero authority
    // transition supplies the same information without inventing deaths from absent units.
    const old=new Map((previous.units||[]).map(u=>[String(u.id),u]));
    const calls=new Set(), recorded=new Set();
    const fall=(unit,event)=>{
      const id=String(unit.id),key=defeatKey(unit);
      if(!key || this.seenDeaths.has(id) || unit.hp>0 || !Number.isFinite(unit.x) || !Number.isFinite(unit.y))return;
      const bearing=facings?.get(unit.id);
      const side=bearing ? (['w','nw','sw'].includes(bearing)?-1:['e','ne','se'].includes(bearing)?1:0) : Math.sign(unit.dir||0);
      const nativeLeft=leftFacingFalls.has(unit.type) || leftFacingFalls.has(key.slice(0,-7));
      const flip=side!==0 && (side<0)!==nativeLeft;
      this.seenDeaths.add(id);
      this.fallen.set(id,{unit,key,x:Number.isFinite(event?.x)?event.x:unit.x,y:Number.isFinite(event?.y)?event.y:unit.y,flip,start:clock});
      calls.add(unit.type+'-defeat');
    };
    if(live) {
      for (const e of battle.events||[]) {
        if(e.id<=last || !Number.isFinite(e.t) || (battle.elapsed-e.t)>2.5 || e.t>battle.elapsed)continue;
        if(e.type==='death' || e.type==='champion_retreat') {
          const u=visible.get(String(e.targetId));
          if(u && u.hp<=0){fall(u,e);recorded.add(String(u.id));}
        } else if(['deploy','split','resurrect'].includes(e.type)) {
          const u=visible.get(String(e.type==='deploy'?e.sourceId:e.targetId));
          if(u && voicedSpecies.has(u.type))calls.add(u.type+'-call');
        }
      }
      for (const [id,u] of visible) if (!recorded.has(id) && u.hp<=0 && old.get(id)?.hp>0)fall(u,null);
      for(const cue of calls){const [type,kind]=cue.split('-');voice?.(type,kind);}
    }
    for (const [id,e] of this.fallen) {
      if(clock-e.start>=holdMs || !visible.has(id) || visible.get(id).hp>0)this.fallen.delete(id);
    }
  }
}
