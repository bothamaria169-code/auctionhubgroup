<?php
// ============================================================
// AUCTION HUB GROUP — admin_applications_tab.php
// Admin → Finance & Pre-Auction (tab=applications), included by
// admin_dashboard.php. Same layout as the Refunds tab.
//   Finance: approve deposit / suggest a higher one / decline,
//     review documents, verify deposit → Buyer Code, confirm test drive.
//   Pre-auction:      issue the proforma invoice (→ preauction_invoices,
//     the same invoices as the Deal Builder), approve the POP → Buyer Code
//     email, or decline (financial institution notice).
// Actions post to applications_api.php.
// ============================================================
require_once __DIR__ . '/finance_helper.php';
ahgSchemaReady();

$faView = $_GET['view'] ?? 'todo';
$faKind = in_array($_GET['kind'] ?? '', ['finance', 'preauction'], true) ? $_GET['kind'] : '';
$faQ    = trim((string)($_GET['q'] ?? ''));
$faCsrf = e(finCsrfToken());
$faOne  = !empty($_GET['app']) ? finGet((int)$_GET['app']) : null;
$faOpen = ['review', 'quoted', 'chosen', 'invoiced', 'approved', 'requested', 'pop_uploaded', 'expired', 'paid'];
$faTodo = ['review', 'chosen', 'requested', 'pop_uploaded', 'expired'];

$faRows = [];
$faCounts = array_fill_keys(['todo', 'open', 'finance', 'preauction', 'done'], 0);
try {
    // Refresh pre-auction rows from their invoices (Deal Builder approvals etc.)
    foreach ($pdo->query("SELECT * FROM client_applications WHERE kind = 'preauction' AND status IN ('invoiced','pop_uploaded','expired')") as $r) paSync(finDecode($r));
    foreach ($pdo->query("SELECT kind, status, COUNT(*) n FROM client_applications GROUP BY kind, status") as $r) {
        if (in_array($r['status'], $faTodo, true)) $faCounts['todo'] += $r['n'];
        if (in_array($r['status'], $faOpen, true)) { $faCounts['open'] += $r['n']; $faCounts[$r['kind']] += $r['n']; }
        if (in_array($r['status'], ['completed', 'paid', 'approved'], true)) $faCounts['done'] += $r['n'];
    }
    $where = []; $args = [];
    if ($faKind) { $where[] = 'kind = ?'; $args[] = $faKind; }
    if ($faView === 'todo')   $where[] = "status IN ('" . implode("','", $faTodo) . "')";
    if ($faView === 'open')   $where[] = "status IN ('" . implode("','", $faOpen) . "')";
    if ($faView === 'closed') $where[] = "status NOT IN ('" . implode("','", $faOpen) . "')";
    if ($faQ !== '') { $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ? OR vehicle LIKE ? OR buyer_code = ? OR id = ?)'; array_push($args, "%$faQ%", "%$faQ%", "%$faQ%", "%$faQ%", $faQ, (int)preg_replace('/\D/', '', $faQ)); }
    $st = $pdo->prepare("SELECT * FROM client_applications" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY updated_at DESC LIMIT 300");
    $st->execute($args);
    $faRows = array_map('finDecode', $st->fetchAll());
} catch (Throwable $e) { error_log('[AHG applications tab] ' . $e->getMessage()); }

/** A small action form posting to applications_api.php. */
$faForm = fn(int $id, string $action, string $inner, string $btn, string $cls = 'btn-primary', string $confirm = '') =>
    '<form method="post" action="applications_api.php" class="fa2-act"><input type="hidden" name="csrf" value="' . $faCsrf . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="' . $action . '">'
    . $inner . '<button class="btn btn-sm ' . $cls . '" type="submit"' . ($confirm !== '' ? ' onclick="return confirm(\'' . e($confirm) . '\')"' : '') . '>' . $btn . '</button></form>';
?>
<style>
.fa2-stats { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.fa2-stat { background:#fff; border-radius:10px; border:1px solid var(--border-color,#e0e8f0); padding:1rem 1.5rem; flex:1; min-width:120px; text-align:center; text-decoration:none; }
.fa2-stat .num { font-size:1.8rem; font-weight:800; font-family:'Barlow Condensed',sans-serif; }
.fa2-stat .lbl { font-size:.72rem; text-transform:uppercase; letter-spacing:.07em; color:#888; margin-top:.2rem; }
.fa2-stat.on { border-color:#005BA1; box-shadow:0 0 0 2px rgba(0,91,161,.15); }
.fa2-table { width:100%; border-collapse:collapse; font-size:.87rem; }
.fa2-table th { background:#003f73; color:#fff; padding:.75rem 1rem; text-align:left; font-size:.78rem; text-transform:uppercase; letter-spacing:.07em; }
.fa2-table td { padding:.75rem 1rem; border-bottom:1px solid #eef2f7; vertical-align:middle; }
.fa2-table tr:hover td { background:#f8fbff; cursor:pointer; }
.fa2-filters { display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; padding:1rem 1.25rem; }
.fa2-filters a.chip { padding:.38rem .9rem; border-radius:50px; border:1.5px solid #d0dce8; color:#445566; text-decoration:none; font-size:.8rem; font-weight:700; }
.fa2-filters a.chip.on { background:linear-gradient(135deg,#005BA1,#003f73); color:#fff; border-color:#003f73; }
.fa2-grid { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(0,1fr); gap:1.25rem; align-items:start; }
@media(max-width:1000px){ .fa2-grid { grid-template-columns:minmax(0,1fr); } }
.fa2-grid > * { min-width:0; }
.fa2-row .val { overflow-wrap:anywhere; }
.fa2-act .form-control, .fa2-act textarea { max-width:100%; }
.fa2-act .btn, .fa2-body .btn { white-space:normal; }
.fa2-head .badge, .fa2-table .badge { white-space:normal; line-height:1.35; }
@media(max-width:600px){ .fa2-body { padding:1rem; } .fa2-table th, .fa2-table td { padding:.6rem .7rem; } .fa2-act label { width:100%; } .fa2-act label .form-control, .fa2-act label textarea { width:100% !important; } .fa2-stat { min-width:calc(50% - .5rem); padding:.8rem; } }
.fa2-head { background:linear-gradient(135deg,#003f73,#005BA1); padding:1rem 1.4rem; color:#fff; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; }
.fa2-head h4, .fa2-head h4 i { margin:0; font-size:1.05rem; font-weight:700; color:#fff !important; }
.fa2-head .badge { background:#fff !important; }
.fa2-body { padding:1.25rem 1.4rem; }
.fa2-row { display:flex; justify-content:space-between; gap:1rem; padding:.45rem 0; border-bottom:1px solid #f0f4f8; font-size:.88rem; }
.fa2-row:last-child { border-bottom:none; }
.fa2-row .lbl { color:#667; font-weight:600; font-size:.76rem; text-transform:uppercase; letter-spacing:.06em; }
.fa2-row .val { font-weight:700; color:#1a1a1a; text-align:right; word-break:break-word; }
.fa2-next { background:#fffaf0; border:1.5px solid #f5c842; border-radius:10px; padding:1rem 1.25rem; margin-bottom:1.25rem; }
.fa2-next h5 { margin:0 0 .5rem; color:#7a4f00; font-size:.95rem; }
.fa2-act { display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-end; margin:.5rem 0; }
.fa2-act label { font-size:.75rem; font-weight:700; color:#556; }
.fa2-act .form-control { padding:.4rem .6rem; font-size:.85rem; }
.fa2-log { font-size:.8rem; color:#556; max-height:280px; overflow:auto; margin:0; padding-left:1rem; }
</style>

<div class="dashboard-header">
  <div>
    <h1><i class="fas fa-handshake"></i> Finance &amp; Pre-Auction</h1>
    <p class="text-muted">Online finance applications and pre-auction purchase requests. Pre-auction invoices are the same ones as in the <a href="invoice_generator.php">Deal Builder</a>.</p>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a class="btn btn-sm btn-outline" href="<?= APP_URL ?>/finance_apply.php" target="_blank"><i class="fas fa-up-right-from-square"></i> Finance form</a>
    <a class="btn btn-sm btn-outline" href="<?= APP_URL ?>/preauction_request.php" target="_blank"><i class="fas fa-up-right-from-square"></i> Pre-auction form</a>
    <a class="btn btn-sm btn-outline" href="?tab=applications&amp;view=settings"><i class="fas fa-sliders"></i> Settings</a>
  </div>
</div>
<?= finFlashHtml() ?>

<?php if ($faView === 'settings' && !$faOne): [$mn, $mx, $df] = paRange(); ?>
  <form class="card" method="post" action="applications_api.php" style="overflow:visible">
    <div class="fa2-head"><h4><i class="fas fa-sliders"></i> Finance &amp; Pre-Auction Settings</h4></div>
    <div class="fa2-body">
      <input type="hidden" name="csrf" value="<?= $faCsrf ?>"><input type="hidden" name="action" value="settings">
      <h5 style="color:#003f73">Finance</h5>
      <div class="fa2-act">
        <label>Min deposit — cars &amp; bakkies (R)<br><input class="form-control" name="fin_min_deposit" value="<?= e(finSetting('fin_min_deposit', '10500')) ?>"></label>
        <label>Min deposit — trucks &amp; plant (R)<br><input class="form-control" name="fin_min_deposit_heavy" value="<?= e(finSetting('fin_min_deposit_heavy', '50000')) ?>"></label>
      </div>
      <p class="text-muted" style="font-size:.85rem">Rate per month by term — the same tiers as the calculator on financing.php. Cars &amp; bakkies 6–48 months; trucks &amp; plant up to 72.</p>
      <div class="fa2-act">
        <?php foreach (finTiers(['body_type' => 'truck']) as $m => $r): ?>
          <label><?= $m ?> months (% p/m)<br><input class="form-control" name="fin_rate_<?= $m ?>" value="<?= e((string)$r) ?>" style="width:100px"></label>
        <?php endforeach; ?>
      </div>
      <h5 style="color:#003f73;margin-top:1rem">Credit &amp; Affordability Assessment</h5>
      <p class="text-muted" style="font-size:.85rem">Each application is scored out of 100 (affordability 30, credit profile 25, employment 20, deposit 15, stability 10). Grade A gets the normal minimum deposit; B, C and D need a start-up deposit of a share of the price. The start-up deposit is also raised so the instalment on the longest term fits the client's budget.</p>
      <?php $ru = finRules(); ?>
      <div class="fa2-act">
        <label>Grade A from (score)<br><input class="form-control" name="fin_score_a" value="<?= e((string)$ru['score_a']) ?>" style="width:100px"></label>
        <label>Grade B from<br><input class="form-control" name="fin_score_b" value="<?= e((string)$ru['score_b']) ?>" style="width:100px"></label>
        <label>Grade C from<br><input class="form-control" name="fin_score_c" value="<?= e((string)$ru['score_c']) ?>" style="width:100px"></label>
      </div>
      <div class="fa2-act">
        <label>Start-up deposit B (% of price)<br><input class="form-control" name="fin_startup_b" value="<?= e((string)$ru['startup_b']) ?>" style="width:120px"></label>
        <label>Start-up deposit C (%)<br><input class="form-control" name="fin_startup_c" value="<?= e((string)$ru['startup_c']) ?>" style="width:120px"></label>
        <label>Start-up deposit D (%)<br><input class="form-control" name="fin_startup_d" value="<?= e((string)$ru['startup_d']) ?>" style="width:120px"></label>
      </div>
      <div class="fa2-act">
        <label>Max instalment (% of income)<br><input class="form-control" name="fin_afford_pct" value="<?= e((string)$ru['afford_pct']) ?>" style="width:120px"></label>
        <label>Usable share of money left over (%)<br><input class="form-control" name="fin_buffer_pct" value="<?= e((string)$ru['buffer_pct']) ?>" style="width:120px"></label>
        <label>Min monthly income (R)<br><input class="form-control" name="fin_min_income" value="<?= e((string)$ru['min_income']) ?>" style="width:120px"></label>
        <label>Living-cost norm (R)<br><input class="form-control" name="fin_norm_base" value="<?= e((string)$ru['norm_base']) ?>" style="width:110px"></label>
        <label>+ per dependant (R)<br><input class="form-control" name="fin_norm_dependant" value="<?= e((string)$ru['norm_dep']) ?>" style="width:110px"></label>
        <label>Min credit score for grade A (TransUnion 0–999)<br><input class="form-control" name="fin_min_credit_score" value="<?= e((string)$ru['min_credit']) ?>" style="width:110px"></label>
        <label>Refer applicants older than<br><input class="form-control" name="fin_max_age" value="<?= e((string)$ru['max_age']) ?>" style="width:90px"></label>
      </div>
      <div class="alert alert-info" style="font-size:.84rem"><i class="fas fa-circle-info"></i> <span><strong>Credit bureau checks:</strong> automatic checks need an account with a registered credit bureau (TransUnion, Experian, XDS or Compuscan) and NCR registration as a credit provider. Until an API is connected, pull the client's report on the bureau's portal (the client consented on the form) and enter the score on the application — the result, start-up deposit and quote update automatically.</span></div>
      <h5 style="color:#003f73;margin-top:1rem">Pre-Auction Commitment Fee</h5>
      <p class="text-muted" style="font-size:.85rem">Clients no longer choose a percentage — you set it when you issue the proforma invoice, within this range.</p>
      <div class="fa2-act">
        <label>Lowest %<br><input class="form-control" name="pa_min_percent" value="<?= $mn ?>" style="width:90px"></label>
        <label>Highest %<br><input class="form-control" name="pa_max_percent" value="<?= $mx ?>" style="width:90px"></label>
        <label>Pre-filled %<br><input class="form-control" name="pa_default_percent" value="<?= $df ?>" style="width:90px"></label>
      </div>
      <div style="margin-top:1rem"><button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save Settings</button> <a class="btn btn-outline" href="?tab=applications">Back</a></div>
    </div>
  </form>

<?php elseif ($faOne): $a = $faOne; $d = $a['details']; $isFin = $a['kind'] === 'finance'; $plan = $isFin ? finChosenPlan($a) : null; $inv = $isFin ? null : paInvoiceRow($a); $wa = finAdminWhatsAppUrl($a); $id = (int)$a['id']; ?>
  <p><a href="?tab=applications"><i class="fas fa-arrow-left"></i> All applications</a></p>
  <div class="fa2-grid">
    <div>
      <div class="card" style="overflow:visible;margin-bottom:1.25rem">
        <div class="fa2-head"><h4><?= $isFin ? '<i class="fas fa-hand-holding-dollar"></i> Finance' : '<i class="fas fa-key"></i> Pre-Auction Purchase' ?> · <?= e(finRef($a)) ?></h4><?= finBadge($a) ?></div>
        <div class="fa2-body">
          <div class="fa2-row"><span class="lbl">Client</span><span class="val"><?= e($a['name']) ?></span></div>
          <div class="fa2-row"><span class="lbl">Phone</span><span class="val"><a href="tel:<?= e($a['phone']) ?>"><?= e($a['phone']) ?></a></span></div>
          <div class="fa2-row"><span class="lbl">Email</span><span class="val"><a href="mailto:<?= e($a['email']) ?>"><?= e($a['email']) ?></a></span></div>
          <?php if (!$isFin): ?>
            <div class="fa2-row"><span class="lbl">ID / passport</span><span class="val"><?= e($d['id_number'] ?? '—') ?></span></div>
            <div class="fa2-row"><span class="lbl">Address</span><span class="val"><?= e($d['address'] ?? '—') ?></span></div>
          <?php endif; ?>
          <div class="fa2-row"><span class="lbl">Vehicle</span><span class="val"><a href="<?= APP_URL ?>/auction.php?id=<?= (int)$a['auction_id'] ?>" target="_blank"><?= e($a['vehicle']) ?></a> · lot <?= (int)$a['auction_id'] ?></span></div>
          <div class="fa2-row"><span class="lbl">VIN</span><span class="val"><?= e(vinForAuction((int)$a['auction_id']) ?: '— not captured') ?></span></div>
          <div class="fa2-row"><span class="lbl">Price</span><span class="val"><?= formatRand((float)$a['price']) ?></span></div>
          <?php if ($isFin): ?>
            <div class="fa2-row"><span class="lbl">Deposit</span><span class="val"><?= formatRand((float)$a['amount']) ?><?= !empty($d['quote']['counter']) ? ' (our suggestion)' : '' ?></span></div>
            <div class="fa2-row"><span class="lbl">Term</span><span class="val"><?= $plan ? (int)$plan['months'] . ' months × ' . formatRand($plan['monthly']) : '— not chosen yet' ?></span></div>
            <?php if (!empty($d['applicant'])): $ap = $d['applicant']; $wk = $d['work'] ?? []; ?>
              <div class="fa2-row"><span class="lbl">ID / passport</span><span class="val"><?= e(($ap['id_type'] ?? 'sa') === 'passport' ? 'Passport ' : 'SA ID ') ?><?= e($ap['id_number'] ?? '—') ?><?= isset($ap['age']) ? ' · age ' . (int)$ap['age'] : '' ?></span></div>
              <div class="fa2-row"><span class="lbl">Personal</span><span class="val"><?= e(trim(($ap['title'] ?? '') . ' ' . ($ap['first_names'] ?? '') . ' ' . ($ap['surname'] ?? ''))) ?> · <?= e(FIN_MARITAL[$ap['marital'] ?? ''] ?? '—') ?> · <?= (int)($ap['dependants'] ?? 0) ?> dependant(s)</span></div>
              <div class="fa2-row"><span class="lbl">Address</span><span class="val"><?= e($d['address'] ?? '—') ?> · <?= e(FIN_RESIDENCE[$ap['residence'] ?? ''] ?? '') ?>, <?= e((string)($ap['years_address'] ?? 0)) ?> yrs</span></div>
              <div class="fa2-row"><span class="lbl">Employment</span><span class="val"><?= e(FIN_EMPLOYMENT[$wk['employment'] ?? ''] ?? ($d['employment'] ?? '—')) ?><?= !empty($wk['employer']) ? ' · ' . e($wk['employer']) : '' ?><?= !empty($wk['job_title']) ? ' (' . e($wk['job_title']) . ')' : '' ?><?= isset($wk['months_employed']) ? ' · ' . (int)$wk['months_employed'] . ' months' : '' ?><?= !empty($wk['employer_phone']) ? ' · ' . e($wk['employer_phone']) : '' ?></span></div>
              <div class="fa2-row"><span class="lbl">Income</span><span class="val">Gross <?= formatRand((float)($wk['gross_income'] ?? 0)) ?> · net <?= formatRand((float)($wk['net_income'] ?? 0)) ?><?= !empty($wk['other_income']) ? ' · other ' . formatRand((float)$wk['other_income']) : '' ?> · paid <?= e($wk['pay_day'] ?? '—') ?></span></div>
              <?php $fi = $d['finances'] ?? []; ?>
              <div class="fa2-row"><span class="lbl">Expenses</span><span class="val">Housing <?= formatRand((float)($fi['exp_housing'] ?? 0)) ?> · living <?= formatRand((float)($fi['exp_living'] ?? 0)) ?> · debt <?= formatRand((float)($fi['exp_debt'] ?? 0)) ?> · other <?= formatRand((float)($fi['exp_other'] ?? 0)) ?></span></div>
              <div class="fa2-row"><span class="lbl">Credit (declared)</span><span class="val"><?= e(FIN_CREDIT_BANDS[finBandKey($fi['credit_band'] ?? null)][0] ?? '—') ?><?= !empty($fi['credit_score']) ? ' · ' . (int)$fi['credit_score'] : '' ?><?= !empty($fi['debt_review']) ? ' · DEBT REVIEW' : '' ?><?= !empty($fi['judgments']) ? ' · JUDGMENTS' : '' ?></span></div>
              <div class="fa2-row"><span class="lbl">Bank</span><span class="val"><?= e($d['bank']['name'] ?? '—') ?> · <?= e((string)($d['bank']['years'] ?? 0)) ?> yrs</span></div>
              <div class="fa2-row"><span class="lbl">Consent</span><span class="val"><?= !empty($d['consent']['credit_check']) ? 'Credit check, POPIA &amp; terms accepted ' . safeDate('d M Y H:i', $d['consent']['at']) : '—' ?></span></div>
            <?php else: ?>
              <div class="fa2-row"><span class="lbl">Employment</span><span class="val"><?= e($d['employment'] ?? '—') ?><?= !empty($d['income']) ? ' · ' . formatRand((float)$d['income']) . '/mo' : '' ?></span></div>
            <?php endif; ?>
          <?php else: ?>
            <div class="fa2-row"><span class="lbl">Commitment fee</span><span class="val"><?= paPercent($a) > 0 ? paPercent($a) . '% = ' . formatRand($inv ? (float)$inv['commitment_fee_amount'] : paCommitment($a)) : 'Not set yet — you set it on the proforma invoice' ?></span></div>
            <div class="fa2-row"><span class="lbl">Alternative</span><span class="val"><?= e($d['alt']['title'] ?? '—') ?></span></div>
            <div class="fa2-row"><span class="lbl">Mechanic</span><span class="val"><?= !empty($d['mechanic']) ? 'Yes' : 'No' ?></span></div>
            <div class="fa2-row"><span class="lbl">Invoice</span><span class="val"><?= $inv ? e($inv['invoice_number']) . ' · ' . e($inv['status']) . ' · expires ' . safeDate('d M H:i', $inv['expires_at']) : '— not issued' ?></span></div>
            <?php if (!empty($d['decision'])): ?><div class="fa2-row"><span class="lbl">After test drive</span><span class="val"><?= e(['proceed' => 'Proceeding', 'alternative' => 'Switching to alternative', 'refund' => 'Cancelled — refund'][$d['decision']['choice']]) ?> <?= e($d['decision']['reason']) ?></span></div><?php endif; ?>
          <?php endif; ?>
          <div class="fa2-row"><span class="lbl">Buyer Code</span><span class="val"><?= e($a['buyer_code'] ?: '—') ?></span></div>
          <div class="fa2-row"><span class="lbl">Test drive</span><span class="val"><?= !empty($d['viewing']['date']) ? e(finViewingLabel($d['viewing'])) . ' (' . e($d['viewing']['status']) . ')' : '—' ?></span></div>
          <div class="fa2-row"><span class="lbl">Submitted</span><span class="val"><?= safeDate('d M Y H:i', $a['created_at']) ?></span></div>
          <?php if (!empty($d['message'])): ?><div class="fa2-row"><span class="lbl">Message</span><span class="val"><?= nl2br(e($d['message'])) ?></span></div><?php endif; ?>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem">
            <?php if ($wa): ?><a class="btn btn-sm btn-success" href="<?= e($wa) ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> WhatsApp next step</a><?php endif; ?>
            <a class="btn btn-sm btn-outline" href="<?= e(finLink($a)) ?>" target="_blank"><i class="fas fa-eye"></i> Client page</a>
            <?php if ($isFin): foreach (['quote' => 'Quote', 'invoice' => 'Deposit invoice', 'approval' => 'Approval', 'decline' => 'Decline notice'] as $k => $l): ?>
              <a class="btn btn-sm btn-outline" href="<?= APP_URL ?>/application_doc.php?id=<?= $id ?>&amp;doc=<?= $k ?>" target="_blank"><?= $l ?></a>
            <?php endforeach; elseif ($inv): ?>
              <a class="btn btn-sm btn-outline" href="invoice_print.php?id=<?= (int)$inv['id'] ?>" target="_blank"><i class="fas fa-file-invoice"></i> Proforma invoice</a>
              <?php if ($inv['pop_file_path']): ?><a class="btn btn-sm btn-outline" href="serve_pop.php?file=<?= urlencode(basename($inv['pop_file_path'])) ?>" target="_blank"><i class="fas fa-paperclip"></i> Proof of payment</a><?php endif; ?>
              <a class="btn btn-sm btn-outline" href="invoice_generator.php?tab=preauction"><i class="fas fa-magic"></i> Deal Builder</a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if (!finClosed($a)): ?>
      <div class="fa2-next">
        <h5><i class="fas fa-bolt"></i> Next Step</h5>
        <?php if ($isFin && $a['status'] === 'review'): ?>
          <p style="font-size:.88rem">The assessment <strong>referred</strong> this application: <?= e(implode(' ', $d['assessment']['reasons'] ?? [])) ?> Call the client — then enter a bureau score below, suggest a deposit (this opens the terms to the client), or decline.</p>
        <?php elseif ($isFin && $a['status'] === 'quoted'): ?>
          <p class="text-muted" style="font-size:.86rem">Waiting for the client to choose a term.</p>
          <?= $faForm($id, 'resend', '<input type="hidden" name="what" value="quote">', '<i class="fas fa-paper-plane"></i> Email the quote again', 'btn-outline') ?>
        <?php elseif ($isFin && $a['status'] === 'chosen'): ?>
          <p style="font-size:.88rem">Client chose <strong><?= $plan ? (int)$plan['months'] . ' months at ' . formatRand($plan['monthly']) : '—' ?></strong> on a deposit of <strong><?= formatRand((float)$a['amount']) ?></strong>.</p>
          <?= $faForm($id, 'approve_deposit', '', '<i class="fas fa-check"></i> Approve Deposit &amp; Request Documents', 'btn-success') ?>
        <?php elseif ($isFin && $a['status'] === 'invoiced'): $sum = finDocsSummary($a); ?>
          <p style="font-size:.88rem">Deposit invoice <?= e($d['invoice']['number']) ?> (due <?= safeDate('d M', $d['invoice']['due']) ?>). Documents: <strong><?= $sum['uploaded'] ?>/<?= $sum['total'] ?></strong> uploaded, <?= $sum['accepted'] ?> accepted.</p>
          <?= $faForm($id, 'approve', '', '<i class="fas fa-id-badge"></i> Deposit Verified — Approve &amp; Send Buyer Code', 'btn-success', 'Has the deposit reflected in the bank?') ?>
        <?php elseif ($isFin && $a['status'] === 'approved'): ?>
          <?= $faForm($id, 'viewing', '<label>Test-drive day<br><input class="form-control" type="date" name="date" required value="' . e($d['viewing']['date'] ?? '') . '"></label><label>Time<br><input class="form-control" type="time" name="time" required value="' . e($d['viewing']['time'] ?? '10:00') . '"></label><label>Note<br><input class="form-control" name="note" style="width:180px"></label>', '<i class="fas fa-calendar-check"></i> Confirm Test Drive') ?>
          <?= $faForm($id, 'complete', '<label>Note<br><input class="form-control" name="note" placeholder="Agreement signed" style="width:220px"></label>', '<i class="fas fa-flag-checkered"></i> Signed &amp; Driven Away', 'btn-success', 'Mark this completed?') ?>
        <?php endif; ?>
        <?php if ($isFin && in_array($a['status'], ['review', 'quoted', 'chosen'], true)): ?>
          <?= $faForm($id, 'counter_deposit', '<label>Suggest deposit (R)<br><input class="form-control" name="deposit" required style="width:130px"></label><label>Note to client<br><input class="form-control" name="note" style="width:220px"></label>', 'Suggest Higher Deposit', 'btn-outline') ?>
        <?php endif; ?>

        <?php if (!$isFin && in_array($a['status'], ['requested', 'expired'], true)): [$mn, $mx, $df] = paRange(); $cur = paPercent($a) ?: $df; ?>
          <p style="font-size:.88rem"><?= $a['status'] === 'expired' ? 'The 24-hour window closed without payment. Re-issue if the vehicle is still available, or decline.' : 'Decide the <strong>commitment fee</strong> (' . $mn . '–' . $mx . '% of ' . formatRand((float)$a['price']) . ') and issue the proforma invoice — it goes into the Deal Builder\'s pre-auction invoices and is emailed to the client (valid 24 hours).' ?></p>
          <?= $faForm($id, 'pa_invoice', '<label>Commitment fee (%)<br><input class="form-control" type="number" name="percent" min="' . $mn . '" max="' . $mx . '" step="1" value="' . $cur . '" required style="width:100px" oninput="var f=this.form.querySelector(\'.pa-fee\');if(f)f.textContent=\'= R\'+Math.round(' . (float)$a['price'] . '*this.value/100).toLocaleString(\'en-ZA\')"> <small class="pa-fee" style="font-weight:700;color:#003f73">= ' . formatRand(round((float)$a['price'] * $cur / 100)) . '</small></label><label>Note on the invoice email<br><input class="form-control" name="note" style="width:240px"></label>', '<i class="fas fa-file-invoice-dollar"></i> ' . ($a['status'] === 'expired' ? 'Re-issue' : 'Issue') . ' Proforma Invoice', 'btn-success') ?>
        <?php elseif (!$isFin && in_array($a['status'], ['invoiced', 'pop_uploaded'], true)): ?>
          <p style="font-size:.88rem"><?= $a['status'] === 'pop_uploaded' ? 'Proof of payment uploaded — verify it with the bank, then approve.' : 'Waiting for the commitment fee (invoice expires ' . e(safeDate('d M H:i', $inv['expires_at'] ?? '')) . ').' ?></p>
          <?= $faForm($id, 'pa_approve', '<label>Note in the Buyer Code email<br><input class="form-control" name="note" style="width:240px"></label>', '<i class="fas fa-id-badge"></i> Approve &amp; Send Buyer Code', 'btn-success', 'Has the commitment fee been verified?') ?>
        <?php elseif (!$isFin && $a['status'] === 'paid'): ?>
          <?= $faForm($id, 'viewing', '<label>Test-drive day<br><input class="form-control" type="date" name="date" required value="' . e($d['viewing']['date'] ?? '') . '"></label><label>Time<br><input class="form-control" type="time" name="time" required value="' . e($d['viewing']['time'] ?? '10:00') . '"></label><label>Note<br><input class="form-control" name="note" style="width:180px"></label>', '<i class="fas fa-calendar-check"></i> Confirm Test Drive') ?>
          <?= $faForm($id, 'complete', '<label>Note<br><input class="form-control" name="note" placeholder="Balance paid" style="width:220px"></label>', '<i class="fas fa-flag-checkered"></i> Sold — Balance Paid', 'btn-success', 'Mark this sold?') ?>
        <?php endif; ?>

        <?php if (!in_array($a['status'], ['paid', 'approved'], true)): ?>
          <details style="margin-top:.6rem"><summary style="cursor:pointer;color:#a1271b;font-weight:700;font-size:.85rem">Decline…</summary>
            <?= $faForm($id, 'decline', '<label>' . ($inv ? 'Reason from the financial institution (emailed)' : 'Reason (emailed politely)') . '<br><textarea class="form-control" name="note" rows="2" style="width:340px"></textarea></label>', 'Decline &amp; Notify', 'btn-danger', 'Decline and notify the client?') ?>
          </details>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($isFin && !empty($d['docs'])): ?>
      <div class="card" style="overflow:visible;margin-bottom:1.25rem">
        <div class="fa2-head"><h4><i class="fas fa-folder-open"></i> Documents</h4></div>
        <div style="overflow-x:auto"><table class="fa2-table">
          <thead><tr><th>Document</th><th>Files</th><th>Status</th><th></th></tr></thead><tbody>
          <?php foreach (FIN_DOCS as $k => [$label]): $doc = $d['docs'][$k] ?? null; ?>
            <tr style="cursor:default"><td><?= e($label) ?></td>
              <td><?php foreach ($doc['files'] ?? [] as $f): ?><a href="<?= APP_URL ?>/application_file.php?id=<?= $id ?>&amp;f=<?= e($f['file']) ?>" target="_blank"><i class="fas fa-paperclip"></i> <?= e($f['original']) ?></a><br><?php endforeach; ?><?= empty($doc['files']) ? '<span class="text-muted">—</span>' : '' ?></td>
              <td><span class="badge <?= ['pending' => 'badge-pending', 'accepted' => 'badge-approved', 'rejected' => 'badge-rejected'][$doc['status'] ?? ''] ?? 'badge-registered' ?>"><?= e($doc['status'] ?? 'required') ?></span><?= !empty($doc['note']) ? '<br><small>' . e($doc['note']) . '</small>' : '' ?></td>
              <td><?php if (!empty($doc['files']) && ($doc['status'] ?? '') !== 'accepted'): ?>
                <?= $faForm($id, 'doc', '<input type="hidden" name="doc" value="' . $k . '"><input type="hidden" name="status" value="accepted">', 'Accept', 'btn-success') ?>
                <?= $faForm($id, 'doc', '<input type="hidden" name="doc" value="' . $k . '"><input type="hidden" name="status" value="rejected"><input class="form-control" name="note" placeholder="Why? (emailed)" style="width:150px">', 'Re-upload', 'btn-outline') ?>
              <?php endif; ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </div>
      <?php endif; ?>
    </div>

    <div>
      <?php if ($isFin && !empty($d['assessment'])): $as = $d['assessment']; $gc = finGradeColour($as['grade']); ?>
      <div class="card" style="overflow:visible;margin-bottom:1.25rem">
        <div class="fa2-head"><h4><i class="fas fa-chart-simple"></i> Assessment</h4><span class="badge" style="color:<?= $gc ?>;font-weight:800"><?= (int)$as['score'] ?>/100 · <?= $as['grade'] === 'R' ? 'Referred' : 'Grade ' . e($as['grade']) ?></span></div>
        <div class="fa2-body">
          <p style="margin:0 0 .6rem;font-weight:700;color:<?= $gc ?>"><?= e($as['label']) ?></p>
          <?php foreach ($as['points'] as [$l, $p, $m, $n]): ?>
            <div style="margin:.35rem 0"><div style="display:flex;justify-content:space-between;font-size:.8rem;font-weight:700"><span><?= e($l) ?></span><span><?= (int)$p ?> / <?= (int)$m ?></span></div>
              <div style="height:6px;background:#e5ecf4;border-radius:6px;overflow:hidden;margin:.15rem 0"><i style="display:block;height:100%;width:<?= round($p / max(1, $m) * 100) ?>%;background:<?= $gc ?>"></i></div>
              <div style="font-size:.74rem;color:#778"><?= e($n) ?></div></div>
          <?php endforeach; ?>
          <?php foreach ($as['reasons'] as $why): ?><p style="font-size:.82rem;color:#a30000;margin:.4rem 0 0"><i class="fas fa-triangle-exclamation"></i> <?= e($why) ?></p><?php endforeach; ?>
          <div style="margin-top:.75rem">
            <div class="fa2-row"><span class="lbl">Income</span><span class="val"><?= formatRand($as['income']) ?></span></div>
            <div class="fa2-row"><span class="lbl">Expenses (norm <?= formatRand($as['living_norm']) ?>)</span><span class="val"><?= formatRand($as['expenses']) ?></span></div>
            <div class="fa2-row"><span class="lbl">Left over</span><span class="val" style="color:<?= $as['free'] > 0 ? '#1a9e4e' : '#a30000' ?>"><?= formatRand($as['free']) ?></span></div>
            <div class="fa2-row"><span class="lbl">Max instalment</span><span class="val"><?= formatRand($as['max_instalment']) ?></span></div>
            <div class="fa2-row"><span class="lbl">Max to lend (<?= (int)$as['longest'] ?> m)</span><span class="val"><?= formatRand($as['max_lend']) ?></span></div>
            <div class="fa2-row"><span class="lbl">Deposit offered → quoted</span><span class="val"><?= formatRand((float)($d['quote']['offered'] ?? $a['amount'])) ?> → <?= formatRand((float)($d['quote']['deposit'] ?? $a['amount'])) ?><?= !empty($d['quote']['startup']) ? ' (start-up)' : '' ?></span></div>
            <div class="fa2-row"><span class="lbl">Credit score source</span><span class="val"><?= $as['credit_source'] === 'bureau' ? 'Bureau — ' . (int)($d['bureau']['score'] ?? 0) . (!empty($d['bureau']['bureau']) ? ' (' . e($d['bureau']['bureau']) . ')' : '') : 'Client\'s own estimate' ?></span></div>
          </div>
          <?php if (in_array($a['status'], ['review', 'quoted', 'chosen'], true)): ?>
            <h5 style="color:#003f73;margin:1rem 0 .3rem;font-size:.9rem"><i class="fas fa-magnifying-glass-chart"></i> Enter the credit bureau score</h5>
            <p class="text-muted" style="font-size:.8rem;margin:0 0 .3rem">The client consented to a bureau enquiry. Pull the report, enter the score — the result is re-scored; if it needs a higher start-up deposit, a new quote is emailed.</p>
            <?= $faForm($id, 'bureau', '<label>TransUnion score (0–999)<br><input class="form-control" type="number" name="score" min="0" max="999" required style="width:100px" value="' . e((string)($d['bureau']['score'] ?? '')) . '"></label><label>Bureau<br><select class="form-control" name="bureau"><option>TransUnion</option><option>Experian</option><option>XDS</option><option>Compuscan</option><option>Other</option></select></label><label style="display:flex;gap:.3rem;align-items:center;margin-bottom:.4rem"><input type="checkbox" name="debt_review" value="1"' . (!empty($d['finances']['debt_review']) ? ' checked' : '') . '> Debt review</label><label style="display:flex;gap:.3rem;align-items:center;margin-bottom:.4rem"><input type="checkbox" name="judgments" value="1"' . (!empty($d['finances']['judgments']) ? ' checked' : '') . '> Judgments</label><label>Note<br><input class="form-control" name="note" style="width:180px"></label>', '<i class="fas fa-rotate"></i> Re-score', 'btn-primary') ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($isFin && !empty($d['quote'])): ?>
      <div class="card" style="overflow:visible;margin-bottom:1.25rem">
        <div class="fa2-head"><h4>Quote <?= e($d['quote']['number']) ?></h4></div>
        <table class="fa2-table"><thead><tr><th>Term</th><th>p/m</th><th>Monthly</th></tr></thead><tbody>
          <?php foreach ($d['quote']['plans'] as $p): ?><tr style="cursor:default<?= $plan && $plan['months'] === $p['months'] ? ';background:#f1fbf5;font-weight:700' : '' ?><?= finPlanAllowed($p) ? '' : ';opacity:.5' ?>"><td><?= $p['months'] ?> months<?= finPlanAllowed($p) ? '' : ' <small>(over budget)</small>' ?></td><td><?= $p['rate'] ?>%</td><td><?= formatRand($p['monthly']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
      </div>
      <?php endif; ?>
      <form class="card" method="post" action="applications_api.php" style="overflow:visible;margin-bottom:1.25rem">
        <div class="fa2-head"><h4><i class="fas fa-note-sticky"></i> Office Notes</h4></div>
        <div class="fa2-body">
          <input type="hidden" name="csrf" value="<?= $faCsrf ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="note">
          <?php foreach ($d['notes'] ?? [] as $n): ?><p style="font-size:.85rem;margin:.3rem 0"><strong><?= e($n['by']) ?></strong> <span class="text-muted"><?= safeDate('d M H:i', $n['at']) ?></span><br><?= nl2br(e($n['text'])) ?></p><?php endforeach; ?>
          <textarea class="form-control" name="note" rows="2" placeholder="Only the office sees this"></textarea>
          <button class="btn btn-sm btn-outline" type="submit" style="margin-top:.5rem">Save Note</button>
        </div>
      </form>
      <div class="card" style="overflow:visible">
        <div class="fa2-head"><h4><i class="fas fa-clock-rotate-left"></i> History</h4></div>
        <div class="fa2-body"><ul class="fa2-log">
          <?php foreach (array_reverse($d['log'] ?? []) as $l): ?><li><?= safeDate('d M H:i', $l['at']) ?> · <?= e($l['by']) ?> — <?= e($l['what']) ?></li><?php endforeach; ?>
          <?php foreach (array_reverse($d['emails'] ?? []) as $m): ?><li><?= safeDate('d M H:i', $m['at']) ?> · email — <?= e($m['what']) ?> <?= $m['ok'] ? '✓' : '<strong style="color:#c00">FAILED</strong>' ?></li><?php endforeach; ?>
        </ul></div>
      </div>
    </div>
  </div>

<?php else: ?>
  <div class="fa2-stats">
    <?php foreach (['todo' => ['Waiting on us', '#856404'], 'open' => ['Open', '#004085'], 'finance' => ['Finance (open)', '#005BA1'], 'preauction' => ['Pre-auction (open)', '#8b5cf6'], 'done' => ['Approved / sold', '#155724']] as $k => [$l, $c]): ?>
      <a class="fa2-stat<?= ($faView === $k || $faKind === $k) ? ' on' : '' ?>" href="?tab=applications&amp;<?= in_array($k, ['finance', 'preauction'], true) ? 'view=open&amp;kind=' . $k : 'view=' . ($k === 'done' ? 'all' : $k) ?>"><div class="num" style="color:<?= $c ?>"><?= $faCounts[$k] ?></div><div class="lbl"><?= $l ?></div></a>
    <?php endforeach; ?>
  </div>
  <div class="card" style="overflow:visible">
    <div class="fa2-filters">
      <?php foreach (['todo' => 'Waiting on us', 'open' => 'Open', 'closed' => 'Closed', 'all' => 'All'] as $k => $l): ?>
        <a class="chip<?= $faView === $k ? ' on' : '' ?>" href="?tab=applications&amp;view=<?= $k ?>&amp;kind=<?= e($faKind) ?>"><?= $l ?></a>
      <?php endforeach; ?>
      <form method="get" style="display:flex;gap:.5rem;margin-left:auto;flex:1;min-width:240px;max-width:420px"><input type="hidden" name="tab" value="applications"><input type="hidden" name="view" value="all">
        <input class="form-control" name="q" value="<?= e($faQ) ?>" placeholder="Name, phone, email, vehicle, Buyer Code…"><button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-search"></i></button></form>
    </div>
    <?php if (!$faRows): ?>
      <div style="padding:3rem;text-align:center;color:#888;"><i class="fas fa-inbox" style="font-size:2.5rem;margin-bottom:1rem;display:block;opacity:.4;"></i>Nothing here. Applications come from <a href="<?= APP_URL ?>/finance_apply.php" target="_blank">Apply for Finance</a> and <a href="<?= APP_URL ?>/preauction_request.php" target="_blank">Pre-Auction Purchase</a> (also on every vehicle without bids).</div>
    <?php else: ?>
    <div style="overflow-x:auto"><table class="fa2-table">
      <thead><tr><th>Ref</th><th>Client</th><th>Vehicle</th><th>Figures</th><th>Status</th><th>Updated</th></tr></thead><tbody>
      <?php foreach ($faRows as $a): ?>
        <tr onclick="location.href='?tab=applications&amp;app=<?= (int)$a['id'] ?>'">
          <td><a href="?tab=applications&amp;app=<?= (int)$a['id'] ?>"><strong><?= e(finRef($a)) ?></strong></a><br><small class="text-muted"><?= $a['kind'] === 'finance' ? 'Finance' : 'Pre-auction' ?></small></td>
          <td><strong><?= e($a['name']) ?></strong><br><small class="text-muted"><?= e($a['phone']) ?></small></td>
          <td><?= e($a['vehicle']) ?><br><small class="text-muted"><?= formatRand((float)$a['price']) ?></small></td>
          <td><?= $a['kind'] === 'finance' ? 'Deposit ' . formatRand((float)$a['amount']) . ($a['months'] ? '<br><small class="text-muted">' . (int)$a['months'] . ' months</small>' : '') . (isset($a['details']['assessment']['score']) ? '<br><small style="font-weight:700;color:' . finGradeColour($a['details']['assessment']['grade']) . '">Score ' . (int)$a['details']['assessment']['score'] . ' · ' . ($a['details']['assessment']['grade'] === 'R' ? 'referred' : 'grade ' . e($a['details']['assessment']['grade'])) . '</small>' : '') : (paPercent($a) > 0 ? paPercent($a) . '% = ' . formatRand(paCommitment($a)) : '<small class="text-muted">Fee to set</small>') ?></td>
          <td><?= finBadge($a) ?><?= $a['buyer_code'] ? '<br><small class="text-muted">' . e($a['buyer_code']) . '</small>' : '' ?></td>
          <td><small class="text-muted"><?= safeDate('d M H:i', $a['updated_at']) ?></small></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </div>
<?php endif; ?>
