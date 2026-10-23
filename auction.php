<?php
// ============================================================
// AUCTION HUB GROUP — auction.php
// Single vehicle auction page
// Sections: gallery · vehicle details · bid panel · live feed
//           · related vehicles
// ============================================================

require_once 'config.php';
require_once 'auth.php';

// Resolve current user (revoked/stale tokens resolve to null so the page
// renders a logged-out state instead of a bid panel that fails on submit)
$currentUser = getPageUser();
if ($currentUser) {
    syncSession($currentUser);
}

// ── LOAD AUCTION ──────────────────────────────────────────────
$auctionId = (int)($_GET['id'] ?? 0);
if (!$auctionId) {
    redirect('auctions.php', 'Auction not found.', 'error');
}

$auction = null;
try {
    $stmt = $pdo->prepare("
        SELECT a.*, m.name AS make_name
        FROM auctions a
        LEFT JOIN makes m ON m.id = a.make_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$auctionId]);
    $auction = $stmt->fetch();
} catch (Exception $e) {}

if (!$auction) {
    redirect('auctions.php', 'Auction not found.', 'error');
}

// Auto-close expired active auctions
if ($auction['status'] === 'active' && !empty($auction['end_time'])
    && strtotime($auction['end_time']) < time()) {
    try {
        $pdo->prepare("UPDATE auctions SET status='closed' WHERE id=?")->execute([$auctionId]);
        $auction['status'] = 'closed';
    } catch (Exception $e) {}
}

// Auto-open upcoming auctions whose start_time has passed
if ($auction['status'] === 'upcoming' && !empty($auction['start_time'])
    && strtotime($auction['start_time']) < time()) {
    try {
        $pdo->prepare("UPDATE auctions SET status='active' WHERE id=?")->execute([$auctionId]);
        $auction['status'] = 'active';
    } catch (Exception $e) {}
}

// ── PREV/NEXT VEHICLE NAVIGATION ─────────────────────────────
$prevAuction = $nextAuction = null;
try {
    $stmt = $pdo->prepare("
        SELECT a.id, CONCAT(a.year, ' ', COALESCE(m.name,''), ' ', SUBSTRING_INDEX(a.item_name, ' ', 3)) AS title
        FROM auctions a LEFT JOIN makes m ON m.id = a.make_id
        WHERE a.status IN ('active','upcoming') AND a.id < ?
        ORDER BY a.id DESC LIMIT 1
    ");
    $stmt->execute([$auctionId]);
    $prevAuction = $stmt->fetch();
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("
        SELECT a.id, CONCAT(a.year, ' ', COALESCE(m.name,''), ' ', SUBSTRING_INDEX(a.item_name, ' ', 3)) AS title
        FROM auctions a LEFT JOIN makes m ON m.id = a.make_id
        WHERE a.status IN ('active','upcoming') AND a.id > ?
        ORDER BY a.id ASC LIMIT 1
    ");
    $stmt->execute([$auctionId]);
    $nextAuction = $stmt->fetch();
} catch (Exception $e) {}

// ── GALLERY IMAGES ────────────────────────────────────────────
$gallery = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, file_path, cloudinary_public_id, is_primary, sort_order
        FROM auction_gallery
        WHERE auction_id = ?
        ORDER BY is_primary DESC, sort_order ASC, id ASC
    ");
    $stmt->execute([$auctionId]);
    $rawGallery = $stmt->fetchAll();
    // Normalise: expose image URL as 'image_url' for the template
    foreach ($rawGallery as $row) {
        $row['image_url'] = !empty($row['file_path']) ? $row['file_path'] : '';
        $gallery[] = $row;
    }
} catch (Exception $e) {}

// Primary image fallback
$primaryImg = imgHost($auction['image_url']) ?: APP_URL . '/assets/no-image.jpg';
if (!empty($gallery)) {
    foreach ($gallery as $g) {
        if ($g['is_primary'] && $g['image_url']) {
            $primaryImg = $g['image_url'];
            break;
        }
    }
}

// ── BID DATA ──────────────────────────────────────────────────
$bidCount   = 0;
$bidsRecent = [];
try {
    // Fetch recent bids — COALESCE handles both old (amount/created_at) and new (bid_amount/bid_time) schema
    $stmt = $pdo->prepare("
        SELECT b.id,
               COALESCE(b.bid_amount, b.amount)       AS amount,
               b.bidder_number, b.bidder_name,
               COALESCE(b.bid_time,   b.created_at)   AS created_at,
               b.is_winning
        FROM bids b
        WHERE b.auction_id = ?
        ORDER BY b.id DESC
        LIMIT 20
    ");
    $stmt->execute([$auctionId]);
    $bidsRecent = $stmt->fetchAll();

    // Accurate total bid count
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM bids WHERE auction_id = ?");
    $cStmt->execute([$auctionId]);
    $bidCount = (int)$cStmt->fetchColumn();
} catch (Exception $e) { $bidCount = count($bidsRecent); }

// Current user's last bid on this auction
$myLastBid = null;
if ($currentUser) {
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(bid_amount, amount) AS amount,
                   COALESCE(bid_time, created_at) AS created_at,
                   is_winning
            FROM bids
            WHERE auction_id=? AND user_id=?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$auctionId, $currentUser['sub']]);
        $myLastBid = $stmt->fetch();
    } catch (Exception $e) {}
}

// ── RELATED AUCTIONS ──────────────────────────────────────────
$related = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.id, a.item_name, a.year, a.current_price, a.start_price,
               a.image_url, a.status, a.end_time, m.name AS make_name
        FROM auctions a
        LEFT JOIN makes m ON m.id = a.make_id
        WHERE a.make_id = ?
          AND a.id != ?
          AND a.status IN ('active','upcoming')
        ORDER BY a.status DESC, a.end_time ASC
        LIMIT 3
    ");
    $stmt->execute([$auction['make_id'], $auctionId]);
    $related = $stmt->fetchAll();
} catch (Exception $e) {}

// ── COMPUTED VALUES ───────────────────────────────────────────
$currentPrice  = (float)$auction['current_price'] > 0
                    ? (float)$auction['current_price']
                    : (float)$auction['start_price'];
$reservePrice  = (float)($auction['reserve_price'] ?? 0);
$minIncrement  = (float)($auction['min_increment'] ?? 500);
$minNextBid    = $currentPrice + $minIncrement;
$reserveMet    = $reservePrice > 0 && $currentPrice >= $reservePrice;
$isClosed      = $auction['status'] === 'closed';
$isActive      = $auction['status'] === 'active';
$isUpcoming    = $auction['status'] === 'upcoming';
$canBid        = $isActive
                 && $currentUser
                 && ($currentUser['bidder_status'] ?? '') === 'verified_bidder';

// ── PAGE META ─────────────────────────────────────────────────
// Build "Year Make Model" without duplicating parts already inside
// item_name (bulk-imported rows often start with the year + make)
$tName = trim($auction['item_name'] ?? '');
$tMake = trim($auction['make_name'] ?? '');
$tYear = (string)($auction['year'] ?? '');
$vehicleTitle = $tName;
if ($tMake !== '' && stripos($tName, $tMake) === false) $vehicleTitle = $tMake . ' ' . $vehicleTitle;
if ($tYear !== '' && !str_contains($vehicleTitle, $tYear)) $vehicleTitle = $tYear . ' ' . $vehicleTitle;
$vehicleTitle = trim($vehicleTitle);

// SEO: unique title/description per vehicle (header.php appends the brand)
$pageTitle = $vehicleTitle
    . ($isActive ? ' — Live Auction' : ($isUpcoming ? ' — Upcoming Auction' : ' — Sold at Auction'));

$descBits = [];
if ((int)$auction['mileage'] > 0)        $descBits[] = number_format((int)$auction['mileage']) . ' km';
if (!empty($auction['transmission']))    $descBits[] = ucfirst($auction['transmission']);
if (!empty($auction['fuel_type']))       $descBits[] = ucfirst($auction['fuel_type']);
if (!empty($auction['condition_grade'])) $descBits[] = 'DEKRA Grade ' . strtoupper($auction['condition_grade']);
$pageDescription = ($isActive ? 'Bid now on this ' : 'Bank-repossessed ') . $vehicleTitle
    . ($descBits ? ' — ' . implode(', ', $descBits) : '')
    . '. Current price ' . formatRand($currentPrice)
    . '. DEKRA-inspected repossessed vehicle at Auction Hub Group.';

$canonicalUrl = CANONICAL_URL . '/auction.php?id=' . $auctionId;
if (!empty($auction['image_url'])) {
    $pageImage = preg_match('#^https?://#i', $auction['image_url'])
        ? cldImg($auction['image_url'], CLD_LARGE)
        : APP_URL . '/' . ltrim($auction['image_url'], '/');
}
$bodyClass = 'page-auction';

require_once 'header.php';

// ── STRUCTURED DATA (Google rich results) ────────────────────
$jsonLd = [
    '@context'      => 'https://schema.org',
    '@type'         => 'Car',
    'name'          => $vehicleTitle,
    'url'           => $canonicalUrl,
    'itemCondition' => 'https://schema.org/UsedCondition',
    'offers'        => [
        '@type'         => 'Offer',
        'price'         => (string)$currentPrice,
        'priceCurrency' => 'ZAR',
        'availability'  => $isClosed ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
        'url'           => $canonicalUrl,
    ],
];
if (!empty($auction['make_name']))    $jsonLd['brand'] = ['@type' => 'Brand', 'name' => $auction['make_name']];
if (!empty($auction['year']))         $jsonLd['vehicleModelDate'] = (string)$auction['year'];
if ((int)$auction['mileage'] > 0)     $jsonLd['mileageFromOdometer'] = ['@type' => 'QuantitativeValue', 'value' => (int)$auction['mileage'], 'unitCode' => 'KMT'];
if (!empty($auction['fuel_type']))    $jsonLd['fuelType'] = ucfirst($auction['fuel_type']);
if (!empty($auction['transmission'])) $jsonLd['vehicleTransmission'] = ucfirst($auction['transmission']);
if (!empty($auction['color']))        $jsonLd['color'] = $auction['color'];
if (isset($pageImage))                $jsonLd['image'] = $pageImage;

// Tie the offer back to the sitewide Organization node emitted by header.php.
// Without a seller, Google treats the listing as an orphaned product and is
// far less likely to show a rich result for it.
$jsonLd['offers']['seller']         = ['@id' => CANONICAL_URL . '/#organization'];
$jsonLd['offers']['itemCondition']  = 'https://schema.org/UsedCondition';
$jsonLd['offers']['areaServed']     = ['@type' => 'Country', 'name' => 'South Africa'];

// Breadcrumbs give the listing a visible path in search results and tell
// Google that auctions.php is the parent hub for every vehicle page.
$breadcrumbLd = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',            'item' => CANONICAL_URL . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Browse Auctions', 'item' => CANONICAL_URL . '/auctions.php'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $vehicleTitle,     'item' => $canonicalUrl],
    ],
];
?>
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/ld+json"><?= json_encode($breadcrumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php ?>

<!-- ══════════════════════════════════════════════════════════
     AUCTION PAGE STYLES
     ══════════════════════════════════════════════════════════ -->
<style>
/* ── Specs: uppercase value text ─────────────────────────── */
.specs-value { text-transform: uppercase !important; letter-spacing: .04em; }
.specs-row-dekra .specs-value { text-transform: none !important; }
.specs-value-dekra { display:flex; flex-direction:column; align-items:flex-start; }

/* ── Bank Repo / Liquidated origin banner ────────────────── */
.vehicle-origin-banner {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
    background: linear-gradient(135deg,#003f73 0%,#005BA1 100%);
    border-radius: 12px;
    padding: 1.25rem 1.4rem;
    margin-bottom: 1.5rem;
    color: #fff;
    box-shadow: 0 4px 20px rgba(0,63,115,.25);
}
.vob-left { display:flex; gap:.9rem; flex:1; align-items:flex-start; }
.vob-icon { font-size:2rem; color:rgba(255,255,255,.7); margin-top:.1rem; flex-shrink:0; }
.vob-copy { flex:1; }
.vob-title { display:block; font-size:.95rem; font-weight:800; letter-spacing:.05em; color:#fff; margin-bottom:.45rem; }
.vob-text  { font-size:.83rem; line-height:1.65; color:rgba(255,255,255,.88); margin:0 0 .75rem; }
.vob-tags  { display:flex; flex-wrap:wrap; gap:.4rem; }
.vob-tag   { display:inline-flex; align-items:center; gap:.3rem; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.3); border-radius:20px; padding:.25rem .7rem; font-size:.7rem; font-weight:700; letter-spacing:.06em; color:#fff; }
.vob-tag-green { background:rgba(76,175,80,.3); border-color:rgba(76,175,80,.6); }
.vob-dekra { flex-shrink:0; display:flex; align-items:center; }
@media (max-width:600px) {
    .vehicle-origin-banner { flex-direction:column; }
    .vob-left { flex-direction:column; }
    .vob-dekra { justify-content:center; }
}

/* ── Bid panel: vehicle tags ─────────────────────────────── */
.bp-vehicle-tags { display:flex; flex-wrap:wrap; gap:.35rem; margin:.4rem 0 .75rem; }
.bp-tag { display:inline-flex; align-items:center; gap:.28rem; padding:.22rem .65rem; border-radius:16px; font-size:.68rem; font-weight:800; letter-spacing:.07em; border:1.5px solid; }
.bp-tag-repo { background:#fff3cd; color:#7d4e00; border-color:#f0a500; }

/* ── Bid panel: DEKRA badge row ──────────────────────────── */
.bp-dekra-badge {
    display: flex;
    align-items: center;
    gap: .75rem;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: .6rem .9rem;
    margin-bottom: .85rem;
}
.bp-dekra-info { flex:1; }
.bp-dekra-info strong { display:block; font-size:.8rem; letter-spacing:.06em; color:#1a2a3a; margin-bottom:.2rem; }
.bp-dekra-info span { font-size:.75rem; color:#4a6080; line-height:1.5; }

/* ── Force vehicle title uppercase ──────────────────────── */
.bid-panel-title { text-transform: uppercase; letter-spacing: .04em; }
</style>

<!-- ══════════════════════════════════════════════════════════
     BREADCRUMB
     ══════════════════════════════════════════════════════════ -->
<div class="breadcrumb-bar">
    <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="index.php">Home</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="auctions.php">Auctions</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <?php if ($auction['make_name']): ?>
                <a href="auctions.php?make_id=<?= (int)$auction['make_id'] ?>">
                    <?= e($auction['make_name']) ?>
                </a>
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <?php endif; ?>
            <span aria-current="page"><?= e($vehicleTitle) ?></span>
        </nav>

        <!-- Status badge in breadcrumb bar -->
        <div class="breadcrumb-status">
            <?php if ($isActive): ?>
                <span class="badge badge-active badge-lg">
                    <span class="live-dot" aria-hidden="true"></span> Live Auction
                </span>
            <?php elseif ($isUpcoming): ?>
                <span class="badge badge-upcoming badge-lg">
                    <i class="fas fa-clock" aria-hidden="true"></i> Upcoming
                </span>
            <?php else: ?>
                <span class="badge badge-closed badge-lg">
                    <i class="fas fa-lock" aria-hidden="true"></i> Closed
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     MAIN AUCTION LAYOUT  (gallery + details + bid panel)
     ══════════════════════════════════════════════════════════ -->
<main class="auction-page-wrap" id="main-content">

<!-- Prev / Next Vehicle Nav -->
<?php if ($prevAuction || $nextAuction): ?>
<div class="container">
    <div class="vehicle-nav">
        <?php if ($prevAuction): ?>
            <a href="auction.php?id=<?= $prevAuction['id'] ?>" class="vehicle-nav-btn vehicle-nav-prev">
                <i class="fas fa-chevron-left"></i>
                <span>
                    <small>Previous Vehicle</small>
                    <strong><?= e($prevAuction['title'] ?? 'Previous') ?></strong>
                </span>
            </a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>

        <a href="auctions.php" class="vehicle-nav-center" title="Back to all auctions">
            <i class="fas fa-th"></i> All Vehicles
        </a>

        <?php if ($nextAuction): ?>
            <a href="auction.php?id=<?= $nextAuction['id'] ?>" class="vehicle-nav-btn vehicle-nav-next">
                <span>
                    <small>Next Vehicle</small>
                    <strong><?= e($nextAuction['title'] ?? 'Next') ?></strong>
                </span>
                <i class="fas fa-chevron-right"></i>
            </a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="container auction-layout">

    <!-- ── LEFT COLUMN: Gallery + Details ────────────────── -->
    <div class="auction-left-col">

        <!-- ════════════════════════════════════════════════
             IMAGE GALLERY
             ════════════════════════════════════════════════ -->
        <div class="auction-gallery" id="auction-gallery">

            <!-- Main image -->
            <div class="gallery-main-wrap">
                <img src="<?= e(cldImg($primaryImg, CLD_LARGE)) ?>"
                     alt="<?= e($vehicleTitle) ?>"
                     class="gallery-main-img"
                     id="gallery-main-img"
                     data-lightbox-trigger
                     width="800" height="540">

                <?php if (!empty($auction['is_sold'])): ?>
                    <div class="sold-stamp sold-stamp-lg" aria-label="Vehicle sold"><span>Sold</span></div>
                <?php elseif ($isClosed): ?>
                    <div class="gallery-sold-overlay" aria-label="Auction closed">
                        <i class="fas fa-lock" aria-hidden="true"></i>
                        <span>Auction Closed</span>
                    </div>
                <?php endif; ?>

                <!-- Lightbox trigger icon -->
                <button class="gallery-expand-btn"
                        aria-label="View full-size image"
                        data-lightbox-trigger>
                    <i class="fas fa-expand" aria-hidden="true"></i>
                </button>

                <!-- Arrow nav (shown when >1 image) -->
                <?php $totalImgCount = ($auction['image_url'] ? 1 : 0) + count($gallery); if ($totalImgCount > 1): ?>
                <button class="gallery-nav-btn gallery-nav-prev" aria-label="Previous image" id="gallery-prev">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </button>
                <button class="gallery-nav-btn gallery-nav-next" aria-label="Next image" id="gallery-next">
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                </button>
                <?php endif; ?>
            </div>

            <!-- Thumbnail strip: main image + all gallery images -->
            <?php
            // Build all images array: main image first, then gallery
            $allImages = [];
            if ($auction['image_url']) {
                $allImages[] = ['url' => $auction['image_url'], 'label' => 'Main image'];
            }
            foreach ($gallery as $g) {
                if (!empty($g['image_url'])) {
                    $allImages[] = ['url' => $g['image_url'], 'label' => 'Gallery image'];
                }
            }
            ?>
            <?php if (count($allImages) > 1): ?>
            <div class="gallery-thumbs" role="list" aria-label="Image thumbnails" id="gallery-thumbs">
                <?php foreach ($allImages as $i => $img): ?>
                    <button class="gallery-thumb <?= $i === 0 ? 'gallery-thumb-active' : '' ?>"
                            role="listitem"
                            data-src="<?= e(cldImg($img['url'], CLD_LARGE)) ?>"
                            data-full="<?= e(cldImg($img['url'], CLD_LARGE)) ?>"
                            data-index="<?= $i ?>"
                            aria-label="<?= e($img['label']) ?> <?= $i + 1 ?>"
                            aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>">
                        <img src="<?= e(cldImg($img['url'], CLD_THUMB)) ?>"
                             alt="Vehicle image <?= $i + 1 ?>"
                             loading="lazy"
                             width="120" height="80">
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Lightbox overlay (hidden by default, activated by JS) -->
            <div class="lightbox-overlay" id="lightbox-overlay" role="dialog"
                 aria-modal="true" aria-label="Full-size image viewer" hidden>
                <button class="lightbox-close" id="lightbox-close" aria-label="Close lightbox">
                    <i class="fas fa-xmark" aria-hidden="true"></i>
                </button>
                <button class="lightbox-nav lightbox-prev" id="lightbox-prev" aria-label="Previous image">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </button>
                <div class="lightbox-img-wrap">
                    <img src="" alt="" class="lightbox-img" id="lightbox-img">
                </div>
                <button class="lightbox-nav lightbox-next" id="lightbox-next" aria-label="Next image">
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                </button>
                <div class="lightbox-counter" id="lightbox-counter" aria-live="polite"></div>
            </div>

        </div><!-- /.auction-gallery -->


        <!-- ════════════════════════════════════════════════
             VEHICLE ORIGIN BANNER (Bank Repo / Liquidated)
             ════════════════════════════════════════════════ -->
        <div class="vehicle-origin-banner">
            <div class="vob-left">
                <div class="vob-icon"><i class="fas fa-university" aria-hidden="true"></i></div>
                <div class="vob-copy">
                    <strong class="vob-title">
                        <i class="fas fa-gavel" aria-hidden="true"></i>
                        BANK LIQUIDATED &mdash; PREOWNED VEHICLE
                    </strong>
                    <p class="vob-text">
                        This vehicle is a <strong>bank repossession / financial institution liquidation</strong>.
                        Own it for <strong>below market value</strong> — all vehicles are sold
                        <em>voetstoots</em>, DEKRA inspected, and come with a full vehicle condition report.
                        Register &amp; bid to drive this home today.
                    </p>
                    <div class="vob-tags">
                        <span class="vob-tag"><i class="fas fa-shield-halved"></i> DEKRA INSPECTED</span>
                        <span class="vob-tag"><i class="fas fa-file-contract"></i> CONDITION REPORT AVAILABLE</span>
                        <span class="vob-tag"><i class="fas fa-percent"></i> BELOW MARKET VALUE</span>
                        <?php if ($auction['service_history']): ?>
                        <span class="vob-tag vob-tag-green"><i class="fas fa-clipboard-check"></i> FULL SERVICE HISTORY</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($auction['condition_grade']): ?>
            <div class="vob-dekra">
                <?= dekraBadgeSVG($auction['condition_grade'], 110) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ════════════════════════════════════════════════
             VEHICLE DETAILS
             ════════════════════════════════════════════════ -->
        <div class="vehicle-details" id="vehicle-details">

            <div class="vehicle-details-tabs">
                <button class="vd-tab active" data-target="vd-specs" aria-selected="true">
                    <i class="fas fa-list-check" aria-hidden="true"></i> Specifications
                </button>
                <button class="vd-tab" data-target="vd-description" aria-selected="false">
                    <i class="fas fa-file-lines" aria-hidden="true"></i> Description
                </button>
                <?php if ($auction['service_history']): ?>
                <button class="vd-tab" data-target="vd-history" aria-selected="false">
                    <i class="fas fa-clipboard-list" aria-hidden="true"></i> Service History
                </button>
                <?php endif; ?>
            </div>

            <!-- Specs tab -->
            <div class="vd-tab-panel active" id="vd-specs">
                <table class="specs-table" aria-label="Vehicle specifications">
                    <tbody>
                        <?php
                        $specs = [
                            ['Make',         $auction['make_name']],
                            ['Model',        $auction['item_name']],
                            ['Year',         $auction['year']],
                            ['Mileage',      $auction['mileage'] ? number_format((int)$auction['mileage']) . ' km' : null],
                            ['Fuel Type',    $auction['fuel_type']],
                            ['Transmission', $auction['transmission']],
                            ['Color',        $auction['color']],
                            ['Body Type',    $auction['body_type'] ?? null],
                            ['Engine',       $auction['engine_size'] ?? null],
                            ['VIN / Reg',    $auction['vin_number'] ?? null],
                        ];
                        foreach ($specs as [$label, $value]):
                            if (!$value) continue;
                        ?>
                        <tr class="specs-row">
                            <th class="specs-label" scope="row"><?= e($label) ?></th>
                            <td class="specs-value"><?= e($value) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($auction['condition_grade']): ?>
                        <tr class="specs-row specs-row-dekra">
                            <th class="specs-label" scope="row">DEKRA Sticker</th>
                            <td class="specs-value specs-value-dekra">
                                <?php $stk = dekraSticker($auction['condition_grade']); ?>
                                <?= dekraBadgeSVG($auction['condition_grade'], 64) ?>
                                <span style="color:<?= e($stk['color']) ?>;font-size:.82rem;display:block;margin-top:.25rem;">
                                    <?= e($stk['name']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Description tab -->
            <div class="vd-tab-panel" id="vd-description" hidden>
                <?php if (!empty($auction['description'])): ?>
                    <div class="vehicle-description">
                        <?= nl2br(e($auction['description'])) ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No description provided for this vehicle.</p>
                <?php endif; ?>
            </div>

            <!-- Service history tab -->
            <?php if ($auction['service_history']): ?>
            <div class="vd-tab-panel" id="vd-history" hidden>
                <div class="vehicle-description">
                    <?= nl2br(e($auction['service_history'])) ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.vehicle-details -->


        <!-- ════════════════════════════════════════════════
             VEHICLE FINANCE CALCULATOR
             ════════════════════════════════════════════════ -->
        <?php
            $vehiclePrice = (float)$auction['current_price'] > 0
                ? (float)$auction['current_price']
                : (float)$auction['start_price'];
        ?>
        <div class="finance-calculator" id="finance-calculator">
            <div class="finance-header">
                <h3 class="finance-title">
                    <i class="fas fa-calculator" aria-hidden="true"></i>
                    Vehicle Finance Calculator
                </h3>
                <p class="finance-subtitle">Estimate your monthly repayment. Minimum deposit: <strong>R10,500</strong> — increase your deposit to lower your instalments.</p>
            </div>

            <div class="finance-body">
                <div class="finance-row">
                    <label class="finance-label" for="fc-price">Vehicle Price (R)</label>
                    <input type="number" id="fc-price" class="finance-input" value="<?= round($vehiclePrice) ?>" min="1" step="1">
                </div>

                <div class="finance-row">
                    <label class="finance-label" for="fc-deposit">Deposit (R) <span class="finance-min">min R10,500 &mdash; increase any amount to lower instalments</span></label>
                    <input type="number" id="fc-deposit" class="finance-input" value="10500" min="10500" step="100">
                    <input type="range" id="fc-deposit-slider" class="finance-slider" min="10500" max="<?= max(round($vehiclePrice), 10500) ?>" value="10500" step="100">
                    <div class="finance-range-labels">
                        <span>R10,500</span>
                        <span id="fc-deposit-pct">0%</span>
                        <span>R<?= number_format(round($vehiclePrice)) ?></span>
                    </div>
                    <!-- Quick deposit buttons -->
                    <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.6rem;">
                        <?php
                        $quickDeposits = [10500, 15000, 20000, 25000, 30000];
                        $maxVP = round($vehiclePrice);
                        foreach ($quickDeposits as $qd):
                            if ($qd >= $maxVP) continue;
                        ?>
                        <button type="button" onclick="document.getElementById('fc-deposit').value=<?= $qd ?>;document.getElementById('fc-deposit-slider').value=<?= $qd ?>;document.getElementById('fc-calculate').click();"
                            style="padding:.3rem .7rem;border-radius:6px;font-size:.72rem;font-weight:700;cursor:pointer;border:1.5px solid #c8d8ea;background:#f1f5f9;color:#005DDB;transition:all .15s;"
                            onmouseover="this.style.borderColor='#005DDB';this.style.background='#005DDB';this.style.color='#fff'"
                            onmouseout="this.style.borderColor='#c8d8ea';this.style.background='#f1f5f9';this.style.color='#005DDB'">
                            R<?= number_format($qd) ?>
                        </button>
                        <?php endforeach; ?>
                        <span style="font-size:.7rem;color:#6a7f96;align-self:center;margin-left:.25rem;">
                            <i class="fas fa-bolt" style="color:#e8a000;"></i> Pay more, save more
                        </span>
                    </div>
                </div>

                <div class="finance-row-grid">
                    <div>
                        <label class="finance-label" for="fc-rate">Interest Rate (%)</label>
                        <input type="number" id="fc-rate" class="finance-input" value="11.5" min="0" max="30" step="0.25">
                    </div>
                    <div>
                        <label class="finance-label" for="fc-term">Loan Term (months)</label>
                        <select id="fc-term" class="finance-input">
                            <option value="12">12 months</option>
                            <option value="24">24 months</option>
                            <option value="36">36 months</option>
                            <option value="48">48 months</option>
                            <option value="54">54 months</option>
                            <option value="60" selected>60 months</option>
                            <option value="72">72 months</option>
                        </select>
                    </div>
                </div>

                <div class="finance-result" id="fc-result">
                    <div class="finance-result-label">Estimated Monthly Repayment</div>
                    <div class="finance-result-amount" id="fc-monthly">R0.00</div>
                    <div class="finance-result-details">
                        <div><span>Loan Amount:</span> <strong id="fc-loan">R0</strong></div>
                        <div><span>Total Interest:</span> <strong id="fc-interest">R0</strong></div>
                        <div><span>Total Repayment:</span> <strong id="fc-total">R0</strong></div>
                    </div>
                </div>

                <button type="button" class="finance-calc-btn" id="fc-calculate">
                    <i class="fas fa-calculator" aria-hidden="true"></i> Calculate
                </button>

                <p class="finance-disclaimer">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    This is an estimate only. Actual rates depend on your credit profile and lender terms. A minimum deposit of R10,500 is required before any payment can be processed.
                </p>
            </div>
        </div>

    </div><!-- /.auction-left-col -->


    <!-- ── RIGHT COLUMN: Bid Panel + Live Feed ────────────── -->
    <div class="auction-right-col">

        <!-- Sticky bid panel wrapper -->
        <div class="bid-panel-sticky" id="bid-panel-sticky">

        <!-- ════════════════════════════════════════════════
             BID PANEL
             ════════════════════════════════════════════════ -->
        <div class="bid-panel" id="bid-panel"
             data-auction-id="<?= $auctionId ?>"
             data-status="<?= e($auction['status']) ?>">

            <!-- Vehicle title (compact) -->
            <h1 class="bid-panel-title"><?= e(strtoupper($vehicleTitle)) ?></h1>

            <!-- Bank repo + DEKRA tags -->
            <div class="bp-vehicle-tags">
                <span class="bp-tag bp-tag-repo">
                    <i class="fas fa-university" aria-hidden="true"></i> BANK REPO
                </span>
                <span class="bp-tag bp-tag-repo">
                    <i class="fas fa-gavel" aria-hidden="true"></i> LIQUIDATED
                </span>
                <?php if ($auction['condition_grade']): ?>
                <?php $stk = dekraSticker($auction['condition_grade']); ?>
                <span class="bp-tag" style="background:<?= e($stk['bg']) ?>;color:<?= e($stk['color']) ?>;border-color:<?= e($stk['color']) ?>">
                    <i class="fas <?= e($stk['icon']) ?>" aria-hidden="true"></i>
                    <?= e(strtoupper($stk['name'])) ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- DEKRA Badge (prominent) -->
            <?php if ($auction['condition_grade']): ?>
            <div class="bp-dekra-badge" title="<?= e($stk['name'] ?? '') ?>">
                <?= dekraBadgeSVG($auction['condition_grade'], 80) ?>
                <div class="bp-dekra-info">
                    <strong><?= e(strtoupper($stk['name'] ?? '')) ?></strong>
                    <?php if ($auction['condition_grade'] === 'A'): ?>
                        <span>This vehicle has passed its roadworthy test &amp; is ready to go.</span>
                    <?php elseif ($auction['condition_grade'] === 'B'): ?>
                        <span>No roadworthy issues identified &mdash; will pass a roadworthy test.</span>
                    <?php else: ?>
                        <span>May require specific repairs to pass a roadworthy test. See DEKRA report.</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Auction status row -->
            <div class="bid-panel-status-row">
                <?php if ($isActive): ?>
                    <span class="badge badge-active">
                        <span class="live-dot" aria-hidden="true"></span> Live
                    </span>
                    <?php if ($auction['end_time']): ?>
                        <div class="bid-panel-timer"
                             data-countdown="<?= e($auction['end_time']) ?>"
                             id="auction-countdown"
                             aria-label="Time remaining"
                             aria-live="polite">
                            <?= date('d M Y H:i', strtotime($auction['end_time'])) ?>
                        </div>
                    <?php endif; ?>
                <?php elseif ($isUpcoming): ?>
                    <span class="badge badge-upcoming">
                        <i class="fas fa-clock" aria-hidden="true"></i> Upcoming
                    </span>
                    <?php if ($auction['start_time']): ?>
                        <div class="bid-panel-timer"
                             data-countdown="<?= e($auction['start_time']) ?>"
                             data-label="Starts in"
                             id="auction-countdown"
                             aria-label="Auction starts">
                            Starts <?= date('d M Y H:i', strtotime($auction['start_time'])) ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="badge badge-closed">
                        <i class="fas fa-lock" aria-hidden="true"></i> Closed
                    </span>
                    <?php if ($auction['end_time']): ?>
                        <span class="bid-panel-closed-date">
                            Ended <?= date('d M Y H:i', strtotime($auction['end_time'])) ?>
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Current price (big) -->
            <div class="bid-panel-price-block">
                <div class="bid-panel-price-label">
                    <?= $bidCount > 0 ? 'Current Bid' : 'Starting Price' ?>
                </div>
                <div class="bid-panel-price"
                     id="bp-current-price"
                     data-raw="<?= $currentPrice ?>"
                     aria-live="polite"
                     aria-label="Current bid amount">
                    <?= formatRand($currentPrice) ?>
                </div>
                <div class="bid-panel-bid-count" id="bp-bid-count" aria-live="polite">
                    <?= $bidCount ?> bid<?= $bidCount !== 1 ? 's' : '' ?> placed
                </div>
            </div>

            <!-- Reserve price -->
            <?php if ($reservePrice > 0): ?>
            <div class="bid-panel-reserve" id="bp-reserve-row"
                 aria-label="Reserve price status">
                <span class="bid-panel-reserve-label">Reserve:</span>
                <span class="bid-panel-reserve-price"><?= formatRand($reservePrice) ?></span>
                <span class="bid-panel-reserve-status <?= $reserveMet ? 'reserve-status-met' : 'reserve-status-not-met' ?>"
                      id="bp-reserve-status">
                    <?php if ($reserveMet): ?>
                        <i class="fas fa-circle-check" aria-hidden="true"></i> Met
                    <?php else: ?>
                        <i class="fas fa-circle-xmark" aria-hidden="true"></i> Not Met
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>

            <!-- Min increment info -->
            <?php if ($isActive): ?>
            <div class="bid-panel-increment" aria-label="Minimum bid increment">
                <i class="fas fa-circle-info" aria-hidden="true"></i>
                Minimum increment: <strong><?= formatRand($minIncrement) ?></strong>
                &nbsp;· Next min bid:
                <strong id="bp-min-next"><?= formatRand($minNextBid) ?></strong>
            </div>
            <?php endif; ?>

            <!-- My last bid (shown if user has bid) -->
            <?php if ($myLastBid): ?>
            <div class="bid-panel-my-bid <?= $myLastBid['is_winning'] ? 'my-bid-winning' : 'my-bid-outbid' ?>"
                 id="bp-my-bid-row"
                 aria-live="polite">
                <?php if ($myLastBid['is_winning']): ?>
                    <i class="fas fa-trophy" aria-hidden="true"></i>
                    <span>You're winning! Your bid: <strong><?= formatRand((float)$myLastBid['amount']) ?></strong></span>
                <?php else: ?>
                    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                    <span>You've been outbid. Your last bid: <strong><?= formatRand((float)$myLastBid['amount']) ?></strong></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ── BID INPUT AREA ───────────────────────── -->
            <?php if ($isActive): ?>

                <?php if ($canBid): ?>
                <!-- Verified bidder — show bid form -->
                <div class="bid-input-area" id="bid-input-area">

                    <!-- Quick bid buttons -->
                    <div class="quick-bid-row" aria-label="Quick bid amounts">
                        <?php
                        $increments = [1, 2, 5, 10]; // multiples of minIncrement
                        foreach ($increments as $mult):
                            $quickAmt = $currentPrice + ($minIncrement * $mult);
                        ?>
                        <button type="button"
                                class="quick-bid-btn"
                                data-amount="<?= $quickAmt ?>"
                                aria-label="Quick bid <?= formatRand($quickAmt) ?>">
                            +<?= $mult === 1 ? 'Min' : formatRand($minIncrement * $mult) ?>
                            <span class="quick-bid-total"><?= formatRand($quickAmt) ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="bid-input-group">
                        <label for="bid-amount" class="bid-input-label">Your bid amount (R)</label>
                        <div class="bid-input-wrap">
                            <span class="bid-input-prefix" aria-hidden="true">R</span>
                            <input type="number"
                                   id="bid-amount"
                                   class="bid-amount-input"
                                   placeholder="<?= number_format($minNextBid, 0, '.', '') ?>"
                                   min="<?= $minNextBid ?>"
                                   step="<?= $minIncrement ?>"
                                   data-min="<?= $minNextBid ?>"
                                   data-increment="<?= $minIncrement ?>"
                                   aria-describedby="bid-amount-hint"
                                   autocomplete="off">
                        </div>
                        <span class="bid-input-hint" id="bid-amount-hint">
                            Minimum bid: <?= formatRand($minNextBid) ?>
                        </span>
                    </div>

                    <button type="button"
                            class="btn btn-primary btn-lg w-100 btn-place-bid"
                            id="btn-place-bid"
                            data-auction-id="<?= $auctionId ?>">
                        <span class="btn-text">
                            <i class="fas fa-gavel" aria-hidden="true"></i> Place Bid
                        </span>
                        <span class="btn-spinner" hidden>
                            <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Placing bid…
                        </span>
                    </button>

                    <div id="bid-result" class="bid-result" role="alert" aria-live="assertive" hidden></div>

                    <p class="bid-terms-note">
                        <i class="fas fa-scale-balanced" aria-hidden="true"></i>
                        By bidding, you agree to our
                        <a href="#" target="_blank" rel="noopener noreferrer">terms &amp; conditions</a>.
                        All bids are binding and voetstoots.
                    </p>
                </div>

                <?php elseif (!$currentUser): ?>
                <!-- Not logged in -->
                <div class="bid-locked bid-locked-auth">
                    <i class="fas fa-lock bid-locked-icon" aria-hidden="true"></i>
                    <h3 class="bid-locked-title">Login to Bid</h3>
                    <p class="bid-locked-text">You need an account to place bids on Auction Hub Group.</p>
                    <a href="login.php?tab=login&redirect=auction.php%3Fid%3D<?= $auctionId ?>"
                       class="btn btn-primary bid-locked-btn">
                        <i class="fas fa-right-to-bracket" aria-hidden="true"></i> Login Now
                    </a>
                    <a href="login.php?tab=register" class="btn btn-outline bid-locked-btn-sec">
                        Register Free
                    </a>
                </div>

                <?php elseif ($currentUser['bidder_status'] === 'pending_approval'): ?>
                <!-- Awaiting POP review -->
                <div class="bid-locked bid-locked-pending">
                    <i class="fas fa-hourglass-half bid-locked-icon" aria-hidden="true"></i>
                    <h3 class="bid-locked-title">Approval Pending</h3>
                    <p class="bid-locked-text">Your proof of payment is under review. You'll receive an email once your bidder number is assigned.</p>
                    <a href="user_dashboard.php" class="btn btn-outline bid-locked-btn">View My Dashboard</a>
                </div>

                <?php elseif ($currentUser['bidder_status'] === 'pending_payment'): ?>
                <!-- POP uploaded, not yet submitted -->
                <div class="bid-locked bid-locked-payment">
                    <i class="fas fa-file-invoice bid-locked-icon" aria-hidden="true"></i>
                    <h3 class="bid-locked-title">Upload Proof of Payment</h3>
                    <p class="bid-locked-text">
                        Upload your EFT proof of payment to activate bidding.
                        Pay <strong>R10,000</strong> and request banking details from your dashboard.
                    </p>
                    <a href="user_dashboard.php?tab=register" class="btn btn-primary bid-locked-btn">
                        <i class="fas fa-upload" aria-hidden="true"></i> Upload POP
                    </a>
                </div>

                <?php else: ?>
                <!-- Registered but not yet paid -->
                <div class="bid-locked bid-locked-register">
                    <i class="fas fa-id-card bid-locked-icon" aria-hidden="true"></i>
                    <h3 class="bid-locked-title">Activate Your Bidder Account</h3>
                    <p class="bid-locked-text">
                        Pay the <strong>R10,000 refundable registration deposit</strong> and upload your
                        proof of payment to unlock bidding on all auctions.
                    </p>
                    <div class="bid-locked-bank">
                        <p style="margin:0.5rem 0"><i class="fas fa-info-circle"></i> Request banking details from your dashboard to make the EFT payment.</p>
                    </div>
                    <a href="user_dashboard.php?tab=register" class="btn btn-primary bid-locked-btn">
                        <i class="fas fa-upload" aria-hidden="true"></i> Upload Proof of Payment
                    </a>
                </div>
                <?php endif; ?>

            <?php elseif ($isUpcoming): ?>
            <!-- Auction not yet started -->
            <div class="bid-locked bid-locked-upcoming">
                <i class="fas fa-calendar-alt bid-locked-icon" aria-hidden="true"></i>
                <h3 class="bid-locked-title">Auction Not Yet Open</h3>
                <p class="bid-locked-text">
                    Bidding opens <?= $auction['start_time'] ? date('d M Y \a\t H:i', strtotime($auction['start_time'])) : 'soon' ?>.
                    <?php if (!$currentUser): ?>
                        Register now so you're ready the moment it goes live.
                    <?php else: ?>
                        We'll email you when this auction opens.
                    <?php endif; ?>
                </p>
                <?php if (!$currentUser): ?>
                <a href="login.php?tab=register" class="btn btn-primary bid-locked-btn">
                    Register Free
                </a>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <!-- Auction closed -->
            <div class="bid-locked bid-locked-closed">
                <i class="fas fa-lock bid-locked-icon" aria-hidden="true"></i>
                <h3 class="bid-locked-title">Auction Closed</h3>
                <?php if (!empty($bidsRecent)): ?>
                    <?php $winner = $bidsRecent[0]; // first = highest (ordered DESC) ?>
                    <p class="bid-locked-text">
                        Sold for <strong class="bid-closed-final"><?= formatRand((float)$winner['amount']) ?></strong>
                        to bidder <strong><?= e($winner['bidder_number'] ?: 'Anonymous') ?></strong>.
                    </p>
                <?php else: ?>
                    <p class="bid-locked-text">This auction ended with no bids.</p>
                <?php endif; ?>
                <a href="auctions.php" class="btn btn-outline bid-locked-btn">
                    Browse More Auctions
                </a>
            </div>
            <?php endif; ?>
            <!-- END BID PANEL MAIN CONTENT -->

        </div><!-- /.bid-panel -->

        <!-- ════════════════════════════════════════════════
             LIVE BID FEED
             ════════════════════════════════════════════════ -->
        <div class="live-feed-panel" id="live-feed-panel">
            <div class="live-feed-header">
                <h2 class="live-feed-title">
                    <i class="fas fa-bars-staggered" aria-hidden="true"></i>
                    Bid History
                </h2>
                <?php if ($isActive): ?>
                <span class="live-feed-badge" aria-live="polite">
                    <span class="live-dot live-dot-sm" aria-hidden="true"></span>
                    Live
                </span>
                <?php endif; ?>
            </div>

            <div class="live-feed-list"
                 id="live-feed-list"
                 role="log"
                 aria-label="Bid history"
                 aria-live="polite">
                <?php if (empty($bidsRecent)): ?>
                    <div class="live-feed-empty">
                        <i class="fas fa-gavel" aria-hidden="true"></i>
                        <span>No bids yet — be the first!</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($bidsRecent as $b):
                        $diff = time() - strtotime($b['created_at']);
                        $ago  = $diff < 60 ? $diff.'s ago' : ($diff < 3600 ? floor($diff/60).'m ago' : floor($diff/3600).'h ago');
                    ?>
                    <div class="feed-item <?= $b['is_winning'] ? 'feed-item-winning' : '' ?>"
                         data-bid-id="<?= $b['id'] ?>">
                        <div class="feed-item-avatar" aria-hidden="true">
                            <?= strtoupper(substr($b['bidder_name'] ?? $b['bidder_number'] ?? 'B', 0, 1)) ?>
                        </div>
                        <div class="feed-item-info">
                            <span class="feed-item-bidder">
                                <?= e($b['bidder_number'] ?: 'Bidder') ?>
                                <?php if ($b['is_winning']): ?>
                                    <span class="feed-winning-chip">Winning</span>
                                <?php endif; ?>
                            </span>
                            <span class="feed-item-amount"><?= formatRand((float)$b['amount']) ?></span>
                        </div>
                        <div class="feed-item-time" title="<?= e($b['created_at']) ?>"><?= $ago ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($bidCount > 20): ?>
            <div class="live-feed-footer">
                <span class="text-muted"><?= $bidCount - 20 ?> earlier bid<?= ($bidCount-20)!==1?'s':'' ?> not shown</span>
            </div>
            <?php endif; ?>
        </div><!-- /.live-feed-panel -->

        </div><!-- /.bid-panel-sticky -->
    </div><!-- /.auction-right-col -->

</div><!-- /.auction-layout -->
</main>


<!-- ══════════════════════════════════════════════════════════
     RELATED VEHICLES
     ══════════════════════════════════════════════════════════ -->
<?php if (!empty($related)): ?>
<section class="section related-auctions" aria-labelledby="related-heading">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title" id="related-heading">
                More <?= e($auction['make_name']) ?> Auctions
            </h2>
            <a href="auctions.php?make_id=<?= (int)$auction['make_id'] ?>" class="section-link">
                View all <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
        <div class="related-grid">
            <?php foreach ($related as $r):
                $rPrice = (float)$r['current_price'] > 0 ? (float)$r['current_price'] : (float)$r['start_price'];
                $rImg   = !empty($r['image_url']) ? e(cldImg($r['image_url'], CLD_CARD)) : 'assets/no-image.jpg';
                $rTitle = e(($r['year'] ? $r['year'].' ' : '') . $r['make_name'] . ' ' . $r['item_name']);
            ?>
            <a href="auction.php?id=<?= $r['id'] ?>" class="related-card">
                <div class="related-card-img-wrap">
                    <img src="<?= $rImg ?>" alt="<?= $rTitle ?>" loading="lazy" width="300" height="200">
                    <span class="badge <?= $r['status']==='active'?'badge-active':'badge-upcoming' ?> related-badge">
                        <?= $r['status'] === 'active' ? 'Live' : 'Upcoming' ?>
                    </span>
                </div>
                <div class="related-card-body">
                    <h3 class="related-card-title"><?= $rTitle ?></h3>
                    <div class="related-card-price"><?= formatRand($rPrice) ?></div>
                    <?php if ($r['status']==='active' && $r['end_time']): ?>
                        <div class="related-card-timer"
                             data-countdown="<?= e($r['end_time']) ?>">
                            <?= date('d M H:i', strtotime($r['end_time'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php require_once 'footer.php'; ?>

<!-- ══════════════════════════════════════════════════════════
     AUCTION PAGE INLINE JS
     Gallery, lightbox, vehicle detail tabs, bid form, polling
     ══════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    const AUCTION_ID   = <?= $auctionId ?>;
    const IS_ACTIVE    = <?= $isActive ? 'true' : 'false' ?>;
    const MIN_INC      = <?= $minIncrement ?>;

    // ── Gallery thumbnails ─────────────────────────────────
    const mainImg  = document.getElementById('gallery-main-img');
    const thumbs   = document.querySelectorAll('.gallery-thumb');
    let   activeIdx = 0;

    function setThumb(idx) {
        if (!thumbs[idx]) return;
        const src = thumbs[idx].dataset.src;
        if (mainImg) mainImg.src = src;
        thumbs.forEach((t, i) => {
            t.classList.toggle('gallery-thumb-active', i === idx);
            t.setAttribute('aria-pressed', i === idx ? 'true' : 'false');
        });
        activeIdx = idx;
    }

    thumbs.forEach((t, i) => t.addEventListener('click', () => setThumb(i)));

    document.getElementById('gallery-prev')?.addEventListener('click', () =>
        setThumb((activeIdx - 1 + thumbs.length) % thumbs.length));
    document.getElementById('gallery-next')?.addEventListener('click', () =>
        setThumb((activeIdx + 1) % thumbs.length));

    // ── Lightbox ───────────────────────────────────────────
    const overlay   = document.getElementById('lightbox-overlay');
    const lbImg     = document.getElementById('lightbox-img');
    const lbCounter = document.getElementById('lightbox-counter');
    const allSrcs   = Array.from(thumbs).map(t => t.dataset.src);
    if (!allSrcs.length && mainImg) allSrcs.push(mainImg.src);
    let lbIdx = 0;

    function openLightbox(idx) {
        if (!overlay || !lbImg) return;
        lbIdx = idx;
        lbImg.src = allSrcs[lbIdx] || (mainImg ? mainImg.src : '');
        lbImg.alt = document.title;
        if (lbCounter) lbCounter.textContent = allSrcs.length > 1 ? (lbIdx+1) + ' / ' + allSrcs.length : '';
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        document.getElementById('lightbox-close')?.focus();
    }

    function closeLightbox() {
        if (overlay) { overlay.hidden = true; document.body.style.overflow = ''; }
    }

    document.querySelectorAll('[data-lightbox-trigger]')
        .forEach(el => el.addEventListener('click', () => openLightbox(activeIdx)));
    document.getElementById('lightbox-close')?.addEventListener('click', closeLightbox);
    overlay?.addEventListener('click', e => { if (e.target === overlay) closeLightbox(); });
    document.getElementById('lightbox-prev')?.addEventListener('click', () => {
        lbIdx = (lbIdx - 1 + allSrcs.length) % allSrcs.length;
        openLightbox(lbIdx);
    });
    document.getElementById('lightbox-next')?.addEventListener('click', () => {
        lbIdx = (lbIdx + 1) % allSrcs.length;
        openLightbox(lbIdx);
    });
    document.addEventListener('keydown', e => {
        if (overlay && !overlay.hidden) {
            if (e.key === 'Escape')     closeLightbox();
            if (e.key === 'ArrowLeft')  document.getElementById('lightbox-prev')?.click();
            if (e.key === 'ArrowRight') document.getElementById('lightbox-next')?.click();
        }
    });

    // ── Vehicle detail tabs ────────────────────────────────
    document.querySelectorAll('.vd-tab').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.vd-tab').forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            document.querySelectorAll('.vd-tab-panel').forEach(p => p.hidden = true);
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');
            const target = document.getElementById(this.dataset.target);
            if (target) target.hidden = false;
        });
    });

    // ── Quick bid buttons ──────────────────────────────────
    const bidInput = document.getElementById('bid-amount');
    document.querySelectorAll('.quick-bid-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            if (bidInput) {
                bidInput.value = parseFloat(this.dataset.amount).toFixed(2);
                bidInput.dispatchEvent(new Event('input'));
            }
            document.querySelectorAll('.quick-bid-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // ── Bid input validation hint ──────────────────────────
    if (bidInput) {
        bidInput.addEventListener('input', function () {
            const val = parseFloat(this.value);
            const min = parseFloat(this.dataset.min);
            const hint = document.getElementById('bid-amount-hint');
            if (isNaN(val)) return;
            if (val < min) {
                this.classList.add('input-error');
                if (hint) hint.textContent = 'Minimum bid is R ' + min.toLocaleString('en-ZA', {minimumFractionDigits:2});
            } else {
                this.classList.remove('input-error');
                if (hint) hint.textContent = 'Your bid: R ' + val.toLocaleString('en-ZA', {minimumFractionDigits:2});
            }
        });
    }

    // ── Place bid ──────────────────────────────────────────
    const bidBtn    = document.getElementById('btn-place-bid');
    const bidResult = document.getElementById('bid-result');

    function showBidResult(msg, type) {
        if (!bidResult) return;
        bidResult.textContent = msg;
        bidResult.className   = 'bid-result bid-result-' + type;
        bidResult.hidden      = false;
        bidResult.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    if (bidBtn && bidInput) {
        bidBtn.addEventListener('click', async function () {
            const amount = parseFloat(bidInput.value);
            const min    = parseFloat(bidInput.dataset.min);
            if (isNaN(amount) || amount < min) {
                showBidResult('Please enter a valid bid of at least R ' + min.toLocaleString('en-ZA', {minimumFractionDigits:2}), 'error');
                bidInput.focus();
                return;
            }

            // Disable button, show spinner
            this.disabled = true;
            this.querySelector('.btn-text').hidden  = true;
            this.querySelector('.btn-spinner').hidden = false;
            bidResult.hidden = true;

            try {
                const fd = new FormData();
                fd.append('auction_id', AUCTION_ID);
                fd.append('amount',     amount.toFixed(2));
                const res  = await fetch('api.php?action=place_bid', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    showBidResult('✓ Bid placed successfully: R ' + amount.toLocaleString('en-ZA', {minimumFractionDigits:2}), 'success');
                    bidInput.value = '';
                    document.querySelectorAll('.quick-bid-btn').forEach(b => b.classList.remove('active'));
                    // Trigger immediate poll update
                    if (typeof pollBids === 'function') pollBids();
                } else {
                    showBidResult(data.message || 'Bid failed. Please try again.', 'error');
                }
            } catch(err) {
                showBidResult('Network error. Check your connection and try again.', 'error');
            } finally {
                this.disabled = false;
                this.querySelector('.btn-text').hidden  = false;
                this.querySelector('.btn-spinner').hidden = true;
            }
        });

        // Allow Enter key in bid input
        bidInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') bidBtn.click();
        });
    }

    // ── Live bid polling (every 5s on active auctions) ─────
    if (IS_ACTIVE) {
        let lastBidId  = <?= !empty($bidsRecent) ? (int)$bidsRecent[0]['id'] : 0 ?>;
        let pollInFlight = false;

        async function pollBids() {
            if (pollInFlight) return; // avoid overlapping requests double-appending the same bid
            pollInFlight = true;
            try {
                const res  = await fetch(`api.php?action=updates&id=${AUCTION_ID}&last_bid_id=${lastBidId}`);
                const data = await res.json();
                if (!data.success) return;

                // Update price display
                const priceEl = document.getElementById('bp-current-price');
                if (priceEl && data.current_price) {
                    priceEl.textContent = 'R ' + parseFloat(data.current_price).toLocaleString('en-ZA', {minimumFractionDigits:2});
                }

                // Update bid count
                const countEl = document.getElementById('bp-bid-count');
                if (countEl && data.bid_count !== undefined) {
                    countEl.textContent = data.bid_count + ' bid' + (data.bid_count !== 1 ? 's' : '') + ' placed';
                }

                // Update min next bid
                const minEl = document.getElementById('bp-min-next');
                if (minEl && data.min_next_bid) {
                    minEl.textContent = 'R ' + parseFloat(data.min_next_bid).toLocaleString('en-ZA', {minimumFractionDigits:2});
                    if (bidInput) {
                        bidInput.min            = data.min_next_bid;
                        bidInput.dataset.min    = data.min_next_bid;
                        bidInput.placeholder    = Math.ceil(data.min_next_bid).toString();
                    }
                }

                // Update quick bid buttons
                if (data.current_price) {
                    document.querySelectorAll('.quick-bid-btn').forEach((btn, i) => {
                        const mult = [1,2,5,10][i] || 1;
                        const newAmt = parseFloat(data.current_price) + (MIN_INC * mult);
                        btn.dataset.amount = newAmt;
                        btn.querySelector('.quick-bid-total').textContent =
                            'R ' + newAmt.toLocaleString('en-ZA', {minimumFractionDigits:2});
                    });
                }

                // Append new bids to feed
                if (data.new_bids && data.new_bids.length) {
                    const feed = document.getElementById('live-feed-list');
                    const empty = feed?.querySelector('.live-feed-empty');
                    if (empty) empty.remove();

                    data.new_bids.forEach(bid => {
                        if (feed?.querySelector(`[data-bid-id="${bid.id}"]`)) return; // already rendered

                        const item = document.createElement('div');
                        item.className = 'feed-item feed-item-new' + (bid.is_winning ? ' feed-item-winning' : '');
                        item.dataset.bidId = bid.id;
                        item.innerHTML = `
                            <div class="feed-item-avatar">${(bid.bidder_number||'B').charAt(0).toUpperCase()}</div>
                            <div class="feed-item-info">
                                <span class="feed-item-bidder">${bid.bidder_number || 'Bidder'}
                                    ${bid.is_winning ? '<span class="feed-winning-chip">Winning</span>' : ''}
                                </span>
                                <span class="feed-item-amount">R ${parseFloat(bid.amount).toLocaleString('en-ZA',{minimumFractionDigits:2})}</span>
                            </div>
                            <div class="feed-item-time">just now</div>
                        `;
                        feed?.prepend(item);
                        // Remove winning from previous top
                        if (bid.is_winning) {
                            feed?.querySelectorAll('.feed-item-winning').forEach((el, i) => {
                                if (i > 0) el.classList.remove('feed-item-winning');
                            });
                        }
                        setTimeout(() => item.classList.remove('feed-item-new'), 2000);
                        lastBidId = Math.max(lastBidId, bid.id);
                    });
                }

                // Handle auction close
                if (data.status === 'closed') {
                    clearInterval(pollTimer);
                    const countdown = document.getElementById('auction-countdown');
                    if (countdown) countdown.textContent = 'CLOSED';
                    const panel = document.getElementById('bid-panel');
                    if (panel) panel.dataset.status = 'closed';
                }

            } catch(err) { /* silent fail — polling resumes next interval */ }
            finally { pollInFlight = false; }
        }

        const pollTimer = setInterval(pollBids, 5000);
        pollBids(); // immediate first call
    }

    // ── Finance Calculator ────────────────────────────────────
    const fcPrice   = document.getElementById('fc-price');
    const fcDeposit = document.getElementById('fc-deposit');
    const fcSlider  = document.getElementById('fc-deposit-slider');
    const fcRate    = document.getElementById('fc-rate');
    const fcTerm    = document.getElementById('fc-term');
    const fcBtn     = document.getElementById('fc-calculate');
    const fcPctEl   = document.getElementById('fc-deposit-pct');
    const MIN_DEPOSIT = 10500;

    function calcFinance() {
        const price   = parseFloat(fcPrice.value) || 0;
        let deposit   = parseFloat(fcDeposit.value) || 0;
        if (deposit < MIN_DEPOSIT) deposit = MIN_DEPOSIT;
        if (deposit >= price) deposit = price - 1;
        const rate    = (parseFloat(fcRate.value) || 0) / 100 / 12;
        const months  = parseInt(fcTerm.value) || 60;
        const loan    = price - deposit;

        let monthly = 0, totalInterest = 0, totalRepay = 0;
        if (loan > 0 && rate > 0) {
            monthly = loan * rate * Math.pow(1 + rate, months) / (Math.pow(1 + rate, months) - 1);
            totalRepay    = monthly * months;
            totalInterest = totalRepay - loan;
        } else if (loan > 0) {
            monthly    = loan / months;
            totalRepay = loan;
        }

        const fmt = v => 'R' + v.toLocaleString('en-ZA', {minimumFractionDigits:2, maximumFractionDigits:2});
        document.getElementById('fc-monthly').textContent  = fmt(monthly);
        document.getElementById('fc-loan').textContent     = fmt(loan);
        document.getElementById('fc-interest').textContent = fmt(totalInterest);
        document.getElementById('fc-total').textContent    = fmt(totalRepay);
        document.getElementById('fc-result').classList.add('finance-result-visible');
    }

    function syncSlider() {
        const price = parseFloat(fcPrice.value) || 0;
        const dep   = parseFloat(fcDeposit.value) || MIN_DEPOSIT;
        const pct   = price > 0 ? Math.round((dep / price) * 100) : 0;
        fcPctEl.textContent = pct + '%';
        fcSlider.max = Math.max(Math.round(price), MIN_DEPOSIT);
        fcSlider.value = dep;
    }

    if (fcBtn) {
        fcBtn.addEventListener('click', calcFinance);
        fcDeposit.addEventListener('input', () => { syncSlider(); });
        fcSlider.addEventListener('input', () => {
            fcDeposit.value = fcSlider.value;
            syncSlider();
        });
        fcPrice.addEventListener('input', syncSlider);
        // Auto-calculate on load
        syncSlider();
        calcFinance();
    }

})();
</script>
