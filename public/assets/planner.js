import {icon, esc, num} from './ui.js?v=0.13.0';

const copyPositions = positions => positions.map(p => ({id:Number(p.id),type:p.type,x:Number(p.x),y:Number(p.y)}));
const key = positions => JSON.stringify([...positions].sort((a,b)=>a.id-b.id));

// Draft geometry is isolated from live records. No building can be created,
// destroyed, healed or upgraded by placing it in this temporary inventory.
export class YardPlanDraft {
  constructor(base,catalog) { this.catalog=catalog; this.history=[]; this.selected=null; this.reset(base); }
  reset(base) {
    this.live=base;this.records=new Map(base.buildings.map(b=>[Number(b.id),{...b}]));
    this.revision=base.planner?.revision||'';this.positions=copyPositions(base.buildings);
    this.original=copyPositions(base.buildings);this.locked=new Set(base.planner?.lockedIds||[]);
    this.history=[];this.selected=null;this.stale=false;this.message='';
  }
  sync(base) {
    this.live=base;this.stale=base.planner?.revision!==this.revision;
    this.locked=new Set(base.planner?.lockedIds||[]);
    for(const b of base.buildings) if(this.records.has(Number(b.id)))this.records.set(Number(b.id),{...b});
    const pinned=copyPositions(base.buildings.filter(b=>this.locked.has(Number(b.id))&&this.records.has(Number(b.id))));
    const pin=positions=>[...positions.filter(p=>!this.locked.has(p.id)),...copyPositions(pinned)];
    const next=pin(this.positions);
    if(key(next)!==key(this.positions))this.message='Work started in your live yard. Those buildings are now pinned in their actual positions; your other edits are preserved.';
    this.positions=next;this.history=this.history.map(pin);
  }
  get dirty(){return key(this.positions)!==key(this.original);}
  get unplaced(){const placed=new Set(this.positions.map(p=>p.id));return [...this.records.values()].filter(b=>!placed.has(Number(b.id)));}
  get buildings(){return this.positions.map(p=>({...this.records.get(p.id),...p}));}
  snapshot(){this.history.push(copyPositions(this.positions));if(this.history.length>80)this.history.shift();}
  check(positions=this.positions) {
    const errors=[],invalid=new Set(), seen=new Set(), margin=((this.live.yardExtent||32)-32)/2;
    for(const p of positions){
      const record=this.records.get(p.id), spec=this.catalog.buildings[p.type];
      if(!record||seen.has(p.id)||record.type!==p.type||!spec){errors.push('The draft contains an unknown or repeated building.');invalid.add(p.id);continue;}
      seen.add(p.id);const half=Number(spec.size)/2;
      if(!Number.isFinite(p.x)||!Number.isFinite(p.y)||p.x-half<1-margin-1e-9||p.y-half<1-margin-1e-9||p.x+half>31+margin+1e-9||p.y+half>31+margin+1e-9){errors.push('Keep every footprint inside the yard boundary.');invalid.add(p.id);}
      if((this.live.decorations||[]).some(item=>{const gap=half+(this.catalog.decorations?.[item.item]?.size||.4)/2+.15;return Math.abs(item.x-p.x)<gap-1e-9 && Math.abs(item.y-p.y)<gap-1e-9;})){errors.push('Move or store the decoration under that footprint first.');invalid.add(p.id);}
      if((this.live.signs||[]).some(sign=>sign.expires>Date.now()/1000 && Math.abs(sign.x-p.x)<half+.55-1e-9&&Math.abs(sign.y-p.y)<half+.55-1e-9)){errors.push('Remove the sign under that footprint first.');invalid.add(p.id);}
      const mushroomGap=half+.65/2+.15;
      if((this.live.mushrooms?.items||[]).some(item=>Math.abs(item.x-p.x)<mushroomGap && Math.abs(item.y-p.y)<mushroomGap)){errors.push('Pick the mushroom under that footprint first.');invalid.add(p.id);}
      const live=this.live.buildings.find(b=>Number(b.id)===p.id);
      if(this.locked.has(p.id)&&live&&(p.x!==Number(live.x)||p.y!==Number(live.y))){errors.push('A building with work in progress must stay in its current position.');invalid.add(p.id);}
    }
    for(let i=0;i<positions.length;i++)for(let j=i+1;j<positions.length;j++){
      const a=positions[i],b=positions[j],sa=this.catalog.buildings[a.type],sb=this.catalog.buildings[b.type];if(!sa||!sb)continue;
      const gap=(Number(sa.size)+Number(sb.size))/2+.15;
      if(Math.abs(a.x-b.x)<gap-1e-9&&Math.abs(a.y-b.y)<gap-1e-9){errors.push('Some building footprints overlap.');invalid.add(a.id);invalid.add(b.id);}
    }
    const missing=this.records.size-seen.size;
    if(missing)errors.push(`Place all ${missing} stored building${missing===1?'':'s'} before saving or applying.`);
    if(this.stale)errors.unshift('Your live yard changed. Reset the draft to the current yard before applying.');
    if(!this.live.planner?.available)errors.unshift(this.live.planner?.reason||'A completed, living Yard Planner is required.');
    return {valid:errors.length===0,errors:[...new Set(errors)],invalidIds:[...invalid],missing};
  }
  place(id,x,y) {
    id=Number(id);const record=this.records.get(id);if(!record)return 'Choose a building in this draft.';
    if(this.locked.has(id))return 'This building is pinned until its work finishes.';
    const candidate={id,type:record.type,x:Number(x),y:Number(y)};
    const next=this.positions.filter(p=>p.id!==id);next.push(candidate);
    // Missing buildings are allowed while drafting; placement itself must be clear.
    const issue=this.check(next);if(issue.invalidIds.includes(id))return issue.errors.find(e=>!e.startsWith('Place all')&&!e.startsWith('Your live'))||'Choose a clear position.';
    if(key(next)===key(this.positions))return null;
    this.snapshot();this.positions=next;this.selected=id;this.message='';return null;
  }
  stash(id) {
    id=Number(id);if(this.locked.has(id))return 'Work in progress pins this building to the yard.';
    if(!this.positions.some(p=>p.id===id))return null;
    this.snapshot();this.positions=this.positions.filter(p=>p.id!==id);this.selected=id;return null;
  }
  clear(){this.snapshot();this.positions=this.positions.filter(p=>this.locked.has(p.id));this.selected=null;this.message='Stored buildings remain safe in your live yard.';}
  undo(){if(this.history.length)this.positions=this.history.pop();this.selected=null;this.message='';}
  load(slot) {
    this.snapshot();const saved=new Map((slot.positions||[]).map(p=>[Number(p.id),p]));let obsolete=0;
    for(const [id,p] of saved)if(!this.records.has(id)||this.records.get(id).type!==p.type)obsolete++;
    this.positions=[];
    for(const [id,b] of this.records){
      const live=this.live.buildings.find(p=>Number(p.id)===id);
      if(this.locked.has(id)&&live)this.positions.push(...copyPositions([live]));
      else if(saved.get(id)?.type===b.type)this.positions.push(...copyPositions([saved.get(id)]));
    }
    this.selected=null;
    this.message=`Loaded ${slot.name}.${obsolete?` ${obsolete} unavailable building${obsolete===1?' was':'s were'} omitted.`:''}${this.unplaced.length?` ${this.unplaced.length} new building${this.unplaced.length===1?' needs':'s need'} placement.`:''}${this.locked.size?' Buildings with active work stay pinned.':''}`;
  }
  payload(){return {positions:copyPositions(this.positions).sort((a,b)=>a.id-b.id),revision:this.revision};}
}

export function createYardPlanner({state,getRenderer,api,applyState,toast,art,showModal,closeModal,onOpen,onClose,now}) {
  let draft=null,epoch=0,busy=false,ranges=true,grid=true;
  const names=new Map();
  const $=selector=>document.querySelector(selector);
  const active=()=>!!draft;
  const validSession=token=>draft&&epoch===token.epoch&&state.user?.id===token.owner&&state.page==='yard'&&!state.visiting&&!state.battle;
  function open() {
    if(!state.user||state.visiting||state.battle||state.page!=='yard')return;
    if(!state.base?.planner?.available){toast(state.base?.planner?.reason||'Build a Yard Planner at Town Hall level 3 first.',true);return;}
    onOpen();draft=new YardPlanDraft(state.base,state.catalog);epoch++;busy=false;
    names.clear();for(const slot of state.base.planner.slots||[])names.set(Number(slot.slot),slot.name);
    render();$('#yard-canvas')?.focus();
  }
  function cancel(restore=true) {
    if(!draft)return;draft=null;epoch++;busy=false;
    const renderer=getRenderer();renderer.setPlanner(null);renderer.setPlacement(null);renderer.setSelected(null);
    $('#planner-toolbar').hidden=true;$('#planner-inventory').hidden=true;$('#page-yard').classList.remove('is-planning');
    if(restore&&state.base)renderer.setBase({...state.base,army:state.army});
    $('#yard-canvas')?.setAttribute?.('aria-label','Interactive isometric backyard. Select buildings to upgrade or move them.');
    onClose();
  }
  function sync(){if(!draft)return;draft.sync(state.base);render();}
  function render(){
    if(!draft)return;
    const focused=document.activeElement,focusSlot=focused?.dataset?.plannerName,caret=focusSlot?[focused.selectionStart,focused.selectionEnd]:null;
    const renderer=getRenderer(),status=draft.check(),unplaced=draft.unplaced;
    const selected=draft.records.get(draft.selected),pinned=selected&&draft.locked.has(Number(selected.id));
    $('#yard-canvas')?.setAttribute?.('aria-label','Yard Planner draft. Drag buildings to move them or use the building selector and storage controls.');
    $('#page-yard').classList.add('is-planning');$('#planner-toolbar').hidden=false;$('#planner-inventory').hidden=false;
    $('#planner-toolbar').innerHTML=`<div class="planner-heading"><span class="planner-mark">${icon('move')}</span><div><span class="eyebrow">YARD PLANNER · PRIVATE DRAFT</span><h2>Make room for your next idea.</h2><p>Your live defenses stay in place until you apply this layout.</p></div></div><div class="planner-actions"><button class="button subtle small" data-planner="undo" ${!draft.history.length||busy?'disabled':''}>${icon('refresh')}Undo</button><button class="button subtle small" data-planner="reset" ${busy?'disabled':''}>Reset draft</button><button class="button subtle small" data-planner="cancel" ${busy?'disabled':''}>Cancel</button><button class="button primary small" data-planner="apply" ${!status.valid||busy?'disabled':''}>${icon('check')}Apply layout</button></div>`;
    $('#yard-panel').innerHTML=`<div class="planner-panel-head">${art('planner','Yard Planner')}<span class="eyebrow">ARRANGE YOUR BACKYARD</span><h2>${selected?esc(state.catalog.buildings[selected.type]?.name):'A place for everything.'}</h2><p>${selected?pinned?'Work is underway. This building stays pinned until the job finishes.':'Drag this building, or choose Move and click a clear spot.': 'Select a building to move it. Drag empty ground to pan the yard.'}</p>${selected?`<div class="planner-selected-actions"><button class="button primary block" data-planner="move" data-id="${selected.id}" ${pinned||busy?'disabled':''}>${icon('move')}Move in draft</button><button class="button subtle block" data-planner="stash" data-id="${selected.id}" ${pinned||busy?'disabled':''}>${icon('box')}Store in draft</button></div>`:''}</div><div class="planner-panel-body"><label class="planner-select-label" for="planner-building-select">Select a building</label><select id="planner-building-select" ${busy?'disabled':''}><option value="">Choose a building…</option>${[...draft.records.values()].map(b=>`<option value="${b.id}" ${draft.selected===Number(b.id)?'selected':''}>${esc(state.catalog.buildings[b.type]?.name||b.type)} #${b.id}${draft.locked.has(Number(b.id))?' · pinned':''}</option>`).join('')}</select><div class="planner-validation ${status.valid?'valid':'invalid'}" role="status"><b>${status.valid?'Ready to apply':`${unplaced.length?num(unplaced.length)+' to place':'Check this layout'}`}</b><p>${status.valid?'Every building is placed with room around its footprint.':esc(status.errors[0])}</p></div>${draft.message?`<p class="planner-notice">${esc(draft.message)}</p>`:''}<div class="planner-view-options"><button class="button subtle small" data-planner="grid" aria-pressed="${grid}">${icon('build')}Grid</button><button class="button subtle small" data-planner="ranges" aria-pressed="${ranges}">${icon('eye')}Ranges</button></div><div class="planner-slots-heading"><span class="eyebrow">TWO SAVED LAYOUTS</span><p>Save a plan without moving your buildings.</p></div>${[1,2].map(slotNumber=>{const slot=(state.base.planner.slots||[]).find(s=>Number(s.slot)===slotNumber);return `<section class="planner-slot"><label for="planner-name-${slotNumber}">Layout ${slotNumber}${slot?' · saved':''}</label><input id="planner-name-${slotNumber}" data-planner-name="${slotNumber}" maxlength="80" value="${esc(names.get(slotNumber)||'')}" placeholder="Name this layout" ${busy?'disabled':''}><div><button class="button primary small" data-planner="save" data-slot="${slotNumber}" ${!status.valid||busy?'disabled':''}>Save draft</button><button class="button subtle small" data-planner="load" data-slot="${slotNumber}" ${!slot||busy?'disabled':''}>Load</button>${slot?`<button class="icon-button" data-planner="delete-review" data-slot="${slotNumber}" aria-label="Delete layout ${slotNumber}" ${busy?'disabled':''}>${icon('trash')}</button>`:''}</div></section>`;}).join('')}<button class="button subtle block" data-planner="clear" ${busy?'disabled':''}>Store all movable buildings</button><p class="subtle-note">Temporary storage affects this draft only. Construction and reinforcement pin their buildings in place.</p></div>`;
    $('#planner-inventory').innerHTML=`<div class="planner-inventory-heading"><div><span class="eyebrow">TEMPORARY STORAGE</span><h3>${unplaced.length?`${unplaced.length} building${unplaced.length===1?'':'s'} to place`:'Every building is in the yard'}</h3></div><span>${draft.positions.length} / ${draft.records.size} placed</span></div>${unplaced.length?`<div class="planner-building-list">${unplaced.map(b=>`<button class="planner-building ${draft.selected===Number(b.id)?'selected':''}" data-planner="move" data-id="${b.id}" ${busy?'disabled':''}>${art(b.type,'')}<span><b>${esc(state.catalog.buildings[b.type]?.name||b.type)}</b><small>Level ${b.level} · #${b.id}</small></span>${icon('plus')}</button>`).join('')}</div>`:'<p class="subtle-note">Store a building here to make space for a swap. Nothing is removed from your live backyard.</p>'}`;
    renderer.setBase({...state.base,buildings:draft.buildings,army:{}});
    renderer.setPlanner({lockedIds:[...draft.locked],invalidIds:status.invalidIds,grid,ranges,busy});
    renderer.setSelected(draft.buildings.find(b=>Number(b.id)===draft.selected)||null);
    if(state.placement?.mode!=='planner')renderer.setPlacement(null);
    $('#yard-title').innerHTML='Yard Planner <span class="level-tag">DRAFT</span>';
    $('#stage-badge').innerHTML='<i></i> Planning your backyard';$('#stage-hint').textContent='Drag buildings · click to select · empty ground pans';
    $('#yard-weather').textContent=busy?'Saving your changes…':'Your live yard stays safe while you plan.';
    if(focusSlot){const input=$('#planner-name-'+focusSlot);input?.focus({preventScroll:true});if(caret?.every(Number.isInteger))input?.setSelectionRange?.(...caret);}
  }
  function select(building){if(!draft)return false;draft.selected=building?Number(building.id):null;state.placement=null;getRenderer().setPlacement(null);render();return true;}
  function place(pos,placement){if(!draft||placement.mode!=='planner')return false;if(busy)return true;const error=draft.place(placement.id,pos.x,pos.y);if(error){toast(error,true);return true;}state.placement=null;getRenderer().setPlacement(null);render();return true;}
  function drag(id,pos){if(!draft||busy)return;const error=draft.place(id,pos.x,pos.y);if(error)toast(error,true);state.placement=null;getRenderer().setPlacement(null);render();}
  function handleInput(el){if(el.dataset.plannerName)names.set(Number(el.dataset.plannerName),el.value);if(el.id==='planner-building-select'&&draft){draft.selected=Number(el.value)||null;state.placement=null;render();}}
  function keydown(event){if(!draft||busy||state.modal||['INPUT','TEXTAREA','SELECT'].includes(event.target?.tagName))return false;if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='z'){event.preventDefault();draft.undo();state.placement=null;render();return true;}return false;}
  async function request(action,input,success){
    if(!draft||busy)return;const token={epoch,owner:state.user.id};busy=true;render();
    try{const result=await api(action,input);if(!validSession(token))return;applyState(result);if(!validSession(token))return;if(action==='plannerApply'){cancel();toast(success);}else{draft.sync(state.base);toast(success);}return true;}
    catch(error){if(validSession(token)){toast(error.message,true);}return false;}
    finally{if(validSession(token)){busy=false;render();}}
  }
  async function handleClick(el){
    const command=el.dataset.planner;if(!command)return false;if(command==='open'){open();return true;}if(!draft)return true;
    if(busy)return true;if(command==='cancel'){cancel();return true;}
    const id=Number(el.dataset.id),slotNumber=Number(el.dataset.slot),slot=(state.base.planner.slots||[]).find(s=>Number(s.slot)===slotNumber);
    if(command==='move'){if(!draft.records.has(id)||draft.locked.has(id))return true;draft.selected=id;state.placement={mode:'planner',type:draft.records.get(id).type,id};render();getRenderer().setPlacement(state.placement);}
    if(command==='stash'){const error=draft.stash(id);if(error)toast(error,true);state.placement=null;render();}
    if(command==='undo'){draft.undo();state.placement=null;render();}
    if(command==='clear'){draft.clear();state.placement=null;render();}
    if(command==='reset'){draft.reset(state.base);state.placement=null;render();}
    if(command==='grid'){grid=!grid;render();}if(command==='ranges'){ranges=!ranges;render();}
    if(command==='load'&&slot){draft.load(slot);state.placement=null;render();}
    if(command==='save'||command==='save-confirm'){const name=(names.get(slotNumber)||'').trim();if(!name){toast('Give this saved layout a name.',true);return true;}if(Array.from(name).length>40){toast('Layout names can contain up to 40 characters.',true);return true;}if(command==='save'&&slot){showModal(`<span class="eyebrow">SAVED YARD LAYOUT</span><h2 id="modal-title">Replace ${esc(slot.name)}?</h2><p>Save this draft as ${esc(name)} in slot ${slotNumber}. Your live yard stays unchanged.</p><div class="planner-dialog-actions"><button class="button subtle" data-command="close-modal">Keep existing layout</button><button class="button primary" data-planner="save-confirm" data-slot="${slotNumber}">Replace saved layout</button></div>`,'planner-save');return true;}if(command==='save-confirm'){if(state.modal!=='planner-save')return true;closeModal();}if(draft.check().valid)await request('plannerSave',{...draft.payload(),slot:slotNumber,name},'Layout saved. Your live yard has not moved.');}
    if(command==='apply'&&draft.check().valid)await request('plannerApply',draft.payload(),'Layout applied. Your buildings are in their new positions.');
    if(command==='delete-review'&&slot)showModal(`<span class="eyebrow">SAVED YARD LAYOUT</span><h2 id="modal-title">Delete ${esc(slot.name)}?</h2><p>This removes the saved layout from slot ${slotNumber}. Your live yard and current draft stay unchanged.</p><div class="planner-dialog-actions"><button class="button subtle" data-command="close-modal">Keep layout</button><button class="button danger" data-planner="delete" data-slot="${slotNumber}">Delete saved layout</button></div>`,'planner-delete');
    if(command==='delete'&&slot&&state.modal==='planner-delete'){closeModal();const saved=await request('plannerDelete',{slot:slotNumber,revision:draft.revision},'Saved layout deleted.');if(saved&&draft){names.delete(slotNumber);render();}}
    return true;
  }
  function escape(){if(!draft)return false;if(busy)return true;if(state.placement?.mode==='planner'){state.placement=null;getRenderer().setPlacement(null);render();}else cancel();return true;}
  return {active,open,cancel,sync,render,select,place,drag,handleClick,handleInput,keydown,escape};
}
