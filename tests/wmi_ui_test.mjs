import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {createBaiter} from '../public/assets/baiter.js';
import {wmiBoard,wmiResult} from '../public/assets/wmi.js';
if(!globalThis.crypto)globalThis.crypto=webcrypto;
const groups=[{type:'spurtz',count:5,edge:'t',level:4,powerup:1,rage:25,delay:0},{type:'drull',count:1,edge:'b',level:3,guardianHP:12000,powerup:0,rage:0,delay:12}];
const run={cleared:30,stage:31,deadline:1900000000,run:2,restart:false,tier:4,item:121,nextGroups:groups};
const state={page:'yard',user:{id:8},base:{owner:{id:8},wmi:{1:run,2:{...run,item:131}},baiter:{session:null}},catalog:{monsters:{spurtz:{name:'Spurtz'}},champions:{drull:{name:'Drull'}}},navigationEpoch:1,battleEpoch:0,modalEpoch:0,snapshotEpoch:0,modal:'season'};
const app=createBaiter({state,api,art:()=>'',showModal:(html,kind)=>{modal=html;state.modal=kind;state.modalEpoch++;},closeModal:()=>{state.modal=null;state.modalEpoch++;},isPlanning:()=>false,toast:message=>assert.fail(message),audio:{effect(){}},present:value=>{state.battle=value;}});
let modal='',request=null;
async function api(action,input){
  request={action,input};
  const session={id:'session',status:'active',revision:1,wmiEvent:2,wmiStage:31};
  return {session,battle:{training:true,status:'active',wmiEvent:2,wmiStage:31,defender:{id:8}},wmi:state.base.wmi};
}
assert.match(wmiBoard(state,1),/Bonus wave 1/);
assert.match(wmiBoard(state,2),/Victory totem tier 4 of 6/);
assert.match(wmiBoard(state,1),/5 × Spurtz/);
assert.match(wmiBoard(state,1),/Champion/);assert.match(wmiBoard(state,1),/Enraged 25s/);assert.match(wmiBoard(state,1),/After 12s · south/);
await app.handleClick({dataset:{baiter:'wmi',wmiEvent:'2'}});
assert.equal(state.modal,'wmi');assert.match(modal,/Wild Monster Invasion II/);
await app.handleClick({dataset:{baiter:'wmi-start',wmiEvent:'2'}});
assert.equal(request.action,'wmiStart');assert.equal(request.input.run,2);assert.equal(request.input.event,2);assert.equal(request.input.stage,31);assert.equal(request.input.previousId,null);assert.equal(request.input.requestId.length,32);
assert.equal(state.modal,null);assert.equal(state.battle.wmiEvent,2);
assert.match(wmiBoard(state,2),/Resume wave 31/);assert.match(wmiBoard(state,1),/Resume current defense/);
assert.match(wmiResult({...state.battle,result:{outcome:'held'}},state.base.wmi),/Tier 5 of 6/);
assert.match(wmiResult({...state.battle,wmiStage:1,result:{outcome:'held'}},state.base.wmi),/Decorations → Storage/);
assert.match(wmiResult({...state.battle,result:{outcome:'dismissed'}},state.base.wmi),/available to retry/);
console.log('Wild Monster Invasions UI: 21 checks passed.');
