import {esc} from './ui.js?v=0.13.1';

export function createTruces({state,api,showModal,toast,loadRivals}) {
  let revision = 0, busy = false;
  const current = (owner, turn) => state.user?.id === owner && state.modal === 'truces' && turn === revision;
  const newToken = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2,'0')).join('');
  const button = (label,kind,peer,token,previous='') => `<button class="button subtle small" data-truce-action="${kind}" data-peer="${peer}" data-token="${esc(token)}" data-previous="${esc(previous)}">${label}</button>`;
  function render(data) {
    const heading = '<h2 id="modal-title">Truces</h2>';
    const rows = data.keepers.map(agreement => {
      const {keeper,name,isBot,status,token,outgoing,expires} = agreement;
      let caption, actions = '';
      if (status === 'accepted') {
        caption = 'Protected until '+new Date(expires * 1000).toLocaleString();
      } else if (status === 'requested') {
        caption = outgoing ? 'Awaiting their reply' : 'Requests a 14-day truce';
        actions = outgoing ? button('Withdraw','withdraw',keeper,token)
          : button('Accept','accept',keeper,token) + button('Decline','decline',keeper,token);
      } else {
        caption = {none:'No agreement',declined:'Last offer declined',withdrawn:'Last offer withdrawn',expired:'Previous truce expired'}[status];
        actions = button('Offer 14-day truce','request',keeper,newToken(),token);
      }
      return `<li><div><b>${esc(name)}</b><small>${isBot?'Bot · ':''}${esc(caption)}</small></div><div class="neighbor-actions">${actions}</div></li>`;
    });
    const selected = data.keepers.findIndex(peer => peer.keeper === data.selected);
    const groups = [];
    if (selected >= 0) groups.push('<section><h3>Selected keeper</h3><ul>'+rows[selected]+'</ul></section>');
    for (const [title,filter] of [
      ['Offers to answer', p => p.status === 'requested' && !p.outgoing],
      ['Sent offers', p => p.status === 'requested' && p.outgoing],
      ['Active truces', p => p.status === 'accepted'],
    ]) {
      const section = data.keepers.map((p,i) => i !== selected && filter(p) ? rows[i] : '').join('');
      if (section) groups.push('<section><h3>'+title+'</h3><ul>'+section+'</ul></section>');
    }
    const detail = data.selected && data.keepers.some(p=>p.keeper===data.selected&&p.isBot)
      ? '<p class="subtle-note">Bot keepers reply immediately according to their personality. Raiders decline truce offers.</p>' : '';
    showModal(`<div class="neighbors">${heading}<p>A truce protects both keepers’ backyards and territories for 14 days from acceptance. You can still visit each other.</p>${detail}${groups.join('')||'<p>No active truces or pending offers. Choose Truce beside a keeper on the Invasion page.</p>'}<button class="button subtle small" data-truce-open="${Number(data.selected)}">Refresh</button></div>`,'truces');
  }
  async function open(peer) {
    const owner = state.user?.id;
    if (!owner) return;
    const turn = ++revision;
    busy = false;
    showModal('<h2 id="modal-title">Truces</h2><p>Loading agreements…</p>','truces');
    try {
      const data = await api('truces',undefined,peer ? {keeper:peer} : {});
      if (current(owner,turn)) render(data);
    } catch (error) {
      if (current(owner,turn)) {
        toast(error.message,true);
        showModal(`<h2 id="modal-title">Truces</h2><p>${esc(error.message)}</p><button class="button subtle" data-truce-open="${peer}">Try again</button>`,'truces');
      }
    }
  }
  async function handleClick(el) {
    if (el.dataset.truceOpen !== undefined) {
      await open(Number(el.dataset.truceOpen));
      return true;
    }
    if (!el.dataset.truceAction) return false;
    if (busy || state.modal !== 'truces' || !state.user) return true;
    const owner = state.user.id, turn = revision, keeper = Number(el.dataset.peer);
    busy = true;
    el.disabled = true;
    try {
      const data = await api('truceAction',{kind:el.dataset.truceAction,keeper,token:el.dataset.token,previous:el.dataset.previous});
      if (owner !== state.user?.id) return true;
      const agreement = data.keepers.find(row => row.keeper === keeper);
      if (state.visiting?.owner?.id === keeper) state.visiting.truceUntil = agreement?.status === 'accepted' ? agreement.expires : 0;
      if (current(owner,turn)) render(data);
      if (state.page === 'invasion') await loadRivals();
    } catch (error) {
      if (current(owner,turn)) toast(error.message,true);
    } finally {
      if (revision === turn) { busy = false; el.disabled = false; }
    }
    return true;
  }
  return {handleClick};
}
