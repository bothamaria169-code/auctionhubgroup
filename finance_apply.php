<?php
// ============================================================
// AUCTION HUB GROUP — finance_apply.php
// The full Finance application (financing.php → "Apply Now").
// Five steps on one page (works without JavaScript too):
//   1 Vehicle & deposit  2 About you  3 Work & income
//   4 Expenses & credit  5 Your result, terms & consent
// Step 5 shows the live assessment (finance_check.php → finAssess):
// score out of 100, grade, the start-up deposit needed for approval and
// which repayment terms fit the budget. On submit the application is
// saved, scored, quoted and emailed; the client continues on
// my_application.php (choose term → documents → Buyer Code).
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
$startStep = 1;
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim($_POST['website'] ?? '') !== '') { header('Location: finance_apply.php?sent=1'); exit; }   // honeypot
    [$f, $in, $errors, $startStep] = finReadForm($_POST, true);
    $deposit = finMoneyInput((string)($_POST['deposit'] ?? ''));
    if (!$vehicle)                                       { $errors['auction_id'] = 'Please choose the vehicle you would like to finance.'; $startStep = 1; }
    elseif ($why = finDepositError($vehicle, $deposit))  { $errors['deposit'] = $why; $startStep = 1; }
    foreach (['consent_credit' => 'Please give consent for the credit and affordability check — we cannot assess your application without it.',
              'consent_popia'  => 'Please agree to how we use your information.',
              'agree_terms'    => 'Please accept the finance terms.',
              'confirm_true'   => 'Please confirm that your information is true and complete.'] as $k => $msg) {
        if (empty($_POST[$k])) { $errors[$k] = $msg; if (!$startStep || $startStep > 5) $startStep = 5; }
    }
    if (!$startStep) $startStep = 5;
    if (!finCsrfOk()) $errors['form'] = 'Your session expired — please send the form again.';
    if (!$errors && enquiryRateLimited(6, 900)) $errors['form'] = 'You have sent several applications already — we will be in touch. For anything urgent call ' . COMPANY_PHONE . '.';

    if (!$errors) {
        try {
            $name = trim($f['first_names'] . ' ' . $f['surname']);
            $app = finCreate('finance', ['name' => $name, 'email' => $f['email'], 'phone' => $f['phone'], 'user_id' => $pageUser['sub'] ?? null], $vehicle, $deposit, null, [
                'applicant'  => array_intersect_key($f, array_flip(['title', 'first_names', 'surname', 'id_type', 'id_number', 'dob', 'gender', 'age', 'marital',
                                                                  'dependants', 'address', 'postal_code', 'residence', 'years_address'])),
                'work'       => array_intersect_key($f, array_flip(['employment', 'employer', 'job_title', 'employer_phone', 'months_employed', 'pay_day', 'gross_income', 'net_income', 'other_income'])),
                'bank'       => ['name' => $f['bank_name'], 'years' => $f['bank_years']],
                'finances'   => $in,
                'consent'    => ['credit_check' => true, 'popia' => true, 'terms' => true, 'true' => true, 'at' => date('Y-m-d H:i:s'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null],
                'id_number'  => $f['id_number'],
                'address'    => $f['address'] . ($f['postal_code'] !== '' ? ', ' . $f['postal_code'] : ''),
                'employment' => FIN_EMPLOYMENT[$f['employment']],
                'income'     => $f['net_income'],
                'message'    => trim(mb_substr((string)($_POST['message'] ?? ''), 0, 2000)),
                'assessment' => finAssess($in, finPrice($vehicle), $deposit, $vehicle),
            ]);
            finQuoteFromAssessment($app, $deposit);
            $as = $app['details']['assessment'];
            $q  = $app['details']['quote'];
            finLog($app, 'Applied online — score ' . $as['score'] . '/100 (' . $as['grade'] . '), quote ' . $q['number']
                . (!empty($q['startup']) ? ' at a start-up deposit of ' . formatRand($q['deposit']) : ''));
            finSendQuote($app);
            finSave($app);
            finMailOffice($app, $app['status'] === 'review'
                ? 'New Finance Application — REFERRED (score ' . $as['score'] . '/100): please call the client'
                : 'New Finance Application — score ' . $as['score'] . '/100, grade ' . $as['grade']);
            finFlash('success', $app['status'] === 'review'
                ? 'Thank you — your application is in. Our finance team will call you within one business day to discuss your options (we have emailed you the details).'
                : 'Your result and quote have been emailed to <strong>' . e($f['email']) . '</strong>. Choose your repayment term below.');
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
$opt = function (array $list, string $k, string $def = '', bool $assoc = true): string {
    global $old;
    $cur = (string)($old[$k] ?? $def);
    $h = '';
    foreach ($list as $v => $l) { if (!$assoc) $v = $l; $h .= '<option value="' . e((string)$v) . '"' . ($cur === (string)$v ? ' selected' : '') . '>' . e($l) . '</option>'; }
    return $h;
};
$opt2 = fn(array $list, string $k, string $def = '') => $opt($list, $k, $def, false);
$chk = fn(string $k) => !empty($old[$k]) ? ' checked' : '';
$minStd = (float)finSetting('fin_min_deposit', '10500');
$rules = finRules();
$opt_ = fn(string $k) => '<span style="font-weight:400;color:#889">optional</span>';

$pageTitle       = 'Apply for Vehicle Finance — Instant Assessment';
$pageDescription = 'Apply online for Auction Hub Group vehicle finance. Complete one application, see your finance score and the deposit you need for approval, and get your quote instantly.';
$bodyClass       = 'page-financing';
require_once __DIR__ . '/header.php';
require __DIR__ . '/fin_ui.php';

finHero('<i class="fas fa-file-signature"></i> Finance Application', 'Apply for Finance',
    'One application, five short steps. You see your <strong style="color:#fff">finance score</strong> and the deposit you need for approval before you submit — then your quote is ready instantly.',
    '<a href="' . APP_URL . '/financing.php">Finance</a><span class="sep">/</span>Apply',
    ['About 7 minutes', 'Instant score & quote', 'Low score? Qualify with a start-up deposit', '6–' . (max(array_keys(finTiers(['body_type' => 'truck'])))) . ' month terms']);
?>
<style>
.fw-nav { display:flex; gap:.4rem; margin:0 0 1.25rem; padding:0; list-style:none; counter-reset:fw; }
.fw-nav li { flex:1; text-align:center; font-size:.72rem; font-weight:700; color:#8a9bb0; text-transform:uppercase; letter-spacing:.05em; counter-increment:fw; cursor:default; }
.fw-nav li::before { content:counter(fw); display:flex; align-items:center; justify-content:center; width:32px; height:32px; margin:0 auto .35rem; border-radius:50%; background:#e5ecf4; color:#6a7f96; font-family:'Barlow Condensed',sans-serif; font-size:1rem; }
.fw-nav li.done::before { content:'\2713'; background:#22c55e; color:#fff; }
.fw-nav li.now { color:#003f73; }
.fw-nav li.now::before { background:linear-gradient(135deg,#003f73,#005BA1); color:#fff; box-shadow:0 4px 14px rgba(0,63,115,.25); }
.fw-nav li.done { cursor:pointer; color:#1a9e4e; }
@media(max-width:600px){ .fw-nav li { font-size:0; } .fw-nav li.now { font-size:.68rem; } }
.fw-step h3 { font-family:'Barlow Condensed',sans-serif; color:#003f73; font-size:1.35rem; margin:0 0 .25rem; }
.fw-step .ap-sub { margin-bottom:1rem; }
.fw-js .fw-step { display:none; }
.fw-js .fw-step.on { display:block; animation:fwIn .25s ease; }
@keyframes fwIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
.fw-step + .fw-step { border-top:1px dashed #dbe4ee; padding-top:1.25rem; margin-top:1.25rem; }
.fw-js .fw-step + .fw-step { border-top:none; padding-top:0; margin-top:0; }
.fw-btns { display:flex; gap:.6rem; justify-content:space-between; flex-wrap:wrap; margin-top:1.25rem; }
.fw-btns .btn { min-width:140px; }
.fw-hide { display:none !important; }
.fw-money { position:relative; }
.fw-money::before { content:'R'; position:absolute; left:.8rem; top:50%; transform:translateY(-50%); color:#889; font-weight:700; pointer-events:none; }
.fw-money .form-control { padding-left:1.8rem; }
.fw-yn { display:flex; gap:.5rem; }
.fw-yn label { flex:1; border:1.5px solid #dbe4ee; border-radius:10px; padding:.55rem .8rem; cursor:pointer; font-weight:600; font-size:.88rem; display:flex; gap:.45rem; align-items:center; }
.fw-yn input { accent-color:#005BA1; }
.fw-budget { background:linear-gradient(135deg,#f0f6fc,#e8f4fd); border:1.5px solid #b8d4ee; border-radius:12px; padding:.5rem 1rem; margin:.25rem 0 1rem; }
.fw-terms { max-height:260px; overflow:auto; border:1.5px solid #e0e8f0; border-radius:12px; padding:1rem 1.2rem; font-size:.84rem; color:#445; line-height:1.65; background:#fbfdff; margin-bottom:1rem; }
.fw-terms h4 { font-size:.9rem; color:#003f73; margin:.8rem 0 .3rem; }
.fw-terms h4:first-child { margin-top:0; }
.fw-terms ol { margin:0; padding-left:1.2rem; }
</style>

<section class="ap-section"><div class="ap-container">
  <?= finFlashHtml() ?>
  <?php if (!empty($errors['form'])): ?><div class="alert alert-error"><i class="fas fa-circle-xmark"></i><span><?= e($errors['form']) ?></span></div><?php endif; ?>
  <?php if ($errors && empty($errors['form'])): ?><div class="alert alert-error"><i class="fas fa-circle-xmark"></i><span>Please check the highlighted fields.</span></div><?php endif; ?>
  <?php if (isset($_GET['sent'])): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span>Thank you — our finance team will be in touch.</span></div><?php endif; ?>

  <div class="ap-grid">
    <form class="ap-card" id="fw" method="post" novalidate data-start="<?= (int)$startStep ?>">
      <div class="ap-card-head"><i class="fas fa-file-signature"></i> Finance Application</div>
      <div class="ap-card-body">
        <input type="hidden" name="csrf" value="<?= e(finCsrfToken()) ?>">
        <div class="ap-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
        <ol class="fw-nav" id="fw-nav" style="display:none"><li>Vehicle</li><li>About You</li><li>Income</li><li>Expenses &amp; Credit</li><li>Result</li></ol>

        <!-- 1 Vehicle & deposit -->
        <div class="fw-step" data-step="1">
          <h3>1. Vehicle &amp; Deposit</h3>
          <p class="ap-sub">Choose the vehicle and the deposit you can pay. If your assessment needs a start-up deposit you will see it before you submit.</p>
          <?php if ($notEligible): ?><div class="alert alert-warning"><i class="fas fa-triangle-exclamation"></i><span>That vehicle has already received a bid or is Presold, so it can no longer be financed — please choose another.</span></div><?php endif; ?>
          <div class="ap-form">
            <div class="full <?= $grp('auction_id') ?>"><label class="form-label" for="ap-vehicle">Vehicle <span class="required">*</span></label>
              <?= finVehicleSelect($lots, $vehicle) ?><?= $err('auction_id') ?>
              <span class="ap-help" id="ap-vehicle-help"><?= count($lots) ?> vehicles available — finance applies until a vehicle receives its first bid.</span></div>
            <div class="<?= $grp('deposit') ?>"><label class="form-label" for="deposit">Deposit you can pay <span class="required">*</span></label>
              <div class="fw-money"><input class="form-control" id="deposit" name="deposit" inputmode="decimal" required value="<?= $val('deposit', $vehicle ? (string)(int)finMinDeposit($vehicle) : '') ?>" placeholder="<?= (int)$minStd ?>"></div><?= $err('deposit') ?>
              <span class="ap-help" id="ap-deposit-help">Minimum <?= e(formatRand($minStd)) ?> — more deposit lowers your instalment and raises your score.</span></div>
          </div>
        </div>

        <!-- 2 About you -->
        <div class="fw-step" data-step="2">
          <h3>2. About You</h3>
          <p class="ap-sub">As on your ID document. Your ID number confirms your age — we never show it publicly.</p>
          <div class="ap-form">
            <div class="<?= $grp('title') ?>"><label class="form-label" for="title">Title <span class="required">*</span></label>
              <select class="form-control" id="title" name="title" required><option value="">Choose…</option><?= $opt2(FIN_TITLES, 'title') ?></select><?= $err('title') ?></div>
            <div class="<?= $grp('marital') ?>"><label class="form-label" for="marital">Marital status <span class="required">*</span></label>
              <select class="form-control" id="marital" name="marital" required><option value="">Choose…</option><?= $opt(FIN_MARITAL, 'marital') ?></select><?= $err('marital') ?></div>
            <div class="<?= $grp('first_names') ?>"><label class="form-label" for="first_names">First name(s) <span class="required">*</span></label>
              <input class="form-control" id="first_names" name="first_names" autocomplete="given-name" required value="<?= $val('first_names', explode(' ', (string)($pageUser['full_name'] ?? ''))[0]) ?>"><?= $err('first_names') ?></div>
            <div class="<?= $grp('surname') ?>"><label class="form-label" for="surname">Surname <span class="required">*</span></label>
              <input class="form-control" id="surname" name="surname" autocomplete="family-name" required value="<?= $val('surname', trim(strstr((string)($pageUser['full_name'] ?? ''), ' ') ?: '')) ?>"><?= $err('surname') ?></div>
            <div class="form-group"><label class="form-label" for="id_type">Identity document</label>
              <select class="form-control" id="id_type" name="id_type"><?= $opt(['sa' => 'South African ID', 'passport' => 'Passport'], 'id_type', 'sa') ?></select></div>
            <div class="<?= $grp('id_number') ?>"><label class="form-label" for="id_number"><span id="id-label">ID number</span> <span class="required">*</span></label>
              <input class="form-control" id="id_number" name="id_number" required inputmode="numeric" autocomplete="off" value="<?= $val('id_number') ?>"><?= $err('id_number') ?>
              <span class="ap-help" id="id-help"></span></div>
            <div class="<?= $grp('dob', 'fw-passport') ?>"><label class="form-label" for="dob">Date of birth <span class="required">*</span></label>
              <input class="form-control" id="dob" type="date" name="dob" max="<?= date('Y-m-d', strtotime('-18 years')) ?>" value="<?= $val('dob') ?>"><?= $err('dob') ?></div>
            <div class="form-group"><label class="form-label" for="dependants">Dependants <span style="font-weight:400;color:#889">people you support</span></label>
              <select class="form-control" id="dependants" name="dependants"><?php for ($i = 0; $i <= 10; $i++): ?><option<?= (int)($old['dependants'] ?? 0) === $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
            <div class="<?= $grp('phone') ?>"><label class="form-label" for="phone">Cellphone <span class="required">*</span></label>
              <input class="form-control" id="phone" type="tel" name="phone" autocomplete="tel" required value="<?= $val('phone') ?>"><?= $err('phone') ?></div>
            <div class="<?= $grp('email') ?>"><label class="form-label" for="email">Email <span class="required">*</span></label>
              <input class="form-control" id="email" type="email" name="email" autocomplete="email" required value="<?= $val('email', $pageUser['email'] ?? '') ?>"><?= $err('email') ?></div>
            <div class="full <?= $grp('address') ?>"><label class="form-label" for="address">Residential address <span class="required">*</span></label>
              <input class="form-control" id="address" name="address" autocomplete="street-address" required placeholder="Street, suburb, town" value="<?= $val('address') ?>"><?= $err('address') ?></div>
            <div class="form-group"><label class="form-label" for="postal_code">Postal code</label>
              <input class="form-control" id="postal_code" name="postal_code" inputmode="numeric" maxlength="4" autocomplete="postal-code" value="<?= $val('postal_code') ?>"></div>
            <div class="<?= $grp('residence') ?>"><label class="form-label" for="residence">Where you live <span class="required">*</span></label>
              <select class="form-control" id="residence" name="residence" required><option value="">Choose…</option><?= $opt(FIN_RESIDENCE, 'residence') ?></select><?= $err('residence') ?></div>
            <div class="form-group"><label class="form-label" for="years_address">Years at this address</label>
              <input class="form-control" id="years_address" name="years_address" type="number" min="0" max="80" step="0.5" value="<?= $val('years_address', '0') ?>"></div>
          </div>
        </div>

        <!-- 3 Work & income -->
        <div class="fw-step" data-step="3">
          <h3>3. Work &amp; Income</h3>
          <p class="ap-sub">Use the figures on your latest payslip or bank statement — we check them against your documents.</p>
          <div class="ap-form">
            <div class="full <?= $grp('employment') ?>"><label class="form-label" for="employment">Employment status <span class="required">*</span></label>
              <select class="form-control" id="employment" name="employment" required><option value="">Choose…</option><?= $opt(FIN_EMPLOYMENT, 'employment') ?></select><?= $err('employment') ?></div>
            <div class="<?= $grp('employer', 'fw-job') ?>"><label class="form-label" for="employer"><span id="employer-label">Employer</span> <span class="required">*</span></label>
              <input class="form-control" id="employer" name="employer" autocomplete="organization" value="<?= $val('employer') ?>"><?= $err('employer') ?></div>
            <div class="form-group fw-job"><label class="form-label" for="job_title">Job title / occupation</label>
              <input class="form-control" id="job_title" name="job_title" autocomplete="organization-title" value="<?= $val('job_title') ?>"></div>
            <div class="form-group fw-job"><label class="form-label">Time in this job / business</label>
              <div style="display:flex;gap:.5rem">
                <select class="form-control" name="emp_years" aria-label="Years"><?php for ($i = 0; $i <= 40; $i++): ?><option value="<?= $i ?>"<?= (int)($old['emp_years'] ?? 0) === $i ? ' selected' : '' ?>><?= $i ?> yr<?= $i === 1 ? '' : 's' ?></option><?php endfor; ?></select>
                <select class="form-control" name="emp_months" aria-label="Months"><?php for ($i = 0; $i <= 11; $i++): ?><option value="<?= $i ?>"<?= (int)($old['emp_months'] ?? 0) === $i ? ' selected' : '' ?>><?= $i ?> mth<?= $i === 1 ? '' : 's' ?></option><?php endfor; ?></select>
              </div></div>
            <div class="form-group fw-job"><label class="form-label" for="employer_phone">Work phone <?= $opt_('') ?></label>
              <input class="form-control" id="employer_phone" type="tel" name="employer_phone" value="<?= $val('employer_phone') ?>"></div>
            <div class="<?= $grp('pay_day') ?>"><label class="form-label" for="pay_day">When are you paid? <span class="required">*</span></label>
              <select class="form-control" id="pay_day" name="pay_day" required><option value="">Choose…</option><?= $opt2(FIN_PAY_DAYS, 'pay_day') ?></select><?= $err('pay_day') ?>
              <span class="ap-help">Your debit order runs on your pay day.</span></div>
            <div class="form-group"><label class="form-label" for="gross_income">Gross monthly income <?= $opt_('') ?></label>
              <div class="fw-money"><input class="form-control" id="gross_income" name="gross_income" inputmode="decimal" value="<?= $val('gross_income') ?>" placeholder="Before tax"></div></div>
            <div class="<?= $grp('net_income') ?>"><label class="form-label" for="net_income">Take-home pay <span class="required">*</span></label>
              <div class="fw-money"><input class="form-control" id="net_income" name="net_income" inputmode="decimal" required value="<?= $val('net_income') ?>" placeholder="After tax &amp; deductions"></div><?= $err('net_income') ?></div>
            <div class="form-group"><label class="form-label" for="other_income">Other monthly income <?= $opt_('') ?></label>
              <div class="fw-money"><input class="form-control" id="other_income" name="other_income" inputmode="decimal" value="<?= $val('other_income') ?>" placeholder="Rental, maintenance, side business"></div>
              <span class="ap-help">Only income that reaches your bank account every month.</span></div>
          </div>
        </div>

        <!-- 4 Expenses & credit -->
        <div class="fw-step" data-step="4">
          <h3>4. Expenses &amp; Credit Profile</h3>
          <p class="ap-sub">Be honest — an instalment you can comfortably afford is the whole point. We use the higher of your living costs or a minimum living-cost norm (<?= e(formatRand($rules['norm_base'])) ?> + <?= e(formatRand($rules['norm_dep'])) ?> per dependant).</p>
          <div class="ap-form">
            <div class="form-group"><label class="form-label" for="exp_housing">Rent / bond</label>
              <div class="fw-money"><input class="form-control" id="exp_housing" name="exp_housing" inputmode="decimal" value="<?= $val('exp_housing', '0') ?>"></div></div>
            <div class="<?= $grp('exp_living') ?>"><label class="form-label" for="exp_living">Living costs <span class="required">*</span></label>
              <div class="fw-money"><input class="form-control" id="exp_living" name="exp_living" inputmode="decimal" required value="<?= $val('exp_living') ?>" placeholder="Food, transport, school, airtime"></div><?= $err('exp_living') ?></div>
            <div class="form-group"><label class="form-label" for="exp_debt">Loan &amp; account repayments</label>
              <div class="fw-money"><input class="form-control" id="exp_debt" name="exp_debt" inputmode="decimal" value="<?= $val('exp_debt', '0') ?>" placeholder="Car, personal loans, store cards"></div></div>
            <div class="form-group"><label class="form-label" for="exp_other">Other fixed costs</label>
              <div class="fw-money"><input class="form-control" id="exp_other" name="exp_other" inputmode="decimal" value="<?= $val('exp_other', '0') ?>" placeholder="Insurance, policies, child support"></div></div>
            <div class="full fw-budget" id="fw-budget" style="display:none">
              <div class="ap-rowline"><span class="lbl">Income</span><span class="val" id="fb-in">—</span></div>
              <div class="ap-rowline fee"><span class="lbl">Expenses (incl. living-cost norm)</span><span class="val" id="fb-out">—</span></div>
              <div class="ap-rowline big"><span class="lbl">Left over each month</span><span class="val" id="fb-free">—</span></div>
            </div>
            <div class="<?= $grp('bank_name') ?>"><label class="form-label" for="bank_name">Your bank <span class="required">*</span></label>
              <select class="form-control" id="bank_name" name="bank_name" required><option value="">Choose…</option><?= $opt2(FIN_BANKS, 'bank_name') ?></select><?= $err('bank_name') ?></div>
            <div class="form-group"><label class="form-label" for="bank_years">Years with this bank</label>
              <input class="form-control" id="bank_years" name="bank_years" type="number" min="0" max="80" step="0.5" value="<?= $val('bank_years', '0') ?>"></div>
            <div class="full fp-scorebox">
              <strong><i class="fas fa-magnifying-glass-chart"></i> Know your credit score?</strong>
              <span class="ap-help" style="display:block;margin:.2rem 0 0">Standard approval needs <strong><?= (int)$rules['min_credit'] ?>+</strong> on TransUnion's 0–999 scale. Not sure of yours? Check it free — it opens in a new tab, so your answers here stay.</span>
              <?= finScoreCheckLinks() ?>
            </div>
            <div class="form-group"><label class="form-label" for="credit_band">Your credit score</label>
              <select class="form-control" id="credit_band" name="credit_band"><?= $opt(array_map(fn($b) => $b[0], FIN_CREDIT_BANDS), 'credit_band', 'unknown') ?></select>
</div>
            <div class="form-group"><label class="form-label" for="credit_score">Exact score, if you know it <?= $opt_('') ?></label>
              <input class="form-control" id="credit_score" name="credit_score" type="number" min="100" max="999" value="<?= $val('credit_score') ?>" placeholder="e.g. 640"></div>
            <div class="form-group"><label class="form-label">Under debt review / administration?</label>
              <div class="fw-yn"><label><input type="radio" name="debt_review" value="no"<?= ($old['debt_review'] ?? 'no') !== 'yes' ? ' checked' : '' ?>> No</label><label><input type="radio" name="debt_review" value="yes"<?= ($old['debt_review'] ?? '') === 'yes' ? ' checked' : '' ?>> Yes</label></div></div>
            <div class="form-group"><label class="form-label">Judgments or blacklisting?</label>
              <div class="fw-yn"><label><input type="radio" name="judgments" value="no"<?= ($old['judgments'] ?? 'no') !== 'yes' ? ' checked' : '' ?>> No</label><label><input type="radio" name="judgments" value="yes"<?= ($old['judgments'] ?? '') === 'yes' ? ' checked' : '' ?>> Yes</label></div></div>
          </div>
          <p class="ap-help"><i class="fas fa-circle-info"></i> Debt review, judgments or a low score do not rule you out — they may mean a start-up deposit for approval.</p>
        </div>

        <!-- 5 Result, terms & consent -->
        <div class="fw-step" data-step="5">
          <h3>5. Your Result, Terms &amp; Consent</h3>
          <div id="fw-result" class="ap-card" style="box-shadow:none;margin-bottom:1rem;display:none"><div class="ap-card-body" id="fw-result-body"></div></div>
          <div class="fw-terms" tabindex="0" aria-label="Finance terms">
            <h4>Key terms of Auction Hub Group finance</h4>
            <ol>
              <li><strong>Assessment.</strong> We assess every application for affordability and credit risk, as the National Credit Act 34 of 2005 requires. Your score is worked out from your affordability, credit profile, employment, deposit and stability. A credit bureau score we obtain with your consent replaces your own estimate and can change your result.</li>
              <li><strong>Start-up deposit.</strong> If your score or budget does not meet our criteria for the standard minimum deposit (<?= e(formatRand($minStd)) ?>), approval is conditional on the start-up deposit shown in your result. It forms part of the purchase price — it is not an extra fee.</li>
              <li><strong>Affordability.</strong> We only offer repayment terms whose instalment fits your budget: at most <?= (int)$rules['afford_pct'] ?>% of your monthly income, and within what is left after your expenses.</li>
              <li><strong>Quote.</strong> Your quote is valid for 7 days and subject to the vehicle still being available — finance ends when a vehicle receives its first auction bid. Figures are indicative until your agreement is signed.</li>
              <li><strong>Documents.</strong> Approval is subject to your SA ID / passport, proof of address (not older than 3 months), 3 months' bank statements, latest payslip or proof of income, and driver's licence matching what you declared.</li>
              <li><strong>Instalment sale.</strong> The vehicle remains the property of Auction Hub Group until it is paid in full. It must be comprehensively insured for the full term with Auction Hub Group noted as the owner, and a tracking device may be required.</li>
              <li><strong>Payments.</strong> Instalments are collected by debit order on your pay day. Missed payments are charged as arrears and may, after notice, lead to the vehicle being repossessed in line with the National Credit Act. You may settle early at any time.</li>
              <li><strong>False information.</strong> Giving false or incomplete information is fraud; it cancels your application or agreement.</li>
              <li><strong>Your information.</strong> Under the Protection of Personal Information Act we use your information only to assess and run your finance, share it only with credit bureaus and service providers who need it for that, and keep it securely.</li>
            </ol>
            <p style="margin:.8rem 0 0">The full terms are in your instalment sale agreement, which you sign before you drive away. See also our <a href="<?= APP_URL ?>/vin-policy.php" target="_blank">VIN policy</a>.</p>
          </div>
          <div class="<?= isset($errors['consent_credit']) ? 'ap-err' : '' ?>"><label class="ap-check"><input type="checkbox" name="consent_credit" value="1"<?= $chk('consent_credit') ?>><span>I consent to Auction Hub Group doing a <strong>credit and affordability check</strong>, including an enquiry with a registered credit bureau, to assess this application.</span></label><?= $err('consent_credit') ?></div>
          <div class="<?= isset($errors['consent_popia']) ? 'ap-err' : '' ?>"><label class="ap-check"><input type="checkbox" name="consent_popia" value="1"<?= $chk('consent_popia') ?>><span>I agree that my personal information is used as described above (POPIA).</span></label><?= $err('consent_popia') ?></div>
          <div class="<?= isset($errors['agree_terms']) ? 'ap-err' : '' ?>"><label class="ap-check"><input type="checkbox" name="agree_terms" value="1"<?= $chk('agree_terms') ?>><span>I have read and accept the key finance terms, including the start-up deposit if my result requires one.</span></label><?= $err('agree_terms') ?></div>
          <div class="<?= isset($errors['confirm_true']) ? 'ap-err' : '' ?>"><label class="ap-check"><input type="checkbox" name="confirm_true" value="1"<?= $chk('confirm_true') ?>><span>The information I have given is true and complete.</span></label><?= $err('confirm_true') ?></div>
          <div class="form-group" style="margin-top:.75rem"><label class="form-label" for="message">Anything else we should know? <?= $opt_('') ?></label>
            <textarea class="form-control" id="message" name="message" rows="2"><?= $val('message') ?></textarea></div>
          <button class="btn btn-primary btn-lg btn-block" type="submit" id="fw-submit"><i class="fas fa-paper-plane"></i> Submit My Application</button>
        </div>

        <div class="fw-btns" id="fw-btns" style="display:none">
          <button type="button" class="btn btn-outline" id="fw-back"><i class="fas fa-arrow-left"></i> Back</button>
          <button type="button" class="btn btn-primary" id="fw-next">Next <i class="fas fa-arrow-right"></i></button>
        </div>
        <p class="ap-help" style="text-align:center;margin-top:1rem">Already applied? <a href="<?= APP_URL ?>/my_applications.php">Track my application</a></p>
      </div>
    </form>

    <aside>
      <div class="ap-card">
        <div class="ap-card-head"><i class="fas fa-chart-simple"></i> How We Score You</div>
        <div class="ap-card-body">
          <?php foreach ([['Affordability', 30, 'Does the instalment fit your budget?'], ['Credit profile', 25, 'Your credit score, debt review, judgments'], ['Employment', 20, 'Type of income and time in the job'], ['Deposit', 15, 'More deposit, better score'], ['Stability', 10, 'Time at your address and bank']] as [$l, $m, $h]): ?>
            <div class="ap-rowline"><span class="lbl"><strong style="color:#003f73"><?= $l ?></strong><br><small><?= $h ?></small></span><span class="val"><?= $m ?> pts</span></div>
          <?php endforeach; ?>
          <div style="margin-top:.9rem">
            <?php foreach ([['A', $rules['score_a'] . '+', 'Approved in principle — ' . formatRand($minStd) . ' minimum deposit, credit score ' . (int)$rules['min_credit'] . '+'],
                            ['B', (int)$rules['score_b'] . '–' . ((int)$rules['score_a'] - 1), 'Start-up deposit from ' . (int)$rules['startup_b'] . '% of the price'],
                            ['C', (int)$rules['score_c'] . '–' . ((int)$rules['score_b'] - 1), 'Start-up deposit from ' . (int)$rules['startup_c'] . '%'],
                            ['D', 'below ' . (int)$rules['score_c'], 'Start-up deposit from ' . (int)$rules['startup_d'] . '%']] as [$g, $r, $t]): ?>
              <div style="display:flex;gap:.6rem;align-items:center;margin:.35rem 0;font-size:.84rem"><span class="fr-grade" style="background:<?= finGradeColour($g) ?>;margin:0;min-width:2.2rem;text-align:center"><?= $g ?></span><span><strong><?= e($r) ?></strong> · <?= e($t) ?></span></div>
            <?php endforeach; ?>
          </div>
          <p class="ap-help">The start-up deposit is also raised if needed so your instalment fits your budget.</p>
        </div>
      </div>
      <div class="ap-faq">
        <span class="ap-label"><i class="fas fa-circle-question"></i> Quick Questions</span>
        <details><summary>I have a low score or I am blacklisted — can I apply?</summary><p>Yes. A low score, judgments or debt review do not rule you out — they usually mean a start-up deposit for approval. You see the exact amount before you submit.</p></details>
        <details><summary>Is the start-up deposit an extra cost?</summary><p>No — it is part of the vehicle price, so you finance less and your instalments are lower.</p></details>
        <details><summary>Will this affect my credit record?</summary><p>An enquiry is recorded when we check your credit with a bureau. Checking your own score (free once a year from each bureau) never affects it.</p></details>
        <details><summary>What if the vehicle receives a bid?</summary><p>Finance applies until a vehicle's first bid. After that it goes to the auction floor — you are welcome to <a href="<?= APP_URL ?>/login.php?tab=register">register and bid</a>.</p></details>
      </div>
      <div class="ap-card" style="margin-top:1.25rem">
        <div class="ap-card-head gold"><i class="fas fa-bolt"></i> Paying Cash Instead?</div>
        <div class="ap-card-body"><p class="ap-help" style="margin:0 0 .8rem">Secure the vehicle before the auction with a <strong>Pre-Auction Purchase</strong> at the bank's price.</p>
          <a class="btn btn-outline btn-sm" href="<?= APP_URL ?>/preauction_request.php<?= $vehicle ? '?id=' . (int)$vehicle['id'] : '' ?>"><i class="fas fa-key"></i> Reserve Before Auction</a></div>
      </div>
    </aside>
  </div>
</div></section>
<script src="<?= APP_URL ?>/assets/js/fin-result.js"></script>
<script>
(function () {
  var form = document.getElementById('fw'); if (!form) return;
  var R = function (v) { return 'R' + Math.round(v).toLocaleString('en-ZA', {maximumFractionDigits: 0}); };
  var num = function (n) { var el = form.elements[n]; return el ? (+String(el.value).replace(/[^\d.]/g, '') || 0) : 0; };
  var esc = function (s) { var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; };
  var NORM_BASE = <?= (float)$rules['norm_base'] ?>, NORM_DEP = <?= (float)$rules['norm_dep'] ?>;

  // Carry figures over from the "Do I qualify?" check on financing.php
  try {
    var pre = JSON.parse(sessionStorage.getItem('ahgFinPrecheck') || 'null');
    if (pre && !<?= $_SERVER['REQUEST_METHOD'] === 'POST' ? 'true' : 'false' ?>) {
      Object.keys(pre).forEach(function (k) {
        var el = form.elements[k]; if (!el || el.type === 'hidden') return;
        if (el.length && el[0] && el[0].type === 'radio') { for (var i = 0; i < el.length; i++) el[i].checked = el[i].value === pre[k]; return; }
        if (k === 'auction_id' && el.value) return;
        if (!el.value || el.value === '0') el.value = pre[k];
      });
    }
  } catch (e) {}

  // Vehicle → deposit help
  var sel = document.getElementById('ap-vehicle'), dep = document.getElementById('deposit');
  function vehicle(setMin) {
    var o = sel.options[sel.selectedIndex];
    if (!o || !o.value) return;
    document.getElementById('ap-deposit-help').textContent = 'Minimum ' + R(+o.dataset.min) + ' on this vehicle — more deposit lowers your instalment and raises your score.';
    document.getElementById('ap-vehicle-help').textContent = 'Price ' + R(+o.dataset.price) + ' · terms from 6 to ' + o.dataset.maxTerm + ' months.';
    if (setMin && num('deposit') < +o.dataset.min) dep.value = o.dataset.min;
  }
  sel.addEventListener('change', function () { vehicle(true); });
  vehicle(false);

  // ID type / employment toggles
  var idType = document.getElementById('id_type'), idNum = document.getElementById('id_number');
  function idUi() {
    var pass = idType.value === 'passport';
    document.getElementById('id-label').textContent = pass ? 'Passport number' : 'SA ID number';
    idNum.inputMode = pass ? 'text' : 'numeric';
    document.querySelectorAll('.fw-passport').forEach(function (el) { el.classList.toggle('fw-hide', !pass); });
    document.getElementById('dob').required = pass;
    idCheck();
  }
  function luhn(id) { var s = 0; for (var i = 0; i < 13; i++) { var d = +id[12 - i]; if (i % 2) { d *= 2; if (d > 9) d -= 9; } s += d; } return s % 10 === 0; }
  function idCheck() {
    var help = document.getElementById('id-help'), v = idNum.value.replace(/\D/g, '');
    idNum.setCustomValidity('');
    if (idType.value !== 'sa' || !v) { help.textContent = ''; return; }
    if (v.length !== 13 || !luhn(v)) { help.textContent = v.length < 13 ? (13 - v.length) + ' more digit(s)…' : 'This ID number does not look right — please check it.'; if (v.length === 13) idNum.setCustomValidity('Invalid SA ID number'); return; }
    var yy = +v.substr(0, 2), now = new Date(), y = yy + (yy > now.getFullYear() % 100 ? 1900 : 2000);
    var dob = new Date(y, +v.substr(2, 2) - 1, +v.substr(4, 2)), age = now.getFullYear() - y - (now < new Date(now.getFullYear(), dob.getMonth(), dob.getDate()) ? 1 : 0);
    help.innerHTML = '<i class="fas fa-circle-check" style="color:#1a9e4e"></i> Valid ID · age ' + age;
    if (age < 18) { idNum.setCustomValidity('You must be 18 or older'); help.textContent = 'You must be 18 or older to apply for finance.'; }
  }
  idType.addEventListener('change', idUi); idNum.addEventListener('input', idCheck); idUi();
  var emp = document.getElementById('employment');
  function empUi() {
    var v = emp.value, job = v === 'permanent' || v === 'contract' || v === 'self';
    document.querySelectorAll('.fw-job').forEach(function (el) { el.classList.toggle('fw-hide', !job); });
    document.getElementById('employer').required = job;
    document.getElementById('employer-label').textContent = v === 'self' ? 'Business name' : 'Employer';
  }
  emp.addEventListener('change', empUi); empUi();

  // Budget summary
  function budget() {
    var inc = num('net_income') + num('other_income');
    var norm = NORM_BASE + NORM_DEP * num('dependants');
    var out = num('exp_housing') + Math.max(num('exp_living'), norm) + num('exp_debt') + num('exp_other');
    var box = document.getElementById('fw-budget');
    if (!inc) { box.style.display = 'none'; return; }
    box.style.display = '';
    document.getElementById('fb-in').textContent = R(inc);
    document.getElementById('fb-out').textContent = '− ' + R(out);
    var f = document.getElementById('fb-free'); f.textContent = R(inc - out); f.style.color = inc - out > 0 ? '#1a9e4e' : '#a30000';
  }
  ['net_income', 'other_income', 'exp_housing', 'exp_living', 'exp_debt', 'exp_other', 'dependants'].forEach(function (n) { var el = form.elements[n]; if (el) el.addEventListener('input', budget); });
  budget();

  // ── Wizard ──
  form.classList.add('fw-js');
  var steps = [].slice.call(form.querySelectorAll('.fw-step')), nav = [].slice.call(document.querySelectorAll('#fw-nav li'));
  var back = document.getElementById('fw-back'), next = document.getElementById('fw-next');
  document.getElementById('fw-nav').style.display = ''; document.getElementById('fw-btns').style.display = '';
  var cur = 1;
  function show(n) {
    cur = n;
    steps.forEach(function (s) { s.classList.toggle('on', +s.dataset.step === n); });
    nav.forEach(function (li, i) { li.className = i + 1 < n ? 'done' : (i + 1 === n ? 'now' : ''); });
    back.style.visibility = n > 1 ? '' : 'hidden';
    next.style.display = n < steps.length ? '' : 'none';
    if (n === steps.length) check();
    var top = form.getBoundingClientRect().top + window.pageYOffset - 90;
    if (window.pageYOffset > top) window.scrollTo({top: top, behavior: 'smooth'});
  }
  function valid(n) {
    var els = steps[n - 1].querySelectorAll('input,select,textarea');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (el.closest('.fw-hide')) continue;
      if (!el.checkValidity()) { el.reportValidity(); el.focus(); return false; }
    }
    if (n === 1 && !sel.value) { sel.focus(); return false; }
    return true;
  }
  next.addEventListener('click', function () { if (valid(cur)) show(cur + 1); });
  back.addEventListener('click', function () { show(cur - 1); });
  nav.forEach(function (li, i) { li.addEventListener('click', function () { if (i + 1 < cur) show(i + 1); }); });
  form.addEventListener('submit', function (e) {
    for (var n = 1; n < steps.length; n++) if (!valid(n)) { e.preventDefault(); show(n); valid(n); return; }
    document.getElementById('fw-submit').disabled = true;
    try { sessionStorage.removeItem('ahgFinPrecheck'); } catch (x) {}
  });

  // ── Live result (finance_check.php) ──
  function check() {
    var box = document.getElementById('fw-result'), body = document.getElementById('fw-result-body');
    box.style.display = ''; body.innerHTML = '<div class="fr-loading"><i class="fas fa-spinner fa-spin"></i> Working out your result…</div>';
    var fd = new FormData(form); fd.delete('csrf');
    fetch('<?= APP_URL ?>/finance_check.php', {method: 'POST', body: fd, credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { body.innerHTML = '<div class="alert alert-warning" style="margin:0"><i class="fas fa-triangle-exclamation"></i><span>' + esc(d.error || 'Please check your details.') + '</span></div>'; return; }
        body.innerHTML = window.ahgFinResult(d, 'Based on what you entered. Your documents and a credit bureau check confirm it — a different bureau score can change your result.');
      })
      .catch(function () { body.innerHTML = '<p class="ap-help">We could not show your result right now — you can still submit; it is worked out when we receive your application.</p>'; });
  }
  show(Math.max(1, Math.min(steps.length, +form.dataset.start || 1)));
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
