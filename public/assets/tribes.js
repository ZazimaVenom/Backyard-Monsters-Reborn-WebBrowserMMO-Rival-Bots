import {hellBoard, hellScout, hellResult, hellPlan} from './hell-raisers.js?v=0.13.1';
import {esc, icon, num} from './ui.js?v=0.13.1';

const supplies = amounts => Object.entries(amounts || {}).filter(([,n])=>n>0)
  .map(([key,n])=>`${num(n)} ${esc(key)}`).join(' · ');

export const isTerritory = target => ['resource','stronghold','fortification'].includes(target?.kind);

export function createTribes({state,api,showModal,closeModal,navigationIntent,navigationCurrent,inspect,enter,hasFlinger,readyChampion,toast,buttonBusy,refreshHome}) {
  function card(row) {
    return `<article class="tribe-card"><div><strong>${esc(row.name)}</strong><small>${row.record?`Cleared · best ${num(row.record.best)}% destruction`:row.open?'Route open' :row.event==='carnage'?'Clear the previous Town Hall':row.event?'Win the preceding waves and yard':'Complete the previous camp'}</small></div>
      <button class="button subtle small" data-tribe-scout="${row.stage}" ${row.open?'':'disabled'} aria-label="Scout ${esc(row.name)}">${icon(row.open?'eye':'lock')}Scout</button></article>`;
  }

  function carnageBoard(event) {
    if(!event)return '';
    const date=ts=>esc(new Date(Number(ts)*1000).toLocaleString());
    const details={
      locked:'A level 2–4 Town Hall opens the hunt for Vorg.',
      locker:'Your level permits paid Vorg discovery in the Monster Locker.',
      discovering:'Your paid Vorg discovery is already in progress.',
      owned:'Your Monster Locker already has Vorg.',
      complete:'Vorg is yours. Hatch this flying healer with goo and available housing.',
      waiting:`This run has ended. A new run opens ${date(event.nextAt)}.`,
      available:'Your six days start with the first invasion. Scouting is free.',
      active:`Destroy all ten Town Halls by ${date(event.endsAt)}.`
    };
    const playable=event.status==='available'||event.status==='active';
    return `<details class="brukkarg-event carnage-event" data-event-board="carnage"><summary><span><b>Creature Carnage</b><small>${num(event.score)} / 10 yards · Vorg</small></span></summary>
      <div class="carnage-prize"><img src="assets/art/vorg.png" alt="Vorg, a flying healer" width="84" height="84"><div><h3>Creature Carnage</h3><p>${details[event.status]||''}</p></div></div>
      ${playable?`<p>Destroy the Town Hall in each yard to earn one point. Ten points permanently unlock Vorg. You can return to a damaged yard for 24 hours after the first damaging attack.</p>${event.requirement?`<p class="facility-status">${esc(event.requirement)}</p>`:''}`:''}
      ${playable||event.status==='complete'?event.camps.map(card).join(''):''}</details>`;
  }

  function eventBoard(event) {
    if(!event)return '';
    const active=state.base?.baiter?.session, wave=active?.status==='active'&&active.brukkargStage?Number(active.brukkargStage):event.nextWave;
    const button=wave?`<button type="button" class="button primary small" data-brukkarg-wave="start" data-wave="${wave}">${icon('shield')}${active?.status==='active'&&active.brukkargStage?'Resume':'Defend'} wave ${num(wave)}</button>`:'';
    return `<details class="brukkarg-event" data-event-board="brukkarg"><summary><span><b>Brukkarg War</b><small>${event.eligible?`${num(event.waves)} / 25 waves · ${num(event.cleared)} / 5 yards`:'Opens at Town Hall level 5'}</small></span></summary>
      <div class="brukkarg-intro"><h3>Brukkarg War</h3><p>Defend five waves to open each expedition. Destroy the enemy Town Hall to advance. The final opponent copies your own yard.</p>
      <strong>${num(event.score)} / 5,025 points</strong>${button}</div>
      <p>Clear three yards for a Spurtz Cannon, four for a second cannon, and all five to earn the Black Diamond upgrade.</p>
      ${event.camps.map(row=>card(row)).join('')}</details>`;
  }

  function route(data) {
    const next = data.camps.find(row=>row.open&&!row.record)?.stage;
    const groups = data.camps.reduce((groups,row)=>{
      if(!groups.has(row.tier)) groups.set(row.tier,[]);
      groups.get(row.tier).push(row); return groups;
    }, new Map());
    const pending = supplies(data.pending);
    return `<header class="tribe-valley"><div><h2 id="modal-title">Wild Monster Tribes</h2><p>${data.cleared} of ${data.camps.length} camps cleared</p></div></header>
      <p>Scout a camp, then send your own monsters. A first victory opens the next camp and earns supplies.</p>
      ${pending?`<div class="tribe-supplies"><div><b>Supplies awaiting storage</b><small>${pending}</small></div><button class="button primary small" data-tribe-collect>Collect</button></div>`:''}
      ${carnageBoard(data.carnage)}
      ${eventBoard(data.brukkarg)}
      ${hellBoard(data.hellraisers)}
      <div class="tribe-route">${[...groups].map(([tier,rows])=>`<details ${rows.some(r=>r.stage===(next??data.camps.length))?'open':''}><summary>${esc(tier)}<small>${rows.filter(r=>r.record).length} / ${rows.length}</small></summary>${rows.map(card).join('')}</details>`).join('')}</div>`;
  }

  async function open(focusEvent) {
    if(!state.user || state.battle?.status==='active') return;
    const token=navigationIntent();
    try {
      const data=await api('tribes');
      if (!navigationCurrent(token,true)) return;
      showModal(route(data),'tribes');
      if (['brukkarg','carnage','hellraisers'].includes(focusEvent)) {
        const board=document.querySelector('[data-event-board="'+focusEvent+'"]');
        if (board) {
          board.open=true;
          board.scrollIntoView({block:'start'});
          board.querySelector('summary')?.focus({preventScroll:true});
        }
      }
    } catch(error) { if(navigationCurrent(token,true)) toast(error.message,true); }
  }

  async function scout(stage) {
    if(!state.user || state.battle?.status==='active') return;
    const token=navigationIntent();
    try {
      const camp=await api('tribeVisit',undefined,{stage});
      if(!navigationCurrent(token,true)) return;
      closeModal();
      await inspect(camp);
    } catch(error) { if(navigationCurrent(token,true)) toast(error.message,true); }
  }

  function attackButton(row) {
    if(row.event&&row.record)return '<p>Event yard cleared.</p>';
    return `<button class="button primary block" data-tribe-plan="${row.stage}">${icon('swords')}Plan an expedition</button>`;
  }

  function scoutPanel(base) {
    const row=base.tribe;
    if(row.event==='hellraisers')return hellScout(row,attackButton(row));
    return `<span class="eyebrow">WILD MONSTER TRIBES</span><h2>${esc(row.name)}</h2>
      <p>Select a tower to inspect its range. ${row.event?'Destroy the Town Hall to win. Damage from an unfinished attack stays for 24 hours from the first attack.':'Destroy the Town Hall or half of the camp’s intact target buildings to win.'}</p>
      <div class="panel-stats"><div><span>Expedition</span><b>${row.event?(row.event==='carnage'?num(row.stage-905)+' of 10':num(row.stage-900)+' of 5'):num(row.stage)}</b></div><div><span>Best destruction</span><b>${row.record?`${num(row.record.best)}%`:'Uncleared'}</b></div></div>
      <p>${row.record||row.supplied?'First-clear supplies earned.':`First-clear supplies · ${supplies(row.reward)}`}</p>
      ${attackButton(row)}<button class="button subtle block" data-tribes-open>Campaign route</button>`;
  }

  function plan(stage) {
    const camp=state.visiting;
    if (!camp?.tribe || camp.tribe.stage!==stage || state.battle?.status==='active') return;
    const key=Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');
    const carnage=camp.tribe.event==='carnage', requirement=carnage?camp.tribe.requirement:'', flinger=hasFlinger(), army=Object.values(state.army).some(q=>q>0)||!!readyChampion()||!!state.base?.krallenCompanion;
    showModal(`<h2 id="modal-title">Invade ${esc(camp.tribe.name)}?</h2><p>Your launched monsters leave your army. Champion injuries and Catapult ammunition carry home. Campaign battles preserve your rank and your home shield.</p>
      ${camp.tribe.event==='hellraisers'?hellPlan(camp.tribe):camp.tribe.supplied?'<p>This yard’s supply parcel has already been earned. A victory advances this run.</p>':camp.tribe.record?'<p>This is a replay. Your highest destruction score can still improve.</p>':`<p><b>First victory</b><br>${supplies(camp.tribe.reward)}. Supplies beyond your capacity wait on the campaign route.</p>`}
      ${carnage?'<p>Destroy the Town Hall for one Carnage point. The first invasion starts your six-day run. Ten points unlock Vorg.</p>':''}
      ${requirement?`<p class="facility-status">${esc(requirement)}</p>`:!flinger||!army?`<p class="facility-status">${!flinger?'Build or repair a Flinger at home.':'Hatch monsters or ready your champion first.'}</p>`:''}
      <div class="tribe-actions"><button class="button subtle" data-command="close-modal">Keep scouting</button>
      <button class="button primary" data-tribe-start="${stage}" data-request="${key}" ${flinger&&army&&!requirement?'':'disabled'}>Begin expedition</button></div>`,'tribe-confirm');
  }

  async function start(stage,button) {
    const camp=state.visiting, token=navigationIntent();
    if (!camp?.tribe || camp.tribe.stage!==stage || state.modal!=='tribe-confirm') return;
    buttonBusy(button,true);
    try {
      const battle=await api('tribeAttack',{stage,requestId:button.dataset.request,previousId:camp.previousId,...(camp.tribe.run?{run:camp.tribe.run}:{})});
      if (!navigationCurrent(token,true)) return;
      closeModal(); await enter(battle);
    } catch(error) { if(navigationCurrent(token,true)) toast(error.message,true); }
    finally { buttonBusy(button,false); }
  }

  async function collect(button) {
    const token=navigationIntent();
    buttonBusy(button,true);
    try {
      const data=await api('tribeCollect',{});
      if (!navigationCurrent(token,true)) return;
      const received=supplies(data.collected);
      showModal(route(data),'tribes');
      toast(received?`Collected ${received}.`:'Make room in storage to collect your supplies.');
      await refreshHome();
    } catch(error) { if(navigationCurrent(token,true)) toast(error.message,true); }
    finally { buttonBusy(button,false); }
  }

  function result(battle,champions) {
    const r=battle.result, c=r.campaign, won=r.attackerWon;
    const parcel=supplies(r.loot), bank=supplies(c.pending);
    if(c.event==='hellraisers')return hellResult(battle,champions);
    if(c.event==='carnage'){
      const outcome=c.expired?'This run ended before the yard fell.':c.vorg?'All ten Town Halls are down. Vorg is permanently unlocked in your Hatchery.':won?'Town Hall destroyed. The next Carnage yard is open.':'The Town Hall is still standing. Return during its 24-hour damage window to continue.';
      return `<span class="result-icon">${icon(won?'trophy':'shield')}</span><span class="eyebrow">CREATURE CARNAGE</span><h2>${esc(battle.target.name)}</h2><p>${outcome}</p><strong>${num(c.score)} / 10 points</strong>${parcel?`<p>Supplies delivered · ${parcel}</p>`:''}${bank?`<p>Awaiting storage · ${bank}</p>`:''}${champions}<button class="button subtle" data-tribes-open>Campaign route</button><button class="button primary" data-command="home">Return home</button>`;
    }
    if(c.event==='brukkarg'){
      const award=c.firstClear?({903:'You earned a Spurtz Cannon.',904:'You can now place two Spurtz Cannons.',905:'Your cannons earned the Black Diamond upgrade.'}[battle.target.stage]||''):'';
      return `<span class="result-icon">${icon(won?'trophy':'shield')}</span><span class="eyebrow">BRUKKARG WAR</span><h2>${esc(battle.target.name)}</h2><p>${won?('Enemy Town Hall destroyed. '+award):'The Town Hall is still standing. Return within 24 hours of the first attack to continue.'}</p>${parcel?`<p>Supplies delivered · ${parcel}</p>`:''}${champions}<button class="button subtle" data-tribes-open>Campaign route</button><button class="button primary" data-command="home">Return home</button>`;
    }
    return `<span class="result-icon">${icon(won?'trophy':'shield')}</span><span class="eyebrow">WILD MONSTER TRIBES</span>
      <h2>${esc(battle.target.name)}</h2><p>${won?(c.firstClear?(c.nextStage?'Camp cleared. The next expedition is open.':'Final camp cleared. You have won the campaign.'):'Camp cleared again.'):'Your invasion ended before the camp fell.'}</p>
      <div class="result-stats"><div><b>${num(r.destruction)}%</b><span>DESTRUCTION</span></div></div>
      ${parcel?`<p>Supplies delivered · ${parcel}</p>`:''}${bank?`<p>Awaiting storage · ${bank}</p>`:''}
      ${champions}<button class="button subtle" data-tribes-open>Campaign route</button><button class="button primary" data-command="home">${icon('home')}Return to my backyard</button>`;
  }

  async function handleClick(el) {
    if(el.dataset.hellBuy){
      const token=navigationIntent();
      buttonBusy(el,true);
      try{
        const {event,prize}=await api('hellRaisersBuy',{prize:el.dataset.hellBuy,run:Number(el.dataset.run)});
        if(navigationCurrent(token,true)){
          const board=document.querySelector('.hell-raisers');
          if(board){board.outerHTML=hellBoard(event,true);document.querySelector('.hell-raisers [data-hell-store]').open=true;}
          toast(prize.name+' unlocked.');await refreshHome();
        }
      }catch(error){if(navigationCurrent(token,true))toast(error.message,true);}
      finally{buttonBusy(el,false);}
      return true;
    }
    if(el.hasAttribute('data-tribes-open')) await open(el.dataset.tribesOpen);
    else if(el.dataset.tribeScout) await scout(Number(el.dataset.tribeScout));
    else if(el.dataset.tribePlan) plan(Number(el.dataset.tribePlan));
    else if(el.dataset.tribeStart) await start(Number(el.dataset.tribeStart),el);
    else if(el.hasAttribute('data-tribe-collect')) await collect(el);
    else return false;
    return true;
  }
  return {handleClick,scoutPanel,attackButton,result};
}
