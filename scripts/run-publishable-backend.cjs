/* Execute the versioned collection without writing credentials into Git files. */
const fs=require('node:fs'),path=require('node:path'),{spawnSync,execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),args=process.argv.slice(2);
const contract=require('../tests/qa-flow-contract.json');
const option=name=>{const i=args.indexOf(name);return i<0?null:args[i+1];};
const cliArgument=option('--cli')||process.env.BRU_BIN||path.join(root,'test-results/bruno-tools/node_modules/@usebruno/cli/bin/bru.js');
const cli=path.resolve(cliArgument);
if(!fs.existsSync(cli))throw Error('Falta Bruno CLI 4.2.1. Instala las herramientas indicadas en pruebas/README.md o usa --cli con su entrada JavaScript.');
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
let admin,bruno,deployment;const ownedEvents=[];const base=access.base;
async function login(actor){return require('./native-login.cjs')(base,actor);}
async function api(route,session,body,method=body===undefined?'GET':'POST'){const response=await fetch(base+'/wp-json/ascla/v1'+route,{method,body:body===undefined?undefined:JSON.stringify(body),headers:{Connection:'close',Cookie:session.cookie,'X-WP-Nonce':session.nonce,...(body===undefined?{}:{'Content-Type':'application/json'})},redirect:'manual',signal:AbortSignal.timeout(45000)});const data=await response.json();if(response.status!==200)throw Error('Preparación/limpieza: HTTP '+response.status);return data;}
function run(group,name,expectedRequests,expectedTests){
  const child=spawnSync(process.execPath,[cli,'run',group,'--env-file',environment,'--disable-cookies','--bail','--reporter-html',path.join(out,name+'.html'),'--reporter-json',path.join(out,name+'.json'),'--reporter-junit',path.join(out,name+'.xml'),'--reporter-skip-all-headers','--reporter-skip-body'],{cwd:collection,encoding:'utf8',maxBuffer:20*1024*1024,timeout:240000});
  const log=redact((child.stdout||'')+(child.stderr||''));fs.writeFileSync(path.join(out,name+'.log'),log);process.stdout.write(log);
  const file=path.join(out,name+'.json'),report=fs.existsSync(file)?JSON.parse(fs.readFileSync(file,'utf8'))[0]:null;
  for(const extension of ['html','json','xml']){const p=path.join(out,name+'.'+extension);if(fs.existsSync(p)&&secrets.some(secret=>fs.readFileSync(p,'utf8').includes(secret)))throw Error('Credencial detectada en un reporte; requiere revisión antes de publicar.');}
  if(['aforo-lista-espera-notificacion','borrador-publicacion-inscripcion'].includes(name)&&report?.results.some(item=>item.request?.method==='POST'&&/\/content\/event$/.test(item.request.url)&&item.response?.status===200)){
    const vars=parseCollection(fs.readFileSync(collectionFile,'utf8'),{format:'bru'}).request.vars.req;
    const get=key=>vars.find(variable=>variable.name===key)?.value;
    const publication=name==='borrador-publicacion-inscripcion',id=Number(get(publication?'eventoPublicacionId':'eventoCupoId'));
    if(id>0)ownedEvents.push({id,title:publication?get('tituloEventoPublicacion'):'Encuentro ASCLA con cupo limitado'});
  }
  const summary=report?.summary;
  const passed=child.status===0&&summary?.totalRequests===expectedRequests&&summary.passedRequests===expectedRequests&&summary.totalTests===expectedTests&&summary.passedTests===expectedTests&&summary.errorRequests===0&&summary.skippedRequests===0;
  results.push({name,status:passed?'passed':'failed',summary:summary||null});failure||=!passed;
}
(async()=>{let cleanup;
  try{
    deployment=await require('./qa-target-provenance.cjs')(base);
    admin=await login(access.actors.Administrador);bruno=await login(access.actors.Bruno);
    const identity=await api('/bootstrap',admin);if(!identity.admin)throw Error('Se requiere administración ASCLA.');
    const actor=await api('/admin/users/'+access.actors.Carla.id,admin);if(actor.login!==access.actors.Carla.login)throw Error('Identidad de Carla inesperada.');
    if(actor.suspended)await api('/admin/member/'+actor.id,admin,{suspended:false});
    for(const active of [true,false])await api('/relations',bruno,{target:access.actors.Ana.id,kind:'block',active});
    fs.cpSync(collection,path.join(out,'sources/coleccion-bruno'),{recursive:true});fs.copyFileSync(__filename,path.join(out,'sources/run-publishable-backend.cjs'));fs.copyFileSync(path.join(__dirname,'qa-access.cjs'),path.join(out,'sources/qa-access.cjs'));fs.copyFileSync(path.join(__dirname,'native-login.cjs'),path.join(out,'sources/native-login.cjs'));fs.copyFileSync(path.join(__dirname,'qa-target-provenance.cjs'),path.join(out,'sources/qa-target-provenance.cjs'));
    fs.copyFileSync(path.join(root,'tests/qa-flow-contract.json'),path.join(out,'sources/qa-flow-contract.json'));
    for(const item of [contract.integration,...contract.flows])run(item.folder,item.id,item.requests,item.checks);
    fs.copyFileSync(path.join(collection,'collection.bru'),path.join(out,'variables-finales.bru'));
  }catch(error){failure=true;results.push({name:'runner',status:'failed',error:redact(error.message)});console.error(redact(error.message));}
  finally{
    try{if(admin){const actor=await api('/admin/users/'+access.actors.Carla.id,admin);if(actor.suspended)await api('/admin/member/'+actor.id,admin,{suspended:false});}if(bruno)await api('/relations',bruno,{target:access.actors.Ana.id,kind:'block',active:false});for(const owned of ownedEvents){const event=await api('/events/'+owned.id,admin);if(event.title!==owned.title)throw Error('Evento de prueba inesperado.');if(event.status==='draft')await api('/items/'+owned.id,admin,undefined,'DELETE');else if(!event.cancelled)await api('/events/'+owned.id+'/cancel',admin,{reason:'Cierre de la prueba dedicada.'});}cleanup={status:'passed',reactivated:!!admin,unblocked:!!bruno,events:ownedEvents.map(event=>event.id)};}catch(error){failure=true;cleanup={status:'failed',error:redact(error.message)};}
    fs.rmSync(environment,{force:true});
    fs.rmSync(collection,{recursive:true,force:true});
    const git=command=>execFileSync('git',command,{cwd:root,encoding:'utf8'}).trim();
    const summary={date:new Date().toISOString(),target:base,mode,deployment,flow_contract:contract,assistant_provider:access.assistant_provider||"observado en respuesta",scope:'Integración API adicional y los mismos seis flujos de negocio que Playwright.',commit:git(['rev-parse','HEAD']),branch:git(['branch','--show-current']),working_tree:git(['status','--porcelain'])?'uncommitted':'clean',results,cleanup};
    fs.writeFileSync(path.join(out,'summary.json'),JSON.stringify(summary,null,2)+'\n');
    fs.writeFileSync(path.join(root,'test-results/ultimo-backend-publicable.json'),JSON.stringify({path:out},null,2)+'\n');
    console.log('Resultados publicables: '+out);if(failure)process.exitCode=1;
  }
})().catch(error=>{console.error(redact(error.message));process.exitCode=1;});
