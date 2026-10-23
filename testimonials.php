<?php
// ============================================================
// AUCTION HUB GROUP — testimonials.php
// Dedicated testimonials page — all active testimonials displayed
// ============================================================

$pageTitle       = 'Buyer Reviews & Testimonials';
$pageDescription = 'See what real buyers say about Auction Hub Group. Trusted auctioneering excellence since 2016.';
$bodyClass       = 'page-testimonials';

require_once 'header.php';

// Fetch all active testimonials
$testimonials = [];
try {
    $testimonials = $pdo->query("
        SELECT id, headline, image_url
        FROM testimonials
        WHERE is_active = 1
        ORDER BY sort_order ASC, id DESC
    ")->fetchAll();
} catch (Exception $e) {
    $testimonials = [];
}
?>

<!-- Hero -->
<section style="background:linear-gradient(135deg,#003f73 0%,#005BA1 50%,#0078d7 100%);padding:4rem 1.5rem 3rem;text-align:center;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.04) 1px,transparent 1px);background-size:24px 24px;pointer-events:none;"></div>
    <div class="container" style="position:relative;z-index:1;">
        <div style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);border-radius:50px;padding:.4rem 1.25rem;margin-bottom:1.25rem;">
            <i class="fas fa-star" style="color:#ffd54f;font-size:.8rem;"></i>
            <span style="color:rgba(255,255,255,.9);font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;">Testimonials</span>
        </div>
        <h1 style="color:#fff;font-size:clamp(1.6rem,3.5vw,2.4rem);margin-bottom:.75rem;font-weight:800;">
            What Our Buyers Say
        </h1>
        <p style="color:rgba(255,255,255,.8);font-size:1rem;max-width:600px;margin:0 auto;line-height:1.7;">
            Since 2016, thousands of satisfied buyers, and a legacy built on integrity. Here are real stories from real buyers.
        </p>
        <div style="display:flex;justify-content:center;gap:2rem;margin-top:2rem;">
            <div style="text-align:center;">
                <div style="font-size:1.8rem;font-weight:800;color:#fff;font-family:'Barlow Condensed',sans-serif;">98,000+</div>
                <div style="font-size:.75rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;">Vehicles Sold</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:1.8rem;font-weight:800;color:#ffd54f;font-family:'Barlow Condensed',sans-serif;"><?= date('Y') - 2016 ?>+</div>
                <div style="font-size:.75rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;">Years Trusted</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:1.8rem;font-weight:800;color:#6eff9e;font-family:'Barlow Condensed',sans-serif;">6,000+</div>
                <div style="font-size:.75rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;">Verified Bidders</div>
            </div>
        </div>
    </div>
</section>

<!-- Testimonials Grid -->
<section style="padding:3.5rem 1.5rem;background:#f8fafc;">
    <div class="container">

        <?php if (empty($testimonials)): ?>
        <div class="empty-state" style="text-align:center;padding:4rem 1rem;">
            <i class="fas fa-images" style="font-size:3rem;color:#ccc;margin-bottom:1rem;display:block;"></i>
            <h3 style="color:#003f73;margin-bottom:.5rem;">Testimonials Coming Soon</h3>
            <p style="color:#888;max-width:400px;margin:0 auto;">We're collecting stories from our amazing buyers. Check back soon!</p>
        </div>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.5rem;">
            <?php foreach ($testimonials as $t): ?>
            <div style="background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e5eaf0;transition:transform .3s,box-shadow .3s;" onmouseenter="this.style.transform='translateY(-4px)';this.style.boxShadow='0 12px 32px rgba(0,0,0,.1)'" onmouseleave="this.style.transform='none';this.style.boxShadow='none'">
                <div style="width:100%;aspect-ratio:4/3;overflow:hidden;background:#f0f0f0;display:flex;align-items:center;justify-content:center;">
                    <img src="<?= e(imgHost($t['image_url'])) ?>" alt="<?= e($t['headline']) ?>"
                         style="width:100%;height:100%;object-fit:cover;" loading="lazy"
                         onerror="this.parentElement.innerHTML='<i class=\'fas fa-image\' style=\'font-size:2rem;color:#ccc;\'></i>'">
                </div>
                <div style="padding:1rem 1.25rem;text-align:center;">
                    <p style="margin:0;font-size:.95rem;font-weight:600;color:#003f73;line-height:1.5;"><?= e($t['headline']) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</section>

<!-- CTA Banner -->
<section style="background:linear-gradient(135deg,#001F5C,#005DDB);padding:3rem 1.5rem;text-align:center;">
    <div class="container">
        <h2 style="color:#fff;font-size:clamp(1.3rem,2.5vw,1.8rem);margin-bottom:.75rem;">Ready to Join Our Growing Community?</h2>
        <p style="color:rgba(255,255,255,.7);margin-bottom:1.5rem;max-width:500px;margin-left:auto;margin-right:auto;">Register today and start bidding on quality vehicles at below-market prices.</p>
        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:.75rem;">
            <a href="auctions.php" style="background:#005BA1;color:#fff;padding:.8rem 1.75rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.9rem;display:inline-flex;align-items:center;gap:.4rem;">
                <i class="fas fa-gavel"></i> Browse Auctions
            </a>
            <a href="login.php?tab=register" style="background:transparent;color:#fff;padding:.8rem 1.75rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.9rem;display:inline-flex;align-items:center;gap:.4rem;border:2px solid rgba(255,255,255,.3);">
                <i class="fas fa-user-plus"></i> Register Free
            </a>
        </div>
    </div>
</section>

<?php require_once 'footer.php'; ?>
