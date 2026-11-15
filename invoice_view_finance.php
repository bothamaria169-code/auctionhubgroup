<?php
// ============================================================
// AUCTION HUB GROUP — invoice_view_finance.php
// The finance deposit invoice (layout = AHG-INV-20261028-01): one A4
// page, print / Save as PDF. Included by invoice_view.php, which has
// already checked access and set $inv, $a, $bank, $photos, $staff,
// $statusNote. The pre-auction commitment-fee invoice is a different
// design (invoice_view.php itself).
// ============================================================
if (!isset($inv, $a, $bank)) { http_response_code(404); exit; }

$ymd    = fn($d) => $d ? date('Y/m/d', strtotime((string)$d)) : '';
$issued = $inv['invoice_date'] ?: ($inv['sent_at'] ?: $inv['created_at']);
$due    = $inv['expires_at'] ?: date('Y-m-d H:i:s', strtotime($issued . ' +' . INV_PAY_HOURS . ' hours'));
$money  = fn(float $n) => 'R' . number_format($n, 2, '.', ',');
$sticker = '';
foreach (preg_split('/\r?\n|,/', (string)$inv['vehicle_features']) as $f) { if (stripos($f, 'dekra') !== false) { $sticker = trim($f); break; } }
$chips = array_values(array_filter([
    $inv['vehicle_year'], $inv['vehicle_mileage'], $inv['vehicle_transmission'], $inv['vehicle_fuel'],
    $inv['vehicle_body'], $inv['vehicle_colour'], $inv['vehicle_condition'], $sticker,
], fn($v) => trim((string)$v) !== ''));
$payRef = trim((string)$inv['buyer_name']);
$steps = [['Pay deposit', 'EFT with your name as reference'], ['Approval starts', 'Lender begins finance approval'],
          ['Test drive', 'Our team books it with you'], ['You decide', 'Proceed, or withdraw for a refund*']];
$web = preg_replace('~^https?://(www\.)?~', '', CANONICAL_URL);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($inv['invoice_number']) ?> — Finance Approval Deposit — <?= e(COMPANY_TRADING_AS) ?></title>
<style>
  :root { --navy:#0c1c45; --blue:#1d4fd8; --gold:#c9a24c; --ink:#1d2433; --muted:#6b7385; --card:#f2f4f8; }
  * { box-sizing:border-box; }
  body { margin:0; background:#e9edf3; font-family:Helvetica, Arial, sans-serif; color:var(--ink); }
  .bar { position:sticky; top:0; z-index:5; background:var(--navy); color:#fff; padding:.7rem 1rem; display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; justify-content:center; font-size:14px; }
  .bar a, .bar button { background:#fff; color:var(--navy); border:0; border-radius:6px; padding:.5rem .95rem; font-weight:700; font-size:13px; text-decoration:none; cursor:pointer; }
  .bar .flag { background:#ffd54f; color:#5c4400; padding:.35rem .7rem; border-radius:6px; font-weight:700; }
  .page { width:210mm; height:297mm; margin:14px auto; background:#fff; position:relative; overflow:hidden; box-shadow:0 2px 14px rgba(0,0,0,.12); }
  .wm { position:absolute; left:50%; top:52%; width:120mm; height:120mm; transform:translate(-50%,-50%); opacity:.05; border-radius:50%; }
  .hd { position:relative; background:var(--navy); height:46mm; color:#fff; display:flex; align-items:center; padding:0 9mm; gap:6mm; }
  .hd::after { content:''; position:absolute; left:0; right:0; bottom:-1.6mm; height:1.6mm; background:var(--gold); }
  .hd img { width:30mm; height:30mm; border-radius:50%; object-fit:cover; flex:none; position:relative; z-index:1; }
  .hd .co { position:relative; z-index:1; font-size:7.6pt; line-height:1.55; color:#d9e1f2; }
  .hd .co b { display:block; color:#fff; font-size:19pt; letter-spacing:.01em; margin-bottom:.6mm; }
  .hd .co .reg { color:var(--gold); font-weight:700; font-size:6.5pt; letter-spacing:.04em; margin-bottom:1.6mm; display:block; }
  .hd .panel { position:absolute; right:0; top:0; bottom:0; width:72mm; background:var(--blue); clip-path:polygon(18mm 0, 100% 0, 100% 100%, 0 100%); text-align:right; padding:6mm 9mm 0 0; }
  .hd .panel .big { font-size:28pt; font-weight:800; letter-spacing:.02em; color:#fff; line-height:1.1; }
  .hd .panel .sub { color:#f0d79a; font-size:7pt; letter-spacing:.06em; margin-top:2mm; text-transform:uppercase; }
  .hd .panel .no { font-weight:700; font-size:8.6pt; margin-top:4mm; color:#fff; }
  .body { padding:7mm 9mm 0; position:relative; }
  .two { display:flex; gap:4mm; }
  .card { flex:1; background:var(--card); border-radius:2.5mm; padding:3mm 4.5mm; border-left:1.2mm solid var(--gold); }
  .card.blue { border-left-color:var(--blue); }
  .lbl { font-size:6.4pt; font-weight:700; letter-spacing:.06em; color:var(--muted); text-transform:uppercase; }
  .card .name { font-size:12pt; font-weight:800; color:var(--navy); margin:1.2mm 0 .8mm; }
  .small { font-size:7.2pt; color:var(--muted); }
  .dates { display:flex; gap:10mm; }
  .dates .v { font-size:12pt; font-weight:800; color:var(--navy); margin-top:1mm; }
  .dates .v.red { color:#c0392b; }
  .veh { margin-top:4mm; background:var(--navy); color:#fff; border-radius:2.5mm; padding:3.6mm 5mm 4mm; }
  .veh .lbl { color:var(--gold); }
  .veh .t { font-size:14pt; font-weight:800; margin:1.2mm 0 2.6mm; }
  .chips { display:flex; flex-wrap:wrap; gap:1.6mm; }
  .chips span { background:#1e3270; color:#fff; border-radius:999px; padding:1.1mm 3.2mm; font-size:6.6pt; font-weight:700; }
  .photos { display:grid; grid-template-columns:repeat(4,1fr); gap:2.4mm; margin-top:3mm; }
  .photos img { width:100%; height:30mm; object-fit:cover; border-radius:1.6mm; display:block; }
  table.items { width:100%; border-collapse:collapse; margin-top:4mm; font-size:8pt; }
  table.items th { background:var(--blue); color:#fff; text-transform:uppercase; text-align:left; font-size:6.8pt; letter-spacing:.06em; padding:2.6mm 4.5mm; }
  table.items th.r, table.items td.r { text-align:right; }
  table.items th.c, table.items td.c { text-align:center; width:20mm; }
  table.items td { padding:3.6mm 4.5mm 3mm; border-bottom:1px solid #e3e7ef; vertical-align:top; }
  table.items td b { font-size:9.2pt; color:var(--navy); display:block; margin-bottom:.8mm; }
  .bal { margin:4mm 0 0 auto; width:84mm; background:var(--gold); border-radius:2.5mm; padding:3mm 5mm 3.4mm; color:var(--navy); }
  .bal .lbl { color:var(--navy); }
  .bal b { display:block; text-align:right; font-size:17pt; font-weight:800; margin-top:1mm; }
  h4 { margin:6mm 0 3mm; font-size:8.6pt; color:var(--navy); letter-spacing:.02em; display:inline-block; border-bottom:.8mm solid var(--gold); padding-bottom:.8mm; text-transform:uppercase; }
  .steps { display:grid; grid-template-columns:repeat(4,1fr); gap:2.6mm; }
  .steps div { background:var(--card); border-radius:2.2mm; padding:3mm 3.2mm; font-size:6.9pt; color:var(--muted); line-height:1.45; }
  .steps strong { display:flex; align-items:center; gap:2mm; color:var(--navy); font-size:8pt; margin-bottom:1.4mm; }
  .steps i { font-style:normal; background:var(--gold); color:var(--navy); width:5mm; height:5mm; border-radius:50%; display:inline-grid; place-items:center; font-size:6.8pt; font-weight:800; flex:none; }
  .lower { display:flex; gap:8mm; }
  .lower > div { flex:1; }
  .bank td { font-size:7.6pt; padding:.9mm 0; }
  .bank td:first-child { color:var(--muted); width:30mm; }
  .bank td + td { font-weight:700; color:var(--navy); }
  .terms ol { margin:0; padding-left:4mm; font-size:7.1pt; color:var(--muted); line-height:1.6; }
  .warn { margin-top:2.5mm; font-size:7.2pt; font-weight:700; color:#c0392b; }
  .ft { position:absolute; left:0; right:0; bottom:0; height:16mm; background:var(--navy); color:#e4e9f5; font-size:7.4pt; display:flex; align-items:center; justify-content:center; }
  .ft::before { content:''; position:absolute; left:0; right:0; top:-1.6mm; height:1.6mm; background:var(--gold); }
  .stamp { position:absolute; left:92mm; top:52mm; transform:rotate(-12deg); border:2.5px solid #c8102e; color:#c8102e; font-weight:800; padding:1.5mm 4mm; border-radius:2mm; font-size:12pt; opacity:.85; z-index:3; }
  @media print {
    @page { size:A4; margin:0; }
    body { background:#fff; }
    .bar { display:none; }
    .page { margin:0; box-shadow:none; }
    * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
</style>
</head>
<body>
<?php if (!empty($_GET['embed'])): ?>
<style>.bar { display:none; } .page { margin:8px auto; }</style>
<script>
  function fit() { var w = document.querySelector('.page').offsetWidth + 16; document.body.style.zoom = Math.min(1, window.innerWidth / w); }
  window.addEventListener('load', fit); window.addEventListener('resize', fit);
</script>
<?php endif; ?>
<div class="bar">
  <?php if ($statusNote !== ''): ?><span class="flag"><?= e($statusNote) ?></span><?php endif; ?>
  <button type="button" onclick="window.print()">Print / Save as PDF</button>
  <?php if ($staff): ?><a href="invoice_edit.php?id=<?= (int)$inv['id'] ?>">Edit invoice</a><?php endif; ?>
</div>

<div class="page">
  <img class="wm" src="<?= APP_URL ?>/logo-round.png" alt="">
  <?php if ($inv['status'] === 'draft'): ?><div class="stamp">DRAFT</div><?php endif; ?>
  <div class="hd">
    <img src="<?= APP_URL ?>/logo-round.png" alt="">
    <div class="co">
      <b><?= e(mb_strtoupper(COMPANY_TRADING_AS)) ?></b>
      <span class="reg">REGISTERED AS AUCTION HUB AUCTIONEERS (PTY) LTD</span>
      <?= e(COMPANY_ADDRESS) ?><br>
      <?= e(COMPANY_PHONE) ?> &nbsp;|&nbsp; <?= e(COMPANY_EMAIL) ?><br>
      Reg No: <?= e(COMPANY_REG_NO) ?> &nbsp;|&nbsp; SARS Tax No: <?= e(COMPANY_SARS_NO) ?>
    </div>
    <div class="panel">
      <div class="big">INVOICE</div>
      <div class="sub">Finance approval deposit</div>
      <div class="no">No. <?= e($inv['invoice_number']) ?></div>
    </div>
  </div>

  <div class="body">
    <div class="two">
      <div class="card">
        <div class="lbl">Bill to</div>
        <div class="name"><?= e($inv['buyer_name']) ?></div>
        <div class="small">Payment reference: <?= e($payRef) ?></div>
      </div>
      <div class="card blue">
        <div class="dates">
          <div><div class="lbl">Invoice date</div><div class="v"><?= e($ymd($issued)) ?></div></div>
          <div><div class="lbl">Due date</div><div class="v red"><?= e($ymd($due)) ?></div></div>
        </div>
        <div class="small" style="margin-top:1mm">Valid for <?= INV_PAY_HOURS ?> hours. Payable by EFT</div>
      </div>
    </div>

    <div class="veh">
      <div class="lbl">Vehicle</div>
      <div class="t"><?= e($inv['vehicle_description']) ?></div>
      <?php if ($chips): ?><div class="chips"><?php foreach ($chips as $c): ?><span><?= e((string)$c) ?></span><?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php if ($photos): ?>
      <div class="photos"><?php foreach ($photos as $p): ?><img src="<?= e(cldImg($p, CLD_CARD)) ?>" alt=""><?php endforeach; ?></div>
    <?php endif; ?>

    <table class="items">
      <tr><th>Description</th><th class="c">Qty</th><th class="r">Amount (ZAR)</th></tr>
      <tr>
        <td><b><?= e(invFinanceLine($inv)) ?></b><span class="small"><?= nl2br(e(invNote($inv))) ?></span></td>
        <td class="c">1</td>
        <td class="r"><?= $money($a['fee']) ?></td>
      </tr>
    </table>
    <div class="bal"><div class="lbl">Balance due</div><b>ZAR <?= number_format($a['fee'], 2, '.', ' ') ?></b></div>

    <h4>What happens next</h4>
    <div class="steps">
      <?php foreach ($steps as $i => [$h, $t]): ?><div><strong><i><?= $i + 1 ?></i><?= e($h) ?></strong><?= e($t) ?></div><?php endforeach; ?>
    </div>

    <div class="lower">
      <div class="bank">
        <h4>Banking details</h4>
        <table>
          <tr><td>Account Name</td><td><?= e($bank['bank_account_name']) ?></td></tr>
          <tr><td>Bank</td><td><?= e($bank['bank_name']) ?></td></tr>
          <tr><td>Account No.</td><td><?= e($bank['bank_account_no']) ?></td></tr>
          <tr><td>Branch Code</td><td><?= e($bank['bank_branch_code']) ?></td></tr>
          <tr><td>Account Type</td><td><?= e($bank['bank_account_type']) ?></td></tr>
          <tr><td>Reference</td><td><?= e($payRef) ?></td></tr>
        </table>
      </div>
      <div class="terms">
        <h4>Terms</h4>
        <ol>
          <li>Email proof of payment to <?= e(COMPANY_EMAIL) ?>.</li>
          <li>*Not satisfied after test drive and inspection, or withdrawing at any time: deposit refunded less a flat R<?= number_format(PA_REFUND_FEE, 0) ?> administration fee.</li>
          <li>Vehicle sold voetstoots (as-is). All amounts in ZAR.</li>
        </ol>
        <div class="warn"><?= e(invPayWarning()) ?></div>
      </div>
    </div>
  </div>

  <div class="ft">Thank you for your business &nbsp;&nbsp;|&nbsp;&nbsp; <?= e(COMPANY_TRADING_AS) ?> &nbsp;&nbsp;|&nbsp;&nbsp; <?= e($web) ?></div>
</div>
</body>
</html>
