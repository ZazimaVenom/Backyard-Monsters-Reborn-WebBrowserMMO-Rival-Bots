import {esc, num} from './ui.js?v=0.13.1';

const unlockText={121:'Defend wave 1 of Wild Monster Invasion I',131:'Defend wave 1 of Wild Monster Invasion II',135:'Unlock D.A.V.E. in your Monster Locker'};
export function decorationSelection(piece){return piece && {...piece,pieceId:piece.id,id:'decoration-'+piece.id,decoration:true};}
export function createDecorations({state,showModal,closeModal,choosePlacement,cancelPlacement,action,toast,selectBuilding}){
  let group='All', query='', currency='materials', placing=false, drawnCards='';
  const catalog=()=>state.catalog?.decorations||{};
  const home=()=>state.page==='yard'&&state.user&&!state.visiting&&!state.battle&&!state.worldSite;
  const held=()=>[...(state.base.decorations||[]),...(state.base.decorationStorage||[])];
  const thumbnail=(item,name)=>`<img src="assets/decorations/${Number(item)}.webp" loading="lazy" alt="${esc(name)}" decoding="async">`;
  function price(spec){
    if(!spec.shiny)return 'Free';
    if(currency==='shiny')return num(spec.shiny)+' Shiny';
    return Object.entries(spec.materials).map(([key,amount])=>num(amount)+' '+({twig:'twigs',pebble:'pebbles'}[key]||key)).join(' · ');
  }
  function eligible(item,spec){
    if(unlockText[item]&&!state.base.decorationUnlocks?.[item])return unlockText[item];
    const limit=spec.limit||(unlockText[item]?1:0);
    if(limit&&held().filter(p=>p.item===Number(item)).length>=limit)return 'Collection limit reached';
    if(spec.shiny&&(currency==='shiny'?(state.base.shiny||0)<spec.shiny:Object.entries(spec.materials).some(([k,v])=>(state.base.resources[k]||0)<v)))return currency==='shiny'?'More Shiny needed':'More materials needed';
    return '';
  }
  function cards(){
    const stored=state.base.decorationStorage||[];
    return Object.entries(catalog()).filter(([item,s])=>(group==='All'||group===s.group||group==='Storage'&&stored.some(p=>p.item===Number(item)))&&s.name.toLowerCase().includes(query.toLowerCase().trim())).map(([item,s])=>{
      const own=held().filter(p=>p.item===Number(item)).length, copies=stored.filter(p=>p.item===Number(item)),reason=eligible(item,s);
      return `<article class="decoration-card">${thumbnail(item,'')}<h3>${esc(s.name)}</h3><small>${esc(s.group)}${[121,131].includes(+item)&&own?' · Tier '+(held().find(p=>p.item===+item)?.tier||1):''}${own?' · '+own+' owned':''}</small>${copies.length?`<button class="button subtle small" data-decor-action="place" data-piece="${copies[0].id}" data-item="${item}">Place stored · ${copies.length}</button>`:''}${group!=='Storage'?`<button class="button primary small" data-decor-action="buy" data-item="${item}" ${reason?'disabled':''}>${esc(price(s))}</button>${reason?`<small>${esc(reason)}</small>`:''}`:''}</article>`;
    }).join('')||'<p class="subtle-note">No decorations match this view.</p>';
  }
  function refresh(){if(state.modal==='decorations'){const list=document.getElementById('decoration-grid');const next=cards();if(list&&next!==drawnCards){list.innerHTML=next;drawnCards=next;}const wallet=document.getElementById('decoration-wallet');if(wallet)wallet.textContent=num(state.base.shiny||0)+' Shiny';}}
  function show(){
    if(!home())return;
    const groups=['All','Storage',...new Set(Object.values(catalog()).map(s=>s.group))];
    drawnCards=cards();
    showModal(`<div class="decoration-heading"><h2 id="modal-title">Yard decorations</h2><span id="decoration-wallet">${num(state.base.shiny||0)} Shiny</span></div><p>Pick a piece, then click an open spot in your backyard. Return pieces to storage to use them again.</p><div class="decoration-controls"><label>Find a decoration<input id="decoration-search" type="search" placeholder="Name" value="${esc(query)}"></label><label>Collection<select id="decoration-group">${groups.map(k=>`<option ${group===k?'selected':''}>${esc(k)}</option>`).join('')}</select></label><label>Pay with<select id="decoration-currency"><option value="materials">Twigs and pebbles</option><option value="shiny" ${currency==='shiny'?'selected':''}>Shiny</option></select></label></div><div class="decoration-grid" id="decoration-grid">${drawnCards}</div>`,'decorations');
  }
  function input(el){
    if(state.modal!=='decorations')return;
    if(el.id==='decoration-search')query=el.value;
    else if(el.id==='decoration-group')group=el.value;
    else if(el.id==='decoration-currency')currency=el.value;
    else return;
    refresh();
  }
  function panel(b){
    const spec=catalog()[b.item];
    if(!spec)return '';
    return `<div class="decoration-detail"><div class="decoration-portrait">${thumbnail(b.item,spec.name)}<button class="panel-close icon-button" data-command="deselect" aria-label="Deselect decoration">×</button></div><div class="panel-content"><span class="eyebrow">${esc(spec.group)}${[121,131].includes(+b.item)?' · Victory tier '+(b.tier||1)+'/6':''}</span><h2>${esc(spec.name)}</h2>${home()?`<button class="button primary block" data-decor-action="move" data-piece="${b.pieceId}" data-item="${b.item}">Move decoration</button><button class="button subtle block" data-decor-action="store" data-piece="${b.pieceId}">Return to storage</button>`:''}</div></div>`;
  }
  async function click(el){
    const op=el.dataset.decorAction;
    if(!op)return false;
    if(!home())return true;
    if(op==='open'){group='All';query='';show();return true;}
    const id=Number(el.dataset.piece),item=Number(el.dataset.item);
    if(op==='store'){if(await action('decorationStore',{id},el)){selectBuilding(null);toast('Decoration returned to storage.');}return true;}
    if(!catalog()[item]||!['buy','place','move'].includes(op))return true;
    if(op==='buy'&&eligible(item,catalog()[item])){refresh();return true;}
    if(state.modal)closeModal();
    const bytes=new Uint8Array(16); crypto.getRandomValues(bytes);
    choosePlacement({mode:'decoration',op,item,id:'decoration-'+id,pieceId:id,currency,receipt:Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('')});
    return true;
  }
  async function place(pos,p){
    if(placing||!home())return;
    placing=true;
    try {
      const name={move:'decorationMove',place:'decorationPlace',buy:'decorationBuy'}[p.op];
      const data=await action(name,{item:p.item,id:p.pieceId,receipt:p.receipt,currency:p.currency,...pos});
      if(data&&state.placement===p){cancelPlacement();selectBuilding(null);toast(catalog()[p.item].name+' placed.');}
    } finally {placing=false;}
  }
  return {click,input,panel,place,refresh};
}
