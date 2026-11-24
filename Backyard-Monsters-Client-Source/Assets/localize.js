(() => {
  const data = JSON.parse(document.getElementById('yard-language-data').textContent);
  const t = key => Object.prototype.hasOwnProperty.call(data.strings,key) ? data.strings[key] : key;
  function translate(root) {
    if (!root || data.code === 'en') return;
    const text = document.createTreeWalker(root,NodeFilter.SHOW_TEXT);
    while (text.nextNode()) {
      const node = text.currentNode;
      if (node.parentElement?.closest('option,script,style,[data-user-text]')) continue;
      const value=node.nodeValue.trim();
      if (value && t(value)!==value) node.nodeValue=node.nodeValue.replace(value,t(value));
    }
    root.querySelectorAll('[aria-label],[placeholder],[title]').forEach(el=>{
      for (const attr of ['aria-label','placeholder','title']) if(el.hasAttribute(attr))el.setAttribute(attr,t(el.getAttribute(attr)));
    });
  }
  function picker(){
    const label=document.createElement('label');
    label.className='yard-language';
    label.append(document.createTextNode(t('Language')));
    const select=document.createElement('select');
    select.setAttribute('aria-label',t('Language'));
    for(const [code,name] of Object.entries(data.names))select.add(new Option(name,code,false,code===data.code));
    select.addEventListener('change',()=>{
      if (!Object.prototype.hasOwnProperty.call(data.names,select.value))return;
      // Scope preferences to this installation, including installations in htdocs subdirectories.
      const path=location.pathname.substring(0,location.pathname.lastIndexOf('/')+1);
      document.cookie='yard_language='+select.value+'; Path='+encodeURI(path)+'; SameSite=Lax; Max-Age=31536000';
      location.reload();
    });
    label.append(select);
    return label;
  }
  for(const id of ['app','boot','modal-close'])translate(document.getElementById(id));
  document.getElementById('yard-language-slot').append(picker());
  window.yardLanguage={t,translate,picker,code:data.code};
})();
