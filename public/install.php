<?php
/**
 * Opryx one-time web installer.
 *
 * Open https://YOUR-DOMAIN/install.php once after uploading the code. It:
 *   1. tests your MySQL details, 2. writes the .env file with a fresh APP_KEY,
 *   3. creates all database tables, 4. creates your company + owner login,
 *   5. locks itself (storage/installed.lock) so it can never run again.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$lock = $root.'/storage/installed.lock';
$envFile = $root.'/.env';

function h(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
// Single quotes = literal value in phpdotenv (no ${VAR} interpolation); escape only \\ and '.
function envValue(string $v): string { return "'".str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", '', ''], $v)."'"; }

$errors = [];
$done = false;
$existing = false;
$installed = file_exists($lock);
$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$old = array_merge([
    'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'company' => '', 'owner_name' => '', 'owner_email' => '', 'owner_pass' => '',
], array_map(fn ($v) => is_string($v) ? trim($v) : '', $_POST));

// Server requirement checks.
$checks = [
    'PHP 8.2 or newer' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'pdo_mysql extension' => extension_loaded('pdo_mysql'),
    'mbstring extension' => extension_loaded('mbstring'),
    'openssl extension' => extension_loaded('openssl'),
    'Composer packages (vendor/) present' => file_exists($root.'/vendor/autoload.php'),
    'Built frontend (public/build) present' => file_exists(__DIR__.'/build/manifest.json'),
    'storage/ is writable' => is_writable($root.'/storage'),
    'bootstrap/cache is writable' => is_writable($root.'/bootstrap/cache'),
    'App folder is writable (for .env)' => is_writable($root) || (file_exists($envFile) && is_writable($envFile)),
];
$ready = !in_array(false, $checks, true);

if (!$installed && $ready && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $old['db_name'])) $errors[] = 'Database name may only contain letters, numbers and _.';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $old['db_user'])) $errors[] = 'Database user may only contain letters, numbers and _.';
    // Only the database on this server may be used (stops anyone pointing the app at a foreign database).
    if (!in_array($old['db_host'], ['127.0.0.1', 'localhost'], true)) $errors[] = 'Database host must be 127.0.0.1 (the database on this server).';

    $existing = false; // true = re-connecting this code to an Opryx database that already has this company
    if (!$errors) {
        try {
            $pdo = new PDO("mysql:host={$old['db_host']};port=".(int) $old['db_port'].";dbname={$old['db_name']};charset=utf8mb4",
                $old['db_user'], $_POST['db_pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            try {
                $q = $pdo->prepare('SELECT COUNT(*) FROM tenant_domains WHERE hostname = ?');
                $q->execute([$host]);
                $existing = (int) $q->fetchColumn() > 0;
            } catch (Throwable) { $existing = false; }
            $pdo = null;
        } catch (Throwable $e) {
            $errors[] = 'Could not connect to the database: '.$e->getMessage();
        }
    }
    if (!$errors && !$existing) {
        if ($old['company'] === '') $errors[] = 'Company name is required.';
        if ($old['owner_name'] === '') $errors[] = 'Your name is required.';
        if (!filter_var($old['owner_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
        if (strlen($_POST['owner_pass'] ?? '') < 8) $errors[] = 'Owner password must be at least 8 characters.';
    }

    if (!$errors) {
        try {
            $appUrl = ($https ? 'https://' : 'http://').$host;
            $env = [
                'APP_NAME' => 'Opryx', 'APP_ENV' => 'production', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'APP_DEBUG' => 'false', 'APP_URL' => $appUrl, 'APP_TIMEZONE' => 'Asia/Dhaka',
                'APP_LOCALE' => 'en', 'APP_FALLBACK_LOCALE' => 'en',
                'LOG_CHANNEL' => 'daily', 'LOG_LEVEL' => 'warning',
                'DB_CONNECTION' => 'mysql', 'DB_HOST' => $old['db_host'], 'DB_PORT' => (string) (int) $old['db_port'],
                'DB_DATABASE' => $old['db_name'], 'DB_USERNAME' => $old['db_user'], 'DB_PASSWORD' => $_POST['db_pass'] ?? '',
                'SESSION_DRIVER' => 'database', 'SESSION_LIFETIME' => '480', 'SESSION_SECURE_COOKIE' => $https ? 'true' : 'false',
                'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'sync', 'FILESYSTEM_DISK' => 'local',
                'MAIL_MAILER' => 'log', 'MAIL_FROM_ADDRESS' => 'noreply@'.$host, 'MAIL_FROM_NAME' => 'Opryx',
                'VITE_APP_NAME' => 'Opryx',
            ];
            $text = "# Written by the Opryx installer on ".date('c')."\n";
            foreach ($env as $k => $v) $text .= $k.'='.envValue($v)."\n";
            if (file_put_contents($envFile, $text, LOCK_EX) === false) throw new RuntimeException('Could not write .env');
            @chmod($envFile, 0600);

            require $root.'/vendor/autoload.php';
            $app = require $root.'/bootstrap/app.php';
            $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();
            $kernel->call('config:clear');
            if ($kernel->call('migrate', ['--force' => true]) !== 0) throw new RuntimeException("Migration failed:\n".$kernel->output());
            if (!$existing) App\Support\TenantProvisioner::create($old['company'], $host, $old['owner_name'], $old['owner_email'], $_POST['owner_pass']);
            file_put_contents($lock, 'Installed '.date('c')." for $host\n");
            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Installation failed: '.$e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Install Opryx</title>
<style>
  :root { --ink:#0b1220; --brand:#168374; --brand2:#43bfaa; --line:#e2e8f0; --muted:#64748b; }
  * { box-sizing: border-box; }
  body { margin:0; font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:#f1f5f9; color:#0f172a; }
  .wrap { max-width:640px; margin:40px auto; padding:0 16px; }
  .brand { display:flex; align-items:center; gap:10px; font-weight:700; font-size:20px; margin-bottom:20px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:24px; margin-bottom:16px; }
  h2 { font-size:15px; margin:0 0 12px; }
  label { display:block; font-size:13px; font-weight:600; margin:12px 0 4px; }
  input { width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font:inherit; }
  input:focus { outline:2px solid var(--brand2); border-color:transparent; }
  .row { display:grid; grid-template-columns:2fr 1fr; gap:12px; }
  .hint { font-size:12px; color:var(--muted); margin-top:4px; }
  button { margin-top:20px; width:100%; padding:12px; border:0; border-radius:8px; background:var(--brand); color:#fff; font-weight:600; font-size:15px; cursor:pointer; }
  .err { background:#fff1f2; color:#9f1239; border:1px solid #fecdd3; border-radius:8px; padding:12px; margin-bottom:16px; white-space:pre-wrap; }
  .ok { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; border-radius:8px; padding:16px; }
  .checks li { list-style:none; padding:3px 0; } .checks { padding:0; margin:0; }
  a.btn { display:inline-block; margin-top:12px; padding:10px 18px; background:var(--brand); color:#fff; border-radius:8px; text-decoration:none; font-weight:600; }
</style>
</head>
<body><div class="wrap">
  <div class="brand"><svg width="32" height="32" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#0b1220"/><circle cx="32" cy="32" r="15" fill="none" stroke="#43bfaa" stroke-width="7"/><circle cx="44" cy="20" r="5" fill="#d5f5ee"/></svg> Opryx installer</div>

<?php if ($installed && !$done): ?>
  <div class="card ok">Opryx is already installed on this server. <br><a class="btn" href="/login">Go to login</a></div>
<?php elseif ($done): ?>
  <div class="card ok"><b><?= $existing ? 'Reconnected to your existing data.' : 'Installation complete.' ?></b><br><?= $existing ? 'Your company, products and logins are unchanged.' : 'Your company and owner login are ready.' ?> This installer is now locked.<br>
    <a class="btn" href="/login">Log in now</a></div>
<?php else: ?>
  <div class="card"><h2>1. Server check</h2><ul class="checks">
    <?php foreach ($checks as $label => $ok): ?><li><?= $ok ? '✅' : '❌' ?> <?= h($label) ?></li><?php endforeach; ?>
  </ul><?php if (!$ready): ?><p class="hint">Fix the ❌ items first (see the setup guide), then reload this page.</p><?php endif; ?></div>

  <?php if ($errors): ?><div class="err"><?= h(implode("\n", $errors)) ?></div><?php endif; ?>

  <?php if ($ready): ?>
  <form method="post" class="card" autocomplete="off">
    <h2>2. Database (from Hostinger → Databases)</h2>
    <div class="row">
      <div><label>Database host</label><input name="db_host" value="<?= h($old['db_host']) ?>" required><div class="hint">On Hostinger keep 127.0.0.1</div></div>
      <div><label>Port</label><input name="db_port" value="<?= h($old['db_port']) ?>" required></div>
    </div>
    <label>Database name</label><input name="db_name" value="<?= h($old['db_name']) ?>" placeholder="u725004010_opryx" required>
    <label>Database user</label><input name="db_user" value="<?= h($old['db_user']) ?>" placeholder="u725004010_opryx" required>
    <label>Database password</label><input type="password" name="db_pass" required>

    <h2 style="margin-top:24px">3. Your company &amp; owner login</h2>
    <p class="hint">Already used Opryx with this database? Leave this part empty — your existing company and logins are kept.</p>
    <label>Company name</label><input name="company" value="<?= h($old['company']) ?>" placeholder="Sheikh IT">
    <div class="hint">This company will open on <b><?= h($host) ?></b></div>
    <label>Your name</label><input name="owner_name" value="<?= h($old['owner_name']) ?>">
    <label>Login email</label><input type="email" name="owner_email" value="<?= h($old['owner_email']) ?>">
    <label>Login password (min 8)</label><input type="password" name="owner_pass" minlength="8">
    <button type="submit">Install Opryx</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div></body></html>
