<?php
// ============================================================
// AUCTION HUB GROUP — my_application.php?t=TOKEN
// The client's private page for an In-House Finance application or a
// Pre-Auction Purchase request: progress, and each step they take.
//   Finance:     choose a term · upload documents & proof of payment ·
//                Buyer Code · book the test drive
//   Pre-auction: proforma invoice + POP upload (invoice_pop_upload.php) ·
//                Buyer Code · after the 1h30 test drive: proceed, switch
//                to the alternative, or cancel for a refund
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';

$app = finFind((string)($_GET['t'] ?? ''));
if (!$app) {
    http_response_code(404);
    $pageTitle = 'Application not found';
    $noIndex = true;
    require_once __DIR__ . '/header.php';
    require __DIR__ . '/fin_ui.php';
    finHero('<i class="fas fa-circle-question"></i> Application', 'We Could Not Find That Application', 'Please use the button in your email, or have your links emailed to you again.', 'My Application');
    echo '<section class="ap-section"><div class="ap-container" style="text-align:center"><a class="btn btn-primary" href="' . APP_URL . '/my_applications.php"><i class="fas fa-envelope"></i> Email Me My Links</a></div></section>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$isFin = $app['kind'] === 'finance';
$back  = finLink($app);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $s = $app['status'];
    if (!finCsrfOk()) {
        finFlash('error', 'Your session expired — please try again.');
        header('Location: ' . $back); exit;
    }
    $to = '';
    if ($isFin && $action === 'choose' && in_array($s, ['quoted', 'chosen'], true)) {
        $m = (int)($_POST['months'] ?? 0);
        $plan = null;
        foreach ($app['details']['quote']['plans'] ?? [] as $p) if ((int)$p['months'] === $m) $plan = $p;
        if ($plan) {
            $app['details']['choice'] = ['months' => $m, 'at' => date('Y-m-d H:i:s')];
            $app['months'] = $m;
            $app['status'] = 'chosen';
            finLog($app, "Chose the $m-month term at " . formatRand($plan['monthly']) . ' per month');
            finSave($app);
            finMailOffice($app, "Term chosen: $m months at " . formatRand($plan['monthly']) . ' — approve the deposit');
            finFlash('success', "You chose <strong>$m months at " . e(formatRand($plan['monthly'])) . ' per month</strong>. Our finance team is reviewing your deposit — we email you as soon as it is approved.');
        } else {
            finFlash('error', 'Please choose one of the terms.');
        }
        $to = 'plans';
    } elseif ($isFin && $action === 'upload' && in_array($s, ['invoiced', 'approved'], true)) {
        $kind = (string)($_POST['doc'] ?? '');
        [$n, $err] = finDocUpload($app, $kind, $_FILES['files'] ?? []);
        if ($n) {
            finSave($app);
            finMailOffice($app, 'Document uploaded: ' . (FIN_DOCS[$kind][0] ?? $kind));
            finFlash('success', e(FIN_DOCS[$kind][0]) . ' uploaded — thank you. Our team will review it.');
        }
        if ($err !== '') finFlash('error', e($err));
        $to = 'documents';
    } elseif ($isFin && $action === 'viewing' && $s === 'approved') {
        $date = (string)($_POST['date'] ?? ''); $time = (string)($_POST['time'] ?? '');
        if ($e = finViewingError($date, $time)) {
            finFlash('error', e($e));
        } else {
            $app['details']['viewing'] = ['date' => $date, 'time' => $time, 'status' => 'requested', 'note' => trim(mb_substr((string)($_POST['note'] ?? ''), 0, 500)), 'at' => date('Y-m-d H:i:s')];
            finLog($app, 'Requested a test drive on ' . finViewingLabel($app['details']['viewing']));
            finSave($app);
            finMailOffice($app, 'Test drive requested: ' . finViewingLabel($app['details']['viewing']));
            finFlash('success', 'Thank you — we will confirm your test drive shortly.');
        }
        $to = 'viewing';
    } elseif (!$isFin && $action === 'decision' && $s === 'paid' && empty($app['details']['decision'])) {
        $choice = (string)($_POST['choice'] ?? '');
        if (in_array($choice, ['proceed', 'alternative', 'refund'], true) && ($choice !== 'alternative' || !empty($app['details']['alt']))) {
            $app['details']['decision'] = ['choice' => $choice, 'at' => date('Y-m-d H:i:s'), 'reason' => trim(mb_substr((string)($_POST['reason'] ?? ''), 0, 500))];
            if ($choice === 'refund') $app['status'] = 'refund';
            $label = ['proceed' => 'Proceeding with the purchase — will pay the outstanding balance', 'alternative' => 'Switching to the nominated alternative vehicle', 'refund' => 'Cancelled after the test drive — refund requested'][$choice];
            finLog($app, $label);
            finSave($app);
            finMailOffice($app, $label);
            finFlash('success', $choice === 'refund'
                ? 'Understood. Your commitment fee is refunded within 3–5 business days to the account it came from, less R' . number_format(PA_REFUND_FEE, 2) . ' admin and bank charges.'
                : 'Thank you — our team will contact you to finalise and arrange handover.');
        }
        $to = 'decision';
    } elseif ($action === 'cancel' && in_array($s, ['quoted', 'chosen', 'invoiced', 'requested', 'expired'], true)) {
        $app['status'] = 'cancelled';
        $app['details']['cancelled'] = ['at' => date('Y-m-d H:i:s'), 'reason' => trim(mb_substr((string)($_POST['reason'] ?? ''), 0, 500))];
        finLog($app, 'Cancelled' . ($app['details']['cancelled']['reason'] !== '' ? ': ' . $app['details']['cancelled']['reason'] : ''));
        finSave($app);
        finMailOffice($app, 'Client cancelled');
        finFlash('info', 'Cancelled — nothing is owed. You can still buy at auction: <a href="' . APP_URL . '/login.php?tab=register">register to bid</a>.');
    }
    header('Location: ' . $back . ($to !== '' ? '#' . $to : '')); exit;
}

$d       = $app['details'];
$s       = $app['status'];
$step    = finStepIndex($app);
$vehicle = finVehicle((int)$app['auction_id']);
$showVin = ($isFin && in_array($s, ['approved', 'completed'], true)) || (!$isFin && in_array($s, ['paid', 'completed'], true));
$vin     = $showVin ? vinForAuction((int)$app['auction_id']) : '';
$plan    = $isFin ? finChosenPlan($app) : null;
$inv     = !$isFin ? paInvoiceRow($app) : null;
$bank    = getBankSettings();
$csrf    = e(finCsrfToken());
$first   = explode(' ', trim($app['name']))[0];
$img     = $d['vehicle']['image'] ?? ($vehicle['image_url'] ?? '');
$R       = fn($n) => formatRand((float)$n);

$pageTitle = ($isFin ? 'My Finance Application' : 'My Pre-Auction Purchase') . ' — ' . $app['vehicle'];
$pageDescription = 'Your private Auction Hub Group application page.';
$bodyClass = $isFin ? 'page-financing' : 'page-preauction';
$noIndex = true;
require_once __DIR__ . '/header.php';
require __DIR__ . '/fin_ui.php';

finHero($isFin ? '<i class="fas fa-hand-holding-dollar"></i> In-House Finance · ' . e(finRef($app)) : '<i class="fas fa-key"></i> Pre-Auction Purchase · ' . e(finRef($app)),
    'Hi ' . e($first) . ', Here Is Where It Stands', e($app['vehicle']) . ' · ' . $R($app['price']),
    $isFin ? 'My Finance Application' : 'My Pre-Auction Purchase');
?>
<section class="ap-section"><div class="ap-container">
  <?= finFlashHtml() ?>

  <?php if (!in_array($s, ['declined', 'cancelled'], true)): ?>
  <ol class="ap-steps" aria-label="Progress">
    <?php foreach (finSteps($app['kind']) as $i => [$icon, $color, $label]): ?>
      <li class="<?= $i < $step ? 'done' : ($i === $step ? 'now' : '') ?>" style="--c:<?= $color ?>">
        <span class="n"><i class="fas <?= $i < $step ? 'fa-check' : $icon ?>"></i></span><b><?= e($label) ?></b></li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>

  <div class="ap-grid">
    <div>
    <?php if ($s === 'declined'): ?>
      <div class="ap-card"><div class="ap-card-head"><i class="fas fa-circle-info"></i> <?= !empty($d['declined']['institution']) ? 'Decision From the Financial Institution' : 'We Could Not Proceed' ?></div><div class="ap-card-body">
        <p><?= !empty($d['declined']['institution'])
            ? 'The financial institution holding this vehicle has determined that the pre-auction commitment cannot be processed at this time. This is not a reflection of your creditworthiness.'
            : 'We are sorry — we are not able to proceed with this ' . ($isFin ? 'finance application' : 'request') . ' on this vehicle. This is not a reflection of your creditworthiness.' ?></p>
        <?php if (!empty($d['declined']['reason'])): ?><div class="ap-notice"><i class="fas fa-circle-info"></i><div><?= nl2br(e($d['declined']['reason'])) ?></div></div><?php endif; ?>
        <?php if (!$isFin && $inv && $inv['pop_file_path']): ?><p class="ap-help">Any commitment fee paid is refunded within 3–5 business days, less R<?= number_format(PA_REFUND_FEE, 2) ?> admin and bank charges.</p><?php endif; ?>
        <div class="ap-row" style="margin-top:1rem"><a class="btn btn-primary btn-sm" href="<?= APP_URL ?>/preauction.php">Browse Pre-Auction Vehicles</a>
          <a class="btn btn-outline btn-sm" href="<?= APP_URL ?>/auctions.php">Live Auctions</a></div>
      </div></div>
    <?php elseif ($s === 'cancelled'): ?>
      <div class="ap-card"><div class="ap-card-head"><i class="fas fa-circle-info"></i> Cancelled</div><div class="ap-card-body">
        <p>Nothing is owed. You can still get a great deal at auction — <a href="<?= APP_URL ?>/login.php?tab=register">register to bid</a> (the <?= e(REG_FEE_LABEL) ?> deposit is refundable).</p>
        <a class="btn btn-primary btn-sm" href="<?= APP_URL ?>/auctions.php">Browse Auctions</a></div></div>
    <?php endif; ?>

    <?php /* ════════ IN-HOUSE FINANCE ════════ */ ?>
    <?php if ($isFin && !empty($d['quote'])): $q = $d['quote']; ?>
      <?php if (in_array($s, ['quoted', 'chosen'], true)): ?>
      <form class="ap-card" id="plans" method="post">
        <div class="ap-card-head"><i class="fas fa-calendar-days"></i> <?= !empty($q['counter']) ? 'Your Updated Quote' : 'Choose Your Repayment Term' ?></div>
        <div class="ap-card-body">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="choose">
          <?php if (!empty($q['counter'])): ?>
            <div class="ap-notice"><i class="fas fa-circle-info"></i><div>We can approve a deposit of <strong><?= $R($q['deposit']) ?></strong> on this vehicle. Choose your term at this deposit to continue.<?= $q['note'] !== '' ? '<br>' . nl2br(e($q['note'])) : '' ?></div></div>
          <?php endif; ?>
          <div style="margin-bottom:1rem">
            <div class="ap-rowline"><span class="lbl">Vehicle price</span><span class="val"><?= $R($q['price']) ?></span></div>
            <div class="ap-rowline fee"><span class="lbl">Your deposit</span><span class="val">− <?= $R($q['deposit']) ?></span></div>
            <div class="ap-rowline big"><span class="lbl">Amount financed</span><span class="val"><?= $R($q['financed']) ?></span></div>
          </div>
          <?php foreach ($q['plans'] as $p): $on = (int)($d['choice']['months'] ?? 0) === (int)$p['months']; ?>
            <label class="ap-plan<?= $on ? ' chosen' : '' ?>">
              <input type="radio" name="months" value="<?= (int)$p['months'] ?>"<?= $on ? ' checked' : '' ?> required>
              <span><span class="m"><?= (int)$p['months'] ?> Months</span> <?= (int)$p['months'] === 12 ? '<span class="pop">Popular</span>' : '' ?><br><span class="t">Total <?= $R($p['total']) ?></span></span>
              <span class="p"><?= $R($p['monthly']) ?><small> /month</small></span>
            </label>
          <?php endforeach; ?>
          <p class="ap-help">Quote <?= e($q['number']) ?> · valid until <?= date('j F Y', strtotime($q['valid'])) ?> · indicative until you sign your agreement.</p>
          <div class="ap-row" style="margin-top:.9rem">
            <button class="btn btn-success" type="submit"><i class="fas fa-check"></i> <?= $s === 'chosen' ? 'Change My Term' : 'Choose This Term' ?></button>
            <a class="btn btn-outline btn-sm" href="<?= e(finDocLink($app, 'quote')) ?>" target="_blank"><i class="fas fa-print"></i> Print Quote</a>
          </div>
          <?php if ($s === 'chosen'): ?><div class="alert alert-info" style="margin:1rem 0 0"><i class="fas fa-hourglass-half"></i><span>You chose <?= (int)$d['choice']['months'] ?> months. Our finance team is reviewing your deposit — next you submit your documents.</span></div><?php endif; ?>
        </div>
      </form>
      <?php endif; ?>

      <?php if (in_array($s, ['invoiced', 'approved', 'completed'], true)): ?>
      <div class="ap-card">
        <div class="ap-card-head"><i class="fas fa-file-invoice-dollar"></i> <?= $s === 'invoiced' ? 'Pay Your Deposit' : 'Your Finance Plan' ?></div>
        <div class="ap-card-body">
          <div class="ap-rowline"><span class="lbl">Deposit</span><span class="val"><?= $R($app['amount']) ?></span></div>
          <?php if ($plan): ?><div class="ap-rowline"><span class="lbl">Term</span><span class="val"><?= (int)$plan['months'] ?> months × <?= $R($plan['monthly']) ?></span></div><?php endif; ?>
          <div class="ap-rowline big"><span class="lbl">Amount financed</span><span class="val"><?= $R($q['financed']) ?></span></div>
          <?php if ($s === 'invoiced'): ?>
            <div style="background:#f0f6fc;border-radius:10px;padding:.4rem 1rem;margin-top:1rem">
              <div class="ap-rowline"><span class="lbl">Bank</span><span class="val"><?= e($bank['bank_name']) ?></span></div>
              <div class="ap-rowline"><span class="lbl">Account name</span><span class="val"><?= e($bank['bank_account_name']) ?></span></div>
              <div class="ap-rowline"><span class="lbl">Account number</span><span class="val"><?= e($bank['bank_account_no']) ?></span></div>
              <div class="ap-rowline"><span class="lbl">Branch code</span><span class="val"><?= e($bank['bank_branch_code']) ?></span></div>
              <div class="ap-rowline"><span class="lbl">Reference</span><span class="val"><?= e(finPayRef($app)) ?></span></div>
            </div>
            <p class="ap-help"><i class="fas fa-shield-halved"></i> Only pay into the account on your Auction Hub Group invoice. Please pay by <?= date('j F Y', strtotime($d['invoice']['due'])) ?>.</p>
          <?php endif; ?>
          <div class="ap-row" style="margin-top:.9rem">
            <?php if (!empty($d['invoice'])): ?><a class="btn btn-outline btn-sm" href="<?= e(finDocLink($app, 'invoice')) ?>" target="_blank"><i class="fas fa-file-invoice"></i> Deposit Invoice</a><?php endif; ?>
            <a class="btn btn-outline btn-sm" href="<?= e(finDocLink($app, 'quote')) ?>" target="_blank"><i class="fas fa-print"></i> Quote</a>
            <?php if ($s !== 'invoiced'): ?><a class="btn btn-outline btn-sm" href="<?= e(finDocLink($app, 'approval')) ?>" target="_blank"><i class="fas fa-award"></i> Approval Letter</a><?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php /* ════════ PRE-AUCTION PURCHASE ════════ */ ?>
    <?php if (!$isFin && !in_array($s, ['declined', 'cancelled'], true)): $pc = paPercent($app); ?>
      <div class="ap-card">
        <div class="ap-card-head"><i class="fas fa-file-invoice-dollar"></i> <?= match ($s) {
            'requested' => 'Your Proforma Invoice Is Being Prepared', 'invoiced' => 'Pay Your Commitment Fee',
            'expired' => 'Your Invoice Has Expired', 'pop_uploaded' => 'Verifying Your Payment', default => 'Your Pre-Auction Purchase' } ?></div>
        <div class="ap-card-body">
          <div class="ap-rowline"><span class="lbl">Total price</span><span class="val"><?= $R($app['price']) ?></span></div>
          <div class="ap-rowline fee"><span class="lbl">Commitment fee (<?= $pc ?>%)</span><span class="val"><?= $R($inv ? $inv['commitment_fee_amount'] : paCommitment($app)) ?></span></div>
          <div class="ap-rowline big"><span class="lbl">Outstanding after your test drive</span><span class="val"><?= $R($inv ? $inv['outstanding_amount'] : (float)$app['price'] - paCommitment($app)) ?></span></div>
          <?php if (!empty($d['alt'])): ?><div class="ap-rowline"><span class="lbl">Alternative vehicle</span><span class="val"><?= e($d['alt']['title']) ?></span></div><?php endif; ?>
          <?php if ($inv): ?><div class="ap-rowline"><span class="lbl">Invoice</span><span class="val"><?= e($inv['invoice_number']) ?></span></div><?php endif; ?>

          <?php if ($s === 'requested'): ?>
            <div class="alert alert-info" style="margin:1rem 0 0"><i class="fas fa-hourglass-half"></i><span>Our team is preparing your personalised proforma invoice — usually within the hour. It comes to you by email and appears here.</span></div>
          <?php elseif ($s === 'invoiced' && $inv): ?>
            <div class="ap-notice"><i class="fas fa-clock"></i><div><strong>Valid for 24 hours</strong> — until <?= safeDate('l j F, H:i', $inv['expires_at']) ?>. This window is set by the financial institution; once it closes the vehicle returns to the auction process.</div></div>
            <a class="btn btn-primary btn-lg btn-block" href="<?= e(paInvoiceLink($inv)) ?>"><i class="fas fa-upload"></i> View Invoice &amp; Upload Proof of Payment</a>
          <?php elseif ($s === 'pop_uploaded'): ?>
            <div class="alert alert-info" style="margin:1rem 0 0"><i class="fas fa-magnifying-glass-dollar"></i><span>We have your proof of payment and are verifying it with the financial institution. Your Buyer Code follows by email.</span></div>
          <?php elseif ($s === 'expired'): ?>
            <div class="alert alert-warning" style="margin:1rem 0 0"><i class="fas fa-triangle-exclamation"></i><span>The 24-hour window closed before payment. Please <a href="<?= e(finWhatsAppUrl($app)) ?>" target="_blank" rel="noopener">WhatsApp us</a> — if the vehicle is still available we can re-issue your invoice.</span></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php /* ── Buyer Code access pass ── */ ?>
    <?php if (!empty($app['buyer_code']) && in_array($s, ['approved', 'paid', 'completed'], true)): ?>
      <div class="ap-pass">
        <div class="ap-pass-head"><div class="ap-pass-icon"><i class="fas fa-gavel"></i></div>
          <div><small>Auction Hub Group</small><strong><?= $isFin ? 'Finance Access Pass' : 'Pre-Auction Access Pass' ?></strong></div>
          <span class="ap-pass-ok"><i class="fas fa-circle-check"></i> <?= $isFin ? 'Approved' : 'Presold' ?></span></div>
        <div class="ap-pass-body">
          <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.1em;color:#888;font-weight:700">Registered Buyer</div>
          <div style="font-weight:800;color:#003f73"><?= e($app['name']) ?></div>
          <div class="ap-pass-code"><small>Buyer Code</small><b><?= e($app['buyer_code']) ?></b></div>
          <?php if ($showVin): ?>
            <div class="ap-rowline"><span class="lbl">VIN</span><span class="val"><?= $vin !== '' ? e($vin) : 'Shown on site, on the vehicle' ?></span></div>
            <p class="ap-help">Shared with you as a verified buyer — see our <a href="<?= APP_URL ?>/vin-policy.php">VIN policy</a>. Check it against the plate on the vehicle.</p>
          <?php endif; ?>
          <p class="ap-help"><i class="fas fa-triangle-exclamation" style="color:#e8a000"></i> Present this code on arrival at <?= e(COMPANY_ADDRESS) ?>. It is unique to you — do not share it. No Buyer Code, no entry.</p>
        </div>
      </div>
    <?php endif; ?>

    <?php /* ── Test drive ── */ ?>
    <?php if (($isFin && in_array($s, ['approved', 'completed'], true)) || (!$isFin && in_array($s, ['paid', 'completed'], true))): $v = $d['viewing'] ?? []; ?>
      <div class="ap-card" id="viewing">
        <div class="ap-card-head"><i class="fas fa-car-side"></i> Your Test Drive</div>
        <div class="ap-card-body">
          <?php if (($v['status'] ?? '') === 'confirmed'): ?>
            <div class="alert alert-success" style="margin:0 0 .8rem"><i class="fas fa-calendar-check"></i><span>Confirmed: <strong><?= e(finViewingLabel($v)) ?></strong></span></div>
            <?php if (!empty($v['note'])): ?><p class="ap-help"><?= nl2br(e($v['note'])) ?></p><?php endif; ?>
          <?php elseif (!empty($v['date'])): ?>
            <div class="alert alert-info" style="margin:0 0 .8rem"><i class="fas fa-hourglass-half"></i><span>You asked for <strong><?= e(finViewingLabel($v)) ?></strong> — our team confirms the time with you.</span></div>
          <?php endif; ?>
          <p class="ap-help">At <?= e(COMPANY_ADDRESS) ?>. You have <strong>1 hour 30 minutes</strong> to test drive and inspect — bring your own mechanic if you like, plus your ID<?= $isFin ? ' and driver\'s licence' : '' ?>.</p>
          <?php if ($isFin && $s === 'approved' && ($v['status'] ?? '') !== 'confirmed'): ?>
            <form method="post" class="ap-form" style="margin-top:1rem">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="viewing">
              <div class="form-group"><label class="form-label" for="v-date">Day</label><input class="form-control" id="v-date" type="date" name="date" required min="<?= date('Y-m-d', strtotime('tomorrow')) ?>" max="<?= date('Y-m-d', strtotime('+60 days')) ?>"></div>
              <div class="form-group"><label class="form-label" for="v-time">Time</label><select class="form-control" id="v-time" name="time"><?php for ($h = 8; $h <= 15; $h++): ?><option><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?></select></div>
              <div class="full form-group"><label class="form-label" for="v-note">Note <span style="font-weight:400;color:#889">optional</span></label><input class="form-control" id="v-note" name="note"></div>
              <div class="full"><button class="btn btn-primary" type="submit"><i class="fas fa-calendar-plus"></i> <?= !empty($v['date']) ? 'Request Another Time' : 'Book My Test Drive' ?></button></div>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php /* ── Pre-auction: decide after the test drive ── */ ?>
    <?php if (!$isFin && in_array($s, ['paid', 'refund', 'completed'], true)): ?>
      <div class="ap-card" id="decision">
        <div class="ap-card-head green"><i class="fas fa-scale-balanced"></i> After Your Test Drive — Your Choice</div>
        <div class="ap-card-body">
          <?php if (!empty($d['decision'])): ?>
            <div class="alert alert-success" style="margin:0"><i class="fas fa-circle-check"></i><span><?= e(['proceed' => 'You are proceeding — our team contacts you for the outstanding balance and handover.', 'alternative' => 'You are switching to your alternative vehicle — our team will be in touch.', 'refund' => 'You cancelled — your commitment fee is refunded within 3–5 business days, less R' . number_format(PA_REFUND_FEE, 2) . '.'][$d['decision']['choice']]) ?></span></div>
          <?php else: ?>
            <p class="ap-help" style="margin:0 0 .8rem">No pressure, no penalties — once you have inspected and driven it, let us know.</p>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="decision">
              <label class="ap-check"><input type="radio" name="choice" value="proceed" required><span><strong>Proceed with the purchase</strong> — pay the outstanding balance of <?= $R($inv ? $inv['outstanding_amount'] : (float)$app['price'] - (float)$app['amount']) ?></span></label>
              <?php if (!empty($d['alt'])): ?><label class="ap-check"><input type="radio" name="choice" value="alternative"><span><strong>Switch to my alternative</strong> — <?= e($d['alt']['title']) ?></span></label><?php endif; ?>
              <label class="ap-check"><input type="radio" name="choice" value="refund"><span><strong>Cancel the deal</strong> — refund of my commitment fee, less R<?= number_format(PA_REFUND_FEE, 2) ?> admin and bank charges</span></label>
              <input class="form-control" name="reason" placeholder="Anything we should know? (optional)" style="margin:.5rem 0 .9rem">
              <button class="btn btn-primary" type="submit">Send My Decision</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php /* ── Finance documents ── */ ?>
    <?php if ($isFin && in_array($s, ['invoiced', 'approved'], true)): ?>
      <div class="ap-card" id="documents">
        <div class="ap-card-head"><i class="fas fa-folder-open"></i> Submit Your Documents</div>
        <div class="ap-card-body">
          <p class="ap-help" style="margin:0 0 1rem">PDF or photos, up to 8 MB each. Stored privately — only you and our finance team can open them.</p>
          <?php foreach (FIN_DOCS as $k => [$label, $hint, $max]): $doc = $d['docs'][$k] ?? null; $st = $doc['status'] ?? ''; ?>
            <div class="ap-doc">
              <div class="ap-doc-head">
                <div><strong><?= e($label) ?></strong><div class="ap-help" style="margin:0"><?= e($hint) ?></div></div>
                <span class="badge <?= ['pending' => 'badge-pending', 'accepted' => 'badge-approved', 'rejected' => 'badge-rejected'][$st] ?? 'badge-registered' ?>"><?= ['pending' => 'Under review', 'accepted' => 'Accepted', 'rejected' => 'Please re-upload'][$st] ?? 'Required' ?></span>
              </div>
              <?php if ($st === 'rejected' && !empty($doc['note'])): ?><div class="ap-notice" style="margin:.6rem 0"><i class="fas fa-circle-info"></i><div><?= nl2br(e($doc['note'])) ?></div></div><?php endif; ?>
              <?php if (!empty($doc['files'])): ?><p class="ap-help"><?php foreach ($doc['files'] as $f): ?><a href="<?= APP_URL ?>/application_file.php?t=<?= e($app['token']) ?>&amp;f=<?= e($f['file']) ?>" target="_blank"><i class="fas fa-paperclip"></i> <?= e($f['original']) ?></a> &nbsp;<?php endforeach; ?></p><?php endif; ?>
              <?php if ($st !== 'accepted'): ?>
              <form method="post" enctype="multipart/form-data" class="ap-row" style="margin-top:.6rem">
                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="doc" value="<?= e($k) ?>">
                <input type="file" name="files[]" accept=".pdf,image/jpeg,image/png,image/webp"<?= $max > 1 ? ' multiple' : '' ?> required style="font-size:.85rem;max-width:100%">
                <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-upload"></i> Upload</button>
              </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (in_array($s, ['quoted', 'chosen', 'invoiced', 'requested', 'expired'], true)): ?>
      <details class="ap-card" style="padding:1rem 1.4rem"><summary style="cursor:pointer;font-weight:700;color:#6a7f96">Cancel this <?= $isFin ? 'application' : 'request' ?></summary>
        <form method="post" style="margin-top:.8rem"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="cancel">
          <input class="form-control" name="reason" placeholder="Tell us why (optional)" style="margin-bottom:.7rem">
          <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('Cancel this? Nothing is owed.')">Yes, Cancel</button></form></details>
    <?php endif; ?>
    </div>

    <aside>
      <div class="ap-card"><div class="ap-card-body">
        <div class="ap-veh">
          <?php if ($img): ?><img src="<?= e(cldImg($img, CLD_THUMB)) ?>" alt="" loading="lazy"><?php endif; ?>
          <div><strong><?= e($app['vehicle']) ?></strong>
            <span class="ap-help"><?= e(implode(' · ', array_filter([!empty($d['vehicle']['mileage']) ? number_format((int)$d['vehicle']['mileage']) . ' km' : '', $d['vehicle']['transmission'] ?? '', $d['vehicle']['fuel'] ?? '']))) ?></span>
            <?php if ($vehicle): ?><br><a class="ap-help" href="<?= APP_URL ?>/auction.php?id=<?= (int)$app['auction_id'] ?>">View vehicle →</a><?php endif; ?></div>
        </div>
        <div style="margin-top:1rem">
          <div class="ap-rowline"><span class="lbl">Reference</span><span class="val"><?= e(finRef($app)) ?></span></div>
          <div class="ap-rowline"><span class="lbl">Status</span><span class="val"><?= finBadge($app) ?></span></div>
          <div class="ap-rowline"><span class="lbl">Submitted</span><span class="val"><?= safeDate('j M Y', $app['created_at']) ?></span></div>
        </div>
      </div></div>
      <div class="ap-card">
        <div class="ap-card-head"><i class="fas fa-headset"></i> Talk to Us</div>
        <div class="ap-card-body">
          <div class="ap-row">
            <a class="btn btn-wa btn-sm" href="<?= e(finWhatsAppUrl($app)) ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> WhatsApp</a>
            <a class="btn btn-outline btn-sm" href="tel:<?= e(preg_replace('/\s/', '', COMPANY_PHONE)) ?>"><i class="fas fa-phone"></i> <?= e(COMPANY_PHONE) ?></a>
          </div>
          <p class="ap-help">Keep this page's link private — anyone with it can see your application.</p>
        </div>
      </div>
      <?php if (!empty($d['log'])): ?>
      <div class="ap-card"><div class="ap-card-head"><i class="fas fa-clock-rotate-left"></i> History</div><div class="ap-card-body"><ul class="ap-log">
        <?php foreach (array_reverse($d['log']) as $l): ?><li><?= safeDate('j M, H:i', $l['at']) ?> — <?= e($l['what']) ?></li><?php endforeach; ?>
      </ul></div></div>
      <?php endif; ?>
    </aside>
  </div>
</div></section>
<?php require_once __DIR__ . '/footer.php'; ?>
