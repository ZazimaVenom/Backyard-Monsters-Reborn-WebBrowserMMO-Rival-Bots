import assert from 'node:assert/strict';
import { YardRenderer } from '../public/assets/renderer.js';

let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; console.log(`PASS ${message}`); };
const calls = [];
const context = new Proxy({}, {
  get(target, key) {
    if (key === 'measureText') return text => ({ width: text.length * 7 });
    if (key === 'createRadialGradient') return () => ({ addColorStop() {} });
    if (key in target) return target[key];
    return (...args) => { for (const number of args.filter(value => typeof value === 'number')) assert.ok(Number.isFinite(number), `${key} received a nonfinite coordinate`); calls.push([key, ...args]); };
  },
  set(target, key, value) { target[key] = value; return true; },
});
const makeRenderer = () => Object.assign(Object.create(YardRenderer.prototype), {
  ctx: context, catalog: {
    buildings: { townhall: { size: 3.4 }, wall: { size: .8 }, trap: { size: .8 }, laser: { size: 2 }, championcage: { size: 3.5 }, championchamber: { size: 2.5 } },
    monsters: {
      pokey: { size: 10, movement: 'ground' }, teratorn: { size: 70, movement: 'air' },
      wormzer: { size: 70, movement: 'burrow' }, zafreeti: { size: 100, movement: 'air' },
      brain: { size: 30, movement: 'ground' }, crabatron: { size: 40, movement: 'ground' },
      projectx: { size: 70, movement: 'ground' }, octo: { size: 10, movement: 'ground' }, fink: { size: 20, movement: 'ground' },
      ichi: { size: 40, movement: 'ground' }, bandito: { size: 15, movement: 'ground' }, dave: { size: 100, movement: 'ground' },
    },
    champions: {
      gorgo: { name: 'Gorgo', art: 'gorgo.png', levels: { 1: { hp: 5000, movement: 'ground' } } },
      drull: { name: 'Drull', art: 'drull.png', levels: { 1: { hp: 1800, movement: 'ground' } } },
      fomor: { name: 'Fomor', art: 'fomor.png', levels: { 1: { hp: 2000, movement: 'ground' }, 2: { hp: 2200, movement: 'ground' }, 3: { hp: 2500, movement: 'air' } } },
    },
  },
  base: { buildings: [], army: {} }, battle: null, images: {}, bounds: {}, fx: [],
  unitEffects: new Map(), previousUnits: new Map(), now: .26, reduced: false,
  snapshotAt: performance.now(), selected: null, hover: null, callbacks: {},
  placement: null, lastEvent: 0, serverOffset: 0,
});
const building = (id, type, x, y) => ({ id, type, x, y, level: 1, hp: 1000, max_hp: 1000, ready_at: 0 });
const unit = (id, type, team = 'attacker') => ({ id, type, team, x: 6, y: 6, state: 'walk', hp: 90, max_hp: 100, movement: type === 'teratorn' || type === 'zafreeti' ? 'air' : type === 'wormzer' ? 'burrow' : 'ground' });

const renderer = makeRenderer();
renderer.base.buildings = [building(1, 'townhall', 16, 16), { ...building(2, 'trap', 4, 4), hidden: true }];
check(renderer.hit({ x: 4, y: 4 }) === null, 'hidden traps cannot be selected by their footprint or art');
renderer.battle = { buildings: [], units: [], status: 'active', elapsed: 0, duration: 300, remaining: { pokey: 20, teratorn: 2 } };
check(renderer.visibleBuildings().length === 0, 'an empty battle snapshot never falls back to private base buildings');
renderer.battle.buildings = [building(2, 'trap', 4, 4)];
const deployment = { mode: 'deploy', type: 'pokey' };
check(renderer.placementLegal(4, 4, deployment), 'revealed traps do not create deployment probing zones');
renderer.battle.buildings.push({ ...building(3, 'wall', 8, 8), hp: 0 });
check(!renderer.placementLegal(8, 8, deployment), 'non-trap ruins retain the server deployment exclusion');
check(!renderer.placementLegal(.3, 4, deployment) && renderer.placementLegal(.5, 4, deployment), 'deployment boundary matches the server');
renderer.battle.flinger = { capacity: 100, readyAt: 0, cooldown: 5 };
check(renderer.placementLegal(2, 2, { mode: 'deploy', troops: { pokey: 3, teratorn: 1 } }), 'mixed bucket uses total housing space');
check(!renderer.placementLegal(2, 2, { mode: 'deploy', troops: { pokey: 4, teratorn: 1 } }), 'over-capacity mixed bucket is marked invalid');
check(!renderer.placementLegal(2, 2, { mode: 'deploy', troops: { pokey: 25 } }), 'bucket cannot advertise more troops than remain');
renderer.battle.flinger.readyAt = 5;
check(!renderer.placementLegal(2, 2, deployment), 'Flinger cooldown appears in deployment validity');
renderer.battle.flinger.readyAt = 0;
check(!renderer.placementLegal(NaN, 2, deployment), 'nonfinite cursor coordinates are rejected');

renderer.images['pokey-walk'] = { width: 1024, height: 1024 };
renderer.images['teratorn-fly'] = { width: 1024, height: 1024 };
renderer.images['wormzer-emerge'] = { width: 1024, height: 1024 };
check(renderer.animationFrame(unit('u1', 'pokey')).frame === 2, 'walk animation uses the backend walk state');
check(renderer.animationFrame(unit('u2', 'teratorn')).key === 'teratorn-fly', 'flying monsters use the wing animation sheet');
renderer.reduced = true;
check(renderer.animationFrame(unit('u2', 'teratorn')).frame === 1, 'reduced motion freezes the wing animation');
renderer.reduced = false;
renderer.unitEffects.set('u3:emerge', { start: performance.now() - 250 });
check(renderer.animationFrame(unit('u3', 'wormzer')).frame === 1, 'emergence plays sequential newly drawn frames');
renderer.unitEffects.set('u3:emerge', { start: performance.now() - 1000 });
check(renderer.animationFrame(unit('u3', 'wormzer')).key === 'wormzer', 'completed emergence returns to the stable monster sprite');

const spriteCalls = [];
const realDrawImage = renderer.drawImage;
renderer.drawImage = (...args) => { spriteCalls.push(args); return true; };
renderer.drawUnit({ ...unit('u3', 'wormzer'), state: 'burrow' });
check(spriteCalls.length === 0, 'submerged Wormzer shows a ground trail instead of a floating full-body sprite');
renderer.drawUnit(unit('d1', 'teratorn', 'defender'));
check(spriteCalls.at(-1)[4].bounce > 20 && spriteCalls.at(-1)[4].outline, 'air defenders have altitude and a distinct team outline');
renderer.battle.buildings.push(building(4, 'wall', 3, 10));
renderer.drawUnit({ ...unit('u1', 'pokey'), state: 'attack', targetId: 4 });
check(spriteCalls.at(-1)[4].flip === true, 'stationary attackers face their actual building target');
renderer.battle.units = [{ ...unit('u8', 'pokey'), x: 3, y: 10 }];
renderer.drawUnit({ ...unit('u4', 'zafreeti'), state: 'heal', targetId: 'u8' });
check(spriteCalls.at(-1)[4].flip === false, 'native-left Zafreeti faces its allied target on the left without mirroring');
renderer.drawImage = realDrawImage;

const sound = [];
renderer.callbacks.sound = value => sound.push(value);
const events = [
  { id: 1, type: 'heal', kind: 'zafreeti', x: 6, y: 6, tx: 9, ty: 9, sourceId: 'u4', targetId: 'u1', amount: 50 },
  { id: 2, type: 'shot', kind: 'laser', x: 15, y: 15, tx: 9, ty: 9, sourceId: 5, targetId: 'u1', amount: 80 },
  { id: 3, type: 'explode', kind: 'eyera', x: 9, y: 9, amount: 100, radius: 2.3 },
  { id: 4, type: 'trap', kind: 'trap', x: 9, y: 9, amount: 100, radius: 2.3 },
  { id: 5, type: 'emerge', kind: 'wormzer', x: 9, y: 9, sourceId: 'u3' },
  { id: 6, type: 'hit', kind: 'pokey', x: 6, y: 4, tx: 15, ty: 15, targetId: 5, amount: 25 },
].map(value => ({ ...value, t: 1 }));
renderer.setBattle({ id: 4, buildings: [building(5, 'laser', 15, 15)], units: [unit('u1', 'pokey'), unit('u4', 'zafreeti')], status: 'active', elapsed: 1, duration: 300, serverTime: Date.now() / 1000, events });
check(sound.join(',') === 'heal,laser,explode,explode,burrow', 'special combat events select their matching audio cues');
renderer.fx.forEach(event => { event.start = performance.now() - 100; });
renderer.drawEffects();
check(calls.some(call => call[0] === 'quadraticCurveTo') && calls.some(call => call[0] === 'fillText' && call[1] === '+50'), 'healing draws a beam and positive healing amount');
check(calls.some(call => call[0] === 'fillText' && call[1] === '−25' && call[2] === 0), 'melee damage appears at the struck target rather than the attacker');
const lastEffectCount = renderer.fx.length;
renderer.setBattle({ ...renderer.battle, events });
check(renderer.fx.length === lastEffectCount, 'repeated battle snapshots do not replay effects');
renderer.setBattle(null);
check(renderer.unitEffects.size === 0 && renderer.fx.length === 0 && renderer.previousUnits.size === 0, 'leaving a battle clears animation state');

const championUnit = (type = 'gorgo', level = 1) => ({ id: `champion-${type}`, type, level, champion: true, team: 'attacker', x: 4, y: 4, state: 'walk', hp: 1000, max_hp: 2000 });
const championPlacement = { mode: 'champion', type: 'gorgo' };
const championBattle = { id: 10, buildings: [building(1, 'townhall', 16, 16)], units: [], events: [], status: 'active', elapsed: 0, duration: 300,
  champion: { type: 'gorgo', level: 1, hp: 4000, max_hp: 5000, available: true, deployed: false },
  flinger: { capacity: 1, readyAt: 100, cooldown: 5 }, remaining: {}, serverTime: Date.now() / 1000 };
renderer.setBattle(championBattle);
check(renderer.placementLegal(2, 2, championPlacement), 'champion deployment is independent of Flinger reload, capacity and ordinary army');
check(!renderer.placementLegal(16, 16, { ...championPlacement, id: 1 }), 'champion deployment cannot bypass structure exclusions with a building ID');
renderer.battle.buildings.push(building(2, 'trap', 4, 4));
check(renderer.placementLegal(4, 4, championPlacement), 'champions use the same trap-safe deployment rule');
renderer.battle.champion.deployed = true;
check(!renderer.placementLegal(2, 2, championPlacement), 'a deployed champion cannot be previewed for a second launch');
renderer.battle.champion.deployed = false;
renderer.battle.champion.available = false;
check(!renderer.placementLegal(2, 2, championPlacement), 'unavailable champions cannot be launched');
renderer.battle.champion.available = true;
renderer.battle.champion.hp = 0;
check(!renderer.placementLegal(2, 2, championPlacement), 'defeated champions cannot be launched');
renderer.battle.champion.hp = 4000;
check(!renderer.placementLegal(2, 2, { mode: 'champion', type: 'drull' }), 'placement cannot change the server-selected champion');
renderer.battle.status = 'finished';
check(!renderer.placementLegal(2, 2, championPlacement), 'champions cannot launch into a finished battle');
renderer.battle.status = 'active';
const selectedChampion = renderer.battle.champion;
renderer.battle.champion = null;
check(!renderer.placementLegal(2, 2, championPlacement), 'legacy battles with no champion remain safe');
renderer.battle.champion = selectedChampion;

renderer.placement = championPlacement; renderer.pointer = { x: 2, y: 2 };
renderer.world = point => point;
const beforeGhost = calls.length;
renderer.drawGhost();
check(calls.slice(beforeGhost).some(call => call[0] === 'fillText' && call[1] === 'Deploy Gorgo · Lv 1') && !calls.slice(beforeGhost).some(call => call[0] === 'fillText' && /Flinger ready/.test(call[1])), 'champion ghost identifies the selected champion without a Flinger countdown');
renderer.placement = null;

const loaded = [];
const preload = makeRenderer();
preload.loadImage = async (key, file) => { loaded.push([key, file]); };
await preload.load();
check(['gorgo', 'drull', 'fomor', 'brain', 'crabatron', 'gorgo-walk', 'drull-walk', 'fomor-fly', 'brain-walk', 'crabatron-walk'].every(key => loaded.some(([value]) => value === key)), 'separate champion artwork, champion sheets and new ordinary-monster sheets are preloaded');
check(!preload.catalog.monsters.gorgo && !!preload.catalog.champions.gorgo, 'render loading does not mix champions into the ordinary monster catalog');

for (const key of ['gorgo-walk', 'drull-walk', 'fomor-fly']) renderer.images[key] = { width: 1254, height: 1254 };
check(renderer.animationFrame(championUnit()).key === 'gorgo-walk', 'walking Gorgo uses the generated walk sheet');
check(renderer.animationFrame(championUnit('drull')).key === 'drull-walk', 'walking Drull uses the generated walk sheet');
check(renderer.animationFrame({ ...championUnit(), state: 'attack' }).key === 'gorgo', 'attack motion does not falsely reuse a walk frame sequence');
check(renderer.animationFrame(championUnit('fomor', 2)).key === 'fomor', 'Fomor level 2 remains a ground sprite');
check(renderer.animationFrame(championUnit('fomor', 3)).key === 'fomor-fly', 'Fomor level 3 uses the flight sheet from its level stats');
renderer.reduced = true;
check(renderer.animationFrame(championUnit()).frame === 0 && renderer.animationFrame(championUnit('fomor', 3)).frame === 1, 'reduced motion freezes champion walk and flight poses');
renderer.reduced = false;
const savedSheet = renderer.images['drull-walk']; delete renderer.images['drull-walk'];
check(renderer.animationFrame(championUnit('drull')).key === 'drull', 'a missing champion sheet falls back to the static asset');
renderer.images['drull-walk'] = savedSheet;

renderer.setBattle(null);
renderer.base = { buildings: [building(4, 'championcage', 16, 16)], army: { pokey: 10 }, activeChampion: 'gorgo',
  champions: { gorgo: { hp: 3000, level: 1, battle_id: 0, frozen: false }, drull: { hp: 1800, level: 1, frozen: true } } };
let idle = renderer.idleUnits();
check(idle.filter(value => value.champion).length === 1 && idle.find(value => value.champion).type === 'gorgo', 'only the active champion appears beside the Cage');
check(renderer.idleChampion().max_hp === 5000 && renderer.idleChampion().hp === 3000, 'home champion health uses saved injury and level-specific maximum');
check(Math.hypot(renderer.idleChampion().x - 16, renderer.idleChampion().y - 16) < 4, 'home champion stays near its Cage');
renderer.base.champions.gorgo.frozen = true;
check(renderer.idleChampion() === null, 'frozen champions never wander in the yard');
renderer.base.champions.gorgo.frozen = false; renderer.base.champions.gorgo.hp = 0;
check(renderer.idleChampion() === null, 'zero-health champions do not appear as healthy roaming units');
renderer.base.champions.gorgo.hp = 3000; renderer.base.champions.gorgo.battle_id = 99;
check(renderer.idleChampion() === null, 'a champion reserved in a battle does not simultaneously roam at home');
renderer.base.champions.gorgo.battle_id = 0; renderer.base.buildings[0].ready_at = Date.now() / 1000 + 60;
check(renderer.idleChampion() === null, 'an unfinished Cage does not emit a home champion');
renderer.base.buildings[0].ready_at = 0; renderer.base.buildings[0].hp = 0;
check(renderer.idleChampion() === null, 'a ruined Cage does not emit a home champion');
renderer.base.buildings[0].hp = 1000; renderer.base.activeChampion = 'fomor'; renderer.base.champions.fomor = { hp: 2100, level: 3, frozen: false, battle_id: 0 };
check(renderer.idleChampion().movement === 'air' && renderer.idleChampion().max_hp === 2500, 'home Fomor reflects evolved flying movement and level stats');

renderer.setBattle({ ...championBattle, champion: selectedChampion });
const auraUnit = { ...championUnit('fomor', 3), auraRange: 6, auraBuff: .3 };
const beforeAura = calls.length;
renderer.drawAura(auraUnit);
check(calls.slice(beforeAura).some(call => call[0] === 'ellipse' && Math.abs(call[3] - 169.704) < .001 && Math.abs(call[4] - 84.852) < .001), 'Fomor aura projects the authoritative six-unit radius onto the ground');
const afterAura = calls.length;
renderer.drawAura({ ...auraUnit, hp: 0 }); renderer.drawAura({ ...auraUnit, hidden: true }); renderer.drawAura({ ...auraUnit, state: 'retreat' });
check(calls.length === afterAura, 'dead, hidden and retreating champions do not leave a live aura');
const beforeBuff = calls.length;
renderer.drawUnit({ ...unit('b1', 'pokey'), buffed: true, buffSource: 'champion-fomor' });
check(calls.slice(beforeBuff).some(call => call[0] === 'ellipse' && Math.abs(call[3] - 36 * .38) < .001), 'server-buffed allies receive a separate aura indicator');
const beforeChamp = calls.length;
renderer.drawUnit({ ...championUnit('drull'), team: 'defender' });
check(calls.slice(beforeChamp).some(call => call[0] === 'fillText' && call[1] === 'Drull · Lv 1'), 'champions retain readable species and evolution labels in combat');

const championSounds = [];
renderer.callbacks.sound = value => championSounds.push(value);
const championEvents = [
  { id: 1, t: 1, type: 'deploy', champion: true, kind: 'gorgo', sourceId: 'champion-gorgo', team: 'attacker', x: 2, y: 2 },
  { id: 2, t: 1, type: 'champion_retreat', kind: 'drull', targetId: 'champion-drull', team: 'defender', x: 9, y: 9 },
];
renderer.setBattle({ ...championBattle, id: 11, elapsed: 1, events: championEvents });
check(championSounds.join(',') === 'champion,champion-retreat', 'champion deployment and retreat dispatch distinct sound cues');
renderer.fx.forEach(event => { event.start = performance.now() - 100; });
const beforeRetreat = calls.length;
renderer.drawEffects();
check(calls.slice(beforeRetreat).some(call => call[0] === 'fillText' && call[1] === 'Drull retreats'), 'champion defeat is visually communicated as retreat');
renderer.setBattle({ ...renderer.battle, events: championEvents });
check(championSounds.length === 2, 'repeated snapshots do not repeat champion audio');

const facingRenderer = makeRenderer(), facingSprites = [];
facingRenderer.drawImage = (...args) => { facingSprites.push(args); return true; };
for (const key of ['brain-walk', 'crabatron-walk']) facingRenderer.images[key] = { width: 1254, height: 1254 };
check(facingRenderer.animationFrame(unit('brain-test', 'brain')).key === 'brain-walk', 'Brain uses its own four-pose walking sheet');
check(facingRenderer.animationFrame(unit('crab-test', 'crabatron')).key === 'crabatron-walk', 'Crabatron uses its own four-pose walking sheet');
check(['brain', 'crabatron'].every(type => facingRenderer.animationFrame({ ...unit('still', type), state: 'attack' }).key === type), 'Brain and Crabatron attacks use static art rather than pretending walking frames are attack frames');
facingRenderer.reduced = true;
check(['brain', 'crabatron'].every(type => facingRenderer.animationFrame(unit('reduced', type)).frame === 0), 'reduced motion freezes both new walking cycles');
facingRenderer.reduced = false;
const walkingSheets = facingRenderer.images; facingRenderer.images = {};
check(['brain', 'crabatron'].every(type => facingRenderer.animationFrame(unit('fallback', type)).key === type), 'both new species retain static fallback when their walk sheet is missing');
facingRenderer.images = walkingSheets;
facingRenderer.battle = { status: 'active', units: [], buildings: [building(20, 'wall', 3, 10), building(21, 'wall', 10, 3)] };
for (const type of ['brain', 'crabatron']) {
  facingRenderer.drawUnit({ ...unit('facing', type), state: 'attack', targetId: 20 });
  check(facingSprites.at(-1)[4].flip === false, `${type} native-left art faces a stationary target on its left without mirroring`);
  facingRenderer.drawUnit({ ...unit('facing', type), state: 'attack', targetId: 21 });
  check(facingSprites.at(-1)[4].flip === true, `${type} native-left art mirrors toward a stationary target on its right`);
  facingRenderer.previousUnits.set('facing', { x: 7, y: 6 });
  facingRenderer.drawUnit(unit('facing', type));
  check(facingSprites.at(-1)[0] === `${type}-walk` && facingSprites.at(-1)[4].flip === false, `${type} walking left preserves its native sheet orientation`);
  facingRenderer.previousUnits.set('facing', { x: 5, y: 6 });
  facingRenderer.drawUnit(unit('facing', type));
  check(facingSprites.at(-1)[0] === `${type}-walk` && facingSprites.at(-1)[4].flip === true, `${type} walking right mirrors its native sheet orientation`);
  facingRenderer.previousUnits.clear();
}
facingRenderer.drawUnit({ ...unit('idle', 'brain'), state: 'idle' });
check(facingSprites.at(-1)[4].flip === false, 'an idle native-left creature without a heading preserves its original pose');


// Version 0.4: real method calls with recorded canvas commands and server-shaped snapshots.
const expanded = makeRenderer();
for (const type of ['projectx', 'octo', 'fink', 'ichi', 'bandito', 'dave']) {
  expanded.images[`${type}-walk`] = { width: 1254, height: 1254 };
  check(expanded.animationFrame(unit('moving', type)).key === `${type}-walk`, `${type} selects its own generated walk cycle`);
  check(expanded.animationFrame({ ...unit('attack', type), state: 'attack' }).key === type, `${type} attacks keep static artwork instead of replaying walking poses`);
  expanded.reduced = true;
  check(expanded.animationFrame(unit('still', type)).frame === 0, `${type} walk cycle stops changing in reduced-motion mode`);
  expanded.reduced = false;
  const sheet = expanded.images[`${type}-walk`]; delete expanded.images[`${type}-walk`];
  check(expanded.animationFrame(unit('missing', type)).key === type, `${type} remains visible if its optional walk sheet cannot load`);
  expanded.images[`${type}-walk`] = sheet;
}
const expandedSprites = [];
expanded.drawImage = (...args) => { expandedSprites.push(args); return true; };
expanded.battle = { status: 'active', units: [], buildings: [building(31, 'wall', 3, 10), building(32, 'wall', 10, 3)] };
for (const type of ['projectx', 'octo', 'fink', 'ichi', 'bandito', 'dave']) {
  expanded.drawUnit({ ...unit('native', type), state: 'attack', targetId: 31 });
  check(expandedSprites.at(-1)[4].flip === !['bandito', 'dave'].includes(type), `${type} faces a left-side target with its correct native orientation`);
  expanded.drawUnit({ ...unit('native', type), state: 'attack', targetId: 32 });
  check(expandedSprites.at(-1)[4].flip === ['bandito', 'dave'].includes(type), `${type} faces a right-side target with its correct native orientation`);
}
const cropRenderer = makeRenderer();
cropRenderer.images['projectx-walk'] = { width: 1254, height: 1254 };
const cropStart = calls.length;
cropRenderer.drawImage('projectx-walk', 0, 0, 65, { frame: 3 });
const crop = calls.slice(cropStart).find(call => call[0] === 'drawImage');
check(crop[2] === 627 && crop[3] === 627 && crop[4] === 627 && crop[5] === 627, 'four-pose sheets crop the fourth cell from the actual 1254-pixel dimensions');

const artillery = makeRenderer();
artillery.catalog.catapultBombs = {
  twig: { name: 'Twig Fireball', resource: 'twig', requiredLevel: 1, levels: { 1: { cost: 1000, radius: 4 }, 2: { cost: 5000, radius: 4 }, 3: { cost: 20000, radius: 4 } } },
  pebble: { name: 'Pebble Bomb', resource: 'pebble', requiredLevel: 2, levels: { 1: { cost: 1000, radius: 4 }, 4: { cost: 30000, radius: 7 } } },
  putty: { name: 'Putty Rage', resource: 'putty', requiredLevel: 3, levels: { 1: { cost: 1000, radius: 3 }, 4: { cost: 30000, radius: 7 } } },
};
artillery.setBattle({ id: 91, status: 'active', elapsed: 1, duration: 300, events: [], buildings: [building(1, 'townhall', 16, 16)], units: [], remaining: {}, flinger: { readyAt: 500, capacity: 0 },
  catapult: { level: 3, used: { twig: false, pebble: false, putty: false }, resources: { twig: 50000, pebble: 50000, putty: 50000 }, pending: [], shots: [] } });
const bombPlacement = { mode: 'catapult', type: 'twig', level: 2, radius: 90 };
check(artillery.placementLegal(16, 16, bombPlacement), 'Catapult aim may overlap the Town Hall and ignores the empty reloading Flinger');
check(artillery.placementLegal(.4, 31.6, bombPlacement) && !artillery.placementLegal(.399, 31.6, bombPlacement) && !artillery.placementLegal(31.601, .4, bombPlacement), 'Catapult aim uses finite server bounds without building-footprint padding');
check(!artillery.placementLegal(Infinity, 4, bombPlacement) && !artillery.placementLegal(2, NaN, bombPlacement), 'Catapult aim rejects nonfinite coordinates');
check(!artillery.placementLegal(4, 4, { ...bombPlacement, level: 1.5 }) && !artillery.placementLegal(4, 4, { ...bombPlacement, level: 99 }) && !artillery.placementLegal(4, 4, { ...bombPlacement, type: 'goo' }), 'Catapult preview rejects unknown resources and invalid tiers');
artillery.battle.catapult.used.twig = true;
check(!artillery.placementLegal(4, 4, { ...bombPlacement, level: 1 }) && !artillery.placementLegal(4, 4, { ...bombPlacement, level: 3 }) && artillery.placementLegal(4, 4, { mode: 'catapult', type: 'pebble', level: 1 }), 'using one twig tier disables the whole twig family while preserving other families');
artillery.battle.catapult.used.twig = false;
artillery.battle.catapult.level = 1;
check(!artillery.placementLegal(4, 4, { mode: 'catapult', type: 'pebble', level: 1 }) && artillery.placementLegal(4, 4, bombPlacement), 'Catapult facility level gates ammunition families');
artillery.battle.catapult.level = 3; artillery.battle.catapult.resources.twig = 4999;
check(!artillery.placementLegal(4, 4, bombPlacement), 'Catapult preview requires enough of the selected resource');
artillery.battle.catapult.resources.twig = 5000;
check(artillery.placementLegal(4, 4, bombPlacement), 'an exactly affordable Catapult shot is valid');
const purse = artillery.battle.catapult.resources; delete artillery.battle.catapult.resources;
check(!artillery.placementLegal(4, 4, bombPlacement), 'defender snapshots without the attacker purse cannot preview an affordable shot');
artillery.battle.catapult.resources = purse;
const catapultSnapshot = artillery.battle.catapult; artillery.battle.catapult = null;
check(!artillery.placementLegal(4, 4, bombPlacement), 'legacy battles without Catapult state cannot acquire artillery');
artillery.battle.catapult = catapultSnapshot; artillery.battle.status = 'finished';
check(!artillery.placementLegal(4, 4, bombPlacement), 'completed battles do not allow artillery aiming');
artillery.battle.status = 'active';
artillery.battle.elapsed = 299.5;
check(!artillery.placementLegal(4, 4, bombPlacement), 'Catapult preview rejects a shot that cannot land before the invasion timer ends');
artillery.battle.elapsed = 1;
artillery.pointer = { x: 16, y: 16 }; artillery.world = point => point; artillery.placement = bombPlacement;
const ghostCalls = calls.length;
artillery.drawGhost();
const ghost = calls.slice(ghostCalls);
check(ghost.some(call => call[0] === 'ellipse' && Math.abs(call[3] - 4 * 28.284) < .001) && !ghost.some(call => call[0] === 'drawImage'), 'Catapult ghost uses the catalog blast radius and a crosshair instead of a sprite or supplied false radius');
check(ghost.some(call => call[0] === 'fillText' && call[1] === 'Medium Twig Fireball') && ghost.some(call => call[0] === 'fillText' && /5,000 twig/.test(call[1])), 'Catapult ghost identifies the resource family, tier and exact price');
artillery.battle.catapult.used.twig = true;
const spentCalls = calls.length; artillery.drawGhost();
check(calls.slice(spentCalls).some(call => call[0] === 'fillText' && /already been used/.test(call[1])), 'a spent family produces an explanatory aim label');
artillery.battle.catapult.used.twig = false;

artillery.battle.catapult.pending = [{ type: 'pebble', level: 2, x: 16, y: 16, radius: 5, impactAt: 2 }];
artillery.battleElapsed = () => 1.5;
const pendingCalls = calls.length; artillery.drawCatapultTargets(); artillery.drawCatapultProjectiles();
check(calls.slice(pendingCalls).some(call => call[0] === 'ellipse' && Math.abs(call[3] - 5 * 28.284) < .001), 'pending artillery marks the server-selected ground radius');
check(calls.slice(pendingCalls).some(call => call[0] === 'ellipse' && call[3] === 8 && call[2] < artillery.project(16, 16).y - 50), 'a pending pebble follows an elevated arc before impact');
artillery.reduced = true;
const reducedCalls = calls.length; artillery.drawCatapultProjectiles();
check(calls.slice(reducedCalls).some(call => call[0] === 'fillText' && call[1] === 'Incoming') && !calls.slice(reducedCalls).some(call => call[0] === 'ellipse'), 'reduced-motion artillery keeps an incoming label without moving projectiles');
artillery.reduced = false; artillery.battle.catapult.pending = [];
const emptyCalls = calls.length; artillery.drawCatapultTargets(); artillery.drawCatapultProjectiles();
check(calls.length === emptyCalls, 'landed artillery does not leave stale pending markers or projectiles');

const statusSprites = [];
artillery.drawImage = (...args) => { statusSprites.push(args); return true; };
const statusStart = calls.length;
artillery.drawUnit({ ...unit('status-brain', 'brain'), cloaked: true, labLevel: 2 });
check(statusSprites.at(-1)[4].alpha === .38 && calls.slice(statusStart).some(call => call[0] === 'fillText' && call[1] === 'CLOAK'), 'cloaked Brain remains translucently readable with an explicit status label');
artillery.drawUnit({ ...unit('status-bolt', 'bolt'), state: 'blink', blinking: true });
check(statusSprites.at(-1)[4].alpha === .24, 'blinking Bolt has a distinct transient transparency');
const poisonStart = calls.length;
artillery.drawUnit({ ...unit('status-poison', 'pokey'), poisoned: true, boosted: true, labLevel: 3 });
check(calls.slice(poisonStart).some(call => call[0] === 'bezierCurveTo') && calls.slice(poisonStart).some(call => call[0] === 'ellipse' && Math.abs(call[3] - 36 * .43) < .001), 'poison and putty status markers coexist without replacing team or Fomor indicators');
const markers = calls.slice(poisonStart).filter(call => call[0] === 'arc' && call[3] === 1.6);
check(markers.length === 3, 'three researched Lab tiers display three small ability markers');
artillery.drawUnit({ ...unit('status-clear', 'brain'), cloaked: false, blinking: false });
check(statusSprites.at(-1)[4].alpha === 1, 'a later clear snapshot restores ordinary sprite opacity');

const effectsRenderer = makeRenderer(), effectSounds = [];
effectsRenderer.callbacks.sound = sound => effectSounds.push(sound);
const labEvents = [
  { type: 'blink', kind: 'bolt', x: 1, y: 1, tx: 5, ty: 5, duration: .5 },
  { type: 'cloak', kind: 'brain', x: 4, y: 4, active: true },
  { type: 'venom', kind: 'fang', x: 9, y: 9, targetId: 'victim', amount: 40 },
  { type: 'multistrike', kind: 'fink', x: 3, y: 3, tx: 8, ty: 8, targetId: 2, amount: 40 },
  { type: 'whirlwind', kind: 'bandito', x: 3, y: 3, radius: 2.4 },
  { type: 'ricochet', kind: 'teratorn', x: 10, y: 8, tx: 12, ty: 8, sourceId: 'flying-teratorn', targetId: 2 },
  { type: 'acid', kind: 'projectx', x: 5, y: 5, radius: 2.4, amount: 100 },
  { type: 'emerge_splash', kind: 'wormzer', x: 6, y: 6, radius: 3, amount: 100 },
  { type: 'buff', kind: 'putty', x: 9, y: 9, targetId: 'victim' },
  { type: 'catapult_launch', kind: 'pebble', x: 1, y: 31, tx: 16, ty: 16, impactAt: 2, radius: 5 },
  { type: 'catapult_impact', kind: 'pebble', x: 16, y: 16, radius: 5, amount: 900 },
  { type: 'catapult_impact', kind: 'putty', x: 9, y: 9, radius: 3, amount: 0 },
  { type: 'shot', kind: 'dave', projectile: 'rocket', volley: 0, x: 5, y: 5, tx: 8, ty: 8, amount: 100 },
  { type: 'shot', kind: 'dave', projectile: 'rocket', volley: 1, x: 5, y: 5, tx: 8, ty: 8, amount: 100 },
].map((event, index) => ({ id: index + 1, t: 1, team: 'attacker', ...event }));
effectsRenderer.setBattle({ id: 92, status: 'active', elapsed: 1, duration: 300, buildings: [building(2, 'townhall', 12, 8)], units: [unit('victim', 'pokey'), unit('flying-teratorn', 'teratorn')], events: labEvents });
check(effectSounds.join(',') === 'blink,venom,explode,catapult,catapult-impact,boost,shot,shot', 'Lab and artillery events dispatch supported, distinct audio cues');
effectsRenderer.fx.forEach(event => { event.start = performance.now() - 200; });
for (const event of effectsRenderer.fx) {
  const before = calls.length;
  if (event.type === 'catapult_launch') continue; // Its persistent pending snapshot owns the flying projectile.
  effectsRenderer.fx = [event]; effectsRenderer.drawEffects();
  check(calls.length > before, `${event.type}${event.projectile ? ` ${event.projectile} ${event.volley}` : ''} draws a concrete combat effect`);
}
effectsRenderer.setBattle({ ...effectsRenderer.battle, events: labEvents });
check(effectSounds.length === 8, 'repeated snapshots do not replay ability or artillery sounds');
const ricochetStart = calls.length;
effectsRenderer.drawAbilityEffect(labEvents[5], effectsRenderer.project(10, 8), effectsRenderer.project(12, 8), .2);
check(calls.slice(ricochetStart).some(call => call[0] === 'moveTo' && call[1] === 40 && call[2] === 162), 'ricochet starts at the previously struck building rather than the original flying attacker');
const rocketStart = calls.length;
effectsRenderer.drawProjectile(labEvents[12], { x: 0, y: 0 }, { x: 100, y: 100 }, .2);
effectsRenderer.drawProjectile(labEvents[13], { x: 0, y: 0 }, { x: 100, y: 100 }, .2);
const translations = calls.slice(rocketStart).filter(call => call[0] === 'translate');
check(translations.length === 2 && translations[0][2] !== translations[1][2], 'DAVE twin rockets use distinct trajectories rather than drawing one over the other');
const plainBlastStart = calls.length;
effectsRenderer.drawExplosion({ id: 41, type: 'explode', kind: 'eyera', radius: 3, labLevel: 3 }, { x: 100, y: 200 }, .3);
check(!calls.slice(plainBlastStart).some(call => call[0] === 'ellipse' && call[2] < 180), 'an ordinary Eye-Ra explosion does not invent an airborne ability from a tier alone');
const airBlastStart = calls.length;
effectsRenderer.drawExplosion({ id: 42, type: 'explode', kind: 'eyera', radius: 3, airburst: true, labLevel: 3 }, { x: 100, y: 200 }, .3);
check(calls.slice(airBlastStart).some(call => call[0] === 'ellipse' && call[2] < 168) && calls.slice(airBlastStart).filter(call => call[0] === 'quadraticCurveTo').length === 2, 'researched Eye-Ra Airburst adds an elevated shock wave and rising trails');
effectsRenderer.reduced = true;
const stillAirStart = calls.length;
effectsRenderer.drawExplosion({ id: 42, type: 'explode', kind: 'eyera', radius: 3, airburst: true }, { x: 100, y: 200 }, .2);
const stillAirOne = calls.slice(stillAirStart).find(call => call[0] === 'ellipse' && call[2] < 168);
const stillAirNext = calls.length;
effectsRenderer.drawExplosion({ id: 42, type: 'explode', kind: 'eyera', radius: 3, airburst: true }, { x: 100, y: 200 }, .7);
check(JSON.stringify(stillAirOne) === JSON.stringify(calls.slice(stillAirNext).find(call => call[0] === 'ellipse' && call[2] < 168)), 'reduced-motion Airburst keeps its elevated marker fixed while it fades');
effectsRenderer.reduced = false;
const overflowEvents = Array.from({ length: 210 }, (_, index) => ({ id: index + 100, t: 2, type: 'cloak', x: 1, y: 1, active: true }));
effectsRenderer.setBattle({ ...effectsRenderer.battle, elapsed: 2, events: overflowEvents });
check(effectsRenderer.fx.length === 160, 'dense Lab combat keeps the visual effect stream bounded');
effectsRenderer.fx.forEach(event => { event.start = performance.now() - 1800; });
effectsRenderer.drawEffects();
check(effectsRenderer.fx.length === 0, 'expired ability effects leave the render queue');

const inputRenderer = makeRenderer(), handlers = new Map();
let clicked = null;
inputRenderer.canvas = { style: {}, addEventListener: (name, fn) => handlers.set(name, fn), setPointerCapture() {}, getBoundingClientRect: () => ({ left: 0, top: 0 }) };
inputRenderer.world = p => p; inputRenderer.callbacks.place = point => { clicked = point; };
inputRenderer.placement = bombPlacement; inputRenderer.bind();
handlers.get('pointerup')({ clientX: 5.1234, clientY: 6.7894 });
check(clicked.x === 5.123 && clicked.y === 6.789, 'Catapult click keeps fine target coordinates instead of snapping to the construction grid');
// v0.5: complete ordinary locomotion, territorial cores and Stronghold fire.
const territoryRenderer = makeRenderer(), territorySprites = [];
territoryRenderer.drawImage = (...args) => { territorySprites.push(args); return true; };
territoryRenderer.battle = { status: 'active', units: [], buildings: [building(30, 'stronghold', 10, 3), building(31, 'outpost', 3, 10)] };
for (const type of ['bolt', 'fang', 'eyera']) {
  territoryRenderer.images[`${type}-walk`] = { width: 1254, height: 1254 };
  check(territoryRenderer.animationFrame(unit(type, type)).key === `${type}-walk`, `${type} uses its own newly drawn walking sheet`);
  check(territoryRenderer.animationFrame({ ...unit(type, type), state: 'attack' }).key === type, `${type} keeps attack presentation distinct from locomotion poses`);
  territoryRenderer.reduced = true;
  check(territoryRenderer.animationFrame(unit(type, type)).frame === 0, `${type} freezes its new poses in reduced motion`);
  territoryRenderer.reduced = false;
  territoryRenderer.drawUnit({ ...unit(type, type), state: 'attack', targetId: 30 });
  check(territorySprites.at(-1)[4].flip === (type !== 'eyera'), `${type} faces a target on its right with its confirmed native orientation`);
  territoryRenderer.drawUnit({ ...unit(type, type), state: 'attack', targetId: 31 });
  check(territorySprites.at(-1)[4].flip === (type === 'eyera'), `${type} faces a target on its left with its confirmed native orientation`);
  delete territoryRenderer.images[`${type}-walk`];
  check(territoryRenderer.animationFrame(unit(type, type)).key === type, `${type} remains visible when its optional sheet cannot load`);
}
territoryRenderer.images['zafreeti-fly'] = { width: 1254, height: 1254 };
check(territoryRenderer.animationFrame({ ...unit('healer', 'zafreeti'), state: 'heal' }).key === 'zafreeti-fly', 'Zafreeti continues gentle levitation while healing');
check(territoryRenderer.animationFrame(unit('healer', 'zafreeti')).frame === 1, 'Zafreeti levitation progresses through its own four poses');
territoryRenderer.reduced = true;
check(territoryRenderer.animationFrame(unit('healer', 'zafreeti')).frame === 1, 'reduced-motion Zafreeti retains a fixed levitating pose');
territoryRenderer.reduced = false;
delete territoryRenderer.images['zafreeti-fly'];
check(territoryRenderer.animationFrame(unit('healer', 'zafreeti')).key === 'zafreeti', 'Zafreeti retains its static-art fallback');
territoryRenderer.drawUnit({ ...unit('healer', 'zafreeti'), state: 'heal', dir: 1 });
check(territorySprites.at(-1)[4].flip === true, 'Zafreeti left-native levitation and static art mirror toward the right');
let territoryBars = 0;
territoryRenderer.drawBar = () => { territoryBars++; };
territoryRenderer.catalog.buildings = { outpost: { size: 3, untargetable: true }, stronghold: { size: 3, damage: 200 }, maproom: { size: 3 } };
check(territoryRenderer.placementLegal(3, 10, { mode: 'deploy', type: 'pokey' }), 'untargetable resource core does not invent a deployment exclusion absent from the battle engine');
check(!territoryRenderer.placementLegal(10, 3, { mode: 'deploy', type: 'pokey' }), 'the targetable Stronghold retains its real deployment exclusion');
const exclusionDiamonds = [];
territoryRenderer.diamond = (...args) => exclusionDiamonds.push(args);
territoryRenderer.world = point => point; territoryRenderer.pointer = { x: 2, y: 2 }; territoryRenderer.placement = { mode: 'deploy', type: 'pokey' };
territoryRenderer.drawGhost();
check(!exclusionDiamonds.some(args => args[0] === 3 && args[1] === 10) && exclusionDiamonds.some(args => args[0] === 10 && args[1] === 3), 'deployment overlay draws the real Stronghold zone without a misleading core zone');
const beforeCore = calls.length;
territoryRenderer.drawBuilding({ ...building(31, 'outpost', 3, 10), hp: 1, max_hp: 1 });
check(territoryBars === 0 && calls.slice(beforeCore).some(call => call[0] === 'fillText' && call[1] === 'CAPTURE THE DEFENSES'), 'untargetable Resource Outpost explains capture instead of displaying a misleading one-HP target bar');
territoryRenderer.drawBuilding(building(30, 'stronghold', 10, 3));
check(territoryBars === 1 && territorySprites.at(-1)[0] === 'stronghold', 'Stronghold remains a normal damageable illustrated defense');
territoryRenderer.drawBuilding(building(32, 'maproom', 8, 8));
check(territorySprites.at(-1)[0] === 'maproom', 'Map Room uses its own generated building art');
const beforeStrongholdShot = calls.length;
territoryRenderer.drawProjectile({ type: 'shot', kind: 'stronghold' }, { x: 10, y: 10 }, { x: 100, y: 100 }, .2);
check(calls.slice(beforeStrongholdShot).filter(call => call[0] === 'lineTo').length === 6, 'Stronghold fire uses a visible electric arc to its actual target');
const stillCore = calls.length;
territoryRenderer.reduced = true;
territoryRenderer.drawBuilding({ ...building(31, 'outpost', 3, 10), hp: 1, max_hp: 1 });
check(calls.slice(stillCore).some(call => call[0] === 'fillText' && call[1] === 'CAPTURE THE DEFENSES'), 'reduced motion preserves the untargetable core instruction');
// v0.6: new attack artwork plays once per confirmed strike, independent of locomotion.
const attackSpecies = ['pokey', 'octo', 'ichi', 'dave', 'gorgo', 'drull', 'fomor'];
check(attackSpecies.every(type => loaded.some(([key, file]) => key === `${type}-attack` && file === `${type}-attack.png`)), 'all seven independent attack sheets are preloaded');
const strikeRenderer = makeRenderer(), strikeSprites = [];
strikeRenderer.drawImage = (...args) => { strikeSprites.push(args); return true; };
for (const type of attackSpecies) strikeRenderer.images[`${type}-attack`] = { width: 1254, height: 1254 };
const strikeTarget = building(81, 'townhall', 10, 3);
const strikeUnit = (type = 'pokey', extra = {}) => ({ ...unit(`striker-${type}`, type), state: 'attack', targetId: 81, champion: ['gorgo', 'drull', 'fomor'].includes(type), level: 6, ...extra });
const strikeEvent = (type = 'pokey', extra = {}) => ({ id: 1, t: 1, type: 'hit', kind: type, sourceId: `striker-${type}`, targetId: 81, x: 6, y: 6, tx: 10, ty: 3, ...extra });
const strikeBattle = (type = 'pokey', extra = {}) => ({ id: `strike-${type}`, status: 'active', elapsed: 1, duration: 300, buildings: [strikeTarget], units: [strikeUnit(type)], events: [strikeEvent(type)], ...extra });
for (const type of attackSpecies) {
  const current = strikeUnit(type);
  strikeRenderer.setBattle(strikeBattle(type));
  check(strikeRenderer.animationFrame(current).key === `${type}-attack` && strikeRenderer.animationFrame(current).frame === 0, `${type} starts its drawn anticipation pose only after a confirmed attack`);
  const effect = strikeRenderer.unitEffects.get(`${current.id}:attack`);
  const frames = [.2, .4, .55].map(age => { effect.start = performance.now() - age * 1000; return strikeRenderer.animationFrame(current).frame; });
  check(frames.join(',') === '1,2,3', `${type} progresses through strike, recoil and recovery without random frame changes`);
  strikeRenderer.drawUnit(current);
  check(strikeSprites.at(-1)[0] === `${type}-attack` && strikeSprites.at(-1)[4].rotate === 0 && strikeSprites.at(-1)[4].squash === 1, `${type} drawn attack uses stable body geometry instead of the old sprite wobble`);
  check(strikeSprites.at(-1)[4].flip === (type === 'dave'), `${type} attack sheet faces the right-side target with its native orientation`);
  strikeRenderer.battle.buildings = [building(81, 'townhall', 3, 10)];
  strikeRenderer.drawUnit(current);
  check(strikeSprites.at(-1)[4].flip === (type !== 'dave'), `${type} attack sheet faces a left-side target correctly`);
  strikeRenderer.reduced = true;
  effect.start = performance.now() - 20; const stillPose = strikeRenderer.animationFrame(current);
  effect.start = performance.now() - 500;
  check(stillPose.frame === 1 && strikeRenderer.animationFrame(current).frame === 1, `${type} reduced-motion attack holds the explicit strike pose`);
  strikeRenderer.reduced = false;
  effect.start = performance.now() - 700;
  check(strikeRenderer.animationFrame(current).key === type, `${type} completed attack never loops merely because the server state remains attack`);
  effect.start = performance.now();
  const sheet = strikeRenderer.images[`${type}-attack`]; delete strikeRenderer.images[`${type}-attack`];
  check(strikeRenderer.animationFrame(current).key === type, `${type} missing attack artwork retains its visible static fallback`);
  strikeRenderer.images[`${type}-attack`] = sheet;
}
strikeRenderer.setBattle(strikeBattle('pokey', { id: 'dedupe-attack' }));
const originalStrike = strikeRenderer.unitEffects.get('striker-pokey:attack'); originalStrike.start = performance.now() - 250;
strikeRenderer.setBattle(strikeBattle('pokey', { id: 'dedupe-attack' }));
check(strikeRenderer.unitEffects.get('striker-pokey:attack') === originalStrike, 'polling the same event does not restart an attack sequence');
strikeRenderer.setBattle(strikeBattle('pokey', { id: 'dedupe-attack', elapsed: 1.4, events: [strikeEvent('pokey', { id: 2, t: 1.4 })] }));
check(strikeRenderer.unitEffects.get('striker-pokey:attack') !== originalStrike, 'a later real strike starts a fresh bounded sequence');
strikeRenderer.setBattle(strikeBattle('dave', { events: [strikeEvent('dave', { type: 'shot', projectile: 'rocket', volley: 0 })] }));
const rocketStrike = strikeRenderer.unitEffects.get('striker-dave:attack'); rocketStrike.start = performance.now() - 200;
strikeRenderer.setBattle(strikeBattle('dave', { elapsed: 1.2, events: [strikeEvent('dave', { id: 2, type: 'shot', projectile: 'rocket', volley: 1 })] }));
check(strikeRenderer.unitEffects.get('striker-dave:attack') === rocketStrike, 'D.A.V.E. twin rockets share one body strike even when their events arrive in separate polls');
strikeRenderer.setBattle(strikeBattle('pokey', { id: 'old-event', elapsed: 3, events: [strikeEvent()] }));
check(strikeRenderer.animationFrame(strikeUnit()).key === 'pokey', 'late battle history does not replay an old body attack');
strikeRenderer.setBattle(strikeBattle('pokey', { id: 'missing-event-time', events: [strikeEvent('pokey', { t: undefined })] }));
check(strikeRenderer.animationFrame(strikeUnit()).key === 'pokey', 'an event without an authoritative battle time cannot invent an attack cycle');
strikeRenderer.setBattle(strikeBattle('octo', { units: [strikeUnit('octo', { team: 'defender' })] }));
check(strikeRenderer.animationFrame(strikeUnit('octo', { team: 'defender' })).key === 'octo-attack', 'bunker defenders use the same confirmed attack animation path');
strikeRenderer.setBattle(strikeBattle('octo', { units: [strikeUnit('octo', { state: 'walk' })], events: [] }));
check(!strikeRenderer.unitEffects.has('striker-octo:attack') && strikeRenderer.animationFrame(strikeUnit('octo')).key === 'octo', 'returning to movement cancels the previous strike instead of replaying it on the next attack state');
strikeRenderer.setBattle(strikeBattle('gorgo', { units: [strikeUnit('gorgo', { hp: 0, state: 'dead' })] }));
const beforeDeadStrike = strikeSprites.length; strikeRenderer.drawUnit(strikeUnit('gorgo', { hp: 0, state: 'dead' }));
check(strikeSprites.length === beforeDeadStrike && !strikeRenderer.unitEffects.has('striker-gorgo:attack'), 'dead champions cannot retain or draw an attack pose');
strikeRenderer.setBattle(strikeBattle('drull', { units: [strikeUnit('drull', { state: 'retreat' })] }));
check(strikeRenderer.animationFrame(strikeUnit('drull', { state: 'retreat' })).key === 'drull', 'retreat cancels an attack even if the saved HP remains positive');
strikeRenderer.setBattle(strikeBattle('pokey', { status: 'finished' }));
check(strikeRenderer.animationFrame(strikeUnit()).key === 'pokey', 'a completed invasion cannot start another body attack');
strikeRenderer.setBattle(strikeBattle('fomor', { units: [strikeUnit('fomor', { movement: 'air' })], events: [strikeEvent('fomor', { type: 'shot' })] }));
strikeRenderer.images['fomor-fly'] = { width: 1254, height: 1254 };
check(strikeRenderer.animationFrame(strikeUnit('fomor', { movement: 'air' })).key === 'fomor-attack', 'confirmed Fomor casting temporarily takes priority over its wing loop');
strikeRenderer.unitEffects.get('striker-fomor:attack').start = performance.now() - 700;
check(strikeRenderer.animationFrame(strikeUnit('fomor', { movement: 'air' })).key === 'fomor-fly', 'airborne Fomor resumes its wing animation after casting');
const manyStrikers = Array.from({ length: 600 }, (_, i) => strikeUnit('pokey', { id: `crowd-${i}` }));
const manyStrikes = manyStrikers.map((u, i) => strikeEvent('pokey', { id: i + 1, sourceId: u.id }));
strikeRenderer.setBattle(strikeBattle('pokey', { id: 'crowded-attack', units: manyStrikers, events: manyStrikes }));
check(strikeRenderer.unitEffects.size <= 512 && strikeRenderer.fx.length <= 160, 'dense combat keeps both sprite timelines and particle effects bounded');
for (const effect of strikeRenderer.unitEffects.values()) effect.start = performance.now() - 2100;
strikeRenderer.setBattle({ ...strikeRenderer.battle, events: [] });
check(strikeRenderer.unitEffects.size === 0, 'expired sprite timelines are removed on the next snapshot');
strikeRenderer.setBattle(strikeBattle()); strikeRenderer.setBattle(null);
check(strikeRenderer.unitEffects.size === 0, 'leaving battle clears every attack timeline');

// Fortification is a separate saved armor tier, never fabricated maximum HP.
const fortRenderer = makeRenderer(), fortSprites = [], fortBars = [];
fortRenderer.drawImage = (...args) => { fortSprites.push(args); return true; };
fortRenderer.drawBar = (...args) => fortBars.push(args);
const fortified = { ...building(91, 'laser', 16, 16), hp: 400, max_hp: 1000, fortification: 2, fortificationReduction: .3 };
fortRenderer.selected = fortified;
const fortStart = calls.length; fortRenderer.drawBuilding(fortified);
check(calls.slice(fortStart).some(c => c[0] === 'fillText' && c[1] === 'F2') && calls.slice(fortStart).some(c => c[0] === 'fillText' && c[1] === 'FORTIFIED · −30% DAMAGE'), 'fortified buildings show an independent tier shield and exact resistance label');
check(fortBars.at(-1)[3] === .4 && fortified.max_hp === 1000, 'armor presentation leaves actual health and maximum health unchanged');
const remaining = Date.now() / 1000 + 90;
const workStart = calls.length; fortRenderer.drawBuilding({ ...fortified, fortify_ready_at: remaining, fortify_to: 3 });
check(calls.slice(workStart).some(c => c[0] === 'fillText' && /^FORTIFYING · 1m /.test(c[1])) && fortSprites.findLast(sprite=>sprite[0]==='laser')[4].alpha === .82, 'home fortification shows a server-clock countdown and inactive building appearance');
check(calls.slice(workStart).some(c => c[0] === 'fillText' && c[1] === 'F2') && !calls.slice(workStart).some(c => c[0] === 'fillText' && c[1] === 'F3'), 'queued fortification never displays the unearned next tier');
let operationalMotion = 0; fortRenderer.drawBuildingMotion = () => { operationalMotion++; };
fortRenderer.drawBuilding({ ...fortified, fortify_ready_at: remaining });
check(operationalMotion === 0, 'fortifying towers suppress operational glow and motion');
fortRenderer.battle = { status: 'active', buildings: [], units: [] };
const frozenStart = calls.length; fortRenderer.drawBuilding({ ...fortified, fortify_ready_at: 1, fortifying: true });
check(calls.slice(frozenStart).some(c => c[0] === 'fillText' && c[1] === 'FORTIFYING · OFFLINE') && operationalMotion === 0, 'combat uses the frozen offline flag after the home fortification timer would expire');
fortRenderer.drawBuilding({ ...fortified, fortify_ready_at: remaining, fortifying: false });
check(operationalMotion === 1, 'combat does not recalculate a server-frozen active tower from a wall clock');
fortRenderer.battle = null; fortRenderer.reduced = true;
const reducedFortStart = calls.length; fortRenderer.drawBuilding(fortified);
check(calls.slice(reducedFortStart).some(c => c[0] === 'fillText' && c[1] === 'F2'), 'reduced motion retains the fortification tier and resistance information');
for (const value of [0, 5, -1, 1.5, NaN]) {
  const absentStart = calls.length; fortRenderer.drawBuilding({ ...fortified, fortification: value });
  check(!calls.slice(absentStart).some(c => c[0] === 'fillText' && /^F[1-4]$/.test(c[1])), `invalid or absent armor tier ${String(value)} cannot invent a fortification badge`);
}
const ruinsStart = calls.length; fortRenderer.drawBuilding({ ...fortified, hp: 0 });
check(!calls.slice(ruinsStart).some(c => c[0] === 'fillText' && c[1] === 'F2'), 'destroyed structures do not show a live fortification badge');
const bonusHome = makeRenderer(); bonusHome.base = { buildings: [building(4, 'championcage', 16, 16)], activeChampion: 'gorgo', champions: { gorgo: { hp: 6500, level: 6, max_hp: 7000, bonus_feeds: 2, stats: { hp: 7000, movement: 'ground' } } } };
check(bonusHome.idleChampion().max_hp === 7000 && bonusHome.idleChampion().hp === 6500, 'home champion uses authoritative bonus health instead of the unbonused catalog maximum');
const bonusLabelStart = calls.length; bonusHome.drawUnit(bonusHome.idleChampion());
check(calls.slice(bonusLabelStart).some(c => c[0] === 'fillText' && c[1] === 'Gorgo · Lv 6 · B2'), 'champion bonus feeding is visible separately from evolution level');

// v0.7: every implemented species has a distinct drawn attack/cast action.
const nextAttackSpecies = ['fink', 'bandito', 'bolt', 'eyera', 'fang', 'wormzer', 'teratorn', 'zafreeti', 'brain', 'crabatron', 'projectx'];
const nextFacingLeft = new Set(['bandito', 'bolt', 'fang', 'zafreeti', 'brain', 'crabatron']);
check(nextAttackSpecies.every(type => loaded.some(([key, file]) => key === `${type}-attack` && file === `${type}-attack.png`)), 'all eleven remaining species preload independent attack/cast sheets');
const nextRenderer = makeRenderer(), nextSprites = [];
nextRenderer.drawImage = (...args) => { nextSprites.push(args); return true; };
for (const type of nextAttackSpecies) nextRenderer.images[`${type}-attack`] = { width: 1254, height: 1254 };
for (const type of nextAttackSpecies.filter(value => !['eyera', 'zafreeti'].includes(value))) {
  const current = strikeUnit(type);
  const action = type === 'teratorn' ? 'shot' : 'hit';
  nextRenderer.setBattle(strikeBattle(type, { events: [strikeEvent(type, { type: action })] }));
  check(nextRenderer.animationFrame(current).key === `${type}-attack`, `${type} uses its own drawn action for a confirmed ${action}`);
  const effect = nextRenderer.unitEffects.get(`${current.id}:attack`);
  const poses = [.01, .2, .4, .55].map(age => { effect.start = performance.now() - age * 1000; return nextRenderer.animationFrame(current).frame; });
  check(poses.join(',') === '0,1,2,3', `${type} action advances once through all four distinct poses`);
  nextRenderer.drawUnit(current);
  check(nextSprites.at(-1)[4].flip === nextFacingLeft.has(type), `${type} action faces its right-side target using the action sheet orientation`);
  nextRenderer.battle.buildings = [building(81, 'townhall', 3, 10)]; nextRenderer.drawUnit(current);
  check(nextSprites.at(-1)[4].flip === !nextFacingLeft.has(type), `${type} action mirrors correctly toward a left-side target`);
  nextRenderer.reduced = true; effect.start = performance.now() - 50;
  check(nextRenderer.animationFrame(current).frame === 1, `${type} reduced motion retains a static strike pose`);
  nextRenderer.reduced = false; effect.start = performance.now() - 700;
  check(nextRenderer.animationFrame(current).key === type, `${type} action ends instead of looping while the server retains attack state`);
  effect.start = performance.now(); const image = nextRenderer.images[`${type}-attack`]; delete nextRenderer.images[`${type}-attack`];
  check(nextRenderer.animationFrame(current).key === type, `${type} stays visible if its optional action image fails`);
  nextRenderer.images[`${type}-attack`] = image;
}

nextRenderer.images['teratorn-fly'] = { width: 1254, height: 1254 };
nextRenderer.setBattle(strikeBattle('teratorn', { events: [strikeEvent('teratorn', { type: 'shot' })] }));
nextRenderer.drawUnit(strikeUnit('teratorn'));
check(nextSprites.at(-1)[4].bounce >= 22 && nextSprites.at(-1)[4].bounce <= 30, 'Teratorn stays at its flying altitude during a drawn fire cast');
nextRenderer.unitEffects.get('striker-teratorn:attack').start = performance.now() - 700;
check(nextRenderer.animationFrame(strikeUnit('teratorn')).key === 'teratorn-fly', 'Teratorn resumes its wing sheet after a confirmed fire cast');
nextRenderer.images['wormzer-emerge'] = { width: 1254, height: 1254 };
nextRenderer.setBattle(strikeBattle('wormzer', { events: [strikeEvent('wormzer', { type: 'emerge' }), strikeEvent('wormzer', { id: 2, type: 'hit' })] }));
check(nextRenderer.animationFrame(strikeUnit('wormzer')).key === 'wormzer-attack', 'Wormzer bite takes priority over an emergence in the same server step');
nextRenderer.setBattle(strikeBattle('wormzer', { elapsed: 1.2, units: [strikeUnit('wormzer', { state: 'burrow' })], events: [] }));
const burrowSprites = nextSprites.length; nextRenderer.drawUnit(strikeUnit('wormzer', { state: 'burrow' }));
check(nextSprites.length === burrowSprites && !nextRenderer.unitEffects.has('striker-wormzer:attack'), 'a newly submerged Wormzer clears its bite and never floats above the burrow trail');

const healer = strikeUnit('zafreeti', { state: 'heal', targetId: 'wounded' });
const wounded = { ...unit('wounded', 'pokey'), hp: 40, x: 10, y: 3 };
const healEvent = strikeEvent('zafreeti', { type: 'heal', amount: 60, targetId: 'wounded' });
const healBattle = extra => strikeBattle('zafreeti', { units: [healer, wounded], events: [healEvent], ...extra });
nextRenderer.images['zafreeti-fly'] = { width: 1254, height: 1254 };
nextRenderer.setBattle(healBattle({ events: [] }));
check(nextRenderer.animationFrame(healer).key === 'zafreeti-fly', 'healing state without an actual heal event does not invent a Zafreeti cast');
nextRenderer.setBattle(healBattle({}));
check(nextRenderer.animationFrame(healer).key === 'zafreeti-attack', 'a positive server heal starts Zafreeti’s drawn casting action');
const healingEffect = nextRenderer.unitEffects.get('striker-zafreeti:attack');
nextRenderer.drawUnit(healer);
check(nextSprites.at(-1)[4].flip === true && nextSprites.at(-1)[4].rotate === 0, 'Zafreeti’s native-left cast faces the allied target with a stable drawn body');
nextRenderer.setBattle(healBattle({}));
check(nextRenderer.unitEffects.get('striker-zafreeti:attack') === healingEffect, 'repeat polling cannot restart a healing cast');
nextRenderer.reduced = true; healingEffect.start = performance.now() - 200;
check(nextRenderer.animationFrame(healer).frame === 1, 'reduced-motion healing retains the explicit cast pose');
nextRenderer.reduced = false; healingEffect.start = performance.now() - 700;
check(nextRenderer.animationFrame(healer).key === 'zafreeti-fly', 'Zafreeti returns to levitation when its finite cast ends');
nextRenderer.setBattle(healBattle({ id: 'zero-heal', events: [{ ...healEvent, amount: 0 }] }));
check(!nextRenderer.unitEffects.has('striker-zafreeti:attack'), 'zero healing cannot grant a new casting animation');
nextRenderer.setBattle(healBattle({ id: 'false-healer-shot', units: [{ ...healer, state: 'attack' }], events: [{ ...healEvent, type: 'shot' }] }));
check(!nextRenderer.unitEffects.has('striker-zafreeti:attack'), 'Zafreeti does not reinterpret offensive events as healing');
nextRenderer.setBattle(healBattle({ id: 'heal-then-walk' }));
nextRenderer.setBattle(healBattle({ id: 'heal-then-walk', events: [], units: [{ ...healer, state: 'walk' }, wounded] }));
check(!nextRenderer.unitEffects.has('striker-zafreeti:attack'), 'following an ally cancels Zafreeti’s old casting timeline');
nextRenderer.setBattle(healBattle({ id: 'healer-fallback' }));
delete nextRenderer.images['zafreeti-attack'];
check(nextRenderer.animationFrame(healer).key === 'zafreeti-fly', 'a missing cast sheet retains Zafreeti’s ordinary levitation');
nextRenderer.images['zafreeti-attack'] = { width: 1254, height: 1254 };

// A terminal burst is a short rendered echo, not a resurrected gameplay unit.
const terminalUnit = type => strikeUnit(type, { hp: 0, state: 'dead' });
const terminalEvent = type => strikeEvent(type, { type: type === 'eyera' ? 'explode' : 'acid', amount: 300, radius: 2.5 });
const terminalBattle = (type, extra = {}) => strikeBattle(type, { units: [terminalUnit(type)], events: [terminalEvent(type)], ...extra });
for (const type of ['eyera', 'projectx']) {
  const snapshot = terminalBattle(type), saved = JSON.stringify(snapshot);
  nextRenderer.setBattle(snapshot);
  const burst = nextRenderer.unitEffects.get(`striker-${type}:burst`);
  check(!!burst && !nextRenderer.unitEffects.has(`striker-${type}:attack`), `${type} confirmed terminal event has its own bounded visual timeline`);
  const poses = [.01, .2, .4, .55].map(age => { burst.start = performance.now() - age * 1000; return nextRenderer.burstFrame(burst).frame; });
  check(poses.join(',') === '0,1,2,3', `${type} terminal action advances through all four drawn poses before removal`);
  burst.start = performance.now() - 700; nextRenderer.drawUnitBurst(burst);
  check(nextSprites.at(-1)[0] === `${type}-attack` && nextSprites.at(-1)[4].alpha > 0 && nextSprites.at(-1)[4].alpha < .5, `${type} final pose fades instead of remaining as a living body`);
  check(JSON.stringify(snapshot) === saved && snapshot.units[0].hp === 0, `${type} terminal drawing cannot alter dead HP or authoritative battle state`);
  nextRenderer.reduced = true; burst.start = performance.now() - 200;
  check(nextRenderer.burstFrame(burst).frame === (type === 'eyera' ? 3 : 2), `${type} reduced motion holds a static burst pose`);
  nextRenderer.reduced = false;
  nextRenderer.setBattle(snapshot);
  check(nextRenderer.unitEffects.get(`striker-${type}:burst`) === burst, `${type} terminal event cannot restart on duplicate polling`);
  delete nextRenderer.images[`${type}-attack`];
  check(nextRenderer.burstFrame(burst) === null && nextRenderer.fx.some(event => event.type === terminalEvent(type).type), `${type} failed terminal image retains the independent authoritative particle effect`);
  nextRenderer.images[`${type}-attack`] = { width: 1254, height: 1254 };
  burst.start = performance.now() - 900;
  check(nextRenderer.burstFrame(burst) === null, `${type} terminal sprite expires without waiting for a network poll`);
  nextRenderer.setBattle({ ...snapshot, events: [] });
  check(!nextRenderer.unitEffects.has(`striker-${type}:burst`), `${type} expired terminal timeline is removed by the next snapshot`);
  nextRenderer.setBattle(terminalBattle(type, { id: `${type}-ordinary-death`, events: [strikeEvent(type, { type: 'death', sourceId: undefined, targetId: `striker-${type}` })] }));
  check(!nextRenderer.unitEffects.has(`striker-${type}:burst`), `${type} ordinary death alone cannot invent an explosion or researched acid power`);
  nextRenderer.setBattle(terminalBattle(type, { id: `${type}-still-alive`, units: [strikeUnit(type)] }));
  check(!nextRenderer.unitEffects.has(`striker-${type}:burst`), `${type} terminal body cannot appear while its source remains alive`);
  nextRenderer.setBattle(terminalBattle(type, { id: `${type}-hidden`, units: [terminalUnit(type)].map(u => ({ ...u, hidden: true })) }));
  check(!nextRenderer.unitEffects.has(`striker-${type}:burst`), `${type} terminal effect cannot reveal a hidden body`);
  nextRenderer.setBattle(terminalBattle(type, { id: `${type}-late`, elapsed: 3 }));
  check(!nextRenderer.unitEffects.has(`striker-${type}:burst`), `${type} old battle history cannot replay a terminal sprite`);
}
nextRenderer.setBattle(terminalBattle('eyera', { id: 'removed-burst-source' }));
nextRenderer.setBattle(terminalBattle('eyera', { id: 'removed-burst-source', units: [], events: [] }));
check(!nextRenderer.unitEffects.has('striker-eyera:burst'), 'removing a terminal source clears its body echo');
nextRenderer.setBattle(terminalBattle('projectx', { id: 'retreat-burst-source', units: [terminalUnit('projectx')].map(u => ({ ...u, state: 'retreat' })) }));
check(!nextRenderer.unitEffects.has('striker-projectx:burst'), 'retreat never plays a final death burst');
nextRenderer.setBattle(strikeBattle('projectx', { id: 'live-to-terminal' }));
nextRenderer.setBattle(terminalBattle('projectx', { id: 'live-to-terminal', events: [terminalEvent('projectx')].map(e => ({ ...e, id: 2 })) }));
check(!nextRenderer.unitEffects.has('striker-projectx:attack') && nextRenderer.unitEffects.has('striker-projectx:burst'), 'Project X death replaces its pending melee action instead of drawing both bodies');
nextRenderer.setBattle(strikeBattle('eyera', { id: 'final-kill', events: [] }));
nextRenderer.setBattle(terminalBattle('eyera', { id: 'final-kill', status: 'finished' }));
check(nextRenderer.unitEffects.has('striker-eyera:burst'), 'a confirmed final-kill explosion can finish its visual sequence as the live battle settles');
nextRenderer.setBattle(terminalBattle('eyera', { id: 'old-finished-report', status: 'finished' }));
check(!nextRenderer.unitEffects.has('striker-eyera:burst'), 'opening an already finished report does not start another terminal body action');
nextRenderer.setBattle(terminalBattle('eyera', { id: 'terminal-coordinate', events: [{ ...terminalEvent('eyera'), x: NaN }] }));
check(!nextRenderer.unitEffects.has('striker-eyera:burst'), 'invalid burst coordinates cannot reach drawing transforms');
nextRenderer.setBattle(strikeBattle('fink', { id: 'future-body-event', events: [strikeEvent('fink', { t: 2 })] }));
check(!nextRenderer.unitEffects.has('striker-fink:attack'), 'future battle timestamps cannot manufacture a body action');
nextRenderer.setBattle(strikeBattle('fink', { id: 'unit-melee', events: [strikeEvent('fink', { type: 'strike', targetId: 'defender' })] }));
check(nextRenderer.animationFrame(strikeUnit('fink')).key === 'fink-attack', 'a source-only melee strike animates without requiring duplicate damage events');

const allDead = Array.from({ length: 600 }, (_, i) => ({ ...terminalUnit('eyera'), id: `blast-${i}` }));
const allBlasts = allDead.map((u, i) => ({ ...terminalEvent('eyera'), id: i + 1, sourceId: u.id }));
nextRenderer.setBattle(terminalBattle('eyera', { id: 'many-terminal-events', units: allDead, events: allBlasts }));
check(nextRenderer.unitEffects.size <= 512 && nextRenderer.fx.length <= 160, 'mass terminal bursts retain the shared body and particle queue limits');
nextRenderer.setBattle(null);
check(nextRenderer.unitEffects.size === 0 && nextRenderer.fx.length === 0, 'returning home clears both casting and terminal-body timelines');

const layered = makeRenderer(), layers = [];
Object.assign(layered, { w: 800, h: 600, dpr: 1, scaleBase: 1, zoom: 1, pan: { x: 0, y: 0 } });
for (const method of ['drawGround', 'drawCatapultTargets', 'drawEffects', 'drawAmbient', 'drawAura']) layered[method] = () => {};
layered.drawBuilding = b => layers.push(`building:${b.id}`);
layered.drawUnit = u => layers.push(`living:${u.id}`);
layered.drawUnitBurst = e => layers.push(`burst:${e.unit.id}`);
layered.images['eyera-attack'] = { width: 1254, height: 1254 };
layered.setBattle(terminalBattle('eyera', { id: 'terminal-depth-order', buildings: [building(1, 'wall', 3, 3), building(2, 'wall', 10, 10)] }));
layered.draw();
check(layers.join(',') === 'building:1,burst:striker-eyera,building:2', 'terminal body participates in isometric depth sorting while the dead unit stays outside the living pass');
layers.length = 0; layered.unitEffects.get('striker-eyera:burst').start = performance.now() - 900; layered.draw();
check(layers.join(',') === 'building:1,building:2', 'terminal body leaves the actual frame loop after its bounded fade without another snapshot');

// Network protection is a server-owned area modifier, separate from F1–F4.
const network = makeRenderer(), networkBars = [], networkSprites = [];
network.drawBar = (...args) => networkBars.push(args);
network.drawImage = (...args) => { networkSprites.push(args); return true; };
network.base.networkDefense = { count: 3, reduction: .23, siteIds: [10, 11, 12] };
network.selected = fortified;
const networkStart = calls.length; network.drawBuilding(fortified);
const networkText = calls.slice(networkStart).filter(c => c[0] === 'fillText').map(c => c[1]);
check(networkText.includes('F2') && networkText.includes('N3') && networkText.includes('NETWORK 3/6 · −23% DAMAGE'), 'selected structures distinguish saved F-tier armor from neighboring network protection');
check(networkText.includes('ARMOR + NETWORK · −46.1% TOTAL'), 'home protection label combines multiplicative armor and network resistance without adding percentages');
check(networkBars.at(-1)[3] === .4 && fortified.hp === 400 && fortified.max_hp === 1000, 'network protection never invents extra current or maximum hit points');
network.selected = null;
let mark = calls.length; network.drawBuilding(fortified);
check(!calls.slice(mark).some(c => c[0] === 'fillText' && /^N[1-6]?$|^NETWORK/.test(c[1])), 'unselected yards do not fill every building with network labels');
network.hover = fortified; mark = calls.length; network.drawBuilding(fortified);
check(calls.slice(mark).some(c => c[0] === 'fillText' && c[1] === 'N3'), 'hovering a protected structure exposes its network shield');
network.reduced = true; mark = calls.length; network.drawBuilding(fortified);
check(calls.slice(mark).some(c => c[0] === 'fillText' && c[1] === 'NETWORK 3/6 · −23% DAMAGE'), 'reduced motion keeps exact network protection readable');
network.reduced = false;
network.battle = { status: 'active', units: [], buildings: [], networkDefense: { count: 1, reduction: .07, siteIds: [15] } };
const combatArmor = { ...fortified, networkReduction: .13, damageReduction: .47 };
mark = calls.length; network.drawBuilding(combatArmor);
const combatNetworkText = calls.slice(mark).filter(c => c[0] === 'fillText').map(c => c[1]);
check(combatNetworkText.includes('NETWORK 1/6 · −13% DAMAGE') && combatNetworkText.includes('ARMOR + NETWORK · −47% TOTAL'), 'combat uses frozen per-building network and combined percentages instead of recalculating server values');
check(network.networkProtection({ ...fortified, networkReduction: 0 }) === null, 'an authoritative zero per-building reducer cannot inherit a larger base bonus');
network.battle = { status: 'active', units: [], buildings: [] };
check(network.networkProtection(fortified) === null, 'a legacy battle without network fields never inherits private home protection');
network.battle = { status: 'active', units: [], buildings: [], networkDefense: { count: 6, reduction: .75 }, target: { kind: 'fortification' } };
check(network.networkProtection({ ...fortified, networkReduction: .75 }) === null, 'Fortification targets cannot display a network bonus on their own surrounding defenses');
network.battle = null;
for (const reduction of [0, -.07, .76, NaN, Infinity, '0.23']) {
  network.base.networkDefense = { count: 3, reduction };
  check(network.networkProtection(fortified) === null, `invalid or inactive network reduction ${String(reduction)} cannot manufacture a shield`);
}
network.base.networkDefense = { count: 6, reduction: .75 };
check(network.networkProtection({ ...fortified, fortification: 4, fortificationReduction: .5 }).total === .875, 'maximum six-neighbor support and F4 display their bounded multiplicative total');
network.base.networkDefense = { count: 3, siteIds: [10, 11, 12] };
check(network.networkProtection(fortified) === null, 'neighbor counts and IDs alone cannot manufacture a missing authoritative reducer');
network.base.networkDefense = { count: 3, reduction: .23 };
mark = calls.length; network.drawBuilding({ ...fortified, hp: 0 });
check(!calls.slice(mark).some(c => c[0] === 'fillText' && /^N[1-6]?$|^NETWORK/.test(c[1])), 'destroyed structures do not display a live network shield');

const core = { ...building(98, 'outpostdefender', 16, 16), hp: 5000, max_hp: 5000 };
network.catalog.buildings.outpostdefender = { size: 3, art: 'outpostdefender.png' };
network.images.outpostdefender = { width: 1254, height: 1254 };
network.bounds.outpostdefender = { x: 233, y: 163, w: 789, h: 873 };
network.selected = core; network.hover = null;
const coreBarCount = networkBars.length;
mark = calls.length; network.drawBuilding(core);
check(networkSprites.at(-1)[0] === 'outpostdefender' && networkBars.length === coreBarCount + 1 && networkBars.at(-1)[3] === 1 && !calls.slice(mark).some(c => c[0] === 'fillText' && c[1] === 'CAPTURE THE DEFENSES'), 'targetable Fortification core uses its own art and real health bar without the Resource Outpost capture label');
check(network.networkProtection(core) === null, 'non-firing Fortification beacon does not receive its own network shield');
const corePoint = network.project(core.x, core.y), coreWidth = 40 * 3 * 1.35;
const lantern = calls.slice(mark).find(c => c[0] === 'arc' && Math.abs(c[3] - coreWidth * .026) < .001);
check(!!lantern && Math.abs(lantern[1] - (corePoint.x + coreWidth * ((628 - 233) / 789 - .5))) < .001 && Math.abs(lantern[2] - (corePoint.y + 8 - coreWidth * (873 - (380 - 163)) / 789)) < .001, 'beacon glow stays anchored to the lantern in the same cropped image geometry');
const liveBeacon = network.drawNetworkBeacon;
let beaconCalls = 0; network.drawNetworkBeacon = (...args) => { beaconCalls++; return liveBeacon.apply(network, args); };
network.drawBuilding({ ...core, hp: 0 });
network.drawBuilding({ ...core, ready_at: Date.now() / 1000 + 60 });
network.drawBuilding({ ...core, fortify_ready_at: Date.now() / 1000 + 60 });
check(beaconCalls === 0, 'dead, building and offline Fortification cores never show an operational beacon');
network.reduced = true; network.now = 1; network.drawBuilding(core); const firstBeaconAlpha = context.globalAlpha;
network.now = 9; network.drawBuilding(core);
check(beaconCalls === 2 && context.globalAlpha === firstBeaconAlpha, 'reduced-motion Fortification keeps a stable beacon without pulsing');
const coreLoader = makeRenderer(), coreLoads = [];
coreLoader.catalog.buildings.outpostdefender = { art: 'outpostdefender.png' };
coreLoader.loadImage = async (...args) => coreLoads.push(args); await coreLoader.load();
check(coreLoads.some(([key, file]) => key === 'outpostdefender' && file === 'outpostdefender.png'), 'new Fortification art loads through the ordinary catalog asset path');

{
// Planning and worker visuals never invent authoritative game changes.
const planning = makeRenderer();
planning.base={owner:{id:1},buildings:[building(1,'townhall',16,16),building(2,'laser',8,8)],army:{}};
planning.setPlanner({lockedIds:[1],invalidIds:[],grid:true,ranges:true,busy:false});
check(!planning.placementLegal(12,12,{mode:'planner',id:1,type:'townhall'}),'planner ghost cannot relocate a work-pinned building');
check(planning.placementLegal(5,5,{mode:'planner',id:2,type:'laser'}),'planner ghost accepts a clear footprint inside the yard');
check(!planning.placementLegal(16,16,{mode:'planner',id:2,type:'laser'}),'planner ghost rejects a real building overlap');
planning.planner.busy=true;
check(!planning.placementLegal(5,5,{mode:'planner',id:2,type:'laser'}),'a submitted layout disables further canvas placement');
planning.planner.busy=false;planning.placement={mode:'planner',id:2,type:'laser'};planning.setPlanner(null);
check(planning.placement===null,'leaving planner mode clears its placement ghost');
const input=makeRenderer(),handlers={},moves=[],selections=[];
input.canvas={style:{},setPointerCapture(){},getBoundingClientRect:()=>({left:0,top:0}),addEventListener:(name,handler)=>handlers[name]=handler};
input.pan={x:0,y:0};input.locate=e=>({x:e.clientX,y:e.clientY});input.world=p=>p;
input.base={owner:{id:1},buildings:[building(1,'laser',8,8)],army:{}};
input.setPlanner({lockedIds:[],invalidIds:[],grid:true,ranges:true,busy:false});
input.callbacks={planMove:(id,pos)=>moves.push({id,pos}),select:b=>selections.push(b),place(){}};input.bind();
handlers.pointerdown({pointerId:1,clientX:8,clientY:8});handlers.pointermove({clientX:18.2,clientY:8});handlers.pointerup({clientX:18.2,clientY:8});
check(moves.length===1&&moves[0].id===1&&moves[0].pos.x===18,'dragging a movable building requests one snapped draft move');
check(input.pan.x===0&&input.pan.y===0,'building dragging does not pan the camera at the same time');
check(input.base.buildings[0].x===8,'renderer drag never directly changes live coordinates');
input.planner.lockedIds=[1];handlers.pointerdown({pointerId:2,clientX:8,clientY:8});handlers.pointermove({clientX:20,clientY:8});handlers.pointerup({clientX:20,clientY:8});
check(moves.length===1&&input.pan.x===12,'pinned work cannot become a draft move through dragging');
input.planner.lockedIds=[];handlers.pointerdown({pointerId:3,clientX:8,clientY:8});handlers.pointerup({clientX:8,clientY:8});
check(selections.at(-1).id===1,'a click without dragging still selects the draft building');
handlers.pointerdown({pointerId:4,clientX:8,clientY:8});handlers.pointermove({clientX:18,clientY:8});handlers.pointercancel();
check(input.placement===null&&moves.length===1,'canceled pointers clear their ghost without committing a move');
input.hit=()=>input.base.buildings[0];const ghostFootprints=[];input.diamond=(...args)=>ghostFootprints.push(args);input.drawImage=()=>true;
handlers.pointerdown({pointerId:5,clientX:10,clientY:5});handlers.pointermove({clientX:20,clientY:5});input.drawGhost();
check(ghostFootprints.at(-1)[0]===18&&ghostFootprints.at(-1)[1]===8,'an off-center artwork grab preserves its offset in the placement ghost');
handlers.pointerup({clientX:20,clientY:5});check(moves.at(-1).pos.x===18&&moves.at(-1).pos.y===8,'releasing a roof grab moves by the drag distance without jumping its footprint to the pointer');

const crew=makeRenderer(),deadline=Math.floor(Date.now()/1000)+300;
crew.catalog.buildings.planner={size:2.6};crew.base={owner:{id:1},buildings:[building(1,'townhall',16,16),building(2,'planner',8,8)],construction:{capacity:2,busy:1,available:1,jobs:[{buildingId:2,type:'planner',kind:'build',readyAt:deadline}]}};
crew.now=1;
let people=crew.workerVisuals();
check(people.length===2&&people.filter(p=>p.job).length===1,'only the purchased workers are drawn, with assignments from actual jobs');
check(people.every(p=>crew.workerClear(p.x,p.y)),'workers start on clear ground outside every footprint');
const route=crew.workerPath({x:8,y:16},{x:24,y:16});
check(route.length>0&&route.every(p=>crew.workerClear(p.x,p.y)),'worker routes find clear ground around the Town Hall');
check(route.every((p,i)=>!i||Math.abs(p.x-route[i-1].x)<=1&&Math.abs(p.y-route[i-1].y)<=1),'worker routes use bounded neighboring steps');
for(let i=0;i<220;i++){crew.now+=.1;people=crew.workerVisuals();}
check(people.some(p=>p.working&&p.job.buildingId===2),'a worker walks to the real job before starting its work pose');
const records=structuredClone(crew.base.buildings);crew.workerVisuals();check(JSON.stringify(records)===JSON.stringify(crew.base.buildings),'decorative workers do not complete jobs or mutate building state');
crew.base.construction.jobs.unshift({buildingId:1,type:'townhall',kind:'upgrade',readyAt:deadline-20});people=crew.workerVisuals();
check(people.find(p=>p.id===0).job.buildingId===2&&people.find(p=>p.id===1).job.buildingId===1,'an earlier new deadline assigns the free worker without switching the existing worker away from its job');
crew.base.construction={capacity:1,busy:8,available:0,jobs:Array.from({length:8},()=>({buildingId:2,type:'planner',kind:'build',readyAt:deadline}))};
check(crew.workerVisuals().length===1,'grandfathered excess jobs do not fabricate additional hired workers');
crew.base.construction.jobs.forEach(job=>job.readyAt=1);check(!crew.workerVisuals()[0].job,'a finished deadline stops the working pose without inventing a new job');
crew.planner={};check(crew.workerVisuals().length===0,'workers are hidden while editing a layout');crew.planner=null;
crew.battle={status:'active',units:[],buildings:[]};check(crew.workerVisuals().length===0,'workers never appear as live combat participants');crew.battle=null;
const privateCrew=crew.base.construction;delete crew.base.construction;check(crew.workerVisuals().length===0,'a rival snapshot without private construction data draws no home workers');crew.base.construction=privateCrew;
crew.base.site={kind:'fortification'};check(crew.workerVisuals().length===0,'territory visits do not borrow the home crew');delete crew.base.site;
const workerImages=[];crew.drawImage=(...args)=>{workerImages.push(args);return true;};crew.now=1.2;
crew.drawWorker({id:0,x:4,y:4,moving:true,working:false,flip:false});
check(workerImages.at(-1)[0]==='worker-walk'&&workerImages.at(-1)[4].frame!==null,'walking workers use their separate four-pose movement sheet');
crew.drawWorker({id:0,x:4,y:4,moving:false,working:true,flip:true});
check(workerImages.at(-1)[0]==='worker-work'&&workerImages.at(-1)[4].flip===true,'actual work uses the right-native hammer sheet with runtime facing');
crew.reduced=true;crew.now=9.8;crew.drawWorker({id:0,x:4,y:4,moving:false,working:true,flip:false});
check(workerImages.at(-1)[4].frame===0&&workerImages.at(-1)[4].bounce===0,'reduced motion freezes worker poses and bounce');
const workerLoader=makeRenderer(),workerLoads=[];workerLoader.loadImage=async(...args)=>workerLoads.push(args);await workerLoader.load();
check(['worker','worker-walk','worker-work'].every(type=>workerLoads.some(([key])=>key===type)),'static worker and both action sheets load independently of monster inventory');

}
{
  const yard = makeRenderer(), sound = [], loaded = [];
  yard.catalog.buildings.aerial = {size:2, damage:100, range:7.5, ranges:[7.5,8,8.5,9,9.5]};
  yard.catalog.buildings.heavytrap = {size:1.3, damage:5000, range:.7};
  yard.callbacks.sound = key => sound.push(key);
  yard.loadImage = async (key, file) => loaded.push([key, file]);
  await yard.load();
  check(['aerial','heavytrap'].every(key => loaded.some(([id,file]) => id === key && file === key + '.png')), 'both new defense illustrations load through the normal catalog');
  const tower = {...building(4, 'aerial', 10, 10), level:5};
  const plate = building(5, 'heavytrap', 16, 16);
  yard.base.buildings = [tower, plate];
  yard.planner = {lockedIds:[], invalidIds:[], ranges:true};
  calls.length = 0;
  yard.drawPlannerGuides();
  check(calls.some(c => c[0] === 'ellipse' && Math.abs(c[3] - 9.5 * 28.284) < .001), 'Planner shows the earned tower range at level five');
  check(calls.some(c => c[0] === 'ellipse' && Math.abs(c[3] - .7 * 28.284) < .001), 'Planner marks the Heavy Trap trigger radius');
  yard.planner = null;
  const raid = {id:72, buildings:[tower], units:[unit('air','teratorn')], status:'active', elapsed:2, duration:300,
    events:[{id:1,t:1.5,type:'shot',kind:'aerial',x:10,y:10,tx:6,ty:6,sourceId:4,targetId:'air'},
      {id:2,t:1.75,type:'shot',kind:'aerial',x:10,y:10,tx:6,ty:6,sourceId:4,targetId:'air'}]};
  yard.setBattle(raid);
  check(!yard.hit({x:16,y:16}) && yard.placementLegal(16,16,deployment),'private heavy trap creates no visible marker or deployment exclusion when omitted by server');
  yard.battle.buildings.push({...plate,hp:0});
  check(yard.placementLegal(16,16,deployment), 'spent heavy trap creates no deployment exclusion');
  check(yard.fx[1].start - yard.fx[0].start >= 249, 'rounds arriving in the same poll keep their volley spacing');
  calls.length = 0;
  yard.fx[0].start = performance.now() - 110;
  yard.fx[1].start = performance.now() + 700;
  yard.drawEffects();
  check(calls.some(c=>c[0]==='quadraticCurveTo') && calls.some(c=>c[0]==='rotate'), 'confirmed tower round draws a travelling arc and oriented bolt');
  check(sound.join(',') === 'aerial','sound begins with the visible round, once per shot');
  yard.drawEffects();
  check(sound.length === 1, 'redrawing an in-flight round cannot replay its sound');
  yard.fx[0].start = performance.now() - 390;
  calls.length = 0;yard.drawEffects();
  check(calls.filter(c=>c[0]==='stroke').length >= 7 && !calls.some(c=>c[0]==='rotate'), 'bolt opens into a visible airburst on arrival');
  yard.reduced = true;yard.fx[0].start = performance.now() - 10;
  calls.length = 0;yard.drawEffects();
  check(!calls.some(c=>c[0]==='rotate'), 'reduced motion uses the stationary aerial impact instead of a sweeping missile');
  raid.events = [{id:3,t:2,type:'trap',kind:'heavytrap',x:16,y:16,radius:3}];
  yard.setBattle(raid);yard.setBattle(raid);
  check(sound.filter(k=>k==='heavytrap').length === 1, 'spent plate emits its own impact sound once across repeated snapshots');
  yard.fx = yard.fx.filter(e=>e.kind==='heavytrap');
  calls.length = 0;yard.drawEffects();
  check(calls.filter(c=>c[0]==='lineTo').length===18 && calls.some(c=>c[0]==='ellipse'), 'heavy plate burst draws six ground fissures and a shock ring');
  yard.setBattle(null);
  check(yard.fx.length === 0,'leaving combat discards all delayed rounds and trap effects');
}
{
  const yard = makeRenderer(), pump = building(6,'baiter',11,12);
  const sheet = {width:1254, height:1254}, still = {width:1254,height:1254};
  yard.images = {'baiter-work':sheet,baiter:still};
  yard.catalog.buildings.baiter = {size:2.4};
  const sprites = () => calls.filter(([name]) => name === 'drawImage');
  const renderPump = (props = pump) => { calls.length=0; yard.drawBuilding(props); return sprites(); };
  const loaded = [];
  yard.loadImage = async (...entry) => loaded.push(entry);
  await yard.load();
  check(loaded.some(([id,file,meta])=>id==='baiter-work' && file==='baiter-work.png' && meta.count===4 && meta.columns===2 && meta.rows===2), 'Baiter pump requests its four illustrated phases');
  const homeImage = renderPump();
  check(homeImage.length===1 && homeImage[0][1]===sheet && homeImage[0][3]===0,'a ready home pump flexes its actuator on the first row of the sheet');
  yard.battle = {id:-48,training:true,status:'active',countdown:3,entry:{x:.5,y:15.5}};
  let geometry;
  for(let f=0;f<4;f++){
    yard.now=(f+.25)/3.4;
    const [draw]=renderPump();
    check(draw[1]===sheet && draw[2]===(f%2)*627 && draw[3]===Math.floor(f/2)*627,'active Baiter selects illustrated phase '+f);
    const destination=draw.slice(-4);
    if(geometry)check(destination.every((n,i)=>n===geometry[i]),'musk plume never changes the pump anchor or size');
    geometry=destination;
  }
  yard.drawTrainingApproach();
  const pt=yard.project(.5,15.5);
  check(calls.some(([name,x,y])=>name==='translate'&&x===pt.x&&y===pt.y),'wave countdown marks the actual server-selected opening');
  yard.reduced=true;const [reduced]=renderPump();
  check(reduced[1]===still,'reduced motion displays the full idle illustration');
  calls.length=0;yard.drawTrainingApproach();const fixed=JSON.stringify(calls);
  yard.now+=.37;calls.length=0;yard.drawTrainingApproach();
  check(fixed===JSON.stringify(calls),'reduced motion keeps the entry indicator steady');
  yard.reduced=false;
  for(const patch of [{countdown:0},{training:false},{status:'finished'},{entry:null}]){
    const original=yard.battle; yard.battle={...original,...patch};
    calls.length=0;yard.drawTrainingApproach();
    check(calls.length===0,'approach marker clears for '+JSON.stringify(patch));
    yard.battle=original;
  }
  for(const changed of [{hp:0}, {ready_at:Date.now()/1000+120, level:0}]){
    check(renderPump({...pump,...changed}).every(draw=>draw[1]!==sheet),'destroyed or unfinished Baiter never sprays');
  }
  yard.catalog.speed = 2;
  yard.catalog.buildings.baiter.levels = {3:{buildTime:240}};
  let filled = null;
  yard.drawBar = (x,y,width,ratio)=>{filled=ratio;};
  renderPump({...pump,level:2,upgrade_to:3,ready_at:Date.now()/1000+60});
  check(Math.abs(filled-.5)<.01,'Baiter upgrade bar uses the paid level timer and configured game speed');
  delete yard.images['baiter-work'];
  check(renderPump()[0][1]===still,'pending sheet falls back to the building artwork');
}
{
  const renderer = makeRenderer();
  const pack = [unit('w1','pokey'), unit('w2','teratorn'), {...unit('w3','pokey'),hp:0},unit('guard','fink','defender')];
  const active = {id:-802,training:true,status:'active',countdown:0,elapsed:5,duration:300,
    units:pack,events:[],buildings:[],entry:{x:.5,y:6}};
  const dismissed = {...active,status:'finished',result:{outcome:'dismissed'}};
  renderer.setBattle(active); renderer.setBattle(dismissed);
  const anim = renderer.sirenRetreat;
  check(anim?.origins.size===2 && renderer.battle.status==='finished','siren gives the result immediately and captures only previously visible wild survivors');
  renderer.setBattle(dismissed);
  check(renderer.sirenRetreat === anim,'a second dismissed snapshot cannot restart retreat');
  anim.start = performance.now() - 700;
  const retreat = renderer.retreatPosition(pack[0]);
  check(retreat.x<pack[0].x && retreat.y===pack[0].y && retreat.retreatAlpha>0 && retreat.retreatAlpha<1,'survivors move toward the chosen opening while fading');
  check(JSON.stringify(dismissed.units)===JSON.stringify(pack) && renderer.retreatPosition(pack[3])===null && renderer.retreatPosition(pack[2])===null,'retreat never moves the garrison or revives a casualty');
  const poses=[]; renderer.images['pokey-walk']={};renderer.drawImage=(...args)=>{poses.push(args);return true;};
  renderer.drawRetreatingUnit({...pack[0],...retreat});
  check(poses[0][0]==='pokey-walk' && poses[0][4].alpha===retreat.retreatAlpha,'retreat plays the walking illustration at its current opacity');
  renderer.drawRetreatingUnit({...pack[1],...renderer.retreatPosition(pack[1])});
  check(poses.at(-1)[4].bounce>=24,'retreating flyers keep their altitude');
  anim.start=performance.now()-1300;
  check(renderer.retreatPosition(pack[0])===null && !renderer.sirenRetreat,'the pack disappears after its 1.2 second retreat');
  renderer.setBattle(null); renderer.setBattle(dismissed);
  check(!renderer.sirenRetreat,'opening an already finished drill cannot replay its siren retreat');
  renderer.reduced=true;renderer.setBattle(active);renderer.setBattle(dismissed);
  check(!renderer.sirenRetreat,'reduced motion clears the pack without displacement');
  renderer.reduced=false; renderer.setBattle({...active,countdown:1,units:[]});renderer.setBattle({...dismissed,units:[]});
  check(!renderer.sirenRetreat,'siren before launch cannot fabricate survivors');
  renderer.setBattle(active);renderer.setBattle({...dismissed,training:false});
  check(!renderer.sirenRetreat,'live PvP snapshots never enter practice retreat');
}
const flingerView = makeRenderer();
flingerView.battle = { status:'active', elapsed:0, buildings:[building(1,'wall',16,16)], remaining:{pokey:200,teratorn:100},
  flinger:{weights:{pokey:7,teratorn:70}, pixelsPerTile:80, capacity:3500, readyAt:0} };
flingerView.placement = { mode:'deploy', troops:{pokey:10,teratorn:1} };
check(flingerView.flingerZone(flingerView.placement) === 1.25, 'Light bucket keeps the minimum scatter ring');
check(flingerView.placementPoint({x:3.1234,y:5.6788}).x === 3.123, 'New launch reticle preserves the pointer instead of snapping it to a half tile');
check(flingerView.placementLegal(19,16), 'Light loads fit in a narrow opening');
flingerView.placement = { mode:'deploy', troops:{teratorn:50} };
check(flingerView.flingerZone(flingerView.placement) === 5.46875 && !flingerView.placementLegal(19,16), 'Full loads widen the ring and reject overlap');
flingerView.battle.buildings[0].hp = 0;
check(flingerView.placementLegal(16,16), 'Building ruins no longer block a new invasion drop zone');
flingerView.battle.flinger.capacity = 250;
flingerView.placement = { mode:'deploy', troops:{pokey:35} };
check(flingerView.placementLegal(16,16), '35 Pokeys fit a level-one Flinger at the source weight of seven');
flingerView.placement.troops.pokey = 36;
check(!flingerView.placementLegal(16,16), '36 Pokeys exceed level-one Flinger space');
flingerView.battle.flinger.capacity = 3500;
flingerView.battle.units = Array.from({length:190}, () => ({team:'attacker',hp:0}));
flingerView.placement.troops.pokey = 11;
check(!flingerView.placementLegal(16,16), 'Late launches count spent troops toward the invasion limit');
flingerView.battle.units[0] = {team:'attacker',champion:true};
flingerView.battle.units[1] = {team:'defender'};
check(flingerView.placementLegal(16,16), 'Champions and defenders do not occupy ordinary deployment slots');
flingerView.battle.flinger = {capacity:100,readyAt:0};
check(flingerView.flingerZone(flingerView.placement) === null && flingerView.placementPoint({x:3.123,y:5.679}).x === 3, 'A resumed old invasion keeps its old aim controls');
const siegeView = makeRenderer();
siegeView.battle={status:'active',duration:300,elapsed:8,units:[],buildings:[building(1,'laser',12,16)],siege:{type:'decoy',radius:3.125,used:false,active:null}};
const decoyAim={mode:'siege',type:'decoy',radius:3.125};
check(!siegeView.placementLegal(12,16,decoyAim) && siegeView.placementLegal(13.4,16,decoyAim), 'Decoy preview avoids a living tower footprint and its clearance margin.');
siegeView.battle.siege.type='jars';
const jarAim={...decoyAim,type:'jars'};
check(siegeView.placementLegal(15,16,jarAim) && !siegeView.placementLegal(21,16,jarAim),'Jar clear and out-of-range previews follow the saved range.');
siegeView.battle.siege.used=true;
check(!siegeView.placementLegal(15,16,jarAim), 'A spent siege device cannot be aimed again.');
const drawn=[];
siegeView.drawImage=(name,...args)=>{drawn.push(name);return true;};
siegeView.project=(x,y)=>({x,y});siegeView.drawBar=()=>{};
for (const type of ['decoy','vacuum']) siegeView.drawSiegeDevice({type,active:{x:2,y:3,startedAt:0,hp:17,maxHp:20}});
siegeView.drawSiegeJar({jarHp:20,jarMaxHp:54},{x:2,y:3},42);
check(drawn.join(' ')==='siege-decoy siege-vacuum siege-jars','Each active device draws its own artwork.');
const beforeJar=drawn.length;siegeView.drawSiegeJar({jarHp:0,jarMaxHp:54},{x:2,y:3},42);
check(drawn.length===beforeJar,'A broken jar clears its defense immediately.');
for(const type of ['siege_launch','siege_blast','siege_break','siege_end']) siegeView.drawSiegePulse({type,kind:'jars'},{x:4,y:8},.2);
const ruins = makeRenderer(), drawnRuins = [];
ruins.catalog.buildings.hatchery = {size:2}; ruins.catalog.buildings.cannon = {size:2};
ruins.drawImage = (...args) => { drawnRuins.push(args); return true; };
const scorch = { ...building(71,'townhall',3,4), hp:0 };
for(const [type,key] of [['townhall','rubble-stone'],['hatchery','rubble-timber'],['cannon','rubble-machinery']]) {
  drawnRuins.length=0;ruins.drawBuilding({...scorch,type});
  check(drawnRuins.length===1&&drawnRuins[0][0]===key,type+' ruin replaces the intact facade');
}
drawnRuins.length=0; ruins.drawBuilding({...scorch,type:'trap',hidden:true});
check(drawnRuins.length===0,'A hidden trap never paints debris or a repair sparkle');
ruins.drawBuilding({...scorch,type:'trap'});
check(drawnRuins.at(-1)[0]==='rubble-machinery','A revealed spent trap has a visible broken casing');
drawnRuins.length=0;ruins.drawBuilding({...scorch,type:'hatchery',level:0,ready_at:Date.now()/1000+60});
check(drawnRuins.some(s=>s[0]==='hatchery')&&!drawnRuins.some(s=>s[0].startsWith('rubble')),'A fresh construction site keeps its job appearance');
check([100,80,60,36,8].map(hp=>ruins.wearLevel({...scorch,hp,max_hp:100})).join(',')==='0,1,2,3,4','Real HP selects four wear tiers');
const repairedBuilding = {...scorch,hp:100,max_hp:100};
ruins.setBase({owner:{id:2},repair:{},buildings:[repairedBuilding,{...scorch,id:72}]});
check(!ruins.restoration?.size,'Loading a damaged or healthy home starts no repair highlights');
ruins.showRestored(3,[71]);
check(!ruins.restoration?.size,'A delayed repair from another owner is ignored');
ruins.reduced=true;ruins.showRestored(2,[71]);
check(!ruins.restoration?.size,'Reduced motion keeps stable repaired artwork');
ruins.reduced=false;ruins.showRestored(2,[71,72,90]);
check(ruins.restoration.size===1&&ruins.restoration.has(71),'Only returned, visibly healthy building IDs get a repair sweep');
ruins.restoration.get(71).until=performance.now()-1;
ruins.drawBuilding(repairedBuilding);
check(!ruins.restoration.size,'Repair glow expires while full-health art persists');
ruins.showRestored(2,[71]);ruins.setBase({owner:{id:2},buildings:[repairedBuilding]});
check(!ruins.restoration.size,'Visiting a territory with the same owner clears private repair effects');
const replayAudio=[]; ruins.callbacks.sound=s=>replayAudio.push(s);
const destroyedEvent={id:1,t:0,type:'destroy',x:1,y:1};
const damageBattle={id:931,elapsed:0,status:'active',units:[],buildings:[],events:[destroyedEvent],serverTime:Date.now()/1000};
ruins.setBattle(damageBattle);
check(!replayAudio.includes('hit'),'Restoring a battle snapshot never plays historical collapse audio');
ruins.setBattle({...damageBattle,events:[destroyedEvent,{...destroyedEvent,id:2}]});
check(replayAudio.filter(s=>s==='hit').length===1,'A new authoritative destruction during the same battle sounds once');
console.log(`Renderer: ${checks} checks passed.`);
