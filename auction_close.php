<?php
// ============================================================
// AUCTION HUB GROUP — auction_close.php
// closeAuction(): the one place an expired lot is closed — winner
// decided, sale recorded, purchase request, winner email + alerts.
// Used by api.php, auction.php and cron_close_auctions.php.
// Requires: config.php (already loaded before this file)
// ============================================================

/**
 * Close an auction: mark as sold, create purchase request, notify winner.
 * Safe to call multiple times — idempotent on already-closed auctions.
 */
function closeAuction(int $auctionId): void {
    global $pdo;

    // Get auction + winning bid in one query
    $stmt = $pdo->prepare("
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
    $stmt->execute([$auctionId]);
    $auction = $stmt->fetch();

    if (!$auction || $auction['status'] === 'closed') return; // Already closed

    $winnerId     = $auction['winner_id'] ? (int)$auction['winner_id'] : null;
    $winningAmt   = $auction['winning_amount'] ? (float)$auction['winning_amount'] : null;
    $reserveMet   = $winningAmt && $winningAmt >= (float)$auction['reserve_price'];

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
        $auction['winner_name'],
        $winnerId,
        $winningAmt,
        $auctionId,
    ]);

    // Create purchase request for winner if reserve was met
    if ($winnerId && $reserveMet) {
        notifyLotWon($winnerId, $auctionId, $auction['item_name'], (float)$winningAmt);
        notifyWatchersSold($auctionId, $winnerId);
        $winnerUser = $pdo->prepare("SELECT id, full_name, email, phone, id_number FROM users WHERE id = ?");
        $winnerUser->execute([$winnerId]);
        $winnerRow = $winnerUser->fetch();

        // Purchase request (non-fatal). full_name and email are NOT NULL in the
        // table; leaving them out used to throw here and skip the winner email.
        try {
            $exists = $pdo->prepare("SELECT id FROM purchase_requests WHERE auction_id = ? AND user_id = ?");
            $exists->execute([$auctionId, $winnerId]);
            if ($winnerRow && !$exists->fetch()) {
                $pdo->prepare("
                    INSERT INTO purchase_requests (auction_id, user_id, vehicle_name, full_name, email, phone, id_number, amount, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ")->execute([$auctionId, $winnerId, $auction['item_name'], $winnerRow['full_name'], $winnerRow['email'],
                             $winnerRow['phone'], $winnerRow['id_number'], $winningAmt]);
            }
        } catch (PDOException $e) {
            error_log('[AHG] purchase request #' . $auctionId . ': ' . $e->getMessage());
        }

        // Send winner email (non-fatal)
        try {
            require_once __DIR__ . '/email_helper.php';
            if ($winnerRow) {
                sendAuctionWinnerEmail($winnerRow, $auction, (float)$winningAmt);
            }
        } catch (Exception $e) {
            error_log('Winner email error: ' . $e->getMessage());
        }
    }
}
