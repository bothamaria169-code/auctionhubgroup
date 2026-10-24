<?php
// ============================================================
// AUCTION HUB GROUP — live_bids_widget.php
// "Live Bids" pill at the bottom centre of public pages (WhatsApp sits
// bottom-left, the chatbot bottom-right). It cycles through the latest real
// bids; tapping it opens a centred panel: Latest bids · Ending soon · Most bid.
// Data: api.php?action=live_bids (public, cached 15 s, bidders masked).
// Included from footer.php; hidden on admin/account/checkout pages.
// ============================================================
$lbSkip = ['admin_dashboard.php', 'user_dashboard.php', 'login.php', 'manage_auction.php', 'invoice_generator.php',
           'invoice_print.php', 'invoice_pop_upload.php', 'sign_contract.php', 'worker_portal.php', 'reset_password.php',
           'forgot_password.php', 'unsubscribe.php'];
if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $lbSkip, true)) return;
?>
<div class="lb-dock" id="lbDock" hidden>
    <button type="button" class="lb-pill" id="lbPill" aria-haspopup="dialog" aria-controls="lbPanel">
        <span class="lb-pulse" aria-hidden="true"></span>
        <span class="lb-pill-label">LIVE</span>
        <span class="lb-pill-text" id="lbPillText" aria-live="polite">Live bids</span>
        <i class="fas fa-chevron-up lb-pill-chev" aria-hidden="true"></i>
    </button>
    <button type="button" class="lb-hide" id="lbHide" title="Hide live bids" aria-label="Hide live bids">&times;</button>
</div>
<div class="lb-toasts" id="lbToasts" aria-live="polite"></div>
<button type="button" class="lb-restore" id="lbRestore" hidden aria-label="Show live bids">
    <span class="lb-pulse" aria-hidden="true"></span> Live
</button>

<div class="lb-overlay" id="lbPanel" role="dialog" aria-modal="true" aria-labelledby="lbTitle" hidden>
    <div class="lb-panel">
        <div class="lb-head">
            <div>
                <h2 class="lb-title" id="lbTitle"><span class="lb-pulse" aria-hidden="true"></span> Live on the floor</h2>
                <p class="lb-sub" id="lbSub">Updating every 20 seconds</p>
            </div>
            <button type="button" class="lb-close" id="lbClose" aria-label="Close">&times;</button>
        </div>
        <div class="lb-tabs" role="tablist">
            <button type="button" role="tab" class="lb-tab active" data-tab="bids" aria-selected="true">Latest bids</button>
            <button type="button" role="tab" class="lb-tab" data-tab="leading" aria-selected="false">Who's leading</button>
            <button type="button" role="tab" class="lb-tab" data-tab="ending" aria-selected="false">Ending soon</button>
        </div>
        <div class="lb-list" id="lbList"></div>
        <a href="auctions.php" class="lb-all">See all live auctions <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
    </div>
</div>

<script>
(function () {
    var API = '<?= APP_URL ?>/api.php?action=live_bids', BASE = '<?= APP_URL ?>/';
    var dock = document.getElementById('lbDock'), pill = document.getElementById('lbPill'),
        text = document.getElementById('lbPillText'), panel = document.getElementById('lbPanel'),
        list = document.getElementById('lbList'), restore = document.getElementById('lbRestore');
    var data = null, tab = 'bids', lastBidId = 0, cycle = 0, cycleTimer = null, tickTimer = null, skew = 0;
    var store = {
        get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
    };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
    var short = function (t) { t = String(t || ''); return t.length > 34 ? t.slice(0, 32) + '…' : t; };

    // "2d 4h", "3h 12m", "08:41" — countdown against the server clock
    function left(ends) {
        var d = new Date(String(ends).replace(' ', 'T')).getTime() - (Date.now() + skew);
        if (!(d > 0)) return 'Closing';
        var s = Math.floor(d / 1000), dd = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
        if (dd) return dd + 'd ' + h + 'h';
        if (h) return h + 'h ' + m + 'm';
        return String(m).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    }

    function pillTick() {
        if (!data) return;
        if (data.bids.length) {
            var b = data.bids[cycle % data.bids.length];
            text.innerHTML = '<strong>' + esc(b.amount_fmt) + '</strong> · ' + esc(short(b.title)) + ' <em>' + esc(b.ago) + '</em>';
            cycle++;
        } else if (data.ending.length) {
            var e = data.ending[cycle % data.ending.length];
            text.innerHTML = '<strong>' + esc(left(e.ends)) + '</strong> left · ' + esc(short(e.title));
            cycle++;
        } else {
            text.innerHTML = '<strong>' + data.live_count + '</strong> vehicles live now';
        }
    }

    function row(item) {
        var img = item.img ? '<img src="' + esc(item.img) + '" alt="" loading="lazy">' : '<span class="lb-noimg"><i class="fas fa-car"></i></span>';
        var right, meta;
        if (tab === 'bids') {
            right = '<strong class="lb-amt">' + esc(item.amount_fmt) + '</strong><small>' + esc(item.ago) + '</small>';
            meta = esc(item.bidder) + (item.leading ? ' <span class="lb-lead">Leading</span>' : '') +
                   ' · ends in <span data-ends="' + esc(item.ends) + '">' + left(item.ends) + '</span>';
        } else if (tab === 'leading') {
            right = '<strong class="lb-amt">' + esc(item.price_fmt) + '</strong><small>' + item.bids + ' bid' + (item.bids === 1 ? '' : 's') + '</small>';
            meta = '<i class="fas fa-trophy"></i> ' + esc(item.leader) + ' leads · <span data-ends="' + esc(item.ends) + '">' + left(item.ends) + '</span> left';
        } else {
            right = '<strong class="lb-amt">' + esc(item.price_fmt) + '</strong><small>' + item.bids + ' bid' + (item.bids === 1 ? '' : 's') + '</small>';
            meta = '<i class="fas fa-clock"></i> <span data-ends="' + esc(item.ends) + '">' + left(item.ends) + '</span> left';
        }
        return '<a class="lb-row' + (item.isNew ? ' lb-new' : '') + '" href="' + esc(BASE + item.url) + '">' + img +
               '<span class="lb-info"><span class="lb-name">' + esc(item.title) + '</span><span class="lb-meta">' + meta + '</span></span>' +
               '<span class="lb-right">' + right + '</span></a>';
    }

    function render() {
        if (!data) return;
        var items = data[tab] || [];
        list.innerHTML = items.length ? items.map(row).join('')
            : '<p class="lb-empty">' + (tab === 'bids' ? 'No bids yet today. Be the first!' : 'Nothing here right now.') + '</p>';
        document.getElementById('lbSub').textContent = data.live_count + ' vehicles live · updating every 20 seconds';
    }

    // Toasts above the pill: real new bids (and who now leads), staff notes
    var toasts = document.getElementById('lbToasts');
    var ss = {
        get: function (k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
    };
    function toast(html, href) {
        if (store.get('ahg_lb_hidden') === '1' || !panel.hidden) return;
        var el = document.createElement(href ? 'a' : 'div');
        el.className = 'lb-toast';
        if (href) el.href = href;
        el.innerHTML = html;
        toasts.appendChild(el);
        while (toasts.children.length > 2) toasts.removeChild(toasts.firstChild);
        setTimeout(function () { el.classList.add('lb-toast-out'); setTimeout(function () { el.remove(); }, 400); }, 6500);
    }
    function bidToast(b) {
        toast('<i class="fas fa-gavel" aria-hidden="true"></i><span><strong>' + esc(b.bidder) + '</strong> bid <strong>' +
              esc(b.amount_fmt) + '</strong> on ' + esc(short(b.title)) +
              (b.leading ? ' <span class="lb-lead">now leading</span>' : '') + '<em>' + esc(b.ago) + '</em></span>', BASE + b.url);
    }
    var firstLoad = true;
    function notify(d, prevId) {
        if (firstLoad) {
            firstLoad = false;
            // On arrival: the latest bid once per visit, if it's recent
            var b = d.bids[0];
            if (b && ss.get('ahg_lb_seen') !== String(b.bid_id) &&
                (Date.now() + skew) - new Date(String(b.at).replace(' ', 'T')).getTime() < 30 * 60000) {
                ss.set('ahg_lb_seen', String(b.bid_id));
                setTimeout(function () { bidToast(b); }, 2500);
            }
            (d.notes || []).forEach(function (n, i) {
                if (ss.get('ahg_note_' + n.id)) return;
                ss.set('ahg_note_' + n.id, '1');
                setTimeout(function () { toast('<i class="fas fa-bullhorn" aria-hidden="true"></i><span>' + esc(n.text) + '</span>'); }, 9000 + i * 8000);
            });
            return;
        }
        d.bids.filter(function (b) { return b.bid_id > prevId; }).slice(0, 2).reverse().forEach(bidToast);
    }

    function load() {
        fetch(API, {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.success) return;
            skew = new Date(d.server_time.replace(' ', 'T')).getTime() - Date.now();
            var newest = d.bids.length ? d.bids[0].bid_id : 0;
            var prevId = lastBidId;
            if (lastBidId && newest > lastBidId) {
                d.bids.forEach(function (b) { b.isNew = b.bid_id > lastBidId; });
                cycle = 0;
                pill.classList.remove('lb-flash'); void pill.offsetWidth; pill.classList.add('lb-flash');
            }
            lastBidId = Math.max(lastBidId, newest);
            data = d;
            notify(d, prevId);
            var anything = d.bids.length || d.ending.length || d.live_count;
            if (!anything) { dock.hidden = true; restore.hidden = true; return; }
            if (store.get('ahg_lb_hidden') === '1') { restore.hidden = false; } else { dock.hidden = false; }
            pillTick();
            if (!panel.hidden) render();
        }).catch(function () {});
    }

    function open() {
        panel.hidden = false;
        document.body.classList.add('lb-open');
        render();
        clearInterval(tickTimer);
        tickTimer = setInterval(function () {
            list.querySelectorAll('[data-ends]').forEach(function (el) { el.textContent = left(el.dataset.ends); });
        }, 1000);
        document.getElementById('lbClose').focus();
    }
    function close() {
        panel.hidden = true;
        document.body.classList.remove('lb-open');
        clearInterval(tickTimer);
        pill.focus();
    }

    pill.addEventListener('click', open);
    document.getElementById('lbClose').addEventListener('click', close);
    panel.addEventListener('click', function (e) { if (e.target === panel) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) close(); });
    document.querySelectorAll('.lb-tab').forEach(function (t) {
        t.addEventListener('click', function () {
            tab = t.dataset.tab;
            document.querySelectorAll('.lb-tab').forEach(function (x) {
                x.classList.toggle('active', x === t); x.setAttribute('aria-selected', x === t ? 'true' : 'false');
            });
            render();
        });
    });
    document.getElementById('lbHide').addEventListener('click', function () {
        store.set('ahg_lb_hidden', '1'); dock.hidden = true; restore.hidden = false;
    });
    restore.addEventListener('click', function () {
        store.set('ahg_lb_hidden', '0'); restore.hidden = true; dock.hidden = false; open();
    });

    load();
    setInterval(function () { if (!document.hidden) load(); }, 20000);
    cycleTimer = setInterval(function () { if (!document.hidden && panel.hidden) pillTick(); }, 4500);
})();
</script>
