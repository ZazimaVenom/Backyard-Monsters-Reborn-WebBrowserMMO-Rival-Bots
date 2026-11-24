import {duration,esc,num} from './ui.js?v=0.13.0';

const actionNames={train:'infernoTrain',unlock:'infernoUnlock',research:'infernoResearch'};
const jobLabels={train:'Hatching',discovery:'Discovering',study:'Studying'};

function jobRow(lane,job,view){
  const species=view.options[job.type];
  return `<div class="queue-item"><div><b>${jobLabels[lane]} ${esc(species.name)}</b><small>${lane==='train'?'Incubator '+Number(job.lane):'Level '+Number(job.level)} · ${num(job.paid)} Magma paid</small></div><span data-countdown="${Number(job.ready_at)}"></span><button class="button subtle small" data-cavern-monster="cancel" data-lane="${lane}" data-egg="${Number(job.id||0)}" data-order-rev="${Number(view.revision)}" title="Refund paid Magma up to Resource Pod capacity">Cancel</button></div>`;
}

function control(type,lane,option,level,revision){
  const label=lane==='train'?'Hatch one':lane==='unlock'?'Discover':'Study level '+(level+1);
  return `<div class="train-action"><small>${num(option.cost.magma||0)} Magma · ${duration(option.seconds)}</small><button class="button subtle small" data-cavern-monster="${lane}" data-species="${esc(type)}" data-order-rev="${revision}" ${option.reason?'disabled':''}>${esc(option.reason||label)}</button></div>`;
}

export function renderInfernoMonsters(view){
  if(!view?.options)return '';
  const jobs=[...view.queue.map(egg=>jobRow('train',egg,view)),...['discovery','study'].filter(lane=>view[lane]).map(lane=>jobRow(lane,view[lane],view))];
  const cards=Object.entries(view.options).map(([type,s])=>{
    const actions=s.unlocked?control(type,'train',s.train,s.level,view.revision)+(s.level<6?control(type,'research',s.research,s.level,view.revision):''):
      control(type,'unlock',s.unlock,s.level,view.revision);
    return `<article class="train-row"><img src="assets/art/${esc(s.art)}" width="72" height="72" alt="" loading="lazy"><div class="train-info"><h3>${esc(s.name)}${s.unlocked?' <small>Lv '+Number(s.level)+'</small>':''}</h3><p>${esc(s.description)}</p><div class="train-meta"><span>${num(s.hp)} HP</span><span>${num(s.damage)} damage</span><span>${num(s.size)} space</span><span>${num(view.army[type]||0)} ready</span></div></div><div>${actions}</div></article>`;
  });
  return `<section aria-label="Inferno monsters"><div class="stat-line"><span>Compound space</span><b>${num(view.used)} / ${num(view.capacity)}</b></div>${jobs.length?`<div class="queue-list">${jobs.join('')}</div>`:''}<div class="train-list">${cards.join('')}</div></section>`;
}

export function infernoMonsterAction(el){
  const {cavernMonster:lane,species:type,orderRev,lane:jobLane,egg}=el?.dataset||{};
  if(lane==='cancel')return {action:'infernoCancel',lane:jobLane,id:Number(egg),monsterRevision:Number(orderRev)};
  return Object.hasOwn(actionNames,lane)?{action:actionNames[lane],type,monsterRevision:Number(orderRev)}:null;
}
