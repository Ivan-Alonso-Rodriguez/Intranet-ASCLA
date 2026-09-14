"""Local SonarQube runner. Secrets stay in an ignored file and process environment."""
from pathlib import Path
import base64, datetime, json, os, secrets, subprocess, sys, urllib.request, urllib.parse, urllib.error
ROOT=Path(__file__).resolve().parents[1]
BASE='http://127.0.0.1:9001'
SECRET=ROOT/'secrets/sonar-local.json'

def call(endpoint,data=None,auth=None):
    headers={}
    if auth: headers['Authorization']=auth
    body=urllib.parse.urlencode(data).encode() if data is not None else None
    with urllib.request.urlopen(urllib.request.Request(BASE+'/'+endpoint,body,headers),timeout=30) as response:
        raw=response.read()
        return json.loads(raw) if raw else {}

def basic(password):
    return 'Basic '+base64.b64encode(('admin:'+password).encode()).decode()

def initialize():
    SECRET.parent.mkdir(exist_ok=True)
    config=json.loads(SECRET.read_text()) if SECRET.exists() else {'password':secrets.token_urlsafe(36),'initialized':False}
    SECRET.write_text(json.dumps(config))
    if not config['initialized']:
        try: call('api/users/change_password',{'login':'admin','previousPassword':'admin','password':config['password']},basic('admin'))
        except urllib.error.HTTPError:
            if not call('api/authentication/validate',auth=basic(config['password'])).get('valid'): raise
        config['initialized']=True;SECRET.write_text(json.dumps(config))
    auth=basic(config['password'])
    projects=call('api/projects/search?projects=ascla-core',auth=auth)
    if not projects.get('components'): call('api/projects/create',{'project':'ascla-core','name':'ASCLA Core'},auth)
    if not config.get('token'):
        token=call('api/user_tokens/generate',{'name':'ascla-local-analysis','type':'USER_TOKEN','expirationDate':(datetime.date.today()+datetime.timedelta(days=7)).isoformat()},auth)
        config['token']=token['token'];SECRET.write_text(json.dumps(config))
    print('Local SonarQube initialized; credentials saved only in ignored secrets/sonar-local.json.')

def gate():
    config=json.loads(SECRET.read_text());auth='Bearer '+config['token'];name='ASCLA Release'
    try: current=call('api/qualitygates/show?name='+urllib.parse.quote(name),auth=auth)
    except urllib.error.HTTPError as exc:
        if exc.code!=404: raise
        call('api/qualitygates/create',{'name':name},auth);current={'conditions':[]}
    existing={c['metric'] for c in current.get('conditions',[])}
    conditions=[('coverage','LT','80'),('duplicated_lines_density','GT','4.99'),('security_rating','GT','2'),('reliability_rating','GT','2'),('sqale_rating','GT','3'),('bugs','GT','0'),('vulnerabilities','GT','0'),('accepted_issues','GT','0'),('security_hotspots_reviewed','LT','100')]
    for metric,op,error in conditions:
        if metric not in existing: call('api/qualitygates/create_condition',{'gateName':name,'metric':metric,'op':op,'error':error},auth)
    call('api/qualitygates/select',{'gateName':name,'projectKey':'ascla-core'},auth)
    print('ASCLA Release gate configured with the requested thresholds.')

def scan():
    lcov=ROOT/'coverage/lcov.info'
    if lcov.exists(): lcov.write_text(lcov.read_text().replace('SF:wp-content\\plugins\\ascla-core\\assets\\','SF:wp-content/plugins/ascla-core/assets/'))
    config=json.loads(SECRET.read_text());env=os.environ.copy();env['SONAR_TOKEN']=config['token']
    result=subprocess.run(['docker','compose','-f','compose.quality.yaml','run','--rm','scanner'],cwd=ROOT,env=env)
    if result.returncode: raise RuntimeError('Sonar scanner failed')

def report():
    config=json.loads(SECRET.read_text());auth='Bearer '+config['token'];target=ROOT/'test-results';target.mkdir(exist_ok=True)
    metrics='bugs,vulnerabilities,code_smells,duplicated_lines_density,coverage,line_coverage,branch_coverage,security_rating,reliability_rating,sqale_rating,security_hotspots,security_hotspots_reviewed,accepted_issues,ncloc'
    endpoints={'sonar-metrics':'api/measures/component?component=ascla-core&metricKeys='+metrics,'sonar-issues':'api/issues/search?componentKeys=ascla-core&ps=500&resolved=false','sonar-hotspots':'api/hotspots/search?projectKey=ascla-core&ps=500','sonar-gate':'api/qualitygates/project_status?projectKey=ascla-core'}
    for name,url in endpoints.items():
        value=call(url,auth=auth);(target/(name+'.json')).write_text(json.dumps(value,indent=2),encoding='utf8')
        print(name+': saved')

if __name__=='__main__':
    {'init':initialize,'gate':gate,'scan':scan,'report':report}[sys.argv[1]]()
