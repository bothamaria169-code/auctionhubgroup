<?php
// ============================================================
// AUCTION HUB GROUP — admin_split_stock.php (admin only)
// Splits the live (active, unsold) vehicles into two halves. The system picks a
// random half and shows it pre-ticked. An admin reviews the picks, changes any
// they want, and confirms. Only ticked vehicles that are still active and unsold
// move to "coming soon" (status = upcoming). Sold vehicles are never listed.
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';

// ── Admin auth guard (same as admin_dashboard.php) ──────────
$token = getTokenFromRequest();
if (!$token) redirect('login.php', 'Admin access required.', 'error');
$currentUser = verifyJWT($token);
if (!$currentUser || $currentUser['role'] !== 'admin') redirect('user_dashboard.php', 'Access denied.', 'error');
syncSession($currentUser);

global $pdo;
$adminName = $currentUser['full_name'] ?? ($currentUser['name'] ?? 'Admin');
$message = '';
$messageType = 'success';

/** The live vehicles the split applies to: active and not sold. */
function splitLiveRows(PDO $pdo): array {
    return $pdo->query("SELECT id, item_name, year, start_price, current_price, end_time
                        FROM auctions WHERE status = 'active' AND COALESCE(is_sold, 0) = 0
                        ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Confirm: move the ticked vehicles to coming soon ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!finCsrfOk()) {
        $message = 'Your session expired. Reload the page and try again.';
        $messageType = 'error';
    } else {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($i) => $i > 0)));
        if (!$ids) {
            $message = 'No vehicles were ticked, so nothing changed.';
            $messageType = 'error';
        } else {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE auctions SET status = 'upcoming'
                                   WHERE id IN ($marks) AND status = 'active' AND COALESCE(is_sold, 0) = 0");
            $stmt->execute($ids);
            $moved = $stmt->rowCount();
            try {
                $log = $pdo->prepare("INSERT INTO admin_activity_log (admin_id, admin_name, action, auction_id, item_name, details) VALUES (?, ?, ?, ?, ?, ?)");
                $log->execute([(int)$currentUser['sub'], $adminName, 'split_stock', null, 'Live stock split',
                               $moved . ' vehicle(s) moved to coming soon: ' . implode(',', $ids)]);
            } catch (Throwable $e) { /* activity log is optional */ }
            $message = "$moved vehicle(s) moved to coming soon. Vehicles that were not ticked stay live.";
        }
    }
}

// ── The random half (same seed = same picks on reload) ───────
$rows = splitLiveRows($pdo);
$total = count($rows);
$seed = isset($_GET['seed']) ? (int)$_GET['seed'] : (int)($_POST['seed'] ?? random_int(1, 2147483646));
$picks = [];
if ($total > 0) {
    $ids = array_column($rows, 'id');
    mt_srand($seed);
    $shuffled = $ids;
    shuffle($shuffled);
    $picks = array_slice($shuffled, 0, (int)ceil($total / 2));
}
$checked = $message !== '' && $messageType === 'success' ? [] : array_flip(array_map('intval', $picks));

$csrf = finCsrfToken();
header('X-Robots-Tag: noindex, nofollow');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Split Live Stock — Auction Hub Group Admin</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; background:#f4f6fb; color:#1a1a1a; margin:0; }
  .wrap { max-width: 960px; margin: 0 auto; padding: 16px; }
  h1 { font-size: 1.35rem; color:#001F5C; margin: 8px 0 4px; }
  .muted { color:#5b6b7f; font-size:.9rem; }
  .card { background:#fff; border:1px solid #dde4ee; border-radius:10px; padding:14px; margin:14px 0; }
  .alert { padding:10px 12px; border-radius:8px; margin:12px 0; font-size:.92rem; }
  .alert-success { background:#e8f7ee; border:1px solid #9bd7b1; color:#14532d; }
  .alert-error { background:#fdecec; border:1px solid #f0a3a3; color:#7a1212; }
  table { width:100%; border-collapse:collapse; font-size:.9rem; }
  th, td { padding:8px 6px; border-bottom:1px solid #eef2f7; text-align:left; vertical-align:middle; }
  th { color:#5b6b7f; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
  .btn { display:inline-block; padding:10px 14px; border-radius:8px; border:0; font-weight:700; cursor:pointer; text-decoration:none; font-size:.92rem; }
  .btn-primary { background:#005BA1; color:#fff; }
  .btn-outline { background:#fff; color:#005BA1; border:1px solid #005BA1; }
  .row-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:10px; }
  @media (max-width:640px) {
    table, thead, tbody, tr, td, th { display:block; }
    thead { display:none; }
    tr { border-bottom:1px solid #eef2f7; padding:8px 0; }
    td { border:0; padding:3px 0; }
  }
</style>
</head>
<body>
<div class="wrap">
  <h1>Split live stock into two groups</h1>
  <p class="muted">The system has picked a random half of the live vehicles (ticked below). Review the picks, change any you want, then confirm. Ticked vehicles move to <strong>coming soon</strong>. Sold vehicles are never shown.</p>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?= e($messageType) ?>" role="alert"><?= e($message) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Live vehicles, not sold:</strong> <?= $total ?> &nbsp;·&nbsp; <strong>Picked for coming soon:</strong> <?= count($picks) ?>
    <div class="row-actions">
      <a class="btn btn-outline" href="?seed=<?= random_int(1, 2147483646) ?>">Pick a different random half</a>
    </div>
  </div>

  <?php if ($total === 0): ?>
    <div class="card"><p>There are no live vehicles that are unsold, so there is nothing to split.</p></div>
  <?php else: ?>
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="seed" value="<?= (int)$seed ?>">
    <table>
      <thead><tr><th style="width:70px">Coming soon?</th><th>Vehicle</th><th>Year</th><th>Start price</th><th>Current bid</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $id = (int)$r['id']; $on = isset($checked[$id]); ?>
        <tr>
          <td><input type="checkbox" name="ids[]" value="<?= $id ?>" <?= $on ? 'checked' : '' ?>></td>
          <td><?= e($r['item_name']) ?> <span class="muted">(#<?= $id ?>)</span></td>
          <td><?= e($r['year'] ?? '') ?></td>
          <td>R <?= number_format((float)$r['start_price'], 0, '.', ' ') ?></td>
          <td>R <?= number_format((float)($r['current_price'] ?? 0), 0, '.', ' ') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="row-actions">
      <button class="btn btn-primary" type="submit" onclick="return confirm('Move the ticked vehicles to coming soon?');">Confirm — move ticked vehicles to coming soon</button>
      <span class="muted">Unticked vehicles stay live.</span>
    </div>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
