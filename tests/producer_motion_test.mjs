import assert from 'node:assert/strict';
import {existsSync,readFileSync} from 'node:fs';
import {producerFrames} from '../public/assets/producer-frames.js';
import {producerClock,ProducerMotion} from '../public/assets/producer-motion.js';
const expected={twig:34,pebble:26,putty:26,goo:26,'inferno-twig':47,'inferno-pebble':47,'inferno-putty':51,'inferno-goo':49,hatchery:30,'inferno-hatchery':150};
let frames=0;
for(const [name,count] of Object.entries(expected)){
  for(let level=1;level<=5;level++){
    const key=level===1?name:(name.startsWith('inferno-')?name:'surface-'+name)+'-'+level;
    const [x,y,w,h,fw,fh,n]=producerFrames[key];
    assert.ok(x>=0&&y>=0&&Math.min(w,h,fw,fh)>0);assert.equal(n,count);frames+=count;
    const file=new URL('../public/assets/producer-motion/'+key+'.webp',import.meta.url);
    assert.ok(existsSync(file));assert.equal(readFileSync(file).subarray(8,12).toString(),'WEBP');
  }
}
assert.equal(frames,2430);
const initial=producerClock(null,0,0,true,1,51);
assert.equal(producerClock(initial,1.25,0,true,1,51).position,12);
assert.equal(producerClock(initial,1.25,0,false,1,51).position,3);
assert.equal(producerClock(initial,1.25,0,true,2,51).position,24);
const p=new ProducerMotion(),calls=[];
p.assets.set('twig',{image:{}});const drawing={images:{twig:{width:1254,height:1254}},bounds:{twig:{x:0,y:0,w:1254,h:1254}},catalog:{buildings:{twig:{capacity:100}}},ctx:{drawImage(...args){calls.push(args);}},now:1,base:{},serverOffset:0};
const yard={id:1,type:'twig',level:1,hp:900,stored:2};const draw=(props={},building={},constructing=false,fortifying=false)=>p.draw({...drawing,...props},{...yard,...building},'twig',{x:20,y:100},150,constructing,fortifying);
assert.ok(draw());assert.equal(calls.length,5);
for(const [r,b,c,f] of [[{reduced:true},{}],[{planner:{}},{}],[{battle:{status:'finished'}},{}],[{},{stored:100}],[{},{hp:0}],[{},{level:0}],[{},{},true],[{},{},false,true]]){
 assert.equal(draw(r,b,c,f),false);
}
assert.equal(producerClock(initial,1.75,0,true,1,150,true).position,24);
assert.equal(producerClock(initial,1.75,0,false,1,150,true).position,6);
console.log('50 painted machinery loops, 2,430 frames, source idle/combat/boost timing and 8 stop conditions passed.');
