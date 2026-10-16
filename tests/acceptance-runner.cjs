const fs=require('node:fs'),path=require('node:path'),{spawnSync,execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..');
const fixturePath=process.env.ASCLA_ACCEPTANCE_FIXTURE;
if(!fixturePath)throw Error('ASCLA_ACCEPTANCE_FIXTURE is required');
const fixture=JSON.parse(fs.readFileSync(fixturePath,'utf8'));
const remote=process.env.ASCLA_ACCEPTANCE_MODE==='remote';
if(remote){
 const target=new URL(process.env.ASCLA_TEST_SITE_URL||'https://wordpress.ingsoftware.lat/');
 if(target.username||target.password||target.search||target.hash||target.pathname!=='/'||(!((target.protocol==='https:'&&target.hostname==='wordpress.ingsoftware.lat')||(process.env.ASCLA_E2E_EPHEMERAL==='1'&&target.protocol==='http:'&&/^ascla[_-]/.test(target.hostname)))))throw Error('Use the configured WordPress QA URL');
 if(new URL(fixture.base).origin!==target.origin||fixture.origin!=='ascla-qa-tools'||fixture.core_version!=='1.10.8'||!/^ascla_qa_[a-f0-9]{10}_$/.test(fixture.prefix||''))throw Error('Download a fresh QA access file from this site');
 for(const name of ['admin','a','b','ui_a','ui_b'])if(!fixture.actors[name]?.login?.startsWith(fixture.prefix)||!Number.isInteger(fixture.actors[name]?.id)||typeof fixture.actors[name]?.password!=='string')throw Error('Synthetic QA accounts are required');
 fixture.base=target.origin;
}
const out=path.resolve(process.env.ASCLA_ACCEPTANCE_OUTPUT||root+'/test-results/acceptance');
fs.mkdirSync(out,{recursive:true});const variables={base:fixture.base.replace(/\/$/,''),prefix:fixture.prefix};
for(const [name,actor] of Object.entries(fixture.actors))for(const [key,value] of Object.entries(actor))variables[name+key[0].toUpperCase()+key.slice(1)]=String(value);
const privateEnv=path.join(path.dirname(fixturePath),'.acceptance-bruno-'+process.pid+'.json');
fs.writeFileSync(privateEnv,JSON.stringify({name:'QA temporal',variables:Object.entries(variables).map(([name,value])=>({name,value,enabled:true,type:'text',secret:name.endsWith('Password')}))}),{mode:0o600});
const xml=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&apos;'}[c]));
const summaries=[];let failure=false;
const provenance=require('./acceptance-provenance.cjs');
let tools,cleanup,sourceReady=false;
(async()=>{
try {
 provenance.snapshot(root,out,{recorded:true,secrets:Object.values(fixture.actors).map(a=>a.password)});sourceReady=true;tools=provenance.tooling();
 for(const group of ['integration','business-e2e']) {
  const start=Date.now();const run=spawnSync(process.env.BRU_BIN||'bru',['run',group,'--env-file',privateEnv,'--disable-cookies','--bail','--reporter-json',path.join(out,group+'.json'),'--reporter-junit',path.join(out,group+'.xml'),'--reporter-html',path.join(out,group+'.bruno.html'),'--reporter-skip-all-headers','--reporter-skip-body'],{cwd:path.join(out,'sources/bruno'),encoding:'utf8',maxBuffer:4*1024*1024});
  const log=(run.stdout||'')+(run.stderr||'');
  // Reject accidental credential capture before saving a report for review.
  const passwords=Object.values(fixture.actors).map(actor=>actor.password);
  const leaked=[group+'.json',group+'.bruno.html',group+'.xml'].filter(file=>fs.existsSync(path.join(out,file))&&passwords.some(secret=>fs.readFileSync(path.join(out,file),'utf8').includes(secret)));
  for(const file of leaked)fs.rmSync(path.join(out,file));
  if(leaked.length)throw Error('Credential-bearing output omitted');
  if(passwords.some(secret=>log.includes(secret)))throw Error('Unexpected credential in CLI output');
  fs.writeFileSync(path.join(out,group+'.log'),log);process.stdout.write(log);
  const reportFile=path.join(out,group+'.json');const summary=fs.existsSync(reportFile)?JSON.parse(fs.readFileSync(reportFile,'utf8'))[0]?.summary:null;
  const expected=group==='integration'?10:23;
  const passed=run.status===0&&summary?.totalRequests===expected&&summary.passedRequests===expected&&summary.totalTests>0&&summary.failedTests===0&&summary.errorRequests===0&&summary.skippedRequests===0;
  summaries.push({name:group,status:passed?'passed':'failed',requests:summary?.totalRequests||0,tests:summary?.totalTests||0,ms:Date.now()-start});if(!passed)failure=true;
 }
 const results=await require(path.join(out,'sources/acceptance-ui.cjs'))({fixture,out});
 failure ||=results.some(test=>test.status!=='passed');
 summaries.push(...results);
 fs.writeFileSync(path.join(out,'frontend.xml'),'<testsuites><testsuite name="ASCLA UI" tests="'+results.length+'" failures="'+results.filter(t=>t.status==='failed').length+'">'+results.map(t=>'<testcase name="'+xml(t.name)+'" time="'+t.ms/1000+'">'+(t.error?'<failure>'+xml(t.error)+'</failure>':'')+'</testcase>').join('')+'</testsuite></testsuites>');
} catch(error) {failure=true;summaries.push({name:'runner',status:'failed',error:error.message});console.error(error.message);} finally {
 if(remote){try{const copy=path.join(out,'sources/acceptance-remote-cleanup.cjs');cleanup=await require(fs.existsSync(copy)?copy:'./acceptance-remote-cleanup.cjs')(fixture);fs.writeFileSync(path.join(out,'cleanup.log'),'QA helper: temporary batch removed.\n');}catch(error){failure=true;cleanup={status:'failed',message:'Use Tools > ASCLA QA to clean the active batch.'};summaries.push({name:'runner',status:'failed',error:'Remote cleanup was not confirmed. Use WordPress Tools > ASCLA QA.'});fs.writeFileSync(path.join(out,'cleanup.log'),cleanup.message+'\n');}}
 fs.rmSync(privateEnv,{force:true});
 const git=args=>execFileSync('git',['-c','safe.directory='+root,...args],{cwd:root,encoding:'utf8'}).trim();
 const metadata={date:new Date().toISOString(),target:fixture.base,target_version:fixture.core_version||null,target_version_recorded_at:fixture.issued_at||null,source_capture:sourceReady?'execution':'failed',mode:remote?'remote':process.env.ASCLA_ACCEPTANCE_MODE||'ephemeral',tools,cleanup,commit:git(['rev-parse','HEAD']),branch:git(['branch','--show-current']),working_tree:git(['status','--porcelain'])?'uncommitted':'clean',results:summaries};
 fs.writeFileSync(path.join(out,'summary.json'),JSON.stringify(metadata,null,2));
 provenance.manifest(out,{recorded:true});
 require('./acceptance-report.cjs').render(out);
 if(failure)process.exitCode=1;
 console.log('Evidencia: '+out+'/index.html');
}
})();
