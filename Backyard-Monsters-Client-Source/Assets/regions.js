import {esc, num, duration} from './ui.js?v=0.13.1';

const token=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');
const btn=(op,label,disabled=false,attrs='')=>`<button type="button" class="button subtle small" data-region-do="${op}" ${attrs} ${disabled?'disabled':''}>${esc(label)}</button>`;
export function createRegions({state,api,showModal,closeModal,toast,worldMap,navigate,isCurrent,refreshHome}) {
  let data=null,pending=null,busy=false,owner=null;
  const name=id=>data.regions.find(r=>r.id===id)?.name||'Region';
  const wait=()=>Math.max(0,data.cooldown-(data.now+Math.floor((Date.now()-data.loadedAt)/1000)));
  const title=(label,text)=>`<header class="region-intro"><span class="eyebrow">${esc(label)}</span><h2 id="modal-title">${esc(text)}</h2></header>`;
  function board() {
    if (!data || owner!==state.user?.id) return;
    pending=null;
    const regionRows=data.regions.map(r=>{
      const required=r.id===1?2:r.mapRoom,locked=data.level<required||wait()>0;
      const desc=r.mapRoom===2?'Build and offer frontier outposts.':r.id===1?'Original local home sectors.':'Claim resources and strongholds. Six defenders surround a new home.';
      return `<article class="region-row"><div><h3>${esc(r.name)}</h3><small>Map Room ${r.mapRoom} · ${num(r.keepers)} keepers${r.id===data.homeRegion?' · Your region':''}</small><p>${desc}</p></div><div>${btn('view','Explore',false,`data-id="${r.id}"`)}${btn('move',locked&&data.level<required?'Requires Map Room '+required:r.id===data.homeRegion?'Find new sector':'Move here',locked,`data-id="${r.id}"`)}</div></article>`;
    }).join('');
    const center=worldMap.snapshot?.center,viewport=worldMap.snapshot?.region;
    const bookmarks=data.bookmarks.map((b,i)=>`<article class="region-row"><div><strong>${esc(b.label)}</strong><small>${esc(name(b.region))} · ${b.x}, ${b.y}</small></div><div>${btn('jump','View',false,`data-mark="${i}"`)}${btn('forget','Remove',false,`data-mark="${i}"`)}</div></article>`).join('');
    const reserved=data.sent.map(s=>`<article class="region-row"><div><strong>Outpost #${s.site}</strong><small>Offered to ${esc(data.friends.find(f=>f.id===s.recipient)?.name||'a keeper')}</small></div>${btn('withdraw','Withdraw invitation',false,`data-site="${s.site}" data-offer="${esc(s.token)}"` )}</article>`).join('');
    const contacts=data.friends.map(f=>`<option value="${f.id}">${esc(f.name)} · ${esc(name(f.region))}</option>`).join('');
    const outposts=data.outposts.map(s=>`<option value="${s.id}">${s.x}, ${s.y} · #${s.id}</option>`).join('');
    const near=data.friends.filter(f=>data.regions.find(r=>r.id===f.region)?.mapRoom===3);
    const routes=near.length?`<section><h3>Relocate near a friend</h3><label>Keeper<select id="region-near">${near.map(f=>`<option value="${f.id}">${esc(f.name)} · ${esc(name(f.region))}</option>`).join('')}</select></label>${btn('near','Review relocation',!!wait())}</section>`:'';
    const offers=data.invites.map(o=>`<article class="region-row"><div><strong>${esc(o.senderName)}</strong><p>${esc(name(o.region))} · ${o.x}, ${o.y}</p></div><div>${btn('offer','Review invitation',!!wait(),`data-offer="${esc(o.token)}"`)}${btn('decline','Decline',false,`data-offer="${esc(o.token)}"`)}</div></article>`).join('');
    showModal(`${title('STRATEGIC MAP','Regions and relocation')}<p>Your backyard is in <strong>${esc(name(data.homeRegion))}</strong> at ${data.x}, ${data.y}.</p>${wait()?`<p class="subtle-note">Next move in ${duration(wait())}.</p>`:''}
      <div class="region-list">${regionRows}</div>
      <section><h3>Map bookmarks</h3>${bookmarks}${center&&viewport?`<label>Name this position<input id="region-bookmark-name" maxlength="24" placeholder="${esc(name(viewport))} · ${center.q}, ${center.r}"></label>${btn('bookmark','Save current position')}`:''}</section>
      ${reserved?`<section><h3>Invitations you sent</h3>${reserved}</section>`:''}
      ${offers?`<section><h3>Outpost invitations</h3>${offers}</section>`:''}
      ${routes}
      ${outposts?`<section><h3>Frontier outposts</h3><label>Your outpost<select id="region-outpost">${outposts}</select></label>${btn('own','Move onto this outpost',!!wait())}${contacts?`<label>Invite a friend<select id="region-friend">${contacts}</select></label>${btn('invite','Offer this outpost')}`:''}</section>`:''}`, 'regions');
  }
  async function open() {
    if(!state.user||state.battle?.status==='active')return;
    const n=navigate(),user=state.user.id;
    try { const fresh=await api('worldRegions');if(!isCurrent(n,true)||state.user?.id!==user)return;
      owner=user;data={...fresh,loadedAt:Date.now()};board();
    }catch(e){if(isCurrent(n,true))toast(e.message,true);}
  }
  function confirm(input,region,contact) {
    const invite=input.op==='invite',paid=['own','accept'].includes(input.op);
    const loses=region!==data.homeRegion||input.op==='accept';
    const regionName=esc(name(region));
    const enter=invite?'Offer this outpost':paid?'Relocate onto this outpost':input.op==='near'?'Move near '+esc(contact):'Move your backyard';
    const cost=paid?data.price[input.op==='own'?'own':'offer']:null;
    pending={...input,revision:data.revision,estateKey:data.estateKey,request:token()};
    showModal(`${title('REGIONAL MAP',invite?'Confirm invitation':'Review relocation')}<p>${enter} ${invite?'to '+esc(contact):'in <strong>'+regionName+'</strong>'}.</p>
      ${invite?'<p>Your friend can accept or decline from Regions. Acceptance gives them the outpost’s position for their backyard.</p>':`<p>Your main yard, army, resources and rank travel with you.</p>${loses&&data.estate?`<p><strong>${num(data.estate)} owned territories will be released.</strong> Their stationed monsters and improvements remain behind.</p>`:''}${paid?'<p>The destination outpost is surrendered. Frontier moves start a 24-hour cooldown.</p>':data.regions.find(r=>r.id===region)?.mapRoom===2?'<p>Moving into the Frontier starts a 24-hour cooldown. Random relocation from a Frontier requires giving up your outposts first.</p>':''}` }
      ${cost?`<div class="region-buttons">${btn('confirm','Pay '+num(cost.resource)+' of each resource',false,'data-pay="resources"')}${btn('confirm','Pay '+num(cost.shiny)+' Shiny',false,'data-pay="shiny"')}</div>`:btn('confirm',invite?'Send invitation':'Confirm free move')}${btn('back','Back to regions')}`, 'regions-confirm');
  }
  async function change(input) {
    if(busy||owner!==state.user?.id)return;
    const n=navigate();busy=true;
    try{
      const fresh=await api('worldRelocate',input);
      if(!isCurrent(n,true)||owner!==state.user?.id)return;
      data={...fresh,loadedAt:Date.now()};
      if(['invite','decline','withdraw'].includes(input.op)){board();toast(input.op==='invite'?'Outpost invitation sent.':input.op==='withdraw'?'Outpost invitation withdrawn.':'Invitation declined.');}
      else{closeModal();await worldMap.changeRegion(data.homeRegion);await worldMap.centerHome();await refreshHome();toast('Your backyard has arrived in '+name(data.homeRegion)+'.');}
    }catch(e){if(isCurrent(n,true))toast(e.message,true);}
    finally {busy=false;}
  }
  async function click(el){
    if(el.hasAttribute('data-region-open')){await open();return true;}
    const op=el.dataset.regionDo;if(!op)return false;
    if(!data||owner!==state.user?.id||busy)return true;
    const id=Number(el.dataset.id),get=s=>Number(document.querySelector(s)?.value)||0;
    if(op==='jump'){const m=data.bookmarks[Number(el.dataset.mark)];if(m){closeModal();await worldMap.changeRegion(m.region);await worldMap.panTo(m.x,m.y);}return true;}
    if(op==='forget'||op==='bookmark'){
      const position=worldMap.snapshot,mark=data.bookmarks[Number(el.dataset.mark)];
      const input=op==='forget'&&mark?{op:'remove',region:mark.region,q:mark.x,r:mark.y}:position?{op:'save',region:position.region,...position.center,label:document.querySelector('#region-bookmark-name')?.value||''}:null;
      if(input){const n=navigate();busy=true;try{const fresh=await api('worldBookmark',input);if(isCurrent(n,true)){data={...fresh,loadedAt:Date.now()};board();}}catch(e){if(isCurrent(n,true))toast(e.message,true);}finally{busy=false;}}return true;
    }
    if(op==='withdraw'){await change({op,site:Number(el.dataset.site),offer:el.dataset.offer,request:token(),revision:data.revision});return true;}
    if(op==='back')board();
    if(op==='view'){closeModal();await worldMap.changeRegion(id);}
    if(op==='move')confirm({op:'relocate',region:id},id);
    if(op==='near'){const f=data.friends.find(f=>f.id===get('#region-near'));if(f)confirm({op,friend:f.id},f.region,f.name);}
    if(op==='own')confirm({op,site:get('#region-outpost')},data.homeRegion);
    if(op==='invite'){const f=data.friends.find(f=>f.id===get('#region-friend'));if(f)confirm({op,friend:f.id,site:get('#region-outpost')},data.homeRegion,f.name);}
    if(op==='offer'){const o=data.invites.find(v=>v.token===el.dataset.offer);if(o)confirm({op:'accept',offer:o.token},o.region,o.senderName);}
    if(op==='decline')await change({op,offer:el.dataset.offer,request:token(),revision:data.revision});
    if(op==='confirm'&&pending&&state.modal==='regions-confirm')await change({...pending,payment:el.dataset.pay||'resources'});
    return true;
  }
  return {click,open};
}
