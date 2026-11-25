import {esc,num} from './ui.js?v=0.13.1';

const edges={t:'north',tr:'northeast',r:'east',br:'southeast',b:'south',bl:'southwest',l:'west',tl:'northwest'};
export function siegeBoard(state) {
  const event=state.base.molochSiege,session=state.base.baiter?.session;
  const active=session?.status==='active',resume=active&&session.molochStage===event.stage;
  const names=state.catalog.monsters;
  const roster=Object.entries(event.wave).map(([key,n])=>`<li><img src="assets/portraits/${esc(key)}.webp" alt="" width="36" height="36"><span>${esc(names[key]?.name??key)}</span><strong>×${num(n)}</strong></li>`).join('');
  return `<section class="moloch-siege"><header class="moloch-siege-header"><img src="assets/portraits/moloch-envoy.webp" alt=""><div><span class="eyebrow">HELL-RAISERS · EVENT 3</span><h2 id="modal-title">Moloch’s siege</h2><p>Moloch is leading the Inferno broods against your surface backyard.</p></div></header>
    <div class="stat-line"><span>Waves held</span><strong>${event.cleared} / 10</strong></div><progress value="${event.cleared}" max="10" aria-label="Waves held"></progress>
    ${event.complete?`<h3>Hot and Bothered</h3><p>Moloch has retreated. Build up to two Magma Towers in your main yard and in each captured Frontier outpost.</p>`:`<h3>Wave ${num(event.stage)}</h3><p>Approaches from the ${event.edges.map(e=>edges[e]).join(', ')}.</p><ul class="moloch-wave-roster">${roster}</ul><p>Repel all attackers before time expires with less than 90% damage to your yard. Hold ten waves to unlock two Magma Towers.</p>${event.reason?`<p>${esc(event.reason)}</p>`:''}<button class="button primary" data-baiter="${resume?'blitz-resume':'moloch-start'}" ${(!resume&&active)||!event.eligible?'disabled':''}>${resume?'Resume defense':active?'Another defense is active':'Defend wave '+num(event.stage)}</button>`}
    ${event.last?`<p class="subtle-note">Last attack · Wave ${num(event.last.stage)} · ${esc(event.last.outcome)} · ${num(event.last.damage)}% yard damage</p>`:''}</section>`;
}
export function siegeResult(battle) {
  if(!battle.molochStage)return '';
  if(battle.result?.outcome==='held')return `<p>${battle.molochStage===10?'All ten Hell-Raisers waves held. Your main yard and Frontier outposts can each build two Magma Towers.':'Hell-Raisers wave '+num(battle.molochStage)+' held. The next wave is open.'}</p>`;
  return '<p>Wave progress stays at the last defense you held. Return home and use Repair Base to restore your buildings for free before retrying.</p>';
}
