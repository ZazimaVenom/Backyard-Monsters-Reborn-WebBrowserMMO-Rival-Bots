import {icon, esc, num, duration} from './ui.js?v=0.13.0';

export function createWorkers({state,art,costHTML,showModal,closeModal,action,toast,now,focusBuilding}) {
  let buying=false;
  const $=selector=>document.querySelector(selector);
  const canStart=()=>!state.base?.construction||Number(state.base.construction.available)>0;
  const reason=()=>canStart()?'':'All construction workers are busy. Wait for a job to finish or hire another worker.';
  function chip(){
    const status=state.base?.construction,button=$('#worker-open');if(!button)return;
    button.innerHTML=`${icon('repair')}<span><b>${num(status?.available||0)} / ${num(status?.capacity||1)} workers free</b><small>${status?.overCapacity?'Earlier paid jobs are finishing':status?.busy?`${status.busy} job${status.busy===1?'':'s'} in progress`:'Ready for your next project'}</small></span>${icon('chevron')}`;
    button.setAttribute('aria-label',`${num(status?.available||0)} of ${num(status?.capacity||1)} construction workers available. Manage workers`);
  }
  function html(){
    const status=state.base?.construction||{capacity:1,busy:0,available:1,jobs:[],max:5},hire=status.nextHire;
    const titles={build:'Constructing',upgrade:'Upgrading',fortify:'Reinforcing'};
    return `<div class="workers-header"><div><span class="eyebrow">A LITTLE HELP GOES A LONG WAY</span><h2 id="modal-title">Your construction crew</h2><p>Workers build, upgrade and reinforce your backyard. Each worker handles one project at a time.</p></div>${art('worker','Construction worker')}</div><div class="worker-summary"><div><b>${num(status.available)}</b><span>FREE</span></div><div><b>${num(status.busy)}</b><span>ACTIVE JOBS</span></div><div><b>${num(status.capacity)} / ${num(status.max)}</b><span>WORKERS HIRED</span></div></div>${status.overCapacity?'<div class="network-defense-note"><div><b>Your earlier jobs are safe.</b><p>All already-paid projects will finish on their existing timers. New work can start when your crew has a free worker.</p></div></div>':''}<div class="worker-jobs">${(status.jobs||[]).map(job=>job.kind==='pick'?`<div class="worker-job">${job.type==='pumpkin'?art('eventpumpkin',''):'<span class="mushroom-art" aria-hidden="true"></span>'}<span><b>${job.type==='pumpkin'?'Gathering a pumpkin':'Picking a mushroom'}</b><small>Yard foraging</small></span><strong data-countdown="${job.readyAt}">${duration(job.readyAt-now())}</strong></div>`:`<button class="worker-job" data-worker="focus" data-id="${job.buildingId}">${art(job.type,'')}<span><b>${esc(state.catalog.buildings[job.type]?.name||job.type)}</b><small>${titles[job.kind]||'Working'} · #${job.buildingId}</small></span><strong data-countdown="${job.readyAt}">${duration(job.readyAt-now())}</strong>${icon('chevron')}</button>`).join('')||'<div class="worker-idle"><span>'+icon('check')+'</span><b>Your crew is ready.</b><p>Choose a building project to put them to work.</p></div>'}</div><div class="worker-hire"><div><span class="eyebrow">ROOM FOR ANOTHER PAIR OF HANDS</span><h3>${status.capacity>=status.max?'Your crew is complete':`Hire worker ${num(hire?.target||status.capacity+1)}`}</h3><p>${status.capacity>=status.max?'All five workers are available for your backyard.':'A permanent extra worker lets another building project run at the same time.'}</p></div>${status.capacity<status.max&&hire?`<div>${costHTML(hire.cost)}<button class="button primary" data-worker="hire" ${buying||!hire.available?'disabled':''}>${icon('plus')}${buying?'Hiring…':'Hire worker '+hire.target}</button>${!hire.available?`<p class="subtle-note">${esc(hire.reason)}</p>`:''}</div>`:''}</div><p class="subtle-note">Hatching, research, repairs and territory jobs use their own systems. Workers stay with your home and do not join invasions.</p>`;
  }
  function show(){if(!state.user||state.visiting||state.battle)return;showModal(html(),'workers');}
  function refresh(){chip();if(state.modal==='workers'&&!buying){const focused=document.activeElement,command=focused?.dataset?.worker,id=focused?.dataset?.id;$('#modal-body').innerHTML=html();if(command){const target=$(`[data-worker="${command}"]${id?`[data-id="${Number(id)}"]`:''}`);target?.focus?.({preventScroll:true});}}}
  async function handleClick(el){
    const command=el.dataset.worker;if(!command)return false;
    if(command==='open'){show();return true;}
    if(!state.user||state.visiting||state.battle)return true;
    if(command==='focus'){const building=state.base.buildings.find(b=>Number(b.id)===Number(el.dataset.id));if(building){closeModal();focusBuilding(building);}}
    if(command==='hire'&&!buying&&state.base.construction?.nextHire?.available){buying=true;const owner=state.user.id;try{const result=await action('hireWorker',{},el);if(result&&state.user?.id===owner)toast('A new worker has joined your crew.');}finally{buying=false;refresh();}}
    return true;
  }
  return {show,refresh,chip,handleClick,canStart,reason};
}
