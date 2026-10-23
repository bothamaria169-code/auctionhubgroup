<?php
// ============================================================
// AHG Admin Career API — admin_career_api.php
// Handles career application status updates and worker creation
// ============================================================

// Suppress PHP notices/warnings on shared hosting so they never
// corrupt the JSON response body even if display_errors=On in php.ini
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);

ob_start();
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/auth.php';
} catch (Throwable $bootErr) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Boot error: ' . $bootErr->getMessage()]);
    exit;
}

// Admin auth guard
$token = getTokenFromRequest();
if (!$token) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorised. Please log in again.']);
    exit;
}
$payload = verifyJWT($token);
if (!$payload || ($payload['role'] ?? '') !== 'admin') {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}

$action = $_POST['action'] ?? '';

// ── Update application status ──────────────────────────────
if ($action === 'update_status') {
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $notes  = trim($_POST['notes'] ?? '');
    $valid  = ['pending', 'reviewing', 'approved', 'rejected'];

    if (!$id || !in_array($status, $valid)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid input.']);
        exit;
    }

    try {
        $pdo->prepare("UPDATE career_applications SET status=?, admin_notes=?, updated_at=NOW() WHERE id=?")
            ->execute([$status, $notes, $id]);
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Status updated to "' . ucfirst($status) . '".']);
    } catch (Throwable $e) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ── Send induction contract email manually ─────────────────
if ($action === 'send_contract') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid application ID.']);
        exit;
    }

    try {
        // Ensure columns exist
        foreach ([
            "ALTER TABLE career_applications ADD COLUMN contract_token     VARCHAR(64)  DEFAULT NULL",
            "ALTER TABLE career_applications ADD COLUMN induction_sent_at  DATETIME     DEFAULT NULL",
            "ALTER TABLE career_applications ADD COLUMN contract_signed_at DATETIME     DEFAULT NULL",
            "ALTER TABLE career_applications ADD COLUMN position_applied   VARCHAR(150) DEFAULT NULL",
        ] as $_mig) {
            try { $pdo->exec($_mig); } catch (Exception $ex) {}
        }

        $appStmt = $pdo->prepare("SELECT * FROM career_applications WHERE id = ? LIMIT 1");
        $appStmt->execute([$id]);
        $appRow = $appStmt->fetch();

        if (!$appRow) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Application not found.']);
            exit;
        }
        if (!$appRow['email']) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'No email address on this application.']);
            exit;
        }

        // Generate token if not already set
        $contractToken = $appRow['contract_token'] ?? '';
        if (!$contractToken) {
            $contractToken = bin2hex(random_bytes(24));
            $pdo->prepare("UPDATE career_applications SET contract_token=? WHERE id=?")
                ->execute([$contractToken, $id]);
        }

        // Mark induction as sent (now)
        $pdo->prepare("UPDATE career_applications SET induction_sent_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$id]);

        $appRef = 'AHG/DM/' . date('Y') . '/' . str_pad($id, 4, '0', STR_PAD_LEFT);

        require_once __DIR__ . '/email_helper.php';
        sendInductionContractEmail($appRow['email'], $appRow['full_name'], $contractToken, $appRef);

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Induction contract sent to ' . $appRow['email'] . '.',
        ]);
    } catch (Throwable $e) {
        error_log('[AHG career api] send_contract error: ' . $e->getMessage());
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to send: ' . $e->getMessage()]);
    }
    exit;
}

// ── Create worker account ──────────────────────────────────
if ($action === 'create_worker') {
    $appId  = (int)($_POST['app_id'] ?? 0);
    $name   = trim($_POST['full_name'] ?? '');
    $phone  = preg_replace('/\s+/', '', trim($_POST['phone'] ?? ''));
    $pin    = trim($_POST['pin'] ?? '');
    $code   = trim($_POST['worker_code'] ?? '');

    if (!$name || !$phone) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Name and phone are required.']);
        exit;
    }

    // Auto-generate PIN if not provided
    if (!$pin) {
        $pin = str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    } elseif (!preg_match('/^\d{4,6}$/', $pin)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'PIN must be 4–6 digits.']);
        exit;
    }

    try {
        // Silent migration — add email column to workers if missing
        try { $pdo->exec("ALTER TABLE workers ADD COLUMN email VARCHAR(150) DEFAULT NULL"); } catch (Exception $ex) {}

        $check = $pdo->prepare("SELECT id FROM workers WHERE phone = ?");
        $check->execute([$phone]);
        if ($check->fetchColumn()) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'A worker with this phone number already exists.']);
            exit;
        }

        // Look up applicant email from career_applications
        $applicantEmail = '';
        if ($appId) {
            $eStmt = $pdo->prepare("SELECT email FROM career_applications WHERE id = ? LIMIT 1");
            $eStmt->execute([$appId]);
            $applicantEmail = (string)($eStmt->fetchColumn() ?: '');
        }

        $hash = password_hash($pin, PASSWORD_BCRYPT);
        $pdo->prepare("
            INSERT INTO workers (application_id, full_name, email, phone, pin_hash, worker_code)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$appId ?: null, $name, $applicantEmail ?: null, $phone, $hash, $code ?: null]);

        if ($appId) {
            $pdo->prepare("UPDATE career_applications SET status='approved', updated_at=NOW() WHERE id=?")->execute([$appId]);
        }

        // Send portal access email — load email_helper only here
        $emailMsg = '';
        if ($applicantEmail) {
            try {
                require_once __DIR__ . '/email_helper.php';
                sendWorkerPortalAccessEmail($applicantEmail, $name, $phone, $pin, $code);
                $emailMsg = ' | Access email sent to ' . $applicantEmail;
            } catch (Throwable $emailErr) {
                error_log('[AHG career api] portal access email failed: ' . $emailErr->getMessage());
                $emailMsg = ' | (Email could not be sent — check mail config)';
            }
        }

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => "Worker account created for {$name}. Phone: {$phone} | PIN: {$pin} | Code: " . ($code ?: 'N/A') . $emailMsg,
        ]);
    } catch (Throwable $e) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

ob_clean();
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit;
