<?php
// ============================================================
// AUCTION HUB GROUP — export_cloudinary_account.php
// Downloads EVERY file in the Cloudinary account to a local folder,
// ready to be uploaded to a new host (ImageKit) under identical paths.
//
// CLI only — this is a machine-side migration tool, never a web page:
//   php export_cloudinary_account.php
//   php export_cloudinary_account.php --dest="D:\AHG_Cloudinary_Export"
//
// Output:
//   <dest>/<public_id>.<format>   one file per asset, same folder tree
//   <dest>/_manifest.csv          public_id, type, format, bytes, url, local path
//   <dest>/_failed.txt            anything that could not be fetched
//
// Resumable: files already present at the right size are skipped. Vehicle
// photos already saved by backup_cloudinary_images.php are copied from
// uploads/vehicle_backup/ instead of being downloaded a second time, so the
// export does not spend bandwidth credits twice on the same file.
//
// The default destination is OUTSIDE htdocs on purpose: the account holds
// proof-of-payment documents, which must never land in a web-served folder.
// ============================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
ob_start();
require_once dirname(__DIR__) . '/config.php';
ob_end_clean();

@set_time_limit(0);

$opts = getopt('', ['dest::']);
$dest = rtrim($opts['dest'] ?? (getenv('USERPROFILE') ?: __DIR__ . '/..') . DIRECTORY_SEPARATOR . 'AHG_Cloudinary_Export', '\\/');
$vehicleBackup = dirname(__DIR__) . '/uploads/vehicle_backup/';

if (!is_dir($dest)) mkdir($dest, 0755, true);

$lock = fopen($dest . '/_export.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    say('Another export is already running into ' . $dest . ' — wait for it to finish.');
    exit(1);
}
$manifestPath = $dest . '/_manifest.csv';
$failPath     = $dest . '/_failed.txt';

function say(string $s): void { echo $s, PHP_EOL; }

/** Admin API GET with basic auth; waits out rate limits instead of failing. */
function adminGet(string $path): array {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $ch = curl_init('https://api.cloudinary.com/v1_1/' . CLOUDINARY_CLOUD_NAME . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => CLOUDINARY_API_KEY . ':' . CLOUDINARY_API_SECRET,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) return json_decode($body, true) ?: [];
        if ($code === 420 || $code === 429) {
            say("  rate limited — waiting 60s (attempt $attempt)");
            sleep(60);
            continue;
        }
        if ($code === 401) {
            say('STOPPED: Cloudinary rejected the credentials or the account is disabled (401).');
            exit(1);
        }
        say("  admin API HTTP $code, retrying in 10s");
        sleep(10);
    }
    say("STOPPED: admin API kept failing for $path");
    exit(1);
}

// ── 1. List every asset ──────────────────────────────────────
$assets = [];
foreach (['image', 'raw', 'video'] as $type) {
    $cursor = null;
    do {
        $q = '/resources/' . $type . '/upload?max_results=500' . ($cursor ? '&next_cursor=' . urlencode($cursor) : '');
        $page = adminGet($q);
        foreach ($page['resources'] ?? [] as $r) {
            // Cloudinary's own demo assets, created with every new account.
            if (str_starts_with($r['public_id'], 'samples/') || str_starts_with($r['public_id'], 'cld-sample')) continue;
            $assets[] = [
                'public_id' => $r['public_id'],
                'type'      => $type,
                'format'    => $r['format'] ?? '',
                'bytes'     => (int)($r['bytes'] ?? 0),
                'url'       => $r['secure_url'],
            ];
        }
        $cursor = $page['next_cursor'] ?? null;
        say(sprintf('Listed %-5s assets: %d so far', $type, count(array_filter($assets, fn($a) => $a['type'] === $type))));
    } while ($cursor);
}

$totalBytes = array_sum(array_column($assets, 'bytes'));
say('');
say('Assets in account : ' . count($assets));
say('Total size        : ' . round($totalBytes / 1048576) . ' MB');
say('Destination       : ' . $dest);
say('');

// ── 2. Download (or reuse) each one ──────────────────────────
$mf = fopen($manifestPath, 'w');
fputcsv($mf, ['public_id', 'type', 'format', 'bytes', 'cloudinary_url', 'local_path']);

$saved = $copied = $skipped = $failed = 0;
$i = 0;
foreach ($assets as $a) {
    $i++;
    // Raw assets already carry their extension inside the public_id.
    $rel = $a['type'] === 'raw' || $a['format'] === ''
        ? $a['public_id']
        : $a['public_id'] . '.' . $a['format'];
    $rel   = str_replace(['..', '\\'], ['', '/'], $rel);
    $local = $dest . '/' . $rel;

    fputcsv($mf, [$a['public_id'], $a['type'], $a['format'], $a['bytes'], $a['url'], $rel]);

    if (is_file($local) && filesize($local) === $a['bytes']) { $skipped++; continue; }
    if (!is_dir(dirname($local))) mkdir(dirname($local), 0755, true);

    $fromBackup = $vehicleBackup . $rel;
    if (is_file($fromBackup) && filesize($fromBackup) === $a['bytes']) {
        copy($fromBackup, $local);
        $copied++;
    } else {
        $ok = false;
        for ($try = 1; $try <= 3 && !$ok; $try++) {
            $fh = @fopen($local . '.part', 'w');
            if (!$fh) { sleep(2 * $try); continue; }
            $ch = curl_init($a['url']);
            curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fh);
            if ($code === 200 && filesize($local . '.part') > 0) {
                rename($local . '.part', $local);
                $ok = true;
            } else {
                @unlink($local . '.part');
                if ($code === 401) { say('STOPPED: account disabled mid-export (401). Re-run once it is back.'); exit(1); }
                sleep(2 * $try);
            }
        }
        if ($ok) { $saved++; } else {
            $failed++;
            file_put_contents($failPath, date('c') . "  {$a['url']}\n", FILE_APPEND);
        }
        usleep(50000);
    }

    if ($i % 250 === 0) {
        say(sprintf('  %d / %d  (downloaded %d, reused %d, already had %d, failed %d)',
            $i, count($assets), $saved, $copied, $skipped, $failed));
    }
}
fclose($mf);

say('');
say("Downloaded     : $saved");
say("Reused backup  : $copied");
say("Already had    : $skipped");
say("Failed         : $failed" . ($failed ? "  (see $failPath)" : ''));
say('Manifest       : ' . $manifestPath);
say($failed ? 'Re-run to retry the failures.' : 'EXPORT COMPLETE.');
