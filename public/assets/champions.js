import {icon,esc,num,duration} from './ui.js?v=0.13.0';

import {huntPanel} from './krallen.js?v=0.13.0';

export function madnessReceipt(result,role){
  const m=result?.monsterMadness;
  if(!m||!Number.isFinite(m.added)||m.added<=0)return '';
  if(role==='defender')return `<p>Monster Madness · The attacker earned ${num(m.added)} points.</p>`;
  const reward=['','Korath','Breath of Fire','Fists of Doom'][m.power]||'';
  return `<p>Monster Madness · +${num(m.added)} points · ${num(m.score)} in this run.${m.earned&&reward?' Earned '+reward+'.':''}</p>`;
}

/** Champion care uses the same locked, authoritative actions as every yard change. */
export function createChampionHall({state,art,showModal,action,toast,now,hall,audio,goToEventPage}) {
  const roster=()=>state.base?.champions||{};
  const active=()=>state.base?.activeChampion||null;
  const buildings=type=>(state.base?.buildings||[]).filter(b=>b.type===type);
  const living=type=>buildings(type).some(b=>b.level>0&&b.hp>0&&b.ready_at<=now());
  const fullChamber=()=>buildings('championchamber').some(b=>b.level>0&&b.hp>=b.max_hp&&b.ready_at<=now());
  const spec=type=>state.catalog.champions?.[type];
  const earned=record=>record?.level===6?Math.max(0,Math.min(3,Number(record.bonus_feeds)||0)):0;
  const stats=(type,level=1,bonus=0)=>{const base={...(spec(type)?.levels?.[level]||{})};if(level===6&&bonus){const extra=spec(type)?.bonuses?.[bonus]||{};for(const key of ['hp','damage','speed','range','auraBuff'])if(Number.isFinite(Number(extra[key])))base[key]=Number(base[key]||0)+Number(extra[key]);}return base;};
  const currentStats=(type,record)=>record?.stats||stats(type,record?.level||1,earned(record));
  const role=type=>({gorgo:'THE UNBREAKABLE GUARDIAN',drull:'THE RELENTLESS HUNTER',fomor:'THE SWARM’S STRENGTH',korath:'MONSTER MADNESS'})[type]||'CHAMPION';
  const maxHp=(type,record)=>Number(currentStats(type,record).hp)||1;
  const fed=record=>Number(record?.next_feed_at||0)>=now();
  const freezeReason=record=>record?.battle_id?'Champion is in battle':!living('championchamber')?'Build or repair the Chamber':!living('championcage')?'Build or repair the Cage':record.hp<maxHp(active(),record)?'Recover to full health first':!fed(record)?'Feed before freezing':'';
  let lastKey='';

  function madness(){
    const m=state.base?.monsterMadness;
    if(!m)return '';
    const names=['Korath','Breath of Fire','Fists of Doom'];
    const lifetime=m.power===3?'All powers earned':m.ends?`Run ends in <b data-countdown="${Number(m.ends)}">${duration(m.ends-now())}</b>`:'Your next resource outpost capture starts a four-day run.';
    return `<section class="champion-care"><h3 id="madness-event-title" tabindex="-1">Monster Madness · Wrath of the Champion</h3><p>Capture distinct resource outposts on the World Map. Each site scores once per run. Earned powers stay with Korath.</p><div class="stat-line"><span>${lifetime}</span><b>${num(m.score)} points</b></div><div class="champion-mini-stats">${m.goals.map((goal,i)=>`<span><b>${names[i]}</b>${m.power>i?'Earned':num(goal)+' points'}</span>`).join('')}</div><button type="button" class="button subtle small" data-champion-event-route="world">Open the World Map</button></section>`;
  }

  function facility(){
    if(living('championcage'))return `<div class="champion-facility ready">${icon('shield')}<span><b>Your Champion Cage is ready.</b> One champion leads your army and guards your home.</span><span class="pill">${Object.keys(roster()).length} / ${living('championchamber')?Object.keys(state.catalog.champions||{}).length:1} raised</span></div>`;
    const cage=buildings('championcage')[0],working=cage?.ready_at>now();
    const text=working?`Champion Cage completes in <b data-countdown="${cage.ready_at}">${duration(cage.ready_at-now())}</b>.`:cage?'Repair your Champion Cage from its building panel to raise or feed a champion.':'Build a Champion Cage at Town Hall 4 to raise your first champion.';
    return `<div class="champion-facility">${icon('lock')}<span>${text}</span>${!cage?`<button class="button subtle small" data-build="championcage" ${hall()<4?'disabled':''}>${hall()<4?'Town Hall 4 required':'Build the Cage'}</button>`:''}</div>`;
  }

  function card(type,c){
    const record=roster()[type],isActive=active()===type,s=currentStats(type,record),frozen=!!record?.frozen;
    const caption=isActive?(record.hp>0?'ACTIVE CHAMPION':'RECOVERING'):frozen?'FROZEN IN THE CHAMBER':'WAITING TO BE RAISED';
    const reason=type==='korath'&&!state.base?.monsterMadness?.power?'Earn in Monster Madness':!living('championcage')?'Cage required':active()?'Freeze your active champion first':Object.keys(roster()).length>=1&&!living('championchamber')?'Champion Chamber required':'';
    const thawReason=active()?'Freeze your active champion first':!living('championcage')?'Repair your Cage':!fullChamber()?'Fully repair your Chamber':'';
    return `<article class="champion-card ${isActive?'is-active':''} ${frozen?'is-frozen':''}">
      <div class="champion-portrait"><span class="champion-state">${caption}</span>${art(type+'-e'+(record?.level||1),c.name)}<span class="champion-level">${record?'EVOLUTION '+record.level:'EVOLUTION 1'}</span></div>
      <div class="champion-card-body"><span class="eyebrow">${role(type)}</span><h3>${esc(c.name)}</h3><p>${esc(c.description)}</p>
      ${record?.level===6?`<span class="champion-bonus-badge">${icon('spark')}Food bonus ${earned(record)} / 3</span>`:''}<div class="champion-mini-stats"><span><b>${num(s.hp)}</b> health</span><span><b>${num(s.damage)}</b> damage</span><span>${s.movement==='air'?'Flying':'Ground'}</span></div>
      ${record?`<div class="health-label"><span>${frozen?'Health preserved':'Current health'}</span><span>${num(record.hp)} / ${num(s.hp)}</span></div><div class="progress-bar champion-health"><i style="width:${Math.max(0,Math.min(100,record.hp/s.hp*100))}%"></i></div>`:''}
      ${isActive?`<div class="champion-active-label">${icon('check')}Leading your backyard</div>`:frozen?`<button id="champion-thaw-${type}" class="button subtle block" data-champion-action="championThaw" data-type="${type}" ${thawReason?'disabled':''}>${thawReason||'Thaw '+esc(c.name)}</button>`:`<button id="champion-raise-${type}" class="button primary block" data-champion-action="championRaise" data-type="${type}" ${reason?'disabled':''}>${reason||'Raise '+esc(c.name)}</button>`}
      </div></article>`;
  }

  function care(){
    const type=active(),record=roster()[type],c=spec(type);if(!record||!c)return '';
    const level=record.level,bonus=earned(record),s=currentStats(type,record),recipe=(level===6?c.bonusRecipes?.[Math.min(3,bonus+1)]:null)||c.feedRecipes?.[level]||c.feedRecipes?.[Math.min(5,level)]||{},needed=c.feedsRequired?.[level]||0;
    const pantry=type==='korath'?(state.base?.infernoFood||{}):state.army;
    const total=Object.values(recipe).reduce((sum,n)=>sum+Number(n),0),missing=Object.entries(recipe).some(([key,n])=>(pantry[key]||0)<n);
    const remaining=Math.max(0,(record.next_feed_at||0)-now()),ready=remaining<=0;
    const feedReason=record.battle_id?'Champion is in battle':!living('championcage')?'Build or repair the Cage':!ready?'Digesting this meal':missing?'Hatch the missing monsters':'';
    const freezing=freezeReason(record),speed=state.catalog.speed||1;
    const recovery=(c.recoverySeconds?.[level-1]||0)*(1-Math.max(0,record.hp)/maxHp(type,record))/speed;
    const hungryAfter=(record.next_feed_at||0)+(c.hungerGrace||86400);
    return `<section class="champion-care"><div class="champion-care-heading"><div><span class="eyebrow">GREATNESS TAKES A BIG APPETITE</span><h3>Care for ${esc(c.name)}</h3></div><span class="pill">Evolution ${level} / 6</span></div>
      <div class="evolution-path" aria-label="Evolution level ${level} of 6">${[1,2,3,4,5,6].map(n=>`<span class="${n<=level?'reached':''} ${n===level?'current':''}"><b>${n}</b><small>${n===level?'Current':n<level?'Evolved':'Level '+n}</small></span>`).join('')}</div>
      <div class="champion-care-columns"><div class="champion-feeding">
      <div class="health-label"><span>${level<6?'Feeds toward the next evolution':'Food bonus power'}</span><b>${level<6?`${record.feeds||0} / ${needed}`:`Tier ${bonus} / 3`}</b></div><div class="progress-bar feeding-progress"><i style="width:${level<6?Math.min(100,(record.feeds||0)/needed*100):bonus/3*100}%"></i></div>
      <p class="champion-meal-note">${level<6?(type==='korath'?'Korath eats monsters from your Inferno Compound.':'Feeding consumes monsters from your ready army.'):bonus<3?`The next meal earns food bonus tier ${bonus+1}. New power increases your champion’s statistics while keeping existing injuries. Frozen champions preserve their earned tiers.`:'A maintenance meal protects all three bonus tiers and keeps the Chamber available. It grants no extra statistics or instant healing.'}</p><div class="champion-recipe">${Object.entries(recipe).map(([key,n])=>`<div class="recipe-monster ${(pantry[key]||0)<n?'missing':''}">${art(key,state.catalog.monsters[key]?.name||key)}<span><b>${n} ${esc(state.catalog.monsters[key]?.name||key)}</b><small>${num(pantry[key])} ready in your army</small></span>${(pantry[key]||0)>=n?icon('check'):icon('egg')}</div>`).join('')}</div>
      <div class="champion-feed-footer"><button id="champion-feed" class="button primary" data-champion-action="championFeed" ${feedReason?'disabled':''}>${feedReason||`Feed ${total} monsters`}</button><span>${ready?'Ready for a meal':`Next meal in <b data-countdown="${record.next_feed_at}">${duration(remaining)}</b>`}</span></div>${missing?(type==='korath'?'<button class="quiet-button" data-inferno-open>Open the Inferno Compound →</button>':'<button class="quiet-button" data-command="hatchery">Open the Hatchery to prepare this meal →</button>'):''}
      <p class="subtle-note">${level>=6?(hungryAfter>now()?`Feed before the hunger grace ends in <span data-countdown="${hungryAfter}">${duration(hungryAfter-now())}</span> to protect bonus power.`:'An overdue meal can cost one bonus tier per 24 hours. Evolution is never lost.'):(hungryAfter<now()?'Overdue meals reduce feed progress, never evolution level.':`Feed before the hunger grace ends in <span data-countdown="${hungryAfter}">${duration(hungryAfter-now())}</span> to protect feed progress.`)}</p>${level>=6?bonusHTML(type,bonus):''}
      </div><div class="champion-care-notes">
      <div class="champion-note">${icon('shield')}<div><h4>${record.hp>=s.hp?'Ready for the battlefield':'Resting in the Cage'}</h4><p>${record.hp>=s.hp?'Deploy once at a clear point during an invasion. At home, your active champion defends automatically beside its Cage.':`Full recovery in about ${duration(recovery)}. Injuries persist after battle; your champion returns home instead of being permanently lost.`}</p></div></div>
      ${type==='korath'?`<div class="champion-note">${icon('spark')}<div><h4>Korath’s powers</h4><p>Claw attacks burn enemy monsters. Breath of Fire targets flyers from evolution 4. Fists of Doom delivers a ground shock after three melee strikes from evolution 5.</p></div></div>`:''}
      ${type==='fomor'?`<div class="champion-note">${icon('spark')}<div><h4>Aura of strength</h4><p>Nearby allies move and attack ${Math.round((1+2*(s.auraBuff||0))*100)}% as fast and take ${Math.round((s.auraBuff||0)*100)}% less damage. The aura does not affect Fomor itself. Flight begins at evolution 3.</p></div></div>`:''}
      <div class="champion-note">${icon('lock')}<div><h4>A place in the Chamber</h4><p>Freeze a fully healed, fed champion to raise or thaw another. Frozen champions keep their evolution and food bonuses, and pause their feeding timer.</p><button id="champion-freeze" class="button subtle small" data-champion-action="championFreeze" ${freezing?'disabled':''}>${freezing||'Freeze '+esc(c.name)}</button>${!buildings('championchamber').length?`<button class="quiet-button" data-build="championchamber" ${hall()<4||!living('championcage')?'disabled':''}>Build Champion Chamber →</button>`:''}</div></div>
      </div></div>
      <details class="champion-evolution-table"><summary>Compare all six evolution levels</summary><div class="table-scroll"><table><thead><tr><th>Level</th><th>Health</th><th>Damage</th><th>Movement</th><th>Feeds to evolve</th></tr></thead><tbody>${[1,2,3,4,5,6].map(n=>{const v=stats(type,n);return `<tr class="${n===level?'current':''}"><td>${n}</td><td>${num(v.hp)}</td><td>${num(v.damage)}</td><td>${v.movement==='air'?'Flying':'Ground'}</td><td>${c.feedsRequired?.[n]||'Complete'}</td></tr>`}).join('')}</tbody></table></div></details>
    </section>`;
  }

  function bonusHTML(type,current){
    const c=spec(type);
    return `<div class="champion-bonus-path" aria-label="Champion food bonus tiers">${[1,2,3].map(n=>{const b=c.bonuses?.[n]||{};return `<div class="champion-bonus-step ${n<=current?'earned':''} ${n===current?'current':''}"><strong>Bonus ${n}</strong>${b.hp?`<span>+${num(b.hp)} health</span>`:''}${b.damage?`<span>+${num(b.damage)} damage</span>`:''}${b.speed?`<span>+${Number(b.speed).toLocaleString(undefined,{maximumFractionDigits:2})} speed</span>`:''}${b.range?`<span>+${Number(b.range).toLocaleString(undefined,{maximumFractionDigits:2})} range</span>`:''}${b.auraBuff?`<span>+${Math.round(b.auraBuff*100)}% aura strength</span>`:''}<small>${n===current?'Current power':n<current?'Earned':n===current+1?'Next meal':'Later meal'}</small></div>`}).join('')}</div><p class="subtle-note">Each tier shows the total bonus over evolution 6. Tiers do not add together. Meals over 24 hours late gradually reduce bonus power; frozen champions are protected.</p>`;
  }

  function show(){
    if(state.battle?.status==='active'){toast('Finish the battle before caring for your champions.');return;}
    if(!state.catalog?.champions)return;
    showModal(`<div class="champion-hall"><nav class="workshop-nav" aria-label="Monster workshop"><button class="button subtle small" data-command="hatchery">${icon('egg')}Hatchery</button><button class="button primary small" aria-current="page">${icon('trophy')}Champions</button></nav><span class="eyebrow">ONE CHAMPION. A WHOLE NEW STRATEGY.</span><h2 id="modal-title">Raise a backyard legend.</h2><p class="champion-intro">Choose a guardian, a hunter, or the heart of your swarm. Feed your champion, guide its evolution, and carry its story from one battle to the next.</p>${facility()}${madness()}${huntPanel(state.base?.krallenHunt,now())}<div class="champion-roster">${Object.entries(state.catalog.champions).map(([type,c])=>card(type,c)).join('')}</div>${care()}<p class="subtle-note champion-rules">Champions use their own deployment slot and do not occupy monster housing. One raised champion can be active. An earned Krallen can accompany it. Their injuries and evolution are saved with your world.</p></div>`,'champions');
    lastKey=progressKey();
  }

  async function handleClick(el){
    const event=el.dataset.championEvent;
    if (event==='krallen'||event==='madness') {
      if (!state.user || state.modal!=='season' || state.visiting || state.battle || state.worldSite) return true;
      show();
      const heading=document.getElementById(event+'-event-title');
      heading?.scrollIntoView({block:'start'});
      heading?.focus({preventScroll:true});
      return true;
    }
    if (el.dataset.championEventRoute) {
      if (state.modal==='champions' && ['invasion','world'].includes(el.dataset.championEventRoute)) {
        await goToEventPage(el.dataset.championEventRoute);
      }
      return true;
    }
    if(!el.dataset.championAction)return false;
    const name=el.dataset.championAction;
    if(!['championRaise','championFeed','championFreeze','championThaw'].includes(name))return true;
    const type=el.dataset.type||active(),oldLevel=roster()[type]?.level||0,oldBonus=earned(roster()[type]);
    const data=await action(name,el.dataset.type?{type:el.dataset.type}:{},el);
    if(data){
      const messages={championRaise:`${spec(type)?.name||'Your champion'} has joined your backyard.`,championFeed:'Meal served. Your champion is growing stronger.',championFreeze:'Champion frozen. Its feeding timer is paused.',championThaw:'Champion thawed and ready to lead.'};
      const evolved=name==='championFeed'&&(roster()[type]?.level||0)>oldLevel;
      const empowered=name==='championFeed'&&earned(roster()[type])>oldBonus;
      toast(evolved?`${spec(type).name} evolved to level ${roster()[type].level}!`:empowered?`${spec(type).name} earned food bonus tier ${earned(roster()[type])}!`:name==='championFeed'&&oldLevel===6&&oldBonus===3?'Meal served. Maximum bonus power is maintained.':messages[name]);
      audio.effect(evolved?'evolve':empowered?'boost':name==='championFeed'?'feed':'champion');redrawPreserving();
    }
    return true;
  }

  function progressKey(){return JSON.stringify([state.base?.champions,state.base?.monsterMadness,state.base?.krallenHunt,state.base?.infernoFood,state.base?.activeChampion,state.army,(state.base?.buildings||[]).filter(b=>['championcage','championchamber'].includes(b.type)),active()?Number(roster()[active()]?.next_feed_at||0)<=now():false]);}
  function redrawPreserving(){
    const focused=document.activeElement?.id,scroll=document.querySelector('.modal-card')?.scrollTop||0,details=document.querySelector('.champion-evolution-table')?.open;
    show();
    if(details&&document.querySelector('.champion-evolution-table'))document.querySelector('.champion-evolution-table').open=true;
    if(focused)document.getElementById(focused)?.focus({preventScroll:true});
    document.querySelector('.modal-card').scrollTop=scroll;
  }
  function refresh(){
    if(state.modal==='champions'&&progressKey()!==lastKey)redrawPreserving();
  }
  return {show,handleClick,refresh};
}
