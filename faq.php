<?php
// ============================================================
// AUCTION HUB GROUP — faq.php
// Frequently Asked Questions
// ============================================================
$pageTitle       = 'FAQ';
$pageDescription = 'Frequently asked questions about Auction Hub Group — registration, bidding, payment, vehicle collection, and more.';
$bodyClass       = 'page-faq';
require_once __DIR__ . '/header.php';

$faqs = [
    'Getting Started' => [
        ['How do I register to bid?', 'Registration is free! Visit <a href="'.APP_URL.'/login.php?tab=register" style="color:#005BA1;">auctionhubgroup.co.za</a> and create your bidder profile. Complete your FICA documentation online before auction day. To become a <strong>Verified Bidder</strong>, pay the refundable registration deposit and upload your proof of payment through your dashboard.'],
        ['Is the registration deposit refundable?', '<strong>Yes, fully refundable.</strong> The deposit is a good-faith security deposit only. If you attend an auction and do not purchase a vehicle, or if no auction takes place, your deposit is refunded in full. If you win a bid, the deposit is deducted from your purchase price.'],
        ['What FICA documents do I need?', 'You will need: a <strong>valid SA ID document</strong> (original or certified copy), <strong>proof of residential address</strong> (not older than 3 months), and for companies: <strong>CK1/CM1</strong> registration, company resolution, and letter of authority.'],
        ['How long does verification take?', 'Once you upload your proof of payment, our team reviews it within <strong>1–2 business days</strong>. You\'ll be notified via email and your dashboard status will update to "Verified Bidder".'],
        ['Can I register and bid on the same day?', 'No. You need to register, pay the deposit, and be verified <em>before</em> auction day. We recommend registering at least <strong>3–5 business days</strong> before the auction you wish to attend.'],
    ],
    'The Auction' => [
        ['Where are auctions held?', 'Auctions are held at our premises and online via webcast. Watch our announcements and listings for specific dates, times, and locations. You can bid in person at any of our branch locations or online.'],
        ['Are these damaged vehicles?', '<strong>No.</strong> Every vehicle auctioned through Auction Hub Group undergoes a professional <strong>DEKRA inspection</strong> prior to auction. You receive a full condition report and are welcome to bring your own mechanic or inspector for additional peace of mind.'],
        ['Why are the prices so low?', 'Vehicles come directly from banks as <strong>liquidated or insolvency assets</strong>, priced at the outstanding balance owed — not at retail value. There are no middlemen and no markups.'],
        ['What are the DEKRA inspection stickers?', '<strong>Green Sticker:</strong> May require specific repairs to pass a roadworthy test — full report provided. <strong>Gold Sticker:</strong> No roadworthy issues identified — will pass a roadworthy test. <strong>Platinum Sticker:</strong> No roadworthy issues and has already passed a roadworthy test — ready to go.'],
        ['Can I bring a mechanic to inspect a vehicle?', 'Absolutely. We encourage all buyers to inspect vehicles prior to auction. You may bring a trusted mechanic or advisor with you during viewing hours. We have nothing to hide.'],
        ['What happens if I bid and change my mind?', 'A successful bid is a legally binding commitment to purchase. <strong>Bids cannot be withdrawn</strong> after acceptance by the auctioneer. Failure to pay forfeits your deposit and may result in a <strong>10% penalty</strong> of the purchase price.'],
        ['Is there a buyer\'s commission?', '<strong>No.</strong> No buyer\'s commission is payable on any vehicle purchased at Auction Hub Group. An admin fee plus VAT applies — see our <a href="'.APP_URL.'/auction-guide.php" style="color:#005BA1;">Auction Guide</a> for a full cost breakdown.'],
    ],
    'Payment & Costs' => [
        ['What does a vehicle cost at auction?', 'Vehicles are sold to the highest bidder. The hammer price plus an <strong>admin fee of R2,600</strong> and <strong>VAT (15%)</strong> is what you pay. Your registration deposit is deducted from the total. See the full cost breakdown example on our <a href="'.APP_URL.'/auction-guide.php" style="color:#005BA1;">Auction Guide</a> page.'],
        ['What payment methods are accepted?', 'We accept <strong>EFT (Electronic Funds Transfer)</strong> and <strong>Credit/Debit Card</strong> for deposits. No cash or cheques. EFT payments must reflect on the day.'],
        ['How quickly must I pay after winning a bid?', 'Full payment must be completed within <strong>24–48 hours</strong> of the auction. Delays may result in the sale being cancelled and your deposit being forfeited.'],
        ['Can I get a refund if I change my mind?', 'On <strong>Pre-Auction purchases</strong>, your funds are 100% refundable before you leave the premises. On <strong>Auction purchases</strong>, no cancellations are accepted once the hammer falls.'],
    ],
    'In-House Finance' => [
        ['What finance options are available?', 'We offer <strong>in-house finance</strong> with NO credit checks, NO ITC checks, and NO bureau inquiries of any kind. Blacklisted clients welcome. Pay a minimum <strong>R10,500 deposit</strong> (increase any amount to lower instalments) and choose a repayment plan of 6, 12, 24, 36, or 48 months. Visit our <a href="'.APP_URL.'/financing.php" style="color:#005BA1;">In-House Finance</a> page for full details.'],
        ['Do I need a good credit score for finance?', '<strong>Absolutely not.</strong> Our in-house finance is designed for everyone — including blacklisted clients, those with low or no credit score, clients under debt review, those previously declined by banks, and first-time buyers.'],
        ['What deposit is required for finance?', 'A minimum deposit of <strong>R10,500</strong> is required. You can increase your deposit to any amount above R10,500 to reduce your monthly instalments. This is not linked to any credit assessment.'],
        ['What documents do I need for finance?', 'You\'ll need: a copy of your <strong>valid SA ID</strong>, <strong>proof of residential address</strong> (not older than 3 months), <strong>3 months\' bank statements</strong>, and a <strong>recent payslip</strong> or proof of income.'],
        ['What are the repayment terms?', 'Choose from <strong>6, 12, 24, 36, or 48 months</strong>. Shorter terms have lower total costs, while longer terms offer lower monthly payments. For example, on a R60,000 vehicle, your monthly instalment ranges from around R1,783 (48 months) to R9,290 (6 months).'],
    ],
    'Pre-Auction Purchases' => [
        ['What is a Pre-Auction Purchase?', 'A Pre-Auction Purchase allows you to <strong>buy a vehicle before it goes to auction</strong> at the bank\'s outstanding balance — not the retail or market value. This means you skip the bidding war entirely and secure the vehicle at a fraction of the cost. Visit our <a href="'.APP_URL.'/preauction.php" style="color:#005BA1;">Pre-Auction Deals</a> page to browse available vehicles.'],
        ['How does the Pre-Auction process work?', 'It\'s simple: <strong>1)</strong> Browse pre-auction vehicles on our website. <strong>2)</strong> Contact us via email to express interest. <strong>3)</strong> Pay a refundable holding deposit to secure the vehicle. <strong>4)</strong> Inspect the vehicle (DEKRA report provided, bring your own mechanic if you wish). <strong>5)</strong> Complete payment and drive away — or request free delivery.'],
        ['What is the price based on?', 'Pre-auction vehicles are priced at the <strong>bank\'s outstanding balance</strong> — the amount still owed on the finance agreement. This is typically well below retail or market value. There are no middlemen and no markups.'],
        ['Can I inspect a Pre-Auction vehicle?', 'Absolutely. Every pre-auction vehicle comes with a professional <strong>DEKRA inspection sticker</strong> (Green, Gold, or Platinum). You also receive a full condition report, and you are welcome to bring your own mechanic or trusted advisor for additional peace of mind.'],
        ['Can I test drive a Pre-Auction vehicle?', 'Yes. We offer a <strong>1.5-hour test drive</strong> on pre-auction vehicles so you can be completely confident in your purchase before committing.'],
        ['Is my money refundable on a Pre-Auction purchase?', '<strong>Yes, 100%.</strong> On pre-auction purchases, your funds are fully refundable before you leave the premises. If you\'re not satisfied after inspecting or test driving the vehicle, you get your money back — no questions asked.'],
        ['Do I get delivery on Pre-Auction vehicles?', 'Yes! We offer <strong>free delivery</strong> to your door anywhere in South Africa on all pre-auction purchases.'],
        ['Can I use in-house finance for a Pre-Auction vehicle?', 'Yes. Our in-house finance (no credit checks, no ITC, blacklisted welcome) is available for pre-auction purchases. Minimum deposit of <strong>R10,500</strong> — pay any higher amount to reduce your monthly instalments.'],
    ],
    'After the Auction' => [
        ['How do I collect my vehicle?', 'Once full payment has been received and cleared, you may collect your vehicle from our premises. Contact us to arrange a collection time. All paperwork is handled by our admin team.'],
        ['Do you offer delivery?', 'Yes! On Pre-Auction purchases, we offer <strong>free delivery</strong> to your door anywhere in South Africa. For auction purchases, delivery options may be available — contact us directly.'],
        ['How long do I have to register the vehicle?', 'You have <strong>21 days</strong> to register the vehicle in your name. Change of ownership notification is handled by Auction Hub Group.'],
        ['What documents will I receive?', 'You will receive the relevant sale documentation. Change of ownership is handled by our admin team through the relevant financial institution.'],
        ['What about storage?', '<strong>Storage charges apply</strong> if the vehicle is not collected timeously after payment. Please arrange collection promptly.'],
    ],
    'Trust & Legitimacy' => [
        ['How do I verify that Auction Hub Group is legitimate?', 'Auction Hub Group holds a <strong>Level 2 BBBEE certificate</strong>, is a member of <strong>SAIA, JCCI, NAA, and IEASA</strong>, and was awarded <strong>Best Auctioneer 2018 &amp; 2019 by Standard Bank</strong>. We were founded in 2016 and are fully regulated under the CPA, FICA, and POPI Act.'],
        ['Are there vehicles for sale outside of auctions?', 'Yes! Our <strong>Pre-Auction Purchase</strong> programme lets you secure vehicles before auction day at the bank\'s outstanding balance — often well below market value. Visit our <a href="'.APP_URL.'/preauction.php" style="color:#005BA1;">Pre-Auction Deals</a> page or contact us.'],
        ['How do I contact Auction Hub Group?', 'You can reach us via:<br><strong>Phone:</strong> <a href="tel:'.str_replace(' ','',COMPANY_PHONE).'" style="color:#005BA1;">'.COMPANY_PHONE.'</a> | <a href="tel:'.str_replace(' ','',COMPANY_PHONE2).'" style="color:#005BA1;">'.COMPANY_PHONE2.'</a><br><strong>Email:</strong> <a href="mailto:'.COMPANY_EMAIL.'" style="color:#005BA1;">'.COMPANY_EMAIL.'</a><br><strong>Address:</strong> 80 Sterling Rd, Kosmosdal, Centurion, 0187'],
        ['How do I stay updated on upcoming auctions?', 'Register on our website for free. Auction announcements are posted on our platform and displayed in our ticker. Follow our website regularly for the latest auction dates and vehicle listings.'],
    ],
];
?>

<section style="background:linear-gradient(135deg,#003f73 0%,#005BA1 60%,#0078d7 100%);padding:5rem 1.5rem 4rem;text-align:center;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.05) 1px,transparent 1px);background-size:28px 28px;pointer-events:none;"></div>
    <div class="container" style="position:relative;z-index:1;">
        <p style="color:#7ec8ff;font-size:.88rem;letter-spacing:.15em;text-transform:uppercase;margin-bottom:.75rem;font-weight:600;">Got Questions?</p>
        <h1 style="color:#fff;font-size:clamp(2.2rem,5vw,3.6rem);line-height:1.1;margin-bottom:1.25rem;">Frequently Asked Questions</h1>
        <p style="color:rgba(255,255,255,.75);font-size:1.1rem;max-width:600px;margin:0 auto 2rem;">
            Everything you need to know about registering, bidding, and buying at Auction Hub Group.
        </p>
        <nav aria-label="Breadcrumb" style="color:rgba(255,255,255,.5);font-size:.87rem;">
            <a href="<?= APP_URL ?>/index.php" style="color:rgba(255,255,255,.6);text-decoration:none;">Home</a>
            <span style="margin:0 .5rem;">/</span>
            <span style="color:#fff;">FAQ</span>
        </nav>
    </div>
</section>

<main style="padding:4rem 1.5rem;background:#f5f7fa;">
<div class="container" style="max-width:900px;margin:0 auto;">

    <!-- SEARCH BOX -->
    <div style="background:#fff;border-radius:14px;padding:1.5rem;margin-bottom:2.5rem;box-shadow:0 2px 20px rgba(0,0,0,.06);display:flex;gap:.75rem;align-items:center;">
        <i class="fas fa-search" style="color:#8ba3be;font-size:1.1rem;flex-shrink:0;"></i>
        <input type="text" id="faqSearch" placeholder="Search questions..."
            style="border:none;outline:none;font-size:1rem;width:100%;color:#333;background:transparent;"
            oninput="filterFaqs(this.value)">
    </div>

    <?php $qi = 0; foreach ($faqs as $category => $questions): ?>
    <div class="faq-category" data-category="<?= e($category) ?>" style="margin-bottom:2.5rem;">
        <h2 style="color:#005BA1;font-size:1rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;margin-bottom:1.25rem;display:flex;align-items:center;gap:.6rem;">
            <span style="display:inline-block;width:24px;height:2px;background:#005BA1;"></span><?= e($category) ?>
        </h2>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
            <?php foreach ($questions as [$q,$a]): $qi++; ?>
            <div class="faq-item" style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.05);overflow:hidden;">
                <button onclick="toggleFaq(this)"
                    style="width:100%;text-align:left;background:none;border:none;padding:1.25rem 1.5rem;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:1rem;">
                    <span style="font-weight:600;color:#003f73;font-size:.95rem;line-height:1.4;"><?= e($q) ?></span>
                    <i class="fas fa-chevron-down" style="color:#005BA1;flex-shrink:0;transition:transform .25s;"></i>
                </button>
                <div class="faq-answer" style="display:none;padding:0 1.5rem 1.25rem;">
                    <div style="border-top:1px solid #eef1f5;padding-top:1rem;color:#555;font-size:.93rem;line-height:1.8;"><?= $a ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- STILL HAVE QUESTIONS -->
    <div style="background:linear-gradient(135deg,#003f73,#005BA1);border-radius:16px;padding:2.5rem;color:#fff;text-align:center;">
        <h2 style="font-size:1.5rem;margin-bottom:.75rem;">Still Have Questions?</h2>
        <p style="color:rgba(255,255,255,.8);margin-bottom:1.75rem;">Our team is ready to help. Reach out via email or phone.</p>
        <a href="mailto:<?= COMPANY_EMAIL ?>"
           style="background:#fff;color:#003f73;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;display:inline-block;margin-right:.75rem;margin-bottom:.5rem;">
            <i class="fas fa-envelope"></i> Email Us
        </a>
        <a href="tel:<?= str_replace(' ','',COMPANY_PHONE) ?>"
           style="background:rgba(255,255,255,.15);color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;border:1px solid rgba(255,255,255,.3);display:inline-block;margin-right:.75rem;margin-bottom:.5rem;">
            <i class="fas fa-phone"></i> <?= COMPANY_PHONE ?>
        </a>
        <a href="<?= APP_URL ?>/contact.php"
           style="background:rgba(255,255,255,.15);color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;border:1px solid rgba(255,255,255,.3);display:inline-block;margin-bottom:.5rem;">
            <i class="fas fa-address-card"></i> Contact Page
        </a>
    </div>

</div>
</main>

<script>
function toggleFaq(btn) {
    const answer = btn.nextElementSibling;
    const icon = btn.querySelector('i');
    const isOpen = answer.style.display === 'block';
    answer.style.display = isOpen ? 'none' : 'block';
    icon.style.transform = isOpen ? '' : 'rotate(180deg)';
    btn.style.background = isOpen ? '' : '#f8fafc';
}

function filterFaqs(query) {
    query = query.toLowerCase().trim();
    document.querySelectorAll('.faq-category').forEach(cat => {
        let categoryVisible = false;
        cat.querySelectorAll('.faq-item').forEach(item => {
            const text = item.textContent.toLowerCase();
            const match = !query || text.includes(query);
            item.style.display = match ? '' : 'none';
            if (match) categoryVisible = true;
        });
        cat.style.display = categoryVisible ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
