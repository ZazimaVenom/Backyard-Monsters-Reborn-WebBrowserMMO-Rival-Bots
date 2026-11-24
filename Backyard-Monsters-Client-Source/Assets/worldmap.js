// Strategic map presentation only. Ownership, reach and attack permission are server-owned.
const SQRT3 = Math.sqrt(3), CELL = 31;
const clamp = (n, low, high) => Math.max(low, Math.min(high, n));
const finite = n => Number.isFinite(Number(n));
const kindName = site => site.mr2Outpost ? 'Regional Outpost' : ({ home: 'Backyard', resource: 'Resource Outpost', stronghold: 'Stronghold', fortification: 'Fortification' })[site.kind] || 'Site';
const artKey = site => site.mr2Outpost ? 'mr2core' : ({resource:'outpost',home:'townhall',stronghold:'stronghold',fortification:'outpostdefender'})[site.kind] || 'townhall';
const symbol = kind => ({home:'⌂',resource:'◆',stronghold:'♜',fortification:'⬡'})[kind] || '◆';
export function networkProtection(site) {
  if(site?.kind==='fortification') return {count:0,percent:0,siteIds:[]};
  const value=site?.networkDefense||{};
  return {count:clamp(Math.floor(Number(value.count)||0),0,6),percent:Math.round(clamp(Number(value.reduction)||0,0,.75)*100),siteIds:Array.isArray(value.siteIds)?value.siteIds.filter(id=>Number.isSafeInteger(id)&&id>0):[]};
}
const safeEsc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);

export function hexPoint(q, r, size = CELL) {
  return { x: SQRT3 * size * (q + (Math.floor(r) & 1) / 2), y: 1.5 * size * r };
}
export function hexAt(x, y, size = CELL) {
  const axialQ = (SQRT3 / 3 * x - y / 3) / size, axialR = 2 / 3 * y / size;
  let q = Math.round(axialQ), r = Math.round(axialR), s = Math.round(-axialQ - axialR);
  const dq = Math.abs(q - axialQ), dr = Math.abs(r - axialR), ds = Math.abs(s + axialQ + axialR);
  if (dq > dr && dq > ds) q = -r - s;
  else if (dr > ds) r = -q - s;
  return { q: q + (r - (r & 1)) / 2, r };
}
export function hexDistance(a, b) {
  const q = p => p.q - (p.r - (p.r & 1)) / 2;
  const dq = q(a) - q(b), dr = a.r - b.r;
  return Math.max(Math.abs(dq), Math.abs(dr), Math.abs(dq + dr));
}
export function siteStatus(site, serverTime = 0) {
  if (site.busy) return 'Battle in progress';
  if (Number(site.ready_at) > serverTime) return 'Rebuilding / upgrading';
  if (Number(site.shield_until) > serverTime) return 'Protected';
  if (site.kind === 'home') return site.owned ? 'Your backyard' : 'Visit backyard';
  if (site.owned) return 'Your territory';
  return site.reachable ? 'Within reach' : 'Beyond reach';
}

export function createWorldMap({ state, api, esc = safeEsc, toast = () => {}, onVisitHome, onVisitSite } = {}) {
  const el = id => document.getElementById(id);
  const canvas = el('world-map-canvas'), info = el('world-map-info'), holdings = el('world-map-holdings');
  const status = el('world-map-status'), controls = el('world-map-controls'), list = el('world-map-sites');
  if (!canvas || !info || !holdings || !status || !controls || !list) throw new Error('The world map containers are missing.');
  const ctx = canvas.getContext('2d', { alpha: false }), cleanups = [], images = {};
  let region = null;
  let data = null, visible = false, destroyed = false, ownerId = null, request = 0, selectedId = null;
  let camera = { x: 0, y: 0 }, zoom = 1, width = 0, height = 0, dpr = 1;
  let drag = null, hoveredId = null, filter = 'all', showReach = true, frame = null, debounce = null, periodic = null;
  let loading = false, stale = false, lastRadius = 8, loadedRadius = 8, loadedCenter = null;
  const listen = (target, event, callback, options) => {
    target.addEventListener(event, callback, options);
    cleanups.push(() => target.removeEventListener(event, callback, options));
  };
  const sites = () => data?.sites || [];
  const allKnown = () => [...sites(), ...(data?.holdings || []), ...(data?.fortifications || [])];
  const selected = () => allKnown().find(site => site.id === selectedId);
  const viewerId = () => state?.user?.id ?? null;
  const serverNow = () => Number(data?.serverTime) || 0;
  const isSite = site => Number.isSafeInteger(site?.id) && site.id > 0 && ['home', 'resource', 'stronghold', 'fortification'].includes(site.kind)
    && Number.isInteger(site.q) && Number.isInteger(site.r) && site.q >= 0 && site.r >= 0 && site.q < 64 && site.r < 64;
  const screen = point => ({ x: (point.x - camera.x) * zoom + width / 2, y: (point.y - camera.y) * zoom + height / 2 });
  const unscreen = point => ({ x: (point.x - width / 2) / zoom + camera.x, y: (point.y - height / 2) / zoom + camera.y });
  const centerCell = () => {
    const cell = hexAt(camera.x, camera.y);
    return { q: clamp(cell.q, 0, 63), r: clamp(cell.r, 0, 63) };
  };
  const pointOf = event => { const rect = canvas.getBoundingClientRect(); return { x: event.clientX - rect.left, y: event.clientY - rect.top }; };
  const filtered = () => sites().filter(site => filter === 'all' || site.kind === filter);
  const siteAt = event => {
    const point = unscreen(pointOf(event)), cell = hexAt(point.x, point.y);
    return filtered().find(site => site.q === cell.q && site.r === cell.r);
  };
  const setStatus = message => { status.textContent = message; };
  const replaceHTML = (target, html) => {
    if (target.innerHTML === html) return;
    const active = target.contains(document.activeElement) ? document.activeElement?.dataset : null;
    const focused = active?.worldSelect || active?.worldVisit, attribute = active?.worldSelect ? 'select' : 'visit';
    target.innerHTML = html;
    if (focused && /^\d+$/.test(focused)) target.querySelector(`[data-world-${attribute}="${focused}"]`)?.focus({ preventScroll: true });
  };
  const drawSoon = () => {
    if (!visible || destroyed || frame !== null) return;
    frame = requestAnimationFrame(() => { frame = null; if (visible && !destroyed) draw(); });
  };
  function resize() {
    const rect = canvas.getBoundingClientRect();
    if (!rect.width || !rect.height) return;
    width = rect.width; height = rect.height; dpr = Math.min(globalThis.devicePixelRatio || 1, 2);
    canvas.width = Math.round(width * dpr); canvas.height = Math.round(height * dpr);
    drawSoon();
  }
  function boundCamera() {
    camera.x = clamp(camera.x, 0, hexPoint(63, 63).x);
    camera.y = clamp(camera.y, 0, hexPoint(63, 63).y);
  }
  function neededRadius() {
    // The backend returns cells in a bounded hex radius, at most 12. Farther
    // corners at the widest zoom remain visibly muted until the camera pans.
    const center = centerCell();
    const distances = [[0, 0], [width, 0], [width, height], [0, height]].map(([x, y]) => {
      const point = unscreen({ x, y }); return hexDistance(center, hexAt(point.x, point.y));
    });
    return clamp(Math.max(...distances) + 1, 4, 12);
  }
  function scheduleRefresh() {
    clearTimeout(debounce);
    if (!visible) return;
    debounce = setTimeout(() => { debounce = null; refresh(); }, 220);
  }
  function setZoom(value, anchor = { x: width / 2, y: height / 2 }) {
    const before = unscreen(anchor);
    zoom = clamp(value, .65, 1.8);
    camera = { x: before.x - (anchor.x - width / 2) / zoom, y: before.y - (anchor.y - height / 2) / zoom };
    boundCamera(); updateZoom(); drawSoon(); scheduleRefresh();
  }
  function updateZoom() {
    const output = controls.querySelector('[data-world-zoom]');
    if (output) output.textContent = `${Math.round(zoom * 100)}%`;
  }
  function panTo(q, r) {
    if (!finite(q) || !finite(r)) return Promise.resolve(null);
    camera = hexPoint(clamp(Math.round(Number(q)), 0, 63), clamp(Math.round(Number(r)), 0, 63));
    drawSoon(); return refresh();
  }
  function centerHome() {
    if (!data?.home) return refresh({ home: true });
    if (data.home.region && data.home.region!==data.region) return changeRegion(data.home.region);
    return panTo(data.home.q, data.home.r);
  }
  function changeRegion(next) {
    region=Number(next)||null;data=null;selectedId=null;loadedCenter=null;camera=hexPoint(32,32);
    return refresh({home:true});
  }
  function selectSite(id, { center = false } = {}) {
    const site = allKnown().find(site => site.id === Number(id));
    if (!site) return false;
    selectedId = site.id; renderPanels(); drawSoon();
    if (center && site.region && site.region!==data.region) {changeRegion(site.region).then(()=>{if(data?.region===site.region)panTo(site.q,site.r);});}
    else if (center) panTo(site.q, site.r);
    return true;
  }
  async function visitSelected() {
    const site = selected();
    if (!site) return;
    try {
      if (site.kind === 'home') await onVisitHome?.(site.homeUserId || site.owner?.id);
      else await onVisitSite?.(site.id);
    } catch (error) { toast(error.message || 'Could not visit this site.', true); }
  }
  function renderPanels() {
    const site = selected(), timestamp = serverNow();
    if (!data) {
      replaceHTML(info, '<div class="world-empty"><h3>Your next frontier.</h3><p>Load the map to explore nearby backyards and territory.</p></div>');
      replaceHTML(holdings, '<p class="world-map-note">Your captured sites appear here.</p>');
      replaceHTML(list, ''); return;
    }
    if (site) {
      const protection=networkProtection(site);
      const network=site.kind==='fortification'?'<div class="world-network-note"><b>Strengthen neighboring defenses</b><span>Protects adjacent backyards, Resource Outposts and Strongholds owned by the same keeper. Adds no income or reach.</span></div>':`<div class="world-network-note"><b>${protection.percent}% network protection</b><span>${protection.count} / 6 neighboring Fortifications share this keeper. Protection reduces incoming building damage.</span></div>`;
      const owner = site.owned ? 'Your territory' : site.owner ? `${site.owner.name} · ${site.owner.is_bot ? 'Bot' : 'Player'}` : 'Neutral territory';
      replaceHTML(info, `<div class="world-map-summary"><img class="world-selection-art" src="assets/art/${artKey(site)}.png" alt=""><span class="eyebrow">${esc(kindName(site))}${site.kind === 'home' ? '' : ' · LEVEL ' + (Number(site.level) || 1)}</span><h3>${esc(site.name)}</h3><p>${esc(owner)}</p><div class="world-selection-meta"><span class="world-map-coordinate">${site.q}, ${site.r}</span><span>${esc(siteStatus(site, timestamp))}</span></div>${network}<button class="button primary block" data-world-visit="${site.id}">${site.kind === 'home' ? 'Visit backyard' : site.owned ? 'Visit & manage' : 'Scout this site'}</button><p class="world-map-note">${site.kind === 'home' ? 'Home invasions use the existing backyard rules.' : site.owned ? 'Manage upgrades, repairs and real monster garrisons from the site.' : 'Capture requires 90% damage to eligible defense health. Scout for current invasion requirements.'}</p></div>`);
    } else {
      replaceHTML(info, '<div class="world-empty"><span class="eyebrow">EXPLORE THE REALM</span><h3>A bigger backyard.</h3><p>Select a marker or a site below to scout its defenses. Resource Outposts grow your economy; Strongholds extend your reach. Capture neighboring Fortifications to protect their keeper’s buildings.</p><p class="world-map-note">Drag to explore. Scroll to zoom. Arrow keys pan; Home returns to your backyard.</p></div>');
    }
    const own = data.holdings || [], forts=data.fortifications||[], fortLimit=Math.max(0,Number(data.fortificationLimit)||0), limit = Math.max(0, Number(data.holdingLimit) || 0), bonuses = data.bonuses || {};
    const rate = (Number(bonuses.resourcePerSecond) || 0) * (Number(state?.catalog?.speed) || 1), capacity = Number(bonuses.capacity) || 0;
    replaceHTML(holdings, `<div class="world-holdings-summary"><b>${own.length} / ${limit} holdings</b><span>Map Room ${Number(data.mapRoomLevel) || 0}</span></div><p class="world-map-note">${rate.toLocaleString('en-US', { maximumFractionDigits: 2 })} / sec of each resource · +${capacity.toLocaleString('en-US')} storage each</p>${own.length ? own.map(item => `<button type="button" class="world-holding-button${item.id === selectedId ? ' selected' : ''}" data-world-select="${item.id}" data-world-center="true"><img src="assets/art/${artKey(item)}.png" alt=""><span><b>${esc(item.name)}</b><small>${esc(kindName(item))}${item.kind === 'home' ? '' : ' · Lv ' + (Number(item.level) || 1)} · ${item.q}, ${item.r}</small></span></button>`).join('') : `<p class="world-map-note">${data.mapRoomLevel ? 'Capture a reachable site to establish your first holding.' : 'Build a Map Room at Town Hall 3 to begin capturing territory. Exploring is free.'}</p>`}<div class="world-holdings-summary world-fortification-summary"><b>${forts.length} / ${fortLimit} Fortifications</b><span>Separate capacity</span></div><p class="world-map-note">Connected cells protect neighboring sites of the same keeper. Fortifications do not extend reach.</p>${forts.map(item=>`<button type="button" class="world-holding-button${item.id===selectedId?' selected':''}" data-world-select="${item.id}" data-world-center="true"><img src="assets/art/outpostdefender.png" alt=""><span><b>${esc(item.name)}</b><small>Fortification · Lv ${Number(item.level)||1} · ${item.q}, ${item.r}</small></span></button>`).join('')}`);
    const ordered = filtered().slice().sort((a, b) => Number(b.owned) - Number(a.owned) || Number(b.reachable) - Number(a.reachable) || hexDistance(a, centerCell()) - hexDistance(b, centerCell()) || a.id - b.id);
    replaceHTML(list, ordered.length ? `<div class="world-site-list">${ordered.map(item => `<button type="button" class="world-site-button${item.id === selectedId ? ' selected' : ''}${item.owned ? ' owned' : ''}" data-world-select="${item.id}" aria-pressed="${item.id === selectedId}"><span class="world-site-symbol" aria-hidden="true">${symbol(item.kind)}</span><span class="world-site-copy"><b>${esc(item.name)}</b><small>${esc(kindName(item))}${item.kind === 'home' ? '' : ' · Lv ' + (Number(item.level) || 1)} · ${item.q}, ${item.r}</small></span><span class="world-site-state">${esc(siteStatus(item, timestamp))}</span></button>`).join('')}</div>` : '<p class="world-empty">No matching sites in this area. Pan the map or change the filter.</p>');
  }
  async function refresh({ home = false } = {}) {
    if (!visible || destroyed) return null;
    const epoch = ++request, identity = viewerId(), center = home || !data ? null : centerCell();
    loading = true; stale = false; lastRadius = neededRadius();
    setStatus(data ? 'Updating this area…' : 'Opening your realm…'); canvas.setAttribute('aria-busy', 'true');
    try {
      const response = await api('worldMap', undefined, { ...(center || {}), ...(region?{region}:{}), radius: lastRadius });
      if (epoch !== request || !visible || destroyed || identity !== viewerId()) return null;
      if (!response || response.layout !== 'odd-r' || response.width !== 64 || response.height !== 64 || !Array.isArray(response.sites)
        || !response.home || !finite(response.home.q) || !finite(response.home.r)) throw new Error('The server returned an unsupported map.');
      const first = !data || home;
      data = { ...response, sites: response.sites.filter(isSite), holdings: (response.holdings || []).filter(item=>isSite(item)&&['resource','stronghold'].includes(item.kind)), fortifications:(response.fortifications||[]).filter(item=>isSite(item)&&item.kind==='fortification') };
      loadedRadius = lastRadius;
      loadedCenter = response.center && finite(response.center.q) && finite(response.center.r) ? response.center : center || response.home;
      if (first) camera = hexPoint(Number(loadedCenter.q), Number(loadedCenter.r));
      if(response.region)region=response.region;
      ownerId = identity; loading = false;
      if (!selected()) selectedId = null;
      renderPanels(); drawSoon();
      setStatus(`${response.regionName?response.regionName+' · ':''}${sites().length} sites in this area · ${loadedCenter.q}, ${loadedCenter.r} · Updated ${new Date(serverNow() * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`);
      return data;
    } catch (error) {
      if (epoch !== request || !visible || destroyed || identity !== viewerId()) return null;
      loading = false; stale = true; setStatus(`${data ? 'Showing the last map. ' : ''}${error.message || 'Could not load the map.'} Use Refresh to retry.`); drawSoon(); return null;
    } finally { if (epoch === request) canvas.setAttribute('aria-busy', 'false'); }
  }
  function hexPath(x, y, radius) {
    ctx.beginPath();
    for (let i = 0; i < 6; i++) {
      const angle = Math.PI / 3 * i - Math.PI / 6, px = x + Math.cos(angle) * radius, py = y + Math.sin(angle) * radius;
      if (i) ctx.lineTo(px, py); else ctx.moveTo(px, py);
    }
    ctx.closePath();
  }
  function inReach(cell) {
    if (!data?.home) return false;
    const homeRange = Number(data.home.range) || 10;
    if ((!data.region || data.home.region===data.region) && hexDistance(cell, data.home) <= homeRange) return true;
    return (data.holdings || []).some(site => {
      const range = Number(site.range) || (site.kind === 'resource' ? [0, 2, 3, 4, 5, 6][site.level] : [0, 10, 15, 20][site.level]) || 0;
      return (!site.region || site.region===data.region) && hexDistance(cell, site) <= range + (homeRange - 10);
    });
  }
  function draw() {
    if (!width || !height) return;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.fillStyle = '#132b2a'; ctx.fillRect(0, 0, width, height);
    const upper = unscreen({ x: -CELL * zoom, y: -CELL * zoom }), lower = unscreen({ x: width + CELL * zoom, y: height + CELL * zoom });
    const cornerA = hexAt(upper.x, upper.y), cornerB = hexAt(lower.x, lower.y);
    const loaded = cell => loadedCenter && hexDistance(cell, loadedCenter) <= loadedRadius;
    for (let r = Math.max(0, cornerA.r - 2); r <= Math.min(63, cornerB.r + 2); r++) {
      for (let q = Math.max(0, cornerA.q - 2); q <= Math.min(63, cornerB.q + 2); q++) {
        const cell = { q, r }, point = screen(hexPoint(q, r)), known = loaded(cell), reach = showReach && inReach(cell);
        const tone = ((q * 17 + r * 31) % 7); // Ground shading only; never places invented sites.
        ctx.fillStyle = !known ? '#18322f' : reach ? ['#365b3f', '#3a6043', '#355b43'][tone % 3] : ['#284b3b', '#2b503f', '#2a4c3e'][tone % 3];
        ctx.strokeStyle = known ? '#9ab07a24' : '#70867913'; ctx.lineWidth = .7;
        hexPath(point.x, point.y, CELL * zoom - 1); ctx.fill(); ctx.stroke();
        if (known && zoom > 1.15 && tone === 0) {
          ctx.fillStyle = '#98b77d19'; ctx.beginPath(); ctx.arc(point.x + 7 * zoom, point.y + 7 * zoom, 4 * zoom, 0, Math.PI * 2); ctx.fill();
        }
      }
    }
    // Draw only relationships supplied by the current server snapshot.
    const selectedSite=selected(), support=networkProtection(selectedSite);
    if(selectedSite&&support.siteIds.length){
      const center=screen(hexPoint(selectedSite.q,selectedSite.r));
      for(const sourceId of support.siteIds){
        const source=allKnown().find(item=>item.id===sourceId&&item.kind==='fortification');
        if(!source)continue;
        const point=screen(hexPoint(source.q,source.r));
        ctx.strokeStyle='#9adbd1aa';ctx.lineWidth=2*zoom;ctx.setLineDash([4*zoom,4*zoom]);ctx.beginPath();ctx.moveTo(center.x,center.y);ctx.lineTo(point.x,point.y);ctx.stroke();ctx.setLineDash([]);
        hexPath(point.x,point.y,26*zoom);ctx.fillStyle='#7ed8cb24';ctx.fill();
      }
    }
    for (const site of filtered()) {
      const point = screen(hexPoint(site.q, site.r));
      if (point.x < -60 || point.y < -60 || point.x > width + 60 || point.y > height + 60) continue;
      const chosen = site.id === selectedId, hover = site.id === hoveredId;
      const color = site.owned ? '#d9ed9e' : site.owner ? '#e8ac88' : '#b8d4db';
      const radius = (site.kind === 'home' ? 11 : 16) * zoom;
      ctx.fillStyle = '#112b2be8'; ctx.strokeStyle = color; ctx.lineWidth = (chosen ? 3 : hover ? 2 : 1.3) * zoom;
      hexPath(point.x, point.y, radius + (chosen ? 5 : 1) * zoom); ctx.fill(); ctx.stroke();
      if (site.kind !== 'home' && images[artKey(site)]) {
        const size = 45 * zoom; ctx.drawImage(images[artKey(site)], point.x - size / 2, point.y - size * .66, size, size);
      } else {
        ctx.font = `${site.kind === 'home' ? 19 : 23}px system-ui`; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = color;
        ctx.fillText(symbol(site.kind), point.x, point.y - 1);
      }
      if (site.kind !== 'home' && site.reachable && !site.owned) {
        ctx.strokeStyle = '#cae7a7'; ctx.lineWidth = 1.5; ctx.beginPath(); ctx.arc(point.x, point.y, 22 * zoom, 0, Math.PI * 2); ctx.stroke();
      }
      if (site.busy || Number(site.shield_until) > serverNow()) {
        ctx.fillStyle = site.busy ? '#e9a67d' : '#a8dce6'; ctx.beginPath(); ctx.arc(point.x + radius, point.y - radius, 4 * zoom, 0, Math.PI * 2); ctx.fill();
      }
      if (chosen || hover || site.owned && zoom >= .9) drawLabel(site.name, point.x, point.y + 28 * zoom, chosen ? '#f1e9bb' : '#dfe6c7');
    }
    if (data?.home) {
      const point = screen(hexPoint(data.home.q, data.home.r));
      ctx.strokeStyle = '#e5efae'; ctx.lineWidth = 1.5; ctx.setLineDash([4, 4]);
      hexPath(point.x, point.y, 24 * zoom); ctx.stroke(); ctx.setLineDash([]);
    }
    ctx.textBaseline = 'alphabetic'; ctx.textAlign = 'left'; ctx.font = '11px system-ui'; ctx.fillStyle = '#d9e6c799';
    ctx.fillText(`${centerCell().q}, ${centerCell().r}   ·   64 × 64`, 16, height - 16);
    if (loading || stale) { ctx.textAlign = 'right'; ctx.fillStyle = stale ? '#f0bc94' : '#d9e6c7'; ctx.fillText(stale ? 'Last known map' : 'Updating…', width - 16, height - 16); }
  }
  function drawLabel(name, x, y, color) {
    const text = String(name).length > 26 ? `${String(name).slice(0, 25)}…` : String(name);
    ctx.font = '600 11px system-ui'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    const w = ctx.measureText(text).width + 14;
    ctx.fillStyle = '#102827eb'; ctx.fillRect(x - w / 2, y - 9, w, 19); ctx.fillStyle = color; ctx.fillText(text, x, y + 1);
  }
  function reset() {
    region = null;
    request++; data = null; selectedId = null; ownerId = null; loadedCenter = null; stale = false; loading = false;
    camera = { x: 0, y: 0 }; zoom = 1; filter = 'all'; hoveredId = null; drag = null;
    controls.querySelector('[data-world-filter]').value = 'all'; updateZoom(); renderPanels(); drawSoon(); setStatus('Open the map to explore your realm.');
  }
  function setVisible(value) {
    visible = !!value && !destroyed;
    clearInterval(periodic); periodic = null; clearTimeout(debounce); debounce = null;
    if (!visible) {
      request++; drag = null; loading = false; canvas.setAttribute('aria-busy', 'false');
      if (frame !== null) cancelAnimationFrame(frame); frame = null;
    } else {
      resize(); drawSoon(); periodic = setInterval(() => refresh(), 30000);
    }
  }
  async function open() {
    if (destroyed) return null;
    if (ownerId !== null && ownerId !== viewerId()) reset();
    setVisible(true); return refresh();
  }
  function destroy() {
    setVisible(false); destroyed = true; request++; observer.disconnect(); cleanups.forEach(cleanup => cleanup());
  }
  controls.innerHTML = '<div class="world-map-toolbar"><div class="world-map-actions"><button type="button" class="button subtle small" data-world-action="home">My backyard</button><button type="button" class="button subtle small" data-region-open>Regions &amp; relocation</button><button type="button" class="button subtle small" data-world-action="refresh">Refresh</button><button type="button" class="button subtle small" data-tribes-open="hellraisers">Hell Raisers</button><button type="button" class="button subtle small" data-world-action="out" aria-label="Zoom out">−</button><output data-world-zoom aria-label="Map zoom">100%</output><button type="button" class="button subtle small" data-world-action="in" aria-label="Zoom in">+</button></div><label class="world-map-filter">Show <select data-world-filter><option value="all">All sites</option><option value="resource">Resource Outposts</option><option value="stronghold">Strongholds</option><option value="fortification">Fortifications</option><option value="home">Backyards</option></select></label><label class="world-map-filter"><input type="checkbox" data-world-reach checked> Reach guide</label></div><div class="world-map-legend"><span><i class="world-legend-dot own"></i>Yours</span><span><i class="world-legend-dot neutral"></i>Neutral</span><span><i class="world-legend-dot rival"></i>Rival</span><span><i class="world-legend-dot reachable"></i>Within reach</span></div><p id="world-map-help" class="world-map-caption">Drag or use arrow keys to explore. Scroll or + / − to zoom. Select a marker, then choose Visit. Reach is distance only; scout for invasion requirements.</p>';
  canvas.tabIndex = 0; canvas.setAttribute('aria-label', 'World map. Select sites with the list below, or click a marker.'); canvas.setAttribute('aria-describedby', 'world-map-help');
  canvas.style.touchAction = 'none'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
  listen(controls, 'click', event => {
    const action = event.target.closest('[data-world-action]')?.dataset.worldAction;
    if (action === 'home') centerHome();
    if (action === 'refresh') refresh();
    if (action === 'in') setZoom(zoom * 1.2);
    if (action === 'out') setZoom(zoom / 1.2);
  });
  listen(controls, 'change', event => {
    if (event.target.matches('[data-world-filter]')) { filter = event.target.value; renderPanels(); drawSoon(); }
    if (event.target.matches('[data-world-reach]')) { showReach = event.target.checked; drawSoon(); }
  });
  for (const target of [info, holdings, list]) listen(target, 'click', event => {
    const button = event.target.closest('[data-world-select], [data-world-visit]');
    if (!button) return;
    if (button.dataset.worldSelect) selectSite(button.dataset.worldSelect, { center: button.dataset.worldCenter === 'true' });
    else visitSelected();
  });
  listen(canvas, 'pointerdown', event => {
    if (event.button !== 0 || drag) return;
    canvas.setPointerCapture?.(event.pointerId); canvas.focus({ preventScroll: true });
    drag = { id: event.pointerId, x: event.clientX, y: event.clientY, camera: { ...camera }, moved: false };
  });
  listen(canvas, 'pointermove', event => {
    if (drag && drag.id === event.pointerId) {
      const dx = event.clientX - drag.x, dy = event.clientY - drag.y;
      if (Math.hypot(dx, dy) > 6) drag.moved = true;
      if (drag.moved) { camera = { x: drag.camera.x - dx / zoom, y: drag.camera.y - dy / zoom }; boundCamera(); canvas.style.cursor = 'grabbing'; drawSoon(); }
    } else {
      const next = siteAt(event)?.id ?? null;
      if (hoveredId !== next) { hoveredId = next; canvas.style.cursor = next ? 'pointer' : 'grab'; drawSoon(); }
    }
  });
  listen(canvas, 'pointerup', event => {
    if (!drag || drag.id !== event.pointerId) return;
    const moved = drag.moved; drag = null; canvas.releasePointerCapture?.(event.pointerId); canvas.style.cursor = 'grab';
    if (moved) scheduleRefresh();
    else { const site = siteAt(event); if (site) selectSite(site.id); else { selectedId = null; renderPanels(); drawSoon(); } }
  });
  listen(canvas, 'pointercancel', () => { if (drag?.moved) scheduleRefresh(); drag = null; canvas.style.cursor = 'grab'; });
  listen(canvas, 'pointerleave', () => { hoveredId = null; drawSoon(); });
  listen(canvas, 'wheel', event => { event.preventDefault(); setZoom(zoom * (event.deltaY < 0 ? 1.12 : 1 / 1.12), pointOf(event)); }, { passive: false });
  listen(canvas, 'keydown', event => {
    const vectors = { ArrowLeft: [-80, 0], ArrowRight: [80, 0], ArrowUp: [0, -80], ArrowDown: [0, 80] };
    if (vectors[event.key]) { event.preventDefault(); camera.x += vectors[event.key][0] / zoom; camera.y += vectors[event.key][1] / zoom; boundCamera(); drawSoon(); scheduleRefresh(); }
    if (['+', '=', '-'].includes(event.key)) { event.preventDefault(); setZoom(zoom * (event.key === '-' ? 1 / 1.2 : 1.2)); }
    if (event.key === 'Home') { event.preventDefault(); centerHome(); }
    if (event.key === 'Enter' && selected()) { event.preventDefault(); visitSelected(); }
    if (event.key === 'Escape') { selectedId = null; renderPanels(); drawSoon(); }
  });
  const observer = new ResizeObserver(() => { resize(); if (visible && data) scheduleRefresh(); }); observer.observe(canvas);
  for (const key of ['outpost', 'stronghold', 'outpostdefender', 'mr2core']) {
    const image = new Image(); image.decoding = 'async';
    image.onload = () => { if (!destroyed) { images[key] = image; drawSoon(); } };
    image.src = key==='mr2core'?'assets/art/mr2core.png':`assets/portraits/${key}.webp`;
  }
  renderPanels();
  return { open, refresh, changeRegion, setVisible, resize, destroy, reset, selectSite, centerHome, panTo, get snapshot() { return data; } };
}
