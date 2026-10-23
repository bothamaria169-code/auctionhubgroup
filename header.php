<?php
// ============================================================
// AUCTION HUB GROUP — header.php
// Universal page header — include at top of every PHP page
//
// Usage:
//   $pageTitle       = 'Browse Auctions';       // required
//   $pageDescription = 'Find your next vehicle'; // optional
//   $bodyClass       = 'page-auctions';          // optional
//   require_once 'header.php';
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tracking_helper.php';

// Record the Google Ads click ID / UTM tags that brought this visitor in,
// before anything else runs. Needed so a signup can be traced back to the
// keyword that paid for it. No-op for organic traffic.
captureAttribution();

// ── Resolve current user from JWT ─────────────────────────────
$token       = getTokenFromRequest();
$currentUser = null;

if ($token) {
    $payload = verifyJWT($token);
    if ($payload) {
        $stmt = $pdo->prepare("
            SELECT bidder_status, bidder_number, status, full_name
            FROM users WHERE id = ?
        ");
        $stmt->execute([$payload['sub']]);
        $dbUser = $stmt->fetch();

        if ($dbUser && $dbUser['status'] !== 'suspended') {
            $currentUser = array_merge($payload, [
                'bidder_status' => $dbUser['bidder_status'],
                'bidder_number' => $dbUser['bidder_number'],
                'full_name'     => $dbUser['full_name'],
            ]);
            syncSession($currentUser);
        } else {
            clearTokenCookie();
        }
    }
}

// ── Flash message ─────────────────────────────────────────────
$flash = getFlash();

// ── Worker Portal session (Sales Reps) ────────────────────────
// Separate auth from the bidder JWT above. worker_portal.php stores
// these keys on the SAME pva_session, so any page can tell whether a
// Sales Rep is signed into the portal and surface a link to it.
$workerPortal = null;
if (!empty($_SESSION['ahg_worker_id'])) {
    $wpFullName = trim($_SESSION['ahg_worker_name'] ?? '');
    $workerPortal = [
        'first' => $wpFullName !== '' ? explode(' ', $wpFullName)[0] : 'Sales Rep',
        'code'  => $_SESSION['ahg_worker_code'] ?? '',
    ];
}

// ── Active nav detection ──────────────────────────────────────
$currentPage = basename($_SERVER['PHP_SELF']);

function navActive(string $page): string {
    global $currentPage;
    return ($currentPage === $page) ? ' active' : '';
}

// ── Is current page under About dropdown? ─────────────────────
function isAboutPage(): bool {
    global $currentPage;
    return in_array($currentPage, ['about.php', 'careers.php', 'testimonials.php', 'faq.php', 'team.php']);
}

// ── Announcement ticker messages ──────────────────────────────
$tickerMessages = [];
try {
    $tickerStmt = $pdo->query("
        SELECT message FROM news_announcements
        WHERE is_active = 1
        ORDER BY sort_order ASC, created_at DESC
        LIMIT 10
    ");
    $tickerMessages = $tickerStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Silently fail — ticker is non-critical
}

if (empty($tickerMessages)) {
    $tickerMessages = [
        'Trusted by banks, respected by buyers · Auction Hub Group',
        'New vehicles added weekly — Register today to start bidding',
        'Bank repossessions and estate vehicles at unbeatable prices',
        'All Vehicles are Dekra Certified · SAIA · NAA · JCCI · IEASA members',
        'Nationwide delivery available — Call us on ' . COMPANY_PHONE,
        'In-House Finance available — No credit checks, blacklisted welcome',
    ];
}

// ── SEO defaults ──────────────────────────────────────────────
$pageTitle       = isset($pageTitle)       ? e($pageTitle) . ' — ' . APP_NAME : APP_NAME;
$pageDescription = isset($pageDescription) ? e($pageDescription) : 'Auction Hub Group — Repossessed and bank auction vehicles. Register to bid on quality cars across South Africa.';
$bodyClass       = $bodyClass ?? '';

// Pages may set these before including header.php:
//   $canonicalUrl — full canonical URL (needed when the page uses ?id= / ?slug=)
//   $pageImage    — full URL of a page-specific share image (og:image)
$canonicalUrl = $canonicalUrl
    ?? CANONICAL_URL . ($currentPage === 'index.php' ? '/' : '/' . $currentPage);
$pageImage    = $pageImage ?? APP_URL . '/assets/og-image.jpg';

// ── Bidder status dot helper ──────────────────────────────────
function bidderStatusDot(string $status): string {
    $map = [
        'registered'        => ['colour' => '#6c757d', 'title' => 'Not yet registered to bid'],
        'pending_payment'   => ['colour' => '#f5a623', 'title' => 'Awaiting your EFT payment'],
        'pending_approval'  => ['colour' => '#00AEEF', 'title' => 'Payment under review'],
        'verified_bidder'   => ['colour' => '#28a745', 'title' => 'Verified — ready to bid!'],
        'suspended'         => ['colour' => '#dc3545', 'title' => 'Account suspended'],
    ];
    $s = $map[$status] ?? $map['registered'];
    return sprintf(
        '<span class="bidder-dot" style="background:%s" title="%s"></span>',
        $s['colour'], e($s['title'])
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php if (gtmEnabled()): ?>
    <?php
    // dataLayer is seeded BEFORE the container loads, so the very first
    // Container Loaded event already knows the page type, whether the visitor
    // is signed in, their bidder status and which campaign brought them.
    // Triggers built on those variables then work on the first pageview
    // rather than one event late.
    $dlContext = dataLayerContext($currentUser, $currentPage);
    ?>
    <script>window.dataLayer = window.dataLayer || [];
    dataLayer.push(<?= json_encode($dlContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);</script>
    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','<?= e(GTM_CONTAINER_ID) ?>');</script>
    <!-- End Google Tag Manager -->
    <?php endif; ?>
    <meta name="description" content="<?= $pageDescription ?>">
    <?php if (!empty($noIndex)): // set $noIndex = true before including header.php ?>
    <meta name="robots" content="noindex,nofollow">
    <?php endif; ?>
    <meta name="theme-color" content="#005BA1">

    <!-- Canonical -->
    <link rel="canonical" href="<?= e($canonicalUrl) ?>">

    <!-- Open Graph -->
    <meta property="og:title"       content="<?= $pageTitle ?>">
    <meta property="og:description" content="<?= $pageDescription ?>">
    <meta property="og:type"        content="website">
    <meta property="og:url"         content="<?= e($canonicalUrl) ?>">
    <meta property="og:image"       content="<?= e($pageImage) ?>">
    <meta property="og:site_name"   content="<?= APP_NAME ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="<?= $pageTitle ?>">
    <meta name="twitter:description" content="<?= $pageDescription ?>">
    <meta name="twitter:image"       content="<?= e($pageImage) ?>">

    <title><?= $pageTitle ?></title>

    <?php
    // ── Google tag (gtag.js) — GA4 + Google Ads on one loader ─────────
    // Renders on production only, and only once a real ID is configured.
    // One script tag serves both properties; each gets its own config line.
    $ga4On  = ga4Enabled();
    $adsOn  = googleAdsEnabled();
    $tagId  = $ga4On ? GA_MEASUREMENT_ID : ($adsOn ? GOOGLE_ADS_ID : '');
    if ($tagId !== ''): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($tagId) ?>"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      <?php if ($ga4On): ?>
      gtag('config', '<?= e(GA_MEASUREMENT_ID) ?>');
      <?php endif; ?>
      <?php if ($adsOn): ?>
      /* allow_enhanced_conversions lets Google match hashed email/phone from the
         signup form to the ad click — recovers conversions lost to cookie limits. */
      gtag('config', '<?= e(GOOGLE_ADS_ID) ?>', { allow_enhanced_conversions: true });
      <?php endif; ?>
    </script>
    <?php endif; ?>

    <?php if (clarityEnabled()): ?>
    <!-- Microsoft Clarity — heatmaps + session replay for landing-page debugging -->
    <script type="text/javascript">
      (function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
      t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
      y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})
      (window,document,"clarity","script","<?= e(CLARITY_PROJECT_ID) ?>");
    </script>
    <?php endif; ?>

    <?php
    // ── Sitewide Organization / AutoDealer schema ─────────────────────
    // Emitted once per page from the header so every URL carries the
    // business identity Google needs for brand + local knowledge panels.
    $orgSchema = [
        '@context'  => 'https://schema.org',
        '@type'     => ['AutoDealer', 'Organization'],
        '@id'       => CANONICAL_URL . '/#organization',
        'name'      => COMPANY_NAME,
        'legalName' => COMPANY_REGISTERED_NAME,
        'url'       => CANONICAL_URL . '/',
        'logo'      => CANONICAL_URL . '/logo-nav.png',
        'image'     => CANONICAL_URL . '/logo-nav.png',
        'email'     => COMPANY_EMAIL,
        'telephone' => COMPANY_PHONE,
        'address'   => [
            '@type'           => 'PostalAddress',
            'streetAddress'   => '80 Sterling Rd, Kosmosdal',
            'addressLocality' => 'Centurion',
            'addressRegion'   => 'Gauteng',
            'postalCode'      => '0187',
            'addressCountry'  => 'ZA',
        ],
        'areaServed'  => ['@type' => 'Country', 'name' => 'South Africa'],
        'contactPoint' => [
            [
                '@type'             => 'ContactPoint',
                'contactType'       => 'sales',
                'telephone'         => COMPANY_PHONE,
                'availableLanguage' => ['en'],
            ],
            [
                '@type'       => 'ContactPoint',
                'contactType' => 'customer support',
                'telephone'   => defined('COMPANY_WHATSAPP') ? COMPANY_WHATSAPP : COMPANY_PHONE2,
            ],
        ],
        'sameAs' => array_values(array_filter([
            'https://wa.me/' . (defined('COMPANY_WHATSAPP_INTL') ? COMPANY_WHATSAPP_INTL : ''),
        ])),
    ];
    ?>
    <script type="application/ld+json"><?= json_encode($orgSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/favicon.png">
    <link rel="apple-touch-icon"      href="<?= APP_URL ?>/assets/apple-touch-icon.png">

    <!-- Google Fonts: Barlow Condensed (headings) + Barlow (body) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Barlow:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">

    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
          integrity="sha512-Avb2QiuDEEvB4bZJYdft2mNjVShBftLdPG8FJ0V7irTLQ8Uo0qcPxh4Plq7G5tGm0rU+1SPhVotteLpBERwTkw=="
          crossorigin="anonymous" referrerpolicy="no-referrer">

    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="<?= APP_URL ?>/style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">

    <!-- Dropdown Nav Styles -->
    <style>
        /* ── About Dropdown ─────────────────────────────── */
        .nav-dropdown {
            position: relative;
        }

        .nav-dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            text-decoration: none;
        }

        .nav-dropdown-toggle .dd-chevron {
            font-size: .65rem;
            transition: transform .25s;
            color: inherit;
        }

        .nav-dropdown:hover .dd-chevron,
        .nav-dropdown.open .dd-chevron {
            transform: rotate(180deg);
        }

        .nav-dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            min-width: 220px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,.13);
            border: 1px solid #e5eaf0;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-4px);
            transition: opacity .2s, transform .2s, visibility .2s;
            z-index: 9999;
            padding: .5rem 0;
            padding-top: 14px;
            pointer-events: none;
        }

        .nav-dropdown-menu::before {
            content: '';
            position: absolute;
            top: -14px;
            left: 0;
            right: 0;
            height: 14px;
            background: transparent;
        }

        .nav-dropdown:hover .nav-dropdown-menu,
        .nav-dropdown.open .nav-dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
            pointer-events: auto;
        }

        .nav-dropdown-menu a {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .65rem 1.25rem;
            color: #334155;
            text-decoration: none;
            font-size: .9rem;
            font-weight: 500;
            transition: background .15s, color .15s, padding-left .15s;
            white-space: nowrap;
        }

        .nav-dropdown-menu a i {
            width: 16px;
            color: #005BA1;
            font-size: .85rem;
        }

        .nav-dropdown-menu a:hover {
            background: #f0f6ff;
            color: #005BA1;
            padding-left: 1.5rem;
        }

        .nav-dropdown-menu a.active {
            color: #005BA1;
            background: #ebf4ff;
            font-weight: 600;
        }

        .nav-dropdown-menu .dd-divider {
            height: 1px;
            background: #eef1f5;
            margin: .4rem 0;
        }

        /* ── Mobile menu link colours (dark bg needs white text) ── */
        .navbar-mobile .nav-link {
            color: rgba(255,255,255,.9) !important;
            background: none;
            border-radius: 8px;
            padding: .75rem 1rem;
            font-size: .95rem;
            display: flex;
            align-items: center;
            min-height: 48px; /* finger-friendly tap target */
        }

        .navbar-mobile .nav-link:hover,
        .navbar-mobile .nav-link.active {
            background: rgba(255,255,255,.12) !important;
            color: #fff !important;
        }

        /* Register Free button in mobile menu */
        .navbar-mobile .btn-nav-register {
            background: #00AEEF;
            color: #fff !important;
            text-align: center;
            border-radius: 8px;
            padding: .75rem 1rem;
            font-weight: 700;
            margin-top: .5rem;
            display: block;
            min-height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .navbar-mobile .btn-nav-register:hover {
            background: #0090c5;
        }

        /* ── Mobile About sub-menu ── */
        .mobile-sub-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            color: rgba(255,255,255,.9);
            background: none;
            border-radius: 8px;
            padding: .75rem 1rem;
            font-size: .95rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: .04em;
            min-height: 48px;
            user-select: none;
            -webkit-user-select: none;
        }

        .mobile-sub-toggle:hover {
            background: rgba(255,255,255,.12);
        }

        .mobile-sub-toggle.open {
            background: rgba(255,255,255,.08);
            color: #fff;
        }

        .mobile-sub-toggle .dd-chevron-m {
            font-size: .75rem;
            transition: transform .25s;
            opacity: .7;
        }

        .mobile-sub-toggle.open .dd-chevron-m {
            transform: rotate(180deg);
            opacity: 1;
        }

        .mobile-sub-menu {
            display: none;
            flex-direction: column;
            padding: .25rem 0 .25rem 1rem;
            border-left: 2px solid rgba(255,255,255,.2);
            margin: 0 0 .25rem 1rem;
            gap: .1rem;
        }

        .mobile-sub-menu.open {
            display: flex;
        }

        .mobile-sub-menu a {
            font-size: .9rem;
            padding: .6rem .75rem;
            color: rgba(255,255,255,.8) !important;
            border-radius: 6px;
            display: flex;
            align-items: center;
            min-height: 44px;
            transition: background .15s, color .15s;
        }

        .mobile-sub-menu a:hover {
            background: rgba(255,255,255,.1);
            color: #fff !important;
        }

        .mobile-sub-menu a.active {
            color: #00AEEF !important;
            background: rgba(0,174,239,.15);
        }

        /* ── Nav User Dropdown (replaces separate Dashboard/Admin/Logout buttons) ── */
        .nav-user-menu {
            position: relative;
        }

        .nav-user-btn {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            color: rgba(255,255,255,.9);
            font-size: .75rem;
            font-weight: 600;
            padding: .4rem .75rem;
            border-radius: var(--radius-sm, 6px);
            border: 1px solid rgba(255,255,255,.3);
            background: transparent;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: .04em;
            transition: background .2s, border-color .2s;
            white-space: nowrap;
            font-family: inherit;
            line-height: 1;
        }

        .nav-user-btn:hover,
        .nav-user-menu.open .nav-user-btn {
            background: rgba(255,255,255,.12);
            border-color: rgba(255,255,255,.5);
            color: #fff;
        }

        .nav-user-btn .dd-chevron {
            font-size: .6rem;
            transition: transform .2s;
            opacity: .7;
        }

        .nav-user-menu.open .nav-user-btn .dd-chevron {
            transform: rotate(180deg);
            opacity: 1;
        }

        .nav-user-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + .6rem);
            right: 0;
            min-width: 170px;
            background: #001432;
            border: 1px solid rgba(255,255,255,.13);
            border-radius: 10px;
            box-shadow: 0 8px 32px rgba(0,0,0,.45);
            z-index: 9999;
            padding: .4rem 0;
            overflow: hidden;
        }

        .nav-user-menu.open .nav-user-dropdown {
            display: block;
        }

        .nav-user-dropdown a {
            display: flex;
            align-items: center;
            gap: .55rem;
            padding: .65rem 1rem;
            color: rgba(255,255,255,.82);
            font-size: .82rem;
            font-weight: 500;
            text-decoration: none;
            white-space: nowrap;
            transition: background .15s, color .15s;
        }

        .nav-user-dropdown a i {
            width: 14px;
            font-size: .78rem;
            color: rgba(255,255,255,.45);
            text-align: center;
        }

        .nav-user-dropdown a:hover {
            background: rgba(255,255,255,.09);
            color: #fff;
        }

        .nav-user-dropdown a:hover i {
            color: rgba(255,255,255,.75);
        }

        .nav-user-dropdown .nav-user-dd-divider {
            height: 1px;
            background: rgba(255,255,255,.1);
            margin: .3rem 0;
        }

        .nav-user-dropdown .logout-link {
            color: rgba(255, 120, 120, .85);
        }

        .nav-user-dropdown .logout-link i {
            color: rgba(255, 120, 120, .6);
        }

        .nav-user-dropdown .logout-link:hover {
            color: #ff8080;
            background: rgba(255, 80, 80, .08);
        }

        /* ── Worker Portal chip (Sales Rep signed into the portal) ── */
        .nav-worker-chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            color: #eafff1;
            font-size: .72rem;
            font-weight: 700;
            padding: .4rem .7rem;
            margin-right: .6rem;
            border-radius: var(--radius-sm, 6px);
            background: rgba(40, 167, 69, .18);
            border: 1px solid rgba(75, 201, 120, .45);
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
            line-height: 1;
            transition: background .2s, border-color .2s;
        }

        .nav-worker-chip:hover {
            background: rgba(40, 167, 69, .32);
            border-color: rgba(75, 201, 120, .75);
            color: #fff;
        }

        .nav-worker-chip .wk-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #4be07a;
            box-shadow: 0 0 0 3px rgba(75, 224, 122, .22);
            flex: none;
        }

        .nav-worker-chip .wk-code {
            opacity: .8;
            font-weight: 600;
        }

        .nav-worker-chip i {
            font-size: .72rem;
            opacity: .85;
        }

        /* Mobile worker-portal link */
        .navbar-mobile .nav-worker-link {
            background: rgba(40, 167, 69, .16);
            border: 1px solid rgba(75, 201, 120, .4);
            color: #eafff1 !important;
            border-radius: 8px;
        }

        .navbar-mobile .nav-worker-link:hover {
            background: rgba(40, 167, 69, .28) !important;
            color: #fff !important;
        }
    </style>

    <?php if (isset($extraCss)): ?>
        <?= $extraCss ?>
    <?php endif; ?>
</head>
<body class="<?= e($bodyClass) ?>">
<?php if (gtmEnabled()): ?>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?= e(GTM_CONTAINER_ID) ?>"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     SITE HEADER + NAVIGATION
     ══════════════════════════════════════════════════════════ -->
<nav class="navbar" id="siteHeader" role="navigation" aria-label="Main navigation">
    <div class="navbar-inner">

        <!-- Logo -->
        <a href="<?= APP_URL ?>/index.php" class="navbar-logo" aria-label="Auction Hub Group — Home">
            <picture>
                <source srcset="<?= APP_URL ?>/logo-nav.webp" type="image/webp">
                <img src="<?= APP_URL ?>/logo-nav.png"
                     alt="Auction Hub Group"
                     width="108" height="72"
                     loading="eager">
            </picture>
        </a>

        <!-- Desktop nav links -->
        <ul class="navbar-nav" role="list">
            <li>
                <a href="<?= APP_URL ?>/index.php" class="nav-link<?= navActive('index.php') ?>">Home</a>
            </li>
            <li>
                <a href="<?= APP_URL ?>/auctions.php" class="nav-link<?= navActive('auctions.php') ?>">
                    Auctions
                    <?php
                    try {
                        $cStmt = $pdo->query("SELECT COUNT(*) FROM auctions WHERE status = 'active'");
                        $liveCount = (int)$cStmt->fetchColumn();
                        if ($liveCount > 0):
                    ?>
                        <span class="nav-badge live-pulse"><?= $liveCount ?> Live</span>
                    <?php endif; } catch(Exception $e) {} ?>
                </a>
            </li>

            <li>
                <a href="<?= APP_URL ?>/preauction.php" class="nav-link<?= navActive('preauction.php') ?>">Pre-Auction</a>
            </li>
            <li>
                <a href="<?= APP_URL ?>/events.php" class="nav-link<?= navActive('events.php') ?>">Events</a>
            </li>
            <li>
                <a href="<?= APP_URL ?>/sold_vehicles.php" class="nav-link<?= navActive('sold_vehicles.php') ?>">Sold</a>
            </li>
            <li>
                <a href="<?= APP_URL ?>/auction-guide.php" class="nav-link<?= navActive('auction-guide.php') ?>">How to Buy</a>
            </li>
            <li>
                <a href="<?= APP_URL ?>/financing.php" class="nav-link<?= navActive('financing.php') ?>">In-House Finance</a>
            </li>

            <!-- ── About Dropdown (Company info + FAQ) ── -->
            <li class="nav-dropdown <?= isAboutPage() ? 'open' : '' ?>" id="aboutDropdown">
                <a href="<?= APP_URL ?>/about.php" class="nav-link nav-dropdown-toggle <?= isAboutPage() ? 'active' : '' ?>"
                   aria-haspopup="true" aria-expanded="<?= isAboutPage() ? 'true' : 'false' ?>">
                    About
                    <i class="fas fa-chevron-down dd-chevron"></i>
                </a>
                <div class="nav-dropdown-menu" role="menu">
                    <a href="<?= APP_URL ?>/about.php" class="<?= navActive('about.php') ?>" role="menuitem">
                        <i class="fas fa-building"></i> Company Overview
                    </a>
                    <a href="<?= APP_URL ?>/team.php" class="<?= navActive('team.php') ?>" role="menuitem">
                        <i class="fas fa-users"></i> Meet the Team
                    </a>
                    <a href="<?= APP_URL ?>/careers.php" class="<?= navActive('careers.php') ?>" role="menuitem">
                        <i class="fas fa-briefcase"></i> Careers
                    </a>
                    <a href="<?= APP_URL ?>/testimonials.php" class="<?= navActive('testimonials.php') ?>" role="menuitem">
                        <i class="fas fa-star"></i> Testimonials
                    </a>
                    <div class="dd-divider" role="separator"></div>
                    <a href="<?= APP_URL ?>/faq.php" class="<?= navActive('faq.php') ?>" role="menuitem">
                        <i class="fas fa-circle-question"></i> FAQ
                    </a>
                </div>
            </li>

            <li>
                <a href="<?= APP_URL ?>/index.php#how-it-works" class="nav-link">How It Works</a>
            </li>
            <li>
                <a href="<?= APP_URL ?>/news.php" class="nav-link<?= in_array($currentPage,['news.php','news-post.php'])?' active':'' ?>">News</a>
            </li>
            <li>
                <a href="<?= APP_URL ?>/contact.php" class="nav-link<?= navActive('contact.php') ?>">Contact</a>
            </li>
        </ul>

        <!-- Auth controls (right side) -->
        <div class="navbar-right">
            <?php if ($workerPortal): ?>
                <!-- Sales Rep is signed into the Worker Portal → quick link back to it -->
                <a href="<?= APP_URL ?>/worker_portal.php" class="nav-worker-chip"
                   title="Signed into the Worker Portal — open your dashboard">
                    <span class="wk-dot" aria-hidden="true"></span>
                    <i class="fas fa-briefcase" aria-hidden="true"></i>
                    <?= e($workerPortal['first']) ?>
                    <?php if ($workerPortal['code']): ?>
                        <span class="wk-code"><?= e($workerPortal['code']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            <?php if ($currentUser): ?>
                <?php
                $dotClass = match($currentUser['bidder_status']) {
                    'verified_bidder'  => 'verified',
                    'pending_payment',
                    'pending_approval' => 'pending',
                    'suspended'        => 'suspended',
                    default            => 'registered',
                };
                $firstName = e(explode(' ', trim($currentUser['full_name']))[0]);
                ?>
                <!-- Compact user dropdown: name + status dot → Dashboard / Admin / Log Out -->
                <div class="nav-user-menu" id="navUserMenu">
                    <button class="nav-user-btn" id="navUserBtn"
                            aria-haspopup="true" aria-expanded="false"
                            title="<?= e($currentUser['bidder_status']) ?>">
                        <span class="status-dot <?= $dotClass ?>"></span>
                        <?= $firstName ?>
                        <i class="fas fa-chevron-down dd-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="nav-user-dropdown" id="navUserDropdown" role="menu">
                        <a href="<?= APP_URL ?>/user_dashboard.php" role="menuitem">
                            <i class="fas fa-gauge-high" aria-hidden="true"></i> My Dashboard
                        </a>
                        <?php if ($currentUser['role'] === 'admin'): ?>
                        <a href="<?= APP_URL ?>/admin_dashboard.php" role="menuitem">
                            <i class="fas fa-shield-alt" aria-hidden="true"></i> Admin Panel
                        </a>
                        <?php endif; ?>
                        <div class="nav-user-dd-divider" role="separator"></div>
                        <a href="<?= APP_URL ?>/logout.php" id="logoutBtn" class="logout-link" role="menuitem">
                            <i class="fas fa-right-from-bracket" aria-hidden="true"></i> Log Out
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= APP_URL ?>/login.php" class="btn-nav-login">Log In</a>
                <a href="<?= APP_URL ?>/login.php?tab=register" class="btn-nav-register">
                    <i class="fas fa-user-plus" aria-hidden="true"></i> Register
                </a>
            <?php endif; ?>
        </div>

        <?php if ($currentUser && ufReady()): $bellUnread = unreadAlertCount((int)$currentUser['sub']); ?>
        <!-- Alerts bell (outside .navbar-right so it also shows on phones) -->
        <div class="nav-bell" id="navBell">
            <button type="button" class="nav-bell-btn" id="navBellBtn"
                    aria-haspopup="true" aria-expanded="false" aria-controls="navBellDropdown"
                    aria-label="Alerts<?= $bellUnread ? " ($bellUnread unread)" : '' ?>">
                <i class="fas fa-bell" aria-hidden="true"></i>
                <span class="nav-bell-count" id="navBellCount"<?= $bellUnread ? '' : ' hidden' ?>><?= $bellUnread > 99 ? '99+' : $bellUnread ?></span>
            </button>
            <div class="nav-bell-dropdown" id="navBellDropdown" role="menu" aria-label="Alerts">
                <div class="nav-bell-head">
                    <strong>Alerts</strong>
                    <button type="button" class="nav-bell-markall" id="navBellMarkAll">Mark all read</button>
                </div>
                <div class="nav-bell-list" id="navBellList"><div class="nav-bell-empty">Loading…</div></div>
                <a class="nav-bell-foot" href="<?= APP_URL ?>/user_dashboard.php?tab=alerts">See all alerts</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Hamburger (mobile) -->
        <button class="navbar-hamburger" id="navHamburger"
                aria-label="Toggle navigation menu"
                aria-expanded="false"
                aria-controls="navMobileMenu">
            <div class="hamburger-lines">
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
                <span class="hamburger-line"></span>
            </div>
            <span class="hamburger-label">MENU</span>
        </button>

    </div><!-- /.navbar-inner -->
</nav>

<!-- Mobile menu -->
<div class="navbar-mobile" id="navMobileMenu" aria-hidden="true">
    <a href="<?= APP_URL ?>/index.php"             class="nav-link<?= navActive('index.php') ?>">Home</a>
    <a href="<?= APP_URL ?>/auctions.php"           class="nav-link<?= navActive('auctions.php') ?>">Auctions</a>
    <a href="<?= APP_URL ?>/preauction.php"         class="nav-link<?= navActive('preauction.php') ?>">Pre-Auction Deals</a>
    <a href="<?= APP_URL ?>/events.php"              class="nav-link<?= navActive('events.php') ?>">Events</a>
    <a href="<?= APP_URL ?>/sold_vehicles.php"       class="nav-link<?= navActive('sold_vehicles.php') ?>">Sold Vehicles</a>
    <a href="<?= APP_URL ?>/auction-guide.php"      class="nav-link<?= navActive('auction-guide.php') ?>">How to Buy</a>
    <a href="<?= APP_URL ?>/financing.php"          class="nav-link<?= navActive('financing.php') ?>">In-House Finance</a>

    <!-- Mobile About dropdown -->
    <div>
        <div class="mobile-sub-toggle <?= isAboutPage() ? 'open' : '' ?>" id="mobileAboutToggle">
            <span>About</span>
            <i class="fas fa-chevron-down dd-chevron-m"></i>
        </div>
        <div class="mobile-sub-menu <?= isAboutPage() ? 'open' : '' ?>" id="mobileAboutMenu">
            <a href="<?= APP_URL ?>/about.php"        class="nav-link<?= navActive('about.php') ?>">Company Overview</a>
            <a href="<?= APP_URL ?>/team.php"         class="nav-link<?= navActive('team.php') ?>">Meet the Team</a>
            <a href="<?= APP_URL ?>/careers.php"      class="nav-link<?= navActive('careers.php') ?>">Careers</a>
            <a href="<?= APP_URL ?>/testimonials.php"  class="nav-link<?= navActive('testimonials.php') ?>">Testimonials</a>
            <a href="<?= APP_URL ?>/faq.php"          class="nav-link<?= navActive('faq.php') ?>">FAQ</a>
        </div>
    </div>

    <a href="<?= APP_URL ?>/index.php#how-it-works" class="nav-link">How It Works</a>
    <a href="<?= APP_URL ?>/news.php"                class="nav-link<?= in_array($currentPage,['news.php','news-post.php'])?' active':'' ?>">News &amp; Reviews</a>
    <a href="<?= APP_URL ?>/contact.php"             class="nav-link<?= navActive('contact.php') ?>">Contact</a>
    <div class="navbar-mobile-divider"></div>
    <?php if ($workerPortal): ?>
        <a href="<?= APP_URL ?>/worker_portal.php" class="nav-link nav-worker-link">
            <i class="fas fa-briefcase" style="margin-right:.5rem;"></i>
            Worker Portal
            <?php if ($workerPortal['code']): ?>
                <span style="margin-left:.4rem;opacity:.75;font-size:.85em;"><?= e($workerPortal['code']) ?></span>
            <?php endif; ?>
        </a>
    <?php endif; ?>
    <?php if ($currentUser): ?>
        <a href="<?= APP_URL ?>/user_dashboard.php"             class="nav-link">My Dashboard</a>
        <a href="<?= APP_URL ?>/user_dashboard.php?tab=my-bids" class="nav-link">My Bids</a>
        <?php if ($currentUser['role'] === 'admin'): ?>
            <a href="<?= APP_URL ?>/admin_dashboard.php" class="nav-link">Admin Panel</a>
        <?php endif; ?>
        <?php if ($currentUser['bidder_status'] !== 'verified_bidder'): ?>
            <a href="<?= APP_URL ?>/user_dashboard.php?tab=register" class="btn-nav-register">Register to Bid</a>
        <?php endif; ?>
        <a href="logout.php" id="logoutBtnMobile" class="nav-link">Log Out</a>
    <?php else: ?>
        <a href="<?= APP_URL ?>/login.php" class="nav-link">Log In</a>
        <a href="<?= APP_URL ?>/login.php?tab=register" class="btn-nav-register">Register Free</a>
    <?php endif; ?>
</div>

<!-- Announcement Ticker -->
<div class="ticker-bar" aria-label="Announcements" role="marquee">
    <div class="ticker-scroll" id="tickerTrack">
        <?php foreach ($tickerMessages as $msg): ?>
            <span class="ticker-item"><?= e($msg) ?></span>
        <?php endforeach; ?>
        <?php foreach ($tickerMessages as $msg): ?>
            <span class="ticker-item" aria-hidden="true"><?= e($msg) ?></span>
        <?php endforeach; ?>
    </div>
</div>

<!-- Flash Message -->
<?php if (!empty($flash['message'])): ?>
<div class="alert alert-<?= e($flash['type']) ?>" id="flashBanner" role="alert"
     style="margin:1rem auto;max-width:var(--container-max);padding-left:1.5rem;padding-right:1.5rem;">
    <i class="fas <?= $flash['type'] === 'success' ? 'fa-circle-check' : ($flash['type'] === 'error' ? 'fa-circle-xmark' : 'fa-circle-info') ?>"
       aria-hidden="true"></i>
    <span><?= e($flash['message']) ?></span>
    <button onclick="this.parentElement.remove()" aria-label="Dismiss"
            style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:1rem;">
        <i class="fas fa-xmark"></i>
    </button>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';

    // ── Elements ────────────────────────────────────────────────
    var hamburger    = document.getElementById('navHamburger');
    var mobileMenu   = document.getElementById('navMobileMenu');
    var aboutToggle  = document.getElementById('mobileAboutToggle');
    var aboutSubmenu = document.getElementById('mobileAboutMenu');
    var desktopDD    = document.getElementById('aboutDropdown');

    // ── Hamburger — open / close mobile nav ─────────────────────
    if (hamburger && mobileMenu) {
        hamburger.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = mobileMenu.classList.contains('open');
            if (isOpen) {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        });
    }

    function openMobileMenu() {
        if (!hamburger || !mobileMenu) return;
        mobileMenu.classList.add('open');
        mobileMenu.setAttribute('aria-hidden', 'false');
        hamburger.classList.add('open');
        hamburger.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden'; // prevent background scroll
    }

    function closeMobileMenu() {
        if (!hamburger || !mobileMenu) return;
        mobileMenu.classList.remove('open');
        mobileMenu.setAttribute('aria-hidden', 'true');
        hamburger.classList.remove('open');
        hamburger.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }

    // Close mobile menu when a nav link (not the About toggle) is tapped
    if (mobileMenu) {
        mobileMenu.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                closeMobileMenu();
            });
        });
    }

    // Close mobile menu when tapping outside it
    document.addEventListener('click', function (e) {
        if (mobileMenu && mobileMenu.classList.contains('open')) {
            if (!mobileMenu.contains(e.target) && !hamburger.contains(e.target)) {
                closeMobileMenu();
            }
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeMobileMenu();
            if (desktopDD) desktopDD.classList.remove('open');
            // nav-user-menu Escape is handled in its own listener below
        }
    });

    // ── Mobile About sub-menu toggle ────────────────────────────
    if (aboutToggle && aboutSubmenu) {
        aboutToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = aboutSubmenu.classList.contains('open');
            aboutSubmenu.classList.toggle('open', !isOpen);
            aboutToggle.classList.toggle('open', !isOpen);
        });
    }

    // ── Desktop About dropdown — click/touch support ─────────────
    if (desktopDD) {
        desktopDD.addEventListener('click', function (e) {
            if (e.target.closest('.nav-dropdown-menu')) return; // link click — let it navigate
            e.stopPropagation();
            this.classList.toggle('open');
        });

        document.addEventListener('click', function (e) {
            if (desktopDD && !desktopDD.contains(e.target)) {
                desktopDD.classList.remove('open');
            }
        });
    }

    // ── Alerts bell ───────────────────────────────────────────────
    var bell = document.getElementById('navBell');
    if (bell) {
        var bellBtn   = document.getElementById('navBellBtn');
        var bellList  = document.getElementById('navBellList');
        var bellCount = document.getElementById('navBellCount');
        var bellApi   = '<?= APP_URL ?>/api.php';
        var bellIcons = {outbid: 'fa-arrow-trend-down', watch_live: 'fa-bolt', watch_ending: 'fa-hourglass-half',
                         won: 'fa-trophy', watch_sold: 'fa-tag', saved_search: 'fa-magnifying-glass'};
        var esc = function (s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
            });
        };
        var setCount = function (n) {
            bellCount.textContent = n > 99 ? '99+' : n;
            bellCount.hidden = !n;
            bellBtn.setAttribute('aria-label', 'Alerts' + (n ? ' (' + n + ' unread)' : ''));
        };
        var post = function (fields) {
            var fd = new FormData();
            Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
            return fetch(bellApi, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); });
        };
        var loadBell = function () {
            fetch(bellApi + '?action=get_alerts', {credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success) return;
                    setCount(d.unread);
                    bellList.innerHTML = d.alerts.length ? d.alerts.map(function (a) {
                        var href = a.link ? '<?= APP_URL ?>/' + a.link.replace(/^\//, '') : '<?= APP_URL ?>/user_dashboard.php?tab=alerts';
                        return '<a class="nav-bell-item' + (a.is_read == 1 ? '' : ' unread') + '" href="' + esc(href) + '" data-id="' + a.id + '">'
                             + '<i class="fas ' + (bellIcons[a.type] || 'fa-bell') + '" aria-hidden="true"></i>'
                             + '<span><strong>' + esc(a.title) + '</strong>'
                             + (a.message ? '<small>' + esc(a.message) + '</small>' : '')
                             + '<em>' + esc(a.ago) + '</em></span></a>';
                    }).join('') : '<div class="nav-bell-empty">No alerts yet. Watch a vehicle or place a bid and updates appear here.</div>';
                })
                .catch(function () { bellList.innerHTML = '<div class="nav-bell-empty">Could not load alerts.</div>'; });
        };
        bellBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = bell.classList.toggle('open');
            bellBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) loadBell();
        });
        bellList.addEventListener('click', function (e) {
            var item = e.target.closest('.nav-bell-item');
            if (item && item.classList.contains('unread')) post({action: 'mark_alerts_read', id: item.dataset.id});
        });
        document.getElementById('navBellMarkAll').addEventListener('click', function (e) {
            e.stopPropagation();
            post({action: 'mark_alerts_read'}).then(function (d) {
                setCount(d.unread || 0);
                bellList.querySelectorAll('.unread').forEach(function (el) { el.classList.remove('unread'); });
            });
        });
        document.addEventListener('click', function (e) {
            if (!bell.contains(e.target)) { bell.classList.remove('open'); bellBtn.setAttribute('aria-expanded', 'false'); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && bell.classList.contains('open')) {
                bell.classList.remove('open'); bellBtn.setAttribute('aria-expanded', 'false'); bellBtn.focus();
            }
        });
    }

    // ── ♥ Watch buttons (any page; markup from watchButton()) ─────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-watch]');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        <?php if (!$currentUser): ?>
        window.location.href = '<?= APP_URL ?>/login.php?tab=register';
        return;
        <?php else: ?>
        if (btn.disabled) return;
        var id = btn.dataset.watch;
        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'toggle_watch');
        fd.append('auction_id', id);
        fetch('<?= APP_URL ?>/api.php', {method: 'POST', body: fd, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (typeof showToast === 'function') showToast(d.message, d.success ? 'success' : 'error');
                if (!d.success) return;
                document.querySelectorAll('[data-watch="' + id + '"]').forEach(function (b) {
                    b.classList.toggle('is-watching', d.watching);
                    b.setAttribute('aria-pressed', d.watching ? 'true' : 'false');
                    var label = d.watching ? 'Remove from watchlist' : 'Add to watchlist';
                    b.title = label;
                    b.setAttribute('aria-label', label);
                    var icon = b.querySelector('i');
                    if (icon) icon.className = (d.watching ? 'fas' : 'far') + ' fa-heart';
                    var text = b.querySelector('.watch-btn-text');
                    if (text) text.textContent = d.watching ? 'Watching' : 'Watch';
                });
                document.dispatchEvent(new CustomEvent('ahg:watch', {detail: {id: id, watching: d.watching}}));
            })
            .catch(function () { if (typeof showToast === 'function') showToast('Could not update your watchlist.', 'error'); })
            .finally(function () { btn.disabled = false; });
        <?php endif; ?>
    });

    // ── User account dropdown (Dashboard / Admin / Log Out) ───────
    var navUserMenu = document.getElementById('navUserMenu');
    var navUserBtn  = document.getElementById('navUserBtn');

    if (navUserMenu && navUserBtn) {
        navUserBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = navUserMenu.classList.toggle('open');
            navUserBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        // Close when clicking a link inside the dropdown
        navUserMenu.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                navUserMenu.classList.remove('open');
                navUserBtn.setAttribute('aria-expanded', 'false');
            });
        });

        // Close on outside click
        document.addEventListener('click', function (e) {
            if (navUserMenu && !navUserMenu.contains(e.target)) {
                navUserMenu.classList.remove('open');
                if (navUserBtn) navUserBtn.setAttribute('aria-expanded', 'false');
            }
        });

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && navUserMenu.classList.contains('open')) {
                navUserMenu.classList.remove('open');
                navUserBtn.setAttribute('aria-expanded', 'false');
                navUserBtn.focus();
            }
        });
    }

})();
</script>

<!-- Page content starts below this line -->
