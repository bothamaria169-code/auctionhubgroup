/* AUCTION HUB GROUP — fin-result.js
 * Draws a finance assessment returned by finance_check.php (score gauge,
 * grade, the five score bars, start-up deposit and which terms fit the
 * budget). Used by financing.php ("Do I qualify?") and finance_apply.php
 * (step 5). Styles: .fr-* in fin_ui.php. */
window.ahgFinResult = function (d, note) {
  var R = function (v) { return 'R' + Math.round(v).toLocaleString('en-ZA', {maximumFractionDigits: 0}); };
  var esc = function (s) { var x = document.createElement('div'); x.textContent = String(s); return x.innerHTML; };
  var h = '<div class="fr-top"><div class="fr-gauge" style="--v:' + d.score + ';--c:' + d.colour + '"><b>' + d.score + '<small>/ 100</small></b></div>'
    + '<div class="fr-head"><span class="fr-grade" style="background:' + d.colour + '">' + (d.grade === 'R' ? 'Referred' : 'Grade ' + d.grade) + '</span><h4>' + esc(d.label) + '</h4>';
  if (d.grade === 'R') h += '<p class="ap-help" style="margin:0">' + d.reasons.map(esc).join('<br>') + '</p>';
  else if (d.startup) h += '<p class="ap-help" style="margin:0">Start-up deposit for approval: <strong style="color:#a30000">' + R(d.deposit) + '</strong> (you entered ' + R(d.offered) + ').</p>';
  else h += '<p class="ap-help" style="margin:0">Deposit <strong>' + R(d.deposit) + '</strong> — no start-up deposit needed.</p>';
  h += '</div></div>';
  d.points.forEach(function (p) {
    h += '<div class="fr-bar"><div class="t"><span>' + esc(p.label) + '</span><span>' + p.pts + ' / ' + p.max + '</span></div>'
      + '<div class="b"><i style="width:' + Math.round(p.pts / p.max * 100) + '%"></i></div><div class="n">' + esc(p.note) + '</div></div>';
  });
  h += '<div style="margin-top:1rem"><div class="ap-rowline"><span class="lbl">Price</span><span class="val">' + R(d.price) + '</span></div>'
    + '<div class="ap-rowline fee"><span class="lbl">' + (d.startup ? 'Start-up deposit' : 'Deposit') + '</span><span class="val">− ' + R(d.deposit) + '</span></div>'
    + '<div class="ap-rowline big"><span class="lbl">Amount financed</span><span class="val">' + R(d.financed) + '</span></div>'
    + '<div class="ap-rowline"><span class="lbl">Most your budget allows per month</span><span class="val">' + R(d.max_instalment) + '</span></div></div>';
  h += '<p class="ap-help" style="margin:.8rem 0 .3rem"><strong>Repayment terms</strong> — the terms that fit your budget are open to you:</p>';
  d.plans.forEach(function (p) {
    h += '<div class="fr-plan ' + (p.affordable ? 'ok' : 'no') + '"><span>' + p.months + ' months' + (p.affordable ? '' : ' — over budget') + '</span><b>' + R(p.monthly) + '<small> /month</small></b></div>';
  });
  if (note) h += '<p class="ap-help" style="margin-top:.8rem"><i class="fas fa-circle-info"></i> ' + note + '</p>';
  return h;
};
