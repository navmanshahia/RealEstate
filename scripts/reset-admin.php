<?php
/** Run only from the hosting account's terminal; never expose recovery over HTTP. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(empty($argv[1])||in_array($argv[1],['--help','-h'],true)){
    fwrite(STDOUT,"Usage: php scripts/reset-admin.php /absolute/path/to/Estate [admin-email]\nCreates a new random password for an existing administrator.\n");exit(empty($argv[1])?1:0);
}
$root=realpath($argv[1]);
if(!$root||!is_file($root.'/app/config.php')||!is_file($root.'/app/core.php')){
    fwrite(STDERR,"Estate installation not found at that path. Use the live folder containing app/config.php.\n");exit(1);
}
try{
    require $root.'/app/core.php';
    $admins=query("SELECT id,workspace,name,email,active FROM users WHERE role='admin' ORDER BY email")->fetchAll();
    $selected=null;
    if(!empty($argv[2])){
        foreach($admins as $admin)if(strtolower($admin['email'])===strtolower(trim($argv[2])))$selected=$admin;
    }elseif(count($admins)===1){$selected=$admins[0];}
    if(!$selected){
        fwrite(STDERR,"No unique administrator selected. Existing administrator emails:\n");
        foreach($admins as $admin)fwrite(STDERR,'  '.$admin['email']."\n");
        fwrite(STDERR,"Run again with the administrator email after the installation path. No password was changed.\n");exit(1);
    }
    $password=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
    $hash=password_hash($password,PASSWORD_DEFAULT);
    db()->beginTransaction();
    query("UPDATE users SET password=?,active=1 WHERE id=? AND role='admin'",[$hash,$selected['id']]);
    query('DELETE FROM resets WHERE user_id=?',[$selected['id']]);
    query('INSERT INTO audit (id,workspace,user_id,action,record_id,created) VALUES (?,?,?,?,?,?)',[uuid(),$selected['workspace'],$selected['id'],'admin.password_recovery_cli',$selected['id'],now()]);
    db()->commit();
    fwrite(STDOUT,"Administrator access recovered.\nWebsite: ".rtrim(config()['app_url'],'/')."/\nEmail: ".$selected['email']."\nNew password: ".$password."\n\nCopy the password now; it is not saved in plaintext. Sign in using a private/incognito window.\nYou can change it under Website & settings after signing in. Existing sessions and reset links have been invalidated.\n");
}catch(Throwable $e){
    try{if(db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}
    fwrite(STDERR,"Recovery failed. Check the installation path, PHP extensions, and database access. No credentials are printed on failure.\n");exit(1);
}
