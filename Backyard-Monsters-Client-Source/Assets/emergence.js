import {esc,num,icon} from './ui.js?v=0.13.0';

const portraits=['portal-rift','portal-rift','portal-stage2','portal-stage3','portal-stage4','portal-gate'];

export function entranceTexture(base) {
  if (!base?.infernoPortal) return null;
  const stage=base.emergence?.stage;
  if (stage>=1 && stage<=5) return portraits[stage];
  return base.infernoPortal==='closed'?'portal-rift':'portal-gate';
}

export function emergenceBoard(state,lines=emergenceDialogue) {
  const event=state.base.emergence, stage=event.stage, next=event.next;
  const session=state.base.baiter?.session;
  const busy=session?.status==='active';
  const pieces=Object.entries(event.wave).map(([type,count])=>{
    const m=state.catalog.monsters[type];
    return `<li><img alt="" loading="lazy" src="assets/portraits/${esc(type)}.webp"><span>${esc(m?.name||type)}</span><b>×${num(count)}</b></li>`;
  }).join('');
  const declaration=lines.intro;
  const guide=lines['cavern'+Math.max(1,stage)];
  const date=(event.nextAt||0)*1000;
  const clock=event.nextAt && !event.ready ? `<p>Next earthquake · <time datetime="${new Date(date).toISOString()}">${esc(new Date(date).toLocaleString())}</time></p>`:'';
  const controls=busy ? '<button class="button primary" data-baiter="blitz-resume">Resume current defense</button>' : event.complete?'':event.pending?`<button class="button primary" data-baiter="emergence-start" ${event.ready?'':'disabled'}>Engage Moloch’s minions</button>`:`<button class="button primary" data-baiter="emergence-raise" ${event.ready?'':'disabled'}>${stage?'Witness earthquake '+next:'Investigate the tremor'}</button>`;
  return `<section class="emergence-board"><header class="${event.complete?'emergence-finale':''}"><div><span class="eyebrow">INFERNO EMERGENCE</span><h2 id="modal-title">${event.complete?'The Skull of Doom is open':event.pending?'Moloch is approaching':'The Skull of Doom'}</h2><p>Earthquake ${num(stage)} of 5</p></div>${event.complete?'':`<img src="assets/art/${portraits[stage]}.webp" alt="The cavern at stage ${num(stage)}">`}</header>
    <ol class="emergence-track" aria-label="Portal growth">${portraits.slice(1).map((key,i)=>`<li class="${i<stage?'raised':''}" aria-label="Stage ${i+1}${i<stage?' revealed':''}"><img src="assets/art/${key}.webp" alt=""><span>${i+1}</span></li>`).join('')}</ol>
    <p>${esc(event.complete?lines.finale:guide)}</p>
    ${event.complete?'<p>Your surface build menu now includes the Magma Tower and Quake Tower.</p>':`<div class="emergence-envoy"><img src="assets/art/moloch-envoy.webp" alt=""><div><h3>Moloch, Lord of Inferno</h3><p>${esc(stage===0 || (stage===1 && event.pending)?declaration:lines['taunt'+(event.pending?stage-1:stage)])}</p></div></div>
    <h3>${event.pending?'Incoming pack':'Next pack'}</h3><ul class="baiter-roster">${pieces}</ul><p>From the western rift · Damage stays in your yard.</p>`}
    ${event.reason && !event.complete?`<p>${esc(event.reason)}</p>`:''}${clock}
    <footer>${controls}<button class="button subtle" data-inferno-open>${event.complete?'Enter Inferno':state.base.infernoPortal==='open'?'Visit my cavern':'Cavern entrance'}</button></footer></section>`;
}

export function emergenceDebrief(battle,lines=emergenceDialogue) {
  if (!battle.emergenceStage) return '';
  const s=battle.emergenceStage,opened=s===5;
  return `<div class="emergence-envoy"><img src="assets/art/moloch-envoy.webp" alt="Moloch"><p>${esc(lines['taunt'+s])}</p></div>
   <p>${opened?'The Skull of Doom is complete. Your passage to Inferno is open.':'The next stage rises 12 hours after this quake.'} Use Repair Base to restore damage for free.</p>`;
}

const emergenceDialogue={
  "intro": "Your reign over the backyard has come to an end! I am Moloch, Lord of Inferno, and I'm here to shake up your pathetic little world! Prepare to meet your doom!",
  "finale": "Moloch's on the run. Enter Inferno and fight your way though his network of subterranean bases.  Any monster in your arsenal is fair game, but you'll have to leave the catapults at home. If you survive, you'll control your own Inferno Base. Are you ready to rip the crown off Moloch's head and take your place as ruler of all that's unholy in the underworld?",
  "taunt1": "Gloat all you want! I'm simply testing your defenses. I'll be back with more minions soon. Your days are numbered!",
  "taunt2": "You may think you've beaten me, but I'm just biding my time. Wait and see!",
  "taunt3": "Don't get too comfortable! Once my Skull of Doom has fully penetrated the outer crust, you'll wish you had submitted to me from the start.",
  "taunt4": "Your puny yard is more of an annoyance than a challenge. Once my Skull of Doom emerges and you're faced with the full power of Inferno, your monsters will quake with fear and flee my wrath.",
  "taunt5": "My Skull of Doom is complete! The doorway to Inferno is open. Now I can come and go as I please. Wait a minute. That means you can too. I shouldn't have said that. Forget I said that!",
  "cavern1": "This structure looks unstable. It would probably be a good idea to check it again later.",
  "cavern2": "The earthquake has revealed more of this strange formation. It's possible future earthquakes will do the same.",
  "cavern3": "This structure looks like some sort of cavern, but it's too small to tell for sure. You'll need to wait for another earthquake to learn more.",
  "cavern4": "It looks like this \"Skull of Doom\" is an entrance of some sort, but it's not accessible yet. One more earthquake should open it up enough for you to see what's inside.",
  "cavern5": "The cavern beckons. Brave the darkness and fight Moloch on his own turf."
};
