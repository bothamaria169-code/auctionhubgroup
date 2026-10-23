<?php
// ============================================================
// AUCTION HUB GROUP — auth.php
// JWT-based authentication handler
// Called via AJAX from login.php and register.php
// Endpoint: auth.php?action=login|register|logout|refresh
// ============================================================

// Buffer output ONLY when this file is the direct AJAX entry point.
// When auth.php is included by admin_dashboard.php (or any other page),
// we must NOT call ob_start() — doing so would buffer all of that page's
// HTML output and cause a blank page.
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    ob_start(); // captures PHP 8.x notices/deprecations from config.php
}

require_once 'config.php';
require_once __DIR__ . '/tracking_helper.php';  // attribution + gadsSendTo()

// ── JWT Utility Functions ─────────────────────────────────────

/**
 * Base64URL encode (JWT-safe, no padding)
 */
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64URL decode
 */
function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}

/**
 * Generate a signed JWT token for a user
 */
function generateJWT(array $user): string {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

    $now     = time();
    $payload = [
        'iss'            => APP_URL,
        'iat'            => $now,
        'exp'            => $now + JWT_EXPIRY,
        'sub'            => $user['id'],
        'email'          => $user['email'],
        'full_name'      => $user['full_name'],
        'role'           => $user['role'],
        'bidder_status'  => $user['bidder_status'],
        'bidder_number'  => $user['bidder_number'] ?? null,
        'jwt_version'    => $user['jwt_version'] ?? 0,
    ];

    $payloadEncoded = base64url_encode(json_encode($payload));
    $signature      = base64url_encode(
        hash_hmac('sha256', "$header.$payloadEncoded", JWT_SECRET, true)
    );

    return "$header.$payloadEncoded.$signature";
}

/**
 * Verify and decode a JWT token
 * Returns payload array on success, false on failure
 */
function verifyJWT(string $token): array|false {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;

    [$header, $payload, $signature] = $parts;

    // Verify signature
    $expected = base64url_encode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    );

    if (!hash_equals($expected, $signature)) return false;

    $data = json_decode(base64url_decode($payload), true);
    if (!$data) return false;

    // Check expiry
    if ($data['exp'] < time()) return false;

    return $data;
}

/**
 * Get JWT from cookie or Authorization header
 */
function getTokenFromRequest(): string {
    // Check HttpOnly cookie first
    if (!empty($_COOKIE['pva_token'])) {
        return $_COOKIE['pva_token'];
    }

    // Check Authorization: Bearer <token>
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($auth, 'Bearer ')) {
        return substr($auth, 7);
    }

    return '';
}

/**
 * Validate the incoming request token and return the user payload.
 * Optionally checks jwt_version against the database to detect revoked tokens.
 */
function requireAuth(bool $checkVersion = true): array {
    global $pdo;

    $token = getTokenFromRequest();
    if (!$token) {
        http_response_code(401);
        die(json_encode(['success' => false, 'message' => 'Authentication required.']));
    }

    $payload = verifyJWT($token);
    if (!$payload) {
        http_response_code(401);
        die(json_encode(['success' => false, 'message' => 'Invalid or expired session. Please log in again.']));
    }

    if ($checkVersion) {
        $stmt = $pdo->prepare("SELECT jwt_version, status, bidder_status FROM users WHERE id = ?");
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] === 'suspended') {
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'Your account has been suspended.']));
        }

        if ((int)$user['jwt_version'] !== (int)$payload['jwt_version']) {
            http_response_code(401);
            die(json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']));
        }

        // Sync bidder_status in case it changed since token was issued
        $payload['bidder_status'] = $user['bidder_status'];
    }

    return $payload;
}

/**
 * Resolve the current user for PAGE RENDERING (not an AJAX/API endpoint).
 * Unlike requireAuth(), this never dies — it returns null for anyone
 * without a valid, non-revoked session so pages can render a logged-out
 * state (e.g. show a login prompt) instead of a UI that looks logged in
 * but then fails when the user tries to act (place a bid, etc).
 */
function getPageUser(): ?array {
    global $pdo;

    $token = getTokenFromRequest();
    if (!$token) return null;

    $payload = verifyJWT($token);
    if (!$payload) return null;

    $stmt = $pdo->prepare("SELECT jwt_version, status, bidder_status, bidder_number FROM users WHERE id = ?");
    $stmt->execute([$payload['sub']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] === 'suspended') return null;
    if ((int)$user['jwt_version'] !== (int)$payload['jwt_version']) return null;

    $payload['bidder_status'] = $user['bidder_status'];
    $payload['bidder_number'] = $user['bidder_number'];

    return $payload;
}

/**
 * Set the JWT as an HttpOnly cookie
 */
function setTokenCookie(string $token): void {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
             || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
    setcookie('pva_token', $token, [
        'expires'  => time() + JWT_EXPIRY,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,   // Auto-detected: true on HTTPS production
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Clear the JWT cookie
 */
function clearTokenCookie(): void {
    setcookie('pva_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Sync JWT payload into $_SESSION for easy server-side access
 */
function syncSession(array $payload): void {
    $_SESSION['user_id']       = $payload['sub'];
    $_SESSION['user_name']     = $payload['full_name'];
    $_SESSION['user_email']    = $payload['email'];
    $_SESSION['user_role']     = $payload['role'];
    $_SESSION['bidder_status'] = $payload['bidder_status'];
    $_SESSION['bidder_number'] = $payload['bidder_number'];
}

// ── Route Actions ─────────────────────────────────────────────
// Only handle HTTP requests when this file is the entry point,
// not when it is included as a library (e.g. by logout.php).

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) :

ob_clean(); // Discard any notices/warnings from config.php captured since ob_start() above
header('Content-Type: application/json');
$action = $_REQUEST['action'] ?? '';

// Top-level error handler: ensures every failure returns valid JSON,
// never an HTML error page (which would cause "Network error" in the browser).
set_exception_handler(function (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    error_log('[auth.php] Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => 'A server error occurred. Please try again or contact support.',
    ]);
});

try {
switch ($action) {

    // ── REGISTER ──────────────────────────────────────────────
    case 'register':
        $fullName  = trim($_POST['full_name']  ?? '');
        $email     = trim(strtolower($_POST['email'] ?? ''));
        $phone     = trim($_POST['phone']     ?? '');
        $idNumber  = trim($_POST['id_number'] ?? '');
        $password  = $_POST['password']       ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';

        // Validation
        $errors = [];
        if (strlen($fullName) < 2)      $errors[] = 'Full name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email address required.';
        if (strlen($phone) < 10)        $errors[] = 'Valid phone number required.';
        if (strlen($idNumber) < 6)      $errors[] = 'ID number is required.';
        if (strlen($password) < 8)      $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm)     $errors[] = 'Passwords do not match.';

        if ($errors) {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
            exit;
        }

        // Check email uniqueness
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
            exit;
        }

        // Insert user
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (full_name, email, phone, id_number, password, role, bidder_status)
            VALUES (?, ?, ?, ?, ?, 'user', 'registered')
        ");
        $stmt->execute([$fullName, $email, $phone, $idNumber, $hashed]);
        $userId = $pdo->lastInsertId();

        // Stamp the ad click that produced this bidder, so cost-per-registration
        // can be read straight off the users table. Wrapped because the acq_*
        // columns arrive via ahg_ads_attribution.sql — a database that has not
        // had that migration run must still be able to register users.
        try {
            $attr = attributionSnapshot();
            if ($attr) {
                $pdo->prepare("
                    UPDATE users
                    SET acq_source = ?, acq_gclid = ?, acq_campaign = ?,
                        acq_term = ?, acq_landing = ?, acq_raw = ?
                    WHERE id = ?
                ")->execute([
                    attributionSourceLabel(),
                    $attr['gclid'] ?? ($attr['gbraid'] ?? ($attr['wbraid'] ?? null)),
                    $attr['utm_campaign'] ?? null,
                    $attr['utm_term'] ?? null,
                    $attr['landing_page'] ?? null,
                    attributionJson(),
                    $userId,
                ]);
            }
        } catch (Exception $e) {
            error_log('Attribution stamp skipped: ' . $e->getMessage());
        }

        // Fetch the new user to generate token
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $token   = generateJWT($user);
        $payload = verifyJWT($token);
        setTokenCookie($token);
        syncSession($payload);

        // Send welcome email (non-fatal — registration succeeds even if email fails)
        try {
            require_once 'email_helper.php';
            if (function_exists('sendWelcomeEmail')) {
                sendWelcomeEmail($user);
            }
        } catch (Exception $e) {
            error_log('Welcome email error: ' . $e->getMessage());
        }

        // Conversion payload for the front-end tag. send_to is empty until a real
        // Google Ads conversion label is set in config.php, in which case the
        // page simply skips the Ads call and still reports the GA4 event.
        $conversion = [
            'send_to'        => gadsSendTo('GADS_LABEL_REGISTRATION'),
            'value'          => defined('GADS_REGISTRATION_VALUE') ? GADS_REGISTRATION_VALUE : 0,
            'currency'       => 'ZAR',
            'transaction_id' => 'reg-' . $userId,
        ];

        ob_end_clean(); echo json_encode([
            'success'    => true,
            'message'    => 'Registration successful! Welcome to Auction Hub Group.',
            'redirect'   => 'user_dashboard.php',
            'conversion' => $conversion,
            'user'       => [
                'full_name'     => $user['full_name'],
                'email'         => $user['email'],
                'bidder_status' => $user['bidder_status'],
            ],
        ]);
        break;


    // ── LOGIN ─────────────────────────────────────────────────
    case 'login':
        // Rate limit: 10 login attempts per minute per IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rlDir = sys_get_temp_dir() . '/pva_rate_limit';
        if (!is_dir($rlDir)) @mkdir($rlDir, 0700, true);
        $rlFile = $rlDir . '/' . md5('login:' . $ip) . '.json';
        $rlNow = time();
        $rlData = file_exists($rlFile) ? (json_decode(file_get_contents($rlFile), true) ?: []) : [];
        $rlData = array_values(array_filter($rlData, fn($t) => $t > $rlNow - 60));
        if (count($rlData) >= 10) {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Too many login attempts. Please wait a minute.']);
            exit;
        }
        $rlData[] = $rlNow;
        file_put_contents($rlFile, json_encode($rlData), LOCK_EX);

        $email    = trim(strtolower($_POST['email']    ?? ''));
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            exit;
        }

        if ($user['status'] === 'suspended') {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Your account has been suspended. Please contact us.']);
            exit;
        }

        $token   = generateJWT($user);
        $payload = verifyJWT($token);
        setTokenCookie($token);
        syncSession($payload);

        $redirect = $user['role'] === 'admin' ? 'admin_dashboard.php' : 'user_dashboard.php';

        ob_end_clean(); echo json_encode([
            'success'  => true,
            'message'  => 'Welcome back, ' . $user['full_name'] . '!',
            'redirect' => $redirect,
            'user'     => [
                'full_name'     => $user['full_name'],
                'role'          => $user['role'],
                'bidder_status' => $user['bidder_status'],
            ],
        ]);
        break;


    // ── LOGOUT ───────────────────────────────────────────────
    case 'logout':
        // Increment jwt_version to invalidate all existing tokens for this user
        $token = getTokenFromRequest();
        if ($token) {
            $payload = verifyJWT($token);
            if ($payload) {
                $pdo->prepare("UPDATE users SET jwt_version = jwt_version + 1 WHERE id = ?")
                    ->execute([$payload['sub']]);
            }
        }

        clearTokenCookie();

        // Clear session
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']);
        }
        session_destroy();

        ob_end_clean(); echo json_encode([
            'success'  => true,
            'message'  => 'You have been logged out.',
            'redirect' => 'login.php',
        ]);
        break;


    // ── REFRESH — re-check auth status ───────────────────────
    case 'check':
        $token = getTokenFromRequest();
        if (!$token) {
            ob_end_clean(); echo json_encode(['authenticated' => false]);
            exit;
        }

        $payload = verifyJWT($token);
        if (!$payload) {
            ob_end_clean(); echo json_encode(['authenticated' => false]);
            exit;
        }

        // Always return fresh bidder_status from DB
        $stmt = $pdo->prepare("SELECT bidder_status, bidder_number, status FROM users WHERE id = ?");
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();

        ob_end_clean(); echo json_encode([
            'authenticated'  => true,
            'user_id'        => $payload['sub'],
            'full_name'      => $payload['full_name'],
            'role'           => $payload['role'],
            'bidder_status'  => $user['bidder_status'] ?? $payload['bidder_status'],
            'bidder_number'  => $user['bidder_number']  ?? $payload['bidder_number'],
        ]);
        break;


    // ── FORGOT PASSWORD — generate reset token & send email ─────
    case 'forgot_password':
        // Rate limit: 5 reset requests per minute per IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rlDir = sys_get_temp_dir() . '/pva_rate_limit';
        if (!is_dir($rlDir)) @mkdir($rlDir, 0700, true);
        $rlFile = $rlDir . '/' . md5('forgot:' . $ip) . '.json';
        $rlNow = time();
        $rlData = file_exists($rlFile) ? (json_decode(file_get_contents($rlFile), true) ?: []) : [];
        $rlData = array_values(array_filter($rlData, fn($t) => $t > $rlNow - 60));
        if (count($rlData) >= 5) {
            ob_end_clean(); echo json_encode(['success' => true, 'message' => 'If that email exists, a reset link has been sent.']);
            exit;
        }
        $rlData[] = $rlNow;
        file_put_contents($rlFile, json_encode($rlData), LOCK_EX);

        $email = trim(strtolower($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, full_name, email, status FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always return success to prevent email enumeration
        if (!$user || $user['status'] === 'suspended') {
            ob_end_clean(); echo json_encode(['success' => true, 'message' => 'If that email exists, a reset link has been sent.']);
            exit;
        }

        // Generate a secure token (valid 1 hour)
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);

        // Store in user_meta (upsert)
        $exists = $pdo->prepare("SELECT id FROM user_meta WHERE user_id = ? AND meta_key = 'reset_token'");
        $exists->execute([$user['id']]);
        if ($exists->fetch()) {
            $pdo->prepare("UPDATE user_meta SET meta_value = ? WHERE user_id = ? AND meta_key = 'reset_token'")
                ->execute([$token . '|' . $expires, $user['id']]);
        } else {
            $pdo->prepare("INSERT INTO user_meta (user_id, meta_key, meta_value) VALUES (?, 'reset_token', ?)")
                ->execute([$user['id'], $token . '|' . $expires]);
        }

        try {
            require_once 'email_helper.php';
            sendPasswordResetEmail($user, $token);
        } catch (Exception $e) {
            error_log('Password reset email error: ' . $e->getMessage());
        }

        ob_end_clean(); echo json_encode(['success' => true, 'message' => 'If that email exists, a reset link has been sent.']);
        break;


    // ── RESET PASSWORD — validate token & set new password ──────
    case 'reset_password':
        $token      = trim($_POST['token']            ?? '');
        $newPw      = $_POST['new_password']           ?? '';
        $confirmPw  = $_POST['confirm_password']       ?? '';

        if (!$token)                        { ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Invalid reset link.']);                   exit; }
        if (strlen($newPw) < 8)             { ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']); exit; }
        if ($newPw !== $confirmPw)          { ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);               exit; }

        // Find the token in user_meta
        $stmt = $pdo->prepare("
            SELECT um.user_id, um.meta_value
            FROM user_meta um
            WHERE um.meta_key = 'reset_token' AND um.meta_value LIKE ?
        ");
        $stmt->execute([$token . '|%']);
        $meta = $stmt->fetch();

        if (!$meta) {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Invalid or expired reset link. Please request a new one.']);
            exit;
        }

        // Check expiry
        [, $expires] = explode('|', $meta['meta_value'], 2);
        if (strtotime($expires) < time()) {
            $pdo->prepare("DELETE FROM user_meta WHERE user_id = ? AND meta_key = 'reset_token'")
                ->execute([$meta['user_id']]);
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'This reset link has expired. Please request a new one.']);
            exit;
        }

        // Update password + invalidate all sessions
        $pdo->prepare("
            UPDATE users SET password = ?, jwt_version = jwt_version + 1 WHERE id = ?
        ")->execute([password_hash($newPw, PASSWORD_BCRYPT), $meta['user_id']]);

        // Remove used token
        $pdo->prepare("DELETE FROM user_meta WHERE user_id = ? AND meta_key = 'reset_token'")
            ->execute([$meta['user_id']]);

        ob_end_clean(); echo json_encode(['success' => true, 'message' => 'Password reset successfully. You can now log in.', 'redirect' => 'login.php']);
        break;


    default:
        http_response_code(400);
        ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        break;
}
} catch (Throwable $e) {
    // Catch any PDO / runtime exception and return clean JSON
    ob_end_clean();
    http_response_code(500);
    error_log('[auth.php] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => 'A server error occurred. Please try again or contact support.',
    ]);
}

endif; // end: only run routes when accessed directly
