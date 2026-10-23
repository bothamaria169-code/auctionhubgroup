<?php
// ============================================================
// AHG Sign Contract — sign_contract.php
// Applicants sign their induction contract via a secure token link
// ============================================================
$pageTitle       = 'Sign Your Induction Contract';
$pageDescription = 'Review and sign your AHG Sales Representative Induction Contract.';
$bodyClass       = 'page-sign-contract';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email_helper.php';

// Migrations (run silently — columns may already exist)
foreach ([
    "ALTER TABLE career_applications ADD COLUMN contract_token     VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE career_applications ADD COLUMN induction_sent_at  DATETIME    DEFAULT NULL",
    "ALTER TABLE career_applications ADD COLUMN contract_signed_at DATETIME    DEFAULT NULL",
] as $_mig) {
    try { $pdo->exec($_mig); } catch (Exception $e) {}
}
unset($_mig);

$token = trim($_GET['token'] ?? '');

// ── No token: redirect to careers ─────────────────────────
if (!$token) {
    header('Location: careers.php');
    exit;
}

// ── Look up application ───────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM career_applications WHERE contract_token = ? LIMIT 1");
$stmt->execute([$token]);
$app = $stmt->fetch();

if (!$app) {
    // Invalid or expired token
    $invalidToken = true;
} else {
    $alreadySigned = !empty($app['contract_signed_at']);
}

// ── Handle POST (signing) ─────────────────────────────────
$signError   = '';
$signSuccess = false;

if (!isset($invalidToken) && !$alreadySigned && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sigName = trim($_POST['sig_name'] ?? '');
    $agreed  = isset($_POST['agreed']);

    if (!$sigName || !$agreed) {
        $signError = 'Please type your full name and tick the agreement checkbox.';
    } elseif (mb_strtolower(trim($sigName)) !== mb_strtolower(trim($app['full_name']))) {
        $signError = 'The name you entered does not match your application name (' . e($app['full_name']) . '). Please check spelling.';
    } else {
        // Mark as signed and move to reviewing
        $pdo->prepare("
            UPDATE career_applications
            SET contract_signed_at = NOW(), status = 'reviewing', updated_at = NOW()
            WHERE id = ?
        ")->execute([$app['id']]);

        // Alert admin
        sendContractSignedAdminAlert((int)$app['id'], $app['full_name'], $app['email'], $app['phone']);

        $signSuccess  = true;
        $alreadySigned = true;
    }
}

require_once __DIR__ . '/header.php';
?>

<!-- ── Hero ──────────────────────────────────────────────── -->
<section style="background:linear-gradient(135deg,#003f73 0%,#005BA1 60%,#0078d7 100%);padding:3rem 1.5rem 2.5rem;text-align:center;">
    <div class="container" style="max-width:680px;margin:0 auto;">
        <span style="display:inline-block;background:rgba(255,255,255,.15);color:#fff;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:.3rem .9rem;border-radius:20px;margin-bottom:.9rem;">
            <i class="fas fa-file-signature" style="margin-right:.35rem;font-size:.7rem;"></i>Induction Contract
        </span>
        <h1 style="color:#fff;font-size:clamp(1.5rem,3.5vw,2.25rem);margin-bottom:.6rem;line-height:1.2;">
            <?php if (isset($invalidToken)): ?>
                Invalid or Expired Link
            <?php elseif ($alreadySigned): ?>
                Contract Already Signed
            <?php else: ?>
                Review &amp; Sign Your Contract
            <?php endif; ?>
        </h1>
        <?php if (!isset($invalidToken) && !$alreadySigned): ?>
        <p style="color:rgba(255,255,255,.8);font-size:.95rem;max-width:500px;margin:0 auto;">
            Please read the contract below carefully, then sign at the bottom to begin your induction.
        </p>
        <?php endif; ?>
    </div>
</section>

<main style="background:#f5f7fa;padding:2.5rem 1.5rem 4rem;">
<div class="container" style="max-width:780px;margin:0 auto;">

<?php if (isset($invalidToken)): ?>
    <!-- Invalid token -->
    <div style="background:#fff;border-radius:14px;padding:3rem 2rem;text-align:center;box-shadow:0 2px 16px rgba(0,0,0,.06);">
        <div style="width:64px;height:64px;background:#fde8e8;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
            <i class="fas fa-triangle-exclamation" style="font-size:1.75rem;color:#e53935;"></i>
        </div>
        <h2 style="color:#003f73;font-size:1.3rem;margin-bottom:.75rem;">This Link Is Invalid</h2>
        <p style="color:#555;font-size:.95rem;max-width:440px;margin:0 auto 1.5rem;line-height:1.75;">
            This contract link has expired, already been used, or doesn't exist. Please check the email we sent you and use the original link, or contact us if you need help.
        </p>
        <a href="careers.php" style="display:inline-block;background:#005BA1;color:#fff;padding:.75rem 2rem;border-radius:8px;font-weight:700;text-decoration:none;font-size:.9rem;">
            <i class="fas fa-arrow-left" style="margin-right:.4rem;"></i>Back to Careers
        </a>
        &nbsp;
        <a href="mailto:careers@auctionhubgroup.co.za" style="display:inline-block;background:#f0f4f9;color:#003f73;padding:.75rem 1.5rem;border-radius:8px;font-weight:600;text-decoration:none;font-size:.9rem;">
            Contact Us
        </a>
    </div>

<?php elseif ($signSuccess): ?>
    <!-- Freshly signed -->
    <div style="background:#fff;border-radius:14px;padding:3rem 2rem;text-align:center;box-shadow:0 2px 16px rgba(0,0,0,.06);">
        <div style="width:72px;height:72px;background:#e6f9ee;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
            <i class="fas fa-circle-check" style="font-size:2.25rem;color:#28a745;"></i>
        </div>
        <h2 style="color:#003f73;font-size:1.5rem;margin-bottom:.75rem;">Contract Signed!</h2>
        <p style="color:#555;font-size:.95rem;max-width:480px;margin:0 auto 1.25rem;line-height:1.75;">
            Thank you, <strong><?= e($app['full_name']) ?></strong>. Your Induction Contract has been signed and submitted to the AHG team for review.
        </p>
        <div style="background:#e6f9ee;border:1px solid #b2dfdb;border-radius:10px;padding:1rem 1.5rem;max-width:440px;margin:0 auto 1.75rem;text-align:left;">
            <p style="color:#1a5c3a;font-size:.9rem;margin:0;line-height:1.75;">
                <i class="fas fa-clock" style="margin-right:.4rem;color:#28a745;"></i>
                <strong>What happens next?</strong><br>
                Our team will review your contract within <strong>2–3 business days</strong>. Once approved, you will receive an email with your Worker Portal login details (phone + PIN) so you can start your induction immediately.
            </p>
        </div>
        <a href="index.php" style="display:inline-block;background:#005BA1;color:#fff;padding:.75rem 2rem;border-radius:8px;font-weight:700;text-decoration:none;font-size:.9rem;">
            <i class="fas fa-home" style="margin-right:.4rem;"></i>Back to Home
        </a>
    </div>

<?php elseif ($alreadySigned): ?>
    <!-- Already signed (not fresh) -->
    <div style="background:#fff;border-radius:14px;padding:3rem 2rem;text-align:center;box-shadow:0 2px 16px rgba(0,0,0,.06);">
        <div style="width:64px;height:64px;background:#e6f9ee;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
            <i class="fas fa-circle-check" style="font-size:1.75rem;color:#28a745;"></i>
        </div>
        <h2 style="color:#003f73;font-size:1.3rem;margin-bottom:.75rem;">Already Signed</h2>
        <p style="color:#555;font-size:.9rem;max-width:440px;margin:0 auto 1.5rem;line-height:1.75;">
            You already signed this contract on <strong><?= date('d M Y \a\t H:i', strtotime($app['contract_signed_at'])) ?></strong>.
            We're currently reviewing your application. You'll receive your portal login details by email once approved.
        </p>
        <a href="index.php" style="display:inline-block;background:#005BA1;color:#fff;padding:.75rem 2rem;border-radius:8px;font-weight:700;text-decoration:none;font-size:.9rem;">
            Back to Home
        </a>
    </div>

<?php else: ?>
    <!-- Show contract + sign form -->

    <!-- Applicant summary -->
    <div style="background:#fff;border-radius:14px;padding:1.5rem 2rem;margin-bottom:1.5rem;box-shadow:0 2px 16px rgba(0,0,0,.06);display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <div style="width:44px;height:44px;background:#e6f0fc;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-user" style="color:#005BA1;font-size:1.1rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#003f73;font-size:1rem;"><?= e($app['full_name']) ?></div>
            <div style="color:#666;font-size:.85rem;"><?= e($app['email']) ?> &nbsp;·&nbsp; <?= e($app['phone']) ?></div>
        </div>
        <div style="margin-left:auto;">
            <span style="background:#fff8e1;color:#7d5a00;font-size:.78rem;font-weight:700;padding:.3rem .85rem;border-radius:20px;border:1px solid #ffe082;">
                <i class="fas fa-hourglass-half" style="font-size:.68rem;margin-right:.25rem;"></i>Awaiting Signature
            </span>
        </div>
    </div>

    <!-- Contract Document -->
    <div style="background:#fff;border-radius:14px;padding:2rem 2.5rem;margin-bottom:1.5rem;box-shadow:0 2px 16px rgba(0,0,0,.06);font-size:.92rem;line-height:1.9;color:#334155;">
        <div style="border-bottom:2px solid #003f73;padding-bottom:1rem;margin-bottom:1.5rem;">
            <div style="font-weight:800;color:#003f73;font-size:1.05rem;text-transform:uppercase;letter-spacing:.06em;">Letter of Appointment — Induction Period</div>
            <div style="color:#888;font-size:.8rem;margin-top:.25rem;">Reference: AHG/DM/<?= date('Y') ?>/<?= str_pad($app['id'], 4, '0', STR_PAD_LEFT) ?> &nbsp;·&nbsp; Date: <?= date('d F Y') ?></div>
        </div>

        <p>Dear <strong><?= e($app['full_name']) ?></strong>,</p>

        <p>On behalf of <strong>Auction Hub Group (Pty) Ltd</strong> ("AHG"), we are pleased to inform you that you have been <strong>accepted onto our Sales Representative Induction Programme</strong>.</p>

        <p><strong>1. Role</strong><br>
        Sales Representative — Vehicle Advertising</p>

        <p><strong>2. Induction Period</strong><br>
        Your induction commences on the date you sign this contract and continues until you have generated <strong>3 (three) qualified leads</strong> as defined in clause 4. There is no fixed end date — your performance determines the duration.</p>

        <p><strong>3. Compensation During Induction</strong><br>
        You will receive a guaranteed basic salary of <strong>R6,500 per month</strong>, paid regardless of sales, plus <strong>10.5% commission</strong> on every vehicle sold under your name and a monthly performance bonus when you exceed the average target. A Rain SIM data allowance (50GB day + 50GB night) is provided for work-related communications. Salary is paid on the 25th of each month, less statutory PAYE and UIF deductions.</p>

        <p><strong>4. Definition of a Qualified Lead</strong><br>
        A qualified lead is a prospective buyer who (a) contacts AHG directly as a result of your digital advertising activity, and (b) expresses a genuine intent to purchase a vehicle. All leads must be logged in the AHG Worker Portal on the date of contact.</p>

        <p><strong>5. Placement After Induction</strong><br>
        Upon generating your <strong>3rd confirmed qualified lead</strong>, you complete the induction programme and continue in your role on the AHG sales team. Eligibility for permanent positions, promotions, and other roles is thereafter assessed through AHG's standard HR process, which may include further interviews and assessments.</p>

        <p><strong>6. Daily Reporting</strong><br>
        You are required to submit a daily activity report via the AHG Worker Portal by 18:00 (6 PM) on each working day. Reports must include: (a) platforms posted on, (b) number of listings posted, (c) screenshot proof of postings, and (d) details of any leads received that day.</p>

        <p><strong>7. Conduct and Representation</strong><br>
        While representing AHG across digital platforms you must: act honestly and professionally at all times; use only AHG-approved vehicle information and images; not make representations beyond what is authorised; and maintain the confidentiality of any non-public AHG information.</p>

        <p><strong>8. POPIA Compliance</strong><br>
        All personal information you process in the course of this role is subject to the Protection of Personal Information Act 4 of 2013 ("POPIA"). You may only use personal data for the purposes of this role and must not share or retain it for any other purpose.</p>

        <p><strong>9. Acceptance</strong><br>
        By signing below, you confirm that you have read, understood, and agree to the terms of this Induction Period appointment. This document constitutes a binding agreement between you and Auction Hub Group (Pty) Ltd.</p>

        <div style="border-top:1px solid #e5eaf0;margin-top:1.5rem;padding-top:1.25rem;display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <div style="font-size:.75rem;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.35rem;">On behalf of AHG</div>
                <div style="font-weight:700;color:#003f73;">Auction Hub Group (Pty) Ltd</div>
                <div style="font-size:.82rem;color:#888;">80 Sterling Rd, Kosmosdal, Centurion</div>
            </div>
            <div>
                <div style="font-size:.75rem;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.35rem;">Applicant</div>
                <div style="font-weight:700;color:#003f73;"><?= e($app['full_name']) ?></div>
                <div style="font-size:.82rem;color:#888;">Signed electronically via <?= e(APP_URL) ?></div>
            </div>
        </div>
    </div>

    <!-- Error -->
    <?php if ($signError): ?>
    <div style="background:#fde8e8;border:1px solid #f5c6cb;border-radius:8px;padding:.9rem 1.1rem;margin-bottom:1.25rem;color:#721c24;font-weight:600;font-size:.9rem;">
        <i class="fas fa-circle-exclamation" style="margin-right:.4rem;"></i><?= e($signError) ?>
    </div>
    <?php endif; ?>

    <!-- Signature Form -->
    <div style="background:#fff;border-radius:14px;padding:2rem 2.25rem;box-shadow:0 2px 16px rgba(0,0,0,.06);">
        <h3 style="color:#003f73;font-size:1.05rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.6rem;">
            <i class="fas fa-pen-to-square" style="color:#005BA1;"></i> Sign &amp; Accept
        </h3>
        <form method="POST" action="">
            <div style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#003f73;margin-bottom:.5rem;">
                    Type your full name to sign <span style="color:#e53935;">*</span>
                </label>
                <input type="text" name="sig_name" required
                       placeholder="<?= e($app['full_name']) ?>"
                       value="<?= isset($_POST['sig_name']) ? e($_POST['sig_name']) : '' ?>"
                       style="padding:.75rem 1rem;border:1.5px solid #d0dae8;border-radius:8px;font-size:1rem;font-style:italic;font-family:Georgia,serif;width:100%;box-sizing:border-box;outline:none;"
                       onfocus="this.style.borderColor='#005BA1'" onblur="this.style.borderColor='#d0dae8'">
                <p style="font-size:.78rem;color:#999;margin:.4rem 0 0;">Must match exactly: <strong><?= e($app['full_name']) ?></strong></p>
            </div>
            <div style="margin-bottom:1.5rem;">
                <label style="display:flex;align-items:flex-start;gap:.75rem;cursor:pointer;">
                    <input type="checkbox" name="agreed" required
                           style="margin-top:.2rem;width:16px;height:16px;cursor:pointer;flex-shrink:0;"
                           <?= isset($_POST['agreed']) ? 'checked' : '' ?>>
                    <span style="font-size:.88rem;color:#334155;line-height:1.65;">
                        I, <strong><?= e($app['full_name']) ?></strong>, confirm that I have read and fully understood the Induction Contract above and agree to be bound by its terms. I understand that generating <strong>3 qualified leads</strong> completes my induction and secures my place on the AHG sales team, and that permanent positions and promotions are assessed thereafter through AHG's standard HR process.
                    </span>
                </label>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
                <p style="font-size:.78rem;color:#aaa;margin:0;">
                    <i class="fas fa-shield-halved" style="margin-right:.3rem;color:#888;"></i>
                    Signed electronically · IP and timestamp recorded · POPIA compliant
                </p>
                <button type="submit"
                        style="background:#28a745;color:#fff;padding:.85rem 2.25rem;border:none;border-radius:8px;font-size:.95rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:.5rem;"
                        onmouseover="this.style.background='#1e7e34'" onmouseout="this.style.background='#28a745'">
                    <i class="fas fa-signature"></i> Sign &amp; Accept Contract
                </button>
            </div>
        </form>
    </div>

<?php endif; ?>

</div>
</main>

<style>
@media(max-width:600px){
    div[style*="grid-template-columns:1fr 1fr"]{grid-template-columns:1fr!important;}
}
</style>

<?php require_once __DIR__ . '/footer.php'; ?>
