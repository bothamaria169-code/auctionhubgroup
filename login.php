<?php
// ============================================================
// AUCTION HUB GROUP — login.php
// Login + Registration on one page, tab-switched
// Handles: JWT login, new account registration, auth redirects
// ============================================================

require_once 'config.php';
require_once 'auth.php';

// Redirect already-logged-in users
$token = getTokenFromRequest();
if ($token) {
    $payload = verifyJWT($token);
    if ($payload) {
        redirect($payload['role'] === 'admin' ? 'admin_dashboard.php' : 'user_dashboard.php');
    }
}

// Determine which tab to open from URL param
$activeTab = in_array($_GET['tab'] ?? '', ['login', 'register']) ? $_GET['tab'] : 'login';

$pageTitle       = 'Log In or Register to Bid';
$pageDescription = 'Log in to your Auction Hub Group account or register free to start bidding on quality repossessed vehicles.';
$bodyClass       = 'page-auth';

require_once 'header.php';
?>

<!-- ══════════════════════════════════════════════════════════
     AUTH PAGE WRAPPER
     ══════════════════════════════════════════════════════════ -->
<main class="auth-page" id="main-content">

    <!-- Left panel: branding / trust -->
    <div class="auth-left" aria-hidden="true">
        <div class="auth-left-inner">
            <a href="index.php" class="auth-logo-link">
                <img src="logo.png" alt="Auction Hub Group" class="auth-logo" width="200">
            </a>
            <h2 class="auth-left-heading">South Africa's Trusted<br>Vehicle Auction Platform</h2>
            <p class="auth-left-sub">Repossessed and bank vehicles. DEKRA inspected. Verified. Bid with confidence.</p>

            <ul class="auth-trust-list">
                <li><i class="fas fa-circle-check" aria-hidden="true"></i> Free account registration</li>
                <li><i class="fas fa-circle-check" aria-hidden="true"></i> One-time R10,000 refundable deposit</li>
                <li><i class="fas fa-circle-check" aria-hidden="true"></i> Approved bidder number within 24hrs</li>
                <li><i class="fas fa-circle-check" aria-hidden="true"></i> Bid live on hundreds of vehicles</li>
                <li><i class="fas fa-circle-check" aria-hidden="true"></i> Nationwide delivery available</li>
            </ul>

            <!-- Stat badges -->
            <div class="auth-stats">
                <?php
                try {
                    $liveCount = (int)$pdo->query("SELECT COUNT(*) FROM auctions WHERE status='active'")->fetchColumn();
                } catch(Exception $e) { $liveCount = 0; }
                ?>
                <div class="auth-stat-badge">
                    <span class="live-dot"></span>
                    <strong><?= $liveCount ?></strong> Live Auctions
                </div>
                <div class="auth-stat-badge">
                    <i class="fas fa-gavel" aria-hidden="true"></i>
                    <strong>Bid</strong> from Anywhere
                </div>
            </div>
        </div>
    </div>

    <!-- Right panel: forms -->
    <div class="auth-right">
        <div class="auth-card">

            <!-- Tab switcher -->
            <div class="auth-tabs" role="tablist" aria-label="Authentication options">
                <button class="auth-tab-btn <?= $activeTab === 'login' ? 'active' : '' ?>"
                        role="tab"
                        aria-selected="<?= $activeTab === 'login' ? 'true' : 'false' ?>"
                        aria-controls="tab-login"
                        id="btn-login"
                        data-tab="login">
                    <i class="fas fa-right-to-bracket" aria-hidden="true"></i> Login
                </button>
                <button class="auth-tab-btn <?= $activeTab === 'register' ? 'active' : '' ?>"
                        role="tab"
                        aria-selected="<?= $activeTab === 'register' ? 'true' : 'false' ?>"
                        aria-controls="tab-register"
                        id="btn-register"
                        data-tab="register">
                    <i class="fas fa-user-plus" aria-hidden="true"></i> Register
                </button>
            </div>

            <!-- ── LOGIN TAB ──────────────────────────────────── -->
            <div class="auth-tab-content <?= $activeTab === 'login' ? 'active' : '' ?>"
                 id="tab-login"
                 role="tabpanel"
                 aria-labelledby="btn-login">

                <div class="auth-tab-header">
                    <h1 class="auth-heading">Welcome back</h1>
                    <p class="auth-subheading">Enter your details to access your account</p>
                </div>

                <div id="login-alert" class="auth-alert" role="alert" aria-live="polite" hidden></div>

                <div id="login-form" data-action="login">

                    <div class="form-group">
                        <label for="login-email" class="form-label">Email address</label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-envelope input-icon" aria-hidden="true"></i>
                            <input type="email"
                                   id="login-email"
                                   name="email"
                                   class="form-input input-with-icon"
                                   placeholder="you@example.com"
                                   autocomplete="email"
                                   required>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="form-label-row">
                            <label for="login-password" class="form-label">Password</label>
                            <a href="forgot_password.php" class="auth-forgot-link" id="forgot-link">Forgot password?</a>
                        </div>
                        <div class="input-icon-wrap">
                            <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                            <input type="password"
                                   id="login-password"
                                   name="password"
                                   class="form-input input-with-icon"
                                   placeholder="••••••••"
                                   autocomplete="current-password"
                                   required>
                            <button type="button"
                                    class="input-icon-btn input-icon-right toggle-password"
                                    aria-label="Show or hide password"
                                    data-target="login-password">
                                <i class="fas fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <button type="button"
                            class="btn btn-primary btn-lg w-100 btn-submit-login"
                            id="btn-submit-login">
                        <span class="btn-text">Login to My Account</span>
                        <span class="btn-spinner" hidden>
                            <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Logging in…
                        </span>
                    </button>

                </div><!-- /#login-form -->

                <p class="auth-switch-text">
                    Don't have an account?
                    <button type="button" class="auth-switch-btn" data-switch-tab="register">
                        Register free
                    </button>
                </p>

                <!-- Forgot password note — now handled by forgot_password.php -->

            </div><!-- /#tab-login -->

            <!-- ── REGISTER TAB — Part 2 below ──────────────── -->
            <!-- ── REGISTER TAB ───────────────────────────────── -->
            <div class="auth-tab-content <?= $activeTab === 'register' ? 'active' : '' ?>"
                 id="tab-register"
                 role="tabpanel"
                 aria-labelledby="btn-register">

                <div class="auth-tab-header">
                    <h1 class="auth-heading">Create your account</h1>
                    <p class="auth-subheading">Free to register — activate your bidder status after approval</p>
                </div>

                <div id="register-alert" class="auth-alert" role="alert" aria-live="polite" hidden></div>

                <div id="register-form" data-action="register">

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="reg-fullname" class="form-label">Full name <span class="req">*</span></label>
                            <div class="input-icon-wrap">
                                <i class="fas fa-user input-icon" aria-hidden="true"></i>
                                <input type="text"
                                       id="reg-fullname"
                                       name="full_name"
                                       class="form-input input-with-icon"
                                       placeholder="John Smith"
                                       autocomplete="name"
                                       required
                                       minlength="2">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="reg-phone" class="form-label">Phone number <span class="req">*</span></label>
                            <div class="input-icon-wrap">
                                <i class="fas fa-phone input-icon" aria-hidden="true"></i>
                                <input type="tel"
                                       id="reg-phone"
                                       name="phone"
                                       class="form-input input-with-icon"
                                       placeholder="0712345678"
                                       autocomplete="tel"
                                       required>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="reg-email" class="form-label">Email address <span class="req">*</span></label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-envelope input-icon" aria-hidden="true"></i>
                            <input type="email"
                                   id="reg-email"
                                   name="email"
                                   class="form-input input-with-icon"
                                   placeholder="you@example.com"
                                   autocomplete="email"
                                   required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="reg-id" class="form-label">SA ID number <span class="req">*</span></label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-id-card input-icon" aria-hidden="true"></i>
                            <input type="text"
                                   id="reg-id"
                                   name="id_number"
                                   class="form-input input-with-icon"
                                   placeholder="8001015009087"
                                   maxlength="13"
                                   autocomplete="off"
                                   required>
                        </div>
                        <span class="form-hint">Required for bidder verification</span>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="reg-password" class="form-label">Password <span class="req">*</span></label>
                            <div class="input-icon-wrap">
                                <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                                <input type="password"
                                       id="reg-password"
                                       name="password"
                                       class="form-input input-with-icon"
                                       placeholder="Min 8 characters"
                                       autocomplete="new-password"
                                       minlength="8"
                                       required>
                                <button type="button"
                                        class="input-icon-btn input-icon-right toggle-password"
                                        aria-label="Toggle password visibility"
                                        data-target="reg-password">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="reg-confirm" class="form-label">Confirm password <span class="req">*</span></label>
                            <div class="input-icon-wrap">
                                <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                                <input type="password"
                                       id="reg-confirm"
                                       name="confirm_password"
                                       class="form-input input-with-icon"
                                       placeholder="Repeat password"
                                       autocomplete="new-password"
                                       minlength="8"
                                       required>
                            </div>
                            <span class="form-hint pw-match-hint" id="pw-match-hint" hidden></span>
                        </div>
                    </div>

                    <!-- Password strength bar -->
                    <div class="pw-strength-wrap" id="pw-strength-wrap" hidden>
                        <div class="pw-strength-bar">
                            <div class="pw-strength-fill" id="pw-strength-fill"></div>
                        </div>
                        <span class="pw-strength-label" id="pw-strength-label">Weak</span>
                    </div>

                    <div class="form-group form-check-group">
                        <label class="form-check-label">
                            <input type="checkbox" id="reg-terms" name="terms" required class="form-check-input">
                            <span>
                                I agree to the
                                <a href="#" target="_blank" rel="noopener noreferrer">terms &amp; conditions</a>
                                and understand the R10,000 bidder registration fee
                            </span>
                        </label>
                    </div>

                    <button type="button"
                            class="btn btn-primary btn-lg w-100 btn-submit-register"
                            id="btn-submit-register">
                        <span class="btn-text">
                            <i class="fas fa-user-plus" aria-hidden="true"></i> Create My Account
                        </span>
                        <span class="btn-spinner" hidden>
                            <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Creating account…
                        </span>
                    </button>

                </div><!-- /#register-form -->

                <p class="auth-switch-text">
                    Already have an account?
                    <button type="button" class="auth-switch-btn" data-switch-tab="login">
                        Login here
                    </button>
                </p>

            </div><!-- /#tab-register -->

        </div><!-- /.auth-card -->

        <!-- EFT registration fee info — shown after register success OR as info note -->
        <div class="auth-eft-note" id="auth-eft-note">
            <div class="auth-eft-header">
                <i class="fas fa-circle-info" aria-hidden="true"></i>
                <strong>How Bidder Activation Works</strong>
            </div>
            <ol class="auth-eft-steps">
                <li>
                    <span class="eft-step-num">1</span>
                    <span>Create your free account (done!)</span>
                </li>
                <li>
                    <span class="eft-step-num">2</span>
                    <span>
                        Pay the <strong>R10,000 registration deposit</strong> via EFT.
                        <span class="eft-bank-inline">
                            Request banking details from your dashboard after registering.
                        </span>
                    </span>
                </li>
                <li>
                    <span class="eft-step-num">3</span>
                    <span>Upload your proof of payment in your dashboard</span>
                </li>
                <li>
                    <span class="eft-step-num">4</span>
                    <span>Admin reviews &amp; assigns your unique bidder number</span>
                </li>
                <li>
                    <span class="eft-step-num">5</span>
                    <span>You're approved — start bidding on any active auction!</span>
                </li>
            </ol>
            <p class="auth-eft-refund">
                <i class="fas fa-rotate-left" aria-hidden="true"></i>
                The R10,000 deposit is fully refundable if you do not win any auction.
            </p>
        </div><!-- /.auth-eft-note -->

    </div><!-- /.auth-right -->

</main><!-- /.auth-page -->

<?php require_once 'footer.php'; ?>

<!-- ── login.php inline JS ────────────────────────────────────
     Handles: tab switching, login AJAX, register AJAX,
     password strength, pw-match check, toggle visibility,
     forgot-password note toggle
     All heavy logic lives in app.js (LoginRegister module)
     This block only wires up the page-specific interactions
──────────────────────────────────────────────────────────── -->
<script>
(function () {
    'use strict';

    // ── Tab switching ────────────────────────────────────────
    const tabBtns    = document.querySelectorAll('.auth-tab-btn');
    const tabPanels  = document.querySelectorAll('.auth-tab-content');

    function switchTab(tabName) {
        tabBtns.forEach(btn => {
            const isActive = btn.dataset.tab === tabName;
            btn.classList.toggle('active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        tabPanels.forEach(panel => {
            panel.classList.toggle('active', panel.id === 'tab-' + tabName);
        });
        // Update URL without reload
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        history.replaceState(null, '', url);
    }

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => switchTab(btn.dataset.tab));
    });
    document.querySelectorAll('[data-switch-tab]').forEach(el => {
        el.addEventListener('click', () => switchTab(el.dataset.switchTab));
    });

    // ── Password toggle visibility ───────────────────────────
    document.querySelectorAll('.toggle-password').forEach(btn => {
        btn.addEventListener('click', function () {
            const input = document.getElementById(this.dataset.target);
            if (!input) return;
            const isText = input.type === 'text';
            input.type = isText ? 'password' : 'text';
            this.querySelector('i').className = isText ? 'fa fa-eye' : 'fa fa-eye-slash';
            this.setAttribute('aria-label', (isText ? 'Show' : 'Hide') + ' password');
        });
    });

    // Forgot password: now links to forgot_password.php (no JS toggle needed)

    // ── Password strength meter ───────────────────────────────
    const pwInput      = document.getElementById('reg-password');
    const strengthWrap = document.getElementById('pw-strength-wrap');
    const strengthFill = document.getElementById('pw-strength-fill');
    const strengthLabel= document.getElementById('pw-strength-label');

    const levels = [
        { min: 0,  max: 30,  label: 'Too short',  color: '#dc3545', width: '15%' },
        { min: 30, max: 50,  label: 'Weak',        color: '#f5a623', width: '35%' },
        { min: 50, max: 70,  label: 'Fair',        color: '#f0c040', width: '55%' },
        { min: 70, max: 90,  label: 'Good',        color: '#28a745', width: '75%' },
        { min: 90, max: 101, label: 'Strong',      color: '#00AEEF', width: '100%' },
    ];

    function scorePassword(pw) {
        let score = 0;
        if (!pw) return 0;
        if (pw.length >= 8)  score += 20;
        if (pw.length >= 12) score += 10;
        if (/[a-z]/.test(pw)) score += 10;
        if (/[A-Z]/.test(pw)) score += 20;
        if (/[0-9]/.test(pw)) score += 20;
        if (/[^a-zA-Z0-9]/.test(pw)) score += 20;
        return Math.min(score, 100);
    }

    if (pwInput) {
        pwInput.addEventListener('input', function () {
            const val = this.value;
            if (!val) { strengthWrap.hidden = true; return; }
            strengthWrap.hidden = false;
            const score = scorePassword(val);
            const level = levels.find(l => score >= l.min && score < l.max) || levels[0];
            strengthFill.style.width = level.width;
            strengthFill.style.backgroundColor = level.color;
            strengthLabel.textContent = level.label;
            strengthLabel.style.color = level.color;
        });
    }

    // ── Password match hint ───────────────────────────────────
    const confirmInput = document.getElementById('reg-confirm');
    const matchHint    = document.getElementById('pw-match-hint');

    function checkMatch() {
        if (!pwInput || !confirmInput || !confirmInput.value) {
            if (matchHint) matchHint.hidden = true;
            return;
        }
        const match = pwInput.value === confirmInput.value;
        matchHint.hidden = false;
        matchHint.textContent = match ? '✓ Passwords match' : '✗ Passwords do not match';
        matchHint.className = 'form-hint pw-match-hint ' + (match ? 'hint-success' : 'hint-error');
    }

    if (confirmInput) confirmInput.addEventListener('input', checkMatch);
    if (pwInput)      pwInput.addEventListener('input', checkMatch);

    // ── Login form AJAX ───────────────────────────────────────
    const loginBtn   = document.getElementById('btn-submit-login');
    const loginAlert = document.getElementById('login-alert');

    function setAlert(el, message, type) {
        if (!el) return;
        el.textContent  = message;
        el.className    = 'auth-alert auth-alert-' + type;
        el.hidden       = false;
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function setLoading(btn, loading) {
        const text    = btn.querySelector('.btn-text');
        const spinner = btn.querySelector('.btn-spinner');
        btn.disabled  = loading;
        if (text)    text.hidden    = loading;
        if (spinner) spinner.hidden = !loading;
    }

    if (loginBtn) {
        loginBtn.addEventListener('click', async function () {
            const email    = document.getElementById('login-email')?.value.trim();
            const password = document.getElementById('login-password')?.value;

            if (!email || !password) {
                setAlert(loginAlert, 'Please enter your email and password.', 'error');
                return;
            }

            setLoading(this, true);
            loginAlert.hidden = true;

            try {
                const fd = new FormData();
                fd.append('email', email);
                fd.append('password', password);

                const res  = await fetch('auth.php?action=login', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    setAlert(loginAlert, data.message, 'success');
                    setTimeout(() => { window.location.href = data.redirect || 'user_dashboard.php'; }, 800);
                } else {
                    setAlert(loginAlert, data.message || 'Login failed. Please try again.', 'error');
                    setLoading(loginBtn, false);
                }
            } catch (err) {
                setAlert(loginAlert, 'Network error. Please check your connection and try again.', 'error');
                setLoading(loginBtn, false);
            }
        });

        // Allow Enter key in password field
        document.getElementById('login-password')?.addEventListener('keydown', e => {
            if (e.key === 'Enter') loginBtn.click();
        });
    }

    // ── Register form AJAX ────────────────────────────────────
    const registerBtn   = document.getElementById('btn-submit-register');
    const registerAlert = document.getElementById('register-alert');

    if (registerBtn) {
        registerBtn.addEventListener('click', async function () {
            const fullName = document.getElementById('reg-fullname')?.value.trim();
            const email    = document.getElementById('reg-email')?.value.trim();
            const phone    = document.getElementById('reg-phone')?.value.trim();
            const idNum    = document.getElementById('reg-id')?.value.trim();
            const password = document.getElementById('reg-password')?.value;
            const confirm  = document.getElementById('reg-confirm')?.value;
            const terms    = document.getElementById('reg-terms')?.checked;

            // Client-side validation
            if (!fullName || fullName.length < 2) {
                setAlert(registerAlert, 'Please enter your full name.', 'error'); return;
            }
            if (!email || !email.includes('@')) {
                setAlert(registerAlert, 'Please enter a valid email address.', 'error'); return;
            }
            if (!phone || phone.replace(/\D/g,'').length < 10) {
                setAlert(registerAlert, 'Please enter a valid phone number.', 'error'); return;
            }
            if (!idNum || idNum.length < 6) {
                setAlert(registerAlert, 'Please enter your SA ID number.', 'error'); return;
            }
            if (!password || password.length < 8) {
                setAlert(registerAlert, 'Password must be at least 8 characters.', 'error'); return;
            }
            if (password !== confirm) {
                setAlert(registerAlert, 'Passwords do not match.', 'error'); return;
            }
            if (!terms) {
                setAlert(registerAlert, 'Please accept the terms and conditions to continue.', 'error'); return;
            }

            setLoading(this, true);
            registerAlert.hidden = true;

            try {
                const fd = new FormData();
                fd.append('full_name',        fullName);
                fd.append('email',            email);
                fd.append('phone',            phone);
                fd.append('id_number',        idNum);
                fd.append('password',         password);
                fd.append('confirm_password', confirm);

                const res  = await fetch('auth.php?action=register', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    // Report the signup to GA4 + Google Ads before navigating away.
                    // data.conversion.send_to is empty until a real Ads label is
                    // configured, in which case only the GA4 event is sent.
                    const c = data.conversion || {};

                    // GTM path
                    window.dataLayer = window.dataLayer || [];
                    window.dataLayer.push({
                        event:          'ahg_registration',
                        signup_method:  'login_page',
                        value:          c.value,
                        currency:       c.currency || 'ZAR',
                        transaction_id: c.transaction_id
                    });

                    // Direct gtag path
                    if (typeof gtag === 'function') {
                        gtag('event', 'sign_up', { method: 'bidder_registration' });
                        // Google Ads auto-created this action from the "Submit lead form"
                        // goal during account setup. Fired here — on a genuine completed
                        // registration — never on page load, which would count every ad
                        // click as a conversion before anyone filled anything in.
                        gtag('event', 'conversion_event_submit_lead_form', {
                            value:          c.value,
                            currency:       c.currency || 'ZAR',
                            transaction_id: c.transaction_id
                        });
                        if (c.send_to) {
                            gtag('event', 'conversion', {
                                send_to:        c.send_to,
                                value:          c.value,
                                currency:       c.currency || 'ZAR',
                                transaction_id: c.transaction_id
                            });
                        }
                    }
                    // Show success state before redirect
                    setAlert(registerAlert, '🎉 ' + data.message, 'success');
                    document.getElementById('auth-eft-note').classList.add('highlight');
                    setTimeout(() => { window.location.href = data.redirect || 'user_dashboard.php'; }, 1500);
                } else {
                    setAlert(registerAlert, data.message || 'Registration failed. Please try again.', 'error');
                    setLoading(registerBtn, false);
                }
            } catch (err) {
                setAlert(registerAlert, 'Network error. Please check your connection.', 'error');
                setLoading(registerBtn, false);
            }
        });
    }

})();
</script>
