import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const dataUrl = code => 'data:text/javascript;base64,'+Buffer.from(code).toString('base64');
const ui = dataUrl(await readFile(new URL('../public/assets/ui.js',import.meta.url),'utf8'));
const source = (await readFile(new URL('../public/assets/truces.js',import.meta.url),'utf8'))
  .replace(/from '\.\/ui\.js\?v=[^']+'/u,`from '${ui}'`);
const {createTruces} = await import(dataUrl(source));
let checks=0;
function check(value) { assert.ok(value); ++checks; }
const state={user:{id:1},page:'invasion',modal:null,visiting:{owner:{id:2}}};
const calls=[], errors=[], modals=[];
let refreshes=0;
const controller=createTruces({state,
  api:(action,body,params)=>new Promise((resolve,reject)=>calls.push({action,body,params,resolve,reject})),
  showModal:(html,kind)=>{state.modal=kind;modals.push(html);},toast:message=>errors.push(message),
  loadRivals:async()=>{refreshes++;},
});
const button=(dataset)=>({dataset,disabled:false});
const peer={keeper:2,name:'<Keeper & Friend>',isBot:false,status:'none',token:'',outgoing:false,expires:0};
const data={selected:2,owner:1,keepers:[peer]};
check(!await controller.handleClick(button({foo:'test'})));
let first=controller.handleClick(button({truceOpen:'2'}));
check(calls[0].action==='truces'&&calls[0].params.keeper===2);
calls.shift().resolve(data);await first;
check(modals.at(-1).includes('&lt;Keeper &amp; Friend&gt;')&&!modals.at(-1).includes('<Keeper & Friend>'));
const token=modals.at(-1).match(/data-token="([a-f0-9]{32})"/u)[1];
const proposal=button({truceAction:'request',peer:'2',token,previous:''});
first=controller.handleClick(proposal);
await controller.handleClick(proposal);
check(calls.length===1&&proposal.disabled);
check(calls[0].body.kind==='request'&&calls[0].body.token===token);
calls.shift().resolve({...data,keepers:[{...peer,status:'requested',token,outgoing:true}]});await first;
check(modals.at(-1).includes('Withdraw')&&!modals.at(-1).includes('data-truce-action="accept"')&&refreshes===1);
first=controller.handleClick(button({truceOpen:'0'}));
calls.shift().resolve({...data,selected:0,keepers:[{...peer,status:'requested',token},{...peer,keeper:3,name:'Third',status:'accepted',expires:1980000000}]});await first;
check(modals.at(-1).includes('Offers to answer')&&modals.at(-1).includes('Active truces'));
check(modals.at(-1).includes('data-truce-action="accept"')&&modals.at(-1).includes('data-truce-action="decline"'));
first=controller.handleClick(button({truceAction:'accept',peer:'2',token,previous:''}));
calls.shift().resolve({...data,keepers:[{...peer,status:'accepted',token,expires:1980000000}]});await first;
check(state.visiting.truceUntil===1980000000&&refreshes===2);
check(modals.at(-1).includes('Protected until')&&!modals.at(-1).includes('truce-action="withdraw"'));
first=controller.handleClick(button({truceOpen:'2'}));const older=calls.shift();
const second=controller.handleClick(button({truceOpen:'9'}));
calls.shift().resolve({...data,selected:9,keepers:[{...peer,keeper:9,isBot:true,name:'Fast bot'}]});await second;
older.resolve(data);await first;
check(modals.at(-1).includes('Fast bot')&&modals.at(-1).includes('Raiders decline'));
first=controller.handleClick(button({truceOpen:'2'}));state.modal='neighbors';
calls.shift().resolve(data);await first;
check(state.modal==='neighbors');
first=controller.handleClick(button({truceOpen:'2'}));state.user={id:999};
calls.shift().reject(new Error('stale'));await first;
check(errors.length===0);
console.log(`Truce UI: ${checks} checks passed.`);
