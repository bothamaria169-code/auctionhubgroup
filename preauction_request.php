<?php
// ============================================================
// AUCTION HUB GROUP — preauction_request.php
// Pre-auction purchase (Buy Now) request: a vehicle with no bids, the
// commitment the buyer wants to pay up front (30–50% of the price), and
// when they would like to test-drive it at head office. The office then
// approves, suggests another % or declines (Admin → Finance & Pre-Auction).
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';
require_once __DIR__ . '/leads_helper.php';

ahgSchemaReady();
$pageUser = getPageUser();
$lots = finEligibleLots();
[$pMin, $pMax, $pDef] = paRange();
$vehicle = null;
$notEligible = false;
if (($vid = (int)($_GET['id'] ?? $_POST['auction_id'] ?? 0)) > 0) {
    foreach ($lots as $l) if ((int)$l['id'] === $vid) $vehicle = $l;
    $notEligible = !$vehicle;
}
$errors = [];
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim($_POST['website'] ?? '') !== '') { header('Location: preauction_request.php?sent=1'); exit; }
    if (!finCsrfOk()) $errors['form'] = 'Your session expired — please send the form again.';
    $name  = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 150));
    $email = trim(mb_substr((string)($_POST['email'] ?? ''), 0, 190));
    $phone = finCleanPhone((string)($_POST['phone'] ?? ''));
    $percent = (int)($_POST['percent'] ?? 0);
    $date = (string)($_POST['date'] ?? '');
    $time = (string)($_POST['time'] ?? '');
    $alt = null;
    if (($altId = (int)($_POST['alt_id'] ?? 0)) > 0) foreach ($lots as $l) if ((int)$l['id'] === $altId) $alt = $l;

    if (mb_strlen($name) < 3)                       $errors['name']  = 'Please enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Please enter a valid email — your letters and invoice are sent there.';
    if (!finValidPhone($phone))                     $errors['phone'] = 'Please enter a contact number we can call or WhatsApp.';
    if (!$vehicle)                                  $errors['auction_id'] = 'Please choose the vehicle you want to reserve.';
    if ($percent < $pMin || $percent > $pMax)       $errors['percent'] = "Choose a commitment between $pMin% and $pMax% of the price.";
    if ($e = finViewingError($date, $time))         $errors['date'] = $e;
    if (empty($_POST['agree']))                     $errors['agree'] = 'Please confirm you have read how the commitment works.';
    if (!$errors && enquiryRateLimited(6, 900))     $errors['form'] = 'You have sent several requests already — we will be in touch. For anything urgent call ' . COMPANY_PHONE . '.';

    if (!$errors) {
        try {
            $app = finCreate('preauction', ['name' => $name, 'email' => $email, 'phone' => $phone, 'user_id' => $pageUser['sub'] ?? null], $vehicle, 0, (float)$percent, [
                'viewing'  => ['date' => $date, 'time' => $time, 'status' => 'wanted'],
                'mechanic' => !empty($_POST['mechanic']),
                'alt'      => $alt ? ['id' => (int)$alt['id'], 'title' => finVehicleTitle($alt), 'price' => finPrice($alt)] : null,
                'message'  => trim(mb_substr((string)($_POST['message'] ?? ''), 0, 2000)),
            ]);
            finLog($app, "Requested at $percent% — test drive wanted " . finViewingLabel($app['details']['viewing']));
            $ok = finMailClient($app, 'We have your pre-auction request',
                '<p>Thank you for your pre-auction request on the <strong>' . e($app['vehicle']) . '</strong> at ' . formatRand((float)$app['price']) . '.</p>'
                . '<div class="highlight-box"><p><strong>Commitment you asked for:</strong> ' . $percent . '% = ' . formatRand(paCommitment($app)) . '</p>'
                . '<p><strong>Test drive wanted:</strong> ' . e(finViewingLabel($app['details']['viewing'])) . '</p></div>'
                . '<p><strong>What happens next:</strong> we check your request with the financial institution and approve your percentage, suggest one we can approve, or let you know if we cannot. '
                . 'You will then receive your commitment letter to read and confirm — <strong>no invoice is sent before you confirm</strong>.</p>',
                'Track my request');
            finRecordMail($app, 'Request received', $ok);
            finSave($app);
            finMailOffice($app, 'New pre-auction request');
            finFlash('success', 'Your request is in. We will approve it (or suggest a percentage) shortly — you can follow every step on this page, and we have emailed you the link.');
            header('Location: ' . finLink($app));
            exit;
        } catch (Throwable $e) {
            error_log('[AHG preauction_request] ' . $e->getMessage());
            $errors['form'] = 'Sorry, we could not save your request. Please call ' . COMPANY_PHONE . ' or WhatsApp ' . COMPANY_WHATSAPP . '.';
        }
    }
}

$val = fn(string $k, string $d = '') => e((string)($old[$k] ?? $d));
$err = fn(string $k) => isset($errors[$k]) ? '<span class="ap-err-text">' . e($errors[$k]) . '</span>' : '';
$cls = fn(string $k, string $x = '') => trim($x . (isset($errors[$k]) ? ' ap-err' : ''));

$pageTitle       = 'Reserve a Vehicle Before Auction — Pre-Auction Request';
$pageDescription = 'Reserve a bank-stock vehicle before it reaches the auction floor. Choose your commitment (30–50%), book your test drive, and pay the balance only after you have driven it.';
$bodyClass       = 'page-preauction';
require_once __DIR__ . '/header.php';
require __DIR__ . '/fin_ui.php';
?>
<section class="ap-hero">
  <span class="ap-eyebrow"><i class="fas fa-bolt"></i> Pre-auction · Buy Now</span>
  <h1>Reserve it before the hammer falls</h1>
  <p>Pick a vehicle with no bids, choose the commitment you want to pay up front (<?= $pMin ?>–<?= $pMax ?>% of the price) and when you want to test-drive it. The commitment is never an extra cost — it comes off the price.</p>
</section>

<div class="ap-wrap">
  <?= finFlashHtml() ?>
  <?php if (!empty($errors['form'])): ?><div class="ap-flash ap-flash-error"><?= e($errors['form']) ?></div><?php endif; ?>
  <?php if (isset($_GET['sent'])): ?><div class="ap-flash ap-flash-success">Thank you — we will be in touch.</div><?php endif; ?>

  <div class="ap-grid">
    <form class="ap-card" method="post" novalidate>
      <input type="hidden" name="csrf" value="<?= e(finCsrfToken()) ?>">
      <div class="ap-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
      <h2>Your pre-auction request</h2>
      <?php if ($notEligible): ?><div class="ap-flash ap-flash-error">That vehicle has already taken a bid or is reserved — please choose another one.</div><?php endif; ?>
      <div class="ap-form">
        <div class="full <?= $cls('auction_id') ?>"><label for="ap-vehicle">Vehicle</label>
          <?= finVehicleSelect($lots, $vehicle) ?><?= $err('auction_id') ?>
          <span class="ap-help"><?= count($lots) ?> vehicles can be reserved — only vehicles without bids qualify.</span></div>
        <div class="<?= $cls('percent') ?>"><label for="percent">Commitment (% of the price)</label>
          <select class="ap-input" id="percent" name="percent">
            <?php for ($p = $pMin; $p <= $pMax; $p += 5): ?>
              <option value="<?= $p ?>"<?= (int)($old['percent'] ?? $pDef) === $p ? ' selected' : '' ?>><?= $p ?>%</option>
            <?php endfor; ?>
          </select><?= $err('percent') ?>
          <span class="ap-help" id="pa-amount"></span></div>
        <div><label for="alt_id">Alternative vehicle <span style="font-weight:400;color:#889">optional</span></label>
          <?= finVehicleSelect($lots, isset($old['alt_id']) ? ['id' => (int)$old['alt_id']] : null, 'alt_id', 'alt_id', false) ?>
          <span class="ap-help">Switch to it after your test drive if the first one isn't right.</span></div>
        <div class="<?= $cls('date') ?>"><label for="date">Test-drive day</label>
          <input class="ap-input" id="date" type="date" name="date" required min="<?= date('Y-m-d', strtotime('tomorrow')) ?>" max="<?= date('Y-m-d', strtotime('+60 days')) ?>" value="<?= $val('date') ?>"><?= $err('date') ?></div>
        <div><label for="time">Time</label>
          <select class="ap-input" id="time" name="time">
            <?php for ($h = 8; $h <= 15; $h++): $t = sprintf('%02d:00', $h); ?>
              <option<?= ($old['time'] ?? '10:00') === $t ? ' selected' : '' ?>><?= $t ?></option>
            <?php endfor; ?>
          </select><span class="ap-help">Monday to Saturday, at our head office.</span></div>
        <div class="<?= $cls('name') ?>"><label for="name">Full name</label><input class="ap-input" id="name" name="name" autocomplete="name" required value="<?= $val('name', $pageUser['full_name'] ?? '') ?>"><?= $err('name') ?></div>
        <div class="<?= $cls('phone') ?>"><label for="phone">Cellphone</label><input class="ap-input" id="phone" type="tel" name="phone" autocomplete="tel" required value="<?= $val('phone') ?>"><?= $err('phone') ?></div>
        <div class="full <?= $cls('email') ?>"><label for="email">Email</label><input class="ap-input" id="email" type="email" name="email" autocomplete="email" required value="<?= $val('email', $pageUser['email'] ?? '') ?>"><?= $err('email') ?></div>
        <div class="full"><label for="message">Anything else? <span style="font-weight:400;color:#889">optional</span></label><textarea class="ap-input" id="message" name="message" rows="2"><?= $val('message') ?></textarea></div>
        <div class="full">
          <label class="ap-check"><input type="checkbox" name="mechanic" value="1"<?= !empty($old['mechanic']) ? ' checked' : '' ?>><span>I'll bring a mechanic to the test drive</span></label>
          <label class="ap-check <?= $cls('agree') ?>"><input type="checkbox" name="agree" value="1"<?= !empty($old['agree']) ? ' checked' : '' ?>><span>I understand: the office approves my commitment first; I confirm on my commitment letter before any invoice; the vehicle is reserved once the commitment reflects; after the test drive I pay the balance or ask for my commitment back (refunded less R<?= number_format(PA_REFUND_FEE, 2) ?> bank &amp; admin charges).</span></label><?= $err('agree') ?>
        </div>
      </div>
      <button class="ap-btn" type="submit" style="margin-top:1rem"><i class="fas fa-paper-plane"></i> Send my request</button>
      <p class="ap-help">Already sent one? <a href="<?= APP_URL ?>/my_applications.php">Find my applications</a></p>
    </form>

    <aside>
      <div class="ap-card">
        <h3>Six clear steps</h3>
        <ol style="padding-left:1.1rem;margin:0;color:#445;font-size:.92rem;line-height:1.7">
          <li><strong>Request</strong> — vehicle, commitment and test-drive time.</li>
          <li><strong>Approval</strong> — we approve your %, or suggest one we can.</li>
          <li><strong>Confirm</strong> — read your commitment letter and confirm, with your gate-pass details.</li>
          <li><strong>Pay the commitment</strong> — only into the account on your invoice; upload proof.</li>
          <li><strong>Buyer's Code</strong> — the vehicle is reserved in your name; 1h30 test drive. No code, no entry.</li>
          <li><strong>Your decision</strong> — pay the balance, switch to your alternative, or ask for the commitment back.</li>
        </ol>
      </div>
      <div class="ap-card">
        <h3>Prefer to pay it off?</h3>
        <p class="ap-help">Apply for <a href="<?= APP_URL ?>/finance_apply.php<?= $vehicle ? '?id=' . (int)$vehicle['id'] : '' ?>">in-house finance</a> on the same vehicle — no credit checks, from <?= formatRand((float)finSetting('fin_min_deposit', '10500')) ?> deposit.</p>
        <p class="ap-help">We never publish VINs — see <a href="<?= APP_URL ?>/vin-policy.php">our VIN policy</a>. Yours is on your Buyer's Code letter.</p>
      </div>
    </aside>
  </div>
</div>
<script>
(function () {
  var sel = document.getElementById('ap-vehicle'), pct = document.getElementById('percent'), out = document.getElementById('pa-amount');
  var rand = function (n) { return 'R' + Math.round(n).toLocaleString('en-ZA').replace(/ /g, ' '); };
  function update() {
    var o = sel.options[sel.selectedIndex];
    if (!o || !o.value) { out.textContent = ''; return; }
    var price = +o.dataset.price, c = price * (+pct.value) / 100;
    out.textContent = 'Commitment ' + rand(c) + ' of ' + rand(price) + ' — balance ' + rand(price - c) + ' after your test drive.';
  }
  sel.addEventListener('change', update); pct.addEventListener('change', update); update();
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
