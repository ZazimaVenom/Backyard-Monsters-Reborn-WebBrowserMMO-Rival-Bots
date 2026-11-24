const TAU = Math.PI * 2;
const GOLD = '#ffc278';
const LAVA = '#ff802f';

function circle(ctx, x, y, radius, color) {
  ctx.fillStyle = color;
  ctx.beginPath(); ctx.arc(x, y, radius, 0, TAU); ctx.fill();
}
function stroke(ctx, a, b, width, color) {
  ctx.lineWidth = width; ctx.strokeStyle = color;
  ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
}

export function drawDefenseEvent(view, e, source, target, ground, age) {
  const c = view.ctx;
  if (e.type === 'quake_impact' || e.type === 'shell_impact') {
    const quake = e.type === 'quake_impact', life = quake ? .9 : .42;
    if (age >= life) return true;
    const t = age / life, radius = Math.max(11, Math.min(6, e.radius || .5) * 28.284);
    c.globalAlpha = 1 - t;
    for (let ring = 0; ring < (view.reduced ? 1 : 3); ring++) {
      const scale = Math.max(.01, 1 - Math.pow(1 - t, 3) - ring * .14);
      c.strokeStyle = ring % 2 ? '#863c20' : GOLD; c.lineWidth = Math.max(.7, 4 * (1-t));
      c.beginPath(); c.ellipse(ground.x, ground.y, radius * scale, radius * scale / 2, 0, 0, TAU); c.stroke();
    }
    if (!view.reduced) for (let i = 0; i < (quake ? 11 : 6); i++) {
      const angle = i * 2.399 + e.id * .57, r = radius * (.24 + t * .7);
      circle(c, ground.x + Math.cos(angle) * r, ground.y + Math.sin(angle) * r * .5 - Math.sin(t*Math.PI) * 10, Math.max(1, (1-t)*3.5), i%2 ? '#a55d3b' : GOLD);
    }
    return true;
  }
  if (e.type !== 'shot') return false;
  if (e.kind === 'railgun' && Number.isFinite(e.endX)) {
    if (age >= .46) return true;
    const end = view.project(e.endX, e.endY); end.y -= 15;
    const t = age / .46;
    c.globalAlpha = 1 - t; c.lineCap = 'round';
    stroke(c, source, end, view.reduced ? 3 : 7*(1-t)+1, '#3096a8');
    stroke(c, source, end, 2, '#cbfff3');
    if (!view.reduced) for (let n = 1; n <= 16; n++) {
      const p = (n + t*.8)/18;
      c.strokeStyle = '#5febeb'; c.lineWidth = 1;
      c.beginPath(); c.ellipse(source.x+(end.x-source.x)*p,source.y+(end.y-source.y)*p,2+3*t,4+5*t,.25,0,TAU); c.stroke();
    }
    return true;
  }
  const egg = e.kind === 'spurtzcannon' || e.kind === 'diamondspurtz';
  if (!egg && !['infernocannon','magmatower'].includes(e.kind)) return false;
  if (egg && Number.isFinite(e.tx)) target=view.project(e.tx,e.ty);
  const duration = Math.max(.14, Math.min(1.4, Number(e.travel) || .35));
  if (age >= duration) return true;
  const t = Math.min(1, age/duration), slag = e.kind==='magmatower';
  const arc = slag ? 7 : Math.min(42, Math.hypot(target.x-source.x,target.y-source.y)*.3);
  const at = p => ({x:source.x+(target.x-source.x)*p,y:source.y+(target.y-source.y)*p-Math.sin(p*Math.PI)*arc});
  const tip=at(t), tail=at(Math.max(0,t-.19));
  stroke(c,tail,tip,slag?5:2.5,slag?'#fc612a':'#e3c8a8');
  if (egg) {
    c.fillStyle=e.kind==='diamondspurtz'?'#b5b2ea':'#abb66a';
    c.beginPath(); c.ellipse(tip.x,tip.y,5,3.2,Math.atan2(tip.y-tail.y,tip.x-tail.x),0,TAU); c.fill();
  } else circle(c,tip.x,tip.y,slag?4.4:4,slag?LAVA:'#49434b');
  circle(c,tip.x-1,tip.y-1,slag?2.3:1.4,slag?'#ffedb7':'#ba9987');
  return true;
}

export function drawQuakeCharge(view, building, point, width) {
  const remaining = building.quakeImpactAt - view.battleElapsed();
  if (!(remaining > 0 && remaining <= 4.25)) return;
  const ctx=view.ctx, lift = 1-remaining/4.25;
  ctx.save(); ctx.lineWidth=1.5; ctx.globalAlpha=.38+lift*.5;
  ctx.strokeStyle=GOLD;
  for (let i=0;i<(view.reduced?1:3);i++) {
    const y=point.y-width*(.19+i*.08+lift*.18), r=width*(.13+lift*.025);
    ctx.beginPath(); ctx.ellipse(point.x,y,r,r*.38,0,0,TAU); ctx.stroke();
  }
  ctx.restore();
}

export function drawBurning(view, point, width, top) {
  const c=view.ctx, phase=view.reduced?0:Math.sin(view.now*9)*2;
  const x=point.x+width*.35, y=top+4;
  c.save(); c.fillStyle=LAVA; c.strokeStyle='#8b381e'; c.lineWidth=1.1;
  c.beginPath(); c.moveTo(x-1,y-8-phase); c.bezierCurveTo(x+8,y-3,x+7,y+6,x,y+7);
  c.bezierCurveTo(x-7,y+6,x-6,y-1,x-4,y-4); c.lineTo(x-3,y);c.closePath();c.fill();c.stroke();
  circle(c,x,y+3,2.3,'#ffe197'); c.restore();
}

function ramPath(path) {
  path.moveTo(457,905); path.lineTo(457,669);
  path.bezierCurveTo(457,632,497,606,541,589);
  path.lineTo(551,539);path.bezierCurveTo(558,513,597,506,619,535);
  path.lineTo(637,588);path.quadraticCurveTo(661,592,678,607);path.lineTo(678,961);
  path.bezierCurveTo(626,1001,485,1000,457,925);path.closePath();
}

/** The generated ram is separate from its gantry only while a server-confirmed charge is active. */
export function drawQuakeMechanism(view, building, point, width) {
  const remaining=building.quakeImpactAt-view.battleElapsed();
  const image=view.images.quaketower, bounds=view.bounds.quaketower;
  if (!image || !bounds || view.reduced || !(remaining > 0 && remaining <= 4.25)) return false;
  const phase=(4.25-remaining)/4.25;
  const lift=phase < .88 ? 105*Math.sin(Math.PI*.5*phase/.88) : 105*(1-Math.pow((phase-.88)/.12,2));
  const ctx=view.ctx, factor=width/bounds.w;
  ctx.save();
  ctx.translate(point.x-width/2-bounds.x*factor, point.y+8-(bounds.y+bounds.h)*factor);
  ctx.scale(factor,factor);
  const mask=new Path2D();ramPath(mask);
  const frame=new Path2D();frame.rect(0,0,image.width,image.height);frame.addPath(mask);
  ctx.save();ctx.clip(frame,'evenodd');ctx.drawImage(image,0,0);ctx.restore();
  ctx.save();ctx.clip(mask);
  const well=ctx.createLinearGradient(0,860,0,1000);
  well.addColorStop(0,'#262327');well.addColorStop(.7,'#372b29');well.addColorStop(1,'#6a4940');
  ctx.fillStyle=well;ctx.fillRect(452,850,235,155);ctx.restore();
  ctx.translate(0,-lift);ctx.clip(mask);ctx.drawImage(image,0,0);
  ctx.restore();return true;
}
