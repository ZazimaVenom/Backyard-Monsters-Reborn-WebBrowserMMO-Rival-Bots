import assert from 'node:assert/strict';
import {createTribes,isTerritory} from '../public/assets/tribes.js';

const camp = {stage:1,name:'Legionnaire border camp',tribe:'Legionnaire',tier:'Border camp',open:true,reward:{twig:80},record:null};
const base = {tribe:camp,previousId:null};
const listing = {camps:[camp,{...camp,stage:2,name:'Kozu fort',open:false}],cleared:0,pending:{twig:80}};
let checks=0;
const check=(value,label)=>{assert.ok(value,label);++checks;};
function harness(){
  const state={user:{id:7},army:{pokey:2},visiting:null,modal:null};
  const requests=[],modals=[],entered=[],visited=[],notices=[];
  let epoch=0;
  const app=createTribes({state,api:(action,input,params)=>new Promise(resolve=>requests.push({action,input,params,resolve})),
    showModal:(html,type)=>{state.modal=type;modals.push(html);},closeModal:()=>{state.modal=null;},
    navigationIntent:()=>++epoch,navigationCurrent:token=>token===epoch,
    inspect:data=>{state.visiting=data;visited.push(data);},enter:data=>entered.push(data),
    readyChampion:()=>null,hasFlinger:()=>true,toast:message=>notices.push(message),buttonBusy(){},refreshHome(){}});
  return {app,state,requests,modals,entered,visited,notices,leave(){++epoch;}};
}
const button=(dataset={},attr='')=>({dataset,hasAttribute:key=>key===attr});
{
  const h=harness();
  let p=h.app.handleClick(button({},'data-tribes-open'));
  check(h.requests[0].action==='tribes','Opening queries the authenticated route');
  h.requests[0].resolve(listing);await p;
  check(h.modals[0].includes('0 of 2 camps cleared'),'Campaign progress');
  check(h.modals[0].includes('data-tribe-scout="2" disabled')&&h.modals[0].includes('data-tribe-collect'),'Locked stage and bank controls');
  p=h.app.handleClick(button({tribeScout:'1'}));h.requests[1].resolve(base);await p;
  check(h.visited[0]===base && h.state.modal===null,'Scouting opens the real camp in the yard');
  check(h.app.scoutPanel(base).includes('data-tribe-plan="1"'),'Camp offers its own invasion control');
  await h.app.handleClick(button({tribePlan:'1'}));
  check(h.state.modal==='tribe-confirm'&&h.modals.at(-1).includes('leave your army'),'Launch confirmation explains cost');
  const key=h.modals.at(-1).match(/data-request="([0-9a-f]{32})"/)?.[1];
  check(!!key,'Scoped retry token');
  p=h.app.handleClick(button({tribeStart:'1',request:key}));
  check(h.requests[2].input.requestId===key&&h.requests[2].input.previousId===null,'Launch supplies preview revision');
  h.requests[2].resolve({id:32});await p;
  check(h.entered[0].id===32,'Play uses authoritative battle');
  const html=h.app.result({target:{name:camp.name},result:{attackerWon:true,destruction:64,campaign:{firstClear:true,nextStage:2,pending:{}},loot:{twig:80}}},'');
  check(html.includes('next expedition is open')&&html.includes('80 twig')&&!html.includes('Territory'),'Expedition result displays the unlocked route and first-clear supplies');
}
{
  const h=harness(), p=h.app.handleClick(button({tribeScout:'1'}));
  h.leave();h.requests[0].resolve(base);await p;
  check(!h.visited.length,'Stale scouting response cannot hijack navigation');
  h.state.battle={status:'active'};
  await h.app.handleClick(button({},'data-tribes-open'));
  check(h.requests.length===1,'An active battle keeps control');
}
check(!isTerritory({kind:'tribe'})&&isTerritory({kind:'fortification'}),'Expeditions use destruction goals instead of capture UI');
console.log('Tribes UI: '+checks+' checks passed.');
