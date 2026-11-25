import {num,duration} from './ui.js?v=0.13.1';

const portrait=k=>`<img src="assets/art/krallen-p${Number(k.power)||0}.webp" alt="Krallen">`;

export function huntPanel(k,now){
  if(!k)return '';
  const title=k.retained?'Permanent companion':k.power?'This week’s companion':'Weekly companion';
  return `<section class="champion-care"><div class="champion-care-heading"><div><span class="eyebrow">${title}</span><h3 id="krallen-event-title" tabindex="-1">King of the Hill · Hunt for Krallen</h3></div></div>
    <p>${k.eligible?'Loot rival player and bot home yards. Resources that reach your stores count toward this week’s hunt.':'Reach Town Hall 5 and complete Map Room level 2 to enter the hunt.'} The hunt closes every Monday at 00:00 UTC.</p>
    <div class="stat-line"><span>Week ends in <b data-countdown="${Number(k.ends)}">${duration(k.ends-now)}</b></span><b>${num(k.loot)} looted</b></div>
    <div class="champion-mini-stats"><span><b>${k.retained?'Retained':num(k.award)+' loot'}</b>weekly companion</span><span><b>${num(k.upgrade)} loot</b>upgraded power</span><span><b>${Number(k.level)} / 5</b>consecutive wins</span></div>
    ${k.power?`<div class="champion-deploy-panel"><div>${portrait(k)}<span><b>Krallen · Level ${Number(k.level)}</b><small>${num(k.hp)} / ${num(k.max_hp)} HP · ${k.power===2?'Upgraded loot aura':'Standard power'}</small></span></div></div>`:''}
    <p>Win five consecutive rounds to keep Krallen. With a working Champion Cage, Krallen can guard your yard and deploy beside your active champion. Upgraded power increases loot from nearby allies’ attacks on harvesters and Silos.</p>
    ${k.eligible?'<button type="button" class="button subtle small" data-champion-event-route="invasion">Find a rival yard</button>':''}
    ${k.previous?`<p class="subtle-note">Last recorded round · ${num(k.previous.loot)} looted</p>`:''}</section>`;
}

export function krallenDock(k,combat,enabled,selected){
  if(!k?.power)return '';
  return `<button class="monster-card champion-dock-card ${selected?'selected':''}" data-command="${combat?'deploy-krallen':'champions'}" ${enabled?'':'disabled'} title="Krallen · Level ${Number(k.level)} · ${num(k.hp)} HP">${portrait(k)}<span class="count">LV ${Number(k.level)}</span><span class="name">Krallen</span></button>`;
}

export function krallenDeploymentHTML(k){
  if(!k)return '';
  return `<div class="champion-deploy-panel"><div>${portrait(k)}<span><b>Krallen</b><small>Companion · Level ${Number(k.level)} · ${num(k.hp)} HP</small></span></div><button class="button subtle block" data-command="deploy-krallen" ${!k.available||k.deployed?'disabled':''}>${k.deployed?'Krallen deployed':'Deploy Krallen'}</button></div>`;
}

export function krallenReceipt(result,role){
  const h=result?.krallenHunt;
  const c=result?.[role==='defender'?'defenderKrallen':'krallen'];
  return (h?.added>0?(role==='defender'?`<p>Hunt for Krallen · The attacker collected ${num(h.added)} qualifying loot.</p>`:`<p>Hunt for Krallen · +${num(h.added)} loot · ${num(h.loot)} this week.</p>`):'')+
    (c?.deployed?`<p>Krallen returned with ${num(c.hp)} / ${num(c.max_hp)} HP.</p>`:'');
}
