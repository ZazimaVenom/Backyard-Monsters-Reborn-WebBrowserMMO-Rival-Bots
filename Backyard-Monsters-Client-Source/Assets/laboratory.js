import {icon,esc,num,short,duration} from './ui.js?v=0.13.1';

export function labGate(state, type, now) {
  const spec=state.catalog.labAbilities?.[type], lab=state.lab||state.base?.lab||{levels:{},queue:null};
  if(!spec)return {reason:'Ability unavailable',current:0,next:null};
  const current=Number(lab.levels?.[type]||0), next=spec.levels?.[current+1];
  const facility=Math.max(0,...(state.base?.buildings||[]).filter(b=>b.type==='lab'&&b.hp>0&&b.level>0&&b.ready_at<=now).map(b=>b.level));
  const monster=state.catalog.monsters[type], discovered=!monster?.lockerLevel||(state.unlocked||state.base?.unlocked||[]).includes(type);
  const reason=state.battle?.status==='active'?'Finish the battle':!next?'Fully studied':!discovered?'Discover this monster first':facility<(next.requiredLabLevel||current+1)?`Monster Lab level ${next.requiredLabLevel||current+1}`:(state.research?.[type]||1)<next.requiredMonsterLevel?`Academy monster level ${next.requiredMonsterLevel}`:lab.queue?'Another study is in progress':(state.base?.resources?.putty||0)<(next.cost?.putty||0)?'Collect more putty':'';
  return {reason,current,next,facility,discovered};
}

export function catapultGate(state, family, tier) {
  const spec=state.catalog.catapultBombs?.[family], ammunition=spec?.levels?.[tier], launcher=state.battle?.catapult;
  if(!spec||!ammunition)return {reason:'Ammunition unavailable'};
  const live=state.battle?.status==='active'&&state.battle.attacker?.id===state.user?.id;
  const reason=!live?'Choose during your invasion':!launcher?'No Catapult in this invasion':launcher.used?.[family]?'Already launched this invasion':launcher.level<spec.requiredLevel?`Catapult level ${spec.requiredLevel}`:(launcher.resources?.[spec.resource]||0)<ammunition.cost?'Not enough '+spec.resource:state.battle.duration-state.battle.elapsed<1?'The invasion is ending':'';
  return {reason,spec,ammunition};
}

/** All unlocks, costs, tiers and results come from the server catalog and saved yard. */
export function createLaboratory({state,art,costHTML,showModal,action,toast,now,hall,audio,selectCatapult}) {
  let lastProgress='',lastCatapult='';
  const lab=()=>state.lab||state.base?.lab||{levels:{},queue:null};
  const nav=()=>`<nav class="workshop-nav" aria-label="Monster workshop"><button class="button subtle small" data-command="hatchery">${icon('egg')}Hatchery</button><button class="button subtle small" data-command="research">${icon('book')}Academy</button><button class="button primary small" aria-current="page">${icon('flask')}Monster Lab</button></nav>`;
  function show() {
    if(state.battle?.status==='active'){toast('Finish the battle before opening the Monster Lab.');return;}
    const saved=lab(), buildings=state.base?.buildings||[], facility=buildings.find(b=>b.type==='lab'), level=Math.max(0,...buildings.filter(b=>b.type==='lab'&&b.hp>0&&b.level>0&&b.ready_at<=now()).map(b=>b.level));
    const completed=Object.values(saved.levels||{}).filter(v=>v>0).length, total=Object.keys(state.catalog.labAbilities||{}).length;
    showModal(`${nav()}<header class="lab-hero"><div><span class="eyebrow">A LITTLE SCIENCE. A LOT OF TROUBLE.</span><h2 id="modal-title">New tricks.<br>Wilder monsters.</h2><p>Teach your swarm to blink, cloak, poison and ricochet. Each discovery changes the way a species fights.</p><div class="lab-summary"><span>${icon('flask')}${level?'Lab level '+level:'Lab not ready'}</span><span>${completed} / ${total} abilities discovered</span></div></div>${art('lab','Monster Lab')}</header>${!facility?`<div class="facility-status">${icon('lock')}<span>Build a Monster Lab at Town Hall 3 to begin your first study.</span><button class="button primary small" data-build="lab" ${hall()<3?'disabled':''}>${hall()<3?'Town Hall 3 required':'Build Monster Lab'}</button></div>`:!level?'<div class="facility-status">'+icon('clock')+'<span>Finish construction or repair your Monster Lab before starting a study.</span></div>':''}${queueHTML()}<div class="lab-grid">${Object.entries(state.catalog.labAbilities||{}).map(([type,spec])=>card(type,spec)).join('')}</div><p class="subtle-note">One study runs at a time. Completed abilities apply to future invasions and bunker defenders. Academy levels improve raw strength; Lab tiers unlock special behavior. Studies already paid for continue while you are away.</p>`,'lab');
    lastProgress=progressKey();
  }
  function queueHTML(){
    const q=lab().queue;if(!q)return '<div class="lab-idle">'+icon('spark')+'<div><b>Your next breakthrough starts here.</b><span>Pick a species below. Studies consume putty and take time.</span></div></div>';
    const spec=state.catalog.labAbilities?.[q.type], m=state.catalog.monsters[q.type];
    const elapsed=now()-q.started_at, total=Math.max(1,q.ready_at-q.started_at), progress=Math.min(100,Math.max(0,100*elapsed/total));
    return `<section class="lab-study" aria-label="Active Lab study">${art(q.type,m?.name||q.type)}<div><span class="eyebrow">EXPERIMENT IN PROGRESS</span><h3>${esc(spec?.name||q.type)} · Tier ${q.target_level}</h3><div class="progress-bar"><i data-study-start="${q.started_at}" data-study-end="${q.ready_at}" style="width:${progress}%"></i></div><p><span data-countdown="${q.ready_at}">${duration(q.ready_at-now())}</span> remaining · ${num(q.paid_putty||0)} putty invested</p></div><button class="button subtle small" data-lab-cancel>Cancel & recover putty</button></section>`;
  }
  function card(type,spec){
    const gate=labGate(state,type,now()),m=state.catalog.monsters[type],current=spec.levels?.[gate.current],next=gate.next;
    return `<article class="lab-card ${gate.current?'studied':''}" data-lab-card="${type}"><div class="lab-card-heading">${art(type,m?.name||type)}<div><span class="species-trait">${esc(m?.name||type)}</span><h3>${esc(spec.name)}</h3><div class="lab-tiers" aria-label="Tier ${gate.current} of 3">${[1,2,3].map(n=>`<span class="${n<=gate.current?'complete':''}">${n<=gate.current?icon('check'):n}</span>`).join('')}</div></div></div><p>${esc(spec.description)}</p>${current?`<div class="lab-current">${icon('check')}<span><b>Active · Tier ${gate.current}</b>${esc(current.effectText||'Ability learned')}</span></div>`:'<span class="lab-undiscovered">Not yet discovered</span>'}${next?`<div class="lab-next"><span class="eyebrow">${gate.current?'NEXT BREAKTHROUGH':'FIRST DISCOVERY'} · TIER ${gate.current+1}</span><p>${esc(next.effectText||spec.description)}</p><div class="lab-requirements"><span>${icon('flask')}Lab ${next.requiredLabLevel}</span><span>${icon('book')}${esc(m?.name||type)} Lv ${next.requiredMonsterLevel}</span></div>${costHTML(next.cost||{})}<button id="lab-study-${type}" class="button ${gate.reason?'subtle':'primary'} block" data-lab-research="${type}" ${gate.reason?'disabled':''}>${esc(gate.reason)||'Study · '+duration(next.researchTime/(state.catalog.speed||1))}</button></div>`:`<div class="lab-mastered">${icon('spark')}All three tiers mastered</div>`}<details class="lab-comparison" data-lab-details="${type}"><summary>Compare all tiers</summary>${Object.entries(spec.levels||{}).map(([n,tier])=>`<div><b>Tier ${n}</b><span>${esc(tier.effectText||spec.description)}</span></div>`).join('')}</details></article>`;
  }
  function catapultPanel(){
    const c=state.battle?.catapult;if(!c||state.battle?.attacker?.id!==state.user?.id)return '';
    const available=Object.entries(state.catalog.catapultBombs||{}).filter(([key,spec])=>!c.used?.[key]&&c.level>=spec.requiredLevel).length;
    return `<div class="catapult-panel"><div>${icon('catapult')}<span><b>Catapult</b><small>Level ${c.level} · ${available} unused resource ${available===1?'family':'families'}</small></span></div><button class="button subtle block" data-command="catapult" ${available?'':'disabled'}>${available?'Choose ammunition':'All families launched'}</button></div>`;
  }
  function showCatapult(){
    const c=state.battle?.catapult,homeLevel=Math.max(0,...(state.base?.buildings||[]).filter(b=>b.type==='catapult'&&b.hp>0&&b.level>0&&b.ready_at<=now()).map(b=>b.level));
    showModal(`<header class="catapult-hero"><div><span class="eyebrow">MAKE AN IMPACT</span><h2 id="modal-title">A little help from home.</h2><p>Choose ammunition, then aim anywhere in the rival yard. Each resource family can be launched once per invasion.</p><span class="pill">${c?'Catapult · Level '+c.level:homeLevel?'Home Catapult · Level '+homeLevel:'Build or repair a Catapult at Town Hall 3'}</span></div>${art('catapult','Catapult')}</header><div class="ammunition-grid">${Object.entries(state.catalog.catapultBombs||{}).map(([family,spec])=>`<section class="ammo-family ammo-${family}"><div class="ammo-heading">${icon(spec.resource)}<div><h3>${esc(spec.name)}</h3><span>${c?.used?.[family]?'ALREADY LAUNCHED':'CATAPULT LEVEL '+spec.requiredLevel}</span></div></div><p>${esc(spec.description)}</p><div class="ammo-options">${Object.entries(spec.levels||{}).map(([tier,a])=>{const gate=catapultGate(state,family,Number(tier));return `<button id="catapult-${family}-${tier}" class="ammo-option" data-catapult-family="${family}" data-tier="${tier}" ${gate.reason?'disabled':''}><span class="ammo-size"><b>Tier ${tier}</b><span>${family==='putty'?`${Math.round(a.speed*100)}% speed · ${Math.round(a.armor*100)}% armor`:`${num(a.damage)} base damage`}</span><small>${a.radius}-tile radius${a.duration?' · '+a.duration+'s':''}</small></span><span class="ammo-cost">${icon(spec.resource)}${short(a.cost)}<small>${esc(gate.reason)||'Select →'}</small></span></button>`}).join('')}</div></section>`).join('')}</div><div class="catapult-notes"><p>${icon('focus')}Twigs and pebbles damage buildings, with reduced damage to walls. Traps and Champion Cages are unaffected.</p><p>${icon('spark')}Putty boosts allied monsters in the impact area. Launch it over your deployed army. The effect lasts for the selected duration.</p></div>`,'catapult');lastCatapult=catapultKey();
  }
  function preserveRedraw(view){
    const scroll=document.querySelector('.modal-card')?.scrollTop||0,focus=document.activeElement?.id;
    const opened=[...document.querySelectorAll('[data-lab-details][open]')].map(el=>el.dataset.labDetails);
    view();
    for(const type of opened){const el=document.querySelector(`[data-lab-details="${type}"]`);if(el)el.open=true;}
    if(focus)document.getElementById(focus)?.focus({preventScroll:true});
    const modal=document.querySelector('.modal-card');if(modal)modal.scrollTop=scroll;
  }
  async function handleClick(el){
    if(el.dataset.labResearch){
      const type=el.dataset.labResearch;if(!state.catalog.labAbilities?.[type])return true;
      const data=await action('labResearch',{type},el);if(data){audio.effect('research');toast('Study started. Your Lab will keep working while you are away.');preserveRedraw(show);}return true;
    }
    if(Object.hasOwn(el.dataset,'labCancel')){
      const data=await action('labCancel',{},el);if(data){const refund=data.refund||{};toast(`Study cancelled. ${num(refund.putty)} putty recovered.${refund.overflow?' Storage could not hold '+num(refund.overflow)+' surplus putty.':''}`);preserveRedraw(show);}return true;
    }
    if(el.dataset.catapultFamily){
      const type=el.dataset.catapultFamily,level=Number(el.dataset.tier),gate=catapultGate(state,type,level);
      if(gate.reason){toast(gate.reason);return true;}
      selectCatapult({mode:'catapult',type,level,radius:gate.ammunition.radius});return true;
    }
    return false;
  }
  const progressKey=()=>JSON.stringify([lab(),state.research,state.unlocked,state.base?.resources?.putty,(state.base?.buildings||[]).filter(b=>b.type==='lab').map(b=>[b.id,b.level,b.hp,b.ready_at])]);
  function refresh(){
    if(state.modal==='lab'&&progressKey()!==lastProgress)preserveRedraw(show);
    const bar=document.querySelector('[data-study-start]');if(bar)bar.style.width=Math.min(100,Math.max(0,100*(now()-Number(bar.dataset.studyStart))/Math.max(1,Number(bar.dataset.studyEnd)-Number(bar.dataset.studyStart))))+'%';
  }
  const catapultKey=()=>JSON.stringify([state.battle?.catapult,state.battle?.status]);
  function battleRefresh(){if(state.modal==='catapult'&&catapultKey()!==lastCatapult)preserveRedraw(showCatapult);}
  return {show,showCatapult,catapultPanel,handleClick,refresh,battleRefresh};
}
