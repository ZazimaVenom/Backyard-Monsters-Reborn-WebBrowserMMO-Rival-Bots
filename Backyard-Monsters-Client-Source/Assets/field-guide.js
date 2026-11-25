import {esc} from './ui.js?v=0.13.1';

export async function openFieldGuide({api,showModal,state,toast}) {
  const language=window.yardLanguage;
  const translate=language?.t??(v=>v);
  const current=state.modalEpoch;
  try {
    const data=await api('fieldGuide');
    if(current!==state.modalEpoch)return;
    const sections=['Bestiary','Structures','Inferno','Decorations','Training manual'];
    let section=sections[0];
    showModal(`<section class="field-guide"><h2 id="modal-title">${esc(translate('Field guide'))}</h2>
      <div class="field-guide-filters"><input type="search" aria-label="${esc(translate('Search the field guide'))}" placeholder="${esc(translate('Search the field guide'))}">
      <div class="field-guide-tabs">${sections.map(v=>`<button class="button subtle small" type="button" data-section="${esc(v)}" aria-pressed="${v===section}">${esc(translate(v))}</button>`).join('')}</div></div>
      <div class="field-guide-list" aria-live="polite"></div><button type="button" data-guide-primer class="button subtle small">${esc(translate('Gameplay guide'))}</button></section>`,'field-guide');
    const pane=document.querySelector('.field-guide');
    const search=pane.querySelector('input');
    const fold=s=>s.toLocaleLowerCase(data.language).normalize('NFD').replace(/[\u0300-\u036f]/g,'');
    function draw(){
      const needle=fold(search.value.trim());
      const records=data.entries.filter(row=>row.section===section&&(!needle||fold(row.name+' '+row.description).includes(needle)));
      pane.querySelector('.field-guide-list').innerHTML=records.length?records.map(row=>{
        const stats=Object.entries(row.stats).map(([key,n])=>`<span>${esc(translate(key))} <b>${esc(new Intl.NumberFormat(data.language,{maximumFractionDigits:3}).format(n))}</b></span>`).join('');
        return `<article><img loading="lazy" decoding="async" alt="" src="assets/${esc(row.image)}"><div><h3>${esc(row.name)}</h3><p>${esc(row.description)}</p><div class="field-guide-stats">${stats}</div></div></article>`;
      }).join(''):`<p>${esc(translate('No matching entries.'))}</p>`;
    }
    pane.querySelector('.field-guide-tabs').addEventListener('click',event=>{
      const button=event.target.closest('[data-section]');
      if(!button)return;
      section=button.dataset.section;
      for(const child of button.parentElement.children)child.setAttribute('aria-pressed',String(child===button));
      draw();
    });
    search.addEventListener('input',draw);
    draw();
  }catch(error){if(current===state.modalEpoch)toast(error.message,true);}
}
