<?php
// ============================================================
// AUCTION HUB GROUP — application_file.php
// Serves a document a client uploaded to their application, from the
// private uploads/applications/{id}/ folder (blocked from the web).
//   ?t=TOKEN&f=FILE    the client (their private link)
//   ?id=ID&f=FILE      the office (admin login)
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';

$app = finFind((string)($_GET['t'] ?? ''));
if (!$app && !empty($_GET['id'])) {
    $u = getPageUser();
    if (($u['role'] ?? '') === 'admin') $app = finGet((int)$_GET['id']);
}
$file = basename((string)($_GET['f'] ?? ''));
$known = null;
if ($app) {
    foreach ($app['details']['docs'] ?? [] as $doc) {
        foreach ($doc['files'] ?? [] as $f) if ($f['file'] === $file) $known = $f;
    }
}
$path = $known ? FIN_UPLOAD_DIR . (int)$app['id'] . '/' . $file : '';
if (!$known || !is_file($path)) { http_response_code(404); exit('File not found.'); }

header('Content-Type: ' . ($known['mime'] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '', $known['original'] ?: $file) . '"');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
