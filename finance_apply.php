<?php
// ============================================================
// AUCTION HUB GROUP — finance_apply.php
// Apply for in-house finance: choose a vehicle with no bids and the start-up
// deposit — the quotation (every term) is ready at once and emailed.
// The client then continues on my_application.php (their private link).
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';
require_once __DIR__ . '/leads_helper.php';

ahgSchemaReady();
$pageUser = getPageUser();
$lots = finEligibleLots();
$vehicle = null;
$notEligible = false;
if (($vid = (int)($_GET['id'] ?? $_POST['auction_id'] ?? 0)) > 0) {
    foreach ($lots as $l) if ((int)$l['id'] === $vid) $vehicle = $l;
    $notEligible = !$vehicle;
}
$errors = [];
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim($_POST['website'] ?? '') !== '') { header('Location: finance_apply.php?sent=1'); exit; }   // honeypot
    if (!finCsrfOk()) $errors['form'] = 'Your session expired — please send the form again.';
    $name  = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 150));
    $email = trim(mb_substr((string)($_POST['email'] ?? ''), 0, 190));
    $phone = finCleanPhone((string)($_POST['phone'] ?? ''));
    $deposit = finMoneyInput((string)($_POST['deposit'] ?? ''));
    if (mb_strlen($name) < 3)                          $errors['name']  = 'Please enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))    $errors['email'] = 'Please enter a valid email — your quotation is sent there.';
    if (!finValidPhone($phone))                        $errors['phone'] = 'Please enter a contact number we can call or WhatsApp.';
    if (!$vehicle)                                     $errors['auction_id'] = 'Please choose the vehicle you would like to finance.';
    elseif ($why = finDepositError($vehicle, $deposit)) $errors['deposit'] = $why;
    if (empty($_POST['agree']))                        $errors['agree'] = 'Please confirm you understand the quotation is an estimate until you sign.';
    if (!$errors && enquiryRateLimited(6, 900)) $errors['form'] = 'You have sent several applications already — we will be in touch. For anything urgent call ' . COMPANY_PHONE . '.';

    if (!$errors) {
        try {
            $app = finCreate('finance', ['name' => $name, 'email' => $email, 'phone' => $phone, 'user_id' => $pageUser['sub'] ?? null], $vehicle, $deposit, null, [
                'message' => trim(mb_substr((string)($_POST['message'] ?? ''), 0, 2000)),
                'employment' => trim(mb_substr((string)($_POST['employment'] ?? ''), 0, 60)),
                'income' => finMoneyInput((string)($_POST['income'] ?? '')),
                'whatsapp_ok' => !empty($_POST['whatsapp_ok']),
            ]);
            finIssueQuote($app, $deposit);
            finLog($app, 'Applied — quotation ' . $app['details']['quote']['number'] . ' issued');
            finSendQuote($app);
            finSave($app);
            finMailOffice($app, 'New in-house finance application');
            finFlash('success', 'Your quotation is ready — we have also emailed it to <strong>' . e($email) . '</strong>. Choose your monthly plan below.');
            header('Location: ' . finLink($app, 'plans'));
            exit;
        } catch (Throwable $e) {
            error_log('[AHG finance_apply] ' . $e->getMessage());
            $errors['form'] = 'Sorry, we could not save your application. Please call ' . COMPANY_PHONE . ' or WhatsApp ' . COMPANY_WHATSAPP . '.';
        }
    }
}

$val = fn(string $k, string $d = '') => e((string)($old[$k] ?? $d));
$err = fn(string $k) => isset($errors[$k]) ? '<span class="ap-err-text">' . e($errors[$k]) . '</span>' : '';
$cls = fn(string $k, string $x = '') => trim($x . (isset($errors[$k]) ? ' ap-err' : ''));

$pageTitle       = 'Apply for In-House Finance — Instant Quotation';
$pageDescription = 'Apply for Auction Hub Group in-house vehicle finance: no credit checks, no ITC. Choose a vehicle, enter your deposit and get your quotation instantly.';
$bodyClass       = 'page-financing';
require_once __DIR__ . '/header.php';
require __DIR__ . '/fin_ui.php';
$minStd = (float)finSetting('fin_min_deposit', '10500');
?>
<section class="ap-hero">
  <span class="ap-eyebrow"><i class="fas fa-check-circle"></i> No credit checks · No ITC · Everyone qualifies</span>
  <h1>Apply for in-house finance</h1>
  <p>Choose the vehicle, enter the start-up deposit you can put down, and your quotation — with the monthly payment for every term — is ready the moment you send it.</p>
</section>

<div class="ap-wrap">
  <?= finFlashHtml() ?>
  <?php if (!empty($errors['form'])): ?><div class="ap-flash ap-flash-error"><?= e($errors['form']) ?></div><?php endif; ?>
  <?php if (isset($_GET['sent'])): ?><div class="ap-flash ap-flash-success">Thank you — our finance team will be in touch.</div><?php endif; ?>

  <div class="ap-card ap-row" style="justify-content:space-between">
    <div><strong style="color:#003f73"><i class="fas fa-route"></i> Already applied?</strong>
      <p class="ap-help">Your quotation, plan, documents, invoice, approval and Buyer's Code — all on your own application page.</p></div>
    <a class="ap-btn ap-btn-out ap-btn-sm" href="<?= APP_URL ?>/my_applications.php">Find my application</a>
  </div>

  <div class="ap-grid">
    <form class="ap-card" method="post" novalidate>
      <input type="hidden" name="csrf" value="<?= e(finCsrfToken()) ?>">
      <div class="ap-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
      <h2>Your application</h2>
      <p class="ap-help" style="margin-bottom:1rem">Only vehicles without bids can be financed. Your ID, address and income documents are uploaded afterwards on your private application page — not on this form.</p>
      <?php if ($notEligible): ?><div class="ap-flash ap-flash-error">That vehicle has already taken a bid or is reserved, so it can no longer be financed — please choose another one.</div><?php endif; ?>
      <div class="ap-form">
        <div class="full <?= $cls('auction_id') ?>"><label for="ap-vehicle">Vehicle</label>
          <?= finVehicleSelect($lots, $vehicle) ?><?= $err('auction_id') ?>
          <span class="ap-help" id="ap-vehicle-help"><?= count($lots) ?> vehicles available for in-house finance.</span></div>
        <div class="<?= $cls('deposit') ?>"><label for="deposit">Start-up deposit (R)</label>
          <input class="ap-input" id="deposit" name="deposit" inputmode="decimal" required value="<?= $val('deposit', $vehicle ? (string)(int)finMinDeposit($vehicle) : '') ?>" placeholder="<?= (int)$minStd ?>"><?= $err('deposit') ?>
          <span class="ap-help" id="ap-deposit-help">Minimum <?= formatRand($minStd) ?> on cars &amp; bakkies, <?= formatRand((float)finSetting('fin_min_deposit_heavy', '50000')) ?> on trucks &amp; plant. More lowers your instalment.</span></div>
        <div><label for="employment">Employment</label>
          <select class="ap-input" id="employment" name="employment">
            <?php foreach (['Employed (payslip)', 'Self-employed', 'Contract / temporary', 'Pensioner', 'Other'] as $o): ?>
              <option<?= ($old['employment'] ?? '') === $o ? ' selected' : '' ?>><?= e($o) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="<?= $cls('name') ?>"><label for="name">Full name</label><input class="ap-input" id="name" name="name" autocomplete="name" required value="<?= $val('name', $pageUser['full_name'] ?? '') ?>"><?= $err('name') ?></div>
        <div class="<?= $cls('phone') ?>"><label for="phone">Cellphone</label><input class="ap-input" id="phone" type="tel" name="phone" autocomplete="tel" required value="<?= $val('phone') ?>"><?= $err('phone') ?></div>
        <div class="<?= $cls('email') ?>"><label for="email">Email</label><input class="ap-input" id="email" type="email" name="email" autocomplete="email" required value="<?= $val('email', $pageUser['email'] ?? '') ?>"><?= $err('email') ?></div>
        <div><label for="income">Monthly income (R) <span style="font-weight:400;color:#889">optional</span></label><input class="ap-input" id="income" name="income" inputmode="decimal" value="<?= $val('income') ?>"></div>
        <div class="full"><label for="message">Anything else we should know? <span style="font-weight:400;color:#889">optional</span></label><textarea class="ap-input" id="message" name="message" rows="2"><?= $val('message') ?></textarea></div>
        <div class="full">
          <label class="ap-check"><input type="checkbox" name="whatsapp_ok" value="1"<?= ($old ? !empty($old['whatsapp_ok']) : true) ? ' checked' : '' ?>><span><i class="fab fa-whatsapp" style="color:#25D366"></i> Send me updates on WhatsApp too</span></label>
          <label class="ap-check <?= $cls('agree') ?>"><input type="checkbox" name="agree" value="1"<?= !empty($old['agree']) ? ' checked' : '' ?>><span>I understand my quotation is an estimate until I sign the finance agreement, and that I upload my documents on my application page.</span></label><?= $err('agree') ?>
        </div>
      </div>
      <button class="ap-btn" type="submit" style="margin-top:1rem"><i class="fas fa-file-invoice"></i> Get my quotation</button>
      <p class="ap-help">No credit check is run and there is no obligation. You choose your monthly payment on the quotation, and only then do we send your deposit invoice.</p>
    </form>

    <aside>
      <div class="ap-card">
        <h3>How it works</h3>
        <ol style="padding-left:1.1rem;margin:0;color:#445;font-size:.92rem;line-height:1.7">
          <li><strong>Quotation</strong> — instant, emailed to you.</li>
          <li><strong>Choose your plan</strong> — 6 to 48 months (up to 72 on trucks &amp; plant).</li>
          <li><strong>Deposit invoice</strong> — once we approve your deposit.</li>
          <li><strong>Documents &amp; payment</strong> — upload ID, address, bank statements, payslip, licence and proof of payment.</li>
          <li><strong>Approved</strong> — approval letter and your 6-digit Buyer's Code.</li>
          <li><strong>Test drive, sign &amp; drive away</strong> — at our head office.</li>
        </ol>
      </div>
      <div class="ap-card ap-faq">
        <h3>Quick questions</h3>
        <details><summary>Do I need a good credit score?</summary><p>No. No credit checks, no ITC and no bureau inquiries. Blacklisted, debt-review and first-time buyers are all welcome.</p></details>
        <details><summary>What if the vehicle already has a bid?</summary><p>Then it is live on the auction floor and can no longer be financed this way — you are welcome to bid on it instead.</p></details>
        <details><summary>Is the deposit an extra cost?</summary><p>No. It comes off the price. Paying more than the minimum lowers what is left to repay.</p></details>
        <details><summary>Why can't I see the VIN?</summary><p>We never publish VINs (<a href="<?= APP_URL ?>/vin-policy.php">our VIN policy</a>). Once you are approved, the VIN is on your approval letter.</p></details>
      </div>
      <div class="ap-card">
        <h3>Other ways to buy</h3>
        <p class="ap-help"><a href="<?= APP_URL ?>/preauction_request.php<?= $vehicle ? '?id=' . (int)$vehicle['id'] : '' ?>">Pre-auction purchase (Buy Now)</a> · <a href="<?= APP_URL ?>/auctions.php">Bid at auction</a> · <a href="<?= APP_URL ?>/financing.php">About in-house finance</a></p>
      </div>
    </aside>
  </div>
</div>
<script>
(function () {
  var sel = document.getElementById('ap-vehicle'), dep = document.getElementById('deposit');
  var help = document.getElementById('ap-deposit-help'), vhelp = document.getElementById('ap-vehicle-help');
  var rand = function (n) { return 'R' + Number(n).toLocaleString('en-ZA').replace(/ /g, ' '); };
  function update(setMin) {
    var o = sel.options[sel.selectedIndex];
    if (!o || !o.value) return;
    var min = +o.dataset.min, price = +o.dataset.price;
    help.textContent = 'Minimum ' + rand(min) + ' on this vehicle. You may put down more — it lowers your instalment.';
    vhelp.textContent = 'Price ' + rand(price) + ' · terms from 6 to ' + o.dataset.maxTerm + ' months.';
    var cur = +String(dep.value).replace(/[^\d.]/g, '');
    if (setMin && (!cur || cur < min)) dep.value = min;
  }
  sel.addEventListener('change', function () { update(true); });
  update(false);
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
