<?php
// ============================================================
// AUCTION HUB GROUP — admin_applications_tab.php
// Admin → Finance & Pre-Auction. Included by admin_dashboard.php (tab=applications).
// List of in-house finance applications and pre-auction requests, one
// application at a time with the next action on top, and the settings.
// Actions post to applications_api.php.
// ============================================================
require_once __DIR__ . '/finance_helper.php';
ahgSchemaReady();

$faView = $_GET['view'] ?? 'open';
$faKind = in_array($_GET['kind'] ?? '', ['finance', 'preauction'], true) ? $_GET['kind'] : '';
$faQ    = trim((string)($_GET['q'] ?? ''));
$faCsrf = e(finCsrfToken());
$faOne  = !empty($_GET['app']) ? finGet((int)$_GET['app']) : null;
$faOpenStatuses = ['quoted', 'chosen', 'invoiced', 'approved', 'requested', 'countered', 'confirmed', 'paid'];
$faTodo = ['chosen', 'requested', 'invoiced', 'confirmed'];   // waiting on the office

$where = []; $args = [];
if ($faKind) { $where[] = 'kind = ?'; $args[] = $faKind; }
if ($faView === 'open')  $where[] = "status IN ('" . implode("','", $faOpenStatuses) . "')";
if ($faView === 'todo')  $where[] = "status IN ('" . implode("','", $faTodo) . "')";
if ($faView === 'closed') $where[] = "status NOT IN ('" . implode("','", $faOpenStatuses) . "')";
if ($faQ !== '') { $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ? OR vehicle LIKE ? OR buyer_code = ? OR id = ?)'; array_push($args, "%$faQ%", "%$faQ%", "%$faQ%", "%$faQ%", $faQ, (int)preg_replace('/\D/', '', $faQ)); }
$faRows = [];
$faCounts = ['todo' => 0, 'open' => 0];
try {
    $st = $pdo->prepare("SELECT * FROM client_applications" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY updated_at DESC LIMIT 300");
    $st->execute($args);
    $faRows = array_map('finDecode', $st->fetchAll());
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM client_applications GROUP BY status") as $r) {
        if (in_array($r['status'], $faTodo, true)) $faCounts['todo'] += $r['n'];
        if (in_array($r['status'], $faOpenStatuses, true)) $faCounts['open'] += $r['n'];
    }
} catch (Throwable $e) { error_log('[AHG applications tab] ' . $e->getMessage()); }

$faPill = function (array $a): string {
    $c = in_array($a['status'], ['chosen', 'requested', 'invoiced', 'confirmed'], true) ? '#b26a00;background:#fff4d6'
       : (in_array($a['status'], ['approved', 'paid', 'completed'], true) ? '#167a39;background:#e3f4ea'
       : (in_array($a['status'], ['declined', 'cancelled', 'not_ready', 'refund'], true) ? '#a1271b;background:#fdecea' : '#0b4a80;background:#eef5fc'));
    return '<span style="display:inline-block;border-radius:50px;padding:.15rem .6rem;font-size:.74rem;font-weight:700;color:' . $c . '">' . e(finStatusLabel($a)) . '</span>';
};
?>
<style>
.ap-adm .card{background:#fff;border:1px solid #e3e9f0;border-radius:10px;padding:1.1rem 1.25rem;margin-bottom:1rem}
.ap-adm h3{color:#003f73;margin:0 0 .6rem;font-size:1.05rem}
.ap-adm table{width:100%;border-collapse:collapse;font-size:.9rem}
.ap-adm th{background:#f3f7fb;color:#003f73;text-align:left;padding:.55rem .6rem;font-size:.78rem;text-transform:uppercase}
.ap-adm td{padding:.55rem .6rem;border-bottom:1px solid #eef1f5;vertical-align:top}
.ap-adm .chips{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.8rem}
.ap-adm .chip{padding:.35rem .8rem;border-radius:50px;border:1px solid #cfd9e4;color:#335;text-decoration:none;font-size:.85rem;font-weight:600}
.ap-adm .chip.on{background:#005BA1;color:#fff;border-color:#005BA1}
.ap-adm .kv{display:grid;grid-template-columns:160px 1fr;gap:.35rem .8rem;font-size:.9rem}
.ap-adm .kv span:nth-child(odd){color:#667}
.ap-adm .act{display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin:.4rem 0}
.ap-adm .act input,.ap-adm .act select,.ap-adm .act textarea{padding:.45rem .6rem;border:1px solid #cfd9e4;border-radius:6px;font-size:.9rem}
.ap-adm .two{display:grid;grid-template-columns:1.3fr 1fr;gap:1rem}
@media(max-width:980px){.ap-adm .two{grid-template-columns:1fr}}
.ap-adm .next{border-left:4px solid #f5a623;background:#fffaf0}
.ap-adm .log{font-size:.82rem;color:#556;max-height:260px;overflow:auto;margin:0;padding-left:1rem}
</style>
<div class="ap-adm">
<div class="dashboard-header">
  <div>
    <h1><i class="fas fa-handshake"></i> Finance &amp; Pre-Auction</h1>
    <p class="text-muted">In-house finance applications (quotation → plan → deposit invoice → documents → approval &amp; Buyer's Code → test drive) and pre-auction / Buy Now requests (request → approval → confirm → commitment paid → Buyer's Code → decision).</p>
  </div>
  <div class="act">
    <a class="btn btn-sm btn-outline" href="<?= APP_URL ?>/finance_apply.php" target="_blank"><i class="fas fa-up-right-from-square"></i> Finance form</a>
    <a class="btn btn-sm btn-outline" href="<?= APP_URL ?>/preauction_request.php" target="_blank"><i class="fas fa-up-right-from-square"></i> Pre-auction form</a>
    <a class="btn btn-sm btn-outline" href="?tab=applications&amp;view=settings"><i class="fas fa-sliders"></i> Settings</a>
  </div>
</div>
<?= finFlashHtml() ?>

<?php if ($faView === 'settings'): ?>
  <form class="card" method="post" action="applications_api.php">
    <input type="hidden" name="csrf" value="<?= $faCsrf ?>"><input type="hidden" name="action" value="settings">
    <h3>In-house finance</h3>
    <div class="act">
      <label>Min deposit — cars &amp; bakkies (R)<br><input name="fin_min_deposit" value="<?= e(finSetting('fin_min_deposit', '10500')) ?>"></label>
      <label>Min deposit — trucks &amp; plant (R)<br><input name="fin_min_deposit_heavy" value="<?= e(finSetting('fin_min_deposit_heavy', '50000')) ?>"></label>
    </div>
    <p class="text-muted" style="font-size:.85rem">Flat rate per month by term (the same model as the calculator on financing.php). Cars &amp; bakkies get 6–48 months; trucks &amp; plant up to 72.</p>
    <div class="act">
      <?php foreach (finTiers(['body_type' => 'truck']) as $m => $r): ?>
        <label><?= $m ?> months (% p/m)<br><input name="fin_rate_<?= $m ?>" value="<?= e((string)$r) ?>" style="width:90px"></label>
      <?php endforeach; ?>
    </div>
    <h3 style="margin-top:1rem">Pre-auction commitment</h3>
    <?php [$mn, $mx, $df] = paRange(); ?>
    <div class="act">
      <label>Lowest %<br><input name="pa_min_percent" value="<?= $mn ?>" style="width:80px"></label>
      <label>Highest %<br><input name="pa_max_percent" value="<?= $mx ?>" style="width:80px"></label>
      <label>Default %<br><input name="pa_default_percent" value="<?= $df ?>" style="width:80px"></label>
    </div>
    <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save settings</button>
    <a class="btn btn-outline" href="?tab=applications">Back</a>
  </form>

<?php elseif ($faOne): $a = $faOne; $d = $a['details']; $isFin = $a['kind'] === 'finance'; $plan = $isFin ? finChosenPlan($a) : null; $wa = finAdminWhatsAppUrl($a); ?>
<?php $form = fn(string $action, string $inner, string $btn, string $cls = 'btn-primary', string $confirm = '') =>
            '<form method="post" action="applications_api.php" class="act"><input type="hidden" name="csrf" value="' . $faCsrf . '"><input type="hidden" name="id" value="' . (int)$a['id'] . '"><input type="hidden" name="action" value="' . $action . '">'
            . $inner . '<button class="btn btn-sm ' . $cls . '" type="submit"' . ($confirm !== '' ? ' onclick="return confirm(\'' . e($confirm) . '\')"' : '') . '>' . $btn . '</button></form>'; ?>
  <p><a href="?tab=applications">← All applications</a></p>
  <div class="two">
    <div>
      <div class="card">
        <h3><?= $isFin ? 'In-house finance' : 'Pre-auction' ?> <?= e(finRef($a)) ?> — <?= e($a['name']) ?></h3>
        <p><?= $faPill($a) ?></p>
        <div class="kv">
          <span>Vehicle</span><span><a href="<?= APP_URL ?>/auction.php?id=<?= (int)$a['auction_id'] ?>" target="_blank"><?= e($a['vehicle']) ?></a> · lot <?= (int)$a['auction_id'] ?></span>
          <span>VIN</span><span><?= e(vinForAuction((int)$a['auction_id']) ?: '— (not captured)') ?></span>
          <span>Price</span><span><?= formatRand((float)$a['price']) ?></span>
          <?php if ($isFin): ?>
            <span>Deposit</span><span><?= formatRand((float)$a['amount']) ?><?= !empty($d['quote']['counter']) ? ' (our suggestion)' : '' ?></span>
            <span>Plan</span><span><?= $plan ? (int)$plan['months'] . ' months × ' . formatRand($plan['monthly']) . ' (total ' . formatRand($plan['total']) . ')' : '— not chosen yet' ?></span>
            <span>Employment</span><span><?= e($d['employment'] ?? '—') ?><?= !empty($d['income']) ? ' · income ' . formatRand((float)$d['income']) . '/mo' : '' ?></span>
          <?php else: ?>
            <span>Commitment</span><span><?= (int)$a['percent'] ?>% asked<?= isset($d['office_percent']) ? ' · ' . (int)$d['office_percent'] . '% approved/suggested' : '' ?> = <?= formatRand(paCommitment($a)) ?></span>
            <span>Alternative</span><span><?= e($d['alt']['title'] ?? '—') ?></span>
            <span>Mechanic</span><span><?= !empty($d['mechanic']) ? 'Yes' : 'No' ?></span>
            <?php if (!empty($d['gate'])): ?><span>Gate pass</span><span>ID <?= e($d['gate']['id_number']) ?> · <?= (int)$d['gate']['people'] ?> person(s)<?= $d['gate']['car_reg'] !== '' ? ' · car ' . e($d['gate']['car_reg']) : '' ?></span><?php endif; ?>
            <?php if (!empty($d['decision'])): ?><span>Decision</span><span><strong><?= e($d['decision']['choice']) ?></strong> <?= e($d['decision']['reason']) ?></span><?php endif; ?>
          <?php endif; ?>
          <span>Buyer's Code</span><span><strong><?= e($a['buyer_code'] ?: '—') ?></strong></span>
          <span>Test drive</span><span><?= !empty($d['viewing']['date']) ? e(finViewingLabel($d['viewing'])) . ' (' . e($d['viewing']['status']) . ')' : '—' ?></span>
          <span>Phone</span><span><a href="tel:<?= e($a['phone']) ?>"><?= e($a['phone']) ?></a></span>
          <span>Email</span><span><a href="mailto:<?= e($a['email']) ?>"><?= e($a['email']) ?></a></span>
          <span>Applied</span><span><?= safeDate('j M Y H:i', $a['created_at']) ?></span>
          <?php if (!empty($d['message'])): ?><span>Message</span><span><?= nl2br(e($d['message'])) ?></span><?php endif; ?>
        </div>
        <div class="act" style="margin-top:.8rem">
          <?php if ($wa): ?><a class="btn btn-sm btn-success" href="<?= e($wa) ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> WhatsApp next step</a><?php endif; ?>
          <a class="btn btn-sm btn-outline" href="<?= e(finLink($a)) ?>" target="_blank"><i class="fas fa-eye"></i> Client's page</a>
          <?php foreach (($isFin ? ['quote' => 'Quotation', 'counter' => 'Deposit letter', 'invoice' => 'Invoice', 'approval' => 'Approval letter', 'decline' => 'Decline letter']
                                 : ['letter' => 'Commitment letter', 'invoice' => 'Invoice', 'code' => 'Buyer\'s Code letter', 'decline' => 'Decline letter']) as $k => $l): ?>
            <a class="btn btn-sm btn-outline" href="<?= APP_URL ?>/application_doc.php?id=<?= (int)$a['id'] ?>&amp;doc=<?= $k ?>" target="_blank"><?= e($l) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php // ── The next action ── ?>
      <?php if (!finClosed($a)): ?>
      <div class="card next">
        <h3><i class="fas fa-bolt"></i> Next step</h3>

        <?php if ($isFin && $a['status'] === 'quoted'): ?>
          <p>Waiting for the client to choose a plan. You can resend the quotation, or suggest a higher deposit now.</p>
          <?= $form('resend', '<input type="hidden" name="what" value="quote">', '<i class="fas fa-paper-plane"></i> Email the quotation again', 'btn-outline') ?>
        <?php endif; ?>
        <?php if ($isFin && $a['status'] === 'chosen'): ?>
          <p>The client chose <strong><?= $plan ? (int)$plan['months'] . ' months at ' . formatRand($plan['monthly']) : '—' ?></strong> on a deposit of <strong><?= formatRand((float)$a['amount']) ?></strong>. Approve it, suggest a higher deposit, or decline.</p>
          <?= $form('approve_deposit', '', '<i class="fas fa-check"></i> Approve deposit &amp; send invoice', 'btn-success') ?>
        <?php endif; ?>
        <?php if ($isFin && in_array($a['status'], ['quoted', 'chosen'], true)): ?>
          <?= $form('counter_deposit', '<label>Suggest deposit (R)<br><input name="deposit" required style="width:130px"></label><label>Note to the client<br><input name="note" style="width:260px" placeholder="optional"></label>', 'Suggest higher deposit', 'btn-outline') ?>
        <?php endif; ?>
        <?php if ($isFin && $a['status'] === 'invoiced'): $sum = finDocsSummary($a); ?>
          <p>Deposit invoice <?= e($d['invoice']['number']) ?> sent <?= safeDate('j M', $d['invoice']['issued']) ?> (due <?= safeDate('j M', $d['invoice']['due']) ?>). Documents: <?= $sum['uploaded'] ?>/<?= $sum['total'] ?> uploaded, <?= $sum['accepted'] ?> accepted.</p>
          <p class="text-muted" style="font-size:.85rem">Check the bank first. Once the deposit has reflected and the documents are in order, approve: the approval letter and Buyer's Code go out by themselves.</p>
          <?= $form('approve', '', '<i class="fas fa-award"></i> Deposit reflected — approve &amp; issue Buyer\'s Code', 'btn-success', 'Has the deposit reflected in the bank?') ?>
        <?php endif; ?>

        <?php if (!$isFin && in_array($a['status'], ['requested', 'countered'], true)): [$mn, $mx] = paRange(); ?>
          <p>They asked for <strong><?= (int)$a['percent'] ?>%</strong> (<?= formatRand((float)$a['price'] * $a['percent'] / 100) ?>). Approve it, suggest another, or decline.</p>
          <?= $form('pa_approve', '<input type="hidden" name="percent" value="' . (int)$a['percent'] . '"><label>Note (in the letter)<br><input name="note" style="width:240px" placeholder="optional"></label>', '<i class="fas fa-check"></i> Approve ' . paPercent($a) . '% &amp; send letter', 'btn-success') ?>
          <?php $opts = ''; for ($p = $mn; $p <= $mx; $p += 5) $opts .= '<option' . ($p === paPercent($a) ? ' selected' : '') . '>' . $p . '</option>'; ?>
          <?= $form('pa_counter', '<label>Suggest %<br><select name="percent">' . $opts . '</select></label><label>Note to the client<br><input name="note" style="width:240px" placeholder="optional"></label>', 'Suggest this %', 'btn-outline') ?>
        <?php endif; ?>
        <?php if (!$isFin && $a['status'] === 'approved'): ?>
          <p>Approved at <?= paPercent($a) ?>% — waiting for the client to read the commitment letter and confirm (with gate-pass details). The invoice is issued when they confirm.</p>
          <?= $form('resend', '<input type="hidden" name="what" value="letter">', '<i class="fas fa-paper-plane"></i> Email the commitment letter again', 'btn-outline') ?>
        <?php endif; ?>
        <?php if (!$isFin && $a['status'] === 'confirmed'): ?>
          <p>Commitment invoice <?= e($d['invoice']['number']) ?> for <strong><?= formatRand((float)$a['amount']) ?></strong> sent <?= safeDate('j M', $d['invoice']['issued']) ?> (due <?= safeDate('j M', $d['invoice']['due']) ?>). Proof of payment: <?= !empty($d['docs']['payment']['files']) ? 'uploaded' : 'not yet' ?>.</p>
          <?= $form('pa_paid', '', '<i class="fas fa-key"></i> Commitment reflected — send Buyer\'s Code', 'btn-success', 'Has the commitment reflected in the bank?') ?>
        <?php endif; ?>

        <?php if (($isFin && $a['status'] === 'approved') || (!$isFin && $a['status'] === 'paid')): ?>
          <?= $form('viewing', '<label>Test-drive day<br><input type="date" name="date" required value="' . e($d['viewing']['date'] ?? '') . '"></label><label>Time<br><input type="time" name="time" required value="' . e($d['viewing']['time'] ?? '10:00') . '" step="1800"></label><label>Note<br><input name="note" style="width:200px" placeholder="optional"></label>', '<i class="fas fa-calendar-check"></i> Confirm test drive', 'btn-primary') ?>
          <?= $form('complete', '<label>Note<br><input name="note" style="width:240px" placeholder="e.g. agreement signed, balance paid"></label>', '<i class="fas fa-flag-checkered"></i> Mark completed', 'btn-success', 'Mark this completed (signed / paid and delivered)?') ?>
        <?php endif; ?>

        <?php if (!in_array($a['status'], ['completed'], true)): ?>
          <details style="margin-top:.6rem"><summary style="cursor:pointer;color:#a1271b;font-weight:600">Decline…</summary>
            <?= $form('decline', '<label>Reason (shown politely in the letter)<br><textarea name="note" rows="2" style="width:340px"></textarea></label>', 'Decline &amp; send letter', 'btn-danger', 'Decline and send the letter?') ?>
          </details>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php // ── Documents ── ?>
      <?php if (!empty($d['docs'])): ?>
      <div class="card">
        <h3>Documents</h3>
        <table><tr><th>Document</th><th>Files</th><th>Status</th><th></th></tr>
        <?php foreach (finDocKinds($a) as $k): $doc = $d['docs'][$k] ?? null; ?>
          <tr><td><?= e(FIN_DOCS[$k][0]) ?></td>
            <td><?php foreach ($doc['files'] ?? [] as $f): ?><a href="<?= APP_URL ?>/application_file.php?id=<?= (int)$a['id'] ?>&amp;f=<?= e($f['file']) ?>" target="_blank"><i class="fas fa-paperclip"></i> <?= e($f['original']) ?></a><br><?php endforeach; ?><?= empty($doc['files']) ? '<span class="text-muted">—</span>' : '' ?></td>
            <td><?= e($doc['status'] ?? 'needed') ?><?= !empty($doc['note']) ? '<br><small>' . e($doc['note']) . '</small>' : '' ?></td>
            <td><?php if (!empty($doc['files']) && ($doc['status'] ?? '') !== 'accepted'): ?>
              <?= $form('doc', '<input type="hidden" name="doc" value="' . $k . '"><input type="hidden" name="status" value="accepted">', 'Accept', 'btn-success') ?>
              <?= $form('doc', '<input type="hidden" name="doc" value="' . $k . '"><input type="hidden" name="status" value="rejected"><input name="note" placeholder="Why? (emailed)" style="width:150px">', 'Ask again', 'btn-outline') ?>
            <?php endif; ?></td></tr>
        <?php endforeach; ?>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div>
      <?php if ($isFin && !empty($d['quote'])): ?>
      <div class="card"><h3>Quotation <?= e($d['quote']['number']) ?></h3>
        <table><tr><th>Term</th><th>Rate</th><th>Monthly</th><th>Total</th></tr>
          <?php foreach ($d['quote']['plans'] as $p): ?><tr<?= $plan && $plan['months'] === $p['months'] ? ' style="background:#f1fbf5;font-weight:700"' : '' ?>><td><?= $p['months'] ?> m</td><td><?= $p['rate'] ?>%</td><td><?= formatRand($p['monthly']) ?></td><td><?= formatRand($p['total']) ?></td></tr><?php endforeach; ?>
        </table></div>
      <?php endif; ?>
      <form class="card" method="post" action="applications_api.php">
        <input type="hidden" name="csrf" value="<?= $faCsrf ?>"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="action" value="note">
        <h3>Office notes</h3>
        <?php foreach ($d['notes'] ?? [] as $n): ?><p style="font-size:.86rem;margin:.3rem 0"><strong><?= e($n['by']) ?></strong> <?= safeDate('j M H:i', $n['at']) ?>: <?= nl2br(e($n['text'])) ?></p><?php endforeach; ?>
        <textarea name="note" rows="2" style="width:100%;padding:.45rem;border:1px solid #cfd9e4;border-radius:6px" placeholder="Only the office sees this"></textarea>
        <button class="btn btn-sm btn-outline" type="submit" style="margin-top:.4rem">Save note</button>
      </form>
      <div class="card"><h3>History</h3><ul class="log">
        <?php foreach (array_reverse($d['log'] ?? []) as $l): ?><li><?= safeDate('j M H:i', $l['at']) ?> · <?= e($l['by']) ?> — <?= e($l['what']) ?></li><?php endforeach; ?>
      </ul>
      <?php if (!empty($d['emails'])): ?><h3 style="margin-top:.8rem">Emails</h3><ul class="log">
        <?php foreach (array_reverse($d['emails']) as $m): ?><li><?= safeDate('j M H:i', $m['at']) ?> — <?= e($m['what']) ?> <?= $m['ok'] ? '✓' : '<strong style="color:#c00">FAILED</strong>' ?></li><?php endforeach; ?>
      </ul><?php endif; ?></div>
    </div>
  </div>

<?php else: ?>
  <div class="card">
    <div class="chips">
      <?php foreach (['todo' => 'Waiting on us <b>' . $faCounts['todo'] . '</b>', 'open' => 'Open <b>' . $faCounts['open'] . '</b>', 'closed' => 'Closed', 'all' => 'All'] as $k => $l): ?>
        <a class="chip<?= $faView === $k ? ' on' : '' ?>" href="?tab=applications&amp;view=<?= $k ?>&amp;kind=<?= e($faKind) ?>"><?= $l ?></a>
      <?php endforeach; ?>
      <span style="flex:1"></span>
      <?php foreach (['' => 'Both', 'finance' => 'Finance', 'preauction' => 'Pre-auction'] as $k => $l): ?>
        <a class="chip<?= $faKind === $k ? ' on' : '' ?>" href="?tab=applications&amp;view=<?= e($faView) ?>&amp;kind=<?= $k ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>
    <form class="act" method="get"><input type="hidden" name="tab" value="applications"><input type="hidden" name="view" value="all">
      <input name="q" value="<?= e($faQ) ?>" placeholder="Name, phone, email, vehicle, Buyer's Code, ref…" style="flex:1;min-width:220px">
      <button class="btn btn-sm btn-primary" type="submit">Search</button></form>
  </div>
  <div class="card" style="overflow-x:auto">
    <?php if (!$faRows): ?>
      <p class="text-muted">Nothing here yet. Applications arrive from <a href="<?= APP_URL ?>/finance_apply.php" target="_blank">finance_apply.php</a> and <a href="<?= APP_URL ?>/preauction_request.php" target="_blank">preauction_request.php</a> (also linked from every lot without bids).</p>
    <?php else: ?>
    <table>
      <tr><th>Ref</th><th>Client</th><th>Vehicle</th><th>Figures</th><th>Where it stands</th><th>Updated</th></tr>
      <?php foreach ($faRows as $a): ?>
        <tr onclick="location.href='?tab=applications&amp;app=<?= (int)$a['id'] ?>'" style="cursor:pointer">
          <td><a href="?tab=applications&amp;app=<?= (int)$a['id'] ?>"><?= e(finRef($a)) ?></a><br><small><?= $a['kind'] === 'finance' ? 'Finance' : 'Pre-auction' ?></small></td>
          <td><strong><?= e($a['name']) ?></strong><br><small><?= e($a['phone']) ?></small></td>
          <td><?= e($a['vehicle']) ?><br><small><?= formatRand((float)$a['price']) ?></small></td>
          <td><?= $a['kind'] === 'finance' ? 'Deposit ' . formatRand((float)$a['amount']) . ($a['months'] ? '<br><small>' . (int)$a['months'] . ' months</small>' : '') : (int)paPercent($a) . '% = ' . formatRand(paCommitment($a)) ?></td>
          <td><?= $faPill($a) ?><?= $a['buyer_code'] ? '<br><small>Code ' . e($a['buyer_code']) . '</small>' : '' ?></td>
          <td><small><?= safeDate('j M H:i', $a['updated_at']) ?></small></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
