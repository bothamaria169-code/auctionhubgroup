<?php
// ============================================================
// AUCTION HUB GROUP — invoice_print.php
// Print-ready invoice — Standard and Pre-Auction types
// Access: ?id=X (pre-auction by DB id) OR POST (standard)
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$type = 'standard';
$inv  = null;
$isPreAuction = false;

// ── Load Pre-Auction invoice from DB ──────────────────────
if (!empty($_GET['id'])) {
    if (!isAdmin()) {
        die('<p style="font-family:sans-serif;padding:2rem;color:#721c24;">Access denied.</p>');
    }
    $stmt = $pdo->prepare("SELECT * FROM preauction_invoices WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $inv = $stmt->fetch();
    if (!$inv) die('<p style="font-family:sans-serif;padding:2rem;color:#721c24;">Invoice not found.</p>');
    $type = 'preauction';
    $isPreAuction = true;
}

// ── Standard invoice from POST ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['type'] ?? '') === 'standard') {
    $inv = [
        'invoice_number'          => trim($_POST['invoice_number']          ?? ('AHG-INV-' . strtoupper(bin2hex(random_bytes(3))))),
        'buyer_name'              => trim($_POST['buyer_name']              ?? ''),
        'buyer_id_passport'       => trim($_POST['buyer_id_passport']       ?? ''),
        'buyer_address'           => trim($_POST['buyer_address']           ?? ''),
        'buyer_phone'             => trim($_POST['buyer_phone']             ?? ''),
        'buyer_email'             => trim($_POST['buyer_email']             ?? ''),
        'vehicle_description'     => trim($_POST['vehicle_description']     ?? ''),
        'vehicle_year'            => trim($_POST['vehicle_year']            ?? ''),
        'vehicle_mileage'         => trim($_POST['vehicle_mileage']         ?? ''),
        'vehicle_transmission'    => trim($_POST['vehicle_transmission']    ?? ''),
        'vehicle_service_history' => trim($_POST['vehicle_service_history'] ?? 'Yes'),
        'vehicle_condition'       => trim($_POST['vehicle_condition']       ?? 'Used'),
        'vehicle_body'            => trim($_POST['vehicle_body']            ?? ''),
        'vehicle_colour'          => trim($_POST['vehicle_colour']          ?? ''),
        'vehicle_fuel'            => trim($_POST['vehicle_fuel']            ?? ''),
        'vehicle_features'        => trim($_POST['vehicle_features']        ?? ''),
        'vehicle_image_url'       => trim($_POST['vehicle_image_url']       ?? ''),
        'total_price'             => (float)($_POST['total_price']         ?? 0),
        'commitment_fee_pct'      => 0,
        'commitment_fee_amount'   => 0,
        'outstanding_amount'      => (float)($_POST['total_price']         ?? 0),
        'created_at'              => date('Y-m-d H:i:s'),
        'expires_at'              => date('Y-m-d H:i:s', strtotime('+24 hours')),
        'status'                  => 'pending',
        'buyer_code'              => null,
    ];
    $invDate    = trim($_POST['invoice_date'] ?? date('Y/m/d'));
    $invDueDate = trim($_POST['due_date']     ?? date('Y/m/d'));
    $type       = 'standard';
}

if (!$inv) {
    die('<p style="font-family:sans-serif;padding:2rem;color:#721c24;">No invoice data provided.</p>');
}

// ── Formatting helpers ────────────────────────────────────
function rands(float $n): string {
    return 'R' . number_format($n, 2, '.', ' ');
}
function rands2(float $n): string {
    return 'R' . number_format($n, 2, '.', ',');
}
function d(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$invoiceDate  = isset($invDate)    ? $invDate    : safeDate('Y/m/d', $inv['created_at'], date('Y/m/d'));
$dueDate      = isset($invDueDate) ? $invDueDate : safeDate('Y/m/d', $inv['expires_at'],   date('Y/m/d'));
$expiresAt    = safeDate('Y/m/d H:i', $inv['expires_at'], date('Y/m/d H:i'));
$dateSigned   = !empty($inv['date_signed']) ? safeDate('Y/m/d', $inv['date_signed'], date('Y/m/d')) : date('Y/m/d');

// Features as lines
$features     = array_filter(array_map('trim', explode("\n", $inv['vehicle_features'] ?? '')));

// Bank details
$bank         = getBankSettings();

// Agreement clauses heading
$sellerName   = COMPANY_NAME;
$buyerName    = $inv['buyer_name'];
$vehicleDesc  = $inv['vehicle_description'];

// ── Pricing & VAT (South Africa, 15%) ─────────────────────────
// Standard invoice: the entered price is the vehicle price EXCLUDING VAT.
// VAT (15%) is added on top; an optional Document Handling Fee is added
// after VAT to arrive at the total due.
$VAT_RATE     = 0.15;
$priceExcl    = (float)$inv['total_price'];              // vehicle price excl VAT
$vatAmount    = round($priceExcl * $VAT_RATE, 2);        // 15% VAT on vehicle
$vehicleIncl  = round($priceExcl + $vatAmount, 2);       // vehicle incl VAT
$docFee       = isset($_POST['doc_handling_fee']) ? (float)$_POST['doc_handling_fee'] : 0.0;
$grandTotal   = round($vehicleIncl + $docFee, 2);        // total due
// Aliases kept for the plain-number balance displays below
$netTotal     = $priceExcl;
$grossTotal   = $grandTotal;

// ══════════════════════════════════════════════════════════════
//  STANDARD INVOICE — single-page, VAT-inclusive TAX INVOICE
//  Momentum-style layout with downloadable PDF (no print headers)
// ══════════════════════════════════════════════════════════════
if (!$isPreAuction):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tax Invoice <?= d($inv['invoice_number']) ?> — <?= d(COMPANY_TRADING_AS) ?></title>
<style>
  * { box-sizing:border-box; margin:0; padding:0; }
  :root{
    --navy:#001F5C; --blue:#005DDB; --ink:#1a1a1a; --muted:#5b6b7f;
    --line:#e2e8f0; --soft:#f6f8fc; --accent:#0a4da2;
  }
  html{ font-size:10pt; }
  body{ font-family:'Helvetica Neue',Arial,sans-serif; color:var(--ink); background:#eef1f6; line-height:1.45; }

  /* Screen toolbar (never printed / never in PDF) */
  .toolbar{
    position:sticky; top:0; z-index:50;
    background:linear-gradient(90deg,var(--navy),#003580); color:#fff;
    display:flex; align-items:center; justify-content:space-between; gap:.75rem;
    padding:10px 16px; flex-wrap:wrap;
  }
  .toolbar .t-info{ min-width:0; }
  .toolbar .t-info h2{ font-size:.95rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .toolbar .t-info span{ font-size:.74rem; opacity:.8; }
  .t-actions{ display:flex; gap:.5rem; flex-wrap:wrap; }
  .tbtn{ padding:.5rem 1rem; border-radius:6px; font-weight:700; font-size:.82rem; cursor:pointer; border:none;
         display:inline-flex; align-items:center; gap:.4rem; white-space:nowrap; }
  .tbtn-primary{ background:#fff; color:var(--navy); }
  .tbtn-primary:hover{ background:#e8f0fa; }
  .tbtn-ghost{ background:rgba(255,255,255,.12); color:#fff; border:1px solid rgba(255,255,255,.35); }
  .tbtn-ghost:hover{ background:rgba(255,255,255,.22); }

  /* A4 sheet */
  .sheet{
    width:210mm; min-height:297mm; margin:22px auto; background:#fff;
    box-shadow:0 6px 30px rgba(0,0,0,.14); padding:12mm 13mm 10mm;
    display:flex; flex-direction:column;
  }

  /* Header */
  .head{ display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding-bottom:12px; }
  .brand{ display:flex; gap:14px; align-items:center; }
  .brand img{ width:66px; height:66px; object-fit:contain; }
  .brand .co-name{ font-size:16pt; font-weight:900; color:var(--navy); letter-spacing:.02em; text-transform:uppercase; line-height:1.05; }
  .brand .co-reg{ font-size:7.5pt; font-weight:700; color:var(--blue); text-transform:uppercase; letter-spacing:.04em; margin-top:2px; }
  .brand .co-meta{ font-size:8pt; color:var(--muted); margin-top:5px; line-height:1.55; }
  .brand .co-meta a{ color:var(--blue); text-decoration:none; }
  .doc-title{ text-align:right; flex-shrink:0; }
  .doc-title .tag{ font-size:17pt; font-weight:900; color:var(--navy); letter-spacing:.06em; text-transform:uppercase; }
  .doc-title .vat-badge{ display:inline-block; margin-top:4px; font-size:7pt; font-weight:700; color:#fff;
        background:var(--blue); border-radius:20px; padding:2px 9px; letter-spacing:.06em; text-transform:uppercase; }

  .rule{ height:4px; background:linear-gradient(90deg,var(--navy),var(--blue),#1E90FF); border-radius:3px; margin:6px 0 14px; }

  /* Meta / Bill-to */
  .info-row{ display:flex; justify-content:space-between; gap:24px; margin-bottom:10px; }
  .bill-to{ flex:1; }
  .lbl{ font-size:7pt; font-weight:800; color:#8794a5; text-transform:uppercase; letter-spacing:.12em; margin-bottom:5px; }
  .bill-to .name{ font-size:12.5pt; font-weight:800; margin-bottom:3px; }
  .bill-to .det{ font-size:8.5pt; color:#3f4a58; line-height:1.65; }
  .meta{ flex:0 0 220px; }
  .meta table{ width:100%; border-collapse:collapse; }
  .meta td{ padding:3px 0; font-size:8.5pt; vertical-align:top; }
  .meta td.k{ color:#8794a5; font-weight:700; text-transform:uppercase; font-size:7pt; letter-spacing:.06em; white-space:nowrap; padding-right:12px; }
  .meta td.v{ text-align:right; font-weight:700; }
  .meta .balance{ margin-top:8px; background:var(--soft); border:1px solid var(--line); border-radius:6px; padding:8px 12px; text-align:right; }
  .meta .balance .bl-lbl{ font-size:7pt; font-weight:800; color:#5b6b7f; letter-spacing:.1em; text-transform:uppercase; }
  .meta .balance .bl-amt{ font-size:14pt; font-weight:900; color:var(--navy); }

  /* Items */
  table.items{ width:100%; border-collapse:collapse; margin-bottom:2px; }
  table.items thead th{ background:var(--navy); color:#fff; font-size:7.5pt; font-weight:700; text-transform:uppercase;
        letter-spacing:.08em; padding:9px 12px; text-align:right; }
  table.items thead th:first-child{ text-align:left; border-radius:5px 0 0 0; }
  table.items thead th:last-child{ border-radius:0 5px 0 0; }
  table.items tbody td{ padding:9px 12px; font-size:9pt; border-bottom:1px solid var(--line); vertical-align:top; text-align:right; }
  table.items tbody td:first-child{ text-align:left; }
  .it-name{ font-weight:800; font-size:10pt; margin-bottom:4px; }
  .it-spec{ font-size:7.8pt; color:#5b6b7f; line-height:1.6; }
  .it-feat{ font-size:7.8pt; color:#3f4a58; line-height:1.7; margin-top:4px; }
  td.money{ font-variant-numeric:tabular-nums; white-space:nowrap; }
  td.total-cell{ font-weight:800; }

  /* Totals */
  .totals{ display:flex; justify-content:flex-end; margin-top:10px; }
  .totals table{ width:270px; border-collapse:collapse; }
  .totals td{ padding:4px 0; font-size:9pt; }
  .totals td.k{ color:#5b6b7f; }
  .totals td.v{ text-align:right; font-weight:700; font-variant-numeric:tabular-nums; }
  .totals tr.grand td{ border-top:2px solid var(--ink); padding-top:7px; }
  .totals tr.grand td.k{ font-weight:800; text-transform:uppercase; font-size:8.5pt; color:var(--ink); }
  .totals tr.grand td.v{ font-size:12pt; font-weight:900; color:var(--navy); }

  /* Payment / banking */
  .pay{ display:flex; gap:18px; margin-top:11px; }
  .pay .bank{ flex:1; background:var(--soft); border:1px solid var(--line); border-radius:8px; padding:12px 14px; }
  .pay .bank .lbl{ margin-bottom:8px; }
  .bank-grid{ display:grid; grid-template-columns:auto 1fr; gap:3px 14px; font-size:8.5pt; }
  .bank-grid .bk{ color:#5b6b7f; font-weight:700; }
  .bank-grid .bv{ font-weight:800; }
  .pay .note{ flex:0 0 42%; font-size:7.6pt; color:#5b6b7f; line-height:1.6; }
  .pay .note strong{ color:var(--ink); }

  /* Terms */
  .terms{ margin-top:12px; padding-top:6px; }
  .terms-title{ font-size:8.5pt; font-weight:800; color:var(--navy); text-transform:uppercase; letter-spacing:.08em;
        border-top:1px solid var(--line); padding-top:8px; margin-bottom:6px; }
  .terms-cols{ column-count:2; column-gap:20px; font-size:6.8pt; color:#5b6b7f; line-height:1.5; text-align:justify; }
  .terms-cols p{ margin-bottom:4px; break-inside:avoid; }
  .terms-cols strong{ color:var(--ink); }

  /* Signature strip */
  .sign{ display:flex; justify-content:space-between; align-items:flex-end; gap:24px; margin-top:12px; }
  .sign .sig-line{ border-top:1.4px solid var(--ink); width:180px; margin-bottom:3px; }
  .sign .sig-lbl{ font-size:7pt; font-weight:700; color:#5b6b7f; text-transform:uppercase; letter-spacing:.06em; }
  .sign .thanks{ text-align:right; font-size:8pt; color:var(--muted); }
  .sign .thanks b{ color:var(--navy); }

  /* Edit panel (screen only) */
  .ovl{ position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:90; display:none; }
  .ovl.show{ display:block; }
  .epanel{ position:fixed; top:0; right:0; height:100vh; width:min(360px,100vw); background:#fff; z-index:100;
    box-shadow:-8px 0 32px rgba(0,31,92,.18); transform:translateX(100%); transition:transform .28s cubic-bezier(.4,0,.2,1);
    display:flex; flex-direction:column; overflow-y:auto; }
  .epanel.open{ transform:translateX(0); }
  .ep-head{ background:linear-gradient(90deg,var(--navy),var(--blue)); color:#fff; padding:14px 18px; display:flex;
    justify-content:space-between; align-items:center; position:sticky; top:0; }
  .ep-head h3{ font-size:.95rem; }
  .ep-close{ background:none; border:none; color:#fff; font-size:1.4rem; cursor:pointer; line-height:1; }
  .ep-body{ padding:16px 18px; }
  .eg{ margin-bottom:12px; }
  .eg label{ display:block; font-size:.7rem; font-weight:700; color:#4a6080; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
  .eg input,.eg textarea{ width:100%; padding:7px 10px; border:1.5px solid #c8d8ea; border-radius:6px; font-size:.85rem;
    font-family:inherit; background:#f7f9fc; }
  .eg input:focus,.eg textarea:focus{ outline:none; border-color:var(--blue); background:#fff; }
  .ep-sect{ font-size:.78rem; font-weight:800; color:var(--navy); text-transform:uppercase; letter-spacing:.06em;
    margin:16px 0 10px; border-bottom:1.5px solid #e8eef5; padding-bottom:5px; }

  @media (max-width:820px){
    .sheet{ width:100%; min-height:0; padding:18px 16px; margin:12px auto; }
    .head{ flex-direction:column; }
    .doc-title{ text-align:left; }
    .info-row{ flex-direction:column; gap:14px; }
    .meta{ flex:none; }
    .pay{ flex-direction:column; }
    .pay .note{ flex:none; }
    .terms-cols{ column-count:1; }
    table.items thead th:nth-child(2), table.items tbody td:nth-child(2){ display:none; }
  }

  /* PDF/print: remove all browser chrome, edge-to-edge A4 */
  @page{ size:A4; margin:0; }
  @media print{
    body{ background:#fff; }
    .toolbar,.epanel,.ovl{ display:none !important; }
    .sheet{ box-shadow:none; margin:0; width:auto; min-height:auto; padding:12mm 12mm 10mm; }
  }
</style>
</head>
<body>

<!-- Screen toolbar — excluded from PDF & print -->
<div class="toolbar" id="toolbar">
  <div class="t-info">
    <h2>&#128196; Tax Invoice <?= d($inv['invoice_number']) ?></h2>
    <span><?= d($inv['buyer_name']) ?> &mdash; ZAR <span class="gross-display"><?= number_format($grossTotal,2,'.',' ') ?></span></span>
  </div>
  <div class="t-actions">
    <button class="tbtn tbtn-ghost" onclick="openEdit()">&#9998; Edit</button>
    <button class="tbtn tbtn-ghost" onclick="window.print()">&#128438; Print</button>
    <button class="tbtn tbtn-primary" id="dlBtn" onclick="downloadPDF()">&#11015; Download PDF</button>
  </div>
</div>

<!-- ══════════════════ A4 INVOICE SHEET ══════════════════ -->
<div class="sheet" id="sheet">

  <div class="head">
    <div class="brand">
      <img src="<?= APP_URL ?>/logo-round.png" alt="<?= d(COMPANY_TRADING_AS) ?>" crossorigin="anonymous">
      <div>
        <div class="co-name"><?= d(COMPANY_TRADING_AS) ?></div>
        <div class="co-reg">Registered as <?= d(COMPANY_REGISTERED_NAME) ?> (Pty) Ltd</div>
        <div class="co-meta">
          <?= d(COMPANY_ADDRESS) ?><br>
          <strong><?= d(COMPANY_PHONE) ?></strong> &nbsp;|&nbsp; <a href="mailto:<?= d(COMPANY_EMAIL) ?>"><?= d(COMPANY_EMAIL) ?></a><br>
          Reg No: <?= d(COMPANY_REG_NO) ?> &nbsp;|&nbsp; SARS Tax No: <?= d(COMPANY_SARS_NO) ?>
        </div>
      </div>
    </div>
    <div class="doc-title">
      <div class="tag">Tax Invoice</div>
      <div class="vat-badge">VAT @ 15%</div>
    </div>
  </div>

  <div class="rule"></div>

  <div class="info-row">
    <div class="bill-to">
      <div class="lbl">Bill To</div>
      <div class="name" id="d-buyer-name"><?= d($inv['buyer_name']) ?></div>
      <div class="det">
        <?php if ($inv['buyer_id_passport']): ?>ID / Passport: <span id="d-buyer-id"><?= d($inv['buyer_id_passport']) ?></span><br><?php endif; ?>
        <?php if ($inv['buyer_address']): ?><span id="d-buyer-addr"><?= nl2br(d($inv['buyer_address'])) ?></span><br><?php endif; ?>
        <?php if ($inv['buyer_phone']): ?>Tel: <span id="d-buyer-phone"><?= d($inv['buyer_phone']) ?></span><br><?php endif; ?>
        <?php if ($inv['buyer_email']): ?><span id="d-buyer-email"><?= d($inv['buyer_email']) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="meta">
      <table>
        <tr><td class="k">Invoice No.</td><td class="v" id="d-inv-no"><?= d($inv['invoice_number']) ?></td></tr>
        <tr><td class="k">Invoice Date</td><td class="v" id="d-inv-date"><?= d($invoiceDate) ?></td></tr>
        <tr><td class="k">Due Date</td><td class="v" id="d-due-date"><?= d($dueDate) ?></td></tr>
      </table>
      <div class="balance">
        <div class="bl-lbl">Balance Due</div>
        <div class="bl-amt">ZAR <span class="gross-display"><?= number_format($grossTotal,2,'.',' ') ?></span></div>
      </div>
    </div>
  </div>

  <table class="items">
    <thead>
      <tr>
        <th style="width:52%">Description</th>
        <th style="width:16%">Price (Excl.)</th>
        <th style="width:14%">VAT 15%</th>
        <th style="width:18%">Total (Incl.)</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <div class="it-name" id="d-veh-desc"><?= d($inv['vehicle_description']) ?></div>
          <div class="it-spec">
            <?php
              $specs = [];
              if ($inv['vehicle_year'])            $specs[] = 'Year: '.d($inv['vehicle_year']);
              if ($inv['vehicle_mileage'])         $specs[] = 'Mileage: '.d($inv['vehicle_mileage']);
              if ($inv['vehicle_transmission'])    $specs[] = 'Transmission: '.d($inv['vehicle_transmission']);
              if ($inv['vehicle_fuel'])            $specs[] = 'Fuel: '.d($inv['vehicle_fuel']);
              if ($inv['vehicle_body'])            $specs[] = 'Body: '.d($inv['vehicle_body']);
              if ($inv['vehicle_colour'])          $specs[] = 'Colour: '.d($inv['vehicle_colour']);
              if ($inv['vehicle_condition'])       $specs[] = 'Condition: '.d($inv['vehicle_condition']);
              if ($inv['vehicle_service_history']) $specs[] = 'Service History: '.d($inv['vehicle_service_history']);
              echo implode(' &nbsp;&middot;&nbsp; ', $specs);
            ?>
          </div>
          <?php if ($features): ?>
          <div class="it-feat"><?php foreach ($features as $f): ?>&rsaquo; <?= d($f) ?><br><?php endforeach; ?></div>
          <?php endif; ?>
        </td>
        <td class="money net-display"><?= rands2($priceExcl) ?></td>
        <td class="money vat-display"><?= rands2($vatAmount) ?></td>
        <td class="money total-cell vehincl-display"><?= rands2($vehicleIncl) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="totals">
    <table>
      <tr><td class="k">Subtotal (Excl. VAT)</td><td class="v net-display"><?= rands2($priceExcl) ?></td></tr>
      <tr><td class="k">VAT @ 15%</td><td class="v vat-display"><?= rands2($vatAmount) ?></td></tr>
      <tr><td class="k">Vehicle Total (Incl. VAT)</td><td class="v vehincl-display"><?= rands2($vehicleIncl) ?></td></tr>
      <tr id="docfee-row" style="<?= $docFee > 0 ? '' : 'display:none;' ?>"><td class="k">Document Handling Fee</td><td class="v doc-display"><?= rands2($docFee) ?></td></tr>
      <tr class="grand"><td class="k">Balance Due</td><td class="v">ZAR <span class="gross-display"><?= number_format($grandTotal,2,'.',' ') ?></span></td></tr>
    </table>
  </div>

  <div class="pay">
    <div class="bank">
      <div class="lbl">Banking Details</div>
      <div class="bank-grid">
        <div class="bk">Account Name</div><div class="bv"><?= d($bank['bank_account_name'] ?? COMPANY_NAME) ?></div>
        <div class="bk">Bank</div><div class="bv"><?= d($bank['bank_name']) ?></div>
        <div class="bk">Account No.</div><div class="bv"><?= d($bank['bank_account_no']) ?></div>
        <div class="bk">Branch Code</div><div class="bv"><?= d($bank['bank_branch_code']) ?></div>
        <div class="bk">Account Type</div><div class="bv"><?= d($bank['bank_account_type']) ?></div>
        <div class="bk">Reference</div><div class="bv"><?= d($inv['buyer_name'] ?: 'Your name &amp; surname') ?></div>
      </div>
    </div>
    <div class="note">
      <strong>Payment instructions.</strong> Please pay by EFT to the account shown, using your name and surname as
      reference, and email proof of payment (P.O.P.) to <?= d(COMPANY_EMAIL) ?>. All amounts are in South African Rand;
      VAT at 15% is included in the total due. Ownership of the vehicle passes to the purchaser only once the full
      purchase price reflects in the seller's bank account.
    </div>
  </div>

  <div class="terms">
    <div class="terms-title">Terms &amp; Conditions</div>
    <div class="terms-cols">
      <p><strong>1. General.</strong> This tax invoice is issued by <?= d(COMPANY_REGISTERED_NAME) ?> (Pty) Ltd, trading as <?= d(COMPANY_TRADING_AS) ?>. All amounts are in South African Rand (ZAR). The vehicle price is shown exclusive of VAT; Value-Added Tax at the prevailing rate of 15% is added and reflected separately, together with any applicable fees.</p>
      <p><strong>2. Payment.</strong> The full amount is payable by the due date via electronic funds transfer (EFT) into the banking details reflected above. The purchaser's name and surname must be used as the payment reference and proof of payment emailed to <?= d(COMPANY_EMAIL) ?>.</p>
      <p><strong>3. Ownership &amp; Risk.</strong> Ownership and risk in the vehicle pass to the purchaser only once the full purchase price has been received and reflects as cleared funds in the seller's bank account.</p>
      <p><strong>4. Voetstoots.</strong> The vehicle is sold voetstoots (as-is).</p>
      <p><strong>5. Roadworthy &amp; Registration.</strong> The seller will provide all documentation reasonably required to effect the change of ownership. A DEKRA and/or roadworthy certificate is provided where applicable.</p>
      <p><strong>6. Warranty.</strong> Any mechanical or electronic warranty applies only to the extent expressly stated on this invoice. Save as required by law, no further warranties are given or implied.</p>
      <p><strong>7. Delivery.</strong> The vehicle is handed over or delivered once the full purchase price reflects. Delivery arrangements are as agreed in writing between the parties.</p>
      <p><strong>8. Refunds.</strong> Where a refund is due, a reasonable administration and bank charge may be deducted. Refunds are processed to the original paying account.</p>
      <p><strong>9. Governing Law.</strong> This agreement and any dispute arising from it are governed by and construed in accordance with the laws of the Republic of South Africa.</p>
    </div>
    <div class="sign">
      <div>
        <div class="sig-line"></div>
        <div class="sig-lbl">Authorised Signature &nbsp;&middot;&nbsp; <?= d(COMPANY_TRADING_AS) ?></div>
      </div>
      <div class="thanks">
        Thank you for your business.<br><b><?= d(COMPANY_TRADING_AS) ?></b> &mdash; auctionhubgroup.co.za
      </div>
    </div>
  </div>

</div><!-- /sheet -->

<!-- Edit panel (screen only) -->
<div class="ovl" id="ovl" onclick="closeEdit()"></div>
<div class="epanel" id="epanel">
  <div class="ep-head">
    <h3>&#9998; Edit Invoice</h3>
    <button class="ep-close" onclick="closeEdit()">&times;</button>
  </div>
  <div class="ep-body">
    <div class="ep-sect">Invoice</div>
    <div class="eg"><label>Invoice Number</label><input type="text" id="e-inv-no" oninput="setTxt('d-inv-no',this.value)"></div>
    <div class="eg"><label>Invoice Date</label><input type="text" id="e-inv-date" oninput="setTxt('d-inv-date',this.value)"></div>
    <div class="eg"><label>Due Date</label><input type="text" id="e-due-date" oninput="setTxt('d-due-date',this.value)"></div>
    <div class="ep-sect">Buyer</div>
    <div class="eg"><label>Name</label><input type="text" id="e-buyer-name" oninput="setTxt('d-buyer-name',this.value)"></div>
    <div class="eg"><label>ID / Passport</label><input type="text" id="e-buyer-id" oninput="setTxt('d-buyer-id',this.value)"></div>
    <div class="eg"><label>Address</label><textarea id="e-buyer-addr" rows="2" oninput="setTxt('d-buyer-addr',this.value)"></textarea></div>
    <div class="eg"><label>Phone</label><input type="text" id="e-buyer-phone" oninput="setTxt('d-buyer-phone',this.value)"></div>
    <div class="eg"><label>Email</label><input type="text" id="e-buyer-email" oninput="setTxt('d-buyer-email',this.value)"></div>
    <div class="ep-sect">Vehicle &amp; Price</div>
    <div class="eg"><label>Vehicle Description</label><textarea id="e-veh-desc" rows="2" oninput="setTxt('d-veh-desc',this.value)"></textarea></div>
    <div class="eg"><label>Vehicle Price EXCL. VAT (numbers only)</label><input type="number" id="e-total" step="0.01" value="<?= $priceExcl ?>" oninput="recalc()"></div>
    <div class="eg"><label>Document Handling Fee (numbers only)</label><input type="number" id="e-docfee" step="0.01" value="<?= $docFee ?>" oninput="recalc()"></div>
    <button class="tbtn tbtn-primary" style="width:100%;justify-content:center;background:var(--blue);color:#fff;" onclick="closeEdit()">&#10003; Done</button>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/js/html2canvas.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/jspdf.umd.min.js"></script>
<script>
  var INV_NO = <?= json_encode($inv['invoice_number']) ?>;

  function setTxt(id, val){ var el = document.getElementById(id); if (el) el.textContent = val; }

  function fmt(n){ return 'R' + n.toLocaleString('en-ZA',{minimumFractionDigits:2, maximumFractionDigits:2}); }
  function fmtPlain(n){ return n.toLocaleString('en-ZA',{minimumFractionDigits:2, maximumFractionDigits:2}); }

  var DOC_FEE = <?= json_encode($docFee) ?>;
  function setAll(cls, txt){ document.querySelectorAll(cls).forEach(function(e){ e.textContent = txt; }); }
  function recalc(){
    var excl = parseFloat(document.getElementById('e-total').value) || 0;
    var docEl = document.getElementById('e-docfee');
    var docf = docEl && docEl.value !== '' ? (parseFloat(docEl.value) || 0) : DOC_FEE;
    var vat   = Math.round(excl * 0.15 * 100) / 100;
    var vinc  = Math.round((excl + vat) * 100) / 100;
    var grand = Math.round((vinc + docf) * 100) / 100;
    setAll('.net-display', fmt(excl));
    setAll('.vat-display', fmt(vat));
    setAll('.vehincl-display', fmt(vinc));
    setAll('.doc-display', fmt(docf));
    setAll('.gross-display', fmtPlain(grand));
    var row = document.getElementById('docfee-row');
    if (row) row.style.display = docf > 0 ? '' : 'none';
  }

  function openEdit(){
    var g = function(id){ return document.getElementById(id); };
    var t = function(id){ var e=g(id); return e ? e.textContent.trim() : ''; };
    g('e-inv-no').value=t('d-inv-no'); g('e-inv-date').value=t('d-inv-date'); g('e-due-date').value=t('d-due-date');
    g('e-buyer-name').value=t('d-buyer-name'); g('e-buyer-id').value=t('d-buyer-id');
    g('e-buyer-addr').value=t('d-buyer-addr'); g('e-buyer-phone').value=t('d-buyer-phone');
    g('e-buyer-email').value=t('d-buyer-email'); g('e-veh-desc').value=t('d-veh-desc');
    document.getElementById('epanel').classList.add('open');
    document.getElementById('ovl').classList.add('show');
  }
  function closeEdit(){
    document.getElementById('epanel').classList.remove('open');
    document.getElementById('ovl').classList.remove('show');
  }

  function downloadPDF(){
    var btn = document.getElementById('dlBtn');
    var sheet = document.getElementById('sheet');
    // Fall back to browser print if the PDF libraries are unavailable
    if (!window.html2canvas || !window.jspdf || !window.jspdf.jsPDF){ window.print(); return; }
    btn.textContent = 'Generating…'; btn.disabled = true;

    // Force the full desktop A4 layout even on phones, so the PDF always shows
    // every column and the one-page design (bypasses on-screen mobile rules).
    window.html2canvas(sheet, { scale: 2, useCORS: true, backgroundColor: '#ffffff',
                                windowWidth: 1200, windowHeight: Math.max(1200, sheet.scrollHeight + 80) })
      .then(function(canvas){
        var jsPDF = window.jspdf.jsPDF;
        var pdf   = new jsPDF('p', 'mm', 'a4');
        var pageW = pdf.internal.pageSize.getWidth();   // 210
        var pageH = pdf.internal.pageSize.getHeight();  // 297

        // Scale the whole sheet to fit inside ONE A4 page (preserve aspect ratio)
        var ratio = Math.min(pageW / canvas.width, pageH / canvas.height);
        var w = canvas.width  * ratio;
        var h = canvas.height * ratio;
        var x = (pageW - w) / 2;   // centre horizontally

        pdf.addImage(canvas.toDataURL('image/jpeg', 0.98), 'JPEG', x, 0, w, h);
        pdf.save('Tax-Invoice-' + INV_NO + '.pdf');
        btn.innerHTML = '&#11015; Download PDF'; btn.disabled = false;
      })
      .catch(function(){
        btn.innerHTML = '&#11015; Download PDF'; btn.disabled = false; window.print();
      });
  }

  if (new URLSearchParams(window.location.search).get('download') === '1') {
    window.addEventListener('load', function(){ setTimeout(downloadPDF, 400); });
  }
</script>
</body>
</html>
<?php
exit;
endif;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= d($inv['invoice_number']) ?> — Auction Hub Group</title>
<style>
/* ── Base ── */
* { box-sizing:border-box; margin:0; padding:0; }
html { font-size:11pt; }
body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    color: #1a1a1a;
    background: #f4f4f4;
    line-height: 1.5;
}

/* ── Page wrapper ── */
.page {
    background: #fff;
    max-width: 210mm;
    margin: 0 auto;
    box-shadow: 0 2px 20px rgba(0,0,0,.12);
}
.page-section {
    padding: 0;
    page-break-after: always;
}
.page-section:last-child { page-break-after: auto; }

/* ── Invoice page 1 ── */
.inv-header {
    background: #fff;
    padding: 28px 36px 0;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
}
.inv-logo-box { flex: 0 0 90px; display:flex; align-items:center; justify-content:center; }
.inv-logo-box img { height:80px; width:80px; object-fit:contain; }
.inv-company {
    flex: 1;
    text-align: center;
    padding: 0 16px;
}
.inv-company h1 {
    font-size: 20pt;
    font-weight: 900;
    color: #001F5C;
    letter-spacing: .04em;
    text-transform: uppercase;
    margin-bottom: 2px;
}
.inv-company .trading-as {
    font-size: 8pt; color: #005DDB; font-weight: 600;
    letter-spacing: .06em; text-transform: uppercase; margin-bottom: 4px;
}
.inv-company p { font-size: 9pt; color: #444; line-height: 1.6; }
.inv-company .reg-line {
    font-size: 8pt; color: #666; margin-top: 4px;
    border-top: 1px solid #e0e4f0; padding-top: 4px;
}
.inv-company a { color: #005DDB; text-decoration: none; }
.inv-meta { flex: 0 0 160px; text-align: right; }
.inv-meta-label {
    font-size: 7.5pt; font-weight: 700; color: #666;
    text-transform: uppercase; letter-spacing: .08em;
}
.inv-meta-title {
    font-size: 18pt; font-weight: 900; color: #001F5C;
    text-transform: uppercase; letter-spacing: .1em;
    margin-bottom: 12px;
}
.inv-meta-row { margin-bottom: 6px; }
.inv-meta-row .lbl { font-size: 7.5pt; color: #888; font-weight: 700; text-transform: uppercase; }
.inv-meta-row .val { font-size: 9.5pt; font-weight: 600; color: #1a1a1a; }
.balance-box {
    background: #f7f7f7;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 8px 12px;
    margin-top: 8px;
    text-align: right;
}
.balance-box .lbl2 { font-size: 7.5pt; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: .08em; }
.balance-box .amount { font-size: 14pt; font-weight: 900; color: #1a1a1a; }

.red-bar { height: 5px; background: linear-gradient(90deg, #001F5C, #005DDB, #1E90FF); margin: 20px 0 0; }

/* ── Bill To ── */
.bill-to-section {
    padding: 22px 36px;
    display: flex;
    gap: 24px;
}
.bill-to-box { flex: 1; }
.bill-to-label {
    font-size: 7.5pt; font-weight: 700; color: #888;
    text-transform: uppercase; letter-spacing: .1em;
    margin-bottom: 6px;
}
.bill-to-name { font-size: 13pt; font-weight: 800; color: #1a1a1a; margin-bottom: 4px; }
.bill-to-detail { font-size: 9pt; color: #444; line-height: 1.7; }

/* ── Items table ── */
.items-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}
.items-table thead tr {
    background: linear-gradient(90deg, #001F5C, #002B7F);
}
.items-table thead th {
    color: #fff;
    font-size: 8pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    padding: 10px 16px;
    text-align: left;
}
.items-table thead th:last-child,
.items-table thead th:nth-child(2),
.items-table thead th:nth-child(3) { text-align: right; }
.items-table tbody td {
    padding: 14px 16px;
    font-size: 9.5pt;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: top;
}
.items-table .item-name { font-weight: 700; font-size: 10pt; margin-bottom: 6px; }
.items-table .item-spec { font-size: 8.5pt; color: #444; line-height: 1.8; }
.items-table .item-feature {
    font-size: 8.5pt; color: #333; line-height: 1.9;
    margin-top: 6px;
}
.items-table .item-feature .fi { font-size: 8pt; }
.items-table td.rate, .items-table td.qty, .items-table td.amount { text-align: right; }
.items-table td.amount { font-weight: 700; font-size: 10pt; }

.bottom-line { height: 1px; background: #e0e0e0; margin: 0 36px; }

/* ── Page 2: Payment + Agreement ── */
.payment-section {
    padding: 28px 36px;
    display: flex;
    gap: 32px;
    align-items: flex-start;
}
.payment-left { flex: 1; }
.payment-right { flex: 0 0 220px; }

.payment-title {
    font-size: 13pt; font-weight: 800; color: #1a1a1a;
    margin-bottom: 14px; text-transform: uppercase; letter-spacing: .04em;
}
.payment-instructions { font-size: 8.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #333; margin-bottom: 8px; }
.payment-line { font-size: 9.5pt; color: #1a1a1a; margin-bottom: 4px; font-weight: 600; }
.bank-info { font-size: 9.5pt; color: #1a1a1a; line-height: 2; margin: 14px 0; font-weight: 600; }
.payment-note { font-size: 8pt; color: #333; line-height: 1.7; margin-bottom: 8px; text-transform: uppercase; font-weight: 600; }

/* Total panel */
.total-panel { text-align: right; }
.total-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 9.5pt; }
.total-row.final { border-top: 2px solid #1a1a1a; margin-top: 4px; padding-top: 8px; }
.total-row.final .lbl3 { font-size: 9pt; font-weight: 700; color: #555; text-transform: uppercase; }
.total-row.final .val3 { font-size: 14pt; font-weight: 900; color: #1a1a1a; }

/* Signature */
.sig-area { margin-top: 20px; }
.sig-line { border-top: 1.5px solid #1a1a1a; width: 160px; margin: 30px 0 4px; }
.sig-label { font-size: 8pt; color: #555; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }

/* Validity badge */
.validity-bar {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    padding: 8px 14px;
    font-size: 8.5pt;
    color: #856404;
    font-weight: 700;
    margin: 12px 0;
    text-align: center;
}
<?php if ($isPreAuction && $inv['status'] === 'approved'): ?>
.validity-bar { background: #d4edda; border-color: #28a745; color: #155724; }
<?php endif; ?>

/* ── Agreement page ── */
.agreement-section {
    padding: 28px 36px;
}
.agreement-divider { height: 1px; background: #e0e0e0; margin: 20px 0; }
.agreement-heading {
    font-size: 10pt; font-weight: 800; color: #1a1a1a;
    text-transform: uppercase; letter-spacing: .06em;
    margin-bottom: 10px;
}
.agreement-text {
    font-size: 8.5pt; color: #333; line-height: 1.85;
    margin-bottom: 10px;
}
.agreement-clause {
    display: flex;
    gap: 10px;
    margin: 14px 0;
}
.clause-check { color: #1a1a1a; font-size: 9pt; flex-shrink: 0; margin-top: 1px; }
.clause-title { font-size: 9pt; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 5px; }
.clause-body { font-size: 8.5pt; color: #333; line-height: 1.8; }
.clause-body p { margin-bottom: 5px; }

/* ── Vehicle photo page ── */
.photo-section { padding: 28px 36px; }
.photo-box { display: flex; gap: 24px; }
.vehicle-img { width: 220px; height: 165px; object-fit: cover; border-radius: 6px; border: 1px solid #e0e0e0; }
.vehicle-img-placeholder {
    width: 220px; height: 165px; border-radius: 6px;
    border: 2px dashed #ccc; display: flex; align-items: center; justify-content: center;
    color: #bbb; font-size: 9pt;
}
.photo-specs { flex: 1; }

/* ── 4-photo grid (user-selected) ── */
.photo-grid-section { padding: 20px 36px 28px; }
.photo-grid-title { font-size: 10pt; font-weight: 800; color: #001F5C; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 14px; border-bottom: 2px solid #001F5C; padding-bottom: 6px; }
.photo-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.photo-grid-img {
    width: 100%;
    aspect-ratio: 4/3;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #dce6f0;
    display: block;
}
.photo-grid-slot { position: relative; }
.photo-grid-slot-label { font-size: 7pt; color: #888; text-align: center; margin-top: 3px; }

/* ── Edit panel (screen only) ── */
.edit-panel {
    position: fixed;
    right: 0; top: 0;
    height: 100vh;
    width: min(360px, 100vw);
    background: #fff;
    border-left: 2px solid #dce6f0;
    box-shadow: -6px 0 32px rgba(0,31,92,0.15);
    z-index: 500;
    overflow-y: auto;
    transform: translateX(100%);
    transition: transform .28s cubic-bezier(.4,0,.2,1);
    display: flex;
    flex-direction: column;
}
.edit-panel.open { transform: translateX(0); }
.edit-panel-header {
    background: linear-gradient(90deg,#001F5C,#005DDB);
    color: #fff;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 1;
}
.edit-panel-header h3 { font-size: .95rem; font-weight: 700; margin: 0; }
.edit-panel-close { background: none; border: none; color: #fff; font-size: 1.4rem; cursor: pointer; padding: 0 4px; line-height: 1; }
.edit-panel-body { padding: 16px 18px; flex: 1; }
.edit-group { margin-bottom: 14px; }
.edit-group label { display: block; font-size: .72rem; font-weight: 700; color: #4a6080; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }
.edit-group input,
.edit-group textarea {
    width: 100%;
    padding: 7px 10px;
    border: 1.5px solid #c8d8ea;
    border-radius: 6px;
    font-size: .85rem;
    color: #1a2a3a;
    font-family: inherit;
    background: #f7f9fc;
    transition: border-color .15s;
}
.edit-group input:focus,
.edit-group textarea:focus { outline: none; border-color: #005DDB; background: #fff; }
.edit-group textarea { resize: vertical; min-height: 62px; }
.edit-section-title { font-size: .8rem; font-weight: 800; color: #001F5C; text-transform: uppercase; letter-spacing: .08em; margin: 18px 0 10px; border-bottom: 1.5px solid #e8eef5; padding-bottom: 5px; }

/* ── Image picker panel (screen only) ── */
.img-picker-panel {
    background: #f4f8ff;
    border: 1px solid #dce6f0;
    border-radius: 10px;
    padding: 14px;
    margin: 0 18px 16px;
}
.img-picker-title { font-size: .78rem; font-weight: 700; color: #001F5C; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 10px; }
.img-picker-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.img-slot {
    position: relative;
    aspect-ratio: 4/3;
    background: #fff;
    border: 2px dashed #c8d8ea;
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: border-color .15s;
}
.img-slot:hover { border-color: #005DDB; }
.img-slot img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.img-slot input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
.img-slot-placeholder { text-align: center; color: #8fa3b8; font-size: .72rem; pointer-events: none; }
.img-slot-placeholder svg { display: block; margin: 0 auto 4px; }
.img-slot-clear { position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,.55); color: #fff; border: none; border-radius: 50%; width: 20px; height: 20px; font-size: .7rem; cursor: pointer; display: none; align-items: center; justify-content: center; z-index: 2; }
.img-slot.has-img .img-slot-clear { display: flex; }

/* ── Mobile responsive ── */
@media screen and (max-width: 640px) {
    html { font-size: 9pt; }
    .page { max-width: 100% !important; }
    .inv-header { flex-direction: column; align-items: center; text-align: center; gap: 10px; padding: 14px 16px 0; }
    .inv-logo-box { flex: none; }
    .inv-meta { flex: none; width: 100%; text-align: left; }
    .inv-company { padding: 0; }
    .bill-to-section { flex-direction: column; padding: 14px 16px; gap: 14px; }
    .payment-section { flex-direction: column; padding: 14px 16px; gap: 16px; }
    .payment-right { flex: none; width: 100%; }
    .agreement-section { padding: 14px 16px; }
    .photo-section { padding: 14px 16px; }
    .photo-box { flex-direction: column; gap: 12px; }
    .vehicle-img { width: 100%; height: auto; max-height: 220px; }
    .photo-grid-section { padding: 14px 16px 20px; }
    .photo-grid { gap: 8px; }
    .items-table thead th:nth-child(2),
    .items-table thead th:nth-child(3),
    .items-table tbody td:nth-child(2),
    .items-table tbody td:nth-child(3) { display: none; }
    .items-table thead th,
    .items-table tbody td { padding: 8px 10px; }
    .bottom-line { margin: 0 16px; }
    .inv-meta-title { font-size: 14pt; }
}

/* ── Buyer code box ── */
.buyer-code-box {
    background: #003f73;
    color: #fff;
    border-radius: 6px;
    padding: 14px 20px;
    margin: 14px 0;
    text-align: center;
}
.buyer-code-box .lbl { font-size: 8pt; text-transform: uppercase; letter-spacing: .1em; opacity: .75; margin-bottom: 4px; }
.buyer-code-box .code { font-size: 18pt; font-weight: 900; letter-spacing: .12em; }

/* ── Print bar (screen only) ── */
.print-bar {
    background: #003f73;
    color: #fff;
    padding: 12px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 100;
    gap: 12px;
    flex-wrap: wrap;
}
.print-bar h2 { font-size: 1rem; margin: 0; font-weight: 700; }
.print-btn {
    padding: .5rem 1.25rem;
    background: #fff;
    color: #003f73;
    border: none;
    border-radius: 5px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
}
.print-btn:hover { background: #e8f0fa; }

/* ── Print media ── */
@media print {
    body { background: #fff; font-size: 10pt; }
    .page { box-shadow: none; max-width: none; }
    .print-bar, .edit-panel, .edit-overlay { display: none !important; }
    .page-section { page-break-after: always; }
    .page-section:last-child { page-break-after: auto; }
    #photo-grid-page { page-break-before: always; }
}

@media screen {
    .page { margin: 0 auto 40px; }
}

/* ── Print bar buttons ── */
.bar-btn {
    padding: .45rem 1rem;
    border-radius: 5px;
    font-weight: 700;
    font-size: .85rem;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    white-space: nowrap;
}
.bar-btn-white { background: #fff; color: #001F5C; }
.bar-btn-white:hover { background: #e8f0fa; }
.bar-btn-outline { background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.35); }
.bar-btn-outline:hover { background: rgba(255,255,255,.22); }
.edit-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.35);
    z-index: 490;
    display: none;
}
.edit-overlay.show { display: block; }
</style>
</head>
<body>

<!-- ── Print Bar (screen only) ── -->
<div class="print-bar" style="background:linear-gradient(90deg,#001F5C,#003580);padding:10px 16px;">
    <div style="min-width:0;">
        <h2 id="bar-inv-no" style="font-size:.95rem;margin:0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            &#128197; Invoice <?= d($inv['invoice_number']) ?>
        </h2>
        <span id="bar-buyer-summary" style="font-size:.74rem;opacity:.75;"><?= d($inv['buyer_name']) ?> &mdash; <?= rands2((float)$inv['total_price']) ?></span>
    </div>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;">
        <?php if ($isPreAuction): ?>
        <a href="invoice_generator.php?tab=preauction" class="bar-btn bar-btn-outline">&#8592; Back</a>
        <?php endif; ?>
        <button class="bar-btn bar-btn-outline" onclick="toggleEdit()" title="Edit invoice fields">
            &#9998; Edit
        </button>
        <button class="bar-btn bar-btn-outline" onclick="togglePhotos()" title="Add vehicle photos">
            &#128247; Photos
        </button>
        <button class="bar-btn bar-btn-white" onclick="window.print()">&#128438; Print / PDF</button>
    </div>
</div>

<!-- Edit overlay backdrop -->
<div class="edit-overlay" id="editOverlay" onclick="closeEdit()"></div>

<!-- ── Edit Panel (screen only) ── -->
<div class="edit-panel" id="editPanel">
    <div class="edit-panel-header">
        <h3>&#9998; Edit Invoice Fields</h3>
        <button class="edit-panel-close" onclick="closeEdit()" title="Close">&times;</button>
    </div>
    <div class="edit-panel-body">
        <div class="edit-section-title">Invoice Details</div>
        <div class="edit-group">
            <label>Invoice Number</label>
            <input type="text" id="ei-inv-no" oninput="liveUpdate('inv-no-display',this.value);liveUpdate('bar-inv-no','&#128197; '+this.value);">
        </div>
        <div class="edit-group">
            <label>Invoice Date</label>
            <input type="text" id="ei-inv-date" oninput="liveUpdate('inv-date-display',this.value);">
        </div>
        <div class="edit-group">
            <label>Due Date</label>
            <input type="text" id="ei-due-date" oninput="liveUpdate('inv-due-display',this.value);">
        </div>

        <div class="edit-section-title">Buyer Details</div>
        <div class="edit-group">
            <label>Buyer Name</label>
            <input type="text" id="ei-buyer-name" oninput="liveUpdate('buyer-name-display',this.value);updateBarSummary();">
        </div>
        <div class="edit-group">
            <label>ID / Passport No</label>
            <input type="text" id="ei-buyer-id" oninput="liveUpdate('buyer-id-display',this.value);">
        </div>
        <div class="edit-group">
            <label>Address</label>
            <textarea id="ei-buyer-addr" rows="2" oninput="liveUpdate('buyer-addr-display',this.value);"></textarea>
        </div>
        <div class="edit-group">
            <label>Phone</label>
            <input type="text" id="ei-buyer-phone" oninput="liveUpdate('buyer-phone-display',this.value);">
        </div>
        <div class="edit-group">
            <label>Email</label>
            <input type="text" id="ei-buyer-email" oninput="liveUpdate('buyer-email-display',this.value);">
        </div>

        <div class="edit-section-title">Vehicle</div>
        <div class="edit-group">
            <label>Vehicle Description</label>
            <textarea id="ei-veh-desc" rows="2" oninput="liveUpdateAll('veh-desc-display',this.value);"></textarea>
        </div>
        <div class="edit-group">
            <label>Total Price (numbers only)</label>
            <input type="number" id="ei-total" oninput="updatePrice(this.value);">
        </div>

        <div style="margin-top:18px;">
            <button class="bar-btn bar-btn-white" onclick="closeEdit();window.print();" style="width:100%;justify-content:center;background:#005DDB;color:#fff;">
                &#128438; Save &amp; Print
            </button>
        </div>
    </div>
</div>

<div class="page">

<!-- ════════════════════════════════════════════════════════
     PAGE 1 — INVOICE
════════════════════════════════════════════════════════ -->
<div class="page-section">

    <!-- Header -->
    <div class="inv-header">
        <!-- Company logo -->
        <div class="inv-logo-box">
            <img src="<?= APP_URL ?>/logo-round.png"
                 alt="Auction Hub Group"
                 style="height:80px;width:80px;object-fit:contain;">
        </div>

        <!-- Company info -->
        <div class="inv-company">
            <h1><?= d(COMPANY_TRADING_AS) ?></h1>
            <div class="trading-as">Registered as: <?= d(COMPANY_REGISTERED_NAME) ?> (Pty) Ltd</div>
            <p>
                <?= d(COMPANY_ADDRESS) ?><br>
                <strong><?= d(COMPANY_PHONE) ?></strong> &nbsp;|&nbsp;
                <a href="mailto:<?= d(COMPANY_EMAIL) ?>"><?= d(COMPANY_EMAIL) ?></a><br>
                <a href="https://auctionhubgroup.co.za">auctionhubgroup.co.za</a>
            </p>
            <div class="reg-line">
                Reg: <?= d(COMPANY_REG_NO) ?> &nbsp;|&nbsp; SARS Tax No: <?= d(COMPANY_SARS_NO) ?> &nbsp;|&nbsp; Reg Date: <?= d(COMPANY_REG_DATE) ?>
            </div>
        </div>

        <!-- Invoice meta -->
        <div class="inv-meta">
            <div class="inv-meta-title">Invoice</div>
            <div class="inv-meta-row">
                <div class="inv-meta-label">Invoice No.</div>
                <div id="inv-no-display" class="val" style="font-size:9pt;font-weight:700;"><?= d($inv['invoice_number']) ?></div>
            </div>
            <div class="inv-meta-row">
                <div class="inv-meta-label">Date</div>
                <div id="inv-date-display" class="val"><?= d($invoiceDate) ?></div>
            </div>
            <div class="inv-meta-row">
                <div class="inv-meta-label">Due Date</div>
                <div id="inv-due-display" class="val"><?= d($dueDate) ?></div>
            </div>
            <div class="balance-box">
                <div class="lbl2">Balance Due</div>
                <div class="amount">ZAR <span class="price-display"><?= rands2((float)$inv['total_price']) ?></span></div>
            </div>
        </div>
    </div>

    <div class="red-bar"></div>

    <!-- Bill To -->
    <div class="bill-to-section">
        <div class="bill-to-box">
            <div class="bill-to-label">Bill To</div>
            <div id="buyer-name-display" class="bill-to-name"><?= d($inv['buyer_name']) ?></div>
            <div class="bill-to-detail">
                <?php if ($inv['buyer_id_passport']): ?>
                    Passport / ID No: <span id="buyer-id-display"><?= d($inv['buyer_id_passport']) ?></span><br>
                <?php endif; ?>
                <?php if ($inv['buyer_address']): ?>
                    <span id="buyer-addr-display"><?= nl2br(d($inv['buyer_address'])) ?></span><br>
                <?php endif; ?>
                <?php if ($inv['buyer_phone'] || true): ?>
                    &#9990; <span id="buyer-phone-display"><?= d($inv['buyer_phone']) ?></span><br>
                <?php endif; ?>
                <?php if ($inv['buyer_email'] || true): ?>
                    <span id="buyer-email-display"><?= d($inv['buyer_email']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isPreAuction): ?>
        <div style="text-align:right;">
            <?php if ($inv['status'] === 'approved' && $inv['buyer_code']): ?>
            <div class="buyer-code-box" style="width:200px;">
                <div class="lbl">Buyer Code</div>
                <div class="code"><?= d($inv['buyer_code']) ?></div>
            </div>
            <?php else: ?>
            <div class="validity-bar" style="width:200px;">
                &#9200; Invoice valid until:<br>
                <strong><?= d($expiresAt) ?></strong><br>
                <span style="font-size:7.5pt;font-weight:400;">(24-hour validity)</span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Items table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:60%">Description</th>
                <th style="width:15%;text-align:right;">Rate</th>
                <th style="width:8%;text-align:right;">Qty</th>
                <th style="width:17%;text-align:right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div id="veh-desc-display" class="item-name"><?= d($inv['vehicle_description']) ?></div>
                    <div class="item-spec">
                        <?php if ($inv['vehicle_year']):  ?>Year: <?= d($inv['vehicle_year']) ?><br><?php endif; ?>
                        <?php if ($inv['vehicle_mileage']): ?>Mileage: <?= d($inv['vehicle_mileage']) ?><br><?php endif; ?>
                        <?php if ($inv['vehicle_transmission']): ?>Transmission: <?= d($inv['vehicle_transmission']) ?><br><?php endif; ?>
                        <?php if ($inv['vehicle_service_history']): ?>Service History: <?= d($inv['vehicle_service_history']) ?><br><?php endif; ?>
                        <?php if ($inv['vehicle_condition']): ?>Condition: <?= d($inv['vehicle_condition']) ?><br><?php endif; ?>
                        <?php if ($inv['vehicle_body']): ?>Body: <?= d($inv['vehicle_body']) ?><br><?php endif; ?>
                        <?php if ($inv['vehicle_colour']): ?>Colour: <?= d($inv['vehicle_colour']) ?><br><?php endif; ?>
                        <?php if ($inv['vehicle_fuel']): ?>Fuel: <?= d($inv['vehicle_fuel']) ?><?php endif; ?>
                    </div>
                    <?php if ($features): ?>
                    <div class="item-feature">
                        <?php foreach ($features as $f): ?>
                        &rsaquo; <?= d($f) ?><br>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="rate"><span class="price-display"><?= rands2((float)$inv['total_price']) ?></span></td>
                <td class="qty">1</td>
                <td class="amount"><span class="price-display"><?= rands2((float)$inv['total_price']) ?></span></td>
            </tr>
        </tbody>
    </table>

    <div class="bottom-line"></div>

    <!-- Subtotal row -->
    <div style="padding:12px 36px 24px;text-align:right;">
        <table style="margin-left:auto;border-collapse:collapse;width:200px;">
            <tr>
                <td style="font-size:9pt;color:#555;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:4px 0;">Subtotal</td>
                <td style="font-size:9.5pt;font-weight:600;text-align:right;padding:4px 0;"><span class="price-display"><?= rands2((float)$inv['total_price']) ?></span></td>
            </tr>
            <tr style="border-top:2px solid #1a1a1a;">
                <td style="font-size:9pt;color:#1a1a1a;font-weight:800;text-transform:uppercase;padding:6px 0;">Balance Due</td>
                <td style="font-size:12pt;font-weight:900;text-align:right;padding:6px 0;">ZAR <span class="price-display"><?= rands2((float)$inv['total_price']) ?></span></td>
            </tr>
        </table>
    </div>

</div><!-- /page 1 -->


<!-- ════════════════════════════════════════════════════════
     PAGE 2 — PAYMENT INFO + SALES AGREEMENT
════════════════════════════════════════════════════════ -->
<div class="page-section">

    <div style="padding:0 36px;background:#a30000;height:5px;"></div>

    <!-- Payment + Totals -->
    <div class="payment-section">

        <!-- Left: payment instructions -->
        <div class="payment-left">
            <div class="payment-title">Payment Info</div>
            <div class="payment-instructions">Payment Instructions</div>

            <?php if ($isPreAuction): ?>
            <div class="payment-line">COMMITMENT FEE DUE: <?= rands2((float)$inv['commitment_fee_amount']) ?> (<?= $inv['commitment_fee_pct'] ?>%)</div>
            <div class="payment-line">REMAINING BALANCE: <?= rands2((float)$inv['outstanding_amount']) ?></div>
            <?php else: ?>
            <div class="payment-line">TOTAL DUE: <?= rands2((float)$inv['total_price']) ?></div>
            <?php endif; ?>

            <div class="bank-info" style="margin-top:12px;">
                <?= d(COMPANY_NAME) ?><br>
                <?= d($bank['bank_name']) ?> ACCOUNT<br>
                <?= d($bank['bank_account_no']) ?><br>
                BRANCH CODE: <?= d($bank['bank_branch_code']) ?><br>
                ACCOUNT TYPE: <?= d($bank['bank_account_type']) ?>
            </div>

            <div class="payment-note">
                KINDLY USE YOUR NAME AND SURNAME AS REFERENCE AND SEND PROOF OF PAYMENT (P.O.P) ONCE YOU ARE DONE WITH YOUR PAYMENT.
            </div>
            <div class="payment-note">
                N.B ALL FUNDS ARE 100% REFUNDABLE EXCLUDING R102.00 FOR OUR ADMINISTRATION AND BANK CHARGES.
            </div>
            <div class="payment-note">
                THIS IS A LEGALLY BINDING DOCUMENT WHICH IS AN AGREEMENT BETWEEN THE BUYER (CLIENT) AND SELLER (<?= strtoupper(d($sellerName)) ?>).
            </div>
            <div class="payment-note">KINDLY ENSURE ALL DETAILS ARE STIPULATED CORRECTLY.</div>

            <!-- Signature area -->
            <div class="sig-area">
                <div class="sig-line"></div>
                <div class="sig-label">Authorised Signature</div>
                <div style="margin-top:14px;">
                    <span class="sig-label">Date Signed:</span>
                    <span style="font-size:9pt;font-weight:600;margin-left:8px;"><?= d($dateSigned) ?></span>
                </div>
            </div>
        </div>

        <!-- Right: totals panel -->
        <div class="payment-right">
            <table style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="font-size:9pt;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#555;padding:5px 0;">Total</td>
                    <td style="font-size:9.5pt;font-weight:600;text-align:right;padding:5px 0;"><span class="price-display"><?= rands2((float)$inv['total_price']) ?></span></td>
                </tr>
                <?php if ($isPreAuction): ?>
                <tr>
                    <td style="font-size:9pt;font-weight:700;color:#a30000;padding:5px 0;">Commitment Fee</td>
                    <td style="font-size:9.5pt;font-weight:600;text-align:right;color:#a30000;padding:5px 0;">-<?= rands2((float)$inv['commitment_fee_amount']) ?></td>
                </tr>
                <tr style="border-top:1px solid #ccc;">
                    <td style="font-size:9pt;font-weight:700;color:#003f73;padding:5px 0;">Outstanding</td>
                    <td style="font-size:9.5pt;font-weight:700;text-align:right;color:#003f73;padding:5px 0;"><?= rands2((float)$inv['outstanding_amount']) ?></td>
                </tr>
                <?php endif; ?>
                <tr style="border-top:2.5px solid #1a1a1a;">
                    <td style="font-size:9pt;font-weight:800;text-transform:uppercase;padding:7px 0;">Balance Due</td>
                    <td style="font-size:13pt;font-weight:900;text-align:right;padding:7px 0;">ZAR <span class="price-display"><?= rands2((float)$inv['total_price']) ?></span></td>
                </tr>
            </table>

            <!-- Validity -->
            <?php if ($isPreAuction && $inv['status'] !== 'approved'): ?>
            <div class="validity-bar" style="margin-top:18px;">
                &#9200; Valid Until: <strong><?= d($expiresAt) ?></strong>
            </div>
            <?php endif; ?>

            <?php if ($isPreAuction && $inv['status'] === 'approved' && $inv['buyer_code']): ?>
            <div class="buyer-code-box" style="margin-top:18px;">
                <div class="lbl">Your Buyer Code</div>
                <div class="code"><?= d($inv['buyer_code']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="agreement-divider"></div>

    <!-- Sales Agreement -->
    <div class="agreement-section">
        <div class="agreement-heading">
            Sales Agreement for the Sale of a Motor Vehicle at <?= d($sellerName) ?>
        </div>

        <div class="agreement-text">
            <?= d($sellerName) ?> (Status: Owner) — Entered into by and between (herein referred to as <strong>"The Seller"</strong>) and you
            <strong><?= d($buyerName) ?></strong> (herein referred to as <strong>"The Purchaser"</strong>).
        </div>

        <div class="agreement-text">
            It is recorded that all registration and licensing fees payable in connection with the vehicle until the date of signing of this agreement are fully paid.
            The vehicle is not a stolen vehicle. The vehicle is roadworthy on the date of signature of this agreement, and the relevant authorities will issue a Roadworthy Certificate or Dekra Certificate.
        </div>

        <div class="agreement-text" style="font-weight:600;">
            <?= d($vehicleDesc) ?>
            <?php if ($inv['vehicle_year']): ?>&nbsp;| Year: <?= d($inv['vehicle_year']) ?><?php endif; ?>
            <?php if ($inv['vehicle_mileage']): ?>&nbsp;| Mileage: <?= d($inv['vehicle_mileage']) ?><?php endif; ?>
        </div>

        <?php if ($isPreAuction): ?>
        <div class="agreement-text" style="background:#fff8e1;border:1px solid #ffc107;border-radius:4px;padding:8px 12px;color:#856404;font-size:8pt;">
            <strong>NB.</strong> The price is only valid within the 24-hour period specified. This is a pre-auction deal for a vehicle in high demand. The commitment fee of
            <?= rands2((float)$inv['commitment_fee_amount']) ?> (<?= $inv['commitment_fee_pct'] ?>%) must be paid within the validity window.
        </div>
        <?php endif; ?>

        <?php if ($features): ?>
        <div class="agreement-text">
            <?php foreach ($features as $f): ?>
            &rsaquo;&nbsp;<?= d($f) ?><br>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Clauses -->
        <?php if ($isPreAuction): ?>
        <div class="agreement-text" style="margin-top:8px;">
            The vehicle comes with a Dekra Certificate or Roadworthy Certificate and a Mechanical and Electronic Warranty.
            The buyer acknowledges that the vehicle is used and will be test driven &amp; inspected for 1 hour 30 minutes once the commitment fee is paid.
        </div>
        <?php endif; ?>

        <div class="agreement-clause">
            <div class="clause-check">&#10003;</div>
            <div>
                <div class="clause-title">Delivery</div>
                <div class="clause-body">
                    <p>(a) The buyer acknowledges that after test drive &amp; inspection the seller will hand over possession of the vehicle to the buyer once the full purchase price has been paid and reflects in the seller's bank account. Free delivery for South African citizens only.</p>
                </div>
            </div>
        </div>

        <div class="agreement-clause">
            <div class="clause-check">&#10003;</div>
            <div>
                <div class="clause-title">Total Asking Price: <?= rands(floatval($inv['total_price'])) ?></div>
                <div class="clause-body">
                    <p>(a) The purchase price payable by the purchaser to the seller is the sum of <?= rands2((float)$inv['total_price']) ?> at no additional fees or hidden costs.</p>
                    <?php if ($isPreAuction): ?>
                    <p>(b) The purchaser shall pay a commitment fee of <?= $inv['commitment_fee_pct'] ?>% (<?= rands2((float)$inv['commitment_fee_amount']) ?>) to secure the buy-now price before the vehicle is moved to auction.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="agreement-clause">
            <div class="clause-check">&#10003;</div>
            <div>
                <div class="clause-title">Registration</div>
                <div class="clause-body">
                    <p>(a) The seller will pay all costs involved in the change of ownership of the vehicle as well as the costs to obtain a roadworthy certificate.</p>
                    <p>(b) The seller will sign and provide all necessary documents to enable the buyer to register the vehicle in the buyer's credentials.</p>
                </div>
            </div>
        </div>

        <div class="agreement-clause">
            <div class="clause-check">&#10003;</div>
            <div>
                <div class="clause-title">Warranties and Voetstoots Sale</div>
                <div class="clause-body">
                    <p>(a) The seller sells the vehicle voetstoots, and subject to what is set out below, as is, we offer 06–12 months mechanical &amp; electronic warranty.</p>
                    <p>(b) The seller warrants full right and authority to sell and transfer the vehicle and indemnifies the purchaser against any related claims.</p>
                    <p>(c) The seller will sign change-of-ownership documentation to enable the purchaser to transfer the vehicle into his/her name against payment of the purchase price.</p>
                    <p>(d) The seller warrants that the vehicle is roadworthy. However, the buyer is responsible for having their own mechanic or engineer verify the vehicle's condition.</p>
                </div>
            </div>
        </div>

        <div class="agreement-clause">
            <div class="clause-check">&#10003;</div>
            <div>
                <div class="clause-title">Insurance</div>
                <div class="clause-body">
                    <p>(a) The buyer will, at their own expense, ensure that the vehicle is comprehensively insured with a reputable insurance company. The seller will cancel the current insurance on the vehicle on the day of purchase.</p>
                </div>
            </div>
        </div>

        <div class="agreement-clause">
            <div class="clause-check">&#10003;</div>
            <div>
                <div class="clause-title">Default</div>
                <div class="clause-body">
                    <p>(a) If the buyer fails to pay the commitment fee, any instalment, or balance of payment on the agreed due date, or breaches any terms and conditions, the seller may claim specific performance without prejudice to any other rights it may have.</p>
                </div>
            </div>
        </div>

        <div class="agreement-clause">
            <div class="clause-check">&#10003;</div>
            <div>
                <div class="clause-title">Cancellation</div>
                <div class="clause-body">
                    <p>(a) Should either party breach this agreement and fail to correct the breach within 6 hours of receiving the proforma invoice, the aggrieved party may cancel the agreement by giving written notice.</p>
                </div>
            </div>
        </div>


    </div><!-- /agreement-section -->

</div><!-- /page 2 -->


<?php if ($isPreAuction && !empty($inv['vehicle_image_url'])): ?>
<!-- ════════════════════════════════════════════════════════
     PAGE 3 — VEHICLE PHOTO (pre-auction DB image)
════════════════════════════════════════════════════════ -->
<div class="page-section">
    <div class="photo-section">
        <div class="photo-box">
            <div>
                <img src="<?= d(imgHost($inv['vehicle_image_url'])) ?>" alt="Vehicle" class="vehicle-img"
                     onerror="this.style.display='none'">
            </div>
            <div class="photo-specs">
                <div style="font-size:13pt;font-weight:800;margin-bottom:10px;color:#1a1a1a;">
                    <?= d($inv['vehicle_description']) ?>
                </div>
                <div style="font-size:9pt;line-height:2;color:#444;">
                    <?php if ($inv['vehicle_year']): ?>Year: <?= d($inv['vehicle_year']) ?><br><?php endif; ?>
                    <?php if ($inv['vehicle_mileage']): ?>Mileage: <?= d($inv['vehicle_mileage']) ?><br><?php endif; ?>
                    <?php if ($inv['vehicle_transmission']): ?>Transmission: <?= d($inv['vehicle_transmission']) ?><br><?php endif; ?>
                    <?php if ($inv['vehicle_service_history']): ?>Service History: <?= d($inv['vehicle_service_history']) ?><br><?php endif; ?>
                    <?php if ($inv['vehicle_condition']): ?>Condition: <?= d($inv['vehicle_condition']) ?><br><?php endif; ?>
                    <?php if ($inv['vehicle_body']): ?>Body: <?= d($inv['vehicle_body']) ?><br><?php endif; ?>
                    <?php if ($inv['vehicle_colour']): ?>Colour: <?= d($inv['vehicle_colour']) ?><br><?php endif; ?>
                    <?php if ($inv['vehicle_fuel']): ?>Fuel: <?= d($inv['vehicle_fuel']) ?><br><?php endif; ?>
                </div>
                <?php if ($features): ?>
                <div style="margin-top:10px;font-size:9pt;color:#333;line-height:1.9;">
                    <?php foreach ($features as $f): ?>
                    &rsaquo;&nbsp;<?= d($f) ?><br>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════
     PHOTO GRID PAGE — user-selected images (screen + print)
════════════════════════════════════════════════════════ -->
<div class="page-section" id="photo-grid-page" style="display:none;">
    <div style="height:5px;background:linear-gradient(90deg,#001F5C,#005DDB,#1E90FF);"></div>
    <div class="photo-grid-section">
        <div class="photo-grid-title">Vehicle Photographs</div>
        <div class="photo-grid" id="photo-grid-display">
            <!-- slots filled by JS -->
        </div>
        <div style="margin-top:10px;font-size:8pt;color:#888;text-align:center;">
            Images for reference only. All vehicles sold voetstoots.
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     PHOTO PICKER (screen only, floats above page)
════════════════════════════════════════════════════════ -->
<div id="photosPanel" style="display:none;position:fixed;bottom:1rem;right:1rem;z-index:600;background:#fff;border:2px solid #dce6f0;border-radius:12px;box-shadow:0 8px 32px rgba(0,31,92,0.18);padding:0;width:min(320px,95vw);">
    <div style="background:linear-gradient(90deg,#001F5C,#005DDB);color:#fff;padding:10px 14px;border-radius:10px 10px 0 0;display:flex;justify-content:space-between;align-items:center;">
        <span style="font-weight:700;font-size:.88rem;">&#128247; Vehicle Photos (max 4)</span>
        <button onclick="togglePhotos()" style="background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer;padding:0 2px;">&times;</button>
    </div>
    <div style="padding:12px;">
        <div class="img-picker-grid">
            <?php for ($pi = 1; $pi <= 4; $pi++): ?>
            <div class="img-slot" id="slot-<?= $pi ?>" onclick="document.getElementById('file-<?= $pi ?>').click()">
                <div class="img-slot-placeholder">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#8fa3b8" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                    Photo <?= $pi ?>
                </div>
                <img id="preview-<?= $pi ?>" src="" alt="" style="display:none;">
                <input type="file" id="file-<?= $pi ?>" accept="image/*" onchange="loadPhoto(<?= $pi ?>,this)" onclick="event.stopPropagation()">
                <button class="img-slot-clear" id="clear-<?= $pi ?>" onclick="clearPhoto(<?= $pi ?>,event)" title="Remove">&#10005;</button>
            </div>
            <?php endfor; ?>
        </div>
        <div style="margin-top:10px;display:flex;gap:.5rem;">
            <button onclick="applyPhotos()" style="flex:1;padding:.55rem;background:#005DDB;color:#fff;border:none;border-radius:7px;font-weight:700;font-size:.82rem;cursor:pointer;">&#10003; Add to Invoice</button>
            <button onclick="clearAllPhotos()" style="padding:.55rem .9rem;background:#f4f8ff;color:#4a6080;border:1px solid #dce6f0;border-radius:7px;font-weight:600;font-size:.82rem;cursor:pointer;">Clear All</button>
        </div>
    </div>
</div>

</div><!-- /page wrapper -->

<script>
// ── Auto-print ────────────────────────────────────────────
if (new URLSearchParams(window.location.search).get('print') === '1') window.print();

// ── Edit panel ────────────────────────────────────────────
const photoData = [null, null, null, null]; // 1-indexed, index 0 unused

function toggleEdit() {
    const p = document.getElementById('editPanel');
    const o = document.getElementById('editOverlay');
    const open = p.classList.toggle('open');
    o.classList.toggle('show', open);
    if (open) seedEditFields();
}
function closeEdit() {
    document.getElementById('editPanel').classList.remove('open');
    document.getElementById('editOverlay').classList.remove('show');
}

function seedEditFields() {
    const g = (id) => document.getElementById(id);
    const t = (id) => { const el = g(id); return el ? el.textContent.trim() : ''; };
    g('ei-inv-no')      && (g('ei-inv-no').value      = t('inv-no-display'));
    g('ei-inv-date')    && (g('ei-inv-date').value     = t('inv-date-display'));
    g('ei-due-date')    && (g('ei-due-date').value     = t('inv-due-display'));
    g('ei-buyer-name')  && (g('ei-buyer-name').value   = t('buyer-name-display'));
    g('ei-buyer-id')    && (g('ei-buyer-id').value     = t('buyer-id-display'));
    g('ei-buyer-addr')  && (g('ei-buyer-addr').value   = t('buyer-addr-display'));
    g('ei-buyer-phone') && (g('ei-buyer-phone').value  = t('buyer-phone-display'));
    g('ei-buyer-email') && (g('ei-buyer-email').value  = t('buyer-email-display'));
    g('ei-veh-desc')    && (g('ei-veh-desc').value     = t('veh-desc-display'));
}

function liveUpdate(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}
function liveUpdateAll(cls, val) {
    document.querySelectorAll('#' + cls).forEach(el => el.textContent = val);
}
function updateBarSummary() {
    const name = document.getElementById('ei-buyer-name')?.value || '';
    const sumEl = document.getElementById('bar-buyer-summary');
    if (sumEl) {
        const existing = sumEl.textContent.split('—')[1]?.trim() || '';
        sumEl.textContent = name + ' — ' + existing;
    }
}
function updatePrice(val) {
    const n = parseFloat(val) || 0;
    const fmt = 'R' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    document.querySelectorAll('.price-display').forEach(el => el.textContent = fmt);
    const bar = document.getElementById('bar-buyer-summary');
    if (bar) {
        const name = bar.textContent.split('—')[0].trim();
        bar.textContent = name + ' — ' + fmt;
    }
}

// ── Photo picker ──────────────────────────────────────────
function togglePhotos() {
    const p = document.getElementById('photosPanel');
    p.style.display = p.style.display === 'none' ? 'block' : 'none';
}

function loadPhoto(n, input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        photoData[n] = e.target.result;
        const prev = document.getElementById('preview-' + n);
        prev.src = e.target.result;
        prev.style.display = 'block';
        document.getElementById('slot-' + n).classList.add('has-img');
    };
    reader.readAsDataURL(input.files[0]);
}

function clearPhoto(n, evt) {
    evt.stopPropagation();
    photoData[n] = null;
    const prev = document.getElementById('preview-' + n);
    prev.src = ''; prev.style.display = 'none';
    document.getElementById('file-' + n).value = '';
    document.getElementById('slot-' + n).classList.remove('has-img');
    refreshGrid();
}

function clearAllPhotos() {
    for (let i = 1; i <= 4; i++) clearPhoto(i, { stopPropagation: () => {} });
}

function applyPhotos() {
    refreshGrid();
    document.getElementById('photosPanel').style.display = 'none';
}

function refreshGrid() {
    const imgs = photoData.filter(Boolean);
    const page = document.getElementById('photo-grid-page');
    const grid = document.getElementById('photo-grid-display');
    if (!imgs.length) { page.style.display = 'none'; grid.innerHTML = ''; return; }

    page.style.display = 'block';
    grid.innerHTML = imgs.map((src, i) =>
        `<div class="photo-grid-slot">
            <img class="photo-grid-img" src="${src}" alt="Vehicle photo ${i+1}">
            <div class="photo-grid-slot-label">Photo ${i+1}</div>
        </div>`
    ).join('');
}
</script>
</body>
</html>
