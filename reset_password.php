<?php
// ============================================================
// AUCTION HUB GROUP — reset_password.php
// Set a new password using a valid reset token
// ============================================================

require_once 'config.php';
require_once 'auth.php';

// Redirect already-logged-in users
$token = getTokenFromRequest();
if ($token && verifyJWT($token)) {
    redirect('user_dashboard.php');
}

$resetToken = trim($_GET['token'] ?? '');

// Validate token exists + not expired
$valid   = false;
$expired = false;

if ($resetToken) {
    $stmt = $pdo->prepare("
        SELECT um.user_id, um.meta_value, u.full_name
        FROM user_meta um
        JOIN users u ON u.id = um.user_id
        WHERE um.meta_key = 'reset_token' AND um.meta_value LIKE ?
    ");
    $stmt->execute([$resetToken . '|%']);
    $meta = $stmt->fetch();

    if ($meta) {
        [, $expires] = explode('|', $meta['meta_value'], 2);
        if (strtotime($expires) >= time()) {
            $valid = true;
        } else {
            $expired = true;
        }
    }
}

$pageTitle       = 'Reset Password — ' . APP_NAME;
$pageDescription = 'Set a new password for your Auction Hub Group account.';
$bodyClass       = 'page-auth';
require_once 'header.php';
?>

<div class="auth-page-wrap">
  <div class="auth-card" style="max-width:460px;margin:3rem auto">

    <div class="auth-logo">
      <img src="logo.png" alt="<?= e(APP_NAME) ?>" style="max-height:60px">
    </div>

    <?php if (!$resetToken || (!$valid && !$expired)): ?>

      <!-- Invalid token -->
      <h1 style="font-size:1.5rem;margin-bottom:.5rem">Invalid Reset Link</h1>
      <p class="text-muted">This password reset link is invalid or has already been used.</p>
      <a href="forgot_password.php" class="btn btn-primary" style="margin-top:1rem">
        <i class="fas fa-redo"></i> Request a New Link
      </a>

    <?php elseif ($expired): ?>

      <!-- Expired token -->
      <h1 style="font-size:1.5rem;margin-bottom:.5rem">Link Expired</h1>
      <p class="text-muted">This password reset link has expired. Reset links are valid for 1 hour.</p>
      <a href="forgot_password.php" class="btn btn-primary" style="margin-top:1rem">
        <i class="fas fa-redo"></i> Request a New Link
      </a>

    <?php else: ?>

      <!-- Valid token — show form -->
      <h1 style="font-size:1.5rem;margin-bottom:.25rem">Set New Password</h1>
      <p class="text-muted" style="margin-bottom:1.5rem;font-size:.95rem">
        Hi <?= e($meta['full_name']) ?>! Choose a strong new password below.
      </p>

      <div id="rpAlert" class="alert" style="display:none"></div>

      <form id="rpForm">
        <input type="hidden" name="token" value="<?= e($resetToken) ?>">

        <div class="form-group">
          <label for="rpNew">New Password</label>
          <input type="password" id="rpNew" name="new_password" class="form-control"
                 placeholder="Minimum 8 characters" required minlength="8" autocomplete="new-password">
        </div>

        <div class="form-group">
          <label for="rpConfirm">Confirm New Password</label>
          <input type="password" id="rpConfirm" name="confirm_password" class="form-control"
                 placeholder="Repeat password" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem" id="rpBtn">
          <span class="btn-text"><i class="fas fa-lock"></i> Reset Password</span>
          <span class="btn-spinner" hidden><i class="fas fa-spinner fa-spin"></i> Saving...</span>
        </button>
      </form>

    <?php endif; ?>

    <div style="text-align:center;margin-top:1.5rem">
      <a href="login.php" class="text-muted" style="font-size:.9rem">
        <i class="fas fa-arrow-left"></i> Back to Login
      </a>
    </div>

  </div>
</div>

<script>
(function(){
    const form  = document.getElementById('rpForm');
    if (!form) return;

    const alert = document.getElementById('rpAlert');
    const btn   = document.getElementById('rpBtn');

    function setAlert(msg, type) {
        alert.textContent = msg;
        alert.className   = 'alert alert-' + type;
        alert.style.display = 'block';
    }

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const newPw  = document.getElementById('rpNew').value;
        const confPw = document.getElementById('rpConfirm').value;

        if (newPw.length < 8)    { setAlert('Password must be at least 8 characters.', 'error'); return; }
        if (newPw !== confPw)    { setAlert('Passwords do not match.', 'error'); return; }

        btn.disabled = true;
        btn.querySelector('.btn-text').hidden   = true;
        btn.querySelector('.btn-spinner').hidden = false;
        alert.style.display = 'none';

        try {
            const fd = new FormData(form);
            fd.append('action', 'reset_password');

            const res  = await fetch('auth.php', { method: 'POST', body: fd });
            const data = await res.json();

            setAlert(data.message, data.success ? 'success' : 'error');

            if (data.success) {
                form.reset();
                setTimeout(() => { window.location.href = 'login.php'; }, 2000);
            }
        } catch {
            setAlert('Network error. Please check your connection and try again.', 'error');
        } finally {
            btn.disabled = false;
            btn.querySelector('.btn-text').hidden   = false;
            btn.querySelector('.btn-spinner').hidden = true;
        }
    });
})();
</script>

<?php require_once 'footer.php'; ?>
