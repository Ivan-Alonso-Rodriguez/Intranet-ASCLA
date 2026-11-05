/* Local, inert conversion of tabular exports. The server validates the resulting CSV again. */
(() => {
  'use strict';
  const MAX=524288,MAX_SOURCE=5242880,decoder=new TextDecoder('utf-8',{fatal:true});
  const fail=message=>{throw Error(message);};
  const norm=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/\s+/g,' ').trim();
  const identity=s=>/^(nombre(s)?( completo)?( [12])?|apellido(s)?( [12])?|correo( electronico)?|direccion de correo electronico|e-?mail|name|first name|last name)$/.test(norm(s));
  function promiseCompatibility() {
    if(typeof Promise.withResolvers!=='function')Promise.withResolvers=function(){let resolve,reject;const promise=new this((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
    if(typeof Promise.try!=='function')Promise.try=function(callback,...args){return new this(resolve=>resolve(callback(...args)));};
  }
  function csvTable(table) {
    if(table.length<2||table.length>5001||table[0].length>100)fail('No se encontró una tabla de respuestas válida (máximo 5000 filas).');
    const csv=table.map(row=>row.map(cell=>'"'+String(cell).replaceAll('"','""')+'"').join(',')).join('\n');
    if(new TextEncoder().encode(csv).length>MAX)fail('La tabla supera 512 KB. Divide el archivo en varios lotes.');
    return csv;
  }
  function htmlTable(source) {
    const template=document.createElement('template');template.innerHTML=source;
    template.content.querySelectorAll('script,style,iframe,object,embed,link,meta,svg').forEach(n=>n.remove());
    const candidates=[];
    for(const table of template.content.querySelectorAll('table')) {
      const rows=[...table.rows].map(row=>[...row.cells].map(cell=>cell.textContent.replace(/\s+/g,' ').trim()));
      const start=rows.findIndex(row=>row.filter(identity).length>=2);
      if(start<0)continue;
      const indices=rows[start].map((s,i)=>s&&!(i===0&&/^\d+$/.test(s))?i:-1).filter(i=>i>=0);
      if(indices.length>100)fail('La tabla tiene demasiadas columnas.');
      const data=rows.slice(start).map(row=>indices.map(i=>row[i]||'')).filter(row=>row.some(Boolean));
      if(data.length>1)candidates.push(data);
    }
    if(candidates.length!==1)fail('Selecciona una sola hoja de respuestas con encabezados de nombre o correo. Puedes exportarla como CSV.');
    return candidates[0];
  }
  async function unzip(buffer) {
    const data=new Uint8Array(buffer),v=new DataView(buffer);let end=-1;
    for(let i=data.length-22;i>=Math.max(0,data.length-65557);i--)if(v.getUint32(i,true)===0x06054b50){end=i;break;}
    if(end<0||v.getUint16(end+4,true)||v.getUint16(end+6,true))fail('El ZIP no es válido o está dividido en varios archivos.');
    const count=v.getUint16(end+10,true),offset=v.getUint32(end+16,true);let at=offset,selected=[];
    if(count>100||offset>=end)fail('El ZIP contiene demasiados archivos o no es válido.');
    for(let i=0;i<count;i++) {
      if(at+46>end||v.getUint32(at,true)!==0x02014b50)fail('El índice del ZIP no es válido.');
      const flags=v.getUint16(at+8,true),method=v.getUint16(at+10,true),size=v.getUint32(at+24,true),packed=v.getUint32(at+20,true),n=v.getUint16(at+28,true),extra=v.getUint16(at+30,true),comment=v.getUint16(at+32,true),local=v.getUint32(at+42,true);
      if(at+46+n+extra+comment>end)fail('El índice del ZIP está incompleto.');
      const name=decoder.decode(data.subarray(at+46,at+46+n));at+=46+n+extra+comment;
      if(!/\.(html?|csv)$/i.test(name))continue; // CSS, images and nested archives are never decompressed or loaded.
      if(flags&1||!['0','8'].includes(String(method))||size>MAX_SOURCE||packed>MAX_SOURCE||size>Math.max(1,packed)*200||local+30>offset||v.getUint32(local,true)!==0x04034b50)fail('La hoja del ZIP está cifrada, es demasiado grande o no es compatible.');
      const begin=local+30+v.getUint16(local+26,true)+v.getUint16(local+28,true);
      if(begin+packed>offset)fail('El contenido del ZIP está incompleto.');
      selected.push({name,method,size,bytes:data.subarray(begin,begin+packed)});
    }
    if(selected.length!==1)fail('El ZIP debe contener una sola hoja HTML o CSV. Exporta cada hoja por separado.');
    const entry=selected[0];let bytes=entry.bytes;
    if(entry.method===8) {
      if(typeof DecompressionStream!=='function')fail('Este navegador no puede leer ZIP. Extrae la hoja HTML o exporta un CSV.');
      const reader=new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate-raw')).getReader();let chunks=[],length=0;
      try {for(;;){const {value,done}=await reader.read();if(done)break;length+=value.length;if(length>entry.size||length>MAX_SOURCE)fail('La hoja descomprimida supera el tamaño permitido.');chunks.push(value);}}
      finally {await reader.cancel();}
      bytes=new Uint8Array(length);let pos=0;for(const chunk of chunks){bytes.set(chunk,pos);pos+=chunk.length;}
    }
    if(bytes.length!==entry.size)fail('El tamaño de la hoja no coincide con el índice del ZIP.');
    return {name:entry.name,text:decoder.decode(bytes)};
  }
  function pdfTable(pdf,viewport,text,ops) {
    if(text.items.length>100000||ops.fnArray.length>300000)fail('El PDF contiene una página demasiado compleja. Exporta la hoja como CSV.');
    const transform=(m,x,y)=>[m[0]*x+m[2]*y+m[4],m[1]*x+m[3]*y+m[5]];
    const items=text.items.filter(t=>t.str?.trim()).map(t=>{const [x,y]=transform(viewport.transform,t.transform[4],t.transform[5]);return {s:t.str.trim(),x,y,h:t.height,w:t.width};});
    const horizontal=[],vertical=[],stack=[];let matrix=[1,0,0,1,0,0];
    ops.fnArray.forEach((op,i)=>{
      const args=ops.argsArray[i];
      if(op===pdf.OPS.save)stack.push(matrix.slice());
      else if(op===pdf.OPS.restore)matrix=stack.pop()||[1,0,0,1,0,0];
      else if(op===pdf.OPS.transform)matrix=pdf.Util.transform(matrix,args);
      else if(op===pdf.OPS.constructPath&&args?.[1]&&args[0]!==pdf.OPS.endPath) {
        const m=pdf.Util.transform(viewport.transform,matrix);
        const segment=(a,b)=>{if(!a||!b)return;const [x,y]=transform(m,...a),[xx,yy]=transform(m,...b);
          if(Math.abs(y-yy)<1&&Math.abs(x-xx)>viewport.width*.45)horizontal.push({y:(y+yy)/2,left:Math.min(x,xx),right:Math.max(x,xx)});
          if(Math.abs(x-xx)<1&&Math.abs(y-yy)>8)vertical.push({x:(x+xx)/2,top:Math.min(y,yy),bottom:Math.max(y,yy)});
        };
        // PDF.js's packed path uses move/line/close commands. Curved paths are irrelevant to grid extraction.
        for(const path of args[1]){if(!path)continue;let last,start;for(let n=0;n<path.length;){const op=path[n++];if(op===0){last=start=[path[n++],path[n++]];}else if(op===1){const next=[path[n++],path[n++]];segment(last,next);last=next;}else if(op===4){segment(last,start);last=start;}else break;}}
      }
    });
    const unique=values=>values.sort((a,b)=>a-b).filter((v,i,list)=>!i||v-list[i-1]>2);
    const ys=unique(horizontal.map(l=>l.y)),bands=[];
    for(let i=1;i<ys.length;i++)if(ys[i]-ys[i-1]>8&&ys[i]-ys[i-1]<viewport.height*.2)bands.push({top:ys[i-1],bottom:ys[i]});
    const inBand=(t,b)=>t.y>b.top+1&&t.y<=b.bottom+1;
    const header=bands.find(b=>items.filter(t=>inBand(t,b)&&identity(t.s)).length>=2);
    if(!header){
      if(bands.length>2&&vertical.length>3)fail('El PDF tiene tablas sin identidad completa o columnas divididas entre páginas. Exporta la hoja completa como CSV o HTML.');
      return null;
    }
    const boundaries=unique(vertical.filter(l=>l.top<header.bottom-2&&l.bottom>header.top+2).map(l=>l.x));
    if(boundaries.length<3||boundaries.length>101)fail('No se pudieron separar las columnas del PDF. Exporta esta hoja como CSV.');
    const cells=band=>{
      const row=boundaries.slice(1).map(()=>[]);
      for(const t of items.filter(t=>inBand(t,band)&&t.x>=boundaries[0]-2&&t.x<boundaries.at(-1))){const col=boundaries.slice(0,-1).findLastIndex(x=>t.x>=x-1);if(col>=0)row[col].push(t);}
      return row.map(parts=>parts.sort((a,b)=>Math.abs(a.y-b.y)>3?a.y-b.y:a.x-b.x).map(t=>t.s).join(' '));
    };
    const rows=[cells(header)];
    for(const band of bands.filter(b=>b.top>=header.bottom-2)){
      const row=cells(band);
      for(let col=0;col<row.length-1;col++){
        if(identity(rows[0][col])&&identity(rows[0][col+1])&&row[col]&&!row[col+1]&&items.some(t=>inBand(t,band)&&t.x>=boundaries[col]-1&&t.x<boundaries[col+1]&&t.x+t.w>boundaries[col+1]+4))fail('El PDF une campos de identidad entre columnas. Exporta la hoja como CSV o HTML para identificar correctamente a cada persona.');
      }
      if(row.some(Boolean))rows.push(row);
    }
    return rows;
  }
  async function readPdf(buffer,onProgress) {
    const moduleUrl=new URL(globalThis.ASCLA.pdfModule,location.href),workerUrl=new URL(globalThis.ASCLA.pdfWorker,location.href);
    if([moduleUrl,workerUrl].some(u=>u.origin!==location.origin))fail('El lector PDF debe cargarse desde ASCLA.');
    promiseCompatibility();const pdf=await import(moduleUrl.href);
    const entry=URL.createObjectURL(new Blob(['('+promiseCompatibility.toString()+')();const {WorkerMessageHandler}=await import('+JSON.stringify(workerUrl.href)+');export {WorkerMessageHandler};'],{type:'text/javascript'}));
    pdf.GlobalWorkerOptions.workerSrc=entry;const worker=new pdf.PDFWorker();
    const task=pdf.getDocument({data:new Uint8Array(buffer),worker,enableXfa:false,isEvalSupported:false,useWasm:false,useWorkerFetch:false,disableFontFace:true,verbosity:0});
    const timer=setTimeout(()=>{void task.destroy();},60000);
    try {
      const doc=await task.promise;
      if(doc.numPages>40)fail('El PDF supera 40 páginas. Importa las hojas de respuestas por separado.');
      let result=[],skipped=0;
      for(let n=1;n<=doc.numPages;n++) {
        onProgress?.(n,doc.numPages);const page=await doc.getPage(n);
        const text=await page.getTextContent(),table=pdfTable(pdf,page.getViewport({scale:1}),text,await page.getOperatorList());page.cleanup();
        if(!table){
          if(text.items.map(t=>t.str||'').join(' ').trim().length>80)fail('El PDF incluye páginas que no forman una tabla de respuestas completa. Exporta la hoja completa como CSV o HTML.');
          skipped++;continue;
        }
        if(!result.length)result=table;
        else if(JSON.stringify(table[0].map(norm))===JSON.stringify(result[0].map(norm)))result.push(...table.slice(1));
        else fail('El PDF contiene tablas con columnas distintas. Exporta cada hoja como CSV para evitar mezclar personas.');
        if(result.length>5001)fail('La tabla supera 5000 filas.');
      }
      if(!result.length)fail('No se encontró una tabla de respuestas legible. Los PDF escaneados y documentos narrativos deben convertirse a CSV.');
      return {csv:csvTable(result),warnings:skipped?['Hay páginas sin tabla de respuestas. Revisa el total de filas antes de continuar.']:[]};
    } finally {clearTimeout(timer);await task.destroy();worker.destroy();URL.revokeObjectURL(entry);}
  }
  globalThis.ASCLAInterestFileReader=async(file,onProgress)=>{
    const ext=file.name.split('.').pop().toLowerCase();
    if(!['csv','pdf','html','htm','zip'].includes(ext)||!file.size||file.size>(ext==='csv'?MAX:MAX_SOURCE))fail('Usa CSV hasta 512 KB o PDF, HTML o ZIP hasta 5 MB.');
    let converted={warnings:[]},text;
    if(ext==='pdf')converted=await readPdf(await file.arrayBuffer(),onProgress);
    else if(ext==='zip'){const entry=await unzip(await file.arrayBuffer());text=entry.text;converted.csv=/\.csv$/i.test(entry.name)?text:csvTable(htmlTable(text));}
    else {text=decoder.decode(await file.arrayBuffer());converted.csv=ext==='csv'?text:csvTable(htmlTable(text));}
    return {...converted,filename:file.name.replace(/\.[^.]+$/,'.csv'),mime:'text/csv',source_filename:file.name,source_type:ext,context:file.name.replace(/\.[^.]+$/,'').replace(/[_-]+/g,' ').slice(0,500)};
  };
})();
