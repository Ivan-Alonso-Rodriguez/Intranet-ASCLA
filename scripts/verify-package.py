"""Check the built archive against its current source, excluding private data from output."""
from pathlib import Path
import subprocess,json,hashlib,re,zipfile,datetime
root=Path(__file__).resolve().parents[1]
source=root/'wp-content/plugins/ascla-core'
out=root/'docs/evidence/release-1.9.15'
out.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(root/'dist/ascla-core.zip') as archive:
    assert archive.testzip() is None
    names=archive.namelist()
    assert 'ascla-core/languages/en.json' in names
    assert all(n.startswith('ascla-core/') for n in names)
    assert not any(any(p in {'.git','.env','node_modules','coverage','tests','vendor'} for p in Path(n).parts) for n in names)
    hashes={n:hashlib.sha256(archive.read(n)).hexdigest() for n in names}
    for name in names:
        assert archive.read(name)==(source/name.removeprefix('ascla-core/')).read_bytes(),name
    assert b'Version: 1.9.15' in archive.read('ascla-core/ascla-core.php')
    secrets=[]
    env_file=root/'.env'
    if env_file.exists():
        for line in env_file.read_text(encoding='utf-8').splitlines():
            if '=' not in line: continue
            key,value=line.split('=',1)
            if re.search('PASSWORD|SECRET|TOKEN|API_KEY',key,re.I) and len(value)>=12: secrets.append(value.encode())
    assert not any(secret in archive.read(name) for secret in secrets for name in names),'Known local secret found'
    package=root/'dist/ascla-core.zip'
    digest=hashlib.sha256(package.read_bytes()).hexdigest()
    assert (root/'dist/ascla-core.sha256').read_text().split()[0]==digest
    report={'date':datetime.datetime.now(datetime.timezone.utc).isoformat(),'version':'1.9.15','bytes':package.stat().st_size,'sha256':digest,'files':len(names),'crc_valid':True,'source_bytes_match':True,'forbidden_paths':[],'known_local_secrets_absent':True,'translation_catalog_included':True,'production_hashes':hashes}
    (out/'package.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
    print(json.dumps({k:v for k,v in report.items() if k!='production_hashes'},indent=2))
