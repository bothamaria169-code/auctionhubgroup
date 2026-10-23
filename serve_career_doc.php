<?php
// ============================================================
// AUCTION HUB GROUP — serve_career_doc.php
// Secure proxy for career application documents (admin only).
// Direct HTTP access to uploads/career_docs/ etc. is blocked
// by .htaccess — all viewing goes through this script.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$user = requireAuth();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$file = trim($_GET['file'] ?? '');
if (!$file) {
    http_response_code(400);
    exit('Missing file parameter');
}

// Whitelist: only career upload subfolders, filename characters only (no traversal)
if (!preg_match('#^uploads/(career_docs|career_photos|career_videos)/[A-Za-z0-9_\-\.]+$#', $file)) {
    http_response_code(400);
    exit('Invalid file path');
}

$fullPath = __DIR__ . '/' . $file;

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File not found — it may not have been uploaded to this server yet.');
}

$mime = mime_content_type($fullPath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;
