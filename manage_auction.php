<?php
// ============================================================
// AUCTION HUB GROUP — manage_auction.php
// Add a new vehicle OR edit an existing one
// Access: ?id=123  → edit mode  |  no id → add mode
// ============================================================

require_once 'config.php';
require_once 'auth.php';

// ── Admin Guard ───────────────────────────────────────────────
$token = getTokenFromRequest();
if (!$token) redirect('login.php', 'Admin access required.', 'error');
$currentUser = verifyJWT($token);
if (!$currentUser || $currentUser['role'] !== 'admin') {
    redirect('admin_dashboard.php', 'Access denied.', 'error');
}
syncSession($currentUser);

// ── Load existing auction (edit mode) ─────────────────────────
$auctionId = (int)($_GET['id'] ?? 0);
$auction   = null;
$gallery   = [];
$isEdit    = false;

if ($auctionId) {
    $auction = $pdo->prepare("SELECT a.*, m.name AS make_name FROM auctions a LEFT JOIN makes m ON m.id = a.make_id WHERE a.id = ?");
    $auction->execute([$auctionId]);
    $auction = $auction->fetch();
    if (!$auction) redirect('admin_dashboard.php?tab=auctions', 'Auction not found.', 'error');
    $isEdit = true;

    $gallery = $pdo->prepare("SELECT * FROM auction_gallery WHERE auction_id = ? ORDER BY sort_order ASC");
    $gallery->execute([$auctionId]);
    $gallery = $gallery->fetchAll();
}

// ── Makes dropdown ─────────────────────────────────────────────
$makes = $pdo->query("SELECT id, name FROM makes ORDER BY name")->fetchAll();

// ── Branches dropdown ──────────────────────────────────────────
$branches = $pdo->query("SELECT id, name, city, province FROM branches ORDER BY sort_order")->fetchAll();

// ── Page setup ─────────────────────────────────────────────────
$pageTitle       = ($isEdit ? 'Edit Vehicle — ' : 'Add Vehicle — ') . APP_NAME;
$pageDescription = 'Manage auction vehicle listing.';
require_once 'header.php';
?>

<!-- ── Mobile admin nav (fixed, shown ≤900px) ────────────────── -->
<div class="admin-mobile-nav" id="adminMobileNav" aria-label="Admin navigation">
  <button type="button" class="admin-mnav-toggle" id="adminNavToggle"
          aria-haspopup="true" aria-expanded="false" aria-controls="adminNavPanel">
    <i class="fas fa-bars" aria-hidden="true"></i>
    <span class="admin-mnav-current"><?= $isEdit ? 'Edit Vehicle' : 'Add Vehicle' ?></span>
    <i class="fas fa-chevron-down admin-mnav-arrow" aria-hidden="true"></i>
  </button>
  <nav class="admin-mnav-panel" id="adminNavPanel" hidden aria-label="Admin sections">
    <a href="admin_dashboard.php?tab=overview"  class="admin-mnav-link"><i class="fas fa-th-large"></i><span>Overview</span></a>
    <a href="admin_dashboard.php?tab=pop"       class="admin-mnav-link"><i class="fas fa-file-invoice"></i><span>POP Approvals</span></a>
    <a href="admin_dashboard.php?tab=auctions"  class="admin-mnav-link"><i class="fas fa-car"></i><span>Auctions</span></a>
    <a href="admin_dashboard.php?tab=users"     class="admin-mnav-link"><i class="fas fa-users"></i><span>Users</span></a>
    <a href="admin_dashboard.php?tab=email"     class="admin-mnav-link"><i class="fas fa-envelope"></i><span>Email</span></a>
    <a href="admin_dashboard.php?tab=import"    class="admin-mnav-link"><i class="fas fa-file-csv"></i><span>CSV Import</span></a>
    <a href="manage_auction.php"                class="admin-mnav-link active"><i class="fas fa-plus-circle"></i><span>Add Vehicle</span></a>
    <a href="index.php"                         class="admin-mnav-link" target="_blank"><i class="fas fa-external-link-alt"></i><span>View Site</span></a>
    <a href="auth.php?action=logout"            class="admin-mnav-link admin-mnav-logout"><i class="fas fa-sign-out-alt"></i><span>Log Out</span></a>
  </nav>
</div>

<div class="admin-wrapper">

  <!-- Sidebar -->
  <div class="admin-sidebar-wrap">
  <aside class="admin-sidebar">
    <div class="admin-sidebar-brand">
      <i class="fas fa-shield-alt"></i><span>Admin Panel</span>
    </div>
    <nav class="sidebar-nav">
      <a href="admin_dashboard.php?tab=overview"  class="sidebar-link"><i class="fas fa-th-large"></i> Overview</a>
      <a href="admin_dashboard.php?tab=pop"        class="sidebar-link"><i class="fas fa-file-invoice"></i> POP Approvals</a>
      <a href="admin_dashboard.php?tab=auctions"   class="sidebar-link active"><i class="fas fa-car"></i> Auctions</a>
      <a href="admin_dashboard.php?tab=users"      class="sidebar-link"><i class="fas fa-users"></i> Users</a>
      <a href="admin_dashboard.php?tab=email"      class="sidebar-link"><i class="fas fa-envelope"></i> Email</a>
      <a href="admin_dashboard.php?tab=import"     class="sidebar-link"><i class="fas fa-file-csv"></i> CSV Import</a>
      <hr class="sidebar-divider">
      <a href="manage_auction.php" class="sidebar-link"><i class="fas fa-plus-circle"></i> Add Vehicle</a>
      <a href="index.php" class="sidebar-link" target="_blank"><i class="fas fa-external-link-alt"></i> View Site</a>
      <a href="auth.php?action=logout" class="sidebar-link sidebar-link-logout" id="logoutBtn">
        <i class="fas fa-sign-out-alt"></i> Log Out
      </a>
    </nav>
  </aside>
  </div><!-- /.admin-sidebar-wrap -->

  <main class="admin-main">

    <!-- Header -->
    <div class="dashboard-header">
      <div>
        <h1>
          <i class="fas fa-<?= $isEdit ? 'edit' : 'plus-circle' ?>"></i>
          <?= $isEdit ? 'Edit Vehicle' : 'Add New Vehicle' ?>
        </h1>
        <p class="text-muted">
          <?= $isEdit ? 'Editing: <strong>' . e($auction['item_name']) . '</strong>' : 'Create a new auction listing.' ?>
        </p>
      </div>
      <a href="admin_dashboard.php?tab=auctions" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Back to Auctions
      </a>
    </div>

    <!-- Alert area -->
    <div id="manageAlert" style="display:none;margin-bottom:1rem;"></div>

    <!-- ── FORM ──────────────────────────────────────────────── -->
    <form id="manageForm" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_auction">
      <input type="hidden" name="auction_id" value="<?= $auctionId ?>">
      <?php if ($isEdit && $auction['image_url']): ?>
        <input type="hidden" name="existing_image" value="<?= e(imgHost($auction['image_url'])) ?>">
      <?php endif; ?>

      <div class="grid-2" style="align-items:start;gap:1.5rem">

        <!-- LEFT COL: Core Details -->
        <div style="display:flex;flex-direction:column;gap:1.5rem">

          <!-- Vehicle Info Card -->
          <div class="card">
            <h3 style="margin-bottom:1.25rem"><i class="fas fa-car" style="color:var(--blue-primary)"></i> Vehicle Information</h3>

            <div class="form-group">
              <label>Vehicle Name / Title <span style="color:red">*</span></label>
              <input type="text" name="item_name" class="form-control" required
                     value="<?= e($auction['item_name'] ?? '') ?>"
                     placeholder="e.g. 2022 Toyota Hilux 2.8 GD-6 Double Cab">
              <small class="text-muted">Use format: Year Make Model Trim</small>
            </div>

            <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
              <div class="form-group">
                <label>Make <span style="color:red">*</span></label>
                <select name="make_id" id="makeSelect" class="form-control" onchange="toggleNewMake(this)">
                  <option value="">— Select Make —</option>
                  <?php foreach ($makes as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= ($auction['make_id'] ?? '') == $m['id'] ? 'selected' : '' ?>>
                      <?= e($m['name']) ?>
                    </option>
                  <?php endforeach; ?>
                  <option value="new">+ Add New Make</option>
                </select>
              </div>
              <div class="form-group" id="newMakeGroup" style="display:none">
                <label>New Make Name</label>
                <input type="text" name="make_name_new" id="newMakeName" class="form-control" placeholder="e.g. GWM">
              </div>
              <div class="form-group">
                <label>Year</label>
                <input type="number" name="year" class="form-control" min="1990" max="<?= date('Y') + 1 ?>"
                       value="<?= e($auction['year'] ?? '') ?>" placeholder="<?= date('Y') ?>">
              </div>
            </div>

            <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
              <div class="form-group">
                <label>Mileage (km)</label>
                <input type="number" name="mileage" class="form-control" min="0"
                       value="<?= e($auction['mileage'] ?? '') ?>" placeholder="e.g. 85000">
              </div>
              <div class="form-group">
                <label>Colour</label>
                <input type="text" name="color" class="form-control"
                       value="<?= e($auction['color'] ?? '') ?>" placeholder="e.g. Silver">
              </div>
              <div class="form-group">
                <label>Fuel Type</label>
                <select name="fuel_type" class="form-control">
                  <option value="">— Select —</option>
                  <?php foreach (['petrol','diesel','electric','hybrid','other'] as $ft): ?>
                    <option value="<?= $ft ?>" <?= ($auction['fuel_type'] ?? '') === $ft ? 'selected' : '' ?>>
                      <?= ucfirst($ft) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>Transmission</label>
                <select name="transmission" class="form-control">
                  <option value="">— Select —</option>
                  <?php foreach (['manual','automatic','other'] as $tr): ?>
                    <option value="<?= $tr ?>" <?= ($auction['transmission'] ?? '') === $tr ? 'selected' : '' ?>>
                      <?= ucfirst($tr) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>Body Type</label>
                <select name="body_type" class="form-control">
                  <option value="">— Select —</option>
                  <?php foreach (['Sedan','Hatchback','SUV','Bakkie','Double Cab','Single Cab','Minivan','Coupe','Convertible','Station Wagon','Commercial','Truck','Heavy Vehicle','Excavator','ADT','Crusher/Screener','Mining Equipment','Farming Equipment','Trailer','Other'] as $bt): ?>
                    <option value="<?= $bt ?>" <?= ($auction['body_type'] ?? '') === $bt ? 'selected' : '' ?>>
                      <?= $bt ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>Engine Size</label>
                <input type="text" name="engine_size" class="form-control"
                       value="<?= e($auction['engine_size'] ?? '') ?>" placeholder="e.g. 2.0L">
              </div>
              <div class="form-group">
                <label>VIN Number</label>
                <input type="text" name="vin_number" class="form-control"
                       value="<?= e($auction['vin_number'] ?? '') ?>" placeholder="17-char VIN">
              </div>
              <div class="form-group">
                <label>Branch / Warehouse <span style="color:#888;font-weight:400;font-size:.8em;">(where vehicle is located)</span></label>
                <select name="branch_id" class="form-control">
                  <option value="">— Unassigned —</option>
                  <?php foreach ($branches as $br): ?>
                    <option value="<?= $br['id'] ?>"
                            <?= ($auction['branch_id'] ?? '') == $br['id'] ? 'selected' : '' ?>>
                      <?= e($br['name']) ?> — <?= e($br['city']) ?>, <?= e($br['province']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>DEKRA Sticker</label>
                <select name="condition_grade" class="form-control">
                  <option value="">— Select —</option>
                  <?php foreach (['A'=>'Platinum Sticker — Roadworthy passed','B'=>'Gold Sticker — Will pass roadworthy','C'=>'Green Sticker — May require repairs'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= ($auction['condition_grade'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="form-group">
              <label class="checkbox-label" style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                <input type="checkbox" name="service_history" value="1"
                       <?= !empty($auction['service_history']) ? 'checked' : '' ?>>
                <span>Full Service History Available</span>
              </label>
            </div>

            <div class="form-group">
              <label>Description</label>
              <textarea name="description" class="form-control" rows="5"
                        placeholder="Describe the vehicle condition, features, any damage notes..."
                        style="resize:vertical"><?= e($auction['description'] ?? '') ?></textarea>
            </div>
          </div>

          <!-- Pricing Card -->
          <div class="card">
            <h3 style="margin-bottom:1.25rem"><i class="fas fa-tag" style="color:var(--blue-primary)"></i> Pricing</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
              <div class="form-group">
                <label>Start Price (R) <span style="color:red">*</span></label>
                <input type="number" name="start_price" class="form-control" required min="1" step="0.01"
                       value="<?= e($auction['start_price'] ?? '') ?>" placeholder="50000">
              </div>
              <div class="form-group">
                <label>Reserve Price (R)</label>
                <input type="number" name="reserve_price" class="form-control" min="0" step="0.01"
                       value="<?= e($auction['reserve_price'] ?? '') ?>" placeholder="Leave blank if none">
              </div>
              <div class="form-group">
                <label>Min. Bid Increment (R)</label>
                <input type="number" name="min_increment" class="form-control" min="100" step="100"
                       value="<?= e($auction['min_increment'] ?? 500) ?>">
              </div>
            </div>
            <p class="text-muted" style="font-size:.85rem;margin-top:.25rem">
              <i class="fas fa-info-circle"></i> Reserve price is visible to bidders. Leave at 0 for no reserve.
            </p>
          </div>

        </div><!-- /left col -->

        <!-- RIGHT COL: Images + Scheduling -->
        <div style="display:flex;flex-direction:column;gap:1.5rem">

          <!-- Main Image Card -->
          <div class="card">
            <h3 style="margin-bottom:1.25rem"><i class="fas fa-image" style="color:var(--blue-primary)"></i> Main Image</h3>

            <?php if ($isEdit && $auction['image_url']): ?>
            <div style="margin-bottom:1rem;text-align:center">
              <img src="<?= e(imgHost($auction['image_url'])) ?>" alt="Current main image"
                   style="max-width:100%;max-height:200px;border-radius:8px;object-fit:cover;border:2px solid #e5eaf0">
              <p class="text-muted" style="font-size:.8rem;margin-top:.4rem">Current main image</p>
            </div>
            <?php endif; ?>

            <label class="upload-drop-zone" id="mainImgDrop" for="mainImageInput" style="cursor:pointer;display:block">
              <div class="upload-drop-icon"><i class="fas fa-cloud-upload-alt" style="color:var(--blue-primary)"></i></div>
              <div class="upload-drop-text">
                <strong><?= $isEdit ? 'Replace main image' : 'Upload main image' ?></strong><br>
                <span class="text-muted">Click or drag &amp; drop — JPG, PNG, WEBP</span>
              </div>
              <div id="mainImgName" class="upload-drop-hint"></div>
              <input type="file" id="mainImageInput" name="main_image" accept="image/*"
                     style="position:absolute;opacity:0;width:0;height:0;pointer-events:none"
                     onchange="previewMainImage(this)">
            </label>
            <div id="mainImgPreview" style="display:none;margin-top:.75rem;text-align:center">
              <img id="mainImgPreviewImg" src="" alt="Preview"
                   style="max-width:100%;max-height:180px;border-radius:8px;object-fit:cover">
            </div>
          </div>

          <!-- Gallery Images Card -->
          <div class="card">
            <h3 style="margin-bottom:.5rem"><i class="fas fa-images" style="color:var(--blue-primary)"></i> Gallery Images</h3>
            <p class="text-muted" style="font-size:.85rem;margin-bottom:1rem">Upload multiple images. First image is the main thumbnail if no main image is set.</p>

            <?php if ($isEdit && !empty($gallery)): ?>
            <div id="currentGallery" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:.5rem;margin-bottom:1rem">
              <?php foreach ($gallery as $g): ?>
              <div class="gallery-thumb-wrap" id="gthumb-<?= $g['id'] ?>">
                <img src="<?= e($g['file_path']) ?>" alt="" style="width:100%;height:70px;object-fit:cover;border-radius:6px;border:1px solid #ddd">
                <button type="button" class="gallery-delete-btn"
                        onclick="deleteGalleryImage(<?= $g['id'] ?>, <?= $auctionId ?>)"
                        title="Remove">&times;</button>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <label class="upload-drop-zone" for="galleryInput" style="cursor:pointer;display:block">
              <div class="upload-drop-icon"><i class="fas fa-images" style="color:#6c757d"></i></div>
              <div class="upload-drop-text">
                <strong>Add gallery images</strong><br>
                <span class="text-muted">Select multiple — JPG, PNG, WEBP</span>
              </div>
              <input type="file" id="galleryInput" name="gallery_images[]" accept="image/*" multiple
                     style="position:absolute;opacity:0;width:0;height:0;pointer-events:none"
                     onchange="previewGallery(this)">
            </label>
            <div id="galleryNewPreviews" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:.5rem;margin-top:.75rem"></div>
          </div>

          <!-- Scheduling Card -->
          <div class="card">
            <h3 style="margin-bottom:1.25rem"><i class="fas fa-clock" style="color:var(--blue-primary)"></i> Scheduling &amp; Status</h3>

            <div class="form-group">
              <label>Status</label>
              <select name="status" class="form-control">
                <option value="upcoming" <?= ($auction['status'] ?? 'upcoming') === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                <option value="active"   <?= ($auction['status'] ?? '') === 'active'   ? 'selected' : '' ?>>Active (Live Now)</option>
                <option value="closed"   <?= ($auction['status'] ?? '') === 'closed'   ? 'selected' : '' ?>>Closed</option>
              </select>
            </div>
            <div class="form-group">
              <label>Start Time</label>
              <input type="datetime-local" name="start_time" class="form-control"
                     value="<?= $auction['start_time'] ? date('Y-m-d\TH:i', strtotime($auction['start_time'])) : '' ?>">
            </div>
            <div class="form-group">
              <label>End Time</label>
              <input type="datetime-local" name="end_time" class="form-control"
                     value="<?= $auction['end_time'] ? date('Y-m-d\TH:i', strtotime($auction['end_time'])) : '' ?>">
            </div>
          </div>

          <!-- Submit Card -->
          <div class="card" style="text-align:center;padding:1.5rem">
            <button type="submit" id="submitBtn" class="btn btn-primary" style="width:100%;font-size:1.05rem;padding:.9rem">
              <i class="fas fa-<?= $isEdit ? 'save' : 'plus-circle' ?>"></i>
              <?= $isEdit ? 'Save Changes' : 'Create Auction' ?>
            </button>
            <?php if ($isEdit): ?>
            <a href="auction.php?id=<?= $auctionId ?>" target="_blank"
               class="btn btn-outline" style="width:100%;margin-top:.75rem">
              <i class="fas fa-eye"></i> Preview Listing
            </a>
            <?php endif; ?>
            <a href="admin_dashboard.php?tab=auctions" class="btn btn-outline"
               style="width:100%;margin-top:.75rem;color:#666">
              Cancel
            </a>
          </div>

        </div><!-- /right col -->
      </div><!-- /grid-2 -->
    </form>

  </main>
</div>

<script>
// ── Make select toggle ─────────────────────────────────────────
function toggleNewMake(sel) {
    const grp = document.getElementById('newMakeGroup');
    const inp = document.getElementById('newMakeName');
    if (sel.value === 'new') {
        sel.value = '';
        grp.style.display = 'block';
        inp.required = true;
        inp.focus();
    } else {
        grp.style.display = 'none';
        inp.required = false;
    }
}

// ── Main image preview ─────────────────────────────────────────
function previewMainImage(input) {
    if (!input.files[0]) return;
    document.getElementById('mainImgName').textContent = input.files[0].name;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('mainImgPreviewImg').src = e.target.result;
        document.getElementById('mainImgPreview').style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
}

// ── Gallery previews ───────────────────────────────────────────
function previewGallery(input) {
    const wrap = document.getElementById('galleryNewPreviews');
    wrap.innerHTML = '';
    Array.from(input.files).forEach(file => {
        const reader = new FileReader();
        reader.onload = e => {
            const div = document.createElement('div');
            div.style.cssText = 'position:relative';
            div.innerHTML = `<img src="${e.target.result}" style="width:100%;height:70px;object-fit:cover;border-radius:6px;border:1px solid #ddd">`;
            wrap.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
}

// ── Delete gallery image (edit mode) ──────────────────────────
async function deleteGalleryImage(galleryId, auctionId) {
    if (!confirm('Remove this image?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_gallery_image');
    fd.append('image_id', galleryId);
    fd.append('auction_id', auctionId);
    try {
        const res = await fetch('api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const el = document.getElementById('gthumb-' + galleryId);
            if (el) el.remove();
            showToast('Image removed.', 'success');
        } else {
            showToast(data.message || 'Error removing image.', 'error');
        }
    } catch { showToast('Network error.', 'error'); }
}

// ── Drag-and-drop visual ───────────────────────────────────────
document.querySelectorAll('.upload-drop-zone').forEach(zone => {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
        e.preventDefault(); e.stopPropagation(); zone.classList.remove('drag-over');
        const inp = zone.querySelector('input[type=file]');
        if (inp && e.dataTransfer.files.length) {
            const dt = new DataTransfer();
            for (const file of e.dataTransfer.files) dt.items.add(file);
            inp.files = dt.files;
            inp.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
});

// ── Form submit ───────────────────────────────────────────────
document.getElementById('manageForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    const alert = document.getElementById('manageAlert');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    try {
        const res = await fetch('api.php', { method: 'POST', body: new FormData(this) });
        const data = await res.json();

        alert.style.display = 'block';
        alert.className = 'alert alert-' + (data.success ? 'success' : 'error');
        alert.innerHTML = (data.success ? '<i class="fas fa-check-circle"></i> ' : '<i class="fas fa-exclamation-triangle"></i> ') + data.message;
        alert.scrollIntoView({ behavior: 'smooth', block: 'center' });

        if (data.success) {
            setTimeout(() => {
                window.location.href = data.auction_id
                    ? 'manage_auction.php?id=' + data.auction_id + '&saved=1'
                    : 'admin_dashboard.php?tab=auctions';
            }, 1200);
        }
    } catch (err) {
        alert.style.display = 'block';
        alert.className = 'alert alert-error';
        alert.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Network error: ' + (err.message || 'Please check your connection and try again.');
        alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
        console.error('Form submission error:', err);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-<?= $isEdit ? 'save' : 'plus-circle' ?>"></i> <?= $isEdit ? 'Save Changes' : 'Create Auction' ?>';
    }
});

// ── Saved flash ───────────────────────────────────────────────
<?php if (isset($_GET['saved'])): ?>
document.addEventListener('DOMContentLoaded', () => {
    const alert = document.getElementById('manageAlert');
    alert.style.display = 'block';
    alert.className = 'alert alert-success';
    alert.innerHTML = '<i class="fas fa-check-circle"></i> Changes saved successfully!';
    setTimeout(() => { alert.style.display = 'none'; }, 4000);
});
<?php endif; ?>
</script>

<style>
.gallery-thumb-wrap { position:relative; }
.gallery-delete-btn {
    position:absolute; top:3px; right:3px;
    background:rgba(220,53,69,.85); color:#fff;
    border:none; border-radius:50%;
    width:20px; height:20px; font-size:12px;
    cursor:pointer; display:flex; align-items:center; justify-content:center;
    line-height:1;
}
.gallery-delete-btn:hover { background:#c82333; }
.drag-over { border-color:var(--blue-primary) !important; background:#f0f7ff !important; }
</style>

<script>
/* Admin mobile nav toggle */
(function () {
  var toggle = document.getElementById('adminNavToggle');
  var panel  = document.getElementById('adminNavPanel');
  if (!toggle || !panel) return;
  function openPanel()  { panel.hidden = false; toggle.setAttribute('aria-expanded', 'true'); }
  function closePanel() { panel.hidden = true;  toggle.setAttribute('aria-expanded', 'false'); }
  toggle.addEventListener('click', function (e) {
    e.preventDefault(); e.stopPropagation();
    panel.hidden ? openPanel() : closePanel();
  });
  document.addEventListener('click', function (e) {
    if (!panel.hidden && !panel.contains(e.target) && !toggle.contains(e.target)) closePanel();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) { closePanel(); toggle.focus(); }
  });
})();
</script>

<?php require_once 'footer.php'; ?>
