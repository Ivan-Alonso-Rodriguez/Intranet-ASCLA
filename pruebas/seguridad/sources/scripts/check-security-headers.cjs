/* Independent HTTP verification. Does not run an attack or replace browser/CSP checks. */
const fs=require('node:fs'),path=require('node:path');
function assess(headers,status,url){
 const csp=headers.get('content-security-policy')||'',directives=new Map(csp.split(';').map(part=>{const [key,...values]=part.trim().split(/\s+/);return [key?.toLowerCase(),values];}));
 const has=(key,value)=>directives.get(key)?.includes(value),strict=key=>directives.has(key)&&!directives.get(key).some(v=>['*','http:','https:',"'unsafe-inline'","'unsafe-eval'"].includes(v));
 return [
 ['Transporte HTTPS',new URL(url).protocol==='https:'],
 ['HTTP HTML correcto',status>=200&&status<300&&/text\/html/i.test(headers.get('content-type')||'')],
 ['CSP impuesta presente',Boolean(csp)],['default-src restringido',has('default-src',"'none'")],
 ['script-src restringido con nonce',strict('script-src')&&has('script-src',"'strict-dynamic'")&&directives.get('script-src').length===2&&directives.get('script-src').some(v=>/^'nonce-[A-Za-z0-9+/=]{24,}'$/.test(v))],
 ['style-src restringido con nonce',strict('style-src')&&directives.get('style-src').some(v=>/^'nonce-[A-Za-z0-9+/=]{24,}'$/.test(v))],
 ['Eventos y estilos de atributo bloqueados',has('script-src-attr',"'none'")&&has('style-src-attr',"'none'")],
 ['Sin objetos',has('object-src',"'none'")],['Base limitada',has('base-uri',"'self'")||has('base-uri',"'none'")],
 ['Formularios limitados',has('form-action',"'self'")||has('form-action',"'none'")],
 ['Protección de marcos',has('frame-ancestors',"'self'")||has('frame-ancestors',"'none'")],
 ['Sin sniffing',headers.get('x-content-type-options')==='nosniff'],
 ['Anticlickjacking',/^(DENY|SAMEORIGIN)$/i.test(headers.get('x-frame-options')||'')],
 ['Referrer restringido',/^(same-origin|no-referrer|strict-origin-when-cross-origin)$/i.test(headers.get('referrer-policy')||'')],
 ['Permisos de navegador definidos',Boolean(headers.get('permissions-policy'))],
 ['Respuesta privada sin caché',/no-store/i.test(headers.get('cache-control')||'')],
 ['Sin X-Powered-By',!headers.has('x-powered-by')],
 ['Sin versión del servidor',!/[a-z][a-z-]*\/\d/i.test(headers.get('server')||'')],
 ['HSTS sobre HTTPS',new URL(url).protocol!=='https:'||Number((headers.get('strict-transport-security')||'').match(/max-age=(\d+)/)?.[1]||0)>=15552000],
 ].map(([nombre,cumple])=>({nombre,cumple:Boolean(cumple)}));
}
async function main(){
 const url=new URL(process.argv[2]||'');if(!['http:','https:'].includes(url.protocol)||url.username||url.password)throw Error('Use una URL HTTP(S) sin credenciales');
 const output=path.resolve(process.argv[3]||'test-results/security-latest/csp-validation.json');fs.mkdirSync(path.dirname(output),{recursive:true});
 const result={url:url.href,fecha:new Date().toISOString(),estado:'FAIL',validaciones:[]};
 try{const response=await fetch(url,{signal:AbortSignal.timeout(15000),redirect:'error'});result.validaciones=assess(response.headers,response.status,url.href);const first=response.headers.get('content-security-policy')?.match(/nonce-([^']+)/)?.[1];await response.body?.cancel();const second=await fetch(url,{signal:AbortSignal.timeout(15000),redirect:'error'});const nonce=second.headers.get('content-security-policy')?.match(/nonce-([^']+)/)?.[1];await second.body?.cancel();result.validaciones.push({nombre:'Nonce distinto entre respuestas',cumple:Boolean(first&&nonce&&first!==nonce)});result.estado=result.validaciones.every(c=>c.cumple)?'PASS':'FAIL';}catch(error){result.error=error.message;}
 fs.writeFileSync(output,JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result,null,2));if(result.estado!=='PASS')process.exitCode=1;
}
module.exports={assess};if(require.main===module)main().catch(error=>{console.error(error.message);process.exitCode=1;});
