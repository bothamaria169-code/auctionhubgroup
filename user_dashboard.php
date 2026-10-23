<?php
// ============================================================
// AUCTION HUB GROUP — user_dashboard.php
// Logged-in user's personal hub
// Tabs: Overview · My Bids · Register to Bid · My Profile
// ============================================================

require_once 'config.php';
require_once 'auth.php';

// ── Auth Guard ────────────────────────────────────────────────
$token = getTokenFromRequest();
if (!$token) {
    redirect('login.php', 'Please log in to access your dashboard.', 'info');
}

$currentUser = verifyJWT($token);
if (!$currentUser) {
    clearTokenCookie();
    redirect('login.php', 'Your session has expired. Please log in again.', 'info');
}

// Always fetch fresh user data from DB (bidder_status may have changed)
$stmt = $pdo->prepare("
    SELECT u.*,
           br.status          AS reg_status,
           br.submitted_at    AS pop_submitted_at,
           br.pop_file_path   AS reg_pop_path,
           br.pop_file_type   AS reg_pop_type,
           br.admin_notes     AS reg_admin_notes,
           br.reviewed_at     AS reg_reviewed_at,
           br.assigned_bidder_number AS reg_bidder_number
    FROM users u
    LEFT JOIN bidder_registrations br ON br.user_id = u.id AND br.status != 'rejected'
    ORDER BY br.submitted_at DESC
    LIMIT 1
");
// Re-query properly
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$currentUser['sub']]);
$user = $stmt->fetch();

if (!$user || $user['status'] === 'suspended') {
    clearTokenCookie();
    redirect('login.php', 'Your account has been suspended. Please contact us.', 'error');
}

syncSession($currentUser);

// ── Fetch bidder registration record ─────────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM bidder_registrations
    WHERE user_id = ?
    ORDER BY submitted_at DESC
    LIMIT 1
");
$stmt->execute([$user['id']]);
$bidderReg = $stmt->fetch();

// ── Fetch bid history ─────────────────────────────────────────
// COALESCE handles both old schema (amount/created_at) and new schema (bid_amount/bid_time)
$stmt = $pdo->prepare("
    SELECT b.*,
           COALESCE(b.bid_amount, b.amount)     AS bid_amount,
           COALESCE(b.bid_time,   b.created_at) AS bid_time,
           a.item_name, a.status AS auction_status,
           a.current_price, a.end_time, a.image_url,
           a.reserve_price,
           (SELECT MAX(COALESCE(bid_amount, amount)) FROM bids WHERE auction_id = b.auction_id) AS top_bid
    FROM bids b
    JOIN auctions a ON a.id = b.auction_id
    WHERE b.user_id = ?
    ORDER BY b.id DESC
    LIMIT 50
");
$stmt->execute([$user['id']]);
$myBids = $stmt->fetchAll();

// ── Dashboard stats ──────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bids WHERE user_id = ?");
$stmt->execute([$user['id']]);
$totalBids = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT b.auction_id)
    FROM bids b
    JOIN auctions a ON a.id = b.auction_id
    WHERE b.user_id = ? AND a.status = 'active'
");
$stmt->execute([$user['id']]);
$activeAuctions = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT b.auction_id)
    FROM bids b
    JOIN auctions a ON a.id = b.auction_id
    WHERE b.user_id = ? AND b.is_winning = 1 AND a.status = 'active'
");
$stmt->execute([$user['id']]);
$currentlyWinning = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT b.auction_id)
    FROM bids b
    JOIN auctions a ON a.id = b.auction_id
    WHERE b.user_id = ? AND b.is_winning = 1 AND a.status = 'closed'
");
$stmt->execute([$user['id']]);
$auctionsWon = $stmt->fetchColumn();

// ── EFT reference for this user ───────────────────────────────
$eftRef = eftReference($user['id']); // AHG-{id}-REG

// ── Status step helper ────────────────────────────────────────
function getStatusStep(string $status): int {
    return match($status) {
        'registered'       => 1,
        'pending_payment'  => 1,
        'pending_approval' => 2,
        'verified_bidder'  => 4,
        'suspended'        => 0,
        default            => 1,
    };
}
$statusStep = getStatusStep($user['bidder_status']);

// ── Active tab from URL ───────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'overview';
$validTabs = ['overview', 'bids', 'register', 'profile', 'refunds'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'overview';

// ── Fetch user refund requests (only when needed) ─────────────
$myRefunds = [];
if ($activeTab === 'refunds') {
    $stmt = $pdo->prepare("SELECT * FROM refund_requests WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    $myRefunds = $stmt->fetchAll();
}

// ── Flash message ─────────────────────────────────────────────
$flash = getFlash();

$pageTitle = 'My Dashboard';
$pageDescription = 'Manage your bids, registration, and profile.';
require_once 'header.php';
?>

<div class="dashboard-wrapper">

  <!-- ── Sidebar ─────────────────────────────────────────── -->
  <aside class="dashboard-sidebar">
    <div class="sidebar-user">
      <div class="sidebar-avatar">
        <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
      </div>
      <div class="sidebar-user-info">
        <strong><?= e($user['full_name']) ?></strong>
        <small><?= e($user['email']) ?></small>
      </div>
    </div>

    <?php if ($user['bidder_status'] === 'verified_bidder'): ?>
    <div class="sidebar-bidder-badge">
      <i class="fas fa-id-card"></i>
      <div>
        <small>Bidder Number</small>
        <strong><?= e($user['bidder_number']) ?></strong>
      </div>
    </div>
    <?php endif; ?>

    <nav class="sidebar-nav">
      <a href="?tab=overview"  class="sidebar-link <?= $activeTab === 'overview'  ? 'active' : '' ?>">
        <i class="fas fa-th-large"></i> Overview
      </a>
      <a href="?tab=bids"      class="sidebar-link <?= $activeTab === 'bids'      ? 'active' : '' ?>">
        <i class="fas fa-gavel"></i> My Bids
        <?php if ($totalBids > 0): ?>
          <span class="sidebar-badge"><?= $totalBids ?></span>
        <?php endif; ?>
      </a>
      <?php if ($user['bidder_status'] !== 'verified_bidder'): ?>
      <a href="?tab=register"  class="sidebar-link <?= $activeTab === 'register'  ? 'active' : '' ?> sidebar-link-highlight">
        <i class="fas fa-star"></i> Register to Bid
        <?php if ($user['bidder_status'] === 'pending_approval'): ?>
          <span class="sidebar-badge sidebar-badge-amber">Pending</span>
        <?php else: ?>
          <span class="sidebar-badge sidebar-badge-blue">Action</span>
        <?php endif; ?>
      </a>
      <?php endif; ?>
      <a href="?tab=profile"   class="sidebar-link <?= $activeTab === 'profile'   ? 'active' : '' ?>">
        <i class="fas fa-user-cog"></i> My Profile
      </a>
      <a href="?tab=refunds"  class="sidebar-link <?= $activeTab === 'refunds'  ? 'active' : '' ?>">
        <i class="fas fa-rotate-left"></i> Refund Requests
      </a>
      <hr class="sidebar-divider">
      <a href="auctions.php"   class="sidebar-link">
        <i class="fas fa-car"></i> Browse Auctions
      </a>
      <a href="#" class="sidebar-link sidebar-link-logout" id="logoutBtn" onclick="document.getElementById('userLogoutModal').style.display='flex';return false;">
        <i class="fas fa-sign-out-alt"></i> Log Out
      </a>
    </nav>
  </aside>

  <!-- ── Main Content ────────────────────────────────────── -->
  <main class="dashboard-main">

    <?php if ($flash['message']): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?> alert-dismissible">
      <?= e($flash['message']) ?>
      <button class="alert-close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════
         TAB: OVERVIEW
    ═══════════════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'overview'): ?>

    <div class="dashboard-header">
      <div>
        <h1>Welcome back, <?= e(explode(' ', $user['full_name'])[0]) ?>!</h1>
        <p class="text-muted">Here's your bidding activity at a glance.</p>
      </div>
      <a href="auctions.php" class="btn btn-primary">
        <i class="fas fa-gavel"></i> Browse Auctions
      </a>
    </div>

    <!-- Bidder Status Banner -->
    <?php if ($user['bidder_status'] !== 'verified_bidder'): ?>
    <div class="status-banner status-banner-<?= $user['bidder_status'] ?>">
      <div class="status-banner-icon">
        <?php if ($user['bidder_status'] === 'pending_approval'): ?>
          <i class="fas fa-clock"></i>
        <?php elseif ($user['bidder_status'] === 'pending_payment'): ?>
          <i class="fas fa-credit-card"></i>
        <?php else: ?>
          <i class="fas fa-exclamation-circle"></i>
        <?php endif; ?>
      </div>
      <div class="status-banner-content">
        <?php if ($user['bidder_status'] === 'pending_approval'): ?>
          <strong>Registration Under Review</strong>
          <p>Your proof of payment has been received. Our team will review it within 1–2 business days and assign your bidder number.</p>
        <?php elseif ($user['bidder_status'] === 'pending_payment'): ?>
          <strong>Payment Required</strong>
          <p>Please pay the R10,000 registration fee via EFT and upload your proof of payment to start bidding.</p>
        <?php else: ?>
          <strong>Complete Your Registration to Bid</strong>
          <p>You need to pay the R10,000 registration fee to participate in live auctions.</p>
        <?php endif; ?>
      </div>
      <a href="?tab=register" class="btn btn-outline btn-sm">
        <?= $user['bidder_status'] === 'pending_approval' ? 'View Status' : 'Complete Registration' ?>
      </a>
    </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="grid-4 dashboard-stats">
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--blue-light);color:var(--blue-primary)">
          <i class="fas fa-gavel"></i>
        </div>
        <div class="stat-value"><?= $totalBids ?></div>
        <div class="stat-label">Total Bids Placed</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fff3e0;color:#f57c00">
          <i class="fas fa-fire"></i>
        </div>
        <div class="stat-value"><?= $activeAuctions ?></div>
        <div class="stat-label">Active Auctions</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9;color:#388e3c">
          <i class="fas fa-trophy"></i>
        </div>
        <div class="stat-value"><?= $currentlyWinning ?></div>
        <div class="stat-label">Currently Winning</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#e3f2fd;color:#1565c0">
          <i class="fas fa-star"></i>
        </div>
        <div class="stat-value"><?= $auctionsWon ?></div>
        <div class="stat-label">Auctions Won</div>
      </div>
    </div>

    <!-- Recent Bids Preview -->
    <?php if (!empty($myBids)): ?>
    <div class="card">
      <div class="card-header flex-between">
        <h3><i class="fas fa-history"></i> Recent Bids</h3>
        <a href="?tab=bids" class="btn btn-outline btn-sm">View All</a>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Vehicle</th>
              <th>Your Bid</th>
              <th>Current Price</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach (array_slice($myBids, 0, 5) as $bid): ?>
            <?php
              $isWinning = $bid['is_winning'] && $bid['auction_status'] === 'active';
              $isWon     = $bid['is_winning'] && $bid['auction_status'] === 'closed';
              $isOutbid  = !$bid['is_winning'] && $bid['auction_status'] === 'active';
              $isClosed  = $bid['auction_status'] === 'closed';
            ?>
            <tr>
              <td>
                <div class="bid-vehicle-info">
                  <?php if ($bid['image_url']): ?>
                    <img src="<?= e(cldImg($bid['image_url'], CLD_THUMB)) ?>" alt="" class="bid-thumb" onerror="this.style.display='none'">
                  <?php endif; ?>
                  <a href="auction.php?id=<?= $bid['auction_id'] ?>">
                    <?= e($bid['item_name']) ?>
                  </a>
                </div>
              </td>
              <td><strong><?= formatRand($bid['bid_amount']) ?></strong></td>
              <td><?= formatRand($bid['current_price']) ?></td>
              <td>
                <?php if ($isWon): ?>
                  <span class="badge badge-success"><i class="fas fa-trophy"></i> Won</span>
                <?php elseif ($isWinning): ?>
                  <span class="badge badge-winning"><i class="fas fa-star"></i> Winning</span>
                <?php elseif ($isOutbid): ?>
                  <span class="badge badge-outbid"><i class="fas fa-arrow-down"></i> Outbid</span>
                <?php elseif ($isClosed): ?>
                  <span class="badge badge-closed">Closed</span>
                <?php endif; ?>
              </td>
              <td>
                <a href="auction.php?id=<?= $bid['auction_id'] ?>" class="btn btn-outline btn-sm">
                  <?= $bid['auction_status'] === 'active' ? 'Bid Again' : 'View' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php else: ?>
    <div class="card empty-state">
      <i class="fas fa-gavel fa-3x text-muted"></i>
      <h3>No bids yet</h3>
      <p class="text-muted">You haven't placed any bids yet. Browse our active auctions to get started.</p>
      <a href="auctions.php" class="btn btn-primary">Browse Auctions</a>
    </div>
    <?php endif; ?>

    <!-- Active Auctions Quick Links -->
    <?php
    $stmt = $pdo->query("
        SELECT a.id, a.item_name, a.current_price, a.end_time, a.image_url, a.status
        FROM auctions a
        WHERE a.status = 'active'
        ORDER BY a.end_time ASC
        LIMIT 3
    ");
    $liveAuctions = $stmt->fetchAll();
    if (!empty($liveAuctions)): ?>
    <div class="card">
      <div class="card-header flex-between">
        <h3><i class="fas fa-fire" style="color:var(--danger)"></i> Live Auctions Right Now</h3>
        <a href="auctions.php" class="btn btn-outline btn-sm">View All</a>
      </div>
      <div class="grid-3">
        <?php foreach ($liveAuctions as $a): ?>
        <a href="auction.php?id=<?= $a['id'] ?>" class="mini-auction-card">
          <?php if ($a['image_url']): ?>
            <img src="<?= e(cldImg($a['image_url'], CLD_CARD)) ?>" alt="<?= e($a['item_name']) ?>" loading="lazy">
          <?php else: ?>
            <div class="mini-auction-placeholder"><i class="fas fa-car"></i></div>
          <?php endif; ?>
          <div class="mini-auction-info">
            <div class="mini-auction-title"><?= e($a['item_name']) ?></div>
            <div class="mini-auction-price"><?= formatRand($a['current_price']) ?></div>
            <div class="mini-auction-timer" data-countdown="<?= e($a['end_time']) ?>">
              <i class="fas fa-clock"></i> <span>Loading...</span>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════════════════════
         TAB: MY BIDS
    ═══════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'bids'): ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-gavel"></i> My Bids</h1>
        <p class="text-muted"><?= $totalBids ?> bid<?= $totalBids !== 1 ? 's' : '' ?> placed across all auctions.</p>
      </div>
      <a href="auctions.php" class="btn btn-primary">
        <i class="fas fa-search"></i> Find More Auctions
      </a>
    </div>

    <?php if (!empty($myBids)): ?>

    <!-- Filter tabs -->
    <div class="tab-nav tab-nav-pill" style="margin-bottom:1.5rem">
      <button class="tab-btn active" data-filter="all">All Bids</button>
      <button class="tab-btn" data-filter="winning">Winning</button>
      <button class="tab-btn" data-filter="outbid">Outbid</button>
      <button class="tab-btn" data-filter="won">Won</button>
      <button class="tab-btn" data-filter="closed">Closed</button>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table" id="bidsTable">
          <thead>
            <tr>
              <th>Vehicle</th>
              <th>My Bid</th>
              <th>Top Bid</th>
              <th>Reserve</th>
              <th>Auction Ends</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($myBids as $bid):
            $isWinning = $bid['is_winning'] && $bid['auction_status'] === 'active';
            $isWon     = $bid['is_winning'] && $bid['auction_status'] === 'closed';
            $isOutbid  = !$bid['is_winning'] && $bid['auction_status'] === 'active';
            $isClosed  = $bid['auction_status'] === 'closed' && !$bid['is_winning'];

            if ($isWon)     $filterClass = 'won';
            elseif ($isWinning) $filterClass = 'winning';
            elseif ($isOutbid)  $filterClass = 'outbid';
            else                $filterClass = 'closed';
          ?>
          <tr class="bid-row" data-filter="<?= $filterClass ?>">
            <td>
              <div class="bid-vehicle-info">
                <?php if ($bid['image_url']): ?>
                  <img src="<?= e(cldImg($bid['image_url'], CLD_THUMB)) ?>" alt="" class="bid-thumb" onerror="this.style.display='none'">
                <?php else: ?>
                  <div class="bid-thumb bid-thumb-placeholder"><i class="fas fa-car"></i></div>
                <?php endif; ?>
                <div>
                  <a href="auction.php?id=<?= $bid['auction_id'] ?>">
                    <strong><?= e($bid['item_name']) ?></strong>
                  </a>
                  <small class="d-block text-muted">
                    Bid placed <?= safeDate('d M Y H:i', $bid['bid_time']) ?>
                  </small>
                </div>
              </div>
            </td>
            <td><strong><?= formatRand($bid['bid_amount']) ?></strong></td>
            <td><?= $bid['top_bid'] !== null ? formatRand($bid['top_bid']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $bid['reserve_price'] !== null ? formatRand($bid['reserve_price']) : '<span class="text-muted">N/A</span>' ?></td>
            <td>
              <?php if ($bid['auction_status'] === 'active'): ?>
                <span class="bid-timer" data-countdown="<?= e($bid['end_time']) ?>">
                  <i class="fas fa-clock"></i> <span>Loading...</span>
                </span>
              <?php else: ?>
                <span class="text-muted"><?= safeDate('d M Y', $bid['end_time']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isWon): ?>
                <span class="badge badge-success"><i class="fas fa-trophy"></i> Won!</span>
              <?php elseif ($isWinning): ?>
                <span class="badge badge-winning"><i class="fas fa-crown"></i> Winning</span>
              <?php elseif ($isOutbid): ?>
                <span class="badge badge-outbid"><i class="fas fa-arrow-down"></i> Outbid</span>
              <?php elseif ($isClosed): ?>
                <span class="badge badge-closed">Closed</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="auction.php?id=<?= $bid['auction_id'] ?>"
                 class="btn btn-<?= $bid['auction_status'] === 'active' ? 'primary' : 'outline' ?> btn-sm">
                <?= $bid['auction_status'] === 'active' ? '<i class="fas fa-gavel"></i> Bid' : 'View' ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php else: ?>
    <div class="card empty-state">
      <i class="fas fa-gavel fa-3x text-muted"></i>
      <h3>No bids placed yet</h3>
      <p class="text-muted">
        <?php if ($user['bidder_status'] !== 'verified_bidder'): ?>
          You need to complete your bidder registration before you can place bids.
          <a href="?tab=register">Complete registration →</a>
        <?php else: ?>
          Browse our active auctions and place your first bid!
        <?php endif; ?>
      </p>
      <a href="auctions.php" class="btn btn-primary">Browse Auctions</a>
    </div>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════════════════════
         TAB: REGISTER TO BID
    ═══════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'register'): ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-id-card"></i> Register to Bid</h1>
        <p class="text-muted">Complete your registration to participate in live auctions.</p>
      </div>
    </div>

    <?php if ($user['bidder_status'] === 'verified_bidder'): ?>
    <div class="card" style="text-align:center;padding:3rem">
      <div style="font-size:4rem;color:var(--success);margin-bottom:1rem"><i class="fas fa-check-circle"></i></div>
      <h2>You're a Verified Bidder!</h2>
      <p class="text-muted">Your registration is approved. Your bidder number is:</p>
      <div class="bidder-number-display">
        <?= e($user['bidder_number']) ?>
        <button class="btn btn-outline btn-sm copy-btn" data-copy="<?= e($user['bidder_number']) ?>" style="margin-left:.5rem"><i class="fas fa-copy"></i></button>
      </div>
      <a href="auctions.php" class="btn btn-primary" style="margin-top:1.5rem"><i class="fas fa-gavel"></i> Start Bidding</a>
    </div>

    <?php else: ?>

    <div class="card">
      <h3 style="margin-bottom:1.5rem">Registration Progress</h3>
      <div class="status-timeline">
        <div class="timeline-step completed">
          <div class="timeline-icon"><i class="fas fa-user-plus"></i></div>
          <div class="timeline-content">
            <strong>Account Created</strong>
            <small>Registered on <?= safeDate('d M Y', $user['created_at']) ?></small>
          </div>
        </div>
        <div class="timeline-connector <?= $statusStep >= 2 ? 'completed' : '' ?>"></div>
        <div class="timeline-step <?= $statusStep >= 2 ? 'completed' : 'pending' ?>">
          <div class="timeline-icon"><?= $statusStep >= 2 ? '<i class="fas fa-check"></i>' : '<i class="fas fa-credit-card"></i>' ?></div>
          <div class="timeline-content">
            <strong>EFT Payment &amp; POP Upload</strong>
            <small><?= $statusStep >= 2 && $user['pop_uploaded_at'] ? 'Submitted '.safeDate('d M Y', $user['pop_uploaded_at']) : 'Pay R10,000 via EFT and upload proof of payment' ?></small>
          </div>
        </div>
        <div class="timeline-connector <?= $statusStep >= 3 ? 'completed' : '' ?>"></div>
        <div class="timeline-step <?= $statusStep === 2 ? 'current' : ($statusStep >= 3 ? 'completed' : '') ?>">
          <div class="timeline-icon"><i class="fas fa-search"></i></div>
          <div class="timeline-content">
            <strong>Under Review</strong>
            <small>Admin verifies your payment — usually 1–2 business days</small>
          </div>
        </div>
        <div class="timeline-connector <?= $statusStep >= 4 ? 'completed' : '' ?>"></div>
        <div class="timeline-step <?= $statusStep >= 4 ? 'completed' : '' ?>">
          <div class="timeline-icon"><i class="fas fa-id-badge"></i></div>
          <div class="timeline-content">
            <strong>Bidder Number Assigned</strong>
            <small>You'll receive your AHG bidder number via email</small>
          </div>
        </div>
      </div>
    </div>

    <?php if ($user['bidder_status'] === 'pending_approval'): ?>
    <div class="card" style="border-left:4px solid var(--warning)">
      <div style="display:flex;align-items:center;gap:1rem">
        <div style="font-size:2.5rem;color:var(--warning)"><i class="fas fa-clock"></i></div>
        <div>
          <h3 style="margin:0">Payment Under Review</h3>
          <p class="text-muted" style="margin:.25rem 0 0">Received on <strong><?= $user['pop_uploaded_at'] ? safeDate('d M Y \a\t H:i', $user['pop_uploaded_at'], 'recently') : 'recently' ?></strong>. Our team will review it within 1–2 business days.</p>
        </div>
      </div>
      <?php if ($user['pop_file_path']): ?>
      <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border-color)">
        <small class="text-muted">Proof of payment uploaded:</small>
        <a href="<?= e($user['pop_file_path']) ?>" target="_blank" class="btn btn-outline btn-sm" style="margin-left:.5rem"><i class="fas fa-file"></i> View POP</a>
      </div>
      <?php endif; ?>
      <p class="text-muted" style="margin-top:1rem;font-size:.85rem"><i class="fas fa-info-circle"></i> Questions? <a href="mailto:<?= COMPANY_EMAIL ?>"><?= COMPANY_EMAIL ?></a> | <a href="tel:<?= str_replace(' ','',COMPANY_PHONE) ?>"><?= COMPANY_PHONE ?></a></p>
    </div>

    <?php else: ?>

    <div class="card bank-details-card">
      <h3><i class="fas fa-university"></i> Step 1 — Request Banking Details &amp; Pay Registration Fee</h3>
      <p class="text-muted">To protect our banking information, please request the EFT details below. We will email them to you immediately.</p>
      <div class="bank-details-grid">
        <div class="bank-detail-row"><span class="bank-label">Amount</span><span class="bank-value"><strong><?= REG_FEE_LABEL ?></strong></span></div>
        <div class="bank-detail-row bank-detail-highlight">
          <span class="bank-label">Your Payment Reference</span>
          <span class="bank-value bank-value-ref"><strong><?= $eftRef ?></strong> <button class="copy-btn" data-copy="<?= $eftRef ?>"><i class="fas fa-copy"></i></button></span>
        </div>
      </div>
      <div id="bankDetailsRequestResult" style="margin-top:1rem"></div>
      <button type="button" id="requestBankDetailsBtn" class="btn btn-primary" style="margin-top:1rem">
        <i class="fas fa-envelope"></i> Request Banking Details via Email
      </button>
      <p class="text-muted" style="margin-top:1rem;font-size:.85rem"><i class="fas fa-exclamation-triangle" style="color:var(--warning)"></i> Use <strong><?= $eftRef ?></strong> as your exact payment reference when making payment.</p>
    </div>

    <div class="card">
      <h3><i class="fas fa-upload"></i> Step 2 — Upload Proof of Payment</h3>
      <p class="text-muted">Once you've made the payment, upload your bank confirmation or screenshot below.</p>
      <div id="popUploadResult"></div>
      <form id="popUploadForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_pop">
        <div class="form-group">
          <label>Payment Reference Used</label>
          <input type="text" name="bank_reference" value="<?= $eftRef ?>" class="form-control">
        </div>
        <div class="upload-drop-zone" id="popDropZone" style="position:relative">
          <div class="upload-drop-icon"><i class="fas fa-cloud-upload-alt"></i></div>
          <div class="upload-drop-text"><strong>Drag &amp; drop your proof of payment here</strong><br><span class="text-muted">or click to browse files</span></div>
          <div class="upload-drop-hint">Accepted: JPG, PNG, PDF — Max 10MB</div>
          <input type="file" id="popFile" name="pop_file" accept=".jpg,.jpeg,.png,.pdf" style="position:absolute;opacity:0;inset:0;cursor:pointer">
        </div>
        <div id="popPreview" style="display:none;margin-top:1rem">
          <img id="popPreviewImg" src="" alt="" style="max-height:200px;border-radius:8px">
          <div id="popPreviewName" class="text-muted" style="margin-top:.5rem;font-size:.85rem"></div>
        </div>
        <div style="margin-top:1.5rem">
          <button type="submit" class="btn btn-primary btn-lg" id="popSubmitBtn"><i class="fas fa-paper-plane"></i> Submit Proof of Payment</button>
          <p class="text-muted" style="margin-top:.75rem;font-size:.85rem"><i class="fas fa-shield-alt"></i> Your file is stored securely and only visible to our admin team.</p>
        </div>
      </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════════════════════
         TAB: MY PROFILE
    ═══════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'profile'): ?>

    <div class="dashboard-header">
      <div>
        <h1><i class="fas fa-user-cog"></i> My Profile</h1>
        <p class="text-muted">Update your contact details and password.</p>
      </div>
    </div>

    <div class="grid-2" style="align-items:start">
      <div class="card">
        <h3>Personal Details</h3>
        <div id="profileResult"></div>
        <form id="profileForm">
          <input type="hidden" name="action" value="update_profile">
          <div class="form-group"><label>Full Name</label><input type="text" class="form-control" value="<?= e($user['full_name']) ?>" disabled><small class="text-muted">Contact us to change your name.</small></div>
          <div class="form-group"><label>Email Address</label><input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled><small class="text-muted">Contact us to change your email.</small></div>
          <div class="form-group"><label>Phone Number</label><input type="tel" name="phone" class="form-control" value="<?= e($user['phone']) ?>" placeholder="+27 XX XXX XXXX"></div>
          <?php if ($user['id_number']): ?>
          <div class="form-group"><label>SA ID Number</label><input type="text" class="form-control" value="<?= e($user['id_number']) ?>" disabled></div>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary" id="saveProfileBtn"><i class="fas fa-save"></i> Save Changes</button>
        </form>
      </div>

      <div>
        <div class="card">
          <h3>Change Password</h3>
          <div id="passwordResult"></div>
          <form id="passwordForm">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="change_password" value="1">
            <div class="form-group"><label>Current Password</label><div class="input-icon-wrap"><input type="password" name="current_password" class="form-control" placeholder="Enter current password" id="currentPwd"><button type="button" class="input-icon-btn toggle-pwd" data-target="currentPwd"><i class="fas fa-eye"></i></button></div></div>
            <div class="form-group"><label>New Password</label><div class="input-icon-wrap"><input type="password" name="new_password" class="form-control" placeholder="At least 8 characters" id="newPwd"><button type="button" class="input-icon-btn toggle-pwd" data-target="newPwd"><i class="fas fa-eye"></i></button></div></div>
            <div class="form-group"><label>Confirm New Password</label><div class="input-icon-wrap"><input type="password" name="confirm_password" class="form-control" placeholder="Repeat new password" id="confirmPwd"><button type="button" class="input-icon-btn toggle-pwd" data-target="confirmPwd"><i class="fas fa-eye"></i></button></div><small id="pwdMatchHint" style="display:none"></small></div>
            <button type="submit" class="btn btn-outline" id="changePwdBtn"><i class="fas fa-lock"></i> Update Password</button>
          </form>
        </div>

        <div class="card" style="margin-top:1.5rem">
          <h3>Account Status</h3>
          <div class="profile-info-rows">
            <div class="profile-info-row"><span>Member Since</span><strong><?= safeDate('d F Y', $user['created_at']) ?></strong></div>
            <div class="profile-info-row"><span>Bidder Status</span><span class="badge badge-<?= str_replace('_','-',$user['bidder_status']) ?>"><?= ucwords(str_replace('_',' ',$user['bidder_status'])) ?></span></div>
            <?php if ($user['bidder_number']): ?><div class="profile-info-row"><span>Bidder Number</span><strong><?= e($user['bidder_number']) ?></strong> <button class="copy-btn" data-copy="<?= e($user['bidder_number']) ?>"><i class="fas fa-copy"></i></button></div><?php endif; ?>
            <div class="profile-info-row"><span>Total Bids</span><strong><?= $totalBids ?></strong></div>
            <div class="profile-info-row"><span>Auctions Won</span><strong><?= $auctionsWon ?></strong></div>
          </div>
          <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border-color)">
            <p class="text-muted" style="font-size:.85rem"><i class="fas fa-question-circle"></i> Need help? Contact us:</p>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
              <a href="mailto:<?= COMPANY_EMAIL ?>" class="btn btn-outline btn-sm"><i class="fas fa-envelope"></i> Email Us</a>
              <a href="tel:<?= str_replace(' ','',COMPANY_PHONE) ?>" class="btn btn-outline btn-sm"><i class="fas fa-phone"></i> <?= COMPANY_PHONE ?></a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════
         TAB: REFUND REQUESTS
    ═══════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'refunds'): ?>

    <div class="dashboard-header">
      <div>
        <h1>Refund Requests</h1>
        <p class="text-muted">Submit a refund request for registration fees or pre-auction payments.</p>
      </div>
    </div>

    <!-- Submit New Refund Request -->
    <div class="card" style="margin-bottom:1.5rem">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;cursor:pointer" onclick="toggleRefundForm()">
        <h3 style="margin:0"><i class="fas fa-plus-circle" style="color:var(--primary)"></i> Submit New Refund Request</h3>
        <i class="fas fa-chevron-down" id="refundFormChevron"></i>
      </div>
      <div id="refundFormBody" style="display:none;padding:1.5rem">

        <div class="alert alert-info" style="margin-bottom:1rem;font-size:.9rem">
          <i class="fas fa-info-circle"></i>
          <strong>EFT Refunds:</strong> Approved refunds are processed via EFT within 3–5 business days.
          A processing fee of <strong>R102.00</strong> applies. Please ensure your banking details are correct.
        </div>

        <form id="refundForm" enctype="multipart/form-data">
          <input type="hidden" name="action" value="submit_refund_request">

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
            <div class="form-group">
              <label class="form-label">Refund Is For <span class="text-danger">*</span></label>
              <select name="reference_type" class="form-control" required onchange="toggleRefNum(this)">
                <option value="">— Select —</option>
                <option value="registration">Bidder Registration Fee</option>
                <option value="preauction_invoice">Pre-Auction Purchase</option>
                <option value="other">Other Payment</option>
              </select>
            </div>
            <div class="form-group" id="refNumGroup" style="display:none">
              <label class="form-label">Reference / Invoice Number</label>
              <input type="text" name="reference_number" class="form-control" placeholder="e.g. AHG-INV-202504-00001">
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
            <div class="form-group">
              <label class="form-label">Amount You Paid (R) <span class="text-danger">*</span></label>
              <input type="number" name="amount_paid" class="form-control" placeholder="0.00" min="0.01" step="0.01" required>
            </div>
            <div class="form-group">
              <label class="form-label">Refund Amount Requested (R) <span class="text-danger">*</span></label>
              <input type="number" name="refund_amount" class="form-control" placeholder="0.00" min="0.01" step="0.01" required>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label">Reason for Refund <span class="text-danger">*</span></label>
            <textarea name="reason" class="form-control" rows="3" placeholder="Please describe why you are requesting this refund..." required></textarea>
          </div>

          <hr style="margin:1.5rem 0">
          <h4 style="margin:0 0 1rem"><i class="fas fa-university"></i> Banking Details for EFT Refund</h4>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
            <div class="form-group">
              <label class="form-label">Bank Name <span class="text-danger">*</span></label>
              <select name="bank_name" class="form-control" required>
                <option value="">— Select Bank —</option>
                <option>ABSA</option>
                <option>Capitec Bank</option>
                <option>Discovery Bank</option>
                <option>FNB / First National Bank</option>
                <option>Investec</option>
                <option>Nedbank</option>
                <option>Standard Bank</option>
                <option>TymeBank</option>
                <option>African Bank</option>
                <option>Other</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Account Type <span class="text-danger">*</span></label>
              <select name="account_type" class="form-control" required>
                <option value="cheque">Cheque / Current</option>
                <option value="savings">Savings</option>
                <option value="transmission">Transmission</option>
                <option value="credit">Credit</option>
              </select>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
            <div class="form-group">
              <label class="form-label">Account Holder Name <span class="text-danger">*</span></label>
              <input type="text" name="account_holder" class="form-control" placeholder="Name as it appears on the account" required>
            </div>
            <div class="form-group">
              <label class="form-label">Account Number <span class="text-danger">*</span></label>
              <input type="text" name="account_number" class="form-control" placeholder="e.g. 1234567890" required>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
            <div class="form-group">
              <label class="form-label">Branch Code <span class="text-danger">*</span></label>
              <input type="text" name="branch_code" class="form-control" placeholder="e.g. 632005" required>
            </div>
          </div>

          <hr style="margin:1.5rem 0">
          <h4 style="margin:0 0 1rem"><i class="fas fa-file-upload"></i> Proof of Original Payment (Optional)</h4>

          <div class="form-group" style="margin-bottom:1.5rem">
            <label class="form-label">Upload POP <small class="text-muted">(JPG, PNG or PDF · max 10 MB)</small></label>
            <div class="pop-drop-zone" id="rfDropZone" onclick="document.getElementById('rfFile').click()">
              <i class="fas fa-cloud-upload-alt" style="font-size:2rem;color:var(--primary);margin-bottom:.5rem"></i>
              <p style="margin:0">Drag &amp; drop or <strong>click to browse</strong></p>
            </div>
            <input type="file" name="pop_file" id="rfFile" accept=".jpg,.jpeg,.png,.pdf" style="display:none">
            <div id="rfPreview" style="display:none;margin-top:.75rem;padding:.75rem;background:var(--bg-card-alt,#f8f9fa);border-radius:8px">
              <i class="fas fa-file-alt"></i> <span id="rfPreviewName"></span>
            </div>
          </div>

          <div id="refundFormResult"></div>

          <div style="display:flex;gap:1rem;justify-content:flex-end">
            <button type="button" class="btn btn-outline" onclick="toggleRefundForm()">Cancel</button>
            <button type="submit" class="btn btn-primary" id="rfSubmitBtn">
              <i class="fas fa-paper-plane"></i> Submit Refund Request
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Existing Refund Requests -->
    <?php if (empty($myRefunds)): ?>
    <div class="card empty-state" style="text-align:center;padding:3rem">
      <i class="fas fa-rotate-left" style="font-size:3rem;color:var(--border-color);margin-bottom:1rem"></i>
      <h3>No Refund Requests Yet</h3>
      <p class="text-muted">You haven't submitted any refund requests. Use the form above to get started.</p>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-header"><h3 style="margin:0">Your Refund Requests</h3></div>
      <div style="overflow-x:auto">
        <table class="admin-table" style="width:100%">
          <thead>
            <tr>
              <th>#</th>
              <th>Type</th>
              <th>Amount Paid</th>
              <th>Refund Requested</th>
              <th>Bank</th>
              <th>Status</th>
              <th>Submitted</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($myRefunds as $rf): ?>
            <tr>
              <td><?= $rf['id'] ?></td>
              <td><?= ucwords(str_replace('_', ' ', $rf['reference_type'])) ?>
                <?php if ($rf['reference_number']): ?>
                  <br><small class="text-muted"><?= e($rf['reference_number']) ?></small>
                <?php endif; ?>
              </td>
              <td><?= formatRand($rf['amount_paid']) ?></td>
              <td><?= formatRand($rf['refund_amount']) ?></td>
              <td style="font-size:.85rem"><?= e($rf['bank_name']) ?><br><small class="text-muted"><?= e($rf['account_number']) ?></small></td>
              <td>
                <span class="badge badge-<?= match($rf['status']) {
                  'pending'      => 'warning',
                  'under_review' => 'info',
                  'processed'    => 'success',
                  'rejected'     => 'danger',
                  default        => 'secondary'
                } ?>">
                  <?= ucwords(str_replace('_',' ',$rf['status'])) ?>
                </span>
              </td>
              <td style="font-size:.85rem"><?= safeDate('d M Y', $rf['created_at']) ?></td>
              <td style="font-size:.85rem;max-width:180px">
                <?php if ($rf['status'] === 'processed'): ?>
                  <span class="text-success"><i class="fas fa-check-circle"></i> EFT Sent</span>
                  <?php if ($rf['admin_notes']): ?><br><small><?= e($rf['admin_notes']) ?></small><?php endif; ?>
                <?php elseif ($rf['status'] === 'rejected'): ?>
                  <span class="text-danger"><i class="fas fa-times-circle"></i> Rejected</span>
                  <?php if ($rf['rejection_reason']): ?><br><small><?= e($rf['rejection_reason']) ?></small><?php endif; ?>
                <?php elseif ($rf['status'] === 'under_review'): ?>
                  <span class="text-info"><i class="fas fa-search"></i> Under review</span>
                <?php else: ?>
                  <span class="text-muted">Awaiting review</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>

  </main>
</div>


<script>
// Bid filter tabs
document.querySelectorAll('[data-filter]').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('[data-filter]').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const filter = this.dataset.filter;
        document.querySelectorAll('.bid-row').forEach(row => {
            row.style.display = (filter === 'all' || row.dataset.filter === filter) ? '' : 'none';
        });
    });
});

// POP drag-and-drop
const dropZone=document.getElementById('popDropZone'),popFile=document.getElementById('popFile'),
      popPreview=document.getElementById('popPreview'),popPreviewImg=document.getElementById('popPreviewImg'),
      popPreviewName=document.getElementById('popPreviewName');
if(dropZone&&popFile){
    ['dragenter','dragover'].forEach(e=>dropZone.addEventListener(e,ev=>{ev.preventDefault();dropZone.classList.add('drag-over');}));
    ['dragleave','drop'].forEach(e=>dropZone.addEventListener(e,ev=>{ev.preventDefault();dropZone.classList.remove('drag-over');}));
    dropZone.addEventListener('drop',ev=>{if(ev.dataTransfer.files.length){popFile.files=ev.dataTransfer.files;handleFilePreview(ev.dataTransfer.files[0]);}});
    popFile.addEventListener('change',()=>{if(popFile.files[0])handleFilePreview(popFile.files[0]);});
}
function handleFilePreview(file){
    if(!['image/jpeg','image/jpg','image/png','application/pdf'].includes(file.type)){showToast('Invalid file type. JPG, PNG, or PDF only.','error');return;}
    if(file.size>10485760){showToast('File too large. Max 10MB.','error');return;}
    if(popPreviewName)popPreviewName.textContent=file.name+' ('+(file.size/1024).toFixed(1)+' KB)';
    if(file.type.startsWith('image/')&&popPreviewImg){const r=new FileReader();r.onload=e=>{popPreviewImg.src=e.target.result;popPreviewImg.style.display='';};r.readAsDataURL(file);}
    else if(popPreviewImg)popPreviewImg.style.display='none';
    if(popPreview)popPreview.style.display='block';
    if(dropZone)dropZone.style.borderColor='var(--success)';
}

// POP submit
const popForm=document.getElementById('popUploadForm');
if(popForm){popForm.addEventListener('submit',async function(e){
    e.preventDefault();
    if(!popFile||!popFile.files[0]){showToast('Please select a file.','error');return;}
    const btn=document.getElementById('popSubmitBtn');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Uploading...';
    try{const res=await fetch('api.php',{method:'POST',body:new FormData(popForm)});const data=await res.json();
        document.getElementById('popUploadResult').innerHTML=`<div class="alert alert-${data.success?'success':'error'}">${data.message}</div>`;
        if(data.success)setTimeout(()=>window.location.reload(),2000);
    }catch{showToast('Upload failed. Please try again.','error');}
    finally{btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Submit Proof of Payment';}
});}

// Request banking details
const bankReqBtn=document.getElementById('requestBankDetailsBtn');
if(bankReqBtn){bankReqBtn.addEventListener('click',async function(){
    bankReqBtn.disabled=true;bankReqBtn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Sending...';
    const resultDiv=document.getElementById('bankDetailsRequestResult');
    try{const fd=new FormData();fd.append('action','request_bank_details');
        const res=await fetch('api.php',{method:'POST',body:fd});const data=await res.json();
        resultDiv.innerHTML=`<div class="alert alert-${data.success?'success':'error'}">${data.message}</div>`;
    }catch{resultDiv.innerHTML='<div class="alert alert-error">Request failed. Please try again.</div>';}
    finally{bankReqBtn.disabled=false;bankReqBtn.innerHTML='<i class="fas fa-envelope"></i> Request Banking Details via Email';}
});}

// Profile form
const profileForm=document.getElementById('profileForm');
if(profileForm){profileForm.addEventListener('submit',async function(e){
    e.preventDefault();const btn=document.getElementById('saveProfileBtn');btn.disabled=true;btn.textContent='Saving...';
    try{const res=await fetch('api.php',{method:'POST',body:new FormData(profileForm)});const data=await res.json();
        document.getElementById('profileResult').innerHTML=`<div class="alert alert-${data.success?'success':'error'}">${data.message}</div>`;
    }catch{showToast('Error saving profile.','error');}
    finally{btn.disabled=false;btn.innerHTML='<i class="fas fa-save"></i> Save Changes';}
});}

// Password form
const passwordForm=document.getElementById('passwordForm');
if(passwordForm){
    const newPwd=document.getElementById('newPwd'),confirmPwd=document.getElementById('confirmPwd'),matchHint=document.getElementById('pwdMatchHint');
    [newPwd,confirmPwd].forEach(el=>el&&el.addEventListener('input',()=>{
        if(!confirmPwd.value){matchHint.style.display='none';return;}
        const ok=newPwd.value===confirmPwd.value;matchHint.style.display='block';
        matchHint.style.color=ok?'var(--success)':'var(--danger)';matchHint.textContent=ok?'✓ Passwords match':'✗ Passwords do not match';
    }));
    passwordForm.addEventListener('submit',async function(e){
        e.preventDefault();if(newPwd.value!==confirmPwd.value){showToast('Passwords do not match.','error');return;}
        const btn=document.getElementById('changePwdBtn');btn.disabled=true;btn.textContent='Updating...';
        try{const res=await fetch('api.php',{method:'POST',body:new FormData(passwordForm)});const data=await res.json();
            document.getElementById('passwordResult').innerHTML=`<div class="alert alert-${data.success?'success':'error'}">${data.message}</div>`;
            if(data.success)passwordForm.reset();
        }catch{showToast('Error updating password.','error');}
        finally{btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> Update Password';}
    });
    document.querySelectorAll('.toggle-pwd').forEach(btn=>{btn.addEventListener('click',function(){
        const inp=document.getElementById(this.dataset.target);inp.type=inp.type==='password'?'text':'password';
        this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');
    });});
}

// Copy buttons
document.querySelectorAll('.copy-btn,[data-copy]').forEach(btn=>{
    btn.addEventListener('click',function(){navigator.clipboard.writeText(this.dataset.copy).then(()=>showToast('Copied!','success'));});
});

// ── Refund form ────────────────────────────────────────────────
function toggleRefundForm(){
    const body=document.getElementById('refundFormBody'),chev=document.getElementById('refundFormChevron');
    if(!body)return;
    const open=body.style.display==='none'||!body.style.display;
    body.style.display=open?'block':'none';
    if(chev){chev.style.transform=open?'rotate(180deg)':'';}
}
function toggleRefNum(sel){
    const grp=document.getElementById('refNumGroup');
    if(grp)grp.style.display=['preauction_invoice','other'].includes(sel.value)?'block':'none';
}
// Refund POP drag-drop
(function(){
    const dz=document.getElementById('rfDropZone'),fi=document.getElementById('rfFile');
    if(!dz||!fi)return;
    ['dragenter','dragover'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.add('drag-over');}));
    ['dragleave','drop'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.remove('drag-over');}));
    dz.addEventListener('drop',ev=>{if(ev.dataTransfer.files.length){fi.files=ev.dataTransfer.files;showRfFile(ev.dataTransfer.files[0]);}});
    fi.addEventListener('change',()=>{if(fi.files[0])showRfFile(fi.files[0]);});
    function showRfFile(f){
        const prev=document.getElementById('rfPreview'),name=document.getElementById('rfPreviewName');
        if(name)name.textContent=f.name+' ('+(f.size/1024).toFixed(1)+' KB)';
        if(prev)prev.style.display='block';
        dz.style.borderColor='var(--success)';
    }
})();
// Refund form submit
const rfForm=document.getElementById('refundForm');
if(rfForm){rfForm.addEventListener('submit',async function(e){
    e.preventDefault();
    const btn=document.getElementById('rfSubmitBtn');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Submitting...';
    const res=document.getElementById('refundFormResult');
    try{
        const r=await fetch('api.php',{method:'POST',body:new FormData(rfForm)});
        const d=await r.json();
        res.innerHTML=`<div class="alert alert-${d.success?'success':'error'}" style="margin-bottom:1rem">${d.message}</div>`;
        if(d.success){rfForm.reset();document.getElementById('rfPreview').style.display='none';setTimeout(()=>location.reload(),2500);}
    }catch{res.innerHTML='<div class="alert alert-error">Submission failed. Please try again.</div>';}
    finally{btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Submit Refund Request';}
});}

// Mini timers
document.querySelectorAll('[data-countdown]').forEach(el=>{
    function tick(){
        const end=new Date(el.dataset.countdown.replace(' ','T')).getTime(),diff=end-Date.now();
        const span=el.querySelector('span')||el;
        if(diff<=0){span.textContent='Closed';return;}
        const h=Math.floor(diff/3600000),m=Math.floor((diff%3600000)/60000),s=Math.floor((diff%60000)/1000);
        span.textContent=`${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
        setTimeout(tick,1000);
    }tick();
});
</script>

<!-- Logout Confirmation Modal -->
<div id="userLogoutModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box" style="max-width:420px;text-align:center">
    <div style="padding:1.5rem 1.5rem 0">
      <div style="width:64px;height:64px;border-radius:50%;background:#fce4ec;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
        <i class="fas fa-sign-out-alt" style="font-size:1.6rem;color:#c62828"></i>
      </div>
      <h3 style="margin:0 0 .5rem">Log Out?</h3>
      <p class="text-muted" style="font-size:.95rem;margin-bottom:1.5rem">
        Are you sure you want to log out of your account?
      </p>
    </div>
    <div class="modal-footer" style="justify-content:center;gap:1rem">
      <button class="btn btn-outline" onclick="document.getElementById('userLogoutModal').style.display='none'">
        <i class="fas fa-times"></i> Cancel
      </button>
      <a href="logout.php" class="btn btn-danger">
        <i class="fas fa-sign-out-alt"></i> Yes, Log Out
      </a>
    </div>
  </div>
</div>

<?php require_once 'footer.php'; ?>
