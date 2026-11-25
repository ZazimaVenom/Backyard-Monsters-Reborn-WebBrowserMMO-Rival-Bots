import {esc,num} from './ui.js?v=0.13.1';

const label = {twig:'twigs',pebble:'pebbles',putty:'putty',goo:'goo'};
const button = (action,text,id=0,link=0) => `<button type="button" class="button subtle small" data-neighbor="${action}" data-id="${id}" data-link="${link}">${text}</button>`;
const row = (name,detail,actions) => `<li><div><b>${esc(name)}</b>${detail ? `<small>${esc(detail)}</small>` : ''}</div><div class="neighbor-actions">${actions}</div></li>`;
const list = (title,rows,empty='') => rows.length||empty ? `<section><h3>${title}</h3>${rows.length?`<ul>${rows.join('')}</ul>`:`<p>${empty}</p>`}</section>`:'';
const token = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), v=>v.toString(16).padStart(2,'0')).join('');

export function createNeighbors({state,api,showModal,closeModal,visit,synchronizeHome,toast,audio}) {
    let revision=0, current, search='', tab='friends', peer=null, before=null, draftKey=null;
    const body=()=>document.querySelector('#modal-body');
    const active=(id,rev)=>state.user?.id===id && state.modal==='neighbors' && revision===rev;
    function render(markup) {
        body().innerHTML=markup;
        body().querySelector('.neighbor-tabs [aria-current="page"]')?.setAttribute('tabindex','0');
    }
    function display() {
        const {friends,gifts,threads,matches,now,owner}=current;
        const incoming=friends.filter(f=>!Number(f.accepted)&&Number(f.requested_by)!==Number(owner));
        const outgoing=friends.filter(f=>!Number(f.accepted)&&Number(f.requested_by)===Number(owner));
        const accepted=friends.filter(f=>Number(f.accepted));
        const inbox=threads.reduce((sum,t)=>sum+Number(t.unread),0);
        const tabs=[['friends','Friends',incoming.length],['gifts','Mystery sacks',gifts.length],['mail','Mail',inbox]];
        let html='';
        if(tab==='friends'){
            html='<form data-neighbor-form="search" class="neighbor-search"><label>Find a player keeper<input name="search" minlength="2" maxlength="32" placeholder="Keeper name" value="'+esc(search)+'" required></label><button class="button primary">Search</button></form>';
            if(search)html+=list('Search results',matches.map(k=>{
                const f=friends.find(f=>Number(f.id)===Number(k.id));
                return row(k.name,f?(Number(f.accepted)?'Friend':'Invitation pending'):'',f?'':button('request','Add friend',k.id));
            }),'No matching player keepers.');
            html+=list('Invitations',incoming.map(f=>row(f.name,'Wants to be friends',button('accept','Accept',f.id,f.link_id)+button('remove','Decline',f.id,f.link_id))));
            html+=list('Your friends',accepted.map(f=>row(f.name,'',button('visit','Visit',f.id)+button('thread','Write',f.id)+(Number(f.gift_after)<=now?button('sendGift','Send sack',f.id):'<span class="neighbor-sent">Sack sent</span>')+button('confirmRemove','Unfriend',f.id,f.link_id))),'Find another player by keeper name to exchange gifts and messages.');
            html+=list('Sent invitations',outgoing.map(f=>row(f.name,'Awaiting reply',button('remove','Withdraw',f.id,f.link_id))));
        } else if(tab==='gifts'){
            html=list('Sacks from friends',gifts.map(g=>row(g.name,g.remaining===null?'Mystery sack':num(g.remaining)+' '+label[g.resource]+' waiting for storage',button('openGift',g.remaining===null?'Open sack':'Collect remainder',g.id))),'Your friends can send you one mystery sack each day.');
        } else {
            html=list('Conversations',threads.map(t=>row(t.name,Number(t.unread)?num(t.unread)+' unread':'',button('thread','Open',t.id))),'Choose Write beside a friend to start a conversation.');
        }
        render(`<div class="neighbors"><h2 id="modal-title">Neighbors</h2><nav class="neighbor-tabs" aria-label="Neighbor sections">${tabs.map(([id,name,count])=>`<button class="button subtle small" data-neighbor="tab" data-tab="${id}" ${tab===id?'aria-current="page"':''}>${name}${count?' ('+num(count)+')':''}</button>`).join('')}</nav>${html}</div>`);
    }
    async function load() {
        const id=state.user?.id, rev=++revision;
        const data=await api('neighbors',undefined,{search});
        if(active(id,rev)){current=data;peer=null;display();}
    }
    async function thread(other, older=null) {
        const id=state.user?.id, rev=++revision;
        const data=await api('neighborThread',undefined,{keeper:other,...(older?{before:older}:{})});
        if(!active(id,rev))return;
        const friend=current.friends.find(f=>Number(f.id)===other&&Number(f.accepted));
        const name=friend?.name||current.threads.find(t=>Number(t.id)===other)?.name||'Keeper';
        peer=other;before=data.messages[0]?.id;draftKey=token();
        const note=m=>`<li class="${Number(m.sender_id)===Number(id)?'own':''}"><small>${Number(m.sender_id)===Number(id)?'You':esc(name)} · ${esc(new Date(Number(m.sent_at)*1000).toLocaleString())}</small><p>${esc(m.body)}</p></li>`;
        const compose=friend&&!older?`<form data-neighbor-form="mail"><label>Message<textarea name="message" maxlength="1200" rows="3" required></textarea></label><button class="button primary">Send message</button></form>`:'';
        render(`<div class="neighbors"><h2 id="modal-title">${esc(name)}</h2><div class="neighbor-actions">${button('back','Back to neighbors')}${data.older?button('older','Earlier messages',other):''}${older?button('thread','Latest messages',other):''}</div><ul class="neighbor-thread">${data.messages.map(note).join('')||'<li>Start a conversation.</li>'}</ul>${compose}</div>`);
        const through=data.messages.at(-1)?.id;
        if(through&&!older)await api('neighborAction',{kind:'read',keeper:other,through});
    }
    async function open(){
        const id=state.user?.id;if(!id)return;
        search='';tab='friends';peer=null;current=null;
        showModal('<div class="neighbors"><h2 id="modal-title">Neighbors</h2><p>Loading your neighbors…</p></div>','neighbors');
        body().removeEventListener('click',click);body().addEventListener('click',click);
        body().removeEventListener('submit',submit);body().addEventListener('submit',submit);
        try{await load();}catch(e){if(state.user?.id===id&&state.modal==='neighbors')toast(e.message,true);}
    }
    async function click(event){
        const el=event.target.closest('[data-neighbor]');if(!el||el.disabled||state.modal!=='neighbors')return;
        const owner=state.user?.id, what=el.dataset.neighbor, other=Number(el.dataset.id), link=Number(el.dataset.link);
        el.disabled=true;
        try{
            if(what==='tab'||what==='back'){if(what==='tab')tab=el.dataset.tab;await load();return;}
            if(what==='visit'){closeModal();await visit(other);return;}
            if(what==='thread'||what==='older'){await thread(other,what==='older'?before:null);return;}
            if(what==='confirmRemove'){el.textContent='Confirm unfriend';el.dataset.neighbor='remove';return;}
            const rev=revision;
            const result=await api('neighborAction',what==='openGift'?{kind:what,gift:other}:{kind:what,keeper:other,link});
            if(!active(owner,rev))return;
            if(what==='openGift'){
                if(result.credited>0)audio.effect('sack');
                toast(`${num(result.credited)} ${label[result.resource]} collected.${result.remaining?' Room needed for '+num(result.remaining)+' more.':''}`);
                await synchronizeHome();if(!active(owner,rev))return;
            } else if(what==='sendGift'){toast('Mystery sack delivered.');}
            await load();
        }catch(e){if(state.user?.id===owner&&state.modal==='neighbors')toast(e.message,true);}
        finally{el.disabled=false;}
    }
    async function submit(event){
        const form=event.target;if(!form.dataset.neighborForm||state.modal!=='neighbors')return;
        event.preventDefault();const owner=state.user?.id, rev=revision, el=form.querySelector('button');if(el.disabled)return;
        el.disabled=true;
        try{
            if(form.dataset.neighborForm==='search'){search=form.elements.search.value.trim();await load();return;}
            const input=form.elements.message, message=input.value.trim();
            if(!message)return;
            // A retry of the same draft after a lost response reuses the same key.
            if(input.dataset.previous!==undefined&&input.dataset.previous!==message)draftKey=token();
            input.dataset.previous=message;
            await api('neighborAction',{kind:'sendMail',keeper:peer,body:message,token:draftKey});
            if(active(owner,rev))await thread(peer);
        }catch(e){if(active(owner,rev))toast(e.message,true);}
        finally{el.disabled=false;}
    }
    return {open};
}
