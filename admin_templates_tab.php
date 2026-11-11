<?php
// ============================================================
// AUCTION HUB GROUP — admin_templates_tab.php
// Admin → Templates. Included by admin_dashboard.php (tab=templates).
// The ready-made emails and WhatsApp messages used on the Leads page.
// Office wording is saved in site_settings; "Reset" restores the original.
// ============================================================
require_once __DIR__ . '/leads_helper.php';
$tpEmail = leadTemplates('email');
$tpWa    = leadTemplates('wa');
?>
<div class="dashboard-header">
  <div>
    <h1><i class="fas fa-pen-to-square"></i> Email &amp; WhatsApp Templates</h1>
    <p class="text-muted">Ready-made messages for the <a href="?tab=leads">Leads</a> page. Write plain words — the letterhead, links and banking table are added when sent.</p>
  </div>
</div>

<div class="card tp-settings">
  <label class="form-group" style="margin:0">
    <strong>Agent name</strong> <small class="text-muted">— fills <code>{agent}</code> in messages and call scripts</small>
    <div class="tp-inline">
      <input class="form-control" id="tpAgent" value="<?= e(leadSetting('lead_agent_name')) ?>" placeholder="e.g. Thandi Nkosi" maxlength="100">
      <button type="button" class="btn btn-primary btn-sm" id="tpAgentSave">Save</button>
    </div>
  </label>
  <details class="tp-ph">
    <summary>Placeholders you can use</summary>
    <dl><?php foreach (LEAD_PLACEHOLDERS as $ph => $what): ?><dt><code><?= e($ph) ?></code></dt><dd><?= e($what) ?></dd><?php endforeach; ?></dl>
  </details>
</div>

<div class="tp-tabs" role="tablist">
  <button type="button" class="tp-tab active" data-tp="email"><i class="fas fa-envelope"></i> Emails (<?= count($tpEmail) ?>)</button>
  <button type="button" class="tp-tab" data-tp="wa"><i class="fab fa-whatsapp"></i> WhatsApp (<?= count($tpWa) ?>)</button>
</div>

<div class="tp-list" data-tp-list="email">
  <?php foreach ($tpEmail as $k => $t): ?>
  <details class="card tp-item" data-channel="email" data-tpl="<?= $k ?>">
    <summary><span><?= e($t['label']) ?></span><?= $t['edited'] ? '<span class="tp-edited">Edited</span>' : '' ?></summary>
    <label class="form-group">Subject <input class="form-control tp-subject" value="<?= e($t['subject']) ?>" maxlength="255"></label>
    <label class="form-group">Message <textarea class="form-control tp-body" rows="12"><?= e($t['body']) ?></textarea></label>
    <div class="ld-sheet-actions">
      <button type="button" class="btn btn-primary btn-sm" data-tp-act="save_template"><i class="fas fa-save"></i> Save</button>
      <button type="button" class="btn btn-outline btn-sm" data-tp-act="preview"><i class="fas fa-eye"></i> Preview</button>
      <button type="button" class="btn btn-outline btn-sm" data-tp-act="reset_template"><i class="fas fa-rotate-left"></i> Reset to original</button>
      <span class="tp-msg" role="status"></span>
    </div>
    <iframe class="ld-preview" title="Email preview" hidden></iframe>
  </details>
  <?php endforeach; ?>
</div>

<div class="tp-list" data-tp-list="wa" hidden>
  <?php foreach ($tpWa as $k => $t): ?>
  <details class="card tp-item" data-channel="wa" data-tpl="<?= $k ?>">
    <summary><span><?= e($t['label']) ?></span><?= $t['edited'] ? '<span class="tp-edited">Edited</span>' : '' ?></summary>
    <label class="form-group">Message <small class="text-muted">— *bold* and _italic_ work in WhatsApp</small><textarea class="form-control tp-body" rows="11"><?= e($t['body']) ?></textarea></label>
    <div class="ld-sheet-actions">
      <button type="button" class="btn btn-primary btn-sm" data-tp-act="save_template"><i class="fas fa-save"></i> Save</button>
      <button type="button" class="btn btn-outline btn-sm" data-tp-act="reset_template"><i class="fas fa-rotate-left"></i> Reset to original</button>
      <span class="tp-msg" role="status"></span>
    </div>
  </details>
  <?php endforeach; ?>
</div>

<script>
(function () {
  function post(data) {
    var fd = new FormData(); Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('leads_api.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  document.querySelectorAll('.tp-tab').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.tp-tab').forEach(function (x) { x.classList.toggle('active', x === b); });
      document.querySelectorAll('[data-tp-list]').forEach(function (l) { l.hidden = l.dataset.tpList !== b.dataset.tp; });
    });
  });
  document.getElementById('tpAgentSave').addEventListener('click', function () {
    var b = this; post({ action: 'settings', agent_name: document.getElementById('tpAgent').value }).then(function (d) { b.textContent = d.success ? 'Saved ✓' : 'Error'; setTimeout(function () { b.textContent = 'Save'; }, 1500); });
  });
  document.querySelectorAll('[data-tp-act]').forEach(function (b) {
    b.addEventListener('click', function () {
      var item = b.closest('.tp-item'), act = b.dataset.tpAct, out = item.querySelector('.tp-msg');
      var subj = item.querySelector('.tp-subject'), body = item.querySelector('.tp-body');
      if (act === 'reset_template' && !confirm('Go back to the original wording for this template?')) return;
      var data = { action: act, channel: item.dataset.channel, tpl: item.dataset.tpl, body: body.value };
      if (subj) data.subject = subj.value;
      out.textContent = '…';
      post(data).then(function (d) {
        out.textContent = d.message || '';
        if (act === 'preview') { var f = item.querySelector('iframe'); f.hidden = false; f.srcdoc = d.html || ''; out.textContent = 'Preview uses a sample person.'; }
        if (d.template) { body.value = d.template.body; if (subj) subj.value = d.template.subject; }
      }).catch(function () { out.textContent = 'Could not reach the server.'; });
    });
  });
})();
</script>
