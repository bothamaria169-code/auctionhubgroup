<?php
// ============================================================
// AUCTION HUB GROUP — unsubscribe.php
// One-click email opt-out from the links in alert emails:
//   unsubscribe.php?t=<unsubscribe_token>&k=outbid|watch|saved_search|all
// GET shows a confirm button; only the POST changes anything, so mail
// scanners that open every link can't unsubscribe people by accident.
// ============================================================

require_once 'config.php';

$kinds = [
    'outbid'       => 'outbid alerts',
    'watch'        => 'watchlist reminders',
    'saved_search' => 'saved-search emails',
    'all'          => 'all alert emails',
];
$token = preg_match('/^[a-f0-9]{32}$/', $_REQUEST['t'] ?? '') ? $_REQUEST['t'] : '';
$kind  = array_key_exists($_REQUEST['k'] ?? '', $kinds) ? $_REQUEST['k'] : 'all';

$row = null;
if ($token && ufReady()) {
    $st = $pdo->prepare("SELECT p.user_id, u.email FROM user_email_prefs p JOIN users u ON u.id = p.user_id WHERE p.unsubscribe_token = ?");
    $st->execute([$token]);
    $row = $st->fetch();
}

$done = false;
if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cols = $kind === 'all' ? array_values(UF_PREF_COLUMNS) : [UF_PREF_COLUMNS[$kind]];
    $pdo->prepare("UPDATE user_email_prefs SET " . implode(', ', array_map(fn($c) => "$c = 0", $cols)) . " WHERE user_id = ?")
        ->execute([$row['user_id']]);
    $done = true;
}

// Mask the address: jo***@gmail.com
$masked = '';
if ($row) {
    [$local, $domain] = array_pad(explode('@', $row['email'], 2), 2, '');
    $masked = mb_substr($local, 0, 2) . '***@' . $domain;
}

$pageTitle       = 'Email Settings';
$pageDescription = 'Manage the alert emails you receive from Auction Hub Group.';
$noIndex         = true;
require_once 'header.php';
?>
<section class="section" style="padding:4rem 1rem;">
  <div class="container" style="max-width:560px;">
    <div class="card" style="text-align:center;padding:2.25rem 1.5rem;">
      <?php if (!$row): ?>
        <i class="fas fa-link-slash" style="font-size:2.4rem;color:#c0cbd8;margin-bottom:1rem;"></i>
        <h1 style="font-size:1.4rem;margin:0 0 .5rem;">This link has expired</h1>
        <p class="text-muted">Log in and open <strong>My Profile → Email Settings</strong> to choose which emails you get.</p>
        <a href="user_dashboard.php?tab=profile#email-prefs" class="btn btn-primary" style="margin-top:1rem;">Email Settings</a>
      <?php elseif ($done): ?>
        <i class="fas fa-circle-check" style="font-size:2.4rem;color:#2e7d32;margin-bottom:1rem;"></i>
        <h1 style="font-size:1.4rem;margin:0 0 .5rem;">You're unsubscribed</h1>
        <p class="text-muted">We'll stop sending <?= e($kinds[$kind]) ?> to <?= e($masked) ?>.
          Account emails (registration, invoices, wins) still arrive.</p>
        <a href="user_dashboard.php?tab=profile#email-prefs" class="btn btn-outline" style="margin-top:1rem;">Change Email Settings</a>
      <?php else: ?>
        <i class="fas fa-envelope-open-text" style="font-size:2.4rem;color:#005BA1;margin-bottom:1rem;"></i>
        <h1 style="font-size:1.4rem;margin:0 0 .5rem;">Stop <?= e($kinds[$kind]) ?>?</h1>
        <p class="text-muted">For <?= e($masked) ?>. You can switch them back on any time in your dashboard.</p>
        <form method="post" style="margin-top:1.25rem;">
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <input type="hidden" name="k" value="<?= e($kind) ?>">
          <button type="submit" class="btn btn-primary"><i class="fas fa-bell-slash"></i> Yes, unsubscribe</button>
        </form>
        <?php if ($kind !== 'all'): ?>
          <form method="post" style="margin-top:.75rem;">
            <input type="hidden" name="t" value="<?= e($token) ?>">
            <input type="hidden" name="k" value="all">
            <button type="submit" class="btn btn-outline btn-sm">Unsubscribe from all alert emails</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php require_once 'footer.php'; ?>
