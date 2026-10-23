<?php
/**
 * CRON JOB — Automatic Auction Close + bidder reminders
 *
 * 1. Finds all active auctions whose end_time has passed and closes them.
 *    Triggers winner notification emails and creates purchase requests.
 * 2. Watchlist reminders: watched lot went live / ends within the hour.
 * 3. Saved-search digests: one email per search per day with new matches.
 *
 * SETUP (cPanel Cron Jobs):
 *   Command:  /usr/local/bin/php /home/YOUR_CPANEL_USER/public_html/cron_close_auctions.php
 *   Schedule: Every 5 minutes (cPanel "Once Per Five Minutes" — minute field: star-slash-5)
 *   (Never write the cron expression literally here: its star-slash ends this comment.)
 *
 * Can also be called via browser for testing (admin-only):
 *   https://auctionhubgroup.co.za/cron_close_auctions.php?key=YOUR_CRON_KEY
 */

// Allow CLI execution without session
if (php_sapi_name() === 'cli') {
    // Running from command line — skip session
    define('SKIP_SESSION', true);
}

// Minimal bootstrap — just DB + email
require_once __DIR__ . '/config.php';

// Security: If called via browser, require a secret key
if (php_sapi_name() !== 'cli') {
    $cronKey = 'pva_cron_2026_close'; // Change this to a strong secret
    if (($_GET['key'] ?? '') !== $cronKey) {
        http_response_code(403);
        die('Forbidden');
    }
}

// closeAuction() lives in auction_close.php (shared with api.php and auction.php)
require_once __DIR__ . '/email_helper.php';

$log = function(string $msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    error_log($line);
    if (php_sapi_name() === 'cli') {
        echo $line . PHP_EOL;
    }
};

try {
    // Find all expired active auctions
    $stmt = $pdo->query("
        SELECT id, item_name, end_time
        FROM auctions
        WHERE status = 'active' AND end_time <= NOW()
        ORDER BY end_time ASC
    ");
    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($expired)) {
        $log('No expired auctions to close.');
    } else {
        $log(count($expired) . ' expired auction(s) found.');
    }

    require_once __DIR__ . '/auction_close.php';
    foreach ($expired as $auction) {
        $auctionId = (int)$auction['id'];
        try {
            closeAuction($auctionId);   // same routine the site uses: winner, sale, emails, alerts
            $r = $pdo->prepare("SELECT is_sold, sold_to, final_price FROM auctions WHERE id = ?");
            $r->execute([$auctionId]);
            $r = $r->fetch(PDO::FETCH_ASSOC);
            $log("  Closed auction #{$auctionId}: \"{$auction['item_name']}\" — "
                . (!empty($r['is_sold']) ? "sold to {$r['sold_to']} for " . formatRand((float)$r['final_price']) : 'not sold (no bids or reserve not met)'));
        } catch (Throwable $e) {
            $log("  Auction #{$auctionId} close failed: " . $e->getMessage());
        }
    }

    // Reminders run every time, even when nothing closed
    try { runWatchReminders($log); }     catch (Throwable $e) { $log('Watch reminders error: ' . $e->getMessage()); }
    try { runSavedSearchDigests($log); } catch (Throwable $e) { $log('Saved searches error: ' . $e->getMessage()); }

    $log('Cron complete.');

} catch (Exception $e) {
    $log('CRON ERROR: ' . $e->getMessage());
    exit(1);
}
