import {esc, icon, num} from './ui.js?v=0.13.1';

const sections = ['Rewards ready','Getting started','Construction','Monsters','Attacking','Harvests','Juicer','Foraging','Chaos workshop','Cavern buildings','Cavern monsters','Cavern harvests','Neighbors','Achievements'];
const symbols = ['collect','book','build','monster','swords','leaf','flask','leaf','build','build','monster','collect','mail','trophy'];
const resourceNames = {twig:'twigs',pebble:'pebbles',putty:'putty',goo:'goo',housing:'housing space',bone:'bones',coal:'coal',sulfur:'sulfur',magma:'magma'};
const stateLabels = {ready:'Reward ready', claimed:'Collected', earned:'Achievement earned', active:'In progress'};

export function questRows(entries, section) {
  return entries.filter(entry => section === 'Rewards ready' ? entry.status === 'ready' : entry.group === section)
    .sort((a,b) => ['ready','active','earned','claimed'].indexOf(a.status) - ['ready','active','earned','claimed'].indexOf(b.status));
}

function rewardLabels(entry, catalog) {
  const parts = Object.entries(entry.resources).map(([type,amount]) =>
    `<span title="${esc(resourceNames[type])}">${icon(['bone','magma','coal','sulfur'].includes(type)?'collect':type)}${num(amount)} ${esc(resourceNames[type])}</span>`);
  for (const [type,count] of Object.entries(entry.monsters)) {
    const name = catalog?.monsters?.[type]?.name || type;
    parts.push(`<span>${icon('monster')}${num(count)} × ${esc(name)}</span>`);
  }
  if (entry.shiny) { parts.push(`<span>${icon('trophy')}${num(entry.shiny)} Shiny</span>`); }
  if (entry.device) { const name=({decoy:'Marilyn Monstroe',vacuum:'Loot-O-Tron 5000',jars:'Candy Jars'})[entry.device]; parts.push(`<span>${icon('catapult')}1 × ${esc(name)}</span>`); }
  return parts.join('');
}

export function questCard(entry, catalog, {busy=false,battle=false}={}) {
  const complete = entry.status !== 'active', ready = entry.status === 'ready';
  const progress = Math.max(0,Math.min(entry.target,entry.progress));
  const missing = Object.entries(entry.missing).map(([type,count]) => `${num(count)} ${resourceNames[type] || type}`);
  const disabled = busy || battle || !!entry.factory || missing.length > 0;
  const note = ready && (battle ? 'Finish the invasion to collect this reward.' : (entry.factory || '') || (missing.length ? `Space needed: ${missing.join(', ')}. Your reward stays here.` : ''));
  const award = rewardLabels(entry, catalog);
  return `<article class="quest-card" data-status="${esc(entry.status)}">
    <header><h3>${esc(entry.title)}</h3><span class="quest-status">${icon(complete ? 'check' : 'clock')}${esc(stateLabels[entry.status])}</span></header>
    <p>${esc(entry.instruction)}</p>${entry.destination==='inferno'?'<small class="quest-realm">Reward destination · Inferno cavern</small>':''}
    ${!complete ? `<div class="quest-progress"><progress max="${Number(entry.target)}" value="${progress}" aria-label="${esc(entry.title)} progress"></progress><span>${num(progress)} / ${num(entry.target)}</span></div>` : ''}
    ${award ? `<footer><div class="quest-award" aria-label="Reward">${award}</div>${ready ? `<button type="button" class="button primary small" data-quest-claim="${esc(entry.id)}" aria-label="Collect reward for ${esc(entry.title)}" ${disabled ? 'disabled' : ''}>Collect</button>` : ''}</footer>` : ''}
    ${note ? `<small class="quest-space">${esc(note)}</small>` : ''}
  </article>`;
}

export function createQuests({state,showModal,action,toast,audio,synchronizeHome,refreshCavern}) {
  let owner = null, section = 'Getting started', last = '', pending = null;

  function referralLink(token) {
    if (!token) return '';
    const url=new URL(location.href);url.hash='';url.search='';url.searchParams.set('invite',token);return url.href;
  }

  function renderJournal() {
    const journal = state.base?.quests, entries = journal?.entries || [];
    const counts = journal?.counts || {};
    const tabs = sections.map((name,index) => {
      const count = name === 'Rewards ready' ? counts.ready || 0 : entries.filter(e => e.group === name).length;
      return `<button type="button" class="quest-tab" aria-pressed="${section === name}" data-quest-tab="${esc(name)}">${icon(symbols[index])}<span>${esc(name)}</span><small>${num(count)}</small></button>`;
    }).join('');
    const invite = section==='Neighbors' ? referralLink(journal?.invite) : '';
    const visible = questRows(entries,section);
    const cards = visible.map(e => questCard(e,state.catalog,{busy:!!pending,battle:!!state.activeBattle || state.battle?.status==='active'})).join('');
    return `<header class="quest-hero"><div><h2 id="modal-title">Quest journal</h2>
      <p><b>${num(counts.completed)}</b> of ${num(entries.length)} objectives complete</p>
      <progress max="${entries.length || 1}" value="${counts.completed || 0}" aria-label="Overall quest progress"></progress></div>
      <img src="assets/art/quest-keeper.webp" width="112" height="112" alt=""></header>
      <div class="quest-layout"><nav class="quest-tabs" aria-label="Quest sections">${tabs}</nav>
      <section class="quest-list" aria-label="${esc(section)}"><h3 tabindex="-1" data-quest-heading>${esc(section)}</h3>
      ${invite ? `<div class="quest-referral"><label for="quest-invite">Invite a new keeper</label><p>A new account registered through this link counts toward your invitation quests.</p><div><input id="quest-invite" type="url" readonly value="${esc(invite)}"><button type="button" class="button subtle small" data-quest-copy>Copy link</button></div></div>` : ''}
      ${cards || '<p class="quest-empty">Your next reward will appear here when you complete a quest. Choose a section to see your objectives.</p>'}</section></div>`;
  }

  function refresh() {
    const count = document.querySelector('#quest-ready-count');
    const ready = state.user ? state.base?.quests?.counts?.ready || 0 : 0;
    if (count) { count.hidden = !ready; count.textContent = num(ready); count.setAttribute('aria-label', `${num(ready)} rewards ready`); }
    if (owner !== state.user?.id) {
      owner = state.user?.id;
      section = 'Getting started';
      last = ''; pending = null;
    }
    if (state.modal !== 'quests' || !owner) { return; }
    const root = document.querySelector('#quest-journal');
    if (!root) { return; }
    const content = renderJournal();
    if (last === content) { return; }
    const focus = root.contains(document.activeElement) ? document.activeElement.dataset : null;
    root.innerHTML = content; last = content;
    if (focus) {
      const selector = focus.questClaim ? `[data-quest-claim="${CSS.escape(focus.questClaim)}"]:not(:disabled)` :
        focus.questTab ? `[data-quest-tab="${CSS.escape(focus.questTab)}"]` : '[data-quest-heading]';
      (root.querySelector(selector) || root.querySelector('[data-quest-heading]'))?.focus({preventScroll:true});
    }
  }

  function open(preferred) {
    if (!state.user || !state.base?.quests) { return; }
    if (owner !== state.user.id) { section = 'Getting started'; owner = state.user.id; }
    if (sections.includes(preferred)) section=preferred;
    last = renderJournal();
    showModal(`<div id="quest-journal">${last}</div>`, 'quests');
  }

  async function handleClick(el) {
    if ('questOpen' in el.dataset) {
      open(el.dataset.questOpen);
      if (state.modal==='quests') {
        try { await synchronizeHome?.(); } catch (error) { toast(error.message,true); }
      }
      return true;
    }
    if (state.modal !== 'quests' || !state.user) { return false; }
    if ('questCopy' in el.dataset) {
      const field=document.querySelector('#quest-invite');
      if (field) {
        try { await navigator.clipboard.writeText(field.value); toast('Invitation link copied.'); }
        catch { field.focus();field.select();toast('Invitation link selected. Copy it to share.'); }
      }
      return true;
    }
    if (el.dataset.questTab && sections.includes(el.dataset.questTab)) {
      section = el.dataset.questTab; refresh(); return true;
    }
    if (!el.dataset.questClaim) { return false; }
    if (pending) { return true; }
    const entry = state.base?.quests?.entries?.find(item => item.id === el.dataset.questClaim);
    if (entry?.status !== 'ready' || entry.factory || Object.keys(entry.missing).length || state.activeBattle || state.battle?.status === 'active') { return true; }
    const token = {owner:state.user.id, modal:state.modalEpoch};
    const current = () => state.user?.id === token.owner && token.modal === state.modalEpoch && state.modal === 'quests';
    pending = token; refresh();
    try {
      const data = await action('questClaim',{quest:entry.id});
      if (data?.questClaim?.destination==='inferno' && state.visiting?.infernoHome) await refreshCavern?.();
      if (current() && data?.questClaim) {
        if (data.questClaim.alreadyClaimed) { toast('You have already collected this reward.'); }
        else { audio.effect('quest'); toast(`Reward collected for ${entry.title}.`); }
      }
    } finally {
      if (pending === token) { pending = null; refresh(); }
    }
    return true;
  }

  return {refresh, handleClick};
}
