<?php
// ============================================================
// AUCTION HUB GROUP — admin_bid_desk.php (admin only)
// Bid desk for the bidders we bid for (floor / phone bidders created by admin).
// Every live lot is a photo card with its gallery, current bid, next bid, time left
// and who is leading. Pick the bidder once (kept for the session, last few one tap
// away), then tap Next bid, +2 steps or type an amount.
//
// Bids go through api.php → admin_place_bid exactly like the Manual Bids page:
// minimum bid check, outbid alert to the previous leader, bid confirmation email.
// Public bid lists show only the bidder's name and number.
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/vehicle_strip.php';   // lotTitle()

// ── Admin auth guard (same as admin_dashboard.php) ──────────
$token = getTokenFromRequest();
if (!$token) redirect('login.php', 'Admin access required.', 'error');
$currentUser = verifyJWT($token);
if (!$currentUser || $currentUser['role'] !== 'admin') redirect('user_dashboard.php', 'Access denied.', 'error');
syncSession($currentUser);
global $pdo;

// ── Who we are bidding for: picked once, kept for the session ──
if (isset($_GET['bidder'])) {
    $_SESSION['desk_bidder'] = max(0, (int)$_GET['bidder']);
}
if (isset($_GET['channel']) && in_array($_GET['channel'], ['phone', 'floor', 'whatsapp'], true)) {
    $_SESSION['desk_channel'] = $_GET['channel'];
}
$bidderId = (int)($_SESSION['desk_bidder'] ?? 0);
$channel  = $_SESSION['desk_channel'] ?? 'phone';
$recentIds = array_values(array_filter(array_map('intval', (array)($_SESSION['desk_recent'] ?? []))));
if ($bidderId > 0) {
    $recentIds = array_slice(array_values(array_unique(array_merge([$bidderId], $recentIds))), 0, 6);
    $_SESSION['desk_recent'] = $recentIds;
}

// Floor bidders (verified, created by admin) for the picker
$bidders = [];
try {
    $bidders = $pdo->query("SELECT id, full_name, bidder_number, phone FROM users
                            WHERE is_floor_bidder = 1 AND bidder_status = 'verified_bidder'
                            ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$byId = array_column($bidders, null, 'id');
$bidder = $byId[$bidderId] ?? null;
if (!$bidder) $bidderId = 0;
$recent = array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $recentIds)));

// ── Live lots ────────────────────────────────────────────────
$q = trim((string)($_GET['q'] ?? ''));
$where = "a.status = 'active' AND COALESCE(a.is_sold, 0) = 0 AND (a.end_time IS NULL OR a.end_time > NOW())";
$params = [];
if ($q !== '') {
    $where .= " AND (a.item_name LIKE ? OR m.name LIKE ? OR a.year LIKE ? OR a.id = ?)";
    $params = ["%$q%", "%$q%", "%$q%", (int)$q];
}
$lots = [];
try {
    $st = $pdo->prepare("
        SELECT a.id, a.item_name, a.year, a.mileage, a.image_url, a.start_price, a.current_price,
               a.min_increment, a.end_time, m.name AS make_name,
               (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) AS bid_count,
               (SELECT CONCAT(b.user_id, '|', COALESCE(b.bidder_name, ''), '|', COALESCE(b.bidder_number, ''))
                  FROM bids b WHERE b.auction_id = a.id ORDER BY b.bid_amount DESC, b.id DESC LIMIT 1) AS leader
        FROM auctions a LEFT JOIN makes m ON m.id = a.make_id
        WHERE $where
        ORDER BY (a.end_time IS NULL), a.end_time ASC, a.id ASC
        LIMIT 120");
    $st->execute($params);
    $lots = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('bid desk lots: ' . $e->getMessage());
}

// Gallery photos (up to 6 per lot)
$photos = [];
if ($lots) {
    try {
        $ids = array_map('intval', array_column($lots, 'id'));
        $g = $pdo->query("SELECT auction_id, file_path FROM auction_gallery
                          WHERE auction_id IN (" . implode(',', $ids) . ") AND file_path <> ''
                          ORDER BY auction_id, is_primary DESC, sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($g as $r) {
            if (count($photos[$r['auction_id']] ?? []) < 6) $photos[$r['auction_id']][] = $r['file_path'];
        }
    } catch (Throwable $e) {}
}

/** Lot figures the card and buttons need. */
function deskFigures(array $l): array {
    $cur  = (float)$l['current_price'];
    $step = max(1.0, (float)$l['min_increment']);
    $next = $cur > 0 ? $cur + $step : (float)$l['start_price'];
    [$uid, $name, $num] = array_pad(explode('|', (string)$l['leader'], 3), 3, '');
    return ['cur' => $cur, 'step' => $step, 'next' => $next, 'next2' => $next + $step,
            'leader_id' => (int)$uid, 'leader' => trim($name . ($num !== '' ? ' · ' . $num : '')),
            'left' => $l['end_time'] ? max(0, strtotime($l['end_time']) - time()) : null];
}
$R = fn($n) => 'R ' . number_format((float)$n, 0, '.', ' ');
$noImg = APP_URL . '/assets/no-image.jpg';

header('X-Robots-Tag: noindex, nofollow');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Bid Desk — Auction Hub Group Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; background:#f4f6fb; color:#1a1a1a; margin:0; }
  .wrap { max-width: 1280px; margin: 0 auto; padding: 14px; }
  .top { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
  h1 { font-size: 1.35rem; color:#001F5C; margin: 4px 0; }
  .muted { color:#5b6b7f; font-size:.88rem; }
  a.back { color:#005BA1; font-weight:700; text-decoration:none; font-size:.9rem; }

  /* Bidding-for bar: sticks to the top so the chosen bidder is always visible */
  .forbar { position:relative; background:#001F5C; color:#fff; border-radius:12px; padding:12px 14px; margin:12px 0; box-shadow:0 6px 18px rgba(0,31,92,.25); }
  .forbar .who { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .forbar .label { font-size:.72rem; letter-spacing:.08em; text-transform:uppercase; color:#9fc3ff; }
  .forbar .name { font-size:1.15rem; font-weight:800; }
  .forbar .num { background:#fbbf24; color:#001F5C; font-weight:800; border-radius:6px; padding:2px 8px; font-size:.85rem; }
  .forbar form { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
  .forbar select, .forbar input { min-height:44px; border-radius:8px; border:0; padding:0 10px; font-size:.95rem; }
  .forbar select { flex:1 1 260px; }
  .chips { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
  .chip { display:inline-flex; align-items:center; min-height:36px; padding:0 12px; border-radius:999px; background:rgba(255,255,255,.12); color:#fff; text-decoration:none; font-size:.85rem; font-weight:600; }
  .chip.on { background:#fff; color:#001F5C; }
  .chip.add { background:transparent; border:1px dashed rgba(255,255,255,.5); }

  .tools { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:6px 0 12px; }
  .tools input { flex:1 1 220px; min-height:44px; border:1px solid #cdd7e4; border-radius:8px; padding:0 12px; font-size:.95rem; }
  .btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:44px; padding:0 14px; border-radius:8px; border:0; font-weight:700; cursor:pointer; text-decoration:none; font-size:.92rem; }
  .btn-primary { background:#005BA1; color:#fff; }
  .btn-outline { background:#fff; color:#005BA1; border:1px solid #005BA1; }
  .btn-gold { background:#fbbf24; color:#001F5C; }
  .btn[disabled] { opacity:.45; cursor:not-allowed; }

  .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:14px; }
  .lot { background:#fff; border:1px solid #dde4ee; border-radius:12px; overflow:hidden; display:flex; flex-direction:column; scroll-margin-top:140px; }
  .lot.leading { border:2px solid #22c55e; }
  .photo { position:relative; aspect-ratio:16/10; background:#e8edf5; }
  .photo img { width:100%; height:100%; object-fit:cover; display:block; }
  .badge { position:absolute; top:8px; left:8px; background:rgba(0,31,92,.85); color:#fff; font-size:.75rem; font-weight:700; padding:4px 8px; border-radius:6px; }
  .timer { position:absolute; top:8px; right:8px; background:#fff; color:#001F5C; font-weight:800; font-size:.85rem; padding:4px 8px; border-radius:6px; }
  .timer.soon { background:#ef4444; color:#fff; }
  .thumbs { display:flex; gap:4px; padding:6px; overflow-x:auto; background:#f7f9fc; }
  .thumbs button { flex:none; width:56px; height:40px; padding:0; border:2px solid transparent; border-radius:5px; overflow:hidden; cursor:pointer; background:#ddd; }
  .thumbs button.on { border-color:#005BA1; }
  .thumbs img { width:100%; height:100%; object-fit:cover; display:block; }
  .body { padding:10px 12px 12px; display:flex; flex-direction:column; gap:8px; flex:1; }
  .title { font-weight:800; color:#001F5C; font-size:1rem; line-height:1.3; }
  .meta { color:#5b6b7f; font-size:.8rem; }
  .figs { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
  .fig { background:#f4f7fb; border-radius:8px; padding:6px 8px; }
  .fig small { display:block; color:#5b6b7f; font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; }
  .fig b { font-size:1.05rem; color:#001F5C; }
  .lead { font-size:.85rem; padding:6px 8px; border-radius:8px; background:#fff7e0; color:#7a5300; }
  .lead.us { background:#e8f7ee; color:#14532d; font-weight:700; }
  .bids { display:grid; grid-template-columns:1fr 1fr; gap:6px; margin-top:auto; }
  .own { display:flex; gap:6px; grid-column:1 / -1; }
  .own input { flex:1; min-width:0; min-height:44px; border:1px solid #cdd7e4; border-radius:8px; padding:0 10px; font-size:.95rem; }
  .msg { font-size:.85rem; min-height:1em; }
  .msg.ok { color:#14532d; } .msg.err { color:#b91c1c; }
  .empty { background:#fff; border:1px dashed #cdd7e4; border-radius:12px; padding:30px; text-align:center; color:#5b6b7f; }
  @media (min-width:700px) { .forbar { position:sticky; top:0; z-index:20; } }
  .forbar.compact form, .forbar.compact .chips { display:none; }
  @media (max-width:480px) { .wrap { padding:10px; } .grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <h1><i class="fas fa-gavel"></i> Bid Desk</h1>
      <div class="muted">Bid for the bidders we bid for. Bids show only the bidder's name and number.</div>
    </div>
    <a class="back" href="admin_dashboard.php?tab=manual_bid"><i class="fas fa-arrow-left"></i> Manual Bids &amp; Floor</a>
  </div>

  <!-- Bidding for -->
  <div class="forbar" id="forbar">
    <div class="who">
      <span class="label">Bidding for</span>
      <?php if ($bidder): ?>
        <span class="name"><?= e($bidder['full_name']) ?></span>
        <span class="num"><?= e($bidder['bidder_number']) ?></span>
        <?php if ($bidder['phone']): ?><a class="chip" href="tel:<?= e(preg_replace('/\s+/', '', $bidder['phone'])) ?>"><i class="fas fa-phone"></i>&nbsp;<?= e($bidder['phone']) ?></a><?php endif; ?>
      <?php else: ?>
        <span class="name">Choose a bidder below</span>
      <?php endif; ?>
    </div>
    <form method="get">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <select name="bidder" aria-label="Bidder" onchange="this.form.submit()">
        <option value="0">— Choose a floor / phone bidder —</option>
        <?php foreach ($bidders as $b): ?>
          <option value="<?= (int)$b['id'] ?>"<?= (int)$b['id'] === $bidderId ? ' selected' : '' ?>><?= e($b['full_name'] . ' · ' . $b['bidder_number']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="channel" aria-label="How the bid reached us" onchange="this.form.submit()">
        <?php foreach (['phone' => 'By phone', 'floor' => 'On the floor', 'whatsapp' => 'By WhatsApp'] as $k => $l): ?>
          <option value="<?= $k ?>"<?= $k === $channel ? ' selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <div class="chips">
      <?php foreach ($recent as $r): ?>
        <a class="chip<?= (int)$r['id'] === $bidderId ? ' on' : '' ?>" href="?bidder=<?= (int)$r['id'] ?><?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>"><?= e($r['full_name']) ?></a>
      <?php endforeach; ?>
      <a class="chip add" href="admin_dashboard.php?tab=manual_bid"><i class="fas fa-user-plus"></i>&nbsp;Add a floor bidder</a>
    </div>
  </div>

  <form class="tools" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Find a lot: make, model, year or lot number">
    <button class="btn btn-outline" type="submit"><i class="fas fa-search"></i> Find</button>
    <?php if ($q !== ''): ?><a class="btn btn-outline" href="admin_bid_desk.php">Show all</a><?php endif; ?>
    <button class="btn btn-outline" type="button" onclick="location.reload()"><i class="fas fa-rotate"></i> Refresh</button>
    <span class="muted"><?= count($lots) ?> live lot<?= count($lots) === 1 ? '' : 's' ?></span>
  </form>

  <?php if (!$lots): ?>
    <div class="empty"><i class="fas fa-car"></i><br>No live lots<?= $q !== '' ? ' match "' . e($q) . '"' : ' right now' ?>.</div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($lots as $l):
        $f = deskFigures($l);
        $id = (int)$l['id'];
        $imgs = $photos[$id] ?? [];
        if (!$imgs && !empty($l['image_url'])) $imgs = [$l['image_url']];
        $main = $imgs ? cldImg(imgHost($imgs[0]), CLD_CARD) : $noImg;
        $usLead = $bidderId > 0 && $f['leader_id'] === $bidderId;
        $title = lotTitle($l);
    ?>
    <article class="lot<?= $usLead ? ' leading' : '' ?>" id="lot-<?= $id ?>"
             data-id="<?= $id ?>" data-title="<?= e($title) ?>" data-next="<?= $f['next'] ?>" data-step="<?= $f['step'] ?>">
      <div class="photo">
        <img src="<?= e($main) ?>" alt="<?= e($title) ?>" loading="lazy" class="main-img" onerror="this.src='<?= e($noImg) ?>'">
        <span class="badge">Lot <?= $id ?> · <?= (int)$l['bid_count'] ?> bid<?= (int)$l['bid_count'] === 1 ? '' : 's' ?></span>
        <?php if ($f['left'] !== null): ?><span class="timer" data-left="<?= (int)$f['left'] ?>">…</span><?php endif; ?>
      </div>
      <?php if (count($imgs) > 1): ?>
      <div class="thumbs">
        <?php foreach ($imgs as $i => $src): ?>
          <button type="button" class="<?= $i === 0 ? 'on' : '' ?>" data-src="<?= e(cldImg(imgHost($src), CLD_CARD)) ?>" aria-label="Photo <?= $i + 1 ?>">
            <img src="<?= e(cldImg(imgHost($src), 160)) ?>" alt="" loading="lazy">
          </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="body">
        <div>
          <div class="title"><?= e($title) ?></div>
          <div class="meta"><?= $l['mileage'] ? number_format((int)$l['mileage'], 0, '.', ' ') . ' km · ' : '' ?>Step <?= $R($f['step']) ?></div>
        </div>
        <div class="figs">
          <div class="fig"><small><?= $f['cur'] > 0 ? 'Current bid' : 'Starting bid' ?></small><b><?= $R($f['cur'] > 0 ? $f['cur'] : $l['start_price']) ?></b></div>
          <div class="fig"><small>Next bid</small><b><?= $R($f['next']) ?></b></div>
        </div>
        <?php if ($f['leader'] !== ''): ?>
          <div class="lead<?= $usLead ? ' us' : '' ?>"><?= $usLead ? '<i class="fas fa-check-circle"></i> Your bidder is leading' : 'Leading: ' . e($f['leader']) ?></div>
        <?php else: ?>
          <div class="lead">No bids yet</div>
        <?php endif; ?>
        <div class="bids">
          <button class="btn btn-primary js-bid" type="button" data-amount="<?= $f['next'] ?>"<?= $bidderId ? '' : ' disabled' ?>>Bid <?= $R($f['next']) ?></button>
          <button class="btn btn-gold js-bid" type="button" data-amount="<?= $f['next2'] ?>"<?= $bidderId ? '' : ' disabled' ?>><?= $R($f['next2']) ?> <small>(+2)</small></button>
          <div class="own">
            <input type="number" inputmode="numeric" min="<?= $f['next'] ?>" step="<?= $f['step'] ?>" placeholder="Own amount" aria-label="Own amount"<?= $bidderId ? '' : ' disabled' ?>>
            <button class="btn btn-outline js-own" type="button"<?= $bidderId ? '' : ' disabled' ?>>Bid</button>
          </div>
        </div>
        <div class="msg" role="status"></div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var bidderId = <?= (int)$bidderId ?>;
  var bidderName = <?= json_encode($bidder ? $bidder['full_name'] . ' (' . $bidder['bidder_number'] . ')' : '') ?>;
  var channel = <?= json_encode(['phone' => 'phone', 'floor' => 'floor', 'whatsapp' => 'WhatsApp'][$channel]) ?>;
  var R = function (n) { return 'R ' + Math.round(n).toLocaleString('en-ZA').replace(/,/g, ' '); };

  // Gallery thumbnails
  document.querySelectorAll('.thumbs button').forEach(function (b) {
    b.addEventListener('click', function () {
      var lot = b.closest('.lot');
      lot.querySelector('.main-img').src = b.dataset.src;
      lot.querySelectorAll('.thumbs button').forEach(function (x) { x.classList.toggle('on', x === b); });
    });
  });

  // Countdown
  var start = Date.now();
  function tick() {
    document.querySelectorAll('.timer').forEach(function (t) {
      var s = Math.max(0, parseInt(t.dataset.left, 10) - Math.floor((Date.now() - start) / 1000));
      var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), sec = s % 60;
      t.textContent = s === 0 ? 'Closed' : d > 0 ? d + 'd ' + h + 'h' : h > 0 ? h + 'h ' + m + 'm' : m + 'm ' + (sec < 10 ? '0' : '') + sec + 's';
      t.classList.toggle('soon', s > 0 && s < 300);
    });
  }
  tick(); setInterval(tick, 1000);

  // Place a bid through the same endpoint as Manual Bids
  function place(lot, amount, btn) {
    var msg = lot.querySelector('.msg');
    if (!bidderId) { msg.className = 'msg err'; msg.textContent = 'Choose who you are bidding for first.'; return; }
    if (!(amount > 0)) { msg.className = 'msg err'; msg.textContent = 'Enter an amount.'; return; }
    if (!confirm('Bid ' + R(amount) + ' for ' + bidderName + ' on ' + lot.dataset.title + '?')) return;
    var fd = new FormData();
    fd.append('action', 'admin_place_bid');
    fd.append('auction_id', lot.dataset.id);
    fd.append('user_id', bidderId);
    fd.append('amount', amount);
    fd.append('note', 'Bid desk: ' + channel);
    var buttons = lot.querySelectorAll('button.js-bid, button.js-own');
    buttons.forEach(function (b) { b.disabled = true; });
    msg.className = 'msg'; msg.textContent = 'Placing bid…';
    fetch('api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        msg.className = 'msg ' + (d.success ? 'ok' : 'err');
        msg.textContent = d.message || (d.success ? 'Bid placed.' : 'The bid did not go through.');
        if (d.success) setTimeout(function () { location.hash = 'lot-' + lot.dataset.id; location.reload(); }, 900);
        else buttons.forEach(function (b) { b.disabled = false; });
      })
      .catch(function () {
        msg.className = 'msg err'; msg.textContent = 'No connection. Check the bid list before trying again.';
        buttons.forEach(function (b) { b.disabled = false; });
      });
  }
  document.querySelectorAll('.js-bid').forEach(function (b) {
    b.addEventListener('click', function () { place(b.closest('.lot'), parseFloat(b.dataset.amount), b); });
  });
  document.querySelectorAll('.js-own').forEach(function (b) {
    b.addEventListener('click', function () {
      var lot = b.closest('.lot');
      place(lot, parseFloat(lot.querySelector('.own input').value), b);
    });
  });

  // Keep figures fresh: reload every 30 seconds unless someone is typing or a bid is in flight
  setInterval(function () {
    var busy = document.activeElement && document.activeElement.tagName === 'INPUT';
    var placing = Array.prototype.some.call(document.querySelectorAll('.msg'), function (m) { return m.textContent === 'Placing bid…'; });
    if (!busy && !placing && !document.hidden) location.reload();
  }, 30000);
})();
</script>
</body>
</html>
