// A document owns one short presence lease. The server owns scheduling, battle
// outcomes and ranks; this module only keeps an authenticated game tab online.
export function activityView(status, {now = Date.now() / 1000, failed = false, connected = true} = {}) {
  const online = Math.max(0, Number(status?.onlinePlayers) || 0);
  if (!connected) return {kind:'waiting', label:'Bots pause while nobody plays', detail:'Sign in to wake the persistent bot world.'};
  if (failed) return {kind:'reconnecting', label:'Reconnecting to the world', detail:'Bot activity will resume when this game reconnects. Your progress stays saved.'};
  if (!status) return {kind:'starting', label:'Waking the bot world', detail:'Connecting your game to the shared simulation.'};
  if (status.mode === 'disabled') return {kind:'disabled', label:'Bot simulation disabled', detail:'Autonomous bot activity is disabled in this world.'};
  if (status.mode !== 'active' || online < 1) return {kind:'waiting', label:'Bots paused · nobody online', detail:'Bots wake automatically when a player enters the game.'};
  const lastTick = Number(status.lastTick) || 0, recent = lastTick > 0 && now - lastTick < Math.max(45, Number(status.leaseSeconds) || 45);
  const last = status.lastResult || {};
  if (last.status === 'error' || last.status === 'failed') return {kind:'error', label:'Bot activity is retrying', detail:'The simulation could not finish its latest turn. It will retry automatically.'};
  if (!recent) return {kind:'starting', label:'Starting bot activity', detail:`${online} player${online === 1 ? ' is' : 's are'} online. Waiting for the next simulation turn.`};
  const battles = Math.max(0, Number(last.active_battles) || 0);
  return {kind:'active', label:`Bots active · ${online} online`, detail:`Bots build, train and fight while players are online.${battles ? ` ${battles} battle${battles === 1 ? ' is' : 's are'} in progress.` : ' Rankings change when real battles finish.'}`};
}

export function createWorldActivity({state, request, onStatus = () => {}, onPulse = () => {},
  send = (...args) => fetch(...args), clock = () => Date.now(),
  schedule = (callback, delay) => setTimeout(callback, delay), cancel = id => clearTimeout(id),
  newId = () => typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : [...crypto.getRandomValues(new Uint8Array(16))].map(byte => byte.toString(16).padStart(2,'0')).join(''),
  windowTarget = window, documentTarget = document}) {
  let owner = null, clientId = null, epoch = 0, timer = null, pending = false;
  let suspended = false, bound = false, confirmed = false, failed = false, status = null;
  const sameOwner = () => owner !== null && Number(state.user?.id) === owner;
  const current = token => token === epoch && sameOwner() && !suspended;
  function view() { return activityView(status, {now:clock()/1000 + Number(state.serverOffset || 0), failed, connected:!!state.user}); }
  function announce() { onStatus(view(), status); }
  function clearTimer() { if (timer !== null) cancel(timer); timer = null; }
  function leave(id, csrf) {
    if (!id || !csrf) return;
    try {
      Promise.resolve(send('api.php?action=worldLeave', {method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify({clientId:id}), keepalive:true, cache:'no-store'})).catch(() => {});
    } catch { /* The short server lease also handles a closed or disconnected tab. */ }
  }
  function queue(seconds = 5) {
    clearTimer();
    if (sameOwner() && !suspended) timer = schedule(() => { timer = null; void pulse(); }, Math.max(3, Math.min(30, Number(seconds) || 5)) * 1000);
  }
  async function pulse() {
    if (!sameOwner() || suspended || pending) return;
    clearTimer(); pending = true; const token = epoch, id = clientId;
    try {
      const result = await request('worldPulse', {clientId:id});
      if (!current(token)) return;
      const next = result?.world?.activity;
      if (!next || typeof next.mode !== 'string') throw new Error('The world activity response is incomplete.');
      status = next; confirmed = true; failed = false;
      if (Number.isFinite(Number(result.serverTime))) state.serverOffset = Number(result.serverTime) - clock()/1000;
      announce(); onPulse(result.world);
    } catch {
      if (current(token)) { failed = true; announce(); }
    } finally {
      if (current(token)) { pending = false; queue(status?.pulseSeconds); }
    }
  }
  function stop({release = true} = {}) {
    const oldId = clientId, csrf = state.csrf;
    clearTimer(); epoch++; owner = null; clientId = null; pending = false; confirmed = false; failed = false; status = null;
    if (release) leave(oldId, csrf);
    announce();
  }
  function sync() {
    const next = Number(state.user?.id) || null;
    if (!next) { if (owner !== null) stop(); else announce(); return; }
    if (next !== owner) {
      if (owner !== null) stop();
      owner = next; clientId = newId(); epoch++; pending = false; confirmed = false; failed = false;
      announce(); if (!suspended) void pulse();
    }
  }
  function observe(next) {
    // Once this tab has a confirmed pulse, a slower bootstrap must not roll its
    // activity status back. Pulses carry no home state or saved-layout records.
    if (!confirmed) { status = next || null; announce(); }
  }
  function hide() {
    if (!sameOwner()) return;
    suspended = true; clearTimer(); epoch++; pending = false;
    leave(clientId, state.csrf); clientId = null; confirmed = false;
  }
  function show() {
    const wasSuspended = suspended; suspended = false;
    if (wasSuspended && sameOwner()) { clientId = newId(); epoch++; failed = false; void pulse(); }
    else sync();
  }
  function wake() { if (!documentTarget.hidden && sameOwner() && !suspended) void pulse(); }
  function bind() {
    if (bound) return; bound = true;
    windowTarget.addEventListener('pagehide', hide);
    windowTarget.addEventListener('pageshow', show);
    windowTarget.addEventListener('online', wake);
    documentTarget.addEventListener('visibilitychange', wake);
  }
  return {sync, stop, observe, view, pulse, bind};
}
