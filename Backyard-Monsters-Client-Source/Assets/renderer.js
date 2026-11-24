import { ProducerMotion } from './producer-motion.js?v=0.13.0';
import { CombatPerformances, ordinaryVoices, performanceSheets, defeatKey, defeatPose, articulatedFrame } from './combat-performances.js?v=0.13.0';
import { entranceTexture } from './emergence.js?v=0.13.0';
import { buildingTierKey } from './building-tiers.js?v=0.13.0';
import { directionSheets, yardBearing, directionalFrame, screenStep } from './monster-directions.js?v=0.13.0';
import { drawBurning, drawDefenseEvent, drawQuakeCharge, drawQuakeMechanism } from './expanded-defenses.js';
import { creatureWidths, creaturePose, makeCreatureFrame, drawCreatureAbility } from './creature-motion.js?v=0.13.0';

const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
const lerp = (a, b, amount) => a + (b - a) * amount;
const isTrap = type => type === 'trap' || type === 'heavytrap';
const infernoTypes = new Set(['townhall','twig','pebble','putty','goo','storage','hatchery','housing','academy','locker','flinger','sniper','cannon','wall']);
const defenseMuzzles = {railgun:[928,670],infernocannon:[875,325],magmatower:[800,315],spurtzcannon:[820,290],diamondspurtz:[775,288]};
const evolutionSheets = Object.create(null);
for (const type of ['gorgo','drull','fomor','korath']) {
  for (let level = 1; level <= 6; level++) {
    const key = type + '-e' + level + '-poses';
    evolutionSheets[key] = {file:key+'.webp',columns:2,rows:2,count:4};
  }
}
for (let power = 0; power <= 2; power++) {
  const key = 'krallen-p' + power + '-poses';
  evolutionSheets[key] = {file:key+'.webp',columns:2,rows:2,count:4};
}
// Original object 127 stands outside the building area at the western edge of each home.
const portalPosition = {x:-2,y:14};
const seasonalSprites = {27:['horsey',90],53:['eventpumpkin',32],54:['giantpumpkin',110]};
const visual = {
  townhall: 1.22, hatchery: 1.32, housing: 1.3, twig: 1.3, pebble: 1.35,
  putty: 1.3, goo: 1.3, storage: 1.32, sniper: 1.3, cannon: 1.35,
  tesla: 1.3, wall: 1.6, stonewall: 1.6, academy: 1.35, flinger: 1.3, locker: 1.25,
  juicer: 1.3, laser: 1.3, bunker: 1.3, trap: 1.35, aerial: 1.32, heavytrap: 1.5,
  championcage: 1.2, championchamber: 1.3, lab: 1.3, catapult: 1.3,
  baiter: 1.1, planner: 1.25, maproom: 1.3, outpost: 1.3, stronghold: 1.35, outpostdefender: 1.35,
};
const unitWidths = {
  ...creatureWidths, krallen: 88,
  pokey: 36, octo: 48, fink: 43, ichi: 61, bandito: 45, dave: 78,
  bolt: 38, eyera: 45, fang: 48, wormzer: 60, teratorn: 76, zafreeti: 58,
  brain: 48, crabatron: 69, projectx: 65, gorgo: 95, drull: 85, fomor: 95,
};
// These generated species face screen-left in both their static art and walk sheets.
const nativeFacingLeft = new Set(['krallen', 'brain', 'crabatron', 'bandito', 'dave', 'bolt', 'fang', 'zafreeti']);
// Each optional sheet contains four new drawn poses in a 2 x 2 row-major grid.
// Fixed union bounds preserve each frame's alignment rather than recropping poses.
const animationSheets = {
  'baiter-work': { file: 'baiter-work.png', columns: 2, rows: 2, count: 4 },
  'worker-walk': { file: 'worker-walk.png', columns: 2, rows: 2, count: 4 },
  'worker-work': { file: 'worker-work.png', columns: 2, rows: 2, count: 4 },
  'pokey-walk': { file: 'pokey-walk.png', columns: 2, rows: 2, count: 4 },
  'teratorn-fly': { file: 'teratorn-fly.png', columns: 2, rows: 2, count: 4 },
  'wormzer-emerge': { file: 'wormzer-emerge.png', columns: 2, rows: 2, count: 4 },
  'gorgo-walk': { file: 'gorgo-walk.png', columns: 2, rows: 2, count: 4 },
  'drull-walk': { file: 'drull-walk.png', columns: 2, rows: 2, count: 4 },
  'fomor-fly': { file: 'fomor-fly.png', columns: 2, rows: 2, count: 4 },
  'brain-walk': { file: 'brain-walk.png', columns: 2, rows: 2, count: 4 },
  'crabatron-walk': { file: 'crabatron-walk.png', columns: 2, rows: 2, count: 4 },
  'projectx-walk': { file: 'projectx-walk.png', columns: 2, rows: 2, count: 4 },
  'octo-walk': { file: 'octo-walk.png', columns: 2, rows: 2, count: 4 },
  'fink-walk': { file: 'fink-walk.png', columns: 2, rows: 2, count: 4 },
  'ichi-walk': { file: 'ichi-walk.png', columns: 2, rows: 2, count: 4 },
  'bandito-walk': { file: 'bandito-walk.png', columns: 2, rows: 2, count: 4 },
  'dave-walk': { file: 'dave-walk.png', columns: 2, rows: 2, count: 4 },
  'bolt-walk': { file: 'bolt-walk.png', columns: 2, rows: 2, count: 4 },
  'fang-walk': { file: 'fang-walk.png', columns: 2, rows: 2, count: 4 },
  'eyera-walk': { file: 'eyera-walk.png', columns: 2, rows: 2, count: 4 },
  'zafreeti-fly': { file: 'zafreeti-fly.png', columns: 2, rows: 2, count: 4 },
  'pokey-attack': { file: 'pokey-attack.png', columns: 2, rows: 2, count: 4 },
  'octo-attack': { file: 'octo-attack.png', columns: 2, rows: 2, count: 4 },
  'ichi-attack': { file: 'ichi-attack.png', columns: 2, rows: 2, count: 4 },
  'dave-attack': { file: 'dave-attack.png', columns: 2, rows: 2, count: 4 },
  'gorgo-attack': { file: 'gorgo-attack.png', columns: 2, rows: 2, count: 4 },
  'drull-attack': { file: 'drull-attack.png', columns: 2, rows: 2, count: 4 },
  'fomor-attack': { file: 'fomor-attack.png', columns: 2, rows: 2, count: 4 },
  'fink-attack': { file: 'fink-attack.png', columns: 2, rows: 2, count: 4 },
  'bandito-attack': { file: 'bandito-attack.png', columns: 2, rows: 2, count: 4 },
  'bolt-attack': { file: 'bolt-attack.png', columns: 2, rows: 2, count: 4 },
  'eyera-attack': { file: 'eyera-attack.png', columns: 2, rows: 2, count: 4 },
  'fang-attack': { file: 'fang-attack.png', columns: 2, rows: 2, count: 4 },
  'wormzer-attack': { file: 'wormzer-attack.png', columns: 2, rows: 2, count: 4 },
  'teratorn-attack': { file: 'teratorn-attack.png', columns: 2, rows: 2, count: 4 },
  'zafreeti-attack': { file: 'zafreeti-attack.png', columns: 2, rows: 2, count: 4 },
  'brain-attack': { file: 'brain-attack.png', columns: 2, rows: 2, count: 4 },
  'crabatron-attack': { file: 'crabatron-attack.png', columns: 2, rows: 2, count: 4 },
  'projectx-attack': { file: 'projectx-attack.png', columns: 2, rows: 2, count: 4 },
};
const attackTypes = new Set(['pokey', 'octo', 'fink', 'ichi', 'bandito', 'dave', 'bolt', 'fang', 'wormzer', 'teratorn', 'brain', 'crabatron', 'projectx', 'gorgo', 'drull', 'fomor']);
// Action illustrations have their own orientation, independent of locomotion.
const attackNativeFacingLeft = new Set(['dave', 'bandito', 'bolt', 'fang', 'zafreeti', 'brain', 'crabatron']);
const attackDuration = .64;
const burstDuration = .84;
const walkRates = { pokey: 8, octo: 6, fink: 8, ichi: 5, bandito: 9, dave: 5, brain: 8, crabatron: 6, projectx: 7, gorgo: 6, drull: 8, bolt: 10, fang: 8, eyera: 7 };
const artilleryColors = { twig: '#eccb8c', pebble: '#a6dfec', putty: '#e8abea' };
const teamColor = team => team === 'defender' ? '#83ddea' : '#dce9a3';
const championLevel = (unit, catalog) => catalog.champions?.[unit.type]?.levels?.[unit.level || 1] || {};
const movementOf = (unit, catalog) => unit.movement || championLevel(unit, catalog).movement || catalog.monsters[unit.type]?.movement || 'ground';

export class YardRenderer {
  constructor(canvas, { select, mushroom, seasonal, portal, place, planMove, sound, voice } = {}) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d', { alpha: false });
    this.callbacks = { select, mushroom, seasonal, portal, place, planMove, sound, voice };
    this.planner = null; this.workerSprites = new Map(); this.workerOwner = null;
    this.images = {};
    this.bounds = {};
    this.wearPlates = new Map();
    this.restoration = new Map();
    this.pendingAssets = new Map();
    this.failedAssets = new Map();
    this.catalog = { buildings: {}, monsters: {}, champions: {} };
    this.producerMotion=new ProducerMotion();
    this.base = { buildings: [] };
    this.battle = null;
    this.sirenRetreat = null;
    this.previousUnits = new Map();
    this.unitFacings = new Map();
    this.unitEffects = new Map();
    this.selected = null;
    this.hover = null;
    this.placement = null;
    this.pointer = null;
    this.drag = null;
    this.zoom = 1;
    this.pan = { x: 0, y: 0 };
    this.fx = [];
    this.lastEvent = 0;
    this.reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
    this.now = 0;
    this.isVisible = !document.hidden;
    this.serverOffset = 0;
    this.resizeObserver = new ResizeObserver(() => this.resize());
    this.resizeObserver.observe(canvas);
    this.bind();
    this.resize();
    this.loadImage('terrain', 'terrain.png');
    this.loop = this.loop.bind(this);
    requestAnimationFrame(this.loop);
    document.addEventListener('visibilitychange', () => { this.isVisible = !document.hidden; });
  }

  async load() {
    // Champions have a separate catalog and never become ordinary hatchable monsters.
    const assets = Object.entries({ ...this.catalog.buildings, ...this.catalog.monsters, ...this.catalog.champions });
    await Promise.all([
      this.loadImage('terrain', 'terrain.png'),
      this.loadImage('worker', 'worker.png'),
      this.loadImage('simplesign','simplesign.png'),
      this.loadImage('mushrooms', 'mushrooms.png', {columns:5,rows:1,count:5}),
      ...['timber','stone','machinery'].map(material=>this.loadImage('rubble-'+material,'rubble-'+material+'.png')),
      ...['decoy','vacuum','jars'].map(type=>this.loadImage('siege-'+type,'siege-'+type+'.png')),
      ...assets.map(([type, spec]) => this.loadImage(type, spec.art || `${type}.png`)),
      ...Object.entries(animationSheets).map(([key, sheet]) => this.loadImage(key, sheet.file, sheet)),
    ]);
  }

  loadImage(key, file, sheet = null) {
    if (this.images[key]) return Promise.resolve();
    if (this.pendingAssets.has(key)) return this.pendingAssets.get(key);
    // A missing optional animation is retried after a later catalog refresh, not every frame.
    if (Date.now() - (this.failedAssets.get(key) || 0) < 30000) return Promise.resolve();
    const tier = /^(?:surface|inferno)-[a-z]+-[1-9][0-9]?$/.test(key);
    const decoration=/^decor-\d+$/.test(key);
    if (decoration ? !/^\d+\.webp$/.test(file) : !/^[a-z0-9_-]+\.(?:png|webp)$/i.test(file)) return Promise.resolve();
    const task = new Promise(resolve => {
      const image = new Image();
      image.decoding = 'async';
      image.onload = () => {
        if (performanceSheets[key] && this.performanceWanted && !this.performanceWanted.has(key)) { resolve();return; }
        this.images[key] = image;
        if (key !== 'terrain' && key !== 'inferno-terrain') this.bounds[key] = this.measureBounds(image, sheet);
        this.failedAssets.delete(key);
        resolve();
      };
      const master = performanceSheets[key] ? (key.endsWith('-defeat')?'assets/defeats/':'assets/facing-actions/')+file : tier ? 'assets/building-tiers/'+file : decoration ? `assets/decorations/${file}` : `assets/art/${file}`;
      let useWebP = !decoration && file.endsWith('.png');
      image.onerror = () => {
        if (useWebP) { useWebP = false; image.src = master; return; }
        this.failedAssets.set(key, Date.now()); resolve();
      };
      image.src = useWebP ? 'assets/atlas/'+file.slice(0,-4)+'.webp' : master;
    }).finally(() => this.pendingAssets.delete(key));
    this.pendingAssets.set(key, task);
    return task;
  }

  measureBounds(image, sheet) {
    const columns = sheet?.columns || 1, rows = sheet?.rows || 1;
    const width = image.width / columns, height = image.height / rows;
    const cell = document.createElement('canvas');
    cell.width = 128; cell.height = 128;
    const context = cell.getContext('2d', { willReadFrequently: true });
    let minX = 128, minY = 128, maxX = -1, maxY = -1;
    for (let frame = 0; frame < (sheet?.count || 1); frame++) {
      context.clearRect(0, 0, 128, 128);
      context.drawImage(image, (frame % columns) * width, Math.floor(frame / columns) * height, width, height, 0, 0, 128, 128);
      const pixels = context.getImageData(0, 0, 128, 128).data;
      for (let y = 0; y < 128; y++) for (let x = 0; x < 128; x++) {
        if (pixels[(y * 128 + x) * 4 + 3] > 40) {
          minX = Math.min(minX, x); minY = Math.min(minY, y);
          maxX = Math.max(maxX, x); maxY = Math.max(maxY, y);
        }
      }
    }
    if (maxX < minX || maxY < minY) return { x: 0, y: 0, w: width, h: height };
    minX = Math.max(0, minX - 2); minY = Math.max(0, minY - 2);
    maxX = Math.min(128, maxX + 3); maxY = Math.min(128, maxY + 3);
    return { x: minX / 128 * width, y: minY / 128 * height, w: (maxX - minX) / 128 * width, h: (maxY - minY) / 128 * height };
  }

  resize() {
    const rect = this.canvas.getBoundingClientRect();
    if (!rect.width || !rect.height) return;
    this.w = rect.width; this.h = rect.height;
    this.dpr = Math.min(devicePixelRatio || 1, 2);
    this.canvas.width = Math.round(rect.width * this.dpr);
    this.canvas.height = Math.round(rect.height * this.dpr);
    this.scaleBase = Math.min(this.w / 1420, this.h / 890);
    this.ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
  }

  bind() {
    this.canvas.addEventListener('pointerdown', event => {
      this.canvas.setPointerCapture(event.pointerId);
      this.pointer = this.locate(event);
      const position=this.world(this.pointer),building=this.planner&&!this.planner.busy&&!this.placement&&!event.shiftKey?this.hit(position):null;
      const movable=building&&!this.planner.lockedIds.includes(Number(building.id));
      this.drag = { x: event.clientX, y: event.clientY, px: this.pan.x, py: this.pan.y, moved: false, plan:movable?{id:building.id,type:building.type,offsetX:position.x-building.x,offsetY:position.y-building.y}:null };
    });
    this.canvas.addEventListener('pointermove', event => {
      this.pointer = this.locate(event);
      if (this.drag) {
        const dx = event.clientX - this.drag.x, dy = event.clientY - this.drag.y;
        if (Math.hypot(dx, dy) > 7) this.drag.moved = true;
        if (this.drag.moved) {
          if(this.drag.plan){this.placement={mode:'planner',...this.drag.plan};this.canvas.style.cursor='crosshair';}
          else{this.pan.x = this.drag.px + dx; this.pan.y = this.drag.py + dy;this.canvas.style.cursor = 'grabbing';}
        }
      }
      const at=this.world(this.pointer);
      this.hover = this.hit(at);
      this.portalHover = !this.hover && this.hitPortal(at);
      if (!this.drag && !this.placement) this.canvas.style.cursor = this.portalHover ? 'pointer' : 'default';
    });
    this.canvas.addEventListener('pointerup', event => {
      const didDrag = this.drag?.moved, plan=this.drag?.plan;
      this.drag = null;
      if(didDrag&&plan){const point=this.world(this.locate(event));this.placement=null;this.callbacks.planMove?.(Number(plan.id),{x:Math.round((point.x-plan.offsetX)*2)/2,y:Math.round((point.y-plan.offsetY)*2)/2});}
      this.canvas.style.cursor = this.placement ? 'crosshair' : 'default';
      if (!didDrag) {
        const pos = this.world(this.locate(event));
        if (this.placement) {
          this.callbacks.place?.(this.placementPoint(pos), this.placement);
        }
        else {
          const building=this.hit(pos), mushroom=(!this.battle&&!this.planner)?this.hitMushroom(pos):null;
          const prop = !this.battle && !this.planner ? this.hitSeasonal(pos) : null;
          if (prop && [building,mushroom].every(other=>!other || prop.x+prop.y>=other.x+other.y)) this.callbacks.seasonal?.(prop);
          else if(mushroom && (!building || mushroom.x+mushroom.y >= building.x+building.y)) this.callbacks.mushroom?.(mushroom);
          else if (!building && this.hitPortal(pos)) this.callbacks.portal?.(this.base.infernoPortal);
          else this.callbacks.select?.(building);
        }
      }
    });
    this.canvas.addEventListener('pointercancel', () => { if(this.drag?.plan)this.placement=null;this.drag = null; });
    this.canvas.addEventListener('pointerleave', () => { if (!this.drag) { this.pointer = null; this.hover = null; this.portalHover = false; } });
    this.canvas.addEventListener('wheel', event => {
      event.preventDefault(); this.setZoom(this.zoom * (event.deltaY < 0 ? 1.1 : .9));
    }, { passive: false });
    this.canvas.addEventListener('keydown', event => {
      if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', '+', '-'].includes(event.key)) event.preventDefault();
      if (event.key === 'ArrowLeft') this.pan.x += 30;
      if (event.key === 'ArrowRight') this.pan.x -= 30;
      if (event.key === 'ArrowUp') this.pan.y += 30;
      if (event.key === 'ArrowDown') this.pan.y -= 30;
      if (event.key === '+') this.setZoom(this.zoom * 1.1);
      if (event.key === '-') this.setZoom(this.zoom * .9);
    });
  }

  locate(event) { const rect = this.canvas.getBoundingClientRect(); return { x: event.clientX - rect.left, y: event.clientY - rect.top }; }
  project(x, y) { return { x: (x - y) * 20, y: (x + y) * 10 }; }
  get yardExtent() { return (this.battle||this.base)?.yardExtent || 32; }
  get yardMargin() { return (this.yardExtent-32)/2; }
  insideYard(x,y,inset) {
    const margin=this.yardMargin;
    return Math.min(x,y)>=inset-margin && Math.max(x,y)<=32+margin-inset;
  }
  get transform() { const s = this.scaleBase * this.zoom * 32/this.yardExtent; return { s, x: this.w / 2 + this.pan.x, y: this.h / 2 - 295 * s + this.pan.y }; }
  world(point) {
    const t = this.transform, a = (point.x - t.x) / t.s / 20, b = (point.y - t.y) / t.s / 10;
    return { x: (a + b) / 2, y: (b - a) / 2 };
  }
  visibleBuildings() {
    // The server filters secret traps by viewer. Never use base fallback while in a battle,
    // and honour explicit visibility flags if a future snapshot supplies them.
    return (this.battle ? this.battle.buildings || [] : this.base.buildings || []).filter(b => b.hidden !== true && b.visible !== false);
  }
  untargetable(building) { return building.untargetable === true || this.catalog.buildings[building.type]?.untargetable === true; }
  portalSprite() {
    if (!this.base?.infernoPortal || this.battle || this.planner) return null;
    return entranceTexture(this.base);
  }
  hitPortal(pos) {
    const key=this.portalSprite();
    if (!key) return false;
    const root=this.project(portalPosition.x,portalPosition.y), p=this.project(pos.x,pos.y);
    const width=key==='portal-gate'?134:key==='portal-rift'?97:109+4*Number(key.slice(-1)), bounds=this.bounds?.[key];
    const height=bounds?width*bounds.h/bounds.w:width*.8;
    return Math.abs(p.x-root.x)<width*.43 && p.y>root.y-height+4 && p.y<root.y+3;
  }
  drawPortal() {
    const key=this.portalSprite(), p=this.project(portalPosition.x,portalPosition.y);
    const width=key==='portal-gate'?134:key==='portal-rift'?97:109+4*Number(key.slice(-1));
    if (!this.drawImage(key,p.x,p.y,width)) return;
    if (this.portalHover && !this.placement && !this.drag) {
      const gate=this.base.infernoPortal==='return'?'Return to backyard':'Inferno entrance';
      const height=width*this.bounds[key].h/this.bounds[key].w;
      const c=this.ctx;
      c.font='600 13px system-ui';
      const w=c.measureText(gate).width+24;
      c.fillStyle='#1e1828ed';c.strokeStyle='#d1996d';c.lineWidth=1;
      this.roundRect(p.x-w/2,p.y-height-29,w,24,6);c.fill();c.stroke();
      c.textAlign='center';c.fillStyle='#ffebcf';c.fillText(gate,p.x,p.y-height-13);
    }
  }
  hitSeasonal(pos) {
    const p=this.project(pos.x,pos.y);
    return [...(this.base?.seasonal?.objects || [])].sort((a,b)=>b.x+b.y-a.x-a.y).find(item=>{
      const sprite=seasonalSprites[item.type]; if (!sprite) return false;
      const root=this.project(item.x,item.y), bounds=this.bounds?.[sprite[0]];
      const width=sprite[1], height=bounds ? width*bounds.h/bounds.w : width*.84;
      return Math.abs(p.x-root.x)<width*.46 && p.y>root.y-height+5 && p.y<root.y+8;
    });
  }
  drawSeasonal(item) {
    const entry=seasonalSprites[item.type];if (!entry) return;
    const [key,width]=entry,point=this.project(item.x,item.y);
    const progress=item.readyAt>0 && !this.reduced ? Math.sin(this.now*24)*.04 : 0;
    if (this.drawImage(key,point.x,point.y,width,{rotate:progress})) return;
    const c=this.ctx;c.save();
    const horse=Number(item.type)===27, radius=width*.23;
    c.fillStyle=horse?'#bd8e5b':'#ee9040';c.strokeStyle=horse?'#5f402a':'#8e4a24';c.lineWidth=1.5;
    c.beginPath();c.ellipse(point.x,point.y-radius*.75,radius,radius*.8,0,0,Math.PI*2);c.fill();c.stroke();
    c.fillStyle=horse?'#edcca1':'#797d47';c.fillRect(point.x-radius*.1,point.y-radius*1.9,radius*.24,radius*.55);
    c.restore();
  }
  hitMushroom(point) {
    const target=this.project(point.x,point.y);
    return [...(this.base?.mushrooms?.items||[])].sort((a,b)=>b.x+b.y-a.x-a.y).find(item=>{
      const root=this.project(item.x,item.y);
      return Math.abs(target.x-root.x)<16 && target.y>root.y-28 && target.y<root.y+4;
    });
  }
  drawMushroom(item) {
    const image=this.images.mushrooms, root=this.project(item.x,item.y);
    if(!image)return;
    const width=32,cell=image.width/5, sway=!this.reduced&&item.readyAt ? Math.sin(this.now*28)*.09 : 0;
    const ctx=this.ctx;
    ctx.save();ctx.translate(root.x,root.y+2);ctx.rotate(sway);
    if(item.readyAt){ctx.shadowColor='#e5cb7b';ctx.shadowBlur=5;}
    ctx.drawImage(image,(item.variant-1)*cell,0,cell,image.height,-width/2,-width,width,width);ctx.restore();
  }
  yardSigns() {return this.battle?(this.battle.signs||[]):(this.base?.signs||[]).filter(s=>s.expires>Date.now()/1000+(this.serverOffset||0));}
  yardDecorations() { return (this.battle ? this.battle.decorations : this.base?.decorations) || []; }
  preloadDecorations(pieces) {
    for (const piece of pieces || []) {
      const id = Number(piece.item);
      if (Number.isInteger(id) && this.catalog?.decorations?.[id]) this.loadImage('decor-'+id,id+'.webp');
    }
  }
  decorationWidth(item) {
    const spec=this.catalog.decorations?.[item];
    return Math.max(spec?.group==='Flags'?33:27, Number(spec?.size||.4)*45);
  }
  drawDecoration(piece, ghost=false, valid=true) {
    const spec=this.catalog.decorations?.[piece.item];
    if(!spec)return;
    const ctx=this.ctx, root=this.project(piece.x,piece.y), width=this.decorationWidth(piece.item);
    const focus='decoration-'+piece.id;
    if(ghost || this.hover?.id===focus || this.selected?.id===focus)
      this.diamond(piece.x,piece.y,spec.size+.22,valid?'#bcd99540':'#f5997466',valid?'#d6edae':'#ffa382');
    const key='decor-'+piece.item, image=this.images[key], box=this.bounds[key];
    if(!image||!box)return;
    if(spec.group!=='Flags' || this.reduced || ghost) {
      this.drawImage(key,root.x,root.y,width,{alpha:ghost?.72:1});
      if (!ghost && [121,131].includes(piece.item)) {
        const tier=Math.max(1,Math.min(6,piece.tier||1)),height=width*box.h/box.w;
        const top=root.y-height+9;
        ctx.save();
        for(let i=0;i<tier;i++) {
          const angle=(i-2.5)*.36, x=root.x+Math.sin(angle)*width*.48, y=top-Math.cos(angle)*width*.13;
          ctx.fillStyle=piece.item===121?'#ffdc78':'#8bf1ef';ctx.strokeStyle='#292323';ctx.lineWidth=1;
          ctx.beginPath();ctx.moveTo(x,y-3.3);ctx.lineTo(x+2.4,y);ctx.lineTo(x,y+3.3);ctx.lineTo(x-2.4,y);ctx.closePath();ctx.fill();ctx.stroke();
        }
        ctx.restore();
      }
      return;
    }
    // The pole and foot stay fixed. Tiny vertical strips ripple only the cloth.
    const height=width*box.h/box.w, x=root.x-width/2,y=root.y-height+8;
    const fixed=box.w*.28, strip=box.w/24, phase=this.now*3+piece.id*.8;
    ctx.drawImage(image,box.x,box.y,fixed,box.h,x,y,width*.28,height);
    for(let col=fixed;col<box.w-.01;col+=strip) {
      const w=Math.min(strip,box.w-col), t=(col-fixed)/(box.w-fixed);
      const wind=Math.sin(phase-5*t)*t*height*.035;
      ctx.drawImage(image,box.x+col,box.y,w,box.h,x+width*col/box.w,y+wind,width*w/box.w+.08,height);
    }
  }
  hit(point) {
    const projected = this.project(point.x, point.y);
    const decorations=this.planner?[]:this.yardDecorations().map(p=>({...p,pieceId:p.id,id:"decoration-"+p.id,decoration:true}));
    const signs=this.planner?[]:this.yardSigns().map(s=>({...s,id:"sign-"+s.id,yardSign:true,type:"simplesign"}));
    return [...this.visibleBuildings(), ...decorations, ...signs].sort((a, b) => (b.x + b.y) - (a.x + a.y)).find(building => {
      if(building.yardSign){const p=this.project(building.x,building.y);return Math.abs(p.x-projected.x)<16 && projected.y>p.y-44 && projected.y<p.y+8;}
      if(building.decoration){
        const width=this.decorationWidth(building.item), box=this.bounds['decor-'+building.item];
        const p=this.project(building.x,building.y), height=box?width*box.h/box.w:width;
        return Math.abs(p.x-projected.x)<width*.45 && projected.y>p.y-height+8 && projected.y<p.y+8;
      }
      const size = this.catalog.buildings[building.type]?.size || 2, half = size / 2 + .3;
      const p = this.project(building.x, building.y), bounds = this.bounds[this.buildingArt(building)];
      const width = 40 * size * (visual[building.type] || 1.3), height = bounds ? width * bounds.h / bounds.w : width;
      return Math.abs(building.x - point.x) < half && Math.abs(building.y - point.y) < half
        || (building.hp > 0 || building.ready_at > Date.now() / 1000 + this.serverOffset) && Math.abs(projected.x - p.x) < width * .46 && projected.y > p.y - height + 10 && projected.y < p.y + 7;
    }) || null;
  }
  prepareScene(base, battle, previewType = null) {
    if (!this.pendingAssets || !this.catalog?.buildings) return;
    const buildings = battle?.buildings || base?.buildings || [];
    for (const item of base?.seasonal?.objects || []) {
      const sprite=seasonalSprites[item.type];if(sprite)this.loadImage(sprite[0],sprite[0]+'.png');
    }
    if (!battle && base?.infernoPortal) {
      const key=entranceTexture(base);
      this.loadImage(key,key+'.webp');
    }
    const moloch=this.molochYard(base,battle);
    if (moloch) {
      for (const type of new Set(buildings.map(b=>b.type))) {
        if (infernoTypes.has(type)) this.loadImage('inferno-'+type,'inferno-'+type+'.webp');
      }
    }
    const underground = battle ? ['inferno','infernoRival'].includes(battle.target?.kind) : base?.realm==='inferno';
    this.producerMotion?.prepare(buildings,underground||moloch,this.reduced,battle?.id ?? (String(base?.owner?.id)+'-'+String(base?.site?.id||base?.realm||'home')));
    for (const building of buildings) {
      const key=buildingTierKey(building,underground||moloch);
      if (key) this.loadImage(key,key+'.webp');
    }
    const types = new Set(buildings.map(b => b.type));
    if(types.has('mr2core')){
      for(const key of ['mr2core-damaged','mr2core-destroyed'])this.loadImage(key,key+'.png');
    }
    const army = battle ? battle.remaining || {} : base?.army || {};
    // Only a visible unit can need a new action or defeat sheet. At home idleUnits()
    // draws the first nine army entries. The deployment inventory can be much larger.
    if (base || battle) {
      const wanted=new Set(), scene=battle?'battle:'+battle.id:'yard:'+String(base?.owner?.id??'')+':'+(base?.realm||'surface');
      const active=new Set(), live=battle?.status==='active', continuing=this.battle?.id===battle?.id && this.battle?.status==='active';
      if (battle && (live || continuing)) {
        const old=continuing ? new Set((this.battle.units||[]).filter(u=>u.hp>0 && !u.hidden).map(u=>u.id)) : new Set();
        for (const unit of battle.units||[]) {
          if (unit.hidden || (unit.hp<=0 && !old.has(unit.id))) continue;
          const key=defeatKey(unit);if (key) wanted.add(key);
          if (unit.hp>0) active.add(unit.type);
        }
      } else if (!battle) {
        for (const [type] of Object.entries(army).filter(([,n])=>n>0).slice(0,9)) active.add(type);
      }
      if (!this.reduced) for (const type of active) if (ordinaryVoices.has(type)) {
        wanted.add(type+'-stride');if (battle) wanted.add(type+'-strike');
      }
      if (scene!==this.performanceScene) {
        this.performanceScene=scene;
        for (const key of Object.keys(performanceSheets)) {
          if (!wanted.has(key)) { delete this.images?.[key];delete this.bounds?.[key]; }
        }
        this.creatureFrames?.clear();
      }
      this.performanceWanted=wanted;
      for (const key of wanted) this.loadImage(key,performanceSheets[key].file,performanceSheets[key]);
    }
    for (const [type,n] of Object.entries(army)) if (n>0) types.add(type);
    for (const unit of battle?.units || []) types.add(unit.type);
    for (const type of [base?.activeChampion,battle?.champion?.type,battle?.defenderChampion?.type,previewType]) if (type) types.add(type);
    if (!battle && base?.construction) types.add('worker');
    if ((battle ? battle.signs : base?.signs)?.length) this.loadImage('simplesign','simplesign.png');
    if (battle?.siege?.type) this.loadImage('siege-'+battle.siege.type,'siege-'+battle.siege.type+'.png');
    if (base?.mushrooms?.items?.length || base?.mushrooms?.length) this.loadImage('mushrooms','mushrooms.png',{columns:5,rows:1,count:5});
    for (const material of new Set(buildings.filter(b=>b.hp<b.max_hp).map(b=>this.rubbleType(b.type)))) {
      this.loadImage(material,material+'.png');
    }
    for (const type of types) {
      const spec=this.catalog.buildings[type] || this.catalog.monsters?.[type] || this.catalog.champions?.[type];
      if (!spec && type!=='worker') continue;
      this.loadImage(type,spec?.art || type+'.png');
      const directions=directionSheets[type+'-directions'];
      if (directions) {
        this.loadImage(type+'-directions',directions.file,directions);

      }
      for (const action of ['walk','attack','fly','emerge','work']) {
        const key=type+'-'+action, sheet=animationSheets[key];
        if (sheet && (action!=='attack' || battle)) this.loadImage(key,sheet.file,sheet);
      }
    }
  }
  setCatalog(catalog) {
    this.catalog = { buildings: {}, monsters: {}, champions: {}, ...catalog };
    this.prepareScene(this.base,this.battle);
  }
  setServerTime(timestamp) { if (Number.isFinite(Number(timestamp))) this.serverOffset = Number(timestamp) - Date.now() / 1000; }
  molochYard(base=this.base,battle=this.battle) {
    return battle ? battle.target?.event==='hellraisers' : base?.tribe?.event==='hellraisers';
  }
  inInferno() {
    return this.battle ? ['inferno','infernoRival'].includes(this.battle.target?.kind) : this.base?.realm === 'inferno';
  }
  loadInferno() {
    this.loadImage('inferno-terrain', 'inferno-terrain.webp');
    for (const type of infernoTypes) this.loadImage('inferno-' + type, 'inferno-' + type + '.webp');
  }
  buildingTierKey(building) { return buildingTierKey(building,this.inInferno()||this.molochYard()); }
  buildingArt(building) {
    if(building.type==='mr2core' && building.hp<=building.max_hp*.5){
      const damage=building.hp<=0?'mr2core-destroyed':'mr2core-damaged';
      if(this.images?.[damage])return damage;
    }
    const tier=this.buildingTierKey(building);
    if (tier && this.images?.[tier]) return tier;
    const id = 'inferno-' + building.type;
    return (this.molochYard()||this.inInferno()) && infernoTypes.has(building.type) && this.images?.[id] ? id : building.type;
  }
  setBase(base) {
    this.portalHover = false;
    this.prepareScene(base, this.battle);
    if (this.base?.owner?.id !== base?.owner?.id) this.unitFacings?.clear();
    if (base?.realm === 'inferno') this.loadInferno();
    if (this.base?.owner?.id !== base?.owner?.id || !base?.repair) this.restoration?.clear();
    this.base = base || { buildings: [] };
    this.preloadDecorations(this.base.decorations);
    if(this.workerOwner!==this.base.owner?.id){this.workerSprites?.clear();this.workerOwner=this.base.owner?.id;}
    if(this.selected?.decoration && !this.yardDecorations().some(p=>p.id===this.selected.pieceId))this.selected=null;
    if (this.selected && !this.selected.decoration) this.selected = this.visibleBuildings().find(b => b.id === this.selected.id) || null;
  }
  setPlanner(plan) { if(plan)this.restoration?.clear(); this.planner=plan; if(!plan&&this.placement?.mode==='planner')this.placement=null; }
  setSelected(building) { this.selected = building; }
  setPlacement(placement) { if(placement?.mode==='yardSign')this.loadImage('simplesign','simplesign.png'); if (placement?.type) this.prepareScene(null,null,placement.type); if(placement?.mode==="decoration")this.preloadDecorations([placement]); this.placement = placement; this.canvas.style.cursor = placement ? 'crosshair' : 'default'; }
  async portalTremor(stage,sound) {
    const anchor=this.project(portalPosition.x,portalPosition.y),original={zoom:this.zoom,pan:{...this.pan}};
    const duration=this.reduced?350:3000, start=performance.now(), owner=this.base.owner?.id;
    const label=document.createElement('div');
    label.className='emergence-quake-label';label.setAttribute('role','status');
    label.textContent='Earthquake · '+stage+' of 5';
    this.canvas.parentElement.append(label);sound();
    await new Promise(resolve=>{
      const next=time=>{
        if (this.battle || this.base?.owner?.id!==owner || !this.canvas.isConnected || !this.isVisible) { resolve();return; }
        const t=Math.min(1,(time-start)/duration);
        if (!this.reduced) {
          const ease=Math.min(1,t/.46), flex=ease*ease*(3-2*ease);
          const shake=t>.42?Math.sin((t-.42)*Math.PI/.58)*7:0;
          this.zoom=original.zoom+(1.52-original.zoom)*flex;
          const scale=this.transform.s;
          this.pan.x=original.pan.x*(1-flex)-anchor.x*scale*flex+shake*Math.sin(t*107);
          this.pan.y=original.pan.y*(1-flex)+(295-anchor.y+40)*scale*flex+shake*.4*Math.sin(t*139);
        }
        if (t===1) resolve();else requestAnimationFrame(next);
      };
      requestAnimationFrame(next);
    });
    label.remove();
    if (!this.battle && this.base?.owner?.id===owner) {this.zoom=original.zoom;this.pan=original.pan;}
  }
  setZoom(zoom) { this.zoom = clamp(zoom, .7, 2.4); }
  resetCamera() { this.zoom = 1; this.pan = { x: 0, y: 0 }; }

  setBattle(battle) {
    this.prepareScene(this.base,battle);
    this.preloadDecorations(battle?.decorations);
    if (['inferno','infernoRival'].includes(battle?.target?.kind)) this.loadInferno();
    if(battle)this.restoration?.clear();
    if (battle?.brukkargStage === 10 && !this.images.krallen) void this.loadImage('krallen','krallen.png');
    const dismissed = battle?.training && battle.status === 'finished' && battle.result?.outcome === 'dismissed';
    if (!dismissed || battle.id !== this.battle?.id) this.sirenRetreat = null;
    if (dismissed && !this.reduced && this.battle?.training && this.battle.id === battle.id &&
        this.battle.status === 'active' && !this.battle.countdown &&
        Number.isFinite(battle.entry?.x) && Number.isFinite(battle.entry?.y)) {
      // Start where those attackers were actually drawn, even between polls.
      const origins = new Map();
      for (const unit of this.battle.units || []) {
        if (unit.team === 'attacker' && unit.hp > 0 && !unit.hidden) origins.set(unit.id, this.unitPosition(unit));
      }
      if (origins.size) this.sirenRetreat = {start:performance.now(), origins, entry:battle.entry};
    }
    this.performances ??= new CombatPerformances();
    const previousBattle=this.battle;
    if (!battle || this.battle?.id !== battle.id) this.unitFacings?.clear();
    if (!battle) { this.performances.fallen.clear(); this.battle = null; this.previousUnits.clear(); this.unitEffects.clear(); this.fx = []; this.lastEvent = 0; return; }
    const continuing = this.battle?.id === battle.id;
    const finishing = this.battle?.id === battle.id && this.battle.status === 'active' && battle.status === 'finished';
    if (this.battle?.id !== battle.id) { this.lastEvent = 0; this.previousUnits.clear(); this.unitEffects.clear(); this.fx = []; }
    else this.previousUnits = new Map((this.battle.units || []).map(unit => [unit.id, this.unitPosition(unit)]));
    this.battle = battle;
    this.snapshotAt = performance.now();
    this.setServerTime(battle.serverTime);
    const visibleUnits = new Map((battle.units || []).filter(unit => unit.hidden !== true).map(unit => [String(unit.id), unit]));
    this.performances.observe(previousBattle,battle,visibleUnits,this.unitFacings,this.snapshotAt,this.callbacks.voice,globalThis.document?.hidden);
    for (const {key} of this.performances.fallen.values()) if (!this.images[key] && this.pendingAssets) {
      this.performanceWanted?.add(key);
      this.loadImage(key,performanceSheets[key].file,performanceSheets[key]);
    }
    const livingUnits = new Map([...visibleUnits].filter(([, unit]) => unit.hp > 0 && !['dead', 'retreat'].includes(unit.state)));
    for (const id of this.unitFacings?.keys() || []) {
      if (!livingUnits.has(String(id))) this.unitFacings.delete(id);
    }
    let firstAerialShotTime;
    for (const event of battle.events || []) {
      if (event.id <= this.lastEvent) continue;
      this.lastEvent = event.id;
      if (battle.elapsed - event.t > 3) continue;
      const effect = { ...event, start: performance.now() };
      if (event.type === 'shot' && event.kind === 'aerial') {
        // One poll can contain several rounds from the same volley.
        firstAerialShotTime ??= event.t;
        effect.start += Math.min(600, Math.max(0, event.t - firstAerialShotTime) * 1000);
      }
      this.fx.push(effect);
      if (event.sourceId && ['deploy', 'emerge'].includes(event.type)) this.unitEffects.set(`${event.sourceId}:${event.type}`, effect);
      const attacker = livingUnits.get(String(event.sourceId));
      const recent = Number.isFinite(event.t) && event.t <= battle.elapsed && battle.elapsed - event.t <= 1;
      const strike = attacker && !['zafreeti','vorg'].includes(attacker.type) && (ordinaryVoices.has(attacker.type) || attacker.champion) && ['hit', 'shot', 'strike'].includes(event.type);
      const healing = (attacker?.type === 'zafreeti' || attacker?.type === 'vorg') && event.type === 'heal' && event.amount > 0;
      if (battle.status === 'active' && (strike || healing) && recent) {
        const key = `${attacker.id}:attack`, previous = this.unitEffects.get(key);
        // Twin rockets and multi-target hits share one strike, even across polls.
        if (!previous || previous.t !== event.t) this.unitEffects.set(key, effect);
      }
      const source = visibleUnits.get(String(event.sourceId));
      const burst = source && (event.type === 'explode' && source.type === 'eyera' || event.type === 'acid' && source.type === 'projectx');
      if ((battle.status === 'active' || finishing) && burst && source.hp <= 0 && source.state !== 'retreat' && recent
        && Number.isFinite(event.x) && Number.isFinite(event.y)) {
        // These actions kill their source before a snapshot is sent. This is a
        // short visual echo of a confirmed burst, never a living combat unit.
        const key = `${source.id}:burst`, previous = this.unitEffects.get(key);
        if (!previous || previous.t !== event.t) this.unitEffects.set(key, { ...effect, unit: { ...source, x: event.x, y: event.y } });
        this.unitEffects.delete(`${source.id}:attack`);
      }
      if (event.type === 'shot' && event.kind !== 'aerial') this.callbacks.sound?.(['laser','railgun'].includes(event.kind) ? 'laser' : 'shot');
      if (event.type === 'quake_impact' || event.type === 'korath_stomp') this.callbacks.sound?.('catapult-impact');
      if (event.type === 'destroy' && continuing) this.callbacks.sound?.('hit');
      if (['explode', 'trap'].includes(event.type)) {
        this.callbacks.sound?.(event.kind === 'heavytrap' ? 'heavytrap' : 'explode');
      }
      if (event.type === 'heal' || event.type === 'resurrect') this.callbacks.sound?.('heal');
      if (event.type === 'emerge') this.callbacks.sound?.('burrow');
      if (event.type === 'deploy' && event.champion) this.callbacks.sound?.('champion');
      if (event.type === 'champion_retreat') this.callbacks.sound?.('champion-retreat');
      if(event.type==='siege_launch')this.callbacks.sound?.('catapult');
      if(event.type==='siege_blast')this.callbacks.sound?.('explode');
      if(event.type==='siege_break')this.callbacks.sound?.('hit');
      if (event.type === 'catapult_launch') this.callbacks.sound?.('catapult');
      if (event.type === 'catapult_impact') this.callbacks.sound?.(event.kind === 'putty' ? 'boost' : 'catapult-impact');
      if (event.type === 'blink') this.callbacks.sound?.('blink');
      if (event.type === 'venom') this.callbacks.sound?.('venom');
      if (event.type === 'acid') this.callbacks.sound?.('explode');
    }
    this.fx = this.fx.slice(-160);
    for (const [key, effect] of this.unitEffects) {
      if (key.endsWith(':burst')) {
        const source = visibleUnits.get(String(effect.sourceId));
        if (!source || source.hp > 0 || source.state === 'retreat' || this.snapshotAt - effect.start >= burstDuration * 1000) this.unitEffects.delete(key);
        continue;
      }
      const unit = livingUnits.get(String(effect.sourceId));
      const actionState = effect.type === 'heal' ? unit?.state === 'heal' : ['attack', 'attacking'].includes(unit?.state);
      if (!unit || this.snapshotAt - effect.start > 2000 || key.endsWith(':attack') && (battle.status !== 'active' || !actionState)) this.unitEffects.delete(key);
    }
    while (this.unitEffects.size > 512) this.unitEffects.delete(this.unitEffects.keys().next().value);
  }
  unitPosition(unit) {
    const previous = this.previousUnits.get(unit.id);
    if (!previous) return { x: unit.x, y: unit.y };
    const amount = clamp((performance.now() - (this.snapshotAt || 0)) / 650, 0, 1);
    return { x: lerp(previous.x, unit.x, amount), y: lerp(previous.y, unit.y, amount) };
  }
  battleElapsed() {
    if (!this.battle) return 0;
    return Math.min(this.battle.duration || 300, this.battle.elapsed + (this.battle.status === 'active' ? Math.max(0, performance.now() - (this.snapshotAt || performance.now())) / 1000 : 0));
  }
  loop(timestamp) {
    this.now = timestamp / 1000;
    if (this.isVisible && this.w && this.h && this.canvas.isConnected) this.draw();
    requestAnimationFrame(this.loop);
  }

  draw() {
    const context = this.ctx, dpr = this.dpr;
    context.setTransform(dpr, 0, 0, dpr, 0, 0);
    context.clearRect(0, 0, this.w, this.h);
    const inferno = this.inInferno();
    context.fillStyle = inferno ? '#392b39' : '#416849'; context.fillRect(0, 0, this.w, this.h);
    const background = this.images[inferno ? 'inferno-terrain' : 'terrain'];
    if (background) {
      const ratio = Math.max(this.w / background.width, this.h / background.height);
      context.drawImage(background, (this.w - background.width * ratio) / 2, (this.h - background.height * ratio) / 2, background.width * ratio, background.height * ratio);
    }
    const vignette = context.createRadialGradient(this.w / 2, this.h / 2, 80, this.w / 2, this.h / 2, Math.max(this.w, this.h) * .7);
    vignette.addColorStop(0, '#15342100'); vignette.addColorStop(1, inferno ? '#190f2a72' : '#0d2c2475');
    context.fillStyle = vignette; context.fillRect(0, 0, this.w, this.h);
    const transform = this.transform;
    context.translate(transform.x, transform.y); context.scale(transform.s, transform.s);
    this.drawGround();
    this.drawTrainingApproach();
    this.drawCatapultTargets();
    this.drawPlannerGuides();
    const buildings = this.visibleBuildings();
    if (this.selected && !this.placement && buildings.some(b => b.id === this.selected.id)) {
      const spec = this.catalog.buildings[this.selected.type];
      const range = this.selected.compoundRange ?? (this.inInferno() && this.selected.type==='housing' && this.selected.level>0 ? 12.5+.75*(Math.min(6,this.selected.level)-1) : null) ?? spec?.ranges?.[Math.max(0, this.selected.level - 1)] ?? spec?.range;
      if (range) {
        const point = this.project(this.selected.x, this.selected.y);
        context.beginPath(); context.ellipse(point.x, point.y, range * 28.284, range * 14.142, 0, 0, Math.PI * 2);
        context.fillStyle = '#dae9b416'; context.fill(); context.strokeStyle = '#e0edbc65';
        context.lineWidth = 1.5; context.setLineDash([8, 6]); context.stroke(); context.setLineDash([]);
      }
    }
    const objects = buildings.map(building => ({ kind: 'building', value: building, depth: building.x + building.y }));
    if (this.portalSprite()) objects.push({kind:'portal',depth:portalPosition.x+portalPosition.y});
    if (!this.battle) for(const item of this.base?.mushrooms?.items||[]) objects.push({kind:'mushroom',value:item,depth:item.x+item.y});
    for(const item of this.yardSigns())objects.push({kind:"yard-sign",value:item,depth:item.x+item.y});
    for(const item of this.yardDecorations())objects.push({kind:'decoration',value:item,depth:item.x+item.y});
    if (!this.battle && !this.planner) for (const item of this.base?.seasonal?.objects || []) {
      objects.push({kind:'seasonal',value:item,depth:item.x+item.y});
    }
    const units = this.battle ? this.battle.units || [] : this.planner ? [] : this.idleUnits();
    for(const worker of this.workerVisuals())objects.push({kind:'worker',value:worker,depth:worker.x+worker.y+.2});
    // Aura footprints stay below buildings and sprites, rather than covering the fight.
    if (this.battle?.status === 'active') for (const unit of units) this.drawAura(unit);
    for (const unit of units) {
      if (unit.hp <= 0 || unit.hidden === true) continue;
      const dismissed = this.battle?.training && this.battle.result?.outcome === 'dismissed' && unit.team === 'attacker';
      const point = dismissed ? this.retreatPosition(unit) : this.battle ? this.unitPosition(unit) : unit;
      if (!point) continue;
      const air = movementOf(unit, this.catalog) === 'air';
      objects.push({ kind: dismissed ? 'siren-retreat' : 'unit', value: { ...unit, ...point }, depth: point.x + point.y + (air ? 1.4 : .3) });
    }
    if (this.battle) for (const [id,effect] of this.performances?.fallen || []) {
      const pose=defeatPose(effect,performance.now(),this.reduced);
      if (!pose) { this.performances.fallen.delete(id);continue; }
      objects.push({kind:'defeat-pose',value:{effect,pose},depth:effect.x+effect.y+.3});
    }
    if (this.battle) for (const [key, effect] of this.unitEffects) {
      if (!key.endsWith(':burst') || this.performances?.fallen.has(String(effect.unit?.id)) && this.images[this.performances.fallen.get(String(effect.unit.id)).key] || !this.burstFrame(effect)) continue;
      objects.push({ kind: 'burst', value: effect, depth: effect.unit.x + effect.unit.y + .3 });
    }
    const siege = this.battle?.siege;
    if (this.battle?.status === 'active' && siege?.active && siege.type !== 'jars') {
      const {x,y}=siege.active;
      objects.push({kind:'siege-device',value:siege,depth:x+y+.45});
    }
    objects.sort((a, b) => a.depth - b.depth);
    for (const object of objects) {
      if (object.kind === 'building') this.drawBuilding(object.value);
      else if (object.kind === 'yard-sign'){const p=this.project(object.value.x,object.value.y);this.drawImage('simplesign',p.x,p.y,36,{rotate:this.reduced?0:Math.sin(this.now*1.9+object.value.x)*.012});}
      else if (object.kind === 'decoration') this.drawDecoration(object.value);
      else if (object.kind === 'portal') this.drawPortal();
      else if (object.kind === 'seasonal') this.drawSeasonal(object.value);
      else if (object.kind === 'mushroom') this.drawMushroom(object.value);
      else if (object.kind === 'siege-device') this.drawSiegeDevice(object.value);
      else if (object.kind === 'siren-retreat') this.drawRetreatingUnit(object.value);
      else if (object.kind === 'defeat-pose') {
        const {effect,pose}=object.value, pos=this.project(effect.x,effect.y);
        this.drawImage(effect.key,pos.x,pos.y,unitWidths[effect.unit.type]||42,{...pose,zombie:effect.unit.zombie===true});
      } else if (object.kind === 'burst') this.drawUnitBurst(object.value);
      else if(object.kind==='worker')this.drawWorker(object.value);
      else this.drawUnit(object.value);
    }
    this.drawEffects();
    if (this.placement && this.pointer) this.drawGhost();
    if (this.hover && !this.placement && !this.battle && (this.hover.decoration||buildings.some(b => b.id === this.hover.id))) this.drawLabel(this.hover);
    this.drawAmbient();
    context.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  diamond(x, y, size, fill, stroke) {
    const context = this.ctx, a = this.project(x - size / 2, y - size / 2), b = this.project(x + size / 2, y - size / 2);
    const c = this.project(x + size / 2, y + size / 2), d = this.project(x - size / 2, y + size / 2);
    context.beginPath(); context.moveTo(a.x, a.y); context.lineTo(b.x, b.y); context.lineTo(c.x, c.y); context.lineTo(d.x, d.y); context.closePath();
    if (fill) { context.fillStyle = fill; context.fill(); }
    if (stroke) { context.strokeStyle = stroke; context.stroke(); }
  }
  // The server chooses a legal point along the requested approach, even when
  // buildings block its center. Mark that actual point during the countdown.
  drawTrainingApproach() {
    const battle = this.battle;
    if (!battle?.training || battle.status !== 'active' || !(battle.countdown > 0)) return;
    for (const entry of Object.values(battle.entries || [battle.entry])) this.drawEntryMarker(entry);
  }
  drawEntryMarker(entry) {
    if (!Number.isFinite(entry?.x) || !Number.isFinite(entry?.y)) return;
    const center = this.project(16, 16);
    const point = this.project(entry.x, entry.y);
    const angle = Math.atan2(center.y - point.y, center.x - point.x);
    const pulse = this.reduced ? 0 : (this.now % 1.1) / 1.1;
    const ctx = this.ctx;
    ctx.save();
    ctx.translate(point.x, point.y);
    ctx.fillStyle = '#192925b8';
    ctx.strokeStyle = '#c0f369';
    ctx.lineWidth = 2;
    ctx.beginPath(); ctx.ellipse(0, 0, 18, 8, 0, 0, 2 * Math.PI); ctx.fill(); ctx.stroke();
    ctx.rotate(angle);
    ctx.lineWidth = 3;
    ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    // Both chevrons point inward, along the selected edge.
    for (let i = 0; i < 2; i++) {
      const x = 7 + 15 * i + 6 * pulse;
      ctx.beginPath(); ctx.moveTo(x - 6, -6); ctx.lineTo(x, 0); ctx.lineTo(x - 6, 6); ctx.stroke();
    }
    ctx.restore();
  }
  drawGround() {
    const context = this.ctx;
    const inferno = this.inInferno();
    const extent=this.yardExtent, low=-this.yardMargin, high=32+this.yardMargin;
    context.lineWidth = 2; this.diamond(16, 16, extent, inferno ? '#77526619' : '#7eaa5828', inferno ? '#e8b08652' : '#cde6a93d');
    context.save(); context.setLineDash([8, 8]); this.diamond(16, 16, extent-.2, null, '#d2e3ab58'); context.restore();
    if (this.planner?.grid || this.placement && !['deploy', 'champion', 'catapult','siege'].includes(this.placement.mode)) {
      context.strokeStyle = '#e5efc627'; context.lineWidth = 1;
      for (let i = low; i <= high; i++) {
        let a = this.project(i, low), b = this.project(i, high);
        context.beginPath(); context.moveTo(a.x, a.y); context.lineTo(b.x, b.y); context.stroke();
        a = this.project(low, i); b = this.project(high, i);
        context.beginPath(); context.moveTo(a.x, a.y); context.lineTo(b.x, b.y); context.stroke();
      }
    } else for (let i = 0; i < 70; i++) {
      const point = this.project(low+(i * 13.37) % (extent-1)+.5, low+(i * 7.71) % (extent-1)+.5);
      context.fillStyle = inferno ? (i % 3 ? '#ea996b35' : '#b99bc239') : (i % 3 ? '#ceca7b38' : '#c6df9430');
      context.beginPath(); context.ellipse(point.x, point.y, 2 + i % 3, 1.4, 0, 0, Math.PI * 2); context.fill();
    }
  }

  drawImage(type, x, y, width, { alpha = 1, flip = false, rotate = 0, bounce = 0, squash = 1, frame = null, outline = null, pose = null, zombie = false } = {}) {
    const image = this.images[type];
    if (!image) return false;
    const context = this.ctx, sheet = animationSheets[type] || evolutionSheets[type] || directionSheets[type] || performanceSheets[type];
    const bounds = this.bounds[type] || { x: 0, y: 0, w: image.width / (sheet?.columns || 1), h: image.height / (sheet?.rows || 1) };
    const height = width * bounds.h / bounds.w;
    let sourceX = bounds.x, sourceY = bounds.y;
    if (sheet && frame !== null) {
      const index = clamp(Math.floor(frame), 0, sheet.count - 1);
      sourceX += index % sheet.columns * image.width / sheet.columns;
      sourceY += Math.floor(index / sheet.columns) * image.height / sheet.rows;
    }
    context.save(); context.translate(x, y - bounce); context.rotate(rotate); context.scale(flip ? -1 : 1, squash); context.globalAlpha = alpha;
    if (outline) { context.shadowColor = outline; context.shadowBlur = 4; }
    if (zombie) context.filter = 'grayscale(.88) sepia(.28) hue-rotate(112deg)';
    if (pose === null) {
      context.drawImage(image, sourceX, sourceY, bounds.w, bounds.h, -width / 2, -height + 8, width, height);
    } else {
      this.creatureFrames ??= new Map();
      const key = type + ':' + (frame ?? '-') + ':' + pose;
      let body = this.creatureFrames.get(key);
      if (!body) {
        const directional = Boolean(directionSheets[type]);
        const species = directional ? type.slice(0,-11) : type;
        body = makeCreatureFrame(species, pose, image, {x:sourceX,y:sourceY,w:bounds.w,h:bounds.h}, directional ? 256 : 384);
        // Bound the shared cutout cache across large mixed armies, directions and yards.
        if (body && this.creatureFrames.size >= 128) this.creatureFrames.delete(this.creatureFrames.keys().next().value);
        if (body) this.creatureFrames.set(key, body);
      }
      if (body) {
        const scale = width / body.width;
        context.drawImage(body.canvas, -width / 2 - body.margin * scale, -height + 8 - body.margin * scale,
          body.canvas.width * scale, body.canvas.height * scale);
      } else {
        context.drawImage(image, sourceX, sourceY, bounds.w, bounds.h, -width / 2, -height + 8, width, height);
      }
    }
    context.restore(); return true;
  }
  drawBaiterPump(building, point, width) {
    const sheet = this.images['baiter-work'];
    if (!sheet || this.reduced || this.planner) return false;
    const active = this.battle?.training && this.battle.status === 'active';
    // Quiet yards only flex the actuator; a drill cycles all the way through
    // compression, musk release, and recovery. A ruined or unfinished pump is
    // drawn separately by drawBuilding and cannot emit a spray.
    if (this.battle && !active) return false;
    const frame = active
      ? Math.floor(this.now * 3.4) % 4
      : (this.now + building.id * .13) % 3.8 < .4 ? 1 : 0;
    // Align by the metal canister (x=109..521), not by the changing plume.
    // The full 627px cell is painted to retain the mist left of the nozzle.
    const cell = sheet.width / 2, cellHeight = sheet.height / 2;
    const scale = width * .97 / 412;
    this.ctx.drawImage(sheet, frame % 2 * cell, Math.floor(frame / 2) * cellHeight, cell, cellHeight,
      point.x - 315 * scale, point.y + 8 - 580 * scale, 627 * scale, 627 * scale);
    return true;
  }
  rubbleType(type) {
    if (['townhall','wall','stonewall','bunker','stronghold','outpostdefender','championcage','championchamber','pebble'].includes(type)) return 'rubble-stone';
    if (['railgun','quaketower','magmatower','infernocannon','spurtzcannon','diamondspurtz','cannon','laser','aerial','tesla','catapult','flinger','goo','juicer','baiter','lab','putty','heavytrap','trap','siegefactory','siegelab'].includes(type)) return 'rubble-machinery';
    return 'rubble-timber';
  }
  showRestored(owner, ids) {
    if (this.reduced || this.battle || this.planner || !this.base?.repair || this.base.owner?.id !== owner) return;
    const restored = new Set(ids), until = performance.now() + 850;
    this.restoration ??= new Map();
    for (const b of this.visibleBuildings()) {
      if (restored.has(b.id) && b.level > 0 && b.hp === b.max_hp && b.hp > 0) {
        this.restoration.set(b.id, {owner,type:b.type,until});
      }
    }
  }
  drawRestoration(b, point, width, height) {
    const note = this.restoration?.get(b.id);
    if (!note) return;
    const progress = 1 - (note.until - performance.now()) / 850;
    if (progress >= 1 || this.reduced || this.battle || this.planner || !this.base.repair ||
        note.owner !== this.base.owner?.id || note.type !== b.type || b.hp !== b.max_hp) {
      this.restoration.delete(b.id); return;
    }
    // The complete, healthy structure already occupies its foundation. A rising
    // ring and a few construction sparks mark the accepted repair.
    const ctx = this.ctx, phase = clamp(progress,0,1), y = point.y + 5 - phase * height;
    const radius = width * (.42 - phase * .25);
    ctx.save(); ctx.globalAlpha = Math.sin(Math.PI * phase) * .84;
    ctx.strokeStyle = '#defbc0'; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.ellipse(point.x,y,radius,radius * .24,0,0,Math.PI*2); ctx.stroke();
    for (let i=0; i<6; ++i) {
      const a = i * 2.39996 + b.id * .72, lift = (phase + i / 6) % 1;
      const x = point.x + Math.sin(a) * width * .38, sparkY = y - lift * 13 + Math.cos(a) * 5;
      ctx.fillStyle = i % 2 ? '#fff2b4' : '#a9e6c0';
      ctx.beginPath();ctx.moveTo(x,sparkY-3);ctx.lineTo(x+1.6,sparkY);ctx.lineTo(x,sparkY+3);ctx.lineTo(x-1.6,sparkY);ctx.closePath();ctx.fill();
    }
    ctx.restore();
  }
  wearLevel(b) {
    if (!(b.level > 0 && b.hp > 0 && b.max_hp > 0 && b.hp < b.max_hp) || isTrap(b.type) || this.untargetable(b)) return 0;
    return Math.min(4, Math.ceil((1 - b.hp / b.max_hp) * 4));
  }
  drawWear(b, point, width, tier) {
    const art = this.buildingArt(b), image = this.images[art], bounds = this.bounds[art];
    if (!image || !bounds || !tier) return;
    // Cache small, alpha-masked weathering plates. No per-frame pixel readback.
    const key = art + '/' + tier;
    this.wearPlates ??= new Map();
    let plate = this.wearPlates.get(key);
    if (!plate) {
      if (typeof document === 'undefined' || !document.createElement) return;
      plate = document.createElement('canvas');
      plate.width = 320; plate.height = Math.max(1,Math.round(320 * bounds.h / bounds.w));
      const g = plate.getContext?.('2d');
      if (!g) return;
      const stone = this.rubbleType(b.type) === 'rubble-stone', wood = this.rubbleType(b.type) === 'rubble-timber';
      const w = plate.width, h = plate.height;
      g.fillStyle = 'rgba(47,34,29,'+(tier * .078)+')';
      g.fillRect(0,0,w,h);
      let seed = Array.from(b.type).reduce((s,c) => Math.imul(s,31) + c.charCodeAt(0) >>> 0, 17);
      const sample = () => ((seed = (Math.imul(seed,1664525)+1013904223) >>> 0) / 4294967296);
      for (let i=0; i<tier*2; ++i) {
        const x = (.22 + sample()*.56)*w, y = (.12+sample()*.58)*h;
        const scale = stone ? 1 : wood ? .73 : .58;
        const points = [[x,y]];
        for(let k=1;k<=4;++k) points.push([
          points[k-1][0] + (wood ? 4+sample()*9 : (sample()-.48)*26)*scale,
          points[k-1][1] + (5+sample()*9)*scale,
        ]);
        g.lineJoin='round';
        for (const highlight of [false,true]) {
          g.strokeStyle=highlight ? (stone?'#d3c5a2a4':wood?'#dfab72a1':'#bdc8cba0') : '#362e30be';
          g.lineWidth=highlight?1.6:4;
          g.beginPath();points.forEach(([px,py],j)=>j?g.lineTo(px+(highlight?2.2:0),py):g.moveTo(px+(highlight?2.2:0),py));g.stroke();
        }
        if (tier>=3) {
          g.fillStyle=stone?'#3b37468a':wood?'#3b29238a':'#423c3c9c';
          g.beginPath();g.moveTo(x-5,y-2);g.lineTo(x+6,y);g.lineTo(x+4,y+7);g.lineTo(x-4,y+10);g.closePath();g.fill();
        }
      }
      g.globalCompositeOperation='destination-in';
      g.drawImage(image,bounds.x,bounds.y,bounds.w,bounds.h,0,0,w,h);
      if (this.wearPlates.size >= 48) this.wearPlates.delete(this.wearPlates.keys().next().value);
      this.wearPlates.set(key,plate);
    }
    const height = width * bounds.h / bounds.w;
    this.ctx.drawImage(plate,point.x-width/2,point.y-height+8,width,height);
  }
  drawDamageDebris(b, point, width, tier) {
    if (tier < 2) return;
    const lost = 1 - b.hp / b.max_hp, side = b.id % 2 ? -1 : 1;
    // Dislodged pieces collect on the near edge as actual damage increases.
    this.drawImage(this.rubbleType(b.type), point.x + side * width * .26, point.y + 3, width * (.10 + .23 * lost), {flip:side < 0});
    if (tier < 4 || this.reduced) return;
    const ctx = this.ctx, chimney = point.x + side * width * .14;
    const drift = this.now * .34 + b.id * .131;
    ctx.save();
    for (let i=0; i<3; ++i) {
      const t = (drift + i/3) % 1, radius = width*(.022 + t*.057);
      ctx.globalAlpha = Math.sin(t*Math.PI)*(.17 + .09*lost);
      ctx.fillStyle = '#716761';
      ctx.beginPath();ctx.ellipse(chimney+t*width*.10, point.y-width*(.42+t*.4), radius, radius*.81, -.35, 0, Math.PI*2);ctx.fill();
    }
    ctx.restore();
  }
  drawRuins(b, point, width) {
    const key = this.rubbleType(b.type), trap = isTrap(b.type);
    const scale = ['wall','stonewall'].includes(b.type) ? .91 : trap ? .74 : .92;
    // A spent trap is already exposed by the server; concealed traps never
    // appear in visibleBuildings and leave no hints in the terrain.
    if (this.drawImage(key,point.x,point.y,width*scale,{flip:(b.id%2)===0})) return;
    const ctx=this.ctx;
    for(let i=0;i<5;i++) {
      const x=point.x+(i-2)*width*.13,y=point.y-2+(i%2)*4;
      ctx.fillStyle=i%2?'#726d59':'#595c4c';
      ctx.beginPath();ctx.moveTo(x-8,y);ctx.lineTo(x-5,y-5);ctx.lineTo(x+6,y-8);ctx.lineTo(x+10,y-2);ctx.lineTo(x+4,y+3);ctx.closePath();ctx.fill();
    }
  }
  drawBuilding(building) {
    if (building.hidden === true || building.visible === false) return;
    const context = this.ctx, point = this.project(building.x, building.y), spec = this.catalog.buildings[building.type] || {}, size = spec.size || 2;
    const selected = this.selected?.id === building.id && !this.battle, hovered = this.hover?.id === building.id && !this.placement;
    const constructing = building.ready_at > Date.now() / 1000 + this.serverOffset;
    // A fortifying tower stays offline for its entire saved battle, even after
    // its home clock reaches zero. Only a new server snapshot grants a tier.
    const fortifying = this.battle ? building.fortifying === true : building.fortify_ready_at > Date.now() / 1000 + this.serverOffset;
    const dead = building.level > 0 && building.hp <= 0;
    const untargetable = this.untargetable(building);
    context.save(); context.lineWidth = selected ? 2.5 : 1;
    this.diamond(building.x, building.y, size + .25, dead ? '#4b493575' : selected ? '#d9f1ad28' : '#716e4050', selected ? '#ecf6af' : hovered ? '#e4edb09a' : '#e6d99821');
    context.fillStyle = dead ? '#28302559' : '#1e352b45';
    context.beginPath(); context.ellipse(point.x + 7, point.y + 7, size * 20, size * 8, -.1, 0, Math.PI * 2); context.fill();
    const width = 40 * size * (visual[building.type] || 1.3);
    if (dead) {
      if(building.type!=='mr2core'||!this.drawImage('mr2core-destroyed',point.x,point.y,width))this.drawRuins(building, point, width);
      if (constructing || fortifying) {
        context.fillStyle = '#efe4b7'; context.font = '13px system-ui'; context.textAlign='center';
        context.fillText('⚒',point.x,point.y+16);
      }
      context.restore(); return;
    }
    const art = this.buildingArt(building);
    const upgraded = art === this.buildingTierKey(building);
    const pumping = !upgraded && building.type === 'baiter' && building.level > 0 && !constructing && !fortifying &&
      this.drawBaiterPump(building, point, width);
    const ram = !upgraded && !constructing && !fortifying && building.type==='quaketower' && building.quakeImpactAt && this.battle && drawQuakeMechanism(this,building,point,width);
    const moving = upgraded && !this.reduced && !this.planner && !constructing && !fortifying;
    const pump = moving && building.type==='baiter' && (!this.battle || (this.battle.training && this.battle.status==='active'));
    const charging = moving && this.battle && building.type==='quaketower' && building.quakeImpactAt>this.battleElapsed() && building.quakeImpactAt-this.battleElapsed()<=4.25;
    const motion = pump ? (.5+.5*Math.sin(this.now*(this.battle?12:4)+building.id)) : charging ? (1+Math.sin(this.now*12))*.5 : 0;
    const producing=this.producerMotion?.draw(this,building,art,point,width,constructing,fortifying);
    if (!producing && !pumping && !ram && !this.drawImage(art, point.x, point.y, width, { alpha: constructing ? .65 : fortifying ? .82 : 1, squash:1-motion*(pump ? .028 : .016) })) {
      this.diamond(building.x, building.y, size, '#657c49', '#bdcf8c');
      context.fillStyle = '#e4eccc'; context.textAlign = 'center'; context.font = 'bold 14px system-ui';
      context.fillText(spec.name || building.type, point.x, point.y - 14);
    }
    const wear = this.wearLevel(building);
    this.drawWear(building, point, width, wear);
    this.drawDamageDebris(building, point, width, wear);
    if (building.towerOverdrive && building.hp>0 && this.battle?.status==='active') {
      context.save();context.strokeStyle='#ffca75';context.lineWidth=2;
      const angle=this.reduced?0:this.now*2;
      for(let i=0;i<4;i++){
        const start=angle+i*Math.PI/2;
        context.beginPath();context.ellipse(point.x,point.y+1,width*.38,width*.15,0,start,start+.75);context.stroke();
      }
      context.restore();
    }
    if (this.battle?.status === 'active' && building.jarHp > 0) this.drawSiegeJar(building, point, width);
    if (this.battle && building.quakeImpactAt && building.hp > 0) drawQuakeCharge(this,building,point,width);
    if (!constructing && !fortifying && !this.reduced) this.drawBuildingMotion(building, point, width);
    if (!constructing && !fortifying && building.type === 'outpostdefender') this.drawNetworkBeacon(point, width);
    this.drawFortification(building, point, width, size, fortifying, selected || hovered);
    if (selected || hovered) this.drawNetworkDefense(building, point, width, size);
    if (constructing) {
      const targetLevel = building.upgrade_to || (building.level || 0) + 1;
      const buildSeconds = spec.levels?.[targetLevel]?.buildTime ?? (spec.buildTime || 30) * Math.pow(1.6, building.level || 0);
      const duration = Math.max(1, Math.ceil(buildSeconds / (this.catalog.speed || 1)));
      const amount = 1 - clamp((building.ready_at - Date.now() / 1000 - this.serverOffset) / duration, 0, 1);
      this.drawBar(point.x, point.y + 17, 60, amount, '#dfc37f');
      context.fillStyle = '#f4e8b9'; context.font = '12px system-ui'; context.textAlign = 'center'; context.fillText('⚒', point.x, point.y - width * .68);
    } else if (!untargetable && (this.battle || selected || building.hp < building.max_hp) && building.hp > 0) {
      this.drawBar(point.x, point.y + 12, Math.max(35, size * 18), building.hp / building.max_hp, building.hp / building.max_hp > .4 ? '#b9df8b' : '#e6a279');
    }
    if (untargetable && (this.battle || selected || hovered)) {
      context.font = '600 10px system-ui'; context.textAlign = 'center';
      context.fillStyle = '#17352aeb'; this.roundRect(point.x - 60, point.y + 10, 120, 19, 5); context.fill();
      context.fillStyle = '#dbebbc'; context.fillText('CAPTURE THE DEFENSES', point.x, point.y + 23);
    }
    if (building.level > 1 && !this.battle) {
      const levelY = untargetable && (selected || hovered) ? 33 : 20;
      context.fillStyle = '#183528cc'; this.roundRect(point.x - 9, point.y + levelY, 18, 16, 4); context.fill();
      context.fillStyle = '#e1edc8'; context.font = 'bold 10px system-ui'; context.textAlign = 'center'; context.fillText(building.level, point.x, point.y + levelY + 11);
    }
    const bounds = this.bounds[this.buildingArt(building)];
    this.drawRestoration(building,point,width,bounds ? width*bounds.h/bounds.w : width);
    context.restore();
  }
  drawFortification(building, point, width, size, fortifying, detailed) {
    const value = Number(building.fortification ?? 0), tier = Number.isInteger(value) && value >= 0 && value <= 4 ? value : 0;
    if (!tier && !fortifying) return;
    const context = this.ctx;
    context.save();
    if (tier) {
      const colors = ['#aab9bd', '#d1b887', '#c8dfeb', '#e9dbb2'], color = colors[tier - 1];
      // Reinforced foundation and a separate shield badge do not alter the HP bar.
      context.strokeStyle = color; context.lineWidth = 1.4 + tier * .35;
      this.diamond(building.x, building.y, size + .1, null, `${color}b0`);
      const x = point.x + width * .27, y = point.y - 12;
      context.fillStyle = '#1a302de8'; context.strokeStyle = color; context.lineWidth = 1.5;
      context.beginPath(); context.moveTo(x - 12, y - 11); context.lineTo(x + 12, y - 11);
      context.lineTo(x + 11, y + 3); context.lineTo(x, y + 12); context.lineTo(x - 11, y + 3); context.closePath(); context.fill(); context.stroke();
      context.fillStyle = color; context.font = 'bold 10px system-ui'; context.textAlign = 'center'; context.fillText(`F${tier}`, x, y + 2);
      if (detailed) {
        const reduction = Number.isFinite(building.fortificationReduction) ? clamp(building.fortificationReduction, 0, .5) : (tier + 1) / 10;
        const label = `FORTIFIED · −${Math.round(reduction * 100)}% DAMAGE`, labelWidth = context.measureText(label).width + 14;
        context.fillStyle = '#19312aee'; this.roundRect(point.x - labelWidth / 2, point.y + 42, labelWidth, 19, 5); context.fill();
        context.fillStyle = color; context.font = '600 9px system-ui'; context.fillText(label, point.x, point.y + 55);
      }
    }
    if (fortifying) {
      const seconds = Math.max(0, Math.ceil((Number(building.fortify_ready_at) || 0) - Date.now() / 1000 - this.serverOffset));
      const time = seconds >= 60 ? `${Math.floor(seconds / 60)}m ${seconds % 60}s` : `${seconds}s`;
      const label = this.battle ? 'FORTIFYING · OFFLINE' : `FORTIFYING · ${time}`;
      context.font = '600 10px system-ui'; context.textAlign = 'center';
      const labelWidth = context.measureText(label).width + 18, y = point.y - width * .69;
      context.fillStyle = '#293328ed'; this.roundRect(point.x - labelWidth / 2, y - 15, labelWidth, 22, 5); context.fill();
      context.fillStyle = '#f2d28e'; context.fillText(label, point.x, y);
    }
    context.restore();
  }
  networkProtection(building) {
    const snapshot = this.battle || this.base;
    // The server supplies adjacency and ownership. Battle fields are frozen at
    // invasion start; private home data must never fill a missing combat field.
    if (this.untargetable(building) || building.type === 'outpostdefender' || snapshot.target?.kind === 'fortification') return null;
    const reduction = building.networkReduction ?? snapshot.networkDefense?.reduction;
    if (!Number.isFinite(reduction) || reduction <= 0 || reduction > .75) return null;
    const count = snapshot.networkDefense?.count;
    const tier = Number(building.fortification ?? 0), validTier = Number.isInteger(tier) && tier > 0 && tier <= 4;
    const armor = Number.isFinite(building.fortificationReduction) ? clamp(building.fortificationReduction, 0, .5) : validTier ? (tier + 1) / 10 : 0;
    const total = Number.isFinite(building.damageReduction) && building.damageReduction >= 0 && building.damageReduction <= 1
      ? building.damageReduction : 1 - (1 - armor) * (1 - reduction);
    return { reduction, count: Number.isInteger(count) && count > 0 && count <= 6 ? count : null, total, tier: validTier ? tier : 0 };
  }
  drawNetworkDefense(building, point, width, size) {
    const protection = this.networkProtection(building);
    if (!protection) return;
    const context = this.ctx, color = '#8ddcdb', x = point.x - width * .27, y = point.y - 12;
    const percent = value => Math.round(value * 1000) / 10;
    context.save(); context.strokeStyle = color; context.lineWidth = 1.2;
    context.setLineDash([4, 4]); this.diamond(building.x, building.y, size + .42, null, '#8ddcdb80'); context.setLineDash([]);
    context.fillStyle = '#14322fe8'; context.strokeStyle = color;
    context.beginPath(); context.moveTo(x - 12, y - 9); context.lineTo(x, y - 14); context.lineTo(x + 12, y - 9);
    context.lineTo(x + 10, y + 4); context.lineTo(x, y + 11); context.lineTo(x - 10, y + 4); context.closePath(); context.fill(); context.stroke();
    context.font = 'bold 9px system-ui'; context.textAlign = 'center'; context.fillStyle = color;
    context.fillText(`N${protection.count ?? ''}`, x, y + 2);
    const labels = [`NETWORK${protection.count ? ` ${protection.count}/6` : ''} · −${percent(protection.reduction)}% DAMAGE`];
    if (protection.tier) labels.push(`ARMOR + NETWORK · −${percent(protection.total)}% TOTAL`);
    labels.forEach((label, index) => {
      const labelWidth = context.measureText(label).width + 14, labelY = point.y + 42 + (protection.tier ? 22 : 0) + index * 22;
      context.fillStyle = '#14322fee'; this.roundRect(point.x - labelWidth / 2, labelY, labelWidth, 19, 5); context.fill();
      context.fillStyle = color; context.font = '600 9px system-ui'; context.fillText(label, point.x, labelY + 13);
    });
    context.restore();
  }
  drawNetworkBeacon(point, width) {
    // Artwork anchor: the non-firing core's lantern is at (628, 380) in the
    // native 1254px image. Use the same cropped bounds as drawImage.
    if (!this.images.outpostdefender) return;
    const context = this.ctx, bounds = this.bounds.outpostdefender || { x: 0, y: 0, w: 1254, h: 1254 };
    const x = point.x + width * ((628 - bounds.x) / bounds.w - .5);
    const y = point.y + 8 - width * (bounds.h - (380 - bounds.y)) / bounds.w;
    const pulse = this.reduced ? .55 : .55 + Math.sin(this.now * 1.7) * .12;
    context.save(); context.globalAlpha = pulse; context.fillStyle = '#b2f2df'; context.shadowColor = '#8adfdf'; context.shadowBlur = 12;
    context.beginPath(); context.arc(x, y, width * .026, 0, Math.PI * 2); context.fill();
    context.shadowBlur = 0; context.strokeStyle = '#a2e7da'; context.lineWidth = 1.1;
    context.beginPath(); context.ellipse(x, y, width * .065, width * .025, 0, 0, Math.PI * 2); context.stroke(); context.restore();
  }
  spritePoint(type, point, width, pixelX, pixelY) {
    const bounds = this.bounds[type];
    return {
      x: point.x + width * ((pixelX - bounds.x) / bounds.w - .5),
      y: point.y + 8 - width * (bounds.h - (pixelY - bounds.y)) / bounds.w,
    };
  }
  drawBuildingMotion(building, point, width) {
    const context = this.ctx;
    if (['juicer', 'locker', 'championchamber', 'lab'].includes(building.type)) {
      for (let i = 0; i < 3; i++) {
        const phase = (this.now * .35 + i * .33 + building.id * .1) % 1;
        context.globalAlpha = (1 - phase) * .45;
        context.fillStyle = ['goo', 'juicer'].includes(building.type) ? '#d3b2f0' : ['locker', 'championchamber', 'lab'].includes(building.type) ? '#a6e3dc' : '#e7e4b3';
        context.beginPath(); context.arc(point.x + Math.sin(i * 7 + building.id) * 14, point.y - width * .45 - phase * 30, 2 + phase * 5, 0, Math.PI * 2); context.fill();
      }
      context.globalAlpha = 1;
    }
    if (building.type === 'aerial' && this.bounds.aerial) {
      const core = this.spritePoint('aerial', point, width, 620, 310);
      const scan = this.now * 1.5 + building.id;
      context.save();
      context.strokeStyle = '#b9f3ff';
      context.globalAlpha = .62;
      context.lineWidth = 1.5;
      context.beginPath();
      context.ellipse(core.x, core.y, width * .08, width * .033, -.17, scan, scan + 2);
      context.stroke();
      context.restore();
    }
    if (building.type === 'siegefactory' || building.type === 'siegelab') {
      // The coal furnaces throw sparks in short intermittent bursts.
      context.save();
      for (let i=0;i<4;i++) {
        const phase=(this.now * .53 + building.id * .7 + i * .23) % 1;
        context.globalAlpha=.65 * (1-phase);
        context.fillStyle=i%2?'#ffb557':'#fff0ad';
        context.beginPath();context.arc(point.x + width * (.14 + .06*Math.sin(i+phase*5)),point.y-width*(.25+phase*.32),1+1.5*(1-phase),0,Math.PI*2);context.fill();
      }
      context.restore();
    }
    if (building.type === 'heavytrap' && this.bounds.heavytrap) {
      const plate = this.spritePoint('heavytrap', point, width, 628, 630);
      context.save();
      context.fillStyle = '#ffd790';
      context.globalAlpha = .28 + .18 * Math.sin(this.now * 2.5 + building.id);
      context.beginPath();
      context.ellipse(plate.x, plate.y, width * .066, width * .034, 0, 0, Math.PI * 2);
      context.fill();
      context.restore();
    }
    if (['laser', 'tesla', 'stronghold'].includes(building.type)) {
      const color = building.type === 'laser' ? '#ffa5bd' : '#b2dfff';
      context.save(); context.globalAlpha = .3 + Math.sin(this.now * 3 + building.id) * .1;
      context.shadowColor = color; context.shadowBlur = 13; context.fillStyle = color;
      context.beginPath(); context.arc(point.x, point.y - width * .57, 4, 0, Math.PI * 2); context.fill(); context.restore();
    }
    if (building.type === 'bunker' && (building.garrison_count || building.garrisonCount)) {
      context.fillStyle = '#89d8cf'; context.beginPath(); context.arc(point.x, point.y - width * .31, 2.5, 0, Math.PI * 2); context.fill();
    }
    if (building.type === 'maproom' || building.type === 'outpost') {
      const pulse = .4 + Math.sin(this.now * 1.6 + building.id) * .15;
      context.save(); context.globalAlpha = pulse; context.strokeStyle = '#d9edb1'; context.lineWidth = 1.3;
      context.beginPath(); context.ellipse(point.x, point.y - width * .42, width * .1, width * .035, 0, 0, Math.PI * 2); context.stroke(); context.restore();
    }
  }
  drawBar(x, y, width, ratio, color) {
    const context = this.ctx;
    context.fillStyle = '#193226d9'; this.roundRect(x - width / 2 - 2, y - 2, width + 4, 8, 3); context.fill();
    context.fillStyle = '#3a4d35'; context.fillRect(x - width / 2, y, width, 4);
    context.fillStyle = color; context.fillRect(x - width / 2, y, width * clamp(ratio, 0, 1), 4);
  }
  roundRect(x, y, width, height, radius) { this.ctx.beginPath(); this.ctx.roundRect(x, y, width, height, radius); }
  drawPlannerGuides() {
    if(!this.planner||this.battle)return;
    const context=this.ctx,locked=new Set(this.planner.lockedIds),invalid=new Set(this.planner.invalidIds);
    context.save();context.lineWidth=1.5;
    this.diamond(16,16,30,'#18393615','#dcebb591');
    if(this.planner.ranges)for(const building of this.visibleBuildings()){
      const spec = this.catalog.buildings[building.type];
      const range = spec?.ranges?.[Math.max(0, building.level - 1)] ?? spec?.range;
      if (!range || !spec.damage) continue;
      const point=this.project(building.x,building.y);context.beginPath();context.ellipse(point.x,point.y,range*28.284,range*14.142,0,0,Math.PI*2);
      context.fillStyle=building.hp>0?'#9cc8bf0b':'#ca9f8e0b';context.fill();context.strokeStyle=building.hp>0?'#a5cdb94a':'#c39d8850';context.setLineDash([6,8]);context.stroke();context.setLineDash([]);
    }
    for(const building of this.visibleBuildings()){
      const size=(this.catalog.buildings[building.type]?.size||2)+.15,id=Number(building.id);
      const bad=invalid.has(id),pin=locked.has(id);
      this.diamond(building.x,building.y,size,bad?'#f09c7740':pin?'#e3c98b22':'#c5e7a616',bad?'#ffb89f':pin?'#e6cf98':'#d7ebbd5e');
      if(pin){const point=this.project(building.x,building.y);context.fillStyle='#eed4a1';context.font='bold 11px system-ui';context.textAlign='center';context.fillText('PINNED',point.x,point.y+35);}
    }
    context.restore();
  }

  workerClear(x,y) {
    if(!this.insideYard(x,y,1))return false;
    return !(this.base.buildings||[]).some(building=>{
      const half=(this.catalog.buildings[building.type]?.size||2)/2+.22;
      return Math.abs(x-building.x)<half&&Math.abs(y-building.y)<half;
    });
  }
  workerAnchor(x,y) {
    const candidates=[];
    const low=1-this.yardMargin,high=31+this.yardMargin;
    for(let a=low;a<=high;a++)for(let b=low;b<=high;b++)if(this.workerClear(a,b))candidates.push({x:a,y:b,d:Math.abs(a-x)+Math.abs(b-y)});
    candidates.sort((a,b)=>a.d-b.d||a.y-b.y||a.x-b.x);
    return candidates.length?{x:candidates[0].x,y:candidates[0].y}:null;
  }
  workerPath(from,to) {
    const start=this.workerAnchor(from.x,from.y),goal=this.workerAnchor(to.x,to.y);if(!start||!goal)return [];
    const margin=this.yardMargin,extent=this.yardExtent;
    const code=p=>(p.y+margin)*extent+p.x+margin,startKey=code(start),goalKey=code(goal),queue=[start],previous=new Map([[startKey,null]]);let index=0;
    const directions=[[1,0],[0,1],[-1,0],[0,-1],[1,1],[-1,1],[1,-1],[-1,-1]];
    while(index<queue.length&&queue.length<=(extent-1)**2){const point=queue[index++];if(code(point)===goalKey)break;
      for(const [dx,dy] of directions){const next={x:point.x+dx,y:point.y+dy},id=code(next);
        if(previous.has(id)||!this.workerClear(next.x,next.y)||dx&&dy&&(!this.workerClear(point.x+dx,point.y)||!this.workerClear(point.x,point.y+dy)))continue;
        previous.set(id,code(point));queue.push(next);
      }
    }
    if(!previous.has(goalKey))return [];
    const path=[];let cursor=goalKey;while(cursor!==null){path.push({x:cursor%extent-margin,y:Math.floor(cursor/extent)-margin});cursor=previous.get(cursor);}
    return path.reverse();
  }
  workerVisuals() {
    if(this.battle||this.planner||!this.base.construction||!this.base.owner||this.base.site)return [];
    const construction=this.base.construction,capacity=clamp(Math.floor(Number(construction.capacity)||1),1,5);
    const wallNow=Date.now()/1000+(this.serverOffset||0),jobs=(construction.jobs||[]).filter(j=>Number(j.readyAt)>wallNow);
    if(!this.workerSprites)this.workerSprites=new Map();
    const assignments=new Map(),claimed=new Set();
    for(let index=0;index<capacity;index++){const id=this.workerSprites.get(index)?.assignedId,job=jobs.find(j=>Number(j.buildingId)===id);if(job&&!claimed.has(id)){assignments.set(index,job);claimed.add(id);}}
    for(let index=0;index<capacity;index++)if(!assignments.has(index)){const job=jobs.find(j=>!claimed.has(Number(j.buildingId)));if(job){assignments.set(index,job);claimed.add(Number(job.buildingId));}}
    const hall=(this.base.buildings||[]).find(b=>b.type==='townhall')||{x:16,y:16},result=[];
    const geometry=(this.base.buildings||[]).map(b=>`${b.id}:${b.x}:${b.y}`).join('|');
    for(let index=0;index<capacity;index++){
      const job=assignments.get(index),building=job&&(job.kind==='pick'?(job.type==='pumpkin'?(this.base.seasonal?.objects||[]).find(item=>item.id===-1000000-job.buildingId):(this.base.mushrooms?.items||[]).find(item=>item.id===-job.buildingId)):(this.base.buildings||[]).find(b=>Number(b.id)===Number(job.buildingId)));
      const half=building?(this.catalog.buildings[building.type]?.size||2)/2:0;
      const target=building?{x:building.x+half+.8,y:building.y+half+.8}:{x:hall.x-3+index*1.5,y:hall.y+3.8};
      const destinationKey=`${job?.buildingId||0}:${job?.kind||'idle'}:${geometry}`;
      let worker=this.workerSprites.get(index);
      if(!worker){const origin=this.workerAnchor(hall.x-3+index,hall.y+3.5);if(!origin)continue;worker={...origin,jobKey:null,path:[],at:0,last:this.now,flip:false};this.workerSprites.set(index,worker);}
      worker.assignedId=job?Number(job.buildingId):null;
      if(worker.jobKey!==destinationKey){worker.path=this.workerPath(worker,target);worker.at=0;worker.jobKey=destinationKey;worker.working=false;}
      let remaining=this.reduced?0:Math.min(.1,Math.max(0,this.now-worker.last))*2.6;worker.last=this.now;
      if(this.reduced&&worker.path.length){Object.assign(worker,worker.path.at(-1));worker.at=worker.path.length;}
      const before={x:worker.x,y:worker.y};
      while(remaining>0&&worker.at<worker.path.length){const next=worker.path[worker.at],dx=next.x-worker.x,dy=next.y-worker.y,distance=Math.hypot(dx,dy);
        if(distance<=remaining){worker.x=next.x;worker.y=next.y;worker.at++;remaining-=distance;}
        else{worker.x+=dx/distance*remaining;worker.y+=dy/distance*remaining;remaining=0;}}
      const moving=Math.hypot(worker.x-before.x,worker.y-before.y)>.001;
      if(moving)worker.flip=(worker.x-before.x)-(worker.y-before.y)<0;
      const arrived=worker.path.length>0&&worker.at>=worker.path.length;
      worker.working=!!building&&arrived;
      if(worker.working)worker.flip=(building.x-worker.x)-(building.y-worker.y)<0;
      result.push({id:index,x:worker.x,y:worker.y,moving,working:worker.working,flip:worker.flip,job:job||null});
    }
    for(const id of this.workerSprites.keys())if(id>=capacity)this.workerSprites.delete(id);
    return result;
  }
  drawWorker(worker) {
    const point=this.project(worker.x,worker.y),context=this.ctx;
    context.save();context.fillStyle='#19332955';context.beginPath();context.ellipse(point.x,point.y+5,13,5,0,0,Math.PI*2);context.fill();
    const sheet=worker.working?'worker-work':worker.moving?'worker-walk':'worker';
    const animated=sheet!=='worker',frame=this.reduced?0:Math.floor(this.now*(worker.working?6:8)+worker.id)%4;
    if(!this.drawImage(sheet,point.x,point.y,39,{flip:worker.flip,frame:animated?frame:null,bounce:this.reduced?0:worker.moving?Math.abs(Math.sin(this.now*12+worker.id))*1.5:0}))this.drawImage('worker',point.x,point.y,39,{flip:worker.flip});
    if(worker.working&&!this.reduced){const phase=(this.now*2.4+worker.id)%1;if(phase<.22){context.strokeStyle='#efda9c';context.lineWidth=1.5;for(let i=0;i<3;i++){const a=i*2.1+.4;context.beginPath();context.moveTo(point.x+Math.cos(a)*14,point.y-11+Math.sin(a)*5);context.lineTo(point.x+Math.cos(a)*20,point.y-11+Math.sin(a)*10);context.stroke();}}}
    context.restore();
  }

  idleUnits() {
    const entries = Object.entries(this.base.army || {}).filter(([, count]) => count > 0), units = [];
    for (let i = 0; i < Math.min(9, entries.length * 2); i++) {
      const type = entries[i % entries.length][0], time = this.reduced ? 0 : this.now, angle = time * .13 + i * 2.4;
      const movement = this.catalog.monsters[type]?.movement || 'ground';
      units.push({ id: `idle${i}`, type, x: 16 + Math.sin(angle) * 8 + i % 2 * 3, y: 18 + Math.cos(angle * .83) * 7,
        state: movement === 'burrow' ? 'burrow' : 'walk', hp: 1, max_hp: 1, dir: Math.cos(angle), faceDX:8*Math.cos(angle), faceDY:-7*.83*Math.sin(angle*.83), ambient: true, movement });
    }
    const champion = this.idleChampion();
    if (champion) units.push(champion);
    const pet=this.base.krallenCompanion;
    if (pet) {
      const companion=this.idleChampion(true);
      if(companion)units.push(companion);
    }
    return units;
  }

  idleChampion(companion=false) {
    const type=companion?'krallen':this.base.activeChampion, saved=companion?this.base.krallenCompanion:this.base.champions?.[type];
    if (!type || (!companion&&!this.catalog.champions?.[type]) || !saved || saved.frozen || saved.hp <= 0 || saved.battle_id > 0) return null;
    const now = Date.now() / 1000 + this.serverOffset;
    const cage = this.visibleBuildings().find(building => building.type === 'championcage' && building.hp > 0 && !(building.ready_at > now));
    if (!cage) return null;
    const size = this.catalog.buildings.championcage?.size || 3;
    const positions = [[size / 2 + .7, .4], [.4, size / 2 + .7], [-size / 2 - .7, .4], [.4, -size / 2 - .7]];
    if(companion)positions.reverse();
    const position = positions.map(([dx, dy]) => ({ x: clamp(cage.x + dx, 1-this.yardMargin, 31+this.yardMargin), y: clamp(cage.y + dy, 1-this.yardMargin, 31+this.yardMargin) })).find(point =>
      !this.visibleBuildings().some(building => {
        const half = (this.catalog.buildings[building.type]?.size || 2) / 2 + .35;
        return !isTrap(building.type) && Math.abs(building.x - point.x) < half && Math.abs(building.y - point.y) < half;
      })) || { x: clamp(cage.x + .5, 1-this.yardMargin, 31+this.yardMargin), y: clamp(cage.y + size / 2 + .7, 1-this.yardMargin, 31+this.yardMargin) };
    const stats = companion?{}:saved.stats || saved.combatStats || championLevel({ type, level: saved.level }, this.catalog);
    return { id: `home-champion-${type}`, type, champion: true, level: saved.level || 1, ...(companion?{krallenPower:saved.power}:{}),
      hp: saved.hp, max_hp: saved.max_hp || stats.hp || saved.hp, movement: stats.movement || 'ground', bonus_feeds: saved.bonus_feeds || 0,
      x: position.x, y: position.y, state: 'idle', dir: 1, ambient: false };
  }

  drawAura(unit) {
    const radius = Math.min(16, Number(unit.auraRange ?? unit.auraRadius ?? unit.lootRadius ?? 0));
    if (!unit.champion || unit.hp <= 0 || unit.hidden === true || unit.state === 'dead' || unit.state === 'retreat' || !Number.isFinite(radius) || radius <= 0) return;
    const point = this.unitPosition(unit), projected = this.project(point.x, point.y), context = this.ctx;
    const pulse = this.reduced ? .11 : .11 + Math.sin(this.now * 2) * .025;
    context.save(); context.fillStyle = `rgba(223,140,211,${pulse})`; context.strokeStyle = '#f3b1e077'; context.lineWidth = 1.5;
    context.beginPath(); context.ellipse(projected.x, projected.y, radius * 28.284, radius * 14.142, 0, 0, Math.PI * 2); context.fill();
    context.setLineDash([8, 7]); context.stroke(); context.setLineDash([]);
    context.strokeStyle = '#e8c88855'; context.lineWidth = 1;
    context.beginPath(); context.ellipse(projected.x, projected.y, Math.max(.1, radius * 28.284 - 5), Math.max(.1, radius * 14.142 - 3), 0, 0, Math.PI * 2); context.stroke(); context.restore();
  }

  evolutionFrame(unit) {
    if (!unit.champion) return null;
    const key = unit.type === 'krallen' && Number.isInteger(unit.krallenPower)
      ? 'krallen-p' + Math.max(0, Math.min(2,unit.krallenPower)) + '-poses'
      : unit.type + '-e' + (unit.level || 1) + '-poses';
    const sheet = evolutionSheets[key];
    if (!sheet) return null;
    if (!this.images[key]) {
      if (this.pendingAssets && this.failedAssets) this.loadImage(key, sheet.file, sheet);
      return null;
    }
    let frame = 0;
    if (!this.reduced && unit.hp > 0) {
      const impulse = this.unitEffects.get(unit.id + ':attack');
      const age = impulse && this.battle?.status === 'active' ? (performance.now() - impulse.start) / 1000 : 1;
      if (age >= 0 && age < .55) frame = age < .22 ? 2 : 3;
      else if (unit.ambient || ['walk','walking','moving'].includes(unit.state)) frame = Math.floor(this.now * 4.5) % 2;
    }
    return {key,frame,evolution:true,striking:frame>=2};
  }
  animationFrame(unit) {
    const evolution = this.evolutionFrame(unit);
    if (evolution) return evolution;
    if (unit.hp <= 0 || ['dead', 'retreat'].includes(unit.state)) return { key: unit.type, frame: null };
    const attack = this.attackFrame(unit);
    if (attack) return attack;
    const walking = ['walk', 'walking', 'moving'].includes(unit.state) || unit.ambient;
    if (walkRates[unit.type] && walking && this.images[`${unit.type}-walk`]) {
      return { key: `${unit.type}-walk`, frame: this.reduced ? 0 : Math.floor(this.now * walkRates[unit.type]) % 4 };
    }
    if (unit.type === 'teratorn' && this.images['teratorn-fly']) {
      return { key: 'teratorn-fly', frame: this.reduced ? 1 : Math.floor(this.now * 7) % 4 };
    }
    if (unit.type === 'zafreeti' && movementOf(unit, this.catalog) === 'air' && this.images['zafreeti-fly']) {
      return { key: 'zafreeti-fly', frame: this.reduced ? 1 : Math.floor(this.now * 5) % 4 };
    }
    if (unit.type === 'fomor' && movementOf(unit, this.catalog) === 'air' && this.images['fomor-fly']) {
      return { key: 'fomor-fly', frame: this.reduced ? 1 : Math.floor(this.now * 6) % 4 };
    }
    if (unit.type === 'wormzer' && this.images['wormzer-emerge']) {
      const emergence = this.unitEffects.get(`${unit.id}:emerge`);
      if (emergence) {
        const age = Math.max(0, performance.now() - emergence.start) / 1000;
        if (age < .72) return { key: 'wormzer-emerge', frame: this.reduced ? 3 : Math.min(3, Math.floor(age / .18)) };
      }
    }
    return { key: unit.type, frame: null };
  }
  attackFrame(unit) {
    if (unit.ambient || unit.hp <= 0 || this.battle?.status !== 'active') return null;
    const key = `${unit.type}-attack`, effect = this.unitEffects.get(`${unit.id}:attack`);
    const healing = unit.type === 'zafreeti' && unit.state === 'heal' && effect?.type === 'heal';
    const attacking = attackTypes.has(unit.type) && ['attack', 'attacking'].includes(unit.state) && ['hit', 'shot', 'strike'].includes(effect?.type);
    if ((!healing && !attacking) || !this.images[key] || !effect) return null;
    const age = Math.max(0, performance.now() - effect.start) / 1000;
    if (age >= attackDuration) return null;
    return { key, frame: this.reduced ? 1 : Math.min(3, Math.floor(age / (attackDuration / 4))) };
  }
  burstFrame(effect) {
    if (!this.battle || !effect.unit || !this.images[`${effect.unit.type}-attack`]) return null;
    const age = Math.max(0, performance.now() - effect.start) / 1000;
    if (age >= burstDuration) return null;
    return { key: `${effect.unit.type}-attack`, frame: this.reduced ? (effect.unit.type === 'eyera' ? 3 : 2) : Math.min(3, Math.floor(age / (attackDuration / 4))),
      alpha: clamp((burstDuration - age) / (burstDuration - .48), 0, 1) };
  }
  drawUnitBurst(effect) {
    const frame = this.burstFrame(effect);
    if (!frame) return;
    const unit = effect.unit, point = this.project(unit.x, unit.y);
    const target = typeof unit.targetId === 'number' ? this.visibleBuildings().find(building => building.id === unit.targetId)
      : this.battle.units?.find(other => other.id === unit.targetId);
    const heading = unit.dir ?? (target ? target.x - target.y - unit.x + unit.y : 0);
    const flip = heading === 0 ? false : attackNativeFacingLeft.has(unit.type) ? heading > 0 : heading < 0;
    this.drawImage(frame.key, point.x, point.y, unitWidths[unit.type] || 42, { frame: frame.frame, alpha: frame.alpha, flip,
      outline: unit.type === 'projectx' ? '#b8ed7299' : '#ffd19288' });
  }
  retreatPosition(unit) {
    const retreat = this.sirenRetreat;
    if (!retreat || this.reduced) return null;
    const phase = clamp((performance.now() - retreat.start) / 1200, 0, 1);
    if (phase >= 1) { this.sirenRetreat = null; return null; }
    const origin = retreat.origins.get(unit.id);
    if (!origin || unit.hp <= 0 || unit.team !== 'attacker') return null;
    let dx = retreat.entry.x - origin.x, dy = retreat.entry.y - origin.y;
    let distance = Math.hypot(dx, dy);
    if (distance < .02) {
      dx = retreat.entry.x - 16; dy = retreat.entry.y - 16;
      distance = Math.hypot(dx, dy);
    }
    // Show a few strides toward the opening, at the same yard scale as a raid.
    // Near the opening monsters continue outwards instead of piling up on it.
    const step = Math.min(4, distance + 1.3) * phase / Math.max(distance, .001);
    return {x:origin.x + dx * step, y:origin.y + dy * step,
      dir:Math.sign(dx - dy), faceDX:dx, faceDY:dy, retreatAlpha:Math.min(1, (1 - phase) * 1.8)};
  }
  drawRetreatingUnit(unit) {
    const point = this.project(unit.x, unit.y), ctx = this.ctx;
    const air = movementOf(unit, this.catalog) === 'air';
    const width = unitWidths[unit.type] || 42;
    const burrowing = unit.state === 'burrow';
    ctx.save(); ctx.globalAlpha = unit.retreatAlpha;
    ctx.fillStyle = burrowing ? '#6b533dcc' : '#15332642';
    ctx.beginPath(); ctx.ellipse(point.x, point.y, width * .21, width * .08, 0, 0, Math.PI * 2); ctx.fill();
    ctx.restore();
    if (burrowing) return;
    const facing = yardBearing(unit.faceDX,unit.faceDY);
    const frame = directionalFrame(unit.type,this.animationFrame({...unit,state:'walk'}),facing,this.images);
    const left = nativeFacingLeft.has(unit.type);
    this.drawImage(frame.key, point.x, point.y, width, {
      frame:frame.frame, flip:frame.flip ?? (unit.dir !== 0 && (left ? unit.dir > 0 : unit.dir < 0)),
      pose:frame.directional ? creaturePose(unit.type,this.now,0,true,true) : null,
      bounce:air ? 24 : Math.abs(Math.sin(this.now * 10)) * 3, alpha:unit.retreatAlpha * (unit.cloaked ? .38 : 1),
    });
  }
  unitFacing(unit, previous, target) {
    let bearing = yardBearing(unit.faceDX,unit.faceDY);
    if (!bearing && target) bearing = yardBearing(target.x-unit.x,target.y-unit.y);
    if (!bearing && previous) bearing = yardBearing(unit.x-previous.x,unit.y-previous.y);
    if (!bearing && unit.dir) bearing = unit.dir<0?'sw':'se';
    this.unitFacings ??= new Map();
    if (bearing) this.unitFacings.set(unit.id,bearing);
    return bearing || this.unitFacings.get(unit.id) || 'se';
  }
  drawUnit(unit) {
    if (unit.hp <= 0 || unit.hidden === true || ['dead', 'retreat'].includes(unit.state)) return;
    const context = this.ctx, point = this.project(unit.x, unit.y), previous = this.previousUnits.get(unit.id);
    const movement = movementOf(unit, this.catalog), air = movement === 'air';
    const underground = movement === 'burrow' && unit.state === 'burrow';
    const walking = ['walk', 'walking', 'moving', 'burrow'].includes(unit.state) || unit.ambient;
    const attacking = ['attack', 'attacking'].includes(unit.state), healing = unit.state === 'heal';
    const idNumber = Number(String(unit.id).replace(/\D/g, '')) || 0;
    const phase = this.now * (walking ? 9 : 3) + idNumber * 2;
    const width = unitWidths[unit.type] || 42, color = teamColor(unit.team), champion = unit.champion === true;
    const target = (attacking || healing) && unit.targetId != null
      ? typeof unit.targetId === 'number'
        ? this.visibleBuildings().find(building => building.id === unit.targetId)
        : this.battle?.units?.find(other => other.id === unit.targetId)
      : null;
    const heading = target ? target.x - target.y - unit.x + unit.y : previous ? unit.x - unit.y - previous.x + previous.y : 0;
    const direction = unit.dir !== undefined ? unit.dir : heading;
    const bearing = this.images[unit.type+'-directions'] ? this.unitFacing(unit,previous,target) : null;
    const baseFrame = directionalFrame(unit.type,this.animationFrame(unit),bearing,this.images);
    const impulseEvent=this.unitEffects.get(unit.id+':attack');
    const strikeAge=impulseEvent && (attacking||healing) ? (performance.now()-impulseEvent.start)/1000 : -1;
    const frame = articulatedFrame(unit,baseFrame,bearing,this.images,{walking,strikeAge,reduced:this.reduced,clock:this.now});
    const drawnAttack = frame.striking || frame.key.endsWith('-attack');
    const left = frame.evolution || (drawnAttack ? attackNativeFacingLeft : nativeFacingLeft).has(unit.type);
    const lateral = bearing && !direction ? screenStep(bearing)[0] : direction;
    const flip = frame.flip ?? (lateral === 0 ? false : left ? lateral > 0 : lateral < 0);
    const altitude = air ? (unit.type === 'balthazar' ? 12 : 26) + (this.reduced ? 0 : Math.sin(this.now * 3 + idNumber) * 4) : 0;
    context.save();
    context.fillStyle = air ? '#15332630' : '#1533264d';
    context.beginPath(); context.ellipse(point.x, point.y, width * (air ? .22 : .28), width * (air ? .085 : .12), 0, 0, Math.PI * 2); context.fill();
    if (this.battle || champion) {
      context.strokeStyle = champion ? '#f3ce88cc' : unit.team === 'defender' ? '#83ddeaab' : '#dce9a35e';
      context.lineWidth = champion ? 2.5 : unit.team === 'defender' ? 2 : 1;
      context.beginPath(); context.ellipse(point.x, point.y + 1, width * .32, width * .13, 0, 0, Math.PI * 2); context.stroke();
      if (champion && this.battle) {
        context.strokeStyle = color; context.lineWidth = 1.2;
        context.beginPath(); context.ellipse(point.x, point.y + 1, width * .27, width * .105, 0, 0, Math.PI * 2); context.stroke();
      }
    }
    if (unit.overdriveColor && this.battle?.status==='active') {
      context.strokeStyle='#'+unit.overdriveColor;context.lineWidth=2;
      const angle=this.reduced?0:this.now*2+idNumber;
      for(let i=0;i<3;i++){
        const start=angle+i*Math.PI*2/3;
        context.beginPath();context.ellipse(point.x,point.y+1,width*.38,width*.15,0,start,start+1.15);context.stroke();
      }
    }
    if (this.battle?.status === 'active' && unit.buffed && unit.buffSource) {
      context.strokeStyle = '#f1a7ded9'; context.lineWidth = 2;
      context.beginPath(); context.ellipse(point.x, point.y + 2, width * .38, width * .16, 0, 0, Math.PI * 2); context.stroke();
      for (const side of [-1, 1]) {
        const lift = this.reduced ? 4 : 4 + Math.sin(this.now * 3 + idNumber) * 3;
        context.fillStyle = '#ffd8f0'; context.beginPath();
        context.moveTo(point.x + side * width * .37, point.y - altitude - 12 - lift);
        context.lineTo(point.x + side * width * .37 - 3, point.y - altitude - 8 - lift);
        context.lineTo(point.x + side * width * .37 + 3, point.y - altitude - 8 - lift); context.closePath(); context.fill();
      }
    }
    if (underground) {
      this.drawBurrow(unit, point, previous, width);
      context.restore(); return;
    }
    let bounce = this.reduced ? 0 : walking && !air ? Math.abs(Math.sin(phase)) * 4 : Math.sin(phase) * 1.2;
    const arrival = this.unitEffects.get(`${unit.id}:deploy`);
    if (arrival && !this.reduced && unit.team !== 'defender') {
      const age = clamp((performance.now() - arrival.start) / 700, 0, 1);
      bounce += Math.pow(1 - age, 2) * 34;
    }
    // Newly drawn attack poses carry their own action; do not wobble their body
    // as though the sheet were the old procedural static-sprite attack.
    let angle = this.reduced || drawnAttack ? 0 : healing ? Math.sin(phase) * .025 : attacking ? Math.sin(phase * 3) * .17 : walking ? Math.sin(phase) * (air ? .035 : .07) : 0;
    if (drawnAttack) bounce = 0;
    const pose = this.reduced || frame.painted || frame.evolution || drawnAttack ? null : creaturePose(unit.type, this.now, idNumber, walking, frame.directional);
    let lunge = 0, lift = 0;
    if (pose !== null && attacking) {
      const effect = this.unitEffects.get(`${unit.id}:attack`);
      const progress = effect ? clamp((performance.now() - effect.start) / 440, 0, 1) : 0;
      const impulse = Math.sin(progress * Math.PI);
      const [sx,sy] = bearing ? screenStep(bearing) : [Math.sign(direction),0];
      lunge = sx * impulse * width * .10;
      lift = sy * impulse * width * .052;
      angle = sx * impulse * .095;
    }
    this.drawImage(frame.key, point.x + lunge, point.y + lift, width, {
      flip, frame: frame.frame, pose, zombie: unit.zombie === true, rotate: angle, bounce: altitude + bounce,
      alpha: unit.blinking ? .24 : unit.cloaked ? .38 : 1,
      squash: this.reduced || frame.frame !== null ? 1 : 1 + Math.sin(phase) * (air ? .055 : .035),
      outline: unit.cloaked || unit.blinking ? '#a9e5f3b0' : champion ? (unit.team === 'defender' ? '#71dbef99' : '#edc88380') : this.battle && unit.team === 'defender' ? '#71dbef80' : null,
    });
    if (this.battle?.status === 'active') this.drawUnitStatuses(unit, point, width, altitude);
    if (air && !this.reduced) this.drawAirMotion(point, width, altitude, phase, flip);
    if (healing) {
      context.strokeStyle = '#b9f0c7aa'; context.lineWidth = 1.5;
      context.beginPath(); context.ellipse(point.x, point.y - altitude - 5, width * .29, width * .12, 0, 0, Math.PI * 2); context.stroke();
      if (!this.reduced) for (let i = 0; i < 3; i++) {
        const a = this.now * 2 + i * Math.PI * 2 / 3;
        context.fillStyle = '#d6ffe0'; context.beginPath(); context.arc(point.x + Math.cos(a) * width * .32, point.y - altitude - 10 + Math.sin(a) * width * .12, 2, 0, Math.PI * 2); context.fill();
      }
    }
    if (champion) {
      const bounds = this.bounds[frame.key], height = bounds ? width * bounds.h / bounds.w : width;
      const top = point.y - altitude - height - 10;
      const bonus = clamp(Math.floor(Number(unit.bonus_feeds) || 0), 0, 3);
      const name = `${this.catalog.champions?.[unit.type]?.name || (unit.type === 'krallen' ? 'Krallen' : unit.type)} · Lv ${unit.level || 1}${bonus ? ` · B${bonus}` : ''}`;
      context.font = '600 11px system-ui'; context.textAlign = 'center';
      const labelWidth = context.measureText(name).width + 18;
      context.fillStyle = '#192d25e6'; this.roundRect(point.x - labelWidth / 2, top - 18, labelWidth, 18, 5); context.fill();
      context.fillStyle = '#f7daa0'; context.fillText(name, point.x, top - 5);
      this.drawBar(point.x, top + 5, 46, unit.hp / Math.max(1, unit.max_hp), unit.hp / unit.max_hp > .4 ? '#e8c681' : '#eea17f');
      context.fillStyle = '#f4d08c'; context.strokeStyle = this.battle ? color : '#ffebbe'; context.lineWidth = 1.3;
      context.beginPath(); context.moveTo(point.x + width * .34, point.y - altitude - width * .52 - 6);
      context.lineTo(point.x + width * .34 + 5, point.y - altitude - width * .52);
      context.lineTo(point.x + width * .34, point.y - altitude - width * .52 + 6);
      context.lineTo(point.x + width * .34 - 5, point.y - altitude - width * .52); context.closePath(); context.fill(); context.stroke();
    } else if (this.battle && unit.hp < unit.max_hp) this.drawBar(point.x, point.y - altitude - width * .85, 24, unit.hp / unit.max_hp, unit.hp / unit.max_hp > .4 ? color : '#eea17f');
    if (!champion && this.battle && unit.team === 'defender') {
      context.fillStyle = '#83ddea'; context.beginPath();
      context.moveTo(point.x + width * .3, point.y - altitude - width * .55);
      context.lineTo(point.x + width * .3 + 5, point.y - altitude - width * .55 + 3);
      context.lineTo(point.x + width * .3, point.y - altitude - width * .55 + 9);
      context.lineTo(point.x + width * .3 - 5, point.y - altitude - width * .55 + 3); context.closePath(); context.fill();
    }
    context.restore();
  }
  drawKorathEvent(event, source, target, point, age) {
    const ctx = this.ctx;
    if (event.type === 'korath_stomp') {
      if (age >= .8) return;
      const t = this.reduced ? .8 : Math.max(.04,age/.8);
      const radius = Math.max(1,event.radius || 2)*28.284;
      ctx.globalAlpha = 1-age/.8;
      ctx.strokeStyle = '#ffaf62'; ctx.lineWidth = 2.5;
      ctx.beginPath();ctx.ellipse(point.x,point.y,radius*t,radius*t*.5,0,0,Math.PI*2);ctx.stroke();
      for (let i=0;i<9;i++) {
        const a=i*Math.PI*2/9;
        ctx.beginPath();ctx.moveTo(point.x+Math.cos(a)*radius*t*.32,point.y+Math.sin(a)*radius*t*.16);
        ctx.lineTo(point.x+Math.cos(a+.18)*radius*t*.67,point.y+Math.sin(a+.18)*radius*t*.34);
        ctx.lineTo(point.x+Math.cos(a)*radius*t,point.y+Math.sin(a)*radius*t*.5);ctx.stroke();
      }
    } else if (age < .48) {
      const t = this.reduced ? .9 : age/.48;
      const x=source.x+(target.x-source.x)*t, y=source.y+(target.y-source.y)*t-16*Math.sin(Math.PI*t);
      ctx.fillStyle='#e5532f';ctx.beginPath();ctx.arc(x,y,6,0,Math.PI*2);ctx.fill();
      ctx.fillStyle='#ffda84';ctx.beginPath();ctx.arc(x-1,y-1,3,0,Math.PI*2);ctx.fill();
    }
  }
  drawUnitStatuses(unit, point, width, altitude) {
    const context = this.ctx, top = point.y - altitude - width * .83;
    context.save();
    if (unit.burning) {
      for (let i=0;i<3;i++) {
        const phase=this.reduced ? .45 : (this.now*1.5+i/3)%1;
        const x=point.x+(i-1)*width*.11, y=point.y-altitude-phase*width*.34;
        context.globalAlpha=.85-phase*.55;context.fillStyle=i%2?'#ffd065':'#ec6d38';
        context.beginPath();context.ellipse(x,y,2.1,3.6,0,0,Math.PI*2);context.fill();
      }
      context.globalAlpha=1;
    }
    if (unit.cloaked || unit.blinking) {
      context.strokeStyle = '#b3e8f6bb'; context.lineWidth = 1.3; context.setLineDash([3, 4]);
      context.beginPath(); context.ellipse(point.x, point.y + 1, width * .36, width * .145, 0, 0, Math.PI * 2); context.stroke(); context.setLineDash([]);
      context.fillStyle = '#c5edf7'; context.font = '600 9px system-ui'; context.textAlign = 'center';
      context.fillText(unit.blinking ? 'BLINK' : 'CLOAK', point.x, top - 7);
    }
    if (unit.burning) drawBurning(this,point,width,top);
    if (unit.poisoned) {
      const x = point.x - width * .37, y = top + 3;
      context.fillStyle = '#b6e573'; context.strokeStyle = '#28452b'; context.lineWidth = 1.2;
      context.beginPath(); context.moveTo(x, y - 7); context.bezierCurveTo(x - 7, y + 1, x - 5, y + 6, x, y + 6);
      context.bezierCurveTo(x + 5, y + 6, x + 7, y + 1, x, y - 7); context.fill(); context.stroke();
    }
    if (unit.enraged) {
      context.save(); context.strokeStyle='#ff542c'; context.lineWidth=2;
      context.beginPath(); context.ellipse(point.x,point.y+3,width*.45,width*.18,0,0,Math.PI*2);context.stroke();
      context.restore();
    }
    if (unit.boosted) {
      context.strokeStyle = '#e7a2efce'; context.lineWidth = 2;
      context.beginPath(); context.ellipse(point.x, point.y + 3, width * .43, width * .185, 0, 0, Math.PI * 2); context.stroke();
      const lift = this.reduced ? 0 : Math.sin(this.now * 3) * 2;
      context.fillStyle = '#f3c5f7'; context.beginPath();
      const x = point.x + width * .38, y = top + 5 - lift;
      context.moveTo(x, y - 8); context.lineTo(x - 5, y); context.lineTo(x - 1, y); context.lineTo(x - 3, y + 7);
      context.lineTo(x + 6, y - 2); context.lineTo(x + 1, y - 2); context.closePath(); context.fill();
    }
    const tier = Math.min(3, Math.max(0, Number(unit.labLevel || unit.invasionPower) || 0));
    if (tier && !unit.champion) {
      context.fillStyle = '#bce5df';
      for (let i = 0; i < tier; i++) {
        context.beginPath(); context.arc(point.x + (i - (tier - 1) / 2) * 6, point.y + 10, 1.6, 0, Math.PI * 2); context.fill();
      }
    }
    context.restore();
  }
  drawBurrow(unit, point, previous, width) {
    const context = this.ctx, phase = this.reduced ? 0 : this.now * 7;
    const direction = previous ? this.project(unit.x - previous.x, unit.y - previous.y) : { x: 1, y: .3 };
    const length = Math.hypot(direction.x, direction.y) || 1, dx = direction.x / length, dy = direction.y / length;
    for (let i = 3; i >= 0; i--) {
      const trailX = point.x - dx * i * 10, trailY = point.y - dy * i * 10;
      context.fillStyle = i ? `rgba(101,82,51,${.4 - i * .075})` : '#62543b';
      context.beginPath(); context.ellipse(trailX, trailY, width * (.18 - i * .02), 3 + (i ? 0 : Math.sin(phase) * .7), -.1, 0, Math.PI * 2); context.fill();
      if (!i) { context.strokeStyle = '#d7c58c88'; context.lineWidth = 2; context.beginPath(); context.ellipse(trailX, trailY - 1, width * .16, 4, 0, Math.PI, Math.PI * 2); context.stroke(); }
    }
    if (!this.reduced) for (let i = 0; i < 5; i++) {
      const t = (this.now * 1.8 + i * .2) % 1;
      context.globalAlpha = (1 - t) * .55; context.fillStyle = i % 2 ? '#b7a46f' : '#706443';
      context.beginPath(); context.arc(point.x + Math.sin(i * 6 + unit.x) * (7 + t * 8), point.y - t * 12, 1.4 + t * 2, 0, Math.PI * 2); context.fill();
    }
    context.globalAlpha = 1;
  }
  drawAirMotion(point, width, altitude, phase, flip) {
    const context = this.ctx;
    context.save(); context.globalAlpha = .15 + (Math.sin(phase) + 1) * .06;
    context.strokeStyle = '#e8f2da'; context.lineWidth = 1.2;
    for (const side of [-1, 1]) {
      context.beginPath(); context.ellipse(point.x + side * width * .32, point.y - altitude - width * .22, width * .17, 4, flip ? -.2 : .2, .2, Math.PI); context.stroke();
    }
    context.restore();
  }

  effectPoint(id, x, y, fallbackHeight = 18) {
    const unit = this.battle?.units?.find(value => String(value.id) === String(id));
    if (unit) {
      const position = this.unitPosition(unit), point = this.project(position.x, position.y);
      const air = movementOf(unit, this.catalog) === 'air';
      point.y -= (unitWidths[unit.type] || 42) * .45 + (air ? (unit.type === 'balthazar' ? 12 : 26) : 0);
      return point;
    }
    const building = this.visibleBuildings().find(value => value.id === id);
    const point = this.project(x, y);
    const muzzle = building && defenseMuzzles[building.type];
    if (muzzle && this.bounds[building.type]) {
      const width = 40 * this.catalog.buildings[building.type].size * (visual[building.type] || 1.3);
      return this.spritePoint(building.type,point,width,...muzzle);
    }
    if (building?.type === 'aerial' && this.bounds.aerial) {
      const width = 40 * this.catalog.buildings.aerial.size * visual.aerial;
      return this.spritePoint('aerial', point, width, 620, 310);
    }
    point.y -= building ? 40 * (this.catalog.buildings[building.type]?.size || 2) * .47 : fallbackHeight;
    return point;
  }
  drawEffects() {
    const context = this.ctx, timestamp = performance.now();
    this.drawCatapultProjectiles();
    this.fx = this.fx.filter(event => timestamp - event.start < 1700);
    for (const event of this.fx) {
      const age = (timestamp - event.start) / 1000;
      if (age < 0) continue;
      if (event.kind === 'aerial' && event.type === 'shot' && !event.sounded && age < .45) {
        this.callbacks.sound?.('aerial');
        event.sounded = true;
      }
      const point = event.type === 'hit'
        ? this.project(event.tx ?? event.x, event.ty ?? event.y)
        : this.project(event.x, event.y);
      const source = this.effectPoint(event.sourceId, event.x, event.y, 22);
      const target = this.effectPoint(event.targetId, event.tx ?? event.x, event.ty ?? event.y);
      context.save();
      if (drawCreatureAbility(context, event, source, target, point, age, this.reduced)) {
        context.restore(); continue;
      }
      if (event.type === 'korath_stomp' || (event.kind === 'korath' && event.type === 'shot' && event.projectile === 'magma')) {
        this.drawKorathEvent(event, source, target, point, age);
        context.restore(); continue;
      }
      if (drawDefenseEvent(this,event,source,target,point,age)) { context.restore(); continue; }
      if (['siege_launch','siege_blast','siege_break','siege_end'].includes(event.type)) {
        this.drawSiegePulse(event, point, age);
      } else if (['blink', 'cloak', 'venom', 'multistrike', 'whirlwind', 'ricochet', 'acid', 'emerge_splash', 'buff', 'catapult_impact'].includes(event.type)) {
        this.drawAbilityEffect(event, point, target, age);
      } else if (event.type === 'heal' && age < .85) {
        const fade = 1 - age / .85;
        context.globalAlpha = fade; context.lineWidth = 3; context.strokeStyle = '#a1eac3';
        context.shadowColor = '#a9f4cb'; context.shadowBlur = this.reduced ? 0 : 8;
        context.beginPath(); context.moveTo(source.x, source.y);
        context.quadraticCurveTo((source.x + target.x) / 2, Math.min(source.y, target.y) - 17, target.x, target.y); context.stroke();
        context.shadowBlur = 0; context.strokeStyle = '#e3ffe6'; context.lineWidth = 1;
        context.beginPath(); context.ellipse(target.x, target.y + 9, 13 + age * 9, 6 + age * 3, 0, 0, Math.PI * 2); context.stroke();
        context.font = 'bold 13px system-ui'; context.textAlign = 'center'; context.fillStyle = '#d8ffe3';
        context.fillText(`+${Math.round(event.amount || 0)}`, target.x, target.y - 15 - age * 24);
      } else if (event.type === 'shot' && age < .45) {
        this.drawProjectile(event, source, target, age);
      } else if (['explode', 'trap'].includes(event.type) && age < 1.25) {
        this.drawExplosion(event, point, age);
      } else if (event.type === 'emerge' && age < .8) {
        const fade = 1 - age / .8;
        context.globalAlpha = fade; context.strokeStyle = '#cbb88d'; context.lineWidth = 3 * fade;
        context.beginPath(); context.ellipse(point.x, point.y, 10 + age * 30, 5 + age * 12, 0, 0, Math.PI * 2); context.stroke();
        for (let i = 0; i < 9; i++) {
          const angle = i * 2.4, radius = 10 + age * 35;
          context.fillStyle = i % 2 ? '#a99061' : '#706347'; context.beginPath();
          context.ellipse(point.x + Math.cos(angle) * radius, point.y + Math.sin(angle) * radius * .4 - Math.sin(age / .8 * Math.PI) * 10, 2.5 * fade, 2 * fade, angle, 0, Math.PI * 2); context.fill();
        }
      } else if (event.type === 'champion_retreat' && age < 1.6) {
        const fade = 1 - age / 1.6;
        context.globalAlpha = fade; context.strokeStyle = '#e6c58f'; context.lineWidth = 2;
        context.beginPath(); context.ellipse(point.x, point.y, 14 + age * 20, 7 + age * 8, 0, 0, Math.PI * 2); context.stroke();
        context.font = '600 12px system-ui'; context.textAlign = 'center'; context.fillStyle = '#f8dcaa';
        const name = this.catalog.champions?.[event.kind]?.name || 'Champion';
        context.fillText(`${name} retreats`, point.x, point.y - 42 - (this.reduced ? 0 : age * 16));
      } else if (event.type === 'raid_loot' && age < 1.2) {
        context.globalAlpha=Math.min(1,(1.2-age)*2.5);
        context.textAlign='center';context.font='bold 12px sans-serif';context.lineWidth=3;
        const y=point.y-43-(this.reduced?0:age*18);
        const text='+'+Math.floor(event.amount)+' '+event.kind;
        context.strokeStyle='#201a16';context.strokeText(text,point.x,y);
        context.fillStyle='#f6d885';context.fillText(text,point.x,y);
      } else if (['hit', 'destroy', 'death', 'deploy'].includes(event.type)) {
        const life = event.type === 'destroy' ? 1.3 : .65;
        if (age <= life) {
          const fade = 1 - age / life, color = event.type === 'deploy' ? (event.champion ? '#f5ce85' : teamColor(event.team)) : event.type === 'destroy' ? '#ffc677' : '#e6edc0';
          context.globalAlpha = fade;
          const particles = this.reduced ? 3 : event.type === 'destroy' ? 12 : 5;
          for (let i = 0; i < particles; i++) {
            const angle = i * 2.4 + event.id * 1.2, radius = age * (event.type === 'destroy' ? 55 : 28);
            context.fillStyle = color; context.beginPath();
            context.arc(point.x + Math.cos(angle) * radius, point.y - 16 + Math.sin(angle) * radius * .6 - age * 12, Math.max(1, fade * (event.type === 'destroy' ? 5 : 3)), 0, Math.PI * 2); context.fill();
          }
          if (event.amount) {
            context.font = 'bold 13px system-ui'; context.textAlign = 'center'; context.fillStyle = '#fff2cb';
            context.fillText(`−${Math.round(event.amount)}`, point.x, point.y - 45 - age * 20);
          }
        }
      }
      context.restore();
    }
  }
  drawProjectile(event, source, target, age) {
    const context = this.ctx, amount = Math.min(1, age / .4);
    if (event.kind === 'aerial') {
      this.drawAirDefenseShot(source, target, age);
      return;
    }
    if (event.projectile === 'rocket') {
      const spread = Number(event.volley) === 1 ? 5 : -5;
      const x = lerp(source.x, target.x, this.reduced ? .65 : amount), y = lerp(source.y + spread, target.y, this.reduced ? .65 : amount);
      const angle = Math.atan2(target.y - source.y - spread, target.x - source.x);
      context.save(); context.translate(x, y); context.rotate(angle);
      context.fillStyle = '#ffd58e'; context.beginPath(); context.moveTo(-5, -2); context.lineTo(-(this.reduced ? 8 : 15), 0); context.lineTo(-5, 2); context.fill();
      context.fillStyle = '#dbe8de'; this.roundRect(-6, -2.5, 12, 5, 2); context.fill();
      context.fillStyle = '#e4a684'; context.beginPath(); context.moveTo(6, -2.5); context.lineTo(10, 0); context.lineTo(6, 2.5); context.fill(); context.restore(); return;
    }
    if (event.kind === 'laser') {
      context.globalAlpha = Math.max(0, 1 - age / .45); context.shadowColor = '#ff86a9'; context.shadowBlur = this.reduced ? 0 : 12;
      context.strokeStyle = '#e86e9e'; context.lineWidth = 6;
      context.beginPath(); context.moveTo(source.x, source.y); context.lineTo(target.x, target.y); context.stroke();
      context.strokeStyle = '#ffe6ef'; context.lineWidth = 2; context.stroke(); context.shadowBlur = 0;
      context.fillStyle = '#fff1cf'; context.beginPath(); context.arc(target.x, target.y, 5 + Math.sin(age * 30) * 2, 0, Math.PI * 2); context.fill(); return;
    }
    if (event.kind === 'tesla' || event.kind === 'stronghold') {
      context.globalAlpha = 1 - age / .45; context.strokeStyle = '#bddbfa'; context.lineWidth = 2.5;
      context.beginPath(); context.moveTo(source.x, source.y);
      for (let i = 1; i < 6; i++) {
        const t = i / 6, offset = this.reduced ? 0 : Math.sin(i * 5 + age * 70) * 7;
        context.lineTo(lerp(source.x, target.x, t) + offset, lerp(source.y, target.y, t) - offset);
      }
      context.lineTo(target.x, target.y); context.stroke(); return;
    }
    const cannon = event.kind === 'cannon', magma = event.kind === 'teratorn' || event.projectile === 'magma', aura = event.kind === 'fomor';
    const x = lerp(source.x, target.x, amount), y = lerp(source.y, target.y, amount) - Math.sin(amount * Math.PI) * (cannon ? 50 : magma ? 12 : 5);
    context.strokeStyle = aura ? '#e7a4f1' : magma ? '#ffae65' : '#ffe5a3'; context.lineWidth = magma || aura ? 3 : 2;
    context.beginPath(); context.moveTo(lerp(source.x, target.x, Math.max(0, amount - .14)), lerp(source.y, target.y, Math.max(0, amount - .14))); context.lineTo(x, y); context.stroke();
    context.fillStyle = aura ? '#ffe2f8' : magma ? '#ffce7e' : '#fff4c6'; context.beginPath(); context.arc(x, y, cannon ? 5 : magma || aura ? 4 : 2.5, 0, Math.PI * 2); context.fill();
  }
  drawAirDefenseShot(source, target, age) {
    const ctx = this.ctx;
    const progress = this.reduced ? 1 : clamp(age / .29, 0, 1);
    const trail = Math.max(0, progress - .22);
    const height = t => lerp(source.y, target.y, t) - 18 * Math.sin(t * Math.PI);
    const x = lerp(source.x, target.x, progress), y = height(progress);
    ctx.lineCap = 'round';
    ctx.globalAlpha = 1 - .5 * age / .45;
    ctx.strokeStyle = '#5cb7c7';
    ctx.lineWidth = 4;
    ctx.beginPath();
    ctx.moveTo(lerp(source.x, target.x, trail), height(trail));
    ctx.quadraticCurveTo(lerp(source.x, target.x, (trail + progress) / 2), height((trail + progress) / 2), x, y);
    ctx.stroke();
    if (progress < 1) {
      const angle = Math.atan2(target.y - source.y - 18 * Math.PI * Math.cos(progress * Math.PI), target.x - source.x);
      ctx.save();
      ctx.translate(x, y);
      ctx.rotate(angle);
      ctx.fillStyle = '#fff3c6';
      ctx.beginPath();
      ctx.moveTo(7, 0);
      ctx.lineTo(-5, -3);
      ctx.lineTo(-2, 0);
      ctx.lineTo(-5, 3);
      ctx.closePath();
      ctx.fill();
      ctx.restore();
      return;
    }
    const burst = this.reduced ? 8 : 3 + 100 * Math.max(0, age - .29);
    ctx.strokeStyle = '#e7f7cf';
    ctx.lineWidth = 2;
    for (let i = 0; i < 6; i++) {
      const angle = i * Math.PI / 3;
      ctx.beginPath();
      ctx.moveTo(x + Math.cos(angle) * burst * .45, y + Math.sin(angle) * burst * .45);
      ctx.lineTo(x + Math.cos(angle) * burst, y + Math.sin(angle) * burst);
      ctx.stroke();
    }
  }

  drawSiegeDevice(siege) {
    const active = siege?.active;
    if (!active || !Number.isFinite(active.x) || !Number.isFinite(active.y)) return;
    const ctx = this.ctx, pos = this.project(active.x, active.y);
    const t = this.reduced ? 0 : this.battleElapsed() - active.startedAt;
    ctx.save();
    if (siege.type === 'decoy') {
      // A small rocking lure and lit fuse keep its silhouette visible among a pack of defenders.
      const fuse = .5 + .5 * Math.sin(t * 14);
      ctx.fillStyle = '#301d1b55'; ctx.beginPath(); ctx.ellipse(pos.x + 3, pos.y + 4, 23, 8, 0, 0, Math.PI * 2); ctx.fill();
      this.drawImage('siege-decoy', pos.x, pos.y, 54, { rotate: .045 * Math.sin(t * 5), bounce: -1.5 * Math.abs(Math.sin(t * 5)) });
      ctx.fillStyle = '#ffb657'; ctx.globalAlpha = .45 + fuse * .45;
      ctx.beginPath(); ctx.arc(pos.x + 12, pos.y - 34, 2 + fuse, 0, Math.PI * 2); ctx.fill();
    } else if (siege.type === 'vacuum') {
      const x = pos.x + 30, y = pos.y - 29;
      this.drawImage('siege-vacuum', x, y, 83, {rotate: .012 * Math.sin(t * 18)});
      for (let i = 0; i < 5; i++) {
        const part = (t * .85 + i / 5) % 1;
        const sx = pos.x - 20 + part * 45, sy = pos.y - 8 - part * 48 - Math.sin(Math.PI * part) * 7;
        ctx.globalAlpha = .35 + Math.sin(Math.PI * part) * .6; ctx.fillStyle = ['#e7c995','#acded5','#dbb8ee'][i % 3];
        ctx.beginPath(); ctx.arc(sx, sy, 2.4, 0, Math.PI * 2); ctx.fill();
      }
      ctx.globalAlpha=1;
      if (active.maxHp > 0) this.drawBar(x, y + 12, 49, active.hp / active.maxHp, '#7bcdda');
    }
    ctx.restore();
  }

  drawSiegeJar(building, point, width) {
    if (!(building.jarHp > 0) || !(building.jarMaxHp > 0)) return;
    const ctx = this.ctx, height = width * .78;
    ctx.save();
    // The glass stays translucent, so the enclosed defense and both durability bars remain legible.
    this.drawImage('siege-jars', point.x, point.y, width * 1.12, {alpha: .60});
    ctx.strokeStyle = '#b9f4f4'; ctx.globalAlpha = .5;
    ctx.beginPath(); ctx.ellipse(point.x, point.y - 1, width * .49, width * .145, 0, 0, Math.PI * 2); ctx.stroke();
    if (building.jarHp / building.jarMaxHp < .55) {
      ctx.beginPath(); ctx.moveTo(point.x - width * .20, point.y - height * .74);
      ctx.lineTo(point.x - width * .07, point.y - height * .55); ctx.lineTo(point.x - width * .14, point.y - height * .46);
      ctx.lineTo(point.x, point.y - height * .20); ctx.stroke();
    }
    ctx.globalAlpha=1;
    this.drawBar(point.x, point.y + 20, Math.max(32,width * .35), building.jarHp / building.jarMaxHp, '#95e3e7');
    ctx.restore();
  }

  drawSiegePulse(event, point, age) {
    const breaking = event.type === 'siege_break', blast = event.type === 'siege_blast';
    const life = blast ? .9 : .6;
    if (age > life) return;
    const t = this.reduced ? .5 : age / life;
    const ctx = this.ctx;
    ctx.globalAlpha = Math.max(0,1 - age / life);
    ctx.strokeStyle = blast ? '#ffc27e' : event.kind === 'jars' ? '#c8f3f6' : '#b0e0ce';
    ctx.lineWidth = blast ? 4 : 2;
    ctx.beginPath(); ctx.ellipse(point.x,point.y,9 + t * (blast ? 67 : 27),4 + t * (blast ? 33 : 15),0,0,Math.PI*2); ctx.stroke();
    if (breaking || blast) {
      ctx.fillStyle = ctx.strokeStyle;
      for(let i=0; i<(this.reduced?3:9); i++) {
        const a=i*2.399, x=point.x+Math.cos(a)*t*42, y=point.y+Math.sin(a)*t*19-30*Math.sin(Math.PI*t);
        ctx.beginPath(); ctx.moveTo(x,y-3); ctx.lineTo(x+4,y+2); ctx.lineTo(x-1,y+5); ctx.closePath();ctx.fill();
      }
    }
  }

  drawSiegeAim(x,y,placement) {
    const ctx=this.ctx, point=this.project(x,y), legal=this.placementLegal(x,y,placement);
    const radius=clamp(Number(placement.radius)||1,.2,16);
    ctx.save(); ctx.fillStyle=legal?'#8bcfe92b':'#ec886c35';ctx.strokeStyle=legal?'#c8f4ff':'#ffb29c';
    ctx.beginPath();ctx.ellipse(point.x,point.y,radius*28.284,radius*14.142,0,0,Math.PI*2);ctx.fill();
    ctx.setLineDash([7,5]);ctx.stroke();ctx.setLineDash([]);
    this.drawImage('siege-'+placement.type,point.x,point.y,38,{alpha:.72});
    ctx.restore();
  }

  drawHeavyTrapBurst(point, radius, age) {
    const ctx = this.ctx;
    const travel = this.reduced ? .65 : Math.min(1, age / .55);
    const fade = 1 - age / 1.25;
    const reach = radius * (.15 + .85 * travel);
    ctx.globalAlpha = fade * .85;
    ctx.strokeStyle = '#ffc482';
    ctx.lineWidth = 1 + 4 * fade;
    ctx.beginPath();
    ctx.ellipse(point.x, point.y, reach, reach * .5, 0, 0, Math.PI * 2);
    ctx.stroke();
    // Cracked ground radiates from the pressure plate along the yard plane.
    for (let i = 0; i < 6; i++) {
      const angle = i * Math.PI / 3 + .15;
      ctx.strokeStyle = i % 2 ? '#b87948' : '#f2b668';
      ctx.lineWidth = 1 + 2 * fade;
      ctx.beginPath();
      ctx.moveTo(point.x + Math.cos(angle) * 4, point.y + Math.sin(angle) * 2);
      for (let step = 1; step <= 3; step++) {
        const direction = angle + (step % 2 ? .13 : -.13);
        const length = reach * step / 3;
        ctx.lineTo(point.x + Math.cos(direction) * length, point.y + Math.sin(direction) * length * .5);
      }
      ctx.stroke();
    }
  }
  drawExplosion(event, point, age) {
    const context = this.ctx, fade = 1 - age / 1.25, trap = event.type === 'trap';
    const radius = clamp(Number(event.radius || event.splash || 2) * 24, 35, 90);
    if (trap && event.kind === 'heavytrap') {
      this.drawHeavyTrapBurst(point, radius, age);
      return;
    }
    context.globalAlpha = fade * .75; context.strokeStyle = trap ? '#f1c48b' : '#ffcb7a'; context.lineWidth = Math.max(1, 8 * fade);
    context.beginPath(); context.ellipse(point.x, point.y, 5 + age * radius, 3 + age * radius * .45, 0, 0, Math.PI * 2); context.stroke();
    if (event.airburst === true && !trap) {
      // Only the server's researched Airburst flag adds the elevated shock wave.
      const motion = this.reduced ? .35 : age, lift = 32 + motion * 18;
      context.globalAlpha = fade * .65; context.strokeStyle = '#fff0b8'; context.lineWidth = Math.max(1, 4 * fade);
      context.beginPath(); context.ellipse(point.x, point.y - lift, 8 + motion * radius * .8, 5 + motion * radius * .34, 0, 0, Math.PI * 2); context.stroke();
      for (const side of [-1, 1]) {
        context.beginPath(); context.moveTo(point.x + side * 7, point.y - 8);
        context.quadraticCurveTo(point.x + side * (16 + motion * radius * .3), point.y - lift * .6, point.x + side * (8 + motion * radius * .7), point.y - lift); context.stroke();
      }
    }
    if (age < .2) {
      context.globalAlpha = (1 - age / .2) * .65; context.fillStyle = '#fff3c4'; context.beginPath();
      context.ellipse(point.x, point.y - 12, 8 + age * radius, 12 + age * radius * .7, 0, 0, Math.PI * 2); context.fill();
    }
    const particles = this.reduced ? 5 : trap ? 13 : 21;
    for (let i = 0; i < particles; i++) {
      const angle = i * 2.399 + event.id, speed = radius * (.5 + (i % 5) * .15), x = point.x + Math.cos(angle) * age * speed;
      const y = point.y + Math.sin(angle) * age * speed * .4 - Math.sin(age / 1.25 * Math.PI) * (16 + i % 3 * 10);
      context.globalAlpha = fade * .85; context.fillStyle = trap ? (i % 2 ? '#d6c19c' : '#8e8264') : (i % 3 ? '#ffc069' : '#f1875a');
      context.save(); context.translate(x, y); context.rotate(angle + age * 4);
      if (trap) context.fillRect(-2 * fade, -4 * fade, 4 * fade, 8 * fade);
      else { context.beginPath(); context.arc(0, 0, Math.max(.5, (3 + i % 4) * fade), 0, Math.PI * 2); context.fill(); }
      context.restore();
    }
  }

  drawAbilityEffect(event, point, target, age) {
    const context = this.ctx, life = event.type === 'catapult_impact' ? 1.45 : 1.1;
    if (age < 0 || age > life) return;
    const fade = 1 - age / life, motion = this.reduced ? .28 : age;
    const radius = clamp(Number(event.radius || 1.8), .2, 12);
    context.globalAlpha = fade;
    if (event.type === 'blink') {
      const from = this.project(event.x, event.y), to = this.project(event.tx, event.ty);
      context.strokeStyle = '#96dfe9'; context.lineWidth = 2; context.setLineDash([5, 7]);
      context.beginPath(); context.moveTo(from.x, from.y - 10); context.lineTo(to.x, to.y - 10); context.stroke(); context.setLineDash([]);
      for (const p of [from, to]) {
        context.strokeStyle = '#d7ffff'; context.lineWidth = 2.5;
        context.beginPath(); context.ellipse(p.x, p.y - 15, 8 + motion * 12, 17 + motion * 10, -.2, 0, Math.PI * 2); context.stroke();
      }
    } else if (event.type === 'multistrike') {
      // Claws appear at the struck target, not beside the attacking Fink.
      context.strokeStyle = '#f9dbaa'; context.lineWidth = 2.5;
      for (let i = -1; i <= 1; i++) {
        context.beginPath(); context.moveTo(target.x - 9 + i * 5, target.y - 12);
        context.quadraticCurveTo(target.x + i * 4, target.y - 3, target.x + 9 + i * 4, target.y + 7); context.stroke();
      }
    } else if (event.type === 'ricochet') {
      // event.x/y is the previous struck building; sourceId is still the Teratorn.
      const from = this.project(event.x, event.y), to = this.project(event.tx, event.ty);
      const amount = this.reduced ? .65 : clamp(age / .5, 0, 1), bend = 28;
      context.strokeStyle = '#f7bd7977'; context.lineWidth = 2;
      context.beginPath(); context.moveTo(from.x, from.y - 18);
      context.quadraticCurveTo((from.x + to.x) / 2, Math.min(from.y, to.y) - bend, to.x, to.y - 18); context.stroke();
      context.fillStyle = '#ffe3a5'; context.beginPath();
      context.arc(lerp(from.x, to.x, amount), lerp(from.y, to.y, amount) - 18 - Math.sin(amount * Math.PI) * bend, 4, 0, Math.PI * 2); context.fill();
    } else if (event.type === 'venom') {
      context.fillStyle = '#b6e573';
      for (let i = 0; i < (this.reduced ? 3 : 6); i++) {
        context.beginPath(); context.arc(target.x + Math.sin(i * 2.4) * (8 + motion * 9), target.y - 5 - motion * 20 + Math.cos(i * 2.4) * 6, 2.4, 0, Math.PI * 2); context.fill();
      }
    } else if (event.type === 'cloak') {
      context.strokeStyle = event.active ? '#b8e9f2' : '#d8edc2'; context.lineWidth = 1.7;
      context.beginPath(); context.ellipse(point.x, point.y - 12, 14 + motion * 13, 18 + motion * 8, 0, 0, Math.PI * 2); context.stroke();
    } else if (event.type === 'whirlwind') {
      context.strokeStyle = '#efe2b4'; context.lineWidth = 2.2;
      for (let i = 0; i < 3; i++) {
        const spin = this.reduced ? i * 2 : motion * 7 + i * 2;
        context.beginPath(); context.ellipse(point.x, point.y - 5 - i * 3, radius * 24, radius * 10, 0, spin, spin + 1.5); context.stroke();
      }
    } else if (event.type === 'acid' || event.type === 'emerge_splash') {
      const acid = event.type === 'acid', color = acid ? '#b8ed72' : '#dcc294';
      context.strokeStyle = color; context.lineWidth = 3 * fade + 1;
      context.beginPath(); context.ellipse(point.x, point.y, (8 + motion * radius * 28), (4 + motion * radius * 14), 0, 0, Math.PI * 2); context.stroke();
      const particles = this.reduced ? 4 : 12;
      for (let i = 0; i < particles; i++) {
        const angle = i * 2.399, distance = motion * radius * 24;
        context.fillStyle = i % 2 ? color : acid ? '#75a74a' : '#927c58'; context.beginPath();
        context.arc(point.x + Math.cos(angle) * distance, point.y + Math.sin(angle) * distance * .5 - Math.sin(motion * 2) * 13, Math.max(1, 4 * fade), 0, Math.PI * 2); context.fill();
      }
    } else if (event.type === 'buff') {
      context.strokeStyle = '#efb6f0'; context.lineWidth = 2;
      context.beginPath(); context.ellipse(target.x, target.y + 13, 14 + motion * 9, 6 + motion * 4, 0, 0, Math.PI * 2); context.stroke();
    } else if (event.type === 'catapult_impact') {
      const color = artilleryColors[event.kind] || '#e4d9b8', putty = event.kind === 'putty';
      const expansion = this.reduced ? 1 : Math.min(1, .18 + age * 2.5);
      context.fillStyle = putty ? '#d598e329' : event.kind === 'pebble' ? '#9bd0e522' : '#efbd6f24';
      context.strokeStyle = color; context.lineWidth = (putty ? 2 : 5) * fade + 1;
      context.beginPath(); context.ellipse(point.x, point.y, radius * 28.284 * expansion, radius * 14.142 * expansion, 0, 0, Math.PI * 2); context.fill(); context.stroke();
      for (let i = 0; i < (this.reduced ? 4 : putty ? 10 : 18); i++) {
        const angle = i * 2.399, spread = radius * 22 * Math.min(1, motion * 1.5), lift = Math.sin(Math.min(1, motion / life) * Math.PI) * (putty ? 34 : 58);
        const x = point.x + Math.cos(angle) * spread, y = point.y + Math.sin(angle) * spread * .5 - lift;
        context.fillStyle = i % 3 ? color : '#f6f0d4'; context.beginPath();
        if (event.kind === 'twig') { context.save(); context.translate(x, y); context.rotate(angle); context.fillRect(-2, -7 * fade, 4, 14 * fade); context.restore(); }
        else { context.ellipse(x, y, Math.max(.5, (putty ? 3 : 5) * fade), Math.max(.5, (putty ? 5 : 3) * fade), angle, 0, Math.PI * 2); context.fill(); }
      }
    }
  }

  drawCatapultTargets() {
    if (this.battle?.status !== 'active') return;
    const context = this.ctx;
    for (const shot of (this.battle.catapult?.pending || []).slice(0, 3)) {
      if (![shot.x, shot.y, shot.radius, shot.impactAt].every(Number.isFinite) || shot.radius <= 0) continue;
      const point = this.project(shot.x, shot.y), radius = Math.min(12, shot.radius), color = artilleryColors[shot.type] || '#ead5a4';
      context.save(); context.strokeStyle = color; context.lineWidth = 2; context.globalAlpha = .6;
      context.setLineDash([7, 6]); context.beginPath(); context.ellipse(point.x, point.y, radius * 28.284, radius * 14.142, 0, 0, Math.PI * 2); context.stroke(); context.setLineDash([]);
      context.beginPath(); context.moveTo(point.x - 10, point.y); context.lineTo(point.x + 10, point.y); context.moveTo(point.x, point.y - 6); context.lineTo(point.x, point.y + 6); context.stroke();
      context.restore();
    }
  }

  drawCatapultProjectiles() {
    if (this.battle?.status !== 'active') return;
    const context = this.ctx, elapsed = this.battleElapsed(), origin = this.project(1-this.yardMargin, 31+this.yardMargin);
    for (const shot of (this.battle.catapult?.pending || []).slice(0, 3)) {
      if (![shot.x, shot.y, shot.impactAt].every(Number.isFinite)) continue;
      const target = this.project(shot.x, shot.y), progress = clamp(elapsed - (shot.impactAt - 1), 0, 1), color = artilleryColors[shot.type] || '#ead5a4';
      context.save(); context.strokeStyle = color; context.fillStyle = color; context.lineWidth = 2;
      if (this.reduced) {
        context.globalAlpha = .65; context.font = '600 11px system-ui'; context.textAlign = 'center';
        context.fillText('Incoming', target.x, target.y - 22); context.restore(); continue;
      }
      const arc = t => ({ x: lerp(origin.x, target.x, t), y: lerp(origin.y - 35, target.y, t) - Math.sin(t * Math.PI) * 145 });
      const point = arc(progress);
      context.globalAlpha = .5; context.beginPath();
      for (let i = 0; i < 7; i++) {
        const t = clamp(progress - .12 + i * .02, 0, 1), p = arc(t);
        if (!i) context.moveTo(p.x, p.y); else context.lineTo(p.x, p.y);
      }
      context.stroke(); context.globalAlpha = 1;
      context.shadowColor = color; context.shadowBlur = 8;
      context.beginPath(); context.ellipse(point.x, point.y, shot.type === 'pebble' ? 8 : 6, shot.type === 'putty' ? 8 : 5, -.45, 0, Math.PI * 2); context.fill();
      context.shadowBlur = 0; context.fillStyle = '#fff5d9'; context.beginPath(); context.arc(point.x - 2, point.y - 2, 2.5, 0, Math.PI * 2); context.fill(); context.restore();
    }
  }

  flingerZone(placement) {
    const flinger = this.battle?.flinger;
    if (placement.mode !== 'deploy' || !flinger?.weights) return null;
    const troops = placement.troops || (placement.type ? { [placement.type]: 1 } : {});
    const weight = Object.entries(troops).reduce((sum, [type, count]) => sum + Number(count) * (flinger.weights[type] ?? this.catalog.monsters[type]?.size ?? 0), 0);
    return Math.max(200, Math.floor(weight / 4)) / (2 * flinger.pixelsPerTile);
  }
  placementPoint(pos) {
    const mode = this.placement.mode;
    // Combat reticles follow the pointer, while construction keeps its half-tile grid.
    const grid = ['catapult','siege'].includes(mode) || (['champion', 'deploy'].includes(mode) && this.battle?.flinger?.weights) ? 1000 : 2;
    return { x: Math.round(pos.x * grid) / grid, y: Math.round(pos.y * grid) / grid };
  }
  placementLegal(x, y, placement = this.placement) {
    if (!placement || !Number.isFinite(x) || !Number.isFinite(y)) return false;
    if(placement.mode==='planner'&&(!this.planner||this.planner.busy||this.planner.lockedIds.includes(Number(placement.id))))return false;
    if (placement.mode === 'siege') {
      const siege=this.battle?.siege;
      if(this.battle?.status!=='active' || !siege || siege.used || siege.reason || siege.type!==placement.type
        || !this.insideYard(x,y,.4)) return false;
      const distance = building => {
        const half=(this.catalog.buildings[building.type]?.size || 2)/2;
        return Math.hypot(Math.max(0,Math.abs(x-building.x)-half),Math.max(0,Math.abs(y-building.y)-half));
      };
      if(siege.type==='jars')return this.visibleBuildings().some(building=>building.hp>0 &&
        ['sniper','cannon','laser','tesla','aerial','railgun','quaketower','magmatower','infernocannon','spurtzcannon','diamondspurtz'].includes(building.type) && distance(building)<=siege.radius);
      return siege.type==='decoy' && !this.visibleBuildings().some(building=>building.hp>0 &&
        !isTrap(building.type) && !this.untargetable(building) && distance(building)<.375);
    }
    if (placement.mode === 'catapult') {
      if (!this.insideYard(x,y,.4) || this.battle?.status !== 'active') return false;
      if (this.battleElapsed() + 1 > Number(this.battle.duration || 300) + .00001) return false;
      const family = this.catalog.catapultBombs?.[placement.type], tier = Number(placement.level), catapult = this.battle.catapult;
      const spec = Number.isInteger(tier) && tier > 0 ? family?.levels?.[tier] : null;
      if (!catapult || !family || !spec || !(Number(catapult.level) >= Number(family.requiredLevel)) || catapult.used?.[placement.type]) return false;
      const resources = Number(catapult.resources?.[family.resource]), cost = Number(spec.cost);
      // Defender snapshots omit the attacker's purse. Absence never means unlimited funds.
      return Number.isFinite(resources) && Number.isFinite(cost) && cost >= 0 && resources >= cost;
    }
    const champion = placement.mode === 'champion', deploy = placement.mode === 'deploy' || champion, spec = placement.mode==='yardSign'?{size:.8}:placement.mode==='decoration'?this.catalog.decorations?.[placement.item]:this.catalog.buildings[placement.type], size = spec?.size || 2;
    if(placement.mode==='decoration'&&!spec)return false;
    if(!deploy&&this.yardSigns().some(s=>Math.abs(s.x-x)<(size+.8)/2+.15-1e-9&&Math.abs(s.y-y)<(size+.8)/2+.15-1e-9))return false;
    if(!deploy && this.yardDecorations().some(p=>{const gap=(size+(this.catalog.decorations?.[p.item]?.size||.4))/2+.15;return !(placement.mode==='decoration' && placement.pieceId===p.id) && Math.abs(p.x-x)<gap-1e-9 && Math.abs(p.y-y)<gap-1e-9;}))return false;
    if (!this.insideYard(x,y,deploy ? .4 : 1+size/2-1e-9)) return false;
    if (!deploy && (this.base?.seasonal?.objects||[]).some(item=>{
      const gap=(size+(Number(item.type)===53?.65:1.8))/2+.15;
      return Math.abs(item.x-x)<gap && Math.abs(item.y-y)<gap;
    })) return false;
    if (!deploy && (this.base?.mushrooms?.items||[]).some(item=>Math.abs(item.x-x)<(size+.65)/2+.15 && Math.abs(item.y-y)<(size+.65)/2+.15)) return false;
    if (deploy && this.battle?.status !== 'active') return false;
    if (champion) {
      const selected = placement.type==='krallen'?this.battle.krallen:this.battle.champion;
      if (!selected?.available || selected.deployed || !(selected.hp > 0) || selected.type!=='krallen'&&!this.catalog.champions?.[selected.type] || placement.type && placement.type !== selected.type) return false;
    }
    const zone = this.flingerZone(placement);
    const padding = Number(this.battle?.deploymentPadding ?? 1.25);
    if (this.visibleBuildings().some(building => {
      if (!deploy && building.id === placement.id || deploy && (isTrap(building.type) || this.untargetable(building))) return false;
      const foundation = (this.catalog.buildings[building.type]?.size || 2) / 2;
      if (zone !== null) {
        if (building.hp <= 0) return false;
        const dx = Math.max(0, Math.abs(building.x - x) - foundation);
        const dy = Math.max(0, Math.abs(building.y - y) - foundation);
        return dx * dx + dy * dy < zone * zone;
      }
      const half = foundation + (deploy ? padding : size / 2 + .15);
      return Math.abs(building.x - x) < half-(deploy?0:1e-9) && Math.abs(building.y - y) < half-(deploy?0:1e-9);
    })) return false;
    if (deploy && !champion && this.battle?.flinger) {
      if (Number(this.battle.flinger.readyAt || 0) > this.battleElapsed()) return false;
      const troops = placement.troops || (placement.type ? { [placement.type]: 1 } : {});
      const entries = Object.entries(troops);
      if (!entries.length || entries.some(([type, count]) => !this.catalog.monsters[type] || !Number.isInteger(Number(count)) || Number(count) <= 0 || Number(count) > Number(this.battle.remaining?.[type] ?? 0))) return false;
      const unitCount = entries.reduce((sum, [,count]) => sum + Number(count), 0);
      const deployed = (this.battle.units || []).filter(unit => unit.team !== 'defender' && !unit.champion).length;
      if (unitCount + deployed > (this.battle.flinger.unitLimit ?? 200)) return false;
      const space = entries.reduce((total, [type, count]) => total + (this.battle.flinger.weights?.[type] ?? this.catalog.monsters[type].size) * Number(count), 0);
      if (space <= 0 || space > Number(this.battle.flinger.capacity)) return false;
    }
    return true;
  }
  drawGhost() {
    const pos = this.world(this.pointer);
    if(this.placement.mode==='planner'){pos.x-=this.placement.offsetX||0;pos.y-=this.placement.offsetY||0;}
    const { x, y } = this.placementPoint(pos), context = this.ctx;
    if (!Number.isFinite(x) || !Number.isFinite(y)) return;
    if(this.placement.mode==='yardSign'){const p=this.project(x,y),ok=this.placementLegal(x,y);this.diamond(x,y,1,ok?'#d5ed9e42':'#ef856655',ok?'#d5ed9e':'#ef8566');this.drawImage('simplesign',p.x,p.y,36,{alpha:.7});return;}
    if (this.placement.mode === 'decoration') {this.drawDecoration({...this.placement,x,y},true,this.placementLegal(x,y));return;}
    if (this.placement.mode === 'siege') { this.drawSiegeAim(x,y,this.placement); return; }
    if (this.placement.mode === 'catapult') { this.drawCatapultGhost(x, y, this.placement); return; }
    const placement = this.placement, champion = placement.mode === 'champion', deploy = placement.mode === 'deploy' || champion;
    const troops = placement.troops || (placement.type ? { [placement.type]: 1 } : {});
    const entries = Object.entries(troops).filter(([, count]) => count > 0);
    const chosenChampion=placement.type==='krallen'?this.battle?.krallen:this.battle?.champion;
    const type = champion ? chosenChampion?.type || placement.type : placement.type || entries[0]?.[0], size = deploy ? .6 : this.catalog.buildings[type]?.size || 2;
    const valid = this.placementLegal(x, y, placement), point = this.project(x, y);
    context.save(); context.lineWidth = 2;
    const zone = this.flingerZone(placement);
    if (zone !== null) {
      context.fillStyle = valid ? '#d2ef9c2e' : '#ed806e44';
      context.strokeStyle = valid ? '#e8ffbf' : '#ffb39c';
      context.beginPath(); context.ellipse(point.x, point.y, zone * 20 * Math.SQRT2, zone * 10 * Math.SQRT2, 0, 0, Math.PI * 2);
      context.fill(); context.stroke();
    } else this.diamond(x, y, Math.max(size, 1), valid ? '#ddf4b448' : '#f5a28355', valid ? '#e8ffbf' : '#ffb39c');
    if (deploy && !champion && entries.length > 1) {
      entries.slice(0, 3).forEach(([key], index) => this.drawImage(key, point.x + (index - (Math.min(3, entries.length) - 1) / 2) * 23, point.y - index % 2 * 4, 38, { alpha: .72 }));
    } else this.drawImage(type, point.x, point.y, champion ? unitWidths[type] || 85 : deploy ? 45 : 40 * size * (visual[type] || 1.3), { alpha: .7, outline: champion ? '#e8c88880' : null });
    if (deploy) {
      const padding = Number(this.battle?.deploymentPadding ?? 1.25);
      for (const building of this.visibleBuildings()) {
        if (isTrap(building.type) || this.untargetable(building) || (zone !== null && building.hp <= 0)) continue;
        this.diamond(building.x, building.y, (this.catalog.buildings[building.type]?.size || 2) + (zone === null ? padding * 2 : 0), '#eb9f8a0c', '#e8ac8c33');
      }
      const count = entries.reduce((total, [, value]) => total + Number(value), 0);
      const cooldown = Math.max(0, Number(this.battle?.flinger?.readyAt || 0) - this.battleElapsed());
      const label = champion
        ? valid ? `Deploy ${(type==='krallen'?'Krallen':this.catalog.champions?.[type]?.name)||'champion'} · Lv ${chosenChampion?.level || 1}` : 'Choose clear ground for your champion'
        : cooldown > 0 ? `Flinger ready in ${cooldown.toFixed(1)}s` : count > 1 ? `${count} monsters in the Flinger` : '';
      if (label) {
        context.font = '600 12px system-ui'; context.textAlign = 'center';
        const width = context.measureText(label).width + 18;
        context.fillStyle = '#173227eb'; this.roundRect(point.x - width / 2, point.y + 22, width, 24, 5); context.fill();
        context.fillStyle = valid ? '#e8f5c3' : '#efb59d'; context.fillText(label, point.x, point.y + 38);
      }
    }
    context.restore();
  }
  drawCatapultGhost(x, y, placement) {
    const context = this.ctx, family = this.catalog.catapultBombs?.[placement.type], spec = family?.levels?.[Number(placement.level)];
    const radius = clamp(Number(spec?.radius || placement.radius || 1), .2, 12), point = this.project(x, y), valid = this.placementLegal(x, y, placement);
    const color = valid ? artilleryColors[placement.type] || '#e4dca9' : '#f2a589';
    context.save(); context.fillStyle = valid ? `${color}20` : '#e8a08022'; context.strokeStyle = color; context.lineWidth = 2;
    context.beginPath(); context.ellipse(point.x, point.y, radius * 28.284, radius * 14.142, 0, 0, Math.PI * 2); context.fill();
    context.setLineDash([8, 5]); context.stroke(); context.setLineDash([]);
    context.beginPath(); context.ellipse(point.x, point.y, 12, 6, 0, 0, Math.PI * 2); context.stroke();
    context.beginPath(); context.moveTo(point.x - 22, point.y); context.lineTo(point.x - 8, point.y);
    context.moveTo(point.x + 8, point.y); context.lineTo(point.x + 22, point.y);
    context.moveTo(point.x, point.y - 13); context.lineTo(point.x, point.y - 5);
    context.moveTo(point.x, point.y + 5); context.lineTo(point.x, point.y + 13); context.stroke();
    const sizes = ['Small', 'Medium', 'Large', 'Huge'], title = `${sizes[Number(placement.level) - 1] || 'Selected'} ${family?.name || 'Catapult shot'}`;
    let detail = valid ? `${Number(spec.cost).toLocaleString('en-US')} ${family.resource} · one shot per family` : 'Unavailable at this target';
    const catapult = this.battle?.catapult;
    if (!this.insideYard(x,y,.4)) detail = 'Aim inside the backyard';
    else if (this.battleElapsed() + 1 > Number(this.battle?.duration || 300) + .00001) detail = 'Not enough time for the shot to land';
    else if (catapult?.used?.[placement.type]) detail = 'This resource family has already been used';
    else if (catapult && family && Number(catapult.level) < Number(family.requiredLevel)) detail = `Requires Catapult level ${family.requiredLevel}`;
    else if (spec && catapult?.resources && Number(catapult.resources[family.resource]) < Number(spec.cost)) detail = `Need ${Number(spec.cost).toLocaleString('en-US')} ${family.resource}`;
    context.font = '600 12px system-ui'; context.textAlign = 'center';
    const width = Math.max(context.measureText(title).width, context.measureText(detail).width) + 24;
    context.fillStyle = '#182e29ed'; this.roundRect(point.x - width / 2, point.y + 20, width, 46, 7); context.fill();
    context.fillStyle = color; context.fillText(title, point.x, point.y + 38);
    context.font = '11px system-ui'; context.fillStyle = valid ? '#e4eacb' : '#eeb49d'; context.fillText(detail, point.x, point.y + 55);
    context.restore();
  }
  drawLabel(building) {
    const spec = building.decoration?this.catalog.decorations[building.item]:this.catalog.buildings[building.type] || {}, point = this.project(building.x, building.y), context = this.ctx;
    const text = building.decoration?spec.name:`${spec.name || building.type} · Lv ${building.level}`;
    context.font = '600 13px system-ui'; const width = context.measureText(text).width + 24;
    const y = point.y - 40 * (spec.size || 2) * (visual[building.type] || 1.3) * .8 - 20;
    context.fillStyle = '#173227f0'; context.strokeStyle = '#c9dfa764'; context.lineWidth = 1;
    this.roundRect(point.x - width / 2, y, width, 29, 6); context.fill(); context.stroke();
    context.textAlign = 'center'; context.fillStyle = '#edf0d4'; context.fillText(text, point.x, y + 19);
  }
  drawAmbient() {
    if (this.reduced) return;
    const context = this.ctx;
    for (let i = 0; i < 10; i++) {
      const point = this.project((i * 7.73) % this.yardExtent-this.yardMargin, (i * 13.1) % this.yardExtent-this.yardMargin), alpha = (Math.sin(this.now * .8 + i * 1.3) + 1) * .5;
      context.fillStyle = `rgba(233,240,183,${alpha * .5})`; context.beginPath();
      context.arc(point.x + Math.sin(this.now * .3 + i) * 13, point.y - 20 - Math.cos(this.now * .25 + i) * 20, 1.4, 0, Math.PI * 2); context.fill();
    }
  }
}
