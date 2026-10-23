<?php
// ============================================================
// AUCTION HUB GROUP — register-to-bid.php
// Dedicated Google Ads landing page.
//
// Single goal: turn a paid click into a registered bidder.
// Everything on this page either builds trust or moves the visitor
// into the form. Do NOT add secondary offers here — every extra
// choice costs registrations.
//
// Message match matters for Quality Score: the H1 below must stay
// aligned with the ad headlines in AHG_GOOGLE_ADS_PLAYBOOK.md.
// If you rewrite one, rewrite the other.
// ============================================================
require_once 'config.php';
require_once 'tracking_helper.php';
require_once 'vehicle_strip.php';   // lotTitle()

// ── Live stock strip ─────────────────────────────────────────
// Proof that real vehicles exist, pulled from the same table the
// auctions page uses. Newest live lots first.
$lots = [];
try {
    $lots = $pdo->query("
        SELECT a.id, a.item_name, a.year, a.mileage, a.image_url,
               a.current_price, a.start_price, a.condition_grade,
               m.name AS make_name
        FROM auctions a
        LEFT JOIN makes m ON m.id = a.make_id
        WHERE a.status IN ('active','upcoming') AND a.is_sold = 0
        ORDER BY a.created_at DESC
        LIMIT 6
    ")->fetchAll();
} catch (Exception $e) {
    $lots = [];
}

// ── Headline stat: how much stock is actually available ──────
$lotCount = 0;
try {
    $lotCount = (int)$pdo->query("
        SELECT COUNT(*) FROM auctions
        WHERE status IN ('active','upcoming') AND is_sold = 0
    ")->fetchColumn();
} catch (Exception $e) {}

// ── Page meta ────────────────────────────────────────────────
$pageTitle       = 'Register to Bid on Bank Repossessed Cars';
$pageDescription = 'Free bidder registration. Bid on DEKRA-inspected bank repossessed vehicles priced at the outstanding balance, not retail. No buyer\'s commission. Refundable R10,000 deposit. Verified in 1–2 business days.';
$bodyClass       = 'page-register-to-bid';
$canonicalUrl    = CANONICAL_URL . '/register-to-bid.php';

// ── FAQ schema — the objections that actually block signups ──
$lpFaqs = [
    ['Is registration free?',
     'Yes. Creating your bidder profile costs nothing. You only pay the refundable R10,000 registration deposit when you are ready to be verified as a bidder for a live auction.'],
    ['Is the R10,000 deposit refundable?',
     'Yes, fully refundable. It is a good-faith security deposit. If you attend an auction and do not buy, it is refunded in full. If you win, it comes off your purchase price.'],
    ['Are these damaged or written-off vehicles?',
     'No. Every vehicle is professionally DEKRA inspected before auction and you receive the full condition report. You are welcome to bring your own mechanic to the viewing.'],
    ['Why are the prices below retail?',
     'The vehicles come directly from banks as liquidated or insolvency assets and are priced at the outstanding balance owed rather than at retail value. There are no middlemen and no markups.'],
    ['Do I pay a buyer\'s commission?',
     'No buyer\'s commission is payable. You pay the hammer price plus a R2,600 admin fee and VAT.'],
    ['How long does verification take?',
     'Once you upload your proof of payment, our team reviews it within 1–2 business days. Register at least 3–5 business days before the auction you want to attend.'],
];

$faqSchema = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(fn($f) => [
        '@type'          => 'Question',
        'name'           => $f[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]],
    ], $lpFaqs),
];

require_once 'header.php';

$waHeroLink = waLink("Hi Auction Hub Group, I'd like help registering as a bidder.");
?>

<script type="application/ld+json"><?= json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

<style>
/* ── Landing page scoped styles ───────────────────────────────
   Namespaced under .lp- so nothing here can leak into the rest
   of the site. Deliberately self-contained: this page gets edited
   far more often than the shared stylesheet.                     */
.lp-hero {
    background: linear-gradient(135deg, #001F5C 0%, #003f73 55%, #005BA1 100%);
    color: #fff;
    padding: 3rem 0 3.5rem;
    position: relative;
    overflow: hidden;
}
.lp-hero::after {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(circle at 82% 18%, rgba(255,213,79,.18), transparent 55%);
    pointer-events: none;
}
.lp-hero-grid {
    position: relative; z-index: 1;
    display: grid;
    grid-template-columns: 1.05fr .95fr;
    gap: 3rem;
    align-items: start;
}
.lp-eyebrow {
    display: inline-flex; align-items: center; gap: .5rem;
    background: rgba(255,213,79,.16);
    border: 1px solid rgba(255,213,79,.4);
    color: #ffd54f;
    font-weight: 700; font-size: .78rem; letter-spacing: .04em; text-transform: uppercase;
    padding: .4rem .85rem; border-radius: 50px; margin-bottom: 1.1rem;
}
.lp-h1 {
    color: #fff;                       /* overrides the global dark heading colour */
    font-family: 'Barlow Condensed', sans-serif;
    font-size: clamp(2.1rem, 4.4vw, 3.3rem);
    line-height: 1.06; font-weight: 800; margin: 0 0 1rem;
    text-transform: uppercase; letter-spacing: -.01em;
}
.lp-h1 span { color: #ffd54f; }
.lp-sub { font-size: 1.06rem; line-height: 1.65; color: rgba(255,255,255,.9); max-width: 34rem; margin-bottom: 1.6rem; }
.lp-ticks { list-style: none; padding: 0; margin: 0 0 1.8rem; display: grid; gap: .7rem; }
.lp-ticks li { display: flex; gap: .7rem; align-items: flex-start; font-size: .97rem; line-height: 1.5; }
.lp-ticks i { color: #4ade80; margin-top: .22rem; flex-shrink: 0; }
/* global `strong { color: var(--text-dark) }` would go invisible on the navy hero */
.lp-ticks strong, .lp-sub strong { color: #fff; }
.lp-hero-wa {
    display: inline-flex; align-items: center; gap: .6rem;
    background: #25d366; color: #fff; font-weight: 700;
    padding: .8rem 1.4rem; border-radius: 50px; text-decoration: none;
    box-shadow: 0 6px 20px rgba(37,211,102,.35); transition: transform .2s, box-shadow .2s;
}
.lp-hero-wa:hover { transform: translateY(-2px); color: #fff; box-shadow: 0 9px 26px rgba(37,211,102,.45); }

/* ── Form card ───────────────────────────────────────────── */
.lp-form-card {
    background: #fff; border-radius: 16px; padding: 1.9rem;
    box-shadow: 0 18px 50px rgba(0,0,0,.28); color: #0d1e2e;
    position: sticky; top: 1.5rem;
}
.lp-form-card h2 {
    font-family: 'Barlow Condensed', sans-serif; font-size: 1.65rem;
    text-transform: uppercase; margin: 0 0 .25rem; color: #001F5C;
}
.lp-form-note { font-size: .86rem; color: #5b6b7c; margin-bottom: 1.25rem; }
.lp-field { margin-bottom: .85rem; }
.lp-field label { display: block; font-size: .8rem; font-weight: 700; color: #33475b; margin-bottom: .3rem; }
.lp-field input {
    width: 100%; padding: .72rem .85rem; border: 1.5px solid #d6dee6;
    border-radius: 9px; font-size: .95rem; font-family: inherit; transition: border-color .15s, box-shadow .15s;
}
.lp-field input:focus { outline: none; border-color: #005BA1; box-shadow: 0 0 0 3px rgba(0,91,161,.14); }
.lp-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: .7rem; }
.lp-terms { display: flex; gap: .55rem; align-items: flex-start; font-size: .8rem; color: #5b6b7c; margin: .9rem 0 1.1rem; line-height: 1.45; }
.lp-terms input { margin-top: .18rem; flex-shrink: 0; }
.lp-submit {
    width: 100%; background: linear-gradient(135deg, #ffd54f, #f5a623);
    color: #001F5C; border: none; border-radius: 50px;
    padding: .95rem 1rem; font-size: 1.02rem; font-weight: 800;
    font-family: 'Barlow Condensed', sans-serif; text-transform: uppercase; letter-spacing: .03em;
    cursor: pointer; transition: transform .18s, box-shadow .18s;
    box-shadow: 0 6px 20px rgba(245,166,35,.35);
}
.lp-submit:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(245,166,35,.45); }
.lp-submit:disabled { opacity: .6; cursor: not-allowed; transform: none; }
.lp-alert { padding: .8rem .95rem; border-radius: 9px; font-size: .88rem; margin-bottom: 1rem; line-height: 1.5; }
.lp-alert-error   { background: #fdecea; color: #a4271c; border: 1px solid #f5c6c1; }
.lp-alert-success { background: #e6f7ed; color: #1a6b3c; border: 1px solid #b7e4c9; }
.lp-form-foot { text-align: center; font-size: .78rem; color: #8095a8; margin-top: .9rem; }

/* ── Trust bar ───────────────────────────────────────────── */
.lp-trust { background: #f4f7fa; border-bottom: 1px solid #e3eaf1; padding: 1.4rem 0; }
.lp-trust-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; text-align: center; }
.lp-trust-item i { font-size: 1.35rem; color: #005BA1; margin-bottom: .4rem; display: block; }
.lp-trust-item strong { display: block; font-size: .92rem; color: #001F5C; }
.lp-trust-item span { font-size: .78rem; color: #6b7d8f; }

/* ── Sections ────────────────────────────────────────────── */
.lp-section { padding: 3.2rem 0; }
.lp-section-alt { background: #f4f7fa; }
.lp-h2 {
    font-family: 'Barlow Condensed', sans-serif; text-transform: uppercase;
    font-size: clamp(1.65rem, 3vw, 2.3rem); color: #001F5C;
    text-align: center; margin: 0 0 .6rem; line-height: 1.12;
}
.lp-lede { text-align: center; color: #5b6b7c; max-width: 44rem; margin: 0 auto 2.2rem; line-height: 1.65; }

/* ── Live stock strip ────────────────────────────────────── */
.lp-lots { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.3rem; }
.lp-lot {
    background: #fff; border-radius: 13px; overflow: hidden;
    border: 1px solid #e3eaf1; text-decoration: none; color: inherit;
    transition: transform .2s, box-shadow .2s; display: block;
}
.lp-lot:hover { transform: translateY(-4px); box-shadow: 0 14px 32px rgba(0,31,92,.14); color: inherit; }
.lp-lot-img { width: 100%; height: 175px; object-fit: cover; background: #e8eef4; display: block; }
.lp-lot-body { padding: .95rem 1.05rem 1.15rem; }
.lp-lot-title { font-weight: 700; font-size: .98rem; color: #001F5C; margin-bottom: .3rem; line-height: 1.3; }
.lp-lot-meta { font-size: .79rem; color: #7b8b9c; margin-bottom: .6rem; }
.lp-lot-price { font-family: 'Barlow Condensed', sans-serif; font-size: 1.4rem; font-weight: 800; color: #005BA1; }
.lp-lot-price small { display: block; font-family: 'Barlow', sans-serif; font-size: .7rem; font-weight: 500; color: #8095a8; text-transform: uppercase; letter-spacing: .04em; }
.lp-lots-cta { text-align: center; margin-top: 2rem; }

/* ── Steps ───────────────────────────────────────────────── */
.lp-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.6rem; counter-reset: lpstep; }
.lp-step { background: #fff; border-radius: 13px; padding: 1.7rem 1.5rem; border: 1px solid #e3eaf1; position: relative; }
.lp-step::before {
    counter-increment: lpstep; content: counter(lpstep);
    position: absolute; top: -16px; left: 1.5rem;
    width: 34px; height: 34px; border-radius: 50%;
    background: linear-gradient(135deg, #ffd54f, #f5a623); color: #001F5C;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 1.05rem;
}
.lp-step h3 { font-family: 'Barlow Condensed', sans-serif; text-transform: uppercase; font-size: 1.2rem; color: #001F5C; margin: .5rem 0 .5rem; }
.lp-step p { font-size: .9rem; color: #5b6b7c; line-height: 1.6; margin: 0; }

/* ── Cost table ──────────────────────────────────────────── */
.lp-cost { max-width: 34rem; margin: 0 auto; background: #fff; border: 1px solid #e3eaf1; border-radius: 13px; overflow: hidden; }
.lp-cost-row { display: flex; justify-content: space-between; padding: .85rem 1.3rem; border-bottom: 1px solid #eef3f7; font-size: .93rem; }
.lp-cost-row:last-child { border-bottom: none; background: #f4f7fa; font-weight: 700; color: #001F5C; }
.lp-cost-row span:last-child { font-weight: 700; }
.lp-cost-note { text-align: center; font-size: .82rem; color: #7b8b9c; margin-top: 1rem; }

/* ── FAQ ─────────────────────────────────────────────────── */
.lp-faq { max-width: 46rem; margin: 0 auto; }
.lp-faq details { background: #fff; border: 1px solid #e3eaf1; border-radius: 11px; margin-bottom: .7rem; }
.lp-faq summary { padding: 1rem 1.2rem; font-weight: 700; color: #001F5C; cursor: pointer; font-size: .96rem; list-style: none; display: flex; justify-content: space-between; gap: 1rem; }
.lp-faq summary::-webkit-details-marker { display: none; }
.lp-faq summary::after { content: '+'; color: #005BA1; font-size: 1.35rem; line-height: 1; flex-shrink: 0; }
.lp-faq details[open] summary::after { content: '\2212'; }
.lp-faq p { padding: 0 1.2rem 1.1rem; margin: 0; color: #5b6b7c; line-height: 1.65; font-size: .92rem; }

/* ── Closing CTA ─────────────────────────────────────────── */
.lp-close { background: linear-gradient(135deg, #001F5C, #003f73); color: #fff; padding: 3.2rem 0; text-align: center; }
.lp-close h2 { color: #fff; font-family: 'Barlow Condensed', sans-serif; text-transform: uppercase; font-size: clamp(1.7rem, 3vw, 2.4rem); margin: 0 0 .7rem; }
.lp-close strong { color: #fff; }
.lp-close p { color: rgba(255,255,255,.85); max-width: 38rem; margin: 0 auto 1.7rem; line-height: 1.65; }
.lp-close-btns { display: flex; gap: .9rem; justify-content: center; flex-wrap: wrap; }
.lp-btn-gold {
    background: linear-gradient(135deg, #ffd54f, #f5a623); color: #001F5C;
    padding: .9rem 1.9rem; border-radius: 50px; font-weight: 800; text-decoration: none;
    font-family: 'Barlow Condensed', sans-serif; text-transform: uppercase; letter-spacing: .03em; font-size: 1.02rem;
}
.lp-btn-gold:hover { color: #001F5C; opacity: .9; }
.lp-btn-wa {
    background: #25d366; color: #fff; padding: .9rem 1.9rem; border-radius: 50px;
    font-weight: 800; text-decoration: none; font-family: 'Barlow Condensed', sans-serif;
    text-transform: uppercase; letter-spacing: .03em; font-size: 1.02rem;
    display: inline-flex; align-items: center; gap: .55rem;
}
.lp-btn-wa:hover { color: #fff; opacity: .9; }

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 991px) {
    .lp-hero-grid { grid-template-columns: 1fr; gap: 2.2rem; }
    .lp-form-card { position: static; }
    .lp-lots, .lp-steps { grid-template-columns: repeat(2, 1fr); }
    .lp-trust-grid { grid-template-columns: repeat(2, 1fr); gap: 1.2rem; }
}
@media (max-width: 640px) {
    .lp-hero { padding: 2.2rem 0 2.6rem; }
    .lp-lots, .lp-steps { grid-template-columns: 1fr; }
    .lp-field-row { grid-template-columns: 1fr; }
    .lp-form-card { padding: 1.4rem; }
}
</style>

<!-- ══════════════════════════════════════════════════════════
     HERO — headline must stay message-matched to the ad copy
     ══════════════════════════════════════════════════════════ -->
<section class="lp-hero">
    <div class="container">
        <div class="lp-hero-grid">

            <div>
                <span class="lp-eyebrow"><i class="fas fa-gavel"></i> Free Bidder Registration</span>

                <h1 class="lp-h1">
                    Bid on Bank Repossessed Cars at the <span>Bank's Price</span> — Not Retail
                </h1>

                <p class="lp-sub">
                    Vehicles come to us directly from banks as liquidated and insolvency
                    assets, priced at the outstanding balance owed. Register free, get
                    verified, and bid at our Centurion auction floor or online.
                </p>

                <ul class="lp-ticks">
                    <li><i class="fas fa-circle-check"></i> <span><strong>Every vehicle DEKRA inspected</strong> — full condition report supplied, bring your own mechanic to the viewing.</span></li>
                    <li><i class="fas fa-circle-check"></i> <span><strong>No buyer's commission.</strong> Hammer price + R2,600 admin fee + VAT. That's the whole cost.</span></li>
                    <li><i class="fas fa-circle-check"></i> <span><strong>R10,000 deposit is fully refundable</strong> — refunded in full if you don't buy, deducted from the price if you win.</span></li>
                    <li><i class="fas fa-circle-check"></i> <span><strong>Verified in 1–2 business days</strong> once your proof of payment is uploaded.</span></li>
                </ul>

                <a href="<?= e($waHeroLink) ?>" target="_blank" rel="noopener"
                   class="lp-hero-wa" data-ahg-contact="whatsapp">
                    <i class="fab fa-whatsapp" style="font-size:1.3rem"></i>
                    Prefer WhatsApp? <?= e(waDisplay()) ?>
                </a>
            </div>

            <!-- ── Signup form — the single conversion point ── -->
            <div class="lp-form-card">
                <h2>Create Your Bidder Profile</h2>
                <p class="lp-form-note">
                    Takes under a minute. Registration is free — you only pay the
                    refundable deposit when you're ready to bid.
                </p>

                <div id="lp-alert" class="lp-alert" role="alert" aria-live="polite" hidden></div>

                <form id="lp-form" novalidate>
                    <div class="lp-field">
                        <label for="lp-name">Full Name</label>
                        <input type="text" id="lp-name" name="full_name" autocomplete="name" required>
                    </div>
                    <div class="lp-field">
                        <label for="lp-email">Email Address</label>
                        <input type="email" id="lp-email" name="email" autocomplete="email" required>
                    </div>
                    <div class="lp-field-row">
                        <div class="lp-field">
                            <label for="lp-phone">Mobile Number</label>
                            <input type="tel" id="lp-phone" name="phone" autocomplete="tel" required>
                        </div>
                        <div class="lp-field">
                            <label for="lp-id">SA ID Number</label>
                            <input type="text" id="lp-id" name="id_number" inputmode="numeric" required>
                        </div>
                    </div>
                    <div class="lp-field-row">
                        <div class="lp-field">
                            <label for="lp-pass">Password</label>
                            <input type="password" id="lp-pass" name="password" autocomplete="new-password" minlength="8" required>
                        </div>
                        <div class="lp-field">
                            <label for="lp-confirm">Confirm Password</label>
                            <input type="password" id="lp-confirm" name="confirm_password" autocomplete="new-password" minlength="8" required>
                        </div>
                    </div>

                    <label class="lp-terms">
                        <input type="checkbox" id="lp-terms" required>
                        <span>
                            I accept the <a href="<?= APP_URL ?>/auction-guide.php" target="_blank" style="color:#005BA1">auction terms</a>
                            and agree to be contacted about upcoming auctions.
                        </span>
                    </label>

                    <button type="submit" class="lp-submit" id="lp-submit">
                        Register Free to Bid
                    </button>
                </form>

                <p class="lp-form-foot">
                    <i class="fas fa-lock"></i> Your details are used for FICA verification only.
                    <?= COMPANY_REGISTERED_NAME ?> — CIPC <?= COMPANY_REG_NO ?>
                </p>
            </div>

        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     TRUST BAR
     ══════════════════════════════════════════════════════════ -->
<section class="lp-trust">
    <div class="container">
        <div class="lp-trust-grid">
            <div class="lp-trust-item">
                <i class="fas fa-clipboard-check"></i>
                <strong>DEKRA Inspected</strong>
                <span>Full report on every lot</span>
            </div>
            <div class="lp-trust-item">
                <i class="fas fa-building-columns"></i>
                <strong>Bank &amp; Liquidation Stock</strong>
                <span>Sourced direct, no middlemen</span>
            </div>
            <div class="lp-trust-item">
                <i class="fas fa-certificate"></i>
                <strong>Registered Since 2016</strong>
                <span>CIPC <?= COMPANY_REG_NO ?></span>
            </div>
            <div class="lp-trust-item">
                <i class="fas fa-location-dot"></i>
                <strong>Centurion Auction Floor</strong>
                <span>80 Sterling Rd, Kosmosdal</span>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     LIVE STOCK — proof the inventory is real
     ══════════════════════════════════════════════════════════ -->
<?php if ($lots): ?>
<section class="lp-section">
    <div class="container">
        <h2 class="lp-h2">On the Floor Right Now</h2>
        <p class="lp-lede">
            <?= $lotCount > 0 ? number_format($lotCount) . ' vehicles' : 'Vehicles' ?>
            currently open for bidding or coming up at our next auction.
            Registered bidders see reserve status and can bid the moment a lot opens.
        </p>

        <div class="lp-lots">
            <?php foreach ($lots as $lot):
                $lotTitle = lotTitle($lot);   // de-duplicates year/make already in item_name
                $lotImg   = !empty($lot['image_url']) ? cldImg($lot['image_url'], CLD_CARD) : 'assets/no-image.jpg';
                $lotPrice = (float)($lot['current_price'] ?: $lot['start_price']);
            ?>
            <a href="<?= APP_URL ?>/auction.php?id=<?= (int)$lot['id'] ?>" class="lp-lot">
                <img src="<?= e($lotImg) ?>" alt="<?= e($lotTitle) ?>" class="lp-lot-img" loading="lazy" width="400" height="270">
                <div class="lp-lot-body">
                    <div class="lp-lot-title"><?= e($lotTitle) ?></div>
                    <div class="lp-lot-meta">
                        <?= $lot['mileage'] ? number_format((int)$lot['mileage']) . ' km' : 'Mileage on request' ?>
                        <?= $lot['condition_grade'] ? ' &middot; ' . e($lot['condition_grade']) . ' grade' : '' ?>
                    </div>
                    <div class="lp-lot-price">
                        R<?= number_format($lotPrice, 0, '.', ' ') ?>
                        <small>Current bid</small>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="lp-lots-cta">
            <a href="<?= APP_URL ?>/auctions.php" class="btn btn-outline">
                View all vehicles <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     HOW IT WORKS
     ══════════════════════════════════════════════════════════ -->
<section class="lp-section lp-section-alt">
    <div class="container">
        <h2 class="lp-h2">Three Steps to Your First Bid</h2>
        <p class="lp-lede">
            Register at least 3–5 business days before the auction you want to attend,
            so there is time for verification.
        </p>

        <div class="lp-steps">
            <div class="lp-step">
                <h3>Create your profile</h3>
                <p>
                    Fill in the form above — name, email, mobile and SA ID number.
                    It's free and there is no obligation to bid.
                </p>
            </div>
            <div class="lp-step">
                <h3>Pay the refundable deposit</h3>
                <p>
                    Request banking details from your dashboard, pay the R10,000 EFT
                    deposit and upload your proof of payment along with your FICA
                    documents — SA ID and proof of address under 3 months old.
                </p>
            </div>
            <div class="lp-step">
                <h3>Get verified and bid</h3>
                <p>
                    We review within 1–2 business days and issue your bidder number.
                    Bid in person at our Centurion floor or online via webcast.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     WHAT IT COSTS — pre-empts the biggest objection
     ══════════════════════════════════════════════════════════ -->
<section class="lp-section">
    <div class="container">
        <h2 class="lp-h2">What You Actually Pay</h2>
        <p class="lp-lede">No buyer's commission, no hidden add-ons. This is the whole cost structure.</p>

        <div class="lp-cost">
            <div class="lp-cost-row"><span>Bidder registration</span><span>Free</span></div>
            <div class="lp-cost-row"><span>Registration deposit (refundable)</span><span><?= REG_FEE_LABEL ?></span></div>
            <div class="lp-cost-row"><span>Buyer's commission</span><span>R0</span></div>
            <div class="lp-cost-row"><span>Admin fee per vehicle</span><span>R2,600</span></div>
            <div class="lp-cost-row"><span>VAT</span><span>15%</span></div>
            <div class="lp-cost-row"><span>You pay</span><span>Hammer price + admin fee + VAT</span></div>
        </div>

        <p class="lp-cost-note">
            Your <?= REG_FEE_LABEL ?> deposit is deducted from the purchase price if you win,
            or refunded in full if you don't. Full worked example on the
            <a href="<?= APP_URL ?>/auction-guide.php" style="color:#005BA1">Auction Guide</a>.
        </p>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     FAQ
     ══════════════════════════════════════════════════════════ -->
<section class="lp-section lp-section-alt">
    <div class="container">
        <h2 class="lp-h2">Before You Register</h2>
        <p class="lp-lede">The questions we get asked most often by first-time bidders.</p>

        <div class="lp-faq">
            <?php foreach ($lpFaqs as $i => $f): ?>
            <details<?= $i === 0 ? ' open' : '' ?>>
                <summary><?= e($f[0]) ?></summary>
                <p><?= e($f[1]) ?></p>
            </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     CLOSING CTA
     ══════════════════════════════════════════════════════════ -->
<section class="lp-close">
    <div class="container">
        <h2>Next Auction Is Filling Up</h2>
        <p>
            Registration closes for verification 3–5 business days before each auction.
            Create your free profile now so you're cleared to bid when the lots you want come up.
        </p>
        <div class="lp-close-btns">
            <a href="#lp-form" class="lp-btn-gold" onclick="document.getElementById('lp-name').focus()">Register Free to Bid</a>
            <a href="<?= e($waHeroLink) ?>" target="_blank" rel="noopener" class="lp-btn-wa" data-ahg-contact="whatsapp">
                <i class="fab fa-whatsapp"></i> WhatsApp Us
            </a>
        </div>
    </div>
</section>

<script>
/* ── Landing page signup ──────────────────────────────────────
   Posts to the same auth.php endpoint the main login page uses, so
   there is exactly one registration code path to maintain. On success
   it fires the Google Ads conversion before navigating. */
(function () {
    const form   = document.getElementById('lp-form');
    const btn    = document.getElementById('lp-submit');
    const alertB = document.getElementById('lp-alert');
    if (!form) return;

    function showAlert(msg, type) {
        alertB.className = 'lp-alert lp-alert-' + type;
        alertB.innerHTML = msg;
        alertB.hidden = false;
        alertB.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    const val = id => (document.getElementById(id).value || '').trim();

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const fullName = val('lp-name');
        const email    = val('lp-email');
        const phone    = val('lp-phone');
        const idNum    = val('lp-id');
        const password = document.getElementById('lp-pass').value;
        const confirm  = document.getElementById('lp-confirm').value;

        if (fullName.length < 2)                      return showAlert('Please enter your full name.', 'error');
        if (!email.includes('@'))                     return showAlert('Please enter a valid email address.', 'error');
        if (phone.replace(/\D/g, '').length < 10)     return showAlert('Please enter a valid mobile number.', 'error');
        if (idNum.length < 6)                         return showAlert('Please enter your SA ID number.', 'error');
        if (password.length < 8)                      return showAlert('Password must be at least 8 characters.', 'error');
        if (password !== confirm)                     return showAlert('The two passwords do not match.', 'error');
        if (!document.getElementById('lp-terms').checked)
            return showAlert('Please accept the auction terms to continue.', 'error');

        btn.disabled = true;
        btn.textContent = 'Creating your profile…';
        alertB.hidden = true;

        try {
            const fd = new FormData();
            fd.append('full_name', fullName);
            fd.append('email', email);
            fd.append('phone', phone);
            fd.append('id_number', idNum);
            fd.append('password', password);
            fd.append('confirm_password', confirm);

            const res  = await fetch('auth.php?action=register', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                const c = data.conversion || {};

                // GTM path
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({
                    event:          'ahg_registration',
                    signup_method:  'landing_page',
                    value:          c.value,
                    currency:       c.currency || 'ZAR',
                    transaction_id: c.transaction_id
                });

                // Direct gtag path
                if (typeof gtag === 'function') {
                    /* Enhanced conversions: Google hashes these in the browser and
                       uses them to match the signup back to the ad click. */
                    gtag('set', 'user_data', { email: email, phone_number: phone });
                    gtag('event', 'sign_up', { method: 'landing_page' });
                    // Google Ads auto-created conversion action from the "Submit lead
                    // form" goal. Fired only here, on a genuine completed registration —
                    // this page is also the ad's landing page, so a page-load trigger
                    // (what Google's own UI suggested) would count every click as a
                    // conversion before the visitor did anything.
                    gtag('event', 'conversion_event_submit_lead_form', {
                        value:          c.value,
                        currency:       c.currency || 'ZAR',
                        transaction_id: c.transaction_id
                    });
                    if (c.send_to) {
                        gtag('event', 'conversion', {
                            send_to:        c.send_to,
                            value:          c.value,
                            currency:       c.currency || 'ZAR',
                            transaction_id: c.transaction_id
                        });
                    }
                }
                showAlert('🎉 ' + data.message + ' Taking you to your dashboard…', 'success');
                setTimeout(() => { window.location.href = data.redirect || 'user_dashboard.php'; }, 1600);
            } else {
                showAlert(data.message || 'Registration failed. Please try again.', 'error');
                btn.disabled = false;
                btn.textContent = 'Register Free to Bid';
            }
        } catch (err) {
            showAlert('Something went wrong. Please try again, or WhatsApp us on <?= e(waDisplay()) ?>.', 'error');
            btn.disabled = false;
            btn.textContent = 'Register Free to Bid';
        }
    });
})();
</script>

<?php require_once 'footer.php'; ?>
