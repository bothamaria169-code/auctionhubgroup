<?php
// ============================================================
// AUCTION HUB GROUP — finance_apply.php
// Apply online for In-House Vehicle Finance (financing.php → "Apply Now").
// Choose a vehicle that has no bids yet and your deposit: your quote on
// every term is ready immediately (same rates as the financing.php
// calculator) and emailed. You continue on my_application.php.
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
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))    $errors['email'] = 'Please enter a valid email — your quote is sent there.';
    if (!finValidPhone($phone))                        $errors['phone'] = 'Please enter a number we can call or WhatsApp.';
    if (!$vehicle)                                     $errors['auction_id'] = 'Please choose the vehicle you would like to finance.';
    elseif ($why = finDepositError($vehicle, $deposit)) $errors['deposit'] = $why;
    if (empty($_POST['agree']))                        $errors['agree'] = 'Please tick to confirm.';
    if (!$errors && enquiryRateLimited(6, 900))        $errors['form'] = 'You have sent several applications already — we will be in touch. For anything urgent call ' . COMPANY_PHONE . '.';

    if (!$errors) {
        try {
            $app = finCreate('finance', ['name' => $name, 'email' => $email, 'phone' => $phone, 'user_id' => $pageUser['sub'] ?? null], $vehicle, $deposit, null, [
                'message'    => trim(mb_substr((string)($_POST['message'] ?? ''), 0, 2000)),
                'employment' => trim(mb_substr((string)($_POST['employment'] ?? ''), 0, 60)),
                'income'     => finMoneyInput((string)($_POST['income'] ?? '')),
            ]);
            finIssueQuote($app, $deposit);
            finLog($app, 'Applied online — quote ' . $app['details']['quote']['number']);
            finSendQuote($app);
            finSave($app);
            finMailOffice($app, 'New In-House Finance Application');
            finFlash('success', 'Your quote is ready and has been emailed to <strong>' . e($email) . '</strong>. Choose your repayment term below.');
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
$grp = fn(string $k, string $x = '') => trim('form-group ' . $x . (isset($errors[$k]) ? ' ap-err' : ''));
$minStd = (float)finSetting('fin_min_deposit', '10500');

$pageTitle       = 'Apply for In-House Vehicle Finance — Instant Quote';
$pageDescription = 'Apply online for Auction Hub Group in-house vehicle finance. No credit checks, no ITC, no bureau inquiries. Choose your vehicle and deposit and get your quote instantly.';
$bodyClass       = 'page-financing';
require_once __DIR__ . '/header.php';
require __DIR__ . '/fin_ui.php';

finHero('<i class="fas fa-check-circle"></i> Everyone Qualifies', 'Apply for In-House Finance',
    'Choose your vehicle and your deposit — your personalised quote on every repayment term is ready the moment you apply. <strong style="color:#fff">No credit checks. No ITC. No bureau inquiries.</strong>',
    '<a href="' . APP_URL . '/financing.php">In-House Finance</a><span class="sep">/</span>Apply',
    ['Blacklisted welcome', 'From ' . e(formatRand($minStd)) . ' deposit', '6–48 month terms']);
?>
<section class="ap-section"><div class="ap-container">
  <?= finFlashHtml() ?>
  <?php if (!empty($errors['form'])): ?><div class="alert alert-error"><i class="fas fa-circle-xmark"></i><span><?= e($errors['form']) ?></span></div><?php endif; ?>
  <?php if (isset($_GET['sent'])): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span>Thank you — our finance team will be in touch.</span></div><?php endif; ?>

  <div class="ap-grid">
    <form class="ap-card" method="post" novalidate>
      <div class="ap-card-head"><i class="fas fa-file-signature"></i> Your Application</div>
      <div class="ap-card-body">
        <input type="hidden" name="csrf" value="<?= e(finCsrfToken()) ?>">
        <div class="ap-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
        <?php if ($notEligible): ?><div class="alert alert-warning"><i class="fas fa-triangle-exclamation"></i><span>That vehicle has already received a bid or is Presold, so it can no longer be financed — please choose another.</span></div><?php endif; ?>
        <div class="ap-form">
          <div class="full <?= $grp('auction_id') ?>"><label class="form-label" for="ap-vehicle">Vehicle <span class="required">*</span></label>
            <?= finVehicleSelect($lots, $vehicle) ?><?= $err('auction_id') ?>
            <span class="ap-help" id="ap-vehicle-help"><?= count($lots) ?> vehicles available — in-house finance applies until a vehicle receives its first bid.</span></div>
          <div class="<?= $grp('deposit') ?>"><label class="form-label" for="deposit">Deposit (R) <span class="required">*</span></label>
            <input class="form-control" id="deposit" name="deposit" inputmode="decimal" required value="<?= $val('deposit', $vehicle ? (string)(int)finMinDeposit($vehicle) : '') ?>" placeholder="<?= (int)$minStd ?>"><?= $err('deposit') ?>
            <span class="ap-help" id="ap-deposit-help">Min <?= e(formatRand($minStd)) ?> — pay more to lower your instalments.</span></div>
          <div class="form-group"><label class="form-label" for="employment">Employment</label>
            <select class="form-control" id="employment" name="employment">
              <?php foreach (['Employed (payslip)', 'Self-employed', 'Contract / temporary', 'Pensioner', 'Other'] as $o): ?>
                <option<?= ($old['employment'] ?? '') === $o ? ' selected' : '' ?>><?= e($o) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="<?= $grp('name') ?>"><label class="form-label" for="name">Full name <span class="required">*</span></label><input class="form-control" id="name" name="name" autocomplete="name" required value="<?= $val('name', $pageUser['full_name'] ?? '') ?>"><?= $err('name') ?></div>
          <div class="<?= $grp('phone') ?>"><label class="form-label" for="phone">Cellphone <span class="required">*</span></label><input class="form-control" id="phone" type="tel" name="phone" autocomplete="tel" required value="<?= $val('phone') ?>"><?= $err('phone') ?></div>
          <div class="<?= $grp('email') ?>"><label class="form-label" for="email">Email <span class="required">*</span></label><input class="form-control" id="email" type="email" name="email" autocomplete="email" required value="<?= $val('email', $pageUser['email'] ?? '') ?>"><?= $err('email') ?></div>
          <div class="form-group"><label class="form-label" for="income">Monthly income (R) <span style="font-weight:400;color:#889">optional</span></label><input class="form-control" id="income" name="income" inputmode="decimal" value="<?= $val('income') ?>"></div>
          <div class="full form-group"><label class="form-label" for="message">Anything else we should know? <span style="font-weight:400;color:#889">optional</span></label><textarea class="form-control" id="message" name="message" rows="2"><?= $val('message') ?></textarea></div>
          <div class="full <?= isset($errors['agree']) ? 'ap-err' : '' ?>">
            <label class="ap-check"><input type="checkbox" name="agree" value="1"<?= !empty($old['agree']) ? ' checked' : '' ?>><span>I understand my quote is indicative until I sign my finance agreement, and that I submit my documents on my application page.</span></label><?= $err('agree') ?>
          </div>
        </div>
        <button class="btn btn-primary btn-lg btn-block" type="submit" style="margin-top:1rem"><i class="fas fa-calculator"></i> Get My Instant Quote</button>
        <p class="ap-help" style="text-align:center">Already applied? <a href="<?= APP_URL ?>/my_applications.php">Track my application</a></p>
      </div>
    </form>

    <aside>
      <div class="ap-card">
        <div class="ap-card-head"><i class="fas fa-route"></i> How It Works</div>
        <div class="ap-card-body">
          <ol class="ap-mini-steps">
            <li><strong>Get your quote</strong> — instantly, on every term.</li>
            <li><strong>Choose your term</strong> — 6 to 48 months (up to 72 on trucks &amp; plant).</li>
            <li><strong>Submit your documents</strong> — SA ID, proof of address, 3 months' bank statements, payslip.</li>
            <li><strong>Pay your deposit</strong> — it secures the vehicle and activates your agreement.</li>
            <li><strong>Drive away</strong> — test drive with your Buyer Code, sign, and go.</li>
          </ol>
        </div>
      </div>
      <div class="ap-faq">
        <span class="ap-label"><i class="fas fa-circle-question"></i> Quick Questions</span>
        <details><summary>Do I need a good credit score?</summary><p>No. No credit checks, no ITC and no bureau inquiries. Blacklisted clients, debt review and first-time buyers are all welcome.</p></details>
        <details><summary>Is the deposit an extra cost?</summary><p>No — it is deducted from the vehicle price. Pay any amount above <?= e(formatRand($minStd)) ?> to reduce your instalments.</p></details>
        <details><summary>What if the vehicle receives a bid?</summary><p>In-house finance applies until a vehicle's first bid. After that it goes to the auction floor — you are welcome to <a href="<?= APP_URL ?>/login.php?tab=register">register and bid</a>.</p></details>
        <details><summary>Why is the VIN not shown?</summary><p>We never publish VINs online — see our <a href="<?= APP_URL ?>/vin-policy.php">VIN policy</a>. Once approved, the VIN is on your application page.</p></details>
      </div>
      <div class="ap-card" style="margin-top:1.25rem">
        <div class="ap-card-head gold"><i class="fas fa-bolt"></i> Paying Cash Instead?</div>
        <div class="ap-card-body"><p class="ap-help" style="margin:0 0 .8rem">Secure the vehicle before the auction with a <strong>Pre-Auction Purchase</strong> at the bank's price.</p>
          <a class="btn btn-outline btn-sm" href="<?= APP_URL ?>/preauction_request.php<?= $vehicle ? '?id=' . (int)$vehicle['id'] : '' ?>"><i class="fas fa-key"></i> Reserve Before Auction</a></div>
      </div>
    </aside>
  </div>
</div></section>
<script>
(function () {
  var sel = document.getElementById('ap-vehicle'), dep = document.getElementById('deposit');
  var help = document.getElementById('ap-deposit-help'), vhelp = document.getElementById('ap-vehicle-help');
  var R = function (v) { return 'R' + Math.round(v).toLocaleString('en-ZA', {maximumFractionDigits: 0}); };
  function update(setMin) {
    var o = sel.options[sel.selectedIndex];
    if (!o || !o.value) return;
    var min = +o.dataset.min, price = +o.dataset.price;
    help.textContent = 'Min ' + R(min) + ' on this vehicle — pay more to lower your instalments.';
    vhelp.textContent = 'Price ' + R(price) + ' · terms from 6 to ' + o.dataset.maxTerm + ' months.';
    var cur = +String(dep.value).replace(/[^\d.]/g, '');
    if (setMin && (!cur || cur < min)) dep.value = min;
  }
  sel.addEventListener('change', function () { update(true); });
  update(false);
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
