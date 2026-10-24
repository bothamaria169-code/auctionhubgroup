<?php
// ============================================================
// AUCTION HUB GROUP — footer.php
// Universal page footer — include at the bottom of every page
// ============================================================
?>

<!-- ══════════════════════════════════════════════════════════
     MAIN FOOTER
     ══════════════════════════════════════════════════════════ -->
<footer class="footer" role="contentinfo">

    <div class="container footer-grid">

        <!-- Column 1: Brand / company info -->
        <div class="footer-col footer-brand">
            <div class="footer-logo">
                <a href="<?= APP_URL ?>/index.php">
                    <img src="<?= APP_URL ?>/logo-round.png"
                         alt="Auction Hub Group"
                         loading="lazy"
                         style="height:95px;width:95px;object-fit:contain;">
                </a>
            </div>
            <p>
                Trusted by banks, respected by buyers.<br>
                Connecting South Africa's best repossessed and auction vehicles with serious buyers since day one.
            </p>

            <div class="footer-contact">
                <a href="mailto:<?= COMPANY_EMAIL ?>" class="footer-contact-item">
                    <i class="fas fa-envelope" aria-hidden="true"></i>
                    <?= COMPANY_EMAIL ?>
                </a>
                <a href="tel:<?= str_replace(' ', '', COMPANY_PHONE) ?>" class="footer-contact-item">
                    <i class="fas fa-phone" aria-hidden="true"></i>
                    <?= COMPANY_PHONE ?>
                </a>
                <a href="tel:<?= str_replace(' ', '', COMPANY_PHONE2) ?>" class="footer-contact-item">
                    <i class="fas fa-phone" aria-hidden="true"></i>
                    <?= COMPANY_PHONE2 ?>
                </a>
                <div class="footer-contact-item">
                    <i class="fas fa-location-dot" aria-hidden="true"></i>
                    <?= COMPANY_ADDRESS ?>
                </div>
                <div class="footer-contact-item">
                    <i class="fas fa-warehouse" aria-hidden="true"></i>
                    Auction Ground: 8 Top Rd, O.R. Tambo, Boksburg, 1459
                </div>
                <div class="footer-contact-item">
                    <i class="fas fa-warehouse" aria-hidden="true"></i>
                    Quarry Place, Off Queen Nandi Drive, River Horse Estate, Durban
                </div>
                <div class="footer-contact-item">
                    <i class="fas fa-warehouse" aria-hidden="true"></i>
                    Cnr R64 &amp; Valentia Road, Waterbron, Bloemfontein
                </div>
                <div class="footer-contact-item">
                    <i class="fas fa-warehouse" aria-hidden="true"></i>
                    ABSA Vehicle &amp; Asset Finance Trade Centre, 142 Burman Road, Deal Party, Gqeberha (PE), Eastern Cape
                </div>
            </div>
        </div>

        <!-- Column 2: Quick links + About pages -->
        <div class="footer-col">
            <h5>Quick Links</h5>
            <div class="footer-links">
                <a href="<?= APP_URL ?>/index.php"                   class="footer-link"><i class="fas fa-chevron-right"></i> Home</a>
                <a href="<?= APP_URL ?>/auctions.php"                 class="footer-link"><i class="fas fa-chevron-right"></i> Browse Auctions</a>
                <a href="<?= APP_URL ?>/index.php#how-it-works"       class="footer-link"><i class="fas fa-chevron-right"></i> How It Works</a>
                <a href="<?= APP_URL ?>/contact.php"                  class="footer-link"><i class="fas fa-chevron-right"></i> Contact Us</a>
                <a href="<?= APP_URL ?>/login.php?tab=register"       class="footer-link"><i class="fas fa-chevron-right"></i> Register Free</a>
                <a href="<?= APP_URL ?>/user_dashboard.php?tab=register" class="footer-link"><i class="fas fa-chevron-right"></i> Pay Registration Fee</a>
                <a href="<?= APP_URL ?>/user_dashboard.php"           class="footer-link"><i class="fas fa-chevron-right"></i> My Dashboard</a>
                <?php if (isAdmin()): ?>
                    <a href="<?= APP_URL ?>/admin_dashboard.php"      class="footer-link"><i class="fas fa-chevron-right"></i> Admin Panel</a>
                <?php endif; ?>
            </div>

            <h5 style="margin-top:1.75rem;">About & Services</h5>
            <div class="footer-links">
                <a href="<?= APP_URL ?>/about.php"         class="footer-link"><i class="fas fa-chevron-right"></i> Company Overview</a>
                <a href="<?= APP_URL ?>/team.php"          class="footer-link"><i class="fas fa-chevron-right"></i> Meet Our Team</a>
                <a href="<?= APP_URL ?>/careers.php"       class="footer-link"><i class="fas fa-chevron-right"></i> Careers</a>
                <a href="<?= APP_URL ?>/events.php"         class="footer-link"><i class="fas fa-chevron-right"></i> Auction Events</a>
                <a href="<?= APP_URL ?>/preauction.php"    class="footer-link"><i class="fas fa-chevron-right"></i> Pre-Auction Deals</a>
                <a href="<?= APP_URL ?>/auction-guide.php" class="footer-link"><i class="fas fa-chevron-right"></i> Auction Guide</a>
                <a href="<?= APP_URL ?>/financing.php"     class="footer-link"><i class="fas fa-chevron-right"></i> Financing Options</a>
                <a href="<?= APP_URL ?>/testimonials.php"  class="footer-link"><i class="fas fa-chevron-right"></i> Testimonials</a>
                <a href="<?= APP_URL ?>/news.php"          class="footer-link"><i class="fas fa-chevron-right"></i> News &amp; Reviews</a>
                <a href="<?= APP_URL ?>/faq.php"           class="footer-link"><i class="fas fa-chevron-right"></i> FAQ</a>
            </div>
        </div>

        <!-- Column 3: Contact + Links -->
        <div class="footer-col">
            <h5>Get In Touch</h5>
            <div class="footer-whatsapp-links">
                <a href="mailto:<?= COMPANY_EMAIL ?>?subject=General%20Enquiry" class="footer-wa-link" style="background:rgba(0,91,161,.3);border-color:rgba(0,91,161,.5);">
                    <i class="fas fa-envelope"></i> <?= COMPANY_EMAIL ?>
                </a>
                <a href="tel:<?= str_replace(' ','',COMPANY_PHONE) ?>" class="footer-wa-link" style="background:rgba(0,91,161,.15);border-color:rgba(0,91,161,.3);">
                    <i class="fas fa-phone"></i> <?= COMPANY_PHONE ?>
                </a>
                <a href="tel:<?= str_replace(' ','',COMPANY_PHONE2) ?>" class="footer-wa-link" style="background:rgba(0,91,161,.15);border-color:rgba(0,91,161,.3);">
                    <i class="fas fa-phone"></i> <?= COMPANY_PHONE2 ?>
                </a>
                <a href="<?= APP_URL ?>/contact.php" class="footer-wa-link" style="background:rgba(0,91,161,.15);border-color:rgba(0,91,161,.3);">
                    <i class="fas fa-address-card"></i> Contact Page
                </a>
                <a href="<?= APP_URL ?>/team.php" class="footer-wa-link" style="background:rgba(0,91,161,.15);border-color:rgba(0,91,161,.3);">
                    <i class="fas fa-users"></i> Meet Our Team
                </a>
            </div>

            <!-- Registration Info -->
            <h5 style="margin-top:1.5rem">Bidder Registration</h5>
            <div class="footer-contact">
                <div class="footer-contact-item"><i class="fas fa-money-bill"></i> Registration Deposit: <strong><?= REG_FEE_LABEL ?></strong></div>
                <div class="footer-contact-item"><i class="fas fa-envelope"></i> <a href="user_dashboard.php?tab=register" style="color:rgba(255,255,255,.7)">Request banking details from your dashboard</a></div>
            </div>
        </div>

    </div><!-- /.footer-grid -->

    <!-- Scam Alert Banner -->
    <div class="footer-scam-alert">
        <div class="container">
            <h5 class="scam-alert-title"><i class="fas fa-triangle-exclamation"></i> Scam Alert — Protect Yourself</h5>
            <p class="scam-alert-text">
                Auction Hub Group warns of escalating auction scams. Report suspicious activity immediately.
                We will <strong>never</strong> pressure you into quick decisions or request upfront payments without verification.
            </p>
            <div class="scam-alert-images">
                <img src="<?= APP_URL ?>/assets/images/scamalert.png"
                     alt="AHG Scam Alert — Protect Yourself"
                     class="scam-alert-img" loading="lazy"
                     onclick="openScamAlert(this)">
                <img src="<?= APP_URL ?>/assets/images/scamalert.png"
                     alt="AHG Scam Alert — Protect Yourself"
                     class="scam-alert-img" loading="lazy"
                     onclick="openScamAlert(this)">
                <img src="<?= APP_URL ?>/assets/images/scamalert.png"
                     alt="AHG Scam Alert — Protect Yourself"
                     class="scam-alert-img" loading="lazy"
                     onclick="openScamAlert(this)">
            </div>
        </div>
    </div>

    <!-- Scam Alert Lightbox -->
    <div class="scam-alert-overlay" id="scamAlertOverlay" hidden>
        <button class="scam-alert-close" id="scamAlertClose" aria-label="Close">&times;</button>
        <button class="scam-alert-nav scam-alert-prev" id="scamAlertPrev" aria-label="Previous"><i class="fas fa-chevron-left"></i></button>
        <img src="" alt="" class="scam-alert-full-img" id="scamAlertImg">
        <button class="scam-alert-nav scam-alert-next" id="scamAlertNext" aria-label="Next"><i class="fas fa-chevron-right"></i></button>
        <div class="scam-alert-counter" id="scamAlertCounter"></div>
    </div>

    <!-- Registered Buyers Notice -->
    <div style="background:linear-gradient(135deg,#1a3a5c,#003f73);border-top:2px solid #e8a000;">
        <div class="container" style="padding:.85rem 1rem;display:flex;align-items:center;justify-content:center;gap:.75rem;flex-wrap:wrap;text-align:center;">
            <i class="fas fa-triangle-exclamation" style="color:#e8a000;font-size:1rem;"></i>
            <p style="color:rgba(255,255,255,.9);font-size:.82rem;margin:0;font-weight:600;letter-spacing:.02em;">
                Registered Buyers Only &mdash; Vehicle previews and test drives are strictly by appointment for registered buyers only. No walk-ins permitted.
            </p>
        </div>
    </div>

    <!-- Copyright bar -->
    <div class="footer-bottom">
        <div class="container" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
            <p class="footer-copyright">
                &copy; <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved. Regulated under the CPA, FICA and POPI Act. Level 2 BBBEE Accredited.
            </p>
            <p class="footer-copyright">
                All vehicles are sold voetstoots (as-is). Bidders are encouraged to inspect vehicles before bidding.
            </p>
        </div>
    </div>

</footer><!-- /.footer -->


<!-- ══════════════════════════════════════════════════════════
     BOTPRESS WEBCHAT — floats bottom-right (default)
     ══════════════════════════════════════════════════════════ -->
<script src="https://cdn.botpress.cloud/webchat/v3.6/inject.js"></script>
<script src="https://files.bpcontent.cloud/2026/04/30/13/20260430133011-ZFX9DO2T.js" defer></script>

<!-- ==========================================================
     FLOATING WHATSAPP BUTTON
     Primary action is WhatsApp; email + landlines stay available
     inside the popup. Every channel click reports a Google Ads
     conversion so paid traffic that phones or messages instead of
     filling in the signup form still gets counted.
     ========================================================== -->
<?php
// Pre-filled WhatsApp message, tailored to the page the visitor is on.
$waMessage = match ($currentPage) {
    'auction.php', 'auctions.php' => "Hi Auction Hub Group, I'm interested in a vehicle I saw on your auctions page. Please send me more details.",
    'preauction.php'              => "Hi Auction Hub Group, I'd like more information on your pre-auction vehicle deals.",
    'register-to-bid.php',
    'login.php'                   => "Hi Auction Hub Group, I'd like help registering as a bidder.",
    'financing.php'               => "Hi Auction Hub Group, I'd like to know more about vehicle finance options.",
    default                       => "Hi Auction Hub Group, I'd like to know more about your vehicle auctions.",
};
$waHref         = waLink($waMessage);
$waConvSendTo   = gadsSendTo('GADS_LABEL_WHATSAPP');
$callConvSendTo = gadsSendTo('GADS_LABEL_CALL');
?>
<?php include __DIR__ . '/live_bids_widget.php'; // bottom-centre Live Bids pill + panel ?>

<div class="whatsapp-float" id="waFloatWrap">
    <div class="wa-agent-popup" id="waAgentPopup">
        <div class="wa-agent-header">
            <i class="fab fa-whatsapp"></i> Chat to a consultant
            <button class="wa-agent-close" onclick="toggleWaPopup()" aria-label="Close">&times;</button>
        </div>

        <!-- WhatsApp - primary channel -->
        <a href="<?= e($waHref) ?>" target="_blank" rel="noopener"
           class="wa-agent-item wa-agent-item-primary"
           data-ahg-contact="whatsapp"
           style="border-bottom:1px solid #e5eaf0;">
            <div class="wa-agent-avatar" style="background:linear-gradient(135deg,#25d366,#128c7e);">
                <i class="fab fa-whatsapp" style="font-size:1.05rem;"></i>
            </div>
            <div class="wa-agent-info">
                <strong>WhatsApp Us</strong>
                <span><?= e(waDisplay()) ?></span>
            </div>
            <i class="fab fa-whatsapp wa-agent-icon" style="color:#25d366;"></i>
        </a>

        <a href="mailto:<?= COMPANY_EMAIL ?>?subject=General%20Enquiry" class="wa-agent-item"
           data-ahg-contact="email" style="border-bottom:1px solid #e5eaf0;">
            <div class="wa-agent-avatar" style="background:linear-gradient(135deg,#005BA1,#003f73);">
                <i class="fas fa-envelope" style="font-size:.9rem;"></i>
            </div>
            <div class="wa-agent-info">
                <strong>Email Us</strong>
                <span><?= COMPANY_EMAIL ?></span>
            </div>
            <i class="fas fa-envelope wa-agent-icon" style="color:#005BA1;"></i>
        </a>

        <a href="tel:<?= str_replace(' ','',COMPANY_PHONE) ?>" class="wa-agent-item"
           data-ahg-contact="call" style="border-bottom:1px solid #e5eaf0;">
            <div class="wa-agent-avatar" style="background:linear-gradient(135deg,#003f73,#001F5C);">
                <i class="fas fa-phone" style="font-size:.9rem;"></i>
            </div>
            <div class="wa-agent-info">
                <strong>Call Us</strong>
                <span><?= COMPANY_PHONE ?></span>
            </div>
            <i class="fas fa-phone wa-agent-icon" style="color:#005BA1;"></i>
        </a>

        <a href="tel:<?= str_replace(' ','',COMPANY_PHONE2) ?>" class="wa-agent-item"
           data-ahg-contact="call">
            <div class="wa-agent-avatar" style="background:linear-gradient(135deg,#003f73,#001F5C);">
                <i class="fas fa-phone" style="font-size:.9rem;"></i>
            </div>
            <div class="wa-agent-info">
                <strong>Call Us</strong>
                <span><?= COMPANY_PHONE2 ?></span>
            </div>
            <i class="fas fa-phone wa-agent-icon" style="color:#005BA1;"></i>
        </a>
    </div>

    <div class="wa-float-row">
        <button class="wa-float-main" id="waFloatBtn" onclick="toggleWaPopup()"
                aria-label="Chat to us on WhatsApp" aria-expanded="false" aria-controls="waAgentPopup">
            <i class="fab fa-whatsapp" aria-hidden="true" id="waFloatIcon"></i>
        </button>
        <span class="wa-float-label" id="waFloatLabel" aria-hidden="true">Chat on WhatsApp</span>
    </div>
</div>
<script>
function toggleWaPopup(){
    const popup=document.getElementById('waAgentPopup');
    const icon=document.getElementById('waFloatIcon');
    const btn=document.getElementById('waFloatBtn');
    const label=document.getElementById('waFloatLabel');
    const open=popup.classList.toggle('wa-popup-open');
    icon.className=open?'fa-solid fa-xmark':'fab fa-whatsapp';
    btn.setAttribute('aria-expanded',open?'true':'false');
    if(label) label.hidden=open;
}
document.addEventListener('click',function(e){
    const wrap=document.getElementById('waFloatWrap');
    const popup=document.getElementById('waAgentPopup');
    if(popup.classList.contains('wa-popup-open') && !wrap.contains(e.target)){
        popup.classList.remove('wa-popup-open');
        document.getElementById('waFloatIcon').className='fab fa-whatsapp';
        document.getElementById('waFloatBtn').setAttribute('aria-expanded','false');
        const label=document.getElementById('waFloatLabel');
        if(label) label.hidden=false;
    }
});
document.addEventListener('keydown',function(e){
    if(e.key!=='Escape') return;
    const popup=document.getElementById('waAgentPopup');
    if(popup && popup.classList.contains('wa-popup-open')) toggleWaPopup();
});

/* Contact-click conversion reporting.
   Any element carrying data-ahg-contact="whatsapp|call|email" anywhere on the
   site reports to GA4 and, where a label is configured, to Google Ads.
   Delegated, so CTAs added to future pages are covered automatically. */
(function(){
    var sendTo = {
        whatsapp: <?= $waConvSendTo   ? json_encode($waConvSendTo)   : 'null' ?>,
        call:     <?= $callConvSendTo ? json_encode($callConvSendTo) : 'null' ?>
    };
    document.addEventListener('click', function(e){
        var el = e.target.closest ? e.target.closest('[data-ahg-contact]') : null;
        if (!el) return;
        var channel = el.getAttribute('data-ahg-contact');

        // GTM path — fires regardless of whether direct gtag is configured.
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
            event: 'ahg_contact_click',
            contact_channel: channel
        });

        if (typeof gtag !== 'function') return;
        gtag('event', 'contact_click', {
            contact_channel: channel,
            page_location: window.location.href
        });
        if (sendTo[channel]) {
            gtag('event', 'conversion', { send_to: sendTo[channel] });
        }
    }, true);
})();
</script>


<!-- ══════════════════════════════════════════════════════════
     SCROLL-TO-TOP BUTTON
     ══════════════════════════════════════════════════════════ -->
<button class="scroll-top-btn" id="scrollTopBtn"
        aria-label="Scroll back to top"
        title="Back to top"
        style="display:none">
    <i class="fas fa-chevron-up" aria-hidden="true"></i>
</button>


<!-- ══════════════════════════════════════════════════════════
     TOAST CONTAINER
     ══════════════════════════════════════════════════════════ -->
<div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>


<!-- ══════════════════════════════════════════════════════════
     CONFIRM MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="confirmModal" aria-modal="true" role="dialog" aria-hidden="true">
    <div class="modal-box modal-box-sm">
        <div class="modal-header">
            <h3 class="modal-title" id="confirmModalTitle">Confirm Action</h3>
        </div>
        <div class="modal-body">
            <p id="confirmModalMessage">Are you sure you want to proceed?</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" id="confirmModalCancel">Cancel</button>
            <button class="btn btn-danger"  id="confirmModalConfirm">Confirm</button>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     GLOBAL CONFIG (for app.js)
     ══════════════════════════════════════════════════════════ -->
<script>
    window.PVA_CONFIG = {
        appUrl:        '<?= APP_URL ?>',
        currentPage:   '<?= e($currentPage) ?>',
        isLoggedIn:    <?= $currentUser ? 'true' : 'false' ?>,
        isAdmin:       <?= ($currentUser && $currentUser['role'] === 'admin') ? 'true' : 'false' ?>,
        userId:        <?= $currentUser ? (int)$currentUser['sub'] : 'null' ?>,
        bidderStatus:  '<?= $currentUser ? e($currentUser['bidder_status']) : '' ?>',
        bidderNumber:  '<?= $currentUser && $currentUser['bidder_number'] ? e($currentUser['bidder_number']) : '' ?>',
        userName:      '<?= $currentUser ? e($currentUser['full_name']) : '' ?>',
        regFee:        '<?= REG_FEE_LABEL ?>',
        regFeeLabel:   '<?= REG_FEE_LABEL ?>',
    };
</script>

<script>
// Scam Alert Lightbox
(function(){
    const imgs = document.querySelectorAll('.scam-alert-img');
    const overlay = document.getElementById('scamAlertOverlay');
    const fullImg = document.getElementById('scamAlertImg');
    const counter = document.getElementById('scamAlertCounter');
    const srcs = Array.from(imgs).map(i => i.src);
    let idx = 0;

    window.openScamAlert = function(el) {
        idx = srcs.indexOf(el.src);
        if (idx < 0) idx = 0;
        show();
    };
    function show() {
        fullImg.src = srcs[idx];
        fullImg.alt = imgs[idx]?.alt || '';
        counter.textContent = (idx+1) + ' / ' + srcs.length;
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    function hide() {
        overlay.hidden = true;
        document.body.style.overflow = '';
    }
    document.getElementById('scamAlertClose')?.addEventListener('click', hide);
    overlay?.addEventListener('click', function(e){ if(e.target===overlay) hide(); });
    document.getElementById('scamAlertPrev')?.addEventListener('click', function(){
        idx = (idx-1+srcs.length)%srcs.length; show();
    });
    document.getElementById('scamAlertNext')?.addEventListener('click', function(){
        idx = (idx+1)%srcs.length; show();
    });
    document.addEventListener('keydown', function(e){
        if(overlay && !overlay.hidden){
            if(e.key==='Escape') hide();
            if(e.key==='ArrowLeft') document.getElementById('scamAlertPrev')?.click();
            if(e.key==='ArrowRight') document.getElementById('scamAlertNext')?.click();
        }
    });
})();
</script>
<script src="<?= APP_URL ?>/app.js" defer></script>

<?php if (isset($extraJs)): ?>
    <?= $extraJs ?>
<?php endif; ?>

<?php
// Only show newsletter popup on public pages (not admin/login/dashboard)
$noPopupPages = ['login.php','admin_dashboard.php','user_dashboard.php','logout.php','register.php'];
$showNewsletterPopup = !in_array($currentPage ?? '', $noPopupPages);
?>
<?php if ($showNewsletterPopup): ?>
<!-- ── Newsletter First-Visit Popup ──────────────────────────── -->
<div id="nlPopupOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,15,40,.65);z-index:99999;align-items:center;justify-content:center;padding:1rem;backdrop-filter:blur(3px);">
  <div id="nlPopupBox" style="background:#fff;border-radius:24px;max-width:520px;width:100%;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.35);position:relative;animation:nlSlideIn .4s ease;">
    <button onclick="dismissNlPopup(true)" aria-label="Close" style="position:absolute;top:.9rem;right:.9rem;z-index:2;background:rgba(255,255,255,.9);border:none;border-radius:50%;width:32px;height:32px;font-size:1.1rem;cursor:pointer;color:#667;display:flex;align-items:center;justify-content:center;">&times;</button>
    <!-- Hero band -->
    <div style="background:linear-gradient(135deg,#001F5C 0%,#0050c8 60%,#005DDB 100%);padding:2.25rem 2rem 1.75rem;text-align:center;position:relative;overflow:hidden;">
      <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.07) 1px,transparent 1px);background-size:20px 20px;pointer-events:none;"></div>
      <div style="position:relative;z-index:1;">
        <span style="display:inline-flex;align-items:center;gap:.45rem;background:rgba(255,213,79,.18);border:1px solid rgba(255,213,79,.4);border-radius:50px;padding:.35rem 1rem;font-size:.72rem;font-weight:700;color:#ffd54f;letter-spacing:.1em;text-transform:uppercase;margin-bottom:.9rem;">
          <i class="fas fa-envelope-open-text"></i> Stay in the Loop
        </span>
        <h3 style="color:#fff;font-size:1.45rem;font-weight:800;margin:0 0 .5rem;line-height:1.25;">Get the Best Auction Deals<br>Delivered to Your Inbox</h3>
        <p style="color:rgba(255,255,255,.75);font-size:.88rem;margin:0;line-height:1.6;">New vehicle listings, auction dates, exclusive pre-auction previews and car news — weekly, no spam.</p>
      </div>
    </div>
    <!-- Form -->
    <div style="padding:1.75rem 2rem 2rem;">
      <div id="nlPopupMsg" style="display:none;padding:.65rem 1rem;border-radius:10px;font-size:.88rem;font-weight:600;margin-bottom:1rem;text-align:center;"></div>
      <form id="nlPopupForm" onsubmit="submitNlPopup(event)" novalidate>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
          <input type="text" id="nlPopupName" placeholder="Your name (optional)"
                 style="padding:.8rem 1.1rem;border:1.5px solid #dde3ec;border-radius:12px;font-size:.95rem;color:#1a2a40;outline:none;transition:border-color .2s;"
                 onfocus="this.style.borderColor='#005DDB'" onblur="this.style.borderColor='#dde3ec'">
          <input type="email" id="nlPopupEmail" required placeholder="Your email address *"
                 style="padding:.8rem 1.1rem;border:1.5px solid #dde3ec;border-radius:12px;font-size:.95rem;color:#1a2a40;outline:none;transition:border-color .2s;"
                 onfocus="this.style.borderColor='#005DDB'" onblur="this.style.borderColor='#dde3ec'">
          <button type="submit" id="nlPopupBtn"
                  style="padding:.9rem 1.5rem;background:linear-gradient(135deg,#001F5C,#005DDB);color:#fff;border:none;border-radius:12px;font-size:1rem;font-weight:700;cursor:pointer;transition:opacity .2s;display:flex;align-items:center;justify-content:center;gap:.5rem;"
                  onmouseover="this.style.opacity='.9'" onmouseout="this.style.opacity='1'">
            <i class="fas fa-paper-plane"></i> Subscribe — It's Free
          </button>
        </div>
      </form>
      <p style="font-size:.73rem;color:#aaa;text-align:center;margin-top:.85rem;">
        <i class="fas fa-shield-alt" style="color:#005DDB;"></i> No spam, ever. Unsubscribe with one click anytime.
      </p>
      <button onclick="dismissNlPopup(true)" style="display:block;width:100%;background:none;border:none;color:#bbb;font-size:.8rem;cursor:pointer;margin-top:.35rem;padding:.3rem;">
        No thanks, I'll miss out
      </button>
    </div>
  </div>
</div>
<style>
@keyframes nlSlideIn{from{opacity:0;transform:translateY(30px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
</style>
<script>
(function(){
  const KEY  = 'ahg_nl_popup';
  const DELAY = 6000; // 6 seconds

  function showPopup(){
    const el = document.getElementById('nlPopupOverlay');
    if(el){ el.style.display='flex'; }
  }
  function dismissNlPopup(permanent){
    document.getElementById('nlPopupOverlay').style.display='none';
    if(permanent) localStorage.setItem(KEY, '1');
  }
  window.dismissNlPopup = dismissNlPopup;

  // Don't show if already dismissed or subscribed
  if(localStorage.getItem(KEY)) return;
  // Don't show if user is logged in
  if(window.AHG && window.AHG.isLoggedIn) return;
  setTimeout(showPopup, DELAY);

  // Close on overlay click (outside box)
  document.getElementById('nlPopupOverlay').addEventListener('click', function(e){
    if(e.target === this) dismissNlPopup(false);
  });

  window.submitNlPopup = async function(e){
    e.preventDefault();
    const email = document.getElementById('nlPopupEmail').value.trim();
    const name  = document.getElementById('nlPopupName').value.trim();
    const msg   = document.getElementById('nlPopupMsg');
    const btn   = document.getElementById('nlPopupBtn');
    if(!email){ msg.style.display='block'; msg.style.background='rgba(255,107,107,.12)'; msg.style.color='#c62828'; msg.textContent='Please enter your email address.'; return; }
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Subscribing…';
    try {
      const fd = new FormData();
      fd.append('action','newsletter_subscribe');
      fd.append('email', email);
      if(name) fd.append('name', name);
      const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:fd});
      const d = await r.json();
      msg.style.display='block';
      if(d.success){
        msg.style.background='rgba(110,255,158,.15)'; msg.style.color='#1b5e20';
        msg.textContent='You\'re subscribed! Welcome to the AHG community.';
        localStorage.setItem(KEY,'1');
        setTimeout(()=>{ document.getElementById('nlPopupOverlay').style.display='none'; }, 2200);
      } else {
        msg.style.background='rgba(255,107,107,.12)'; msg.style.color='#c62828';
        msg.textContent = d.message || 'Something went wrong. Please try again.';
        btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane"></i> Subscribe — It\'s Free';
      }
    } catch {
      msg.style.background='rgba(255,107,107,.12)'; msg.style.color='#c62828';
      msg.textContent='Network error. Please try again.';
      btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane"></i> Subscribe — It\'s Free';
    }
  };
})();
</script>
<?php endif; ?>

</body>
</html>
