<?php
// ============================================================
// AUCTION HUB GROUP — admin_relist.php (admin only)
// Relists vehicles that are NOT sold and either ended/closed with NO bids.
// Vehicles with bids, sold vehicles, and vehicles with an approved or paid
// client application are never listed here, so no bid history is touched.
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

/** Vehicles that can be relisted: unsold, no bids, ended or closed, and not reserved by a client. */
function relistCandidates(PDO $pdo): array {
    return $pdo->query("SELECT a.id, a.item_name, a.year, a.start_price, a.status, a.end_time
        FROM auctions a
        WHERE COALESCE(a.is_sold, 0) = 0
          AND (a.status = 'closed' OR (a.end_time IS NOT NULL AND a.end_time <= NOW()))
          AND NOT EXISTS (SELECT 1 FROM bids b WHERE b.auction_id = a.id)
          AND NOT EXISTS (SELECT 1 FROM client_applications c
                          WHERE c.auction_id = a.id AND c.status IN ('approved','paid','completed'))
        ORDER BY a.id")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Relist the ticked vehicles ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!finCsrfOk()) {
        $message = 'Your session expired. Reload the page and try again.';
        $messageType = 'error';
    } else {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($i) => $i > 0)));
        $start = trim((string)($_POST['start_time'] ?? ''));
        $end   = trim((string)($_POST['end_time'] ?? ''));
        if (!$ids) {
            $message = 'No vehicles were ticked, so nothing changed.';
            $messageType = 'error';
        } elseif (!strtotime($start) || !strtotime($end) || strtotime($end) <= strtotime($start)) {
            $message = 'Set a start time and an end time that is after it.';
            $messageType = 'error';
        } else {
            $start = date('Y-m-d H:i:s', strtotime($start));
            $end   = date('Y-m-d H:i:s', strtotime($end));
            $status = strtotime($start) <= time() ? 'active' : 'upcoming';
            // Re-check every rule at the moment of change, so a sale or bid made since the page loaded is never overwritten.
            $upd = $pdo->prepare("UPDATE auctions
                SET status = ?, start_time = ?, end_time = ?, current_price = start_price,
                    is_sold = 0, final_price = NULL, sold_date = NULL, sold_to = NULL, sold_to_user_id = NULL
                WHERE id = ? AND COALESCE(is_sold, 0) = 0
                  AND (status = 'closed' OR (end_time IS NOT NULL AND end_time <= NOW()))
                  AND NOT EXISTS (SELECT 1 FROM bids b WHERE b.auction_id = auctions.id)
                  AND NOT EXISTS (SELECT 1 FROM client_applications c
                                  WHERE c.auction_id = auctions.id AND c.status IN ('approved','paid','completed'))");
            $done = [];
            foreach ($ids as $id) {
                $upd->execute([$status, $start, $end, $id]);
                if ($upd->rowCount() > 0) $done[] = $id;
            }
            if ($done) {
                try {
                    $log = $pdo->prepare("INSERT INTO admin_activity_log (admin_id, admin_name, action, auction_id, item_name, details) VALUES (?, ?, ?, ?, ?, ?)");
                    $log->execute([(int)$currentUser['sub'], $adminName, 'relist', null, 'Relist with no bids',
                                   implode(',', $done) . ' relisted as ' . ($status === 'active' ? 'Live' : 'Upcoming') . " from $start to $end"]);
                } catch (Throwable $e) { /* activity log is optional */ }
            }
            $skipped = count($ids) - count($done);
            $message = count($done) . ' vehicle(s) relisted as ' . ($status === 'active' ? 'Live' : 'Upcoming') . '.'
                     . ($skipped ? " $skipped skipped: sold, has bids, or reserved by a client." : '');
        }
    }
}

$rows = relistCandidates($pdo);
$csrf = finCsrfToken();
$defStart = date('Y-m-d\TH:i');
$defEnd   = date('Y-m-d\TH:i', strtotime('+7 days'));
header('X-Robots-Tag: noindex, nofollow');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Relist Vehicles — Auction Hub Group Admin</title>
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
  label { display:block; font-size:.85rem; color:#5b6b7f; margin-bottom:4px; }
  input[type=datetime-local] { width:100%; max-width:260px; padding:8px; border:1px solid #c9d3e0; border-radius:6px; font-size:.95rem; }
  .fields { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:12px; }
  .btn { display:inline-block; padding:10px 14px; border-radius:8px; border:0; font-weight:700; cursor:pointer; font-size:.92rem; }
  .btn-primary { background:#005BA1; color:#fff; }
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
  <h1>Relist vehicles</h1>
  <p class="muted">Shows vehicles that are <strong>not sold</strong> and have ended or closed <strong>with no bids</strong>. Vehicles with bids, sold vehicles, and vehicles reserved by a client are never shown here.</p>

  <?php if ($message !== ''): ?>
    <div class="alert alert-<?= e($messageType) ?>" role="alert"><?= e($message) ?></div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="card"><p>There are no vehicles to relist right now.</p></div>
  <?php else: ?>
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="fields">
      <div><label for="start_time">Start</label><input type="datetime-local" id="start_time" name="start_time" value="<?= e($defStart) ?>" required></div>
      <div><label for="end_time">End</label><input type="datetime-local" id="end_time" name="end_time" value="<?= e($defEnd) ?>" required></div>
    </div>
    <table>
      <thead><tr><th style="width:70px">Relist?</th><th>Vehicle</th><th>Year</th><th>Start price</th><th>Was</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
          <td><?= e($r['item_name']) ?> <span class="muted">(#<?= (int)$r['id'] ?>)</span></td>
          <td><?= e($r['year'] ?? '') ?></td>
          <td>R <?= number_format((float)$r['start_price'], 0, '.', ' ') ?></td>
          <td><?= $r['status'] === 'closed' ? 'Closed' : 'Ended ' . e(date('j M Y', strtotime($r['end_time']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin:12px 0 0"><button class="btn btn-primary" type="submit" onclick="return confirm('Relist the ticked vehicles? This clears their status and starts a new listing.');">Relist ticked vehicles</button></p>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
