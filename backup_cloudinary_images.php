<?php
// ============================================================
// AUCTION HUB GROUP — backup_cloudinary_images.php
// Copies every vehicle photo out of Cloudinary onto this server.
//
// Why: Cloudinary was the ONLY copy of ~6,370 vehicle photos. When the
// account was disabled for exceeding its credit quota, every photo on the
// site went dark with no way to recover them. This keeps a local copy.
//
// Run AFTER Cloudinary access is restored:
//   Browser: https://auctionhubgroup.co.za/backup_cloudinary_images.php?key=<AHG_CRON_KEY>
//   CLI:     php backup_cloudinary_images.php
//
// Resumable: files already on disk are skipped, so just reload the page
// (or re-run the command) until it reports 0 remaining. Each browser run
// handles ?limit=N files (default 250) to stay under PHP's time limit.
// Stops immediately if Cloudinary is still disabled, so it never burns
// through requests against a dead account.
// ============================================================

ob_start();
require_once __DIR__ . '/config.php';
ob_end_clean();

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && (($_GET['key'] ?? '') !== AHG_CRON_KEY)) {
    http_response_code(403);
    die('Forbidden');
}

@set_time_limit(0);
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');

$limit   = $isCli ? PHP_INT_MAX : max(1, min(2000, (int)($_GET['limit'] ?? 250)));
$destDir = __DIR__ . '/uploads/vehicle_backup/';

function out(string $line): void {
    echo $line, "\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ── Collect every Cloudinary URL the site references ─────────
$urls = [];
foreach ($pdo->query("SELECT image_url FROM auctions WHERE image_url LIKE '%res.cloudinary.com%'") as $r) {
    $urls[] = $r['image_url'];
}
foreach ($pdo->query("SELECT file_path FROM auction_gallery WHERE file_path LIKE '%res.cloudinary.com%'") as $r) {
    $urls[] = $r['file_path'];
}
$urls = array_values(array_unique($urls));

/** Local path mirrors everything after /image/upload/, minus the version segment. */
function localPathFor(string $url, string $destDir): ?string {
    if (!preg_match('#/image/upload/(?:v\d+/)?(.+)$#', parse_url($url, PHP_URL_PATH) ?? '', $m)) {
        return null;
    }
    $rel = str_replace(['..', '\\'], '', $m[1]);
    return $destDir . $rel;
}

$pending = [];
$already = 0;
foreach ($urls as $u) {
    $path = localPathFor($u, $destDir);
    if ($path === null) continue;
    if (is_file($path) && filesize($path) > 0) { $already++; continue; }
    $pending[] = [$u, $path];
}

out('=== AHG Cloudinary photo backup ===');
out('Referenced photos : ' . count($urls));
out('Already backed up : ' . $already);
out('Still to download : ' . count($pending));
out('This run          : up to ' . ($limit === PHP_INT_MAX ? 'all' : $limit));
out('');

if (!$pending) {
    out('Nothing to do — every photo is already saved in uploads/vehicle_backup/.');
    exit;
}

$ok = 0; $failed = 0; $done = 0;
$failLog = $destDir . '_failed.txt';
if (!is_dir($destDir)) mkdir($destDir, 0755, true);

foreach ($pending as [$url, $path]) {
    if ($done >= $limit) break;
    $done++;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HEADER         => true,
    ]);
    $resp   = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = $resp === false ? '' : substr($resp, 0, $hsize);
    $body    = $resp === false ? '' : substr($resp, $hsize);

    $accountDisabled = stripos($headers, 'disabled') !== false
                    || stripos($headers, 'cannot be accessed') !== false;
    if ($code === 401 && $accountDisabled) {
        out('STOPPED: Cloudinary account is still disabled (HTTP 401).');
        out('Nothing was downloaded. Re-run once support has reactivated the account.');
        exit;
    }

    if ($code === 200 && strlen($body) > 0) {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
        file_put_contents($path, $body);
        $ok++;
    } else {
        $failed++;
        file_put_contents($failLog, date('c') . "  HTTP $code  $url\n", FILE_APPEND);
    }

    if ($done % 50 === 0) out("  … $done processed ($ok saved, $failed failed)");
    usleep(100000); // gentle pacing: ~10 requests/second
}

$remaining = count($pending) - $ok;
out('');
out("Saved this run : $ok");
out("Failed         : $failed" . ($failed ? '  (see uploads/vehicle_backup/_failed.txt)' : ''));
out("Remaining      : $remaining");
out($remaining > 0 ? 'Reload this page to continue.' : 'Backup complete.');
