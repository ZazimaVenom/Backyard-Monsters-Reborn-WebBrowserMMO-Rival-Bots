import {esc,icon,num} from './ui.js?v=0.13.0';

export function createAscension({state,api,home,showModal,closeModal,navigationIntent,navigationCurrent,sync,toast,buttonBusy}){
  let data=null,chosen={},owner=null,pending=false,request=null;
  const token=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');
  const space=()=>Object.entries(chosen).reduce((total,[type,n])=>total+data.species[type].space*n,0);
  function reset(){data=null;chosen={};owner=null;request=null;pending=false}
  function markup(){
    if(!data.available)return `<header class="inferno-hero"><span class="eyebrow">MONSTER HOUSING</span><h2 id="modal-title">Ascension</h2></header>
      <p>Clear the 13 Moloch Descent camps to move native monsters from your underground Compound into your backyard.</p><div class="stat-line"><span>Descent camps cleared</span><b>${num(data.cleared)} / 13</b></div>
      <button class="button primary" data-inferno-open>Open Inferno</button>`;
    const used=space(),rows=Object.entries(data.species).filter(([,m])=>m.cavern>0).map(([type,m])=>{
      const q=chosen[type]||0;
      return `<article class="ascension-row"><img src="assets/art/${esc(m.art)}" alt=""><div><b>${esc(m.name)}</b><small>Level ${num(m.level)} · ${m.space} space · ${num(m.cavern)} in Compound</small></div>
        <div class="ascension-step"><button class="button subtle small" data-ascend-remove="${type}" aria-label="Remove one ${esc(m.name)}" ${q?'':'disabled'}>−</button><output>${q}</output><button class="button subtle small" data-ascend-add="${type}" aria-label="Add one ${esc(m.name)}" ${q<m.cavern&&data.used+used+m.space<=data.capacity?'':'disabled'}>+</button></div></article>`;
    }).join('');
    let recent='';
    if(data.last){const moved=Object.entries(data.last.troops).map(([type,n])=>num(n)+' '+esc(data.species[type].name)).join(' · ');recent=`<p class="subtle-note">Most recent arrival · ${moved}</p>`;}
    return `<header class="inferno-hero"><span class="eyebrow">COMPOUND → BACKYARD</span><h2 id="modal-title">Ascension</h2></header>
      <p>Bring ready Inferno monsters into surface Housing instantly and for free. This is a one-way transfer. Monsters left out of your Flinger stay in the backyard.</p>
      <div class="stat-line"><span>Surface housing after transfer</span><b>${num(data.used+used)} / ${num(data.capacity)}</b></div>
      <div class="ascension-roster">${rows||'<p>Your Compound has no ready monsters. Hatch more in the underground Incubator.</p>'}</div>
      <button class="button primary block" data-ascend-confirm ${used?'':'disabled'}>${icon('arrow')}Ascend selected monsters</button>${recent}`;
  }
  function paint(reopen=false){
    const current=state.modal==='ascension',body=document.querySelector('#modal-body');
    if(current&&!reopen&&body){const sh=body.closest('.modal-card'),scroll=sh?.scrollTop||0;body.innerHTML=markup();if(sh)sh.scrollTop=scroll;}
    else showModal(markup(),'ascension');
  }
  async function open(){
    if(!state.user||state.battle?.status==='active')return;
    if(state.visiting||state.battle||state.page!=='yard'){
      const id=state.user.id;await home();if(id!==state.user?.id||state.page!=='yard'||state.visiting||state.battle)return;
    }
    const n=navigationIntent();try{
      const fresh=await api('ascension');if(!navigationCurrent(n,true))return;
      data=fresh;owner=state.user.id;chosen={};request=token();paint(true);
    }catch(e){if(navigationCurrent(n,true))toast(e.message,true)}
  }
  function edit(type,delta){
    const m=data.species[type];if(!m||pending)return;
    const quantity=(chosen[type]||0)+delta;
    if(quantity<0||quantity>m.cavern||delta>0&&data.used+space()+m.space>data.capacity)return;
    if(quantity)chosen[type]=quantity;else delete chosen[type];request=token();paint();
    const focus=document.querySelector(`button[data-ascend-${delta>0?'add':'remove'}="${type}"]:not(:disabled)`);
    focus?.focus?.({preventScroll:true});
  }
  async function send(el){
    if(pending||!Object.keys(chosen).length)return;
    const n=navigationIntent();pending=true;buttonBusy(el,true);
    try{
      const fresh=await api('ascendMonsters',{troops:{...chosen},requestId:request,previousId:data.last?.requestId??null});
      if(!navigationCurrent(n,true))return;
      data=fresh;chosen={};request=token();paint();await sync();toast('Your Inferno monsters have arrived in surface Housing.');
    }catch(e){if(navigationCurrent(n,true))toast(e.message,true)}
    finally{buttonBusy(el,false);pending=false}
  }
  async function handleClick(el){
    if(el.hasAttribute('data-ascension-open')){await open();return true}
    if(state.modal!=='ascension'||state.user?.id!==owner||!data)return false;
    if(el.dataset.ascendAdd)edit(el.dataset.ascendAdd,1);
    else if(el.dataset.ascendRemove)edit(el.dataset.ascendRemove,-1);
    else if(el.hasAttribute('data-ascend-confirm'))await send(el);
    else return false;
    return true;
  }
  return {handleClick,reset};
}
