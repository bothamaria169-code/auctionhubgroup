<?php
// ============================================================
// AUCTION HUB GROUP — user_features.php
// Watchlist, in-site alerts (bell), email preferences and saved
// searches. Tables are created on first use by db_schema.php; if that
// fails (no CREATE permission), every function here is a no-op, so
// pages keep working.
// Requires: config.php (already loaded before this file)
// ============================================================

/** True once the feature tables exist (db_schema.php creates them on first use) */
function ufReady(): bool {
    return ahgSchemaReady();
}

// ── Alerts (bell) ─────────────────────────────────────────────

/**
 * Add an alert for one user. $dedupe (e.g. "won:123") makes a one-off alert
 * idempotent; leave it null for alerts that may repeat (outbid).
 * Returns true only when a new row was written.
 */
function notifyUser(int $userId, string $type, ?int $auctionId, string $title,
                    string $message = '', string $link = '', ?string $dedupe = null): bool {
    global $pdo;
    if (!ufReady() || $userId <= 0) return false;
    try {
        $st = $pdo->prepare("
            INSERT IGNORE INTO user_notifications (user_id, type, auction_id, title, message, link, dedupe_key)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $st->execute([$userId, $type, $auctionId, mb_substr($title, 0, 200), mb_substr($message, 0, 500),
                      $link !== '' ? $link : null, $dedupe]);
        return $st->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('[AHG] notifyUser: ' . $e->getMessage());
        return false;
    }
}

/** "just now", "5 min ago", "3 h ago", "2 d ago", then the date */
function timeAgoShort(?string $when): string {
    $t = $when ? strtotime($when) : false;
    if (!$t) return '';
    $d = time() - $t;
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return floor($d / 60) . ' min ago';
    if ($d < 86400)  return floor($d / 3600) . ' h ago';
    if ($d < 604800) return floor($d / 86400) . ' d ago';
    return date('d M Y', $t);
}

function unreadAlertCount(int $userId): int {
    global $pdo;
    if (!ufReady()) return 0;
    $st = $pdo->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0");
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

/** Winner of a sold lot: alert (the existing winner email is sent separately) */
function notifyLotWon(int $userId, int $auctionId, string $itemName, float $price): void {
    notifyUser($userId, 'won', $auctionId, 'You won: ' . $itemName,
        'Final price ' . formatRand($price) . '. See My Wins for payment and collection steps.',
        'user_dashboard.php?tab=wins', 'won:' . $auctionId);
}

/** Everyone watching a lot that just sold (except the buyer) */
function notifyWatchersSold(int $auctionId, ?int $buyerId = null): void {
    global $pdo;
    if (!ufReady()) return;
    $st = $pdo->prepare("SELECT a.item_name, a.final_price, w.user_id
                         FROM watchlist w JOIN auctions a ON a.id = w.auction_id
                         WHERE w.auction_id = ?");
    $st->execute([$auctionId]);
    foreach ($st->fetchAll() as $r) {
        if ($buyerId && (int)$r['user_id'] === $buyerId) continue;
        notifyUser((int)$r['user_id'], 'watch_sold', $auctionId, 'Sold: ' . $r['item_name'],
            'A lot you were watching sold' . ((float)$r['final_price'] > 0 ? ' for ' . formatRand((float)$r['final_price']) : '')
            . '. Similar vehicles come up every week.',
            'auction.php?id=' . $auctionId, 'sold:' . $auctionId);
    }
}

// ── Watchlist ─────────────────────────────────────────────────

/** IDs this user watches, as a lookup set: [auction_id => true] */
function watchedAuctionIds(?int $userId): array {
    global $pdo;
    if (!$userId || !ufReady()) return [];
    $st = $pdo->prepare("SELECT auction_id FROM watchlist WHERE user_id = ?");
    $st->execute([$userId]);
    return array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
}

/**
 * ♥ Watch button. Works for guests too: the JS sends them to log in.
 * $variant: 'card' (round icon over a photo) or 'panel' (full-width button).
 */
function watchButton(int $auctionId, bool $watching, string $variant = 'card'): string {
    $cls   = 'watch-btn watch-btn-' . $variant . ($watching ? ' is-watching' : '');
    $label = $watching ? 'Watching' : 'Watch';
    $text  = $variant === 'panel' ? '<span class="watch-btn-text">' . $label . '</span>' : '';
    return '<button type="button" class="' . $cls . '" data-watch="' . $auctionId . '"'
         . ' aria-pressed="' . ($watching ? 'true' : 'false') . '"'
         . ' title="' . ($watching ? 'Remove from watchlist' : 'Add to watchlist') . '"'
         . ' aria-label="' . ($watching ? 'Remove from watchlist' : 'Add to watchlist') . '">'
         . '<i class="' . ($watching ? 'fas' : 'far') . ' fa-heart" aria-hidden="true"></i>' . $text . '</button>';
}

// ── Email preferences ─────────────────────────────────────────

const UF_PREF_COLUMNS = [
    'outbid'       => 'outbid_emails',
    'watch'        => 'watch_emails',
    'saved_search' => 'saved_search_emails',
];

/** A user's email switches, creating the row (and unsubscribe token) on first use */
function emailPrefs(int $userId): array {
    global $pdo;
    $all = ['outbid_emails' => 1, 'watch_emails' => 1, 'saved_search_emails' => 1, 'unsubscribe_token' => ''];
    if (!ufReady() || $userId <= 0) return $all;
    try {
        $st = $pdo->prepare("SELECT * FROM user_email_prefs WHERE user_id = ?");
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) {
            $pdo->prepare("INSERT IGNORE INTO user_email_prefs (user_id, unsubscribe_token) VALUES (?, ?)")
                ->execute([$userId, bin2hex(random_bytes(16))]);
            $st->execute([$userId]);
            $row = $st->fetch();
        }
        return $row ?: $all;
    } catch (PDOException $e) {
        error_log('[AHG] emailPrefs: ' . $e->getMessage());
        return $all;
    }
}

function wantsEmail(int $userId, string $kind): bool {
    $col = UF_PREF_COLUMNS[$kind] ?? null;
    return $col ? (bool)(emailPrefs($userId)[$col] ?? 1) : true;
}

/** One-click unsubscribe link for an email of this kind ('all' turns every switch off) */
function unsubscribeUrl(int $userId, string $kind): string {
    $token = emailPrefs($userId)['unsubscribe_token'] ?? '';
    if ($token === '') return CANONICAL_URL . '/user_dashboard.php?tab=profile#email-prefs';
    return CANONICAL_URL . '/unsubscribe.php?t=' . $token . '&k=' . urlencode($kind);
}

/** Footer line added to alert emails */
function unsubscribeFooterHtml(int $userId, string $kind, string $what): string {
    return '<p style="margin-top:28px;font-size:12px;color:#888;">You get these because ' . e($what) . '. '
         . '<a href="' . e(unsubscribeUrl($userId, $kind)) . '" style="color:#888;">Turn these emails off</a> · '
         . '<a href="' . e(CANONICAL_URL . '/user_dashboard.php?tab=profile#email-prefs') . '" style="color:#888;">Email settings</a></p>';
}

// ── Saved searches ────────────────────────────────────────────

/** Keep only filters the auctions page understands, cleaned */
function cleanSearchFilters(array $in): array {
    $f = [];
    if ((int)($in['make_id'] ?? 0) > 0)      $f['make_id']   = (int)$in['make_id'];
    if ((int)($in['year_min'] ?? 0) > 0)     $f['year_min']  = (int)$in['year_min'];
    if ((int)($in['year_max'] ?? 0) > 0)     $f['year_max']  = (int)$in['year_max'];
    if ((float)($in['price_min'] ?? 0) > 0)  $f['price_min'] = (float)$in['price_min'];
    if ((float)($in['price_max'] ?? 0) > 0)  $f['price_max'] = (float)$in['price_max'];
    if (in_array($in['fuel'] ?? '', ['Petrol', 'Diesel', 'Hybrid', 'Electric'], true))  $f['fuel']  = $in['fuel'];
    if (in_array($in['trans'] ?? '', ['Automatic', 'Manual'], true))                      $f['trans'] = $in['trans'];
    $s = mb_substr(trim(strip_tags($in['search'] ?? '')), 0, 80);
    if ($s !== '') $f['search'] = $s;
    return $f;
}

/** SQL conditions for a saved search (same rules as auctions.php) — for lots still for sale */
function savedSearchWhere(array $f): array {
    $w = ["a.status IN ('active','upcoming')", 'a.is_sold = 0'];
    $p = [];
    if (!empty($f['make_id']))   { $w[] = 'a.make_id = ?';  $p[] = $f['make_id']; }
    if (!empty($f['year_min']))  { $w[] = 'a.year >= ?';    $p[] = $f['year_min']; }
    if (!empty($f['year_max']))  { $w[] = 'a.year <= ?';    $p[] = $f['year_max']; }
    if (!empty($f['price_min'])) { $w[] = 'GREATEST(a.current_price, a.start_price) >= ?'; $p[] = $f['price_min']; }
    if (!empty($f['price_max'])) { $w[] = 'GREATEST(a.current_price, a.start_price) <= ?'; $p[] = $f['price_max']; }
    if (!empty($f['fuel']))      { $w[] = 'a.fuel_type = ?';    $p[] = $f['fuel']; }
    if (!empty($f['trans']))     { $w[] = 'a.transmission = ?'; $p[] = $f['trans']; }
    if (!empty($f['search'])) {
        $w[] = '(a.item_name LIKE ? OR m.name LIKE ? OR a.description LIKE ?)';
        $like = '%' . $f['search'] . '%';
        array_push($p, $like, $like, $like);
    }
    return [implode(' AND ', $w), $p];
}

/** "Toyota · 2018+ · up to R250,000 · Diesel" */
function savedSearchLabel(array $f): string {
    global $pdo;
    $parts = [];
    if (!empty($f['search'])) $parts[] = '"' . $f['search'] . '"';
    if (!empty($f['make_id'])) {
        $st = $pdo->prepare("SELECT name FROM makes WHERE id = ?");
        $st->execute([$f['make_id']]);
        if ($n = $st->fetchColumn()) $parts[] = $n;
    }
    if (!empty($f['year_min']) && !empty($f['year_max'])) $parts[] = $f['year_min'] . '–' . $f['year_max'];
    elseif (!empty($f['year_min'])) $parts[] = $f['year_min'] . '+';
    elseif (!empty($f['year_max'])) $parts[] = 'up to ' . $f['year_max'];
    if (!empty($f['price_min']) && !empty($f['price_max'])) $parts[] = 'R' . number_format($f['price_min']) . '–R' . number_format($f['price_max']);
    elseif (!empty($f['price_max'])) $parts[] = 'up to R' . number_format($f['price_max']);
    elseif (!empty($f['price_min'])) $parts[] = 'from R' . number_format($f['price_min']);
    if (!empty($f['fuel']))  $parts[] = $f['fuel'];
    if (!empty($f['trans'])) $parts[] = $f['trans'];
    return $parts ? implode(' · ', $parts) : 'All vehicles';
}

/** auctions.php link that shows the same results */
function savedSearchUrl(array $f): string {
    $q = $f;
    $q['status'] = 'all';
    return 'auctions.php?' . http_build_query($q);
}

// ── Cron jobs (called from cron_close_auctions.php) ───────────

/**
 * Watchlist reminders:
 *   • lot went live in the last 2 hours → alert
 *   • lot ends within the next hour     → alert + email (if the user wants them)
 * The dedupe keys make each fire once per lot per user, so running every
 * 5 minutes is safe.
 */
function runWatchReminders(callable $log): void {
    global $pdo;
    if (!ufReady()) { $log('Watch reminders skipped: run ahg_user_features.sql first.'); return; }

    $live = $pdo->query("
        SELECT w.user_id, a.id, a.item_name, a.end_time
        FROM watchlist w JOIN auctions a ON a.id = w.auction_id
        WHERE a.status = 'active' AND a.is_sold = 0
          AND a.start_time BETWEEN DATE_SUB(NOW(), INTERVAL 2 HOUR) AND NOW()
    ")->fetchAll();
    $n = 0;
    foreach ($live as $r) {
        $n += (int)notifyUser((int)$r['user_id'], 'watch_live', (int)$r['id'], 'Now live: ' . $r['item_name'],
            'Bidding is open' . ($r['end_time'] ? ' until ' . safeDate('d M H:i', $r['end_time']) : '') . '.',
            'auction.php?id=' . (int)$r['id'], 'live:' . (int)$r['id']);
    }

    $ending = $pdo->query("
        SELECT w.user_id, u.email, u.full_name, a.id, a.item_name, a.end_time, a.current_price, a.start_price
        FROM watchlist w
        JOIN auctions a ON a.id = w.auction_id
        JOIN users u    ON u.id = w.user_id AND u.status <> 'suspended'
        WHERE a.status = 'active' AND a.is_sold = 0
          AND a.end_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 HOUR)
    ")->fetchAll();
    $m = 0;
    foreach ($ending as $r) {
        $price = max((float)$r['current_price'], (float)$r['start_price']);
        $isNew = notifyUser((int)$r['user_id'], 'watch_ending', (int)$r['id'], 'Ending soon: ' . $r['item_name'],
            'Ends ' . safeDate('H:i', $r['end_time']) . ' — current price ' . formatRand($price) . '.',
            'auction.php?id=' . (int)$r['id'], 'ending:' . (int)$r['id']);
        if ($isNew && wantsEmail((int)$r['user_id'], 'watch')) {
            try {
                require_once __DIR__ . '/email_helper.php';
                sendWatchEndingEmail($r + ['price' => $price]);
                $m++;
            } catch (\Throwable $e) {
                $log('  Watch email failed: ' . $e->getMessage());
            }
        }
    }
    $log("Watch reminders: $n went-live alert(s), " . count($ending) . " ending lot-watcher pair(s), $m email(s).");
}

/**
 * Saved-search digest: at most one email per search per ~24 hours, listing
 * lots added since the last digest (or the last day, the first time).
 */
function runSavedSearchDigests(callable $log): void {
    global $pdo;
    if (!ufReady()) return;
    $due = $pdo->query("
        SELECT s.*, u.email, u.full_name
        FROM saved_searches s JOIN users u ON u.id = s.user_id AND u.status <> 'suspended'
        WHERE s.last_sent_at IS NULL OR s.last_sent_at <= DATE_SUB(NOW(), INTERVAL 23 HOUR)
    ")->fetchAll();
    $sent = 0;
    foreach ($due as $s) {
        $since = $s['last_sent_at'] ?: date('Y-m-d H:i:s', strtotime('-1 day'));
        $f = json_decode($s['filters'], true) ?: [];
        [$where, $params] = savedSearchWhere($f);
        $st = $pdo->prepare("
            SELECT a.id, a.item_name, a.year, a.mileage, a.image_url, a.status, a.start_time, a.end_time,
                   GREATEST(a.current_price, a.start_price) AS price
            FROM auctions a LEFT JOIN makes m ON m.id = a.make_id
            WHERE $where AND a.created_at > ?
            ORDER BY a.created_at DESC LIMIT 12
        ");
        $st->execute(array_merge($params, [$since]));
        $lots = $st->fetchAll();

        // Mark as handled even when nothing matched, so the window moves on
        $pdo->prepare("UPDATE saved_searches SET last_sent_at = NOW() WHERE id = ?")->execute([$s['id']]);
        if (!$lots || !wantsEmail((int)$s['user_id'], 'saved_search')) continue;

        notifyUser((int)$s['user_id'], 'saved_search', null,
            count($lots) . ' new match' . (count($lots) === 1 ? '' : 'es') . ': ' . $s['label'],
            'New lots matching your saved search.', savedSearchUrl($f));
        try {
            require_once __DIR__ . '/email_helper.php';
            if (sendSavedSearchDigestEmail($s, $f, $lots)) $sent++;
        } catch (\Throwable $e) {
            $log('  Digest email failed: ' . $e->getMessage());
        }
    }
    $log('Saved searches: ' . count($due) . " checked, $sent digest(s) sent.");
}
