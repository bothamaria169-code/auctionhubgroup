<?php
// ============================================================
// AUCTION HUB GROUP — my_applications.php
// "Find my applications": signed-in clients see theirs; anyone else enters
// the email they applied with and we email the private links there (never
// shown on screen, so nobody can look up someone else's application).
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';
require_once __DIR__ . '/leads_helper.php';

ahgSchemaReady();
$pageUser = getPageUser();
$mine = $pageUser ? finForEmail((string)$pageUser['email']) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    if (!finCsrfOk()) {
        finFlash('error', 'Your session expired — please try again.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        finFlash('error', 'Please enter the email address you applied with.');
    } elseif (enquiryRateLimited(5, 900)) {
        finFlash('error', 'Too many tries — please wait a few minutes, or call ' . e(COMPANY_PHONE) . '.');
    } else {
        $apps = finForEmail($email);
        if ($apps) {
            $list = '';
            foreach ($apps as $a) {
                $list .= '<li><a href="' . e(finLink($a)) . '">' . e(($a['kind'] === 'finance' ? 'In-house finance' : 'Pre-auction') . ' — ' . $a['vehicle']) . '</a> (' . e(finRef($a)) . ', ' . e(finStatusLabel($a)) . ')</li>';
            }
            try {
                $mail = _buildMailer();
                $mail->addAddress($email, $apps[0]['name']);
                $mail->Subject = 'Your Auction Hub Group applications';
                $mail->Body = _emailTemplate('Your applications', '<p>Here are the private links to your applications:</p><ul>' . $list . '</ul><p style="font-size:13px;color:#666">Did not ask for this? You can ignore this email.</p>');
                $mail->AltBody = 'Your applications: ' . implode(' ', array_map('finLink', $apps));
                _sendMail($mail, 'fin_lookup');
            } catch (Throwable $e) { error_log('[AHG my_applications] ' . $e->getMessage()); }
        }
        // Same answer either way, so the form cannot be used to test whose email has applied.
        finFlash('success', 'If we have applications under <strong>' . e($email) . '</strong>, the links are on their way to that inbox now.');
    }
    header('Location: my_applications.php');
    exit;
}

$pageTitle = 'Find my applications — Finance & Pre-Auction';
$pageDescription = 'Track your Auction Hub Group in-house finance application or pre-auction request.';
$noIndex = true;
require_once __DIR__ . '/header.php';
require __DIR__ . '/fin_ui.php';
?>
<section class="ap-hero" style="padding:2.6rem 1.25rem 2.2rem">
  <h1>Find my applications</h1>
  <p>In-house finance applications and pre-auction requests — see where each one stands.</p>
</section>
<div class="ap-wrap" style="max-width:760px">
  <?= finFlashHtml() ?>
  <?php if ($mine): ?>
    <div class="ap-card"><h2>Your applications</h2>
      <table class="ap-table"><tr><th>Application</th><th>Status</th><th></th></tr>
      <?php foreach ($mine as $a): ?>
        <tr><td><strong><?= e($a['vehicle']) ?></strong><br><span class="ap-help"><?= $a['kind'] === 'finance' ? 'In-house finance' : 'Pre-auction' ?> · <?= e(finRef($a)) ?> · <?= safeDate('j M Y', $a['created_at']) ?></span></td>
          <td><?= e(finStatusLabel($a)) ?></td><td><a class="ap-btn ap-btn-sm" href="<?= e(finLink($a)) ?>">Open</a></td></tr>
      <?php endforeach; ?></table></div>
  <?php endif; ?>
  <form class="ap-card" method="post">
    <input type="hidden" name="csrf" value="<?= e(finCsrfToken()) ?>">
    <h2><?= $mine ? 'Applied with another email?' : 'Email me my links' ?></h2>
    <p class="ap-help" style="margin-bottom:.8rem">Enter the email you applied with. For your privacy we send the links to that inbox rather than showing them here.</p>
    <div class="ap-row"><input class="ap-input" type="email" name="email" required placeholder="you@example.com" style="flex:1;min-width:220px" value="<?= e($pageUser['email'] ?? '') ?>">
      <button class="ap-btn" type="submit"><i class="fas fa-envelope"></i> Send my links</button></div>
  </form>
  <div class="ap-row"><a class="ap-btn ap-btn-out ap-btn-sm" href="<?= APP_URL ?>/finance_apply.php">Apply for finance</a>
    <a class="ap-btn ap-btn-out ap-btn-sm" href="<?= APP_URL ?>/preauction_request.php">Reserve before auction</a></div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
