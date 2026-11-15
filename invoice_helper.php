<?php
// ============================================================
// AUCTION HUB GROUP — invoice_helper.php
// Client invoices for the purchase (pre-auction) and finance forms.
//
//   1. The client submits preauction_request.php or finance_apply.php
//      → invCreateDraft() writes a DRAFT row in preauction_invoices
//        (number AHG-YYYYMMDD-NN, client, vehicle, photos, amounts).
//   2. Staff review / edit it in invoice_edit.php (Admin → Finance &
//      Pre-Auction → "Review & edit invoice"). Nothing is emailed yet.
//   3. "Send to client": pre-auction → invSendPreauction() (24-hour
//      proforma); finance → finApproveDeposit() (deposit invoice).
//   4. The client sees it at invoice_view.php?num=…&token=… (A4, print /
//      Save as PDF), the same layout staff preview.
//
// kind = 'preauction' (commitment fee) | 'finance' (deposit).
// Requires: config.php, finance_helper.php (loads this file).
// ============================================================

const INV_PHOTOS_MAX = 4;
const INV_PAY_HOURS  = 24;   // every client invoice: pay within 24 hours of receiving it

/** The payment-window sentence used in every invoice email and on the invoices. */
function invPayWarning(): string {
    return 'Please pay within ' . INV_PAY_HOURS . ' hours of receiving this invoice, otherwise the vehicle will be sold to another buyer.';
}

/** Columns and the 'draft' status this feature needs; added once, on first use. */
function invSchemaFix(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $have = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'preauction_invoices'")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!$have) return;
        $add = [
            'kind'           => "VARCHAR(12) NOT NULL DEFAULT 'preauction'",
            'application_id' => "INT UNSIGNED NULL",
            'photos'         => "TEXT NULL",
            'note_text'      => "TEXT NULL",
            'due_text'       => "VARCHAR(60) NULL",
            'invoice_date'   => "DATE NULL",
            'sent_at'        => "DATETIME NULL",
            'edited_at'      => "DATETIME NULL",
        ];
        foreach ($add as $col => $def) {
            if (!isset($have[$col])) $pdo->exec("ALTER TABLE preauction_invoices ADD COLUMN $col $def");
        }
        if (isset($have['status']) && !str_contains($have['status'], "'draft'")) {
            $pdo->exec("ALTER TABLE preauction_invoices MODIFY status ENUM('draft','pending','pop_uploaded','approved','declined') NOT NULL DEFAULT 'pending'");
        }
        // A draft has no 24-hour window yet, so expires_at must allow NULL.
        $nullable = $pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME = 'preauction_invoices' AND COLUMN_NAME = 'expires_at'")->fetchColumn();
        if ($nullable === 'NO') $pdo->exec("ALTER TABLE preauction_invoices MODIFY expires_at DATETIME NULL DEFAULT NULL");
    } catch (Throwable $e) {
        error_log('[AHG inv] schema: ' . $e->getMessage());
    }
}

function invRow(int $id): ?array {
    global $pdo;
    invSchemaFix();
    try {
        $st = $pdo->prepare("SELECT * FROM preauction_invoices WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/** The invoice row belonging to an application (finance or pre-auction), if any. */
function invForApp(array $app): ?array {
    $id = (int)($app['details']['invoice_row_id'] ?? $app['details']['pa_invoice_id'] ?? 0);
    return $id > 0 ? invRow($id) : null;
}

/** Running number per day: AHG-20261115-01 (commitment fee) or AHG-INV-20261115-01 (finance deposit). */
function invNextNumber(string $kind = 'preauction'): string {
    global $pdo;
    $prefix = ($kind === 'finance' ? 'AHG-INV-' : 'AHG-') . date('Ymd') . '-';
    $n = 0;
    try {
        $st = $pdo->prepare("SELECT invoice_number FROM preauction_invoices WHERE invoice_number LIKE ?");
        $st->execute([$prefix . '%']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $num) $n = max($n, (int)substr($num, strlen($prefix)));
    } catch (Throwable $e) {}
    return $prefix . str_pad((string)($n + 1), 2, '0', STR_PAD_LEFT);
}

/** DEKRA condition grade → sticker wording. */
function invSticker(?string $grade): string {
    return match (strtoupper(trim((string)$grade))) {
        'A' => 'DEKRA Platinum Sticker', 'B' => 'DEKRA Gold Sticker', 'C' => 'DEKRA Green Sticker',
        default => 'DEKRA inspected',
    };
}

/** Up to 4 photos of the lot: gallery first, then the main image. */
function invLotPhotos(int $auctionId): array {
    global $pdo;
    $out = [];
    try {
        $st = $pdo->prepare("SELECT file_path FROM auction_gallery WHERE auction_id = ? AND file_path <> '' ORDER BY is_primary DESC, sort_order, id LIMIT 8");
        $st->execute([$auctionId]);
        $out = $st->fetchAll(PDO::FETCH_COLUMN);
        $st = $pdo->prepare("SELECT image_url FROM auctions WHERE id = ?");
        $st->execute([$auctionId]);
        if (($main = (string)$st->fetchColumn()) !== '' && !in_array($main, $out, true)) array_unshift($out, $main);
    } catch (Throwable $e) {}
    return array_slice(array_values(array_unique(array_filter($out))), 0, INV_PHOTOS_MAX);
}

function invPhotos(array $inv): array {
    $p = json_decode((string)($inv['photos'] ?? ''), true);
    if (!is_array($p)) $p = !empty($inv['vehicle_image_url']) ? [$inv['vehicle_image_url']] : [];
    return array_slice(array_values(array_filter($p, 'is_string')), 0, INV_PHOTOS_MAX);
}

function invIsFinance(array $inv): bool { return ($inv['kind'] ?? 'preauction') === 'finance'; }

function invTitle(array $inv): string { return invIsFinance($inv) ? 'FINANCE APPROVAL DEPOSIT INVOICE' : 'COMMITMENT FEE INVOICE'; }

/** Finance line item: "Startup Deposit" when the assessment asked for a start-up deposit. */
function invFinanceLine(array $inv): string {
    $app = !empty($inv['application_id']) ? finGet((int)$inv['application_id']) : null;
    return (!empty($app['details']['quote']['startup']) ? 'Startup Deposit' : 'Deposit') . ': Finance Approval Process';
}

/** What the client pays on this invoice (commitment fee or deposit) and what remains. */
function invAmounts(array $inv): array {
    $price = (float)$inv['total_price'];
    $fee   = (float)$inv['commitment_fee_amount'];
    $pct   = $price > 0 ? round($fee / $price * 100, 1) : 0;
    return ['price' => $price, 'fee' => $fee, 'pct' => $pct, 'rest' => round($price - $fee, 2)];
}

function invPctLabel(float $pct): string {
    return rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.') . '%';
}

/** The note under the description — staff can replace it; empty = this default, which follows the amounts. */
function invDefaultNote(array $inv): string {
    $a = invAmounts($inv);
    if (invIsFinance($inv)) {
        // The sub-line under the deposit on the finance invoice.
        return 'Opens the finance approval process with the lender. Forms part of the deposit, not an extra cost.';
    }
    return 'The ' . formatRand($a['fee']) . ' commitment fee on this invoice is ' . invPctLabel($a['pct']) . ' of the vehicle price. '
         . 'Once it is paid, the outstanding balance is ' . formatRand($a['rest']) . '. '
         . "Once payment is made, the buyer will receive a buyer's code and a confirmed appointment booking time.";
}

function invNote(array $inv): string {
    $n = trim((string)($inv['note_text'] ?? ''));
    return $n !== '' ? $n : invDefaultNote($inv);
}

/** Client link (A4 invoice, print / Save as PDF). Only works once the invoice is sent. */
function invClientLink(array $inv): string {
    return APP_URL . '/invoice_view.php?num=' . urlencode($inv['invoice_number']) . '&token=' . urlencode((string)$inv['upload_token']);
}

/** Step 1: a draft invoice, straight after the client submits the form. Never emails anyone. */
function invCreateDraft(array &$app): ?array {
    global $pdo;
    invSchemaFix();
    if ($row = invForApp($app)) return $row;
    $isFin = $app['kind'] === 'finance';
    $d     = $app['details'];
    $v     = finVehicle((int)$app['auction_id']) ?: [];
    $price = (float)$app['price'];
    if ($isFin) {
        $fee = round((float)$app['amount'], 2);
        $pct = $price > 0 ? round($fee / $price * 100, 2) : 0;
    } else {
        [, , $def] = paRange();
        $pct = $def;
        $fee = round($price * $pct / 100, 2);
    }
    $vd = $d['vehicle'] ?? [];
    $features = implode("\n", [invSticker($vd['grade'] ?? ($v['condition_grade'] ?? '')), 'Sold voetstoots', 'Full vehicle condition report available']);
    $notes = ($isFin ? 'Online finance application ' : 'Online pre-auction request ') . finRef($app)
           . (!empty($d['alt']) ? ' · Alternative vehicle: ' . $d['alt']['title'] . ' (lot ' . $d['alt']['id'] . ')' : '')
           . (!empty($d['mechanic']) ? ' · Bringing a mechanic' : '');
    try {
        $pdo->prepare("INSERT INTO preauction_invoices
                (invoice_number, kind, application_id, status, buyer_name, buyer_email, buyer_phone, buyer_id_passport, buyer_address,
                 vehicle_description, vehicle_year, vehicle_mileage, vehicle_transmission, vehicle_service_history,
                 vehicle_condition, vehicle_body, vehicle_colour, vehicle_fuel, vehicle_features, vehicle_image_url, photos,
                 total_price, commitment_fee_pct, commitment_fee_amount, outstanding_amount,
                 upload_token, notes, due_text, invoice_date, auction_id)
                VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)")
            ->execute([invNextNumber($isFin ? 'finance' : 'preauction'), $isFin ? 'finance' : 'preauction', (int)$app['id'],
                       $app['name'], $app['email'], $app['phone'], $d['id_number'] ?? null, $d['address'] ?? null,
                       $app['vehicle'], ($vd['year'] ?? null) ?: null,
                       !empty($vd['mileage']) ? number_format((int)$vd['mileage'], 0, '.', ' ') . ' km' : null,
                       $vd['transmission'] ?? null,
                       !empty($v['service_history']) && stripos((string)$v['service_history'], 'no') !== 0 ? 'Yes' : 'No', 'Used',
                       $vd['body'] ?? null, $vd['colour'] ?? null, $vd['fuel'] ?? null, $features, $vd['image'] ?? null,
                       json_encode(invLotPhotos((int)$app['auction_id']), JSON_UNESCAPED_SLASHES),
                       $price, $pct, $fee, round($price - $fee, 2),
                       bin2hex(random_bytes(32)), $notes, $isFin ? 'Within ' . INV_PAY_HOURS . ' hours' : 'On receipt', (int)$app['auction_id']]);
    } catch (Throwable $e) {
        error_log('[AHG inv] draft: ' . $e->getMessage());
        return null;
    }
    $id = (int)$pdo->lastInsertId();
    $app['details']['invoice_row_id'] = $id;
    if (!$isFin) $app['details']['pa_invoice_id'] = $id;
    $row = invRow($id);
    finLog($app, 'Draft invoice ' . $row['invoice_number'] . ' created — waiting for the office to review', 'system');
    finSave($app);
    return $row;
}

/** Finance invoices follow the deposit on the application (quotes and counter-offers change it). */
function invSyncFinance(array $app, array $inv): array {
    global $pdo;
    if (!invIsFinance($inv) || $inv['status'] !== 'draft') return $inv;
    $price = (float)$app['price']; $fee = round((float)$app['amount'], 2);
    if (abs($fee - (float)$inv['commitment_fee_amount']) < 0.01 && abs($price - (float)$inv['total_price']) < 0.01) return $inv;
    $pdo->prepare("UPDATE preauction_invoices SET total_price = ?, commitment_fee_amount = ?, commitment_fee_pct = ?, outstanding_amount = ? WHERE id = ?")
        ->execute([$price, $fee, $price > 0 ? round($fee / $price * 100, 2) : 0, round($price - $fee, 2), $inv['id']]);
    return invRow((int)$inv['id']);
}

/**
 * Saves the staff edits from invoice_edit.php. Returns an error message, or ''.
 * Finance deposits are not edited here: they come from the application (Suggest Higher Deposit).
 */
function invSaveEdits(array $inv, array $p): string {
    global $pdo;
    $t = fn(string $k, int $len = 255) => trim(mb_substr((string)($p[$k] ?? ''), 0, $len));
    $name = $t('buyer_name', 200);
    if (mb_strlen($name) < 2) return 'Enter the client\'s name.';
    $email = $t('buyer_email', 200);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'That email address is not valid.';
    $title = $t('vehicle_description', 500);
    if ($title === '') return 'Enter the vehicle description.';
    $price = finMoneyInput((string)($p['total_price'] ?? ''));
    if ($price <= 0) return 'Enter the vehicle price.';
    if (invIsFinance($inv)) {
        $fee = (float)$inv['commitment_fee_amount'];
    } else {
        $pct = (float)str_replace(',', '.', (string)($p['commitment_fee_pct'] ?? ''));
        if ($pct <= 0 || $pct >= 100) return 'The commitment fee must be between 1% and 99% of the price.';
        $fee = round($price * $pct / 100, 2);
    }
    if ($fee >= $price) return 'The amount on the invoice must be less than the vehicle price.';
    $date = $t('invoice_date', 10);
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'The invoice date is not valid.';
    $photos = array_values(array_filter(array_map('trim', (array)($p['photos'] ?? [])), fn($u) => preg_match('~^https?://~i', $u)));
    $features = implode("\n", array_filter(array_map('trim', preg_split('/\r?\n/', (string)($p['vehicle_features'] ?? '')))));
    $note = trim(mb_substr((string)($p['note_text'] ?? ''), 0, 1500));
    // An untouched default note stays "automatic", so it keeps following the amounts.
    if ($note !== '' && preg_replace('/\s+/', ' ', $note) === preg_replace('/\s+/', ' ', invDefaultNote($inv))) $note = '';
    $pdo->prepare("UPDATE preauction_invoices SET
            buyer_name = ?, buyer_email = ?, buyer_phone = ?, buyer_id_passport = ?, buyer_address = ?,
            vehicle_description = ?, vehicle_year = ?, vehicle_mileage = ?, vehicle_transmission = ?, vehicle_fuel = ?,
            vehicle_body = ?, vehicle_colour = ?, vehicle_condition = ?, vehicle_features = ?, photos = ?,
            total_price = ?, commitment_fee_pct = ?, commitment_fee_amount = ?, outstanding_amount = ?,
            note_text = ?, due_text = ?, invoice_date = ?, edited_at = NOW()
        WHERE id = ?")
        ->execute([$name, $email ?: null, $t('buyer_phone', 50) ?: null, $t('buyer_id_passport', 50) ?: null, $t('buyer_address', 500) ?: null,
                   $title, (int)$t('vehicle_year', 4) ?: null, $t('vehicle_mileage', 50) ?: null, $t('vehicle_transmission', 50) ?: null, $t('vehicle_fuel', 50) ?: null,
                   $t('vehicle_body', 50) ?: null, $t('vehicle_colour', 100) ?: null, $t('vehicle_condition', 50) ?: 'Used', $features ?: null,
                   json_encode(array_slice($photos, 0, INV_PHOTOS_MAX), JSON_UNESCAPED_SLASHES),
                   $price, $price > 0 ? round($fee / $price * 100, 2) : 0, $fee, round($price - $fee, 2),
                   $note !== '' ? $note : null, $t('due_text', 60) ?: null, $date ?: null, $inv['id']]);
    return '';
}

/** Step 3 (pre-auction): the reviewed draft goes to the client as a 24-hour proforma. Also re-sends an expired one. */
function invSendPreauction(array &$app, string $by): string {
    global $pdo;
    $inv = invForApp($app);
    if (!$inv) return 'There is no invoice to send.';
    if (!in_array($inv['status'], ['draft', 'pending'], true)) return 'This invoice can no longer be sent (it is ' . $inv['status'] . ').';
    if (!in_array($app['status'], ['requested', 'expired', 'invoiced'], true)) return 'This request is past the invoice step.';
    if (empty($inv['buyer_email'])) return 'Add the client\'s email address first.';
    $pdo->prepare("UPDATE preauction_invoices SET status = 'pending', sent_at = NOW(), expires_at = ?, invoice_date = COALESCE(invoice_date, CURDATE()) WHERE id = ?")
        ->execute([date('Y-m-d H:i:s', strtotime('+' . INV_PAY_HOURS . ' hours')), $inv['id']]);
    $inv = invRow((int)$inv['id']);
    $a = invAmounts($inv);
    $app['details']['pa_invoice_id'] = (int)$inv['id'];
    $app['details']['invoice_pct'] = $a['pct'];
    $app['percent'] = $a['pct'];
    $app['amount'] = $a['fee'];
    $app['price'] = $a['price'];
    $app['status'] = 'invoiced';
    finLog($app, 'Invoice ' . $inv['invoice_number'] . ' sent at ' . invPctLabel($a['pct']) . ' (' . formatRand($a['fee']) . ')', $by);
    $ok = finMailClient($app, 'Your commitment fee invoice ' . $inv['invoice_number'] . ' — valid for 24 hours',
        '<p>Thank you for your pre-auction purchase request. Your <strong>commitment fee invoice ' . e($inv['invoice_number']) . '</strong> is ready:</p>'
        . '<div class="highlight-box"><p><strong>Vehicle price:</strong> ' . formatRand($a['price']) . '</p>'
        . '<p><strong>Commitment fee (' . invPctLabel($a['pct']) . '):</strong> <span class="gold">' . formatRand($a['fee']) . '</span></p>'
        . '<p><strong>Outstanding balance after payment:</strong> ' . formatRand($a['rest']) . '</p></div>'
        . '<p><strong>' . e(invPayWarning()) . '</strong> Upload your proof of payment once you have paid.</p>'
        . "<p>Once payment is made you receive your buyer's code, and our team contacts you with a confirmed appointment booking time.</p>"
        . '<p><a class="cta-btn" href="' . e(invClientLink($inv)) . '">View &amp; download your invoice</a></p>'
        . '<p><a href="' . e(paInvoiceLink($inv)) . '">Upload proof of payment</a></p>',
        'Follow my request');
    finRecordMail($app, 'Invoice ' . $inv['invoice_number'], $ok);
    finSave($app);
    return '';
}

/** Signature image for "Authorised signature" (Admin uploads it once in invoice_edit.php). Inline, so it prints anywhere. */
function invSignatureDataUri(): string {
    $rel = finSetting('invoice_signature', '');
    if ($rel === '' || str_contains($rel, '..')) return '';
    $path = __DIR__ . '/' . ltrim($rel, '/');
    if (!is_file($path) || filesize($path) > 600000) return '';
    $mime = @mime_content_type($path) ?: 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path));
}
