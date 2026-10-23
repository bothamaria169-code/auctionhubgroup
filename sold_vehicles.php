<?php
// ============================================================
// AUCTION HUB GROUP — sold_vehicles.php
// Public page listing all sold vehicles
// ============================================================

$pageTitle       = 'Sold Vehicle Prices — What Cars Fetch at Auction';
$pageDescription = 'See what repossessed cars actually sold for at our auctions — real hammer prices by make, model and year. Use them to set your own bidding limit.';
$bodyClass       = 'page-sold-vehicles';

require_once 'header.php';

// ── FILTERS ──────────────────────────────────────────────────
$search     = trim($_GET['q']    ?? '');
$filterMake = (int)($_GET['make'] ?? 0);
$filterYear = (int)($_GET['year'] ?? 0);
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 24;
$offset     = ($page - 1) * $perPage;

// ── QUERIES ──────────────────────────────────────────────────
$makes = [];
try {
    $makes = $pdo->query("SELECT id, name FROM makes ORDER BY name")->fetchAll();
} catch (Exception $e) {}

$years = [];
try {
    $years = $pdo->query("SELECT DISTINCT year FROM auctions WHERE is_sold = 1 AND year IS NOT NULL ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Build WHERE
$conditions = ["a.is_sold = 1"];
$params = [];

if ($search) {
    $conditions[] = "(a.item_name LIKE ? OR m.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterMake) {
    $conditions[] = "a.make_id = ?";
    $params[] = $filterMake;
}
if ($filterYear) {
    $conditions[] = "a.year = ?";
    $params[] = $filterYear;
}

$where = implode(' AND ', $conditions);

$totalCount = 0;
$vehicles   = [];

try {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM auctions a LEFT JOIN makes m ON m.id = a.make_id WHERE $where");
    $stmtCount->execute($params);
    $totalCount = (int)$stmtCount->fetchColumn();

    $stmtVeh = $pdo->prepare("
        SELECT a.id, a.item_name, a.year, a.mileage, a.fuel_type, a.transmission,
               a.image_url, a.final_price, a.sold_date, a.is_sold,
               m.name AS make_name
        FROM auctions a
        LEFT JOIN makes m ON m.id = a.make_id
        WHERE $where
        ORDER BY a.sold_date DESC, a.id DESC
        LIMIT ? OFFSET ?
    ");
    $stmtVeh->execute(array_merge($params, [$perPage, $offset]));
    $vehicles = $stmtVeh->fetchAll();

    // Photo galleries for this page's lots (main photo first, no duplicates)
    if ($vehicles) {
        $ids = array_column($vehicles, 'id');
        $g = $pdo->prepare("
            SELECT auction_id, file_path FROM auction_gallery
            WHERE auction_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND file_path <> ''
            ORDER BY auction_id, is_primary DESC, sort_order, id
        ");
        $g->execute($ids);
        $byLot = [];
        foreach ($g->fetchAll() as $row) $byLot[$row['auction_id']][] = $row['file_path'];
        foreach ($vehicles as &$veh) {
            $all = array_values(array_unique(array_filter(array_merge([$veh['image_url']], $byLot[$veh['id']] ?? []))));
            $veh['gallery'] = array_map(fn($u) => ['full' => cldImg($u, CLD_LARGE), 'thumb' => cldImg($u, CLD_THUMB)], $all);
        }
        unset($veh);
    }
} catch (Exception $e) {
    $vehicles   = [];
    $totalCount = 0;
}

$totalPages = max(1, (int)ceil($totalCount / $perPage));
?>

<style>
.sv-hero {
    background: linear-gradient(135deg, #001432 0%, #002366 100%);
    padding: 3rem 0 2rem;
    text-align: center;
    color: #fff;
}
.sv-hero h1 { font-size: clamp(1.6rem, 4vw, 2.6rem); font-weight: 900; margin: 0 0 .5rem; }
.sv-hero p { color: rgba(255,255,255,.75); font-size: 1rem; margin: 0; }
.sv-hero-stat { display: inline-flex; align-items: center; gap: .4rem; background: rgba(255,255,255,.1); border-radius: 30px; padding: .35rem 1rem; font-size: .85rem; font-weight: 700; margin-top: 1rem; }

.sv-filters {
    background: #fff;
    border-bottom: 1px solid #e5eaf0;
    padding: 1rem 0;
    position: sticky;
    top: var(--nav-height, 72px);
    z-index: 100;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.sv-filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    align-items: center;
}
.sv-filter-bar input,
.sv-filter-bar select { flex: 1; min-width: 140px; }
.sv-filter-bar .btn { flex-shrink: 0; }

.sv-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.25rem;
    padding: 2rem 0;
}
.sv-card {
    background: #fff;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 16px rgba(0,0,0,.07);
    display: flex;
    flex-direction: column;
    transition: transform .18s, box-shadow .18s;
}
.sv-card:hover { transform: translateY(-3px); box-shadow: 0 6px 24px rgba(0,0,0,.12); }
.sv-card-img { height: 180px; background: #edf1f7; position: relative; overflow: hidden; }
.sv-card-img img { width: 100%; height: 100%; object-fit: cover; }
.sv-no-img { display: flex; align-items: center; justify-content: center; height: 100%; color: #b0bcc8; font-size: 2.5rem; }
.sv-sold-badge {
    position: absolute; top: .6rem; left: .6rem;
    background: #7b1818; color: #fff;
    font-size: .68rem; font-weight: 800; padding: .25rem .7rem;
    border-radius: 20px; text-transform: uppercase; letter-spacing: .05em;
}
.sv-card-body { padding: 1rem; flex: 1; display: flex; flex-direction: column; gap: .4rem; }
.sv-card-title { font-weight: 700; color: #003f73; font-size: .94rem; line-height: 1.3; }
.sv-card-meta { display: flex; flex-wrap: wrap; gap: .35rem; font-size: .75rem; }
.sv-card-meta span { background: #f0f4f8; border-radius: 4px; padding: .15rem .45rem; color: #556; }
.sv-card-price { font-family: 'Barlow Condensed', sans-serif; font-size: 1.4rem; font-weight: 900; color: #7b1818; margin-top: auto; padding-top: .5rem; }
.sv-card-price small { font-family: inherit; font-size: .75rem; font-weight: 600; color: #888; display: block; }
.sv-card-date { font-size: .75rem; color: #999; }

.sv-empty { text-align: center; padding: 4rem 2rem; color: #888; }
.sv-empty i { font-size: 3rem; display: block; margin-bottom: 1rem; color: #c0cbd8; }

.sv-pagination { display: flex; justify-content: center; gap: .4rem; flex-wrap: wrap; padding: 1.5rem 0 2.5rem; }
.sv-page-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 38px; height: 38px; border-radius: 8px;
    border: 1px solid #d0dbe8; background: #fff;
    color: #003f73; font-weight: 700; font-size: .88rem;
    text-decoration: none; transition: background .15s, color .15s;
}
.sv-page-btn:hover, .sv-page-btn.active {
    background: #005DDB; color: #fff; border-color: #005DDB;
}

@media (max-width: 600px) {
    .sv-grid { grid-template-columns: 1fr 1fr; gap: .75rem; }
    .sv-card-img { height: 140px; }
    .sv-card-body { padding: .75rem; }
    .sv-card-price { font-size: 1.15rem; }
}
@media (max-width: 400px) {
    .sv-grid { grid-template-columns: 1fr; }
}
.sv-gallery-btn { display: block; width: 100%; border: 0; padding: 0; cursor: zoom-in; font: inherit; }
.sv-gallery-btn:focus-visible { outline: 3px solid #005DDB; outline-offset: -3px; }
.sv-photo-count {
    position: absolute; right: .55rem; bottom: .55rem; z-index: 4;
    background: rgba(0,0,0,.65); color: #fff; font-size: .72rem; font-weight: 700;
    padding: .2rem .55rem; border-radius: 12px;
}
a.sv-card-title { text-decoration: none; }
a.sv-card-title:hover { text-decoration: underline; }
.sv-admin-actions { display: flex; gap: .75rem; margin-top: .5rem; padding-top: .5rem; border-top: 1px dashed #d8e0ea; font-size: .76rem; }
.sv-admin-actions a { color: #1565c0; font-weight: 600; text-decoration: none; }

/* Photo lightbox */
.sv-lightbox { position: fixed; inset: 0; z-index: 3000; background: rgba(5,12,25,.94); display: none; flex-direction: column; }
.sv-lightbox.open { display: flex; }
.sv-lb-top { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .8rem 1rem; color: #fff; }
.sv-lb-title { font-weight: 700; font-size: .95rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sv-lb-count { font-size: .82rem; opacity: .75; flex-shrink: 0; }
.sv-lb-close, .sv-lb-nav {
    background: rgba(255,255,255,.12); color: #fff; border: 0; border-radius: 50%;
    width: 44px; height: 44px; font-size: 1.1rem; cursor: pointer; flex-shrink: 0;
}
.sv-lb-close:hover, .sv-lb-nav:hover { background: rgba(255,255,255,.25); }
.sv-lb-stage { position: relative; flex: 1; display: flex; align-items: center; justify-content: center; min-height: 0; padding: 0 3.75rem; }
.sv-lb-stage img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 6px; user-select: none; }
.sv-lb-nav { position: absolute; top: 50%; transform: translateY(-50%); }
.sv-lb-prev { left: .75rem; }
.sv-lb-next { right: .75rem; }
.sv-lb-thumbs { display: flex; gap: .4rem; overflow-x: auto; padding: .75rem 1rem 1rem; justify-content: safe center; }
.sv-lb-thumbs button { flex-shrink: 0; width: 72px; height: 50px; padding: 0; border: 2px solid transparent; border-radius: 6px; overflow: hidden; cursor: pointer; background: #223; opacity: .6; }
.sv-lb-thumbs button.active { border-color: #fff; opacity: 1; }
.sv-lb-thumbs img { width: 100%; height: 100%; object-fit: cover; }
@media (max-width: 600px) {
    .sv-lb-stage { padding: 0 .5rem; }
    .sv-lb-nav { width: 38px; height: 38px; background: rgba(0,0,0,.45); }
}
</style>

<!-- ── Hero ────────────────────────────────────────────────── -->
<section class="sv-hero">
    <div class="container">
        <h1><i class="fas fa-tag"></i> Sold Vehicles</h1>
        <p>Vehicles successfully sold through Auction Hub Group auctions</p>
        <div class="sv-hero-stat">
            <i class="fas fa-car"></i>
            <?= number_format($totalCount) ?> vehicle<?= $totalCount !== 1 ? 's' : '' ?> sold
        </div>
    </div>
</section>

<!-- ── Filter Bar ───────────────────────────────────────────── -->
<div class="sv-filters">
    <div class="container">
        <form class="sv-filter-bar" method="get">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by name or make…" class="form-control">
            <select name="make" class="form-control">
                <option value="">All Makes</option>
                <?php foreach ($makes as $mk): ?>
                    <option value="<?= $mk['id'] ?>" <?= $filterMake === (int)$mk['id'] ? 'selected' : '' ?>>
                        <?= e($mk['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="year" class="form-control">
                <option value="">All Years</option>
                <?php foreach ($years as $yr): ?>
                    <option value="<?= $yr ?>" <?= $filterYear === (int)$yr ? 'selected' : '' ?>><?= $yr ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
            <?php if ($search || $filterMake || $filterYear): ?>
                <a href="sold_vehicles.php" class="btn btn-outline">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ── Vehicle Grid ─────────────────────────────────────────── -->
<main class="container">
    <?php if (empty($vehicles)): ?>
        <div class="sv-empty">
            <i class="fas fa-tag"></i>
            <p>No sold vehicles found<?= ($search || $filterMake || $filterYear) ? ' matching your filters' : '' ?>.</p>
        </div>
    <?php else: ?>
        <div class="sv-grid">
            <?php foreach ($vehicles as $v): ?>
            <?php $gallery = $v['gallery'] ?? []; ?>
            <div class="sv-card">
                <?php if ($gallery): ?>
                <button type="button" class="sv-card-img sv-gallery-btn"
                        data-gallery="<?= e(json_encode($gallery, JSON_UNESCAPED_SLASHES)) ?>"
                        data-title="<?= e($v['item_name']) ?>"
                        aria-label="View <?= count($gallery) ?> photo<?= count($gallery) === 1 ? '' : 's' ?> of <?= e($v['item_name']) ?>">
                    <img src="<?= e(cldImg($v['image_url'] ?: $gallery[0]['full'], CLD_CARD)) ?>" alt="<?= e($v['item_name']) ?>" loading="lazy"
                         onerror="this.style.display='none'">
                    <div class="sold-stamp"><span>Sold</span></div>
                    <span class="sv-photo-count"><i class="fas fa-images" aria-hidden="true"></i> <?= count($gallery) ?></span>
                </button>
                <?php else: ?>
                <div class="sv-card-img">
                    <div class="sv-no-img"><i class="fas fa-car"></i></div>
                    <div class="sold-stamp"><span>Sold</span></div>
                </div>
                <?php endif; ?>
                <div class="sv-card-body">
                    <a href="auction.php?id=<?= (int)$v['id'] ?>" class="sv-card-title"><?= e($v['item_name']) ?></a>
                    <div class="sv-card-meta">
                        <?php if ($v['year']): ?><span><?= $v['year'] ?></span><?php endif; ?>
                        <?php if ($v['make_name']): ?><span><?= e($v['make_name']) ?></span><?php endif; ?>
                        <?php if ($v['mileage']): ?><span><?= number_format($v['mileage']) ?> km</span><?php endif; ?>
                        <?php if ($v['transmission']): ?><span><?= ucfirst($v['transmission']) ?></span><?php endif; ?>
                    </div>
                    <?php if ($v['final_price']): ?>
                    <div class="sv-card-price">
                        <small>Sold for</small>
                        <?= formatRand($v['final_price']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($v['sold_date']): ?>
                    <div class="sv-card-date">
                        <i class="fas fa-calendar-check"></i>
                        <?= safeDate('d M Y', $v['sold_date']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                    <div class="sv-admin-actions">
                        <a href="manage_auction.php?id=<?= (int)$v['id'] ?>"><i class="fas fa-pen"></i> Edit vehicle</a>
                        <a href="admin_dashboard.php?tab=auctions&amp;astatus=sold&amp;asearch=<?= (int)$v['id'] ?>"><i class="fas fa-tag"></i> Edit sale</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="sv-pagination">
            <?php
            $queryBase = http_build_query(array_filter(['q' => $search, 'make' => $filterMake ?: '', 'year' => $filterYear ?: '']));
            $queryBase = $queryBase ? "&$queryBase" : '';
            for ($p = 1; $p <= $totalPages; $p++):
                $active = $p === $page;
            ?>
                <a href="?page=<?= $p ?><?= $queryBase ?>" class="sv-page-btn <?= $active ? 'active' : '' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<!-- Photo lightbox (one for the page; filled from the clicked card) -->
<div class="sv-lightbox" id="svLightbox" role="dialog" aria-modal="true" aria-label="Vehicle photos">
    <div class="sv-lb-top">
        <span class="sv-lb-title" id="svLbTitle"></span>
        <span class="sv-lb-count" id="svLbCount"></span>
        <button type="button" class="sv-lb-close" id="svLbClose" aria-label="Close photos"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="sv-lb-stage" id="svLbStage">
        <button type="button" class="sv-lb-nav sv-lb-prev" id="svLbPrev" aria-label="Previous photo"><i class="fas fa-chevron-left"></i></button>
        <img id="svLbImg" alt="">
        <button type="button" class="sv-lb-nav sv-lb-next" id="svLbNext" aria-label="Next photo"><i class="fas fa-chevron-right"></i></button>
    </div>
    <div class="sv-lb-thumbs" id="svLbThumbs"></div>
</div>
<script>
(function () {
    var box = document.getElementById('svLightbox');
    if (!box) return;
    var img = document.getElementById('svLbImg'), thumbs = document.getElementById('svLbThumbs');
    var photos = [], idx = 0, title = '', opener = null;

    function show(i) {
        if (!photos.length) return;
        idx = (i + photos.length) % photos.length;
        img.src = photos[idx].full;
        img.alt = title + ' — photo ' + (idx + 1);
        document.getElementById('svLbCount').textContent = (idx + 1) + ' / ' + photos.length;
        thumbs.querySelectorAll('button').forEach(function (b, n) {
            b.classList.toggle('active', n === idx);
            if (n === idx) b.scrollIntoView({block: 'nearest', inline: 'center'});
        });
        // Warm the next photo so arrowing feels instant
        if (photos.length > 1) (new Image()).src = photos[(idx + 1) % photos.length].full;
    }
    function open(btn) {
        try { photos = JSON.parse(btn.dataset.gallery || '[]'); } catch (e) { photos = []; }
        if (!photos.length) return;
        opener = btn;
        title = btn.dataset.title || '';
        document.getElementById('svLbTitle').textContent = title;
        thumbs.innerHTML = photos.map(function (p, n) {
            return '<button type="button" aria-label="Photo ' + (n + 1) + '"><img src="' + p.thumb.replace(/"/g, '&quot;') + '" alt="" loading="lazy"></button>';
        }).join('');
        thumbs.querySelectorAll('button').forEach(function (b, n) { b.addEventListener('click', function () { show(n); }); });
        var multi = photos.length > 1;
        document.getElementById('svLbPrev').hidden = !multi;
        document.getElementById('svLbNext').hidden = !multi;
        thumbs.hidden = !multi;
        box.classList.add('open');
        document.body.style.overflow = 'hidden';
        show(0);
        document.getElementById('svLbClose').focus();
    }
    function close() {
        box.classList.remove('open');
        document.body.style.overflow = '';
        img.removeAttribute('src');
        if (opener) opener.focus();
    }

    document.querySelectorAll('.sv-gallery-btn').forEach(function (b) {
        b.addEventListener('click', function () { open(b); });
    });
    document.getElementById('svLbClose').addEventListener('click', close);
    document.getElementById('svLbPrev').addEventListener('click', function () { show(idx - 1); });
    document.getElementById('svLbNext').addEventListener('click', function () { show(idx + 1); });
    box.addEventListener('click', function (e) { if (e.target === box || e.target.id === 'svLbStage') close(); });
    document.addEventListener('keydown', function (e) {
        if (!box.classList.contains('open')) return;
        if (e.key === 'Escape') close();
        else if (e.key === 'ArrowLeft') show(idx - 1);
        else if (e.key === 'ArrowRight') show(idx + 1);
    });
    var x0 = null;   // swipe on phones
    box.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, {passive: true});
    box.addEventListener('touchend', function (e) {
        if (x0 === null) return;
        var dx = e.changedTouches[0].clientX - x0;
        if (Math.abs(dx) > 40) show(idx + (dx < 0 ? 1 : -1));
        x0 = null;
    });
})();
</script>

<?php require_once 'footer.php'; ?>
