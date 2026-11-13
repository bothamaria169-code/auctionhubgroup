<?php
// ============================================================
// AUCTION HUB GROUP — application_doc.php?t=TOKEN&doc=…
// In-house finance documents, on the same A4 layout as invoice_print.php
// (print or "Save as PDF"):  quote · invoice (deposit) · approval · decline
// Pre-auction purchases use the existing proforma invoice
// (preauction_invoices / invoice_pop_upload.php), not this file.
// Admins open any application with ?id=…&doc=… (their login).
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';

$app = finFind((string)($_GET['t'] ?? ''));
if (!$app && !empty($_GET['id'])) {
    $u = getPageUser();
    if (($u['role'] ?? '') === 'admin') $app = finGet((int)$_GET['id']);
}
if (!$app || $app['kind'] !== 'finance') { http_response_code(404); exit('Document not found.'); }

$doc = (string)($_GET['doc'] ?? '');
$d   = $app['details'];
$s   = $app['status'];
$allowed = ['quote' => !empty($d['quote']), 'invoice' => !empty($d['invoice']),
            'approval' => in_array($s, ['approved', 'completed'], true), 'decline' => $s === 'declined'];
if (empty($allowed[$doc])) { http_response_code(404); exit('This document is not available yet.'); }

$bank  = getBankSettings();
$v     = $d['vehicle'] ?? [];
$q     = $d['quote'] ?? [];
$plan  = finChosenPlan($app);
$vin   = $doc === 'approval' ? vinForAuction((int)$app['auction_id']) : '';
$R     = fn($n) => 'R' . number_format((float)$n, 2, '.', ',');
$tag   = ['quote' => 'Finance Quote', 'invoice' => 'Deposit Invoice', 'approval' => 'Finance Approval', 'decline' => 'Notice'][$doc];
$num   = match ($doc) { 'quote' => $q['number'], 'invoice' => $d['invoice']['number'], default => finRef($app) };
$date  = match ($doc) { 'quote' => $q['issued'], 'invoice' => $d['invoice']['issued'], 'approval' => $d['approved_at'] ?? $app['updated_at'], default => $d['declined']['at'] ?? $app['updated_at'] };
header('X-Robots-Tag: noindex, nofollow');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($tag . ' ' . $num . ' — ' . COMPANY_NAME) ?></title>
<style>
  * { box-sizing:border-box; margin:0; padding:0; }
  :root{ --navy:#001F5C; --blue:#005DDB; --ink:#1a1a1a; --muted:#5b6b7f; --line:#e2e8f0; --soft:#f6f8fc; }
  html{ font-size:10pt; }
  body{ font-family:'Helvetica Neue',Arial,sans-serif; color:var(--ink); background:#eef1f6; line-height:1.5; }
  .toolbar{ position:sticky; top:0; z-index:50; background:linear-gradient(90deg,var(--navy),#003580); color:#fff; display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:10px 16px; flex-wrap:wrap; }
  .toolbar h2{ font-size:.95rem; font-weight:700; }
  .toolbar span{ font-size:.74rem; opacity:.8; }
  .tbtn{ padding:.5rem 1rem; border-radius:6px; font-weight:700; font-size:.82rem; cursor:pointer; border:none; text-decoration:none; display:inline-flex; gap:.4rem; }
  .tbtn-primary{ background:#fff; color:var(--navy); }
  .tbtn-ghost{ background:rgba(255,255,255,.12); color:#fff; border:1px solid rgba(255,255,255,.35); }
  .sheet{ width:210mm; max-width:100%; min-height:297mm; margin:22px auto; background:#fff; box-shadow:0 6px 30px rgba(0,0,0,.14); padding:12mm 13mm 10mm; }
  .head{ display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding-bottom:12px; }
  .brand{ display:flex; gap:14px; align-items:center; }
  .brand img{ width:66px; height:66px; object-fit:contain; }
  .co-name{ font-size:16pt; font-weight:900; color:var(--navy); letter-spacing:.02em; text-transform:uppercase; line-height:1.05; }
  .co-reg{ font-size:7.5pt; font-weight:700; color:var(--blue); text-transform:uppercase; letter-spacing:.04em; margin-top:2px; }
  .co-meta{ font-size:8pt; color:var(--muted); margin-top:5px; line-height:1.55; }
  .doc-title{ text-align:right; }
  .doc-title .tag{ font-size:17pt; font-weight:900; color:var(--navy); letter-spacing:.06em; text-transform:uppercase; }
  .doc-title .badge{ display:inline-block; margin-top:4px; font-size:7pt; font-weight:700; color:#fff; background:var(--blue); border-radius:20px; padding:2px 9px; letter-spacing:.06em; text-transform:uppercase; }
  .rule{ height:4px; background:linear-gradient(90deg,var(--navy),var(--blue),#1E90FF); border-radius:3px; margin:6px 0 14px; }
  .info-row{ display:flex; justify-content:space-between; gap:24px; margin-bottom:12px; }
  .lbl{ font-size:7pt; font-weight:800; color:#8794a5; text-transform:uppercase; letter-spacing:.12em; margin-bottom:5px; }
  .bill .name{ font-size:12.5pt; font-weight:800; margin-bottom:3px; }
  .bill .det{ font-size:8.5pt; color:#3f4a58; line-height:1.65; }
  .meta{ flex:0 0 230px; }
  .meta table{ width:100%; border-collapse:collapse; }
  .meta td{ padding:3px 0; font-size:8.5pt; }
  .meta td.k{ color:#8794a5; font-weight:700; text-transform:uppercase; font-size:7pt; letter-spacing:.06em; padding-right:12px; }
  .meta td.v{ text-align:right; font-weight:700; }
  .meta .balance{ margin-top:8px; background:var(--soft); border:1px solid var(--line); border-radius:6px; padding:8px 12px; text-align:right; }
  .meta .balance .a{ font-size:14pt; font-weight:900; color:var(--navy); }
  table.items{ width:100%; border-collapse:collapse; }
  table.items th{ background:var(--navy); color:#fff; font-size:7.5pt; font-weight:700; text-transform:uppercase; letter-spacing:.08em; padding:9px 12px; text-align:right; }
  table.items th:first-child{ text-align:left; border-radius:5px 0 0 0; }
  table.items th:last-child{ border-radius:0 5px 0 0; }
  table.items td{ padding:9px 12px; font-size:9pt; border-bottom:1px solid var(--line); text-align:right; vertical-align:top; }
  table.items td:first-child{ text-align:left; }
  table.items tr.on td{ background:#f1fbf5; font-weight:800; }
  .it-name{ font-weight:800; font-size:10pt; margin-bottom:3px; }
  .it-spec{ font-size:7.8pt; color:#5b6b7f; line-height:1.6; }
  .veh{ display:flex; gap:14px; align-items:center; }
  .veh img{ width:150px; height:100px; object-fit:cover; border-radius:6px; background:var(--soft); }
  .totals{ display:flex; justify-content:flex-end; margin-top:10px; }
  .totals table{ width:280px; border-collapse:collapse; }
  .totals td{ padding:4px 0; font-size:9pt; }
  .totals td.k{ color:#5b6b7f; }
  .totals td.v{ text-align:right; font-weight:700; }
  .totals tr.grand td{ border-top:2px solid var(--ink); padding-top:7px; }
  .totals tr.grand td.k{ font-weight:800; text-transform:uppercase; font-size:8.5pt; color:var(--ink); }
  .totals tr.grand td.v{ font-size:12pt; font-weight:900; color:var(--navy); }
  h3{ font-size:8.5pt; font-weight:800; color:var(--navy); text-transform:uppercase; letter-spacing:.08em; border-top:1px solid var(--line); padding-top:8px; margin:14px 0 6px; }
  .pay{ display:flex; gap:18px; margin-top:12px; }
  .bank{ flex:1; background:var(--soft); border:1px solid var(--line); border-radius:8px; padding:12px 14px; }
  .bank-grid{ display:grid; grid-template-columns:auto 1fr; gap:3px 14px; font-size:8.5pt; }
  .bank-grid .bk{ color:#5b6b7f; font-weight:700; }
  .bank-grid .bv{ font-weight:800; }
  .note{ flex:0 0 42%; font-size:7.8pt; color:#5b6b7f; line-height:1.6; }
  .note strong{ color:var(--ink); }
  p.body{ font-size:9.5pt; margin:0 0 8px; color:#2b3644; }
  ul.small, ol.small{ font-size:8.5pt; color:#3f4a58; margin:0 0 8px 18px; line-height:1.75; }
  .code{ margin:10px 0; background:#f4f8ff; border:2px dashed #c5d8f0; border-radius:10px; padding:12px; text-align:center; }
  .code small{ display:block; font-size:7pt; font-weight:800; color:#667; letter-spacing:.16em; text-transform:uppercase; }
  .code b{ font-size:22pt; font-weight:900; color:#002a5c; letter-spacing:.06em; }
  .box{ background:var(--soft); border-left:4px solid var(--blue); padding:9px 12px; font-size:8.5pt; color:#3f4a58; margin:8px 0; border-radius:0 6px 6px 0; }
  .sign{ display:flex; justify-content:space-between; align-items:flex-end; gap:24px; margin-top:26px; }
  .sign .line{ border-top:1.4px solid var(--ink); width:180px; margin-bottom:3px; }
  .sign .sl{ font-size:7pt; font-weight:700; color:#5b6b7f; text-transform:uppercase; letter-spacing:.06em; }
  .sign .thanks{ text-align:right; font-size:8pt; color:var(--muted); }
  .sign .thanks b{ color:var(--navy); }
  @media(max-width:700px){ .sheet{ padding:16px; min-height:0; margin:0; } .head,.info-row,.pay{ flex-direction:column; } .doc-title{ text-align:left; } .meta,.note{ flex:none; width:100%; } .veh{ flex-direction:column; align-items:flex-start; } }
  @media print{ body{ background:#fff; } .toolbar{ display:none; } .sheet{ box-shadow:none; margin:0; width:auto; min-height:0; padding:0; } @page{ size:A4; margin:12mm; } }
</style>
</head>
<body>
<div class="toolbar">
  <div><h2><?= e($tag) ?> · <?= e($num) ?></h2><span><?= e($app['name']) ?> — <?= e($app['vehicle']) ?></span></div>
  <div style="display:flex;gap:.5rem"><a class="tbtn tbtn-ghost" href="<?= e(finLink($app)) ?>">← My Application</a><button class="tbtn tbtn-primary" onclick="window.print()">Print / Save PDF</button></div>
</div>
<div class="sheet">
  <div class="head">
    <div class="brand">
      <img src="<?= APP_URL ?>/logo-round.png" alt="<?= e(COMPANY_NAME) ?>">
      <div><div class="co-name"><?= e(COMPANY_NAME) ?></div>
        <div class="co-reg"><?= e(COMPANY_REGISTERED_NAME) ?> · Reg <?= e(COMPANY_REG_NO) ?></div>
        <div class="co-meta"><?= e(COMPANY_ADDRESS) ?><br><?= e(COMPANY_PHONE) ?> · <?= e(COMPANY_EMAIL) ?></div></div>
    </div>
    <div class="doc-title"><div class="tag"><?= e($tag) ?></div><span class="badge">In-House Finance</span></div>
  </div>
  <div class="rule"></div>

  <div class="info-row">
    <div class="bill"><div class="lbl"><?= $doc === 'invoice' ? 'Bill To' : 'Prepared For' ?></div>
      <div class="name"><?= e($app['name']) ?></div>
      <div class="det"><?= e($app['email']) ?><br><?= e($app['phone']) ?></div></div>
    <div class="meta"><table>
      <tr><td class="k">No.</td><td class="v"><?= e($num) ?></td></tr>
      <tr><td class="k">Date</td><td class="v"><?= safeDate('d M Y', $date) ?></td></tr>
      <tr><td class="k">Reference</td><td class="v"><?= e(finRef($app)) ?></td></tr>
      <?php if ($doc === 'quote'): ?><tr><td class="k">Valid until</td><td class="v"><?= safeDate('d M Y', $q['valid']) ?></td></tr><?php endif; ?>
      <?php if ($doc === 'invoice'): ?><tr><td class="k">Due</td><td class="v"><?= safeDate('d M Y', $d['invoice']['due']) ?></td></tr><?php endif; ?>
    </table>
    <?php if ($doc === 'invoice'): ?><div class="balance"><div class="lbl" style="margin:0">Amount Due</div><div class="a"><?= $R($d['invoice']['amount']) ?></div></div><?php endif; ?>
    </div>
  </div>

  <table class="items"><thead><tr><th>Vehicle</th><th>Price</th></tr></thead><tbody><tr>
    <td><div class="veh"><?php if (!empty($v['image'])): ?><img src="<?= e(cldImg($v['image'], CLD_CARD)) ?>" alt=""><?php endif; ?>
      <div><div class="it-name"><?= e($app['vehicle']) ?></div>
      <div class="it-spec"><?= e(implode(' · ', array_filter([!empty($v['mileage']) ? number_format((int)$v['mileage']) . ' km' : '', $v['transmission'] ?? '', $v['fuel'] ?? '', $v['colour'] ?? '', !empty($v['grade']) ? 'DEKRA ' . $v['grade'] : '']))) ?></div>
      <?php if ($doc === 'approval'): ?><div class="it-spec"><strong>VIN:</strong> <?= $vin !== '' ? e($vin) : 'shown on site, on the vehicle' ?></div><?php endif; ?></div></div></td>
    <td class="money"><?= $R($app['price']) ?></td></tr></tbody></table>

<?php if ($doc === 'quote'): ?>
  <div class="totals"><table>
    <tr><td class="k">Vehicle price</td><td class="v"><?= $R($q['price']) ?></td></tr>
    <tr><td class="k">Less: deposit</td><td class="v">− <?= $R($q['deposit']) ?></td></tr>
    <tr class="grand"><td class="k">Amount financed</td><td class="v"><?= $R($q['financed']) ?></td></tr>
  </table></div>
  <h3>Your Repayment Options</h3>
  <table class="items"><thead><tr><th>Term</th><th>Rate (p/m)</th><th>Monthly</th><th>Total repayable</th></tr></thead><tbody>
    <?php foreach ($q['plans'] as $p): $on = $plan && (int)$plan['months'] === (int)$p['months']; ?>
      <tr class="<?= $on ? 'on' : '' ?>"><td><?= (int)$p['months'] ?> months<?= $on ? ' ✓' : '' ?></td><td><?= rtrim(rtrim(number_format($p['rate'], 2), '0'), '.') ?>%</td><td class="money"><?= $R($p['monthly']) ?></td><td class="money"><?= $R($p['total']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <h3>Documents Required</h3>
  <ul class="small"><?php foreach (['id', 'address', 'bank', 'income', 'licence'] as $k): ?><li><?= e(FIN_DOCS[$k][0]) ?></li><?php endforeach; ?></ul>
  <div class="box"><strong>No credit checks · No ITC · No bureau inquiries.</strong> This quote is indicative and becomes binding only when your finance agreement is signed. The deposit is deducted from the price — it is not an extra cost.</div>

<?php elseif ($doc === 'invoice'): ?>
  <div class="totals"><table>
    <tr><td class="k">Vehicle price</td><td class="v"><?= $R($app['price']) ?></td></tr>
    <?php if ($plan): ?><tr><td class="k">Term</td><td class="v"><?= (int)$plan['months'] ?> × <?= $R($plan['monthly']) ?></td></tr><?php endif; ?>
    <tr class="grand"><td class="k">Deposit due</td><td class="v"><?= $R($d['invoice']['amount']) ?></td></tr>
  </table></div>
  <div class="pay">
    <div class="bank"><div class="lbl">Banking Details</div><div class="bank-grid">
      <span class="bk">Bank</span><span class="bv"><?= e($bank['bank_name']) ?></span>
      <span class="bk">Account name</span><span class="bv"><?= e($bank['bank_account_name']) ?></span>
      <span class="bk">Account no.</span><span class="bv"><?= e($bank['bank_account_no']) ?></span>
      <span class="bk">Branch code</span><span class="bv"><?= e($bank['bank_branch_code']) ?></span>
      <span class="bk">Account type</span><span class="bv"><?= e($bank['bank_account_type']) ?></span>
      <span class="bk">Reference</span><span class="bv"><?= e(finPayRef($app)) ?></span></div></div>
    <div class="note"><strong>How to pay:</strong> EFT into the account shown, using the reference above, then upload your proof of payment and documents on your application page. Your deposit secures the vehicle and activates your finance agreement — it is deducted from the price.<br><br><strong>Only pay into this account.</strong> Auction Hub Group never changes banking details by email or WhatsApp.</div>
  </div>

<?php elseif ($doc === 'approval'): ?>
  <p class="body" style="margin-top:12px">Dear <?= e($app['name']) ?>,</p>
  <p class="body">We are pleased to confirm that your <strong>In-House Vehicle Finance is approved</strong> and your deposit has been received. The vehicle above is reserved in your name.</p>
  <div class="totals"><table>
    <tr><td class="k">Vehicle price</td><td class="v"><?= $R($app['price']) ?></td></tr>
    <tr><td class="k">Deposit received</td><td class="v"><?= $R($app['amount']) ?></td></tr>
    <tr><td class="k">Amount financed</td><td class="v"><?= $R($q['financed']) ?></td></tr>
    <?php if ($plan): ?><tr class="grand"><td class="k"><?= (int)$plan['months'] ?> monthly instalments</td><td class="v"><?= $R($plan['monthly']) ?></td></tr><?php endif; ?>
  </table></div>
  <div class="code"><small>Your Buyer Code</small><b><?= e((string)$app['buyer_code']) ?></b></div>
  <ol class="small"><li>Present your Buyer Code at <?= e(COMPANY_ADDRESS) ?><?= ($d['viewing']['status'] ?? '') === 'confirmed' ? ' on <strong>' . e(finViewingLabel($d['viewing'])) . '</strong>' : '' ?>.</li>
    <li>Bring your ID and driver's licence. You have 1 hour 30 minutes to test drive and inspect.</li><li>Sign your finance agreement and drive away.</li></ol>
  <div class="sign"><div><div class="line"></div><div class="sl">For <?= e(COMPANY_NAME) ?></div></div><div><div class="line"></div><div class="sl">Client: <?= e($app['name']) ?></div></div></div>

<?php else: ?>
  <p class="body" style="margin-top:12px">Dear <?= e($app['name']) ?>,</p>
  <p class="body">Thank you for your trust in Auction Hub Group. We regret that we are <strong>not able to proceed</strong> with your in-house finance application on the vehicle above.</p>
  <?php if (!empty($d['declined']['reason'])): ?><div class="box"><?= nl2br(e($d['declined']['reason'])) ?></div><?php endif; ?>
  <p class="body">This relates only to this application and vehicle — it is not a reflection of your creditworthiness. New bank-stock vehicles become available every week; our consultants will gladly help you find another option.</p>
<?php endif; ?>

  <div class="sign"><div class="sl">Questions? <?= e(COMPANY_PHONE) ?> · WhatsApp <?= e(COMPANY_WHATSAPP) ?></div><div class="thanks">Thank you for choosing <b><?= e(COMPANY_NAME) ?></b></div></div>
</div>
</body>
</html>
