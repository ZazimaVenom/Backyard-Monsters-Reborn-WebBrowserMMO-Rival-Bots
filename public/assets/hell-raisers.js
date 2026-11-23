import {esc, icon, num} from './ui.js?v=0.13.0';

const stamp = time => esc(new Date(time*1000).toLocaleString());

export function hellBoard(event, open=false) {
  if(!event)return '';
  const phase=event.phase;
  const intro=!event.mapReady?'Build or repair your Map Room to scout Moloch’s yards.':
    phase==='active'?`Your hunt ends ${stamp(event.ends)}.`:
    phase==='shop'?`The hunt ended. Spend your XP until ${stamp(event.shopEnds)}.`:
    phase==='replay'?'Start another four-day hunt by invading a yard. Your unspent XP carries over.':
    'The first invasion starts your four-day hunt. Scout any yard before choosing where to fight.';
  const groups=new Map();
  for(const camp of event.camps){
    if(!groups.has(camp.tier))groups.set(camp.tier,[]);
    groups.get(camp.tier).push(camp);
  }
  const yards=[...groups].map(([tier,camps])=>`<details><summary><b>${esc(tier)}</b><small>${num(camps.length)} yards</small></summary>
    ${camps.map(c=>`<article class="tribe-card"><div><strong>${esc(c.name)}</strong><small>${num(c.xp)} XP${c.best!==null?' · best '+num(c.best)+'%':''}</small></div><button class="button subtle small" data-tribe-scout="${c.stage}" aria-label="Scout ${esc(c.name)}">${icon('eye')}Scout</button></article>`).join('')}</details>`).join('');
  const prizes=event.prizes.map(p=>{
    const label=p.owned?'Owned':p.studying?'Discovering':p.blocked?'Previous prize required':num(p.cost)+' XP';
    return `<article class="tribe-card"><div><strong>${esc(p.name)}</strong><small>${esc(label)}</small></div>
      <button class="button subtle small" data-hell-buy="${esc(p.id)}" data-run="${event.run}" aria-label="Unlock ${esc(p.name)}" ${p.canBuy?'':'disabled'}>${p.owned?'Unlocked':'Unlock'}</button></article>`;
  }).join('');
  return `<details class="brukkarg-event hell-raisers" data-event-board="hellraisers" ${open?'open':''}><summary><span><b>Hell Raisers</b><small>${num(event.balance)} event XP · Rezghul</small></span></summary>
    <div class="carnage-prize"><img src="assets/portraits/rezghul.webp" alt="Rezghul" width="84" height="84"><div><h3>Hunt Moloch’s yards</h3><p>${intro}</p></div></div>
    <p>Launch your surface monsters against Moloch’s Inferno outposts. A fought battle earns one event XP per structure in the yard. You can attack the same yard again.</p>
    <div class="panel-stats"><div><span>${['shop','replay'].includes(phase)?'Last run':'Run score'}</span><b>${num(event.score)}</b></div><div><span>Best run</span><b>${num(event.best)}</b></div></div>
    ${yards}<details data-hell-store><summary><b>Event Store</b><small>${num(event.balance)} XP</small></summary>${!event.shopOpen?'<p>The store opens with your first invasion and stays open for 24 hours after the hunt ends.</p>':''}${prizes}</details></details>`;
}

export function hellPlan(row) {
  return `<p>Deploy a monster or attack with siege equipment to earn <b>${num(row.xp)} event XP</b> when this battle ends. You can spend event XP on Rezghul and other permanent unlocks.</p>`;
}

export function hellScout(row,button) {
  return `<span class="eyebrow">HELL RAISERS</span><h2>${esc(row.name)}</h2>
    <div class="panel-stats"><div><span>Event XP</span><b>${num(row.xp)}</b></div><div><span>Best destruction</span><b>${row.best==null?'—':num(row.best)+'%'}</b></div></div>
    <p>Inflict 90% damage on the yard’s non-wall building health to defeat it. Inspect the defenses and choose where to launch your surface army. Moloch rebuilds this yard between invasions.</p>
    ${button}<button class="button subtle block" data-tribes-open="hellraisers">Event route</button>`;
}

export function hellResult(battle,champions) {
  const {campaign:c,attackerWon:won}=battle.result;
  const message=c.xp?`This attack earned ${num(c.xp)} event XP.`:c.expired?'The hunt ended before this battle qualified.':'Your army returned before fighting.';
  return `<span class="result-icon">${icon(won?'trophy':'shield')}</span><span class="eyebrow">HELL RAISERS</span><h2>${won?'Moloch’s yard defeated':'Attack ended'}</h2>
    <p>${message}</p><div class="result-stats"><div><b>${num(c.balance)}</b><span>XP BALANCE</span></div><div><b>${num(c.score)}</b><span>RUN SCORE</span></div></div>
    ${champions}<button class="button subtle" data-tribes-open="hellraisers">Event route and prizes</button><button class="button primary" data-command="home">Return home</button>`;
}
