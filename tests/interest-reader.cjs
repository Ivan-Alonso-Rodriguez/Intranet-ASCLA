/* Exercises the production converter in Chromium; all files and identities are synthetic. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
module.exports=async page=>{
  const dir=path.join(__dirname,'fixtures/interest-reader');
  const files=Object.fromEntries(fs.readdirSync(dir).map(name=>[name,fs.readFileSync(path.join(dir,name)).toString('base64')]));
  const result=await page.evaluate(async files=>{
    const file=name=>new File([Uint8Array.from(atob(files[name]),c=>c.charCodeAt(0))],name);
    const read=(name,progress)=>ASCLAInterestFileReader(file(name),progress);
    const rejected=async source=>{try{await ASCLAInterestFileReader(source);return false;}catch(error){return !!error.message;}};
    const progress=[];
    const pdf=await read('forms.pdf',(page,total)=>progress.push([page,total]));
    const html=await read('forms.html'),zip=await read('forms.zip');
    const csv=await ASCLAInterestFileReader(new File(['Correo,Intereses\na@example.invalid,Riesgos'],'respuestas.csv'));
    const invalid=[];
    for(const name of ['multiple.zip','bomb.zip','narrative.pdf','merged-identity.pdf'])invalid.push(await rejected(file(name)));
    for(const source of [new File(['x'],'notes.txt'),new File([],'empty.csv'),new File(['x'.repeat(524289)],'large.csv'),new File(['not a zip'],'broken.zip'),new File(['<table><tr><td>Sin identidad</td></tr></table>'],'no-table.html'),new File([Uint8Array.from([255,255])],'invalid.csv')])invalid.push(await rejected(source));
    const oldStream=globalThis.DecompressionStream;
    try{globalThis.DecompressionStream=undefined;invalid.push(await rejected(file('forms.zip')));}finally{globalThis.DecompressionStream=oldStream;}
    const oldModule=ASCLA.pdfModule;
    try{ASCLA.pdfModule='https://example.invalid/pdf.js';invalid.push(await rejected(file('forms.pdf')));}finally{ASCLA.pdfModule=oldModule;}
    return {pdf,html,zip,csv,progress,invalid,inert:globalThis.unsafeImport===undefined};
  },files);
  assert.deepEqual(result.progress,[[1,2],[2,2]]);
  for(const item of [result.pdf,result.html,result.zip]){
    assert.match(item.csv,/Maria Alejandra/);assert.match(item.csv,/Pedro Enrique/);
    assert.equal(item.csv.split('\n').length,4);assert.equal(item.mime,'text/csv');
  }
  assert.equal(result.html.csv,result.zip.csv);assert.deepEqual(result.pdf.warnings,[]);
  assert.equal(result.csv.csv,'Correo,Intereses\na@example.invalid,Riesgos');
  assert.equal(result.invalid.length,12);assert.ok(result.invalid.every(Boolean));assert.equal(result.inert,true);
};
