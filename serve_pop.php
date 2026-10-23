<?php
// ============================================================
// AUCTION HUB GROUP — serve_pop.php
// Secure proxy for proof-of-payment files (admin only)
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$user = requireAuth();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$file = $_GET['file'] ?? '';
if (!$file) {
    http_response_code(400);
    exit('Missing file parameter');
}

// Sanitise: only allow filenames, no directory traversal
$file = basename($file);
$path = UPLOAD_POP . $file;

if (!file_exists($path)) {
    http_response_code(404);
    exit('File not found');
}

$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
