<?php
// ============================================================
// AUCTION HUB GROUP — config.php
// Central configuration: DB, PHPMailer, JWT, session
// Include this file at the top of every PHP page/endpoint
// ============================================================

// ── Session ─────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.cookie_secure', $isHttps ? 1 : 0);
    session_name('pva_session');
    session_start();
}

// ── Environment detection ────────────────────────────────────
$_isLocalDev = str_contains($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost');

// ── Secrets ──────────────────────────────────────────────────
// Passwords and API keys live in config.secrets.php (git-ignored, never
// committed). Copy config.secrets.example.php to create it.
if (!is_file(__DIR__ . '/config.secrets.php')) {
    die('<h2 style="font-family:sans-serif;color:#c00;padding:2rem">
          Missing config.secrets.php. Copy config.secrets.example.php and fill it in.
         </h2>');
}
$_secrets = require __DIR__ . '/config.secrets.php';
$_dbSecrets = $_secrets[$_isLocalDev ? 'local' : 'production'];

// ── Database ─────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_NAME', $_dbSecrets['DB_NAME']);
define('DB_USER', $_dbSecrets['DB_USER']);
define('DB_PASS', $_dbSecrets['DB_PASS']);

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    DB_HOST, DB_NAME, DB_CHARSET
);

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);
} catch (PDOException $e) {
    // Return JSON error if called from API, HTML page otherwise
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        !empty($_GET['action']) || !empty($_POST['action'])) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
    }
    die('<h2 style="font-family:sans-serif;color:#c00;padding:2rem">
          Database connection failed. Please contact the administrator.
         </h2>');
}

// ── PHPMailer (cPanel shared hosting SMTP) ───────────────────
// Run: composer require phpmailer/phpmailer
// Or manually place PHPMailer in /vendor/phpmailer/phpmailer/src/
require_once __DIR__ . '/vendor/autoload.php';

define('MAIL_HOST',       'mail.auctionhubgroup.co.za');
define('MAIL_PORT',       465);
define('MAIL_ENCRYPTION', 'ssl');
define('MAIL_FROM_NAME',  'Auction Hub Group');
// Both environments use admin@ — the info@ password no longer
// authenticates (verified June 2026: SMTP rejects it), and cPanel
// requires the From address to match the authenticated mailbox.
define('MAIL_USERNAME',   'admin@auctionhubgroup.co.za');
define('MAIL_PASSWORD',   $_secrets['MAIL_PASSWORD']);
define('MAIL_FROM_EMAIL', 'admin@auctionhubgroup.co.za');
define('MAIL_ADMIN',      'admin@auctionhubgroup.co.za');

// Career-specific sender — replies from applicants go to this mailbox
define('CAREER_MAIL_FROM',  'careers@auctionhubgroup.co.za');
define('CAREER_MAIL_USER',  'careers@auctionhubgroup.co.za');
define('CAREER_MAIL_PASS',  $_secrets['CAREER_MAIL_PASS']);

// Set to false when SMTP is unavailable (e.g. local development).
// All email functions will return true silently without sending.
define('EMAIL_ENABLED', true);

// ── JWT ──────────────────────────────────────────────────────
// Generate a strong random secret: php -r "echo bin2hex(random_bytes(32));"
define('JWT_SECRET',  $_secrets['JWT_SECRET']);
define('JWT_EXPIRY',  86400 * 7);  // 7 days in seconds

// ── Application ──────────────────────────────────────────────
define('APP_NAME',    'Auction Hub Group');
// Auto-detect local vs production URL and protocol
if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false) {
    define('APP_URL', 'http://localhost/auctionhubgroup');
} else {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('APP_URL', $protocol . '://auctionhubgroup.co.za');
}    // No trailing slash

// CANONICAL_URL is the public-facing production domain.
// Use this in all outbound EMAIL content so that links in newsletters,
// notifications, and automated emails ALWAYS point to the live site —
// even when testing locally (where APP_URL = localhost/…).
define('CANONICAL_URL', 'https://auctionhubgroup.co.za');

define('APP_VERSION', '1.0.0');

// ── Google Analytics 4 ───────────────────────────────────────
// Create a GA4 property at https://analytics.google.com →
// Admin → Data Streams → Web → copy the Measurement ID (G-…)
// and paste it below. The tag only renders on production.
define('GA_MEASUREMENT_ID', 'G-STZ00ZTE9Y');

// ── Google Ads (paid search / performance max) ───────────────
// 1. Google Ads → Tools → Conversions → create the actions listed below.
// 2. The account-level tag ID looks like 'AW-123456789' — paste it here.
// 3. Each conversion action gives you a send_to label like 'AW-123456789/AbC-D_efG'.
//    Paste ONLY the part after the slash into the matching constant below.
// The tags render on production only, and only once GOOGLE_ADS_ID is real.
define('GOOGLE_ADS_ID', 'AW-3636429749');

// Primary conversion — a new bidder account is created (this is the one you bid to).
define('GADS_LABEL_REGISTRATION', 'XXXXXXXXXXXXXXXXXX');
// Secondary conversion — visitor taps the WhatsApp float / a WhatsApp CTA.
define('GADS_LABEL_WHATSAPP',     'XXXXXXXXXXXXXXXXXX');
// Secondary conversion — visitor taps a click-to-call number.
define('GADS_LABEL_CALL',         'XXXXXXXXXXXXXXXXXX');
// Value assigned to one bidder registration for Smart Bidding (in ZAR).
// This is NOT the R10,000 deposit — it is what an average signup is worth to you.
define('GADS_REGISTRATION_VALUE', 350);

// ── Google Tag Manager ───────────────────────────────────────
// Container ID from tagmanager.google.com. Installed once in header.php,
// which every public page includes, so it is live on all of them.
//
// IMPORTANT — do not measure the same thing twice. GA4 and Google Ads can be
// configured EITHER through the constants above (the tags render directly in
// header.php) OR as tags inside this GTM container, never both: two GA4
// configs on one page double every pageview, and two Ads conversion tags
// double every conversion. Pick one path:
//   Direct  — leave GA_MEASUREMENT_ID / GOOGLE_ADS_ID filled in and do NOT add
//             GA4 or Ads conversion tags in GTM. Simplest, already wired up.
//   Via GTM — blank GA_MEASUREMENT_ID and GOOGLE_ADS_ID here, then build those
//             tags in GTM off the dataLayer events the site pushes
//             (ahg_registration, ahg_contact_click). More flexible, more setup.
define('GTM_CONTAINER_ID', 'GTM-N5LK7BDP');

// ── Microsoft Clarity (free heatmaps + session replay) ───────
// https://clarity.microsoft.com — optional but it is the cheapest way to
// see why landing-page visitors are not completing the signup form.
define('CLARITY_PROJECT_ID', '');

// ── Company Details ──────────────────────────────────────────
define('COMPANY_NAME',            'Auction Hub Group');
define('COMPANY_REGISTERED_NAME', 'Auction Hub Auctioneers');
define('COMPANY_TRADING_AS',      'Auction Hub Group');
define('COMPANY_REG_NO',          'K2016224499');
define('COMPANY_SARS_NO',         '9982096167');
define('COMPANY_REG_DATE',        '2016/06/30');
define('COMPANY_TYPE',            'Private Company');
define('COMPANY_ADDRESS', '80 Sterling Rd, Kosmosdal, Centurion, 0187');
define('AUCTION_GROUND',  '80 Sterling Rd, Kosmosdal, Centurion, 0187');
define('COMPANY_EMAIL',   'info@auctionhubgroup.co.za');
define('COMPANY_PHONE',   '+27 10 516 0683');
define('COMPANY_PHONE2',  '+27 10 516 0681');

// ── WhatsApp ─────────────────────────────────────────────────
// COMPANY_WHATSAPP        — pretty display format (used in visible text)
// COMPANY_WHATSAPP_INTL   — digits only, international, no + or spaces (wa.me format)
define('COMPANY_WHATSAPP',      '+27 83 670 9292');
define('COMPANY_WHATSAPP_INTL', '27836709292');

// ── Bank Details (EFT Registration Fee) ─────────────────────
define('BANK_NAME',       'FNB');
define('BANK_ACCOUNT_NAME','Auction Hub Group');
define('BANK_ACCOUNT_NO', '62 0000 00000');   // Replace with real account number
define('BANK_BRANCH_CODE', '250 555');
define('BANK_ACCOUNT_TYPE','Cheque/Current');
define('REG_FEE_AMOUNT',   10000.00);
define('REG_FEE_LABEL',    'R10,000');

// ── Upload Paths ─────────────────────────────────────────────
define('UPLOAD_ROOT',          __DIR__ . '/uploads/');
define('UPLOAD_VEHICLES',      __DIR__ . '/uploads/vehicles/');
define('UPLOAD_POP',           __DIR__ . '/uploads/proof_of_payment/');
define('UPLOAD_CSV',           __DIR__ . '/uploads/csv_imports/');
define('UPLOAD_CAREER_DOCS',   __DIR__ . '/uploads/career_docs/');
define('UPLOAD_CAREER_PHOTOS', __DIR__ . '/uploads/career_photos/');
define('UPLOAD_CAREER_VIDEOS', __DIR__ . '/uploads/career_videos/');

// Create upload directories if they don't exist
foreach ([UPLOAD_ROOT, UPLOAD_VEHICLES, UPLOAD_POP, UPLOAD_CSV,
          UPLOAD_CAREER_DOCS, UPLOAD_CAREER_PHOTOS, UPLOAD_CAREER_VIDEOS] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ── Allowed File Types ────────────────────────────────────────
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']);
define('ALLOWED_POP_TYPES',   ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf']);
define('MAX_UPLOAD_SIZE',     10 * 1024 * 1024); // 10MB

// ── Bidder Number Prefix ──────────────────────────────────────
define('BIDDER_PREFIX', 'AHG-' . date('Y') . '-');  // e.g. AHG-2026-

// ── Cloudinary (Vehicle Image CDN) ───────────────────────────
// Sign up free at cloudinary.com — find these in your dashboard
// Free tier: 25 GB storage · 25 GB bandwidth/month
//
// To register:
//   1. Go to https://cloudinary.com → Sign Up Free
//   2. Dashboard shows: Cloud Name, API Key, API Secret
//   3. Replace the placeholder values below
//
define('CLOUDINARY_CLOUD_NAME', 'dkrfbur9f');           // e.g. 'pva-auctions'
define('CLOUDINARY_API_KEY',    $_secrets['CLOUDINARY_API_KEY']);
define('CLOUDINARY_API_SECRET', $_secrets['CLOUDINARY_API_SECRET']);
define('CLOUDINARY_BASE_URL',
    'https://res.cloudinary.com/' . CLOUDINARY_CLOUD_NAME . '/image/upload'
);

// ── Cloudinary delivery sizes ─────────────────────────────────
// Only three widths, reused everywhere. Every distinct width creates a new
// derived image (a billable transformation) the first time it is requested,
// so keeping the set small is what keeps the credit bill down.
define('CLD_THUMB', 240);    // gallery thumbnails, dashboard bid thumbs
define('CLD_CARD',  600);    // listing cards, strips, landing page, related lots
define('CLD_LARGE', 1200);   // vehicle detail main image and lightbox

// ── Cloudinary Folder Paths ───────────────────────────────────
// All vehicle images live under pva/vehicles/{year}/
// Proof-of-payment stays LOCAL (sensitive — never on public CDN)
define('CLOUDINARY_VEHICLE_FOLDER', 'pva/vehicles/' . date('Y'));  // e.g. pva/vehicles/2026
define('CLOUDINARY_POP_FOLDER',     'pva/proof-of-payment');       // fallback only

// ── ImageKit (image CDN — replaced Cloudinary) ───────────────
// Every new upload (vehicles, events, testimonials, team, news) goes here via
// imagekit_helper.php. Older photos were copied over from Cloudinary under
// identical paths (export_cloudinary_account.php → upload_to_imagekit.php),
// then the stored URLs are switched over (ahg_switch_images_to_imagekit.sql).
// The private upload key is never kept in this web-served file: it lives in
// ahg_secrets.php in the folder above the site (see imagekit_helper.php).
define('IMAGEKIT_URL_ENDPOINT', 'https://ik.imagekit.io/2f8m5mdk5');
define('IMAGEKIT_VEHICLE_FOLDER', 'pva/vehicles/' . date('Y'));  // same layout as the Cloudinary copies

// ── AI Article Generator (Groq) ──────────────────────────────
// Free LLM for automated SEO article drafts. Get a key at
// https://console.groq.com → API Keys (free tier).
// Used by cron_generate_article.php (run from cPanel cron).
//
// IMPORTANT: this lives in config.php — NOT a .env file — so the
// cron script needs no extra environment setup on cPanel.
define('GROQ_API_KEY', $_secrets['GROQ_API_KEY']);
define('GROQ_MODEL',   'llama-3.3-70b-versatile');   // 70B for long-form (not the 8B social model)

// Shared secret so the generator can also be triggered via browser
// (https://auctionhubgroup.co.za/cron_generate_article.php?key=...).
// CLI runs (cPanel cron) bypass this check.
define('AHG_CRON_KEY', $_secrets['AHG_CRON_KEY']);

// Auto-generate a featured image for each article. Stores the result
// permanently on ImageKit (configured above); if ImageKit fails, the raw
// image URL is kept as a fallback. Set to false to skip images entirely.
define('AHG_IMAGE_ENABLED', true);

// Image source. If PEXELS_API_KEY is set, real stock photos are used (free key
// at https://www.pexels.com/api/ — better for cars than AI). If left empty, it
// falls back to Pollinations.ai (free, no key, Flux AI model).
define('PEXELS_API_KEY', $_secrets['PEXELS_API_KEY']);

// ── Helper Functions ──────────────────────────────────────────

/**
 * Point a stored photo URL at the host that actually serves it.
 * The Cloudinary account (dkrfbur9f) is disabled and answers 401, but every
 * pva/… file was copied to ImageKit under the same path. Rows the switch SQL
 * hasn't rewritten yet are mapped here, so photos load either way:
 *   res.cloudinary.com/dkrfbur9f/image/upload/[transforms/][v123/]pva/x.jpg
 *     → <IMAGEKIT_URL_ENDPOINT>/pva/x.jpg
 * Cloudinary transforms are dropped; cldImg() adds the ImageKit equivalent.
 */
function imgHost(?string $url): string {
    $url = (string)$url;
    if (!defined('IMAGEKIT_URL_ENDPOINT') || strpos($url, 'res.cloudinary.com/' . CLOUDINARY_CLOUD_NAME . '/') === false) {
        return $url;
    }
    if (preg_match('#/image/upload/(?:[^/]*[_,][^/]*/)*(?:v\d+/)?(pva/.+)$#', $url, $m)) {
        return IMAGEKIT_URL_ENDPOINT . '/' . $m[1];
    }
    return $url;
}

/**
 * imgHost() applied to every string in a JSON payload (API responses).
 */
function imgHostDeep(array $data): array {
    array_walk_recursive($data, function (&$v) {
        if (is_string($v)) $v = imgHost($v);
    });
    return $data;
}

/**
 * Resized, auto-format, auto-quality delivery URL for a stored vehicle photo.
 * Works for both hosts, so the site keeps rendering correctly before, during and
 * after the Cloudinary → ImageKit database switch:
 *   Cloudinary → /image/upload/c_limit,w_N,f_auto,q_auto/<path>
 *   ImageKit   → <endpoint>/tr:w-N,c-at_max/<path>  (format/quality are automatic)
 * Anything else (local files, the placeholder, URLs already transformed) passes through.
 */
function cldImg(?string $url, int $width): string {
    $url = (string)$url;
    if ($url === '') return $url;
    $url = imgHost($url);

    if (defined('IMAGEKIT_URL_ENDPOINT') && strpos($url, IMAGEKIT_URL_ENDPOINT . '/') === 0) {
        $rest = substr($url, strlen(IMAGEKIT_URL_ENDPOINT) + 1);
        if (strpos($rest, 'tr:') === 0 || strpos($url, 'tr=') !== false) return $url;
        return IMAGEKIT_URL_ENDPOINT . '/tr:w-' . $width . ',c-at_max/' . $rest;
    }

    if (strpos($url, 'res.cloudinary.com/') === false) return $url;
    $marker = '/image/upload/';
    $pos = strpos($url, $marker);
    if ($pos === false) return $url;
    $rest = substr($url, $pos + strlen($marker));
    if (preg_match('#^[a-z]{1,3}_[^/]+/#', $rest)) return $url;
    return substr($url, 0, $pos) . $marker . 'c_limit,w_' . $width . ',f_auto,q_auto/' . $rest;
}

/**
 * Map condition_grade to DEKRA sticker name and colour
 * A = Platinum, B = Gold, C = Green, D = Green (requires work)
 */
function dekraSticker(?string $grade): array {
    $map = [
        'A' => ['name' => 'Platinum Sticker', 'color' => '#1565c0', 'icon' => 'fa-crown',     'bg' => '#e3f2fd'],
        'B' => ['name' => 'Gold Sticker',     'color' => '#f9a825', 'icon' => 'fa-award',      'bg' => '#fff8e1'],
        'C' => ['name' => 'Green Sticker',     'color' => '#4caf50', 'icon' => 'fa-circle-check','bg' => '#e8f5e9'],
        'D' => ['name' => 'Green Sticker',     'color' => '#4caf50', 'icon' => 'fa-circle-check','bg' => '#e8f5e9'],
    ];
    return $map[strtoupper($grade ?? '')] ?? ['name' => '', 'color' => '#888', 'icon' => 'fa-question', 'bg' => '#f5f5f5'];
}

/**
 * Generate an inline SVG of the official-style DEKRA circular badge.
 * A = Platinum (silver ring, "ready to go"), B = Gold, C/D = Green ("inspected").
 */
function dekraBadgeSVG(?string $grade, int $size = 140): string {
    $grade = strtoupper($grade ?? '');
    $cfgs = [
        'A' => ['ring'=>'#8a8a8a', 'label'=>'PLATINUM', 'bottom'=>'ready to go',  'sub1'=>'Roadworthy Certificate', 'sub2'=>'Used Car Report', 'check'=>true ],
        'B' => ['ring'=>'#c8860a', 'label'=>'GOLD',     'bottom'=>'inspected',    'sub1'=>'Used Car Report',         'sub2'=>'',               'check'=>false],
        'C' => ['ring'=>'#2a7d2a', 'label'=>'GREEN',    'bottom'=>'inspected',    'sub1'=>'Used Car Report',         'sub2'=>'',               'check'=>false],
        'D' => ['ring'=>'#2a7d2a', 'label'=>'GREEN',    'bottom'=>'inspected',    'sub1'=>'Used Car Report',         'sub2'=>'',               'check'=>false],
    ];
    if (!isset($cfgs[$grade])) return '';
    $c   = $cfgs[$grade];
    $uid = 'dkr_' . $grade . '_' . $size;
    $ring   = htmlspecialchars($c['ring'], ENT_QUOTES);
    $label  = htmlspecialchars($c['label'], ENT_QUOTES);
    $bottom = htmlspecialchars($c['bottom'], ENT_QUOTES);
    $sub1   = htmlspecialchars($c['sub1'], ENT_QUOTES);
    $sub2   = htmlspecialchars($c['sub2'], ENT_QUOTES);

    // Vertical layout inside inner circle (r=74, top≈26, bottom≈174)
    $check  = $c['check']
        ? '<text x="100" y="97" font-family="Arial,sans-serif" font-size="11" fill="#2a7d2a" text-anchor="middle" font-weight="bold">&#10003;</text>'
        : '';
    $sub2el = $c['sub2']
        ? '<text x="100" y="124" font-family="Arial,sans-serif" font-size="8" fill="#444" text-anchor="middle">' . $sub2 . '</text>'
        : '';
    $sub1y  = $c['check'] ? 111 : ($c['sub2'] ? 113 : 117);

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
     viewBox="0 0 200 200" width="{$size}" height="{$size}"
     role="img" aria-label="DEKRA {$label} Sticker">
  <!-- outer coloured ring -->
  <circle cx="100" cy="100" r="96" fill="{$ring}"/>
  <!-- inner white disc -->
  <circle cx="100" cy="100" r="74" fill="white"/>
  <circle cx="100" cy="100" r="74" fill="none" stroke="{$ring}" stroke-width="1.5"/>
  <!-- arc paths for curved ring text -->
  <defs>
    <path id="{$uid}T" d="M 18,100 A 82,82 0 0 1 182,100"/>
    <path id="{$uid}B" d="M 18,100 A 82,82 0 0 0 182,100"/>
  </defs>
  <!-- grade label (top, curved) -->
  <text font-family="Arial Black,Arial,sans-serif" font-weight="900" font-size="15" fill="white" letter-spacing="2" text-anchor="middle">
    <textPath href="#{$uid}T" xlink:href="#{$uid}T" startOffset="50%">{$label}</textPath>
  </text>
  <!-- status text (bottom, curved italic) -->
  <text font-family="Arial,sans-serif" font-size="11" fill="white" font-style="italic" text-anchor="middle" letter-spacing="1">
    <textPath href="#{$uid}B" xlink:href="#{$uid}B" startOffset="50%">{$bottom}</textPath>
  </text>
  <!-- DEKRA chevron / play icon -->
  <polygon points="62,68 62,92 80,80" fill="#2a7d2a"/>
  <!-- DEKRA wordmark -->
  <text x="103" y="86" font-family="Arial Black,Arial,sans-serif" font-weight="900" font-size="23" fill="#2a7d2a" text-anchor="middle" letter-spacing="0.5">DEKRA</text>
  <!-- checkmark (Platinum only) -->
  {$check}
  <!-- sub-line 1 -->
  <text x="100" y="{$sub1y}" font-family="Arial,sans-serif" font-size="8.5" fill="#444" text-anchor="middle">{$sub1}</text>
  {$sub2el}
</svg>
SVG;
}

/**
 * Sanitize output to prevent XSS — PHP 8.4 compatible (accepts null)
 */
function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Safely format a nullable datetime string — PHP 8.4 compatible.
 * Replaces date('fmt', strtotime($maybeNull)) patterns throughout the codebase.
 *
 * @param  string      $format  date() format string
 * @param  string|null $dateStr DB datetime value (may be null or '')
 * @param  string      $default Return value when $dateStr is empty or invalid
 * @return string
 */
function safeDate(string $format, ?string $dateStr, string $default = '—'): string {
    if (empty($dateStr)) return $default;
    $ts = strtotime($dateStr);
    return ($ts !== false && $ts > 0) ? date($format, $ts) : $default;
}

/**
 * Get bank/payment settings from DB (cached per request)
 */
function getBankSettings(): array {
    global $pdo;
    static $cache = null;
    if ($cache !== null) return $cache;

    $settings = [];
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll();
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Exception $e) {
        // site_settings table may not exist yet — fall back to config constants
        error_log('[AHG] getBankSettings: ' . $e->getMessage());
    }

    $cache = [
        'bank_name'         => $settings['bank_name']         ?? BANK_NAME,
        'bank_account_name' => $settings['bank_account_name'] ?? BANK_ACCOUNT_NAME,
        'bank_account_no'   => $settings['bank_account_no']   ?? BANK_ACCOUNT_NO,
        'bank_branch_code'  => $settings['bank_branch_code']  ?? BANK_BRANCH_CODE,
        'bank_account_type' => $settings['bank_account_type'] ?? BANK_ACCOUNT_TYPE,
        'reg_fee_amount'    => $settings['reg_fee_amount']    ?? REG_FEE_AMOUNT,
        'reg_fee_label'     => $settings['reg_fee_label']     ?? REG_FEE_LABEL,
    ];
    return $cache;
}

/**
 * Format rand amounts: R10,000.00
 */
function formatRand(?float $amount): string {
    if ($amount === null) return 'R0.00';
    return 'R' . number_format($amount, 2);
}

/**
 * Generate EFT reference for a user: AHG-18-REG
 */
function eftReference(int $userId): string {
    return 'AHG-' . $userId . '-REG';
}

/**
 * Check if currently logged-in user is admin
 */
function isAdmin(): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Check if currently logged-in user is a verified bidder
 */
function isVerifiedBidder(): bool {
    return isset($_SESSION['bidder_status']) && 
           $_SESSION['bidder_status'] === 'verified_bidder';
}

/**
 * Redirect with a flash message stored in session
 */
function redirect(string $url, string $message = '', string $type = 'info'): void {
    if ($message) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type']    = $type;
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Return and clear any flash message
 */
function getFlash(): array {
    $msg  = $_SESSION['flash_message'] ?? '';
    $type = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
    return ['message' => $msg, 'type' => $type];
}

/**
 * Generate a secure unique filename for uploads
 * Returns: 'prefix_timestamp_randomhex.ext'
 */
function generateUploadFilename(string $originalName, string $prefix = 'file'): string {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return $prefix . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
}

/**
 * Validate and move an uploaded file
 * Returns: ['success' => bool, 'path' => string, 'message' => string]
 */
function handleUpload(array $file, string $destination, array $allowedTypes, string $prefix = 'upload'): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'path' => '', 'message' => 'Upload error code: ' . $file['error']];
    }

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['success' => false, 'path' => '', 'message' => 'File too large. Maximum size is 10MB.'];
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'path' => '', 'message' => 'Invalid file type: ' . $mimeType];
    }

    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }

    $filename = generateUploadFilename($file['name'], $prefix);
    $fullPath = $destination . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        return ['success' => false, 'path' => '', 'message' => 'Failed to save file.'];
    }

    // Return a web-relative path (strip __DIR__ prefix)
    $relativePath = 'uploads/' . basename($destination) . '/' . $filename;

    return ['success' => true, 'path' => $relativePath, 'message' => 'Upload successful.'];
}
