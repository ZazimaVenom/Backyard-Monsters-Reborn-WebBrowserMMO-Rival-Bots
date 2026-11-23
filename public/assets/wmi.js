import {esc, num} from './ui.js?v=0.13.0';

const awards = [1,10,20,30,31,32];
const sides = {t:'north',r:'east',b:'south',l:'west'};
const title = event => 'Wild Monster Invasion '+(Number(event)===1?'I':'II');
export function wmiTitle(battle) { return title(battle.wmiEvent)+' · Wave '+num(battle.wmiStage); }

export function wmiBoard(state, selected) {
  const records=state.base?.wmi;
  if (!records) return '';
  const event=Number(selected)===2?2:1, run=records[event], session=state.base.baiter?.session;
  const active=session?.status==='active', matching=active && session.wmiEvent===event;
  const stage=run.stage, best=run.tier, groups=run.nextGroups;
  const selection=[1,2].map(key=>`<button class="button ${event===key?'primary':'subtle'}" data-baiter="wmi" data-wmi-event="${key}" aria-pressed="${event===key}">Invasion ${key===1?'I':'II'}</button>`).join('');
  const tiers=awards.map((wave,i)=>`<li class="${i<best?'earned':''}"><span>${num(wave)}</span><small>Tier ${i+1}</small></li>`).join('');
  const chips=groups.map((group,i)=>{
    const species=state.catalog.monsters[group.type]||state.catalog.champions?.[group.type];
    const name=species?.name||group.type;
    const details=[`Level ${group.level}`,group.guardianHP?'Champion':'',group.powerup?`Power ${group.powerup}`:'',group.rage?`Enraged ${group.rage}s`:''].filter(Boolean);
    return `<li><strong>${num(group.count)} × ${esc(name)}</strong><span>${esc(details.join(' · '))}</span><small>${i===0?'Opening group':group.delay?'After '+num(group.delay)+'s':'Next group'} · ${sides[group.edge]}</small></li>`;
  }).join('');
  const copy=event===1?'Surface packs and wild champions advance on your yard.':'Inferno strains break through to the surface.';
  const time=run.deadline?`<p>${run.restart?(run.cleared===32?'All 32 waves cleared.':'Your seven-day run has ended.'):'This run ends '+esc(new Date(run.deadline*1000).toLocaleString())+'.'}${run.restart?' Starting again keeps your highest totem tier.':''}</p>`:'<p>The seven-day clock starts when you defend the first wave.</p>';
  const button=matching?'<button class="button primary" data-baiter="blitz-resume">Resume wave '+num(session.wmiStage)+'</button>':active?'<button class="button subtle" data-baiter="blitz-resume">Resume current defense</button>':`<button class="button primary" data-baiter="wmi-start" data-wmi-event="${event}">${run.restart?'Replay from wave 1':'Defend wave '+num(stage)}</button>`;
  return `<section class="wmi-board"><header><img src="assets/decorations/${run.item}.webp" width="96" height="104" alt="Victory totem"><div><h2 id="modal-title">${title(event)}</h2><p>${copy}</p><nav aria-label="Choose an invasion">${selection}</nav></div></header><p><b>${num(run.cleared)} of 32 waves cleared</b> · ${best?'Victory totem tier '+best+' of 6':'No victory totem earned yet'}</p><progress max="32" value="${run.cleared}" aria-label="Waves cleared"></progress><ol class="wmi-tiers" aria-label="Totem milestones">${tiers}</ol>${time}<h3>${stage>30?'Bonus wave '+(stage-30):'Wave '+stage}</h3><p>Defeat every group and keep yard damage below 90%. Approaches rotate together when the wave starts. Repair your base for free between waves.</p><ol class="wmi-packs">${chips}</ol><footer>${button}<button class="button subtle" data-baiter="season">Yard events</button></footer></section>`;
}

export function wmiResult(battle,events) {
  const w=battle.wmiStage,event=battle.wmiEvent,held=battle.result?.outcome==='held';
  if (!w) return '';
  const tier=awards.indexOf(w)+1, earned=held && tier;
  const text=earned?(tier===1?'Victory totem earned. Place it from Decorations → Storage.':`Victory totem milestone · Tier ${tier} of 6.`):held?'Wave cleared.':'This wave is available to retry.';
  return `<p><b>${wmiTitle(battle)}</b><br>${text}${events?.[event]?.tier>tier && earned?' Your higher totem tier is preserved.':''}</p><p>Damage carries back to your home yard. Use Repair Base to restore your buildings for free.</p>`;
}
