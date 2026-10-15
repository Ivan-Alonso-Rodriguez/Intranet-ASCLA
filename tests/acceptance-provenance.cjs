/* Keep executable sources and native output separate from their HTML presentation. */
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawnSync}=require('node:child_process');
const sha=data=>crypto.createHash('sha256').update(data).digest('hex');
const safe=name=>!name.includes('\\')&&!name.startsWith('/')&&!name.split('/').some(part=>!part||part==='..'||part.includes(':'));
function zip(entries){
 const chunks=[],central=[];let offset=0;
 const crc=bytes=>{let n=0xffffffff;for(const b of bytes){n^=b;for(let i=0;i<8;i++)n=(n>>>1)^((n&1)?0xedb88320:0);}return (n^0xffffffff)>>>0;};
 for(const [name,bytes] of entries){if(!safe(name))throw Error('Unsafe archive entry');const filename=Buffer.from(name);const header=Buffer.alloc(30);header.writeUInt32LE(0x04034b50);header.writeUInt16LE(20,4);header.writeUInt16LE(0x800,6);header.writeUInt16LE(33,12);header.writeUInt32LE(crc(bytes),14);header.writeUInt32LE(bytes.length,18);header.writeUInt32LE(bytes.length,22);header.writeUInt16LE(filename.length,26);chunks.push(header,filename,bytes);
  const c=Buffer.alloc(46);c.writeUInt32LE(0x02014b50);c.writeUInt16LE(20,4);header.copy(c,6,4,28);c.writeUInt32LE(offset,42);central.push(c,filename);offset+=header.length+filename.length+bytes.length;
 }
 const directory=Buffer.concat(central),end=Buffer.alloc(22);end.writeUInt32LE(0x06054b50);end.writeUInt16LE(entries.length,8);end.writeUInt16LE(entries.length,10);end.writeUInt32LE(directory.length,12);end.writeUInt32LE(offset,16);return Buffer.concat([...chunks,directory,end]);
}
function files(dir){return fs.readdirSync(dir,{withFileTypes:true}).sort((a,b)=>a.name.localeCompare(b.name)).flatMap(entry=>{const p=path.join(dir,entry.name);return entry.isSymbolicLink()?[]:entry.isDirectory()?files(p):[p];});}
function snapshot(root,out,{recorded=false,secrets=[]}={}){
 const destination=path.join(out,'sources'),descriptor=path.join(out,'sources.json');
 if(fs.existsSync(descriptor))return JSON.parse(fs.readFileSync(descriptor,'utf8'));
 fs.mkdirSync(destination,{recursive:true});const hashes={},entries=[];
 const inputs=files(path.join(root,'tests/http')).filter(p=>p.endsWith('.bru')||p===path.join(root,'tests/http/bruno.json'));
 for(const file of inputs){const name=path.relative(path.join(root,'tests/http'),file).replaceAll('\\','/');if(!safe(name))throw Error('Unsafe collection source');const bytes=fs.readFileSync(file);if(secrets.some(s=>s&&bytes.includes(Buffer.from(s))))throw Error('Credential in collection source');const target=path.join(destination,'bruno',name);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,bytes);hashes['sources/bruno/'+name]=sha(bytes);entries.push(['ascla-bruno/'+name,bytes]);}
 for(const name of ['acceptance-ui.cjs','acceptance-runner.cjs','acceptance-provenance.cjs','acceptance-remote-cleanup.cjs']){const bytes=fs.readFileSync(path.join(root,'tests',name));if(secrets.some(s=>s&&bytes.includes(Buffer.from(s))))throw Error('Credential in test source');fs.writeFileSync(path.join(destination,name),bytes);hashes['sources/'+name]=sha(bytes);}
 fs.writeFileSync(path.join(out,'bruno-collection.zip'),zip(entries));
 const info={type:recorded?'execution':'reference',captured_at:new Date().toISOString(),description:recorded?'Fuentes copiadas al iniciar esta ejecución.':'Colección actual de referencia; esta ejecución antigua no registró una copia de sus fuentes.',hashes};fs.writeFileSync(descriptor,JSON.stringify(info,null,2));return info;
}
function tooling(){const r=spawnSync(process.env.BRU_BIN||'bru',['--version'],{encoding:'utf8'});return{node:process.version,bruno:r.status===0?(r.stdout||'').match(/\d+\.\d+\.\d+/)?.[0]||null:null,playwright:require('playwright/package.json').version};}
function manifest(out,{recorded=false}={}){
 const hashes={};for(const file of files(out)){const name=path.relative(out,file).replaceAll('\\','/');if(!safe(name)||name==='evidence-manifest.json'||/\.(?:html|css|js|png)$/.test(name)&&name.startsWith('assets/'))continue;
  if(name.startsWith('sources/')||name==='sources.json'||name==='cleanup.log'||name==='bruno-collection.zip'||/^(?:summary|frontend|integration|business-e2e)\.(?:json|xml|log|bruno\.html)$/.test(name)||name.startsWith('videos/')||name.endsWith('.png'))hashes[name]=sha(fs.readFileSync(file));
 }const result={type:recorded?'execution':'inspection',calculated_at:new Date().toISOString(),algorithm:'SHA-256',note:'Permite detectar cambios en los archivos; no es una firma de un tercero.',files:hashes};fs.writeFileSync(path.join(out,'evidence-manifest.json'),JSON.stringify(result,null,2));return result;
}
function verify(out,data){return Object.entries(data.files||{}).map(([name,expected])=>({name,valid:safe(name)&&fs.existsSync(path.join(out,name))&&sha(fs.readFileSync(path.join(out,name)))===expected}));}
module.exports={snapshot,tooling,manifest,verify,zip,safe};
