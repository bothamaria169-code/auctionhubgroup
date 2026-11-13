<?php
// ============================================================
// AUCTION HUB GROUP — applications_api.php
// Admin actions for Admin → Finance & Pre-Auction (admin_applications_tab.php).
// Plain form posts; every action redirects back to the application.
//   finance:    approve_deposit · counter_deposit · approve (deposit paid) · complete
//   preauction: pa_approve · pa_counter · pa_paid · complete
//   both:       decline · doc (accept / reject) · viewing · resend · note · settings
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
    foreach (['fin_min_deposit', 'fin_min_deposit_heavy', 'pa_min_percent', 'pa_max_percent', 'pa_default_percent'] as $k) {
        if (isset($_POST[$k]) && is_numeric($_POST[$k])) leadSaveSetting($k, (string)(float)$_POST[$k]);
    }
    foreach ([6, 12, 24, 36, 48, 60, 72] as $m) {
        $k = 'fin_rate_' . $m;
        if (isset($_POST[$k]) && is_numeric($_POST[$k])) leadSaveSetting($k, (string)max(0, min(20, (float)$_POST[$k])));
    }
    finFlash('success', 'Finance and pre-auction settings saved. New quotations use them; existing ones keep their figures.');
    header('Location: admin_dashboard.php?tab=applications&view=settings');
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
        if (!$isFin) break;
        $err = finApproveDeposit($app, $by);
        $done = 'Deposit approved — the deposit invoice has gone to the client.';
        break;
    case 'counter_deposit':
        if (!$isFin || !in_array($app['status'], ['quoted', 'chosen'], true)) { $err = 'A deposit can only be suggested before the invoice.'; break; }
        $err = finCounterDeposit($app, finMoneyInput((string)($_POST['deposit'] ?? '')), $note, $by);
        $done = 'Suggested deposit sent with an updated quotation and deposit letter.';
        break;
    case 'approve':
        if (!$isFin) break;
        $err = finApprove($app, $by);
        $done = 'Approved — approval letter and Buyer\'s Code ' . ($app['buyer_code'] ?? '') . ' sent.';
        break;
    case 'pa_approve':
    case 'pa_counter':
        if ($isFin) break;
        $err = paDecide($app, $action === 'pa_counter' ? 'counter' : 'approve', (int)($_POST['percent'] ?? 0), $note, $by);
        $done = $action === 'pa_counter' ? 'Suggested percentage sent — the client accepts it on their page.' : 'Approved — the commitment letter has gone to the client to confirm.';
        break;
    case 'pa_paid':
        if ($isFin) break;
        $err = paMarkPaid($app, $by);
        $done = 'Marked paid — Buyer\'s Code ' . ($app['buyer_code'] ?? '') . ' letter sent.';
        break;
    case 'decline':
        if (finClosed($app)) { $err = 'This application is already closed.'; break; }
        finDecline($app, $note, $by);
        $done = 'Declined — the letter of decline has gone to the client.';
        break;
    case 'doc':
        $kind = (string)($_POST['doc'] ?? '');
        $status = (string)($_POST['status'] ?? '');
        if (!isset($app['details']['docs'][$kind]) || !in_array($status, ['accepted', 'rejected'], true)) { $err = 'Unknown document.'; break; }
        $app['details']['docs'][$kind]['status'] = $status;
        $app['details']['docs'][$kind]['note'] = $status === 'rejected' ? $note : '';
        finLog($app, FIN_DOCS[$kind][0] . ' ' . $status . ($status === 'rejected' && $note !== '' ? ': ' . $note : ''), $by);
        if ($status === 'rejected') {
            $ok = finMailClient($app, 'Please upload your ' . strtolower(FIN_DOCS[$kind][0]) . ' again',
                '<p>Thank you for your documents. We need your <strong>' . e(FIN_DOCS[$kind][0]) . '</strong> again, please.</p>'
                . ($note !== '' ? '<div class="highlight-box"><p>' . nl2br(e($note)) . '</p></div>' : '')
                . '<p>Upload a new copy on your application page — it only takes a minute.</p>', 'Upload it again', 'documents');
            finRecordMail($app, 'Asked again for ' . FIN_DOCS[$kind][0], $ok);
        }
        $done = FIN_DOCS[$kind][0] . ' ' . $status . '.';
        break;
    case 'viewing':
        $err = finConfirmViewing($app, (string)($_POST['date'] ?? ''), (string)($_POST['time'] ?? ''), $note, $by);
        $done = 'Test drive confirmed and emailed to the client.';
        break;
    case 'complete':
        if (!in_array($app['status'], ['approved', 'paid'], true)) { $err = 'Only an approved / paid application can be completed.'; break; }
        $app['status'] = 'completed';
        $app['details']['completed_at'] = date('Y-m-d H:i:s');
        finLog($app, 'Completed' . ($note !== '' ? ': ' . $note : ''), $by);
        $done = 'Marked completed. Remember to mark the lot sold on the Auctions tab (sale channel: pre-auction / private).';
        break;
    case 'resend':
        $what = (string)($_POST['what'] ?? '');
        if ($what === 'quote' && $isFin && !empty($app['details']['quote'])) { finSendQuote($app); $done = 'Quotation emailed again.'; }
        elseif ($what === 'letter' && !$isFin && $app['status'] === 'approved') { paApproved($app, $by); $done = 'Commitment letter emailed again.'; }
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
    } catch (Throwable $e) { /* log table optional */ }
    finFlash('success', e($done));
} else {
    finFlash('error', e($err));
}
header('Location: admin_dashboard.php?tab=applications&app=' . (int)$app['id']);
exit;
