import {esc,timeAgo} from './ui.js?v=0.13.0';

export function createYardMessages({state,api,action,showModal,closeModal,choosePlacement,cancelPlacement,toast,renderPanel,refreshHome,getRenderer,now}){
  let pending=false,selectedId=null;
  const yard=()=>state.visiting||state.base;
  const valid=()=>state.user&&!state.battle&&!state.worldSite&&!state.visiting?.tribe&&!state.visiting?.realm;
  const live=()=>valid()?(yard()?.signs||[]).filter(s=>s.expires>now()):[];
  const receipt=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
  const controls=()=>valid()?`<button class="button subtle block" data-yard-msg="signs">Yard signs${live().length?' · '+live().length:''}</button>`:'';
  function signList(){
    if(!valid())return;
    const own=yard().owner.id===state.user.id;
    const notes=live().map(s=>`<article class="yard-note"><strong>${esc(s.name)}</strong><p>${esc(s.text)}</p><small>Expires in ${Math.max(1,Math.ceil((s.expires-now())/3600))}h</small><div>${s.sender===state.user.id?`<button class="button subtle small" data-yard-msg="edit" data-note="${s.id}">Edit</button>`:''}${own||s.sender===state.user.id?`<button class="button subtle small" data-yard-msg="remove" data-note="${s.id}">Remove</button>`:''}</div></article>`).join('');
    showModal(`<h2 id="modal-title">Yard signs</h2><p>${own?'Visitors can leave a message here for 48 hours.':'Leave a 48-hour note for '+esc(yard().owner.name)+'.'}</p>${!own?'<button class="button primary" data-yard-msg="new">Leave a sign</button>':''}<div class="yard-notes">${notes||'<p>No signs in this yard.</p>'}</div>`,'yard-signs');
  }
  function editor(record=null){
    if(!valid()||(!record&&!state.visiting))return;
    selectedId=record?.id||null;
    showModal(`<h2 id="modal-title">${record?'Edit sign':'Leave a Simple Sign'}</h2><label class="sign-compose">Message<textarea id="yard-note-text" rows="3" maxlength="180" placeholder="Write a note">${esc(record?.text||'')}</textarea></label><p>${record?'Changes keep the original expiry.':'Placing this sign costs 1,000 each of twigs, pebbles, putty and goo. It stays for 48 hours.'}</p><button class="button primary" data-yard-msg="${record?'save':'aim'}">${record?'Save message':'Choose a spot'}</button>`,'yard-sign-editor');
  }
  async function submit(op,token,text,pos={}){
    if(pending||!valid())return false;
    const owner=state.user.id,peer=yard().owner.id,epoch=state.navigationEpoch;
    pending=true;
    try{
      const response=await api('signChange',{op,token,text,yard:peer,...pos});
      if(state.user?.id!==owner||state.navigationEpoch!==epoch||!valid()||yard().owner.id!==peer)return false;
      yard().signs=response.signs;getRenderer().setBase(yard());renderPanel();
      if(op==='post')await refreshHome();
      return true;
    }catch(e){if(state.user?.id===owner)toast(e.message,true);return false;}
    finally{pending=false;}
  }
  async function place(pos,p){
    if(yard()?.owner?.id!==p.peer||!state.visiting)return;
    if(await submit('post',p.receipt,p.text,pos)){
      if(state.placement===p){cancelPlacement();toast('Your sign is in the yard.');}
    }
  }
  function radio(){
    const r=state.base?.radio;if(!state.user||!r?.ready||state.visiting||state.battle)return;
    const notes=r.items.map(m=>`<article class="yard-note"><strong>${m.category==='attacks'?'Attack alert':'Yard news'}</strong><p>${esc(m.message)}</p><small>${esc(timeAgo(m.at))}</small>${m.battle?`<button class="button subtle small" data-yard-msg="report" data-log="${m.battle}">Open invasion</button>`:''}</article>`).join('');
    showModal(`<h2 id="modal-title">Radio Tower</h2><p>Receive alerts in this yard’s local inbox.</p><div class="radio-prefs"><label><input type="checkbox" id="radio-attacks" ${r.attacks?'checked':''}> Attack alerts</label><label><input type="checkbox" id="radio-news" ${r.news?'checked':''}> Construction news</label><button class="button primary small" data-yard-msg="tune">Save preferences</button></div><div class="radio-summary"><h3>Inbox · ${r.unread} unread</h3>${r.unread?`<button class="button subtle small" data-yard-msg="read" data-through="${r.items[0].id}">Mark read</button>`:''}</div><div class="yard-notes">${notes||'<p>No broadcasts received yet.</p>'}</div>`,'yard-radio');
  }
  async function click(el){
    const op=el.dataset.yardMsg;if(!op)return false;
    if(op==='radio'){radio();return true;}
    if(op==='report'){closeModal();document.dispatchEvent(new CustomEvent('yard-radio-report',{detail:Number(el.dataset.log)}));return true;}
    if(op==='read'||op==='tune'){
      const data=op==='read'?{through:Number(el.dataset.through)}:{attacks:document.getElementById('radio-attacks').checked,news:document.getElementById('radio-news').checked};
      const epoch=state.modalEpoch;
      if(await action(op==='read'?'radioRead':'radioTune',data,el)&&state.modalEpoch===epoch)radio();
      return true;
    }
    if(!valid())return true;
    if(op==='signs'){signList();return true;}
    if(op==='new'){editor();return true;}
    const note=live().find(s=>s.id===el.dataset.note);
    if(op==='edit'&&note){editor(note);return true;}
    if(op==='remove'&&note){if(await submit('remove',note.id,''))signList();return true;}
    if(op==='aim'||op==='save'){
      const text=document.getElementById('yard-note-text')?.value.trim();if(!text){toast('Write a message first.');return true;}
      if(op==='save'){if(await submit('edit',selectedId,text))signList();}
      else{const peer=yard().owner.id;closeModal();choosePlacement({mode:'yardSign',receipt:receipt(),peer,text});}
    }
    return true;
  }
  return {click,controls,signList,place,radio};
}
