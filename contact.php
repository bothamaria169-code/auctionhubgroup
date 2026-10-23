<?php
// ============================================================
// AUCTION HUB GROUP — contact.php
// Full contact information, agent WhatsApp lines, and locations
// ============================================================
$pageTitle       = 'Contact Us';
$pageDescription = 'Get in touch with Auction Hub Group. Call us, email us, or visit one of our auction grounds across South Africa.';
$bodyClass       = 'page-contact';
require_once __DIR__ . '/header.php';
?>

<!-- ══════════════════════════════════════════════════════════
     HERO
     ══════════════════════════════════════════════════════════ -->
<section style="background:linear-gradient(135deg,#003f73 0%,#005BA1 60%,#0078d7 100%);padding:5rem 1.5rem 4rem;text-align:center;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.05) 1px,transparent 1px);background-size:28px 28px;pointer-events:none;"></div>
    <div class="container" style="position:relative;z-index:1;">
        <p style="color:#7ec8ff;font-size:.88rem;letter-spacing:.15em;text-transform:uppercase;margin-bottom:.75rem;font-weight:600;">We're Here to Help</p>
        <h1 style="color:#fff;font-size:clamp(2rem,5vw,3.4rem);line-height:1.1;margin-bottom:1.25rem;">Contact Auction Hub Group</h1>
        <p style="color:rgba(255,255,255,.8);font-size:1.05rem;max-width:620px;margin:0 auto;line-height:1.75;">
            Reach our team by phone or email. We're available Monday to Saturday to assist registered buyers and new enquiries.
        </p>
    </div>
</section>


<!-- ══════════════════════════════════════════════════════════
     QUICK CONTACT STRIP
     ══════════════════════════════════════════════════════════ -->
<div style="background:#fff;border-bottom:1px solid #e5eaf0;">
    <div class="container" style="display:flex;flex-wrap:wrap;gap:0;justify-content:center;">

        <a href="tel:<?= str_replace(' ', '', COMPANY_PHONE) ?>"
           style="display:flex;align-items:center;gap:.6rem;padding:1.25rem 2rem;color:#005BA1;text-decoration:none;font-weight:600;font-size:.9rem;border-right:1px solid #e5eaf0;transition:background .15s;"
           onmouseover="this.style.background='#f0f6ff'" onmouseout="this.style.background='transparent'">
            <i class="fas fa-phone" style="font-size:1rem;"></i>
            <?= COMPANY_PHONE ?>
        </a>

        <a href="tel:<?= str_replace(' ', '', COMPANY_PHONE2) ?>"
           style="display:flex;align-items:center;gap:.6rem;padding:1.25rem 2rem;color:#005BA1;text-decoration:none;font-weight:600;font-size:.9rem;border-right:1px solid #e5eaf0;transition:background .15s;"
           onmouseover="this.style.background='#f0f6ff'" onmouseout="this.style.background='transparent'">
            <i class="fas fa-phone" style="font-size:1rem;"></i>
            <?= COMPANY_PHONE2 ?>
        </a>

        <a href="mailto:<?= COMPANY_EMAIL ?>?subject=General%20Enquiry"
           style="display:flex;align-items:center;gap:.6rem;padding:1.25rem 2rem;color:#005BA1;text-decoration:none;font-weight:600;font-size:.9rem;transition:background .15s;"
           onmouseover="this.style.background='#f0f6ff'" onmouseout="this.style.background='transparent'">
            <i class="fas fa-envelope" style="font-size:1rem;"></i>
            <?= COMPANY_EMAIL ?>
        </a>

    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     MAIN CONTACT GRID
     ══════════════════════════════════════════════════════════ -->
<section class="section" style="background:#f8fafc;padding:4rem 1.5rem;">
    <div class="container">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;align-items:start;">

            <!-- LEFT — Phone + Email + Team -->
            <div>

                <!-- Phone Lines -->
                <div style="background:#fff;border-radius:16px;border:1px solid #e5eaf0;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06);margin-bottom:1.5rem;">
                    <div style="background:linear-gradient(135deg,#003f73,#005BA1);padding:1.25rem 1.5rem;display:flex;align-items:center;gap:.75rem;">
                        <i class="fas fa-phone" style="color:#fff;font-size:1.3rem;"></i>
                        <div>
                            <h2 style="color:#fff;font-size:1.1rem;margin:0;font-weight:700;">Call Us</h2>
                            <p style="color:rgba(255,255,255,.85);font-size:.8rem;margin:0;">Mon–Fri 08:00–17:00 · Sat 08:00–13:00</p>
                        </div>
                    </div>
                    <div style="padding:1.25rem 1.5rem;">
                        <a href="tel:<?= str_replace(' ','',COMPANY_PHONE) ?>"
                           style="display:flex;align-items:center;gap:1rem;padding:.9rem 1rem;border-radius:10px;text-decoration:none;border:1px solid #e5eaf0;margin-bottom:.75rem;transition:background .15s;"
                           onmouseover="this.style.background='#f0f6ff'" onmouseout="this.style.background='transparent'">
                            <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#005BA1,#003f73);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas fa-phone" style="color:#fff;"></i>
                            </div>
                            <div>
                                <strong style="color:#003f73;display:block;font-size:.95rem;"><?= COMPANY_PHONE ?></strong>
                                <span style="color:#888;font-size:.82rem;">Main Office Line</span>
                            </div>
                            <i class="fas fa-chevron-right" style="color:#c0cfe0;margin-left:auto;"></i>
                        </a>
                        <a href="tel:<?= str_replace(' ','',COMPANY_PHONE2) ?>"
                           style="display:flex;align-items:center;gap:1rem;padding:.9rem 1rem;border-radius:10px;text-decoration:none;border:1px solid #e5eaf0;transition:background .15s;"
                           onmouseover="this.style.background='#f0f6ff'" onmouseout="this.style.background='transparent'">
                            <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#005BA1,#003f73);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas fa-phone" style="color:#fff;"></i>
                            </div>
                            <div>
                                <strong style="color:#003f73;display:block;font-size:.95rem;"><?= COMPANY_PHONE2 ?></strong>
                                <span style="color:#888;font-size:.82rem;">Secondary Line</span>
                            </div>
                            <i class="fas fa-chevron-right" style="color:#c0cfe0;margin-left:auto;"></i>
                        </a>
                    </div>
                </div>

                <!-- Email Enquiry -->
                <div style="background:#fff;border-radius:16px;border:1px solid #e5eaf0;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06);margin-bottom:1.5rem;">
                    <div style="background:linear-gradient(135deg,#005BA1,#003f73);padding:1.25rem 1.5rem;display:flex;align-items:center;gap:.75rem;">
                        <i class="fas fa-envelope" style="color:#fff;font-size:1.3rem;"></i>
                        <div>
                            <h2 style="color:#fff;font-size:1.1rem;margin:0;font-weight:700;">Email Enquiries</h2>
                            <p style="color:rgba(255,255,255,.85);font-size:.8rem;margin:0;">We respond within 1 business day</p>
                        </div>
                    </div>
                    <div style="padding:1.5rem;">
                        <p style="color:#555;font-size:.9rem;margin-bottom:1.25rem;line-height:1.65;">
                            For general enquiries, registration questions, financing information, or appointment bookings — send us an email and our team will get back to you promptly.
                        </p>
                        <a href="mailto:<?= COMPANY_EMAIL ?>?subject=General%20Enquiry"
                           style="display:inline-flex;align-items:center;gap:.6rem;background:#005BA1;color:#fff;padding:.75rem 1.5rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.9rem;width:100%;justify-content:center;">
                            <i class="fas fa-envelope"></i> <?= COMPANY_EMAIL ?>
                        </a>
                    </div>
                </div>

                <!-- Meet the Team link -->
                <a href="<?= APP_URL ?>/team.php"
                   style="display:flex;align-items:center;gap:1rem;background:#f8fafc;border-radius:14px;border:1px solid #e5eaf0;padding:1.1rem 1.5rem;text-decoration:none;transition:all .2s;"
                   onmouseover="this.style.background='#eef4ff';this.style.borderColor='#c8d8ea'" onmouseout="this.style.background='#f8fafc';this.style.borderColor='#e5eaf0'">
                    <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#001F5C,#005DDB);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-users" style="color:#fff;"></i>
                    </div>
                    <div style="flex:1;">
                        <strong style="color:#003f73;display:block;font-size:.95rem;">Meet Our Team</strong>
                        <span style="color:#888;font-size:.82rem;">Get to know the people behind AHG</span>
                    </div>
                    <i class="fas fa-arrow-right" style="color:#005BA1;"></i>
                </a>

            </div><!-- /left col -->

            <!-- RIGHT — Addresses + Map -->
            <div>

                <!-- Office Address + Map -->
                <div style="background:#fff;border-radius:16px;border:1px solid #e5eaf0;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06);margin-bottom:1.5rem;">
                    <div style="background:linear-gradient(135deg,#003f73,#005BA1);padding:1.25rem 1.5rem;display:flex;align-items:center;gap:.75rem;">
                        <i class="fas fa-location-dot" style="color:#fff;font-size:1.3rem;"></i>
                        <div>
                            <h2 style="color:#fff;font-size:1.1rem;margin:0;font-weight:700;">Head Office</h2>
                            <p style="color:rgba(255,255,255,.85);font-size:.8rem;margin:0;">Centurion, Gauteng</p>
                        </div>
                    </div>
                    <div style="padding:1.25rem 1.5rem;border-bottom:1px solid #f0f4f8;">
                        <address style="font-style:normal;color:#444;font-size:.9rem;line-height:1.75;margin:0;">
                            80 Sterling Rd<br>
                            Kosmosdal, Centurion, 0187
                        </address>
                        <div style="display:flex;gap:.5rem;margin-top:.9rem;flex-wrap:wrap;">
                            <a href="tel:<?= str_replace(' ', '', COMPANY_PHONE) ?>"
                               style="display:inline-flex;align-items:center;gap:.4rem;background:#f0f6ff;color:#005BA1;padding:.5rem 1rem;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600;border:1px solid #c8dcf5;">
                                <i class="fas fa-phone"></i> <?= COMPANY_PHONE ?>
                            </a>
                            <a href="tel:<?= str_replace(' ', '', COMPANY_PHONE2) ?>"
                               style="display:inline-flex;align-items:center;gap:.4rem;background:#f0f6ff;color:#005BA1;padding:.5rem 1rem;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600;border:1px solid #c8dcf5;">
                                <i class="fas fa-phone"></i> <?= COMPANY_PHONE2 ?>
                            </a>
                        </div>
                    </div>
                    <!-- Embedded map -->
                    <div style="height:280px;overflow:hidden;">
                        <iframe
                            src="https://maps.google.com/maps?q=Ferndale+Mews+North+Oak+and+Dover+Road+Ferndale+Randburg&output=embed&z=15"
                            width="100%" height="100%"
                            style="border:0;display:block;"
                            allowfullscreen="" loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"
                            title="Auction Hub Group head office location">
                        </iframe>
                    </div>
                </div>

                <!-- Business Hours -->
                <div style="background:#fff;border-radius:16px;border:1px solid #e5eaf0;padding:1.5rem;box-shadow:0 4px 20px rgba(0,0,0,.06);">
                    <h3 style="color:#003f73;font-size:1rem;margin:0 0 1rem;display:flex;align-items:center;gap:.5rem;">
                        <i class="fas fa-clock" style="color:#005BA1;"></i> Business Hours
                    </h3>
                    <div style="display:grid;gap:.5rem;">
                        <div style="display:flex;justify-content:space-between;font-size:.88rem;padding:.4rem 0;border-bottom:1px solid #f0f4f8;">
                            <span style="color:#555;">Monday – Friday</span>
                            <strong style="color:#003f73;">08:00 – 17:00</strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:.88rem;padding:.4rem 0;border-bottom:1px solid #f0f4f8;">
                            <span style="color:#555;">Saturday</span>
                            <strong style="color:#003f73;">08:00 – 13:00</strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:.88rem;padding:.4rem 0;">
                            <span style="color:#555;">Sunday &amp; Public Holidays</span>
                            <strong style="color:#dc3545;">Closed</strong>
                        </div>
                    </div>
                    <div style="margin-top:1rem;background:#fff8e1;border:1px solid #ffe08a;border-radius:8px;padding:.75rem 1rem;font-size:.82rem;color:#7a5700;display:flex;align-items:flex-start;gap:.5rem;">
                        <i class="fas fa-triangle-exclamation" style="flex-shrink:0;margin-top:.1rem;"></i>
                        <span>Vehicle previews and test drives are <strong>by appointment only</strong> for registered buyers. No walk-ins permitted.</span>
                    </div>
                </div>

            </div><!-- /right col -->

        </div>
    </div>
</section>

<style>
@media (max-width: 768px) {
    .contact-two-col { grid-template-columns: 1fr !important; }
}
</style>
<script>
/* Apply mobile grid fix (inline styles can't use media queries) */
(function () {
    var grid = document.querySelector('section.section [style*="grid-template-columns:1fr 1fr"]');
    function applyLayout() {
        if (!grid) return;
        grid.style.gridTemplateColumns = window.innerWidth < 768 ? '1fr' : '1fr 1fr';
    }
    applyLayout();
    window.addEventListener('resize', applyLayout);
})();
</script>


<!-- ══════════════════════════════════════════════════════════
     AUCTION GROUNDS / LOCATIONS
     ══════════════════════════════════════════════════════════ -->
<section class="section" style="padding:4rem 1.5rem;background:#fff;">
    <div class="container">

        <div class="section-header">
            <h2 class="section-title">Auction Ground Locations</h2>
            <p class="section-subtitle">We operate auction grounds across South Africa — all accessible by appointment for registered buyers</p>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.25rem;margin-top:2rem;">

            <div style="background:#f8fafc;border-radius:14px;padding:1.5rem;border:1px solid #e5eaf0;">
                <div style="width:44px;height:44px;background:linear-gradient(135deg,#005BA1,#003f73);border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                    <i class="fas fa-warehouse" style="color:#fff;font-size:1rem;"></i>
                </div>
                <h4 style="color:#003f73;font-size:.95rem;margin:0 0 .5rem;">Gauteng — Boksburg</h4>
                <address style="font-style:normal;color:#555;font-size:.87rem;line-height:1.7;margin:0;">
                    8 Top Rd, O.R. Tambo<br>
                    Boksburg, 1459
                </address>
            </div>

            <div style="background:#f8fafc;border-radius:14px;padding:1.5rem;border:1px solid #e5eaf0;">
                <div style="width:44px;height:44px;background:linear-gradient(135deg,#005BA1,#003f73);border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                    <i class="fas fa-warehouse" style="color:#fff;font-size:1rem;"></i>
                </div>
                <h4 style="color:#003f73;font-size:.95rem;margin:0 0 .5rem;">KwaZulu-Natal — Durban</h4>
                <address style="font-style:normal;color:#555;font-size:.87rem;line-height:1.7;margin:0;">
                    Quarry Place<br>
                    Off Queen Nandi Drive<br>
                    River Horse Estate, Durban
                </address>
            </div>

            <div style="background:#f8fafc;border-radius:14px;padding:1.5rem;border:1px solid #e5eaf0;">
                <div style="width:44px;height:44px;background:linear-gradient(135deg,#005BA1,#003f73);border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                    <i class="fas fa-warehouse" style="color:#fff;font-size:1rem;"></i>
                </div>
                <h4 style="color:#003f73;font-size:.95rem;margin:0 0 .5rem;">Free State — Bloemfontein</h4>
                <address style="font-style:normal;color:#555;font-size:.87rem;line-height:1.7;margin:0;">
                    Cnr R64 &amp; Valentia Road<br>
                    Waterbron, Bloemfontein
                </address>
            </div>

            <div style="background:#f8fafc;border-radius:14px;padding:1.5rem;border:1px solid #e5eaf0;">
                <div style="width:44px;height:44px;background:linear-gradient(135deg,#005BA1,#003f73);border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                    <i class="fas fa-warehouse" style="color:#fff;font-size:1rem;"></i>
                </div>
                <h4 style="color:#003f73;font-size:.95rem;margin:0 0 .5rem;">Eastern Cape — Gqeberha (PE)</h4>
                <address style="font-style:normal;color:#555;font-size:.87rem;line-height:1.7;margin:0;">
                    ABSA Vehicle &amp; Asset Finance Trade Centre<br>
                    142 Burman Road, Deal Party<br>
                    Gqeberha (PE), Eastern Cape
                </address>
            </div>

        </div>

        <!-- Appointment notice -->
        <div style="margin-top:2rem;background:linear-gradient(135deg,#001F5C,#005DDB);border-radius:14px;padding:1.5rem 2rem;display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:.75rem;">
                <div style="width:40px;height:40px;background:#dc3545;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-calendar-check" style="color:#fff;"></i>
                </div>
                <div>
                    <strong style="color:#fff;display:block;font-size:.92rem;">Appointments Required</strong>
                    <span style="color:rgba(255,255,255,.65);font-size:.82rem;">All visits to our auction grounds must be pre-booked. Call or email us to schedule yours.</span>
                </div>
            </div>
            <a href="mailto:<?= COMPANY_EMAIL ?>?subject=Appointment%20Request%20—%20Vehicle%20Viewing"
               style="display:inline-flex;align-items:center;gap:.5rem;background:#fff;color:#003f73;padding:.75rem 1.5rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.88rem;white-space:nowrap;">
                <i class="fas fa-calendar-check"></i> Book via Email
            </a>
        </div>

    </div>
</section>


<!-- ══════════════════════════════════════════════════════════
     SCAM ALERT CALLOUT
     ══════════════════════════════════════════════════════════ -->
<section style="background:#fff8e1;border-top:3px solid #f5a623;border-bottom:3px solid #f5a623;padding:2rem 1.5rem;">
    <div class="container" style="max-width:860px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:.5rem;background:#f5a62320;border:1px solid #f5a62360;border-radius:50px;padding:.35rem 1.1rem;margin-bottom:1rem;">
            <i class="fas fa-triangle-exclamation" style="color:#e67e00;font-size:.8rem;"></i>
            <span style="color:#e67e00;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;">Scam Alert</span>
        </div>
        <h3 style="color:#7a3e00;font-size:1.2rem;margin-bottom:.75rem;">Protect Yourself from Auction Scams</h3>
        <p style="color:#7a5700;font-size:.9rem;line-height:1.75;margin:0;">
            Auction Hub Group will <strong>never</strong> pressure you into quick decisions or request upfront payments without proper verification.
            We will <strong>never</strong> contact you from personal or unofficial numbers.
            Always verify our contact details on this page before making any payment.
            If you suspect fraud, report it immediately by contacting us directly via the numbers above.
        </p>
    </div>
</section>


<?php require_once __DIR__ . '/footer.php'; ?>
