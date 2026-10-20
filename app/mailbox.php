<?php
declare(strict_types=1);

/**
 * Optional two-way Gmail / Microsoft 365 mailbox connection.
 * OAuth tokens are encrypted using the installation key (calendar.php).
 * Only mail from existing CRM contacts is imported into agent activity records.
 */
function mailbox_schema(): void {
    static $ok=false;
    if($ok)return;
    db()->exec('CREATE TABLE IF NOT EXISTS mailbox_connections (workspace VARCHAR(32) NOT NULL,provider VARCHAR(16) NOT NULL,token TEXT NOT NULL,expires INTEGER NOT NULL,created VARCHAR(30) NOT NULL,PRIMARY KEY (workspace,provider))');
    $ok=true;
}
function mailbox_provider(string $provider): array {
    if(!in_array($provider,['google','microsoft'],true))fail('Unsupported mailbox provider',400);
    $id=(string)(config()[$provider.'_oauth_client_id']??'');
    $secret=(string)(config()[$provider.'_oauth_client_secret']??'');
    if(!$id||!$secret)fail('Configure the OAuth client credentials first',503);
    $google=$provider==='google';
    return ['id'=>$id,'secret'=>$secret,
        'authorize'=>$google?'https://accounts.google.com/o/oauth2/v2/auth':'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
        'token'=>$google?'https://oauth2.googleapis.com/token':'https://login.microsoftonline.com/common/oauth2/v2.0/token',
        'scope'=>$google?'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send':'offline_access Mail.Read Mail.Send User.Read'
    ];
}
function mailbox_redirect(): string {
    return rtrim(config()['app_url'],'/').'/api.php?action=mailbox_callback';
}
function mailbox_http(string $url,string $method='GET',array $headers=[],?string $payload=null): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>16,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method]);
    if($payload!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$payload);
    $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if($raw===false||$status<200||$status>=300)throw new RuntimeException('Mailbox provider request failed (HTTP '.$status.')');
    $result=json_decode((string)$raw,true);
    return is_array($result)?$result:[];
}
function mailbox_token_post(string $provider,array $fields): array {
    $p=mailbox_provider($provider);
    $body=http_build_query($fields);
    $response=mailbox_http($p['token'],'POST',['Content-Type: application/x-www-form-urlencoded'],$body);
    if(empty($response['access_token']))throw new RuntimeException('Mailbox permission was not granted');
    return $response;
}
function mailbox_store(string $workspace,string $provider,array $tokens): void {
    mailbox_schema();
    $previous=query('SELECT token FROM mailbox_connections WHERE workspace=? AND provider=?',[$workspace,$provider])->fetchColumn();
    $old=$previous?json_decode(calendar_plain((string)$previous),true):[];
    $value=['access_token'=>(string)$tokens['access_token'],'refresh_token'=>$tokens['refresh_token']??($old['refresh_token']??'')];
    $protected=calendar_secret(json_encode($value,JSON_THROW_ON_ERROR));
    $expires=time()+max(30,(int)($tokens['expires_in']??3600));
    if($previous)query('UPDATE mailbox_connections SET token=?,expires=? WHERE workspace=? AND provider=?',[$protected,$expires,$workspace,$provider]);
    else query('INSERT INTO mailbox_connections (workspace,provider,token,expires,created) VALUES (?,?,?,?,?)',[$workspace,$provider,$protected,$expires,now()]);
}
function mailbox_token(string $workspace,string $provider): string {
    mailbox_schema();
    $row=query('SELECT token,expires FROM mailbox_connections WHERE workspace=? AND provider=?',[$workspace,$provider])->fetch();
    if(!$row)fail('Mailbox is not connected',409);
    $stored=json_decode(calendar_plain($row['token']),true)?:[];
    if((int)$row['expires']>time()+90)return (string)($stored['access_token']??'');
    if(empty($stored['refresh_token']))fail('Reconnect mailbox to continue',409);
    $p=mailbox_provider($provider);
    $new=mailbox_token_post($provider,['grant_type'=>'refresh_token','refresh_token'=>$stored['refresh_token'],'client_id'=>$p['id'],'client_secret'=>$p['secret']]);
    mailbox_store($workspace,$provider,$new);
    return (string)$new['access_token'];
}
function mailbox_api(string $workspace,string $provider,string $url,string $method='GET',?array $payload=null): array {
    $token=mailbox_token($workspace,$provider);
    $headers=['Authorization: Bearer '.$token,'Accept: application/json'];
    $body=null;
    if($payload!==null){$headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_THROW_ON_ERROR);}
    return mailbox_http($url,$method,$headers,$body);
}
function mailbox_email_address(string $input): string {
    if(preg_match('/<([^<>]+@[^<>]+)>/',$input,$m))$input=$m[1];
    $input=strtolower(trim($input));
    return filter_var($input,FILTER_VALIDATE_EMAIL)?$input:'';
}
function mailbox_gmail_body(array $payload,int $depth=0): string {
    if($depth>6)return '';
    if(strtolower((string)($payload['mimeType']??''))==='text/plain' && !empty($payload['body']['data'])){
        $raw=base64_decode(strtr((string)$payload['body']['data'],'-_','+/'),true);
        if(is_string($raw))return clean($raw,8000);
    }
    foreach($payload['parts']??[] as $part){
        if(!is_array($part))continue;
        $found=mailbox_gmail_body($part,$depth+1);
        if($found!=='')return $found;
    }
    return '';
}
function mailbox_read(string $workspace,string $provider): array {
    if($provider==='google'){
        $list=mailbox_api($workspace,$provider,'https://gmail.googleapis.com/gmail/v1/users/me/messages?'.http_build_query(['maxResults'=>12,'labelIds'=>'INBOX','q'=>'newer_than:30d']));
        $messages=[];
        foreach(array_slice($list['messages']??[],0,12) as $item){
            $id=$item['id']??'';if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{1,150}$/D',$id))continue;
            $m=mailbox_api($workspace,$provider,'https://gmail.googleapis.com/gmail/v1/users/me/messages/'.rawurlencode($id).'?format=full');
            $headers=[];foreach($m['payload']['headers']??[] as $h)if(isset($h['name']))$headers[strtolower((string)$h['name'])]=(string)($h['value']??'');
            $messages[]=['id'=>$id,'email'=>mailbox_email_address($headers['from']??''),'subject'=>clean($headers['subject']??'(No subject)',180),'text'=>mailbox_gmail_body($m['payload']??[])?:clean($m['snippet']??'',8000),'date'=>isset($m['internalDate'])?gmdate('Y-m-d\TH:i',(int)floor((float)$m['internalDate']/1000)):gmdate('Y-m-d\TH:i')];
        }
        return $messages;
    }
    $url='https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages?'.http_build_query(['$top'=>12,'$orderby'=>'receivedDateTime desc','$select'=>'id,subject,from,bodyPreview,receivedDateTime']);
    $response=mailbox_api($workspace,$provider,$url);
    $out=[];
    foreach($response['value']??[] as $m){
        if(!is_array($m)||empty($m['id']))continue;
        $out[]=['id'=>(string)$m['id'],'email'=>mailbox_email_address((string)($m['from']['emailAddress']['address']??'')),'subject'=>clean($m['subject']??'(No subject)',180),'text'=>clean($m['bodyPreview']??'',8000),'date'=>!empty($m['receivedDateTime'])?gmdate('Y-m-d\TH:i',strtotime($m['receivedDateTime'])):gmdate('Y-m-d\TH:i')];
    }
    return $out;
}
function mailbox_import_messages(string $workspace,string $provider,array $messages): array {
    $contacts=[];
    foreach(query("SELECT id,data FROM records WHERE workspace=? AND kind='contact'",[$workspace])->fetchAll() as $r){
        $d=json_decode($r['data'],true)?:[];
        $email=mailbox_email_address((string)($d['email']??''));
        if($email)$contacts[$email]=$r['id'];
    }
    $imported=0;$skipped=0;
    foreach($messages as $m){
        $email=(string)($m['email']??'');$id=(string)($m['id']??'');
        if(!$id||!isset($contacts[$email])){$skipped++;continue;}
        $eventId='mailbox:'.$provider.':'.hash('sha256',$workspace.':'.$id);
        if(query('SELECT id FROM events WHERE id=?',[$eventId])->fetch()){$skipped++;continue;}
        $subject=clean($m['subject']??'(No subject)',180);
        $notes='Received from '.$email."\n\n".clean($m['text']??'',8000);
        db()->beginTransaction();
        try {
            query('INSERT INTO events (id,created) VALUES (?,?)',[$eventId,now()]);
            saveRecord($workspace,'activity',['name'=>$subject?:'(No subject)','contact'=>$contacts[$email],'type'=>'Email','date'=>$m['date']??gmdate('Y-m-d\TH:i'),'notes'=>$notes]);
            db()->commit();$imported++;
        } catch(Throwable $e) {
            if(db()->inTransaction())db()->rollBack();
            if($e instanceof PDOException){$skipped++;continue;}
            throw $e;
        }
    }
    return ['imported'=>$imported,'skipped'=>$skipped,'checked'=>count($messages)];
}
function mailbox_send_message(string $workspace,string $provider,string $recipient,string $subject,string $message): void {
    if($provider==='google'){
        $mime="To: ".$recipient."\r\nSubject: ".mb_encode_mimeheader($subject,'UTF-8','B',"\r\n")."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n".$message;
        mailbox_api($workspace,$provider,'https://gmail.googleapis.com/gmail/v1/users/me/messages/send','POST',['raw'=>rtrim(strtr(base64_encode($mime),'+/','-_'),'=')]);
    }else{
        mailbox_api($workspace,$provider,'https://graph.microsoft.com/v1.0/me/sendMail','POST',['message'=>['subject'=>$subject,'body'=>['contentType'=>'Text','content'=>$message],'toRecipients'=>[['emailAddress'=>['address'=>$recipient]]]],'saveToSentItems'=>true]);
    }
}
function mailbox_get(string $action): void {
    if($action==='mailbox_start'){
        $u=member();if(!in_array($u['role'],['owner','admin']))fail('Workspace owner required',403);
        $provider=clean($_GET['provider']??'',20);$p=mailbox_provider($provider);
        $state=bin2hex(random_bytes(24));
        $_SESSION['mailbox_oauth']=['provider'=>$provider,'state'=>$state,'workspace'=>$u['workspace'],'created'=>time()];
        $params=['client_id'=>$p['id'],'redirect_uri'=>mailbox_redirect(),'response_type'=>'code','scope'=>$p['scope'],'state'=>$state];
        if($provider==='google'){$params['access_type']='offline';$params['prompt']='consent';}
        header('Location: '.$p['authorize'].'?'.http_build_query($params));exit;
    }
    if($action==='mailbox_callback'){
        $o=$_SESSION['mailbox_oauth']??null;unset($_SESSION['mailbox_oauth']);
        $u=user(false);
        if(!$o||time()-$o['created']>600||!hash_equals($o['state'],(string)($_GET['state']??''))||!$u||$u['workspace']!==$o['workspace'])fail('Mailbox authorization invalid or expired',403);
        if(empty($_GET['code']))fail('Mailbox permission was declined',400);
        $p=mailbox_provider($o['provider']);
        $tokens=mailbox_token_post($o['provider'],['grant_type'=>'authorization_code','code'=>(string)$_GET['code'],'client_id'=>$p['id'],'client_secret'=>$p['secret'],'redirect_uri'=>mailbox_redirect()]);
        mailbox_store($o['workspace'],$o['provider'],$tokens);
        audit('mailbox.connected',$o['provider']);
        header('Location: '.rtrim(config()['app_url'],'/').'/index.php?view=workspace&mailbox=connected');exit;
    }
    if($action==='mailbox_status'){
        $u=member();mailbox_schema();
        $connected=array_column(query('SELECT provider FROM mailbox_connections WHERE workspace=?',[$u['workspace']])->fetchAll(),'provider');
        respond(['connected'=>$connected,'available'=>['google'=>!empty(config()['google_oauth_client_id'])&&!empty(config()['google_oauth_client_secret']),'microsoft'=>!empty(config()['microsoft_oauth_client_id'])&&!empty(config()['microsoft_oauth_client_secret'])]]);
    }
}
function mailbox_post(string $action,array $b): void {
    if($action==='mailbox_disconnect'){
        $u=member();if(!in_array($u['role'],['owner','admin']))fail('Workspace owner required',403);
        $provider=clean($b['provider']??'',20);if(!in_array($provider,['google','microsoft'],true))fail('Invalid provider');
        mailbox_schema();query('DELETE FROM mailbox_connections WHERE workspace=? AND provider=?',[$u['workspace'],$provider]);
        audit('mailbox.disconnected',$provider);respond(['ok'=>true]);
    }
    if($action==='mailbox_sync'){
        $u=member();rate('mailbox_sync:'.$u['workspace'],15,3600);
        $provider=clean($b['provider']??'',20);if(!in_array($provider,['google','microsoft'],true))fail('Invalid provider');
        $messages=mailbox_read($u['workspace'],$provider);
        $results=mailbox_import_messages($u['workspace'],$provider,$messages);
        audit('mailbox.sync',$provider);
        respond($results);
    }
    if($action==='mailbox_send'){
        $u=member();rate('mailbox_send:'.$u['id'],30,3600);
        $provider=clean($b['provider']??'',20);if(!in_array($provider,['google','microsoft'],true))fail('Invalid provider');
        $id=clean($b['contact']??'',32);
        $contact=query("SELECT id,data FROM records WHERE id=? AND workspace=? AND kind='contact'",[$id,$u['workspace']])->fetch();
        if(!$contact)fail('Contact not found',404);
        $data=json_decode($contact['data'],true)?:[];
        $to=email($data['email']??'');
        $subject=clean($b['subject']??'',180);$message=clean($b['message']??'',8000);
        if(!$subject||!$message)fail('Subject and message required');
        mailbox_send_message($u['workspace'],$provider,$to,$subject,$message);
        saveRecord($u['workspace'],'activity',['name'=>$subject,'contact'=>$id,'type'=>'Email','date'=>gmdate('Y-m-d\TH:i'),'notes'=>'Sent to '.$to."\n\n".$message]);
        log_delivery($u['workspace'],'mailbox_sent',$to,true);audit('mailbox.sent',$id);
        respond(['ok'=>true]);
    }
}
