<?php
// ============================================================
// AHG Careers — careers.php
// Induction programme pitch + 3-step application form + job board
// ============================================================
$pageTitle       = 'Careers — Auction Sales Jobs in South Africa';
$pageDescription = 'Auction Hub Group is growing across South Africa. All positions are entry-level, performance-based and begin with a paid Induction Programme. Apply online today.';
$bodyClass       = 'page-careers';

$appliedName = trim(strip_tags($_GET['name'] ?? ''));
$showSuccess = isset($_GET['applied']);

require_once __DIR__ . '/header.php';
?>

<!-- ── Hero ──────────────────────────────────────────────── -->
<section style="background:linear-gradient(135deg,#003f73 0%,#005BA1 60%,#0078d7 100%);padding:4rem 1.5rem 3rem;text-align:center;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.05) 1px,transparent 1px);background-size:28px 28px;pointer-events:none;"></div>
    <div class="container" style="position:relative;z-index:1;max-width:700px;margin:0 auto;">
        <span style="display:inline-block;background:rgba(0,174,239,.25);color:#7ec8ff;font-size:.8rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;padding:.35rem 1rem;border-radius:20px;margin-bottom:1rem;border:1px solid rgba(126,200,255,.3);">Now Hiring</span>
        <h1 style="color:#fff;font-size:clamp(1.8rem,4.5vw,2.9rem);line-height:1.15;margin-bottom:1rem;">Sales Representative<br><span style="color:#7ec8ff;font-size:.75em;">Vehicle Advertising — Induction Programme</span></h1>
        <p style="color:rgba(255,255,255,.8);font-size:1rem;max-width:580px;margin:0 auto 1.25rem;line-height:1.7;">
            Post repossessed and auction vehicles across digital platforms and generate <strong style="color:#fff;">3 qualified leads</strong> during your induction period to earn your place on our digital marketing team — with a clear path to permanent positions and promotions through our standard HR process.
        </p>
        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:.75rem;margin-bottom:1.5rem;">
            <?php
            $chips = [
                ['fa-location-dot','Centurion, Gauteng'],
                ['fa-clock','Full-Time'],
                ['fa-money-bill-wave','R6,500 Basic + 10.5% Commission'],
                ['fa-mobile-screen','Remote-Friendly'],
            ];
            foreach ($chips as [$ic,$label]):
            ?>
            <span style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.9);padding:.4rem .9rem;border-radius:20px;font-size:.82rem;display:flex;align-items:center;gap:.4rem;border:1px solid rgba(255,255,255,.2);">
                <i class="fas <?= $ic ?>" style="font-size:.75rem;"></i><?= $label ?>
            </span>
            <?php endforeach; ?>
        </div>
        <a href="#apply-form" style="display:inline-block;background:#00AEEF;color:#fff;padding:.8rem 2.25rem;border-radius:8px;font-weight:700;font-size:.95rem;text-decoration:none;transition:background .2s;"
           onmouseover="this.style.background='#0090c5'" onmouseout="this.style.background='#00AEEF'">
            <i class="fas fa-paper-plane" style="margin-right:.4rem;"></i>Apply Now
        </a>
    </div>
</section>

<main style="background:#f5f7fa;padding:3rem 1.5rem 4rem;">
<div class="container" style="max-width:880px;margin:0 auto;">

<!-- ── Induction Target ───────────────────────────────────── -->
<div style="background:linear-gradient(135deg,#003f73,#005BA1);border-radius:16px;padding:2.25rem 2.5rem;margin-bottom:2rem;box-shadow:0 4px 24px rgba(0,63,115,.2);position:relative;overflow:hidden;">
    <div style="position:absolute;top:-30px;right:-30px;width:140px;height:140px;background:rgba(255,255,255,.04);border-radius:50%;pointer-events:none;"></div>
    <div style="position:relative;z-index:1;">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.1rem;">
            <div style="width:40px;height:40px;background:rgba(255,255,255,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-bullseye" style="color:#7ec8ff;font-size:1.1rem;"></i>
            </div>
            <h2 style="color:#fff;font-size:1.2rem;margin:0;">Your Induction Target</h2>
        </div>
        <div style="background:rgba(255,255,255,.1);border:1.5px solid rgba(126,200,255,.35);border-radius:10px;padding:1.25rem 1.5rem;margin-bottom:1.1rem;">
            <p style="color:#fff;font-size:1rem;line-height:1.8;margin:0;">
                Here is your target: Generate <strong style="color:#7ec8ff;font-size:1.15em;">3 successful leads</strong> during this induction period.
                Hit this number and you complete your induction and <strong style="color:#7fe8a0;">secure your working place</strong> on our
                <em>Sales</em> team. Permanent positions, promotions, and other roles then open up through our standard HR process.
            </p>
        </div>
        <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;margin-bottom:1rem;">
            <?php foreach ([1,2,3] as $n): ?>
            <div style="background:rgba(255,255,255,.12);border:2px solid rgba(126,200,255,.5);border-radius:10px;padding:.85rem 1.4rem;text-align:center;min-width:80px;">
                <div style="font-size:1.75rem;font-weight:800;color:#7ec8ff;line-height:1;"><?= $n ?></div>
                <div style="color:rgba(255,255,255,.65);font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;margin-top:.2rem;">Lead</div>
            </div>
            <?php endforeach; ?>
            <div style="background:rgba(40,167,69,.25);border:2px solid rgba(127,232,160,.5);border-radius:10px;padding:.85rem 1.25rem;text-align:center;display:flex;flex-direction:column;justify-content:center;min-width:80px;">
                <i class="fas fa-arrow-right" style="color:#7fe8a0;font-size:1.2rem;"></i>
                <div style="color:#7fe8a0;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:.3rem;">Permanent</div>
            </div>
        </div>
        <p style="color:rgba(255,255,255,.7);font-size:.88rem;text-align:center;margin:0;line-height:1.7;">
            Your focus will be posting repossessed and auction vehicles across digital platforms and driving qualified leads.
            This is a <strong style="color:#fff;">performance-based trial</strong> — your results speak louder than a CV.
        </p>
    </div>
</div>

<!-- ── Role Highlights ────────────────────────────────────── -->
<div style="background:#fff;border-radius:14px;padding:2rem 2.25rem;margin-bottom:2rem;box-shadow:0 2px 16px rgba(0,0,0,.06);">
    <h2 style="color:#003f73;font-size:1.2rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.6rem;">
        <i class="fas fa-briefcase" style="color:#005BA1;"></i> About This Role
    </h2>
    <p style="color:#555;line-height:1.75;margin-bottom:1.25rem;font-size:.95rem;">
        As an <strong>AHG Sales Representative (Vehicle Advertising)</strong>, you will be responsible for advertising our repossessed, bank and estate vehicles across South Africa's major online platforms — Gumtree, Facebook Marketplace, Facebook Buy &amp; Sell Groups, Ads Africa, Carzar and Junk Mail — and reporting daily on your activity and leads.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
        <?php
        $highlights = [
            ['fa-share-nodes','Platform Posting','List vehicles daily on AutoTrader, Facebook, OLX, Gumtree &amp; more.'],
            ['fa-chart-simple','Daily Reporting','Submit proof of uploads and lead counts through the worker portal.'],
            ['fa-sack-dollar','Base + Performance Pay','Base pay linked to verified daily activity and qualified leads.'],
            ['fa-shield-halved','POPIA Compliant','All candidate data is handled per POPIA (Act 4 of 2013).'],
        ];
        foreach ($highlights as [$icon,$title,$desc]):
        ?>
        <div style="background:#f8fafc;border-radius:10px;padding:1.1rem 1.25rem;border:1px solid #e8eef5;">
            <i class="fas <?= $icon ?>" style="color:#005BA1;font-size:1.1rem;margin-bottom:.5rem;display:block;"></i>
            <div style="font-weight:700;color:#003f73;font-size:.88rem;margin-bottom:.25rem;"><?= $title ?></div>
            <div style="color:#666;font-size:.82rem;line-height:1.55;"><?= $desc ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── Application Form / Success ────────────────────────── -->
<div id="apply-form" style="background:#fff;border-radius:14px;padding:2rem 2.25rem;margin-bottom:2rem;box-shadow:0 2px 16px rgba(0,0,0,.06);">

<?php if ($showSuccess): ?>
    <div style="text-align:center;padding:1.5rem 0;">
        <div style="width:72px;height:72px;background:#e6f9ee;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
            <i class="fas fa-circle-check" style="font-size:2.25rem;color:#28a745;"></i>
        </div>
        <h2 style="color:#003f73;font-size:1.5rem;margin-bottom:.75rem;">Application Received!</h2>
        <p style="color:#555;font-size:.95rem;max-width:500px;margin:0 auto 1rem;line-height:1.75;">
            <?php if ($appliedName): ?>
                Thank you, <strong><?= e($appliedName) ?></strong>!
            <?php else: ?>
                Thank you for applying!
            <?php endif; ?>
            Your application has been received.
        </p>
        <div style="background:#e6f0fc;border:1px solid #c5d8f0;border-radius:10px;padding:1.1rem 1.5rem;max-width:500px;margin:0 auto 1.5rem;text-align:left;">
            <p style="color:#003f73;font-size:.9rem;margin:0;line-height:1.8;">
                <i class="fas fa-hourglass-half" style="margin-right:.4rem;color:#005BA1;"></i>
                Our team will <strong>review your application</strong>. Once approved, you will receive your official
                <strong>Induction Letter &amp; Contract</strong> to sign — your Worker Portal access follows from there.
            </p>
        </div>
        <p style="color:#999;font-size:.8rem;margin-bottom:1.75rem;">Reference: AHG/DM/APP/<?= date('Y') ?></p>
        <a href="index.php" style="display:inline-block;background:#005BA1;color:#fff;padding:.75rem 2rem;border-radius:8px;font-weight:700;text-decoration:none;font-size:.9rem;margin-right:.5rem;">
            <i class="fas fa-home" style="margin-right:.4rem;"></i>Back to Home
        </a>
        <a href="careers.php" style="display:inline-block;background:#f0f4f9;color:#003f73;padding:.75rem 1.5rem;border-radius:8px;font-weight:600;text-decoration:none;font-size:.9rem;">
            Apply Again
        </a>
    </div>

<?php else: ?>
    <h2 style="color:#003f73;font-size:1.25rem;margin-bottom:.5rem;display:flex;align-items:center;gap:.6rem;">
        <i class="fas fa-user-plus" style="color:#005BA1;"></i> Submit Your Application
    </h2>
    <p style="color:#666;font-size:.88rem;margin-bottom:1.75rem;">Complete all three sections below. Fields marked <span style="color:#e53935;">*</span> are required.</p>

    <!-- Progress bar -->
    <div style="display:flex;gap:0;margin-bottom:2rem;border-radius:8px;overflow:hidden;border:1px solid #e5eaf0;" id="stepProgress">
        <?php
        $steps = ['1 — Personal Info','2 — Your Profile','3 — Documents'];
        foreach ($steps as $i => $label):
            $n = $i + 1;
        ?>
        <div id="prog<?= $n ?>" style="flex:1;text-align:center;padding:.65rem .5rem;font-size:.8rem;font-weight:600;background:<?= $n===1?'#005BA1':'#f8fafc' ?>;color:<?= $n===1?'#fff':'#999' ?>;border-right:<?= $n<3?'1px solid #e5eaf0':'none' ?>;transition:background .3s,color .3s;cursor:pointer;" onclick="goStep(<?= $n ?>)">
            <?= $label ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="appAlert" style="display:none;padding:.9rem 1.1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;font-weight:600;"></div>

    <form id="careerForm" enctype="multipart/form-data" novalidate>

        <!-- Step 1: Personal Information -->
        <div id="step1" class="form-step">
            <h3 style="color:#003f73;font-size:1rem;margin-bottom:1.25rem;padding-bottom:.6rem;border-bottom:1px solid #f0f3f8;">
                <i class="fas fa-circle-user" style="color:#005BA1;margin-right:.4rem;"></i>Personal Information
            </h3>
            <!-- Position selector — spans full width -->
            <div class="form-group" style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:1.1rem;">
                <label class="form-label">Position Applying For <span style="color:#e53935;">*</span></label>
                <select name="position_applied" id="positionSelect" required class="form-input" style="background:#f8fafc;">
                    <option value="">— Select a position —</option>
                    <optgroup label="Digital &amp; Marketing">
                        <option value="Sales Representative">Sales Representative (Vehicle Advertising)</option>
                        <option value="Social Media &amp; Content Creator">Social Media &amp; Content Creator</option>
                        <option value="Vehicle Photography &amp; Listing Specialist">Vehicle Photography &amp; Listing Specialist</option>
                    </optgroup>
                    <optgroup label="Sales &amp; Customer Relations">
                        <option value="Vehicle Sales Consultant">Vehicle Sales Consultant</option>
                        <option value="Customer Relations Officer">Customer Relations Officer</option>
                    </optgroup>
                    <optgroup label="Auction Operations">
                        <option value="Auction Floor Coordinator">Auction Floor Coordinator</option>
                        <option value="Logistics &amp; Delivery Coordinator">Logistics &amp; Delivery Coordinator</option>
                        <option value="Vehicle Inspector / Condition Assessor">Vehicle Inspector / Condition Assessor</option>
                    </optgroup>
                    <optgroup label="Admin &amp; Finance">
                        <option value="Finance &amp; Admin Clerk">Finance &amp; Admin Clerk</option>
                        <option value="Vehicle Procurement Assistant">Vehicle Procurement Assistant</option>
                    </optgroup>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;">
                <div class="form-group" style="display:flex;flex-direction:column;gap:.4rem;">
                    <label class="form-label">Full Name <span style="color:#e53935;">*</span></label>
                    <input type="text" name="full_name" required placeholder="First and Last name" class="form-input" autocomplete="name">
                </div>
                <div class="form-group" style="display:flex;flex-direction:column;gap:.4rem;">
                    <label class="form-label">Email Address <span style="color:#e53935;">*</span></label>
                    <input type="email" name="email" required placeholder="your@email.com" class="form-input" autocomplete="email">
                </div>
                <div class="form-group" style="display:flex;flex-direction:column;gap:.4rem;">
                    <label class="form-label">Phone Number <span style="color:#e53935;">*</span></label>
                    <input type="tel" name="phone" required placeholder="0XX XXX XXXX" class="form-input" autocomplete="tel">
                </div>
                <div class="form-group" style="display:flex;flex-direction:column;gap:.4rem;">
                    <label class="form-label">Profile Photo <span style="color:#888;font-weight:400;">(optional)</span></label>
                    <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" class="form-input" style="padding:.55rem .75rem;background:#f8fafc;">
                    <span style="font-size:.75rem;color:#999;">JPG/PNG, max 5MB</span>
                </div>
            </div>
            <div style="margin-top:1.5rem;text-align:right;">
                <button type="button" onclick="goStep(2)" class="btn-next">
                    Next: Your Profile <i class="fas fa-arrow-right" style="margin-left:.4rem;"></i>
                </button>
            </div>
        </div>

        <!-- Step 2: Professional Profile -->
        <div id="step2" class="form-step" style="display:none;">
            <h3 style="color:#003f73;font-size:1rem;margin-bottom:1.25rem;padding-bottom:.6rem;border-bottom:1px solid #f0f3f8;">
                <i class="fas fa-chart-line" style="color:#005BA1;margin-right:.4rem;"></i>Professional Profile
            </h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;">
                <div class="form-group" style="display:flex;flex-direction:column;gap:.4rem;">
                    <label class="form-label">Your Key Strengths <span style="color:#e53935;">*</span></label>
                    <textarea name="strengths" required rows="4" placeholder="e.g. Strong social media presence, content writing, vehicle knowledge, customer communication…" class="form-input" style="resize:vertical;"></textarea>
                </div>
                <div class="form-group" style="display:flex;flex-direction:column;gap:.4rem;">
                    <label class="form-label">Areas for Development <span style="color:#e53935;">*</span></label>
                    <textarea name="weaknesses" required rows="4" placeholder="e.g. Still learning video editing — enrolled in an online course to improve." class="form-input" style="resize:vertical;"></textarea>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label" style="margin-bottom:.6rem;display:block;">Previous Employment / References <span style="color:#888;font-weight:400;">(optional — up to 3)</span></label>
                    <?php foreach ([1,2,3] as $rn): ?>
                    <div style="background:#f8fafc;border-radius:8px;padding:.85rem 1rem;border:1px solid #e5eaf0;<?= $rn>1?'margin-top:.5rem;':'' ?>">
                        <span style="font-size:.72rem;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:.5rem;">Reference <?= $rn ?></span>
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.65rem;">
                            <input type="text" name="ref<?= $rn ?>_company" placeholder="Company Name" class="form-input" style="font-size:.84rem;">
                            <input type="text" name="ref<?= $rn ?>_role" placeholder="Your Role" class="form-input" style="font-size:.84rem;">
                            <input type="tel" name="ref<?= $rn ?>_phone" placeholder="Phone Number" class="form-input" style="font-size:.84rem;">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="margin-top:1.5rem;display:flex;justify-content:space-between;">
                <button type="button" onclick="goStep(1)" class="btn-back">
                    <i class="fas fa-arrow-left" style="margin-right:.4rem;"></i>Back
                </button>
                <button type="button" onclick="goStep(3)" class="btn-next">
                    Next: Documents <i class="fas fa-arrow-right" style="margin-left:.4rem;"></i>
                </button>
            </div>
        </div>

        <!-- Step 3: Documents -->
        <div id="step3" class="form-step" style="display:none;">
            <h3 style="color:#003f73;font-size:1rem;margin-bottom:1.25rem;padding-bottom:.6rem;border-bottom:1px solid #f0f3f8;">
                <i class="fas fa-paperclip" style="color:#005BA1;margin-right:.4rem;"></i>Documents &amp; Cover Letter
            </h3>
            <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:.85rem 1rem;margin-bottom:1.25rem;font-size:.85rem;color:#6d4c00;">
                <i class="fas fa-shield-halved" style="margin-right:.4rem;"></i>
                Your documents are handled in compliance with <strong>POPIA (Act 4 of 2013)</strong> and will only be used for identity verification and employment purposes.
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;">
                <div class="form-group" style="display:flex;flex-direction:column;gap:.4rem;">
                    <label class="form-label">CV / Résumé <span style="color:#e53935;">*</span></label>
                    <input type="file" name="cv" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" required class="form-input" style="padding:.55rem .75rem;background:#f8fafc;">
                    <span style="font-size:.75rem;color:#999;">PDF, Word, JPG or PNG — max 10MB</span>
                </div>
                <div class="form-group" style="display:flex;flex-direction:column;gap:.4rem;">
                    <label class="form-label">Certified ID Document <span style="color:#e53935;">*</span></label>
                    <input type="file" name="id_doc" accept=".pdf,.jpg,.jpeg,.png,.webp" required class="form-input" style="padding:.55rem .75rem;background:#f8fafc;">
                    <span style="font-size:.75rem;color:#999;">SA ID or Passport — certified copy. PDF, JPG or PNG, max 10MB</span>
                </div>
                <div class="form-group" style="display:flex;flex-direction:column;gap:.4rem;grid-column:1/-1;">
                    <label class="form-label">Certifications <span style="color:#888;font-weight:400;">(optional)</span></label>
                    <input type="file" name="cert" accept=".pdf,.jpg,.jpeg,.png,.webp" class="form-input" style="padding:.55rem .75rem;background:#f8fafc;">
                    <span style="font-size:.75rem;color:#999;">Any relevant certification — PDF, JPG or PNG</span>
                </div>
                <!-- Video Introduction -->
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label" style="margin-bottom:.6rem;display:block;">
                        Video Introduction <span style="color:#888;font-weight:400;">(optional — max 1 minute)</span>
                    </label>
                    <div style="background:#f0f7ff;border:1.5px dashed #90c0f0;border-radius:10px;padding:1.25rem 1.5rem;">
                        <div style="display:flex;align-items:flex-start;gap:1rem;">
                            <i class="fas fa-video" style="color:#005BA1;font-size:1.6rem;margin-top:.15rem;flex-shrink:0;"></i>
                            <div style="flex:1;">
                                <p style="font-size:.85rem;color:#334155;font-weight:600;margin-bottom:.3rem;">Record or upload a 1-minute video</p>
                                <p style="font-size:.8rem;color:#64748b;line-height:1.55;margin-bottom:.85rem;">
                                    Introduce yourself, share your experience and explain why you're the right fit for this role.<br>
                                    <strong>On mobile:</strong> tap Browse and choose "Take Video" to record directly with your camera.
                                </p>
                                <input type="file" name="intro_video" accept="video/mp4,video/quicktime,video/x-msvideo,video/webm,video/*" class="form-input" style="padding:.55rem .75rem;background:#fff;font-size:.83rem;">
                                <span style="font-size:.73rem;color:#94a3b8;margin-top:.3rem;display:block;">MP4, MOV, AVI or WebM — max 100MB &bull; 1 minute max</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group" style="display:flex;flex-direction:column;gap:.4rem;grid-column:1/-1;">
                    <label class="form-label">Cover Letter / Message <span style="color:#888;font-weight:400;">(optional)</span></label>
                    <textarea name="cover_letter" rows="4" placeholder="Tell us why you're a great fit for this role and what you bring to the Auction Hub Group team…" class="form-input" style="resize:vertical;"></textarea>
                </div>
            </div>
            <!-- Upload progress (shown during submission) -->
            <div id="uploadProgress" style="display:none;margin-top:1.25rem;">
                <div style="background:#e8f4ff;border:1px solid #b3d4f5;border-radius:8px;padding:.85rem 1rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;font-size:.85rem;color:#003f73;font-weight:600;">
                        <span><i class="fas fa-upload" style="margin-right:.4rem;"></i><span id="uploadStatusText">Uploading documents…</span></span>
                        <span id="uploadProgressPct">0%</span>
                    </div>
                    <div style="background:#c8dff5;border-radius:20px;height:10px;overflow:hidden;">
                        <div id="uploadProgressBar" style="background:#005BA1;height:100%;width:0%;transition:width .25s ease;border-radius:20px;"></div>
                    </div>
                    <p style="font-size:.75rem;color:#5a7a9a;margin-top:.5rem;margin-bottom:0;">Please wait — do not close this page.</p>
                </div>
            </div>

            <div style="margin-top:1.5rem;display:flex;justify-content:space-between;align-items:center;">
                <button type="button" onclick="goStep(2)" class="btn-back">
                    <i class="fas fa-arrow-left" style="margin-right:.4rem;"></i>Back
                </button>
                <button type="submit" id="submitBtn"
                    style="background:#005BA1;color:#fff;padding:.85rem 2.25rem;border:none;border-radius:8px;font-size:.95rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:.5rem;transition:background .2s;"
                    onmouseover="this.style.background='#003f73'" onmouseout="this.style.background='#005BA1'">
                    <i class="fas fa-paper-plane"></i> Submit Application
                </button>
            </div>
        </div>

    </form>
<?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     ALL CURRENT OPENINGS
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:1.5rem;">
    <h2 style="color:#003f73;font-size:1.3rem;margin-bottom:.4rem;">All Current Openings</h2>
    <p style="color:#666;font-size:.9rem;margin-bottom:.6rem;line-height:1.65;">
        Auction Hub Group is growing across South Africa. <strong>Every position is entry-level and open to all</strong> — no experience required for most roles.
    </p>
    <div style="display:inline-flex;align-items:center;gap:.5rem;background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:.55rem 1rem;font-size:.82rem;color:#7d5a00;">
        <i class="fas fa-info-circle"></i>
        <span><strong>Induction First — All Roles:</strong> Every hire starts with a performance-based Induction Programme before permanent placement. No experience needed — we train you.</span>
    </div>
</div>

<?php
// ── Role definitions ──────────────────────────────────────────────────────────
// [icon, title, department, branches (array), description, highlight (null or string)]
$allRoles = [
    // ── Digital & Marketing ───────────────────────────────────────────────────
    [
        'icon'  => 'fa-bullhorn',
        'title' => 'Sales Representative',
        'dept'  => 'Sales',
        'branches' => ['Centurion HQ','Johannesburg','Pretoria','Cape Town','Durban','Remote'],
        'desc'  => 'Advertise repossessed and auction vehicles across Gumtree, Facebook Marketplace, Ads Africa, Carzar and more. Earn R6,500 basic + 10.5% commission per vehicle sold. Generate 3 qualified leads to secure your place on the team.',
        'highlight' => 'R6,500 + Commission',
        'apply_online' => true,
    ],
    [
        'icon'  => 'fa-share-nodes',
        'title' => 'Social Media & Content Creator',
        'dept'  => 'Marketing',
        'branches' => ['Centurion HQ','Johannesburg','Remote'],
        'desc'  => 'Create engaging content for Facebook, Instagram and TikTok. Film short vehicle walkthroughs, write captions and schedule posts. Performance measured on engagement and lead quality.',
        'highlight' => null,
        'apply_online' => true,
    ],
    [
        'icon'  => 'fa-camera',
        'title' => 'Vehicle Photography & Listing Specialist',
        'dept'  => 'Marketing',
        'branches' => ['Centurion HQ','Johannesburg','Cape Town'],
        'desc'  => 'Photograph auction and repossessed vehicles to a professional standard. Upload images and write accurate listing descriptions across digital platforms.',
        'highlight' => null,
        'apply_online' => true,
    ],
    // ── Sales & Customer Relations ────────────────────────────────────────────
    [
        'icon'  => 'fa-car',
        'title' => 'Vehicle Sales Consultant',
        'dept'  => 'Sales',
        'branches' => ['Centurion HQ','Johannesburg','Pretoria','Cape Town','Durban'],
        'desc'  => 'Assist buyers on the auction floor and via phone/WhatsApp. Explain vehicle conditions, bidding process and post-sale procedures. Commission-based performance pay.',
        'highlight' => null,
        'apply_online' => true,
    ],
    [
        'icon'  => 'fa-headset',
        'title' => 'Customer Relations Officer',
        'dept'  => 'Sales',
        'branches' => ['Centurion HQ','Johannesburg','Durban'],
        'desc'  => 'Handle buyer and seller enquiries via phone, email and WhatsApp. Follow up on registrations, payments and post-auction queries. First point of contact for all clients.',
        'highlight' => null,
        'apply_online' => true,
    ],
    // ── Auction Operations ────────────────────────────────────────────────────
    [
        'icon'  => 'fa-gavel',
        'title' => 'Auction Floor Coordinator',
        'dept'  => 'Operations',
        'branches' => ['Centurion HQ','Johannesburg','Cape Town'],
        'desc'  => 'Assist in running live auction events — vehicle intake, buyer registration, bidding facilitation and post-auction paperwork. On-site role.',
        'highlight' => null,
        'apply_online' => true,
    ],
    [
        'icon'  => 'fa-truck',
        'title' => 'Logistics & Delivery Coordinator',
        'dept'  => 'Operations',
        'branches' => ['Centurion HQ','Johannesburg'],
        'desc'  => 'Coordinate vehicle collections, deliveries and transport between branches, buyers and repossession agents. Manage transport schedules and documentation.',
        'highlight' => null,
        'apply_online' => true,
    ],
    [
        'icon'  => 'fa-clipboard-check',
        'title' => 'Vehicle Inspector / Condition Assessor',
        'dept'  => 'Operations',
        'branches' => ['Centurion HQ','Johannesburg','Pretoria','Cape Town','Durban'],
        'desc'  => 'Inspect and grade vehicles on arrival. Document body damage, mechanical issues and service history. Complete DEKRA-standard condition reports.',
        'highlight' => null,
        'apply_online' => true,
    ],
    // ── Admin & Finance ───────────────────────────────────────────────────────
    [
        'icon'  => 'fa-file-invoice-dollar',
        'title' => 'Finance & Admin Clerk',
        'dept'  => 'Admin',
        'branches' => ['Centurion HQ','Cape Town'],
        'desc'  => 'Process buyer and seller invoices, capture payments and assist with financial record-keeping. Basic bookkeeping knowledge an advantage.',
        'highlight' => null,
        'apply_online' => true,
    ],
    [
        'icon'  => 'fa-handshake',
        'title' => 'Vehicle Procurement Assistant',
        'dept'  => 'Procurement',
        'branches' => ['Centurion HQ','Johannesburg','Nationwide'],
        'desc'  => 'Support the procurement team in sourcing repossessed, bank and estate vehicles. Assist with condition evaluations, paperwork and supplier liaison.',
        'highlight' => null,
        'apply_online' => true,
    ],
];

$deptColors = [
    'Marketing'   => ['#e8f0fe','#1a56db'],
    'Sales'       => ['#e6f4ea','#1a7f37'],
    'Operations'  => ['#fff3cd','#7d5a00'],
    'Admin'       => ['#f3e8ff','#6b21a8'],
    'Procurement' => ['#fce8e6','#c62828'],
];
?>

<div style="display:flex;flex-direction:column;gap:1rem;margin-bottom:1.5rem;">
<?php foreach ($allRoles as $role):
    [$deptBg, $deptColor] = $deptColors[$role['dept']] ?? ['#f0f4f8','#334155'];
    $branchList = implode(' · ', $role['branches']);
?>
    <div style="background:#fff;border-radius:14px;border:1px solid #e5eaf0;box-shadow:0 2px 10px rgba(0,0,0,.04);overflow:hidden;<?= $role['apply_online'] && $role['title'] === 'Sales Representative' ? 'border-color:#ffe082;border-width:2px;box-shadow:0 2px 16px rgba(255,193,7,.12);' : '' ?>">
        <!-- department strip -->
        <div style="background:<?= $deptBg ?>;border-bottom:1px solid rgba(0,0,0,.06);padding:.35rem 1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
            <span style="font-size:.73rem;font-weight:700;color:<?= $deptColor ?>;text-transform:uppercase;letter-spacing:.08em;"><?= $role['dept'] ?></span>
            <span style="font-size:.73rem;color:#888;display:inline-flex;align-items:center;gap:.3rem;">
                <i class="fas fa-location-dot" style="color:#005BA1;font-size:.65rem;"></i><?= e($branchList) ?>
            </span>
        </div>
        <div style="padding:1.25rem 1.75rem;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:.65rem;margin-bottom:.45rem;">
                        <div style="width:34px;height:34px;background:#f0f6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas <?= $role['icon'] ?>" style="color:#005BA1;font-size:.85rem;"></i>
                        </div>
                        <h3 style="color:#003f73;font-size:.97rem;margin:0;"><?= e($role['title']) ?></h3>
                    </div>
                    <p style="color:#555;font-size:.83rem;line-height:1.65;margin:0 0 .7rem;"><?= $role['desc'] ?></p>
                    <div style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;">
                        <span style="background:#fff8e1;color:#7d5a00;padding:.25rem .7rem;border-radius:20px;font-size:.74rem;font-weight:700;border:1px solid #ffe082;display:inline-flex;align-items:center;gap:.3rem;">
                            <i class="fas fa-graduation-cap" style="font-size:.65rem;"></i> Induction First
                        </span>
                        <span style="background:#f0f6ff;color:#005BA1;padding:.25rem .7rem;border-radius:20px;font-size:.74rem;font-weight:600;border:1px solid #cce0ff;display:inline-flex;align-items:center;gap:.3rem;">
                            <i class="fas fa-sack-dollar" style="font-size:.65rem;"></i> Performance Pay
                        </span>
                        <span style="background:#e6f4ea;color:#1a7f37;padding:.25rem .7rem;border-radius:20px;font-size:.74rem;font-weight:600;border:1px solid #c3e6cb;display:inline-flex;align-items:center;gap:.3rem;">
                            <i class="fas fa-circle-check" style="font-size:.65rem;"></i> Entry Level
                        </span>
                        <?php if ($role['highlight']): ?>
                        <span style="background:#fff3cd;color:#7d5a00;padding:.25rem .7rem;border-radius:20px;font-size:.74rem;font-weight:700;border:1px solid #ffe082;">
                            <?= e($role['highlight']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="flex-shrink:0;padding-top:.1rem;">
                    <a href="#apply-form" onclick="document.getElementById('positionSelect')&&(document.getElementById('positionSelect').value=<?= json_encode($role['title']) ?>)"
                       style="display:inline-block;background:#005BA1;color:#fff;padding:.45rem 1.1rem;border-radius:8px;font-size:.8rem;font-weight:700;text-decoration:none;white-space:nowrap;">
                        Apply Now
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- Footer CTA -->
<div style="background:#fff;border-radius:14px;padding:1.75rem 2.25rem;text-align:center;box-shadow:0 2px 16px rgba(0,0,0,.06);">
    <i class="fas fa-envelope-open-text" style="font-size:1.75rem;color:#005BA1;margin-bottom:.75rem;display:block;"></i>
    <h3 style="color:#003f73;font-size:1rem;margin-bottom:.5rem;">Not Sure Which Role to Apply For?</h3>
    <p style="color:#666;font-size:.88rem;max-width:480px;margin:0 auto 1rem;line-height:1.7;">
        Use the application form above and select the position you're most interested in — our team will guide you from there.
        You can also email us at <a href="mailto:careers@auctionhubgroup.co.za" style="color:#005BA1;font-weight:600;">careers@auctionhubgroup.co.za</a>.
    </p>
    <p style="color:#999;font-size:.78rem;margin:0;"><i class="fas fa-shield-halved" style="margin-right:.3rem;"></i>All applications handled per POPIA (Act 4 of 2013).</p>
</div>

</div>
</main>

<style>
.form-label{font-size:.85rem;font-weight:600;color:#003f73;}
.form-input{padding:.7rem .9rem;border:1.5px solid #d0dae8;border-radius:8px;font-size:.9rem;outline:none;transition:border .2s;font-family:inherit;width:100%;box-sizing:border-box;}
.form-input:focus{border-color:#005BA1;}
.btn-next{background:#005BA1;color:#fff;padding:.7rem 1.75rem;border:none;border-radius:8px;font-size:.88rem;font-weight:700;cursor:pointer;transition:background .2s;}
.btn-next:hover{background:#003f73;}
.btn-back{background:#f0f4f9;color:#003f73;padding:.7rem 1.5rem;border:none;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;transition:background .2s;}
.btn-back:hover{background:#e0e8f4;}
@media(max-width:600px){
    .form-input{font-size:.85rem;}
    div[style*="grid-template-columns:1fr 1fr"]{grid-template-columns:1fr!important;}
    div[style*="grid-template-columns:1fr 1fr 1fr"]{grid-template-columns:1fr!important;}
    div[style*="grid-column:1/-1"]{grid-column:1!important;}
}
</style>

<script>
var currentStep = 1;

function goStep(n) {
    var alert = document.getElementById('appAlert');
    alert.style.display = 'none';

    if (n > currentStep) {
        var step = document.getElementById('step' + currentStep);
        var inputs = step.querySelectorAll('[required]');
        var ok = true;
        inputs.forEach(function(inp) {
            if (!inp.value.trim()) {
                inp.style.borderColor = '#e53935';
                ok = false;
            } else {
                inp.style.borderColor = '#d0dae8';
            }
        });
        if (!ok) {
            alert.style.display = 'block';
            alert.style.background = '#fde8e8';
            alert.style.color = '#721c24';
            alert.style.border = '1px solid #f5c6cb';
            alert.textContent = 'Please fill in all required fields before continuing.';
            return;
        }
    }

    document.getElementById('step' + currentStep).style.display = 'none';
    document.getElementById('step' + n).style.display = 'block';

    for (var i = 1; i <= 3; i++) {
        var prog = document.getElementById('prog' + i);
        if (i === n) {
            prog.style.background = '#005BA1';
            prog.style.color = '#fff';
        } else if (i < n) {
            prog.style.background = '#e6f0fc';
            prog.style.color = '#005BA1';
        } else {
            prog.style.background = '#f8fafc';
            prog.style.color = '#999';
        }
    }
    currentStep = n;
    document.getElementById('apply-form').scrollIntoView({behavior:'smooth', block:'start'});
}

document.getElementById('careerForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var form      = this;
    var btn       = document.getElementById('submitBtn');
    var alertBox  = document.getElementById('appAlert');
    var progress  = document.getElementById('uploadProgress');
    var bar       = document.getElementById('uploadProgressBar');
    var pct       = document.getElementById('uploadProgressPct');
    var statusTxt = document.getElementById('uploadStatusText');

    // Client-side size guard for video
    var vid = form.querySelector('[name=intro_video]');
    if (vid && vid.files[0] && vid.files[0].size > 100 * 1024 * 1024) {
        alertBox.style.cssText = 'display:block;background:#fde8e8;color:#721c24;border:1px solid #f5c6cb;';
        alertBox.textContent = 'Video is too large (max 100 MB). Please shorten or compress it before uploading.';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading…';
    alertBox.style.display = 'none';
    progress.style.display = 'block';

    function showError(msg) {
        progress.style.display = 'none';
        alertBox.style.cssText = 'display:block;background:#fde8e8;color:#721c24;border:1px solid #f5c6cb;padding:.9rem 1.1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem;font-weight:600;';
        alertBox.textContent = msg;
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Application';
    }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'process_career.php');

    xhr.upload.addEventListener('progress', function(ev) {
        if (!ev.lengthComputable) return;
        var p = Math.round((ev.loaded / ev.total) * 100);
        bar.style.width = p + '%';
        pct.textContent = p + '%';
        if (p >= 100) {
            statusTxt.textContent = 'Processing application…';
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
        }
    });

    xhr.onload = function() {
        try {
            var data = JSON.parse(xhr.responseText);
            if (data.success) {
                var name = (form.querySelector('[name=full_name]') || {}).value || '';
                window.location.href = 'careers.php?applied=1&name=' + encodeURIComponent(name.split(' ')[0]);
            } else {
                showError(data.message || 'Submission failed. Please try again.');
            }
        } catch(err) {
            showError('Server error — please try again or email careers@auctionhubgroup.co.za');
        }
    };

    xhr.onerror = function() {
        showError('Network error — please check your connection and try again.');
    };

    xhr.send(new FormData(form));
});

document.querySelectorAll('.form-input').forEach(function(inp) {
    inp.addEventListener('input', function() { this.style.borderColor = '#d0dae8'; });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
