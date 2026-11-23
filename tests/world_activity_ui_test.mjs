import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const source=await readFile(new URL('../public/assets/worldactivity.js',import.meta.url),'utf8');
const {createWorldActivity,activityView}=await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
let checks=0;
const check=(condition,label)=>{assert.ok(condition,label);checks++;console.log(`PASS ${label}`);};
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
const status=(extra={})=>({mode:'active',onlinePlayers:1,onlineTabs:1,leaseSeconds:45,pulseSeconds:5,lastTick:1000,lastResult:{status:'ok',processed:25,active_battles:2},...extra});
const reply=(extra={})=>({serverTime:1000,world:{bots:1000,players:1,activity:status(extra)}});
const flush=async()=>{await Promise.resolve();await Promise.resolve();};
function harness(){
 let timestamp=1000000,nextTimer=0,nextId=0;
 const state={user:null,csrf:'csrf-before',serverOffset:0},requests=[],sent=[],updates=[],views=[],timers=new Map(),windowEvents=new Map(),documentEvents=new Map();
 const eventTarget=events=>({addEventListener:(name,fn)=>{const values=events.get(name)||[];values.push(fn);events.set(name,values);}});
 const documentTarget={...eventTarget(documentEvents),hidden:false};
 const app=createWorldActivity({state,request:(action,input)=>{const pending=deferred();requests.push({action,input,...pending});return pending.promise;},send:(url,options)=>{sent.push({url,options});return Promise.resolve({ok:true});},onStatus:view=>views.push(view),onPulse:world=>updates.push(world),clock:()=>timestamp,schedule:(callback,delay)=>{const id=++nextTimer;timers.set(id,{callback,delay});return id;},cancel:id=>timers.delete(id),newId:()=>`client-unique-${String(++nextId).padStart(8,'0')}`,windowTarget:eventTarget(windowEvents),documentTarget});
 const fire=(events,name)=>{for(const fn of events.get(name)||[])fn({});};
 const runTimer=()=>{assert.equal(timers.size,1);const [id,item]=[...timers][0];timers.delete(id);timestamp+=item.delay;item.callback();return item.delay;};
 return {app,state,requests,sent,updates,views,timers,documentTarget,windowEvents,documentEvents,runTimer,fire,setTime:value=>timestamp=value};
}
{
 const h=harness();h.app.bind();h.app.bind();h.app.sync();await h.app.pulse();
 check(h.requests.length===0&&h.timers.size===0,'signed-out visitors never create a presence lease or wake bots');
 check(h.windowEvents.get('pagehide').length===1&&h.documentEvents.get('visibilitychange').length===1,'lifecycle handlers bind exactly once');
 check(h.app.view().kind==='waiting','signed-out status explains that signing in wakes the world');
 h.state.user={id:9};h.app.sync();
 check(h.requests.length===1&&h.requests[0].action==='worldPulse','normal sign-in immediately starts the authenticated pulse');
 check(Object.keys(h.requests[0].input).join()==='clientId','the client cannot select player identity, bot count or tick budget');
 h.app.sync();await h.app.pulse();
 check(h.requests.length===1,'bootstrap refresh and repeated wake events cannot overlap a pending heartbeat');
 h.requests[0].resolve(reply());await flush();
 check(h.app.view().kind==='active'&&h.updates.length===1,'a recent successful server tick displays active status');
 check([...h.timers.values()][0].delay===5000,'the next heartbeat uses the server five-second cadence');
 check(h.app.view().detail.includes('2 battles'),'activity detail uses the actual shared battle count');
 h.app.observe(status({mode:'waiting',onlinePlayers:0,lastTick:null}));
 check(h.app.view().kind==='active','an older bootstrap cannot overwrite a confirmed pulse status');
 h.documentTarget.hidden=true;h.runTimer();
 check(h.requests.length===2,'an open background game tab continues its presence heartbeat');
 h.requests[1].resolve(reply());await flush();
 h.app.stop();
 check(h.timers.size===0&&h.sent.length===1,'stopping the game clears the timer and releases only this tab lease');
 check(h.sent[0].url==='api.php?action=worldLeave'&&h.sent[0].options.keepalive===true&&h.sent[0].options.credentials==='same-origin','tab departure uses a same-origin keepalive POST');
 check(h.sent[0].options.headers['X-CSRF-Token']==='csrf-before'&&JSON.parse(h.sent[0].options.body).clientId===h.requests[0].input.clientId,'leave carries normal CSRF protection and the exact lease identifier');
 h.state.user=null;h.app.sync();check(h.app.view().kind==='waiting','sign-out clears stale online status');
}
{
 const h=harness();h.state.user={id:2};h.app.sync();const first=h.requests[0];h.app.stop();h.state.user={id:3};h.state.csrf='csrf-new';h.app.sync();
 check(h.requests[1].input.clientId!==first.input.clientId,'each authenticated document lifecycle receives a new lease');
 first.resolve(reply({onlinePlayers:99}));await flush();
 check(h.updates.length===0,'an old account pulse cannot install its activity after an account switch');
 h.requests[1].resolve(reply());await flush();check(h.updates.length===1&&h.app.view().label.includes('1 online'),'the new account retains its own in-flight pulse and status');
 check(h.timers.size===1,'an obsolete response cannot create a second heartbeat loop');
}
{
 const h=harness();h.app.bind();h.state.user={id:4};h.app.sync();const firstId=h.requests[0].input.clientId;
 h.fire(h.windowEvents,'pagehide');check(h.sent.length===1&&h.timers.size===0,'pagehide immediately releases this tab without waiting for the pending tick');
 h.fire(h.windowEvents,'pageshow');check(h.requests.length===2&&h.requests[1].input.clientId!==firstId,'back-forward-cache restoration obtains a fresh lease');
 check(JSON.parse(h.sent[0].options.body).clientId!==h.requests[1].input.clientId,'a delayed leave cannot remove the restored page’s fresh lease');
 h.requests[0].resolve(reply({onlinePlayers:88}));await flush();check(h.updates.length===0,'the departed document’s response is discarded');
 h.requests[1].resolve(reply());await flush();h.fire(h.windowEvents,'pageshow');check(h.requests.length===2&&h.timers.size===1,'ordinary duplicate pageshow events keep a single simulation loop');
}
{
 const h=harness();h.state.user={id:5};h.app.sync();h.requests[0].reject(new Error('offline'));await flush();
 check(h.app.view().kind==='reconnecting'&&h.app.view().label.includes('Reconnecting'),'failed heartbeat never claims that bots are active');
 check(h.timers.size===1,'connection failure schedules recovery without an unhandled rejection');
 h.app.observe(status());check(h.app.view().kind==='reconnecting','a recent historical heartbeat cannot hide the current connection failure');
 h.runTimer();h.requests[1].resolve(reply({mode:'disabled'}));await flush();check(h.app.view().kind==='disabled','server-disabled simulation stays visibly disabled after reconnection');
 h.runTimer();h.requests[2].resolve({world:{bots:1000}});await flush();check(h.app.view().kind==='reconnecting','an incomplete pulse response cannot falsely show active');
 h.runTimer();h.requests[3].resolve(reply({mode:'waiting',onlinePlayers:0,lastTick:1000}));await flush();check(h.app.view().kind==='waiting','zero players overrides even a recent completed tick');
}
{
 const h=harness();h.app.bind();h.state.user={id:6};h.app.sync();h.requests[0].resolve(reply());await flush();
 h.documentTarget.hidden=true;h.fire(h.documentEvents,'visibilitychange');check(h.requests.length===1,'hiding the page does not release a still-open game');
 h.documentTarget.hidden=false;h.fire(h.documentEvents,'visibilitychange');check(h.requests.length===2&&h.timers.size===0,'returning to the game immediately renews an expired or throttled presence');
 h.fire(h.windowEvents,'online');check(h.requests.length===2,'network recovery cannot duplicate the already pending pulse');
 h.requests[1].resolve(reply());await flush();check(h.timers.size===1,'visibility recovery preserves one timer');
}
{
 check(activityView(status({lastTick:null}),{now:1000}).kind==='starting','a player lease alone does not prove the simulation has run');
 check(activityView(status({lastTick:900}),{now:1000}).kind==='starting','a stale tick is never presented as current active work');
 check(activityView(status({lastResult:{status:'error'}}),{now:1000}).kind==='error','a failed scheduler result displays retrying instead of active');
 check(activityView(status({mode:'disabled'}),{now:1000}).kind==='disabled','disabled configuration is distinct from nobody online');
 check(activityView(status({mode:'waiting',onlinePlayers:0}),{now:1000}).kind==='waiting','an empty realm is explicitly paused');
 check(!activityView(status(),{now:1000}).detail.includes('worker.php'),'ordinary players are never instructed to run a manual world worker');
}
{
 const h=harness();h.state.user={id:12};h.state.battle={id:-14,status:'active',training:true};h.app.sync();
 h.requests[0].resolve(reply());await flush();h.runTimer();
 check(h.requests[1]?.action==='worldPulse'&&h.requests[1].input.clientId===h.requests[0].input.clientId,'watching a Baiter wave renews the signed-in keeper’s existing bot activity lease');
}
console.log(`World activity UI: ${checks} checks passed.`);
