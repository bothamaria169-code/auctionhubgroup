<?php
// ============================================================
// AUCTION HUB GROUP — about.php
// Company history, accreditations, and trust signals
// ============================================================
$pageTitle       = 'About Us — Bank Repossessed Car Auctioneers';
$pageDescription = 'Auction Hub Group was founded in 2016. Trusted auctioneering excellence. Level 2 BBBEE. Best Auctioneer 2018 & 2019 by Standard Bank.';
$bodyClass       = 'page-about';
require_once __DIR__ . '/header.php';

// Fetch active team members
$aboutTeam = [];
try {
    $aboutTeam = $pdo->query("
        SELECT name, position, bio, email, phone, photo_url
        FROM team_members
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
        LIMIT 12
    ")->fetchAll();
} catch (Exception $e) { $aboutTeam = []; }

function aboutInitials(string $name): string {
    $words = array_filter(explode(' ', trim($name)));
    $init = '';
    foreach ($words as $w) $init .= mb_strtoupper(mb_substr($w, 0, 1));
    return mb_substr($init, 0, 2);
}
?>

<!-- ═══════════ HERO ═══════════ -->
<section style="background:linear-gradient(135deg,#003f73 0%,#005BA1 60%,#0078d7 100%);padding:5rem 1.5rem 4rem;text-align:center;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.05) 1px,transparent 1px);background-size:28px 28px;pointer-events:none;"></div>
    <div class="container" style="position:relative;z-index:1;">
        <p style="color:#7ec8ff;font-size:.88rem;letter-spacing:.15em;text-transform:uppercase;margin-bottom:.75rem;font-weight:600;">Founded 2016</p>
        <h1 style="color:#fff;font-size:clamp(2.2rem,5vw,3.6rem);line-height:1.1;margin-bottom:1.25rem;">South Africa's Premier<br>Multi-Asset Auction House</h1>
        <p style="color:rgba(255,255,255,.8);font-size:1.1rem;max-width:680px;margin:0 auto 2rem;line-height:1.7;">
            A reputation built on trusted auctioneering excellence.<br>
            <em>Trusted by banks. Respected by buyers.</em>
        </p>
        <nav aria-label="Breadcrumb" style="color:rgba(255,255,255,.5);font-size:.87rem;">
            <a href="<?= APP_URL ?>/index.php" style="color:rgba(255,255,255,.6);text-decoration:none;">Home</a>
            <span style="margin:0 .5rem;">/</span>
            <span style="color:#fff;">About Us</span>
        </nav>
    </div>
</section>

<main style="padding:4rem 1.5rem;background:#f5f7fa;">
<div class="container" style="max-width:1100px;margin:0 auto;">

    <!-- ═══════════ OUR STORY ═══════════ -->
    <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);border-left:5px solid #005BA1;">
        <h2 style="color:#003f73;font-size:1.6rem;margin-bottom:1rem;display:flex;align-items:center;gap:.75rem;">
            <i class="fas fa-building" style="color:#005BA1;"></i> Our Story
        </h2>
        <p style="color:#555;line-height:1.8;margin-bottom:1rem;">
            Auction Hub Group was <strong>founded in 2016</strong> with a clear mandate: to connect South African buyers with quality bank-repossessed vehicles through transparent, professional, and accessible auctions. From our very first event, we set out to do things differently — with integrity at the core of every transaction.
        </p>
        <p style="color:#555;line-height:1.8;margin-bottom:1rem;">
            Our reputation is built on <strong>cast-iron integrity</strong> in every client and stakeholder relationship. We retain our leading position by raising the bar across every aspect of our business and setting the pace in the auction industry.
        </p>
        <p style="color:#555;line-height:1.8;">
            We work directly with banks and credit providers to source high-quality repossessed vehicles &mdash; passenger cars, SUVs, bakkies, commercial vehicles, mining equipment, agricultural assets, and more &mdash; and make them available to registered bidders at competitive auction prices. Our auction platform blends traditional live auction energy with modern online bidding, enabling buyers from across South Africa to participate with confidence.
        </p>
    </div>

    <!-- ═══════════ TRUST PILLARS ═══════════ -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:2rem;">
        <?php
        $pillars = [
            ['fa-calendar-check','<?= date("Y") - 2016 ?>+ Years','Founded 2016. Trusted by thousands of buyers annually.','#005BA1'],
            ['fa-clipboard-check','DEKRA Inspected','Full condition transparency on every vehicle at auction.','#0078d7'],
            ['fa-hand-holding-dollar','In-House Finance','No credit checks. Blacklisted clients welcome.','#25D366'],
            ['fa-shield-halved','Regulated &amp; Compliant','Level 2 BBBEE. CPA, FICA &amp; POPI Act compliant.','#e8a000'],
            ['fa-trophy','Award-Winning','Best Auctioneer 2018 &amp; 2019 by Standard Bank.','#c9960a'],
        ];
        foreach ($pillars as [$icon,$title,$desc,$color]):
        ?>
        <div style="background:#fff;border-radius:14px;padding:1.5rem;text-align:center;box-shadow:0 2px 20px rgba(0,0,0,.06);border-top:3px solid <?= $color ?>;">
            <div style="width:48px;height:48px;background:<?= $color ?>;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;">
                <i class="fas <?= $icon ?>" style="color:#fff;font-size:1.1rem;"></i>
            </div>
            <h4 style="color:#003f73;font-size:.95rem;margin-bottom:.4rem;"><?= $title ?></h4>
            <p style="color:#666;font-size:.82rem;line-height:1.55;"><?= $desc ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ═══════════ THREE WAYS TO BUY ═══════════ -->
    <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
        <h2 style="color:#003f73;font-size:1.5rem;margin-bottom:1.5rem;text-align:center;">Three Ways to Get Your Vehicle</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.25rem;">
            <div style="background:linear-gradient(135deg,#ebf4ff,#f0f7ff);border:1px solid #c5d9f0;border-radius:14px;padding:1.75rem;text-align:center;">
                <div style="font-size:1.75rem;color:#005BA1;margin-bottom:.75rem;"><i class="fas fa-bolt"></i></div>
                <h4 style="color:#003f73;margin-bottom:.5rem;">Pre-Auction Purchase</h4>
                <p style="color:#555;font-size:.88rem;line-height:1.65;">Skip the bidding. Secure your vehicle before auction day at the bank's outstanding balance.</p>
                <a href="<?= APP_URL ?>/preauction.php" style="color:#005BA1;font-size:.85rem;font-weight:600;text-decoration:none;margin-top:.75rem;display:inline-block;">View Pre-Auction Deals <i class="fas fa-arrow-right" style="font-size:.75rem;"></i></a>
            </div>
            <div style="background:linear-gradient(135deg,#e6f9ee,#f0faf4);border:1px solid #b7e2c5;border-radius:14px;padding:1.75rem;text-align:center;">
                <div style="font-size:1.75rem;color:#25D366;margin-bottom:.75rem;"><i class="fas fa-gavel"></i></div>
                <h4 style="color:#003f73;margin-bottom:.5rem;">Auction Bidding</h4>
                <p style="color:#555;font-size:.88rem;line-height:1.65;">Bid online or in person on vehicles, property, machinery, agricultural assets and more.</p>
                <a href="<?= APP_URL ?>/auctions.php" style="color:#005BA1;font-size:.85rem;font-weight:600;text-decoration:none;margin-top:.75rem;display:inline-block;">Browse Auctions <i class="fas fa-arrow-right" style="font-size:.75rem;"></i></a>
            </div>
            <div style="background:linear-gradient(135deg,#fff8e1,#fffbf0);border:1px solid #ffe08a;border-radius:14px;padding:1.75rem;text-align:center;">
                <div style="font-size:1.75rem;color:#e8a000;margin-bottom:.75rem;"><i class="fas fa-hand-holding-dollar"></i></div>
                <h4 style="color:#003f73;margin-bottom:.5rem;">In-House Finance</h4>
                <p style="color:#555;font-size:.88rem;line-height:1.65;">No credit checks. No ITC. Blacklisted or low credit score? Welcome. From R10,500 deposit — increase any amount to lower instalments.</p>
                <a href="<?= APP_URL ?>/financing.php" style="color:#005BA1;font-size:.85rem;font-weight:600;text-decoration:none;margin-top:.75rem;display:inline-block;">Learn About Finance <i class="fas fa-arrow-right" style="font-size:.75rem;"></i></a>
            </div>
        </div>
    </div>

    <!-- ═══════════ VISION + MISSION ═══════════ -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:1.5rem;margin-bottom:2rem;">
        <div style="background:#fff;border-radius:16px;padding:2.25rem;box-shadow:0 2px 20px rgba(0,0,0,.06);border-top:4px solid #005BA1;">
            <div style="width:52px;height:52px;background:linear-gradient(135deg,#005BA1,#0078d7);border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:1.25rem;">
                <i class="fas fa-eye" style="color:#fff;font-size:1.3rem;"></i>
            </div>
            <h3 style="color:#003f73;font-size:1.15rem;margin-bottom:.75rem;">Our Vision</h3>
            <p style="color:#666;line-height:1.75;font-size:.95rem;">To be the most trusted multi-asset auction house in South Africa &mdash; where banks choose us first and buyers return with confidence, setting the benchmark for transparency and integrity in auctioneering.</p>
        </div>
        <div style="background:#fff;border-radius:16px;padding:2.25rem;box-shadow:0 2px 20px rgba(0,0,0,.06);border-top:4px solid #e8a000;">
            <div style="width:52px;height:52px;background:linear-gradient(135deg,#e8a000,#f5c842);border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:1.25rem;">
                <i class="fas fa-bullseye" style="color:#fff;font-size:1.3rem;"></i>
            </div>
            <h3 style="color:#003f73;font-size:1.15rem;margin-bottom:.75rem;">Our Mission</h3>
            <p style="color:#666;line-height:1.75;font-size:.95rem;">To deliver fair, efficient, and fully transparent auction experiences for every participant &mdash; from the banks that trust us with their assets, to the buyers who invest their money with us. Every auction is conducted with cast-iron integrity.</p>
        </div>
    </div>

    <!-- ═══════════ WHAT SETS US APART ═══════════ -->
    <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
        <h2 style="color:#003f73;font-size:1.6rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;">
            <i class="fas fa-star" style="color:#005BA1;"></i> What Sets Us Apart
        </h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;">
            <?php
            $points = [
                ['fa-handshake','Bank-Mandated Auctions','Entrusted by financial institutions to responsibly dispose of repossessed assets &mdash; a mandate we honour with pride.'],
                ['fa-clipboard-check','DEKRA Sticker Inspections','Every vehicle undergoes a professional DEKRA inspection and receives a Green, Gold, or Platinum sticker. Full condition report provided to all bidders.'],
                ['fa-hand-holding-dollar','In-House Finance','No credit checks, no ITC. Blacklisted and debt-review clients welcome. From R10,500 deposit — pay more to lower instalments. Terms up to 48 months.'],
                ['fa-gavel','Professional Auctioneers','Experienced auctioneers who bring energy, transparency, and fairness to every event.'],
                ['fa-rotate','Regular Fresh Stock','New inventory arrives continuously. Hundreds of vehicles, properties, and assets go under the hammer each month.'],
                ['fa-headset','Dedicated Support','Available via email and phone through every step of the buying process.'],
            ];
            foreach ($points as [$icon, $title, $desc]):
            ?>
            <div style="background:#f8fafc;border-radius:12px;padding:1.5rem;border:1px solid #e5eaf0;">
                <div style="color:#005BA1;font-size:1.3rem;margin-bottom:.75rem;"><i class="fas <?= $icon ?>"></i></div>
                <h4 style="color:#003f73;margin-bottom:.5rem;font-size:.95rem;"><?= $title ?></h4>
                <p style="color:#666;font-size:.88rem;line-height:1.65;"><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ═══════════ ACCREDITATIONS & MEMBERSHIPS ═══════════ -->
    <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
        <h2 style="color:#003f73;font-size:1.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;">
            <i class="fas fa-certificate" style="color:#c9960a;"></i> Accreditation &amp; Industry Memberships
        </h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:.85rem;">
            <?php
            $accreditations = [
                ['fa-landmark','Member of South African Institute of Auctioneers (SAIA)'],
                ['fa-globe','Member of National Auctioneers Association (NAA)'],
                ['fa-building','Member of Johannesburg Chamber of Commerce and Industry (JCCI)'],
                ['fa-house-chimney','Member of Institute of Estate Agents of South Africa (IEASA)'],
                ['fa-certificate','Level 2 Certificate &mdash; Current BBBEE Framework'],
                ['fa-trophy','Awarded Best Auctioneer 2018 by Standard Bank'],
                ['fa-trophy','Awarded Best Auctioneer 2019 by Standard Bank'],
                ['fa-calendar-check','<?= date("Y") - 2016 ?>+ Years of Auctioneering Excellence'],
            ];
            foreach ($accreditations as [$icon,$text]):
            ?>
            <div style="display:flex;gap:.75rem;align-items:center;padding:.65rem .75rem;background:#f8fafc;border-radius:8px;border:1px solid #e5eaf0;">
                <i class="fas <?= $icon ?>" style="color:#c9960a;flex-shrink:0;font-size:.9rem;"></i>
                <span style="color:#444;font-size:.88rem;"><?= $text ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ═══════════ CORE VALUES ═══════════ -->
    <div style="background:linear-gradient(135deg,#003f73 0%,#005BA1 100%);border-radius:16px;padding:2.5rem;margin-bottom:2rem;color:#fff;">
        <h2 style="font-size:1.6rem;margin-bottom:1.75rem;text-align:center;">Our Core Values</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1.25rem;">
            <?php
            $values = [
                ['fa-medal','Excellence','High standards in every interaction and transaction.'],
                ['fa-scale-balanced','Honesty','Every vehicle presented truthfully. No hidden surprises.'],
                ['fa-shield-halved','Integrity','Cast-iron ethical standards in all our dealings.'],
                ['fa-users','Customer Focus','Buyers and banks always come first.'],
            ];
            foreach ($values as [$icon, $title, $desc]):
            ?>
            <div style="background:rgba(255,255,255,.1);border-radius:12px;padding:1.5rem;text-align:center;">
                <i class="fas <?= $icon ?>" style="font-size:1.75rem;color:#7ec8ff;margin-bottom:.75rem;display:block;"></i>
                <h4 style="margin-bottom:.4rem;font-size:.95rem;"><?= $title ?></h4>
                <p style="font-size:.85rem;color:rgba(255,255,255,.75);line-height:1.6;"><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ═══════════ MEET THE TEAM ═══════════ -->
    <?php if (!empty($aboutTeam)): ?>
    <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:2rem;">
            <h2 style="color:#003f73;font-size:1.6rem;margin:0;display:flex;align-items:center;gap:.75rem;">
                <i class="fas fa-users" style="color:#005BA1;"></i> Meet Our Team
            </h2>
            <a href="<?= APP_URL ?>/team.php"
               style="display:inline-flex;align-items:center;gap:.4rem;color:#005BA1;font-size:.88rem;font-weight:600;text-decoration:none;background:#f0f6ff;padding:.5rem 1rem;border-radius:8px;border:1px solid #c8d8ea;transition:background .15s;"
               onmouseover="this.style.background='#deeeff'" onmouseout="this.style.background='#f0f6ff'">
                View Full Team <i class="fas fa-arrow-right" style="font-size:.75rem;"></i>
            </a>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1.25rem;">
            <?php
            $teamGrads = [
                'linear-gradient(135deg,#001F5C,#005DDB)',
                'linear-gradient(135deg,#003f73,#0078d7)',
                'linear-gradient(135deg,#1a3a5c,#005BA1)',
                'linear-gradient(135deg,#002060,#0044a3)',
                'linear-gradient(135deg,#001845,#0050c8)',
                'linear-gradient(135deg,#00305f,#006ad0)',
            ];
            foreach ($aboutTeam as $i => $m):
                $grad = $teamGrads[$i % count($teamGrads)];
                $initials = aboutInitials($m['name']);
            ?>
            <a href="<?= APP_URL ?>/team.php" style="text-decoration:none;"
               onmouseover="this.querySelector('.team-card-inner').style.transform='translateY(-4px)';this.querySelector('.team-card-inner').style.boxShadow='0 10px 30px rgba(0,31,92,0.15)'"
               onmouseout="this.querySelector('.team-card-inner').style.transform='none';this.querySelector('.team-card-inner').style.boxShadow='0 2px 12px rgba(0,31,92,0.07)'">
                <div class="team-card-inner" style="background:#fff;border-radius:14px;border:1px solid #e2eaf4;box-shadow:0 2px 12px rgba(0,31,92,0.07);overflow:hidden;transition:transform .25s,box-shadow .25s;text-align:center;">

                    <!-- Photo / Initials -->
                    <div style="background:<?= $grad ?>;padding:1.5rem 1rem 1rem;position:relative;overflow:hidden;">
                        <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.05) 1px,transparent 1px);background-size:16px 16px;pointer-events:none;"></div>
                        <?php if ($m['photo_url']): ?>
                        <img src="<?= e(imgHost($m['photo_url'])) ?>" alt="<?= e($m['name']) ?>"
                             style="width:70px;height:70px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.3);position:relative;z-index:1;"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div style="width:70px;height:70px;border-radius:50%;background:rgba(255,255,255,.15);border:3px solid rgba(255,255,255,.3);display:none;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;color:#fff;margin:0 auto;font-family:'Barlow Condensed',sans-serif;position:relative;z-index:1;">
                            <?= $initials ?>
                        </div>
                        <?php else: ?>
                        <div style="width:70px;height:70px;border-radius:50%;background:rgba(255,255,255,.15);border:3px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;color:#fff;margin:0 auto;font-family:'Barlow Condensed',sans-serif;position:relative;z-index:1;">
                            <?= $initials ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Info -->
                    <div style="padding:.9rem .85rem;">
                        <div style="font-weight:700;color:#003f73;font-size:.92rem;margin-bottom:.2rem;line-height:1.3;"><?= e($m['name']) ?></div>
                        <div style="font-size:.75rem;color:#6a7f96;font-weight:500;"><?= e($m['position']) ?></div>
                        <?php if ($m['email'] || $m['phone']): ?>
                        <div style="margin-top:.65rem;display:flex;justify-content:center;gap:.4rem;flex-wrap:wrap;">
                            <?php if ($m['email']): ?>
                            <span style="display:inline-flex;align-items:center;gap:.25rem;font-size:.7rem;color:#005BA1;background:#f0f6ff;padding:.2rem .5rem;border-radius:4px;">
                                <i class="fas fa-envelope" style="font-size:.65rem;"></i> Email
                            </span>
                            <?php endif; ?>
                            <?php if ($m['phone']): ?>
                            <span style="display:inline-flex;align-items:center;gap:.25rem;font-size:.7rem;color:#001F5C;background:#f0f6ff;padding:.2rem .5rem;border-radius:4px;">
                                <i class="fas fa-phone" style="font-size:.65rem;"></i> Call
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══════════ CTA ═══════════ -->
    <div style="text-align:center;background:#fff;border-radius:16px;padding:3rem 2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
        <h2 style="color:#003f73;font-size:1.6rem;margin-bottom:1rem;">Ready to Start Bidding?</h2>
        <p style="color:#666;margin-bottom:2rem;max-width:460px;margin-left:auto;margin-right:auto;">Register your free account today and take the first step toward owning your next vehicle at an auction price. Trust and integrity back every transaction.</p>
        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:.75rem;">
            <a href="<?= APP_URL ?>/login.php?tab=register" style="background:#005BA1;color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.95rem;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="fas fa-user-plus"></i> Register Free
            </a>
            <a href="<?= APP_URL ?>/auctions.php" style="color:#005BA1;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.95rem;border:2px solid #005BA1;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="fas fa-gavel"></i> Browse Auctions
            </a>
            <a href="<?= APP_URL ?>/financing.php" style="background:#25D366;color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.95rem;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="fas fa-hand-holding-dollar"></i> In-House Finance
            </a>
        </div>
    </div>

</div>
</main>

<!-- ══════════════════════════════════════════════════════════
     OUR BRANCHES / AUCTION GROUNDS
     ══════════════════════════════════════════════════════════ -->
<section style="background:#f0f4f8;padding:4rem 1.5rem;" id="branches">
    <div class="container" style="max-width:1200px;margin:0 auto;">

        <div style="text-align:center;margin-bottom:2.5rem;">
            <h2 style="color:#003f73;font-size:clamp(1.6rem,3vw,2.2rem);margin-bottom:.5rem;">
                <i class="fas fa-warehouse" style="color:#005BA1;"></i>
                Our Locations
            </h2>
            <p style="color:#4a6080;font-size:1rem;">
                Five locations across South Africa &mdash; Head Office &bull; Boksburg &bull; Bloemfontein &bull; Durban &bull; Eastern Cape
            </p>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.4rem;">

            <!-- Head Office / Centurion (Online Sales) -->
            <div class="branch-card">
                <div class="branch-card-img-wrap">
                    <img src="assets/images/headoffice.jpg"
                         alt="Auction Hub Group Head Office — 80 Sterling Rd, Centurion"
                         class="branch-card-img" loading="lazy"
                         onerror="this.style.display='none';this.closest('.branch-card-img-wrap').style.background='#003f73'">
                    <span class="branch-card-badge" style="background:#005BA1;">HEAD OFFICE</span>
                </div>
                <div class="branch-card-body">
                    <h3 class="branch-card-city"><i class="fas fa-building"></i> Centurion (Head Office)</h3>
                    <address class="branch-card-address">80 Sterling Rd<br>Kosmosdal, Centurion, 0187</address>
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
                         class="branch-card-img" loading="lazy"
                         onerror="this.style.display='none';this.closest('.branch-card-img-wrap').style.background='#1a3a5c'">
                    <span class="branch-card-badge">MAIN BRANCH</span>
                </div>
                <div class="branch-card-body">
                    <h3 class="branch-card-city"><i class="fas fa-location-dot"></i> Boksburg (Johannesburg)</h3>
                    <address class="branch-card-address">8 Top Rd, O.R. Tambo<br>Boksburg, 1459</address>
                    <div class="branch-card-tags">
                        <span class="branch-tag"><i class="fas fa-car"></i> VEHICLE AUCTIONS</span>
                        <span class="branch-tag"><i class="fas fa-tractor"></i> DEALER STOCK</span>
                        <span class="branch-tag"><i class="fas fa-truck"></i> FLEET VEHICLES</span>
                    </div>
                </div>
            </div>

            <!-- Bloemfontein -->
            <div class="branch-card">
                <div class="branch-card-img-wrap">
                    <img src="assets/images/branch-bloemfontein.jpg"
                         alt="Auction Hub Group Bloemfontein branch — Cnr R64 &amp; Valentia Road"
                         class="branch-card-img" loading="lazy"
                         onerror="this.style.display='none';this.closest('.branch-card-img-wrap').style.background='#1a3a5c'">
                </div>
                <div class="branch-card-body">
                    <h3 class="branch-card-city"><i class="fas fa-location-dot"></i> Bloemfontein</h3>
                    <address class="branch-card-address">Cnr R64 &amp; Valentia Road<br>Waterbron, Bloemfontein</address>
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
                         class="branch-card-img" loading="lazy"
                         onerror="this.style.display='none';this.closest('.branch-card-img-wrap').style.background='#1a3a5c'">
                </div>
                <div class="branch-card-body">
                    <h3 class="branch-card-city"><i class="fas fa-location-dot"></i> Gqeberha (Port Elizabeth)</h3>
                    <address class="branch-card-address">142 Burman Road, Deal Party<br>Gqeberha, Eastern Cape</address>
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
                         class="branch-card-img" loading="lazy"
                         onerror="this.style.display='none';this.closest('.branch-card-img-wrap').style.background='#1a3a5c'">
                </div>
                <div class="branch-card-body">
                    <h3 class="branch-card-city"><i class="fas fa-location-dot"></i> Durban</h3>
                    <address class="branch-card-address">Quarry Place, Off Queen Nandi Drive<br>River Horse Estate, Durban</address>
                    <div class="branch-card-tags">
                        <span class="branch-tag"><i class="fas fa-car"></i> VEHICLE AUCTIONS</span>
                        <span class="branch-tag"><i class="fas fa-gavel"></i> OPEN TO PUBLIC</span>
                    </div>
                </div>
            </div>

        </div><!-- /branches grid -->

        <p style="text-align:center;margin-top:2.5rem;font-size:.85rem;color:#4a6080;">
            <i class="fas fa-info-circle" style="color:#005BA1;"></i>
            All branches host live auctions of bank-repossessed &amp; liquidated vehicles.
            <a href="auctions.php" style="color:#005BA1;font-weight:700;">View all current lots &rarr;</a>
        </p>

    </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     ONLINE SALES FACILITY
     ══════════════════════════════════════════════════════════ -->
<section style="background:#fff;padding:4rem 1.5rem;" id="online-facility">
    <div class="container" style="max-width:1200px;margin:0 auto;">

        <div style="text-align:center;margin-bottom:2.5rem;">
            <h2 style="color:#003f73;font-size:clamp(1.6rem,3vw,2.2rem);margin-bottom:.5rem;">
                <i class="fas fa-laptop-house" style="color:#005BA1;"></i>
                Online Sales Facility
            </h2>
            <p style="color:#4a6080;font-size:1rem;max-width:640px;margin:0 auto;">
                Our state-of-the-art indoor bidding floor allows buyers to bid live from anywhere in South Africa — online or in person.
            </p>
        </div>

        <!-- Mosaic photo grid -->
        <div class="sales-mosaic" style="margin-bottom:2.5rem;">
            <div class="sales-mosaic-main">
                <a href="assets/images/sales-1.jpg" target="_blank" class="sales-img-link" aria-label="Online sales facility main view">
                    <img src="assets/images/sales-1.jpg" alt="Auction Hub Group indoor online bidding facility" class="sales-img" loading="lazy">
                    <div class="sales-img-overlay"><i class="fas fa-expand-alt"></i></div>
                </a>
            </div>
            <div class="sales-mosaic-grid">
                <a href="assets/images/sales-2.jpg" target="_blank" class="sales-img-link">
                    <img src="assets/images/sales-2.jpg" alt="Indoor auction sales floor" class="sales-img" loading="lazy">
                    <div class="sales-img-overlay"><i class="fas fa-expand-alt"></i></div>
                </a>
                <a href="assets/images/sales-3.jpg" target="_blank" class="sales-img-link">
                    <img src="assets/images/sales-3.jpg" alt="Indoor auction sales floor" class="sales-img" loading="lazy">
                    <div class="sales-img-overlay"><i class="fas fa-expand-alt"></i></div>
                </a>
                <a href="assets/images/sales-5.jpg" target="_blank" class="sales-img-link">
                    <img src="assets/images/sales-5.jpg" alt="Indoor auction sales floor" class="sales-img" loading="lazy">
                    <div class="sales-img-overlay"><i class="fas fa-expand-alt"></i></div>
                </a>
                <a href="assets/images/sales-6.jpg" target="_blank" class="sales-img-link">
                    <img src="assets/images/sales-6.jpg" alt="Indoor auction sales floor" class="sales-img" loading="lazy">
                    <div class="sales-img-overlay"><i class="fas fa-expand-alt"></i></div>
                </a>
            </div>
        </div>

        <!-- Feature bullets -->
        <div class="sales-features">
            <div class="sales-feat">
                <i class="fas fa-wifi"></i>
                <strong>LIVE ONLINE BIDDING</strong>
                <span>Bid in real-time from your phone, tablet or PC — no travel required.</span>
            </div>
            <div class="sales-feat">
                <i class="fas fa-video"></i>
                <strong>LIVE-STREAMED AUCTIONS</strong>
                <span>Watch the auction floor live and see every vehicle before you bid.</span>
            </div>
            <div class="sales-feat">
                <i class="fas fa-headset"></i>
                <strong>DEDICATED SUPPORT TEAM</strong>
                <span>Our indoor sales team is on hand to assist you through every bid.</span>
            </div>
            <div class="sales-feat">
                <i class="fas fa-shield-halved"></i>
                <strong>SECURE &amp; TRANSPARENT</strong>
                <span>All bids are recorded, timestamped and fully auditable. 100% above board.</span>
            </div>
        </div>

        <div style="text-align:center;margin-top:2.5rem;display:flex;flex-wrap:wrap;gap:.75rem;justify-content:center;">
            <a href="login.php?tab=register" style="background:#005BA1;color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.95rem;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="fas fa-user-plus"></i> Register to Bid Online
            </a>
            <a href="auctions.php" style="color:#005BA1;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.95rem;border:2px solid #005BA1;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="fas fa-gavel"></i> Browse Live Lots
            </a>
        </div>
    </div>
</section>

<!-- Shared styles for branches + sales facility -->
<style>
.branch-card { background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 16px rgba(0,63,115,.1); transition:transform .2s,box-shadow .2s; display:flex; flex-direction:column; }
.branch-card:hover { transform:translateY(-4px); box-shadow:0 8px 30px rgba(0,63,115,.18); }
.branch-card-img-wrap { position:relative; height:200px; background:#1a3a5c; overflow:hidden; }
.branch-card-img { width:100%; height:100%; object-fit:cover; display:block; }
.branch-card-badge { position:absolute; top:.7rem; left:.7rem; background:#e8a000; color:#fff; font-size:.65rem; font-weight:800; letter-spacing:.08em; padding:.22rem .65rem; border-radius:20px; }
.branch-card-body { padding:1.1rem 1.2rem 1.25rem; flex:1; display:flex; flex-direction:column; gap:.6rem; }
.branch-card-city { font-size:1rem; font-weight:700; color:#1a2a3a; margin:0; display:flex; align-items:center; gap:.4rem; }
.branch-card-city i { color:#005BA1; font-size:.9rem; }
.branch-card-address { font-style:normal; font-size:.82rem; color:#4a6080; line-height:1.6; margin:0; }
.branch-card-tags { display:flex; flex-wrap:wrap; gap:.35rem; margin-top:auto; }
.branch-tag { display:inline-flex; align-items:center; gap:.3rem; background:#eef3f9; color:#005BA1; border-radius:14px; padding:.2rem .6rem; font-size:.67rem; font-weight:700; letter-spacing:.05em; }

.sales-mosaic { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; border-radius:16px; overflow:hidden; }
.sales-mosaic-main { grid-row:span 2; }
.sales-mosaic-grid { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
.sales-img-link { display:block; position:relative; overflow:hidden; height:100%; min-height:160px; background:#1a3a5c; }
.sales-mosaic-main .sales-img-link { min-height:340px; }
.sales-img { width:100%; height:100%; object-fit:cover; display:block; transition:transform .35s ease; }
.sales-img-link:hover .sales-img { transform:scale(1.05); }
.sales-img-overlay { position:absolute; inset:0; background:rgba(0,63,115,0); display:flex; align-items:center; justify-content:center; transition:background .25s; }
.sales-img-overlay i { color:#fff; font-size:1.4rem; opacity:0; transition:opacity .25s; }
.sales-img-link:hover .sales-img-overlay { background:rgba(0,63,115,.35); }
.sales-img-link:hover .sales-img-overlay i { opacity:1; }

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

<?php require_once __DIR__ . '/footer.php'; ?>
