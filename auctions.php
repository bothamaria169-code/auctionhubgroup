<?php
// ============================================================
// AUCTION HUB GROUP — auctions.php
// Browse, filter, sort and paginate all active/upcoming auctions
// ============================================================

require_once 'config.php';
require_once 'auth.php';

// Resolve current user (optional on this page)
$token       = getTokenFromRequest();
$currentUser = $token ? verifyJWT($token) : null;
if ($currentUser) syncSession($currentUser);

// ── FILTER & SORT PARAMETERS ──────────────────────────────────
$allowedStatuses = ['active', 'upcoming', 'closed', 'all', 'sold'];
$allowedSorts    = ['ending_soon', 'newest', 'price_low', 'price_high', 'most_bids'];
$allowedFuels    = ['Petrol', 'Diesel', 'Hybrid', 'Electric'];
$allowedTrans    = ['Automatic', 'Manual'];

$filterStatus   = in_array($_GET['status']   ?? '', $allowedStatuses) ? $_GET['status']   : 'active';
$filterSort     = in_array($_GET['sort']     ?? '', $allowedSorts)    ? $_GET['sort']     : 'ending_soon';
$filterMakeId   = (int)($_GET['make_id']     ?? 0);
$filterYearMin  = (int)($_GET['year_min']    ?? 0);
$filterYearMax  = (int)($_GET['year_max']    ?? 0);
$filterPriceMin = (float)($_GET['price_min'] ?? 0);
$filterPriceMax = (float)($_GET['price_max'] ?? 0);
$filterFuel     = in_array($_GET['fuel']     ?? '', $allowedFuels)    ? $_GET['fuel']     : '';
$filterTrans    = in_array($_GET['trans']    ?? '', $allowedTrans)    ? $_GET['trans']    : '';
$filterSearch   = trim(strip_tags($_GET['search'] ?? ''));
$perPage        = 20;
$page           = max(1, (int)($_GET['page'] ?? 1));
$offset         = ($page - 1) * $perPage;

// ── BUILD QUERY ───────────────────────────────────────────────
$conditions = [];
$params     = [];

if ($filterStatus === 'all') {
    $conditions[] = "a.status IN ('active','upcoming','closed')";
} elseif ($filterStatus === 'sold') {
    $conditions[] = "a.is_sold = 1";
} else {
    $conditions[] = "a.status = ?";
    $params[]     = $filterStatus;
    // Live/upcoming lists show only lots still for sale
    if ($filterStatus !== 'closed') $conditions[] = "a.is_sold = 0";
}

if ($filterMakeId > 0) {
    $conditions[] = "a.make_id = ?";
    $params[]     = $filterMakeId;
}
if ($filterYearMin > 0) {
    $conditions[] = "a.year >= ?";
    $params[]     = $filterYearMin;
}
if ($filterYearMax > 0) {
    $conditions[] = "a.year <= ?";
    $params[]     = $filterYearMax;
}
if ($filterPriceMin > 0) {
    $conditions[] = "GREATEST(a.current_price, a.start_price) >= ?";
    $params[]     = $filterPriceMin;
}
if ($filterPriceMax > 0) {
    $conditions[] = "GREATEST(a.current_price, a.start_price) <= ?";
    $params[]     = $filterPriceMax;
}
if ($filterFuel) {
    $conditions[] = "a.fuel_type = ?";
    $params[]     = $filterFuel;
}
if ($filterTrans) {
    $conditions[] = "a.transmission = ?";
    $params[]     = $filterTrans;
}
if ($filterSearch !== '') {
    $conditions[] = "(a.item_name LIKE ? OR m.name LIKE ? OR a.description LIKE ?)";
    $like         = '%' . $filterSearch . '%';
    $params[]     = $like;
    $params[]     = $like;
    $params[]     = $like;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$orderMap = [
    'ending_soon' => "FIELD(a.status,'active','upcoming','closed'), a.end_time ASC",
    'newest'      => "a.created_at DESC",
    'price_low'   => "GREATEST(a.current_price, a.start_price) ASC",
    'price_high'  => "GREATEST(a.current_price, a.start_price) DESC",
    'most_bids'   => "bid_count DESC",
];
$orderBy = $orderMap[$filterSort];

// Count total for pagination
$countSql = "
    SELECT COUNT(DISTINCT a.id)
    FROM auctions a
    LEFT JOIN makes m ON m.id = a.make_id
    $where
";
try {
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalResults = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    $totalResults = 0;
}
$totalPages = max(1, (int)ceil($totalResults / $perPage));
$page       = min($page, $totalPages);

// Fetch auction rows
$auctions = [];
$listSql  = "
    SELECT a.id, a.item_name, a.make_id, a.year, a.mileage, a.fuel_type, a.transmission,
           a.color, a.current_price, a.start_price, a.reserve_price, a.min_increment,
           a.status, a.start_time, a.end_time, a.image_url, a.condition_grade, a.is_sold, a.final_price, a.sold_date,
           m.name AS make_name,
           COUNT(DISTINCT b.id) AS bid_count
    FROM auctions a
    LEFT JOIN makes m ON m.id = a.make_id
    LEFT JOIN bids  b ON b.auction_id = a.id
    $where
    GROUP BY a.id
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
";
try {
    $stmt     = $pdo->prepare($listSql);
    $stmt->execute($params);
    $auctions = $stmt->fetchAll();
} catch (Exception $e) {
    $auctions = [];
}

// Filter options: makes list
$makes = [];
try {
    $makes = $pdo->query("SELECT id, name FROM makes ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {}

// Year range from DB
$yearRange = ['min' => 2000, 'max' => (int)date('Y')];
try {
    $yr = $pdo->query("SELECT MIN(year) AS mn, MAX(year) AS mx FROM auctions")->fetch();
    if ($yr) {
        $yearRange['min'] = (int)$yr['mn'];
        $yearRange['max'] = (int)$yr['mx'];
    }
} catch (Exception $e) {}

// ── PAGE META ─────────────────────────────────────────────────
$pageTitle       = 'Bank Repossessed Cars for Sale — Live Auctions';
$pageDescription = 'Browse live bank repossessed car auctions in South Africa. Every vehicle DEKRA inspected, priced at the outstanding balance. No buyer\'s commission. Register free to bid.';
$bodyClass       = 'page-auctions';
$canonicalUrl    = CANONICAL_URL . '/auctions.php';

require_once 'header.php';
require_once 'vehicle_strip.php';   // lotTitle()

// ── STRUCTURED DATA ──────────────────────────────────────────
// An ItemList of the visible lots tells Google this is a real listing
// hub rather than a thin filter page, and a BreadcrumbList gives it a
// path. Only the current page of results is listed, which is what the
// spec asks for.
$listLd = [
    '@context'        => 'https://schema.org',
    '@type'           => 'ItemList',
    'name'            => 'Bank repossessed vehicles on auction',
    'numberOfItems'   => count($auctions),
    'itemListElement' => [],
];
foreach ($auctions as $i => $a) {
    $listLd['itemListElement'][] = [
        '@type'    => 'ListItem',
        'position' => $i + 1,
        'url'      => CANONICAL_URL . '/auction.php?id=' . (int)$a['id'],
        'name'     => lotTitle($a),
    ];
}

$auctionsCrumbLd = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',            'item' => CANONICAL_URL . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Browse Auctions', 'item' => CANONICAL_URL . '/auctions.php'],
    ],
];
?>
<?php if ($auctions): ?>
<script type="application/ld+json"><?= json_encode($listLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<script type="application/ld+json"><?= json_encode($auctionsCrumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

<!-- ══════════════════════════════════════════════════════════
     PAGE HEADER BANNER
     ══════════════════════════════════════════════════════════ -->
<div class="page-hero page-hero-sm">
    <div class="container page-hero-inner">
        <div>
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="index.php">Home</a>
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
                <span aria-current="page">Auctions</span>
            </nav>
            <h1 class="page-hero-title">
                <?php if ($filterSearch): ?>
                    Results for "<?= e($filterSearch) ?>"
                <?php elseif ($filterStatus === 'active'): ?>
                    <span class="live-dot" aria-hidden="true"></span> Live Auctions
                <?php elseif ($filterStatus === 'upcoming'): ?>
                    Upcoming Auctions
                <?php elseif ($filterStatus === 'closed'): ?>
                    Closed Auctions
                <?php else: ?>
                    All Auctions
                <?php endif; ?>
            </h1>
            <p class="page-hero-sub">
                <?= $totalResults ?> vehicle<?= $totalResults !== 1 ? 's' : '' ?> found
                <?php if ($totalPages > 1): ?>
                    &mdash; page <?= $page ?> of <?= $totalPages ?>
                <?php endif; ?>
            </p>
        </div>

        <?php if ($filterStatus === 'active' || $filterStatus === 'all'): ?>
        <div class="page-hero-badge" aria-live="polite" aria-label="Active auction count">
            <span class="live-dot"></span>
            <span id="live-count">
                <?php
                try {
                    echo (int)$pdo->query("SELECT COUNT(*) FROM auctions WHERE status='active'")->fetchColumn();
                } catch(Exception $e) { echo 0; }
                ?>
            </span> live now
        </div>
        <?php endif; ?>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     FILTER BAR
     ══════════════════════════════════════════════════════════ -->
<div class="filter-bar-wrap" id="filter-bar-wrap">
    <div class="container">
        <form id="auction-filter-form"
              method="GET"
              action="auctions.php"
              class="filter-bar"
              role="search"
              aria-label="Filter auctions">

            <!-- Search -->
            <div class="filter-group filter-group-search">
                <label for="filter-search" class="sr-only">Search vehicles</label>
                <div class="input-icon-wrap">
                    <i class="fas fa-search input-icon" aria-hidden="true"></i>
                    <input type="search"
                           id="filter-search"
                           name="search"
                           class="form-input input-with-icon"
                           placeholder="Search make, model, keyword…"
                           value="<?= e($filterSearch) ?>"
                           autocomplete="off">
                </div>
            </div>

            <!-- Status -->
            <div class="filter-group">
                <label for="filter-status" class="filter-label">Status</label>
                <select id="filter-status" name="status" class="form-select filter-select">
                    <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Live Now</option>
                    <option value="upcoming" <?= $filterStatus === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                    <option value="closed"   <?= $filterStatus === 'closed'   ? 'selected' : '' ?>>Closed</option>
                    <option value="sold"     <?= $filterStatus === 'sold'     ? 'selected' : '' ?>>Sold</option>
                    <option value="all"      <?= $filterStatus === 'all'      ? 'selected' : '' ?>>All</option>
                </select>
            </div>

            <!-- Make -->
            <div class="filter-group">
                <label for="filter-make" class="filter-label">Make</label>
                <select id="filter-make" name="make_id" class="form-select filter-select">
                    <option value="0">Any make</option>
                    <?php foreach ($makes as $mk): ?>
                        <option value="<?= $mk['id'] ?>" <?= $filterMakeId === (int)$mk['id'] ? 'selected' : '' ?>>
                            <?= e($mk['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Year range -->
            <div class="filter-group filter-group-range">
                <label class="filter-label">Year</label>
                <div class="filter-range-pair">
                    <input type="number"
                           name="year_min"
                           class="form-input filter-range-input"
                           placeholder="From"
                           min="<?= $yearRange['min'] ?>"
                           max="<?= $yearRange['max'] ?>"
                           value="<?= $filterYearMin > 0 ? $filterYearMin : '' ?>">
                    <span class="filter-range-sep">–</span>
                    <input type="number"
                           name="year_max"
                           class="form-input filter-range-input"
                           placeholder="To"
                           min="<?= $yearRange['min'] ?>"
                           max="<?= $yearRange['max'] ?>"
                           value="<?= $filterYearMax > 0 ? $filterYearMax : '' ?>">
                </div>
            </div>

            <!-- Price range -->
            <div class="filter-group filter-group-range">
                <label class="filter-label">Price (R)</label>
                <div class="filter-range-pair">
                    <input type="number"
                           name="price_min"
                           class="form-input filter-range-input"
                           placeholder="Min"
                           min="0"
                           step="1000"
                           value="<?= $filterPriceMin > 0 ? (int)$filterPriceMin : '' ?>">
                    <span class="filter-range-sep">–</span>
                    <input type="number"
                           name="price_max"
                           class="form-input filter-range-input"
                           placeholder="Max"
                           min="0"
                           step="1000"
                           value="<?= $filterPriceMax > 0 ? (int)$filterPriceMax : '' ?>">
                </div>
            </div>

            <!-- Fuel type -->
            <div class="filter-group">
                <label for="filter-fuel" class="filter-label">Fuel</label>
                <select id="filter-fuel" name="fuel" class="form-select filter-select">
                    <option value="">Any</option>
                    <?php foreach ($allowedFuels as $f): ?>
                        <option value="<?= $f ?>" <?= $filterFuel === $f ? 'selected' : '' ?>><?= $f ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Transmission -->
            <div class="filter-group">
                <label for="filter-trans" class="filter-label">Transmission</label>
                <select id="filter-trans" name="trans" class="form-select filter-select">
                    <option value="">Any</option>
                    <?php foreach ($allowedTrans as $t): ?>
                        <option value="<?= $t ?>" <?= $filterTrans === $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Sort -->
            <div class="filter-group">
                <label for="filter-sort" class="filter-label">Sort by</label>
                <select id="filter-sort" name="sort" class="form-select filter-select">
                    <option value="ending_soon" <?= $filterSort === 'ending_soon' ? 'selected' : '' ?>>Ending Soon</option>
                    <option value="newest"      <?= $filterSort === 'newest'      ? 'selected' : '' ?>>Newest</option>
                    <option value="price_low"   <?= $filterSort === 'price_low'   ? 'selected' : '' ?>>Price: Low–High</option>
                    <option value="price_high"  <?= $filterSort === 'price_high'  ? 'selected' : '' ?>>Price: High–Low</option>
                    <option value="most_bids"   <?= $filterSort === 'most_bids'   ? 'selected' : '' ?>>Most Bids</option>
                </select>
            </div>

            <!-- Buttons -->
            <div class="filter-group filter-group-actions">
                <button type="submit" class="btn btn-primary filter-btn-apply">
                    <i class="fas fa-filter" aria-hidden="true"></i>
                    <span>Filter</span>
                </button>
                <?php if ($filterSearch || $filterMakeId || $filterYearMin || $filterYearMax ||
                          $filterPriceMin || $filterPriceMax || $filterFuel || $filterTrans ||
                          $filterStatus !== 'active' || $filterSort !== 'ending_soon'): ?>
                    <a href="auctions.php" class="btn btn-outline filter-btn-clear" title="Clear all filters">
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                        <span>Clear</span>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Preserve page param reset on filter -->
            <input type="hidden" name="page" value="1">

        </form><!-- /#auction-filter-form -->
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     ACTIVE FILTERS CHIPS (shown when filters are applied)
     ══════════════════════════════════════════════════════════ -->
<?php
$activeFilters = [];
if ($filterSearch)               $activeFilters[] = ['Search: "' . e($filterSearch) . '"',  'search',    ''];
if ($filterStatus !== 'active')  $activeFilters[] = ['Status: ' . ucfirst($filterStatus),    'status',    'active'];
if ($filterMakeId > 0) {
    $mkName = '';
    foreach ($makes as $mk) { if ((int)$mk['id'] === $filterMakeId) { $mkName = $mk['name']; break; } }
    $activeFilters[] = ['Make: ' . e($mkName), 'make_id', '0'];
}
if ($filterYearMin > 0)          $activeFilters[] = ['From: ' . $filterYearMin,              'year_min',  ''];
if ($filterYearMax > 0)          $activeFilters[] = ['To: '   . $filterYearMax,              'year_max',  ''];
if ($filterPriceMin > 0)         $activeFilters[] = ['Min: R' . number_format($filterPriceMin), 'price_min', ''];
if ($filterPriceMax > 0)         $activeFilters[] = ['Max: R' . number_format($filterPriceMax), 'price_max', ''];
if ($filterFuel)                 $activeFilters[] = ['Fuel: ' . $filterFuel,                 'fuel',      ''];
if ($filterTrans)                $activeFilters[] = ['Trans: ' . $filterTrans,               'trans',     ''];
if ($filterSort !== 'ending_soon') $activeFilters[] = ['Sort: ' . str_replace('_',' ',ucfirst($filterSort)), 'sort', 'ending_soon'];
?>
<?php if ($activeFilters): ?>
<div class="active-filters-bar container" aria-label="Active filters">
    <span class="active-filters-label">
        <i class="fas fa-filter" aria-hidden="true"></i> Filters:
    </span>
    <div class="active-filters-chips">
        <?php foreach ($activeFilters as $af):
            $removeParams = $_GET;
            $removeParams[$af[1]] = $af[2];
            $removeParams['page'] = 1;
            $removeUrl = 'auctions.php?' . http_build_query(array_filter($removeParams, fn($v) => $v !== '' && $v !== '0'));
        ?>
            <a href="<?= $removeUrl ?>" class="filter-chip" title="Remove this filter">
                <?= $af[0] ?> <i class="fas fa-xmark" aria-hidden="true"></i>
            </a>
        <?php endforeach; ?>
    </div>
    <a href="auctions.php" class="active-filters-clear">Clear all</a>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     AUCTION GRID
     ══════════════════════════════════════════════════════════ -->
<main class="section auction-list-section" id="main-content">
    <div class="container">

        <?php if (empty($auctions)): ?>
        <div class="empty-state">
            <div class="empty-state-icon" aria-hidden="true"><i class="fas fa-car-side"></i></div>
            <h2 class="empty-state-title">No vehicles found</h2>
            <p class="empty-state-text">
                <?php if ($filterSearch || $filterMakeId || $filterYearMin || $filterYearMax || $filterPriceMin || $filterPriceMax || $filterFuel || $filterTrans): ?>
                    No auctions match your current filters. Try adjusting or clearing them.
                <?php elseif ($filterStatus === 'closed'): ?>
                    There are no closed auctions to display yet.
                <?php elseif ($filterStatus === 'upcoming'): ?>
                    No upcoming auctions are scheduled right now. Check back soon!
                <?php else: ?>
                    There are no live auctions at this moment. Check back soon or view upcoming lots.
                <?php endif; ?>
            </p>
            <div class="empty-state-actions">
                <a href="auctions.php" class="btn btn-primary">
                    <i class="fas fa-list" aria-hidden="true"></i> View All Auctions
                </a>
                <a href="auctions.php?status=upcoming" class="btn btn-outline">
                    <i class="fas fa-clock" aria-hidden="true"></i> Upcoming Lots
                </a>
            </div>
        </div>

        <?php else: ?>
        <div class="results-header">
            <p class="results-count" aria-live="polite">
                Showing <strong><?= (($page-1)*$perPage)+1 ?>–<?= min($page*$perPage,$totalResults) ?></strong>
                of <strong><?= $totalResults ?></strong> vehicle<?= $totalResults!==1?'s':'' ?>
            </p>
            <div class="results-view-toggle" role="group" aria-label="View toggle">
                <button class="view-toggle-btn active" data-view="grid" aria-label="Grid view" aria-pressed="true">
                    <i class="fas fa-grip" aria-hidden="true"></i>
                </button>
                <button class="view-toggle-btn" data-view="list" aria-label="List view" aria-pressed="false">
                    <i class="fas fa-list" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <div class="auction-grid" id="auction-grid" role="list">
            <?php foreach ($auctions as $a):
                $price      = (float)$a['current_price'] > 0 ? (float)$a['current_price'] : (float)$a['start_price'];
                $reserve    = (float)$a['reserve_price'];
                $reserveMet = $reserve > 0 && $price >= $reserve;
                $hasReserve = $reserve > 0;
                $bidCount   = (int)$a['bid_count'];
                $imgSrc     = !empty($a['image_url']) ? e(cldImg($a['image_url'], CLD_CARD)) : 'assets/no-image.jpg';
                $title      = e(lotTitle($a));   // item_name often already carries year + make
                $isSold     = !empty($a['is_sold']);
                $isClosed   = $a['status'] === 'closed' || $isSold;
            ?>
            <article class="auction-card <?= $isClosed ? 'auction-card-closed' : '' ?>"
                     role="listitem"
                     aria-label="<?= $title ?>">

                <a href="auction.php?id=<?= $a['id'] ?>" class="auction-card-img-link" tabindex="-1" aria-hidden="true">
                    <div class="auction-card-img-wrap">
                        <img src="<?= $imgSrc ?>" alt="<?= $title ?>" class="auction-card-img"
                             loading="lazy" width="400" height="270">
                        <?php if ($isSold): ?>
                            <div class="sold-stamp"><span>Sold</span></div>
                        <?php endif; ?>
                        <div class="auction-card-badges">
                            <?php if ($isSold): ?>
                                <span class="badge badge-sold">Sold</span>
                            <?php elseif ($a['status'] === 'active'): ?>
                                <span class="badge badge-active">
                                    <span class="live-dot live-dot-sm" aria-hidden="true"></span> Live
                                </span>
                            <?php elseif ($a['status'] === 'upcoming'): ?>
                                <span class="badge badge-upcoming">
                                    <i class="fas fa-clock" aria-hidden="true"></i> Upcoming
                                </span>
                            <?php else: ?>
                                <span class="badge badge-closed">Closed</span>
                            <?php endif; ?>
                            <?php if ($hasReserve && !$isClosed): ?>
                                <span class="badge <?= $reserveMet ? 'badge-reserve-met' : 'badge-no-reserve' ?>">
                                    <?= $reserveMet ? '✓ Reserve Met' : 'Reserve Not Met' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($bidCount > 0): ?>
                        <div class="auction-card-bid-chip" aria-label="<?= $bidCount ?> bids">
                            <i class="fas fa-gavel" aria-hidden="true"></i>
                            <?= $bidCount ?> bid<?= $bidCount !== 1 ? 's' : '' ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </a>

                <div class="auction-card-body">
                    <div class="auction-card-specs" aria-label="Vehicle specs">
                        <?php if ($a['year']): ?>
                            <span class="spec-pill"><i class="fas fa-calendar-alt" aria-hidden="true"></i> <?= (int)$a['year'] ?></span>
                        <?php endif; ?>
                        <?php if ($a['mileage']): ?>
                            <span class="spec-pill"><i class="fas fa-road" aria-hidden="true"></i> <?= number_format((int)$a['mileage']) ?> km</span>
                        <?php endif; ?>
                        <?php if ($a['fuel_type']): ?>
                            <span class="spec-pill"><i class="fas fa-gas-pump" aria-hidden="true"></i> <?= e($a['fuel_type']) ?></span>
                        <?php endif; ?>
                        <?php if ($a['transmission']): ?>
                            <span class="spec-pill"><?= $a['transmission'] === 'Automatic' ? 'Auto' : 'Manual' ?></span>
                        <?php endif; ?>
                        <?php if ($a['condition_grade']): ?>
                            <?php $stk = dekraSticker($a['condition_grade']); ?>
                            <span class="spec-pill spec-pill-grade" style="color:<?= $stk['color'] ?>;border-color:<?= $stk['color'] ?>"><i class="fas <?= $stk['icon'] ?>"></i> DEKRA <?= e($stk['name']) ?></span>
                        <?php endif; ?>
                    </div>

                    <h2 class="auction-card-title">
                        <a href="auction.php?id=<?= $a['id'] ?>" class="auction-card-title-link">
                            <?= $title ?>
                        </a>
                    </h2>

                    <div class="auction-card-pricing">
                        <?php if ($isSold): ?>
                        <div class="auction-card-price-main auction-card-price-sold">
                            <span class="price-label">Sold For</span>
                            <span class="price-value"><?= formatRand((float)($a['final_price'] ?: $price)) ?></span>
                        </div>
                        <?php else: ?>
                        <div class="auction-card-price-main">
                            <span class="price-label"><?= $bidCount > 0 ? 'Current Bid' : 'Starting Bid' ?></span>
                            <span class="price-value" id="price-<?= $a['id'] ?>" data-auction-price="<?= $a['id'] ?>">
                                <?= formatRand($price) ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if ($hasReserve && !$isSold): ?>
                        <div class="auction-card-reserve">
                            <span class="reserve-label">Reserve:</span>
                            <span class="reserve-value <?= $reserveMet ? 'reserve-met' : '' ?>">
                                <?= formatRand($reserve) ?>
                                <?php if ($reserveMet): ?>
                                    <i class="fas fa-circle-check" aria-hidden="true" title="Reserve met"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="auction-card-footer">
                        <?php if ($isSold): ?>
                            <div class="auction-card-closed-label auction-card-sold-label" aria-label="Vehicle sold">
                                <i class="fas fa-circle-check" aria-hidden="true"></i> Sold
                                <?php if ($a['sold_date'] || $a['end_time']): ?> · <?= safeDate('d M Y', $a['sold_date'] ?: $a['end_time']) ?><?php endif; ?>
                            </div>
                        <?php elseif ($a['status'] === 'active' && $a['end_time']): ?>
                            <div class="auction-card-timer"
                                 data-countdown="<?= e($a['end_time']) ?>" aria-label="Time remaining">
                                <i class="fas fa-clock" aria-hidden="true"></i>
                                <span><?= date('d M H:i', strtotime($a['end_time'])) ?></span>
                            </div>
                        <?php elseif ($a['status'] === 'upcoming' && $a['start_time']): ?>
                            <div class="auction-card-timer auction-card-timer-upcoming"
                                 data-countdown="<?= e($a['start_time']) ?>" data-label="Starts in"
                                 aria-label="Auction start time">
                                <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                                <span>Starts <?= date('d M H:i', strtotime($a['start_time'])) ?></span>
                            </div>
                        <?php elseif ($isClosed): ?>
                            <div class="auction-card-closed-label" aria-label="Auction closed">
                                <i class="fas fa-lock" aria-hidden="true"></i> Closed
                                <?php if ($a['end_time']): ?> · <?= date('d M Y', strtotime($a['end_time'])) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!$isClosed): ?>
                            <a href="auction.php?id=<?= $a['id'] ?>" class="btn btn-primary btn-sm auction-card-cta">
                                <i class="fas fa-gavel" aria-hidden="true"></i>
                                <?= $a['status'] === 'upcoming' ? 'View Details' : 'Bid Now' ?>
                            </a>
                        <?php else: ?>
                            <a href="auction.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm auction-card-cta">
                                View Results
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div><!-- /#auction-grid -->


        <!-- ── PAGINATION ──────────────────────────────────── -->
        <?php if ($totalPages > 1):
            $baseParams = $_GET; unset($baseParams['page']);
            $baseQS = $baseParams ? '&' . http_build_query($baseParams) : '';
            $window = 2; $showFirst = $page > $window+1; $showLast = $page < $totalPages-$window;
        ?>
        <nav class="pagination" aria-label="Auction pages" role="navigation">
            <ul class="pagination-list">
                <li class="pagination-item <?= $page<=1?'pagination-disabled':'' ?>">
                    <?php if ($page>1): ?>
                        <a href="?page=<?= $page-1 ?><?= $baseQS ?>" class="pagination-link" aria-label="Previous">
                            <i class="fas fa-chevron-left" aria-hidden="true"></i>
                        </a>
                    <?php else: ?>
                        <span class="pagination-link" aria-disabled="true">
                            <i class="fas fa-chevron-left" aria-hidden="true"></i>
                        </span>
                    <?php endif; ?>
                </li>
                <?php if ($showFirst): ?>
                    <li class="pagination-item"><a href="?page=1<?= $baseQS ?>" class="pagination-link">1</a></li>
                    <li class="pagination-item pagination-ellipsis" aria-hidden="true"><span>…</span></li>
                <?php endif; ?>
                <?php for ($p = max(1,$page-$window); $p <= min($totalPages,$page+$window); $p++): ?>
                    <li class="pagination-item <?= $p===$page?'pagination-active':'' ?>">
                        <?php if ($p===$page): ?>
                            <span class="pagination-link" aria-current="page"><?= $p ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $p ?><?= $baseQS ?>" class="pagination-link" aria-label="Page <?= $p ?>"><?= $p ?></a>
                        <?php endif; ?>
                    </li>
                <?php endfor; ?>
                <?php if ($showLast): ?>
                    <li class="pagination-item pagination-ellipsis" aria-hidden="true"><span>…</span></li>
                    <li class="pagination-item">
                        <a href="?page=<?= $totalPages ?><?= $baseQS ?>" class="pagination-link"><?= $totalPages ?></a>
                    </li>
                <?php endif; ?>
                <li class="pagination-item <?= $page>=$totalPages?'pagination-disabled':'' ?>">
                    <?php if ($page<$totalPages): ?>
                        <a href="?page=<?= $page+1 ?><?= $baseQS ?>" class="pagination-link" aria-label="Next">
                            <i class="fas fa-chevron-right" aria-hidden="true"></i>
                        </a>
                    <?php else: ?>
                        <span class="pagination-link" aria-disabled="true">
                            <i class="fas fa-chevron-right" aria-hidden="true"></i>
                        </span>
                    <?php endif; ?>
                </li>
            </ul>
            <p class="pagination-summary">
                Page <?= $page ?> of <?= $totalPages ?> &nbsp;·&nbsp;
                <?= $totalResults ?> result<?= $totalResults!==1?'s':'' ?>
            </p>
        </nav>
        <?php endif; ?>

        <?php endif; // end non-empty results ?>
    </div>
</main>


<!-- ══════════════════════════════════════════════════════════
     CTA STRIP — register to bid
     ══════════════════════════════════════════════════════════ -->
<?php if (!$currentUser || $currentUser['bidder_status'] !== 'verified_bidder'): ?>
<section class="cta-strip" aria-label="Registration call to action">
    <div class="container cta-strip-inner">
        <div class="cta-strip-text">
            <?php if (!$currentUser): ?>
                <i class="fas fa-gavel cta-strip-icon" aria-hidden="true"></i>
                <span>Ready to bid? <strong>Register free</strong> and activate your bidder account in 24 hours.</span>
            <?php else: ?>
                <i class="fas fa-id-card cta-strip-icon" aria-hidden="true"></i>
                <span>Your account is active but bidding isn't unlocked yet. <strong>Complete your registration</strong> to place bids.</span>
            <?php endif; ?>
        </div>
        <a href="<?= !$currentUser ? 'login.php?tab=register' : 'user_dashboard.php?tab=register' ?>"
           class="btn btn-white btn-sm">
            <?= !$currentUser ? 'Register Free' : 'Complete Registration' ?>
            <i class="fas fa-arrow-right" aria-hidden="true"></i>
        </a>
    </div>
</section>
<?php endif; ?>

<?php require_once 'footer.php'; ?>

<script>
// ── auctions.php inline JS ─────────────────────────────────
(function () {
    'use strict';

    // Grid ↔ list view toggle
    const grid    = document.getElementById('auction-grid');
    const toggles = document.querySelectorAll('.view-toggle-btn');

    toggles.forEach(btn => {
        btn.addEventListener('click', function () {
            const view = this.dataset.view;
            if (grid) grid.classList.toggle('auction-list-view', view === 'list');
            toggles.forEach(b => {
                b.classList.toggle('active', b === this);
                b.setAttribute('aria-pressed', b === this ? 'true' : 'false');
            });
            try { localStorage.setItem('pva_auction_view', view); } catch(e){}
        });
    });

    // Restore saved view
    try {
        const saved = localStorage.getItem('pva_auction_view');
        if (saved && grid) {
            grid.classList.toggle('auction-list-view', saved === 'list');
            toggles.forEach(b => {
                b.classList.toggle('active', b.dataset.view === saved);
                b.setAttribute('aria-pressed', b.dataset.view === saved ? 'true' : 'false');
            });
        }
    } catch(e){}

    // Auto-submit filter form on select changes
    const filterForm = document.getElementById('auction-filter-form');
    document.querySelectorAll('#filter-status,#filter-make,#filter-fuel,#filter-trans,#filter-sort')
        .forEach(sel => sel.addEventListener('change', () => filterForm && filterForm.submit()));

    // Debounced search submit
    const searchInput = document.getElementById('filter-search');
    if (searchInput && filterForm) {
        let t;
        searchInput.addEventListener('input', () => {
            clearTimeout(t);
            t = setTimeout(() => filterForm.submit(), 600);
        });
    }

}());
</script>
