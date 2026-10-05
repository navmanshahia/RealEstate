<?php
declare(strict_types=1);
function config():array {static $c;return $c??=require __DIR__.'/config.php';}
function db():PDO {static $p;if(!$p){$c=config();$p=new PDO($c['dsn'],$c['db_user']??null,$c['db_pass']??null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);if(str_starts_with($c['dsn'],'sqlite:'))$p->exec('PRAGMA foreign_keys=ON');}return $p;}
function query(string $sql,array $args=[]):PDOStatement{$s=db()->prepare($sql);$s->execute($args);return $s;}
function uuid():string{return bin2hex(random_bytes(16));}
function now():string{return gmdate('Y-m-d H:i:s');}
function fail(string $message,int $status=400):never{http_response_code($status);echo json_encode(['error'=>$message]);exit;}
function respond(mixed $v):never{echo json_encode($v,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function session_boot():void{session_name('estate_session');ini_set('session.use_strict_mode','1');session_set_cookie_params(['httponly'=>true,'secure'=>config()['session_secure']??true,'samesite'=>'Lax','path'=>parse_url(config()['app_url'],PHP_URL_PATH)?:'/']);session_start();$_SESSION['csrf']??=bin2hex(random_bytes(32));if(isset($_SESSION['last'])&&time()-$_SESSION['last']>7200){unset($_SESSION['user']);session_regenerate_id(true);}$_SESSION['last']=time();}
function csrf():void{if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail('Your session changed. Refresh and try again.',419);}
function body():array{$raw=file_get_contents('php://input');if(strlen($raw)>100000)fail('Request too large',413);$b=json_decode($raw,true);if(!is_array($b))fail('Invalid request');return $b;}
function user(bool $required=true):?array{$u=isset($_SESSION['user'])?query('SELECT id,workspace,name,email,role,active,password FROM users WHERE id=?',[$_SESSION['user']])->fetch():false;if(!$u||!$u['active']||!hash_equals($_SESSION['version']??'',hash('sha256',$u['password']))){if($required)fail('Please sign in',401);return null;}unset($u['password']);return $u;}
function admin():array{$u=user();if($u['role']!=='admin')fail('Administrator access required',403);return $u;}
function member():array{$u=user();$w=query('SELECT * FROM workspaces WHERE id=?',[$u['workspace']])->fetch();if(!$w||$w['status']==='suspended')fail('Workspace is suspended',403);return $u;}
function clean(mixed $s,int $max=200):string{return mb_substr(trim((string)$s),0,$max);}
function email(mixed $s):string{$s=strtolower(clean($s,254));if(!filter_var($s,FILTER_VALIDATE_EMAIL))fail('Enter a valid email address');return $s;}
function audit(string $action,string $record=''):void{$u=user(false);query('INSERT INTO audit (id,workspace,user_id,action,record_id,created) VALUES (?,?,?,?,?,?)',[uuid(),$u['workspace']??'', $u['id']??'', $action,$record,now()]);}
function rate(string $key,int $max=15,int $window=900):void{$key=hash('sha256',$key);$cut=gmdate('Y-m-d H:i:s',time()-$window);query('DELETE FROM attempts WHERE created < ?',[$cut]);$n=query('SELECT COUNT(*) FROM attempts WHERE bucket=?',[$key])->fetchColumn();if($n>=$max)fail('Too many attempts. Please try again later.',429);query('INSERT INTO attempts (id,bucket,created) VALUES (?,?,?)',[uuid(),$key,now()]);}
function workspacePublic(array $w):array{$d=json_decode($w['data'],true)?:[];return ['id'=>$w['id'],'name'=>$w['name'],'slug'=>$w['slug'],'data'=>$d];}
function recordPublic(array $r):array{$d=json_decode($r['data'],true)?:[];$keys=['name','price','beds','baths','area','location','type','status','image','images','description','published','video'];return ['id'=>$r['id'],'workspace'=>$r['workspace'],'kind'=>'property','data'=>array_intersect_key($d,array_flip($keys))];}
function schema():void{foreach([
'CREATE TABLE IF NOT EXISTS workspaces (id VARCHAR(32) PRIMARY KEY,name VARCHAR(150) NOT NULL,slug VARCHAR(180) NOT NULL UNIQUE,data TEXT NOT NULL,status VARCHAR(30) NOT NULL,plan VARCHAR(30) NOT NULL,subscription_status VARCHAR(30) NOT NULL,customer VARCHAR(100),created VARCHAR(30) NOT NULL)',
'CREATE TABLE IF NOT EXISTS users (id VARCHAR(32) PRIMARY KEY,workspace VARCHAR(32) NOT NULL,name VARCHAR(150) NOT NULL,email VARCHAR(254) NOT NULL UNIQUE,password VARCHAR(255) NOT NULL,role VARCHAR(30) NOT NULL,active INTEGER NOT NULL DEFAULT 1,created VARCHAR(30) NOT NULL)',
'CREATE TABLE IF NOT EXISTS records (id VARCHAR(32) PRIMARY KEY,workspace VARCHAR(32) NOT NULL,kind VARCHAR(30) NOT NULL,data TEXT NOT NULL,created VARCHAR(30) NOT NULL,updated VARCHAR(30) NOT NULL)',
'CREATE TABLE IF NOT EXISTS files (id VARCHAR(32) PRIMARY KEY,workspace VARCHAR(32) NOT NULL,name VARCHAR(200) NOT NULL,mime VARCHAR(100) NOT NULL,size INTEGER NOT NULL,created VARCHAR(30) NOT NULL)',
'CREATE TABLE IF NOT EXISTS audit (id VARCHAR(32) PRIMARY KEY,workspace VARCHAR(32) NOT NULL,user_id VARCHAR(32) NOT NULL,action VARCHAR(100) NOT NULL,record_id VARCHAR(100) NOT NULL,created VARCHAR(30) NOT NULL)',
'CREATE TABLE IF NOT EXISTS attempts (id VARCHAR(32) PRIMARY KEY,bucket VARCHAR(64) NOT NULL,created VARCHAR(30) NOT NULL)',
'CREATE TABLE IF NOT EXISTS resets (id VARCHAR(64) PRIMARY KEY,user_id VARCHAR(32) NOT NULL,expires VARCHAR(30) NOT NULL)',
'CREATE TABLE IF NOT EXISTS events (id VARCHAR(150) PRIMARY KEY,created VARCHAR(30) NOT NULL)',
'CREATE INDEX records_workspace ON records (workspace,kind)',
'CREATE INDEX attempts_bucket ON attempts (bucket,created)',
'CREATE INDEX audit_workspace ON audit (workspace,created)'
] as $sql){try{db()->exec($sql);}catch(PDOException $e){if(!str_starts_with($sql,'CREATE INDEX'))throw $e;}}}
function validateRecord(string $kind,array $d):array{if(!in_array($kind,['contact','deal','property','task','appointment','campaign','document','activity']))fail('Unknown record type');$d['name']=clean($d['name']??'',200);if(!$d['name'])fail('A name or title is required');if(strlen(json_encode($d))>40000)fail('Record too large');foreach($d as $k=>$v){if(!is_scalar($v)&&!is_null($v))fail('Invalid field');if(is_string($v))$d[$k]=clean($v,10000);}foreach(['price','beds','baths','area','value','budget','commission'] as $key){if(isset($d[$key])&&$d[$key]!==''){if(!is_numeric($d[$key])||$d[$key]<0||$d[$key]>1e12)fail('Enter a valid '.$key);$d[$key]=(float)$d[$key];}}if(!empty($d['email']))$d['email']=email($d['email']);foreach(['date','due'] as $k){if(!empty($d[$k])&&!preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/',$d[$k]))fail('Invalid date');}foreach(['image','url','video'] as $k){if(!empty($d[$k])&&!preg_match('~^(https://|api\.php\?action=image&id=[a-f0-9]{32}$)~',$d[$k]))fail('Use a secure HTTPS link');}if($kind==='property')$d['published']=!empty($d['published']);return $d;}
function saveRecord(string $workspace,string $kind,array $d,?string $id=null):string{$d=validateRecord($kind,$d);$time=now();if($id){if(!query('SELECT id FROM records WHERE id=? AND workspace=? AND kind=?',[$id,$workspace,$kind])->fetch())fail('Record not found',404);query('UPDATE records SET data=?,updated=? WHERE id=? AND workspace=?',[json_encode($d),$time,$id,$workspace]);}else{$id=uuid();query('INSERT INTO records (id,workspace,kind,data,created,updated) VALUES (?,?,?,?,?,?)',[$id,$workspace,$kind,json_encode($d),$time,$time]);}return $id;}

function entitled(array $w):bool{if(empty(config()['billing_required']))return true;if(in_array($w['subscription_status'],['active','trialing']))return true;return $w['subscription_status']==='trial'&&strtotime($w['created'])>time()-14*86400;}
