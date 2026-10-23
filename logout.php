<?php
// ============================================================
// AUCTION HUB GROUP — logout.php
// Terminates the user session cleanly:
//   1. Increments jwt_version in DB (invalidates all tokens)
//   2. Clears the pva_token HttpOnly cookie
//   3. Destroys the PHP session
//   4. Redirects to login.php with a flash message
//
// Can be called directly (GET /logout.php) or via AJAX
// (auth.php?action=logout — which app.js uses).
// This file handles the direct browser navigation case.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// ── Invalidate the JWT in the database ───────────────────────
$token = getTokenFromRequest();

if ($token) {
    $payload = verifyJWT($token);
    if ($payload && isset($payload['sub'])) {
        // Bump jwt_version so any existing tokens for this user
        // will fail the version check in requireAuth()
        $pdo->prepare("UPDATE users SET jwt_version = jwt_version + 1 WHERE id = ?")
            ->execute([$payload['sub']]);
    }
}

// ── Clear the JWT cookie ─────────────────────────────────────
clearTokenCookie();

// ── Destroy the PHP session ───────────────────────────────────
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];

    // Remove the session cookie from the browser
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

// ── Redirect to login ─────────────────────────────────────────
// Start a fresh session just long enough to store the flash message
session_name('pva_session');
session_start();
$_SESSION['flash_message'] = 'You have been logged out successfully.';
$_SESSION['flash_type']    = 'info';

header('Location: login.php');
exit;
