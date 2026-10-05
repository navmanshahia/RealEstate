"""Run against a disposable PHP server and SQLite database; never production."""
import http.cookiejar,json,os,re,subprocess,tempfile,time,urllib.request,urllib.error,shutil
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
TMP=Path(tempfile.mkdtemp(prefix='estate-test-'))
APP=TMP/'app'
shutil.copytree(ROOT,APP,ignore=shutil.ignore_patterns('.git','node_modules','config.php'))
PORT=18766
(APP/'app/config.php').write_text("<?php return "+"['dsn'=>'sqlite:"+str(TMP/'test.db')+"','db_user'=>null,'db_pass'=>null,'app_url'=>'http://127.0.0.1:"+str(PORT)+"','install_key'=>'"+'T'*40+"','session_secure'=>false,'mail_enabled'=>false,'storage_path'=>'"+str(TMP/'files')+"','stripe_secret'=>'','stripe_webhook_secret'=>'','stripe_prices'=>[],'billing_required'=>false];")
server=subprocess.Popen(['php','-S',f'127.0.0.1:{PORT}','-t',str(APP)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
class Client:
 def __init__(self):self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
 def request(self,action,data=None,token=True):
  req=urllib.request.Request(f'http://127.0.0.1:{PORT}/api.php?action={action}',data=json.dumps(data).encode() if data is not None else None,headers={'Content-Type':'application/json',**({'X-CSRF-Token':self.csrf} if token else {})})
  try:r=self.opener.open(req);return r.status,json.load(r)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 def session(self):s,d=self.request('session');assert s==200,(s,d);self.csrf=d['csrf'];return d
try:
 time.sleep(1)
 a=Client();page=a.opener.open(f'http://127.0.0.1:{PORT}/install.php').read().decode();csrf=re.search('name="csrf" value="([^"]+)"',page)[1]
 form=urllib.parse.urlencode({'csrf':csrf,'key':'T'*40,'name':'Platform Owner','email':'admin@example.com','password':'Admin-pass-12345'}).encode()
 a.opener.open(urllib.request.Request(f'http://127.0.0.1:{PORT}/install.php',data=form)).read();ad=a.session();assert ad['user']['role']=='admin',ad
 b,c=Client(),Client()
 for i,client in enumerate([b,c]):
  client.session();s,d=client.request('register',{'name':f'Agent {i}','email':f'agent{i}@example.com','password':'Agent-password-123'});assert s==200,(s,d);client.session()
 assert b.request('save',{'kind':'contact','data':{'name':'Private client'}},token=False)[0]==419
 s,d=b.request('save',{'kind':'contact','data':{'name':'Private client','email':'client@example.com'}});assert s==200,(s,d);rid=d['id']
 assert not any(r['id']==rid for r in c.request('records')[1])
 assert c.request('save',{'kind':'contact','id':rid,'data':{'name':'Stolen'}})[0]==404
 assert c.request('delete',{'id':rid})[0]==200
 assert any(r['id']==rid for r in b.request('records')[1])
 assert b.request('admin')[0]==403
 assert b.request('save',{'kind':'property','data':{'name':'Invalid','price':-1}})[0]==400
 assert b.request('save',{'kind':'property','data':{'name':'Invalid URL','image':'javascript:alert(1)'}})[0]==400
 s,d=b.request('save',{'kind':'property','data':{'name':'Authorized home','price':1000000,'published':True}});assert s==200;pid=d['id'];wid=b.session()['workspace']['id']
 assert b.request('settings',{'name':'Agent 0','published':True,'email':'agent0@example.com'})[0]==200
 assert len(b.request('public')[1]['properties'])==0
 assert a.request('admin_update',{'id':wid,'status':'approved'})[0]==200
 public=b.request('public')[1];assert any(p['id']==pid for p in public['properties']);assert all('owner' not in w for w in public['agents'])
 anon=Client();anon.session();s,d=anon.request('enquiry',{'workspace':wid,'property':pid,'name':'Buyer Test','email':'buyer@example.com','message':'A showing, please'});assert s==200,(s,d)
 assert any(r['data']['name']=='Buyer Test' for r in b.request('records')[1])
 assert not any(r['data']['name']=='Buyer Test' for r in c.request('records')[1])
 assert b.request('team_add',{'name':'Colleague','email':'team@example.com','password':'Team-password-123'})[0]==200
 teammate=Client();teammate.session();assert teammate.request('login',{'email':'team@example.com','password':'Team-password-123'})[0]==200;teammate.session()
 assert any(r['id']==rid for r in teammate.request('records')[1])
 assert teammate.request('settings',{'name':'Hijacked'})[0]==403
 assert a.request('admin_update',{'id':wid,'status':'suspended'})[0]==200
 assert b.request('records')[0]==403
 assert not any(p['id']==pid for p in c.request('public')[1]['properties'])
 assert a.request('admin_update',{'id':wid,'status':'approved'})[0]==200
 assert b.request('password',{'current':'Agent-password-123','password':'Changed-password-123'})[0]==200
 assert b.session()['user'] is not None
 assert b.request('logout',{})[0]==200
 assert b.request('records')[0]==401
 assert 'installer is locked' in a.opener.open(f'http://127.0.0.1:{PORT}/install.php').read().decode()
 assert anon.request('stripe_webhook',{})[0] in [503,400]
 print('PASS: installer, authentication, CSRF, tenant isolation, validation, publication approval, enquiries, team roles, suspension, password changes, logout, webhook configuration guard')
finally:
 server.terminate();server.wait();shutil.rmtree(TMP)
