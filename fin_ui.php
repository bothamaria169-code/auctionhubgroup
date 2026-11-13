<?php
// ============================================================
// AUCTION HUB GROUP — fin_ui.php
// Shared look for the finance / pre-auction application pages, in the
// same style as financing.php and preauction.php (Barlow Condensed
// titles, navy gradient hero, section labels, step circles, navy card
// headers as on invoice_pop_upload.php). Buttons, forms, alerts and
// badges are the site-wide ones from style.css (.btn, .form-control …).
// ============================================================
?>
<style>
.ap-section { padding:3rem 1.5rem 4rem; background:#f5f8fc; }
.ap-container { max-width:1200px; margin:0 auto; }
.ap-hero { background:linear-gradient(135deg,#002850 0%,#003f73 45%,#005BA1 100%); padding:4rem 1.5rem 3.5rem; position:relative; overflow:hidden; text-align:center; }
.ap-hero::before { content:''; position:absolute; inset:0; background-image:radial-gradient(circle,rgba(255,255,255,.035) 1px,transparent 1px); background-size:30px 30px; pointer-events:none; }
.ap-hero::after { content:''; position:absolute; right:-80px; top:-80px; width:400px; height:400px; background:radial-gradient(circle,rgba(0,212,255,.08),transparent 70%); pointer-events:none; }
.ap-hero > div { position:relative; z-index:1; }
.ap-crumbs { color:rgba(255,255,255,.4); font-size:.82rem; margin-bottom:1.25rem; }
.ap-crumbs a { color:rgba(255,255,255,.55); text-decoration:none; }
.ap-crumbs span.sep { margin:0 .5rem; }
.ap-crumbs span.here { color:#fff; }
.ap-eyebrow { display:inline-block; background:rgba(37,211,102,.15); color:#6eff9e; font-size:.72rem; font-weight:700; padding:.4rem 1.25rem; border-radius:50px; letter-spacing:.12em; text-transform:uppercase; margin-bottom:1.1rem; border:1px solid rgba(37,211,102,.3); }
.ap-hero h1 { color:#fff; font-family:'Barlow Condensed',sans-serif; font-size:clamp(2rem,5vw,3.2rem); line-height:1.08; font-weight:800; margin:0 0 1rem; letter-spacing:.02em; }
.ap-hero p { color:rgba(255,255,255,.82); font-size:1.05rem; max-width:680px; margin:0 auto; line-height:1.7; }
.ap-chips { display:flex; flex-wrap:wrap; justify-content:center; gap:.6rem; margin-top:1.4rem; }
.ap-chips span { background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); border-radius:8px; padding:.45rem .9rem; color:#fff; font-size:.82rem; font-weight:600; }
.ap-chips i { color:#6eff9e; margin-right:.3rem; }

.ap-label { display:inline-block; background:rgba(0,91,161,.1); color:#005BA1; font-size:.72rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase; padding:.35rem 1rem; border-radius:50px; border:1px solid rgba(0,91,161,.2); margin-bottom:.8rem; }
.ap-title { font-family:'Barlow Condensed',sans-serif; font-size:clamp(1.5rem,3.2vw,2rem); font-weight:800; color:#003f73; line-height:1.15; margin:0 0 .6rem; }
.ap-sub { font-size:.93rem; color:#5a6a7a; line-height:1.7; margin:0 0 1.2rem; }

.ap-grid { display:grid; grid-template-columns:minmax(0,1.4fr) minmax(0,1fr); gap:1.5rem; align-items:start; }
@media(max-width:900px){ .ap-grid { grid-template-columns:1fr; } }

/* Cards: navy header band, as on the POP upload page */
.ap-card { background:#fff; border-radius:14px; box-shadow:0 2px 16px rgba(0,0,0,.06); overflow:hidden; margin-bottom:1.5rem; border:1px solid #e3ebf4; }
.ap-card-head { background:linear-gradient(135deg,#003f73,#005BA1); color:#fff; padding:.95rem 1.4rem; font-family:'Barlow Condensed',sans-serif; font-size:1.15rem; font-weight:700; letter-spacing:.04em; display:flex; align-items:center; gap:.55rem; }
.ap-card-head.gold { background:linear-gradient(135deg,#7a4f00,#c8860a); }
.ap-card-head.green { background:linear-gradient(135deg,#0f6b34,#1a9e4e); }
.ap-card-body { padding:1.4rem; }
.ap-card-body > p:last-child { margin-bottom:0; }
.ap-help { font-size:.84rem; color:#667; line-height:1.6; margin:.3rem 0 0; }

/* Forms: site-wide .form-group/.form-control */
.ap-form { display:grid; grid-template-columns:1fr 1fr; gap:0 1rem; }
.ap-form .full { grid-column:1/-1; }
@media(max-width:600px){ .ap-form { grid-template-columns:1fr; } }
.ap-err .form-control { border-color:var(--danger); }
.ap-err-text { color:var(--danger); font-size:.8rem; display:block; margin-top:.3rem; font-weight:600; }
.ap-check { display:flex; gap:.6rem; align-items:flex-start; font-size:.88rem; color:#334; margin:.55rem 0; font-weight:500; line-height:1.55; }
.ap-check input { margin-top:.25rem; accent-color:#005BA1; width:16px; height:16px; flex-shrink:0; }
.ap-hp { position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden; }
.ap-row { display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; }
.btn-wa { background:#25D366; border-color:#25D366; color:#fff; }
.btn-wa:hover { background:#1da851; border-color:#1da851; color:#fff; }

/* Progress: step circles like preauction.php */
.ap-steps { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:.75rem; margin:0 0 1.75rem; padding:0; list-style:none; }
.ap-steps li { background:#fff; border-radius:12px; padding:1rem .6rem .85rem; text-align:center; box-shadow:0 2px 12px rgba(0,0,0,.05); border-top:3px solid #e0e8f0; opacity:.6; }
.ap-steps li .n { width:44px; height:44px; border-radius:50%; margin:0 auto .5rem; display:flex; align-items:center; justify-content:center; background:#e5ecf4; color:#6a7f96; font-size:1rem; }
.ap-steps li b { display:block; color:#003f73; font-size:.8rem; line-height:1.3; }
.ap-steps li.done, .ap-steps li.now { opacity:1; }
.ap-steps li.done { border-top-color:#22c55e; }
.ap-steps li.done .n { background:#22c55e; color:#fff; }
.ap-steps li.now { border-top-color:var(--c); box-shadow:0 6px 22px rgba(0,63,115,.15); }
.ap-steps li.now .n { background:var(--c); color:#fff; box-shadow:0 4px 14px rgba(0,0,0,.18); }

/* Figures: as the commitment-fee example on preauction.php */
.ap-rowline { display:flex; justify-content:space-between; gap:1rem; align-items:center; padding:.6rem 0; font-size:.9rem; border-bottom:1px solid #eef3f8; }
.ap-rowline:last-child { border-bottom:none; }
.ap-rowline .lbl { color:#556; }
.ap-rowline .val { font-weight:700; color:#003f73; text-align:right; }
.ap-rowline.fee .val { color:#a30000; }
.ap-rowline.big .val { color:#005BA1; font-size:1.08rem; }
.ap-rowline.save { background:#e6f9ee; border-radius:8px; padding:.65rem .75rem; margin-top:.4rem; border:none; }
.ap-rowline.save .val { color:#155724; }

/* Term choice */
.ap-plan { display:flex; align-items:center; gap:.85rem; border:1.5px solid #dbe4ee; border-radius:12px; padding:.85rem 1.1rem; margin-bottom:.6rem; cursor:pointer; transition:all .2s; }
.ap-plan:hover { border-color:#005BA1; box-shadow:0 4px 14px rgba(0,91,161,.1); }
.ap-plan input { accent-color:#005BA1; width:18px; height:18px; }
.ap-plan .m { font-family:'Barlow Condensed',sans-serif; font-weight:800; color:#003f73; font-size:1.15rem; min-width:95px; }
.ap-plan .t { font-size:.8rem; color:#778; }
.ap-plan .p { margin-left:auto; font-family:'Barlow Condensed',sans-serif; font-size:1.45rem; font-weight:800; color:#1a9e4e; white-space:nowrap; }
.ap-plan .p small { font-family:'Barlow',sans-serif; font-size:.75rem; color:#778; font-weight:600; }
.ap-plan.chosen { border-color:#22c55e; background:#f1fbf5; }
.ap-plan .pop { background:#e8a000; color:#fff; font-size:.6rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; padding:.15rem .5rem; border-radius:50px; }
@media(max-width:480px){ .ap-plan { flex-wrap:wrap; } .ap-plan .p { margin-left:0; width:100%; padding-left:2rem; } }

/* Access pass (as the Buyer Code card on preauction.php) */
.ap-pass { background:#fff; border-radius:20px; overflow:hidden; box-shadow:0 16px 48px rgba(0,40,100,.18); border:1px solid #d0dce8; margin-bottom:1.5rem; }
.ap-pass-head { background:linear-gradient(135deg,#003f73,#005BA1); padding:1.1rem 1.4rem; display:flex; align-items:center; gap:.9rem; }
.ap-pass-icon { width:46px; height:46px; background:rgba(255,255,255,.15); border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid rgba(255,255,255,.25); color:#fff; font-size:1.15rem; flex-shrink:0; }
.ap-pass-head small { display:block; color:rgba(255,255,255,.65); font-size:.63rem; font-weight:700; letter-spacing:.14em; text-transform:uppercase; }
.ap-pass-head strong { color:#fff; font-size:.95rem; }
.ap-pass-ok { margin-left:auto; background:#22c55e; color:#fff; font-size:.6rem; font-weight:900; letter-spacing:.1em; text-transform:uppercase; padding:.25rem .65rem; border-radius:50px; white-space:nowrap; }
.ap-pass-body { padding:1.2rem 1.4rem 1.3rem; }
.ap-pass-code { background:#f4f8ff; border:2px dashed #c5d8f0; border-radius:12px; padding:1rem; text-align:center; margin:.6rem 0 1rem; }
.ap-pass-code small { display:block; font-size:.63rem; text-transform:uppercase; letter-spacing:.16em; color:#667; font-weight:700; margin-bottom:.3rem; }
.ap-pass-code b { font-size:clamp(1.5rem,4vw,2.1rem); font-weight:900; color:#002a5c; letter-spacing:.06em; font-family:'Barlow Condensed',sans-serif; line-height:1; word-break:break-all; }

.ap-notice { display:flex; gap:1rem; align-items:flex-start; background:#fff8e6; border:1.5px solid #f5c842; border-radius:12px; padding:1rem 1.2rem; margin:1rem 0; font-size:.88rem; color:#5b4300; line-height:1.65; }
.ap-notice > i { color:#e8a000; font-size:1.2rem; margin-top:.1rem; }
.ap-veh { display:flex; gap:1rem; align-items:center; }
.ap-veh img { width:120px; height:84px; object-fit:cover; border-radius:10px; background:#e5eaf0; flex-shrink:0; }
.ap-veh strong { color:#003f73; display:block; line-height:1.3; }
.ap-doc { border:1.5px solid #e0e8f0; border-radius:12px; padding:1rem 1.1rem; margin-bottom:.75rem; }
.ap-doc-head { display:flex; justify-content:space-between; gap:.75rem; align-items:center; flex-wrap:wrap; }
.ap-doc-head strong { color:#003f73; }
.ap-log { font-size:.8rem; color:#667; list-style:none; padding:0; margin:0; }
.ap-log li { padding:.4rem 0; border-bottom:1px dashed #e6ebf0; }
.ap-faq details { border:1.5px solid #e0e8f0; border-radius:10px; margin-bottom:.6rem; background:#fff; }
.ap-faq summary { padding:.85rem 1.1rem; font-weight:700; color:#003f73; font-size:.9rem; cursor:pointer; }
.ap-faq p { padding:0 1.1rem 1rem; margin:0; color:#556; font-size:.86rem; line-height:1.7; }
.ap-mini-steps { list-style:none; margin:0; padding:0; counter-reset:s; }
.ap-mini-steps li { position:relative; padding:0 0 .9rem 2.6rem; font-size:.88rem; color:#556; line-height:1.6; counter-increment:s; }
.ap-mini-steps li::before { content:counter(s); position:absolute; left:0; top:-.1rem; width:30px; height:30px; border-radius:50%; background:linear-gradient(135deg,#003f73,#005BA1); color:#fff; font-family:'Barlow Condensed',sans-serif; font-weight:800; display:flex; align-items:center; justify-content:center; font-size:.95rem; }
.ap-mini-steps li strong { color:#003f73; }
.vin-channels { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:1rem; margin:1.25rem 0; }
.vin-channels > div { background:#fff; border-radius:14px; padding:1.25rem 1.25rem 1.25rem 1.4rem; box-shadow:0 2px 16px rgba(0,0,0,.06); border-left:4px solid var(--accent); }
.vin-channels .ic { width:42px; height:42px; background:var(--accent); border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; margin-bottom:.7rem; }
.vin-channels strong { display:block; color:#003f73; margin-bottom:.3rem; }
.vin-channels span { font-size:.85rem; color:#556; line-height:1.6; }
</style>
<?php
/** AHG page hero (as financing.php / preauction.php). */
function finHero(string $eyebrow, string $title, string $sub, string $crumb, array $chips = []): void { ?>
<section class="ap-hero"><div class="ap-container">
  <nav class="ap-crumbs" aria-label="Breadcrumb"><a href="<?= APP_URL ?>/index.php">Home</a><span class="sep">/</span><span class="here"><?= $crumb ?></span></nav>
  <span class="ap-eyebrow"><?= $eyebrow ?></span>
  <h1><?= $title ?></h1>
  <p><?= $sub ?></p>
  <?php if ($chips): ?><div class="ap-chips"><?php foreach ($chips as $c): ?><span><i class="fas fa-check"></i><?= $c ?></span><?php endforeach; ?></div><?php endif; ?>
</div></section>
<?php }
