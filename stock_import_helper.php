<?php
// ============================================================
// AUCTION HUB GROUP — stock_import_helper.php
// Admin → Stock Import: turn old Facebook posts (from Facebook's own
// "Download your information" export ZIP) and new branch stock (photos +
// details) into drafts, review them, then publish each as an upcoming lot
// or as a past sale on the Sold page.
//
// Photos wait in AHG_IMPORT_DIR (outside public_html on the server) until a
// draft is published; only then are they uploaded to ImageKit.
// Requires: config.php (already loaded before this file)
// ============================================================

/** Staging folder: one level above the site (never web-reachable on cPanel) */
function stockImportDir(): string {
    $dir = dirname(__DIR__) . '/ahg_import';
    foreach ([$dir, "$dir/inbox", "$dir/drafts"] as $d) if (!is_dir($d)) @mkdir($d, 0750, true);
    return $dir;
}
function stockDraftDir(int $draftId): string {
    $d = stockImportDir() . '/drafts/' . $draftId;
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

/**
 * Facebook export JSON stores UTF-8 text as escaped single bytes ("â€™" for
 * "'"). Re-read those bytes as UTF-8 when that's what they are.
 */
function fbFixText(string $s): string {
    if ($s === '' || !preg_match('/[\x{80}-\x{FF}]/u', $s) || preg_match('/[^\x{00}-\x{FF}]/u', $s)) return $s;
    $bytes = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $s;
}

/**
 * Best guesses from free post text: year, make, price, mileage and a title.
 * $makes: [id => name]. Everything stays editable before publishing.
 */
function parseVehicleText(string $text, array $makes): array {
    $out = ['title' => '', 'year' => null, 'make_id' => null, 'price' => null, 'mileage' => null];
    $flat = preg_replace('/\s+/u', ' ', $text);

    if (preg_match('/\b(19[89]\d|20[0-3]\d)\b/', $flat, $m)) $out['year'] = (int)$m[1];

    $best = '';
    foreach ($makes as $id => $name) {
        if (mb_strlen($name) > mb_strlen($best) && preg_match('/\b' . preg_quote($name, '/') . '\b/iu', $flat)) {
            $best = $name; $out['make_id'] = (int)$id;
        }
    }
    // VW is written both ways on Facebook
    if (!$out['make_id'] && preg_match('/\bVW\b/i', $flat)) {
        foreach ($makes as $id => $name) if (stripos($name, 'volkswagen') !== false) { $out['make_id'] = (int)$id; break; }
    }

    // R 125 000 · R125,000 · R125k · R1.2m
    if (preg_match('/\bR\s?(\d{1,3}(?:[ ,.]\d{3})+|\d{4,7}|\d{1,4}(?:[.,]\d+)?\s?[kKmM])\b/u', $flat, $m)) {
        $raw = strtolower(str_replace(' ', '', $m[1]));
        if (str_ends_with($raw, 'k'))      $val = (float)str_replace(',', '.', rtrim($raw, 'k')) * 1000;
        elseif (str_ends_with($raw, 'm'))  $val = (float)str_replace(',', '.', rtrim($raw, 'm')) * 1000000;
        else                               $val = (float)preg_replace('/\D/', '', $raw);
        if ($val >= 5000 && $val <= 20000000) $out['price'] = round($val);
    }

    // 123 000 km · 123,000kms · 123000km
    if (preg_match('/\b(\d{1,3}(?:[ ,.]\d{3})+|\d{3,7})\s?(?:km|kms|kilo)/iu', $flat, $m)) {
        $km = (int)preg_replace('/\D/', '', $m[1]);
        if ($km > 0 && $km < 2000000) $out['mileage'] = $km;
    }

    // Title: first meaningful line, without emojis/hashtags/prices
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim(preg_replace(['/[^\p{L}\p{N}\s.,\/\-+()&]/u', '/#\S+/u', '/\bR\s?\d[\d ,.]*[kKmM]?\b/u'], ' ', $line));
        $line = trim(preg_replace('/\s+/u', ' ', $line));
        if (mb_strlen($line) >= 6) { $out['title'] = mb_substr($line, 0, 120); break; }
    }
    if ($out['title'] === '') {
        $out['title'] = trim(($out['year'] ?? '') . ' ' . ($out['make_id'] ? $makes[$out['make_id']] : 'Vehicle'));
    }
    return $out;
}

/** Walk any Facebook export JSON and collect posts that have photos */
function fbCollectPosts($node, array &$posts): void {
    if (!is_array($node)) return;
    $isList = array_is_list($node);
    if (!$isList && (isset($node['attachments']) || isset($node['data'])) && isset($node['timestamp'])) {
        $text = '';
        foreach (($node['data'] ?? []) as $d) if (is_array($d) && !empty($d['post'])) $text .= $d['post'] . "\n";
        if ($text === '' && !empty($node['title']) && is_string($node['title'])) $text = $node['title'];
        $uris = [];
        $attachments = is_array($node['attachments'] ?? null) ? $node['attachments'] : [];
        array_walk_recursive($attachments, function ($v, $k) use (&$uris) {
            if ($k === 'uri' && is_string($v) && preg_match('/\.(jpe?g|png|webp)$/i', $v)) $uris[] = $v;
        });
        if ($uris) {
            $posts[] = ['timestamp' => (int)$node['timestamp'], 'text' => fbFixText(trim($text)), 'uris' => array_values(array_unique($uris))];
            return;
        }
    }
    foreach ($node as $child) if (is_array($child)) fbCollectPosts($child, $posts);
}

/** List posts-with-photos in an export ZIP (newest first) */
function fbZipPosts(ZipArchive $zip): array {
    $posts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!preg_match('#posts?/[^/]*\.json$#i', $name) && !preg_match('#(your_posts|profile_posts|page_posts)[^/]*\.json$#i', $name)) continue;
        $json = json_decode((string)$zip->getFromIndex($i), true);
        if (is_array($json)) fbCollectPosts($json, $posts);
    }
    usort($posts, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
    return $posts;
}

/**
 * Import part of a Facebook export ZIP as drafts. Resumable: call again with
 * the returned 'next' until it is null. Posts already imported are skipped.
 */
function importFacebookZip(string $zipPath, ?int $branchId, int $offset, int $limit, int $adminId): array {
    global $pdo;
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) throw new RuntimeException('Could not open the ZIP file.');
    $posts = fbZipPosts($zip);
    if (!$posts) {
        $zip->close();
        throw new RuntimeException('No posts with photos found. In Facebook choose Download your information → Posts → Format: JSON.');
    }
    $makes = $pdo->query("SELECT id, name FROM makes")->fetchAll(PDO::FETCH_KEY_PAIR);
    $exists = $pdo->prepare("SELECT 1 FROM stock_drafts WHERE source = 'facebook' AND source_ref = ?");
    $insert = $pdo->prepare("
        INSERT INTO stock_drafts (source, source_ref, branch_id, title, description, year, make_id, mileage, price, posted_at, created_by)
        VALUES ('facebook', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $setPhotos = $pdo->prepare("UPDATE stock_drafts SET photos = ? WHERE id = ?");
    $added = 0; $skipped = 0;
    $slice = array_slice($posts, $offset, $limit);
    foreach ($slice as $p) {
        $ref = sha1($p['timestamp'] . '|' . $p['uris'][0]);
        $exists->execute([$ref]);
        if ($exists->fetchColumn()) { $skipped++; continue; }
        $g = parseVehicleText($p['text'], $makes);
        $insert->execute([$ref, $branchId, $g['title'], $p['text'], $g['year'], $g['make_id'], $g['mileage'], $g['price'],
                          date('Y-m-d H:i:s', $p['timestamp']), $adminId]);
        $id = (int)$pdo->lastInsertId();
        $dir = stockDraftDir($id);
        $files = [];
        foreach (array_slice($p['uris'], 0, 30) as $n => $uri) {
            $bytes = $zip->getFromName($uri);
            if ($bytes === false) {           // some exports nest everything one folder deeper
                $idx = $zip->locateName(basename($uri), ZipArchive::FL_NODIR);
                $bytes = $idx !== false ? $zip->getFromIndex($idx) : false;
            }
            if ($bytes === false || strlen($bytes) < 2000) continue;
            $ext  = strtolower(pathinfo($uri, PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpg';
            $file = sprintf('%02d.%s', $n + 1, $ext);
            file_put_contents("$dir/$file", $bytes);
            $files[] = $file;
        }
        $setPhotos->execute([json_encode($files), $id]);
        $added++;
    }
    $zip->close();
    $next = $offset + $limit < count($posts) ? $offset + $limit : null;
    return ['total' => count($posts), 'added' => $added, 'skipped' => $skipped, 'next' => $next];
}

/** Manual new stock: photos from a form upload become a draft */
function saveUploadDraft(array $fields, array $files, int $adminId): int {
    global $pdo;
    $pdo->prepare("
        INSERT INTO stock_drafts (source, source_ref, branch_id, title, description, year, make_id, mileage, price, posted_at, created_by)
        VALUES ('upload', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ")->execute([bin2hex(random_bytes(8)), $fields['branch_id'], $fields['title'], $fields['description'],
                 $fields['year'], $fields['make_id'], $fields['mileage'], $fields['price'], $adminId]);
    $id = (int)$pdo->lastInsertId();
    $dir = stockDraftDir($id);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $saved = [];
    foreach ($files as $n => $f) {
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > MAX_UPLOAD_SIZE) continue;
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$finfo->file($f['tmp_name'])] ?? null;
        if (!$ext) continue;
        $file = sprintf('%02d.%s', $n + 1, $ext);
        if (move_uploaded_file($f['tmp_name'], "$dir/$file")) $saved[] = $file;
    }
    $pdo->prepare("UPDATE stock_drafts SET photos = ? WHERE id = ?")->execute([json_encode($saved), $id]);
    return $id;
}

/** Path of one staged photo (names are checked, so no path tricks) */
function stockPhotoPath(int $draftId, string $file): ?string {
    if (!preg_match('/^\d{2}\.(jpg|png|webp)$/', $file)) return null;
    $p = stockImportDir() . "/drafts/$draftId/$file";
    return is_file($p) ? $p : null;
}

/** Delete a draft's staged photo folder */
function stockRemoveDraftFiles(int $draftId): void {
    $dir = stockImportDir() . "/drafts/$draftId";
    foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
    @rmdir($dir);
}
