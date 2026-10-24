<?php
// ============================================================
// AUCTION HUB GROUP — index.php
// Homepage: hero, live stats, featured auctions, how it works,
// why Auction Hub Group, live bid activity, upcoming CTA, contact, footer
// ============================================================

$pageTitle       = 'Bank Repossessed Car Auctions in South Africa';
$pageDescription = 'Browse repossessed and bank auction vehicles. Register to bid on quality cars across South Africa. DEKRA inspected, verified, and priced below market value.';
$bodyClass       = 'page-home';

require_once 'header.php';

// ── DATA QUERIES ──────────────────────────────────────────────

// 1. Live stats counters
// Historical offsets: AHG has been operating since 2016 — the online
// system is new, but these numbers reflect the company's full history.
$STATS_OFFSET_BIDS     = 498750;   // ~500 000 lifetime bids
$STATS_OFFSET_SOLD     = 97840;    // ~98 000 vehicles sold
$STATS_OFFSET_BIDDERS  = 5920;     // ~6 000 verified bidders

$stats = [];
try {
    $stats['active_auctions'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM auctions WHERE status = 'active'"
    )->fetchColumn();

    $stats['verified_bidders'] = $STATS_OFFSET_BIDDERS + (int)$pdo->query(
        "SELECT COUNT(*) FROM users WHERE bidder_status = 'verified_bidder'"
    )->fetchColumn();

    $stats['vehicles_sold'] = $STATS_OFFSET_SOLD + (int)$pdo->query(
        "SELECT COUNT(*) FROM auctions WHERE is_sold = 1"
    )->fetchColumn();

    $stats['total_bids'] = $STATS_OFFSET_BIDS + (int)$pdo->query(
        "SELECT COUNT(*) FROM bids"
    )->fetchColumn();
} catch (Exception $e) {
    $stats = ['active_auctions' => 0, 'verified_bidders' => $STATS_OFFSET_BIDDERS, 'vehicles_sold' => $STATS_OFFSET_SOLD, 'total_bids' => $STATS_OFFSET_BIDS];
}

// 2. Featured auctions (active first, then upcoming — 6 total)
$featuredAuctions = [];
try {
    $stmt = $pdo->query("
        SELECT a.id, a.item_name, a.year, a.mileage, a.fuel_type, a.transmission,
               a.current_price, a.start_price, a.reserve_price, a.min_increment,
               a.status, a.end_time, a.start_time, a.image_url,
               m.name AS make_name,
               COUNT(b.id) AS bid_count
        FROM auctions a
        LEFT JOIN makes m ON m.id = a.make_id
        LEFT JOIN bids  b ON b.auction_id = a.id
        WHERE a.status IN ('active', 'upcoming') AND a.is_sold = 0
        GROUP BY a.id
        ORDER BY
            CASE a.status WHEN 'active' THEN 0 WHEN 'upcoming' THEN 1 END ASC,
            a.end_time ASC
        LIMIT 6
    ");
    $featuredAuctions = $stmt->fetchAll();
} catch (Exception $e) {
    $featuredAuctions = [];
}

// 2b. Recently sold (social proof strip under the featured grid)
$recentlySold = [];
try {
    $recentlySold = $pdo->query("
        SELECT a.id, a.item_name, a.year, a.mileage, a.image_url,
               COALESCE(a.final_price, a.current_price) AS sold_price,
               COALESCE(a.sold_date, a.end_time)        AS sold_on
        FROM auctions a
        WHERE a.is_sold = 1
        ORDER BY COALESCE(a.sold_date, a.end_time) DESC
        LIMIT 12
    ")->fetchAll();
} catch (Exception $e) {
    $recentlySold = [];
}

// 3. Nearest upcoming auction (for CTA countdown)
$nextAuction = null;
try {
    $nextAuction = $pdo->query("
        SELECT id, item_name, start_time, end_time, status
        FROM auctions
        WHERE status IN ('active', 'upcoming')
        ORDER BY
            CASE status WHEN 'active' THEN 0 WHEN 'upcoming' THEN 1 END,
            end_time ASC
        LIMIT 1
    ")->fetch();
} catch (Exception $e) {}

// 4. Recent bid activity (for live feed)
$recentBids = [];
try {
    $stmt = $pdo->query("
        SELECT b.bidder_number, b.amount, b.created_at,
               a.item_name, a.id AS auction_id,
               u.full_name
        FROM bids b
        JOIN auctions a ON a.id = b.auction_id
        JOIN users u    ON u.id = b.user_id
        ORDER BY b.id DESC
        LIMIT 8
    ");
    $recentBids = $stmt->fetchAll();
} catch (Exception $e) {
    $recentBids = [];
}

// 5. Testimonials (active, sorted)
$testimonials = [];
try {
    $testimonials = $pdo->query("
        SELECT id, headline, image_url
        FROM testimonials
        WHERE is_active = 1
        ORDER BY sort_order ASC, id DESC
        LIMIT 12
    ")->fetchAll();
} catch (Exception $e) {
    $testimonials = [];
}

// 6. Popup notifications: shown sitewide by live_bids_widget.php (real bids + staff notes)

// ── HELPER — display price ────────────────────────────────────
function displayPrice(array $auction): string {
    $price = (float)$auction['current_price'] > 0
        ? (float)$auction['current_price']
        : (float)$auction['start_price'];
    return 'R ' . number_format($price, 2);
}
?>

<!-- ══════════════════════════════════════════════════════════
     HERO SECTION
     ══════════════════════════════════════════════════════════ -->
<section class="hero" id="hero">
    <div class="hero-bg">
        <!-- Overlay gradient is applied via CSS -->
    </div>
    <div class="container hero-content">
        <div class="hero-badge">
            <span class="live-dot"></span>
            <?= $stats['active_auctions'] ?> Active Auction<?= $stats['active_auctions'] !== 1 ? 's' : '' ?> Now
        </div>
        <h1 class="hero-title">
            South Africa's Home of<br>
            <span class="hero-title-accent">Bank-Repo Vehicle</span> Auctions
        </h1>
        <p class="hero-subtitle">
            DEKRA-inspected. Below market price. Bid <strong style="color:#fff;">live on the floor</strong> or
            <strong style="color:#fff;">online from anywhere</strong> in South Africa —<br class="hero-br-desktop">
            every vehicle bank-liquidated and priced at the outstanding balance.
        </p>
        <div class="hero-ctas">
            <a href="auctions.php" class="btn btn-primary btn-lg hero-cta-primary">
                <i class="fas fa-gavel" aria-hidden="true"></i>
                Browse Auctions
            </a>
            <?php if (!$currentUser): ?>
                <a href="login.php?tab=register" class="btn btn-outline-white btn-lg hero-cta-secondary">
                    <i class="fas fa-user-plus" aria-hidden="true"></i>
                    Register Free
                </a>
            <?php elseif ($currentUser['bidder_status'] !== 'verified_bidder'): ?>
                <a href="user_dashboard.php?tab=register" class="btn btn-outline-white btn-lg hero-cta-secondary">
                    <i class="fas fa-id-card" aria-hidden="true"></i>
                    Complete Registration
                </a>
            <?php else: ?>
                <a href="user_dashboard.php" class="btn btn-outline-white btn-lg hero-cta-secondary">
                    <i class="fas fa-gauge-high" aria-hidden="true"></i>
                    My Dashboard
                </a>
            <?php endif; ?>
        </div>

        <!-- Trust badges -->
        <div class="hero-trust">
            <div class="hero-trust-item">
                <i class="fas fa-gavel" aria-hidden="true"></i>
                <span>Live Floor Auctions</span>
            </div>
            <div class="hero-trust-item">
                <i class="fas fa-laptop" aria-hidden="true"></i>
                <span>Online Auctions</span>
            </div>
            <div class="hero-trust-item">
                <i class="fas fa-clipboard-check" aria-hidden="true"></i>
                <span>DEKRA Inspected</span>
            </div>
            <div class="hero-trust-item">
                <i class="fas fa-building-columns" aria-hidden="true"></i>
                <span>Bank Liquidations</span>
            </div>
            <div class="hero-trust-item">
                <i class="fas fa-truck-fast" aria-hidden="true"></i>
                <span>Nationwide Delivery</span>
            </div>
        </div>
    </div>

    <!-- Scroll indicator -->
    <a href="#stats" class="hero-scroll-indicator" aria-label="Scroll to content">
        <i class="fas fa-chevron-down" aria-hidden="true"></i>
    </a>
</section>


<!-- ══════════════════════════════════════════════════════════
     LIVE STATS STRIP
     ══════════════════════════════════════════════════════════ -->
<section class="stats-strip" id="stats" aria-label="Site statistics">
    <div class="container stats-strip-inner">

        <div class="stat-card">
            <div class="stat-card-icon">
                <i class="fas fa-gavel" aria-hidden="true"></i>
            </div>
            <div class="stat-card-body">
                <span class="stat-card-number" data-count-to="<?= $stats['active_auctions'] ?>">
                    <?= $stats['active_auctions'] ?>
                </span>
                <span class="stat-card-label">Live Auctions</span>
            </div>
        </div>

        <div class="stat-divider" aria-hidden="true"></div>

        <div class="stat-card">
            <div class="stat-card-icon">
                <i class="fas fa-users" aria-hidden="true"></i>
            </div>
            <div class="stat-card-body">
                <span class="stat-card-number" data-count-to="<?= $stats['verified_bidders'] ?>">
                    <?= $stats['verified_bidders'] ?>
                </span>
                <span class="stat-card-label">Verified Bidders</span>
            </div>
        </div>

        <div class="stat-divider" aria-hidden="true"></div>

        <div class="stat-card">
            <div class="stat-card-icon">
                <i class="fas fa-car" aria-hidden="true"></i>
            </div>
            <div class="stat-card-body">
                <span class="stat-card-number" data-count-to="<?= $stats['vehicles_sold'] ?>">
                    <?= $stats['vehicles_sold'] ?>
                </span>
                <span class="stat-card-label">Vehicles Sold</span>
            </div>
        </div>

        <div class="stat-divider" aria-hidden="true"></div>

        <div class="stat-card">
            <div class="stat-card-icon">
                <i class="fas fa-trophy" aria-hidden="true"></i>
            </div>
            <div class="stat-card-body">
                <span class="stat-card-number" data-count-to="<?= $stats['total_bids'] ?>">
                    <?= $stats['total_bids'] ?>
                </span>
                <span class="stat-card-label">Bids Placed</span>
            </div>
        </div>

    </div>
</section>


<!-- ══════════════════════════════════════════════════════════
     TWO WAYS TO BID
     ══════════════════════════════════════════════════════════ -->
<section style="background:#001a35;padding:5rem 1.5rem;" id="how-to-bid">
  <div class="container">

    <div style="text-align:center;margin-bottom:3rem;">
        <div style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:50px;padding:.4rem 1.25rem;margin-bottom:1rem;">
            <i class="fas fa-gavel" style="color:#7ec8ff;font-size:.8rem;"></i>
            <span style="color:#d0e8ff;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;">Our Auction Formats</span>
        </div>
        <h2 style="color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:clamp(1.8rem,4vw,2.8rem);font-weight:800;line-height:1.1;margin-bottom:.75rem;">
            Two Ways to Win Your Next Vehicle
        </h2>
        <p style="color:#99b8d0;font-size:1rem;max-width:600px;margin:0 auto;line-height:1.7;">
            Every lot is bank-repossessed, DEKRA-inspected, and priced at the outstanding balance &mdash; below retail every time. Your choice of how you bid.
        </p>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.75rem;">

        <!-- Live Floor Auction -->
        <div style="background:rgba(255,255,255,.05);border-radius:22px;padding:2.5rem;border:1.5px solid rgba(255,255,255,.1);display:flex;flex-direction:column;gap:1.5rem;transition:border-color .2s;"
             onmouseover="this.style.borderColor='rgba(255,255,255,.28)'" onmouseout="this.style.borderColor='rgba(255,255,255,.1)'">
            <div style="display:flex;align-items:center;gap:1rem;">
                <div style="width:56px;height:56px;background:linear-gradient(135deg,#005BA1,#003f73);border-radius:16px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-gavel" style="color:#fff;font-size:1.4rem;"></i>
                </div>
                <div>
                    <div style="color:#7ec8ff;font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:.2rem;">Format 01</div>
                    <h3 style="color:#fff;font-size:1.3rem;font-weight:800;margin:0;font-family:'Barlow Condensed',sans-serif;letter-spacing:.02em;">Live Floor Auction</h3>
                </div>
            </div>
            <p style="color:#99b8d0;font-size:.93rem;line-height:1.7;margin:0;">
                Attend in person at any of our <strong style="color:#fff;">5 branches</strong> across South Africa. Stand on the floor, see the vehicles before the hammer falls, and bid against real buyers in real time.
            </p>
            <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.65rem;">
                <?php foreach ([
                    ['fa-users','Bid live alongside other registered buyers'],
                    ['fa-car-side','View vehicles physically before bidding'],
                    ['fa-map-marker-alt','5 branches: Centurion, Boksburg, Durban, Bloemfontein, PE'],
                    ['fa-calendar-check','Scheduled auction days — no appointment needed to bid'],
                    ['fa-key','Same-day collection after payment is confirmed'],
                ] as [$ic, $txt]): ?>
                <li style="display:flex;gap:.7rem;align-items:flex-start;font-size:.87rem;color:#b8d0e8;">
                    <i class="fas <?= $ic ?>" style="color:#25D366;margin-top:.18rem;flex-shrink:0;font-size:.8rem;"></i>
                    <?= $txt ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <a href="<?= APP_URL ?>/auctions.php" style="display:inline-flex;align-items:center;gap:.6rem;background:linear-gradient(135deg,#005BA1,#003f73);color:#fff;font-weight:800;font-size:.9rem;padding:.85rem 1.75rem;border-radius:10px;text-decoration:none;letter-spacing:.03em;margin-top:auto;box-shadow:0 4px 16px rgba(0,91,161,.4);">
                <i class="fas fa-gavel"></i> View Live Auctions
            </a>
        </div>

        <!-- Online Auction -->
        <div style="background:rgba(0,91,161,.18);border-radius:22px;padding:2.5rem;border:1.5px solid rgba(0,91,161,.45);display:flex;flex-direction:column;gap:1.5rem;position:relative;overflow:hidden;transition:border-color .2s;"
             onmouseover="this.style.borderColor='rgba(126,200,255,.55)'" onmouseout="this.style.borderColor='rgba(0,91,161,.45)'">
            <div style="position:absolute;top:1rem;right:1.25rem;background:linear-gradient(135deg,#25D366,#1a9e50);color:#fff;font-size:.65rem;font-weight:800;letter-spacing:.1em;padding:.3rem .8rem;border-radius:20px;text-transform:uppercase;">
                <span style="display:inline-block;width:6px;height:6px;background:#fff;border-radius:50%;margin-right:.4rem;vertical-align:middle;animation:blink 1.2s infinite;"></span>LIVE NOW
            </div>
            <div style="display:flex;align-items:center;gap:1rem;">
                <div style="width:56px;height:56px;background:linear-gradient(135deg,#0078d7,#005BA1);border-radius:16px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-laptop" style="color:#fff;font-size:1.3rem;"></i>
                </div>
                <div>
                    <div style="color:#7ec8ff;font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:.2rem;">Format 02</div>
                    <h3 style="color:#fff;font-size:1.3rem;font-weight:800;margin:0;font-family:'Barlow Condensed',sans-serif;letter-spacing:.02em;">Online Auction</h3>
                </div>
            </div>
            <p style="color:#99b8d0;font-size:.93rem;line-height:1.7;margin:0;">
                Bid from <strong style="color:#fff;">anywhere in South Africa</strong> — phone, tablet, or laptop. Our online platform gives you full access to every lot, live bid updates, and real-time notifications.
            </p>
            <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.65rem;">
                <?php foreach ([
                    ['fa-mobile-alt','Bid from your phone, tablet, or desktop — 24/7'],
                    ['fa-wifi','Live-streamed auctions with real-time bid updates'],
                    ['fa-bell','Instant outbid notifications — never miss a winning chance'],
                    ['fa-globe','Access every lot nationwide from one platform'],
                    ['fa-truck','Nationwide delivery arranged after you win'],
                ] as [$ic, $txt]): ?>
                <li style="display:flex;gap:.7rem;align-items:flex-start;font-size:.87rem;color:#b8d0e8;">
                    <i class="fas <?= $ic ?>" style="color:#7ec8ff;margin-top:.18rem;flex-shrink:0;font-size:.8rem;"></i>
                    <?= $txt ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <a href="<?= APP_URL ?>/login.php?tab=register" style="display:inline-flex;align-items:center;gap:.6rem;background:linear-gradient(135deg,#0078d7,#005BA1);color:#fff;font-weight:800;font-size:.9rem;padding:.85rem 1.75rem;border-radius:10px;text-decoration:none;letter-spacing:.03em;margin-top:auto;box-shadow:0 4px 16px rgba(0,120,215,.4);">
                <i class="fas fa-laptop"></i> Register to Bid Online
            </a>
        </div>

    </div><!-- /grid -->

    <!-- Pre-auction teaser strip -->
    <div style="margin-top:1.75rem;background:rgba(200,134,10,.12);border:1.5px solid rgba(200,134,10,.3);border-radius:14px;padding:1.25rem 1.75rem;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:1rem;">
            <div style="width:42px;height:42px;background:rgba(200,134,10,.2);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-lock" style="color:#e8a020;font-size:1rem;"></i>
            </div>
            <div>
                <div style="color:#f0c040;font-weight:800;font-size:.92rem;margin-bottom:.15rem;">Pre-Auction Purchase — Available Exclusively Before Auction Day</div>
                <div style="color:#99b8d0;font-size:.82rem;">Buy at the bank's outstanding balance. DEKRA inspected. 90-minute test drive included. Full refund if you walk away.</div>
            </div>
        </div>
        <a href="<?= APP_URL ?>/preauction.php" style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(200,134,10,.25);border:1px solid rgba(200,134,10,.5);color:#f0c040;font-weight:800;font-size:.85rem;padding:.7rem 1.4rem;border-radius:10px;text-decoration:none;white-space:nowrap;flex-shrink:0;">
            Learn More <i class="fas fa-arrow-right"></i>
        </a>
    </div>

  </div>
</section>
<style>
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }
@media (max-width:768px){
  #how-to-bid .container > div:nth-child(2) { grid-template-columns:1fr !important; }
}
</style>

<!-- ══════════════════════════════════════════════════════════
     FEATURED AUCTIONS
     ══════════════════════════════════════════════════════════ -->
<section class="section featured-auctions" id="featured">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">
                <?= $stats['active_auctions'] > 0 ? 'Live &amp; Upcoming Auctions' : 'Upcoming Auctions' ?>
            </h2>
            <p class="section-subtitle">
                Bid on quality repossessed and bank vehicles — all DEKRA inspected and verified
            </p>
        </div>

        <?php if (empty($featuredAuctions)): ?>
            <div class="empty-state">
                <i class="fas fa-car-side" aria-hidden="true"></i>
                <h3>No auctions running right now</h3>
                <p>Register your account and we'll notify you as soon as new vehicles are listed.</p>
                <a href="login.php?tab=register" class="btn btn-primary">Register to Bid</a>
            </div>
        <?php else: ?>
            <div class="auction-grid" role="list">
                <?php foreach ($featuredAuctions as $auction): ?>
                    <?php
                        $isActive    = $auction['status'] === 'active';
                        $displayImg  = cldImg($auction['image_url'], CLD_CARD) ?: 'assets/no-image.jpg';
                        $currentBid  = (float)$auction['current_price'];
                        $startPrice  = (float)$auction['start_price'];
                        $reservePrice= (float)$auction['reserve_price'];
                        $reserveMet  = $currentBid > 0 && $currentBid >= $reservePrice;
                        $timeTarget  = $isActive ? $auction['end_time'] : $auction['start_time'];
                        $watchedIds ??= watchedAuctionIds(isset($currentUser['sub']) ? (int)$currentUser['sub'] : null);
                    ?>
                    <article class="auction-card <?= $isActive ? 'auction-card-live' : '' ?>" role="listitem">

                        <!-- Image -->
                        <div class="watch-media">
                        <a href="auction.php?id=<?= $auction['id'] ?>" class="auction-card-img-wrap"
                           aria-label="View <?= e($auction['item_name']) ?>">
                            <img src="<?= e($displayImg) ?>"
                                 alt="<?= e($auction['item_name']) ?>"
                                 class="auction-card-img"
                                 loading="lazy"
                                 onerror="this.src='assets/no-image.jpg'">

                            <!-- Status badge -->
                            <span class="auction-card-status-badge badge badge-<?= $isActive ? 'active' : 'upcoming' ?>">
                                <?php if ($isActive): ?>
                                    <span class="live-dot-sm" aria-hidden="true"></span> Live
                                <?php else: ?>
                                    <i class="fas fa-clock" aria-hidden="true"></i> Upcoming
                                <?php endif; ?>
                            </span>

                            <?php if ($reserveMet): ?>
                                <span class="auction-card-reserve-badge">
                                    <i class="fas fa-check" aria-hidden="true"></i> Reserve Met
                                </span>
                            <?php endif; ?>
                        </a>
                        <?= watchButton((int)$auction['id'], isset($watchedIds[(int)$auction['id']])) ?>
                        </div>

                        <!-- Content -->
                        <div class="auction-card-body">
                            <h3 class="auction-card-title">
                                <a href="auction.php?id=<?= $auction['id'] ?>">
                                    <?= e($auction['item_name']) ?>
                                </a>
                            </h3>

                            <!-- Specs pills -->
                            <div class="auction-card-specs">
                                <?php if ($auction['mileage']): ?>
                                    <span class="spec-pill">
                                        <i class="fas fa-gauge" aria-hidden="true"></i>
                                        <?= number_format($auction['mileage']) ?> km
                                    </span>
                                <?php endif; ?>
                                <?php if ($auction['fuel_type']): ?>
                                    <span class="spec-pill">
                                        <i class="fas fa-gas-pump" aria-hidden="true"></i>
                                        <?= ucfirst(e($auction['fuel_type'])) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($auction['transmission']): ?>
                                    <span class="spec-pill">
                                        <i class="fas fa-gears" aria-hidden="true"></i>
                                        <?= ucfirst(e($auction['transmission'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Pricing -->
                            <div class="auction-card-pricing">
                                <div class="auction-card-price">
                                    <span class="price-label">
                                        <?= $currentBid > 0 ? 'Current Bid' : 'Starting Price' ?>
                                    </span>
                                    <span class="price-amount">
                                        <?= $currentBid > 0
                                            ? 'R ' . number_format($currentBid, 2)
                                            : 'R ' . number_format($startPrice, 2) ?>
                                    </span>
                                </div>
                                <?php if ($reservePrice > 0): ?>
                                    <div class="auction-card-reserve">
                                        <span class="reserve-label">Reserve</span>
                                        <span class="reserve-amount <?= $reserveMet ? 'reserve-met' : '' ?>">
                                            R <?= number_format($reservePrice, 2) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Footer: bids + timer + action -->
                            <div class="auction-card-footer">
                                <div class="auction-card-meta">
                                    <span class="bid-count-chip">
                                        <i class="fas fa-gavel" aria-hidden="true"></i>
                                        <?= $auction['bid_count'] ?> bid<?= $auction['bid_count'] != 1 ? 's' : '' ?>
                                    </span>
                                    <?php if ($timeTarget): ?>
                                        <span class="countdown-chip <?= $isActive ? 'live' : '' ?>"
                                              data-countdown="<?= e($auction['end_time']) ?>"
                                              <?= !$isActive ? 'data-start="' . e($auction['start_time']) . '"' : '' ?>>
                                            <?php if ($isActive): ?>
                                                Ends <?= date('d M, H:i', strtotime($auction['end_time'])) ?>
                                            <?php else: ?>
                                                Starts <?= date('d M', strtotime($auction['start_time'])) ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <a href="auction.php?id=<?= $auction['id'] ?>"
                                   class="btn btn-primary btn-sm auction-card-btn">
                                    <?= $isActive ? 'Bid Now' : 'View Details' ?>
                                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <!-- View all link -->
            <div class="section-footer">
                <a href="auctions.php" class="btn btn-outline btn-lg">
                    View All Auctions
                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
</section>


<?php if (!empty($recentlySold)): ?>
<!-- ══════════════════════════════════════════════════════════
     RECENTLY SOLD
     ══════════════════════════════════════════════════════════ -->
<section class="section recently-sold" id="recently-sold" aria-labelledby="recently-sold-title">
    <div class="container">
        <div class="rs-header">
            <div>
                <h2 class="section-title" id="recently-sold-title">Recently Sold</h2>
                <p class="section-subtitle">Real prices our buyers paid at recent auctions</p>
            </div>
            <a href="sold_vehicles.php" class="btn btn-outline btn-sm">
                All Sold Prices <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <div class="rs-strip" role="list">
            <?php foreach ($recentlySold as $s): ?>
            <a href="auction.php?id=<?= (int)$s['id'] ?>" class="rs-card" role="listitem">
                <div class="rs-card-img">
                    <img src="<?= e(cldImg($s['image_url'], CLD_CARD) ?: 'assets/no-image.jpg') ?>"
                         alt="<?= e($s['item_name']) ?>" loading="lazy"
                         onerror="this.src='assets/no-image.jpg'">
                    <div class="sold-stamp"><span>Sold</span></div>
                </div>
                <div class="rs-card-body">
                    <h3 class="rs-card-title"><?= e($s['item_name']) ?></h3>
                    <div class="rs-card-price">
                        <small>Sold for</small>
                        <?= formatRand((float)$s['sold_price']) ?>
                    </div>
                    <div class="rs-card-date">
                        <i class="fas fa-calendar-check" aria-hidden="true"></i>
                        <?= safeDate('d M Y', $s['sold_on']) ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     HOW IT WORKS
     ══════════════════════════════════════════════════════════ -->
<section class="section how-it-works" id="how-it-works">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">How It Works</h2>
            <p class="section-subtitle">Four simple steps to bid and win your next vehicle</p>
        </div>

        <div class="steps-grid" role="list">

            <div class="step-card" role="listitem">
                <div class="step-number" aria-hidden="true">01</div>
                <div class="step-icon">
                    <i class="fas fa-user-plus" aria-hidden="true"></i>
                </div>
                <h3 class="step-title">Create Your Account</h3>
                <p class="step-desc">
                    Sign up in under two minutes. We collect your name, email,
                    phone number and SA ID — that's it.
                </p>
                <a href="login.php?tab=register" class="step-link">
                    Register Now <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>

            <div class="step-card" role="listitem">
                <div class="step-number" aria-hidden="true">02</div>
                <div class="step-icon">
                    <i class="fas fa-building-columns" aria-hidden="true"></i>
                </div>
                <h3 class="step-title">Pay Registration Fee</h3>
                <p class="step-desc">
                    Transfer your refundable <?= REG_FEE_LABEL ?> registration deposit via EFT and upload your proof of payment. Request banking details from your dashboard.
                </p>
                <a href="user_dashboard.php?tab=register" class="step-link">
                    Pay &amp; Upload <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>

            <div class="step-card" role="listitem">
                <div class="step-number" aria-hidden="true">03</div>
                <div class="step-icon">
                    <i class="fas fa-id-card-clip" aria-hidden="true"></i>
                </div>
                <h3 class="step-title">Get Your Bidder Number</h3>
                <p class="step-desc">
                    Our team reviews your payment within 1–2 business days.
                    Once approved, you'll receive your unique AHG bidder number.
                </p>
                <a href="user_dashboard.php" class="step-link">
                    Check Status <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>

            <div class="step-card" role="listitem">
                <div class="step-number" aria-hidden="true">04</div>
                <div class="step-icon">
                    <i class="fas fa-gavel" aria-hidden="true"></i>
                </div>
                <h3 class="step-title">Bid &amp; Win</h3>
                <p class="step-desc">
                    Browse active auctions, place your bids, and track them live.
                    Win a vehicle and we'll contact you within 24 hours.
                </p>
                <a href="auctions.php" class="step-link">
                    Browse Vehicles <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>

        </div><!-- /.steps-grid -->

        <!-- Registration fee callout -->
        <div class="hiw-bank-callout">
            <div class="hiw-bank-icon">
                <i class="fas fa-building-columns" aria-hidden="true"></i>
            </div>
            <div class="hiw-bank-details">
                <h4>Registration Deposit</h4>
                <div class="hiw-bank-grid">
                    <div class="hiw-bank-row hiw-bank-amount-row">
                        <span>Amount</span>
                        <strong class="hiw-bank-amount"><?= REG_FEE_LABEL ?></strong>
                    </div>
                    <div class="hiw-bank-row">
                        <span>Payment Method</span>
                        <strong>EFT (Electronic Funds Transfer)</strong>
                    </div>
                    <div class="hiw-bank-row">
                        <span>Banking Details</span>
                        <strong>Request from your dashboard after registering</strong>
                    </div>
                </div>
            </div>
            <div class="hiw-bank-action">
                <?php if (!$currentUser): ?>
                    <a href="login.php?tab=register" class="btn btn-primary">
                        Register &amp; Upload POP
                    </a>
                <?php elseif ($currentUser['bidder_status'] !== 'verified_bidder'): ?>
                    <a href="user_dashboard.php?tab=register" class="btn btn-primary">
                        Upload Proof of Payment
                    </a>
                <?php else: ?>
                    <div class="hiw-verified-badge">
                        <i class="fas fa-circle-check" aria-hidden="true"></i>
                        You're a verified bidder!
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>


<!-- ══════════════════════════════════════════════════════════
     IN-HOUSE FINANCE — TEASER STRIP
     ══════════════════════════════════════════════════════════ -->
<section style="background:linear-gradient(135deg,#001432,#003f73);padding:2.25rem 1.5rem;" id="finance-teaser">
    <div class="container">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:2rem;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:1.25rem;flex:1;min-width:260px;">
                <div style="width:50px;height:50px;background:rgba(37,211,102,.15);border:1px solid rgba(37,211,102,.4);border-radius:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-hand-holding-dollar" style="color:#6eff9e;font-size:1.2rem;"></i>
                </div>
                <div>
                    <div style="color:#6eff9e;font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:.2rem;">In-House Vehicle Finance</div>
                    <h3 style="color:#fff;font-size:1rem;font-weight:800;margin:0;line-height:1.4;">No Credit Checks &bull; No ITC &bull; Blacklisted Welcome &bull; R10,500 Deposit</h3>
                </div>
            </div>
            <div style="display:flex;gap:.65rem;flex-wrap:wrap;flex-shrink:0;">
                <a href="<?= APP_URL ?>/financing.php" style="background:#fff;color:#005BA1;padding:.75rem 1.5rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.88rem;display:inline-flex;align-items:center;gap:.4rem;white-space:nowrap;">
                    <i class="fas fa-hand-holding-dollar"></i> Explore Finance
                </a>
                <a href="mailto:<?= COMPANY_EMAIL ?>?subject=In-House%20Finance%20Enquiry"
                   style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.25);color:#fff;padding:.75rem 1.5rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.88rem;display:inline-flex;align-items:center;gap:.4rem;white-space:nowrap;">
                    <i class="fas fa-envelope"></i> Enquire
                </a>
            </div>
        </div>
    </div>
</section>




<!-- ══════════════════════════════════════════════════════════
     WHY AUCTION HUB — VALUE PROPOSITIONS
     ══════════════════════════════════════════════════════════ -->
<section class="section why-pva" id="why-pva">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Why South Africa Trusts Auction Hub Group</h2>
            <p class="section-subtitle">Founded in 2016 — <?= date('Y') - 2016 ?>+ years of trusted auctioneering excellence</p>
        </div>

        <div class="why-grid">

            <div class="why-card">
                <div class="why-card-icon why-icon-blue">
                    <i class="fas fa-calendar-check" aria-hidden="true"></i>
                </div>
                <h3><?= date('Y') - 2016 ?>+ Years in Operation</h3>
                <p>
                    Founded in 2016. Trusted by thousands of buyers annually.
                    A reputation built on cast-iron integrity since day one.
                </p>
            </div>

            <div class="why-card">
                <div class="why-card-icon why-icon-cyan">
                    <i class="fas fa-clipboard-check" aria-hidden="true"></i>
                </div>
                <h3>DEKRA Sticker Inspected</h3>
                <p>
                    Every vehicle is professionally DEKRA inspected and assigned a Green, Gold, or Platinum sticker
                    — full condition transparency so you know exactly what you're bidding on.
                </p>
            </div>

            <div class="why-card">
                <div class="why-card-icon why-icon-blue">
                    <i class="fas fa-hand-holding-dollar" aria-hidden="true"></i>
                </div>
                <h3>In-House Finance</h3>
                <p>
                    No credit checks. No ITC. Blacklisted clients welcome.
                    From R10,500 deposit — pay more to lower instalments. Flexible terms from 6 to 48 months.
                </p>
            </div>

            <div class="why-card">
                <div class="why-card-icon why-icon-cyan">
                    <i class="fas fa-shield-halved" aria-hidden="true"></i>
                </div>
                <h3>Regulated &amp; Compliant</h3>
                <p>
                    Level 2 BBBEE certified. CPA, FICA &amp; POPI Act compliant.
                    Members of SAIA, NAA, JCCI, and IEASA.
                </p>
            </div>

            <div class="why-card">
                <div class="why-card-icon why-icon-blue">
                    <i class="fas fa-trophy" aria-hidden="true"></i>
                </div>
                <h3>Award-Winning</h3>
                <p>
                    Awarded Best Auctioneer 2018 &amp; 2019 by Standard Bank.
                    Recognised for excellence in the industry.
                </p>
            </div>

            <div class="why-card">
                <div class="why-card-icon why-icon-cyan">
                    <i class="fas fa-headset" aria-hidden="true"></i>
                </div>
                <h3>Dedicated Support</h3>
                <p>
                    Three dedicated WhatsApp lines for instant support. Our team is here
                    before, during, and after every auction to help you succeed.
                </p>
            </div>

        </div><!-- /.why-grid -->
    </div>
</section>


<!-- ══════════════════════════════════════════════════════════
     TESTIMONIALS
     ══════════════════════════════════════════════════════════ -->
<?php if (!empty($testimonials)): ?>
<section class="section" id="testimonials" style="background:#f8fafc;padding:4rem 1.5rem;">
    <div class="container">
        <div class="section-header">
            <div style="display:inline-flex;align-items:center;gap:.5rem;background:#005BA11a;border:1px solid #005BA140;border-radius:50px;padding:.4rem 1.25rem;margin-bottom:1rem;">
                <i class="fas fa-star" style="color:#005BA1;font-size:.8rem;"></i>
                <span style="color:#005BA1;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;">What Our Buyers Say</span>
            </div>
            <h2 class="section-title">Real Buyers. Real Results.</h2>
            <p class="section-subtitle">Thousands of satisfied buyers since 2016 — here's what they have to say</p>
        </div>

        <div class="testimonials-carousel" id="testimonialCarousel">
            <div class="testimonials-track">
                <?php foreach ($testimonials as $t): ?>
                <div class="testimonial-card">
                    <div class="testimonial-img-wrap">
                        <img src="<?= e(imgHost($t['image_url'])) ?>" alt="<?= e($t['headline']) ?>" class="testimonial-img" loading="lazy" onerror="this.parentElement.style.display='none'">
                    </div>
                    <p class="testimonial-headline"><?= e($t['headline']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (count($testimonials) > 3): ?>
            <button class="testimonial-nav testimonial-nav-prev" onclick="scrollTestimonials(-1)" aria-label="Previous">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button class="testimonial-nav testimonial-nav-next" onclick="scrollTestimonials(1)" aria-label="Next">
                <i class="fas fa-chevron-right"></i>
            </button>
            <?php endif; ?>
        </div>

        <div class="section-footer" style="margin-top:2rem;">
            <a href="testimonials.php" class="btn btn-outline btn-lg">
                View All Testimonials <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</section>

<style>
.testimonials-carousel { position:relative; overflow:hidden; }
.testimonials-track { display:flex; gap:1.25rem; overflow-x:auto; scroll-behavior:smooth; padding:1rem .5rem; scrollbar-width:none; -ms-overflow-style:none; }
.testimonials-track::-webkit-scrollbar { display:none; }
.testimonial-card { flex:0 0 calc(33.333% - .85rem); background:#fff; border-radius:14px; overflow:hidden; border:1px solid #e5eaf0; transition:transform .3s, box-shadow .3s; }
.testimonial-card:hover { transform:translateY(-4px); box-shadow:0 12px 32px rgba(0,0,0,.1); }
.testimonial-img-wrap { width:100%; aspect-ratio:4/3; overflow:hidden; background:#f0f0f0; display:flex; align-items:center; justify-content:center; }
.testimonial-img { width:100%; height:100%; object-fit:cover; }
.testimonial-headline { padding:1rem 1.25rem; font-size:.95rem; font-weight:600; color:#003f73; text-align:center; line-height:1.5; margin:0; }
.testimonial-nav { position:absolute; top:50%; transform:translateY(-50%); width:42px; height:42px; border-radius:50%; background:#005BA1; color:#fff; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:1rem; box-shadow:0 4px 12px rgba(0,0,0,.2); z-index:2; transition:background .2s; }
.testimonial-nav:hover { background:#003f73; }
.testimonial-nav-prev { left:.5rem; }
.testimonial-nav-next { right:.5rem; }
@media (max-width:992px) { .testimonial-card { flex:0 0 calc(50% - .65rem); } }
@media (max-width:600px) { .testimonial-card { flex:0 0 calc(100% - .5rem); } }
</style>

<script>
function scrollTestimonials(dir) {
    const track = document.querySelector('.testimonials-track');
    const card = track.querySelector('.testimonial-card');
    if (!card) return;
    const scrollAmount = card.offsetWidth + 20;
    track.scrollBy({ left: dir * scrollAmount, behavior: 'smooth' });
}
</script>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     LIVE BID ACTIVITY FEED
     ══════════════════════════════════════════════════════════ -->
<?php if (!empty($recentBids)): ?>
<section class="section live-activity" id="live-activity">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">
                <span class="live-dot" aria-hidden="true"></span>
                Live Bid Activity
            </h2>
            <p class="section-subtitle">Real-time bidding happening right now on our platform</p>
        </div>

        <div class="activity-feed" role="list" aria-label="Recent bids">
            <?php foreach ($recentBids as $bid): ?>
                <div class="activity-item" role="listitem">
                    <div class="activity-avatar" aria-hidden="true">
                        <?= strtoupper(substr($bid['full_name'] ?? 'B', 0, 1)) ?>
                    </div>
                    <div class="activity-content">
                        <span class="activity-bidder">
                            <?= $bid['bidder_number'] ? e($bid['bidder_number']) : 'Anonymous Bidder' ?>
                        </span>
                        <span class="activity-action">placed a bid of</span>
                        <span class="activity-amount">R <?= number_format((float)$bid['amount'], 2) ?></span>
                        <span class="activity-on">on</span>
                        <a href="auction.php?id=<?= $bid['auction_id'] ?>" class="activity-vehicle">
                            <?= e($bid['item_name']) ?>
                        </a>
                    </div>
                    <div class="activity-time" title="<?= e($bid['created_at']) ?>">
                        <?php
                            $bidTs = !empty($bid['created_at']) ? strtotime($bid['created_at']) : 0;
                            $diff  = $bidTs > 0 ? time() - $bidTs : 0;
                            if ($bidTs <= 0)       echo '—';
                            elseif ($diff < 60)    echo $diff . 's ago';
                            elseif ($diff < 3600)  echo floor($diff/60) . 'm ago';
                            elseif ($diff < 86400) echo floor($diff/3600) . 'h ago';
                            else echo date('d M', $bidTs);
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="section-footer">
            <a href="auctions.php" class="btn btn-outline">
                Join the Action <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     UPCOMING AUCTION CTA BANNER
     ══════════════════════════════════════════════════════════ -->
<?php if ($nextAuction): ?>
<section class="cta-banner" id="next-auction">
    <div class="container cta-banner-inner">
        <div class="cta-banner-text">
            <?php if ($nextAuction['status'] === 'active'): ?>
                <div class="cta-banner-eyebrow">
                    <span class="live-dot" aria-hidden="true"></span> Auction Live Now
                </div>
                <h2 class="cta-banner-title">Don't Miss Your Chance to Bid!</h2>
                <p class="cta-banner-subtitle">
                    <?= e($nextAuction['item_name']) ?> is live right now. Auction ends:
                </p>
                <div class="cta-countdown"
                     data-countdown="<?= e($nextAuction['end_time']) ?>"
                     aria-label="Time remaining">
                    <?= safeDate('d M Y H:i', $nextAuction['end_time'], 'TBC') ?>
                </div>
            <?php else: ?>
                <div class="cta-banner-eyebrow">
                    <i class="fas fa-clock" aria-hidden="true"></i> Coming Up Next
                </div>
                <h2 class="cta-banner-title">Our Next Auction Starts Soon</h2>
                <p class="cta-banner-subtitle">
                    Register now so you're ready to bid the moment it opens.
                </p>
                <?php if ($nextAuction['start_time']): ?>
                    <div class="cta-countdown"
                         data-countdown="<?= e($nextAuction['end_time']) ?>"
                         data-start="<?= e($nextAuction['start_time']) ?>"
                         aria-label="Time until auction starts">
                        <?= date('d M Y H:i', strtotime($nextAuction['start_time'])) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="cta-banner-actions">
            <a href="auction.php?id=<?= $nextAuction['id'] ?>" class="btn btn-white btn-lg">
                <i class="fas fa-gavel" aria-hidden="true"></i>
                <?= $nextAuction['status'] === 'active' ? 'Bid Now' : 'View Auction' ?>
            </a>
            <?php if (!$currentUser): ?>
                <a href="login.php?tab=register" class="btn btn-outline-white btn-lg">
                    Register to Bid
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     OUR BRANCHES / AUCTION GROUNDS
     ══════════════════════════════════════════════════════════ -->
<section class="section" id="branches" style="background:#f0f4f8;padding:4rem 0;">
    <div class="container">
        <div class="section-header" style="margin-bottom:2.5rem;">
            <h2 class="section-title">
                <i class="fas fa-warehouse" style="color:#005BA1;" aria-hidden="true"></i>
                Our Locations
            </h2>
            <p class="section-subtitle">
                Five locations across South Africa &mdash;
                <strong>Head Office · Boksburg · Bloemfontein · Durban · Eastern Cape</strong>
            </p>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.4rem;">

            <!-- Head Office / Centurion (Online Sales) -->
            <div class="branch-card">
                <div class="branch-card-img-wrap">
                    <img src="assets/images/headoffice.jpg"
                         alt="Auction Hub Group Head Office — 80 Sterling Rd, Centurion"
                         class="branch-card-img"
                         loading="lazy"
                         onerror="this.style.display='none';this.closest('.branch-card-img-wrap').style.background='#003f73'">
                    <span class="branch-card-badge" style="background:#005BA1;">HEAD OFFICE</span>
                </div>
                <div class="branch-card-body">
                    <h3 class="branch-card-city">
                        <i class="fas fa-building" aria-hidden="true"></i> Centurion (Head Office)
                    </h3>
                    <address class="branch-card-address">
                        80 Sterling Rd<br>
                        Kosmosdal, Centurion, 0187
                    </address>
                    <div class="branch-card-tags">
                        <span class="branch-tag"><i class="fas fa-laptop"></i> ONLINE BIDDING</span>
                        <span class="branch-tag"><i class="fas fa-headset"></i> SALES SUPPORT</span>
                    </div>
                </div>
            </div>

            <!-- Boksburg / Johannesburg -->
            <div class="branch-card">
                <div class="branch-card-img-wrap">
                    <img src="assets/images/branch-boksburg.jpg"
                         alt="Auction Hub Group Boksburg branch — 8 Top Rd, O.R. Tambo"
                         class="branch-card-img"
                         loading="lazy"
                         onerror="this.style.display='none';this.closest('.branch-card-img-wrap').style.background='#1a3a5c'">
                    <span class="branch-card-badge">MAIN BRANCH</span>
                </div>
                <div class="branch-card-body">
                    <h3 class="branch-card-city">
                        <i class="fas fa-location-dot" aria-hidden="true"></i> Boksburg (Johannesburg)
                    </h3>
                    <address class="branch-card-address">
                        8 Top Rd, O.R. Tambo<br>
                        Boksburg, 1459
                    </address>
                    <div class="branch-card-tags">
                        <span class="branch-tag"><i class="fas fa-car"></i> VEHICLE AUCTIONS</span>
                        <span class="branch-tag"><i class="fas fa-tractor"></i> DEALER STOCK</span>
                    </div>
                </div>
            </div>

            <!-- Bloemfontein -->
            <div class="branch-card">
                <div class="branch-card-img-wrap">
                    <img src="assets/images/branch-bloemfontein.jpg"
                         alt="Auction Hub Group Bloemfontein branch — Cnr R64 &amp; Valentia Road"
                         class="branch-card-img"
                         loading="lazy"
                         onerror="this.style.display='none';this.closest('.branch-card-img-wrap').style.background='#1a3a5c'">
                </div>
                <div class="branch-card-body">
                    <h3 class="branch-card-city">
                        <i class="fas fa-location-dot" aria-hidden="true"></i> Bloemfontein
                    </h3>
                    <address class="branch-card-address">
                        Cnr R64 &amp; Valentia Road<br>
                        Waterbron, Bloemfontein
                    </address>
                    <div class="branch-card-tags">
                        <span class="branch-tag"><i class="fas fa-car"></i> VEHICLE AUCTIONS</span>
                        <span class="branch-tag"><i class="fas fa-gavel"></i> REPO STOCK</span>
                    </div>
                </div>
            </div>

            <!-- Gqeberha / Eastern Cape -->
            <div class="branch-card">
                <div class="branch-card-img-wrap">
                    <img src="assets/images/branch-eastern-cape.jpg"
                         alt="Auction Hub Group Eastern Cape branch — 142 Burman Road, Gqeberha"
                         class="branch-card-img"
                         loading="lazy"
                         onerror="this.style.display='none';this.closest('.branch-card-img-wrap').style.background='#1a3a5c'">
                </div>
                <div class="branch-card-body">
                    <h3 class="branch-card-city">
                        <i class="fas fa-location-dot" aria-hidden="true"></i> Gqeberha (Port Elizabeth)
                    </h3>
                    <address class="branch-card-address">
                        142 Burman Road, Deal Party<br>
                        Gqeberha, Eastern Cape
                    </address>
                    <div class="branch-card-tags">
                        <span class="branch-tag"><i class="fas fa-car"></i> VEHICLE AUCTIONS</span>
                        <span class="branch-tag"><i class="fas fa-truck"></i> FLEET VEHICLES</span>
                    </div>
                </div>
            </div>

            <!-- Durban -->
            <div class="branch-card">
                <div class="branch-card-img-wrap">
                    <img src="assets/images/branch-durban.jpg"
                         alt="Auction Hub Group Durban branch — River Horse Estate"
                         class="branch-card-img"
                         loading="lazy"
                         onerror="this.style.display='none';this.closest('.branch-card-img-wrap').style.background='#1a3a5c'">
                </div>
                <div class="branch-card-body">
                    <h3 class="branch-card-city">
                        <i class="fas fa-location-dot" aria-hidden="true"></i> Durban
                    </h3>
                    <address class="branch-card-address">
                        Quarry Place, Off Queen Nandi Drive<br>
                        River Horse Estate, Durban
                    </address>
                    <div class="branch-card-tags">
                        <span class="branch-tag"><i class="fas fa-car"></i> VEHICLE AUCTIONS</span>
                        <span class="branch-tag"><i class="fas fa-gavel"></i> OPEN TO PUBLIC</span>
                    </div>
                </div>
            </div>

        </div><!-- /grid -->

        <p style="text-align:center;margin-top:2rem;font-size:.85rem;color:#4a6080;">
            <i class="fas fa-info-circle" style="color:#005BA1;"></i>
            All branches host live auctions of bank-repossessed &amp; liquidated vehicles.
            <a href="auctions.php" style="color:#005BA1;font-weight:700;">View all current lots &rarr;</a>
        </p>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     ONLINE SALES FACILITY
     ══════════════════════════════════════════════════════════ -->
<section style="background:#fff;padding:4rem 0;" id="online-facility">
    <div class="container">
        <div class="section-header" style="margin-bottom:2.5rem;">
            <h2 class="section-title">
                <i class="fas fa-laptop-house" style="color:#005BA1;" aria-hidden="true"></i>
                Online Sales Facility
            </h2>
            <p class="section-subtitle">
                State-of-the-art indoor bidding floor &amp; online auction facility &mdash;
                bid live from anywhere in South Africa.
            </p>
        </div>

        <!-- Mosaic photo grid -->
        <div class="sales-mosaic">
            <!-- Large featured image left -->
            <div class="sales-mosaic-main">
                <a href="assets/images/sales-1.jpg" target="_blank" class="sales-img-link" aria-label="Online sales facility main view">
                    <img src="assets/images/sales-1.jpg"
                         alt="Auction Hub Group online bidding facility — indoor sales floor"
                         class="sales-img" loading="lazy">
                    <div class="sales-img-overlay">
                        <i class="fas fa-expand-alt"></i>
                    </div>
                </a>
            </div>
            <!-- Right 2×2 grid -->
            <div class="sales-mosaic-grid">
                <a href="assets/images/sales-2.jpg" target="_blank" class="sales-img-link" aria-label="Sales facility view 2">
                    <img src="assets/images/sales-2.jpg" alt="Indoor auction facility" class="sales-img" loading="lazy">
                    <div class="sales-img-overlay"><i class="fas fa-expand-alt"></i></div>
                </a>
                <a href="assets/images/sales-3.jpg" target="_blank" class="sales-img-link" aria-label="Sales facility view 3">
                    <img src="assets/images/sales-3.jpg" alt="Indoor auction facility" class="sales-img" loading="lazy">
                    <div class="sales-img-overlay"><i class="fas fa-expand-alt"></i></div>
                </a>
                <a href="assets/images/sales-5.jpg" target="_blank" class="sales-img-link" aria-label="Sales facility view 4">
                    <img src="assets/images/sales-5.jpg" alt="Indoor auction facility" class="sales-img" loading="lazy">
                    <div class="sales-img-overlay"><i class="fas fa-expand-alt"></i></div>
                </a>
                <a href="assets/images/sales-6.jpg" target="_blank" class="sales-img-link" aria-label="Sales facility view 5">
                    <img src="assets/images/sales-6.jpg" alt="Indoor auction facility" class="sales-img" loading="lazy">
                    <div class="sales-img-overlay"><i class="fas fa-expand-alt"></i></div>
                </a>
            </div>
        </div>

        <!-- Feature bullets below gallery -->
        <div class="sales-features">
            <div class="sales-feat">
                <i class="fas fa-wifi" aria-hidden="true"></i>
                <strong>LIVE ONLINE BIDDING</strong>
                <span>Bid in real-time from your phone, tablet or PC — no travel required.</span>
            </div>
            <div class="sales-feat">
                <i class="fas fa-video" aria-hidden="true"></i>
                <strong>LIVE-STREAMED AUCTIONS</strong>
                <span>Watch the auction floor live. See every vehicle before you bid.</span>
            </div>
            <div class="sales-feat">
                <i class="fas fa-headset" aria-hidden="true"></i>
                <strong>DEDICATED SUPPORT TEAM</strong>
                <span>Our indoor sales team is on hand to assist you through every bid.</span>
            </div>
            <div class="sales-feat">
                <i class="fas fa-shield-halved" aria-hidden="true"></i>
                <strong>SECURE &amp; TRANSPARENT</strong>
                <span>All bids are recorded, timestamped and auditable. 100% above board.</span>
            </div>
        </div>

        <div style="text-align:center;margin-top:2.5rem;">
            <a href="login.php?tab=register" class="btn btn-primary" style="margin-right:.75rem;">
                <i class="fas fa-user-plus" aria-hidden="true"></i> Register to Bid Online
            </a>
            <a href="auctions.php" class="btn btn-outline">
                <i class="fas fa-gavel" aria-hidden="true"></i> Browse Live Lots
            </a>
        </div>
    </div>
</section>

<style>
/* ── Branch cards ────────────────────────────────────────── */
.branch-card { background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 16px rgba(0,63,115,.1); transition:transform .2s,box-shadow .2s; display:flex; flex-direction:column; }
.branch-card:hover { transform:translateY(-4px); box-shadow:0 8px 30px rgba(0,63,115,.18); }
.branch-card-img-wrap { position:relative; height:190px; background:#1a3a5c; overflow:hidden; }
.branch-card-img { width:100%; height:100%; object-fit:cover; display:block; }
.branch-card-badge { position:absolute; top:.7rem; left:.7rem; background:#e8a000; color:#fff; font-size:.65rem; font-weight:800; letter-spacing:.08em; padding:.22rem .65rem; border-radius:20px; }
.branch-card-body { padding:1.1rem 1.2rem 1.25rem; flex:1; display:flex; flex-direction:column; gap:.6rem; }
.branch-card-city { font-size:1rem; font-weight:700; color:#1a2a3a; margin:0; display:flex; align-items:center; gap:.4rem; }
.branch-card-city i { color:#005BA1; font-size:.9rem; }
.branch-card-address { font-style:normal; font-size:.82rem; color:#4a6080; line-height:1.6; margin:0; }
.branch-card-tags { display:flex; flex-wrap:wrap; gap:.35rem; margin-top:auto; }
.branch-tag { display:inline-flex; align-items:center; gap:.3rem; background:#eef3f9; color:#005BA1; border-radius:14px; padding:.2rem .6rem; font-size:.67rem; font-weight:700; letter-spacing:.05em; }

/* ── Online Sales Facility mosaic ────────────────────────── */
.sales-mosaic { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; border-radius:16px; overflow:hidden; margin-bottom:2.5rem; }
.sales-mosaic-main { grid-row: span 2; }
.sales-mosaic-grid { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
.sales-img-link { display:block; position:relative; overflow:hidden; height:100%; min-height:160px; background:#1a3a5c; }
.sales-mosaic-main .sales-img-link { min-height:340px; }
.sales-img { width:100%; height:100%; object-fit:cover; display:block; transition:transform .35s ease; }
.sales-img-link:hover .sales-img { transform:scale(1.05); }
.sales-img-overlay { position:absolute; inset:0; background:rgba(0,63,115,0); display:flex; align-items:center; justify-content:center; transition:background .25s; }
.sales-img-overlay i { color:#fff; font-size:1.4rem; opacity:0; transition:opacity .25s; }
.sales-img-link:hover .sales-img-overlay { background:rgba(0,63,115,.35); }
.sales-img-link:hover .sales-img-overlay i { opacity:1; }

/* ── Online Sales feature bullets ────────────────────────── */
.sales-features { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1.25rem; }
.sales-feat { display:flex; flex-direction:column; align-items:flex-start; gap:.4rem; padding:1.25rem; background:#f8fafc; border-radius:12px; border-left:3px solid #005BA1; }
.sales-feat i { font-size:1.3rem; color:#005BA1; }
.sales-feat strong { font-size:.8rem; font-weight:800; letter-spacing:.06em; color:#1a2a3a; text-transform:uppercase; }
.sales-feat span { font-size:.8rem; color:#4a6080; line-height:1.6; }

@media (max-width:640px) {
    .sales-mosaic { grid-template-columns:1fr; }
    .sales-mosaic-main { grid-row:span 1; }
    .sales-mosaic-main .sales-img-link { min-height:220px; }
}
</style>

<!-- ══════════════════════════════════════════════════════════
     CONTACT / FIND US
     ══════════════════════════════════════════════════════════ -->
<section class="section contact-section" id="contact">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Get In Touch</h2>
            <p class="section-subtitle">Four branches across South Africa — always close to you</p>
        </div>

        <div class="contact-grid">

            <!-- Contact info -->
            <div class="contact-info-col">
                <div class="contact-info-card">
                    <h3 class="contact-info-heading">Auction Hub Group</h3>

                    <div class="contact-info-row">
                        <i class="fas fa-location-dot" aria-hidden="true"></i>
                        <div>
                            <strong>Office Address</strong>
                            <address>
                                80 Sterling Rd<br>
                                Kosmosdal, Centurion, 0187
                            </address>
                        </div>
                    </div>
                    <div class="contact-info-row">
                        <i class="fas fa-warehouse" aria-hidden="true"></i>
                        <div>
                            <strong>Auction Ground</strong>
                            <address>
                                8 Top Rd, O.R. Tambo<br>
                                Boksburg, 1459
                            </address>
                        </div>
                    </div>

                    <div class="contact-info-row">
                        <i class="fas fa-envelope" aria-hidden="true"></i>
                        <div>
                            <strong>Email</strong>
                            <a href="mailto:<?= COMPANY_EMAIL ?>"><?= COMPANY_EMAIL ?></a>
                        </div>
                    </div>

                    <div class="contact-info-row">
                        <i class="fas fa-phone" aria-hidden="true"></i>
                        <div>
                            <strong>Phone</strong>
                            <a href="tel:<?= COMPANY_PHONE ?>"><?= COMPANY_PHONE ?></a>
                        </div>
                    </div>

                    <!-- Email Enquiries -->
                    <div class="contact-whatsapp-group" style="margin-bottom:1.25rem;">
                        <strong class="contact-wa-heading">
                            <i class="fas fa-envelope" aria-hidden="true"></i>
                            Enquiries
                        </strong>
                        <div class="contact-wa-buttons">
                            <a href="mailto:<?= COMPANY_EMAIL ?>?subject=General%20Enquiry"
                               class="contact-wa-btn" style="background:linear-gradient(135deg,#005BA1,#003f73);">
                                <i class="fas fa-envelope" aria-hidden="true"></i>
                                <?= COMPANY_EMAIL ?>
                            </a>
                        </div>
                    </div>

                    <!-- Contact Buttons -->
                    <div class="contact-whatsapp-group">
                        <strong class="contact-wa-heading">
                            <i class="fas fa-headset" aria-hidden="true"></i>
                            Call or Email Our Team
                        </strong>
                        <div class="contact-wa-buttons">
                            <a href="tel:<?= str_replace(' ','',COMPANY_PHONE) ?>" class="contact-wa-btn">
                                <i class="fas fa-phone" aria-hidden="true"></i>
                                <?= COMPANY_PHONE ?>
                            </a>
                            <a href="tel:<?= str_replace(' ','',COMPANY_PHONE2) ?>" class="contact-wa-btn">
                                <i class="fas fa-phone" aria-hidden="true"></i>
                                <?= COMPANY_PHONE2 ?>
                            </a>
                            <a href="mailto:<?= COMPANY_EMAIL ?>?subject=General%20Enquiry" class="contact-wa-btn">
                                <i class="fas fa-envelope" aria-hidden="true"></i>
                                <?= COMPANY_EMAIL ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Map embed -->
            <div class="contact-map-col">
                <div class="contact-map-wrap">
                    <iframe
                        src="https://maps.google.com/maps?q=89+Sterling+Rd+Kosmosdal+Centurion+0187&output=embed&z=15"
                        width="100%"
                        height="100%"
                        style="border:0; min-height: 380px;"
                        allowfullscreen=""
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        title="Auction Hub Group office location"
                        aria-label="Map showing 80 Sterling Rd, Kosmosdal, Centurion">
                    </iframe>
                </div>
            </div>

        </div><!-- /.contact-grid -->
    </div>
</section>


<?php require_once 'footer.php'; ?>
