<?php
// ============================================================
// AUCTION HUB GROUP — invoice_api.php
// AJAX API: create, approve, decline, upload POP for invoices
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/email_helper.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Helpers ──────────────────────────────────────────────
function ok(array $data = []): void {
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}
function fail(string $msg, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

function generateInvoiceNumber(): string {
    $now  = new DateTime();
    $rand = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    return 'AHG-INV-' . $now->format('Ym') . '-' . $rand;
}

function generateBuyerCode(): string {
    $rand = strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
    return 'AHG-PA-' . date('Y') . '-' . $rand;
}

function generateUploadToken(): string {
    return bin2hex(random_bytes(32));
}

// ── Route ─────────────────────────────────────────────────
switch ($action) {

    // ── CREATE PRE-AUCTION INVOICE ─────────────────────────────
    case 'create_preauction_invoice':
        if (!isAdmin()) fail('Unauthorised', 403);

        $buyerName        = trim($_POST['buyer_name']        ?? '');
        $buyerEmail       = trim($_POST['buyer_email']       ?? '');
        $buyerPhone       = trim($_POST['buyer_phone']       ?? '');
        $buyerIdPassport  = trim($_POST['buyer_id_passport'] ?? '');
        $buyerAddress     = trim($_POST['buyer_address']     ?? '');

        $vDesc     = trim($_POST['vehicle_description']    ?? '');
        $vYear     = (int)($_POST['vehicle_year']          ?? 0) ?: null;
        $vMileage  = trim($_POST['vehicle_mileage']        ?? '');
        $vTrans    = trim($_POST['vehicle_transmission']   ?? '');
        $vSH       = trim($_POST['vehicle_service_history']?? 'Yes');
        $vCond     = trim($_POST['vehicle_condition']      ?? 'Used');
        $vBody     = trim($_POST['vehicle_body']           ?? '');
        $vColour   = trim($_POST['vehicle_colour']         ?? '');
        $vFuel     = trim($_POST['vehicle_fuel']           ?? '');
        $vFeatures = trim($_POST['vehicle_features']       ?? '');
        $vImage    = trim($_POST['vehicle_image_url']      ?? '');

        $totalPrice  = (float)($_POST['total_price']          ?? 0);
        $feePct      = (float)($_POST['commitment_fee_pct']   ?? 35);
        $notes       = trim($_POST['notes']                   ?? '');

        if (!$buyerName || !$vDesc || $totalPrice <= 0) {
            fail('Buyer name, vehicle description, and total price are required.');
        }
        if ($feePct < 1 || $feePct > 100) {
            fail('Commitment fee percentage must be between 1 and 100.');
        }

        $feeAmount    = round($totalPrice * ($feePct / 100), 2);
        $outstanding  = round($totalPrice - $feeAmount, 2);
        $invoiceNum   = generateInvoiceNumber();
        $uploadToken  = generateUploadToken();
        $expiresAt    = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $createdBy    = $_SESSION['user_id'] ?? null;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO preauction_invoices
                    (invoice_number, buyer_name, buyer_email, buyer_phone, buyer_id_passport,
                     buyer_address, vehicle_description, vehicle_year, vehicle_mileage,
                     vehicle_transmission, vehicle_service_history, vehicle_condition,
                     vehicle_body, vehicle_colour, vehicle_fuel, vehicle_features,
                     vehicle_image_url, total_price, commitment_fee_pct, commitment_fee_amount,
                     outstanding_amount, upload_token, notes, created_by, expires_at)
                VALUES
                    (:inv_num, :buyer_name, :buyer_email, :buyer_phone, :buyer_id,
                     :buyer_addr, :v_desc, :v_year, :v_mileage,
                     :v_trans, :v_sh, :v_cond,
                     :v_body, :v_colour, :v_fuel, :v_features,
                     :v_image, :total, :fee_pct, :fee_amt,
                     :outstanding, :token, :notes, :created_by, :expires_at)
            ");
            $stmt->execute([
                ':inv_num'    => $invoiceNum,
                ':buyer_name' => $buyerName,
                ':buyer_email'=> $buyerEmail,
                ':buyer_phone'=> $buyerPhone,
                ':buyer_id'   => $buyerIdPassport,
                ':buyer_addr' => $buyerAddress,
                ':v_desc'     => $vDesc,
                ':v_year'     => $vYear,
                ':v_mileage'  => $vMileage,
                ':v_trans'    => $vTrans,
                ':v_sh'       => $vSH,
                ':v_cond'     => $vCond,
                ':v_body'     => $vBody,
                ':v_colour'   => $vColour,
                ':v_fuel'     => $vFuel,
                ':v_features' => $vFeatures,
                ':v_image'    => $vImage,
                ':total'      => $totalPrice,
                ':fee_pct'    => $feePct,
                ':fee_amt'    => $feeAmount,
                ':outstanding'=> $outstanding,
                ':token'      => $uploadToken,
                ':notes'      => $notes,
                ':created_by' => $createdBy,
                ':expires_at' => $expiresAt,
            ]);

            $invoiceId  = $pdo->lastInsertId();
            $uploadLink = APP_URL . '/invoice_pop_upload.php?num=' . urlencode($invoiceNum) . '&token=' . urlencode($uploadToken);

            ok([
                'invoice_id'   => $invoiceId,
                'invoice_num'  => $invoiceNum,
                'upload_link'  => $uploadLink,
                'fee_amount'   => $feeAmount,
                'outstanding'  => $outstanding,
            ]);
        } catch (PDOException $e) {
            error_log('AHG Invoice Create Error: ' . $e->getMessage());
            fail('Database error. Please try again.');
        }
        break;


    // ── APPROVE INVOICE ────────────────────────────────────────
    case 'approve_invoice':
        if (!isAdmin()) fail('Unauthorised', 403);

        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $note      = trim($_POST['note'] ?? '');
        if (!$invoiceId) fail('Invalid invoice ID.');

        $inv = $pdo->prepare("SELECT * FROM preauction_invoices WHERE id = ?");
        $inv->execute([$invoiceId]);
        $invoice = $inv->fetch();
        if (!$invoice) fail('Invoice not found.');
        if ($invoice['status'] === 'approved') fail('Invoice already approved.');

        $buyerCode = generateBuyerCode();

        try {
            $upd = $pdo->prepare("
                UPDATE preauction_invoices
                SET status = 'approved', buyer_code = ?, date_signed = CURDATE()
                WHERE id = ?
            ");
            $upd->execute([$buyerCode, $invoiceId]);

            // Send buyer code email
            if ($invoice['buyer_email']) {
                sendBuyerCodeEmail([
                    'buyer_name'          => $invoice['buyer_name'],
                    'buyer_email'         => $invoice['buyer_email'],
                    'buyer_code'          => $buyerCode,
                    'invoice_number'      => $invoice['invoice_number'],
                    'vehicle_description' => $invoice['vehicle_description'],
                    'commitment_fee'      => $invoice['commitment_fee_amount'],
                    'outstanding'         => $invoice['outstanding_amount'],
                    'total_price'         => $invoice['total_price'],
                    'admin_note'          => $note,
                ]);
            }

            ok(['buyer_code' => $buyerCode]);
        } catch (PDOException $e) {
            error_log('AHG Invoice Approve Error: ' . $e->getMessage());
            fail('Database error during approval.');
        }
        break;


    // ── DECLINE INVOICE ────────────────────────────────────────
    case 'decline_invoice':
        if (!isAdmin()) fail('Unauthorised', 403);

        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $reason    = trim($_POST['reason'] ?? '');
        if (!$invoiceId) fail('Invalid invoice ID.');
        if (!$reason)    fail('A declination reason is required.');

        $inv = $pdo->prepare("SELECT * FROM preauction_invoices WHERE id = ?");
        $inv->execute([$invoiceId]);
        $invoice = $inv->fetch();
        if (!$invoice) fail('Invoice not found.');
        if ($invoice['status'] === 'approved') fail('Approved invoices cannot be declined.');

        try {
            $upd = $pdo->prepare("
                UPDATE preauction_invoices
                SET status = 'declined', declination_reason = ?
                WHERE id = ?
            ");
            $upd->execute([$reason, $invoiceId]);

            if ($invoice['buyer_email']) {
                sendDeclinationEmail([
                    'buyer_name'          => $invoice['buyer_name'],
                    'buyer_email'         => $invoice['buyer_email'],
                    'invoice_number'      => $invoice['invoice_number'],
                    'vehicle_description' => $invoice['vehicle_description'],
                    'reason'              => $reason,
                ]);
            }

            ok(['message' => 'Invoice declined and buyer notified.']);
        } catch (PDOException $e) {
            error_log('AHG Invoice Decline Error: ' . $e->getMessage());
            fail('Database error during declination.');
        }
        break;


    // ── UPLOAD POP (called from invoice_pop_upload.php) ────────
    case 'upload_invoice_pop':
        $invoiceNum  = trim($_POST['invoice_number'] ?? '');
        $uploadToken = trim($_POST['upload_token']   ?? '');

        if (!$invoiceNum || !$uploadToken) fail('Missing invoice reference.');

        // Validate token
        $inv = $pdo->prepare("
            SELECT * FROM preauction_invoices
            WHERE invoice_number = ? AND upload_token = ? AND status IN ('pending','pop_uploaded')
        ");
        $inv->execute([$invoiceNum, $uploadToken]);
        $invoice = $inv->fetch();
        if (!$invoice) fail('Invalid link or invoice already processed.');

        // Check expiry
        if (strtotime($invoice['expires_at']) < time()) {
            fail('This invoice link has expired. Please contact Auction Hub Group.');
        }

        // Handle file upload
        if (empty($_FILES['pop_file']['name'])) fail('Please select a file to upload.');

        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        $result  = handleUpload($_FILES['pop_file'], UPLOAD_POP, $allowed, 'inv_pop');
        if (!$result['success']) fail($result['message']);

        try {
            $upd = $pdo->prepare("
                UPDATE preauction_invoices
                SET status = 'pop_uploaded', pop_file_path = ?, pop_uploaded_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([$result['path'], $invoice['id']]);

            // Alert admin
            sendPreAuctionPOPAdminAlert($invoice);

            ok(['message' => 'Proof of payment uploaded successfully. Auction Hub Group will review and contact you shortly.']);
        } catch (PDOException $e) {
            error_log('AHG POP Upload Error: ' . $e->getMessage());
            fail('Could not save your upload. Please try again.');
        }
        break;


    // ── GET INVOICE (for print view) ───────────────────────────
    case 'get_invoice':
        $id  = (int)($_GET['id']  ?? 0);
        $num = trim($_GET['num']  ?? '');

        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM preauction_invoices WHERE id = ?");
            $stmt->execute([$id]);
        } elseif ($num) {
            $stmt = $pdo->prepare("SELECT * FROM preauction_invoices WHERE invoice_number = ?");
            $stmt->execute([$num]);
        } else {
            fail('No invoice identifier provided.');
        }

        $invoice = $stmt->fetch();
        if (!$invoice) fail('Invoice not found.');

        // Admin or valid token required
        $token = $_GET['token'] ?? '';
        if (!isAdmin() && $invoice['upload_token'] !== $token) {
            fail('Unauthorised', 403);
        }

        ok(['invoice' => $invoice]);
        break;


    default:
        fail('Unknown action.');
}
