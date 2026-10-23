<?php
// ============================================================
// AHG Worker Portal — worker_portal.php
// Daily tracker for approved Sales Representatives
// Login: phone number + 4-digit PIN
// ============================================================
require_once __DIR__ . '/config.php';

// ── Session auth keys (separate from main site) ────────────
define('WS_ID',   'ahg_worker_id');
define('WS_NAME', 'ahg_worker_name');
define('WS_CODE', 'ahg_worker_code');

$workerId   = $_SESSION[WS_ID]   ?? null;
$workerName = $_SESSION[WS_NAME] ?? null;
$workerCode = $_SESSION[WS_CODE] ?? null;

// ── Handle logout ──────────────────────────────────────────
if (isset($_GET['logout'])) {
    unset($_SESSION[WS_ID], $_SESSION[WS_NAME], $_SESSION[WS_CODE]);
    header('Location: worker_portal.php');
    exit;
}

// ── Ensure career tables exist ────────────────────────────
try {
    $pdo->exec(file_get_contents(__DIR__ . '/ahg_career_portal.sql'));
} catch (Exception $e) { /* tables already exist */ }

// Migrate existing installs
foreach ([
    "ALTER TABLE worker_daily_logs ADD COLUMN proof_paths TEXT DEFAULT NULL",
] as $_mig) {
    try { $pdo->exec($_mig); } catch (Exception $e) {}
}
unset($_mig);

// ── Handle login POST ─────────────────────────────────────
$loginError = '';
if (!$workerId && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wlogin'])) {
    $phone = preg_replace('/[\s\-]/', '', trim($_POST['phone'] ?? ''));
    $pin   = trim($_POST['pin'] ?? '');
    try {
        // Check if workers table has any accounts at all
        $total = $pdo->query("SELECT COUNT(*) FROM workers")->fetchColumn();
        if ($total == 0) {
            $loginError = 'No worker accounts have been set up yet. Please ask your administrator to run the setup script or create your account via the admin panel.';
        } else {
            $stmt = $pdo->prepare("SELECT id, full_name, worker_code, pin_hash, status FROM workers WHERE phone = ? LIMIT 1");
            $stmt->execute([$phone]);
            $worker = $stmt->fetch();
            if ($worker && password_verify($pin, $worker['pin_hash'])) {
                if ($worker['status'] !== 'active') {
                    $loginError = 'Your account is currently ' . $worker['status'] . '. Please contact HR at info@auctionhubgroup.co.za.';
                } else {
                    $_SESSION[WS_ID]   = $worker['id'];
                    $_SESSION[WS_NAME] = $worker['full_name'];
                    $_SESSION[WS_CODE] = $worker['worker_code'];
                    $pdo->prepare("UPDATE workers SET last_login = NOW() WHERE id = ?")->execute([$worker['id']]);
                    header('Location: worker_portal.php');
                    exit;
                }
            } else {
                $loginError = 'Incorrect phone number or PIN. Please check your credentials and try again.';
            }
        }
    } catch (Exception $e) {
        $loginError = 'System error: ' . $e->getMessage() . ' — Please contact info@auctionhubgroup.co.za.';
    }
    $workerId   = $_SESSION[WS_ID]   ?? null;
    $workerName = $_SESSION[WS_NAME] ?? null;
    $workerCode = $_SESSION[WS_CODE] ?? null;
}

// ── Load worker data if logged in ─────────────────────────
$todayLog   = null;
$todayLeads = [];
$recentLogs = [];
$recentLeadsByLog = [];
$monthStats = ['posts' => 0, 'leads' => 0, 'days' => 0];

if ($workerId) {
    try {
        $today = date('Y-m-d');
        $todayStmt = $pdo->prepare("SELECT * FROM worker_daily_logs WHERE worker_id = ? AND log_date = ?");
        $todayStmt->execute([$workerId, $today]);
        $todayLog = $todayStmt->fetch();

        if ($todayLog) {
            $lStmt = $pdo->prepare("SELECT * FROM worker_leads WHERE log_id=? ORDER BY id");
            $lStmt->execute([$todayLog['id']]);
            $todayLeads = $lStmt->fetchAll();
        }

        $recentStmt = $pdo->prepare("
            SELECT * FROM worker_daily_logs WHERE worker_id = ?
            ORDER BY log_date DESC LIMIT 14
        ");
        $recentStmt->execute([$workerId]);
        $recentLogs = $recentStmt->fetchAll();

        // Load leads for all recent logs
        if ($recentLogs) {
            $logIds = array_column($recentLogs, 'id');
            $inSql  = implode(',', array_fill(0, count($logIds), '?'));
            $rlStmt = $pdo->prepare("SELECT * FROM worker_leads WHERE log_id IN ($inSql) ORDER BY log_id, id");
            $rlStmt->execute($logIds);
            foreach ($rlStmt->fetchAll() as $rl) {
                $recentLeadsByLog[$rl['log_id']][] = $rl;
            }
        }

        $mStmt = $pdo->prepare("
            SELECT SUM(posts_count) AS posts, SUM(leads_count) AS leads, COUNT(*) AS days
            FROM worker_daily_logs
            WHERE worker_id = ? AND MONTH(log_date) = MONTH(NOW()) AND YEAR(log_date) = YEAR(NOW())
        ");
        $mStmt->execute([$workerId]);
        $mRow = $mStmt->fetch();
        $monthStats = [
            'posts' => (int)($mRow['posts'] ?? 0),
            'leads' => (int)($mRow['leads'] ?? 0),
            'days'  => (int)($mRow['days']  ?? 0),
        ];
    } catch (Exception $e) {}
}

// ── Handle daily log submission ────────────────────────────
$submitMsg  = '';
$submitType = '';
if ($workerId && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wsubmit'])) {
    $logDate      = date('Y-m-d');
    $platforms    = implode(',', array_filter(array_map('trim', (array)($_POST['platforms'] ?? []))));
    $postsCount   = max(0, (int)($_POST['posts_count'] ?? 0));
    $postDetails  = trim($_POST['post_details'] ?? '');
    $leadsDetails = trim($_POST['leads_details'] ?? '');

    // Structured leads (up to 5) — optional
    $rawNames   = (array)($_POST['lead_name']  ?? []);
    $rawPhones  = (array)($_POST['lead_phone'] ?? []);
    $structuredLeads = [];
    for ($li = 0; $li < min(5, count($rawNames)); $li++) {
        $lname  = trim($rawNames[$li]  ?? '');
        $lphone = trim($rawPhones[$li] ?? '');
        if ($lname && $lphone) {
            $structuredLeads[] = ['name' => $lname, 'phone' => $lphone];
        }
    }
    $leadsCount = count($structuredLeads);

    // Multiple proof images (up to 5)
    $proofsDir = __DIR__ . '/uploads/worker_proofs/';
    if (!is_dir($proofsDir)) mkdir($proofsDir, 0755, true);

    $newProofPaths = [];
    $isMulti = isset($_FILES['proof']['name'][0]);
    if ($isMulti) {
        for ($fi = 0; $fi < min(5, count($_FILES['proof']['name'])); $fi++) {
            if ($_FILES['proof']['error'][$fi] !== UPLOAD_ERR_OK) continue;
            $singleFile = [
                'name'     => $_FILES['proof']['name'][$fi],
                'type'     => $_FILES['proof']['type'][$fi],
                'tmp_name' => $_FILES['proof']['tmp_name'][$fi],
                'error'    => $_FILES['proof']['error'][$fi],
                'size'     => $_FILES['proof']['size'][$fi],
            ];
            $r = handleUpload($singleFile, $proofsDir,
                ['image/jpeg','image/jpg','image/png','image/webp'], 'proof');
            if ($r['success']) $newProofPaths[] = $r['path'];
        }
    } elseif (!empty($_FILES['proof']['name']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
        $r = handleUpload($_FILES['proof'], $proofsDir,
            ['image/jpeg','image/jpg','image/png','image/webp'], 'proof');
        if ($r['success']) $newProofPaths[] = $r['path'];
    }

    $isNewLog = !$todayLog;
    $hasProofFile = !empty($newProofPaths);

    // Validation
    if (!$platforms || $postsCount < 1 || !$postDetails) {
        $submitMsg  = 'Please complete all required fields: platforms, post count, and post details.';
        $submitType = 'error';
    } elseif ($isNewLog && !$hasProofFile) {
        $submitMsg  = 'A proof of upload screenshot is required to submit your first log of the day.';
        $submitType = 'error';
    } else {
        // Determine final proof paths (new upload or keep existing)
        $existingPaths = [];
        if ($todayLog) {
            $existingPaths = $todayLog['proof_paths'] ? json_decode($todayLog['proof_paths'], true) : [];
            if (empty($existingPaths) && $todayLog['proof_path']) {
                $existingPaths = [$todayLog['proof_path']]; // migrate old single-path
            }
        }
        $finalPaths    = $hasProofFile ? $newProofPaths : $existingPaths;
        $proofPathsJson = !empty($finalPaths) ? json_encode(array_values($finalPaths)) : null;
        $proofPath      = $finalPaths[0] ?? null; // keep first for legacy column

        try {
            $existingStmt = $pdo->prepare("SELECT id FROM worker_daily_logs WHERE worker_id=? AND log_date=?");
            $existingStmt->execute([$workerId, $logDate]);
            $existingLogId = $existingStmt->fetchColumn();

            if ($existingLogId) {
                $pdo->prepare("
                    UPDATE worker_daily_logs SET
                        platforms=?, posts_count=?, post_details=?,
                        proof_path=?, proof_paths=?,
                        leads_count=?, leads_details=?,
                        status='submitted', submitted_at=NOW()
                    WHERE id=?
                ")->execute([$platforms,$postsCount,$postDetails,$proofPath,$proofPathsJson,
                             $leadsCount,$leadsDetails,$existingLogId]);
                $logId = $existingLogId;
            } else {
                $pdo->prepare("
                    INSERT INTO worker_daily_logs
                        (worker_id,log_date,platforms,posts_count,post_details,
                         proof_path,proof_paths,leads_count,leads_details)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ")->execute([$workerId,$logDate,$platforms,$postsCount,$postDetails,
                             $proofPath,$proofPathsJson,$leadsCount,$leadsDetails]);
                $logId = $pdo->lastInsertId();
            }

            // Replace structured leads
            $pdo->prepare("DELETE FROM worker_leads WHERE log_id=?")->execute([$logId]);
            foreach ($structuredLeads as $lead) {
                $pdo->prepare("INSERT INTO worker_leads (log_id,worker_id,lead_name,lead_phone) VALUES (?,?,?,?)")
                    ->execute([$logId, $workerId, $lead['name'], $lead['phone']]);
            }

            $submitMsg  = "Today's log submitted! " . count($finalPaths) . " proof image(s), $leadsCount lead(s) recorded.";
            $submitType = 'success';

            // Refresh today log & leads
            $todayStmt->execute([$workerId, $logDate]);
            $todayLog = $todayStmt->fetch();
        } catch (Exception $e) {
            $submitMsg  = 'Database error: ' . $e->getMessage();
            $submitType = 'error';
        }
    }
}

$firstName = $workerName ? explode(' ', $workerName)[0] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Worker Portal — Auction Hub Group</title>
<link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/favicon.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
      crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Barlow',system-ui,sans-serif;background:#f0f4f9;color:#222;font-size:15px;min-height:100vh}
a{color:#005BA1;text-decoration:none}
/* ── Top bar ── */
.wp-topbar{background:linear-gradient(135deg,#001e4a,#003f73);padding:.85rem 1.5rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}
.wp-logo{display:flex;align-items:center;gap:.75rem}
.wp-logo img{height:40px}
.wp-logo-text{color:#fff;font-size:.95rem;font-weight:700;line-height:1.2}
.wp-logo-sub{font-size:.7rem;color:rgba(255,255,255,.55);font-weight:400}
.wp-user{display:flex;align-items:center;gap:.75rem;color:rgba(255,255,255,.85);font-size:.82rem}
.wp-badge{background:rgba(0,174,239,.25);color:#7ec8ff;padding:.25rem .7rem;border-radius:12px;font-weight:700;font-size:.78rem;border:1px solid rgba(126,200,255,.3)}
.wp-logout{color:rgba(255,100,100,.8);font-size:.8rem;padding:.3rem .7rem;border:1px solid rgba(255,100,100,.3);border-radius:6px;transition:.2s}
.wp-logout:hover{background:rgba(255,80,80,.1);color:#ff6464}
/* ── Container ── */
.wp-wrap{max-width:900px;margin:0 auto;padding:1.75rem 1.25rem}
/* ── Stats row ── */
.wp-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem}
.wp-stat{background:#fff;border-radius:12px;padding:1.1rem 1.25rem;box-shadow:0 1px 8px rgba(0,0,0,.06);text-align:center}
.wp-stat-val{font-size:1.7rem;font-weight:800;color:#003f73;line-height:1}
.wp-stat-lbl{font-size:.75rem;color:#888;margin-top:.3rem;text-transform:uppercase;letter-spacing:.05em}
/* ── Card ── */
.wp-card{background:#fff;border-radius:12px;padding:1.5rem 1.75rem;box-shadow:0 1px 8px rgba(0,0,0,.06);margin-bottom:1.25rem}
.wp-card-title{font-size:1rem;font-weight:700;color:#003f73;margin-bottom:1.1rem;display:flex;align-items:center;gap:.5rem;border-bottom:1px solid #f0f3f8;padding-bottom:.75rem}
/* ── Form ── */
.wp-label{font-size:.82rem;font-weight:600;color:#334155;margin-bottom:.3rem;display:block}
.wp-input,.wp-textarea,.wp-select{width:100%;padding:.65rem .85rem;border:1.5px solid #d0dae8;border-radius:8px;font-size:.88rem;outline:none;font-family:inherit;transition:border .2s;background:#fff}
.wp-input:focus,.wp-textarea:focus,.wp-select:focus{border-color:#005BA1}
.wp-textarea{resize:vertical;min-height:80px}
.wp-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:.9rem}
.wp-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-bottom:.9rem}
.wp-full{margin-bottom:.9rem}
.wp-btn{background:#005BA1;color:#fff;border:none;padding:.75rem 1.75rem;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;transition:background .2s;display:inline-flex;align-items:center;gap:.4rem}
.wp-btn:hover{background:#003f73}
.wp-btn:disabled{background:#9db9d5;cursor:not-allowed}
/* ── Platforms checkboxes ── */
.wp-checks{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.3rem}
.wp-check{display:flex;align-items:center;gap:.35rem;background:#f5f7fa;border:1.5px solid #dce4ee;border-radius:6px;padding:.4rem .75rem;cursor:pointer;font-size:.82rem;font-weight:500;color:#334155;transition:.15s;user-select:none}
.wp-check:has(input:checked){background:#ebf4ff;border-color:#005BA1;color:#003f73}
.wp-check input{accent-color:#005BA1}
/* ── Alert ── */
.wp-alert{border-radius:8px;padding:.8rem 1rem;font-size:.88rem;font-weight:600;margin-bottom:1.1rem}
.wp-alert-success{background:#e8f9ef;color:#1a6b3c;border:1px solid #a7e3bf}
.wp-alert-error{background:#fde8e8;color:#8b1a1a;border:1px solid #f5c6cb}
/* ── Submitted badge ── */
.wp-submitted{background:#e8f9ef;border:1px solid #a7e3bf;border-radius:10px;padding:1.1rem 1.25rem;color:#1a6b3c;display:flex;align-items:flex-start;gap:.75rem}
.wp-submitted i{font-size:1.4rem;margin-top:.1rem}
/* ── History table ── */
.wp-table{width:100%;border-collapse:collapse;font-size:.82rem}
.wp-table th{background:#f5f7fa;color:#555;font-weight:600;text-align:left;padding:.6rem .75rem;border-bottom:2px solid #e5eaf0}
.wp-table td{padding:.6rem .75rem;border-bottom:1px solid #f0f3f8;color:#333;vertical-align:middle}
.wp-table tr:last-child td{border-bottom:none}
.status-pill{display:inline-block;padding:.2rem .65rem;border-radius:12px;font-size:.73rem;font-weight:700}
.pill-submitted{background:#e8f0fe;color:#1a4098}
.pill-verified{background:#e6f9ee;color:#1a6b3c}
.pill-rejected{background:#fde8e8;color:#8b1a1a}
/* ── Login ── */
.wp-login{min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#001e4a,#003f73,#005BA1);padding:1.5rem}
.wp-login-box{background:#fff;border-radius:16px;padding:2.5rem 2.25rem;width:100%;max-width:360px;box-shadow:0 8px 40px rgba(0,0,0,.25)}
.wp-login-logo{text-align:center;margin-bottom:1.5rem}
.wp-login-logo img{height:60px}
.wp-login-title{font-size:1.3rem;font-weight:800;color:#003f73;text-align:center;margin-bottom:.4rem}
.wp-login-sub{font-size:.83rem;color:#777;text-align:center;margin-bottom:1.75rem}
.wp-pin{letter-spacing:.2em;font-size:1.1rem;text-align:center}
@media(max-width:580px){
    .wp-stats{grid-template-columns:1fr 1fr}
    .wp-row{grid-template-columns:1fr}
    .wp-row-3{grid-template-columns:1fr 1fr}
    .wp-wrap{padding:1.25rem .9rem}
}
</style>
</head>
<body>

<?php if (!$workerId): ?>
<!-- ══════════════ LOGIN SCREEN ════════════════════════════ -->
<div class="wp-login">
    <div class="wp-login-box">
        <div class="wp-login-logo">
            <img src="<?= APP_URL ?>/logo.png" alt="Auction Hub Group">
        </div>
        <h1 class="wp-login-title">Worker Portal</h1>
        <p class="wp-login-sub">Sales Representatives only.<br>Enter your assigned credentials to continue.</p>

        <?php if ($loginError): ?>
        <div class="wp-alert wp-alert-error" style="margin-bottom:1.1rem;">
            <i class="fas fa-circle-xmark" style="margin-right:.4rem;"></i><?= htmlspecialchars($loginError) ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="wlogin" value="1">
            <div style="margin-bottom:1rem;">
                <label class="wp-label" for="wphone">Phone Number</label>
                <input type="tel" id="wphone" name="phone" class="wp-input" placeholder="0XX XXX XXXX"
                    required autocomplete="tel" autofocus style="font-size:1rem;">
            </div>
            <div style="margin-bottom:1.5rem;">
                <label class="wp-label" for="wpin">4-Digit PIN</label>
                <input type="password" id="wpin" name="pin" class="wp-input wp-pin"
                    placeholder="••••" maxlength="6" required autocomplete="current-password">
            </div>
            <button type="submit" class="wp-btn" style="width:100%;justify-content:center;">
                <i class="fas fa-right-to-bracket"></i> Sign In
            </button>
        </form>
        <p style="text-align:center;margin-top:1.25rem;font-size:.78rem;color:#999;">
            Forgot your PIN? Contact HR at <a href="mailto:info@auctionhubgroup.co.za">info@auctionhubgroup.co.za</a>
        </p>
    </div>
</div>

<?php else: ?>
<!-- ══════════════ WORKER DASHBOARD ═══════════════════════ -->
<div class="wp-topbar">
    <div class="wp-logo">
        <img src="<?= APP_URL ?>/logo.png" alt="AHG">
        <div class="wp-logo-text">
            Worker Portal
            <div class="wp-logo-sub">Auction Hub Group</div>
        </div>
    </div>
    <div class="wp-user">
        <span class="wp-badge"><?= htmlspecialchars($workerCode) ?></span>
        <span style="display:none" id="wpName"><?= htmlspecialchars($workerName) ?></span>
        <span style="color:rgba(255,255,255,.7);font-size:.8rem"><?= htmlspecialchars($workerName) ?></span>
        <a href="?logout=1" class="wp-logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<div class="wp-wrap">

    <div style="margin-bottom:1.25rem;">
        <h2 style="font-size:1.2rem;color:#003f73;">Good <?= (date('H')<12?'morning':(date('H')<17?'afternoon':'evening')) ?>, <?= htmlspecialchars($firstName) ?>!</h2>
        <p style="color:#777;font-size:.85rem;"><?= date('l, d F Y') ?> — Log your activity to confirm today's work and secure your salary.</p>
    </div>

    <!-- Stats -->
    <div class="wp-stats">
        <div class="wp-stat">
            <div class="wp-stat-val"><?= $monthStats['posts'] ?></div>
            <div class="wp-stat-lbl">Posts This Month</div>
        </div>
        <div class="wp-stat">
            <div class="wp-stat-val"><?= $monthStats['leads'] ?></div>
            <div class="wp-stat-lbl">Leads This Month</div>
        </div>
        <div class="wp-stat">
            <div class="wp-stat-val"><?= $monthStats['days'] ?></div>
            <div class="wp-stat-lbl">Days Logged</div>
        </div>
    </div>

    <?php if ($submitMsg): ?>
    <div class="wp-alert wp-alert-<?= $submitType ?>">
        <i class="fas fa-<?= $submitType==='success'?'circle-check':'circle-xmark' ?>" style="margin-right:.4rem;"></i>
        <?= htmlspecialchars($submitMsg) ?>
    </div>
    <?php endif; ?>

    <!-- Today's Log Card -->
    <div class="wp-card">
        <div class="wp-card-title">
            <i class="fas fa-calendar-day" style="color:#005BA1;"></i>
            Today's Activity Log — <?= date('d M Y') ?>
            <?php if ($todayLog): ?>
                <span class="status-pill pill-<?= $todayLog['status'] ?>" style="margin-left:auto;"><?= ucfirst($todayLog['status']) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($todayLog && $todayLog['status'] === 'verified'): ?>
        <div class="wp-submitted">
            <i class="fas fa-circle-check"></i>
            <div>
                <strong>Today's log has been verified by admin.</strong><br>
                <span style="font-size:.83rem;color:#2d6a4f;">
                    <?= $todayLog['posts_count'] ?> posts &bull; <?= $todayLog['leads_count'] ?> leads &bull; Platforms: <?= htmlspecialchars($todayLog['platforms']) ?>
                </span>
            </div>
        </div>
        <?php else: ?>

        <?php if ($todayLog): ?>
        <div class="wp-alert wp-alert-success" style="margin-bottom:1rem;">
            <i class="fas fa-circle-check" style="margin-right:.4rem;"></i>
            Log submitted — <?= $todayLog['posts_count'] ?> posts, <?= $todayLog['leads_count'] ?> leads. You can update it below before midnight.
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="logForm">
            <input type="hidden" name="wsubmit" value="1">

            <!-- Platforms -->
            <div class="wp-full">
                <label class="wp-label">Platforms Used Today <span style="color:#e53935">*</span></label>
                <div class="wp-checks">
                    <?php
                    $platforms_list = ['Facebook','AutoTrader','OLX','Gumtree','Instagram','TikTok','Twitter/X','Cars.co.za','PrivateProperty','Other'];
                    $checked = $todayLog ? explode(',', $todayLog['platforms']) : [];
                    foreach ($platforms_list as $p):
                    ?>
                    <label class="wp-check">
                        <input type="checkbox" name="platforms[]" value="<?= $p ?>"
                            <?= in_array($p, $checked)?'checked':'' ?>>
                        <?= $p ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="wp-row">
                <div>
                    <label class="wp-label">Number of Posts Made Today <span style="color:#e53935">*</span></label>
                    <input type="number" name="posts_count" min="0" max="999" class="wp-input"
                        placeholder="e.g. 12" value="<?= $todayLog['posts_count'] ?? '' ?>" required>
                </div>
                <div>
                    <label class="wp-label">Post Details <span style="color:#e53935">*</span></label>
                    <input type="text" name="post_details" class="wp-input" required
                        placeholder="Vehicle types, listing titles, lot numbers…"
                        value="<?= htmlspecialchars($todayLog['post_details'] ?? '') ?>">
                </div>
            </div>

            <!-- Proof of uploads — multiple images -->
            <div class="wp-full">
                <label class="wp-label">
                    Proof of Uploads — Screenshots
                    <span style="color:#e53935">*</span>
                    <span style="color:#888;font-weight:400;font-size:.78rem;"> (up to 5 images)</span>
                </label>
                <?php
                $existingProofs = [];
                if ($todayLog) {
                    $existingProofs = $todayLog['proof_paths'] ? json_decode($todayLog['proof_paths'], true) : [];
                    if (empty($existingProofs) && $todayLog['proof_path']) {
                        $existingProofs = [$todayLog['proof_path']];
                    }
                }
                ?>
                <?php if (!empty($existingProofs)): ?>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;">
                    <?php foreach ($existingProofs as $ep): ?>
                    <a href="<?= APP_URL.'/'.htmlspecialchars($ep) ?>" target="_blank"
                       style="display:block;width:72px;height:72px;border-radius:6px;overflow:hidden;border:2px solid #d0dae8;">
                        <img src="<?= APP_URL.'/'.htmlspecialchars($ep) ?>"
                             style="width:100%;height:100%;object-fit:cover;" alt="proof">
                    </a>
                    <?php endforeach; ?>
                    <span style="font-size:.75rem;color:#777;align-self:center;">
                        <?= count($existingProofs) ?> image(s) uploaded. Add new to replace.
                    </span>
                </div>
                <?php endif; ?>
                <input type="file" name="proof[]" accept="image/jpeg,image/png,image/webp"
                       class="wp-input" style="padding:.55rem .75rem;background:#f8fafc;"
                       multiple id="proofInput" <?= empty($existingProofs)?'required':'' ?>>
                <span style="font-size:.75rem;color:#999;margin-top:.25rem;display:block;" id="proofHint">
                    JPG/PNG, max 10MB each. Screenshots showing your posts live on the platform.
                </span>
            </div>

            <!-- Structured Leads -->
            <div class="wp-card" style="margin-top:1rem;box-shadow:none;border:1.5px solid #e2eaf4;background:#f8fbff;">
                <div class="wp-card-title" style="border-bottom:1px solid #dde7f2;padding-bottom:.65rem;">
                    <i class="fas fa-users" style="color:#005BA1;"></i>
                    Leads Submitted to Company
                    <span style="color:#888;font-size:.75rem;font-weight:400;margin-left:.4rem;">(optional — up to 5 leads with name &amp; phone)</span>
                </div>
                <div id="leadRows">
                    <?php for ($li = 0; $li < 5; $li++):
                        $el = $todayLeads[$li] ?? null; ?>
                    <div class="lead-row" id="lead-row-<?= $li ?>"
                         style="<?= ($li > 0 && !$el) ? 'display:none;' : '' ?>margin-bottom:.75rem;">
                        <div style="display:flex;align-items:center;gap:.3rem;margin-bottom:.3rem;">
                            <span style="font-size:.76rem;font-weight:700;color:#005BA1;background:#e8f3ff;padding:.15rem .5rem;border-radius:10px;">Lead <?= $li+1 ?></span>
                            <?php if ($li > 0): ?>
                            <button type="button" onclick="removeLead(<?= $li ?>)"
                                    style="background:none;border:none;color:#dc3545;cursor:pointer;font-size:.78rem;padding:.1rem .4rem;">
                                <i class="fas fa-times"></i> Remove
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="wp-row" style="margin-bottom:0;">
                            <div>
                                <input type="text" name="lead_name[]" class="wp-input"
                                       placeholder="Full name of potential buyer"
                                       value="<?= htmlspecialchars($el['lead_name'] ?? '') ?>">
                            </div>
                            <div>
                                <input type="tel" name="lead_phone[]" class="wp-input"
                                       placeholder="Phone number (e.g. 0821234567)"
                                       value="<?= htmlspecialchars($el['lead_phone'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                <button type="button" onclick="addLead()" id="addLeadBtn"
                        style="background:none;border:1.5px dashed #7aafdf;color:#005BA1;padding:.45rem 1rem;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;margin-top:.25rem;">
                    <i class="fas fa-plus"></i> Add Another Lead
                </button>
            </div>

            <div style="margin-top:1.1rem;">
                <button type="submit" class="wp-btn">
                    <i class="fas fa-floppy-disk"></i>
                    <?= $todayLog ? 'Update Today\'s Log' : 'Submit Today\'s Log' ?>
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- History -->
    <?php if ($recentLogs): ?>
    <div class="wp-card">
        <div class="wp-card-title">
            <i class="fas fa-clock-rotate-left" style="color:#005BA1;"></i> Recent Activity (Last 14 Days)
        </div>
        <?php foreach ($recentLogs as $log):
            $logProofs = $log['proof_paths'] ? json_decode($log['proof_paths'], true) : [];
            if (empty($logProofs) && $log['proof_path']) $logProofs = [$log['proof_path']];
            $logLeads  = $recentLeadsByLog[$log['id']] ?? [];
            $platforms_arr = array_filter(explode(',', $log['platforms'] ?? ''));
        ?>
        <div style="border:1px solid #eef1f6;border-radius:9px;padding:.9rem 1rem;margin-bottom:.75rem;background:#fafbfd;">
            <!-- Row header -->
            <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                <span style="font-weight:700;color:#003f73;font-size:.88rem;min-width:52px;">
                    <?= date('d M', strtotime($log['log_date'])) ?>
                </span>
                <span class="status-pill pill-<?= $log['status'] ?>"><?= ucfirst($log['status']) ?></span>
                <span style="font-size:.8rem;color:#555;">
                    <strong style="color:#003f73"><?= $log['posts_count'] ?></strong> posts &bull;
                    <strong style="color:#005BA1"><?= $log['leads_count'] ?></strong> leads
                </span>
                <?php if (!empty($logProofs)): ?>
                <span style="font-size:.78rem;color:#666;">
                    <i class="fas fa-image" style="color:#7aafdf;"></i> <?= count($logProofs) ?> image(s)
                </span>
                <?php endif; ?>
                <button type="button" onclick="toggleLog(<?= $log['id'] ?>)"
                        style="margin-left:auto;background:none;border:1px solid #d0dae8;color:#005BA1;padding:.25rem .65rem;border-radius:6px;font-size:.75rem;cursor:pointer;">
                    <i class="fas fa-chevron-down" id="icon-<?= $log['id'] ?>"></i> Details
                </button>
            </div>
            <!-- Platforms -->
            <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.5rem;">
                <?php foreach ($platforms_arr as $pt): ?>
                <span style="background:#e8f0fe;color:#1a4098;padding:.15rem .5rem;border-radius:10px;font-size:.72rem;font-weight:600;">
                    <?= htmlspecialchars(trim($pt)) ?>
                </span>
                <?php endforeach; ?>
            </div>
            <!-- Expandable detail -->
            <div id="log-detail-<?= $log['id'] ?>" style="display:none;margin-top:.75rem;border-top:1px solid #eef1f6;padding-top:.75rem;">
                <?php if (!empty($logProofs)): ?>
                <div style="margin-bottom:.6rem;">
                    <span style="font-size:.76rem;font-weight:700;color:#555;display:block;margin-bottom:.4rem;">Proof Images</span>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                        <?php foreach ($logProofs as $ep): ?>
                        <a href="<?= APP_URL.'/'.htmlspecialchars($ep) ?>" target="_blank"
                           style="display:block;width:64px;height:64px;border-radius:6px;overflow:hidden;border:1.5px solid #d0dae8;">
                            <img src="<?= APP_URL.'/'.htmlspecialchars($ep) ?>"
                                 style="width:100%;height:100%;object-fit:cover;" alt="proof">
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($logLeads)): ?>
                <div>
                    <span style="font-size:.76rem;font-weight:700;color:#555;display:block;margin-bottom:.4rem;">
                        Leads Submitted (<?= count($logLeads) ?>)
                    </span>
                    <?php foreach ($logLeads as $i => $lead): ?>
                    <div style="background:#f0f8ff;border:1px solid #c8ddf5;border-radius:6px;padding:.4rem .75rem;margin-bottom:.3rem;font-size:.8rem;display:flex;gap:1rem;flex-wrap:wrap;">
                        <span><strong>#<?= $i+1 ?></strong> <?= htmlspecialchars($lead['lead_name']) ?></span>
                        <span style="color:#005BA1;"><i class="fas fa-phone" style="font-size:.72rem;"></i> <?= htmlspecialchars($lead['lead_phone']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif ($log['leads_count'] == 0): ?>
                <span style="font-size:.78rem;color:#aaa;">No leads submitted for this day.</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="text-align:center;margin-top:1.5rem;font-size:.78rem;color:#bbb;">
        Auction Hub Group &copy; <?= date('Y') ?> &bull; Worker Portal v1.0 &bull;
        <a href="mailto:info@auctionhubgroup.co.za" style="color:#999;">Contact HR</a>
    </div>

</div>
<?php endif; ?>

<script>
// ── Lead row management ────────────────────────────────────
var _visibleLeads = <?= max(1, count($todayLeads)) ?>;
function addLead() {
    if (_visibleLeads >= 5) return;
    document.getElementById('lead-row-' + _visibleLeads).style.display = '';
    _visibleLeads++;
    if (_visibleLeads >= 5) document.getElementById('addLeadBtn').style.display = 'none';
}
function removeLead(idx) {
    var row = document.getElementById('lead-row-' + idx);
    row.querySelectorAll('input').forEach(function(i){ i.value=''; });
    row.style.display = 'none';
}

// ── Proof image counter ────────────────────────────────────
var proofInput = document.getElementById('proofInput');
if (proofInput) {
    proofInput.addEventListener('change', function() {
        var n = this.files.length;
        var hint = document.getElementById('proofHint');
        if (n > 5) {
            hint.textContent = 'Maximum 5 images allowed. Only the first 5 will be saved.';
            hint.style.color = '#dc3545';
        } else {
            hint.textContent = n + ' image(s) selected.';
            hint.style.color = '#155724';
        }
    });
}

// ── History row toggle ────────────────────────────────────
function toggleLog(id) {
    var el   = document.getElementById('log-detail-' + id);
    var icon = document.getElementById('icon-' + id);
    var open = el.style.display === 'block';
    el.style.display   = open ? 'none' : 'block';
    icon.className     = open ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
}
</script>
</body>
</html>
