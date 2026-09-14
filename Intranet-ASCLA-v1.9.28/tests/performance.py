"""Bounded local load profiles, never against staging/production. Python stdlib."""
from pathlib import Path
import concurrent.futures, http.cookiejar, http.client, socket, urllib.request, urllib.parse, json, time, statistics, re, subprocess
ROOT=Path(__file__).resolve().parents[1]
BASE='http://localhost:8088'
ENV=dict(line.split('=',1) for line in (ROOT/'.env').read_text().splitlines() if '=' in line)

class LocalConnection(http.client.HTTPConnection):
    def connect(self):
        if self.host != 'localhost' or self.port != 8088:
            raise RuntimeError('Benchmark only permits local WordPress')
        self.sock=socket.create_connection(('127.0.0.1',8088),self.timeout)

class LocalTransport(urllib.request.HTTPHandler):
    def http_open(self,request):
        return self.do_open(LocalConnection,request)

def client(index):
    jar=http.cookiejar.CookieJar(); opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),LocalTransport(),urllib.request.HTTPCookieProcessor(jar))
    opener.open(BASE+'/wp-login.php',timeout=15).read()
    user='demo.asociado' if index==0 else f'demo.miembro.{index+1}'
    body=urllib.parse.urlencode({'log':user,'pwd':ENV['ASCLA_DEMO_PASSWORD'],'wp-submit':'Log In','redirect_to':BASE+'/intranet/','testcookie':'1'}).encode()
    html=opener.open(BASE+'/wp-login.php',body,timeout=15).read().decode()
    match=re.search(r'var ASCLA\s*=\s*(\{.*?\});',html)
    if not match: raise RuntimeError('Local login failed; check demo seed and .env')
    config=json.loads(match.group(1))
    return opener,config['nonce']

def run(name,concurrency,requests_per_worker,pause_seconds=0):
    samples=[]; errors=[]; started=time.perf_counter()
    def worker(i):
        opener,nonce=client(i%18); values=[]; failures=[]
        routes=['profiles','content/resource','content/hub','content/event?past=0','notifications','matching']
        for n in range(requests_per_worker):
            endpoint=routes[n%len(routes)]; tick=time.perf_counter()
            try:
                req=urllib.request.Request(BASE+'/wp-json/ascla/v1/'+endpoint,headers={'X-WP-Nonce':nonce})
                with opener.open(req,timeout=30) as response:
                    data=json.loads(response.read())
                    if response.status!=200: raise RuntimeError('Unexpected HTTP status')
            except Exception as exc: failures.append(type(exc).__name__)
            values.append((time.perf_counter()-tick)*1000)
            if pause_seconds: time.sleep(pause_seconds)
        return values,failures
    with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as pool:
        for values,failures in pool.map(worker,range(concurrency)): samples+=values;errors+=failures
    elapsed=time.perf_counter()-started;samples.sort()
    return {'name':name,'concurrency':concurrency,'requests':len(samples),'seconds':round(elapsed,3),'requests_per_second':round(len(samples)/elapsed,2),'p50_ms':round(statistics.median(samples),2),'p95_ms':round(samples[int((len(samples)-1)*.95)],2),'max_ms':round(max(samples),2),'errors':len(errors),'error_types':sorted(set(errors))}

if __name__=='__main__':
    result={'environment':'Local Docker, WordPress 7.1 / PHP 8.3 / MariaDB 11.4; 18 demo members','scope':'Six read endpoints, independent authenticated sessions; login included in total duration; explicit IPv4 transport avoids Windows localhost IPv6 fallback; duration includes 250ms think time','profiles':[]}
    for profile in [('baseline',1,18),('load',4,18),('spike',12,12),('stress',18,12),('duration',4,480,.25),('recovery',1,18)]:
        row=run(*profile)
        stats=subprocess.check_output(['docker','stats','--no-stream','--format','{{json .}}','ascla-dev-wordpress-1','ascla-dev-db-1'],text=True)
        row['container_snapshot']=[{k:v for k,v in json.loads(line).items() if k in ['Name','MemUsage','CPUPerc','PIDs']} for line in stats.splitlines()]
        result['profiles'].append(row);print(json.dumps(row),flush=True)
    (ROOT/'test-results').mkdir(exist_ok=True)
    (ROOT/'test-results/performance.json').write_text(json.dumps(result,indent=2),encoding='utf8')
