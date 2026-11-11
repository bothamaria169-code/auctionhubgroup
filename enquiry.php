<?php
// ============================================================
// AUCTION HUB GROUP — enquiry.php
// POST endpoint for the website enquiry forms (enquiry_form.php).
// Returns JSON {success, message}. Stored in `enquiries`, shown in
// Admin → Leads, office emailed, customer acknowledged.
// ============================================================
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email_helper.php';
require_once __DIR__ . '/tracking_helper.php';
require_once __DIR__ . '/leads_helper.php';

header('Content-Type: application/json');

function enquiryReply(bool $ok, string $message, int $code = 200): void {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['success' => $ok, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') enquiryReply(false, 'Please use the form.', 405);

// Same-site posts only (the form is on our own pages).
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url(APP_URL, PHP_URL_HOST)
    && !in_array(parse_url($origin, PHP_URL_HOST), ['auctionhubgroup.co.za', 'www.auctionhubgroup.co.za', 'localhost'], true)) {
    enquiryReply(false, 'Please use the form on our website.', 403);
}

// Honeypot: real people never fill the hidden "website" field. Pretend it worked.
if (trim($_POST['website'] ?? '') !== '') enquiryReply(true, 'Thank you! We will be in touch.');

// Bots post instantly; people take a few seconds.
$started = (int)($_POST['t'] ?? 0);
if ($started > 0 && time() - $started < 3) enquiryReply(false, 'That was quick! Please check your details and send again.');

if (enquiryRateLimited()) enquiryReply(false, 'You have sent several enquiries already — we will be in touch soon. For anything urgent call ' . COMPANY_PHONE . '.', 429);

if (!ahgSchemaReady()) enquiryReply(false, 'Sorry, we could not save your enquiry right now. Please call ' . COMPANY_PHONE . '.', 500);

try {
    [$ok, $msg] = saveEnquiry($_POST);
    enquiryReply($ok, $msg);
} catch (Throwable $e) {
    error_log('[AHG enquiry] ' . $e->getMessage());
    enquiryReply(false, 'Sorry, something went wrong. Please call ' . COMPANY_PHONE . ' or WhatsApp us.', 500);
}
