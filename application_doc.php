<?php
// ============================================================
// AUCTION HUB GROUP — application_doc.php?t=TOKEN&doc=…
// Finance documents, on the same A4 layout as invoice_print.php
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
// The deposit invoice is the invoice_view.php layout (invoice_helper.php) when the office sent one.
if ($doc === 'invoice' && ($row = invForApp($app)) && $row['status'] !== 'draft') {
    header('Location: ' . (!empty($_GET['id']) ? APP_URL . '/invoice_view.php?id=' . (int)$row['id'] : invClientLink($row)));
    exit;
}

$bank  = getBankSettings();
$v     = $d['vehicle'] ?? [];
$q     = $d['quote'] ?? [];
$plan  = finChosenPlan($app);
$vin   = $doc === 'approval' ? vinForAuction((int)$app['auction_id']) : '';
$R     = fn($n) => 'R' . number_format((float)$n, 2, '.', ',');
$tag   = ['quote' => 'Finance Quote', 'invoice' => 'Deposit Invoice', 'approval' => 'Finance Approval', 'decline' => 'Decline Letter'][$doc];
// The decline letter written by the office; clients only see it once an admin has sent it.
$letter = $doc === 'decline' ? ($d['declined']['letter'] ?? []) : [];
$showLetter = !empty($letter['text']) && (!empty($_GET['id']) || !empty($letter['sent_at']));
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
  .sign{ display:flex; justify-content:space-between; align-items:flex-end; gap:16px 24px; margin-top:26px; flex-wrap:wrap; }
  .sign .line{ border-top:1.4px solid var(--ink); width:180px; max-width:100%; margin-bottom:3px; }
  .sign .sl{ font-size:7pt; font-weight:700; color:#5b6b7f; text-transform:uppercase; letter-spacing:.06em; }
  .sign .thanks{ text-align:right; font-size:8pt; color:var(--muted); }
  .sign .thanks b{ color:var(--navy); }
  @media(max-width:700px){ table.items{ display:block; overflow-x:auto; white-space:nowrap; } }
  @media(max-width:700px){ .sheet{ padding:16px; min-height:0; margin:0; } .head,.info-row,.pay{ flex-direction:column; } .doc-title{ text-align:left; } .meta,.note{ flex:none; width:100%; } .veh{ flex-direction:column; align-items:flex-start; } .veh img{ width:100%; height:auto; aspect-ratio:3/2; } .totals table{ width:100%; } table.items th, table.items td{ padding:7px 8px; } .toolbar{ position:static; } .toolbar h2{ font-size:.85rem; } .brand img{ width:52px; height:52px; } .co-name{ font-size:13pt; } .doc-title .tag{ font-size:14pt; } .bank-grid{ grid-template-columns:minmax(0,auto) minmax(0,1fr); } .bank-grid .bv{ overflow-wrap:anywhere; } }
  .sec{ margin-top:6px; }
  .pagebreak{ page-break-before:always; break-before:page; margin-top:18px; }
  .ticks{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:8px; margin:6px 0 10px; }
  .tick{ border:1px solid var(--line); border-radius:8px; padding:8px 10px; font-size:9pt; }
  .tick.on{ border-color:#1a9e4e; background:#f1fbf5; }
  .tick span{ display:block; color:var(--muted); font-size:8pt; margin-top:2px; }
  .ok{ color:#1a7a3d; font-weight:800; }
  .todo{ color:#b45309; font-weight:800; }
  table.docs td:last-child{ text-align:left; }
  table.verify td{ text-align:left; font-size:8.5pt; }
  table.verify td:first-child{ font-weight:800; color:var(--muted); width:32%; }
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
    <div class="doc-title"><div class="tag"><?= e($tag) ?></div><span class="badge">Finance</span></div>
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
      <?php if ($doc === 'quote'): echo finQuoteBodyHtml($app); ?>

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
  <p class="body">We are pleased to confirm that your <strong>Vehicle Finance is approved</strong> and your deposit has been received. The vehicle above is reserved in your name.</p>
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

<?php elseif ($showLetter): ?>
  <?php if (!empty($_GET['id']) && empty($letter['sent_at'])): ?><div class="box" style="border-color:#f5c842;background:#fffaf0">Draft — not sent to the client yet (approve it in the Outbox).</div><?php endif; ?>
  <div class="body letter" style="margin-top:12px"><?= finLetterHtml($letter['text']) ?></div>
<?php else: ?>
  <p class="body" style="margin-top:12px">Dear <?= e($app['name']) ?>,</p>
  <p class="body">Thank you for your trust in Auction Hub Group. We regret that we are <strong>not able to proceed</strong> with your finance application on the vehicle above.</p>
  <?php if (!empty($d['declined']['reason'])): ?><div class="box"><?= nl2br(e($d['declined']['reason'])) ?></div><?php endif; ?>
  <p class="body">This relates only to this application and vehicle — it is not a reflection of your creditworthiness. New bank-stock vehicles become available every week; our consultants will gladly help you find another option.</p>
<?php endif; ?>

  <div class="sign"><div class="sl">Questions? <?= e(COMPANY_PHONE) ?> · WhatsApp <?= e(COMPANY_WHATSAPP) ?></div><div class="thanks">Thank you for choosing <b><?= e(COMPANY_NAME) ?></b></div></div>
</div>
</body>
</html>
