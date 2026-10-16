/* Execute the versioned collection without writing credentials into Git files. */
const fs=require('node:fs'),path=require('node:path'),{spawnSync,execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),args=process.argv.slice(2);
const option=name=>{const i=args.indexOf(name);return i<0?null:args[i+1];};
const cliArgument=option('--cli')||process.env.BRU_BIN;
if(!cliArgument)throw Error('Indica --cli con la entrada JavaScript de Bruno CLI 4.2.1.');
const cli=path.resolve(cliArgument);
if(!fs.existsSync(cli))throw Error('No se encontró la entrada JavaScript de Bruno CLI.');
const access=JSON.parse(fs.readFileSync(path.resolve(option('--fixture')||path.join(root,'test-results/playwright-usuarios.json')),'utf8'));
const mode=require('./qa-access.cjs')(access);
const sourceCollection=path.join(root,'pruebas/backend/coleccion-bruno');
const runId=new Date().toISOString().replace(/[:.]/g,'-'),out=path.join(root,'test-results/backend-publicable-'+runId);
fs.mkdirSync(out,{recursive:true});
// Local IDs stay in a working copy, separate from the shared QA collection.
const collection=path.join(out,'.coleccion-ejecucion');
fs.cpSync(sourceCollection,collection,{recursive:true});
const {parseCollection,stringifyCollection}=require('node:module').createRequire(fs.realpathSync(cli))('@usebruno/filestore');
const collectionFile=path.join(collection,'collection.bru');
const config=parseCollection(fs.readFileSync(collectionFile,'utf8'),{format:'bru'});
const overrides={base:access.base};
for(const [name,actor] of Object.entries(access.actors)){overrides['id'+name]=actor.id;overrides['usuario'+name]=actor.login;}
overrides.idAsociado=access.actors.Ana.id;overrides.usuarioAsociado=access.actors.Ana.login;
for(const variable of config.request.vars.req)if(Object.hasOwn(overrides,variable.name)){variable.value=overrides[variable.name];if(typeof variable.value==='number')variable.dataType='number';else delete variable.dataType;}
fs.writeFileSync(collectionFile,stringifyCollection(config,{version:'1',name:'Pruebas ASCLA',type:'collection'},{format:'bru'}));
const environment=path.join(out,'.acceso-privado.json');
const credentials=Object.fromEntries(['Ana','Bruno','Carla','Administrador'].map(name=>{const actor=access.actors[name];if(!actor?.password||!Number.isInteger(actor.id))throw Error('Falta el acceso de '+name);return ['clave'+name,actor.password];}));
credentials.claveAsociado=access.actors.Ana.password;
fs.writeFileSync(environment,JSON.stringify({name:'Acceso privado',variables:Object.entries(credentials).map(([name,value])=>({name,value,enabled:true,type:'text',secret:true}))}),{mode:0o600});
const secrets=[...new Set(Object.values(credentials))],results=[];let failure=false;
const redact=value=>secrets.reduce((text,secret)=>text.split(secret).join('[contraseña omitida]'),String(value));
let admin,bruno,eventId;const base=access.base;
async function login(actor){const response=await fetch(base+'/wp-login.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Cookie:'wordpress_test_cookie=WP%20Cookie%20check'},body:new URLSearchParams({log:actor.login,pwd:actor.password,testcookie:'1',redirect_to:base+'/intranet/'}),redirect:'manual',signal:AbortSignal.timeout(45000)});if(response.status!==302)throw Error('Login de preparación rechazado.');const cookie=response.headers.getSetCookie().map(c=>c.split(';')[0]).join('; ');const page=await fetch(base+'/intranet/',{headers:{Cookie:cookie},signal:AbortSignal.timeout(45000)});const match=(await page.text()).match(/var ASCLA\s*=\s*(\{[\s\S]*?\});/);if(!match)throw Error('No se encontró el nonce.');return {cookie,nonce:JSON.parse(match[1]).nonce};}
async function api(route,session,body){const response=await fetch(base+'/wp-json/ascla/v1'+route,{method:body===undefined?'GET':'POST',body:body===undefined?undefined:JSON.stringify(body),headers:{Cookie:session.cookie,'X-WP-Nonce':session.nonce,...(body===undefined?{}:{'Content-Type':'application/json'})},redirect:'manual',signal:AbortSignal.timeout(45000)});const data=await response.json();if(response.status!==200)throw Error('Preparación/limpieza: HTTP '+response.status);return data;}
function run(group,name,expectedRequests,expectedTests){
  const child=spawnSync(process.execPath,[cli,'run',group,'--env-file',environment,'--disable-cookies','--bail','--reporter-html',path.join(out,name+'.html'),'--reporter-json',path.join(out,name+'.json'),'--reporter-junit',path.join(out,name+'.xml'),'--reporter-skip-all-headers','--reporter-skip-body'],{cwd:collection,encoding:'utf8',maxBuffer:20*1024*1024,timeout:240000});
  const log=redact((child.stdout||'')+(child.stderr||''));fs.writeFileSync(path.join(out,name+'.log'),log);process.stdout.write(log);
  const file=path.join(out,name+'.json'),report=fs.existsSync(file)?JSON.parse(fs.readFileSync(file,'utf8'))[0]:null;
  for(const extension of ['html','json','xml']){const p=path.join(out,name+'.'+extension);if(fs.existsSync(p)&&secrets.some(secret=>fs.readFileSync(p,'utf8').includes(secret)))throw Error('Credencial detectada en un reporte; requiere revisión antes de publicar.');}
  if(name==='02-evento-con-cupo')eventId=Number(report?.results.find(item=>item.request?.url?.match(/\/events\/\d+\/register$/))?.request.url.match(/\/events\/(\d+)/)[1]);
  const summary=report?.summary;
  const passed=child.status===0&&summary?.totalRequests===expectedRequests&&summary.passedRequests===expectedRequests&&summary.totalTests===expectedTests&&summary.passedTests===expectedTests&&summary.errorRequests===0&&summary.skippedRequests===0;
  results.push({name,status:passed?'passed':'failed',summary:summary||null});failure||=!passed;
}
(async()=>{let cleanup;
  try{
    admin=await login(access.actors.Administrador);bruno=await login(access.actors.Bruno);
    const identity=await api('/bootstrap',admin);if(!identity.admin)throw Error('Se requiere administración ASCLA.');
    const actor=await api('/admin/users/'+access.actors.Carla.id,admin);if(actor.login!==access.actors.Carla.login)throw Error('Identidad de Carla inesperada.');
    if(actor.suspended)await api('/admin/member/'+actor.id,admin,{suspended:false});
    for(const active of [true,false])await api('/relations',bruno,{target:access.actors.Ana.id,kind:'block',active});
    fs.cpSync(collection,path.join(out,'sources/coleccion-bruno'),{recursive:true});fs.copyFileSync(__filename,path.join(out,'sources/run-publishable-backend.cjs'));fs.copyFileSync(path.join(__dirname,'qa-access.cjs'),path.join(out,'sources/qa-access.cjs'));
    for(const item of [['integracion','integracion',18,26],['flujos-e2e/01-conexion-y-bloqueo','01-conexion-y-bloqueo',12,24],['flujos-e2e/02-evento-con-cupo','02-evento-con-cupo',15,29],['flujos-e2e/03-suspension-y-reactivacion','03-suspension-y-reactivacion',9,18],['flujos-e2e/04-asistente-ia','04-asistente-ia',4,8]])run(...item);
    fs.copyFileSync(path.join(collection,'collection.bru'),path.join(out,'variables-finales.bru'));
  }catch(error){failure=true;results.push({name:'runner',status:'failed',error:redact(error.message)});console.error(redact(error.message));}
  finally{
    try{if(admin){const actor=await api('/admin/users/'+access.actors.Carla.id,admin);if(actor.suspended)await api('/admin/member/'+actor.id,admin,{suspended:false});}if(bruno)await api('/relations',bruno,{target:access.actors.Ana.id,kind:'block',active:false});if(eventId){const event=await api('/events/'+eventId,admin);if(event.title!=='Encuentro ASCLA con cupo limitado')throw Error('Evento de prueba inesperado.');if(!event.cancelled)await api('/events/'+eventId+'/cancel',admin,{reason:'Cierre de la prueba de aforo.'});}cleanup={status:'passed',reactivated:!!admin,unblocked:!!bruno,event_id:eventId||null};}catch(error){failure=true;cleanup={status:'failed',error:redact(error.message)};}
    fs.rmSync(environment,{force:true});
    fs.rmSync(collection,{recursive:true,force:true});
    const git=command=>execFileSync('git',command,{cwd:root,encoding:'utf8'}).trim();
    const summary={date:new Date().toISOString(),target:base,mode,assistant_provider:access.assistant_provider||"observado en respuesta",scope:'Integración y cuatro flujos E2E del backend; una ejecución de la colección publicable.',commit:git(['rev-parse','HEAD']),branch:git(['branch','--show-current']),working_tree:git(['status','--porcelain'])?'uncommitted':'clean',results,cleanup};
    fs.writeFileSync(path.join(out,'summary.json'),JSON.stringify(summary,null,2)+'\n');
    fs.writeFileSync(path.join(root,'test-results/ultimo-backend-publicable.json'),JSON.stringify({path:out},null,2)+'\n');
    console.log('Resultados publicables: '+out);if(failure)process.exitCode=1;
  }
})().catch(error=>{console.error(redact(error.message));process.exitCode=1;});
