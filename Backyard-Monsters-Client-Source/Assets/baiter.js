import {esc, icon, num} from './ui.js?v=0.13.1';

import {siegeBoard,siegeResult} from './moloch-siege.js?v=0.13.1';
import {emergenceBoard, emergenceDebrief} from './emergence.js?v=0.13.1';
import {wmiBoard, wmiTitle, wmiResult} from './wmi.js?v=0.13.1';

const approaches = {
  tl: ['↘', 'Upper left'], t: ['↓', 'Top'], tr: ['↙', 'Upper right'],
  l: ['→', 'Left'], r: ['←', 'Right'],
  bl: ['↗', 'Lower left'], b: ['↑', 'Bottom'], br: ['↖', 'Lower right'],
};
const $ = selector => document.querySelector(selector);
const nameOf = key => approaches[key]?.[1] || 'Yard edge';
const countWave = wave => Object.values(wave).reduce((sum, amount) => sum + amount, 0);

export function createBaiter({state, api, art, audio, showModal, closeModal, present, leave, toast, isPlanning, prepareEventYard, seasonAction, raisePortal, tremor}) {
  let wave = {}, direction = 'tl', draftOwner = null, retry = null;
  let generation = 0, pending = null, polling = null, sessionId = null, retryAfter = 0;
  const options = () => state.base?.baiter;
  const atHome = () => Boolean(state.user && state.base?.owner?.id === state.user.id && state.page === 'yard'
    && !state.visiting && !state.worldSite && !state.battle && !state.activeBattle && !isPlanning());
  const busy = () => pending !== null;
  const cost = () => Object.entries(wave).reduce((sum, [key, qty]) => sum + qty * Number(options()?.costs?.[key] || 0), 0);
  const capacity = () => Number(options()?.capacity || 0);
  const maxUnits = () => Number(options()?.maxUnits || 0);
  const valid = () => countWave(wave) > 0 && cost() <= capacity() && countWave(wave) <= maxUnits()
    && options()?.directions?.includes(direction);

  function buildingButton() {
    const config = options();
    if (!atHome() || !config) return '';
    if (config.session?.status === 'active' && config.session.horse) return '<button class="button primary block" data-baiter="resume">Resume Trojan Horse defense</button>';
    if (config.session?.status === 'active') return `<button class="button primary block" data-baiter="resume">${icon('eye')}Resume defense drill</button>`;
    const latest = config.session?.status === 'finished' && !config.session.molochStage && !config.session.emergenceStage && !config.session.horse && !config.session.wmiEvent && !config.session.blitzStage && !config.session.brukkargStage ? `<button class="button subtle block" data-baiter="review">${icon('scroll')}Last drill result</button>` : '';
    return `<button class="button primary block" data-baiter="configure" ${config.available ? '' : 'disabled'}>${icon('shield')}Set up defense drill</button>${config.available ? '' : `<p class="subtle-note">${esc(config.reason)}</p>`}${latest}`;
  }
  function storageKey() {
    return `bym-baiter:${location.pathname.replace(/[^/]*$/, '')}:${state.user.id}`;
  }
  function saveDraft() {
    try { localStorage.setItem(storageKey(), JSON.stringify({wave,direction})); } catch { /* Storage can be disabled for this browser. */ }
  }
  function restoreDraft() {
    const config = options();
    wave = {}; direction = config.directions[0];
    let last;
    try { last = JSON.parse(localStorage.getItem(storageKey())); } catch { return; }
    if (config.directions.includes(last?.direction)) direction = last.direction;
    for (const key of Object.keys(config.costs)) {
      const qty = last?.wave?.[key];
      if (Number.isSafeInteger(qty) && qty > 0) {
        const restored = Math.min(qty,maxFor(key));
        if (restored) wave[key] = restored;
      }
    }
  }
  function maxFor(key) {
    const unitCost = Number(options()?.costs?.[key]);
    if (!unitCost) return 0;
    return Math.max(0, (wave[key] || 0) + Math.min(maxUnits() - countWave(wave), Math.floor((capacity() - cost()) / unitCost)));
  }
  function edit(key, amount) {
    if (busy() || state.modal !== 'baiter' || !atHome() || !Object.hasOwn(options()?.costs || {}, key)) return;
    const value = Math.min(maxFor(key), Math.max(0, Math.trunc(Number(amount) || 0)));
    if (value > 0) wave[key] = value; else delete wave[key];
    saveDraft(); refreshEditor();
  }
  function refreshEditor() {
    if (state.modal !== 'baiter') return;
    const total = countWave(wave), used = cost();
    $('#baiter-totals').textContent = `${num(used)} / ${num(capacity())} musk · ${num(total)} / ${num(maxUnits())} monsters`;
    $('#baiter-clear').disabled = busy() || !total;
    $('#baiter-meter').value = used;
    $('#baiter-meter').max = Math.max(1, capacity());
    for (const input of document.querySelectorAll('[data-baiter-qty]')) {
      const key = input.dataset.baiterQty;
      input.max = maxFor(key);
      input.value = wave[key] || 0;
      input.disabled = busy();
      const card = input.closest('.baiter-species');
      card.classList.toggle('chosen', Boolean(wave[key]));
      card.querySelector('[data-baiter="less"]').disabled = busy() || !wave[key];
      card.querySelector('[data-baiter="more"]').disabled = busy() || (wave[key] || 0) >= maxFor(key);
    }
    for (const button of document.querySelectorAll('[data-baiter="direction"]')) {
      button.setAttribute('aria-pressed', String(button.dataset.edge === direction));
      button.disabled = busy();
    }
    const start = $('#baiter-launch');
    start.disabled = busy() || !options()?.available || !valid() || !atHome();
    start.textContent = busy() ? 'Calling the wave…' : 'Call wild monsters';
  }
  function configure() {
    if (!atHome() || !options()?.available || busy()) return;
    if (options().session?.status === 'active') { void resume(); return; }
    const config = options();
    if (draftOwner !== state.user.id) { restoreDraft(); draftOwner = state.user.id; }
    if (!config.directions.includes(direction)) direction = config.directions[0];
    const cards = Object.entries(config.costs).map(([key, musk]) => {
      const name = esc(state.catalog.monsters[key]?.name || key);
      return `<div class="baiter-species">${art(key,'')}<div class="baiter-species-info"><b>${name}</b><small>${num(musk)} musk</small></div><div class="baiter-stepper"><button type="button" data-baiter="less" data-species="${esc(key)}" aria-label="Remove one ${name}">−</button><input type="number" inputmode="numeric" min="0" step="1" data-baiter-qty="${esc(key)}" aria-label="${name} count"><button type="button" data-baiter="more" data-species="${esc(key)}" aria-label="Add one ${name}">+</button></div></div>`;
    }).join('');
    const edges = Object.entries(approaches).map(([key,[arrow,label]]) => config.directions.includes(key)
      ? `<button type="button" data-baiter="direction" data-edge="${key}" style="grid-area:${key}" aria-label="Approach from ${label}" title="${label}"><span aria-hidden="true">${arrow}</span><small>${label}</small></button>` : '').join('');
    showModal(`<section class="baiter-editor"><header class="baiter-intro">${art('baiter','')}<div><h2 id="modal-title">Wild Monster Baiter</h2><p>Choose a wild pack to test a copy of your defenses. Your home, army and rank are safe.</p></div></header><div class="baiter-config"><section><div class="baiter-pack-heading"><h3>Wild pack</h3><button type="button" class="button subtle small" id="baiter-clear" data-baiter="clear">Clear wave</button></div><div class="baiter-species-grid">${cards}</div></section><section class="baiter-approach"><h3>Approach</h3><div class="baiter-compass" role="group" aria-label="Entry direction">${edges}<span class="baiter-center">${icon('home')}</span></div>${config.directions.length < 8 ? '<small>Four more approaches unlock at Baiter level 3.</small>' : ''}</section></div><footer class="baiter-footer"><div><b id="baiter-totals" aria-live="polite"></b><progress id="baiter-meter" aria-label="Musk used"></progress></div><button type="button" class="button primary" data-baiter="launch" id="baiter-launch"></button></footer></section>`,'baiter');
    refreshEditor();
  }
  function context() { return {owner:state.user?.id, navigation:state.navigationEpoch, combat:state.battleEpoch, generation}; }
  function current(token) {
    return token.owner === state.user?.id && token.navigation === state.navigationEpoch && token.combat === state.battleEpoch && token.generation === generation && !state.activeBattle;
  }
  function accept(data, entering) {
    if (!data?.session?.id || data.battle?.training !== true || data.battle.defender?.id !== state.user?.id) return false;
    if (!entering && data.session.id !== sessionId) return false;
    const known = options()?.session;
    if (known?.id === data.session.id && Number(known.revision) > Number(data.session.revision)) return false;
    sessionId = data.session.id;
    if (options()) options().session = data.session;
    if (entering || data.battle.status !== state.battle?.status) state.snapshotEpoch++;
    if(data.molochSiege)state.base.molochSiege=data.molochSiege;
    if (data.emergence) state.base.emergence=data.emergence;
    if (data.wmi) state.base.wmi=data.wmi;
    if (data.blitz) state.base.blitz = data.blitz;
    if (entering && ['baiter','blitz','wmi','emergence','season','horse-note'].includes(state.modal)) closeModal();
    present(data.battle, entering);
    return true;
  }
  const seasonNames = {27:['Horsey','horsey'],53:['Pumpkin','eventpumpkin'],54:['Giant Pumpkin','giantpumpkin']};
  let seasonSelection = null, seasonFingerprint = '';
  let emergenceFingerprint='';
  function showEmergence() {
    if (!atHome() || !state.base.emergence) return;
    emergenceFingerprint=JSON.stringify(state.base.emergence);
    showModal(emergenceBoard(state),'emergence');
  }
  function refreshSeason() {
    if (state.modal==='emergence' && !busy() && JSON.stringify(state.base?.emergence)!==emergenceFingerprint) showEmergence();
    if (state.modal !== 'season') return;
    const data = state.visiting?.seasonal || state.base?.seasonal;
    if (!data || JSON.stringify(data)===seasonFingerprint) return;
    showSeason((data.objects || []).find(row=>row.id===seasonSelection) || null, true);
  }
  function eventDirectory() {
    const hunts = [
      ['data-champion-event="krallen"', 'King of the Hill', 'Weekly raid loot · Krallen', 'krallen-p1'],
      ['data-champion-event="madness"', 'Monster Madness', 'Outpost captures · Korath', 'korath-e1'],
      ['data-baiter="blitz"', 'Monster Blitzkrieg', 'Ten defensive waves · Slimeattikus', 'slimeattikus'],
      ['data-tribes-open="carnage"', 'Creature Carnage', 'Ten enemy yards · Vorg', 'vorg'],
      ['data-tribes-open="brukkarg"', 'Brukkarg War', 'Defenses and expeditions · Spurtz Cannon', 'spurtzcannon'],
      ['data-tribes-open="hellraisers"', 'Hell Raisers', 'Moloch raids · event shop', 'rezghul'],
    ];
    if (state.base?.emergence) hunts.push(['data-baiter="emergence"', 'Inferno Emergence', 'Five earthquakes · Skull of Doom', 'spurtz']);
    if(state.base?.molochSiege)hunts.push(['data-baiter="moloch"','Hell-Raisers · Moloch’s siege',state.base.molochSiege.cleared+' / 10 defenses held','moloch-envoy']);
    if (state.base?.wmi) hunts.push(['data-baiter="wmi"', 'Wild Monster Invasions', 'Two campaigns · 32 waves each', 'baiter']);
    return `<button type="button" class="button primary" data-calendar-open>World event calendar</button><details class="yard-event-directory"><summary>Campaigns and weekly hunts</summary><nav aria-label="Event selection">${hunts.map(([command,name,detail,key]) =>
      `<button type="button" ${command}>${art(key,'')}<span><strong>${name}</strong><small>${detail}</small></span>${icon('chevron')}</button>`).join('')}</nav></details>`;
  }
  function showSeason(item = null, refresh = false) {
    const visit = state.visiting && !state.battle;
    const data = visit ? state.visiting.seasonal : state.base?.seasonal;
    if (!data || (!visit && !atHome())) return;
    seasonSelection = item?.id || null;
    seasonFingerprint = JSON.stringify(data);
    const objects = item ? (data.objects || []).filter(row => row.id === item.id) : (data.objects || []);
    const cards = objects.map(row => {
      const type = Number(row.type), info = seasonNames[type];
      if (!info) return '';
      let button='';
      if (!visit) {
        const locked = row.readyAt>0 || (type===54 && data.bagClaimed);
        const op = type===27 ? 'horse' : type===54 ? 'giantOpen' : 'pumpkinPick';
        const label = type===27 ? 'Read the attached note' : type===54 ? (data.bagClaimed?'Goodie bag claimed':'Open goodie bag') : (locked?'Worker gathering':'Send a worker');
        button=`<button type="button" class="button subtle small" data-baiter="season-act" data-season-op="${op}" data-season-id="${Number(row.id)}" ${locked?'disabled':''}>${label}</button>`;
      }
      return `<article class="season-card">${art(info[1],'')}<div><b>${info[0]}</b><small>Yard position ${num(row.x)}, ${num(row.y)}</small></div>${button}</article>`;
    }).join('');
    const active = options()?.session?.status === 'active' && options()?.session?.horse;
    const horse = data.horse || {};
    const prior = data.lastPick ? `<p>Last harvest · ${esc(data.lastPick.kind)}${data.lastPick.amount ? ' · '+num(data.lastPick.amount)+' '+esc(data.lastPick.resource || '') : ''}</p>` : '';
    const replay = !visit && !data.replayUsed && !data.calendarActive && !data.replayActive
      ? '<button type="button" class="button subtle" data-baiter="season-act" data-season-op="halloweenReplay">Replay Halloween</button>' : '';
    const claimed = !visit && data.bagClaimed ? '<p>This year’s giant pumpkin goodie bag has been claimed.</p>' : '';
    const review = !visit && options()?.session?.horse && options().session.status==='finished'
      ? '<button class="button subtle" data-baiter="horse-review">Review Trojan Horse defense</button>' : '';
    const detail = visit ? '<p>Only this yard’s keeper can open these gifts or send a worker.</p>'
      : `<p>${data.calendarActive?'Halloween is active.':data.replayActive?'Halloween replay is active.':data.replayUsed?'This year’s Halloween replay has ended.':'Gather pumpkins during Halloween or start a local replay.'}</p>`;
    const content = `<section class="blitz-dialog season-dialog"><h2 id="modal-title">Yard events</h2>${!visit && !item ? eventDirectory() : ''}${detail}${cards || '<p>No event objects are standing in this yard.</p>'}${!visit?prior:''}${claimed}${replay}${review}${active?'<button type="button" class="button primary" data-baiter="blitz-resume">Resume Trojan Horse defense</button>':''}${!visit && !item && Number.isFinite(horse.level)?`<p>Gift horse · ${horse.opened?'opened':horse.encountered?'in the yard':'arrives at keeper level 9 with over 2,000,000 yard points. Your yard is level '+num(horse.level)+' with '+num(horse.points||0)+' points'}</p>`:''}${item && !visit?'<button class="button subtle" data-baiter="season">All yard events</button>':''}</section>`;
    if (refresh) {
      const directory = $('#modal-body').querySelector?.('.yard-event-directory');
      const directoryOpen = directory?.open;
      const directoryFocus = directory?.contains(document.activeElement)
        ? [...directory.querySelectorAll('summary,button')].indexOf(document.activeElement) : -1;
      const currentButton=document.activeElement?.closest('[data-season-op], [data-baiter]');
      const focus=currentButton && {op:currentButton.dataset.seasonOp,id:currentButton.dataset.seasonId,cmd:currentButton.dataset.baiter};
      $('#modal-body').innerHTML=content;
      if (directoryOpen || directoryFocus >= 0) {
        const replacement = $('#modal-body').querySelector('.yard-event-directory');
        if (replacement) {
          replacement.open = Boolean(directoryOpen);
          if (directoryFocus >= 0) replacement.querySelectorAll('summary,button')[directoryFocus]?.focus({preventScroll:true});
        }
      }
      if (focus && directoryFocus < 0) {
        const next=[...$('#modal-body').querySelectorAll('button')].find(b=>b.dataset.seasonOp===focus.op&&b.dataset.seasonId===focus.id&&b.dataset.baiter===focus.cmd);
        (next && !next.disabled ? next : $('#modal-close'))?.focus({preventScroll:true});
      }
    } else {
      showModal(content,'season');
      if (!visit && !item) $('#modal-body')?.querySelector?.('.yard-event-directory>summary')?.focus({preventScroll:true});
    }
  }
  async function seasonClick(el) {
    if (!atHome() || !['season','horse-note'].includes(state.modal) || busy()) return;
    const op=el.dataset.seasonOp,id=Number(el.dataset.seasonId), owner=state.user.id;
    if (op==='horse' && state.modal==='season') {
      showModal('<section class="blitz-dialog"><header class="baiter-intro">'+art('horsey','Wooden gift horse')+
        '<div><h2 id="modal-title">A gift from the wild monsters</h2><p>A note tied to the wooden horse offers a truce. Opening it starts a one-time defense in your home yard.</p></div></header>'+
        '<p>Your towers will fight automatically. Damage to your buildings will remain after the defense.</p>'+
        '<div class="button-row"><button class="button primary" data-baiter="season-act" data-season-op="horse-open">Open the gift horse</button>'+
        '<button class="button subtle" data-baiter="season">Leave it closed</button></div></section>','horse-note');
      return;
    }
    if (op==='horse-open' && state.modal==='horse-note') {
      const token=context(), modal=state.modalEpoch, previousId=options()?.session?.id||null;
      const signature=JSON.stringify(['horse',owner,previousId]);
      if (retry?.signature!==signature) {
        retry={signature,id:Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('')};
      }
      pending=token;
      try {
        const data=await api('horseStart',{previousId,requestId:retry.id});
        if (current(token)&&state.modal==='horse-note'&&state.modalEpoch===modal&&accept(data,true)) {retry=null;audio.effect('baiter');}
      } catch(e) {if(current(token)) toast(e.message,true);}
      finally {if(pending===token)pending=null;}
      return;
    }
    if (!['halloweenReplay','pumpkinPick','giantOpen'].includes(op) || !seasonAction) return;
    const modal = state.modalEpoch;
    const data = await seasonAction({op,...(id>0?{id}:{})},el);
    if (!data || owner!==state.user?.id || state.modalEpoch!==modal || state.modal!=='season') return;
    const reward = data.seasonResult;
    if (reward?.kind==='bag') {
      const pieces=Object.values(reward.decorations || {}).reduce((sum,n)=>sum+(Number(n)||0),0);
      toast(reward.alreadyClaimed?'This year’s bag has already been claimed.':`Goodie bag · ${num(reward.shiny||0)} Shiny${pieces?' and '+num(pieces)+' decorations':''}.`);
    } else if(reward?.kind==='picking') toast('A worker is gathering this pumpkin.');
    showSeason();
  }
  function showBlitz() {
    const event = state.base?.blitz;
    if (!atHome() || !event) return;
    const stage = event.restart ? 1 : Math.min(event.total, event.cleared + 1);
    const session = options()?.session;
    const active = session?.status === 'active';
    const groups = event.rosters[stage - 1] || {};
    const roster = Object.entries(groups).map(([type, qty]) =>
      `<li>${art(type,'')}<span>${esc(state.catalog.monsters[type]?.name || type)}</span><b>×${num(qty)}</b></li>`).join('');
    const startButton = active ? '<button class="button primary" data-baiter="blitz-resume">Resume active defense</button>'
      : (event.eligible || event.restart) ? `<button class="button primary" data-baiter="blitz-start">${event.restart ? 'Restart at wave 1' : 'Defend wave ' + stage}</button>` : '';
    const timeLeft = event.deadline > 0 && event.cleared < event.total ? `<p>Run ends ${esc(new Date(event.deadline*1000).toLocaleString())}.</p>` : '';
    showModal(`<section class="blitz-dialog"><header><div>${art('slimeattikus','Slimeattikus')}</div><h2 id="modal-title">Monster Blitzkrieg</h2><p>Hold ten waves to unlock Slimeattikus at your Hatchery.</p></header><progress value="${event.cleared}" max="${event.total}" aria-label="Cleared waves"></progress><p>${num(event.cleared)} of ${num(event.total)} waves cleared</p>${event.cleared < event.total ? `<h3>Wave ${stage} · northern approach</h3><ul class="baiter-roster">${roster}</ul><p>Keep damage below 90%. Your buildings take real damage. Repair them for free between waves. The first wave starts a six-day run.</p>` : ''}${event.reason ? `<p>${esc(event.reason)}</p>` : ''}${timeLeft}${startButton}</section>`,'blitz');
  }
  async function investigate(el) {
    if (!atHome() || busy() || state.modal!=='emergence') return;
    const e=state.base.emergence;
    if (!e?.ready || e.pending) return;
    const token=context(), modalEpoch=state.modalEpoch;pending=token;
    try {
      const data=await raisePortal({previousStage:e.stage},el);
      if (!data || !current(token) || state.modal!=='emergence' || modalEpoch!==state.modalEpoch || !atHome()) return;
      closeModal();const closed=state.modalEpoch;
      await tremor(data.base.emergence.stage);
      if (current(token) && atHome() && !state.modal && closed===state.modalEpoch) showEmergence();
    } finally { if (pending===token) pending=null; }
  }
  async function startBlitz(wmiEvent=0,emergence=false,moloch=false) {
    const modal=moloch?'moloch':emergence?'emergence':wmiEvent?'wmi':'blitz';
    const event=moloch?state.base?.molochSiege:emergence?state.base?.emergence:wmiEvent?state.base?.wmi?.[wmiEvent]:state.base?.blitz;
    if (!atHome() || state.modal!==modal || busy() || !event || (emergence ? !(event.pending && event.ready) : !wmiEvent && !(event.eligible || event.restart))) return;
    const stage=moloch||emergence||wmiEvent?event.stage:event.restart?1:event.cleared+1;
    const previousId = options()?.session?.id || null;
    const signature = JSON.stringify([modal,wmiEvent,event.run,state.user.id,stage,previousId]);
    if (retry?.signature !== signature) {
      const bytes = crypto.getRandomValues(new Uint8Array(16));
      retry = {signature,id:Array.from(bytes, n=>n.toString(16).padStart(2,'0')).join('')};
    }
    const token = context(), modalEpoch = state.modalEpoch; pending = token;
    try {
      const input={stage,previousId,requestId:retry.id};
      if (wmiEvent) Object.assign(input,{event:wmiEvent,run:event.run});
      const data=await api(moloch?'molochStart':emergence?'emergenceStart':wmiEvent?'wmiStart':'blitzStart',input);
      if (current(token) && state.modal === modal && state.modalEpoch === modalEpoch && atHome() && accept(data,true)) {
        retry = null; audio.effect('baiter');
      }
    } catch (error) { if (current(token)) toast(error.message,true); }
    finally { if (pending === token) pending = null; }
  }
  async function startBrukkarg(stage, button) {
    if (busy() || !state.user || state.modal !== 'tribes' || state.activeBattle || state.battle?.status === 'active' || !Number.isInteger(stage)) return;
    const session = options()?.session, previousId = session?.id || null;
    if (session?.status === 'active' && session.brukkargStage !== stage) {
      toast('Resume your active defense at home before starting Brukkarg War.', true); return;
    }
    const token = context(), modalEpoch = state.modalEpoch;
    const signature = JSON.stringify(['brukkarg', state.user.id, stage, previousId]);
    if (retry?.signature !== signature) {
      const bytes = crypto.getRandomValues(new Uint8Array(16));
      retry = {signature, id: Array.from(bytes, n => n.toString(16).padStart(2,'0')).join('')};
    }
    pending = token; button.disabled = true;
    try {
      const data = session?.status === 'active' ? await api('baiterPoll', {id:previousId})
        : await api('brukkargDefend', {stage,previousId,requestId:retry.id});
      if (!current(token) || state.modal !== 'tribes' || state.modalEpoch !== modalEpoch || data.battle?.brukkargStage !== stage) return;
      closeModal(); prepareEventYard();
      if (accept(data,true)) { retry = null; audio.effect('baiter'); }
    } catch (error) { if (current(token)) toast(error.message,true); }
    finally { if (pending === token) pending = null; if (button.isConnected) button.disabled = false; }
  }
  async function start() {
    if (!atHome() || state.modal !== 'baiter' || !options()?.available || !valid() || busy()) return;
    const token = context(), modalEpoch = state.modalEpoch;
    const previousId = options().session?.id || null;
    const signature = JSON.stringify([state.user.id, wave, direction, previousId]);
    if (retry?.signature !== signature) {
      const bytes = crypto.getRandomValues(new Uint8Array(16));
      retry = {signature, id:Array.from(bytes, byte => byte.toString(16).padStart(2,'0')).join('')};
    }
    const requestId = retry.id;
    pending = token; refreshEditor();
    try {
      const data = await api('baiterStart', {wave:{...wave},direction,requestId,previousId});
      if (!current(token) || state.modal !== 'baiter' || state.modalEpoch !== modalEpoch || !atHome()) return;
      if (accept(data, true)) { retry = null; if (data.battle.status === 'active') audio.effect('baiter'); }
    } catch (error) { if (current(token) && state.modalEpoch === modalEpoch) toast(error.message,true); }
    finally { if (pending === token) { pending = null; refreshEditor(); } }
  }
  async function resume(review = false) {
    const stored = options()?.session;
    if (!atHome() || busy() || state.modal || !stored || (!review && stored.status !== 'active') || (review && stored.status !== 'finished')) return;
    const token = context(), modalEpoch = state.modalEpoch; pending = token;
    try {
      const data = await api('baiterPoll',{id:stored.id});
      if (current(token) && modalEpoch === state.modalEpoch && atHome() && data.session?.id === stored.id) accept(data,true);
    } catch (error) { if (current(token)) toast(error.message,true); }
    finally { if (pending === token) pending = null; }
  }
  async function poll() {
    if (!sessionId || !state.battle?.training || state.battle.status !== 'active' || busy() || polling || document.hidden || state.activeBattle || Date.now() < retryAfter) return;
    const token = context(), id = sessionId; polling = token;
    try {
      const data = await api('baiterPoll',{id});
      if (current(token) && polling === token && sessionId === id && state.battle?.status === 'active') accept(data,false);
    } catch (error) {
      if (current(token) && polling === token) { retryAfter = Date.now() + 4000; toast(error.message,true); }
    } finally { if (polling === token) polling = null; }
  }
  async function siren() {
    if (!sessionId || !state.battle?.training || state.battle.status !== 'active' || state.activeBattle || busy()) return;
    const token = context(), id = sessionId; token.action = 'siren'; pending = token; polling = null;
    const button = $('#baiter-siren'); if (button) button.disabled = true;
    try {
      const data = await api('baiterSiren',{id});
      if (current(token) && sessionId === id && accept(data,false) && data.battle.result?.outcome === 'dismissed') audio.effect('siren');
    } catch (error) { if (current(token)) toast(error.message,true); }
    finally { if (pending === token) { pending = null; const visible = $('#baiter-siren'); if (visible) visible.disabled = false; } }
  }
  function reset() { generation++; pending = null; polling = null; sessionId = null; retryAfter = 0; }
  function resetAccount() { reset(); retry = null; wave = {}; draftOwner = null; }
  async function handleClick(el) {
    if (el.dataset.brukkargWave === 'start') { await startBrukkarg(Number(el.dataset.wave),el); return true; }
    switch (el.dataset.baiter) {
      case 'season': showSeason(); break;
      case 'season-act': await seasonClick(el); break;
      case 'moloch': if(atHome()&&state.base?.molochSiege)showModal(siegeBoard(state),'moloch');break;
      case 'moloch-start': await startBlitz(0,false,true);break;
      case 'emergence': showEmergence(); break;
      case 'emergence-raise': await investigate(el); break;
      case 'emergence-start': await startBlitz(0,true); break;
      case 'wmi': {
        if (atHome() && state.base?.wmi) showModal(wmiBoard(state,el.dataset.wmiEvent),'wmi');
        break;
      }
      case 'wmi-start': await startBlitz(Number(el.dataset.wmiEvent)); break;
      case 'blitz': showBlitz(); break;
      case 'blitz-start': await startBlitz(); break;
      case 'horse-review': closeModal(); await resume(true); break;
      case 'blitz-resume': closeModal(); await resume(); break;
      case 'configure': configure(); break;
      case 'resume': await resume(); break;
      case 'review': await resume(true); break;
      case 'launch': await start(); break;
      case 'clear':
        if (state.modal === 'baiter' && atHome() && !busy()) { wave = {}; saveDraft(); refreshEditor(); }
        break;
      case 'siren': await siren(); break;
      case 'leave': leave(); break;
      case 'less': edit(el.dataset.species,(wave[el.dataset.species] || 0) - 1); break;
      case 'more': edit(el.dataset.species,(wave[el.dataset.species] || 0) + 1); break;
      case 'direction':
        if (state.modal === 'baiter' && atHome() && !busy() && options()?.directions.includes(el.dataset.edge)) { direction = el.dataset.edge; saveDraft(); refreshEditor(); }
        break;
      default: return false;
    }
    return true;
  }
  function panel(battle) {
    const total = countWave(battle.wave), survivors = battle.units.filter(u => u.team === 'attacker' && u.hp > 0).length;
    const event = battle.molochStage || battle.emergenceStage || battle.wmiEvent || battle.blitzStage || battle.brukkargStage || battle.horse;
    const edges = battle.entries ? Object.keys(battle.entries) : [battle.direction];
    const directions = edges.map(key=>nameOf(key).toLowerCase()).join(', ');
    const waiting = event && battle.status === 'active'
      ? `<div class="stat-line"><span>${battle.horse ? 'Still inside the horse' : 'Yet to arrive'}</span><b data-wave-reserve>${num(countWave(battle.remaining || {}))}</b></div>` : '';
    const composition = Object.entries(battle.wave).map(([key, qty]) => `<li>${art(key,'')}<span>${esc(state.catalog.monsters[key]?.name || state.catalog.champions?.[key]?.name || (key === 'krallen' ? 'Krallen' : key))}</span><b>×${num(qty)}</b></li>`).join('');
    return `<h2>${battle.molochStage?'Moloch’s siege · Wave '+num(battle.molochStage):battle.emergenceStage?'Moloch · Earthquake '+num(battle.emergenceStage):battle.wmiEvent?wmiTitle(battle):battle.horse ? 'Trojan Horse ambush' : battle.brukkargStage ? 'Brukkarg War · Wave ' + num(battle.brukkargStage) : battle.blitzStage ? 'Monster Blitzkrieg · Wave ' + num(battle.blitzStage) : 'Defense drill'}</h2><p>${num(total)} wild ${total === 1 ? 'monster' : 'monsters'} ${battle.horse ? 'hidden inside the wooden horse' : 'from the '+esc(directions)}.</p><ul class="baiter-roster">${composition}</ul><div class="stat-line"><span>Wild monsters on field</span><b>${num(survivors)}</b></div>${waiting}${battle.status === 'active' ? `<button class="button danger block" id="baiter-siren" data-baiter="siren" ${pending?.action === 'siren' ? 'disabled' : ''}>${icon('music')}Sound the siren</button><p class="subtle-note">${battle.horse ? 'The siren ends the ambush. Any damage to your yard will remain.' : event ? 'Sounding the siren forfeits this wave. Damage already dealt remains.' : 'The siren sends the pack away and ends this drill.'}</p>` : ''}<button class="button subtle block" data-baiter="leave">${icon('home')}Return to my backyard</button>`;
  }
  function result(battle) {
    const r = battle.result || {};
    const brukkarg = battle.brukkargStage && r.outcome === 'held'
      ? `<p>Wave ${num(battle.brukkargStage)} held · +1 Brukkarg point. ${battle.brukkargStage % 5 === 0 ? 'Your next expedition is open.' : ''}</p>` : '';
    const title = battle.emergenceStage ? ({held:'Your yard held against Moloch.',overrun:'Moloch overran your defenses.',dismissed:'The emergence defense ended.'}[r.outcome]||'Emergence complete') : battle.horse ? ({held:'You repelled the Trojan Horse.',overrun:'The Trojan Horse broke through.',dismissed:'The Trojan Horse ambush ended.'}[r.outcome] || 'Trojan Horse defense complete') : {held:'Your defenses held.', overrun:'The pack broke through.', dismissed:'The siren cleared the yard.'}[r.outcome] || 'Defense drill complete';
    return `<span class="result-icon">${icon('shield')}</span><h2>${title}</h2>${battle.horse?'<p>Building damage carries back to your home yard. Use Repair Base to restore it for free.</p>':''}${brukkarg}${siegeResult(battle)}${emergenceDebrief(battle)}${wmiResult(battle,state.base?.wmi)}${battle.blitzStage && r.outcome === 'held' ? `<p>${battle.blitzStage === 10 ? "Slimeattikus unlocked at the Hatchery." : "Wave " + num(battle.blitzStage) + " cleared."}</p>` : ''}<div class="result-stats"><div><b>${num(r.defeated || 0)} / ${num(r.spawned || 0)}</b><span>WILD MONSTERS DEFEATED</span></div><div><b>${num(r.destruction || 0)}%</b><span>${battle.molochStage || battle.emergenceStage || battle.wmiEvent || battle.blitzStage || battle.brukkargStage || battle.horse ? 'YARD DAMAGE' : 'DRILL DAMAGE'}</span></div></div><button class="button primary" data-baiter="leave">${icon('home')}Return to my backyard</button>`;
  }
  return {showEmergence, showSeason, refreshSeason, buildingButton, panel, result, configure, start, resume, poll, siren, edit, handleClick, refreshEditor, reset, resetAccount, currentId:()=>sessionId};
}
