<?php
// ============================================================
// AUCTION HUB GROUP — application_doc.php?t=TOKEN&doc=…
// Printable letters for an application (print or "Save as PDF"):
//   quote     finance quotation (every term)
//   counter   deposit letter (we can approve a higher deposit)
//   invoice   deposit invoice (finance) / commitment invoice (pre-auction)
//   approval  finance approval letter + Buyer's Code + VIN
//   letter    pre-auction commitment letter
//   code      pre-auction Buyer's Code letter + VIN
//   decline   letter of decline
// Admins can open any application by id: ?id=…&doc=… (with their login).
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';

$app = finFind((string)($_GET['t'] ?? ''));
if (!$app && !empty($_GET['id'])) {
    $u = getPageUser();
    if (($u['role'] ?? '') === 'admin') $app = finGet((int)$_GET['id']);
}
if (!$app) { http_response_code(404); exit('Document not found.'); }

$doc   = (string)($_GET['doc'] ?? '');
$isFin = $app['kind'] === 'finance';
$d     = $app['details'];
$s     = $app['status'];
$allowed = $isFin
    ? ['quote' => !empty($d['quote']), 'counter' => !empty($d['quote']['counter']), 'invoice' => !empty($d['invoice']),
       'approval' => in_array($s, ['approved', 'completed'], true), 'decline' => $s === 'declined']
    : ['letter' => !empty($d['approved_at']) || in_array($s, ['approved', 'confirmed', 'paid', 'completed', 'refund'], true),
       'invoice' => !empty($d['invoice']), 'code' => in_array($s, ['paid', 'completed'], true), 'decline' => $s === 'declined'];
if (empty($allowed[$doc])) { http_response_code(404); exit('This document is not available for this application yet.'); }

$bank  = getBankSettings();
$first = explode(' ', trim($app['name']))[0];
$v     = $d['vehicle'] ?? [];
$vin   = in_array($doc, ['approval', 'code'], true) ? vinForAuction((int)$app['auction_id']) : '';
$titles = ['quote' => 'Finance Quotation', 'counter' => 'Deposit Letter', 'invoice' => $isFin ? 'Deposit Invoice' : 'Commitment Invoice',
           'approval' => 'Letter of Approval', 'letter' => 'Pre-Auction Commitment Letter', 'code' => 'Buyer\'s Code Letter', 'decline' => 'Letter of Decline'];
$title = $titles[$doc];
$docNo = match ($doc) {
    'quote', 'counter' => $d['quote']['number'] ?? finRef($app),
    'invoice' => $d['invoice']['number'],
    default => finRef($app),
};
$date = match ($doc) {
    'quote', 'counter' => $d['quote']['issued'],
    'invoice' => $d['invoice']['issued'],
    'approval' => $d['approved_at'] ?? $app['updated_at'],
    'letter' => $d['approved_at'] ?? $app['updated_at'],
    'code' => $d['paid']['at'] ?? $app['updated_at'],
    'decline' => $d['declined']['at'] ?? $app['updated_at'],
};
$R = fn($n) => formatRand((float)$n);
header('X-Robots-Tag: noindex, nofollow');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e(COMPANY_NAME . ' ' . $title . ' - ' . $app['vehicle']) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#e9eef3;font-family:Arial,Helvetica,sans-serif;color:#1d2a36;font-size:14px;line-height:1.55}
.page{max-width:820px;margin:24px auto;background:#fff;padding:40px 46px;box-shadow:0 4px 24px rgba(0,0,0,.08)}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;border-bottom:3px solid #005BA1;padding-bottom:16px;margin-bottom:22px}
.head img{height:58px}
.head .co{text-align:right;font-size:12px;color:#556}
.head .co strong{color:#003f73;font-size:14px}
h1{color:#003f73;font-size:22px;margin:0 0 4px}
.meta{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;font-size:12.5px;color:#556;margin-bottom:18px}
.meta b{color:#1d2a36}
h2{color:#005BA1;font-size:15px;margin:22px 0 8px;text-transform:uppercase;letter-spacing:.04em}
table{width:100%;border-collapse:collapse;margin:6px 0 10px}
th{background:#f0f6fc;color:#003f73;text-align:left;padding:8px 10px;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
td{padding:8px 10px;border-bottom:1px solid #e6ebf0;vertical-align:top}
td.r,th.r{text-align:right}
.tot td{font-weight:700;border-top:2px solid #003f73;font-size:15px}
.box{background:#f0f6fc;border-left:4px solid #005BA1;padding:12px 16px;margin:12px 0;border-radius:0 6px 6px 0}
.warn{background:#fff8e6;border-left-color:#f5a623}
.code{font-size:34px;font-weight:900;letter-spacing:.3em;color:#005BA1;text-align:center;border:2px dashed #9cc2e5;border-radius:10px;padding:12px;margin:10px 0}
.veh{display:flex;gap:16px;align-items:center}
.veh img{width:170px;height:113px;object-fit:cover;border-radius:6px;background:#eef2f6}
.small{font-size:11.5px;color:#667}
.sign{display:flex;gap:40px;margin-top:34px}
.sign div{flex:1;border-top:1px solid #333;padding-top:6px;font-size:12px;color:#556}
.bar{max-width:820px;margin:16px auto 0;display:flex;gap:10px;justify-content:flex-end;padding:0 10px}
.bar a,.bar button{background:#005BA1;color:#fff;border:0;border-radius:6px;padding:9px 16px;font-weight:700;cursor:pointer;text-decoration:none;font-size:14px}
.bar a{background:#fff;color:#005BA1;border:1.5px solid #005BA1}
@media(max-width:640px){.page{padding:24px 18px;margin:0}.head{flex-direction:column}.head .co{text-align:left}.veh{flex-direction:column;align-items:flex-start}}
@media print{body{background:#fff}.page{box-shadow:none;margin:0;max-width:none;padding:0}.bar{display:none}@page{margin:16mm}}
</style>
</head>
<body>
<div class="bar"><a href="<?= e(finLink($app)) ?>">← My application</a><button onclick="window.print()">Print / Save as PDF</button></div>
<div class="page">
  <div class="head">
    <img src="<?= APP_URL ?>/logo.png" alt="<?= e(COMPANY_NAME) ?>">
    <div class="co"><strong><?= e(COMPANY_REGISTERED_NAME) ?> t/a <?= e(COMPANY_TRADING_AS) ?></strong><br>
      Reg. <?= e(COMPANY_REG_NO) ?> · Tax <?= e(COMPANY_SARS_NO) ?><br><?= e(COMPANY_ADDRESS) ?><br><?= e(COMPANY_PHONE) ?> · <?= e(COMPANY_EMAIL) ?></div>
  </div>
  <h1><?= e($title) ?></h1>
  <div class="meta">
    <div>To: <b><?= e($app['name']) ?></b><br><?= e($app['email']) ?> · <?= e($app['phone']) ?></div>
    <div style="text-align:right">No. <b><?= e($docNo) ?></b><br>Date: <b><?= safeDate('j F Y', $date) ?></b><br>Ref: <b><?= e(finRef($app)) ?></b></div>
  </div>

  <div class="veh">
    <?php if (!empty($v['image'])): ?><img src="<?= e(cldImg($v['image'], CLD_CARD)) ?>" alt=""><?php endif; ?>
    <table style="flex:1">
      <tr><td style="width:38%;color:#667">Vehicle</td><td><b><?= e($app['vehicle']) ?></b></td></tr>
      <?php foreach (['Year' => $v['year'] ?? '', 'Mileage' => !empty($v['mileage']) ? number_format((int)$v['mileage']) . ' km' : '', 'Transmission' => $v['transmission'] ?? '', 'Fuel' => $v['fuel'] ?? '', 'Colour' => $v['colour'] ?? ''] as $k => $val): if ((string)$val === '') continue; ?>
        <tr><td style="color:#667"><?= $k ?></td><td><?= e((string)$val) ?></td></tr>
      <?php endforeach; ?>
      <?php if (in_array($doc, ['approval', 'code'], true)): ?><tr><td style="color:#667">VIN</td><td><b><?= $vin !== '' ? e($vin) : 'Shown on site, on the vehicle' ?></b></td></tr><?php endif; ?>
    </table>
  </div>

<?php if ($doc === 'quote' || $doc === 'counter'): $q = $d['quote']; ?>
  <?php if ($doc === 'counter'): $prev = end($d['quotes_old']); ?>
    <p>Dear <?= e($first) ?>,</p>
    <p>Thank you for your in-house finance application. After reviewing it, we regret that we are <strong>not able to approve the start-up deposit of <?= $R($prev['deposit'] ?? 0) ?></strong> on this vehicle. We are, however, pleased to let you know that we <strong>can approve a start-up deposit of <?= $R($q['deposit']) ?></strong>.</p>
    <?php if ($q['note'] !== ''): ?><div class="box"><?= nl2br(e($q['note'])) ?></div><?php endif; ?>
    <p>Your monthly options at this deposit are below. To go ahead, choose your plan on your application page; we then send your deposit invoice.</p>
  <?php else: ?>
    <p>Dear <?= e($first) ?>, thank you for applying for Auction Hub Group in-house finance — <strong>no credit checks, no ITC, no bureau inquiries</strong>. This is your personalised quotation.</p>
  <?php endif; ?>
  <h2>Finance summary</h2>
  <table>
    <tr><td>Purchase price</td><td class="r"><?= $R($q['price']) ?></td></tr>
    <tr><td>Less: start-up deposit</td><td class="r">− <?= $R($q['deposit']) ?></td></tr>
    <tr class="tot"><td>Amount financed</td><td class="r"><?= $R($q['financed']) ?></td></tr>
  </table>
  <h2>Your monthly options</h2>
  <table>
    <tr><th>Term</th><th class="r">Rate (per month)</th><th class="r">Monthly instalment</th><th class="r">Total repayable</th></tr>
    <?php foreach ($q['plans'] as $p): $on = (int)($d['choice']['months'] ?? 0) === (int)$p['months']; ?>
      <tr<?= $on ? ' style="background:#f1fbf5;font-weight:700"' : '' ?>><td><?= (int)$p['months'] ?> months<?= $on ? ' ✓ chosen' : '' ?></td><td class="r"><?= rtrim(rtrim(number_format($p['rate'], 2), '0'), '.') ?>%</td><td class="r"><?= $R($p['monthly']) ?></td><td class="r"><?= $R($p['total']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <div class="box"><strong>Valid until <?= date('j F Y', strtotime($q['valid'])) ?>.</strong> This quotation is an estimate and becomes binding only once your finance agreement is signed. The deposit is deducted from the price — it is not an extra cost.</div>
  <h2>What you need to send us</h2>
  <ul><?php foreach (['id', 'address', 'bank', 'income', 'licence'] as $k): ?><li><?= e(FIN_DOCS[$k][0]) ?> — <span class="small"><?= e(FIN_DOCS[$k][1]) ?></span></li><?php endforeach; ?></ul>
  <p class="small">Upload these on your private application page once your deposit is approved: <?= e(finLink($app)) ?></p>

<?php elseif ($doc === 'invoice'): $inv = $d['invoice']; $plan = finChosenPlan($app); ?>
  <h2><?= $isFin ? 'Start-up deposit' : 'Pre-auction commitment' ?></h2>
  <table>
    <tr><th>Description</th><th class="r">Amount</th></tr>
    <tr><td>Purchase price — <?= e($app['vehicle']) ?></td><td class="r"><?= $R($app['price']) ?></td></tr>
    <?php if ($isFin): ?>
      <tr><td>Start-up deposit (deducted from the price)<?= $plan ? '<br><span class="small">Plan: ' . (int)$plan['months'] . ' months × ' . $R($plan['monthly']) . '</span>' : '' ?></td><td class="r"><?= $R($inv['amount']) ?></td></tr>
    <?php else: ?>
      <tr><td>Commitment: <?= (int)$app['percent'] ?>% of the price (deducted from the price)</td><td class="r"><?= $R($inv['amount']) ?></td></tr>
      <tr><td>Balance, payable after your test drive</td><td class="r"><?= $R((float)$app['price'] - (float)$inv['amount']) ?></td></tr>
    <?php endif; ?>
    <tr class="tot"><td>Amount payable now</td><td class="r"><?= $R($inv['amount']) ?></td></tr>
  </table>
  <h2>Pay into this account only</h2>
  <table>
    <tr><td style="width:40%">Bank</td><td><b><?= e($bank['bank_name']) ?></b></td></tr>
    <tr><td>Account name</td><td><b><?= e($bank['bank_account_name']) ?></b></td></tr>
    <tr><td>Account number</td><td><b><?= e($bank['bank_account_no']) ?></b></td></tr>
    <tr><td>Branch code</td><td><b><?= e($bank['bank_branch_code']) ?></b></td></tr>
    <tr><td>Account type</td><td><?= e($bank['bank_account_type'] ?? BANK_ACCOUNT_TYPE) ?></td></tr>
    <tr><td>Reference</td><td><b><?= e(finPayRef($app)) ?></b></td></tr>
    <tr><td>Please pay by</td><td><b><?= date('j F Y', strtotime($inv['due'])) ?></b></td></tr>
  </table>
  <div class="box warn"><strong>Fraud warning:</strong> only ever pay into the account on this invoice. Auction Hub Group never changes banking details by email, SMS or WhatsApp — if anyone asks you to pay elsewhere, call us on <?= e(COMPANY_PHONE) ?> first.</div>
  <p>After paying, upload your proof of payment on your application page: <?= e(finLink($app)) ?>. Once it reflects you receive your <strong>Buyer's Code</strong> for the test drive.</p>
  <?php if (!$isFin): ?><p class="small">An unpaid commitment invoice lapses after <?= FIN_DECISION_DAYS ?> days and the vehicle returns to auction.</p><?php endif; ?>

<?php elseif ($doc === 'approval'): $plan = finChosenPlan($app); ?>
  <p>Dear <?= e($first) ?>,</p>
  <p>We are pleased to confirm that your <strong>in-house finance application is approved</strong> and your start-up deposit of <?= $R($app['amount']) ?> has been received. The vehicle above is now reserved in your name.</p>
  <table>
    <tr><td>Purchase price</td><td class="r"><?= $R($app['price']) ?></td></tr>
    <tr><td>Deposit received</td><td class="r"><?= $R($app['amount']) ?></td></tr>
    <tr><td>Amount financed</td><td class="r"><?= $R($d['quote']['financed']) ?></td></tr>
    <?php if ($plan): ?><tr class="tot"><td><?= (int)$plan['months'] ?> monthly instalments of</td><td class="r"><?= $R($plan['monthly']) ?></td></tr><?php endif; ?>
  </table>
  <h2>Your Buyer's Code</h2>
  <div class="code"><?= e((string)$app['buyer_code']) ?></div>
  <p>Present this code at the gate of our head office, <?= e(COMPANY_ADDRESS) ?>, for your test drive<?= !empty($d['viewing']['status']) && $d['viewing']['status'] === 'confirmed' ? ' on <strong>' . e(finViewingLabel($d['viewing'])) . '</strong>' : ' (book it on your application page)' ?>. Bring your ID and driver's licence. After the test drive you sign your finance agreement and drive away.</p>
  <p class="small">The code is unique to you and valid for this vehicle only. The VIN above is shared with you as an approved finance client under our VIN policy — check it against the plate on the vehicle.</p>
  <div class="sign"><div>For <?= e(COMPANY_NAME) ?></div><div>Client: <?= e($app['name']) ?></div></div>

<?php elseif ($doc === 'letter'): $pc = paPercent($app); $commit = paCommitment($app); ?>
  <p>Dear <?= e($first) ?>,</p>
  <p>Thank you for your pre-auction request. We are pleased to confirm it is <strong>approved at <?= $pc ?>%</strong>. Before we issue any invoice, please read this letter and confirm on your application page that you understand and are going ahead.</p>
  <table>
    <tr><td>Purchase price</td><td class="r"><?= $R($app['price']) ?></td></tr>
    <tr><td>Commitment (<?= $pc ?>%)</td><td class="r"><?= $R($commit) ?></td></tr>
    <tr class="tot"><td>Balance after your test drive</td><td class="r"><?= $R((float)$app['price'] - $commit) ?></td></tr>
  </table>
  <h2>How it works</h2>
  <ol>
    <li><strong>The commitment is mandatory</strong> to take the vehicle out of the auction. It is not an extra cost — it is deducted from the price.</li>
    <li>Once you confirm, your commitment invoice is issued. Pay <strong>only into the account on the invoice</strong> within <?= FIN_DECISION_DAYS ?> days and upload proof of payment.</li>
    <li>When it reflects, the vehicle is <strong>reserved under your name</strong> and you receive your six-digit <strong>Buyer's Code</strong> with your appointment at our head office.</li>
    <li>You have <strong>1 hour 30 minutes</strong> to inspect and test-drive the vehicle. A trusted mechanic is welcome. <strong>No Buyer's Code, no entry — no walk-ins.</strong></li>
    <li>Then you decide: pay the balance and take the vehicle<?= !empty($d['alt']) ? ', switch to your alternative (' . e($d['alt']['title']) . ')' : '' ?>, or ask for your commitment back — refunded within 3–5 business days, less <?= $R(PA_REFUND_FEE) ?> for bank and administration charges.</li>
  </ol>
  <?php if (!empty($d['office_note'])): ?><div class="box"><?= nl2br(e($d['office_note'])) ?></div><?php endif; ?>
  <div class="box warn">Not ready to commit? Tell us on your application page — you are always welcome to register and bid on the vehicle at auction instead.</div>
  <?php if (!empty($d['confirmed_at'])): ?><p><strong>Confirmed by <?= e($app['name']) ?> on <?= safeDate('j F Y \a\t H:i', $d['confirmed_at']) ?>.</strong></p><?php endif; ?>
  <div class="sign"><div>For <?= e(COMPANY_NAME) ?></div><div>Buyer: <?= e($app['name']) ?></div></div>

<?php elseif ($doc === 'code'): $vw = $d['viewing'] ?? []; ?>
  <p>Dear <?= e($first) ?>,</p>
  <p>Your commitment of <strong><?= $R($app['amount']) ?></strong> has been received and the vehicle is now <strong>reserved under your name</strong>. No other buyer can bid on it.</p>
  <h2>Your Buyer's Code</h2>
  <div class="code"><?= e((string)$app['buyer_code']) ?></div>
  <table>
    <tr><td style="width:40%">Where</td><td><?= e(COMPANY_ADDRESS) ?> (head office)</td></tr>
    <tr><td>When</td><td><b><?= ($vw['status'] ?? '') === 'confirmed' ? e(finViewingLabel($vw)) : (!empty($vw['date']) ? e(finViewingLabel($vw)) . ' (requested — we confirm it)' : 'We confirm the time with you') ?></b></td></tr>
    <tr><td>Time allowed</td><td>1 hour 30 minutes — inspection and test drive</td></tr>
    <tr><td>Bring</td><td>This code, your ID<?= !empty($d['mechanic']) ? ', and your mechanic' : '' ?></td></tr>
    <?php if (!empty($d['gate'])): ?><tr><td>Gate pass</td><td><?= (int)$d['gate']['people'] ?> person(s)<?= $d['gate']['car_reg'] !== '' ? ', arriving in ' . e($d['gate']['car_reg']) : '' ?></td></tr><?php endif; ?>
  </table>
  <div class="box warn"><strong>No Buyer's Code, no entry. No walk-ins.</strong> The code is unique to you and valid for this vehicle only — do not share it.</div>
  <p>After your test drive: pay the balance of <strong><?= $R((float)$app['price'] - (float)$app['amount']) ?></strong>, or ask for your commitment back (refunded less <?= $R(PA_REFUND_FEE) ?>).</p>
  <p class="small">The VIN above is shared with you as a buyer with a paid reservation, under our VIN policy — check it against the plate on the vehicle.</p>

<?php elseif ($doc === 'decline'): ?>
  <p>Dear <?= e($first) ?>,</p>
  <p>Thank you for choosing Auction Hub Group, and for the time and trust you placed in us with your <?= $isFin ? 'in-house finance application' : 'pre-auction request' ?> for the vehicle above.</p>
  <p>We are sincerely sorry to let you know that, after careful consideration, we are <strong>not able to approve it</strong> at this time.</p>
  <?php if (!empty($d['declined']['reason'])): ?><div class="box"><?= nl2br(e($d['declined']['reason'])) ?></div><?php endif; ?>
  <p>This decision relates only to this vehicle and application — it is not a reflection on you. New stock arrives every week, and you are always welcome to apply on another vehicle, or to register and bid at auction (the registration deposit is refundable).</p>
  <?php if (!$isFin && !empty($d['paid'])): ?><p>Your commitment of <?= $R($app['amount']) ?> is refunded in full within 3–5 business days to the account it came from, less <?= $R(PA_REFUND_FEE) ?> for bank and administration charges.</p><?php endif; ?>
  <p>We apologise for the disappointment, and we would be glad to help you find the right vehicle. Call us on <?= e(COMPANY_PHONE) ?> or WhatsApp <?= e(COMPANY_WHATSAPP) ?>.</p>
  <p>Kind regards,<br><strong>The Auction Hub Group team</strong></p>
<?php endif; ?>

  <p class="small" style="margin-top:28px;border-top:1px solid #e6ebf0;padding-top:10px"><?= e(COMPANY_REGISTERED_NAME) ?> t/a <?= e(COMPANY_TRADING_AS) ?> · Reg. <?= e(COMPANY_REG_NO) ?> · <?= e(COMPANY_ADDRESS) ?> · auctionhubgroup.co.za</p>
</div>
</body>
</html>
