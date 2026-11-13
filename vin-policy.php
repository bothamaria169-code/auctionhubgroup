<?php
// ============================================================
// AUCTION HUB GROUP — vin-policy.php
// Why we never publish VINs online, the law behind it, and how verified
// buyers get one. Vehicle pages link here ("VIN: On request").
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finance_helper.php';

$pageTitle       = 'VIN Policy — Why We Never Publish VINs Online';
$pageDescription = 'Why Auction Hub Group never publishes vehicle VINs online, the South African law behind it (POPIA, National Road Traffic Act, Second-Hand Goods Act, Cybercrimes Act) and how verified buyers receive a VIN.';
$bodyClass       = 'page-vin-policy';
require_once __DIR__ . '/header.php';
require __DIR__ . '/fin_ui.php';
$lot = !empty($_GET['id']) ? finVehicle((int)$_GET['id']) : null;

finHero('<i class="fas fa-user-shield"></i> Buyer Protection', 'Our VIN Policy',
    'We never publish Vehicle Identification Numbers online. Here is why — and how serious buyers receive the VIN of the vehicle they want.', 'VIN Policy');

$reasons = [
    ['fa-user-shield', '#005BA1', 'Privacy & Security', 'A VIN is a unique 17-character code that identifies one specific vehicle. In the wrong hands it is used for <strong>vehicle cloning</strong> — copying a legitimate VIN onto a stolen vehicle of the same make and model and selling it to an unsuspecting buyer. VIN records can also be traced to current and previous owners. Keeping VINs off public listings reduces the risk of cloning, identity theft and fraud.'],
    ['fa-building-shield', '#8b5cf6', 'Responsible Selling', 'Our vehicles are sold on behalf of banks and financial institutions. Sharing a VIN in a controlled, verified setting stops competitors and scammers from harvesting stock information, and lets our team confirm the vehicle\'s status with the institution first.'],
    ['fa-truck-ramp-box', '#e8a000', 'Stock Not Yet Allocated', 'Bank stock is sometimes listed before the exact unit has been confirmed, allocated or delivered to our yard. Publishing a VIN before the unit is finalised could mislead buyers if details change.'],
    ['fa-user-xmark', '#ef4444', 'Preventing Fraud', 'Open VINs attract enquiries from people who are not genuine buyers — scammers, identity thieves and those trying to misuse vehicle records. Verifying you first protects you, our clients and the institutions we act for.'],
];
?>
<section class="ap-section"><div class="ap-container" style="max-width:1000px">
  <span class="ap-label"><i class="fas fa-circle-question"></i> Why</span>
  <h2 class="ap-title">Why VINs Are Not Shown Online</h2>
  <div class="vin-channels" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
    <?php foreach ($reasons as [$icon, $color, $t, $text]): ?>
      <div style="--accent:<?= $color ?>"><div class="ic"><i class="fas <?= $icon ?>"></i></div><strong><?= e($t) ?></strong><span><?= $text ?></span></div>
    <?php endforeach; ?>
  </div>

  <div class="ap-card" id="request" style="margin-top:2rem">
    <div class="ap-card-head green"><i class="fas fa-circle-check"></i> How Verified Buyers Get the VIN</div>
    <div class="ap-card-body">
      <p class="ap-sub" style="margin:0">A VIN can only be trusted when it comes from us <strong>on site</strong>, or to <strong>verified bidders</strong>, <strong>approved finance clients</strong> and <strong>buyers with a Presold vehicle</strong>. A VIN obtained any other way should not be relied upon.</p>
      <div class="vin-channels">
        <?php foreach (vinChannels() as [$icon, $color, $t, $text]): ?>
          <div style="--accent:<?= $color ?>"><div class="ic"><i class="fas <?= e($icon) ?>"></i></div><strong><?= e($t) ?></strong><span><?= e($text) ?></span></div>
        <?php endforeach; ?>
      </div>
      <div class="ap-row">
        <a class="btn btn-primary" href="<?= APP_URL ?>/login.php?tab=register"><i class="fas fa-user-plus"></i> Register to Bid</a>
        <a class="btn btn-outline" href="<?= APP_URL ?>/finance_apply.php<?= $lot ? '?id=' . (int)$lot['id'] : '' ?>"><i class="fas fa-hand-holding-dollar"></i> Apply for Finance</a>
        <a class="btn btn-outline" href="<?= APP_URL ?>/preauction_request.php<?= $lot ? '?id=' . (int)$lot['id'] : '' ?>"><i class="fas fa-key"></i> Pre-Auction Purchase</a>
        <a class="btn btn-wa" target="_blank" rel="noopener" href="https://wa.me/<?= COMPANY_WHATSAPP_INTL ?>?text=<?= rawurlencode('Hi Auction Hub Group, I would like to view ' . ($lot ? 'the ' . finVehicleTitle($lot) . ' (lot ' . (int)$lot['id'] . ')' : 'a vehicle') . ' and see its VIN on site.') ?>"><i class="fab fa-whatsapp"></i> Book a Visit</a>
      </div>
    </div>
  </div>

  <div class="ap-card">
    <div class="ap-card-head"><i class="fas fa-scale-balanced"></i> The Law Behind This Policy</div>
    <div class="ap-card-body">
      <div class="ap-rowline" style="display:block"><strong style="color:#003f73">Protection of Personal Information Act 4 of 2013 (POPIA)</strong><br><span class="ap-help">Section 19 (security safeguards) requires us to keep personal information secure. Vehicle records can be traced to current and previous owners, so we limit who can see a VIN.</span></div>
      <div class="ap-rowline" style="display:block"><strong style="color:#003f73">National Road Traffic Act 93 of 1996</strong><br><span class="ap-help">Section 68(6) makes it an offence to falsify, alter or remove a vehicle's engine or chassis number, or to possess a vehicle whose numbers have been tampered with. Regulation 56A requires vehicles first registered from 1 December 2015 to carry a VIN plate — which is why the VIN you can trust is the one you check on the vehicle itself.</span></div>
      <div class="ap-rowline" style="display:block"><strong style="color:#003f73">Second-Hand Goods Act 6 of 2009</strong><br><span class="ap-help">Section 24 requires dealers to record each vehicle's VIN, chassis and engine numbers with the buyer's or seller's identity document and keep those records for at least five years. We share VINs with verified buyers in the same controlled way.</span></div>
      <div class="ap-rowline" style="display:block"><strong style="color:#003f73">Cybercrimes Act 19 of 2020</strong><br><span class="ap-help">Section 8 makes it an offence to use data to misrepresent and defraud. We report any attempt to misuse our listings or vehicle records.</span></div>
      <p class="ap-help" style="margin-top:.8rem">A plain-language summary for your information — not legal advice.</p>
    </div>
  </div>

  <div class="ap-notice"><i class="fas fa-magnifying-glass"></i><div><strong>Verify us with confidence.</strong> You are always welcome to run a background check on our company: <?= e(COMPANY_REGISTERED_NAME) ?> t/a <?= e(COMPANY_TRADING_AS) ?>, registration no. <?= e(COMPANY_REG_NO) ?>, SARS no. <?= e(COMPANY_SARS_NO) ?>, <?= e(COMPANY_ADDRESS) ?>. Only ever pay into the account on an official Auction Hub Group invoice.</div></div>
</div></section>
<?php require_once __DIR__ . '/footer.php'; ?>
