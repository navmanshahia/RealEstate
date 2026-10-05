<?php
return [
 'dsn'=>'mysql:host=localhost;dbname=CPANEL_DATABASE;charset=utf8mb4',
 'db_user'=>'CPANEL_USER','db_pass'=>'CHANGE_ME',
 'app_url'=>'https://YOUR_DOMAIN',
 'install_key'=>'REPLACE_WITH_A_RANDOM_STRING_AT_LEAST_32_CHARACTERS',
 'session_secure'=>true,
 'mail_enabled'=>false,'mail_from'=>'hello@YOUR_DOMAIN',
 // Put storage outside public_html where possible. Must be writable by PHP.
 'storage_path'=>dirname(__DIR__).'/storage',
 'stripe_secret'=>'','stripe_webhook_secret'=>'',
 'stripe_prices'=>['Starter'=>'','Professional'=>'','Team'=>''],
 'billing_required'=>false,
];
