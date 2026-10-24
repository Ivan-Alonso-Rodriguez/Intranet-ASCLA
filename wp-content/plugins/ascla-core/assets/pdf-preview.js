/* Private PDF reader. Extracted text is available to assistive technology; scripts are never run. */
globalThis.ASCLAPdfPreview = ({root,config:C,T=text=>text}) => {
  const states=new Map();let library,workerEntry,workerUsers=0,libraryRetries=0;
  // These small standard Promise APIs are absent in some otherwise supported Chromium versions.
  function installPromiseCompatibility() {
    if(typeof Promise.withResolvers!=='function')Object.defineProperty(Promise,'withResolvers',{configurable:true,writable:true,value:function(){
      let resolve,reject;const promise=new this((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};
    }});
    if(typeof Promise.try!=='function')Object.defineProperty(Promise,'try',{configurable:true,writable:true,value:function(callback,...args){
      return new this(resolve=>resolve(callback(...args)));
    }});
  }
  installPromiseCompatibility();
  const current=(node,state)=>states.get(node)===state && node.open && !state.failed;
  const failure=code=>Object.assign(new Error(code),{pdfCode:code});
  async function timed(promise,ms=25000) {
    let timer;
    try { return await Promise.race([promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(failure('timeout')),ms);})]); }
    finally { clearTimeout(timer); }
  }
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
    release(state);
    const canvas=node.querySelector('canvas');canvas.width=canvas.height=0;canvas.hidden=true;
    node.querySelector('.pdf-extracted-text').textContent='';
    node.querySelector('.pdf-position').textContent='';message(node,'');busy(node,true);
    node.querySelector('[data-pdf-retry]').hidden=true;
    delete node.dataset.pdfPage;delete node.dataset.pdfPages;delete node.dataset.pdfError;
  }
  function release(state) {
    if(state.released)return;state.released=true;
    const finish=()=>{
      state.pdfWorker?.destroy();
      if(state.workerEntry&&--workerUsers===0){URL.revokeObjectURL(workerEntry);workerEntry=null;}
    };
    if(state.task)void state.task.destroy().catch(()=>{}).finally(finish);else finish();
  }
  function failed(node,state,error) {
    const code=error.pdfCode||(error.name==='PasswordException'?'password':error.name==='InvalidPDFException'?'invalid':'reader');
    if(code==='reader'){library=null;libraryRetries++;}
    const messages={
      reader:'No se pudo cargar el lector PDF. Recarga la página o actualiza tu navegador e inténtalo nuevamente.',
      timeout:'La carga del PDF tardó demasiado. Comprueba tu conexión e inténtalo nuevamente.',
      network:'No se pudo descargar el PDF. Comprueba tu conexión e inténtalo nuevamente.',
      access:'El PDF no está disponible o no tienes permiso para verlo. Comprueba tu sesión y vuelve a intentarlo.',
      session:'El servidor no devolvió un PDF. Tu sesión puede haber caducado; vuelve a iniciar sesión e inténtalo nuevamente.',
      size:'Este PDF supera el tamaño permitido para previsualizar. Puedes descargarlo para abrirlo con tu lector.',
      invalid:'No se pudo previsualizar este PDF. El archivo está dañado o no es un PDF válido. Puedes descargarlo para revisarlo.',
      password:'Este PDF requiere una contraseña. Descárgalo para abrirlo con tu lector.'
    };
    state.failed=true;state.abort.abort();state.observer?.disconnect();state.render?.cancel();release(state);
    node.dataset.pdfError=code;message(node,messages[code]||messages.reader);busy(node,true);
    const canvas=node.querySelector('canvas');canvas.width=canvas.height=0;canvas.hidden=true;
    node.querySelector('.pdf-extracted-text').textContent='';
    node.querySelector('[data-pdf-retry]').hidden=false;
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
      if(current(node,state)&&error.name!=='RenderingCancelledException')failed(node,state,error);
    } finally {
      state.busy=false;if(current(node,state)){busy(node,false);controls(node,state);}
    }
  }
  async function open(node) {
    if(states.has(node))return;
    const state={page:1,zoom:Number(node.querySelector('[data-pdf-zoom]').value),abort:new AbortController(),busy:false};states.set(node,state);
    node.querySelector('[data-pdf-retry]').hidden=true;delete node.dataset.pdfError;
    busy(node,true);message(node,'Cargando PDF…');
    try {
      const url=new URL(node.dataset.pdfUrl,location.href);
      const moduleUrl=new URL(C.pdfModule,location.href),workerUrl=new URL(C.pdfWorker,location.href);
      if([url,moduleUrl,workerUrl].some(value=>value.origin!==location.origin))throw failure('reader');
      if(libraryRetries)moduleUrl.searchParams.set('pdf_retry',String(libraryRetries));
      library ||= import(moduleUrl.href).catch(error=>{library=null;throw error;});
      const pdf=await timed(library);if(!current(node,state))return;
      let response;
      try { response=await timed(fetch(url,{credentials:'same-origin',cache:'no-store',signal:state.abort.signal})); }
      catch(error){throw error.pdfCode?error:failure('network');}
      if(!response.ok)throw failure([401,403,404].includes(response.status)?'access':'network');
      const mime=(response.headers.get('Content-Type')||'').split(';')[0].trim().toLowerCase();
      if(!['application/pdf','application/octet-stream'].includes(mime))throw failure('session');
      if(Number(response.headers.get('Content-Length'))>6*1024*1024)throw failure('size');
      const data=await timed(response.arrayBuffer());if(data.byteLength>6*1024*1024)throw failure('size');
      if(!current(node,state))return;
      // Preserve isolation in a worker; exporting its handler also enables PDF.js's official main-thread fallback.
      workerEntry ||= URL.createObjectURL(new Blob(['('+installPromiseCompatibility.toString()+')(); const {WorkerMessageHandler}=await import('+JSON.stringify(workerUrl.href)+'); export {WorkerMessageHandler};'],{type:'text/javascript'}));
      state.workerEntry=true;workerUsers++;pdf.GlobalWorkerOptions.workerSrc=workerEntry;
      state.pdfWorker=new pdf.PDFWorker();
      state.task=pdf.getDocument({data:new Uint8Array(data),worker:state.pdfWorker,disableFontFace:true,useSystemFonts:true,useWasm:false,useWorkerFetch:false,enableXfa:false,isEvalSupported:false,verbosity:0,maxImageSize:16000000});
      state.doc=await timed(state.task.promise);if(!current(node,state))return;
      await render(node,state);
      if(!current(node,state)||typeof ResizeObserver!=='function')return;
      state.observer=new ResizeObserver(()=>{
        if(Math.abs(node.querySelector('.pdf-page').clientWidth-state.width)<8)return;
        clearTimeout(state.timer);state.timer=setTimeout(()=>{if(current(node,state))void render(node,state);},180);
      });state.observer.observe(node.querySelector('.pdf-page'));
    } catch(error) {
      if(!current(node,state)||error.name==='AbortError')return;
      failed(node,state,error);
    }
  }
  root.addEventListener('toggle',event=>{
    if(!event.target.matches?.('.pdf-preview'))return;
    if(event.target.open)void open(event.target);else dispose(event.target);
  },true);
  root.addEventListener('click',event=>{
    const retry=event.target.closest?.('[data-pdf-retry]');
    if(retry){event.preventDefault();const node=retry.closest('.pdf-preview');dispose(node);void open(node);return;}
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
