<?php
// ============================================================
// AUCTION HUB GROUP — events.php
// Public auction events listing + single event view
// Professional, bank-partnered auction events
// ============================================================

require_once 'config.php';

// ── Single event view ─────────────────────────────────────────
$eventId = (int)($_GET['id'] ?? 0);
$event = null;
$eventVehicles = [];
$hotVehicles = [];

if ($eventId) {
    $stmt = $pdo->prepare("SELECT * FROM auction_events WHERE id=? AND status IN ('upcoming','live','completed')");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($event) {
        $evStmt = $pdo->prepare("
            SELECT a.id, a.item_name, a.year, a.status, a.current_price, a.start_price,
                   a.start_time, a.end_time, a.image_url, a.min_increment, a.condition_grade,
                   a.mileage, a.fuel_type, a.transmission,
                   m.name AS make_name, ev.is_hot, ev.lot_number, ev.sort_order,
                   COUNT(b.id) AS bid_count
            FROM event_vehicles ev
            JOIN auctions a ON a.id = ev.auction_id
            LEFT JOIN makes m ON m.id = a.make_id
            LEFT JOIN bids b ON b.auction_id = a.id
            WHERE ev.event_id = ?
            GROUP BY a.id
            ORDER BY ev.is_hot DESC, ev.sort_order, a.item_name
        ");
        $evStmt->execute([$eventId]);
        $eventVehicles = $evStmt->fetchAll();

        $hotVehicles = array_filter($eventVehicles, fn($v) => $v['is_hot']);
    }
}

// ── Events listing ────────────────────────────────────────────
$bankFilter    = trim($_GET['bank'] ?? '');
$provinceFilter = trim($_GET['province'] ?? '');

$where = ["e.status IN ('upcoming','live','completed')"];
$params = [];
if ($bankFilter) {
    $where[] = "e.bank_lot = ?";
    $params[] = $bankFilter;
}
if ($provinceFilter) {
    $where[] = "e.province = ?";
    $params[] = $provinceFilter;
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$eventsStmt = $pdo->prepare("
    SELECT e.*, COUNT(ev.id) AS vehicle_count, SUM(ev.is_hot) AS hot_count
    FROM auction_events e
    LEFT JOIN event_vehicles ev ON ev.event_id = e.id
    $whereSQL
    GROUP BY e.id
    ORDER BY FIELD(e.status,'live','upcoming','completed'), e.event_date ASC
    LIMIT 50
");
$eventsStmt->execute($params);
$events = $eventsStmt->fetchAll();

// ── Stats ────────────────────────────────────────────────────
$totalUpcoming = 0;
$totalVehicles = 0;
try {
    $totalUpcoming = (int)$pdo->query("SELECT COUNT(*) FROM auction_events WHERE status IN ('upcoming','live')")->fetchColumn();
    $totalVehicles = (int)$pdo->query("SELECT COUNT(DISTINCT ev.auction_id) FROM event_vehicles ev JOIN auction_events e ON e.id = ev.event_id WHERE e.status IN ('upcoming','live')")->fetchColumn();
} catch (Exception $e) {}

// ── Page setup ────────────────────────────────────────────────
$pageTitle = $event ? e($event['title']) : 'Auction Events & Sale Dates';
$pageDescription = $event ? e($event['description'] ?? 'Auction event at Auction Hub Group') : 'Browse upcoming bank auction events. We partner with Absa, Standard Bank, Nedbank (MFC), WesBank and more. DEKRA-inspected vehicles at below-market prices.';
require_once 'header.php';
?>

<main class="events-page" id="main-content">

<?php if ($event): ?>
<!-- ═══════════════════════════════════════════════════════════
     SINGLE EVENT VIEW
═══════════════════════════════════════════════════════════════ -->
<?php if (!empty($event['banner_image'])): ?>
<!-- Clean banner image — no overlay, no text, 16:9 standard (1920×1080) -->
<div class="event-banner-hero">
    <img src="<?= e($event['banner_image']) ?>" alt="<?= e($event['title']) ?>" loading="eager">
</div>
<?php endif; ?>

<section class="event-info-strip" style="background:linear-gradient(135deg,#000E2E 0%,#001F5C 50%,#005DDB 100%);padding:2.5rem 1.5rem 2rem;position:relative;overflow:hidden;">
    <?php if (empty($event['banner_image'])): ?>
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.05) 1px,transparent 1px);background-size:28px 28px;pointer-events:none;"></div>
    <?php endif; ?>
    <div class="container" style="position:relative;z-index:1;">
        <a href="events.php" style="color:rgba(255,255,255,.7);text-decoration:none;font-size:.85rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;margin-bottom:1.5rem;">
            <i class="fas fa-arrow-left"></i> All Events
        </a>
        <div style="display:grid;grid-template-columns:1fr auto;gap:2rem;align-items:center;">
            <div>
                <span class="badge badge-<?= $event['status']==='live'?'active':($event['status']==='upcoming'?'upcoming':'closed') ?>" style="font-size:.85rem;margin-bottom:.75rem;display:inline-block;">
                    <?= $event['status']==='live' ? '<span class="live-dot"></span> Live Now' : ucfirst($event['status']) ?>
                </span>
                <h1 style="color:#fff;font-size:clamp(1.8rem,4vw,2.8rem);line-height:1.1;margin-bottom:1rem;"><?= e($event['title']) ?></h1>
                <div style="display:flex;flex-wrap:wrap;gap:1rem;color:rgba(255,255,255,.8);font-size:.9rem;">
                    <?php if ($event['bank_lot']): ?>
                    <span style="display:inline-flex;align-items:center;gap:.4rem;background:rgba(255,255,255,.12);padding:.4rem .9rem;border-radius:8px;font-weight:600;">
                        <i class="fas fa-university"></i> <?= e($event['bank_lot']) ?>
                    </span>
                    <?php endif; ?>
                    <span style="display:inline-flex;align-items:center;gap:.4rem;"><i class="fas fa-calendar"></i> <?= safeDate('d M Y', $event['event_date'], 'TBC') ?></span>
                    <?php if ($event['event_time']): ?>
                    <span style="display:inline-flex;align-items:center;gap:.4rem;"><i class="fas fa-clock"></i> <?= date('H:i', strtotime($event['event_time'])) ?></span>
                    <?php endif; ?>
                    <?php if ($event['province']): ?>
                    <span style="display:inline-flex;align-items:center;gap:.4rem;"><i class="fas fa-map-marker-alt"></i> <?= e($event['province']) ?></span>
                    <?php endif; ?>
                    <?php if ($event['venue']): ?>
                    <span style="display:inline-flex;align-items:center;gap:.4rem;"><i class="fas fa-location-dot"></i> <?= e($event['venue']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($event['description']): ?>
                <p style="color:rgba(255,255,255,.75);font-size:.95rem;margin-top:1rem;max-width:640px;line-height:1.7;"><?= nl2br(e($event['description'])) ?></p>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:1rem;">
                <div style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:14px;padding:1.25rem 1.75rem;text-align:center;">
                    <div style="font-size:2.2rem;font-weight:800;color:#fff;font-family:'Barlow Condensed',sans-serif;"><?= count($eventVehicles) ?></div>
                    <div style="font-size:.75rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;">Vehicles</div>
                </div>
                <?php if (count($hotVehicles)): ?>
                <div style="background:rgba(226,88,34,.2);border:1px solid rgba(226,88,34,.4);border-radius:14px;padding:1.25rem 1.75rem;text-align:center;">
                    <div style="font-size:2.2rem;font-weight:800;color:#ff8a50;font-family:'Barlow Condensed',sans-serif;"><i class="fas fa-fire"></i> <?= count($hotVehicles) ?></div>
                    <div style="font-size:.75rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;">Hot List</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <style>@media(max-width:768px){.events-page section:first-child .container>div{grid-template-columns:1fr!important;}}</style>
</section>

<!-- Trust strip -->
<div style="background:rgba(0,20,75,0.50);border-bottom:1px solid rgba(30,144,255,0.15);padding:.75rem 1.5rem;">
    <div class="container" style="display:flex;align-items:center;justify-content:center;gap:2rem;flex-wrap:wrap;font-size:.82rem;color:rgba(255,255,255,0.65);font-weight:600;">
        <span><i class="fas fa-clipboard-check" style="color:#1E90FF;"></i> DEKRA Inspected</span>
        <span><i class="fas fa-university" style="color:#1E90FF;"></i> Bank-Sourced Vehicles</span>
        <span><i class="fas fa-shield-halved" style="color:#1E90FF;"></i> Below Market Value</span>
        <span><i class="fas fa-hand-holding-dollar" style="color:#1E90FF;"></i> In-House Finance Available</span>
    </div>
</div>

<?php if (!empty($hotVehicles)): ?>
<!-- ── Hot List ─────────────────────────────────────────── -->
<section class="section" style="background:rgba(0,20,75,0.50)8f0;">
    <div class="container">
        <div style="text-align:center;margin-bottom:2rem;">
            <div style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(226,88,34,.1);border:1px solid rgba(226,88,34,.3);border-radius:50px;padding:.4rem 1.25rem;margin-bottom:.75rem;">
                <i class="fas fa-fire" style="color:#e25822;"></i>
                <span style="color:#e25822;font-size:.8rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Don't Miss These</span>
            </div>
            <h2 style="color:#ffffff;font-size:clamp(1.3rem,3vw,1.8rem);">Hot List This Week</h2>
            <p style="color:rgba(255,255,255,0.55);font-size:.9rem;">Hand-picked vehicles with the highest demand. Move fast — these won't last.</p>
        </div>
        <div class="event-vehicles-grid">
            <?php foreach ($hotVehicles as $v):
                $vPrice = (float)($v['current_price'] ?: $v['start_price']);
                $vImg   = cldImg($v['image_url'], CLD_CARD) ?: 'assets/no-image.jpg';
                $vTitle = ($v['year'] ? $v['year'].' ' : '') . ($v['make_name'] ?? '') . ' ' . $v['item_name'];
                $stk    = $v['condition_grade'] ? dekraSticker($v['condition_grade']) : null;
            ?>
            <a href="auction.php?id=<?= $v['id'] ?>" class="event-vehicle-card event-vehicle-hot">
                <div class="ev-card-img">
                    <img src="<?= e($vImg) ?>" alt="<?= e($vTitle) ?>" loading="lazy">
                    <span class="ev-card-hot-badge"><i class="fas fa-fire"></i> HOT</span>
                    <span class="badge badge-<?= $v['status']==='active'?'active':'upcoming' ?> ev-card-status">
                        <?= $v['status']==='active' ? 'Live' : ucfirst($v['status']) ?>
                    </span>
                </div>
                <div class="ev-card-body">
                    <h3 class="ev-card-title"><?= e($vTitle) ?></h3>
                    <?php if ($stk && $stk['name']): ?>
                    <div style="display:inline-flex;align-items:center;gap:.35rem;font-size:.72rem;font-weight:700;color:<?= $stk['color'] ?>;background:rgba(0,20,60,0.7);border:1px solid <?= $stk['color'] ?>44;padding:.2rem .6rem;border-radius:4px;margin-bottom:.4rem;">
                        <i class="fas <?= $stk['icon'] ?>"></i> DEKRA <?= $stk['name'] ?>
                    </div>
                    <?php endif; ?>
                    <div class="ev-card-price"><?= formatRand($vPrice) ?></div>
                    <div class="ev-card-meta">
                        <span><i class="fas fa-gavel"></i> <?= $v['bid_count'] ?> bid<?= $v['bid_count']!=1?'s':'' ?></span>
                        <?php if ($v['mileage']): ?><span><i class="fas fa-road"></i> <?= number_format($v['mileage']) ?> km</span><?php endif; ?>
                        <?php if ($v['end_time']): ?>
                        <span><i class="fas fa-clock"></i> <?= date('d M H:i', strtotime($v['end_time'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ev-card-footer-btn"><i class="fas fa-gavel"></i> View &amp; Bid</div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ── All Event Vehicles ──────────────────────────────── -->
<section class="section">
    <div class="container">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:2rem;">
            <h2 class="section-title" style="margin:0;">All Vehicles (<?= count($eventVehicles) ?>)</h2>
            <?php if (!empty($eventVehicles)): ?>
            <div style="color:rgba(255,255,255,0.55);font-size:.85rem;">
                <i class="fas fa-clipboard-check" style="color:#1E90FF;"></i> All vehicles are DEKRA inspected and sourced directly from banks
            </div>
            <?php endif; ?>
        </div>
        <?php if (empty($eventVehicles)): ?>
            <div style="text-align:center;padding:4rem 1rem;background:rgba(0,15,55,0.60);border-radius:16px;border:2px dashed rgba(30,144,255,0.25);">
                <i class="fas fa-car" style="font-size:3rem;color:#ccc;display:block;margin-bottom:1rem"></i>
                <h3 style="color:#ffffff;margin-bottom:.5rem;">Vehicles Coming Soon</h3>
                <p style="color:rgba(255,255,255,0.55);max-width:400px;margin:0 auto;">Our team is hand-selecting vehicles for this event. Check back closer to the event date or register to be notified.</p>
            </div>
        <?php else: ?>
        <div class="event-vehicles-grid">
            <?php foreach ($eventVehicles as $v):
                $vPrice = (float)($v['current_price'] ?: $v['start_price']);
                $vImg   = cldImg($v['image_url'], CLD_CARD) ?: 'assets/no-image.jpg';
                $vTitle = ($v['year'] ? $v['year'].' ' : '') . ($v['make_name'] ?? '') . ' ' . $v['item_name'];
                $stk    = $v['condition_grade'] ? dekraSticker($v['condition_grade']) : null;
            ?>
            <a href="auction.php?id=<?= $v['id'] ?>" class="event-vehicle-card <?= $v['is_hot'] ? 'event-vehicle-hot' : '' ?>">
                <div class="ev-card-img">
                    <img src="<?= e($vImg) ?>" alt="<?= e($vTitle) ?>" loading="lazy">
                    <?php if ($v['is_hot']): ?>
                    <span class="ev-card-hot-badge"><i class="fas fa-fire"></i> HOT</span>
                    <?php endif; ?>
                    <span class="badge badge-<?= $v['status']==='active'?'active':'upcoming' ?> ev-card-status">
                        <?= $v['status']==='active' ? 'Live' : ucfirst($v['status']) ?>
                    </span>
                </div>
                <div class="ev-card-body">
                    <h3 class="ev-card-title"><?= e($vTitle) ?></h3>
                    <?php if ($stk && $stk['name']): ?>
                    <div style="display:inline-flex;align-items:center;gap:.35rem;font-size:.72rem;font-weight:700;color:<?= $stk['color'] ?>;background:rgba(0,20,60,0.7);border:1px solid <?= $stk['color'] ?>44;padding:.2rem .6rem;border-radius:4px;margin-bottom:.4rem;">
                        <i class="fas <?= $stk['icon'] ?>"></i> DEKRA <?= $stk['name'] ?>
                    </div>
                    <?php endif; ?>
                    <div class="ev-card-price"><?= formatRand($vPrice) ?></div>
                    <div class="ev-card-meta">
                        <span><i class="fas fa-gavel"></i> <?= $v['bid_count'] ?> bid<?= $v['bid_count']!=1?'s':'' ?></span>
                        <?php if ($v['mileage']): ?><span><i class="fas fa-road"></i> <?= number_format($v['mileage']) ?> km</span><?php endif; ?>
                        <?php if ($v['end_time']): ?>
                        <span><i class="fas fa-clock"></i> <?= date('d M H:i', strtotime($v['end_time'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ev-card-footer-btn"><i class="fas fa-gavel"></i> View &amp; Bid</div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Event CTA -->
<section style="background:linear-gradient(135deg,#000E2E,#005DDB);padding:3rem 1.5rem;text-align:center;">
    <div class="container">
        <h2 style="color:#fff;font-size:clamp(1.3rem,3vw,1.8rem);margin-bottom:.75rem;">Ready to Bid at This Event?</h2>
        <p style="color:rgba(255,255,255,.7);margin-bottom:1.5rem;font-size:.95rem;">Register now and secure your spot. R10,000 refundable deposit gets you started.</p>
        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:.75rem;">
            <a href="login.php?tab=register" style="background:rgba(0,20,75,0.50);color:#1E90FF;padding:.8rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;">
                <i class="fas fa-user-plus"></i> Register to Bid
            </a>
            <a href="mailto:<?= COMPANY_EMAIL ?>?subject=Event%20Enquiry%20—%20<?= urlencode($event['title']) ?>"
               style="background:rgba(30,144,255,0.18);color:#fff;padding:.8rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;border:1px solid rgba(255,255,255,.3);display:inline-flex;align-items:center;gap:.4rem;">
                <i class="fas fa-envelope"></i> Enquire About This Event
            </a>
        </div>
    </div>
</section>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════
     EVENTS LISTING — PROFESSIONAL LANDING
═══════════════════════════════════════════════════════════════ -->

<!-- Hero -->
<section style="background:linear-gradient(135deg,#000E2E 0%,#001F5C 50%,#005DDB 100%);padding:5rem 1.5rem 4rem;text-align:center;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.05) 1px,transparent 1px);background-size:28px 28px;pointer-events:none;"></div>
    <div class="container" style="position:relative;z-index:1;">
        <div style="display:inline-block;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);border-radius:50px;padding:.4rem 1.25rem;margin-bottom:1.25rem;">
            <span style="color:#fff;font-size:.82rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">
                <i class="fas fa-university"></i> Partnered with South Africa's Leading Banks
            </span>
        </div>
        <h1 style="color:#fff;font-size:clamp(2.2rem,5vw,3.4rem);line-height:1.1;margin-bottom:1.25rem;">
            Auction Events
        </h1>
        <p style="color:rgba(255,255,255,.85);font-size:1.1rem;max-width:700px;margin:0 auto 2rem;line-height:1.7;">
            Every auction event is curated with <strong style="color:#fff;">hand-selected vehicles</strong> sourced directly from South Africa's major banks. DEKRA inspected, below market value, and ready for new owners. Each event is unique — don't miss your opportunity.
        </p>

        <!-- Live stats -->
        <div style="display:flex;justify-content:center;gap:1.5rem;flex-wrap:wrap;margin-bottom:2rem;">
            <div style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:12px;padding:1rem 1.75rem;text-align:center;">
                <div style="font-size:2rem;font-weight:800;color:#fff;font-family:'Barlow Condensed',sans-serif;"><?= $totalUpcoming ?></div>
                <div style="font-size:.72rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;">Upcoming Events</div>
            </div>
            <div style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:12px;padding:1rem 1.75rem;text-align:center;">
                <div style="font-size:2rem;font-weight:800;color:#6eff9e;font-family:'Barlow Condensed',sans-serif;"><?= $totalVehicles ?></div>
                <div style="font-size:.72rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;">Vehicles Listed</div>
            </div>
            <div style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:12px;padding:1rem 1.75rem;text-align:center;">
                <div style="font-size:2rem;font-weight:800;color:#ffd54f;font-family:'Barlow Condensed',sans-serif;">5+</div>
                <div style="font-size:.72rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;">Bank Partners</div>
            </div>
        </div>

        <nav aria-label="Breadcrumb" style="color:rgba(255,255,255,.5);font-size:.87rem;">
            <a href="<?= APP_URL ?>/index.php" style="color:rgba(255,255,255,.6);text-decoration:none;">Home</a>
            <span style="margin:0 .5rem;">/</span>
            <span style="color:#fff;">Auction Events</span>
        </nav>
    </div>
</section>

<!-- ═══════════ BANK PARTNERS STRIP ═══════════ -->
<section style="background:rgba(0,20,75,0.50);border-bottom:1px solid rgba(30,144,255,0.15);padding:2.5rem 1.5rem;">
    <div class="container">
        <div style="text-align:center;margin-bottom:1.75rem;">
            <h2 style="color:#ffffff;font-size:1.2rem;margin-bottom:.5rem;">We Work with All Major Banks</h2>
            <p style="color:rgba(255,255,255,0.55);font-size:.87rem;">Each auction event features vehicles sourced directly from bank repossessions, liquidations, and fleet disposals.</p>
        </div>
        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:1rem;">
            <?php
            $banks = [
                ['Absa', '#af1f24', 'fa-university'],
                ['Standard Bank', '#003b7c', 'fa-university'],
                ['Nedbank (MFC)', '#009944', 'fa-university'],
                ['WesBank', '#00457c', 'fa-university'],
                ['FNB', '#009bdb', 'fa-university'],
                ['Capitec', '#e31837', 'fa-university'],
                ['Private Sellers', '#555', 'fa-user-tie'],
            ];
            foreach ($banks as [$name, $color, $icon]):
            ?>
            <div style="display:flex;align-items:center;gap:.6rem;background:rgba(0,15,55,0.60);border:1px solid rgba(30,144,255,0.20);border-radius:10px;padding:.65rem 1.25rem;transition:all .2s;"
                 onmouseover="this.style.borderColor='<?= $color ?>';this.style.boxShadow='0 2px 12px rgba(0,0,0,.08)'"
                 onmouseout="this.style.borderColor='#e5eaf0';this.style.boxShadow='none'">
                <div style="width:32px;height:32px;background:<?= $color ?>;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas <?= $icon ?>" style="color:#fff;font-size:.75rem;"></i>
                </div>
                <span style="font-size:.87rem;font-weight:600;color:#ffffff;"><?= $name ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════ HOW EVENTS WORK ═══════════ -->
<section style="background:rgba(0,15,58,0.55);padding:3rem 1.5rem;">
    <div class="container" style="max-width:1080px;">
        <div style="text-align:center;margin-bottom:2rem;">
            <h2 style="color:#ffffff;font-size:1.35rem;">How Our Auction Events Work</h2>
            <p style="color:rgba(255,255,255,0.55);font-size:.88rem;">Each event is a unique opportunity to purchase quality vehicles at below-market prices.</p>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.25rem;">
            <?php
            $steps = [
                ['01','fa-university','Bank Releases Stock','Banks provide us with repossessed, fleet, and insolvency vehicles for each auction event.','#005BA1'],
                ['02','fa-clipboard-check','We Inspect & Select','Every vehicle undergoes a professional DEKRA inspection. We hand-select the best vehicles for each event.','#0078d7'],
                ['03','fa-calendar-check','Event Goes Live','The event is published with full vehicle details, photos, and DEKRA sticker status. Register to bid.','#e8a000'],
                ['04','fa-gavel','You Bid & Win','Bid on your chosen vehicles during the live auction. Win and drive away at below-market price.','#25D366'],
            ];
            foreach ($steps as [$num,$icon,$title,$desc,$color]):
            ?>
            <div style="background:rgba(0,20,75,0.50);border-radius:14px;padding:1.5rem;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.05);">
                <div style="width:52px;height:52px;background:<?= $color ?>;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;position:relative;">
                    <i class="fas <?= $icon ?>" style="color:#fff;font-size:1.1rem;"></i>
                    <span style="position:absolute;top:-4px;right:-4px;background:#003f73;color:#fff;font-size:.6rem;font-weight:800;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><?= $num ?></span>
                </div>
                <h4 style="color:#ffffff;font-size:.93rem;margin-bottom:.4rem;"><?= $title ?></h4>
                <p style="color:rgba(255,255,255,0.65);font-size:.82rem;line-height:1.6;"><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════ EVENTS LIST WITH FILTERS ═══════════ -->
<section class="section" style="background:rgba(0,20,75,0.50);">
    <div class="container">
        <div style="text-align:center;margin-bottom:2rem;">
            <h2 style="color:#ffffff;font-size:clamp(1.3rem,3vw,1.8rem);">Browse Auction Events</h2>
            <p style="color:rgba(255,255,255,0.55);font-size:.9rem;">Filter by bank lot or province to find events near you.</p>
        </div>

        <!-- Filter Bar -->
        <form class="event-filter-bar" method="get" style="margin-bottom:2rem;">
            <select name="bank" class="form-control">
                <option value="">All Bank Lots</option>
                <?php foreach (['Absa Lot','Nedbank (MFC) Lot','Standard Bank Lot','Wesbank Lot','FNB Lot','Capitec Lot','Mixed Lot','Private Seller Lot'] as $bl): ?>
                <option value="<?= $bl ?>" <?= $bankFilter===$bl?'selected':'' ?>><?= $bl ?></option>
                <?php endforeach; ?>
            </select>
            <select name="province" class="form-control">
                <option value="">All Provinces</option>
                <?php foreach (['Gauteng','Western Cape','KwaZulu-Natal','Eastern Cape','Free State','Limpopo','Mpumalanga','North West','Northern Cape'] as $p): ?>
                <option value="<?= $p ?>" <?= $provinceFilter===$p?'selected':'' ?>><?= $p ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($bankFilter || $provinceFilter): ?>
            <a href="events.php" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>

        <?php if (empty($events)): ?>
        <div style="text-align:center;padding:4rem 1rem;background:rgba(0,15,55,0.60);border-radius:16px;border:2px dashed rgba(30,144,255,0.25);">
            <i class="fas fa-calendar-times" style="font-size:3rem;color:#ccc;display:block;margin-bottom:1rem"></i>
            <h3 style="color:#ffffff;margin-bottom:.5rem;">No Events Found</h3>
            <p style="color:rgba(255,255,255,0.55);max-width:400px;margin:0 auto;">No events match your current filters. Try clearing your filters or check back soon — new events are added regularly.</p>
            <?php if ($bankFilter || $provinceFilter): ?>
            <a href="events.php" class="btn btn-primary" style="margin-top:1rem;"><i class="fas fa-times"></i> Clear Filters</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="events-list-grid">
            <?php foreach ($events as $evt):
                $bannerSrc = !empty($evt['banner_image'])
                    ? $evt['banner_image']
                    : 'assets/event-placeholder.jpg';
            ?>
            <a href="events.php?id=<?= $evt['id'] ?>" class="event-list-card <?= $evt['status']==='live'?'event-list-live':'' ?>">
                <div class="event-list-banner">
                    <img src="<?= e($bannerSrc) ?>" alt="<?= e($evt['title']) ?>" loading="lazy"
                         onerror="this.style.display='none';this.parentNode.classList.add('event-list-banner-fallback');">
                    <div class="event-list-banner-overlay">
                        <span class="badge badge-<?= $evt['status']==='live'?'active':($evt['status']==='upcoming'?'upcoming':'closed') ?>">
                            <?= $evt['status']==='live' ? '<span class="live-dot"></span> Live Now' : ucfirst($evt['status']) ?>
                        </span>
                        <?php if ((int)$evt['hot_count'] > 0): ?>
                        <span class="event-list-hot"><i class="fas fa-fire"></i> <?= (int)$evt['hot_count'] ?> Hot</span>
                        <?php endif; ?>
                    </div>
                    <div class="event-list-date">
                        <span class="eld-day"><?= safeDate('d', $evt['event_date'], '—') ?></span>
                        <span class="eld-month"><?= safeDate('M', $evt['event_date'], '') ?></span>
                        <span class="eld-year"><?= safeDate('Y', $evt['event_date'], '') ?></span>
                    </div>
                </div>
                <div class="event-list-body">
                    <?php if ($evt['bank_lot']): ?>
                    <div class="event-list-top">
                        <span class="event-list-bank"><i class="fas fa-university"></i> <?= e($evt['bank_lot']) ?></span>
                    </div>
                    <?php endif; ?>
                    <h3 class="event-list-title"><?= e($evt['title']) ?></h3>
                    <div class="event-list-meta">
                        <?php if ($evt['province']): ?>
                        <span><i class="fas fa-map-marker-alt"></i> <?= e($evt['province']) ?></span>
                        <?php endif; ?>
                        <?php if ($evt['event_time']): ?>
                        <span><i class="fas fa-clock"></i> <?= date('H:i', strtotime($evt['event_time'])) ?></span>
                        <?php endif; ?>
                        <span><i class="fas fa-car"></i> <?= (int)$evt['vehicle_count'] ?> vehicles</span>
                    </div>
                    <?php if ($evt['venue']): ?>
                    <p class="event-list-venue"><i class="fas fa-location-dot"></i> <?= e($evt['venue']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($evt['description'])): ?>
                    <p class="event-list-desc"><?= e(mb_strimwidth($evt['description'], 0, 140, '…')) ?></p>
                    <?php endif; ?>
                    <span class="event-list-cta">View Event <i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</section>

<!-- ═══════════ WHY ATTEND OUR EVENTS ═══════════ -->
<section style="background:rgba(0,15,58,0.55);padding:3rem 1.5rem;">
    <div class="container" style="max-width:1080px;">
        <div style="text-align:center;margin-bottom:2rem;">
            <h2 style="color:#ffffff;font-size:1.35rem;">Why Attend a Auction Hub Auction Event?</h2>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:1.25rem;">
            <?php
            $reasons = [
                ['fa-tags','Below Market Prices','Every vehicle is priced significantly below retail value. Bank repossessions and insolvency stock mean real savings for you.','#005BA1'],
                ['fa-clipboard-check','DEKRA Inspected','Full transparency. Every vehicle comes with a DEKRA inspection sticker — Green, Gold, or Platinum — so you know exactly what you\'re buying.','#4caf50'],
                ['fa-hand-holding-dollar','In-House Finance','No credit checks. Blacklisted welcome. From R10,500 deposit with terms up to 48 months. Everyone qualifies.','#e8a000'],
                ['fa-university','Bank-Sourced','We partner with Absa, Standard Bank, Nedbank (MFC), WesBank, FNB, Capitec, and private sellers. Fresh stock at every event.','#0078d7'],
                ['fa-shield-halved','<?= date(\'Y\')-2016 ?>+ Years Trusted','Founded in 2016. Award-winning auctioneers trusted by South Africa\'s largest financial institutions.','#003f73'],
                ['fa-truck-fast','Nationwide Delivery','Can\'t attend in person? We offer nationwide delivery to your door after you win your vehicle.','#25D366'],
            ];
            foreach ($reasons as [$icon,$title,$desc,$color]):
            ?>
            <div style="background:rgba(0,20,75,0.50);border-radius:14px;padding:1.5rem;box-shadow:0 2px 12px rgba(0,0,0,.05);border-left:4px solid <?= $color ?>;">
                <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem;">
                    <div style="width:36px;height:36px;background:<?= $color ?>;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas <?= $icon ?>" style="color:#fff;font-size:.85rem;"></i>
                    </div>
                    <h4 style="color:#ffffff;font-size:.95rem;margin:0;"><?= $title ?></h4>
                </div>
                <p style="color:rgba(255,255,255,0.65);font-size:.85rem;line-height:1.65;"><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════ CTA ═══════════ -->
<section style="background:linear-gradient(135deg,#000E2E,#005DDB);padding:3.5rem 1.5rem;text-align:center;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.04) 1px,transparent 1px);background-size:22px 22px;pointer-events:none;"></div>
    <div class="container" style="position:relative;z-index:1;">
        <h2 style="color:#fff;font-size:clamp(1.5rem,3vw,2rem);margin-bottom:.75rem;">Don't Miss the Next Auction Event</h2>
        <p style="color:rgba(255,255,255,.8);max-width:560px;margin:0 auto 2rem;font-size:1rem;line-height:1.7;">
            Register today and be the first to know when new events go live. R10,000 refundable deposit secures your bidder status. Quality vehicles at below-market prices await.
        </p>
        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:.75rem;">
            <a href="login.php?tab=register" style="background:rgba(0,20,75,0.50);color:#1E90FF;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:.5rem;font-size:.95rem;">
                <i class="fas fa-user-plus"></i> Register to Bid
            </a>
            <a href="auctions.php" style="background:rgba(30,144,255,0.18);color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;border:1px solid rgba(255,255,255,.3);display:inline-flex;align-items:center;gap:.5rem;font-size:.95rem;">
                <i class="fas fa-gavel"></i> Browse All Vehicles
            </a>
            <a href="mailto:<?= COMPANY_EMAIL ?>?subject=Auction%20Event%20Enquiry"
               style="background:rgba(30,144,255,0.18);color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;border:1px solid rgba(255,255,255,.3);display:inline-flex;align-items:center;gap:.5rem;font-size:.95rem;">
                <i class="fas fa-envelope"></i> Enquire via Email
            </a>
        </div>
    </div>
</section>

<?php endif; ?>

</main>

<?php require_once 'footer.php'; ?>
