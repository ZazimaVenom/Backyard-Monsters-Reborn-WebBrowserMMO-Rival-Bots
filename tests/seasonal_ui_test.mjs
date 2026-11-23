import assert from 'node:assert/strict';
import {createBaiter} from '../public/assets/baiter.js';
import {YardRenderer} from '../public/assets/renderer.js';

const horse={id:27,type:27,x:14.5,y:.5};
const pumpkin={id:1020,type:53,x:3,y:9};
const giant={id:54,type:54,x:8,y:12};
const state={user:{id:7},base:{owner:{id:7},baiter:{session:null},seasonal:{objects:[horse,pumpkin,giant],horse:{level:9,points:2100000}}},
  page:'yard',catalog:{monsters:{pokey:{name:'Pokey'}}},modalEpoch:0,snapshotEpoch:0};
const body={innerHTML:'',querySelectorAll:()=>[]};
globalThis.document={activeElement:null,querySelector:selector=>selector==='#modal-body'?body:null};
let calls=[],toasts=[],delayed=null;
const handler=createBaiter({state,art:(key)=>`<img alt="${key}">`,
  showModal:(html,name)=>{body.innerHTML=html;state.modal=name;state.modalEpoch++;},closeModal:()=>{state.modal=null;state.modalEpoch++;},
  seasonAction:async input=>{calls.push(input);return delayed?await delayed:{seasonResult:{kind:'bag',shiny:100,decorations:{110:1,111:6}}};},
  toast:message=>toasts.push(message),isPlanning:()=>false});
const act=(name,id)=>handler.handleClick({dataset:{baiter:'season-act',seasonOp:name,seasonId:id}});
handler.showSeason();assert.match(body.innerHTML,/Replay Halloween/);
for(const command of ['data-champion-event="krallen"','data-champion-event="madness"','data-tribes-open="carnage"','data-tribes-open="brukkarg"','data-tribes-open="hellraisers"','data-baiter="blitz"'])assert.ok(body.innerHTML.includes(command));
assert.doesNotMatch(body.innerHTML,/data-baiter="wmi"/);
state.base.wmi={};handler.showSeason();assert.match(body.innerHTML,/data-baiter="wmi"/);
await act('giantOpen','54');assert.deepEqual(calls,[{op:'giantOpen',id:54}]);assert.deepEqual(toasts,['Goodie bag · 100 Shiny and 7 decorations.']);
Object.assign(state.base.seasonal,{objects:[horse,pumpkin],replayUsed:true,bagClaimed:true});
const epoch=state.modalEpoch;handler.refreshSeason();assert.equal(state.modalEpoch,epoch);assert.doesNotMatch(body.innerHTML,/Replay Halloween|Open goodie bag/);assert.match(body.innerHTML,/has been claimed/);
handler.showSeason(pumpkin);assert.match(body.innerHTML,/Send a worker/);assert.doesNotMatch(body.innerHTML,/attached note/);
state.base.seasonal.objects=[horse];state.base.seasonal.lastPick={kind:'treat',amount:12,resource:'twig'};handler.refreshSeason();assert.match(body.innerHTML,/Last harvest · treat · 12 twig/);assert.doesNotMatch(body.innerHTML,/Send a worker/);
await act('horse','27');assert.equal(state.modal,'horse-note');assert.match(body.innerHTML,/Damage to your buildings will remain/);assert.match(body.innerHTML,/Leave it closed/);
await handler.handleClick({dataset:{baiter:'season'}});assert.equal(state.modal,'season');
let complete;delayed=new Promise(resolve=>{complete=resolve;});const stale=act('halloweenReplay');state.modalEpoch++;body.innerHTML='Another screen';complete({seasonResult:{kind:'replay'}});await stale;assert.equal(body.innerHTML,'Another screen');
state.visiting={seasonal:{objects:[horse,giant]}};handler.showSeason();assert.match(body.innerHTML,/Only this yard’s keeper/);assert.doesNotMatch(body.innerHTML,/data-season-op|Review Trojan|claimed|Campaigns and weekly hunts/);state.visiting=null;
state.base.baiter.session={status:'active',horse:true};assert.match(handler.buildingButton(),/Resume Trojan Horse defense/);
const battle={horse:true,units:[{hp:20,team:'attacker'}],remaining:{pokey:2},wave:{pokey:3},status:'active',direction:'t'};
assert.match(handler.panel(battle),/Still inside the horse<\/span><b data-wave-reserve>2/);assert.doesNotMatch(handler.panel(battle),/drill/);
for(const [outcome,heading] of [['held','repelled the Trojan Horse'],['overrun','Trojan Horse broke through'],['dismissed','Trojan Horse ambush ended']]){
  battle.result={outcome,destruction:23};const result=handler.result(battle);assert.ok(result.includes(heading));assert.match(result,/YARD DAMAGE/);assert.doesNotMatch(result,/DRILL DAMAGE/);
}
state.base.baiter.session.status='finished';handler.showSeason();assert.match(body.innerHTML,/Review Trojan Horse defense/);
assert.match(handler.result({...battle,horse:false}),/DRILL DAMAGE/);assert.doesNotMatch(handler.panel({...battle,horse:false}),/Still inside the horse/);
const ellipses=[];
const renderer=Object.assign(Object.create(YardRenderer.prototype),{base:{seasonal:{objects:[horse,pumpkin]}},drawImage:()=>false,
  project:(x,y)=>({x:x*40,y:y*40}),ctx:{save(){},restore(){},beginPath(){},fill(){},stroke(){},fillRect(){},ellipse(...v){ellipses.push(v);}}});
renderer.drawSeasonal(pumpkin);renderer.drawSeasonal(horse);assert.equal(ellipses.length,2);assert.equal(renderer.hitSeasonal({x:horse.x,y:horse.y-.25}).id,horse.id);assert.equal(renderer.hitSeasonal(pumpkin).id,pumpkin.id);
console.log('PASS seasonal action dispatch, reward quantities, guarded refresh, worker results, note/defer, stale responses, visit restrictions, staged Trojan Horse, event review, standard drills and missing-art hit targets');
