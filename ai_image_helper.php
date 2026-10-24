<?php
// ============================================================
// AUCTION HUB GROUP — ai_image_helper.php
// Grok (xAI) photo editing for vehicle images.
//   • The API key is pasted by an admin in Admin → AI Photos and stored in
//     site_settings — never in code or GitHub.
//   • xAI docs: POST https://api.x.ai/v1/images/edits (JSON only, not
//     multipart) — https://docs.x.ai/developers/model-capabilities/images/editing
// Requires: config.php (already loaded before this file)
// ============================================================

const AI_DEFAULT_MODEL = 'grok-imagine-image-2.0';
const AI_TMP_TTL       = 3600;   // unsaved edits are discarded after an hour

// Added in front of every prompt. Buyers bid on what the photos show, so an
// edit may tidy the picture but must never change the vehicle itself.
const AI_PROMPT_GUARD = 'Edit this used-vehicle auction photo. Keep the vehicle itself exactly as it is: '
    . 'same make, model, colour, wheels, badges, number of doors, and every scratch, dent, rust spot or other damage. '
    . 'Do not add, remove or repair anything on the vehicle. Only make this change: ';

// "After repair" preview: allowed only because the result is stamped as an AI
// preview on the image itself and can only be saved as an extra gallery photo,
// never the main one, so buyers can't mistake it for the vehicle's condition.
const AI_REPAIR_PROMPT = 'Show this exact vehicle as it would look after its visible body damage '
    . '(dents, scratches, scuffs, rust, cracked lights or glass) has been professionally repaired. '
    . 'Keep the make, model, colour, wheels, camera angle, lighting and background identical.';
const AI_PREVIEW_LABEL = 'AI PREVIEW - AFTER REPAIR - NOT CURRENT CONDITION';

/** Ready-made edits shown as buttons in the admin */
const AI_PRESETS = [
    'background' => ['Clean background',  'Replace the background with a clean, bright, neutral grey studio backdrop with a soft floor shadow.'],
    'yard'       => ['Tidy yard',         'Remove clutter, other vehicles and people from the background; keep a realistic, tidy auction-yard setting.'],
    'light'      => ['Fix lighting',      'Correct the exposure, white balance and contrast so the photo looks evenly lit and natural, like a professional dealer photo.'],
    'plate'      => ['Blur number plate', 'Blur the number plate so the characters cannot be read. Change nothing else.'],
    'sky'        => ['Blue sky',          'Replace a dull or overexposed sky with a natural clear blue sky. Change nothing else.'],
];

// ── Settings (site_settings table) ───────────────────────────

function aiSetting(string $key, string $default = ''): string {
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : (string)$v;
    } catch (PDOException $e) {
        return $default;
    }
}

function aiSaveSetting(string $key, ?string $value): void {
    global $pdo;
    if ($value === null) {
        $pdo->prepare("DELETE FROM site_settings WHERE setting_key = ?")->execute([$key]);
        return;
    }
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value]);
}

function aiApiKey(): string { return trim(aiSetting('xai_api_key')); }
function aiModel(): string  { return trim(aiSetting('xai_image_model', AI_DEFAULT_MODEL)) ?: AI_DEFAULT_MODEL; }

/** "xai-…3f9a" — enough to recognise a key without revealing it */
function aiMaskedKey(): string {
    $k = aiApiKey();
    return $k === '' ? '' : substr($k, 0, 4) . '…' . substr($k, -4);
}

// ── xAI calls ─────────────────────────────────────────────────

/** JSON request to api.x.ai; returns [httpCode, decodedBody] */
function xaiRequest(string $method, string $path, ?array $body = null, ?string $key = null, int $timeout = 150): array {
    $key = $key ?? aiApiKey();
    if ($key === '') throw new RuntimeException('No xAI API key saved yet. Paste it under Admin → AI Photos.');
    $ch = curl_init('https://api.x.ai' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES) : null,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Could not reach xAI: ' . $err);
    return [$code, json_decode((string)$raw, true) ?? ['raw' => substr((string)$raw, 0, 300)]];
}

/** Human-readable reason from an xAI error response */
function xaiErrorText(int $code, array $body): string {
    $msg = $body['error']['message'] ?? $body['error'] ?? $body['message'] ?? $body['raw'] ?? '';
    if (is_array($msg)) $msg = json_encode($msg);
    $hint = match (true) {
        $code === 401, $code === 403 => ' — check the API key.',
        $code === 429                => ' — rate limit or out of credits; try again shortly or top up at console.x.ai.',
        default                      => '',
    };
    return "xAI error $code" . ($msg !== '' ? ": $msg" : '') . $hint;
}

/** Checks a key works (lists models). Returns the image models it can use. */
function xaiTestKey(string $key): array {
    [$code, $body] = xaiRequest('GET', '/v1/models', null, $key, 20);
    if ($code !== 200) throw new RuntimeException(xaiErrorText($code, $body));
    $ids = array_column($body['data'] ?? [], 'id');
    return array_values(array_filter($ids, fn($id) => stripos($id, 'image') !== false || stripos($id, 'imagine') !== false));
}

/**
 * Edit one photo. $imageUrl must be publicly reachable (our ImageKit URLs are).
 * Returns ['bytes' => binary image, 'mime' => 'image/…'].
 */
function xaiEditImage(string $imageUrl, string $instruction, bool $guard = true): array {
    [$code, $body] = xaiRequest('POST', '/v1/images/edits', [
        'model'           => aiModel(),
        'prompt'          => ($guard ? AI_PROMPT_GUARD : '') . $instruction,
        'image'           => ['url' => $imageUrl, 'type' => 'image_url'],
        'response_format' => 'b64_json',
    ]);
    if ($code !== 200) throw new RuntimeException(xaiErrorText($code, $body));
    $item = $body['data'][0] ?? [];
    if (!empty($item['b64_json'])) {
        $bytes = base64_decode($item['b64_json'], true);
    } elseif (!empty($item['url'])) {
        $bytes = @file_get_contents($item['url']);
    } else {
        $bytes = false;
    }
    if (!$bytes) throw new RuntimeException('xAI returned no image. Try a different instruction.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/jpeg';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException("xAI returned an unexpected file type ($mime).");
    }
    return ['bytes' => $bytes, 'mime' => $mime];
}

// ── Unsaved edits (kept outside the web root until saved or expired) ──

function aiTmpDir(): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ahg_ai_edits';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $old) {
        if (filemtime($old) < time() - AI_TMP_TTL) @unlink($old);
    }
    return $dir;
}

/**
 * Stamp a wide banner across the bottom of an image ("AI PREVIEW - …").
 * Built-in GD font drawn small, then scaled up, so no font file is needed.
 */
function aiLabelImage(array $img, string $label): array {
    $src = @imagecreatefromstring($img['bytes']);
    if (!$src) throw new RuntimeException('Could not read the generated image to label it.');
    $w = imagesx($src); $h = imagesy($src);
    $font = 5; $tw = imagefontwidth($font) * strlen($label); $th = imagefontheight($font);
    $txt = imagecreatetruecolor($tw + 12, $th + 6);
    imagefill($txt, 0, 0, imagecolorallocate($txt, 200, 16, 46));
    imagestring($txt, $font, 6, 3, $label, imagecolorallocate($txt, 255, 255, 255));
    $bw = (int)($w * 0.96); $bh = (int)round($bw * imagesy($txt) / imagesx($txt));
    imagecopyresampled($src, $txt, (int)(($w - $bw) / 2), $h - $bh - (int)($h * 0.02), 0, 0, $bw, $bh, imagesx($txt), imagesy($txt));
    ob_start(); imagejpeg($src, null, 90); $bytes = ob_get_clean();
    imagedestroy($src); imagedestroy($txt);
    return ['bytes' => $bytes, 'mime' => 'image/jpeg'];
}

/** Store an edit; returns its token ("_p…" marks a repair preview: gallery only) */
function aiStoreTmp(array $img, int $auctionId, bool $preview = false): string {
    $ext   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$img['mime']];
    $token = $auctionId . '_' . ($preview ? 'p' : '') . bin2hex(random_bytes(12));
    file_put_contents(aiTmpDir() . DIRECTORY_SEPARATOR . "$token.$ext", $img['bytes']);
    return $token;
}

/** Path of a stored edit, or null (token format is checked, so no path tricks) */
function aiTmpPath(string $token): ?string {
    if (!preg_match('/^\d+_p?[a-f0-9]{24}$/', $token)) return null;
    foreach (['jpg', 'png', 'webp'] as $ext) {
        $p = aiTmpDir() . DIRECTORY_SEPARATOR . "$token.$ext";
        if (is_file($p)) return $p;
    }
    return null;
}

/** All photos of a lot: main first, then gallery — [['url','kind','gallery_id']] */
function aiLotPhotos(int $auctionId): array {
    global $pdo;
    $st = $pdo->prepare("SELECT image_url FROM auctions WHERE id = ?");
    $st->execute([$auctionId]);
    $main = $st->fetchColumn();
    $out = [];
    if ($main) $out[] = ['url' => imgHost($main), 'kind' => 'main', 'gallery_id' => null];
    $g = $pdo->prepare("SELECT id, file_path FROM auction_gallery WHERE auction_id = ? AND file_path <> '' ORDER BY is_primary DESC, sort_order, id");
    $g->execute([$auctionId]);
    foreach ($g->fetchAll() as $r) {
        $u = imgHost($r['file_path']);
        if ($main && $u === imgHost($main)) continue;
        $out[] = ['url' => $u, 'kind' => 'gallery', 'gallery_id' => (int)$r['id']];
    }
    return $out;
}

function aiIsPreviewToken(string $token): bool { return (bool)preg_match('/^\d+_p/', $token); }

/**
 * Upload an edited photo to ImageKit and attach it to a lot. 'main' replaces
 * the main photo (the old one moves into the gallery, so nothing is lost);
 * 'gallery' adds it at the end. Returns the uploaded URL.
 */
function aiSaveToLot(int $lotId, string $path, string $mode, string $tag): string {
    global $pdo;
    require_once __DIR__ . '/imagekit_helper.php';
    $st = $pdo->prepare("SELECT image_url, cloudinary_public_id FROM auctions WHERE id = ?");
    $st->execute([$lotId]);
    $lot = $st->fetch();
    if (!$lot) throw new RuntimeException('No lot with that ID.');
    $up = uploadToImageKit($path, IMAGEKIT_VEHICLE_FOLDER, 'lot-' . $lotId . '-' . $tag . '-' . date('YmdHis'));
    $next = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM auction_gallery WHERE auction_id = ?");
    $next->execute([$lotId]);
    $add = $pdo->prepare("INSERT INTO auction_gallery (auction_id, file_path, cloudinary_public_id, sort_order) VALUES (?,?,?,?)");
    if ($mode === 'main') {
        if ($lot['image_url']) $add->execute([$lotId, $lot['image_url'], $lot['cloudinary_public_id'], (int)$next->fetchColumn()]);
        $pdo->prepare("UPDATE auctions SET image_url = ?, cloudinary_public_id = ? WHERE id = ?")->execute([$up['url'], $up['file_id'], $lotId]);
    } else {
        $add->execute([$lotId, $up['url'], $up['file_id'], (int)$next->fetchColumn()]);
    }
    return $up['url'];
}
