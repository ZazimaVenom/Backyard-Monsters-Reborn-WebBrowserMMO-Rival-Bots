import {num, duration} from './ui.js?v=0.13.1';

const names = ['Ruby cap','Amber chanterelle','Violet bell','Copper bolete','Mooncap cluster'];
const cap = item => `<span aria-hidden="true" class="mushroom-art" style="background-position:${(Number(item.variant)-1)*25}% bottom"></span>`;

export function createMushrooms({state,showModal,action,toast,now,refreshHome,sound}){
  let selected=null, busy=false, pending=null;
  const home=()=>state.user && !state.visiting && !state.battle && !state.worldSite;
  function content(){
    const patch=state.base.mushrooms, jobs=state.base.construction.available;
    const choice=patch.items.find(item=>item.id===selected);
    const receipt=patch.lastPick;
    const status=receipt ? `Last pick · ${receipt.shiny?num(receipt.shiny)+' Shiny found':'ordinary mushroom'}` : 'Some mushrooms hide Shiny beneath their roots.';
    const cards=(choice?[choice]:patch.items).map(item=>{
      const title=names[item.variant-1], picking=!!item.readyAt;
      const text=picking?'Picking…':choice?'Send a worker':'Pick';
      return `<button type="button" class="mushroom-card" data-mushroom-pick="${item.id}" aria-label="${text} ${title} ${item.id}" ${busy||!jobs||picking?'disabled':''}>${cap(item)}<b>${title}</b><small>${text}</small></button>`;
    }).join('');
    return `<div class="mushroom-heading"><div><h2 id="modal-title">${choice?names[choice.variant-1]:'Yard mushrooms'}</h2><p>Send a free worker to pick a mushroom. A golden find gives you 3 or 8 Shiny.</p></div><strong>${num(state.base.shiny||0)} Shiny</strong></div><div class="mushroom-meter"><span>${num(patch.picked)} picked · ${num(patch.golden)} golden</span><span>${jobs?num(jobs)+' workers free':'All workers busy'}</span></div><p role="status" class="mushroom-status">${status}</p><div class="mushroom-grid">${cards}</div>${!cards&&patch.nextGrowthAt?`<p>Next mushroom in <span data-countdown="${patch.nextGrowthAt}">${duration(patch.nextGrowthAt-now())}</span>.</p>`:''}`;
  }
  function open(item=null){
    if(item && state.visiting && !state.battle){
      showModal(`<div class="mushroom-heading"><div><h2 id="modal-title">${names[item.variant-1]}</h2><p>This mushroom grows in the yard you are visiting. Its keeper can send a worker to pick it.</p></div>${cap(item)}</div>`,'mushroom-visit');
      return;
    }
    if(!home()||!state.base?.mushrooms)return;
    selected=item?.id||null;
    showModal(content(),'mushrooms');
  }
  function refresh(){
    if(pending && pending.owner!==state.user?.id)pending=null;
    if(!home())return;
    const patch=state.base?.mushrooms;
    if(!patch)return;
    if(pending && patch.lastPick && !patch.items.some(item=>item.id===pending.id)){
      if(patch.lastPick.id===pending.id){
        sound('collect');
        toast(patch.lastPick.shiny?`Golden mushroom! +${patch.lastPick.shiny} Shiny.`:'Mushroom picked.');
      }
      pending=null;
    }
    if(state.modal!=='mushrooms')return;
    const focus=document.activeElement?.dataset?.mushroomPick;
    document.querySelector('#modal-body').innerHTML=content();
    if(focus)document.querySelector(`[data-mushroom-pick="${Number(focus)}"]`)?.focus({preventScroll:true});
  }
  async function click(el){
    if(el.id==='mushrooms-open'){open();return true;}
    if(el.dataset.mushroomPick===undefined)return false;
    if(!home()||busy)return true;
    const owner=state.user.id, id=Number(el.dataset.mushroomPick);
    busy=true;
    try{
      const result=await action('mushroomPick',{id},el);
      if(result?.mushroomPick?.status==='picking' && owner===state.user?.id){
        pending={id,owner};
        setTimeout(()=>{if(home()&&state.user?.id===owner)refreshHome();},Math.max(100,(result.mushroomPick.readyAt-now())*1000+220));
      }
    }finally{busy=false;refresh();}
    return true;
  }
  return {open,refresh,click};
}
