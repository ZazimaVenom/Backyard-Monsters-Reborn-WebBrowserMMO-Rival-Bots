import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const data=source=>`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const ui=data(await readFile(new URL('../public/assets/ui.js',import.meta.url),'utf8'));
const source=(await readFile(new URL('../public/assets/repairs.js',import.meta.url),'utf8')).replace(/from '\.\/ui\.js\?v=[^']+'/u,`from '${ui}'`);
const {createHomeRepair}=await import(data(source));
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
function harness(){
  const button={hidden:true,disabled:true,innerHTML:'',dataset:{},attributes:{},setAttribute(name,value){this.attributes[name]=value;}};
  globalThis.document={querySelector:()=>button};
  const state={user:{id:1},page:'yard',base:{owner:{id:1},repair:{damaged:3,available:true,free:true,instant:true},resources:{twig:0,pebble:0,putty:0,goo:0},buildings:[
    {id:1,type:'townhall',level:2,hp:0,max_hp:500,ready_at:0},
    {id:2,type:'trap',level:1,hp:0,max_hp:100,ready_at:0},
    {id:3,type:'sniper',level:2,hp:25,max_hp:200,ready_at:4000,fortify_ready_at:5000},
    {id:4,type:'hatchery',level:0,hp:1,max_hp:100,ready_at:6000},
  ],champions:{drull:{hp:4,max_hp:100}},construction:{busy:2},garrison:{},army:{pokey:2}},army:{pokey:2},battle:null,activeBattle:null,visiting:null,worldSite:null,battleBusy:false,navigationEpoch:0,battleEpoch:0,snapshotEpoch:0};
  let planning=false,panelRefreshes=0,resyncs=0;
  const requests=[],snapshots=[],messages=[],sounds=[],restored=[];
  const app=createHomeRepair({state,showRestored:(owner,ids)=>restored.push({owner,ids}),request:(action,input)=>{const pending=deferred();requests.push({action,input,...pending});return pending.promise;},applyState:value=>{snapshots.push(value);Object.assign(state,value);state.snapshotEpoch++;},refreshHome:async()=>{resyncs++;},renderPanel:()=>panelRefreshes++,toast:(message,error)=>messages.push({message,error}),audio:{effect:name=>sounds.push(name)},isPlanning:()=>planning});
  const repaired=(count=3)=>({base:{...state.base,repair:{...state.base.repair,damaged:0,available:false},buildings:state.base.buildings.map(b=>b.level>0?{...b,hp:b.max_hp}:b)},repairResult:{action:'repairBase',repaired:count,buildingIds:count===3?[1,2,3]:count?[3]:[]}});
  return {state,app,button,requests,snapshots,messages,sounds,restored,repaired,plan:value=>planning=value,panelRefreshes:()=>panelRefreshes,resyncs:()=>resyncs};
}

{
  const h=harness();h.app.refresh();
  check(!h.button.hidden&&!h.button.disabled,'the owned home exposes repair with zero resources');
  check(h.button.innerHTML.includes('3 damaged · Free · instant'),'home repair shows the authoritative damaged count and free instant promise');
  check(h.button.attributes['aria-label'].includes('Repair base. 3 damaged'),'the damaged count is accessible without the icon');
  const pending=h.app.repairAll();await h.app.repairAll();await h.app.repairOne(1);
  check(h.requests.length===1&&h.requests[0].action==='repairBase'&&JSON.stringify(h.requests[0].input)==='{}','rapid bulk and single repair presses send one owner-only request');
  check(h.button.disabled&&h.button.dataset.state==='busy'&&h.button.attributes['aria-busy']==='true','repair stays visibly busy until the response arrives');
  check(h.state.base.buildings[0].hp===0&&h.snapshots.length===0,'rubble and HP are never changed before the server confirms repair');
  h.requests[0].resolve(h.repaired());await pending;
  check(h.snapshots.length===1&&h.state.base.buildings.filter(b=>b.level>0).every(b=>b.hp===b.max_hp),'confirmed repair applies the full server snapshot immediately');
  check(h.state.base.buildings[3].level===0&&h.state.base.buildings[3].hp===1,'new construction stays unfinished in the repaired snapshot');
  check(h.state.base.buildings[2].ready_at===4000&&h.state.base.buildings[2].fortify_ready_at===5000,'repair does not invent completed upgrade or fortification timers');
  check(h.state.base.champions.drull.hp===4&&h.state.army.pokey===2,'repair does not invent replacement monsters or heal champions');
  check(h.button.disabled&&!h.button.hidden&&h.button.innerHTML.includes('No repairs needed'),'healthy home has a clear disabled ready state');
  check(h.messages.length===1&&h.messages[0].message.includes('3 buildings restored')&&h.sounds.length===1,'one confirmed repair has one free success notice and effect');
  check(JSON.stringify(h.restored)==='[{"owner":1,"ids":[1,2,3]}]','Accepted repair marks only server-returned building IDs');
  check(h.panelRefreshes()===2&&!h.app.busy(),'selected building controls refresh when repair starts and finishes');
  await h.app.repairAll();check(h.requests.length===1,'a fully repaired base does not submit another request');
}
{
  const h=harness();
  check(h.app.canRepair(h.state.base.buildings[2]),'damaged existing buildings remain repairable while paid work continues');
  check(!h.app.canRepair(h.state.base.buildings[3])&&!h.app.canRepair(undefined),'new construction and missing buildings are not repairable');
  await h.app.repairOne(4);check(h.requests.length===0,'single repair cannot finish a new construction');
  const pending=h.app.repairOne('3');await h.app.repairAll();
  check(h.requests.length===1&&h.requests[0].action==='repair'&&h.requests[0].input.id===3,'existing single repair uses the free endpoint and blocks concurrent bulk repair');
  h.requests[0].resolve(h.repaired(1));await pending;
  check(h.restored.length===1&&h.restored[0].ids[0]===3,'Single repair highlights exactly one building');
  check(h.messages[0].message==='Building repaired for free.','single-building repair clearly confirms no resource charge');
}
for(const [label,change] of [
  ['a rival visit',h=>h.state.visiting={owner:{id:2}}],
  ['a territory view',h=>h.state.worldSite={site:{id:9}}],
  ['an active battle',h=>h.state.battle={id:1,status:'active'}],
  ['a finished battle screen',h=>h.state.battle={id:1,status:'finished'}],
  ['a discovered battle waiting to load',h=>h.state.activeBattle={id:1,status:'active'}],
  ['a battle request',h=>h.state.battleBusy=true],
  ['Yard Planner',h=>h.plan(true)],
  ['another page',h=>h.state.page='rankings'],
  ['sign-out',h=>h.state.user=null],
  ['a mismatched account snapshot',h=>h.state.base.owner.id=2],
]){
  const h=harness();change(h);h.app.refresh();await h.app.repairAll();await h.app.repairOne(1);
  check(h.button.hidden&&h.button.disabled&&h.requests.length===0,`${label} hides repair and cannot submit a home mutation`);
}
{
  const h=harness();h.state.base.repair={damaged:3,available:false};h.app.refresh();await h.app.repairAll();
  check(h.button.disabled&&h.requests.length===0,'server-declared unavailability is honored despite visible damage');
}
{
  const h=harness();delete h.state.base.repair;h.app.refresh();await h.app.repairAll();
  check(h.button.disabled&&h.requests.length===0,'missing repair status never enables an unconfirmed action');
}
{
  const h=harness(),pending=h.app.repairAll();h.requests[0].resolve(h.repaired(0));await pending;
  check(h.messages[0].message==='Your base is already fully repaired.'&&h.sounds.length===0&&h.restored.length===0,'another tab having repaired the base returns a truthful no-op notice');
}
{
  const h=harness(),pending=h.app.repairAll();h.requests[0].reject(new Error('The invasion is still active.'));await pending;
  check(h.messages[0].error&&h.messages[0].message==='The invasion is still active.','server rejection explains the real repair failure');
  check(h.state.base.buildings[0].hp===0&&!h.button.disabled&&!h.app.busy(),'failed repair leaves damage intact and allows retry');
}
for(const [label,change] of [
  ['a newer navigation intent',h=>h.state.navigationEpoch++],
  ['a new battle',h=>{h.state.battleEpoch++;h.state.battle={id:7,status:'active'};}],
  ['an open Planner draft',h=>h.plan(true)],
  ['another signed-in keeper',h=>{h.state.user={id:2};h.state.base.owner={id:2};}],
]){
  const h=harness(),pending=h.app.repairAll();change(h);h.requests[0].resolve(h.repaired());await pending;
  check(h.snapshots.length===0&&h.messages.length===0&&h.restored.length===0,`a delayed repair cannot replace ${label} or announce success there`);
}
{
  const h=harness(),pending=h.app.repairAll();h.state.snapshotEpoch++;h.state.base.resources.twig=400;
  h.requests[0].resolve(h.repaired());await pending;
  check(h.snapshots.length===0&&h.resyncs()===1&&h.state.base.resources.twig===400,'a newer snapshot triggers fresh synchronization instead of restoring stale resources');
}
{
  const h=harness(),old=h.app.repairAll();h.app.reset();h.state.user={id:2};h.state.base.owner={id:2};const newer=h.app.repairAll();
  h.requests[0].resolve(h.repaired());await old;
  check(h.app.busy()&&h.snapshots.length===0,'an old account response cannot release a new account repair lock');
  h.requests[1].resolve(h.repaired());await newer;check(h.snapshots.length===1&&!h.app.busy(),'the new account repair still completes normally');
}
console.log(`${checks} home repair UI checks passed.`);
