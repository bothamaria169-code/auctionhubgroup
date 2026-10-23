<?php
// ============================================================
// AUCTION HUB GROUP — imagekit_helper.php
// Upload and delete site images on ImageKit, which replaced
// Cloudinary as the image store in September 2026.
//
// Usage:
//   require_once 'imagekit_helper.php';
//
//   $up = uploadToImageKit($_FILES['photo']['tmp_name'], 'pva/team', 'team-jane-doe');
//   // $up['url']     → stored in the table's image URL column
//   // $up['file_id'] → stored in its cloudinary_public_id column (the column
//   //                  keeps its old name) so the file can be deleted later
//
//   deleteStoredImage($row['photo_url'], $row['cloudinary_public_id']);
//
// The private API key is never kept in the site files. It is read from the
// IMAGEKIT_PRIVATE_KEY environment variable or, failing that, from
// ahg_secrets.php in the folder ABOVE the site (outside public_html on
// cPanel, C:\xampp\htdocs locally), which contains:
//
//   <?php return ['IMAGEKIT_PRIVATE_KEY' => 'private_xxxxxxxxxxxxxxxx'];
// ============================================================

require_once __DIR__ . '/config.php';

// ── Credentials ───────────────────────────────────────────────

/**
 * The ImageKit private API key, or '' when none is configured.
 */
function imagekitPrivateKey(): string
{
    static $key = null;

    if ($key === null) {
        $key  = (string)getenv('IMAGEKIT_PRIVATE_KEY');
        $file = dirname(__DIR__) . '/ahg_secrets.php';
        if ($key === '' && is_file($file)) {
            $secrets = include $file;
            $key = is_array($secrets) ? (string)($secrets['IMAGEKIT_PRIVATE_KEY'] ?? '') : '';
        }
    }

    return $key;
}

// ── Upload ────────────────────────────────────────────────────

/**
 * Upload a local file or remote image URL to ImageKit.
 *
 * ImageKit adds a short random suffix to every file name
 * (lot-001-toyota-2022-main_Ab12Cd34.jpg), so an upload can never replace
 * another lot's photo that happens to have the same name.
 *
 * @param  string $source  Local file path OR http(s) URL
 * @param  string $folder  e.g. 'pva/vehicles/2026'
 * @param  string $name    File name without extension (slugged here); the
 *                         extension comes from the image's real type
 * @return array{url: string, file_id: string, file_path: string, width: int, height: int}
 * @throws RuntimeException on any failure
 */
function uploadToImageKit(string $source, string $folder, string $name): array
{
    $key = imagekitPrivateKey();
    if ($key === '') {
        throw new RuntimeException('ImageKit private key not found. Add it to ahg_secrets.php in the folder above the site.');
    }

    // Remote images are downloaded here rather than handed to ImageKit as a
    // URL, so slow sources (AI image generators can take 20+ seconds) run on
    // our own timeout.
    $download = preg_match('#^https?://#i', $source) ? imagekitDownload($source) : null;
    $local    = $download ?? $source;

    try {
        if (!is_file($local)) {
            throw new RuntimeException('Image file not found.');
        }
        $mime = (string)mime_content_type($local);
        $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? '';
        if ($ext === '') {
            throw new RuntimeException("Not a supported image type: $mime");
        }

        $slug     = rtrim(substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'), 0, 100), '-');
        $fileName = ($slug !== '' ? $slug : 'image') . '.' . $ext;

        $ch = curl_init('https://upload.imagekit.io/api/v1/files/upload');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $key . ':',
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_POSTFIELDS     => [
                'file'              => new CURLFile($local, $mime, $fileName),
                'fileName'          => $fileName,
                'folder'            => '/' . trim($folder, '/'),
                'useUniqueFileName' => 'true',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
    } finally {
        if ($download !== null) @unlink($download);
    }

    $data = json_decode((string)$body, true);
    if ($code !== 200 || empty($data['fileId']) || empty($data['filePath'])) {
        $why = $data['message'] ?? ($err ?: substr((string)$body, 0, 200));
        throw new RuntimeException("ImageKit upload failed (HTTP $code): $why");
    }

    // Built from IMAGEKIT_URL_ENDPOINT so cldImg() recognises the URL and resizes it
    $path = '/' . ltrim($data['filePath'], '/');
    return [
        'url'       => IMAGEKIT_URL_ENDPOINT . implode('/', array_map('rawurlencode', explode('/', $path))),
        'file_id'   => (string)$data['fileId'],
        'file_path' => $path,
        'width'     => (int)($data['width'] ?? 0),
        'height'    => (int)($data['height'] ?? 0),
    ];
}

/**
 * Download a remote image to a temp file and return its path.
 *
 * @throws RuntimeException when the download fails
 */
function imagekitDownload(string $url): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ahg_img_');
    $fh  = fopen($tmp, 'wb');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_USERAGENT      => 'AuctionHubGroup/1.0 (+https://auctionhubgroup.co.za)',
    ]);
    $ok  = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if (!$ok || filesize($tmp) === 0) {
        @unlink($tmp);
        throw new RuntimeException('Could not download the source image: ' . ($err ?: 'empty response'));
    }
    return $tmp;
}

// ── URLs ──────────────────────────────────────────────────────

/**
 * Add an ImageKit transformation to a stored URL, e.g. 'w-800,h-600' for an
 * 800×600 crop or 'w-600,h-600,fo-face' for a face-centred square.
 * Non-ImageKit URLs are returned unchanged.
 */
function imagekitTransformUrl(string $url, string $transform): string
{
    $prefix = IMAGEKIT_URL_ENDPOINT . '/';
    if (strpos($url, $prefix) !== 0) return $url;
    return $prefix . 'tr:' . $transform . '/' . substr($url, strlen($prefix));
}

/**
 * The file path inside ImageKit ('/pva/vehicles/2026/x.jpg') that a stored
 * URL points at, ignoring any transformation or query string.
 * Returns null for URLs on any other host.
 */
function imagekitPathFromUrl(string $url): ?string
{
    $prefix = IMAGEKIT_URL_ENDPOINT . '/';
    if (strpos($url, $prefix) !== 0) return null;

    $path = explode('?', substr($url, strlen($prefix)), 2)[0];
    $path = preg_replace('#^tr:[^/]*/#', '', $path);
    return '/' . rawurldecode($path);
}

// ── Delete ────────────────────────────────────────────────────

/**
 * Delete a stored image wherever it lives: ImageKit, Cloudinary (rows not
 * switched over yet) or a local fallback copy under uploads/.
 *
 * @param  string|null $url       The stored URL or relative path
 * @param  string|null $storedId  The row's cloudinary_public_id value
 * @return bool  true when the file was deleted
 */
function deleteStoredImage(?string $url, ?string $storedId = null): bool
{
    $url      = (string)$url;
    $storedId = (string)$storedId;
    if ($url === '') return false;

    if (strpos($url, IMAGEKIT_URL_ENDPOINT . '/') === 0) {
        return deleteFromImageKit($url, $storedId);
    }

    if (strpos($url, 'res.cloudinary.com/') !== false) {
        if ($storedId === '') return false;
        require_once __DIR__ . '/cloudinary_helper.php';
        return deleteFromCloudinary($storedId);
    }

    // Local copy, stored either as a relative path or as a full APP_URL link
    $rel = ltrim(str_replace(APP_URL, '', $url), '/');
    if (strpos($rel, 'uploads/') !== 0 || strpos($rel, '..') !== false) return false;
    return is_file(__DIR__ . '/' . $rel) && @unlink(__DIR__ . '/' . $rel);
}

/**
 * Delete a file from ImageKit.
 *
 * New uploads store ImageKit's fileId, which deletes directly. Photos copied
 * over from Cloudinary still carry their Cloudinary public_id (always a path
 * containing "/"), so those are looked up by their URL instead.
 *
 * @param  string $url     The stored ImageKit URL
 * @param  string $fileId  The stored id, if any
 * @return bool  true when ImageKit confirmed the delete
 */
function deleteFromImageKit(string $url, string $fileId = ''): bool
{
    if (imagekitPrivateKey() === '') {
        error_log('[AHG ImageKit] delete skipped, no private key configured: ' . $url);
        return false;
    }

    if ($fileId === '' || strpos($fileId, '/') !== false) {
        $fileId = imagekitFileIdForUrl($url) ?? '';
    }
    if ($fileId === '') {
        error_log('[AHG ImageKit] delete skipped, file not found: ' . $url);
        return false;
    }

    [$code] = imagekitApi('DELETE', 'https://api.imagekit.io/v1/files/' . rawurlencode($fileId));
    if ($code !== 204) {
        error_log("[AHG ImageKit] delete of $fileId returned HTTP $code");
    }
    return $code === 204;
}

/**
 * Look up the fileId of the ImageKit file a stored URL points at.
 */
function imagekitFileIdForUrl(string $url): ?string
{
    $path = imagekitPathFromUrl($url);
    if ($path === null) return null;

    // Exact-name search inside the file's own folder (ImageKit searches one level only)
    $cut = strrpos($path, '/');
    [$code, $files] = imagekitApi('GET', 'https://api.imagekit.io/v1/files?' . http_build_query([
        'path'        => substr($path, 0, $cut + 1),
        'searchQuery' => 'name = "' . substr($path, $cut + 1) . '"',
    ], '', '&', PHP_QUERY_RFC3986));
    if ($code !== 200 || !is_array($files)) return null;

    foreach ($files as $f) {
        if (($f['filePath'] ?? '') === $path && !empty($f['fileId'])) {
            return (string)$f['fileId'];
        }
    }
    return null;
}

/**
 * Call the ImageKit management API. Returns [HTTP status, decoded JSON body].
 */
function imagekitApi(string $method, string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => imagekitPrivateKey() . ':',
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, json_decode((string)$body, true)];
}
