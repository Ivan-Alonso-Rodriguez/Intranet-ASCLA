/* Private PDF reader. Extracted text is available to assistive technology; scripts are never run. */
globalThis.ASCLAPdfPreview = ({root,config:C,T=text=>text}) => {
  const states=new Map();let library;
  const current=(node,state)=>states.get(node)===state && node.open;
  const message=(node,text)=>{node.querySelector('.pdf-status').textContent=T(text);};
  const busy=(node,value)=>{
    node.querySelector('.pdf-page').setAttribute('aria-busy',String(value));
    node.querySelectorAll('[data-pdf-step],[data-pdf-zoom]').forEach(control=>{control.disabled=value;});
  };
  function controls(node,state) {
    node.querySelector('[data-pdf-step="-1"]').disabled=state.page<=1;
    node.querySelector('[data-pdf-step="1"]').disabled=state.page>=state.doc.numPages;
    node.querySelector('.pdf-position').textContent=`${T('Página')} ${state.page} ${T('de')} ${state.doc.numPages}`;
  }
  function dispose(node) {
    const state=states.get(node);if(!state)return;
    states.delete(node);clearTimeout(state.timer);state.abort.abort();state.observer?.disconnect();
    state.render?.cancel();
    const finish=()=>{state.worker?.terminate();if(state.workerUrl)URL.revokeObjectURL(state.workerUrl);};
    if(state.task)void state.task.destroy().catch(()=>{}).finally(finish);else finish();
    const canvas=node.querySelector('canvas');canvas.width=canvas.height=0;canvas.hidden=true;
    node.querySelector('.pdf-extracted-text').textContent='';
    node.querySelector('.pdf-position').textContent='';message(node,'');busy(node,true);
    delete node.dataset.pdfPage;delete node.dataset.pdfPages;
  }
  async function render(node,state) {
    if(!current(node,state)||state.busy)return;
    state.busy=true;busy(node,true);message(node,'Cargando página…');
    try {
      const page=await state.doc.getPage(state.page);if(!current(node,state))return;
      const panel=node.querySelector('.pdf-page'),canvas=panel.querySelector('canvas');
      canvas.hidden=false;
      const width=Math.max(240,panel.clientWidth-24),base=page.getViewport({scale:1});
      const scale=Math.min(width/base.width*(state.zoom/100)*Math.min(devicePixelRatio||1,2),3,Math.sqrt(12000000/(base.width*base.height)));
      const viewport=page.getViewport({scale});
      canvas.width=Math.ceil(viewport.width);canvas.height=Math.ceil(viewport.height);
      panel.dataset.zoom=String(state.zoom);state.width=panel.clientWidth;
      state.render=page.render({canvas,viewport,background:'rgb(255,255,255)'});
      await state.render.promise;if(!current(node,state))return;
      const text=await page.getTextContent();if(!current(node,state))return;
      const extracted=text.items.map(item=>item.str+(item.hasEOL?'\n':' ')).join('').trim();
      node.querySelector('.pdf-extracted-text').textContent=extracted;
      canvas.setAttribute('aria-label',node.dataset.pdfName+' · '+T('Página')+' '+state.page);
      node.dataset.pdfPage=String(state.page);node.dataset.pdfPages=String(state.doc.numPages);
      message(node,'');
    } catch(error) {
      if(current(node,state)&&error.name!=='RenderingCancelledException')message(node,'No se pudo mostrar esta página. Puedes descargar el PDF.');
    } finally {
      state.busy=false;if(current(node,state)){busy(node,false);controls(node,state);}
    }
  }
  async function open(node) {
    if(states.has(node))return;
    const state={page:1,zoom:Number(node.querySelector('[data-pdf-zoom]').value),abort:new AbortController(),busy:false};states.set(node,state);
    busy(node,true);message(node,'Cargando PDF…');
    try {
      const url=new URL(node.dataset.pdfUrl,location.href);
      if(url.origin!==location.origin)throw Error('Private origin required');
      library ||= import(C.pdfModule).catch(error=>{library=null;throw error;});
      const pdf=await library;if(!current(node,state))return;
      pdf.GlobalWorkerOptions.workerSrc=C.pdfWorker;
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:state.abort.signal});
      if(!response.ok||!response.headers.get('Content-Type')?.startsWith('application/pdf'))throw Error('PDF unavailable');
      const data=await response.arrayBuffer();if(data.byteLength>6*1024*1024)throw Error('PDF too large');
      if(!current(node,state))return;
      // A blob entry inherits the page's isolation policy; its only import is our pinned local worker.
      const workerUrl=new URL(C.pdfWorker,location.href);if(workerUrl.origin!==location.origin)throw Error('Private worker origin required');
      state.workerUrl=URL.createObjectURL(new Blob(['import '+JSON.stringify(workerUrl.href)+';'],{type:'text/javascript'}));
      state.worker=new Worker(state.workerUrl,{type:'module'});
      state.pdfWorker=new pdf.PDFWorker({port:state.worker});
      state.task=pdf.getDocument({data:new Uint8Array(data),worker:state.pdfWorker,disableFontFace:true,useSystemFonts:true,useWasm:false,useWorkerFetch:false,enableXfa:false,isEvalSupported:false,verbosity:0,maxImageSize:16000000});
      state.doc=await state.task.promise;if(!current(node,state))return;
      await render(node,state);
      state.observer=new ResizeObserver(()=>{
        if(Math.abs(node.querySelector('.pdf-page').clientWidth-state.width)<8)return;
        clearTimeout(state.timer);state.timer=setTimeout(()=>{if(current(node,state))void render(node,state);},180);
      });state.observer.observe(node.querySelector('.pdf-page'));
    } catch(error) {
      if(!current(node,state)||error.name==='AbortError')return;
      message(node,error.name==='PasswordException'?'Este PDF requiere una contraseña. Descárgalo para abrirlo con tu lector.':'No se pudo previsualizar este PDF. Puedes descargarlo para abrirlo con tu lector.');
    }
  }
  root.addEventListener('toggle',event=>{
    if(!event.target.matches?.('.pdf-preview'))return;
    if(event.target.open)void open(event.target);else dispose(event.target);
  },true);
  root.addEventListener('click',event=>{
    const button=event.target.closest?.('[data-pdf-step]');if(!button||button.disabled)return;
    const node=button.closest('.pdf-preview'),state=states.get(node);if(!state?.doc||state.busy)return;
    event.preventDefault();state.page=Math.max(1,Math.min(state.doc.numPages,state.page+Number(button.dataset.pdfStep)));void render(node,state);
  });
  root.addEventListener('change',event=>{
    if(!event.target.matches?.('[data-pdf-zoom]'))return;
    const node=event.target.closest('.pdf-preview'),state=states.get(node);if(!state?.doc||state.busy)return;
    state.zoom=Number(event.target.value);void render(node,state);
  });
  return {
    mount(scope){for(const node of scope.querySelectorAll('.pdf-preview[open]'))void open(node);},
    release(scope){for(const node of states.keys())if(node===scope||scope?.contains(node))dispose(node);}
  };
};
