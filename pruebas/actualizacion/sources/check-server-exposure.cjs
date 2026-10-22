/* Read-only deployment check. Inspect public responses without authentication or attacks. */
const fs=require('node:fs'),path=require('node:path');
(async()=>{
 const base=new URL(process.argv[2]||'https://wordpress.ingsoftware.lat');
 if(base.protocol!=='https:'||base.username||base.password||base.pathname!=='/'||base.search||base.hash)throw Error('Use un origen HTTPS sin credenciales ni ruta.');
 const output=path.resolve(process.argv[3]||'test-results/qa-refresh-20261022/server-exposure.json');
 const results=[];
 for(const route of ['/login/','/wp-login.php','/wp-content/plugins/ascla-core/assets/app.js','/wp-json/ascla/v1/profiles','/ascla-security-check-missing-20261022/']){
  const response=await fetch(base.origin+route,{redirect:'manual',signal:AbortSignal.timeout(15000)});
  const server=response.headers.get('server');
  results.push({path:route,status:response.status,server,version_hidden:!/[a-z][a-z-]*\/\d/i.test(server||'')});
  await response.body?.cancel();
 }
 const data={date:new Date().toISOString(),target:base.origin,mode:'public-read-only',status:results.every(item=>item.version_hidden)?'PASS':'FAIL',results};
 fs.mkdirSync(path.dirname(output),{recursive:true});fs.writeFileSync(output,JSON.stringify(data,null,2)+'\n');
 console.log(JSON.stringify(data,null,2));if(data.status!=='PASS')process.exitCode=1;
})().catch(error=>{console.error(error.message);process.exitCode=1;});
