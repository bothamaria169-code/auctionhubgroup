<?php
// ============================================================
// AUCTION HUB GROUP — my_application.php?t=TOKEN
// The client's private page for an in-house finance application or a
// pre-auction (Buy Now) request: where it stands, and every step they take —
// choose a plan, accept a suggestion, confirm, upload documents and proof of
// payment, see their Buyer's Code and VIN, book the test drive, decide.
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
    echo '<div class="ap-wrap" style="max-width:640px"><div class="ap-card"><h2>We could not find that application</h2>'
       . '<p>Please use the button in your email, or <a href="' . APP_URL . '/my_applications.php">find your applications</a>. You can also call us on ' . e(COMPANY_PHONE) . '.</p></div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$isFin = $app['kind'] === 'finance';
$back  = finLink($app);
$d     = &$app['details'];
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
        foreach ($d['quote']['plans'] ?? [] as $p) if ((int)$p['months'] === $m) $plan = $p;
        if ($plan) {
            $d['choice'] = ['months' => $m, 'at' => date('Y-m-d H:i:s')];
            $app['months'] = $m;
            $app['status'] = 'chosen';
            finLog($app, "Chose the $m-month plan at " . formatRand($plan['monthly']) . ' a month');
            finSave($app);
            finMailOffice($app, "Plan chosen: $m months at " . formatRand($plan['monthly']) . ' — approve the deposit');
            finFlash('success', "Thank you — you chose <strong>$m months at " . e(formatRand($plan['monthly'])) . ' a month</strong>. We are reviewing your deposit now; your deposit invoice comes to you by email.');
        } else {
            finFlash('error', 'Please choose one of the plans.');
        }
        $to = 'plans';
    } elseif ($action === 'upload' && in_array($s, ['invoiced', 'chosen', 'approved', 'confirmed'], true)) {
        $kind = (string)($_POST['doc'] ?? '');
        [$n, $err] = finDocUpload($app, $kind, $_FILES['files'] ?? []);
        if ($n) {
            finSave($app);
            finMailOffice($app, 'Document uploaded: ' . (FIN_DOCS[$kind][0] ?? $kind));
            finFlash('success', e(FIN_DOCS[$kind][0]) . ' uploaded — thank you. We check it and let you know.');
        }
        if ($err !== '') finFlash('error', e($err));
        $to = 'documents';
    } elseif ($isFin && $action === 'viewing' && $s === 'approved') {
        $date = (string)($_POST['date'] ?? ''); $time = (string)($_POST['time'] ?? '');
        if ($e = finViewingError($date, $time)) {
            finFlash('error', e($e));
        } else {
            $d['viewing'] = ['date' => $date, 'time' => $time, 'status' => 'requested', 'note' => trim(mb_substr((string)($_POST['note'] ?? ''), 0, 500)), 'at' => date('Y-m-d H:i:s')];
            finLog($app, 'Asked for a test drive on ' . finViewingLabel($d['viewing']));
            finSave($app);
            finMailOffice($app, 'Test drive requested: ' . finViewingLabel($d['viewing']));
            finFlash('success', 'Thank you — we confirm your test drive shortly.');
        }
        $to = 'viewing';
    } elseif (!$isFin && $action === 'accept' && $s === 'countered') {
        finLog($app, 'Accepted the suggested ' . paPercent($app) . '%');
        paApproved($app, 'client');
        finSave($app);
        finMailOffice($app, 'Client accepted ' . paPercent($app) . '%');
        finFlash('success', 'Approved at ' . paPercent($app) . '%. One last step: read your commitment letter and confirm below.');
        $to = 'confirm';
    } elseif (!$isFin && $action === 'confirm' && $s === 'approved') {
        $gate = [
            'id_number' => trim(mb_substr((string)($_POST['id_number'] ?? ''), 0, 20)),
            'car_reg'   => strtoupper(trim(mb_substr((string)($_POST['car_reg'] ?? ''), 0, 15))),
            'people'    => max(1, min(4, (int)($_POST['people'] ?? 1))),
        ];
        if (!preg_match('/^[A-Za-z0-9]{6,20}$/', $gate['id_number'])) $errors['id_number'] = 'Please enter your ID or passport number (for the security gate).';
        if (empty($_POST['understood'])) $errors['understood'] = 'Please confirm you have read and understood the commitment letter.';
        if (!$errors) {
            paConfirm($app, $gate);
            finSave($app);
            finFlash('success', 'You are in! Your commitment invoice is on this page and in your email. Pay within ' . FIN_DECISION_DAYS . ' days to lock the vehicle in your name.');
            $to = 'documents';
        }
    } elseif (!$isFin && $action === 'decision' && $s === 'paid' && empty($d['decision'])) {
        $choice = (string)($_POST['choice'] ?? '');
        if (in_array($choice, ['proceed', 'alternative', 'refund'], true)) {
            $d['decision'] = ['choice' => $choice, 'at' => date('Y-m-d H:i:s'), 'reason' => trim(mb_substr((string)($_POST['reason'] ?? ''), 0, 500))];
            if ($choice === 'refund') $app['status'] = 'refund';
            $label = ['proceed' => 'Going ahead — will pay the balance', 'alternative' => 'Switching to the alternative vehicle', 'refund' => 'Asked for the commitment back'][$choice];
            finLog($app, $label);
            finSave($app);
            finMailOffice($app, $label);
            finFlash('success', $choice === 'refund'
                ? 'Understood. Your commitment is refunded within 3–5 business days, less R' . number_format(PA_REFUND_FEE, 2) . ' for bank and administration charges.'
                : 'Thank you — our team will send the balance invoice and arrange handover.');
        }
        $to = 'decision';
    } elseif ($action === 'cancel' && in_array($s, ['quoted', 'chosen', 'invoiced', 'requested', 'countered', 'approved', 'confirmed'], true)
              && !($isFin && $s === 'approved')) {
        $app['status'] = (!$isFin && in_array($s, ['countered', 'approved'], true)) ? 'not_ready' : 'cancelled';
        $d['cancelled'] = ['at' => date('Y-m-d H:i:s'), 'reason' => trim(mb_substr((string)($_POST['reason'] ?? ''), 0, 500))];
        finLog($app, ($app['status'] === 'not_ready' ? 'Not ready to commit' : 'Cancelled') . ($d['cancelled']['reason'] !== '' ? ': ' . $d['cancelled']['reason'] : ''));
        finSave($app);
        finMailOffice($app, $app['status'] === 'not_ready' ? 'Client is not ready to commit' : 'Client cancelled');
        finFlash('info', 'Done — nothing is owed. You can still get a great deal at auction: <a href="' . APP_URL . '/login.php?tab=register">register</a>, view and bid.');
    }
    if (!$errors) { header('Location: ' . $back . ($to !== '' ? '#' . $to : '')); exit; }
}

$s       = $app['status'];
$step    = finStepIndex($app);
$closed  = finClosed($app);
$vehicle = finVehicle((int)$app['auction_id']);
$showVin = ($isFin && in_array($s, ['approved', 'completed'], true)) || (!$isFin && in_array($s, ['paid', 'completed'], true));
$vin     = $showVin ? vinForAuction((int)$app['auction_id']) : '';
$plan    = $isFin ? finChosenPlan($app) : null;
$bank    = getBankSettings();
$csrf    = e(finCsrfToken());
$first   = explode(' ', trim($app['name']))[0];
$img     = $d['vehicle']['image'] ?? ($vehicle['image_url'] ?? '');

$pageTitle = ($isFin ? 'My finance application' : 'My pre-auction request') . ' — ' . $app['vehicle'];
$pageDescription = 'Your private Auction Hub Group application page.';
$bodyClass = 'page-financing';
$noIndex = true;
require_once __DIR__ . '/header.php';
require __DIR__ . '/fin_ui.php';
?>
<section class="ap-hero" style="padding:2.4rem 1.25rem 2rem">
  <span class="ap-eyebrow"><?= $isFin ? '<i class="fas fa-handshake"></i> In-house finance' : '<i class="fas fa-bolt"></i> Pre-auction purchase' ?> · <?= e(finRef($app)) ?></span>
  <h1 style="font-size:clamp(1.6rem,3.6vw,2.3rem)">Hi <?= e($first) ?> — here is where it stands</h1>
  <p><?= e($app['vehicle']) ?> · <?= formatRand((float)$app['price']) ?></p>
</section>

<div class="ap-wrap">
  <?= finFlashHtml() ?>

  <?php if (!in_array($s, ['declined', 'cancelled', 'not_ready'], true)): ?>
  <ol class="ap-steps" aria-label="Progress">
    <?php foreach (finSteps($app['kind']) as $i => $label): ?>
      <li class="<?= $i < $step ? 'done' : ($i === $step ? 'now' : '') ?>"><b><?= $i < $step ? '✓' : $i + 1 ?></b><?= e($label) ?></li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>

  <div class="ap-grid">
    <div>
      <?php // ── Closed states ── ?>
      <?php if ($s === 'declined'): ?>
        <div class="ap-card"><h2>We could not approve this one</h2>
          <p>We are sorry — we are not able to approve this <?= $isFin ? 'finance application' : 'pre-auction request' ?> on this vehicle.</p>
          <?php if (!empty($d['declined']['reason'])): ?><div class="ap-note"><?= nl2br(e($d['declined']['reason'])) ?></div><?php endif; ?>
          <div class="ap-row"><a class="ap-btn ap-btn-out ap-btn-sm" href="<?= e(finDocLink($app, 'decline')) ?>" target="_blank"><i class="fas fa-file-lines"></i> My letter</a>
            <a class="ap-btn ap-btn-sm" href="<?= APP_URL ?>/auctions.php">See what's on auction</a>
            <a class="ap-btn ap-btn-out ap-btn-sm" href="<?= APP_URL ?>/<?= $isFin ? 'finance_apply.php' : 'preauction_request.php' ?>">Apply on another vehicle</a></div></div>
      <?php elseif (in_array($s, ['cancelled', 'not_ready'], true)): ?>
        <div class="ap-card"><h2><?= $s === 'not_ready' ? 'No problem — maybe next time' : 'Cancelled' ?></h2>
          <p>Nothing is owed. You can still get a great deal at auction: <a href="<?= APP_URL ?>/login.php?tab=register">register to bid</a> (the deposit is refundable), view on a preview day and bid.</p>
          <a class="ap-btn ap-btn-sm" href="<?= APP_URL ?>/auctions.php">Browse auctions</a></div>
      <?php endif; ?>

      <?php // ════════ FINANCE ════════ ?>
      <?php if ($isFin && !empty($d['quote'])): $q = $d['quote']; ?>
        <?php if (in_array($s, ['quoted', 'chosen'], true)): ?>
        <form class="ap-card" id="plans" method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="choose">
          <h2><?= !empty($q['counter']) ? 'Your updated quotation' : 'Choose your monthly plan' ?></h2>
          <?php if (!empty($q['counter'])): ?>
            <div class="ap-note">We could not approve your first deposit, but we <strong>can approve <?= formatRand((float)$q['deposit']) ?></strong>. Choose your plan at this deposit to go ahead.<?= $q['note'] !== '' ? '<br>' . nl2br(e($q['note'])) : '' ?>
              <br><a href="<?= e(finDocLink($app, 'counter')) ?>" target="_blank">Read the deposit letter</a></div>
          <?php endif; ?>
          <p class="ap-help" style="margin-bottom:.8rem">Deposit <?= formatRand((float)$q['deposit']) ?> · amount financed <strong><?= formatRand((float)$q['financed']) ?></strong>. Quotation <?= e($q['number']) ?>, valid until <?= date('j F Y', strtotime($q['valid'])) ?>.</p>
          <?php foreach ($q['plans'] as $p): $on = (int)($d['choice']['months'] ?? 0) === (int)$p['months']; ?>
            <label class="ap-plan<?= $on ? ' chosen' : '' ?>">
              <input type="radio" name="months" value="<?= (int)$p['months'] ?>"<?= $on ? ' checked' : '' ?> required>
              <span class="m"><?= (int)$p['months'] ?> months</span>
              <span class="ap-help" style="margin:0">Total <?= formatRand($p['total']) ?></span>
              <span class="p"><?= formatRand($p['monthly']) ?><small style="font-weight:600;color:#667"> /mo</small></span>
            </label>
          <?php endforeach; ?>
          <div class="ap-row" style="margin-top:.8rem">
            <button class="ap-btn ap-btn-green" type="submit"><i class="fas fa-check"></i> <?= $s === 'chosen' ? 'Change my plan' : 'Choose this plan' ?></button>
            <a class="ap-btn ap-btn-out ap-btn-sm" href="<?= e(finDocLink($app, 'quote')) ?>" target="_blank"><i class="fas fa-print"></i> View / print quotation</a>
          </div>
          <?php if ($s === 'chosen'): ?><p class="ap-help"><i class="fas fa-hourglass-half"></i> You chose <?= (int)$d['choice']['months'] ?> months. We are reviewing your deposit — your deposit invoice comes next.</p><?php endif; ?>
        </form>
        <?php endif; ?>

        <?php if (in_array($s, ['invoiced', 'approved', 'completed'], true)): ?>
        <div class="ap-card">
          <h2><?= $s === 'invoiced' ? 'Pay your deposit' : 'Your plan' ?></h2>
          <ul class="ap-kv">
            <li><span>Deposit</span><span><?= formatRand((float)$app['amount']) ?></span></li>
            <?php if ($plan): ?><li><span>Plan</span><span><?= (int)$plan['months'] ?> months × <?= formatRand($plan['monthly']) ?></span></li><?php endif; ?>
            <li><span>Amount financed</span><span><?= formatRand((float)$q['financed']) ?></span></li>
            <?php if ($s === 'invoiced'): ?>
              <li><span>Bank</span><span><?= e($bank['bank_name']) ?></span></li>
              <li><span>Account name</span><span><?= e($bank['bank_account_name']) ?></span></li>
              <li><span>Account number</span><span><?= e($bank['bank_account_no']) ?></span></li>
              <li><span>Branch code</span><span><?= e($bank['bank_branch_code']) ?></span></li>
              <li><span>Reference</span><span><?= e(finPayRef($app)) ?></span></li>
              <li><span>Please pay by</span><span><?= date('j F Y', strtotime($d['invoice']['due'])) ?></span></li>
            <?php endif; ?>
          </ul>
          <div class="ap-row" style="margin-top:.8rem">
            <?php if (!empty($d['invoice'])): ?><a class="ap-btn ap-btn-out ap-btn-sm" href="<?= e(finDocLink($app, 'invoice')) ?>" target="_blank"><i class="fas fa-file-invoice"></i> Deposit invoice</a><?php endif; ?>
            <a class="ap-btn ap-btn-out ap-btn-sm" href="<?= e(finDocLink($app, 'quote')) ?>" target="_blank"><i class="fas fa-print"></i> Quotation</a>
            <?php if ($s !== 'invoiced'): ?><a class="ap-btn ap-btn-out ap-btn-sm" href="<?= e(finDocLink($app, 'approval')) ?>" target="_blank"><i class="fas fa-award"></i> Approval letter</a><?php endif; ?>
          </div>
          <?php if ($s === 'invoiced'): ?><p class="ap-help"><i class="fas fa-shield-halved"></i> Only ever pay into the account on your invoice. We never change banking details by email or WhatsApp.</p><?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php // ════════ PRE-AUCTION ════════ ?>
      <?php if (!$isFin): $pc = paPercent($app); $commit = paCommitment($app); ?>
        <?php if (in_array($s, ['requested', 'countered', 'approved'], true)): ?>
        <div class="ap-card" id="decision">
          <h2><?= $s === 'requested' ? 'We are checking your request' : ($s === 'countered' ? 'We can approve ' . $pc . '%' : 'Approved at ' . $pc . '% — please confirm') ?></h2>
          <ul class="ap-kv">
            <li><span>Price</span><span><?= formatRand((float)$app['price']) ?></span></li>
            <li><span>Commitment <?= $s === 'requested' ? 'you asked for' : '' ?></span><span><?= $pc ?>% = <?= formatRand($commit) ?></span></li>
            <li><span>Balance after the test drive</span><span><?= formatRand((float)$app['price'] - $commit) ?></span></li>
            <?php if (!empty($d['viewing']['date'])): ?><li><span>Test drive wanted</span><span><?= e(finViewingLabel($d['viewing'])) ?></span></li><?php endif; ?>
            <?php if (!empty($d['alt'])): ?><li><span>Alternative</span><span><?= e($d['alt']['title']) ?></span></li><?php endif; ?>
          </ul>
          <?php if ($s === 'requested'): ?>
            <p class="ap-help"><i class="fas fa-hourglass-half"></i> We check your request with the financial institution and come back to you — usually the same business day. No invoice is sent before you confirm.</p>
          <?php elseif ($s === 'countered'): ?>
            <div class="ap-note">We cannot approve <?= (int)$app['percent'] ?>% on this vehicle, but we can approve <strong><?= $pc ?>%</strong>.<?= !empty($d['office_note']) ? '<br>' . nl2br(e($d['office_note'])) : '' ?></div>
            <form method="post" class="ap-row"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="accept">
              <button class="ap-btn ap-btn-green" type="submit"><i class="fas fa-check"></i> Accept <?= $pc ?>%</button></form>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($s === 'approved'): ?>
        <form class="ap-card" id="confirm" method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="confirm">
          <h2>Confirm you are going ahead</h2>
          <p>Please <a href="<?= e(finDocLink($app, 'letter')) ?>" target="_blank"><strong>read your commitment letter</strong></a> first. The commitment is mandatory to reserve the vehicle; your invoice is issued the moment you confirm.</p>
          <div class="ap-form">
            <div class="<?= isset($errors['id_number']) ? 'ap-err' : '' ?>"><label for="id_number">ID / passport number</label><input class="ap-input" id="id_number" name="id_number" required value="<?= e($_POST['id_number'] ?? '') ?>">
              <?= isset($errors['id_number']) ? '<span class="ap-err-text">' . e($errors['id_number']) . '</span>' : '' ?><span class="ap-help">For your gate pass at head office.</span></div>
            <div><label for="car_reg">Car you arrive in (reg) <span style="font-weight:400;color:#889">optional</span></label><input class="ap-input" id="car_reg" name="car_reg" value="<?= e($_POST['car_reg'] ?? '') ?>"></div>
            <div><label for="people">People coming (incl. you)</label><select class="ap-input" id="people" name="people"><?php for ($i = 1; $i <= 4; $i++): ?><option><?= $i ?></option><?php endfor; ?></select></div>
          </div>
          <label class="ap-check <?= isset($errors['understood']) ? 'ap-err' : '' ?>"><input type="checkbox" name="understood" value="1"><span>I have read the commitment letter. I pay the commitment of <?= formatRand($commit) ?> only into the account on my invoice; after the test drive I pay the balance or ask for my commitment back (less R<?= number_format(PA_REFUND_FEE, 2) ?>).</span></label>
          <?= isset($errors['understood']) ? '<span class="ap-err-text">' . e($errors['understood']) . '</span>' : '' ?>
          <button class="ap-btn ap-btn-green" type="submit" style="margin-top:.6rem"><i class="fas fa-file-invoice"></i> Confirm and get my invoice</button>
        </form>
        <?php endif; ?>

        <?php if (in_array($s, ['confirmed', 'paid', 'completed', 'refund'], true)): ?>
        <div class="ap-card">
          <h2><?= $s === 'confirmed' ? 'Pay your commitment' : 'Your purchase' ?></h2>
          <ul class="ap-kv">
            <li><span>Commitment (<?= (int)$app['percent'] ?>%)</span><span><?= formatRand((float)$app['amount']) ?></span></li>
            <li><span>Balance after the test drive</span><span><?= formatRand((float)$app['price'] - (float)$app['amount']) ?></span></li>
            <?php if ($s === 'confirmed'): ?>
              <li><span>Bank</span><span><?= e($bank['bank_name']) ?></span></li>
              <li><span>Account name</span><span><?= e($bank['bank_account_name']) ?></span></li>
              <li><span>Account number</span><span><?= e($bank['bank_account_no']) ?></span></li>
              <li><span>Branch code</span><span><?= e($bank['bank_branch_code']) ?></span></li>
              <li><span>Reference</span><span><?= e(finPayRef($app)) ?></span></li>
              <li><span>Please pay by</span><span><?= date('j F Y', strtotime($d['invoice']['due'])) ?></span></li>
            <?php endif; ?>
          </ul>
          <div class="ap-row" style="margin-top:.8rem">
            <a class="ap-btn ap-btn-out ap-btn-sm" href="<?= e(finDocLink($app, 'invoice')) ?>" target="_blank"><i class="fas fa-file-invoice"></i> Commitment invoice</a>
            <a class="ap-btn ap-btn-out ap-btn-sm" href="<?= e(finDocLink($app, 'letter')) ?>" target="_blank"><i class="fas fa-file-lines"></i> Commitment letter</a>
            <?php if ($s !== 'confirmed'): ?><a class="ap-btn ap-btn-out ap-btn-sm" href="<?= e(finDocLink($app, 'code')) ?>" target="_blank"><i class="fas fa-key"></i> Buyer's Code letter</a><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php // ── Buyer's Code + VIN ── ?>
      <?php if (!empty($app['buyer_code']) && !in_array($s, ['declined', 'cancelled', 'refund'], true)): ?>
      <div class="ap-card">
        <h2><i class="fas fa-key" style="color:#005BA1"></i> Your Buyer's Code</h2>
        <div class="ap-code"><?= e($app['buyer_code']) ?></div>
        <p class="ap-help">Your gate pass for the test drive at <?= e(COMPANY_ADDRESS) ?>. Unique to you — don't share it. No Buyer's Code, no entry.</p>
        <?php if ($showVin): ?>
          <ul class="ap-kv" style="margin-top:.6rem"><li><span>VIN</span><span><?= $vin !== '' ? e($vin) : 'Shown on site — on the vehicle itself' ?></span></li></ul>
          <p class="ap-help">Shared with you as a verified buyer under our <a href="<?= APP_URL ?>/vin-policy.php">VIN policy</a>. Check it against the plate on the vehicle.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php // ── Viewing / test drive ── ?>
      <?php if (($isFin && in_array($s, ['approved', 'completed'], true)) || (!$isFin && in_array($s, ['paid', 'completed'], true))): $v = $d['viewing'] ?? []; ?>
      <div class="ap-card" id="viewing">
        <h2>Your test drive</h2>
        <?php if (($v['status'] ?? '') === 'confirmed'): ?>
          <div class="ap-flash ap-flash-success" style="margin:0 0 .6rem"><i class="fas fa-calendar-check"></i> Confirmed: <?= e(finViewingLabel($v)) ?></div>
          <?php if (!empty($v['note'])): ?><p class="ap-help"><?= nl2br(e($v['note'])) ?></p><?php endif; ?>
          <p class="ap-help">At our head office, <?= e(COMPANY_ADDRESS) ?>. You have 1 hour 30 minutes. Bring your Buyer's Code, ID and driver's licence.</p>
        <?php elseif (!empty($v['date']) && in_array($v['status'] ?? '', ['requested', 'wanted'], true)): ?>
          <p><i class="fas fa-hourglass-half"></i> You asked for <strong><?= e(finViewingLabel($v)) ?></strong> — we confirm it shortly.</p>
        <?php endif; ?>
        <?php if ($isFin && $s === 'approved' && ($v['status'] ?? '') !== 'confirmed'): ?>
          <form method="post" class="ap-form" style="margin-top:.6rem">
            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="viewing">
            <div><label for="v-date">Day</label><input class="ap-input" id="v-date" type="date" name="date" required min="<?= date('Y-m-d', strtotime('tomorrow')) ?>" max="<?= date('Y-m-d', strtotime('+60 days')) ?>"></div>
            <div><label for="v-time">Time</label><select class="ap-input" id="v-time" name="time"><?php for ($h = 8; $h <= 15; $h++): ?><option><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?></select></div>
            <div class="full"><label for="v-note">Note <span style="font-weight:400;color:#889">optional</span></label><input class="ap-input" id="v-note" name="note"></div>
            <div class="full"><button class="ap-btn" type="submit"><i class="fas fa-calendar-plus"></i> <?= !empty($v['date']) ? 'Ask for another time' : 'Book my test drive' ?></button></div>
          </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php // ── Pre-auction decision after the test drive ── ?>
      <?php if (!$isFin && in_array($s, ['paid', 'refund', 'completed'], true)): ?>
      <div class="ap-card" id="decision">
        <h2>After your test drive</h2>
        <?php if (!empty($d['decision'])): ?>
          <p><strong><?= e(['proceed' => 'You are going ahead — we send the balance invoice and arrange handover.', 'alternative' => 'You are switching to your alternative vehicle — we will be in touch.', 'refund' => 'You asked for your commitment back. It is refunded within 3–5 business days, less R' . number_format(PA_REFUND_FEE, 2) . '.'][$d['decision']['choice']]) ?></strong></p>
        <?php else: ?>
          <p class="ap-help">Once you have inspected and driven it, let us know what you decide.</p>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="decision">
            <label class="ap-check"><input type="radio" name="choice" value="proceed" required><span><strong>I'm going ahead</strong> — send me the balance invoice of <?= formatRand((float)$app['price'] - (float)$app['amount']) ?></span></label>
            <?php if (!empty($d['alt'])): ?><label class="ap-check"><input type="radio" name="choice" value="alternative"><span><strong>Switch to my alternative</strong> — <?= e($d['alt']['title']) ?></span></label><?php endif; ?>
            <label class="ap-check"><input type="radio" name="choice" value="refund"><span><strong>Not for me</strong> — refund my commitment (less R<?= number_format(PA_REFUND_FEE, 2) ?>)</span></label>
            <input class="ap-input" name="reason" placeholder="Anything we should know? (optional)" style="margin:.4rem 0 .8rem">
            <button class="ap-btn" type="submit">Send my decision</button>
          </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php // ── Documents ── ?>
      <?php if (($isFin && in_array($s, ['invoiced', 'approved'], true)) || (!$isFin && in_array($s, ['confirmed', 'paid'], true))): ?>
      <div class="ap-card" id="documents">
        <h2><?= $isFin ? 'Your documents' : 'Proof of payment' ?></h2>
        <p class="ap-help" style="margin-bottom:.8rem">PDF or photos, up to 8 MB each. Stored privately — only you and our finance team can open them.</p>
        <?php foreach (finDocKinds($app) as $k): [$label, $hint, $max] = FIN_DOCS[$k]; $doc = $d['docs'][$k] ?? null; $st = $doc['status'] ?? ''; ?>
          <div class="ap-doc">
            <div class="ap-doc-head">
              <div><strong><?= e($label) ?></strong><div class="ap-help" style="margin:0"><?= e($hint) ?></div></div>
              <span class="ap-pill <?= ['pending' => 'ap-pill-wait', 'accepted' => 'ap-pill-ok', 'rejected' => 'ap-pill-bad'][$st] ?? 'ap-pill-none' ?>"><?= ['pending' => 'Checking', 'accepted' => 'Accepted', 'rejected' => 'Please upload again'][$st] ?? 'Needed' ?></span>
            </div>
            <?php if ($st === 'rejected' && !empty($doc['note'])): ?><div class="ap-note" style="margin:.5rem 0"><?= nl2br(e($doc['note'])) ?></div><?php endif; ?>
            <?php if (!empty($doc['files'])): ?><p class="ap-help"><?php foreach ($doc['files'] as $f): ?><a href="<?= APP_URL ?>/application_file.php?t=<?= e($app['token']) ?>&amp;f=<?= e($f['file']) ?>" target="_blank"><i class="fas fa-paperclip"></i> <?= e($f['original']) ?></a> &nbsp;<?php endforeach; ?></p><?php endif; ?>
            <?php if ($st !== 'accepted'): ?>
            <form method="post" enctype="multipart/form-data" class="ap-row" style="margin-top:.5rem">
              <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="doc" value="<?= e($k) ?>">
              <input type="file" name="files[]" accept=".pdf,image/jpeg,image/png,image/webp"<?= $max > 1 ? ' multiple' : '' ?> required style="font-size:.85rem;max-width:100%">
              <button class="ap-btn ap-btn-sm" type="submit"><i class="fas fa-upload"></i> Upload</button>
            </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!$closed && !in_array($s, ['paid'], true) && !($isFin && $s === 'approved')): ?>
        <details class="ap-card"><summary style="cursor:pointer;font-weight:700;color:#7a8794"><?= !$isFin && in_array($s, ['countered', 'approved'], true) ? 'Not ready to commit?' : 'Cancel this ' . ($isFin ? 'application' : 'request') ?></summary>
          <form method="post" style="margin-top:.7rem"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="cancel">
            <input class="ap-input" name="reason" placeholder="Tell us why (optional) — it helps us" style="margin-bottom:.6rem">
            <button class="ap-btn ap-btn-red ap-btn-sm" type="submit" onclick="return confirm('Cancel this? Nothing is owed.')">Yes, cancel</button></form></details>
      <?php endif; ?>
    </div>

    <aside>
      <div class="ap-card">
        <div class="ap-veh">
          <?php if ($img): ?><img src="<?= e(cldImg($img, CLD_THUMB)) ?>" alt="" loading="lazy"><?php endif; ?>
          <div><strong style="color:#003f73"><?= e($app['vehicle']) ?></strong>
            <div class="ap-help" style="margin:0"><?= e(implode(' · ', array_filter([$d['vehicle']['mileage'] ? number_format((int)$d['vehicle']['mileage']) . ' km' : '', $d['vehicle']['transmission'] ?? '', $d['vehicle']['fuel'] ?? '']))) ?></div>
            <?php if ($vehicle): ?><a class="ap-help" href="<?= APP_URL ?>/auction.php?id=<?= (int)$app['auction_id'] ?>">View the vehicle</a><?php endif; ?></div>
        </div>
        <ul class="ap-kv" style="margin-top:.8rem">
          <li><span>Reference</span><span><?= e(finRef($app)) ?></span></li>
          <li><span>Status</span><span><?= e(finStatusLabel($app)) ?></span></li>
          <li><span>Applied</span><span><?= safeDate('j M Y', $app['created_at']) ?></span></li>
        </ul>
      </div>
      <div class="ap-card">
        <h3>Talk to us</h3>
        <div class="ap-row">
          <a class="ap-btn ap-btn-wa ap-btn-sm" href="<?= e(finWhatsAppUrl($app)) ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> WhatsApp</a>
          <a class="ap-btn ap-btn-out ap-btn-sm" href="tel:<?= e(preg_replace('/\s/', '', COMPANY_PHONE)) ?>"><i class="fas fa-phone"></i> <?= e(COMPANY_PHONE) ?></a>
        </div>
        <p class="ap-help">Keep this page's link private — anyone with it can see your application.</p>
      </div>
      <?php if (!empty($d['log'])): ?>
      <div class="ap-card"><h3>History</h3><ul class="ap-log">
        <?php foreach (array_reverse($d['log']) as $l): ?><li><?= safeDate('j M, H:i', $l['at']) ?> — <?= e($l['what']) ?></li><?php endforeach; ?>
      </ul></div>
      <?php endif; ?>
    </aside>
  </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
