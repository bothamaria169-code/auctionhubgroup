<?php
// ============================================================
// AUCTION HUB GROUP — enquiry_form.php
// Reusable enquiry form. Set before including:
//   $enqAuctionId (int|null)  — vehicle the enquiry is about
//   $enqTitle     (string)    — card heading
//   $enqCompact   (bool)      — smaller card for the vehicle page
// Posts to enquiry.php; styles in style.css (.enq-*)
// ============================================================
require_once __DIR__ . '/leads_helper.php';
$enqAuctionId = $enqAuctionId ?? null;
$enqTitle     = $enqTitle ?? 'Send us a message';
$enqCompact   = $enqCompact ?? false;
$enqUser      = $currentUser ?? null;
$enqId        = 'enq' . substr(md5((string)$enqAuctionId . $enqTitle), 0, 6);
?>
<div class="enq-card<?= $enqCompact ? ' enq-compact' : '' ?>" id="<?= $enqId ?>">
  <div class="enq-head">
    <i class="fas fa-comment-dots" aria-hidden="true"></i>
    <div>
      <h2 class="enq-title"><?= e($enqTitle) ?></h2>
      <p class="enq-sub">We call or WhatsApp you back within one business day.</p>
    </div>
  </div>
  <form class="enq-form" novalidate>
    <input type="hidden" name="t" value="<?= time() ?>">
    <input type="hidden" name="source_page" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
    <?php if ($enqAuctionId): ?><input type="hidden" name="auction_id" value="<?= (int)$enqAuctionId ?>"><?php endif; ?>
    <div class="enq-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

    <div class="enq-grid">
      <label class="enq-field">
        <span>Your name</span>
        <input type="text" name="name" required autocomplete="name" maxlength="150" value="<?= e($enqUser['full_name'] ?? '') ?>">
      </label>
      <label class="enq-field">
        <span>Phone / WhatsApp</span>
        <input type="tel" name="phone" required autocomplete="tel" inputmode="tel" maxlength="30" placeholder="e.g. 082 123 4567">
      </label>
      <label class="enq-field">
        <span>Email <em>(optional)</em></span>
        <input type="email" name="email" autocomplete="email" maxlength="190" value="<?= e($enqUser['email'] ?? '') ?>">
      </label>
      <?php if (!$enqAuctionId): ?>
      <label class="enq-field">
        <span>What is it about?</span>
        <select name="type">
          <?php foreach (ENQUIRY_TYPES as $k => $label): ?>
            <option value="<?= $k ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
    </div>
    <label class="enq-field enq-full">
      <span>Message <em>(optional)</em></span>
      <textarea name="message" rows="<?= $enqCompact ? 3 : 4 ?>" maxlength="3000"
        placeholder="<?= $enqAuctionId ? 'e.g. Is it still available? Can I view it? Does it have a service history?' : 'How can we help?' ?>"></textarea>
    </label>
    <button type="submit" class="btn btn-primary enq-send">
      <i class="fas fa-paper-plane" aria-hidden="true"></i> <span>Send enquiry</span>
    </button>
    <p class="enq-note">Or WhatsApp us on <a href="<?= e(waLink($enqAuctionId ? 'Hi, I am interested in lot #' . (int)$enqAuctionId : 'Hi, I have a question')) ?>" target="_blank" rel="noopener"><?= e(waDisplay()) ?></a></p>
    <div class="enq-result" role="status" aria-live="polite" hidden></div>
  </form>
</div>
<?php if (empty($GLOBALS['enqScriptPrinted'])): $GLOBALS['enqScriptPrinted'] = true; ?>
<script>
document.addEventListener('submit', function (ev) {
  var form = ev.target;
  if (!form.classList || !form.classList.contains('enq-form')) return;
  ev.preventDefault();
  var btn = form.querySelector('.enq-send'), out = form.querySelector('.enq-result');
  var f = form.elements, name = f['name'].value.trim(), phone = f['phone'].value.replace(/\D/g, '');
  out.hidden = false; out.className = 'enq-result';
  if (name.length < 2) { out.textContent = 'Please tell us your name.'; out.classList.add('enq-err'); f['name'].focus(); return; }
  if (phone.length < 9) { out.textContent = 'Please give a phone number we can call or WhatsApp.'; out.classList.add('enq-err'); f['phone'].focus(); return; }
  btn.disabled = true; btn.querySelector('span').textContent = 'Sending…';
  fetch('<?= APP_URL ?>/enquiry.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      out.textContent = d.message || (d.success ? 'Thank you!' : 'Please try again.');
      out.classList.add(d.success ? 'enq-ok' : 'enq-err');
      if (d.success) {
        form.querySelectorAll('input:not([type=hidden]),textarea,select,button').forEach(function (el) { el.disabled = true; });
        try { (window.dataLayer = window.dataLayer || []).push({ event: 'generate_lead', lead_source: 'enquiry_form' }); } catch (e) {}
      }
    })
    .catch(function () { out.textContent = 'Could not send — please check your connection, or call <?= COMPANY_PHONE ?>.'; out.classList.add('enq-err'); })
    .finally(function () { if (!form.querySelector('.enq-ok')) { btn.disabled = false; } btn.querySelector('span').textContent = 'Send enquiry'; });
});
</script>
<?php endif; ?>
