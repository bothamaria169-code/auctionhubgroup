<?php
// ============================================================
// AUCTION HUB GROUP — invoice_pop_upload.php
// Buyer-facing proof-of-payment upload for pre-auction invoices
// Access: ?num=AHG-INV-...&token=XXXXX
// ============================================================
require_once __DIR__ . '/config.php';

$invoiceNum  = trim($_GET['num']   ?? '');
$uploadToken = trim($_GET['token'] ?? '');
$invoice     = null;
$error       = '';
$success     = '';

// ── Validate link ─────────────────────────────────────────
if (!$invoiceNum || !$uploadToken) {
    $error = 'Invalid link. Please contact Auction Hub Group for a valid payment link.';
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM preauction_invoices
            WHERE invoice_number = ? AND upload_token = ?
            LIMIT 1
        ");
        $stmt->execute([$invoiceNum, $uploadToken]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            $error = 'This link is invalid or has been revoked. Please contact us for assistance.';
        } elseif ($invoice['status'] === 'approved') {
            $success = 'Your commitment fee payment has already been approved. Your Buyer Code is <strong>' . htmlspecialchars($invoice['buyer_code'], ENT_QUOTES) . '</strong>. Please check your email for details.';
        } elseif ($invoice['status'] === 'declined') {
            $error = 'This pre-auction commitment has been declined. Please contact Auction Hub Group for more information.';
        } elseif (strtotime($invoice['expires_at']) < time() && $invoice['status'] === 'pending') {
            $error = 'This invoice has expired (24-hour validity window passed). Please contact Auction Hub Group to request a new invoice.';
        }
    } catch (Exception $e) {
        $error = 'System error. Please try again or contact us directly.';
    }
}

// ── Handle POST upload ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invoice && !$error && !$success) {
    if (empty($_FILES['pop_file']['name'])) {
        $error = 'Please select a file to upload.';
    } else {
        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        $result  = handleUpload($_FILES['pop_file'], UPLOAD_POP, $allowed, 'inv_pop');

        if (!$result['success']) {
            $error = $result['message'];
        } else {
            try {
                $upd = $pdo->prepare("
                    UPDATE preauction_invoices
                    SET status = 'pop_uploaded', pop_file_path = ?, pop_uploaded_at = NOW()
                    WHERE id = ?
                ");
                $upd->execute([$result['path'], $invoice['id']]);

                require_once __DIR__ . '/email_helper.php';
                sendPreAuctionPOPAdminAlert($invoice);

                $success = 'Your proof of payment has been uploaded successfully. <strong>Auction Hub Group will review and respond within 24 hours.</strong> You will receive an email with your Buyer Code once approved.';
                // Refresh invoice data
                $stmt = $pdo->prepare("SELECT * FROM preauction_invoices WHERE id = ?");
                $stmt->execute([$invoice['id']]);
                $invoice = $stmt->fetch();
            } catch (Exception $e) {
                $error = 'Could not save your upload. Please try again or contact us directly.';
            }
        }
    }
}

function d2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function rands3(float $n): string { return 'R' . number_format($n, 2, '.', ','); }

$expiresAt   = $invoice ? safeDate('d M Y, H:i', $invoice['expires_at'], '') : '';
$expiresTs   = $invoice && !empty($invoice['expires_at']) ? strtotime($invoice['expires_at']) : 0;
$isExpired   = $invoice ? ($expiresTs > 0 && $expiresTs < time()) : false;
$alreadyUploaded = $invoice && $invoice['status'] === 'pop_uploaded';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upload Proof of Payment — Auction Hub Group</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous">
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Barlow',sans-serif; background:#f0f4f8; min-height:100vh; }
.pop-header {
    background:linear-gradient(135deg,#003f73,#005BA1);
    padding:2rem 1.5rem; text-align:center;
}
.pop-header h1 {
    color:#fff; font-family:'Barlow Condensed',sans-serif;
    font-size:1.8rem; font-weight:800; letter-spacing:.05em;
}
.pop-header p { color:rgba(255,255,255,.8); font-size:.9rem; margin-top:.5rem; }
.pop-wrap {
    max-width:640px; margin:2rem auto; padding:0 1.5rem 4rem;
}
.pop-card {
    background:#fff; border-radius:12px;
    border:1px solid #dce6f0; box-shadow:0 4px 20px rgba(0,0,0,.08);
    overflow:hidden; margin-bottom:1.5rem;
}
.pop-card-header {
    background:#003f73; color:#fff; padding:1rem 1.5rem;
    font-family:'Barlow Condensed',sans-serif; font-size:1.05rem; font-weight:700;
    display:flex; align-items:center; gap:.5rem; letter-spacing:.05em;
}
.pop-card-body { padding:1.5rem; }
.detail-row { display:flex; justify-content:space-between; align-items:flex-start; padding:.5rem 0; border-bottom:1px solid #f0f4f8; font-size:.88rem; }
.detail-row:last-child { border-bottom:none; }
.detail-row .lbl { color:#666; font-weight:600; text-transform:uppercase; font-size:.75rem; letter-spacing:.06em; }
.detail-row .val { color:#1a1a1a; font-weight:700; text-align:right; }
.fee-highlight {
    background:#e8f4fd; border:1.5px solid #b8d4ee; border-radius:8px;
    padding:1rem 1.25rem; margin:1rem 0; text-align:center;
}
.fee-highlight .num { font-size:2rem; font-weight:900; color:#003f73; font-family:'Barlow Condensed',sans-serif; }
.fee-highlight .lbl2 { font-size:.78rem; text-transform:uppercase; letter-spacing:.08em; color:#666; margin-top:.2rem; }
.fee-outstanding {
    background:#fff3cd; border:1.5px solid #ffc107; border-radius:8px;
    padding:.75rem 1rem; text-align:center; margin:.5rem 0;
}
.fee-outstanding .num { font-size:1.4rem; font-weight:800; color:#856404; font-family:'Barlow Condensed',sans-serif; }

/* Upload area */
.upload-zone {
    border:2.5px dashed #b8d4ee; border-radius:10px;
    padding:2.5rem 1.5rem; text-align:center; cursor:pointer;
    background:#fafcff; transition:all .2s; position:relative;
}
.upload-zone:hover, .upload-zone.dragover { border-color:#005BA1; background:#eff7ff; }
.upload-zone i { font-size:2.5rem; color:#b8d4ee; margin-bottom:.75rem; }
.upload-zone.has-file i { color:#005BA1; }
.upload-zone .zone-text { font-size:.9rem; color:#555; }
.upload-zone .zone-sub { font-size:.78rem; color:#999; margin-top:.3rem; }
#pop_file { position:absolute; inset:0; opacity:0; cursor:pointer; }
.file-name-display {
    margin-top:1rem; font-size:.85rem; font-weight:600;
    color:#005BA1; display:none;
}

/* Submit button */
.btn-upload {
    width:100%; padding:1rem; background:#005BA1; color:#fff;
    border:none; border-radius:8px; font-size:1rem; font-weight:700;
    cursor:pointer; font-family:'Barlow',sans-serif; transition:background .2s;
    display:flex; align-items:center; justify-content:center; gap:.5rem;
    margin-top:1.25rem;
}
.btn-upload:hover { background:#004a8a; }
.btn-upload:disabled { background:#aaa; cursor:not-allowed; }

/* Alerts */
.alert { border-radius:8px; padding:1rem 1.25rem; margin-bottom:1.5rem; font-size:.9rem; }
.alert-success { background:#d4edda; border:1.5px solid #c3e6cb; color:#155724; }
.alert-error   { background:#f8d7da; border:1.5px solid #f5c6cb; color:#721c24; }
.alert-info    { background:#d1ecf1; border:1.5px solid #bee5eb; color:#0c5460; }
.alert-warning { background:#fff3cd; border:1.5px solid #ffc107; color:#856404; }
.alert strong { display:block; margin-bottom:.3rem; font-size:.95rem; }

/* Validity */
.validity-bar {
    display:flex; align-items:center; justify-content:center; gap:.5rem;
    background:#fff3cd; border:1px solid #ffc107; border-radius:6px;
    padding:.6rem 1rem; font-size:.82rem; font-weight:600; color:#856404;
    margin-bottom:1.5rem;
}
.validity-bar.expired { background:#f8d7da; border-color:#dc3545; color:#721c24; }

/* Bank details box */
.bank-box {
    background:#f5f8fc; border-radius:8px; padding:1rem 1.25rem;
    font-size:.88rem; line-height:2; margin-top:1rem; font-weight:600;
}

/* Footer */
.pop-footer {
    text-align:center; color:#888; font-size:.78rem; margin-top:1.5rem; line-height:1.7;
}

/* Already uploaded state */
.status-icon { font-size:3rem; margin-bottom:1rem; }
</style>
</head>
<body>

<div class="pop-header">
    <h1><i class="fas fa-file-upload"></i> Upload Proof of Payment</h1>
    <p>Auction Hub Group — Pre-Auction Purchase Commitment</p>
</div>

<div class="pop-wrap">

    <?php if ($error): ?>
    <div class="alert alert-error">
        <strong><i class="fas fa-exclamation-triangle"></i> Unable to Process</strong>
        <?= $error ?>
        <p style="margin-top:.75rem;font-size:.85rem;">
            Contact us: <a href="mailto:<?= d2(COMPANY_EMAIL) ?>" style="color:#721c24;"><?= d2(COMPANY_EMAIL) ?></a>
            &nbsp;|&nbsp; <a href="tel:<?= str_replace(' ','',COMPANY_PHONE) ?>" style="color:#721c24;"><?= d2(COMPANY_PHONE) ?></a>
        </p>
    </div>

    <?php elseif ($success): ?>
    <div class="pop-card">
        <div class="pop-card-header" style="background:#155724;">
            <i class="fas fa-check-circle"></i> Payment Submitted Successfully
        </div>
        <div class="pop-card-body" style="text-align:center;padding:2.5rem 1.5rem;">
            <div class="status-icon">&#9989;</div>
            <div class="alert alert-success" style="text-align:left;"><?= $success ?></div>
            <?php if ($invoice): ?>
            <div class="bank-box" style="text-align:left;">
                <strong>What happens next?</strong><br>
                1. Our team will verify your proof of payment<br>
                2. If approved, you will receive an email with your <strong>unique Buyer Code</strong><br>
                3. Your Buyer Code grants you access to test-drive &amp; inspect the vehicle<br>
                4. The remaining balance of <strong><?= rands3((float)$invoice['outstanding_amount']) ?></strong> is due before handover
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($alreadyUploaded): ?>
    <div class="pop-card">
        <div class="pop-card-header" style="background:#004085;">
            <i class="fas fa-clock"></i> Proof of Payment Under Review
        </div>
        <div class="pop-card-body" style="text-align:center;padding:2.5rem 1.5rem;">
            <div class="status-icon" style="color:#004085;">&#128336;</div>
            <div class="alert alert-info">
                <strong>Your proof of payment has been received.</strong>
                Our team is currently reviewing your submission. You will receive an email once a decision has been made.
            </div>
            <div class="bank-box" style="text-align:left;">
                Invoice: <strong><?= d2($invoice['invoice_number']) ?></strong><br>
                Submitted: <strong><?= safeDate('d M Y H:i', $invoice['pop_uploaded_at']) ?></strong><br>
                Vehicle: <strong><?= d2($invoice['vehicle_description']) ?></strong>
            </div>
        </div>
    </div>

    <?php elseif ($invoice): ?>

    <!-- Validity bar -->
    <?php if ($isExpired): ?>
    <div class="validity-bar expired">
        <i class="fas fa-exclamation-triangle"></i>
        This invoice expired on <?= d2($expiresAt) ?>
    </div>
    <?php else: ?>
    <div class="validity-bar">
        <i class="fas fa-clock"></i>
        Invoice valid until: <strong><?= d2($expiresAt) ?></strong>
    </div>
    <?php endif; ?>

    <!-- Invoice summary -->
    <div class="pop-card">
        <div class="pop-card-header">
            <i class="fas fa-file-invoice"></i> Invoice Summary — <?= d2($invoice['invoice_number']) ?>
        </div>
        <div class="pop-card-body">
            <div class="detail-row">
                <span class="lbl">Buyer</span>
                <span class="val"><?= d2($invoice['buyer_name']) ?></span>
            </div>
            <div class="detail-row">
                <span class="lbl">Vehicle</span>
                <span class="val"><?= d2($invoice['vehicle_description']) ?></span>
            </div>
            <div class="detail-row">
                <span class="lbl">Total Price</span>
                <span class="val"><?= rands3((float)$invoice['total_price']) ?></span>
            </div>

            <div class="fee-highlight">
                <div class="num"><?= rands3((float)$invoice['commitment_fee_amount']) ?></div>
                <div class="lbl2">Commitment Fee Due (<?= $invoice['commitment_fee_pct'] ?>% of total)</div>
            </div>

            <div class="fee-outstanding">
                <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:#856404;font-weight:700;margin-bottom:.2rem;">Outstanding Balance (after commitment)</div>
                <div class="num"><?= rands3((float)$invoice['outstanding_amount']) ?></div>
            </div>
        </div>
    </div>

    <!-- Bank details -->
    <div class="pop-card">
        <div class="pop-card-header"><i class="fas fa-university"></i> Payment Details</div>
        <div class="pop-card-body">
            <?php $bank = getBankSettings(); ?>
            <div class="bank-box">
                <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:#888;font-weight:700;margin-bottom:.5rem;">Pay the Commitment Fee To:</div>
                <strong><?= d2(COMPANY_NAME) ?></strong><br>
                Bank: <strong><?= d2($bank['bank_name']) ?></strong><br>
                Account No: <strong><?= d2($bank['bank_account_no']) ?></strong><br>
                Branch Code: <strong><?= d2($bank['bank_branch_code']) ?></strong><br>
                Account Type: <strong><?= d2($bank['bank_account_type']) ?></strong><br>
                Reference: <strong><?= d2($invoice['buyer_name']) ?></strong>
            </div>
            <div class="alert alert-info" style="margin-top:1rem;font-size:.82rem;">
                <strong><i class="fas fa-info-circle"></i> Important</strong>
                Use your <strong>full name and surname</strong> as the payment reference. Upload your proof of payment below immediately after completing the payment.
            </div>
            <div class="alert alert-warning" style="font-size:.82rem;">
                <strong><i class="fas fa-shield-alt"></i> Refund Policy</strong>
                All funds are 100% refundable, excluding R102.00 for administration and bank charges.
            </div>
        </div>
    </div>

    <!-- Upload form -->
    <?php if (!$isExpired): ?>
    <div class="pop-card">
        <div class="pop-card-header"><i class="fas fa-upload"></i> Upload Your Proof of Payment</div>
        <div class="pop-card-body">
            <form method="POST" enctype="multipart/form-data" id="popForm">
                <input type="hidden" name="invoice_number" value="<?= d2($invoice['invoice_number']) ?>">
                <input type="hidden" name="upload_token"   value="<?= d2($invoice['upload_token']) ?>">

                <div class="upload-zone" id="uploadZone">
                    <input type="file" name="pop_file" id="pop_file" accept="image/jpeg,image/png,application/pdf" required onchange="showFile(this)">
                    <i class="fas fa-cloud-upload-alt" id="uploadIcon"></i>
                    <div class="zone-text">Click to select your proof of payment</div>
                    <div class="zone-sub">Accepted: JPG, PNG, PDF &mdash; Max 10MB</div>
                </div>
                <div class="file-name-display" id="fileNameDisplay">
                    <i class="fas fa-paperclip"></i> <span id="fileNameText"></span>
                </div>

                <button type="submit" class="btn-upload" id="submitBtn" disabled>
                    <i class="fas fa-upload"></i> Submit Proof of Payment
                </button>
            </form>

            <p style="font-size:.78rem;color:#888;text-align:center;margin-top:1rem;line-height:1.7;">
                By submitting, you confirm this is your genuine proof of payment for invoice <?= d2($invoice['invoice_number']) ?>.<br>
                This is a legally binding document.
            </p>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-error">
        <strong><i class="fas fa-lock"></i> Upload Window Closed</strong>
        This invoice expired and can no longer accept proof of payment uploads. Please contact Auction Hub Group to obtain a new invoice.
    </div>
    <?php endif; ?>

    <?php endif; ?><!-- /invoice available -->

    <!-- Footer -->
    <div class="pop-footer">
        <strong>Auction Hub Group</strong><br>
        <?= d2(COMPANY_ADDRESS) ?><br>
        <a href="mailto:<?= d2(COMPANY_EMAIL) ?>" style="color:#005BA1;"><?= d2(COMPANY_EMAIL) ?></a>
        &nbsp;|&nbsp; <?= d2(COMPANY_PHONE) ?><br>
        <a href="tel:<?= str_replace(' ','',COMPANY_PHONE) ?>" style="color:#005BA1;font-weight:700;">
            <i class="fas fa-phone"></i> <?= d2(COMPANY_PHONE) ?>
        </a>
    </div>

</div><!-- /pop-wrap -->

<script>
function showFile(input) {
    const zone   = document.getElementById('uploadZone');
    const icon   = document.getElementById('uploadIcon');
    const name   = document.getElementById('fileNameDisplay');
    const text   = document.getElementById('fileNameText');
    const submit = document.getElementById('submitBtn');

    if (input.files && input.files[0]) {
        zone.classList.add('has-file');
        icon.className = 'fas fa-file-check';
        text.textContent = input.files[0].name + ' (' + (input.files[0].size / 1024).toFixed(0) + ' KB)';
        name.style.display = 'block';
        submit.disabled = false;
        submit.style.background = '#005BA1';
    }
}

// Drag & drop
const zone = document.getElementById('uploadZone');
if (zone) {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('dragover');
        const dt   = e.dataTransfer;
        const file = dt.files[0];
        if (file) {
            document.getElementById('pop_file').files = dt.files;
            showFile(document.getElementById('pop_file'));
        }
    });
}

// Prevent double submit
const form = document.getElementById('popForm');
if (form) {
    form.addEventListener('submit', () => {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    });
}
</script>
</body>
</html>
