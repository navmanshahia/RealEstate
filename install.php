<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function setupUrl(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]+)?$/D', $host)) throw new RuntimeException('Invalid website address.');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
    return ($https ? 'https://' : 'http://') . $host . ($path === '/' ? '' : $path);
}

$configPath = __DIR__ . '/app/config.php';
$configured = is_file($configPath);
$done = false;
$error = '';
$requirements = [];
$lock = null;
$createdConfig = false;
$url = setupUrl();
$local = in_array(parse_url($url, PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
$secure = str_starts_with($url, 'https://');
foreach (['pdo', 'pdo_mysql', 'mbstring', 'fileinfo', 'curl'] as $extension) {
    if (!extension_loaded($extension)) $requirements[] = $extension;
}
if (version_compare(PHP_VERSION, '8.2', '<')) $requirements[] = 'PHP 8.2 or newer';

if ($configured) {
    require __DIR__ . '/app/core.php';
    session_boot();
    try { $done = (bool) query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch(); }
    catch (Throwable $e) { /* A configured database may still need its tables. */ }
} else {
    session_name('estate_session');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly' => true, 'secure' => $secure, 'samesite' => 'Lax', 'path' => parse_url($url, PHP_URL_PATH) ?: '/']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Refresh this page and try again.');
        if (!$secure && !$local) throw new RuntimeException('Open this installer using HTTPS before entering database or admin credentials.');
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if (strlen($name) < 2 || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || strlen($password) < 12 || strlen($password) > 200) {
            throw new RuntimeException('Enter your name, a valid email, and a password of 12–200 characters.');
        }
        if (isset($_POST['confirm']) && !hash_equals($password, (string)$_POST['confirm'])) throw new RuntimeException('The admin passwords do not match.');
        $lock = fopen(__DIR__ . '/app/.install-lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another installation is running. Wait a moment and refresh.');

        if (!$configured) {
            if (is_file($configPath)) throw new RuntimeException('Configuration was created in another window. Refresh this page.');
            if ($requirements) throw new RuntimeException('Enable the missing PHP extensions shown below in cPanel.');
            if (!is_writable(__DIR__ . '/app')) throw new RuntimeException('PHP needs write permission on the app folder to save configuration. Check folder ownership in cPanel.');
            $dbName = trim((string)($_POST['db_name'] ?? ''));
            $dbUser = trim((string)($_POST['db_user'] ?? ''));
            $dbPass = (string)($_POST['db_pass'] ?? '');
            $dbHost = (string)($_POST['db_host'] ?? 'localhost');
            if (!in_array($dbHost, ['localhost', '127.0.0.1'], true) || !preg_match('/^[a-zA-Z0-9_\-]{1,64}$/D', $dbName) || !preg_match('/^[a-zA-Z0-9_\-]{1,80}$/D', $dbUser) || $dbPass === '') {
                throw new RuntimeException('Enter the full cPanel database name, database username, and database password.');
            }
            // The operator must know the local cPanel database credentials. Never accept arbitrary remote hosts or file paths.
            $candidate = [
                'dsn' => 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4',
                'db_user' => $dbUser, 'db_pass' => $dbPass,
                'app_url' => $url, 'install_key' => bin2hex(random_bytes(32)),
                'session_secure' => $secure, 'mail_enabled' => false,
                'mail_from' => $email, 'stripe_secret' => '', 'stripe_webhook_secret' => '',
                'stripe_prices' => ['Starter' => '', 'Professional' => '', 'Team' => ''],
                'billing_required' => false,
            ];
            try {
                $probe = new PDO($candidate['dsn'], $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $probe->query('SELECT 1');
                $probe = null;
            } catch (Throwable $e) {
                throw new RuntimeException('Cannot connect to MySQL. Check the full cPanel database/user names, password, and that the user has all privileges on this database.');
            }
            $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) ?: __DIR__;
            $private = dirname($documentRoot) . '/.estate-storage-' . substr(hash('sha256', __DIR__), 0, 12);
            if ((!is_dir($private) && !@mkdir($private, 0700, true)) || !is_writable($private)) {
                $private = __DIR__ . '/storage';
                if (!is_dir($private) || !is_writable($private) || !is_file($private . '/.htaccess')) {
                    throw new RuntimeException('Cannot create private storage. Make the bundled storage folder writable by your cPanel PHP user and retain its .htaccess file.');
                }
            }
            $candidate['storage_path'] = $private;
            define('ESTATE_SETUP_CONFIG', $candidate);
            require __DIR__ . '/app/core.php';
        } else {
            $key = config()['install_key'] ?? '';
            if (strlen($key) < 32 || str_contains($key, 'REPLACE_') || !hash_equals($key, (string)($_POST['key'] ?? ''))) {
                throw new RuntimeException('Enter the installation key from your existing app/config.php. Existing configuration will not be overwritten.');
            }
        }
        schema();
        if (query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch()) throw new RuntimeException('This database is already installed. Sign in with its existing administrator account.');
        db()->beginTransaction();
        $wid = uuid(); $id = uuid(); $hash = password_hash($password, PASSWORD_DEFAULT);
        query('INSERT INTO workspaces (id,name,slug,data,status,plan,subscription_status,created) VALUES (?,?,?,?,?,?,?,?)', [$wid, $name, 'founder-' . substr($wid, 0, 6), json_encode(['headline'=>'Exceptional homes. Personal guidance.','area'=>'Vancouver','email'=>$email,'published'=>false]), 'approved', 'Professional', 'trial', now()]);
        query('INSERT INTO users (id,workspace,name,email,password,role,active,created) VALUES (?,?,?,?,?,?,?,?)', [$id,$wid,$name,$email,$hash,'admin',1,now()]);
        if (!$configured) {
            // Exclusive creation protects existing configuration; mode 0600 keeps credentials private.
            $oldMask = umask(0077);
            $file = @fopen($configPath, 'x');
            umask($oldMask);
            if (!$file) throw new RuntimeException('Could not save app/config.php. Check app folder ownership in cPanel.');
            $createdConfig = true;
            $contents = "<?php\n// Generated by the Estate setup wizard. Do not commit this file.\nreturn " . var_export($candidate, true) . ";\n";
            $written = fwrite($file, $contents);
            fflush($file); fclose($file);
            if ($written !== strlen($contents)) throw new RuntimeException('Configuration could not be completely saved. Check available disk space.');
        }
        db()->commit();
        $createdConfig = false;
        session_regenerate_id(true);
        $_SESSION['user'] = $id;
        $_SESSION['version'] = hash('sha256', $hash);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['last'] = time();
        header('Location: index.php?view=workspace');
        exit;
    } catch (Throwable $e) {
        if (function_exists('db')) { try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $ignored) {} }
        if ($createdConfig) @unlink($configPath);
        // Connection and SQL errors must never print credentials or server internals.
        $error = $e instanceof PDOException ? 'Database setup failed. Check database privileges and the PHP error log, then try again.' : $e->getMessage();
        if ($e instanceof PDOException) error_log('Estate installation: ' . $e->getCode());
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Set up Estate</title><link rel="stylesheet" href="assets/style.css"></head><body>
<main class="install panel" style="max-width:720px">
<a class="brand" href="index.php">E S T A T E<span>REAL ESTATE, REIMAGINED</span></a>
<h1>Your platform starts here.</h1>
<?php if ($done): ?>
<p>Estate is installed. The installer is locked.</p><a class="btn" href="index.php?view=workspace">Open your workspace →</a>
<?php else: ?>
<p><?= $configured ? 'Your configuration is ready. Create your administrator account.' : 'One form. We’ll save your configuration, prepare storage, create the database tables, and sign you in.' ?></p>
<?php if ($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif; ?>
<?php if (!$configured && $requirements): ?><p class="error">Enable in cPanel: <?=h(implode(', ', $requirements))?>.</p><?php endif; ?>
<?php if (!$secure && !$local): ?><p class="error">Open this page using HTTPS before entering passwords.</p><?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
<?php if (!$configured): ?>
<h3>1. Connect your cPanel database</h3>
<p class="helper">In cPanel’s MySQL Database Wizard, create a database and user, then grant all privileges. Paste those details below. No file editing or installation key is needed.</p>
<div class="fieldgrid">
<label>Database name<input name="db_name" placeholder="cpanelname_estate" value="<?=h($_POST['db_name']??'')?>" required maxlength="64"></label>
<label>Database username<input name="db_user" placeholder="cpanelname_estateuser" value="<?=h($_POST['db_user']??'')?>" required maxlength="80"></label>
<label>Database password<input type="password" name="db_pass" required autocomplete="new-password"></label>
<label>Database host<select name="db_host"><option value="localhost">localhost (usual cPanel setting)</option><option value="127.0.0.1" <?=($_POST['db_host']??'')==='127.0.0.1'?'selected':''?>>127.0.0.1</option></select></label>
</div>
<p class="helper safe-break">Detected website address: <strong><?=h($url)?></strong></p>
<h3>2. Create your admin login</h3>
<?php else: ?>
<label>Existing installation key<input name="key" type="password" required></label>
<?php endif; ?>
<div class="fieldgrid">
<label>Your name<input name="name" required maxlength="150" value="<?=h($_POST['name']??'')?>" autocomplete="name"></label>
<label>Admin email<input type="email" name="email" required maxlength="254" value="<?=h($_POST['email']??'')?>" autocomplete="email"></label>
<label>Admin password<input type="password" name="password" minlength="12" maxlength="200" required autocomplete="new-password"></label>
<label>Confirm admin password<input type="password" name="confirm" minlength="12" maxlength="200" required autocomplete="new-password"></label>
</div>
<p class="helper">Use at least 12 characters. This becomes your login password; there is no default admin password. The installer locks automatically when setup finishes.</p>
<button type="submit" <?=(!$configured&&$requirements)||(!$secure&&!$local)?'disabled':''?>>Install Estate automatically →</button>
</form>
<?php endif; ?>
</main></body></html>
