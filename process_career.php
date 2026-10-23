<?php
// ============================================================
// AHG Career Portal — process_career.php
// Handles job application form submissions (JSON API)
// ============================================================
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// ── Input validation ──────────────────────────────────────
$fullName    = trim($_POST['full_name']       ?? '');
$email       = trim($_POST['email']           ?? '');
$phone       = trim($_POST['phone']           ?? '');
$strengths   = trim($_POST['strengths']       ?? '');
$weaknesses  = trim($_POST['weaknesses']      ?? '');
$coverLetter = trim($_POST['cover_letter']    ?? '');
$positionApplied = trim($_POST['position_applied'] ?? 'Sales Representative');

// Structured references: Company + Role + Phone per reference
$refLines = [];
for ($i = 1; $i <= 3; $i++) {
    $company = trim($_POST["ref{$i}_company"] ?? '');
    $role    = trim($_POST["ref{$i}_role"]    ?? '');
    $rphone  = trim($_POST["ref{$i}_phone"]   ?? '');
    if ($company || $role || $rphone) {
        $refLines[] = implode(' | ', array_filter([$company, $role, $rphone]));
    }
}
$refCompanies = implode("\n", $refLines);

if (!$fullName || !$email || !$phone || !$strengths || !$weaknesses) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// ── Ensure tables exist + migrate new columns ─────────────
try {
    $pdo->exec(file_get_contents(__DIR__ . '/ahg_career_portal.sql'));
} catch (Exception $e) {
    // Tables likely already exist — ignore
}
foreach ([
    "ALTER TABLE career_applications ADD COLUMN contract_token     VARCHAR(64)  DEFAULT NULL",
    "ALTER TABLE career_applications ADD COLUMN induction_sent_at  DATETIME     DEFAULT NULL",
    "ALTER TABLE career_applications ADD COLUMN contract_signed_at DATETIME     DEFAULT NULL",
    "ALTER TABLE career_applications ADD COLUMN position_applied   VARCHAR(150) DEFAULT NULL",
] as $_mig) {
    try { $pdo->exec($_mig); } catch (Exception $e) {}
}
unset($_mig);

// ── Duplicate check ────────────────────────────────────────
// Pending applications can be re-submitted (updates existing record with new files).
// Applications already under review / approved / rejected are blocked.
$existingAppId     = null;
$existingAppStatus = null;
try {
    $dup = $pdo->prepare("SELECT id, status FROM career_applications WHERE email = ? LIMIT 1");
    $dup->execute([$email]);
    $existing = $dup->fetch();
    if ($existing) {
        if (in_array($existing['status'], ['reviewing', 'approved', 'rejected'])) {
            $statusLabel = ucfirst($existing['status']);
            echo json_encode(['success' => false,
                'message' => "Your application is already {$statusLabel}. Please email careers@auctionhubgroup.co.za if you need to make changes."]);
            exit;
        }
        // Status is 'pending' — allow resubmit to update files/details
        $existingAppId     = (int)$existing['id'];
        $existingAppStatus = $existing['status'];
    }
} catch (Exception $e) {
    error_log('[AHG careers] duplicate check: ' . $e->getMessage());
}

// ── Upload directories (constants defined + auto-created in config.php) ───
$docsDir   = UPLOAD_CAREER_DOCS;
$photosDir = UPLOAD_CAREER_PHOTOS;
$videosDir = UPLOAD_CAREER_VIDEOS;

$cvPath    = null;
$idPath    = null;
$certPath  = null;
$photoPath = null;
$videoPath = null;

// CV (required)
if (empty($_FILES['cv']['name'])) {
    echo json_encode(['success' => false, 'message' => 'A CV is required. Please upload your CV.']);
    exit;
}
$cvResult = handleUpload($_FILES['cv'], $docsDir,
    ['application/pdf','application/msword',
     'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
     'image/jpeg','image/jpg','image/png','image/webp'],
    'cv');
if (!$cvResult['success']) {
    echo json_encode(['success' => false, 'message' => 'CV upload failed: ' . $cvResult['message']]);
    exit;
}
$cvPath = $cvResult['path'];

// ID document (required)
if (empty($_FILES['id_doc']['name'])) {
    echo json_encode(['success' => false, 'message' => 'A certified ID document is required.']);
    exit;
}
$idResult = handleUpload($_FILES['id_doc'], $docsDir,
    ['application/pdf','image/jpeg','image/jpg','image/png','image/webp'],
    'id');
if (!$idResult['success']) {
    echo json_encode(['success' => false, 'message' => 'ID document upload failed: ' . $idResult['message']]);
    exit;
}
$idPath = $idResult['path'];

// Certifications (optional)
if (!empty($_FILES['cert']['name'])) {
    $certResult = handleUpload($_FILES['cert'], $docsDir,
        ['application/pdf','image/jpeg','image/jpg','image/png','image/webp'],
        'cert');
    if ($certResult['success']) {
        $certPath = $certResult['path'];
    }
}

// Profile photo (optional)
if (!empty($_FILES['profile_photo']['name'])) {
    $photoResult = handleUpload($_FILES['profile_photo'], $photosDir,
        ['image/jpeg','image/jpg','image/png','image/webp'],
        'photo');
    if ($photoResult['success']) {
        $photoPath = $photoResult['path'];
    }
}

// Video introduction (optional) — separate 100MB limit
if (!empty($_FILES['intro_video']['name']) && $_FILES['intro_video']['error'] === UPLOAD_ERR_OK) {
    $videoMaxSize = 100 * 1024 * 1024; // 100 MB
    if ($_FILES['intro_video']['size'] > $videoMaxSize) {
        echo json_encode(['success' => false, 'message' => 'Video is too large. Maximum size is 100MB (about 1 minute of compressed video).']);
        exit;
    }
    $finfo     = new finfo(FILEINFO_MIME_TYPE);
    $videoMime = $finfo->file($_FILES['intro_video']['tmp_name']);
    $allowedVid = ['video/mp4','video/quicktime','video/x-msvideo','video/avi','video/mpeg','video/webm','video/ogg'];
    if (in_array($videoMime, $allowedVid)) {
        $ext       = strtolower(pathinfo($_FILES['intro_video']['name'], PATHINFO_EXTENSION));
        $vidFile   = 'video_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $vidFull   = $videosDir . $vidFile;
        if (move_uploaded_file($_FILES['intro_video']['tmp_name'], $vidFull)) {
            $videoPath = 'uploads/career_videos/' . $vidFile;
        }
    }
}

// ── Insert or update database record ──────────────────────
try {
    // Ensure video_path column exists
    try { $pdo->exec("ALTER TABLE career_applications ADD COLUMN video_path VARCHAR(255) DEFAULT NULL"); } catch (Exception $ex) {}

    if ($existingAppId) {
        // Resubmit: update the pending application with fresh data + new files
        $updFields = "full_name=?, phone=?, strengths=?, weaknesses=?,
                      ref_companies=?, cover_letter=?, position_applied=?,
                      cv_path=?, id_doc_path=?, cert_path=?,
                      submitted_at=NOW()";
        $updVals   = [
            $fullName, $phone, $strengths, $weaknesses,
            $refCompanies, $coverLetter, $positionApplied,
            $cvPath, $idPath, $certPath,
        ];
        if ($photoPath) { $updFields .= ', profile_photo=?'; $updVals[] = $photoPath; }
        if ($videoPath) { $updFields .= ', video_path=?';    $updVals[] = $videoPath; }
        $updVals[] = $existingAppId;
        $pdo->prepare("UPDATE career_applications SET {$updFields} WHERE id=?")->execute($updVals);
        $newAppId = $existingAppId;
    } else {
        // New applicant — fresh insert
        $contractToken = bin2hex(random_bytes(24));
        $stmt = $pdo->prepare("
            INSERT INTO career_applications
                (full_name, email, phone, profile_photo, strengths, weaknesses,
                 ref_companies, cv_path, id_doc_path, cert_path, cover_letter,
                 video_path, contract_token, position_applied)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $fullName, $email, $phone, $photoPath,
            $strengths, $weaknesses, $refCompanies,
            $cvPath, $idPath, $certPath, $coverLetter,
            $videoPath, $contractToken, $positionApplied,
        ]);
        $newAppId = (int)$pdo->lastInsertId();
    }

    // Confirmation to the applicant (may go to Gmail spam — non-fatal)
    try {
        sendCareerApplicationEmail($email, $fullName, $positionApplied);
    } catch (Throwable $mailErr) {
        error_log('[AHG careers] applicant confirmation email failed: ' . $mailErr->getMessage());
    }

    // Admin notification to careers@auctionhubgroup.co.za — .co.za delivery is reliable
    try {
        sendCareerApplicationAdminAlert($fullName, $email, $phone, $positionApplied, $newAppId);
    } catch (Throwable $alertErr) {
        error_log('[AHG careers] admin alert email failed: ' . $alertErr->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Your application has been received. We will review it and be in touch.',
    ]);
} catch (Exception $e) {
    error_log('[AHG careers] DB insert failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again or email careers@auctionhubgroup.co.za.']);
}
