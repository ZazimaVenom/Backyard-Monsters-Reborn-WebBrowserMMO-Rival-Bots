#!/usr/bin/env python3
"""Exercise branding and legacy session migration over HTTP in an isolated copy.

Requires Python 3 and PHP 8.2+ (PHP_EXE may select the PHP executable).
No game installation or database is read, modified or created.
"""
from pathlib import Path
import tempfile,shutil,subprocess,time,urllib.request,urllib.error,re,json,socket,os
source=Path(__file__).resolve().parents[1];php=os.environ.get('PHP_EXE','php')
checks=[]
def check(ok,label):
 if not ok: raise AssertionError(label)
 checks.append(label)
with tempfile.TemporaryDirectory(prefix='rehatched-http-') as tmp:
 root=Path(tmp);shutil.copytree(source/'app',root/'app');(root/'var/sessions').mkdir(parents=True);(root/'public').mkdir()
 for name in ['setup.php','index.php']:shutil.copy2(source/'public'/name,root/'public'/name)
 sid='legacyBrandSession123'; current='currentBrandSession456'
 (root/'var/sessions'/('sess_'+sid)).write_text('csrf|s:12:"old-csrf-123";user_id|i:987;')
 (root/'var/sessions'/('sess_'+current)).write_text('csrf|s:12:"new-csrf-456";user_id|i:654;')
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 log=open(root/'server.log','w');proc=subprocess.Popen([php,'-S',f'127.0.0.1:{port}','-t',str(root/'public')],stdout=log,stderr=log)
 def request(cookie=None,path='setup.php'):
  req=urllib.request.Request(f'http://127.0.0.1:{port}/{path}',headers={'Cookie':cookie} if cookie else {})
  with urllib.request.urlopen(req,timeout=5) as response:return response.read().decode(),response.headers.get_all('Set-Cookie') or []
 try:
  for i in range(50):
   try: fresh,headers=request();break
   except OSError:time.sleep(.1)
  else:raise RuntimeError('PHP HTTP server failed')
  check('value="backyard_rehatched"' in fresh,'fresh setup uses new database default')
  check('Backyard Monsters Rehatched' in fresh and 'Reborn' not in fresh,'fresh setup displays only current branding')
  check(any(x.startswith('backyard_rehatched=') for x in headers),'fresh browser receives current session cookie')
  body,cookies=request('backyard_reborn='+sid)
  check('value="old-csrf-123"' in body,'legacy session keeps existing CSRF token')
  new=next(x.split(';')[0].split('=',1)[1] for x in cookies if x.startswith('backyard_rehatched='))
  check(new!=sid,'legacy session receives a new session ID')
  check('user_id|i:987;' in (root/'var/sessions'/('sess_'+new)).read_text(),'legacy authenticated user survives cookie migration')
  check(any(x.startswith('backyard_reborn=') and 'Max-Age=0' in x for x in cookies),'legacy cookie expires')
  body,cookies=request('backyard_rehatched='+current+'; backyard_reborn='+sid)
  check('value="new-csrf-456"' in body,'current cookie takes precedence over legacy cookie')
  body,cookies=request('backyard_reborn=../../invalid')
  check('old-csrf-123' not in body and any(x.startswith('backyard_rehatched=') for x in cookies),'invalid legacy session ID is rejected')
  (root/'var/config.php').write_text("<?php return ['database'=>'existing_world'];")
  body,cookies=request(path='index.php')
  check('<title>Backyard Monsters Rehatched</title>' in body,'game page title uses current branding')
  check('<small>REHATCHED</small>' in body and 'Backyard Monsters Rehatched home' in body,'game header and accessible label use current branding')
  check('?v=0.13.1' in body and '?v=0.13.0' not in body,'game shell requests current asset release')
 finally:
  proc.terminate();proc.wait(timeout=5);log.close()
 print(json.dumps({'checks':len(checks),'passed':checks},indent=2))
