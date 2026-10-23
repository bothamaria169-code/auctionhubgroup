<?php
// ============================================================
// AUCTION HUB GROUP — team.php
// Meet the Team — public-facing page
// ============================================================

$pageTitle       = 'Meet Our Team';
$pageDescription = 'Get to know the dedicated professionals behind Auction Hub Group — driven by passion and auctioneering excellence since 2016.';
$bodyClass       = 'page-team';

require_once 'header.php';

$teamMembers = [];
try {
    $teamMembers = $pdo->query("
        SELECT id, name, position, bio, email, phone, photo_url
        FROM team_members
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    ")->fetchAll();
} catch (Exception $e) {
    $teamMembers = [];
}

// Generate initials from name
function memberInitials(string $name): string {
    $words = array_filter(explode(' ', trim($name)));
    $init  = '';
    foreach ($words as $w) $init .= mb_strtoupper(mb_substr($w, 0, 1));
    return mb_substr($init, 0, 2);
}

// Assign a gradient per member index for placeholder avatars
$gradients = [
    'linear-gradient(135deg,#001F5C,#005DDB)',
    'linear-gradient(135deg,#003f73,#0078d7)',
    'linear-gradient(135deg,#1a3a5c,#005BA1)',
    'linear-gradient(135deg,#002060,#0044a3)',
    'linear-gradient(135deg,#001845,#0050c8)',
    'linear-gradient(135deg,#00305f,#006ad0)',
];
?>

<!-- ══════════════════════════════════════════════════════════
     HERO
     ══════════════════════════════════════════════════════════ -->
<section style="background:linear-gradient(135deg,#001F5C 0%,#003f73 45%,#005BA1 100%);padding:5rem 1.5rem 4rem;text-align:center;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.04) 1px,transparent 1px);background-size:26px 26px;pointer-events:none;"></div>
    <!-- Decorative circles -->
    <div style="position:absolute;top:-80px;right:-80px;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.03);pointer-events:none;"></div>
    <div style="position:absolute;bottom:-60px;left:-60px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;"></div>

    <div class="container" style="position:relative;z-index:1;">
        <div style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:50px;padding:.4rem 1.25rem;margin-bottom:1.25rem;">
            <i class="fas fa-users" style="color:#7ec8ff;font-size:.8rem;"></i>
            <span style="color:rgba(255,255,255,.9);font-size:.78rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;">Our People</span>
        </div>
        <h1 style="color:#fff;font-size:clamp(1.8rem,4vw,3rem);margin-bottom:.9rem;font-weight:800;line-height:1.1;">
            Meet Our Team
        </h1>
        <p style="color:rgba(255,255,255,.78);font-size:1.05rem;max-width:580px;margin:0 auto 2rem;line-height:1.75;">
            Behind every successful auction is a dedicated team. Get to know the professionals who make Auction Hub Group the most trusted name in repossessed vehicle auctions.
        </p>
        <div style="display:flex;justify-content:center;gap:2.5rem;flex-wrap:wrap;">
            <div style="text-align:center;">
                <div style="font-size:1.8rem;font-weight:800;color:#fff;font-family:'Barlow Condensed',sans-serif;line-height:1;"><?= date('Y') - 2016 ?>+</div>
                <div style="font-size:.72rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;margin-top:.25rem;">Years Experience</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:1.8rem;font-weight:800;color:#ffd54f;font-family:'Barlow Condensed',sans-serif;line-height:1;">98K+</div>
                <div style="font-size:.72rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;margin-top:.25rem;">Vehicles Sold</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:1.8rem;font-weight:800;color:#6eff9e;font-family:'Barlow Condensed',sans-serif;line-height:1;">6K+</div>
                <div style="font-size:.72rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.08em;margin-top:.25rem;">Registered Bidders</div>
            </div>
        </div>
    </div>
</section>


<!-- ══════════════════════════════════════════════════════════
     TEAM GRID
     ══════════════════════════════════════════════════════════ -->
<section style="padding:4rem 1.5rem 5rem;background:#f4f8ff;">
    <div class="container">

        <?php if (empty($teamMembers)): ?>
        <div style="text-align:center;padding:4rem 1rem;">
            <i class="fas fa-users" style="font-size:3rem;color:#c8d8ea;display:block;margin-bottom:1rem;"></i>
            <h3 style="color:#003f73;margin-bottom:.5rem;">Team profiles coming soon</h3>
            <p style="color:#6a7f96;max-width:380px;margin:0 auto;">Our team information is being updated. Check back shortly!</p>
        </div>
        <?php else: ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:2rem;">
            <?php foreach ($teamMembers as $i => $m): ?>
            <?php $grad = $gradients[$i % count($gradients)]; ?>
            <div style="background:#fff;border-radius:18px;border:1px solid #e2eaf4;box-shadow:0 4px 24px rgba(0,31,92,0.08);overflow:hidden;display:flex;flex-direction:column;transition:transform .25s,box-shadow .25s;"
                 onmouseenter="this.style.transform='translateY(-6px)';this.style.boxShadow='0 12px 40px rgba(0,31,92,0.14)'"
                 onmouseleave="this.style.transform='none';this.style.boxShadow='0 4px 24px rgba(0,31,92,0.08)'">

                <!-- Header with photo -->
                <div style="background:<?= $grad ?>;padding:2rem 1.5rem 1.5rem;text-align:center;position:relative;overflow:hidden;">
                    <!-- Pattern overlay -->
                    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.06) 1px,transparent 1px);background-size:18px 18px;pointer-events:none;"></div>

                    <?php if ($m['photo_url']): ?>
                    <img src="<?= e(imgHost($m['photo_url'])) ?>" alt="<?= e($m['name']) ?>"
                         style="width:130px;height:130px;border-radius:50%;object-fit:cover;border:4px solid rgba(255,255,255,.25);box-shadow:0 4px 20px rgba(0,0,0,.3);margin:0 auto 1rem;display:block;position:relative;z-index:1;"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div style="width:130px;height:130px;border-radius:50%;background:rgba(255,255,255,.15);border:4px solid rgba(255,255,255,.25);display:none;align-items:center;justify-content:center;font-size:2.4rem;font-weight:800;color:#fff;margin:0 auto 1rem;font-family:'Barlow Condensed',sans-serif;position:relative;z-index:1;">
                        <?= memberInitials($m['name']) ?>
                    </div>
                    <?php else: ?>
                    <div style="width:130px;height:130px;border-radius:50%;background:rgba(255,255,255,.15);border:4px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:2.4rem;font-weight:800;color:#fff;margin:0 auto 1rem;font-family:'Barlow Condensed',sans-serif;position:relative;z-index:1;">
                        <?= memberInitials($m['name']) ?>
                    </div>
                    <?php endif; ?>

                    <h3 style="color:#fff;font-size:1.05rem;font-weight:700;margin:0 0 .3rem;position:relative;z-index:1;"><?= e($m['name']) ?></h3>
                    <div style="display:inline-block;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:50px;padding:.2rem .85rem;position:relative;z-index:1;">
                        <span style="color:rgba(255,255,255,.92);font-size:.75rem;font-weight:600;letter-spacing:.04em;"><?= e($m['position']) ?></span>
                    </div>
                </div>

                <!-- Body -->
                <div style="padding:1.25rem 1.5rem;flex:1;display:flex;flex-direction:column;gap:.85rem;">

                    <?php if ($m['bio']): ?>
                    <p style="color:#4a6080;font-size:.88rem;line-height:1.65;margin:0;"><?= e($m['bio']) ?></p>
                    <?php endif; ?>

                    <?php if ($m['email'] || $m['phone']): ?>
                    <div style="display:flex;flex-direction:column;gap:.5rem;margin-top:auto;">
                        <?php if ($m['email']): ?>
                        <a href="mailto:<?= e($m['email']) ?>"
                           style="display:flex;align-items:center;gap:.6rem;padding:.6rem .9rem;border-radius:8px;background:#f1f5f9;text-decoration:none;font-size:.84rem;font-weight:600;color:#005BA1;border:1px solid #dce6f0;transition:background .15s;"
                           onmouseover="this.style.background='#e0eeff'" onmouseout="this.style.background='#f1f5f9'">
                            <i class="fas fa-envelope" style="font-size:.85rem;color:#005BA1;flex-shrink:0;"></i>
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($m['email']) ?></span>
                        </a>
                        <?php endif; ?>
                        <?php if ($m['phone']): ?>
                        <a href="tel:<?= e(preg_replace('/\s+/','',$m['phone'])) ?>"
                           style="display:flex;align-items:center;gap:.6rem;padding:.6rem .9rem;border-radius:8px;background:#f1f5f9;text-decoration:none;font-size:.84rem;font-weight:600;color:#001F5C;border:1px solid #dce6f0;transition:background .15s;"
                           onmouseover="this.style.background='#e0eeff'" onmouseout="this.style.background='#f1f5f9'">
                            <i class="fas fa-phone" style="font-size:.85rem;color:#001F5C;flex-shrink:0;"></i>
                            <?= e($m['phone']) ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div style="margin-top:auto;padding-top:.5rem;">
                        <div style="display:flex;align-items:center;gap:.5rem;color:#94a3b8;font-size:.82rem;">
                            <i class="fas fa-envelope" style="font-size:.75rem;"></i>
                            <a href="mailto:<?= COMPANY_EMAIL ?>" style="color:#94a3b8;text-decoration:none;"><?= COMPANY_EMAIL ?></a>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>
</section>


<!-- ══════════════════════════════════════════════════════════
     CTA BANNER
     ══════════════════════════════════════════════════════════ -->
<section style="background:linear-gradient(135deg,#001F5C,#005DDB);padding:3.5rem 1.5rem;text-align:center;">
    <div class="container" style="max-width:640px;">
        <div style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:50px;padding:.35rem 1.1rem;margin-bottom:1.25rem;">
            <i class="fas fa-handshake" style="color:#ffd54f;font-size:.8rem;"></i>
            <span style="color:rgba(255,255,255,.9);font-size:.76rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;">Work With Us</span>
        </div>
        <h2 style="color:#fff;font-size:clamp(1.3rem,3vw,2rem);margin-bottom:.8rem;font-weight:800;">Ready to Buy at Auction?</h2>
        <p style="color:rgba(255,255,255,.78);margin-bottom:2rem;max-width:480px;margin-left:auto;margin-right:auto;line-height:1.7;">
            Our team is standing by to guide you through every step — registration, bidding, financing, and collection.
        </p>
        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:.75rem;">
            <a href="<?= APP_URL ?>/auctions.php"
               style="background:#fff;color:#003f73;padding:.85rem 1.75rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.9rem;display:inline-flex;align-items:center;gap:.45rem;">
                <i class="fas fa-gavel"></i> Browse Auctions
            </a>
            <a href="<?= APP_URL ?>/contact.php"
               style="background:rgba(255,255,255,.15);color:#fff;padding:.85rem 1.75rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.9rem;display:inline-flex;align-items:center;gap:.45rem;border:2px solid rgba(255,255,255,.3);">
                <i class="fas fa-envelope"></i> Contact Us
            </a>
            <a href="<?= APP_URL ?>/login.php?tab=register"
               style="background:rgba(255,255,255,.15);color:#fff;padding:.85rem 1.75rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.9rem;display:inline-flex;align-items:center;gap:.45rem;border:2px solid rgba(255,255,255,.3);">
                <i class="fas fa-user-plus"></i> Register Free
            </a>
        </div>
    </div>
</section>

<?php require_once 'footer.php'; ?>
