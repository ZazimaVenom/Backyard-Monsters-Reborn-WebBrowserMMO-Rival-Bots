import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { directionSheets, directionCell, directionalFrame, yardBearing } from '../public/assets/monster-directions.js';
import { YardRenderer } from '../public/assets/renderer.js';
const specs=Object.entries(directionSheets);
assert.equal(specs.length,27);
for(const [key,sheet] of specs) {
  const type=key.slice(0,-11);
  const bytes=await readFile(new URL('../public/assets/art/'+sheet.file,import.meta.url));
  assert.equal(bytes.subarray(8,12).toString(),'WEBP',type);
  assert.deepEqual([sheet.columns,sheet.rows,sheet.count],[3,2,6]);
  const images={[key]:{width:1536,height:1024}};
  for(const direction of ['n','ne','e','se','s','sw','w','nw']) {
    const cell=directionCell(type,direction);
    assert.ok(cell && Number.isInteger(cell.frame) && cell.frame>=0 && cell.frame<6,type+' '+direction);
    const frame=directionalFrame(type,{key:type,frame:null},direction,images);
    assert.equal(frame.key,key);assert.equal(frame.frame,cell.frame);
  }
  for(const state of ['walk','attack','fly']) {
    const native={key:type+'-'+state,frame:2};
    assert.equal(directionalFrame(type,native,'se',images),native);
    assert.equal(directionalFrame(type,native,'sw',images),native);
    assert.equal(directionalFrame(type,native,'n',images).key,key);
    assert.equal(directionalFrame(type,native,'n',{}),native);
  }
}
const screen=[['e',1,-1],['se',1,0],['s',1,1],['sw',0,1],['w',-1,1],['nw',-1,0],['n',-1,-1],['ne',0,-1]];
for (const [expected,dx,dy] of screen) assert.equal(yardBearing(dx,dy),expected);
assert.equal(yardBearing(0,0),null);
assert.equal(directionCell('gorgo','ne'),null);
const renderer=Object.create(YardRenderer.prototype);
assert.equal(renderer.unitFacing({id:'a',x:2,y:2},{x:3,y:3},null),'n');
assert.equal(renderer.unitFacing({id:'a',x:2,y:2},{x:2,y:2},null),'n','standing unit retains last heading');
assert.equal(renderer.unitFacing({id:'a',x:2,y:2},{x:3,y:3},{x:2,y:4}),'sw','target takes priority over previous movement');
const emergence={key:'wormzer-emerge',frame:1};
assert.equal(directionalFrame('wormzer',emergence,'n',{'wormzer-directions':{}}),emergence);
console.log('27 directional atlases, 216 heading selections, camera projection, animation priority, facing memory and missing-asset fallback passed.');
