/* Host runner: Docker control stays outside the browser container. */
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const root=path.resolve(__dirname,'..'),file=root+'/test-results/atomic-fault.json';
function applyFault(input){
  return JSON.parse(cp.execFileSync('docker',['exec','-i','-e','ASCLA_E2E_EPHEMERAL=1','ascla_audit-wordpress-1','php','/opt/ascla-tests/atomic-fixture.php'],{input:JSON.stringify(input),windowsHide:true,stdio:['pipe','pipe','pipe']}).toString());
}
fs.rmSync(file,{force:true});
const child=cp.spawn('docker',['exec','-e','NODE_PATH=/tmp/ascla-node/node_modules','-e','ASCLA_E2E_EPHEMERAL=1','-e','ASCLA_ENV_FILE=/work/.env.audit','ascla-audit-browser','node','/work/tests/atomic107.cjs'],{stdio:'inherit',windowsHide:true});
const timer=setInterval(()=>{
  if(!fs.existsSync(file))return;
  let input;try{input=JSON.parse(fs.readFileSync(file,'utf8'));}catch{return;}
  if(input.done)return;
  try{input.result=applyFault(input);input.done=true;}catch{input.done=true;input.error='Local fault injection failed';}
  fs.writeFileSync(file,JSON.stringify(input));
},200);
child.on('exit',code=>{
  clearInterval(timer);process.exitCode=code??1;
  try{applyFault({action:'disable'});}catch{console.error('Cannot remove isolated fault trigger');process.exitCode=1;}
  fs.rmSync(file,{force:true});
});
