<?php
// ============================================================
// AUCTION HUB GROUP — admin_dashboard.php
// Admin control panel
// Tabs: Overview · POP Approvals · Auctions · Users · Email · Import
// ============================================================

// Global exception handler — logs errors and outputs a safe inline message.
// IMPORTANT: only outputs a full page if headers haven't been sent yet.
// If headers are already sent (mid-page), outputs a compact inline error so
// the rest of the page structure isn't destroyed.
set_exception_handler(function (Throwable $e) {
    $isLocal = str_contains($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost');
    error_log('[AHG admin_dashboard] Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        // Nothing output yet — safe to send a proper error page
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Dashboard Error</title>
        <style>body{font-family:sans-serif;padding:40px;background:#fff8f8}
        .box{background:#fff;border:1px solid #f5c6cb;border-radius:8px;padding:24px;max-width:600px;margin:auto}
        h2{color:#c00}pre{background:#f9f9f9;padding:12px;border-radius:4px;overflow:auto;font-size:12px}</style>
        </head><body><div class="box"><h2>⚠ Dashboard could not load</h2>
        <p>A server error occurred. Please try again or contact support.</p>';
        if ($isLocal) {
            echo '<p><strong>' . htmlspecialchars(get_class($e)) . ':</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . "\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }
        echo '<p><a href="login.php">← Back to login</a></p></div></body></html>';
    } else {
        // HTML already output — append a compact inline error, do NOT dump a full page
        echo '<div style="margin:16px;padding:16px;background:#fff3f3;border:2px solid #f5c6cb;border-radius:8px;font-family:sans-serif;clear:both">'
           . '<strong style="color:#c00">⚠ A section failed to load.</strong> ';
        if ($isLocal) {
            echo htmlspecialchars($e->getMessage()) . ' — ' . htmlspecialchars(basename($e->getFile())) . ':' . $e->getLine();
        } else {
            echo 'The administrator has been notified.';
        }
        echo '</div>';
    }
    exit;
});

require_once 'config.php';
require_once 'auth.php';

// ── Admin Auth Guard ──────────────────────────────────────────
$token = getTokenFromRequest();
if (!$token) redirect('login.php', 'Admin access required.', 'error');

$currentUser = verifyJWT($token);
if (!$currentUser || $currentUser['role'] !== 'admin') {
    redirect('user_dashboard.php', 'Access denied.', 'error');
}

syncSession($currentUser);

// ── Active tab ────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'overview';
$validTabs = ['overview', 'pop', 'auctions', 'events', 'users', 'email', 'import', 'analytics', 'manual_bid', 'banking', 'testimonials', 'team', 'notifications', 'refunds', 'news', 'newsletter', 'careers', 'workers', 'ai'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'overview';

// ── Flash ─────────────────────────────────────────────────────
$flash = getFlash();

// ── DATA QUERIES ──────────────────────────────────────────────

// New tables/columns (db_schema.php) exist before any query below uses them
ahgSchemaReady();

// --- Overview stats ---
$stats = [];

$stats['total_auctions']    = $pdo->query("SELECT COUNT(*) FROM auctions")->fetchColumn();
$stats['active_auctions']   = $pdo->query("SELECT COUNT(*) FROM auctions WHERE status='active' AND is_sold=0")->fetchColumn();
$stats['upcoming_auctions'] = $pdo->query("SELECT COUNT(*) FROM auctions WHERE status='upcoming' AND is_sold=0")->fetchColumn();
$stats['closed_auctions']   = $pdo->query("SELECT COUNT(*) FROM auctions WHERE status='closed' AND is_sold=0")->fetchColumn();
$stats['sold_auctions']     = $pdo->query("SELECT COUNT(*) FROM auctions WHERE is_sold=1")->fetchColumn();

$stats['total_users']       = $pdo->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn();
$stats['verified_bidders']  = $pdo->query("SELECT COUNT(*) FROM users WHERE bidder_status='verified_bidder'")->fetchColumn();
$stats['pending_pops']      = $pdo->query("SELECT COUNT(*) FROM users WHERE bidder_status='pending_approval'")->fetchColumn();
$stats['suspended_users']   = $pdo->query("SELECT COUNT(*) FROM users WHERE status='suspended'")->fetchColumn();

$stats['total_bids']        = $pdo->query("SELECT COUNT(*) FROM bids")->fetchColumn();
$stats['revenue']           = $pdo->query("SELECT COALESCE(SUM(final_price),0) FROM auctions WHERE is_sold=1 AND final_price > 0")->fetchColumn();
$stats['pending_refunds']   = 0;
try { $stats['pending_refunds'] = $pdo->query("SELECT COUNT(*) FROM refund_requests WHERE status IN ('pending','under_review')")->fetchColumn(); } catch(Exception $e) {}

// Sales summary + change log (Overview tab only)
$sales = null;
$activityRows = [];
$activityMissing = false;
$activityLimit = ($_GET['log'] ?? '') === '200' ? 200 : 15;
if ($activeTab === 'overview') {
    $salesQ = $pdo->prepare("
        SELECT COUNT(*) AS n, COALESCE(SUM(final_price), 0) AS total
        FROM auctions WHERE is_sold = 1 AND final_price > 0 AND sold_date >= ? AND sold_date < ?
    ");
    $mStart = date('Y-m-01');
    $mNext  = date('Y-m-01', strtotime('first day of next month'));
    $mPrev  = date('Y-m-01', strtotime('first day of last month'));
    $salesQ->execute([$mStart, $mNext]);  $thisMonth = $salesQ->fetch();
    $salesQ->execute([$mPrev,  $mStart]); $lastMonth = $salesQ->fetch();
    $ratio = $pdo->query("
        SELECT AVG(final_price / start_price) AS r, AVG(final_price) AS avg_price, COUNT(*) AS n
        FROM auctions
        WHERE is_sold = 1 AND final_price > 0 AND start_price > 0
          AND sold_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    ")->fetch();
    $attention = (int)$pdo->query("
        SELECT COUNT(*) FROM auctions a
        WHERE (a.is_sold = 0 AND a.status = 'closed' AND EXISTS (SELECT 1 FROM bids b WHERE b.auction_id = a.id))
           OR (a.is_sold = 1 AND (a.final_price IS NULL OR a.final_price <= 0))
    ")->fetchColumn();
    $sales = [
        'month_n'     => (int)$thisMonth['n'],
        'month_total' => (float)$thisMonth['total'],
        'last_n'      => (int)$lastMonth['n'],
        'last_total'  => (float)$lastMonth['total'],
        'ratio'       => $ratio['r'] !== null ? (float)$ratio['r'] : null,
        'avg_price'   => (float)$ratio['avg_price'],
        'ratio_n'     => (int)$ratio['n'],
        'attention'   => $attention,
        'month_from'  => $mStart,
        'month_label' => date('F Y'),
    ];
    ahgSchemaReady(); // creates admin_activity_log on first use
    try {
        $activityRows = $pdo->query("
            SELECT admin_name, action, auction_id, item_name, details, created_at
            FROM admin_activity_log ORDER BY id DESC LIMIT " . $activityLimit . "
        ")->fetchAll();
    } catch (PDOException $e) {
        $activityMissing = true;
    }
}

/** One-line description of a change-log row (shared by the Overview list and its JS loader) */
function describeAdminActivity(string $action, ?string $detailsJson): string {
    $d = $detailsJson ? (json_decode($detailsJson, true) ?: []) : [];
    $money = fn($v) => 'R' . number_format((float)$v, 0, '.', ',');
    switch ($action) {
        case 'mark_sold':
            return 'Marked sold for ' . $money($d['final_price'] ?? 0)
                 . (!empty($d['buyer']) ? ' to ' . $d['buyer'] : '') . (!empty($d['bulk']) ? ' (bulk)' : '');
        case 'edit_sale':
            $parts = [];
            if (($d['previous_price'] ?? null) != ($d['final_price'] ?? null)) {
                $parts[] = 'price ' . (isset($d['previous_price']) ? $money($d['previous_price']) : 'none')
                         . ' → ' . (isset($d['final_price']) ? $money($d['final_price']) : 'none');
            }
            if (!empty($d['previous_date']) && ($d['previous_date'] !== ($d['sold_date'] ?? ''))) {
                $parts[] = 'date ' . safeDate('d M Y', $d['previous_date']) . ' → ' . safeDate('d M Y', $d['sold_date'] ?? null);
            }
            return 'Edited sale' . ($parts ? ': ' . implode(', ', $parts) : '') . (!empty($d['buyer']) ? ' (buyer ' . $d['buyer'] . ')' : '');
        case 'spread_date':
            return 'Sold date ' . safeDate('d M Y', $d['previous_date'] ?? null) . ' → ' . safeDate('d M Y', $d['sold_date'] ?? null) . ' (spread)';
        case 'undo_spread':
            return 'Undid date spread: ' . (int)($d['restored'] ?? 0) . ' date(s) restored';
        case 'undo_sold':
            return 'Removed sale (was ' . $money($d['previous_price'] ?? 0) . ')';
        case 'relist':
            $n = count($d['cleared_bids'] ?? []);
            return 'Relisted, ' . safeDate('d M H:i', $d['start'] ?? null) . ' – ' . safeDate('d M H:i', $d['end'] ?? null)
                 . ($n ? " ($n old bid" . ($n === 1 ? '' : 's') . ' cleared)' : '');
        case 'delete':
            return 'Deleted lot' . (!empty($d['bids']) ? ' with ' . $d['bids'] . ' bid(s)' : '') . (!empty($d['bulk']) ? ' (bulk)' : '');
        case 'status':
            return 'Status set to ' . ($d['status'] ?? '?') . (!empty($d['bulk']) ? ' (bulk)' : '');
        case 'ai_edit':
            return 'AI photo edit: "' . mb_strimwidth($d['instruction'] ?? '', 0, 70, '…') . '"';
        case 'ai_save':
            return 'Saved AI-edited photo as ' . (($d['mode'] ?? '') === 'main' ? 'main photo' : 'gallery photo');
        case 'ai_settings':
            return 'xAI key ' . ($d['key'] ?? 'changed');
        case 'floor_bidder':
            return (!empty($d['created']) ? 'Added floor bidder ' : 'Edited floor bidder ') . ($d['name'] ?? '')
                 . (!empty($d['bidder_number']) ? ' (' . $d['bidder_number'] . ')' : '');
    }
    return ucfirst(str_replace('_', ' ', $action));
}

// Refund requests list (loaded only when tab is active)
$refundRequests = [];
if ($activeTab === 'refunds') {
    try {
        $refundRequests = $pdo->query("
            SELECT r.*, u.full_name, u.email
            FROM refund_requests r
            JOIN users u ON u.id = r.user_id
            ORDER BY r.created_at DESC LIMIT 200
        ")->fetchAll();
    } catch(Exception $e) {}
}

// Next upcoming auction
$nextAuction = $pdo->query("
    SELECT item_name, start_time FROM auctions
    WHERE status='upcoming' ORDER BY start_time ASC LIMIT 1
")->fetch();

// Recent activity (last 10 bids)
$recentBids = $pdo->query("
    SELECT b.bid_amount, b.bid_time, b.bidder_number,
           u.full_name, a.item_name, a.id AS auction_id
    FROM bids b
    JOIN users u ON u.id = b.user_id
    JOIN auctions a ON a.id = b.auction_id
    ORDER BY b.bid_time DESC LIMIT 10
")->fetchAll();

// --- POP Approvals ---
$pendingPOPs = $pdo->query("
    SELECT u.id, u.full_name, u.email, u.phone, u.id_number,
           u.pop_file_path, u.pop_file_type, u.pop_uploaded_at,
           u.pop_original_name, u.bidder_status,
           br.bank_reference, br.payment_amount, br.id AS reg_id
    FROM users u
    LEFT JOIN bidder_registrations br ON br.user_id = u.id AND br.status = 'pending'
    WHERE u.bidder_status = 'pending_approval'
    ORDER BY u.pop_uploaded_at ASC
")->fetchAll();

$recentlyApproved = $pdo->query("
    SELECT u.id, u.full_name, u.email, u.bidder_number, u.pop_approved_at,
           a.full_name AS approved_by_name
    FROM users u
    LEFT JOIN users a ON a.id = u.pop_approved_by
    WHERE u.bidder_status = 'verified_bidder' AND u.pop_approved_at IS NOT NULL
    ORDER BY u.pop_approved_at DESC LIMIT 5
")->fetchAll();

// Next available bidder number suggestion
$lastBidder = $pdo->query("
    SELECT bidder_number FROM users
    WHERE bidder_number IS NOT NULL
    ORDER BY id DESC LIMIT 1
")->fetchColumn();

$nextBidderNum = '';
if ($lastBidder && preg_match('/AHG-\d{4}-(\d+)/', $lastBidder, $m)) {
    $nextBidderNum = BIDDER_PREFIX . str_pad((int)$m[1] + 1, 3, '0', STR_PAD_LEFT);
} else {
    $nextBidderNum = BIDDER_PREFIX . '001';
}

// --- Auctions tab ---
$auctionSearch = trim($_GET['asearch'] ?? '');
$auctionStatus = $_GET['astatus'] ?? '';
$auctionPage   = max(1, (int)($_GET['apage'] ?? 1));
$auctionPer    = 48;

// Status tabs. "attention" = lots an admin still has to decide on:
// closed with bids but not sold (reserve not met → sell or relist),
// or marked sold without a final price.
$auctionTabSql = [
    'active'    => "a.status = 'active'   AND a.is_sold = 0",
    'upcoming'  => "a.status = 'upcoming' AND a.is_sold = 0",
    'closed'    => "a.status = 'closed'   AND a.is_sold = 0",
    'sold'      => "a.is_sold = 1",
    'attention' => "((a.is_sold = 0 AND a.status = 'closed' AND EXISTS (SELECT 1 FROM bids bx WHERE bx.auction_id = a.id))
                     OR (a.is_sold = 1 AND (a.final_price IS NULL OR a.final_price <= 0)))",
];
if (!isset($auctionTabSql[$auctionStatus])) $auctionStatus = '';

$aWhere = [];
$aParams = [];
if ($auctionSearch) {
    $aWhere[] = "(a.item_name LIKE ? OR m.name LIKE ? OR a.vin_number LIKE ? OR a.id = ?)";
    array_push($aParams, "%$auctionSearch%", "%$auctionSearch%", "%$auctionSearch%", (int)$auctionSearch);
}
// Counts per tab honour the search box, so the tabs read "Sold (3)" for "toyota"
$aTabCounts = ['' => 0];
$aBaseWhere = $aWhere ? implode(' AND ', $aWhere) : '1=1';
$cnt = $pdo->prepare("SELECT COUNT(*) FROM auctions a LEFT JOIN makes m ON m.id = a.make_id WHERE $aBaseWhere");
$cnt->execute($aParams);
$aTabCounts[''] = (int)$cnt->fetchColumn();
foreach ($auctionTabSql as $k => $cond) {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM auctions a LEFT JOIN makes m ON m.id = a.make_id WHERE $aBaseWhere AND $cond");
    $cnt->execute($aParams);
    $aTabCounts[$k] = (int)$cnt->fetchColumn();
}
if ($auctionStatus) $aWhere[] = $auctionTabSql[$auctionStatus];
$aWhereSQL = $aWhere ? 'WHERE ' . implode(' AND ', $aWhere) : '';

$aTotal = $pdo->prepare("SELECT COUNT(*) FROM auctions a LEFT JOIN makes m ON m.id = a.make_id $aWhereSQL");
$aTotal->execute($aParams);
$aTotal = (int)$aTotal->fetchColumn();
$aPages = max(1, (int)ceil($aTotal / $auctionPer));
$auctionPage = min($auctionPage, $aPages);
$aOffset = ($auctionPage - 1) * $auctionPer;

$aStmt = $pdo->prepare("
    SELECT a.id, a.item_name, a.year, a.status, a.current_price,
           a.reserve_price, a.start_time, a.end_time, a.start_price,
           a.image_url, a.is_sold, a.final_price, a.sold_date, a.sold_to, a.sale_channel,
           m.name AS make_name,
           COUNT(b.id) AS bid_count,
           MAX(COALESCE(b.bid_amount, b.amount)) AS top_bid
    FROM auctions a
    LEFT JOIN makes m ON m.id = a.make_id
    LEFT JOIN bids b ON b.auction_id = a.id
    $aWhereSQL
    GROUP BY a.id
    ORDER BY a.created_at DESC
    LIMIT $auctionPer OFFSET $aOffset
");
$aStmt->execute($aParams);
$adminAuctions = $aStmt->fetchAll();

// Makes list for add/edit dropdowns
$makes = $pdo->query("SELECT id, name FROM makes ORDER BY name")->fetchAll();

// --- Users tab ---
$userSearch  = trim($_GET['usearch'] ?? '');
$userStatus  = $_GET['ustatus'] ?? '';
$userPage    = max(1, (int)($_GET['upage'] ?? 1));
$userPer     = 25;

$uWhere  = ["u.role = 'user'"];
$uParams = [];
if ($userSearch) {
    $uWhere[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.bidder_number LIKE ?)";
    $uParams[] = "%$userSearch%";
    $uParams[] = "%$userSearch%";
    $uParams[] = "%$userSearch%";
}
if (in_array($userStatus, ['registered','pending_payment','pending_approval','verified_bidder','suspended'])) {
    $uWhere[] = "u.bidder_status = ?";
    $uParams[] = $userStatus;
}
$uWhereSQL = 'WHERE ' . implode(' AND ', $uWhere);

$uTotal = $pdo->prepare("SELECT COUNT(*) FROM users u $uWhereSQL");
$uTotal->execute($uParams);
$uTotal = (int)$uTotal->fetchColumn();
$uPages = max(1, ceil($uTotal / $userPer));
$uOffset = ($userPage - 1) * $userPer;

$uStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, u.phone,
           u.bidder_status, u.bidder_number, u.status,
           u.pop_uploaded_at, u.created_at,
           COUNT(b.id) AS bid_count
    FROM users u
    LEFT JOIN bids b ON b.user_id = u.id
    $uWhereSQL
    GROUP BY u.id
    ORDER BY u.created_at DESC
    LIMIT $userPer OFFSET $uOffset
");
$uStmt->execute($uParams);
$adminUsers = $uStmt->fetchAll();

// --- Events tab ---
$eventSearch = trim($_GET['esearch'] ?? '');
$eventStatus = $_GET['estatus'] ?? '';
$eventPage   = max(1, (int)($_GET['epage'] ?? 1));
$eventPer    = 20;

$eWhere = [];
$eParams = [];
if ($eventSearch) {
    $eWhere[] = "(e.title LIKE ? OR e.bank_lot LIKE ? OR e.province LIKE ?)";
    $eParams[] = "%$eventSearch%";
    $eParams[] = "%$eventSearch%";
    $eParams[] = "%$eventSearch%";
}
if (in_array($eventStatus, ['draft','upcoming','live','completed','cancelled'])) {
    $eWhere[] = "e.status = ?";
    $eParams[] = $eventStatus;
}
$eWhereSQL = $eWhere ? 'WHERE ' . implode(' AND ', $eWhere) : '';

// --- Auction Events (wrapped in try/catch — table may not exist on fresh install) ---
$eTotal = 0; $ePages = 1; $eOffset = 0; $adminEvents = []; $editEventId = 0; $editEvent = null; $editEventVehicles = [];
try {
    $eTotalStmt = $pdo->prepare("SELECT COUNT(*) FROM auction_events e $eWhereSQL");
    $eTotalStmt->execute($eParams);
    $eTotal  = (int)$eTotalStmt->fetchColumn();
    $ePages  = max(1, ceil($eTotal / $eventPer));
    $eOffset = ($eventPage - 1) * $eventPer;

    $eStmt = $pdo->prepare("
        SELECT e.*, COUNT(ev.id) AS vehicle_count,
               SUM(ev.is_hot) AS hot_count
        FROM auction_events e
        LEFT JOIN event_vehicles ev ON ev.event_id = e.id
        $eWhereSQL
        GROUP BY e.id
        ORDER BY e.event_date DESC
        LIMIT $eventPer OFFSET $eOffset
    ");
    $eStmt->execute($eParams);
    $adminEvents = $eStmt->fetchAll();

    // If viewing a specific event, load its vehicles
    $editEventId = (int)($_GET['edit_event'] ?? 0);
    if ($editEventId) {
        $stmt = $pdo->prepare("SELECT * FROM auction_events WHERE id=?");
        $stmt->execute([$editEventId]);
        $editEvent = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($editEvent) {
            $evStmt = $pdo->prepare("
                SELECT a.id, a.item_name, a.year, a.status, a.current_price, a.start_price,
                       a.start_time, a.end_time, a.image_url,
                       m.name AS make_name, ev.is_hot, ev.lot_number, ev.sort_order
                FROM event_vehicles ev
                JOIN auctions a ON a.id = ev.auction_id
                LEFT JOIN makes m ON m.id = a.make_id
                WHERE ev.event_id = ?
                ORDER BY ev.sort_order, a.item_name
            ");
            $evStmt->execute([$editEventId]);
            $editEventVehicles = $evStmt->fetchAll();
        }
    }
} catch (Exception $e) {
    error_log('[AHG] auction_events query failed: ' . $e->getMessage());
}

// All vehicles for the vehicle picker
$allVehicles = $pdo->query("
    SELECT a.id, a.item_name, a.year, a.image_url, a.status, a.current_price, a.start_price,
           m.name AS make_name
    FROM auctions a
    LEFT JOIN makes m ON m.id = a.make_id
    ORDER BY a.item_name
")->fetchAll();

// --- Import tab: last 10 import logs ---
$importLogs = [];
try {
    $importLogs = $pdo->query("
        SELECT ci.*, u.full_name AS admin_name
        FROM csv_import_log ci
        LEFT JOIN users u ON u.id = ci.admin_id
        ORDER BY ci.imported_at DESC LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {
    error_log('[AHG] csv_import_log query failed: ' . $e->getMessage());
}

// --- Testimonials tab ---
$adminTestimonials = [];
try {
    $adminTestimonials = $pdo->query("SELECT * FROM testimonials ORDER BY sort_order ASC, id DESC")->fetchAll();
} catch (Exception $e) { $adminTestimonials = []; }

// --- Team tab ---
$adminTeam = [];
try {
    // Auto-create table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        position VARCHAR(150) NOT NULL,
        bio TEXT DEFAULT NULL,
        email VARCHAR(200) DEFAULT NULL,
        phone VARCHAR(50) DEFAULT NULL,
        photo_url VARCHAR(500) DEFAULT NULL,
        cloudinary_public_id VARCHAR(300) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $adminTeam = $pdo->query("SELECT * FROM team_members ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (Exception $e) { $adminTeam = []; }

// --- News tab ---
$adminNewsPosts = []; $adminNewsCategories = []; $adminRssFeeds = [];
if ($activeTab === 'news') {
    try {
        $adminNewsCategories = $pdo->query("SELECT * FROM news_categories ORDER BY sort_order ASC")->fetchAll();
        $adminNewsPosts      = $pdo->query("SELECT p.*,c.name AS cat_name,c.color AS cat_color FROM news_posts p LEFT JOIN news_categories c ON p.category_id=c.id ORDER BY p.created_at DESC LIMIT 100")->fetchAll();
        $adminRssFeeds       = $pdo->query("SELECT f.*,c.name AS cat_name FROM rss_feeds f LEFT JOIN news_categories c ON f.category_id=c.id ORDER BY f.id ASC")->fetchAll();
    } catch (Exception $e) { /* tables may not exist yet — created on news.php load */ }
}

// --- Newsletter tab ---
$adminNlSubscribers = []; $adminNlCampaigns = [];
if ($activeTab === 'newsletter') {
    try {
        $adminNlSubscribers = $pdo->query("SELECT * FROM newsletter_subscribers ORDER BY subscribed_at DESC")->fetchAll();
        $adminNlCampaigns   = $pdo->query("SELECT nc.*,u.full_name AS author_name FROM newsletter_campaigns nc LEFT JOIN users u ON nc.created_by=u.id ORDER BY nc.created_at DESC")->fetchAll();
    } catch (Exception $e) { /* newsletter tables created on news.php load */ }
}

// --- Careers tab ---
$careerApplications = []; $careerStats = ['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0];
if ($activeTab === 'careers') {
    try {
        $careerApplications = $pdo->query("SELECT * FROM career_applications ORDER BY submitted_at DESC")->fetchAll();
        foreach ($careerApplications as $row) {
            $careerStats['total']++;
            $careerStats[$row['status']] = ($careerStats[$row['status']] ?? 0) + 1;
        }
    } catch (Exception $e) {}
}

// --- Workers tab ---
$adminWorkers = [];
if ($activeTab === 'workers') {
    try {
        $adminWorkers = $pdo->query("
            SELECT w.*,
                (SELECT COUNT(*) FROM worker_daily_logs dl WHERE dl.worker_id=w.id) AS total_days,
                (SELECT SUM(posts_count) FROM worker_daily_logs dl WHERE dl.worker_id=w.id AND MONTH(dl.log_date)=MONTH(NOW()) AND YEAR(dl.log_date)=YEAR(NOW())) AS month_posts,
                (SELECT SUM(leads_count) FROM worker_daily_logs dl WHERE dl.worker_id=w.id AND MONTH(dl.log_date)=MONTH(NOW()) AND YEAR(dl.log_date)=YEAR(NOW())) AS month_leads,
                (SELECT id           FROM worker_daily_logs dl WHERE dl.worker_id=w.id AND dl.log_date=CURDATE()) AS today_log_id,
                (SELECT proof_path   FROM worker_daily_logs dl WHERE dl.worker_id=w.id AND dl.log_date=CURDATE()) AS today_proof,
                (SELECT proof_paths  FROM worker_daily_logs dl WHERE dl.worker_id=w.id AND dl.log_date=CURDATE()) AS today_proof_paths,
                (SELECT status       FROM worker_daily_logs dl WHERE dl.worker_id=w.id AND dl.log_date=CURDATE()) AS today_status,
                (SELECT platforms    FROM worker_daily_logs dl WHERE dl.worker_id=w.id AND dl.log_date=CURDATE()) AS today_platforms,
                (SELECT posts_count  FROM worker_daily_logs dl WHERE dl.worker_id=w.id AND dl.log_date=CURDATE()) AS today_posts
            FROM workers w ORDER BY w.created_at DESC
        ")->fetchAll();

        // Load today's leads for all workers in one query
        $adminWorkerLeads = [];
        if ($adminWorkers) {
            $logIds = array_filter(array_column($adminWorkers, 'today_log_id'));
            if ($logIds) {
                $inSql = implode(',', array_fill(0, count($logIds), '?'));
                $wlStmt = $pdo->prepare("SELECT * FROM worker_leads WHERE log_id IN ($inSql) ORDER BY log_id, id");
                $wlStmt->execute(array_values($logIds));
                foreach ($wlStmt->fetchAll() as $wl) {
                    $adminWorkerLeads[$wl['log_id']][] = $wl;
                }
            }
        }
    } catch (Exception $e) { $adminWorkerLeads = []; }
}

// --- Notifications tab ---
$adminNotifications = [];
try {
    $adminNotifications = $pdo->query("SELECT * FROM popup_notifications ORDER BY id DESC")->fetchAll();
} catch (Exception $e) { $adminNotifications = []; }

// --- Banking detail requests (pending) ---
$bankRequests = [];
try {
    $bankRequests = $pdo->query("
        SELECT br.*, u.full_name, u.email, u.phone
        FROM bank_detail_requests br
        JOIN users u ON u.id = br.user_id
        ORDER BY br.status = 'pending' DESC, br.created_at DESC
        LIMIT 50
    ")->fetchAll();
} catch (Exception $e) { $bankRequests = []; }

// ── Page setup ────────────────────────────────────────────────
$pageTitle = 'Admin Dashboard — ' . APP_NAME;
$pageDescription = 'Auction Hub Group administration panel.';
$bodyClass = 'page-admin';   // lets CSS pin the admin mobile nav to the top
require_once 'header.php';
?>

<style>
.toggle-switch { position:relative;display:inline-block;width:44px;height:24px;cursor:pointer; }
.toggle-switch input { opacity:0;width:0;height:0; }
.toggle-slider { position:absolute;inset:0;background:#ccc;border-radius:24px;transition:.3s; }
.toggle-slider::before { content:'';position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s; }
.toggle-switch input:checked + .toggle-slider { background:#4caf50; }
.toggle-switch input:checked + .toggle-slider::before { transform:translateX(20px); }

/* HTML Email Editor */
.email-format-toggle { display:inline-flex;gap:2px;background:#eee;border-radius:6px;padding:2px; }
.email-fmt-btn { padding:.25rem .6rem !important;font-size:.75rem !important;border-radius:4px !important;border:none !important;background:transparent !important;color:#666 !important;cursor:pointer; }
.email-fmt-btn.active { background:#005BA1 !important;color:#fff !important; }
.html-toolbar { display:flex;flex-wrap:wrap;gap:4px;padding:.5rem;background:#f5f7fa;border:1px solid #ddd;border-bottom:none;border-radius:8px 8px 0 0;align-items:center; }
.html-toolbar button { background:#fff;border:1px solid #ddd;border-radius:4px;padding:.3rem .55rem;font-size:.78rem;cursor:pointer;color:#333;transition:background .2s; }
.html-toolbar button:hover { background:#e3f2fd;border-color:#005BA1; }
.html-toolbar + textarea { border-radius:0 0 8px 8px !important;font-family:'Courier New',monospace !important;font-size:.85rem;line-height:1.6; }
.html-preview { margin-top:.75rem;border:2px solid #005BA1;border-radius:10px;overflow:hidden; }
.html-preview-label { background:#005BA1;color:#fff;font-size:.78rem;font-weight:600;padding:.4rem .8rem; }
.html-preview-content { padding:1rem;background:#fff;max-height:400px;overflow:auto;font-size:.9rem;line-height:1.6; }
.html-preview-content img { max-width:100%;height:auto; }
.html-preview-content table { border-collapse:collapse;width:100%; }
.html-preview-content td,.html-preview-content th { border:1px solid #ddd;padding:.5rem; }
</style>

<?php
  // Admin menu, grouped. Both the desktop sidebar and the phone menu are
  // rendered from this one list. [tab or url, label, icon, badge count]
  $adminNav = [
    'Dashboard' => [
      ['overview',   'Overview',   'fa-th-large',   0],
      ['analytics',  'Analytics',  'fa-chart-line', 0],
    ],
    'Sales & Auctions' => [
      ['auctions',   'Auctions',              'fa-car',                0],
      ['events',     'Events',                'fa-calendar-alt',       0],
      ['manual_bid', 'Manual Bids & Floor',   'fa-gavel',              0],
      ['ai',         'AI Photos',             'fa-wand-magic-sparkles', 0],
      ['import',     'CSV Import',            'fa-file-csv',           0],
      ['url:manage_auction.php',                'Add Vehicle',  'fa-plus-circle', 0],
      ['url:invoice_generator.php?tab=builder', 'Deal Builder', 'fa-magic',       0],
    ],
    'Bidders & Payments' => [
      ['pop',        'POP Approvals',   'fa-file-invoice', (int)$stats['pending_pops']],
      ['users',      'Users',           'fa-users',        0],
      ['refunds',    'Refunds',         'fa-rotate-left',  (int)$stats['pending_refunds']],
      ['banking',    'Banking Details', 'fa-university',   0],
    ],
    'Marketing & Content' => [
      ['email',         'Email',             'fa-envelope',           0],
      ['newsletter',    'Newsletter',        'fa-envelope-open-text', 0],
      ['notifications', 'Notifications',     'fa-bell',               0],
      ['news',          'News &amp; Reviews', 'fa-newspaper',         0],
      ['testimonials',  'Testimonials',      'fa-star',               0],
      ['team',          'Meet the Team',     'fa-users',              0],
    ],
    'People' => [
      ['careers', 'Job Applications', 'fa-user-tie', 0],
      ['workers', 'Workers',          'fa-id-badge', 0],
    ],
  ];
  $tabLabels = [];
  foreach ($adminNav as $items) foreach ($items as [$t, $l]) if (strpos($t, 'url:') !== 0) $tabLabels[$t] = $l;
  /** One admin menu link (sidebar or phone menu) */
  $navLink = function (array $item, string $cls) use ($activeTab): string {
      [$t, $label, $icon, $badge] = $item;
      $isUrl = strpos($t, 'url:') === 0;
      $href  = $isUrl ? substr($t, 4) : '?tab=' . $t;
      $on    = !$isUrl && $activeTab === $t ? ' active' : '';
      $b     = $badge > 0 ? ' <span class="sidebar-badge sidebar-badge-danger">' . $badge . '</span>' : '';
      return '<a href="' . htmlspecialchars($href) . '" class="' . $cls . $on . '"><i class="fas ' . $icon . '"></i><span>' . $label . '</span>' . $b . '</a>';
  };
?>

<!-- ── Mobile admin nav (fixed, outside all wrappers, shown ≤900px) ── -->
<div class="admin-mobile-nav" id="adminMobileNav" aria-label="Admin navigation">
  <button type="button" class="admin-mnav-toggle" id="adminNavToggle"
          aria-haspopup="true" aria-expanded="false" aria-controls="adminNavPanel">
    <i class="fas fa-bars" aria-hidden="true"></i>
    <span class="admin-mnav-current"><?= $tabLabels[$activeTab] ?? 'Menu' ?></span>
    <span class="admin-mnav-badge-wrap">
      <?php if ($stats['pending_pops'] + $stats['pending_refunds'] > 0): ?>
        <span class="admin-mnav-badge"><?= $stats['pending_pops'] + $stats['pending_refunds'] ?></span>
      <?php endif; ?>
    </span>
    <i class="fas fa-chevron-down admin-mnav-arrow" aria-hidden="true"></i>
  </button>
  <nav class="admin-mnav-panel" id="adminNavPanel" hidden aria-label="Admin sections">
    <?php foreach ($adminNav as $group => $items): ?>
      <div class="admin-mnav-group"><?= e($group) ?></div>
      <?php foreach ($items as $item) echo $navLink($item, 'admin-mnav-link'); ?>
    <?php endforeach; ?>
    <div class="admin-mnav-group">Site</div>
    <a href="sold_vehicles.php"   class="admin-mnav-link" target="_blank"><i class="fas fa-tag"></i><span>Sold Vehicles</span></a>
    <a href="index.php"           class="admin-mnav-link" target="_blank"><i class="fas fa-external-link-alt"></i><span>View Site</span></a>
    <a href="#" class="admin-mnav-link admin-mnav-logout" onclick="openModal('logoutModal');return false;"><i class="fas fa-sign-out-alt"></i><span>Log Out</span></a>
  </nav>
</div>

<div class="admin-wrapper">

  <!-- ── Admin Sidebar wrapper (desktop only) ──────────────────── -->
  <div class="admin-sidebar-wrap">

  <!-- ── Admin Sidebar ───────────────────────────────────────── -->
  <aside class="admin-sidebar">
    <div class="admin-sidebar-brand">
      <i class="fas fa-shield-alt"></i>
      <span>Admin Panel</span>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($adminNav as $group => $items):
          $groupOn = in_array($activeTab, array_column($items, 0), true); ?>
        <div class="sidebar-group<?= $groupOn ? ' active' : '' ?>"><?= e($group) ?></div>
        <?php foreach ($items as $item) echo $navLink($item, 'sidebar-link'); ?>
      <?php endforeach; ?>
      <hr class="sidebar-divider">
      <a href="sold_vehicles.php" class="sidebar-link" target="_blank">
        <i class="fas fa-tag"></i> Sold Vehicles
      </a>
      <a href="index.php" class="sidebar-link" target="_blank">
        <i class="fas fa-external-link-alt"></i> View Site
      </a>
      <a href="#" class="sidebar-link sidebar-link-logout" id="logoutBtn" onclick="openModal('logoutModal');return false;">
        <i class="fas fa-sign-out-alt"></i> Log Out
      </a>
    </nav>
  </aside>

  </div><!-- /.admin-sidebar-wrap -->

  <!-- ── Admin Main ─────────────────────────────────────────── -->
  <main class="admin-main">

    <?php if ($flash['message']): ?>
    <div class="alert alert-<?= $flash['type']==='error' ? 'error' : 'success' ?> alert-dismissible">
      <?= e($flash['message']) ?>
      <button class="alert-close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>

    <div id="adminToast"></div>

<!-- ═══════════════════════════════════════════════════════════
     TAB: OVERVIEW
═══════════════════════════════════════════════════════════════ -->
<?php if ($activeTab === 'overview'): ?>

    <!-- ── System Status Banner ──────────────────────────────── -->
    <?php if (!EMAIL_ENABLED): ?>
    <div class="alert alert-error" style="margin-bottom:1rem;display:flex;align-items:center;gap:.75rem">
      <i class="fas fa-envelope-open-text" style="font-size:1.2rem"></i>
      <div>
        <strong>Email sending is disabled.</strong>
        Set <code>EMAIL_ENABLED = true</code> in config.php when your SMTP is ready.
        All approval, rejection and notification emails will be skipped until then.
      </div>
    </div>
    <?php endif; ?>

    <div class="dashboard-header">
      <div>
        <h1>Good <?= date('H')<12?'Morning':(date('H')<18?'Afternoon':'Evening') ?>, <?= e(explode(' ',$currentUser['full_name'])[0]) ?>!</h1>
        <p class="text-muted"><?= date('l, d F Y') ?></p>
      </div>
      <a href="manage_auction.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add Vehicle
      </a>
    </div>

    <!-- Stats Row 1: Auctions -->
    <div class="admin-stats-section">
      <h3 class="admin-section-label">Auctions</h3>
      <div class="grid-4">
        <div class="stat-card stat-card-blue">
          <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
          <div class="stat-value"><?= $stats['total_auctions'] ?></div>
          <div class="stat-label">Total Listings</div>
        </div>
        <div class="stat-card stat-card-green">
          <div class="stat-icon"><i class="fas fa-fire"></i></div>
          <div class="stat-value"><?= $stats['active_auctions'] ?></div>
          <div class="stat-label">Live Now</div>
        </div>
        <div class="stat-card stat-card-cyan">
          <div class="stat-icon"><i class="fas fa-clock"></i></div>
          <div class="stat-value"><?= $stats['upcoming_auctions'] ?></div>
          <div class="stat-label">Upcoming</div>
        </div>
        <div class="stat-card stat-card-grey">
          <div class="stat-icon"><i class="fas fa-flag-checkered"></i></div>
          <div class="stat-value"><?= $stats['closed_auctions'] ?></div>
          <div class="stat-label">Closed</div>
        </div>
      </div>
    </div>

    <!-- Sales summary -->
    <?php if ($sales): ?>
    <div class="admin-stats-section">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
        <h3 class="admin-section-label" style="margin:0;">Sales</h3>
        <form method="get" action="api.php" style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;font-size:.82rem;">
          <input type="hidden" name="action" value="export_auctions_csv">
          <input type="hidden" name="filter" value="sold">
          <label for="salesFrom" class="text-muted">Sales report</label>
          <input type="date" id="salesFrom" name="from" value="<?= e($sales['month_from']) ?>" class="form-control" style="width:auto;padding:.3rem .5rem;font-size:.82rem;">
          <span class="text-muted">to</span>
          <input type="date" name="to" value="<?= date('Y-m-d') ?>" class="form-control" style="width:auto;padding:.3rem .5rem;font-size:.82rem;">
          <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> Download CSV</button>
        </form>
      </div>
      <div class="grid-4" style="margin-top:.75rem;">
        <div class="stat-card">
          <div class="stat-icon" style="background:#fde8e8;color:#c8102e"><i class="fas fa-tag"></i></div>
          <div class="sales-stat-text">
            <div class="stat-value"><?= $sales['month_n'] ?></div>
            <div class="stat-label">Sold in <?= e($sales['month_label']) ?></div>
            <small class="text-muted"><?= $sales['last_n'] ?> last month</small>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#e8f5e9;color:#2e7d32"><i class="fas fa-sack-dollar"></i></div>
          <div class="sales-stat-text">
            <div class="stat-value" style="font-size:1.35rem;"><?= formatRand($sales['month_total']) ?></div>
            <div class="stat-label">Sales value this month</div>
            <?php if ($sales['last_total'] > 0):
                $chg = ($sales['month_total'] - $sales['last_total']) / $sales['last_total'] * 100; ?>
              <small style="color:<?= $chg >= 0 ? '#2e7d32' : '#c62828' ?>">
                <i class="fas fa-arrow-<?= $chg >= 0 ? 'up' : 'down' ?>"></i> <?= number_format(abs($chg), 0) ?>% vs <?= formatRand($sales['last_total']) ?> last month
              </small>
            <?php else: ?>
              <small class="text-muted">No sales recorded last month</small>
            <?php endif; ?>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:var(--blue-light);color:var(--blue-primary)"><i class="fas fa-scale-balanced"></i></div>
          <div class="sales-stat-text">
            <?php if ($sales['ratio'] !== null): ?>
              <div class="stat-value"><?= number_format($sales['ratio'] * 100, 0) ?>%</div>
              <div class="stat-label">Sold price vs starting price</div>
              <small class="text-muted">Avg <?= formatRand($sales['avg_price']) ?> · <?= $sales['ratio_n'] ?> sale<?= $sales['ratio_n'] === 1 ? '' : 's' ?>, last 90 days</small>
            <?php else: ?>
              <div class="stat-value">—</div>
              <div class="stat-label">Sold price vs starting price</div>
              <small class="text-muted">No priced sales in the last 90 days</small>
            <?php endif; ?>
          </div>
        </div>
        <div class="stat-card" style="<?= $sales['attention'] > 0 ? 'border-left:3px solid var(--warning)' : '' ?>">
          <div class="stat-icon" style="background:#fff8e1;color:#f57c00"><i class="fas fa-triangle-exclamation"></i></div>
          <div class="sales-stat-text">
            <div class="stat-value"><?= $sales['attention'] ?></div>
            <div class="stat-label">Lots needing a decision</div>
            <?php if ($sales['attention'] > 0): ?>
              <a href="?tab=auctions&amp;astatus=attention" class="stat-card-link">Sell or relist →</a>
            <?php else: ?>
              <small class="text-muted">Closed lots with bids are all handled</small>
            <?php endif; ?>
          </div>
        </div>
        <style>
          .sales-stat-text { display:flex; flex-direction:column; gap:.15rem; min-width:0; }
          .sales-stat-text small { font-size:.74rem; line-height:1.3; }
        </style>
      </div>
    </div>

    <!-- Change log -->
    <div class="card" style="margin-bottom:1.5rem;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem;">
        <h3 style="margin:0;"><i class="fas fa-clock-rotate-left" style="color:#1565c0;"></i> Change Log</h3>
        <?php if (!$activityMissing && $activityLimit === 15 && count($activityRows) >= 15): ?>
          <a href="?tab=overview&amp;log=200#activityTable" class="btn btn-outline btn-sm">Show last 200</a>
        <?php endif; ?>
      </div>
      <?php if ($activityMissing): ?>
        <div class="alert alert-error" style="margin:0;">
          <i class="fas fa-database"></i> The change log isn't set up on this server yet. Run <code>sql/ahg_admin_activity_log.sql</code> in phpMyAdmin.
          Until then nothing is logged, and Relist is disabled.
        </div>
      <?php elseif (!$activityRows): ?>
        <p class="text-muted" style="margin:0;">No changes logged yet. Sales, relists, status changes and deletes will appear here.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="admin-table" id="activityTable">
            <thead><tr><th>When</th><th>Admin</th><th>Lot</th><th>Change</th></tr></thead>
            <tbody>
              <?php foreach ($activityRows as $r): ?>
              <tr>
                <td style="white-space:nowrap;"><?= safeDate('d M Y H:i', $r['created_at']) ?></td>
                <td><?= e($r['admin_name'] ?: '—') ?></td>
                <td>
                  <?php if ($r['auction_id'] && $r['action'] === 'delete'): ?>
                    #<?= (int)$r['auction_id'] ?>
                    <small class="text-muted"><?= e($r['item_name']) ?></small>
                  <?php elseif ($r['auction_id']): ?>
                    <a href="auction.php?id=<?= (int)$r['auction_id'] ?>" target="_blank">#<?= (int)$r['auction_id'] ?></a>
                    <small class="text-muted"><?= e($r['item_name']) ?></small>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= e(describeAdminActivity($r['action'], $r['details'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Stats Row 2: Users -->
    <div class="admin-stats-section">
      <h3 class="admin-section-label">Users</h3>
      <div class="grid-4">
        <div class="stat-card">
          <div class="stat-icon" style="background:var(--blue-light);color:var(--blue-primary)"><i class="fas fa-users"></i></div>
          <div class="stat-value"><?= $stats['total_users'] ?></div>
          <div class="stat-label">Registered Users</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#e8f5e9;color:#388e3c"><i class="fas fa-id-badge"></i></div>
          <div class="stat-value"><?= $stats['verified_bidders'] ?></div>
          <div class="stat-label">Verified Bidders</div>
        </div>
        <div class="stat-card" style="<?= $stats['pending_pops']>0 ? 'border-left:3px solid var(--warning)' : '' ?>">
          <div class="stat-icon" style="background:#fff3e0;color:#f57c00"><i class="fas fa-hourglass-half"></i></div>
          <div class="stat-value"><?= $stats['pending_pops'] ?></div>
          <div class="stat-label">Pending POPs</div>
          <?php if ($stats['pending_pops']>0): ?>
            <a href="?tab=pop" class="stat-card-link">Review now →</a>
          <?php endif; ?>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fce4ec;color:#c62828"><i class="fas fa-ban"></i></div>
          <div class="stat-value"><?= $stats['suspended_users'] ?></div>
          <div class="stat-label">Suspended</div>
        </div>
      </div>
    </div>

    <!-- Stats Row 3: Bids & Revenue -->
    <div class="grid-2">
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--blue-light);color:var(--blue-primary)"><i class="fas fa-gavel"></i></div>
        <div class="stat-value"><?= number_format($stats['total_bids']) ?></div>
        <div class="stat-label">Total Bids Placed</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9;color:#388e3c"><i class="fas fa-rand-sign"></i></div>
        <div class="stat-value"><?= formatRand($stats['revenue']) ?></div>
        <div class="stat-label">Total Closed Revenue</div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="card" style="margin-top:1.5rem">
      <h3>Quick Actions</h3>
      <div class="quick-actions-grid">
        <a href="manage_auction.php" class="quick-action-btn">
          <i class="fas fa-plus-circle"></i><span>Add Vehicle</span>
        </a>
        <a href="?tab=pop" class="quick-action-btn <?= $stats['pending_pops']>0 ? 'quick-action-urgent':'' ?>">
          <i class="fas fa-file-invoice"></i>
          <span>POP Approvals <?= $stats['pending_pops']>0 ? "({$stats['pending_pops']})" : '' ?></span>
        </a>
        <button class="quick-action-btn" onclick="openModal('setAllTimesModal')">
          <i class="fas fa-clock"></i><span>Set Auction Times</span>
        </button>
        <a href="?tab=import" class="quick-action-btn">
          <i class="fas fa-file-csv"></i><span>CSV Import</span>
        </a>
        <a href="?tab=email" class="quick-action-btn">
          <i class="fas fa-envelope"></i><span>Send Email</span>
        </a>
        <a href="auctions.php" target="_blank" class="quick-action-btn">
          <i class="fas fa-external-link-alt"></i><span>View Live Site</span>
        </a>
      </div>
    </div>

    <!-- Next Auction & Recent Bids -->
    <div class="grid-2" style="align-items:start;margin-top:1.5rem">

      <?php if ($nextAuction): ?>
      <div class="card">
        <h3><i class="fas fa-calendar-alt"></i> Next Upcoming Auction</h3>
        <p><strong><?= e($nextAuction['item_name']) ?></strong></p>
        <?php
          $nStartTs = !empty($nextAuction['start_time']) ? strtotime($nextAuction['start_time']) : 0;
          $nStartFmt = $nStartTs > 0 ? date('d M Y \a\t H:i', $nStartTs) : 'Date TBC';
        ?>
        <p class="text-muted">Starts: <?= $nStartFmt ?></p>
        <div class="countdown-display" data-countdown="<?= e($nextAuction['start_time'] ?? '') ?>">
          <span>Loading...</span>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header flex-between">
          <h3><i class="fas fa-history"></i> Recent Bids</h3>
          <a href="?tab=auctions" class="btn btn-outline btn-sm">All Auctions</a>
        </div>
        <?php if ($recentBids): ?>
        <div class="recent-bids-list">
          <?php foreach ($recentBids as $rb): ?>
          <div class="recent-bid-item">
            <div class="recent-bid-meta">
              <strong><?= e($rb['full_name']) ?></strong>
              <?php if ($rb['bidder_number']): ?>
                <span class="badge badge-verified-bidder" style="font-size:.7rem"><?= e($rb['bidder_number']) ?></span>
              <?php endif; ?>
            </div>
            <div class="recent-bid-detail">
              <a href="auction.php?id=<?= $rb['auction_id'] ?>"><?= e($rb['item_name']) ?></a>
              — <strong><?= formatRand($rb['bid_amount']) ?></strong>
            </div>
            <small class="text-muted"><?= !empty($rb['bid_time']) ? safeDate('d M H:i', $rb['bid_time']) : '' ?></small>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted" style="padding:1rem">No bids yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Project To-Do Progress -->
    <div class="card" style="margin-top:1.5rem">
      <div class="card-header flex-between">
        <h3><i class="fas fa-tasks"></i> System Build Progress</h3>
        <span class="badge badge-active" style="font-size:.8rem">Live Tracker</span>
      </div>
      <div style="padding:.5rem 0">
        <?php
        $todos = [
            ['label' => 'Admin dashboard — vehicles, users, bids, bulk upload', 'done' => true],
            ['label' => 'User dashboard — bids, POP upload, profile', 'done' => true],
            ['label' => 'Bidding system — place bid, live updates, min increment', 'done' => true],
            ['label' => 'Login &amp; logout — JWT, session, confirmation modal', 'done' => true],
            ['label' => 'Registration — validation, duplicate check, welcome email', 'done' => true],
            ['label' => 'POP approval flow — admin approve/reject with bidder number', 'done' => true],
            ['label' => 'Winner notification + purchase request on auction close', 'done' => true],
            ['label' => 'Password reset (forgot password email flow)', 'done' => true],
            ['label' => 'Analytics tab — charts, KPIs, top bidders', 'done' => true],
            ['label' => 'Manual bid placement (admin bids on behalf of user)', 'done' => true],
            ['label' => 'Email system — SMTP via cPanel (enable EMAIL_ENABLED)', 'done' => false],
            ['label' => 'End-to-end testing — login, register, bid, approve POP', 'done' => false],
            ['label' => 'Automatic auction close cron job (winner notif on expiry)', 'done' => false],
            ['label' => 'Rate limiting on API bid polling endpoint', 'done' => false],
        ];
        $doneCount = count(array_filter($todos, fn($t) => $t['done']));
        $total = count($todos);
        $pct   = round($doneCount / $total * 100);
        ?>
        <div style="padding:.5rem 1.25rem">
          <div style="display:flex;justify-content:space-between;font-size:.88rem;margin-bottom:.4rem">
            <span class="text-muted"><?= $doneCount ?> of <?= $total ?> tasks complete</span>
            <strong><?= $pct ?>%</strong>
          </div>
          <div class="progress-bar-wrap" style="height:8px;border-radius:4px;background:#e0e0e0">
            <div class="progress-bar-fill" style="width:<?= $pct ?>%;background:var(--success,#2e7d32);border-radius:4px;height:100%;transition:width .4s"></div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.25rem .5rem;padding:.75rem 1.25rem">
          <?php foreach ($todos as $t): ?>
          <div style="display:flex;align-items:flex-start;gap:.5rem;font-size:.85rem;padding:.3rem 0">
            <i class="fas <?= $t['done'] ? 'fa-check-circle' : 'fa-circle' ?>"
               style="margin-top:2px;color:<?= $t['done'] ? 'var(--success,#2e7d32)' : '#bdbdbd' ?>;flex-shrink:0"></i>
            <span style="color:<?= $t['done'] ? 'inherit' : '#888' ?>"><?= $t['label'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>


<!-- ═══════════════════════════════════════════════════════════
     TAB: POP APPROVALS
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'pop'): ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-file-invoice"></i> POP Approvals</h1>
        <p class="text-muted"><?= count($pendingPOPs) ?> pending review<?= count($pendingPOPs)!==1?'s':'' ?></p>
      </div>
    </div>

    <?php if (empty($pendingPOPs)): ?>
    <div class="card empty-state">
      <i class="fas fa-check-circle fa-3x" style="color:var(--success)"></i>
      <h3>All Clear!</h3>
      <p class="text-muted">No pending proof of payment submissions.</p>
    </div>
    <?php else: ?>

    <div class="pop-approvals-grid">
    <?php foreach ($pendingPOPs as $p): ?>
    <div class="pop-card" id="pop-card-<?= $p['id'] ?>">
      <div class="pop-card-header">
        <div>
          <h3><?= e($p['full_name']) ?></h3>
          <small class="text-muted"><?= e($p['email']) ?> <?= $p['phone'] ? '· '.e($p['phone']) : '' ?></small>
        </div>
        <span class="badge badge-pending-approval">Pending</span>
      </div>

      <div class="pop-card-details">
        <?php if ($p['id_number']): ?>
        <div class="pop-detail-row">
          <span>ID Number</span><strong><?= e($p['id_number']) ?></strong>
        </div>
        <?php endif; ?>
        <div class="pop-detail-row">
          <span>Submitted</span>
          <strong><?= $p['pop_uploaded_at'] ? safeDate('d M Y H:i', $p['pop_uploaded_at']) : '—' ?></strong>
        </div>
        <?php if ($p['bank_reference']): ?>
        <div class="pop-detail-row">
          <span>EFT Reference</span><strong><?= e($p['bank_reference']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if ($p['payment_amount']): ?>
        <div class="pop-detail-row">
          <span>Amount</span><strong><?= formatRand($p['payment_amount']) ?></strong>
        </div>
        <?php endif; ?>
        <div class="pop-detail-row">
          <span>Expected Ref</span>
          <strong><?= eftReference($p['id']) ?></strong>
        </div>
      </div>

      <!-- POP File Preview -->
      <?php if ($p['pop_file_path']):
        $popUrl = 'serve_pop.php?file=' . urlencode(basename($p['pop_file_path']));
      ?>
      <div class="pop-file-preview">
        <?php if (str_starts_with($p['pop_file_type'] ?? '', 'image/')): ?>
          <img src="<?= e($popUrl) ?>" alt="Proof of payment"
               onclick="openImageLightbox('<?= e($popUrl) ?>')" style="cursor:zoom-in">
        <?php else: ?>
          <a href="<?= e($popUrl) ?>" target="_blank" class="pop-pdf-link">
            <i class="fas fa-file-pdf fa-3x"></i>
            <span>View PDF</span>
          </a>
        <?php endif; ?>
        <a href="<?= e($popUrl) ?>" target="_blank" class="btn btn-outline btn-sm" style="margin-top:.5rem">
          <i class="fas fa-external-link-alt"></i> Open Full Size
        </a>
      </div>
      <?php else: ?>
      <div class="pop-file-preview pop-file-missing">
        <i class="fas fa-file-slash fa-2x text-muted"></i>
        <p class="text-muted">No file uploaded</p>
      </div>
      <?php endif; ?>

      <!-- Approve / Reject Actions -->
      <div class="pop-card-actions">
        <div class="pop-approve-form">
          <label>Assign Bidder Number</label>
          <div class="pop-bidder-input-row">
            <input type="text"
                   id="bidderNum_<?= $p['id'] ?>"
                   class="form-control"
                   value="<?= e($nextBidderNum) ?>"
                   placeholder="<?= e($nextBidderNum) ?>">
            <button class="btn btn-success"
                    onclick="approvePOP(<?= $p['id'] ?>, '<?= e($p['full_name']) ?>')">
              <i class="fas fa-check"></i> Approve
            </button>
          </div>
          <small class="text-muted">Next suggested: <?= e($nextBidderNum) ?></small>
        </div>
        <button class="btn btn-danger btn-sm"
                onclick="openRejectModal(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['full_name']), ENT_QUOTES) ?>, <?= (int)($p['reg_id'] ?? 0) ?>)">
          <i class="fas fa-times"></i> Reject
        </button>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recently Approved -->
    <?php if (!empty($recentlyApproved)): ?>
    <div class="card" style="margin-top:2rem">
      <h3>Recently Approved</h3>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Name</th><th>Email</th><th>Bidder Number</th><th>Approved</th><th>By</th></tr></thead>
          <tbody>
          <?php foreach ($recentlyApproved as $a): ?>
          <tr>
            <td><?= e($a['full_name']) ?></td>
            <td><?= e($a['email']) ?></td>
            <td><span class="badge badge-verified-bidder"><?= e($a['bidder_number']) ?></span></td>
            <td><?= safeDate('d M Y H:i', $a['pop_approved_at']) ?></td>
            <td><?= e($a['approved_by_name'] ?? 'Admin') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════
     TAB: AUCTIONS
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'auctions'): ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-car"></i> Auction Management</h1>
        <p class="text-muted"><?= $aTotal ?> listing<?= $aTotal!==1?'s':'' ?> total</p>
      </div>
      <a href="manage_auction.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add Vehicle
      </a>
    </div>

    <!-- ── Inline Set All Times form ───────────────────────── -->
    <div class="card" style="margin-bottom:1.5rem;">
      <h3 style="margin:0 0 1rem;"><i class="fas fa-clock" style="color:#1565c0;"></i> Set Auction Times (Bulk)</h3>
      <p class="text-muted" style="margin:0 0 1rem;font-size:.88rem;">
        Update start and end times for multiple auctions in one go. Individual times can still be adjusted per-row below.
      </p>
      <div id="inlineSetAllTimesResult"></div>
      <form id="inlineSetAllTimesForm">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;">
          <div class="form-group" style="margin:0;">
            <label>Apply To</label>
            <select name="status_filter" class="form-control">
              <option value="upcoming">Upcoming Only</option>
              <option value="active">Active Only</option>
              <option value="all">All Auctions</option>
            </select>
          </div>
          <div class="form-group" style="margin:0;">
            <label>New Start Time</label>
            <input type="datetime-local" name="start_time" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
          </div>
          <div class="form-group" style="margin:0;">
            <label>New End Time</label>
            <input type="datetime-local" name="end_time" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime('+7 days')) ?>" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary" id="inlineSetAllTimesBtn" style="margin-top:.75rem;">
          <i class="fas fa-clock"></i> Update Times
        </button>
      </form>
    </div>

    <!-- ── Make All Vehicles Live ───────────────────────────── -->
    <div class="card" style="margin-bottom:1.5rem;border:2px solid #43a047;">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:.75rem;">
        <div>
          <h3 style="margin:0;color:#2e7d32;"><i class="fas fa-broadcast-tower" style="color:#43a047;"></i> Go Live — Publish All Vehicles</h3>
          <p class="text-muted" style="margin:.25rem 0 0;font-size:.88rem;">Set all vehicles to <strong>Live (active)</strong> instantly — visible and open for bidding on the website.</p>
        </div>
        <button type="button" class="btn btn-success" onclick="makeAllVehiclesLive()"
                style="background:#2e7d32;border-color:#2e7d32;font-size:1rem;padding:.6rem 1.4rem;">
          <i class="fas fa-broadcast-tower"></i> Make All Vehicles Live
        </button>
      </div>
      <div id="makeAllLiveResult"></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
        <div class="form-group" style="margin:0;">
          <label style="font-size:.83rem;">Apply To</label>
          <select id="makeAllLiveFilter" class="form-control">
            <option value="upcoming">Upcoming vehicles only</option>
            <option value="all">All non-closed vehicles</option>
          </select>
        </div>
        <div class="form-group" style="margin:0;">
          <label style="font-size:.83rem;">Override Start Time <small class="text-muted">(optional)</small></label>
          <input type="datetime-local" id="makeAllLiveStart" class="form-control">
        </div>
        <div class="form-group" style="margin:0;">
          <label style="font-size:.83rem;">Override End Time <small class="text-muted">(optional)</small></label>
          <input type="datetime-local" id="makeAllLiveEnd" class="form-control">
        </div>
      </div>
      <p class="text-muted" style="margin:.75rem 0 0;font-size:.8rem;">
        <i class="fas fa-info-circle"></i> Leave times blank to keep each vehicle's existing times. Sold vehicles are never affected.
      </p>
    </div>

    <!-- Bulk Action Bar (appears when checkboxes are selected) -->
    <div id="auctionBulkBar" style="display:none;flex-wrap:wrap;align-items:center;gap:.75rem;background:linear-gradient(135deg,#e3f2fd,#f3e5f5);border:1px solid #90caf9;border-radius:10px;padding:.75rem 1.25rem;margin-bottom:.75rem;">
      <span style="font-weight:700;color:#1565c0;font-size:.9rem;" id="auctionSelectedCount">0 selected</span>
      <button type="button" class="btn btn-primary btn-sm" onclick="toggleAuctionBulkPanel('times')">
        <i class="fas fa-clock"></i> Set Times &amp; Status
      </button>
      <button type="button" class="btn btn-outline btn-sm" onclick="toggleAuctionBulkPanel('event')" style="border-color:#6a1b9a;color:#6a1b9a;">
        <i class="fas fa-calendar-plus"></i> Add to Event
      </button>
      <button type="button" class="btn btn-sold btn-sm" onclick="toggleAuctionBulkPanel('sold')">
        <i class="fas fa-tag"></i> Mark Sold
      </button>
      <button type="button" class="btn btn-outline btn-sm" onclick="toggleAuctionBulkPanel('relist')" style="border-color:#2e7d32;color:#2e7d32;">
        <i class="fas fa-rotate-right"></i> Relist
      </button>
      <button type="button" class="btn btn-outline btn-sm" onclick="exportSelectedAuctions()">
        <i class="fas fa-file-csv"></i> Export CSV
      </button>
      <button type="button" class="btn btn-danger btn-sm" onclick="bulkDeleteAuctions()">
        <i class="fas fa-trash"></i> Delete
      </button>
    </div>

    <!-- Inline Bulk Mark Sold Panel -->
    <div id="auctionBulkSoldPanel" class="card" style="display:none;margin-bottom:1rem;border:2px solid #ef9a9a;">
      <h3 style="margin:0 0 .5rem;"><i class="fas fa-tag" style="color:#c8102e;"></i> Mark Selected Lots as Sold</h3>
      <p class="text-muted" style="margin:0 0 .75rem;font-size:.86rem;">
        Each price starts at the lot's top bid. Change any that sold for a different amount. Lots already sold are left out.
      </p>
      <div id="bulkSoldResult"></div>
      <div style="overflow-x:auto;">
        <table class="admin-table" style="min-width:420px;">
          <thead><tr><th>Lot</th><th>Vehicle</th><th style="width:170px;">Final price (R)</th></tr></thead>
          <tbody id="bulkSoldRows"></tbody>
        </table>
      </div>
      <p style="margin:.75rem 0 0;font-weight:700;color:#003f73;">Total: <span id="bulkSoldTotal">R0.00</span></p>
      <div class="form-group" style="margin:.75rem 0 0;max-width:280px;">
        <label>Sold at</label>
        <select id="bulkSoldChannel" class="form-control">
          <option value="">— Leave as is —</option>
          <?php foreach (SALE_CHANNELS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap;">
        <button type="button" class="btn btn-sold" onclick="submitBulkMarkSold()">
          <i class="fas fa-check"></i> Save Sales
        </button>
        <button type="button" class="btn btn-outline" onclick="toggleAuctionBulkPanel('sold', true)">Close</button>
      </div>
    </div>

    <!-- Inline Bulk Relist Panel -->
    <div id="auctionBulkRelistPanel" class="card" style="display:none;margin-bottom:1rem;border:2px solid #a5d6a7;">
      <h3 style="margin:0 0 .5rem;"><i class="fas fa-rotate-right" style="color:#2e7d32;"></i> Relist Unsold Lots</h3>
      <p class="text-muted" style="margin:0 0 .75rem;font-size:.86rem;">
        <span id="bulkRelistInfo"></span>
        Each lot goes back up for auction at its <strong>starting price</strong>. Its old bids are cleared from the lot
        (a copy is kept in the change log). Sold lots are skipped.
      </p>
      <div id="bulkRelistResult"></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
        <div class="form-group" style="margin:0;">
          <label>New Start Time</label>
          <input type="datetime-local" id="bulkRelistStart" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime('+1 day 09:00')) ?>">
        </div>
        <div class="form-group" style="margin:0;">
          <label>New End Time</label>
          <input type="datetime-local" id="bulkRelistEnd" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime('+8 days 18:00')) ?>">
        </div>
      </div>
      <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" style="background:#2e7d32;border-color:#2e7d32;" onclick="submitBulkRelist()">
          <i class="fas fa-rotate-right"></i> Relist Lots
        </button>
        <button type="button" class="btn btn-outline" onclick="toggleAuctionBulkPanel('relist', true)">Close</button>
      </div>
    </div>

    <!-- Inline Bulk Times & Status Panel -->
    <div id="auctionBulkTimesPanel" class="card" style="display:none;margin-bottom:1rem;border:2px solid #90caf9;">
      <h3 style="margin:0 0 .75rem;"><i class="fas fa-clock" style="color:#1565c0;"></i> Bulk Update Selected Auctions</h3>
      <p class="text-muted" style="margin:0 0 .75rem;"><span id="bulkAuctionCount">0</span> auction(s) selected. Leave fields empty to skip them.</p>
      <div id="bulkAuctionResult"></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
        <div class="form-group" style="margin:0;">
          <label>New Start Time</label>
          <input type="datetime-local" id="bulkAucStartTime" class="form-control">
        </div>
        <div class="form-group" style="margin:0;">
          <label>New End Time</label>
          <input type="datetime-local" id="bulkAucEndTime" class="form-control">
        </div>
        <div class="form-group" style="margin:0;">
          <label>Change Status</label>
          <select id="bulkAucStatus" class="form-control">
            <option value="">— Don't change —</option>
            <option value="upcoming">Upcoming</option>
            <option value="active">Live</option>
            <option value="closed">Closed</option>
            <option value="sold">Sold</option>
          </select>
        </div>
      </div>
      <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" onclick="submitBulkAuctionUpdate()">
          <i class="fas fa-check"></i> Apply Changes
        </button>
        <button type="button" class="btn btn-outline" onclick="toggleAuctionBulkPanel('times', true)">Close</button>
      </div>
    </div>

    <!-- Inline Bulk Add-to-Event Panel -->
    <div id="auctionBulkEventPanel" class="card" style="display:none;margin-bottom:1rem;border:2px solid #ce93d8;">
      <h3 style="margin:0 0 .75rem;"><i class="fas fa-calendar-plus" style="color:#6a1b9a;"></i> Add Selected Vehicles to an Event</h3>
      <p class="text-muted" id="bulkAssignInfo" style="margin:0 0 .75rem;">No vehicles selected.</p>
      <div id="bulkAssignResult"></div>
      <input type="hidden" id="assignEventAuctionIds" value="">
      <div class="form-group">
        <label>Select Event</label>
        <select id="assignEventSelect" class="form-control">
          <option value="">— Choose an Event —</option>
          <?php
          try { $evtListInline = $pdo->query("SELECT id, title, event_date, status FROM auction_events WHERE status IN ('draft','upcoming','live') ORDER BY event_date DESC")->fetchAll(); } catch(Exception $e){ $evtListInline=[]; }
          foreach ($evtListInline as $ev): ?>
            <option value="<?= $ev['id'] ?>"><?= e($ev['title']) ?> — <?= safeDate('d M Y', $ev['event_date']) ?> (<?= ucfirst($ev['status']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" onclick="submitAssignEvent()">
          <i class="fas fa-calendar-plus"></i> Add to Event
        </button>
        <button type="button" class="btn btn-outline" onclick="toggleAuctionBulkPanel('event', true)">Close</button>
      </div>
    </div>

    <!-- Search & Filter Bar -->
    <form class="admin-filter-bar" method="get">
      <input type="hidden" name="tab" value="auctions">
      <input type="hidden" name="astatus" value="<?= e($auctionStatus) ?>">
      <input type="text" name="asearch" value="<?= e($auctionSearch) ?>"
             placeholder="Search by vehicle, make, VIN or lot ID..." class="form-control" style="flex:1">
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
      <?php if ($auctionSearch || $auctionStatus): ?>
        <a href="?tab=auctions" class="btn btn-outline">Clear</a>
      <?php endif; ?>
    </form>

    <!-- Status tabs (counts follow the search box) -->
    <?php
    $aTabLabels = [
        ''          => ['All',             'fa-layer-group'],
        'active'    => ['Live',            'fa-fire'],
        'upcoming'  => ['Upcoming',        'fa-clock'],
        'closed'    => ['Closed (unsold)', 'fa-lock'],
        'sold'      => ['Sold',            'fa-circle-check'],
        'attention' => ['Needs attention', 'fa-triangle-exclamation'],
    ];
    // Builds a link to this tab keeping search/status; pass null to drop a key
    $aQs = function (array $over) use ($auctionSearch, $auctionStatus): string {
        $q = array_merge(['tab' => 'auctions', 'asearch' => $auctionSearch, 'astatus' => $auctionStatus], $over);
        $q = array_filter($q, fn($v) => $v !== '' && $v !== null && $v !== 1);
        return '?' . http_build_query($q);
    };
    ?>
    <nav class="av-tabs" aria-label="Filter lots by status">
      <?php foreach ($aTabLabels as $k => [$label, $icon]): ?>
        <a href="<?= e($aQs(['astatus' => $k, 'apage' => null])) ?>"
           class="av-tab <?= $auctionStatus === $k ? 'active' : '' ?> <?= $k === 'attention' && $aTabCounts[$k] > 0 ? 'av-tab-warn' : '' ?>">
          <i class="fas <?= $icon ?>"></i> <?= $label ?> <span class="av-tab-count"><?= $aTabCounts[$k] ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php
    // Sold tab: sold dates shared by 5+ lots were bulk-marked, not real sale days
    if ($auctionStatus === 'sold'):
        $bulkDays = $pdo->query("
            SELECT DATE(sold_date) AS d, COUNT(*) AS n FROM auctions
            WHERE is_sold = 1 AND sold_date IS NOT NULL
            GROUP BY DATE(sold_date) HAVING n >= 5 ORDER BY n DESC
        ")->fetchAll();
        $lastSpread = null;
        try {
            $row = $pdo->query("SELECT details, created_at FROM admin_activity_log WHERE action = 'spread_date' ORDER BY id DESC LIMIT 1")->fetch();
            if ($row) {
                $b = json_decode($row['details'], true)['batch'] ?? '';
                $undone = $pdo->prepare("SELECT 1 FROM admin_activity_log WHERE action = 'undo_spread' AND details LIKE ?");
                $undone->execute(['%"batch":"' . $b . '"%']);
                if ($b && !$undone->fetchColumn()) $lastSpread = ['batch' => $b, 'at' => $row['created_at']];
            }
        } catch (PDOException $e) { /* change log not created yet */ }
        if ($bulkDays || $lastSpread): ?>
    <div class="card spread-panel">
      <?php foreach ($bulkDays as $bd): ?>
        <div class="spread-row">
          <div>
            <strong><?= (int)$bd['n'] ?> sold lots share the date <?= safeDate('d M Y', $bd['d']) ?>.</strong>
            <small class="text-muted">That's the day they were bulk-marked sold, so the public sold page shows the same date on every car.
              Spreading gives each one a weekday date between when it was listed (at most 2 years back) and <?= safeDate('d M Y', $bd['d']) ?>.
              You can undo it, and still edit any single date with <em>Edit Sale</em>.</small>
          </div>
          <button type="button" class="btn btn-primary btn-sm" onclick="spreadSoldDates('<?= e($bd['d']) ?>', <?= (int)$bd['n'] ?>)">
            <i class="fas fa-calendar-days"></i> Spread these dates
          </button>
        </div>
      <?php endforeach; ?>
      <?php if ($lastSpread): ?>
        <div class="spread-row">
          <small class="text-muted">Dates were spread on <?= safeDate('d M Y H:i', $lastSpread['at']) ?>.</small>
          <button type="button" class="btn btn-outline btn-sm" onclick="undoSpreadDates('<?= e($lastSpread['batch']) ?>')">
            <i class="fas fa-rotate-left"></i> Undo spread
          </button>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; endif; ?>

    <?php if ($auctionStatus === 'attention'): ?>
    <div class="alert alert-info" style="margin-bottom:1rem;font-size:.86rem;">
      <i class="fas fa-info-circle"></i>
      These lots closed with bids but aren't marked sold (usually the reserve wasn't met). <strong>Mark Sold</strong> accepts a price,
      or select them and <strong>Relist</strong>. Sold lots with no final price also show here.
    </div>
    <?php endif; ?>

    <style>
    /* ── Auction vehicle cards (mobile-first) ────────────────── */
    .av-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:1rem; }
    .av-card { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.07); overflow:hidden; display:flex; flex-direction:column; }
    .av-card-img { height:160px; background:#e8edf4; position:relative; overflow:hidden; flex-shrink:0; }
    .av-card-img img { width:100%; height:100%; object-fit:cover; }
    .av-card-img .av-no-img { display:flex; align-items:center; justify-content:center; height:100%; color:#b0bcc8; font-size:2.5rem; }
    .av-card-body { padding:1rem; flex:1; display:flex; flex-direction:column; gap:.5rem; }
    .av-card-title { font-weight:700; color:#003f73; font-size:.95rem; line-height:1.3; }
    .av-card-meta { font-size:.78rem; color:#667; display:flex; flex-wrap:wrap; gap:.4rem; }
    .av-card-meta span { background:#f0f4f8; padding:.15rem .5rem; border-radius:4px; }
    .av-card-row { display:flex; justify-content:space-between; align-items:center; font-size:.82rem; }
    .av-card-row .lbl { color:#888; }
    .av-card-row .val { font-weight:700; color:#003f73; }
    .av-status-badge { position:absolute; top:.6rem; left:.6rem; font-size:.68rem; font-weight:700; padding:.25rem .65rem; border-radius:20px; text-transform:uppercase; letter-spacing:.04em; }
    .av-status-active   { background:#d4edda; color:#155724; }
    .av-status-upcoming { background:#fff3cd; color:#856404; }
    .av-status-closed   { background:#e2e3e5; color:#495057; }
    .av-status-sold     { background:#fde8e8; color:#7b1818; }
    .av-select-wrap { position:absolute; top:.5rem; right:.5rem; z-index:10; }
    .av-select-cb { width:18px; height:18px; cursor:pointer; accent-color:#1565c0; }
    .av-card-actions { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; margin-top:auto; padding-top:.75rem; border-top:1px solid #eef2f7; }
    .av-card-actions .btn { justify-content:center; font-size:.82rem; }
    .av-card-actions-extra { display:flex; gap:.5rem; flex-wrap:wrap; }
    .av-status-select { width:100%; font-size:.82rem; padding:.35rem .5rem; border:1.5px solid #d0dbe8; border-radius:8px; color:#003f73; font-weight:600; background:#f8fbff; }
    .av-count { font-size:.78rem; color:#888; margin-bottom:.75rem; }
    @media(max-width:480px) {
        .av-grid { grid-template-columns:1fr; }
    }
    .av-tabs { display:flex; gap:.4rem; flex-wrap:wrap; margin:0 0 1rem; }
    .av-tab { display:inline-flex; align-items:center; gap:.4rem; padding:.45rem .85rem; border-radius:20px; background:#fff; border:1.5px solid #d0dbe8; color:#003f73; font-size:.82rem; font-weight:600; text-decoration:none; }
    .av-tab:hover { border-color:#1565c0; }
    .av-tab.active { background:#1565c0; border-color:#1565c0; color:#fff; }
    .av-tab-count { background:rgba(0,0,0,.08); border-radius:10px; padding:0 .45rem; font-size:.74rem; }
    .av-tab.active .av-tab-count { background:rgba(255,255,255,.25); }
    .av-tab-warn:not(.active) { border-color:#f9a825; color:#8a5a00; background:#fff8e1; }
    .av-pagination { display:flex; justify-content:center; align-items:center; gap:.35rem; flex-wrap:wrap; padding:1.5rem 0; }
    .av-page-btn { min-width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; border-radius:8px; border:1px solid #d0dbe8; background:#fff; color:#003f73; font-weight:700; font-size:.85rem; text-decoration:none; padding:0 .5rem; }
    .av-page-btn.active, .av-page-btn:hover { background:#1565c0; border-color:#1565c0; color:#fff; }
    .av-page-gap { color:#99a; padding:0 .2rem; }
    .av-sold-row { background:#fde8e8; border-radius:8px; padding:.45rem .6rem; }
    .spread-panel { margin-bottom:1rem; border:1.5px solid #f9a825; background:#fffdf5; }
    .spread-row { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; padding:.35rem 0; }
    .spread-row small { display:block; margin-top:.2rem; max-width:720px; }
    .av-sold-row .val { color:#7b1818; }
    .av-sale-actions { display:flex; gap:.5rem; }
    .av-sale-actions .btn { flex:1; justify-content:center; font-size:.82rem; }
    .btn-sold { background:#c8102e; border-color:#c8102e; color:#fff; }
    .btn-sold:hover { background:#a50d26; border-color:#a50d26; color:#fff; }
    </style>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;flex-wrap:wrap;gap:.5rem;">
      <p class="av-count" style="margin:0;">
        <?= $aTotal ?> vehicle<?= $aTotal!==1?'s':'' ?><?= ($auctionSearch||$auctionStatus) ? ' (filtered)' : '' ?>
        <?php if ($aPages > 1): ?> · showing <?= $aOffset + 1 ?>–<?= min($aOffset + $auctionPer, $aTotal) ?><?php endif; ?>
      </p>
      <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <?php if (!$auctionSearch && $auctionStatus !== 'attention'): ?>
        <a href="api.php?action=export_auctions_csv&amp;filter=<?= e($auctionStatus ?: 'all') ?>" class="btn btn-outline btn-sm">
          <i class="fas fa-file-csv"></i> Export this list
        </a>
        <?php endif; ?>
        <label style="display:flex;align-items:center;gap:.5rem;font-size:.84rem;font-weight:600;color:#445;cursor:pointer;user-select:none;">
          <input type="checkbox" id="auctionSelectAll" onchange="toggleAuctionSelectAll(this)" style="width:16px;height:16px;accent-color:#1565c0;cursor:pointer;">
          Select all on this page
        </label>
      </div>
    </div>

    <?php if (empty($adminAuctions)): ?>
    <div class="card" style="text-align:center;padding:3rem;color:#888;">
        <i class="fas fa-car" style="font-size:2.5rem;margin-bottom:1rem;display:block;"></i>
        No vehicles found. <a href="manage_auction.php" class="text-primary">Add one now.</a>
    </div>
    <?php else: ?>
    <div class="av-grid">
      <?php foreach ($adminAuctions as $a): ?>
      <div class="av-card" id="auction-row-<?= $a['id'] ?>">

        <!-- Image + status badge -->
        <div class="av-card-img">
          <?php if ($a['image_url']): ?>
            <img src="<?= e(cldImg($a['image_url'], CLD_CARD)) ?>" alt="<?= e($a['item_name']) ?>" loading="lazy" onerror="this.parentElement.innerHTML='<div class=av-no-img><i class=\'fas fa-car\'></i></div>'">
          <?php else: ?>
            <div class="av-no-img"><i class="fas fa-car"></i></div>
          <?php endif; ?>
          <span class="av-status-badge av-status-<?= $a['is_sold'] ? 'sold' : $a['status'] ?>">
            <?= $a['is_sold'] ? 'Sold' : ($a['status'] === 'active' ? 'Live' : ucfirst($a['status'])) ?>
          </span>
          <div class="av-select-wrap">
            <input type="checkbox" class="av-select-cb auction-select-cb" value="<?= $a['id'] ?>" title="Select vehicle"
                   data-name="<?= e($a['item_name']) ?>"
                   data-top="<?= (float)$a['top_bid'] ?>"
                   data-sold="<?= (int)$a['is_sold'] ?>">
          </div>
          <?php if ($a['bid_count'] > 0): ?>
          <span style="position:absolute;top:.6rem;right:.6rem;background:rgba(0,0,0,.55);color:#fff;font-size:.65rem;padding:.2rem .5rem;border-radius:10px;">
            <i class="fas fa-gavel"></i> <?= $a['bid_count'] ?>
          </span>
          <?php endif; ?>
        </div>

        <div class="av-card-body">
          <!-- Title + meta -->
          <div>
            <div class="av-card-title"><?= e($a['item_name']) ?></div>
            <div class="av-card-meta">
              <?php if ($a['year']): ?><span><?= $a['year'] ?></span><?php endif; ?>
              <?php if ($a['make_name']): ?><span><?= e($a['make_name']) ?></span><?php endif; ?>
              <span>ID #<?= $a['id'] ?></span>
            </div>
          </div>

          <!-- Sale / price / reserve -->
          <?php if ($a['is_sold']): ?>
          <div class="av-card-row av-sold-row">
            <span class="lbl"><i class="fas fa-circle-check"></i> Sold<?= $a['sold_date'] ? ' ' . safeDate('d M Y', $a['sold_date']) : '' ?></span>
            <span class="val"><?= (float)$a['final_price'] > 0 ? formatRand($a['final_price']) : '<span style="color:#b26a00">No price!</span>' ?></span>
          </div>
          <?php if (!empty($a['sale_channel']) && isset(SALE_CHANNELS[$a['sale_channel']])): ?>
          <div class="av-card-row">
            <span class="lbl">Sold at</span>
            <span class="val" style="font-size:.8rem;"><?= e(SALE_CHANNELS[$a['sale_channel']]) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($a['sold_to']): ?>
          <div class="av-card-row">
            <span class="lbl">Buyer</span>
            <span class="val" style="font-size:.8rem;"><?= e($a['sold_to']) ?></span>
          </div>
          <?php endif; ?>
          <?php endif; ?>
          <div class="av-card-row">
            <span class="lbl"><?= (int)$a['bid_count'] > 0 ? 'Top Bid' : 'Current Price' ?></span>
            <span class="val"><?= formatRand((int)$a['bid_count'] > 0 ? $a['top_bid'] : $a['current_price']) ?></span>
          </div>
          <div class="av-card-row">
            <span class="lbl">Reserve</span>
            <span class="val" style="<?= ($a['current_price'] >= $a['reserve_price'] && $a['reserve_price'] > 0) ? 'color:#155724' : '' ?>">
              <?= formatRand($a['reserve_price']) ?>
              <?php if ($a['current_price'] >= $a['reserve_price'] && $a['reserve_price'] > 0): ?>
                <i class="fas fa-check-circle" style="font-size:.75rem;"></i>
              <?php endif; ?>
            </span>
          </div>

          <!-- End time -->
          <div class="av-card-row">
            <span class="lbl"><i class="fas fa-clock"></i> Ends</span>
            <span class="val" style="font-size:.8rem;" data-auction="<?= $a['id'] ?>">
              <?= $a['end_time'] ? safeDate('d M Y H:i', $a['end_time']) : '—' ?>
            </span>
          </div>

          <!-- Status dropdown -->
          <select class="av-status-select"
                  data-prev="<?= $a['is_sold'] ? 'sold' : e($a['status']) ?>"
                  onchange="onAuctionStatusChange(<?= $a['id'] ?>, this)">
            <option value="upcoming" <?= $a['status']==='upcoming'&&!$a['is_sold']?'selected':'' ?>>⏳ Upcoming</option>
            <option value="active"   <?= $a['status']==='active'  &&!$a['is_sold']?'selected':'' ?>>🟢 Live Now</option>
            <option value="closed"   <?= $a['status']==='closed'  &&!$a['is_sold']?'selected':'' ?>>🔒 Closed</option>
            <option value="sold"     <?= $a['is_sold'] ?'selected':'' ?>>🏷️ Sold</option>
          </select>

          <!-- Sale actions -->
          <?php $saleArgs = $a['id'] . ', ' . e(json_encode([
              'name'  => $a['item_name'],
              'top'   => (float)$a['top_bid'],
              'price' => (float)$a['final_price'],
              'date'  => $a['sold_date'] ? substr($a['sold_date'], 0, 10) : '',
              'buyer' => $a['sold_to'] ?? '',
              'sold'  => (int)$a['is_sold'],
              'channel' => $a['sale_channel'] ?? '',
          ], JSON_HEX_APOS | JSON_HEX_QUOT)); ?>
          <div class="av-sale-actions">
            <?php if ($a['is_sold']): ?>
              <button type="button" class="btn btn-outline btn-sm" onclick="openMarkSoldModal(<?= $saleArgs ?>)">
                <i class="fas fa-pen"></i> Edit Sale
              </button>
              <button type="button" class="btn btn-outline btn-sm" style="border-color:#7b1818;color:#7b1818;"
                      onclick="undoSold(<?= $a['id'] ?>, <?= e(json_encode($a['item_name'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)">
                <i class="fas fa-rotate-left"></i> Undo Sold
              </button>
            <?php else: ?>
              <button type="button" class="btn btn-sold btn-sm" onclick="openMarkSoldModal(<?= $saleArgs ?>)">
                <i class="fas fa-tag"></i> Mark as Sold
              </button>
            <?php endif; ?>
          </div>

          <!-- Primary actions -->
          <div class="av-card-actions">
            <a href="manage_auction.php?id=<?= $a['id'] ?>" class="btn btn-primary btn-sm">
              <i class="fas fa-edit"></i> Edit Vehicle
            </a>
            <a href="auction.php?id=<?= $a['id'] ?>" target="_blank" class="btn btn-outline btn-sm">
              <i class="fas fa-eye"></i> View
            </a>
          </div>

          <!-- Secondary actions -->
          <div class="av-card-actions-extra">
            <button class="btn btn-outline btn-sm" style="flex:1;border-color:#6a1b9a;color:#6a1b9a;"
                    onclick="openSetTimeModal(<?= $a['id'] ?>, '<?= e(addslashes($a['item_name'])) ?>', '<?= $a['start_time'] ?>', '<?= $a['end_time'] ?>')"
                    title="Set auction times">
              <i class="fas fa-clock"></i> Times
            </button>
            <button class="btn btn-outline btn-sm" style="flex:1;border-color:#1565c0;color:#1565c0;"
                    onclick="singleAssignToEvent(<?= $a['id'] ?>, '<?= e(addslashes($a['item_name'])) ?>')"
                    title="Add to event">
              <i class="fas fa-calendar-plus"></i> Event
            </button>
            <a class="btn btn-outline btn-sm" style="flex:1;border-color:#7b1fa2;color:#7b1fa2;"
               href="?tab=ai&amp;lot=<?= (int)$a['id'] ?>" title="Edit photos with AI">
              <i class="fas fa-wand-magic-sparkles"></i> AI
            </a>
            <?php if ($a['status'] === 'closed' || $a['bid_count'] > 0): ?>
            <button class="btn btn-outline btn-sm" style="flex:1;border-color:#2e7d32;color:#2e7d32;"
                    onclick="openAssignWinnerModal(<?= $a['id'] ?>, '<?= e(addslashes($a['item_name'])) ?>')"
                    title="Assign winner">
              <i class="fas fa-trophy"></i> Winner
            </button>
            <?php endif; ?>
            <button class="btn btn-danger btn-sm" style="flex:1;"
                    onclick="confirmDeleteAuction(<?= $a['id'] ?>, '<?= e(addslashes($a['item_name'])) ?>')"
                    title="Delete">
              <i class="fas fa-trash"></i> Delete
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($aPages > 1):
      $pgFrom = max(1, $auctionPage - 2);
      $pgTo   = min($aPages, $auctionPage + 2); ?>
    <nav class="av-pagination" aria-label="Lot pages">
      <?php if ($auctionPage > 1): ?>
        <a href="<?= e($aQs(['apage' => $auctionPage - 1])) ?>" class="av-page-btn" aria-label="Previous page"><i class="fas fa-chevron-left"></i></a>
      <?php endif; ?>
      <?php if ($pgFrom > 1): ?>
        <a href="<?= e($aQs(['apage' => 1])) ?>" class="av-page-btn">1</a>
        <?php if ($pgFrom > 2): ?><span class="av-page-gap">…</span><?php endif; ?>
      <?php endif; ?>
      <?php for ($p = $pgFrom; $p <= $pgTo; $p++): ?>
        <a href="<?= e($aQs(['apage' => $p])) ?>" class="av-page-btn <?= $p === $auctionPage ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($pgTo < $aPages): ?>
        <?php if ($pgTo < $aPages - 1): ?><span class="av-page-gap">…</span><?php endif; ?>
        <a href="<?= e($aQs(['apage' => $aPages])) ?>" class="av-page-btn"><?= $aPages ?></a>
      <?php endif; ?>
      <?php if ($auctionPage < $aPages): ?>
        <a href="<?= e($aQs(['apage' => $auctionPage + 1])) ?>" class="av-page-btn" aria-label="Next page"><i class="fas fa-chevron-right"></i></a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════
     TAB: USERS
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'users'): ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-users"></i> User Management</h1>
        <p class="text-muted"><?= $uTotal ?> user<?= $uTotal!==1?'s':'' ?> total</p>
      </div>
    </div>

    <!-- ── Inline Bulk Email ─────────────────────────────── -->
    <div class="card" style="margin-bottom:1.5rem;">
      <h3 style="margin:0 0 1rem;"><i class="fas fa-broadcast-tower" style="color:#1565c0;"></i> Bulk Email</h3>
      <p class="text-muted" style="margin:0 0 1rem;">Send an announcement to a group of users. For individual or templated emails use the <a href="?tab=email">Email Centre</a>.</p>
      <div id="inlineBulkEmailResult"></div>
      <form id="inlineBulkEmailForm">
        <div class="form-group">
          <label>Send To</label>
          <select name="group" class="form-control">
            <option value="all">All Users (<?= $stats['total_users'] ?>)</option>
            <option value="verified">Verified Bidders (<?= $stats['verified_bidders'] ?>)</option>
            <option value="pending">Pending Approval (<?= $stats['pending_pops'] ?>)</option>
            <option value="registered">Registered Only</option>
          </select>
        </div>
        <div class="form-group">
          <label>Subject</label>
          <input type="text" name="subject" class="form-control" placeholder="Email subject line..." required>
        </div>
        <div class="form-group">
          <label>Message</label>
          <textarea name="message" class="form-control" rows="5" placeholder="Write your message here..." required style="resize:vertical;"></textarea>
        </div>
        <div class="form-group">
          <label class="checkbox-label">
            <input type="checkbox" id="inlineBulkConfirm">
            I confirm I want to send this email to the selected group
          </label>
        </div>
        <button type="submit" class="btn btn-primary" id="inlineBulkEmailBtn" disabled>
          <i class="fas fa-broadcast-tower"></i> Send Bulk Email
        </button>
      </form>
    </div>

    <!-- Search & Filter Bar -->
    <form class="admin-filter-bar" method="get">
      <input type="hidden" name="tab" value="users">
      <input type="text" name="usearch" value="<?= e($userSearch) ?>"
             placeholder="Search by name, email, or bidder number..." class="form-control" style="flex:1">
      <select name="ustatus" class="form-control" style="width:190px">
        <option value="">All Statuses</option>
        <option value="registered"       <?= $userStatus==='registered'       ?'selected':'' ?>>Registered</option>
        <option value="pending_payment"  <?= $userStatus==='pending_payment'  ?'selected':'' ?>>Pending Payment</option>
        <option value="pending_approval" <?= $userStatus==='pending_approval' ?'selected':'' ?>>Pending Approval</option>
        <option value="verified_bidder"  <?= $userStatus==='verified_bidder'  ?'selected':'' ?>>Verified Bidder</option>
        <option value="suspended"        <?= $userStatus==='suspended'        ?'selected':'' ?>>Suspended</option>
      </select>
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
      <?php if ($userSearch || $userStatus): ?>
        <a href="?tab=users" class="btn btn-outline">Clear</a>
      <?php endif; ?>
    </form>

    <div class="card">
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email / Phone</th>
              <th>Bidder Status</th>
              <th>Bidder No.</th>
              <th>Bids</th>
              <th>Joined</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($adminUsers as $u): ?>
          <tr id="user-row-<?= $u['id'] ?>">
            <td>
              <strong><?= e($u['full_name']) ?></strong>
              <?php if ($u['status'] === 'suspended'): ?>
                <span class="badge badge-danger" style="font-size:.65rem">Suspended</span>
              <?php endif; ?>
            </td>
            <td>
              <div><?= e($u['email']) ?></div>
              <?php if ($u['phone']): ?>
                <small class="text-muted"><?= e($u['phone']) ?></small>
              <?php endif; ?>
            </td>
            <td>
              <?php
              $bsClass = match($u['bidder_status']) {
                  'verified_bidder'  => 'verified-bidder',
                  'pending_approval' => 'pending-approval',
                  'pending_payment'  => 'pending',
                  'suspended'        => 'danger',
                  default            => 'registered',
              };
              ?>
              <span class="badge badge-<?= $bsClass ?>">
                <?= ucwords(str_replace('_',' ',$u['bidder_status'])) ?>
              </span>
            </td>
            <td><?= $u['bidder_number'] ? '<span class="badge badge-verified-bidder">'.e($u['bidder_number']).'</span>' : '—' ?></td>
            <td><?= $u['bid_count'] ?></td>
            <td><?= safeDate('d M Y', $u['created_at']) ?></td>
            <td>
              <div class="table-actions">
                <?php if ($u['bidder_status'] === 'pending_approval'): ?>
                  <a href="?tab=pop" class="btn btn-outline btn-sm" title="Review POP">
                    <i class="fas fa-file-invoice"></i>
                  </a>
                <?php endif; ?>
                <button class="btn btn-outline btn-sm"
                        onclick="toggleUserPanel(<?= $u['id'] ?>, 'email')"
                        title="Send Email">
                  <i class="fas fa-envelope"></i>
                </button>
                <button class="btn btn-outline btn-sm"
                        onclick="sendBankDetails(<?= $u['id'] ?>, '<?= e(addslashes($u['full_name'])) ?>')"
                        title="Send Banking Details"
                        id="bank-btn-<?= $u['id'] ?>">
                  <i class="fas fa-university"></i>
                </button>
                <button class="btn btn-outline btn-sm"
                        onclick="toggleUserPanel(<?= $u['id'] ?>, 'reset')"
                        title="Reset Password">
                  <i class="fas fa-key"></i>
                </button>
                <?php if ($u['status'] === 'suspended'): ?>
                  <button class="btn btn-success btn-sm"
                          onclick="updateUserStatus(<?= $u['id'] ?>, 'active', '<?= e(addslashes($u['full_name'])) ?>')"
                          title="Activate">
                    <i class="fas fa-check"></i>
                  </button>
                <?php else: ?>
                  <button class="btn btn-warning btn-sm"
                          onclick="updateUserStatus(<?= $u['id'] ?>, 'suspended', '<?= e(addslashes($u['full_name'])) ?>')"
                          title="Suspend">
                    <i class="fas fa-ban"></i>
                  </button>
                <?php endif; ?>
                <button class="btn btn-danger btn-sm"
                        onclick="confirmDeleteUser(<?= $u['id'] ?>, '<?= e(addslashes($u['full_name'])) ?>')"
                        title="Delete">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </td>
          </tr>
          <tr id="user-email-row-<?= $u['id'] ?>" style="display:none;background:#f8fafc;">
            <td colspan="7" style="padding:1rem 1.25rem;">
              <form class="inline-user-email-form" data-user-id="<?= $u['id'] ?>">
                <h4 style="margin:0 0 .5rem;"><i class="fas fa-envelope" style="color:#1565c0;"></i> Send Email to <?= e($u['full_name']) ?> &lt;<?= e($u['email']) ?>&gt;</h4>
                <div class="inline-user-email-result"></div>
                <div class="form-group" style="margin-bottom:.5rem;">
                  <input type="text" name="subject" class="form-control" placeholder="Subject..." required>
                </div>
                <div class="form-group" style="margin-bottom:.5rem;">
                  <textarea name="message" class="form-control" rows="4" placeholder="Your message..." required style="resize:vertical;"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">
                  <i class="fas fa-paper-plane"></i> Send Email
                </button>
                <button type="button" class="btn btn-outline btn-sm" onclick="toggleUserPanel(<?= $u['id'] ?>, 'email')">Cancel</button>
              </form>
            </td>
          </tr>
          <tr id="user-reset-row-<?= $u['id'] ?>" style="display:none;background:#fff7e6;">
            <td colspan="7" style="padding:1rem 1.25rem;">
              <form class="inline-user-reset-form" data-user-id="<?= $u['id'] ?>" data-user-name="<?= e($u['full_name']) ?>">
                <h4 style="margin:0 0 .5rem;"><i class="fas fa-key" style="color:#f9a825;"></i> Reset Password for <?= e($u['full_name']) ?></h4>
                <p class="text-muted" style="margin:0 0 .5rem;">A new temporary password will be emailed to <strong><?= e($u['email']) ?></strong>.</p>
                <div class="inline-user-reset-result"></div>
                <div style="display:flex;gap:.5rem;align-items:center;">
                  <label class="checkbox-label" style="margin:0;">
                    <input type="checkbox" name="send_email_notice" checked> Email the new password to the user
                  </label>
                </div>
                <div style="margin-top:.5rem;">
                  <button type="submit" class="btn btn-warning btn-sm">
                    <i class="fas fa-key"></i> Reset Password
                  </button>
                  <button type="button" class="btn btn-outline btn-sm" onclick="toggleUserPanel(<?= $u['id'] ?>, 'reset')">Cancel</button>
                </div>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($adminUsers)): ?>
          <tr><td colspan="7" class="text-center text-muted" style="padding:2rem">No users found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($uPages > 1): ?>
    <div class="pagination">
      <?php for ($p = 1; $p <= $uPages; $p++): ?>
        <a href="?tab=users&upage=<?= $p ?>&usearch=<?= urlencode($userSearch) ?>&ustatus=<?= urlencode($userStatus) ?>"
           class="page-btn <?= $p === $userPage ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════
     TAB: EMAIL
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'email'): ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-envelope"></i> Email Centre</h1>
        <p class="text-muted">Send individual or bulk emails to your users.</p>
      </div>
    </div>

    <div class="grid-2" style="align-items:start">

      <!-- Single Email -->
      <div class="card">
        <h3>Send to Individual User</h3>
        <div id="singleEmailResult"></div>
        <form id="singleEmailForm">
          <input type="hidden" name="action" value="send_email">
          <input type="hidden" name="format" id="singleEmailFormat" value="plain">
          <div class="form-group">
            <label>Find User</label>
            <input type="text" id="userSearchInput" class="form-control"
                   placeholder="Type name or email..." autocomplete="off">
            <div id="userSearchDropdown" class="search-dropdown" style="display:none"></div>
            <input type="hidden" name="user_id" id="selectedUserId">
            <div id="selectedUserDisplay" style="display:none;margin-top:.5rem" class="selected-user-chip">
              <i class="fas fa-user"></i>
              <span id="selectedUserName"></span>
              <button type="button" onclick="clearSelectedUser()" class="chip-remove">×</button>
            </div>
          </div>
          <div class="form-group">
            <label>Subject</label>
            <input type="text" name="subject" class="form-control"
                   placeholder="Email subject line..." required>
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;justify-content:space-between;">
              Message
              <span class="email-format-toggle">
                <button type="button" class="btn btn-sm btn-outline email-fmt-btn active" data-target="single" data-format="plain" onclick="setEmailFormat('single','plain')">
                  <i class="fas fa-align-left"></i> Plain Text
                </button>
                <button type="button" class="btn btn-sm btn-outline email-fmt-btn" data-target="single" data-format="html" onclick="setEmailFormat('single','html')">
                  <i class="fas fa-code"></i> HTML
                </button>
              </span>
            </label>
            <div id="singleHtmlToolbar" class="html-toolbar" style="display:none;">
              <button type="button" onclick="insertHtmlTag('singleMsg','h2')" title="Heading"><i class="fas fa-heading"></i></button>
              <button type="button" onclick="insertHtmlTag('singleMsg','strong')" title="Bold"><i class="fas fa-bold"></i></button>
              <button type="button" onclick="insertHtmlTag('singleMsg','em')" title="Italic"><i class="fas fa-italic"></i></button>
              <button type="button" onclick="insertHtmlTag('singleMsg','u')" title="Underline"><i class="fas fa-underline"></i></button>
              <button type="button" onclick="insertHtmlTag('singleMsg','p')" title="Paragraph"><i class="fas fa-paragraph"></i></button>
              <button type="button" onclick="insertHtmlTag('singleMsg','br',true)" title="Line Break">BR</button>
              <button type="button" onclick="insertHtmlTag('singleMsg','hr',true)" title="Horizontal Rule">HR</button>
              <button type="button" onclick="insertHtmlLink('singleMsg')" title="Link"><i class="fas fa-link"></i></button>
              <button type="button" onclick="insertHtmlImg('singleMsg')" title="Image"><i class="fas fa-image"></i></button>
              <button type="button" onclick="insertHtmlTag('singleMsg','table')" title="Table"><i class="fas fa-table"></i></button>
              <button type="button" onclick="toggleHtmlPreview('single')" title="Preview" style="margin-left:auto;background:#005BA1;color:#fff;">
                <i class="fas fa-eye"></i> Preview
              </button>
            </div>
            <textarea name="message" id="singleMsg" class="form-control" rows="10"
                      placeholder="Write your message here..." required style="resize:vertical;font-family:inherit;"
                      oninput="updateHtmlPreview('single')"></textarea>
            <div id="singleHtmlPreview" class="html-preview" style="display:none;">
              <div class="html-preview-label"><i class="fas fa-eye"></i> Email Preview</div>
              <div id="singlePreviewContent" class="html-preview-content"></div>
            </div>
          </div>
          <button type="submit" class="btn btn-primary" id="sendSingleBtn">
            <i class="fas fa-paper-plane"></i> Send Email
          </button>
        </form>
      </div>

      <!-- Bulk Email -->
      <div class="card">
        <h3>Bulk Email</h3>
        <div id="bulkEmailResult"></div>
        <form id="bulkEmailForm">
          <input type="hidden" name="action" value="send_bulk_email">
          <input type="hidden" name="format" id="bulkEmailFormat" value="plain">
          <div class="form-group">
            <label>Send To</label>
            <select name="group" class="form-control" id="bulkGroup" onchange="updateBulkCount(this.value)">
              <option value="all">All Users (<?= $stats['total_users'] ?>)</option>
              <option value="verified">Verified Bidders (<?= $stats['verified_bidders'] ?>)</option>
              <option value="pending">Pending Approval (<?= $stats['pending_pops'] ?>)</option>
              <option value="registered">Registered Only</option>
            </select>
            <small id="bulkCountHint" class="text-muted"></small>
          </div>
          <div class="form-group">
            <label>Subject</label>
            <input type="text" name="subject" class="form-control"
                   placeholder="Email subject line..." required>
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;justify-content:space-between;">
              Message
              <span class="email-format-toggle">
                <button type="button" class="btn btn-sm btn-outline email-fmt-btn active" data-target="bulk" data-format="plain" onclick="setEmailFormat('bulk','plain')">
                  <i class="fas fa-align-left"></i> Plain Text
                </button>
                <button type="button" class="btn btn-sm btn-outline email-fmt-btn" data-target="bulk" data-format="html" onclick="setEmailFormat('bulk','html')">
                  <i class="fas fa-code"></i> HTML
                </button>
              </span>
            </label>
            <div id="bulkHtmlToolbar" class="html-toolbar" style="display:none;">
              <button type="button" onclick="insertHtmlTag('bulkMsg','h2')" title="Heading"><i class="fas fa-heading"></i></button>
              <button type="button" onclick="insertHtmlTag('bulkMsg','strong')" title="Bold"><i class="fas fa-bold"></i></button>
              <button type="button" onclick="insertHtmlTag('bulkMsg','em')" title="Italic"><i class="fas fa-italic"></i></button>
              <button type="button" onclick="insertHtmlTag('bulkMsg','u')" title="Underline"><i class="fas fa-underline"></i></button>
              <button type="button" onclick="insertHtmlTag('bulkMsg','p')" title="Paragraph"><i class="fas fa-paragraph"></i></button>
              <button type="button" onclick="insertHtmlTag('bulkMsg','br',true)" title="Line Break">BR</button>
              <button type="button" onclick="insertHtmlTag('bulkMsg','hr',true)" title="Horizontal Rule">HR</button>
              <button type="button" onclick="insertHtmlLink('bulkMsg')" title="Link"><i class="fas fa-link"></i></button>
              <button type="button" onclick="insertHtmlImg('bulkMsg')" title="Image"><i class="fas fa-image"></i></button>
              <button type="button" onclick="insertHtmlTag('bulkMsg','table')" title="Table"><i class="fas fa-table"></i></button>
              <button type="button" onclick="toggleHtmlPreview('bulk')" title="Preview" style="margin-left:auto;background:#005BA1;color:#fff;">
                <i class="fas fa-eye"></i> Preview
              </button>
            </div>
            <textarea name="message" id="bulkMsg" class="form-control" rows="10"
                      placeholder="Write your message here..." required style="resize:vertical;font-family:inherit;"
                      oninput="updateHtmlPreview('bulk')"></textarea>
            <div id="bulkHtmlPreview" class="html-preview" style="display:none;">
              <div class="html-preview-label"><i class="fas fa-eye"></i> Email Preview</div>
              <div id="bulkPreviewContent" class="html-preview-content"></div>
            </div>
          </div>
          <div class="form-group">
            <label class="checkbox-label">
              <input type="checkbox" id="bulkConfirm">
              I confirm I want to send this email to the selected group
            </label>
          </div>
          <button type="submit" class="btn btn-primary" id="sendBulkBtn" disabled>
            <i class="fas fa-broadcast-tower"></i> Send Bulk Email
          </button>
        </form>
      </div>
    </div>

    <!-- HTML Email Templates -->
    <div class="card email-templates-card" style="margin-top:1.5rem;border-top:4px solid #005BA1;">
      <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.25rem;">
        <h3 style="margin:0;color:#003f73;"><i class="fas fa-palette" style="color:#005BA1;"></i> Quick HTML Email Templates</h3>
      </div>
      <p class="text-muted" style="margin-bottom:1rem;">Click a template to load it into either email form. Switch to HTML mode first.</p>
      <div class="email-templates-grid">
        <button type="button" class="email-template-tile" onclick="loadEmailTemplate('auction_announcement')">
          <span class="etpl-icon" style="background:#e8f0fa;color:#005BA1;"><i class="fas fa-gavel"></i></span>
          <span class="etpl-text">
            <strong>Auction Announcement</strong>
            <small>New auction starting — vehicles, dates, CTA</small>
          </span>
        </button>
        <button type="button" class="email-template-tile" onclick="loadEmailTemplate('event_invite')">
          <span class="etpl-icon" style="background:#e6f7ee;color:#25a35a;"><i class="fas fa-calendar"></i></span>
          <span class="etpl-text">
            <strong>Event Invitation</strong>
            <small>Invite users to an upcoming event</small>
          </span>
        </button>
        <button type="button" class="email-template-tile" onclick="loadEmailTemplate('payment_reminder')">
          <span class="etpl-icon" style="background:#fff5e0;color:#c97a00;"><i class="fas fa-credit-card"></i></span>
          <span class="etpl-text">
            <strong>Payment Reminder</strong>
            <small>Remind users about pending payments</small>
          </span>
        </button>
        <button type="button" class="email-template-tile" onclick="loadEmailTemplate('general_update')">
          <span class="etpl-icon" style="background:#fde6e6;color:#c22;"><i class="fas fa-bullhorn"></i></span>
          <span class="etpl-text">
            <strong>General Update</strong>
            <small>General news / platform updates</small>
          </span>
        </button>
      </div>
    </div>

    <!-- ── INVOICE BUILDER ────────────────────────────────────── -->
    <div class="card" style="margin-top:1.5rem;border-top:4px solid #005BA1;">
      <div style="display:flex;align-items:center;gap:1rem;margin-bottom:.5rem;flex-wrap:wrap;">
        <img src="https://auctionhubgroup.co.za/logo.png" alt="AHG" style="height:46px;width:auto;">
        <h3 style="margin:0;color:#003f73;"><i class="fas fa-file-invoice-dollar" style="color:#005BA1;"></i> Invoice Email Builder</h3>
      </div>
      <p class="text-muted" style="margin-bottom:1rem;">
        Fill in the invoice details, choose Winner or Pre-Auction, and the HTML invoice is loaded into the Single Email form (switches to HTML mode automatically). Then pick a recipient and send. A PDF copy can be downloaded and will auto-attach when sending.
      </p>

      <!-- Vehicle loader row -->
      <div style="background:#f2f7fc;border:1px solid #cfe0f0;border-radius:10px;padding:1rem;margin-bottom:1rem;">
        <label style="display:block;font-weight:700;color:#003f73;margin-bottom:.4rem;font-size:.9rem;">
          <i class="fas fa-car"></i> Load Vehicle Details (optional — adds image + specs to invoice)
        </label>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
          <input type="text" id="invVehicleSearch" class="form-control" placeholder="Search by make or model…" style="flex:1;min-width:220px;">
          <button type="button" class="btn btn-outline" onclick="searchVehicleForInvoice()">
            <i class="fas fa-search"></i> Search
          </button>
          <button type="button" class="btn btn-outline" onclick="clearInvoiceVehicle()" id="invVehicleClearBtn" style="display:none;">
            <i class="fas fa-times"></i> Clear
          </button>
        </div>
        <div id="invVehicleResults" style="margin-top:.75rem;display:none;max-height:240px;overflow-y:auto;"></div>
        <div id="invVehicleLoaded" style="margin-top:.75rem;display:none;padding:.75rem;background:#e8f3ff;border:1px solid #0078d7;border-radius:8px;font-size:.88rem;"></div>
        <input type="hidden" id="invVehicleDetailsJson">
      </div>

      <div class="invoice-builder-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
        <div class="form-group" style="margin:0;">
          <label>Bidder Name</label>
          <input type="text" id="invBidderName" class="form-control" placeholder="e.g. Jean Van Wy">
        </div>
        <div class="form-group" style="margin:0;">
          <label>Vehicle (Year + Make + Model)</label>
          <input type="text" id="invVehicle" class="form-control" placeholder="e.g. 2022 Toyota Hilux 2.4 GD-6">
        </div>
        <div class="form-group" style="margin:0;">
          <label>Invoice / Reference #</label>
          <input type="text" id="invNumber" class="form-control" placeholder="e.g. 56145">
        </div>
        <div class="form-group" style="margin:0;">
          <label>Accessories (optional)</label>
          <input type="text" id="invAccessories" class="form-control" placeholder="e.g. Spare Wheel, Key">
        </div>
        <div class="form-group" style="margin:0;">
          <label>Sales Price (R)</label>
          <input type="number" id="invSalesPrice" class="form-control" step="0.01" placeholder="50000">
        </div>
        <div class="form-group" style="margin:0;">
          <label>Buyer's Premium (R)</label>
          <input type="number" id="invPremium" class="form-control" step="0.01" placeholder="3100">
        </div>
        <div class="form-group" style="margin:0;">
          <label>Documentation Fee (R)</label>
          <input type="number" id="invDocFee" class="form-control" step="0.01" placeholder="2600">
        </div>
        <div class="form-group" style="margin:0;">
          <label>VAT (R)</label>
          <input type="number" id="invVat" class="form-control" step="0.01" placeholder="10500">
        </div>
      </div>

      <div style="margin-top:1rem;padding:.85rem 1rem;background:#f2f7fc;border:1px solid #cfe0f0;border-radius:8px;font-size:.9rem;">
        <strong style="color:#003f73;">Total Due:</strong>
        <span id="invTotalPreview" style="color:#005BA1;font-weight:800;font-size:1.1rem;margin-left:.5rem;">R 0.00</span>
        <span class="text-muted" style="margin-left:.75rem;font-size:.78rem;">(auto-calculated)</span>
      </div>

      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem;">
        <button type="button" class="btn btn-primary" onclick="buildInvoiceTemplate('winner')" style="background:#005BA1;border-color:#005BA1;">
          <i class="fas fa-trophy"></i> Load Winner Invoice
        </button>
        <button type="button" class="btn btn-primary" onclick="buildInvoiceTemplate('preauction')" style="background:#003f73;border-color:#003f73;">
          <i class="fas fa-clipboard-list"></i> Load Pre-Auction Invoice
        </button>
        <button type="button" class="btn btn-outline" onclick="previewInvoiceTemplate()" style="border-color:#005BA1;color:#005BA1;">
          <i class="fas fa-eye"></i> Preview in New Tab
        </button>
        <button type="button" class="btn btn-outline" onclick="downloadInvoicePdf('winner')" style="border-color:#003f73;color:#003f73;">
          <i class="fas fa-file-pdf"></i> Download Winner PDF
        </button>
      </div>
      <p class="text-muted" style="font-size:.78rem;margin-top:.5rem;">
        <i class="fas fa-info-circle"></i> Winner invoices auto-generate a PDF that attaches on send. For pre-auction quotations, attach your own PDF manually via the email form's attachment field if needed.
      </p>
    </div>


<!-- ═══════════════════════════════════════════════════════════
     TAB: CSV IMPORT
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'import'): ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-file-csv"></i> Bulk CSV Import</h1>
        <p class="text-muted">Import multiple vehicles at once from a spreadsheet.</p>
      </div>
    </div>

    <div class="grid-2" style="align-items:start">

      <!-- Import Form -->
      <div class="card">
        <h3>Upload CSV File</h3>
        <div id="importResult"></div>
        <form id="importForm" enctype="multipart/form-data">
          <input type="hidden" name="action" value="import_csv">

          <div class="upload-drop-zone" id="csvDropZone" style="position:relative">
            <div class="upload-drop-icon"><i class="fas fa-file-csv" style="color:var(--success)"></i></div>
            <div class="upload-drop-text">
              <strong>Drag &amp; drop your CSV file here</strong><br>
              <span class="text-muted">or click to browse</span>
            </div>
            <div class="upload-drop-hint">CSV files only — UTF-8 encoded</div>
            <input type="file" id="csvFile" name="csv_file" accept=".csv"
                   style="position:absolute;opacity:0;inset:0;cursor:pointer">
          </div>

          <div id="csvPreviewInfo" style="display:none;margin-top:1rem" class="alert alert-info">
            <i class="fas fa-table"></i> <span id="csvRowCount"></span> rows detected — ready to import.
          </div>

          <div class="form-group" style="margin-top:1rem">
            <label>Default Auction Status</label>
            <select name="default_status" class="form-control">
              <option value="upcoming">Upcoming</option>
              <option value="active">Active (Live immediately)</option>
            </select>
          </div>
          <div class="form-group">
            <label>Default Start Time (if not in CSV)</label>
            <input type="datetime-local" name="default_start" class="form-control"
                   value="<?= date('Y-m-d\TH:i') ?>">
          </div>
          <div class="form-group">
            <label>Default End Time (if not in CSV)</label>
            <input type="datetime-local" name="default_end" class="form-control"
                   value="<?= date('Y-m-d\TH:i', strtotime('+7 days')) ?>">
          </div>

          <!-- Progress Bar -->
          <div id="importProgress" style="display:none;margin-top:1rem">
            <div class="progress-bar-wrap">
              <div class="progress-bar-fill" id="importProgressFill" style="width:0%"></div>
            </div>
            <small id="importProgressLabel" class="text-muted">Processing...</small>
          </div>

          <button type="submit" class="btn btn-primary" id="importSubmitBtn" disabled>
            <i class="fas fa-upload"></i> Import Vehicles
          </button>
        </form>
      </div>

      <!-- CSV Format Guide -->
      <div class="card">
        <h3>CSV Column Format</h3>
        <p class="text-muted" style="font-size:.85rem">Your CSV must use these column headers in this order. The first row must be the header row.</p>
        <div class="csv-guide-table">
          <table class="table table-sm">
            <thead><tr><th>Column</th><th>Required</th><th>Notes</th></tr></thead>
            <tbody>
              <tr><td><code>item_name</code></td><td><span class="badge badge-danger">Yes</span></td><td>Full vehicle name, e.g. "2020 Toyota Hilux"</td></tr>
              <tr><td><code>make_name</code></td><td><span class="badge badge-danger">Yes</span></td><td>Must match a make in the DB, e.g. "Toyota"</td></tr>
              <tr><td><code>year</code></td><td>No</td><td>4-digit year, e.g. 2020</td></tr>
              <tr><td><code>mileage</code></td><td>No</td><td>Number in km, e.g. 85000</td></tr>
              <tr><td><code>fuel_type</code></td><td>No</td><td>Petrol / Diesel / Electric / Hybrid</td></tr>
              <tr><td><code>transmission</code></td><td>No</td><td>Manual / Automatic</td></tr>
              <tr><td><code>color</code></td><td>No</td><td>e.g. "Silver"</td></tr>
              <tr><td><code>body_type</code></td><td>No</td><td>Sedan / SUV / Bakkie / Hatchback…</td></tr>
              <tr><td><code>engine_size</code></td><td>No</td><td>e.g. "2.0L"</td></tr>
              <tr><td><code>description</code></td><td>No</td><td>Vehicle description</td></tr>
              <tr><td><code>start_price</code></td><td><span class="badge badge-danger">Yes</span></td><td>Number only, no R symbol, e.g. 50000</td></tr>
              <tr><td><code>reserve_price</code></td><td>No</td><td>Number only</td></tr>
              <tr><td><code>min_increment</code></td><td>No</td><td>Default: 500</td></tr>
              <tr><td><code>start_time</code></td><td>No</td><td>Format: YYYY-MM-DD HH:MM:SS</td></tr>
              <tr><td><code>end_time</code></td><td>No</td><td>Format: YYYY-MM-DD HH:MM:SS</td></tr>
              <tr><td><code>status</code></td><td>No</td><td>upcoming / active / closed</td></tr>
              <tr><td><code>image_url</code></td><td>No</td><td>Path: uploads/vehicles/img.jpg</td></tr>
              <tr><td><code>gallery_images</code></td><td>No</td><td>Pipe-separated: img1.jpg|img2.jpg</td></tr>
            </tbody>
          </table>
        </div>
        <a href="sample_import.csv" class="btn btn-outline btn-sm" download>
          <i class="fas fa-download"></i> Download Sample CSV
        </a>
      </div>
    </div>

    <!-- Import Log -->
    <?php if (!empty($importLogs)): ?>
    <div class="card" style="margin-top:1.5rem">
      <h3>Import History</h3>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Date</th><th>File</th><th>Admin</th><th>Total</th><th>Imported</th><th>Failed</th><th>Details</th></tr></thead>
          <tbody>
          <?php foreach ($importLogs as $log): ?>
          <tr>
            <td><?= safeDate('d M Y H:i', $log['imported_at']) ?></td>
            <td><?= e($log['filename'] ?? '—') ?></td>
            <td><?= e($log['admin_name'] ?? 'Unknown') ?></td>
            <td><?= $log['rows_total'] ?></td>
            <td><span class="badge badge-success"><?= $log['rows_imported'] ?></span></td>
            <td><?= $log['rows_failed'] > 0 ? '<span class="badge badge-danger">'.$log['rows_failed'].'</span>' : '0' ?></td>
            <td>
              <?php if ($log['error_details']): ?>
                <button class="btn btn-outline btn-sm"
                        onclick="showImportErrors(<?= htmlspecialchars(json_encode($log['error_details'])) ?>)">
                  View Errors
                </button>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     TAB: ANALYTICS
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'analytics'): ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-chart-line"></i> Analytics</h1>
        <p class="text-muted">Platform performance overview — last 30 days</p>
      </div>
      <button class="btn btn-outline" onclick="loadAnalytics()">
        <i class="fas fa-sync-alt"></i> Refresh
      </button>
    </div>

    <!-- Summary KPI row -->
    <div class="grid-4" id="analyticsKpis">
      <div class="stat-card stat-card-blue">
        <div class="stat-icon"><i class="fas fa-gavel"></i></div>
        <div class="stat-value" id="kpi-bids">—</div>
        <div class="stat-label">Bids (30d)</div>
      </div>
      <div class="stat-card stat-card-green">
        <div class="stat-icon"><i class="fas fa-user-plus"></i></div>
        <div class="stat-value" id="kpi-regs">—</div>
        <div class="stat-label">New Users (30d)</div>
      </div>
      <div class="stat-card stat-card-cyan">
        <div class="stat-icon"><i class="fas fa-trophy"></i></div>
        <div class="stat-value" id="kpi-closed">—</div>
        <div class="stat-label">Closed Auctions</div>
      </div>
      <div class="stat-card stat-card-grey">
        <div class="stat-icon"><i class="fas fa-rand-sign"></i></div>
        <div class="stat-value" id="kpi-revenue">—</div>
        <div class="stat-label">Total Revenue</div>
      </div>
    </div>

    <!-- Charts row 1 -->
    <div class="grid-2" style="margin-top:1.5rem;align-items:start">
      <div class="card">
        <h3><i class="fas fa-gavel"></i> Bids Per Day (Last 30 Days)</h3>
        <canvas id="chartBidsPerDay" height="200"></canvas>
      </div>
      <div class="card">
        <h3><i class="fas fa-user-plus"></i> New Registrations Per Day (Last 30 Days)</h3>
        <canvas id="chartRegsPerDay" height="200"></canvas>
      </div>
    </div>

    <!-- Charts row 2 -->
    <div class="grid-2" style="margin-top:1.5rem;align-items:start">
      <div class="card">
        <h3><i class="fas fa-chart-bar"></i> Revenue by Month</h3>
        <canvas id="chartRevenue" height="200"></canvas>
      </div>
      <div class="card">
        <h3><i class="fas fa-circle-notch"></i> Auctions by Status</h3>
        <div style="max-width:280px;margin:0 auto">
          <canvas id="chartStatus" height="240"></canvas>
        </div>
      </div>
    </div>

    <!-- Tables row -->
    <div class="grid-2" style="margin-top:1.5rem;align-items:start">

      <!-- Top Auctions -->
      <div class="card">
        <h3><i class="fas fa-car"></i> Top Closed Auctions by Price</h3>
        <div class="table-responsive">
          <table class="admin-table" id="topAuctionsTable">
            <thead><tr><th>#</th><th>Vehicle</th><th>Final Price</th><th>Bids</th></tr></thead>
            <tbody><tr><td colspan="4" class="text-muted" style="text-align:center">Loading...</td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- Top Bidders -->
      <div class="card">
        <h3><i class="fas fa-fire"></i> Most Active Bidders</h3>
        <div class="table-responsive">
          <table class="admin-table" id="topBiddersTable">
            <thead><tr><th>#</th><th>Bidder</th><th>Total Bids</th><th>Total Value</th></tr></thead>
            <tbody><tr><td colspan="4" class="text-muted" style="text-align:center">Loading...</td></tr></tbody>
          </table>
        </div>
      </div>

    </div>

<!-- ═══════════════════════════════════════════════════════════
     TAB: MANUAL BID
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'manual_bid'): ?>

    <?php
    // Floor / phone bidders: accounts staff run for people who bid at floor or live auctions
    $fbSearch = trim($_GET['fbsearch'] ?? '');
    $fbSql = "SELECT u.id, u.full_name, u.phone, u.email, u.id_number, u.bidder_number, u.created_at,
                     (SELECT COUNT(*) FROM bids b WHERE b.user_id = u.id) AS bid_count,
                     (SELECT COUNT(*) FROM auctions a WHERE a.is_sold = 1 AND a.sold_to_user_id = u.id) AS win_count
              FROM users u WHERE u.is_floor_bidder = 1";
    $fbParams = [];
    if ($fbSearch !== '') {
        $fbSql .= " AND (u.full_name LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR u.id_number LIKE ? OR u.bidder_number LIKE ?)";
        $fbParams = array_fill(0, 5, "%$fbSearch%");
    }
    $fbStmt = $pdo->prepare($fbSql . " ORDER BY u.id DESC LIMIT 200");
    $fbStmt->execute($fbParams);
    $floorBidders = $fbStmt->fetchAll();
    ?>
    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-gavel"></i> Manual Bids &amp; Floor Bidders</h1>
        <p class="text-muted">Bid for customers at floor or live auctions and over the phone. Floor bidders can't log in; only staff bid for them here.</p>
      </div>
      <button type="button" class="btn btn-primary" onclick="openFloorBidder()">
        <i class="fas fa-user-plus"></i> Add floor bidder
      </button>
    </div>

    <!-- Floor / phone bidders -->
    <div class="card" style="margin-bottom:1.5rem;">
      <div class="card-header flex-between" style="flex-wrap:wrap;gap:.75rem;">
        <h3 style="margin:0"><i class="fas fa-people-group"></i> Floor &amp; phone bidders <span class="av-tab-count"><?= count($floorBidders) ?></span></h3>
        <form method="get" style="display:flex;gap:.4rem;">
          <input type="hidden" name="tab" value="manual_bid">
          <input type="text" name="fbsearch" value="<?= e($fbSearch) ?>" class="form-control" placeholder="Name, cellphone, email, ID or bidder no." style="min-width:240px;">
          <button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-search"></i></button>
          <?php if ($fbSearch !== ''): ?><a class="btn btn-outline btn-sm" href="?tab=manual_bid">Clear</a><?php endif; ?>
        </form>
      </div>
      <?php if (!$floorBidders): ?>
        <p class="text-muted" style="margin:0;">
          <?= $fbSearch !== '' ? 'No floor bidders match that search.' : 'No floor bidders yet. Use <strong>Add floor bidder</strong> for each person who registers at a floor or live auction.' ?>
        </p>
      <?php else: ?>
      <div class="table-responsive">
        <table class="admin-table">
          <thead><tr><th>Bidder no.</th><th>Name</th><th>Cellphone</th><th>Email</th><th>ID / passport</th><th>Bids</th><th>Wins</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($floorBidders as $fb):
              $fbData = ['id' => (int)$fb['id'], 'full_name' => $fb['full_name'], 'phone' => $fb['phone'] ?? '',
                         'email' => displayEmail($fb['email']), 'id_number' => $fb['id_number'] ?? '', 'bidder_number' => $fb['bidder_number'] ?? '']; ?>
            <tr>
              <td><span class="badge badge-verified-bidder"><?= e($fb['bidder_number'] ?: '—') ?></span></td>
              <td><strong><?= e($fb['full_name']) ?></strong></td>
              <td><?= $fb['phone'] ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $fb['phone'])) . '">' . e($fb['phone']) . '</a>' : '—' ?></td>
              <td><?= displayEmail($fb['email']) !== '' ? e($fb['email']) : '<span class="text-muted">—</span>' ?></td>
              <td><?= e($fb['id_number'] ?: '—') ?></td>
              <td><?= (int)$fb['bid_count'] ?></td>
              <td><?= (int)$fb['win_count'] ?></td>
              <td style="white-space:nowrap;">
                <button type="button" class="btn btn-outline btn-sm" onclick='openFloorBidder(<?= e(json_encode($fbData, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)'><i class="fas fa-pen"></i> Edit</button>
                <button type="button" class="btn btn-primary btn-sm" onclick='bidForFloorBidder(<?= e(json_encode($fbData, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)'><i class="fas fa-gavel"></i> Bid</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Add / edit floor bidder -->
    <div id="floorBidderModal" class="modal-overlay" style="display:none">
      <div class="modal-box">
        <div class="modal-header">
          <h3><i class="fas fa-user-plus"></i> <span id="fbTitle">Add floor bidder</span></h3>
          <button class="modal-close" onclick="closeModal('floorBidderModal')">×</button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="fbId">
          <div class="form-group"><label>Full name <span style="color:var(--danger)">*</span></label>
            <input type="text" id="fbName" class="form-control" maxlength="100" autocomplete="off"></div>
          <div class="grid-2" style="gap:.75rem;">
            <div class="form-group"><label>Cellphone <span style="color:var(--danger)">*</span></label>
              <input type="tel" id="fbPhone" class="form-control" placeholder="082 123 4567" autocomplete="off"></div>
            <div class="form-group"><label>Email <small class="text-muted">(optional)</small></label>
              <input type="email" id="fbEmail" class="form-control" autocomplete="off"></div>
          </div>
          <div class="grid-2" style="gap:.75rem;">
            <div class="form-group"><label>SA ID / passport no. <small class="text-muted">(optional)</small></label>
              <input type="text" id="fbIdNo" class="form-control" maxlength="20" autocomplete="off"></div>
            <div class="form-group"><label>Bidder / paddle no.</label>
              <input type="text" id="fbNumber" class="form-control" maxlength="20" placeholder="Auto: next AHG number" autocomplete="off"></div>
          </div>
          <small class="text-muted">Floor bidders are verified straight away, never get emails, and can't log in. Their bids are placed below by staff.</small>
          <div id="fbError" class="alert alert-error" style="display:none;margin:.75rem 0 0;"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline" onclick="closeModal('floorBidderModal')">Cancel</button>
          <button class="btn btn-primary" id="fbSave" onclick="saveFloorBidder()"><i class="fas fa-save"></i> Save</button>
        </div>
      </div>
    </div>
    <script>
    window.openFloorBidder = function (fb) {
        fb = fb || {};
        document.getElementById('fbTitle').textContent = fb.id ? 'Edit floor bidder' : 'Add floor bidder';
        document.getElementById('fbId').value     = fb.id || '';
        document.getElementById('fbName').value   = fb.full_name || '';
        document.getElementById('fbPhone').value  = fb.phone || '';
        document.getElementById('fbEmail').value  = fb.email || '';
        document.getElementById('fbIdNo').value   = fb.id_number || '';
        document.getElementById('fbNumber').value = fb.bidder_number || '';
        document.getElementById('fbError').style.display = 'none';
        openModal('floorBidderModal');
        setTimeout(() => document.getElementById('fbName').focus(), 50);
    };
    window.saveFloorBidder = async function () {
        const err = document.getElementById('fbError'), btn = document.getElementById('fbSave');
        const fd = new FormData();
        fd.append('action', 'floor_bidder_save');
        fd.append('id', document.getElementById('fbId').value);
        fd.append('full_name', document.getElementById('fbName').value.trim());
        fd.append('phone', document.getElementById('fbPhone').value.trim());
        fd.append('email', document.getElementById('fbEmail').value.trim());
        fd.append('id_number', document.getElementById('fbIdNo').value.trim());
        fd.append('bidder_number', document.getElementById('fbNumber').value.trim());
        btn.disabled = true; err.style.display = 'none';
        try {
            const d = await (await fetch('api.php', {method: 'POST', body: fd})).json();
            if (!d.success) { err.textContent = d.message; err.style.display = ''; return; }
            showToast(d.message, 'success');
            setTimeout(() => location.reload(), 700);
        } catch { err.textContent = 'Could not save. Please try again.'; err.style.display = ''; }
        finally { btn.disabled = false; }
    };
    // "Bid" on a row: pick that bidder in the form below and jump to it
    window.bidForFloorBidder = function (fb) {
        selectBidder(fb.id, fb.full_name, fb.phone || '', fb.bidder_number || '');
        document.getElementById('auctionSearch').scrollIntoView({behavior: 'smooth', block: 'center'});
        setTimeout(() => document.getElementById('auctionSearch').focus(), 400);
    };
    </script>

    <div class="grid-2" style="align-items:start">

      <!-- Bid Form -->
      <div class="card">
        <h3>Place Bid</h3>

        <div id="manualBidResult"></div>

        <div class="form-group">
          <label>Select Auction <span style="color:var(--danger)">*</span></label>
          <input type="text" id="auctionSearch" class="form-control"
                 placeholder="Search active auctions by name or make..."
                 autocomplete="off">
          <div id="auctionSuggestions" class="autocomplete-dropdown" style="display:none"></div>
          <input type="hidden" id="selectedAuctionId">
          <div id="auctionPreview" class="selected-item-preview" style="display:none"></div>
        </div>

        <div class="form-group">
          <label>Select Bidder <span style="color:var(--danger)">*</span></label>
          <input type="text" id="bidderSearch" class="form-control"
                 placeholder="Search verified bidders by name, email or bidder number..."
                 autocomplete="off">
          <div id="bidderSuggestions" class="autocomplete-dropdown" style="display:none"></div>
          <input type="hidden" id="selectedBidderId">
          <div id="bidderPreview" class="selected-item-preview" style="display:none"></div>
        </div>

        <div class="form-group">
          <label>Bid Amount (R) <span style="color:var(--danger)">*</span></label>
          <input type="number" id="manualBidAmount" class="form-control"
                 min="0" step="500" placeholder="0.00">
          <small id="minBidHint" class="text-muted"></small>
        </div>

        <div class="form-group">
          <label>Internal Note (optional)</label>
          <input type="text" id="manualBidNote" class="form-control"
                 placeholder="e.g. Phone bid — bidder called in">
        </div>

        <div class="alert alert-info" style="margin-bottom:1rem">
          <i class="fas fa-info-circle"></i>
          This bid will be recorded under the selected bidder's account. Online bidders get an email confirmation; floor bidders don't. All admin-placed bids are logged for audit purposes.
        </div>

        <button class="btn btn-primary" id="submitManualBidBtn" onclick="submitManualBid()">
          <i class="fas fa-gavel"></i> Place Bid
        </button>
      </div>

      <!-- Recent Manual Bids Log -->
      <div class="card">
        <h3><i class="fas fa-history"></i> Recent Admin-Placed Bids</h3>
        <p class="text-muted" style="font-size:.85rem">Bids placed via this panel (ip_address starts with ADMIN:)</p>
        <div class="table-responsive">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Bidder</th>
                <th>Auction</th>
                <th>Amount</th>
                <th>Note</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $adminBids = $pdo->query("
                  SELECT b.bid_amount, b.bid_time, b.bidder_name, b.bidder_number,
                         b.ip_address, a.item_name, a.id AS auction_id
                  FROM bids b
                  JOIN auctions a ON a.id = b.auction_id
                  WHERE b.ip_address LIKE 'ADMIN:%'
                  ORDER BY b.bid_time DESC
                  LIMIT 50
              ")->fetchAll();
              ?>
              <?php if (empty($adminBids)): ?>
              <tr><td colspan="5" class="text-muted" style="text-align:center;padding:1rem">No admin-placed bids yet.</td></tr>
              <?php else: ?>
              <?php foreach ($adminBids as $ab):
                  $note = preg_replace('/^ADMIN:\d+\s*—?\s*/', '', $ab['ip_address']);
              ?>
              <tr>
                <td><?= safeDate('d M H:i', $ab['bid_time']) ?></td>
                <td>
                  <?= e($ab['bidder_name']) ?>
                  <?php if ($ab['bidder_number']): ?>
                    <br><small class="text-muted"><?= e($ab['bidder_number']) ?></small>
                  <?php endif; ?>
                </td>
                <td><a href="auction.php?id=<?= $ab['auction_id'] ?>"><?= e($ab['item_name']) ?></a></td>
                <td><strong><?= formatRand($ab['bid_amount']) ?></strong></td>
                <td><small class="text-muted"><?= e($note ?: '—') ?></small></td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

<!-- ═══════════════════════════════════════════════════════════════
     TAB: BANKING DETAILS
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'banking'):
    $bankSettings = getBankSettings();
?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-university"></i> Banking Details</h1>
        <p class="text-muted">Manage the EFT banking details sent to users when they request them.</p>
      </div>
    </div>

    <div class="grid-2" style="align-items:start">

      <!-- Edit Banking Details -->
      <div class="card">
        <h3><i class="fas fa-edit"></i> Edit Banking Details</h3>
        <div id="bankSettingsResult"></div>
        <form id="bankSettingsForm">
          <input type="hidden" name="action" value="update_bank_settings">
          <div class="form-group">
            <label>Bank Name</label>
            <input type="text" name="bank_name" class="form-control"
                   value="<?= e($bankSettings['bank_name']) ?>" required>
          </div>
          <div class="form-group">
            <label>Account Name</label>
            <input type="text" name="bank_account_name" class="form-control"
                   value="<?= e($bankSettings['bank_account_name']) ?>" required>
          </div>
          <div class="form-group">
            <label>Account Number</label>
            <input type="text" name="bank_account_no" class="form-control"
                   value="<?= e($bankSettings['bank_account_no']) ?>" required>
          </div>
          <div class="form-group">
            <label>Branch Code</label>
            <input type="text" name="bank_branch_code" class="form-control"
                   value="<?= e($bankSettings['bank_branch_code']) ?>" required>
          </div>
          <div class="form-group">
            <label>Account Type</label>
            <input type="text" name="bank_account_type" class="form-control"
                   value="<?= e($bankSettings['bank_account_type']) ?>" required>
          </div>
          <div class="form-group">
            <label>Registration Fee Label (e.g. R10,000)</label>
            <input type="text" name="reg_fee_label" class="form-control"
                   value="<?= e($bankSettings['reg_fee_label']) ?>" required>
          </div>
          <button type="submit" class="btn btn-primary" id="saveBankSettingsBtn">
            <i class="fas fa-save"></i> Save Banking Details
          </button>
        </form>
      </div>

      <!-- Preview -->
      <div class="card">
        <h3><i class="fas fa-eye"></i> Preview (What Users Will See in Email)</h3>
        <div style="background:var(--gray-100);border-radius:var(--radius-md);padding:1.25rem;margin-top:.5rem">
          <div style="border-left:4px solid var(--blue-primary);padding-left:1rem;margin-bottom:1rem">
            <p style="margin:.3rem 0"><strong>Bank:</strong> <span id="prevBankName"><?= e($bankSettings['bank_name']) ?></span></p>
            <p style="margin:.3rem 0"><strong>Account Name:</strong> <span id="prevAccountName"><?= e($bankSettings['bank_account_name']) ?></span></p>
            <p style="margin:.3rem 0"><strong>Account Number:</strong> <span id="prevAccountNo"><?= e($bankSettings['bank_account_no']) ?></span></p>
            <p style="margin:.3rem 0"><strong>Branch Code:</strong> <span id="prevBranchCode"><?= e($bankSettings['bank_branch_code']) ?></span></p>
            <p style="margin:.3rem 0"><strong>Account Type:</strong> <span id="prevAccountType"><?= e($bankSettings['bank_account_type']) ?></span></p>
            <p style="margin:.3rem 0"><strong>Amount:</strong> <span id="prevFeeLabel" style="color:var(--blue-primary);font-weight:700"><?= e($bankSettings['reg_fee_label']) ?></span></p>
            <p style="margin:.3rem 0"><strong>Reference:</strong> <span style="color:var(--gold)">AHG-[UserID]-REG</span></p>
          </div>
        </div>
        <p class="text-muted" style="margin-top:1rem;font-size:.85rem">
          <i class="fas fa-info-circle"></i> This is how the banking details appear in the email sent to users.
          The payment reference is generated automatically per user.
        </p>
      </div>

    </div>

    <!-- Pending Banking Detail Requests -->
    <div class="card" style="margin-top:1.5rem;">
      <h3 style="margin-bottom:1rem;">
        <i class="fas fa-clock" style="color:#f9a825;"></i> Pending Banking Detail Requests
        <?php $pendingCount = count(array_filter($bankRequests, fn($r) => $r['status'] === 'pending')); ?>
        <?php if ($pendingCount > 0): ?>
        <span style="background:#dc3545;color:#fff;font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:12px;margin-left:.5rem;"><?= $pendingCount ?></span>
        <?php endif; ?>
      </h3>
      <p class="text-muted" style="margin-bottom:1rem;font-size:.88rem;">
        Users who request banking details must be approved here first. Once approved, the system emails them the EFT details.
      </p>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>User</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Requested</th>
              <th>Status</th>
              <th style="width:160px">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($bankRequests as $br): ?>
          <tr id="bank-req-<?= $br['id'] ?>">
            <td style="font-weight:600;"><?= e($br['full_name']) ?></td>
            <td style="font-size:.88rem;"><?= e($br['email']) ?></td>
            <td style="font-size:.88rem;"><?= e($br['phone'] ?? '—') ?></td>
            <td style="font-size:.85rem;color:#666;"><?= safeDate('d M Y H:i', $br['created_at']) ?></td>
            <td>
              <?php
                $statusColors = ['pending' => '#f9a825', 'approved' => '#4caf50', 'rejected' => '#dc3545'];
                $sc = $statusColors[$br['status']] ?? '#888';
              ?>
              <span style="display:inline-block;padding:.2rem .6rem;border-radius:6px;font-size:.72rem;font-weight:700;text-transform:uppercase;color:#fff;background:<?= $sc ?>;">
                <?= e($br['status']) ?>
              </span>
            </td>
            <td>
              <?php if ($br['status'] === 'pending'): ?>
              <div class="table-actions">
                <button class="btn btn-primary btn-sm" onclick="toggleBankApprovalForm(<?= $br['id'] ?>)" title="Review & Approve">
                  <i class="fas fa-eye"></i> Review
                </button>
                <button class="btn btn-danger btn-sm" onclick="rejectBankRequest(<?= $br['id'] ?>)" title="Reject">
                  <i class="fas fa-times"></i>
                </button>
              </div>
              <?php elseif ($br['processed_at']): ?>
              <span style="font-size:.78rem;color:#888;"><?= safeDate('d M H:i', $br['processed_at']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($br['status'] === 'pending'): ?>
          <tr id="bank-approval-row-<?= $br['id'] ?>" style="display:none;">
            <td colspan="6" style="background:#f8fafc;padding:1.25rem;">
              <h4 style="margin:0 0 .75rem;"><i class="fas fa-envelope-open-text" style="color:#1565c0;"></i> Approve &amp; Send Banking Details to <?= e($br['full_name']) ?></h4>
              <p class="text-muted" style="font-size:.88rem;margin:0 0 1rem;">
                Review the EFT details below. When you click <strong>Approve &amp; Send Email</strong>, the system will email them to <strong><?= e($br['email']) ?></strong>.
                The payment reference will be <code>AHG-<?= (int)$br['user_id'] ?>-REG</code>.
              </p>
              <div style="background:#fff;border-left:4px solid #1565c0;padding:1rem;border-radius:6px;margin-bottom:1rem;font-size:.9rem;line-height:1.7;">
                <div><strong>Bank:</strong> <?= e($bankSettings['bank_name']) ?></div>
                <div><strong>Account Name:</strong> <?= e($bankSettings['bank_account_name']) ?></div>
                <div><strong>Account Number:</strong> <?= e($bankSettings['bank_account_no']) ?></div>
                <div><strong>Branch Code:</strong> <?= e($bankSettings['bank_branch_code']) ?></div>
                <div><strong>Account Type:</strong> <?= e($bankSettings['bank_account_type']) ?></div>
                <div><strong>Amount:</strong> <?= e($bankSettings['reg_fee_label']) ?></div>
                <div><strong>Reference:</strong> AHG-<?= (int)$br['user_id'] ?>-REG</div>
              </div>
              <div id="bank-approval-result-<?= $br['id'] ?>"></div>
              <div class="table-actions">
                <button class="btn btn-primary" onclick="approveBankRequest(<?= $br['id'] ?>, '<?= e(addslashes($br['full_name'])) ?>')" id="bank-approve-btn-<?= $br['id'] ?>">
                  <i class="fas fa-paper-plane"></i> Approve &amp; Send Email
                </button>
                <button class="btn btn-outline" onclick="toggleBankApprovalForm(<?= $br['id'] ?>)">Cancel</button>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
          <?php if (empty($bankRequests)): ?>
          <tr><td colspan="6" class="text-center text-muted" style="padding:2rem;">No banking detail requests yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

<!-- ═══════════════════════════════════════════════════════════
     TAB: EVENTS
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'events'): ?>

<?php if ($editEvent): ?>
<!-- ── EDIT/MANAGE SINGLE EVENT ───────────────────────────── -->
<div class="dashboard-header">
    <h1><i class="fas fa-calendar-alt"></i> <?= e($editEvent['title']) ?></h1>
    <a href="?tab=events" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Events</a>
</div>

<!-- Event Details Form -->
<div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem"><i class="fas fa-edit"></i> Event Details</h3>
    <form id="editEventForm" class="event-form-grid">
        <input type="hidden" name="action" value="save_event">
        <input type="hidden" name="event_id" value="<?= $editEvent['id'] ?>">

        <div class="form-row-3">
            <div>
                <label class="form-label">Event Title *</label>
                <input type="text" name="title" class="form-control" value="<?= e($editEvent['title']) ?>" required>
            </div>
            <div>
                <label class="form-label">Bank Lot</label>
                <select name="bank_lot" class="form-control">
                    <option value="">— Select —</option>
                    <?php foreach (['Absa Lot','Nedbank (MFC) Lot','Standard Bank Lot','Wesbank Lot','Mixed Lot','Private Seller Lot'] as $bl): ?>
                    <option value="<?= $bl ?>" <?= $editEvent['bank_lot']===$bl?'selected':'' ?>><?= $bl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Province</label>
                <select name="province" class="form-control">
                    <option value="">— Select —</option>
                    <?php foreach (['Gauteng','Western Cape','KwaZulu-Natal','Eastern Cape','Free State','Limpopo','Mpumalanga','North West','Northern Cape'] as $p): ?>
                    <option value="<?= $p ?>" <?= $editEvent['province']===$p?'selected':'' ?>><?= $p ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row-3">
            <div>
                <label class="form-label">Venue</label>
                <input type="text" name="venue" class="form-control" value="<?= e($editEvent['venue'] ?? '') ?>">
            </div>
            <div>
                <label class="form-label">Event Date *</label>
                <input type="date" name="event_date" class="form-control" value="<?= $editEvent['event_date'] ?>" required>
            </div>
            <div>
                <label class="form-label">Event Time</label>
                <input type="time" name="event_time" class="form-control" value="<?= $editEvent['event_time'] ?? '' ?>">
            </div>
        </div>

        <div class="form-row-3">
            <div>
                <label class="form-label">Bidding Opens</label>
                <input type="datetime-local" name="start_time" class="form-control"
                       value="<?= $editEvent['start_time'] ? safeDate('Y-m-d\TH:i', $editEvent['start_time']) : '' ?>">
            </div>
            <div>
                <label class="form-label">Bidding Closes</label>
                <input type="datetime-local" name="end_time" class="form-control"
                       value="<?= $editEvent['end_time'] ? safeDate('Y-m-d\TH:i', $editEvent['end_time']) : '' ?>">
            </div>
            <div>
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <?php foreach (['draft'=>'Draft','upcoming'=>'Upcoming','live'=>'Live','completed'=>'Completed','cancelled'=>'Cancelled'] as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $editEvent['status']===$k?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div>
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3"><?= e($editEvent['description'] ?? '') ?></textarea>
        </div>

        <div>
            <label class="form-label">Banner Image (1920 × 1080px recommended)</label>
            <div style="display:flex;align-items:center;gap:1rem;">
                <input type="file" name="banner_image" class="form-control" accept="image/jpeg,image/png,image/webp" style="flex:1;">
                <?php if (!empty($editEvent['banner_image'])): ?>
                <div style="flex-shrink:0;">
                    <img src="<?= e($editEvent['banner_image']) ?>" alt="Current banner" style="height:60px;border-radius:6px;border:1px solid #e5eaf0;">
                </div>
                <?php endif; ?>
            </div>
            <small class="text-muted">JPG, PNG or WebP. Max 10MB. This banner will display on the public event page.</small>
        </div>

        <div style="display:flex;align-items:center;gap:1rem">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                <input type="checkbox" name="is_featured" value="1" <?= $editEvent['is_featured']?'checked':'' ?>>
                <strong>Featured Event</strong>
            </label>
            <button type="submit" class="btn btn-primary" id="saveEventBtn">
                <i class="fas fa-save"></i> Save Changes
            </button>
            <div id="eventFormResult" style="flex:1"></div>
        </div>
    </form>
</div>

<!-- ── Bulk Date/Status Update ──────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:.75rem"><i class="fas fa-clock"></i> Bulk Update — Dates &amp; Status</h3>
    <p class="text-muted" style="font-size:.85rem;margin-bottom:1rem">Apply dates or status to all vehicles in this event, or only selected ones.</p>

    <div class="form-row-3" style="margin-bottom:1rem">
        <div>
            <label class="form-label">Bidding Opens</label>
            <input type="datetime-local" id="bulkStartTime" class="form-control">
        </div>
        <div>
            <label class="form-label">Bidding Closes</label>
            <input type="datetime-local" id="bulkEndTime" class="form-control">
        </div>
        <div>
            <label class="form-label">Status</label>
            <select id="bulkStatus" class="form-control">
                <option value="">— Don't change —</option>
                <option value="upcoming">Upcoming</option>
                <option value="active">Live</option>
                <option value="closed">Closed</option>
            </select>
        </div>
    </div>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
        <button class="btn btn-primary" onclick="bulkUpdateDates('all')">
            <i class="fas fa-sync"></i> Apply to ALL Vehicles
        </button>
        <button class="btn btn-outline" onclick="bulkUpdateDates('selected')">
            <i class="fas fa-check-square"></i> Apply to Selected Only
        </button>
        <span class="text-muted" style="font-size:.82rem" id="bulkSelectedCount">0 selected</span>
    </div>
</div>

<!-- ── Add Vehicles to Event ──────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:.75rem"><i class="fas fa-plus-circle"></i> Add Vehicles to Event</h3>
    <div style="display:flex;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap">
        <input type="text" id="vehiclePickerSearch" class="form-control" placeholder="Search vehicles by name or make..."
               style="flex:1;min-width:200px" oninput="filterVehiclePicker()">
        <button class="btn btn-primary" onclick="addSelectedVehicles()">
            <i class="fas fa-plus"></i> Add Selected
        </button>
    </div>
    <div id="vehiclePickerGrid" class="vehicle-picker-grid" style="max-height:320px;overflow-y:auto">
        <?php
        $eventVehicleIds = array_column($editEventVehicles, 'id');
        foreach ($allVehicles as $v):
            if (in_array($v['id'], $eventVehicleIds)) continue;
            $vPrice = (float)($v['current_price'] ?: $v['start_price']);
        ?>
        <label class="vehicle-picker-item" data-name="<?= e(strtolower($v['item_name'] . ' ' . ($v['make_name'] ?? ''))) ?>">
            <input type="checkbox" class="picker-cb" value="<?= $v['id'] ?>">
            <img src="<?= e(imgHost($v['image_url'] ?: 'assets/no-image.jpg')) ?>" alt="" class="picker-thumb">
            <div class="picker-info">
                <strong><?= e($v['item_name']) ?></strong>
                <small><?= e($v['make_name'] ?? '') ?> · <?= $v['status'] ?> · <?= formatRand($vPrice) ?></small>
            </div>
        </label>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── Event Vehicles List ──────────────────────────────── -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
        <h3 style="margin:0"><i class="fas fa-list"></i> Event Vehicles (<?= count($editEventVehicles) ?>)</h3>
        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.85rem">
            <input type="checkbox" id="selectAllEV" onchange="toggleSelectAll(this)"> Select All
        </label>
    </div>

    <?php if (empty($editEventVehicles)): ?>
        <p class="text-muted">No vehicles added to this event yet. Use the picker above to add vehicles.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:40px"><input type="checkbox" id="selectAllEV2" onchange="toggleSelectAll(this)"></th>
                    <th style="width:50px">Img</th>
                    <th>Vehicle</th>
                    <th>Status</th>
                    <th>Price</th>
                    <th>Start</th>
                    <th>End</th>
                    <th style="width:80px">Hot</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($editEventVehicles as $ev):
                $evPrice = (float)($ev['current_price'] ?: $ev['start_price']);
            ?>
                <tr data-vehicle-id="<?= $ev['id'] ?>">
                    <td><input type="checkbox" class="ev-select-cb" value="<?= $ev['id'] ?>"></td>
                    <td>
                        <?php if ($ev['image_url']): ?>
                        <img src="<?= e(imgHost($ev['image_url'])) ?>" alt="" class="admin-thumb">
                        <?php else: ?>
                        <div class="admin-thumb-placeholder"><i class="fas fa-car"></i></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="auction.php?id=<?= $ev['id'] ?>" target="_blank"><strong><?= e($ev['item_name']) ?></strong></a>
                        <small class="d-block text-muted"><?= $ev['year'] ?> · <?= e($ev['make_name'] ?? '') ?></small>
                    </td>
                    <td><span class="badge badge-<?= $ev['status']==='active'?'active':($ev['status']==='upcoming'?'upcoming':'closed') ?>"><?= ucfirst($ev['status']) ?></span></td>
                    <td><?= formatRand($evPrice) ?></td>
                    <td><small><?= $ev['start_time'] ? safeDate('d M H:i', $ev['start_time']) : '—' ?></small></td>
                    <td><small><?= $ev['end_time'] ? safeDate('d M H:i', $ev['end_time']) : '—' ?></small></td>
                    <td>
                        <button class="btn btn-sm <?= $ev['is_hot'] ? 'btn-warning' : 'btn-outline' ?>"
                                onclick="toggleHot(<?= $editEvent['id'] ?>, <?= $ev['id'] ?>, this)"
                                title="<?= $ev['is_hot'] ? 'Remove from hot list' : 'Add to hot list' ?>">
                            <i class="fas fa-fire"></i>
                        </button>
                    </td>
                    <td>
                        <button class="btn btn-danger btn-sm" onclick="removeFromEvent(<?= $editEvent['id'] ?>, <?= $ev['id'] ?>, this)"
                                title="Remove from event"><i class="fas fa-times"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ── EVENTS LIST VIEW ───────────────────────────────────── -->
<div class="dashboard-header">
    <h1><i class="fas fa-calendar-alt"></i> Auction Events</h1>
    <button class="btn btn-primary" onclick="document.getElementById('newEventSection').hidden=!document.getElementById('newEventSection').hidden">
        <i class="fas fa-plus-circle"></i> Create Event
    </button>
</div>

<!-- ── Create New Event (collapsed) ────────────────────────── -->
<div class="card" id="newEventSection" hidden style="margin-bottom:1.5rem">
    <h3 style="margin-bottom:1rem"><i class="fas fa-plus"></i> New Online Auction Event</h3>
    <form id="newEventForm">
        <input type="hidden" name="action" value="save_event">
        <input type="hidden" name="venue" value="Online">

        <div class="form-row-3">
            <div>
                <label class="form-label">Event Name *</label>
                <input type="text" name="title" class="form-control" required placeholder="e.g. Weekly Bank Repo Auction">
            </div>
            <div>
                <label class="form-label">Bank Lot</label>
                <select name="bank_lot" class="form-control">
                    <option value="">— Select —</option>
                    <option>Absa Lot</option>
                    <option>Nedbank (MFC) Lot</option>
                    <option>Standard Bank Lot</option>
                    <option>Wesbank Lot</option>
                    <option>Mixed Lot</option>
                    <option>Private Seller Lot</option>
                </select>
            </div>
            <div>
                <label class="form-label">Province</label>
                <select name="province" class="form-control">
                    <option value="">— Select —</option>
                    <option>Gauteng</option>
                    <option>Western Cape</option>
                    <option>KwaZulu-Natal</option>
                    <option>Eastern Cape</option>
                    <option>Free State</option>
                    <option>Limpopo</option>
                    <option>Mpumalanga</option>
                    <option>North West</option>
                    <option>Northern Cape</option>
                </select>
            </div>
        </div>

        <div class="form-row-3">
            <div>
                <label class="form-label">Event Date *</label>
                <input type="date" name="event_date" class="form-control" required>
            </div>
            <div>
                <label class="form-label">Bidding Opens *</label>
                <input type="datetime-local" name="start_time" class="form-control" required>
            </div>
            <div>
                <label class="form-label">Bidding Closes *</label>
                <input type="datetime-local" name="end_time" class="form-control" required>
            </div>
        </div>

        <div class="form-row-3">
            <div>
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="upcoming">Upcoming</option>
                    <option value="live">Live</option>
                    <option value="draft">Draft</option>
                </select>
            </div>
            <div style="display:flex;align-items:end">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;padding-bottom:.5rem">
                    <input type="checkbox" name="is_featured" value="1"> Featured Event
                </label>
            </div>
            <div></div>
        </div>

        <div style="margin-bottom:1rem">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Brief description of this auction event"></textarea>
        </div>

        <div style="display:flex;gap:.75rem;align-items:center">
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Create Event</button>
            <div id="newEventResult"></div>
        </div>
    </form>
</div>

<!-- ── Filter Bar ──────────────────────────────────────── -->
<form class="admin-filter-bar" method="get">
    <input type="hidden" name="tab" value="events">
    <input type="text" name="esearch" value="<?= e($eventSearch) ?>" placeholder="Search events..." class="form-control" style="flex:1">
    <select name="estatus" class="form-control" style="width:160px">
        <option value="">All Statuses</option>
        <option value="draft"     <?= $eventStatus==='draft'?'selected':'' ?>>Draft</option>
        <option value="upcoming"  <?= $eventStatus==='upcoming'?'selected':'' ?>>Upcoming</option>
        <option value="live"      <?= $eventStatus==='live'?'selected':'' ?>>Live</option>
        <option value="completed" <?= $eventStatus==='completed'?'selected':'' ?>>Completed</option>
        <option value="cancelled" <?= $eventStatus==='cancelled'?'selected':'' ?>>Cancelled</option>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
</form>

<!-- ── Events Table ────────────────────────────────────── -->
<?php if (empty($adminEvents)): ?>
    <div class="card" style="text-align:center;padding:3rem">
        <i class="fas fa-calendar-plus" style="font-size:3rem;color:#ccc;margin-bottom:1rem;display:block"></i>
        <p class="text-muted">No events found. Create your first auction event above.</p>
    </div>
<?php else: ?>
<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Event</th>
                <th>Bank Lot</th>
                <th>Province</th>
                <th>Duration</th>
                <th>Vehicles</th>
                <th>Hot</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($adminEvents as $evt): ?>
            <tr>
                <td>
                    <?php if (!empty($evt['banner_image'])): ?>
                    <img src="<?= e($evt['banner_image']) ?>" alt="Banner" style="height:38px;width:68px;object-fit:cover;border-radius:4px;border:1px solid #e5eaf0;display:block;margin-bottom:.35rem">
                    <?php endif; ?>
                    <strong><?= e($evt['title']) ?></strong>
                    <small class="d-block text-muted"><i class="fas fa-globe"></i> Online Auction</small>
                    <?php if ($evt['is_featured']): ?>
                    <span class="badge badge-warning" style="font-size:.65rem"><i class="fas fa-star"></i> Featured</span>
                    <?php endif; ?>
                </td>
                <td><?= e($evt['bank_lot'] ?: '—') ?></td>
                <td><?= e($evt['province'] ?: '—') ?></td>
                <td>
                    <strong><?= safeDate('d M Y', $evt['event_date']) ?></strong>
                    <?php if ($evt['start_time'] && $evt['end_time']): ?>
                    <small class="d-block text-muted">
                        <?= safeDate('d M H:i', $evt['start_time']) ?> &rarr; <?= safeDate('d M H:i', $evt['end_time']) ?>
                    </small>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-bid-count"><?= (int)$evt['vehicle_count'] ?></span></td>
                <td>
                    <?php if ((int)$evt['hot_count'] > 0): ?>
                    <span class="badge badge-warning"><i class="fas fa-fire"></i> <?= (int)$evt['hot_count'] ?></span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?= $evt['status']==='live'?'active':($evt['status']==='upcoming'?'upcoming':($evt['status']==='draft'?'draft':'closed')) ?>">
                        <?= ucfirst($evt['status']) ?>
                    </span>
                </td>
                <td>
                    <div class="table-actions">
                        <a href="?tab=events&edit_event=<?= $evt['id'] ?>" class="btn btn-primary btn-sm" title="Manage">
                            <i class="fas fa-cog"></i>
                        </a>
                        <a href="events.php?id=<?= $evt['id'] ?>" target="_blank" class="btn btn-outline btn-sm" title="View">
                            <i class="fas fa-eye"></i>
                        </a>
                        <button class="btn btn-danger btn-sm" onclick="deleteEvent(<?= $evt['id'] ?>, <?= htmlspecialchars(json_encode($evt['title']), ENT_QUOTES) ?>)" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($ePages > 1): ?>
<div class="pagination">
    <?php for ($p = 1; $p <= $ePages; $p++): ?>
    <a href="?tab=events&epage=<?= $p ?>&esearch=<?= urlencode($eventSearch) ?>&estatus=<?= urlencode($eventStatus) ?>"
       class="page-btn <?= $p === $eventPage ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; // adminEvents ?>
<?php endif; // editEvent ?>

<!-- ═══════════════════════════════════════════════════════════
     TAB: TESTIMONIALS
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'testimonials'): ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-star"></i> Testimonials</h1>
        <p class="text-muted"><?= count($adminTestimonials) ?> testimonial<?= count($adminTestimonials)!==1?'s':'' ?></p>
      </div>
    </div>

    <!-- ── Add Testimonial — multi-upload ─────────────────── -->
    <div class="card" style="margin-bottom:1.5rem;">
      <h3 style="margin:0 0 .25rem;"><i class="fas fa-plus-circle" style="color:#005DDB;"></i> Add Testimonials</h3>
      <p class="text-muted" style="margin:0 0 1rem;font-size:.88rem;">Select one or multiple images. Each gets its own headline.</p>
      <div id="inlineTestimonialResult"></div>

      <!-- Multi-file drop zone -->
      <div id="testimDrop" onclick="document.getElementById('inlineTestimonialImage').click()"
           style="border:2px dashed #c8d8ea;border-radius:12px;padding:2rem;text-align:center;cursor:pointer;background:#f8faff;transition:border-color .2s,background .2s;margin-bottom:1rem;"
           ondragover="event.preventDefault();this.style.borderColor='#005DDB';this.style.background='#eef4ff';"
           ondragleave="this.style.borderColor='#c8d8ea';this.style.background='#f8faff';"
           ondrop="event.preventDefault();this.style.borderColor='#c8d8ea';this.style.background='#f8faff';handleTestimDrop(event.dataTransfer.files);">
        <i class="fas fa-cloud-upload-alt" style="font-size:2.2rem;color:#005DDB;margin-bottom:.75rem;display:block;"></i>
        <strong style="color:#001F5C;">Click to browse or drag images here</strong><br>
        <small class="text-muted">JPG / PNG / WebP · Max 10 MB each · Select multiple at once</small>
        <input type="file" id="inlineTestimonialImage" accept="image/jpeg,image/png,image/webp" multiple style="display:none;" onchange="handleTestimFiles(this.files)">
      </div>

      <!-- Preview grid for selected files -->
      <div id="testimPreviews" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:1rem;"></div>

      <div style="display:flex;gap:.75rem;align-items:center;">
        <button class="btn btn-primary" id="inlineTestimonialBtn" onclick="submitAllTestimonials()" style="display:none;">
          <i class="fas fa-upload"></i> Upload All
        </button>
        <button class="btn btn-outline" onclick="clearTestimPreviews()" id="testimClearBtn" style="display:none;">
          <i class="fas fa-times"></i> Clear
        </button>
        <span id="testimProgress" style="font-size:.85rem;color:#6a7f96;"></span>
      </div>
    </div>

    <!-- ── Existing testimonials ─────────────────────────── -->
    <h3 style="margin:1.5rem 0 .75rem;"><i class="fas fa-images"></i> Existing Testimonials
      <span style="font-size:.85rem;font-weight:400;color:#6a7f96;margin-left:.5rem;"><?= count($adminTestimonials) ?> total — drag to reorder</span>
    </h3>

    <?php if (empty($adminTestimonials)): ?>
    <div class="card" style="text-align:center;padding:3rem;color:#6a7f96;">
      <i class="fas fa-images" style="font-size:2.5rem;margin-bottom:1rem;display:block;color:#c8d8ea;"></i>
      No testimonials yet. Upload some above.
    </div>
    <?php else: ?>
    <div id="testimGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1.25rem;">
      <?php foreach ($adminTestimonials as $t): ?>
      <div class="testim-admin-card <?= $t['is_active'] ? '' : 'testim-inactive' ?>" data-id="<?= $t['id'] ?>" data-order="<?= $t['sort_order'] ?>"
           style="background:#fff;border-radius:12px;border:1px solid #dce6f0;box-shadow:0 2px 8px rgba(0,31,92,0.07);overflow:hidden;display:flex;flex-direction:column;">

        <!-- Image -->
        <div style="position:relative;aspect-ratio:4/3;overflow:hidden;background:#f1f5f9;cursor:pointer;" onclick="replaceTestimImage(<?= $t['id'] ?>)" title="Click to replace image">
          <img src="<?= e(imgHost($t['image_url'])) ?>" alt="" style="width:100%;height:100%;object-fit:cover;transition:opacity .2s;" onerror="this.src='';this.style.display='none'">
          <div class="testim-img-overlay" style="position:absolute;inset:0;background:rgba(0,31,92,0.50);display:none;align-items:center;justify-content:center;flex-direction:column;gap:.4rem;color:#fff;font-size:.8rem;font-weight:600;">
            <i class="fas fa-camera" style="font-size:1.4rem;"></i> Replace Photo
          </div>
          <?php if (!$t['is_active']): ?>
          <div style="position:absolute;top:.5rem;left:.5rem;background:#f59e0b;color:#fff;font-size:.65rem;font-weight:700;padding:.15rem .45rem;border-radius:4px;text-transform:uppercase;">Hidden</div>
          <?php endif; ?>
        </div>

        <!-- Headline (click to edit) -->
        <div style="padding:.75rem;flex:1;display:flex;flex-direction:column;gap:.6rem;">
          <div class="testim-headline" data-id="<?= $t['id'] ?>"
               style="font-size:.88rem;font-weight:600;color:#001F5C;line-height:1.45;cursor:pointer;border-bottom:1px dashed transparent;transition:border-color .2s;"
               onclick="inlineEditHeadline(this,<?= $t['id'] ?>)"
               title="Click to edit headline">
            <?= e($t['headline']) ?>
          </div>

          <!-- Controls -->
          <div style="display:flex;align-items:center;justify-content:space-between;gap:.4rem;margin-top:auto;">
            <div style="display:flex;gap:.3rem;">
              <button class="btn btn-sm" style="padding:.25rem .5rem;background:#f1f5f9;border:1px solid #dce6f0;color:#001F5C;" onclick="moveTestim(<?= $t['id'] ?>,-1)" title="Move up">↑</button>
              <button class="btn btn-sm" style="padding:.25rem .5rem;background:#f1f5f9;border:1px solid #dce6f0;color:#001F5C;" onclick="moveTestim(<?= $t['id'] ?>,1)" title="Move down">↓</button>
            </div>
            <label class="toggle-switch" style="margin:0;" title="Show/Hide">
              <input type="checkbox" <?= $t['is_active'] ? 'checked' : '' ?>
                     onchange="toggleTestim(<?= $t['id'] ?>,this.checked,this)">
              <span class="toggle-slider"></span>
            </label>
            <button class="btn btn-danger btn-sm" style="padding:.25rem .5rem;" onclick="deleteTestimonial(<?= $t['id'] ?>)" title="Delete">
              <i class="fas fa-trash"></i>
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- hidden file input for image replacement -->
    <input type="file" id="testimReplaceInput" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="submitReplaceImage(this)">

    <div class="card" style="margin-top:1.5rem;padding:1.25rem;background:#f8faff;border-color:#dce6f0;">
      <ul style="margin:0;padding-left:1.25rem;color:#4a6080;font-size:.88rem;line-height:1.9;">
        <li><strong>Upload:</strong> select multiple images at once — each gets its own headline</li>
        <li><strong>Edit headline:</strong> click the caption text directly to edit it inline</li>
        <li><strong>Replace photo:</strong> click on the image to upload a new one</li>
        <li><strong>Reorder:</strong> use ↑ ↓ buttons to change display order</li>
        <li><strong>Show/Hide:</strong> toggle the switch to hide without deleting</li>
      </ul>
    </div>

<style>
.testim-admin-card:hover .testim-img-overlay { display:flex !important; }
.testim-inactive { opacity:.6; }
</style>


<!-- ═══════════════════════════════════════════════════════════
     TAB: MEET THE TEAM
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'team'): ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-users"></i> Meet the Team</h1>
        <p class="text-muted"><?= count($adminTeam) ?> member<?= count($adminTeam)!==1?'s':'' ?> · <a href="<?= APP_URL ?>/team.php" target="_blank" style="color:#005DDB;">View public page →</a></p>
      </div>
    </div>

    <!-- ── Add Team Member ─────────────────────────────────── -->
    <div class="card" style="margin-bottom:1.5rem;">
      <h3 style="margin:0 0 1rem;"><i class="fas fa-user-plus" style="color:#005DDB;"></i> Add Team Member</h3>
      <div id="teamAddResult"></div>

      <div style="display:grid;grid-template-columns:auto 1fr;gap:1.5rem;align-items:start;">

        <!-- Photo picker -->
        <div>
          <div id="teamPhotoDrop"
               onclick="document.getElementById('teamPhotoInput').click()"
               style="width:120px;height:120px;border-radius:50%;border:3px dashed #c8d8ea;background:#f1f5f9;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;position:relative;transition:border-color .2s;"
               onmouseover="this.style.borderColor='#005DDB'" onmouseout="if(!this.querySelector('img'))this.style.borderColor='#c8d8ea'">
            <div id="teamPhotoPlaceholder" style="text-align:center;pointer-events:none;">
              <i class="fas fa-camera" style="font-size:1.5rem;color:#94a3b8;display:block;margin-bottom:.3rem;"></i>
              <span style="font-size:.7rem;color:#94a3b8;">Add Photo</span>
            </div>
          </div>
          <input type="file" id="teamPhotoInput" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="previewTeamPhoto(this)">
          <p style="font-size:.72rem;color:#6a7f96;margin:.5rem 0 0;text-align:center;width:120px;">Optional</p>
        </div>

        <!-- Fields -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
          <div>
            <label class="form-label">Full Name <span style="color:#dc3545;">*</span></label>
            <input type="text" id="tmName" class="form-control" placeholder="e.g. Brian Khumalo" required>
          </div>
          <div>
            <label class="form-label">Position / Title <span style="color:#dc3545;">*</span></label>
            <input type="text" id="tmPosition" class="form-control" placeholder="e.g. Senior Sales Agent">
          </div>
          <div>
            <label class="form-label">Email <span style="color:#6a7f96;font-weight:400;">(optional)</span></label>
            <input type="email" id="tmEmail" class="form-control" placeholder="name@auctionhubgroup.co.za">
          </div>
          <div>
            <label class="form-label">Phone <span style="color:#6a7f96;font-weight:400;">(optional)</span></label>
            <input type="tel" id="tmPhone" class="form-control" placeholder="+27 10 516 0681">
          </div>
          <div style="grid-column:1/-1;">
            <label class="form-label">Short Bio <span style="color:#6a7f96;font-weight:400;">(optional)</span></label>
            <textarea id="tmBio" class="form-control" rows="2" placeholder="A brief description about this team member…" style="resize:vertical;"></textarea>
          </div>
          <div style="grid-column:1/-1;display:flex;gap:.75rem;align-items:center;">
            <button class="btn btn-primary" onclick="submitAddTeamMember()">
              <i class="fas fa-user-plus"></i> Add Member
            </button>
            <span id="teamAddProgress" style="font-size:.85rem;color:#6a7f96;"></span>
          </div>
        </div>

      </div>
    </div>

    <!-- ── Existing Members Grid ────────────────────────────── -->
    <h3 style="margin:1.5rem 0 .75rem;"><i class="fas fa-id-badge"></i> Team Members
      <span style="font-size:.85rem;font-weight:400;color:#6a7f96;margin-left:.5rem;"><?= count($adminTeam) ?> total</span>
    </h3>

    <?php if (empty($adminTeam)): ?>
    <div class="card" style="text-align:center;padding:3rem;color:#6a7f96;">
      <i class="fas fa-users" style="font-size:2.5rem;margin-bottom:1rem;display:block;color:#c8d8ea;"></i>
      No team members yet. Add your first one above.
    </div>
    <?php else: ?>
    <div id="teamGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.25rem;">
      <?php foreach ($adminTeam as $tm): ?>
      <?php $initials = implode('', array_map(fn($w)=>mb_strtoupper(mb_substr($w,0,1)), explode(' ', trim($tm['name'])))); $initials = mb_substr($initials,0,2); ?>
      <div class="team-admin-card <?= $tm['is_active'] ? '' : 'team-inactive' ?>" data-id="<?= $tm['id'] ?>" data-order="<?= $tm['sort_order'] ?>"
           style="background:#fff;border-radius:14px;border:1px solid #dce6f0;box-shadow:0 2px 8px rgba(0,31,92,0.07);overflow:hidden;display:flex;flex-direction:column;">

        <!-- Photo header -->
        <div style="background:linear-gradient(135deg,#001F5C,#005DDB);padding:1.5rem;text-align:center;position:relative;">
          <?php if (!$tm['is_active']): ?>
          <div style="position:absolute;top:.6rem;left:.6rem;background:#f59e0b;color:#fff;font-size:.65rem;font-weight:700;padding:.15rem .45rem;border-radius:4px;text-transform:uppercase;">Hidden</div>
          <?php endif; ?>
          <div style="position:relative;display:inline-block;" onclick="replaceTeamPhoto(<?= $tm['id'] ?>)" title="Click to replace photo" class="team-photo-wrap">
            <?php if ($tm['photo_url']): ?>
            <img src="<?= e(imgHost($tm['photo_url'])) ?>" alt="<?= e($tm['name']) ?>"
                 style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.3);cursor:pointer;">
            <?php else: ?>
            <div style="width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,.15);border:3px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:700;color:#fff;cursor:pointer;font-family:'Barlow Condensed',sans-serif;">
              <?= e($initials) ?>
            </div>
            <?php endif; ?>
            <div class="team-photo-overlay" style="position:absolute;inset:0;border-radius:50%;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;cursor:pointer;">
              <i class="fas fa-camera" style="color:#fff;font-size:.9rem;"></i>
            </div>
          </div>
          <!-- Name inline edit -->
          <div class="team-field" data-id="<?= $tm['id'] ?>" data-field="name"
               onclick="inlineEditTeamField(this,<?= $tm['id'] ?>,'name')"
               style="color:#fff;font-size:1rem;font-weight:700;margin-top:.6rem;cursor:pointer;border-bottom:1px dashed transparent;transition:border-color .2s;" title="Click to edit">
            <?= e($tm['name']) ?>
          </div>
          <!-- Position inline edit -->
          <div class="team-field" data-id="<?= $tm['id'] ?>" data-field="position"
               onclick="inlineEditTeamField(this,<?= $tm['id'] ?>,'position')"
               style="color:rgba(255,255,255,.75);font-size:.8rem;margin-top:.25rem;cursor:pointer;border-bottom:1px dashed transparent;transition:border-color .2s;" title="Click to edit">
            <?= e($tm['position']) ?>
          </div>
        </div>

        <!-- Body: bio, email, phone -->
        <div style="padding:.9rem 1rem;flex:1;display:flex;flex-direction:column;gap:.5rem;">

          <!-- Bio -->
          <div class="team-field" data-id="<?= $tm['id'] ?>" data-field="bio"
               onclick="inlineEditTeamField(this,<?= $tm['id'] ?>,'bio',true)"
               style="font-size:.82rem;color:#4a6080;line-height:1.5;cursor:pointer;border-bottom:1px dashed transparent;min-height:1.5em;" title="Click to edit bio">
            <?= $tm['bio'] ? e($tm['bio']) : '<span style="color:#c0cfe0;font-style:italic;">Add a bio…</span>' ?>
          </div>

          <!-- Email -->
          <div style="display:flex;align-items:center;gap:.5rem;">
            <i class="fas fa-envelope" style="color:#94a3b8;font-size:.8rem;width:14px;text-align:center;flex-shrink:0;"></i>
            <div class="team-field" data-id="<?= $tm['id'] ?>" data-field="email"
                 onclick="inlineEditTeamField(this,<?= $tm['id'] ?>,'email')"
                 style="font-size:.82rem;color:<?= $tm['email'] ? '#005BA1' : '#c0cfe0' ?>;cursor:pointer;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="Click to edit email">
              <?= $tm['email'] ? e($tm['email']) : 'Add email…' ?>
            </div>
          </div>

          <!-- Phone -->
          <div style="display:flex;align-items:center;gap:.5rem;">
            <i class="fas fa-phone" style="color:#94a3b8;font-size:.8rem;width:14px;text-align:center;flex-shrink:0;"></i>
            <div class="team-field" data-id="<?= $tm['id'] ?>" data-field="phone"
                 onclick="inlineEditTeamField(this,<?= $tm['id'] ?>,'phone')"
                 style="font-size:.82rem;color:<?= $tm['phone'] ? '#001F5C' : '#c0cfe0' ?>;cursor:pointer;flex:1;" title="Click to edit phone">
              <?= $tm['phone'] ? e($tm['phone']) : 'Add phone…' ?>
            </div>
          </div>

          <!-- Controls -->
          <div style="display:flex;align-items:center;justify-content:space-between;gap:.4rem;margin-top:auto;padding-top:.5rem;border-top:1px solid #f1f5f9;">
            <div style="display:flex;gap:.3rem;">
              <button class="btn btn-sm" style="padding:.25rem .5rem;background:#f1f5f9;border:1px solid #dce6f0;color:#001F5C;" onclick="moveTeamMember(<?= $tm['id'] ?>,-1)" title="Move up">↑</button>
              <button class="btn btn-sm" style="padding:.25rem .5rem;background:#f1f5f9;border:1px solid #dce6f0;color:#001F5C;" onclick="moveTeamMember(<?= $tm['id'] ?>,1)" title="Move down">↓</button>
            </div>
            <label class="toggle-switch" style="margin:0;" title="Show/Hide">
              <input type="checkbox" <?= $tm['is_active'] ? 'checked' : '' ?> onchange="toggleTeamMember(<?= $tm['id'] ?>,this.checked,this)">
              <span class="toggle-slider"></span>
            </label>
            <button class="btn btn-danger btn-sm" style="padding:.25rem .5rem;" onclick="deleteTeamMember(<?= $tm['id'] ?>)" title="Delete">
              <i class="fas fa-trash"></i>
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Hidden file input for photo replacement -->
    <input type="file" id="teamReplaceInput" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="submitReplaceTeamPhoto(this)">

<style>
.team-admin-card:hover .team-photo-overlay { display:flex !important; }
.team-inactive { opacity:.6; }
.team-photo-wrap:hover .team-photo-overlay { display:flex !important; }
</style>


<!-- ═══════════════════════════════════════════════════════════
     TAB: NEWS & REVIEWS
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'news'): ?>

<div class="dashboard-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
  <div>
    <h1><i class="fas fa-newspaper"></i> News &amp; Reviews</h1>
    <p class="text-muted"><?= count($adminNewsPosts) ?> post<?= count($adminNewsPosts)!==1?'s':'' ?> · <a href="<?= APP_URL ?>/news.php" target="_blank" style="color:#005DDB;">View public page →</a></p>
  </div>
  <button class="btn btn-primary" onclick="document.getElementById('newPostModal').style.display='flex'">
    <i class="fas fa-plus"></i> New Post
  </button>
</div>

<!-- Sub-tabs: Posts | Categories | RSS Feeds -->
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;border-bottom:2px solid var(--border-color,#e8edf2);flex-wrap:wrap;">
  <button onclick="switchNewsTab('posts')" id="ntab-posts" class="ntab ntab-active" style="padding:.6rem 1.25rem;border:none;background:none;cursor:pointer;font-weight:700;font-size:.88rem;border-bottom:2px solid #005DDB;margin-bottom:-2px;color:#005DDB;">Posts</button>
  <button onclick="switchNewsTab('categories')" id="ntab-categories" class="ntab" style="padding:.6rem 1.25rem;border:none;background:none;cursor:pointer;font-weight:600;font-size:.88rem;color:#667;border-bottom:2px solid transparent;margin-bottom:-2px;">Categories</button>
  <button onclick="switchNewsTab('rss')" id="ntab-rss" class="ntab" style="padding:.6rem 1.25rem;border:none;background:none;cursor:pointer;font-weight:600;font-size:.88rem;color:#667;border-bottom:2px solid transparent;margin-bottom:-2px;">RSS Feeds</button>
</div>

<!-- Posts list -->
<div id="news-tab-posts">
<?php if (empty($adminNewsPosts)): ?>
  <div style="text-align:center;padding:3rem;background:#f8f9fb;border-radius:12px;color:#999;">
    <i class="fas fa-newspaper" style="font-size:2.5rem;color:#dde3ec;display:block;margin-bottom:.75rem;"></i>
    <p>No posts yet. Click <strong>New Post</strong> to create your first article.</p>
  </div>
<?php else: ?>
  <div style="overflow-x:auto;">
  <table class="data-table" style="width:100%;border-collapse:collapse;font-size:.88rem;">
    <thead>
      <tr style="background:#f0f6ff;">
        <th style="padding:.7rem .9rem;text-align:left;font-weight:700;color:#001F5C;white-space:nowrap;">Title</th>
        <th style="padding:.7rem .9rem;text-align:left;font-weight:700;color:#001F5C;">Category</th>
        <th style="padding:.7rem .9rem;text-align:left;font-weight:700;color:#001F5C;">Type</th>
        <th style="padding:.7rem .9rem;text-align:left;font-weight:700;color:#001F5C;">Published</th>
        <th style="padding:.7rem .9rem;text-align:left;font-weight:700;color:#001F5C;">Views</th>
        <th style="padding:.7rem .9rem;text-align:left;font-weight:700;color:#001F5C;">Status</th>
        <th style="padding:.7rem .9rem;text-align:left;font-weight:700;color:#001F5C;">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($adminNewsPosts as $np): ?>
    <tr style="border-bottom:1px solid #f0f4f8;" id="news-row-<?= $np['id'] ?>">
      <td style="padding:.65rem .9rem;max-width:280px;">
        <div style="font-weight:600;color:#1a2a40;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($np['title']) ?></div>
        <?php if ($np['is_featured']): ?>
        <span style="font-size:.68rem;background:#ffd54f;color:#001F5C;padding:.1rem .5rem;border-radius:4px;font-weight:700;">Featured</span>
        <?php endif; ?>
      </td>
      <td style="padding:.65rem .9rem;">
        <?php if ($np['cat_name']): ?>
        <span style="background:<?= htmlspecialchars($np['cat_color']) ?>20;color:<?= htmlspecialchars($np['cat_color']) ?>;font-size:.75rem;font-weight:700;padding:.2rem .6rem;border-radius:4px;"><?= htmlspecialchars($np['cat_name']) ?></span>
        <?php else: ?><span style="color:#ccc;">—</span><?php endif; ?>
      </td>
      <td style="padding:.65rem .9rem;text-transform:capitalize;font-size:.82rem;color:#667;"><?= htmlspecialchars($np['post_type']) ?></td>
      <td style="padding:.65rem .9rem;font-size:.82rem;color:#667;white-space:nowrap;"><?= $np['published_at'] ? safeDate('d M Y', $np['published_at']) : '—' ?></td>
      <td style="padding:.65rem .9rem;font-size:.82rem;color:#667;"><?= number_format($np['view_count']) ?></td>
      <td style="padding:.65rem .9rem;">
        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
          <input type="checkbox" <?= $np['is_active']?'checked':'' ?> onchange="toggleNewsPost(<?= $np['id'] ?>,this.checked)"
                 style="width:16px;height:16px;cursor:pointer;accent-color:#005DDB;">
          <span style="font-size:.78rem;color:<?= $np['is_active']?'#2e7d32':'#c62828' ?>;"><?= $np['is_active']?'Active':'Draft' ?></span>
        </label>
      </td>
      <td style="padding:.65rem .9rem;">
        <div style="display:flex;gap:.35rem;flex-wrap:wrap;">
          <a href="<?= APP_URL ?>/news-post.php?slug=<?= urlencode($np['slug']) ?>" target="_blank"
             style="padding:.3rem .65rem;background:#f0f6ff;color:#005DDB;border-radius:6px;font-size:.75rem;text-decoration:none;font-weight:600;">
            <i class="fas fa-eye"></i>
          </a>
          <button onclick="editNewsPost(<?= htmlspecialchars(json_encode($np)) ?>)"
                  style="padding:.3rem .65rem;background:#e8f0ff;color:#005DDB;border:none;border-radius:6px;font-size:.75rem;cursor:pointer;font-weight:600;">
            <i class="fas fa-edit"></i>
          </button>
          <button onclick="setFeaturedPost(<?= $np['id'] ?>,<?= $np['is_featured']?0:1 ?>)"
                  style="padding:.3rem .65rem;background:<?= $np['is_featured']?'#ffd54f':'#f5f5f5' ?>;color:<?= $np['is_featured']?'#001F5C':'#999' ?>;border:none;border-radius:6px;font-size:.75rem;cursor:pointer;"
                  title="<?= $np['is_featured']?'Remove Featured':'Set Featured' ?>">
            <i class="fas fa-star"></i>
          </button>
          <button onclick="deleteNewsPost(<?= $np['id'] ?>)"
                  style="padding:.3rem .65rem;background:#fff0f0;color:#c62828;border:none;border-radius:6px;font-size:.75rem;cursor:pointer;font-weight:600;">
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>

<!-- Categories list -->
<div id="news-tab-categories" style="display:none;">
  <div style="display:flex;justify-content:flex-end;margin-bottom:1rem;">
    <button onclick="addNewsCategory()" class="btn btn-outline" style="font-size:.85rem;"><i class="fas fa-plus"></i> Add Category</button>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;">
    <?php foreach ($adminNewsCategories as $nc): ?>
    <div style="background:#fff;border:1px solid var(--border-color,#e8edf2);border-radius:12px;padding:1rem;display:flex;flex-direction:column;gap:.5rem;">
      <div style="display:flex;align-items:center;gap:.5rem;">
        <span style="width:32px;height:32px;border-radius:8px;background:<?= htmlspecialchars($nc['color']) ?>20;display:flex;align-items:center;justify-content:center;">
          <i class="fas <?= htmlspecialchars($nc['icon']) ?>" style="color:<?= htmlspecialchars($nc['color']) ?>;font-size:.9rem;"></i>
        </span>
        <strong style="font-size:.9rem;color:#1a2a40;"><?= htmlspecialchars($nc['name']) ?></strong>
      </div>
      <p style="font-size:.78rem;color:#999;margin:0;"><?= htmlspecialchars(mb_substr($nc['description']??'',0,80)) ?></p>
      <a href="<?= APP_URL ?>/news.php?cat=<?= urlencode($nc['slug']) ?>" target="_blank" style="font-size:.75rem;color:#005DDB;text-decoration:none;">View posts →</a>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- RSS Feeds list -->
<div id="news-tab-rss" style="display:none;">
  <div style="display:flex;justify-content:flex-end;margin-bottom:1rem;">
    <button onclick="addRssFeed()" class="btn btn-outline" style="font-size:.85rem;"><i class="fas fa-plus"></i> Add Feed</button>
  </div>
  <?php if (empty($adminRssFeeds)): ?>
  <p style="color:#999;font-size:.9rem;">No RSS feeds configured.</p>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:.75rem;">
    <?php foreach ($adminRssFeeds as $rf): ?>
    <div style="background:#fff;border:1px solid var(--border-color,#e8edf2);border-radius:12px;padding:1rem 1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
      <div style="flex:1;min-width:200px;">
        <strong style="font-size:.9rem;color:#1a2a40;"><?= htmlspecialchars($rf['name']) ?></strong>
        <div style="font-size:.75rem;color:#999;margin-top:.2rem;word-break:break-all;"><?= htmlspecialchars($rf['feed_url']) ?></div>
        <div style="display:flex;gap:.75rem;margin-top:.3rem;font-size:.75rem;color:#667;flex-wrap:wrap;">
          <?php if ($rf['cat_name']): ?><span><?= htmlspecialchars($rf['cat_name']) ?></span><?php endif; ?>
          <span>Every <?= $rf['fetch_interval_hours'] ?>h</span>
          <?php if ($rf['last_fetched_at']): ?><span>Last: <?= safeDate('d M H:i', $rf['last_fetched_at']) ?></span><?php endif; ?>
          <span><?= $rf['items_imported'] ?> imported</span>
        </div>
        <?php if ($rf['last_error']): ?>
        <div style="font-size:.73rem;color:#c62828;margin-top:.3rem;"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars(mb_substr($rf['last_error'],0,120)) ?></div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:.5rem;flex-shrink:0;">
        <button onclick="importRssFeed(<?= $rf['id'] ?>,this)" class="btn btn-outline" style="font-size:.8rem;padding:.4rem .85rem;">
          <i class="fas fa-sync"></i> Import Now
        </button>
        <button onclick="deleteRssFeed(<?= $rf['id'] ?>)" style="padding:.4rem .75rem;background:#fff0f0;color:#c62828;border:none;border-radius:8px;cursor:pointer;font-size:.8rem;">
          <i class="fas fa-trash"></i>
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- New/Edit Post Modal -->
<div id="newPostModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:flex-start;justify-content:center;padding:1rem;overflow-y:auto;">
  <div style="background:#fff;border-radius:18px;width:100%;max-width:780px;padding:2rem;margin:1rem auto;position:relative;">
    <button onclick="closePostModal()" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.3rem;cursor:pointer;color:#667;">&times;</button>
    <h3 id="postModalTitle" style="font-size:1.1rem;font-weight:800;color:#001F5C;margin-bottom:1.5rem;">New Post</h3>
    <form id="postForm" onsubmit="submitNewsPost(event)" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_news_post">
      <input type="hidden" name="id" id="postId" value="">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
        <div style="grid-column:1/-1;">
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">Title *</label>
          <input type="text" name="title" id="postTitle" required placeholder="Post title…"
                 style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.92rem;color:#1a2a40;outline:none;"
                 onfocus="this.style.borderColor='#005DDB'" onblur="this.style.borderColor='#dde3ec'">
        </div>
        <div>
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">Category</label>
          <select name="category_id" id="postCategory" style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.88rem;color:#1a2a40;background:#fff;">
            <option value="">— None —</option>
            <?php foreach ($adminNewsCategories as $nc): ?>
            <option value="<?= $nc['id'] ?>"><?= htmlspecialchars($nc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">Post Type</label>
          <select name="post_type" id="postType" style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.88rem;color:#1a2a40;background:#fff;">
            <option value="article">Article</option>
            <option value="review">Car Review</option>
            <option value="news">News</option>
          </select>
        </div>
        <div>
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">Published Date</label>
          <input type="datetime-local" name="published_at" id="postPubAt"
                 style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.88rem;color:#1a2a40;">
        </div>
        <div>
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">Featured Image</label>
          <input type="file" name="featured_image" id="postImage" accept="image/*" onchange="previewPostImage(this)"
                 style="width:100%;padding:.5rem;border:1px solid #dde3ec;border-radius:10px;font-size:.82rem;">
          <div id="postImagePreview" style="margin-top:.5rem;"></div>
          <input type="hidden" name="featured_image_url" id="postImageUrl">
        </div>
        <div>
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">Or Image URL</label>
          <input type="url" name="" id="postImageUrlInput" placeholder="https://…" onchange="document.getElementById('postImageUrl').value=this.value"
                 style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.82rem;color:#1a2a40;">
        </div>
        <div style="grid-column:1/-1;">
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">
            <i class="fas fa-play-circle" style="color:#e74c3c;"></i> Video URL <span style="font-weight:400;color:#999;">(YouTube, direct MP4 — optional)</span>
          </label>
          <input type="url" name="video_url" id="postVideoUrl" placeholder="https://www.youtube.com/watch?v=… or direct .mp4 URL"
                 style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.88rem;color:#1a2a40;"
                 onfocus="this.style.borderColor='#e74c3c'" onblur="this.style.borderColor='#dde3ec'">
          <p style="font-size:.73rem;color:#999;margin-top:.3rem;">Paste a YouTube watch URL — it will auto-convert to embed. The video thumbnail becomes the featured image if no image is uploaded.</p>
        </div>
        <div style="grid-column:1/-1;">
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">Excerpt / Summary</label>
          <textarea name="excerpt" id="postExcerpt" rows="2" placeholder="Short summary shown on listing pages…"
                    style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.88rem;resize:vertical;font-family:inherit;color:#1a2a40;"></textarea>
        </div>
        <div style="grid-column:1/-1;">
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">
            Content <span style="font-weight:400;color:#999;">(HTML supported)</span>
          </label>
          <div style="border:1px solid #dde3ec;border-radius:10px;overflow:hidden;">
            <!-- Toolbar -->
            <div style="display:flex;gap:.25rem;padding:.5rem .75rem;background:#f8f9fb;border-bottom:1px solid #dde3ec;flex-wrap:wrap;">
              <?php foreach ([
                ['B','bold','<b>…</b>'],['I','italic','<em>…</em>'],['U','underline','<u>…</u>'],
                ['H2','h2','<h2>…</h2>'],['H3','h3','<h3>…</h3>'],
                ['UL','ul','<ul>\n<li>…</li>\n</ul>'],['OL','ol','<ol>\n<li>…</li>\n</ol>'],
                ['Link','link','<a href="URL">…</a>'],['IMG','img','<img src="URL" alt="description">'],
                ['Quote','quote','<blockquote>…</blockquote>'],
              ] as [$label,$cmd,$tpl]): ?>
              <button type="button" onclick="insertHtml('postContent','<?= addslashes($tpl) ?>')"
                      style="padding:.25rem .5rem;border:1px solid #dde3ec;border-radius:5px;background:#fff;font-size:.75rem;font-weight:700;cursor:pointer;color:#334155;" title="<?= $cmd ?>">
                <?= htmlspecialchars($label) ?>
              </button>
              <?php endforeach; ?>
              <span style="width:1px;background:#dde3ec;align-self:stretch;margin:0 .1rem;display:inline-block;"></span>
              <button type="button" onclick="insertSampleArticle()"
                      style="padding:.25rem .6rem;border:1px solid #005DDB;border-radius:5px;background:#005DDB;color:#fff;font-size:.72rem;font-weight:700;cursor:pointer;" title="Insert sample article HTML">
                <i class="fas fa-file-alt"></i> Sample
              </button>
            </div>
            <textarea name="content" id="postContent" rows="12"
                      placeholder="Write your article HTML here…&#10;&#10;Supports full HTML: headings, paragraphs, images, lists, blockquotes, links, tables."
                      style="width:100%;padding:1rem;border:none;font-size:.88rem;resize:vertical;font-family:'Courier New',monospace;color:#1a2a40;line-height:1.6;min-height:200px;"></textarea>
          </div>
        </div>
        <div>
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">Meta Title (SEO)</label>
          <input type="text" name="meta_title" id="postMetaTitle" placeholder="SEO title (60 chars max)…" maxlength="200"
                 style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.82rem;">
        </div>
        <div>
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">Meta Description (SEO)</label>
          <input type="text" name="meta_description" id="postMetaDesc" placeholder="SEO description (155 chars max)…" maxlength="350"
                 style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.82rem;">
        </div>
        <div style="display:flex;gap:1.5rem;align-items:center;grid-column:1/-1;">
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.88rem;font-weight:600;">
            <input type="checkbox" name="is_featured" id="postFeatured" style="width:16px;height:16px;accent-color:#005DDB;"> Set as Featured Post
          </label>
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.88rem;font-weight:600;">
            <input type="checkbox" name="allow_comments" id="postComments" checked style="width:16px;height:16px;accent-color:#005DDB;"> Allow Comments
          </label>
        </div>
      </div>
      <div id="postFormMsg" style="display:none;padding:.65rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.88rem;"></div>
      <div style="display:flex;justify-content:flex-end;gap:.75rem;">
        <button type="button" onclick="closePostModal()" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary" id="postSubmitBtn">Publish Post</button>
      </div>
    </form>
  </div>
</div>

<!-- Add RSS Feed Modal -->
<div id="rssFeedModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:#fff;border-radius:18px;width:100%;max-width:500px;padding:2rem;position:relative;">
    <button onclick="document.getElementById('rssFeedModal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.3rem;cursor:pointer;color:#667;">&times;</button>
    <h3 style="font-size:1.05rem;font-weight:800;color:#001F5C;margin-bottom:1.5rem;">Add RSS Feed</h3>
    <form id="rssFeedForm" onsubmit="submitRssFeed(event)">
      <input type="hidden" name="action" value="save_rss_feed">
      <div style="margin-bottom:1rem;">
        <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">Feed Name *</label>
        <input type="text" name="name" required placeholder="e.g. Wheels24" style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.88rem;">
      </div>
      <div style="margin-bottom:1rem;">
        <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">RSS/Atom URL *</label>
        <input type="url" name="feed_url" required placeholder="https://example.com/feed/" style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.88rem;">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
        <div>
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">Category</label>
          <select name="category_id" style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.88rem;background:#fff;">
            <option value="">— None —</option>
            <?php foreach ($adminNewsCategories as $nc): ?>
            <option value="<?= $nc['id'] ?>"><?= htmlspecialchars($nc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.35rem;">Fetch Every (hours)</label>
          <input type="number" name="fetch_interval_hours" value="6" min="1" max="168" style="width:100%;padding:.7rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.88rem;">
        </div>
      </div>
      <div id="rssFeedMsg" style="display:none;padding:.65rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.88rem;"></div>
      <div style="display:flex;justify-content:flex-end;gap:.75rem;">
        <button type="button" onclick="document.getElementById('rssFeedModal').style.display='none'" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Feed</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Category Modal -->
<div id="newCatModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:#fff;border-radius:18px;width:100%;max-width:480px;padding:2rem;position:relative;">
    <button onclick="closeNewsCatModal()" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.3rem;cursor:pointer;color:#667;">&times;</button>
    <h3 style="font-size:1.05rem;font-weight:800;color:#001F5C;margin-bottom:1.25rem;"><i class="fas fa-folder-plus"></i> Add Category</h3>
    <form id="catForm" onsubmit="submitNewsCategory(event)">
      <input type="hidden" name="action" value="save_news_category">
      <div style="margin-bottom:.85rem;">
        <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.3rem;">Name *</label>
        <input type="text" name="cat_name" id="catName" required placeholder="e.g. Market News"
               style="width:100%;padding:.65rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.9rem;color:#1a2a40;">
      </div>
      <div style="margin-bottom:.85rem;">
        <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.3rem;">Description</label>
        <input type="text" name="cat_description" placeholder="Short description…"
               style="width:100%;padding:.65rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.9rem;color:#1a2a40;">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.85rem;">
        <div>
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.3rem;">Colour</label>
          <input type="color" name="cat_color" value="#005DDB"
                 style="width:100%;height:42px;border:1px solid #dde3ec;border-radius:10px;padding:.25rem;cursor:pointer;">
        </div>
        <div>
          <label style="font-size:.82rem;font-weight:700;color:#334155;display:block;margin-bottom:.3rem;">Icon (FA class)</label>
          <input type="text" name="cat_icon" value="fa-newspaper" placeholder="fa-newspaper"
                 style="width:100%;padding:.65rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.9rem;color:#1a2a40;">
        </div>
      </div>
      <div id="catFormMsg" style="display:none;padding:.6rem 1rem;border-radius:8px;font-size:.85rem;margin-bottom:.75rem;"></div>
      <div style="display:flex;gap:.75rem;justify-content:flex-end;">
        <button type="button" onclick="closeNewsCatModal()" class="btn btn-outline">Cancel</button>
        <button type="submit" id="catSubmitBtn" class="btn btn-primary">Add Category</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchNewsTab(tab){
  ['posts','categories','rss'].forEach(t=>{
    document.getElementById('news-tab-'+t).style.display = t===tab?'':'none';
    const btn = document.getElementById('ntab-'+t);
    if(t===tab){ btn.style.borderBottomColor='#005DDB'; btn.style.color='#005DDB'; btn.style.fontWeight='700'; }
    else { btn.style.borderBottomColor='transparent'; btn.style.color='#667'; btn.style.fontWeight='600'; }
  });
}

function addNewsCategory(){ document.getElementById('newCatModal').style.display='flex'; }
function closeNewsCatModal(){
  document.getElementById('newCatModal').style.display='none';
  document.getElementById('catForm').reset();
  document.getElementById('catFormMsg').style.display='none';
}
async function submitNewsCategory(e){
  e.preventDefault();
  const btn = document.getElementById('catSubmitBtn');
  const msg = document.getElementById('catFormMsg');
  btn.disabled=true; btn.textContent='Saving…';
  const fd = new FormData(e.target);
  try {
    const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:fd});
    const d = await r.json();
    if(d.success){
      msg.style.background='rgba(110,255,158,.15)'; msg.style.color='#2e7d32';
      msg.textContent=d.message||'Category added!'; msg.style.display='block';
      setTimeout(()=>location.reload(),800);
    } else {
      msg.style.background='rgba(255,107,107,.15)'; msg.style.color='#c62828';
      msg.textContent=d.message||'Error.'; msg.style.display='block';
      btn.disabled=false; btn.textContent='Add Category';
    }
  } catch {
    msg.style.background='rgba(255,107,107,.15)'; msg.style.color='#c62828';
    msg.textContent='Network error.'; msg.style.display='block';
    btn.disabled=false; btn.textContent='Add Category';
  }
}

function closePostModal(){
  document.getElementById('newPostModal').style.display='none';
  document.getElementById('postForm').reset();
  document.getElementById('postId').value='';
  document.getElementById('postModalTitle').textContent='New Post';
  document.getElementById('postImagePreview').innerHTML='';
  document.getElementById('postImageUrl').value='';
  document.getElementById('postImageUrlInput').value='';
  document.getElementById('postVideoUrl').value='';
  document.getElementById('postSubmitBtn').textContent='Publish Post';
  document.getElementById('postPubAt').value = new Date().toISOString().slice(0,16);
}

function editNewsPost(p){
  document.getElementById('postId').value = p.id;
  document.getElementById('postTitle').value = p.title||'';
  document.getElementById('postCategory').value = p.category_id||'';
  document.getElementById('postType').value = p.post_type||'article';
  document.getElementById('postExcerpt').value = p.excerpt||'';
  document.getElementById('postContent').value = p.content||'';
  document.getElementById('postMetaTitle').value = p.meta_title||'';
  document.getElementById('postMetaDesc').value = p.meta_description||'';
  document.getElementById('postFeatured').checked = !!p.is_featured;
  document.getElementById('postComments').checked = !!p.allow_comments;
  document.getElementById('postImageUrl').value = p.featured_image_url||'';
  document.getElementById('postImageUrlInput').value = p.featured_image_url||'';
  document.getElementById('postVideoUrl').value = p.video_url||'';
  if(p.featured_image_url){
    document.getElementById('postImagePreview').innerHTML = '<img src="'+p.featured_image_url+'" style="max-height:80px;border-radius:8px;margin-top:.4rem;">';
  }
  if(p.published_at) document.getElementById('postPubAt').value = p.published_at.replace(' ','T').slice(0,16);
  document.getElementById('postModalTitle').textContent = 'Edit Post';
  document.getElementById('postSubmitBtn').textContent = 'Save Changes';
  document.getElementById('newPostModal').style.display = 'flex';
}

function previewPostImage(input){
  const file = input.files[0]; if(!file) return;
  const r = new FileReader();
  r.onload = e => { document.getElementById('postImagePreview').innerHTML = '<img src="'+e.target.result+'" style="max-height:80px;border-radius:8px;margin-top:.4rem;">'; };
  r.readAsDataURL(file);
}

function insertHtml(fieldId, tpl){
  const ta = document.getElementById(fieldId);
  const s = ta.selectionStart, e = ta.selectionEnd;
  const sel = ta.value.substring(s,e) || '…';
  const ins = tpl.replace('…', sel);
  ta.value = ta.value.substring(0,s) + ins + ta.value.substring(e);
  ta.focus(); ta.selectionStart = ta.selectionEnd = s + ins.length;
}

function insertSampleArticle(){
  if(document.getElementById('postContent').value.trim() && !confirm('This will replace the current content. Continue?')) return;
  document.getElementById('postContent').value = `<p>Opening paragraph — introduce the topic in 2-3 compelling sentences. This is displayed larger than the rest of the article, so make it engaging. Summarise the key point the reader will learn.</p>

<h2>Section Heading (use for major topic shifts)</h2>

<p>Body paragraph. Write naturally in short paragraphs of 3-5 sentences. Each paragraph should cover one idea. Avoid walls of text — white space helps readers stay engaged.</p>

<p>You can <strong>bold important phrases</strong> and use <em>italics for emphasis</em>. Link to related pages with <a href="/news.php">anchor text like this</a>.</p>

<blockquote>Use a blockquote for a stand-out quote, statistic, or expert opinion. "Up to 60% of South African car buyers overpay because they don't use auctions." — AHG Market Report</blockquote>

<h2>Second Section Heading</h2>

<p>Continue your article here. If you have a numbered list of steps or tips, use an ordered list:</p>

<ol>
  <li><strong>Step one:</strong> Register as a bidder on AHG — takes 5 minutes online.</li>
  <li><strong>Step two:</strong> Browse upcoming auctions and shortlist vehicles.</li>
  <li><strong>Step three:</strong> Attend the inspection day and check DEKRA reports.</li>
  <li><strong>Step four:</strong> Place your bids confidently knowing the reserve price.</li>
</ol>

<h3>Sub-heading (for detail within a section)</h3>

<p>For unordered bullet points, use a UL list:</p>

<ul>
  <li>DEKRA-certified vehicles with full inspection history</li>
  <li>In-house finance available — no credit checks required</li>
  <li>Nationwide delivery to your door</li>
  <li>Transparent bidding — no hidden fees</li>
</ul>

<img src="https://placehold.co/800x450/001F5C/fff?text=Replace+with+your+image" alt="Descriptive alt text for SEO">

<p>Caption or context for the image above. Always use a descriptive alt attribute on images — it helps both accessibility and Google rankings.</p>

<h2>Conclusion</h2>

<p>Wrap up with a clear takeaway. What should the reader do next? Guide them towards an action — visiting an auction, registering, or reading another article.</p>

<p>Ready to find your next vehicle? <a href="/auctions.php"><strong>Browse our live auctions</strong></a> or <a href="/login.php?tab=register">register as a bidder</a> — it only takes a few minutes.</p>`;
}

async function submitNewsPost(e){
  e.preventDefault();
  const btn = document.getElementById('postSubmitBtn');
  const msg = document.getElementById('postFormMsg');
  btn.disabled=true; btn.textContent='Saving…';
  const fd = new FormData(e.target);
  // If url input was filled but no file, use it as featured_image_url
  const urlInput = document.getElementById('postImageUrlInput').value;
  if(urlInput && !e.target.featured_image.files[0]) fd.set('featured_image_url', urlInput);
  // Convert YouTube watch URL to embed URL
  let vUrl = (document.getElementById('postVideoUrl').value||'').trim();
  if(vUrl){
    const ytMatch = vUrl.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
    if(ytMatch) vUrl = 'https://www.youtube.com/embed/'+ytMatch[1];
    fd.set('video_url', vUrl);
  }
  try {
    const r = await fetch('<?= APP_URL ?>/api.php', {method:'POST',body:fd});
    const d = await r.json();
    if(d.success){
      msg.style.background='rgba(110,255,158,.15)'; msg.style.color='#2e7d32';
      msg.textContent=d.message||'Post saved!'; msg.style.display='block';
      setTimeout(()=>location.reload(),900);
    } else {
      msg.style.background='rgba(255,107,107,.15)'; msg.style.color='#c62828';
      msg.textContent=d.message||'Error saving post.'; msg.style.display='block';
      btn.disabled=false; btn.textContent='Publish Post';
    }
  } catch {
    msg.style.background='rgba(255,107,107,.15)'; msg.style.color='#c62828';
    msg.textContent='Network error.'; msg.style.display='block';
    btn.disabled=false; btn.textContent='Publish Post';
  }
}

async function toggleNewsPost(id,checked){
  await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new URLSearchParams({action:'toggle_news_post',id,is_active:checked?1:0})});
}

async function setFeaturedPost(id,val){
  const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new URLSearchParams({action:'set_featured_post',id,is_featured:val})});
  const d = await r.json();
  if(d.success) location.reload();
}

async function deleteNewsPost(id){
  if(!confirm('Delete this post? This cannot be undone.')) return;
  const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new URLSearchParams({action:'delete_news_post',id})});
  const d = await r.json();
  if(d.success){ const row=document.getElementById('news-row-'+id); if(row) row.remove(); }
  else alert(d.message||'Error');
}

function addRssFeed(){ document.getElementById('rssFeedModal').style.display='flex'; }

async function submitRssFeed(e){
  e.preventDefault();
  const btn = e.target.querySelector('button[type=submit]');
  const msg = document.getElementById('rssFeedMsg');
  btn.disabled=true; btn.textContent='Adding…';
  const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new FormData(e.target)});
  const d = await r.json();
  if(d.success){ location.reload(); }
  else {
    msg.style.background='rgba(255,107,107,.15)'; msg.style.color='#c62828';
    msg.textContent=d.message||'Error'; msg.style.display='block';
    btn.disabled=false; btn.textContent='Add Feed';
  }
}

async function importRssFeed(id, btn){
  const orig = btn.innerHTML;
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Importing…';
  try {
    const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new URLSearchParams({action:'import_rss_feed',feed_id:id})});
    const d = await r.json();
    if(d.success){ alert(d.message); location.reload(); }
    else { alert('Error: '+(d.message||'Import failed')); }
  } catch { alert('Network error during import.'); }
  btn.disabled=false; btn.innerHTML=orig;
}

async function deleteRssFeed(id){
  if(!confirm('Remove this RSS feed? Imported articles will remain.')) return;
  const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new URLSearchParams({action:'delete_rss_feed',id})});
  const d = await r.json();
  if(d.success) location.reload(); else alert(d.message||'Error');
}

// Set default date on modal open
document.addEventListener('DOMContentLoaded',()=>{
  const dt = document.getElementById('postPubAt');
  if(dt && !dt.value) dt.value = new Date().toISOString().slice(0,16);
});
</script>

<!-- ═══════════════════════════════════════════════════════════
     TAB: NEWSLETTER
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'newsletter'): ?>

<div class="dashboard-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
  <div>
    <h1><i class="fas fa-envelope-open-text"></i> Newsletter</h1>
    <p class="text-muted"><?= count($adminNlSubscribers) ?> subscriber<?= count($adminNlSubscribers)!==1?'s':'' ?> · <?= count($adminNlCampaigns) ?> campaign<?= count($adminNlCampaigns)!==1?'s':'' ?></p>
  </div>
  <button class="btn btn-primary" onclick="switchNlTab('compose');setTimeout(()=>document.getElementById('nl-tab-compose').scrollIntoView({behavior:'smooth',block:'start'}),50);">
    <i class="fas fa-plus"></i> New Campaign
  </button>
</div>

<!-- Sub-tabs -->
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;border-bottom:2px solid var(--border-color,#e8edf2);flex-wrap:wrap;">
  <button onclick="switchNlTab('campaigns')" id="nltab-campaigns" class="nltab nltab-active" style="padding:.6rem 1.25rem;border:none;background:none;cursor:pointer;font-weight:700;font-size:.88rem;border-bottom:2px solid #005DDB;margin-bottom:-2px;color:#005DDB;">Campaigns</button>
  <button onclick="switchNlTab('subscribers')" id="nltab-subscribers" class="nltab" style="padding:.6rem 1.25rem;border:none;background:none;cursor:pointer;font-weight:600;font-size:.88rem;color:#667;border-bottom:2px solid transparent;margin-bottom:-2px;">Subscribers (<?= count($adminNlSubscribers) ?>)</button>
  <button onclick="switchNlTab('compose')" id="nltab-compose" class="nltab" style="padding:.6rem 1.25rem;border:none;background:none;cursor:pointer;font-weight:600;font-size:.88rem;color:#667;border-bottom:2px solid transparent;margin-bottom:-2px;"><i class="fas fa-pen" style="font-size:.8rem;"></i> Compose</button>
</div>

<!-- Campaigns -->
<div id="nl-tab-campaigns">
<?php if (empty($adminNlCampaigns)): ?>
  <div style="text-align:center;padding:3rem;background:#f8f9fb;border-radius:12px;color:#999;">
    <i class="fas fa-envelope" style="font-size:2.5rem;color:#dde3ec;display:block;margin-bottom:.75rem;"></i>
    <p>No campaigns yet. Click <strong>New Campaign</strong> to create one.</p>
  </div>
<?php else: ?>
  <div style="display:flex;flex-direction:column;gap:.75rem;">
  <?php foreach ($adminNlCampaigns as $nc): ?>
  <div style="background:#fff;border:1px solid var(--border-color,#e8edf2);border-radius:12px;padding:1rem 1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
    <div style="flex:1;min-width:200px;">
      <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.25rem;">
        <strong style="font-size:.92rem;color:#1a2a40;"><?= htmlspecialchars($nc['subject']) ?></strong>
        <?php if ($nc['is_sent']): ?>
        <span style="background:rgba(110,255,158,.15);color:#2e7d32;font-size:.7rem;font-weight:700;padding:.15rem .5rem;border-radius:4px;">SENT</span>
        <?php else: ?>
        <span style="background:rgba(232,160,0,.15);color:#7a5000;font-size:.7rem;font-weight:700;padding:.15rem .5rem;border-radius:4px;">DRAFT</span>
        <?php endif; ?>
      </div>
      <div style="font-size:.75rem;color:#999;display:flex;gap:1rem;flex-wrap:wrap;">
        <?php if ($nc['author_name']): ?><span>By <?= htmlspecialchars($nc['author_name']) ?></span><?php endif; ?>
        <span>Created <?= safeDate('d M Y', $nc['created_at']) ?></span>
        <?php if ($nc['is_sent']): ?><span><?= $nc['recipients_count'] ?> recipients · <?= safeDate('d M Y H:i', $nc['sent_at']) ?></span><?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:.5rem;flex-shrink:0;">
      <?php if (!$nc['is_sent']): ?>
      <button onclick="editCampaign(<?= htmlspecialchars(json_encode($nc)) ?>)" class="btn btn-outline" style="font-size:.8rem;padding:.4rem .85rem;">
        <i class="fas fa-edit"></i> Edit
      </button>
      <button onclick="sendCampaign(<?= $nc['id'] ?>,this)" class="btn btn-primary" style="font-size:.8rem;padding:.4rem .85rem;">
        <i class="fas fa-paper-plane"></i> Send
      </button>
      <?php else: ?>
      <span style="font-size:.78rem;color:#2e7d32;font-weight:600;"><i class="fas fa-check-circle"></i> Delivered</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<!-- Subscribers -->
<div id="nl-tab-subscribers" style="display:none;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
    <p style="color:#667;font-size:.88rem;"><strong><?= count($adminNlSubscribers) ?></strong> total · <strong><?= count(array_filter($adminNlSubscribers,fn($s)=>$s['is_active'])) ?></strong> active</p>
  </div>
  <?php if (empty($adminNlSubscribers)): ?>
  <p style="color:#999;font-size:.9rem;text-align:center;padding:2rem;">No subscribers yet.</p>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="data-table" style="width:100%;border-collapse:collapse;font-size:.85rem;">
    <thead><tr style="background:#f0f6ff;">
      <th style="padding:.65rem .9rem;text-align:left;font-weight:700;color:#001F5C;">Email</th>
      <th style="padding:.65rem .9rem;text-align:left;font-weight:700;color:#001F5C;">Name</th>
      <th style="padding:.65rem .9rem;text-align:left;font-weight:700;color:#001F5C;">Subscribed</th>
      <th style="padding:.65rem .9rem;text-align:left;font-weight:700;color:#001F5C;">Status</th>
      <th style="padding:.65rem .9rem;text-align:left;font-weight:700;color:#001F5C;">Action</th>
    </tr></thead>
    <tbody>
    <?php foreach ($adminNlSubscribers as $sub): ?>
    <tr style="border-bottom:1px solid #f0f4f8;" id="sub-row-<?= $sub['id'] ?>">
      <td style="padding:.55rem .9rem;"><?= htmlspecialchars($sub['email']) ?></td>
      <td style="padding:.55rem .9rem;color:#667;"><?= htmlspecialchars($sub['name']??'—') ?></td>
      <td style="padding:.55rem .9rem;color:#667;font-size:.8rem;"><?= safeDate('d M Y', $sub['subscribed_at']) ?></td>
      <td style="padding:.55rem .9rem;">
        <span style="font-size:.75rem;font-weight:700;color:<?= $sub['is_active']?'#2e7d32':'#c62828' ?>;">
          <?= $sub['is_active'] ? 'Active' : 'Unsubscribed' ?>
        </span>
      </td>
      <td style="padding:.55rem .9rem;">
        <button onclick="deleteSubscriber(<?= $sub['id'] ?>)" style="padding:.25rem .6rem;background:#fff0f0;color:#c62828;border:none;border-radius:6px;font-size:.75rem;cursor:pointer;">
          <i class="fas fa-trash"></i>
        </button>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════
     COMPOSE TAB — Plain Text / Template / Raw HTML (inline)
═══════════════════════════════════════════════════════════ -->
<div id="nl-tab-compose" style="display:none;">
  <div style="background:#fff;border:1px solid var(--border-color,#e8edf2);border-radius:16px;padding:2rem;margin-top:.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.5rem;">
      <h3 id="campaignModalTitle" style="font-size:1.15rem;font-weight:800;color:#001F5C;margin:0;">New Newsletter Campaign</h3>
      <button type="button" onclick="resetComposeForm()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#667;line-height:1;" aria-label="Close compose">&times;</button>
    </div>

    <!-- Subject + Preview -->
    <form id="campaignForm" onsubmit="saveCampaign(event)">
      <input type="hidden" name="action" value="save_newsletter_campaign">
      <input type="hidden" name="id" id="campaignId" value="">
      <input type="hidden" name="content_html" id="campaignContentFinal">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem;margin-bottom:1.25rem;">
        <div>
          <label style="font-size:.78rem;font-weight:700;color:#334155;display:block;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.05em;">Subject Line *</label>
          <input type="text" name="subject" id="campaignSubject" required placeholder="e.g. New Auction Dates — June 2026"
                 style="width:100%;padding:.65rem .9rem;border:1.5px solid #dde3ec;border-radius:9px;font-size:.9rem;box-sizing:border-box;">
        </div>
        <div>
          <label style="font-size:.78rem;font-weight:700;color:#334155;display:block;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.05em;">Inbox Preview Text</label>
          <input type="text" name="preview_text" id="campaignPreview" placeholder="Short teaser shown in email clients…"
                 style="width:100%;padding:.65rem .9rem;border:1.5px solid #dde3ec;border-radius:9px;font-size:.88rem;box-sizing:border-box;">
        </div>
      </div>

      <!-- ── COMPOSE MODE TABS ───────────────────────────── -->
      <div style="display:flex;gap:0;border:1.5px solid #dde3ec;border-radius:10px;overflow:hidden;margin-bottom:1.25rem;">
        <button type="button" id="nlmode-plain" onclick="setNlMode('plain')"
                style="flex:1;padding:.6rem;font-size:.8rem;font-weight:700;border:none;cursor:pointer;background:#005DDB;color:#fff;transition:background .15s;">
          <i class="fas fa-align-left"></i> Plain Text
        </button>
        <button type="button" id="nlmode-template" onclick="setNlMode('template')"
                style="flex:1;padding:.6rem;font-size:.8rem;font-weight:700;border:none;cursor:pointer;background:#f0f4f8;color:#334155;border-left:1.5px solid #dde3ec;transition:background .15s;">
          <i class="fas fa-magic"></i> Smart Form
        </button>
        <button type="button" id="nlmode-html" onclick="setNlMode('html')"
                style="flex:1;padding:.6rem;font-size:.8rem;font-weight:700;border:none;cursor:pointer;background:#f0f4f8;color:#334155;border-left:1.5px solid #dde3ec;transition:background .15s;">
          <i class="fas fa-code"></i> Raw HTML
        </button>
      </div>

      <!-- ── MODE: PLAIN TEXT ─────────────────────────────── -->
      <div id="nlpane-plain">
        <div style="background:#f0f6ff;border:1.5px solid #c7dcff;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.82rem;color:#334155;">
          <i class="fas fa-info-circle" style="color:#005DDB;"></i>
          Write your article below in plain text. It will be automatically wrapped in the branded Auction Hub Group email layout when sent.
        </div>
        <div style="display:grid;gap:.75rem;margin-bottom:.85rem;">
          <div>
            <label style="font-size:.78rem;font-weight:700;color:#334155;display:block;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.05em;">Article Heading</label>
            <input type="text" id="ptHeading" placeholder="e.g. Our Biggest Auction Is Coming — 14 June 2026"
                   style="width:100%;padding:.65rem .9rem;border:1.5px solid #dde3ec;border-radius:9px;font-size:.9rem;box-sizing:border-box;">
          </div>
          <div>
            <label style="font-size:.78rem;font-weight:700;color:#334155;display:block;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.05em;">Body Text</label>
            <textarea id="ptBody" rows="7" placeholder="Write your newsletter content here in plain language. You can use multiple paragraphs — just leave a blank line between them."
                      style="width:100%;padding:.75rem 1rem;border:1.5px solid #dde3ec;border-radius:9px;font-size:.9rem;resize:vertical;line-height:1.65;box-sizing:border-box;font-family:inherit;"></textarea>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
            <div>
              <label style="font-size:.78rem;font-weight:700;color:#334155;display:block;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.05em;">Call-to-Action Button Text <span style="font-weight:400;color:#999;">(optional)</span></label>
              <input type="text" id="ptCtaText" placeholder="e.g. View Vehicles"
                     style="width:100%;padding:.65rem .9rem;border:1.5px solid #dde3ec;border-radius:9px;font-size:.88rem;box-sizing:border-box;">
            </div>
            <div>
              <label style="font-size:.78rem;font-weight:700;color:#334155;display:block;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.05em;">CTA Link URL <span style="font-weight:400;color:#999;">(optional)</span></label>
              <input type="text" id="ptCtaUrl" placeholder="e.g. <?= CANONICAL_URL ?>/auctions.php"
                     style="width:100%;padding:.65rem .9rem;border:1.5px solid #dde3ec;border-radius:9px;font-size:.88rem;box-sizing:border-box;">
            </div>
          </div>
        </div>
      </div>

      <!-- ── MODE: SMART FORM ────────────────────────────── -->
      <div id="nlpane-template" style="display:none;">

        <!-- Step 1: pick a template type -->
        <div id="sf-type-picker">
          <p style="font-size:.82rem;color:#667;margin:0 0 .9rem;font-weight:600;">Choose the type of email you want to send:</p>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem;margin-bottom:.5rem;">

            <div onclick="openSmartForm('event')" class="nl-tpl-card sf-type-card" style="border:2px solid #dde3ec;border-radius:12px;padding:1rem;cursor:pointer;transition:border-color .15s,box-shadow .15s;text-align:center;">
              <div style="width:44px;height:44px;background:linear-gradient(135deg,#001F5C,#005DDB);border-radius:10px;display:flex;align-items:center;justify-content:center;margin:0 auto .7rem;">
                <i class="fas fa-calendar-alt" style="color:#fff;font-size:1rem;"></i>
              </div>
              <div style="font-weight:700;color:#001F5C;font-size:.85rem;margin-bottom:.25rem;">Event / Auction</div>
              <div style="font-size:.75rem;color:#999;line-height:1.4;">Upcoming auction promo</div>
            </div>

            <div onclick="openSmartForm('vehicles')" class="nl-tpl-card sf-type-card" style="border:2px solid #dde3ec;border-radius:12px;padding:1rem;cursor:pointer;transition:border-color .15s,box-shadow .15s;text-align:center;">
              <div style="width:44px;height:44px;background:linear-gradient(135deg,#e8a000,#f5c842);border-radius:10px;display:flex;align-items:center;justify-content:center;margin:0 auto .7rem;">
                <i class="fas fa-car" style="color:#fff;font-size:1rem;"></i>
              </div>
              <div style="font-weight:700;color:#001F5C;font-size:.85rem;margin-bottom:.25rem;">Vehicle Showcase</div>
              <div style="font-size:.75rem;color:#999;line-height:1.4;">Fetch &amp; pick live vehicles</div>
            </div>

            <div onclick="openSmartForm('news')" class="nl-tpl-card sf-type-card" style="border:2px solid #dde3ec;border-radius:12px;padding:1rem;cursor:pointer;transition:border-color .15s,box-shadow .15s;text-align:center;">
              <div style="width:44px;height:44px;background:linear-gradient(135deg,#2e7d32,#43a047);border-radius:10px;display:flex;align-items:center;justify-content:center;margin:0 auto .7rem;">
                <i class="fas fa-newspaper" style="color:#fff;font-size:1rem;"></i>
              </div>
              <div style="font-weight:700;color:#001F5C;font-size:.85rem;margin-bottom:.25rem;">News Article</div>
              <div style="font-size:.75rem;color:#999;line-height:1.4;">Share news or an update</div>
            </div>

            <div onclick="openSmartForm('finance')" class="nl-tpl-card sf-type-card" style="border:2px solid #dde3ec;border-radius:12px;padding:1rem;cursor:pointer;transition:border-color .15s,box-shadow .15s;text-align:center;">
              <div style="width:44px;height:44px;background:linear-gradient(135deg,#c62828,#e53935);border-radius:10px;display:flex;align-items:center;justify-content:center;margin:0 auto .7rem;">
                <i class="fas fa-hand-holding-dollar" style="color:#fff;font-size:1rem;"></i>
              </div>
              <div style="font-weight:700;color:#001F5C;font-size:.85rem;margin-bottom:.25rem;">Finance Deals</div>
              <div style="font-size:.75rem;color:#999;line-height:1.4;">In-house finance offers</div>
            </div>

          </div>
        </div>

        <!-- Step 2: smart form fields (shown after picking type) -->
        <div id="sf-form-area" style="display:none;">

          <!-- Back link -->
          <button type="button" onclick="closeSmartForm()" style="background:none;border:none;color:#005DDB;font-size:.8rem;font-weight:700;cursor:pointer;padding:0;margin-bottom:1rem;">
            <i class="fas fa-arrow-left"></i> Change template type
          </button>
          <div id="sf-type-label" style="font-size:.88rem;font-weight:800;color:#001F5C;margin-bottom:1rem;padding:.5rem .9rem;background:#f0f6ff;border-radius:8px;border-left:4px solid #005DDB;"></div>

          <!-- ── EVENT form ── -->
          <div id="sf-event" style="display:none;">
            <div style="background:#fffbea;border:1.5px solid #ffd54f;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.82rem;color:#7a5000;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
              <span><i class="fas fa-bolt" style="color:#e8a000;"></i> <strong>Auto-fill from live system:</strong> Load your real events, pick one, and the email links straight to its page.</span>
              <button type="button" onclick="fetchNlEvents()" id="sf-ev-fetch-btn" style="padding:.4rem .9rem;background:#005DDB;color:#fff;border:none;border-radius:8px;font-size:.8rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                <i class="fas fa-sync"></i> Fetch Upcoming Events
              </button>
            </div>
            <div id="sf-event-list" style="display:grid;gap:.6rem;margin-bottom:1rem;"></div>
            <div style="display:grid;gap:.75rem;">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                <div>
                  <label class="sf-label">Auction / Event Name *</label>
                  <input type="text" id="sf-ev-name" placeholder="e.g. Bank Repossession Auction — July 2026" class="sf-input">
                </div>
                <div>
                  <label class="sf-label">Date &amp; Time *</label>
                  <input type="text" id="sf-ev-date" placeholder="e.g. Saturday, 12 July 2026 — 10:00 AM" class="sf-input">
                </div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                <div>
                  <label class="sf-label">Number of Vehicles</label>
                  <input type="text" id="sf-ev-count" placeholder="e.g. 80+" class="sf-input">
                </div>
                <div>
                  <label class="sf-label">Venue / Location</label>
                  <input type="text" id="sf-ev-venue" placeholder="80 Sterling Rd, Centurion" class="sf-input" value="80 Sterling Rd, Kosmosdal, Centurion">
                </div>
              </div>
              <div>
                <label class="sf-label">Intro Paragraph <span style="font-weight:400;color:#999;">(optional — leave blank for default)</span></label>
                <textarea id="sf-ev-intro" rows="2" placeholder="Short intro about this auction event…" class="sf-input" style="resize:vertical;"></textarea>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                <div>
                  <label class="sf-label">Register CTA button text</label>
                  <input type="text" id="sf-ev-cta" placeholder="Register Free" class="sf-input">
                </div>
                <div>
                  <label class="sf-label">View Vehicles link text</label>
                  <input type="text" id="sf-ev-view" placeholder="View All Vehicles" class="sf-input">
                </div>
              </div>
            </div>
          </div>

          <!-- ── VEHICLES form ── -->
          <div id="sf-vehicles" style="display:none;">
            <div style="background:#fffbea;border:1.5px solid #ffd54f;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.82rem;color:#7a5000;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
              <span><i class="fas fa-bolt" style="color:#e8a000;"></i> <strong>Auto-fill from live system:</strong> Click the button to load current vehicles, then tick the ones to feature.</span>
              <button type="button" onclick="fetchNlVehicles()" id="sf-fetch-btn" style="padding:.4rem .9rem;background:#005DDB;color:#fff;border:none;border-radius:8px;font-size:.8rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                <i class="fas fa-sync"></i> Fetch Live Vehicles
              </button>
            </div>

            <!-- Vehicle list (populated by JS) -->
            <div id="sf-vehicle-list" style="display:grid;gap:.6rem;margin-bottom:1rem;max-height:280px;overflow-y:auto;"></div>

            <!-- Manual override for up to 3 vehicles -->
            <details style="margin-top:.5rem;">
              <summary style="font-size:.8rem;font-weight:700;color:#667;cursor:pointer;padding:.4rem 0;">Or enter vehicle details manually</summary>
              <div style="display:grid;gap:.85rem;margin-top:.75rem;">
                <?php for ($vi=1; $vi<=3; $vi++): ?>
                <div style="border:1px solid #dde3ec;border-radius:10px;padding:.9rem;background:#f8f9fb;">
                  <p style="font-size:.78rem;font-weight:700;color:#334155;margin:0 0 .6rem;">Vehicle <?= $vi ?></p>
                  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;margin-bottom:.5rem;">
                    <input type="text" id="sf-v<?= $vi ?>-name" placeholder="Year Make Model" class="sf-input" style="font-size:.82rem;">
                    <input type="text" id="sf-v<?= $vi ?>-mileage" placeholder="Mileage (km)" class="sf-input" style="font-size:.82rem;">
                    <input type="text" id="sf-v<?= $vi ?>-price" placeholder="Starting from R …" class="sf-input" style="font-size:.82rem;">
                  </div>
                  <input type="text" id="sf-v<?= $vi ?>-img" placeholder="Image URL (optional)" class="sf-input" style="font-size:.82rem;width:100%;box-sizing:border-box;">
                </div>
                <?php endfor; ?>
              </div>
            </details>
          </div>

          <!-- ── NEWS ARTICLE form ── -->
          <div id="sf-news" style="display:none;">
            <div style="display:grid;gap:.75rem;">
              <div>
                <label class="sf-label">Article Headline *</label>
                <input type="text" id="sf-nw-headline" placeholder="e.g. AHG Surpasses 100,000 Vehicles Sold" class="sf-input">
              </div>
              <div>
                <label class="sf-label">Featured Image URL <span style="font-weight:400;color:#999;">(optional)</span></label>
                <input type="url" id="sf-nw-img" placeholder="https://… (paste a direct image link)" class="sf-input">
              </div>
              <div>
                <label class="sf-label">Opening Paragraph *</label>
                <textarea id="sf-nw-p1" rows="3" placeholder="Summarise the news — what happened and why it matters." class="sf-input" style="resize:vertical;"></textarea>
              </div>
              <div>
                <label class="sf-label">Main Paragraph</label>
                <textarea id="sf-nw-p2" rows="3" placeholder="Key details, context, or insights." class="sf-input" style="resize:vertical;"></textarea>
              </div>
              <div>
                <label class="sf-label">Pull Quote / Highlight <span style="font-weight:400;color:#999;">(optional — shown in blue box)</span></label>
                <input type="text" id="sf-nw-quote" placeholder="e.g. "Every vehicle we sell represents a family's fresh start."" class="sf-input">
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                <div>
                  <label class="sf-label">CTA Button Text</label>
                  <input type="text" id="sf-nw-cta" placeholder="Read Full Article" class="sf-input">
                </div>
                <div>
                  <label class="sf-label">CTA Link URL</label>
                  <input type="url" id="sf-nw-url" placeholder="<?= CANONICAL_URL ?>/news.php" class="sf-input">
                </div>
              </div>
            </div>
          </div>

          <!-- ── FINANCE form ── -->
          <div id="sf-finance" style="display:none;">
            <div style="display:grid;gap:.75rem;">
              <div>
                <label class="sf-label">Email Headline *</label>
                <input type="text" id="sf-fi-headline" placeholder="e.g. Drive Today — No Credit Checks!" class="sf-input" value="Drive Today — No Credit Checks!">
              </div>
              <div>
                <label class="sf-label">Intro Paragraph *</label>
                <textarea id="sf-fi-intro" rows="3" placeholder="e.g. Blacklisted? Low credit score? Bank said no? We say YES." class="sf-input" style="resize:vertical;">Blacklisted? Low credit score? Bank said no? We say YES. Our in-house finance is designed for South Africans who deserve a second chance behind the wheel.</textarea>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                <div>
                  <label class="sf-label">Starting Deposit Amount *</label>
                  <input type="text" id="sf-fi-deposit" placeholder="e.g. R10,500" class="sf-input" value="R10,500">
                </div>
                <div>
                  <label class="sf-label">Deposit Tagline</label>
                  <input type="text" id="sf-fi-tagline" placeholder="Drive away today — pay the rest monthly" class="sf-input" value="Drive away today — pay the rest monthly">
                </div>
              </div>
              <div>
                <label class="sf-label">Key Benefits <span style="font-weight:400;color:#999;">(one per line)</span></label>
                <textarea id="sf-fi-bullets" rows="4" placeholder="No credit checks&#10;No ITC inquiries&#10;Blacklisted clients welcome&#10;Flexible monthly terms" class="sf-input" style="resize:vertical;">No credit checks
No ITC inquiries
Blacklisted clients welcome
Flexible monthly terms</textarea>
              </div>
            </div>
          </div>

          <!-- Build button (shared) -->
          <div style="margin-top:1.25rem;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
            <button type="button" onclick="buildSmartEmail()" style="padding:.65rem 1.5rem;background:#001F5C;color:#fff;border:none;border-radius:9px;font-size:.88rem;font-weight:800;cursor:pointer;">
              <i class="fas fa-magic"></i> Build Email &amp; Preview
            </button>
            <span style="font-size:.78rem;color:#999;">Generates the branded email from your answers</span>
          </div>

        </div><!-- /#sf-form-area -->
      </div><!-- /#nlpane-template -->

      <!-- ── MODE: RAW HTML ───────────────────────────────── -->
      <div id="nlpane-html" style="display:none;">
        <div style="border:1.5px solid #dde3ec;border-radius:10px;overflow:hidden;">
          <div style="display:flex;gap:.25rem;padding:.5rem .75rem;background:#f8f9fb;border-bottom:1px solid #dde3ec;flex-wrap:wrap;align-items:center;">
            <span style="font-size:.75rem;color:#667;font-weight:600;margin-right:.25rem;">Insert:</span>
            <?php foreach ([
              ['B','<b>…</b>'],
              ['I','<em>…</em>'],
              ['H2','<h2 style="color:#001F5C;">…</h2>'],
              ['H3','<h3 style="color:#005DDB;">…</h3>'],
              ['Link','<a href="URL" style="color:#005DDB;font-weight:700;">…</a>'],
              ['Button','<a href="URL" style="display:inline-block;background:#005DDB;color:#fff;padding:11px 26px;border-radius:50px;font-weight:700;font-size:14px;text-decoration:none;">Button Text</a>'],
              ['Image','<img src="URL" width="100%" alt="" style="border-radius:8px;display:block;margin:12px 0;">'],
              ['Divider','<hr style="border:none;border-top:1px solid #e8edf2;margin:20px 0">'],
            ] as [$l,$t]): ?>
            <button type="button" onclick="insertHtml('campaignContent','<?= addslashes($t) ?>')"
                    style="padding:.2rem .5rem;border:1px solid #dde3ec;border-radius:5px;background:#fff;font-size:.73rem;cursor:pointer;color:#334155;"><?= $l ?></button>
            <?php endforeach; ?>
          </div>
          <textarea id="campaignContent" rows="16"
                    placeholder="Paste or write full HTML email here…"
                    style="width:100%;padding:1rem;border:none;font-size:.82rem;resize:vertical;font-family:'Courier New',monospace;color:#1a2a40;line-height:1.6;min-height:220px;box-sizing:border-box;"></textarea>
        </div>
        <!-- Live preview -->
        <div id="nlHtmlPreview" style="margin-top:.75rem;border:2px solid #005DDB;border-radius:10px;overflow:hidden;display:none;">
          <div style="background:#005DDB;color:#fff;font-size:.75rem;font-weight:700;padding:.35rem .8rem;display:flex;justify-content:space-between;align-items:center;">
            <span><i class="fas fa-eye"></i> Live Preview</span>
            <button type="button" onclick="document.getElementById('nlHtmlPreview').style.display='none'" style="background:none;border:none;color:#fff;cursor:pointer;font-size:.9rem;">&times;</button>
          </div>
          <iframe id="nlPreviewFrame" style="width:100%;height:420px;border:none;background:#fff;"></iframe>
        </div>
        <div style="margin-top:.5rem;display:flex;justify-content:flex-end;">
          <button type="button" onclick="previewNlHtml()" style="font-size:.78rem;color:#005DDB;background:none;border:none;cursor:pointer;font-weight:600;padding:0;">
            <i class="fas fa-eye"></i> Show Preview
          </button>
        </div>
      </div>

      <div id="campaignMsg" style="display:none;padding:.65rem 1rem;border-radius:8px;margin-bottom:.75rem;font-size:.88rem;margin-top:.75rem;"></div>
      <div style="display:flex;justify-content:flex-end;gap:.75rem;margin-top:1.25rem;padding-top:1rem;border-top:1px solid #eef1f5;">
        <button type="button" onclick="resetComposeForm()" class="btn btn-outline">Cancel</button>
        <button type="submit" class="btn btn-primary" id="campaignSaveBtn">
          <i class="fas fa-save"></i> Save Draft
        </button>
      </div>
    </form>
  </div><!-- /.compose-card -->
</div><!-- /#nl-tab-compose -->

<style>
.nl-tpl-card:hover { border-color:#005DDB !important; box-shadow:0 4px 16px rgba(0,93,219,.15); }
.nl-tpl-card.selected { border-color:#005DDB !important; background:#f0f6ff; }
.sf-label { font-size:.78rem;font-weight:700;color:#334155;display:block;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.05em; }
.sf-input { width:100%;padding:.65rem .9rem;border:1.5px solid #dde3ec;border-radius:9px;font-size:.88rem;box-sizing:border-box;color:#1a2a40;font-family:inherit; }
.sf-input:focus { outline:none;border-color:#005DDB; }
.sf-vehicle-row { display:flex;gap:.75rem;align-items:center;padding:.6rem .75rem;border:1px solid #dde3ec;border-radius:10px;background:#fff; }
.sf-vehicle-row:hover { border-color:#005DDB;background:#f0f6ff; }
.sf-vehicle-row input[type=checkbox] { width:17px;height:17px;accent-color:#005DDB;flex-shrink:0;cursor:pointer; }
</style>

<script>
// ── Newsletter sub-tabs ──────────────────────────────────────
function switchNlTab(tab){
  ['campaigns','subscribers','compose'].forEach(t=>{
    const pane = document.getElementById('nl-tab-'+t);
    if(pane) pane.style.display = t===tab?'':'none';
    const btn = document.getElementById('nltab-'+t);
    if(btn){
      if(t===tab){ btn.style.borderBottomColor='#005DDB'; btn.style.color='#005DDB'; btn.style.fontWeight='700'; }
      else { btn.style.borderBottomColor='transparent'; btn.style.color='#667'; btn.style.fontWeight='600'; }
    }
  });
}

// ── Reset / close compose panel ──────────────────────────────
function resetComposeForm(){
  document.getElementById('campaignForm').reset();
  document.getElementById('campaignId').value='';
  document.getElementById('campaignContent').value='';
  document.getElementById('campaignModalTitle').textContent='New Newsletter Campaign';
  const msg = document.getElementById('campaignMsg');
  if(msg){ msg.style.display='none'; msg.textContent=''; }
  setNlMode('plain');
  switchNlTab('campaigns');
}

function editCampaign(c){
  document.getElementById('campaignId').value      = c.id;
  document.getElementById('campaignSubject').value  = c.subject||'';
  document.getElementById('campaignPreview').value  = c.preview_text||'';
  document.getElementById('campaignContent').value  = c.content_html||'';
  document.getElementById('campaignModalTitle').textContent = 'Edit Campaign';
  // If existing campaign has HTML, open in HTML mode
  setNlMode(c.content_html ? 'html' : 'plain');
  switchNlTab('compose');
  setTimeout(()=>document.getElementById('nl-tab-compose').scrollIntoView({behavior:'smooth',block:'start'}),50);
}

// ── Compose mode switcher ────────────────────────────────────
function setNlMode(mode){
  ['plain','template','html'].forEach(m => {
    const btn  = document.getElementById('nlmode-'+m);
    const pane = document.getElementById('nlpane-'+m);
    const active = m === mode;
    btn.style.background  = active ? '#005DDB' : '#f0f4f8';
    btn.style.color       = active ? '#fff'    : '#334155';
    pane.style.display    = active ? '' : 'none';
  });
  window._nlMode = mode;
}
setNlMode('plain'); // default

// ── Build final HTML from Plain Text inputs ──────────────────
function buildPlainTextHtml(){
  const heading = document.getElementById('ptHeading').value.trim();
  const body    = document.getElementById('ptBody').value.trim();
  const ctaTxt  = document.getElementById('ptCtaText').value.trim();
  const ctaUrl  = document.getElementById('ptCtaUrl').value.trim() || '<?= CANONICAL_URL ?>/auctions.php';
  if(!body) return null;
  // Convert blank-line paragraphs
  const paragraphs = body.split(/\n{2,}/).map(p =>
    `<p style="font-size:15px;color:#334155;line-height:1.75;margin:0 0 14px;">${p.replace(/\n/g,'<br>')}</p>`
  ).join('');
  const ctaBlock = ctaTxt
    ? `<div style="text-align:center;margin:24px 0 8px;"><a href="${ctaUrl}" style="display:inline-block;background:#005DDB;color:#fff;padding:13px 30px;border-radius:50px;font-weight:800;font-size:15px;text-decoration:none;">${ctaTxt} &rarr;</a></div>`
    : '';
  return buildEmailShell(
    heading ? `<h2 style="font-size:24px;font-weight:800;color:#001F5C;margin:0 0 18px;line-height:1.25;">${heading}</h2>` : '',
    paragraphs + ctaBlock
  );
}

// ── Shared branded email shell ───────────────────────────────
function buildEmailShell(headerContent, bodyContent){
  return `<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Auction Hub Group</title></head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Segoe UI',Arial,sans-serif;">
<div style="max-width:620px;margin:24px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,31,92,.12);">
  <div style="background:linear-gradient(135deg,#001F5C 0%,#005DDB 100%);padding:28px 32px;text-align:center;">
    <h1 style="color:#fff;font-size:20px;font-weight:800;margin:0 0 4px;letter-spacing:-.2px;">Auction Hub Group</h1>
    <p style="color:rgba(255,255,255,.7);font-size:13px;margin:0;">Trusted by banks, respected by buyers</p>
  </div>
  <div style="padding:28px 32px;">
    ${headerContent}
    ${bodyContent}
  </div>
  <div style="padding:20px 32px;text-align:center;border-top:1px solid #e8edf2;background:#f8f9fb;">
    <p style="font-size:12px;color:#aaa;line-height:1.7;margin:0 0 6px;">
      <strong style="color:#667;">Auction Hub Group</strong><br>
      80 Sterling Rd, Centurion &nbsp;·&nbsp; <?= COMPANY_PHONE ?> &nbsp;·&nbsp; <a href="mailto:<?= COMPANY_EMAIL ?>" style="color:#005DDB;"><?= COMPANY_EMAIL ?></a>
    </p>
    <p style="font-size:11px;color:#bbb;margin:0;">
      You received this because you registered at <a href="<?= CANONICAL_URL ?>" style="color:#aaa;"><?= CANONICAL_URL ?></a>.
      &nbsp;<a href="{{UNSUBSCRIBE_LINK}}" style="color:#aaa;">Unsubscribe</a>
    </p>
  </div>
</div>
</body></html>`;
}

// ── Smart Form ───────────────────────────────────────────────
let _sfType = '';

function openSmartForm(type){
  _sfType = type;
  _sfEventLink = ''; // clear any previously picked event link
  // Hide all sub-forms, show the chosen one
  ['event','vehicles','news','finance'].forEach(t => {
    const el = document.getElementById('sf-'+t);
    if(el) el.style.display = t===type ? '' : 'none';
  });
  // Update label
  const labels = {
    event:    '<i class="fas fa-calendar-alt" style="color:#005DDB;margin-right:.4rem;"></i>Event / Auction Promo',
    vehicles: '<i class="fas fa-car" style="color:#e8a000;margin-right:.4rem;"></i>Vehicle Showcase',
    news:     '<i class="fas fa-newspaper" style="color:#2e7d32;margin-right:.4rem;"></i>News Article',
    finance:  '<i class="fas fa-hand-holding-dollar" style="color:#c62828;margin-right:.4rem;"></i>Finance Deals',
  };
  document.getElementById('sf-type-label').innerHTML = labels[type] || '';
  document.getElementById('sf-type-picker').style.display = 'none';
  document.getElementById('sf-form-area').style.display   = '';
}

function closeSmartForm(){
  document.getElementById('sf-type-picker').style.display = '';
  document.getElementById('sf-form-area').style.display   = 'none';
  _sfType = '';
}

async function fetchNlVehicles(){
  const btn = document.getElementById('sf-fetch-btn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';
  try {
    const r = await fetch('<?= APP_URL ?>/api.php', {method:'POST', body: new URLSearchParams({action:'get_newsletter_vehicles'})});
    const d = await r.json();
    const list = document.getElementById('sf-vehicle-list');
    if(!d.success || !d.vehicles || !d.vehicles.length){
      list.innerHTML = '<p style="font-size:.82rem;color:#999;padding:.5rem;">No active or upcoming vehicles found right now.</p>';
    } else {
      list.innerHTML = d.vehicles.map(v => {
        const price = v.current_price > 0
          ? 'R ' + Number(v.current_price).toLocaleString('en-ZA')
          : (v.start_price > 0 ? 'Starting R ' + Number(v.start_price).toLocaleString('en-ZA') : 'POA');
        const badge = v.status==='active'
          ? '<span style="font-size:.65rem;background:#e8f5e9;color:#2e7d32;border-radius:4px;padding:.1rem .35rem;font-weight:700;">LIVE</span>'
          : '<span style="font-size:.65rem;background:#fff3cd;color:#7a5000;border-radius:4px;padding:.1rem .35rem;font-weight:700;">UPCOMING</span>';
        const img = v.image_url ? `<img src="${v.image_url}" style="width:48px;height:36px;object-fit:cover;border-radius:5px;flex-shrink:0;" onerror="this.style.display='none'">` : '';
        const encoded = encodeURIComponent(JSON.stringify({
          name: v.item_name, mileage: v.mileage||'', transmission: v.transmission||'',
          price, img: v.image_url||'', id: v.id
        }));
        return `<label class="sf-vehicle-row" style="cursor:pointer;">
          <input type="checkbox" class="sf-veh-check" data-vehicle="${encoded}">
          ${img}
          <div style="flex:1;min-width:0;">
            <div style="font-size:.85rem;font-weight:700;color:#1a2a40;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${v.item_name} ${badge}</div>
            <div style="font-size:.75rem;color:#667;">${v.mileage ? Number(v.mileage).toLocaleString('en-ZA')+' km · ' : ''}${v.transmission||''}</div>
          </div>
          <div style="font-size:.82rem;font-weight:800;color:#005DDB;white-space:nowrap;">${price}</div>
        </label>`;
      }).join('');
    }
  } catch(err) {
    document.getElementById('sf-vehicle-list').innerHTML = '<p style="font-size:.82rem;color:#c62828;padding:.5rem;">Failed to load vehicles. Check your connection.</p>';
  }
  btn.disabled=false; btn.innerHTML='<i class="fas fa-sync"></i> Fetch Live Vehicles';
}

// ── Event picker: load real events so emails link to the event page ──
let _sfEventLink = '';
async function fetchNlEvents(){
  const btn = document.getElementById('sf-ev-fetch-btn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';
  try {
    const r = await fetch('<?= APP_URL ?>/api.php', {method:'POST', body: new URLSearchParams({action:'get_newsletter_events'})});
    const d = await r.json();
    const list = document.getElementById('sf-event-list');
    if(!d.success || !d.events || !d.events.length){
      list.innerHTML = '<p style="font-size:.82rem;color:#999;padding:.5rem;">No upcoming or live events found. Create one under the Events tab, or fill in the fields manually below.</p>';
    } else {
      list.innerHTML = d.events.map(ev => {
        const badge = ev.status==='live'
          ? '<span style="font-size:.65rem;background:#e8f5e9;color:#2e7d32;border-radius:4px;padding:.1rem .35rem;font-weight:700;">LIVE</span>'
          : '<span style="font-size:.65rem;background:#fff3cd;color:#7a5000;border-radius:4px;padding:.1rem .35rem;font-weight:700;">UPCOMING</span>';
        const when = ev.date_label + (ev.time_label ? ' — ' + ev.time_label : '');
        return `<label class="sf-vehicle-row" style="cursor:pointer;">
          <input type="radio" name="sf-ev-pick" data-event="${encodeURIComponent(JSON.stringify(ev))}" onchange="pickNlEvent(this)">
          <div style="flex:1;min-width:0;">
            <div style="font-size:.85rem;font-weight:700;color:#1a2a40;">${ev.title} ${badge}</div>
            <div style="font-size:.75rem;color:#667;">${when}${ev.venue ? ' · ' + ev.venue : ''} · ${ev.vehicle_count} vehicle${ev.vehicle_count==1?'':'s'}</div>
          </div>
        </label>`;
      }).join('');
    }
  } catch(err) {
    document.getElementById('sf-event-list').innerHTML = '<p style="font-size:.82rem;color:#c62828;padding:.5rem;">Failed to load events. Check your connection.</p>';
  }
  btn.disabled=false; btn.innerHTML='<i class="fas fa-sync"></i> Fetch Upcoming Events';
}
function pickNlEvent(el){
  const ev = JSON.parse(decodeURIComponent(el.dataset.event));
  document.getElementById('sf-ev-name').value  = ev.title || '';
  document.getElementById('sf-ev-date').value  = ev.date_label + (ev.time_label ? ' — ' + ev.time_label : '');
  if (ev.venue) document.getElementById('sf-ev-venue').value = ev.venue;
  if (Number(ev.vehicle_count) > 0) document.getElementById('sf-ev-count').value = ev.vehicle_count;
  _sfEventLink = '<?= CANONICAL_URL ?>/events.php?id=' + ev.id;
}

function buildSmartEmail(){
  let html = '';
  if(_sfType === 'event'){
    const name  = document.getElementById('sf-ev-name').value.trim() || 'Upcoming Bank Repossession Auction';
    const date  = document.getElementById('sf-ev-date').value.trim() || '[DATE & TIME]';
    const count = document.getElementById('sf-ev-count').value.trim() || '80+';
    const venue = document.getElementById('sf-ev-venue').value.trim() || '80 Sterling Rd, Kosmosdal, Centurion';
    const intro = document.getElementById('sf-ev-intro').value.trim() ||
      `Over ${count} vehicles going under the hammer — sedans, SUVs, bakkies, and light commercial vehicles. All DEKRA-certified with full inspection reports.`;
    const ctaView = document.getElementById('sf-ev-view').value.trim() || 'View All Vehicles';
    const ctaReg  = document.getElementById('sf-ev-cta').value.trim()  || 'Register Free';
    html = buildEmailShell(
      `<p style="font-size:13px;color:#667;margin:0 0 4px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;">Upcoming Auction · <?= date('F Y') ?></p>
       <h2 style="font-size:26px;font-weight:800;color:#001F5C;margin:0 0 14px;line-height:1.25;">${name}</h2>`,
      `<div style="border:1px solid #e8edf2;border-radius:12px;overflow:hidden;margin-bottom:16px;">
         <div style="background:#001F5C;padding:14px 18px;">
           <p style="color:#ffd54f;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;margin:0 0 3px;">Bank Repossession Auction</p>
           <p style="color:#fff;font-size:18px;font-weight:700;margin:0;">${date}</p>
         </div>
         <div style="padding:18px;">
           <p style="font-size:14px;color:#334155;line-height:1.7;margin:0 0 6px;"><i class="fas fa-map-marker-alt"></i> ${venue}</p>
           <p style="font-size:14px;color:#334155;line-height:1.7;margin:0 0 14px;">${intro}</p>
           <a href="${_sfEventLink || '<?= CANONICAL_URL ?>/auctions.php'}" style="display:inline-block;background:#005DDB;color:#fff;padding:11px 26px;border-radius:50px;font-weight:700;font-size:13px;text-decoration:none;">${ctaView} &rarr;</a>
         </div>
       </div>
       <div style="background:linear-gradient(135deg,#001F5C,#005DDB);border-radius:12px;padding:22px;text-align:center;margin-top:8px;">
         <p style="color:#fff;font-size:18px;font-weight:800;margin:0 0 8px;">Secure Your Bidder Number Today</p>
         <p style="color:rgba(255,255,255,.8);font-size:13px;margin:0 0 16px;">Registration closes 48 hours before the auction.</p>
         <a href="<?= CANONICAL_URL ?>/login.php?tab=register" style="display:inline-block;background:#ffd54f;color:#001F5C;padding:12px 28px;border-radius:50px;font-weight:800;font-size:14px;text-decoration:none;">${ctaReg} &rarr;</a>
       </div>`
    );

  } else if(_sfType === 'vehicles'){
    // Collect checked vehicles from the live list
    const checked = [...document.querySelectorAll('.sf-veh-check:checked')].map(cb => {
      try { return JSON.parse(decodeURIComponent(cb.dataset.vehicle)); } catch(e){ return null; }
    }).filter(Boolean);
    // Fall back to manual fields
    const manuals = [1,2,3].map(i=>({
      name:     document.getElementById('sf-v'+i+'-name').value.trim(),
      mileage:  document.getElementById('sf-v'+i+'-mileage').value.trim(),
      price:    document.getElementById('sf-v'+i+'-price').value.trim(),
      img:      document.getElementById('sf-v'+i+'-img').value.trim(),
    })).filter(v=>v.name);
    const vehicles = checked.length ? checked : manuals;
    if(!vehicles.length){ alert('Please select or enter at least one vehicle.'); return; }
    const rows = vehicles.slice(0,4).map(v =>
      `<div style="border:1px solid #e8edf2;border-radius:12px;padding:16px;display:flex;gap:14px;align-items:flex-start;margin-bottom:12px;">
        ${v.img ? `<img src="${v.img}" style="width:90px;height:65px;object-fit:cover;border-radius:8px;flex-shrink:0;" alt="${v.name}">` :
          `<div style="width:80px;height:60px;background:#e8edf2;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">🚗</div>`}
        <div>
          <p style="font-weight:700;color:#001F5C;font-size:15px;margin:0 0 4px;">${v.name}</p>
          <p style="font-size:13px;color:#667;margin:0 0 8px;">${v.mileage ? v.mileage+' km · ' : ''}${v.transmission||''}${(v.mileage||v.transmission)?'DEKRA Certified':''}</p>
          <p style="font-size:17px;font-weight:800;color:#005DDB;margin:0 0 10px;">${v.price||''}</p>
          <a href="${v.id ? '<?= CANONICAL_URL ?>/auction.php?id=' + v.id : '<?= CANONICAL_URL ?>/auctions.php'}" style="font-size:13px;color:#005DDB;font-weight:700;text-decoration:none;">View &amp; Bid &rarr;</a>
        </div>
      </div>`
    ).join('');
    html = buildEmailShell(
      `<p style="font-size:13px;color:#667;margin:0 0 4px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;">Vehicle Spotlight · <?= date('F Y') ?></p>
       <h2 style="font-size:26px;font-weight:800;color:#001F5C;margin:0 0 14px;line-height:1.25;">Hot Vehicles Available Now</h2>`,
      `<p style="font-size:15px;color:#334155;line-height:1.7;margin:0 0 18px;">This month's selection includes some of our best repossessed and bank-consigned vehicles — all DEKRA inspected and ready to go.</p>
       ${rows}
       <div style="text-align:center;margin-top:8px;">
         <a href="<?= CANONICAL_URL ?>/auctions.php" style="display:inline-block;background:#005DDB;color:#fff;padding:13px 30px;border-radius:50px;font-weight:800;font-size:15px;text-decoration:none;">Browse All Vehicles &rarr;</a>
       </div>`
    );

  } else if(_sfType === 'news'){
    const headline = document.getElementById('sf-nw-headline').value.trim() || '[Headline]';
    const imgUrl   = document.getElementById('sf-nw-img').value.trim();
    const p1       = document.getElementById('sf-nw-p1').value.trim();
    const p2       = document.getElementById('sf-nw-p2').value.trim();
    const quote    = document.getElementById('sf-nw-quote').value.trim();
    const ctaTxt   = document.getElementById('sf-nw-cta').value.trim() || 'Read Full Article';
    const ctaUrl   = document.getElementById('sf-nw-url').value.trim() || '<?= CANONICAL_URL ?>/news.php';
    html = buildEmailShell(
      `<p style="font-size:13px;color:#667;margin:0 0 4px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;">News &amp; Updates · <?= date('F Y') ?></p>
       <h2 style="font-size:26px;font-weight:800;color:#001F5C;margin:0 0 14px;line-height:1.25;">${headline}</h2>`,
      `${imgUrl ? `<img src="${imgUrl}" width="100%" alt="${headline}" style="border-radius:10px;display:block;margin-bottom:18px;max-height:260px;object-fit:cover;">` : ''}
       ${p1 ? `<p style="font-size:15px;color:#334155;line-height:1.75;margin:0 0 14px;">${p1}</p>` : ''}
       ${p2 ? `<p style="font-size:15px;color:#334155;line-height:1.75;margin:0 0 14px;">${p2}</p>` : ''}
       ${quote ? `<div style="background:#f0f6ff;border-left:4px solid #005DDB;padding:14px 18px;border-radius:0 8px 8px 0;margin-bottom:20px;">
         <p style="font-size:14px;color:#334155;font-style:italic;margin:0;">"${quote}"</p>
       </div>` : ''}
       <a href="${ctaUrl}" style="display:inline-block;background:#005DDB;color:#fff;padding:11px 26px;border-radius:50px;font-weight:700;font-size:14px;text-decoration:none;">${ctaTxt} &rarr;</a>`
    );

  } else if(_sfType === 'finance'){
    const headline = document.getElementById('sf-fi-headline').value.trim() || 'Drive Today — No Credit Checks!';
    const intro    = document.getElementById('sf-fi-intro').value.trim();
    const deposit  = document.getElementById('sf-fi-deposit').value.trim() || 'R10,500';
    const tagline  = document.getElementById('sf-fi-tagline').value.trim() || 'Drive away today — pay the rest monthly';
    const bullets  = document.getElementById('sf-fi-bullets').value.trim().split('\n').filter(Boolean);
    const bulletHtml = bullets.map(b=>`<li style="font-size:14px;color:#334155;margin-bottom:6px;">${b}</li>`).join('');
    html = buildEmailShell(
      `<p style="font-size:13px;color:#667;margin:0 0 4px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;">Finance Offer · <?= date('F Y') ?></p>
       <h2 style="font-size:26px;font-weight:800;color:#001F5C;margin:0 0 14px;line-height:1.25;">${headline}</h2>`,
      `<p style="font-size:15px;color:#334155;line-height:1.75;margin:0 0 18px;">${intro}</p>
       <div style="background:linear-gradient(135deg,#fff8e1,#fff3cd);border:2px solid #ffd54f;border-radius:14px;padding:22px;margin-bottom:18px;text-align:center;">
         <p style="font-size:12px;font-weight:800;color:#e8a000;text-transform:uppercase;letter-spacing:.1em;margin:0 0 6px;">Starting Deposit</p>
         <p style="font-size:42px;font-weight:900;color:#001F5C;margin:0 0 8px;line-height:1;">${deposit}</p>
         <p style="font-size:14px;color:#667;margin:0;">${tagline}</p>
       </div>
       ${bulletHtml ? `<ul style="padding-left:20px;margin:0 0 20px;">${bulletHtml}</ul>` : ''}
       <div style="text-align:center;">
         <a href="<?= CANONICAL_URL ?>/financing.php" style="display:inline-block;background:#e8a000;color:#001F5C;padding:13px 32px;border-radius:50px;font-weight:900;font-size:15px;text-decoration:none;">Apply for Finance &rarr;</a>
       </div>`
    );
  }

  if(!html){ alert('Something went wrong building the email. Please check your fields.'); return; }
  // Push into the HTML editor + switch modes + show preview
  document.getElementById('campaignContent').value = html;
  setNlMode('html');
  previewNlHtml();
}

// ── Template library ─────────────────────────────────────────
function loadNlTemplate(key){
  document.querySelectorAll('.nl-tpl-card').forEach(c => c.classList.remove('selected'));
  event.currentTarget.classList.add('selected');
  const tpls = {

    event: buildEmailShell(
      `<p style="font-size:13px;color:#667;margin:0 0 4px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;">Upcoming Auction · <?= date('F Y') ?></p>
       <h2 style="font-size:26px;font-weight:800;color:#001F5C;margin:0 0 14px;line-height:1.25;">Our Next Big Auction Is Coming</h2>`,
      `<div style="border:1px solid #e8edf2;border-radius:12px;overflow:hidden;margin-bottom:16px;">
         <div style="background:#001F5C;padding:14px 18px;">
           <p style="color:#ffd54f;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;margin:0 0 3px;">Bank Repossession Auction</p>
           <p style="color:#fff;font-size:18px;font-weight:700;margin:0;">Saturday, [DATE] — 10:00 AM</p>
         </div>
         <div style="padding:18px;">
           <p style="font-size:14px;color:#334155;line-height:1.7;margin:0 0 14px;">Over 80 vehicles going under the hammer — sedans, SUVs, bakkies, and light commercial vehicles. All DEKRA-certified with full inspection reports.</p>
           <a href="<?= CANONICAL_URL ?>/auctions.php" style="display:inline-block;background:#005DDB;color:#fff;padding:11px 26px;border-radius:50px;font-weight:700;font-size:13px;text-decoration:none;">View All Vehicles &rarr;</a>
         </div>
       </div>
       <div style="background:linear-gradient(135deg,#001F5C,#005DDB);border-radius:12px;padding:22px;text-align:center;margin-top:8px;">
         <p style="color:#fff;font-size:18px;font-weight:800;margin:0 0 8px;">Secure Your Bidder Number Today</p>
         <p style="color:rgba(255,255,255,.8);font-size:13px;margin:0 0 16px;">Registration closes 48 hours before the auction.</p>
         <a href="<?= CANONICAL_URL ?>/login.php?tab=register" style="display:inline-block;background:#ffd54f;color:#001F5C;padding:12px 28px;border-radius:50px;font-weight:800;font-size:14px;text-decoration:none;">Register Free &rarr;</a>
       </div>`
    ),

    vehicles: buildEmailShell(
      `<p style="font-size:13px;color:#667;margin:0 0 4px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;">Vehicle Spotlight · <?= date('F Y') ?></p>
       <h2 style="font-size:26px;font-weight:800;color:#001F5C;margin:0 0 14px;line-height:1.25;">Hot Vehicles Available Now</h2>`,
      `<p style="font-size:15px;color:#334155;line-height:1.7;margin:0 0 18px;">This month's selection includes some of our best repossessed and bank-consigned vehicles — all DEKRA inspected and ready to go.</p>
       <div style="display:grid;gap:14px;margin-bottom:18px;">
         <div style="border:1px solid #e8edf2;border-radius:12px;padding:16px;display:flex;gap:14px;align-items:flex-start;">
           <div style="width:80px;height:60px;background:#e8edf2;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.5rem;">🚗</div>
           <div>
             <p style="font-weight:700;color:#001F5C;font-size:15px;margin:0 0 4px;">[Year] [Make] [Model]</p>
             <p style="font-size:13px;color:#667;margin:0 0 8px;">[Mileage] km &nbsp;·&nbsp; [Transmission] &nbsp;·&nbsp; DEKRA Certified</p>
             <p style="font-size:18px;font-weight:800;color:#005DDB;margin:0 0 10px;">Starting from R [Amount]</p>
             <a href="<?= CANONICAL_URL ?>/auctions.php" style="font-size:13px;color:#005DDB;font-weight:700;text-decoration:none;">View &amp; Bid &rarr;</a>
           </div>
         </div>
         <div style="border:1px solid #e8edf2;border-radius:12px;padding:16px;display:flex;gap:14px;align-items:flex-start;">
           <div style="width:80px;height:60px;background:#e8edf2;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.5rem;">🚙</div>
           <div>
             <p style="font-weight:700;color:#001F5C;font-size:15px;margin:0 0 4px;">[Year] [Make] [Model]</p>
             <p style="font-size:13px;color:#667;margin:0 0 8px;">[Mileage] km &nbsp;·&nbsp; [Transmission] &nbsp;·&nbsp; DEKRA Certified</p>
             <p style="font-size:18px;font-weight:800;color:#005DDB;margin:0 0 10px;">Starting from R [Amount]</p>
             <a href="<?= CANONICAL_URL ?>/auctions.php" style="font-size:13px;color:#005DDB;font-weight:700;text-decoration:none;">View &amp; Bid &rarr;</a>
           </div>
         </div>
       </div>
       <div style="text-align:center;">
         <a href="<?= CANONICAL_URL ?>/auctions.php" style="display:inline-block;background:#005DDB;color:#fff;padding:13px 30px;border-radius:50px;font-weight:800;font-size:15px;text-decoration:none;">Browse All Vehicles &rarr;</a>
       </div>`
    ),

    news: buildEmailShell(
      `<p style="font-size:13px;color:#667;margin:0 0 4px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;">News &amp; Updates · <?= date('F Y') ?></p>
       <h2 style="font-size:26px;font-weight:800;color:#001F5C;margin:0 0 14px;line-height:1.25;">[Your Article Headline Here]</h2>`,
      `<img src="[ARTICLE_IMAGE_URL]" width="100%" alt="Article Image" style="border-radius:10px;display:block;margin-bottom:18px;max-height:260px;object-fit:cover;">
       <p style="font-size:15px;color:#334155;line-height:1.75;margin:0 0 14px;">[Opening paragraph — summarise what the article is about and why it matters to the reader.]</p>
       <p style="font-size:15px;color:#334155;line-height:1.75;margin:0 0 14px;">[Second paragraph — key details, tips, or insights. Replace this with your actual article content.]</p>
       <p style="font-size:15px;color:#334155;line-height:1.75;margin:0 0 20px;">[Closing paragraph — wrap up with a takeaway or a teaser for the full article.]</p>
       <div style="background:#f0f6ff;border-left:4px solid #005DDB;padding:14px 18px;border-radius:0 8px 8px 0;margin-bottom:20px;">
         <p style="font-size:14px;color:#334155;font-style:italic;margin:0;">"[Pull quote or key stat from the article]"</p>
       </div>
       <a href="<?= CANONICAL_URL ?>/news.php" style="display:inline-block;background:#005DDB;color:#fff;padding:11px 26px;border-radius:50px;font-weight:700;font-size:14px;text-decoration:none;">Read Full Article &rarr;</a>`
    ),

    finance: buildEmailShell(
      `<p style="font-size:13px;color:#667;margin:0 0 4px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;">Finance Offer · <?= date('F Y') ?></p>
       <h2 style="font-size:26px;font-weight:800;color:#001F5C;margin:0 0 14px;line-height:1.25;">Drive Today — No Credit Checks!</h2>`,
      `<p style="font-size:15px;color:#334155;line-height:1.75;margin:0 0 18px;">Blacklisted? Low credit score? Bank said no? <strong>We say YES.</strong> Our in-house finance is designed for South Africans who deserve a second chance behind the wheel.</p>
       <div style="background:linear-gradient(135deg,#fff8e1,#fff3cd);border:2px solid #ffd54f;border-radius:14px;padding:22px;margin-bottom:18px;text-align:center;">
         <p style="font-size:12px;font-weight:800;color:#e8a000;text-transform:uppercase;letter-spacing:.1em;margin:0 0 6px;">Starting Deposit</p>
         <p style="font-size:42px;font-weight:900;color:#001F5C;margin:0 0 8px;line-height:1;font-family:'Segoe UI',sans-serif;">R10,500</p>
         <p style="font-size:14px;color:#667;margin:0;">Drive away today — pay the rest monthly</p>
       </div>
       <table style="width:100%;border-collapse:collapse;margin-bottom:20px;border-radius:10px;overflow:hidden;border:1px solid #e8edf2;">
         <thead><tr style="background:#001F5C;">
           <th style="padding:10px 14px;color:#fff;font-size:12px;text-align:center;">6 months</th>
           <th style="padding:10px 14px;color:#fff;font-size:12px;text-align:center;">12 months</th>
           <th style="padding:10px 14px;color:#fff;font-size:12px;text-align:center;">24 months</th>
           <th style="padding:10px 14px;color:#fff;font-size:12px;text-align:center;">36 months</th>
           <th style="padding:10px 14px;color:#ffd54f;font-size:12px;text-align:center;font-weight:800;">48 months</th>
         </tr></thead>
         <tbody><tr style="background:#f0f6ff;">
           <td style="padding:10px;text-align:center;font-size:13px;color:#334155;">Lowest total</td>
           <td style="padding:10px;text-align:center;font-size:13px;color:#334155;">Balanced</td>
           <td style="padding:10px;text-align:center;font-size:13px;color:#334155;">Comfortable</td>
           <td style="padding:10px;text-align:center;font-size:13px;color:#334155;">Lower pmts</td>
           <td style="padding:10px;text-align:center;font-size:13px;color:#005DDB;font-weight:700;">Most flexible</td>
         </tr></tbody>
       </table>
       <div style="text-align:center;">
         <a href="<?= CANONICAL_URL ?>/financing.php" style="display:inline-block;background:#e8a000;color:#001F5C;padding:13px 32px;border-radius:50px;font-weight:900;font-size:15px;text-decoration:none;">Apply for Finance &rarr;</a>
       </div>`
    ),
  };

  const html = tpls[key] || '';
  document.getElementById('campaignContent').value = html;
  // Switch to HTML editor so they can customise
  setNlMode('html');
}

// ── HTML insertion helper ────────────────────────────────────
function insertHtml(id, tag){
  const ta = document.getElementById(id);
  const s = ta.selectionStart, e = ta.selectionEnd;
  const selected = ta.value.slice(s,e) || '…';
  const inserted = tag.replace('…', selected);
  ta.value = ta.value.slice(0,s) + inserted + ta.value.slice(e);
  ta.focus();
}

// ── Live HTML preview ────────────────────────────────────────
function previewNlHtml(){
  const html = document.getElementById('campaignContent').value;
  const preview = document.getElementById('nlHtmlPreview');
  const frame   = document.getElementById('nlPreviewFrame');
  preview.style.display = 'block';
  frame.srcdoc = html || '<p style="padding:1rem;color:#999;font-family:sans-serif;">No HTML to preview yet.</p>';
}

// ── Save campaign ────────────────────────────────────────────
async function saveCampaign(e){
  e.preventDefault();
  const mode = window._nlMode || 'plain';
  let finalHtml = '';
  if(mode === 'plain'){
    finalHtml = buildPlainTextHtml();
    if(!finalHtml){ alert('Please enter body text before saving.'); return; }
  } else {
    finalHtml = document.getElementById('campaignContent').value.trim();
    if(!finalHtml){ alert('Please enter email content before saving.'); return; }
  }
  document.getElementById('campaignContentFinal').value = finalHtml;

  const btn = document.getElementById('campaignSaveBtn');
  const msg = document.getElementById('campaignMsg');
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
  const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new FormData(e.target)});
  const d = await r.json();
  if(d.success){
    msg.style.background='rgba(110,255,158,.15)'; msg.style.color='#2e7d32';
    msg.textContent='Campaign saved as draft!'; msg.style.display='block';
    setTimeout(()=>location.reload(),900);
  } else {
    msg.style.background='rgba(255,107,107,.15)'; msg.style.color='#c62828';
    msg.textContent=d.message||'Error saving — please try again.'; msg.style.display='block';
    btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save Draft';
  }
}

async function sendCampaign(id,btn){
  const total = <?= count($adminNlSubscribers) ?>;
  if(!confirm(`Send this newsletter to all ${total} subscribers + all registered users? This cannot be undone.`)) return;
  const orig = btn.innerHTML;
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Sending…';
  const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new URLSearchParams({action:'send_newsletter_campaign',campaign_id:id})});
  const d = await r.json();
  alert(d.message||(d.success?'Sent!':'Failed'));
  if(d.success) location.reload();
  else { btn.disabled=false; btn.innerHTML=orig; }
}

async function deleteSubscriber(id){
  if(!confirm('Remove subscriber permanently?')) return;
  const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new URLSearchParams({action:'delete_newsletter_subscriber',id})});
  const d = await r.json();
  if(d.success){ const row=document.getElementById('sub-row-'+id); if(row) row.remove(); }
}

</script>

<!-- ═══════════════════════════════════════════════════════════
     TAB: NOTIFICATIONS
═══════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'notifications'): ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-bell"></i> Notifications &amp; Ticker</h1>
        <p class="text-muted"><?= count($adminNotifications) ?> popup notification<?= count($adminNotifications)!==1?'s':'' ?></p>
      </div>
    </div>

    <!-- ══ TICKER ANNOUNCEMENTS ══ -->
    <?php
    $tickerAdmin = [];
    try { $tickerAdmin = $pdo->query("SELECT * FROM news_announcements ORDER BY sort_order ASC, id ASC")->fetchAll(); } catch(Exception $e){}
    ?>
    <div class="card" style="margin-bottom:1.75rem;border-left:4px solid #005DDB;">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem;">
        <h3 style="margin:0;font-size:1rem;"><i class="fas fa-scroll" style="color:#005DDB;"></i> Ticker Announcements <span style="font-size:.78rem;color:#999;font-weight:400;">(scrolling banner at top of every page)</span></h3>
        <button onclick="resetTickerMessages()" class="btn btn-outline" style="font-size:.8rem;padding:.4rem .9rem;color:#c62828;border-color:#c62828;">
          <i class="fas fa-redo"></i> Reset to Defaults
        </button>
      </div>
      <div id="tickerAdminMsg"></div>

      <!-- Add new announcement -->
      <form id="addAnnouncementForm" onsubmit="addAnnouncement(event)" style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
        <input type="hidden" name="action" value="save_announcement">
        <input type="text" name="message" required placeholder="Announcement text (no emojis — use plain text only)"
               style="flex:1;min-width:200px;padding:.6rem .9rem;border:1px solid #dde3ec;border-radius:8px;font-size:.88rem;">
        <button type="submit" class="btn btn-primary" style="padding:.6rem 1.1rem;font-size:.85rem;white-space:nowrap;">
          <i class="fas fa-plus"></i> Add
        </button>
      </form>

      <!-- Existing announcements -->
      <?php if (empty($tickerAdmin)): ?>
      <p style="color:#999;font-size:.88rem;text-align:center;padding:1rem;">No ticker messages. Click <strong>Reset to Defaults</strong> to populate.</p>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:.5rem;" id="tickerList">
        <?php foreach ($tickerAdmin as $ta): ?>
        <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem .9rem;background:#f8f9fb;border-radius:8px;border:1px solid #eef1f5;" id="ticker-row-<?= $ta['id'] ?>">
          <i class="fas fa-grip-vertical" style="color:#ccc;cursor:grab;"></i>
          <span style="flex:1;font-size:.88rem;color:#334155;"><?= htmlspecialchars($ta['message']) ?></span>
          <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;flex-shrink:0;">
            <input type="checkbox" <?= $ta['is_active']?'checked':'' ?> onchange="toggleTicker(<?= $ta['id'] ?>,this.checked)"
                   style="accent-color:#005DDB;"> <span style="font-size:.75rem;color:#667;">Active</span>
          </label>
          <button onclick="deleteTicker(<?= $ta['id'] ?>)" style="padding:.3rem .55rem;background:#fff0f0;color:#c62828;border:none;border-radius:6px;cursor:pointer;font-size:.75rem;flex-shrink:0;">
            <i class="fas fa-trash"></i>
          </button>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <script>
    async function addAnnouncement(e){
      e.preventDefault();
      const btn = e.target.querySelector('button');
      btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
      const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new FormData(e.target)});
      const d = await r.json();
      const msg = document.getElementById('tickerAdminMsg');
      if(d.success){ location.reload(); }
      else { msg.innerHTML='<div style="background:rgba(255,107,107,.12);color:#c62828;padding:.6rem 1rem;border-radius:8px;font-size:.85rem;margin-bottom:.75rem;">'+d.message+'</div>'; btn.disabled=false; btn.innerHTML='<i class="fas fa-plus"></i> Add'; }
    }
    async function toggleTicker(id,checked){
      await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new URLSearchParams({action:'toggle_announcement',id,is_active:checked?1:0})});
    }
    async function deleteTicker(id){
      if(!confirm('Remove this ticker message?')) return;
      const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new URLSearchParams({action:'delete_announcement',id})});
      const d = await r.json();
      if(d.success){ const row=document.getElementById('ticker-row-'+id); if(row) row.remove(); }
    }
    async function resetTickerMessages(){
      if(!confirm('This will DELETE all current ticker messages and replace them with the defaults. Continue?')) return;
      const r = await fetch('<?= APP_URL ?>/api.php',{method:'POST',body:new URLSearchParams({action:'reset_ticker_messages'})});
      const d = await r.json();
      alert(d.message||(d.success?'Reset complete!':'Failed'));
      if(d.success) location.reload();
    }
    </script>

    <div class="alert alert-info" style="margin-bottom:1.5rem;">
      <i class="fas fa-info-circle"></i>
      Popup notifications appear as toast messages on the homepage. They rotate automatically to create a sense of live activity (bids, wins, leading bids). Add realistic messages to build trust and urgency.
    </div>

    <!-- ── Inline Add Notification form ────────────────────── -->
    <div class="card" style="margin-bottom:1.5rem;">
      <h3 style="margin:0 0 1rem;"><i class="fas fa-plus-circle" style="color:#1565c0;"></i> Add New Notification</h3>
      <div id="inlineNotifResult"></div>
      <form id="inlineNotifForm">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
          <div class="form-group" style="margin:0;">
            <label>Type</label>
            <select name="type" id="inlineNotifType" class="form-control">
              <option value="bid">Bid — Someone placed a bid</option>
              <option value="win">Win — Vehicle sold</option>
              <option value="leading">Leading — Someone is leading</option>
              <option value="custom" selected>Custom — General announcement</option>
            </select>
          </div>
          <div class="form-group" style="margin:0;">
            <label>Icon</label>
            <select name="icon" id="inlineNotifIcon" class="form-control">
              <option value="fa-gavel">fa-gavel (Bid)</option>
              <option value="fa-check-circle">fa-check-circle (Win/Sold)</option>
              <option value="fa-trophy">fa-trophy (Leading)</option>
              <option value="fa-bell" selected>fa-bell (General)</option>
              <option value="fa-fire">fa-fire (Hot)</option>
              <option value="fa-star">fa-star (Featured)</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Message</label>
          <textarea name="message" id="inlineNotifMessage" class="form-control" rows="2" maxlength="500"
                    placeholder="e.g. Bidder AHG-2026-047 just placed a bid of R85,000 on 2019 Toyota Hilux" required></textarea>
        </div>
        <div class="form-group" style="max-width:260px;">
          <label>Frequency (seconds between displays)</label>
          <input type="number" name="frequency" id="inlineNotifFrequency" class="form-control" value="10" min="5" max="300">
          <small class="text-muted">Minimum 5 seconds.</small>
        </div>
        <button type="submit" class="btn btn-primary" id="inlineNotifBtn">
          <i class="fas fa-plus"></i> Add Notification
        </button>
      </form>
    </div>

    <h3 style="margin:1.5rem 0 .75rem;"><i class="fas fa-list"></i> Existing Notifications</h3>
    <div class="card">
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th style="width:80px">Type</th>
              <th>Message</th>
              <th style="width:80px">Freq.</th>
              <th style="width:80px">Active</th>
              <th style="width:120px">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($adminNotifications as $n): ?>
          <tr id="notif-row-<?= $n['id'] ?>">
            <td>
              <?php
                $typeColors = ['bid'=>'#1565c0','win'=>'#4caf50','leading'=>'#f9a825','custom'=>'#9c27b0'];
                $tc = $typeColors[$n['type']] ?? '#888';
              ?>
              <span style="display:inline-block;padding:.25rem .6rem;border-radius:6px;font-size:.72rem;font-weight:700;text-transform:uppercase;color:#fff;background:<?= $tc ?>;">
                <?= e($n['type']) ?>
              </span>
            </td>
            <td style="font-size:.9rem;"><?= e($n['message']) ?></td>
            <td style="text-align:center;font-size:.85rem;font-weight:600;"><?= (int)($n['frequency'] ?? 10) ?>s</td>
            <td>
              <label class="toggle-switch" style="margin:0;">
                <input type="checkbox" <?= $n['is_active'] ? 'checked' : '' ?>
                       onchange="updateNotification(<?= $n['id'] ?>, 'is_active', this.checked?1:0)">
                <span class="toggle-slider"></span>
              </label>
            </td>
            <td>
              <div class="table-actions">
                <button class="btn btn-outline btn-sm" onclick="editNotification(<?= $n['id'] ?>, <?= htmlspecialchars(json_encode($n['message']), ENT_QUOTES) ?>, '<?= e($n['type']) ?>', <?= (int)($n['frequency'] ?? 10) ?>)" title="Edit">
                  <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-danger btn-sm" onclick="deleteNotification(<?= $n['id'] ?>)" title="Delete">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($adminNotifications)): ?>
          <tr><td colspan="5" class="text-center text-muted" style="padding:2rem">No notifications yet. Click "Add Notification" to create one.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" style="margin-top:1.5rem;padding:1.25rem;">
      <h4 style="margin:0 0 .75rem;"><i class="fas fa-lightbulb" style="color:#f9a825;"></i> Suggested Notification Messages</h4>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;font-size:.85rem;color:#555;">
        <div><strong>Bid:</strong> "Bidder AHG-2026-XXX just placed a bid of R85,000 on 2019 Toyota Hilux"</div>
        <div><strong>Win:</strong> "2018 Ford Ranger SOLD to Bidder AHG-2026-XXX for R142,500!"</div>
        <div><strong>Leading:</strong> "Bidder AHG-2026-XXX is now leading on 2020 VW Polo Vivo"</div>
        <div><strong>Custom:</strong> "New auction starting soon — 2021 Hyundai Tucson from Standard Bank"</div>
      </div>
    </div>

<?php elseif ($activeTab === 'careers'): ?>

<div class="dashboard-header">
  <div>
    <h1><i class="fas fa-user-tie"></i> Job Applications</h1>
    <p class="text-muted">Sales Representative — Vehicle Advertising</p>
  </div>
  <a href="careers.php" target="_blank" class="btn btn-secondary" style="font-size:.82rem;">
    <i class="fas fa-external-link-alt"></i> View Career Page
  </a>
</div>

<!-- Stats row -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;">
  <?php
  $cStatsMap = [
    ['total',    '#003f73', 'fa-inbox',        'Total Applications'],
    ['pending',  '#f5a623', 'fa-clock',        'Pending Review'],
    ['approved', '#28a745', 'fa-circle-check', 'Approved'],
    ['rejected', '#dc3545', 'fa-circle-xmark', 'Rejected'],
  ];
  foreach ($cStatsMap as [$key,$col,$ico,$lbl]):
  ?>
  <div style="background:#fff;border-radius:10px;padding:1.1rem;box-shadow:0 1px 6px rgba(0,0,0,.06);text-align:center;">
    <i class="fas <?= $ico ?>" style="color:<?= $col ?>;font-size:1.3rem;margin-bottom:.4rem;display:block;"></i>
    <div style="font-size:1.6rem;font-weight:800;color:<?= $col ?>;line-height:1;"><?= $careerStats[$key] ?? 0 ?></div>
    <div style="font-size:.73rem;color:#888;margin-top:.2rem;text-transform:uppercase;letter-spacing:.04em;"><?= $lbl ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (empty($careerApplications)): ?>
  <div style="background:#fff;border-radius:10px;padding:2.5rem;text-align:center;color:#999;box-shadow:0 1px 6px rgba(0,0,0,.06);">
    <i class="fas fa-inbox" style="font-size:2rem;margin-bottom:.75rem;display:block;opacity:.4;"></i>
    No applications received yet.
    <br><a href="careers.php" target="_blank" style="color:#005BA1;font-size:.88rem;">Share the career page →</a>
  </div>
<?php else: ?>
  <div style="background:#fff;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,.06);overflow:hidden;">
    <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
      <thead>
        <tr style="background:#f5f7fa;">
          <th style="padding:.75rem 1rem;text-align:left;color:#555;font-weight:600;border-bottom:2px solid #e8edf2;">#</th>
          <th style="padding:.75rem 1rem;text-align:left;color:#555;font-weight:600;border-bottom:2px solid #e8edf2;">Applicant</th>
          <th style="padding:.75rem 1rem;text-align:left;color:#555;font-weight:600;border-bottom:2px solid #e8edf2;">Phone</th>
          <th style="padding:.75rem 1rem;text-align:left;color:#555;font-weight:600;border-bottom:2px solid #e8edf2;">Documents</th>
          <th style="padding:.75rem 1rem;text-align:left;color:#555;font-weight:600;border-bottom:2px solid #e8edf2;">Date</th>
          <th style="padding:.75rem 1rem;text-align:left;color:#555;font-weight:600;border-bottom:2px solid #e8edf2;">Status</th>
          <th style="padding:.75rem 1rem;text-align:left;color:#555;font-weight:600;border-bottom:2px solid #e8edf2;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($careerApplications as $app): ?>
        <tr style="border-bottom:1px solid #f0f3f8;" id="car-row-<?= $app['id'] ?>">
          <td style="padding:.7rem 1rem;color:#888;"><?= $app['id'] ?></td>
          <td style="padding:.7rem 1rem;">
            <div style="font-weight:700;color:#003f73;"><?= e($app['full_name']) ?></div>
            <div style="color:#888;font-size:.77rem;"><?= e($app['email']) ?></div>
          </td>
          <td style="padding:.7rem 1rem;"><?= e($app['phone']) ?></td>
          <td style="padding:.7rem 1rem;">
            <?php if ($app['cv_path']): ?>
              <a href="<?= APP_URL.'/serve_career_doc.php?file='.urlencode($app['cv_path']) ?>" target="_blank" title="View CV"
                 style="display:inline-block;margin:.1rem .15rem;background:#ebf4ff;color:#005BA1;padding:.2rem .55rem;border-radius:5px;font-size:.73rem;font-weight:600;">
                <i class="fas fa-file-alt"></i> CV
              </a>
            <?php endif; ?>
            <?php if ($app['id_doc_path']): ?>
              <a href="<?= APP_URL.'/serve_career_doc.php?file='.urlencode($app['id_doc_path']) ?>" target="_blank" title="View ID"
                 style="display:inline-block;margin:.1rem .15rem;background:#fff3e0;color:#e65100;padding:.2rem .55rem;border-radius:5px;font-size:.73rem;font-weight:600;">
                <i class="fas fa-id-card"></i> ID
              </a>
            <?php endif; ?>
            <?php if ($app['cert_path']): ?>
              <a href="<?= APP_URL.'/serve_career_doc.php?file='.urlencode($app['cert_path']) ?>" target="_blank" title="View Certification"
                 style="display:inline-block;margin:.1rem .15rem;background:#e8f5e9;color:#2e7d32;padding:.2rem .55rem;border-radius:5px;font-size:.73rem;font-weight:600;">
                <i class="fas fa-certificate"></i> Cert
              </a>
            <?php endif; ?>
            <?php if (!empty($app['video_path'])): ?>
              <a href="<?= APP_URL.'/serve_career_doc.php?file='.urlencode($app['video_path']) ?>" target="_blank" title="Watch Video Introduction"
                 style="display:inline-block;margin:.1rem .15rem;background:#fce4ec;color:#880e4f;padding:.2rem .55rem;border-radius:5px;font-size:.73rem;font-weight:600;">
                <i class="fas fa-video"></i> Video
              </a>
            <?php endif; ?>
          </td>
          <td style="padding:.7rem 1rem;white-space:nowrap;color:#888;"><?= date('d M Y', strtotime($app['submitted_at'])) ?></td>
          <td style="padding:.7rem 1rem;">
            <?php
            $statusColors = ['pending'=>['#fff3cd','#856404'],'reviewing'=>['#cce5ff','#004085'],'approved'=>['#d4edda','#155724'],'rejected'=>['#f8d7da','#721c24']];
            $sc = $statusColors[$app['status']] ?? ['#eee','#333'];
            ?>
            <span style="background:<?= $sc[0] ?>;color:<?= $sc[1] ?>;padding:.25rem .65rem;border-radius:12px;font-size:.75rem;font-weight:700;">
              <?= ucfirst($app['status']) ?>
            </span>
            <?php if (!empty($app['contract_signed_at'])): ?>
            <span title="Contract signed on <?= date('d M Y H:i', strtotime($app['contract_signed_at'])) ?>"
                  style="display:inline-flex;align-items:center;gap:.25rem;background:#e6f9ee;color:#1a5c3a;padding:.2rem .6rem;border-radius:12px;font-size:.72rem;font-weight:700;margin-top:.25rem;border:1px solid #b2dfdb;">
              <i class="fas fa-signature" style="font-size:.65rem;"></i> Signed
            </span>
            <?php elseif (!empty($app['induction_sent_at'])): ?>
            <span title="Induction email sent — awaiting signature"
                  style="display:inline-flex;align-items:center;gap:.25rem;background:#fff8e1;color:#7d5a00;padding:.2rem .6rem;border-radius:12px;font-size:.72rem;font-weight:700;margin-top:.25rem;border:1px solid #ffe082;">
              <i class="fas fa-hourglass-half" style="font-size:.65rem;"></i> Awaiting Sig.
            </span>
            <?php endif; ?>
          </td>
          <td style="padding:.7rem 1rem;">
            <button onclick="openCareerModal(<?= htmlspecialchars(json_encode($app), ENT_QUOTES) ?>)"
                    style="background:#005BA1;color:#fff;border:none;padding:.35rem .75rem;border-radius:6px;font-size:.77rem;font-weight:600;cursor:pointer;margin-right:.25rem;">
              <i class="fas fa-eye"></i> View
            </button>
            <?php if ($app['status'] === 'approved' && empty($app['contract_signed_at'])): ?>
            <button onclick="sendCareerContract(<?= $app['id'] ?>, this)"
                    style="background:#f59e0b;color:#fff;border:none;padding:.35rem .75rem;border-radius:6px;font-size:.77rem;font-weight:600;cursor:pointer;margin-right:.25rem;"
                    title="Send induction letter + contract to applicant">
              <i class="fas fa-file-signature"></i> Send Contract
            </button>
            <?php endif; ?>
            <?php if (!empty($app['contract_signed_at'])): ?>
            <button onclick="openWorkerModal(<?= $app['id'] ?>, '<?= e($app['full_name']) ?>', '<?= e($app['phone']) ?>')"
                    style="background:#28a745;color:#fff;border:none;padding:.35rem .75rem;border-radius:6px;font-size:.77rem;font-weight:600;cursor:pointer;">
              <i class="fas fa-user-plus"></i> Create Worker
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<!-- ── Application Detail Modal ─────────────────────────── -->
<div id="careerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;overflow-y:auto;padding:1.5rem;">
  <div style="background:#fff;border-radius:14px;max-width:600px;margin:0 auto;padding:2rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;border-bottom:1px solid #f0f3f8;padding-bottom:.75rem;">
      <h3 style="color:#003f73;font-size:1.1rem;margin:0;" id="cmTitle">Application</h3>
      <button onclick="document.getElementById('careerModal').style.display='none'" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#888;">&times;</button>
    </div>
    <div id="cmBody"></div>
    <div style="margin-top:1.5rem;display:flex;gap:.75rem;flex-wrap:wrap;">
      <select id="cmStatus" style="padding:.55rem .85rem;border:1.5px solid #d0dae8;border-radius:8px;font-size:.88rem;flex:1;min-width:140px;">
        <option value="pending">Pending</option>
        <option value="reviewing">Reviewing</option>
        <option value="approved">Approved</option>
        <option value="rejected">Rejected</option>
      </select>
      <textarea id="cmNotes" placeholder="Admin notes (optional)" rows="2"
        style="padding:.55rem .85rem;border:1.5px solid #d0dae8;border-radius:8px;font-size:.88rem;resize:none;flex:2;min-width:180px;font-family:inherit;"></textarea>
      <button onclick="updateCareerStatus()" style="background:#005BA1;color:#fff;border:none;padding:.6rem 1.25rem;border-radius:8px;font-size:.88rem;font-weight:700;cursor:pointer;">
        Save Status
      </button>
    </div>
    <div id="cmMsg" style="margin-top:.75rem;font-size:.85rem;"></div>
  </div>
</div>

<!-- ── Create Worker Modal ───────────────────────────────── -->
<div id="workerCreateModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;padding:1.5rem;display:flex;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:400px;padding:2rem;box-shadow:0 8px 40px rgba(0,0,0,.2);">
    <h3 style="color:#003f73;margin-bottom:1.25rem;font-size:1rem;"><i class="fas fa-user-plus" style="margin-right:.4rem;"></i>Create Worker Account</h3>
    <form id="workerCreateForm">
      <input type="hidden" name="action" value="create_worker">
      <input type="hidden" id="wcAppId" name="app_id">
      <div style="margin-bottom:.85rem;">
        <label style="font-size:.83rem;font-weight:600;color:#334155;display:block;margin-bottom:.3rem;">Full Name</label>
        <input type="text" id="wcName" name="full_name" class="form-control" readonly
          style="padding:.65rem .85rem;border:1.5px solid #d0dae8;border-radius:8px;font-size:.88rem;width:100%;background:#f8fafc;">
      </div>
      <div style="margin-bottom:.85rem;">
        <label style="font-size:.83rem;font-weight:600;color:#334155;display:block;margin-bottom:.3rem;">Phone Number</label>
        <input type="tel" id="wcPhone" name="phone"
          style="padding:.65rem .85rem;border:1.5px solid #d0dae8;border-radius:8px;font-size:.88rem;width:100%;">
      </div>
      <div style="margin-bottom:.85rem;">
        <label style="font-size:.83rem;font-weight:600;color:#334155;display:block;margin-bottom:.3rem;">4-Digit PIN</label>
        <input type="text" id="wcPin" name="pin" maxlength="6" placeholder="e.g. 1234"
          style="padding:.65rem .85rem;border:1.5px solid #d0dae8;border-radius:8px;font-size:.88rem;width:100%;letter-spacing:.15em;">
      </div>
      <div style="margin-bottom:1.25rem;">
        <label style="font-size:.83rem;font-weight:600;color:#334155;display:block;margin-bottom:.3rem;">Worker Code</label>
        <input type="text" id="wcCode" name="worker_code" placeholder="e.g. AHG-DM-003"
          style="padding:.65rem .85rem;border:1.5px solid #d0dae8;border-radius:8px;font-size:.88rem;width:100%;">
      </div>
      <div id="wcMsg" style="font-size:.83rem;margin-bottom:.75rem;"></div>
      <div style="display:flex;gap:.75rem;">
        <button type="submit" style="background:#28a745;color:#fff;border:none;padding:.7rem 1.5rem;border-radius:8px;font-size:.88rem;font-weight:700;cursor:pointer;flex:1;">
          <i class="fas fa-check"></i> Create Account
        </button>
        <button type="button" onclick="document.getElementById('workerCreateModal').style.display='none'"
          style="background:#f0f3f8;color:#555;border:none;padding:.7rem 1.25rem;border-radius:8px;font-size:.88rem;cursor:pointer;">
          Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<script>
var _cmId = null;

function openCareerModal(app) {
    _cmId = app.id;
    document.getElementById('cmTitle').textContent = app.full_name + ' — Application #' + app.id;
    document.getElementById('cmStatus').value = app.status;
    document.getElementById('cmNotes').value = app.admin_notes || '';
    document.getElementById('cmMsg').textContent = '';
    var b = document.getElementById('cmBody');
    b.innerHTML = '<div style="display:grid;gap:.6rem;font-size:.85rem;">' +
      (app.position_applied ? '<div><strong style="color:#003f73">Position:</strong> <span style="background:#e8f0fe;color:#1a56db;padding:.15rem .6rem;border-radius:12px;font-size:.82rem;font-weight:600;">'+app.position_applied+'</span></div>' : '') +
      '<div><strong style="color:#003f73">Email:</strong> ' + (app.email||'—') + '</div>' +
      '<div><strong style="color:#003f73">Phone:</strong> ' + (app.phone||'—') + '</div>' +
      '<div><strong style="color:#003f73">Strengths:</strong><br><span style="color:#555;white-space:pre-wrap">' + (app.strengths||'—') + '</span></div>' +
      '<div><strong style="color:#003f73">Weaknesses:</strong><br><span style="color:#555;white-space:pre-wrap">' + (app.weaknesses||'—') + '</span></div>' +
      (app.ref_companies ? '<div><strong style="color:#003f73">References:</strong><br><span style="color:#555;white-space:pre-wrap">'+app.ref_companies+'</span></div>' : '') +
      (app.cover_letter ? '<div><strong style="color:#003f73">Cover Letter:</strong><br><span style="color:#555;white-space:pre-wrap">'+app.cover_letter+'</span></div>' : '') +
      '</div>';
    document.getElementById('careerModal').style.display = 'block';
}

async function updateCareerStatus() {
    var status = document.getElementById('cmStatus').value;
    var notes  = document.getElementById('cmNotes').value;
    var msg    = document.getElementById('cmMsg');
    try {
        var res  = await fetch('<?= APP_URL ?>/admin_career_api.php', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=update_status&id=' + _cmId + '&status=' + encodeURIComponent(status) + '&notes=' + encodeURIComponent(notes)
        });
        var data = await res.json();
        msg.style.color = data.success ? '#155724' : '#721c24';
        msg.textContent = data.message;
        if (data.success) {
            var row = document.getElementById('car-row-' + _cmId);
            if (row) {
                var statusColors = {pending:['#fff3cd','#856404'],reviewing:['#cce5ff','#004085'],approved:['#d4edda','#155724'],rejected:['#f8d7da','#721c24']};
                var sc = statusColors[status] || ['#eee','#333'];
                row.querySelector('[style*="border-radius:12px"]').textContent = status.charAt(0).toUpperCase() + status.slice(1);
                row.querySelector('[style*="border-radius:12px"]').style.background = sc[0];
                row.querySelector('[style*="border-radius:12px"]').style.color = sc[1];
            }
        }
    } catch(e) {
        msg.style.color = '#721c24';
        msg.textContent = 'Request failed.';
    }
}

async function sendCareerContract(appId, btn) {
    if (!confirm('Send the induction letter and contract to this applicant?')) return;
    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
    try {
        var res  = await fetch('<?= APP_URL ?>/admin_career_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=send_contract&id=' + appId
        });
        var data = await res.json();
        if (data.success) {
            btn.style.background = '#155724';
            btn.style.color = '#fff';
            btn.innerHTML = '<i class="fas fa-check"></i> Sent';
            btn.title = data.message;
        } else {
            btn.disabled = false;
            btn.innerHTML = orig;
            alert('Error: ' + data.message);
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = orig;
        alert('Request failed. Check the browser console for details.');
    }
}

function openWorkerModal(appId, name, phone) {
    document.getElementById('wcAppId').value = appId;
    document.getElementById('wcName').value  = name;
    document.getElementById('wcPhone').value = phone;
    document.getElementById('wcPin').value   = '';
    document.getElementById('wcCode').value  = '';
    document.getElementById('wcMsg').textContent = '';
    document.getElementById('workerCreateModal').style.display = 'flex';
}

document.getElementById('workerCreateForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var msg = document.getElementById('wcMsg');
    msg.textContent = 'Creating…';
    try {
        var res  = await fetch('<?= APP_URL ?>/admin_career_api.php', {method:'POST', credentials:'same-origin', body: new FormData(this)});
        var data = await res.json();
        msg.style.color = data.success ? '#155724' : '#721c24';
        msg.textContent = data.message;
        if (data.success) {
            setTimeout(function(){ document.getElementById('workerCreateModal').style.display='none'; location.reload(); }, 1500);
        }
    } catch(e) {
        msg.style.color = '#721c24'; msg.textContent = 'Request failed.';
    }
});
</script>

<?php elseif ($activeTab === 'ai'):
    require_once __DIR__ . '/ai_image_helper.php';
    $aiMasked = aiMaskedKey();
    $aiLot    = (int)($_GET['lot'] ?? 0); ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-wand-magic-sparkles"></i> AI Photos</h1>
        <p class="text-muted">Tidy vehicle photos with Grok (xAI): clean backgrounds, better lighting, blurred plates.</p>
      </div>
    </div>

    <!-- Key + model -->
    <div class="card" style="margin-bottom:1.25rem;">
      <h3 style="margin-top:0"><i class="fas fa-key"></i> xAI API key</h3>
      <p class="text-muted" style="font-size:.86rem;margin-top:0;">
        Create a key at <a href="https://console.x.ai" target="_blank" rel="noopener">console.x.ai</a> → API Keys, then paste it here.
        It's stored in the database only (never in the code or GitHub). Each edit is billed to that xAI account.
      </p>
      <div id="aiKeyResult"></div>
      <p style="margin:.25rem 0 .75rem;">
        Status:
        <?php if ($aiMasked): ?>
          <span class="badge badge-success"><i class="fas fa-check"></i> Key saved</span> <code><?= e($aiMasked) ?></code>
        <?php else: ?>
          <span class="badge badge-closed">No key yet</span>
        <?php endif; ?>
      </p>
      <form id="aiKeyForm" class="ai-key-form" autocomplete="off">
        <input type="password" name="api_key" class="form-control" placeholder="<?= $aiMasked ? 'Paste a new key to replace it' : 'xai-…' ?>" autocomplete="new-password">
        <input type="text" name="model" class="form-control" value="<?= e(aiModel()) ?>" title="Image model" style="max-width:240px">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
        <?php if ($aiMasked): ?>
          <button type="button" class="btn btn-outline" onclick="aiTestKey()"><i class="fas fa-plug"></i> Test</button>
          <button type="button" class="btn btn-outline" style="color:#c62828;border-color:#c62828" onclick="aiClearKey()"><i class="fas fa-trash"></i> Remove</button>
        <?php endif; ?>
      </form>
    </div>

    <!-- Editor -->
    <div class="card">
      <h3 style="margin-top:0"><i class="fas fa-image"></i> Edit a vehicle photo</h3>
      <div class="alert alert-info" style="font-size:.84rem;">
        <i class="fas fa-scale-balanced"></i> Buyers rely on these photos. Every edit is told to keep the vehicle exactly as it is,
        including any damage. Only use it to tidy the picture, never to hide faults.
      </div>
      <form id="aiLotForm" class="ai-key-form" style="margin-bottom:1rem;">
        <input type="number" id="aiLotId" class="form-control" placeholder="Lot ID (shown on each vehicle card as ID #…)" min="1" value="<?= $aiLot ?: '' ?>" style="max-width:320px">
        <button type="submit" class="btn btn-primary"><i class="fas fa-images"></i> Load photos</button>
      </form>
      <div id="aiLotTitle" class="ai-lot-title"></div>
      <div id="aiPhotos" class="ai-photos"></div>

      <div id="aiControls" style="display:none;">
        <h4 style="margin:1.25rem 0 .5rem;">What should change?</h4>
        <div class="ai-presets">
          <?php foreach (AI_PRESETS as $k => [$label, $text]): ?>
            <button type="button" class="btn btn-outline btn-sm ai-preset" data-preset="<?= e($k) ?>" title="<?= e($text) ?>"><?= e($label) ?></button>
          <?php endforeach; ?>
        </div>
        <textarea id="aiInstruction" class="form-control" rows="2" maxlength="600" placeholder="…or describe it, e.g. Remove the orange cones in front of the car" style="margin-top:.6rem;"></textarea>
        <button type="button" class="btn btn-primary" id="aiRunBtn" style="margin-top:.6rem;" <?= $aiMasked ? '' : 'disabled title="Save an xAI key first"' ?>>
          <i class="fas fa-wand-magic-sparkles"></i> Run edit
        </button>
        <small class="text-muted" style="margin-left:.5rem;">Takes 10–60 seconds.</small>
      </div>

      <div id="aiResult" class="ai-result" style="display:none;">
        <div class="ai-compare">
          <figure><figcaption>Before</figcaption><img id="aiBefore" alt="Original photo"></figure>
          <figure><figcaption>After (not saved yet)</figcaption><img id="aiAfter" alt="AI-edited photo"></figure>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem;">
          <button type="button" class="btn btn-primary" onclick="aiSave('main')"><i class="fas fa-star"></i> Use as main photo</button>
          <button type="button" class="btn btn-outline" onclick="aiSave('gallery')"><i class="fas fa-plus"></i> Add to gallery</button>
          <button type="button" class="btn btn-outline" onclick="aiDiscard()"><i class="fas fa-xmark"></i> Discard</button>
        </div>
        <small class="text-muted">Saving keeps the original: "main photo" moves the old one into the gallery.</small>
      </div>
    </div>

    <style>
      .ai-key-form { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
      .ai-key-form .form-control { flex:1; min-width:220px; }
      .ai-lot-title { font-weight:700; color:#003f73; margin-bottom:.5rem; }
      .ai-photos { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:.5rem; }
      .ai-photos button { padding:0; border:3px solid transparent; border-radius:8px; overflow:hidden; cursor:pointer; background:#eef2f7; height:86px; position:relative; }
      .ai-photos button.selected { border-color:#1565c0; }
      .ai-photos img { width:100%; height:100%; object-fit:cover; }
      .ai-photos .ai-main-tag { position:absolute; left:4px; top:4px; background:#1565c0; color:#fff; font-size:.62rem; font-weight:700; padding:1px 6px; border-radius:8px; }
      .ai-presets { display:flex; gap:.4rem; flex-wrap:wrap; }
      .ai-preset.active { background:#1565c0; color:#fff; border-color:#1565c0; }
      .ai-result { margin-top:1.25rem; padding-top:1rem; border-top:1px solid #eef2f7; }
      .ai-compare { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
      .ai-compare figure { margin:0; }
      .ai-compare figcaption { font-size:.78rem; font-weight:700; color:#667; margin-bottom:.3rem; }
      .ai-compare img { width:100%; border-radius:8px; background:#eef2f7; min-height:120px; object-fit:contain; }
      @media (max-width:640px) { .ai-compare { grid-template-columns:1fr; } }
    </style>
    <script>
    (function () {
        const post = async (fields) => {
            const fd = new FormData();
            Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
            return (await fetch('api.php', {method: 'POST', body: fd})).json();
        };
        const say = (el, ok, msg) => document.getElementById(el).innerHTML =
            `<div class="alert alert-${ok ? 'success' : 'error'}" style="margin-bottom:.75rem">${String(msg).replace(/</g, '&lt;')}</div>`;
        let lotId = 0, photoUrl = '', preset = '', token = '';

        document.getElementById('aiKeyForm').addEventListener('submit', async e => {
            e.preventDefault();
            const f = e.target, btn = f.querySelector('button[type=submit]');
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking…';
            try {
                const d = await post({action: 'ai_settings_save', api_key: f.api_key.value.trim(), model: f.model.value.trim()});
                say('aiKeyResult', d.success, d.message);
                if (d.success) setTimeout(() => location.reload(), 1200);
            } catch { say('aiKeyResult', false, 'Could not save.'); }
            finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save'; }
        });
        window.aiTestKey = async () => {
            const d = await post({action: 'ai_settings_test'});
            say('aiKeyResult', d.success, d.message);
        };
        window.aiClearKey = async () => {
            if (!confirm('Remove the saved xAI key? AI photo editing stops until a new key is saved.')) return;
            const d = await post({action: 'ai_settings_save', clear: 1});
            say('aiKeyResult', d.success, d.message);
            if (d.success) setTimeout(() => location.reload(), 900);
        };

        async function loadLot(id) {
            const box = document.getElementById('aiPhotos');
            box.innerHTML = '<span class="text-muted">Loading…</span>';
            document.getElementById('aiResult').style.display = 'none';
            const d = await (await fetch('api.php?action=ai_lot_photos&auction_id=' + id)).json();
            if (!d.success) { box.innerHTML = ''; document.getElementById('aiLotTitle').textContent = d.message; return; }
            lotId = id; photoUrl = '';
            document.getElementById('aiLotTitle').innerHTML = '#' + id + ' · ' + d.lot.item_name.replace(/</g, '&lt;')
                + ' <a href="auction.php?id=' + id + '" target="_blank" style="font-weight:400;font-size:.82rem">view lot ↗</a>';
            if (!d.photos.length) { box.innerHTML = '<span class="text-muted">This lot has no photos.</span>'; return; }
            box.innerHTML = d.photos.map((p, i) =>
                `<button type="button" data-url="${p.url.replace(/"/g, '&quot;')}">${p.kind === 'main' ? '<span class="ai-main-tag">MAIN</span>' : ''}<img src="${p.thumb.replace(/"/g, '&quot;')}" alt="Photo ${i + 1}" loading="lazy"></button>`).join('');
            box.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
                box.querySelectorAll('button').forEach(x => x.classList.remove('selected'));
                b.classList.add('selected');
                photoUrl = b.dataset.url;
                document.getElementById('aiControls').style.display = '';
            }));
            box.querySelector('button').click();
        }
        document.getElementById('aiLotForm').addEventListener('submit', e => {
            e.preventDefault();
            const id = parseInt(document.getElementById('aiLotId').value, 10);
            if (id > 0) loadLot(id);
        });
        <?php if ($aiLot): ?>loadLot(<?= $aiLot ?>);<?php endif; ?>

        document.querySelectorAll('.ai-preset').forEach(b => b.addEventListener('click', () => {
            const on = !b.classList.contains('active');
            document.querySelectorAll('.ai-preset').forEach(x => x.classList.remove('active'));
            b.classList.toggle('active', on);
            preset = on ? b.dataset.preset : '';
            if (on) document.getElementById('aiInstruction').value = '';
        }));
        document.getElementById('aiInstruction').addEventListener('input', () => {
            preset = ''; document.querySelectorAll('.ai-preset').forEach(x => x.classList.remove('active'));
        });

        document.getElementById('aiRunBtn').addEventListener('click', async () => {
            const instruction = document.getElementById('aiInstruction').value.trim();
            if (!photoUrl) { showToast('Pick a photo first.', 'error'); return; }
            if (!preset && !instruction) { showToast('Pick a preset or describe the change.', 'error'); return; }
            const btn = document.getElementById('aiRunBtn');
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Editing… (10–60 s)';
            try {
                const d = await post({action: 'ai_edit', auction_id: lotId, photo_url: photoUrl, preset: preset, instruction: instruction});
                if (!d.success) { showToast(d.message, 'error'); return; }
                token = d.token;
                document.getElementById('aiBefore').src = document.querySelector('.ai-photos button.selected img').src.replace(/tr:w-\d+/, 'tr:w-1200');
                document.getElementById('aiAfter').src = d.preview;
                document.getElementById('aiResult').style.display = '';
                document.getElementById('aiResult').scrollIntoView({behavior: 'smooth', block: 'start'});
            } catch { showToast('The edit timed out or failed. Try again.', 'error'); }
            finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Run edit'; }
        });

        window.aiSave = async (mode) => {
            if (!token) return;
            const d = await post({action: 'ai_save', auction_id: lotId, token: token, mode: mode});
            showToast(d.message, d.success ? 'success' : 'error');
            if (d.success) { token = ''; document.getElementById('aiResult').style.display = 'none'; loadLot(lotId); }
        };
        window.aiDiscard = () => { token = ''; document.getElementById('aiResult').style.display = 'none'; };
    })();
    </script>

<?php elseif ($activeTab === 'workers'): ?>

<div class="dashboard-header">
  <div>
    <h1><i class="fas fa-id-badge"></i> Workers</h1>
    <p class="text-muted">Approved Sales Representatives — daily activity tracking</p>
  </div>
  <a href="worker_portal.php" target="_blank" class="btn btn-secondary" style="font-size:.82rem;">
    <i class="fas fa-external-link-alt"></i> Open Worker Portal
  </a>
</div>

<?php if (empty($adminWorkers)): ?>
  <div style="background:#fff;border-radius:10px;padding:2.5rem;text-align:center;color:#999;box-shadow:0 1px 6px rgba(0,0,0,.06);">
    <i class="fas fa-id-badge" style="font-size:2rem;margin-bottom:.75rem;display:block;opacity:.4;"></i>
    No workers yet. Approve an application on the <a href="?tab=careers" style="color:#005BA1;">Job Applications</a> tab and create their worker account.
  </div>
<?php else: ?>
  <?php foreach ($adminWorkers as $w):
    $todayProofs = [];
    if ($w['today_proof_paths']) $todayProofs = json_decode($w['today_proof_paths'], true) ?: [];
    if (empty($todayProofs) && $w['today_proof']) $todayProofs = [$w['today_proof']];
    $todayPlatforms = array_filter(explode(',', $w['today_platforms'] ?? ''));
    $wLeads = $adminWorkerLeads[$w['today_log_id']] ?? [];
    $tsc = ['submitted'=>['#e8f0fe','#1a4098'],'verified'=>['#d4edda','#155724'],'rejected'=>['#f8d7da','#721c24']];
    $tc  = $tsc[$w['today_status'] ?? ''] ?? ['#eee','#555'];
    $wsc = ['active'=>['#d4edda','#155724'],'inactive'=>['#e9ecef','#555'],'suspended'=>['#f8d7da','#721c24']];
    $ws  = $wsc[$w['status']] ?? ['#eee','#333'];
  ?>
  <div style="background:#fff;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,.06);margin-bottom:1rem;overflow:hidden;">
    <!-- Worker header row -->
    <div style="display:flex;align-items:center;gap:1rem;padding:.9rem 1.25rem;border-bottom:1px solid #f0f3f8;flex-wrap:wrap;">
      <div style="min-width:180px;">
        <div style="font-weight:700;color:#003f73;font-size:.92rem;"><?= e($w['full_name']) ?></div>
        <div style="color:#888;font-size:.75rem;"><?= e($w['phone']) ?> &bull;
          <span style="color:#005BA1;font-weight:600;"><?= e($w['worker_code'] ?? '—') ?></span></div>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
        <span style="background:#f0f6fc;color:#003f73;border-radius:8px;padding:.3rem .7rem;font-size:.78rem;font-weight:700;">
          <i class="fas fa-pen-to-square" style="color:#5a9fd4;"></i> <?= (int)$w['month_posts'] ?> posts/mo
        </span>
        <span style="background:#f0f6fc;color:#005BA1;border-radius:8px;padding:.3rem .7rem;font-size:.78rem;font-weight:700;">
          <i class="fas fa-users" style="color:#5a9fd4;"></i> <?= (int)$w['month_leads'] ?> leads/mo
        </span>
        <span style="background:<?= $ws[0] ?>;color:<?= $ws[1] ?>;padding:.25rem .65rem;border-radius:10px;font-size:.73rem;font-weight:700;">
          <?= ucfirst($w['status']) ?>
        </span>
        <span style="color:#aaa;font-size:.75rem;">
          Last login: <?= $w['last_login'] ? date('d M H:i', strtotime($w['last_login'])) : 'Never' ?>
        </span>
      </div>
      <?php if ($w['today_status']): ?>
      <span style="margin-left:auto;background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;padding:.25rem .75rem;border-radius:10px;font-size:.75rem;font-weight:700;">
        Today: <?= ucfirst($w['today_status']) ?>
        <?php if ($w['today_posts']): ?>&nbsp;&bull;&nbsp;<?= (int)$w['today_posts'] ?> posts<?php endif; ?>
      </span>
      <?php else: ?>
      <span style="margin-left:auto;color:#ccc;font-size:.8rem;">No log today</span>
      <?php endif; ?>
    </div>

    <?php if ($w['today_status']): ?>
    <!-- Today's log detail -->
    <div style="padding:.85rem 1.25rem;">

      <!-- Platforms -->
      <?php if (!empty($todayPlatforms)): ?>
      <div style="margin-bottom:.75rem;">
        <span style="font-size:.74rem;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.05em;">Platforms Posted Today</span>
        <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.35rem;">
          <?php foreach ($todayPlatforms as $pt): ?>
          <span style="background:#e8f0fe;color:#1a4098;padding:.2rem .55rem;border-radius:10px;font-size:.74rem;font-weight:600;">
            <?= htmlspecialchars(trim($pt)) ?>
          </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start;">
        <!-- Proof images -->
        <div>
          <span style="font-size:.74rem;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.4rem;">
            Proof Images (<?= count($todayProofs) ?>)
          </span>
          <?php if (!empty($todayProofs)): ?>
          <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
            <?php foreach ($todayProofs as $idx => $ep): ?>
            <a href="<?= APP_URL.'/'.e($ep) ?>" target="_blank"
               style="display:flex;align-items:center;justify-content:center;width:80px;height:80px;border-radius:7px;overflow:hidden;border:2px solid #d0dae8;position:relative;"
               title="View Image <?= $idx+1 ?>">
              <img src="<?= APP_URL.'/'.e($ep) ?>" alt="proof <?= $idx+1 ?>"
                   style="width:100%;height:100%;object-fit:cover;">
              <span style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.45);color:#fff;font-size:.62rem;text-align:center;padding:.1rem 0;">
                #<?= $idx+1 ?>
              </span>
            </a>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <span style="color:#ccc;font-size:.8rem;">No images</span>
          <?php endif; ?>
        </div>

        <!-- Structured leads -->
        <div>
          <span style="font-size:.74rem;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.4rem;">
            Leads Submitted (<?= count($wLeads) ?>)
          </span>
          <?php if (!empty($wLeads)): ?>
          <?php foreach ($wLeads as $i => $wl): ?>
          <div style="background:#f5faff;border:1px solid #c8ddf5;border-radius:6px;padding:.35rem .65rem;margin-bottom:.3rem;font-size:.8rem;display:flex;gap:.75rem;align-items:center;">
            <span style="font-weight:700;color:#003f73;min-width:16px;"><?= $i+1 ?>.</span>
            <span><?= e($wl['lead_name']) ?></span>
            <span style="color:#005BA1;"><i class="fas fa-phone" style="font-size:.7rem;"></i> <?= e($wl['lead_phone']) ?></span>
          </div>
          <?php endforeach; ?>
          <?php else: ?>
          <span style="color:#ccc;font-size:.8rem;">No leads submitted today</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php endif; // end all tabs ?>

  </main>
</div>


<!-- ═══════════════════════════════════════════════════════════
     MODALS
═══════════════════════════════════════════════════════════════ -->

<!-- Logout Confirmation Modal -->
<div id="logoutModal" class="modal-overlay" style="display:none">
  <div class="modal-box" style="max-width:420px;text-align:center">
    <div style="padding:1.5rem 1.5rem 0">
      <div style="width:64px;height:64px;border-radius:50%;background:#fce4ec;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
        <i class="fas fa-sign-out-alt" style="font-size:1.6rem;color:#c62828"></i>
      </div>
      <h3 style="margin:0 0 .5rem">Log Out?</h3>
      <p class="text-muted" style="font-size:.95rem;margin-bottom:1.5rem">
        You will be returned to the login page. Any unsaved changes will be lost.
      </p>
    </div>
    <div class="modal-footer" style="justify-content:center;gap:1rem">
      <button class="btn btn-outline" onclick="closeModal('logoutModal')">
        <i class="fas fa-times"></i> Cancel
      </button>
      <a href="logout.php" class="btn btn-danger">
        <i class="fas fa-sign-out-alt"></i> Yes, Log Out
      </a>
    </div>
  </div>
</div>

<!-- Reject POP Modal -->
<div id="rejectModal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Reject Proof of Payment</h3>
      <button class="modal-close" onclick="closeModal('rejectModal')">×</button>
    </div>
    <div class="modal-body">
      <p>Rejecting POP for: <strong id="rejectUserName"></strong></p>
      <p class="text-muted" style="font-size:.9rem">The user will receive an email with this reason and can re-submit.</p>
      <div class="form-group">
        <label>Rejection Reason (sent to user)</label>
        <textarea id="rejectReason" class="form-control" rows="4"
                  placeholder="e.g. Payment reference does not match. Please re-submit using reference AHG-XX-REG."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('rejectModal')">Cancel</button>
      <button class="btn btn-danger" onclick="submitReject()">
        <i class="fas fa-times"></i> Reject &amp; Notify User
      </button>
    </div>
  </div>
</div>

<!-- Set All Auction Times Modal -->
<div id="setAllTimesModal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="fas fa-clock"></i> Set Auction Times</h3>
      <button class="modal-close" onclick="closeModal('setAllTimesModal')">×</button>
    </div>
    <div class="modal-body">
      <p class="text-muted">Update start and end times for multiple auctions at once.</p>
      <div class="form-group">
        <label>Apply To</label>
        <select id="allTimesFilter" class="form-control">
          <option value="all">All Auctions</option>
          <option value="upcoming">Upcoming Only</option>
          <option value="active">Active Only</option>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>New Start Time</label>
          <input type="datetime-local" id="allStartTime" class="form-control"
                 value="<?= date('Y-m-d\TH:i') ?>">
        </div>
        <div class="form-group">
          <label>New End Time</label>
          <input type="datetime-local" id="allEndTime" class="form-control"
                 value="<?= date('Y-m-d\TH:i', strtotime('+7 days')) ?>">
        </div>
      </div>
      <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        This will overwrite the times for all matching auctions. Individual times can be adjusted in the Auctions tab.
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('setAllTimesModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitSetAllTimes()">
        <i class="fas fa-clock"></i> Update Times
      </button>
    </div>
  </div>
</div>

<!-- Set Single Auction Time Modal -->
<div id="setTimeModal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Edit Auction Times</h3>
      <button class="modal-close" onclick="closeModal('setTimeModal')">×</button>
    </div>
    <div class="modal-body">
      <p><strong id="setTimeAuctionName"></strong></p>
      <input type="hidden" id="setTimeAuctionId">
      <div class="form-row">
        <div class="form-group">
          <label>Start Time</label>
          <input type="datetime-local" id="singleStartTime" class="form-control">
        </div>
        <div class="form-group">
          <label>End Time</label>
          <input type="datetime-local" id="singleEndTime" class="form-control">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('setTimeModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitSingleTime()">Save Times</button>
    </div>
  </div>
</div>

<!-- Email User Modal -->
<div id="emailUserModal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Send Email</h3>
      <button class="modal-close" onclick="closeModal('emailUserModal')">×</button>
    </div>
    <div class="modal-body">
      <p>Sending to: <strong id="emailUserName"></strong></p>
      <input type="hidden" id="emailUserId">
      <div id="emailModalResult"></div>
      <div class="form-group">
        <label>Subject</label>
        <input type="text" id="emailModalSubject" class="form-control" placeholder="Email subject...">
      </div>
      <div class="form-group">
        <label>Message</label>
        <textarea id="emailModalMessage" class="form-control" rows="6" placeholder="Your message..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('emailUserModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitEmailModal()">
        <i class="fas fa-paper-plane"></i> Send Email
      </button>
    </div>
  </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="fas fa-key"></i> Reset Password</h3>
      <button class="modal-close" onclick="closeModal('resetPasswordModal')">×</button>
    </div>
    <div class="modal-body">
      <p>Resetting password for: <strong id="resetPwdUserName"></strong></p>
      <input type="hidden" id="resetPwdUserId">
      <div id="resetPwdResult"></div>
      <div class="form-group">
        <label>New Password</label>
        <div style="position:relative">
          <input type="text" id="resetPwdInput" class="form-control" placeholder="Enter new password..." minlength="8">
          <button type="button" class="btn btn-outline btn-sm" id="generatePwdBtn"
                  onclick="generatePassword()" style="position:absolute;right:4px;top:4px;font-size:.75rem">
            Generate
          </button>
        </div>
        <small class="text-muted">Minimum 8 characters. Share this password with the user directly.</small>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('resetPasswordModal')">Cancel</button>
      <button class="btn btn-primary" id="resetPwdSubmitBtn" onclick="submitResetPassword()">
        <i class="fas fa-key"></i> Reset Password
      </button>
    </div>
  </div>
</div>

<!-- Import Errors Modal -->
<div id="importErrorsModal" class="modal-overlay" style="display:none">
  <div class="modal-box" style="max-width:600px">
    <div class="modal-header">
      <h3>Import Errors</h3>
      <button class="modal-close" onclick="closeModal('importErrorsModal')">×</button>
    </div>
    <div class="modal-body">
      <div id="importErrorsContent" class="import-errors-list"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('importErrorsModal')">Close</button>
    </div>
  </div>
</div>

<!-- Image Lightbox -->
<div id="adminLightbox" class="lightbox-overlay" style="display:none" onclick="closeImageLightbox()">
  <img id="adminLightboxImg" src="" alt="" onclick="event.stopPropagation()">
  <button class="lightbox-close" onclick="closeImageLightbox()">×</button>
</div>


<!-- Add Testimonial Modal -->
<div id="addTestimonialModal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="fas fa-star"></i> Add Testimonial</h3>
      <button class="modal-close" onclick="closeModal('addTestimonialModal')">×</button>
    </div>
    <div class="modal-body">
      <div id="testimonialUploadResult"></div>
      <div class="form-group">
        <label>Testimonial Image</label>
        <input type="file" id="testimonialImage" class="form-control" accept="image/jpeg,image/png,image/webp">
        <small class="text-muted">Recommended: Landscape or portrait. Will be displayed at 4:3 ratio. Max 10MB.</small>
      </div>
      <div class="form-group">
        <label>Image Preview</label>
        <div id="testimonialPreview" style="width:200px;height:150px;background:#f5f5f5;border-radius:8px;border:2px dashed #ddd;display:flex;align-items:center;justify-content:center;overflow:hidden;">
          <i class="fas fa-image" style="font-size:2rem;color:#ccc;"></i>
        </div>
      </div>
      <div class="form-group">
        <label>Headline (displayed below image)</label>
        <input type="text" id="testimonialHeadline" class="form-control" placeholder="e.g. 'Best auction experience! Got my dream car at half the price.'" maxlength="255">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('addTestimonialModal')">Cancel</button>
      <button class="btn btn-primary" id="submitTestimonialBtn" onclick="submitTestimonial()">
        <i class="fas fa-upload"></i> Upload Testimonial
      </button>
    </div>
  </div>
</div>

<!-- Edit Testimonial Headline Modal -->
<div id="editTestimonialModal" class="modal-overlay" style="display:none">
  <div class="modal-box" style="max-width:500px;">
    <div class="modal-header">
      <h3>Edit Headline</h3>
      <button class="modal-close" onclick="closeModal('editTestimonialModal')">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editTestimonialId">
      <div class="form-group">
        <label>Headline</label>
        <input type="text" id="editTestimonialHeadline" class="form-control" maxlength="255">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('editTestimonialModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitEditTestimonial()">Save</button>
    </div>
  </div>
</div>

<!-- Add Notification Modal -->
<div id="addNotificationModal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="fas fa-bell"></i> Add Notification</h3>
      <button class="modal-close" onclick="closeModal('addNotificationModal')">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>Type</label>
        <select id="notifType" class="form-control">
          <option value="bid">Bid — Someone placed a bid</option>
          <option value="win">Win — Vehicle sold</option>
          <option value="leading">Leading — Someone is leading</option>
          <option value="custom">Custom — General announcement</option>
        </select>
      </div>
      <div class="form-group">
        <label>Message</label>
        <textarea id="notifMessage" class="form-control" rows="3" placeholder="e.g. Bidder AHG-2026-047 just placed a bid of R85,000 on 2019 Toyota Hilux" maxlength="500"></textarea>
      </div>
      <div class="form-group">
        <label>Icon (Font Awesome class)</label>
        <select id="notifIcon" class="form-control">
          <option value="fa-gavel">fa-gavel (Bid)</option>
          <option value="fa-check-circle">fa-check-circle (Win/Sold)</option>
          <option value="fa-trophy">fa-trophy (Leading)</option>
          <option value="fa-bell">fa-bell (General)</option>
          <option value="fa-fire">fa-fire (Hot)</option>
          <option value="fa-star">fa-star (Featured)</option>
        </select>
      </div>
      <div class="form-group">
        <label>Frequency (seconds between displays)</label>
        <input type="number" id="notifFrequency" class="form-control" value="10" min="5" max="300" step="1">
        <small class="text-muted">How often this notification rotates in (minimum 5 seconds).</small>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('addNotificationModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitNotification()">
        <i class="fas fa-plus"></i> Add Notification
      </button>
    </div>
  </div>
</div>

<!-- Edit Notification Modal -->
<div id="editNotificationModal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Edit Notification</h3>
      <button class="modal-close" onclick="closeModal('editNotificationModal')">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editNotifId">
      <div class="form-group">
        <label>Type</label>
        <select id="editNotifType" class="form-control">
          <option value="bid">Bid</option>
          <option value="win">Win</option>
          <option value="leading">Leading</option>
          <option value="custom">Custom</option>
        </select>
      </div>
      <div class="form-group">
        <label>Message</label>
        <textarea id="editNotifMessage" class="form-control" rows="3" maxlength="500"></textarea>
      </div>
      <div class="form-group">
        <label>Frequency (seconds)</label>
        <input type="number" id="editNotifFrequency" class="form-control" value="10" min="5" max="300">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('editNotificationModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitEditNotification()">Save</button>
    </div>
  </div>
</div>

<!-- Bulk Auction / Assign Event modals removed — replaced by inline panels on Auctions tab -->

<!-- Mark as Sold Modal -->
<div id="markSoldModal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="fas fa-tag" style="color:#c8102e"></i> <span id="markSoldTitle">Mark as Sold</span></h3>
      <button class="modal-close" onclick="closeModal('markSoldModal')">×</button>
    </div>
    <div class="modal-body">
      <p style="margin-top:0"><strong id="markSoldVehicleName"></strong></p>
      <input type="hidden" id="markSoldAuctionId">
      <div class="form-group">
        <label>Final Price (R) <span style="color:#c8102e">*</span></label>
        <input type="number" id="markSoldPrice" class="form-control" min="1" step="0.01" placeholder="e.g. 185000">
        <small class="text-muted" id="markSoldTopHint"></small>
      </div>
      <div class="grid-2" style="gap:.75rem;">
        <div class="form-group">
          <label>Sold Date</label>
          <input type="date" id="markSoldDate" class="form-control" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label>Sold at</label>
          <select id="markSoldChannel" class="form-control">
            <option value="">— Not recorded —</option>
            <?php foreach (SALE_CHANNELS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Buyer <small class="text-muted">(optional)</small></label>
        <input type="text" id="markSoldBuyerSearch" class="form-control" placeholder="Search registered bidders, or type a walk-in buyer's name" autocomplete="off">
        <div id="markSoldBuyerDropdown" class="search-dropdown" style="display:none"></div>
        <input type="hidden" id="markSoldBuyerId">
        <small class="text-muted" id="markSoldBuyerHint">Not linked to a bidder account.</small>
      </div>
      <div id="markSoldError" class="alert alert-error" style="display:none;margin:0;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('markSoldModal')">Cancel</button>
      <button class="btn btn-sold" id="markSoldSubmit" onclick="submitMarkSold()">
        <i class="fas fa-check"></i> Save Sale
      </button>
    </div>
  </div>
</div>

<!-- Assign Winner Modal -->
<div id="assignWinnerModal" class="modal-overlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="fas fa-trophy"></i> Assign Winner</h3>
      <button class="modal-close" onclick="closeModal('assignWinnerModal')">×</button>
    </div>
    <div class="modal-body">
      <p>Assign a winner for: <strong id="assignWinnerVehicleName"></strong></p>
      <input type="hidden" id="assignWinnerAuctionId">
      <div class="form-group">
        <label>Search Bidder</label>
        <input type="text" id="winnerBidderSearch" class="form-control" placeholder="Type name, email, or bidder number..." autocomplete="off">
        <div id="winnerBidderDropdown" class="search-dropdown" style="display:none"></div>
      </div>
      <div id="winnerBidderPreview" style="display:none;margin:.75rem 0;padding:.75rem;background:#e8f5e9;border-radius:8px;">
        <input type="hidden" id="winnerBidderId">
        <span id="winnerBidderInfo"></span>
      </div>
      <div class="form-group">
        <label>Final Price (R)</label>
        <input type="number" id="winnerFinalPrice" class="form-control" min="0" step="0.01" placeholder="Enter final selling price">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('assignWinnerModal')">Cancel</button>
      <button class="btn btn-primary" style="background:#2e7d32;" onclick="submitAssignWinner()">
        <i class="fas fa-trophy"></i> Assign Winner
      </button>
    </div>
  </div>
</div>

<!-- Chart.js — loaded only on admin dashboard for analytics tab -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<!-- html2pdf — client-side PDF generation for invoice builder -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
// ── Modal helpers ──────────────────────────────────────────────
// .modal-overlay stays invisible without the "open" class (style.css), so set both
function openModal(id) { const m = document.getElementById(id); m.style.display = 'flex'; m.classList.add('open'); }
function closeModal(id) { const m = document.getElementById(id); m.style.display = 'none'; m.classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if(e.target===m) m.style.display='none'; });
});
document.addEventListener('keydown', e => {
    if(e.key==='Escape') document.querySelectorAll('.modal-overlay').forEach(m=>m.style.display='none');
});

// ── POP Approval ──────────────────────────────────────────────
async function approvePOP(userId, name) {
    const bidNum = document.getElementById('bidderNum_'+userId)?.value?.trim();
    if(!bidNum) { showToast('Please enter a bidder number.','error'); return; }
    if(!confirm(`Approve ${name} with bidder number ${bidNum}?`)) return;
    const fd = new FormData();
    fd.append('action','approve_pop');
    fd.append('user_id', userId);
    fd.append('bidder_number', bidNum);
    try {
        const res = await fetch('api.php',{method:'POST',body:fd});
        const data = await res.json();
        showToast(data.message, data.success?'success':'error');
        if(data.success) {
            const card = document.getElementById('pop-card-'+userId);
            if(card) { card.style.opacity='0'; card.style.transition='opacity .4s'; setTimeout(()=>card.remove(),400); }
        }
    } catch { showToast('Error. Please try again.','error'); }
}

let rejectUserId = null;
let rejectRegId = null;
function openRejectModal(userId, name, regId) {
    rejectUserId = userId;
    rejectRegId = regId || 0;
    document.getElementById('rejectUserName').textContent = name;
    document.getElementById('rejectReason').value = '';
    openModal('rejectModal');
}
async function submitReject() {
    const reason = document.getElementById('rejectReason').value.trim();
    if(!reason) { showToast('Please provide a rejection reason.','error'); return; }
    const fd = new FormData();
    fd.append('action','reject_pop');
    fd.append('user_id', rejectUserId);
    fd.append('registration_id', rejectRegId);
    fd.append('reason', reason);
    try {
        const res = await fetch('api.php',{method:'POST',body:fd});
        const data = await res.json();
        showToast(data.message, data.success?'success':'error');
        if(data.success) {
            closeModal('rejectModal');
            const card = document.getElementById('pop-card-'+rejectUserId);
            if(card){card.style.opacity='0';card.style.transition='opacity .4s';setTimeout(()=>card.remove(),400);}
        }
    } catch { showToast('Error. Please try again.','error'); }
}

// ── Auction actions ───────────────────────────────────────────
async function updateAuctionStatus(id, status, sel) {
    const fd = new FormData();
    fd.append('action','update_auction_status');
    fd.append('auction_id',id);
    fd.append('status',status);
    try {
        const res = await fetch('api.php',{method:'POST',body:fd});
        const data = await res.json();
        showToast(data.message, data.success?'success':'error');
        if(!data.success && sel) sel.value = sel.dataset.prev||'upcoming';
    } catch { showToast('Error updating status.','error'); }
}

function confirmDeleteAuction(id, name) {
    if(!confirm(`Delete "${name}" and all its bids and images? This cannot be undone.`)) return;
    const fd = new FormData();
    fd.append('action','delete_auction');
    fd.append('auction_id',id);
    fetch('api.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(data=>{
            showToast(data.message, data.success?'success':'error');
            if(data.success){const row=document.getElementById('auction-row-'+id);if(row){row.style.opacity='0';row.style.transition='opacity .4s';setTimeout(()=>row.remove(),400);}}
        }).catch(()=>showToast('Error deleting auction.','error'));
}

// Single auction time
function openSetTimeModal(id, name, start, end) {
    document.getElementById('setTimeAuctionId').value = id;
    document.getElementById('setTimeAuctionName').textContent = name;
    document.getElementById('singleStartTime').value = start ? start.replace(' ','T').slice(0,16) : '';
    document.getElementById('singleEndTime').value   = end   ? end.replace(' ','T').slice(0,16)   : '';
    openModal('setTimeModal');
}
async function submitSingleTime() {
    const id    = document.getElementById('setTimeAuctionId').value;
    const start = document.getElementById('singleStartTime').value;
    const end   = document.getElementById('singleEndTime').value;
    if(!start||!end){showToast('Please set both start and end times.','error');return;}
    if(end<=start){showToast('End time must be after start time.','error');return;}
    const fd = new FormData();
    fd.append('action','update_auction_times');
    fd.append('auction_id',id);
    fd.append('start_time',start.replace('T',' '));
    fd.append('end_time',end.replace('T',' '));
    try{
        const res=await fetch('api.php',{method:'POST',body:fd});
        const data=await res.json();
        showToast(data.message,data.success?'success':'error');
        if(data.success)closeModal('setTimeModal');
    }catch{showToast('Error.','error');}
}

// Set all times
async function submitSetAllTimes() {
    const filter = document.getElementById('allTimesFilter').value;
    const start  = document.getElementById('allStartTime').value;
    const end    = document.getElementById('allEndTime').value;
    if(!start||!end){showToast('Please set both times.','error');return;}
    if(!confirm(`Update times for all ${filter} auctions?`))return;
    const fd = new FormData();
    fd.append('action','set_all_auction_times');
    fd.append('status_filter',filter);
    fd.append('start_time',start.replace('T',' '));
    fd.append('end_time',end.replace('T',' '));
    try{
        const res=await fetch('api.php',{method:'POST',body:fd});
        const data=await res.json();
        showToast(data.message,data.success?'success':'error');
        if(data.success){closeModal('setAllTimesModal');setTimeout(()=>location.reload(),1500);}
    }catch{showToast('Error.','error');}
}

// ── User actions ──────────────────────────────────────────────
async function updateUserStatus(id, status, name) {
    if(!confirm(`${status==='suspended'?'Suspend':'Activate'} account for ${name}?`))return;
    const fd=new FormData();
    fd.append('action','update_user_status');
    fd.append('user_id',id);
    fd.append('status',status);
    try{
        const res=await fetch('api.php',{method:'POST',body:fd});
        const data=await res.json();
        showToast(data.message,data.success?'success':'error');
        if(data.success)setTimeout(()=>location.reload(),1200);
    }catch{showToast('Error.','error');}
}

function confirmDeleteUser(id,name){
    if(!confirm(`Permanently delete user "${name}"? Their bids will be anonymised.`))return;
    const fd=new FormData();
    fd.append('action','delete_user');
    fd.append('user_id',id);
    fetch('api.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(data=>{
            showToast(data.message,data.success?'success':'error');
            if(data.success){const row=document.getElementById('user-row-'+id);if(row){row.style.opacity='0';row.style.transition='opacity .4s';setTimeout(()=>row.remove(),400);}}
        }).catch(()=>showToast('Error.','error'));
}

// ── Send bank details to user ────────────────────────────────
async function sendBankDetails(userId, name) {
    if(!confirm(`Send banking details to ${name}?`))return;
    const btn=document.getElementById('bank-btn-'+userId);
    if(btn){btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';}
    const fd=new FormData();
    fd.append('action','admin_send_bank_details');
    fd.append('user_id',userId);
    try{
        const res=await fetch('api.php',{method:'POST',body:fd});
        const data=await res.json();
        showToast(data.message,data.success?'success':'error');
    }catch{showToast('Error sending bank details.','error');}
    finally{if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-university"></i>';}}
}

// ── Approve / Reject bank detail requests ────────────────────
async function approveBankRequest(id, name) {
    if(!confirm(`Approve banking details request for ${name}? This will email them the EFT details.`))return;
    const btn = document.getElementById('bank-approve-btn-'+id);
    const inlineResult = document.getElementById('bank-approval-result-'+id);
    if(btn){btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Sending...';}
    const fd=new FormData();
    fd.append('action','approve_bank_request');
    fd.append('request_id',id);
    try{
        const res=await fetch('api.php',{method:'POST',body:fd});
        const data=await res.json();
        showToast(data.message,data.success?'success':'error');
        if(inlineResult){inlineResult.innerHTML=`<div class="alert alert-${data.success?'success':'error'}" style="margin-bottom:1rem;">${data.message}</div>`;}
        if(data.success){
            const row=document.getElementById('bank-req-'+id);
            if(row){row.querySelector('td:nth-child(5) span').textContent='APPROVED';row.querySelector('td:nth-child(5) span').style.background='#4caf50';row.querySelector('td:last-child').innerHTML='<span style="font-size:.78rem;color:#4caf50;font-weight:600"><i class="fas fa-check"></i> Sent</span>';}
            const panel=document.getElementById('bank-approval-row-'+id);
            if(panel) setTimeout(()=>panel.style.display='none', 2500);
        } else if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Approve &amp; Send Email';}
    }catch{
        showToast('Error.','error');
        if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Approve &amp; Send Email';}
    }
}
async function rejectBankRequest(id) {
    if(!confirm('Reject this banking details request?'))return;
    const fd=new FormData();
    fd.append('action','reject_bank_request');
    fd.append('request_id',id);
    try{
        const res=await fetch('api.php',{method:'POST',body:fd});
        const data=await res.json();
        showToast(data.message,data.success?'success':'error');
        if(data.success){const row=document.getElementById('bank-req-'+id);if(row){row.querySelector('td:nth-child(5) span').textContent='REJECTED';row.querySelector('td:nth-child(5) span').style.background='#dc3545';row.querySelector('td:last-child').innerHTML='<span style="font-size:.78rem;color:#dc3545;">Rejected</span>';}}
    }catch{showToast('Error.','error');}
}

// ── Reset password modal ─────────────────────────────────────
function openResetPasswordModal(id, name) {
    document.getElementById('resetPwdUserId').value = id;
    document.getElementById('resetPwdUserName').textContent = name;
    document.getElementById('resetPwdInput').value = '';
    document.getElementById('resetPwdResult').innerHTML = '';
    openModal('resetPasswordModal');
}

function generatePassword() {
    const chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$';
    let pwd = '';
    for (let i = 0; i < 12; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length));
    document.getElementById('resetPwdInput').value = pwd;
}

async function submitResetPassword() {
    const userId = document.getElementById('resetPwdUserId').value;
    const newPwd = document.getElementById('resetPwdInput').value.trim();
    const resultDiv = document.getElementById('resetPwdResult');
    if (!newPwd || newPwd.length < 8) {
        resultDiv.innerHTML = '<div class="alert alert-error">Password must be at least 8 characters.</div>';
        return;
    }
    const btn = document.getElementById('resetPwdSubmitBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
    const fd = new FormData();
    fd.append('action', 'admin_reset_password');
    fd.append('user_id', userId);
    fd.append('new_password', newPwd);
    try {
        const res = await fetch('api.php', {method:'POST', body:fd});
        const data = await res.json();
        resultDiv.innerHTML = `<div class="alert alert-${data.success?'success':'error'}">${data.message}</div>`;
    } catch { resultDiv.innerHTML = '<div class="alert alert-error">Error resetting password.</div>'; }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-key"></i> Reset Password'; }
}

// ── Email user modal ──────────────────────────────────────────
function openEmailUserModal(id,name,email){
    document.getElementById('emailUserId').value=id;
    document.getElementById('emailUserName').textContent=name+' <'+email+'>';
    document.getElementById('emailModalSubject').value='';
    document.getElementById('emailModalMessage').value='';
    document.getElementById('emailModalResult').innerHTML='';
    openModal('emailUserModal');
}
async function submitEmailModal(){
    const id=document.getElementById('emailUserId').value;
    const subject=document.getElementById('emailModalSubject').value.trim();
    const message=document.getElementById('emailModalMessage').value.trim();
    if(!subject||!message){showToast('Subject and message are required.','error');return;}
    const fd=new FormData();
    fd.append('action','send_email');
    fd.append('user_id',id);
    fd.append('subject',subject);
    fd.append('message',message);
    try{
        const res=await fetch('api.php',{method:'POST',body:fd});
        const data=await res.json();
        document.getElementById('emailModalResult').innerHTML=`<div class="alert alert-${data.success?'success':'error'}">${data.message}</div>`;
        if(data.success)setTimeout(()=>closeModal('emailUserModal'),2000);
    }catch{showToast('Error sending email.','error');}
}

// ── Single email form (Email tab) ─────────────────────────────
let userSearchTimeout;
const userSearchInput = document.getElementById('userSearchInput');
const userDropdown    = document.getElementById('userSearchDropdown');
if(userSearchInput){
    userSearchInput.addEventListener('input',function(){
        clearTimeout(userSearchTimeout);
        if(this.value.length<2){userDropdown.style.display='none';return;}
        userSearchTimeout=setTimeout(()=>searchUsers(this.value),300);
    });
}
async function searchUsers(q){
    try{
        const res=await fetch(`api.php?action=search_users&q=${encodeURIComponent(q)}`);
        const data=await res.json();
        if(!data.users||!data.users.length){userDropdown.style.display='none';return;}
        userDropdown.innerHTML=data.users.map(u=>
            `<div class="search-dropdown-item" onclick="selectUser(${u.id},'${u.full_name.replace(/'/g,"\\'")}','${u.email}')">
                <strong>${u.full_name}</strong><small>${u.email}</small>
            </div>`).join('');
        userDropdown.style.display='block';
    }catch{}
}
function selectUser(id,name,email){
    document.getElementById('selectedUserId').value=id;
    document.getElementById('selectedUserName').textContent=name+' <'+email+'>';
    document.getElementById('selectedUserDisplay').style.display='flex';
    document.getElementById('userSearchDropdown').style.display='none';
    userSearchInput.value='';
}
function clearSelectedUser(){
    document.getElementById('selectedUserId').value='';
    document.getElementById('selectedUserDisplay').style.display='none';
}

// Holds a pending PDF attachment (File) to be sent with the next single email
window._pendingInvoicePdf = null;

const singleEmailForm=document.getElementById('singleEmailForm');
if(singleEmailForm){singleEmailForm.addEventListener('submit',async function(e){
    e.preventDefault();
    if(!document.getElementById('selectedUserId').value){showToast('Please select a user.','error');return;}
    const btn=document.getElementById('sendSingleBtn');btn.disabled=true;btn.textContent='Sending...';
    try{
        const fd = new FormData(singleEmailForm);
        // If an invoice was just loaded, auto-generate a fresh PDF from the HTML body and attach it.
        if (window._pendingInvoicePdf && typeof window._pendingInvoicePdf.buildBlob === 'function') {
            btn.textContent = 'Building PDF...';
            try {
                const blob = await window._pendingInvoicePdf.buildBlob();
                if (blob) fd.append('attachment', blob, window._pendingInvoicePdf.filename || 'invoice.pdf');
            } catch (err) { console.warn('PDF build failed', err); }
            btn.textContent = 'Sending...';
        }
        const res=await fetch('api.php',{method:'POST',body:fd});
        const data=await res.json();
        document.getElementById('singleEmailResult').innerHTML=`<div class="alert alert-${data.success?'success':'error'}">${data.message}</div>`;
        if(data.success){singleEmailForm.reset();clearSelectedUser();window._pendingInvoicePdf=null;}
    }catch{showToast('Error.','error');}
    finally{btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Send Email';}
});}

// Bulk email
const bulkConfirm=document.getElementById('bulkConfirm');
const sendBulkBtn=document.getElementById('sendBulkBtn');
if(bulkConfirm)bulkConfirm.addEventListener('change',()=>{if(sendBulkBtn)sendBulkBtn.disabled=!bulkConfirm.checked;});

const bulkEmailForm=document.getElementById('bulkEmailForm');
if(bulkEmailForm){bulkEmailForm.addEventListener('submit',async function(e){
    e.preventDefault();
    if(!confirm('Send bulk email to the selected group? This cannot be undone.'))return;
    const btn=document.getElementById('sendBulkBtn');btn.disabled=true;btn.textContent='Sending...';
    try{
        const res=await fetch('api.php',{method:'POST',body:new FormData(bulkEmailForm)});
        const data=await res.json();
        document.getElementById('bulkEmailResult').innerHTML=`<div class="alert alert-${data.success?'success':'error'}">${data.message}</div>`;
        if(data.success){bulkEmailForm.reset();if(bulkConfirm)bulkConfirm.checked=false;}
    }catch{showToast('Error.','error');}
    finally{btn.disabled=!bulkConfirm?.checked;btn.innerHTML='<i class="fas fa-broadcast-tower"></i> Send Bulk Email';}
});}

// ── HTML Email Editor Functions ───────────────────────────────
function setEmailFormat(target, format) {
    const formatInput = document.getElementById(target === 'single' ? 'singleEmailFormat' : 'bulkEmailFormat');
    const toolbar = document.getElementById(target === 'single' ? 'singleHtmlToolbar' : 'bulkHtmlToolbar');
    const preview = document.getElementById(target === 'single' ? 'singleHtmlPreview' : 'bulkHtmlPreview');
    const textarea = document.getElementById(target === 'single' ? 'singleMsg' : 'bulkMsg');

    formatInput.value = format;
    toolbar.style.display = format === 'html' ? 'flex' : 'none';
    if (format === 'plain') { preview.style.display = 'none'; }

    // Toggle active state on buttons
    document.querySelectorAll(`.email-fmt-btn[data-target="${target}"]`).forEach(btn => {
        btn.classList.toggle('active', btn.dataset.format === format);
    });

    // Update textarea style
    if (format === 'html') {
        textarea.style.fontFamily = "'Courier New', monospace";
        textarea.style.fontSize = '.85rem';
    } else {
        textarea.style.fontFamily = 'inherit';
        textarea.style.fontSize = '';
    }
}

function insertHtmlTag(textareaId, tag, selfClosing) {
    const ta = document.getElementById(textareaId);
    const start = ta.selectionStart, end = ta.selectionEnd;
    const selected = ta.value.substring(start, end);

    let insert;
    if (selfClosing) {
        insert = `<${tag}>`;
    } else if (tag === 'table') {
        insert = `<table>\n  <tr>\n    <th>Header 1</th>\n    <th>Header 2</th>\n  </tr>\n  <tr>\n    <td>${selected || 'Cell 1'}</td>\n    <td>Cell 2</td>\n  </tr>\n</table>`;
    } else {
        insert = `<${tag}>${selected || ''}</${tag}>`;
    }

    ta.value = ta.value.substring(0, start) + insert + ta.value.substring(end);
    ta.focus();
    const cursorPos = selfClosing ? start + insert.length : start + tag.length + 2 + (selected ? selected.length : 0);
    ta.setSelectionRange(cursorPos, cursorPos);
    updateHtmlPreview(textareaId === 'singleMsg' ? 'single' : 'bulk');
}

function insertHtmlLink(textareaId) {
    const url = prompt('Enter URL:', 'https://');
    if (!url) return;
    const text = prompt('Link text:', 'Click here');
    if (!text) return;
    const ta = document.getElementById(textareaId);
    const start = ta.selectionStart;
    const insert = `<a href="${url}" style="color:#005BA1;font-weight:600;">${text}</a>`;
    ta.value = ta.value.substring(0, start) + insert + ta.value.substring(ta.selectionEnd);
    ta.focus();
    updateHtmlPreview(textareaId === 'singleMsg' ? 'single' : 'bulk');
}

function insertHtmlImg(textareaId) {
    const url = prompt('Image URL:', 'https://');
    if (!url) return;
    const ta = document.getElementById(textareaId);
    const start = ta.selectionStart;
    const insert = `<img src="${url}" alt="Image" style="max-width:100%;height:auto;border-radius:8px;">`;
    ta.value = ta.value.substring(0, start) + insert + ta.value.substring(ta.selectionEnd);
    ta.focus();
    updateHtmlPreview(textareaId === 'singleMsg' ? 'single' : 'bulk');
}

function toggleHtmlPreview(target) {
    const preview = document.getElementById(target === 'single' ? 'singleHtmlPreview' : 'bulkHtmlPreview');
    const isHidden = preview.style.display === 'none';
    preview.style.display = isHidden ? 'block' : 'none';
    if (isHidden) updateHtmlPreview(target);
}

function updateHtmlPreview(target) {
    const textarea = document.getElementById(target === 'single' ? 'singleMsg' : 'bulkMsg');
    const previewEl = document.getElementById(target === 'single' ? 'singlePreviewContent' : 'bulkPreviewContent');
    const formatEl = document.getElementById(target === 'single' ? 'singleEmailFormat' : 'bulkEmailFormat');
    if (!previewEl || previewEl.parentElement.style.display === 'none') return;
    if (formatEl.value === 'html') {
        previewEl.innerHTML = textarea.value;
    } else {
        previewEl.innerHTML = '<p>' + textarea.value.replace(/\n/g, '<br>') + '</p>';
    }
}

const emailTemplates = {
    auction_announcement: {
        subject: 'New Auction Starting Soon — Don\'t Miss Out!',
        body: `<div style="text-align:center;margin-bottom:1.5rem;">
  <img src="https://auctionhubgroup.co.za/logo.png" alt="AHG Logo" style="max-width:200px;">
</div>

<h2 style="color:#003f73;text-align:center;">New Auction Starting Soon!</h2>

<p>Dear Valued Bidder,</p>

<p>We're excited to announce our upcoming auction featuring an incredible selection of <strong>DEKRA-inspected vehicles</strong> directly from major banks.</p>

<table style="width:100%;border-collapse:collapse;margin:1rem 0;">
  <tr style="background:#003f73;color:#fff;">
    <th style="padding:.75rem;text-align:left;">Detail</th>
    <th style="padding:.75rem;text-align:left;">Information</th>
  </tr>
  <tr>
    <td style="padding:.75rem;border:1px solid #ddd;"><strong>Auction Date</strong></td>
    <td style="padding:.75rem;border:1px solid #ddd;">[INSERT DATE]</td>
  </tr>
  <tr style="background:#f8f9fa;">
    <td style="padding:.75rem;border:1px solid #ddd;"><strong>Vehicles Available</strong></td>
    <td style="padding:.75rem;border:1px solid #ddd;">[INSERT COUNT] vehicles</td>
  </tr>
  <tr>
    <td style="padding:.75rem;border:1px solid #ddd;"><strong>Registration Fee</strong></td>
    <td style="padding:.75rem;border:1px solid #ddd;">R10,000 (fully refundable)</td>
  </tr>
</table>

<p style="text-align:center;margin:1.5rem 0;">
  <a href="https://auctionhubgroup.co.za/auctions.php" style="display:inline-block;background:#005BA1;color:#fff;padding:.85rem 2rem;border-radius:8px;text-decoration:none;font-weight:700;">View All Vehicles</a>
</p>

<p style="color:#666;font-size:.9rem;">Happy bidding!<br><strong>Auction Hub Group Team</strong></p>`
    },
    event_invite: {
        subject: 'You\'re Invited — Upcoming AHG Auction Event',
        body: `<h2 style="color:#003f73;text-align:center;">You're Invited!</h2>

<p>Dear Bidder,</p>

<p>We'd like to invite you to our upcoming <strong>auction event</strong>:</p>

<div style="background:#f0f6ff;border-left:4px solid #005BA1;padding:1rem 1.25rem;border-radius:0 8px 8px 0;margin:1rem 0;">
  <p style="margin:.2rem 0;"><strong>Event:</strong> [EVENT NAME]</p>
  <p style="margin:.2rem 0;"><strong>Date:</strong> [EVENT DATE]</p>
  <p style="margin:.2rem 0;"><strong>Venue:</strong> [VENUE ADDRESS]</p>
  <p style="margin:.2rem 0;"><strong>Time:</strong> [START TIME]</p>
</div>

<p>Don't miss this opportunity to bid on quality vehicles at unbeatable prices.</p>

<p style="text-align:center;margin:1.5rem 0;">
  <a href="https://auctionhubgroup.co.za/events.php" style="display:inline-block;background:#25D366;color:#fff;padding:.85rem 2rem;border-radius:8px;text-decoration:none;font-weight:700;">View Event Details</a>
</p>

<p style="color:#666;font-size:.9rem;">See you there!<br><strong>Auction Hub Group Team</strong></p>`
    },
    payment_reminder: {
        subject: 'Payment Reminder — Action Required',
        body: `<h2 style="color:#e8a000;text-align:center;">⚠️ Payment Reminder</h2>

<p>Dear Bidder,</p>

<p>This is a friendly reminder that your <strong>payment is due</strong>. Please ensure your payment is made promptly to avoid any delays.</p>

<div style="background:#fff8e1;border:2px solid #e8a000;border-radius:8px;padding:1rem 1.25rem;margin:1rem 0;">
  <p style="margin:.3rem 0;"><strong>Amount Due:</strong> [AMOUNT]</p>
  <p style="margin:.3rem 0;"><strong>Reference:</strong> [REFERENCE]</p>
  <p style="margin:.3rem 0;"><strong>Due Date:</strong> [DATE]</p>
</div>

<p>If you have already made your payment, please disregard this email and ensure your proof of payment has been uploaded on the platform.</p>

<p style="text-align:center;margin:1.5rem 0;">
  <a href="https://auctionhubgroup.co.za/dashboard.php" style="display:inline-block;background:#005BA1;color:#fff;padding:.85rem 2rem;border-radius:8px;text-decoration:none;font-weight:700;">Upload Proof of Payment</a>
</p>

<p style="color:#666;font-size:.9rem;">Thank you,<br><strong>Auction Hub Group Team</strong></p>`
    },
    general_update: {
        subject: 'Important Update from Auction Hub Group',
        body: `<h2 style="color:#003f73;text-align:center;">Important Update</h2>

<p>Dear Valued Member,</p>

<p>[YOUR MESSAGE HERE]</p>

<p>If you have any questions, don't hesitate to reach out to us via email at <a href="mailto:info@auctionhubgroup.co.za" style="color:#005BA1;">info@auctionhubgroup.co.za</a> or on WhatsApp.</p>

<p style="text-align:center;margin:1.5rem 0;">
  <a href="https://auctionhubgroup.co.za" style="display:inline-block;background:#005BA1;color:#fff;padding:.85rem 2rem;border-radius:8px;text-decoration:none;font-weight:700;">Visit Our Website</a>
</p>

<p style="color:#666;font-size:.9rem;">Kind regards,<br><strong>Auction Hub Group Team</strong></p>`
    }
};

function loadEmailTemplate(templateName) {
    const tpl = emailTemplates[templateName];
    if (!tpl) return;

    // Determine which form to load into — prefer bulk, but use single if it has a user selected
    const singleHasUser = document.getElementById('selectedUserId')?.value;
    const target = singleHasUser ? 'single' : 'bulk';

    // Switch to HTML mode
    setEmailFormat(target, 'html');

    // Set values
    const textarea = document.getElementById(target === 'single' ? 'singleMsg' : 'bulkMsg');
    const subjectInput = textarea.closest('form').querySelector('input[name="subject"]');
    subjectInput.value = tpl.subject;
    textarea.value = tpl.body;

    updateHtmlPreview(target);
    toggleHtmlPreview(target); // Show preview
    showToast('Template loaded into ' + (target === 'single' ? 'single email' : 'bulk email') + ' form.', 'success');
}

// ══════════════════════════════════════════════════════════════
// INVOICE BUILDER — Winner + Pre-Auction invoice emails
// ══════════════════════════════════════════════════════════════
function _invNum(id) { return parseFloat(document.getElementById(id)?.value) || 0; }
function _invTxt(id) { return (document.getElementById(id)?.value || '').trim(); }
function _fmtR(n) { return 'R ' + n.toLocaleString('en-ZA', {minimumFractionDigits:2, maximumFractionDigits:2}); }

function _invTotal() {
    return _invNum('invSalesPrice') + _invNum('invPremium') + _invNum('invDocFee') + _invNum('invVat');
}
// live total preview
['invSalesPrice','invPremium','invDocFee','invVat'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', () => {
        const p = document.getElementById('invTotalPreview');
        if (p) p.textContent = _fmtR(_invTotal());
    });
});

const PVA_LOGO_URL = 'https://auctionhubgroup.co.za/logo.png';
const PVA_BLUE_DARK  = '#003f73';
const PVA_BLUE       = '#005BA1';
const PVA_BLUE_LIGHT = '#0078d7';

function _vehicleDetailsBlock() {
    const box = document.getElementById('invVehicleDetailsJson');
    if (!box || !box.value) return '';
    let v;
    try { v = JSON.parse(box.value); } catch { return ''; }
    if (!v || !v.item_name) return '';
    const title = [v.year, v.make, v.item_name].filter(Boolean).join(' ');
    const facts = [
        v.mileage     ? ['Mileage',      Number(v.mileage).toLocaleString('en-ZA') + ' km'] : null,
        v.fuel_type   ? ['Fuel',         v.fuel_type] : null,
        v.transmission? ['Transmission', v.transmission] : null,
        v.body_type   ? ['Body',         v.body_type] : null,
        v.engine_size ? ['Engine',       v.engine_size] : null,
        v.condition_grade ? ['DEKRA',    v.condition_grade] : null,
    ].filter(Boolean);
    const img = v.image_url
        ? `<img src="${v.image_url}" alt="${title}" style="width:100%;display:block;border-radius:10px;max-height:360px;object-fit:cover;">`
        : '';
    const rows = facts.map(([k, val]) =>
        `<tr><td style="padding:6px 0;color:#666;font-size:13px;width:42%;">${k}:</td><td style="padding:6px 0;color:#003f73;font-size:13px;font-weight:700;">${val}</td></tr>`
    ).join('');
    const desc = v.description
        ? `<p style="margin:12px 0 0;color:#444;font-size:13px;line-height:1.6;">${String(v.description).substring(0, 400)}</p>`
        : '';
    return `
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:18px 0;background:#ffffff;border:1px solid #d5e3f2;border-radius:12px;overflow:hidden;">
        <tr><td style="padding:0;">${img}</td></tr>
        <tr><td style="padding:18px 22px;">
          <h4 style="margin:0 0 12px;color:${PVA_BLUE_DARK};font-size:17px;">Vehicle Details</h4>
          <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">${rows}</table>
          ${desc}
        </td></tr>
      </table>`;
}

function _invoiceHtml(type) {
    const isWinner = type === 'winner';
    const bidder     = _invTxt('invBidderName') || 'Valued Bidder';
    const vehicle    = _invTxt('invVehicle')    || 'Your Vehicle';
    const invNum     = _invTxt('invNumber')     || '—';
    const accessories= _invTxt('invAccessories');
    const sales      = _invNum('invSalesPrice');
    const premium    = _invNum('invPremium');
    const docFee     = _invNum('invDocFee');
    const vat        = _invNum('invVat');
    const total      = _invTotal();

    const heroTitle = isWinner ? 'Congratulations!' : 'Pre-Auction Quotation';
    const heroSub   = isWinner ? "You're the Winning Bidder!" : 'Your commitment quote for the upcoming auction';
    const greetLine = isWinner
        ? "Fantastic news! You've successfully won your auction bid. Below is your invoice and the next steps to secure your vehicle. <strong>Please note: only registered bidders are permitted on the premises for pre-auction viewing and collection.</strong>"
        : 'Below is your pre-auction quotation for the vehicle you are interested in. The vehicle is sold at the advertised price &mdash; there is <strong>no buyer\u2019s premium and no documentation fee</strong>. To bid, we require a commitment payment of <strong>30%&ndash;50% of the asking price</strong> (for certain vehicles full settlement may be required). Once received, you will be issued a unique <strong>Buyer\u2019s Code</strong>, which is your access key to our premises &mdash; <strong>no buyer\u2019s code, no entry.</strong>';

    const vehicleBlock = _vehicleDetailsBlock();

    // Brochure-style pre-auction guide section (replaces winner "next steps")
    const guideSection = isWinner ? `
      <div style="margin:26px 0 16px;background:${PVA_BLUE_DARK};border-radius:10px;padding:22px;text-align:center;">
        <h3 style="margin:0 0 8px;color:#ffffff;font-size:20px;">Important Next Steps</h3>
        <p style="margin:0;color:rgba(255,255,255,.85);font-size:14px;">Please complete these steps to secure your vehicle.</p>
      </div>

      <div style="margin:0 0 16px;background:#f2f7fc;border-left:5px solid ${PVA_BLUE};border-radius:10px;padding:20px 22px;">
        <h4 style="margin:0 0 10px;color:${PVA_BLUE_DARK};font-size:18px;">Step 1 &mdash; Make Payment</h4>
        <p style="margin:0 0 12px;color:#333;font-size:14px;line-height:1.6;">Pay the full invoice amount using your unique Buyer's Payment Reference Number:</p>
        <div style="background:${PVA_BLUE_DARK};border-radius:8px;padding:18px;text-align:center;margin:12px 0;">
          <p style="margin:0 0 6px;color:#fff;font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.85;">Your Payment Reference</p>
          <p style="margin:0;color:#fff;font-size:28px;font-weight:800;letter-spacing:2px;font-family:'Courier New',monospace;">${invNum}</p>
          <p style="margin:8px 0 0;color:#ffffff;font-size:11px;opacity:.8;">Use this reference for your EFT payment</p>
        </div>
        <p style="margin:10px 0 0;color:#333;font-size:13px;line-height:1.6;">
          <strong style="color:${PVA_BLUE_DARK};">Bank Details:</strong><br>
          Account Name: Auction Hub Group<br>
          Bank: FNB<br>
          Account Number: [YOUR ACCOUNT NO]<br>
          Branch Code: 250655<br>
          Reference: <strong>${invNum}</strong>
        </p>
      </div>

      <div style="margin:0 0 16px;background:#f2f7fc;border-left:5px solid ${PVA_BLUE};border-radius:10px;padding:20px 22px;">
        <h4 style="margin:0 0 10px;color:${PVA_BLUE_DARK};font-size:18px;">Step 2 &mdash; Wait for Payment Confirmation</h4>
        <p style="margin:0;color:#333;font-size:14px;line-height:1.6;">Once your payment has reflected in our account we will send you a collection notification by email and SMS. EFT payments typically clear within 1&ndash;2 business hours.</p>
      </div>

      <div style="margin:0 0 24px;background:#f2f7fc;border-left:5px solid ${PVA_BLUE};border-radius:10px;padding:20px 22px;">
        <h4 style="margin:0 0 10px;color:${PVA_BLUE_DARK};font-size:18px;">Step 3 &mdash; Collect Your Vehicle</h4>
        <p style="margin:0 0 10px;color:#333;font-size:14px;line-height:1.6;"><strong style="color:${PVA_BLUE_DARK};">Important:</strong> You must collect your vehicle <strong>within 2 working days</strong> of payment confirmation.</p>
        <p style="margin:0;color:#333;font-size:13px;line-height:1.6;">
          <strong style="color:${PVA_BLUE_DARK};">What to bring:</strong><br>
          Valid ID or passport &middot; Proof of payment &middot; This email confirmation
        </p>
      </div>` : `
      <div style="margin:26px 0 16px;background:${PVA_BLUE_DARK};border-radius:10px;padding:22px;text-align:center;">
        <h3 style="margin:0 0 8px;color:#ffffff;font-size:20px;">Pre-Auction Guide</h3>
        <p style="margin:0;color:rgba(255,255,255,.85);font-size:14px;">Everything you need to know before bidding at a Auction Hub Auction.</p>
      </div>

      <div style="margin:0 0 16px;background:#f2f7fc;border-left:5px solid ${PVA_BLUE};border-radius:10px;padding:20px 22px;">
        <h4 style="margin:0 0 10px;color:${PVA_BLUE_DARK};font-size:18px;">1 &mdash; Register as a Bidder</h4>
        <p style="margin:0;color:#333;font-size:14px;line-height:1.6;">Register online and pay a <strong>R10,000 refundable registration deposit</strong>. This secures your unique bidder number. The deposit is refunded in full after the auction if you do not purchase, or applied toward your purchase if you win.</p>
      </div>

      <div style="margin:0 0 16px;background:#f2f7fc;border-left:5px solid ${PVA_BLUE};border-radius:10px;padding:20px 22px;">
        <h4 style="margin:0 0 10px;color:${PVA_BLUE_DARK};font-size:18px;">2 &mdash; Secure Your Buyer's Code</h4>
        <p style="margin:0;color:#333;font-size:14px;line-height:1.6;">To commit to bidding on this vehicle, pay <strong>30%&ndash;50% of the asking price</strong> as a commitment charge (some vehicles require full settlement upfront). Once received you will be issued a unique <strong>Buyer's Code</strong>. <strong style="color:${PVA_BLUE_DARK};">This code is your access key to our premises &mdash; no buyer's code, no entry.</strong></p>
      </div>

      <div style="margin:0 0 16px;background:#f2f7fc;border-left:5px solid ${PVA_BLUE};border-radius:10px;padding:20px 22px;">
        <h4 style="margin:0 0 10px;color:${PVA_BLUE_DARK};font-size:18px;">3 &mdash; View the Vehicle (Registered Bidders Only)</h4>
        <p style="margin:0;color:#333;font-size:14px;line-height:1.6;">All vehicles are DEKRA inspected and available for physical viewing before the auction. Only registered bidders with a valid Buyer's Code will be permitted on the premises. Viewing dates and venue are published on our website and in this email.</p>
      </div>

      <div style="margin:0 0 16px;background:#f2f7fc;border-left:5px solid ${PVA_BLUE};border-radius:10px;padding:20px 22px;">
        <h4 style="margin:0 0 10px;color:${PVA_BLUE_DARK};font-size:18px;">4 &mdash; Bid Online or On-Site</h4>
        <p style="margin:0;color:#333;font-size:14px;line-height:1.6;">Attend the auction in person or place your bids on our live online platform. Bidding opens at the published start time and closes at the end time. The highest bid at close wins &mdash; at the <strong>advertised price with no buyer's premium or documentation fee</strong>.</p>
      </div>

      <div style="margin:0 0 16px;background:#f2f7fc;border-left:5px solid ${PVA_BLUE};border-radius:10px;padding:20px 22px;">
        <h4 style="margin:0 0 10px;color:${PVA_BLUE_DARK};font-size:18px;">5 &mdash; Your Payment Reference</h4>
        <p style="margin:0 0 12px;color:#333;font-size:14px;line-height:1.6;">Use this unique reference when paying your commitment or final settlement:</p>
        <div style="background:${PVA_BLUE_DARK};border-radius:8px;padding:18px;text-align:center;margin:12px 0;">
          <p style="margin:0 0 6px;color:#fff;font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.85;">Your Reference</p>
          <p style="margin:0;color:#fff;font-size:28px;font-weight:800;letter-spacing:2px;font-family:'Courier New',monospace;">${invNum}</p>
        </div>
        <p style="margin:10px 0 0;color:#333;font-size:13px;line-height:1.6;">
          <strong style="color:${PVA_BLUE_DARK};">Bank Details:</strong><br>
          Account Name: Auction Hub Group &middot; Bank: FNB<br>
          Account Number: [YOUR ACCOUNT NO] &middot; Branch Code: 250655
        </p>
      </div>

      <div style="margin:0 0 24px;background:#f2f7fc;border-left:5px solid ${PVA_BLUE};border-radius:10px;padding:20px 22px;">
        <h4 style="margin:0 0 10px;color:${PVA_BLUE_DARK};font-size:18px;">6 &mdash; Pay and Collect</h4>
        <p style="margin:0;color:#333;font-size:14px;line-height:1.6;">Full settlement is due within <strong>48 hours</strong> of winning. Vehicles must be collected within <strong>2 working days</strong> of payment confirmation. Bring valid ID, your Buyer's Code, proof of payment, and this quotation.</p>
      </div>`;

    return `<!-- Invoice email - ${isWinner ? 'Winner' : 'Pre-Auction'} - Auction Hub Group -->
<div id="pva-invoice-root" style="margin:0;padding:0;background:#eef3f8;font-family:'Segoe UI',Arial,Helvetica,sans-serif;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#eef3f8;">
<tr><td style="padding:24px 12px;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto;max-width:760px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 6px 30px rgba(0,63,115,.14);">

  <!-- LOGO HEADER -->
  <tr>
    <td style="background:#ffffff;padding:30px 24px 18px;text-align:center;border-bottom:4px solid ${PVA_BLUE};">
      <img src="${PVA_LOGO_URL}" alt="Auction Hub Group" style="max-width:260px;width:100%;height:auto;display:block;margin:0 auto 6px;">
      <p style="margin:8px 0 0;color:${PVA_BLUE};font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;">Trusted Auctioneers Since 2016</p>
    </td>
  </tr>

  <!-- BLUE HERO -->
  <tr>
    <td style="background:linear-gradient(135deg,${PVA_BLUE_DARK} 0%,${PVA_BLUE} 55%,${PVA_BLUE_LIGHT} 100%);padding:46px 24px;text-align:center;color:#ffffff;">
      <h1 style="margin:0 0 8px;color:#ffffff;font-size:32px;font-weight:800;text-shadow:0 2px 4px rgba(0,0,0,.18);letter-spacing:.01em;">${heroTitle}</h1>
      <p style="margin:0;color:#ffffff;font-size:17px;font-weight:500;opacity:.95;">${heroSub}</p>
      <div style="display:inline-block;margin-top:14px;padding:6px 16px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);border-radius:30px;">
        <span style="color:#fff;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">${isWinner ? 'Invoice' : 'Quotation'} #${invNum}</span>
      </div>
    </td>
  </tr>

  <!-- BODY -->
  <tr>
    <td style="padding:32px 32px 8px;">
      <h2 style="margin:0 0 14px;color:${PVA_BLUE_DARK};font-size:22px;">Hi ${bidder},</h2>
      <p style="margin:0 0 18px;color:#333;font-size:16px;line-height:1.65;">${greetLine}</p>

      ${vehicleBlock}

      <!-- Summary tile -->
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:18px 0;background:#f2f7fc;border:1px solid #cfe0f0;border-radius:10px;">
        <tr><td style="padding:22px 24px;">
          <h3 style="margin:0 0 14px;color:${PVA_BLUE};font-size:19px;">${isWinner ? 'Your Winning Vehicle' : 'Vehicle of Interest'}</h3>
          <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr>
              <td style="padding:9px 0;border-bottom:1px solid #cfe0f0;"><strong style="color:${PVA_BLUE_DARK};font-size:14px;">Vehicle:</strong></td>
              <td style="padding:9px 0;border-bottom:1px solid #cfe0f0;text-align:right;color:#333;font-size:14px;font-weight:600;">${vehicle}</td>
            </tr>
            ${accessories ? `<tr>
              <td style="padding:9px 0;border-bottom:1px solid #cfe0f0;"><strong style="color:${PVA_BLUE_DARK};font-size:14px;">Accessories:</strong></td>
              <td style="padding:9px 0;border-bottom:1px solid #cfe0f0;text-align:right;color:#666;font-size:13px;">${accessories}</td>
            </tr>` : ''}
            <tr>
              <td style="padding:9px 0;border-bottom:1px solid #cfe0f0;"><strong style="color:${PVA_BLUE_DARK};font-size:14px;">${isWinner ? 'Invoice Number' : 'Reference Number'}:</strong></td>
              <td style="padding:9px 0;border-bottom:1px solid #cfe0f0;text-align:right;color:#666;font-size:13px;">${invNum}</td>
            </tr>
            <tr>
              <td style="padding:14px 0 4px;"><strong style="color:${PVA_BLUE_DARK};font-size:17px;">Total ${isWinner ? 'Amount' : 'Estimate'}:</strong></td>
              <td style="padding:14px 0 4px;text-align:right;color:${PVA_BLUE};font-size:26px;font-weight:800;">${_fmtR(total)}</td>
            </tr>
          </table>
        </td></tr>
      </table>

      <!-- Payment breakdown -->
      ${isWinner ? `
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:18px 0;background:#ffffff;border-radius:10px;border:1px solid #dee6ee;">
        <tr><td style="padding:20px 22px;">
          <h4 style="margin:0 0 14px;color:${PVA_BLUE_DARK};font-size:17px;">Payment Breakdown</h4>
          <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr><td style="padding:7px 0;color:#666;font-size:14px;">Sales Price:</td><td style="padding:7px 0;text-align:right;color:#333;font-size:14px;">${_fmtR(sales)}</td></tr>
            <tr><td style="padding:7px 0;color:#666;font-size:14px;">Buyer's Premium:</td><td style="padding:7px 0;text-align:right;color:#333;font-size:14px;">${_fmtR(premium)}</td></tr>
            <tr><td style="padding:7px 0;color:#666;font-size:14px;">Documentation Fee:</td><td style="padding:7px 0;text-align:right;color:#333;font-size:14px;">${_fmtR(docFee)}</td></tr>
            <tr><td style="padding:7px 0;border-bottom:1px solid #dee6ee;color:#666;font-size:14px;">VAT:</td><td style="padding:7px 0;border-bottom:1px solid #dee6ee;text-align:right;color:#333;font-size:14px;">${_fmtR(vat)}</td></tr>
            <tr><td style="padding:12px 0 0;font-weight:800;color:${PVA_BLUE_DARK};font-size:16px;">Total Due:</td><td style="padding:12px 0 0;text-align:right;font-weight:800;color:${PVA_BLUE};font-size:18px;">${_fmtR(total)}</td></tr>
          </table>
        </td></tr>
      </table>` : `
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:18px 0;background:#ffffff;border-radius:10px;border:1px solid #dee6ee;">
        <tr><td style="padding:20px 22px;">
          <h4 style="margin:0 0 14px;color:${PVA_BLUE_DARK};font-size:17px;">Commitment &amp; Price Summary</h4>
          <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr><td style="padding:9px 0;border-bottom:1px solid #dee6ee;color:#666;font-size:14px;">Advertised Sales Price:</td><td style="padding:9px 0;border-bottom:1px solid #dee6ee;text-align:right;color:#003f73;font-size:15px;font-weight:700;">${_fmtR(sales)}</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #dee6ee;color:#666;font-size:14px;">Buyer's Premium:</td><td style="padding:9px 0;border-bottom:1px solid #dee6ee;text-align:right;color:#1a7a33;font-size:13px;font-weight:700;">NOT CHARGED</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #dee6ee;color:#666;font-size:14px;">Documentation Fee:</td><td style="padding:9px 0;border-bottom:1px solid #dee6ee;text-align:right;color:#1a7a33;font-size:13px;font-weight:700;">NOT CHARGED</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #dee6ee;color:#666;font-size:14px;">Minimum Commitment (30%):</td><td style="padding:9px 0;border-bottom:1px solid #dee6ee;text-align:right;color:#333;font-size:14px;">${_fmtR(sales * 0.30)}</td></tr>
            <tr><td style="padding:9px 0;border-bottom:1px solid #dee6ee;color:#666;font-size:14px;">Maximum Commitment (50%):</td><td style="padding:9px 0;border-bottom:1px solid #dee6ee;text-align:right;color:#333;font-size:14px;">${_fmtR(sales * 0.50)}</td></tr>
            <tr><td style="padding:12px 0 0;font-weight:800;color:${PVA_BLUE_DARK};font-size:16px;">Total on Winning Bid:</td><td style="padding:12px 0 0;text-align:right;font-weight:800;color:${PVA_BLUE};font-size:18px;">${_fmtR(sales)}</td></tr>
          </table>
          <p style="margin:14px 0 0;padding:10px 12px;background:#eaf4ff;border-left:3px solid ${PVA_BLUE};border-radius:6px;color:#003f73;font-size:12.5px;line-height:1.55;">
            <strong>Note:</strong> The vehicle is sold at the advertised price &mdash; no buyer's premium, no documentation fee. A commitment of 30%&ndash;50% of the asking price is required to be issued your Buyer's Code. Some high-value vehicles may require full settlement upfront. Your commitment is applied to the final balance if you win.
          </p>
        </td></tr>
      </table>`}

      ${guideSection}

      <!-- Reminders -->
      <div style="margin:18px 0;background:#f2f7fc;border-left:4px solid ${PVA_BLUE};border-radius:8px;padding:16px 20px;">
        <h4 style="margin:0 0 8px;color:${PVA_BLUE_DARK};font-size:15px;">Important Reminders</h4>
        <ul style="margin:0;padding-left:20px;color:#333;font-size:13px;line-height:1.75;">
          ${isWinner
            ? `<li>Payment is due within 48 hours or the sale may be forfeited</li>
               <li><strong>Only registered bidders are permitted on the premises for pre-auction preview and vehicle collection.</strong></li>`
            : `<li>No buyer's premium, no documentation fee &mdash; the advertised price is the winning price</li>
               <li>A commitment of 30%&ndash;50% is required to be issued your Buyer's Code</li>
               <li><strong>No Buyer's Code = no entry to our premises.</strong> Only registered bidders with a valid code are admitted for preview or collection</li>
               <li>Winning bidders must settle the balance within 48 hours of the auction close</li>`}
          <li>Vehicles must be collected within 2 working days of payment confirmation</li>
          <li>Late collection may result in storage fees</li>
          <li>Always use your Reference Number: <strong>${invNum}</strong></li>
          <li>Bring valid ID${isWinner ? '' : ', your Buyer\u2019s Code'} and proof of payment on collection day</li>
        </ul>
      </div>

      <p style="margin:22px 0 12px;color:#333;font-size:15px;line-height:1.65;">Thank you for choosing <strong>Auction Hub Group</strong>. ${isWinner ? 'We look forward to handing over the keys.' : 'We look forward to welcoming you at the auction.'}</p>
      <p style="margin:18px 0 0;color:#333;font-size:15px;line-height:1.65;">${isWinner ? 'Congratulations once again.' : 'Best of luck on auction day.'}<br><strong style="color:${PVA_BLUE_DARK};">The Auction Hub Group Team</strong></p>
    </td>
  </tr>

  <!-- FOOTER -->
  <tr>
    <td style="background:${PVA_BLUE_DARK};padding:26px;text-align:center;">
      <img src="${PVA_LOGO_URL}" alt="Auction Hub Group" style="max-width:140px;height:auto;margin:0 auto 10px;filter:brightness(0) invert(1);">
      <p style="margin:0 0 6px;color:#ffffff;font-size:14px;font-weight:700;">Auction Hub Group (Pty) Ltd</p>
      <p style="margin:0 0 10px;color:rgba(255,255,255,.7);font-size:12px;">South Africa &middot; Auctioneers since 2016</p>
      <p style="margin:0 0 10px;color:rgba(255,255,255,.75);font-size:12px;">
        <a href="mailto:info@auctionhubgroup.co.za" style="color:#ffffff;text-decoration:none;">info@auctionhubgroup.co.za</a> &middot;
        <a href="https://auctionhubgroup.co.za" style="color:#ffffff;text-decoration:none;">auctionhubgroup.co.za</a>
      </p>
      <p style="margin:10px 0 0;color:rgba(255,255,255,.5);font-size:11px;">Payment Ref: ${invNum} &middot; &copy; ${new Date().getFullYear()} Auction Hub Group. All rights reserved.</p>
    </td>
  </tr>
</table>
</td></tr></table>
</div>`;
}

function _invoiceFilename(type) {
    const bidder = (_invTxt('invBidderName') || 'bidder').replace(/[^A-Za-z0-9]+/g, '_');
    const num    = (_invTxt('invNumber') || 'ref').replace(/[^A-Za-z0-9]+/g, '_');
    const label  = type === 'winner' ? 'Winner_Invoice' : 'PreAuction_Quotation';
    return `AHG_${label}_${bidder}_${num}.pdf`;
}

function _buildInvoicePdfBlob(type) {
    // Build PDF from an off-screen rendering of the invoice HTML.
    return new Promise((resolve, reject) => {
        if (typeof html2pdf === 'undefined') { reject(new Error('html2pdf not loaded')); return; }
        const html = _invoiceHtml(type);
        const wrap = document.createElement('div');
        wrap.style.cssText = 'position:fixed;left:-10000px;top:0;width:820px;background:#fff;';
        wrap.innerHTML = html;
        document.body.appendChild(wrap);
        const root = wrap.querySelector('#pva-invoice-root') || wrap;
        html2pdf().set({
            margin: [8, 6, 8, 6],
            filename: _invoiceFilename(type),
            image: { type: 'jpeg', quality: 0.95 },
            html2canvas: { scale: 2, useCORS: true, allowTaint: true, backgroundColor: '#ffffff' },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak: { mode: ['css', 'legacy'] }
        }).from(root).outputPdf('blob').then(blob => {
            document.body.removeChild(wrap);
            resolve(blob);
        }).catch(err => { if (wrap.parentNode) document.body.removeChild(wrap); reject(err); });
    });
}

function downloadInvoicePdf(type) {
    if (!_invTxt('invBidderName') || !_invTxt('invVehicle') || !_invTxt('invNumber')) {
        showToast('Please fill in Bidder Name, Vehicle, and Invoice / Reference #.', 'error');
        return;
    }
    if (typeof html2pdf === 'undefined') { showToast('PDF library not loaded — refresh the page.', 'error'); return; }
    showToast('Building PDF…', 'success');
    _buildInvoicePdfBlob(type).then(blob => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = _invoiceFilename(type);
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 2000);
        showToast('PDF downloaded.', 'success');
    }).catch(err => { console.error(err); showToast('PDF build failed.', 'error'); });
}

function buildInvoiceTemplate(type) {
    if (!_invTxt('invBidderName') || !_invTxt('invVehicle') || !_invTxt('invNumber')) {
        showToast('Please fill in Bidder Name, Vehicle, and Invoice / Reference #.', 'error');
        return;
    }
    const html = _invoiceHtml(type);
    const subject = type === 'winner'
        ? 'Congratulations — You Won ' + _invTxt('invVehicle') + ' — Invoice #' + _invTxt('invNumber')
        : 'Pre-Auction Invoice — ' + _invTxt('invVehicle') + ' — Ref #' + _invTxt('invNumber');

    // Load into single email (it's a per-user invoice)
    setEmailFormat('single', 'html');
    document.getElementById('singleMsg').value = html;
    document.querySelector('#singleEmailForm input[name="subject"]').value = subject;
    updateHtmlPreview('single');
    // Ensure preview is visible
    const preview = document.getElementById('singleHtmlPreview');
    if (preview && preview.style.display === 'none') toggleHtmlPreview('single');

    // Only winner invoices auto-attach a generated PDF. Pre-auction quotations use an admin-supplied PDF.
    if (type === 'winner') {
        window._pendingInvoicePdf = {
            filename: _invoiceFilename(type),
            buildBlob: () => _buildInvoicePdfBlob(type)
        };
    } else {
        window._pendingInvoicePdf = null;
    }

    document.getElementById('singleEmailForm').scrollIntoView({behavior:'smooth', block:'start'});
    const msg = type === 'winner'
        ? 'Winner invoice loaded. PDF will auto-attach on send.'
        : 'Pre-auction invoice loaded. Attach your own PDF manually if required.';
    showToast(msg, 'success');
}

function previewInvoiceTemplate() {
    const type = confirm('OK = Winner invoice preview · Cancel = Pre-Auction invoice preview') ? 'winner' : 'preauction';
    if (!_invTxt('invBidderName') || !_invTxt('invVehicle') || !_invTxt('invNumber')) {
        showToast('Please fill in Bidder Name, Vehicle, and Invoice / Reference #.', 'error');
        return;
    }
    const w = window.open('', '_blank');
    if (w) { w.document.write(_invoiceHtml(type)); w.document.close(); }
}

// ── Vehicle lookup for invoice builder ─────────────────────────
async function searchVehicleForInvoice() {
    const q = (document.getElementById('invVehicleSearch').value || '').trim();
    const box = document.getElementById('invVehicleResults');
    box.style.display = 'block';
    box.innerHTML = '<p class="text-muted" style="padding:.5rem;">Searching…</p>';
    try {
        const res = await fetch('api.php?action=search_active_auctions&q=' + encodeURIComponent(q));
        const data = await res.json();
        const list = (data && data.auctions) || [];
        if (!list.length) { box.innerHTML = '<p class="text-muted" style="padding:.5rem;">No matching vehicles.</p>'; return; }
        box.innerHTML = list.map(a => {
            const title = [a.year, a.make_name, a.item_name].filter(Boolean).join(' ');
            const thumb = a.image_url
                ? `<img src="${a.image_url}" alt="" style="width:56px;height:42px;object-fit:cover;border-radius:4px;flex-shrink:0;">`
                : `<div style="width:56px;height:42px;background:#e8eef5;border-radius:4px;flex-shrink:0;"></div>`;
            return `
              <div style="display:flex;align-items:center;gap:.6rem;padding:.5rem;border:1px solid #e5ebf2;border-radius:6px;margin-bottom:.4rem;cursor:pointer;background:#fff;" onclick='selectVehicleForInvoice(${a.id})'>
                ${thumb}
                <div style="flex:1;min-width:0;">
                  <div style="font-weight:700;color:#003f73;font-size:.88rem;">${title}</div>
                  <div style="font-size:.75rem;color:#666;">#${a.id} · ${a.status}</div>
                </div>
                <button type="button" class="btn btn-sm btn-primary" style="background:#005BA1;border-color:#005BA1;"><i class="fas fa-plus"></i> Load</button>
              </div>`;
        }).join('');
    } catch (e) {
        box.innerHTML = '<p style="color:#b00;padding:.5rem;">Search failed.</p>';
    }
}

async function selectVehicleForInvoice(id) {
    try {
        const res = await fetch('api.php?action=get_auction_detail&id=' + id);
        const data = await res.json();
        if (!data || !data.success || !data.auction) { showToast('Could not load vehicle.', 'error'); return; }
        const v = data.auction;
        document.getElementById('invVehicleDetailsJson').value = JSON.stringify(v);
        const title = [v.year, v.make, v.item_name].filter(Boolean).join(' ');
        if (!document.getElementById('invVehicle').value.trim()) {
            document.getElementById('invVehicle').value = title;
        }
        document.getElementById('invVehicleResults').style.display = 'none';
        document.getElementById('invVehicleClearBtn').style.display = '';
        const loaded = document.getElementById('invVehicleLoaded');
        loaded.style.display = 'block';
        loaded.innerHTML = `
          <div style="display:flex;gap:.75rem;align-items:center;">
            ${v.image_url ? `<img src="${v.image_url}" alt="" style="width:80px;height:60px;object-fit:cover;border-radius:6px;">` : ''}
            <div>
              <strong style="color:#003f73;">${title}</strong><br>
              <small style="color:#555;">
                ${v.mileage ? Number(v.mileage).toLocaleString('en-ZA') + ' km · ' : ''}
                ${v.fuel_type || ''} ${v.transmission ? '· ' + v.transmission : ''}
                ${v.condition_grade ? '· DEKRA ' + v.condition_grade : ''}
              </small>
            </div>
          </div>`;
        showToast('Vehicle loaded into invoice.', 'success');
    } catch (e) { showToast('Error loading vehicle.', 'error'); }
}

function clearInvoiceVehicle() {
    document.getElementById('invVehicleDetailsJson').value = '';
    document.getElementById('invVehicleLoaded').style.display = 'none';
    document.getElementById('invVehicleLoaded').innerHTML = '';
    document.getElementById('invVehicleClearBtn').style.display = 'none';
    document.getElementById('invVehicleResults').style.display = 'none';
    document.getElementById('invVehicleSearch').value = '';
    showToast('Vehicle details cleared.', 'success');
}

// ── CSV Import ────────────────────────────────────────────────
const csvFile   = document.getElementById('csvFile');
const csvDrop   = document.getElementById('csvDropZone');
const importBtn = document.getElementById('importSubmitBtn');
if(csvDrop&&csvFile){
    ['dragenter','dragover'].forEach(e=>csvDrop.addEventListener(e,ev=>{ev.preventDefault();csvDrop.classList.add('drag-over');}));
    ['dragleave','drop'].forEach(e=>csvDrop.addEventListener(e,ev=>{ev.preventDefault();csvDrop.classList.remove('drag-over');}));
    csvDrop.addEventListener('drop',ev=>{if(ev.dataTransfer.files[0]){csvFile.files=ev.dataTransfer.files;handleCSVSelect(ev.dataTransfer.files[0]);}});
    csvFile.addEventListener('change',()=>{if(csvFile.files[0])handleCSVSelect(csvFile.files[0]);});
}
function handleCSVSelect(file){
    if(!file.name.endsWith('.csv')){showToast('Please select a CSV file.','error');return;}
    const reader=new FileReader();
    reader.onload=e=>{
        const rows=e.target.result.split('\n').filter(r=>r.trim()).length-1;
        const info=document.getElementById('csvPreviewInfo');
        if(info){document.getElementById('csvRowCount').textContent=rows;info.style.display='block';}
        if(importBtn)importBtn.disabled=false;
    };
    reader.readAsText(file);
}

const importForm=document.getElementById('importForm');
if(importForm){importForm.addEventListener('submit',async function(e){
    e.preventDefault();
    if(!csvFile||!csvFile.files[0]){showToast('Please select a CSV file.','error');return;}
    const btn=document.getElementById('importSubmitBtn');
    const prog=document.getElementById('importProgress');
    const fill=document.getElementById('importProgressFill');
    const label=document.getElementById('importProgressLabel');
    btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Importing...';
    if(prog)prog.style.display='block';
    // Animate progress bar
    let pct=0;const timer=setInterval(()=>{pct=Math.min(pct+Math.random()*8,85);if(fill)fill.style.width=pct+'%';if(label)label.textContent='Processing row '+(Math.floor(pct))+' of 100...';},300);
    try{
        const res=await fetch('api.php',{method:'POST',body:new FormData(importForm)});
        const data=await res.json();
        clearInterval(timer);
        if(fill)fill.style.width='100%';
        if(label)label.textContent=data.success?'Import complete!':'Import failed.';
        const resultEl=document.getElementById('importResult');
        if(resultEl)resultEl.innerHTML=`<div class="alert alert-${data.success?'success':'error'}">${data.message}${data.imported?` — ${data.imported} vehicles imported.`:''}</div>`;
        if(data.success)setTimeout(()=>location.reload(),2000);
    }catch{clearInterval(timer);showToast('Import failed. Please try again.','error');}
    finally{btn.disabled=false;btn.innerHTML='<i class="fas fa-upload"></i> Import Vehicles';}
});}

// ── Import errors ─────────────────────────────────────────────
function showImportErrors(errJson){
    let errors;
    try{errors=typeof errJson==='string'?JSON.parse(errJson):errJson;}catch{errors=['Could not parse error log.'];}
    const list=Array.isArray(errors)?errors.map(e=>`<div class="import-error-item"><i class="fas fa-exclamation-triangle"></i> ${e}</div>`).join(''):'<p>'+errors+'</p>';
    document.getElementById('importErrorsContent').innerHTML=list;
    openModal('importErrorsModal');
}

// ── Image lightbox ────────────────────────────────────────────
function openImageLightbox(src){
    document.getElementById('adminLightboxImg').src=src;
    document.getElementById('adminLightbox').style.display='flex';
}
function closeImageLightbox(){
    document.getElementById('adminLightbox').style.display='none';
}

// ── Overview countdown timers ─────────────────────────────────
document.querySelectorAll('[data-countdown]').forEach(el=>{
    const raw=(el.dataset.countdown||'').trim();
    const span=el.querySelector('span')||el;
    if(!raw){span.textContent='Date TBC';return;}
    const end=new Date(raw.replace(' ','T')).getTime();
    if(isNaN(end)){span.textContent='Date TBC';return;}
    function tick(){
        const diff=end-Date.now();
        if(diff<=0){span.textContent='Started!';return;}
        const h=Math.floor(diff/3600000),m=Math.floor((diff%3600000)/60000),s=Math.floor((diff%60000)/1000);
        span.textContent=`${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
        setTimeout(tick,1000);
    }tick();
});

// ── Bulk email count hint ─────────────────────────────────────
function updateBulkCount(group){
    const counts={
        all:<?= $stats['total_users'] ?>,
        verified:<?= $stats['verified_bidders'] ?>,
        pending:<?= $stats['pending_pops'] ?>,
        registered:<?= (int)$pdo->query("SELECT COUNT(*) FROM users WHERE bidder_status='registered' AND role='user'")->fetchColumn() ?>
    };
    const hint=document.getElementById('bulkCountHint');
    if(hint)hint.textContent=`${counts[group]||0} email(s) will be sent.`;
}

// ══════════════════════════════════════════════════════════════
// ANALYTICS TAB
// ══════════════════════════════════════════════════════════════
(function(){
    if(!document.getElementById('chartBidsPerDay')) return;

    let chartsLoaded = false;

    function loadAnalytics(){
        fetch('api.php?action=get_analytics')
            .then(r=>r.json())
            .then(d=>{
                if(!d.success){ showToast('Failed to load analytics.','error'); return; }

                // KPIs
                const bids30 = d.bids_per_day.data.reduce((a,b)=>a+b,0);
                const regs30 = d.registrations.data.reduce((a,b)=>a+b,0);
                const closedIdx = d.auction_status.labels.indexOf('Closed');
                const closed = closedIdx>=0 ? d.auction_status.data[closedIdx] : 0;
                const totalRev = d.revenue_monthly.data.reduce((a,b)=>a+b,0);
                document.getElementById('kpi-bids').textContent  = bids30.toLocaleString();
                document.getElementById('kpi-regs').textContent  = regs30.toLocaleString();
                document.getElementById('kpi-closed').textContent = closed.toLocaleString();
                document.getElementById('kpi-revenue').textContent = 'R '+totalRev.toLocaleString('en-ZA',{minimumFractionDigits:0,maximumFractionDigits:0});

                if(chartsLoaded){ window._analyticsCharts.forEach(c=>c.destroy()); }
                window._analyticsCharts = [];
                chartsLoaded = true;

                const lineOpts = (label,color)=>({
                    type:'line',
                    options:{responsive:true,plugins:{legend:{display:false}},
                             scales:{x:{ticks:{maxRotation:45,font:{size:10}}},y:{beginAtZero:true,ticks:{precision:0}}}}
                });

                // Bids per day
                window._analyticsCharts.push(new Chart(document.getElementById('chartBidsPerDay'),{
                    type:'line',
                    data:{labels:d.bids_per_day.labels,datasets:[{label:'Bids',data:d.bids_per_day.data,fill:true,
                          borderColor:'#1565c0',backgroundColor:'rgba(21,101,192,.1)',tension:.3,pointRadius:3}]},
                    options:{responsive:true,plugins:{legend:{display:false}},
                             scales:{x:{ticks:{maxRotation:45,font:{size:10}}},y:{beginAtZero:true,ticks:{precision:0}}}}
                }));

                // Regs per day
                window._analyticsCharts.push(new Chart(document.getElementById('chartRegsPerDay'),{
                    type:'line',
                    data:{labels:d.registrations.labels,datasets:[{label:'Registrations',data:d.registrations.data,fill:true,
                          borderColor:'#2e7d32',backgroundColor:'rgba(46,125,50,.1)',tension:.3,pointRadius:3}]},
                    options:{responsive:true,plugins:{legend:{display:false}},
                             scales:{x:{ticks:{maxRotation:45,font:{size:10}}},y:{beginAtZero:true,ticks:{precision:0}}}}
                }));

                // Revenue monthly
                window._analyticsCharts.push(new Chart(document.getElementById('chartRevenue'),{
                    type:'bar',
                    data:{labels:d.revenue_monthly.labels,datasets:[{label:'Revenue (R)',data:d.revenue_monthly.data,
                          backgroundColor:'#1565c0',borderRadius:4}]},
                    options:{responsive:true,plugins:{legend:{display:false}},
                             scales:{y:{beginAtZero:true,ticks:{callback:v=>'R '+v.toLocaleString()}}}}
                }));

                // Auction status doughnut
                const colors=['#1565c0','#2e7d32','#c62828','#546e7a'];
                window._analyticsCharts.push(new Chart(document.getElementById('chartStatus'),{
                    type:'doughnut',
                    data:{labels:d.auction_status.labels,datasets:[{data:d.auction_status.data,
                          backgroundColor:colors.slice(0,d.auction_status.labels.length)}]},
                    options:{responsive:true,plugins:{legend:{position:'bottom'}}}
                }));

                // Top auctions table
                const atbody = document.querySelector('#topAuctionsTable tbody');
                atbody.innerHTML = d.top_auctions.length
                    ? d.top_auctions.map((a,i)=>`<tr>
                        <td>${i+1}</td>
                        <td>${a.item_name}</td>
                        <td><strong>R ${parseFloat(a.price).toLocaleString('en-ZA',{minimumFractionDigits:2,maximumFractionDigits:2})}</strong></td>
                        <td>${a.bid_count}</td>
                      </tr>`).join('')
                    : '<tr><td colspan="4" class="text-muted" style="text-align:center">No closed auctions yet.</td></tr>';

                // Top bidders table
                const btbody = document.querySelector('#topBiddersTable tbody');
                btbody.innerHTML = d.top_bidders.length
                    ? d.top_bidders.map((b,i)=>`<tr>
                        <td>${i+1}</td>
                        <td>${b.full_name}${b.bidder_number?` <small class="text-muted">${b.bidder_number}</small>`:''}</td>
                        <td>${b.total_bids}</td>
                        <td>R ${parseFloat(b.total_value||0).toLocaleString('en-ZA',{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                      </tr>`).join('')
                    : '<tr><td colspan="4" class="text-muted" style="text-align:center">No bids yet.</td></tr>';
            })
            .catch(()=>showToast('Error loading analytics.','error'));
    }
    window.loadAnalytics = loadAnalytics;
    loadAnalytics();
})();

// ══════════════════════════════════════════════════════════════
// MANUAL BID TAB
// ══════════════════════════════════════════════════════════════
(function(){
    if(!document.getElementById('auctionSearch')) return;

    let selectedAuction = null;
    let selectedBidder  = null;

    // ── Auction autocomplete ──────────────────────────────────
    const aInput = document.getElementById('auctionSearch');
    const aDrop  = document.getElementById('auctionSuggestions');
    const aPrev  = document.getElementById('auctionPreview');

    let aTimer;
    aInput.addEventListener('input',()=>{
        clearTimeout(aTimer);
        const q = aInput.value.trim();
        if(q.length<1){ aDrop.style.display='none'; return; }
        aTimer = setTimeout(()=>{
            fetch(`api.php?action=search_active_auctions&q=${encodeURIComponent(q)}`)
                .then(r=>r.json()).then(d=>{
                    if(!d.success||!d.auctions.length){ aDrop.innerHTML='<div class="autocomplete-item text-muted">No active auctions found.</div>'; aDrop.style.display='block'; return; }
                    aDrop.innerHTML = d.auctions.map(a=>{
                        const curr = parseFloat(a.current_price||a.start_price||0);
                        const minNext = curr>0 ? curr+parseFloat(a.min_increment||500) : parseFloat(a.start_price||0);
                        return `<div class="autocomplete-item" onclick="selectAuction(${a.id},'${a.item_name.replace(/'/g,"\\'")}',${curr},${minNext},${a.min_increment||500})">
                            <strong>${a.item_name}</strong>
                            <small class="text-muted"> — Current: R ${curr.toLocaleString()}</small>
                        </div>`;
                    }).join('');
                    aDrop.style.display='block';
                });
        },300);
    });

    window.selectAuction = function(id, name, curr, minNext, inc){
        selectedAuction = {id, name, curr, minNext, inc};
        document.getElementById('selectedAuctionId').value = id;
        aInput.value = name;
        aDrop.style.display='none';
        aPrev.innerHTML = `<span class="badge badge-active">Active</span> <strong>${name}</strong> — Current Price: <strong>R ${curr.toLocaleString()}</strong> | Min Next Bid: <strong>R ${minNext.toLocaleString()}</strong>`;
        aPrev.style.display='block';
        document.getElementById('manualBidAmount').value = minNext;
        document.getElementById('minBidHint').textContent = `Minimum bid: R ${minNext.toLocaleString('en-ZA',{minimumFractionDigits:2})}`;
    };

    document.addEventListener('click',e=>{
        if(!aInput.contains(e.target)&&!aDrop.contains(e.target)) aDrop.style.display='none';
    });

    // ── Bidder autocomplete ───────────────────────────────────
    const bInput = document.getElementById('bidderSearch');
    const bDrop  = document.getElementById('bidderSuggestions');
    const bPrev  = document.getElementById('bidderPreview');

    let bTimer;
    bInput.addEventListener('input',()=>{
        clearTimeout(bTimer);
        const q = bInput.value.trim();
        if(q.length<2){ bDrop.style.display='none'; return; }
        bTimer = setTimeout(()=>{
            fetch(`api.php?action=search_bidders&q=${encodeURIComponent(q)}`)
                .then(r=>r.json()).then(d=>{
                    if(!d.success||!d.users.length){ bDrop.innerHTML='<div class="autocomplete-item text-muted">No verified bidders found.</div>'; bDrop.style.display='block'; return; }
                    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                    bDrop.innerHTML = d.users.map((u, i) => `<div class="autocomplete-item" data-i="${i}">
                        <strong>${esc(u.full_name)}</strong>${u.floor == 1 ? ' <span class="floor-badge">Floor</span>' : ''}
                        <small class="text-muted"> ${u.bidder_number ? '· ' + esc(u.bidder_number) : ''}${u.phone ? ' · ' + esc(u.phone) : ''}${u.email ? ' · ' + esc(u.email) : ''}</small>
                    </div>`).join('');
                    bDrop.querySelectorAll('.autocomplete-item').forEach(el => el.addEventListener('click', () => {
                        const u = d.users[el.dataset.i];
                        selectBidder(u.id, u.full_name, u.email || u.phone || '', u.bidder_number || '');
                    }));
                    bDrop.style.display='block';
                });
        },300);
    });

    window.selectBidder = function(id, name, email, num){
        selectedBidder = {id, name, email, num};
        document.getElementById('selectedBidderId').value = id;
        bInput.value = name;
        bDrop.style.display='none';
        const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        bPrev.innerHTML = `<span class="badge badge-verified-bidder">${esc(num||'No #')}</span> <strong>${esc(name)}</strong> <small class="text-muted">${esc(email)}</small>`;
        bPrev.style.display='block';
    };

    document.addEventListener('click',e=>{
        if(!bInput.contains(e.target)&&!bDrop.contains(e.target)) bDrop.style.display='none';
    });

    // ── Submit manual bid ─────────────────────────────────────
    window.submitManualBid = async function(){
        const auctionId = document.getElementById('selectedAuctionId').value;
        const userId    = document.getElementById('selectedBidderId').value;
        const amount    = document.getElementById('manualBidAmount').value;
        const note      = document.getElementById('manualBidNote').value.trim();
        const resultEl  = document.getElementById('manualBidResult');
        const btn       = document.getElementById('submitManualBidBtn');

        if(!auctionId){ showToast('Please select an auction.','error'); return; }
        if(!userId)   { showToast('Please select a bidder.','error'); return; }
        if(!amount||parseFloat(amount)<=0){ showToast('Please enter a valid bid amount.','error'); return; }

        if(!confirm(`Place bid of R ${parseFloat(amount).toLocaleString()} for ${selectedBidder?.name||'this bidder'} on "${selectedAuction?.name||'this auction'}"?`)) return;

        btn.disabled=true;
        btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Placing bid...';
        resultEl.innerHTML='';

        const fd = new FormData();
        fd.append('action','admin_place_bid');
        fd.append('auction_id', auctionId);
        fd.append('user_id', userId);
        fd.append('amount', amount);
        fd.append('note', note);

        try{
            const res  = await fetch('api.php',{method:'POST',body:fd});
            const data = await res.json();
            resultEl.innerHTML=`<div class="alert alert-${data.success?'success':'error'}">${data.message}</div>`;
            showToast(data.message, data.success?'success':'error');
            if(data.success){
                // Reset form
                selectedAuction=null; selectedBidder=null;
                aInput.value=''; bInput.value='';
                aPrev.style.display='none'; bPrev.style.display='none';
                document.getElementById('selectedAuctionId').value='';
                document.getElementById('selectedBidderId').value='';
                document.getElementById('manualBidAmount').value='';
                document.getElementById('manualBidNote').value='';
                document.getElementById('minBidHint').textContent='';
                setTimeout(()=>location.reload(),2000);
            }
        }catch{
            resultEl.innerHTML='<div class="alert alert-error">Request failed. Please try again.</div>';
        }finally{
            btn.disabled=false;
            btn.innerHTML='<i class="fas fa-gavel"></i> Place Bid';
        }
    };
})();

// ── Banking Details tab ──────────────────────────────────────
(function(){
    const form = document.getElementById('bankSettingsForm');
    if (!form) return;

    // Live preview
    const map = {
        bank_name: 'prevBankName',
        bank_account_name: 'prevAccountName',
        bank_account_no: 'prevAccountNo',
        bank_branch_code: 'prevBranchCode',
        bank_account_type: 'prevAccountType',
        reg_fee_label: 'prevFeeLabel',
    };
    Object.keys(map).forEach(name => {
        const input = form.querySelector(`[name="${name}"]`);
        const preview = document.getElementById(map[name]);
        if (input && preview) {
            input.addEventListener('input', () => { preview.textContent = input.value; });
        }
    });

    // Submit
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('saveBankSettingsBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        const resultDiv = document.getElementById('bankSettingsResult');
        try {
            const res = await fetch('api.php', {method:'POST', body: new FormData(form)});
            const data = await res.json();
            resultDiv.innerHTML = `<div class="alert alert-${data.success?'success':'error'}">${data.message}</div>`;
        } catch { resultDiv.innerHTML = '<div class="alert alert-error">Error saving settings.</div>'; }
        finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Banking Details'; }
    });
})();

// ══════════════════════════════════════════════════════════════
// TESTIMONIALS TAB
// ══════════════════════════════════════════════════════════════

// Image preview for testimonial upload
const testimonialImgInput = document.getElementById('testimonialImage');
if (testimonialImgInput) {
    testimonialImgInput.addEventListener('change', function() {
        const preview = document.getElementById('testimonialPreview');
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = e => { preview.innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;">`; };
            reader.readAsDataURL(this.files[0]);
        }
    });
}

async function submitTestimonial() {
    const fileInput = document.getElementById('testimonialImage');
    const headline  = document.getElementById('testimonialHeadline').value.trim();
    if (!fileInput.files[0]) { showToast('Please select an image.','error'); return; }
    if (!headline) { showToast('Please enter a headline.','error'); return; }

    const btn = document.getElementById('submitTestimonialBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';

    const fd = new FormData();
    fd.append('action', 'add_testimonial');
    fd.append('image', fileInput.files[0]);
    fd.append('headline', headline);

    try {
        const res = await fetch('api.php', {method:'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) { closeModal('addTestimonialModal'); setTimeout(() => location.reload(), 1000); }
    } catch { showToast('Upload failed.','error'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-upload"></i> Upload Testimonial'; }
}

function editTestimonial(id, headline) {
    document.getElementById('editTestimonialId').value = id;
    document.getElementById('editTestimonialHeadline').value = headline;
    openModal('editTestimonialModal');
}

async function submitEditTestimonial() {
    const id = document.getElementById('editTestimonialId').value;
    const headline = document.getElementById('editTestimonialHeadline').value.trim();
    if (!headline) { showToast('Headline is required.','error'); return; }
    const fd = new FormData();
    fd.append('action', 'update_testimonial');
    fd.append('id', id);
    fd.append('field', 'headline');
    fd.append('value', headline);
    try {
        const res = await fetch('api.php', {method:'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) { closeModal('editTestimonialModal'); setTimeout(() => location.reload(), 800); }
    } catch { showToast('Error.','error'); }
}

async function updateTestimonial(id, field, value) {
    const fd = new FormData();
    fd.append('action', 'update_testimonial');
    fd.append('id', id);
    fd.append('field', field);
    fd.append('value', value);
    try {
        const res = await fetch('api.php', {method:'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
    } catch { showToast('Error updating.','error'); }
}

async function deleteTestimonial(id) {
    if (!confirm('Delete this testimonial? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_testimonial');
    fd.append('id', id);
    try {
        const res = await fetch('api.php', {method:'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) {
            const card = document.querySelector(`.testim-admin-card[data-id="${id}"]`);
            if (card) { card.style.opacity = '0'; card.style.transition = 'opacity .4s'; setTimeout(() => card.remove(), 400); }
        }
    } catch { showToast('Error.','error'); }
}

// ══════════════════════════════════════════════════════════════
// NOTIFICATIONS TAB
// ══════════════════════════════════════════════════════════════

async function submitNotification() {
    const type = document.getElementById('notifType').value;
    const message = document.getElementById('notifMessage').value.trim();
    const icon = document.getElementById('notifIcon').value;
    const frequency = document.getElementById('notifFrequency').value || 10;
    if (!message) { showToast('Message is required.','error'); return; }

    const fd = new FormData();
    fd.append('action', 'add_notification');
    fd.append('type', type);
    fd.append('message', message);
    fd.append('icon', icon);
    fd.append('frequency', frequency);
    try {
        const res = await fetch('api.php', {method:'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) { closeModal('addNotificationModal'); setTimeout(() => location.reload(), 800); }
    } catch { showToast('Error.','error'); }
}

function editNotification(id, message, type, frequency) {
    document.getElementById('editNotifId').value = id;
    document.getElementById('editNotifMessage').value = message;
    document.getElementById('editNotifType').value = type;
    document.getElementById('editNotifFrequency').value = frequency || 10;
    openModal('editNotificationModal');
}

async function submitEditNotification() {
    const id = document.getElementById('editNotifId').value;
    const message = document.getElementById('editNotifMessage').value.trim();
    const type = document.getElementById('editNotifType').value;
    const frequency = document.getElementById('editNotifFrequency').value || 10;
    if (!message) { showToast('Message is required.','error'); return; }
    const fd = new FormData();
    fd.append('action', 'update_notification');
    fd.append('id', id);
    fd.append('message', message);
    fd.append('type', type);
    fd.append('frequency', frequency);
    try {
        const res = await fetch('api.php', {method:'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) { closeModal('editNotificationModal'); setTimeout(() => location.reload(), 800); }
    } catch { showToast('Error.','error'); }
}

async function updateNotification(id, field, value) {
    const fd = new FormData();
    fd.append('action', 'update_notification');
    fd.append('id', id);
    fd.append('field', field);
    fd.append('value', value);
    try {
        const res = await fetch('api.php', {method:'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
    } catch { showToast('Error.','error'); }
}

async function deleteNotification(id) {
    if (!confirm('Delete this notification?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_notification');
    fd.append('id', id);
    try {
        const res = await fetch('api.php', {method:'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) {
            const row = document.getElementById('notif-row-' + id);
            if (row) { row.style.opacity = '0'; row.style.transition = 'opacity .4s'; setTimeout(() => row.remove(), 400); }
        }
    } catch { showToast('Error.','error'); }
}

// ══════════════════════════════════════════════════════════════
// TESTIMONIALS — multi-upload, inline edit, reorder, replace
// ══════════════════════════════════════════════════════════════

let testimPendingFiles = []; // {file, objectUrl}

function handleTestimDrop(files) { handleTestimFiles(files); }

function handleTestimFiles(files) {
    if (!files || !files.length) return;
    const previews = document.getElementById('testimPreviews');
    Array.from(files).forEach((file, i) => {
        const idx = testimPendingFiles.length;
        const url = URL.createObjectURL(file);
        testimPendingFiles.push({file, objectUrl: url});
        const card = document.createElement('div');
        card.dataset.index = idx;
        card.style.cssText = 'background:#fff;border:1px solid #dce6f0;border-radius:10px;overflow:hidden;display:flex;flex-direction:column;';
        card.innerHTML = `
            <div style="aspect-ratio:4/3;overflow:hidden;background:#f1f5f9;">
                <img src="${url}" style="width:100%;height:100%;object-fit:cover;">
            </div>
            <div style="padding:.6rem;">
                <input type="text" class="form-control testim-headline-input" placeholder="Enter caption..."
                       style="width:100%;font-size:.83rem;margin-bottom:.4rem;" value="">
                <button onclick="removeTestimPreview(${idx})" style="font-size:.75rem;background:#fee2e2;color:#b91c1c;border:none;border-radius:6px;padding:.25rem .6rem;cursor:pointer;width:100%;">
                    <i class="fas fa-times"></i> Remove
                </button>
            </div>`;
        previews.appendChild(card);
    });
    // Reset the file input so the same files can be re-selected after clear
    document.getElementById('inlineTestimonialImage').value = '';
    document.getElementById('inlineTestimonialBtn').style.display = '';
    document.getElementById('testimClearBtn').style.display = '';
}

function removeTestimPreview(idx) {
    testimPendingFiles[idx] = null;
    const previews = document.getElementById('testimPreviews');
    const card = previews.querySelector(`[data-index="${idx}"]`);
    if (card) card.remove();
    if (!previews.children.length) {
        document.getElementById('inlineTestimonialBtn').style.display = 'none';
        document.getElementById('testimClearBtn').style.display = 'none';
    }
}

function clearTestimPreviews() {
    testimPendingFiles.forEach(f => f && URL.revokeObjectURL(f.objectUrl));
    testimPendingFiles = [];
    document.getElementById('testimPreviews').innerHTML = '';
    document.getElementById('inlineTestimonialImage').value = '';
    document.getElementById('inlineTestimonialBtn').style.display = 'none';
    document.getElementById('testimClearBtn').style.display = 'none';
    document.getElementById('testimProgress').textContent = '';
}

async function submitAllTestimonials() {
    const queue = testimPendingFiles.filter(f => f !== null);
    if (!queue.length) { showToast('No images selected.', 'error'); return; }

    const btn = document.getElementById('inlineTestimonialBtn');
    const progress = document.getElementById('testimProgress');
    const result = document.getElementById('inlineTestimonialResult');
    btn.disabled = true;

    // Collect headlines from the preview cards in order
    const previews = document.getElementById('testimPreviews');
    const inputs = previews.querySelectorAll('.testim-headline-input');
    const headlines = Array.from(inputs).map(el => el.value.trim());

    let done = 0, failed = 0;
    for (let i = 0; i < queue.length; i++) {
        const item = queue[i];
        progress.textContent = `Uploading ${i + 1} of ${queue.length}…`;
        const fd = new FormData();
        fd.append('action', 'add_testimonial');
        fd.append('image', item.file);
        fd.append('headline', headlines[i] || '');
        try {
            const res = await fetch('api.php', {method: 'POST', body: fd});
            const data = await res.json();
            if (data.success) done++; else { failed++; showToast(`Image ${i+1}: ${data.message}`, 'error'); }
        } catch { failed++; showToast(`Image ${i+1}: upload failed.`, 'error'); }
    }

    btn.disabled = false;
    progress.textContent = '';
    if (done) {
        result.innerHTML = `<div class="alert alert-success" style="margin-bottom:.75rem;">Uploaded ${done} testimonial${done > 1 ? 's' : ''} successfully!</div>`;
        setTimeout(() => location.reload(), 900);
    }
}

function inlineEditHeadline(el, id) {
    if (el.querySelector('input')) return; // already editing
    const original = el.textContent.trim();
    el.style.borderBottomColor = '#005DDB';
    const input = document.createElement('input');
    input.type = 'text';
    input.value = original;
    input.style.cssText = 'width:100%;border:none;outline:none;background:transparent;font:inherit;color:inherit;padding:0;';

    async function save() {
        const val = input.value.trim() || original;
        el.textContent = val;
        el.style.borderBottomColor = 'transparent';
        if (val === original) return;
        const fd = new FormData();
        fd.append('action', 'update_testimonial');
        fd.append('id', id);
        fd.append('field', 'headline');
        fd.append('value', val);
        try {
            const res = await fetch('api.php', {method: 'POST', body: fd});
            const data = await res.json();
            showToast(data.message, data.success ? 'success' : 'error');
        } catch { showToast('Error saving.', 'error'); }
    }

    input.addEventListener('blur', save);
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { input.value = original; input.blur(); }
    });

    el.textContent = '';
    el.appendChild(input);
    input.focus();
    input.select();
}

async function moveTestim(id, direction) {
    const grid = document.getElementById('testimGrid');
    const cards = Array.from(grid.querySelectorAll('.testim-admin-card'));
    const idx = cards.findIndex(c => +c.dataset.id === id);
    if (idx === -1) return;
    const targetIdx = idx + direction;
    if (targetIdx < 0 || targetIdx >= cards.length) return;

    const cardA = cards[idx];
    const cardB = cards[targetIdx];
    const orderA = +cardA.dataset.order;
    const orderB = +cardB.dataset.order;

    // Swap dataset.order
    cardA.dataset.order = orderB;
    cardB.dataset.order = orderA;

    // Swap DOM positions
    if (direction === 1) {
        grid.insertBefore(cardB, cardA);
    } else {
        grid.insertBefore(cardA, cardB);
    }

    // Persist both
    await Promise.all([
        apiUpdate(id, 'sort_order', orderB),
        apiUpdate(+cardB.dataset.id, 'sort_order', orderA),
    ]);
}

async function apiUpdate(id, field, value) {
    const fd = new FormData();
    fd.append('action', 'update_testimonial');
    fd.append('id', id);
    fd.append('field', field);
    fd.append('value', value);
    try {
        const res = await fetch('api.php', {method: 'POST', body: fd});
        const data = await res.json();
        if (!data.success) showToast(data.message, 'error');
    } catch { showToast('Error saving.', 'error'); }
}

async function toggleTestim(id, checked, el) {
    const card = el.closest('.testim-admin-card');
    const fd = new FormData();
    fd.append('action', 'update_testimonial');
    fd.append('id', id);
    fd.append('field', 'is_active');
    fd.append('value', checked ? 1 : 0);
    try {
        const res = await fetch('api.php', {method: 'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success && card) {
            card.classList.toggle('testim-inactive', !checked);
        } else if (!data.success) {
            el.checked = !checked; // revert
        }
    } catch { showToast('Error.', 'error'); el.checked = !checked; }
}

let currentReplaceTestimId = null;

function replaceTestimImage(id) {
    currentReplaceTestimId = id;
    const input = document.getElementById('testimReplaceInput');
    input.value = '';
    input.click();
}

async function submitReplaceImage(input) {
    if (!input.files[0] || !currentReplaceTestimId) return;
    const card = document.querySelector(`.testim-admin-card[data-id="${currentReplaceTestimId}"]`);
    const fd = new FormData();
    fd.append('action', 'replace_testimonial_image');
    fd.append('id', currentReplaceTestimId);
    fd.append('image', input.files[0]);
    showToast('Uploading replacement image…', 'success');
    try {
        const res = await fetch('api.php', {method: 'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success && card) {
            const img = card.querySelector('img');
            if (img) img.src = data.image_url + '?v=' + Date.now();
        }
    } catch { showToast('Upload failed.', 'error'); }
    currentReplaceTestimId = null;
}

// ══════════════════════════════════════════════════════════════
// TEAM MEMBERS — add, inline edit, reorder, photo replace
// ══════════════════════════════════════════════════════════════

function previewTeamPhoto(input) {
    if (!input.files[0]) return;
    const url = URL.createObjectURL(input.files[0]);
    const drop = document.getElementById('teamPhotoDrop');
    document.getElementById('teamPhotoPlaceholder').style.display = 'none';
    let img = drop.querySelector('img.preview');
    if (!img) { img = document.createElement('img'); img.className = 'preview'; img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;'; drop.appendChild(img); }
    img.src = url;
    drop.style.borderColor = '#005DDB';
    drop.style.borderStyle = 'solid';
}

async function submitAddTeamMember() {
    const name     = document.getElementById('tmName').value.trim();
    const position = document.getElementById('tmPosition').value.trim();
    const bio      = document.getElementById('tmBio').value.trim();
    const email    = document.getElementById('tmEmail').value.trim();
    const phone    = document.getElementById('tmPhone').value.trim();
    const photoInput = document.getElementById('teamPhotoInput');
    const progress = document.getElementById('teamAddProgress');
    const result   = document.getElementById('teamAddResult');

    if (!name)     { showToast('Full name is required.', 'error'); return; }
    if (!position) { showToast('Position is required.', 'error'); return; }

    progress.textContent = 'Saving…';
    const fd = new FormData();
    fd.append('action', 'add_team_member');
    fd.append('name', name);
    fd.append('position', position);
    fd.append('bio', bio);
    fd.append('email', email);
    fd.append('phone', phone);
    if (photoInput.files[0]) fd.append('photo', photoInput.files[0]);

    try {
        const res = await fetch('api.php', {method: 'POST', body: fd});
        const data = await res.json();
        progress.textContent = '';
        result.innerHTML = `<div class="alert alert-${data.success?'success':'error'}" style="margin-bottom:.75rem;">${data.message}</div>`;
        if (data.success) setTimeout(() => location.reload(), 800);
    } catch { progress.textContent = ''; showToast('Error saving.', 'error'); }
}

function inlineEditTeamField(el, id, field, isTextarea = false) {
    if (el.querySelector('input,textarea')) return;
    const original = el.dataset.rawValue ?? el.textContent.trim();
    const isPlaceholder = el.querySelector?.('[style*="color:#c0cfe0"]') || el.querySelector?.('[style*="font-style:italic"]');
    const currentValue = isPlaceholder ? '' : original;

    el.style.borderBottomColor = '#005DDB';

    const input = isTextarea ? document.createElement('textarea') : document.createElement('input');
    if (!isTextarea) input.type = 'email' === field ? 'email' : 'tel' === field ? 'tel' : 'text';
    input.value = currentValue;
    input.style.cssText = 'width:100%;border:none;outline:none;background:transparent;font:inherit;color:inherit;padding:0;resize:vertical;';

    async function save() {
        const val = input.value.trim();
        el.style.borderBottomColor = 'transparent';
        // Restore display
        if (!val && ['bio','email','phone'].includes(field)) {
            const placeholder = {'bio':'Add a bio…','email':'Add email…','phone':'Add phone…'}[field];
            el.innerHTML = `<span style="color:#c0cfe0;font-style:italic;">${placeholder}</span>`;
            if (field === 'email') el.style.color = '#c0cfe0';
            if (field === 'phone') el.style.color = '#c0cfe0';
        } else {
            el.textContent = val || original;
            if (field === 'email') el.style.color = val ? '#005BA1' : '#c0cfe0';
            if (field === 'phone') el.style.color = val ? '#001F5C' : '#c0cfe0';
        }
        if (val === currentValue) return;
        const fd = new FormData();
        fd.append('action', 'update_team_member');
        fd.append('id', id);
        fd.append('field', field);
        fd.append('value', val);
        try {
            const res = await fetch('api.php', {method: 'POST', body: fd});
            const data = await res.json();
            showToast(data.message, data.success ? 'success' : 'error');
        } catch { showToast('Error saving.', 'error'); }
    }

    input.addEventListener('blur', save);
    input.addEventListener('keydown', e => {
        if (!isTextarea && e.key === 'Enter') { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { input.value = currentValue; input.blur(); }
    });
    el.textContent = '';
    el.appendChild(input);
    input.focus();
    if (!isTextarea) input.select();
}

async function moveTeamMember(id, direction) {
    const grid = document.getElementById('teamGrid');
    if (!grid) return;
    const cards = Array.from(grid.querySelectorAll('.team-admin-card'));
    const idx = cards.findIndex(c => +c.dataset.id === id);
    if (idx === -1) return;
    const targetIdx = idx + direction;
    if (targetIdx < 0 || targetIdx >= cards.length) return;
    const cardA = cards[idx], cardB = cards[targetIdx];
    const orderA = +cardA.dataset.order, orderB = +cardB.dataset.order;
    cardA.dataset.order = orderB; cardB.dataset.order = orderA;
    direction === 1 ? grid.insertBefore(cardB, cardA) : grid.insertBefore(cardA, cardB);
    await Promise.all([
        teamApiUpdate(id, 'sort_order', orderB),
        teamApiUpdate(+cardB.dataset.id, 'sort_order', orderA),
    ]);
}

async function toggleTeamMember(id, checked, el) {
    const card = el.closest('.team-admin-card');
    const fd = new FormData();
    fd.append('action', 'update_team_member');
    fd.append('id', id);
    fd.append('field', 'is_active');
    fd.append('value', checked ? 1 : 0);
    try {
        const res = await fetch('api.php', {method: 'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success && card) card.classList.toggle('team-inactive', !checked);
        else if (!data.success) el.checked = !checked;
    } catch { showToast('Error.', 'error'); el.checked = !checked; }
}

async function deleteTeamMember(id) {
    if (!confirm('Delete this team member? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_team_member');
    fd.append('id', id);
    try {
        const res = await fetch('api.php', {method: 'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) {
            const card = document.querySelector(`.team-admin-card[data-id="${id}"]`);
            if (card) { card.style.opacity='0'; card.style.transition='opacity .4s'; setTimeout(()=>card.remove(),400); }
        }
    } catch { showToast('Error.', 'error'); }
}

let currentReplaceTeamId = null;

function replaceTeamPhoto(id) {
    currentReplaceTeamId = id;
    const input = document.getElementById('teamReplaceInput');
    input.value = '';
    input.click();
}

async function submitReplaceTeamPhoto(input) {
    if (!input.files[0] || !currentReplaceTeamId) return;
    const card = document.querySelector(`.team-admin-card[data-id="${currentReplaceTeamId}"]`);
    const fd = new FormData();
    fd.append('action', 'replace_team_member_photo');
    fd.append('id', currentReplaceTeamId);
    fd.append('photo', input.files[0]);
    showToast('Uploading photo…', 'success');
    try {
        const res = await fetch('api.php', {method: 'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success && card) {
            const wrap = card.querySelector('.team-photo-wrap');
            if (wrap) {
                let img = wrap.querySelector('img:not(.team-photo-overlay *)');
                if (!img) {
                    wrap.innerHTML = `<img src="" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.3);cursor:pointer;">` + wrap.innerHTML;
                    img = wrap.querySelector('img');
                }
                img.src = data.photo_url + '?v=' + Date.now();
            }
        }
    } catch { showToast('Upload failed.', 'error'); }
    currentReplaceTeamId = null;
}

async function teamApiUpdate(id, field, value) {
    const fd = new FormData();
    fd.append('action', 'update_team_member');
    fd.append('id', id);
    fd.append('field', field);
    fd.append('value', value);
    try {
        const res = await fetch('api.php', {method: 'POST', body: fd});
        const data = await res.json();
        if (!data.success) showToast(data.message, 'error');
    } catch { showToast('Error saving.', 'error'); }
}

const inlineNotifForm = document.getElementById('inlineNotifForm');
if (inlineNotifForm) {
    inlineNotifForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('inlineNotifBtn');
        const result = document.getElementById('inlineNotifResult');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        const fd = new FormData(this);
        fd.append('action', 'add_notification');
        try {
            const res = await fetch('api.php', {method:'POST', body: fd});
            const data = await res.json();
            result.innerHTML = `<div class="alert alert-${data.success?'success':'error'}" style="margin-bottom:1rem;">${data.message}</div>`;
            if (data.success) setTimeout(() => location.reload(), 700);
        } catch { result.innerHTML = '<div class="alert alert-error">Error.</div>'; }
        finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus"></i> Add Notification'; }
    });
}

// Expand/collapse inline bank approval panel
function toggleBankApprovalForm(id) {
    const row = document.getElementById('bank-approval-row-' + id);
    if (!row) return;
    row.style.display = row.style.display === 'none' ? '' : 'none';
}

const inlineSetAllTimesForm = document.getElementById('inlineSetAllTimesForm');
if (inlineSetAllTimesForm) {
    inlineSetAllTimesForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('inlineSetAllTimesBtn');
        const result = document.getElementById('inlineSetAllTimesResult');
        const startRaw = this.start_time.value;
        const endRaw = this.end_time.value;
        if (!startRaw || !endRaw) {
            result.innerHTML = '<div class="alert alert-error" style="margin-bottom:1rem;">Both start and end times are required.</div>';
            return;
        }
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
        const fd = new FormData(this);
        fd.append('action', 'set_all_auction_times');
        fd.set('start_time', startRaw.replace('T',' ') + ':00');
        fd.set('end_time',   endRaw.replace('T',' ') + ':00');
        try {
            const res = await fetch('api.php', {method:'POST', body: fd});
            const data = await res.json();
            result.innerHTML = `<div class="alert alert-${data.success?'success':'error'}" style="margin-bottom:1rem;">${data.message}</div>`;
            if (data.success) setTimeout(() => location.reload(), 900);
        } catch { result.innerHTML = '<div class="alert alert-error" style="margin-bottom:1rem;">Error.</div>'; }
        finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-clock"></i> Update Times'; }
    });
}

// Inline Bulk Email (Users tab)
const inlineBulkEmailForm = document.getElementById('inlineBulkEmailForm');
const inlineBulkConfirm = document.getElementById('inlineBulkConfirm');
const inlineBulkEmailBtn = document.getElementById('inlineBulkEmailBtn');
if (inlineBulkConfirm && inlineBulkEmailBtn) {
    inlineBulkConfirm.addEventListener('change', function() {
        inlineBulkEmailBtn.disabled = !this.checked;
    });
}
if (inlineBulkEmailForm) {
    inlineBulkEmailForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const result = document.getElementById('inlineBulkEmailResult');
        const btn = inlineBulkEmailBtn;
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        const fd = new FormData(this);
        fd.append('action', 'send_bulk_email');
        try {
            const res = await fetch('api.php', {method:'POST', body: fd});
            const data = await res.json();
            result.innerHTML = `<div class="alert alert-${data.success?'success':'error'}" style="margin-bottom:1rem;">${data.message}</div>`;
            if (data.success) this.reset();
        } catch { result.innerHTML = '<div class="alert alert-error" style="margin-bottom:1rem;">Error.</div>'; }
        finally { btn.disabled = !inlineBulkConfirm?.checked; btn.innerHTML = '<i class="fas fa-broadcast-tower"></i> Send Bulk Email'; }
    });
}

// Expand/collapse per-user inline panels
function toggleUserPanel(userId, kind) {
    const row = document.getElementById('user-' + kind + '-row-' + userId);
    if (!row) return;
    const isOpen = row.style.display !== 'none' && row.style.display !== '';
    // Close any other open panels for this user
    ['email','reset'].forEach(k => {
        const other = document.getElementById('user-' + k + '-row-' + userId);
        if (other) other.style.display = 'none';
    });
    row.style.display = isOpen ? 'none' : '';
}

// Inline per-user Send Email forms
document.querySelectorAll('.inline-user-email-form').forEach(form => {
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const userId = this.dataset.userId;
        const result = this.querySelector('.inline-user-email-result');
        const btn = this.querySelector('button[type=submit]');
        const origHtml = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        const fd = new FormData(this);
        fd.append('action', 'send_email');
        fd.append('user_id', userId);
        fd.append('format', 'plain');
        try {
            const res = await fetch('api.php', {method:'POST', body: fd});
            const data = await res.json();
            result.innerHTML = `<div class="alert alert-${data.success?'success':'error'}" style="margin:.5rem 0;">${data.message}</div>`;
            if (data.success) this.reset();
        } catch { result.innerHTML = '<div class="alert alert-error" style="margin:.5rem 0;">Error.</div>'; }
        finally { btn.disabled = false; btn.innerHTML = origHtml; }
    });
});

// Inline per-user Reset Password forms
document.querySelectorAll('.inline-user-reset-form').forEach(form => {
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const userId = this.dataset.userId;
        const userName = this.dataset.userName;
        if (!confirm('Reset password for ' + userName + '?')) return;
        const result = this.querySelector('.inline-user-reset-result');
        const btn = this.querySelector('button[type=submit]');
        const origHtml = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
        const fd = new FormData();
        fd.append('action', 'admin_reset_password');
        fd.append('user_id', userId);
        fd.append('send_email', this.send_email_notice.checked ? '1' : '0');
        try {
            const res = await fetch('api.php', {method:'POST', body: fd});
            const data = await res.json();
            let msg = data.message || (data.success ? 'Password reset.' : 'Failed.');
            if (data.success && data.new_password) msg += ' New password: <code>' + data.new_password + '</code>';
            result.innerHTML = `<div class="alert alert-${data.success?'success':'error'}" style="margin:.5rem 0;">${msg}</div>`;
        } catch { result.innerHTML = '<div class="alert alert-error" style="margin:.5rem 0;">Error.</div>'; }
        finally { btn.disabled = false; btn.innerHTML = origHtml; }
    });
});

// ══════════════════════════════════════════════════════════════
// BULK AUCTION MANAGEMENT
// ══════════════════════════════════════════════════════════════

// Select all auction checkboxes
window.toggleAuctionSelectAll = function(master) {
    document.querySelectorAll('.auction-select-cb').forEach(cb => cb.checked = master.checked);
    updateAuctionSelectedCount();
};

document.querySelectorAll('.auction-select-cb').forEach(cb => cb.addEventListener('change', updateAuctionSelectedCount));

function updateAuctionSelectedCount() {
    const count = document.querySelectorAll('.auction-select-cb:checked').length;
    const el = document.getElementById('auctionSelectedCount');
    if (el) el.textContent = count + ' selected';
    const bulkBar = document.getElementById('auctionBulkBar');
    if (bulkBar) bulkBar.style.display = count > 0 ? 'flex' : 'none';
}

// Toggle inline bulk panels (mutually exclusive)
const AUCTION_BULK_PANELS = {
    times:  'auctionBulkTimesPanel',
    event:  'auctionBulkEventPanel',
    sold:   'auctionBulkSoldPanel',
    relist: 'auctionBulkRelistPanel',
};
window.toggleAuctionBulkPanel = function(which, forceClose) {
    const target = document.getElementById(AUCTION_BULK_PANELS[which]);
    if (!target) return;
    Object.values(AUCTION_BULK_PANELS).forEach(id => {
        const p = document.getElementById(id);
        if (p && p !== target) p.style.display = 'none';
    });
    if (forceClose) { target.style.display = 'none'; return; }
    const opening = target.style.display === 'none';
    target.style.display = opening ? '' : 'none';
    if (!opening) return;
    const boxes = selectedAuctionBoxes();
    const ids   = boxes.map(cb => parseInt(cb.value));
    if (which === 'times') {
        document.getElementById('bulkAuctionCount').textContent = ids.length;
    } else if (which === 'event') {
        document.getElementById('assignEventAuctionIds').value = JSON.stringify(ids);
        document.getElementById('bulkAssignInfo').innerHTML = ids.length
            ? 'Adding <strong>' + ids.length + ' vehicle(s)</strong> to an event.'
            : 'No vehicles selected.';
    } else if (which === 'sold') {
        fillBulkSoldRows(boxes);
    } else if (which === 'relist') {
        const sold = boxes.filter(cb => cb.dataset.sold === '1').length;
        document.getElementById('bulkRelistResult').innerHTML = '';
        document.getElementById('bulkRelistInfo').innerHTML =
            '<strong>' + (ids.length - sold) + ' lot(s)</strong> will be relisted' +
            (sold ? ' (' + sold + ' sold lot(s) skipped).' : '.');
    }
};

async function submitBulkAuctionUpdate() {
    const ids = [...document.querySelectorAll('.auction-select-cb:checked')].map(cb => parseInt(cb.value));
    const result = document.getElementById('bulkAuctionResult');
    const setMsg = (ok, msg) => result.innerHTML = `<div class="alert alert-${ok?'success':'error'}" style="margin-bottom:.75rem;">${msg}</div>`;
    if (!ids.length) { setMsg(false, 'No auctions selected.'); return; }

    const startTime = document.getElementById('bulkAucStartTime').value;
    const endTime   = document.getElementById('bulkAucEndTime').value;
    const status    = document.getElementById('bulkAucStatus').value;

    if (!startTime && !endTime && !status) { setMsg(false, 'Set at least one field to update.'); return; }
    if (!confirm(`Apply changes to ${ids.length} auction(s)?`)) return;

    let messages = [];
    let anyFail = false;

    if (startTime || endTime) {
        const fd = new FormData();
        fd.append('action', 'bulk_update_auction_times');
        fd.append('auction_ids', JSON.stringify(ids));
        fd.append('start_time', startTime ? startTime.replace('T',' ') + ':00' : '');
        fd.append('end_time',   endTime   ? endTime.replace('T',' ') + ':00'   : '');
        try {
            const res = await fetch('api.php', {method:'POST', body: fd});
            const data = await res.json();
            messages.push(data.message);
            if (!data.success) anyFail = true;
        } catch { messages.push('Error updating times.'); anyFail = true; }
    }

    if (status) {
        const fd = new FormData();
        fd.append('action', 'bulk_update_auction_status');
        fd.append('auction_ids', JSON.stringify(ids));
        fd.append('status', status);
        try {
            const res = await fetch('api.php', {method:'POST', body: fd});
            const data = await res.json();
            messages.push(data.message);
            if (!data.success) anyFail = true;
        } catch { messages.push('Error updating status.'); anyFail = true; }
    }

    setMsg(!anyFail, messages.join(' · '));
    if (!anyFail) setTimeout(() => location.reload(), 1200);
}

// ── Make All Vehicles Live ────────────────────────────────
async function makeAllVehiclesLive() {
    const result = document.getElementById('makeAllLiveResult');
    const setMsg = (ok, msg) => result.innerHTML =
        `<div class="alert alert-${ok?'success':'error'}" style="margin-bottom:.75rem;">${msg}</div>`;

    const filter    = document.getElementById('makeAllLiveFilter').value;
    const startRaw  = document.getElementById('makeAllLiveStart').value;
    const endRaw    = document.getElementById('makeAllLiveEnd').value;

    const filterLabel = filter === 'all' ? 'ALL non-closed vehicles' : 'all UPCOMING vehicles';
    if (!confirm(`Set ${filterLabel} to LIVE (active)?\n\nThis will make them visible and open for bidding on the website immediately.`)) return;

    const btn = document.querySelector('button[onclick="makeAllVehiclesLive()"]');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Publishing…';

    const fd = new FormData();
    fd.append('action',        'set_all_auction_status');
    fd.append('status',        'active');
    fd.append('status_filter', filter);
    if (startRaw) fd.append('start_time', startRaw.replace('T',' ') + ':00');
    if (endRaw)   fd.append('end_time',   endRaw.replace('T',' ')   + ':00');

    try {
        const res  = await fetch('api.php', {method:'POST', body: fd});
        const data = await res.json();
        setMsg(data.success, data.message);
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 1400);
        }
    } catch(e) {
        setMsg(false, 'Network error. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

// Single-row shortcut: prefill the inline Add-to-Event panel with one vehicle
window.singleAssignToEvent = function(auctionId, name) {
    document.getElementById('assignEventAuctionIds').value = JSON.stringify([auctionId]);
    document.getElementById('bulkAssignInfo').innerHTML = 'Adding <strong>' + name + '</strong> to an event.';
    const panel = document.getElementById('auctionBulkEventPanel');
    const timesPanel = document.getElementById('auctionBulkTimesPanel');
    if (timesPanel) timesPanel.style.display = 'none';
    if (panel) { panel.style.display = ''; panel.scrollIntoView({behavior:'smooth', block:'start'}); }
};

// ── Assign to Event (inline panel) ──────────────────────────
async function submitAssignEvent() {
    const result = document.getElementById('bulkAssignResult');
    const setMsg = (ok, msg) => result.innerHTML = `<div class="alert alert-${ok?'success':'error'}" style="margin-bottom:.75rem;">${msg}</div>`;
    const eventId = document.getElementById('assignEventSelect').value;
    if (!eventId) { setMsg(false, 'Please select an event.'); return; }
    const ids = JSON.parse(document.getElementById('assignEventAuctionIds').value || '[]');
    if (!ids.length) { setMsg(false, 'No vehicles selected.'); return; }

    const fd = new FormData();
    fd.append('action', 'event_add_vehicles');
    fd.append('event_id', eventId);
    fd.append('vehicle_ids', JSON.stringify(ids));
    try {
        const res = await fetch('api.php', {method:'POST', body: fd});
        const data = await res.json();
        setMsg(!!data.success, data.message || 'Done.');
        if (data.success) setTimeout(() => location.reload(), 1200);
    } catch { setMsg(false, 'Error assigning to event.'); }
}

// ── Sales: mark sold / undo / bulk sold / relist / export / delete ──
function selectedAuctionBoxes() {
    return [...document.querySelectorAll('.auction-select-cb:checked')];
}
function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function randFmt(n) {
    // Same shape as PHP formatRand(): R153,000.00
    return 'R' + Number(n || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
async function postApi(fields) {
    const fd = new FormData();
    Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch('api.php', {method: 'POST', body: fd});
    return res.json();
}

// Status dropdown: "Sold" opens the sale popup instead of saving with no price
window.onAuctionStatusChange = function(id, sel) {
    if (sel.value === 'sold') {
        const card = document.getElementById('auction-row-' + id);
        const cb   = card ? card.querySelector('.auction-select-cb') : null;
        sel.value  = sel.dataset.prev || 'closed';
        openMarkSoldModal(id, {name: cb ? cb.dataset.name : '', top: cb ? parseFloat(cb.dataset.top) : 0});
        return;
    }
    if (sel.dataset.prev === 'sold' &&
        !confirm('Changing the status removes the sale (final price, date and buyer). Continue?')) {
        sel.value = 'sold';
        return;
    }
    updateAuctionStatus(id, sel.value, sel);
    sel.dataset.prev = sel.value;
};

window.openMarkSoldModal = function(id, info) {
    info = info || {};
    const editing = !!info.sold || (info.price || 0) > 0;
    document.getElementById('markSoldModal').dataset.editing = editing ? '1' : '0';
    document.getElementById('markSoldAuctionId').value = id;
    document.getElementById('markSoldVehicleName').textContent = (info.name || '') + '  (Lot #' + id + ')';
    document.getElementById('markSoldTitle').textContent = editing ? 'Edit Sale' : 'Mark as Sold';
    document.getElementById('markSoldPrice').value = editing ? (info.price > 0 ? info.price : '') : (info.top > 0 ? info.top : '');
    document.getElementById('markSoldTopHint').textContent = info.top > 0
        ? 'Top bid on this lot: ' + randFmt(info.top) : 'No bids on this lot. Enter the agreed price.';
    document.getElementById('markSoldDate').value = info.date || new Date().toISOString().slice(0, 10);
    document.getElementById('markSoldBuyerSearch').value = info.buyer || '';
    document.getElementById('markSoldBuyerId').value = '';
    document.getElementById('markSoldBuyerHint').textContent = 'Not linked to a bidder account.';
    document.getElementById('markSoldChannel').value = info.channel || '';
    document.getElementById('markSoldError').style.display = 'none';
    openModal('markSoldModal');
    setTimeout(() => document.getElementById('markSoldPrice').focus(), 50);
};

// Buyer box: suggests registered bidders; anything typed counts as a walk-in name
(function() {
    const input = document.getElementById('markSoldBuyerSearch');
    const drop  = document.getElementById('markSoldBuyerDropdown');
    if (!input) return;
    let timer;
    input.addEventListener('input', function() {
        document.getElementById('markSoldBuyerId').value = '';
        document.getElementById('markSoldBuyerHint').textContent = 'Not linked to a bidder account.';
        clearTimeout(timer);
        const q = this.value.trim();
        if (q.length < 2) { drop.style.display = 'none'; return; }
        timer = setTimeout(() => {
            fetch('api.php?action=search_bidders&q=' + encodeURIComponent(q))
                .then(r => r.json()).then(d => {
                    if (!d.success || !d.users || !d.users.length) { drop.style.display = 'none'; return; }
                    drop.innerHTML = d.users.map(u =>
                        `<div class="autocomplete-item" data-id="${u.id}" data-name="${escHtml(u.full_name)}" data-num="${escHtml(u.bidder_number || '')}">
                            <strong>${escHtml(u.full_name)}</strong>${u.floor == 1 ? ' <span class="floor-badge">Floor</span>' : ''}
                            <small class="text-muted">${u.bidder_number ? ' · ' + escHtml(u.bidder_number) : ''}${u.phone ? ' · ' + escHtml(u.phone) : ''}${u.email ? ' · ' + escHtml(u.email) : ''}</small>
                        </div>`).join('');
                    drop.style.display = 'block';
                });
        }, 300);
    });
    drop.addEventListener('click', e => {
        const item = e.target.closest('.autocomplete-item');
        if (!item) return;
        input.value = item.dataset.name;
        document.getElementById('markSoldBuyerId').value = item.dataset.id;
        document.getElementById('markSoldBuyerHint').textContent =
            'Linked to bidder account' + (item.dataset.num ? ' ' + item.dataset.num : '') + '.';
        drop.style.display = 'none';
    });
    document.addEventListener('click', e => {
        if (!input.contains(e.target) && !drop.contains(e.target)) drop.style.display = 'none';
    });
})();

window.submitMarkSold = async function() {
    const id    = document.getElementById('markSoldAuctionId').value;
    const price = parseFloat(document.getElementById('markSoldPrice').value);
    const editing = document.getElementById('markSoldModal').dataset.editing === '1';
    const errBox = document.getElementById('markSoldError');
    const showErr = msg => { errBox.textContent = msg; errBox.style.display = ''; };
    errBox.style.display = 'none';
    if (!(price > 0) && !editing) { showErr('Enter the final selling price.'); return; }
    const btn = document.getElementById('markSoldSubmit');
    btn.disabled = true;
    try {
        const res = await fetch('api.php', {method: 'POST', body: (() => {
            const fd = new FormData();
            Object.entries({
                action: 'mark_sold', auction_id: id, final_price: price > 0 ? price : '',
                sold_date: document.getElementById('markSoldDate').value,
                sale_channel: document.getElementById('markSoldChannel').value,
                buyer_user_id: document.getElementById('markSoldBuyerId').value,
                buyer_name: document.getElementById('markSoldBuyerSearch').value.trim(),
            }).forEach(([k, v]) => fd.append(k, v));
            return fd;
        })()});
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); }
        catch { showErr('The server answered unexpectedly (HTTP ' + res.status + '). Please send a screenshot of this: ' + text.slice(0, 160)); return; }
        if (!data.success) { showErr(data.message || 'Could not save the sale.'); return; }
        showToast(data.message, 'success');
        closeModal('markSoldModal');
        setTimeout(() => location.reload(), 900);
    } catch (e) { showErr('Could not reach the server: ' + e.message); }
    finally { btn.disabled = false; }
};

window.spreadSoldDates = async function(day, n) {
    if (!confirm(`Give the ${n} lots marked sold on ${day} varied dates?\n\nEach gets a weekday between when it was listed (at most 2 years back) and ${day}. You can undo this.`)) return;
    try {
        const data = await postApi({action: 'spread_sold_dates', day: day, years: 2});
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) setTimeout(() => location.reload(), 1400);
    } catch { showToast('Error spreading the dates.', 'error'); }
};

window.undoSpreadDates = async function(batch) {
    if (!confirm('Put every spread sold date back to what it was?')) return;
    try {
        const data = await postApi({action: 'undo_spread_dates', batch: batch});
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) setTimeout(() => location.reload(), 1200);
    } catch { showToast('Error undoing.', 'error'); }
};

window.undoSold = async function(id, name) {
    if (!confirm('Remove the sale for "' + name + '"?\n\nThe final price, sold date and buyer are cleared and the lot goes back to Closed (unsold).')) return;
    try {
        const data = await postApi({action: 'undo_sold', auction_id: id});
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) setTimeout(() => location.reload(), 900);
    } catch { showToast('Error removing the sale.', 'error'); }
};

function fillBulkSoldRows(boxes) {
    const rows  = boxes.filter(cb => cb.dataset.sold !== '1');
    const tbody = document.getElementById('bulkSoldRows');
    document.getElementById('bulkSoldResult').innerHTML = rows.length < boxes.length
        ? `<div class="alert alert-info" style="margin-bottom:.75rem;">${boxes.length - rows.length} lot(s) already sold, left out.</div>` : '';
    tbody.innerHTML = rows.length ? rows.map(cb => {
        const top = parseFloat(cb.dataset.top) || 0;
        return `<tr>
            <td>#${cb.value}</td>
            <td>${escHtml(cb.dataset.name)}${top ? `<br><small class="text-muted">Top bid ${randFmt(top)}</small>` : '<br><small style="color:#b26a00">No bids</small>'}</td>
            <td><input type="number" class="form-control bulk-sold-price" data-id="${cb.value}" min="1" step="0.01" value="${top > 0 ? top : ''}"></td>
        </tr>`;
    }).join('') : '<tr><td colspan="3" class="text-muted">No unsold lots selected.</td></tr>';
    tbody.querySelectorAll('.bulk-sold-price').forEach(i => i.addEventListener('input', updateBulkSoldTotal));
    updateBulkSoldTotal();
}
function updateBulkSoldTotal() {
    const total = [...document.querySelectorAll('.bulk-sold-price')].reduce((s, i) => s + (parseFloat(i.value) || 0), 0);
    document.getElementById('bulkSoldTotal').textContent = randFmt(total);
}

window.submitBulkMarkSold = async function() {
    const inputs = [...document.querySelectorAll('.bulk-sold-price')];
    const result = document.getElementById('bulkSoldResult');
    if (!inputs.length) return;
    const missing = inputs.filter(i => !(parseFloat(i.value) > 0));
    if (missing.length) {
        missing.forEach(i => i.style.borderColor = '#c8102e');
        result.innerHTML = `<div class="alert alert-error" style="margin-bottom:.75rem;">Enter a price for every lot (${missing.length} missing).</div>`;
        return;
    }
    const items = inputs.map(i => ({id: parseInt(i.dataset.id), price: parseFloat(i.value)}));
    if (!confirm(`Mark ${items.length} lot(s) as sold for a total of ${document.getElementById('bulkSoldTotal').textContent}?`)) return;
    try {
        const data = await postApi({action: 'bulk_mark_sold', items: JSON.stringify(items),
                                    sale_channel: document.getElementById('bulkSoldChannel').value});
        result.innerHTML = `<div class="alert alert-${data.success ? 'success' : 'error'}" style="margin-bottom:.75rem;">${escHtml(data.message)}</div>`;
        if (data.success) setTimeout(() => location.reload(), 1200);
    } catch { result.innerHTML = '<div class="alert alert-error">Error saving the sales.</div>'; }
};

window.submitBulkRelist = async function() {
    const ids    = selectedAuctionBoxes().filter(cb => cb.dataset.sold !== '1').map(cb => parseInt(cb.value));
    const result = document.getElementById('bulkRelistResult');
    const start  = document.getElementById('bulkRelistStart').value;
    const end    = document.getElementById('bulkRelistEnd').value;
    const say = (ok, msg) => result.innerHTML = `<div class="alert alert-${ok ? 'success' : 'error'}" style="margin-bottom:.75rem;">${escHtml(msg)}</div>`;
    if (!ids.length) { say(false, 'No unsold lots selected.'); return; }
    if (!start || !end) { say(false, 'Set both a start and an end time.'); return; }
    if (end <= start) { say(false, 'End time must be after start time.'); return; }
    if (!confirm(`Relist ${ids.length} lot(s)?\n\nTheir old bids are cleared (a copy is kept in the change log) and prices reset to the starting price.`)) return;
    try {
        const data = await postApi({
            action: 'bulk_relist', auction_ids: JSON.stringify(ids),
            start_time: start.replace('T', ' ') + ':00', end_time: end.replace('T', ' ') + ':00',
        });
        say(!!data.success, data.message);
        if (data.success) setTimeout(() => location.reload(), 1400);
    } catch { say(false, 'Error relisting lots.'); }
};

window.exportSelectedAuctions = function() {
    const ids = selectedAuctionBoxes().map(cb => cb.value);
    if (!ids.length) { showToast('Select at least one lot.', 'error'); return; }
    window.location.href = 'api.php?action=export_auctions_csv&ids=' + ids.join(',');
};

window.bulkDeleteAuctions = async function() {
    const boxes = selectedAuctionBoxes();
    if (!boxes.length) return;
    const sold = boxes.filter(cb => cb.dataset.sold === '1').length;
    let msg = `Permanently delete ${boxes.length} lot(s), with all their bids and photos?`;
    if (sold) msg += `\n\n${sold} of them are SOLD — their sale records will be lost too.`;
    if (!confirm(msg)) return;
    if (prompt(`Type DELETE to confirm deleting ${boxes.length} lot(s):`) !== 'DELETE') {
        showToast('Delete cancelled.', 'info');
        return;
    }
    try {
        const data = await postApi({action: 'bulk_delete_auctions', auction_ids: JSON.stringify(boxes.map(cb => parseInt(cb.value)))});
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) setTimeout(() => location.reload(), 1000);
    } catch { showToast('Error deleting lots.', 'error'); }
};

// ── Assign Winner ────────────────────────────────────────────
window.openAssignWinnerModal = function(auctionId, name) {
    document.getElementById('assignWinnerAuctionId').value = auctionId;
    document.getElementById('assignWinnerVehicleName').textContent = name;
    document.getElementById('winnerBidderSearch').value = '';
    document.getElementById('winnerBidderPreview').style.display = 'none';
    document.getElementById('winnerBidderId').value = '';
    document.getElementById('winnerFinalPrice').value = '';
    openModal('assignWinnerModal');
};

// Bidder search for assign winner
(function(){
    const input = document.getElementById('winnerBidderSearch');
    const dropdown = document.getElementById('winnerBidderDropdown');
    if (!input) return;
    let timer;
    input.addEventListener('input', function(){
        clearTimeout(timer);
        if (this.value.length < 2) { dropdown.style.display='none'; return; }
        timer = setTimeout(() => {
            fetch('api.php?action=search_bidders&q=' + encodeURIComponent(this.value))
                .then(r => r.json()).then(d => {
                    if (!d.success || !d.users.length) { dropdown.innerHTML='<div class="autocomplete-item text-muted">No bidders found.</div>'; dropdown.style.display='block'; return; }
                    dropdown.innerHTML = d.users.map(u =>
                        `<div class="autocomplete-item" onclick="selectWinnerBidder(${u.id},'${(u.full_name||'').replace(/'/g,"\\'")}','${u.email||''}','${u.bidder_number||''}')">
                            <strong>${u.full_name}</strong>
                            <small class="text-muted"> ${u.bidder_number ? '· '+u.bidder_number : ''} · ${u.email}</small>
                        </div>`
                    ).join('');
                    dropdown.style.display='block';
                });
        }, 300);
    });
    document.addEventListener('click', e => {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) dropdown.style.display='none';
    });
})();

window.selectWinnerBidder = function(id, name, email, num) {
    document.getElementById('winnerBidderId').value = id;
    document.getElementById('winnerBidderInfo').innerHTML = '<strong>' + name + '</strong> ' + (num ? '<span class="badge badge-verified-bidder">' + num + '</span>' : '') + ' <small class="text-muted">' + email + '</small>';
    document.getElementById('winnerBidderPreview').style.display = 'block';
    document.getElementById('winnerBidderSearch').value = name;
    document.getElementById('winnerBidderDropdown').style.display = 'none';
};

async function submitAssignWinner() {
    const auctionId = document.getElementById('assignWinnerAuctionId').value;
    const userId = document.getElementById('winnerBidderId').value;
    const finalPrice = document.getElementById('winnerFinalPrice').value;
    if (!userId) { showToast('Please select a bidder.','error'); return; }
    if (!finalPrice || parseFloat(finalPrice) <= 0) { showToast('Please enter the final price.','error'); return; }
    if (!confirm('Assign this bidder as the winner? This will mark the vehicle as sold.')) return;

    const fd = new FormData();
    fd.append('action', 'assign_winner');
    fd.append('auction_id', auctionId);
    fd.append('user_id', userId);
    fd.append('final_price', finalPrice);
    try {
        const res = await fetch('api.php', {method:'POST', body: fd});
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) { closeModal('assignWinnerModal'); setTimeout(() => location.reload(), 1200); }
    } catch { showToast('Error assigning winner.','error'); }
}
</script>

<!-- ═══════════════════════════════════════════════════════════
     EVENTS TAB JS
═══════════════════════════════════════════════════════════════ -->
<?php if ($activeTab === 'events'): ?>
<script>
(function(){
    const EVENT_ID = <?= $editEventId ?: 0 ?>;

    // ── Generic API helper ────────────────────────────────────
    async function apiPost(data) {
        const fd = data instanceof FormData ? data : (() => { const f = new FormData(); Object.entries(data).forEach(([k,v]) => f.append(k,v)); return f; })();
        const res = await fetch('api.php', {method:'POST', body: fd});
        return res.json();
    }

    // ── Create event form ─────────────────────────────────────
    const newForm = document.getElementById('newEventForm');
    if (newForm) {
        newForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const result = document.getElementById('newEventResult');
            try {
                const data = await apiPost(new FormData(this));
                result.innerHTML = `<span class="badge badge-${data.success?'active':'closed'}">${data.message}</span>`;
                if (data.success) setTimeout(() => location.href = '?tab=events&edit_event=' + data.event_id, 800);
            } catch { result.innerHTML = '<span class="badge badge-closed">Error</span>'; }
        });
    }

    // ── Edit event form ───────────────────────────────────────
    const editForm = document.getElementById('editEventForm');
    if (editForm) {
        editForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const result = document.getElementById('eventFormResult');
            const btn = document.getElementById('saveEventBtn');
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            try {
                const data = await apiPost(new FormData(this));
                result.innerHTML = `<span class="badge badge-${data.success?'active':'closed'}">${data.message}</span>`;
            } catch { result.innerHTML = '<span class="badge badge-closed">Error</span>'; }
            finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
        });
    }

    // ── Vehicle picker filter ─────────────────────────────────
    window.filterVehiclePicker = function() {
        const q = document.getElementById('vehiclePickerSearch').value.toLowerCase();
        document.querySelectorAll('.vehicle-picker-item').forEach(el => {
            el.style.display = el.dataset.name.includes(q) ? '' : 'none';
        });
    };

    // ── Add selected vehicles ─────────────────────────────────
    window.addSelectedVehicles = async function() {
        const ids = [...document.querySelectorAll('.picker-cb:checked')].map(cb => parseInt(cb.value));
        if (!ids.length) return showToast('Select at least one vehicle.','error');
        try {
            const data = await apiPost({action:'event_add_vehicles', event_id: EVENT_ID, vehicle_ids: JSON.stringify(ids)});
            showToast(data.message, data.success?'success':'error');
            if (data.success) setTimeout(() => location.reload(), 600);
        } catch { showToast('Error adding vehicles.','error'); }
    };

    // ── Remove vehicle from event ─────────────────────────────
    window.removeFromEvent = async function(eventId, auctionId, btn) {
        if (!confirm('Remove this vehicle from the event?')) return;
        try {
            const data = await apiPost({action:'event_remove_vehicle', event_id: eventId, auction_id: auctionId});
            showToast(data.message, data.success?'success':'error');
            if (data.success) btn.closest('tr').remove();
        } catch { showToast('Error.','error'); }
    };

    // ── Toggle hot ────────────────────────────────────────────
    window.toggleHot = async function(eventId, auctionId, btn) {
        try {
            const data = await apiPost({action:'event_toggle_hot', event_id: eventId, auction_id: auctionId});
            showToast(data.message, data.success?'success':'error');
            if (data.success) {
                btn.className = data.is_hot ? 'btn btn-sm btn-warning' : 'btn btn-sm btn-outline';
            }
        } catch { showToast('Error.','error'); }
    };

    // ── Select all toggle ─────────────────────────────────────
    window.toggleSelectAll = function(master) {
        document.querySelectorAll('.ev-select-cb').forEach(cb => cb.checked = master.checked);
        updateSelectedCount();
    };
    document.querySelectorAll('.ev-select-cb').forEach(cb => cb.addEventListener('change', updateSelectedCount));
    function updateSelectedCount() {
        const count = document.querySelectorAll('.ev-select-cb:checked').length;
        const el = document.getElementById('bulkSelectedCount');
        if (el) el.textContent = count + ' selected';
    }

    // ── Bulk update dates/status ──────────────────────────────
    window.bulkUpdateDates = async function(scope) {
        const startTime = document.getElementById('bulkStartTime').value;
        const endTime   = document.getElementById('bulkEndTime').value;
        const status    = document.getElementById('bulkStatus').value;

        if (!startTime && !endTime && !status) return showToast('Set at least one field to update.','error');

        const selectedIds = [...document.querySelectorAll('.ev-select-cb:checked')].map(cb => parseInt(cb.value));
        if (scope === 'selected' && !selectedIds.length) return showToast('Select vehicles first.','error');

        const confirmMsg = scope === 'all'
            ? 'Apply changes to ALL vehicles in this event?'
            : `Apply changes to ${selectedIds.length} selected vehicle(s)?`;
        if (!confirm(confirmMsg)) return;

        try {
            // Update dates
            if (startTime || endTime) {
                const data = await apiPost({
                    action: 'event_bulk_dates', event_id: EVENT_ID,
                    start_time: startTime, end_time: endTime,
                    scope: scope, vehicle_ids: JSON.stringify(selectedIds)
                });
                showToast(data.message, data.success?'success':'error');
            }
            // Update status
            if (status) {
                const ids = scope === 'selected' ? selectedIds :
                    [...document.querySelectorAll('.ev-select-cb')].map(cb => parseInt(cb.value));
                const data = await apiPost({
                    action: 'event_bulk_update', event_id: EVENT_ID,
                    vehicle_ids: JSON.stringify(ids), field: 'status', value: status
                });
                showToast(data.message, data.success?'success':'error');
            }
            setTimeout(() => location.reload(), 1000);
        } catch { showToast('Bulk update error.','error'); }
    };

    // ── Delete event ──────────────────────────────────────────
    window.deleteEvent = async function(id, title) {
        if (!confirm(`Delete event "${title}"? This will unlink all vehicles (vehicles won't be deleted).`)) return;
        try {
            const data = await apiPost({action:'delete_event', event_id: id});
            showToast(data.message, data.success?'success':'error');
            if (data.success) setTimeout(() => location.reload(), 800);
        } catch { showToast('Error.','error'); }
    };
})();
</script>
<?php endif; ?>

<?php if ($activeTab === 'refunds'): ?>
<!-- ════════════════════════════════════════════════════════
     TAB: REFUNDS
════════════════════════════════════════════════════════ -->
<style>
.rf-stats { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.rf-stat { background:#fff; border-radius:10px; border:1px solid var(--border-color,#e0e8f0); padding:1rem 1.5rem; flex:1; min-width:120px; text-align:center; }
.rf-stat .num { font-size:1.8rem; font-weight:800; font-family:'Barlow Condensed',sans-serif; }
.rf-stat .lbl { font-size:.72rem; text-transform:uppercase; letter-spacing:.07em; color:#888; margin-top:.2rem; }
.rf-table { width:100%; border-collapse:collapse; font-size:.87rem; }
.rf-table th { background:#003f73; color:#fff; padding:.75rem 1rem; text-align:left; font-size:.78rem; text-transform:uppercase; letter-spacing:.07em; }
.rf-table td { padding:.75rem 1rem; border-bottom:1px solid #eef2f7; vertical-align:middle; }
.rf-table tr:hover td { background:#f8fbff; }
.rf-table tr:last-child td { border-bottom:none; }
.rf-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.28rem .7rem; border-radius:50px; font-size:.73rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.rf-badge-pending     { background:#fff3cd; color:#856404; }
.rf-badge-under_review{ background:#cce5ff; color:#004085; }
.rf-badge-processed   { background:#d4edda; color:#155724; }
.rf-badge-rejected    { background:#f8d7da; color:#721c24; }
.rf-actions { display:flex; gap:.4rem; flex-wrap:wrap; }
.rf-btn-sm { display:inline-flex; align-items:center; gap:.3rem; padding:.38rem .85rem; border-radius:5px; font-size:.78rem; font-weight:700; cursor:pointer; border:none; font-family:inherit; }
.rf-btn-review   { background:#004085; color:#fff; }
.rf-btn-process  { background:#155724; color:#fff; }
.rf-btn-reject   { background:#721c24; color:#fff; }
.rf-btn-view     { background:transparent; color:#005BA1; border:1.5px solid #005BA1; }
.rf-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9999; align-items:center; justify-content:center; }
.rf-modal-overlay.open { display:flex; }
.rf-modal { background:#fff; border-radius:12px; width:90%; max-width:560px; max-height:88vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.25); }
.rf-modal-header { background:linear-gradient(135deg,#003f73,#005BA1); padding:1.2rem 1.5rem; color:#fff; border-radius:12px 12px 0 0; display:flex; justify-content:space-between; align-items:center; }
.rf-modal-header h4 { margin:0; font-size:1.05rem; font-weight:700; }
.rf-modal-close { background:none; border:none; color:#fff; font-size:1.4rem; cursor:pointer; padding:.2rem .4rem; border-radius:3px; }
.rf-modal-body { padding:1.5rem; }
.rf-modal-footer { padding:1rem 1.5rem; border-top:1px solid #e0e8f0; display:flex; gap:.75rem; justify-content:flex-end; }
.rf-detail-row { display:flex; justify-content:space-between; padding:.45rem 0; border-bottom:1px solid #f0f4f8; font-size:.88rem; }
.rf-detail-row:last-child { border-bottom:none; }
.rf-detail-row .lbl { color:#667; font-weight:600; font-size:.78rem; text-transform:uppercase; letter-spacing:.06em; }
.rf-detail-row .val { font-weight:700; color:#1a1a1a; text-align:right; max-width:220px; word-break:break-word; }
.rf-bank-box { background:#f0f6fc; border:1.5px solid #b8d4ee; border-radius:8px; padding:1rem 1.25rem; margin:.75rem 0; font-size:.88rem; }
</style>

<div class="dashboard-header">
  <div>
    <h1><i class="fas fa-rotate-left"></i> Refund Requests</h1>
    <p class="text-muted">Review and process client EFT refund requests.</p>
  </div>
</div>

<?php
$rfStats = ['pending'=>0,'under_review'=>0,'processed'=>0,'rejected'=>0];
foreach ($refundRequests as $r) { if (isset($rfStats[$r['status']])) $rfStats[$r['status']]++; }
$rfColors = ['pending'=>'#856404','under_review'=>'#004085','processed'=>'#155724','rejected'=>'#721c24'];
?>
<div class="rf-stats">
    <?php foreach ($rfStats as $k => $v): ?>
    <div class="rf-stat">
        <div class="num" style="color:<?= $rfColors[$k] ?>;"><?= $v ?></div>
        <div class="lbl"><?= ucfirst(str_replace('_',' ',$k)) ?></div>
    </div>
    <?php endforeach; ?>
    <div class="rf-stat">
        <div class="num" style="color:#003f73;"><?= count($refundRequests) ?></div>
        <div class="lbl">Total</div>
    </div>
</div>

<div class="card" style="overflow:visible;">
  <?php if (empty($refundRequests)): ?>
  <div style="padding:3rem;text-align:center;color:#888;">
    <i class="fas fa-inbox" style="font-size:2.5rem;margin-bottom:1rem;display:block;opacity:.4;"></i>
    No refund requests yet.
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="rf-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Client</th>
        <th>Reference</th>
        <th>Amount Paid</th>
        <th>Refund Amount</th>
        <th>Bank</th>
        <th>Status</th>
        <th>Date</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($refundRequests as $rf): ?>
    <tr>
      <td style="font-size:.78rem;color:#888;"><?= $rf['id'] ?></td>
      <td>
        <strong><?= e($rf['full_name']) ?></strong><br>
        <span style="font-size:.75rem;color:#888;"><?= e($rf['email']) ?></span>
      </td>
      <td>
        <span style="font-size:.78rem;color:#003f73;font-weight:600;"><?= e($rf['reference_type'] === 'preauction_invoice' ? 'Pre-Auction' : ($rf['reference_type'] === 'registration' ? 'Registration' : 'Other')) ?></span>
        <?php if ($rf['reference_number']): ?>
        <br><span style="font-size:.72rem;color:#888;"><?= e($rf['reference_number']) ?></span>
        <?php endif; ?>
      </td>
      <td><strong>R<?= number_format((float)$rf['amount_paid'],2) ?></strong></td>
      <td><strong style="color:#005BA1;">R<?= number_format((float)$rf['refund_amount'],2) ?></strong></td>
      <td style="font-size:.82rem;"><?= e($rf['bank_name']) ?></td>
      <td><span class="rf-badge rf-badge-<?= $rf['status'] ?>"><?= ucfirst(str_replace('_',' ',$rf['status'])) ?></span></td>
      <td style="font-size:.78rem;color:#888;"><?= safeDate('d M Y', $rf['created_at']) ?></td>
      <td>
        <div class="rf-actions">
          <button class="rf-btn-sm rf-btn-view" onclick="viewRefund(<?= htmlspecialchars(json_encode($rf),ENT_QUOTES) ?>)">
            <i class="fas fa-eye"></i> Details
          </button>
          <?php if ($rf['status'] === 'pending'): ?>
          <button class="rf-btn-sm rf-btn-review" onclick="setStatus(<?= $rf['id'] ?>,'under_review')">
            <i class="fas fa-search"></i> Review
          </button>
          <?php endif; ?>
          <?php if (in_array($rf['status'],['pending','under_review'])): ?>
          <button class="rf-btn-sm rf-btn-process" onclick="openProcess(<?= $rf['id'] ?>, '<?= e($rf['full_name']) ?>')">
            <i class="fas fa-check"></i> Processed
          </button>
          <button class="rf-btn-sm rf-btn-reject" onclick="openReject(<?= $rf['id'] ?>, '<?= e($rf['full_name']) ?>')">
            <i class="fas fa-times"></i> Reject
          </button>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- Detail Modal -->
<div class="rf-modal-overlay" id="rfDetailModal">
  <div class="rf-modal">
    <div class="rf-modal-header">
      <h4><i class="fas fa-file-invoice"></i> Refund Request Details</h4>
      <button class="rf-modal-close" onclick="closeRfModal('rfDetailModal')">&times;</button>
    </div>
    <div class="rf-modal-body" id="rfDetailBody"></div>
    <div class="rf-modal-footer">
      <button class="btn btn-outline" onclick="closeRfModal('rfDetailModal')">Close</button>
    </div>
  </div>
</div>

<!-- Process Modal -->
<div class="rf-modal-overlay" id="rfProcessModal">
  <div class="rf-modal">
    <div class="rf-modal-header" style="background:linear-gradient(135deg,#14532d,#155724);">
      <h4><i class="fas fa-check-circle"></i> Confirm Refund Processed</h4>
      <button class="rf-modal-close" onclick="closeRfModal('rfProcessModal')">&times;</button>
    </div>
    <div class="rf-modal-body">
      <p>Confirm that you have successfully transferred the refund amount to <strong id="processName"></strong>.</p>
      <div class="form-group" style="margin-top:1rem;">
        <label style="font-size:.82rem;font-weight:700;color:#444;text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.35rem;">Admin Notes (optional)</label>
        <textarea id="processNotes" class="form-control" rows="3" placeholder="e.g. EFT transferred via FNB on 18 Apr 2026, reference: AHG-REF-001"></textarea>
      </div>
      <input type="hidden" id="processId">
    </div>
    <div class="rf-modal-footer">
      <button class="btn btn-outline" onclick="closeRfModal('rfProcessModal')">Cancel</button>
      <button class="btn btn-primary" style="background:#155724;" onclick="submitProcess()">
        <i class="fas fa-check"></i> Confirm Processed & Notify Client
      </button>
    </div>
  </div>
</div>

<!-- Reject Modal -->
<div class="rf-modal-overlay" id="rfRejectModal">
  <div class="rf-modal">
    <div class="rf-modal-header" style="background:linear-gradient(135deg,#7f1d1d,#991b1b);">
      <h4><i class="fas fa-times-circle"></i> Reject Refund Request</h4>
      <button class="rf-modal-close" onclick="closeRfModal('rfRejectModal')">&times;</button>
    </div>
    <div class="rf-modal-body">
      <p>You are about to reject the refund request from <strong id="rejectName"></strong>.</p>
      <div class="form-group" style="margin-top:1rem;">
        <label style="font-size:.82rem;font-weight:700;color:#444;text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.35rem;">Reason for Rejection *</label>
        <textarea id="rejectReason" class="form-control" rows="4" required placeholder="e.g. No valid proof of payment provided. The referenced invoice was not found in our system."></textarea>
      </div>
      <input type="hidden" id="rejectId">
    </div>
    <div class="rf-modal-footer">
      <button class="btn btn-outline" onclick="closeRfModal('rfRejectModal')">Cancel</button>
      <button class="btn btn-danger" onclick="submitReject()">
        <i class="fas fa-times"></i> Reject & Notify Client
      </button>
    </div>
  </div>
</div>

<script>
(function(){
    function openRfModal(id)  { document.getElementById(id).classList.add('open'); }
    function closeRfModal2(id){ document.getElementById(id).classList.remove('open'); }
    window.closeRfModal = closeRfModal2;

    document.querySelectorAll('.rf-modal-overlay').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });

    window.viewRefund = function(rf) {
        const typeMap = { registration: 'Registration Fee', preauction_invoice: 'Pre-Auction Invoice', other: 'Other' };
        const statusMap = { pending:'Pending', under_review:'Under Review', processed:'Processed', rejected:'Rejected' };
        const html = `
            <div class="rf-detail-row"><span class="lbl">Client</span><span class="val">${rf.full_name}</span></div>
            <div class="rf-detail-row"><span class="lbl">Email</span><span class="val">${rf.email}</span></div>
            <div class="rf-detail-row"><span class="lbl">Ref Type</span><span class="val">${typeMap[rf.reference_type]||rf.reference_type}</span></div>
            ${rf.reference_number ? `<div class="rf-detail-row"><span class="lbl">Reference #</span><span class="val">${rf.reference_number}</span></div>` : ''}
            <div class="rf-detail-row"><span class="lbl">Amount Paid</span><span class="val">R${parseFloat(rf.amount_paid).toLocaleString('en-ZA',{minimumFractionDigits:2})}</span></div>
            <div class="rf-detail-row"><span class="lbl">Refund Amount</span><span class="val" style="color:#005BA1;font-size:1rem;">R${parseFloat(rf.refund_amount).toLocaleString('en-ZA',{minimumFractionDigits:2})}</span></div>
            <div class="rf-detail-row"><span class="lbl">Reason</span><span class="val">${rf.reason}</span></div>
            <div class="rf-detail-row"><span class="lbl">Status</span><span class="val">${statusMap[rf.status]||rf.status}</span></div>
            <div class="rf-detail-row"><span class="lbl">Submitted</span><span class="val">${rf.created_at}</span></div>
            <div class="rf-bank-box">
                <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:#667;font-weight:700;margin-bottom:.5rem;">Banking Details for EFT Refund</div>
                <strong>Account Holder:</strong> ${rf.account_holder}<br>
                <strong>Bank:</strong> ${rf.bank_name}<br>
                <strong>Account Number:</strong> ${rf.account_number}<br>
                <strong>Branch Code:</strong> ${rf.branch_code}<br>
                <strong>Account Type:</strong> ${rf.account_type}
            </div>
            ${rf.pop_file_path ? `<a href="serve_pop.php?file=${encodeURIComponent(rf.pop_file_path.split('/').pop())}" target="_blank" style="display:inline-flex;align-items:center;gap:.4rem;color:#005BA1;font-weight:600;font-size:.85rem;"><i class="fas fa-file-image"></i> View Proof of Payment</a>` : ''}
            ${rf.admin_notes ? `<div class="rf-detail-row" style="margin-top:.5rem;"><span class="lbl">Admin Notes</span><span class="val">${rf.admin_notes}</span></div>` : ''}
            ${rf.rejection_reason ? `<div class="rf-detail-row" style="color:#721c24;"><span class="lbl">Rejection Reason</span><span class="val">${rf.rejection_reason}</span></div>` : ''}
        `;
        document.getElementById('rfDetailBody').innerHTML = html;
        openRfModal('rfDetailModal');
    };

    window.setStatus = async function(id, status) {
        const fd = new FormData();
        fd.append('action','update_refund_status');
        fd.append('refund_id', id);
        fd.append('status', status);
        const data = await fetch('api.php', {method:'POST',body:fd}).then(r=>r.json());
        showToast(data.message || (data.success?'Updated':'Error'), data.success?'success':'error');
        if (data.success) setTimeout(()=>location.reload(),900);
    };

    window.openProcess = function(id, name) {
        document.getElementById('processId').value = id;
        document.getElementById('processName').textContent = name;
        document.getElementById('processNotes').value = '';
        openRfModal('rfProcessModal');
    };
    window.submitProcess = async function() {
        const fd = new FormData();
        fd.append('action','process_refund');
        fd.append('refund_id', document.getElementById('processId').value);
        fd.append('notes', document.getElementById('processNotes').value);
        const data = await fetch('api.php',{method:'POST',body:fd}).then(r=>r.json());
        closeRfModal2('rfProcessModal');
        showToast(data.message||(data.success?'Refund marked as processed':'Error'), data.success?'success':'error');
        if (data.success) setTimeout(()=>location.reload(),1000);
    };

    window.openReject = function(id, name) {
        document.getElementById('rejectId').value = id;
        document.getElementById('rejectName').textContent = name;
        document.getElementById('rejectReason').value = '';
        openRfModal('rfRejectModal');
    };
    window.submitReject = async function() {
        const reason = document.getElementById('rejectReason').value.trim();
        if (!reason) { alert('Please provide a rejection reason.'); return; }
        const fd = new FormData();
        fd.append('action','reject_refund');
        fd.append('refund_id', document.getElementById('rejectId').value);
        fd.append('reason', reason);
        const data = await fetch('api.php',{method:'POST',body:fd}).then(r=>r.json());
        closeRfModal2('rfRejectModal');
        showToast(data.message||(data.success?'Refund rejected':'Error'), data.success?'success':'error');
        if (data.success) setTimeout(()=>location.reload(),1000);
    };
})();
</script>
<?php endif; ?>

<script>
/* Admin mobile nav toggle */
(function () {
  var toggle = document.getElementById('adminNavToggle');
  var panel  = document.getElementById('adminNavPanel');
  if (!toggle || !panel) return;

  function openPanel() {
    panel.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
  }
  function closePanel() {
    panel.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
  }

  toggle.addEventListener('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    panel.hidden ? openPanel() : closePanel();
  });

  document.addEventListener('click', function (e) {
    if (!panel.hidden && !panel.contains(e.target) && !toggle.contains(e.target)) {
      closePanel();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) { closePanel(); toggle.focus(); }
  });
})();
</script>

<?php require_once 'footer.php'; ?>
