<?php
// ============================================================
// AUCTION HUB GROUP — forgot_password.php
// Request a password reset email
// ============================================================

require_once 'config.php';
require_once 'auth.php';

// Redirect already-logged-in users
$token = getTokenFromRequest();
if ($token && verifyJWT($token)) {
    redirect('user_dashboard.php');
}

$pageTitle       = 'Forgot Password — ' . APP_NAME;
$pageDescription = 'Reset your Auction Hub Group account password.';
$bodyClass       = 'page-auth';
require_once 'header.php';
?>

<div class="auth-page-wrap">
  <div class="auth-card" style="max-width:460px;margin:3rem auto">

    <div class="auth-logo">
      <img src="logo.png" alt="<?= e(APP_NAME) ?>" style="max-height:60px">
    </div>

    <h1 style="font-size:1.5rem;margin-bottom:.25rem">Forgot Your Password?</h1>
    <p class="text-muted" style="margin-bottom:1.5rem;font-size:.95rem">
      Enter your email address and we'll send you a link to reset your password.
    </p>

    <div id="fpAlert" class="alert" style="display:none"></div>

    <form id="fpForm">
      <div class="form-group">
        <label for="fpEmail">Email Address</label>
        <input type="email" id="fpEmail" name="email" class="form-control"
               placeholder="you@example.com" required autocomplete="email">
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem" id="fpBtn">
        <span class="btn-text"><i class="fas fa-envelope"></i> Send Reset Link</span>
        <span class="btn-spinner" hidden><i class="fas fa-spinner fa-spin"></i> Sending...</span>
      </button>
    </form>

    <div style="text-align:center;margin-top:1.5rem">
      <a href="login.php" class="text-muted" style="font-size:.9rem">
        <i class="fas fa-arrow-left"></i> Back to Login
      </a>
    </div>

  </div>
</div>

<script>
(function(){
    const form  = document.getElementById('fpForm');
    const alert = document.getElementById('fpAlert');
    const btn   = document.getElementById('fpBtn');

    function setAlert(msg, type) {
        alert.textContent = msg;
        alert.className   = 'alert alert-' + type;
        alert.style.display = 'block';
    }

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const email = document.getElementById('fpEmail').value.trim();
        if (!email) { setAlert('Please enter your email address.', 'error'); return; }

        btn.disabled = true;
        btn.querySelector('.btn-text').hidden   = true;
        btn.querySelector('.btn-spinner').hidden = false;
        alert.style.display = 'none';

        try {
            const fd = new FormData();
            fd.append('action', 'forgot_password');
            fd.append('email', email);

            const res  = await fetch('auth.php', { method: 'POST', body: fd });
            const data = await res.json();

            setAlert(data.message, data.success ? 'success' : 'error');
            if (data.success) form.reset();
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
