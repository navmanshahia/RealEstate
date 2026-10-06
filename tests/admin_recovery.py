"""Exercise CLI-only admin recovery against a disposable database."""
import json, os, re, shutil, subprocess, tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='estate-recovery-') as temp:
 app=Path(temp);(app/'app').mkdir()
 shutil.copy(root/'app/core.php',app/'app/core.php')
 (app/'app/config.php').write_text("<?php return ['dsn'=>'sqlite:"+str(app/'test.db')+"','app_url'=>'https://example.com/Estate','session_secure'=>false];")
 fixture=app/'fixture.php'
 fixture.write_text('''<?php
 require __DIR__.'/app/core.php';schema();
 query('INSERT INTO workspaces (id,name,slug,data,status,plan,subscription_status,created) VALUES (?,?,?,?,?,?,?,?)',['w','Test','test','{}','approved','Team','active',now()]);
 foreach([['admin','admin@example.com','admin'],['agent','agent@example.com','owner']] as [$id,$email,$role])query('INSERT INTO users (id,workspace,name,email,password,role,active,created) VALUES (?,?,?,?,?,?,?,?)',[$id,'w',$id,$email,password_hash('Old-password-12345',PASSWORD_DEFAULT),$role,1,now()]);
 query('INSERT INTO resets (id,user_id,expires) VALUES (?,?,?)',['token','admin','2099-01-01']);
 ''')
 subprocess.run(['php',str(fixture)],check=True,capture_output=True)
 cmd=['php',str(root/'scripts/reset-admin.php'),str(app)]
 bad=subprocess.run(cmd+['agent@example.com'],capture_output=True,text=True)
 assert 'New password:' not in bad.stdout and 'No unique administrator selected' in (bad.stdout+bad.stderr)
 result=subprocess.run(cmd,capture_output=True,text=True);assert result.returncode==0,'Recovery command failed'
 match=re.search(r'New password: ([A-Za-z0-9_-]{32})',result.stdout);assert match,'Missing generated password'
 password=match[1]
 assert 'Email: admin@example.com' in result.stdout
 check=app/'check.php'
 check.write_text('''<?php require __DIR__.'/app/core.php';
 $admin=query("SELECT password FROM users WHERE id='admin'")->fetchColumn();
 $agent=query("SELECT password FROM users WHERE id='agent'")->fetchColumn();
 echo json_encode([password_verify('''+repr(password)+''',$admin),password_verify('Old-password-12345',$agent),(int)query("SELECT COUNT(*) FROM resets WHERE user_id='admin'")->fetchColumn(),(int)query("SELECT COUNT(*) FROM audit WHERE action='admin.password_recovery_cli'")->fetchColumn()]);''')
 verified=subprocess.run(['php',str(check)],capture_output=True,text=True,check=True)
 assert json.loads(verified.stdout)==[True,True,0,1]
 assert password not in (app/'test.db').read_bytes().decode('latin1')
 print('PASS: existing admin recovery, random hashed password, token revocation, audit trail, non-admin rejection, other accounts unchanged')
