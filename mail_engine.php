<?php
// ============================================================
// AUCTION HUB GROUP — mail_engine.php
// The machinery behind every email (ported from the BidCo email
// system, rebuilt for AHG). email_helper.php's _sendMail() runs
// every message through here:
//
//   • Switches  — each kind of email can be turned off in
//                 Admin → Email Settings (password resets and
//                 staff-written emails can't be switched off).
//   • Log       — every send is recorded as sent / failed / skipped
//                 in `email_log` (also the "already sent?" check
//                 that stops reminders going out twice).
//   • Previews  — capture mode builds an email with sample data
//                 and hands back the HTML instead of sending it.
//   • Tracking  — links back to the site get utm tags so email
//                 visits show in Google Analytics.
//   • Departments — replies go to the right mailbox (finance@ for
//                 payments and refunds, careers@ for HR, info@ else).
//   • After the page — mailDeferUntilAfterResponse() queues mail
//                 and sends it once the visitor already has the page,
//                 so a bid never waits for the mail server.
// Requires: config.php. Loaded by email_helper.php.
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;

const AHG_MAIL_DEPARTMENTS = [
    'auctions' => ['Auctions',  'info@auctionhubgroup.co.za'],
    'finance'  => ['Finance',   'finance@auctionhubgroup.co.za'],
    'careers'  => ['Careers',   'careers@auctionhubgroup.co.za'],
];

/**
 * kind => [label, group, who gets it, department, locked]
 * locked = cannot be switched off (security or written by staff).
 */
const AHG_MAIL_KINDS = [
    // Registration & bidding
    'welcome'             => ['Welcome (new account)',              'Registration & bidding', 'Client', 'auctions', false],
    'reg_reminder'        => ['Finish your registration (reminder)', 'Registration & bidding', 'Client', 'finance',  false],
    'bank_details'        => ['Banking details for the deposit',    'Registration & bidding', 'Client', 'finance',  false],
    'pop_approved'        => ['Proof of payment approved — bidder number', 'Registration & bidding', 'Client', 'finance', false],
    'pop_rejected'        => ['Proof of payment not accepted',      'Registration & bidding', 'Client', 'finance',  false],
    'first_bid_nudge'     => ['First-bid nudge (5 days, no bids)',  'Registration & bidding', 'Client', 'auctions', false],
    'bid_confirm'         => ['Bid placed',                         'Registration & bidding', 'Client', 'auctions', false],
    'outbid_alert'        => ['Outbid',                             'Registration & bidding', 'Client', 'auctions', false],
    'watch_ending'        => ['Watched lot closing soon',           'Registration & bidding', 'Client', 'auctions', false],
    'saved_search_digest' => ['Saved-search matches',               'Registration & bidding', 'Client', 'auctions', false],
    'auction_winner'      => ['You won',                            'Registration & bidding', 'Client', 'finance',  false],
    'auction_won'         => ['You won (manual)',                   'Registration & bidding', 'Client', 'finance',  false],
    'review_request'      => ['Review request (after a sale)',      'Registration & bidding', 'Client', 'auctions', false],
    'password_reset'      => ['Password reset',                     'Registration & bidding', 'Anyone', 'auctions', true],
    // Pre-auction
    'buyer_code'          => ['Pre-auction Buyer\'s Code',          'Pre-auction',            'Client', 'finance',  false],
    'declination'         => ['Pre-auction declined',               'Pre-auction',            'Client', 'finance',  false],
    // Refunds
    'refund_confirmation' => ['Refund request received',            'Refunds',                'Client', 'finance',  false],
    'refund_processed'    => ['Refund paid',                        'Refunds',                'Client', 'finance',  false],
    'refund_rejected'     => ['Refund declined',                    'Refunds',                'Client', 'finance',  false],
    // Office alerts
    'pop_admin_alert'     => ['Proof of payment uploaded',          'Office alerts',          'Office', 'finance',  false],
    'bank_request_admin'  => ['Banking details requested',          'Office alerts',          'Office', 'finance',  false],
    'preauction_pop_alert'=> ['Pre-auction proof of payment',       'Office alerts',          'Office', 'finance',  false],
    'refund_admin_alert'  => ['Refund requested',                   'Office alerts',          'Office', 'finance',  false],
    'enquiry_admin'       => ['New website enquiry',                'Office alerts',          'Office', 'auctions', false],
    'career_application_admin_alert' => ['Job application',         'Office alerts',          'Office', 'careers',  false],
    'contract_signed_admin' => ['Induction contract signed',        'Office alerts',          'Office', 'careers',  false],
    // Careers
    'career_application'  => ['Job application received',           'Careers',                'Applicant', 'careers', false],
    'induction_contract'  => ['Induction contract',                 'Careers',                'Applicant', 'careers', true],
    'worker_portal_access'=> ['Worker portal access (PIN)',         'Careers',                'Worker', 'careers',  true],
    // Written by staff
    'lead_template'       => ['Leads page template email',          'Written by staff',       'Lead',   'auctions', true],
    'custom_admin'        => ['Email Centre — single email',        'Written by staff',       'Client', 'auctions', true],
    'bulk'                => ['Email Centre — bulk email',          'Written by staff',       'Clients','auctions', true],
];

// ── Settings ─────────────────────────────────────────────────

function mailSetting(string $key, string $default = ''): string {
    global $pdo;
    static $all = null;
    if ($all === null) {
        $all = [];
        try { foreach ($pdo->query("SELECT setting_key, setting_value FROM site_settings") as $r) $all[$r['setting_key']] = (string)$r['setting_value']; }
        catch (Throwable $e) { /* no table: defaults */ }
    }
    return isset($all[$key]) && $all[$key] !== '' ? $all[$key] : $default;
}

function mailSaveSetting(string $key, ?string $value): void {
    global $pdo;
    if ($value === null) { $pdo->prepare("DELETE FROM site_settings WHERE setting_key = ?")->execute([$key]); return; }
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$key, $value]);
}

/** Is this kind of email switched on? Unknown kinds and locked kinds are always on. */
function mailKindOn(string $kind): bool {
    $k = AHG_MAIL_KINDS[$kind] ?? null;
    if (!$k || $k[4]) return true;
    return mailSetting('mail_off_' . $kind) !== '1';
}

// ── Log ──────────────────────────────────────────────────────

function mailLog(string $kind, ?string $to, ?string $subject, string $status, string $reason = '', string $ref = ''): void {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO email_log (kind, recipient, subject, status, reason, ref) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([mb_substr($kind ?: 'other', 0, 40), $to ? mb_substr($to, 0, 190) : null, $subject ? mb_substr($subject, 0, 255) : null,
                       $status, $reason !== '' ? mb_substr($reason, 0, 255) : null, $ref !== '' ? mb_substr($ref, 0, 60) : null]);
    } catch (Throwable $e) { /* table not created yet: never block a send */ }
}

/** Was this kind already sent for this reference (e.g. "user:12:2")? Stops reminders repeating. */
function mailAlreadySent(string $kind, string $ref): bool {
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT 1 FROM email_log WHERE kind = ? AND ref = ? AND status = 'sent' LIMIT 1");
        $st->execute([$kind, $ref]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

// ── Links, reply-to, WhatsApp ────────────────────────────────

/** Adds utm tags to links back to our own site, so email visits show in analytics. */
function mailTrackLinks(string $html, string $kind): string {
    $hosts = array_unique(array_filter([parse_url(APP_URL, PHP_URL_HOST), parse_url(CANONICAL_URL, PHP_URL_HOST), 'auctionhubgroup.co.za', 'www.auctionhubgroup.co.za']));
    return preg_replace_callback('~href="(https?://([^/"]+)[^"]*)"~i', function ($m) use ($hosts, $kind) {
        if (!in_array(strtolower($m[2]), $hosts, true) || str_contains($m[1], 'utm_source=') || str_contains($m[1], 'unsubscribe')) return $m[0];
        [$url, $frag] = array_pad(explode('#', html_entity_decode($m[1]), 2), 2, null);
        $url .= (str_contains($url, '?') ? '&' : '?') . 'utm_source=email&utm_medium=email&utm_campaign=' . rawurlencode($kind ?: 'email');
        return 'href="' . htmlspecialchars($url . ($frag !== null ? '#' . $frag : ''), ENT_QUOTES) . '"';
    }, $html);
}

function mailDepartmentAddress(string $kind): array {
    $dept = AHG_MAIL_KINDS[$kind][3] ?? 'auctions';
    return AHG_MAIL_DEPARTMENTS[$dept] ?? AHG_MAIL_DEPARTMENTS['auctions'];
}

/** The green WhatsApp follow-up line every client email ends with. */
function mailWhatsAppButton(string $text = 'Questions? WhatsApp us'): string {
    if (!defined('COMPANY_WHATSAPP_INTL')) return '';
    $url = 'https://wa.me/' . COMPANY_WHATSAPP_INTL . '?text=' . rawurlencode('Hi Auction Hub Group, I have a question');
    return '<p style="margin:0 0 14px;"><a href="' . $url . '" style="display:inline-block;padding:10px 20px;background:#25D366;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:14px;">'
         . e($text) . ' &middot; ' . e(COMPANY_WHATSAPP) . '</a></p>';
}

// ── Capture (previews) and deferred sending ──────────────────

/** Runs $fn with mail captured instead of sent; returns the captured messages. */
function mailCapture(callable $fn): array {
    $GLOBALS['AHG_MAIL_CAPTURE'] = [];
    try { $fn(); } finally { $out = $GLOBALS['AHG_MAIL_CAPTURE']; unset($GLOBALS['AHG_MAIL_CAPTURE']); }
    return $out;
}

/** From now on in this request, mail waits until the visitor has the page. */
function mailDeferUntilAfterResponse(): void {
    if (!empty($GLOBALS['AHG_MAIL_DEFER'])) return;
    $GLOBALS['AHG_MAIL_DEFER'] = true;
    $GLOBALS['AHG_MAIL_QUEUE'] = [];
    register_shutdown_function(function () {
        $queue = $GLOBALS['AHG_MAIL_QUEUE'] ?? [];
        $GLOBALS['AHG_MAIL_DEFER'] = false;
        if (!$queue) return;
        // Hand the page to the visitor first (PHP-FPM / LiteSpeed); plain Apache just sends here.
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        ignore_user_abort(true);
        foreach ($queue as [$mail, $kind, $ref]) _sendMail($mail, $kind, $ref);
    });
}

/**
 * Called by _sendMail(). Returns null to carry on sending, or the bool _sendMail should return.
 * Handles capture, switches, deferral, tracking and reply-to.
 */
function mailBeforeSend(PHPMailer $mail, string $kind, string $ref): ?bool {
    $to = array_key_first($mail->getAllRecipientAddresses()) ?? '';
    if (isset($GLOBALS['AHG_MAIL_CAPTURE'])) {
        $GLOBALS['AHG_MAIL_CAPTURE'][] = ['kind' => $kind, 'to' => $to, 'subject' => $mail->Subject, 'html' => mailTrackLinks($mail->Body, $kind)];
        return true;
    }
    if (!mailKindOn($kind)) { mailLog($kind, $to, $mail->Subject, 'skipped', 'switched off in Email Settings', $ref); return true; }
    if (!empty($GLOBALS['AHG_MAIL_DEFER'])) { $GLOBALS['AHG_MAIL_QUEUE'][] = [$mail, $kind, $ref]; return true; }

    $mail->Body = mailTrackLinks($mail->Body, $kind);
    // Replies go to the department, unless the email chose its own reply-to.
    $replyTos = array_keys($mail->getReplyToAddresses());
    if ($replyTos === [strtolower(MAIL_FROM_EMAIL)] || $replyTos === []) {
        [$deptName, $deptEmail] = mailDepartmentAddress($kind);
        $mail->clearReplyTos();
        $mail->addReplyTo($deptEmail, 'Auction Hub Group ' . $deptName);
    }
    return null;
}

// ── Closing-soon strip (used in outbid, reminders, nudges) ───

/** Up to $limit live lots closing soonest, with photo and current bid, as email HTML. '' when none. */
function mailClosingSoonHtml(int $limit = 3, array $excludeIds = []): string {
    global $pdo;
    try {
        $ex = array_map('intval', $excludeIds ?: [0]);
        $st = $pdo->query("SELECT id, item_name, image_url, current_price, start_price, end_time FROM auctions
                           WHERE status = 'active' AND is_sold = 0 AND end_time > NOW() AND id NOT IN (" . implode(',', $ex) . ")
                           ORDER BY end_time ASC LIMIT " . max(1, min(6, $limit)));
        $lots = $st->fetchAll();
    } catch (Throwable $e) { return ''; }
    if (!$lots) return '';
    $rows = '';
    foreach ($lots as $l) {
        $link = CANONICAL_URL . '/auction.php?id=' . (int)$l['id'];
        $img  = $l['image_url'] ? cldImg($l['image_url'], CLD_THUMB) : '';
        $bid  = (float)$l['current_price'] > 0 ? 'Current bid ' . formatRand((float)$l['current_price']) : 'Opens at ' . formatRand((float)$l['start_price']);
        $rows .= '<tr><td style="padding:8px 10px 8px 0;width:96px;vertical-align:top;">'
               . ($img ? '<a href="' . $link . '"><img src="' . e($img) . '" width="96" alt="" style="border-radius:6px;display:block;"></a>' : '')
               . '</td><td style="padding:8px 0;vertical-align:top;font-size:14px;line-height:1.5;">'
               . '<a href="' . $link . '" style="color:#005BA1;font-weight:bold;text-decoration:none;">' . e($l['item_name']) . '</a><br>'
               . '<span style="color:#555;">' . $bid . ' &middot; closes ' . e(safeDate('D d M H:i', $l['end_time'])) . '</span></td></tr>';
    }
    return '<hr class="divider" style="border:none;border-top:1px solid #e8ecf0;margin:22px 0;">'
         . '<p style="margin:0 0 6px;font-weight:bold;color:#003f73;">⏳ Closing soon</p>'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>';
}

// ── Previews with sample data ────────────────────────────────

/** Builds one kind of email with sample data and returns ['subject','html'] (or null when there is no sample). */
function mailPreview(string $kind): ?array {
    $u = ['id' => 0, 'full_name' => 'Thabo Mokoena', 'email' => 'thabo@example.com', 'phone' => '082 123 4567', 'id_number' => '8001015009087', 'bidder_number' => 'AHG-2026-104'];
    $lotEnd = date('Y-m-d H:i:s', strtotime('+2 days 14:00'));
    $car = '2019 Toyota Hilux 2.4 GD-6 Raider';
    $samples = [
        'welcome'             => fn() => sendWelcomeEmail($u),
        'reg_reminder'        => fn() => sendRegistrationReminderEmail($u, 1),
        'bank_details'        => fn() => sendBankDetailsEmail($u),
        'pop_approved'        => fn() => sendPopApprovedEmail($u, 'AHG-2026-104'),
        'pop_rejected'        => fn() => sendPopRejectedEmail($u, 'The amount on the slip does not match the deposit.'),
        'first_bid_nudge'     => fn() => sendFirstBidNudgeEmail($u),
        'bid_confirm'         => fn() => sendBidConfirmationEmail(['email' => $u['email'], 'name' => $u['full_name'], 'vehicle_name' => $car, 'bid_amount' => 185000, 'current_high_bid' => 185000, 'bid_count' => 7, 'auction_id' => 0, 'auction_end_time' => $lotEnd, 'bidder_number' => 'AHG-2026-104']),
        'outbid_alert'        => fn() => sendOutbidAlertEmail(['email' => $u['email'], 'name' => $u['full_name'], 'vehicle_name' => $car, 'their_bid' => 185000, 'new_high_bid' => 190000, 'auction_id' => 0, 'auction_end_time' => $lotEnd, 'user_id' => 0]),
        'watch_ending'        => fn() => sendWatchEndingEmail(['email' => $u['email'], 'full_name' => $u['full_name'], 'item_name' => $car, 'id' => 0, 'price' => 190000, 'end_time' => $lotEnd, 'user_id' => 0]),
        'auction_winner'      => fn() => sendAuctionWinnerEmail($u, ['id' => 0, 'item_name' => $car], 190000),
        'auction_won'         => fn() => sendAuctionWonEmail(['email' => $u['email'], 'name' => $u['full_name'], 'vehicle_name' => $car, 'winning_amount' => 190000]),
        'review_request'      => fn() => sendReviewRequestEmail($u, ['id' => 0, 'item_name' => $car]),
        'password_reset'      => fn() => sendPasswordResetEmail($u, str_repeat('a', 64)),
        'refund_confirmation' => fn() => sendRefundRequestConfirmation(['email' => $u['email'], 'name' => $u['full_name'], 'refund_id' => 12, 'reference_type' => 'registration', 'amount_paid' => 10000, 'refund_amount' => 10000, 'reference_number' => 'AHG-0-REG', 'bank_name' => 'FNB', 'account_holder' => $u['full_name']]),
        'refund_processed'    => fn() => sendRefundProcessedEmail(['email' => $u['email'], 'name' => $u['full_name'], 'refund_id' => 12, 'refund_amount' => 10000, 'account_holder' => $u['full_name'], 'bank_name' => 'FNB', 'admin_notes' => '']),
        'refund_rejected'     => fn() => sendRefundRejectedEmail(['email' => $u['email'], 'name' => $u['full_name'], 'refund_id' => 12, 'refund_amount' => 10000, 'rejection_reason' => 'The deposit was used toward a purchase.']),
        'bank_request_admin'  => fn() => sendBankRequestAdminAlert($u),
        'pop_admin_alert'     => fn() => sendPopUploadedAdminAlert($u, ['payment_amount' => 10000, 'bank_reference' => 'AHG-0-REG', 'id' => 0]),
    ];
    if (!isset($samples[$kind])) return null;
    $got = mailCapture($samples[$kind]);
    return $got ? ['subject' => $got[0]['subject'], 'html' => $got[0]['html']] : null;
}

/** Counts per kind for the last $days days: kind => [sent, failed, skipped, last_sent_at]. */
function mailStats(int $days = 30): array {
    global $pdo;
    $out = [];
    try {
        $st = $pdo->prepare("SELECT kind, SUM(status='sent') s, SUM(status='failed') f, SUM(status='skipped') k, MAX(CASE WHEN status='sent' THEN created_at END) last
                             FROM email_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY kind");
        $st->execute([$days]);
        foreach ($st->fetchAll() as $r) $out[$r['kind']] = [(int)$r['s'], (int)$r['f'], (int)$r['k'], $r['last']];
    } catch (Throwable $e) {}
    return $out;
}
