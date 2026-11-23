import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { CombatPerformances, performanceSheets, defeatKey, defeatPose, articulatedFrame, voicedSpecies } from '../public/assets/combat-performances.js';
import { GameAudio } from '../public/assets/audio.js';
import { directionCell } from '../public/assets/monster-directions.js';

const names=Object.keys(performanceSheets);
assert.equal(names.filter(k=>k.endsWith('-defeat')).length,54);
assert.equal(names.filter(k=>k.endsWith('-stride')||k.endsWith('-strike')).length,54);
assert.equal(voicedSpecies.size,32);
// Distribution regressions (a manifest or code key with no physical asset) otherwise only emerge in a real browser.
for (const folder of ['defeats','facing-actions','voices']) {
 const root=new URL('../public/assets/'+folder+'/',import.meta.url);
 const rows=JSON.parse(readFileSync(new URL('manifest.json',root),'utf8')).files;
 const expected=folder==='voices'?[...voicedSpecies].flatMap(t=>[t+'-call.ogg',t+'-defeat.ogg'])
   :names.filter(k=>k.endsWith('-defeat')===(folder==='defeats')).map(k=>performanceSheets[k].file);
 assert.deepEqual(rows.map(r=>r.file).sort(),expected.sort(),folder+' manifest covers the runtime catalogue');
 for (const row of rows) {
   const bytes=readFileSync(new URL(row.file,root));
   assert.equal(bytes.length,row.bytes,row.file);
   assert.equal(createHash('sha256').update(bytes).digest('hex'),row.sha256,row.file);
   assert.equal(bytes.toString('ascii',0,4),folder==='voices'?'OggS':'RIFF',row.file);
 }
}
const live={id:'a1',type:'pokey',hp:60,x:3,y:10,state:'walk'},dead={...live,hp:0,state:'dead'};
const visibility=b=>new Map(b.units.filter(u=>!u.hidden).map(u=>[String(u.id),u]));
const create=(elapsed,units,events,status='active',id=7)=>({id,status,elapsed,units,events});
const initial=create(3,[live],[{id:2,t:3,type:'deploy',kind:'pokey',sourceId:'a1'}]);
const terminal=create(4,[dead],[...initial.events,{id:4,t:4,type:'death',kind:'pokey',targetId:'a1',x:3,y:10}]);
const log=[],perform=new CombatPerformances();
let checks=0;
function observe(prev,battle,time,hidden=false){perform.observe(prev,battle,visibility(battle),new Map([['a1','sw']]),time,(t,c)=>log.push([t,c]),hidden);}
observe(null,initial,100);
assert.equal(log.length,0,'reopening a battle does not replay arrival calls');
observe(initial,terminal,200);
assert.deepEqual(log,[['pokey','defeat']]);
const echo=perform.fallen.get('a1');
assert.equal(echo.key,'pokey-defeat');
for(const [ms,frame] of [[0,0],[270,1],[560,2],[820,3],[1270,3]]){assert.equal(defeatPose(echo,200+ms,false).frame,frame);checks++;}
assert(defeatPose(echo,1450,false).alpha<.3);assert.equal(defeatPose(echo,310,true).frame,3);
assert.equal(defeatPose(echo,1541,false),null);assert(echo.flip);
observe(terminal,terminal,1800);
assert.equal(perform.fallen.size,0);
observe(terminal,initial,1900);observe(initial,terminal,1950);
assert.equal(log.length,1,'delayed and duplicate snapshots cannot replay a fallen creature');
const more=create(5,[dead,{...live,id:'u2',type:'fomor',champion:true,level:6}],[...terminal.events,{id:5,t:5,type:'deploy',sourceId:'u2'}]);
observe(terminal,more,2200);assert.deepEqual(log.at(-1),['fomor','call']);
const final=create(6,[dead,{...more.units[1],hp:0,state:'dead'}],[{id:8,t:6,type:'champion_retreat',targetId:'u2',x:3,y:10}],'finished');
observe(more,final,2600);assert.deepEqual(log.at(-1),['fomor','defeat']);assert.equal(perform.fallen.get('u2').key,'fomor-e6-defeat');
assert.equal(defeatKey({type:'krallen',krallenPower:2}),'krallen-p2-defeat');
assert.equal(defeatKey({type:'gorgo',level:100}),'gorgo-e6-defeat');
assert.equal(defeatKey({type:'missing'}),null);
observe(null,terminal,2700);assert.equal(perform.fallen.size,0);
observe(initial,{...terminal,events:[]},2800);
assert.equal(perform.fallen.size,1,'HP transition survives truncated combat event buffer');
observe(null,initial,3000);observe(initial,terminal,3200,true);
assert.equal(perform.fallen.size,0,'background tabs do not build a delayed graveyard');
observe(null,initial,3500);observe(initial,{...terminal,units:[{...dead,hidden:true}]},3600);
assert.equal(perform.fallen.size,0,'hidden casualties have no presentation');

observe(null,initial,3700);observe(initial,{...terminal,elapsed:70},3800);
assert.equal(perform.fallen.size,0,'a sleeping tab does not replay kills on waking');
for (const [type,attributes,flip] of [['drull',{level:6},false],['korath',{level:4},true],['korath',{level:6},false],['krallen',{krallenPower:0},true],['krallen',{krallenPower:2},false]]) {
 const a=create(1,[{...live,type,...attributes}],[]),b=create(2,[{...dead,type,...attributes}],[]);
 observe(null,a,4000);observe(a,b,4200);assert.equal(perform.fallen.get('a1').flip,flip,type+' artistic facing');
}

for(const species of voicedSpecies) {
 if(!performanceSheets[species+'-stride'])continue;
 for(const heading of ['n','ne','e','se','s','sw','w','nw']){
   const cell=directionCell(species,heading),native={key:species+'-directions',directional:true,...cell};
   const images=Object.fromEntries(['stride','strike'].map(s=>[species+'-'+s,true]));
   const monster={id:'u0',type:species};
   const gait=articulatedFrame(monster,native,heading,images,{walking:true,strikeAge:-1,reduced:false,clock:.12});
   const strike=articulatedFrame(monster,native,heading,images,{walking:false,strikeAge:.27,reduced:false,clock:0});
   assert.equal(gait.key,species+'-stride');assert.equal(strike.key,species+'-strike');
   assert.equal(gait.frame,cell.frame);assert.equal(gait.flip,cell.flip);
   assert.equal(articulatedFrame(monster,native,heading,images,{walking:false,strikeAge:.27,reduced:true,clock:.12}),native);
   assert.equal(articulatedFrame(monster,native,heading,{}, {walking:true,strikeAge:-1,reduced:false,clock:.12}),native);
   checks+=6;
 }
}

const audio=new GameAudio(),played=[],loaded=[];
const voiceSink=()=>({connect(){},disconnect(){}});
audio.enabled=true;audio.context={state:'running',destination:{},
 createBufferSource:()=>({...voiceSink(),start(){played.push(this)},stop(){this.onended?.()}}),
 createGain:()=>({...voiceSink(),gain:{value:1}})};
audio.buffer=async (id,ext,folder)=>{loaded.push([id,ext,folder]);return {duration:.6};};
audio.creature('pokey','call');audio.creature('pokey','call');audio.creature('bad','defeat');
await new Promise(r=>setTimeout(r,0));assert.deepEqual(loaded,[['pokey-call','ogg','voices']]);assert.equal(played.length,1);
for(const name of ['octo','fomor','gorgo','valgos','krallen','korath'])audio.creature(name,'defeat');
await new Promise(r=>setTimeout(r,0));assert.equal(audio.creatureSources.size,5);
for(const source of played)source.onended();assert.equal(audio.voices.size,0);
audio.volume=0;const oldCount=loaded.length;audio.creature('ichi','defeat');assert.equal(loaded.length,oldCount);
console.log('54 defeat forms, 216 compass directions, '+checks+' timing/articulated frame checks, confirmed HP transitions, replay suppression and bounded creature audio passed.');
