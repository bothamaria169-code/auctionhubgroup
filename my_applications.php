<?php
// ============================================================
// AUCTION HUB GROUP — my_applications.php
// "Track my application": signed-in clients see theirs; anyone else enters
// the email they applied with and the private links are emailed there
// (never shown on screen, so nobody can look up someone else's).
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
                $list .= '<p><strong>' . e(($a['kind'] === 'finance' ? 'Finance' : 'Pre-Auction Purchase') . ' — ' . $a['vehicle']) . '</strong><br>'
                       . e(finRef($a) . ' · ' . finStatusLabel($a)) . '<br><a href="' . e(finLink($a)) . '">View this application</a></p>';
            }
            try {
                $mail = _buildMailer();
                $mail->addAddress($email, $apps[0]['name']);
                $mail->Subject = 'Your Auction Hub Group Applications';
                $mail->Body = _emailTemplate('Your Applications', '<p>Dear ' . e($apps[0]['name']) . ',</p><p>Here are the private links to your applications:</p><div class="highlight-box">' . $list . '</div><p style="font-size:13px;color:#888;">Did not ask for this? You can safely ignore this email.</p>');
                $mail->AltBody = 'Your applications: ' . implode(' ', array_map('finLink', $apps));
                _sendMail($mail, 'fin_lookup');
            } catch (Throwable $e) { error_log('[AHG my_applications] ' . $e->getMessage()); }
        }
        // Same answer either way, so the form cannot reveal who has applied.
        finFlash('success', 'If we have applications under <strong>' . e($email) . '</strong>, the links are on their way to that inbox now.');
    }
    header('Location: my_applications.php');
    exit;
}

$pageTitle = 'Track My Application — Finance & Pre-Auction';
$pageDescription = 'Track your Auction Hub Group finance application or pre-auction purchase.';
$noIndex = true;
require_once __DIR__ . '/header.php';
require __DIR__ . '/fin_ui.php';
finHero('<i class="fas fa-route"></i> Your Applications', 'Track My Application', 'Finance applications and pre-auction purchases — see exactly where each one stands.', 'Track My Application');
?>
<section class="ap-section"><div class="ap-container" style="max-width:820px">
  <?= finFlashHtml() ?>
  <?php if ($mine): ?>
    <div class="ap-card"><div class="ap-card-head"><i class="fas fa-list-check"></i> Your Applications</div><div class="ap-card-body">
      <?php foreach ($mine as $a): ?>
        <div class="ap-doc"><div class="ap-doc-head">
          <div><strong><?= e($a['vehicle']) ?></strong><div class="ap-help" style="margin:0"><?= $a['kind'] === 'finance' ? 'Finance' : 'Pre-Auction Purchase' ?> · <?= e(finRef($a)) ?> · <?= safeDate('j M Y', $a['created_at']) ?></div>
            <div style="margin-top:.35rem"><?= finBadge($a) ?></div></div>
          <a class="btn btn-primary btn-sm" href="<?= e(finLink($a)) ?>">Open <i class="fas fa-arrow-right"></i></a></div></div>
      <?php endforeach; ?>
    </div></div>
  <?php endif; ?>
  <form class="ap-card" method="post">
    <div class="ap-card-head"><i class="fas fa-envelope"></i> <?= $mine ? 'Applied With Another Email?' : 'Email Me My Links' ?></div>
    <div class="ap-card-body">
      <input type="hidden" name="csrf" value="<?= e(finCsrfToken()) ?>">
      <p class="ap-help" style="margin:0 0 1rem">Enter the email you applied with. For your privacy we send the links to that inbox rather than showing them here.</p>
      <div class="ap-row"><input class="form-control" type="email" name="email" required placeholder="you@example.com" style="flex:1;min-width:220px" value="<?= e($pageUser['email'] ?? '') ?>">
        <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i> Send My Links</button></div>
    </div>
  </form>
  <div class="ap-row" style="justify-content:center">
    <a class="btn btn-outline" href="<?= APP_URL ?>/finance_apply.php"><i class="fas fa-hand-holding-dollar"></i> Apply for Finance</a>
    <a class="btn btn-outline" href="<?= APP_URL ?>/preauction_request.php"><i class="fas fa-key"></i> Pre-Auction Purchase</a>
  </div>
</div></section>
<?php require_once __DIR__ . '/footer.php'; ?>
