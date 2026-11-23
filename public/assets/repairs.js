import {icon, num} from './ui.js?v=0.13.0';

/** Home recovery always uses the server's confirmed snapshot, never optimistic HP. */
export function createHomeRepair({state, request, applyState, refreshHome, renderPanel, toast, audio, isPlanning, showRestored}) {
  const $=selector=>document.querySelector(selector);
  let pending=null;
  const ownsHome=()=>!!state.user&&state.base?.owner?.id===state.user.id&&state.page==='yard'&&!state.visiting&&!state.worldSite&&!state.battle&&!state.activeBattle&&!state.battleBusy&&!isPlanning();
  const busy=()=>!!pending;
  const canRepair=building=>ownsHome()&&!pending&&Number(building?.level)>0&&Number(building.hp)<Number(building.max_hp);

  function refresh() {
    const button=$('#repair-base');if(!button)return;
    const status=state.base?.repair,damaged=Math.max(0,Number(status?.damaged)||0),available=ownsHome();
    button.hidden=!available;
    button.disabled=!available||!!pending||!status?.available||damaged===0;
    button.dataset.state=pending?'busy':damaged?'damaged':'ready';
    button.setAttribute('aria-busy',String(!!pending));
    const label=pending?'Repairing base…':'Repair base';
    const detail=pending?'Restoring your buildings':damaged?`${num(damaged)} damaged · Free · instant`:'No repairs needed · Free · instant';
    button.innerHTML=`${icon(pending?'clock':damaged?'repair':'check')}<span><b>${label}</b><small>${detail}</small></span>`;
    button.setAttribute('aria-label',`${label}. ${detail}.`);
    button.title=damaged?'Restore every damaged home building and rearm traps for free. Construction and upgrade timers keep running.':'Your base is fully repaired.';
  }

  async function repair(action,input) {
    if(!ownsHome()||pending)return;
    const token={owner:state.user.id,navigation:state.navigationEpoch,battle:state.battleEpoch,snapshot:state.snapshotEpoch};
    const current=()=>pending===token&&state.user?.id===token.owner&&state.navigationEpoch===token.navigation&&state.battleEpoch===token.battle&&ownsHome();
    pending=token;refresh();renderPanel();
    try {
      const data=await request(action,input);
      if(!current())return;
      // A newer action/bootstrap may have changed resources or started a battle.
      // Refresh from the server rather than applying a delayed older snapshot.
      if(state.snapshotEpoch===token.snapshot)applyState(data);
      else await refreshHome();
      if(!current())return;
      const repaired=Number(data.repairResult?.repaired)||0;
      if(repaired>0){
        if (Array.isArray(data.repairResult.buildingIds)) showRestored?.(token.owner,data.repairResult.buildingIds);
        audio.effect('build');toast(action==='repairBase'?`Base repaired for free. ${num(repaired)} ${repaired===1?'building':'buildings'} restored.`:'Building repaired for free.');}
      else toast(action==='repairBase'?'Your base is already fully repaired.':'This building is already fully repaired.');
    } catch(error) {
      if(current())toast(error.message,true);
    } finally {
      if(pending===token){pending=null;refresh();if(state.user?.id===token.owner)renderPanel();}
    }
  }

  async function repairAll() {
    if(!state.base?.repair?.available||!(state.base.repair.damaged>0))return;
    await repair('repairBase',{});
  }
  async function repairOne(id) {
    const building=state.base?.buildings?.find(item=>item.id===Number(id));
    if(canRepair(building))await repair('repair',{id:building.id});
  }
  function reset(){pending=null;refresh();}
  return {refresh,repairAll,repairOne,canRepair,busy,reset};
}
