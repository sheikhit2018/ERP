<?php
/**
 * Opryx package unpacker (one-time).
 * Extracts opryx-release.zip that sits next to this file, then deletes the zip and itself.
 * Open in the browser: https://erp.sheikhit.com.bd/opryx-unpack.php
 */
declare(strict_types=1);
@set_time_limit(400);
header('Content-Type: text/html; charset=utf-8');
$dir = __DIR__;
$zipFile = $dir.'/opryx-release.zip';

function out(string $msg, bool $ok = true): void {
    echo '<p style="font:15px system-ui;color:'.($ok ? '#065f46' : '#9f1239').'">'.htmlspecialchars($msg).'</p>';
}
echo '<!doctype html><meta name="robots" content="noindex"><title>Opryx unpack</title><div style="max-width:620px;margin:40px auto">';
echo '<h2 style="font:600 20px system-ui">Opryx – unpacking files</h2>';

if (!file_exists($zipFile)) { out('opryx-release.zip not found. Already unpacked? Open /install.php next.', false); exit; }
if (!class_exists('ZipArchive')) { out('PHP zip extension is not enabled.', false); exit; }

$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) { out('Could not open the zip file (upload may be incomplete).', false); exit; }

// Refuse unsafe paths before extracting anything.
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name === false || str_contains($name, '..') || str_starts_with($name, '/')) { out("Unsafe path in archive: $name", false); exit; }
}
$count = $zip->numFiles;
if (!$zip->extractTo($dir)) { out('Extraction failed.', false); exit; }
$zip->close();

@unlink($zipFile);
foreach (['default.php'] as $placeholder) if (file_exists("$dir/$placeholder")) @unlink("$dir/$placeholder");
out("Done: $count files extracted.");

// Update of an existing installation: apply new database migrations and clear caches.
if (file_exists($dir.'/.env') && file_exists($dir.'/storage/installed.lock')) {
    try {
        require $dir.'/vendor/autoload.php';
        $app = require $dir.'/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        $kernel->call('migrate', ['--force' => true]);
        out('Database updated: '.trim($kernel->output()) ?: 'nothing to migrate');
        $kernel->call('optimize:clear');
        out('Caches cleared. Update complete.');
        echo '<p><a href="/dashboard">Open Opryx →</a></p></div>';
        @unlink(__FILE__);
        exit;
    } catch (Throwable $e) { out('Files updated, but migration failed: '.$e->getMessage(), false); @unlink(__FILE__); exit; }
}
echo '<p><a href="/install.php" style="display:inline-block;padding:10px 18px;background:#168374;color:#fff;border-radius:8px;text-decoration:none;font:600 15px system-ui">Continue to installer →</a></p></div>';
@unlink(__FILE__);
