<?php
// ============================================================
// AUCTION HUB GROUP — tracking_helper.php
// Ad attribution + conversion tracking helpers.
//
// Responsibilities:
//   1. Capture Google Ads click IDs (gclid/gbraid/wbraid) and UTM
//      parameters on the FIRST landing hit and keep them in the
//      session, so a signup that happens three pages later can
//      still be attributed to the keyword that paid for it.
//   2. Decide whether tracking tags should render at all
//      (production + real IDs only — never on localhost).
//   3. Build WhatsApp deep links in one place.
//
// Included by header.php, so every public page gets this for free.
// ============================================================

if (!defined('AHG_TRACKING_HELPER')) {
define('AHG_TRACKING_HELPER', true);

// ── Should analytics/ads tags render? ─────────────────────────
function isProductionHost(): bool {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return !str_contains($host, 'localhost') && !str_contains($host, '127.0.0.1');
}

function ga4Enabled(): bool {
    return isProductionHost()
        && defined('GA_MEASUREMENT_ID')
        && GA_MEASUREMENT_ID !== ''
        && !str_contains(GA_MEASUREMENT_ID, 'XXXX');
}

function googleAdsEnabled(): bool {
    return isProductionHost()
        && defined('GOOGLE_ADS_ID')
        && GOOGLE_ADS_ID !== ''
        && !str_contains(GOOGLE_ADS_ID, 'XXXX');
}

/**
 * Google Tag Manager loads on production, and also on localhost when GTM's
 * own Preview mode is driving the page (it appends gtm_debug), so the
 * container can be tested without polluting live data the rest of the time.
 */
function gtmEnabled(): bool {
    if (!defined('GTM_CONTAINER_ID') || GTM_CONTAINER_ID === '') return false;
    if (str_contains(GTM_CONTAINER_ID, 'XXXX'))                  return false;
    return isProductionHost() || isset($_GET['gtm_debug']);
}

function clarityEnabled(): bool {
    return isProductionHost() && defined('CLARITY_PROJECT_ID') && CLARITY_PROJECT_ID !== '';
}

/**
 * Full send_to value for a conversion action, e.g. 'AW-123456789/AbC-D_efG'.
 * Returns '' when the ID or the label is still a placeholder, which lets
 * callers skip rendering the conversion snippet entirely.
 */
function gadsSendTo(string $labelConstant): string {
    if (!googleAdsEnabled()) return '';
    if (!defined($labelConstant)) return '';
    $label = constant($labelConstant);
    if ($label === '' || str_contains($label, 'XXXX')) return '';
    return GOOGLE_ADS_ID . '/' . $label;
}

// ── Attribution capture ───────────────────────────────────────
function attributionKeys(): array {
    return [
        'gclid', 'gbraid', 'wbraid', 'msclkid', 'fbclid',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    ];
}

/**
 * Store ad-click parameters on first touch. First touch wins — a bidder
 * who clicks an ad, leaves, and later returns organically is still
 * credited to the ad that originally found them (within the session).
 */
function captureAttribution(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;

    $found = [];
    foreach (attributionKeys() as $k) {
        if (!empty($_GET[$k])) {
            $found[$k] = substr(trim((string)$_GET[$k]), 0, 255);
        }
    }
    if (!$found) return;

    // Only overwrite when this hit carries a real paid click ID.
    $isPaidClick = isset($found['gclid']) || isset($found['gbraid'])
                || isset($found['wbraid']) || isset($found['msclkid']);

    if ($isPaidClick || empty($_SESSION['ahg_attribution'])) {
        $_SESSION['ahg_attribution'] = $found + [
            'landing_page' => substr($_SERVER['REQUEST_URI'] ?? '/', 0, 255),
            'referrer'     => substr($_SERVER['HTTP_REFERER'] ?? '', 0, 255),
            'captured_at'  => date('Y-m-d H:i:s'),
        ];
    }
}

/** The stored attribution array, or an empty array. */
function attributionSnapshot(): array {
    return $_SESSION['ahg_attribution'] ?? [];
}

/** Compact single-column representation for storing against a user row. */
function attributionJson(): string {
    $a = attributionSnapshot();
    return $a ? (json_encode($a, JSON_UNESCAPED_SLASHES) ?: '') : '';
}

/** Best-guess traffic source label, for at-a-glance admin reporting. */
function attributionSourceLabel(): string {
    $a = attributionSnapshot();
    if (!$a) return 'direct/organic';
    if (!empty($a['gclid']) || !empty($a['gbraid']) || !empty($a['wbraid'])) return 'google-ads';
    if (!empty($a['msclkid'])) return 'microsoft-ads';
    if (!empty($a['fbclid'])) return 'facebook';
    if (!empty($a['utm_source'])) {
        return substr($a['utm_source'] . '/' . ($a['utm_medium'] ?? 'unknown'), 0, 64);
    }
    return 'direct/organic';
}

/**
 * The dataLayer object pushed before GTM loads. Everything GTM needs to build
 * triggers, audiences and conversion conditions lives here, so adding a tag
 * later is a change in the GTM UI rather than another PHP deploy.
 */
function dataLayerContext(?array $currentUser, string $currentPage): array {
    $attr = attributionSnapshot();
    return array_filter([
        'page_type'     => str_replace('.php', '', $currentPage) ?: 'index',
        'logged_in'     => $currentUser ? 'yes' : 'no',
        'bidder_status' => $currentUser['bidder_status'] ?? 'guest',
        'traffic_source' => attributionSourceLabel(),
        'campaign'      => $attr['utm_campaign'] ?? null,
        'keyword'       => $attr['utm_term'] ?? null,
    ], fn($v) => $v !== null && $v !== '');
}

// ── WhatsApp links ────────────────────────────────────────────
/**
 * Build a wa.me deep link with a pre-filled message.
 * Falls back gracefully if the constants were never defined.
 */
function waLink(string $message = ''): string {
    $number = defined('COMPANY_WHATSAPP_INTL') ? COMPANY_WHATSAPP_INTL : '';
    if ($number === '') return '#';
    $url = 'https://wa.me/' . $number;
    if ($message !== '') $url .= '?text=' . rawurlencode($message);
    return $url;
}

/** Human-readable WhatsApp number for on-page display. */
function waDisplay(): string {
    return defined('COMPANY_WHATSAPP') ? COMPANY_WHATSAPP : '';
}

} // AHG_TRACKING_HELPER
