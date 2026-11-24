import {duration,esc,icon,num} from './ui.js?v=0.13.0';

const facilities = {siegelab:'research',siegefactory:'build'};
const titles = {research:'Chaos Lab',build:'Chaos Factory'};
const resourceNames={twig:'twigs',pebble:'pebbles',putty:'putty',goo:'goo'};

function prices(resources) {
  return Object.entries(resources).filter(([,n])=>n>0).map(([r,n])=>
    `<span title="${esc(resourceNames[r])}">${icon(r)}${num(n)} ${esc(resourceNames[r])}</span>`).join('');
}

export function workshopView(data,lane,{busy=false,battle=false}={}) {
  const current=data[lane];
  const structure=lane==='research'?'siegelab':'siegefactory';
  const intro=lane==='research'?'Each Lab level opens the next research tier for all three devices.':
    'Build one researched device, then take it with you on an invasion.';
  let job='';
  if(current) {
    const label=lane==='research'?'Researching':'Building';
    const clock=current.paused?'Paused until the building is repaired':`<span data-countdown="${Number(current.ready_at)}">Finishing…</span>`;
    const lost=Object.entries(current.overflow || {}).filter(([,n])=>n>0).map(([r,n])=>`${num(n)} ${resourceNames[r]}`).join(', ');
    job=`<section class="chaos-job" aria-live="polite"><div><b>${label} ${esc(data.devices[current.type].name)} · Lv ${current.level}</b>
      <p>${clock}</p><small>${lost?'Cancelling now would exceed storage by '+esc(lost)+'.':'Cancellation refunds your paid resources.'}</small></div>
      <button class="button subtle small" data-siege-cancel="${lane}" data-siege-revision="${Number(data.revision[lane])}" ${busy||battle?'disabled':''}>Cancel order</button></section>`;
  }
  const cards=Object.entries(data.devices).map(([type,device])=>{
    const option=device[lane];
    const label=lane==='research'?device.level===0?'Unlock device':`Research level ${option.level}`:'Build device';
    const priced=option.seconds>0;
    const block=battle?'Finish the invasion before placing a workshop order.':option.reason;
    return `<article class="chaos-device"><header><h3>${esc(device.name)}</h3><span>${device.level?'Level '+device.level:'Undiscovered'}${device.quantity?' · 1 prepared':''}</span></header>
      <p>${esc(device.description)}</p>${priced?`<div class="chaos-cost">${prices(option.cost)}<span>${icon('clock')}${duration(option.seconds)}</span></div>`:''}
      ${block?`<small>${esc(block)}</small>`:''}
      ${priced?`<button class="button primary small" data-siege-order="${esc(type)}" data-siege-lane="${lane}" data-siege-revision="${Number(data.revision[lane])}" ${busy||block?'disabled':''}>${label}</button>`:''}</article>`;
  }).join('');
  return `<header class="chaos-header"><img src="assets/art/${structure}.png" alt=""><div><h2 id="modal-title">${titles[lane]}</h2><p>${intro}</p></div></header>
    <div class="chaos-tabs" aria-label="Workshop facilities">${Object.entries(titles).map(([key,title])=>`<button type="button" class="button subtle small" aria-pressed="${key===lane}" data-siege-open="${key}">${title}</button>`).join('')}</div>
    ${job}<div class="chaos-cards">${cards}</div>`;
}

export function createSiegeWorkshop({state,showModal,action,toast,audio}) {
  let lane='research',pending=null,last='',owner=null;
  const canUse=()=>!!state.user && !state.visiting && !state.worldSite && state.base?.owner?.id===state.user.id;
  const fighting=()=>!!state.activeBattle || state.battle?.status==='active';
  const markup=()=>workshopView(state.base.siege,lane,{busy:!!pending,battle:fighting()});
  function button(building) {
    if (!facilities[building.type]||!canUse()) { return ''; }
    return `<button class="button subtle block" data-siege-open="${facilities[building.type]}">${icon('build')}Manage ${titles[facilities[building.type]]}</button>`;
  }
  function refresh() {
    if(owner!==state.user?.id){owner=state.user?.id;pending=null;last='';}
    if(state.modal!=='siege-workshop'||!canUse()||!state.base?.siege) return;
    const root=document.querySelector('#siege-workshop');
    if(!root)return;
    const html=markup();
    if(html===last)return;
    const focused=root.contains(document.activeElement)?document.activeElement.dataset:null;
    root.innerHTML=html;last=html;
    if(focused){
      const match=focused.siegeOrder?`[data-siege-order="${CSS.escape(focused.siegeOrder)}"]:not(:disabled)`:
        focused.siegeCancel?'[data-siege-cancel]:not(:disabled)':`[data-siege-open="${lane}"]`;
      (root.querySelector(match)||root.querySelector(`[data-siege-open="${lane}"]`))?.focus({preventScroll:true});
    }
  }
  async function handleClick(el) {
    const ds=el.dataset;
    if(ds.siegeOpen && titles[ds.siegeOpen]){
      if(!canUse()||!state.base.siege) return true;
      owner=state.user.id;lane=ds.siegeOpen;
      if(state.modal==='siege-workshop'){refresh();}else{last=markup();showModal(`<div id="siege-workshop">${last}</div>`,'siege-workshop');}
      return true;
    }
    if(!ds.siegeOrder && !ds.siegeCancel)return false;
    if(!canUse()||fighting()||pending||state.modal!=='siege-workshop')return true;
    const selected=ds.siegeCancel || ds.siegeLane, data=state.base.siege;
    const revision=Number(ds.siegeRevision);
    if(selected!==lane||revision!==data.revision[lane])return true;
    const cancel=!!ds.siegeCancel;
    if(cancel?!data[lane]:!data.devices[ds.siegeOrder]||data.devices[ds.siegeOrder][lane].reason)return true;
    const token={id:state.user.id,modal:state.modalEpoch};
    pending=token;refresh();
    try{
      const name=cancel?'siegeCancel':lane==='research'?'siegeResearch':'siegeBuild';
      const result=await action(name,cancel?{lane,revision}:{type:ds.siegeOrder,revision});
      if(state.user?.id===token.id && state.modalEpoch===token.modal && result?.siegeResult) {
        if(cancel){
          const lost=Object.values(result.siegeResult.overflow||{}).reduce((a,b)=>a+Number(b),0);
          toast(lost?`Order cancelled. Storage was full for ${num(lost)} resources.`:'Order cancelled. Paid resources refunded.');
        }else{audio.effect(lane==='research'?'research':'build');toast(lane==='research'?'Chaos research started.':'Factory order started.');}
      }
    }finally{if(pending===token){pending=null;refresh();}}
    return true;
  }
  return {button,refresh,handleClick};
}
