<?php
declare(strict_types=1);

/** Optional Google Calendar / Microsoft Graph OAuth2 integration for an entire agent workspace. */
function calendar_schema(): void {
    static $checked=false;if($checked)return;
    try { query('SELECT workspace FROM calendar_connections LIMIT 1');query('SELECT appointment FROM calendar_event_links LIMIT 1');$checked=true;return; }
    catch(PDOException $e) {}
    db()->exec('CREATE TABLE IF NOT EXISTS calendar_connections (workspace VARCHAR(32) NOT NULL,provider VARCHAR(16) NOT NULL,token TEXT NOT NULL,expires INTEGER NOT NULL,PRIMARY KEY (workspace,provider))');
    db()->exec('CREATE TABLE IF NOT EXISTS calendar_event_links (appointment VARCHAR(32) NOT NULL,workspace VARCHAR(32) NOT NULL,provider VARCHAR(16) NOT NULL,event_id VARCHAR(255) NOT NULL,PRIMARY KEY (appointment,provider))');
    $checked=true;
}
function calendar_provider(string $provider): array {
    if(!in_array($provider,['google','microsoft'],true))fail('Unknown calendar provider',400);
    $id=config()[$provider.'_oauth_client_id']??'';
    $secret=config()[$provider.'_oauth_client_secret']??'';
    if(!$id||!$secret)fail('Calendar provider credentials have not been configured',503);
    $base=$provider==='google'?'https://oauth2.googleapis.com':'https://login.microsoftonline.com/common/oauth2/v2.0';
    return ['id'=>$id,'secret'=>$secret,'token'=>$base.'/token',
        'auth'=>$provider==='google'?'https://accounts.google.com/o/oauth2/v2/auth':$base.'/authorize',
        'scope'=>$provider==='google'?'https://www.googleapis.com/auth/calendar.events':'offline_access Calendars.ReadWrite User.Read'];
}
function calendar_redirect(): string {return rtrim(config()['app_url'],'/').'/api.php?action=calendar_callback';}
function calendar_secret(string $token): string {
    $key=hash('sha256',(string)config()['install_key'],true);$iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($token,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false)throw new RuntimeException('Cannot encrypt OAuth credential');
    return base64_encode($iv.$tag.$cipher);
}
function calendar_plain(string $value): string {
    $buf=base64_decode($value,true);if($buf===false||strlen($buf)<29)throw new RuntimeException('Invalid OAuth credential');
    $token=openssl_decrypt(substr($buf,28),'aes-256-gcm',hash('sha256',(string)config()['install_key'],true),OPENSSL_RAW_DATA,substr($buf,0,12),substr($buf,12,16));
    if($token===false)throw new RuntimeException('Cannot decrypt OAuth credential');return $token;
}
function calendar_token_post(string $provider,array $fields): array {
    $p=calendar_provider($provider);
    $ch=curl_init($p['token']);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($fields),CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true]);
    $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    $json=json_decode((string)$raw,true);
    if($raw===false||$status>=300||!is_array($json)||empty($json['access_token']))throw new RuntimeException('Calendar authorization failed; reconnect the provider');
    return $json;
}
function calendar_store(string $workspace,string $provider,array $tokens): void {
    calendar_schema();
    $old=query('SELECT token FROM calendar_connections WHERE workspace=? AND provider=?',[$workspace,$provider])->fetchColumn();
    $prior=$old?json_decode(calendar_plain($old),true):[];
    $stored=['access_token'=>$tokens['access_token'],'refresh_token'=>$tokens['refresh_token']??($prior['refresh_token']??'')];
    $encrypted=calendar_secret(json_encode($stored,JSON_THROW_ON_ERROR));$expires=time()+max(30,(int)($tokens['expires_in']??3600));
    if($old)query('UPDATE calendar_connections SET token=?,expires=? WHERE workspace=? AND provider=?',[$encrypted,$expires,$workspace,$provider]);
    else query('INSERT INTO calendar_connections (workspace,provider,token,expires) VALUES (?,?,?,?)',[$workspace,$provider,$encrypted,$expires]);
}
function calendar_access(string $workspace,string $provider): string {
    calendar_schema();$row=query('SELECT token,expires FROM calendar_connections WHERE workspace=? AND provider=?',[$workspace,$provider])->fetch();
    if(!$row)throw new RuntimeException('Calendar not connected');
    $tokens=json_decode(calendar_plain($row['token']),true)?:[];
    if((int)$row['expires']>time()+90)return $tokens['access_token']??'';
    if(empty($tokens['refresh_token']))throw new RuntimeException('Calendar needs reconnection');
    $p=calendar_provider($provider);
    $fresh=calendar_token_post($provider,['grant_type'=>'refresh_token','refresh_token'=>$tokens['refresh_token'],'client_id'=>$p['id'],'client_secret'=>$p['secret']]);
    calendar_store($workspace,$provider,$fresh);
    return $fresh['access_token'];
}
function calendar_http(string $workspace,string $provider,string $method,string $path,?array $payload=null): array {
    $url=$provider==='google'?'https://www.googleapis.com/calendar/v3/calendars/primary/events'.$path:'https://graph.microsoft.com/v1.0/me/events'.$path;
    $token=calendar_access($workspace,$provider);
    $headers=['Authorization: Bearer '.$token,'Content-Type: application/json'];
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers]);
    if($payload!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));
    $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($raw===false||$status<200||$status>=300)throw new RuntimeException('Calendar sync rejected the request (HTTP '.$status.')');
    return json_decode((string)$raw,true)?:[];
}
function calendar_sync_record(string $workspace,string $id): array {
    calendar_schema();$record=query("SELECT * FROM records WHERE id=? AND workspace=? AND kind='appointment'",[$id,$workspace])->fetch();
    if(!$record)return [];
    $data=json_decode($record['data'],true)?:[];if(empty($data['date']))return [];
    $ws=query('SELECT data FROM workspaces WHERE id=?',[$workspace])->fetchColumn();
    $settings=json_decode($ws?:'{}',true)?:[];
    try{$zone=new DateTimeZone($settings['timezone']??'America/Vancouver');}
    catch(Throwable $e){$zone=new DateTimeZone('UTC');}
    try{$start=new DateTimeImmutable($data['date'],$zone);}catch(Throwable $e){return ['error'=>'Invalid appointment date'];}
    $end=$start->modify('+1 hour');$startUtc=$start->setTimezone(new DateTimeZone('UTC'));$endUtc=$end->setTimezone(new DateTimeZone('UTC'));
    $results=[];
    foreach(query('SELECT provider FROM calendar_connections WHERE workspace=?',[$workspace])->fetchAll() as $connection){
        $provider=$connection['provider'];$existing=query('SELECT event_id FROM calendar_event_links WHERE appointment=? AND workspace=? AND provider=?',[$id,$workspace,$provider])->fetchColumn();
        $path=$existing?'/'.rawurlencode($existing):'';
        if($provider==='google'){
            $payload=['summary'=>$data['name']??'Appointment','description'=>$data['notes']??'','location'=>$data['location']??'','start'=>['dateTime'=>$startUtc->format('Y-m-d\TH:i:s\Z')],'end'=>['dateTime'=>$endUtc->format('Y-m-d\TH:i:s\Z')]];
        }else{
            $payload=['subject'=>$data['name']??'Appointment','body'=>['contentType'=>'text','content'=>$data['notes']??''],'location'=>['displayName'=>$data['location']??''],'start'=>['dateTime'=>$startUtc->format('Y-m-d\TH:i:s'),'timeZone'=>'UTC'],'end'=>['dateTime'=>$endUtc->format('Y-m-d\TH:i:s'),'timeZone'=>'UTC']];
        }
        try{
            if(($data['status']??'')==='Cancelled'){
                if($existing)calendar_http($workspace,$provider,'DELETE',$path);
                query('DELETE FROM calendar_event_links WHERE appointment=? AND workspace=? AND provider=?',[$id,$workspace,$provider]);
                $results[$provider]='cancelled';continue;
            }
            $response=calendar_http($workspace,$provider,$existing?'PATCH':'POST',$path,$payload);
            $eventId=$existing?:($response['id']??'');
            if(!$eventId)throw new RuntimeException('Calendar provider did not return an event identifier');
            if($existing)query('UPDATE calendar_event_links SET event_id=? WHERE appointment=? AND workspace=? AND provider=?',[$eventId,$id,$workspace,$provider]);
            else query('INSERT INTO calendar_event_links (appointment,workspace,provider,event_id) VALUES (?,?,?,?)',[$id,$workspace,$provider,$eventId]);
            $results[$provider]='synced';
        }catch(Throwable $e){$results[$provider]='failed';error_log('Estate calendar sync '.$provider.': '.$e->getMessage());}
    }
    return $results;
}
function calendar_delete_record(string $workspace,string $id): void {
    calendar_schema();
    foreach(query('SELECT provider,event_id FROM calendar_event_links WHERE workspace=? AND appointment=?',[$workspace,$id])->fetchAll() as $link){
        try {calendar_http($workspace,$link['provider'],'DELETE','/'.rawurlencode($link['event_id']));query('DELETE FROM calendar_event_links WHERE workspace=? AND appointment=? AND provider=?',[$workspace,$id,$link['provider']]);}
        catch(Throwable $e){error_log('Estate calendar delete: '.$e->getMessage());}
    }
}
function calendar_get(string $action): void {
    if($action==='calendar_start'){
        $u=member();if(!in_array($u['role'],['owner','admin']))fail('Only owners can connect calendars',403);
        $provider=clean($_GET['provider']??'',20);$p=calendar_provider($provider);
        $state=bin2hex(random_bytes(24));$_SESSION['calendar_oauth']=['state'=>$state,'provider'=>$provider,'workspace'=>$u['workspace'],'created'=>time()];
        $args=['client_id'=>$p['id'],'redirect_uri'=>calendar_redirect(),'response_type'=>'code','scope'=>$p['scope'],'state'=>$state];
        if($provider==='google'){$args['access_type']='offline';$args['prompt']='consent';}
        header('Location: '.$p['auth'].'?'.http_build_query($args));exit;
    }
    if($action==='calendar_callback'){
        $o=$_SESSION['calendar_oauth']??null;unset($_SESSION['calendar_oauth']);
        if(!$o||time()-$o['created']>600||!hash_equals($o['state'],(string)($_GET['state']??''))||!user(false)||user()['workspace']!==$o['workspace'])fail('Calendar authorization expired or mismatched',403);
        if(empty($_GET['code']))fail('Calendar authorization was declined',400);
        $p=calendar_provider($o['provider']);
        $tokens=calendar_token_post($o['provider'],['grant_type'=>'authorization_code','code'=>(string)$_GET['code'],'client_id'=>$p['id'],'client_secret'=>$p['secret'],'redirect_uri'=>calendar_redirect()]);
        calendar_store($o['workspace'],$o['provider'],$tokens);audit('calendar.connected',$o['provider']);
        header('Location: '.rtrim(config()['app_url'],'/').'/index.php?view=workspace&calendar=connected');exit;
    }
    if($action==='calendar_status'){
        $u=member();calendar_schema();$connected=array_column(query('SELECT provider FROM calendar_connections WHERE workspace=?',[$u['workspace']])->fetchAll(),'provider');
        respond(['connected'=>$connected,'available'=>['google'=>!empty(config()['google_oauth_client_id']),'microsoft'=>!empty(config()['microsoft_oauth_client_id'])]]);
    }
}
function calendar_post(string $action,array $b): void {
    if($action==='calendar_disconnect'){
        $u=member();if(!in_array($u['role'],['owner','admin']))fail('Owner access required',403);
        $provider=clean($b['provider']??'',20);if(!in_array($provider,['google','microsoft']))fail('Invalid provider');
        calendar_schema();query('DELETE FROM calendar_connections WHERE workspace=? AND provider=?',[$u['workspace'],$provider]);
        query('DELETE FROM calendar_event_links WHERE workspace=? AND provider=?',[$u['workspace'],$provider]);audit('calendar.disconnected',$provider);respond(['ok'=>true]);
    }
    if($action==='calendar_sync'){
        $u=member();$id=clean($b['id']??'',32);
        if(!query("SELECT id FROM records WHERE id=? AND workspace=? AND kind='appointment'",[$id,$u['workspace']])->fetch())fail('Appointment not found',404);
        respond(['providers'=>calendar_sync_record($u['workspace'],$id)]);
    }
}
