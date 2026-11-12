"""Real ZAP engine: scoped spiders + active web scan, or OpenAPI import + active API scan.

Run inside the official ZAP image, on isolated local QA. No remote active mode.
Native HTML/JSON reports retain all findings; a separate gate applies project rules.
"""
import argparse,json,os,re,secrets,subprocess,time
from pathlib import Path
from urllib.parse import urlparse
import requests
from zapv2 import ZAPv2

parser=argparse.ArgumentParser();parser.add_argument('kind',choices=['frontend','backend']);parser.add_argument('--directory',default='/zap/wrk');args=parser.parse_args()
out=Path(args.directory);auth=json.loads((out/'private-session.json').read_text());base=auth['base'];host=urlparse(base).hostname or ''
if os.environ.get('ASCLA_E2E_EPHEMERAL')!='1' or not re.fullmatch(r'ascla-zap109-[a-z0-9-]+\.test',host):raise RuntimeError('Only the disposable ASCLA TLS test host is accepted')
role=auth['Administrador'];key=secrets.token_hex(24);port=8090
log=(out/(args.kind+'-engine.log')).open('w')
process=subprocess.Popen(['zap.sh','-daemon','-host','127.0.0.1','-port',str(port),'-config','api.key='+key,'-config','api.addrs.addr.name=127.0.0.1','-config','api.addrs.addr.regex=false','-config','connection.timeoutInSecs=15'],stdout=log,stderr=subprocess.STDOUT)
class LoopbackZAP(ZAPv2):
    """Keep generated API wrappers but send keyed requests directly to loopback.

    The upstream client requires a synthetic http://zap URL, while current daemon
    host validation rejects that Host. No wildcard API host permissions are used.
    """
    def _request_api(self,url,get=None,method='GET',body=None):
        if not url.startswith('http://zap/'):raise ValueError('Only generated ZAP API URLs are accepted')
        params=dict(get or {});params['apikey']=key
        endpoint='http://127.0.0.1:'+str(port)+'/'+url.removeprefix('http://zap/')
        session=requests.Session();session.trust_env=False
        response=session.request(method,endpoint,params=params,data=body,timeout=(3,60))
        if not response.ok:
            try:detail=response.json()
            except ValueError:detail={'code':response.status_code,'message':'API request failed'}
            raise RuntimeError('ZAP API '+url.removeprefix('http://zap/').split('?')[0]+': '+str(detail.get('code'))+' '+str(detail.get('message')))
        return response

    def _request(self,url,get=None,method='GET',body=None):
        data=self._request_api(url,get,method,body).json()
        if isinstance(data,dict) and 'code' in data and 'message' in data:raise RuntimeError('ZAP API: '+str(data['code'])+' '+str(data['message']))
        return data

zap=LoopbackZAP(apikey=key)
result={'kind':args.kind,'status':'ERROR','scope':[],'active_complete':False,'phases':[]};context_name='ASCLA '+args.kind
(out/(args.kind+'-result.json')).write_text(json.dumps(result,indent=2)+'\n')
last_health=0
def authentication_health():
    global last_health
    session=requests.Session();session.trust_env=False
    response=session.get(base+'/wp-json/ascla/v1/bootstrap',headers={'Cookie':role['cookie'],'X-WP-Nonce':role['nonce']},verify=str(out/'qa-cert.pem'),timeout=20)
    if not response.ok:
        try:code=response.json().get('code','unknown')
        except ValueError:code='non-json'
        code=str(code) if re.fullmatch(r'[a-z0-9_-]+',str(code)) else 'unclassified'
        result['authentication_failure']={'http_status':response.status_code,'code':code}
        raise RuntimeError('Authentication health failed: HTTP '+str(response.status_code)+' ('+code+')')
    response.raise_for_status();data=response.json()
    expected=json.loads((out/'private-actors.json').read_text())['actors']['Administrador']['id']
    if not data.get('admin') or int(data.get('me',{}).get('id',0))!=expected:raise RuntimeError('Authenticated administrator session was lost; scan cannot pass')
    # Explicit authorized test-client activity; passive polling alone never renews sessions.
    renewed=session.post(base+'/wp-json/ascla/v1/account/session',headers={'Cookie':role['cookie'],'X-WP-Nonce':role['nonce']},verify=str(out/'qa-cert.pem'),timeout=20)
    renewed.raise_for_status()
    last_health=time.monotonic()
def wait(label,status,limit=1200):
    until=time.monotonic()+limit;last=None
    while time.monotonic()<until:
        if time.monotonic()-last_health>240:authentication_health()
        value=int(status())
        if value==100:return
        if value!=last:print(label+': '+str(value)+'%',flush=True);last=value
        time.sleep(3)
    raise TimeoutError(label+' did not complete; this is not a passed scan')

def completed_rules(progress,enabled):
    rows={}
    def visit(value):
        if isinstance(value,dict):
            plugin=value.get('Plugin')
            if isinstance(plugin,list) and len(plugin)>=7:
                rows[str(plugin[1])]={'id':str(plugin[1]),'name':str(plugin[0]),'status':str(plugin[3]),'requests':int(plugin[5])}
            for item in value.values():visit(item)
        elif isinstance(value,list):
            for item in value:visit(item)
    visit(progress)
    incomplete=[identifier+': '+rows.get(identifier,{}).get('status','Missing') for identifier in enabled if rows.get(identifier,{}).get('status')!='Complete']
    if incomplete:raise RuntimeError('Active rules did not complete: '+', '.join(incomplete))
    engine=log.name and Path(log.name).read_text(errors='replace')
    if re.search(r'OutOfMemoryError|Skipping scanner, failed to start browser|ERROR[^\n]*DomXssScanRule',engine):
        raise RuntimeError('Active engine or DOM XSS browser failed; inspect the private engine log')
    return [rows[identifier] for identifier in enabled]
try:
    for attempt in range(90):
        try:
            version=zap.core.version
            if version:break
        except requests.RequestException:pass
        time.sleep(1)
    else:raise TimeoutError('ZAP did not start')
    result['engine_version']=version;print('ZAP '+version+' '+args.kind,flush=True)
    authentication_health();result['authenticated_before']=True
    cid=zap.context.new_context(context_name);escaped=re.escape(base)
    slugs=['login','intranet','perfil','eventos','hub','galeria','foros','directorio','centro-conocimiento','asistente','aliados','mensajeria','contacto','administracion']
    if args.kind=='frontend':
        pattern=escaped+r'/(?:(?:'+('|'.join(slugs))+r')/?(?:\?[^#]*)?|(?:wp-content|wp-includes|wp-admin/css)/[^?#]+(?:\?[^#]*)?)$'
        targets=[base+'/'+slug+'/' for slug in slugs]
    else:
        spec=json.loads((out/'openapi.json').read_text());targets=[base+'/wp-json/ascla/v1'+route for route in spec['paths']]
        alternatives='|'.join(re.escape(urlparse(url).path) for url in targets)
        pattern=escaped+'(?:'+alternatives+')(?:\\?[^#]*)?$'
    zap.context.include_in_context(context_name,pattern);zap.context.set_context_in_scope(context_name,True);zap.pscan.set_scan_only_in_scope(True)
    # DOM XSS clicks elements (including parent divs), so an include-only context
    # does not prevent it from following the native logout link. Exclude these
    # out-of-scope session/account mutations explicitly at its browser proxy.
    exclusions=[escaped+r'/wp-login\.php(?:\?.*)?$',escaped+r'/wp-admin/(?!css/).*',escaped+r'/wp-json/ascla/v1/account/password(?:-reset)?(?:\?.*)?$']
    for excluded in exclusions:zap.context.exclude_from_context(context_name,excluded)
    result['browser_context_exclusions']=exclusions
    result['scope']=targets;result['scope_regex']=pattern
    cookie_url=escaped+r'/(?!login(?:/|\?|$)).*' if args.kind=='frontend' else pattern
    zap.replacer.add_rule('Synthetic QA cookie',True,'REQ_HEADER',False,'Cookie',replacement=role['cookie'],url=cookie_url)
    zap.replacer.add_rule('WordPress REST nonce',True,'REQ_HEADER',False,'X-WP-Nonce',replacement=role['nonce'],url=escaped+r'/wp-json/ascla/v1/.*')
    for token in ['_ascla_login_nonce','_ascla_language_nonce','_ascla_native_login_nonce','_wpnonce']:
        zap.acsrf.add_option_token(token)
    result['active_threads_per_host']=8
    result['dom_xss_threads_per_host']=1
    # Keep all enabled rules. LOW reduces payload count, not the list of rules.
    scanners=zap.ascan.scanners()
    installed=[rule['id'] for rule in scanners if str(rule.get('enabled')).lower()=='true']
    # Log4Shell targets Java/Log4j; this disposable application is WordPress/PHP
    # with Apache and MariaDB. Declare its technology scope before any attacks.
    # This is not an alert filter: any non-informational alert still fails.
    result['technology_profile']='WordPress/PHP, Apache, MariaDB; no Java application or Log4j runtime'
    result['installed_enabled_active_rule_ids']=installed
    result['not_applicable_active_rules']=[{'id':'40043','name':'Log4Shell','technology':'Java / Log4j',
        'reason':'The owned application has no Java/Log4j runtime. Java OAST/DNS testing is outside this PHP application scope.',
        'reference':'https://www.zaproxy.org/docs/desktop/addons/active-scan-rules/#log4shell-cve-2021-44228-and-cve-2021-45046'}] if '40043' in installed else []
    enabled=[identifier for identifier in installed if identifier!='40043']
    if not enabled:raise RuntimeError('No active rules enabled; scan cannot pass')
    for identifier in enabled:zap.ascan.set_scanner_attack_strength(identifier,'LOW')
    result['active_strength']='LOW';result['enabled_active_rule_ids']=enabled
    for target in targets:zap.core.access_url(target,followredirects=False)
    if args.kind=='frontend':
        # Static assets remain passively scanned; active injection targets are ASCLA HTML.
        zap.ascan.exclude_from_scan(escaped+r'/(?:wp-content|wp-includes|wp-admin/css)/.*')
        zap.spider.set_option_max_duration(1)
        sid=zap.spider.scan(base+'/login/',contextname=context_name,recurse=True,maxchildren=20)
        wait('Traditional Spider',lambda:zap.spider.status(sid),180);result['phases'].append('traditional-spider')
        client=zap.clientSpider.scan(browser='firefox-headless',url=base+'/perfil/',contextname=context_name,maxcrawldepth=2,numberofbrowsers=1,scopecheck='STRICT',logoutavoidance=True)
        until=time.monotonic()+60
        while time.monotonic()<until and int(zap.clientSpider.status(client))<100:time.sleep(3)
        if int(zap.clientSpider.status(client))<100:zap.clientSpider.stop(client)
        result['client_spider_limit_seconds']=60;result['phases'].append('client-spider')
    else:
        imported=zap.openapi.import_file(str(out/'openapi.json'),contextid=cid)
        result['openapi_import']=imported;result['phases'].append('openapi-import')
    # Schedule every enabled rule across two passes. The DOM rule gets a single
    # browser; HTTP rules retain bounded parallelism. No rule is omitted overall.
    if '40026' not in enabled:raise RuntimeError('Required DOM XSS rule is unavailable')
    http_rules=[identifier for identifier in enabled if identifier!='40026']
    result['active_scan_progress']={};result['completed_active_rules']=[];message_ids=set()
    for label,identifiers,threads in [('DOM XSS',['40026'],1),('HTTP',http_rules,8)]:
        zap.ascan.disable_all_scanners()
        zap.ascan.enable_scanners(','.join(identifiers))
        zap.ascan.set_option_thread_per_host(threads)
        aid=zap.ascan.scan(recurse=True,contextid=cid)
        wait('Active Scan '+label,lambda:zap.ascan.status(aid),3600)
        message_ids.update(zap.ascan.messages_ids(aid))
        progress=zap.ascan.scan_progress(aid);result['active_scan_progress'][label]=progress
        result['completed_active_rules'].extend(completed_rules(progress,identifiers))
        authentication_health()
    zap.ascan.enable_scanners(','.join(enabled))
    if not message_ids:raise RuntimeError('Active scanner sent no attack messages')
    result['active_request_count']=len(message_ids)
    result['active_targets_completed']=targets
    result['active_complete']=True;result['phases'].append('active-scan')
    deadline=time.monotonic()+180
    while int(zap.pscan.records_to_scan)>0 and time.monotonic()<deadline:time.sleep(2)
    if int(zap.pscan.records_to_scan)>0:raise TimeoutError('Passive scan incomplete')
    result['phases'].append('passive-scan');result['urls_seen']=zap.core.urls(baseurl=base)
    authentication_health();result['authenticated_after']=True
    (out/(args.kind+'-zap.json')).write_text(zap.core.jsonreport())
    (out/(args.kind+'-zap.html')).write_text(zap.core.htmlreport())
    rules={}
    for line in Path('/work/security/zap-rules.tsv').read_text().splitlines():
        if not line.strip() or line.startswith('#'):continue
        identifier,level,reason=line.split('\t',2)
        if level not in ['FAIL','WARN','IGNORE'] or not reason.strip():raise ValueError('Invalid project rule classification')
        rules[identifier]=(level,reason)
    alerts=json.loads((out/(args.kind+'-zap.json')).read_text());findings=[];risk_counts={str(i):0 for i in range(4)}
    for site in alerts.get('site',[]):
        for alert in site.get('alerts',[]):
            risk=int(alert['riskcode']);risk_counts[str(risk)]+=1
            identifier=alert.get('alertRef',alert['pluginid']);level,reason=rules.get(identifier,rules.get(alert['pluginid'],('FAIL' if risk else 'INFO','Unlisted alert: security gate uses actual risk level.')))
            if risk==0:level='INFO'
            findings.append({'id':identifier,'risk':risk,'name':alert['alert'],'classification':level,'reason':reason,'instances':len(alert.get('instances',[]))})
    result['findings']=findings;result['risk_counts']=risk_counts
    result['status']='FAIL' if any(f['classification']=='FAIL' for f in findings) else 'WARN' if any(f['classification']=='WARN' for f in findings) else 'PASS'
    print(json.dumps({'kind':args.kind,'status':result['status'],'risk_counts':risk_counts,'findings':findings},ensure_ascii=False),flush=True)
except Exception as error:
    message=str(error)
    for secret in [key,role['cookie'],role['nonce']]:message=message.replace(secret,'[redacted]')
    result['error']=message;print('ERROR '+args.kind+': '+message,flush=True)
finally:
    (out/(args.kind+'-result.json')).write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    try:zap.core.shutdown()
    except (requests.RequestException,RuntimeError,ValueError):process.terminate()
    try:process.wait(timeout=30)
    except subprocess.TimeoutExpired:process.kill()
    log.close()
raise SystemExit({'PASS':0,'WARN':2,'FAIL':1,'ERROR':3}[result['status']])
