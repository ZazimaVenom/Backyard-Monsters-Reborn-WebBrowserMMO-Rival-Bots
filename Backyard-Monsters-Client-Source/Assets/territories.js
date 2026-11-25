import {icon, esc, num, duration} from './ui.js?v=0.13.1';

// Territory controls use the server's ownership, costs and attack eligibility.
export function createTerritories({state, api, art, costHTML, now, toast, showModal, closeModal, showSite, choosePlacement, cancelPlacement, updateSite = () => {}, beginBattle, refreshHome, refreshMap, audio, onNavigate = () => {}}) {
  let requestEpoch = 0;
  const cover = site => site.mr2Outpost?'mr2core':site.kind === 'fortification'?'outpostdefender':site.kind === 'stronghold' ? 'stronghold' : 'outpost';
  const owner = site => site.owner?.name || 'Wild territory';
  const isSite = () => !!state.worldSite;
  function benefitsHTML(benefits, label='Current benefits') {
    if(!benefits) return '';
    const rate=Number(benefits.resourcePerSecond||0)*(Number(state.catalog.speed)||1);
    return `<div class="territory-benefits"><span class="eyebrow">${label}</span><div>${rate?`<span><b>${rate.toLocaleString(undefined,{maximumFractionDigits:2})}</b> / sec of each resource</span>`:''}${benefits.capacity?`<span><b>+${num(benefits.capacity)}</b> storage each</span>`:''}${benefits.damageBonus?`<span><b>+${Math.round(benefits.damageBonus*100)}%</b> damage within reach</span>`:''}${benefits.range?`<span><b>${num(benefits.range)}</b> hex reach</span>`:'<span>No added income, storage or reach</span>'}</div></div>`;
  }
  const note = site => site.mr2Outpost?'A regional field yard with its own worker, factories, troops and defenses. A successful siege opens a paid takeover.':site.kind==='fortification'?'A Fortification protects adjacent backyards, resource outposts and strongholds belonging to the same keeper. It gives no income, storage or reach.':site.kind === 'stronghold'
    ? 'A stronghold boosts attacking monsters and defending towers within its reach. Overlapping strongholds add their bonuses.'
    : 'A resource outpost produces all four resources for your home and expands storage. Its reach lets you push farther into the map.';

  function networkHTML(site){
    if(site.kind==='fortification')return `<div class="network-defense-note">${icon('shield')}<div><b>A link in your defense network</b><p>${site.owned?'This Fortification protects neighboring backyards, resource outposts and strongholds that belong to you.':'Capture this cell to protect its six neighboring hexes when they belong to you.'} Fortifications themselves receive no network protection. Upgrading improves its local defenses, not its network strength.</p></div></div>`;
    const support=site.networkDefense||{},count=Math.max(0,Math.min(6,Number(support.count)||0)),percent=Math.round(Math.max(0,Math.min(.75,Number(support.reduction)||0))*100);
    return `<div class="network-defense-note">${icon('shield')}<div><b>${percent}% network protection</b><p>${count} / 6 neighboring Fortifications share this keeper. Protection reduces damage to every building and combines with individual building armor.</p></div></div>`;
  }

  async function visit(id) {
    if (state.battle?.status === 'active') return;
    onNavigate();
    const epoch = ++requestEpoch, viewer = state.user?.id, battleEpoch = state.battleEpoch;
    try {
      const data = await api('worldSite', undefined, {id});
      if (epoch !== requestEpoch || viewer !== state.user?.id || battleEpoch !== state.battleEpoch || state.battle?.status === 'active') return;
      state.worldSite = data;
      await showSite(data.base);
    } catch (e) { if (epoch === requestEpoch && viewer === state.user?.id) toast(e.message, true); }
  }

  function panel() {
    const data = state.worldSite;
    if (!data) return '';
    const {site} = data, selected = state.selected, building = selected && state.catalog.buildings[selected.type];
    const shield = site.shield_until > now(), working = site.ready_at > now();
    const health = selected && (selected.untargetable === true || building?.untargetable === true)
      ? '<p class="subtle-note">Capture the surrounding defenses. This landmark cannot be targeted.</p>'
      : selected ? `<div class="health-label"><span>Building health</span><span>${num(selected.hp)} / ${num(selected.max_hp)}</span></div><div class="progress-bar"><i style="width:${Math.max(0,Math.min(100,100*selected.hp/selected.max_hp))}%"></i></div>` : '';
    const body = selected && building
      ? `<span class="eyebrow">TERRITORY DEFENSE · LEVEL ${selected.level}</span><h2>${esc(building.name)}</h2><p>${esc(building.description)}</p>${health}`
      : `<span class="eyebrow">${site.owned?'YOUR TERRITORY':site.owner?'RIVAL TERRITORY':'UNCLAIMED TERRITORY'}</span><h2>${esc(site.name)}</h2><p>${note(site)}</p>`;
    return `<div class="panel-cover">${art(selected?.type || cover(site),'')}${selected?`<button class="panel-close icon-button" data-command="deselect" aria-label="Deselect building">${icon('close')}</button>`:''}</div><div class="panel-content">${body}<div class="panel-stats"><div><span>Keeper</span><b>${esc(owner(site))}</b></div><div><span>Map position</span><b>${site.q}, ${site.r}</b></div><div><span>Territory level</span><b>${site.level}</b></div><div><span>In your reach</span><b>${site.reachable?'Yes':'No'}</b></div></div>${shield?`<p class="shield-label">Protected for <span data-countdown="${site.shield_until}">${duration(site.shield_until-now())}</span></p>`:''}${networkHTML(site)}${working?`<p class="subtle-note">Work finishes in <span data-countdown="${site.ready_at}">${duration(site.ready_at-now())}</span>.</p>`:''}${data.outpostYard?fieldControls(data):''}${site.owned&&data.outpostYard?'':site.owned?`<button class="button primary block" data-world-manage="${site.id}">${icon('settings')}Manage territory</button>`:`<button class="button primary block" data-world-plan="${site.id}" ${data.canAttack?'':'disabled'}>${icon('swords')}Plan conquest</button>${!data.canAttack?`<p class="subtle-note">${esc(data.attackReason||'This territory is not available to attack.')}</p>`:''}`}<button class="button subtle block" data-page="world">${icon('world')}Back to the map</button><div class="hint-card">${icon('flag')}<p><b>Capture at 90% damage</b><br>Damage is weighted by each eligible building’s full health. ${data.outpostYard?'The regional headquarters counts. Walls and traps are excluded.':'Walls, traps, and the resource core do not count.'}</p></div></div>`;
  }

  function fieldControls(data) {
    const f=data.outpostYard, b=state.selected, id=data.site.id;
    if(!f?.owned){
      const offer=f?.takeover;
      if(!offer)return '';
      return `<div class="territory-garrison"><h3>Take over this outpost</h3><p>Pay from your home treasury to establish the yard with 12 hours of protection.</p><button class="button primary block" data-field-pay="materials" ${offer.eligible&&!f.busy?'':'disabled'}>Pay ${num(offer.price.each)} of each resource</button><button class="button subtle block" data-field-pay="shiny" ${offer.eligible&&!f.busy?'':'disabled'}>Pay ${num(offer.price.shiny)} Shiny</button>${offer.eligible?'':'<p>The winning keeper has first claim for five minutes. A working Map Room 2 and territorial reach are required.</p>'}</div>`;
    }
    const armor=b&&f.armor?.[b.id], up=b&&f.upgrades[b.id], move=b&&b.type!=='mr2core'&&b.ready_at<=now();
    return `${b&&b.ready_at>now()?`<p>Construction finishes in <b data-countdown="${b.ready_at}">${duration(b.ready_at-now())}</b>.</p>`:''}${move?`<button class="button subtle block" data-field-move="${b.id}">Move building</button>`:''}${up?`${costHTML(up.cost)}<button class="button subtle block" data-field-op="upgrade" data-building="${b.id}" ${f.workersBusy?'disabled':''}>Upgrade · ${duration(up.seconds)}</button>`:''}${armor?`<p>Armor tier ${armor.tier} · ${duration(armor.seconds)}</p>${costHTML(armor.cost)}<button class="button subtle block" data-field-op="fortify" data-building="${b.id}" ${f.workersBusy||armor.reason?'disabled':''}>Fortify building</button>${armor.reason?`<p>${esc(armor.reason)}</p>`:''}`:''}<button class="button primary block" data-world-manage="${id}">${icon('settings')}Outpost workshop</button><button class="button subtle block" data-field-op="repair">${icon('repair')}Repair outpost for free</button>`;
  }

  function fieldWorkshop(data){
    const f=data.outpostYard, buildings=state.catalog.buildings, monsters=state.catalog.monsters;
    const stock=t=>`${num(f.homeArmy[t]||0)} home · ${num(f.army[t]||0)} field · research ${f.research[t]||1}`;
    const facilities=Object.entries(f.facilities).map(([t,x])=>`<article><h3>${esc(buildings[t].name)}</h3><p>${x.count} / ${x.limit} built · ${duration(x.seconds)}</p>${costHTML(x.cost)}<button class="button subtle small" data-field-build="${t}" ${f.workersBusy||x.reason||x.count>=x.limit?'disabled':''}>Place building</button>${x.reason?`<p>${esc(x.reason)}</p>`:''}</article>`).join('');
    const bunkers=data.base.buildings.filter(b=>b.type==='bunker'&&b.level>0&&b.hp>0);
    const species=[...new Set([...f.unlocked,...Object.keys(f.homeArmy),...Object.keys(f.army),...Object.values(data.base.garrison||{}).flatMap(Object.keys)])].filter(t=>monsters[t]&&(monsters[t].origin||'surface')!=='summon');
    const troopBtn=(op,label)=>`<button class="button subtle small" data-field-troop="${op}">${label}</button>`;
    const kits=Object.entries(f.kits).map(([k,kit])=>`<article><h3>${esc(kit.name)}</h3><p>${kit.count} buildings</p>${costHTML(kit.cost)}<button class="button subtle small" data-field-kit="${k}" data-payment="materials">Build with resources</button><button class="button subtle small" data-field-kit="${k}" data-payment="shiny">Finish for ${num(kit.shiny)} Shiny</button></article>`).join('');
    const slots=[1,2].map(slot=>`<div><button class="button subtle small" data-field-op="saveLayout" data-slot="${slot}">Save layout ${slot}</button> <button class="button subtle small" data-field-op="loadLayout" data-slot="${slot}" ${f.planner.slots.some(s=>s.slot===slot)?'':'disabled'}>Apply layout ${slot}</button></div>`).join('');
    return `<div class="territory-scout"><div><span class="eyebrow">REGIONAL OUTPOST · ${data.site.q}, ${data.site.r}</span><h2 id="modal-title">${esc(data.site.name)}</h2><p>Work is paid from your home treasury. Select a building in the yard to move or upgrade it.</p></div>${art('mr2core','')}</div><div class="territory-actions"><button class="button primary" data-field-op="collect">Collect production</button><button class="button subtle" data-field-op="repair">Repair outpost for free</button></div><details open><summary>Construction · ${f.workersBusy?'worker occupied':'worker ready'}</summary><div class="territory-investments">${facilities}</div></details><details><summary>Hatchery and garrison · ${num(f.used)} / ${num(f.capacity)} housing</summary><p>Field troops use species and research from your home yard. Bunker troops defend this outpost automatically.</p><label>Species<select id="field-species">${species.map(t=>`<option value="${t}">${esc(monsters[t].name)} · ${stock(t)}</option>`).join('')}</select></label><label>Quantity<input id="field-quantity" type="number" min="1" max="200" value="1"></label><div class="territory-actions">${troopBtn('train','Hatch here')}${troopBtn('send','Send from home')}${troopBtn('return','Return to home')}${troopBtn('juice','Juice into Goo')}</div>${f.queue.map(q=>`<p>${q.quantity} × ${esc(monsters[q.type].name)} · <span data-countdown="${q.ready_at}">${duration(q.ready_at-now())}</span></p>`).join('')}${f.rush? `<button class="button subtle small" data-field-op="rush" data-price="${f.rush.price}">Finish hatching · ${f.rush.price} Shiny</button>`:''}${bunkers.length?`<label>Bunker<select id="field-bunker">${bunkers.map(b=>`<option value="${b.id}">Bunker ${b.id} · lvl ${b.level} · ${Object.entries(data.base.garrison[b.id]||{}).filter(([,n])=>n).map(([t,n])=>n+' '+esc(monsters[t].name)).join(', ')||'empty'}</option>`).join('')}</select></label><div class="territory-actions">${troopBtn('station','Station in bunker')}${troopBtn('recall','Recall from bunker')}</div>`:''}</details>${f.targets?.length?`<details><summary>Launch a field invasion</summary><label>Nearby target<select id="field-target">${f.targets.map(t=>`<option value="${t.id}">${esc(t.name)} · ${t.q}, ${t.r}</option>`).join('')}</select></label><button class="button primary" data-field-launch="1">Invade from outpost</button></details>`:''}<details><summary>Yard Planner</summary>${f.planner.available?slots:'<p>Build a Yard Planner here to save and restore two layouts.</p>'}</details><details><summary>Replace yard with a starter kit</summary><p>Kit construction replaces the current buildings and plans. Transfer field troops home first.</p><div class="territory-investments">${kits}</div></details><div class="territory-abandon"><button class="button danger small" data-world-abandon="${data.site.id}">Abandon outpost</button></div>`;
  }

  async function fieldAction(input,kind='outpostAct',modal=false){
    const yard=state.worldSite;
    if(!yard?.outpostYard)return false;
    const epoch=++requestEpoch,viewer=state.user?.id;
    try{
      const data=await api(kind,{siteId:yard.site.id,revision:yard.outpostYard.revision,...input});
      if(epoch!==requestEpoch||viewer!==state.user?.id)return false;
      cancelPlacement();closeModal();state.worldSite=data;await showSite(data.base);
      await refreshHome();
      if(state.worldSite?.site.id===data.site.id&&modal&&data.site.owned)showModal(fieldWorkshop(data),'mr2-manage');
      audio.effect('build');toast(kind==='outpostClaim'?'Outpost claimed.':input.op==='repair'?'Outpost repaired.':'Outpost updated.');return true;
    }catch(e){toast(e.message,true);return false;}
  }
  async function fieldClick(el){
    const d=el.dataset, yard=state.worldSite;
    if(d.fieldVisit){await visit(Number(d.fieldVisit));return true;}
    if(!yard?.outpostYard)return false;
    if(d.fieldPay){el.disabled=true;await fieldAction({payment:d.fieldPay,battle:yard.outpostYard.takeover.battle},'outpostClaim',true);if(el.isConnected)el.disabled=false;return true;}
    if(d.fieldBuild||d.fieldMove){
      const b=d.fieldMove&&yard.base.buildings.find(x=>x.id===Number(d.fieldMove));
      closeModal();await showSite(yard.base);choosePlacement({realm:'mr2',mode:b?'move':'build',type:b?b.type:d.fieldBuild,id:b?.id,siteId:yard.site.id,revision:yard.outpostYard.revision});return true;
    }
    if(d.fieldLaunch){
      el.disabled=true;
      try{const battle=await api('outpostLaunch',{siteId:yard.site.id,targetId:Number(document.getElementById('field-target').value)});closeModal();state.worldSite=null;await beginBattle(battle);}catch(e){toast(e.message,true);}finally{if(el.isConnected)el.disabled=false;}
      return true;
    }
    if(d.fieldKit){
      const k=yard.outpostYard.kits[d.fieldKit];
      showModal(`<h2 id="modal-title">Replace the outpost with ${esc(k.name)}?</h2><p>This removes all current buildings, stored production and saved layouts. ${d.payment==='shiny'?`The ${k.count} buildings finish immediately for ${num(k.shiny)} Shiny.`:'Construction begins as soon as you pay.'}</p>${d.payment==='materials'?costHTML(k.cost):''}<div class="territory-actions"><button class="button subtle" data-world-manage="${yard.site.id}">Keep yard</button><button class="button primary" data-field-op="kit" data-kit="${d.fieldKit}" data-payment="${d.payment}">Replace yard</button></div>`,'mr2-kit');return true;
    }
    if(!d.fieldOp&&!d.fieldTroop)return false;
    const input={op:d.fieldOp||d.fieldTroop};
    for(const key of ['building','slot','kit','payment','price'])if(d[key])input[key]=d[key];
    if(d.fieldTroop){input.type=document.getElementById('field-species').value;input.quantity=Number(document.getElementById('field-quantity').value);input.building=Number(document.getElementById('field-bunker')?.value||0);}
    const modal=!!state.modal;
    el.disabled=true;await fieldAction(input,'outpostAct',modal);if(el.isConnected)el.disabled=false;return true;
  }
  async function placeOutpost(pos,p){
    if(p.siteId!==state.worldSite?.site.id){cancelPlacement();return;}
    await fieldAction({op:p.mode,type:p.type,building:p.id,revision:p.revision,...pos});
  }

  async function plan(id) {
    onNavigate();
    const epoch=++requestEpoch, viewer=state.user?.id, modalEpoch=state.modalEpoch;
    try {
      const data = await api('worldSite',undefined,{id}), {site}=data;
      if(epoch!==requestEpoch || viewer!==state.user?.id || modalEpoch!==state.modalEpoch || state.battle?.status==='active') return;
      showModal(`<div class="territory-scout"><div><span class="eyebrow">MAKE ROOM FOR AN EMPIRE</span><h2 id="modal-title">Conquer ${esc(site.name)}?</h2><p>${esc(owner(site))} · ${site.q}, ${site.r} · Level ${site.level}</p></div>${art(cover(site),'')}</div><div class="guide-grid"><div class="guide-item"><h3>Win, then claim</h3><p>A battle win and a capture are separate. Reach 90% health-weighted damage to take this territory. Ending after an ordinary 50% building victory can leave it with its current owner.</p></div><div class="guide-item"><h3>Hold what you take</h3><p>${data.outpostYard?'Reach 90% weighted damage, then pay the takeover price from your treasury. The winning keeper has first claim for five minutes. Payment starts 12 hours of protection.':'Capture begins a five-minute shield and repairs this site. Its previous garrison is cleared. Neighboring sites keep their current owners; assign your own defenders after rebuilding.'}</p></div></div>${networkHTML(site)}${site.kind==='fortification'?`<p class="subtle-note">Fortifications owned: ${num(data.fortificationCount)} / ${num(data.fortificationLimit)}. This capacity is separate from your resource outposts and strongholds.</p>`:''}<p class="subtle-note">Launched monsters leave your army. Your home shield ends when the invasion starts. No resources are taken from the previous owner’s home.</p>${!data.canAttack?`<div class="facility-status">${icon('lock')}<span>${esc(data.attackReason)}</span></div>`:''}<div class="territory-actions"><button class="button subtle" data-command="close-modal">Keep scouting</button><button class="button primary" data-world-start="${site.id}" ${data.canAttack?'':'disabled'}>${icon('swords')}Begin conquest</button></div>`,'territory-attack');
    } catch(e) {toast(e.message,true);}
  }

  function managementHTML(data) {
    const {site,management:m}=data;
    if(!site.owned || !m) return '';
    const busy=site.busy || site.ready_at>now(), upgrade=m.upgrade||{}, repair=m.repair||{};
    const garrison=m.garrison||{}, army=m.army||state.army;
    const species=Object.keys(state.catalog.monsters).filter(type=>(army[type]||0)>0 || (garrison[type]||0)>0);
    return `<div class="territory-scout"><div><span class="eyebrow">YOUR OUTER EMPIRE · ${site.q}, ${site.r}</span><h2 id="modal-title">${esc(site.name)}</h2><p>Level ${site.level} · ${note(site)}</p></div>${art(cover(site),'')}</div>${busy?`<div class="facility-status">${icon('clock')}<span>${site.busy?'An invasion is in progress. Management resumes after the battle.':`Work completes in <b data-countdown="${site.ready_at}">${duration(site.ready_at-now())}</b>.`}</span></div>`:''}${networkHTML(site)}${site.kind==='fortification'?`<p class="subtle-note">${num(data.fortificationCount)} / ${num(data.fortificationLimit)} Fortifications owned. Six connected cells reduce neighboring building damage by 75%.</p>`:benefitsHTML(m.benefits)}<div class="territory-investments"><article><span class="eyebrow">${site.kind==='fortification'?'STRENGTHEN THIS CELL':'GROW YOUR REACH'}</span><h3>${upgrade.maxLevel && site.level>=upgrade.maxLevel?'Fully upgraded':`Upgrade to level ${site.level+1}`}</h3><p>${site.kind==='fortification'?'Improve the core, local towers and bunker capacity. Network protection depends on connected ownership, not this level.':'Improve this territory’s benefits and defenses.'}</p>${benefitsHTML(upgrade.benefits,'After upgrade')}${costHTML(upgrade.cost||{})}<button class="button primary block" data-world-action="worldUpgrade" data-site="${site.id}" ${busy||upgrade.available===false?'disabled':''}>${icon('arrow')}Upgrade${upgrade.duration?' · '+duration(upgrade.duration):''}</button>${upgrade.reason?`<p class="subtle-note">${esc(upgrade.reason)}</p>`:''}</article><article><span class="eyebrow">KEEP THE LINE STRONG</span><h3>Repair defenses</h3><p>Restore the territory’s damaged structures and rearm its traps.</p>${costHTML(repair.cost||{})}<button class="button subtle block" data-world-action="worldRepair" data-site="${site.id}" ${busy||repair.available===false?'disabled':''}>${icon('repair')}Repair${repair.duration?' · '+duration(repair.duration):''}</button>${repair.reason?`<p class="subtle-note">${esc(repair.reason)}</p>`:''}</article></div><div class="territory-garrison"><div class="territory-section-heading"><div><span class="eyebrow">MONSTERS ON GUARD</span><h3>Territory garrison</h3></div><span class="pill">${num(m.garrisonUsed)} / ${num(m.garrisonCapacity)} housing</span></div><p>Move real monsters between your home army and this bunker. Defenders fight automatically when attacked.</p>${species.length?species.map(type=>`<div class="territory-troop">${art(type,'')}<div><b>${esc(state.catalog.monsters[type].name)}</b><small>${num(army[type])} at home · ${num(garrison[type])} defending</small></div><label>Amount<input type="number" id="world-quantity-${type}" value="1" min="1" max="1000" step="1" aria-label="${esc(state.catalog.monsters[type].name)} transfer amount" ${busy?'disabled':''}></label><button class="button subtle small" data-world-action="worldGarrison" data-site="${site.id}" data-type="${type}" ${busy||m.canGarrison===false||!army[type]?'disabled':''}>Assign</button><button class="button subtle small" data-world-action="worldUngarrison" data-site="${site.id}" data-type="${type}" ${busy||m.canUngarrison===false||!garrison[type]?'disabled':''}>Return</button></div>`).join(''):'<p class="subtle-note">Hatch monsters at home to station defenders here.</p>'}</div><div class="territory-abandon"><p>${site.kind==='fortification'?'Abandoning removes this cell from your defense network. Future attacks on adjacent holdings receive less protection.':'Abandoning removes this territory’s income, storage, and reach.'} Stationed monsters are lost.</p><button class="button danger small" data-world-abandon="${site.id}" ${busy?'disabled':''}>Abandon territory</button></div>`;
  }

  async function manage(id) {
    onNavigate();
    const epoch=++requestEpoch,viewer=state.user?.id,modalEpoch=state.modalEpoch;
    try {const data=await api('worldSite',undefined,{id});if(epoch!==requestEpoch||viewer!==state.user?.id||modalEpoch!==state.modalEpoch||state.battle?.status==='active')return;if(!data.site.owned)throw new Error('You no longer own this territory.');state.worldSite=data;showModal(data.outpostYard?fieldWorkshop(data):managementHTML(data),data.outpostYard?'mr2-manage':'territory-manage');}
    catch(e){toast(e.message,true);}
  }

  async function refresh() {
    const id=state.worldSite?.site.id, epoch=requestEpoch, viewer=state.user?.id;
    if(!id || state.page!=='yard' || state.battle) return;
    try {
      const data=await api('worldSite',undefined,{id});
      if(epoch!==requestEpoch || viewer!==state.user?.id || state.worldSite?.site.id!==id || state.battle) return;
      state.worldSite=data;updateSite(data.base);
      if(state.modal==='territory-manage') {
        if(!data.site.owned){closeModal();toast('This territory now belongs to another keeper.');return;}
        const body=document.getElementById('modal-body'),card=document.querySelector('.modal-card'),active=document.activeElement;
        const fields=[...body.querySelectorAll('input[id]')].map(input=>({id:input.id,value:input.value}));
        const activeId=body.contains(active)?active.id:null;
        const activeAction=body.contains(active)?active.dataset?.worldAction:null,activeType=active?.dataset?.type;
        const top=card.scrollTop,next=managementHTML(data);
        if(body.innerHTML!==next){
          body.innerHTML=next;for(const field of fields){const input=document.getElementById(field.id);if(input)input.value=field.value;}
          const focus=activeId?document.getElementById(activeId):activeAction?body.querySelector(`[data-world-action="${activeAction}"]${activeType?`[data-type="${activeType}"]`:''}`):null;
          focus?.focus({preventScroll:true});card.scrollTop=top;
        }
      }
    }catch(e){toast(e.message,true);}
  }

  async function handleClick(el) {
    if(await fieldClick(el))return true;
    const id=Number(el.dataset.site || el.dataset.worldManage || el.dataset.worldPlan || el.dataset.worldStart || el.dataset.worldAbandon);
    if(el.dataset.worldManage){await manage(id);return true;}
    if(el.dataset.worldPlan){await plan(id);return true;}
    if(el.dataset.worldAbandon){showModal(`<span class="eyebrow">GIVE UP THIS TERRITORY</span><h2 id="modal-title">Abandon your foothold?</h2><p>This removes its benefits and permanently loses the monsters stationed here. Abandoning a Fortification also removes its protection from adjacent sites you own. The territory becomes available for rivals to capture.</p><div class="territory-actions"><button class="button subtle" data-world-manage="${id}">Keep territory</button><button class="button danger" data-world-action="worldAbandon" data-site="${id}">Abandon territory</button></div>`,'territory-abandon');return true;}
    if(el.dataset.worldStart){
      onNavigate();
      const epoch=++requestEpoch,viewer=state.user?.id;
      el.disabled=true;
      try{const battle=await api('worldAttack',{siteId:id});if(epoch!==requestEpoch||viewer!==state.user?.id)return;closeModal();state.worldSite=null;await beginBattle(battle);}
      catch(e){toast(e.message,true);}finally{if(el.isConnected)el.disabled=false;}return true;
    }
    if(['worldUpgrade','worldRepair','worldGarrison','worldUngarrison','worldAbandon'].includes(el.dataset.worldAction)){
      onNavigate();
      const epoch=++requestEpoch,viewer=state.user?.id;
      const input={siteId:id};
      if(el.dataset.type){input.type=el.dataset.type;input.quantity=Number(document.getElementById(`world-quantity-${input.type}`)?.value);if(!Number.isInteger(input.quantity)||input.quantity<1){toast('Enter a whole number of monsters, at least one.',true);return true;}}
      el.disabled=true;
      try{
        const data=await api(el.dataset.worldAction,input);if(epoch!==requestEpoch||viewer!==state.user?.id)return true;state.worldSite=data;await refreshHome();if(epoch!==requestEpoch||viewer!==state.user?.id)return true;audio.effect('build');
        if(el.dataset.worldAction==='worldAbandon'){closeModal();toast('Territory abandoned.');await visit(id);}
        else{showModal(managementHTML(data),'territory-manage');toast(el.dataset.worldAction==='worldGarrison'?'Defenders assigned.':el.dataset.worldAction==='worldUngarrison'?'Monsters returned home.':'Territory work started.');await showSite(data.base);}
        refreshMap();
      }catch(e){toast(e.message,true);if(el.isConnected)el.disabled=false;}
      return true;
    }
    return false;
  }
  return {placeOutpost,visit,panel,plan,manage,refresh,handleClick,isSite,cancelPending(){requestEpoch++;},invalidate(){requestEpoch++;state.worldSite=null;}};
}
