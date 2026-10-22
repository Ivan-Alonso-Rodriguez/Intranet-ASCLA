/* Public asset comparison identifies the deployed frontend, not private PHP files. */
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
module.exports=async function targetProvenance(base){
 const root=path.resolve(__dirname,'..'),sha=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
 const manifest=JSON.parse(fs.readFileSync(path.join(root,'docs/evidence/release-1.10.9/package.json'),'utf8'));
 const assets=[];
 for(const name of ['assets/app.js','assets/app.css']){
  const response=await fetch(base+'/wp-content/plugins/ascla-core/'+name,{signal:AbortSignal.timeout(30000),cache:'no-store'});
  if(response.status!==200)throw Error('No se pudo identificar el archivo desplegado: '+name);
  const remote=Buffer.from(await response.arrayBuffer()),local=fs.readFileSync(path.join(root,'wp-content/plugins/ascla-core',name));
  assets.push({file:name,local_sha256:sha(local),deployed_sha256:sha(remote),matches:remote.equals(local)});
 }
 if(assets.some(asset=>!asset.matches))throw Error('La interfaz desplegada no coincide con el código que se pretende probar.');
 return {checked_at:new Date().toISOString(),target:base,version:manifest.version,local_reference_package_sha256:manifest.sha256,assets,
  scope:'Public JavaScript and CSS match the current local package. Private server-side PHP files were not downloaded; behavior is checked by the executed API/UI tests.'};
};
