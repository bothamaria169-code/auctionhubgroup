<?php
// ============================================================
// AUCTION HUB GROUP — email_helper.php
// All PHPMailer email functions centralised in one file
// Requires: config.php (already loaded before this file)
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ── Internal: build a configured PHPMailer instance ──────────
function _buildMailer(string $fromEmail = MAIL_FROM_EMAIL, string $fromName = MAIL_FROM_NAME, string $username = MAIL_USERNAME, string $password = MAIL_PASSWORD): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $username;
    $mail->Password   = $password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;   // SSL on port 465
    $mail->Port       = MAIL_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 15;

    // Allow mismatched SSL cert hostname (hosting server name differs from mail subdomain)
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    // Suppress PHPMailer version fingerprint — shared hosting IPs with identifiable
    // mailer headers are more aggressively filtered by Gmail and other providers
    $mail->XMailer = ' ';

    // Set Message-ID domain to match the FROM address domain so Gmail's
    // alignment check passes (mismatched Message-ID is a common spam signal)
    $fromDomain = substr(strrchr($fromEmail, '@'), 1);
    $mail->MessageID = '<' . uniqid('ahg.', true) . '@' . $fromDomain . '>';

    // Envelope sender (Return-Path) must match the authenticated SMTP account
    // — a mismatch causes Gmail to flag the mail as potentially forged
    $mail->Sender = $username;

    $mail->setFrom($fromEmail, $fromName);
    $mail->addReplyTo($fromEmail, $fromName);
    $mail->isHTML(true);
    return $mail;
}

// ── Internal: shared email HTML wrapper (header + footer) ────
// <style> block kept for Outlook/Apple Mail/Thunderbird.
// Gmail strips <head> CSS — all layout-critical elements also carry
// inline styles so rendering survives in every client.
function _emailTemplate(string $title, string $bodyContent): string {
    $t = htmlspecialchars($title);
    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . $t . '</title>
<style>
  body{margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;}
  .wrapper{max-width:620px;margin:24px auto;background:#fff;border-radius:8px;overflow:hidden;}
  .header{background:#005BA1;padding:26px 32px;text-align:center;}
  .header h1{color:#fff;margin:0;font-size:20px;font-weight:700;letter-spacing:.5px;}
  .body{padding:32px;color:#333;font-size:15px;line-height:1.75;}
  .body h2{color:#005BA1;margin-top:0;font-size:18px;}
  .highlight-box{background:#f0f6fc;border-left:4px solid #005BA1;padding:14px 18px;border-radius:0 6px 6px 0;margin:16px 0;}
  .highlight-box p{margin:6px 0;}
  .cta-btn{display:inline-block;margin:20px 0 8px;padding:13px 28px;background:#005BA1;color:#fff!important;text-decoration:none;border-radius:6px;font-size:15px;font-weight:600;}
  .green{color:#28a745;}
  .red{color:#dc3545;}
  .gold{color:#f5a623;font-weight:bold;}
  .footer{background:#f0f6fc;padding:20px 32px;text-align:center;color:#777;font-size:13px;border-top:1px solid #e0e8f0;}
  .footer a{color:#005BA1;text-decoration:none;}
  .divider{border:none;border-top:1px solid #e8ecf0;margin:22px 0;}
</style>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:24px 0;">
  <tr><td align="center">
    <table role="presentation" width="620" cellpadding="0" cellspacing="0" class="wrapper" style="max-width:620px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">

      <tr>
        <td align="center" class="header" style="background:#005BA1;padding:26px 32px;text-align:center;">
          <h1 style="color:#ffffff;margin:0;font-size:20px;font-weight:700;letter-spacing:.5px;font-family:Arial,Helvetica,sans-serif;">AUCTION HUB GROUP</h1>
          <p style="color:rgba(255,255,255,.75);margin:4px 0 0;font-size:12px;">Trusted by banks, respected by buyers</p>
        </td>
      </tr>

      <tr>
        <td class="body" style="padding:32px;font-size:15px;line-height:1.75;color:#333333;font-family:Arial,Helvetica,sans-serif;">
          ' . $bodyContent . '
        </td>
      </tr>

      <tr>
        <td class="footer" style="background:#f0f6fc;padding:20px 32px;text-align:center;font-size:13px;color:#777777;border-top:1px solid #e0e8f0;font-family:Arial,Helvetica,sans-serif;">
          <p style="margin:0 0 4px;"><strong style="color:#333333;">Auction Hub Group</strong></p>
          <p style="margin:0 0 4px;">Head Office &mdash; 80 Sterling Rd, Kosmosdal, Centurion, 0187</p>
          <p style="margin:0 0 10px;">
            <a href="mailto:info@auctionhubgroup.co.za" style="color:#005BA1;text-decoration:none;">info@auctionhubgroup.co.za</a>
            &nbsp;|&nbsp;
            <a href="mailto:careers@auctionhubgroup.co.za" style="color:#005BA1;text-decoration:none;">careers@auctionhubgroup.co.za</a>
            &nbsp;|&nbsp;
            <a href="mailto:finance@auctionhubgroup.co.za" style="color:#005BA1;text-decoration:none;">finance@auctionhubgroup.co.za</a>
          </p>
          <p style="margin:0 0 12px;">+27 10 516 0683 (Main Office Line) &nbsp;|&nbsp; +27 10 516 0681 (Secondary Line)</p>
          <p style="margin:0 0 4px;color:#999999;font-size:11px;">Gauteng &mdash; Boksburg: 8 Top Rd, O.R. Tambo, Boksburg, 1459</p>
          <p style="margin:0 0 4px;color:#999999;font-size:11px;">KwaZulu-Natal &mdash; Durban: Quarry Place, Off Queen Nandi Drive, River Horse Estate, Durban</p>
          <p style="margin:0 0 4px;color:#999999;font-size:11px;">Free State &mdash; Bloemfontein: Cnr R64 &amp; Valentia Road, Waterbron, Bloemfontein</p>
          <p style="margin:0 0 10px;color:#999999;font-size:11px;">Eastern Cape &mdash; Gqeberha (PE): ABSA Vehicle &amp; Asset Finance Trade Centre, 142 Burman Road, Deal Party, Gqeberha (PE)</p>
          <p style="margin:0;color:#aaaaaa;font-size:12px;">This is an automated message. Please do not reply directly to this email.</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>';
}

// ── Internal: safe send with error logging ────────────────────
function _sendMail(PHPMailer $mail, string $context = ''): bool {
    // If email is disabled (e.g. local dev without SMTP), skip silently
    if (!defined('EMAIL_ENABLED') || !EMAIL_ENABLED) {
        error_log("AHG Email SKIPPED [{$context}]: EMAIL_ENABLED is false");
        return true; // return true so callers don't treat it as a failure
    }
    try {
        return $mail->send();
    } catch (Exception $e) {
        error_log("AHG Email Error [{$context}]: " . $mail->ErrorInfo);
        return false;
    }
}


// ============================================================
// 1. WELCOME EMAIL — sent on registration
// ============================================================
function sendWelcomeEmail(array $user): bool {
    $mail = _buildMailer();
    $mail->addAddress($user['email'], $user['full_name']);
    $mail->Subject = 'Welcome to Auction Hub Group — Your Account Is Ready';

    $body = '
    <h2>Welcome, ' . e($user['full_name']) . '!</h2>
    <p>Thank you for registering with <strong>Auction Hub Group</strong>.
    Your account has been created successfully.</p>

    <p>To participate in our live auctions and place bids, you need to complete
    your bidder registration. Log in to your dashboard to get started — the
    registration steps and payment details are available there.</p>

    <a href="' . APP_URL . '/user_dashboard.php" class="cta-btn">Go to My Dashboard</a>

    <hr class="divider">
    <p>If you have any questions, please email us at
    <a href="mailto:' . COMPANY_EMAIL . '">' . COMPANY_EMAIL . '</a> or call ' . COMPANY_PHONE . '.</p>';

    $mail->Body    = _emailTemplate('Welcome to Auction Hub Group', $body);
    $mail->AltBody = "Welcome {$user['full_name']}! Your account is ready. Log in to complete your bidder registration at " . APP_URL . "/user_dashboard.php";

    return _sendMail($mail, 'welcome');
}


// ============================================================
// 2. POP UPLOADED — notify admin that a new POP needs review
// ============================================================
function sendPopUploadedAdminAlert(array $user, array $registration): bool {
    $mail = _buildMailer();
    $mail->addAddress(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    $mail->Subject = '🔔 New Proof of Payment Submitted — ' . $user['full_name'];

    $body = '
    <h2>New Registration Payment Submitted</h2>
    <p>A user has uploaded their proof of payment and is awaiting approval.</p>
    <div class="highlight-box">
      <p><strong>Name:</strong> ' . e($user['full_name']) . '</p>
      <p><strong>Email:</strong> ' . e($user['email']) . '</p>
      <p><strong>Phone:</strong> ' . e($user['phone'] ?? 'N/A') . '</p>
      <p><strong>ID Number:</strong> ' . e($user['id_number'] ?? 'N/A') . '</p>
      <p><strong>EFT Reference:</strong> ' . eftReference($user['id']) . '</p>
      <p><strong>Submitted:</strong> ' . date('d M Y H:i') . '</p>
    </div>
    <a href="' . APP_URL . '/admin_dashboard.php?tab=pop_approvals" class="cta-btn">Review in Admin Dashboard</a>';

    $mail->Body    = _emailTemplate('New POP Submitted', $body);
    $mail->AltBody = "New POP submitted by {$user['full_name']} ({$user['email']}). Review at " . APP_URL . "/admin_dashboard.php";

    return _sendMail($mail, 'pop_admin_alert');
}


// ============================================================
// 3. POP APPROVED — user gets bidder number
// ============================================================
function sendPopApprovedEmail(array $user, string $bidderNumber): bool {
    $mail = _buildMailer();
    $mail->addAddress($user['email'], $user['full_name']);
    $mail->Subject = '✅ Approved! Your Bidder Number is ' . $bidderNumber;

    $body = '
    <h2 class="green">🎉 You\'re Approved!</h2>
    <p>Dear ' . e($user['full_name']) . ',</p>
    <p>Your proof of payment has been reviewed and approved. You are now a 
    <strong>verified bidder</strong> and can participate in all live auctions.</p>

    <div class="highlight-box">
      <p style="font-size:22px; text-align:center; margin:8px 0;">
        Your Bidder Number:<br>
        <span class="gold" style="font-size:32px;">' . e($bidderNumber) . '</span>
      </p>
    </div>

    <p>Keep your bidder number safe — it uniquely identifies you in all our auctions 
    and will appear on all your bid records.</p>

    <a href="' . APP_URL . '/auctions.php" class="cta-btn">Browse Live Auctions</a>

    <hr class="divider">
    <p>Good luck and happy bidding! If you need assistance, please contact us anytime.</p>';

    $mail->Body    = _emailTemplate('Bidder Approved — ' . $bidderNumber, $body);
    $mail->AltBody = "Congratulations {$user['full_name']}! Your bidder number is {$bidderNumber}. Browse auctions at " . APP_URL . "/auctions.php";

    return _sendMail($mail, 'pop_approved');
}


// ============================================================
// 4. POP REJECTED — user must resubmit
// ============================================================
function sendPopRejectedEmail(array $user, string $reason = ''): bool {
    $mail = _buildMailer();
    $mail->addAddress($user['email'], $user['full_name']);
    $mail->Subject = 'Action Required — Your Proof of Payment Could Not Be Verified';

    $reasonHtml = $reason
        ? '<div class="highlight-box"><p><strong>Reason:</strong> ' . e($reason) . '</p></div>'
        : '';

    $body = '
    <h2 class="red">⚠️ Proof of Payment Not Accepted</h2>
    <p>Dear ' . e($user['full_name']) . ',</p>
    <p>Unfortunately, we could not verify your proof of payment. 
    Please review the details below and resubmit a clear, valid proof of payment.</p>
    ' . $reasonHtml . '
    <p>Common reasons for rejection:</p>
    <ul>
      <li>Blurry or illegible image/document</li>
      <li>Incorrect reference number on the payment</li>
      <li>Payment amount does not match R10,000</li>
      <li>Screenshot does not show the full transaction confirmation</li>
    </ul>

    <div class="highlight-box">
      <p><strong>Correct EFT Reference:</strong> <span class="gold">' . eftReference($user['id']) . '</span></p>
      <p><strong>Amount:</strong> <span class="gold">R10,000.00</span></p>
    </div>

    <a href="' . APP_URL . '/user_dashboard.php" class="cta-btn">Resubmit Proof of Payment</a>

    <hr class="divider">
    <p>If you believe this is an error, please contact us via email.</p>';

    $mail->Body    = _emailTemplate('POP Rejected — Action Required', $body);
    $mail->AltBody = "Dear {$user['full_name']}, your proof of payment was not accepted. Please resubmit at " . APP_URL . "/user_dashboard.php. Reason: {$reason}";

    return _sendMail($mail, 'pop_rejected');
}


// ============================================================
// 4b. BANK DETAILS — sent to user on request
// ============================================================
function sendBankDetailsEmail(array $user): bool {
    $bank = getBankSettings();
    $mail = _buildMailer();
    $mail->addAddress($user['email'], $user['full_name']);
    $mail->Subject = 'Auction Hub Group — EFT Banking Details';

    $ref = eftReference($user['id']);

    $body = '
    <h2>EFT Banking Details</h2>
    <p>Dear ' . e($user['full_name']) . ',</p>
    <p>Thank you for your interest in bidding with Auction Hub Group. Below are our banking details for the <strong>' . e($bank['reg_fee_label']) . '</strong> refundable registration deposit:</p>

    <div class="highlight-box">
      <p><strong>Bank:</strong> ' . e($bank['bank_name']) . '</p>
      <p><strong>Account Name:</strong> ' . e($bank['bank_account_name']) . '</p>
      <p><strong>Account Number:</strong> ' . e($bank['bank_account_no']) . '</p>
      <p><strong>Branch Code:</strong> ' . e($bank['bank_branch_code']) . '</p>
      <p><strong>Account Type:</strong> ' . e($bank['bank_account_type']) . '</p>
      <p style="margin-top:10px"><strong>Payment Reference:</strong> <span class="gold" style="font-size:18px;">' . e($ref) . '</span></p>
      <p><strong>Amount:</strong> <span class="gold">' . e($bank['reg_fee_label']) . '</span></p>
    </div>

    <p><strong>Important:</strong> Please use <strong>' . e($ref) . '</strong> as your exact EFT reference so we can match your payment to your account.</p>
    <p>Once paid, upload your proof of payment (bank confirmation screenshot or PDF) in your dashboard:</p>

    <a href="' . APP_URL . '/user_dashboard.php?tab=register" class="cta-btn">Upload Proof of Payment</a>

    <hr class="divider">
    <p>Please do not share these banking details publicly. If you need assistance, contact us via email.</p>';

    $mail->Body    = _emailTemplate('EFT Banking Details', $body);
    $mail->AltBody = "Dear {$user['full_name']}, here are our banking details: Bank: {$bank['bank_name']}, Account: {$bank['bank_account_no']}, Branch: {$bank['bank_branch_code']}, Reference: {$ref}, Amount: {$bank['reg_fee_label']}. Upload POP at " . APP_URL . "/user_dashboard.php";

    return _sendMail($mail, 'bank_details');
}


// ============================================================
// 5. BID PLACED — confirmation to bidder
// ============================================================
function sendBidConfirmationEmail(array $data): bool {
    // $data keys: email, name, vehicle_name, bid_amount, current_high_bid,
    //             bid_count, auction_id, auction_end_time, bidder_number
    $mail = _buildMailer();
    $mail->addAddress($data['email'], $data['name']);
    $mail->Subject = '✅ Bid Placed — ' . $data['vehicle_name'];

    $isWinning = ($data['bid_amount'] >= $data['current_high_bid']);
    $status    = $isWinning
        ? '<span class="green">🏆 You are currently the highest bidder!</span>'
        : '<span style="color:#f5a623;">⚡ You have been outbid. Consider raising your bid.</span>';

    $endTime = safeDate('d M Y H:i', $data['auction_end_time'] ?? null, 'TBC');

    $body = '
    <h2>Bid Confirmation</h2>
    <p>Dear ' . e($data['name']) . ',</p>
    <p>Your bid has been successfully placed.</p>

    <div class="highlight-box">
      <p><strong>Vehicle:</strong> ' . e($data['vehicle_name']) . '</p>
      <p><strong>Your Bid:</strong> <span class="gold">' . formatRand($data['bid_amount']) . '</span></p>
      <p><strong>Current Highest Bid:</strong> ' . formatRand($data['current_high_bid']) . '</p>
      <p><strong>Total Bids:</strong> ' . (int)($data['bid_count'] ?? 0) . '</p>
      <p><strong>Bidder Number:</strong> ' . e($data['bidder_number'] ?? 'N/A') . '</p>
      <p><strong>Auction Ends:</strong> ' . $endTime . '</p>
    </div>

    <p>' . $status . '</p>

    <a href="' . APP_URL . '/auction.php?id=' . (int)($data['auction_id'] ?? 0) . '" class="cta-btn">View Live Auction</a>';

    $mail->Body    = _emailTemplate('Bid Placed — ' . $data['vehicle_name'], $body);
    $mail->AltBody = "Bid of " . formatRand($data['bid_amount']) . " placed on {$data['vehicle_name']}. Auction ends {$endTime}.";

    return _sendMail($mail, 'bid_confirm');
}


// ============================================================
// 6. OUTBID ALERT — someone beat the user's bid
// ============================================================
function sendOutbidAlertEmail(array $data): bool {
    // $data keys: email, name, vehicle_name, their_bid, new_high_bid,
    //             auction_id, auction_end_time
    $mail = _buildMailer();
    $mail->addAddress($data['email'], $data['name']);
    $mail->Subject = '⚡ You\'ve Been Outbid — ' . $data['vehicle_name'];

    $body = '
    <h2 style="color:#f5a623;">⚡ You\'ve Been Outbid!</h2>
    <p>Dear ' . e($data['name']) . ',</p>
    <p>Someone has placed a higher bid on a vehicle you were leading on.</p>

    <div class="highlight-box">
      <p><strong>Vehicle:</strong> ' . e($data['vehicle_name']) . '</p>
      <p><strong>Your Last Bid:</strong> ' . formatRand($data['their_bid']) . '</p>
      <p><strong>New Highest Bid:</strong> <span class="red">' . formatRand($data['new_high_bid']) . '</span></p>
      <p><strong>Auction Ends:</strong> ' . safeDate('d M Y H:i', $data['auction_end_time'] ?? null, 'TBC') . '</p>
    </div>

    <p>Don\'t miss out — place a higher bid now before the auction closes!</p>

    <a href="' . APP_URL . '/auction.php?id=' . (int)($data['auction_id'] ?? 0) . '" class="cta-btn">Bid Again Now</a>';

    if (!empty($data['user_id']) && function_exists('unsubscribeFooterHtml')) {
        $body .= unsubscribeFooterHtml((int)$data['user_id'], 'outbid', 'you bid on this vehicle');
    }

    $mail->Body    = _emailTemplate('You\'ve Been Outbid', $body);
    $mail->AltBody = "You've been outbid on {$data['vehicle_name']}. New highest bid: " . formatRand($data['new_high_bid']) . ". Bid at " . APP_URL . "/auction.php?id=" . ($data['auction_id'] ?? '');

    return _sendMail($mail, 'outbid_alert');
}


// ============================================================
// 6b. WATCHLIST — a watched lot ends within the hour
// ============================================================
function sendWatchEndingEmail(array $r): bool {
    // $r keys: user_id, email, full_name, id, item_name, end_time, price
    $mail = _buildMailer();
    $mail->addAddress($r['email'], $r['full_name']);
    $mail->Subject = '⏰ Ending within the hour — ' . $r['item_name'];
    $link = CANONICAL_URL . '/auction.php?id=' . (int)$r['id'];

    $body = '
    <h2 style="color:#005BA1;">⏰ A vehicle you\'re watching ends soon</h2>
    <p>Dear ' . e($r['full_name']) . ',</p>
    <p>Bidding on a vehicle on your watchlist closes within the hour.</p>
    <div class="highlight-box">
      <p><strong>Vehicle:</strong> ' . e($r['item_name']) . '</p>
      <p><strong>Current Price:</strong> ' . formatRand((float)$r['price']) . '</p>
      <p><strong>Ends:</strong> ' . safeDate('d M Y H:i', $r['end_time'] ?? null, 'soon') . '</p>
    </div>
    <a href="' . $link . '" class="cta-btn">View &amp; Bid</a>'
    . (function_exists('unsubscribeFooterHtml')
        ? unsubscribeFooterHtml((int)$r['user_id'], 'watch', 'this vehicle is on your watchlist') : '');

    $mail->Body    = _emailTemplate('Ending Soon', $body);
    $mail->AltBody = "{$r['item_name']} ends " . safeDate('d M Y H:i', $r['end_time'] ?? null, 'soon')
                   . '. Current price ' . formatRand((float)$r['price']) . ". Bid at $link";
    return _sendMail($mail, 'watch_ending');
}

// ============================================================
// 6c. SAVED SEARCH — daily digest of new matching lots
// ============================================================
function sendSavedSearchDigestEmail(array $search, array $filters, array $lots): bool {
    // $search: saved_searches row + email, full_name. $lots: id, item_name, year,
    // mileage, image_url, status, start_time, end_time, price
    $mail = _buildMailer();
    $mail->addAddress($search['email'], $search['full_name']);
    $n = count($lots);
    $mail->Subject = "🚗 $n new vehicle" . ($n === 1 ? '' : 's') . ' for "' . $search['label'] . '"';

    $rows = '';
    foreach ($lots as $l) {
        $img  = $l['image_url'] ? cldImg($l['image_url'], CLD_THUMB) : '';
        $link = CANONICAL_URL . '/auction.php?id=' . (int)$l['id'];
        $when = $l['status'] === 'active'
            ? 'Live · ends ' . safeDate('d M H:i', $l['end_time'])
            : 'Opens ' . safeDate('d M H:i', $l['start_time'], 'soon');
        $rows .= '<tr>'
            . '<td style="padding:10px 10px 10px 0;width:120px;vertical-align:top;">'
            . ($img ? '<a href="' . $link . '"><img src="' . e($img) . '" width="120" alt="" style="border-radius:6px;display:block;"></a>' : '')
            . '</td><td style="padding:10px 0;vertical-align:top;font-size:14px;">'
            . '<a href="' . $link . '" style="color:#005BA1;font-weight:700;text-decoration:none;">' . e($l['item_name']) . '</a><br>'
            . ($l['mileage'] ? number_format((int)$l['mileage']) . ' km · ' : '')
            . '<strong>' . formatRand((float)$l['price']) . '</strong><br>'
            . '<span style="color:#888;font-size:12px;">' . e($when) . '</span></td></tr>';
    }

    $all = CANONICAL_URL . '/' . (function_exists('savedSearchUrl') ? savedSearchUrl($filters) : 'auctions.php');
    $body = '
    <h2 style="color:#005BA1;">New vehicles matching your search</h2>
    <p>Dear ' . e($search['full_name']) . ',</p>
    <p>' . $n . ' new lot' . ($n === 1 ? '' : 's') . ' matching <strong>' . e($search['label']) . '</strong> '
      . ($n === 1 ? 'has' : 'have') . ' been listed since our last email.</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">' . $rows . '</table>
    <a href="' . e($all) . '" class="cta-btn">See All Matches</a>'
    . (function_exists('unsubscribeFooterHtml')
        ? unsubscribeFooterHtml((int)$search['user_id'], 'saved_search', 'you saved this search') : '');

    $mail->Body    = _emailTemplate('New Matches', $body);
    $mail->AltBody = "$n new vehicle(s) for \"{$search['label']}\": $all";
    return _sendMail($mail, 'saved_search_digest');
}

// ============================================================
// 7. AUCTION WON — winner notification
// ============================================================
function sendAuctionWonEmail(array $data): bool {
    // $data keys: email, name, vehicle_name, winning_amount, auction_id
    $mail = _buildMailer();
    $mail->addAddress($data['email'], $data['name']);
    $mail->Subject = '🏆 Congratulations! You Won — ' . $data['vehicle_name'];

    $body = '
    <h2 class="green">🏆 Congratulations, You Won!</h2>
    <p>Dear ' . e($data['name']) . ',</p>
    <p>You are the winning bidder for the following vehicle:</p>

    <div class="highlight-box">
      <p><strong>Vehicle:</strong> ' . e($data['vehicle_name']) . '</p>
      <p><strong>Winning Bid:</strong> <span class="gold" style="font-size:20px;">' . formatRand($data['winning_amount']) . '</span></p>
    </div>

    <p>Our team will be in contact with you shortly to arrange the next steps, 
    including payment and vehicle collection/delivery.</p>

    <p>If you have any questions in the meantime, please contact us:</p>
    <ul>
      <li>📧 <a href="mailto:' . COMPANY_EMAIL . '">' . COMPANY_EMAIL . '</a></li>
      <li>📞 ' . COMPANY_PHONE . '</li>
    </ul>

    <a href="' . APP_URL . '/user_dashboard.php" class="cta-btn">View Your Dashboard</a>';

    $mail->Body    = _emailTemplate('You Won — ' . $data['vehicle_name'], $body);
    $mail->AltBody = "Congratulations {$data['name']}! You won the auction for {$data['vehicle_name']} with a bid of " . formatRand($data['winning_amount']) . ". We'll contact you shortly.";

    return _sendMail($mail, 'auction_won');
}


// ============================================================
// 8. CUSTOM EMAIL — sent by admin from admin dashboard
// ============================================================
function sendCustomEmail(array $data): bool {
    // $data keys: to, name, subject, message, format (plain|html), attachments (optional array of ['path'=>..., 'name'=>...])
    $mail = _buildMailer();
    $mail->addAddress($data['to'], $data['name'] ?? '');
    $mail->Subject = $data['subject'];

    $format = $data['format'] ?? 'plain';

    if ($format === 'html') {
        // Admin sent raw HTML — wrap in email template but don't escape
        $body = '<h2>' . e($data['subject']) . '</h2>' . $data['message'];
        $mail->Body    = _emailTemplate($data['subject'], $body);
        $mail->AltBody = strip_tags($data['message']);
    } else {
        // Plain text — escape and convert newlines
        $body = '
    <h2>' . e($data['subject']) . '</h2>
    <p>' . nl2br(e($data['message'])) . '</p>';
        $mail->Body    = _emailTemplate($data['subject'], $body);
        $mail->AltBody = $data['message'];
    }

    if (!empty($data['attachments']) && is_array($data['attachments'])) {
        foreach ($data['attachments'] as $att) {
            if (!empty($att['path']) && is_readable($att['path'])) {
                $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
            }
        }
    }

    return _sendMail($mail, 'custom_admin');
}


// ============================================================
// 9. BULK EMAIL — send to all or filtered users (admin)
// ============================================================
function sendBulkEmail(array $recipients, string $subject, string $message): array {
    $results = ['sent' => 0, 'failed' => 0, 'errors' => []];

    foreach ($recipients as $user) {
        $sent = sendCustomEmail([
            'to'      => $user['email'],
            'name'    => $user['full_name'],
            'subject' => $subject,
            'message' => $message,
        ]);

        if ($sent) {
            $results['sent']++;
        } else {
            $results['failed']++;
            $results['errors'][] = $user['email'];
        }

        // Small delay to avoid SMTP rate limiting on shared hosting
        usleep(200000); // 0.2 seconds
    }

    return $results;
}


// ============================================================
// 8. AUCTION WINNER NOTIFICATION
// ============================================================
function sendAuctionWinnerEmail(array $user, array $auction, float $winningAmount): bool {
    $mail = _buildMailer();
    $mail->addAddress($user['email'], $user['full_name']);
    $mail->Subject = 'Congratulations! You Won — ' . $auction['item_name'];

    $body = '
    <h2 class="green">🏆 Congratulations, You Won!</h2>
    <p>Dear ' . e($user['full_name']) . ',</p>
    <p>You have won the auction for <strong>' . e($auction['item_name']) . '</strong>
       with a winning bid of <strong class="gold">R ' . number_format($winningAmount, 2) . '</strong>.</p>

    <div class="highlight-box">
      <p><strong>Vehicle:</strong> ' . e($auction['item_name']) . '</p>
      <p><strong>Your Winning Bid:</strong> <span class="gold">R ' . number_format($winningAmount, 2) . '</span></p>
      <p><strong>Status:</strong> <span class="green">Reserve Price Met — Sale Confirmed</span></p>
    </div>

    <p><strong>What happens next?</strong></p>
    <ul>
      <li>Our team will contact you within <strong>1 business day</strong> to arrange payment and collection.</li>
      <li>Full payment is required within <strong>48 hours</strong> of being contacted.</li>
      <li>The vehicle will be held for you pending payment confirmation.</li>
    </ul>

    <a href="' . APP_URL . '/user_dashboard.php" class="cta-btn">View Your Purchase Details</a>

    <hr class="divider">
    <p>If you have any questions, please contact us via email — our details are below.</p>
    <p>Thank you for bidding with Auction Hub Group!</p>';

    $mail->Body    = _emailTemplate('You Won — ' . $auction['item_name'], $body);
    $mail->AltBody = "Congratulations {$user['full_name']}! You won the auction for {$auction['item_name']} with a bid of R " . number_format($winningAmount, 2) . ". We will contact you within 1 business day. Visit " . APP_URL . "/user_dashboard.php for details.";

    return _sendMail($mail, 'auction_winner');
}


// ============================================================
// 9. PASSWORD RESET REQUEST
// ============================================================
function sendPasswordResetEmail(array $user, string $resetToken): bool {
    $mail = _buildMailer();
    $mail->addAddress($user['email'], $user['full_name']);
    $mail->Subject = 'Reset Your Password — Auction Hub Group';

    $resetLink = APP_URL . '/reset_password.php?token=' . urlencode($resetToken);

    $body = '
    <h2>Reset Your Password</h2>
    <p>Dear ' . e($user['full_name']) . ',</p>
    <p>We received a request to reset the password for your Auction Hub Group account.</p>
    <p>Click the button below to create a new password. This link is valid for <strong>1 hour</strong>.</p>

    <a href="' . $resetLink . '" class="cta-btn">Reset My Password</a>

    <p style="margin-top:1.5rem">Or copy and paste this link into your browser:</p>
    <p style="word-break:break-all;color:#005BA1;font-size:.9rem">' . $resetLink . '</p>

    <hr class="divider">
    <p><strong>Did not request this?</strong> You can safely ignore this email. Your password will not be changed.</p>
    <p>If you are concerned about your account security, please contact us immediately.</p>';

    $mail->Body    = _emailTemplate('Password Reset Request', $body);
    $mail->AltBody = "Hi {$user['full_name']}, reset your password here: {$resetLink} (valid 1 hour). If you did not request this, ignore this email.";

    return _sendMail($mail, 'password_reset');
}


// ============================================================
// PRE-AUCTION INVOICE EMAILS
// ============================================================

// ── A. Buyer Code — sent when commitment approved ────────────
function sendBuyerCodeEmail(array $data): bool {
    // Required keys: buyer_name, buyer_email, buyer_code, invoice_number,
    //                vehicle_description, commitment_fee, outstanding, total_price
    // Optional:      admin_note
    $mail = _buildMailer();
    $mail->addAddress($data['buyer_email'], $data['buyer_name']);
    $mail->Subject = '✅ Commitment Approved — Your Buyer Code is ' . $data['buyer_code'];

    $fee         = 'R' . number_format((float)$data['commitment_fee'], 2, '.', ',');
    $outstanding = 'R' . number_format((float)$data['outstanding'],    2, '.', ',');
    $total       = 'R' . number_format((float)$data['total_price'],    2, '.', ',');
    $noteHtml    = !empty($data['admin_note'])
        ? '<div class="highlight-box"><p><strong>Message from Auction Hub Group:</strong></p><p>' . e($data['admin_note']) . '</p></div>'
        : '';

    $body = '
    <h2 class="green">🎉 Congratulations — Your Commitment is Approved!</h2>
    <p>Dear ' . e($data['buyer_name']) . ',</p>
    <p>We are pleased to inform you that your pre-auction purchase commitment fee has been received and verified. You are now cleared to proceed with the test drive and inspection.</p>
    <div class="highlight-box" style="text-align:center;padding:20px;">
        <p style="margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#666;">Your Buyer Code</p>
        <p style="margin:0;font-size:28px;font-weight:900;color:#005BA1;letter-spacing:.15em;">' . e($data['buyer_code']) . '</p>
    </div>
    <div class="highlight-box">
        <p><strong>Invoice:</strong> ' . e($data['invoice_number']) . '</p>
        <p><strong>Vehicle:</strong> ' . e($data['vehicle_description']) . '</p>
        <p><strong>Total Price:</strong> ' . $total . '</p>
        <p><strong>Commitment Fee Paid:</strong> ' . $fee . '</p>
        <p><strong>Outstanding Balance:</strong> <span class="gold">' . $outstanding . '</span></p>
    </div>
    ' . $noteHtml . '
    <h3 style="color:#005BA1;font-size:16px;">What happens next?</h3>
    <ol style="margin:0 0 12px 20px;padding:0;font-size:14px;line-height:2;">
        <li>Present your <strong>Buyer Code (' . e($data['buyer_code']) . ')</strong> to our team upon arrival</li>
        <li>You will have <strong>1 hour 30 minutes</strong> for test drive &amp; inspection</li>
        <li>Remaining balance of <strong>' . $outstanding . '</strong> must be paid in full before vehicle handover</li>
        <li>Bring a valid ID/Passport and your proof of payment</li>
    </ol>
    <div class="highlight-box" style="background:#fff3cd;border-left-color:#f5a623;">
        <p><strong>⚠️ Important:</strong> This Buyer Code is unique to you. Do not share it with anyone. It is valid for this transaction only.</p>
    </div>
    <p>If you have any questions, please do not hesitate to contact us at
    <a href="mailto:' . COMPANY_EMAIL . '">' . COMPANY_EMAIL . '</a> or ' . COMPANY_PHONE . '.</p>';

    $mail->Body    = _emailTemplate('Pre-Auction Commitment Approved', $body);
    $mail->AltBody = "Dear {$data['buyer_name']}, your commitment has been approved. Buyer Code: {$data['buyer_code']}. Invoice: {$data['invoice_number']}. Outstanding: {$outstanding}. Contact us: " . COMPANY_EMAIL;

    return _sendMail($mail, 'buyer_code');
}


// ── B. Declination — financial institution declined the commitment ──
function sendDeclinationEmail(array $data): bool {
    // Required keys: buyer_name, buyer_email, invoice_number,
    //                vehicle_description, reason
    $mail = _buildMailer();
    $mail->addAddress($data['buyer_email'], $data['buyer_name']);
    $mail->Subject = 'Pre-Auction Commitment — Decision from the Financial Institution | ' . $data['invoice_number'];

    $body = '
    <h2 style="color:#003f73;">Pre-Auction Commitment — Official Notice</h2>

    <p>Dear ' . e($data['buyer_name']) . ',</p>

    <p>Thank you for your trust in Auction Hub Group and for taking the initiative to secure a pre-auction vehicle. We appreciate your promptness in submitting your commitment and, if applicable, your proof of payment.</p>

    <div class="highlight-box">
        <p><strong>Invoice Reference:</strong> ' . e($data['invoice_number']) . '</p>
        <p><strong>Vehicle:</strong> ' . e($data['vehicle_description']) . '</p>
    </div>

    <p>We are writing to formally advise you that the <strong>financial institution holding this vehicle has determined that the pre-auction commitment cannot be processed</strong> at this time.</p>

    <p>Auction Hub Group acts as the authorised sales agent on behalf of banks, credit providers, and asset financiers. While we facilitate the pre-auction process, the ultimate decision to approve or decline a pre-auction commitment rests with the institution that holds the vehicle\'s financial account. This decision is outside of our control and is based on the institution\'s own criteria and account status at the time of review.</p>

    <div class="highlight-box" style="background:#fff8f0;border-left-color:#e07c00;">
        <p style="margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#7a4000;font-weight:700;">
            <span style="font-size:15px;">&#9432;</span> Reason Provided by the Financial Institution
        </p>
        <p style="margin:0;color:#333;font-size:14px;line-height:1.8;">' . e($data['reason']) . '</p>
    </div>

    <h3 style="color:#005BA1;font-size:16px;margin-top:24px;">Your Commitment Fee — Refund Policy</h3>

    <p>We want to reassure you that <strong>your money is fully protected.</strong> If a commitment fee was transferred:</p>

    <ul style="margin:8px 0 16px 20px;padding:0;font-size:14px;line-height:2.1;color:#333;">
        <li>Your payment will be <strong>refunded in full</strong> within <strong>3–5 business days</strong></li>
        <li>The refund will be processed to the same account from which it was received</li>
        <li>A deduction of <strong>R102.00</strong> will apply for administration and bank processing charges</li>
        <li>If your refund does not reflect within 5 business days, please contact us immediately with your proof of payment</li>
    </ul>

    <div class="highlight-box" style="background:#e8f4fd;border-left-color:#005BA1;">
        <p style="margin:0;font-size:13px;color:#003f73;line-height:1.7;">
            <strong>We understand this is disappointing.</strong> Pre-auction deals are time-sensitive and stock-dependent, which means that occasionally a vehicle\'s availability changes after an invoice has been issued. We regret any inconvenience this causes and commit to processing your refund as a matter of priority.
        </p>
    </div>

    <h3 style="color:#005BA1;font-size:16px;margin-top:24px;">What Happens Next?</h3>

    <p>You are not excluded from future opportunities. Our inventory updates regularly, and new bank-stock vehicles become available for pre-auction purchase each week. We encourage you to:</p>

    <ul style="margin:8px 0 20px 20px;padding:0;font-size:14px;line-height:2.1;color:#333;">
        <li>Browse our current pre-auction vehicles — another suitable option may be available today</li>
        <li>Speak to one of our consultants about alternative vehicles matching your requirements</li>
    </ul>

    <a href="' . APP_URL . '/preauction.php" class="cta-btn" style="margin-bottom:8px;">Browse Pre-Auction Vehicles</a>

    <p style="margin-top:24px;font-size:14px;">For any questions about your refund, this decision, or to explore other available vehicles, please do not hesitate to reach out to us directly. Our team is available seven days a week.</p>

    <p style="font-size:14px;">
        <strong>Email:</strong> <a href="mailto:' . COMPANY_EMAIL . '">' . COMPANY_EMAIL . '</a><br>
        <strong>Phone:</strong> ' . COMPANY_PHONE . ' &nbsp;|&nbsp; ' . COMPANY_PHONE2 . '
    </p>

    <p style="font-size:13px;color:#888;border-top:1px solid #e8ecf0;padding-top:16px;margin-top:8px;line-height:1.7;">
        This notice is issued by Auction Hub Group in our capacity as authorised agent for the relevant financial institution. This communication is not a reflection of your creditworthiness or financial standing — it relates solely to the status of the specific vehicle referenced above.
    </p>';

    $mail->Body    = _emailTemplate('Pre-Auction Commitment — Financial Institution Decision', $body);
    $mail->AltBody = "Dear {$data['buyer_name']}, the financial institution holding the vehicle referenced in invoice {$data['invoice_number']} has determined that the pre-auction commitment cannot proceed. Reason: {$data['reason']}. Your commitment fee will be refunded within 3-5 business days (less R102 admin fee). We apologise for the inconvenience. Contact: " . COMPANY_EMAIL;

    return _sendMail($mail, 'declination');
}


// ── C. Admin alert — new POP uploaded for a pre-auction invoice
function sendPreAuctionPOPAdminAlert(array $invoice): bool {
    $mail = _buildMailer();
    $mail->addAddress(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    $mail->Subject = '🔔 Pre-Auction POP Submitted — ' . $invoice['buyer_name'] . ' | ' . $invoice['invoice_number'];

    $fee  = 'R' . number_format((float)$invoice['commitment_fee_amount'], 2, '.', ',');
    $body = '
    <h2>Pre-Auction Commitment Fee — Proof of Payment Received</h2>
    <p>A buyer has uploaded their proof of payment for a pre-auction purchase commitment.</p>
    <div class="highlight-box">
        <p><strong>Invoice No:</strong> ' . e($invoice['invoice_number']) . '</p>
        <p><strong>Buyer:</strong> ' . e($invoice['buyer_name']) . '</p>
        <p><strong>Email:</strong> ' . e($invoice['buyer_email'] ?? 'N/A') . '</p>
        <p><strong>Phone:</strong> ' . e($invoice['buyer_phone'] ?? 'N/A') . '</p>
        <p><strong>Vehicle:</strong> ' . e($invoice['vehicle_description']) . '</p>
        <p><strong>Commitment Fee:</strong> ' . $fee . ' (' . $invoice['commitment_fee_pct'] . '%)</p>
        <p><strong>Uploaded:</strong> ' . date('d M Y H:i') . '</p>
    </div>
    <p>Please review the proof of payment and either <strong>approve</strong> (issue Buyer Code) or <strong>decline</strong> the commitment.</p>
    <a href="' . APP_URL . '/invoice_generator.php?tab=preauction" class="cta-btn">Review in Invoice Generator</a>
    <p style="margin-top:16px;font-size:13px;color:#888;">
        ⚠️ Take action promptly — this invoice has a 24-hour validity window.
    </p>';

    $mail->Body    = _emailTemplate('Pre-Auction POP Submitted', $body);
    $mail->AltBody = "Pre-Auction POP received from {$invoice['buyer_name']} ({$invoice['buyer_email']}) for invoice {$invoice['invoice_number']}. Review at " . APP_URL . "/invoice_generator.php";

    return _sendMail($mail, 'preauction_pop_alert');
}

// ── sendRefundRequestConfirmation ─────────────────────────────
// Sent to client when they submit a refund request
function sendRefundRequestConfirmation(array $data): bool {
    $mail = _buildMailer();
    $mail->addAddress($data['email'], $data['name']);
    $mail->Subject = 'Refund Request Received — AHG Ref #' . $data['refund_id'];

    $typeLabel = match($data['reference_type']) {
        'registration'       => 'Bidder Registration Fee',
        'preauction_invoice' => 'Pre-Auction Purchase',
        default              => 'Other Payment',
    };
    $amountPaid   = 'R' . number_format($data['amount_paid'], 2);
    $refundAmount = 'R' . number_format($data['refund_amount'], 2);

    $body = '
    <p>Dear ' . e($data['name']) . ',</p>
    <p>Thank you for submitting your refund request. We have received it and it is currently <strong>under review</strong>.</p>
    <div style="background:#f4f9ff;border:1px solid #c5d8f0;border-radius:8px;padding:16px;margin:20px 0">
        <p style="margin:0 0 8px"><strong>Refund Request Summary</strong></p>
        <p><strong>Reference #:</strong> AHG-REF-' . str_pad($data['refund_id'], 5, '0', STR_PAD_LEFT) . '</p>
        <p><strong>Type:</strong> ' . $typeLabel . '</p>
        ' . ($data['reference_number'] ? '<p><strong>Invoice/Reference:</strong> ' . e($data['reference_number']) . '</p>' : '') . '
        <p><strong>Amount Paid:</strong> ' . $amountPaid . '</p>
        <p><strong>Refund Requested:</strong> ' . $refundAmount . '</p>
        <p><strong>Bank:</strong> ' . e($data['bank_name']) . ' — ' . e($data['account_holder']) . '</p>
    </div>
    <p>Our team will review your request within <strong>3–5 business days</strong>. Please note that a processing fee of <strong>R102.00</strong> applies to all approved EFT refunds.</p>
    <p>If you have any questions, please contact us via email — quoting your reference number above.</p>';

    $mail->Body    = _emailTemplate('Refund Request Received', $body);
    $mail->AltBody = "Your refund request AHG-REF-{$data['refund_id']} has been received. We will review it within 3-5 business days.";

    return _sendMail($mail, 'refund_confirmation');
}

// ── sendRefundRequestAdminAlert ───────────────────────────────
// Sent to admin when a new refund request is submitted
function sendRefundRequestAdminAlert(array $data): bool {
    $mail = _buildMailer();
    $mail->addAddress(MAIL_ADMIN, APP_NAME . ' Admin');
    $mail->Subject = '⚠️ New Refund Request #' . $data['refund_id'] . ' — ' . $data['client_name'];

    $typeLabel = match($data['reference_type']) {
        'registration'       => 'Bidder Registration Fee',
        'preauction_invoice' => 'Pre-Auction Purchase',
        default              => 'Other Payment',
    };

    $body = '
    <p>A new refund request has been submitted and requires your review.</p>
    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:16px;margin:20px 0">
        <p style="margin:0 0 8px"><strong>Request Details</strong></p>
        <p><strong>Request #:</strong> AHG-REF-' . str_pad($data['refund_id'], 5, '0', STR_PAD_LEFT) . '</p>
        <p><strong>Client:</strong> ' . e($data['client_name']) . ' (' . e($data['client_email']) . ')</p>
        <p><strong>Type:</strong> ' . $typeLabel . '</p>
        ' . ($data['reference_number'] ? '<p><strong>Invoice/Reference:</strong> ' . e($data['reference_number']) . '</p>' : '') . '
        <p><strong>Amount Paid:</strong> R' . number_format($data['amount_paid'], 2) . '</p>
        <p><strong>Refund Requested:</strong> R' . number_format($data['refund_amount'], 2) . '</p>
        <p><strong>Bank:</strong> ' . e($data['bank_name']) . '</p>
    </div>
    <a href="' . APP_URL . '/admin_dashboard.php?tab=refunds" class="cta-btn">Review in Admin Dashboard</a>';

    $mail->Body    = _emailTemplate('New Refund Request', $body);
    $mail->AltBody = "New refund request from {$data['client_name']} for R" . number_format($data['refund_amount'], 2) . ". Review at " . APP_URL . "/admin_dashboard.php?tab=refunds";

    return _sendMail($mail, 'refund_admin_alert');
}

// ── sendRefundProcessedEmail ──────────────────────────────────
// Sent to client when refund is marked as processed (EFT sent)
function sendRefundProcessedEmail(array $data): bool {
    $mail = _buildMailer();
    $mail->addAddress($data['email'], $data['name']);
    $mail->Subject = 'Your Refund Has Been Processed — AHG Ref #' . $data['refund_id'];

    $refundAmount = 'R' . number_format($data['refund_amount'], 2);

    $body = '
    <p>Dear ' . e($data['name']) . ',</p>
    <p>Great news! Your refund request has been <strong style="color:#2e7d32">approved and processed</strong>.</p>
    <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:8px;padding:16px;margin:20px 0">
        <p style="margin:0 0 8px"><strong>EFT Payment Details</strong></p>
        <p><strong>Reference #:</strong> AHG-REF-' . str_pad($data['refund_id'], 5, '0', STR_PAD_LEFT) . '</p>
        <p><strong>Amount:</strong> ' . $refundAmount . '</p>
        <p><strong>Paid to:</strong> ' . e($data['account_holder']) . ' — ' . e($data['bank_name']) . '</p>
        ' . ($data['admin_notes'] ? '<p><strong>Note:</strong> ' . e($data['admin_notes']) . '</p>' : '') . '
    </div>
    <p>The funds have been transferred via EFT. Please allow <strong>1–3 business days</strong> for the payment to reflect in your account, depending on your bank.</p>
    <p>Thank you for your patience throughout this process. We value your trust in Auction Hub Group.</p>';

    $mail->Body    = _emailTemplate('Refund Processed', $body);
    $mail->AltBody = "Your refund of {$refundAmount} (Ref #{$data['refund_id']}) has been processed via EFT. Allow 1-3 business days to reflect.";

    return _sendMail($mail, 'refund_processed');
}

// ============================================================
// CAREER APPLICATION RECEIVED — confirmation to applicant
// Sends FROM careers@auctionhubgroup.co.za
// ============================================================
function sendCareerApplicationEmail(string $toEmail, string $toName, string $position = 'Auction Hub Group'): bool {
    $mail = _buildMailer(CAREER_MAIL_FROM, 'Auction Hub Group Careers', CAREER_MAIL_USER, CAREER_MAIL_PASS);
    $mail->addAddress($toEmail, $toName);
    $mail->Subject = 'Application Received — Auction Hub Group';

    $firstName = htmlspecialchars(explode(' ', trim($toName))[0], ENT_QUOTES);
    $pos       = htmlspecialchars($position, ENT_QUOTES);

    $body = '
    <h2 style="color:#003f73;margin:0 0 16px;">Thank You for Applying, ' . $firstName . '!</h2>
    <p style="margin:0 0 14px;">We have successfully received your application for <strong>' . $pos . '</strong> at Auction Hub Group.</p>

    <div style="background:#f0f6ff;border-left:4px solid #005BA1;border-radius:0 6px 6px 0;padding:14px 18px;margin:0 0 18px;">
      <p style="margin:0 0 6px;"><strong>Position:</strong> ' . $pos . '</p>
      <p style="margin:0;"><strong>Status:</strong> <span style="color:#005BA1;font-weight:700;">Under Review</span></p>
    </div>

    <p style="margin:0 0 14px;">Our team will carefully review your CV, documents, and supporting materials.
    <strong>When your application is approved</strong>, you will receive your official
    <strong>Induction Letter and Contract</strong> to review and sign — portal access follows from there.</p>

    <p style="margin:0 0 8px;">If you have any questions, please reach out:</p>
    <p style="margin:0;">Email: <a href="mailto:careers@auctionhubgroup.co.za" style="color:#005BA1;">careers@auctionhubgroup.co.za</a></p>';

    $mail->Body    = _emailTemplate('Application Received', $body);
    $mail->AltBody = "Hi {$toName}, your application for {$position} at Auction Hub Group has been received. We will review it and be in touch. Questions? Email careers@auctionhubgroup.co.za";

    return _sendMail($mail, 'career_application');
}


// ============================================================
// NEW APPLICATION ADMIN ALERT — notifies careers@auctionhubgroup.co.za
// Triggered immediately when someone submits the career form
// ============================================================
function sendCareerApplicationAdminAlert(string $applicantName, string $applicantEmail, string $applicantPhone, string $position, int $appId): bool {
    // Send to the careers mailbox — .co.za delivery is reliable
    $mail = _buildMailer(CAREER_MAIL_FROM, 'Auction Hub Group Careers', CAREER_MAIL_USER, CAREER_MAIL_PASS);
    $mail->addAddress(CAREER_MAIL_FROM, 'AHG Careers Team');
    $mail->Subject = 'New Career Application — ' . $applicantName . ' | ' . $position;

    $dashUrl = CANONICAL_URL . '/admin_dashboard.php?tab=careers';
    $appRef  = 'AHG/' . date('Y') . '/' . str_pad($appId, 4, '0', STR_PAD_LEFT);

    $body = '
    <h2 style="color:#003f73;margin:0 0 16px;">New Career Application Received</h2>
    <p style="margin:0 0 16px;">A new application has just been submitted via the careers page.</p>

    <div style="background:#f0f6ff;border-left:4px solid #005BA1;border-radius:0 6px 6px 0;padding:16px 20px;margin:0 0 18px;">
      <p style="margin:0 0 8px;"><strong>Reference:</strong> ' . e($appRef) . '</p>
      <p style="margin:0 0 8px;"><strong>Applicant:</strong> ' . e($applicantName) . '</p>
      <p style="margin:0 0 8px;"><strong>Email:</strong> <a href="mailto:' . e($applicantEmail) . '" style="color:#005BA1;">' . e($applicantEmail) . '</a></p>
      <p style="margin:0 0 8px;"><strong>Phone:</strong> <a href="tel:' . e($applicantPhone) . '" style="color:#005BA1;">' . e($applicantPhone) . '</a></p>
      <p style="margin:0;"><strong>Position Applied For:</strong> ' . e($position) . '</p>
    </div>

    <p style="margin:0 0 18px;">Review their CV, ID document and other uploads in the admin dashboard, then update their status to <strong>Approved</strong> when ready.</p>

    <a href="' . $dashUrl . '" style="display:inline-block;background:#005BA1;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:6px;font-size:15px;">View Application in Dashboard</a>';

    $mail->Body    = _emailTemplate('New Career Application', $body);
    $mail->AltBody = "New application from {$applicantName} ({$applicantEmail}, {$applicantPhone}) for {$position}. Ref: {$appRef}. Review at: {$dashUrl}";

    return _sendMail($mail, 'career_application_admin_alert');
}


// ============================================================
// INDUCTION CONTRACT — sent to applicant immediately on submission
// Contains the formal induction letter + contract signing link
// ============================================================
function sendInductionContractEmail(string $toEmail, string $toName, string $contractToken, string $appRef): bool {
    $mail = _buildMailer(CAREER_MAIL_FROM, 'Auction Hub Group Careers', CAREER_MAIL_USER, CAREER_MAIL_PASS);
    $mail->addAddress($toEmail, $toName);
    $mail->Subject = 'You\'ve Been Shortlisted — Please Sign Your Induction Contract | AHG';

    $firstName = htmlspecialchars(explode(' ', trim($toName))[0], ENT_QUOTES);
    $signLink  = CANONICAL_URL . '/sign_contract.php?token=' . urlencode($contractToken);
    $today     = date('d F Y');

    $body = '
    <h2 style="color:#003f73;">Congratulations, ' . $firstName . '!</h2>
    <p>You have been <strong>shortlisted</strong> for the <strong>Sales Representative — Vehicle Advertising</strong> role at Auction Hub Group.</p>
    <p>Please read your Induction Letter and Contract below carefully, then click <strong>"Sign &amp; Accept Contract"</strong> at the bottom to confirm your acceptance.</p>

    <hr class="divider">

    <div style="background:#f8fafc;border:1px solid #e5eaf0;border-radius:8px;padding:24px 28px;margin:20px 0;font-size:14px;line-height:1.9;color:#334155;">
        <p style="text-align:right;color:#888;font-size:13px;margin:0 0 16px;">' . $today . '</p>
        <p style="font-weight:700;color:#003f73;margin:0 0 4px;">LETTER OF APPOINTMENT — INDUCTION PERIOD</p>
        <p style="color:#888;font-size:12px;margin:0 0 20px;">Reference: ' . htmlspecialchars($appRef) . '</p>

        <p>Dear ' . htmlspecialchars($toName, ENT_QUOTES) . ',</p>

        <p>On behalf of <strong>Auction Hub Group (Pty) Ltd</strong> ("AHG"), we are pleased to inform you that you have been <strong>accepted onto our Sales Representative Induction Programme</strong>.</p>

        <p><strong>1. Role</strong><br>
        Sales Representative — Vehicle Advertising</p>

        <p><strong>2. Induction Period</strong><br>
        Your induction commences on the date you sign this contract and continues until you have generated <strong>3 (three) qualified leads</strong> as defined in clause 4. There is no fixed end date — your performance determines the duration.</p>

        <p><strong>3. Compensation During Induction</strong><br>
        You will receive a guaranteed basic salary of <strong>R6,500 per month</strong>, paid regardless of sales, plus <strong>10.5% commission</strong> on every vehicle sold under your name and a monthly performance bonus when you exceed the average target. A Rain SIM data allowance (50GB day + 50GB night) is provided for work-related communications. Salary is paid on the 25th of each month, less statutory PAYE and UIF deductions.</p>

        <p><strong>4. Definition of a Qualified Lead</strong><br>
        A qualified lead is a prospective buyer who (a) contacts AHG directly as a result of your digital advertising activity, and (b) expresses a genuine intent to purchase a vehicle. Leads must be logged in the AHG Worker Portal on the date of contact.</p>

        <p><strong>5. Placement After Induction</strong><br>
        Upon generating your <strong>3rd confirmed qualified lead</strong>, you complete the induction programme and continue in your role on the AHG sales team. Eligibility for permanent positions, promotions, and other roles is thereafter assessed through AHG\'s standard HR process, which may include further interviews and assessments.</p>

        <p><strong>6. Daily Reporting</strong><br>
        You are required to submit a daily activity report via the AHG Worker Portal by 18:00 (6 PM) on each working day. Reports must include: (a) platforms posted on, (b) number of listings posted, (c) screenshot proof of postings, and (d) details of any leads received that day.</p>

        <p><strong>7. Conduct and Representation</strong><br>
        While representing AHG across digital platforms you must: act honestly and professionally at all times; use only AHG-approved vehicle information and images; not make representations beyond what is authorised; and maintain the confidentiality of any non-public AHG information.</p>

        <p><strong>8. POPIA Compliance</strong><br>
        All personal information you process in the course of this role is subject to the Protection of Personal Information Act 4 of 2013 ("POPIA"). You may only use personal data for the purposes of this role and must not share or retain it for any other purpose.</p>

        <p><strong>9. Acceptance</strong><br>
        By signing this contract digitally, you confirm that you have read, understood, and agree to the terms of this Induction Period appointment. This document constitutes a binding agreement between you and Auction Hub Group (Pty) Ltd.</p>

        <p style="margin-top:24px;">Yours sincerely,</p>
        <p style="font-weight:700;color:#003f73;margin:0;">Auction Hub Group (Pty) Ltd</p>
        <p style="color:#888;font-size:12px;margin:4px 0 0;">80 Sterling Rd, Kosmosdal, Centurion, 0187</p>
    </div>

    <div style="text-align:center;margin:28px 0;">
        <a href="' . $signLink . '" class="cta-btn" style="font-size:16px;padding:16px 40px;">
            ✍️ Sign &amp; Accept Contract
        </a>
        <p style="margin-top:12px;font-size:12px;color:#999;">Or copy this link into your browser:<br><span style="word-break:break-all;color:#005BA1;">' . $signLink . '</span></p>
    </div>

    <hr class="divider">
    <p style="font-size:13px;color:#888;">This link is unique to you. Do not share it. If you have any questions, reply to this email or contact us at <a href="mailto:careers@auctionhubgroup.co.za">careers@auctionhubgroup.co.za</a>.</p>';

    $mail->Body    = _emailTemplate('Induction Contract — AHG', $body);
    $mail->AltBody = "Congratulations {$toName}! You've been shortlisted. Please sign your induction contract at: {$signLink}";

    return _sendMail($mail, 'induction_contract');
}


// ============================================================
// CONTRACT SIGNED — admin alert when applicant signs contract
// ============================================================
function sendContractSignedAdminAlert(int $appId, string $name, string $email, string $phone): bool {
    $mail = _buildMailer();
    $mail->addAddress(MAIL_ADMIN, 'AHG Admin');
    $mail->Subject = '✅ Contract Signed — ' . $name . ' | Career Application #' . $appId;

    $body = '
    <h2 class="green">✅ Contract Signed — Action Required</h2>
    <p>An applicant has reviewed and signed their Induction Contract. Please review their application and create their worker account.</p>
    <div class="highlight-box">
        <p><strong>Applicant:</strong> ' . e($name) . '</p>
        <p><strong>Email:</strong> ' . e($email) . '</p>
        <p><strong>Phone:</strong> ' . e($phone) . '</p>
        <p><strong>Application ID:</strong> #' . $appId . '</p>
        <p><strong>Signed:</strong> ' . date('d M Y H:i') . '</p>
    </div>
    <p><strong>Next step:</strong> Go to the Admin Dashboard → Job Applications → find this applicant → create their Worker Account to grant portal access.</p>
    <a href="' . APP_URL . '/admin_dashboard.php?tab=careers" class="cta-btn">Review in Admin Dashboard</a>';

    $mail->Body    = _emailTemplate('Contract Signed — Action Required', $body);
    $mail->AltBody = "Contract signed by {$name} ({$email}, {$phone}). Application #{$appId}. Create their worker account at " . APP_URL . "/admin_dashboard.php?tab=careers";

    return _sendMail($mail, 'contract_signed_admin');
}


// ============================================================
// WORKER PORTAL ACCESS — sent when admin creates worker account
// ============================================================
function sendWorkerPortalAccessEmail(string $toEmail, string $toName, string $phone, string $pin, string $workerCode = ''): bool {
    $mail = _buildMailer(CAREER_MAIL_FROM, 'Auction Hub Group Careers', CAREER_MAIL_USER, CAREER_MAIL_PASS);
    $mail->addAddress($toEmail, $toName);
    $mail->Subject = '🎉 You\'re Approved! Your Worker Portal Access — Auction Hub Group';

    $firstName  = htmlspecialchars(explode(' ', trim($toName))[0], ENT_QUOTES);
    $portalUrl  = APP_URL . '/worker_portal.php';

    $body = '
    <h2 class="green">🎉 Congratulations, ' . $firstName . ' — You\'re Approved!</h2>
    <p>Your Induction Contract has been reviewed and your worker account has been activated. You can now log in to the <strong>AHG Worker Portal</strong> to submit your daily activity reports and start earning.</p>

    <div class="highlight-box" style="text-align:center;padding:20px 24px;">
        <p style="margin:0 0 14px;font-size:13px;font-weight:700;color:#005BA1;text-transform:uppercase;letter-spacing:.08em;">Your Login Details</p>
        <div style="display:inline-block;text-align:left;">
            <p style="margin:0 0 8px;font-size:15px;"><strong>Phone Number:</strong> <span style="font-size:18px;font-weight:700;color:#003f73;">' . htmlspecialchars($phone, ENT_QUOTES) . '</span></p>
            <p style="margin:0 0 8px;font-size:15px;"><strong>PIN:</strong> <span style="font-size:22px;font-weight:800;color:#005BA1;letter-spacing:.15em;">' . htmlspecialchars($pin, ENT_QUOTES) . '</span></p>
            ' . ($workerCode ? '<p style="margin:0;font-size:14px;"><strong>Worker Code:</strong> <span style="color:#005BA1;font-weight:700;">' . htmlspecialchars($workerCode, ENT_QUOTES) . '</span></p>' : '') . '
        </div>
    </div>

    <div style="background:#fff3cd;border:1px solid #ffe082;border-radius:8px;padding:14px 18px;margin:16px 0;font-size:13px;color:#7d5a00;">
        <strong>⚠️ Keep these details safe.</strong> Do not share your PIN with anyone. If you suspect your account has been compromised, contact us immediately.
    </div>

    <h3 style="color:#005BA1;font-size:16px;margin-bottom:10px;">Your Induction Target — Reminder</h3>
    <p>Generate <strong>3 qualified leads</strong> to complete your induction and secure your place on the AHG sales team. Permanent positions and promotions follow through our standard HR process.</p>
    <ul style="font-size:14px;line-height:2.1;padding-left:20px;color:#334155;">
        <li>Log in to the portal daily and submit your activity report</li>
        <li>Post repossessed vehicles on Gumtree, Facebook Marketplace, Facebook Buy &amp; Sell Groups, Ads Africa, Carzar &amp; Junk Mail</li>
        <li>Log every qualified lead you receive on the same day</li>
        <li>3 leads = induction complete &amp; your place on the team secured</li>
    </ul>

    <a href="' . $portalUrl . '" class="cta-btn">Log In to Worker Portal</a>

    <hr class="divider">
    <p style="font-size:13px;color:#888;">Questions? Contact us at <a href="mailto:careers@auctionhubgroup.co.za">careers@auctionhubgroup.co.za</a>.</p>';

    $mail->Body    = _emailTemplate('Worker Portal Access — AHG', $body);
    $mail->AltBody = "Congratulations {$toName}! Your worker account is active. Login: Phone {$phone}, PIN {$pin}. Portal: {$portalUrl}";

    return _sendMail($mail, 'worker_portal_access');
}


// ── sendRefundRejectedEmail ───────────────────────────────────
// Sent to client when refund request is rejected
function sendRefundRejectedEmail(array $data): bool {
    $mail = _buildMailer();
    $mail->addAddress($data['email'], $data['name']);
    $mail->Subject = 'Update on Your Refund Request — AHG Ref #' . $data['refund_id'];

    $refundAmount = 'R' . number_format($data['refund_amount'], 2);

    $body = '
    <p>Dear ' . e($data['name']) . ',</p>
    <p>We have reviewed your refund request and regret to inform you that it could <strong>not be approved</strong> at this time.</p>
    <div style="background:#fce4ec;border:1px solid #ef9a9a;border-radius:8px;padding:16px;margin:20px 0">
        <p style="margin:0 0 8px"><strong>Request Details</strong></p>
        <p><strong>Reference #:</strong> AHG-REF-' . str_pad($data['refund_id'], 5, '0', STR_PAD_LEFT) . '</p>
        <p><strong>Amount Requested:</strong> ' . $refundAmount . '</p>
        <p><strong>Reason:</strong> ' . e($data['rejection_reason']) . '</p>
    </div>
    <p>If you believe this decision was made in error or have additional information to support your claim, please do not hesitate to contact us. We are committed to resolving any issues fairly and transparently.</p>
    <p>You are also welcome to submit a new refund request with any additional supporting documentation.</p>';

    $mail->Body    = _emailTemplate('Refund Request Update', $body);
    $mail->AltBody = "Your refund request Ref #{$data['refund_id']} could not be approved. Reason: {$data['rejection_reason']}. Contact us if you have questions.";

    return _sendMail($mail, 'refund_rejected');
}


// ============================================================
// DIRECT-ACCESS DIAGNOSTIC — only runs when this file is opened
// directly in a browser (not when included by another script).
// Visit /email_helper.php to run a full config + SMTP check.
// ============================================================
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);

    // Config must be loaded for the mailer to work
    if (!defined('MAIL_HOST')) {
        require_once __DIR__ . '/config.php';
    }

    $sendTest  = isset($_POST['send_test']);
    $testTo    = trim($_POST['test_to'] ?? '');
    $testResult = null;

    if ($sendTest && $testTo) {
        try {
            $mail = _buildMailer();
            $mail->addAddress($testTo);
            $mail->Subject = 'AHG Email Diagnostic Test';
            $body = '<h2 style="color:#003f73;">Test Email — Success</h2>
                     <p>This test was sent from <strong>' . e(MAIL_HOST) . '</strong> via port ' . MAIL_PORT . ' (SSL).</p>
                     <p>Sent at: ' . date('Y-m-d H:i:s') . '</p>';
            $mail->Body    = _emailTemplate('AHG Email Test', $body);
            $mail->AltBody = 'AHG email test sent at ' . date('Y-m-d H:i:s') . ' via ' . MAIL_HOST;
            $mail->send();
            $testResult = ['ok' => true, 'msg' => 'Email sent successfully to ' . htmlspecialchars($testTo)];
        } catch (\Throwable $ex) {
            $testResult = ['ok' => false, 'msg' => htmlspecialchars($ex->getMessage())];
        }
    }

    // Mask password for display
    function _mask(string $s): string {
        return strlen($s) <= 4 ? '***' : substr($s, 0, 2) . str_repeat('*', strlen($s) - 4) . substr($s, -2);
    }

    $checks = [
        ['MAIL_HOST',       defined('MAIL_HOST')       ? MAIL_HOST       : '❌ NOT DEFINED'],
        ['MAIL_PORT',       defined('MAIL_PORT')        ? MAIL_PORT       : '❌ NOT DEFINED'],
        ['MAIL_USERNAME',   defined('MAIL_USERNAME')    ? MAIL_USERNAME   : '❌ NOT DEFINED'],
        ['MAIL_PASSWORD',   defined('MAIL_PASSWORD')    ? _mask(MAIL_PASSWORD) : '❌ NOT DEFINED'],
        ['MAIL_FROM_EMAIL', defined('MAIL_FROM_EMAIL')  ? MAIL_FROM_EMAIL : '❌ NOT DEFINED'],
        ['CAREER_MAIL_USER',defined('CAREER_MAIL_USER') ? CAREER_MAIL_USER: '❌ NOT DEFINED'],
        ['CAREER_MAIL_PASS',defined('CAREER_MAIL_PASS') ? _mask(CAREER_MAIL_PASS) : '❌ NOT DEFINED'],
        ['EMAIL_ENABLED',   defined('EMAIL_ENABLED')    ? (EMAIL_ENABLED ? 'true' : 'false') : '❌ NOT DEFINED'],
        ['PHP version',     PHP_VERSION],
        ['PHPMailer',       class_exists('PHPMailer\\PHPMailer\\PHPMailer') ? '✅ Loaded' : '❌ Not found — check vendor/autoload.php'],
    ];

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>AHG Email Diagnostic</title>
    <style>
      body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:24px;}
      .card{background:#fff;border-radius:8px;padding:28px 32px;max-width:680px;margin:0 auto 24px;box-shadow:0 2px 10px rgba(0,0,0,.08);}
      h1{color:#003f73;margin:0 0 4px;font-size:20px;}
      table{width:100%;border-collapse:collapse;margin-top:16px;}
      th,td{text-align:left;padding:9px 12px;border-bottom:1px solid #e5eaf0;font-size:14px;}
      th{background:#f0f6ff;color:#003f73;font-weight:700;}
      .ok{color:#1a7f37;font-weight:700;} .err{color:#c62828;font-weight:700;}
      .btn{background:#005BA1;color:#fff;border:none;padding:11px 26px;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer;}
      input[type=email]{padding:9px 12px;border:1.5px solid #d0dae8;border-radius:6px;font-size:14px;width:60%;margin-right:10px;}
      .result{margin-top:14px;padding:12px 16px;border-radius:6px;font-size:14px;font-weight:600;}
    </style></head><body>';

    echo '<div class="card">
      <h1>AHG Email Diagnostic</h1>
      <p style="color:#666;margin:4px 0 0;font-size:13px;">Configuration check for <strong>' . htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') . '</strong></p>
      <table><tr><th>Setting</th><th>Value</th></tr>';
    foreach ($checks as [$key, $val]) {
        $cls = (str_contains((string)$val, '❌')) ? 'err' : 'ok';
        echo '<tr><td>' . htmlspecialchars($key) . '</td><td class="' . $cls . '">' . $val . '</td></tr>';
    }
    echo '</table></div>';

    echo '<div class="card">
      <h2 style="color:#003f73;margin:0 0 14px;font-size:17px;">Send a Test Email</h2>
      <form method="POST">
        <input type="email" name="test_to" placeholder="recipient@gmail.com" value="' . htmlspecialchars($testTo) . '" required>
        <button class="btn" type="submit" name="send_test">Send Test</button>
      </form>';
    if ($testResult) {
        $bg = $testResult['ok'] ? '#e6f9ee' : '#fde8e8';
        $co = $testResult['ok'] ? '#1a7f37' : '#c62828';
        echo '<div class="result" style="background:' . $bg . ';color:' . $co . ';">'
           . ($testResult['ok'] ? '✅ ' : '❌ ') . $testResult['msg'] . '</div>';
    }
    echo '</div></body></html>';
    exit;
}
