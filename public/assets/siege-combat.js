import {esc,icon,num} from './ui.js?v=0.13.1';

const devices={
  decoy:{name:'Marilyn Monstroe',action:'Place Decoy',hint:'Click clear ground to lure bunker defenders into the blast.'},
  vacuum:{name:'Loot-O-Tron 5000',action:'Attach Vacuum',hint:'Siphon stock from the Town Hall while the hose survives.'},
  jars:{name:'Candy Jars',action:'Aim Jars',hint:'Click a tower cluster to trap its shots behind glass.'}
};

export function siegeLog(result) {
  const shot=result?.siege, device=devices[shot?.type];
  if(!device) return '';
  const loot=Object.values(shot.siphoned||{}).reduce((a,b)=>a+Number(b),0);
  return `<p>Siege device · ${esc(device.name)} level ${num(shot.level)}${loot?` · ${num(loot)} resources siphoned`:''}</p>`;
}

export function createSiegeCombat({state,api,choosePlacement,restoreBucketAim,renderPanel,updateBattle,toast}) {
  let pending=null;
  const key=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
  function panel() {
    const weapon=state.battle?.siege, device=devices[weapon?.type];
    if(!device) return '';
    const active=weapon.active;
    const status=active?(active.endsAt!=null?`${Math.max(0,Math.ceil(active.endsAt-state.battle.elapsed))}s remaining`:`${active.targets.length} tower(s) encased`):weapon.used?'Device spent':weapon.reason||device.hint;
    return `<div class="catapult-panel"><div><img src="assets/art/siege-${weapon.type}.png" width="44" height="44" alt="" style="object-fit:contain"><span><b>${esc(device.name)} · Level ${num(weapon.level)}</b><small>${esc(status)}</small></span></div>
      ${!weapon.used&&!weapon.reason?`<button class="button subtle block" data-siege-aim="${weapon.type}">${icon('swords')}${device.action}</button>`:''}</div>`;
  }
  async function fire(pos,aim) {
    const battle=state.battle;
    if(!battle||battle.status!=='active'||battle.attacker.id!==state.user?.id||battle.id!==aim.battleId||state.battleBusy) return;
    const epoch=state.battleEpoch, owner=state.user.id;
    state.battleBusy=true;
    try {
      const next=await api('siegeFire',{battleId:battle.id,type:aim.type,x:pos.x,y:pos.y,requestId:aim.requestId});
      if(state.user?.id!==owner||state.battle?.id!==battle.id||state.battleEpoch!==epoch)return;
      pending=null;
      if(next.status==='active')restoreBucketAim();
      updateBattle(next);
      toast(`${devices[aim.type].name} activated.`);
    } catch(error) {
      if(state.user?.id===owner&&state.battle?.id===battle.id)toast(error.message,true);
    } finally {if(state.battleEpoch===epoch&&state.user?.id===owner)state.battleBusy=false;}
  }
  async function handleClick(button) {
    const type=button.dataset.siegeAim;
    if(!type) return false;
    const battle=state.battle, weapon=battle?.siege;
    if(!weapon||weapon.type!==type||weapon.used||weapon.reason||battle.status!=='active'||battle.attacker.id!==state.user?.id)return true;
    if(pending?.battleId!==battle.id||pending?.owner!==state.user.id) pending={requestId:key(),battleId:battle.id,owner:state.user.id};
    const aim={...pending,type,mode:'siege',radius:weapon.radius};
    if(type==='vacuum') {
      const hall=battle.buildings.find(b=>b.type==='townhall'&&b.hp>0);
      if(hall) await fire(hall,aim);
      else toast('The Vacuum needs a standing Town Hall.',true);
    } else {choosePlacement(aim);renderPanel();}
    return true;
  }
  return {panel,fire,handleClick,hint:type=>devices[type]?.hint||''};
}
