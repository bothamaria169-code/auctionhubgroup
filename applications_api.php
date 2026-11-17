<?php
// ============================================================
// AUCTION HUB GROUP — applications_api.php
// Admin actions for Admin → Finance & Pre-Auction (admin_applications_tab.php).
// Plain form posts; every action redirects back to the application.
//   finance:     approve_deposit · counter_deposit · approve · resend
//   pre-auction: pa_invoice (→ preauction_invoices) · pa_approve (Buyer Code)
//   both:        decline · doc · viewing · complete · note · settings
//   finance decline letter: decline_letter (build · save · queue)
//   Outbox (client emails wait for approval): outbox_send · outbox_discard · outbox_retry
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';
require_once __DIR__ . '/leads_helper.php';

$admin = getPageUser();
if (!$admin || ($admin['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !finCsrfOk()) {
    finFlash('error', 'Your session expired — please try again.');
    header('Location: admin_dashboard.php?tab=applications');
    exit;
}
ahgSchemaReady();
$by = (string)($admin['full_name'] ?? 'Office');
$action = (string)($_POST['action'] ?? '');
$note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 2000));

if ($action === 'settings') {
    foreach (['fin_min_deposit', 'fin_min_deposit_heavy', 'pa_min_percent', 'pa_max_percent', 'pa_default_percent',
              'fin_score_a', 'fin_score_b', 'fin_score_c', 'fin_startup_b', 'fin_startup_c', 'fin_startup_d', 'fin_afford_pct',
              'fin_buffer_pct', 'fin_min_income', 'fin_norm_base', 'fin_norm_dependant', 'fin_max_age', 'fin_min_credit_score'] as $k) {
        if (isset($_POST[$k]) && is_numeric($_POST[$k])) leadSaveSetting($k, (string)(float)$_POST[$k]);
    }
    foreach ([6, 12, 24, 36, 48, 60, 72] as $m) {
        $k = 'fin_rate_' . $m;
        if (isset($_POST[$k]) && is_numeric($_POST[$k])) leadSaveSetting($k, (string)max(0, min(20, (float)$_POST[$k])));
    }
    finFlash('success', 'Settings saved. New quotes and invoices use them; existing ones keep their figures.');
    header('Location: admin_dashboard.php?tab=applications&view=settings');
    exit;
}

if ($action === 'letter_template') {
    // Decline letter template + the "what you can do" text for each reason
    $tpl = trim(str_replace("\r\n", "\n", (string)($_POST['tpl_fin_decline_letter'] ?? '')));
    leadSaveSetting('tpl_fin_decline_letter', ($tpl === '' || $tpl === FIN_DECLINE_LETTER_DEFAULT || !empty($_POST['reset'])) ? null : mb_substr($tpl, 0, 12000));
    foreach (FIN_DECLINE_REASONS as $k => [, $default]) {
        if ($k === 'other' || !isset($_POST['fix_' . $k])) continue;
        $fix = trim(preg_replace('/\s+/', ' ', (string)$_POST['fix_' . $k]));
        leadSaveSetting('fin_fix_' . $k, ($fix === '' || $fix === $default || !empty($_POST['reset'])) ? null : mb_substr($fix, 0, 2000));
    }
    finFlash('success', !empty($_POST['reset']) ? 'Decline letter template reset to the AHG default.' : 'Decline letter template saved. New letters use it.');
    header('Location: admin_dashboard.php?tab=applications&view=settings#letter');
    exit;
}

// ── Outbox: client emails wait here until an admin approves them ──
if (in_array($action, ['outbox_send', 'outbox_discard', 'outbox_retry'], true)) {
    $oid = (int)($_POST['outbox_id'] ?? 0);
    $row = outboxRow($oid);
    $back = (string)($_POST['back'] ?? '') === 'app' && $row && $row['app_id'] ? 'admin_dashboard.php?tab=applications&app=' . (int)$row['app_id'] : 'admin_dashboard.php?tab=applications&view=outbox';
    if ($action === 'outbox_send') {
        $err = outboxSend($oid, $by, isset($_POST['subject']) ? (string)$_POST['subject'] : null, isset($_POST['body']) ? (string)$_POST['body'] : null);
        $done = 'Approved and emailed to ' . ($row['to_email'] ?? 'the client') . '.';
    } elseif ($action === 'outbox_discard') {
        $err = outboxDiscard($oid, $by);
        $done = 'Discarded — the client does not receive it.';
    } else {
        $err = outboxRetry($oid);
        $done = 'Back in the Outbox — check it and press Send.';
    }
    if ($err === '') {
        try {
            $pdo->prepare("INSERT INTO admin_activity_log (admin_id, admin_name, action, auction_id, item_name, details) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([(int)$admin['sub'], $by, $action, null, $row['subject'] ?? '', ($row['to_email'] ?? '') . ' #' . $oid]);
        } catch (Throwable $e) { /* optional */ }
    }
    finFlash($err === '' ? 'success' : 'error', e($err === '' ? $done : $err));
    header('Location: ' . $back);
    exit;
}

$app = finGet((int)($_POST['id'] ?? 0));
if (!$app) {
    finFlash('error', 'Application not found.');
    header('Location: admin_dashboard.php?tab=applications');
    exit;
}
$err = '';
$done = '';
$isFin = $app['kind'] === 'finance';

switch ($action) {
    case 'approve_deposit':
        $err = $isFin ? finApproveDeposit($app, $by) : 'Not a finance application.';
        $done = 'Deposit approved — the invoice email is in the Outbox: check it and press Send.';
        break;
    case 'counter_deposit':
        if (!$isFin || !in_array($app['status'], ['review', 'quoted', 'chosen'], true)) { $err = 'A deposit can only be suggested before approval.'; break; }
        $err = finCounterDeposit($app, finMoneyInput((string)($_POST['deposit'] ?? '')), $note, $by);
        $done = 'Updated quote at the suggested deposit is in the Outbox — check it and press Send.';
        break;
    case 'bureau':
        // Office enters the score from the client's credit bureau report → re-score; a higher
        // start-up deposit (or a referred application that now qualifies) gets a new quote.
        if (!$isFin || !in_array($app['status'], ['review', 'quoted', 'chosen'], true)) { $err = 'The bureau score can only be entered before approval.'; break; }
        if (empty($app['details']['finances'])) { $err = 'This application was made on the old form — there is nothing to score.'; break; }
        $score = (int)($_POST['score'] ?? -1);
        if ($score < 0 || $score > 999) { $err = 'Enter the bureau score (0–999).'; break; }
        $bureau = in_array($_POST['bureau'] ?? '', ['TransUnion', 'Experian', 'XDS', 'Compuscan', 'Other'], true) ? $_POST['bureau'] : 'Other';
        $app['details']['bureau'] = ['score' => $score, 'bureau' => $bureau, 'note' => $note, 'by' => $by, 'at' => date('Y-m-d H:i:s')];
        $app['details']['finances']['debt_review'] = !empty($_POST['debt_review']);
        $app['details']['finances']['judgments']   = !empty($_POST['judgments']);
        $before = $app['details']['assessment'] ?? null;
        $oldDeposit = (float)($app['details']['quote']['deposit'] ?? $app['amount']);
        $offered = (float)($app['details']['quote']['offered'] ?? $app['amount']);
        $as = finReassess($app);
        $needs = $as['grade'] !== 'R' ? max($offered, (float)$as['required_deposit']) : $oldDeposit;
        finLog($app, "Bureau score $score ($bureau) entered — re-scored " . ($before['score'] ?? '?') . ' → ' . $as['score'] . '/100, ' . ($as['grade'] === 'R' ? 'referred' : 'grade ' . $as['grade']), $by);
        if (($app['status'] === 'review' && $as['grade'] !== 'R') || abs($needs - $oldDeposit) >= 1) {
            $app['amount'] = $offered;
            finQuoteFromAssessment($app, $offered, false);
            $app['details']['quote']['note'] = $note;
            finSendQuote($app);
            $done = $app['status'] === 'review' ? 'Re-scored — still referred.' : 'Re-scored — new quote at ' . formatRand((float)$app['details']['quote']['deposit']) . ' deposit is in the Outbox for approval.';
        } else {
            finMarkAffordable($app);
            $done = 'Re-scored: ' . $as['score'] . '/100 — the deposit and quote stay the same.';
        }
        break;
    case 'approve':
        $err = $isFin ? finApprove($app, $by) : 'Not a finance application.';
        $done = 'Approved — Buyer Code ' . ($app['buyer_code'] ?? '') . '. The approval email is in the Outbox for you to send.';
        break;
    case 'pa_invoice':
        $err = $isFin ? 'Not a pre-auction request.' : paIssueInvoice($app, (int)($_POST['percent'] ?? 0), $note, (int)$admin['sub'], $by);
        $done = 'Proforma invoice issued (valid 24 hours) — the email is in the Outbox for approval. It is also in the Deal Builder → Pre-Auction.';
        break;
    case 'pa_approve':
        $err = $isFin ? 'Not a pre-auction request.' : paApproveInvoice($app, $note, $by);
        $done = 'Approved — vehicle Presold, Buyer Code ' . ($app['buyer_code'] ?? '') . '. The Buyer Code email is in the Outbox for approval.';
        break;
    case 'decline':
        if (finClosed($app)) { $err = 'This application is already closed.'; break; }
        $reasons = array_map('strval', (array)($_POST['reasons'] ?? []));
        if ($isFin) {
            if (!$reasons && $note === '') { $err = 'Tick at least one reason (or write one).'; break; }
            finDecline($app, $note, $by, $reasons);
            // Start the decline letter straight away from the template
            finDeclineLetterSave($app, finDeclineLetterBuild($app, $reasons, $note), $reasons, $by);
            $done = 'Declined. Nothing has been emailed yet — check the decline letter below, then put it in the Outbox.';
        } else {
            $err = paDecline($app, $note, $by);
            $done = 'Declined — the notice to the client is in the Outbox for approval.';
        }
        break;
    case 'decline_letter':
        if (!$isFin || $app['status'] !== 'declined') { $err = 'Decline the finance application first.'; break; }
        $mode = (string)($_POST['mode'] ?? 'save');
        $reasons = array_map('strval', (array)($_POST['reasons'] ?? []));
        if ($mode === 'build') {
            $err = finDeclineLetterSave($app, finDeclineLetterBuild($app, $reasons, $note), $reasons, $by);
            $done = 'Letter rebuilt from the template — check it, then put it in the Outbox.';
        } else {
            $err = finDeclineLetterSave($app, (string)($_POST['letter'] ?? ''), $reasons, $by);
            if ($err === '' && $mode === 'queue') {
                $err = finDeclineLetterQueue($app, $by);
                $done = 'Decline letter is in the Outbox — review it there and press Send to email it.';
            } else {
                $done = 'Letter saved (draft — not sent).';
            }
        }
        break;
    case 'doc':
        $kind = (string)($_POST['doc'] ?? '');
        $status = (string)($_POST['status'] ?? '');
        if (!$isFin || !isset($app['details']['docs'][$kind]) || !in_array($status, ['accepted', 'rejected'], true)) { $err = 'Unknown document.'; break; }
        $app['details']['docs'][$kind]['status'] = $status;
        $app['details']['docs'][$kind]['note'] = $status === 'rejected' ? $note : '';
        finLog($app, FIN_DOCS[$kind][0] . ' ' . $status . ($status === 'rejected' && $note !== '' ? ': ' . $note : ''), $by);
        if ($status === 'rejected') {
            $ok = finMailClient($app, 'Please Re-upload Your ' . FIN_DOCS[$kind][0],
                '<p>Thank you for your documents. We need your <strong>' . e(FIN_DOCS[$kind][0]) . '</strong> again, please.</p>'
                . ($note !== '' ? '<div class="highlight-box"><p>' . nl2br(e($note)) . '</p></div>' : '')
                . '<p>Upload a new copy on your application page — it only takes a minute.</p>', 'Upload Again', 'documents');
            finRecordMail($app, 'Re-upload requested: ' . FIN_DOCS[$kind][0], $ok);
        }
        $done = FIN_DOCS[$kind][0] . ' ' . $status . ($status === 'rejected' ? ' — the re-upload request is in the Outbox for approval.' : '.');
        break;
    case 'viewing':
        $err = finConfirmViewing($app, (string)($_POST['date'] ?? ''), substr((string)($_POST['time'] ?? ''), 0, 5), $note, $by);
        $done = 'Test drive confirmed — the email is in the Outbox for approval.';
        break;
    case 'complete':
        if (!in_array($app['status'], ['approved', 'paid'], true)) { $err = 'Only an approved / Presold application can be completed.'; break; }
        $app['status'] = 'completed';
        $app['details']['completed_at'] = date('Y-m-d H:i:s');
        finLog($app, 'Completed' . ($note !== '' ? ': ' . $note : ''), $by);
        $done = 'Completed. Remember to mark the lot sold on the Auctions tab (sale channel: pre-auction / private).';
        break;
    case 'resend':
        if ($isFin && !empty($app['details']['quote'])) { finSendQuote($app); $done = 'Quote put in the Outbox again — check it and press Send.'; }
        else $err = 'Nothing to resend at this stage.';
        break;
    case 'note':
        if ($note === '') { $err = 'Write a note first.'; break; }
        $app['details']['notes'][] = ['at' => date('Y-m-d H:i:s'), 'by' => $by, 'text' => $note];
        $done = 'Note saved.';
        break;
    default:
        $err = 'Unknown action.';
}

if ($err === '') {
    finSave($app);
    try {
        $pdo->prepare("INSERT INTO admin_activity_log (admin_id, admin_name, action, auction_id, item_name, details) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([(int)$admin['sub'], $by, 'fin_' . $action, $app['auction_id'], $app['vehicle'], finRef($app) . ' ' . $app['name']]);
    } catch (Throwable $e) { /* activity log is optional */ }
    finFlash('success', e($done));
} else {
    finFlash('error', e($err));
}
header('Location: admin_dashboard.php?tab=applications&app=' . (int)$app['id']);
exit;
