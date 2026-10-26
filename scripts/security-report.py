"""Render the latest security run honestly: missing/technical phases never pass."""
from pathlib import Path
from html import escape
import datetime,json,sys
out=Path(sys.argv[1]);phases=[];code=0
priority={0:0,2:1,1:2,3:3}
def worst(current,next_code):return max([current,next_code],key=lambda value:priority[value])
for kind in ['frontend','backend']:
    file=out/(kind+'-result.json')
    data=json.loads(file.read_text()) if file.exists() else {'status':'ERROR','error':'No completed scan report'}
    if not data.get('active_complete'):data['status']='ERROR'
    status=data['status'];code=worst(code,{'PASS':0,'WARN':2,'FAIL':1,'ERROR':3}.get(status,3))
    phases.append({'phase':kind,'status':status,'risk_counts':data.get('risk_counts',{}),'error':data.get('error'),'scope':data.get('scope',[]),'findings':data.get('findings',[])})
for name,filename,field in [('CSP','csp-validation.json','estado'),('Browser','browser-security.json','errors')]:
    file=out/filename
    if file.exists():
        data=json.loads(file.read_text());status=('PASS' if not data[field] else 'FAIL') if field=='errors' else data[field]
    else:status='ERROR'
    code=worst(code,{'PASS':0,'FAIL':1,'ERROR':3}.get(status,3));phases.append({'phase':name,'status':status})
if len(sys.argv)>2:
    import hashlib
    root=Path(sys.argv[2]);manifest=out/'source-manifest.json';changed=[]
    if manifest.exists():
        for name,expected in json.loads(manifest.read_text())['files'].items():
            file=root/name
            if not file.is_file() or hashlib.sha256(file.read_bytes()).hexdigest()!=expected:changed.append(name)
    else:changed=['Missing source manifest']
    status='ERROR' if changed else 'PASS';code=worst(code,3 if changed else 0)
    phases.append({'phase':'Source consistency','status':status,'changed':changed})
summary={'date':datetime.datetime.now(datetime.timezone.utc).isoformat(),'status':'ERROR' if code==3 else 'WARN' if code==2 else 'FAIL' if code==1 else 'PASS','phases':phases,'scope_note':'ASCLA-owned HTML routes and 18 documented GET REST operations in disposable local QA. Excludes native WordPress admin, arbitrary CMS/theme/plugin routes, destructive writes and live external integrations. No universal absence-of-vulnerabilities claim.'}
(out/'security-summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n')
rows=''.join('<tr><td>'+escape(p['phase'])+'</td><td>'+escape(p['status'])+'</td><td>'+escape(json.dumps(p.get('risk_counts',{})))+'</td></tr>' for p in phases)
links=''.join('<li><a href="'+name+'">'+escape(name)+'</a></li>' for name in ['frontend-zap.html','frontend-zap.json','backend-zap.html','backend-zap.json','csp-validation.json','browser-security.json','security-summary.json','openapi.json'] if (out/name).exists())
(out/'index.html').write_text('<!doctype html><html lang="es"><meta charset="utf-8"><title>Seguridad ASCLA</title><style>body{font:16px system-ui;max-width:1050px;margin:40px auto;padding:0 20px;color:#18334d;background:#f5f8fb}main{background:white;border:1px solid #dce5ee;border-radius:16px;padding:28px}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:12px;border-bottom:1px solid #dce5ee}a{color:#106b9c}p{line-height:1.6}</style><main><h1>Seguridad ASCLA: '+summary['status']+'</h1><p>Resultados de la ejecución local. FAIL, WARN y ERROR se conservan; los informes nativos incluyen sus alertas informativas.</p><table><tr><th>Fase</th><th>Resultado</th><th>Alertas por riesgo 0–3</th></tr>'+rows+'</table><h2>Reportes verificables</h2><ul>'+links+'</ul><p>'+escape(summary['scope_note'])+'</p></main></html>')
print(json.dumps({'status':summary['status'],'phases':[{k:v for k,v in p.items() if k in ['phase','status','risk_counts']} for p in phases]},ensure_ascii=False))
raise SystemExit(code)
