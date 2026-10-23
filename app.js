/* ============================================================
   AUCTION HUB GROUP — app.js
   Single unified JavaScript file — all pages
   All modules are gated by DOM element presence checks
   ============================================================ */

'use strict';

// ── GLOBAL STATE ──────────────────────────────────────────────
const PVA = {
    currentUser:    null,
    bidPollingTimer: null,
    countdownTimers: [],
    tickerContent:  [],
    toastQueue:     [],
};

/* ============================================================
   1. UTILITY FUNCTIONS
   ============================================================ */

/**
 * Format a number as South African Rand
 * e.g. 10000 → "R 10,000.00"
 */
function formatRand(amount) {
    if (isNaN(amount)) return 'R 0.00';
    return 'R ' + parseFloat(amount).toLocaleString('en-ZA', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/**
 * Format seconds into HH:MM:SS
 */
function formatCountdown(seconds) {
    if (seconds <= 0) return '00:00:00';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return [h, m, s].map(v => String(v).padStart(2, '0')).join(':');
}

/**
 * Format a timestamp as "2 mins ago", "just now", etc.
 */
function timeAgo(timestamp) {
    const diff = Math.floor((Date.now() - new Date(timestamp).getTime()) / 1000);
    if (diff < 10)  return 'just now';
    if (diff < 60)  return diff + 's ago';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHTML(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(str)));
    return div.innerHTML;
}

/**
 * Debounce a function call
 */
function debounce(fn, delay = 300) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
}

/**
 * Copy text to clipboard + show feedback
 */
function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const original = btn ? btn.innerHTML : null;
        if (btn) {
            btn.innerHTML = '<i class="fas fa-check"></i>';
            btn.style.color = 'var(--success)';
            setTimeout(() => {
                btn.innerHTML = original;
                btn.style.color = '';
            }, 1800);
        }
        showToast('Copied to clipboard!', 'success');
    }).catch(() => {
        showToast('Could not copy. Please copy manually.', 'error');
    });
}

/**
 * Get initials from a full name (for avatars)
 */
function getInitials(name) {
    return (name || 'U')
        .split(' ')
        .map(w => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

/**
 * Serialize a form into a plain object
 */
function serializeForm(form) {
    const data = {};
    new FormData(form).forEach((val, key) => { data[key] = val; });
    return data;
}

/**
 * POST JSON to a PHP endpoint, return parsed JSON
 */
async function apiPost(url, data) {
    const formData = new FormData();
    Object.entries(data).forEach(([k, v]) => formData.append(k, v));
    const res = await fetch(url, { method: 'POST', body: formData });
    if (!res.ok) throw new Error('Network error ' + res.status);
    return res.json();
}

/**
 * GET JSON from a PHP endpoint
 */
async function apiGet(url) {
    const res = await fetch(url);
    if (!res.ok) throw new Error('Network error ' + res.status);
    return res.json();
}

/* ============================================================
   2. TOAST NOTIFICATIONS
   ============================================================ */

function showToast(message, type = 'info', duration = 4000) {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    const icons = {
        success: 'fa-circle-check',
        error:   'fa-circle-xmark',
        warning: 'fa-triangle-exclamation',
        info:    'fa-circle-info',
    };

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <i class="fas ${icons[type] || icons.info}"></i>
        <span>${escapeHTML(message)}</span>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('hiding');
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

/* ============================================================
   3. AUTH MODULE
   ============================================================ */

const Auth = {
    async check() {
        try {
            const data = await apiGet('auth.php?action=check');
            PVA.currentUser = data.authenticated ? data : null;
            Auth.updateNav(data);
            return data;
        } catch {
            PVA.currentUser = null;
            return { authenticated: false };
        }
    },

    updateNav(user) {
        const loginLinks    = document.querySelectorAll('[data-auth="guest"]');
        const dashLinks     = document.querySelectorAll('[data-auth="user"]');
        const adminLinks    = document.querySelectorAll('[data-auth="admin"]');
        const statusDots    = document.querySelectorAll('.nav-bidder-status');
        const userNameSpans = document.querySelectorAll('.nav-user-name');

        if (user && user.authenticated) {
            loginLinks.forEach(el => el.classList.add('hidden'));
            dashLinks.forEach(el => el.classList.remove('hidden'));
            adminLinks.forEach(el => {
                el.classList.toggle('hidden', user.role !== 'admin');
            });

            userNameSpans.forEach(el => {
                el.textContent = user.full_name ? user.full_name.split(' ')[0] : 'Account';
            });

            statusDots.forEach(el => {
                el.innerHTML = Auth.statusDotHTML(user.bidder_status);
                el.classList.remove('hidden');
            });
        } else {
            loginLinks.forEach(el => el.classList.remove('hidden'));
            dashLinks.forEach(el => el.classList.add('hidden'));
            adminLinks.forEach(el => el.classList.add('hidden'));
            statusDots.forEach(el => el.classList.add('hidden'));
        }
    },

    statusDotHTML(status) {
        const map = {
            verified_bidder:  { cls: 'verified',  label: 'Verified Bidder' },
            pending_approval: { cls: 'pending',   label: 'Pending Approval' },
            pending_payment:  { cls: 'pending',   label: 'Payment Pending' },
            suspended:        { cls: 'suspended', label: 'Suspended' },
            registered:       { cls: 'registered',label: 'Registered' },
        };
        const s = map[status] || map.registered;
        return `
            <span class="bidder-status-dot">
                <span class="status-dot ${s.cls}"></span>
                ${escapeHTML(s.label)}
            </span>`;
    },

    requireAuth(redirectUrl = 'login.php') {
        if (!PVA.currentUser || !PVA.currentUser.authenticated) {
            window.location.href = redirectUrl;
            return false;
        }
        return true;
    },

    requireAdmin() {
        if (!PVA.currentUser || PVA.currentUser.role !== 'admin') {
            window.location.href = 'index.php';
            return false;
        }
        return true;
    },
};

/* ============================================================
   4. NAVBAR MODULE
   ============================================================ */

const Navbar = {
    init() {
        const nav       = document.getElementById('main-nav');
        const hamburger = document.getElementById('nav-hamburger');
        const mobile    = document.getElementById('nav-mobile');

        if (!nav) return;

        // Scroll shadow
        window.addEventListener('scroll', () => {
            nav.classList.toggle('scrolled', window.scrollY > 20);
        }, { passive: true });

        // Hamburger toggle
        if (hamburger && mobile) {
            hamburger.addEventListener('click', () => {
                const isOpen = hamburger.classList.toggle('open');
                mobile.classList.toggle('open', isOpen);
                document.body.style.overflow = isOpen ? 'hidden' : '';
            });

            // Close on outside click
            document.addEventListener('click', e => {
                if (!nav.contains(e.target)) {
                    hamburger.classList.remove('open');
                    mobile.classList.remove('open');
                    document.body.style.overflow = '';
                }
            });

            // Close on mobile link click
            mobile.querySelectorAll('a').forEach(a => {
                a.addEventListener('click', () => {
                    hamburger.classList.remove('open');
                    mobile.classList.remove('open');
                    document.body.style.overflow = '';
                });
            });
        }

        // Active link highlight
        const currentPath = window.location.pathname.split('/').pop() || 'index.php';
        document.querySelectorAll('.nav-link[href]').forEach(link => {
            const href = link.getAttribute('href').split('/').pop();
            if (href === currentPath) link.classList.add('active');
        });

        // Logout handler (any element with data-action="logout")
        document.querySelectorAll('[data-action="logout"]').forEach(el => {
            el.addEventListener('click', async e => {
                e.preventDefault();
                try {
                    const data = await apiGet('auth.php?action=logout');
                    if (data.success) {
                        window.location.href = 'login.php';
                    }
                } catch {
                    window.location.href = 'login.php';
                }
            });
        });
    },
};

/* ============================================================
   5. TICKER MODULE
   ============================================================ */

const Ticker = {
    init() {
        const wrap = document.getElementById('ticker-scroll');
        if (!wrap) return;
        Ticker.load(wrap);
        setInterval(() => Ticker.load(wrap), 60000);
    },

    async load(wrap) {
        try {
            const data = await apiGet('api.php?action=ticker');
            if (data.items && data.items.length) {
                // Duplicate for seamless loop
                const items = [...data.items, ...data.items];
                wrap.innerHTML = items.map(item =>
                    `<span class="ticker-item">${escapeHTML(item)}</span>`
                ).join('');
            }
        } catch {
            // Leave existing content
        }
    },
};

/* ============================================================
   6. COUNTDOWN TIMER MODULE
   ============================================================ */

const Countdown = {
    init() {
        document.querySelectorAll('[data-countdown]').forEach(el => {
            const endTime = new Date(el.dataset.countdown).getTime();
            const startTime = el.dataset.start ? new Date(el.dataset.start).getTime() : null;
            Countdown.start(el, endTime, startTime);
        });
    },

    start(el, endTime, startTime = null) {
        const tick = () => {
            const now = Date.now();

            // Not started yet
            if (startTime && now < startTime) {
                const secsToStart = Math.floor((startTime - now) / 1000);
                el.textContent = 'Starts in ' + Countdown.formatFull(secsToStart);
                el.classList.add('upcoming');
                el.classList.remove('closed');
                return;
            }

            const remaining = Math.floor((endTime - now) / 1000);

            if (remaining <= 0) {
                el.textContent = 'CLOSED';
                el.classList.add('closed');
                el.classList.remove('upcoming');

                // Trigger closed state on bid panel if present
                const panel = document.getElementById('bid-panel');
                if (panel) {
                    panel.classList.add('auction-closed');
                    document.getElementById('bid-submit-btn')?.setAttribute('disabled', 'true');
                    const statusBadge = document.getElementById('auction-status-badge');
                    if (statusBadge) {
                        statusBadge.className = 'badge badge-closed';
                        statusBadge.textContent = 'CLOSED';
                    }
                }
                return;
            }

            el.textContent = formatCountdown(remaining);

            // Warn when under 5 minutes
            el.classList.toggle('urgent', remaining < 300);

            PVA.countdownTimers.push(setTimeout(tick, 1000));
        };

        tick();
    },

    formatFull(seconds) {
        const d = Math.floor(seconds / 86400);
        const h = Math.floor((seconds % 86400) / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        if (d > 0) return `${d}d ${h}h`;
        if (h > 0) return `${h}h ${m}m`;
        return [m, s].map(v => String(v).padStart(2, '0')).join(':');
    },
};

/* ============================================================
   7. BID POLLING MODULE (auction.php)
   ============================================================ */

const BidPolling = {
    auctionId: null,
    lastBidId: 0,

    init() {
        const panel = document.getElementById('bid-panel');
        if (!panel) return;

        BidPolling.auctionId = panel.dataset.auctionId;
        if (!BidPolling.auctionId) return;

        BidPolling.poll();
        PVA.bidPollingTimer = setInterval(BidPolling.poll, 5000);
    },

    async poll() {
        try {
            const data = await apiGet(
                `api.php?action=updates&id=${BidPolling.auctionId}&last_bid_id=${BidPolling.lastBidId}`
            );

            // Update current price
            const priceEl = document.getElementById('current-bid-amount');
            if (priceEl && data.current_price !== undefined) {
                const newPrice = formatRand(data.current_price);
                if (priceEl.textContent !== newPrice) {
                    priceEl.textContent = newPrice;
                    priceEl.classList.add('animate-count-up');
                    setTimeout(() => priceEl.classList.remove('animate-count-up'), 600);
                }
            }

            // Update bid count
            const countEl = document.getElementById('bid-count');
            if (countEl && data.bid_count !== undefined) {
                countEl.textContent = data.bid_count + (data.bid_count === 1 ? ' bid' : ' bids');
            }

            // Update reserve status
            if (data.reserve_met !== undefined) {
                const reserveEl = document.getElementById('reserve-status');
                if (reserveEl) {
                    reserveEl.textContent = data.reserve_met ? '✓ Reserve met' : 'Reserve not met';
                    reserveEl.className = 'price-reserve ' + (data.reserve_met ? 'met' : '');
                }
            }

            // Update minimum next bid
            const minBidEl = document.getElementById('min-bid-hint');
            if (minBidEl && data.min_next_bid !== undefined) {
                minBidEl.textContent = 'Minimum bid: ' + formatRand(data.min_next_bid);
            }

            // Update bid input minimum
            const bidInput = document.getElementById('bid-amount-input');
            if (bidInput && data.min_next_bid !== undefined) {
                bidInput.min = data.min_next_bid;
                if (parseFloat(bidInput.value) < data.min_next_bid) {
                    bidInput.value = data.min_next_bid;
                }
            }

            // Append new bid feed items
            if (data.new_bids && data.new_bids.length) {
                BidPolling.appendBids(data.new_bids);
                BidPolling.lastBidId = data.new_bids[data.new_bids.length - 1].id;
            }

            // Check if current user is winning/outbid
            if (PVA.currentUser && data.winning_bidder_id !== undefined) {
                const isWinning = String(data.winning_bidder_id) === String(PVA.currentUser.user_id);
                const statusEl = document.getElementById('my-bid-status');
                if (statusEl) {
                    statusEl.className = 'badge ' + (isWinning ? 'badge-winning' : 'badge-outbid');
                    statusEl.textContent = isWinning ? 'You are winning!' : 'You have been outbid';
                }
            }

        } catch (err) {
            console.warn('Bid poll failed:', err.message);
        }
    },

    appendBids(bids) {
        const feed = document.getElementById('bid-feed-list');
        if (!feed) return;

        bids.forEach(bid => {
            const item = document.createElement('div');
            item.className = 'bid-feed-item';
            if (PVA.currentUser && String(bid.user_id) === String(PVA.currentUser.user_id)) {
                item.classList.add('winning');
            }
            item.innerHTML = `
                <div class="bid-feed-bidder">
                    <div class="bidder-avatar">${escapeHTML(getInitials(bid.bidder_name))}</div>
                    <span>${escapeHTML(bid.bidder_number || 'Bidder')}</span>
                </div>
                <span class="bid-feed-amount">${formatRand(bid.amount)}</span>
                <span class="bid-feed-time">${timeAgo(bid.created_at)}</span>
            `;
            feed.insertBefore(item, feed.firstChild);
        });

        // Cap feed at 30 items
        while (feed.children.length > 30) {
            feed.removeChild(feed.lastChild);
        }
    },
};

/* ============================================================
   8. BID SUBMISSION MODULE (auction.php)
   ============================================================ */

const BidSubmit = {
    init() {
        const form = document.getElementById('bid-form');
        if (!form) return;

        form.addEventListener('submit', async e => {
            e.preventDefault();
            await BidSubmit.submit(form);
        });

        // Quick-bid buttons
        document.querySelectorAll('[data-quick-bid]').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById('bid-amount-input');
                if (input) {
                    input.value = btn.dataset.quickBid;
                    input.focus();
                }
            });
        });
    },

    async submit(form) {
        const submitBtn = form.querySelector('[type="submit"]');
        const input     = document.getElementById('bid-amount-input');
        if (!input || !submitBtn) return;

        const amount    = parseFloat(input.value);
        const minBid    = parseFloat(input.min || 0);

        // Client-side validation
        if (!amount || isNaN(amount)) {
            showToast('Please enter a bid amount.', 'error');
            input.classList.add('is-invalid');
            return;
        }

        if (amount < minBid) {
            showToast(`Minimum bid is ${formatRand(minBid)}`, 'error');
            input.classList.add('is-invalid');
            return;
        }

        input.classList.remove('is-invalid');
        submitBtn.classList.add('loading');
        submitBtn.disabled = true;

        try {
            const data = await apiPost('api.php', {
                action:     'place_bid',
                auction_id: document.getElementById('bid-panel').dataset.auctionId,
                amount:     amount,
            });

            if (data.success) {
                showToast('Bid placed successfully! ' + formatRand(amount), 'success');
                input.value = '';
                // Immediate poll update
                setTimeout(BidPolling.poll, 500);
            } else {
                showToast(data.message || 'Could not place bid.', 'error');
            }
        } catch (err) {
            showToast('Connection error. Please try again.', 'error');
        } finally {
            submitBtn.classList.remove('loading');
            submitBtn.disabled = false;
        }
    },
};

/* ============================================================
   9. IMAGE GALLERY & LIGHTBOX MODULE
   ============================================================ */

const Gallery = {
    images: [],
    currentIndex: 0,

    init() {
        const mainImg  = document.getElementById('gallery-main-img');
        const thumbs   = document.querySelectorAll('.gallery-thumb');
        const lightbox = document.getElementById('lightbox-overlay');

        if (!mainImg) return;

        // Collect all image URLs
        Gallery.images = Array.from(thumbs).map(t => t.dataset.full || t.querySelector('img')?.src || '');

        // Thumb click → swap main
        thumbs.forEach((thumb, i) => {
            thumb.addEventListener('click', () => {
                Gallery.setActive(i);
            });
        });

        // Main click → open lightbox
        if (lightbox) {
            mainImg.parentElement.addEventListener('click', () => {
                Gallery.openLightbox(Gallery.currentIndex);
            });

            // Lightbox controls
            document.getElementById('lightbox-close')?.addEventListener('click', Gallery.closeLightbox);
            document.getElementById('lightbox-prev')?.addEventListener('click',  () => Gallery.lightboxNav(-1));
            document.getElementById('lightbox-next')?.addEventListener('click',  () => Gallery.lightboxNav(1));

            lightbox.addEventListener('click', e => {
                if (e.target === lightbox) Gallery.closeLightbox();
            });

            // Keyboard nav
            document.addEventListener('keydown', e => {
                if (!lightbox.classList.contains('open')) return;
                if (e.key === 'Escape')      Gallery.closeLightbox();
                if (e.key === 'ArrowLeft')   Gallery.lightboxNav(-1);
                if (e.key === 'ArrowRight')  Gallery.lightboxNav(1);
            });
        }
    },

    setActive(index) {
        const mainImg = document.getElementById('gallery-main-img');
        const thumbs  = document.querySelectorAll('.gallery-thumb');
        if (!mainImg || !Gallery.images[index]) return;

        Gallery.currentIndex = index;
        mainImg.src = Gallery.images[index];

        thumbs.forEach((t, i) => t.classList.toggle('active', i === index));
    },

    openLightbox(index) {
        const lb  = document.getElementById('lightbox-overlay');
        const img = document.getElementById('lightbox-img');
        if (!lb || !img) return;

        Gallery.currentIndex = index;
        img.src = Gallery.images[index];
        lb.classList.add('open');
        document.body.style.overflow = 'hidden';
        Gallery.updateLightboxCounter();
    },

    closeLightbox() {
        document.getElementById('lightbox-overlay')?.classList.remove('open');
        document.body.style.overflow = '';
    },

    lightboxNav(dir) {
        const next = (Gallery.currentIndex + dir + Gallery.images.length) % Gallery.images.length;
        const img  = document.getElementById('lightbox-img');
        if (img && Gallery.images[next]) {
            img.src = Gallery.images[next];
            Gallery.currentIndex = next;
            Gallery.updateLightboxCounter();
        }
    },

    updateLightboxCounter() {
        const counter = document.getElementById('lightbox-counter');
        if (counter) {
            counter.textContent = (Gallery.currentIndex + 1) + ' / ' + Gallery.images.length;
        }
    },
};

/* ============================================================
   10. POP UPLOAD MODULE (user_dashboard.php)
   ============================================================ */

const PopUpload = {
    init() {
        const zone = document.getElementById('pop-upload-zone');
        if (!zone) return;

        const input   = zone.querySelector('input[type="file"]');
        const preview = document.getElementById('pop-file-preview');
        const form    = document.getElementById('pop-upload-form');

        // Drag & drop events
        zone.addEventListener('dragover', e => {
            e.preventDefault();
            zone.classList.add('drag-over');
        });

        zone.addEventListener('dragleave', () => {
            zone.classList.remove('drag-over');
        });

        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('drag-over');
            const file = e.dataTransfer.files[0];
            if (file && input) {
                // Set file on hidden input
                const dt = new DataTransfer();
                dt.items.add(file);
                input.files = dt.files;
                PopUpload.previewFile(file, preview);
            }
        });

        // File input change
        if (input) {
            input.addEventListener('change', () => {
                const file = input.files[0];
                if (file) PopUpload.previewFile(file, preview);
            });
        }

        // Form submit
        if (form) {
            form.addEventListener('submit', async e => {
                e.preventDefault();
                await PopUpload.submit(form);
            });
        }
    },

    previewFile(file, preview) {
        if (!preview) return;

        // Size check (10MB)
        if (file.size > 10 * 1024 * 1024) {
            showToast('File too large. Maximum 10MB allowed.', 'error');
            return;
        }

        const allowed = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        if (!allowed.includes(file.type)) {
            showToast('Invalid file type. Please upload JPG, PNG or PDF.', 'error');
            return;
        }

        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = e => {
                preview.innerHTML = `
                    <div class="upload-preview-item">
                        <img src="${e.target.result}" alt="Preview">
                    </div>
                    <span class="text-sm text-muted">${escapeHTML(file.name)} (${(file.size / 1024).toFixed(0)}KB)</span>
                `;
                preview.classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        } else {
            preview.innerHTML = `
                <div class="upload-preview-item" style="width:auto;height:auto;padding:.5rem;display:flex;align-items:center;gap:.5rem;">
                    <i class="fas fa-file-pdf" style="font-size:1.8rem;color:var(--danger)"></i>
                    <span class="text-sm">${escapeHTML(file.name)}</span>
                </div>
            `;
            preview.classList.remove('hidden');
        }
    },

    async submit(form) {
        const submitBtn = form.querySelector('[type="submit"]');
        if (submitBtn) {
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
        }

        try {
            const formData = new FormData(form);
            formData.set('action', 'upload_pop');
            const res  = await fetch('api.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                showToast('Proof of payment uploaded successfully!', 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message || 'Upload failed. Please try again.', 'error');
            }
        } catch {
            showToast('Connection error. Please try again.', 'error');
        } finally {
            if (submitBtn) {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            }
        }
    },
};

/* ============================================================
   11. LOGIN / REGISTER MODULE (login.php)
   ============================================================ */

const LoginRegister = {
    init() {
        const loginForm    = document.getElementById('login-form');
        const registerForm = document.getElementById('register-form');
        const tabBtns      = document.querySelectorAll('[data-tab-target]');

        if (!loginForm && !registerForm) return;

        // Tab switching
        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const target = btn.dataset.tabTarget;
                tabBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                document.querySelectorAll('.tab-content').forEach(c => {
                    c.classList.toggle('active', c.id === target);
                });
            });
        });

        // Auto-switch to register tab if URL has ?tab=register
        if (window.location.search.includes('tab=register')) {
            document.querySelector('[data-tab-target="register-tab"]')?.click();
        }

        // Login form
        if (loginForm) {
            loginForm.addEventListener('submit', async e => {
                e.preventDefault();
                await LoginRegister.submitAuth(loginForm, 'login');
            });
        }

        // Register form
        if (registerForm) {
            registerForm.addEventListener('submit', async e => {
                e.preventDefault();
                await LoginRegister.submitAuth(registerForm, 'register');
            });

            // Live password match check
            const pwd     = registerForm.querySelector('[name="password"]');
            const confirm = registerForm.querySelector('[name="confirm_password"]');
            if (pwd && confirm) {
                const checkMatch = debounce(() => {
                    const match = pwd.value === confirm.value;
                    confirm.classList.toggle('is-valid',   confirm.value.length > 0 && match);
                    confirm.classList.toggle('is-invalid', confirm.value.length > 0 && !match);
                }, 300);
                confirm.addEventListener('input', checkMatch);
                pwd.addEventListener('input', checkMatch);
            }
        }
    },

    async submitAuth(form, action) {
        const submitBtn = form.querySelector('[type="submit"]');
        const errorEl   = form.querySelector('.form-error-msg');

        if (errorEl) errorEl.classList.add('hidden');
        if (submitBtn) { submitBtn.classList.add('loading'); submitBtn.disabled = true; }

        try {
            const formData = new FormData(form);
            formData.set('action', action);
            const res  = await fetch('auth.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => { window.location.href = data.redirect || 'index.php'; }, 800);
            } else {
                if (errorEl) {
                    errorEl.textContent = data.message;
                    errorEl.classList.remove('hidden');
                } else {
                    showToast(data.message || 'An error occurred.', 'error');
                }
            }
        } catch {
            showToast('Connection error. Please try again.', 'error');
        } finally {
            if (submitBtn) { submitBtn.classList.remove('loading'); submitBtn.disabled = false; }
        }
    },
};

/* ============================================================
   12. TABS MODULE (generic, all pages)
   ============================================================ */

const Tabs = {
    init() {
        document.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
            btn.addEventListener('click', () => {
                const tabId  = btn.dataset.tab;
                const parent = btn.closest('[data-tabs-container]') || document;

                parent.querySelectorAll('.tab-btn[data-tab]').forEach(b => b.classList.remove('active'));
                parent.querySelectorAll('.tab-content[id]').forEach(c => c.classList.remove('active'));

                btn.classList.add('active');
                document.getElementById(tabId)?.classList.add('active');

                // Sync URL hash for dashboard tabs
                if (btn.dataset.updateHash !== 'false') {
                    history.replaceState(null, null, '#' + tabId);
                }
            });
        });

        // Open tab from URL hash
        const hash = window.location.hash.slice(1);
        if (hash) {
            document.querySelector(`.tab-btn[data-tab="${hash}"]`)?.click();
        }
    },
};

/* ============================================================
   13. MODALS MODULE (generic)
   ============================================================ */

const Modals = {
    init() {
        // Open via data-modal-open="modal-id"
        document.querySelectorAll('[data-modal-open]').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.modalOpen;
                Modals.open(id);
                // Pre-fill any data attributes
                Object.entries(btn.dataset).forEach(([key, val]) => {
                    if (key.startsWith('fill')) {
                        const fieldName = key.replace('fill', '').toLowerCase();
                        const field = document.querySelector(`#${id} [name="${fieldName}"], #${id} [data-field="${fieldName}"]`);
                        if (field) field.value = val;
                    }
                });
            });
        });

        // Close buttons
        document.querySelectorAll('[data-modal-close], .modal-close').forEach(btn => {
            btn.addEventListener('click', () => {
                const modal = btn.closest('.modal-overlay');
                if (modal) Modals.close(modal.id);
            });
        });

        // Click outside to close
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', e => {
                if (e.target === overlay) Modals.close(overlay.id);
            });
        });

        // Escape key
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelector('.modal-overlay.open')?.let?.(m => Modals.close(m.id));
                // Polyfill for browsers without .let()
                const openModal = document.querySelector('.modal-overlay.open');
                if (openModal) Modals.close(openModal.id);
            }
        });
    },

    open(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
    },

    close(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('open');
        document.body.style.overflow = '';
    },

    closeAll() {
        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
        document.body.style.overflow = '';
    },
};

/* ============================================================
   14. ADMIN: POP APPROVAL MODULE (admin_dashboard.php)
   ============================================================ */

const AdminPOP = {
    init() {
        const container = document.getElementById('pop-approvals-container');
        if (!container) return;

        // Approve button
        container.addEventListener('click', async e => {
            const approveBtn = e.target.closest('[data-action="approve-pop"]');
            const rejectBtn  = e.target.closest('[data-action="reject-pop"]');

            if (approveBtn) {
                const regId       = approveBtn.dataset.regId;
                const userId      = approveBtn.dataset.userId;
                const bidderInput = document.querySelector(`[data-bidder-input="${regId}"]`);
                const bidderNum   = bidderInput ? bidderInput.value.trim() : '';

                if (!bidderNum) {
                    showToast('Please enter a bidder number before approving.', 'error');
                    bidderInput?.focus();
                    return;
                }

                if (!confirm(`Approve POP and assign bidder number ${bidderNum}?`)) return;

                await AdminPOP.action(approveBtn, {
                    action:        'approve_pop',
                    registration_id: regId,
                    user_id:       userId,
                    bidder_number: bidderNum,
                });
            }

            if (rejectBtn) {
                const regId  = rejectBtn.dataset.regId;
                const userId = rejectBtn.dataset.userId;
                const reason = prompt('Reason for rejection (will be emailed to applicant):');
                if (!reason) return;

                await AdminPOP.action(rejectBtn, {
                    action:         'reject_pop',
                    registration_id: regId,
                    user_id:        userId,
                    reason:         reason,
                });
            }
        });
    },

    async action(btn, payload) {
        btn.classList.add('loading');
        btn.disabled = true;
        try {
            const data = await apiPost('api.php', payload);
            if (data.success) {
                showToast(data.message || 'Action completed.', 'success');
                setTimeout(() => window.location.reload(), 1200);
            } else {
                showToast(data.message || 'Action failed.', 'error');
            }
        } catch {
            showToast('Connection error.', 'error');
        } finally {
            btn.classList.remove('loading');
            btn.disabled = false;
        }
    },
};

/* ============================================================
   15. ADMIN: AUCTION MANAGEMENT (admin_dashboard.php)
   ============================================================ */

const AdminAuctions = {
    init() {
        const container = document.getElementById('auctions-admin-container');
        if (!container) return;

        container.addEventListener('click', async e => {

            // Delete auction
            const deleteBtn = e.target.closest('[data-action="delete-auction"]');
            if (deleteBtn) {
                const id    = deleteBtn.dataset.auctionId;
                const title = deleteBtn.dataset.auctionTitle || 'this auction';
                if (!confirm(`Delete "${title}"? This cannot be undone.`)) return;
                await AdminAuctions.post({ action: 'delete_auction', auction_id: id }, deleteBtn);
            }

            // Toggle status
            const statusBtn = e.target.closest('[data-action="toggle-status"]');
            if (statusBtn) {
                const id  = statusBtn.dataset.auctionId;
                const cur = statusBtn.dataset.currentStatus;
                const statusMap = { active: 'closed', upcoming: 'active', closed: 'upcoming' };
                const next = statusMap[cur] || 'active';
                if (!confirm(`Change status to "${next}"?`)) return;
                await AdminAuctions.post({ action: 'update_auction_status', auction_id: id, status: next }, statusBtn);
            }
        });

        // Set times for all auctions modal
        const setAllForm = document.getElementById('set-all-times-form');
        if (setAllForm) {
            setAllForm.addEventListener('submit', async e => {
                e.preventDefault();
                const data = serializeForm(setAllForm);
                data.action = 'update_auction_times';
                data.apply_all = '1';
                await AdminAuctions.post(data, setAllForm.querySelector('[type="submit"]'));
            });
        }
    },

    async post(payload, btn) {
        if (btn) { btn.classList.add('loading'); btn.disabled = true; }
        try {
            const data = await apiPost('api.php', payload);
            if (data.success) {
                showToast(data.message || 'Done.', 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast(data.message || 'Action failed.', 'error');
            }
        } catch {
            showToast('Connection error.', 'error');
        } finally {
            if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
        }
    },
};

/* ============================================================
   16. ADMIN: USER MANAGEMENT (admin_dashboard.php)
   ============================================================ */

const AdminUsers = {
    init() {
        const container = document.getElementById('users-admin-container');
        if (!container) return;

        container.addEventListener('click', async e => {

            const suspendBtn = e.target.closest('[data-action="suspend-user"]');
            const activateBtn = e.target.closest('[data-action="activate-user"]');
            const deleteBtn  = e.target.closest('[data-action="delete-user"]');
            const emailBtn   = e.target.closest('[data-action="email-user"]');

            if (suspendBtn) {
                const id   = suspendBtn.dataset.userId;
                const name = suspendBtn.dataset.userName || 'this user';
                if (!confirm(`Suspend ${name}?`)) return;
                await AdminUsers.post({ action: 'update_user_status', user_id: id, status: 'suspended' }, suspendBtn);
            }

            if (activateBtn) {
                const id = activateBtn.dataset.userId;
                await AdminUsers.post({ action: 'update_user_status', user_id: id, status: 'active' }, activateBtn);
            }

            if (deleteBtn) {
                const id   = deleteBtn.dataset.userId;
                const name = deleteBtn.dataset.userName || 'this user';
                if (!confirm(`Permanently delete ${name}? This cannot be undone.`)) return;
                await AdminUsers.post({ action: 'delete_user', user_id: id }, deleteBtn);
            }

            if (emailBtn) {
                const userId = emailBtn.dataset.userId;
                const userName = emailBtn.dataset.userName;
                const modal = document.getElementById('send-email-modal');
                if (modal) {
                    modal.querySelector('[name="user_id"]').value = userId;
                    modal.querySelector('[data-field="user-name"]').textContent = userName || 'User';
                    Modals.open('send-email-modal');
                }
            }
        });

        // Search filter
        const searchInput = document.getElementById('user-search');
        if (searchInput) {
            searchInput.addEventListener('input', debounce(() => {
                const q = searchInput.value.toLowerCase();
                document.querySelectorAll('[data-user-row]').forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.classList.toggle('hidden', !text.includes(q));
                });
            }, 250));
        }
    },

    async post(payload, btn) {
        if (btn) { btn.classList.add('loading'); btn.disabled = true; }
        try {
            const data = await apiPost('api.php', payload);
            if (data.success) {
                showToast(data.message || 'Done.', 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast(data.message || 'Action failed.', 'error');
            }
        } catch {
            showToast('Connection error.', 'error');
        } finally {
            if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
        }
    },
};

/* ============================================================
   17. ADMIN: EMAIL MODULE (admin_dashboard.php)
   ============================================================ */

const AdminEmail = {
    init() {
        const singleForm = document.getElementById('send-email-form');
        const bulkForm   = document.getElementById('bulk-email-form');

        if (singleForm) {
            singleForm.addEventListener('submit', async e => {
                e.preventDefault();
                const data = serializeForm(singleForm);
                data.action = 'send_email';
                await AdminEmail.send(data, singleForm.querySelector('[type="submit"]'), singleForm);
            });
        }

        if (bulkForm) {
            bulkForm.addEventListener('submit', async e => {
                e.preventDefault();
                const recipientCount = parseInt(document.getElementById('bulk-recipient-count')?.textContent || '0');
                if (!confirm(`Send email to ${recipientCount} users?`)) return;
                const data = serializeForm(bulkForm);
                data.action = 'send_bulk_email';
                await AdminEmail.send(data, bulkForm.querySelector('[type="submit"]'), bulkForm);
            });
        }
    },

    async send(payload, btn, form) {
        if (btn) { btn.classList.add('loading'); btn.disabled = true; }
        try {
            const data = await apiPost('api.php', payload);
            if (data.success) {
                showToast(data.message || 'Email sent!', 'success');
                form?.reset();
                Modals.closeAll();
            } else {
                showToast(data.message || 'Failed to send email.', 'error');
            }
        } catch {
            showToast('Connection error.', 'error');
        } finally {
            if (btn) { btn.classList.remove('loading'); btn.disabled = false; }
        }
    },
};

/* ============================================================
   18. ADMIN: CSV IMPORT MODULE (admin_dashboard.php)
   ============================================================ */

const CSVImport = {
    init() {
        const form     = document.getElementById('csv-import-form');
        const csvInput = document.getElementById('csv-file-input');
        const preview  = document.getElementById('csv-preview');
        const progressWrap = document.getElementById('import-progress-wrap');
        const progressBar  = document.getElementById('import-progress-bar');
        const resultBox    = document.getElementById('import-result');

        if (!form) return;

        // Preview row count when file selected
        if (csvInput) {
            csvInput.addEventListener('change', () => {
                const file = csvInput.files[0];
                if (!file) return;

                const reader = new FileReader();
                reader.onload = e => {
                    const lines = e.target.result.split('\n').filter(l => l.trim());
                    const rowCount = Math.max(0, lines.length - 1); // exclude header
                    if (preview) {
                        preview.textContent = `${rowCount} vehicle${rowCount !== 1 ? 's' : ''} detected in CSV`;
                        preview.classList.remove('hidden');
                    }
                };
                reader.readAsText(file);
            });
        }

        // Submit
        form.addEventListener('submit', async e => {
            e.preventDefault();
            const submitBtn = form.querySelector('[type="submit"]');

            if (progressWrap) progressWrap.classList.remove('hidden');
            if (progressBar) { progressBar.style.width = '0%'; }
            if (resultBox)   resultBox.classList.add('hidden');
            if (submitBtn)   { submitBtn.classList.add('loading'); submitBtn.disabled = true; }

            // Simulate progress during upload (UX only)
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress = Math.min(progress + Math.random() * 15, 85);
                if (progressBar) progressBar.style.width = progress + '%';
            }, 300);

            try {
                const formData = new FormData(form);
                formData.set('action', 'import_csv');
                const res  = await fetch('api.php', { method: 'POST', body: formData });
                const data = await res.json();

                clearInterval(progressInterval);
                if (progressBar) progressBar.style.width = '100%';

                setTimeout(() => {
                    if (progressWrap) progressWrap.classList.add('hidden');
                    if (resultBox) {
                        resultBox.classList.remove('hidden');
                        resultBox.innerHTML = data.success
                            ? `<div class="alert alert-success">
                                <i class="fas fa-circle-check"></i>
                                <div>
                                    <strong>Import complete!</strong><br>
                                    ${escapeHTML(data.message || '')}<br>
                                    <small>Imported: ${data.imported || 0} · Skipped: ${data.skipped || 0} · Errors: ${data.errors || 0}</small>
                                </div>
                               </div>`
                            : `<div class="alert alert-error">
                                <i class="fas fa-circle-xmark"></i>
                                <span>${escapeHTML(data.message || 'Import failed.')}</span>
                               </div>`;
                    }
                    if (data.success) form.reset();
                    if (preview) preview.classList.add('hidden');
                }, 500);

            } catch (err) {
                clearInterval(progressInterval);
                showToast('Upload failed. Please try again.', 'error');
            } finally {
                if (submitBtn) { submitBtn.classList.remove('loading'); submitBtn.disabled = false; }
            }
        });
    },
};

/* ============================================================
   19. ADMIN: GALLERY MANAGER MODULE (manage_auction.php)
   ============================================================ */

const GalleryManager = {
    init() {
        const grid = document.getElementById('gallery-manager-grid');
        if (!grid) return;

        // Delete gallery image
        grid.addEventListener('click', async e => {
            const deleteBtn  = e.target.closest('[data-action="delete-gallery-img"]');
            const primaryBtn = e.target.closest('[data-action="set-primary-img"]');

            if (deleteBtn) {
                if (!confirm('Remove this image?')) return;
                const imgId = deleteBtn.dataset.imgId;
                deleteBtn.classList.add('loading');
                try {
                    const data = await apiPost('api.php', { action: 'delete_gallery_image', image_id: imgId });
                    if (data.success) {
                        deleteBtn.closest('.gallery-manager-item')?.remove();
                        showToast('Image removed.', 'success');
                    } else {
                        showToast(data.message || 'Failed.', 'error');
                    }
                } catch {
                    showToast('Error.', 'error');
                }
            }

            if (primaryBtn) {
                const imgId     = primaryBtn.dataset.imgId;
                const auctionId = primaryBtn.dataset.auctionId;
                try {
                    const data = await apiPost('api.php', {
                        action:     'set_primary_image',
                        image_id:   imgId,
                        auction_id: auctionId,
                    });
                    if (data.success) {
                        grid.querySelectorAll('.gallery-manager-item').forEach(item => {
                            item.classList.remove('is-primary');
                            item.querySelector('.gallery-primary-badge')?.remove();
                        });
                        const item = primaryBtn.closest('.gallery-manager-item');
                        item?.classList.add('is-primary');
                        if (item && !item.querySelector('.gallery-primary-badge')) {
                            const badge = document.createElement('span');
                            badge.className = 'gallery-primary-badge';
                            badge.textContent = 'Main';
                            item.appendChild(badge);
                        }
                        showToast('Primary image updated.', 'success');
                    }
                } catch {
                    showToast('Error.', 'error');
                }
            }
        });

        // Simple drag-to-reorder
        GalleryManager.initDragSort(grid);
    },

    initDragSort(grid) {
        let dragging = null;

        grid.addEventListener('dragstart', e => {
            dragging = e.target.closest('.gallery-manager-item');
            if (dragging) {
                setTimeout(() => dragging.style.opacity = '0.4', 0);
            }
        });

        grid.addEventListener('dragend', () => {
            if (dragging) dragging.style.opacity = '';
            dragging = null;
            GalleryManager.saveOrder(grid);
        });

        grid.addEventListener('dragover', e => {
            e.preventDefault();
            const target = e.target.closest('.gallery-manager-item');
            if (target && dragging && target !== dragging) {
                const rect   = target.getBoundingClientRect();
                const midX   = rect.left + rect.width / 2;
                const before = e.clientX < midX;
                grid.insertBefore(dragging, before ? target : target.nextSibling);
            }
        });

        grid.querySelectorAll('.gallery-manager-item').forEach(item => {
            item.setAttribute('draggable', 'true');
        });
    },

    async saveOrder(grid) {
        const ids = Array.from(grid.querySelectorAll('.gallery-manager-item[data-img-id]'))
                        .map(item => item.dataset.imgId);
        try {
            await apiPost('api.php', {
                action: 'reorder_gallery',
                order:  JSON.stringify(ids),
            });
        } catch {
            console.warn('Could not save gallery order.');
        }
    },
};

/* ============================================================
   20. STATS COUNTER ANIMATION (index.php)
   ============================================================ */

const StatsCounter = {
    init() {
        const counters = document.querySelectorAll('[data-count-to]');
        if (!counters.length) return;

        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    StatsCounter.animate(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        counters.forEach(el => observer.observe(el));
    },

    animate(el) {
        const target   = parseInt(el.dataset.countTo, 10);
        const prefix   = el.dataset.prefix || '';
        const suffix   = el.dataset.suffix || '';
        const duration = 1800;
        const start    = performance.now();

        const step = now => {
            const elapsed  = now - start;
            const progress = Math.min(elapsed / duration, 1);
            // Ease-out cubic
            const eased    = 1 - Math.pow(1 - progress, 3);
            const current  = Math.round(eased * target);
            el.textContent = prefix + current.toLocaleString('en-ZA') + suffix;
            if (progress < 1) requestAnimationFrame(step);
        };

        requestAnimationFrame(step);
    },
};

/* ============================================================
   21. AUCTIONS FILTER (auctions.php)
   ============================================================ */

const AuctionFilter = {
    init() {
        const filterBar = document.getElementById('auction-filter-bar');
        if (!filterBar) return;

        const controls = filterBar.querySelectorAll('select, input[type="text"], input[type="number"]');

        controls.forEach(ctrl => {
            ctrl.addEventListener('change', AuctionFilter.apply);
        });

        const searchInput = filterBar.querySelector('input[type="search"], input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('input', debounce(AuctionFilter.apply, 350));
        }

        // Reset button
        document.getElementById('filter-reset')?.addEventListener('click', () => {
            controls.forEach(ctrl => { ctrl.value = ''; });
            AuctionFilter.apply();
        });
    },

    apply() {
        // Submit the filter form (server-side filtering)
        const form = document.getElementById('auction-filter-form');
        if (form) {
            form.submit();
        }
    },
};

/* ============================================================
   22. SCROLL TO TOP BUTTON
   ============================================================ */

const ScrollTop = {
    init() {
        const btn = document.getElementById('scroll-top-btn');
        if (!btn) return;

        window.addEventListener('scroll', () => {
            btn.classList.toggle('visible', window.scrollY > 400);
        }, { passive: true });

        btn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    },
};

/* ============================================================
   23. COPY BUTTONS (bank details, bidder number, EFT ref)
   ============================================================ */

const CopyButtons = {
    init() {
        document.querySelectorAll('[data-copy]').forEach(btn => {
            btn.addEventListener('click', () => {
                const text = btn.dataset.copy;
                copyToClipboard(text, btn);
            });
        });
    },
};

/* ============================================================
   24. FLASH MESSAGE AUTO-DISMISS
   ============================================================ */

const FlashMessages = {
    init() {
        document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
            const delay = parseInt(alert.dataset.autoDismiss, 10) || 5000;
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity .4s';
                setTimeout(() => alert.remove(), 400);
            }, delay);
        });

        // Manual close
        document.querySelectorAll('.alert-close').forEach(btn => {
            btn.addEventListener('click', () => {
                const alert = btn.closest('.alert');
                if (alert) alert.remove();
            });
        });
    },
};

/* ============================================================
   25. CONFIRM DIALOGS (styled, replaces window.confirm)
   ============================================================ */

const ConfirmDialog = {
    init() {
        // Intercept data-confirm attribute clicks
        document.querySelectorAll('[data-confirm]').forEach(el => {
            el.addEventListener('click', e => {
                const msg = el.dataset.confirm || 'Are you sure?';
                if (!confirm(msg)) e.preventDefault();
            });
        });
    },
};

/* ============================================================
   26. WHATSAPP FLOAT PANEL TOGGLE
   ============================================================ */

const WAFloat = {
    init() {
        const mainBtn = document.getElementById('wa-float-main');
        const subs    = document.querySelectorAll('.wa-float-sub');
        if (!mainBtn || !subs.length) return;

        let open = false;

        mainBtn.addEventListener('click', () => {
            open = !open;
            subs.forEach((sub, i) => {
                setTimeout(() => sub.classList.toggle('visible', open), i * 60);
            });
            mainBtn.innerHTML = open
                ? '<i class="fas fa-times"></i>'
                : '<i class="fab fa-whatsapp"></i>';
        });

        // Close on outside click
        document.addEventListener('click', e => {
            if (!e.target.closest('.whatsapp-float')) {
                open = false;
                subs.forEach(sub => sub.classList.remove('visible'));
                mainBtn.innerHTML = '<i class="fab fa-whatsapp"></i>';
            }
        });
    },
};

/* ============================================================
   27. PROFILE FORM (user_dashboard.php)
   ============================================================ */

const ProfileForm = {
    init() {
        const form = document.getElementById('profile-form');
        if (!form) return;

        form.addEventListener('submit', async e => {
            e.preventDefault();
            const submitBtn = form.querySelector('[type="submit"]');
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;

            try {
                const formData = new FormData(form);
                formData.set('action', 'update_profile');
                const res  = await fetch('api.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    showToast('Profile updated successfully.', 'success');
                } else {
                    showToast(data.message || 'Update failed.', 'error');
                }
            } catch {
                showToast('Connection error.', 'error');
            } finally {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            }
        });

        // Change password inline toggle
        document.getElementById('change-password-toggle')?.addEventListener('click', () => {
            document.getElementById('change-password-fields')?.classList.toggle('hidden');
        });
    },
};

/* ============================================================
   28. MAIN INIT — runs on DOMContentLoaded
   ============================================================ */

document.addEventListener('DOMContentLoaded', async () => {

    // Always run
    Navbar.init();
    Modals.init();
    Tabs.init();
    ScrollTop.init();
    CopyButtons.init();
    FlashMessages.init();
    ConfirmDialog.init();
    WAFloat.init();
    Countdown.init();
    StatsCounter.init();

    // Auth check (async — updates nav after load)
    Auth.check();

    // Page-specific modules (gated by element presence)
    Ticker.init();         // ticker bar on all pages
    Gallery.init();        // single auction page gallery
    BidPolling.init();     // single auction page bid updates
    BidSubmit.init();      // single auction page bid form
    PopUpload.init();      // user dashboard POP upload
    LoginRegister.init();  // login.php
    AuctionFilter.init();  // auctions.php
    AdminPOP.init();       // admin dashboard POP approvals
    AdminAuctions.init();  // admin dashboard auction management
    AdminUsers.init();     // admin dashboard user management
    AdminEmail.init();     // admin dashboard email
    CSVImport.init();      // admin dashboard CSV import
    GalleryManager.init(); // manage_auction.php gallery reorder
    ProfileForm.init();    // user dashboard profile form

});

/* ============================================================
   EXPOSE GLOBALS (for inline PHP-generated onclick attrs)
   ============================================================ */

window.PVA       = PVA;
window.showToast = showToast;
window.Modals    = Modals;
window.formatRand = formatRand;
window.copyToClipboard = copyToClipboard;
