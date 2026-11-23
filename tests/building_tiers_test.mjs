import assert from 'node:assert/strict';
import {buildingTierKey,buildingTierLimits} from '../public/assets/building-tiers.js';
import {YardRenderer} from '../public/assets/renderer.js';

const renderer=Object.create(YardRenderer.prototype);
const requested=[];
Object.assign(renderer,{
  catalog:{buildings:{townhall:{art:'townhall.png'},baiter:{art:'baiter.png'}}},
  images:{},bounds:{},pendingAssets:new Map(),base:{buildings:[]},battle:null,
  loadImage:(key,file)=>requested.push([key,file])
});
const building={type:'townhall',id:1,level:1,hp:200,max_hp:200};
const tiers=()=>requested.filter(([key])=>/^(?:surface|inferno)-townhall-\d+$/.test(key));
renderer.prepareScene({buildings:[{...building,upgrade_to:2}]},null);
assert.deepEqual(tiers(),[],'A pending first upgrade must not fetch the next architecture.');
const upgraded={...building,level:2};
renderer.prepareScene({buildings:[upgraded]},null);
assert.deepEqual(tiers(),[['surface-townhall-2','surface-townhall-2.webp']]);
assert.equal(renderer.buildingArt(upgraded),'townhall','Use available level-one art while the upgrade illustration loads.');
renderer.images['surface-townhall-2']={};
assert.equal(renderer.buildingArt(upgraded),'surface-townhall-2');
assert.equal(renderer.buildingArt({...upgraded,hp:90}),'surface-townhall-2','Damage retains the completed architecture.');
assert.equal(renderer.buildingArt(building),'townhall','A cached higher tier must not replace a level-one building.');
renderer.images['inferno-townhall-6']={};renderer.images['inferno-townhall']={};
const hall={...upgraded,level:6};
for(const target of [{kind:'inferno'},{kind:'infernoRival'},{kind:'tribe',event:'hellraisers'}]){
  requested.length=0;renderer.battle={target,buildings:[hall]};
  renderer.prepareScene({buildings:[building]},renderer.battle);
  assert.deepEqual(tiers(),[['inferno-townhall-6','inferno-townhall-6.webp']]);
  assert.equal(renderer.buildingArt(hall),'inferno-townhall-6');
}
renderer.battle=null;renderer.base={realm:'inferno',buildings:[hall]};
assert.equal(renderer.buildingArt(hall),'inferno-townhall-6');
renderer.base={buildings:[hall],tribe:{event:'hellraisers'}};
assert.equal(renderer.buildingArt(hall),'inferno-townhall-6','Scouting the same Moloch yard uses matching architecture.');
renderer.base={buildings:[hall]};
for(let level=6;level<=10;level++){const key='surface-townhall-'+level;renderer.images[key]={};assert.equal(renderer.buildingArt({...hall,level}),key);}
for(const realm of Object.keys(buildingTierLimits)){
  const underground=realm==='inferno';
  for(const [type,max] of Object.entries(buildingTierLimits[realm])){
    for(let level=2;level<=max;level++)assert.equal(buildingTierKey({type,level},underground),realm+'-'+type+'-'+level);
    for(const level of [0,1,-1,1.5,NaN,max+1])assert.equal(buildingTierKey({type,level},underground),null);
  }
}
console.log('Building tier keys, lazy requests, pending upgrades, wear selection, realm changes and Moloch scouting passed.');
