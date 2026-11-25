import {esc, num, duration, icon} from './ui.js?v=0.13.1';

export function createYardUtilities({state,now,showModal,action,workshop,toast}) {
  let resource='twig', size='10', shelf='Supplies', bunker='', troop='', quantity=1;
  const names={twig:'Twigs',pebble:'Pebbles',putty:'Putty',goo:'Goo',bone:'Bone',coal:'Coal',sulfur:'Sulfur',magma:'Magma'};
  const sizes={'10':'10% of storage','50':'50% of storage',fill:'Fill remaining storage'};

  function buildingButton(building) {
    if (!['generalstore','hatcherycontrol'].includes(building.type)) return '';
    const store=building.type==='generalstore', available=store?state.base.utilities?.store:state.base.utilities?.control;
    return '<button class="button primary block" data-utility-open="'+(store?'store':'control')+'" '+(available?'':'disabled')+'>'+
      icon(store?'plus':'egg')+(store?'Open General Store':'Manage hatcheries')+'</button>';
  }
  function purchaseButton(kind,key,offer,label) {
    const affordable=!offer.reason && offer.price<=(state.base.shiny||0);
    return '<button class="button '+(affordable?'primary':'subtle')+' small" data-util-buy="'+kind+'" data-key="'+esc(key)+'" data-price="'+offer.price+
      '" data-revision="'+state.base.utilities.revision+'" data-amount="'+(offer.amount||0)+'" '+(affordable?'':'disabled')+'>'+label+
      ' · '+(offer.price?num(offer.price)+' Shiny':'Free')+'</button>';
  }
  const options=(rows,value)=>rows.map(([id,label])=>'<option value="'+esc(id)+'" '+(id===value?'selected':'')+'>'+esc(label)+'</option>').join('');
  function supplies(util) {
    const offers=util.offers||{}, available=Object.keys(names).filter(k=>
      ['twig','pebble','putty','goo'].includes(k)||!!util.powers?.HODI);
    if(!available.includes(resource))resource='twig';
    const matching=Object.keys(offers).filter(k=>k.startsWith(resource+'.'));
    if(!offers[resource+'.'+size] && matching.length) size=matching[0].split('.')[1];
    const key=resource+'.'+size,offer=offers[key];
    const selects='<div class="yard-store-fields"><label>Resource<select id="utility-resource">'+options(available.map(k=>[k,names[k]]),resource)+'</select></label>'+
      '<label>Delivery<select id="utility-size">'+Object.entries(sizes).map(([k,label])=>'<option value="'+k+'" '+(k===size?'selected':'')+' '+(!offers[resource+'.'+k]?'disabled':'')+'>'+label+'</option>').join('')+'</select></label></div>';
    const jobs=Object.entries(util.jobs||{}).map(([id,job])=>{
      const left=job.due-now();
      const steps=[[1,'hour'],[2,'twohours']].filter(([h])=>left>=3600*h).map(([h,kind])=>
        purchaseButton(kind,id,{price:20*h},'Cut '+h+(h===1?' hour':' hours'))).join('');
      return '<div class="store-item"><strong>'+esc(job.name)+'</strong><small data-countdown="'+job.due+'">'+duration(Math.max(0,left))+'</small>'+
        '<div class="button-row">'+steps+purchaseButton('time',id,job,'Finish now')+'</div></div>';
    }).join('');
    return '<h3>Resource delivery</h3>'+selects+(offer?'<div class="stat-line"><span>'+num(offer.amount)+' '+names[resource].toLowerCase()+'</span>'+purchaseButton('delivery',key,offer,'Deliver')+'</div>':'<p>Storage for this resource is full.</p>')+
      '<h3>Finish work</h3>'+(jobs||'<p>No construction, fortification or monster studies are underway.</p>');
  }
  function powers(util) {
    const offers=Object.entries(util.powers||{}).filter(([,v])=>v.group===shelf);
    if(!offers.length)return '<p>Raise or thaw a champion in a working Champion Cage to see its meals, evolution and recovery options.</p>';
    return '<div class="store-list">'+offers.map(([key,v])=>{
      const remaining=v.until>now()?'<small>Current effect · <span data-countdown="'+v.until+'">'+duration(v.until-now())+'</span></small>':'';
      return '<article class="store-item"><strong>'+esc(v.name)+'</strong><p>'+esc(v.description)+'</p>'+remaining+
        (v.reason?'<small>'+esc(v.reason)+'</small>':'')+purchaseButton('power',key,v,v.seconds?'Activate':'Purchase')+'</article>';
    }).join('')+'</div>';
  }
  function defenders(util) {
    const bunkers=Object.entries(util.bunkers||{});
    if(!bunkers.length)return '<p>Build or repair a Monster Bunker to buy defenders for its garrison.</p>';
    if(!util.bunkers[bunker])bunker=bunkers[0][0];
    const selected=util.bunkers[bunker],types=Object.entries(selected.troops||{});
    if(!selected.troops[troop])troop=types[0]?.[0]||'';
    const spec=selected.troops[troop],max=Math.min(200,spec?.max||1);
    quantity=Math.min(max,Math.max(1,quantity));
    const picker='<label>Bunker<select id="utility-bunker">'+options(bunkers.map(([id,b])=>[id,'Bunker #'+id+' · '+num(b.room)+' space']),bunker)+'</select></label>';
    if(!spec)return '<div class="yard-store-fields">'+picker+'</div><p>This bunker is full.</p>';
    return '<div class="yard-store-fields">'+picker+'<label>Defender<select id="utility-troop">'+
      options(types.map(([id,v])=>[id,v.name+' · '+num(v.price)+' Shiny each']),troop)+'</select></label>'+
      '<label>Quantity<input id="utility-quantity" type="number" min="1" max="'+max+'" step="1" value="'+quantity+'"></label></div>'+
      '<div id="utility-order">'+purchaseButton('bunker',bunker+'.'+troop,{price:spec.price*quantity,amount:quantity},'Station defenders')+'</div>';
  }
  function store() {
    const util=state.base?.utilities;
    if(!util?.store)return;
    const groups=['Supplies','Workers','Resources','Hatchery','Combat','Protection','Champions','Bunkers'];
    if(util.powers?.HODI)groups.push('Inferno');
    if(!groups.includes(shelf))shelf='Supplies';
    const selector='<label class="store-department">Department<select id="utility-shelf">'+options(groups.map(v=>[v,v]),shelf)+'</select></label>';
    showModal('<h2 id="modal-title">General Store</h2><div class="store-heading"><p>Wallet · <strong>'+num(state.base.shiny||0)+' Shiny</strong></p>'+selector+'</div>'+
      (shelf==='Supplies'?supplies(util):shelf==='Bunkers'?defenders(util):powers(util)),'yard-store');
    const bind=(id,callback)=>{const el=document.getElementById(id);if(el)el.onchange=event=>{callback(event.target.value);store();};};
    bind('utility-shelf',v=>shelf=v);bind('utility-resource',v=>resource=v);bind('utility-size',v=>size=v);
    bind('utility-bunker',v=>{bunker=v;quantity=1;});bind('utility-troop',v=>{troop=v;quantity=1;});
    const qty=document.getElementById('utility-quantity');
    if(qty)qty.oninput=()=>{
      quantity=qty.valueAsNumber;
      const spec=util.bunkers[bunker].troops[troop];
      const valid=Number.isInteger(quantity)&&quantity>=1&&quantity<=Math.min(200,spec.max);
      document.getElementById('utility-order').innerHTML=purchaseButton('bunker',bunker+'.'+troop,
        {price:valid?quantity*spec.price:0,amount:valid?quantity:0,reason:valid?'':'Invalid quantity'},valid?'Station defenders':'Choose a valid quantity');
      if(!valid)quantity=1;
    };
  }
  function control() {
    const q=state.base?.utilities?.hatch;
    if(!q)return;
    const lanes=state.base.buildings.filter(b=>b.type==='hatchery' && b.level>0);
    const orders=state.queue||[];
    const laneCards=lanes.map((lane,n)=>{
      const laneJobs=orders.filter(j=>Number(j.lane||lanes[0]?.id)===Number(lane.id));
      const head=laneJobs[0], active=lane.hp>0&&lane.ready_at<=now();
      return '<div class="stat-line"><span>Hatchery '+(n+1)+' · Level '+num(lane.level)+'</span><b>'+
        (!active?'Offline':head?esc(state.catalog.monsters[head.type]?.name||head.type)+' · '+duration(Math.max(0,head.ready_at-now())):'Idle')+
        (laneJobs.length>1?' · '+num(laneJobs.length-1)+' waiting':'')+'</b></div>';
    }).join('');
    showModal('<h2 id="modal-title">Hatchery Control Center</h2><p>'+num(q.lanes)+(q.lanes===1?' working hatchery · ':' working hatcheries · ')+num(q.quantity)+' monsters in their queues · '+num(state.base.shiny||0)+' Shiny</p>'+
      laneCards+'<div class="button-row"><button class="button primary" data-utility-train>'+icon('egg')+'Hatch or cancel orders</button>'+
      (q.quantity?purchaseButton('hatch',q.key,q,'Finish working queues'):'')+'</div>','yard-control');
  }
  async function click(el) {
    if(el.dataset.utilityOpen){shelf='Supplies';el.dataset.utilityOpen==='store'?store():control();return true;}
    if('utilityTrain' in el.dataset){workshop.showHatchery();return true;}
    const kind=el.dataset.utilBuy;if(!kind)return false;
    const modal=state.modal;
    const data=await action('yardPurchase',{kind,key:el.dataset.key,price:Number(el.dataset.price),amount:Number(el.dataset.amount),revision:Number(el.dataset.revision)},el);
    if(data && modal===state.modal){kind==='hatch'?control():store();toast(({delivery:'Resources delivered.',power:'Purchase applied.',bunker:'Defenders stationed.',hour:'Job shortened.',twohours:'Job shortened.'})[kind]||'Work completed.');}
    return true;
  }
  return {click,buildingButton};
}
