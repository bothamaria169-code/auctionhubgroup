<?php
// ============================================================
// AUCTION HUB GROUP — admin_leads_tab.php
// Admin → Leads. Included by admin_dashboard.php (tab=leads).
// One card per person: what they did, Call / WhatsApp / Email /
// Log call, the call script, and their stage. Styles: style.css (.ld-*)
// ============================================================
require_once __DIR__ . '/leads_helper.php';

$ldDays   = in_array((int)($_GET['days'] ?? 30), [7, 30, 90, 365], true) ? (int)($_GET['days'] ?? 30) : 30;
$ldPeople = leadPeople($ldDays);
$ldStageCounts = array_fill_keys(array_keys(LEAD_STAGES), 0);
$ldTypeCounts  = array_fill_keys(array_keys(LEAD_TYPES), 0);
$ldDue = 0;
$today = date('Y-m-d');
foreach ($ldPeople as $p) {
    $ldStageCounts[$p['stage']] = ($ldStageCounts[$p['stage']] ?? 0) + 1;
    $ldTypeCounts[$p['type']]++;
    if ($p['call_back_at'] && $p['call_back_at'] <= $today && !in_array($p['stage'], ['won', 'lost'], true)) $ldDue++;
}
$ldHistory  = leadMessageHistory(array_column($ldPeople, 'key'));
$ldScripts  = leadCallScripts();
$ldCommon   = leadCommonVars();
$ldEmailTpl = leadTemplates('email');
$ldWaTpl    = leadTemplates('wa');

// What the page script needs, per person (no internal fields beyond what the admin sees).
$ldJs = [];
foreach ($ldPeople as $p) {
    $ldJs[$p['key']] = [
        'name' => $p['name'], 'email' => $p['email'], 'phone' => $p['phone'], 'wa' => $p['wa'], 'type' => $p['type'],
        'user_id' => $p['user_id'], 'vars' => leadPersonVars($p), 'stage' => $p['stage'], 'outcome' => $p['outcome'],
        'note' => $p['note'], 'call_back_at' => $p['call_back_at'],
    ];
}
$ldStageIcon = ['new' => 'fa-circle-dot', 'contacted' => 'fa-paper-plane', 'called' => 'fa-phone', 'won' => 'fa-trophy', 'lost' => 'fa-circle-xmark'];
?>
<div class="dashboard-header">
  <div>
    <h1><i class="fas fa-user-plus"></i> Leads</h1>
    <p class="text-muted">Everyone who got in touch — one card per person. Contact the New ones first, then log each call.</p>
  </div>
  <div class="ld-head-actions">
    <a class="btn btn-sm btn-outline" href="?tab=templates"><i class="fas fa-pen-to-square"></i> Templates</a>
    <a class="btn btn-sm btn-outline" href="leads_api.php?action=export&days=<?= $ldDays ?>"><i class="fas fa-file-csv"></i> Export CSV</a>
  </div>
</div>

<div class="ld-toolbar card">
  <div class="ld-chips" role="tablist" aria-label="Filter by stage">
    <button type="button" class="ld-chip active" data-stage="open">To do <b><?= $ldStageCounts['new'] + $ldStageCounts['contacted'] + $ldStageCounts['called'] ?></b></button>
    <?php foreach (LEAD_STAGES as $k => $label): ?>
      <button type="button" class="ld-chip ld-chip-<?= $k ?>" data-stage="<?= $k ?>"><i class="fas <?= $ldStageIcon[$k] ?>"></i> <?= $label ?> <b><?= $ldStageCounts[$k] ?></b></button>
    <?php endforeach; ?>
    <?php if ($ldDue): ?><button type="button" class="ld-chip ld-chip-due" data-stage="due"><i class="fas fa-bell"></i> Call back today <b><?= $ldDue ?></b></button><?php endif; ?>
    <button type="button" class="ld-chip" data-stage="all">All <b><?= count($ldPeople) ?></b></button>
  </div>
  <div class="ld-filters">
    <input type="search" id="ldSearch" class="form-control" placeholder="Search name, phone, email, vehicle…" aria-label="Search leads">
    <select id="ldType" class="form-control" aria-label="Lead type">
      <option value="">All lead types</option>
      <?php foreach (LEAD_TYPES as $k => [$label]): if (!$ldTypeCounts[$k]) continue; ?>
        <option value="<?= $k ?>"><?= $label ?> (<?= $ldTypeCounts[$k] ?>)</option>
      <?php endforeach; ?>
    </select>
    <select class="form-control" aria-label="Period" onchange="location.href='?tab=leads&days='+this.value">
      <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'] as $d => $l): ?>
        <option value="<?= $d ?>"<?= $d === $ldDays ? ' selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<?php if (!$ldPeople): ?>
  <div class="card ld-empty">
    <i class="fas fa-inbox"></i>
    <p><strong>No leads in this period yet.</strong><br>Enquiries from the Contact page and the “Ask about this vehicle” form on every lot, new sign-ups, banking requests, saved searches and newsletter sign-ups all land here.</p>
  </div>
<?php endif; ?>

<div class="ld-list" id="ldList">
<?php foreach ($ldPeople as $p):
    [$typeLabel, $typeIcon] = LEAD_TYPES[$p['type']];
    $due = $p['call_back_at'] && $p['call_back_at'] <= $today && !in_array($p['stage'], ['won', 'lost'], true);
    $hay = strtolower(implode(' ', [$p['name'], $p['email'], $p['phone'], $p['detail'], $p['vehicle'], $p['note']]));
    $hist = $ldHistory[$p['key']] ?? [];
?>
  <article class="ld-card ld-stage-<?= $p['stage'] ?><?= $due ? ' ld-due' : '' ?>" data-key="<?= e($p['key']) ?>" data-stage="<?= $p['stage'] ?>"
           data-type="<?= $p['type'] ?>" data-due="<?= $due ? 1 : 0 ?>" data-hay="<?= e($hay) ?>">
    <header class="ld-card-head">
      <div class="ld-who">
        <h3 class="ld-name"><?= e($p['name']) ?><?php if ($p['is_new']): ?> <span class="ld-new">NEW</span><?php endif; ?></h3>
        <div class="ld-meta">
          <span class="ld-type ld-type-<?= $p['type'] ?>"><i class="fas <?= $typeIcon ?>"></i> <?= $typeLabel ?></span>
          <span title="<?= e(date('d M Y H:i', strtotime($p['at']))) ?>"><?= leadTimeAgo($p['at']) ?></span>
          <?php if ($p['bidder_status'] === 'verified_bidder'): ?><span class="ld-badge-ok"><i class="fas fa-check"></i> Verified bidder</span><?php endif; ?>
        </div>
      </div>
      <span class="ld-stage-pill"><i class="fas <?= $ldStageIcon[$p['stage']] ?>"></i> <?= LEAD_STAGES[$p['stage']] ?></span>
    </header>

    <p class="ld-detail"><?= e($p['detail']) ?></p>
    <?php if ($p['also']): ?>
      <ul class="ld-also">
        <?php foreach ($p['also'] as $a): ?>
          <li><i class="fas <?= LEAD_TYPES[$a['type']][1] ?>"></i> <?= e($a['detail']) ?><?= $a['times'] > 1 ? ' (' . $a['times'] . '×)' : '' ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="ld-contact">
      <?php if ($p['phone']): ?><span><i class="fas fa-phone"></i> <?= e($p['phone']) ?></span><?php endif; ?>
      <?php if ($p['email']): ?><span class="ld-email"><i class="fas fa-envelope"></i> <?= e($p['email']) ?></span><?php endif; ?>
      <?php if (!$p['phone']): ?><span class="ld-warn"><i class="fas fa-triangle-exclamation"></i> No phone number — email them for one</span><?php endif; ?>
    </div>

    <?php if ($p['note'] || $p['outcome'] || $p['call_back_at'] || $hist): ?>
      <div class="ld-progress">
        <?php if ($p['outcome']): ?><span><i class="fas fa-phone"></i> <?= e(LEAD_OUTCOMES[$p['outcome']][0]) ?><?= $p['calls'] > 1 ? ' · ' . $p['calls'] . ' calls' : '' ?></span><?php endif; ?>
        <?php if ($p['call_back_at'] && !in_array($p['stage'], ['won', 'lost'], true)): ?><span class="<?= $due ? 'ld-due-text' : '' ?>"><i class="fas fa-bell"></i> Call back <?= e(date('D d M', strtotime($p['call_back_at']))) ?></span><?php endif; ?>
        <?php foreach (array_slice($hist, 0, 3) as $h): ?>
          <span class="<?= $h['ok'] ? '' : 'ld-due-text' ?>"><i class="<?= $h['channel'] === 'whatsapp' ? 'fab fa-whatsapp' : 'fas fa-envelope' ?>"></i>
            <?= $h['channel'] === 'email' ? 'Emailed' : 'WhatsApp' ?> <?= e($h['template'] ? ($h['channel'] === 'email' ? ($ldEmailTpl[$h['template']]['label'] ?? $h['template']) : ($ldWaTpl[$h['template']]['label'] ?? $h['template'])) : '') ?>
            · <?= leadTimeAgo($h['created_at']) ?><?= $h['ok'] ? '' : ' (failed)' ?></span>
        <?php endforeach; ?>
        <?php if ($p['note']): ?><p class="ld-note"><i class="fas fa-note-sticky"></i> <?= nl2br(e($p['note'])) ?></p><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="ld-actions">
      <?php if ($p['phone']): ?>
        <a class="ld-btn ld-btn-call" href="tel:<?= e(preg_replace('/[^\d+]/', '', $p['phone'])) ?>" data-act="call"><i class="fas fa-phone"></i><span>Call</span></a>
      <?php endif; ?>
      <?php if ($p['wa']): ?>
        <button type="button" class="ld-btn ld-btn-wa" data-act="wa"><i class="fab fa-whatsapp"></i><span>WhatsApp</span></button>
      <?php endif; ?>
      <?php if ($p['email']): ?>
        <button type="button" class="ld-btn ld-btn-email" data-act="email"><i class="fas fa-envelope"></i><span>Email</span></button>
      <?php endif; ?>
      <button type="button" class="ld-btn ld-btn-log" data-act="log"><i class="fas fa-clipboard-check"></i><span>Log call</span></button>
      <?php if ($p['user_id']): ?>
        <a class="ld-btn" href="?tab=users&usearch=<?= urlencode((string)($p['email'] ?: $p['phone'])) ?>"><i class="fas fa-user"></i><span>Account</span></a>
      <?php endif; ?>
    </div>

    <details class="ld-script">
      <summary><i class="fas fa-headset"></i> Call script — <?= e($ldScripts[$p['type']]['title']) ?></summary>
      <ol>
        <?php foreach ($ldScripts[$p['type']]['steps'] as [$step, $say]): ?>
          <li><strong><?= e($step) ?>:</strong> <?= e(strtr($say, ['[Name]' => leadPersonVars($p)['{name}'], '[Agent]' => leadAgentName(), '[Vehicle]' => $p['vehicle'] ?: 'a vehicle', '[Criteria]' => $p['criteria'] ?: 'a vehicle', '[Deposit]' => $ldCommon['{deposit}']])) ?></li>
        <?php endforeach; ?>
      </ol>
    </details>
  </article>
<?php endforeach; ?>
</div>
<p class="ld-none text-muted" id="ldNone" hidden>No leads match this filter.</p>

<details class="card ld-answers">
  <summary><i class="fas fa-circle-question"></i> Quick answers for every call</summary>
  <dl>
    <?php foreach (LEAD_CALL_ANSWERS as [$q, $a]): ?><dt><?= e($q) ?></dt><dd><?= e($a) ?></dd><?php endforeach; ?>
  </dl>
</details>

<!-- ── WhatsApp / Email / Log call sheet ── -->
<dialog class="ld-sheet" id="ldSheet" aria-labelledby="ldSheetTitle">
  <form method="dialog" class="ld-sheet-inner" id="ldSheetForm">
    <header class="ld-sheet-head">
      <h2 id="ldSheetTitle">Message</h2>
      <button type="button" class="ld-x" aria-label="Close" onclick="ldClose()">&times;</button>
    </header>
    <p class="ld-sheet-to" id="ldSheetTo"></p>

    <div class="ld-pane" data-pane="wa">
      <label class="form-group">Template
        <select class="form-control" id="ldWaTpl">
          <?php foreach ($ldWaTpl as $k => $t): ?><option value="<?= $k ?>"><?= e($t['label']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="form-group">Message <small class="text-muted">— edit before sending</small>
        <textarea class="form-control" id="ldWaText" rows="11"></textarea>
      </label>
      <div class="ld-sheet-actions">
        <button type="button" class="btn btn-primary ld-wa-go" id="ldWaSend"><i class="fab fa-whatsapp"></i> Open WhatsApp</button>
        <button type="button" class="btn btn-outline" id="ldWaCopy"><i class="fas fa-copy"></i> Copy</button>
      </div>
    </div>

    <div class="ld-pane" data-pane="email">
      <label class="form-group">Template
        <select class="form-control" id="ldEmTpl">
          <?php foreach ($ldEmailTpl as $k => $t): ?><option value="<?= $k ?>"><?= e($t['label']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="form-group">Subject <input class="form-control" id="ldEmSubject" maxlength="255"></label>
      <label class="form-group">Message <small class="text-muted">— <code>{bank_details}</code> becomes the banking table</small>
        <textarea class="form-control" id="ldEmBody" rows="12"></textarea>
      </label>
      <div class="ld-sheet-actions">
        <button type="button" class="btn btn-primary" id="ldEmSend"><i class="fas fa-paper-plane"></i> Send email</button>
        <button type="button" class="btn btn-outline" id="ldEmPreview"><i class="fas fa-eye"></i> Preview</button>
      </div>
      <iframe class="ld-preview" id="ldEmFrame" title="Email preview" hidden></iframe>
    </div>

    <div class="ld-pane" data-pane="log">
      <label class="form-group">How did the call go?
        <select class="form-control" id="ldOutcome">
          <option value="">— choose —</option>
          <?php foreach (LEAD_OUTCOMES as $k => [$label]): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="form-group">Call back on <input type="date" class="form-control" id="ldCallBack" min="<?= $today ?>"></label>
      <label class="form-group">Notes <textarea class="form-control" id="ldNote" rows="4" placeholder="What they want, budget, cash or finance, best time to call…"></textarea></label>
      <label class="form-group">Stage
        <select class="form-control" id="ldStage">
          <?php foreach (LEAD_STAGES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
        </select>
      </label>
      <div class="ld-sheet-actions">
        <button type="button" class="btn btn-primary" id="ldLogSave"><i class="fas fa-check"></i> Save</button>
      </div>
    </div>
    <div class="ld-sheet-msg" id="ldSheetMsg" role="status" aria-live="polite" hidden></div>
  </form>
</dialog>

<script>
(function () {
  var PEOPLE = <?= json_encode($ldJs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var COMMON = <?= json_encode($ldCommon, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var WA_TPL = <?= json_encode(array_map(fn($t) => $t['body'], $ldWaTpl), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var EM_TPL = <?= json_encode(array_map(fn($t) => ['s' => $t['subject'], 'b' => $t['body']], $ldEmailTpl), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var WA_FOR = <?= json_encode(LEAD_WA_FOR_TYPE) ?>, EM_FOR = <?= json_encode(LEAD_EMAIL_FOR_TYPE) ?>;
  var OUTCOME_STAGE = <?= json_encode(array_map(fn($o) => $o[1], LEAD_OUTCOMES)) ?>;
  var sheet = document.getElementById('ldSheet'), cur = null, $ = function (id) { return document.getElementById(id); };

  function fill(text, p, keepBank) {
    var vars = Object.assign({}, COMMON, p.vars);
    if (keepBank) delete vars['{bank_details}'];
    return String(text || '').replace(/\{[a-z_]+\}/g, function (m) { return vars.hasOwnProperty(m) ? vars[m] : m; }).replace(/\n{3,}/g, '\n\n').trim();
  }
  function post(data) {
    var fd = new FormData(); Object.keys(data).forEach(function (k) { if (data[k] !== null && data[k] !== undefined) fd.append(k, data[k]); });
    return fetch('leads_api.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function msg(text, ok) { var m = $('ldSheetMsg'); m.hidden = !text; m.textContent = text || ''; m.className = 'ld-sheet-msg ' + (ok ? 'ok' : 'err'); }
  window.ldClose = function () { if (sheet.open) sheet.close(); };

  function open(pane, key) {
    cur = key; var p = PEOPLE[key]; msg('');
    sheet.querySelectorAll('.ld-pane').forEach(function (el) { el.hidden = el.dataset.pane !== pane; });
    $('ldSheetTitle').textContent = { wa: 'WhatsApp ', email: 'Email ', log: 'Log call — ' }[pane] + p.name;
    $('ldSheetTo').textContent = pane === 'wa' ? '+' + p.wa : pane === 'email' ? p.email : (p.phone || '');
    if (pane === 'wa') { $('ldWaTpl').value = WA_FOR[p.type] || 'follow_up'; $('ldWaText').value = fill(WA_TPL[$('ldWaTpl').value], p); }
    if (pane === 'email') { $('ldEmTpl').value = EM_FOR[p.type] || 'follow_up'; setEmail(); $('ldEmFrame').hidden = true; }
    if (pane === 'log') { $('ldOutcome').value = ''; $('ldCallBack').value = p.call_back_at || ''; $('ldNote').value = p.note || ''; $('ldStage').value = p.stage === 'new' || p.stage === 'contacted' ? 'called' : p.stage; }
    if (sheet.showModal) sheet.showModal(); else sheet.setAttribute('open', '');
  }
  function setEmail() { var t = EM_TPL[$('ldEmTpl').value], p = PEOPLE[cur]; $('ldEmSubject').value = fill(t.s, p, true); $('ldEmBody').value = fill(t.b, p, true); }

  document.getElementById('ldList').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-act]'); if (!b) return;
    var key = b.closest('.ld-card').dataset.key, act = b.dataset.act;
    if (act === 'call') { setTimeout(function () { open('log', key); }, 400); return; } // phone app opens, the log sheet waits
    ev.preventDefault(); open(act, key);
  });
  $('ldWaTpl').addEventListener('change', function () { $('ldWaText').value = fill(WA_TPL[this.value], PEOPLE[cur]); });
  $('ldEmTpl').addEventListener('change', setEmail);
  $('ldOutcome').addEventListener('change', function () { if (OUTCOME_STAGE[this.value]) $('ldStage').value = OUTCOME_STAGE[this.value]; });

  $('ldWaSend').addEventListener('click', function () {
    var p = PEOPLE[cur];
    window.open('https://wa.me/' + p.wa + '?text=' + encodeURIComponent($('ldWaText').value), '_blank', 'noopener');
    post({ action: 'wa_log', key: cur, template: $('ldWaTpl').value, to: p.wa }).then(function () { markCard(cur, 'contacted'); });
    msg('WhatsApp opened — send it there. Logged as contacted.', true);
  });
  $('ldWaCopy').addEventListener('click', function () {
    navigator.clipboard && navigator.clipboard.writeText($('ldWaText').value).then(function () { msg('Copied.', true); });
  });
  $('ldEmPreview').addEventListener('click', function () {
    var p = PEOPLE[cur];
    post({ action: 'preview', subject: $('ldEmSubject').value, body: $('ldEmBody').value, name: p.name, user_id: p.user_id || '' }).then(function (d) {
      var f = $('ldEmFrame'); f.hidden = false; f.srcdoc = d.html || '';
    });
  });
  $('ldEmSend').addEventListener('click', function () {
    var p = PEOPLE[cur], btn = this; btn.disabled = true; msg('Sending…', true);
    post({ action: 'email', key: cur, to: p.email, name: p.name, user_id: p.user_id || '', template: $('ldEmTpl').value, subject: $('ldEmSubject').value, body: $('ldEmBody').value })
      .then(function (d) { msg(d.message, d.success); if (d.success) markCard(cur, 'contacted'); })
      .catch(function () { msg('Could not reach the server.', false); })
      .finally(function () { btn.disabled = false; });
  });
  $('ldLogSave').addEventListener('click', function () {
    var btn = this; btn.disabled = true;
    post({ action: 'status', key: cur, outcome: $('ldOutcome').value, call_back_at: $('ldCallBack').value, note: $('ldNote').value, stage: $('ldStage').value, called: $('ldOutcome').value ? 1 : '' })
      .then(function (d) {
        msg(d.message, d.success);
        if (d.success) { var p = PEOPLE[cur]; p.note = $('ldNote').value; p.call_back_at = $('ldCallBack').value; markCard(cur, $('ldStage').value, true); setTimeout(ldClose, 600); }
      })
      .finally(function () { btn.disabled = false; });
  });

  var STAGE_LABEL = <?= json_encode(LEAD_STAGES) ?>;
  function markCard(key, stage, force) {
    var p = PEOPLE[key]; if (!force && p.stage !== 'new') return;
    p.stage = stage;
    var card = document.querySelector('.ld-card[data-key="' + CSS.escape(key) + '"]'); if (!card) return;
    card.dataset.stage = stage; card.className = card.className.replace(/ld-stage-\w+/, 'ld-stage-' + stage);
    card.querySelector('.ld-stage-pill').lastChild.textContent = ' ' + STAGE_LABEL[stage];
  }

  // Filters
  var stageFilter = 'open';
  function apply() {
    var q = $('ldSearch').value.trim().toLowerCase(), type = $('ldType').value, shown = 0;
    document.querySelectorAll('.ld-card').forEach(function (c) {
      var s = c.dataset.stage, ok =
        (stageFilter === 'all' || (stageFilter === 'open' ? ['new', 'contacted', 'called'].indexOf(s) > -1 : stageFilter === 'due' ? c.dataset.due === '1' : s === stageFilter))
        && (!type || c.dataset.type === type) && (!q || c.dataset.hay.indexOf(q) > -1);
      c.hidden = !ok; if (ok) shown++;
    });
    $('ldNone').hidden = shown > 0 || !document.querySelector('.ld-card');
  }
  document.querySelectorAll('.ld-chip').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.ld-chip').forEach(function (x) { x.classList.remove('active'); });
      b.classList.add('active'); stageFilter = b.dataset.stage; apply();
    });
  });
  $('ldSearch').addEventListener('input', apply);
  $('ldType').addEventListener('change', apply);
  apply();
})();
</script>
