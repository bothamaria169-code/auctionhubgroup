<?php
// ============================================================
// AUCTION HUB GROUP — outbox_helper.php
// Client documents are never emailed automatically. Every email of
// a kind in OUTBOX_HOLD_KINDS (quotes, invoices, decline letters,
// Buyer Codes, refund and winner letters…) is stopped in
// _sendMail() and kept in `client_outbox` until an admin opens
// Admin → Finance & Pre-Auction → Outbox, checks (and edits) it
// and presses Send — or discards it.
// Account and security emails (password reset, welcome, bids,
// outbid) and office alerts still go straight out.
// Loaded by email_helper.php.
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;

/** Email kinds (the _sendMail context) that wait for an admin. */
const OUTBOX_HOLD_KINDS = [
    'fin_finance'      => 'Finance application',
    'fin_preauction'   => 'Pre-auction request',
    'buyer_code'       => 'Pre-auction Buyer Code',
    'declination'      => 'Pre-auction declined',
    'refund_processed' => 'Refund paid',
    'refund_rejected'  => 'Refund declined',
    'auction_winner'   => 'You won (auction close)',
    'auction_won'      => 'You won (manual sale)',
];

/** Should this kind of email wait for approval? */
function outboxShouldHold(string $kind): bool {
    return isset(OUTBOX_HOLD_KINDS[$kind]) && empty($GLOBALS['AHG_OUTBOX_SENDING']) && !isset($GLOBALS['AHG_MAIL_CAPTURE']);
}

/** Extra context for the next held email (application, editable body, tag). Cleared after use. */
function outboxContext(array $ctx): void {
    $GLOBALS['AHG_OUTBOX_CTX'] = $ctx;
}

/** Stores the message instead of sending it. Returns true (the caller treats it as done). */
function outboxHold(PHPMailer $mail, string $kind): bool {
    global $pdo;
    $ctx = $GLOBALS['AHG_OUTBOX_CTX'] ?? [];
    unset($GLOBALS['AHG_OUTBOX_CTX']);
    $to = $mail->getToAddresses();
    if (!$to) return false;
    $extra = ['cc' => $mail->getCcAddresses(), 'bcc' => $mail->getBccAddresses(), 'reply_to' => array_values($mail->getReplyToAddresses()),
              'attachments' => array_values(array_filter(array_map(fn($a) => empty($a[5]) && is_file($a[0]) ? ['path' => $a[0], 'name' => $a[1]] : null, $mail->getAttachments())))];
    try {
        $pdo->prepare("INSERT INTO client_outbox (kind, tag, app_id, to_email, to_name, extra, subject, title, body_inner, body_html, alt_body, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$kind, $ctx['tag'] ?? null, $ctx['app_id'] ?? null, $to[0][0], $to[0][1] ?: null, json_encode($extra),
                       mb_substr((string)$mail->Subject, 0, 255), isset($ctx['title']) ? mb_substr($ctx['title'], 0, 255) : null,
                       $ctx['inner'] ?? null, (string)$mail->Body, (string)$mail->AltBody, outboxActor()]);
        $GLOBALS['AHG_OUTBOX_HELD'] = (int)$pdo->lastInsertId();
        $GLOBALS['AHG_OUTBOX_HELD_COUNT'] = ($GLOBALS['AHG_OUTBOX_HELD_COUNT'] ?? 0) + 1;
        return true;
    } catch (Throwable $e) {
        // Never lose a client email: if the outbox table is unavailable, fall back to sending.
        error_log('[AHG outbox] hold failed, sending instead: ' . $e->getMessage());
        return false;
    }
}

/** Who caused the email (admin name, or "System" for cron / client actions). */
function outboxActor(): string {
    if (function_exists('getPageUser')) {
        try { $u = getPageUser(); if (($u['role'] ?? '') === 'admin') return (string)($u['full_name'] ?? 'Admin'); } catch (Throwable $e) {}
    }
    return PHP_SAPI === 'cli' ? 'System (cron)' : 'System';
}

/** Id of the email held by the last send in this request (0 = it was sent, not held). Reading it clears it. */
function outboxTakeHeld(): int {
    $id = (int)($GLOBALS['AHG_OUTBOX_HELD'] ?? 0);
    unset($GLOBALS['AHG_OUTBOX_HELD']);
    return $id;
}

/** How many emails this request put in the outbox. */
function outboxHeldCount(): int {
    return (int)($GLOBALS['AHG_OUTBOX_HELD_COUNT'] ?? 0);
}

function outboxRow(int $id): ?array {
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM client_outbox WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Pending emails (all, or for one application). */
function outboxPending(?int $appId = null): array {
    global $pdo;
    try {
        if ($appId) { $st = $pdo->prepare("SELECT * FROM client_outbox WHERE status = 'pending' AND app_id = ? ORDER BY id"); $st->execute([$appId]); }
        else $st = $pdo->query("SELECT * FROM client_outbox WHERE status = 'pending' ORDER BY id");
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function outboxPendingCount(): int {
    global $pdo;
    try { return (int)$pdo->query("SELECT COUNT(*) FROM client_outbox WHERE status = 'pending'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** Recently decided emails, for the outbox history. */
function outboxRecent(int $limit = 30): array {
    global $pdo;
    try { return $pdo->query("SELECT id, kind, tag, app_id, to_email, to_name, subject, status, error, created_at, decided_by, decided_at FROM client_outbox WHERE status <> 'pending' ORDER BY decided_at DESC LIMIT " . max(1, min(200, $limit)))->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/**
 * Approve and send a held email. $subject / $body are the admin's edits (null = unchanged);
 * $body edits the inner content when the email has one, otherwise the whole HTML.
 * Returns '' on success or an error message.
 */
function outboxSend(int $id, string $by, ?string $subject = null, ?string $body = null): string {
    global $pdo;
    $row = outboxRow($id);
    if (!$row) return 'Email not found.';
    if ($row['status'] !== 'pending') return 'This email was already ' . $row['status'] . '.';
    $subject = $subject !== null && trim($subject) !== '' ? mb_substr(trim($subject), 0, 255) : $row['subject'];
    $html = $row['body_html'];
    if ($body !== null && trim($body) !== '') {
        if ($row['body_inner'] !== null) {
            if (trim($body) !== trim($row['body_inner'])) $html = _emailTemplate($row['title'] ?: $subject, $body);
        } else $html = $body;
    }
    $extra = json_decode((string)$row['extra'], true) ?: [];
    try {
        $mail = _buildMailer();
        $mail->addAddress($row['to_email'], (string)$row['to_name']);
        foreach ($extra['cc'] ?? [] as $a) $mail->addCC($a[0], $a[1] ?? '');
        foreach ($extra['bcc'] ?? [] as $a) $mail->addBCC($a[0], $a[1] ?? '');
        if (!empty($extra['reply_to'])) { $mail->clearReplyTos(); foreach ($extra['reply_to'] as $a) $mail->addReplyTo($a[0], $a[1] ?? ''); }
        foreach ($extra['attachments'] ?? [] as $a) if (is_file($a['path'])) $mail->addAttachment($a['path'], $a['name']);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $html !== $row['body_html'] ? trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>'], "\n", $html)))) : (string)$row['alt_body'];
        $GLOBALS['AHG_OUTBOX_SENDING'] = true;
        try { $ok = _sendMail($mail, $row['kind']); } finally { unset($GLOBALS['AHG_OUTBOX_SENDING']); }
    } catch (Throwable $e) {
        $ok = false;
        error_log('[AHG outbox] send: ' . $e->getMessage());
    }
    $pdo->prepare("UPDATE client_outbox SET status = ?, error = ?, subject = ?, body_html = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")
        ->execute([$ok ? 'sent' : 'failed', $ok ? null : 'The mail server did not accept the email.', $subject, $html, $by, $id]);
    outboxAfter($row, $ok ? 'sent' : 'failed', $by);
    return $ok ? '' : 'The email could not be sent — check the mail settings and try again from the Outbox history.';
}

/** Discard a held email — the client never receives it. */
function outboxDiscard(int $id, string $by): string {
    global $pdo;
    $row = outboxRow($id);
    if (!$row) return 'Email not found.';
    if ($row['status'] !== 'pending' && $row['status'] !== 'failed') return 'This email was already ' . $row['status'] . '.';
    $pdo->prepare("UPDATE client_outbox SET status = 'discarded', decided_by = ?, decided_at = NOW() WHERE id = ?")->execute([$by, $id]);
    outboxAfter($row, 'discarded', $by);
    return '';
}

/** Put a failed email back in the queue so it can be sent again. */
function outboxRetry(int $id): string {
    global $pdo;
    $row = outboxRow($id);
    if (!$row || $row['status'] !== 'failed') return 'Only a failed email can be tried again.';
    $pdo->prepare("UPDATE client_outbox SET status = 'pending', error = NULL WHERE id = ?")->execute([$id]);
    return '';
}

/** Note the decision on the application's history (and mark a decline letter as sent). */
function outboxAfter(array $row, string $status, string $by): void {
    if (empty($row['app_id']) || !function_exists('finGet')) return;
    $app = finGet((int)$row['app_id']);
    if (!$app) return;
    $what = ['sent' => 'Approved & emailed', 'failed' => 'Approved but the email FAILED', 'discarded' => 'Discarded (not sent)'][$status] . ': ' . $row['subject'];
    finLog($app, $what, $by);
    $app['details']['emails'][] = ['at' => date('Y-m-d H:i:s'), 'what' => $what, 'ok' => $status !== 'failed'];
    if (in_array($row['tag'], ['decline_letter', 'new_quotation'], true)) {
        if ($status === 'sent') $app['details']['declined']['letter']['sent_at'] = date('Y-m-d H:i:s');
        else unset($app['details']['declined']['letter']['queued_at']);
    }
    if ($row['tag'] === 'new_quotation' && $status === 'sent') $app['details']['declined']['proposed']['sent_at'] = date('Y-m-d H:i:s');
    finSave($app);
}

function outboxKindLabel(string $kind): string {
    return OUTBOX_HOLD_KINDS[$kind] ?? ucfirst(str_replace('_', ' ', $kind));
}
