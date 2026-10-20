<?php
declare(strict_types=1);

/** Additive, idempotent tables. Existing Estate records are not modified. */
function integration_schema(): void {
    static $checked = false;
    if ($checked) return;
    try { query('SELECT verified FROM client_accounts LIMIT 1'); query('SELECT id FROM client_verifications LIMIT 1'); query('SELECT domain FROM site_domains LIMIT 1'); query('SELECT id FROM notification_log LIMIT 1'); query('SELECT campaign_id FROM campaign_deliveries LIMIT 1'); $checked=true; return; }
    catch (PDOException $e) { /* Existing installations can upgrade without reinstalling. */ }
    foreach ([
        'CREATE TABLE IF NOT EXISTS site_domains (domain VARCHAR(190) PRIMARY KEY,workspace VARCHAR(32) NOT NULL,token VARCHAR(64) NOT NULL,verified INTEGER NOT NULL DEFAULT 0,created VARCHAR(30) NOT NULL)',
        'CREATE TABLE IF NOT EXISTS client_accounts (id VARCHAR(32) PRIMARY KEY,workspace VARCHAR(32) NOT NULL,email VARCHAR(254) NOT NULL,name VARCHAR(150) NOT NULL,password VARCHAR(255) NOT NULL,verified INTEGER NOT NULL DEFAULT 0,created VARCHAR(30) NOT NULL,UNIQUE (workspace,email))',
        'CREATE TABLE IF NOT EXISTS client_verifications (id VARCHAR(64) PRIMARY KEY,client_id VARCHAR(32) NOT NULL,expires VARCHAR(30) NOT NULL)',
        'CREATE TABLE IF NOT EXISTS client_favourites (client_id VARCHAR(32) NOT NULL,property_id VARCHAR(32) NOT NULL,PRIMARY KEY (client_id,property_id))',
        'CREATE TABLE IF NOT EXISTS notification_log (id VARCHAR(32) PRIMARY KEY,workspace VARCHAR(32) NOT NULL,kind VARCHAR(40) NOT NULL,destination VARCHAR(254) NOT NULL,status VARCHAR(20) NOT NULL,created VARCHAR(30) NOT NULL)',
        'CREATE TABLE IF NOT EXISTS campaign_deliveries (campaign_id VARCHAR(32) NOT NULL,contact_id VARCHAR(32) NOT NULL,status VARCHAR(20) NOT NULL,created VARCHAR(30) NOT NULL,PRIMARY KEY (campaign_id,contact_id))'
    ] as $sql) db()->exec($sql);
    try { query('SELECT verified FROM client_accounts LIMIT 1'); } catch(PDOException $e) { db()->exec('ALTER TABLE client_accounts ADD COLUMN verified INTEGER NOT NULL DEFAULT 0'); }
    $checked = true;
}
function site_host(): string {
    $host = strtolower(trim(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]));
    return preg_match('/^[a-z0-9.-]{1,190}$/D',$host) ? rtrim($host,'.') : '';
}
/** Null = normal platform hostname; '' = unauthorized hostname; otherwise specific workspace. */
function site_scope(): ?string {
    $base = strtolower((string)parse_url(config()['app_url'],PHP_URL_HOST));
    $host = site_host();
    if ($host===$base || ($base==='127.0.0.1' && $host==='localhost')) return null;
    integration_schema();
    return (string)(query('SELECT workspace FROM site_domains WHERE domain=? AND verified=1',[$host])->fetchColumn() ?: '');
}
function send_client_confirmation(array $client): bool {
    if(empty(config()['mail_enabled']))return false;
    $token=bin2hex(random_bytes(32));integration_schema();
    query('DELETE FROM client_verifications WHERE client_id=?',[$client['id']]);
    query('INSERT INTO client_verifications (id,client_id,expires) VALUES (?,?,?)',[hash('sha256',$token),$client['id'],gmdate('Y-m-d H:i:s',time()+86400)]);
    $url=rtrim(config()['app_url'],'/').'/api.php?action=client_verify&token='.$token;
    $sent=mail_delivery($client['email'],'Verify your Estate client account',"Verify your email before viewing your personal enquiry history:\n".$url."\n\nThis link expires in 24 hours.");
    log_delivery($client['workspace'],'client_verify',$client['email'],$sent);
    return $sent;
}
function client_logged_in(): ?array {
    $id = $_SESSION['client_id']??'';
    if (!$id) return null;
    integration_schema();
    $client = query('SELECT id,workspace,name,email,verified FROM client_accounts WHERE id=?',[$id])->fetch();
    if (!$client || (site_scope()!==null && site_scope()!==$client['workspace'])) return null;
    return $client;
}
function require_client(): array { $c=client_logged_in(); if(!$c)fail('Client sign-in required',401); return $c; }
function published_property(string $id,string $workspace): bool {
    $r=query("SELECT data FROM records WHERE id=? AND workspace=? AND kind='property'",[$id,$workspace])->fetch();
    $w=query("SELECT * FROM workspaces WHERE id=? AND status='approved'",[$workspace])->fetch();
    return $r && $w && entitled($w) && !empty(json_decode($w['data'],true)['published']) && !empty(json_decode($r['data'],true)['published']);
}
function mail_delivery(string $to,string $subject,string $message): bool {
    if (empty(config()['mail_enabled'])) return false;
    $from=(string)(config()['mail_from']??'');
    if (!filter_var($to,FILTER_VALIDATE_EMAIL) || !filter_var($from,FILTER_VALIDATE_EMAIL)) return false;
    $subject=str_replace(["\r","\n"],' ',mb_substr($subject,0,180));
    return mail($to,$subject,$message,"From: Estate <".$from.">\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: Estate");
}
function log_delivery(string $workspace,string $kind,string $destination,bool $ok): void {
    integration_schema();
    query('INSERT INTO notification_log (id,workspace,kind,destination,status,created) VALUES (?,?,?,?,?,?)',[uuid(),$workspace,$kind,$destination,$ok?'sent':'failed',now()]);
}
function sms_delivery(string $phone,string $message): bool {
    $c=config();$sid=$c['twilio_sid']??'';$token=$c['twilio_token']??'';$from=$c['twilio_from']??'';
    if (!$sid || !$token || !$from || !preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone))return false;
    $ch=curl_init('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid).'/Messages.json');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['To'=>$phone,'From'=>$from,'Body'=>$message]),CURLOPT_USERPWD=>$sid.':'.$token,CURLOPT_TIMEOUT=>12,CURLOPT_SSL_VERIFYPEER=>true]);
    $out=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    return $out!==false && $status>=200 && $status<300;
}
function notify_new_enquiry(array $w,string $name,string $email,string $message): void {
    $data=json_decode($w['data'],true)?:[];
    $recipient=$data['email']??'';
    if (!filter_var($recipient,FILTER_VALIDATE_EMAIL)) $recipient=(string)(query("SELECT email FROM users WHERE workspace=? AND role='owner' LIMIT 1",[$w['id']])->fetchColumn()?:'');
    if ($recipient) { $ok=mail_delivery($recipient,'New Estate lead: '.$name,"New enquiry for ".$w['name']."\nName: ".$name."\nEmail: ".$email."\nMessage: ".$message."\n\nSign in to Estate to respond.");log_delivery($w['id'],'lead_email',$recipient,$ok); }
    $phone=(string)($data['sms_phone']??'');
    if ($phone) { $ok=sms_delivery($phone,'Estate lead: '.$name.' ('.$email.'). Sign in to your workspace.');log_delivery($w['id'],'lead_sms',$phone,$ok); }
}
function campaign_optout_token(string $workspace,string $contact,string $email): string {
    return hash_hmac('sha256',$workspace.':'.$contact.':'.$email,(string)config()['install_key']);
}
function domain_name(string $input): string {
    $domain=strtolower(trim($input));$domain=rtrim($domain,'.');
    if (!preg_match('/^(?=.{4,190}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$domain))fail('Use a valid domain such as homes.example.com');
    if ($domain===parse_url(config()['app_url'],PHP_URL_HOST))fail('Choose your own custom domain');
    return $domain;
}
function integration_get(string $action): void {
    if($action==='client_verify'){
        integration_schema();$token=(string)($_GET['token']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))fail('Invalid verification link',403);
        $row=query('SELECT client_id FROM client_verifications WHERE id=? AND expires>?',[hash('sha256',$token),now()])->fetch();
        if(!$row)fail('Verification link expired or already used',403);
        query('UPDATE client_accounts SET verified=1 WHERE id=?',[$row['client_id']]);
        query('DELETE FROM client_verifications WHERE client_id=?',[$row['client_id']]);
        header('Content-Type: text/html; charset=UTF-8');echo '<!doctype html><title>Client account verified</title><p>Your email is verified. Return to your agent website and sign in to view your enquiries.</p>';exit;
    }
    if ($action==='client_session') respond(['client'=>client_logged_in()]);
    if ($action==='client_portal') {
        $c=require_client();$rows=query('SELECT property_id FROM client_favourites WHERE client_id=?',[$c['id']])->fetchAll();
        $favs=array_values(array_filter(array_column($rows,'property_id'),fn($id)=>published_property($id,$c['workspace'])));
        $enquiries=[];
        if(!empty($c['verified']))foreach(query("SELECT id,data,created FROM records WHERE workspace=? AND kind='contact' ORDER BY created DESC",[$c['workspace']])->fetchAll() as $row){
            $d=json_decode($row['data'],true)?:[];if(strtolower((string)($d['email']??''))===$c['email'] && ($d['source']??'')==='Website')$enquiries[]=['id'=>$row['id'],'created'=>$row['created'],'status'=>$d['status']??'New enquiry','property'=>$d['property']??''];
        }
        respond(['client'=>$c,'favourites'=>$favs,'enquiries'=>$enquiries]);
    }
    if ($action==='unsubscribe') {
        $id=clean($_GET['id']??'',32);$r=query("SELECT * FROM records WHERE id=? AND kind='contact'",[$id])->fetch();
        $d=$r?json_decode($r['data'],true):[];
        $token=(string)($_GET['token']??'');
        if (!$r||!preg_match('/^[a-f0-9]{64}$/',$token)||!hash_equals(campaign_optout_token($r['workspace'],$id,(string)($d['email']??'')),$token))fail('Invalid or expired unsubscribe link',403);
        $d['marketing_consent']=false;saveRecord($r['workspace'],'contact',$d,$id);
        header('Content-Type: text/html; charset=UTF-8');echo '<!doctype html><title>Unsubscribed</title><p>You have been unsubscribed from marketing emails.</p>';exit;
    }
    if ($action==='notification_status') {
        $u=member();integration_schema();respond(query('SELECT kind,status,created FROM notification_log WHERE workspace=? ORDER BY created DESC LIMIT 30',[$u['workspace']])->fetchAll());
    }
    if ($action==='domain_status') {
        $u=member();integration_schema();respond(query('SELECT domain,token,verified FROM site_domains WHERE workspace=?',[$u['workspace']])->fetchAll());
    }
    if ($action==='address_search') {
        member();rate('address:'.($_SERVER['REMOTE_ADDR']??''),60,3600);
        $q=clean($_GET['q']??'',120);if(mb_strlen($q)<4)respond([]);
        $key=config()['geoapify_key']??'';if(!$key)fail('Address autocomplete needs a Geoapify API key',503);
        $url='https://api.geoapify.com/v1/geocode/autocomplete?'.http_build_query(['text'=>$q,'limit'=>5,'format'=>'json','apiKey'=>$key]);
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true]);
        $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($raw===false||$status!==200)fail('Address provider unavailable',502);
        $data=json_decode($raw,true)?:[];respond(array_values(array_filter(array_map(fn($v)=>clean($v['formatted']??'',220),$data['results']??[]))));
    }
}
function integration_post(string $action,array $b): void {
    if ($action==='client_register' || $action==='client_login') {
        integration_schema();rate('client_auth:'.($_SERVER['REMOTE_ADDR']??''),15,900);
        $workspace=clean($b['workspace']??'',32);$e=email($b['email']??'');$password=(string)($b['password']??'');
        $w=query("SELECT * FROM workspaces WHERE id=? AND status='approved'",[$workspace])->fetch();
        if(!$w||!entitled($w)||empty(json_decode($w['data'],true)['published'])||(site_scope()!==null && site_scope()!==$workspace))fail('Agent website unavailable',404);
        if($action==='client_register'){
            if(strlen($password)<12||strlen($password)>200)fail('Use a password between 12 and 200 characters');
            $name=clean($b['name']??'',150);if(strlen($name)<2)fail('Enter your name');
            if(query('SELECT id FROM client_accounts WHERE workspace=? AND email=?',[$workspace,$e])->fetch())fail('Account exists. Please sign in.');
            $id=uuid();query('INSERT INTO client_accounts (id,workspace,email,name,password,verified,created) VALUES (?,?,?,?,?,?,?)',[$id,$workspace,$e,$name,password_hash($password,PASSWORD_DEFAULT),0,now()]);send_client_confirmation(['id'=>$id,'workspace'=>$workspace,'email'=>$e]);
        } else {
            $c=query('SELECT * FROM client_accounts WHERE workspace=? AND email=?',[$workspace,$e])->fetch();
            if(!$c||!password_verify($password,$c['password']))fail('Incorrect email or password',401);
            $id=$c['id'];
        }
        session_regenerate_id(true);$_SESSION['client_id']=$id;respond(['ok'=>true]);
    }
    if($action==='client_resend_verify'){
        $c=require_client();if(!empty($c['verified']))respond(['ok'=>true]);
        rate('client_verify:'.$c['id'],3,3600);
        if(empty(config()['mail_enabled']))fail('Email verification has not been configured',503);
        if(!send_client_confirmation($c))fail('Verification email delivery failed',503);
        respond(['ok'=>true]);
    }
    if($action==='client_logout'){unset($_SESSION['client_id']);respond(['ok'=>true]);}
    if($action==='client_favourite'){
        $c=require_client();$id=clean($b['id']??'',32);
        if(!published_property($id,$c['workspace']))fail('Listing unavailable',404);
        $existing=query('SELECT property_id FROM client_favourites WHERE client_id=? AND property_id=?',[$c['id'],$id])->fetch();
        if($existing){query('DELETE FROM client_favourites WHERE client_id=? AND property_id=?',[$c['id'],$id]);respond(['saved'=>false]);}
        query('INSERT INTO client_favourites (client_id,property_id) VALUES (?,?)',[$c['id'],$id]);respond(['saved'=>true]);
    }
    if($action==='domain_set'){
        $u=member();if(!in_array($u['role'],['owner','admin']))fail('Owner access required',403);
        integration_schema();$domain=domain_name((string)($b['domain']??''));
        $existing=query('SELECT workspace FROM site_domains WHERE domain=?',[$domain])->fetchColumn();
        if($existing && $existing!==$u['workspace'])fail('Domain is already claimed',409);
        $token=bin2hex(random_bytes(20));
        query('DELETE FROM site_domains WHERE workspace=?',[$u['workspace']]);
        query('INSERT INTO site_domains (domain,workspace,token,verified,created) VALUES (?,?,?,?,?)',[$domain,$u['workspace'],$token,0,now()]);
        audit('domain.request',$domain);respond(['domain'=>$domain,'txt_name'=>'_estate-verify.'.$domain,'txt_value'=>'estate='.$token,'verified'=>false]);
    }
    if($action==='domain_verify'){
        $u=member();if(!in_array($u['role'],['owner','admin']))fail('Owner access required',403);
        integration_schema();$d=query('SELECT * FROM site_domains WHERE workspace=?',[$u['workspace']])->fetch();if(!$d)fail('Add a domain first');
        $found=false;foreach(dns_get_record('_estate-verify.'.$d['domain'],DNS_TXT)?:[] as $txt)if(trim($txt['txt']??'')==='estate='.$d['token'])$found=true;
        if(!$found)fail('Verification TXT record was not found yet',409);
        query('UPDATE site_domains SET verified=1 WHERE domain=? AND workspace=?',[$d['domain'],$u['workspace']]);audit('domain.verified',$d['domain']);respond(['ok'=>true,'domain'=>$d['domain']]);
    }
    if($action==='import_listing'){
        $u=member();$w=query('SELECT * FROM workspaces WHERE id=?',[$u['workspace']])->fetch();if(!entitled($w))fail('Subscription required',402);
        if(empty($b['authorized']))fail('Confirm that you have permission to reproduce this listing');
        rate('import:'.$u['id'],20,3600);
        $url=trim((string)($b['url']??''));$v=parse_url($url);$host=strtolower($v['host']??'');
        $allowed=['realtor.ca','remax.ca','remax.com','royallepage.ca','century21.ca','coldwellbanker.ca'];
        if(($v['scheme']??'')!=='https'||isset($v['user'])||isset($v['pass'])||isset($v['port'])||!in_array(true,array_map(fn($p)=>$host===$p||str_ends_with($host,'.'.$p),$allowed),true))fail('Use an HTTPS listing URL from an approved real estate website');
        $ip=gethostbyname($host);
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))fail('Listing website could not be safely reached');
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>['Accept: text/html'],CURLOPT_USERAGENT=>'EstateListingPreview/1.0',CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_MAXFILESIZE=>700000]);
        $html=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
        $d=['name'=>'Imported listing (review details)','location'=>'','price'=>'','description'=>'','image'=>'','source_url'=>$url,'published'=>false];
        if(class_exists('DOMDocument')&&is_string($html)&&strlen($html)<=700000&&$code===200&&str_contains(strtolower((string)$type),'text/html')){
            $doc=new DOMDocument();$prev=libxml_use_internal_errors(true);$doc->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($prev);
            foreach($doc->getElementsByTagName('meta') as $meta){
                $key=strtolower($meta->getAttribute('property')?:$meta->getAttribute('name'));$val=trim($meta->getAttribute('content'));
                if($key==='og:title'&&$val)$d['name']=clean($val,180);
                if(in_array($key,['og:description','description'],true)&&$val)$d['description']=clean($val,3000);
                if($key==='og:image'&&str_starts_with($val,'https://'))$d['image']=clean($val,1000);
                if(in_array($key,['product:price:amount','og:price:amount'],true)&&preg_match('/^[0-9,.]+$/D',$val)&&is_numeric(str_replace(',','',$val)))$d['price']=(float)str_replace(',','',$val);
            }
            listing_extract_fields($doc,$d);
        }
        respond(['data'=>$d,'note'=>'Preview only. Verify every field and your republishing rights before saving. Listing sites can block metadata extraction; use your licensed feed for reliable updates.']);
    }
    if($action==='send_contact_email'){
        $u=member();rate('contact_send:'.$u['id'],30,3600);
        $r=query("SELECT * FROM records WHERE id=? AND workspace=? AND kind='contact'",[clean($b['contact']??'',32),$u['workspace']])->fetch();if(!$r)fail('Contact not found',404);
        $d=json_decode($r['data'],true)?:[];$to=email($d['email']??'');$subject=clean($b['subject']??'',160);$message=clean($b['message']??'',8000);
        if(!$subject||!$message)fail('Subject and message are required');
        $ok=mail_delivery($to,$subject,$message);log_delivery($u['workspace'],'contact_email',$to,$ok);
        if(!$ok)fail('Email delivery failed or mail is not configured',503);
        saveRecord($u['workspace'],'activity',['name'=>$subject,'type'=>'Email','contact'=>$r['id'],'date'=>gmdate('Y-m-d\TH:i'),'notes'=>$message]);
        respond(['ok'=>true]);
    }
    if($action==='campaign_send'){
        $u=member();if(!in_array($u['role'],['owner','admin']))fail('Only a workspace owner can send campaigns',403);
        rate('campaign:'.$u['workspace'],4,3600);
        $r=query("SELECT * FROM records WHERE id=? AND workspace=? AND kind='campaign'",[clean($b['id']??'',32),$u['workspace']])->fetch();
        if(!$r)fail('Campaign not found',404);if(empty(config()['mail_enabled']))fail('Email delivery is not configured',503);
        $d=json_decode($r['data'],true)?:[];$subject=clean($d['subject']??'',180);$message=clean($d['notes']??'',8000);
        if(!$subject||!$message)fail('Campaign needs a subject and body');
        integration_schema();$sent=0;$failed=0;$skipped=0;
        foreach(query("SELECT * FROM records WHERE workspace=? AND kind='contact' ORDER BY created ASC",[$u['workspace']])->fetchAll() as $contact){
            $v=json_decode($contact['data'],true)?:[];$recipient=$v['email']??'';
            if(empty($v['marketing_consent'])||!filter_var($recipient,FILTER_VALIDATE_EMAIL))continue;
            $audience=$d['audience']??'All contacts';if($audience!=='All contacts' && ($v['type']??'')!==rtrim($audience,'s'))continue;
            $previous=query('SELECT status FROM campaign_deliveries WHERE campaign_id=? AND contact_id=?',[$r['id'],$contact['id']])->fetchColumn();
            if($previous==='sent'){$skipped++;continue;}
            if($sent+$failed>=50)break;
            $url=rtrim(config()['app_url'],'/').'/api.php?action=unsubscribe&id='.$contact['id'].'&token='.campaign_optout_token($u['workspace'],$contact['id'],$recipient);
            $ok=mail_delivery($recipient,$subject,$message."\n\nTo unsubscribe: ".$url);
            if($previous)query('UPDATE campaign_deliveries SET status=?,created=? WHERE campaign_id=? AND contact_id=?',[$ok?'sent':'failed',now(),$r['id'],$contact['id']]);
            else query('INSERT INTO campaign_deliveries (campaign_id,contact_id,status,created) VALUES (?,?,?,?)',[$r['id'],$contact['id'],$ok?'sent':'failed',now()]);
            $ok?$sent++:$failed++;log_delivery($u['workspace'],'campaign',$recipient,$ok);
        }
        audit('campaign.send',$r['id']);respond(['sent'=>$sent,'failed'=>$failed,'previously_sent'=>$skipped,'limit'=>50]);
    }
}
