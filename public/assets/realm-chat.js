import {esc} from './ui.js?v=0.13.1';

export async function openRealmChat({api,state,showModal,toast}){
  const owner=state.user?.id,epoch=state.modalEpoch;
  if(!owner)return;
  const t=window.yardLanguage?.t??(v=>v);
  const names={mr1:'Map Room 1',mr2:'Map Room 2',mr3:'Map Room 3',inferno:'Inferno'};
  let first;
  try{first=await api('realmChat',undefined,{room:state.visiting?.infernoHome?'inferno':''});}
  catch(e){if(state.modalEpoch===epoch)toast(e.message,true);return;}
  if(state.modalEpoch!==epoch||state.user?.id!==owner)return;
  showModal(`<section class="realm-chat"><h2 id="modal-title">${esc(t('Realm chat'))}</h2><div class="chat-channels"></div><div class="chat-stream" role="log" aria-live="polite"></div><form class="chat-compose"><input class="form-input" maxlength="200" required autocomplete="off" aria-label="${esc(t('Message'))}" placeholder="${esc(t('Message'))}"><button class="button primary" type="submit">${esc(t('Send'))}</button></form><details><summary>${esc(t('Muted keepers'))}</summary><div class="chat-muted"></div></details></section>`,'realm-chat');
  const pane=document.querySelector('.realm-chat'),stream=pane.querySelector('.chat-stream'),form=pane.querySelector('form'),input=form.querySelector('input');
  const valid=()=>pane.isConnected&&state.modal==='realm-chat'&&state.user?.id===owner;
  let room=first.room,serial=0,busy=false,pending=null,last='';
  function show(data){
    if(!valid())return;
    room=data.room;
    pane.querySelector('.chat-channels').innerHTML=data.channels.map(key=>`<button class="button subtle small" type="button" data-room="${esc(key)}" aria-pressed="${room===key}">${esc(names[key])}</button>`).join('');
    const html=data.messages.map(m=>`<article><header><b>${esc(m.name)}</b> <time>${esc(new Date(m.at*1000).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}))}</time>${owner===m.sender?'':` <button class="quiet-button" data-mute="${Number(m.sender)}">${esc(t('Mute'))}</button>`}</header><p>${esc(m.body)}</p></article>`).join('')||`<p>${esc(t('No messages yet.'))}</p>`;
    if(html!==last){const bottom=stream.scrollHeight-stream.scrollTop-stream.clientHeight<28||!last;stream.innerHTML=html;last=html;if(bottom)stream.scrollTop=stream.scrollHeight;}
    pane.querySelector('.chat-muted').innerHTML=Object.entries(data.muted).map(([id,name])=>`<button class="button subtle small" data-unmute="${Number(id)}">${esc(t('Unmute'))} · ${esc(name)}</button>`).join('');
  }
  async function refresh(next=room){
    const ticket=++serial;
    try{const data=await api('realmChat',undefined,{room:next});if(ticket===serial)show(data);}
    catch(e){if(valid()&&ticket===serial)toast(e.message,true);}
  }
  async function change(inputData){
    if(busy)return false;busy=true;const ticket=++serial;
    try{const data=await api('realmChatAct',{room,...inputData});if(ticket===serial)show(data);return true;}
    catch(e){if(valid())toast(e.message,true);return false;}
    finally{busy=false;}
  }
  form.onsubmit=async e=>{
    e.preventDefault();const body=input.value.trim();if(!body||busy)return;
    if(!pending||pending.body!==body||pending.room!==room)pending={room,body,token:Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('')};
    if(await change({op:'send',...pending})){if(input.value.trim()===body)input.value='';pending=null;}
  };
  pane.onclick=async e=>{
    const b=e.target.closest('button');if(!b||busy)return;
    if(b.dataset.room){await refresh(b.dataset.room);return;}
    if(b.dataset.mute||b.dataset.unmute)await change({op:b.dataset.mute?'mute':'unmute',peer:Number(b.dataset.mute||b.dataset.unmute)});
  };
  show(first);
  async function poll(){if(!valid())return;if(!busy&&!document.hidden)await refresh();if(valid())setTimeout(poll,2500);}
  setTimeout(poll,2500);
}
