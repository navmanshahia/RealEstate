<?php
declare(strict_types=1);

function websiteSchema():void {
    query('CREATE TABLE IF NOT EXISTS website_domains (hostname VARCHAR(253) PRIMARY KEY,workspace VARCHAR(32) NOT NULL UNIQUE,token VARCHAR(64) NOT NULL,status VARCHAR(20) NOT NULL,created VARCHAR(30) NOT NULL)');
}
function hostWorkspace():?array {
    static $done=false,$workspace=null;
    if($done)return $workspace;
    $host=strtolower(explode(':',$_SERVER['HTTP_HOST']??'')[0]);
    $primary=strtolower(parse_url(config()['app_url'],PHP_URL_HOST)?:'');
    if($host===$primary){$done=true;return null;}
    $row=query("SELECT workspace FROM website_domains WHERE hostname=? AND status='verified'",[$host])->fetch();
    if(!$row)fail('This domain is not connected. Open the platform address to configure it.',404);
    $workspace=query('SELECT * FROM workspaces WHERE id=?',[$row['workspace']])->fetch()?:null;
    if(!$workspace)fail('Website unavailable',404);
    $done=true;return $workspace;
}
function requestWorkspace():?array {
    if($w=hostWorkspace())return $w;
    $slug=clean($_GET['site']??$_GET['agent']??'',180);
    if($slug!==''){
        $w=query('SELECT * FROM workspaces WHERE slug=?',[$slug])->fetch();
        if(!$w)fail('Website not found',404);
        return $w;
    }
    $u=user(false);
    return $u?(query('SELECT * FROM workspaces WHERE id=?',[$u['workspace']])->fetch()?:null):null;
}
function publicWebsite():array {
    $empty=['agents'=>[],'properties'=>[],'site'=>null,'unavailable'=>false];
    $w=requestWorkspace();if(!$w)return $empty;
    $d=json_decode($w['data'],true)?:[];
    if($w['status']!=='approved'||!entitled($w)||empty($d['published']))return array_replace($empty,['unavailable'=>true]);
    $site=workspacePublic($w);if(($d['site_type']??'individual')!=='agency')$site['data']['team']=[];$properties=[];
    foreach(query("SELECT * FROM records WHERE workspace=? AND kind='property' ORDER BY created DESC",[$w['id']])->fetchAll() as $r){if(!empty(json_decode($r['data'],true)['published']))$properties[]=recordPublic($r);}
    return ['agents'=>[$site],'site'=>$site,'properties'=>$properties,'unavailable'=>false];
}
function validatePublicTeam(mixed $rows):array {
    if(!is_array($rows)||count($rows)>30)fail('Use up to 30 public team profiles');
    $out=[];
    foreach($rows as $r){
        if(!is_array($r))fail('Invalid team profile');
        $name=clean($r['name']??'',150);if(!$name)continue;
        $email=empty($r['email'])?'':email($r['email']);
        $phone=clean($r['phone']??'',40);if($phone&&!preg_match('/^[+0-9 ().-]+$/',$phone))fail('Use a valid team phone number');
        $method=in_array($r['contact_method']??'', ['email','phone','both'])?$r['contact_method']:'both';
        if(($method==='email'&&!$email)||($method==='phone'&&!$phone)||(!$email&&!$phone))fail('Add the contact details for each team card');
        $out[]=['name'=>$name,'role'=>clean($r['role']??'',100),'bio'=>clean($r['bio']??'',800),'email'=>$email,'phone'=>$phone,'contact_method'=>$method];
    }
    return $out;
}
function connectionSettings(array $u):array {
    $domain=query('SELECT hostname,token,status FROM website_domains WHERE workspace=?',[$u['workspace']])->fetch()?:null;
    $result=['domain'=>$domain,'platform_url'=>config()['app_url'],'billing'=>!empty(config()['stripe_secret']),'email'=>!empty(config()['mail_enabled'])];
    if($u['role']==='admin'){
        $c=config();$result['platform']=['stripe_configured'=>!empty($c['stripe_secret']),'webhook_configured'=>!empty($c['stripe_webhook_secret']),'stripe_prices'=>$c['stripe_prices']??[],'billing_required'=>!empty($c['billing_required']),'mail_enabled'=>!empty($c['mail_enabled']),'mail_from'=>$c['mail_from']??'','stripe_tested_at'=>$c['stripe_tested_at']??'','mail_tested_at'=>$c['mail_tested_at']??''];
    }
    return $result;
}
function saveIntegrationSettings(array $patch):void {
    $path=__DIR__.'/integrations.private.php';$lock=fopen(__DIR__.'/.integration-lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Unable to lock integration settings');
    $tmp=null;
    try{
        $current=is_file($path)?require $path:[];
        $contents="<?php\nreturn ".var_export(array_replace($current,$patch),true).";\n";
        $old=umask(0077);$tmp=tempnam(__DIR__,'.settings-');umask($old);
        if(!$tmp||file_put_contents($tmp,$contents)===false||!chmod($tmp,0600)||!rename($tmp,$path))throw new RuntimeException('Unable to save private settings');
        if(function_exists('opcache_invalidate'))opcache_invalidate($path,true);
    }finally{if($tmp&&is_file($tmp))unlink($tmp);flock($lock,LOCK_UN);fclose($lock);}
}
function connectionAction(string $action,array $u,array $b):never {
    if(!in_array($u['role'],['owner','admin']))fail('Owner access required',403);
    if($action==='domain_remove'){query('DELETE FROM website_domains WHERE workspace=?',[$u['workspace']]);audit('domain.remove');respond(['ok'=>true]);}
    if($action==='domain_save'){
        $host=strtolower(trim((string)($b['hostname']??'')));
        if(strlen($host)>253||!str_contains($host,'.')||!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||filter_var($host,FILTER_VALIDATE_IP)||$host===strtolower(parse_url(config()['app_url'],PHP_URL_HOST)?:''))fail('Enter a domain such as homes.example.com, without https:// or a path');
        $existing=query('SELECT * FROM website_domains WHERE hostname=?',[$host])->fetch();
        if($existing&&$existing['workspace']!==$u['workspace'])fail('Domain is already assigned',409);
        if(!$existing){db()->beginTransaction();query('DELETE FROM website_domains WHERE workspace=?',[$u['workspace']]);query('INSERT INTO website_domains (hostname,workspace,token,status,created) VALUES (?,?,?,?,?)',[$host,$u['workspace'],bin2hex(random_bytes(24)),'pending',now()]);db()->commit();}
        audit('domain.save');respond(['ok'=>true]);
    }
    if($action==='domain_verify'){
        rate('domain-verify:'.$u['workspace'],10);
        $d=query('SELECT * FROM website_domains WHERE workspace=?',[$u['workspace']])->fetch();if(!$d)fail('Save a domain first');
        $records=dns_get_record('_estate-verification.'.$d['hostname'],DNS_TXT);$valid=false;
        foreach($records?:[] as $r)if(hash_equals('estate='.$d['token'],$r['txt']??''))$valid=true;
        if(!$valid){query("UPDATE website_domains SET status='pending' WHERE workspace=?",[$u['workspace']]);fail('TXT record not found yet. Check the value and allow DNS time to update.');}
        query("UPDATE website_domains SET status='verified' WHERE workspace=?",[$u['workspace']]);audit('domain.verify');respond(['ok'=>true,'message'=>'Ownership verified. Hosting routing and HTTPS must also be configured.']);
    }
    admin();
    if($action==='integration_save'){
        $c=config();$patch=[];
        if(($b['section']??'')==='stripe'){
            foreach(['stripe_secret'=>'/^sk_(test|live)_[A-Za-z0-9]+$/','stripe_webhook_secret'=>'/^whsec_[A-Za-z0-9]+$/'] as $key=>$pattern){$v=trim((string)($b[$key]??''));if($v!==''){if(!preg_match($pattern,$v))fail('Check the Stripe key format');$patch[$key]=$v;}}
            $prices=[];foreach(['Starter','Professional','Team'] as $plan){$v=trim((string)($b['stripe_prices'][$plan]??''));if($v!==''&&!preg_match('/^price_[A-Za-z0-9]+$/',$v))fail('Check the price ID for '.$plan);$prices[$plan]=$v;}
            $patch['stripe_prices']=$prices;$patch['billing_required']=!empty($b['billing_required']);
            if($patch['billing_required']&&(empty($patch['stripe_secret']??$c['stripe_secret'])||empty($patch['stripe_webhook_secret']??$c['stripe_webhook_secret'])||count(array_filter($prices))!==3))fail('Configure Stripe, webhook, and all three prices before requiring payment');
            $patch['stripe_tested_at']='';
        }elseif(($b['section']??'')==='email'){
            $patch['mail_enabled']=!empty($b['mail_enabled']);$patch['mail_from']=email($b['mail_from']??'');$patch['mail_tested_at']='';
        }else fail('Unknown integration');
        saveIntegrationSettings($patch);audit('integration.save');respond(['ok'=>true]);
    }
    if($action==='email_test'){
        rate('email-test:'.$u['id'],5);if(empty(config()['mail_enabled']))fail('Enable and save email first');
        if(!mail($u['email'],'Estate email delivery test','Your server accepted this test. Confirm receipt before relying on password-reset email.','From: '.config()['mail_from']))fail('The hosting mail server rejected the message. Contact your host.',502);
        saveIntegrationSettings(['mail_tested_at'=>now()]);respond(['message'=>'The hosting mail server accepted the test. Check your administrator inbox and spam folder to confirm delivery.']);
    }
    if($action==='stripe_test'){
        rate('stripe-test:'.$u['id'],5);require_once __DIR__.'/billing.php';
        $mode=null;
        foreach(['Starter','Professional','Team'] as $plan){$id=config()['stripe_prices'][$plan]??'';if(!$id)fail('Add all three recurring price IDs first');$price=stripe('prices/'.rawurlencode($id),[],'GET');if(empty($price['active'])||($price['recurring']['interval']??'')!=='month'||($price['recurring']['interval_count']??0)!==1||($price['currency']??'')!=='cad')fail($plan.' requires an active monthly CAD price');$expected=['Starter'=>4900,'Professional'=>9900,'Team'=>24900][$plan];if(($price['unit_amount']??0)!==$expected)fail($plan.' price must match the displayed CAD '.($expected/100).'/month');if($mode!==null&&$mode!==$price['livemode'])fail('Use prices from the same Stripe mode');$mode=$price['livemode'];}
        saveIntegrationSettings(['stripe_tested_at'=>now()]);respond(['message'=>'Stripe credentials and monthly prices verified. Complete a test checkout to verify webhook delivery and billing portal configuration.']);
    }
    fail('Unknown connection action',404);
}
