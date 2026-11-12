(() => {
  function showSource(){const source=document.getElementById(location.hash.slice(1));if(source?.classList.contains('source-file')){source.open=true;source.scrollIntoView({block:'start'});}}
  window.addEventListener('hashchange',showSource);showSource();
  const rows=[...document.querySelectorAll('[data-case]')],search=document.querySelector('[data-search]');
  let filter='all';
  function update(){
    const query=(search?.value||'').trim().toLocaleLowerCase('es');let shown=0;
    for(const row of rows){const visible=(filter==='all'||row.dataset.state===filter)&&row.textContent.toLocaleLowerCase('es').includes(query);row.hidden=!visible;if(visible)shown++;}
    const count=document.querySelector('[data-filter-count]');if(count)count.textContent=shown+' de '+rows.length+' casos';
    const empty=document.querySelector('[data-no-results]');if(empty)empty.hidden=shown!==0;
  }
  search?.addEventListener('input',update);
  for(const button of document.querySelectorAll('[data-filter]'))button.addEventListener('click',()=>{filter=button.dataset.filter;for(const other of document.querySelectorAll('[data-filter]')){const selected=other===button;other.classList.toggle('selected',selected);other.setAttribute('aria-pressed',String(selected));}update();});
  document.querySelector('[data-expand]')?.addEventListener('click',event=>{const visible=rows.filter(row=>!row.hidden),open=visible.some(row=>!row.open);visible.forEach(row=>row.open=open);event.currentTarget.textContent=open?'Contraer casos':'Expandir casos';});
  for(const video of document.querySelectorAll('video'))video.addEventListener('play',()=>video.classList.add('is-playing'));
  for(const button of document.querySelectorAll('[data-video]'))button.addEventListener('click',()=>{const panel=button.closest('.evidence-panel'),video=panel.querySelector('video');video.pause();video.classList.remove('is-playing');video.src=button.dataset.video;video.poster=button.dataset.poster||'';video.load();for(const tab of panel.querySelectorAll('[data-video]')){tab.classList.toggle('selected',tab===button);tab.setAttribute('aria-pressed',String(tab===button));}});
  document.querySelector('[data-print]')?.addEventListener('click',()=>window.print());
  let printStates=[];window.addEventListener('beforeprint',()=>{printStates=[...document.querySelectorAll('details')].map(element=>({element,open:element.open}));printStates.forEach(({element})=>element.open=!element.classList.contains('source-file'));});window.addEventListener('afterprint',()=>printStates.forEach(({element,open})=>element.open=open));
  update();
})();
