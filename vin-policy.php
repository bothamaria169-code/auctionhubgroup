<?php
// ============================================================
// AUCTION HUB GROUP — vin-policy.php
// Why we never publish VINs, the law behind it, and how verified buyers get one.
// Ported from BidCo. Lot pages link here (auction.php → "VIN on request").
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finance_helper.php';

$pageTitle       = 'VIN Policy — Why We Don\'t Publish VINs';
$pageDescription = 'Why Auction Hub Group never publishes vehicle VINs online, the South African law behind it (POPIA, NRTA, Second-Hand Goods Act, Cybercrimes Act), and how verified buyers get a VIN.';
$bodyClass       = 'page-vin-policy';
require_once __DIR__ . '/header.php';
require __DIR__ . '/fin_ui.php';
$lot = !empty($_GET['id']) ? finVehicle((int)$_GET['id']) : null;
?>
<section class="ap-hero">
  <span class="ap-eyebrow"><i class="fas fa-user-shield"></i> Your protection</span>
  <h1>Our VIN policy</h1>
  <p>We never publish Vehicle Identification Numbers online. Here is why — and how you get the VIN of a vehicle you are serious about.</p>
</section>
<div class="ap-wrap" style="max-width:880px">
  <div class="ap-card">
    <h2><i class="fas fa-user-shield"></i> Privacy and security</h2>
    <p>A VIN is a unique 17-character code that identifies a specific vehicle, and in the wrong hands it can be misused. Criminals sometimes engage in
       "vehicle cloning" — copying a legitimate vehicle's VIN onto a stolen car of the same make and model, then selling it to an unsuspecting buyer.
       Publicly listing VINs makes this easier. We also protect the privacy of current and previous owners, whose details can sometimes be traced
       through VIN records. By withholding VINs from public view, we reduce the risk of identity theft, cloning and fraud.</p>

    <h2><i class="fas fa-building-shield"></i> Company policy</h2>
    <p>Many dealers have internal rules against sharing VINs before a customer is verified. This protects both the dealer and the customer: the vehicle is
       discussed in a controlled, verified setting, competitors and scammers cannot harvest stock information, and our team can confirm the vehicle's
       availability and condition before committing to specific details.</p>

    <h2><i class="fas fa-truck-ramp-box"></i> Vehicle not yet allocated</h2>
    <p>Bank and fleet stock is sometimes advertised from a stock list before the exact unit has been confirmed, allocated or delivered to us. Publishing a
       VIN before the unit is finalised could mislead buyers if the details change. Once the specific vehicle is confirmed, we share its VIN with serious buyers.</p>

    <h2><i class="fas fa-user-xmark"></i> Preventing fraudulent activity</h2>
    <p>Sharing VINs openly online attracts fraudulent enquiries from people who are not genuine buyers — scammers, identity thieves and those attempting to
       misuse vehicle records. Verifying you first protects our business, our customers and the integrity of the vehicle records we keep.</p>

    <h2 id="request"><i class="fas fa-circle-check"></i> When we share a VIN</h2>
    <p>A VIN can only be trusted when it is provided <strong>on site</strong>, or to <strong>registered bidders</strong>, <strong>approved finance clients</strong>
       and <strong>buyers with reserved vehicles</strong>. A VIN obtained through any other channel should not be relied upon.</p>
    <div class="vin-channels">
      <?php foreach (vinChannels() as [$icon, $t, $text]): ?>
        <div><i class="fas <?= e($icon) ?>"></i><strong><?= e($t) ?></strong><span><?= e($text) ?></span></div>
      <?php endforeach; ?>
    </div>
    <div class="ap-row">
      <a class="ap-btn ap-btn-sm" href="<?= APP_URL ?>/login.php?tab=register"><i class="fas fa-id-card"></i> Register to bid</a>
      <a class="ap-btn ap-btn-out ap-btn-sm" href="<?= APP_URL ?>/finance_apply.php<?= $lot ? '?id=' . (int)$lot['id'] : '' ?>">Apply for finance</a>
      <a class="ap-btn ap-btn-out ap-btn-sm" href="<?= APP_URL ?>/preauction_request.php<?= $lot ? '?id=' . (int)$lot['id'] : '' ?>">Reserve before auction</a>
      <a class="ap-btn ap-btn-wa ap-btn-sm" target="_blank" rel="noopener" href="https://wa.me/<?= COMPANY_WHATSAPP_INTL ?>?text=<?= rawurlencode('Hi Auction Hub Group, I would like to view ' . ($lot ? 'the ' . finVehicleTitle($lot) . ' (lot ' . (int)$lot['id'] . ')' : 'a vehicle') . ' and see its VIN on site.') ?>"><i class="fab fa-whatsapp"></i> Book a visit</a>
    </div>

    <h2><i class="fas fa-scale-balanced"></i> The law behind this policy</h2>
    <ul style="line-height:1.7">
      <li><strong>Protection of Personal Information Act 4 of 2013 (POPIA)</strong> — section 19 (security safeguards) requires us to keep personal information
          secure and confidential. Vehicle records can be traced to current and previous owners, so we limit who can see a VIN.</li>
      <li><strong>National Road Traffic Act 93 of 1996</strong> — section 68(6) makes it an offence to falsify, alter or remove a vehicle's engine or chassis number,
          or to possess a vehicle whose numbers have been tampered with. Regulation 56A requires vehicles first registered from 1 December 2015 to carry a VIN plate —
          which is why the VIN you can trust is the one you check on the vehicle itself.</li>
      <li><strong>Second-Hand Goods Act 6 of 2009</strong> — section 24 requires dealers to record each vehicle's VIN, chassis and engine numbers with the
          buyer's or seller's identity document, and to keep those records for at least five years. We share a VIN with verified buyers in the same controlled way.</li>
      <li><strong>Cybercrimes Act 19 of 2020</strong> — section 8 makes it an offence to use data to misrepresent and defraud. We report any attempt to misuse our
          listings or vehicle records.</li>
    </ul>
    <p class="ap-help">A plain-language summary for your information — not legal advice.</p>

    <h2><i class="fas fa-magnifying-glass"></i> Verify us with confidence</h2>
    <p>You are always welcome to do a background check on our company — we encourage it: <strong><?= e(COMPANY_REGISTERED_NAME) ?> t/a <?= e(COMPANY_TRADING_AS) ?></strong>,
       registration no. <?= e(COMPANY_REG_NO) ?>, SARS tax no. <?= e(COMPANY_SARS_NO) ?>, <?= e(COMPANY_ADDRESS) ?>. If you would like the VIN of a specific vehicle,
       contact us or visit us in person and our team will gladly help.</p>
  </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
