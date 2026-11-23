import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
const source = await readFile(new URL('../public/assets/worldmap.js', import.meta.url), 'utf8');
const { createWorldMap, hexPoint, hexAt, hexDistance, siteStatus, networkProtection } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; console.log(`PASS ${message}`); };
const drawing = [], events = new Map(), frames = new Map(), timers = new Map(), intervals = new Map();
let serial = 0, focused = null, disconnected = false;
const context = new Proxy({}, {
  get(target, key) {
    if (key === 'measureText') return text => ({ width: String(text).length * 6 });
    if (key in target) return target[key];
    return (...args) => { args.filter(v => typeof v === 'number').forEach(v => assert.ok(Number.isFinite(v), `Nonfinite ${key} coordinate`)); drawing.push([key, ...args]); };
  }, set(target, key, value) { target[key] = value; return true; },
});
class Element {
  constructor(id) { this.id = id; this.innerHTML = ''; this.textContent = ''; this.style = {}; this.dataset = {}; this.attrs = {}; this.child = new Map(); }
  addEventListener(name, fn) { events.set(`${this.id}:${name}`, fn); }
  removeEventListener(name) { events.delete(`${this.id}:${name}`); }
  setAttribute(name, value) { this.attrs[name] = value; }
  getContext() { return context; }
  getBoundingClientRect() { return { left: 0, top: 0, width: 800, height: 600 }; }
  setPointerCapture() {} releasePointerCapture() {}
  contains(el) { return el?.owner === this; }
  focus() { focused = this; globalThis.document.activeElement = this; }
  querySelector(selector) {
    if (!this.child.has(selector)) { const child = new Element(selector); child.owner = this; this.child.set(selector, child); }
    return this.child.get(selector);
  }
}
const nodes = Object.fromEntries(['canvas', 'info', 'holdings', 'status', 'controls', 'sites'].map(name => [`world-map-${name}`, new Element(`world-map-${name}`)]));
const node = name => nodes[`world-map-${name}`];
globalThis.document = { getElementById: id => nodes[id], activeElement: null };
globalThis.devicePixelRatio = 3;
globalThis.ResizeObserver = class { constructor(callback) { this.callback = callback; } observe() {} disconnect() { disconnected = true; } };
globalThis.Image = class { set src(value) { this.url = value; this.onload?.(); } };
globalThis.requestAnimationFrame = callback => { const id = ++serial; frames.set(id, callback); return id; };
globalThis.cancelAnimationFrame = id => frames.delete(id);
globalThis.setTimeout = callback => { const id = ++serial; timers.set(id, callback); return id; };
globalThis.clearTimeout = id => timers.delete(id);
globalThis.setInterval = callback => { const id = ++serial; intervals.set(id, callback); return id; };
globalThis.clearInterval = id => intervals.delete(id);
const flush = () => { const pending = [...frames.values()]; frames.clear(); pending.forEach(callback => callback()); };
const emit = (name, type, event = {}) => events.get(`world-map-${name}:${type}`)?.(event);
const button = dataset => ({ dataset, closest() { return this; } });
const click = (name, dataset) => emit(name, 'click', { target: button(dataset) });
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const state = { user: { id: 1001 } }, requests = [], visits = [], errors = [];
const owner = { id: 1001, name: 'Keeper', is_bot: false };
const home = { id: 1, q: 10, r: 10, name: 'My backyard', kind: 'home', homeUserId: 1001, owner, owned: true, reachable: true, level: 3, shield_until: 0, ready_at: 0 };
const resource = { id: 2, q: 11, r: 10, name: '<script>danger</script>', kind: 'resource', owner: null, owned: false, reachable: true, level: 1, shield_until: 0, ready_at: 0 };
const stronghold = { id: 3, q: 10, r: 11, name: 'North Crown', kind: 'stronghold', owner: { id: 44, name: 'Bot <b>name</b>', is_bot: true }, owned: false, reachable: false, level: 2, shield_until: 2000, ready_at: 0 };
const holding = { id: 4, q: 12, r: 12, name: 'Our outpost', kind: 'resource', owner, owned: true, reachable: true, level: 3, range: 4, shield_until: 0, ready_at: 0 };
const snapshot = extra => ({ width: 64, height: 64, layout: 'odd-r', center: { q: 10, r: 10 }, home: { id: 1, q: 10, r: 10 }, mapRoomLevel: 2, holdingLimit: 4, holdings: [holding], bonuses: { resourcePerSecond: .2, capacity: 8000 }, sites: [home, resource, stronghold], serverTime: 1000, ...extra });
let response = async () => snapshot();
const map = createWorldMap({ state, api: async (...args) => { requests.push(args); return response(...args); }, onVisitHome: id => visits.push(['home', id]), onVisitSite: id => visits.push(['site', id]), toast: (...args) => errors.push(args) });

check([...Array(64).keys()].every(r => [...Array(64).keys()].every(q => { const point = hexPoint(q, r), cell = hexAt(point.x, point.y); return cell.q === q && cell.r === r; })), 'all 4096 odd-r cell centers round-trip through screen geometry');
for (const r of [10, 11]) {
  const center = { q: 10, r }, neighbors = r % 2 ? [[11, r], [9, r], [11, r - 1], [10, r - 1], [11, r + 1], [10, r + 1]] : [[11, r], [9, r], [10, r - 1], [9, r - 1], [10, r + 1], [9, r + 1]];
  check(neighbors.every(([q, r]) => hexDistance(center, { q, r }) === 1 && hexDistance({ q, r }, center) === 1), `row ${r} has the original six hex neighbors, symmetrically`);
}
check(hexDistance({ q: 0, r: 0 }, { q: 63, r: 63 }) === 95, 'the finite map does not wrap its edges like MR2');
check(siteStatus(resource, 1000) === 'Within reach' && siteStatus({ ...resource, reachable: false }, 1000) === 'Beyond reach', 'target reach labels use only the authoritative summary flag');
check(siteStatus(stronghold, 1000) === 'Protected' && siteStatus({ ...stronghold, busy: true }, 1000) === 'Battle in progress', 'shield and battle status take precedence over geographic reach');
check(siteStatus({ ...holding, ready_at: 1200 }, 1000) === 'Rebuilding / upgrading', 'site timers are compared with server time rather than the browser clock');
check(requests.length === 0, 'creating a hidden map does not make background requests');
await map.open(); flush();
check(requests[0][0] === 'worldMap' && requests[0][1] === undefined && !('q' in requests[0][2]), 'first view is a GET centered by the server on the real home');
check(requests[0][2].radius >= 1 && requests[0][2].radius <= 12, 'the map uses a bounded viewport request');
check(map.snapshot.sites.length === 3 && node('sites').innerHTML.match(/data-world-select=/g).length === 3, 'only returned real sites appear in the accessible directory');
check(node('canvas').width === 1600 && node('canvas').height === 1200, 'retina rendering caps backing resolution at twice CSS pixels');
check(drawing.some(call => call[0] === 'drawImage') && drawing.some(call => call[0] === 'fillText' && call[1] === '⌂'), 'actual territorial art and distinct home markers draw with finite coordinates');
check(node('sites').innerHTML.includes('&lt;script&gt;danger&lt;/script&gt;') && !node('sites').innerHTML.includes('<script>'), 'server-provided site names are escaped in the accessible directory');
check(node('holdings').innerHTML.includes('1 / 4 holdings') && node('holdings').innerHTML.includes('8,000 storage'), 'holdings and economic benefits come from the current server snapshot');
check(intervals.size === 1 && node('status').attrs['aria-live'] === 'polite', 'visible maps refresh without interrupting screen-reader interaction');

check(map.selectSite(2) && visits.length === 0, 'selecting a site shows details without unexpectedly leaving the map');
check(node('info').innerHTML.includes('&lt;script&gt;') && node('info').innerHTML.includes('90%'), 'selection details escape names and explain HP-weighted capture');
check(!node('info').innerHTML.includes('data-world-attack'), 'geographic reach never invents an allowed invasion button');
click('info', { worldVisit: '2' });
check(visits.at(-1)?.join(',') === 'site,2', 'explicit Scout dispatches the real site ID to the root visit flow');
map.selectSite(1); click('info', { worldVisit: '1' });
check(visits.at(-1)?.join(',') === 'home,1001', 'home visits dispatch the real user ID rather than a world-site ID');
map.selectSite(3);
check(node('info').innerHTML.includes('Bot &lt;b&gt;name&lt;/b&gt;') && node('info').innerHTML.includes('Protected'), 'rival ownership and protection are visible without exposing a private army');
check(!map.selectSite(999999), 'unknown site IDs cannot select invented targets');
emit('canvas', 'pointerdown', { button: 0, pointerId: 7, clientX: 453.7, clientY: 300 });
emit('canvas', 'pointerup', { pointerId: 7, clientX: 453.7, clientY: 300 });
check(node('info').innerHTML.includes('Scout this site') && node('info').innerHTML.includes('&lt;script&gt;'), 'canvas hit testing selects the correct odd-r cell');
const beforeEnter = visits.length;
emit('canvas', 'keydown', { key: 'Enter', preventDefault() {} });
check(visits.length === beforeEnter + 1 && visits.at(-1)[1] === 2, 'keyboard Enter opens the selected real site');
emit('canvas', 'keydown', { key: 'Escape', preventDefault() {} });
check(node('info').innerHTML.includes('A bigger backyard.'), 'Escape clears selection without changing the saved world');
const beforeDrag = visits.length;
emit('canvas', 'pointerdown', { button: 0, pointerId: 8, clientX: 400, clientY: 300 });
emit('canvas', 'pointermove', { pointerId: 8, clientX: 500, clientY: 340 });
emit('canvas', 'pointerup', { pointerId: 8, clientX: 500, clientY: 340 });
check(visits.length === beforeDrag && timers.size === 1, 'dragging pans and schedules a viewport read without triggering a visit');
emit('controls', 'change', { target: { value: 'stronghold', matches: selector => selector === '[data-world-filter]' } });
check(node('sites').innerHTML.includes('North Crown') && !node('sites').innerHTML.includes('danger'), 'filters apply consistently to the accessible site list');
emit('controls', 'change', { target: { value: 'all', matches: selector => selector === '[data-world-filter]' } });

await map.panTo(-500, 800);
check(requests.at(-1)[2].q === 0 && requests.at(-1)[2].r === 63, 'coordinate navigation clamps to actual world bounds');
await map.centerHome();
check(requests.at(-1)[2].q === 10 && requests.at(-1)[2].r === 10, 'Center home uses the persisted home position');
const deferredOld = deferred(), deferredNew = deferred(); let race = 0;
response = () => ++race === 1 ? deferredOld.promise : deferredNew.promise;
const oldRequest = map.refresh(), newRequest = map.refresh();
deferredNew.resolve(snapshot({ sites: [{ ...resource, name: 'New ownership', owned: true, owner }] })); await newRequest;
deferredOld.resolve(snapshot({ sites: [{ ...resource, name: 'Outdated ownership' }] })); await oldRequest;
check(node('sites').innerHTML.includes('New ownership') && !node('sites').innerHTML.includes('Outdated ownership'), 'a slower old request cannot overwrite current ownership');
response = async () => { throw new Error('Connection unavailable'); };
await map.refresh(); flush();
check(map.snapshot.sites[0].name === 'New ownership' && node('status').textContent.includes('Showing the last map.'), 'a refresh failure preserves the previous real map and explicitly marks it stale');
check(drawing.some(call => call[0] === 'fillText' && call[1] === 'Last known map'), 'stale canvas data is visibly labelled, not presented as current');
response = async () => snapshot({ sites: [resource, { ...resource, id: 900, q: 900 }, { ...resource, id: 'bad' }] });
await map.refresh();
check(map.snapshot.sites.length === 1, 'invalid coordinates and nonnumeric site IDs never enter map hit-testing');
map.selectSite(2);
response = async () => snapshot({ sites: [] }); await map.refresh();
check(node('info').innerHTML.includes('A bigger backyard.'), 'a vanished selected target does not retain stale actionable details');
const secret = 'DO_NOT_DISPLAY_PRIVATE_GARRISON';
response = async () => snapshot({ sites: [{ ...resource, garrison: { secret }, resources: { secret } }] }); await map.refresh();
check(!Object.values(nodes).some(element => element.innerHTML.includes(secret)), 'unknown private fields are not spread into public map markup');

const fortCell={id:80,q:11,r:11,name:'Green Ward',kind:'fortification',owner,owned:true,reachable:true,level:2,range:0,shield_until:0,ready_at:0};
const protectedPost={...holding,networkDefense:{count:2,reduction:.13,siteIds:[80,99999]}};
response=async()=>snapshot({holdingLimit:4,fortificationLimit:12,holdings:[protectedPost,fortCell],fortifications:[fortCell,holding],sites:[home,resource,stronghold,protectedPost,fortCell]});
await map.refresh();
check(map.snapshot.holdings.length===1&&map.snapshot.fortifications.length===1, 'ordinary holdings and Fortification ownership cannot contaminate each other’s lists');
check(node('holdings').innerHTML.includes('1 / 4 holdings')&&node('holdings').innerHTML.includes('1 / 12 Fortifications')&&node('holdings').innerHTML.includes('do not extend reach'), 'separate ownership caps and the lack of Fortification reach are explicit');
map.selectSite(80);flush();
check(node('info').innerHTML.includes('outpostdefender.png')&&node('info').innerHTML.includes('Adds no income or reach'), 'Fortification cells use their own core art and describe their actual benefit');
click('info',{worldVisit:'80'});check(visits.at(-1)?.join(',')==='site,80', 'Fortification scouting dispatches its real site identity through the territory route');
map.selectSite(4);flush();
check(node('info').innerHTML.includes('13% network protection')&&node('info').innerHTML.includes('2 / 6'), 'a selected territory displays the authoritative network count and reduction');
check(!map.selectSite(99999), 'an offscreen support identity cannot invent a selectable map site');
emit('controls','change',{target:{value:'fortification',matches:selector=>selector==='[data-world-filter]'}});
check(node('sites').innerHTML.includes('Green Ward')&&!node('sites').innerHTML.includes('North Crown'), 'the Fortification filter applies to the accessible directory');
emit('controls','change',{target:{value:'all',matches:selector=>selector==='[data-world-filter]'}});
check(JSON.stringify(networkProtection({...fortCell,networkDefense:{count:6,reduction:.75,siteIds:[1]}}))==='{"count":0,"percent":0,"siteIds":[]}', 'Fortifications cannot themselves claim neighboring network protection');
const bounded=networkProtection({networkDefense:{count:20,reduction:5,siteIds:[80,'bad',-1,1.5]}});
check(bounded.count===6&&bounded.percent===75&&bounded.siteIds.length===1&&bounded.siteIds[0]===80, 'malformed support values and identities cannot draw invalid map connections');
check(JSON.stringify(networkProtection(resource))==='{"count":0,"percent":0,"siteIds":[]}', 'legacy map responses display zero support without guessing from nearby markers');

const hidden = deferred(); response = () => hidden.promise;
const pendingHidden = map.refresh(); map.setVisible(false);
hidden.resolve(snapshot({ sites: [{ ...resource, name: 'Hidden response' }] })); await pendingHidden;
check(!node('sites').innerHTML.includes('Hidden response') && intervals.size === 0 && frames.size === 0 && timers.size === 0, 'leaving the map ignores pending results and stops refresh, drawing and pan timers');
const inactive = requests.length; await map.refresh();
check(requests.length === inactive, 'hidden maps cannot poll the backend');
state.user = { id: 2002 }; response = async () => snapshot({ home: { id: 99, q: 20, r: 21 }, center: { q: 20, r: 21 }, holdings: [], sites: [] });
await map.open();
check(!('q' in requests.at(-1)[2]) && !node('holdings').innerHTML.includes('Our outpost'), 'switching accounts discards the previous keeper map and requests the new home');
const authChange = deferred(); response = () => authChange.promise;
const pendingAuth = map.refresh(); state.user = { id: 3003 };
authChange.resolve(snapshot({ sites: [{ ...resource, name: 'Previous account response' }] })); await pendingAuth;
check(!node('sites').innerHTML.includes('Previous account response'), 'an in-flight response cannot expose the previous account after a session change');
map.destroy();
check(disconnected && events.size === 0 && intervals.size === 0 && frames.size === 0, 'destroy releases listeners, observer, refresh timers and pending drawing');
const ended = requests.length; await map.open();
check(requests.length === ended, 'destroyed maps cannot restart themselves');
console.log(`World map UI: ${checks} checks passed.`);
