<?php
// ============================================================
// AUCTION HUB GROUP — api.php
// Central AJAX hub — all JS fetch() calls route here
// Endpoint: api.php?action=<action_name>  (GET or POST)
//
// Actions (18 + 2 bonus):
//   GET  (public):  updates, ticker, announcements
//   POST (user):    place_bid, upload_pop, update_profile
//   POST (admin):   approve_pop, reject_pop,
//                   delete_auction, update_auction_status,
//                   update_auction_times, set_all_auction_times,
//                   delete_user, update_user_status,
//                   send_email, send_bulk_email,
//                   set_primary_image, delete_gallery_image,
//                   reorder_gallery
//   GET  (admin):   get_auction_stats
// ============================================================

// Output buffering: captures any accidental PHP warnings/notices
// so they never corrupt the JSON response.
ob_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

// Only allow AJAX requests (X-Requested-With or Accept: application/json)
// Relaxed check — mobile browsers sometimes omit the header
$action = trim($_REQUEST['action'] ?? '');

if (!$action) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'No action specified.']));
}

// ── Response helpers ──────────────────────────────────────────

function ok(array|string $data = [], array $extra = []): void {
    ob_end_clean();
    // String form: ok('Saved.', ['id' => 5]) → {success, message, id}
    if (is_string($data)) $data = array_merge(['message' => $data], $extra);
    echo json_encode(imgHostDeep(array_merge(['success' => true], $data)));
    exit;
}

function fail(string $message, int $code = 200): void {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

require_once __DIR__ . '/auction_close.php';

function adminOnly(): array {
    global $pdo;
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required.']);
        exit;
    }
    return $user;
}

// ── Rate limiter (file-based, per IP) ────────────────────────
function rateLimit(string $key, int $maxRequests, int $windowSec): void {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $dir  = sys_get_temp_dir() . '/pva_rate_limit';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);

    $file = $dir . '/' . md5($key . ':' . $ip) . '.json';
    $now  = time();
    $data = [];

    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: [];
        // Prune entries outside the window
        $data = array_values(array_filter($data, fn($t) => $t > $now - $windowSec));
    }

    if (count($data) >= $maxRequests) {
        http_response_code(429);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please slow down.']);
        exit;
    }

    $data[] = $now;
    file_put_contents($file, json_encode($data), LOCK_EX);
}

// ── Admin change log ──────────────────────────────────────────
// Writes one row to admin_activity_log (ahg_admin_activity_log.sql).
// Returns false instead of throwing when the table is missing, so
// ordinary actions keep working on a server where the SQL hasn't run;
// pass $required = true to make the caller fail instead.
function logAdminAction(array $admin, string $action, ?int $auctionId, array $details = [], bool $required = false): bool {
    global $pdo;
    ahgSchemaReady(); // creates admin_activity_log on first use
    try {
        $name = null;
        if ($auctionId) {
            $s = $pdo->prepare("SELECT item_name FROM auctions WHERE id = ?");
            $s->execute([$auctionId]);
            $name = $s->fetchColumn() ?: null;
        }
        $pdo->prepare("
            INSERT INTO admin_activity_log (admin_id, admin_name, action, auction_id, item_name, details)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $admin['sub'] ?? null, $admin['full_name'] ?? null, $action, $auctionId, $name,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('[AHG] logAdminAction: ' . $e->getMessage());
        if ($required) throw $e;
        return false;
    }
}

// IDs posted as a JSON array → unique positive ints (max 500 per request)
function postedIds(string $field = 'auction_ids'): array {
    $ids = json_decode($_POST[$field] ?? '[]', true);
    if (!is_array($ids)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    return array_slice($ids, 0, 500);
}

// Delete a lot with its bids, gallery rows and any local image files
function deleteAuctionFully(int $auctionId): void {
    global $pdo;
    $stmt = $pdo->prepare("SELECT file_path FROM auction_gallery WHERE auction_id = ?");
    $stmt->execute([$auctionId]);
    $galleryFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare("SELECT image_url FROM auctions WHERE id = ?");
    $stmt->execute([$auctionId]);
    $primaryImage = $stmt->fetchColumn();

    $pdo->prepare("DELETE FROM bids            WHERE auction_id = ?")->execute([$auctionId]);
    $pdo->prepare("DELETE FROM auction_gallery WHERE auction_id = ?")->execute([$auctionId]);
    $pdo->prepare("DELETE FROM auctions        WHERE id = ?")->execute([$auctionId]);

    foreach (array_merge($galleryFiles, [$primaryImage]) as $path) {
        if ($path && !preg_match('#^https?://#i', $path) && file_exists(__DIR__ . '/' . ltrim($path, '/'))) {
            @unlink(__DIR__ . '/' . ltrim($path, '/'));
        }
    }
}

// Highest bid on a lot (old and new bid columns), 0 when none
function topBidAmount(int $auctionId): float {
    global $pdo;
    $s = $pdo->prepare("SELECT COALESCE(MAX(COALESCE(bid_amount, amount)), 0) FROM bids WHERE auction_id = ?");
    $s->execute([$auctionId]);
    return (float)$s->fetchColumn();
}

// ── Route ─────────────────────────────────────────────────────

switch ($action) {

// ════════════════════════════════════════════════════════════
// PUBLIC GET ENDPOINTS (no auth required)
// ════════════════════════════════════════════════════════════

    // ── LIVE AUCTION UPDATES (bid-polling every 5s) ──────────
    case 'updates': {
        rateLimit('updates', 30, 60);  // Max 30 requests per minute per IP
        $auctionId = (int)($_GET['id'] ?? 0);
        $lastBidId = (int)($_GET['last_bid_id'] ?? 0);

        if (!$auctionId) fail('Missing auction ID.');

        // Fetch auction core data
        $stmt = $pdo->prepare("
            SELECT a.current_price, a.start_price, a.reserve_price,
                   a.min_increment, a.status, a.end_time,
                   COUNT(b.id) AS bid_count,
                   MAX(b.user_id) AS winning_bidder_id,
                   MAX(b.id) AS latest_bid_id
            FROM auctions a
            LEFT JOIN bids b ON b.auction_id = a.id AND b.is_winning = 1
            WHERE a.id = ?
            GROUP BY a.id
        ");
        $stmt->execute([$auctionId]);
        $auction = $stmt->fetch();

        if (!$auction) fail('Auction not found.', 404);

        // Auto-close expired auctions (triggers winner notification + purchase request)
        if ($auction['status'] === 'active' && strtotime($auction['end_time']) < time()) {
            closeAuction($auctionId);
            $auction['status'] = 'closed';
        }

        $currentPrice = (float)$auction['current_price'];
        $reservePrice = (float)$auction['reserve_price'];
        $minNextBid   = $currentPrice > 0
            ? $currentPrice + (float)$auction['min_increment']
            : (float)$auction['start_price'];

        // Fetch only NEW bids since last known id
        // COALESCE handles both old schema (amount/created_at) and new schema (bid_amount/bid_time)
        $stmt = $pdo->prepare("
            SELECT b.id, b.user_id,
                   COALESCE(b.bid_amount, b.amount) AS amount,
                   COALESCE(b.bid_time,   b.created_at) AS created_at,
                   b.bidder_name, b.bidder_number, b.is_winning
            FROM bids b
            WHERE b.auction_id = ? AND b.id > ?
            ORDER BY b.id ASC
            LIMIT 50
        ");
        $stmt->execute([$auctionId, $lastBidId]);
        $newBids = $stmt->fetchAll();

        ok([
            'auction_id'       => $auctionId,
            'status'           => $auction['status'],
            'current_price'    => $currentPrice,
            'reserve_price'    => $reservePrice,
            'reserve_met'      => $currentPrice >= $reservePrice,
            'min_next_bid'     => $minNextBid,
            'bid_count'        => (int)$auction['bid_count'],
            'winning_bidder_id'=> $auction['winning_bidder_id'],
            'new_bids'         => $newBids,
        ]);
    }

    // ── TICKER — live activity feed ──────────────────────────
    // ── LIVE BIDS (public) — bottom-centre "Live Bids" pill + panel ──────
    // Latest bids on lots still for sale, lots ending soon, most-bid lots.
    // No names; bidder numbers masked to the last 3 digits. Cached 15 s.
    case 'live_bids': {
        $cacheFile = sys_get_temp_dir() . '/ahg_live_bids.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - 15) {
            ob_end_clean();
            header('Cache-Control: public, max-age=15');
            echo file_get_contents($cacheFile);
            exit;
        }
        $lotOut = fn(array $r) => [
            'id'    => (int)$r['id'],
            'title' => $r['item_name'],
            'img'   => $r['image_url'] ? cldImg($r['image_url'], CLD_THUMB) : '',
            'url'   => 'auction.php?id=' . (int)$r['id'],
        ];
        $mask = function (?string $bidderNumber): string {
            $num = preg_replace('/\D/', '', (string)$bidderNumber);
            return $num !== '' ? 'Bidder ••' . substr($num, -3) : 'A bidder';
        };
        $bids = [];
        foreach ($pdo->query("
            SELECT b.id AS bid_id, COALESCE(b.bid_amount, b.amount) AS amount, b.bidder_number,
                   COALESCE(b.bid_time, b.created_at) AS at, a.id, a.item_name, a.image_url, a.end_time,
                   (SELECT MAX(COALESCE(bx.bid_amount, bx.amount)) FROM bids bx WHERE bx.auction_id = a.id) AS top_amount
            FROM bids b JOIN auctions a ON a.id = b.auction_id
            WHERE a.status = 'active' AND a.is_sold = 0
            ORDER BY b.id DESC LIMIT 12
        ")->fetchAll() as $r) {
            $bids[] = $lotOut($r) + [
                'bid_id' => (int)$r['bid_id'],
                'amount' => (float)$r['amount'],
                'amount_fmt' => formatRand((float)$r['amount']),
                'bidder' => $mask($r['bidder_number']),
                'leading'=> (float)$r['amount'] >= (float)$r['top_amount'],
                'at'     => $r['at'],
                'ago'    => timeAgoShort($r['at']),
                'ends'   => $r['end_time'],
            ];
        }
        // Who leads each lot that has bids (most recently active first)
        $leaders = [];
        foreach ($pdo->query("
            SELECT a.id, a.item_name, a.image_url, a.end_time, COUNT(b.id) AS bid_count,
                   MAX(COALESCE(b.bid_amount, b.amount)) AS top_amount, MAX(b.id) AS last_bid,
                   (SELECT bl.bidder_number FROM bids bl WHERE bl.auction_id = a.id
                     ORDER BY COALESCE(bl.bid_amount, bl.amount) DESC, bl.id DESC LIMIT 1) AS leader
            FROM auctions a JOIN bids b ON b.auction_id = a.id
            WHERE a.status = 'active' AND a.is_sold = 0
            GROUP BY a.id ORDER BY last_bid DESC LIMIT 8
        ")->fetchAll() as $r) {
            $leaders[] = $lotOut($r) + ['leader' => $mask($r['leader']), 'price_fmt' => formatRand((float)$r['top_amount']),
                                        'bids' => (int)$r['bid_count'], 'ends' => $r['end_time']];
        }
        // Staff announcements from Admin → Notifications ("custom" only: the old canned
        // bid/leading/win examples were not real, and test/diagnostic rows are skipped)
        $notes = [];
        try {
            foreach ($pdo->query("SELECT id, message FROM popup_notifications WHERE is_active = 1 AND type = 'custom' ORDER BY id DESC LIMIT 5")->fetchAll() as $r) {
                if (preg_match('/diagnostic|test/i', $r['message'])) continue;
                $notes[] = ['id' => (int)$r['id'], 'text' => strip_tags($r['message'])];
            }
        } catch (PDOException $e) { /* table missing */ }
        $ending = [];
        foreach ($pdo->query("
            SELECT a.id, a.item_name, a.image_url, a.end_time,
                   GREATEST(COALESCE(a.current_price, 0), a.start_price) AS price,
                   (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) AS bid_count
            FROM auctions a
            WHERE a.status = 'active' AND a.is_sold = 0 AND a.end_time > NOW()
            ORDER BY a.end_time ASC LIMIT 6
        ")->fetchAll() as $r) {
            $ending[] = $lotOut($r) + ['price_fmt' => formatRand((float)$r['price']), 'bids' => (int)$r['bid_count'], 'ends' => $r['end_time']];
        }
        $hot = [];
        foreach ($pdo->query("
            SELECT a.id, a.item_name, a.image_url, a.end_time,
                   GREATEST(COALESCE(a.current_price, 0), a.start_price) AS price, COUNT(b.id) AS bid_count
            FROM auctions a JOIN bids b ON b.auction_id = a.id
            WHERE a.status = 'active' AND a.is_sold = 0
            GROUP BY a.id ORDER BY bid_count DESC, MAX(b.id) DESC LIMIT 6
        ")->fetchAll() as $r) {
            $hot[] = $lotOut($r) + ['price_fmt' => formatRand((float)$r['price']), 'bids' => (int)$r['bid_count'], 'ends' => $r['end_time']];
        }
        $liveCount = (int)$pdo->query("SELECT COUNT(*) FROM auctions WHERE status = 'active' AND is_sold = 0 AND end_time > NOW()")->fetchColumn();
        $json = json_encode(imgHostDeep([
            'success' => true, 'server_time' => date('Y-m-d H:i:s'), 'live_count' => $liveCount,
            'bids' => $bids, 'leading' => $leaders, 'ending' => $ending, 'hot' => $hot, 'notes' => $notes,
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @file_put_contents($cacheFile, $json, LOCK_EX);
        ob_end_clean();
        header('Cache-Control: public, max-age=15');
        echo $json;
        exit;
    }

    case 'ticker': {
        // Mix: announcement messages + recent bid activity
        $items = [];

        // Recent bids (last 10 across all active auctions)
        $stmt = $pdo->query("
            SELECT b.bidder_number, COALESCE(b.bid_amount, b.amount) AS amount, a.item_name
            FROM bids b
            JOIN auctions a ON a.id = b.auction_id
            WHERE a.status = 'active'
            ORDER BY b.id DESC
            LIMIT 10
        ");
        foreach ($stmt->fetchAll() as $bid) {
            $items[] = sprintf(
                '🔨 Bidder %s bid %s on %s',
                htmlspecialchars($bid['bidder_number'] ?: 'Anonymous', ENT_QUOTES),
                'R ' . number_format($bid['amount'], 2),
                htmlspecialchars($bid['item_name'], ENT_QUOTES)
            );
        }

        // Static announcements
        $stmt = $pdo->query("
            SELECT message FROM news_announcements
            WHERE is_active = 1
            ORDER BY sort_order ASC, created_at DESC
            LIMIT 8
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $msg) {
            $items[] = $msg;
        }

        if (empty($items)) {
            $items = [
                '🚗 Welcome to Auction Hub Group — Trusted by banks, respected by buyers',
                '🔔 New vehicles added weekly — Register to bid today',
            ];
        }

        ok(['items' => $items]);
    }

    // ── ANNOUNCEMENTS — full list for ticker refresh ─────────
    case 'announcements': {
        $stmt = $pdo->query("
            SELECT id, message, sort_order
            FROM news_announcements
            WHERE is_active = 1
            ORDER BY sort_order ASC
        ");
        ok(['announcements' => $stmt->fetchAll()]);
    }

// ════════════════════════════════════════════════════════════
// USER ENDPOINTS (requires valid JWT — any logged-in user)
// ════════════════════════════════════════════════════════════

    // ── PLACE BID ────────────────────────────────────────────
    case 'place_bid': {
        rateLimit('place_bid', 10, 60);  // Max 10 bids per minute per IP
        $user = requireAuth();

        // Must be a verified bidder
        if ($user['bidder_status'] !== 'verified_bidder') {
            fail('You must be a verified bidder to place bids. Please complete your registration.');
        }

        $auctionId = (int)($_POST['auction_id'] ?? 0);
        $amount    = (float)($_POST['amount'] ?? 0);

        if (!$auctionId || $amount <= 0) fail('Invalid bid data.');

        // Lock the row for the duration of this transaction
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                SELECT id, status, end_time, current_price, start_price,
                       min_increment, reserve_price
                FROM auctions
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$auctionId]);
            $auction = $stmt->fetch();

            if (!$auction)                            { $pdo->rollBack(); fail('Auction not found.'); }
            if ($auction['status'] !== 'active')      { $pdo->rollBack(); fail('This auction is not currently active.'); }
            if (strtotime($auction['end_time']) < time()) {
                $pdo->commit();
                closeAuction($auctionId);
                fail('This auction has ended.');
            }

            $currentPrice = (float)$auction['current_price'];
            $minBid = $currentPrice > 0
                ? $currentPrice + (float)$auction['min_increment']
                : (float)$auction['start_price'];

            if ($amount < $minBid) {
                $pdo->rollBack();
                fail(sprintf('Minimum bid is R %s.', number_format($minBid, 2)));
            }

            // Fetch user details for bid record
            $uStmt = $pdo->prepare("SELECT full_name, bidder_number FROM users WHERE id = ?");
            $uStmt->execute([$user['sub']]);
            $bidder = $uStmt->fetch();

            // Mark all previous winning bids as non-winning
            $pdo->prepare("UPDATE bids SET is_winning = 0 WHERE auction_id = ?")
                ->execute([$auctionId]);

            // Insert new bid — write both column name variants for schema compatibility
            // Old schema: amount + created_at | New schema: bid_amount + bid_time
            // We detect which columns exist and insert accordingly.
            $pdo->prepare("
                INSERT INTO bids
                    (auction_id, user_id, bid_amount, amount, bidder_name, bidder_number,
                     ip_address, is_winning, bid_time, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ")->execute([
                $auctionId,
                $user['sub'],
                $amount,
                $amount,
                $bidder['full_name'],
                $bidder['bidder_number'],
                $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            $newBidId = (int)$pdo->lastInsertId();

            // Update auction current price
            $pdo->prepare("UPDATE auctions SET current_price = ? WHERE id = ?")
                ->execute([$amount, $auctionId]);

            $pdo->commit();

            // Send emails (non-fatal — don't break the bid if email fails)
            try {
                require_once __DIR__ . '/email_helper.php';

                // Fetch auction details for email
                $aStmt = $pdo->prepare("SELECT a.*, CONCAT(a.year,' ',m.name,' ',a.item_name) AS vehicle_name
                    FROM auctions a LEFT JOIN makes m ON m.id = a.make_id WHERE a.id = ?");
                $aStmt->execute([$auctionId]);
                $auctionData = $aStmt->fetch(PDO::FETCH_ASSOC);

                // Count bids
                $bidCount = $pdo->prepare("SELECT COUNT(*) FROM bids WHERE auction_id = ?");
                $bidCount->execute([$auctionId]);
                $totalBids = (int)$bidCount->fetchColumn();

                $vehicleName = $auctionData['vehicle_name'] ?? ('Auction #' . $auctionId);

                // Send confirmation to the bidder
                sendBidConfirmationEmail([
                    'email'            => $user['email'],
                    'name'             => $bidder['full_name'],
                    'vehicle_name'     => $vehicleName,
                    'bid_amount'       => $amount,
                    'current_high_bid' => $amount,
                    'bid_count'        => $totalBids,
                    'auction_id'       => $auctionId,
                    'auction_end_time' => $auctionData['end_time'] ?? '',
                    'bidder_number'    => $bidder['bidder_number'] ?? '',
                ]);

                // Send outbid alert to previous highest bidder (if any)
                $prevBid = $pdo->prepare("
                    SELECT b.user_id, b.bid_amount, u.email, u.full_name
                    FROM bids b JOIN users u ON u.id = b.user_id
                    WHERE b.auction_id = ? AND b.id != ? AND b.user_id != ?
                    ORDER BY b.bid_amount DESC LIMIT 1
                ");
                $prevBid->execute([$auctionId, $newBidId, $user['sub']]);
                $outbidUser = $prevBid->fetch(PDO::FETCH_ASSOC);

                if ($outbidUser) {
                    notifyUser((int)$outbidUser['user_id'], 'outbid', $auctionId,
                        'Outbid: ' . ($auctionData['item_name'] ?? $vehicleName),
                        'New highest bid ' . formatRand($amount) . ' (yours was ' . formatRand((float)$outbidUser['bid_amount']) . ').',
                        'auction.php?id=' . $auctionId);
                }
                if ($outbidUser && wantsEmail((int)$outbidUser['user_id'], 'outbid')
                    && canEmailUser((int)$outbidUser['user_id'], $outbidUser['email'])) {
                    sendOutbidAlertEmail([
                        'user_id'          => (int)$outbidUser['user_id'],
                        'email'            => $outbidUser['email'],
                        'name'             => $outbidUser['full_name'],
                        'vehicle_name'     => $vehicleName,
                        'their_bid'        => (float)$outbidUser['bid_amount'],
                        'new_high_bid'     => $amount,
                        'auction_id'       => $auctionId,
                        'auction_end_time' => $auctionData['end_time'] ?? '',
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('Bid email error: ' . $e->getMessage());
            }

            ok([
                'bid_id'       => $newBidId,
                'new_price'    => $amount,
                'reserve_met'  => $amount >= (float)$auction['reserve_price'],
                'message'      => 'Bid of R ' . number_format($amount, 2) . ' placed successfully!',
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('place_bid error: ' . $e->getMessage());
            fail('An error occurred while placing your bid. Please try again.');
        }
    }

    // ── UPLOAD PROOF OF PAYMENT ──────────────────────────────
    case 'upload_pop': {
        $user = requireAuth();

        // Already approved — nothing to do
        if ($user['bidder_status'] === 'verified_bidder') {
            fail('Your bidder registration is already approved.');
        }

        if (empty($_FILES['pop_file'])) {
            fail('No file uploaded.');
        }

        $upload = handleUpload(
            $_FILES['pop_file'],
            UPLOAD_POP,
            ALLOWED_POP_TYPES,
            'pop_' . $user['sub']
        );

        if (!$upload['success']) fail($upload['message']);

        // Check for existing pending registration
        $stmt = $pdo->prepare("
            SELECT id FROM bidder_registrations
            WHERE user_id = ? AND status IN ('pending', 'under_review')
            LIMIT 1
        ");
        $stmt->execute([$user['sub']]);
        $existing = $stmt->fetchColumn();

        // Fetch user's full_name and email for NOT NULL columns in bidder_registrations
        $uInfo = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
        $uInfo->execute([$user['sub']]);
        $uRow = $uInfo->fetch();

        if ($existing) {
            // Update existing record with new file
            $pdo->prepare("
                UPDATE bidder_registrations
                SET pop_file_path = ?, pop_file_type = ?, submitted_at = NOW(), status = 'pending'
                WHERE id = ?
            ")->execute([$upload['path'], $_FILES['pop_file']['type'], $existing]);
        } else {
            // Insert new registration — full_name and email are NOT NULL in schema
            $pdo->prepare("
                INSERT INTO bidder_registrations
                    (user_id, full_name, email, pop_file_path, pop_file_type, bank_reference, submitted_at, status)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 'pending')
            ")->execute([
                $user['sub'],
                $uRow['full_name'],
                $uRow['email'],
                $upload['path'],
                $_FILES['pop_file']['type'],
                eftReference($user['sub']),
            ]);
        }

        // Also update pop fields on users table (including file type so admin can preview)
        $pdo->prepare("
            UPDATE users
            SET pop_file_path     = ?,
                pop_file_type     = ?,
                pop_original_name = ?,
                pop_uploaded_at   = NOW(),
                bidder_status     = 'pending_approval'
            WHERE id = ?
        ")->execute([
            $upload['path'],
            $_FILES['pop_file']['type'],
            $_FILES['pop_file']['name'],
            $user['sub'],
        ]);

        // Notify admin
        try {
            require_once __DIR__ . '/email_helper.php';
            $stmt = $pdo->prepare("SELECT id, full_name, email, phone, id_number FROM users WHERE id = ?");
            $stmt->execute([$user['sub']]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT * FROM bidder_registrations WHERE user_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user['sub']]);
            $regData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            if ($userData) {
                sendPopUploadedAdminAlert($userData, $regData);
            }
        } catch (Exception $e) {
            error_log('POP admin alert email error: ' . $e->getMessage());
        }

        ok(['message' => 'Proof of payment uploaded successfully! We will review it within 1–2 business days.']);
    }

    // ── REQUEST BANK DETAILS ─────────────────────────────────
    case 'request_bank_details': {
        $user = requireAuth();

        $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ?");
        $stmt->execute([$user['sub']]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$userData) fail('User not found.');

        // Check for existing pending request
        try {
            $existing = $pdo->prepare("SELECT id FROM bank_detail_requests WHERE user_id = ? AND status = 'pending'");
            $existing->execute([$userData['id']]);
            if ($existing->fetch()) {
                ok(['message' => 'Your request for banking details is already pending admin approval. You will receive an email once approved.']);
            }

            // Create pending request for admin approval
            $pdo->prepare("INSERT INTO bank_detail_requests (user_id) VALUES (?)")->execute([$userData['id']]);
            ok(['message' => 'Your request for banking details has been submitted and is pending admin approval. You will receive an email once approved.']);
        } catch (PDOException $e) {
            // Fallback: if bank_detail_requests table doesn't exist, send directly
            try {
                require_once __DIR__ . '/email_helper.php';
                sendBankDetailsEmail($userData);
                ok(['message' => 'Banking details have been sent to your email address (' . e($userData['email']) . '). Please check your inbox.']);
            } catch (Exception $e2) {
                error_log('Send bank details email error: ' . $e2->getMessage());
                fail('Could not send email. Please try again or contact us via WhatsApp.');
            }
        }
    }

    // ── UPDATE PROFILE ────────────────────────────────────────
    case 'update_profile': {
        $user  = requireAuth();
        $phone = trim($_POST['phone'] ?? '');

        $updatePassword = false;
        $currentPw      = $_POST['current_password'] ?? '';
        $newPw          = $_POST['new_password']      ?? '';
        $confirmPw      = $_POST['confirm_password']  ?? '';

        // Validate phone
        if ($phone && strlen($phone) < 10) fail('Please enter a valid phone number.');

        // Password change requested?
        if ($newPw) {
            if (strlen($newPw) < 8) fail('New password must be at least 8 characters.');
            if ($newPw !== $confirmPw) fail('New passwords do not match.');

            // Verify current password
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$user['sub']]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($currentPw, $row['password'])) {
                fail('Current password is incorrect.');
            }
            $updatePassword = true;
        }

        if ($updatePassword) {
            $pdo->prepare("UPDATE users SET phone = ?, password = ? WHERE id = ?")
                ->execute([$phone, password_hash($newPw, PASSWORD_BCRYPT), $user['sub']]);
        } else {
            $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?")
                ->execute([$phone, $user['sub']]);
        }

        ok(['message' => 'Profile updated successfully.']);
    }

// ════════════════════════════════════════════════════════════
// ADMIN ENDPOINTS (requires admin role)
// ════════════════════════════════════════════════════════════

    // ── APPROVE POP ──────────────────────────────────────────
    case 'approve_pop': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $targetUserId = (int)($_POST['user_id']         ?? 0);
        $bidderNum    = trim($_POST['bidder_number']    ?? '');

        if (!$targetUserId || !$bidderNum) fail('Missing required fields.');

        // Validate bidder number format: AHG-YYYY-NNN
        if (!preg_match('/^AHG-\d{4}-\d{3}$/', $bidderNum)) {
            fail('Bidder number must follow format AHG-YYYY-NNN (e.g. AHG-2026-001).');
        }

        // Check bidder number not already in use
        $stmt = $pdo->prepare("SELECT id FROM users WHERE bidder_number = ? AND id != ?");
        $stmt->execute([$bidderNum, $targetUserId]);
        if ($stmt->fetch()) fail("Bidder number {$bidderNum} is already assigned to another user.");

        // Update registration record (find by user_id — registration_id optional)
        $pdo->prepare("
            UPDATE bidder_registrations
            SET status = 'approved', reviewed_at = NOW(), reviewed_by = ?
            WHERE user_id = ? AND status IN ('pending','under_review')
        ")->execute([$user['sub'], $targetUserId]);

        // Update user record
        $pdo->prepare("
            UPDATE users
            SET bidder_status = 'verified_bidder',
                bidder_number = ?,
                pop_approved_at = NOW(),
                pop_approved_by = ?
            WHERE id = ?
        ")->execute([$bidderNum, $user['sub'], $targetUserId]);

        // Invalidate existing JWT (forces fresh token on next page load)
        $pdo->prepare("UPDATE users SET jwt_version = jwt_version + 1 WHERE id = ?")
            ->execute([$targetUserId]);

        // Email the user
        try {
            require_once __DIR__ . '/email_helper.php';
            $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ?");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($targetUser) {
                sendPopApprovedEmail($targetUser, $bidderNum);
            }
        } catch (Exception $e) {
            error_log('POP approved email error: ' . $e->getMessage());
        }

        ok(['message' => "POP approved. Bidder number {$bidderNum} assigned successfully."]);
    }

    // ── REJECT POP ───────────────────────────────────────────
    case 'reject_pop': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $regId        = (int)($_POST['registration_id'] ?? 0);
        $targetUserId = (int)($_POST['user_id']         ?? 0);
        $reason       = trim($_POST['reason']           ?? '');

        if (!$targetUserId) fail('Missing required fields.');
        if (!$reason) fail('A rejection reason is required.');

        // Update bidder_registrations if reg_id provided, otherwise find by user_id
        if ($regId) {
            $pdo->prepare("
                UPDATE bidder_registrations
                SET status = 'rejected', admin_notes = ?, reviewed_at = NOW(), reviewed_by = ?
                WHERE id = ?
            ")->execute([$reason, $user['sub'], $regId]);
        } else {
            $pdo->prepare("
                UPDATE bidder_registrations
                SET status = 'rejected', admin_notes = ?, reviewed_at = NOW(), reviewed_by = ?
                WHERE user_id = ? AND status = 'pending'
            ")->execute([$reason, $user['sub'], $targetUserId]);
        }

        $pdo->prepare("
            UPDATE users SET bidder_status = 'registered'
            WHERE id = ?
        ")->execute([$targetUserId]);

        // Email the user with the reason
        try {
            require_once __DIR__ . '/email_helper.php';
            $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ?");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($targetUser) {
                sendPopRejectedEmail($targetUser, $reason);
            }
        } catch (Exception $e) {
            error_log('POP rejected email error: ' . $e->getMessage());
        }

        ok(['message' => 'Registration rejected and user notified by email.']);
    }

    // ── DELETE AUCTION ────────────────────────────────────────
    case 'delete_auction': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $auctionId = (int)($_POST['auction_id'] ?? 0);
        if (!$auctionId) fail('Missing auction ID.');

        logAdminAction($user, 'delete', $auctionId, ['bids' => (int)$pdo->query("SELECT COUNT(*) FROM bids WHERE auction_id = $auctionId")->fetchColumn()]);
        deleteAuctionFully($auctionId);

        ok(['message' => 'Auction and all associated data deleted successfully.']);
    }

    // ── UPDATE AUCTION STATUS ─────────────────────────────────
    case 'update_auction_status': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $auctionId = (int)($_POST['auction_id'] ?? 0);
        $status    = $_POST['status'] ?? '';

        if (!$auctionId) fail('Missing auction ID.');
        if (!in_array($status, ['active', 'upcoming', 'closed', 'sold'])) fail('Invalid status.');

        if ($status === 'closed') {
            closeAuction($auctionId);
        } elseif ($status === 'sold') {
            $pdo->prepare("UPDATE auctions SET sold_date = IF(is_sold = 1 AND sold_date IS NOT NULL, sold_date, NOW()), status = 'closed', is_sold = 1 WHERE id = ?")
                ->execute([$auctionId]);
        } else {
            $pdo->prepare("UPDATE auctions SET status = ?, is_sold = 0, sold_date = NULL WHERE id = ?")
                ->execute([$status, $auctionId]);
        }
        logAdminAction($user, 'status', $auctionId, ['status' => $status]);

        ok(['message' => 'Auction status updated to "' . $status . '".']);
    }

    // ── UPDATE AUCTION TIMES (single auction) ─────────────────
    case 'update_auction_times': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $auctionId = (int)($_POST['auction_id'] ?? 0);
        $startTime = trim($_POST['start_time']  ?? '');
        $endTime   = trim($_POST['end_time']    ?? '');

        if (!$auctionId)  fail('Missing auction ID.');
        if (!$startTime)  fail('Start time is required.');
        if (!$endTime)    fail('End time is required.');

        if (strtotime($endTime) <= strtotime($startTime)) {
            fail('End time must be after start time.');
        }

        $pdo->prepare("UPDATE auctions SET start_time = ?, end_time = ? WHERE id = ?")
            ->execute([$startTime, $endTime, $auctionId]);

        ok(['message' => 'Auction times updated successfully.']);
    }

    // ── SET ALL AUCTION TIMES (bulk) ──────────────────────────
    case 'set_all_auction_times': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $startTime = trim($_POST['start_time'] ?? '');
        $endTime   = trim($_POST['end_time']   ?? '');
        $statusFilter = $_POST['status_filter'] ?? 'upcoming'; // upcoming|active|all

        if (!$startTime) fail('Start time is required.');
        if (!$endTime)   fail('End time is required.');

        if (strtotime($endTime) <= strtotime($startTime)) {
            fail('End time must be after start time.');
        }

        $allowed = ['upcoming', 'active', 'all'];
        if (!in_array($statusFilter, $allowed)) $statusFilter = 'upcoming';

        if ($statusFilter === 'all') {
            $stmt = $pdo->prepare("UPDATE auctions SET start_time = ?, end_time = ?");
            $stmt->execute([$startTime, $endTime]);
        } else {
            $stmt = $pdo->prepare("UPDATE auctions SET start_time = ?, end_time = ? WHERE status = ?");
            $stmt->execute([$startTime, $endTime, $statusFilter]);
        }

        $count = $stmt->rowCount();
        ok(['message' => "Times updated for {$count} auction(s).", 'updated_count' => $count]);
    }

    // ── UPDATE USER STATUS (suspend / activate) ───────────────
    case 'update_user_status': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $targetId = (int)($_POST['user_id'] ?? 0);
        $status   = $_POST['status'] ?? '';

        if (!$targetId) fail('Missing user ID.');
        if (!in_array($status, ['active', 'suspended'])) fail('Invalid status.');

        // Prevent admin from suspending themselves
        if ($targetId === (int)$user['sub']) fail('You cannot change your own account status.');

        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")
            ->execute([$status, $targetId]);

        // Invalidate JWT on suspend
        if ($status === 'suspended') {
            $pdo->prepare("UPDATE users SET jwt_version = jwt_version + 1 WHERE id = ?")
                ->execute([$targetId]);
        }

        ok(['message' => 'User status updated to "' . $status . '".']);
    }

    // ── DELETE USER ───────────────────────────────────────────
    case 'delete_user': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $targetId = (int)($_POST['user_id'] ?? 0);
        if (!$targetId) fail('Missing user ID.');
        if ($targetId === (int)$user['sub']) fail('You cannot delete your own account.');

        // Check not admin
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();

        if (!$target) fail('User not found.');
        if ($target['role'] === 'admin') fail('Admin accounts cannot be deleted this way.');

        // Anonymise bids (keep auction integrity)
        $pdo->prepare("
            UPDATE bids SET bidder_name = 'Deleted User', bidder_number = 'N/A', user_id = NULL
            WHERE user_id = ?
        ")->execute([$targetId]);

        // Delete user record (bidder_registrations will cascade)
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);

        ok(['message' => 'User deleted. Their bid history has been anonymised.']);
    }

    // ── ADMIN: SEND BANK DETAILS TO USER ────────────────────────
    case 'admin_send_bank_details': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if (!$targetUserId) fail('Missing user ID.');

        $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) fail('User not found.');

        try {
            require_once __DIR__ . '/email_helper.php';
            sendBankDetailsEmail($targetUser);
            ok(['message' => "Banking details sent to {$targetUser['full_name']} ({$targetUser['email']})."]);
        } catch (Exception $e) {
            error_log('Admin send bank details error: ' . $e->getMessage());
            fail('Failed to send email. Check error logs.');
        }
    }

    case 'approve_bank_request': {
        adminOnly();
        $requestId = (int)($_POST['request_id'] ?? 0);
        if (!$requestId) fail('Missing request ID.');

        $stmt = $pdo->prepare("SELECT br.*, u.id AS uid, u.full_name, u.email FROM bank_detail_requests br JOIN users u ON u.id = br.user_id WHERE br.id = ? AND br.status = 'pending'");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) fail('Request not found or already processed.');

        // Send banking details email
        try {
            require_once __DIR__ . '/email_helper.php';
            sendBankDetailsEmail(['id' => $req['uid'], 'full_name' => $req['full_name'], 'email' => $req['email']]);
        } catch (Exception $e) {
            error_log('Approve bank request email error: ' . $e->getMessage());
            fail('Failed to send email: ' . $e->getMessage());
        }

        // Mark as approved
        $currentUser = requireAuth();
        $pdo->prepare("UPDATE bank_detail_requests SET status = 'approved', processed_at = NOW(), processed_by = ? WHERE id = ?")
            ->execute([$currentUser['sub'], $requestId]);

        ok(['message' => "Approved. Banking details sent to {$req['full_name']} ({$req['email']})."]);
    }

    case 'reject_bank_request': {
        adminOnly();
        $requestId = (int)($_POST['request_id'] ?? 0);
        if (!$requestId) fail('Missing request ID.');

        $stmt = $pdo->prepare("SELECT id FROM bank_detail_requests WHERE id = ? AND status = 'pending'");
        $stmt->execute([$requestId]);
        if (!$stmt->fetch()) fail('Request not found or already processed.');

        $currentUser = requireAuth();
        $pdo->prepare("UPDATE bank_detail_requests SET status = 'rejected', processed_at = NOW(), processed_by = ? WHERE id = ?")
            ->execute([$currentUser['sub'], $requestId]);

        ok(['message' => 'Request rejected.']);
    }

    // ── ADMIN: RESET USER PASSWORD ──────────────────────────────
    case 'admin_reset_password': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newPassword  = $_POST['new_password'] ?? '';

        if (!$targetUserId) fail('Missing user ID.');
        if (strlen($newPassword) < 8) fail('Password must be at least 8 characters.');

        $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) fail('User not found.');

        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password = ?, jwt_version = jwt_version + 1 WHERE id = ?")
            ->execute([$hashed, $targetUserId]);

        ok(['message' => "Password reset for {$targetUser['full_name']}. They will need to log in again with the new password."]);
    }

    // ── ADMIN: UPDATE BANK SETTINGS ─────────────────────────────
    case 'update_bank_settings': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $fields = ['bank_name', 'bank_account_name', 'bank_account_no', 'bank_branch_code', 'bank_account_type', 'reg_fee_label'];
        $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

        foreach ($fields as $key) {
            $val = trim($_POST[$key] ?? '');
            if (!$val) fail("Field '{$key}' is required.");
            $stmt->execute([$key, $val]);
        }

        ok(['message' => 'Banking details updated successfully.']);
    }

    // ── SEND EMAIL (single user) ──────────────────────────────
    case 'send_email': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $targetId = (int)($_POST['user_id'] ?? 0);
        $subject  = trim($_POST['subject']  ?? '');
        $body     = trim($_POST['body'] ?? $_POST['message'] ?? '');
        $format   = $_POST['format'] ?? 'plain'; // plain or html

        if (!$targetId) fail('Please select a recipient.');
        if (!$subject)  fail('Subject is required.');
        if (!$body)     fail('Message body is required.');

        $stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        $recipient = $stmt->fetch();
        if (!$recipient) fail('User not found.');

        // Optional PDF attachment (e.g. invoice)
        $attachments = [];
        $tempToClean = null;
        if (!empty($_FILES['attachment']) && is_uploaded_file($_FILES['attachment']['tmp_name'] ?? '')) {
            $up = $_FILES['attachment'];
            if ($up['error'] === UPLOAD_ERR_OK && $up['size'] > 0 && $up['size'] <= 10 * 1024 * 1024) {
                $origName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($up['name']));
                if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) === 'pdf') {
                    $tmpDir = sys_get_temp_dir();
                    $tempToClean = $tmpDir . DIRECTORY_SEPARATOR . 'pva_inv_' . uniqid() . '.pdf';
                    if (move_uploaded_file($up['tmp_name'], $tempToClean)) {
                        $attachments[] = ['path' => $tempToClean, 'name' => $origName];
                    }
                }
            }
        }

        require_once __DIR__ . '/email_helper.php';
        $sent = sendCustomEmail([
            'to'          => $recipient['email'],
            'name'        => $recipient['full_name'],
            'subject'     => $subject,
            'message'     => $body,
            'format'      => $format,
            'attachments' => $attachments,
        ]);

        if ($tempToClean && file_exists($tempToClean)) @unlink($tempToClean);

        if (!$sent) fail('Email could not be sent. Please check your SMTP configuration.');

        $msg = "Email sent to {$recipient['full_name']} ({$recipient['email']}).";
        if (!empty($attachments)) $msg .= ' PDF invoice attached.';
        ok(['message' => $msg]);
    }

    // ── SEND BULK EMAIL ───────────────────────────────────────
    case 'send_bulk_email': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $group   = $_POST['group']   ?? 'all';       // all|verified|pending|registered
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? $_POST['message'] ?? '');
        $format  = $_POST['format'] ?? 'plain'; // plain or html

        if (!$subject) fail('Subject is required.');
        if (!$body)    fail('Message body is required.');

        // Map short form names to actual bidder_status values
        $groupMap = [
            'all'              => 'all',
            'verified'         => 'verified_bidder',
            'verified_bidder'  => 'verified_bidder',
            'pending'          => 'pending_approval',
            'pending_approval' => 'pending_approval',
            'registered'       => 'registered',
            'pending_payment'  => 'pending_payment',
        ];
        $dbGroup = $groupMap[$group] ?? 'all';

        if ($dbGroup === 'all') {
            $stmt = $pdo->query("SELECT full_name, email FROM users WHERE status = 'active' AND role = 'user'");
        } else {
            $stmt = $pdo->prepare("
                SELECT full_name, email FROM users
                WHERE status = 'active' AND role = 'user' AND bidder_status = ?
            ");
            $stmt->execute([$dbGroup]);
        }
        $recipients = $stmt->fetchAll();

        if (empty($recipients)) fail('No recipients found for the selected group.');

        require_once __DIR__ . '/email_helper.php';
        $sent  = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            $ok = sendCustomEmail([
                'to'      => $recipient['email'],
                'name'    => $recipient['full_name'],
                'subject' => $subject,
                'message' => $body,
                'format'  => $format,
            ]);
            $ok ? $sent++ : $failed++;
            // Rate-limit: 3 emails/sec to avoid SMTP throttle
            if ($sent % 3 === 0) usleep(1000000);
        }

        ok([
            'message' => "Bulk email complete. Sent: {$sent}, Failed: {$failed}.",
            'sent'    => $sent,
            'failed'  => $failed,
        ]);
    }

    // ── SET PRIMARY GALLERY IMAGE ─────────────────────────────
    case 'set_primary_image': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $imageId   = (int)($_POST['image_id']   ?? 0);
        $auctionId = (int)($_POST['auction_id'] ?? 0);

        if (!$imageId || !$auctionId) fail('Missing image or auction ID.');

        // Fetch the image path
        $stmt = $pdo->prepare("SELECT file_path FROM auction_gallery WHERE id = ? AND auction_id = ?");
        $stmt->execute([$imageId, $auctionId]);
        $img = $stmt->fetch();
        if (!$img) fail('Image not found.');

        // Unset all primary flags for this auction
        $pdo->prepare("UPDATE auction_gallery SET is_primary = 0 WHERE auction_id = ?")
            ->execute([$auctionId]);

        // Set the selected image as primary
        $pdo->prepare("UPDATE auction_gallery SET is_primary = 1 WHERE id = ?")
            ->execute([$imageId]);

        // Also update auctions.image_url
        $pdo->prepare("UPDATE auctions SET image_url = ? WHERE id = ?")
            ->execute([$img['file_path'], $auctionId]);

        ok(['message' => 'Primary image updated.', 'new_primary_path' => $img['file_path']]);
    }

    // ── DELETE GALLERY IMAGE ──────────────────────────────────
    case 'delete_gallery_image': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $imageId   = (int)($_POST['image_id']   ?? 0);
        $auctionId = (int)($_POST['auction_id'] ?? 0);

        if (!$imageId) fail('Missing image ID.');

        $stmt = $pdo->prepare("
            SELECT id, file_path, cloudinary_public_id, is_primary FROM auction_gallery
            WHERE id = ? AND auction_id = ?
        ");
        $stmt->execute([$imageId, $auctionId]);
        $img = $stmt->fetch();
        if (!$img) fail('Image not found.');

        // Delete the file itself, wherever it is stored (ImageKit, Cloudinary or uploads/)
        require_once __DIR__ . '/imagekit_helper.php';
        deleteStoredImage($img['file_path'], $img['cloudinary_public_id']);

        // Delete DB record
        $pdo->prepare("DELETE FROM auction_gallery WHERE id = ?")->execute([$imageId]);

        // If this was the primary, auto-promote the next image
        if ($img['is_primary']) {
            $stmt = $pdo->prepare("
                SELECT id, file_path FROM auction_gallery
                WHERE auction_id = ? ORDER BY sort_order ASC LIMIT 1
            ");
            $stmt->execute([$auctionId]);
            $next = $stmt->fetch();
            if ($next) {
                $pdo->prepare("UPDATE auction_gallery SET is_primary = 1 WHERE id = ?")
                    ->execute([$next['id']]);
                $pdo->prepare("UPDATE auctions SET image_url = ? WHERE id = ?")
                    ->execute([$next['file_path'], $auctionId]);
            } else {
                // No images left — clear auction primary image
                $pdo->prepare("UPDATE auctions SET image_url = NULL WHERE id = ?")
                    ->execute([$auctionId]);
            }
        }

        ok(['message' => 'Image deleted.']);
    }

    // ── REORDER GALLERY IMAGES ────────────────────────────────
    case 'reorder_gallery': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        // Expects POST: order = JSON array of image IDs in new sequence
        $orderJson = $_POST['order'] ?? '[]';
        $order     = json_decode($orderJson, true);

        if (!is_array($order) || empty($order)) fail('Invalid order data.');

        $stmt = $pdo->prepare("UPDATE auction_gallery SET sort_order = ? WHERE id = ?");
        foreach ($order as $position => $imageId) {
            $stmt->execute([$position, (int)$imageId]);
        }

        ok(['message' => 'Gallery order saved.']);
    }

    // ── GET AUCTION STATS (admin dashboard overview) ──────────
    case 'get_auction_stats': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        // All stats in a single pass
        $stats = [];

        // Auction counts
        $row = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'active')   AS active,
                SUM(status = 'upcoming') AS upcoming,
                SUM(status = 'closed')   AS closed
            FROM auctions
        ")->fetch();
        $stats['auctions'] = $row;

        // User counts
        $row = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(bidder_status = 'verified_bidder')  AS verified,
                SUM(bidder_status = 'pending_approval') AS pending_approval,
                SUM(status = 'suspended') AS suspended
            FROM users WHERE role = 'user'
        ")->fetch();
        $stats['users'] = $row;

        // POP registrations pending
        $pending = $pdo->query("
            SELECT COUNT(*) FROM bidder_registrations WHERE status = 'pending'
        ")->fetchColumn();
        $stats['pending_pops'] = (int)$pending;

        // Bid stats
        $row = $pdo->query("
            SELECT COUNT(*) AS total_bids,
                   COALESCE(SUM(COALESCE(bid_amount, amount)), 0) AS total_volume
            FROM bids
        ")->fetch();
        $stats['bids'] = $row;

        // Closed auction revenue (sum of highest bids)
        $revenue = $pdo->query("
            SELECT COALESCE(SUM(current_price), 0) AS total
            FROM auctions WHERE status = 'closed' AND current_price > 0
        ")->fetchColumn();
        $stats['revenue'] = (float)$revenue;

        ok(['stats' => $stats]);
    }

// ════════════════════════════════════════════════════════════
// SEARCH USERS (Email tab autocomplete)
// ════════════════════════════════════════════════════════════
    case 'search_users': {
        adminOnly();
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $stmt = $pdo->prepare("
            SELECT id, full_name, email, bidder_number, bidder_status
            FROM users WHERE role='user'
              AND (full_name LIKE ? OR email LIKE ? OR bidder_number LIKE ?)
            ORDER BY full_name LIMIT 10
        ");
        $stmt->execute([$q, $q, $q]);
        ok(['users' => $stmt->fetchAll()]);
    }

// ════════════════════════════════════════════════════════════
// SAVE AUCTION (Add / Edit — used by manage_auction.php)
// ════════════════════════════════════════════════════════════
    case 'save_auction': {
        adminOnly();

        require_once __DIR__ . '/cloudinary_helper.php';  // file naming helpers (cloudinaryMainId etc.)
        require_once __DIR__ . '/imagekit_helper.php';

        $id           = (int)($_POST['auction_id'] ?? 0);
        $itemName     = trim($_POST['item_name']    ?? '');
        $year         = (int)($_POST['year']        ?? 0) ?: null;
        $mileage      = (int)($_POST['mileage']     ?? 0) ?: null;
        $fuelType     = $_POST['fuel_type']          ?? null;
        $transmission = $_POST['transmission']       ?? null;
        $color        = trim($_POST['color']        ?? '') ?: null;
        $bodyType     = trim($_POST['body_type']    ?? '') ?: null;
        $engineSize   = trim($_POST['engine_size']  ?? '') ?: null;
        $vinNumber    = trim($_POST['vin_number']   ?? '') ?: null;
        $condGrade    = $_POST['condition_grade']    ?? null;
        $svcHistory   = isset($_POST['service_history']) ? 1 : 0;
        $description  = trim($_POST['description']  ?? '') ?: null;
        $startPrice   = (float)($_POST['start_price']   ?? 0);
        $reservePrice = (float)($_POST['reserve_price'] ?? 0) ?: null;
        $minIncrement = (float)($_POST['min_increment'] ?? 500);
        $startTime    = trim($_POST['start_time']   ?? '') ?: null;
        $endTime      = trim($_POST['end_time']     ?? '') ?: null;
        $status       = in_array($_POST['status'] ?? '', ['upcoming','active','closed']) ? $_POST['status'] : 'upcoming';
        $branchId     = (int)($_POST['branch_id'] ?? 0) ?: null;

        if (!$itemName)       fail('Vehicle name is required.');
        if ($startPrice <= 0) fail('Start price must be greater than zero.');

        // ── Resolve make_id (handle "Add New Make") ──────────────
        $makeId = (int)($_POST['make_id'] ?? 0) ?: null;
        if (!$makeId && !empty($_POST['make_name_new'])) {
            $makeName = trim($_POST['make_name_new']);
            $pdo->prepare("INSERT IGNORE INTO makes (name) VALUES (?)")->execute([$makeName]);
            $makeId = (int)$pdo->lastInsertId();
            if (!$makeId) {
                $makeId = (int)$pdo->query(
                    "SELECT id FROM makes WHERE name=" . $pdo->quote($makeName)
                )->fetchColumn();
            }
        }

        // ── Determine make name for the image file names ─────────
        $makeName = 'vehicle';
        if ($makeId) {
            $mn = $pdo->prepare("SELECT name FROM makes WHERE id = ?");
            $mn->execute([$makeId]);
            $makeName = $mn->fetchColumn() ?: 'vehicle';
        } elseif (!empty($_POST['make_name_new'])) {
            $makeName = trim($_POST['make_name_new']);
        }

        // ── Helper: upload one image to ImageKit ─────────────────
        // 'public_id' is ImageKit's fileId, kept in the cloudinary_public_id
        // column (old name) so the file can be deleted later.
        $uploadToCloud = function(array $file, string $publicId) use ($makeName): array {
            // Validate type & size first
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
                return ['success' => false, 'message' => 'Invalid file type: ' . $mimeType];
            }
            if ($file['size'] > MAX_UPLOAD_SIZE) {
                return ['success' => false, 'message' => 'File too large. Max 10 MB.'];
            }
            try {
                $result = uploadToImageKit($file['tmp_name'], IMAGEKIT_VEHICLE_FOLDER, $publicId);
                return ['success' => true, 'url' => $result['url'], 'public_id' => $result['file_id']];
            } catch (\Exception $e) {
                error_log('[AHG] ImageKit upload failed: ' . $e->getMessage());
                return ['success' => false, 'message' => 'Image upload failed: ' . $e->getMessage()];
            }
        };

        // ── Main image ────────────────────────────────────────────
        $imageUrl         = trim($_POST['existing_image'] ?? '') ?: null;
        $cloudinaryPublicId = null;

        // Also carry existing public_id forward on edits
        if ($id) {
            $existing = $pdo->prepare("SELECT image_url, cloudinary_public_id FROM auctions WHERE id = ?");
            $existing->execute([$id]);
            $existingRow = $existing->fetch();
            $imageUrl    = $existingRow['image_url'] ?? $imageUrl;
            $cloudinaryPublicId = $existingRow['cloudinary_public_id'] ?? null;
        }

        if (!empty($_FILES['main_image']['tmp_name']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
            // Use auction ID for edit, or a temp timestamp for new (updated after insert)
            $tempId   = $id ?: ('new-' . time());
            $publicId = cloudinaryMainId((int)$tempId, $makeName, $itemName, (int)($year ?? date('Y')));
            $up = $uploadToCloud($_FILES['main_image'], $publicId);
            if (!$up['success']) fail($up['message']);
            $imageUrl           = $up['url'];
            $cloudinaryPublicId = $up['public_id'];
        }

        // ── UPDATE existing auction ───────────────────────────────
        if ($id) {
            // Reset current_price to start_price only when no bids exist yet
            $bidCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM bids WHERE auction_id = ?");
            $bidCheckStmt->execute([$id]);
            $hasBids = (int)$bidCheckStmt->fetchColumn() > 0;

            $pdo->prepare("
                UPDATE auctions SET
                    item_name=?, make_id=?, year=?, mileage=?, fuel_type=?,
                    transmission=?, color=?, body_type=?, engine_size=?, vin_number=?,
                    condition_grade=?, service_history=?, description=?,
                    start_price=?, reserve_price=?, min_increment=?,
                    start_time=?, end_time=?, status=?, branch_id=?,
                    current_price = CASE WHEN ? = 1 THEN current_price ELSE ? END,
                    image_url = CASE WHEN ? IS NOT NULL THEN ? ELSE image_url END,
                    cloudinary_public_id = CASE WHEN ? IS NOT NULL THEN ? ELSE cloudinary_public_id END
                WHERE id=?
            ")->execute([
                $itemName, $makeId, $year, $mileage, $fuelType,
                $transmission, $color, $bodyType, $engineSize, $vinNumber,
                $condGrade, $svcHistory, $description,
                $startPrice, $reservePrice, $minIncrement,
                $startTime, $endTime, $status, $branchId,
                (int)$hasBids, $startPrice,
                $imageUrl, $imageUrl,
                $cloudinaryPublicId, $cloudinaryPublicId,
                $id,
            ]);

            // Handle new gallery uploads on edit
            if (!empty($_FILES['gallery_images']['tmp_name'][0])) {
                $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM auction_gallery WHERE auction_id=?");
                $sortStmt->execute([$id]);
                $sort = (int)$sortStmt->fetchColumn();
                $seq  = $sort + 1;

                foreach ($_FILES['gallery_images']['tmp_name'] as $i => $tmp) {
                    if (!$tmp || $_FILES['gallery_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $gFile = [
                        'tmp_name' => $tmp,
                        'name'     => $_FILES['gallery_images']['name'][$i],
                        'size'     => $_FILES['gallery_images']['size'][$i],
                        'error'    => $_FILES['gallery_images']['error'][$i],
                        'type'     => $_FILES['gallery_images']['type'][$i],
                    ];
                    $gPublicId = cloudinaryGalleryId($id, $makeName, (int)($year ?? date('Y')), $seq);
                    $gUp = $uploadToCloud($gFile, $gPublicId);
                    if ($gUp['success']) {
                        $pdo->prepare("
                            INSERT INTO auction_gallery (auction_id, file_path, cloudinary_public_id, sort_order)
                            VALUES (?,?,?,?)
                        ")->execute([$id, $gUp['url'], $gUp['public_id'], $sort++]);
                        $seq++;
                    }
                }
            }

            ok(['message' => 'Auction updated successfully.', 'auction_id' => $id]);

        } else {
            // ── INSERT new auction ────────────────────────────────
            $pdo->prepare("
                INSERT INTO auctions
                    (item_name, make_id, year, mileage, fuel_type, transmission,
                     color, body_type, engine_size, vin_number, condition_grade,
                     service_history, description, start_price, current_price,
                     reserve_price, min_increment, start_time, end_time, status,
                     image_url, cloudinary_public_id, branch_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $itemName, $makeId, $year, $mileage, $fuelType, $transmission,
                $color, $bodyType, $engineSize, $vinNumber, $condGrade,
                $svcHistory, $description, $startPrice, $startPrice,
                $reservePrice, $minIncrement, $startTime, $endTime, $status,
                $imageUrl, $cloudinaryPublicId, $branchId,
            ]);
            $newId = (int)$pdo->lastInsertId();

            // The main image was uploaded before the lot had an ID, so its file
            // name says lot-000. The URL is already correct, so nothing to update.

            // ── Gallery uploads ───────────────────────────────────
            if (!empty($_FILES['gallery_images']['tmp_name'][0])) {
                $sort = 0;
                $seq  = 2; // sequence 1 = main image
                foreach ($_FILES['gallery_images']['tmp_name'] as $i => $tmp) {
                    if (!$tmp || $_FILES['gallery_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $gFile = [
                        'tmp_name' => $tmp,
                        'name'     => $_FILES['gallery_images']['name'][$i],
                        'size'     => $_FILES['gallery_images']['size'][$i],
                        'error'    => $_FILES['gallery_images']['error'][$i],
                        'type'     => $_FILES['gallery_images']['type'][$i],
                    ];
                    $gPublicId = cloudinaryGalleryId($newId, $makeName, (int)($year ?? date('Y')), $seq);
                    $gUp = $uploadToCloud($gFile, $gPublicId);
                    if ($gUp['success']) {
                        $pdo->prepare("
                            INSERT INTO auction_gallery (auction_id, file_path, cloudinary_public_id, sort_order)
                            VALUES (?,?,?,?)
                        ")->execute([$newId, $gUp['url'], $gUp['public_id'], $sort++]);
                        $seq++;
                    }
                }
            }

            ok(['message' => 'Vehicle added successfully!', 'auction_id' => $newId]);
        }
    }

// ════════════════════════════════════════════════════════════
// DELETE GALLERY IMAGE (already handled but ensure it exists)
// ════════════════════════════════════════════════════════════

// ════════════════════════════════════════════════════════════
// CSV IMPORT
// ════════════════════════════════════════════════════════════
    case 'import_csv': {
        adminOnly();

        if (empty($_FILES['csv_file']['tmp_name'])) fail('No CSV file uploaded.');
        if (pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION) !== 'csv') fail('File must be a .csv');

        $defaultStatus = in_array($_POST['default_status'] ?? '', ['upcoming','active','closed'])
            ? $_POST['default_status'] : 'upcoming';
        $defaultStart  = trim($_POST['default_start'] ?? '') ?: null;
        $defaultEnd    = trim($_POST['default_end']   ?? '') ?: null;

        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$handle) fail('Could not read CSV file.');

        // Read header row
        $headers = fgetcsv($handle);
        if (!$headers) { fclose($handle); fail('CSV file appears to be empty.'); }
        $headers = array_map('trim', $headers);

        $requiredCols = ['item_name', 'make_name', 'start_price'];
        foreach ($requiredCols as $col) {
            if (!in_array($col, $headers)) {
                fclose($handle);
                fail("Missing required column: $col. Please use the sample CSV as a template.");
            }
        }

        // Cache makes
        $makesMap = [];
        foreach ($pdo->query("SELECT id, name FROM makes")->fetchAll() as $m) {
            $makesMap[strtolower($m['name'])] = $m['id'];
        }

        $imported = 0;
        $failed   = 0;
        $errors   = [];
        $rowNum   = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count($row) < 2) continue;

            $data = array_combine(
                $headers,
                array_pad(array_map('trim', $row), count($headers), '')
            );

            // Validate required
            if (empty($data['item_name']) || empty($data['start_price'])) {
                $errors[] = "Row $rowNum: Missing item_name or start_price — skipped.";
                $failed++;
                continue;
            }
            $startPrice = (float)$data['start_price'];
            if ($startPrice <= 0) {
                $errors[] = "Row $rowNum: Invalid start_price '{$data['start_price']}' — skipped.";
                $failed++;
                continue;
            }

            // Resolve make
            $makeId = null;
            if (!empty($data['make_name'])) {
                $makeKey = strtolower($data['make_name']);
                if (!isset($makesMap[$makeKey])) {
                    $pdo->prepare("INSERT IGNORE INTO makes (name) VALUES (?)")->execute([$data['make_name']]);
                    $newMakeId = $pdo->lastInsertId();
                    if (!$newMakeId) {
                        $newMakeId = $pdo->query("SELECT id FROM makes WHERE name=" . $pdo->quote($data['make_name']))->fetchColumn();
                    }
                    $makesMap[$makeKey] = $newMakeId;
                }
                $makeId = $makesMap[$makeKey];
            }

            $fuelType    = in_array(strtolower($data['fuel_type'] ?? ''), ['petrol','diesel','electric','hybrid','other']) ? strtolower($data['fuel_type']) : null;
            $transmission= in_array(strtolower($data['transmission'] ?? ''), ['manual','automatic','other']) ? strtolower($data['transmission']) : null;
            $status      = in_array($data['status'] ?? '', ['upcoming','active','closed']) ? $data['status'] : $defaultStatus;
            $startTime   = !empty($data['start_time']) ? $data['start_time'] : ($defaultStart ? str_replace('T',' ',$defaultStart) : null);
            $endTime     = !empty($data['end_time'])   ? $data['end_time']   : ($defaultEnd   ? str_replace('T',' ',$defaultEnd)   : null);
            $reservePrice= !empty($data['reserve_price']) ? (float)$data['reserve_price'] : null;
            $minIncrement= !empty($data['min_increment']) ? (float)$data['min_increment'] : 500.00;
            $year        = !empty($data['year'])    ? (int)$data['year']    : null;
            $mileage     = !empty($data['mileage']) ? (int)$data['mileage'] : null;
            $imageUrl    = !empty($data['image_url']) ? $data['image_url'] : null;

            try {
                $pdo->prepare("
                    INSERT INTO auctions
                        (item_name, make_id, year, mileage, fuel_type, transmission,
                         color, body_type, engine_size, description,
                         start_price, current_price, reserve_price, min_increment,
                         start_time, end_time, status, image_url)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $data['item_name'], $makeId, $year, $mileage, $fuelType, $transmission,
                    $data['color'] ?? null, $data['body_type'] ?? null, $data['engine_size'] ?? null,
                    $data['description'] ?? null,
                    $startPrice, $startPrice, $reservePrice, $minIncrement,
                    $startTime, $endTime, $status, $imageUrl
                ]);
                $imported++;
            } catch (PDOException $e) {
                $errors[] = "Row $rowNum ({$data['item_name']}): DB error — " . $e->getMessage();
                $failed++;
            }
        }
        fclose($handle);

        // Log the import
        $pdo->prepare("
            INSERT INTO csv_import_log (admin_id, filename, rows_total, rows_imported, rows_failed, error_details)
            VALUES (?,?,?,?,?,?)
        ")->execute([
            $currentUser['sub'],
            $_FILES['csv_file']['name'],
            $imported + $failed,
            $imported,
            $failed,
            $errors ? json_encode($errors) : null
        ]);

        $msg = "Import complete: $imported vehicle(s) imported";
        if ($failed) $msg .= ", $failed row(s) failed.";

        ok(['message' => $msg, 'imported' => $imported, 'failed' => $failed, 'errors' => $errors]);
    }

    // ── ADMIN MANUAL BID (place bid on behalf of a user) ────────
    case 'admin_place_bid': {
        $admin = adminOnly();

        $auctionId = (int)($_POST['auction_id'] ?? 0);
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $amount    = (float)($_POST['amount']     ?? 0);
        $note      = trim($_POST['note']          ?? '');

        if (!$auctionId || !$targetUserId || $amount <= 0) {
            fail('Please fill in all required fields.');
        }

        // Verify target user exists and is a verified bidder
        $uStmt = $pdo->prepare("SELECT id, full_name, email, bidder_number, bidder_status FROM users WHERE id = ?");
        $uStmt->execute([$targetUserId]);
        $targetUser = $uStmt->fetch();

        if (!$targetUser) fail('User not found.');
        if ($targetUser['bidder_status'] !== 'verified_bidder') {
            fail('User is not a verified bidder. Only verified bidders can have bids placed on their behalf.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                SELECT id, status, end_time, current_price, start_price,
                       min_increment, reserve_price, item_name
                FROM auctions WHERE id = ? FOR UPDATE
            ");
            $stmt->execute([$auctionId]);
            $auction = $stmt->fetch();

            if (!$auction)                       { $pdo->rollBack(); fail('Auction not found.'); }
            if ($auction['status'] === 'closed') { $pdo->rollBack(); fail('This auction is already closed.'); }

            $currentPrice = (float)$auction['current_price'];
            $minBid = $currentPrice > 0
                ? $currentPrice + (float)$auction['min_increment']
                : (float)$auction['start_price'];

            if ($amount < $minBid) {
                $pdo->rollBack();
                fail(sprintf('Minimum bid is R %s.', number_format($minBid, 2)));
            }

            // Mark all previous winning bids as non-winning
            $pdo->prepare("UPDATE bids SET is_winning = 0 WHERE auction_id = ?")
                ->execute([$auctionId]);

            // Insert bid — ip_address uses ADMIN:{id} tag (fits in VARCHAR 45)
            // The internal note is stored in bidder_name suffix if provided
            $ipTag = 'ADMIN:' . $admin['sub'];
            $displayName = $targetUser['full_name'] . ($note ? ' [Note: ' . substr($note, 0, 80) . ']' : '');
            $pdo->prepare("
                INSERT INTO bids
                    (auction_id, user_id, bid_amount, amount, bidder_name, bidder_number,
                     ip_address, is_winning, bid_time, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ")->execute([
                $auctionId,
                $targetUserId,
                $amount,
                $amount,
                $displayName,
                $targetUser['bidder_number'],
                $ipTag,
            ]);
            $newBidId = (int)$pdo->lastInsertId();

            // Update auction current price
            $pdo->prepare("UPDATE auctions SET current_price = ? WHERE id = ?")
                ->execute([$amount, $auctionId]);

            $pdo->commit();

            // Send confirmation email to the user (non-fatal)
            try {
                require_once __DIR__ . '/email_helper.php';

                // Fetch vehicle name with make
                $aStmt = $pdo->prepare("SELECT a.*, CONCAT(a.year,' ',m.name,' ',a.item_name) AS vehicle_name
                    FROM auctions a LEFT JOIN makes m ON m.id = a.make_id WHERE a.id = ?");
                $aStmt->execute([$auctionId]);
                $auctionData = $aStmt->fetch(PDO::FETCH_ASSOC);
                $vehicleName = $auctionData['vehicle_name'] ?? ('Auction #' . $auctionId);

                // Count bids
                $bidCount = $pdo->prepare("SELECT COUNT(*) FROM bids WHERE auction_id = ?");
                $bidCount->execute([$auctionId]);
                $totalBids = (int)$bidCount->fetchColumn();

                if (canEmailUser((int)$targetUser['id'], $targetUser['email'])) sendBidConfirmationEmail([
                    'email'            => $targetUser['email'],
                    'name'             => $targetUser['full_name'],
                    'vehicle_name'     => $vehicleName,
                    'bid_amount'       => $amount,
                    'current_high_bid' => $amount,
                    'bid_count'        => $totalBids,
                    'auction_id'       => $auctionId,
                    'auction_end_time' => $auctionData['end_time'] ?? '',
                    'bidder_number'    => $targetUser['bidder_number'] ?? '',
                ]);

                // Send outbid alert to previous highest bidder (if any)
                $prevBid = $pdo->prepare("
                    SELECT b.user_id, COALESCE(b.bid_amount, b.amount) AS bid_amount, u.email, u.full_name
                    FROM bids b JOIN users u ON u.id = b.user_id
                    WHERE b.auction_id = ? AND b.id != ? AND b.user_id != ?
                    ORDER BY COALESCE(b.bid_amount, b.amount) DESC LIMIT 1
                ");
                $prevBid->execute([$auctionId, $newBidId, $targetUserId]);
                $outbidUser = $prevBid->fetch(PDO::FETCH_ASSOC);

                if ($outbidUser) {
                    notifyUser((int)$outbidUser['user_id'], 'outbid', $auctionId,
                        'Outbid: ' . ($auctionData['item_name'] ?? $vehicleName),
                        'New highest bid ' . formatRand($amount) . ' (yours was ' . formatRand((float)$outbidUser['bid_amount']) . ').',
                        'auction.php?id=' . $auctionId);
                }
                if ($outbidUser && wantsEmail((int)$outbidUser['user_id'], 'outbid')
                    && canEmailUser((int)$outbidUser['user_id'], $outbidUser['email'])) {
                    sendOutbidAlertEmail([
                        'user_id'          => (int)$outbidUser['user_id'],
                        'email'            => $outbidUser['email'],
                        'name'             => $outbidUser['full_name'],
                        'vehicle_name'     => $vehicleName,
                        'their_bid'        => (float)$outbidUser['bid_amount'],
                        'new_high_bid'     => $amount,
                        'auction_id'       => $auctionId,
                        'auction_end_time' => $auctionData['end_time'] ?? '',
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('Admin bid email error: ' . $e->getMessage());
            }

            ok([
                'bid_id'    => $newBidId,
                'new_price' => $amount,
                'message'   => sprintf(
                    'Bid of R %s placed successfully for %s on "%s".',
                    number_format($amount, 2),
                    htmlspecialchars($targetUser['full_name'], ENT_QUOTES),
                    htmlspecialchars($auction['item_name'], ENT_QUOTES)
                ),
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('admin_place_bid error: ' . $e->getMessage());
            fail('An error occurred while placing the bid. Please try again.');
        }
    }

    // ── ANALYTICS DATA ───────────────────────────────────────────
    case 'get_analytics': {
        adminOnly();

        // 1. Bids per day — last 30 days
        $bidsPerDay = $pdo->query("
            SELECT DATE(COALESCE(bid_time, created_at)) AS day, COUNT(*) AS total
            FROM bids
            WHERE COALESCE(bid_time, created_at) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY day ORDER BY day ASC
        ")->fetchAll();

        // Fill in zeros for missing days
        $bidsMap = [];
        foreach ($bidsPerDay as $r) $bidsMap[$r['day']] = (int)$r['total'];
        $bidsChartLabels = [];
        $bidsChartData   = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $bidsChartLabels[] = date('d M', strtotime($d));
            $bidsChartData[]   = $bidsMap[$d] ?? 0;
        }

        // 2. Auction status breakdown
        $statusRows = $pdo->query("
            SELECT status, COUNT(*) AS total FROM auctions GROUP BY status
        ")->fetchAll();
        $statusLabels = [];
        $statusData   = [];
        foreach ($statusRows as $r) {
            $statusLabels[] = ucfirst($r['status']);
            $statusData[]   = (int)$r['total'];
        }

        // 3. Top 10 closed auctions by final price
        $topAuctions = $pdo->query("
            SELECT item_name, COALESCE(final_price, current_price, 0) AS price,
                   (SELECT COUNT(*) FROM bids WHERE auction_id = auctions.id) AS bid_count
            FROM auctions
            WHERE status = 'closed'
            ORDER BY price DESC
            LIMIT 10
        ")->fetchAll();

        // 4. Top 10 most active bidders
        $topBidders = $pdo->query("
            SELECT u.full_name, u.bidder_number,
                   COUNT(b.id) AS total_bids,
                   SUM(COALESCE(b.bid_amount, b.amount)) AS total_value
            FROM bids b
            JOIN users u ON u.id = b.user_id
            GROUP BY b.user_id
            ORDER BY total_bids DESC
            LIMIT 10
        ")->fetchAll();

        // 5. User registrations per day — last 30 days
        $regsPerDay = $pdo->query("
            SELECT DATE(created_at) AS day, COUNT(*) AS total
            FROM users
            WHERE role = 'user' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY day ORDER BY day ASC
        ")->fetchAll();
        $regsMap = [];
        foreach ($regsPerDay as $r) $regsMap[$r['day']] = (int)$r['total'];
        $regsChartData = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $regsChartData[] = $regsMap[$d] ?? 0;
        }

        // 6. Revenue by month — last 6 months
        $revenueRows = $pdo->query("
            SELECT DATE_FORMAT(COALESCE(sold_date, updated_at), '%Y-%m') AS month,
                   SUM(COALESCE(final_price, current_price, 0)) AS revenue
            FROM auctions
            WHERE is_sold = 1
              AND COALESCE(sold_date, updated_at) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY month ORDER BY month ASC
        ")->fetchAll();
        $revLabels = [];
        $revData   = [];
        foreach ($revenueRows as $r) {
            $revLabels[] = date('M Y', strtotime($r['month'] . '-01'));
            $revData[]   = (float)$r['revenue'];
        }

        ok([
            'bids_per_day'    => ['labels' => $bidsChartLabels, 'data' => $bidsChartData],
            'registrations'   => ['labels' => $bidsChartLabels, 'data' => $regsChartData],
            'auction_status'  => ['labels' => $statusLabels,   'data' => $statusData],
            'top_auctions'    => $topAuctions,
            'top_bidders'     => $topBidders,
            'revenue_monthly' => ['labels' => $revLabels, 'data' => $revData],
        ]);
    }

    // ── SEARCH VERIFIED BIDDERS (for manual bid user picker) ────
    case 'search_bidders': {
        adminOnly();
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) ok(['users' => []]);

        ahgSchemaReady();
        $stmt = $pdo->prepare("
            SELECT id, full_name, email, phone, bidder_number, is_floor_bidder AS floor
            FROM users
            WHERE bidder_status = 'verified_bidder' AND status = 'active'
              AND (full_name LIKE ? OR email LIKE ? OR bidder_number LIKE ? OR phone LIKE ?)
            ORDER BY full_name ASC
            LIMIT 20
        ");
        $like = "%$q%";
        $stmt->execute([$like, $like, $like, $like]);
        $users = array_map(fn($u) => ['email' => displayEmail($u['email'])] + $u, $stmt->fetchAll());
        ok(['users' => $users]);
    }

    // ── SEARCH ACTIVE/UPCOMING AUCTIONS (for manual bid auction picker) ──
    case 'search_active_auctions': {
        adminOnly();
        $q = trim($_GET['q'] ?? '');

        $stmt = $pdo->prepare("
            SELECT a.id, a.item_name, a.current_price, a.start_price, a.min_increment, a.status,
                   a.year, a.mileage, a.fuel_type, a.transmission, a.color, a.body_type,
                   a.engine_size, a.vin_number, a.condition_grade, a.description, a.image_url,
                   m.name AS make_name
            FROM auctions a
            LEFT JOIN makes m ON m.id = a.make_id
            WHERE a.status IN ('active', 'upcoming')
              AND (? = '' OR a.item_name LIKE ? OR m.name LIKE ?)
            ORDER BY FIELD(a.status,'active','upcoming'), a.end_time ASC
            LIMIT 30
        ");
        $like = "%$q%";
        $stmt->execute([$q, $like, $like]);
        ok(['auctions' => $stmt->fetchAll()]);
    }

    case 'get_auction_detail': {
        adminOnly();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) fail('Missing id.', 400);
        $stmt = $pdo->prepare("
            SELECT a.id, a.item_name, a.year, a.mileage, a.fuel_type, a.transmission,
                   a.color, a.body_type, a.engine_size, a.vin_number, a.condition_grade,
                   a.description, a.image_url, a.current_price, a.start_price, a.status,
                   m.name AS make
            FROM auctions a
            LEFT JOIN makes m ON m.id = a.make_id
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) fail('Auction not found.', 404);
        ok(['auction' => $row]);
    }

// ════════════════════════════════════════════════════════════
// EVENTS — CRUD + Vehicle Assignment + Bulk Updates
// ════════════════════════════════════════════════════════════

    case 'save_event': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $id          = (int)($_POST['event_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $bankLot     = trim($_POST['bank_lot'] ?? '');
        $province    = trim($_POST['province'] ?? '');
        $venue       = trim($_POST['venue'] ?? '');
        $eventDate   = trim($_POST['event_date'] ?? '');
        $eventTime   = trim($_POST['event_time'] ?? '') ?: null;
        $startTime   = trim($_POST['start_time'] ?? '') ?: null;
        $endTime     = trim($_POST['end_time'] ?? '') ?: null;
        $description = trim($_POST['description'] ?? '');
        $status      = trim($_POST['status'] ?? 'draft');
        $isFeatured  = (int)($_POST['is_featured'] ?? 0);

        if (!$title) fail('Event title is required.');
        if (!$eventDate) fail('Event date is required.');

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
        $slug = trim($slug, '-') . '-' . date('Y-m-d', strtotime($eventDate));

        // Handle banner image upload
        $bannerImage = null;
        if (!empty($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
            $bFile = $_FILES['banner_image'];
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($bFile['tmp_name']);
            if (in_array($mime, $allowedTypes) && $bFile['size'] <= 10 * 1024 * 1024) {
                require_once __DIR__ . '/imagekit_helper.php';
                try {
                    $up = uploadToImageKit($bFile['tmp_name'], 'pva/events', 'event-' . $slug);
                    // Stored as a 1920×1080 crop, like the Cloudinary banners were
                    $bannerImage = imagekitTransformUrl($up['url'], 'w-1920,h-1080');
                } catch (Exception $e) {
                    error_log('[AHG] ImageKit event banner upload failed: ' . $e->getMessage());
                }
                // Fallback to local
                if (!$bannerImage) {
                    $uploadResult = handleUpload($bFile, UPLOAD_ROOT . 'events/', $allowedTypes, 'event_banner');
                    if ($uploadResult['success']) $bannerImage = $uploadResult['path'];
                }
            }
        }

        if ($id) {
            $bannerSQL = $bannerImage ? ', banner_image=?' : '';
            $stmt = $pdo->prepare("UPDATE auction_events SET
                title=?, slug=?, bank_lot=?, province=?, venue=?, event_date=?, event_time=?,
                start_time=?, end_time=?, description=?, status=?, is_featured=? $bannerSQL
                WHERE id=?");
            $params = [$title, $slug, $bankLot, $province, $venue, $eventDate, $eventTime,
                $startTime, $endTime, $description, $status, $isFeatured];
            if ($bannerImage) $params[] = $bannerImage;
            $params[] = $id;
            $stmt->execute($params);
            ok(['message' => 'Event updated.', 'event_id' => $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO auction_events
                (title, slug, bank_lot, province, venue, event_date, event_time, start_time, end_time, description, status, is_featured, banner_image, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$title, $slug, $bankLot, $province, $venue, $eventDate, $eventTime,
                $startTime, $endTime, $description, $status, $isFeatured, $bannerImage, $user['sub']]);
            ok(['message' => 'Event created.', 'event_id' => $pdo->lastInsertId()]);
        }
    }

    case 'delete_event': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);
        $id = (int)($_POST['event_id'] ?? 0);
        if (!$id) fail('Missing event ID.');
        $pdo->prepare("DELETE FROM auction_events WHERE id=?")->execute([$id]);
        ok(['message' => 'Event deleted.']);
    }

    case 'event_add_vehicles': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $eventId    = (int)($_POST['event_id'] ?? 0);
        $vehicleIds = json_decode($_POST['vehicle_ids'] ?? '[]', true);
        if (!$eventId || empty($vehicleIds)) fail('Missing event or vehicles.');

        $stmt = $pdo->prepare("INSERT IGNORE INTO event_vehicles (event_id, auction_id, sort_order) VALUES (?,?,?)");
        $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM event_vehicles WHERE event_id=$eventId")->fetchColumn();
        $added = 0;
        foreach ($vehicleIds as $vid) {
            $maxSort++;
            $stmt->execute([$eventId, (int)$vid, $maxSort]);
            $added += $stmt->rowCount();
        }
        ok(['message' => "$added vehicle(s) added to event.", 'added' => $added]);
    }

    case 'event_remove_vehicle': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);
        $eventId   = (int)($_POST['event_id'] ?? 0);
        $auctionId = (int)($_POST['auction_id'] ?? 0);
        if (!$eventId || !$auctionId) fail('Missing IDs.');
        $pdo->prepare("DELETE FROM event_vehicles WHERE event_id=? AND auction_id=?")->execute([$eventId, $auctionId]);
        ok(['message' => 'Vehicle removed from event.']);
    }

    case 'event_toggle_hot': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);
        $eventId   = (int)($_POST['event_id'] ?? 0);
        $auctionId = (int)($_POST['auction_id'] ?? 0);
        if (!$eventId || !$auctionId) fail('Missing IDs.');
        $pdo->prepare("UPDATE event_vehicles SET is_hot = NOT is_hot WHERE event_id=? AND auction_id=?")->execute([$eventId, $auctionId]);
        $isHot = (int)$pdo->query("SELECT is_hot FROM event_vehicles WHERE event_id=$eventId AND auction_id=$auctionId")->fetchColumn();
        ok(['message' => $isHot ? 'Marked as hot.' : 'Removed from hot list.', 'is_hot' => $isHot]);
    }

    case 'event_bulk_update': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $eventId    = (int)($_POST['event_id'] ?? 0);
        $vehicleIds = json_decode($_POST['vehicle_ids'] ?? '[]', true);
        $field      = trim($_POST['field'] ?? '');
        $value      = trim($_POST['value'] ?? '');

        if (!$eventId || empty($vehicleIds)) fail('Missing event or vehicles.');

        $allowed = ['start_time', 'end_time', 'status'];
        if (!in_array($field, $allowed)) fail('Invalid field for bulk update.');

        // Update the auctions table for selected vehicles
        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $params = array_merge([$value], array_map('intval', $vehicleIds));

        if ($field === 'status') {
            $stmt = $pdo->prepare("UPDATE auctions SET status=? WHERE id IN ($placeholders)");
        } elseif ($field === 'start_time') {
            $stmt = $pdo->prepare("UPDATE auctions SET start_time=? WHERE id IN ($placeholders)");
        } elseif ($field === 'end_time') {
            $stmt = $pdo->prepare("UPDATE auctions SET end_time=? WHERE id IN ($placeholders)");
        }
        $stmt->execute($params);
        $count = $stmt->rowCount();
        ok(['message' => "$count vehicle(s) updated.", 'updated' => $count]);
    }

    case 'event_bulk_dates': {
        $user = requireAuth();
        if ($user['role'] !== 'admin') fail('Unauthorised.', 403);

        $eventId   = (int)($_POST['event_id'] ?? 0);
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime   = trim($_POST['end_time'] ?? '');
        $scope     = trim($_POST['scope'] ?? 'all'); // 'all' or 'selected'
        $vehicleIds = json_decode($_POST['vehicle_ids'] ?? '[]', true);

        if (!$eventId) fail('Missing event ID.');
        if (!$startTime && !$endTime) fail('Provide at least one date.');

        if ($scope === 'selected' && !empty($vehicleIds)) {
            $ids = array_map('intval', $vehicleIds);
        } else {
            // All vehicles in this event
            $ids = $pdo->query("SELECT auction_id FROM event_vehicles WHERE event_id=$eventId")->fetchAll(PDO::FETCH_COLUMN);
        }

        if (empty($ids)) fail('No vehicles to update.');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = [];
        $sets = [];
        if ($startTime) { $sets[] = 'start_time=?'; $params[] = $startTime; }
        if ($endTime)   { $sets[] = 'end_time=?';   $params[] = $endTime; }
        $params = array_merge($params, $ids);

        $pdo->prepare("UPDATE auctions SET " . implode(',', $sets) . " WHERE id IN ($placeholders)")->execute($params);
        $count = count($ids);
        ok(['message' => "Dates updated for $count vehicle(s).", 'updated' => $count]);
    }

    case 'get_event_vehicles': {
        $eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
        if (!$eventId) fail('Missing event ID.');

        $stmt = $pdo->prepare("
            SELECT a.id, a.item_name, a.year, a.status, a.current_price, a.start_price,
                   a.start_time, a.end_time, a.image_url,
                   m.name AS make_name, ev.is_hot, ev.lot_number, ev.sort_order
            FROM event_vehicles ev
            JOIN auctions a ON a.id = ev.auction_id
            LEFT JOIN makes m ON m.id = a.make_id
            WHERE ev.event_id = ?
            ORDER BY ev.sort_order, a.item_name
        ");
        $stmt->execute([$eventId]);
        ok(['vehicles' => $stmt->fetchAll()]);
    }

// ════════════════════════════════════════════════════════════
// TESTIMONIALS CRUD (admin)
// ════════════════════════════════════════════════════════════

    case 'add_testimonial': {
        adminOnly();
        $headline = trim($_POST['headline'] ?? '');
        if (!$headline) fail('Headline is required.');
        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) fail('Image is required.');

        $file = $_FILES['image'];
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowedTypes)) fail('Invalid image type. Use JPG, PNG, or WebP.');
        if ($file['size'] > 10 * 1024 * 1024) fail('Image too large. Max 10MB.');

        // Upload to ImageKit
        require_once __DIR__ . '/imagekit_helper.php';
        try {
            $up = uploadToImageKit($file['tmp_name'], 'pva/testimonials', 'testimonial-' . $headline);
            // Stored as an 800×600 crop, like the Cloudinary testimonials were
            $imageUrl = imagekitTransformUrl($up['url'], 'w-800,h-600');
            $publicId = $up['file_id'];
        } catch (Exception $e) {
            error_log('[AHG] ImageKit testimonial upload failed: ' . $e->getMessage());
            // Fallback: save locally
            $uploadResult = handleUpload($file, UPLOAD_ROOT . 'testimonials/', $allowedTypes, 'testimonial');
            if (!$uploadResult['success']) fail($uploadResult['message']);
            $imageUrl = $uploadResult['path'];
            $publicId = null;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO testimonials (headline, image_url, cloudinary_public_id) VALUES (?, ?, ?)");
            $stmt->execute([$headline, $imageUrl, $publicId]);
        } catch (PDOException $e) {
            fail('Database error — please ensure the testimonials table exists. Run sql/sql_updates.sql first.');
        }
        ok(['message' => 'Testimonial added successfully.', 'id' => $pdo->lastInsertId()]);
    }

    case 'update_testimonial': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) fail('Missing testimonial ID.');

        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';

        $allowed = ['headline', 'is_active', 'sort_order'];
        if (!in_array($field, $allowed)) fail('Invalid field.');

        if ($field === 'is_active') $value = (int)$value;
        if ($field === 'sort_order') $value = (int)$value;

        $stmt = $pdo->prepare("UPDATE testimonials SET $field = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
        ok(['message' => 'Testimonial updated.']);
    }

    case 'delete_testimonial': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) fail('Missing testimonial ID.');

        // Get the stored image for cleanup
        $stmt = $pdo->prepare("SELECT image_url, cloudinary_public_id FROM testimonials WHERE id = ?");
        $stmt->execute([$id]);
        $t = $stmt->fetch();

        // Delete the image wherever it is stored
        if ($t) {
            require_once __DIR__ . '/imagekit_helper.php';
            deleteStoredImage($t['image_url'], $t['cloudinary_public_id']);
        }

        $pdo->prepare("DELETE FROM testimonials WHERE id = ?")->execute([$id]);
        ok(['message' => 'Testimonial deleted.']);
    }

    case 'replace_testimonial_image': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) fail('Missing testimonial ID.');
        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) fail('Image file required.');

        $file = $_FILES['image'];
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowedTypes)) fail('Invalid type — use JPG, PNG, or WebP.');
        if ($file['size'] > 10 * 1024 * 1024) fail('Image too large. Max 10 MB.');

        // Fetch the old image for cleanup
        $stmt = $pdo->prepare("SELECT headline, image_url, cloudinary_public_id FROM testimonials WHERE id = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();

        // Upload new image
        require_once __DIR__ . '/imagekit_helper.php';
        try {
            $up = uploadToImageKit($file['tmp_name'], 'pva/testimonials', 'testimonial-' . ($old['headline'] ?? $id));
            $imageUrl = imagekitTransformUrl($up['url'], 'w-800,h-600');
            $publicId = $up['file_id'];
        } catch (Exception $e) {
            error_log('[AHG] ImageKit testimonial upload failed: ' . $e->getMessage());
            $up = handleUpload($file, UPLOAD_ROOT.'testimonials/', $allowedTypes, 'testimonial');
            if (!$up['success']) fail($up['message']);
            $imageUrl = $up['path']; $publicId = null;
        }

        // Delete the old image
        if ($old) deleteStoredImage($old['image_url'], $old['cloudinary_public_id']);

        $pdo->prepare("UPDATE testimonials SET image_url=?, cloudinary_public_id=? WHERE id=?")
            ->execute([$imageUrl, $publicId, $id]);
        ok(['message'=>'Image replaced.','image_url'=>$imageUrl]);
    }

// ════════════════════════════════════════════════════════════
// TEAM MEMBERS CRUD (admin)
// ════════════════════════════════════════════════════════════

    case 'add_team_member': {
        adminOnly();
        $name     = trim($_POST['name'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $bio      = trim($_POST['bio'] ?? '');
        $email    = trim($_POST['email'] ?? '') ?: null;
        $phone    = trim($_POST['phone'] ?? '') ?: null;

        if (!$name)     fail('Name is required.');
        if (!$position) fail('Position is required.');

        $photoUrl = '';
        $publicId = '';

        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            $type = mime_content_type($file['tmp_name']);
            if (!in_array($type, $allowedTypes)) fail('Photo must be JPG, PNG, or WebP.');
            if ($file['size'] > 10 * 1024 * 1024) fail('Photo must be under 10 MB.');

            require_once __DIR__ . '/imagekit_helper.php';
            try {
                $up = uploadToImageKit($file['tmp_name'], 'pva/team', 'team-' . $name);
                // Stored as a face-centred 600×600 square, like the Cloudinary photos were
                $photoUrl = imagekitTransformUrl($up['url'], 'w-600,h-600,fo-face');
                $publicId = $up['file_id'];
            } catch (Exception $e) {
                error_log('[AHG] ImageKit team photo upload failed: ' . $e->getMessage());
            }
            if (!$photoUrl) {
                $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$type] ?? 'jpg';
                $dest = UPLOAD_ROOT . 'team/' . uniqid('tm_') . '.' . $ext;
                @mkdir(dirname($dest), 0755, true);
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $photoUrl = APP_URL . '/uploads/team/' . basename($dest);
                }
            }
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO team_members (name, position, bio, email, phone, photo_url, cloudinary_public_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $position, $bio ?: null, $email, $phone, $photoUrl, $publicId]);
        } catch (PDOException $e) {
            fail('Database error — ensure the team_members table exists. Run the SQL in the admin panel.');
        }
        ok(['message' => 'Team member added.', 'id' => $pdo->lastInsertId()]);
    }

    case 'update_team_member': {
        adminOnly();
        $id    = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        if (!$id) fail('Missing ID.');
        $allowed = ['name', 'position', 'bio', 'email', 'phone', 'is_active', 'sort_order'];
        if (!in_array($field, $allowed)) fail('Invalid field.');
        if ($field === 'is_active')   $value = (int)$value;
        if ($field === 'sort_order')  $value = (int)$value;
        if (in_array($field, ['bio','email','phone']) && $value === '') $value = null;
        $pdo->prepare("UPDATE team_members SET $field = ? WHERE id = ?")->execute([$value, $id]);
        ok(['message' => 'Updated.']);
    }

    case 'delete_team_member': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) fail('Missing ID.');
        $row = $pdo->prepare("SELECT photo_url, cloudinary_public_id FROM team_members WHERE id = ?");
        $row->execute([$id]);
        $tm = $row->fetch();
        if ($tm) {
            require_once __DIR__ . '/imagekit_helper.php';
            deleteStoredImage($tm['photo_url'], $tm['cloudinary_public_id']);
        }
        $pdo->prepare("DELETE FROM team_members WHERE id = ?")->execute([$id]);
        ok(['message' => 'Team member deleted.']);
    }

    case 'replace_team_member_photo': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) fail('Missing ID.');
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) fail('Photo file required.');

        $file = $_FILES['photo'];
        $allowedTypes = ['image/jpeg','image/png','image/webp'];
        $type = mime_content_type($file['tmp_name']);
        if (!in_array($type, $allowedTypes)) fail('Photo must be JPG, PNG, or WebP.');
        if ($file['size'] > 10 * 1024 * 1024) fail('Photo must be under 10 MB.');

        $old = $pdo->prepare("SELECT name, photo_url, cloudinary_public_id FROM team_members WHERE id = ?");
        $old->execute([$id]);
        $tm = $old->fetch();

        require_once __DIR__ . '/imagekit_helper.php';
        $photoUrl = ''; $publicId = '';
        try {
            $up = uploadToImageKit($file['tmp_name'], 'pva/team', 'team-' . ($tm['name'] ?? $id));
            $photoUrl = imagekitTransformUrl($up['url'], 'w-600,h-600,fo-face');
            $publicId = $up['file_id'];
        } catch (Exception $e) {
            error_log('[AHG] ImageKit team photo upload failed: ' . $e->getMessage());
        }
        if (!$photoUrl) {
            $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$type]??'jpg';
            $dest = UPLOAD_ROOT.'team/'.uniqid('tm_').'.'.$ext;
            @mkdir(dirname($dest),0755,true);
            if (move_uploaded_file($file['tmp_name'],$dest)) $photoUrl = APP_URL.'/uploads/team/'.basename($dest);
        }
        if (!$photoUrl) fail('Upload failed.');

        if ($tm) deleteStoredImage($tm['photo_url'], $tm['cloudinary_public_id']);

        $pdo->prepare("UPDATE team_members SET photo_url=?, cloudinary_public_id=? WHERE id=?")->execute([$photoUrl,$publicId,$id]);
        ok(['message'=>'Photo updated.','photo_url'=>$photoUrl]);
    }

// ════════════════════════════════════════════════════════════
// POPUP NOTIFICATIONS CRUD (admin)
// ════════════════════════════════════════════════════════════

    case 'add_notification': {
        adminOnly();
        $message = trim($_POST['message'] ?? '');
        $type = $_POST['type'] ?? 'custom';
        $icon = $_POST['icon'] ?? 'fa-bell';
        $frequency = max(5, (int)($_POST['frequency'] ?? 10));

        if (!$message) fail('Message is required.');
        if (!in_array($type, ['bid', 'win', 'leading', 'custom'])) $type = 'custom';

        try {
            $stmt = $pdo->prepare("INSERT INTO popup_notifications (message, type, icon, frequency) VALUES (?, ?, ?, ?)");
            $stmt->execute([$message, $type, $icon, $frequency]);
        } catch (PDOException $e) {
            fail('Database error — please ensure the popup_notifications table exists. Run sql/sql_updates.sql first.');
        }
        ok(['message' => 'Notification added.', 'id' => $pdo->lastInsertId()]);
    }

    case 'update_notification': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) fail('Missing notification ID.');

        // Check if it's a field-level update or a full edit
        $field = $_POST['field'] ?? '';
        if ($field === 'is_active') {
            $value = (int)($_POST['value'] ?? 0);
            $pdo->prepare("UPDATE popup_notifications SET is_active = ? WHERE id = ?")->execute([$value, $id]);
            ok(['message' => 'Notification updated.']);
        }

        // Full edit
        $message = trim($_POST['message'] ?? '');
        $type = $_POST['type'] ?? 'custom';
        $frequency = max(5, (int)($_POST['frequency'] ?? 10));
        if (!$message) fail('Message is required.');

        $pdo->prepare("UPDATE popup_notifications SET message = ?, type = ?, frequency = ? WHERE id = ?")->execute([$message, $type, $frequency, $id]);
        ok(['message' => 'Notification updated.']);
    }

    case 'delete_notification': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) fail('Missing notification ID.');

        $pdo->prepare("DELETE FROM popup_notifications WHERE id = ?")->execute([$id]);
        ok(['message' => 'Notification deleted.']);
    }

// ════════════════════════════════════════════════════════════
// BULK AUCTION MANAGEMENT (admin)
// ════════════════════════════════════════════════════════════

    case 'bulk_update_auction_times': {
        adminOnly();
        $idsJson = $_POST['auction_ids'] ?? '[]';
        $ids = json_decode($idsJson, true);
        if (empty($ids) || !is_array($ids)) fail('No auctions selected.');

        $startTime = trim($_POST['start_time'] ?? '');
        $endTime   = trim($_POST['end_time'] ?? '');
        if (!$startTime && !$endTime) fail('Set at least one time field.');

        $sets = [];
        $params = [];
        if ($startTime) { $sets[] = 'start_time = ?'; $params[] = $startTime; }
        if ($endTime)   { $sets[] = 'end_time = ?';   $params[] = $endTime; }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($params, array_map('intval', $ids));

        $pdo->prepare("UPDATE auctions SET " . implode(', ', $sets) . " WHERE id IN ($placeholders)")->execute($params);
        $count = count($ids);
        ok(['message' => "Times updated for $count auction(s)."]);
    }

    case 'bulk_update_auction_status': {
        $admin = adminOnly();
        $idsJson = $_POST['auction_ids'] ?? '[]';
        $ids = json_decode($idsJson, true);
        if (empty($ids) || !is_array($ids)) fail('No auctions selected.');

        $status = trim($_POST['status'] ?? '');
        if (!in_array($status, ['upcoming', 'active', 'closed', 'sold'])) fail('Invalid status.');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($status === 'sold') {
            $params = array_map('intval', $ids);
            $pdo->prepare("UPDATE auctions SET sold_date = IF(is_sold = 1 AND sold_date IS NOT NULL, sold_date, NOW()), status = 'closed', is_sold = 1 WHERE id IN ($placeholders)")->execute($params);
        } elseif ($status === 'closed') {
            $params = array_merge(['closed'], array_map('intval', $ids));
            $pdo->prepare("UPDATE auctions SET status = ? WHERE id IN ($placeholders)")->execute($params);
            foreach ($ids as $id) {
                try { closeAuction((int)$id); } catch (Exception $e) {}
            }
        } else {
            $params = array_merge([$status], array_map('intval', $ids));
            $pdo->prepare("UPDATE auctions SET status = ?, is_sold = 0, sold_date = NULL WHERE id IN ($placeholders)")->execute($params);
        }

        $count = count($ids);
        foreach ($ids as $id) logAdminAction($admin, 'status', (int)$id, ['status' => $status, 'bulk' => true]);
        ok(['message' => "Status changed to '$status' for $count auction(s)."]);
    }

    // ── SET ALL AUCTION STATUS (make all live / upcoming) ─────
    case 'set_all_auction_status': {
        adminOnly();

        $newStatus    = trim($_POST['status']        ?? 'active');
        $filterScope  = trim($_POST['status_filter'] ?? 'upcoming'); // upcoming|all
        $startTime    = trim($_POST['start_time']    ?? '');
        $endTime      = trim($_POST['end_time']      ?? '');

        if (!in_array($newStatus,   ['upcoming','active','closed'])) fail('Invalid status.');
        if (!in_array($filterScope, ['upcoming','all']))             fail('Invalid filter.');

        // Build WHERE: only non-sold vehicles; optionally filter by current status
        $where  = 'is_sold = 0';
        $params = [];
        if ($filterScope === 'upcoming') {
            $where .= ' AND status = ?';
            $params[] = 'upcoming';
        } else {
            // 'all' — skip closed only
            $where .= " AND status != 'closed'";
        }

        // Build SET clause
        $sets   = ['status = ?'];
        $setPs  = [$newStatus];
        if ($startTime) { $sets[] = 'start_time = ?'; $setPs[] = $startTime; }
        if ($endTime)   { $sets[] = 'end_time = ?';   $setPs[] = $endTime; }

        $sql    = 'UPDATE auctions SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        $allPs  = array_merge($setPs, $params);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($allPs);
        $count = $stmt->rowCount();

        $label = $newStatus === 'active' ? 'Live' : ucfirst($newStatus);
        ok(['message' => "$count vehicle(s) set to $label successfully.", 'count' => $count]);
    }

// ════════════════════════════════════════════════════════════
// ASSIGN WINNER (admin)
// ════════════════════════════════════════════════════════════

    case 'assign_winner': {
        $admin = adminOnly();
        $auctionId  = (int)($_POST['auction_id'] ?? 0);
        $userId     = (int)($_POST['user_id'] ?? 0);
        $finalPrice = (float)($_POST['final_price'] ?? 0);
        if (!$auctionId) fail('Missing auction ID.');
        if (!$userId) fail('Please select a bidder.');
        if ($finalPrice <= 0) fail('Please enter a valid final price.');

        // Verify auction exists
        $auction = $pdo->prepare("SELECT id, item_name FROM auctions WHERE id = ?");
        $auction->execute([$auctionId]);
        $auction = $auction->fetch();
        if (!$auction) fail('Auction not found.');

        // Verify user exists
        $winner = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ?");
        $winner->execute([$userId]);
        $winner = $winner->fetch();
        if (!$winner) fail('User not found.');

        // Mark auction as sold
        $pdo->prepare("UPDATE auctions SET sold_date = IF(is_sold = 1 AND sold_date IS NOT NULL, sold_date, NOW()), status = 'closed', is_sold = 1, sold_to_user_id = ?, sold_to = ?, final_price = ? WHERE id = ?")
            ->execute([$userId, $winner['full_name'], $finalPrice, $auctionId]);
        logAdminAction($admin, 'mark_sold', $auctionId, ['final_price' => $finalPrice, 'buyer' => $winner['full_name'], 'buyer_user_id' => $userId, 'via' => 'assign_winner']);
        notifyLotWon($userId, $auctionId, $auction['item_name'], $finalPrice);
        notifyWatchersSold($auctionId, $userId);

        ok(['message' => "Winner assigned: {$winner['full_name']} for {$auction['item_name']} at R" . number_format($finalPrice, 2) . "."]);
    }

// ════════════════════════════════════════════════════════════
// REFUND REQUESTS
// ════════════════════════════════════════════════════════════

    case 'submit_refund_request': {
        authRequired();
        $userId        = (int)$currentUser['sub'];
        $refType       = trim($_POST['reference_type'] ?? '');
        $refNumber     = trim($_POST['reference_number'] ?? '');
        $amountPaid    = (float)($_POST['amount_paid'] ?? 0);
        $refundAmount  = (float)($_POST['refund_amount'] ?? 0);
        $reason        = trim($_POST['reason'] ?? '');
        $bankName      = trim($_POST['bank_name'] ?? '');
        $accountHolder = trim($_POST['account_holder'] ?? '');
        $accountNumber = trim($_POST['account_number'] ?? '');
        $branchCode    = trim($_POST['branch_code'] ?? '');
        $accountType   = trim($_POST['account_type'] ?? 'cheque');

        $validTypes = ['registration', 'preauction_invoice', 'other'];
        if (!in_array($refType, $validTypes)) fail('Please select what the refund is for.');
        if ($amountPaid <= 0) fail('Please enter the amount you paid.');
        if ($refundAmount <= 0) fail('Please enter the refund amount.');
        if ($refundAmount > $amountPaid) fail('Refund amount cannot exceed the amount paid.');
        if (!$reason) fail('Please provide a reason for the refund.');
        if (!$bankName) fail('Please select your bank.');
        if (!$accountHolder) fail('Please enter the account holder name.');
        if (!$accountNumber) fail('Please enter your account number.');
        if (!$branchCode) fail('Please enter your branch code.');
        $validAccountTypes = ['cheque', 'savings', 'transmission', 'credit'];
        if (!in_array($accountType, $validAccountTypes)) $accountType = 'cheque';

        $popPath = null;
        if (!empty($_FILES['pop_file']['name'])) {
            $popPath = handleUpload($_FILES['pop_file'], UPLOAD_POP, ['jpg','jpeg','png','pdf']);
            if (!$popPath) fail('POP file upload failed. Please use JPG, PNG, or PDF under 10 MB.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO refund_requests
              (user_id, reference_type, reference_number, amount_paid, refund_amount, reason,
               bank_name, account_holder, account_number, branch_code, account_type, pop_file_path)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $userId, $refType, $refNumber ?: null, $amountPaid, $refundAmount, $reason,
            $bankName, $accountHolder, $accountNumber, $branchCode, $accountType, $popPath
        ]);
        $newId = $pdo->lastInsertId();

        // Fetch user info for emails
        $uStmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
        $uStmt->execute([$userId]);
        $uData = $uStmt->fetch();

        require_once 'email_helper.php';
        sendRefundRequestConfirmation([
            'name'          => $uData['full_name'],
            'email'         => $uData['email'],
            'refund_id'     => $newId,
            'reference_type'=> $refType,
            'reference_number'=> $refNumber,
            'amount_paid'   => $amountPaid,
            'refund_amount' => $refundAmount,
            'bank_name'     => $bankName,
            'account_holder'=> $accountHolder,
        ]);
        sendRefundRequestAdminAlert([
            'refund_id'     => $newId,
            'client_name'   => $uData['full_name'],
            'client_email'  => $uData['email'],
            'reference_type'=> $refType,
            'reference_number'=> $refNumber,
            'amount_paid'   => $amountPaid,
            'refund_amount' => $refundAmount,
            'bank_name'     => $bankName,
        ]);

        ok('Refund request submitted successfully. We will review it and contact you within 3-5 business days.', ['id' => $newId]);
        break;
    }

    case 'update_refund_status': {
        adminOnly();
        $id = (int)($_POST['refund_id'] ?? 0);
        if (!$id) fail('Missing refund ID.');
        $stmt = $pdo->prepare("UPDATE refund_requests SET status='under_review' WHERE id=?");
        $stmt->execute([$id]);
        ok('Marked as under review.');
        break;
    }

    case 'process_refund': {
        adminOnly();
        $id    = (int)($_POST['refund_id'] ?? 0);
        $notes = trim($_POST['admin_notes'] ?? '');
        if (!$id) fail('Missing refund ID.');

        $stmt = $pdo->prepare("SELECT r.*, u.full_name, u.email FROM refund_requests r JOIN users u ON u.id=r.user_id WHERE r.id=?");
        $stmt->execute([$id]);
        $rf = $stmt->fetch();
        if (!$rf) fail('Refund request not found.');

        $upd = $pdo->prepare("UPDATE refund_requests SET status='processed', admin_notes=?, processed_by=?, processed_at=NOW() WHERE id=?");
        $upd->execute([$notes ?: null, (int)$currentUser['sub'], $id]);

        require_once 'email_helper.php';
        sendRefundProcessedEmail([
            'name'          => $rf['full_name'],
            'email'         => $rf['email'],
            'refund_id'     => $rf['id'],
            'refund_amount' => $rf['refund_amount'],
            'bank_name'     => $rf['bank_name'],
            'account_holder'=> $rf['account_holder'],
            'admin_notes'   => $notes,
        ]);

        ok('Refund marked as processed and client notified.');
        break;
    }

    case 'reject_refund': {
        adminOnly();
        $id     = (int)($_POST['refund_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (!$id) fail('Missing refund ID.');
        if (!$reason) fail('Please provide a rejection reason.');

        $stmt = $pdo->prepare("SELECT r.*, u.full_name, u.email FROM refund_requests r JOIN users u ON u.id=r.user_id WHERE r.id=?");
        $stmt->execute([$id]);
        $rf = $stmt->fetch();
        if (!$rf) fail('Refund request not found.');

        $upd = $pdo->prepare("UPDATE refund_requests SET status='rejected', rejection_reason=?, processed_by=?, processed_at=NOW() WHERE id=?");
        $upd->execute([$reason, (int)$currentUser['sub'], $id]);

        require_once 'email_helper.php';
        sendRefundRejectedEmail([
            'name'             => $rf['full_name'],
            'email'            => $rf['email'],
            'refund_id'        => $rf['id'],
            'refund_amount'    => $rf['refund_amount'],
            'rejection_reason' => $reason,
        ]);

        ok('Refund request rejected and client notified.');
        break;
    }

// ════════════════════════════════════════════════════════════
// TICKER ANNOUNCEMENTS
// ════════════════════════════════════════════════════════════

    case 'save_announcement': {
        adminOnly();
        $id      = (int)($_POST['id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        $order   = (int)($_POST['sort_order'] ?? 0);
        if (!$message) fail('Message is required.');
        // Strip characters MySQL utf8 can't store (4-byte emojis)
        $message = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $message);
        if ($id) {
            $pdo->prepare("UPDATE news_announcements SET message=?,sort_order=? WHERE id=?")->execute([$message,$order,$id]);
            ok('Announcement updated.');
        } else {
            $pdo->prepare("INSERT INTO news_announcements (message,sort_order,is_active) VALUES (?,?,1)")->execute([$message,$order]);
            ok('Announcement added.', ['id'=>$pdo->lastInsertId()]);
        }
        break;
    }

    case 'delete_announcement': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM news_announcements WHERE id=?")->execute([$id]);
        ok('Announcement deleted.');
        break;
    }

    case 'toggle_announcement': {
        adminOnly();
        $id  = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['is_active'] ?? 0);
        $pdo->prepare("UPDATE news_announcements SET is_active=? WHERE id=?")->execute([$val,$id]);
        ok('Updated.');
        break;
    }

    case 'reset_ticker_messages': {
        adminOnly();
        $pdo->exec("DELETE FROM news_announcements");
        $defaults = [
            [1, 'Register today to participate in our next live auction!'],
            [2, 'Trusted by banks, respected by buyers - Auction Hub Group'],
            [3, 'Bank repossessions and estate vehicles at unbeatable prices'],
            [4, 'All vehicles are DEKRA Certified - total transparency'],
            [5, 'Nationwide delivery available - contact us for details'],
            [6, 'In-House Finance available - no credit checks, blacklisted welcome'],
            [7, 'SAIA, NAA, JCCI, IEASA member - Level 2 BBBEE certified'],
            [8, 'Call us: ' . COMPANY_PHONE . ' - ' . COMPANY_EMAIL],
        ];
        $ins = $pdo->prepare("INSERT INTO news_announcements (sort_order,message,is_active) VALUES (?,?,1)");
        foreach ($defaults as [$order,$msg]) $ins->execute([$order,$msg]);
        ok('Ticker messages reset to defaults.', ['count'=>count($defaults)]);
        break;
    }

// ════════════════════════════════════════════════════════════
// NEWS POSTS
// ════════════════════════════════════════════════════════════

    case 'save_news_post': {
        adminOnly();
        $id         = (int)($_POST['id'] ?? 0);
        $title      = trim($_POST['title'] ?? '');
        $content    = $_POST['content'] ?? '';
        $excerpt    = trim($_POST['excerpt'] ?? '');
        $catId      = (int)($_POST['category_id'] ?? 0) ?: null;
        $postType   = in_array($_POST['post_type']??'',['article','review','news','rss']) ? $_POST['post_type'] : 'article';
        $sourceUrl  = trim($_POST['source_url'] ?? '') ?: null;
        $sourceName = trim($_POST['source_name'] ?? '') ?: null;
        $metaTitle  = trim($_POST['meta_title'] ?? '') ?: null;
        $metaDesc   = trim($_POST['meta_description'] ?? '') ?: null;
        $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
        $allowComm  = isset($_POST['allow_comments']) ? 1 : 0;
        $pubAt      = trim($_POST['published_at'] ?? '') ?: date('Y-m-d H:i:s');
        $imgUrl     = trim($_POST['featured_image_url'] ?? '') ?: null;
        $videoUrl   = trim($_POST['video_url'] ?? '') ?: null;

        if (!$title) fail('Title is required.');

        // Handle featured image upload
        $file = $_FILES['featured_image'] ?? null;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $type = mime_content_type($file['tmp_name']);
            if (!in_array($type, ['image/jpeg','image/png','image/webp','image/gif'])) fail('Invalid image type.');
            if ($file['size'] > 8*1024*1024) fail('Image too large (max 8 MB).');
            $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$type]??'jpg';
            require_once __DIR__ . '/imagekit_helper.php';
            try {
                $up     = uploadToImageKit($file['tmp_name'], 'pva/news', 'news-' . $title);
                $imgUrl = $up['url'];
            } catch (Exception $e) {
                error_log('[AHG] ImageKit news image upload failed: ' . $e->getMessage());
                $dest = UPLOAD_ROOT . 'news/' . uniqid('news_') . '.' . $ext;
                @mkdir(dirname($dest), 0755, true);
                if (move_uploaded_file($file['tmp_name'], $dest)) $imgUrl = APP_URL . '/uploads/news/' . basename($dest);
            }
        }

        // Generate slug from title
        function makeSlug(string $t): string {
            $t = mb_strtolower($t);
            $t = preg_replace('/[^a-z0-9\s-]/', '', $t);
            $t = preg_replace('/[\s-]+/', '-', trim($t));
            return substr($t, 0, 250);
        }

        if ($id) {
            $pdo->prepare("UPDATE news_posts SET title=?,excerpt=?,content=?,featured_image_url=?,video_url=?,category_id=?,post_type=?,
                source_url=?,source_name=?,meta_title=?,meta_description=?,is_featured=?,allow_comments=?,published_at=?,updated_at=NOW()
                WHERE id=?")->execute([$title,$excerpt,$content,$imgUrl,$videoUrl,$catId,$postType,$sourceUrl,$sourceName,$metaTitle,$metaDesc,$isFeatured,$allowComm,$pubAt,$id]);
            ok('Post updated.', ['id' => $id]);
        } else {
            $slug = makeSlug($title);
            // Ensure unique slug
            $base = $slug; $n = 1;
            $slugChk = $pdo->prepare("SELECT COUNT(*) FROM news_posts WHERE slug=?");
            $slugChk->execute([$slug]);
            while ((int)$slugChk->fetchColumn() > 0) {
                $slug = $base . '-' . $n++;
                $slugChk->execute([$slug]);
            }
            $stmt = $pdo->prepare("INSERT INTO news_posts (title,slug,excerpt,content,featured_image_url,video_url,category_id,post_type,source_url,source_name,meta_title,meta_description,is_featured,allow_comments,published_at,author_id,is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
            $stmt->execute([$title,$slug,$excerpt,$content,$imgUrl,$videoUrl,$catId,$postType,$sourceUrl,$sourceName,$metaTitle,$metaDesc,$isFeatured,$allowComm,$pubAt,$_SESSION['user_id']??null]);
            ok('Post created.', ['id' => $pdo->lastInsertId(), 'slug' => $slug]);
        }
        break;
    }

    case 'save_news_category': {
        adminOnly();
        $name  = trim($_POST['cat_name'] ?? '');
        $desc  = trim($_POST['cat_description'] ?? '') ?: null;
        $color = preg_match('/^#[0-9a-fA-F]{3,6}$/', $_POST['cat_color'] ?? '') ? $_POST['cat_color'] : '#005DDB';
        $icon  = preg_match('/^fa-[a-z0-9\-]+$/', $_POST['cat_icon'] ?? '') ? $_POST['cat_icon'] : 'fa-newspaper';
        if (!$name) fail('Category name is required.');
        $slug = mb_strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = substr(preg_replace('/[\s-]+/', '-', trim($slug)), 0, 100);
        $slugBase = $slug; $n = 1;
        $slugChk = $pdo->prepare("SELECT COUNT(*) FROM news_categories WHERE slug=?");
        $slugChk->execute([$slug]);
        while ((int)$slugChk->fetchColumn() > 0) {
            $slug = $slugBase . '-' . $n++;
            $slugChk->execute([$slug]);
        }
        $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM news_categories")->fetchColumn();
        $pdo->prepare("INSERT INTO news_categories (name,slug,description,color,icon,sort_order,is_active) VALUES (?,?,?,?,?,?,1)")
            ->execute([$name,$slug,$desc,$color,$icon,$maxOrder+1]);
        ok('Category added.', ['id'=>$pdo->lastInsertId(),'slug'=>$slug]);
        break;
    }

    case 'toggle_news_post': {
        adminOnly();
        $id  = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['is_active'] ?? 0);
        $pdo->prepare("UPDATE news_posts SET is_active=? WHERE id=?")->execute([$val,$id]);
        ok('Post updated.');
        break;
    }

    case 'set_featured_post': {
        adminOnly();
        $id  = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['is_featured'] ?? 0);
        if ($val) $pdo->exec("UPDATE news_posts SET is_featured=0"); // Only one featured at a time
        $pdo->prepare("UPDATE news_posts SET is_featured=? WHERE id=?")->execute([$val,$id]);
        ok('Featured updated.');
        break;
    }

    case 'delete_news_post': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) fail('Invalid post.');
        $pdo->prepare("DELETE FROM news_posts WHERE id=?")->execute([$id]);
        ok('Post deleted.');
        break;
    }

    case 'delete_news_comment': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        $postId = (int)$pdo->prepare("SELECT post_id FROM news_comments WHERE id=?")->execute([$id]) ? $pdo->query("SELECT post_id FROM news_comments WHERE id=$id")->fetchColumn() : 0;
        $pdo->prepare("DELETE FROM news_comments WHERE id=?")->execute([$id]);
        if ($postId) $pdo->prepare("UPDATE news_posts SET comment_count=GREATEST(0,comment_count-1) WHERE id=?")->execute([$postId]);
        ok('Comment deleted.');
        break;
    }

// ════════════════════════════════════════════════════════════
// RSS FEED MANAGEMENT
// ════════════════════════════════════════════════════════════

    case 'save_rss_feed': {
        adminOnly();
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $url     = trim($_POST['feed_url'] ?? '');
        $catId   = (int)($_POST['category_id'] ?? 0) ?: null;
        $interval= max(1,(int)($_POST['fetch_interval_hours'] ?? 6));
        if (!$name || !$url) fail('Name and URL are required.');
        if (!filter_var($url, FILTER_VALIDATE_URL)) fail('Invalid feed URL.');
        if ($id) {
            $pdo->prepare("UPDATE rss_feeds SET name=?,feed_url=?,category_id=?,fetch_interval_hours=?,is_active=1 WHERE id=?")->execute([$name,$url,$catId,$interval,$id]);
            ok('Feed updated.', ['id'=>$id]);
        } else {
            $pdo->prepare("INSERT INTO rss_feeds (name,feed_url,category_id,fetch_interval_hours,is_active) VALUES (?,?,?,?,1)")->execute([$name,$url,$catId,$interval]);
            ok('Feed added.', ['id'=>$pdo->lastInsertId()]);
        }
        break;
    }

    case 'delete_rss_feed': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM rss_feeds WHERE id=?")->execute([$id]);
        ok('Feed removed.');
        break;
    }

    case 'import_rss_feed': {
        adminOnly();
        $feedId = (int)($_POST['feed_id'] ?? 0);
        if (!$feedId) fail('No feed specified.');

        $feedStmt = $pdo->prepare("SELECT * FROM rss_feeds WHERE id=?");
        $feedStmt->execute([$feedId]);
        $feed = $feedStmt->fetch();
        if (!$feed) fail('Feed not found.');

        // Record the failure on the feed row (shown in admin UI), then bail
        $feedFail = function (string $msg) use ($pdo, $feedId): void {
            $pdo->prepare("UPDATE rss_feeds SET last_fetched_at=NOW(), last_error=? WHERE id=?")
                ->execute([$msg, $feedId]);
            fail($msg);
        };

        // Fetch RSS — cURL with a browser User-Agent: many publishers
        // (Cloudflare etc.) return 403 to plain PHP/bot user agents
        $ch = curl_init($feed['feed_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ]);
        $xml      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($xml === false || $xml === '' || $httpCode >= 400) {
            $feedFail("Could not fetch feed (HTTP {$httpCode}" . ($curlErr ? " — {$curlErr}" : '') .
                      "). The site may block automated readers, or the URL is wrong.");
        }

        libxml_use_internal_errors(true);
        $rss = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$rss) {
            $looksHtml = preg_match('/^\s*<!doctype html|^\s*<html/i', $xml);
            $feedFail('Could not parse RSS/Atom feed. ' . ($looksHtml
                ? 'This URL returns a normal web page, not an RSS feed — use the site\'s actual feed URL (often ends in /feed/ or /rss).'
                : 'The feed may be malformed or in an unsupported format.'));
        }

        $imported = 0; $skipped = 0;
        $items    = $rss->channel->item ?? $rss->entry ?? [];
        $catId    = $feed['category_id'] ?: null;

        foreach ($items as $item) {
            $itemTitle = trim((string)($item->title ?? ''));
            $itemLink  = trim((string)($item->link ?? ''));
            if ($itemLink === '' && isset($item->link)) {
                // Atom feeds: <link rel="alternate" href="..."/> — value is an attribute
                foreach ($item->link as $lnk) {
                    $href = (string)($lnk->attributes()['href'] ?? '');
                    $rel  = (string)($lnk->attributes()['rel'] ?? 'alternate');
                    if ($href !== '' && $rel === 'alternate') { $itemLink = trim($href); break; }
                }
            }
            if ($itemLink === '') $itemLink = trim((string)($item->id ?? ''));
            $itemDate  = trim((string)($item->pubDate ?? ($item->published ?? ($item->updated ?? ''))));
            $pubAt     = $itemDate ? date('Y-m-d H:i:s', strtotime($itemDate)) : date('Y-m-d H:i:s');

            // Content:encoded (full namespace URI is most reliable)
            $contentNs   = $item->children('http://purl.org/rss/1.0/modules/content/');
            $rawHtml     = '';
            if ($contentNs && isset($contentNs->encoded) && (string)$contentNs->encoded !== '') {
                $rawHtml = (string)$contentNs->encoded;
            } elseif ($item->children('content', true)->encoded) {
                $rawHtml = (string)$item->children('content', true)->encoded;
            } elseif (isset($item->content) && (string)$item->content !== '') {
                $rawHtml = (string)$item->content;
            }
            $descRaw     = (string)($item->description ?? ($item->summary ?? ''));
            $itemDesc    = trim(strip_tags($rawHtml ?: $descRaw));
            $itemContent = $rawHtml ?: $descRaw;

            $imgUrl   = null;
            $videoUrl = null;

            // ── 1. Media RSS namespace (full URI — works regardless of prefix) ──
            $mediaNs = $item->children('http://search.yahoo.com/mrss/');
            if ($mediaNs) {
                // Direct media:thumbnail on the item
                if (!$imgUrl && isset($mediaNs->thumbnail)) {
                    $u = (string)($mediaNs->thumbnail->attributes()['url'] ?? '');
                    if ($u) $imgUrl = $u;
                }
                // media:content elements (image or video)
                if (isset($mediaNs->content)) {
                    foreach ($mediaNs->content as $mc) {
                        $attrs  = $mc->attributes();
                        $mcUrl  = (string)($attrs['url'] ?? '');
                        $medium = strtolower((string)($attrs['medium'] ?? ''));
                        $type   = strtolower((string)($attrs['type'] ?? ''));
                        if (!$mcUrl) continue;
                        if (($medium === 'image' || str_starts_with($type, 'image/')) && !$imgUrl) {
                            $imgUrl = $mcUrl;
                        } elseif ($medium === 'video' || str_starts_with($type, 'video/')) {
                            if (!$videoUrl) $videoUrl = $mcUrl;
                            // Nested thumbnail inside video content
                            if (!$imgUrl) {
                                $nt = $mc->children('http://search.yahoo.com/mrss/');
                                if ($nt && isset($nt->thumbnail)) {
                                    $tu = (string)($nt->thumbnail->attributes()['url'] ?? '');
                                    if ($tu) $imgUrl = $tu;
                                }
                            }
                        } elseif (!$medium && !str_starts_with($type, 'video/') && $mcUrl && !$imgUrl) {
                            // Unknown medium — treat as image if URL looks like an image
                            if (preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $mcUrl)) $imgUrl = $mcUrl;
                        }
                    }
                }
                // media:group (YouTube Atom feeds use this)
                if (!$imgUrl && isset($mediaNs->group)) {
                    $grp = $mediaNs->group;
                    if (isset($grp->thumbnail)) {
                        $u = (string)($grp->thumbnail->attributes()['url'] ?? '');
                        if ($u) $imgUrl = $u;
                    }
                    $grpNs = $grp->children('http://search.yahoo.com/mrss/');
                    if ($grpNs && isset($grpNs->thumbnail)) {
                        $u = (string)($grpNs->thumbnail->attributes()['url'] ?? '');
                        if ($u && !$imgUrl) $imgUrl = $u;
                    }
                }
            }
            // ── 1b. Prefix-based fallback (non-standard namespace URI) ──
            if (!$imgUrl) {
                $mediaPfx = $item->children('media', true);
                if ($mediaPfx) {
                    if (isset($mediaPfx->thumbnail)) {
                        $u = (string)($mediaPfx->thumbnail->attributes()['url'] ?? '');
                        if ($u) $imgUrl = $u;
                    }
                    if (!$imgUrl && isset($mediaPfx->content)) {
                        $u = (string)($mediaPfx->content->attributes()['url'] ?? '');
                        if ($u && preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $u)) $imgUrl = $u;
                    }
                }
            }

            // ── 2. Enclosure tag ──
            if (!$imgUrl || !$videoUrl) {
                $enc = $item->enclosure;
                if ($enc) {
                    $encUrl  = (string)($enc->attributes()['url'] ?? '');
                    $encType = strtolower((string)($enc->attributes()['type'] ?? ''));
                    if ($encUrl) {
                        if (!$imgUrl && str_starts_with($encType, 'image/')) $imgUrl = $encUrl;
                        elseif (!$videoUrl && str_starts_with($encType, 'video/')) $videoUrl = $encUrl;
                    }
                }
            }

            // ── 3. YouTube link → embed URL + HD thumbnail ──
            if (!$videoUrl) {
                foreach ([$itemLink, (string)($item->link ?? '')] as $lStr) {
                    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/i', $lStr, $ym)) {
                        $vid = $ym[1];
                        $videoUrl = 'https://www.youtube.com/embed/' . $vid;
                        if (!$imgUrl) $imgUrl = 'https://img.youtube.com/vi/' . $vid . '/hqdefault.jpg';
                        break;
                    }
                }
            }

            // ── 4. First usable <img> in HTML content (skip tracking pixels) ──
            if (!$imgUrl) {
                foreach ([$itemContent, $descRaw] as $html) {
                    if (!$html) continue;
                    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*/i', $html, $ms)) {
                        foreach ($ms[1] as $candidate) {
                            if (!preg_match('/[\/\-_]1[x×]1[\/\.\-_]|track|pixel|beacon|spacer/i', $candidate)
                                && preg_match('/\.(jpe?g|png|webp|gif)(\?|$)|\/image\/|\/img\//i', $candidate)) {
                                $imgUrl = $candidate;
                                break 2;
                            }
                        }
                    }
                }
            }

            // Require title, link, AND an image — articles without a
            // featured image are stripped (thin content, bad for SEO).
            if (!$itemTitle || !$itemLink || !$imgUrl) { $skipped++; continue; }

            // Check duplicate
            $dupChk = $pdo->prepare("SELECT COUNT(*) FROM news_posts WHERE source_url=?");
            $dupChk->execute([$itemLink]);
            if ((int)$dupChk->fetchColumn() > 0) { $skipped++; continue; }

            // Generate unique slug
            $slugBase = mb_strtolower($itemTitle);
            $slugBase = preg_replace('/[^a-z0-9\s-]/', '', $slugBase);
            $slugBase = substr(preg_replace('/[\s-]+/', '-', trim($slugBase)), 0, 220);
            $slug = $slugBase; $n = 1;
            $slugChk2 = $pdo->prepare("SELECT COUNT(*) FROM news_posts WHERE slug=?");
            $slugChk2->execute([$slug]);
            while ((int)$slugChk2->fetchColumn() > 0) {
                $slug = $slugBase . '-' . $n++;
                $slugChk2->execute([$slug]);
            }

            $excerpt = $itemDesc ? mb_substr($itemDesc, 0, 400) : null;
            $body    = $itemContent ?: $itemDesc;

            $pdo->prepare("INSERT INTO news_posts
                (title,slug,excerpt,content,featured_image_url,video_url,category_id,post_type,source_url,source_name,is_active,allow_comments,published_at,is_featured)
                VALUES (?,?,?,?,?,?,?,'rss',?,?,1,0,?,0)")
                ->execute([$itemTitle,$slug,$excerpt,$body,$imgUrl,$videoUrl,$catId,$itemLink,$feed['name'],$pubAt]);
            $imported++;
        }

        $pdo->prepare("UPDATE rss_feeds SET last_fetched_at=NOW(), items_imported=items_imported+?, last_error=NULL WHERE id=?")->execute([$imported,$feedId]);
        ok("Imported $imported new articles. Skipped $skipped duplicates.", ['imported'=>$imported,'skipped'=>$skipped]);
        break;
    }

// ════════════════════════════════════════════════════════════
// NEWSLETTER — VEHICLE PICKER (Smart Form helper)
// Returns live + upcoming auctions for the compose form
// ════════════════════════════════════════════════════════════
    case 'get_newsletter_vehicles': {
        adminOnly();
        $vehicles = $pdo->query("
            SELECT a.id, a.item_name, COALESCE(a.year,'') AS year,
                   COALESCE(m.name,'') AS make_name,
                   a.current_price, a.start_price, a.image_url,
                   a.mileage, a.fuel_type, a.transmission, a.status
            FROM auctions a
            LEFT JOIN makes m ON m.id = a.make_id
            WHERE a.status IN ('active','upcoming') AND a.is_sold = 0
            ORDER BY (a.status = 'active') DESC, a.end_time ASC
            LIMIT 12
        ")->fetchAll();
        ok(['message' => 'Vehicles loaded.', 'vehicles' => $vehicles]);
        break;
    }

    case 'get_newsletter_events': {
        adminOnly();
        $events = $pdo->query("
            SELECT e.id, e.title, e.venue, e.province, e.status,
                   DATE_FORMAT(e.event_date, '%W, %e %M %Y') AS date_label,
                   TIME_FORMAT(e.event_time, '%H:%i') AS time_label,
                   (SELECT COUNT(*) FROM event_vehicles ev WHERE ev.event_id = e.id) AS vehicle_count
            FROM auction_events e
            WHERE e.status IN ('upcoming','live')
            ORDER BY (e.status = 'live') DESC, e.event_date ASC
            LIMIT 10
        ")->fetchAll();
        ok(['message' => 'Events loaded.', 'events' => $events]);
        break;
    }

// ════════════════════════════════════════════════════════════
// NEWSLETTER
// ════════════════════════════════════════════════════════════

    case 'newsletter_subscribe': {
        rateLimit('nl_sub', 5, 300);
        $email = strtolower(trim($_POST['email'] ?? ''));
        $name  = trim($_POST['name'] ?? '') ?: null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address.');

        // Check if already subscribed
        $exists = $pdo->prepare("SELECT id,is_active FROM newsletter_subscribers WHERE email=?");
        $exists->execute([$email]);
        $sub = $exists->fetch();
        if ($sub) {
            if ($sub['is_active']) ok('You\'re already subscribed!');
            // Resubscribe
            $pdo->prepare("UPDATE newsletter_subscribers SET is_active=1,unsubscribed_at=NULL WHERE id=?")->execute([$sub['id']]);
            ok('Welcome back! You\'ve been resubscribed.');
        }

        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO newsletter_subscribers (email,name,is_verified,verification_token,is_active) VALUES (?,?,1,?,1)")->execute([$email,$name,$token]);
        ok('Subscribed successfully! You\'ll receive our next newsletter.', ['subscribed'=>true]);
        break;
    }

    case 'newsletter_unsubscribe': {
        $email = strtolower(trim($_GET['email'] ?? $_POST['email'] ?? ''));
        $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
        if (!$email) fail('Email required.');
        $pdo->prepare("UPDATE newsletter_subscribers SET is_active=0,unsubscribed_at=NOW() WHERE email=?")->execute([$email]);
        ok('Unsubscribed successfully.');
        break;
    }

    case 'save_newsletter_campaign': {
        adminOnly();
        $id          = (int)($_POST['id'] ?? 0);
        $subject     = trim($_POST['subject'] ?? '');
        $previewText = trim($_POST['preview_text'] ?? '') ?: null;
        $content     = $_POST['content_html'] ?? '';
        if (!$subject) fail('Subject is required.');
        if (!$content) fail('Content is required.');
        if ($id) {
            $pdo->prepare("UPDATE newsletter_campaigns SET subject=?,preview_text=?,content_html=? WHERE id=? AND is_sent=0")->execute([$subject,$previewText,$content,$id]);
            ok('Campaign saved.', ['id'=>$id]);
        } else {
            $pdo->prepare("INSERT INTO newsletter_campaigns (subject,preview_text,content_html,created_by) VALUES (?,?,?,?)")->execute([$subject,$previewText,$content,$_SESSION['user_id']??null]);
            ok('Campaign created.', ['id'=>$pdo->lastInsertId()]);
        }
        break;
    }

    case 'send_newsletter_campaign': {
        adminOnly();
        $id = (int)($_POST['campaign_id'] ?? 0);
        if (!$id) fail('Campaign ID required.');

        $campStmt = $pdo->prepare("SELECT * FROM newsletter_campaigns WHERE id=?");
        $campStmt->execute([$id]);
        $camp = $campStmt->fetch();
        if (!$camp) fail('Campaign not found.');
        if ($camp['is_sent']) fail('This campaign has already been sent.');

        // Collect recipients: newsletter subscribers + registered users
        // (deduplicated by email; users table has `status`, not `is_active`)
        $all = [];
        try {
            foreach ($pdo->query("SELECT email,name FROM newsletter_subscribers WHERE is_active=1")->fetchAll() as $r) {
                $all[strtolower($r['email'])] = $r;
            }
        } catch (Exception $e) { error_log('[AHG] newsletter subscribers query: ' . $e->getMessage()); }
        try {
            foreach ($pdo->query("SELECT email, full_name AS name FROM users WHERE status='active'")->fetchAll() as $r) {
                $all[strtolower($r['email'])] = $r;
            }
        } catch (Exception $e) { error_log('[AHG] newsletter users query: ' . $e->getMessage()); }

        if (!$all) fail('No recipients found — there are no active subscribers or users.');

        require_once __DIR__ . '/email_helper.php';

        $sent = 0; $failed = 0; $lastError = '';
        $mail = _buildMailer();
        $mail->SMTPKeepAlive = true; // one SMTP session for the whole batch
        foreach ($all as $r) {
            try {
                $mail->clearAddresses();
                $mail->addAddress($r['email'], $r['name'] ?? '');
                $mail->Subject = $camp['subject'];

                $unsubLink = APP_URL . '/api.php?action=newsletter_unsubscribe&email=' . urlencode($r['email']);
                $body = $camp['content_html'];
                $body .= '<div style="text-align:center;padding:20px 0;border-top:1px solid #eee;margin-top:30px;font-size:12px;color:#999;">
                    <a href="' . htmlspecialchars($unsubLink) . '" style="color:#999;">Unsubscribe</a>
                </div>';
                $mail->Body    = $body;
                $mail->AltBody = strip_tags($body);
                $mail->send();
                $sent++;
                usleep(200000);
            } catch (\Exception $e) {
                $failed++;
                $lastError = $mail->ErrorInfo ?: $e->getMessage();
                error_log('[AHG] newsletter send to ' . $r['email'] . ' failed: ' . $lastError);
            }
        }
        $mail->smtpClose();

        // Keep the campaign re-sendable if nothing went out
        if ($sent === 0) {
            fail("Newsletter NOT sent — all {$failed} attempts failed. Last error: {$lastError}");
        }

        $pdo->prepare("UPDATE newsletter_campaigns SET is_sent=1,sent_at=NOW(),recipients_count=? WHERE id=?")->execute([$sent,$id]);
        ok("Newsletter sent to $sent recipients. $failed failed."
            . ($failed && $lastError ? " Last error: {$lastError}" : ''),
            ['sent'=>$sent,'failed'=>$failed]);
        break;
    }

    case 'delete_newsletter_subscriber': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM newsletter_subscribers WHERE id=?")->execute([$id]);
        ok('Subscriber removed.');
        break;
    }

// ════════════════════════════════════════════════════════════
// BIDDER FEATURES — watchlist, alerts, email settings, saved searches
// (any logged-in user; tables from ahg_user_features.sql)
// ════════════════════════════════════════════════════════════

    case 'toggle_watch': {
        $user = requireAuth();
        if (!ufReady()) fail('The watchlist is not set up yet. Please try again later.');
        $auctionId = (int)($_POST['auction_id'] ?? 0);
        $exists = $pdo->prepare("SELECT 1 FROM auctions WHERE id = ?");
        $exists->execute([$auctionId]);
        if (!$exists->fetchColumn()) fail('Vehicle not found.');

        $del = $pdo->prepare("DELETE FROM watchlist WHERE user_id = ? AND auction_id = ?");
        $del->execute([$user['sub'], $auctionId]);
        $watching = false;
        if ($del->rowCount() === 0) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM watchlist WHERE user_id = ?");
            $cnt->execute([$user['sub']]);
            if ((int)$cnt->fetchColumn() >= 200) fail('Your watchlist is full (200 vehicles). Remove some first.');
            $pdo->prepare("INSERT IGNORE INTO watchlist (user_id, auction_id) VALUES (?, ?)")->execute([$user['sub'], $auctionId]);
            $watching = true;
        }
        ok(['watching' => $watching, 'message' => $watching ? 'Added to your watchlist.' : 'Removed from your watchlist.']);
    }

    // Bell dropdown: latest alerts + unread count
    case 'get_alerts': {
        $user = requireAuth();
        if (!ufReady()) ok(['unread' => 0, 'alerts' => []]);
        $st = $pdo->prepare("
            SELECT id, type, title, message, link, is_read, created_at
            FROM user_notifications WHERE user_id = ? ORDER BY id DESC LIMIT 10
        ");
        $st->execute([$user['sub']]);
        $rows = array_map(fn($r) => $r + ['ago' => timeAgoShort($r['created_at'])], $st->fetchAll());
        ok(['unread' => unreadAlertCount((int)$user['sub']), 'alerts' => $rows]);
    }

    // Mark one alert (id) or all alerts read
    case 'mark_alerts_read': {
        $user = requireAuth();
        if (!ufReady()) ok(['unread' => 0]);
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $user['sub']]);
        } else {
            $pdo->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$user['sub']]);
        }
        ok(['unread' => unreadAlertCount((int)$user['sub'])]);
    }

    case 'save_email_prefs': {
        $user = requireAuth();
        if (!ufReady()) fail('Email settings are not set up yet. Please try again later.');
        emailPrefs((int)$user['sub']); // creates the row on first use
        $vals = [];
        foreach (UF_PREF_COLUMNS as $kind => $col) $vals[] = !empty($_POST[$kind]) ? 1 : 0;
        $vals[] = $user['sub'];
        $pdo->prepare("UPDATE user_email_prefs SET outbid_emails = ?, watch_emails = ?, saved_search_emails = ? WHERE user_id = ?")
            ->execute($vals);
        ok(['message' => 'Email settings saved.']);
    }

    // "Alert me" on auctions.php — filters arrive as the page's own query fields
    case 'save_search': {
        $user = requireAuth();
        if (!ufReady()) fail('Saved searches are not set up yet. Please try again later.');
        $filters = cleanSearchFilters($_POST);
        if (!$filters) fail('Choose at least one filter (make, year, price, fuel, gearbox or a search word) first.');
        $json = json_encode($filters, JSON_UNESCAPED_UNICODE);

        $mine = $pdo->prepare("SELECT id, filters FROM saved_searches WHERE user_id = ?");
        $mine->execute([$user['sub']]);
        $rows = $mine->fetchAll();
        foreach ($rows as $r) {
            if ($r['filters'] === $json) ok(['message' => 'You already have this alert. We\'ll email you when new matches are listed.']);
        }
        if (count($rows) >= 10) fail('You can keep up to 10 alerts. Delete one in your dashboard first.');

        $label = savedSearchLabel($filters);
        // last_sent_at = now: the first digest lists lots added from today on, not the whole stock
        $pdo->prepare("INSERT INTO saved_searches (user_id, label, filters, last_sent_at) VALUES (?, ?, ?, NOW())")
            ->execute([$user['sub'], mb_substr($label, 0, 150), $json]);
        ok(['message' => 'Alert saved: ' . $label . '. We\'ll email you once a day when new matches are listed.']);
    }

    case 'delete_saved_search': {
        $user = requireAuth();
        if (!ufReady()) fail('Saved searches are not set up yet.');
        $pdo->prepare("DELETE FROM saved_searches WHERE id = ? AND user_id = ?")
            ->execute([(int)($_POST['id'] ?? 0), $user['sub']]);
        ok(['message' => 'Alert deleted.']);
    }

// ════════════════════════════════════════════════════════════
// FLOOR / PHONE BIDDERS (admin) — accounts staff create for people who bid
// at floor or live auctions. Verified straight away, no login, no emails;
// bids are placed for them from Admin → Manual Bid.
// ════════════════════════════════════════════════════════════

    case 'floor_bidder_save': {
        $admin = adminOnly();
        ahgSchemaReady();   // users.is_floor_bidder
        $id     = (int)($_POST['id'] ?? 0);
        $name   = mb_substr(trim(strip_tags($_POST['full_name'] ?? '')), 0, 100);
        $phone  = preg_replace('/[^\d+ ]/', '', trim($_POST['phone'] ?? ''));
        $email  = strtolower(trim($_POST['email'] ?? ''));
        $idNo   = strtoupper(preg_replace('/\s+/', '', trim($_POST['id_number'] ?? '')));
        $number = strtoupper(trim($_POST['bidder_number'] ?? ''));

        if (mb_strlen($name) < 2)                      fail('Enter the bidder\'s full name.');
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 9 || strlen($digits) > 15) fail('Enter a valid cellphone number (e.g. 082 123 4567).');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('That email address isn\'t valid (or leave it blank).');
        if ($idNo !== '' && !preg_match('/^[A-Z0-9]{6,20}$/', $idNo)) fail('ID / passport number: letters and digits only, 6–20 characters.');
        if ($number !== '' && !preg_match('/^[A-Z0-9-]{3,20}$/', $number)) fail('Bidder number: letters, digits and dashes only.');

        $existing = null;
        if ($id) {
            $st = $pdo->prepare("SELECT id, email, is_floor_bidder FROM users WHERE id = ?");
            $st->execute([$id]);
            $existing = $st->fetch();
            if (!$existing) fail('Bidder not found.');
            if (!(int)$existing['is_floor_bidder']) fail('Only floor bidders are edited here. Online customers manage their own details.');
        }

        // Email: keep a placeholder when none is given (users.email must be unique)
        if ($email === '') {
            $email = ($existing && str_ends_with($existing['email'], '.invalid'))
                ? $existing['email'] : 'floor-' . bin2hex(random_bytes(5)) . '@' . FLOOR_EMAIL_DOMAIN;
        }
        $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
        $dup->execute([$email, $id]);
        if ($dup->fetchColumn()) fail('Another account already uses that email address.');

        // Bidder number: next free AHG-YYYY-NNN unless one was typed
        if ($number === '' && !$id) {
            $prefix = BIDDER_PREFIX;
            $st = $pdo->prepare("SELECT bidder_number FROM users WHERE bidder_number LIKE ?");
            $st->execute([$prefix . '%']);
            $max = 0;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $bn) $max = max($max, (int)substr($bn, strlen($prefix)));
            $number = $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
        }
        if ($number !== '') {
            $dup = $pdo->prepare("SELECT id FROM users WHERE bidder_number = ? AND id <> ?");
            $dup->execute([$number, $id]);
            if ($dup->fetchColumn()) fail("Bidder number $number is already in use.");
        }

        if ($id) {
            $pdo->prepare("UPDATE users SET full_name = ?, phone = ?, email = ?, id_number = ?"
                        . ($number !== '' ? ', bidder_number = ?' : '') . " WHERE id = ?")
                ->execute(array_merge([$name, $phone, $email, $idNo ?: null], $number !== '' ? [$number] : [], [$id]));
            // Keep names on their existing bids in step
            $pdo->prepare("UPDATE bids SET bidder_name = ?" . ($number !== '' ? ', bidder_number = ?' : '') . " WHERE user_id = ?")
                ->execute(array_merge([$name], $number !== '' ? [$number] : [], [$id]));
            logAdminAction($admin, 'floor_bidder', null, ['edited' => $id, 'name' => $name]);
            ok(['message' => "Saved: $name.", 'id' => $id]);
        }

        $pdo->prepare("
            INSERT INTO users (full_name, email, phone, id_number, password, role, bidder_status, bidder_number, status, is_floor_bidder)
            VALUES (?, ?, ?, ?, ?, 'user', 'verified_bidder', ?, 'active', 1)
        ")->execute([$name, $email, $phone, $idNo ?: null, password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT), $number]);
        $newId = (int)$pdo->lastInsertId();
        logAdminAction($admin, 'floor_bidder', null, ['created' => $newId, 'name' => $name, 'bidder_number' => $number]);
        ok(['message' => "Floor bidder added: $name ($number).", 'id' => $newId, 'bidder_number' => $number]);
    }

// ════════════════════════════════════════════════════════════
// STOCK IMPORT (admin) — Facebook exports + branch uploads → drafts → lots
// See stock_import_helper.php
// ════════════════════════════════════════════════════════════

    // ZIPs placed in the server inbox (~/ahg_import/inbox) with File Manager
    case 'stock_inbox': {
        adminOnly();
        require_once __DIR__ . '/stock_import_helper.php';
        $files = [];
        foreach (glob(stockImportDir() . '/inbox/*.zip') ?: [] as $f) {
            $files[] = ['name' => basename($f), 'mb' => round(filesize($f) / 1048576, 1), 'date' => date('d M Y H:i', filemtime($f))];
        }
        ok(['files' => $files, 'folder' => stockImportDir() . '/inbox']);
    }

    // One batch of a Facebook export. First call: upload 'zip' or name an inbox file.
    case 'stock_import_fb': {
        $admin = adminOnly();
        require_once __DIR__ . '/stock_import_helper.php';
        ahgSchemaReady();
        @set_time_limit(240);
        $inbox = stockImportDir() . '/inbox';
        if (!empty($_FILES['zip']['tmp_name']) && ($_FILES['zip']['error'] ?? 1) === UPLOAD_ERR_OK) {
            if (strtolower(pathinfo($_FILES['zip']['name'], PATHINFO_EXTENSION)) !== 'zip') fail('Upload the .zip file Facebook gave you.');
            $name = 'facebook-' . date('Ymd-His') . '.zip';
            if (!move_uploaded_file($_FILES['zip']['tmp_name'], "$inbox/$name")) fail('Could not save the upload.');
        } else {
            $name = basename((string)($_POST['inbox_file'] ?? ''));
            if (!preg_match('/^[\w .()-]+\.zip$/i', $name) || !is_file("$inbox/$name")) {
                fail(($_FILES['zip']['error'] ?? 0) === UPLOAD_ERR_INI_SIZE
                    ? 'That ZIP is too big to upload here. Put it in the server inbox with File Manager instead.'
                    : 'Choose a Facebook export ZIP.');
            }
        }
        $branch = (int)($_POST['branch_id'] ?? 0) ?: null;
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        try {
            $r = importFacebookZip("$inbox/$name", $branch, $offset, 25, (int)$admin['sub']);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        if ($r['next'] === null) logAdminAction($admin, 'stock_import', null, ['file' => $name, 'posts' => $r['total']]);
        ok($r + ['file' => $name]);
    }

    // New stock from a branch: photos + details → draft
    case 'stock_new': {
        $admin = adminOnly();
        require_once __DIR__ . '/stock_import_helper.php';
        ahgSchemaReady();
        $title = mb_substr(trim(strip_tags($_POST['title'] ?? '')), 0, 255);
        if (mb_strlen($title) < 3) fail('Enter the vehicle name (e.g. 2019 Toyota Hilux 2.4 GD-6).');
        $files = [];
        foreach ((array)($_FILES['photos']['tmp_name'] ?? []) as $i => $tmp) {
            $files[] = ['tmp_name' => $tmp, 'error' => $_FILES['photos']['error'][$i], 'size' => $_FILES['photos']['size'][$i]];
        }
        if (!$files) fail('Add at least one photo.');
        $id = saveUploadDraft([
            'branch_id' => (int)($_POST['branch_id'] ?? 0) ?: null, 'title' => $title,
            'description' => trim(strip_tags($_POST['description'] ?? '')),
            'year' => (int)($_POST['year'] ?? 0) ?: null, 'make_id' => (int)($_POST['make_id'] ?? 0) ?: null,
            'mileage' => (int)preg_replace('/\D/', '', $_POST['mileage'] ?? '') ?: null,
            'price' => (float)preg_replace('/[^\d.]/', '', $_POST['price'] ?? '') ?: null,
        ], $files, (int)$admin['sub']);
        ok(['message' => 'Draft saved. Review it below, then publish.', 'id' => $id]);
    }

    // Edit a draft's details and which photos to keep (in order)
    case 'stock_draft_save': {
        adminOnly();
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT photos FROM stock_drafts WHERE id = ? AND status = 'draft'");
        $st->execute([$id]);
        $have = $st->fetchColumn();
        if ($have === false) fail('Draft not found (already published?).');
        $have = json_decode($have ?: '[]', true) ?: [];
        $keep = array_values(array_intersect(json_decode($_POST['photos'] ?? '[]', true) ?: [], $have));
        $pdo->prepare("
            UPDATE stock_drafts SET title = ?, description = ?, year = ?, make_id = ?, mileage = ?, price = ?, branch_id = ?, photos = ?
            WHERE id = ?
        ")->execute([
            mb_substr(trim(strip_tags($_POST['title'] ?? '')), 0, 255), trim(strip_tags($_POST['description'] ?? '')),
            (int)($_POST['year'] ?? 0) ?: null, (int)($_POST['make_id'] ?? 0) ?: null,
            (int)preg_replace('/\D/', '', $_POST['mileage'] ?? '') ?: null,
            (float)preg_replace('/[^\d.]/', '', $_POST['price'] ?? '') ?: null,
            (int)($_POST['branch_id'] ?? 0) ?: null, json_encode($keep ?: $have), $id,
        ]);
        ok(['message' => 'Draft saved.']);
    }

    // Publish a draft: 'lot' = upcoming auction lot, 'sold' = past sale on the Sold page
    case 'stock_draft_publish': {
        $admin = adminOnly();
        require_once __DIR__ . '/stock_import_helper.php';
        require_once __DIR__ . '/imagekit_helper.php';
        ahgSchemaReady();
        @set_time_limit(240);
        $id   = (int)($_POST['id'] ?? 0);
        $mode = ($_POST['mode'] ?? '') === 'sold' ? 'sold' : 'lot';
        $st = $pdo->prepare("SELECT * FROM stock_drafts WHERE id = ? AND status = 'draft'");
        $st->execute([$id]);
        $d = $st->fetch();
        if (!$d) fail('Draft not found (already published?).');
        if (mb_strlen(trim($d['title'])) < 3) fail('Give the vehicle a name first (Save, then publish).');
        $photos = json_decode($d['photos'] ?: '[]', true) ?: [];
        if (!$photos) fail('This draft has no photos.');
        $price = (float)$d['price'];
        if ($price <= 0) fail($mode === 'sold' ? 'Enter the sold price first.' : 'Enter the starting price first.');

        if ($mode === 'lot') {
            $start = strtotime(trim($_POST['start_time'] ?? '')) ?: strtotime('+1 day 09:00');
            $end   = strtotime(trim($_POST['end_time'] ?? ''))   ?: strtotime('+8 days 18:00');
            if ($end <= $start) fail('End time must be after the start time.');
            $reserve = (float)preg_replace('/[^\d.]/', '', $_POST['reserve'] ?? '') ?: null;
        }
        $channel = array_key_exists($_POST['sale_channel'] ?? '', SALE_CHANNELS) ? $_POST['sale_channel'] : 'floor';

        // The lot first (its id names the photos), then the photos, then the links
        $pdo->prepare("
            INSERT INTO auctions (item_name, make_id, year, mileage, description, start_price, reserve_price, current_price,
                                  start_time, end_time, status, is_sold, final_price, sold_date, sale_channel, branch_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            trim($d['title']), $d['make_id'], $d['year'], $d['mileage'], $d['description'],
            $price, $mode === 'lot' ? $reserve : null,
            $mode === 'lot' ? date('Y-m-d H:i:s', $start) : $d['posted_at'],
            $mode === 'lot' ? date('Y-m-d H:i:s', $end)   : $d['posted_at'],
            $mode === 'lot' ? ($start <= time() ? 'active' : 'upcoming') : 'closed',
            $mode === 'sold' ? 1 : 0, $mode === 'sold' ? $price : null,
            $mode === 'sold' ? ($d['posted_at'] ?: date('Y-m-d H:i:s')) : null,
            $mode === 'sold' ? $channel : null, $d['branch_id'], $admin['sub'],
        ]);
        $lotId = (int)$pdo->lastInsertId();

        $uploaded = [];
        try {
            foreach ($photos as $n => $file) {
                $path = stockPhotoPath($id, $file);
                if (!$path) continue;
                $uploaded[] = uploadToImageKit($path, IMAGEKIT_VEHICLE_FOLDER, sprintf('lot-%d-%02d', $lotId, $n + 1));
            }
        } catch (RuntimeException $e) {
            $pdo->prepare("DELETE FROM auctions WHERE id = ?")->execute([$lotId]);
            foreach ($uploaded as $u) deleteStoredImage($u['url'], $u['file_id']);
            fail('Photo upload failed, nothing was published: ' . $e->getMessage());
        }
        if (!$uploaded) {
            $pdo->prepare("DELETE FROM auctions WHERE id = ?")->execute([$lotId]);
            fail('None of the photos could be read.');
        }
        $pdo->prepare("UPDATE auctions SET image_url = ?, cloudinary_public_id = ? WHERE id = ?")
            ->execute([$uploaded[0]['url'], $uploaded[0]['file_id'], $lotId]);
        // Photo 1 is the main image; the gallery holds the rest (lot pages show main + gallery)
        $g = $pdo->prepare("INSERT INTO auction_gallery (auction_id, file_path, cloudinary_public_id, sort_order) VALUES (?,?,?,?)");
        foreach (array_slice($uploaded, 1) as $n => $u) $g->execute([$lotId, $u['url'], $u['file_id'], $n + 1]);

        $pdo->prepare("UPDATE stock_drafts SET status = 'published', auction_id = ? WHERE id = ?")->execute([$lotId, $id]);
        stockRemoveDraftFiles($id);
        logAdminAction($admin, 'stock_publish', $lotId, ['mode' => $mode, 'draft' => $id, 'photos' => count($uploaded)]);
        ok(['message' => ($mode === 'sold' ? 'Added to the Sold page' : 'Published as an upcoming lot') . ' with ' . count($uploaded) . ' photo(s).',
            'auction_id' => $lotId]);
    }

    case 'stock_draft_skip': {
        $admin = adminOnly();
        require_once __DIR__ . '/stock_import_helper.php';
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE stock_drafts SET status = 'skipped' WHERE id = ? AND status = 'draft'")->execute([$id]);
        stockRemoveDraftFiles($id);
        ok(['message' => 'Draft removed.']);
    }

// ════════════════════════════════════════════════════════════
// AI PHOTOS — Grok (xAI) image editing (admin). See ai_image_helper.php
// ════════════════════════════════════════════════════════════

    // Save / replace / remove the xAI key and model. A new key is tested first.
    case 'ai_settings_save': {
        $admin = adminOnly();
        require_once __DIR__ . '/ai_image_helper.php';
        if (!empty($_POST['clear'])) {
            aiSaveSetting('xai_api_key', null);
            logAdminAction($admin, 'ai_settings', null, ['key' => 'removed']);
            ok(['message' => 'xAI key removed.']);
        }
        $key   = trim($_POST['api_key'] ?? '');
        $model = trim($_POST['model'] ?? '');
        if ($model !== '' && !preg_match('/^[a-z0-9._-]{3,80}$/i', $model)) fail('That model name doesn\'t look right.');
        $msg = [];
        if ($key !== '') {
            if (!preg_match('/^[A-Za-z0-9_-]{20,200}$/', $key)) fail('That doesn\'t look like an xAI API key (it usually starts with "xai-").');
            try { $models = xaiTestKey($key); } catch (RuntimeException $e) { fail('Key not saved — ' . $e->getMessage()); }
            aiSaveSetting('xai_api_key', $key);
            $msg[] = 'Key saved and working' . ($models ? ' (image models: ' . implode(', ', $models) . ')' : '') . '.';
            logAdminAction($admin, 'ai_settings', null, ['key' => 'updated']);
        }
        if ($model !== '') { aiSaveSetting('xai_image_model', $model); $msg[] = "Model set to $model."; }
        if (!$msg) fail('Paste a key or change the model first.');
        ok(['message' => implode(' ', $msg), 'masked' => aiMaskedKey()]);
    }

    case 'ai_settings_test': {
        adminOnly();
        require_once __DIR__ . '/ai_image_helper.php';
        try { $models = xaiTestKey(aiApiKey()); } catch (RuntimeException $e) { fail($e->getMessage()); }
        ok(['message' => 'The saved key works.' . ($models ? ' Image models: ' . implode(', ', $models) . '.' : '')]);
    }

    // A lot's photos for the editor (by lot ID)
    case 'ai_lot_photos': {
        adminOnly();
        require_once __DIR__ . '/ai_image_helper.php';
        $id = (int)($_GET['auction_id'] ?? 0);
        $st = $pdo->prepare("SELECT id, item_name, is_sold FROM auctions WHERE id = ?");
        $st->execute([$id]);
        $lot = $st->fetch();
        if (!$lot) fail('No lot with that ID.');
        $photos = array_map(fn($p) => $p + ['thumb' => cldImg($p['url'], CLD_THUMB), 'large' => cldImg($p['url'], CLD_LARGE)], aiLotPhotos($id));
        ok(['lot' => $lot, 'photos' => $photos]);
    }

    // Run one edit; the result waits in a private temp folder until saved
    case 'ai_edit': {
        $admin = adminOnly();
        require_once __DIR__ . '/ai_image_helper.php';
        rateLimit('ai_edit', 20, 600);   // runaway-click guard: 20 edits / 10 min
        @set_time_limit(200);
        $id       = (int)($_POST['auction_id'] ?? 0);
        $photoUrl = trim($_POST['photo_url'] ?? '');
        $preset   = $_POST['preset'] ?? '';
        $custom   = trim(strip_tags($_POST['instruction'] ?? ''));
        $repair      = $preset === 'repair_preview';
        $instruction = $repair ? AI_REPAIR_PROMPT
                     : (isset(AI_PRESETS[$preset]) ? AI_PRESETS[$preset][1] : mb_substr($custom, 0, 600));
        if ($instruction === '') fail('Pick a preset or describe the change.');

        // Only this lot's own photos can be sent (no arbitrary URLs)
        $owned = array_column(aiLotPhotos($id), 'url');
        if (!in_array($photoUrl, $owned, true)) fail('Pick one of this lot\'s photos.');

        try {
            $img = xaiEditImage(cldImg($photoUrl, CLD_LARGE), $instruction, !$repair);
            if ($repair) $img = aiLabelImage($img, AI_PREVIEW_LABEL);   // stamped before anyone can save it
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        $token = aiStoreTmp($img, $id, $repair);
        logAdminAction($admin, 'ai_edit', $id, ['instruction' => mb_substr($instruction, 0, 200), 'source' => $photoUrl]);
        ok([
            'token'   => $token,
            'repair'  => $repair,
            'preview' => 'data:' . $img['mime'] . ';base64,' . base64_encode($img['bytes']),
            'message' => 'Edit ready — compare it, then save or discard.',
        ]);
    }

    // Keep an edit: upload to ImageKit as the new main photo (the old main
    // moves into the gallery, so nothing is lost) or as an extra gallery photo
    case 'ai_save': {
        $admin = adminOnly();
        require_once __DIR__ . '/ai_image_helper.php';
        $id    = (int)($_POST['auction_id'] ?? 0);
        $token = (string)($_POST['token'] ?? '');
        $mode  = ($_POST['mode'] ?? '') === 'main' ? 'main' : 'gallery';
        $path  = aiTmpPath($token);
        if (!$path || strpos($token, $id . '_') !== 0) fail('That edit has expired — run it again.');
        if ($mode === 'main' && aiIsPreviewToken($token)) fail('An after-repair preview can only be added to the gallery, never as the main photo.');
        try {
            $url = aiSaveToLot($id, $path, $mode, aiIsPreviewToken($token) ? 'repair-preview' : 'ai');
        } catch (RuntimeException $e) {
            fail('Could not save: ' . $e->getMessage());
        }
        @unlink($path);
        logAdminAction($admin, 'ai_save', $id, ['mode' => $mode, 'url' => $url, 'preview' => aiIsPreviewToken($token)]);
        ok(['message' => $mode === 'main' ? 'Saved as the main photo (the old one is now in the gallery).' : 'Added to the gallery.',
            'url' => $url]);
    }

    // Manual photo editor (crop / rotate / light / blur) — the browser sends the result
    case 'photo_save_edit': {
        $admin = adminOnly();
        require_once __DIR__ . '/ai_image_helper.php';
        $id   = (int)($_POST['auction_id'] ?? 0);
        $mode = ($_POST['mode'] ?? '') === 'main' ? 'main' : 'gallery';
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', (string)($_POST['image'] ?? ''), $m)) fail('No edited image received.');
        $bytes = base64_decode($m[2], true);
        if (!$bytes || strlen($bytes) > 12 * 1048576) fail('The edited image is missing or too large.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) fail('Not an image.');
        $token = aiStoreTmp(['bytes' => $bytes, 'mime' => $mime], $id);
        try {
            $url = aiSaveToLot($id, aiTmpPath($token), $mode, 'edit');
        } catch (RuntimeException $e) {
            fail('Could not save: ' . $e->getMessage());
        } finally {
            if ($p = aiTmpPath($token)) @unlink($p);
        }
        logAdminAction($admin, 'photo_edit', $id, ['mode' => $mode, 'url' => $url]);
        ok(['message' => $mode === 'main' ? 'Saved as the main photo (the old one is now in the gallery).' : 'Added to the gallery.', 'url' => $url]);
    }

// ════════════════════════════════════════════════════════════
// SALES — mark sold / undo / bulk / relist / delete / export (admin)
// ════════════════════════════════════════════════════════════

    // One lot: record (or correct) the sale with its final price
    case 'mark_sold': {
        $admin     = adminOnly();
        $auctionId = (int)($_POST['auction_id'] ?? 0);
        $price     = round((float)($_POST['final_price'] ?? 0), 2);
        $dateIn    = trim($_POST['sold_date'] ?? '');
        $buyerId   = (int)($_POST['buyer_user_id'] ?? 0) ?: null;
        $buyerName = mb_substr(trim(strip_tags($_POST['buyer_name'] ?? '')), 0, 255);
        $channel   = array_key_exists($_POST['sale_channel'] ?? '', SALE_CHANNELS) ? $_POST['sale_channel'] : null;

        if (!$auctionId)  fail('Missing auction ID.');
        ahgSchemaReady();   // sale_channel column

        $s = $pdo->prepare("SELECT id, item_name, is_sold, final_price, sold_to, sold_date, sale_channel FROM auctions WHERE id = ?");
        $s->execute([$auctionId]);
        $lot = $s->fetch();
        if (!$lot) fail('Auction not found.');
        $wasSold = (int)$lot['is_sold'] === 1;

        // A new sale needs a price; editing an existing sale may leave it blank
        // (e.g. lots bulk-marked sold before prices were recorded)
        if ($price <= 0 && !$wasSold) fail('Enter the final selling price.');

        $soldDate = ($wasSold && $dateIn === '' && $lot['sold_date']) ? $lot['sold_date'] : date('Y-m-d H:i:s');
        if ($dateIn !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $dateIn);
            if (!$d || $d->format('Y-m-d') !== $dateIn) fail('Invalid sold date.');
            if ($dateIn > date('Y-m-d')) fail('Sold date cannot be in the future.');
            if ($dateIn !== date('Y-m-d')) $soldDate = $dateIn . ' 12:00:00';
        }

        if ($buyerId) {
            $u = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $u->execute([$buyerId]);
            $uName = $u->fetchColumn();
            if ($uName === false) fail('Selected buyer not found.');
            if ($buyerName === '') $buyerName = $uName;
        }

        $pdo->prepare("
            UPDATE auctions SET status = 'closed', is_sold = 1, final_price = ?, sold_date = ?,
                   sold_to = ?, sold_to_user_id = ?, sale_channel = ?
            WHERE id = ?
        ")->execute([$price > 0 ? $price : null, $soldDate, $buyerName !== '' ? $buyerName : null, $buyerId,
                     $channel ?? $lot['sale_channel'], $auctionId]);

        if (!$wasSold) {
            if ($buyerId) notifyLotWon($buyerId, $auctionId, $lot['item_name'], $price);
            notifyWatchersSold($auctionId, $buyerId);
        }
        logAdminAction($admin, $wasSold ? 'edit_sale' : 'mark_sold', $auctionId, array_filter([
            'final_price'     => $price > 0 ? $price : null,
            'previous_price'  => $wasSold && $lot['final_price'] !== null ? (float)$lot['final_price'] : null,
            'sold_date'       => substr($soldDate, 0, 10),
            'previous_date'   => $wasSold && $lot['sold_date'] ? substr($lot['sold_date'], 0, 10) : null,
            'channel'         => $channel,
            'buyer'           => $buyerName ?: null,
            'buyer_user_id'   => $buyerId,
        ], fn($v) => $v !== null));

        ok(['message' => ($wasSold ? 'Sale updated: ' : 'Marked sold: ') . $lot['item_name']
            . ($price > 0 ? ' for ' . formatRand($price) : '') . '.']);
    }

    // Sold lots that all share one bulk-marked date (e.g. 86 lots "sold" on
    // 21 Oct) get varied dates instead: each a random weekday, 09:00–16:00,
    // between when the lot was listed (or N years ago, if later) and that day.
    // Every change goes in the change log with its old date, so undo_spread_dates
    // can put them back. Lots listed too close to the bulk day keep their date.
    case 'spread_sold_dates': {
        $admin = adminOnly();
        $day   = trim($_POST['day'] ?? '');
        $years = min(5, max(1, (int)($_POST['years'] ?? 2)));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) fail('Pick the bulk sold date to spread.');

        $st = $pdo->prepare("SELECT id, sold_date, created_at, start_time FROM auctions
                             WHERE is_sold = 1 AND DATE(sold_date) = ? ORDER BY id");
        $st->execute([$day]);
        $lots = $st->fetchAll();
        if (count($lots) < 5) fail('Only dates shared by 5 or more sold lots (a bulk mark) can be spread.');

        $batch = date('YmdHis');
        $floor = strtotime("-$years years");
        $upd   = $pdo->prepare("UPDATE auctions SET sold_date = ? WHERE id = ?");
        $moved = 0; $kept = 0; $min = null; $max = null;
        $pdo->beginTransaction();
        try {
            foreach ($lots as $l) {
                $hi = strtotime($l['sold_date']);
                $lo = max($floor, strtotime($l['created_at'] ?: $l['start_time'] ?: '') ?: $floor);
                if ($lo >= $hi - 86400) { $kept++; continue; }
                $t = random_int($lo, $hi);
                while ((int)date('N', $t) >= 6) $t -= 86400;            // Sat/Sun → Friday
                if ($t < $lo) { $kept++; continue; }
                $new = date('Y-m-d', $t) . sprintf(' %02d:%02d:00', random_int(9, 15), random_int(0, 59));
                logAdminAction($admin, 'spread_date', (int)$l['id'],
                    ['batch' => $batch, 'previous_date' => $l['sold_date'], 'sold_date' => $new], true);
                $upd->execute([$new, $l['id']]);
                $moved++;
                $min = $min === null || $new < $min ? $new : $min;
                $max = $max === null || $new > $max ? $new : $max;
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            fail('Could not spread the dates: ' . $e->getMessage());
        }
        ok([
            'batch'   => $batch,
            'message' => "$moved lot(s) now dated between " . safeDate('d M Y', $min) . ' and ' . safeDate('d M Y', $max)
                       . ($kept ? "; $kept kept their date (listed too recently)." : '.'),
        ]);
    }

    case 'undo_spread_dates': {
        $admin = adminOnly();
        $batch = preg_replace('/\D/', '', $_POST['batch'] ?? '');
        if ($batch === '') fail('Missing batch.');
        $st = $pdo->prepare("SELECT auction_id, details FROM admin_activity_log
                             WHERE action = 'spread_date' AND details LIKE ? ORDER BY id");
        $st->execute(['%"batch":"' . $batch . '"%']);
        $rows = $st->fetchAll();
        if (!$rows) fail('Nothing to undo for that batch.');
        $upd = $pdo->prepare("UPDATE auctions SET sold_date = ? WHERE id = ? AND is_sold = 1");
        $n = 0;
        foreach ($rows as $r) {
            $d = json_decode($r['details'], true) ?: [];
            if (empty($d['previous_date'])) continue;
            $upd->execute([$d['previous_date'], $r['auction_id']]);
            $n += $upd->rowCount();
        }
        logAdminAction($admin, 'undo_spread', null, ['batch' => $batch, 'restored' => $n]);
        ok(['message' => "$n sold date(s) restored."]);
    }

    // One lot: take the sale back off (lot stays closed)
    case 'undo_sold': {
        $admin     = adminOnly();
        $auctionId = (int)($_POST['auction_id'] ?? 0);
        if (!$auctionId) fail('Missing auction ID.');

        $s = $pdo->prepare("SELECT item_name, is_sold, final_price, sold_date, sold_to FROM auctions WHERE id = ?");
        $s->execute([$auctionId]);
        $lot = $s->fetch();
        if (!$lot) fail('Auction not found.');
        if (!(int)$lot['is_sold']) fail('This lot is not marked as sold.');

        $pdo->prepare("
            UPDATE auctions SET is_sold = 0, final_price = NULL, sold_date = NULL, sold_to = NULL, sold_to_user_id = NULL
            WHERE id = ?
        ")->execute([$auctionId]);
        logAdminAction($admin, 'undo_sold', $auctionId, [
            'previous_price' => (float)$lot['final_price'],
            'previous_date'  => $lot['sold_date'],
            'previous_buyer' => $lot['sold_to'],
        ]);

        ok(['message' => 'Sale removed: ' . $lot['item_name'] . ' is back to Closed (unsold).']);
    }

    // Many lots: [{id, price}, …] — every price is checked before anything is saved
    case 'bulk_mark_sold': {
        $admin = adminOnly();
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($items) || !$items) fail('No lots selected.');
        if (count($items) > 500) fail('Too many lots in one go (max 500).');

        $clean = [];
        foreach ($items as $it) {
            $id    = (int)($it['id'] ?? 0);
            $price = round((float)($it['price'] ?? 0), 2);
            if ($id <= 0) continue;
            if ($price <= 0) fail('Every lot needs a final price above R0 (lot #' . $id . ').');
            $clean[$id] = $price;
        }
        if (!$clean) fail('No lots selected.');

        ahgSchemaReady();
        $channel = array_key_exists($_POST['sale_channel'] ?? '', SALE_CHANNELS) ? $_POST['sale_channel'] : null;
        $upd = $pdo->prepare("
            UPDATE auctions SET sold_date = IF(is_sold = 1 AND sold_date IS NOT NULL, sold_date, NOW()), status = 'closed', is_sold = 1, final_price = ?,
                   sale_channel = COALESCE(?, sale_channel)
            WHERE id = ?
        ");
        $pdo->beginTransaction();
        try {
            foreach ($clean as $id => $price) {
                $upd->execute([$price, $channel, $id]);
                logAdminAction($admin, 'mark_sold', $id, ['final_price' => $price, 'bulk' => true, 'channel' => $channel]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            fail('Could not save the sales: ' . $e->getMessage());
        }
        foreach (array_keys($clean) as $id) notifyWatchersSold($id);
        $n = count($clean);
        ok(['message' => "$n lot(s) marked sold, total " . formatRand(array_sum($clean)) . '.']);
    }

    // Many lots: put unsold lots back up for auction with new times.
    // Old bids are copied into the change log, then cleared, so the lot
    // starts fresh at its starting price. Refused if the log can't be written.
    case 'bulk_relist': {
        $admin = adminOnly();
        $ids   = postedIds();
        $start = trim($_POST['start_time'] ?? '');
        $end   = trim($_POST['end_time'] ?? '');
        if (!$ids) fail('No lots selected.');
        if (!strtotime($start) || !strtotime($end)) fail('Set both a start and an end time.');
        if (strtotime($end) <= strtotime($start)) fail('End time must be after start time.');
        $start  = date('Y-m-d H:i:s', strtotime($start));
        $end    = date('Y-m-d H:i:s', strtotime($end));
        $status = strtotime($start) <= time() ? 'active' : 'upcoming';

        $in  = implode(',', array_fill(0, count($ids), '?'));
        $sel = $pdo->prepare("SELECT id FROM auctions WHERE id IN ($in) AND is_sold = 0");
        $sel->execute($ids);
        $unsold = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));
        $skipped = count($ids) - count($unsold);
        if (!$unsold) fail('All selected lots are sold — undo the sale first to relist them.');

        $bidsQ = $pdo->prepare("
            SELECT bidder_number, bidder_name, user_id, COALESCE(bid_amount, amount) AS amount,
                   COALESCE(bid_time, created_at) AS at
            FROM bids WHERE auction_id = ? ORDER BY id
        ");
        $pdo->beginTransaction();
        try {
            foreach ($unsold as $id) {
                $bidsQ->execute([$id]);
                $bids = $bidsQ->fetchAll();
                logAdminAction($admin, 'relist', $id, ['start' => $start, 'end' => $end, 'cleared_bids' => $bids], true);
                $pdo->prepare("DELETE FROM bids WHERE auction_id = ?")->execute([$id]);
                $pdo->prepare("
                    UPDATE auctions SET status = ?, start_time = ?, end_time = ?, current_price = start_price,
                           is_sold = 0, final_price = NULL, sold_date = NULL, sold_to = NULL, sold_to_user_id = NULL
                    WHERE id = ?
                ")->execute([$status, $start, $end, $id]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if (str_contains($e->getMessage(), 'admin_activity_log')) {
                fail('Relist needs the change-log table first: run sql/ahg_admin_activity_log.sql in phpMyAdmin.');
            }
            fail('Relist failed: ' . $e->getMessage());
        }
        $msg = count($unsold) . ' lot(s) relisted as ' . ($status === 'active' ? 'Live' : 'Upcoming') . '.';
        if ($skipped) $msg .= " $skipped sold lot(s) skipped.";
        ok(['message' => $msg]);
    }

    case 'bulk_delete_auctions': {
        $admin = adminOnly();
        $ids   = postedIds();
        if (!$ids) fail('No lots selected.');
        foreach ($ids as $id) {
            $bidCount = $pdo->prepare("SELECT COUNT(*) FROM bids WHERE auction_id = ?");
            $bidCount->execute([$id]);
            logAdminAction($admin, 'delete', $id, ['bids' => (int)$bidCount->fetchColumn(), 'bulk' => true]);
            deleteAuctionFully($id);
        }
        ok(['message' => count($ids) . ' lot(s) deleted.']);
    }

    // CSV download. ids=1,2,3 for a selection, else filter=all|sold|… plus
    // optional from/to (sold date, Y-m-d) for the sales report.
    case 'export_auctions_csv': {
        adminOnly();
        $where  = [];
        $params = [];
        $ids = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
        if ($ids) {
            $ids = array_slice(array_values(array_unique($ids)), 0, 2000);
            $where[] = 'a.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params  = $ids;
        } else {
            $filter = $_GET['filter'] ?? 'all';
            if ($filter === 'sold') {
                $where[] = 'a.is_sold = 1';
                foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
                    $v = $_GET[$k] ?? '';
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                        $where[]  = "DATE(a.sold_date) $op ?";
                        $params[] = $v;
                    }
                }
            } elseif (in_array($filter, ['active', 'upcoming'], true)) {
                $where[] = 'a.status = ? AND a.is_sold = 0';
                $params[] = $filter;
            } elseif ($filter === 'closed') {
                $where[] = "a.status = 'closed' AND a.is_sold = 0";
            }
        }
        $sql = "
            SELECT a.id, a.item_name, m.name AS make, a.year, a.mileage, a.vin_number,
                   a.condition_grade, a.status, a.is_sold, a.start_price, a.reserve_price,
                   a.current_price, a.final_price, a.sold_date, a.sold_to,
                   a.start_time, a.end_time,
                   (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) AS bid_count
            FROM auctions a LEFT JOIN makes m ON m.id = a.make_id
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
            ORDER BY a.id";
        $st = $pdo->prepare($sql);
        $st->execute($params);

        ob_end_clean();
        $fname = 'ahg-lots-' . (($_GET['filter'] ?? '') === 'sold' ? 'sales-' : '') . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 correctly with a BOM
        fputcsv($out, ['Lot ID', 'Vehicle', 'Make', 'Year', 'Mileage', 'VIN', 'DEKRA Grade', 'Status',
                       'Sold', 'Start Price', 'Reserve', 'Highest Bid', 'Final Price', 'Sold Date', 'Buyer',
                       'Start Time', 'End Time', 'Bids', 'Link']);
        while ($r = $st->fetch()) {
            fputcsv($out, [
                $r['id'], $r['item_name'], $r['make'], $r['year'], $r['mileage'], $r['vin_number'],
                $r['condition_grade'], $r['status'], $r['is_sold'] ? 'Yes' : 'No',
                $r['start_price'], $r['reserve_price'], $r['current_price'],
                $r['is_sold'] ? $r['final_price'] : '', $r['is_sold'] ? $r['sold_date'] : '',
                $r['is_sold'] ? $r['sold_to'] : '', $r['start_time'], $r['end_time'], $r['bid_count'],
                CANONICAL_URL . '/auction.php?id=' . $r['id'],
            ]);
        }
        fclose($out);
        exit;
    }

// ════════════════════════════════════════════════════════════
// UNKNOWN ACTION
// ════════════════════════════════════════════════════════════

    default:
        fail('Unknown action: ' . htmlspecialchars($action, ENT_QUOTES), 400);
        break;
}
