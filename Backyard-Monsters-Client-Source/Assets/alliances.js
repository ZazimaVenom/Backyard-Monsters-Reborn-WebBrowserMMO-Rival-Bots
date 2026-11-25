import {esc, icon, num} from './ui.js?v=0.13.1';

const btn = (text, op, props='', style='subtle') => `<button type="button" class="button small ${style}" data-a="${op}" ${props}>${text}</button>`;
const target = id => `data-id="${Number(id)}"`;
const empty = text => `<p class="alliance-empty">${text}</p>`;
const formSearch = (name,value,placeholder) => `<form data-form="${name}" class="alliance-search"><label>${icon('search')}<input name="search" type="search" maxlength="40" value="${esc(value)}" placeholder="${placeholder}" aria-label="${placeholder}"></label><button class="button small subtle">Search</button></form>`;

export function createAlliances({state, api, showModal, toast}) {
    let root, view, tab, search, keeperSearch, page, warPage, focus, busy, sequence=0, confirm, powerTimer, feedTimer, draft='', sendKey='', sendAfter=0;
    const mine = () => Number(view?.mine)===Number(view?.detail?.id) && !!view?.mine;
    const leader = () => mine() && Number(view.detail.leader_id)===Number(state.user?.id);
    const own = item => Number(item.user_id)===Number(state.user?.id);
    const isOpen = owner => owner===state.user?.id && state.modal==='alliances' && root?.isConnected;

    function directory() {
        const rows = view.groups.map(g=>`<li><span class="alliance-place">#${num(g.standing)}</span><div><b>${esc(g.name)}</b><small>${num(g.members)} / ${view.capacity} keepers · ${num(g.points)} points${Number(g.bot_led)?' · Bot-led':''}</small></div>${btn('View','detail',target(g.id))}</li>`).join('');
        return formSearch('search',search,'Find an alliance')+(rows?`<ul class="alliance-list">${rows}</ul>`:empty(search?'No alliances match that name.':'This realm has no alliances yet. Found one to start recruiting.'))+
            (view.pages>1?`<div class="alliance-pager">${btn('Previous','page',`${target(view.page-1)} ${view.page<=1?'disabled':''}`)}<span>${view.page} / ${view.pages}</span>${btn('Next','page',`${target(view.page+1)} ${view.page>=view.pages?'disabled':''}`)}</div>`:'')+
            (!view.mine?btn('Found an alliance','create','','primary'):'');
    }

    function roster() {
        const g=view.detail;
        if(!g)return empty('This alliance has disbanded.');
        const requests=view.inbox.filter(r=>Number(r.alliance_id)===Number(g.id)&&own(r));
        const rows=view.roster.map(u=>{
            const isLead=Number(u.id)===Number(g.leader_id),bot=Number(u.is_bot)===1;
            const controls=leader()&&!isLead ? (bot?'':btn('Make leader','ask-transfer',target(u.id)))+btn('Remove','ask-kick',target(u.id)) : '';
            return `<li><div><b>${esc(u.name)}</b><small>${isLead?(bot?'Bot leader':'Leader'):bot?'Bot member':'Member'} · ${num(u.rank_points)} points</small></div><div class="alliance-tools">${controls}</div></li>`;
        }).join('');
        let controls='';
        if(mine()) {
            controls=(leader()?btn('Edit description','edit'):'')+btn(leader()&&view.roster.length===1?'Disband alliance':'Leave alliance','ask-leave');
        } else if(!view.mine) {
            controls=requests.length?btn('View pending exchange','tab', 'data-tab="inbox"'):Number(g.members)<view.capacity?btn('Request to join','request','','primary'):empty('All places are filled.');
        }
        return `<div class="alliance-card"><div class="alliance-heading">${icon('shield')}<div><h3>${esc(g.name)}</h3><small>Led by ${esc(g.leader_name)}${Number(g.bot_led)?' · Bot leader':''}</small></div></div>${g.description?`<p>${esc(g.description)}</p>`:''}<div class="alliance-stats"><span>Realm rank <b>#${num(g.standing)}</b></span><span>Rank points <b>${num(g.points)}</b></span><span>Keepers <b>${num(g.members)} / ${view.capacity}</b></span></div><div class="alliance-tools">${controls}</div></div><h3>Members</h3><ul class="alliance-list">${rows}</ul>`;
    }

    function recruits() {
        if(!leader())return empty('Only your alliance leader can recruit members.');
        if(Number(view.detail.members)>=view.capacity)return empty('Your alliance has reached its 50-keeper limit.');
        const rows=view.suggestions.map(u=>{
            const bot=Number(u.is_bot)===1;
            return `<li><div><b>${esc(u.name)}</b><small>${bot?'Bot keeper':'Player'} · ${num(u.rank_points)} points</small></div>${btn(bot?'Recruit':'Invite','invite',target(u.id))}</li>`;
        }).join('');
        return formSearch('keepers',keeperSearch,'Find an available keeper')+
            '<p>Players receive an invitation. Recruited bots join immediately and contribute their battle points.</p>'+
            (rows?`<ul class="alliance-list">${rows}</ul>`:empty('No available keepers match this search.'));
    }

    function inbox() {
        if(!view.inbox.length)return empty('No pending invitations or join requests.');
        return '<ul class="alliance-list">'+view.inbox.map(r=>{
            const recipient=r.kind==='invite'?own(r):!own(r);
            const props=`${target(r.user_id)} data-group="${Number(r.alliance_id)}" data-request="${Number(r.id)}"`;
            const controls=recipient?btn('Accept','accept',props,'primary')+btn('Decline','decline',props):btn('Withdraw','withdraw',props);
            return `<li><div><b>${esc(r.alliance_name)}</b><small>${r.kind==='invite'?'Invitation for':'Request from'} ${esc(r.keeper_name)}</small></div><div class="alliance-tools">${controls}</div></li>`;
        }).join('')+'</ul>';
    }

    function warBoard() {
        const d=view.diplomacy,g=view.detail;
        if(!g)return empty('Select an alliance in the directory to view its war board.');
        const other=view.mine && Number(view.mine)!==Number(g.id);
        const myLead=d.canLead && other;
        const ownId=Number(view.mine)||0;
        const flags=['ally','neutral','foe'].map(f=>btn(f[0].toUpperCase()+f.slice(1),'stance',`data-stance="${f}" ${d.stance===f?'disabled':''}`)).join('');
        let top=`<h3>${esc(g.name)}</h3>`;
        if(other)top+=`<p>Your alliance flag: <b>${esc(d.stance)}</b></p>${myLead?`<div class="alliance-tools">${flags}</div>`:''}`;
        if(myLead&&Number(g.bot_led))top+='<p>This bot clan answers offers on the next active bot turn. It accepts when both alliances are available.</p>';
        if(myLead&&d.canChallenge)top+=btn('Challenge to war','ask-warOffer',target(g.id),'primary');
        const time=t=>new Date(Number(t)*1000).toLocaleString();
        const rows=d.wars.map(w=>{
            const from=Number(w.challenger_id),to=Number(w.opponent_id),isFrom=from===ownId,isTo=to===ownId;
            const score=`${num(w.from_score)} – ${num(w.to_score)}`;
            const props=`data-war="${Number(w.id)}" ${target(isFrom?to:from)}`;
            let controls='',info='';
            if(w.state==='pending'){
                info=`Challenge expires ${esc(time(w.ends_at))}`;
                if(d.canLead&&isFrom)controls=btn('Withdraw challenge','warWithdraw',props);
                if(d.canLead&&isTo)controls=btn('Accept war','ask-warAccept',props,'primary')+btn('Decline','warDecline',props);
            }
            if(w.state==='active')info=`At war until ${esc(time(w.ends_at))}`;
            if(w.state==='finished')info=Number(w.from_score)===Number(w.to_score)?'Draw':`${esc(Number(w.from_score)>Number(w.to_score)?w.challenger_name:w.opponent_name)} won`;
            const targets=w.targets.length?`<details><summary>Enemy keepers (${num(w.targets.length)})</summary><ul class="alliance-list">${w.targets.map(k=>`<li><span>${esc(k.name)}${Number(k.is_bot)?' · bot':''}</span><button type="button" class="button small subtle" data-attack="${Number(k.id)}" ${k.blocked||state.battle?.status==='active'?'disabled':''}>${esc(k.blocked||'Invade')}</button></li>`).join('')}</ul></details>`:'';
            return `<article class="alliance-war"><small>${esc(w.state)}</small><h4>${esc(w.challenger_name)} vs. ${esc(w.opponent_name)}</h4>${w.starts_at?`<b class="alliance-score">${score}</b>`:''}<p>${info}</p><div class="alliance-tools">${controls}</div>${targets}</article>`;
        }).join('');
        const pager=d.pages>1?`<div class="alliance-pager">${btn('Newer','warPage',target(d.page-1)+(d.page<=1?' disabled':''))}<span>${d.page} / ${d.pages}</span>${btn('Older','warPage',target(d.page+1)+(d.page>=d.pages?' disabled':''))}</div>`:'';
        return top+'<p>Both leaders must accept a 12-hour war. A ranked battle between opposing roster members earns one war point for the winner when an attacker deploys and the battle settles before the deadline.</p>'+btn('Refresh scores','refresh')+(rows||empty('No recent wars or challenges.'))+pager;
    }

    function powersBoard() {
        const powers=view.powers;
        if(!powers?.rows)return empty('Join an alliance to share its power-ups.');
        const effects={
            armament:'Towers and walls have 50% more health. Traps deal 25% more damage.',
            conquest:'Reduces invasion and takeover fees by 25%. World Map captures currently cost 0.',
            declarewar:'Extends World Map reach by two cells, adds 25% Flinger space and allows seven-minute surface invasions.'
        };
        const rows=Object.entries(powers.rows).map(([type,p])=>{
            const ref=`data-power="${type}" data-revision="${Number(p.revision)}"`;
            const ending=esc(new Date(Number(p.end)*1000).toLocaleString());
            const when=p.status==='ready'?'Ready to activate':p.status==='running'?`Active until ${ending}`:`Charging until ${ending}`;
            let controls='';
            if(p.status==='charging'){
                const max=Math.min(Number(p.hours),Math.floor(Number(powers.shiny)/powers.price));
                controls=max>0?`<label>Hours <input type="number" min="1" max="${max}" step="1" value="1" data-power-hours="${type}"></label>${btn('Contribute '+powers.price+' Shiny','powerCharge',ref)}`:`<span>You need ${powers.price} Shiny to contribute an hour.</span>`;
            }
            if(p.status==='ready')controls=powers.leader?btn('Activate '+esc(p.label),'powerActivate',ref,'primary'):'Your leader can activate this power-up.';
            const last=p.last?esc(p.last.kind==='charge'?`${p.last.by} contributed ${num(p.last.cost)} Shiny for ${num(p.last.hours)} h.`:`${p.last.by} activated this power-up.`):'';
            return `<article class="alliance-war alliance-power" data-power-card="${type}"><h4>${esc(p.label)}</h4><strong>${when}</strong><p>${effects[type]}</p><small>Active for ${p.run/3600} h · Recharges in ${p.recharge/86400} days</small><div class="alliance-tools">${controls}</div>${last?`<p class="alliance-power-log">${last}</p>`:''}</article>`;
        }).join('');
        return `<div class="alliance-power-balance"><b>${num(powers.shiny)} Shiny</b>${btn('Refresh status','refresh')}</div><p>Any member can contribute ${powers.price} Shiny per hour. Your leader can activate a fully charged power-up for everyone.</p>${rows}`;
    }
    function updateCost(e) {
        const input=e.target.closest?.('[data-power-hours]');
        if(!input)return;
        const button=input.closest('article')?.querySelector('[data-a="powerCharge"]');
        if(!button)return;
        const hours=Number(input.value);
        button.disabled=!Number.isInteger(hours)||hours<1||hours>Number(input.max);
        button.textContent=button.disabled?'Choose available hours':`Contribute ${num(hours*view.powers.price)} Shiny`;
    }

    function feedRows() {
        return (view.feed||[]).slice().reverse().map(row=>{
            const date=new Date(row.at*1000);
            return `<li data-feed-id="${Number(row.id)}"><div><b>${esc(row.name)}</b><time datetime="${date.toISOString()}">${esc(date.toLocaleString())}</time></div><p>${esc(row.body)}</p></li>`;
        }).join('');
    }

    function feedBoard() {
        return `<form class="alliance-form" data-form="message"><label>Message your alliance<input name="message" maxlength="200" required value="${esc(draft)}" autocomplete="off"></label><button class="button primary">Post message</button></form>
            <ol class="alliance-feed" aria-label="Latest alliance messages">${feedRows()}</ol>`;
    }

    function scheduleFeed() {
        clearTimeout(feedTimer);
        if(tab!=='feed'||!mine())return;
        const owner=state.user.id,group=Number(view.mine),seq=sequence;
        feedTimer=setTimeout(async()=>{
            const current=()=>seq===sequence&&isOpen(owner)&&tab==='feed'&&Number(view.mine)===group;
            if(!current())return;
            try{
                const data=await api('alliances',undefined,{feedOnly:'1',allianceId:group});
                if(!current())return;
                if(!data.member){draft='';tab='directory';focus=0;await load();return;}
                const newest=rows=>rows?.[rows.length-1]?.id||0;
                if(newest(data.feed)!==newest(view.feed)){
                    view.feed=data.feed;const list=root.querySelector('.alliance-feed');if(list)list.innerHTML=feedRows();
                }
            }catch(error){if(current())feedback(error.message);}
            if(current())scheduleFeed();
        },5000);
    }

    function editForm() {
        const create=tab==='create';
        return `<form class="alliance-form" data-form="${create?'create':'edit'}">${create?'<label>Alliance name<input name="name" required minlength="3" maxlength="30" autocomplete="off"></label>':''}<label>Description<input name="description" maxlength="200" value="${esc(create?'':view.detail?.description)}" autocomplete="off"></label><button class="button primary">${create?'Found alliance':'Save description'}</button></form>`;
    }

    function render() {
        clearTimeout(powerTimer);scheduleFeed();
        if(tab==='powers'&&view.powers?.rows) {
            const deadlines=Object.values(view.powers.rows).filter(p=>p.status!=='ready').map(p=>Number(p.end)-view.powers.now);
            if(deadlines.length){
                const owner=state.user.id;
                powerTimer=setTimeout(()=>{if(isOpen(owner)&&tab==='powers'&&!busy)load();},Math.max(1,Math.min(...deadlines)+1)*1000);
            }
        }
        const tabs=[['directory','All alliances']];
        if(view?.mine)tabs.push(['mine','My alliance'],['feed','Alliance feed'],['powers','Power-ups']);
        if(leader())tabs.push(['recruit','Recruit']);
        if(view?.detail)tabs.push(['wars','War board']);
        tabs.push(['inbox',`Inbox${view?.inbox.length?` (${num(view.inbox.length)})`:''}`]);
        const active=tab==='detail'&&mine()?'mine':tab;
        const nav=tabs.map(([id,label])=>`<button type="button" data-a="tab" data-tab="${id}" aria-pressed="${active===id}">${label}</button>`).join('');
        let content;
        if(confirm) {
            const keeper=view.roster.find(m=>Number(m.id)===confirm.keeperId);
            const g=view.detail;
            const message=confirm.op==='warOffer'?'Send a war challenge to this alliance? Its leader has 24 hours to answer.':confirm.op==='warAccept'?'Begin a 12-hour war now? Both current rosters will be recorded for this war.':confirm.op==='kick'?`Remove ${esc(keeper?.name)} from ${esc(g.name)}?`:confirm.op==='transfer'?`Make ${esc(keeper?.name)} leader? You will become a regular member.`:view.roster.length===1?`Disband ${esc(g.name)}? Pending invitations and requests will close.`:`Leave ${esc(g.name)}?`;
            content=`<p>${message}</p><div class="alliance-tools">${btn('Cancel','cancel')}${btn('Confirm','confirm','','danger')}</div>`;
        } else {
            content=tab==='feed'?feedBoard():tab==='powers'?powersBoard():tab==='wars'?warBoard():tab==='directory'?directory():tab==='inbox'?inbox():tab==='recruit'?recruits():['edit','create'].includes(tab)?editForm():roster();
        }
        root.innerHTML=`<nav class="alliance-tabs" aria-label="Alliance views">${nav}</nav>${content}<p class="alliance-feedback" role="status"></p>`;
    }

    function feedback(message) {
        const slot=root.querySelector('.alliance-feedback');
        if(slot)slot.textContent=message;
        else root.textContent=message;
    }

    async function load() {
        const owner=state.user?.id, request=++sequence;
        try {
            const params={search,keeperSearch,page,warPage};
            if(focus)params.allianceId=focus;
            const data=await api('alliances',undefined,params);
            if(request!==sequence||!isOpen(owner))return;
            view=data;
            if(tab==='initial')tab=data.mine?'detail':'directory';
            if(tab==='recruit'&&!leader())tab='detail';
            if(tab==='feed'&&!mine()){draft='';sendKey='';tab='directory';}
            render();
        } catch(e) {
            if(request!==sequence||!isOpen(owner))return;
            if(!view)root.innerHTML=btn('Try again','refresh')+'<p class="alliance-feedback" role="status"></p>';
            feedback(e.message);
        }
    }

    async function write(payload) {
        if(busy)return;
        const owner=state.user?.id,request=++sequence;
        busy=true;
        root.setAttribute('aria-busy','true');
        try {
            const data=await api('allianceManage',payload);
            if(request!==sequence||!isOpen(owner))return;
            view=data; focus=Number(data.detail?.id)||0; page=1; warPage=1;search='';keeperSearch='';confirm=null;
            if(payload.op.startsWith('power')){tab='powers';if(state.base&&data.powers)state.base.shiny=data.powers.shiny;}
            if(payload.op==='stance'||payload.op.startsWith('war'))tab='wars';
            if(['create','edit','transfer','kick'].includes(payload.op))tab='detail';
            if(payload.op==='message'){draft='';sendKey='';tab='feed';}
            if(payload.op==='leave')tab='directory';
            if(['accept','withdraw','decline','request'].includes(payload.op))tab='inbox';
            render();
            toast(({message:'Alliance message posted.',powerCharge:'Recharge contribution saved.',powerActivate:'Alliance power-up activated.',stance:'Diplomatic flag updated.',warOffer:'War challenge sent.',warAccept:'War started.',warDecline:'Challenge declined.',warWithdraw:'Challenge withdrawn.',create:'Alliance founded.',invite:'Recruitment updated.',request:'Join request sent.',transfer:'Leadership transferred.',kick:'Member removed.',leave:'You left the alliance.',edit:'Description saved.',accept:'Member joined the alliance.',decline:'Declined.',withdraw:'Withdrawn.'})[payload.op]);
        } catch(e) {
            if(request===sequence&&isOpen(owner)) {
                confirm=null;
                if(view)render();
                feedback(e.message+' Refresh to see the latest roster.');
                root.querySelector('.alliance-feedback')?.insertAdjacentHTML('afterend',btn('Refresh','refresh'));
            }
        } finally {
            if(isOpen(owner)&&request===sequence){busy=false;root.removeAttribute('aria-busy');}
        }
    }

    async function click(e) {
        const el=e.target.closest('[data-a]');
        if(!el||busy||el.disabled)return;
        const op=el.dataset.a,id=Number(el.dataset.id)||0;
        if(op==='warPage'){warPage=id;await load();return;}
        if(op==='refresh'){await load();return;}
        if(op==='tab'||op==='detail'||op==='page') {
            confirm=null;warPage=1;
            if(op==='page'){page=id;tab='directory';}
            if(op==='detail'){focus=id;tab='detail';}
            if(op==='tab'){tab=el.dataset.tab;if(tab==='powers'||tab==='feed')focus=view.mine;if(tab==='mine'){focus=view.mine;tab='detail';}}
            await load();return;
        }
        if(op==='create'||op==='edit'){tab=op;confirm=null;render();root.querySelector('input')?.focus();return;}
        if(op==='cancel'){confirm=null;render();return;}
        if(op==='powerCharge'||op==='powerActivate'){
            const type=el.dataset.power;
            const payload={op,type,allianceId:Number(view.mine),powerRevision:Number(el.dataset.revision),requestId:Array.from(crypto.getRandomValues(new Uint8Array(12)),n=>n.toString(16).padStart(2,'0')).join('')};
            if(op==='powerCharge'){
                const input=el.closest('article').querySelector('[data-power-hours]');
                if(!input.reportValidity())return;
                payload.hours=Number(input.value);
            }
            await write(payload);return;
        }
        if(op==='stance'){await write({op,allianceId:Number(view.mine),otherId:Number(view.detail.id),stance:el.dataset.stance});return;}
        if(['warDecline','warWithdraw','ask-warAccept','ask-warOffer'].includes(op)){
            const payload={op:op.replace('ask-',''),allianceId:Number(view.mine),otherId:id,warId:Number(el.dataset.war)||0};
            if(op.startsWith('ask-')){confirm=payload;render();root.querySelector('[data-a="cancel"]')?.focus();}else{await write(payload);}
            return;
        }
        if(op.startsWith('ask-')){confirm={op:op.slice(4),allianceId:Number(view.detail.id),keeperId:id,revision:Number(view.detail.revision)};render();root.querySelector('[data-a="cancel"]')?.focus();return;}
        if(op==='confirm'&&confirm){await write(confirm);return;}
        const allianceId=Number(el.dataset.group||view.detail?.id);
        await write({op,allianceId,keeperId:id,requestId:Number(el.dataset.request)||0});
    }

    async function submit(e) {
        e.preventDefault();if(busy)return;
        const form=e.target,data=new FormData(form);
        if(form.dataset.form==='search'){search=data.get('search');page=1;await load();return;}
        if(form.dataset.form==='message'){
            const text=String(data.get('message')||'').trim();if(!text)return;
            if(!sendKey){sendAfter=view.feed?.at(-1)?.id||0;sendKey=Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');}
            await write({op:'message',allianceId:Number(view.mine),message:text,key:sendKey,after:sendAfter});return;
        }
        if(form.dataset.form==='keepers'){keeperSearch=data.get('search');await load();return;}
        await write({op:form.dataset.form,allianceId:Number(view.detail?.id)||0,revision:Number(view.detail?.revision)||0,name:data.get('name'),description:data.get('description')});
    }

    return {async open(){
        if(!state.user)return;
        clearTimeout(feedTimer);draft='';sendKey='';tab='initial'; view=null; search='';keeperSearch='';page=1;warPage=1;focus=0;busy=false;confirm=null;
        showModal('<h2 id="modal-title">Alliances</h2><section id="alliance-hall" class="alliance-hall"><p role="status">Loading alliances…</p></section>','alliances');
        root=document.getElementById('alliance-hall');
        root.addEventListener('input',e=>{if(e.target.name==='message'){draft=e.target.value;sendKey='';}updateCost(e);});root.addEventListener('click',click);root.addEventListener('submit',submit);await load();
    }};
}
