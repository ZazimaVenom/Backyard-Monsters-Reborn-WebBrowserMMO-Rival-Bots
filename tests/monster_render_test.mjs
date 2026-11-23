import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { YardRenderer } from '../public/assets/renderer.js';
import { creatureWidths, creaturePose, drawCreatureAbility } from '../public/assets/creature-motion.js';

const species = ['vorg', 'slimeattikus', 'slimeattikusmini', 'rezghul', 'spurtz', 'zagnoid', 'malphus', 'valgos', 'balthazar', 'grokus', 'sabnox', 'kingwormzer'];
const draws = [], paths = [];
const context = new Proxy({}, {
  get(target, key) {
    if (key in target) return target[key];
    return (...args) => {
      for (const n of args.filter(value => typeof value === 'number')) assert.ok(Number.isFinite(n), key);
      paths.push([key, ...args]);
    };
  },
});
const renderer = Object.assign(Object.create(YardRenderer.prototype), {
  ctx: context, catalog: { buildings: {}, monsters: {} }, images: {}, bounds: {}, now: .2,
  previousUnits: new Map(), unitEffects: new Map(), callbacks: {}, fx: [], reduced: false,
  battle: { id: 1, status: 'active', elapsed: 0, units: [], buildings: [] },
});
renderer.drawImage = (...params) => { draws.push(params); return true; };
renderer.project = (x,y) => ({x,y});
for (const type of species) {
  const png = await readFile(new URL('../public/assets/art/' + type + '.png', import.meta.url));
  assert.equal(png.subarray(1,4).toString(), 'PNG',type);
  assert.equal(png[25],6,type+' true-color RGBA');
  assert.ok(png.readUInt32BE(16) >= 1024 && png.readUInt32BE(20) >= 1024,type);
  assert.equal(new Set(Array.from({length:500}, (_,i) => creaturePose(type,i / 50,6,true))).size,8,type);
  const unit = {id:'u31',type,team:'attacker',state:'walk',x:15,y:15,hp:100,max_hp:100,dir:-1};
  renderer.drawUnit(unit);
  let [file,,,width,options] = draws.at(-1);
  assert.equal(file,type); assert.equal(width,creatureWidths[type]); assert.equal(options.flip,true);
  assert.equal(typeof options.pose,'number');
  renderer.reduced=true;
  renderer.drawUnit({...unit,dir:1,zombie:true});
  options=draws.at(-1)[4];
  assert.equal(options.pose,null); assert.equal(options.flip,false); assert.equal(options.zombie,true);
  renderer.reduced=false;
}
assert.ok(creatureWidths.slimeattikusmini<creatureWidths.slimeattikus / 2);
const skull={id:'u1',type:'balthazar',team:'attacker',state:'walk',x:1,y:1,hp:100,max_hp:100,movement:'air'};
renderer.reduced=true;
renderer.drawUnit(skull);assert.equal(draws.at(-1)[4].bounce,12);
renderer.drawUnit({...skull,type:'vorg'});assert.equal(draws.at(-1)[4].bounce,26);
renderer.reduced=false;
const count=draws.length;
renderer.drawUnit({...skull,type:'kingwormzer',movement:'burrow',state:'burrow'});
assert.equal(draws.length,count,'submerged King is not drawn above ground');
for(const type of ['split','resurrect','scorch']){
  const event={id:1,type};
  const p={x:10,y:15}, q={x:20,y:25};
  let before=paths.length;
  assert.equal(drawCreatureAbility(context,event,p,q,p,.15,false),true);
  assert.ok(paths.length>before,type+' has a visible event');
  before=paths.length;
  drawCreatureAbility(context,event,p,q,p,2,false);
  assert.equal(paths.length,before,type+' expires');
}
assert.equal(drawCreatureAbility(context,{type:'hit'},null,null,null,.1,false),false);
renderer.lastEvent=0;
const attacker={...skull,type:'sabnox',state:'attack',dir:1,movement:'ground'};
const snapshot={id:1,status:'active',elapsed:2,serverTime:Date.now()/1000,buildings:[],units:[attacker],
  events:[{id:1,t:2,type:'shot',projectile:'magma',sourceId:'u1',kind:'sabnox',x:1,y:1,tx:2,ty:3}]};
renderer.setBattle(snapshot);
assert.equal(renderer.unitEffects.get('u1:attack').type,'shot');
renderer.unitEffects.get('u1:attack').start=performance.now()-200;
renderer.drawUnit(attacker);assert.ok(draws.at(-1)[1]>attacker.x,'confirmed attack lunges toward target');
const fxCount=renderer.fx.length;renderer.setBattle(snapshot);
assert.equal(renderer.fx.length,fxCount,'repeated snapshots do not multiply the effects');
console.log('Twelve HD species, eight pose timing phases, direction, reduced motion, zombie treatment, flight, burrowing, spawn effects, attack confirmation and event deduplication passed.');
