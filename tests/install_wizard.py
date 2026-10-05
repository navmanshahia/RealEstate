"""Fresh installer checks; MYSQL_TEST=1 additionally exercises successful MySQL setup."""
import http.cookiejar, json, os, re, shutil, subprocess, tempfile, time
import urllib.request, urllib.parse
from pathlib import Path
root=Path(__file__).resolve().parents[1]
tmp=Path(tempfile.mkdtemp(prefix='estate-wizard-'))
web=tmp/'web'; app=web/'Estate'
shutil.copytree(root,app,ignore=shutil.ignore_patterns('.git','node_modules','config.php','.install-lock'))
server=subprocess.Popen(['php','-S','127.0.0.1:18767','-t',str(web)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
base='http://127.0.0.1:18767/Estate/'
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def post(data):
 return client.open(urllib.request.Request(base+'install.php',data=urllib.parse.urlencode(data).encode())).read().decode()
try:
 time.sleep(1)
 page=client.open(base+'install.php').read().decode()
 csrf=re.search('name="csrf" value="([^"]+)"',page)[1]
 assert 'name="db_name"' in page and 'name="key"' not in page
 assert base[:-1] in page
 data={'csrf':csrf,'db_name':'estate_wizard','db_user':'estate','db_pass':'Wizard-database-pass-123','db_host':'127.0.0.1','name':'Wizard Admin','email':'wizard@example.com','password':'Wizard-admin-pass-123','confirm':'Wizard-admin-pass-123'}
 page=post({**data,'csrf':'wrong'});assert 'Refresh this page' in page
 assert not (app/'app/config.php').exists()
 page=post({**data,'confirm':'mismatch'});assert 'do not match' in page
 page=post({**data,'db_host':'attacker.example.com'});assert 'full cPanel database name' in page
 assert data['password'] not in page and data['db_pass'] not in page
 assert not (app/'app/config.php').exists()
 if os.environ.get('MYSQL_TEST')=='1':
  post(data)
  config=app/'app/config.php';assert config.exists(),'Config was not created'
  original=config.read_bytes()
  assert "http://127.0.0.1:18767/Estate" in config.read_text()
  result=json.load(client.open(base+'api.php?action=session'))
  assert result['user']['role']=='admin' and result['user']['email']=='wizard@example.com',result
  page=client.open(base+'install.php').read().decode();assert 'installer is locked' in page
  post({**data,'email':'replacement@example.com'})
  assert config.read_bytes()==original
  print('PASS: MySQL wizard, config generation, subfolder URL detection, admin sign-in, installer lock and no overwrite')
 else:
  print('PASS: fresh wizard form, subfolder URL detection, CSRF, password confirmation, local-host restriction and no partial config. MySQL success path runs in CI.')
finally:
 server.terminate();server.wait();shutil.rmtree(tmp)
