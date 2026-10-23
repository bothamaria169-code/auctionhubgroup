<?php
// ============================================================
// AUCTION HUB GROUP — invoice_generator.php
// Admin-only: Create and manage Standard & Pre-Auction invoices
// ============================================================
$pageTitle = 'Invoice Generator';
$bodyClass = 'page-invoice-generator';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/email_helper.php';

if (!isAdmin()) {
    redirect(APP_URL . '/login.php', 'Admin access required.', 'error');
}

// Load existing pre-auction invoices
$invoices = [];
$tableExists = false;
try {
    $pdo->query("SELECT 1 FROM preauction_invoices LIMIT 1");
    $tableExists = true;
    $invoices = $pdo->query("
        SELECT * FROM preauction_invoices
        ORDER BY created_at DESC LIMIT 200
    ")->fetchAll();
} catch (Exception $e) {
    // Table not yet created — show setup prompt
}

// Stats
$stats = ['pending' => 0, 'pop_uploaded' => 0, 'approved' => 0, 'declined' => 0];
foreach ($invoices as $inv) {
    $s = $inv['status'];
    if (isset($stats[$s])) $stats[$s]++;
}

// Active tab from query string
$activeTab = ($_GET['tab'] ?? 'standard');

// Deal Builder: load vehicles for selector
$dbVehicles = [];
try {
    $dbVehicles = $pdo->query("
        SELECT a.id, a.item_name, a.year, a.current_price, a.start_price,
               a.image_url, a.mileage, a.fuel_type, a.transmission,
               a.body_type, a.engine_size, a.condition_grade,
               a.service_history, a.color, a.description,
               m.name AS make_name
        FROM auctions a
        LEFT JOIN makes m ON m.id = a.make_id
        WHERE a.status IN ('live','upcoming','draft')
          AND (a.is_sold IS NULL OR a.is_sold = 0)
        ORDER BY (a.status='live') DESC, a.item_name
        LIMIT 300
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore if auctions table missing */ }
?>

<style>
/* ── Invoice Generator Page ─────────────────────────── */
.ig-wrap { max-width:1200px; margin:0 auto; padding:2rem 1.5rem 4rem; }
.ig-tabs { display:flex; gap:.5rem; margin-bottom:2rem; border-bottom:2px solid #e0e8f0; padding-bottom:0; }
.ig-tab-btn {
    padding:.75rem 1.75rem; font-family:'Barlow Condensed',sans-serif;
    font-size:1rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase;
    border:none; background:none; cursor:pointer; color:#666;
    border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .2s;
}
.ig-tab-btn:hover { color:#005BA1; }
.ig-tab-btn.active { color:#005BA1; border-bottom-color:#005BA1; }
.ig-tab-panel { display:none; }
.ig-tab-panel.active { display:block; }

/* Cards */
.ig-card {
    background:#fff; border-radius:10px; border:1px solid #e0e8f0;
    box-shadow:0 2px 10px rgba(0,0,0,.06); overflow:hidden; margin-bottom:1.5rem;
}
.ig-card-header {
    background:linear-gradient(135deg,#003f73,#005BA1);
    padding:1rem 1.5rem; color:#fff;
    display:flex; align-items:center; justify-content:space-between;
}
.ig-card-header h3 { margin:0; font-family:'Barlow Condensed',sans-serif; font-size:1.15rem; letter-spacing:.05em; }
.ig-card-body { padding:1.5rem; }

/* Form */
.ig-form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:1rem; }
.ig-form-grid.two-col { grid-template-columns:1fr 1fr; }
.ig-form-grid.three-col { grid-template-columns:1fr 1fr 1fr; }
.ig-field { display:flex; flex-direction:column; gap:.35rem; }
.ig-field label { font-size:.82rem; font-weight:600; color:#444; text-transform:uppercase; letter-spacing:.06em; }
.ig-field input, .ig-field select, .ig-field textarea {
    padding:.6rem .85rem; border:1.5px solid #d0dbe8; border-radius:6px;
    font-size:.95rem; font-family:'Barlow',sans-serif; color:#222;
    transition:border-color .2s;
}
.ig-field input:focus, .ig-field select:focus, .ig-field textarea:focus {
    outline:none; border-color:#005BA1;
}
.ig-field textarea { resize:vertical; min-height:80px; }
.ig-field input[readonly] { background:#f5f8fc; color:#666; }
.ig-section-title {
    font-family:'Barlow Condensed',sans-serif; font-size:1.1rem; font-weight:700;
    color:#003f73; text-transform:uppercase; letter-spacing:.08em;
    margin:1.5rem 0 .75rem; padding-bottom:.4rem;
    border-bottom:2px solid #e0e8f0; display:flex; align-items:center; gap:.5rem;
}

/* Buttons */
.btn-pva-primary {
    display:inline-flex; align-items:center; gap:.5rem;
    padding:.65rem 1.5rem; background:#005BA1; color:#fff;
    border:none; border-radius:6px; font-weight:700; font-size:.9rem;
    cursor:pointer; transition:background .2s; font-family:'Barlow',sans-serif;
    text-decoration:none;
}
.btn-pva-primary:hover { background:#004a8a; color:#fff; }
.btn-pva-gold {
    display:inline-flex; align-items:center; gap:.5rem;
    padding:.65rem 1.5rem; background:#d4a017; color:#fff;
    border:none; border-radius:6px; font-weight:700; font-size:.9rem;
    cursor:pointer; transition:background .2s; font-family:'Barlow',sans-serif;
}
.btn-pva-gold:hover { background:#b8880e; }
.btn-pva-success {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.5rem 1.1rem; background:#1a7a3a; color:#fff;
    border:none; border-radius:5px; font-weight:600; font-size:.82rem;
    cursor:pointer; transition:background .2s; font-family:'Barlow',sans-serif;
}
.btn-pva-success:hover { background:#155e2d; }
.btn-pva-danger {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.5rem 1.1rem; background:#b91c1c; color:#fff;
    border:none; border-radius:5px; font-weight:600; font-size:.82rem;
    cursor:pointer; transition:background .2s; font-family:'Barlow',sans-serif;
}
.btn-pva-danger:hover { background:#991313; }
.btn-pva-outline {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.5rem 1.1rem; background:transparent; color:#005BA1;
    border:1.5px solid #005BA1; border-radius:5px; font-weight:600; font-size:.82rem;
    cursor:pointer; transition:all .2s; font-family:'Barlow',sans-serif;
    text-decoration:none;
}
.btn-pva-outline:hover { background:#005BA1; color:#fff; }

/* Status badges */
.badge {
    display:inline-flex; align-items:center; gap:.3rem;
    padding:.3rem .75rem; border-radius:50px; font-size:.75rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.06em;
}
.badge-pending   { background:#fff3cd; color:#856404; }
.badge-pop       { background:#cce5ff; color:#004085; }
.badge-approved  { background:#d4edda; color:#155724; }
.badge-declined  { background:#f8d7da; color:#721c24; }

/* Invoice table */
.ig-table { width:100%; border-collapse:collapse; font-size:.88rem; }
.ig-table th {
    background:#003f73; color:#fff; padding:.75rem 1rem;
    text-align:left; font-family:'Barlow Condensed',sans-serif;
    letter-spacing:.07em; font-size:.82rem; text-transform:uppercase;
}
.ig-table td { padding:.75rem 1rem; border-bottom:1px solid #e8eef5; vertical-align:middle; }
.ig-table tr:last-child td { border-bottom:none; }
.ig-table tr:hover td { background:#f8fbff; }
.ig-table .actions { display:flex; gap:.4rem; flex-wrap:wrap; }

/* Stats row */
.ig-stats { display:flex; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.ig-stat-card {
    flex:1; min-width:120px; background:#fff; border-radius:8px;
    border:1px solid #e0e8f0; padding:1rem 1.25rem; text-align:center;
    box-shadow:0 1px 6px rgba(0,0,0,.05);
}
.ig-stat-card .num { font-size:2rem; font-weight:800; font-family:'Barlow Condensed',sans-serif; }
.ig-stat-card .lbl { font-size:.72rem; text-transform:uppercase; letter-spacing:.07em; color:#888; margin-top:.2rem; }

/* Commitment fee calculator */
.fee-calc-box {
    background:#f0f6fc; border:1.5px solid #b8d4ee; border-radius:8px;
    padding:1.25rem; margin-top:.75rem;
}
.fee-calc-row { display:flex; justify-content:space-between; align-items:center; padding:.35rem 0; font-size:.9rem; }
.fee-calc-row.total { font-weight:700; font-size:1rem; border-top:2px solid #b8d4ee; margin-top:.5rem; padding-top:.75rem; }
.fee-calc-row .label { color:#555; }
.fee-calc-row .value { font-weight:600; color:#003f73; }
.fee-calc-row.outstanding .value { color:#005BA1; font-size:1.05rem; }

/* Validity timer */
.validity-badge {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.4rem 1rem; background:#fff3cd; color:#856404;
    border:1px solid #ffc107; border-radius:6px; font-size:.85rem; font-weight:600;
}
.validity-badge.expired { background:#f8d7da; color:#721c24; border-color:#dc3545; }

/* POP preview link */
.pop-link {
    display:inline-flex; align-items:center; gap:.3rem;
    font-size:.82rem; color:#005BA1; text-decoration:none; font-weight:600;
}
.pop-link:hover { text-decoration:underline; }

/* Modal */
.ig-modal-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.55);
    z-index:9999; align-items:center; justify-content:center;
}
.ig-modal-overlay.open { display:flex; }
.ig-modal {
    background:#fff; border-radius:12px; width:90%; max-width:520px;
    max-height:85vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.25);
}
.ig-modal-header {
    background:linear-gradient(135deg,#003f73,#005BA1);
    padding:1.25rem 1.5rem; color:#fff; border-radius:12px 12px 0 0;
    display:flex; justify-content:space-between; align-items:center;
}
.ig-modal-header h4 { margin:0; font-family:'Barlow Condensed',sans-serif; font-size:1.1rem; }
.ig-modal-close {
    background:none; border:none; color:#fff; font-size:1.4rem; cursor:pointer;
    line-height:1; padding:.2rem .5rem; border-radius:4px;
}
.ig-modal-close:hover { background:rgba(255,255,255,.15); }
.ig-modal-body { padding:1.5rem; }
.ig-modal-footer { padding:1rem 1.5rem; border-top:1px solid #e0e8f0; display:flex; gap:.75rem; justify-content:flex-end; }

/* Upload link copy */
.copy-link-box {
    display:flex; align-items:center; gap:.5rem;
    background:#f5f8fc; border:1.5px solid #d0dbe8; border-radius:6px; padding:.5rem .75rem;
}
.copy-link-box input { flex:1; border:none; background:transparent; font-size:.8rem; color:#555; font-family:monospace; }
.copy-link-box input:focus { outline:none; }
.copy-btn {
    background:#005BA1; color:#fff; border:none; border-radius:4px;
    padding:.3rem .75rem; font-size:.78rem; font-weight:600; cursor:pointer; white-space:nowrap;
}
.copy-btn:hover { background:#004a8a; }

/* Notice */
.ig-notice {
    background:#e8f4fd; border:1.5px solid #b8d4ee; border-radius:8px;
    padding:1rem 1.25rem; color:#004085; font-size:.9rem; margin-bottom:1.5rem;
}
.ig-notice strong { display:block; margin-bottom:.25rem; }

/* Responsive */
@media(max-width:900px) {
    .ig-wrap { padding:1.25rem 1rem 5rem; }
    .ig-tabs { overflow-x:auto; flex-wrap:nowrap; -webkit-overflow-scrolling:touch; scrollbar-width:none; }
    .ig-tabs::-webkit-scrollbar { display:none; }
    .ig-tab-btn { white-space:nowrap; padding:.6rem 1rem; font-size:.85rem; }
}
@media(max-width:768px) {
    .ig-wrap { padding:1rem .75rem 5rem; }
    .ig-form-grid.two-col,
    .ig-form-grid.three-col { grid-template-columns:1fr; }
    .ig-form-grid.two-col [style*="grid-column:1/-1"],
    .ig-form-grid.three-col [style*="grid-column:1/-1"] { grid-column:1; }
    .ig-table { font-size:.78rem; }
    .ig-table th:nth-child(3),
    .ig-table td:nth-child(3),
    .ig-table th:nth-child(7),
    .ig-table td:nth-child(7) { display:none; }
    .ig-card-body { padding:1rem .85rem; }
    .ig-card-header { padding:.85rem 1rem; flex-wrap:wrap; gap:.5rem; }
    .ig-stats { gap:.6rem; }
    .ig-stat-card { min-width:80px; padding:.75rem; }
    .ig-stat-card .num { font-size:1.5rem; }
    .btn-pva-primary, .btn-pva-gold, .btn-pva-outline { width:100%; justify-content:center; padding:.75rem 1rem; }
    /* Deal builder form action buttons */
    .db-arow { flex-direction:column; gap:8px; }
}
@media(max-width:480px) {
    .ig-wrap { padding:.75rem .5rem 5rem; }
    .ig-tab-btn { padding:.55rem .75rem; font-size:.8rem; }
    .ig-field input, .ig-field select, .ig-field textarea { font-size:16px; min-height:44px; }
    .ig-field label { font-size:.78rem; }
    .ig-section-title { font-size:.95rem; }
    .ig-card-header h3 { font-size:1rem; }
}

/* Print override */
@media print { .ig-wrap, nav, footer { display:none !important; } }

/* ── Deal Builder tab styles ─────────────────────────── */
:root {
  --db-blue:#005BA1; --db-blue2:#004a8a; --db-blue3:#003a70;
  --db-blue-glow:rgba(0,91,161,.18); --db-blue-light:#e8f3fc; --db-blue-mid:#c3ddf5;
  --db-dark:#003f73;
  --db-gold:#C9972B;
  --db-green:#16A34A; --db-green-light:#F0FDF4; --db-green-mid:#BBF7D0;
  --db-bg:#f0f4f8; --db-bg2:#F5F8FC;
  --db-border:#d0dbe8; --db-border2:#b8cce0;
  --db-muted:#6b7a8d; --db-muted2:#99aabb;
}
#tab-builder { padding:0 !important; }
.db-vehicle-picker {
  background:linear-gradient(105deg,#003a70 0%,#005BA1 60%,#0070c5 100%);
  padding:14px 20px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;
}
.db-vp-label { color:rgba(255,255,255,.7); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; white-space:nowrap; }
.db-vp-search { flex:1; min-width:160px; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.25);
  border-radius:7px; color:#fff; padding:8px 12px; font-size:13px; }
.db-vp-search::placeholder { color:rgba(255,255,255,.45); }
.db-vp-search:focus { outline:none; border-color:rgba(255,255,255,.5); background:rgba(255,255,255,.18); }
.db-vp-select { flex:2; min-width:200px; max-width:480px; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.25);
  border-radius:7px; color:#fff; padding:8px 12px; font-size:13px; }
.db-vp-select option { background:#003a70; color:#fff; }
.db-vp-select:focus { outline:none; border-color:rgba(255,255,255,.5); }
.db-vp-img { width:52px; height:38px; border-radius:5px; object-fit:cover; border:1.5px solid rgba(255,255,255,.3); flex-shrink:0; display:none; }
.db-vp-clear { background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.25); color:rgba(255,255,255,.8);
  border-radius:6px; padding:7px 13px; font-size:12px; font-weight:600; cursor:pointer; white-space:nowrap; }
.db-vp-clear:hover { background:rgba(255,255,255,.22); }

.db-layout { display:grid; grid-template-columns:500px 1fr; gap:18px; padding:18px 18px 100px; max-width:1500px; margin:0 auto; }
@media(max-width:1100px) { .db-layout { grid-template-columns:1fr; padding:14px; } }
@media(max-width:768px)  { .db-layout { padding:12px 12px 100px; gap:14px; } }

.db-panel { background:#fff; border-radius:10px; box-shadow:0 2px 12px rgba(0,63,115,.08); overflow:hidden; border:1px solid var(--db-border); }
.db-panel-hd { padding:13px 18px; border-bottom:1px solid var(--db-border); display:flex; align-items:center; gap:9px; background:#f5f9fd; }
.db-panel-hd .ico { width:28px; height:28px; border-radius:6px; background:var(--db-blue-light); display:grid; place-items:center; font-size:14px; flex-shrink:0; }
.db-panel-hd h2 { font-size:13.5px; font-weight:700; color:var(--db-dark); }
.db-panel-hd p { font-size:11px; color:var(--db-muted); margin-top:1px; }
.db-panel-body { padding:16px 18px; }

.db-tabs { display:flex; gap:2px; border-bottom:2px solid var(--db-border); margin-bottom:16px; overflow-x:auto; scrollbar-width:none; }
.db-tabs::-webkit-scrollbar { display:none; }
.db-tab { padding:7px 13px; font-size:12px; font-weight:600; color:var(--db-muted); cursor:pointer;
  border-bottom:2.5px solid transparent; margin-bottom:-2px; white-space:nowrap;
  transition:color .18s,border-color .18s; border-radius:6px 6px 0 0; }
.db-tab:hover { color:var(--db-dark); background:var(--db-bg2); }
.db-tab.on { color:var(--db-blue); border-bottom-color:var(--db-blue); background:var(--db-blue-light); }
.db-tc { display:none; } .db-tc.on { display:block; }

.db-fsec { margin-bottom:14px; }
.db-fsec-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px;
  color:var(--db-blue); margin-bottom:9px; padding-bottom:4px;
  border-bottom:1px solid var(--db-blue-mid); display:flex; align-items:center; gap:5px; }
.db-fgrid { display:grid; grid-template-columns:1fr 1fr; gap:9px 13px; }
.db-fg { display:flex; flex-direction:column; gap:3px; }
.db-fg.full { grid-column:1/-1; }
.db-fg label { font-size:10.5px; font-weight:700; color:var(--db-muted); text-transform:uppercase; letter-spacing:.4px; }
.db-fg input,.db-fg select,.db-fg textarea {
  border:1.5px solid var(--db-border); border-radius:6px; padding:8px 10px;
  font-size:13.5px; color:#1a2a3a; background:#FAFCFE; transition:border-color .18s,box-shadow .18s;
}
.db-fg input:focus,.db-fg select:focus,.db-fg textarea:focus {
  outline:none; border-color:var(--db-blue); box-shadow:0 0 0 3px var(--db-blue-glow); background:#fff;
}
.db-fg textarea { resize:vertical; min-height:60px; }
.db-hint { font-size:10.5px; color:var(--db-muted2); margin-top:2px; }

.db-srow { display:flex; align-items:center; gap:10px; margin-top:3px; }
.db-srow input[type=range] { flex:1; accent-color:var(--db-blue); }
.db-spill { min-width:44px; text-align:center; background:var(--db-blue); color:#fff;
  font-weight:700; font-size:12px; padding:2px 8px; border-radius:20px; flex-shrink:0; }
.db-cbox { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px; }
.db-ccard { background:var(--db-bg2); border-radius:8px; padding:10px 12px; border:1.5px solid var(--db-border); }
.db-ccard .cl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--db-muted); }
.db-ccard .cv { font-size:15px; font-weight:800; color:var(--db-dark); margin-top:2px; }
.db-ccard.hi .cv { color:var(--db-blue); }

.db-img-area { border:2px dashed var(--db-border2); border-radius:9px; padding:18px; text-align:center;
  cursor:pointer; transition:border-color .2s,background .2s; position:relative; background:var(--db-bg2); }
.db-img-area:hover,.db-img-area.drag { border-color:var(--db-blue); background:var(--db-blue-light); }
.db-img-area input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; border:none; background:none; padding:0; }
.db-img-area input[type=file]:focus { box-shadow:none; }
.db-img-main { display:none; width:100%; border-radius:7px; max-height:160px; object-fit:cover; border:1.5px solid var(--db-border); }
.db-img-strip { display:flex; gap:7px; flex-wrap:wrap; margin-top:9px; }
.db-thumb-sm { width:68px; height:50px; border-radius:5px; object-fit:cover; border:1.5px solid var(--db-border); cursor:pointer; transition:border-color .18s; }
.db-thumb-sm.on,.db-thumb-sm:hover { border-color:var(--db-blue); }
.db-add-btn { width:68px; height:50px; border-radius:5px; border:2px dashed var(--db-border2);
  background:var(--db-bg2); cursor:pointer; display:flex; align-items:center; justify-content:center;
  font-size:18px; color:var(--db-muted2); transition:border-color .18s,color .18s; }
.db-add-btn:hover { border-color:var(--db-blue); color:var(--db-blue); }

.db-arow { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; }
.db-btn { padding:9px 17px; border-radius:8px; font-size:12.5px; font-weight:700; cursor:pointer; border:none;
  display:inline-flex; align-items:center; gap:6px; transition:opacity .18s,transform .1s; }
.db-btn:hover { opacity:.86; transform:translateY(-1px); }
.db-btn-red  { background:var(--db-blue); color:#fff; box-shadow:0 3px 12px var(--db-blue-glow); }
.db-btn-navy { background:var(--db-dark); color:#fff; }
.db-btn-gold { background:var(--db-gold); color:#fff; }
.db-btn-ghost { background:transparent; color:var(--db-blue); border:1.5px solid var(--db-blue); }
.db-btn-green { background:var(--db-green); color:#fff; }
.db-btn-outline { background:transparent; color:var(--db-muted); border:1.5px solid var(--db-border); }

.db-preview-area { position:sticky; top:80px; max-height:calc(100vh - 100px); overflow-y:auto; scroll-behavior:smooth; }
.db-preview-area::-webkit-scrollbar { width:4px; }
.db-preview-area::-webkit-scrollbar-thumb { background:var(--db-border); border-radius:10px; }
.db-ptabs { display:flex; gap:3px; padding:13px 16px 0; background:#f5f9fd; border-bottom:1px solid var(--db-border); }
.db-ptab { padding:7px 15px; font-size:12px; font-weight:600; color:var(--db-muted);
  cursor:pointer; border-radius:7px 7px 0 0; border:1px solid transparent; border-bottom:none;
  margin-bottom:-1px; transition:all .18s; white-space:nowrap; }
.db-ptab.on { background:#fff; color:var(--db-blue); border-color:var(--db-border); }

/* Email preview */
.db-ep { background:var(--db-bg); padding:13px; }
.db-ec { max-width:600px; margin:0 auto; background:#fff; border-radius:11px; overflow:hidden; box-shadow:0 8px 40px rgba(0,63,115,.13); }
/* Listing card */
.db-lcard { background:#fff; border-radius:13px; overflow:hidden; box-shadow:0 4px 24px rgba(0,63,115,.12); max-width:420px; margin:0 auto; }
.db-lcard-img { width:100%; height:200px; object-fit:cover; display:block; }
.db-lcard-img-ph { width:100%; height:200px; background:linear-gradient(135deg,#003a70 0%,#005BA1 100%); display:flex; align-items:center; justify-content:center; color:rgba(255,255,255,.3); font-size:42px; }
/* Code area */
.db-code-box { background:#0d1b2a; color:#7dd3fc; font-family:'Courier New',monospace; font-size:11px;
  line-height:1.6; padding:14px; border-radius:9px; overflow-x:auto; white-space:pre; max-height:320px; overflow-y:auto; }

/* Mobile nav bar for builder */
.db-mnav { display:none; position:fixed; bottom:0; left:0; right:0; z-index:600;
  background:linear-gradient(180deg,#004a8a 0%,#003a70 100%);
  border-top:1px solid rgba(255,255,255,.12); padding:6px 8px calc(6px + env(safe-area-inset-bottom,0px));
  box-shadow:0 -4px 20px rgba(0,0,0,.25); }
.db-mnav-inner { display:flex; align-items:center; justify-content:space-around; gap:3px; }
.db-mni { display:flex; flex-direction:column; align-items:center; gap:2px; flex:1;
  padding:5px 3px; border-radius:9px; border:none; background:transparent;
  color:rgba(255,255,255,.5); font-size:9.5px; font-weight:600; cursor:pointer; transition:all .2s; }
.db-mni .ico { font-size:19px; line-height:1.1; }
.db-mni.on,.db-mni:active { color:#fff; background:rgba(255,255,255,.1); }
.db-mni-pdf { flex:1.3; background:var(--db-blue) !important; color:#fff !important; border-radius:11px; padding:7px 5px; }

/* Toast */
.db-toast { position:fixed; bottom:90px; right:20px; z-index:9999; background:var(--db-dark); color:#fff;
  padding:11px 18px; border-radius:9px; font-size:12.5px; font-weight:600; box-shadow:0 8px 28px rgba(0,63,115,.25);
  display:flex; align-items:center; gap:9px;
  transform:translateY(80px); opacity:0; transition:transform .3s,opacity .3s; pointer-events:none; }
.db-toast.show { transform:translateY(0); opacity:1; }

@media(max-width:768px) {
  .db-mnav { display:block; }
  .db-preview-area { position:static; max-height:none; overflow-y:visible; }
  .db-mobile-hidden { display:none !important; }
  #db-pane-form.db-mobile-show { display:block !important; }
  #db-pane-preview.db-mobile-show { display:block !important; }
  /* Vehicle picker stacks vertically on mobile */
  .db-vehicle-picker { flex-direction:column; gap:8px; padding:10px 12px; }
  .db-vp-search, .db-vp-select { width:100%; min-width:unset; max-width:unset; flex:unset; }
  .db-vp-clear { align-self:flex-end; }
  /* Panel padding */
  .db-panel-body { padding:12px; }
  .db-fgrid { gap:8px 10px; }
  /* Action buttons */
  .db-arow { flex-direction:column; }
  .db-btn { width:100%; justify-content:center; min-height:46px; font-size:13px; }
  /* Tabs scrollable */
  .db-tabs { overflow-x:auto; -webkit-overflow-scrolling:touch; flex-wrap:nowrap; }
  .db-tab { white-space:nowrap; }
  /* Preview tabs */
  .db-ptabs { overflow-x:auto; -webkit-overflow-scrolling:touch; flex-wrap:nowrap; gap:2px; }
  .db-ptab { white-space:nowrap; font-size:11px; padding:6px 10px; }
  /* Stats cards compact */
  .db-cbox { grid-template-columns:1fr 1fr; gap:6px; }
  .db-ccard .cv { font-size:13px; }
}
@media(max-width:480px) {
  .db-fgrid { grid-template-columns:1fr; }
  .db-fg.full { grid-column:1; }
  .db-fg input,.db-fg select,.db-fg textarea { font-size:16px !important; min-height:44px; }
  .db-btn { width:100%; justify-content:center; }
  .db-layout { padding:10px 10px 100px; }
  .db-panel-hd { padding:10px 12px; }
  .db-panel-hd h2 { font-size:12.5px; }
  .db-mnav-inner { gap:2px; }
  .db-mni .ico { font-size:17px; }
  .db-mni { font-size:9px; padding:4px 2px; }
}
@media(hover:none) {
  .db-fg input,.db-fg select,.db-fg textarea { min-height:44px; }
  .db-btn { min-height:46px; }
  .db-mni { min-height:46px; }
  .db-tab { min-height:40px; display:flex; align-items:center; }
}
</style>

<!-- ══════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════ -->
<section style="background:linear-gradient(135deg,#003f73,#005BA1);padding:2.5rem 1.5rem 2rem;">
    <div style="max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <nav style="color:rgba(255,255,255,.5);font-size:.82rem;margin-bottom:.5rem;">
                <a href="<?= APP_URL ?>/admin_dashboard.php" style="color:rgba(255,255,255,.6);text-decoration:none;">Admin</a>
                <span style="margin:0 .4rem;">/</span>
                <span style="color:#fff;">Invoice Generator</span>
            </nav>
            <h1 style="margin:0;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:2rem;font-weight:800;letter-spacing:.04em;">
                <i class="fas fa-file-invoice-dollar" style="margin-right:.5rem;"></i>
                Invoice Generator
            </h1>
            <p style="margin:.4rem 0 0;color:rgba(255,255,255,.7);font-size:.9rem;">
                Create standard invoices or manage pre-auction purchase commitments
            </p>
        </div>
        <a href="<?= APP_URL ?>/admin_dashboard.php" class="btn-pva-outline" style="color:#fff;border-color:rgba(255,255,255,.4);background:rgba(255,255,255,.1);">
            <i class="fas fa-arrow-left"></i> Dashboard
        </a>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════
     MAIN CONTENT
══════════════════════════════════════════════════════════ -->
<div class="ig-wrap">

    <!-- Flash -->
    <?php if ($flash['message']): ?>
    <div class="ig-notice" style="background:<?= $flash['type']==='success' ? '#d4edda' : '#f8d7da' ?>;border-color:<?= $flash['type']==='success' ? '#c3e6cb' : '#f5c6cb' ?>;color:<?= $flash['type']==='success' ? '#155724' : '#721c24' ?>;">
        <strong><?= $flash['type']==='success' ? '✅ Success' : '⚠️ Notice' ?></strong>
        <?= e($flash['message']) ?>
    </div>
    <?php endif; ?>

    <?php if (!$tableExists): ?>
    <div class="ig-notice" style="background:#fff3cd;border-color:#ffc107;color:#856404;">
        <strong>⚙️ Setup Required</strong>
        The pre-auction invoice table has not been created yet. Run the SQL migration:
        <code style="display:block;background:#fdf3cd;padding:.5rem .75rem;border-radius:4px;margin:.75rem 0;font-size:.85rem;">
            mysql -u root -p auctionhubgroup_db &lt; preauction_invoice_setup.sql
        </code>
        Standard invoices are still available on Tab 1.
    </div>
    <?php endif; ?>

    <!-- ── TABS ── -->
    <div class="ig-tabs">
        <button class="ig-tab-btn <?= $activeTab==='standard' ? 'active':'' ?>" onclick="switchTab('standard')">
            <i class="fas fa-file-invoice"></i> Standard Invoice
        </button>
        <button class="ig-tab-btn <?= $activeTab==='preauction' ? 'active':'' ?>" onclick="switchTab('preauction')">
            <i class="fas fa-gavel"></i> Pre-Auction Purchase
        </button>
        <button class="ig-tab-btn <?= $activeTab==='builder' ? 'active':'' ?>" onclick="switchTab('builder')">
            <i class="fas fa-magic"></i> Deal Builder
        </button>
    </div>

    <!-- ══════════════════════════════════════════
         TAB 1 — STANDARD INVOICE
    ══════════════════════════════════════════ -->
    <div id="tab-standard" class="ig-tab-panel <?= $activeTab==='standard' ? 'active':'' ?>">

        <div class="ig-notice">
            <strong><i class="fas fa-info-circle"></i> Standard Invoice</strong>
            Fill in the buyer and vehicle details, then click <strong>Preview Invoice</strong> to open the print-ready invoice in a new tab. No database storage — ideal for quick one-off invoices.
        </div>

        <form id="standardForm" target="_blank" action="invoice_print.php" method="POST">
            <input type="hidden" name="type" value="standard">

            <!-- Buyer Info -->
            <div class="ig-card">
                <div class="ig-card-header">
                    <h3><i class="fas fa-user"></i> Buyer Information</h3>
                </div>
                <div class="ig-card-body">
                    <div class="ig-form-grid two-col">
                        <div class="ig-field">
                            <label>Full Name *</label>
                            <input type="text" name="buyer_name" required placeholder="e.g. Pheta Setlojoane">
                        </div>
                        <div class="ig-field">
                            <label>ID / Passport Number</label>
                            <input type="text" name="buyer_id_passport" placeholder="e.g. RS031609">
                        </div>
                        <div class="ig-field">
                            <label>Phone Number</label>
                            <input type="text" name="buyer_phone" placeholder="e.g. +266 5886 4386">
                        </div>
                        <div class="ig-field">
                            <label>Email Address</label>
                            <input type="email" name="buyer_email" placeholder="e.g. buyer@email.com">
                        </div>
                        <div class="ig-field" style="grid-column:1/-1">
                            <label>Full Address</label>
                            <textarea name="buyer_address" placeholder="Street, City, Country"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoice Details -->
            <div class="ig-card">
                <div class="ig-card-header">
                    <h3><i class="fas fa-hashtag"></i> Invoice Details</h3>
                </div>
                <div class="ig-card-body">
                    <div class="ig-form-grid two-col">
                        <div class="ig-field">
                            <label>Invoice Number</label>
                            <input type="text" name="invoice_number" id="stdInvNum" placeholder="Auto-generated" readonly>
                        </div>
                        <div class="ig-field">
                            <label>Invoice Date</label>
                            <input type="date" name="invoice_date" id="stdInvDate">
                        </div>
                        <div class="ig-field">
                            <label>Due Date</label>
                            <input type="date" name="due_date" id="stdDueDate">
                        </div>
                        <div class="ig-field">
                            <label>Vehicle Price EXCL. VAT (ZAR) *</label>
                            <input type="number" name="total_price" min="0" step="0.01" required placeholder="e.g. 54200">
                        </div>
                        <div class="ig-field">
                            <label>Document Handling Fee (ZAR)</label>
                            <input type="number" name="doc_handling_fee" min="0" step="0.01" value="2600" placeholder="e.g. 2600">
                        </div>
                    </div>
                    <p style="margin:.5rem 0 0;font-size:.8rem;color:#64748b;">
                        VAT at 15% is added to the vehicle price automatically; the Document Handling Fee is added after VAT to give the total due.
                    </p>
                </div>
            </div>

            <!-- Vehicle Details -->
            <div class="ig-card">
                <div class="ig-card-header">
                    <h3><i class="fas fa-car"></i> Vehicle Details</h3>
                </div>
                <div class="ig-card-body">
                    <div class="ig-form-grid two-col">
                        <div class="ig-field" style="grid-column:1/-1">
                            <label>Vehicle Description *</label>
                            <input type="text" name="vehicle_description" required placeholder="e.g. 2022 Land Rover Range Rover Velar 2.0D HSE R-Dynamic | D200">
                        </div>
                        <div class="ig-field">
                            <label>Year</label>
                            <input type="number" name="vehicle_year" min="1980" max="2030" placeholder="e.g. 2022">
                        </div>
                        <div class="ig-field">
                            <label>Mileage</label>
                            <input type="text" name="vehicle_mileage" placeholder="e.g. 66 897 km">
                        </div>
                        <div class="ig-field">
                            <label>Transmission</label>
                            <select name="vehicle_transmission">
                                <option value="">— Select —</option>
                                <option value="Automatic">Automatic</option>
                                <option value="Manual">Manual</option>
                                <option value="CVT">CVT</option>
                                <option value="Semi-Automatic">Semi-Automatic</option>
                            </select>
                        </div>
                        <div class="ig-field">
                            <label>Fuel Type</label>
                            <select name="vehicle_fuel">
                                <option value="">— Select —</option>
                                <option value="Petrol">Petrol</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Hybrid">Hybrid</option>
                                <option value="Electric">Electric</option>
                            </select>
                        </div>
                        <div class="ig-field">
                            <label>Body Type</label>
                            <select name="vehicle_body">
                                <option value="">— Select —</option>
                                <option value="SUV">SUV</option>
                                <option value="Sedan">Sedan</option>
                                <option value="Hatchback">Hatchback</option>
                                <option value="Bakkie/Pickup">Bakkie/Pickup</option>
                                <option value="Coupe">Coupe</option>
                                <option value="Convertible">Convertible</option>
                                <option value="Minivan">Minivan</option>
                                <option value="Crossover">Crossover</option>
                            </select>
                        </div>
                        <div class="ig-field">
                            <label>Colour</label>
                            <input type="text" name="vehicle_colour" placeholder="e.g. Santorini Black">
                        </div>
                        <div class="ig-field">
                            <label>Condition</label>
                            <select name="vehicle_condition">
                                <option value="Used">Used</option>
                                <option value="New">New</option>
                                <option value="Demo">Demo</option>
                            </select>
                        </div>
                        <div class="ig-field">
                            <label>Service History</label>
                            <select name="vehicle_service_history">
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                                <option value="Partial">Partial</option>
                            </select>
                        </div>
                        <div class="ig-field" style="grid-column:1/-1">
                            <label>Additional Features / Notes</label>
                            <textarea name="vehicle_features" placeholder="e.g. DEKRA Certified, Accident Free, One previous owner, Mechanical & Electronic Warranty, Nation-wide delivery, SADC delivery"></textarea>
                        </div>
                        <div class="ig-field" style="grid-column:1/-1">
                            <label>Vehicle Image URL (optional)</label>
                            <input type="url" name="vehicle_image_url" placeholder="https://...">
                        </div>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                <button type="submit" class="btn-pva-primary" style="font-size:1rem;padding:.85rem 2rem;">
                    <i class="fas fa-eye"></i> Preview Invoice
                </button>
                <button type="button" onclick="stdPrintFill()" class="btn-pva-gold">
                    <i class="fas fa-magic"></i> Fill Sample Data
                </button>
                <button type="reset" class="btn-pva-outline">
                    <i class="fas fa-times"></i> Clear Form
                </button>
            </div>
        </form>
    </div><!-- /tab-standard -->


    <!-- ══════════════════════════════════════════
         TAB 2 — PRE-AUCTION PURCHASE
    ══════════════════════════════════════════ -->
    <div id="tab-preauction" class="ig-tab-panel <?= $activeTab==='preauction' ? 'active':'' ?>">

        <!-- Stats -->
        <div class="ig-stats">
            <div class="ig-stat-card">
                <div class="num" style="color:#856404;"><?= $stats['pending'] ?></div>
                <div class="lbl">Pending</div>
            </div>
            <div class="ig-stat-card">
                <div class="num" style="color:#004085;"><?= $stats['pop_uploaded'] ?></div>
                <div class="lbl">POP Uploaded</div>
            </div>
            <div class="ig-stat-card">
                <div class="num" style="color:#155724;"><?= $stats['approved'] ?></div>
                <div class="lbl">Approved</div>
            </div>
            <div class="ig-stat-card">
                <div class="num" style="color:#721c24;"><?= $stats['declined'] ?></div>
                <div class="lbl">Declined</div>
            </div>
        </div>

        <!-- Create new invoice (collapsible) -->
        <div class="ig-card" id="createCard">
            <div class="ig-card-header" style="cursor:pointer;" onclick="toggleCreate()">
                <h3><i class="fas fa-plus-circle"></i> Create New Pre-Auction Invoice</h3>
                <span id="createChevron" style="font-size:1.2rem;">&#9660;</span>
            </div>
            <div id="createBody" class="ig-card-body" style="display:<?= empty($invoices) ? 'block' : 'none' ?>;">

                <form id="paForm" onsubmit="submitPreAuction(event)">

                    <!-- Buyer -->
                    <div class="ig-section-title"><i class="fas fa-user"></i> Buyer Information</div>
                    <div class="ig-form-grid two-col">
                        <div class="ig-field">
                            <label>Full Name *</label>
                            <input type="text" name="buyer_name" id="pa_buyer_name" required placeholder="e.g. John Smith">
                        </div>
                        <div class="ig-field">
                            <label>ID / Passport Number</label>
                            <input type="text" name="buyer_id_passport" id="pa_buyer_id" placeholder="e.g. RS031609">
                        </div>
                        <div class="ig-field">
                            <label>Phone Number</label>
                            <input type="text" name="buyer_phone" id="pa_buyer_phone" placeholder="e.g. +27 82 000 0000">
                        </div>
                        <div class="ig-field">
                            <label>Email Address *</label>
                            <input type="email" name="buyer_email" id="pa_buyer_email" required placeholder="e.g. buyer@email.com">
                        </div>
                        <div class="ig-field" style="grid-column:1/-1">
                            <label>Full Address</label>
                            <textarea name="buyer_address" id="pa_buyer_address" placeholder="Street, City, Country"></textarea>
                        </div>
                    </div>

                    <!-- Vehicle -->
                    <div class="ig-section-title"><i class="fas fa-car"></i> Vehicle Details</div>
                    <div class="ig-form-grid two-col">
                        <div class="ig-field" style="grid-column:1/-1">
                            <label>Vehicle Description *</label>
                            <input type="text" name="vehicle_description" id="pa_vdesc" required placeholder="e.g. 2022 Land Rover Range Rover Velar 2.0D HSE R-Dynamic | D200">
                        </div>
                        <div class="ig-field">
                            <label>Year</label>
                            <input type="number" name="vehicle_year" id="pa_vyear" min="1980" max="2030" placeholder="e.g. 2022">
                        </div>
                        <div class="ig-field">
                            <label>Mileage</label>
                            <input type="text" name="vehicle_mileage" id="pa_vmileage" placeholder="e.g. 66 897 km">
                        </div>
                        <div class="ig-field">
                            <label>Transmission</label>
                            <select name="vehicle_transmission" id="pa_vtrans">
                                <option value="">— Select —</option>
                                <option value="Automatic">Automatic</option>
                                <option value="Manual">Manual</option>
                                <option value="CVT">CVT</option>
                                <option value="Semi-Automatic">Semi-Automatic</option>
                            </select>
                        </div>
                        <div class="ig-field">
                            <label>Fuel Type</label>
                            <select name="vehicle_fuel" id="pa_vfuel">
                                <option value="">— Select —</option>
                                <option value="Petrol">Petrol</option>
                                <option value="Diesel" selected>Diesel</option>
                                <option value="Hybrid">Hybrid</option>
                                <option value="Electric">Electric</option>
                            </select>
                        </div>
                        <div class="ig-field">
                            <label>Body Type</label>
                            <select name="vehicle_body" id="pa_vbody">
                                <option value="">— Select —</option>
                                <option value="SUV">SUV</option>
                                <option value="Sedan">Sedan</option>
                                <option value="Hatchback">Hatchback</option>
                                <option value="Bakkie/Pickup">Bakkie/Pickup</option>
                                <option value="Coupe">Coupe</option>
                                <option value="Minivan">Minivan</option>
                            </select>
                        </div>
                        <div class="ig-field">
                            <label>Colour</label>
                            <input type="text" name="vehicle_colour" id="pa_vcolour" placeholder="e.g. Santorini Black">
                        </div>
                        <div class="ig-field">
                            <label>Condition</label>
                            <select name="vehicle_condition" id="pa_vcond">
                                <option value="Used" selected>Used</option>
                                <option value="New">New</option>
                                <option value="Demo">Demo</option>
                            </select>
                        </div>
                        <div class="ig-field">
                            <label>Service History</label>
                            <select name="vehicle_service_history" id="pa_vsh">
                                <option value="Yes" selected>Yes</option>
                                <option value="No">No</option>
                                <option value="Partial">Partial</option>
                            </select>
                        </div>
                        <div class="ig-field" style="grid-column:1/-1">
                            <label>Additional Features</label>
                            <textarea name="vehicle_features" id="pa_vfeatures" placeholder="DEKRA Certified, Accident Free, One previous owner, Mechanical & Electronic Warranty, Nation-wide delivery, SADC delivery"></textarea>
                        </div>
                        <div class="ig-field" style="grid-column:1/-1">
                            <label>Vehicle Image URL (optional)</label>
                            <input type="url" name="vehicle_image_url" id="pa_vimage" placeholder="https://...">
                        </div>
                    </div>

                    <!-- Pricing & Commitment Fee -->
                    <div class="ig-section-title"><i class="fas fa-coins"></i> Pricing & Commitment Fee</div>
                    <div class="ig-form-grid two-col">
                        <div class="ig-field">
                            <label>Total Asking Price (ZAR) *</label>
                            <input type="number" name="total_price" id="pa_total" required min="0" step="0.01"
                                   placeholder="e.g. 244000" oninput="calcFee()">
                        </div>
                        <div class="ig-field">
                            <label>Commitment Fee % (30–50%)</label>
                            <input type="number" name="commitment_fee_pct" id="pa_pct" min="1" max="100" step="0.5"
                                   value="35" oninput="calcFee()">
                        </div>
                    </div>

                    <!-- Live calculator -->
                    <div class="fee-calc-box" id="feeCalcBox">
                        <div class="fee-calc-row">
                            <span class="label">Total Asking Price</span>
                            <span class="value" id="calcTotal">R0.00</span>
                        </div>
                        <div class="fee-calc-row">
                            <span class="label">Commitment Fee (<span id="calcPct">35</span>%)</span>
                            <span class="value" style="color:#b91c1c;" id="calcFee">R0.00</span>
                        </div>
                        <div class="fee-calc-row total outstanding">
                            <span class="label">Outstanding Balance (after commitment fee)</span>
                            <span class="value" id="calcOutstanding">R0.00</span>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="ig-section-title" style="margin-top:1.5rem;"><i class="fas fa-sticky-note"></i> Internal Notes</div>
                    <div class="ig-field">
                        <label>Admin Notes (not shown on invoice)</label>
                        <textarea name="notes" id="pa_notes" placeholder="e.g. High-demand vehicle — strong auction resale potential"></textarea>
                    </div>

                    <div style="margin-top:1.5rem;display:flex;gap:1rem;flex-wrap:wrap;">
                        <button type="submit" class="btn-pva-primary" style="font-size:1rem;padding:.85rem 2rem;" id="paSubmitBtn">
                            <i class="fas fa-file-invoice-dollar"></i> Generate Pre-Auction Invoice
                        </button>
                        <button type="button" onclick="fillPASample()" class="btn-pva-gold">
                            <i class="fas fa-magic"></i> Fill Sample Data
                        </button>
                        <button type="reset" class="btn-pva-outline" onclick="calcFee()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Invoice list -->
        <?php if ($tableExists): ?>
        <div class="ig-card">
            <div class="ig-card-header">
                <h3><i class="fas fa-list"></i> Pre-Auction Invoice List</h3>
                <span style="font-size:.82rem;opacity:.85;"><?= count($invoices) ?> invoice<?= count($invoices)!==1?'s':'' ?></span>
            </div>
            <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                <?php if (empty($invoices)): ?>
                <div style="padding:3rem;text-align:center;color:#888;">
                    <i class="fas fa-inbox" style="font-size:2.5rem;margin-bottom:1rem;display:block;opacity:.4;"></i>
                    No pre-auction invoices yet. Create the first one above.
                </div>
                <?php else: ?>
                <table class="ig-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Buyer</th>
                            <th>Vehicle</th>
                            <th>Total</th>
                            <th>Commitment Fee</th>
                            <th>Outstanding</th>
                            <th>Status</th>
                            <th>Validity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <?php
                        $isExpired = strtotime($inv['expires_at']) < time();
                        $statusBadge = match($inv['status']) {
                            'pop_uploaded' => '<span class="badge badge-pop"><i class="fas fa-upload"></i> POP Uploaded</span>',
                            'approved'     => '<span class="badge badge-approved"><i class="fas fa-check"></i> Approved</span>',
                            'declined'     => '<span class="badge badge-declined"><i class="fas fa-times"></i> Declined</span>',
                            default        => '<span class="badge badge-pending"><i class="fas fa-clock"></i> Pending</span>',
                        };
                        $popLink = APP_URL . '/invoice_pop_upload.php?num=' . urlencode($inv['invoice_number']) . '&token=' . urlencode($inv['upload_token'] ?? '');
                        ?>
                        <tr id="row-<?= $inv['id'] ?>">
                            <td>
                                <strong style="font-size:.82rem;color:#003f73;"><?= e($inv['invoice_number']) ?></strong><br>
                                <span style="font-size:.72rem;color:#888;"><?= safeDate('d M Y', $inv['created_at']) ?></span>
                            </td>
                            <td>
                                <strong><?= e($inv['buyer_name']) ?></strong><br>
                                <span style="font-size:.78rem;color:#666;"><?= e($inv['buyer_email'] ?? '') ?></span>
                            </td>
                            <td style="max-width:200px;">
                                <span style="font-size:.82rem;"><?= e(substr($inv['vehicle_description'], 0, 50)) ?><?= strlen($inv['vehicle_description'])>50 ? '…' : '' ?></span>
                            </td>
                            <td><strong><?= formatRand((float)$inv['total_price']) ?></strong></td>
                            <td>
                                <?= formatRand((float)$inv['commitment_fee_amount']) ?>
                                <span style="color:#888;font-size:.78rem;">(<?= $inv['commitment_fee_pct'] ?>%)</span>
                            </td>
                            <td><strong style="color:#005BA1;"><?= formatRand((float)$inv['outstanding_amount']) ?></strong></td>
                            <td><?= $statusBadge ?></td>
                            <td>
                                <?php
                                $expiresTs = strtotime($inv['expires_at']);
                                $nowTs = time();
                                if ($isExpired): ?>
                                    <span class="validity-badge expired"><i class="fas fa-exclamation-triangle"></i> Expired</span>
                                <?php else:
                                    $hoursLeft = ceil(($expiresTs - $nowTs) / 3600);
                                ?>
                                    <span class="validity-badge"><i class="fas fa-clock"></i> <?= $hoursLeft ?>h left</span>
                                <?php endif; ?>
                                <br><span style="font-size:.72rem;color:#888;"><?= date('d M Y H:i', $expiresTs) ?></span>
                            </td>
                            <td>
                                <div class="actions">
                                    <a href="invoice_print.php?id=<?= $inv['id'] ?>" target="_blank" class="btn-pva-outline" title="View/Print Invoice">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <?php if ($inv['pop_file_path']): ?>
                                    <a href="serve_pop.php?file=<?= urlencode(basename($inv['pop_file_path'])) ?>" target="_blank" class="btn-pva-outline" style="color:#004085;border-color:#004085;" title="View Proof of Payment">
                                        <i class="fas fa-file-image"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($inv['status'] === 'pop_uploaded'): ?>
                                    <button onclick="approveInvoice(<?= $inv['id'] ?>, '<?= e($inv['buyer_name']) ?>', '<?= e($inv['invoice_number']) ?>')"
                                            class="btn-pva-success" title="Approve &amp; Send Buyer Code">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button onclick="declineInvoice(<?= $inv['id'] ?>, '<?= e($inv['buyer_name']) ?>', '<?= e($inv['invoice_number']) ?>')"
                                            class="btn-pva-danger" title="Decline Commitment">
                                        <i class="fas fa-times"></i> Decline
                                    </button>
                                    <?php elseif ($inv['status'] === 'pending'): ?>
                                    <button onclick="copyUploadLink('<?= htmlspecialchars($popLink, ENT_QUOTES) ?>')"
                                            class="btn-pva-outline" style="font-size:.78rem;" title="Copy buyer POP upload link">
                                        <i class="fas fa-link"></i> POP Link
                                    </button>
                                    <?php elseif ($inv['status'] === 'approved'): ?>
                                    <span style="font-size:.78rem;color:#155724;font-weight:600;">
                                        <i class="fas fa-key"></i> <?= e($inv['buyer_code'] ?? '') ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /tab-preauction -->


    <!-- ══════════════════════════════════════════
         TAB 3 — DEAL BUILDER (PVA_Complete_System style)
    ══════════════════════════════════════════ -->
    <div id="tab-builder" class="ig-tab-panel <?= $activeTab==='builder' ? 'active':'' ?>">

    <!-- jsPDF CDN -->
    <script>
    (function(){
        if(!window.jspdf){
            var s=document.createElement('script');
            s.src='https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
            document.head.appendChild(s);
        }
    })();
    </script>
    <!-- Vehicle data from DB -->
    <script>
    const DB_VEHICLES = <?= json_encode(array_values($dbVehicles), JSON_HEX_TAG|JSON_HEX_AMP) ?>;
    </script>

    <!-- Vehicle Picker -->
    <div class="db-vehicle-picker">
        <span class="db-vp-label">🚘 Pick Vehicle:</span>
        <input type="text" class="db-vp-search" id="dbVpSearch" placeholder="Search make, model..." oninput="dbFilterVehicles()"/>
        <select class="db-vp-select" id="dbVpSelect" onchange="dbLoadVehicle(this.value)">
            <option value="">— Select vehicle from database —</option>
            <?php foreach ($dbVehicles as $veh): ?>
            <option value="<?= $veh['id'] ?>" data-img="<?= e(imgHost($veh['image_url'] ?? '')) ?>">
                <?= e(trim(($veh['year'] ? $veh['year'].' ' : '') . ($veh['make_name'] ? $veh['make_name'].' ' : '') . $veh['item_name'])) ?>
                — <?= formatRand($veh['current_price'] ?: $veh['start_price']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <img id="dbVpThumb" class="db-vp-img" src="" alt=""/>
        <button class="db-vp-clear" onclick="dbClearVehicle()">✕ Clear</button>
    </div>

    <!-- Main Layout -->
    <div class="db-layout">

    <!-- LEFT: Form -->
    <div id="db-pane-form">
    <div class="db-panel">
        <div class="db-panel-hd">
            <div class="ico">⚡</div>
            <div>
                <h2>Build Pre-Auction Deal</h2>
                <p>Fill in details — PDF, email &amp; listing generate live</p>
            </div>
        </div>
        <div class="db-panel-body">
            <div class="db-tabs">
                <div class="db-tab on" onclick="dbSw('buyer')">👤 Buyer</div>
                <div class="db-tab" onclick="dbSw('vehicle')">🚘 Vehicle</div>
                <div class="db-tab" onclick="dbSw('images')">📷 Images</div>
                <div class="db-tab" onclick="dbSw('deal')">💰 Deal</div>
                <div class="db-tab" onclick="dbSw('company')">🏢 Company</div>
            </div>

            <!-- Buyer -->
            <div class="db-tc on" id="dbtc-buyer">
                <div class="db-fsec">
                    <div class="db-fsec-title">👤 Buyer Details</div>
                    <div class="db-fgrid">
                        <div class="db-fg full"><label>Full Name</label><input id="dbBName" placeholder="e.g. John Smith" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Passport / ID No.</label><input id="dbBPass" placeholder="e.g. RS031609" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Phone</label><input id="dbBPhone" placeholder="+27 82 000 0000" oninput="dbU()"/></div>
                        <div class="db-fg full"><label>Email Address</label><input id="dbBEmail" type="email" placeholder="buyer@email.com" oninput="dbU()"/></div>
                        <div class="db-fg full"><label>Street Address</label><input id="dbBAddr" placeholder="49 Main Street" oninput="dbU()"/></div>
                        <div class="db-fg full"><label>City / Country</label><input id="dbBCity" placeholder="Maseru, Lesotho" oninput="dbU()"/></div>
                    </div>
                </div>
            </div>

            <!-- Vehicle -->
            <div class="db-tc" id="dbtc-vehicle">
                <div class="db-fsec">
                    <div class="db-fsec-title">🚘 Vehicle Specification</div>
                    <div class="db-fgrid">
                        <div class="db-fg full"><label>Full Vehicle Description</label><input id="dbVTitle" placeholder="2022 Land Rover Range Rover Velar 2.0D HSE" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Make / Brand</label><input id="dbVMake" placeholder="Land Rover" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Year</label><input id="dbVYear" type="number" placeholder="2022" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Mileage</label><input id="dbVMileage" placeholder="66 897 km" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Colour</label><input id="dbVColour" placeholder="Santorini Black" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Fuel Type</label>
                            <select id="dbVFuel" onchange="dbU()">
                                <option value="">— Select —</option>
                                <option>Diesel</option><option>Petrol</option><option>Hybrid</option><option>Electric</option>
                            </select>
                        </div>
                        <div class="db-fg"><label>Transmission</label>
                            <select id="dbVTrans" onchange="dbU()">
                                <option value="">— Select —</option>
                                <option>Automatic</option><option>Manual</option><option>CVT</option><option>Semi-Automatic</option>
                            </select>
                        </div>
                        <div class="db-fg"><label>Body Type</label>
                            <select id="dbVBody" onchange="dbU()">
                                <option value="">— Select —</option>
                                <option>SUV</option><option>Sedan</option><option>Hatchback</option>
                                <option>Bakkie / Pickup</option><option>Coupe</option><option>Convertible</option>
                            </select>
                        </div>
                        <div class="db-fg"><label>Engine Size</label><input id="dbVEngine" placeholder="2.0L Turbodiesel" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Condition</label>
                            <select id="dbVCond" onchange="dbU()">
                                <option>Used</option><option>New</option><option>Demo</option>
                            </select>
                        </div>
                        <div class="db-fg"><label>Service History</label>
                            <select id="dbVSvc" onchange="dbU()">
                                <option value="Full Service History">Full Service History</option>
                                <option value="Partial Service History">Partial</option>
                                <option value="No Service History">None</option>
                            </select>
                        </div>
                        <div class="db-fg full"><label>Notable Features (comma separated)</label>
                            <textarea id="dbVFeatures" oninput="dbU()" rows="2" placeholder="Panoramic Sunroof, Heated Seats, Apple CarPlay..."></textarea>
                        </div>
                        <div class="db-fg full"><label>Extras / Notes</label>
                            <textarea id="dbVNotes" oninput="dbU()" rows="2" placeholder="Any additional info for buyer..."></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Images -->
            <div class="db-tc" id="dbtc-images">
                <div class="db-fsec">
                    <div class="db-fsec-title">📷 Vehicle Images</div>
                    <p class="db-hint" style="margin-bottom:10px">DB image loads automatically. Upload your own (up to 6). First image used in PDF &amp; email.</p>
                    <div class="db-img-area" id="dbImgDrop">
                        <input type="file" accept="image/*" multiple onchange="dbHandleImages(this.files)" id="dbImgInput"/>
                        <div id="dbUploadPrompt" style="pointer-events:none">
                            <div style="font-size:26px;margin-bottom:5px">📸</div>
                            <span style="font-size:12px;font-weight:600;color:var(--db-muted)">Click or drag &amp; drop vehicle photos</span><br/>
                            <span style="font-size:11px;color:var(--db-muted2)">JPG, PNG, WEBP — up to 6 images</span>
                        </div>
                        <img class="db-img-main" id="dbMainThumb" src="" alt="Vehicle"/>
                    </div>
                    <div class="db-img-strip" id="dbImgStrip"></div>
                    <div class="db-arow" style="margin-top:10px">
                        <button class="db-btn db-btn-outline" onclick="dbClearImages()">🗑 Clear Uploaded Images</button>
                    </div>
                </div>
            </div>

            <!-- Deal -->
            <div class="db-tc" id="dbtc-deal">
                <div class="db-fsec">
                    <div class="db-fsec-title">💰 Pricing &amp; Payment Terms</div>
                    <div class="db-fgrid">
                        <div class="db-fg full">
                            <label>Total Asking Price (ZAR)</label>
                            <input type="number" id="dbDPrice" placeholder="e.g. 244000" oninput="dbU()"/>
                        </div>
                        <div class="db-fg full">
                            <label>Commitment Fee % (30–50%) — Upfront to issue Buyer Code</label>
                            <div class="db-srow">
                                <input type="range" id="dbDPct" min="30" max="50" step="1" value="35" oninput="dbU()"/>
                                <span class="db-spill" id="dbPctLbl">35%</span>
                            </div>
                            <div class="db-cbox">
                                <div class="db-ccard hi"><div class="cl">Commitment Fee</div><div class="cv" id="dbCommitAmt">—</div></div>
                                <div class="db-ccard"><div class="cl">Remaining Balance</div><div class="cv" id="dbRemAmt">—</div></div>
                            </div>
                            <p class="db-hint">Commitment fee secures the Buyer Code &amp; inspection booking.</p>
                        </div>
                        <div class="db-fg"><label>Invoice No.</label><input id="dbDInvNo" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Invoice Date</label><input type="date" id="dbDInvDate" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Offer Expiry Date</label><input type="date" id="dbDExpiry" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Bank</label><input id="dbDBank" value="NEDBANK" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Account Number</label><input id="dbDAcct" value="1341275868" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Branch Code</label><input id="dbDBranch" value="198765" oninput="dbU()"/></div>
                    </div>
                </div>
            </div>

            <!-- Company -->
            <div class="db-tc" id="dbtc-company">
                <div class="db-fsec">
                    <div class="db-fsec-title">🏢 Company Details</div>
                    <div class="db-fgrid">
                        <div class="db-fg full"><label>Company Name</label><input id="dbCName" value="AUCTION HUB GROUP" oninput="dbU()"/></div>
                        <div class="db-fg full"><label>Directors</label><input id="dbCDir" value="R DU PREEZ // KL PORTER // PJ MENGEL" oninput="dbU()"/></div>
                        <div class="db-fg"><label>VAT Number</label><input id="dbCVat" value="VAT # 30811/AA13" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Phone</label><input id="dbCPhone" value="010 516 0681 | 010 516 0683" oninput="dbU()"/></div>
                        <div class="db-fg full"><label>Address</label><input id="dbCAddr" value="53 Sterling Road, Samrand Business Park, Centurion 0187" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Website</label><input id="dbCWeb" value="www.auctionhubgroup.co.za" oninput="dbU()"/></div>
                        <div class="db-fg"><label>Email</label><input id="dbCEmail" value="info@auctionhubgroup.co.za" oninput="dbU()"/></div>
                        <div class="db-fg full"><label>Sales Agent</label><input id="dbCAgent" value="R du Preez" oninput="dbU()"/></div>
                    </div>
                </div>
            </div>

            <!-- Action row -->
            <div class="db-arow">
                <button class="db-btn db-btn-red" onclick="dbGeneratePDF()">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Download PDF Invoice
                </button>
                <button class="db-btn db-btn-navy" onclick="dbSaveInvoice()">
                    <i class="fas fa-save"></i> Save &amp; Get Upload Link
                </button>
                <button class="db-btn db-btn-gold" onclick="dbFillMpholoane()">
                    <i class="fas fa-magic"></i> Load Sample (MPHOLOANE01)
                </button>
                <button class="db-btn db-btn-outline" onclick="dbResetAll()">↺ Reset</button>
            </div>
        </div>
    </div>
    </div><!-- /db-pane-form -->

    <!-- RIGHT: Preview -->
    <div id="db-pane-preview" class="db-mobile-hidden">
    <div class="db-panel">
        <div class="db-ptabs">
            <div class="db-ptab on" onclick="dbPt('email',this)">✉️ Email Preview</div>
            <div class="db-ptab" onclick="dbPt('listing',this)">🏷 Listing Card</div>
            <div class="db-ptab" onclick="dbPt('code',this)">💻 Website Code</div>
        </div>
        <div id="dbPv-email" style="display:block">
            <div class="db-ep"><div id="dbEmailPreview" class="db-ec"></div></div>
        </div>
        <div id="dbPv-listing" style="display:none;padding:18px">
            <div id="dbListingCard" class="db-lcard"></div>
        </div>
        <div id="dbPv-code" style="display:none;padding:16px">
            <p style="font-size:12px;color:var(--db-muted);margin-bottom:10px">Copy &amp; paste this HTML snippet on your website.</p>
            <div style="position:relative">
                <div class="db-code-box" id="dbCodeBox"></div>
                <button onclick="dbCopyCode()" style="position:absolute;top:9px;right:9px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.7);padding:4px 11px;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;">📋 Copy</button>
            </div>
        </div>
    </div>
    </div><!-- /db-pane-preview -->

    </div><!-- /db-layout -->
    </div><!-- /tab-builder -->

    <!-- Mobile bottom nav for builder -->
    <div class="db-mnav" id="dbMnav" style="display:none">
        <div class="db-mnav-inner">
            <button class="db-mni on" id="dbMniForm" onclick="dbMnavSwitch('form')"><span class="ico">📋</span>Form</button>
            <button class="db-mni" id="dbMniEmail" onclick="dbMnavSwitch('email')"><span class="ico">✉️</span>Email</button>
            <button class="db-mni" id="dbMniListing" onclick="dbMnavSwitch('listing')"><span class="ico">🏷</span>Listing</button>
            <button class="db-mni db-mni-pdf" onclick="dbGeneratePDF()"><span class="ico">📄</span>PDF</button>
        </div>
    </div>
    <!-- Builder toast -->
    <div class="db-toast" id="dbToast"><span id="dbToastMsg">✅ Done</span></div>

</div><!-- /ig-wrap -->


<!-- ══════════════════════════════════════════
     APPROVE MODAL
══════════════════════════════════════════ -->
<div class="ig-modal-overlay" id="approveModal">
    <div class="ig-modal">
        <div class="ig-modal-header">
            <h4><i class="fas fa-check-circle"></i> Approve Commitment & Issue Buyer Code</h4>
            <button class="ig-modal-close" onclick="closeModal('approveModal')">&times;</button>
        </div>
        <div class="ig-modal-body">
            <p>You are about to <strong>approve</strong> the commitment from <strong id="approveModalName"></strong> on invoice <strong id="approveModalNum"></strong>.</p>
            <div class="ig-notice" style="background:#d4edda;border-color:#c3e6cb;color:#155724;margin-bottom:1rem;">
                <strong><i class="fas fa-info-circle"></i> This will:</strong>
                <ul style="margin:.4rem 0 0 1.2rem;padding:0;font-size:.88rem;">
                    <li>Generate a unique Buyer Code for this buyer</li>
                    <li>Send the buyer a confirmation email with their Buyer Code</li>
                    <li>Mark this invoice as <strong>Approved</strong></li>
                </ul>
            </div>
            <div class="ig-field">
                <label>Optional: Add a note for the buyer</label>
                <textarea id="approveNote" placeholder="e.g. Please bring your original ID to the showroom..."></textarea>
            </div>
            <input type="hidden" id="approveInvoiceId">
        </div>
        <div class="ig-modal-footer">
            <button class="btn-pva-outline" onclick="closeModal('approveModal')">Cancel</button>
            <button class="btn-pva-success" style="padding:.65rem 1.5rem;font-size:.9rem;" onclick="submitApproval()">
                <i class="fas fa-check"></i> Confirm Approval & Send Buyer Code
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     DECLINE MODAL
══════════════════════════════════════════ -->
<div class="ig-modal-overlay" id="declineModal">
    <div class="ig-modal">
        <div class="ig-modal-header" style="background:linear-gradient(135deg,#7f1d1d,#b91c1c);">
            <h4><i class="fas fa-times-circle"></i> Decline Commitment</h4>
            <button class="ig-modal-close" onclick="closeModal('declineModal')">&times;</button>
        </div>
        <div class="ig-modal-body">
            <p>You are about to <strong>decline</strong> the pre-auction commitment from <strong id="declineModalName"></strong>.</p>
            <div class="ig-field">
                <label>Reason for Declination *</label>
                <textarea id="declineReason" required rows="4"
                    placeholder="e.g. We regret to inform you that the vehicle in question is no longer available for pre-auction purchase as it has been committed to another buyer / moved to auction."></textarea>
            </div>
            <input type="hidden" id="declineInvoiceId">
        </div>
        <div class="ig-modal-footer">
            <button class="btn-pva-outline" onclick="closeModal('declineModal')">Cancel</button>
            <button class="btn-pva-danger" style="padding:.65rem 1.5rem;font-size:.9rem;" onclick="submitDeclination()">
                <i class="fas fa-times"></i> Decline & Notify Buyer
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     POP UPLOAD LINK MODAL
══════════════════════════════════════════ -->
<div class="ig-modal-overlay" id="copyModal">
    <div class="ig-modal">
        <div class="ig-modal-header">
            <h4><i class="fas fa-link"></i> Buyer POP Upload Link</h4>
            <button class="ig-modal-close" onclick="closeModal('copyModal')">&times;</button>
        </div>
        <div class="ig-modal-body">
            <p style="font-size:.9rem;margin-bottom:1rem;">Share this link with the buyer so they can upload their proof of payment once the commitment fee has been paid:</p>
            <div class="copy-link-box">
                <input type="text" id="copyLinkInput" readonly>
                <button class="copy-btn" onclick="doCopy()"><i class="fas fa-copy"></i> Copy</button>
            </div>
            <p id="copyConfirm" style="color:#155724;font-size:.82rem;margin-top:.5rem;display:none;">
                <i class="fas fa-check"></i> Copied to clipboard!
            </p>
        </div>
        <div class="ig-modal-footer">
            <button class="btn-pva-outline" onclick="closeModal('copyModal')">Close</button>
        </div>
    </div>
</div>


<?php require_once __DIR__ . '/footer.php'; ?>

<script>
// ── Tab switching ────────────────────────────────────────
function switchTab(name) {
    document.querySelectorAll('.ig-tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.ig-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.currentTarget.classList.add('active');
    history.replaceState(null, '', '?tab=' + name);
    // Show/hide Deal Builder mobile nav
    const dbNav = document.getElementById('dbMnav');
    if(dbNav) dbNav.style.display = (name === 'builder') ? '' : 'none';
    // Refresh preview when switching to builder
    if(name === 'builder') { try { dbU(); } catch(e){} }
}

// ── Collapsible create form ──────────────────────────────
function toggleCreate() {
    const body = document.getElementById('createBody');
    const chevron = document.getElementById('createChevron');
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : 'block';
    chevron.textContent = isOpen ? '▶' : '▼';
}

// ── Auto-fill invoice number and dates ───────────────────
(function initDefaults() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const ymd = now.getFullYear() + '/' + pad(now.getMonth()+1) + '/' + pad(now.getDate());
    const ymd2 = now.getFullYear() + '-' + pad(now.getMonth()+1) + '-' + pad(now.getDate());
    const rand = Math.random().toString(36).substr(2, 6).toUpperCase();
    const invNum = 'AHG-INV-' + now.getFullYear() + pad(now.getMonth()+1) + '-' + rand;

    const stdNum  = document.getElementById('stdInvNum');
    const stdDate = document.getElementById('stdInvDate');
    const stdDue  = document.getElementById('stdDueDate');
    if (stdNum)  stdNum.value  = invNum;
    if (stdDate) stdDate.value = ymd2;
    if (stdDue)  stdDue.value  = ymd2;
})();

// ── Commitment fee calculator ────────────────────────────
function calcFee() {
    const total = parseFloat(document.getElementById('pa_total')?.value || 0);
    const pct   = parseFloat(document.getElementById('pa_pct')?.value  || 0);
    const fee   = total * (pct / 100);
    const outstanding = total - fee;

    document.getElementById('calcTotal').textContent       = 'R' + total.toLocaleString('en-ZA', {minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('calcFee').textContent         = 'R' + fee.toLocaleString('en-ZA', {minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('calcOutstanding').textContent = 'R' + outstanding.toLocaleString('en-ZA', {minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('calcPct').textContent         = pct;
}
calcFee();

// ── Submit pre-auction invoice ────────────────────────────
function submitPreAuction(e) {
    e.preventDefault();
    const btn = document.getElementById('paSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';

    const formData = new FormData(document.getElementById('paForm'));
    formData.append('action', 'create_preauction_invoice');

    fetch('invoice_api.php', { method:'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Open print view in new tab
                window.open('invoice_print.php?id=' + data.invoice_id, '_blank');
                // Show copy link modal
                copyUploadLink(data.upload_link);
                // Reload page to show new invoice in list
                setTimeout(() => location.reload(), 800);
            } else {
                alert('Error: ' + (data.message || 'Could not create invoice.'));
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Generate Pre-Auction Invoice';
            }
        })
        .catch(() => {
            alert('Network error. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Generate Pre-Auction Invoice';
        });
}

// ── Approve invoice ──────────────────────────────────────
function approveInvoice(id, name, num) {
    document.getElementById('approveInvoiceId').value = id;
    document.getElementById('approveModalName').textContent = name;
    document.getElementById('approveModalNum').textContent = num;
    document.getElementById('approveNote').value = '';
    openModal('approveModal');
}
function submitApproval() {
    const id   = document.getElementById('approveInvoiceId').value;
    const note = document.getElementById('approveNote').value;
    const fd   = new FormData();
    fd.append('action', 'approve_invoice');
    fd.append('invoice_id', id);
    fd.append('note', note);

    fetch('invoice_api.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            closeModal('approveModal');
            if (data.success) {
                showToast('✅ Approved! Buyer Code ' + data.buyer_code + ' sent to buyer.', 'success');
                setTimeout(() => location.reload(), 1800);
            } else {
                showToast('❌ ' + (data.message || 'Approval failed.'), 'error');
            }
        });
}

// ── Decline invoice ───────────────────────────────────────
function declineInvoice(id, name, num) {
    document.getElementById('declineInvoiceId').value = id;
    document.getElementById('declineModalName').textContent = name;
    document.getElementById('declineReason').value = '';
    openModal('declineModal');
}
function submitDeclination() {
    const id     = document.getElementById('declineInvoiceId').value;
    const reason = document.getElementById('declineReason').value.trim();
    if (!reason) { alert('Please provide a reason for the declination.'); return; }
    const fd = new FormData();
    fd.append('action', 'decline_invoice');
    fd.append('invoice_id', id);
    fd.append('reason', reason);

    fetch('invoice_api.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            closeModal('declineModal');
            if (data.success) {
                showToast('Invoice declined. Buyer notified.', 'success');
                setTimeout(() => location.reload(), 1800);
            } else {
                showToast('❌ ' + (data.message || 'Declination failed.'), 'error');
            }
        });
}

// ── Copy POP upload link ──────────────────────────────────
function copyUploadLink(url) {
    document.getElementById('copyLinkInput').value = url;
    document.getElementById('copyConfirm').style.display = 'none';
    openModal('copyModal');
}
function doCopy() {
    const inp = document.getElementById('copyLinkInput');
    inp.select();
    navigator.clipboard.writeText(inp.value).then(() => {
        document.getElementById('copyConfirm').style.display = 'block';
    }).catch(() => {
        document.execCommand('copy');
        document.getElementById('copyConfirm').style.display = 'block';
    });
}

// ── Modal helpers ─────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.ig-modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ── Toast notification ────────────────────────────────────
function showToast(msg, type = 'info') {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:2rem;right:2rem;z-index:99999;padding:.9rem 1.5rem;border-radius:8px;font-weight:600;font-size:.9rem;box-shadow:0 6px 24px rgba(0,0,0,.18);max-width:400px;transition:opacity .4s;';
    t.style.background = type === 'success' ? '#155724' : '#721c24';
    t.style.color = '#fff';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 500); }, 3500);
}

// ── Sample data fill ──────────────────────────────────────
function stdPrintFill() {
    const f = document.getElementById('standardForm');
    f.buyer_name.value         = 'Pheta Setlojoane';
    f.buyer_id_passport.value  = 'RS031609';
    f.buyer_phone.value        = '+266 5886 4386';
    f.buyer_email.value        = 'Phetasetloj@gmail.com';
    f.buyer_address.value      = '49 Qhoalinyane Ridge\nThetsane West\nMaseru Lesotho';
    f.total_price.value        = 244000;
    f.vehicle_description.value= '2022 Land Rover Range Rover Velar 2.0D HSE R-Dynamic | D200';
    f.vehicle_year.value       = 2022;
    f.vehicle_mileage.value    = '66 897 km';
    f.vehicle_transmission.value = 'Automatic';
    f.vehicle_fuel.value       = 'Diesel';
    f.vehicle_body.value       = 'SUV';
    f.vehicle_colour.value     = 'Santorini Black';
    f.vehicle_condition.value  = 'Used';
    f.vehicle_service_history.value = 'Yes';
    f.vehicle_features.value   = 'DEKRA Certified\nAccident Free\nOne previous owner\nMechanical & Electronic Warranty\nNation-wide delivery\nSADC delivery';
}

// ══════════════════════════════════════════════════════
//  DEAL BUILDER — Tab 3
// ══════════════════════════════════════════════════════

// State
let dbImages = [];      // base64 strings
let dbActiveImg = 0;
let dbDbImg = null;     // URL from DB vehicle

// Helpers
const dbG  = id => document.getElementById(id);
const dbV  = id => (dbG(id) ? dbG(id).value.trim() : '');
function dbFmtR(n){ n=parseFloat(n)||0; return 'R\u00a0'+n.toLocaleString('en-ZA',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function dbFmtDate(d){ if(!d)return''; try{return new Date(d+'T00:00:00').toLocaleDateString('en-ZA',{day:'numeric',month:'long',year:'numeric'});}catch{return d;} }
function dbEsc(s){ const d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }

// Tab switching (form tabs)
function dbSw(name){
    ['buyer','vehicle','images','deal','company'].forEach((n,i)=>{
        const tabs = document.querySelectorAll('.db-tab');
        if(tabs[i]) tabs[i].classList.toggle('on', n===name);
        const tc = dbG('dbtc-'+n);
        if(tc) tc.classList.toggle('on', n===name);
    });
}
// Preview tabs
function dbPt(name, el){
    document.querySelectorAll('.db-ptab').forEach(t=>t.classList.remove('on'));
    if(el) el.classList.add('on');
    ['email','listing','code'].forEach(n=>{ const p=dbG('dbPv-'+n); if(p) p.style.display=(n===name?'block':'none'); });
}

// Vehicle picker
function dbFilterVehicles(){
    const q=(dbG('dbVpSearch').value||'').toLowerCase();
    const sel=dbG('dbVpSelect');
    Array.from(sel.options).forEach(opt=>{
        if(!opt.value){ opt.style.display=''; return; }
        opt.style.display=(!q||opt.text.toLowerCase().includes(q))?'':'none';
    });
}
function dbLoadVehicle(id){
    if(!id) return;
    const v=DB_VEHICLES.find(x=>String(x.id)===String(id));
    if(!v) return;
    // Populate Vehicle tab
    const setV=(fid,val)=>{ const el=dbG(fid); if(el){el.value=val||'';} };
    setV('dbVTitle',   (v.year?v.year+' ':'')+( v.make_name?v.make_name+' ':'')+v.item_name);
    setV('dbVMake',    v.make_name);
    setV('dbVYear',    v.year);
    setV('dbVMileage', v.mileage ? v.mileage+' km' : '');
    setV('dbVColour',  v.color);
    setV('dbVEngine',  v.engine_size);
    // Selects
    const setS=(fid,val)=>{
        const el=dbG(fid); if(!el||!val) return;
        Array.from(el.options).forEach(o=>{ o.selected=(o.value===val||o.text===val); });
    };
    setS('dbVFuel',  v.fuel_type);
    setS('dbVTrans', v.transmission);
    setS('dbVBody',  v.body_type);
    setS('dbVCond',  v.condition_grade);
    if(v.service_history){ const el=dbG('dbVSvc'); if(el) el.value=v.service_history?'Full Service History':'No Service History'; }
    // Features from description
    setV('dbVFeatures', 'DEKRA Certified, Accident Free');
    if(v.description) setV('dbVNotes', v.description.substring(0,200));
    // Price from DB
    const price=v.current_price||v.start_price||0;
    setV('dbDPrice', price);
    // DB image
    if(v.image_url){
        dbDbImg=v.image_url;
        dbRenderImageUI();
    }
    // Picker thumb
    const thumb=dbG('dbVpThumb');
    if(thumb&&v.image_url){ thumb.src=v.image_url; thumb.style.display='block'; }
    dbU();
}
function dbClearVehicle(){
    dbG('dbVpSelect').value='';
    dbG('dbVpSearch').value='';
    const thumb=dbG('dbVpThumb'); if(thumb){ thumb.src=''; thumb.style.display='none'; }
    dbDbImg=null;
    dbRenderImageUI();
    dbU();
}

// Images
function dbGetActiveImage(){
    if(dbImages.length>0) return dbImages[dbActiveImg];
    return dbDbImg||null;
}
function dbHandleImages(files){
    Array.from(files).slice(0, 6-dbImages.length).forEach(file=>{
        const r=new FileReader();
        r.onload=e=>{ dbImages.push(e.target.result); dbActiveImg=0; dbRenderImageUI(); dbU(); };
        r.readAsDataURL(file);
    });
}
function dbRenderImageUI(){
    const img=dbGetActiveImage();
    const prompt=dbG('dbUploadPrompt'); const main=dbG('dbMainThumb'); const strip=dbG('dbImgStrip');
    if(!img){ if(prompt)prompt.style.display='block'; if(main)main.style.display='none'; if(strip)strip.innerHTML=''; return; }
    if(prompt)prompt.style.display='none';
    if(main){main.style.display='block'; main.src=img;}
    if(strip){
        let h='';
        if(dbImages.length>0){
            dbImages.forEach((s,i)=>{ h+=`<img class="db-thumb-sm ${i===dbActiveImg?'on':''}" src="${s}" onclick="dbSetImg(${i})" alt="img ${i+1}"/>`; });
            if(dbImages.length<6) h+=`<label class="db-add-btn">+<input type="file" accept="image/*" multiple style="display:none" onchange="dbHandleImages(this.files)"/></label>`;
        } else if(dbDbImg){
            h+=`<img class="db-thumb-sm on" src="${dbDbImg}" alt="DB image" title="Database image"/>`;
        }
        strip.innerHTML=h;
    }
}
function dbSetImg(i){ dbActiveImg=i; dbRenderImageUI(); dbU(); }
function dbClearImages(){ dbImages=[]; dbActiveImg=0; dbG('dbImgInput').value=''; dbRenderImageUI(); dbU(); }

// Compute fees
function dbCompute(){
    const total=parseFloat(dbV('dbDPrice'))||0;
    const pct=parseFloat(dbV('dbDPct'))||35;
    const comm=Math.round(total*pct/100);
    const rem=total-comm;
    const lbl=dbG('dbPctLbl'); if(lbl) lbl.textContent=pct+'%';
    const ca=dbG('dbCommitAmt'); if(ca) ca.textContent=total?dbFmtR(comm):'—';
    const ra=dbG('dbRemAmt'); if(ra) ra.textContent=total?dbFmtR(rem):'—';
    return {total,pct,comm,rem};
}

// Main update
function dbU(){
    const {total,pct,comm,rem}=dbCompute();
    const D={
        bName:  dbV('dbBName')||'[Buyer Name]',
        bEmail: dbV('dbBEmail'),
        bPhone: dbV('dbBPhone'),
        bPass:  dbV('dbBPass'),
        bAddr:  dbV('dbBAddr'),
        bCity:  dbV('dbBCity'),
        vTitle: dbV('dbVTitle')||'[Vehicle]',
        vMake:  dbV('dbVMake'),
        vYear:  dbV('dbVYear'),
        vMileage: dbV('dbVMileage'),
        vColour:  dbV('dbVColour'),
        vFuel:    dbV('dbVFuel'),
        vTrans:   dbV('dbVTrans'),
        vBody:    dbV('dbVBody'),
        vEngine:  dbV('dbVEngine'),
        vCond:    dbV('dbVCond'),
        vSvc:     dbV('dbVSvc'),
        vFeatures: dbV('dbVFeatures'),
        vNotes:   dbV('dbVNotes'),
        invNo:    dbV('dbDInvNo'),
        invDate:  dbV('dbDInvDate'),
        expiry:   dbFmtDate(dbV('dbDExpiry')),
        bank:     dbV('dbDBank'),
        acct:     dbV('dbDAcct'),
        branch:   dbV('dbDBranch'),
        agent:    dbV('dbCAgent')||'Auction Hub Group',
        cName:    dbV('dbCName')||'AUCTION HUB GROUP',
        cDir:     dbV('dbCDir'),
        cVat:     dbV('dbCVat'),
        cPhone:   dbV('dbCPhone'),
        cEmail:   dbV('dbCEmail'),
        cWeb:     dbV('dbCWeb'),
        cAddr:    dbV('dbCAddr'),
        total,pct,comm,rem,
        img: dbGetActiveImage(),
    };
    dbRenderEmail(D);
    dbRenderListing(D);
    dbRenderCode(D);
}

// Email preview
function dbRenderEmail(D){
    const specs=[
        D.vYear&&`📅 ${D.vYear}`,D.vMileage&&`🛣 ${D.vMileage}`,D.vColour&&`🎨 ${D.vColour}`,
        D.vFuel&&`⛽ ${D.vFuel}`,D.vTrans&&`⚙️ ${D.vTrans}`,D.vBody&&`🚗 ${D.vBody}`,D.vEngine&&`🔧 ${D.vEngine}`,
    ].filter(Boolean);
    const feats=D.vFeatures?D.vFeatures.split(',').filter(f=>f.trim()).slice(0,8):[];
    const imgH=D.img?`<img src="${D.img}" alt="${dbEsc(D.vTitle)}" style="width:100%;height:185px;object-fit:cover;display:block"/>`
        :`<div style="width:100%;height:130px;background:linear-gradient(135deg,rgba(0,91,161,.2),rgba(201,151,43,.1));display:flex;align-items:center;justify-content:center;font-size:40px">🚘</div>`;
    const ep=dbG('dbEmailPreview'); if(!ep) return;
    ep.innerHTML=`
<div style="background:linear-gradient(135deg,#003a70 0%,#005BA1 55%,#0070c5 100%);padding:26px 28px 24px;position:relative;overflow:hidden">
  <div style="display:flex;align-items:center;gap:11px">
    <div style="width:44px;height:44px;background:#fff;border-radius:9px;display:grid;place-items:center;font-family:serif;font-size:20px;color:#005BA1;font-weight:900;box-shadow:0 2px 10px rgba(0,0,0,.2)">P</div>
    <div><div style="font-family:serif;font-size:15px;color:#fff;font-weight:700">${dbEsc(D.cName)}</div><div style="font-size:10px;color:rgba(255,255,255,.55);letter-spacing:.9px;text-transform:uppercase">Centurion, South Africa 🇿🇦</div></div>
  </div>
  <div style="display:inline-block;margin-top:12px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;font-size:10.5px;font-weight:700;padding:3px 11px;border-radius:20px">🏁 PRE-AUCTION EXCLUSIVE OFFER — Ref: ${dbEsc(D.invNo||'—')}</div>
  <div style="font-size:22px;color:#fff;font-weight:900;margin-top:10px;line-height:1.3">Your Dream Car.<br/><span style="color:#7dd3fc">Secured Before Auction.</span></div>
</div>
<div style="padding:26px 28px">
  <div style="font-size:14.5px;font-weight:600;color:#1A1A2E;margin-bottom:7px">Dear ${dbEsc(D.bName)},</div>
  <p style="font-size:13px;color:#444;line-height:1.8;margin-bottom:18px">We are delighted to present you with an <strong>exclusive Pre-Auction offer</strong> on the vehicle below. Own this unit at the agreed price — <strong>before it goes to public auction</strong> — no registration, no bidding, no hidden costs. Pay your commitment fee today to receive your <strong>Buyer Code</strong>.</p>

  <div style="background:linear-gradient(135deg,#003a70 0%,#005BA1 60%,#0070c5 100%);border-radius:12px;overflow:hidden;margin-bottom:18px">
    ${imgH}
    <div style="padding:16px 18px">
      <div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:rgba(255,255,255,.45);margin-bottom:5px">Featured Vehicle</div>
      <div style="font-size:14.5px;font-weight:700;color:#fff;line-height:1.35;margin-bottom:10px">${dbEsc(D.vTitle)}</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">${specs.map(s=>`<span style="background:rgba(255,255,255,.09);color:rgba(255,255,255,.8);font-size:11px;padding:3px 9px;border-radius:16px;border:1px solid rgba(255,255,255,.12)">${s}</span>`).join('')}</div>
      <div style="display:flex;flex-wrap:wrap;gap:5px">
        <span style="background:rgba(22,163,74,.18);color:#86efac;font-size:10px;font-weight:600;padding:2px 8px;border-radius:14px;border:1px solid rgba(22,163,74,.25)">✓ DEKRA Certified</span>
        <span style="background:rgba(22,163,74,.18);color:#86efac;font-size:10px;font-weight:600;padding:2px 8px;border-radius:14px;border:1px solid rgba(22,163,74,.25)">✓ Accident Free</span>
        <span style="background:rgba(22,163,74,.18);color:#86efac;font-size:10px;font-weight:600;padding:2px 8px;border-radius:14px;border:1px solid rgba(22,163,74,.25)">✓ Mech &amp; Elec Warranty</span>
        <span style="background:rgba(22,163,74,.18);color:#86efac;font-size:10px;font-weight:600;padding:2px 8px;border-radius:14px;border:1px solid rgba(22,163,74,.25)">✓ Nationwide Delivery</span>
        ${feats.map(f=>`<span style="background:rgba(201,151,43,.18);color:#f0c060;font-size:10px;padding:2px 8px;border-radius:12px;border:1px solid rgba(201,151,43,.25)">★ ${dbEsc(f.trim())}</span>`).join('')}
      </div>
    </div>
  </div>

  ${D.expiry?`<div style="background:linear-gradient(90deg,#005BA1,#0070c5);border-radius:8px;padding:10px 14px;margin-bottom:16px;display:flex;align-items:center;gap:9px;color:#fff;font-size:12.5px;font-weight:600">⏰ <span>This exclusive offer expires <strong>${D.expiry}</strong>. After this date the vehicle proceeds to public auction.</span></div>`:''}

  <div style="margin-bottom:18px">
    <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#71717A;margin-bottom:9px">Price Breakdown</div>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <tr><th style="background:#003f73;color:#fff;text-align:left;padding:8px 11px;font-size:10.5px;letter-spacing:.4px;text-transform:uppercase">Description</th><th style="background:#003f73;color:#fff;text-align:right;padding:8px 11px;font-size:10.5px;letter-spacing:.4px;text-transform:uppercase">Amount</th></tr>
      <tr><td style="padding:9px 11px;border-bottom:1px solid #E2E2EA">Total Asking Price</td><td style="text-align:right;padding:9px 11px;border-bottom:1px solid #E2E2EA;font-weight:700">${dbFmtR(D.total)}</td></tr>
      <tr><td style="padding:9px 11px;border-bottom:1px solid #E2E2EA"><strong style="color:#005BA1">Commitment Fee (${D.pct}%)</strong><span style="font-size:10.5px;color:#71717A;display:block;margin-top:2px">Payable upfront — secures Buyer Code &amp; inspection</span></td><td style="text-align:right;padding:9px 11px;border-bottom:1px solid #E2E2EA;font-weight:700;color:#005BA1;font-size:14px">${dbFmtR(D.comm)}</td></tr>
      <tr><td style="padding:9px 11px">Remaining Balance<span style="font-size:10.5px;color:#71717A;display:block;margin-top:2px">Due before delivery / handover</span></td><td style="text-align:right;padding:9px 11px;font-weight:700">${dbFmtR(D.rem)}</td></tr>
    </table>
  </div>

  <div style="display:flex;gap:9px;border-radius:8px;padding:11px 14px;font-size:12.5px;margin-bottom:12px;background:#F0FDF4;border:1px solid #BBF7D0;color:#15803d">
    <span style="font-size:16px;line-height:1.4">✅</span>
    <div><strong style="display:block;margin-bottom:2px">How It Works — Simple 4-Step Process</strong>Pay commitment fee → Receive Buyer Code → 1hr 30min test drive &amp; inspection → Settle balance &amp; take delivery. No auction, no bidding war. Your car, guaranteed.</div>
  </div>

  <div style="background:#F7F7FA;border:1.5px solid #E2E2EA;border-radius:10px;padding:14px 16px;margin-bottom:18px">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#1A1A2E;margin-bottom:10px">Payment Instructions</div>
    <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12.5px;border-bottom:1px dotted #E2E2EA"><span>Bank</span><span>${dbEsc(D.bank)}</span></div>
    <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12.5px;border-bottom:1px dotted #E2E2EA"><span>Account Name</span><span>${dbEsc(D.cName)}</span></div>
    <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12.5px;border-bottom:1px dotted #E2E2EA"><span>Account Number</span><span><strong>${dbEsc(D.acct)}</strong></span></div>
    <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12.5px;border-bottom:1px dotted #E2E2EA"><span>Branch Code</span><span>${dbEsc(D.branch)}</span></div>
    <div style="display:flex;justify-content:space-between;padding:9px 0 5px;font-size:13.5px;font-weight:700"><span>Amount Due Now</span><span style="color:#005BA1;font-size:15px;font-weight:800">${dbFmtR(D.comm)}</span></div>
    <div style="font-size:11.5px;color:#71717A;margin-top:9px;padding:8px 11px;background:#fff;border-radius:6px;border:1px solid #E2E2EA">📎 Once paid, send <strong>Proof of Payment</strong> to <strong>${dbEsc(D.cEmail)}</strong>. Buyer Code issued within 1 hour.</div>
  </div>

  <div style="text-align:center;margin:20px 0">
    <a style="display:inline-block;background:#005BA1;color:#fff;font-weight:700;font-size:13.5px;padding:13px 34px;border-radius:8px;text-decoration:none">🔒 Secure This Deal Now</a>
    <div style="font-size:11px;color:#71717A;margin-top:7px">Questions? Call or WhatsApp — we respond within the hour.</div>
  </div>

  <div style="background:linear-gradient(135deg,#1A1A2E 0%,#16213E 100%);border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:10px;align-items:center">
    <div style="display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.75);font-size:12px"><span>📞</span><a href="tel:${dbEsc(D.cPhone)}" style="color:rgba(255,255,255,.9);font-weight:500">${dbEsc(D.cPhone)}</a></div>
    <div style="display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.75);font-size:12px"><span>📧</span><a href="mailto:${dbEsc(D.cEmail)}" style="color:rgba(255,255,255,.9);font-weight:500">${dbEsc(D.cEmail)}</a></div>
    <div style="display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.75);font-size:12px"><span>🌐</span><a href="https://${dbEsc(D.cWeb)}" style="color:rgba(255,255,255,.9);font-weight:500">${dbEsc(D.cWeb)}</a></div>
  </div>

  <div style="font-size:13px;color:#555;line-height:1.9;margin-bottom:18px">Warm regards,<br/><strong>${dbEsc(D.agent)}</strong><br/><span style="font-size:11.5px;color:#71717A">Sales Agent — ${dbEsc(D.cName)}</span></div>
</div>
<div style="background:#1A1A2E;padding:14px 22px;text-align:center">
  <p style="font-size:10.5px;color:rgba(255,255,255,.4);line-height:1.9"><strong style="color:rgba(255,255,255,.6)">${dbEsc(D.cName)}</strong><br/>${dbEsc(D.cAddr)}<br/><a href="https://${dbEsc(D.cWeb)}" style="color:rgba(255,255,255,.6)">${dbEsc(D.cWeb)}</a></p>
  <p style="font-size:9.5px;color:rgba(255,255,255,.25);margin-top:5px">This email contains a legally binding pre-auction offer. Price valid for the stated duration.</p>
</div>`;
}

// Listing card
function dbRenderListing(D){
    const specs=[
        {i:'🛣',l:D.vMileage},{i:'⛽',l:D.vFuel},{i:'⚙️',l:D.vTrans},
        {i:'🎨',l:D.vColour},{i:'🔧',l:D.vEngine},{i:'🚗',l:D.vBody},
    ].filter(s=>s.l);
    const imgH=D.img?`<img class="db-lcard-img" src="${D.img}" alt="${dbEsc(D.vTitle)}"/>`
        :`<div class="db-lcard-img-ph">🚘</div>`;
    const card=dbG('dbListingCard'); if(!card) return;
    card.innerHTML=`
${imgH}
<div style="padding:18px">
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
    <span style="background:#e8f3fc;color:#005BA1;border:1px solid #c3ddf5;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px">🏁 Pre-Auction</span>
    <span style="background:#F0FDF4;color:#16A34A;border:1px solid #BBF7D0;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px">✓ DEKRA Certified</span>
    <span style="background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px">Accident Free</span>
  </div>
  <div style="font-family:serif;font-size:15px;font-weight:700;color:#1A1A2E;line-height:1.4;margin-bottom:12px">${dbEsc(D.vTitle)}</div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-bottom:14px">
    ${specs.slice(0,6).map(s=>`<div style="display:flex;align-items:center;gap:6px;font-size:12px;color:#71717A"><span>${s.i}</span><span>${dbEsc(s.l)}</span></div>`).join('')}
  </div>
  <div style="display:flex;align-items:flex-end;justify-content:space-between;border-top:1px solid #E2E2EA;padding-top:13px">
    <div>
      <div style="font-size:10px;color:#71717A;text-transform:uppercase;letter-spacing:.5px">Asking Price</div>
      <div style="font-family:serif;font-size:22px;font-weight:800;color:#005BA1;line-height:1.1">${dbFmtR(D.total)}</div>
      <div style="font-size:11px;color:#71717A;margin-top:2px">From ${dbFmtR(D.comm)} commitment to secure</div>
    </div>
    <button style="background:#005BA1;color:#fff;font-size:12.5px;font-weight:700;padding:10px 18px;border-radius:8px;border:none;cursor:pointer">Enquire Now →</button>
  </div>
</div>`;
}

// Code snippet
function dbRenderCode(D){
    const specLines=[
        D.vMileage&&`      <div>🛣 ${dbEsc(D.vMileage)}</div>`,
        D.vFuel&&`      <div>⛽ ${dbEsc(D.vFuel)}</div>`,
        D.vTrans&&`      <div>⚙️ ${dbEsc(D.vTrans)}</div>`,
        D.vColour&&`      <div>🎨 ${dbEsc(D.vColour)}</div>`,
        D.vEngine&&`      <div>🔧 ${dbEsc(D.vEngine)}</div>`,
        D.vBody&&`      <div>🚗 ${dbEsc(D.vBody)}</div>`,
    ].filter(Boolean).join('\n');
    const imgSrc=D.img?'YOUR_IMAGE_URL_HERE':'';
    const code=`<!-- Auction Hub Group — Vehicle Listing Card -->
<div style="max-width:400px;font-family:sans-serif;border-radius:14px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12);background:#fff">
  <img src="${imgSrc}" alt="${dbEsc(D.vTitle)}" style="width:100%;height:210px;object-fit:cover;display:block"/>
  <div style="padding:18px">
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
      <span style="background:#e8f3fc;color:#005BA1;border:1px solid #c3ddf5;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px">🏁 Pre-Auction</span>
      <span style="background:#F0FDF4;color:#16A34A;border:1px solid #BBF7D0;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px">✓ DEKRA Certified</span>
    </div>
    <div style="font-size:15px;font-weight:700;color:#1A1A2E;line-height:1.4;margin-bottom:12px">${dbEsc(D.vTitle)}</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-bottom:14px;font-size:12px;color:#71717A">
${specLines}
    </div>
    <div style="display:flex;align-items:flex-end;justify-content:space-between;border-top:1px solid #E2E2EA;padding-top:13px">
      <div>
        <div style="font-size:10px;color:#71717A;text-transform:uppercase">Asking Price</div>
        <div style="font-size:22px;font-weight:800;color:#005BA1">${dbFmtR(D.total)}</div>
        <div style="font-size:11px;color:#71717A">From ${dbFmtR(D.comm)} to secure</div>
      </div>
      <a href="mailto:${dbEsc(D.cEmail)}" style="background:#005BA1;color:#fff;font-size:12.5px;font-weight:700;padding:10px 18px;border-radius:8px;text-decoration:none">Enquire →</a>
    </div>
  </div>
</div>`;
    const box=dbG('dbCodeBox'); if(box) box.textContent=code;
}
function dbCopyCode(){
    const t=dbG('dbCodeBox'); if(!t) return;
    navigator.clipboard.writeText(t.textContent).then(()=>dbShowToast('📋 Code copied!')).catch(()=>{
        const r=document.createRange(); r.selectNode(t); window.getSelection().addRange(r);
        document.execCommand('copy'); window.getSelection().removeAllRanges(); dbShowToast('📋 Code copied!');
    });
}

// PDF generation
async function dbGeneratePDF(){
    if(!window.jspdf){ dbShowToast('PDF library loading, try again shortly...'); return; }
    const {total,pct,comm,rem}=dbCompute();
    const D={
        bName:dbV('dbBName'),bPass:dbV('dbBPass'),bPhone:dbV('dbBPhone'),bEmail:dbV('dbBEmail'),
        bAddr:dbV('dbBAddr'),bCity:dbV('dbBCity'),
        vTitle:dbV('dbVTitle'),vMake:dbV('dbVMake'),vYear:dbV('dbVYear'),vMileage:dbV('dbVMileage'),
        vColour:dbV('dbVColour'),vFuel:dbV('dbVFuel'),vTrans:dbV('dbVTrans'),vBody:dbV('dbVBody'),
        vEngine:dbV('dbVEngine'),vCond:dbV('dbVCond'),vSvc:dbV('dbVSvc'),vFeatures:dbV('dbVFeatures'),
        invNo:dbV('dbDInvNo'),invDate:dbV('dbDInvDate'),expiry:dbV('dbDExpiry'),
        bank:dbV('dbDBank'),acct:dbV('dbDAcct'),branch:dbV('dbDBranch'),
        cName:dbV('dbCName')||'AUCTION HUB GROUP',
        cDir:dbV('dbCDir'),cVat:dbV('dbCVat'),cPhone:dbV('dbCPhone'),
        cAddr:dbV('dbCAddr')||'80 Sterling Rd, Kosmosdal, Centurion, 0187',
        cWeb:dbV('dbCWeb')||'www.auctionhubgroup.co.za',
        cEmail:dbV('dbCEmail')||'info@auctionhubgroup.co.za',
        cAgent:dbV('dbCAgent'),
        total,pct,comm,rem
    };

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
    const W=210, M=14, cW=W-M*2; // A4: 210mm wide, 297mm tall

    // Color palette — deep navy brand palette
    const RED=[0,31,92],    // --auction-dark  #001F5C (was red, now dark navy)
          NAVY=[0,43,127],  // --auction-navy  #002B7F
          WHITE=[255,255,255];
    const LGREY=[0,18,70],  // dark card bg
          PALE=[0,14,55],   // deeper dark
          AMBER=[255,250,225];
    const GREEN=[34,197,94], LGREEN=[20,80,45];
    const TEXT=[255,255,255], MUTED=[180,205,255];
    // Dark-on-light variants — for sections with white/light backgrounds
    const DARK=[26,26,26],        // dark body text on light bg
          DMUTED=[80,100,128];    // dark muted label on light bg
    const DKBLUE=[0,10,40],  // very dark navy
          ACCENT=[30,144,255]; // --auction-light #1E90FF

    const sf=(...c)=>doc.setFillColor(...c);
    const st=(...c)=>doc.setTextColor(...c);
    const sd=(...c)=>doc.setDrawColor(...c);
    const fr=n=>dbFmtR(n);

    // Truncate text to fit within maxWidth (prevents overflow for fixed-height boxes)
    const clip=(text,maxW,fs)=>{
        doc.setFontSize(fs||9);
        const lines=doc.splitTextToSize(String(text||''),maxW);
        return lines[0]||'';
    };

    let y=0;

    // ══════════════════════════════════════════════════════
    // PAGE 1 — INVOICE
    // ══════════════════════════════════════════════════════

    // ── HEADER (y=8, height=44) — deep navy gradient effect ──
    sf(...RED); doc.roundedRect(M, 8, cW, 44, 3, 3, 'F');
    // Accent stripe
    sf(...NAVY); doc.roundedRect(M, 8, 4, 44, 2, 0, 'F');
    sf(...ACCENT); doc.rect(M, 8, 2, 44, 'F');

    // Trading name — large and dominant
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(18);
    doc.text(D.cName, M+8, 20);

    // Registered as line
    st(160,200,255); doc.setFontSize(8); doc.setFont('helvetica','italic');
    doc.text('Registered as: Auction Hub Auctioneers (Pty) Ltd', M+8, 27);

    // Subtitle
    st(185,220,255); doc.setFontSize(8.5); doc.setFont('helvetica','bold');
    doc.text('SALES AGREEMENT & TAX INVOICE', M+8, 33);

    // Reg / SARS / Address line
    st(160,200,255); doc.setFontSize(7); doc.setFont('helvetica','normal');
    doc.text('Reg: K2016224499  |  SARS Tax No: 9982096167  |  '+D.cAddr, M+8, 39.5, {maxWidth:116});
    doc.text([D.cPhone, D.cEmail].filter(Boolean).join('  |  '), M+8, 44.5, {maxWidth:116});

    // Right box: invoice meta
    const rxW=56, rx=W-M-rxW;
    sf(...NAVY); doc.rect(rx-1, 9.5, rxW, 44, 'F');
    // accent stripe on right box
    sf(...ACCENT); doc.rect(rx-1, 9.5, 2, 44, 'F');
    st(170,210,255); doc.setFont('helvetica','bold'); doc.setFontSize(12);
    doc.text('INVOICE', rx+rxW/2, 17.5, {align:'center'});
    doc.setFontSize(8); st(175,210,255); doc.setFont('helvetica','normal');
    doc.text('Ref:', rx+2, 23.5);
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(7.5);
    doc.text(D.invNo||'—', rx+rxW-2, 23.5, {align:'right'});
    st(175,210,255); doc.setFont('helvetica','normal'); doc.setFontSize(8);
    doc.text('Date:', rx+2, 29.5);
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(7.5);
    doc.text(D.invDate||'—', rx+rxW-2, 29.5, {align:'right'});
    st(175,210,255); doc.setFont('helvetica','normal'); doc.setFontSize(8);
    doc.text('Due:', rx+2, 35.5);
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(7.5);
    doc.text(D.invDate||'—', rx+rxW-2, 35.5, {align:'right'});
    // Total amount prominent
    st(30,144,255); doc.setFont('helvetica','bold'); doc.setFontSize(13);
    doc.text(fr(D.total), rx+rxW/2, 47, {align:'center'});

    y=56; // header bottom (8+44) + 4 gap

    // ── BILL TO / SELLER (labels 7mm + content 44mm + 2 gap = 53mm) ──
    const bW=90, sW=cW-bW-2, sx=M+bW+2;

    // Section label bars
    sf(...NAVY); doc.rect(M,y,bW,7,'F'); doc.rect(sx,y,sW,7,'F');
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(8.5);
    doc.text('BILL TO / PURCHASER', M+3, y+5.2);
    doc.text('SELLER', sx+3, y+5.2);
    y+=7;

    // Content background
    const bH=44;
    sf(...LGREY); doc.rect(M,y,bW,bH,'F'); doc.rect(sx,y,sW,bH,'F');

    // Buyer column
    st(...TEXT); doc.setFont('helvetica','bold'); doc.setFontSize(10.5);
    doc.text(clip(D.bName,bW-8,10.5), M+3, y+8);
    doc.setFontSize(8.5); doc.setFont('helvetica','normal'); st(...MUTED);
    let by=y+15;
    if(D.bPass){ doc.text('ID/Passport: '+D.bPass, M+3, by, {maxWidth:bW-6}); by+=7; }
    if(D.bAddr){ doc.text(D.bAddr, M+3, by, {maxWidth:bW-6}); by+=6.5; }
    if(D.bCity){ doc.text(D.bCity, M+3, by, {maxWidth:bW-6}); by+=6.5; }
    if(D.bPhone){ doc.text('Tel: '+D.bPhone, M+3, by, {maxWidth:bW-6}); by+=6.5; }
    if(D.bEmail && by<=y+bH-3) doc.text(D.bEmail, M+3, by, {maxWidth:bW-6});

    // Seller column
    st(...TEXT); doc.setFont('helvetica','bold'); doc.setFontSize(10.5);
    doc.text(D.cName, sx+3, y+8, {maxWidth:sW-6});
    st(...MUTED); doc.setFont('helvetica','normal'); doc.setFontSize(8.5);
    doc.text('(Status Owner)', sx+3, y+14.5);
    doc.text(D.cAddr, sx+3, y+21, {maxWidth:sW-6});
    if(D.cVat) doc.text(D.cVat, sx+3, y+31, {maxWidth:sW-6});
    doc.text([D.cPhone, D.cEmail].filter(Boolean).join('  |  '), sx+3, y+37.5, {maxWidth:sW-6});
    y+=bH+2;

    // ── LEGAL DISCLAIMER ──
    sf(255,251,220); doc.rect(M,y,cW,8,'F');
    sd(215,180,40); doc.setLineWidth(0.3); doc.rect(M,y,cW,8,'S');
    st(100,72,5); doc.setFont('helvetica','bold'); doc.setFontSize(7.5);
    doc.text('[!] THIS IS A LEGALLY BINDING DOCUMENT — Agreement between the Buyer (Client) and the Seller (Auction Hub Group). Ensure all details are correct.', M+3, y+5.5, {maxWidth:cW-6});
    y+=10;

    // ── VEHICLE INFORMATION ──
    sf(...NAVY); doc.rect(M,y,cW,7.5,'F');
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(9.5);
    doc.text('VEHICLE INFORMATION', M+4, y+5.5);
    y+=7.5;

    // Vehicle title (red bar) — handle multi-line titles
    const vtLines=doc.splitTextToSize(D.vTitle||'—', cW-10); doc.setFontSize(11);
    const vtH=Math.max(9, vtLines.length*6.5+3);
    sf(...RED); doc.rect(M,y,cW,vtH,'F');
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(11);
    doc.text(vtLines, M+4, y+7);
    y+=vtH+1;

    // Specs grid — 2 columns, 7.5mm rows
    const specs=[
        ['Year',D.vYear],['Mileage',D.vMileage],
        ['Body Type',D.vBody],['Colour',D.vColour],
        ['Fuel',D.vFuel],['Transmission',D.vTrans],
        ['Condition',D.vCond],['Service Hist.',D.vSvc],
    ].filter(([,v])=>v);
    const nRows=Math.ceil(specs.length/2), specRH=7.5;
    sf(...PALE); doc.rect(M,y,cW,nRows*specRH,'F');
    specs.forEach(([k,v],i)=>{
        const cx=i%2===0?M:M+cW/2;
        const ry=y+(Math.floor(i/2)*specRH)+5.8;
        st(...MUTED); doc.setFont('helvetica','normal'); doc.setFontSize(8); doc.text(k+':', cx+3, ry);
        st(...TEXT); doc.setFont('helvetica','bold'); doc.setFontSize(8.5); doc.text(clip(String(v),cW/2-35,8.5), cx+32, ry);
    });
    y+=nRows*specRH+1;

    // Features bar (no unicode emoji — use [v] bullet)
    const rawF=D.vFeatures?D.vFeatures.split(',').map(s=>s.trim()).filter(Boolean):[];
    const feats=(rawF.length>0?rawF:['Accident Free','1 Previous Owner','Mech & Elec Warranty','Nationwide Delivery','SADC Delivery']).slice(0,5);
    sf(...LGREEN); doc.rect(M,y,cW,8.5,'F');
    st(...GREEN); doc.setFont('helvetica','bold'); doc.setFontSize(8);
    doc.text(feats.map(f=>'[v] '+f).join('   '), M+4, y+6.2, {maxWidth:cW-8});
    y+=10.5;

    // ── PRICE BREAKDOWN ──
    sf(...NAVY); doc.rect(M,y,cW,7.5,'F');
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(9.5);
    doc.text('PRICE BREAKDOWN', M+4, y+5.5);
    y+=7.5;

    // Total price highlight (light blue bg — use dark text for contrast)
    sf(232,244,255); doc.rect(M,y,cW,12,'F');
    st(...DMUTED); doc.setFont('helvetica','normal'); doc.setFontSize(8.5); doc.text('TOTAL PURCHASE PRICE', M+4, y+4.8);
    st(0,31,92); doc.setFont('helvetica','bold'); doc.setFontSize(16); doc.text(fr(D.total), M+4, y+11.5);
    st(80,100,130); doc.setFont('helvetica','normal'); doc.setFontSize(7.5);
    doc.text('ZAR  |  VAT Inclusive  |  No hidden costs', W-M-4, y+7.5, {align:'right'});
    y+=12;

    // Commitment fee row (light blue bg — use dark text for contrast)
    sf(232,242,255); doc.rect(M,y,cW,13,'F');
    st(0,31,92); doc.setFont('helvetica','bold'); doc.setFontSize(9.5);
    doc.text('Commitment Fee ('+D.pct+'%) — Pay before auction', M+4, y+5.8);
    st(...DMUTED); doc.setFont('helvetica','normal'); doc.setFontSize(7.5);
    doc.text('Secures your Buyer Code & 1.5hr inspection booking. Refundable if not satisfied.', M+4, y+11);
    st(0,31,92); doc.setFont('helvetica','bold'); doc.setFontSize(14);
    doc.text(fr(D.comm), W-M-4, y+9.5, {align:'right'});
    y+=13;

    // Remaining balance row (dark navy bg — white text is correct here)
    sf(0,14,55); doc.rect(M,y,cW,12,'F');
    st(...TEXT); doc.setFont('helvetica','bold'); doc.setFontSize(9.5);
    doc.text('Remaining Balance — Due before delivery / handover', M+4, y+5.8);
    st(120,180,255); doc.setFont('helvetica','bold'); doc.setFontSize(13);
    doc.text(fr(D.rem), W-M-4, y+9, {align:'right'});
    y+=12;

    // Refund notice
    sf(...AMBER); doc.rect(M,y,cW,7,'F');
    st(108,78,5); doc.setFont('helvetica','normal'); doc.setFontSize(7.5);
    doc.text('[!] Refund Policy: All funds 100% refundable, excluding R102.00 for administration & bank charges.', M+3, y+5, {maxWidth:cW-6});
    y+=9;

    // ── PAYMENT INSTRUCTIONS ──
    sf(...NAVY); doc.rect(M,y,cW,7.5,'F');
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(9.5);
    doc.text('PAYMENT INSTRUCTIONS', M+4, y+5.5);
    y+=7.5;

    const payRows=[
        ['Bank', D.bank||'Nedbank'],
        ['Account Holder', D.cName],
        ['Account Number', D.acct||'—'],
        ['Branch Code', D.branch||'—'],
        ['Reference', 'Use your full name and surname'],
    ];
    payRows.forEach(([k,v],i)=>{
        const rh=8.5;
        // Alternate white / very-light-blue rows — use DARK text for contrast
        sf(i%2===0?255:240, i%2===0?255:246, i%2===0?255:255);
        doc.rect(M,y,cW,rh,'F');
        st(...DMUTED); doc.setFont('helvetica','normal'); doc.setFontSize(8.5); doc.text(k, M+4, y+6.2);
        st(...DARK);   doc.setFont('helvetica','bold');   doc.setFontSize(9);   doc.text(clip(String(v||'—'),cW-68,9), M+62, y+6.2);
        y+=rh;
    });

    // POP note
    sf(232,244,255); doc.rect(M,y,cW,7,'F');
    st(...NAVY); doc.setFont('helvetica','normal'); doc.setFontSize(7.5);
    doc.text('Send Proof of Payment (P.O.P) to: '+D.cEmail+'   |   N.B. All funds 100% refundable excluding R102.00 admin charges.', M+3, y+5.2, {maxWidth:cW-6});
    y+=7;

    // Amount due banner
    sf(...RED); doc.rect(M,y,cW,11,'F');
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(10);
    doc.text('AMOUNT TO PAY NOW (Commitment Fee):', M+4, y+7.8);
    doc.setFontSize(13); doc.text(fr(D.comm), W-M-4, y+7.8, {align:'right'});
    y+=15;

    // Page 1 footer
    sf(...NAVY); doc.rect(M,y,cW,18,'F');
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(9);
    doc.text(D.cName+' (Pty) Ltd', M+4, y+5.5);
    st(200,220,255); doc.setFont('helvetica','italic'); doc.setFontSize(7.5);
    doc.text('Invoice: '+(D.invNo||'—')+'  |  Reg: 1966/007846/07  |  SARS: 9156107840', W-M-4, y+5.5, {align:'right'});
    st(185,210,255); doc.setFont('helvetica','normal'); doc.setFontSize(7.5);
    doc.text(D.cAddr, M+4, y+11, {maxWidth:120});
    doc.text([D.cPhone, D.cEmail, D.cWeb].filter(Boolean).join('  |  '), M+4, y+16, {maxWidth:120});
    st(200,220,255); doc.setFont('helvetica','italic'); doc.setFontSize(7.5);
    doc.text('Level 2 BBBEE  |  IEASA  |  JCCI  |  NAA  |  SAIA', W-M-4, y+11, {align:'right'});
    doc.text('CPA, FICA & POPI Act Compliant', W-M-4, y+16, {align:'right'});

    // ══════════════════════════════════════════════════════
    // PAGE 2 — SALES AGREEMENT
    // ══════════════════════════════════════════════════════
    doc.addPage(); y=14;

    // Page 2 header bar
    sf(...RED); doc.rect(M,y,cW,11,'F');
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(12);
    doc.text('SALES AGREEMENT — SALE OF A MOTOR VEHICLE AT AUCTION HUB GROUP', M+4, y+8);
    y+=16;

    // Intro paragraph
    const intro=doc.splitTextToSize(
        'Auction Hub Group (Status Owner) — Entered into by and between (here referred to as "The Seller") '+
        'and you '+( D.bName||'[Buyer]' )+' (here referred to as "The Purchaser").',
        cW);
    st(...TEXT); doc.setFont('helvetica','normal'); doc.setFontSize(9);
    doc.text(intro, M, y); y+=intro.length*5.5+3;

    const intro2Lines=doc.splitTextToSize(
        'It is recorded that all registration and licensing fees payable until the date of signing are fully paid. '+
        'The vehicle is not stolen and is roadworthy on the date of signature of this agreement, and the relevant '+
        'authorities will issue a Roadworthy Certificate or Dekra Certificate. '+
        'NB. The price is only valid within a given duration by financial institutions for a pre-auction deal. '+
        'The vehicle comes with a Mechanical and Electronic Warranty. The buyer acknowledges that the vehicle is used '+
        'and will be test driven and inspected for 1 hour 30 minutes once the commitment fee is paid.',
        cW);
    doc.text(intro2Lines, M, y); y+=intro2Lines.length*5.5+4;

    // T&C header
    sf(232,244,255); doc.rect(M,y,cW,8,'F');
    sd(...NAVY); doc.setLineDashPattern([],0); doc.setLineWidth(0.3); doc.rect(M,y,cW,8,'S');
    st(...NAVY); doc.setFont('helvetica','bold'); doc.setFontSize(10.5);
    doc.text('TERMS & CONDITIONS', M+4, y+5.8);
    y+=12;

    const tcSections=[
        {h:'[v] DELIVERY', items:[
            '(a) The Seller will hand over possession of the vehicle to the Buyer once the full purchase price reflects in the Seller\'s bank account. Free Delivery for South Africa citizens only.',
        ]},
        {h:'[v] TOTAL ASKING PRICE: '+fr(D.total), items:[
            '(a) The purchase price is '+fr(D.total)+' at no additional fees or hidden costs.',
            '(b) The Purchaser shall pay a commitment fee of '+D.pct+'% ('+fr(D.comm)+') before the vehicle is moved for auction.',
        ]},
        {h:'[v] REGISTRATION', items:[
            '(a) The Seller will pay all costs involved in the change of ownership and obtaining a roadworthy certificate.',
            '(b) The Seller will sign all necessary documents to enable the Buyer to register the vehicle in the Buyer\'s name.',
        ]},
        {h:'[v] WARRANTIES AND VOETSTOOTS SALE', items:[
            '(a) The Seller sells the vehicle voetstoots (as-is). We offer 06-12 months mechanical & electronic warranty.',
            '(b) The Seller warrants full right and authority to sell and transfer the vehicle.',
            '(c) The Seller warrants that the vehicle is roadworthy. The Buyer should also get their own mechanic to inspect.',
            '(d) The Seller will sign all change of ownership documentation.',
        ]},
        {h:'[v] INSURANCE', items:[
            '(a) The Buyer will ensure the vehicle is comprehensively insured at their own expense. The Seller cancels current insurance on the day of purchase.',
        ]},
        {h:'[v] DEFAULT', items:[
            '(a) If the Buyer fails to pay on the agreed due date or breaches any term, the Seller may claim specific performance without prejudice to other rights.',
        ]},
        {h:'[v] CANCELLATION', items:[
            '(a) Should either party breach this agreement and fail to correct within 6 hours of receiving the proforma invoice, the aggrieved party may cancel by written notice.',
        ]},
    ];

    tcSections.forEach(sec=>{
        if(y>264){ doc.addPage(); y=14; }
        st(...NAVY); doc.setFont('helvetica','bold'); doc.setFontSize(9.5);
        doc.text(sec.h, M, y); y+=6;
        sec.items.forEach(item=>{
            if(y>268){ doc.addPage(); y=14; }
            const lines=doc.splitTextToSize('    '+item, cW-4);
            st(...TEXT); doc.setFont('helvetica','normal'); doc.setFontSize(8.5);
            doc.text(lines, M, y); y+=lines.length*5.3+1;
        });
        y+=3;
    });

    // Authorised signatory only
    y+=8;
    if(y>250){ doc.addPage(); y=20; }

    sf(232,244,255); doc.rect(M,y,cW,7.5,'F');
    st(...NAVY); doc.setFont('helvetica','bold'); doc.setFontSize(9.5);
    doc.text('AUTHORISED SIGNATORY', M+4, y+5.5);
    y+=11;

    st(...TEXT); doc.setFont('helvetica','normal'); doc.setFontSize(9);
    doc.text('Date: '+(D.invDate||new Date().toISOString().slice(0,10)), M, y);
    y+=12;

    sd(...NAVY); doc.setLineDashPattern([1.5,1.2],0); doc.setLineWidth(0.5);
    doc.line(M+102, y+14, W-M, y+14);
    st(...TEXT); doc.setFont('helvetica','bold'); doc.setFontSize(9);
    doc.text(D.cAgent||D.cName, M+102, y+8);
    st(...MUTED); doc.setFont('helvetica','normal'); doc.setFontSize(8);
    doc.text('SELLER / AUTHORISED SIGNATORY', M+102, y+20);
    y+=30;

    // Page 2 footer
    sf(...NAVY); doc.rect(M,y,cW,18,'F');
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(9);
    doc.text(D.cName+' (Pty) Ltd', M+4, y+6);
    st(200,220,255); doc.setFont('helvetica','italic'); doc.setFontSize(7.5);
    doc.text('Invoice: '+(D.invNo||'—')+'  |  Reg: 1966/007846/07  |  SARS Tax No: 9156107840', W-M-4, y+6, {align:'right'});
    st(185,210,255); doc.setFont('helvetica','normal'); doc.setFontSize(7.5);
    doc.text('Trusted by banks, respected by buyers  |  Trusted Auctioneering Excellence Since 2016', M+4, y+11.5, {maxWidth:120});
    doc.text([D.cAddr, D.cPhone, D.cEmail].filter(Boolean).join('  |  '), M+4, y+16.5, {maxWidth:120});
    st(200,220,255); doc.setFontSize(7.5);
    doc.text('Level 2 BBBEE  |  CPA, FICA & POPI Act', W-M-4, y+11.5, {align:'right'});
    doc.text('Member: IEASA, JCCI, NAA, SAIA', W-M-4, y+16.5, {align:'right'});

    // ══════════════════════════════════════════════════════
    // PAGE 3 — VEHICLE LISTING SUMMARY
    // ══════════════════════════════════════════════════════
    doc.addPage(); y=14;

    sf(...NAVY); doc.rect(M,y,cW,11,'F');
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(12);
    doc.text('VEHICLE LISTING SUMMARY', M+4, y+8);
    y+=17;

    sf(...RED); doc.roundedRect(M,y,cW,10,2,2,'F');
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(11);
    doc.text('PRE-AUCTION EXCLUSIVE OFFER', M+4, y+7.5);
    y+=15;

    const vtL2=doc.splitTextToSize(D.vTitle||'—', cW); doc.setFontSize(15);
    st(...NAVY); doc.setFont('helvetica','bold');
    doc.text(vtL2, M, y); y+=vtL2.length*8+4;

    const pg3grid=[
        ['Make',D.vMake],['Year',D.vYear],['Mileage',D.vMileage],['Colour',D.vColour],
        ['Fuel',D.vFuel],['Transmission',D.vTrans],['Engine',D.vEngine],['Body',D.vBody],
    ].filter(([,v])=>v);
    const gRows=Math.ceil(pg3grid.length/2);
    sf(...PALE); doc.rect(M,y,cW,gRows*9,'F');
    pg3grid.forEach(([k,v],i)=>{
        const cx=i%2===0?M:M+cW/2, ry=y+(Math.floor(i/2)*9)+6.5;
        st(...MUTED); doc.setFont('helvetica','normal'); doc.setFontSize(8.5); doc.text(k+':', cx+3, ry);
        st(...TEXT); doc.setFont('helvetica','bold'); doc.setFontSize(9.5); doc.text(clip(String(v),cW/2-34,9.5), cx+30, ry);
    });
    y+=gRows*9+8;

    sf(...RED); doc.roundedRect(M,y,cW,22,3,3,'F');
    st(...WHITE); doc.setFont('helvetica','normal'); doc.setFontSize(10); doc.text('ASKING PRICE', M+5, y+8);
    doc.setFontSize(19); doc.setFont('helvetica','bold'); doc.text(fr(D.total), M+5, y+18.5);
    st(255,218,170); doc.setFontSize(10); doc.setFont('helvetica','normal');
    doc.text('From '+fr(D.comm)+' commitment to secure', W-M-5, y+14, {align:'right'});
    y+=28;

    sf(...LGREEN); doc.rect(M,y,cW,10,'F');
    st(...GREEN); doc.setFont('helvetica','bold'); doc.setFontSize(9);
    doc.text('[v] DEKRA Certified   [v] Accident Free   [v] Mech & Elec Warranty   [v] Nationwide Delivery   [v] SADC Delivery', M+4, y+7.2, {maxWidth:cW-8});
    y+=16;

    sf(...NAVY); doc.rect(M,y,cW,18,'F');
    st(...WHITE); doc.setFont('helvetica','bold'); doc.setFontSize(10);
    doc.text(D.cName, M+4, y+6.5);
    st(185,215,255); doc.setFont('helvetica','normal'); doc.setFontSize(8.5);
    doc.text([D.cPhone, D.cEmail, D.cWeb].filter(Boolean).join('  |  '), M+4, y+12, {maxWidth:cW-8});
    doc.text(D.cAddr, M+4, y+17, {maxWidth:cW-8});

    doc.save('AHG-Invoice-'+(D.invNo||'Draft')+'.pdf');
    dbShowToast('PDF downloaded successfully!');
}

// Save to DB
function dbSaveInvoice(){
    const bName=dbV('dbBName'), bEmail=dbV('dbBEmail');
    if(!bName||!bEmail){ dbShowToast('⚠️ Buyer name & email required to save.'); return; }
    if(!dbV('dbVTitle')){ dbShowToast('⚠️ Vehicle description required.'); return; }
    const fd=new FormData();
    fd.append('action','create_preauction_invoice');
    fd.append('buyer_name', bName);
    fd.append('buyer_email', bEmail);
    fd.append('buyer_phone', dbV('dbBPhone'));
    fd.append('buyer_id_passport', dbV('dbBPass'));
    fd.append('buyer_address', dbV('dbBAddr')+' '+dbV('dbBCity'));
    fd.append('vehicle_description', dbV('dbVTitle'));
    fd.append('vehicle_year', dbV('dbVYear'));
    fd.append('vehicle_mileage', dbV('dbVMileage'));
    fd.append('vehicle_transmission', dbV('dbVTrans'));
    fd.append('vehicle_fuel', dbV('dbVFuel'));
    fd.append('vehicle_body', dbV('dbVBody'));
    fd.append('vehicle_colour', dbV('dbVColour'));
    fd.append('vehicle_condition', dbV('dbVCond'));
    fd.append('vehicle_service_history', dbV('dbVSvc'));
    fd.append('vehicle_image_url', dbGetActiveImage()||dbV('dbVpThumb')||'');
    fd.append('vehicle_features', dbV('dbVFeatures'));
    fd.append('total_price', dbV('dbDPrice'));
    fd.append('commitment_pct', dbV('dbDPct'));
    fd.append('expiry_date', dbV('dbDExpiry'));
    fetch('invoice_api.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(data=>{
            if(data.success){
                dbShowToast('✅ Saved! Buyer Code: '+data.buyer_code);
                setTimeout(()=>copyUploadLink(data.upload_link), 600);
            } else { dbShowToast('❌ '+(data.message||'Save failed.')); }
        }).catch(()=>dbShowToast('❌ Network error.'));
}

// Fill MPHOLOANE01 sample data
function dbFillMpholoane(){
    const pad=n=>String(n).padStart(2,'0');
    const today=new Date();
    const ymd=today.getFullYear()+'-'+pad(today.getMonth()+1)+'-'+pad(today.getDate());
    const setV=(id,val)=>{ const el=dbG(id); if(el) el.value=val; };
    const setS=(id,val)=>{ const el=dbG(id); if(!el) return; Array.from(el.options).forEach(o=>{o.selected=(o.value===val||o.text===val);}); };
    // Buyer
    setV('dbBName',  'Bongani Lawrence Mpholoane');
    setV('dbBPass',  '9303206219080');
    setV('dbBPhone', '060 863 2364 | 066 120 8161');
    setV('dbBEmail', 'Bonganimpholoane@gmail.com');
    setV('dbBAddr',  '7164 Ext 17, Leandra 2265');
    setV('dbBCity',  'Mpumalanga, South Africa');
    // Vehicle
    setV('dbVTitle',   '2022 Haval Jolion 1.5T Super Luxury Auto');
    setV('dbVMake',    'Haval');
    setV('dbVYear',    '2022');
    setV('dbVMileage', '63 000 km');
    setV('dbVColour',  'Blue');
    setS('dbVFuel',    'Petrol');
    setS('dbVTrans',   'Automatic');
    setS('dbVBody',    'SUV');
    setS('dbVCond',    'Used');
    setV('dbVEngine',  '1.5T');
    setS('dbVSvc',     'Full Service History');
    setV('dbVFeatures','Accident Free, One Previous Owner, 06–12 Month Warranty Mechanical & Electronic, Nationwide Delivery, SADC Delivery, Dekra / Roadworthy Cert');
    // Deal
    setV('dbDPrice',   '77990');
    setV('dbDInvNo',   'MPHOLOANE01');
    setV('dbDInvDate', ymd);
    setV('dbDExpiry',  ymd);
    setV('dbDBank',    'Nedbank');
    setV('dbDAcct',    '1341275868');
    setV('dbDBranch',  '198765');
    const rng=dbG('dbDPct'); if(rng){ rng.value=50; }
    // Company
    setV('dbCName',  'AUCTION HUB GROUP');
    setV('dbCPhone', '010 516 0681 | 010 516 0683');
    setV('dbCEmail', 'info@auctionhubgroup.co.za');
    setV('dbCWeb',   'www.auctionhubgroup.co.za');
    setV('dbCAddr',  '80 Sterling Rd, Kosmosdal, Centurion, 0187');
    setV('dbCVat',   'SARS Tax No: 9156107840');
    setV('dbCDir',   'Reg No: 1966/007846/07');
    setV('dbCAgent', 'Auction Hub Group');
    dbU();
    dbSw('buyer');
    dbShowToast('✅ MPHOLOANE01 sample data loaded!');
}

// Reset
function dbResetAll(){
    ['dbBName','dbBPass','dbBPhone','dbBEmail','dbBAddr','dbBCity',
     'dbVTitle','dbVMake','dbVYear','dbVMileage','dbVColour','dbVEngine','dbVFeatures','dbVNotes',
     'dbDPrice','dbDInvNo','dbDInvDate','dbDExpiry','dbDBank','dbDAcct','dbDBranch'].forEach(id=>{
        const el=dbG(id); if(el) el.value='';
    });
    ['dbVFuel','dbVTrans','dbVBody','dbVCond','dbVSvc'].forEach(id=>{
        const el=dbG(id); if(el) el.selectedIndex=0;
    });
    const rng=dbG('dbDPct'); if(rng) rng.value=35;
    dbDbImg=null; dbImages=[]; dbActiveImg=0; dbRenderImageUI();
    dbG('dbVpSelect').value=''; dbG('dbVpSearch').value='';
    const th=dbG('dbVpThumb'); if(th){th.src='';th.style.display='none';}
    dbU();
    dbShowToast('↺ Form reset.');
}

// Mobile nav
function dbMnavSwitch(pane){
    const isMobile=window.innerWidth<=768;
    if(!isMobile) return;
    document.querySelectorAll('.db-mni').forEach(b=>b.classList.remove('on'));
    const form=dbG('db-pane-form'), prev=dbG('db-pane-preview');
    if(pane==='form'){
        dbG('dbMniForm').classList.add('on');
        form.classList.remove('db-mobile-hidden'); form.classList.add('db-mobile-show');
        prev.classList.add('db-mobile-hidden'); prev.classList.remove('db-mobile-show');
    } else {
        const mni=dbG('dbMni'+pane.charAt(0).toUpperCase()+pane.slice(1));
        if(mni) mni.classList.add('on');
        form.classList.add('db-mobile-hidden'); form.classList.remove('db-mobile-show');
        prev.classList.remove('db-mobile-hidden'); prev.classList.add('db-mobile-show');
        dbPt(pane, document.querySelector(`.db-ptab[onclick*="'${pane}'"]`));
    }
}

// Toast
function dbShowToast(msg){
    const t=dbG('dbToast'), m=dbG('dbToastMsg');
    if(!t||!m) return;
    m.textContent=msg; t.classList.add('show');
    setTimeout(()=>t.classList.remove('show'), 3000);
}

// Init builder defaults
(function initBuilder(){
    const now=new Date();
    const pad=n=>String(n).padStart(2,'0');
    const ymd=now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate());
    const rand=Math.random().toString(36).substr(2,6).toUpperCase();
    const invNo='AHG-PA-'+now.getFullYear()+pad(now.getMonth()+1)+'-'+rand;
    const el=dbG('dbDInvNo'); if(el) el.value=invNo;
    const dt=dbG('dbDInvDate'); if(dt) dt.value=ymd;
    // Expiry: 7 days out
    const exp=new Date(now); exp.setDate(exp.getDate()+7);
    const ey=dbG('dbDExpiry');
    if(ey) ey.value=exp.getFullYear()+'-'+pad(exp.getMonth()+1)+'-'+pad(exp.getDate());
    dbU();
})();

function fillPASample() {
    document.getElementById('pa_buyer_name').value  = 'Pheta Setlojoane';
    document.getElementById('pa_buyer_id').value    = 'RS031609';
    document.getElementById('pa_buyer_phone').value = '+266 5886 4386';
    document.getElementById('pa_buyer_email').value = 'Phetasetloj@gmail.com';
    document.getElementById('pa_buyer_address').value = '49 Qhoalinyane Ridge, Thetsane West, Maseru Lesotho';
    document.getElementById('pa_vdesc').value       = '2022 Land Rover Range Rover Velar 2.0D HSE R-Dynamic | D200';
    document.getElementById('pa_vyear').value       = 2022;
    document.getElementById('pa_vmileage').value    = '66 897 km';
    document.getElementById('pa_vtrans').value      = 'Automatic';
    document.getElementById('pa_vfuel').value       = 'Diesel';
    document.getElementById('pa_vbody').value       = 'SUV';
    document.getElementById('pa_vcolour').value     = 'Santorini Black';
    document.getElementById('pa_vcond').value       = 'Used';
    document.getElementById('pa_vsh').value         = 'Yes';
    document.getElementById('pa_vfeatures').value   = 'DEKRA Certified\nAccident Free\nOne previous owner\nMechanical & Electronic Warranty\nNation-wide delivery\nSADC delivery';
    document.getElementById('pa_total').value       = 244000;
    document.getElementById('pa_pct').value         = 35;
    calcFee();
}
</script>
