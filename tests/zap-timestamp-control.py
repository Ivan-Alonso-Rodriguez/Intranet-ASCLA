from http.server import BaseHTTPRequestHandler,HTTPServer
from pathlib import Path
import json,secrets,subprocess,threading,time,requests
out=Path('/results');out.mkdir(exist_ok=True);key=secrets.token_hex(24);timestamp=int(time.time())
class Fixture(BaseHTTPRequestHandler):
    def do_GET(self):
        body=json.dumps({'issuedAt':timestamp}).encode();self.send_response(200)
        self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)))
        self.end_headers();self.wfile.write(body)
    def log_message(self,*args):pass
server=HTTPServer(('127.0.0.1',9123),Fixture);threading.Thread(target=server.serve_forever,daemon=True).start()
log=(out/'engine.log').open('w')
process=subprocess.Popen(['zap.sh','-daemon','-host','127.0.0.1','-port','8090','-config','api.key='+key,'-config','api.addrs.addr.name=127.0.0.1','-config','api.addrs.addr.regex=false'],stdout=log,stderr=subprocess.STDOUT)
session=requests.Session();session.trust_env=False
def api(endpoint,parameters=None):
    response=session.get('http://127.0.0.1:8090/JSON/'+endpoint+'/',params={**(parameters or {}),'apikey':key},timeout=10)
    if response.status_code!=200:raise RuntimeError('Control API request rejected')
    return response.json()
try:
    for attempt in range(90):
        try:
            version=api('core/view/version')['version'];break
        except (requests.RequestException,ValueError,RuntimeError):time.sleep(1)
    else:raise TimeoutError('Control engine did not start')
    scanners=api('pscan/view/scanners')['scanners'];rule=next(item for item in scanners if str(item['id'])=='10096')
    assert str(rule['enabled']).lower()=='true'
    api('core/action/accessUrl',{'url':'http://127.0.0.1:9123/fixture.json'})
    finding=None
    for attempt in range(60):
        alerts=api('core/view/alerts',{'baseurl':'http://127.0.0.1:9123/'})['alerts']
        finding=next((alert for alert in alerts if str(alert.get('pluginId'))=='10096' and alert.get('evidence')==str(timestamp)),None)
        if finding:break
        time.sleep(1)
    assert finding,'The real timestamp was not detected'
    result={'status':'PASS','engine_version':version,'rule':'10096','rule_enabled':True,'real_timestamp_detected':True,'native_risk':finding['risk'],'evidence':finding['evidence'],'application_scan_modified':False}
    (out/'summary.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result))
except Exception as error:
    print('Timestamp positive control failed: '+type(error).__name__);raise SystemExit(1)
finally:
    server.shutdown();process.terminate()
    try:process.wait(timeout=20)
    except subprocess.TimeoutExpired:process.kill();process.wait()
    key='';log.close()
