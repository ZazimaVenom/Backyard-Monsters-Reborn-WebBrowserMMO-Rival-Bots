import {icon, esc, num, duration} from './ui.js?v=0.13.0';

// Costs, eligibility and completed protection come from the saved owner snapshot.
export function createFortifications({state, costHTML, showModal, closeModal, action, toast, now, audio}) {
  const tier = building => Math.max(0, Math.min(4, Number(building.fortification) || 0));
  const protection = building => [0, 20, 30, 40, 50][tier(building)];
  let cancellation = null;

  function networkPanel(base=state.visiting||state.base, building=null) {
    if(base?.site?.kind==='fortification'||base?.target?.kind==='fortification') return '';
    const support=base?.networkDefense, count=Math.floor(Math.max(0,Math.min(6,Number(support?.count)||0)));
    const reduction=Math.max(0,Math.min(.75,Number(support?.reduction)||0));
    if(!count||!reduction) return '';
    const armor=building?protection(building)/100:0;
    const total=Math.round((1-(1-armor)*(1-reduction))*1000)/10;
    return `<section class="network-defense-note" aria-label="Fortification network">${icon('shield')}<div><b>${Math.round(reduction*100)}% network protection</b><p>${count} / 6 neighboring Fortifications protect this base’s buildings.${armor?` Combined with this building’s ${Math.round(armor*100)}% armor: <strong>${total}% less incoming damage</strong>.`:' Monster and champion health are unaffected.'}${base?.status?' This protection is fixed for this battle.':''}</p></div></section>`;
  }

  function panel(building, visiting=false) {
    const status=building.fortify, level=tier(building);
    const network=networkPanel(state.visiting||state.base,building);
    if(!status?.eligible && !state.catalog.buildings[building.type]?.canFortify && !level) return network;
    const active=Number(building.fortify_ready_at)>now(), next=status?.next;
    const firing=!!state.catalog.buildings[building.type]?.damage;
    return `${network}<section class="fortification-panel" aria-label="Building fortification">
      <div class="fortification-heading"><span>${icon('shield')} Fortification</span><b>${level} / 4</b></div>
      <div class="fortification-tiers" aria-label="Fortification tier ${level} of 4">${[1,2,3,4].map(n=>`<span class="${n<=level?'complete':''} ${active&&n===Number(building.fortify_to)?'pending':''}">${n}</span>`).join('')}</div>
      <p>${level?`Incoming damage reduced by <b>${protection(building)}%</b>.`:'Reinforce this building against monster attacks and artillery.'} Health and building level stay separate.</p>
      ${active?`<div class="fortification-progress"><span>Reinforcing to tier ${num(building.fortify_to)}</span><b data-countdown="${Number(building.fortify_ready_at)}">${duration(building.fortify_ready_at-now())}</b></div><p class="subtle-note">${firing?'This tower cannot fire while reinforcement is underway. ':''}${level?'Its completed protection remains active.':'Protection begins when the work finishes.'}</p>`:''}
      ${visiting?'':active?`<button class="button subtle block" data-fortify-action="reviewCancel" data-id="${building.id}">Cancel reinforcement…</button>`:next?`${costHTML(next.cost)}<button class="button primary block" data-fortify-action="fortify" data-id="${building.id}" ${status.canStart||status.available?'':'disabled'}>${icon('shield')}Fortify · ${duration(next.duration)}</button><p class="subtle-note">${status.canStart||status.available?`Tier ${next.tier}: ${Math.round(next.reduction*100)}% less incoming damage. Work does not repair existing damage.`:esc(status.reason||'Refresh your backyard to check availability.')}</p>`:level===4?'<span class="fortification-complete">Maximum reinforcement</span>':'<p class="subtle-note">Refresh your backyard to check reinforcement costs.</p>'}
    </section>`;
  }

  function reset(){cancellation=null;}
  function refresh(){
    if(!cancellation||state.modal!=='fortification-cancel') return;
    const pending=cancellation, building=state.base?.buildings.find(b=>Number(b.id)===pending.id);
    if(state.user?.id===pending.owner&&building?.fortify_ready_at===pending.readyAt) return;
    const finished=state.user?.id===pending.owner&&building&&!building.fortify_ready_at&&tier(building)>pending.level;
    closeModal();cancellation=null;
    if(finished) toast('Reinforcement has finished. The new tier is now active.');
  }
  async function handleClick(el) {
    const name=el.dataset.fortifyAction;
    if(!name) return false;
    if(!['fortify','reviewCancel','cancelFortify'].includes(name)) return true;
    const id=Number(el.dataset.id), owner=state.user?.id;
    if(!Number.isSafeInteger(id)||id<1||!owner||state.visiting||state.battle?.status==='active') return true;
    const building=state.base?.buildings.find(b=>Number(b.id)===id);
    if(!building) return true;
    if(name==='reviewCancel') {
      if(!(building.fortify_ready_at>now())) return true;
      cancellation={id,owner,readyAt:building.fortify_ready_at,level:tier(building)};
      const forecast=building.fortify?.cancelRefund;
      const preview=forecast?`<div class="territory-benefits"><span class="eyebrow">Currently refundable</span><div>${Object.entries(forecast.credited||{}).filter(([,amount])=>Number(amount)>0).map(([key,amount])=>`<span>${icon(key)} ${num(amount)} ${esc({twig:'twigs',pebble:'pebbles',putty:'putty',goo:'goo'}[key]||key)}</span>`).join('')||'<span>No free storage for a refund.</span>'}</div></div><p class="subtle-note">The final refund uses your available storage when cancellation completes.</p>`:'';
      showModal(`<span class="eyebrow">REINFORCEMENT IN PROGRESS</span><h2 id="modal-title">Cancel this reinforcement?</h2><p>Your completed fortification stays at tier ${tier(building)}. The new tier will not be granted. Paid resources return only up to your available storage; any overflow is lost.</p>${preview}<div class="fortification-cancel-actions"><button class="button subtle" data-command="close-modal">Keep reinforcing</button><button class="button danger" data-fortify-action="cancelFortify" data-id="${id}">Cancel reinforcement</button></div>`,'fortification-cancel');
      return true;
    }
    if(name==='cancelFortify'&&(!cancellation||cancellation.id!==id||cancellation.owner!==owner||state.modal!=='fortification-cancel'||cancellation.readyAt!==building.fortify_ready_at)) return true;
    if(name==='fortify'&&!(building.fortify?.canStart||building.fortify?.available)) return true;
    const result=await action(name,{id},el);
    if(!result||state.user?.id!==owner) return true;
    if(name==='cancelFortify') {
      if(state.modal==='fortification-cancel'&&cancellation?.id===id) closeModal();
      cancellation=null;
      const refund=result.refund||{};
      const credited=Object.entries(refund.credited||{}).filter(([,v])=>Number(v)>0).map(([key,v])=>`${num(v)} ${{twig:'twigs',pebble:'pebbles',putty:'putty',goo:'goo'}[key]||key}`).join(', ');
      const overflow=Object.values(refund.overflow||{}).some(v=>Number(v)>0);
      toast(`Reinforcement canceled.${credited?' Returned '+credited+'.':''}${overflow?' Some resources exceeded your storage capacity.':''}`);
    } else {
      toast('Reinforcement started. Your existing protection remains in place.');
      audio.effect('build');
    }
    return true;
  }
  return {panel, networkPanel, handleClick, reset, refresh};
}
