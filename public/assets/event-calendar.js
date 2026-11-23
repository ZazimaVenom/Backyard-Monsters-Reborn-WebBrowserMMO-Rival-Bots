import {esc,num} from './ui.js?v=0.13.0';

const dates=new Intl.DateTimeFormat('en',{timeZone:'UTC',month:'short',day:'numeric'});
const describe=ts=>dates.format(ts*1000);
const actions={brukkarg:'data-tribes-open="brukkarg"',carnage:'data-tribes-open="carnage"',blitz:'data-baiter="blitz"',wmi1:'data-baiter="wmi" data-wmi-event="1"',wmi2:'data-baiter="wmi" data-wmi-event="2"'};
const illustrations={brukkarg:'spurtzcannon',carnage:'vorg',blitz:'slimeattikus',wmi1:'pokey',wmi2:'spurtz'};
function card(r) {
  const tag=`data-key="${esc(r.key)}" data-start="${r.start}" data-revision="${r.revision}"`;
  const action=(op,label,primary=false)=>`<button class="button ${primary?'primary':'subtle'} small" data-calendar-op="${op}" ${tag}>${label}</button>`;
  const active=r.phase==='active';
  const play=r.joined&&active?`<button class="button primary small" ${actions[r.key]}>Play round</button>`:'';
  const enroll=r.joined?action('leave','Return to private progress'):active&&!r.gate?action('join','Join round',true):'';
  const relevant=active||r.joined||r.phase==='closing'||r.phase==='finished';
  return `<article class="calendar-card"><img decoding="async" loading="lazy" src="assets/portraits/${illustrations[r.key]}.webp" alt=""><div><div class="calendar-date">${describe(r.start)} – ${describe(r.end-1)} UTC</div><h3>${esc(r.name)}</h3><p>${r.joined?'Entered · '+num(r.score)+' / '+num(r.max)+' points':active?(r.gate?esc(r.gate):'Open for entry'):r.phase==='closing'?'Final standings':r.phase==='finished'?'Round finished':r.phase==='countdown'?'Starts soon':'Scheduled'}</p><div class="calendar-actions">${play}${enroll}${relevant?action('scores','Standings'):''}</div></div></article>`;
}
function board(data) {
  const current=data.rounds.filter(r=>r.phase==='active'||r.joined||r.phase==='closing');
  const upcoming=data.rounds.filter(r=>['upcoming','countdown'].includes(r.phase)).slice(0,8);
  const finished=data.rounds.filter(r=>r.phase==='finished'&&!r.joined).slice(-4).reverse();
  return `<section class="world-calendar"><h2 id="modal-title">World event calendar</h2><p>Join a shared UTC round to compete with other keepers. Your private campaign is saved while you play. Troops, yard damage and earned rewards carry over.</p>
    <h3>Current rounds</h3>${current.map(card).join('')||'<p>The next round opens soon.</p>'}
    <details><summary>Upcoming rounds</summary>${upcoming.map(card).join('')}</details>
    ${finished.length?`<details><summary>Recent results</summary>${finished.map(card).join('')}</details>`:''}</section>`;
}
export async function handleCalendar(el,{api,state,applyState,showModal,toast,navigationIntent,navigationCurrent}) {
  if (!state.user)return false;
  const n=navigationIntent(), key=el.dataset.key, start=Number(el.dataset.start), op=el.dataset.calendarOp;
  const owner=state.user.id;
  try {
    if(op==='scores') {
      const data=await api('eventLeaders',undefined,{key,start});if(!navigationCurrent(n,true))return true;
      const rows=data.leaders.map((r,i)=>`<tr><td>${i+1}</td><th scope="row">${esc(r.name)}</th><td>${num(r.score)}</td></tr>`).join('');
      showModal(`<section class="world-calendar"><h2 id="modal-title">Round standings</h2><p>Round starting ${describe(start)} UTC · Your score: ${num(data.score)}</p>${rows?`<table><thead><tr><th>Place</th><th>Keeper</th><th>Points</th></tr></thead><tbody>${rows}</tbody></table>`:'<p>No keeper has scored in this round yet.</p>'}<button class="button subtle" data-calendar-open>Back to calendar</button></section>`,'calendar');return true;
    }
    let data;
    if(op==='join'||op==='leave') {
      el.disabled=true;
      const reply=await api('eventRound',{op,key,start,revision:Number(el.dataset.revision)});
      if(owner!==state.user?.id)return true;
      if(!navigationCurrent(n,true))return true;
      applyState(reply.state);data=reply.calendar;
    }else{
      data=await api('eventCalendar');if(!navigationCurrent(n,true))return true;
    }
    showModal(board(data),'calendar');
  }catch(e){if(navigationCurrent(n,true)){el.disabled=false;toast(e.message,true)}}
  return true;
}
