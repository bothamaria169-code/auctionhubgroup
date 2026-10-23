<?php
// ============================================================
// AUCTION HUB GROUP — preauction.php
// Pre-Auction Purchase — professional selling page
// ============================================================
$pageTitle       = 'Pre-Auction Purchase — Buy at the Bank\'s Price, Not Retail';
$pageDescription = 'Access repossessed and bank-stock vehicles at the financial institution\'s outstanding balance — up to 60% below retail. DEKRA-inspected, free delivery, 100% refund guarantee. Secure yours before it hits the auction floor.';
$bodyClass       = 'page-preauction';
require_once __DIR__ . '/header.php';

$preAuctionVehicles = $pdo->query("
    SELECT a.id, a.item_name, a.year, a.mileage, a.fuel_type, a.transmission,
           a.color, a.body_type, a.engine_size, a.start_price, a.reserve_price,
           a.current_price, a.min_increment, a.status, a.end_time, a.start_time,
           a.image_url, a.description, a.condition_grade, a.service_history,
           a.branch_id,
           m.name AS make_name,
           b2.name AS branch_name,
           b2.city AS branch_city,
           b2.province AS branch_province,
           (SELECT COUNT(*) FROM bids bx WHERE bx.auction_id = a.id) AS bid_count
    FROM auctions a
    LEFT JOIN makes m  ON m.id  = a.make_id
    LEFT JOIN branches b2 ON b2.id = a.branch_id
    WHERE a.status IN ('upcoming','active')
    ORDER BY a.status DESC, a.end_time ASC
")->fetchAll();

$totalDeals = count($preAuctionVehicles);

// Build branch counts for filter tabs
$branchCounts = [];
foreach ($preAuctionVehicles as $v) {
    $bid = $v['branch_id'] ?? 0;
    $branchCounts[$bid] = ($branchCounts[$bid] ?? 0) + 1;
}
$allBranches = $pdo->query("SELECT id, name, city, province FROM branches ORDER BY sort_order")->fetchAll();
?>

<style>
/* ── Pre-Auction Page Styles ─────────────────────────────── */
.pa-section { padding:4rem 1.5rem; }
.pa-container { max-width:1200px; margin:0 auto; }
.pa-section-label {
    display:inline-block; background:rgba(0,91,161,.1); color:#005BA1;
    font-size:.72rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase;
    padding:.35rem 1rem; border-radius:50px; border:1px solid rgba(0,91,161,.2);
    margin-bottom:1rem;
}
.pa-section-title {
    font-family:'Barlow Condensed',sans-serif; font-size:clamp(1.6rem,3.5vw,2.2rem);
    font-weight:800; color:#003f73; line-height:1.15; margin-bottom:.75rem;
}
.pa-section-sub {
    font-size:.95rem; color:#5a6a7a; line-height:1.75; max-width:620px;
}

/* Benefit cards */
.pa-benefit-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(310px,1fr)); gap:1.25rem; margin-top:2.5rem; }
.pa-benefit-card {
    background:#fff; border-radius:14px; padding:1.5rem 1.5rem 1.5rem 1.75rem;
    box-shadow:0 2px 16px rgba(0,0,0,.06); display:flex; gap:1.25rem;
    align-items:flex-start; border-left:4px solid var(--accent);
    transition:transform .2s, box-shadow .2s;
}
.pa-benefit-card:hover { transform:translateY(-3px); box-shadow:0 8px 28px rgba(0,0,0,.1); }
.pa-benefit-icon {
    width:48px; height:48px; background:var(--accent); border-radius:12px;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.pa-benefit-icon i { color:#fff; font-size:1.1rem; }
.pa-benefit-card h3 { color:#003f73; font-size:.97rem; font-weight:700; margin-bottom:.4rem; }
.pa-benefit-card p { color:#556; font-size:.86rem; line-height:1.7; margin:0; }

/* Steps */
.pa-steps { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:0; margin-top:2.5rem; position:relative; }
.pa-step {
    text-align:center; padding:2rem 1.5rem; position:relative;
    background:#fff; border-radius:0;
}
.pa-step:not(:last-child)::after {
    content:''; position:absolute; right:0; top:50%; transform:translateY(-50%);
    width:0; height:0; border-top:12px solid transparent; border-bottom:12px solid transparent;
    border-left:14px solid #e0e8f0; z-index:1;
}
.pa-step-num {
    width:56px; height:56px; background:linear-gradient(135deg,#003f73,#005BA1);
    border-radius:50%; display:flex; align-items:center; justify-content:center;
    margin:0 auto 1rem; position:relative;
    box-shadow:0 4px 16px rgba(0,91,161,.3);
}
.pa-step-num span { color:#fff; font-family:'Barlow Condensed',sans-serif; font-size:1.3rem; font-weight:800; }
.pa-step h4 { color:#003f73; font-size:.95rem; font-weight:700; margin-bottom:.5rem; }
.pa-step p { color:#556; font-size:.84rem; line-height:1.7; }

/* Commitment fee explainer */
.pa-fee-box {
    background:linear-gradient(135deg,#f0f6fc,#e8f4fd); border:1.5px solid #b8d4ee;
    border-radius:16px; padding:2rem 2.5rem;
}
.pa-fee-example {
    background:#fff; border-radius:12px; padding:1.5rem;
    box-shadow:0 2px 12px rgba(0,91,161,.1);
}
.pa-fee-row { display:flex; justify-content:space-between; align-items:center; padding:.5rem 0; font-size:.9rem; border-bottom:1px solid #eef3f8; }
.pa-fee-row:last-child { border-bottom:none; }
.pa-fee-row .lbl { color:#556; }
.pa-fee-row .val { font-weight:700; color:#003f73; }
.pa-fee-row.fee-line .val { color:#a30000; }
.pa-fee-row.outstanding-line .val { color:#005BA1; font-size:1.05rem; }
.pa-fee-row.save-line { background:#e6f9ee; border-radius:8px; padding:.65rem .75rem; margin-top:.5rem; }
.pa-fee-row.save-line .val { color:#155724; }

/* Institution strip */
.pa-institution-strip {
    background:#fff; border-radius:12px; padding:1.25rem 2rem;
    box-shadow:0 2px 12px rgba(0,0,0,.05); margin-top:2rem;
    display:flex; align-items:center; gap:1.5rem; flex-wrap:wrap;
}
.pa-institution-strip .inst-title { font-size:.78rem; color:#888; font-weight:700; text-transform:uppercase; letter-spacing:.08em; margin-bottom:.5rem; }
.pa-institution-logos { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; }
.pa-inst-badge {
    background:#f5f8fc; border:1.5px solid #d8e4f0; border-radius:8px;
    padding:.5rem .75rem; font-size:.78rem; font-weight:700; color:#003f73;
    letter-spacing:.04em; white-space:nowrap;
}
@media (max-width:600px) {
    .pa-institution-strip { padding:.9rem 1rem; gap:.75rem; }
    .pa-institution-logos { gap:.5rem; }
    .pa-inst-badge { padding:.4rem .6rem; font-size:.73rem; }
}

/* Value comparison */
.pa-compare { display:grid; grid-template-columns:1fr auto 1fr; gap:1rem; align-items:center; margin-top:2rem; }
.pa-compare-col { background:#fff; border-radius:14px; padding:1.5rem; text-align:center; box-shadow:0 2px 12px rgba(0,0,0,.06); }
.pa-compare-col.pva { border:2px solid #005BA1; }
.pa-compare-col .col-label { font-size:.72rem; text-transform:uppercase; letter-spacing:.1em; font-weight:700; margin-bottom:.75rem; }
.pa-compare-col .col-price { font-size:1.5rem; font-weight:900; margin:.3rem 0; }
.pa-compare-col .col-note { font-size:.78rem; color:#888; line-height:1.5; }
.pa-compare-vs { font-size:1.1rem; font-weight:800; color:#888; text-align:center; }

/* Vehicle cards */
.pa-vehicle-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:1.5rem; margin-top:2rem; }
.pa-card {
    background:#fff; border-radius:14px; overflow:hidden;
    box-shadow:0 2px 20px rgba(0,0,0,.07); display:flex; flex-direction:column;
    transition:transform .2s, box-shadow .2s;
}
.pa-card:hover { transform:translateY(-4px); box-shadow:0 10px 36px rgba(0,0,0,.12); }
.pa-card-img { height:210px; background:#e5eaf0; position:relative; overflow:hidden; }
.pa-card-img img { width:100%; height:100%; object-fit:cover; }
.pa-card-body { padding:1.25rem; flex:1; display:flex; flex-direction:column; }

/* FAQ */
.pa-faq-item {
    background:#fff; border-radius:10px; border:1.5px solid #e0e8f0;
    margin-bottom:.75rem; overflow:hidden;
}
.pa-faq-q {
    padding:1rem 1.25rem; font-weight:700; color:#003f73; font-size:.92rem;
    cursor:pointer; display:flex; justify-content:space-between; align-items:center;
    transition:background .15s;
}
.pa-faq-q:hover { background:#f5f8fc; }
.pa-faq-q .faq-icon { font-size:.8rem; color:#005BA1; transition:transform .2s; }
.pa-faq-q.open .faq-icon { transform:rotate(180deg); }
.pa-faq-a {
    padding:0 1.25rem; max-height:0; overflow:hidden; transition:max-height .3s ease, padding .2s;
    font-size:.88rem; color:#556; line-height:1.75;
}
.pa-faq-a.open { padding:.75rem 1.25rem 1.25rem; max-height:400px; }

/* Urgency bar */
.pa-urgency-bar {
    background:linear-gradient(90deg,#a30000,#c0392b);
    color:#fff; padding:.65rem 1.5rem; text-align:center;
    font-size:.82rem; font-weight:700; letter-spacing:.03em;
}
.pa-urgency-bar strong { color:#ffd54f; }

/* Timeline badge */
.pa-timeline-badge {
    display:inline-flex; align-items:center; gap:.4rem;
    background:#fff3cd; color:#856404; border:1px solid #ffc107;
    border-radius:20px; padding:.3rem .85rem; font-size:.75rem; font-weight:700;
    margin-bottom:.5rem;
}

@media(max-width:768px) {
    .pa-compare { grid-template-columns:1fr; }
    .pa-compare-vs { display:none; }
    .pa-steps { grid-template-columns:1fr; }
    .pa-step:not(:last-child)::after { display:none; }
    .pa-fee-box { padding:1.25rem; }
    .pa-section { padding:2.5rem 1rem; }
    .pa-what-grid { grid-template-columns:1fr !important; gap:2rem !important; }
    .pa-fee-grid  { grid-template-columns:1fr !important; gap:2rem !important; }
    .pa-dekra-grid { grid-template-columns:1fr !important; gap:2rem !important; }
    .pa-finance-grid { grid-template-columns:1fr !important; }
    .pa-benefit-grid { grid-template-columns:1fr !important; }
    .pa-steps-inner  { grid-template-columns:1fr 1fr !important; }
    .pa-vehicle-grid { grid-template-columns:1fr !important; }
}
@media(max-width:480px) {
    .pa-steps-inner { grid-template-columns:1fr !important; }
    .pa-urgency-bar { font-size:.75rem; padding:.5rem 1rem; line-height:1.5; }
    .pa-section-title { font-size:1.5rem; }
    .pa-fee-row { font-size:.82rem; }
    .pa-card-btns { grid-template-columns:1fr !important; }
    .pa-institution-strip { padding:.85rem 1rem; gap:.75rem; }
    .pa-inst-badge { font-size:.72rem; padding:.35rem .65rem; }
    #branchTabs { gap:.4rem; }
    .pa-branch-tab { font-size:.75rem !important; padding:.45rem .85rem !important; }
}

/* ── Branch filter tabs ────────────────────────────────────── */
.pa-branch-tab {
    display:inline-flex; align-items:center; gap:.45rem;
    background:#f0f4f8; border:1.5px solid #d0dce8; color:#445566;
    font-size:.82rem; font-weight:700; padding:.52rem 1.1rem;
    border-radius:50px; cursor:pointer; transition:all .2s;
    font-family:'Barlow',sans-serif; letter-spacing:.02em;
    white-space:nowrap;
}
.pa-branch-tab:hover {
    background:#e2ecf8; border-color:#a8c4e0; color:#003f73;
}
.pa-branch-tab.active {
    background:linear-gradient(135deg,#005BA1,#003f73);
    border-color:#003f73; color:#fff;
    box-shadow:0 4px 14px rgba(0,91,161,.3);
}
.pa-branch-tab.active .pa-tab-count {
    background:rgba(255,255,255,.25); color:#fff;
}
.pa-tab-count {
    background:#dde8f5; color:#005BA1; font-size:.7rem; font-weight:800;
    padding:.1rem .45rem; border-radius:10px; min-width:20px; text-align:center;
}
</style>

<!-- ══════════════════════════════════════════════════════════
     URGENCY BAR
══════════════════════════════════════════════════════════ -->
<div class="pa-urgency-bar">
    <i class="fas fa-fire"></i>&nbsp;
    <strong><?= $totalDeals ?> vehicle<?= $totalDeals !== 1 ? 's' : '' ?></strong> available for pre-auction purchase right now —
    <strong>vehicles move to auction once the commitment window closes. Act fast.</strong>
</div>

<!-- ══════════════════════════════════════════════════════════
     HERO
══════════════════════════════════════════════════════════ -->
<section style="background:linear-gradient(135deg,#002850 0%,#003f73 45%,#005BA1 100%);padding:5rem 1.5rem 4.5rem;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.035) 1px,transparent 1px);background-size:30px 30px;pointer-events:none;"></div>
    <div style="position:absolute;right:-80px;top:-80px;width:400px;height:400px;background:radial-gradient(circle,rgba(0,212,255,.08),transparent 70%);pointer-events:none;"></div>

    <div class="pa-container" style="position:relative;z-index:1;text-align:center;">
        <nav style="color:rgba(255,255,255,.4);font-size:.82rem;margin-bottom:1.5rem;">
            <a href="<?= APP_URL ?>" style="color:rgba(255,255,255,.55);text-decoration:none;">Home</a>
            <span style="margin:0 .5rem;">/</span>
            <span style="color:#fff;">Pre-Auction Purchase</span>
        </nav>

        <span style="display:inline-block;background:rgba(37,211,102,.15);color:#6eff9e;font-size:.72rem;font-weight:700;padding:.4rem 1.25rem;border-radius:50px;letter-spacing:.12em;text-transform:uppercase;margin-bottom:1.25rem;border:1px solid rgba(37,211,102,.3);">
            <i class="fas fa-building-columns"></i>&nbsp; Direct From Financial Institutions
        </span>

        <h1 style="color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:clamp(2.2rem,5.5vw,3.6rem);line-height:1.08;font-weight:800;margin-bottom:1.25rem;letter-spacing:.02em;">
            Buy the Vehicle at the<br>
            <span style="color:#00d4ff;">Bank's Price.</span>
            <span style="color:rgba(255,255,255,.5);font-size:.65em;"> Not Retail.</span>
        </h1>

        <p style="color:rgba(255,255,255,.85);font-size:1.05rem;max-width:680px;margin:0 auto 1.25rem;line-height:1.8;">
            Financial institutions — banks, credit providers, and asset financiers — repossess vehicles when owners default on payments.
            These vehicles must be sold to recover the outstanding debt.
            <strong style="color:#ffd54f;">Auction Hub Group gives you exclusive access to these vehicles before they ever reach the auction floor</strong> —
            at the outstanding loan balance, not market value.
        </p>

        <p style="color:rgba(255,255,255,.55);font-size:.88rem;max-width:580px;margin:0 auto 2.5rem;line-height:1.7;">
            This is your opportunity to own a fully DEKRA-inspected vehicle at <strong style="color:#fff;">30–60% below retail price</strong>.
            No bidding wars. No commissions. No surprises.
        </p>

        <!-- Hero stats -->
        <div style="display:flex;justify-content:center;gap:3rem;flex-wrap:wrap;margin-bottom:3rem;">
            <div style="text-align:center;">
                <div style="font-size:2.2rem;font-weight:900;color:#00d4ff;font-family:'Barlow Condensed',sans-serif;"><?= $totalDeals ?></div>
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);">Vehicles Available Now</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:2.2rem;font-weight:900;color:#ffd54f;font-family:'Barlow Condensed',sans-serif;">30–60%</div>
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);">Below Retail Value</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:2.2rem;font-weight:900;color:#6eff9e;font-family:'Barlow Condensed',sans-serif;">0%</div>
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);">Buyer's Commission</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:2.2rem;font-weight:900;color:#ff9f43;font-family:'Barlow Condensed',sans-serif;">24h</div>
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);">Commitment Window</div>
            </div>
        </div>

        <div style="display:flex;justify-content:center;gap:.85rem;flex-wrap:wrap;">
            <a href="#vehicles"
               style="background:#005BA1;color:#fff;padding:.9rem 2.25rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.95rem;display:inline-flex;align-items:center;gap:.5rem;border:2px solid #005BA1;">
                <i class="fas fa-car"></i> Browse Vehicles
            </a>
            <a href="mailto:<?= COMPANY_EMAIL ?>?subject=Pre-Auction%20Purchase%20Enquiry"
               style="background:#fff;color:#003f73;padding:.9rem 2.25rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.95rem;display:inline-flex;align-items:center;gap:.5rem;border:2px solid #fff;">
                <i class="fas fa-envelope"></i> Email Us
            </a>
            <a href="#how-it-works"
               style="background:rgba(255,255,255,.12);color:#fff;padding:.9rem 2.25rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.95rem;display:inline-flex;align-items:center;gap:.5rem;border:1px solid rgba(255,255,255,.3);">
                <i class="fas fa-route"></i> How It Works
            </a>
        </div>
    </div>
</section>


<?php
$isVerifiedBidder = $currentUser && $currentUser['bidder_status'] === 'verified_bidder' && !empty($currentUser['bidder_number']);
$isPendingBidder  = $currentUser && in_array($currentUser['bidder_status'], ['pending_payment','pending_approval']);
?>


<!-- ══════════════════════════════════════════════════════════
     WHAT IS A PRE-AUCTION PURCHASE?
══════════════════════════════════════════════════════════ -->
<section style="background:#fff;padding:4rem 1.5rem;">
<div class="pa-container">
    <div class="pa-what-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:4rem;align-items:center;">
        <div>
            <span class="pa-section-label"><i class="fas fa-building-columns"></i> Understand the Opportunity</span>
            <h2 class="pa-section-title">What Exactly Is a Pre-Auction Vehicle?</h2>
            <p class="pa-section-sub" style="margin-bottom:1.25rem;">
                When a vehicle owner fails to keep up with their loan repayments, the financial institution that funded the vehicle — a bank, a credit provider, or an asset finance company — is legally entitled to repossess it.
            </p>
            <p class="pa-section-sub" style="margin-bottom:1.25rem;">
                The institution's primary goal is simple: <strong>recover the outstanding debt as quickly as possible.</strong> That outstanding balance is almost always significantly lower than what the vehicle is worth on the open market.
            </p>
            <p class="pa-section-sub" style="margin-bottom:1.75rem;">
                Auction Hub Group acts as the authorised agent for these financial institutions.
                <strong>Before we put any vehicle up for public auction, we offer it to qualified buyers at the outstanding loan balance</strong> — giving you a rare, time-limited opportunity to secure a quality vehicle at a price no dealership can match.
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:.75rem;">
                <div style="display:flex;align-items:center;gap:.5rem;background:#e6f9ee;color:#155724;padding:.5rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;">
                    <i class="fas fa-check-circle"></i> Fully Legal & Transparent
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;background:#e8f4fd;color:#003f73;padding:.5rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;">
                    <i class="fas fa-check-circle"></i> Bank-Authorised Sale
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;background:#fff3cd;color:#856404;padding:.5rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;">
                    <i class="fas fa-check-circle"></i> DEKRA Inspected
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;background:#f3e8ff;color:#6b21a8;padding:.5rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;">
                    <i class="fas fa-check-circle"></i> Clean Title Transfer
                </div>
            </div>
        </div>
        <div>
            <!-- Visual: Price comparison -->
            <div style="background:linear-gradient(135deg,#f0f6fc,#e8f4fd);border-radius:16px;padding:1.75rem;">
                <h3 style="color:#003f73;font-size:1rem;font-weight:700;margin-bottom:1.25rem;text-align:center;">
                    <i class="fas fa-scale-balanced"></i> Real-World Price Example
                </h3>
                <div style="background:#fff;border-radius:12px;padding:1.25rem;margin-bottom:1rem;text-align:center;border:1.5px solid #dce6f0;">
                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:.4rem;">Retail / Dealership Price</div>
                    <div style="font-size:1.8rem;font-weight:900;color:#aaa;text-decoration:line-through;">R380,000</div>
                    <div style="font-size:.78rem;color:#aaa;margin-top:.25rem;">What you'd normally pay</div>
                </div>
                <div style="background:#003f73;border-radius:12px;padding:1.25rem;text-align:center;border:2px solid #005BA1;">
                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.65);margin-bottom:.4rem;">Your Pre-Auction Price</div>
                    <div style="font-size:2.2rem;font-weight:900;color:#00d4ff;font-family:'Barlow Condensed',sans-serif;">R244,000</div>
                    <div style="font-size:.8rem;color:rgba(255,255,255,.7);margin-top:.25rem;">Bank's outstanding balance only</div>
                </div>
                <div style="background:#d4edda;border-radius:10px;padding:.85rem 1.25rem;margin-top:1rem;text-align:center;">
                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:#155724;font-weight:700;">You Save</div>
                    <div style="font-size:1.5rem;font-weight:900;color:#155724;font-family:'Barlow Condensed',sans-serif;">R136,000 (36%)</div>
                    <div style="font-size:.75rem;color:#1a7a3a;">Compared to retail market value</div>
                </div>
                <p style="font-size:.72rem;color:#888;text-align:center;margin-top:.75rem;line-height:1.5;">
                    * Illustrative example. Savings vary by vehicle, make, model, and outstanding balance.
                </p>
            </div>
        </div>
    </div>

    <!-- Financial institution logos strip -->
    <div class="pa-institution-strip" style="margin-top:2.5rem;">
        <div style="flex-shrink:0;">
            <div class="inst-title">Vehicles from institutions including</div>
            <div class="pa-institution-logos">
                <div class="pa-inst-badge"><i class="fas fa-university"></i> FNB</div>
                <div class="pa-inst-badge"><i class="fas fa-university"></i> ABSA</div>
                <div class="pa-inst-badge"><i class="fas fa-university"></i> Standard Bank</div>
                <div class="pa-inst-badge"><i class="fas fa-university"></i> Nedbank</div>
                <div class="pa-inst-badge"><i class="fas fa-university"></i> WesBank</div>
                <div class="pa-inst-badge"><i class="fas fa-university"></i> MFC</div>
                <div class="pa-inst-badge" style="background:#e8f4fd;color:#005BA1;">+ More</div>
            </div>
        </div>
    </div>
</div>
</section>


<!-- ══════════════════════════════════════════════════════════
     THE 7 ADVANTAGES
══════════════════════════════════════════════════════════ -->
<section class="pa-section" style="background:#f5f7fa;">
<div class="pa-container">
    <div style="text-align:center;margin-bottom:.5rem;">
        <span class="pa-section-label"><i class="fas fa-star"></i> Why Pre-Auction</span>
    </div>
    <h2 class="pa-section-title" style="text-align:center;margin:0 auto .75rem;">8 Reasons Smart Buyers Choose Pre-Auction</h2>
    <p class="pa-section-sub" style="text-align:center;margin:0 auto 0;" >Why thousands of South Africans skip the dealership and go directly to the source.</p>

    <div class="pa-benefit-grid">
        <?php
        $benefits = [
            ['fa-tag',            '#005BA1', 'Pay Only the Bank\'s Outstanding Balance',
             'You bypass retail markup, dealership profit margins, and auction premiums entirely. You pay exactly what the bank needs to recover — often <strong>30–60% below what the same vehicle sells for on AutoTrader or at a dealership</strong>.'],
            ['fa-medal',          '#f59e0b', 'DEKRA-Inspected & Certified',
             'Every pre-auction vehicle undergoes a full DEKRA professional vehicle inspection before it is offered to you. <strong>You receive the full inspection report.</strong> No hidden defects. No surprises at registration.'],
            ['fa-truck-fast',     '#25D366', 'Free Delivery — Anywhere in South Africa',
             'Can\'t travel to our premises? We\'ll deliver the vehicle to your door at <strong>no additional cost</strong>. SADC regional delivery is also available for cross-border buyers.'],
            ['fa-stopwatch',      '#8b5cf6', 'Exclusive 24-Hour Commitment Window',
             'Your window to secure the vehicle lasts <strong>24 hours from the time your invoice is issued.</strong> After that, the vehicle moves to public auction where the price — and the competition — increase significantly.'],
            ['fa-rotate-left',    '#ef4444', 'Cancel, Change Vehicles, or Get a Full Refund',
             'You have a full <strong>1 hour 30 minutes</strong> to test drive and inspect the vehicle. Bring your own mechanic. After your inspection you can complete the purchase, <strong>switch to your nominated alternative vehicle</strong>, or cancel the deal entirely for a full refund — no questions, no hassle.'],
            ['fa-percent',        '#003f73', 'Zero Buyer\'s Commission. Zero Hidden Fees.',
             'Unlike auction purchases where buyers pay a commission of 5–10% on top of the hammer price, <strong>pre-auction deals carry absolutely no buyer\'s commission.</strong> What you agree to is what you pay — nothing more.'],
            ['fa-chart-line',     '#10b981', 'High-Demand Vehicles with Resale Potential',
             'Our pre-auction inventory prioritises <strong>high-demand makes and models</strong> — vehicles the market wants. Many buyers choose these vehicles specifically for their strong auction resale value, knowing the purchase price creates immediate equity.'],
            ['fa-id-badge',       '#6b21a8', 'Vehicle Registered Presold Under Your Name — With Full Flexibility',
             'Once your commitment fee is paid, the vehicle is immediately placed <strong>Presold under your name</strong> with the financial institution. You receive a unique Buyer Code by email. After the 1.5-hour test drive you can <strong>proceed, switch to your alternative vehicle, or cancel for a full refund</strong> — the choice is entirely yours.'],
        ];
        foreach ($benefits as [$icon, $color, $title, $desc]):
        ?>
        <div class="pa-benefit-card" style="--accent:<?= $color ?>;">
            <div class="pa-benefit-icon"><i class="fas <?= $icon ?>"></i></div>
            <div>
                <h3><?= $title ?></h3>
                <p><?= $desc ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</section>


<!-- ══════════════════════════════════════════════════════════
     HOW IT WORKS
══════════════════════════════════════════════════════════ -->
<section id="how-it-works" class="pa-section" style="background:#fff;">
<div class="pa-container">
    <div style="text-align:center;">
        <span class="pa-section-label"><i class="fas fa-route"></i> Simple Process</span>
        <h2 class="pa-section-title" style="text-align:center;margin:.5rem auto .75rem;">From Enquiry to Driving Away in 5 Steps</h2>
        <p class="pa-section-sub" style="text-align:center;margin:0 auto 0;">Clear, transparent, and legally protected from start to finish.</p>
    </div>

    <div style="background:#f5f7fa;border-radius:16px;padding:2.5rem;margin-top:2.5rem;">
        <div class="pa-steps-inner" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1.5rem;position:relative;">
            <?php
            $steps = [
                ['1','fa-user-pen','#005BA1','Submit Your Details',
                 'Provide your full name, ID or passport number, contact details, and physical address. Our team issues a personalised proforma invoice within the hour. You may also nominate an <strong>alternative vehicle</strong> at this stage.'],
                ['2','fa-file-invoice-dollar','#8b5cf6','Review & Sign Your Invoice',
                 'You receive a legally binding pre-auction invoice outlining the total price, commitment fee, outstanding balance, your chosen vehicle, and your nominated alternative. Valid for <strong>24 hours</strong>.'],
                ['3','fa-coins','#f59e0b','Pay the Commitment Fee — Vehicle Goes Presold Under Your Name',
                 'Pay the commitment fee (typically <strong>30–50% of the asking price</strong>). The vehicle is immediately marked <strong>Presold</strong> and registered under your name with the financial institution. No other buyer can access it. Upload your proof of payment straight away.'],
                ['4','fa-id-badge','#25D366','Receive Your Unique Buyer Code',
                 'Once payment is verified with the financial institution, you receive your unique <strong>Buyer Code</strong> by email. This code is your exclusive access pass to the vehicle — <strong>no Buyer Code, no entry</strong>. No walk-ins and no prior appointment without it.'],
                ['5','fa-car-side','#ef4444','Test Drive &amp; Inspect — Then Decide',
                 'Arrive at our premises with your Buyer Code. You have a full <strong>1 hour 30 minutes</strong> to test drive and inspect the vehicle — bring your own mechanic. You can <strong>proceed with the purchase, cancel the deal for a full refund, or switch to your nominated alternative vehicle</strong>.'],
            ];
            foreach ($steps as [$num, $icon, $color, $title, $desc]):
            ?>
            <div style="text-align:center;padding:1.25rem;position:relative;">
                <div style="width:60px;height:60px;background:<?= $color ?>;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;box-shadow:0 4px 16px <?= $color ?>44;position:relative;">
                    <i class="fas <?= $icon ?>" style="color:#fff;font-size:1.2rem;"></i>
                    <span style="position:absolute;top:-5px;right:-5px;background:#003f73;color:#fff;font-size:.72rem;font-weight:900;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #fff;">
                        <?= $num ?>
                    </span>
                </div>
                <h4 style="color:#003f73;font-size:.9rem;font-weight:700;margin-bottom:.5rem;"><?= $title ?></h4>
                <p style="color:#556;font-size:.8rem;line-height:1.7;"><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Important notice -->
    <div style="background:linear-gradient(135deg,#fff8e1,#fff3cd);border:2px solid #f59e0b;border-radius:12px;padding:1.25rem 1.75rem;margin-top:1.5rem;display:flex;gap:1rem;align-items:flex-start;">
        <i class="fas fa-triangle-exclamation" style="color:#f59e0b;font-size:1.3rem;flex-shrink:0;margin-top:.1rem;"></i>
        <div>
            <strong style="color:#7a4f00;font-size:.92rem;">Entry Requirements — Please Read Carefully</strong>
            <p style="color:#856404;font-size:.85rem;line-height:1.7;margin:.35rem 0 0;">
                <strong>No Buyer's Code = No Entry</strong> &nbsp;&bull;&nbsp;
                <strong>No Prior Appointment = No Entry</strong> &nbsp;&bull;&nbsp;
                <strong>No Walk-Ins Permitted</strong><br>
                Your Buyer Code is issued by email once your commitment fee has been received and verified with the financial institution. At that point the vehicle is already marked Presold under your name. The Buyer Code is unique to you and valid for this transaction only — it is your access pass for the 1 hour 30 minute test drive window. Please do not share or misplace it.
            </p>
        </div>
    </div>

    <!-- ── BUYERS CODE: digital access pass ───────────────── -->
    <?php if ($isVerifiedBidder): ?>
    <div style="margin-top:2rem;display:flex;justify-content:center;">
      <div style="width:100%;max-width:460px;">

        <!-- Card -->
        <div id="buyersCodeCard" style="background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 16px 48px rgba(0,40,100,.18);border:1px solid #d0dce8;">

          <!-- Blue header band -->
          <div style="background:linear-gradient(135deg,#003f73,#005BA1);padding:1.2rem 1.5rem 1rem;display:flex;align-items:center;gap:.9rem;">
            <div style="width:46px;height:46px;background:rgba(255,255,255,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:2px solid rgba(255,255,255,.25);">
              <i class="fas fa-gavel" style="color:#fff;font-size:1.2rem;"></i>
            </div>
            <div>
              <div style="color:rgba(255,255,255,.65);font-size:.63rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;">Auction Hub Group</div>
              <div style="color:#fff;font-size:.95rem;font-weight:800;">Pre-Auction Access Pass</div>
            </div>
            <div style="margin-left:auto;background:#22c55e;color:#fff;font-size:.6rem;font-weight:900;letter-spacing:.1em;text-transform:uppercase;padding:.25rem .65rem;border-radius:50px;white-space:nowrap;">
              <i class="fas fa-circle-check"></i> Active
            </div>
          </div>

          <!-- Body -->
          <div style="padding:1.25rem 1.5rem 0;">
            <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.1em;color:#888;font-weight:700;margin-bottom:.15rem;">Registered Buyer</div>
            <div style="font-size:1rem;font-weight:800;color:#003f73;margin-bottom:1.1rem;"><?= e($currentUser['full_name']) ?></div>

            <!-- Code block -->
            <div style="background:#f4f8ff;border:2px dashed #c5d8f0;border-radius:12px;padding:1.1rem 1.25rem;text-align:center;margin-bottom:1.1rem;">
              <div style="font-size:.63rem;text-transform:uppercase;letter-spacing:.16em;color:#667;font-weight:700;margin-bottom:.3rem;">Buyers Code</div>
              <div style="font-size:2.4rem;font-weight:900;color:#002a5c;letter-spacing:.12em;font-family:'Barlow Condensed',sans-serif;line-height:1;"><?= e($currentUser['bidder_number']) ?></div>
              <div style="display:inline-flex;align-items:center;gap:.35rem;margin-top:.55rem;">
                <span style="background:#fee2e2;color:#b91c1c;font-size:.6rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;padding:.2rem .6rem;border-radius:50px;">PREAUCTION</span>
                <span style="background:#dcfce7;color:#15803d;font-size:.6rem;font-weight:900;letter-spacing:.1em;text-transform:uppercase;padding:.2rem .6rem;border-radius:50px;border:1.5px solid #86efac;"><i class="fas fa-check"></i> APPROVED</span>
              </div>
            </div>

            <!-- Instruction -->
            <div style="display:flex;align-items:flex-start;gap:.6rem;background:#fffbea;border:1px solid #fde68a;border-radius:9px;padding:.7rem .9rem;margin-bottom:1.1rem;">
              <i class="fas fa-circle-info" style="color:#d97706;font-size:.9rem;margin-top:.1rem;flex-shrink:0;"></i>
              <p style="margin:0;font-size:.75rem;color:#78350f;line-height:1.55;font-weight:600;">Show this screen at our reception to access test drives and vehicle collection. Appointment required.</p>
            </div>
          </div>

          <!-- Decorative barcode -->
          <div style="padding:0 1.5rem 1.25rem;">
            <div style="display:flex;align-items:center;gap:.25rem;overflow:hidden;opacity:.15;">
              <?php
              $seed = crc32($currentUser['bidder_number']);
              srand($seed);
              for ($i = 0; $i < 70; $i++) {
                  $w = rand(1,4); $h = rand(20,32);
                  echo "<div style='width:{$w}px;height:{$h}px;background:#002a5c;border-radius:1px;flex-shrink:0;'></div>";
              }
              srand();
              ?>
            </div>
          </div>

          <!-- Footer -->
          <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:.7rem 1.5rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="font-size:.6rem;color:#94a3b8;font-weight:600;">auctionhubgroup.co.za</div>
            <div style="font-size:.6rem;color:#94a3b8;font-weight:600;">Valid for pre-auction access only</div>
          </div>
        </div>

        <p style="text-align:center;color:#667;font-size:.72rem;margin-top:.75rem;">
          <i class="fas fa-mobile-screen-button" style="margin-right:.3rem;color:#005BA1;"></i>
          Screenshot or show this screen at reception — no printout needed.
        </p>
      </div>
    </div>

    <?php elseif ($isPendingBidder): ?>
    <div style="margin-top:1.5rem;background:#fff8e1;border:1.5px solid #fde68a;border-radius:12px;padding:1rem 1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
      <i class="fas fa-hourglass-half" style="color:#f59e0b;font-size:1.2rem;flex-shrink:0;"></i>
      <div style="flex:1;min-width:180px;">
        <div style="font-weight:700;color:#7a4f00;font-size:.88rem;">Your Buyers Code is being processed</div>
        <div style="color:#856404;font-size:.78rem;margin-top:.2rem;">Once our team approves your registration, your unique Buyers Code will appear here.</div>
      </div>
      <a href="<?= APP_URL ?>/profile.php" style="background:#f59e0b;color:#fff;font-weight:800;font-size:.8rem;padding:.55rem 1.1rem;border-radius:8px;text-decoration:none;white-space:nowrap;flex-shrink:0;">
        <i class="fas fa-user-clock"></i> Check Status
      </a>
    </div>

    <?php else: ?>
    <div style="margin-top:1.5rem;background:#eef4fb;border:1.5px solid #c5d8f0;border-radius:12px;padding:1rem 1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
      <i class="fas fa-id-badge" style="color:#005BA1;font-size:1.2rem;flex-shrink:0;"></i>
      <div style="flex:1;min-width:180px;">
        <div style="font-weight:700;color:#003f73;font-size:.88rem;">Get Your Buyers Code</div>
        <div style="color:#4a6080;font-size:.78rem;margin-top:.2rem;">Register as a buyer to receive your unique Buyers Code — your digital access pass for test drives and vehicle collection.</div>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;flex-shrink:0;">
        <a href="<?= APP_URL ?>/login.php?tab=register" style="background:#005BA1;color:#fff;font-weight:800;font-size:.8rem;padding:.55rem 1.1rem;border-radius:8px;text-decoration:none;white-space:nowrap;">
          <i class="fas fa-user-plus"></i> Register
        </a>
        <a href="<?= APP_URL ?>/login.php" style="background:#fff;color:#005BA1;font-weight:700;font-size:.8rem;padding:.55rem 1.1rem;border-radius:8px;text-decoration:none;white-space:nowrap;border:1.5px solid #c5d8f0;">
          <i class="fas fa-sign-in-alt"></i> Login
        </a>
      </div>
    </div>
    <?php endif; ?>

</div>
</section>


<!-- ══════════════════════════════════════════════════════════
     COMMITMENT FEE EXPLAINED
══════════════════════════════════════════════════════════ -->
<section class="pa-section" style="background:#f5f7fa;">
<div class="pa-container">
    <div class="pa-fee-grid" style="display:grid;grid-template-columns:1.1fr 1fr;gap:3.5rem;align-items:center;">
        <div>
            <span class="pa-section-label"><i class="fas fa-coins"></i> Commitment Fee</span>
            <h2 class="pa-section-title">What Is the Commitment Fee — and Why Does It Exist?</h2>
            <p class="pa-section-sub" style="margin-bottom:1.25rem;">
                The commitment fee is a <strong>reservation deposit</strong> — typically 30–50% of the total vehicle price — that you pay to formally commit to the pre-auction purchase.
            </p>
            <p class="pa-section-sub" style="margin-bottom:1.25rem;">
                Once your commitment fee is received, <strong>the vehicle is immediately marked as Presold and registered under your name with the financial institution.</strong>
                No other buyer can bid on or access the vehicle from that moment. Your unique <strong>Buyer Code</strong> is then issued by email — this is your exclusive access pass to come and view, test drive, and inspect the vehicle.
            </p>
            <p class="pa-section-sub" style="margin-bottom:1.25rem;">
                You also have the option to nominate an <strong>alternative vehicle</strong> when paying your commitment fee. If after your test drive you decide the primary vehicle isn't right for you, you can switch to your alternative — or simply cancel and receive a full refund.
            </p>
            <p class="pa-section-sub" style="margin-bottom:1.75rem;">
                The 24-hour commitment window is set by the financial institution. Once expired, the vehicle is released back to the auction process.
            </p>

            <div style="display:grid;gap:.75rem;">
                <div style="display:flex;gap:1rem;align-items:flex-start;">
                    <div style="width:36px;height:36px;background:#e8f4fd;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-lock" style="color:#005BA1;font-size:.85rem;"></i>
                    </div>
                    <div>
                        <strong style="color:#003f73;font-size:.88rem;">Vehicle goes Presold under your name immediately</strong>
                        <p style="color:#667;font-size:.82rem;margin:.2rem 0 0;line-height:1.6;">The moment your commitment fee is confirmed, the vehicle is registered to you with the financial institution. No other buyer can access or bid on it.</p>
                    </div>
                </div>
                <div style="display:flex;gap:1rem;align-items:flex-start;">
                    <div style="width:36px;height:36px;background:#f3e8ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-id-badge" style="color:#6b21a8;font-size:.85rem;"></i>
                    </div>
                    <div>
                        <strong style="color:#003f73;font-size:.88rem;">Buyer Code issued — your exclusive access pass</strong>
                        <p style="color:#667;font-size:.82rem;margin:.2rem 0 0;line-height:1.6;">Once payment is verified, you receive a unique Buyer Code by email. Present it on arrival for your 1 hour 30 minute test drive and inspection window.</p>
                    </div>
                </div>
                <div style="display:flex;gap:1rem;align-items:flex-start;">
                    <div style="width:36px;height:36px;background:#e6f9ee;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-rotate-left" style="color:#155724;font-size:.85rem;"></i>
                    </div>
                    <div>
                        <strong style="color:#003f73;font-size:.88rem;">Cancel, change vehicles, or proceed — your choice</strong>
                        <p style="color:#667;font-size:.82rem;margin:.2rem 0 0;line-height:1.6;">After your test drive you can complete the purchase, switch to your nominated alternative vehicle, or cancel for a full refund. Only R102 in admin &amp; bank charges is retained.</p>
                    </div>
                </div>
                <div style="display:flex;gap:1rem;align-items:flex-start;">
                    <div style="width:36px;height:36px;background:#fff3cd;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-receipt" style="color:#856404;font-size:.85rem;"></i>
                    </div>
                    <div>
                        <strong style="color:#003f73;font-size:.88rem;">Counts toward your final purchase price</strong>
                        <p style="color:#667;font-size:.82rem;margin:.2rem 0 0;line-height:1.6;">It is not an extra charge — it forms part of your total payment. The outstanding balance is simply total price minus commitment fee.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fee calculator example -->
        <div>
            <div class="pa-fee-box">
                <h3 style="color:#003f73;font-size:1rem;font-weight:700;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;">
                    <i class="fas fa-calculator" style="color:#005BA1;"></i>
                    Live Example Breakdown
                </h3>
                <div class="pa-fee-example">
                    <div class="pa-fee-row">
                        <span class="lbl">Vehicle</span>
                        <span class="val" style="font-size:.82rem;text-align:right;max-width:180px;">2022 Land Rover Range Rover Velar D200</span>
                    </div>
                    <div class="pa-fee-row">
                        <span class="lbl">Total Asking Price</span>
                        <span class="val">R244,000</span>
                    </div>
                    <div class="pa-fee-row">
                        <span class="lbl">Estimated Retail Value</span>
                        <span class="val" style="color:#aaa;text-decoration:line-through;">~R380,000</span>
                    </div>
                    <div class="pa-fee-row fee-line">
                        <span class="lbl">Commitment Fee (35%)</span>
                        <span class="val">R85,400</span>
                    </div>
                    <div class="pa-fee-row outstanding-line">
                        <span class="lbl" style="font-weight:700;">Outstanding Balance</span>
                        <span class="val">R158,600</span>
                    </div>
                    <div class="pa-fee-row save-line">
                        <span class="lbl" style="font-weight:700;color:#155724;"><i class="fas fa-piggy-bank"></i> Your Saving vs. Retail</span>
                        <span class="val" style="color:#155724;">~R136,000</span>
                    </div>
                </div>
                <div style="margin-top:1.25rem;padding:1rem;background:#fff;border-radius:10px;border:1.5px solid #d0dbe8;">
                    <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:#888;font-weight:700;margin-bottom:.4rem;">Commitment Fee Range</div>
                    <div style="font-size:1.1rem;font-weight:800;color:#003f73;margin-bottom:.2rem;">30% to 50%</div>
                    <div style="font-size:.8rem;color:#667;line-height:1.5;">
                        Set by Auction Hub Group based on the financial institution's requirements for the specific vehicle. Displayed clearly on your invoice before you commit.
                    </div>
                </div>
                <div style="margin-top:.85rem;padding:.9rem 1rem;background:#f3e8ff;border-radius:10px;border:1.5px solid #d8b4fe;display:flex;gap:.75rem;align-items:flex-start;">
                    <i class="fas fa-car-on" style="color:#6b21a8;font-size:1rem;flex-shrink:0;margin-top:.1rem;"></i>
                    <div>
                        <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:#6b21a8;font-weight:700;margin-bottom:.3rem;">Alternative Vehicle Option</div>
                        <div style="font-size:.8rem;color:#4a1d6e;line-height:1.55;">
                            When paying your commitment fee you can nominate an alternative vehicle. If after your 1h30m test drive you change your mind, switch to your alternative — or cancel for a full refund. No pressure.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</section>


<!-- ══════════════════════════════════════════════════════════
     FACILITY GALLERY — SERVICE AREA & COLLECTION ZONE
══════════════════════════════════════════════════════════ -->
<section style="background:#fff;padding:0;overflow:hidden;">

  <!-- Info bar -->
  <div style="background:#001F5C;padding:1.1rem 2rem;text-align:center;border-top:3px solid #ffd54f;">
    <p style="margin:0;line-height:1.6;">
      <i class="fas fa-map-marker-alt" style="color:#ffd54f;margin-right:.4rem;"></i>
      <span style="color:#ffd54f;font-size:1rem;font-weight:900;letter-spacing:.04em;">OUR PREMISES</span>
      <span style="color:#fff;font-weight:600;margin:0 .4rem;">&mdash;</span>
      <span style="color:#fff;font-weight:700;">Available for</span>
      <span style="color:#ffd54f;font-weight:900;letter-spacing:.02em;"> PRE-AUCTION TEST DRIVES &amp; VEHICLE COLLECTION ONLY</span>
      <span style="color:rgba(255,255,255,.6);font-size:.88rem;margin-left:.5rem;">(by appointment · registered buyers only)</span>
    </p>
  </div>

  <!-- Two-image grid -->
  <div style="display:grid;grid-template-columns:1fr 1fr;" class="pa-fac-grid">
    <div style="overflow:hidden;height:420px;">
      <img src="<?= APP_URL ?>/assets/images/gallery1.jpeg"
           alt="Pre-Auction Service Area — Auction Hub Group"
           style="width:100%;height:100%;object-fit:cover;object-position:center;display:block;transition:transform .4s ease;"
           loading="lazy"
           onmouseover="this.style.transform='scale(1.03)'"
           onmouseout="this.style.transform='scale(1)'"
           onerror="this.closest('div').style.background='#002347';this.style.display='none'">
    </div>
    <div style="overflow:hidden;height:420px;position:relative;">
      <img src="<?= APP_URL ?>/assets/images/gallery2.jpeg"
           alt="Vehicle Collection Area — Auction Hub Group"
           style="width:100%;height:100%;object-fit:cover;object-position:center;display:block;transition:transform .4s ease;"
           loading="lazy"
           onmouseover="this.style.transform='scale(1.03)'"
           onmouseout="this.style.transform='scale(1)'"
           onerror="this.closest('div').style.background='#002347';this.style.display='none'">
      <!-- Caption overlay -->
      <div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(to top,rgba(0,10,30,.85),transparent);padding:1.5rem 1.75rem;">
        <div style="color:#fff;font-size:.8rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.2rem;"><i class="fas fa-car-side" style="color:#ffd54f;margin-right:.35rem;"></i>Self-Service Collection Bay</div>
        <div style="color:rgba(255,255,255,.75);font-size:.78rem;line-height:1.5;">Pick up your vehicle at your convenience after payment is confirmed. Our team ensures a smooth, efficient handover every time.</div>
      </div>
    </div>
  </div>

</section>
<style>
@media (max-width:640px){
  .pa-fac-grid { grid-template-columns:1fr !important; }
  .pa-fac-grid > div { height:260px !important; }
}
</style>


<!-- ══════════════════════════════════════════════════════════
     RESERVED VEHICLES — PRE-AUCTION
══════════════════════════════════════════════════════════ -->
<section class="pa-section" style="background:#f5f7fa;padding:4.5rem 1.5rem;">
<div class="pa-container">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:3.5rem;align-items:center;">

        <!-- Image -->
        <div style="position:relative;border-radius:20px;overflow:hidden;box-shadow:0 16px 56px rgba(0,40,80,.2);">
            <img src="<?= APP_URL ?>/assets/images/preauction-reserved.jpg" alt="Pre-Auction Reserved Vehicle Bay"
                 style="width:100%;height:440px;object-fit:cover;display:block;"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
            <div style="display:none;width:100%;height:440px;background:#002347;align-items:center;justify-content:center;">
                <i class="fas fa-car-side" style="font-size:4rem;color:#336699;"></i>
            </div>
            <!-- Badge -->
            <div style="position:absolute;top:1.25rem;left:1.25rem;background:linear-gradient(135deg,#c8860a,#e09a20);color:#fff;font-size:.73rem;font-weight:800;letter-spacing:.12em;padding:.5rem 1.1rem;border-radius:24px;text-transform:uppercase;box-shadow:0 4px 16px rgba(200,134,10,.5);">
                <i class="fas fa-lock"></i>&nbsp; Reserved Vehicles
            </div>
            <!-- Light sheen -->
            <div style="position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,.07) 0%,transparent 60%);pointer-events:none;"></div>
        </div>

        <!-- Copy -->
        <div>
            <span class="pa-section-label"><i class="fas fa-lock"></i> Pre-Auction Exclusive</span>
            <h2 class="pa-section-title">The Reserved Bay — <span style="color:#c8860a;">Yours Before the Hammer Falls</span></h2>
            <p class="pa-section-sub" style="margin-bottom:1.2rem;">
                Walk into the Reserved Bay and you'll understand why smart buyers choose pre-auction over auction day. These vehicles — <strong>many of them premium, luxury, and late-model bank liquidations</strong> — are ring-fenced entirely from the open floor.
            </p>
            <p class="pa-section-sub" style="margin-bottom:1.6rem;">
                Once your commitment fee is confirmed, your chosen vehicle moves into the Reserved Bay registered under your name. No competing bids. No floor drama. No last-minute upset. Just you, the vehicle, and a private 90-minute window to confirm you're making the right call.
            </p>

            <div style="display:flex;flex-direction:column;gap:.85rem;margin-bottom:2rem;">
                <?php foreach ([
                    ['fa-shield-halved','#005BA1','Ring-Fenced From Auction','Your vehicle cannot be bid on or purchased by anyone else the moment it is reserved.'],
                    ['fa-crown','#c8860a','Premium &amp; Luxury Stock Included','Bank liquidations regularly include high-end vehicles that never reach the open auction floor.'],
                    ['fa-rotate-left','#16a34a','Full Refund If You Walk Away','Not satisfied after the test drive? Cancel before your 90-minute window closes — full refund, zero questions.'],
                ] as [$icon, $color, $title, $desc]): ?>
                <div style="display:flex;gap:.9rem;align-items:flex-start;">
                    <div style="width:38px;height:38px;background:<?= $color ?>18;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:.1rem;">
                        <i class="fas <?= $icon ?>" style="color:<?= $color ?>;font-size:.92rem;"></i>
                    </div>
                    <div>
                        <div style="font-weight:700;color:#1a2a3a;font-size:.9rem;margin-bottom:.15rem;"><?= $title ?></div>
                        <div style="color:#556677;font-size:.81rem;line-height:1.65;"><?= $desc ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <a href="#vehicles" style="display:inline-flex;align-items:center;gap:.6rem;background:linear-gradient(135deg,#005BA1,#003f73);color:#fff;font-weight:800;font-size:.9rem;padding:.9rem 1.85rem;border-radius:10px;text-decoration:none;letter-spacing:.03em;box-shadow:0 4px 18px rgba(0,91,161,.32);">
                <i class="fas fa-car"></i>&nbsp; Browse Available Vehicles
            </a>
        </div>

    </div>
</div>
</section>


<!-- ══════════════════════════════════════════════════════════
     VEHICLE LISTINGS
══════════════════════════════════════════════════════════ -->
<section id="vehicles" class="pa-section" style="background:#fff;">
<div class="pa-container">

    <!-- Section header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;">
        <div>
            <span class="pa-section-label"><i class="fas fa-car"></i> Available Now</span>
            <h2 class="pa-section-title" style="margin:.5rem 0 0;">
                Pre-Auction Vehicles
                <span id="paVehicleCount" style="font-size:1rem;color:#888;font-weight:400;font-family:'Barlow',sans-serif;">(<?= $totalDeals ?> available)</span>
            </h2>
        </div>
        <a href="<?= APP_URL ?>/auctions.php" style="color:#005BA1;font-size:.88rem;text-decoration:none;font-weight:700;display:flex;align-items:center;gap:.4rem;">
            View All Auctions <i class="fas fa-arrow-right"></i>
        </a>
    </div>

    <!-- ── Branch Filter Tabs ─────────────────────────────────── -->
    <div id="branchTabs" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.75rem;padding-bottom:1.25rem;border-bottom:2px solid #e8eef5;">
        <button class="pa-branch-tab active" data-branch="all"
                onclick="filterByBranch('all',this)">
            <i class="fas fa-globe"></i> All Branches
            <span class="pa-tab-count"><?= $totalDeals ?></span>
        </button>
        <?php foreach ($allBranches as $br):
            $cnt = $branchCounts[$br['id']] ?? 0;
            if ($cnt === 0) continue; // hide branches with no stock
        ?>
        <button class="pa-branch-tab" data-branch="<?= $br['id'] ?>"
                onclick="filterByBranch('<?= $br['id'] ?>',this)">
            <i class="fas fa-<?= $br['id'] == 1 ? 'building' : 'warehouse' ?>"></i>
            <?= e($br['name']) ?>
            <span class="pa-tab-count"><?= $cnt ?></span>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- No-results message (hidden by default) -->
    <div id="paNoBranch" style="display:none;text-align:center;padding:3rem 1rem;">
        <i class="fas fa-warehouse" style="font-size:3rem;color:#c5d0da;margin-bottom:1rem;display:block;"></i>
        <p style="color:#778899;font-size:1rem;margin:0;">No vehicles currently available at this branch.<br>
        <a href="#" onclick="filterByBranch('all',document.querySelector('.pa-branch-tab'));return false;" style="color:#005BA1;font-weight:700;">View all branches</a></p>
    </div>

    <?php if ($totalDeals > 0): ?>
    <div class="pa-vehicle-grid" id="paVehicleGrid">
        <?php foreach ($preAuctionVehicles as $v):
            $price       = (float)($v['current_price'] ?: $v['start_price']);
            $vehicleName = trim($v['year'] . ' ' . ($v['make_name'] ?? '') . ' ' . $v['item_name']);
            $timeLeft    = strtotime($v['end_time']) - time();
            $isLive      = $v['status'] === 'active';
            $branchId    = $v['branch_id'] ?? 0;
        ?>
        <div class="pa-card" data-branch="<?= $branchId ?>">
            <!-- Image -->
            <div class="pa-card-img">
                <?php if (!empty($v['image_url'])): ?>
                    <img src="<?= e(cldImg($v['image_url'], CLD_CARD)) ?>" alt="<?= e($vehicleName) ?>" loading="lazy">
                <?php else: ?>
                    <div style="display:flex;align-items:center;justify-content:center;height:100%;">
                        <i class="fas fa-car" style="font-size:3rem;color:#b0bcc8;"></i>
                    </div>
                <?php endif; ?>

                <!-- Pre-auction badge -->
                <span style="position:absolute;top:.75rem;left:.75rem;background:linear-gradient(135deg,#25D366,#1a9e50);color:#fff;font-size:.68rem;font-weight:800;padding:.3rem .85rem;border-radius:20px;text-transform:uppercase;letter-spacing:.05em;box-shadow:0 2px 8px rgba(0,0,0,.2);">
                    <i class="fas fa-bolt"></i> Pre-Auction Deal
                </span>

                <?php if ($isLive && $v['bid_count'] > 0): ?>
                <span style="position:absolute;top:.75rem;right:.75rem;background:rgba(163,0,0,.9);color:#fff;font-size:.68rem;font-weight:700;padding:.3rem .65rem;border-radius:10px;">
                    <i class="fas fa-fire"></i> <?= $v['bid_count'] ?> bid<?= $v['bid_count']>1?'s':'' ?>
                </span>
                <?php endif; ?>

                <?php if ($v['condition_grade']): ?>
                <?php $stk = dekraSticker($v['condition_grade']); ?>
                <span style="position:absolute;bottom:.75rem;left:.75rem;background:<?= $stk['color'] ?>;color:#fff;font-size:.68rem;font-weight:700;padding:.25rem .65rem;border-radius:8px;">
                    <i class="fas <?= $stk['icon'] ?>"></i> DEKRA <?= e($stk['name']) ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Details -->
            <div class="pa-card-body">
                <h3 style="color:#003f73;font-size:1rem;font-weight:700;margin-bottom:.3rem;line-height:1.3;"><?= e($vehicleName) ?></h3>

                <?php if (!empty($v['branch_name'])): ?>
                <div style="display:inline-flex;align-items:center;gap:.35rem;background:#eef4fb;border:1px solid #c8dbee;border-radius:6px;padding:.2rem .6rem;font-size:.7rem;font-weight:700;color:#003f73;margin-bottom:.55rem;text-transform:uppercase;letter-spacing:.05em;">
                    <i class="fas fa-<?= $v['branch_id'] == 1 ? 'building' : 'warehouse' ?>" style="color:#005BA1;"></i>
                    <?= e($v['branch_name']) ?> &mdash; <?= e($v['branch_city']) ?>
                </div>
                <?php endif; ?>

                <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.75rem;font-size:.75rem;color:#667;">
                    <?php if ($v['mileage']): ?><span><i class="fas fa-gauge-high" style="color:#005BA1;"></i> <?= number_format($v['mileage']) ?> km</span><?php endif; ?>
                    <?php if ($v['transmission']): ?><span><i class="fas fa-gears" style="color:#005BA1;"></i> <?= e(ucfirst($v['transmission'])) ?></span><?php endif; ?>
                    <?php if ($v['fuel_type']): ?><span><i class="fas fa-gas-pump" style="color:#005BA1;"></i> <?= e(ucfirst($v['fuel_type'])) ?></span><?php endif; ?>
                    <?php if ($v['body_type']): ?><span><i class="fas fa-car-side" style="color:#005BA1;"></i> <?= e($v['body_type']) ?></span><?php endif; ?>
                </div>

                <!-- Trust pills -->
                <div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.85rem;">
                    <span style="background:#e6f9ee;color:#155724;font-size:.65rem;font-weight:600;padding:.2rem .55rem;border-radius:4px;"><i class="fas fa-shield-halved"></i> DEKRA Inspected</span>
                    <span style="background:#e8f4fd;color:#003f73;font-size:.65rem;font-weight:600;padding:.2rem .55rem;border-radius:4px;"><i class="fas fa-truck"></i> Free Delivery</span>
                    <span style="background:#f3e8ff;color:#6b21a8;font-size:.65rem;font-weight:600;padding:.2rem .55rem;border-radius:4px;"><i class="fas fa-id-badge"></i> Presold Under Your Name</span>
                    <span style="background:#fff8e1;color:#7a4f00;font-size:.65rem;font-weight:600;padding:.2rem .55rem;border-radius:4px;"><i class="fas fa-rotate-left"></i> Cancel or Change Vehicle</span>
                    <span style="background:#fce4e4;color:#7a0000;font-size:.65rem;font-weight:600;padding:.2rem .55rem;border-radius:4px;"><i class="fas fa-percent"></i> 0% Commission</span>
                </div>

                <!-- Price box -->
                <div style="background:linear-gradient(135deg,#f0f6fc,#e8f4fd);border-radius:10px;padding:.9rem 1rem;margin-bottom:1rem;">
                    <div style="font-size:.72rem;color:#667;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.3rem;font-weight:600;">Bank's Outstanding Balance</div>
                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:.5rem;">
                        <div style="color:#003f73;font-size:1.45rem;font-weight:900;font-family:'Barlow Condensed',sans-serif;"><?= formatRand($price) ?></div>
                        <?php if ($isLive): ?>
                        <span style="font-size:.72rem;color:#155724;font-weight:700;background:#d4edda;padding:.25rem .6rem;border-radius:4px;flex-shrink:0;"><i class="fas fa-circle" style="font-size:.4rem;vertical-align:middle;"></i> LIVE</span>
                        <?php else: ?>
                        <span style="font-size:.72rem;color:#856404;font-weight:700;background:#fff3cd;padding:.25rem .6rem;border-radius:4px;flex-shrink:0;">UPCOMING</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isLive && $timeLeft > 0): ?>
                    <div style="font-size:.72rem;color:#a30000;margin-top:.3rem;font-weight:600;">
                        <i class="fas fa-clock"></i>
                        Auction closes in <?= floor($timeLeft / 86400) ?>d <?= floor(($timeLeft % 86400) / 3600) ?>h —
                        <strong>secure yours now</strong>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="pa-card-btns" style="margin-top:auto;display:grid;grid-template-columns:1fr 1fr;gap:.5rem;">
                    <a href="<?= APP_URL ?>/auction.php?id=<?= $v['id'] ?>"
                       style="background:#005BA1;color:#fff;text-align:center;padding:.65rem;border-radius:8px;text-decoration:none;font-weight:700;font-size:.82rem;display:flex;align-items:center;justify-content:center;gap:.4rem;">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                    <a href="mailto:<?= COMPANY_EMAIL ?>?subject=Pre-Auction%20Enquiry%3A%20<?= urlencode($vehicleName) ?>"
                       style="background:#005BA1;color:#fff;text-align:center;padding:.65rem;border-radius:8px;text-decoration:none;font-weight:700;font-size:.82rem;display:flex;align-items:center;justify-content:center;gap:.4rem;">
                        <i class="fas fa-envelope"></i> Enquire
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div style="background:#f5f7fa;border-radius:16px;padding:4rem 2rem;text-align:center;margin-top:1.5rem;">
        <i class="fas fa-car-side" style="font-size:3rem;color:#c5d4e8;margin-bottom:1.5rem;display:block;"></i>
        <h3 style="color:#003f73;font-size:1.25rem;margin-bottom:.75rem;">No Pre-Auction Deals Available Right Now</h3>
        <p style="color:#667;max-width:460px;margin:0 auto 2rem;line-height:1.75;">
            New bank-stock vehicles are added regularly. Register and be the <strong>first to know</strong> when the next batch arrives.
        </p>
        <div style="display:flex;justify-content:center;gap:.75rem;flex-wrap:wrap;">
            <a href="mailto:<?= COMPANY_EMAIL ?>?subject=Pre-Auction%20Notification%20Request"
               style="background:#005BA1;color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="fas fa-bell"></i> Notify Me When Available
            </a>
            <a href="<?= APP_URL ?>/auctions.php"
               style="background:#005BA1;color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="fas fa-gavel"></i> Browse All Auctions
            </a>
        </div>
    </div>
    <?php endif; ?>

</div>
</section>

<script>
// ── Branch filter ─────────────────────────────────────────────
function filterByBranch(branchId, btn) {
    // Update active tab
    document.querySelectorAll('.pa-branch-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const cards  = document.querySelectorAll('#paVehicleGrid .pa-card');
    const noMsg  = document.getElementById('paNoBranch');
    const grid   = document.getElementById('paVehicleGrid');
    const countEl = document.getElementById('paVehicleCount');
    let visible  = 0;

    cards.forEach(card => {
        const match = branchId === 'all' || card.dataset.branch == branchId;
        card.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    const label = visible === 1 ? '1 vehicle' : visible + ' available';
    if (countEl) countEl.textContent = '(' + label + ')';

    if (grid)  grid.style.display  = visible > 0 ? '' : 'none';
    if (noMsg) noMsg.style.display = visible === 0 ? 'block' : 'none';
}
</script>


<!-- ══════════════════════════════════════════════════════════
     DEKRA INSPECTION EXPLAINED
══════════════════════════════════════════════════════════ -->
<section class="pa-section" style="background:#f5f7fa;">
<div class="pa-container">
    <div class="pa-dekra-grid" style="display:grid;grid-template-columns:1fr 1.2fr;gap:3.5rem;align-items:center;">
        <div>
            <span class="pa-section-label"><i class="fas fa-clipboard-check"></i> Vehicle Quality</span>
            <h2 class="pa-section-title">Every Vehicle Passes a DEKRA Professional Inspection</h2>
            <p class="pa-section-sub" style="margin-bottom:1.25rem;">
                DEKRA is one of South Africa's most respected independent vehicle inspection bodies. Their certified technicians conduct a comprehensive mechanical, structural, and electrical assessment of every vehicle in our pre-auction inventory.
            </p>
            <p class="pa-section-sub" style="margin-bottom:1.75rem;">
                You receive the full inspection report before you commit. <strong>No surprises. No hidden defects. What you see is what you get</strong> — and what the roadworthy certificate will confirm.
            </p>
            <div style="display:flex;align-items:center;gap:.75rem;background:#fff;border:1.5px solid #e0e8f0;border-radius:10px;padding:1rem 1.25rem;">
                <i class="fas fa-user-doctor" style="color:#005BA1;font-size:1.5rem;flex-shrink:0;"></i>
                <div>
                    <div style="font-weight:700;color:#003f73;font-size:.9rem;">Bring Your Own Mechanic</div>
                    <div style="font-size:.8rem;color:#667;line-height:1.5;">During the 1.5-hour test drive window, you are welcome and encouraged to bring your own independent mechanic or inspector. We have nothing to hide.</div>
                </div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr;gap:1rem;">
            <?php
            $dekraGrades = [
                ['Platinum Sticker','fa-crown','#1565c0','#dbeafe','Already passed a formal roadworthy inspection. The vehicle is in exceptional condition and is immediately road-legal. Ready to register and drive away the same day.','Excellent Condition'],
                ['Gold Sticker','fa-award','#f59e0b','#fff8e1','Roadworthy and in very good condition. Will pass a formal roadworthy inspection without issues. A minor cosmetic imperfection or two — nothing mechanical.','Very Good Condition'],
                ['Green Sticker','fa-circle-check','#16a34a','#dcfce7','Minor repairs may be needed to obtain a roadworthy certificate. The full DEKRA report details exactly what is required. All repairs are disclosed upfront.','Requires Minor Repairs'],
            ];
            foreach ($dekraGrades as [$name, $icon, $color, $bg, $desc, $label]):
            ?>
            <div style="background:<?= $bg ?>;border:1.5px solid <?= $color ?>44;border-radius:12px;padding:1.25rem;display:flex;gap:1rem;align-items:flex-start;">
                <div style="width:42px;height:42px;background:<?= $color ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas <?= $icon ?>" style="color:#fff;font-size:1rem;"></i>
                </div>
                <div>
                    <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.1em;color:<?= $color ?>;font-weight:700;margin-bottom:.2rem;"><?= $label ?></div>
                    <h4 style="color:#1a1a1a;font-size:.9rem;font-weight:700;margin-bottom:.35rem;"><?= $name ?></h4>
                    <p style="color:#445;font-size:.8rem;line-height:1.65;margin:0;"><?= $desc ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</section>


<!-- ══════════════════════════════════════════════════════════
     IN-HOUSE FINANCE BANNER
══════════════════════════════════════════════════════════ -->
<section style="background:linear-gradient(135deg,#002850,#003f73);padding:3.5rem 1.5rem;">
<div class="pa-container">
    <div class="pa-finance-grid" style="display:grid;grid-template-columns:1fr auto;gap:2rem;align-items:center;flex-wrap:wrap;">
        <div>
            <span style="display:inline-block;background:rgba(37,211,102,.15);border:1px solid rgba(37,211,102,.35);color:#6eff9e;font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:.3rem .9rem;border-radius:50px;margin-bottom:.85rem;">
                <i class="fas fa-check-circle"></i> Everyone Qualifies
            </span>
            <h2 style="color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:clamp(1.4rem,3vw,2rem);font-weight:800;margin-bottom:.75rem;line-height:1.2;">
                Don't Have the Full Commitment Fee? <span style="color:#ffd54f;">Our Finance Team Has You Covered.</span>
            </h2>
            <p style="color:rgba(255,255,255,.8);font-size:.92rem;line-height:1.75;max-width:580px;margin-bottom:1rem;">
                Our in-house finance requires <strong style="color:#fff;">no credit checks, no ITC clearance, and no bureau inquiries.</strong>
                Blacklisted? Low credit score? We work with you, not against you.
                Flexible repayment plans from 6 to 48 months with a deposit from just <strong style="color:#ffd54f;">R10,500</strong>.
            </p>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                <a href="<?= APP_URL ?>/financing.php"
                   style="background:#fff;color:#003f73;padding:.75rem 1.75rem;border-radius:8px;text-decoration:none;font-weight:700;font-size:.88rem;display:inline-flex;align-items:center;gap:.4rem;">
                    <i class="fas fa-hand-holding-dollar"></i> Learn About Finance
                </a>
                <a href="mailto:<?= COMPANY_EMAIL ?>?subject=In-House%20Finance%20Enquiry%20—%20Pre-Auction"
                   style="background:rgba(255,255,255,.15);color:#fff;padding:.75rem 1.75rem;border-radius:8px;text-decoration:none;font-weight:700;font-size:.88rem;display:inline-flex;align-items:center;gap:.4rem;border:1px solid rgba(255,255,255,.3);">
                    <i class="fas fa-envelope"></i> Apply via Email
                </a>
            </div>
        </div>
        <div style="display:grid;gap:.65rem;min-width:220px;">
            <?php $financePoints = [['fa-ban','No Credit Checks'],['fa-calendar-check','6–48 Month Terms'],['fa-hand-holding-dollar','From R10,500 Deposit'],['fa-file-slash','No ITC Required']]; ?>
            <?php foreach ($financePoints as [$icon, $label]): ?>
            <div style="display:flex;align-items:center;gap:.75rem;background:rgba(255,255,255,.1);border-radius:8px;padding:.65rem 1rem;">
                <i class="fas <?= $icon ?>" style="color:#ffd54f;width:16px;text-align:center;"></i>
                <span style="color:#fff;font-size:.85rem;font-weight:600;"><?= $label ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</section>


<!-- ══════════════════════════════════════════════════════════
     FAQ
══════════════════════════════════════════════════════════ -->
<section class="pa-section" style="background:#fff;">
<div class="pa-container" style="max-width:820px;">
    <div style="text-align:center;">
        <span class="pa-section-label"><i class="fas fa-circle-question"></i> FAQ</span>
        <h2 class="pa-section-title" style="text-align:center;margin:.5rem auto 2.5rem;">Frequently Asked Questions</h2>
    </div>
    <?php
    $faqs = [
        ['Is the pre-auction deal legally binding?',
         'Yes. When you pay the commitment fee and sign the proforma invoice, you enter into a legally binding sales agreement with Auction Hub Group, acting as the authorised agent of the financial institution. The vehicle is immediately registered as Presold under your name with the financial institution. All terms are clearly stated in the invoice and agreement document provided to you upfront.'],
        ['What happens if the financial institution rejects my commitment?',
         'In rare cases, the financial institution that holds the vehicle may decline a pre-auction commitment — for example, if the vehicle\'s loan account has changed status, if there is an active legal matter on the title, or if the institution has internally mandated the vehicle proceed directly to auction. If your commitment is declined, your full commitment fee is refunded within 3–5 business days, less R102 in administration and bank charges. You will receive a formal written explanation.'],
        ['Can I inspect the vehicle before paying the commitment fee?',
         'The commitment fee is a prerequisite to accessing and inspecting the vehicle. This is a requirement of the financial institution — the vehicle cannot be removed from the secured lot for inspection without a confirmed commitment. Once your fee is paid and your Buyer Code issued, you have 1 hour 30 minutes for a full test drive and inspection, during which you may bring your own mechanic.'],
        ['What if I\'m not happy after the test drive?',
         'You are fully protected. After the 1.5-hour test drive and inspection you have three options: proceed with the purchase, switch to your nominated alternative vehicle, or cancel the deal entirely. If you cancel, your commitment fee is refunded in full within 3–5 business days — only R102.00 is retained for administration and bank charges. No pressure, no penalties.'],
        ['Can I change my mind and choose a different vehicle?',
         'Yes. When paying your commitment fee you can nominate an alternative vehicle. If after your test drive and inspection you decide the primary vehicle is not right for you, you may switch to your nominated alternative without losing your commitment. You may also cancel entirely and receive a full refund less the R102 admin and bank charge.'],
        ['How quickly will I receive my Buyer Code after paying?',
         'Buyer Codes are issued by email once your proof of payment has been reviewed and confirmed with the financial institution. This typically happens within a few hours during business hours. Urgent requests can be escalated by calling us directly.'],
        ['Are these vehicles stolen or salvaged?',
         'No. All vehicles in our pre-auction inventory are repossessed through a legal, court-supervised process by the financial institution. Each vehicle comes with a clean title, a DEKRA inspection certificate, and a roadworthy certificate. We do not accept stolen or salvage-title vehicles.'],
        ['Can I buy the vehicle if I live outside South Africa?',
         'Yes. We offer SADC-wide delivery for cross-border buyers. Additional documentation may be required for vehicles crossing international borders. Contact our team to discuss your specific requirements before committing.'],
        ['What is the 24-hour validity window?',
         'The 24-hour window is the timeframe during which you must pay your commitment fee to secure the vehicle at the pre-auction price. This deadline is set by the financial institution and is non-negotiable — it reflects the time window during which the vehicle can legally be withheld from the auction process. Once the window closes, the vehicle is immediately listed for public auction.'],
    ];
    foreach ($faqs as $i => [$q, $a]):
    ?>
    <div class="pa-faq-item">
        <div class="pa-faq-q" onclick="toggleFaq(this)">
            <?= $q ?>
            <i class="fas fa-chevron-down faq-icon"></i>
        </div>
        <div class="pa-faq-a"><?= $a ?></div>
    </div>
    <?php endforeach; ?>
</div>
</section>


<!-- ══════════════════════════════════════════════════════════
     FINAL CTA
══════════════════════════════════════════════════════════ -->
<section style="background:linear-gradient(135deg,#002850,#003f73,#005BA1);padding:4.5rem 1.5rem;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.04) 1px,transparent 1px);background-size:22px 22px;pointer-events:none;"></div>
    <div class="pa-container" style="text-align:center;position:relative;z-index:1;">
        <span style="display:inline-block;background:rgba(255,215,0,.15);border:1px solid rgba(255,215,0,.35);color:#ffd54f;font-size:.72rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;padding:.35rem 1rem;border-radius:50px;margin-bottom:1.25rem;">
            <i class="fas fa-bolt"></i> Limited Time — 24-Hour Window
        </span>
        <h2 style="color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:clamp(1.6rem,4vw,2.4rem);font-weight:800;margin-bottom:.85rem;line-height:1.15;">
            Secure Your Pre-Auction Vehicle Before It Hits the Floor
        </h2>
        <p style="color:rgba(255,255,255,.8);font-size:.95rem;max-width:560px;margin:0 auto 1rem;line-height:1.8;">
            Once the commitment window closes, the vehicle moves to public auction where you'll compete with hundreds of bidders — and pay a 5–10% buyer's commission on top.
        </p>
        <p style="color:rgba(255,255,255,.5);font-size:.85rem;margin-bottom:2.5rem;">
            The smart move is to act before the auction. Contact us now.
        </p>
        <div style="display:flex;justify-content:center;gap:.85rem;flex-wrap:wrap;">
            <a href="<?= APP_URL ?>/login.php?tab=register"
               style="background:#fff;color:#003f73;padding:.9rem 2.25rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.95rem;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="fas fa-user-plus"></i> Register Free
            </a>
            <a href="mailto:<?= COMPANY_EMAIL ?>?subject=Pre-Auction%20Purchase%20Enquiry&body=Hi%20AHG%2C%0A%0AI%27m%20interested%20in%20a%20pre-auction%20purchase.%20Please%20send%20me%20available%20vehicles.%0A%0ARegards"
               style="background:rgba(255,255,255,.12);color:#fff;padding:.9rem 2.25rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.95rem;display:inline-flex;align-items:center;gap:.5rem;border:1px solid rgba(255,255,255,.3);">
                <i class="fas fa-envelope"></i> Email to Enquire
            </a>
            <a href="mailto:<?= COMPANY_EMAIL ?>?subject=Pre-Auction%20Purchase%20Enquiry&body=Hi%20AHG%2C%0A%0AI%27m%20interested%20in%20making%20a%20pre-auction%20purchase.%20Please%20send%20me%20available%20vehicles.%0A%0ARegards"
               style="background:rgba(255,255,255,.12);color:#fff;padding:.9rem 2.25rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.95rem;display:inline-flex;align-items:center;gap:.5rem;border:1px solid rgba(255,255,255,.3);">
                <i class="fas fa-envelope"></i> Send an Email
            </a>
        </div>
    </div>
</section>

<script>
function toggleFaq(el) {
    const answer = el.nextElementSibling;
    const isOpen = answer.classList.contains('open');
    // Close all
    document.querySelectorAll('.pa-faq-a').forEach(a => a.classList.remove('open'));
    document.querySelectorAll('.pa-faq-q').forEach(q => q.classList.remove('open'));
    if (!isOpen) {
        answer.classList.add('open');
        el.classList.add('open');
    }
}
// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior:'smooth', block:'start' });
        }
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
