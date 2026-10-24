<?php
// ============================================================
// AUCTION HUB GROUP — stock_photo.php
// Admin-only: serves a staged Stock Import photo (stored outside
// public_html until the draft is published).  stock_photo.php?d=ID&f=01.jpg
// ============================================================
require_once 'config.php';
require_once 'auth.php';
require_once 'stock_import_helper.php';

$user = getPageUser();
if (!$user || ($user['role'] ?? '') !== 'admin') { http_response_code(403); exit; }

$path = stockPhotoPath((int)($_GET['d'] ?? 0), (string)($_GET['f'] ?? ''));
if (!$path) { http_response_code(404); exit; }

header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
