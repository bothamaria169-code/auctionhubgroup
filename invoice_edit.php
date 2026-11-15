<?php
// ============================================================
// AUCTION HUB GROUP — invoice_edit.php
// Staff review an auto-drafted client invoice before it is sent:
// edit every field, preview the A4 invoice, then Send to client.
//   ?id=ROW   an invoice (preauction_invoices)
//   ?app=ID   the invoice of an application (creates the draft for
//             requests made before drafts existed)
// See invoice_helper.php for the whole flow.
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';
require_once __DIR__ . '/leads_helper.php';

$admin = getPageUser();
if (!$admin || ($admin['role'] ?? '') !== 'admin') { header('Location: login.php'); exit; }
invSchemaFix();
$by = (string)($admin['full_name'] ?? 'Office');

$inv = !empty($_GET['id']) ? invRow((int)$_GET['id']) : null;
$app = $inv && $inv['application_id'] ? finGet((int)$inv['application_id']) : null;
if (!$inv && !empty($_GET['app']) && ($app = finGet((int)$_GET['app']))) {
    $inv = invForApp($app) ?: invCreateDraft($app);
    if ($inv) { header('Location: invoice_edit.php?id=' . (int)$inv['id']); exit; }
}
if (!$inv) { http_response_code(404); exit('Invoice not found.'); }
if ($app) $inv = invSyncFinance($app, $inv);
$isFin = invIsFinance($inv);
$back = $app ? 'admin_dashboard.php?tab=applications&app=' . (int)$app['id'] : 'invoice_generator.php?tab=preauction';

// ── Actions (post → redirect → get) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $go = 'invoice_edit.php?id=' . (int)$inv['id'];
    if (!finCsrfOk()) { finFlash('error', 'Your session expired — please try again.'); header("Location: $go"); exit; }
    $action = (string)($_POST['action'] ?? '');
    $err = '';
    if (in_array($action, ['save', 'send'], true) && in_array($inv['status'], ['draft', 'pending'], true)) {
        $err = invSaveEdits($inv, $_POST);
        if ($err === '') $inv = invRow((int)$inv['id']);
    }
    if ($err === '' && $action === 'send') {
        if (!$app) {
            $err = 'This invoice is not linked to an online request — send it from the Deal Builder.';
        } elseif ($isFin) {
            if ($app['status'] !== 'chosen') {
                $err = 'Saved. A finance deposit invoice goes out when you approve the deposit, after the client chooses a term (now: ' . finStatusLabel($app) . ').';
            } else {
                $err = finApproveDeposit($app, $by);
                if ($err === '') finSave($app);
            }
        } else {
            $err = invSendPreauction($app, $by);
        }
        if ($err === '') finFlash('success', 'Invoice ' . e($inv['invoice_number']) . ' sent to ' . e((string)$inv['buyer_email']) . '.');
    } elseif ($err === '' && $action === 'save') {
        finFlash('success', 'Invoice saved.' . ($inv['status'] === 'draft' ? ' It has not been sent yet.' : ' The client\'s link shows the changes.'));
    } elseif ($action === 'resend' && $app && $inv['status'] !== 'draft') {
        $ok = finMailClient($app, 'Your invoice ' . $inv['invoice_number'] . ' — ' . COMPANY_TRADING_AS,
            '<p>Here is the link to your ' . ($isFin ? 'deposit' : 'commitment fee') . ' invoice <strong>' . e($inv['invoice_number']) . '</strong> again.</p>'
            . '<p><a class="cta-btn" href="' . e(invClientLink($inv)) . '">View &amp; download your invoice</a></p>', 'Follow my request');
        finRecordMail($app, 'Invoice link re-sent', $ok);
        finLog($app, 'Invoice link re-sent', $by);
        finSave($app);
        finFlash($ok ? 'success' : 'error', $ok ? 'The invoice link was emailed again.' : 'The email could not be sent.');
    } elseif ($action === 'signature' && !empty($_FILES['signature']['tmp_name'])) {
        $f = $_FILES['signature'];
        $type = @mime_content_type($f['tmp_name']);
        if (!in_array($type, ['image/png', 'image/jpeg', 'image/webp'], true) || $f['size'] > 500000) {
            $err = 'Upload a PNG, JPG or WebP signature under 500 KB.';
        } else {
            $dir = __DIR__ . '/uploads/branding';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $name = 'signature-' . bin2hex(random_bytes(4)) . '.' . ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$type];
            if (move_uploaded_file($f['tmp_name'], "$dir/$name")) {
                leadSaveSetting('invoice_signature', 'uploads/branding/' . $name);
                finFlash('success', 'Signature saved — it now appears on every invoice.');
            } else $err = 'The signature could not be saved.';
        }
    } elseif ($action === 'signature_remove') {
        leadSaveSetting('invoice_signature', '');
        finFlash('success', 'Signature removed from invoices.');
    }
    if ($err !== '') finFlash('error', e($err));
    header("Location: $go");
    exit;
}

$a = invAmounts($inv);
$editable = in_array($inv['status'], ['draft', 'pending'], true);
$lotPhotos = $inv['auction_id'] ? invLotPhotos((int)$inv['auction_id']) : [];
$chosen = invPhotos($inv);
$photoChoices = array_values(array_unique(array_merge($chosen, $lotPhotos)));
// Every photo of the lot, not only the first four
if ($inv['auction_id']) {
    try {
        $st = $pdo->prepare("SELECT file_path FROM auction_gallery WHERE auction_id = ? AND file_path <> '' ORDER BY is_primary DESC, sort_order, id LIMIT 24");
        $st->execute([(int)$inv['auction_id']]);
        $photoChoices = array_values(array_unique(array_merge($photoChoices, $st->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $e) {}
}
[$pMin, $pMax] = paRange();
$csrf = finCsrfToken();
$statusLabel = ['draft' => 'Draft — not sent', 'pending' => 'Sent — waiting for payment', 'pop_uploaded' => 'Proof of payment uploaded',
                'approved' => 'Paid', 'declined' => 'Declined'][$inv['status']] ?? $inv['status'];
$v = fn(string $k) => e((string)($inv[$k] ?? ''));

$pageTitle = 'Invoice ' . $inv['invoice_number'];
$bodyClass = 'page-invoice-edit';
require_once __DIR__ . '/header.php';
?>
<style>
  .ie-wrap { max-width:1500px; margin:0 auto; padding:1.25rem 1rem 3rem; }
  .ie-top { display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; justify-content:space-between; margin-bottom:1rem; }
  .ie-top h1 { margin:0; font-size:1.35rem; color:#003f73; }
  .ie-badge { display:inline-block; padding:.25rem .65rem; border-radius:999px; font-size:.78rem; font-weight:700; background:#fff3cd; color:#7a5700; margin-left:.5rem; vertical-align:middle; }
  .ie-badge.sent { background:#d4edda; color:#155724; }
  .ie-grid { display:grid; grid-template-columns:minmax(340px, 560px) 1fr; gap:1.25rem; align-items:start; }
  .ie-card { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.07); padding:1.1rem 1.2rem; margin-bottom:1rem; }
  .ie-card h3 { margin:0 0 .75rem; font-size:1rem; color:#003f73; }
  .ie-f { display:grid; grid-template-columns:1fr 1fr; gap:.6rem .8rem; }
  .ie-f .full { grid-column:1 / -1; }
  .ie-f label { display:block; font-size:.78rem; font-weight:700; color:#4a5a70; margin-bottom:.2rem; }
  .ie-f .form-control { width:100%; }
  .ie-sum { display:grid; grid-template-columns:repeat(3,1fr); gap:.5rem; margin-top:.75rem; }
  .ie-sum div { background:#f2f6fb; border-radius:8px; padding:.55rem .6rem; font-size:.78rem; color:#4a5a70; }
  .ie-sum b { display:block; font-size:1rem; color:#003f73; }
  .ie-photos { display:grid; grid-template-columns:repeat(4,1fr); gap:.45rem; }
  .ie-photos label { position:relative; cursor:pointer; }
  .ie-photos img { width:100%; height:70px; object-fit:cover; border-radius:6px; border:3px solid transparent; display:block; }
  .ie-photos input { position:absolute; top:6px; left:6px; }
  .ie-photos input:checked + img { border-color:#005BA1; }
  .ie-actions { position:sticky; bottom:0; background:#fff; border-top:1px solid #e3e8ef; padding:.8rem 0 .2rem; display:flex; gap:.5rem; flex-wrap:wrap; }
  .ie-preview { position:sticky; top:1rem; background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.07); overflow:hidden; }
  .ie-preview .hd { display:flex; justify-content:space-between; align-items:center; padding:.6rem .9rem; background:#003f73; color:#fff; font-weight:700; font-size:.9rem; }
  .ie-preview .hd a { color:#fff; }
  .ie-preview iframe { width:100%; height:calc(100vh - 120px); min-height:640px; border:0; background:#e9edf3; }
  .ie-help { font-size:.8rem; color:#6b7a90; margin:.35rem 0 0; }
  @media (max-width: 980px) { .ie-grid { grid-template-columns:1fr; } .ie-preview { position:static; } .ie-preview iframe { height:80vh; } }
</style>
<div class="ie-wrap">
  <div class="ie-top">
    <h1><i class="fas fa-file-invoice"></i> <?= e(invTitle($inv)) ?> <?= e($inv['invoice_number']) ?>
      <span class="ie-badge <?= $inv['status'] !== 'draft' ? 'sent' : '' ?>"><?= e($statusLabel) ?></span></h1>
    <a class="btn btn-outline btn-sm" href="<?= e($back) ?>"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
  <?= finFlashHtml() ?>
  <?php if ($inv['status'] === 'draft'): ?>
    <div class="alert alert-info"><i class="fas fa-circle-info"></i> <span>This invoice was created automatically from the client's <?= $isFin ? 'finance application' : 'purchase request' ?>.
      Check every field, then <strong><?= $isFin ? 'save it — it is sent when you approve the deposit' : 'Send to client' ?></strong>. Nothing has been emailed yet.</span></div>
  <?php elseif (!$editable): ?>
    <div class="alert alert-warning"><i class="fas fa-lock"></i> <span>This invoice is <?= e(strtolower($statusLabel)) ?>, so it can no longer be changed.</span></div>
  <?php else: ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation"></i> <span>Already sent on <?= e(safeDate('d M Y H:i', $inv['sent_at'])) ?>. Changes show on the client's link straight away — use <strong>Email the link again</strong> to tell them.</span></div>
  <?php endif; ?>

  <div class="ie-grid">
    <form method="post" id="ieForm">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <fieldset <?= $editable ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
      <div class="ie-card">
        <h3>Client (Bill to)</h3>
        <div class="ie-f">
          <div class="full"><label>Full name</label><input class="form-control" name="buyer_name" value="<?= $v('buyer_name') ?>" required></div>
          <div><label>Phone</label><input class="form-control" name="buyer_phone" value="<?= $v('buyer_phone') ?>"></div>
          <div><label>ID / passport no.</label><input class="form-control" name="buyer_id_passport" value="<?= $v('buyer_id_passport') ?>"></div>
          <div class="full"><label>Email (the invoice is sent here)</label><input class="form-control" type="email" name="buyer_email" value="<?= $v('buyer_email') ?>"></div>
          <div class="full"><label>Address</label><input class="form-control" name="buyer_address" value="<?= $v('buyer_address') ?>"></div>
        </div>
      </div>

      <div class="ie-card">
        <h3>Invoice</h3>
        <div class="ie-f">
          <div><label>Invoice date</label><input class="form-control" type="date" name="invoice_date" value="<?= $v('invoice_date') ?>"></div>
          <div><label>Due date</label><input class="form-control" name="due_text" value="<?= $v('due_text') ?>" placeholder="On receipt"></div>
          <div><label>Vehicle price (R)</label><input class="form-control" name="total_price" id="iePrice" value="<?= e(number_format($a['price'], 2, '.', '')) ?>" required></div>
          <?php if ($isFin): ?>
            <div><label>Deposit (R)</label><input class="form-control" value="<?= e(number_format($a['fee'], 2, '.', '')) ?>" disabled>
              <p class="ie-help">From the application. Change it with <em>Suggest Higher Deposit</em>.</p></div>
          <?php else: ?>
            <div><label>Commitment fee (%)</label><input class="form-control" type="number" step="0.5" min="1" max="99" name="commitment_fee_pct" id="iePct" value="<?= e((string)$a['pct']) ?>" required>
              <p class="ie-help">Usual range <?= $pMin ?>–<?= $pMax ?>%.</p></div>
          <?php endif; ?>
        </div>
        <div class="ie-sum">
          <div><?= $isFin ? 'Deposit' : 'Commitment fee' ?><b id="ieFee"><?= e(formatRand($a['fee'])) ?></b></div>
          <div><?= $isFin ? 'To be financed' : 'Outstanding' ?><b id="ieRest"><?= e(formatRand($a['rest'])) ?></b></div>
          <div>Balance due<b id="ieDue"><?= e(formatRand($a['fee'])) ?></b></div>
        </div>
      </div>

      <div class="ie-card">
        <h3>Vehicle</h3>
        <div class="ie-f">
          <div class="full"><label>Description (the line on the invoice)</label><input class="form-control" name="vehicle_description" value="<?= $v('vehicle_description') ?>" required></div>
          <div><label>Year</label><input class="form-control" name="vehicle_year" value="<?= $v('vehicle_year') ?>"></div>
          <div><label>Mileage</label><input class="form-control" name="vehicle_mileage" value="<?= $v('vehicle_mileage') ?>"></div>
          <div><label>Transmission</label><input class="form-control" name="vehicle_transmission" value="<?= $v('vehicle_transmission') ?>"></div>
          <div><label>Fuel</label><input class="form-control" name="vehicle_fuel" value="<?= $v('vehicle_fuel') ?>"></div>
          <div><label>Body</label><input class="form-control" name="vehicle_body" value="<?= $v('vehicle_body') ?>"></div>
          <div><label>Colour</label><input class="form-control" name="vehicle_colour" value="<?= $v('vehicle_colour') ?>"></div>
          <div><label>Condition</label><input class="form-control" name="vehicle_condition" value="<?= $v('vehicle_condition') ?>"></div>
          <div class="full"><label>Features (one per line)</label><textarea class="form-control" name="vehicle_features" rows="3"><?= $v('vehicle_features') ?></textarea></div>
        </div>
      </div>

      <div class="ie-card">
        <h3>Photos on the invoice <small style="font-weight:400;color:#6b7a90">(tick up to <?= INV_PHOTOS_MAX ?>)</small></h3>
        <?php if ($photoChoices): ?>
          <div class="ie-photos">
            <?php foreach ($photoChoices as $p): ?>
              <label><input type="checkbox" name="photos[]" value="<?= e($p) ?>" <?= in_array($p, $chosen, true) ? 'checked' : '' ?> class="ie-ph"><img src="<?= e(cldImg($p, CLD_THUMB)) ?>" alt=""></label>
            <?php endforeach; ?>
          </div>
        <?php else: ?><p class="ie-help">This lot has no photos.</p><?php endif; ?>
      </div>

      <div class="ie-card">
        <h3>Note on the invoice</h3>
        <textarea class="form-control" name="note_text" rows="4" placeholder="<?= e(invDefaultNote($inv)) ?>"><?= e((string)($inv['note_text'] ?? '')) ?></textarea>
        <p class="ie-help">Leave empty to use the standard note shown above. It updates itself when the amounts change.</p>
      </div>
      </fieldset>

      <div class="ie-actions">
        <?php if ($editable): ?>
          <button class="btn btn-outline" name="action" value="save" type="submit"><i class="fas fa-save"></i> Save</button>
          <?php if ($inv['status'] === 'draft' && !$isFin): ?>
            <button class="btn btn-primary" name="action" value="send" type="submit" onclick="return confirm('Save and email this invoice to <?= e(addslashes((string)$inv['buyer_email'])) ?>? It is valid for 24 hours.')"><i class="fas fa-paper-plane"></i> Save &amp; Send to Client</button>
          <?php elseif ($inv['status'] === 'draft' && $isFin && $app && $app['status'] === 'chosen'): ?>
            <button class="btn btn-primary" name="action" value="send" type="submit" onclick="return confirm('Save, approve the deposit and email this invoice to the client?')"><i class="fas fa-paper-plane"></i> Save, Approve Deposit &amp; Send</button>
          <?php elseif ($inv['status'] === 'pending' && !$isFin && $app && in_array($app['status'], ['expired', 'invoiced'], true) && strtotime((string)$inv['expires_at']) < time()): ?>
            <button class="btn btn-primary" name="action" value="send" type="submit"><i class="fas fa-rotate-right"></i> Save &amp; Re-send (new 24 hours)</button>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($inv['status'] !== 'draft' && $app): ?>
          <button class="btn btn-outline" name="action" value="resend" type="submit" formnovalidate><i class="fas fa-envelope"></i> Email the link again</button>
        <?php endif; ?>
        <a class="btn btn-outline" href="invoice_view.php?id=<?= (int)$inv['id'] ?>" target="_blank"><i class="fas fa-print"></i> Open / Print</a>
      </div>
    </form>

    <div>
      <div class="ie-preview">
        <div class="hd"><span><i class="fas fa-eye"></i> Preview (saved version)</span><a href="invoice_view.php?id=<?= (int)$inv['id'] ?>" target="_blank">Open full size</a></div>
        <iframe src="invoice_view.php?id=<?= (int)$inv['id'] ?>&amp;embed=1" title="Invoice preview"></iframe>
      </div>
      <div class="ie-card" style="margin-top:1rem">
        <h3>Authorised signature</h3>
        <?php $sig = invSignatureDataUri(); ?>
        <?php if ($sig !== ''): ?><img src="<?= $sig ?>" alt="" style="max-height:60px;display:block;margin-bottom:.6rem"><?php endif; ?>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="file" name="signature" accept="image/png,image/jpeg,image/webp" required>
          <button class="btn btn-outline btn-sm" name="action" value="signature" type="submit"><i class="fas fa-upload"></i> <?= $sig ? 'Replace' : 'Upload' ?> signature</button>
          <?php if ($sig): ?><button class="btn btn-outline btn-sm" name="action" value="signature_remove" type="submit" formnovalidate>Remove</button><?php endif; ?>
        </form>
        <p class="ie-help">One image for all invoices: a PNG with a transparent background looks best. It is stored on the server only, not in the code.</p>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var price = document.getElementById('iePrice'), pct = document.getElementById('iePct');
  var fmt = function (n) { return 'R' + n.toLocaleString('en-ZA', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
  function upd() {
    if (!pct) return;
    var p = parseFloat((price.value || '').replace(/[^\d.]/g, '')) || 0, f = Math.round(p * (parseFloat(pct.value) || 0)) / 100;
    document.getElementById('ieFee').textContent = fmt(f);
    document.getElementById('ieDue').textContent = fmt(f);
    document.getElementById('ieRest').textContent = fmt(p - f);
  }
  [price, pct].forEach(function (el) { if (el) el.addEventListener('input', upd); });
  document.querySelectorAll('.ie-ph').forEach(function (cb) {
    cb.addEventListener('change', function () {
      if (document.querySelectorAll('.ie-ph:checked').length > <?= INV_PHOTOS_MAX ?>) { cb.checked = false; alert('Up to <?= INV_PHOTOS_MAX ?> photos fit on the invoice.'); }
    });
  });
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
