"""Write a bounded, authenticated READ API contract for an isolated local QA run.

This does not claim all REST routes: destructive/account/integration operations are
checked separately by integration/E2E, and never sent to an arbitrary remote host.
"""
import json,sys
from pathlib import Path
out=Path(sys.argv[1]);actors=json.loads((out/'private-actors.json').read_text())
base=actors['base'];paths={}
def operation(route,description,parameters=()):
    paths[route]={'get':{'summary':description,'parameters':list(parameters),'responses':{'200':{'description':'Authorized JSON response'},'400':{'description':'Rejected malformed input'},'401':{'description':'Authentication required'},'403':{'description':'Insufficient capability'}},'security':[{'wpNonce':[],'wpCookie':[]}]}}
def query(name,example):return {'name':name,'in':'query','schema':{'type':'string'},'example':example}
operation('/bootstrap','Current account bootstrap')
operation('/profiles','Directory search',[query('q','Prueba'),query('country','Perú')])
member=actors['actors']['Asociado']['id']
operation('/profiles/'+str(member),'Synthetic member profile')
for kind in ['hub','topic','resource','event','gallery','ally']:
    operation('/content/'+kind,'Published '+kind,[query('q','ASCLA'),query('from','2026-01-01'),query('to','2026-12-31')])
operation('/locations/countries','Country catalog')
operation('/locations/cities','City suggestions',[query('country','Perú'),query('q','Lima')])
operation('/recommendations','Authorized recommendations')
operation('/interests/learned','Learned interests for the current synthetic account')
operation('/notifications','Current account notifications')
operation('/admin/users','Administrative directory',[query('q','Prueba')])
operation('/admin/users/'+str(member),'Administrative synthetic profile')
operation('/admin/users/'+str(member)+'/insights','Synthetic account activity')
operation('/settings','Redacted integration and application status')
spec={'openapi':'3.0.3','info':{'title':'ASCLA scoped read API security scan','version':'1.10.9','description':'18 GET operations in disposable QA. Excludes writes, account credentials and external integration jobs; those retain their separate permission and E2E tests.'},'servers':[{'url':base+'/wp-json/ascla/v1'}],'paths':paths,'components':{'securitySchemes':{'wpNonce':{'type':'apiKey','in':'header','name':'X-WP-Nonce'},'wpCookie':{'type':'apiKey','in':'cookie','name':'wordpress_logged_in'}}}}
(out/'openapi.json').write_text(json.dumps(spec,ensure_ascii=False,indent=2)+'\n')
print('OpenAPI read operations:',len(paths))
