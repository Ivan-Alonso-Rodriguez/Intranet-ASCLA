"""Authenticated, bounded loopback load and write races. No production targets."""
from pathlib import Path
import argparse,concurrent.futures,http.cookiejar,urllib.request,urllib.parse,urllib.error,json,re,time,statistics,os,html.parser

parser=argparse.ArgumentParser();parser.add_argument('--base',required=True);parser.add_argument('--fixture',type=Path,required=True);parser.add_argument('--output',type=Path,required=True);parser.add_argument('--phase',choices=['baseline','optimized'],required=True);parser.add_argument('--probe-writes',action='store_true');args=parser.parse_args()
if os.getenv('ASCLA_E2E_EPHEMERAL')!='1' or urllib.parse.urlsplit(args.base).hostname not in ('127.0.0.1','localhost'):raise RuntimeError('Disposable loopback only')
fixture=json.loads(args.fixture.read_text());actors=fixture['actors']
assert len(actors)==32 and all(a['login'].startswith('opt_stress_') for a in actors)
base=args.base.rstrip('/');routes=['profiles','content/resource','content/resource?q=OPT_RARE_KNOWLEDGE_7319','notifications/toasts?after=0','matching','bootstrap','@page']

class Client:
 def __init__(self,actor):
  self.actor=actor;self.opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  form=self.opener.open(base+'/wp-login.php',timeout=45).read().decode()
  class Inputs(html.parser.HTMLParser):
   def __init__(self):super().__init__();self.fields={}
   def handle_starttag(self,tag,attrs):
    attrs=dict(attrs)
    if tag=='input' and attrs.get('type')=='hidden' and attrs.get('name'):self.fields[attrs['name']]=attrs.get('value','')
  inputs=Inputs();inputs.feed(form)
  body=urllib.parse.urlencode(inputs.fields|{'log':actor['login'],'pwd':actor['password'],'wp-submit':'Log In','redirect_to':base+'/intranet/','testcookie':'1'}).encode()
  text=self.opener.open(base+'/wp-login.php',body,timeout=45).read().decode()
  match=re.search(r'var ASCLA\s*=\s*(\{.*?\});',text)
  if not match:raise RuntimeError('Synthetic login did not return application bootstrap')
  config=json.loads(match[1]);assert config['bootstrap']['me']['id']==actor['id'];self.nonce=config['nonce'];self.bio=config['bootstrap']['me']['bio']
 def request(self,route,body=None,nonce=True):
  url=base+'/intranet/' if route=='@page' else base+'/wp-json/ascla/v1/'+route
  headers={'Content-Type':'application/json'}
  if nonce:headers['X-WP-Nonce']=self.nonce
  request=urllib.request.Request(url,data=json.dumps(body).encode() if body is not None else None,headers=headers)
  started=time.perf_counter()
  try:
   with self.opener.open(request,timeout=45) as r:raw=r.read();status=r.status;cache=r.headers.get('Cache-Control','')
  except urllib.error.HTTPError as e:raw=e.read();status=e.code;cache=''
  elapsed=(time.perf_counter()-started)*1000
  if route=='@page':data=None
  else:data=json.loads(raw)
  return {'status':status,'ms':round(elapsed,3),'bytes':len(raw),'data':data,'cache_control':cache}

def stats(values):
 values=sorted(values);return {'p50_ms':round(statistics.median(values),3),'p95_ms':round(values[int((len(values)-1)*.95)],3),'max_ms':round(max(values),3)}

if args.probe_writes:
 selected=[Client(a) for a in actors[:8]]
 with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:responses=list(pool.map(lambda c:c.request('profiles/me',{'bio':'Guardado sintético de diagnóstico.'}),selected))
 print(json.dumps([{'status':r['status'],'message':r['data'].get('message'),'id':r['data'].get('id'),'ms':r['ms']} for r in responses]));raise SystemExit(0)
clients=[]
for actor in actors:clients.append(Client(actor))
print('Authenticated 32 independent synthetic sessions.',flush=True)
# Give each implementation the same warm-up. All assertions stay enabled.
for index,client in enumerate(clients):
 for route in routes:
  r=client.request(route);assert r['status']==200,(index,route,r['status'])
 print('Warm-up '+str(index+1)+'/32',flush=True) if (index+1)%8==0 else None

profiles=[]
def run(concurrency,name):
 def worker(index):
  result=[];client=clients[index]
  for n in range(14):
   route=routes[n%len(routes)];r=client.request(route)
   assert r['status']==200,(route,r['status'])
   if route=='bootstrap':assert r['data']['me']['id']==client.actor['id']
   result.append({'route':route,'ms':r['ms'],'bytes':r['bytes']})
  return result
 start=time.perf_counter();rows=[]
 with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as pool:
  for result in pool.map(worker,range(concurrency)):rows+=result
 elapsed=time.perf_counter()-start
 per_route={route:stats([r['ms'] for r in rows if r['route']==route]) for route in routes}
 result={'name':name,'concurrency':concurrency,'requests':len(rows),'seconds':round(elapsed,3),'requests_per_second':round(len(rows)/elapsed,3),'errors':0,**stats([r['ms'] for r in rows]),'routes':per_route}
 profiles.append(result);print(json.dumps(result),flush=True)

for concurrency in ([1,4,12] if args.phase=='baseline' else [1,4,12,24,32]):run(concurrency,'load-'+str(concurrency))
if args.phase=='optimized':
 start=time.perf_counter();deadline=start+60
 def soak(index):
  rows=[];client=clients[index]
  while time.perf_counter()<deadline and len(rows)<1000:
   route=routes[len(rows)%len(routes)];r=client.request(route);assert r['status']==200,(route,r['status']);rows.append(r['ms']);time.sleep(.05)
  return rows
 samples=[]
 with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
  for rows in pool.map(soak,range(4)):samples+=rows
 elapsed=time.perf_counter()-start;profiles.append({'name':'duration','concurrency':4,'requests':len(samples),'seconds':round(elapsed,3),'think_seconds':.05,'errors':0,'requests_per_second':round(len(samples)/elapsed,3),**stats(samples)})
 print(json.dumps(profiles[-1]),flush=True);run(1,'recovery')
checks=[]
if args.phase=='optimized':
 def save(index):
  c=clients[index];r=c.request('profiles/me',{'bio':'Perfil sintético guardado concurrentemente '+str(index)})
  assert r['status']==200 and r['data']['id']==c.actor['id'],(index,r['status'],r['data'].get('message','Unexpected owner'));return r['ms']
 with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:write_times=list(pool.map(save,range(8)))
 for c in clients[:8]:assert c.request('profiles/me',{'bio':c.bio})['status']==200
 checks.append({'name':'Eight concurrent profile writes, correct owner and restored data','status':'PASS',**stats(write_times)})
 event=fixture['event']
 with concurrent.futures.ThreadPoolExecutor(max_workers=16) as pool:responses=list(pool.map(lambda c:c.request(f'events/{event}/register',{'status':'accepted'}),clients[:16]))
 assert sum(r['status']==200 for r in responses)==2 and sum(r['status']==409 for r in responses)==14
 for c,r in zip(clients,responses):
  if r['status']==409:assert c.request(f'events/{event}/register',{'status':'waitlisted'})['status']==200
 detail=clients[0].request(f'events/{event}')['data'];assert detail['attending']==2 and detail['waitlist_count']==14 and detail['remaining']==0
 checks.append({'name':'Sixteen simultaneous RSVPs: exactly two accepted, fourteen capacity conflicts, fourteen waitlisted','status':'PASS','accepted':2,'expected_conflicts':14,'waitlisted':14})
 assert clients[0].request('admin/statistics')['status']==403
 assert clients[0].request('profiles/me',{'bio':'CSRF must be rejected'},nonce=False)['status'] in (401,403)
 try:urllib.request.urlopen(base+'/wp-json/ascla/v1/bootstrap',timeout=20);raise AssertionError('Anonymous private API access')
 except urllib.error.HTTPError as e:assert e.code in (401,403)
 assert 'no-store' in clients[0].request('bootstrap')['cache_control']
 checks.append({'name':'Anonymous access, staff-only statistics and missing nonce rejected; private responses stay no-store','status':'PASS'})
result={'phase':args.phase,'scope':'Loopback HTTP, native plugin volume, 32 independent sessions, same fixture; 7 routes per worker, 14 measured requests per worker after equal warm-up. Concurrency is closed-loop, not a production capacity guarantee.','profiles':profiles,'write_and_security_checks':checks,'total_measured_reads':sum(r['requests'] for r in profiles),'warmup_requests':32*len(routes),'status':'PASS'}
args.output.parent.mkdir(parents=True,exist_ok=True);args.output.write_text(json.dumps(result,indent=2)+'\n');print('PASS '+str(result['total_measured_reads'])+' measured reads',flush=True)
