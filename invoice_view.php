<?php
// ============================================================
// AUCTION HUB GROUP — invoice_view.php
// The client invoice (commitment fee or finance deposit) as an A4
// document: print, or "Save as PDF". Layout = AHG-20261115-01.
//   Staff:   ?id=ROW            (any status, drafts included; admin login)
//   Client:  ?num=AHG-…&token=… (only once the office has sent it)
// Rows live in preauction_invoices — see invoice_helper.php.
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';

invSchemaFix();
$inv = null; $staff = false;
if (!empty($_GET['id'])) {
    $u = getPageUser();
    if (($u['role'] ?? '') === 'admin') { $inv = invRow((int)$_GET['id']); $staff = true; }
} else {
    $num = trim((string)($_GET['num'] ?? '')); $tok = trim((string)($_GET['token'] ?? ''));
    if ($num !== '' && preg_match('/^[a-f0-9]{64}$/', $tok)) {
        $st = $pdo->prepare("SELECT * FROM preauction_invoices WHERE invoice_number = ? AND upload_token = ? LIMIT 1");
        $st->execute([$num, $tok]);
        $inv = $st->fetch() ?: null;
        if ($inv && $inv['status'] === 'draft') $inv = null;   // not sent yet
    }
}
if (!$inv) { http_response_code(404); exit('<p style="font-family:sans-serif;padding:2rem">This invoice is not available. Please contact ' . e(COMPANY_EMAIL) . '.</p>'); }

$isFin  = invIsFinance($inv);
$a      = invAmounts($inv);
$bank   = getBankSettings();
$photos = invPhotos($inv);
$sig    = invSignatureDataUri();
$R      = fn(float $n) => 'R ' . number_format($n, 2, '.', ',');
$Z      = fn(float $n) => 'ZAR ' . number_format($n, 2, '.', ',');
$date   = safeDate('j M Y', $inv['invoice_date'] ?: ($inv['sent_at'] ?: $inv['created_at']), date('j M Y'));
$specs  = array_filter([
    'Year' => $inv['vehicle_year'], 'Mileage' => $inv['vehicle_mileage'], 'Transmission' => $inv['vehicle_transmission'],
    'Fuel' => $inv['vehicle_fuel'], 'Body' => $inv['vehicle_body'], 'Colour' => $inv['vehicle_colour'],
    'Condition' => $inv['vehicle_condition'], 'Lot' => $inv['auction_id'],
]);
$features = array_filter(array_map('trim', preg_split('/\r?\n|,/', (string)$inv['vehicle_features'])));
$words  = preg_split('/\s+/', trim((string)$inv['buyer_name']));
$payRef = (count($words) > 1 ? mb_strtoupper(mb_substr($words[0], 0, 1)) . ' ' . end($words) : trim((string)$inv['buyer_name'])) . ' / ' . $inv['invoice_number'];
$feeWord = $isFin ? 'deposit' : 'commitment fee';
$steps = $isFin
    ? [['Pay your deposit', 'EFT the amount due, then upload or email your P.O.P.'], ['Payment & documents verified', 'Cleared funds and your documents are checked'],
       ["Receive buyer's code", 'Your buyer code is issued once everything is verified'], ['Appointment booked', 'Confirmed appointment booking time is sent']]
    : [['Pay commitment fee', 'EFT the amount due, then email your P.O.P.'], ['Payment confirmed', 'Cleared funds reflect in our bank account'],
       ["Receive buyer's code", 'Your buyer code is issued once payment is made'], ['Appointment booked', 'Confirmed appointment booking time is sent']];
$popLink = !$isFin ? paInvoiceLink($inv) : null;
$statusNote = match ($inv['status']) {
    'draft' => 'DRAFT — not sent to the client yet', 'approved' => 'PAID — buyer\'s code ' . ($inv['buyer_code'] ?: 'issued'),
    'declined' => 'DECLINED', 'pop_uploaded' => 'Proof of payment received — being verified', default => '',
};
// Finance deposit invoices have their own design (AHG-INV-YYYYMMDD-NN).
if ($isFin) { require __DIR__ . '/invoice_view_finance.php'; exit; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($inv['invoice_number']) ?> — <?= e(invTitle($inv)) ?> — <?= e(COMPANY_TRADING_AS) ?></title>
<style>
  :root { --navy:#0d2b7b; --ink:#222; --muted:#7a8499; --line:#d9dfe9; --pale:#eef2f8; }
  * { box-sizing:border-box; }
  body { margin:0; background:#e9edf3; font-family:Helvetica, Arial, sans-serif; color:var(--ink); }
  .bar { position:sticky; top:0; z-index:5; background:#0d2b7b; color:#fff; padding:.7rem 1rem; display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; justify-content:center; font-size:14px; }
  .bar a, .bar button { background:#fff; color:#0d2b7b; border:0; border-radius:6px; padding:.5rem .95rem; font-weight:700; font-size:13px; text-decoration:none; cursor:pointer; }
  .bar .flag { background:#ffd54f; color:#5c4400; padding:.35rem .7rem; border-radius:6px; font-weight:700; }
  .page { width:210mm; min-height:297mm; margin:14px auto; background:#fff; padding:11mm 12mm 8mm; position:relative; box-shadow:0 2px 14px rgba(0,0,0,.12); }
  .topline { position:absolute; left:12mm; right:12mm; top:7mm; height:2.2mm; border-radius:2px; background:linear-gradient(90deg,#0d2b7b,#1f63d6); }
  .hd { display:flex; gap:5mm; align-items:center; margin-top:4mm; }
  .hd img { width:24mm; height:24mm; border-radius:50%; object-fit:cover; }
  .hd .co { flex:1; min-width:0; font-size:7.6pt; line-height:1.5; white-space:nowrap; }
  .hd .co b { display:block; color:var(--navy); font-size:20pt; letter-spacing:.01em; line-height:1.1; margin-bottom:1mm; }
  .hd .ttl { align-self:flex-start; color:var(--navy); font-weight:700; font-size:12.5pt; text-align:right; margin-top:2mm; white-space:nowrap; }
  hr { border:0; border-top:1px solid var(--line); margin:4mm 0 3.5mm; }
  .lbl { font-size:6.6pt; font-weight:700; letter-spacing:.32em; color:var(--muted); text-transform:uppercase; }
  .meta { display:flex; gap:8mm; }
  .bill { flex:1.15; font-size:8.6pt; line-height:1.75; }
  .bill .name { font-size:12.5pt; font-weight:700; margin:2.5mm 0 1.5mm; line-height:1.3; }
  .info { flex:1; }
  .info table { width:100%; border-collapse:collapse; font-size:8.6pt; }
  .info td { padding:1.15mm 0; }
  .info td:last-child { text-align:right; font-weight:700; }
  .due { margin-top:3mm; border:1px solid var(--line); background:var(--pale); border-radius:3mm; text-align:center; padding:2.6mm; }
  .due b { display:block; color:var(--navy); font-size:15pt; margin-top:1.2mm; }
  .desc { margin-top:4mm; background:var(--navy); color:#fff; border-radius:3mm; padding:2.3mm 5mm; display:flex; justify-content:space-between; }
  .desc .lbl { color:#fff; }
  .item { display:flex; justify-content:space-between; gap:6mm; padding:3.5mm 5mm 0; }
  .item .t { font-weight:700; font-size:11.3pt; }
  .item .p { font-weight:700; font-size:9.8pt; white-space:nowrap; }
  .specs { padding:1.5mm 5mm 0; color:var(--muted); font-size:7.6pt; line-height:1.55; }
  .feats { display:grid; grid-template-columns:1fr 1fr; grid-auto-flow:column; gap:.4mm 6mm; padding:4mm 5mm 0 7mm; font-size:8pt; list-style:none; margin:0; }
  .feats li::before { content:'•'; color:var(--muted); margin-right:2.5mm; }
  .photos { display:grid; grid-template-columns:repeat(4,1fr); gap:2.4mm; margin:3.5mm 0 0; }
  .photos img { width:100%; height:27mm; object-fit:cover; display:block; }
  .lower { display:flex; gap:8mm; margin-top:5mm; }
  .note { flex:1; font-size:8.3pt; line-height:1.55; }
  .note p { margin:2.5mm 0 0; }
  .sig { margin-top:2.5mm; height:13mm; display:flex; align-items:flex-end; }
  .sig img { max-height:13mm; max-width:60mm; }
  .sigline { border-top:1px solid var(--ink); width:72mm; padding-top:1.6mm; }
  .tot { flex:1; font-size:8.6pt; }
  .tot table { width:100%; border-collapse:collapse; }
  .tot td { padding:1.2mm 0; color:#6b7385; }
  .tot td:last-child { text-align:right; font-weight:700; color:var(--ink); }
  .tot tr.out td { color:var(--ink); font-weight:700; border-top:1px solid var(--line); }
  .tot td.small { font-size:6.6pt; color:var(--muted); padding-top:0; text-align:left; font-weight:400; }
  .tot .bd { display:flex; justify-content:space-between; align-items:center; border-top:2px solid var(--navy); margin-top:4mm; padding-top:3mm; color:var(--navy); font-weight:700; }
  .tot .bd b { font-size:15pt; }
  .steps { display:grid; grid-template-columns:repeat(4,1fr); gap:2.4mm; margin-top:2.5mm; }
  .steps div { border:1px solid var(--line); background:#f7f9fc; border-radius:2.4mm; padding:2.3mm; font-size:7pt; color:#6b7385; line-height:1.4; }
  .steps strong { display:flex; gap:1.6mm; align-items:center; color:var(--navy); font-size:7.5pt; margin-bottom:1mm; }
  .steps i { font-style:normal; background:var(--navy); color:#fff; width:4.4mm; height:4.4mm; border-radius:50%; display:inline-grid; place-items:center; font-size:6.5pt; flex:none; }
  .pay { display:flex; gap:7mm; margin-top:4mm; align-items:flex-start; }
  .bank { flex:1.15; border:1px solid var(--line); background:#f3f6fa; border-radius:3mm; padding:3.5mm 5mm; }
  .bank table { width:100%; font-size:8pt; border-collapse:collapse; margin-top:2mm; }
  .bank td { padding:.9mm 0; color:#6b7385; width:34%; }
  .bank td + td { color:var(--ink); font-weight:700; width:auto; }
  .instr { flex:1; font-size:7.8pt; line-height:1.55; }
  .stamp { position:absolute; left:84mm; top:47mm; transform:rotate(-12deg); border:2.5px solid #c8102e; color:#c8102e; font-weight:800; padding:1.5mm 4mm; border-radius:2mm; font-size:12pt; opacity:.85; }
  .terms h2 { color:var(--navy); font-size:12pt; margin:4mm 0 4mm; letter-spacing:.04em; }
  .terms p { font-size:8.2pt; line-height:1.6; margin:0 0 3mm; }
  .terms .end { text-align:center; margin-top:10mm; color:var(--muted); font-size:8pt; }
  @media print {
    @page { size:A4; margin:0; }
    body { background:#fff; }
    .bar { display:none; }
    .page { margin:0; box-shadow:none; page-break-after:always; }
    .page:last-child { page-break-after:auto; }
    * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
  @media screen and (max-width: 820px) { .page { transform-origin:top left; } }
</style>
</head>
<body>
<?php if (!empty($_GET['embed'])): // preview inside invoice_edit.php: no toolbar, page shrunk to the panel ?>
<style>.bar { display:none; } .page { margin:8px auto; }</style>
<script>
  function fit() { var w = document.querySelector('.page').offsetWidth + 16; document.body.style.zoom = Math.min(1, window.innerWidth / w); }
  window.addEventListener('load', fit); window.addEventListener('resize', fit);
</script>
<?php endif; ?>
<div class="bar">
  <?php if ($statusNote !== ''): ?><span class="flag"><?= e($statusNote) ?></span><?php endif; ?>
  <button type="button" onclick="window.print()">Print / Save as PDF</button>
  <?php if ($staff): ?>
    <a href="invoice_edit.php?id=<?= (int)$inv['id'] ?>">Edit invoice</a>
  <?php elseif ($popLink && in_array($inv['status'], ['pending'], true)): ?>
    <a href="<?= e($popLink) ?>">Upload proof of payment</a>
  <?php endif; ?>
</div>

<div class="page">
  <div class="topline"></div>
  <?php if ($inv['status'] === 'draft'): ?><div class="stamp">DRAFT</div><?php endif; ?>
  <div class="hd">
    <img src="<?= APP_URL ?>/logo-round.png" alt="">
    <div class="co">
      <b><?= e(mb_strtoupper(COMPANY_TRADING_AS)) ?></b>
      <?= e(COMPANY_ADDRESS) ?><br>
      <?= e(str_replace(' ', '', COMPANY_PHONE)) ?> &nbsp;|&nbsp; <?= e(COMPANY_EMAIL) ?> &nbsp;|&nbsp; <?= e(preg_replace('~^https?://(www\.)?~', '', CANONICAL_URL)) ?><br>
      Reg: <?= e(COMPANY_REG_NO) ?> &nbsp;|&nbsp; SARS Tax No: <?= e(COMPANY_SARS_NO) ?> &nbsp;|&nbsp; Reg Date: <?= e(COMPANY_REG_DATE) ?>
    </div>
    <div class="ttl"><?= e(invTitle($inv)) ?></div>
  </div>
  <hr>

  <div class="meta">
    <div class="bill">
      <div class="lbl">Bill to</div>
      <div class="name"><?= e($inv['buyer_name']) ?></div>
      <?php if ($inv['buyer_phone']): ?>Tel: <?= e($inv['buyer_phone']) ?><br><?php endif; ?>
      <?php if ($inv['buyer_email']): ?>Email: <?= e($inv['buyer_email']) ?><br><?php endif; ?>
      <?php if ($inv['buyer_id_passport']): ?>ID No: <?= e($inv['buyer_id_passport']) ?><br><?php endif; ?>
      <?php if ($inv['buyer_address']): ?>Address: <?= e($inv['buyer_address']) ?><?php endif; ?>
    </div>
    <div class="info">
      <table>
        <tr><td class="lbl">Invoice no.</td><td><?= e($inv['invoice_number']) ?></td></tr>
        <tr><td class="lbl">Invoice date</td><td><?= e($date) ?></td></tr>
        <?php if ($inv['auction_id']): ?><tr><td class="lbl">Lot no.</td><td><?= (int)$inv['auction_id'] ?></td></tr><?php endif; ?>
        <tr><td class="lbl">Due date</td><td><?= e($inv['due_text'] ?: 'On receipt') ?></td></tr>
      </table>
      <div class="due"><div class="lbl">Balance due</div><b><?= $Z($a['fee']) ?></b></div>
    </div>
  </div>

  <div class="desc"><span class="lbl">Description</span><span class="lbl">Total (ZAR)</span></div>
  <div class="item"><span class="t"><?= e($inv['vehicle_description']) ?></span><span class="p"><?= $R($a['price']) ?></span></div>
  <?php if ($specs): ?>
    <div class="specs"><?= implode(' &nbsp;·&nbsp; ', array_map(fn($k, $v) => e($k) . ': ' . e((string)$v), array_keys($specs), $specs)) ?></div>
  <?php endif; ?>
  <?php if ($features): ?><ul class="feats" style="grid-template-rows:repeat(<?= (int)ceil(count($features) / 2) ?>, auto)"><?php foreach ($features as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($photos): ?>
    <div class="photos"><?php foreach ($photos as $p): ?><img src="<?= e(cldImg($p, CLD_CARD)) ?>" alt=""><?php endforeach; ?></div>
  <?php endif; ?>

  <div class="lower">
    <div class="note">
      <div class="lbl">Note</div>
      <p><?= nl2br(e(invNote($inv))) ?></p>
      <div class="sig"><?php if ($sig !== ''): ?><img src="<?= $sig ?>" alt="Signature"><?php endif; ?></div>
      <div class="sigline"><div class="lbl">Authorised signature</div><div class="lbl" style="margin-top:1.5mm"><?= e(COMPANY_TRADING_AS) ?></div></div>
    </div>
    <div class="tot">
      <table>
        <tr><td>Vehicle price</td><td><?= $R($a['price']) ?></td></tr>
        <tr><td>Less: <?= $feeWord ?> (this invoice)</td><td>-<?= $R($a['fee']) ?></td></tr>
        <tr class="out"><td><?= $isFin ? 'Balance to be financed' : 'Outstanding balance after payment' ?></td><td><?= $R($a['rest']) ?></td></tr>
        <tr><td class="small" colspan="2"><?= ucfirst($feeWord) ?> = <?= e(formatRand($a['fee'])) ?> (<?= invPctLabel($a['pct']) ?> of vehicle price)</td></tr>
      </table>
      <div class="bd"><span>BALANCE DUE</span><b><?= $Z($a['fee']) ?></b></div>
    </div>
  </div>

  <div class="lbl" style="margin-top:4.5mm">What happens once payment is made</div>
  <div class="steps">
    <?php foreach ($steps as $i => [$h, $t]): ?><div><strong><i><?= $i + 1 ?></i><?= e($h) ?></strong><?= e($t) ?></div><?php endforeach; ?>
  </div>

  <div class="pay">
    <div class="bank">
      <div class="lbl">Banking details</div>
      <table>
        <tr><td>Account Name</td><td><?= e($bank['bank_account_name']) ?></td></tr>
        <tr><td>Bank</td><td><?= e($bank['bank_name']) ?></td></tr>
        <tr><td>Account No.</td><td><?= e($bank['bank_account_no']) ?></td></tr>
        <tr><td>Branch Code</td><td><?= e($bank['bank_branch_code']) ?></td></tr>
        <tr><td>Reference</td><td><?= e($payRef) ?></td></tr>
      </table>
    </div>
    <div class="instr">
      <strong>Payment instructions.</strong> Please pay by EFT to the account shown, using your name and surname as reference, and email proof of payment (P.O.P.)
      to <?= e(COMPANY_EMAIL) ?>. All amounts are in South African Rand. Ownership of the vehicle passes to the purchaser only once the full purchase price
      reflects in the seller's bank account.
    </div>
  </div>
</div>

<div class="page terms">
  <div class="topline"></div>
  <h2>TERMS &amp; CONDITIONS</h2>
  <p><strong>1. General.</strong> This invoice is issued by Auction Hub Auctioneers (Pty) Ltd, trading as <?= e(COMPANY_TRADING_AS) ?>. All amounts are in South African Rand (ZAR). Value-Added Tax at the prevailing rate of 15% is reflected separately where applicable, together with any applicable fees.</p>
  <p><strong>2. Payment.</strong> The full amount is payable by the due date via electronic funds transfer (EFT) into the banking details reflected above. The purchaser's name and surname must be used as the payment reference and proof of payment emailed to <?= e(COMPANY_EMAIL) ?>.</p>
  <p><strong>3. Ownership &amp; Risk.</strong> Ownership and risk in the vehicle pass to the purchaser only once the full purchase price has been received and reflects as cleared funds in the seller's bank account.</p>
  <p><strong>4. Voetstoots.</strong> The vehicle is sold voetstoots (as-is).</p>
  <p><strong>5. Roadworthy &amp; Registration.</strong> The seller will provide all documentation reasonably required to effect the change of ownership. A DEKRA and/or roadworthy certificate is provided where applicable.</p>
  <p><strong>6. Warranty.</strong> Any mechanical or electronic warranty applies only to the extent expressly stated on this invoice. Save as required by law, no further warranties are given or implied.</p>
  <p><strong>7. Delivery.</strong> The vehicle is handed over or delivered once the full purchase price reflects. Delivery arrangements are as agreed in writing between the parties.</p>
  <p><strong>8. Refunds.</strong> Where a refund is due, a reasonable administration and bank charge may be deducted. Refunds are processed to the original paying account.</p>
  <p><strong>9. Governing Law.</strong> This agreement and any dispute arising from it are governed by and construed in accordance with the laws of the Republic of South Africa.</p>
  <p class="end">Thank you for your business.<br><?= e(COMPANY_TRADING_AS) ?> · <?= e(preg_replace('~^https?://(www\.)?~', '', CANONICAL_URL)) ?></p>
</div>
</body>
</html>
