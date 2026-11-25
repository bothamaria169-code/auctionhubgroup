<?php
// ============================================================
// AUCTION HUB GROUP — confirm_terms.php?t=TOKEN
// The pre-auction client reads the commitment-fee letter and confirms it.
// Only after this confirmation can the office issue the proforma invoice
// (paIssueInvoice in finance_helper.php checks paTermsConfirmed()).
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';

$app = finFind((string)($_GET['t'] ?? $_POST['t'] ?? ''));
if (!$app || $app['kind'] !== 'preauction') { http_response_code(404); exit('This page is not available.'); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!finCsrfOk()) {
        $error = 'Your session expired. Please reload this page and confirm again.';
    } elseif (!paTermsConfirmed($app)) {
        if (!in_array($app['status'], ['requested', 'expired'], true)) {
            $error = 'This request has already moved on. Please contact us if you need help.';
        } else {
            $app['details']['terms_confirmed_at'] = date('Y-m-d H:i:s');
            $app['details']['terms_confirmed_ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
            finLog($app, 'Confirmed the commitment-fee letter and asked for the invoice');
            finSave($app);
            finMailOffice($app, 'Client confirmed the commitment fee — issue the proforma invoice');
        }
    }
    if ($error === '') {
        header('Location: ' . APP_URL . '/confirm_terms.php?t=' . urlencode((string)$app['token']) . '&done=1');
        exit;
    }
}

$confirmed = paTermsConfirmed($app);
$pageTitle       = 'Confirm Your Commitment Fee — Auction Hub Group';
$pageDescription = 'Read and confirm the commitment-fee terms for your pre-auction purchase before your invoice is issued.';
$bodyClass       = 'page-confirm-terms';
require_once __DIR__ . '/header.php';
?>
<section class="ap-section"><div class="ap-container" style="max-width:860px">
  <span class="ap-label"><i class="fas fa-file-signature"></i> Commitment fee</span>
  <h2 class="ap-title">Confirm your commitment fee</h2>

  <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><i class="fas fa-circle-xmark"></i><span><?= e($error) ?></span></div><?php endif; ?>

  <?php if ($confirmed || isset($_GET['done'])): ?>
    <div class="ap-card"><div class="ap-card-body">
      <p><i class="fas fa-circle-check" style="color:#16a34a"></i> <strong>Thank you. You have confirmed the commitment-fee terms.</strong></p>
      <p class="ap-sub" style="margin:0">Our team will now send your proforma invoice. It is valid for 24 hours, so watch your email. You can follow each step on <a href="<?= e(finLink($app)) ?>">your request page</a>.</p>
    </div></div>
  <?php else: ?>
    <div class="ap-card"><div class="ap-card-body">
      <?= paTermsLetterHtml($app) ?>
    </div></div>
    <form method="post" action="<?= e(APP_URL) ?>/confirm_terms.php" class="ap-card" style="margin-top:1.5rem">
      <div class="ap-card-body">
        <input type="hidden" name="csrf" value="<?= e(finCsrfToken()) ?>">
        <input type="hidden" name="t" value="<?= e($app['token']) ?>">
        <p class="ap-sub" style="margin:0 0 1rem">By confirming, you say you have read and understood the commitment fee, what it is for, and what it means for you.</p>
        <div class="ap-row">
          <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Confirm and proceed with my invoice</button>
          <a class="btn btn-outline" href="<?= e(APP_URL) ?>/register-to-bid.php"><i class="fas fa-gavel"></i> Not ready? Register and bid instead</a>
        </div>
      </div>
    </form>
  <?php endif; ?>
</div></section>
<?php require_once __DIR__ . '/footer.php'; ?>
