import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const data=source=>`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const ui=data(await readFile(new URL('../public/assets/ui.js',import.meta.url),'utf8'));
const source=(await readFile(new URL('../public/assets/planner.js',import.meta.url),'utf8')).replace(/from '\.\/ui\.js\?v=[^']+'/u,`from '${ui}'`);
const {YardPlanDraft,createYardPlanner}=await import(data(source));
let checks=0;const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
const catalog={buildings:{townhall:{size:3.4,name:'Town Hall'},planner:{size:2.6,name:'Yard Planner'},sniper:{size:1.8,name:'Sniper Tower'},trap:{size:1,name:'Booby Trap'}}};
const building=(id,type,x,y)=>({id,type,x,y,level:1,hp:700,max_hp:1000,ready_at:0,fortification:2,fortify_ready_at:0,stored:34});
const initial=()=>({owner:{id:1},buildings:[building(1,'townhall',16,16),building(2,'planner',8,8),building(3,'sniper',24,24),building(4,'trap',5,24)],army:{pokey:3},resources:{twig:10000},garrison:{3:{pokey:2}},planner:{available:true,reason:'',revision:'revision-a',maxSlots:2,slots:[],lockedIds:[]}});
{
 const live=initial(),before=structuredClone(live),d=new YardPlanDraft(live,catalog);
 check(d.check().valid&&!d.dirty,'opening starts from a complete valid live layout');
 d.stash(2);check(d.unplaced.length===1&&!d.check().valid,'temporary storage requires the building to be placed before apply');
 check(d.place(3,8,8)===null,'stashing makes the first side of a swap available');
 check(d.place(2,24,24)===null&&d.check().valid,'a complete two-building swap is valid');
 check(live.buildings[1].x===8&&live.buildings[2].x===24,'draft swaps never change the live base');
 check(JSON.stringify(live)===JSON.stringify(before),'draft editing preserves all health, work, garrisons and resources');
 const positions=d.payload().positions;check(positions.every(p=>Object.keys(p).sort().join(',')==='id,type,x,y'),'requests contain coordinates and identity only');
 check(d.payload().revision==='revision-a','apply carries the original geometry revision');
 d.undo();check(d.unplaced.length===1,'undo restores a previous draft without inventing buildings');
 d.reset(live);check(!d.dirty&&d.history.length===0&&d.positions.length===4,'reset restores the latest live layout and clears undo history');
 d.clear();check(d.positions.length===0&&d.unplaced.length===4,'clear moves all movable buildings into temporary storage');
 d.undo();check(d.check().valid,'undo can restore a cleared draft');
 check(!!d.place(2,16,16)&&!d.dirty,'overlapping placement is rejected without changing the draft');
 check(!!d.place(2,1,1)&&!!d.place(2,NaN,7),'outside and nonfinite coordinates are rejected');
 check(d.place(2,2.3,2.3)===null,'nominal exact footprint boundaries tolerate floating-point subtraction');
}
{
 const live=initial();live.planner.lockedIds=[2];live.buildings[1].ready_at=2000000000;
 const d=new YardPlanDraft(live,catalog);check(!!d.stash(2)&&!!d.place(2,10,10),'busy buildings cannot move or enter storage');
 d.clear();check(d.positions.length===1&&d.positions[0].id===2,'clear preserves the actual position of pinned work');
 const slot={name:'Old plan',positions:[{id:1,type:'townhall',x:16,y:16},{id:2,type:'planner',x:20,y:8},{id:99,type:'sniper',x:5,y:5}]};
 d.load(slot);check(d.positions.find(p=>p.id===2).x===8,'loading an old template retains the actual busy-building position');
 check(d.unplaced.map(b=>b.id).join(',')==='3,4','current buildings absent from an old plan remain in storage');
 check(!d.positions.some(p=>p.id===99)&&d.message.includes('omitted'),'obsolete template identities are omitted with an explanation');
 check(live.buildings.length===4,'old template loading cannot create or delete live buildings');
}
{
 const live=initial(),d=new YardPlanDraft(live,catalog);d.place(2,8,12);
 const passive=structuredClone(live);passive.resources.twig+=90;passive.buildings[1].hp=900;d.sync(passive);
 check(!d.stale&&d.buildings.find(b=>b.id===2).x===8&&d.buildings.find(b=>b.id===2).y===12,'passive refresh preserves unsaved geometry');
 check(d.buildings.find(b=>b.id===2).hp===900,'passive refresh uses current building health while preserving the draft');
 const changed=structuredClone(passive);changed.planner.revision='revision-b';changed.buildings.push(building(5,'sniper',26,7));d.sync(changed);
 check(d.stale&&!d.check().valid,'another tab adding a building makes the draft stale');
 d.reset(changed);check(d.records.size===5&&d.revision==='revision-b'&&!d.stale,'reset deliberately adopts the new inventory and revision');
 changed.planner.available=false;changed.planner.reason='Repair the Yard Planner first.';d.sync(changed);check(!d.check().valid&&d.check().errors.includes(changed.planner.reason),'a destroyed Planner disables saving and applying');
}
function harness(){
 const nodes=new Map(),messages=[],requests=[],applied=[];
 const node=id=>{if(!nodes.has(id))nodes.set(id,{innerHTML:'',textContent:'',hidden:false,dataset:{},value:'',classList:{add(){},remove(){}},focus(){},setSelectionRange(){}});return nodes.get(id);};
 globalThis.document={querySelector:node,activeElement:null};
 const state={user:{id:1},base:initial(),army:{pokey:3},catalog,page:'yard',visiting:null,battle:null,placement:null,modal:null};
 const render={base:null,plan:null,setPlanner(p){this.plan=p;},setPlacement(p){this.placement=p;},setSelected(p){this.selected=p;},setBase(b){this.base=b;}};
 let planner;
 const applyState=result=>{applied.push(result);state.base=result.base;planner.sync();};
 const api=(action,input)=>new Promise((resolve,reject)=>requests.push({action,input,resolve,reject}));
 planner=createYardPlanner({state,getRenderer:()=>render,api,applyState,toast:(message)=>messages.push(message),art:()=>'',showModal:(html,type)=>{state.modal=type;node('#modal-body').innerHTML=html;},closeModal:()=>{state.modal=null;},onOpen(){},onClose(){},now:()=>100});
 return {planner,state,render,nodes,node,messages,requests,applied};
}
{
 const live=initial(),d=new YardPlanDraft(live,catalog);d.stash(2);d.place(3,24,20);d.place(4,5,20);
 const working=structuredClone(live);working.planner.lockedIds=[2,3];working.buildings[1].ready_at=2000000000;working.buildings[2].fortify_ready_at=2000000000;d.sync(working);
 check(d.positions.find(p=>p.id===2)?.x===8&&d.positions.find(p=>p.id===3)?.y===24,'newly started work restores stored and moved buildings to their live pinned positions');
 check(d.positions.find(p=>p.id===4)?.y===20&&d.message.includes('other edits are preserved'),'pinning new work preserves unrelated draft edits and explains the change');
 d.undo();check(d.positions.find(p=>p.id===2)?.x===8&&d.positions.find(p=>p.id===3)?.y===24&&d.check().valid,'undo cannot undo authoritative construction pins');
}
{
 const h=harness();h.planner.open();check(h.planner.active()&&h.render.plan.grid&&h.render.plan.ranges,'opening enables draft grid and range previews');
 h.planner.handleInput({dataset:{plannerName:'1'},value:'North <&> yard'});
 await h.planner.handleClick({dataset:{planner:'stash',id:'2'}});check(h.render.base.buildings.length===3&&h.state.base.buildings.length===4,'storage is rendered independently of the live inventory');
 await h.planner.handleClick({dataset:{planner:'move',id:'2'}});h.planner.place({x:8,y:12},{mode:'planner',id:2});
 const saving=h.planner.handleClick({dataset:{planner:'save',slot:'1'}});
 check(h.requests[0].action==='plannerSave'&&h.requests[0].input.name==='North <&> yard','saving sends the intended named slot through the API');
 check(h.requests[0].input.positions.find(p=>p.id===2).y===12,'saving sends the staged coordinates');
 const fresh=structuredClone(h.state.base);fresh.planner.slots=[{slot:1,name:'North <&> yard',positions:h.requests[0].input.positions,savedAt:100}];h.requests[0].resolve({base:fresh});await saving;
 check(h.planner.active()&&h.render.base.buildings.find(b=>b.id===2).y===12,'save response retains the unsaved applied-to-live geometry');
 check(h.state.base.buildings.find(b=>b.id===2).y===8,'saving a template never moves the live base');
 check(h.node('#yard-panel').innerHTML.includes('North &lt;&amp;&gt; yard'),'saved names are safely escaped in controls');
 await h.planner.handleClick({dataset:{planner:'save',slot:'1'}});check(h.state.modal==='planner-save'&&h.requests.length===1,'replacing an existing slot first reviews the overwrite');
 h.state.modal=null;const applying=h.planner.handleClick({dataset:{planner:'apply'}});
 const changed=structuredClone(fresh);changed.buildings[1].y=12;changed.planner.revision='revision-b';h.requests[1].resolve({base:changed});await applying;
 check(!h.planner.active()&&h.state.base.buildings[1].y===12,'successful apply exits the draft with the authoritative positions');
}
{
 const h=harness();h.planner.open();const pending=h.planner.handleClick({dataset:{planner:'apply'}});
 check(h.planner.escape()&&h.planner.active(),'Escape waits for an already submitted layout write');
 await h.planner.handleClick({dataset:{planner:'cancel'}});check(h.planner.active(),'Cancel also waits for an already submitted layout write');
 h.requests[0].reject(new Error('Try again.'));await pending;h.planner.escape();check(!h.planner.active(),'Escape works again once the pending request has finished');
}
{
 const h=harness();h.planner.open();h.planner.handleInput({dataset:{plannerName:'1'},value:'Late save'});const pending=h.planner.handleClick({dataset:{planner:'save',slot:'1'}});
 h.planner.cancel();h.state.page='rankings';h.requests[0].resolve({base:initial()});await pending;
 check(!h.planner.active()&&h.applied.length===0,'a late save cannot reopen a canceled draft or replace newer navigation');
}
{
 const h=harness();h.planner.open();const pending=h.planner.handleClick({dataset:{planner:'apply'}});h.state.user={id:2};h.requests[0].resolve({base:initial()});await pending;
 check(h.applied.length===0,'an old account response cannot install its base into a new account');
}
{
 const h=harness();h.planner.open();const pending=h.planner.handleClick({dataset:{planner:'apply'}});h.requests[0].reject(new Error('The layout changed. Refresh your yard.'));await pending;
 check(h.planner.active()&&h.messages.some(m=>m.includes('layout changed')),'rejected stale writes retain the draft and expose the server explanation');
 h.planner.cancel();h.state.base.planner.available=false;h.planner.open();check(!h.planner.active(),'a missing or damaged Planner cannot open the editor');
 h.state.base.planner.available=true;h.state.visiting={owner:{id:2}};h.planner.open();check(!h.planner.active(),'rival scouting cannot enter the private editor');
}
console.log(`${checks} planner UI checks passed.`);
