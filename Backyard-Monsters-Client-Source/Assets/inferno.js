import {esc,icon,num,duration} from './ui.js?v=0.13.0';

const supplies=wallet=>Object.entries(wallet||{}).filter(([,amount])=>amount>0).map(([key,amount])=>num(amount)+' '+esc(key)).join(' · ');
const token=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');
const cmd=(action,text,primary=false,disabled=false)=>`<button class="button ${primary?'primary':'subtle'} block" data-inferno-command="${action}" ${disabled?'disabled':''}>${text}</button>`;

export function createInferno({state,api,showModal,closeModal,navigationIntent,navigationCurrent,inspect,redraw,enterBattle,presentDefense,choosePlacement,cancelPlacement,renderMonsters,monsterAction,toast,buttonBusy,now}) {
  let view=null,busy=false,owner=null,loadSeq=0,defending=null;
  function reset(){view=null;owner=null;busy=false;defending=null;++loadSeq}
  function owned(){return owner===state.user?.id&&!!view?.opened}
  function currentCavern(){return owned()&&state.visiting?.infernoHome&&state.page==='yard'&&!state.battle}
  function save(data){owner=state.user.id;view=data}
  function gate(data){
    return `<div class="inferno-hero"><span class="eyebrow">THE UNDERGROUND</span><h2 id="modal-title">Inferno</h2></div>
      <p>Open a passage beneath your backyard. Your first Under Hall, four harvesters, a Flinger and eight Spurtz await below.</p>
      <p><b>Entrance requirements</b><br>${data.emergencePass?"The mature Skull of Doom grants free passage.":"Operational level 2 Town Hall · "+supplies(data.entryCost)}</p>
      ${cmd('enter',data.emergencePass?'Descend through the Skull of Doom':'Open the entrance',true,!data.canEnter)}
      <button class="button subtle block" data-baiter="emergence">Inferno Emergence</button>`;
  }
  async function open(){
    if(!state.user||state.battle?.status==='active')return;
    const navigation=navigationIntent();
    try {
      const data=await api('inferno');if(!navigationCurrent(navigation,true))return;
      save(data);
      if(!data.opened) {showModal(gate(data),'inferno-gate');return}
      closeModal();await inspect(data.base);if(data.counterattack?.status==='active')await defend('poll');
    } catch(e){if(navigationCurrent(navigation,true))toast(e.message,true)}
  }
  function route(){
    if(!owned())return;
    const groups=new Map();
    for(const c of view.camps){if(!groups.has(c.route))groups.set(c.route,[]);groups.get(c.route).push(c)}
    const next=view.camps.find(c=>c.open&&c.best==null)?.stage;
    showModal(`<div class="inferno-hero"><span class="eyebrow">MOLOCH EXPEDITIONS</span><h2 id="modal-title">The descent</h2><p>${view.cleared} of ${view.camps.length} camps cleared</p></div>
      <p>Scout a cavern, then launch your underground army. A first victory earns local supplies and opens the next camp.</p>
      <div class="tribe-route">${[...groups].map(([name,rows])=>`<details ${rows.some(c=>c.stage===next)?'open':''}><summary>${esc(name)}<small>${rows.filter(c=>c.best!=null).length} / ${rows.length}</small></summary>${rows.map(c=>`<article class="tribe-card"><div><strong>${esc(c.name)}</strong><small>${c.best!=null?'Cleared · '+num(c.best)+'%':c.open?'Open':'Locked'}</small></div><button class="button subtle small" data-inferno-scout="${c.stage}" ${c.open?'':'disabled'}>${icon(c.open?'eye':'lock')}Scout</button></article>`).join('')}</details>`).join('')}</div>`, 'inferno-route');
  }
  function works(){return view.base.buildings.some(b=>b.ready_at>now())}
  function hall(){return view.base.buildings.find(b=>b.type==='townhall'&&b.hp>0&&b.ready_at<=now())?.level||0}
  const afford=cost=>Object.entries(cost).every(([k,n])=>view.base.resources[k]>=n);
  function build(){
    if(!currentCavern())return;
    const cards=Object.entries(view.catalog).filter(([,s])=>s.limit>0).map(([key,s])=>{
      const count=view.base.buildings.filter(b=>b.type===key).length;
      const reason=count>=s.limit?'Limit reached':hall()<s.gate?'Under Hall '+s.gate:works()?'Worker busy':!afford(s.cost)?'More supplies needed':'Choose location';
      return `<article class="inferno-structure"><img src="assets/art/${esc(s.art)}.${s.art.startsWith('inferno-')?'webp':'png'}" alt=""><div><b>${esc(s.name)}</b><small>${supplies(s.cost)} · ${duration(Math.ceil(s.time/view.speed))}</small></div><button class="button subtle small" data-inferno-build="${esc(key)}" ${reason==='Choose location'?'':'disabled'}>${reason}</button></article>`
    }).join('');
    showModal(`<h2 id="modal-title">Cavern construction</h2><p>Choose a structure, then click a free spot on the cavern floor.</p><div class="inferno-structures">${cards}</div>`,'inferno-build');
  }
  function brood(){
    if(currentCavern())showModal('<h2 id="modal-title">Inferno monsters</h2>'+renderMonsters(view.monsters),'inferno-monsters');
  }
  async function change(input,el){
    if(busy||!state.user||state.battle?.status==='active')return false;
    busy=true;++loadSeq;const n=navigationIntent(),oldModal=state.modal;
    buttonBusy(el,true);
    try {
      const data=await api('infernoAct',{requestId:token(),realmRevision:view?.revision??0,...input});
      if(!navigationCurrent(n,true))return false;
      save(data);
      if(input.action==='enter'){closeModal();await inspect(data.base);return true}
      if(state.visiting?.infernoHome){redraw(data.base);if(oldModal==='inferno-monsters'){const sheet=document.querySelector('.modal-card'),scroll=sheet?.scrollTop||0;brood();if(sheet)sheet.scrollTop=scroll;}}
      if(input.action==='collect')toast('Cavern stores collected.');
      return true;
    } catch(e){if(navigationCurrent(n,true)){toast(e.message,true);if(currentCavern())await refresh(true)}return false}
    finally{buttonBusy(el,false);busy=false}
  }
  async function refresh(force=false){
    if(!currentCavern()||(busy&&!force))return;
    const n={epoch:state.navigationEpoch,owner:state.user.id,battleEpoch:state.battleEpoch,modalEpoch:state.modalEpoch},seq=++loadSeq;
    try{
      const data=await api('inferno');
      if(seq!==loadSeq||!navigationCurrent(n,true)||!currentCavern())return;
      save(data);redraw(data.base);
      if(state.modal==='inferno-monsters'){
        // Keep quantity inputs and keyboard focus intact while typing.
        const dialog=document.querySelector('#modal-body');
        if(dialog&&!dialog.contains(document.activeElement)){const scroll=dialog.closest('.modal-card')?.scrollTop||0;brood();if(scroll)dialog.closest('.modal-card').scrollTop=scroll;}
      }
    }catch(e){if(force&&navigationCurrent(n,true))toast(e.message,true)}
  }
  function panel(base,selected){
    const camp=base.infernoCamp,spec=selected&&(view?.catalog?.[selected.type]||state.catalog.buildings[selected.type]);
    const working=selected?.ready_at>now();
    let details=selected?`<span class="eyebrow">${camp?'MOLOCH STRUCTURE':'YOUR CAVERN'} · LEVEL ${selected.level}</span><h2>${esc(spec?.name||selected.type)}</h2>
      <div class="stat-line"><span>Health</span><b>${num(selected.hp)} / ${num(selected.max_hp)}</b></div>
      ${spec?.produces?`<p>${num(selected.stored)} ${esc(spec.produces)} ready to harvest</p>`:''}`:'';
    if(selected?.type==='housing'&&spec?.interceptionRanges&&!camp){const radius=spec.interceptionRanges[Math.max(1,selected.level)];details+=`<p>Housed monsters intercept nearby enemies within ${Number(radius).toLocaleString(undefined,{maximumFractionDigits:2})} tiles. Balthazar and Sabnox can intercept fliers.</p>`;}
    if(base.infernoRival){
      const r=base.infernoRival;
      return details+`<span class="eyebrow">${base.owner.is_bot?'BOT':'PLAYER'} CAVERN</span><h2>${esc(base.owner.name)}</h2><p>${num(base.owner.rank_points)} rank points · ${num(base.buildings.length)} structures</p>
        ${!r.canAttack?`<p>${r.truceUntil>now()?'Your truce is active.':r.shieldUntil>now()?'Protected for '+duration(r.shieldUntil-now())+'.':'This cavern is unavailable for invasion.'}</p>`:''}
        ${cmd('peer-plan','Plan a ranked invasion',true,!r.canAttack)}${cmd('rivals','Underground rivals')}${cmd('return','My cavern')}`;
    }
    if(camp)return details+`<span class="eyebrow">MOLOCH ENCAMPMENT</span><h2>${esc(camp.name)}</h2><p>Destroy the Under Hall or half of the intact target buildings.</p><p>${camp.best==null?'First victory · '+supplies(camp.reward):'Best destruction · '+num(camp.best)+'%'}</p>
      ${cmd('plan','Invade this cavern',true)}${cmd('route','Expedition map')}${cmd('return','My cavern')}`;
    if(!owned())return '';
    if(selected){
      const next=selected.level+1,tier=spec.tiers[next],cost=tier?.cost||{};
      const upgrade=!!tier&&hall()>=tier.gate;
      if(working)details+=`<p>Worker finishes in <b data-countdown="${selected.ready_at}">${duration(selected.ready_at-now())}</b></p>`;
      else{
        if(next<=spec.maxLevel)details+=`<p>${upgrade?supplies(cost):'Upgrade the Under Hall to advance this structure.'}</p>${cmd('upgrade','Upgrade to level '+next,true,!upgrade||works()||!afford(cost))}`;
        details+=cmd('move','Move this structure');
      }
    }else details='<span class="eyebrow">YOUR UNDERGROUND SETTLEMENT</span><h2>My cavern</h2>';
    const damaged=base.buildings.some(b=>b.level>0&&b.hp<b.max_hp);
    if(damaged)details+=cmd('repair','Repair cavern · Free',true);
    const amount=base.buildings.reduce((total,b)=>total+Math.floor(b.stored||0),0),reserved=supplies(view.pending);
    return details+`<div class="panel-stats"><div><span>Storage per resource</span><b>${num(view.capacity)}</b></div><div><span>Harvest ready</span><b>${num(amount)}</b></div></div>
      ${reserved?'<p>Expedition supplies awaiting room · '+reserved+'</p>':''}
      ${cmd('collect','Collect cavern supplies',false,!amount&&!reserved)}${cmd('build','Construct a building')}${cmd('brood','Manage Inferno monsters')}${cmd('route','Moloch expeditions',true)}${cmd('rivals','Underground rivals')}${cmd('counter','Defend my cavern')}<button class="button subtle block" data-ascension-open>Ascend monsters to my backyard</button><button class="button subtle block" data-quest-open="Cavern buildings">Cavern quests</button>`;
  }
  async function scout(stage){
    const n=navigationIntent();
    try{const camp=await api('infernoVisit',undefined,{stage});if(navigationCurrent(n,true)){closeModal();await inspect(camp)}}catch(e){if(navigationCurrent(n,true))toast(e.message,true)}
  }
  function plan(){
    const c=state.visiting?.infernoCamp;if(!c||!owned())return;
    const army=Object.values(view.base.army).some(n=>n>0),flinger=view.base.buildings.some(b=>b.type==='flinger'&&b.level>0&&b.hp>0&&b.ready_at<=now());
    showModal(`<h2 id="modal-title">Invade ${esc(c.name)}?</h2><p>Your launched Inferno monsters leave your underground army. Undeployed monsters stay in the Compound.</p>
      <p>${c.best==null?'First-clear reward · '+supplies(c.reward):'Replay this camp to improve your destruction record.'}</p>
      ${!army||!flinger?'<p>Hatch native monsters and complete the cavern Flinger to invade.</p>':''}
      <button class="button primary" data-inferno-launch="${c.stage}" data-request="${token()}" ${army&&flinger?'':'disabled'}>Begin invasion</button>`,'inferno-confirm');
  }
  async function launch(button){
    const c=state.visiting?.infernoCamp,n=navigationIntent();
    if(!c||c.stage!==Number(button.dataset.infernoLaunch)||state.modal!=='inferno-confirm')return;
    buttonBusy(button,true);
    try{const b=await api('infernoAttack',{stage:c.stage,requestId:button.dataset.request,previousId:state.visiting.previousId});
      if(navigationCurrent(n,true)){closeModal();await enterBattle(b)}
    }catch(e){if(navigationCurrent(n,true))toast(e.message,true)}finally{buttonBusy(button,false)}
  }
  function result(b){
    const r=b.result;
    return `<span class="result-icon">${icon(r.attackerWon?'trophy':'shield')}</span><span class="eyebrow">MOLOCH EXPEDITION</span><h2>${esc(b.target.name)}</h2>
      <p>${r.inferno.firstClear?'Cavern cleared. The route ahead is open.':r.attackerWon?'Cavern cleared again.':'Your underground army withdrew.'}</p>
      <div class="result-stats"><div><b>${num(r.destruction)}%</b><span>DESTRUCTION</span></div></div>${supplies(r.loot)?'<p>Delivered · '+supplies(r.loot)+'</p>':''}
      ${supplies(r.inferno.pending)?'<p>Awaiting storage · '+supplies(r.inferno.pending)+'</p>':''}${cmd('return','Return to my cavern',true)}`;
  }
  async function rivals(page=1,filter='all',search=''){
    if(!state.user||state.battle?.status==='active')return;
    const n=navigationIntent();
    try{
      if(!owned()){const data=await api('inferno');if(!navigationCurrent(n,true))return;save(data);if(!data.opened){showModal(gate(data),'inferno-gate');return}}
      const data=await api('infernoRivals',undefined,{page,filter,search});if(!navigationCurrent(n,true))return;
      const rows=data.items.map(c=>`<article class="tribe-card"><div><strong>${esc(c.name)}</strong><small>${c.is_bot?'Bot':'Player'} · ${num(c.rank_points)} rank points · Under Hall ${num(c.hall)} · ${num(c.buildings)} structures</small></div><button class="button subtle small" data-inferno-peer="${c.id}">${icon('eye')}Visit</button></article>`).join('');
      showModal(`<header class="inferno-hero"><span class="eyebrow">RANKED INVASIONS</span><h2 id="modal-title">Underground rivals</h2><p>${num(data.total)} rival caverns</p></header>
        <div class="list-toolbar"><div class="tabs">${['all','players','bots'].map(f=>`<button class="${f===filter?'active':''}" data-inferno-filter="${f}">${f==='all'?'All':f==='bots'?'Bots':'Players'}</button>`).join('')}</div></div>
        <div class="inferno-search"><label class="search"><input id="inferno-search" maxlength="48" aria-label="Search underground keepers" placeholder="Keeper name" value="${esc(search)}"></label><button class="button subtle small" data-inferno-search="${filter}">Search</button></div>
        ${rows||'<p>No other keepers match this search. A keeper appears here after opening an Inferno entrance.</p>'}
        <div class="pagination"><button class="button subtle" data-inferno-page="${data.page-1}" data-filter="${filter}" ${data.page<=1?'disabled':''}>Previous</button><span>${data.page} / ${data.pages}</span><button class="button subtle" data-inferno-page="${data.page+1}" data-filter="${filter}" ${data.page>=data.pages?'disabled':''}>Next</button></div>`,'inferno-rivals');
    }catch(e){if(navigationCurrent(n,true))toast(e.message,true)}
  }
  async function visitPeer(keeper){
    if(!owned()||state.battle?.status==='active')return;
    const n=navigationIntent();
    try{const b=await api('infernoRivalVisit',undefined,{keeper});if(navigationCurrent(n,true)){closeModal();await inspect(b)}}
    catch(e){if(navigationCurrent(n,true))toast(e.message,true)}
  }
  function rivalPlan(){
    const b=state.visiting,rival=b?.infernoRival;if(!rival?.canAttack||!owned())return;
    const troops=Object.values(view.base.army).some(v=>v>0),flinger=view.base.buildings.some(v=>v.type==='flinger'&&v.level>0&&v.hp>0&&v.ready_at<=now());
    showModal(`<h2 id="modal-title">Invade ${esc(b.owner.name)}’s cavern?</h2><p>A victory earns 25 rank points. A loss costs 15. Launched native monsters leave your Compound, and destroyed buildings can yield a share of the defender’s underground stockpile.</p>
      ${!troops||!flinger?'<p>Complete a cavern Flinger and hatch native monsters before invading.</p>':''}<button class="button primary" data-inferno-peer-launch="${rival.id}" data-request="${token()}" ${troops&&flinger?'':'disabled'}>Launch cavern invasion</button>`,'inferno-rival-confirm');
  }
  async function rivalLaunch(el){
    const rival=state.visiting?.infernoRival,n=navigationIntent();
    if(!rival||rival.id!==Number(el.dataset.infernoPeerLaunch)||state.modal!=='inferno-rival-confirm')return;
    buttonBusy(el,true);
    try {
      const b=await api('infernoRivalAttack',{keeper:rival.id,requestId:el.dataset.request,previousId:rival.previousId});
      if(navigationCurrent(n,true)){closeModal();await enterBattle(b)}
    }catch(e){if(navigationCurrent(n,true))toast(e.message,true)}finally{buttonBusy(el,false)}
  }
  function compoundLosses(result){const rows=Object.entries(result?.compoundLosses||{}).filter(([,n])=>n>0);return rows.length?`<p>Defending monsters lost · ${rows.map(([type,n])=>num(n)+' '+esc(state.catalog.monsters?.[type]?.name||type)).join(', ')}</p>`:'';}
  function rivalResult(b){
    const r=b.result,role=b.attacker.id===state.user.id?'attacker':'defender',won=r.winnerId===state.user.id,delta=r.rankDelta?.[role]??(won?25:-15);
    return `<span class="result-icon">${icon(won?'trophy':'shield')}</span><span class="eyebrow">RANKED CAVERN INVASION</span><h2>${won?'Cavern victory.':'Cavern defeat.'}</h2><p>${esc(b.attacker.name)} invaded ${esc(b.defender.name)}’s underground settlement.</p>
      <div class="result-stats"><div><b>${delta>0?'+':''}${delta}</b><span>RANK POINTS</span></div><div><b>${num(r.destruction)}%</b><span>DESTRUCTION</span></div></div>
      <p>${supplies(r.loot)?(role==='attacker'?'Loot gained · ':'Loot lost · ')+supplies(r.loot):'No underground resources changed hands.'}</p>${compoundLosses(r)}${cmd('return','Return to my cavern',true)}`;
  }
  function counter(){
    if(!currentCavern())return;
    const c=view.counterattack;
    if(c.status==='active'){return defend('poll')}
    showModal(`<h2 id="modal-title">Moloch counterattack</h2><p>Invite wave ${num(c.wave)} to attack this cavern. Your defenses fight automatically. Your Compound releases housed monsters. Casualties stay lost, and you can repair damaged buildings for free.</p>
      <p>${num(c.cleared)} of 8 waves repelled</p><button class="button primary" data-inferno-counter="${token()}" ${works()?'disabled':''}>${works()?'Wait for construction':'Call the counterattack'}</button>`,'inferno-counter');
  }
  async function defend(action,request=null){
    const id=state.battle?.infernoDefense?defending?.id:view?.counterattack?.id;
    if(busy||!state.user||(!id&&action!=='start'))return;
    const epoch=state.navigationEpoch,keeper=state.user.id;
    busy=true;
    try{
      const input=action==='start'?{action,requestId:request,previousId:view.counterattack.id}:{action,id};
      const reply=await api('infernoDefense',input);
      if(state.user?.id!==keeper||state.navigationEpoch!==epoch)return;
      const entering=!state.battle?.infernoDefense||state.battle.id!==reply.battle.id;
      defending=reply.counterattack;if(view)view.counterattack=defending;
      if(entering&&state.modal)closeModal();
      presentDefense(reply.battle,entering);
    }catch(e){if(state.user?.id===keeper&&state.navigationEpoch===epoch)toast(e.message,true)}finally{busy=false}
  }
  function defensePanel(b){
    return `<span class="eyebrow">MOLOCH COUNTERATTACK</span><h2>Hold the cavern</h2><p>Your towers and housed monsters defend the Under Hall. Damage and monster losses carry back to your settlement.</p>
      <div class="panel-stats"><div><span>Wave</span><b>${num(view?.counterattack?.wave||1)}</b></div><div><span>Approaching force</span><b>${num(Object.values(b.wave||{}).reduce((a,v)=>a+v,0))} monsters</b></div></div>${b.status==='active'?cmd('dismiss','End counterattack'):''}`;
  }
  function defenseResult(b){
    const r=b.result,held=r.outcome==='held';
    return `<span class="result-icon">${icon(held?'trophy':'shield')}</span><span class="eyebrow">CAVERN DEFENSE</span><h2>${held?'Your defenses held.':r.outcome==='dismissed'?'Counterattack ended.':'The cavern was overrun.'}</h2>
      <p>${num(r.defeated)} invaders defeated · ${num(r.destruction)}% of target buildings destroyed.</p>${compoundLosses(r)}${cmd('return','Return to my cavern',true)}`;
  }
  async function place(pos,p){
    if(!currentCavern())return;
    if(await change({action:p.mode,type:p.type,id:p.id,...pos})){cancelPlacement();toast(p.mode==='move'?'Structure moved.':'Cavern construction started.')}
  }
  async function handleClick(el){
    if(el.hasAttribute('data-inferno-open')){await open();return true}
    if(el.dataset.infernoCounter){await defend('start',el.dataset.infernoCounter);return true}
    const search=()=>document.querySelector('#inferno-search')?.value||'';
    if(el.dataset.infernoPage){await rivals(Number(el.dataset.infernoPage),el.dataset.filter,search());return true}
    if(el.dataset.infernoFilter){await rivals(1,el.dataset.infernoFilter,search());return true}
    if(el.dataset.infernoSearch){await rivals(1,el.dataset.infernoSearch,search());return true}
    if(el.dataset.infernoPeer){await visitPeer(Number(el.dataset.infernoPeer));return true}
    if(el.dataset.infernoPeerLaunch){await rivalLaunch(el);return true}
    if(el.dataset.infernoScout){await scout(Number(el.dataset.infernoScout));return true}
    if(el.dataset.infernoLaunch){await launch(el);return true}
    if(el.dataset.infernoBuild&&currentCavern()){closeModal();choosePlacement({realm:'inferno',mode:'build',type:el.dataset.infernoBuild});return true}
    if(state.modal==='inferno-monsters'&&currentCavern()){const input=monsterAction(el);if(input){await change(input,el);return true}}
    const action=el.dataset.infernoCommand;
    if(!action)return false;
    if(action==='return')await open();
    else if(action==='enter')await change({action},el);
    else if(action==='rivals')await rivals();
    else if(action==='peer-plan')rivalPlan();
    else if(action==='route')route();
    else if(action==='plan')plan();
    else if(action==='dismiss'&&state.battle?.infernoDefense)await defend('end');
    else if(currentCavern()){
      const b=state.selected;
      if(action==='brood')brood();
      else if(action==='build')build();
      else if(action==='counter')await counter();
      else if(action==='collect'||action==='repair')await change({action},el);
      else if(action==='upgrade'&&b)await change({action,id:b.id},el);
      else if(action==='move'&&b)choosePlacement({realm:'inferno',mode:'move',type:b.type,id:b.id});
    }
    return true;
  }
  return {open,refresh,panel,result,rivalResult,defend,defensePanel,defenseResult,place,handleClick,brood,reset,snapshot:()=>owned()?view:null};
}
