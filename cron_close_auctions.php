<?php
/**
 * CRON JOB — Automatic Auction Close
 *
 * Finds all active auctions whose end_time has passed and closes them.
 * Triggers winner notification emails and creates purchase requests.
 *
 * SETUP (cPanel Cron Jobs):
 *   Command:  /usr/local/bin/php /home/YOUR_CPANEL_USER/public_html/cron_close_auctions.php
 *   Schedule: Every 5 minutes  ( */5 * * * * )
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

// Load the closeAuction function from api.php without executing the router
// We re-implement it here to avoid loading the full api.php
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
        exit(0);
    }

    $log(count($expired) . ' expired auction(s) found.');

    foreach ($expired as $auction) {
        $auctionId = (int)$auction['id'];

        // Get winning bid
        $wStmt = $pdo->prepare("
            SELECT a.id, a.item_name, a.status, a.is_sold,
                   a.current_price, a.reserve_price, a.start_price,
                   b.user_id AS winner_id,
                   COALESCE(b.bid_amount, b.amount) AS winning_amount,
                   b.bidder_name AS winner_name, b.bidder_number AS winner_bidder_number
            FROM auctions a
            LEFT JOIN bids b ON b.auction_id = a.id AND b.is_winning = 1
            WHERE a.id = ?
            LIMIT 1
        ");
        $wStmt->execute([$auctionId]);
        $aData = $wStmt->fetch(PDO::FETCH_ASSOC);

        if (!$aData || $aData['status'] === 'closed') {
            $log("  Auction #{$auctionId} already closed, skipping.");
            continue;
        }

        $winnerId   = $aData['winner_id'] ? (int)$aData['winner_id'] : null;
        $winningAmt = $aData['winning_amount'] ? (float)$aData['winning_amount'] : null;
        $reserveMet = $winningAmt && $winningAmt >= (float)$aData['reserve_price'];

        // Close the auction
        $pdo->prepare("
            UPDATE auctions SET
                status      = 'closed',
                is_sold     = ?,
                sold_to     = ?,
                sold_to_user_id = ?,
                final_price = ?,
                sold_date   = NOW()
            WHERE id = ?
        ")->execute([
            ($winnerId && $reserveMet) ? 1 : 0,
            $aData['winner_name'],
            $winnerId,
            $winningAmt,
            $auctionId,
        ]);

        $log("  Closed auction #{$auctionId}: \"{$auction['item_name']}\"");

        // Create purchase request + notify winner if reserve met
        if ($winnerId && $reserveMet) {
            $exists = $pdo->prepare("SELECT id FROM purchase_requests WHERE auction_id = ? AND user_id = ?");
            $exists->execute([$auctionId, $winnerId]);
            if (!$exists->fetch()) {
                $pdo->prepare("
                    INSERT INTO purchase_requests (auction_id, user_id, amount, status)
                    VALUES (?, ?, ?, 'pending')
                ")->execute([$auctionId, $winnerId, $winningAmt]);
            }

            try {
                $winnerUser = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ?");
                $winnerUser->execute([$winnerId]);
                $winnerRow = $winnerUser->fetch(PDO::FETCH_ASSOC);
                if ($winnerRow) {
                    sendAuctionWinnerEmail($winnerRow, $aData, $winningAmt);
                    $log("    Winner notified: {$winnerRow['full_name']} ({$winnerRow['email']})");
                }
            } catch (Exception $e) {
                $log("    Winner email failed: " . $e->getMessage());
            }
        } else {
            $log("    No winner (no bids or reserve not met).");
        }
    }

    $log('Cron complete.');

} catch (Exception $e) {
    $log('CRON ERROR: ' . $e->getMessage());
    exit(1);
}
