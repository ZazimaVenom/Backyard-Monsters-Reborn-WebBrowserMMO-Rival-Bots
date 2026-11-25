import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const gameModule = name => import(new URL(`../public/assets/${name}.js`, import.meta.url));
const [{createTerritories},{createYardPlanner},{createHomeRepair},{createBaiter},{createTribes,isTerritory},
  {createQuests},{createAlliances},{createNeighbors},{createTruces},{createSiegeWorkshop},{createSiegeCombat,siegeLog},{createInferno},{createAscension},{createYardUtilities},{createMushrooms},{createDecorations,decorationSelection},{createYardMessages}] = await Promise.all(
    ['territories','planner','repairs','baiter','tribes','quests','alliances','neighbors','truces','siege-workshop','siege-combat','inferno','ascension','yard-utilities','mushrooms','decorations','yard-messages'].map(gameModule)
  );
const mainSource = (await readFile(new URL('../public/assets/main.js', import.meta.url), 'utf8')).replace(/^import .*;\r?\n/gm, '').replace(/\nboot\(\);\s*$/, '\n');
let checks = 0;
const check = (condition, label) => { assert.ok(condition, label); checks++; console.log(`PASS ${label}`); };
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return {promise, resolve, reject}; };
const base = id => ({owner:{id,name:`Keeper ${id}`},buildings:[{id:1,type:'townhall',level:2,hp:100,max_hp:100}],army:{},resources:{},champions:{}});
const site = id => ({site:{id,kind:'fortification',name:`Fort ${id}`,level:3,q:5,r:5,owned:false,reachable:true,networkDefense:{count:0,reduction:0,siteIds:[]}},base:{...base(2),buildings:[{id:1,type:'outpostdefender',level:3,hp:5000,max_hp:5000}]},canAttack:true});
const battle = id => ({id,status:'active',attacker:{id:1,name:'Keeper 1'},defender:{id:2,name:'Keeper 2'},target:{kind:'fortification',name:'Fort 31'},units:[],buildings:[{id:1,type:'outpostdefender',level:3,hp:5000,max_hp:5000}],remaining:{},bucket:{},duration:300,elapsed:0});

function harness() {
  const nodes = new Map(), requests = [], renders = [], messages = [], music = [];
  const node = key => {
    if (!nodes.has(key)) nodes.set(key, {innerHTML:'',textContent:'',hidden:false,disabled:false,inert:false,isConnected:true,attributes:{},setAttribute(name,value){this.attributes[name]=value;},dataset:{},style:{},scrollTop:0,classList:{toggle(){},add(){},remove(){}},focus(){},querySelector(){return null;},querySelectorAll(){return [];},append(value){messages.push(value.textContent);},remove(){}});
    return nodes.get(key);
  };
  const api = (url, options = {}) => {
    const request = deferred(), action = new URL(url, 'http://localhost').searchParams.get('action');
    requests.push({action,query:Object.fromEntries(new URL(url, 'http://localhost').searchParams),input:options.body ? JSON.parse(options.body) : null,
      resolve:data=>request.resolve({status:200,json:async()=>({ok:true,data})}),reject:request.reject});
    return request.promise;
  };
  const noops = () => ({refresh(){},battleRefresh(){},reset(){},handleClick:async()=>false,networkPanel:()=>'',panel:()=>''});
  const context = vm.createContext({
    console, createYardMessages, createDecorations, decorationSelection, URLSearchParams, performance, fetch:api, createTerritories,createBaiter,createTribes,isTerritory,createQuests,createAlliances,createNeighbors,createTruces,createSiegeWorkshop,createSiegeCombat,siegeLog,createInferno,createAscension,createYardUtilities,createMushrooms,renderInfernoMonsters:()=>'',infernoMonsterAction:()=>null,
    icon:()=>'',icons(){},esc:value=>String(value??''),num:value=>String(value??0),short:value=>String(value??0),duration:value=>String(value??0),timeAgo:()=>'',
    GameAudio:class {effect(){} playMusic(track){music.push(track);} unlock(){}},
    createWorkshop:noops,createLaboratory:noops,createChampionHall:noops,createFortifications:noops,
    createYardPlanner,createHomeRepair,createWorkers:()=>({refresh(){},canStart:()=>true,reason:()=>''}),
    createWorldActivity:options=>({sync(){},stop(){},observe(){},bind(){},emit:options.onPulse,view:()=>({label:'Bots active',kind:'active',detail:'Testing'})}),
    createWorldMap:()=>({setVisible(){},open:async()=>{},refresh:async()=>{},reset(){}}),
    document:{activeElement:null,hidden:false,querySelector:node,querySelectorAll:()=>[],createElement:()=>node(`new-${nodes.size}`),getElementById:id=>node(`#${id}`)},
    window:{scrollTo(){}},requestAnimationFrame:callback=>callback(),setTimeout:()=>1,clearTimeout(){},setInterval:()=>1,
    renders,
  });
  vm.runInContext(mainSource + `
    const realRefresh=refresh,realLoadRankings=loadRankings;
    render={setPlanner(){},setSelected(){},setBattle(){},setBase(value){renders.push(value);},resetCamera(){},setPlacement(){},resize(){}};
    renderPanel=()=>{};renderArmy=()=>{};renderResources=()=>{};refresh=async()=>{};
    loadRivals=async()=>{};loadRankings=async()=>{};loadReports=async()=>{};
    readyChampion=()=>null;hasFlinger=()=>true;
    globalThis.testNavigation={state,inferno,tribes,quests,alliances,neighbors,baiter,presentDrill,leaveDrill,pollBattle,buildingUpgradeGate,showBuild,specialDefenseStats,buildUnavailableReason,planner,visit,home,planAttack,startAttack,openBattle,setPage,resetAccount,showModal,closeModal,updateYardHeading,territories,worldActivity,realRefresh,realLoadRankings,action,repairs,applyState,command,setRefresh(fn){refresh=fn;},setRankings(fn){loadRankings=fn;}};
  `, context);
  globalThis.document=context.document;
  const app = context.testNavigation;
  Object.assign(app.state, {user:{id:1,name:'Keeper 1'},base:base(1),army:{pokey:3},catalog:{buildings:{townhall:{size:3,cost:{twig:100},name:'Town Hall',description:'Home',maxLevel:10},outpostdefender:{size:3},outpost:{untargetable:true}},monsters:{},champions:{}},page:'invasion'});
  return {app, requests, renders, node, messages, music};
}

{
  const h = harness(), first = h.app.visit(2), second = h.app.visit(3);
  h.requests[1].resolve(base(3)); await second; h.requests[0].resolve(base(2)); await first;
  check(h.app.state.visiting.owner.id === 3 && h.renders.length === 1, 'a slower home visit cannot replace the newer chosen backyard');
}
{
  const h = harness(), first = h.app.visit(2), second = h.app.territories.visit(31);
  h.requests[1].resolve(site(31)); await second; h.requests[0].resolve(base(2)); await first;
  check(h.app.state.worldSite.site.id === 31 && h.app.state.visiting.buildings[0].type === 'outpostdefender', 'a newer territory visit invalidates an older home visit');
}
{
  const h = harness(), first = h.app.territories.visit(31), second = h.app.visit(2);
  h.requests[1].resolve(base(2)); await second; h.requests[0].resolve(site(31)); await first;
  check(h.app.state.worldSite === null && h.app.state.visiting.owner.id === 2, 'a newer home visit invalidates an older territory visit');
}
{
  const h = harness(), pending = h.app.visit(2); await h.app.home(); h.requests[0].resolve(base(2)); await pending;
  check(h.app.state.visiting === null && h.renders.at(-1).owner.id === 1, 'returning home cannot be undone by a pending rival response');
}
{
  const h = harness(), wait = deferred(); h.app.setRefresh(()=>wait.promise);
  const returning = h.app.home(); await h.app.setPage('rankings'); wait.resolve(); await returning;
  check(h.app.state.page === 'rankings', 'a delayed home refresh cannot navigate away from a newer page choice');
}
{
  const h = harness(), pending = h.app.visit(2); await h.app.setPage('rankings'); h.requests[0].resolve(base(2)); await pending;
  check(h.app.state.page === 'rankings' && h.app.state.visiting === null, 'leaving the scouting page invalidates pending home navigation');
}
{
  const h = harness(), pending = h.app.visit(2); h.app.resetAccount(); h.app.state.user={id:4}; h.requests[0].resolve(base(2)); await pending;
  check(h.app.state.user.id === 4 && h.app.state.visiting === null, 'a rival read from the old account cannot install its yard after sign-out');
}
{
  const h = harness(), pending = h.app.territories.visit(31); h.app.state.user={id:4}; h.requests[0].resolve(site(31)); await pending;
  check(h.app.state.worldSite === null && h.renders.length === 0, 'territory navigation independently rejects an account identity change');
}
{
  const h = harness(), pending = h.app.visit(2), opening = h.app.openBattle(50);
  h.requests[0].resolve(base(2)); await pending; h.requests[1].resolve(battle(50)); await opening;
  check(h.app.state.battle.id === 50 && h.app.state.visiting === null, 'opening a battle invalidates a rival read before the battle response arrives');
  check(h.node('#yard-title').innerHTML.includes('LV 3'), 'Fortification battle heading uses the targetable core level');
  const count = h.requests.length; await h.app.visit(3);
  check(h.requests.length === count && h.app.state.battle.id === 50, 'scouting cannot replace an active battlefield');
}
{
  const h = harness(), pending = h.app.visit(2), starting = h.app.startAttack(3,{disabled:false,dataset:{},innerHTML:'Begin'});
  h.requests[0].resolve(base(2)); await pending; h.requests[1].resolve(battle(51)); await starting;
  check(h.app.state.battle.id === 51 && h.app.state.visiting === null, 'starting an invasion invalidates pending home scouting');
}
{
  const h = harness(), opening = h.app.openBattle(50); await h.app.home(); h.requests[0].resolve(battle(50)); await opening;
  check(h.app.state.battle === null && h.app.state.visiting === null, 'a canceled battle read cannot overwrite the newer home destination');
}
{
  const h = harness(), old = h.app.planAttack(2), latest = h.app.planAttack(3);
  h.requests[1].resolve(base(3)); await latest; h.requests[0].resolve(base(2)); await old;
  check(h.node('#modal-body').innerHTML.includes('Invade Keeper 3?') && !h.node('#modal-body').innerHTML.includes('Invade Keeper 2?'), 'invasion planning shows only the newest rival response');
}
{
  const h = harness(), pending = h.app.planAttack(2); h.app.showModal('Keeper settings','settings'); h.requests[0].resolve(base(2)); await pending;
  check(h.app.state.modal === 'settings' && h.node('#modal-body').innerHTML === 'Keeper settings', 'a late invasion plan cannot replace a newer dialog');
}
{
  const h = harness(), pending = h.app.planAttack(2); h.app.closeModal(); h.requests[0].resolve(base(2)); await pending;
  check(h.app.state.modal === null && h.node('#modal-body').innerHTML === '', 'closing the dialog cancels a pending invasion plan');
}
{
  const h = harness(), pending = h.app.planAttack(2); h.app.state.user={id:4}; h.requests[0].resolve(base(2)); await pending;
  check(h.app.state.modal === null, 'a late invasion plan cannot cross an account change');
}
{
  const h = harness(), pending = h.app.territories.plan(31); h.app.showModal('Keeper settings','settings'); h.requests[0].resolve(site(31)); await pending;
  check(h.app.state.modal === 'settings' && h.node('#modal-body').innerHTML === 'Keeper settings', 'a late territory conquest plan cannot replace a newer dialog');
}
{
  const h = harness(), pending = h.app.visit(2); await h.app.setPage('rankings'); h.requests[0].reject(new Error('Old read failed')); await pending;
  check(h.messages.length === 0, 'a failed obsolete scouting request does not show an unrelated error on the new page');
}
{
  const h = harness(); h.app.state.worldSite=site(31); h.app.state.worldSite.site.owned=true; h.app.state.visiting=h.app.state.worldSite.base; h.app.updateYardHeading(); h.app.state.selected={type:'outpostdefender',level:3,hp:5000,max_hp:5000};
  check(h.app.territories.panel().includes('Building health') && !h.app.territories.panel().includes('cannot be targeted')&&h.node('#stage-hint').textContent==='Manage your territory defenses', 'Fortification core remains a real target with a health panel');
  h.app.state.worldSite.site.owned=false; h.app.updateYardHeading(); h.app.state.selected={type:'outpost',level:1,hp:1,max_hp:1};
  check(h.app.territories.panel().includes('cannot be targeted') && !h.app.territories.panel().includes('Building health')&&h.node('#stage-hint').textContent==='Scout defenses before you attack', 'only an untargetable Resource Outpost core replaces its misleading health panel with capture guidance');
}


function editable(h){
 const b=h.app.state.base;b.buildings=[{id:1,type:'townhall',x:16,y:16,level:2,hp:100,max_hp:100,ready_at:0}];
 b.planner={available:true,revision:'yard-a',positions:[{id:1,type:'townhall',x:16,y:16}],slots:[],lockedIds:[]};h.app.state.page='yard';
}
{
 const h=harness();editable(h);h.app.planner.open();check(h.app.planner.active(),'actual planner controller opens from the private home');
 await h.app.setPage('rankings');check(!h.app.planner.active()&&h.app.state.page==='rankings','normal page navigation closes the active draft before changing pages');
}
{
 const h=harness();editable(h);h.app.planner.open();h.app.planner.drag(1,{x:10,y:10});await h.app.setPage('yard');
 check(!h.app.planner.active()&&h.renders.at(-1).buildings[0].x===16,'choosing My backyard discards the draft and restores real coordinates immediately');
 h.app.planner.open();h.app.planner.drag(1,{x:10,y:10});const pending=h.app.planAttack(2);
 check(h.renders.at(-1).buildings[0].x===16,'waiting for a scout response already restores the real yard');
 h.requests[0].reject(new Error('Rival unavailable'));await pending;
 check(h.renders.at(-1).buildings[0].x===16&&!h.app.planner.active(),'a failed navigation cannot leave discarded draft coordinates on the canvas');
}
{
 const h=harness();editable(h);h.app.planner.open();const pending=h.app.planner.handleClick({dataset:{planner:'apply'}});
 const opening=h.app.openBattle(90);h.requests[1].resolve(battle(90));await opening;h.requests[0].resolve({base:base(1)});await pending;
 check(h.app.state.battle.id===90&&!h.app.planner.active(),'a delayed planner write cannot reopen the editor over a newer battle');
 check(h.app.state.base.planner.revision==='yard-a','a delayed planner response does not overwrite the current home during newer navigation');
}
{
 const h=harness();editable(h);const old=structuredClone(h.app.state.base),poll=h.app.realRefresh({resume:false,territory:false});
 h.app.planner.open();h.app.planner.drag(1,{x:10,y:10});const pending=h.app.planner.handleClick({dataset:{planner:'apply'}});
 const applied=structuredClone(old);applied.buildings[0].x=10;applied.buildings[0].y=10;applied.planner.revision='yard-b';
 h.requests[1].resolve({base:applied});await pending;h.requests[0].resolve({user:{id:1,name:'Keeper 1'},base:old});await poll;
 check(h.app.state.base.planner.revision==='yard-b'&&h.renders.at(-1).buildings[0].x===10,'an older bootstrap cannot roll back a successfully applied layout');
}
{
 const h=harness();editable(h);const old=structuredClone(h.app.state.base),poll=h.app.realRefresh({resume:false,territory:false});
 const hiring=h.app.action('hireWorker',{}),fresh=structuredClone(old);fresh.construction={capacity:2,available:2,busy:0,jobs:[]};
 h.requests[1].resolve({base:fresh});await hiring;h.requests[0].resolve({user:{id:1,name:'Keeper 1'},base:old});await poll;
 check(h.app.state.base.construction.capacity===2,'an older bootstrap cannot roll back a confirmed paid worker hire');
}
{
 const h=harness();editable(h);h.app.planner.open();h.app.resetAccount();
 check(!h.app.planner.active()&&h.app.state.user===null,'sign-out discards private draft state without leaking it to another account');
}
const ranking=(name='Bot Nova',page=1)=>({items:[{id:2,name,position:page,is_bot:true,townhall:2,wins:1,losses:0,rank_points:25}],page,pages:3,total:60});
{
 const h=harness();h.app.state.page='rankings';const first=h.app.realLoadRankings();h.app.state.rankPage=2;const second=h.app.realLoadRankings();
 h.requests[1].resolve(ranking('New page bot',2));await second;h.requests[0].resolve(ranking('Old page bot'));await first;
 check(h.node('#rankings-list').innerHTML.includes('New page bot')&&!h.node('#rankings-list').innerHTML.includes('Old page bot'),'a slower leaderboard page cannot replace the newly selected page');
 check(h.requests[1].query.page==='2','rank refresh preserves the selected leaderboard page');
}
{
 const h=harness();h.app.state.page='rankings';const pending=h.app.realLoadRankings();await h.app.setPage('yard');h.requests[0].resolve(ranking());await pending;
 check(!h.node('#rankings-list').innerHTML.includes('Bot Nova'),'leaderboard responses cannot paint after leaving rankings');
}
{
 const h=harness();h.app.state.page='rankings';const pending=h.app.realLoadRankings();h.app.resetAccount();h.requests[0].resolve(ranking());await pending;
 check(h.node('#rankings-list').innerHTML===''&&h.app.state.user===null,'an old leaderboard response cannot leak through logout or dereference a missing account');
}
{
 const h=harness();h.app.state.page='rankings';h.app.setRankings(h.app.realLoadRankings);h.app.worldActivity.emit({bots:1000});
 check(h.requests.length===1&&h.requests[0].action==='rankings','a successful game pulse refreshes the visible leaderboard automatically');
 h.app.worldActivity.emit({bots:1000});check(h.requests.length===1,'repeated game pulses do not duplicate an in-flight leaderboard refresh');
 h.requests[0].resolve(ranking());await new Promise(resolve=>setImmediate(resolve));
 check(h.node('#rankings-list').innerHTML.includes('Bot Nova')&&h.node('#rankings-list').innerHTML.includes('25'),'automatic refresh displays authoritative battle-earned ranking data');
 h.app.worldActivity.emit({bots:1000});check(h.requests.length===1,'leaderboard polling is throttled after a successful refresh');
 h.app.state.rankLoadedAt=Date.now()-16000;h.app.state.rankPage=3;h.app.worldActivity.emit({bots:1000});
 check(h.requests.length===2&&h.requests[1].query.page==='3','after fifteen seconds the current leaderboard page is refreshed');
 h.requests[1].reject(new Error('Temporary ranking delay'));await new Promise(resolve=>setImmediate(resolve));
 check(h.messages.length===0&&h.node('#rankings-list').innerHTML.includes('Bot Nova'),'a failed automatic refresh retains usable rankings without repeated error toasts');
 h.app.state.page='yard';h.app.worldActivity.emit({bots:1000});check(h.requests.length===2,'heartbeat never fetches an invisible leaderboard page');
}
function damagedHome(h){
  h.app.state.page='yard';h.app.state.base.buildings[0].hp=0;
  h.app.state.base.repair={damaged:1,available:true,free:true,instant:true};
  h.app.state.selected=h.app.state.base.buildings[0];h.app.updateYardHeading();
}
function repairedHome(h){return {...h.app.state.base,repair:{damaged:0,available:false,free:true,instant:true},buildings:h.app.state.base.buildings.map(b=>({...b,hp:b.max_hp}))};}
const flush=()=>new Promise(resolve=>setImmediate(resolve));
{
  const h=harness();damagedHome(h);
  const repairing=h.app.command('repair-base',{dataset:{}});
  check(h.requests.length===1&&h.requests[0].action==='repairBase'&&JSON.stringify(h.requests[0].input)==='{}','the real home button dispatches the owner-only whole-base repair');
  const repaired=repairedHome(h);h.requests[0].resolve({base:repaired,repairResult:{action:'repairBase',repaired:1}});await repairing;
  check(h.renders.at(-1).buildings[0].hp===100&&h.app.state.selected.hp===100,'confirmed home repair redraws full-health buildings and rebinds the selected panel immediately');
  check(h.node('#repair-base').disabled&&h.node('#repair-base').innerHTML.includes('No repairs needed'),'main snapshot application refreshes the ready control');
  check(!h.node('#yard-panel').innerHTML.includes('data-command="repair"'),'the repaired selected building no longer offers a stale repair action');
}
{
  const h=harness();damagedHome(h);const bootstrap=h.app.realRefresh(),repairing=h.app.repairs.repairAll();
  const oldBase=structuredClone(h.app.state.base),repaired=repairedHome(h);
  h.requests[1].resolve({base:repaired,repairResult:{repaired:1}});await repairing;
  h.requests[0].resolve({user:h.app.state.user,base:oldBase,activeBattle:null});await bootstrap;
  check(h.app.state.base.buildings[0].hp===100&&h.renders.at(-1).buildings[0].hp===100,'an older bootstrap cannot put rubble back after confirmed repair');
}
{
  const h=harness();damagedHome(h);const oldBase=structuredClone(h.app.state.base),collecting=h.app.action('collect',{}),repairing=h.app.repairs.repairAll();
  const repaired=repairedHome(h);h.requests[1].resolve({base:repaired,repairResult:{repaired:1}});await repairing;
  h.requests[0].resolve({base:oldBase,collected:{twig:40}});await flush();
  check(h.requests[2].action==='bootstrap'&&h.app.state.base.buildings[0].hp===100,'a delayed Collect response requests fresh state instead of reverting a confirmed repair');
  h.requests[2].resolve({user:h.app.state.user,base:{...repaired,resources:{twig:40}},activeBattle:null});await collecting;
  check(h.app.state.base.buildings[0].hp===100&&h.app.state.base.resources.twig===40,'post-mutation synchronization preserves both repaired buildings and collected resources');
}
{
  const h=harness();damagedHome(h);const oldBase=structuredClone(h.app.state.base),repairing=h.app.repairs.repairAll(),collecting=h.app.action('collect',{});
  h.requests[1].resolve({base:oldBase,collected:{twig:40}});await collecting;
  const bootstrap=h.app.realRefresh(),repaired=repairedHome(h);
  h.requests[0].resolve({base:repaired,repairResult:{repaired:1}});await flush();
  check(h.requests.length===4&&h.requests[3].action==='bootstrap','repair reconciliation fetches after commit even when a pre-repair bootstrap is in flight');
  h.requests[2].resolve({user:h.app.state.user,base:oldBase,activeBattle:null});await bootstrap;
  h.requests[3].resolve({user:h.app.state.user,base:repaired,activeBattle:null});await repairing;
  check(h.app.state.base.buildings[0].hp===100&&h.renders.at(-1).buildings[0].hp===100,'overlapping Collect, repair, and bootstrap resolve to restored buildings without waiting for the poll');
}
{
  const h=harness();damagedHome(h);const oldBase=structuredClone(h.app.state.base),repairing=h.app.repairs.repairAll();
  h.app.applyState({base:oldBase});h.requests[0].resolve({base:repairedHome(h),repairResult:{repaired:1}});await flush();
  h.app.applyState({base:{...oldBase,resources:{twig:90}}});
  h.requests[1].resolve({user:h.app.state.user,base:oldBase,activeBattle:null});await flush();
  check(h.requests.length===3&&h.requests[2].action==='bootstrap','reconciliation retries when a newer snapshot supersedes its in-flight response');
  h.requests[2].resolve({user:h.app.state.user,base:repairedHome(h),activeBattle:null});await repairing;
  check(h.app.state.base.resources.twig===90&&h.app.state.base.buildings[0].hp===100,'the retried response preserves the latest resources and restored HP');
}
{
  const h=harness();damagedHome(h);h.app.applyState({activeBattle:{id:7,status:'active'}});
  await h.app.command('repair-base',{dataset:{}});
  check(h.node('#repair-base').hidden&&h.requests.length===0,'bootstrap-discovered combat blocks repair before the battlefield has loaded');
}
{
  const h=harness();damagedHome(h);const repairing=h.app.repairs.repairAll();h.app.applyState({base:structuredClone(h.app.state.base)});
  h.requests[0].resolve({base:repairedHome(h),repairResult:{repaired:1}});await flush();await h.app.setPage('rankings');
  h.requests[1].resolve({user:h.app.state.user,base:repairedHome(h),activeBattle:null});await repairing;
  check(h.app.state.page==='rankings'&&h.app.state.base.buildings[0].hp===0&&!h.messages.length,'post-repair reconciliation abandons a newer navigation context without stale success');
}
{
  const h=harness();damagedHome(h);const repairing=h.app.repairs.repairAll();h.app.applyState({base:structuredClone(h.app.state.base)});
  h.requests[0].resolve({base:repairedHome(h),repairResult:{repaired:1}});await flush();h.requests[1].reject(new Error('Connection interrupted.'));await repairing;
  check(h.app.state.base.buildings[0].hp===0&&h.messages.length===1&&h.messages[0]==='Connection interrupted.','failed synchronization reports its error without claiming a completed redraw');
}
{
  const h=harness();damagedHome(h);const oldBase=structuredClone(h.app.state.base),first=h.app.action('collect',{}),second=h.app.action('move',{id:1,x:16,y:16});
  const repaired=repairedHome(h);h.app.applyState({base:repaired});
  h.requests[0].resolve({base:oldBase});await flush();h.requests[1].resolve({base:oldBase});await flush();
  h.requests[2].resolve({user:h.app.state.user,base:repaired,activeBattle:null});await flush();
  h.requests[3].resolve({user:h.app.state.user,base:repaired,activeBattle:null});await second;
  h.requests[4].resolve({user:h.app.state.user,base:repaired,activeBattle:null});await flush();
  h.requests[5].resolve({user:h.app.state.user,base:repaired,activeBattle:null});await first;
  check(h.requests.length===6&&h.app.state.base.buildings[0].hp===100,'concurrent stale mutations finish reconciliation without invalidating each other forever');
}
{
  const {app,node}=harness(), tower={name:'Aerial Defense Tower',description:'Tower',damage:100,cost:{twig:10},buildTime:10,unlockLevel:4,limitByTownHall:[0,0,0,0,1,2],
    shotDamage:[100,125,125,125,150],volleys:[4,4,6,8,10],ranges:[7.5,8,8.5,9,9.5],splashes:[1.8,1.85,1.9,1.95,2]};
  const trap={name:'Heavy Trap',description:'Trap',cost:{twig:10},buildTime:10,unlockLevel:4,limitByTownHall:[0,0,0,0,4,6],range:.7,splash:3,damage:4000};
  app.state.catalog.buildings.outpost.siteOnly = true;app.state.catalog.buildings.outpostdefender.siteOnly = true;
  app.state.catalog.buildings.aerial = tower;app.state.catalog.buildings.heavytrap = trap;
  check(app.buildUnavailableReason('aerial',tower)==='Town Hall 4','build menu reports the first Town Hall gate');
  app.state.base.buildings[0].level=4;
  app.state.base.buildings.push({type:'aerial',level:0,hp:0});
  app.showBuild();
  const cards=node('#modal-body').innerHTML;
  check(cards.includes('data-build="aerial" disabled') && cards.includes('Town Hall limit · 1 built'),'an unfinished tower reserves its Town Hall slot in the actual build menu');
  check(cards.includes('data-build="heavytrap" >'),'a Heavy Trap can be chosen independently of the aerial limit');
  app.state.base.buildings[0].level=5;
  check(app.buildUnavailableReason('aerial',tower)==='','new Town Hall capacity is available immediately');
  const details=app.specialDefenseStats({type:'aerial',level:5,hp:50,max_hp:100},tower);
  check(details.includes('9.5 tiles') && details.includes('10</b>') && details.includes('112.5'), 'selection uses level-five range, volley size and current health before any Stronghold bonuses');
  const plate=app.specialDefenseStats({type:'heavytrap',level:1,hp:1,max_hp:1},trap);
  check(plate.includes('0.7 tiles') && plate.includes('3 tiles') && plate.includes('2000'),'trap selection distinguishes its trigger distance, blast radius and aerial damage');
}

// Actual Baiter controller and main yard transitions share the normal API, Canvas and lifecycle.
const draftStorage = new Map();
globalThis.location = {pathname:'/yard/public/index.php'};
globalThis.localStorage = {getItem:key=>draftStorage.get(key),setItem:(key,value)=>draftStorage.set(key,value)};
const drillSession = '00112233445566778899aabbccddeeff';
function drillResponse(status='active',revision=1,countdown=3) {
  return {session:{id:drillSession,status,revision},battle:{...battle(-7),status,training:true,countdown,wave:{pokey:1},direction:'tl',attacker:{id:0,name:'Wild monsters'},defender:{id:1,name:'Keeper 1'},target:{kind:'training',name:'Wild Monster Baiter'},result:status==='finished'?{outcome:'dismissed',spawned:1,defeated:0,destruction:0}:null}};
}
function drillHarness(){
  const h=harness();h.app.state.page='yard';
  h.app.state.base.baiter={available:true,level:2,capacity:150,costs:{pokey:10,ichi:20,dave:160},directions:['tl','tr','br','bl'],maxUnits:4,session:null};
  h.app.state.catalog.monsters={pokey:{name:'Pokey'},ichi:{name:'Ichi'},dave:{name:'D.A.V.E.'}};
  return h;
}
{
  draftStorage.clear();const {app,node,requests}=drillHarness();
  app.baiter.configure();
  check(app.state.modal==='baiter' && node('#modal-body').innerHTML.includes('Wild pack') && node('#modal-body').innerHTML.includes('Four more approaches'), 'Baiter opens the wave builder with level-appropriate compass');
  check(node('#baiter-launch').disabled && node('#baiter-clear').disabled, 'empty selection cannot summon or clear a wave');
  app.baiter.edit('dave',1);check(node('#baiter-launch').disabled,'species costing more than available musk cannot be queued');
  app.baiter.edit('ichi',99);check(node('#baiter-totals').textContent==='80 / 150 musk · 4 / 4 monsters','species input clamps to simultaneous monster limit');
  app.baiter.handleClick({dataset:{baiter:'clear'}});app.baiter.edit('pokey',1);
  await app.baiter.handleClick({dataset:{baiter:'direction',edge:'t'}});
  const started=app.baiter.start(),duplicate=app.baiter.start();
  check(requests.length===1&&requests[0].action==='baiterStart'&&requests[0].input.direction==='tl'&&requests[0].input.wave.pokey===1&&requests[0].input.previousId===null, 'start sends selected species and a permitted edge once');
  const key=requests[0].input.requestId;requests[0].reject(new Error('Network retry'));await started;await duplicate;
  const retry=app.baiter.start();check(requests[1].input.requestId===key,'retry of an unconfirmed wave uses its original idempotency key');
  requests[1].resolve(drillResponse());await retry;
  check(!node('#drill-countdown').hidden && node('#drill-countdown-number').textContent==='3' && node('#battle-banner-label').textContent==='DEFENSE DRILL','confirmed start installs owner drill and renders countdown');
  const tick=app.pollBattle();check(requests[2].action==='baiterPoll'&&requests[2].input.id===drillSession,'active drill polls the owner practice endpoint');
  const stop=app.baiter.siren();requests[3].resolve(drillResponse('finished',3,0));await stop;
  requests[2].resolve(drillResponse('active',2,1));await tick;
  check(app.state.battle.status==='finished' && node('#drill-countdown').hidden && node('#battle-result').innerHTML.includes('The siren cleared the yard.'),'late poll cannot reopen the drill or replay a countdown after siren');
  check(!node('#battle-result').innerHTML.includes('RANK POINTS'), 'practice result shows wave casualties and drill damage');
  app.leaveDrill();check(!app.state.battle&&node('#battle-result').hidden&&app.state.base.baiter.session.status==='finished','return home preserves the reviewable server result');
  const review=app.baiter.resume(true);requests[4].resolve(drillResponse('finished',3,0));await review;check(app.state.battle.training&&app.state.battle.status==='finished','owner can review last completed result');
}
{
  const {app,node}=drillHarness();app.baiter.configure();check(node('#baiter-totals').textContent.startsWith('10 / 150'), 'saved wave survives a browser reload on the same installation');
  const key='bym-baiter:/yard/public/:1';draftStorage.set(key,JSON.stringify({direction:'invalid',wave:{fake:4,pokey:999,ichi:5,dave:-1}}));
  app.baiter.resetAccount();app.closeModal();app.baiter.configure();check(node('#baiter-totals').textContent==='40 / 150 musk · 4 / 4 monsters','restored draft drops unknown species and clamps counts against current gates');
  app.baiter.resetAccount();app.closeModal();app.state.user={id:2};app.state.base.owner.id=2;app.baiter.configure();check(node('#baiter-launch').disabled,'another keeper gets their own draft');
  app.baiter.resetAccount();app.closeModal();globalThis.location.pathname='/other/public/index.php';app.state.user={id:1};app.state.base.owner.id=1;app.baiter.configure();check(node('#baiter-launch').disabled,'separate local installations do not share a draft');
  const storage=globalThis.localStorage;globalThis.localStorage={getItem(){throw Error('denied')},setItem(){throw Error('denied')}};
  app.baiter.resetAccount();app.closeModal();app.baiter.configure();app.baiter.edit('ichi',1);check(!node('#baiter-launch').disabled,'storage denial allows play with an in-memory draft');globalThis.localStorage=storage;globalThis.location.pathname='/yard/public/index.php';
}
{
  draftStorage.clear();const h=drillHarness();const {app,requests}=h;app.state.base.baiter.session={id:drillSession,status:'active',revision:1};
  const resuming=app.baiter.resume();requests[0].resolve(drillResponse());await resuming;
  const ticking=app.baiter.poll(),refreshing=app.realRefresh();const real={id:77,status:'active'};
  requests[2].resolve({user:app.state.user,base:app.state.base,activeBattle:real});await new Promise(r=>setImmediate(r));
  check(requests.at(-1).action==='battle'&&requests.at(-1).query.id==='77','incoming real invasion takes priority during a drill');
  requests.at(-1).resolve(battle(77));await refreshing;requests[1].resolve(drillResponse('active',2,1));await ticking;
  check(app.state.battle.id===77 && !app.state.battle.training,'late practice response cannot replace the real invasion');
}
{
  const {app,requests}=drillHarness();app.state.base.baiter.session={id:drillSession,status:'active'};
  for(const key of ['visiting','worldSite','activeBattle']){app.state[key]={id:9};await app.baiter.resume();check(!requests.length,`drill entry blocked during ${key}`);app.state[key]=null;}
  const resume=app.baiter.resume();app.resetAccount();requests[0].resolve(drillResponse());await resume;
  check(!app.state.battle && !app.state.user,'a pending resume cannot install practice after logout');
}

{
 const {app}=drillHarness();const hall=app.state.base.buildings[0];hall.level=5;hall.ready_at=0;
 app.state.base.buildings.push({id:9,type:'locker',level:2,hp:10,ready_at:0});
 check(app.buildingUpgradeGate({townHall:4,locker:3}).includes('Locker at level 3'),'Baiter upgrade observes the exact living Locker gate for the next tier');
 app.state.base.buildings[1].level=4;check(!app.buildingUpgradeGate({townHall:5,locker:4}), 'late Baiter levels accept the accessible level-five Hall and level-four Locker');
 hall.hp=0;check(app.buildingUpgradeGate({townHall:4,locker:1}).includes('Town Hall'),'ruined Hall disables advanced Baiter upgrades');
}
{
  const {app,requests,node}=harness();
  const scoutButton={dataset:{tribeScout:'1'},hasAttribute:()=>false};
  const first=app.tribes.handleClick(scoutButton);
  await app.setPage('rankings');
  const camp={...base(0),tribe:{stage:1,name:'Legionnaire border camp'},previousId:null};
  requests[0].resolve(camp);await first;
  check(app.state.page==='rankings' && !app.state.visiting,'an old campaign scout cannot leave Rankings');
  const second=app.tribes.handleClick(scoutButton);
  requests[1].resolve(camp);await second;
  check(app.state.page==='yard' && app.state.visiting?.tribe.stage===1,'a fresh scout click opens the chosen tribe camp');
  check(node('#yard-eyebrow').textContent==='WILD MONSTER TRIBES' && node('#yard-title').innerHTML.includes(camp.tribe.name),'campaign scouting headings identify the actual camp');
  const resume=app.openBattle(89);
  requests[2].resolve({...battle(89),target:{kind:'tribe',stage:1,name:camp.tribe.name}});
  await resume;
  check(app.state.battle.id===89 && node('#battle-banner-label').textContent==='WILD EXPEDITION','resumed expedition uses the ordinary battlefield and a campaign heading');
}
{
  const {app,requests,node}=harness();
  const opened={opened:true,revision:1,base:{...base(1),realm:'inferno',infernoHome:true,resources:{bone:300,magma:650}},counterattack:{status:null}};
  const old=app.inferno.open();await app.setPage('rankings');requests[0].resolve(opened);await old;
  check(!app.state.visiting&&app.state.page==='rankings','a slow cavern load cannot replace a newer navigation choice');
  const fresh=app.inferno.open();requests[1].resolve(opened);await fresh;
  check(app.state.visiting?.infernoHome&&node('#yard-title').textContent==='My cavern','opening Inferno uses the own cavern canvas');
  const scout=app.inferno.handleClick({dataset:{infernoScout:'201'},hasAttribute:()=>false});
  const camp={...base(0),realm:'inferno',infernoCamp:{stage:201,name:'Moloch · Descent 1'},previousId:null};
  requests[2].resolve(camp);await scout;
  check(node('#yard-title').textContent===camp.infernoCamp.name&&app.state.visiting.owner.id===0,'Moloch scout keeps its source camp identity outside the keeper directory');
  const resumed=app.openBattle(91);
  requests[3].resolve({...battle(91),target:{kind:'inferno',stage:201,name:camp.infernoCamp.name}});await resumed;
  check(node('#battle-banner-label').textContent==='MOLOCH EXPEDITION'&&!app.state.battle.training,'resumed Moloch expedition stays on the ordinary real-invasion controls');
}
// Surface event combat has its own cue on both the resumable defense and normal invasion routes.
for (const flags of [{horse:true},{blitzStage:2},{brukkargStage:3},{wmiEvent:1},{wmiEvent:2},{}]) {
  const h=harness();
  h.app.presentDrill({...battle(75),training:true,...flags},true);
  check(h.music.at(-1)===(Object.keys(flags).length?'event':'defense'),'select the correct music for '+JSON.stringify(flags));
}
for (const [kind,event,expected] of [['tribe','carnage','event'],['tribe','hellraisers','event'],['tribe','brukkarg','event'],
  ['tribe',null,'expedition'],['inferno',null,'inferno'],['infernoRival',null,'inferno'],['home',null,'battle']]) {
  const h=harness();
  const resumed=h.app.openBattle(46);
  h.requests[0].resolve({...battle(46),target:{kind,event,name:'Musical field'}});
  await resumed;
  check(h.music.at(-1)===expected,'resume '+(event||kind)+' with its intended score');
}
console.log(`Navigation UI: ${checks} checks passed.`);
