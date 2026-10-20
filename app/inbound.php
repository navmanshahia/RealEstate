<?php
declare(strict_types=1);

/**
 * Signed Mailgun inbound-route receiver. Configure a route on your Mailgun
 * domain to POST to api.php?action=mailgun_inbound; it is not an IMAP sync.
 */
function inbound_mailgun(): never {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')fail('POST required',405);
    $key=(string)(config()['mailgun_webhook_signing_key']??'');
    if($key==='')fail('Inbound email is not configured',503);
    $signature=$_POST['signature']??'';
    $timestamp=(string)($_POST['timestamp']??'');$token=(string)($_POST['token']??'');
    if(!is_string($signature)||!ctype_digit($timestamp)||abs(time()-(int)$timestamp)>300||!preg_match('/^[a-f0-9]{64}$/D',$signature)||!hash_equals(hash_hmac('sha256',$timestamp.$token,$key),$signature))fail('Invalid inbound signature',403);
    if(strlen((string)($_POST['body-plain']??''))>50000)fail('Email too large',413);
    $unique='mailgun:'.hash('sha256',$timestamp.':'.$token);
    if(query('SELECT id FROM events WHERE id=?',[$unique])->fetch())respond(['ok'=>true,'duplicate'=>true]);
    $recipient=strtolower(trim((string)($_POST['recipient']??'')));
    $sender=trim((string)($_POST['sender']??''));
    if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)||!filter_var($sender,FILTER_VALIDATE_EMAIL))fail('Invalid email identity');
    $selected=null;
    foreach(query("SELECT * FROM workspaces WHERE status='approved'")->fetchAll() as $w){
        $d=json_decode($w['data'],true)?:[];
        if(strtolower($d['inbound_email']??'')===$recipient){
            if($selected!==null)fail('Ambiguous inbound mailbox',409);
            $selected=$w;
        }
    }
    if(!$selected||!entitled($selected))fail('Inbox not found',404);
    $subject=clean($_POST['subject']??'(no subject)',180);if($subject==='')$subject='(no subject)';
    $body=clean($_POST['body-plain']??'',10000);
    $contact='';
    foreach(query("SELECT id,data FROM records WHERE workspace=? AND kind='contact'",[$selected['id']])->fetchAll() as $r){
        $d=json_decode($r['data'],true)?:[];if(strtolower($d['email']??'')===$sender){$contact=$r['id'];break;}
    }
    if(!$contact)$contact=saveRecord($selected['id'],'contact',['name'=>$sender,'email'=>$sender,'source'=>'Inbound email','status'=>'New enquiry','type'=>'Buyer']);
    $id=saveRecord($selected['id'],'activity',['name'=>$subject,'contact'=>$contact,'type'=>'Email','date'=>gmdate('Y-m-d\TH:i'),'notes'=>"Inbound email from ".$sender."\n\n".$body]);
    query('INSERT INTO events (id,created) VALUES (?,?)',[$unique,now()]);
    log_delivery($selected['id'],'inbound_email',$sender,true);
    respond(['ok'=>true,'id'=>$id]);
}
