<?php
declare(strict_types=1);

/** First-party customer SMS conversations via the account's Twilio phone number. */
function sms_schema(): void {
    static $ready=false;
    if($ready)return;
    db()->exec('CREATE TABLE IF NOT EXISTS sms_messages (id VARCHAR(32) PRIMARY KEY,workspace VARCHAR(32) NOT NULL,contact_id VARCHAR(32) NOT NULL,direction VARCHAR(12) NOT NULL,sender VARCHAR(20) NOT NULL,recipient VARCHAR(20) NOT NULL,body TEXT NOT NULL,provider_sid VARCHAR(64),status VARCHAR(30) NOT NULL,created VARCHAR(30) NOT NULL)');
    db()->exec('CREATE TABLE IF NOT EXISTS sms_suppressions (sender VARCHAR(20) NOT NULL,recipient VARCHAR(20) NOT NULL,created VARCHAR(30) NOT NULL,PRIMARY KEY(sender,recipient))');
    try {db()->exec('CREATE UNIQUE INDEX sms_provider_sid ON sms_messages(provider_sid)');}catch(PDOException $e){}
    try {db()->exec('CREATE INDEX sms_by_contact ON sms_messages(workspace,contact_id,created)');}catch(PDOException $e){}
    $ready=true;
}
function sms_number(string $input): string {
    $input=trim($input);
    // Canadian / North American local formats can be normalized without guessing the country.
    $digits=preg_replace('/[^0-9]/','',$input);
    if(strlen($digits)===10)$input='+1'.$digits;
    elseif(strlen($digits)===11&&$digits[0]==='1')$input='+'.$digits;
    elseif(preg_match('/^\+[1-9][0-9]{7,14}$/D',$input))return $input;
    else return '';
    return preg_match('/^\+[1-9][0-9]{7,14}$/D',$input)?$input:'';
}
function sms_sender(): string {
    return sms_number((string)(config()['twilio_from']??''));
}
function sms_ready(): bool {
    return (bool)(config()['twilio_sid']??'') && (bool)(config()['twilio_token']??'') && sms_sender()!=='';
}
function sms_contact(string $workspace,string $contactId): array {
    $r=query("SELECT * FROM records WHERE id=? AND workspace=? AND kind='contact'",[$contactId,$workspace])->fetch();
    if(!$r)fail('Contact not found in your workspace',404);
    $r['fields']=json_decode($r['data'],true)?:[];
    return $r;
}
function sms_blocked(string $sender,string $recipient): bool {
    sms_schema();
    return (bool)query('SELECT 1 FROM sms_suppressions WHERE sender=? AND recipient=?',[$sender,$recipient])->fetchColumn();
}
function sms_twilio_signature(string $url,array $params): bool {
    $provided=trim((string)($_SERVER['HTTP_X_TWILIO_SIGNATURE']??''));
    $secret=(string)(config()['twilio_token']??'');
    if(!$secret||!$provided)return false;
    ksort($params,SORT_STRING);
    $payload=$url;
    foreach($params as $key=>$value){
        if(!is_string($key)||!is_scalar($value))return false;
        $payload.=$key.(string)$value;
    }
    return hash_equals(base64_encode(hash_hmac('sha1',$payload,$secret,true)),$provided);
}
function sms_verify_webhook(string $action): void {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')fail('POST required',405);
    if(!sms_ready())fail('Twilio is not configured',503);
    if(stripos($_SERVER['CONTENT_TYPE']??'','application/x-www-form-urlencoded')!==0)fail('Invalid webhook format',415);
    $url=rtrim((string)config()['app_url'],'/').'/api.php?action='.$action;
    if(!sms_twilio_signature($url,$_POST))fail('Invalid Twilio signature',403);
    $sid=(string)($_POST['AccountSid']??'');
    if($sid!==config()['twilio_sid'])fail('Unexpected Twilio account',403);
    if(sms_number((string)($_POST['To']??''))!==sms_sender() && $action==='twilio_inbound')fail('Unexpected SMS destination',403);
}
function sms_xml_reply(): never {
    header('Content-Type: text/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
    exit;
}
function sms_suppress(string $recipient): void {
    sms_schema();
    $from=sms_sender();
    query('DELETE FROM sms_suppressions WHERE sender=? AND recipient=?',[$from,$recipient]);
    query('INSERT INTO sms_suppressions (sender,recipient,created) VALUES (?,?,?)',[$from,$recipient,now()]);
    // A STOP for a shared sender applies across all agent workspaces.
    foreach(query("SELECT id,workspace,data FROM records WHERE kind='contact'")->fetchAll() as $row){
        $data=json_decode($row['data'],true)?:[];
        if(sms_number((string)($data['phone']??''))!==$recipient)continue;
        $data['sms_consent']=false;$data['sms_opt_out']=true;
        saveRecord($row['workspace'],'contact',$data,$row['id']);
    }
}
function sms_webhook(string $action): never {
    sms_verify_webhook($action);sms_schema();
    $sid=trim((string)($_POST['MessageSid']??''));
    if(!preg_match('/^SM[a-fA-F0-9]{32}$/D',$sid) && !preg_match('/^MM[a-fA-F0-9]{32}$/D',$sid))fail('Invalid message identifier');
    if($action==='twilio_status'){
        $status=strtolower(trim((string)($_POST['MessageStatus']??$_POST['SmsStatus']??'')));
        if(in_array($status,['queued','accepted','sending','sent','delivered','undelivered','failed'],true))
            query("UPDATE sms_messages SET status=? WHERE provider_sid=? AND direction='outbound'",[$status,$sid]);
        sms_xml_reply();
    }
    $sender=sms_number((string)($_POST['From']??''));
    $body=clean($_POST['Body']??'',1800);
    if(!$sender||$body==='')sms_xml_reply();
    $command=strtoupper(trim($body));
    if(in_array($command,['STOP','STOPALL','UNSUBSCRIBE','CANCEL','END','QUIT','REVOKE','OPTOUT'],true)){
        sms_suppress($sender);sms_xml_reply();
    }
    if(query('SELECT id FROM sms_messages WHERE provider_sid=?',[$sid])->fetch())sms_xml_reply();
    // Ambiguous numbers must not be routed to the wrong agent on a shared Twilio sender.
    $linked=query("SELECT DISTINCT workspace,contact_id FROM sms_messages WHERE direction='outbound' AND sender=? AND recipient=?", [sms_sender(),$sender])->fetchAll();
    if(count($linked)!==1)sms_xml_reply();
    $link=$linked[0];
    $contact=sms_contact($link['workspace'],$link['contact_id']);
    if(sms_number((string)($contact['fields']['phone']??''))!==$sender)sms_xml_reply();
    query('INSERT INTO sms_messages (id,workspace,contact_id,direction,sender,recipient,body,provider_sid,status,created) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [uuid(),$link['workspace'],$link['contact_id'],'inbound',$sender,sms_sender(),$body,$sid,'received',now()]);
    saveRecord($link['workspace'],'activity',['name'=>'Incoming SMS','type'=>'Note','contact'=>$link['contact_id'],'notes'=>'SMS reply from '.$sender.":\n".$body,'date'=>gmdate('Y-m-d\TH:i')]);
    sms_xml_reply();
}
function sms_get(string $action): void {
    if($action==='sms_status'){
        $u=member();respond(['configured'=>sms_ready(),'sender'=>sms_ready()?sms_sender():null]);
    }
    if($action==='sms_history'){
        $u=member();$id=clean($_GET['contact']??'',32);sms_contact($u['workspace'],$id);
        sms_schema();respond(query('SELECT id,direction,body,status,created FROM sms_messages WHERE workspace=? AND contact_id=? ORDER BY created ASC LIMIT 100',[$u['workspace'],$id])->fetchAll());
    }
}
function sms_post(string $action,array $b): void {
    if($action!=='sms_send')return;
    $u=member();$id=clean($b['contact']??'',32);$contact=sms_contact($u['workspace'],$id);
    $fields=$contact['fields'];
    if(empty($fields['sms_consent'])||!empty($fields['sms_opt_out'])||empty($fields['sms_consent_source']))fail('Recorded SMS permission and its source are required. An opted-out customer cannot be texted.',403);
    $to=sms_number((string)($fields['phone']??''));
    if(!$to)fail('Enter a valid Canadian or E.164 phone number for this client');
    if(!sms_ready())fail('Twilio messaging is not yet configured',503);
    $from=sms_sender();
    if(sms_blocked($from,$to))fail('This customer opted out by SMS STOP',403);
    $w=query('SELECT * FROM workspaces WHERE id=?',[$u['workspace']])->fetch();
    if(!$w||!entitled($w))fail('An active workspace is required',402);
    $message=clean($b['message']??'',600);
    if(!$message)fail('Write a message before sending');
    $mail=(json_decode($w['data'],true)?:[])['email']??'';
    $business=clean($w['name'],70);
    $message=$business.': '.$message."\nReply STOP to opt out.".($mail&&filter_var($mail,FILTER_VALIDATE_EMAIL)?" Contact: ".$mail:'');
    rate('sms-send-user:'.$u['id'],30,3600);
    rate('sms-send-workspace:'.$u['workspace'],150,86400);
    $url='https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode(config()['twilio_sid']).'/Messages.json';
    $post=['To'=>$to,'From'=>$from,'Body'=>$message,'StatusCallback'=>rtrim(config()['app_url'],'/').'/api.php?action=twilio_status'];
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post),CURLOPT_USERPWD=>config()['twilio_sid'].':'.config()['twilio_token'],CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true]);
    $response=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    $out=is_string($response)?json_decode($response,true):null;
    if($response===false||$status<200||$status>=300||!is_array($out)||empty($out['sid'])){
        error_log('Estate SMS send failed HTTP '.$status);
        fail('Twilio could not accept this SMS. Confirm your sender, balance and recipient permissions.',502);
    }
    sms_schema();
    $sid=(string)$out['sid'];
    query('INSERT INTO sms_messages (id,workspace,contact_id,direction,sender,recipient,body,provider_sid,status,created) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [uuid(),$u['workspace'],$id,'outbound',$from,$to,$message,$sid,clean($out['status']??'queued',30),now()]);
    saveRecord($u['workspace'],'activity',['name'=>'Outgoing SMS','type'=>'Note','contact'=>$id,'notes'=>'SMS to '.$to.":\n".$message,'date'=>gmdate('Y-m-d\TH:i')]);
    audit('sms.sent',$id);
    respond(['ok'=>true,'status'=>$out['status']??'queued']);
}
