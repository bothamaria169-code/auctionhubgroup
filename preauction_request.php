<?php
// ============================================================
// AUCTION HUB GROUP — preauction_request.php
// Step 1 of the Pre-Auction Purchase on preauction.php — "Submit Your
// Details": full name, ID/passport, contact details, physical address,
// and an alternative vehicle. The client does NOT choose a test-drive date:
// staff book it in Admin once the commitment fee is verified. The client offers a commitment fee % within the range (usual: 50% to R100,000,
// 40% to R150,000, 30% above); the office approves or adjusts it when it issues the proforma
// invoice from Admin → Finance & Pre-Auction (a preauction_invoices row,
// valid 24 hours).
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
[$paMin, $paMax] = paRange();
$paBand = $vehicle ? paBandPercent(finPrice($vehicle)) : 50;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim($_POST['website'] ?? '') !== '') { header('Location: preauction_request.php?sent=1'); exit; }
    if (!finCsrfOk()) $errors['form'] = 'Your session expired — please send the form again.';
    $name    = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 150));
    $email   = trim(mb_substr((string)($_POST['email'] ?? ''), 0, 190));
    $phone   = finCleanPhone((string)($_POST['phone'] ?? ''));
    $idNo    = strtoupper(trim(mb_substr((string)($_POST['id_number'] ?? ''), 0, 20)));
    $address = trim(mb_substr((string)($_POST['address'] ?? ''), 0, 500));
    $alt = null;
    if (($altId = (int)($_POST['alt_id'] ?? 0)) > 0 && (!$vehicle || $altId !== (int)$vehicle['id'])) {
        foreach ($lots as $l) if ((int)$l['id'] === $altId) $alt = $l;
    }

    if (mb_strlen($name) < 3)                         $errors['name']  = 'Please enter your full name.';
    if (!preg_match('/^[A-Z0-9]{6,20}$/', $idNo))     $errors['id_number'] = 'Please enter your SA ID or passport number — it goes on your invoice.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))   $errors['email'] = 'Please enter a valid email — your invoice and Buyer Code are sent there.';
    if (!finValidPhone($phone))                       $errors['phone'] = 'Please enter a number we can call or WhatsApp.';
    if (mb_strlen($address) < 8)                      $errors['address'] = 'Please enter your physical address.';
    $offerPct = (int)($_POST['offer_pct'] ?? 0);
    if ($offerPct < $paMin || $offerPct > $paMax)    $errors['offer_pct'] = "Please offer a commitment fee between {$paMin}% and {$paMax}%.";
    $bankName = (string)($_POST['bank_name'] ?? '');
    if (!in_array($bankName, FIN_BANKS, true))        $errors['bank_name'] = 'Please tell us which bank you bank with.';
    if (!$vehicle)                                    $errors['auction_id'] = 'Please choose the vehicle you want to secure.';
    if (empty($_POST['agree']))                       $errors['agree'] = 'Please tick to confirm.';
    if (!$errors && enquiryRateLimited(6, 900))       $errors['form'] = 'You have sent several requests already — we will be in touch. For anything urgent call ' . COMPANY_PHONE . '.';

    if (!$errors) {
        try {
            $app = finCreate('preauction', ['name' => $name, 'email' => $email, 'phone' => $phone, 'user_id' => $pageUser['sub'] ?? null], $vehicle, 0, null, [
                'id_number' => $idNo,
                'address'   => $address,
                'bank'      => ['name' => $bankName],
                'offer_pct' => $offerPct,
                'usual_pct' => $paBand,
                'mechanic'  => !empty($_POST['mechanic']),
                'alt'       => $alt ? ['id' => (int)$alt['id'], 'title' => finVehicleTitle($alt), 'price' => finPrice($alt)] : null,
                'message'   => trim(mb_substr((string)($_POST['message'] ?? ''), 0, 2000)),
            ]);
            finLog($app, 'Submitted details');
            require_once __DIR__ . '/commitment_letter_pdf.php';
            $letterPdf = paTermsLetterFile($app);   // attached as a PDF copy of the letter
            $ok = finMailClient($app, 'Your Commitment Fee Letter — please confirm before we issue your invoice',
                preg_replace('~^<p>Dear [^<]*</p>~', '', paTermsLetterHtml($app))   // finMailClient() already opens with "Dear <name>"
                . '<p style="font-size:13px;color:#555;">A PDF copy of this letter is attached for your records.</p>',
                'Follow My Request', '', '', $letterPdf ? [$letterPdf => 'AHG Commitment Fee Letter ' . finRef($app) . '.pdf'] : []);
            finRecordMail($app, 'Request received', $ok);
            finSave($app);
            finMailOffice($app, 'New Pre-Auction Request — issue the proforma invoice');
            finFlash('success', 'Your request is in. Our team issues your proforma invoice shortly — you can follow every step on this page. Keep the link to this page — it is private to you.');
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
$grp = fn(string $k, string $x = '') => trim('form-group ' . $x . (isset($errors[$k]) ? ' ap-err' : ''));

$pageTitle       = 'Pre-Auction Purchase — Secure Your Vehicle Before Auction';
$pageDescription = 'Secure a bank-stock vehicle at the financial institution\'s price before it reaches the auction floor. Pay the commitment fee on your proforma invoice, receive your Buyer Code and test drive for 1h30.';
$bodyClass       = 'page-preauction';
require_once __DIR__ . '/header.php';
require __DIR__ . '/fin_ui.php';

finHero('<i class="fas fa-key"></i> Pre-Auction Purchase', 'Secure It Before the Hammer Falls',
    'Submit your details and our team issues your personalised proforma invoice. Pay the commitment fee and the vehicle goes <strong style="color:#fff">Presold under your name</strong> — no competing bids, no floor drama.',
    '<a href="' . APP_URL . '/preauction.php">Pre-Auction Purchase</a><span class="sep">/</span>Submit Your Details',
    ['DEKRA-inspected', 'Commitment fee on your invoice', '1h30 test drive', 'Full refund option']);
?>
<section class="ap-section"><div class="ap-container">
  <?= finFlashHtml() ?>
  <?php if (!empty($errors['form'])): ?><div class="alert alert-error"><i class="fas fa-circle-xmark"></i><span><?= e($errors['form']) ?></span></div><?php endif; ?>
  <?php if (isset($_GET['sent'])): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span>Thank you — we will be in touch.</span></div><?php endif; ?>

  <div class="ap-grid">
    <form class="ap-card" method="post" novalidate>
      <div class="ap-card-head"><i class="fas fa-user-pen"></i> Step 1 — Submit Your Details</div>
      <div class="ap-card-body">
        <input type="hidden" name="csrf" value="<?= e(finCsrfToken()) ?>">
        <div class="ap-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
        <?php if ($notEligible): ?><div class="alert alert-warning"><i class="fas fa-triangle-exclamation"></i><span>That vehicle has already received a bid or is Presold — please choose another.</span></div><?php endif; ?>
        <div class="ap-form">
          <div class="full <?= $grp('auction_id') ?>"><label class="form-label" for="ap-vehicle">Vehicle <span class="required">*</span></label>
            <?= finVehicleSelect($lots, $vehicle) ?><?= $err('auction_id') ?>
            <span class="ap-help"><?= count($lots) ?> vehicles open for pre-auction purchase.</span></div>
          <div class="full form-group"><label class="form-label" for="alt_id">Alternative vehicle <span style="font-weight:400;color:#889">optional</span></label>
            <?= finVehicleSelect($lots, isset($old['alt_id']) ? ['id' => (int)$old['alt_id']] : null, 'alt_id', 'alt_id', false) ?></div>
          <div class="full" id="pa-figures" style="display:none;margin:-.25rem 0 1rem">
            <div style="background:linear-gradient(135deg,#f0f6fc,#e8f4fd);border:1.5px solid #b8d4ee;border-radius:12px;padding:.6rem 1rem">
              <div class="ap-rowline"><span class="lbl">Total price</span><span class="val" id="pa-price"></span></div>
              <div class="ap-rowline"><span class="lbl">Commitment fee</span><span class="val">Your offer below, approved by our office</span></div>
            </div>
          </div>
          <div class="<?= $grp('name') ?>"><label class="form-label" for="name">Full name <span class="required">*</span></label><input class="form-control" id="name" name="name" autocomplete="name" required value="<?= $val('name', $pageUser['full_name'] ?? '') ?>"><?= $err('name') ?></div>
          <div class="<?= $grp('id_number') ?>"><label class="form-label" for="id_number">ID / passport number <span class="required">*</span></label><input class="form-control" id="id_number" name="id_number" required value="<?= $val('id_number') ?>"><?= $err('id_number') ?></div>
          <div class="<?= $grp('phone') ?>"><label class="form-label" for="phone">Cellphone <span class="required">*</span></label><input class="form-control" id="phone" type="tel" name="phone" autocomplete="tel" required value="<?= $val('phone') ?>"><?= $err('phone') ?></div>
          <div class="<?= $grp('email') ?>"><label class="form-label" for="email">Email <span class="required">*</span></label><input class="form-control" id="email" type="email" name="email" autocomplete="email" required value="<?= $val('email', $pageUser['email'] ?? '') ?>"><?= $err('email') ?></div>
          <div class="full <?= $grp('address') ?>"><label class="form-label" for="address">Physical address <span class="required">*</span></label><input class="form-control" id="address" name="address" autocomplete="street-address" required value="<?= $val('address') ?>"><?= $err('address') ?></div>
          <div class="<?= $grp('offer_pct') ?>"><label class="form-label" for="offer_pct">Commitment fee you offer (%) <span class="required">*</span></label><input class="form-control" id="offer_pct" name="offer_pct" type="number" inputmode="numeric" min="<?= $paMin ?>" max="<?= $paMax ?>" step="1" required value="<?= $val('offer_pct', (string)$paBand) ?>"><span class="ap-help">Usual for a vehicle at this price: <?= $paBand ?>%. You may offer between <?= $paMin ?>% and <?= $paMax ?>%. Our office approves or adjusts your offer when it issues your proforma invoice.</span><?= $err('offer_pct') ?></div>
          <div class="<?= $grp('bank_name') ?>"><label class="form-label" for="bank_name">Which bank do you bank with? <span class="required">*</span></label><select class="form-control" id="bank_name" name="bank_name" required><option value="">Choose…</option><?= $opt2(FIN_BANKS, 'bank_name') ?></select><?= $err('bank_name') ?></div>
          <div class="full form-group"><label class="form-label" for="message">Anything else? <span style="font-weight:400;color:#889">optional</span></label><textarea class="form-control" id="message" name="message" rows="2"><?= $val('message') ?></textarea></div>
          <div class="full <?= isset($errors['agree']) ? 'ap-err' : '' ?>">
            <label class="ap-check"><input type="checkbox" name="mechanic" value="1"<?= !empty($old['mechanic']) ? ' checked' : '' ?>><span>I will bring my own mechanic to the test drive</span></label>
            <label class="ap-check"><input type="checkbox" name="agree" value="1"<?= !empty($old['agree']) ? ' checked' : '' ?>><span>I understand the proforma invoice is valid for 24 hours, the vehicle goes Presold once my commitment fee is verified, and that I may cancel after the test drive for a refund less R<?= number_format(PA_REFUND_FEE, 2) ?> admin and bank charges.</span></label><?= $err('agree') ?>
          </div>
        </div>
        <button class="btn btn-primary btn-lg btn-block" type="submit" style="margin-top:1rem"><i class="fas fa-paper-plane"></i> Submit My Details</button>
        <p class="ap-help" style="text-align:center">Already submitted? <a href="<?= APP_URL ?>/my_applications.php">Track my request</a></p>
      </div>
    </form>

    <aside>
      <div class="ap-card">
        <div class="ap-card-head"><i class="fas fa-route"></i> From Enquiry to Driving Away</div>
        <div class="ap-card-body">
          <ol class="ap-mini-steps">
            <li><strong>Submit your details</strong> — and nominate an alternative vehicle.</li>
            <li><strong>Review your proforma invoice</strong> — total price, commitment fee and outstanding balance. Valid for 24 hours.</li>
            <li><strong>Pay the commitment fee</strong> — the vehicle goes Presold under your name. Upload your proof of payment.</li>
            <li><strong>Receive your Buyer Code</strong> — your access pass. No Buyer Code, no entry.</li>
            <li><strong>Test drive &amp; inspect for 1h30</strong> — our team books the date with you.</li><li><strong>Sign off your decision</strong> — once you are happy with the vehicle, proceed, switch to your alternative, or cancel.</li>
          </ol>
        </div>
      </div>
      <div class="ap-notice"><i class="fas fa-triangle-exclamation"></i><div><strong>Entry requirements:</strong> No Buyer Code = No Entry. Your Buyer Code is issued once your commitment fee is verified with the financial institution.</div></div>
      <div class="ap-card">
        <div class="ap-card-head gold"><i class="fas fa-hand-holding-dollar"></i> Need Finance?</div>
        <div class="ap-card-body"><p class="ap-help" style="margin:0 0 .8rem">Apply for <strong>finance</strong> on the same vehicle — instant score and quote, from <?= e(formatRand((float)finSetting('fin_min_deposit', '10500'))) ?> deposit.</p>
          <a class="btn btn-outline btn-sm" href="<?= APP_URL ?>/finance_apply.php<?= $vehicle ? '?id=' . (int)$vehicle['id'] : '' ?>"><i class="fas fa-file-signature"></i> Apply for Finance</a>
          <p class="ap-help">We never publish VINs online — see our <a href="<?= APP_URL ?>/vin-policy.php">VIN policy</a>.</p></div>
      </div>
    </aside>
  </div>
</div></section>
<script>
(function () {
  var sel = document.getElementById('ap-vehicle');
  var R = function (v) { return 'R' + Math.round(v).toLocaleString('en-ZA', {maximumFractionDigits: 0}); };
  function update() {
    var o = sel.options[sel.selectedIndex], box = document.getElementById('pa-figures');
    if (!o || !o.value) { box.style.display = 'none'; return; }
    document.getElementById('pa-price').textContent = R(+o.dataset.price);
    box.style.display = '';
  }
  sel.addEventListener('change', update); update();
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
