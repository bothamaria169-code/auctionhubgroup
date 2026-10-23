<?php
// ============================================================
// AUCTION HUB GROUP — upload_to_imagekit.php
// Pushes the local Cloudinary export into ImageKit under IDENTICAL paths,
// so switching the site over is a prefix swap:
//   https://res.cloudinary.com/dkrfbur9f/image/upload/v123/pva/vehicles/2026/x.jpg
//   https://ik.imagekit.io/<your_id>/pva/vehicles/2026/x.jpg
//
// CLI only. The key is read from the environment or from ahg_secrets.php in
// the folder above the site (as imagekit_helper.php does), never from the site:
//   set IMAGEKIT_PRIVATE_KEY=private_xxxxxxxxxxxxxxxx
//   php upload_to_imagekit.php
//   php upload_to_imagekit.php --src="C:\Users\Zama\AHG_Cloudinary_Export" --only=pva
//
// --only=pva      upload just one top-level folder (pva, bidanwin, gobid)
// --ledger=NAME   which record of finished uploads to use. Each ImageKit
//                 account needs its own, e.g. --ledger=_imagekit_2f8m5mdk5.txt
// --part=2/4      handle every 4th file starting at the 2nd, so four copies of
//                 this script can run side by side, each with its own ledger
// --dry-run       list what would be uploaded without sending anything
//
// Resumable: every successful upload is appended to the ledger and skipped on
// the next run, so it is safe to stop and restart at any point.
// ============================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

@set_time_limit(0);

$opts   = getopt('', ['src::', 'only::', 'ledger::', 'part::', 'dry-run']);
$src    = rtrim($opts['src'] ?? (getenv('USERPROFILE') . DIRECTORY_SEPARATOR . 'AHG_Cloudinary_Export'), '\\/');
$only   = isset($opts['only']) ? trim($opts['only'], '/') : '';
$dryRun = array_key_exists('dry-run', $opts);
$key    = getenv('IMAGEKIT_PRIVATE_KEY') ?: '';

// A ledger records what reached one particular ImageKit account. Point a run at
// a different account and it needs a different ledger, or everything is skipped.
$ledgerName = isset($opts['ledger']) ? basename((string)$opts['ledger']) : '_imagekit_uploaded.txt';
$part  = 0;
$parts = 1;
if (isset($opts['part'])) {
    if (!preg_match('#^(\d+)\s*/\s*(\d+)$#', (string)$opts['part'], $m) || $m[1] < 1 || $m[1] > $m[2]) {
        echo 'Use --part=2/4 (which part, of how many).', PHP_EOL;
        exit(1);
    }
    $part       = (int)$m[1] - 1;
    $parts      = (int)$m[2];
    $ledgerName = preg_replace('/\.txt$/', '', $ledgerName) . '_part' . ($part + 1) . '.txt';
}

$secretsFile = dirname(__DIR__) . '/ahg_secrets.php';
if ($key === '' && is_file($secretsFile)) {
    $secrets = include $secretsFile;
    $key = is_array($secrets) ? (string)($secrets['IMAGEKIT_PRIVATE_KEY'] ?? '') : '';
}

function say(string $s): void { echo $s, PHP_EOL; }

if (!$dryRun && !str_starts_with($key, 'private_')) {
    say("Set IMAGEKIT_PRIVATE_KEY first, or put it in $secretsFile (ImageKit dashboard → Developer options → API keys).");
    exit(1);
}
if (!is_file($src . '/_manifest.csv')) {
    say("No export found at $src — run export_cloudinary_account.php first.");
    exit(1);
}

$ledgerPath = $src . '/' . $ledgerName;
$failPath   = $src . '/' . preg_replace('/\.txt$/', '', $ledgerName) . '_failed.txt';
$done = is_file($ledgerPath)
    ? array_flip(array_map('trim', file($ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)))
    : [];

// The manifest is the source of truth for which files belong to the account.
$rows = [];
$seen = 0;
$fh = fopen($src . '/_manifest.csv', 'r');
$header = fgetcsv($fh);
while (($r = fgetcsv($fh)) !== false) {
    $row = array_combine($header, $r);
    if ($only !== '' && !str_starts_with($row['local_path'], $only . '/')) continue;
    if ($parts > 1 && ($seen++ % $parts) !== $part) continue;
    $rows[] = $row;
}
fclose($fh);

$pending = array_values(array_filter($rows, fn($r) => !isset($done[$r['local_path']])));
say('Ledger            : ' . $ledgerName . ($parts > 1 ? '   (part ' . ($part + 1) . ' of ' . $parts . ')' : ''));
say('Files in scope    : ' . count($rows));
say('Already uploaded  : ' . (count($rows) - count($pending)));
say('To upload now     : ' . count($pending));
say($dryRun ? '(dry run — nothing will be sent)' : '');

$ok = $failed = 0;

// One connection, reused for every file: setting up TLS again for each photo
// costs more than sending the photo itself.
$ch = curl_init('https://upload.imagekit.io/api/v1/files/upload');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => $key . ':',
    CURLOPT_TIMEOUT        => 120,
]);

foreach ($pending as $i => $r) {
    $local = $src . '/' . $r['local_path'];
    if (!is_file($local)) {
        $failed++;
        file_put_contents($failPath, date('c') . "  missing locally  {$r['local_path']}\n", FILE_APPEND);
        continue;
    }

    $folder   = '/' . trim(str_replace('\\', '/', dirname($r['local_path'])), '/.');
    $fileName = basename($r['local_path']);

    if ($dryRun) {
        if ($i < 10) say("  would upload $folder/$fileName");
        continue;
    }

    $sent = false;
    for ($try = 1; $try <= 3 && !$sent; $try++) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file'              => new CURLFile($local),
            'fileName'          => $fileName,
            'folder'            => $folder,
            // Keep the exact filename so URLs map 1:1 from Cloudinary.
            'useUniqueFileName' => 'false',
            'overwriteFile'     => 'true',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code === 200) {
            file_put_contents($ledgerPath, $r['local_path'] . "\n", FILE_APPEND);
            $ok++;
            $sent = true;
        } elseif ($code === 401 || $code === 403) {
            say("STOPPED: ImageKit rejected the key (HTTP $code). Check IMAGEKIT_PRIVATE_KEY.");
            exit(1);
        } elseif ($code === 429) {
            say('  rate limited by ImageKit — waiting 30s');
            sleep(30);
        } else {
            sleep(2 * $try);
            if ($try === 3) {
                $failed++;
                $msg = json_decode((string)$body, true)['message'] ?? substr((string)$body, 0, 120);
                file_put_contents($failPath, date('c') . "  HTTP $code  {$r['local_path']}  $msg\n", FILE_APPEND);
            }
        }
    }

    if (($i + 1) % 200 === 0) say(sprintf('  %d / %d  (ok %d, failed %d)', $i + 1, count($pending), $ok, $failed));
}
curl_close($ch);

say('');
say("Uploaded : $ok");
say("Failed   : $failed" . ($failed ? "  (see $failPath)" : ''));
say($failed ? 'Re-run to retry the failures.' : ($dryRun ? 'Dry run finished.' : 'UPLOAD COMPLETE.'));
