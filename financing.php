<?php
// ============================================================
// AUCTION HUB GROUP — financing.php
// Vehicle Finance: how we assess you (score out of 100, grades
// A–D, start-up deposit), a "Do I qualify?" pre-check (finance_check.php,
// same rules as the real assessment), a repayment calculator, free credit
// score checks, documents, key terms and FAQ → finance_apply.php.
// All figures come from Admin → Finance & Pre-Auction → Settings.
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/finance_helper.php';

$rules   = finRules();
$minStd  = (float)finSetting('fin_min_deposit', '10500');
$minHvy  = (float)finSetting('fin_min_deposit_heavy', '50000');
$tiers   = finTiers();
$tiersH  = finTiers(['body_type' => 'truck']);
$maxTerm = max(array_keys($tiersH));
$lots    = [];
try { $lots = finEligibleLots(); } catch (Throwable $e) { /* table not ready: the price field still works */ }
$R = fn($n) => formatRand((float)$n);

$pageTitle       = 'Vehicle Finance — Check If You Qualify';
$pageDescription = 'Auction Hub Group vehicle finance: see your finance score in minutes. Strong score? Approved from ' . formatRand($minStd) . ' deposit. Low score or blacklisted? Qualify with a start-up deposit. Terms 6–' . $maxTerm . ' months.';
$bodyClass       = 'page-financing';
require_once __DIR__ . '/header.php';
require __DIR__ . '/fin_ui.php';

finHero('<i class="fas fa-hand-holding-dollar"></i> Vehicle Finance', 'Finance Your Next Vehicle With Us',
    'One application, an instant <strong style="color:#fff">finance score out of 100</strong> and a clear answer. A strong score is approved from ' . e($R($minStd)) . ' deposit — a low score, debt review or blacklisting can still qualify with a <strong style="color:#fff">start-up deposit</strong>.',
    'Finance',
    ['Instant score & quote', 'From ' . e($R($minStd)) . ' deposit', 'Terms 6–' . $maxTerm . ' months', 'Blacklisted? Ask about a start-up deposit']);
?>
<style>
.fp-cta { display:flex; gap:.75rem; justify-content:center; flex-wrap:wrap; margin:-1.6rem auto 0; position:relative; z-index:2; }
.fp-cta .btn { box-shadow:0 8px 24px rgba(0,40,100,.25); }
.fp-block { margin-bottom:2.75rem; }
.fp-head { text-align:center; max-width:760px; margin:0 auto 1.5rem; }
.fp-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(230px,100%),1fr)); gap:1rem; }
.fp-card { background:#fff; border-radius:14px; padding:1.4rem; box-shadow:0 2px 16px rgba(0,0,0,.06); border-top:4px solid var(--c,#005BA1); }
.fp-card .ic { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; background:var(--c,#005BA1); color:#fff; margin-bottom:.8rem; font-size:1.1rem; }
.fp-card h3 { font-family:'Barlow Condensed',sans-serif; font-size:1.25rem; color:#003f73; margin:0 0 .35rem; }
.fp-card p { font-size:.88rem; color:#556; line-height:1.65; margin:0; }
.fp-grades { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(220px,100%),1fr)); gap:1rem; margin-top:1.25rem; }
.fp-grade { background:#fff; border-radius:14px; padding:1.2rem; box-shadow:0 2px 16px rgba(0,0,0,.06); display:flex; gap:.9rem; align-items:flex-start; }
.fp-grade .g { width:52px; height:52px; border-radius:50%; color:#fff; font-family:'Barlow Condensed',sans-serif; font-size:1.7rem; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.fp-grade strong { display:block; color:#003f73; }
.fp-grade small { color:#667; font-size:.82rem; line-height:1.55; display:block; margin-top:.15rem; }
.fp-weights { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:.5rem; margin-top:1.25rem; }
.fp-weights div { background:#fff; border-radius:12px; padding:.9rem .5rem; text-align:center; box-shadow:0 2px 12px rgba(0,0,0,.05); }
.fp-weights b { display:block; font-family:'Barlow Condensed',sans-serif; font-size:1.8rem; color:#005BA1; line-height:1; }
.fp-weights span { font-size:.75rem; color:#556; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
@media(max-width:700px){ .fp-weights { grid-template-columns:repeat(2,minmax(0,1fr)); } }
.fp-calc table { width:100%; border-collapse:collapse; font-size:.9rem; }
.fp-calc th { background:#003f73; color:#fff; padding:.65rem .8rem; text-align:left; font-size:.75rem; text-transform:uppercase; letter-spacing:.06em; }
.fp-calc td { padding:.65rem .8rem; border-bottom:1px solid #eef2f7; }
.fp-calc td.m { font-family:'Barlow Condensed',sans-serif; font-size:1.2rem; font-weight:800; color:#1a9e4e; }
.fp-bands { width:100%; border-collapse:collapse; font-size:.86rem; }
.fp-bands td { padding:.5rem .6rem; border-bottom:1px solid #eef2f7; }
.fp-bands td:first-child { width:12px; padding:0; }
</style>

<div class="ap-container fp-cta">
  <a class="btn btn-primary btn-lg" href="#qualify"><i class="fas fa-gauge-high"></i> Do I Qualify?</a>
  <a class="btn btn-outline btn-lg" href="#credit-score" style="background:#fff"><i class="fas fa-magnifying-glass-chart"></i> Check My Credit Score</a>
  <a class="btn btn-success btn-lg" href="<?= APP_URL ?>/finance_apply.php"><i class="fas fa-file-signature"></i> Apply Now</a>
</div>

<section class="ap-section">
<div class="ap-container">

  <!-- ═══ HOW WE ASSESS ═══ -->
  <div class="fp-block">
    <div class="fp-head">
      <span class="ap-label"><i class="fas fa-scale-balanced"></i> How Approval Works</span>
      <h2 class="ap-title">A Real Assessment — and a Clear Answer</h2>
      <p class="ap-sub">We check every application for affordability and credit, as responsible lending requires. You get a score out of 100 the moment you apply. Your score decides the deposit you need — not whether you are welcome.</p>
    </div>
    <div class="fp-weights" aria-label="How the score is made up">
      <div><b>30</b><span>Affordability</span></div><div><b>25</b><span>Credit profile</span></div><div><b>20</b><span>Employment</span></div><div><b>15</b><span>Deposit</span></div><div><b>10</b><span>Stability</span></div>
    </div>
    <div class="fp-grades">
      <?php foreach ([['A', (int)$rules['score_a'] . ' – 100', 'Approved in principle', 'Standard minimum deposit of ' . $R($minStd) . ' (' . $R($minHvy) . ' on trucks & plant). Needs a credit score of ' . (int)$rules['min_credit'] . ' or more (TransUnion "Favourable" or better).'],
                      ['B', (int)$rules['score_b'] . ' – ' . ((int)$rules['score_a'] - 1), 'Start-up deposit', 'Approved with a start-up deposit from ' . (int)$rules['startup_b'] . '% of the price.'],
                      ['C', (int)$rules['score_c'] . ' – ' . ((int)$rules['score_b'] - 1), 'Start-up deposit', 'Approved with a start-up deposit from ' . (int)$rules['startup_c'] . '% of the price.'],
                      ['D', '0 – ' . ((int)$rules['score_c'] - 1), 'Larger start-up deposit', 'Approved with a start-up deposit from ' . (int)$rules['startup_d'] . '% of the price.']] as [$g, $range, $t, $d]): ?>
        <div class="fp-grade"><span class="g" style="background:<?= finGradeColour($g) ?>"><?= $g ?></span><div><strong><?= e($t) ?> <span style="font-weight:500;color:#889">· score <?= e($range) ?></span></strong><small><?= e($d) ?></small></div></div>
      <?php endforeach; ?>
    </div>
    <div class="ap-notice"><i class="fas fa-circle-info"></i><div><strong>Every grade must also be affordable.</strong> Your instalment may be at most <?= (int)$rules['afford_pct'] ?>% of your take-home pay and must fit what is left after your expenses. If it does not, the start-up deposit is raised until it does — so you never take on more than you can carry. The start-up deposit is part of the price, never an extra fee.</div></div>
  </div>

  <!-- ═══ DO I QUALIFY? ═══ -->
  <div class="fp-block" id="qualify">
    <div class="fp-head">
      <span class="ap-label"><i class="fas fa-gauge-high"></i> 2-Minute Pre-Check</span>
      <h2 class="ap-title">Do I Qualify?</h2>
      <p class="ap-sub">The same rules our finance team uses. Nothing is saved and there is no credit enquiry — it is just your figures.</p>
    </div>
    <div class="ap-grid">
      <form class="ap-card" id="pc" novalidate>
        <div class="ap-card-head"><i class="fas fa-calculator"></i> Your Figures</div>
        <div class="ap-card-body">
          <div class="ap-form">
            <div class="full form-group"><label class="form-label" for="pc-vehicle">Vehicle</label>
              <select class="form-control" id="pc-vehicle" name="auction_id">
                <option value="">Any vehicle — I will enter a price</option>
                <?php foreach ($lots as $l): ?><option value="<?= (int)$l['id'] ?>" data-price="<?= (int)round(finPrice($l)) ?>" data-min="<?= (int)finMinDeposit($l) ?>"><?= e(finVehicleTitle($l)) ?> — <?= e($R(finPrice($l))) ?></option><?php endforeach; ?>
              </select></div>
            <div class="form-group" id="pc-price-wrap"><label class="form-label" for="pc-price">Vehicle price (R)</label>
              <input class="form-control" id="pc-price" name="price" inputmode="decimal" value="150000"></div>
            <div class="form-group"><label class="form-label" for="pc-deposit">Deposit you can pay (R)</label>
              <input class="form-control" id="pc-deposit" name="deposit" inputmode="decimal" value="<?= (int)$minStd ?>"></div>
            <div class="form-group"><label class="form-label" for="pc-net">Take-home pay (R / month)</label>
              <input class="form-control" id="pc-net" name="net_income" inputmode="decimal" required placeholder="After tax & deductions"></div>
            <div class="form-group"><label class="form-label" for="pc-other">Other income (R / month)</label>
              <input class="form-control" id="pc-other" name="other_income" inputmode="decimal" value="0"></div>
            <div class="form-group"><label class="form-label" for="pc-housing">Rent / bond</label>
              <input class="form-control" id="pc-housing" name="exp_housing" inputmode="decimal" value="0"></div>
            <div class="form-group"><label class="form-label" for="pc-living">Living costs</label>
              <input class="form-control" id="pc-living" name="exp_living" inputmode="decimal" required placeholder="Food, transport, school"></div>
            <div class="form-group"><label class="form-label" for="pc-debt">Loan &amp; account repayments</label>
              <input class="form-control" id="pc-debt" name="exp_debt" inputmode="decimal" value="0"></div>
            <div class="form-group"><label class="form-label" for="pc-deps">Dependants</label>
              <select class="form-control" id="pc-deps" name="dependants"><?php for ($i = 0; $i <= 10; $i++): ?><option><?= $i ?></option><?php endfor; ?></select></div>
            <div class="form-group"><label class="form-label" for="pc-emp">Employment</label>
              <select class="form-control" id="pc-emp" name="employment"><?php foreach (FIN_EMPLOYMENT as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label" for="pc-years">Years in this job</label>
              <select class="form-control" id="pc-years" name="emp_years"><?php for ($i = 0; $i <= 30; $i++): ?><option value="<?= $i ?>"<?= $i === 2 ? ' selected' : '' ?>><?= $i ?><?= $i === 30 ? '+' : '' ?></option><?php endfor; ?></select></div>
            <div class="form-group"><label class="form-label" for="pc-band">Your credit score</label>
              <span class="ap-help" style="display:block;margin:-.2rem 0 .3rem">Don't know it? <a href="#credit-score">Check it free</a> — it takes a few minutes.</span>
              <select class="form-control" id="pc-band" name="credit_band"><?php foreach (FIN_CREDIT_BANDS as $k => $b): ?><option value="<?= $k ?>"<?= $k === 'unknown' ? ' selected' : '' ?>><?= e($b[0]) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label" for="pc-res">Where you live</label>
              <select class="form-control" id="pc-res" name="residence"><?php foreach (FIN_RESIDENCE as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="ap-check"><input type="checkbox" name="debt_review" value="yes"><span>I am under debt review</span></label>
              <label class="ap-check"><input type="checkbox" name="judgments" value="yes"><span>I have judgments / I am blacklisted</span></label></div>
          </div>
          <input type="hidden" name="years_address" value="2"><input type="hidden" name="bank_years" value="2">
          <button class="btn btn-primary btn-lg btn-block" type="submit" id="pc-go"><i class="fas fa-gauge-high"></i> Check My Result</button>
          <p class="ap-help" style="text-align:center">The full application also looks at time at your address and bank — your final score may differ slightly.</p>
        </div>
      </form>
      <div>
        <div class="ap-card" id="pc-result-card">
          <div class="ap-card-head green"><i class="fas fa-chart-simple"></i> Your Result</div>
          <div class="ap-card-body" id="pc-result">
            <p class="ap-help" style="margin:0"><i class="fas fa-arrow-left"></i> Enter your figures and press <strong>Check My Result</strong> to see your score, the deposit you need and the terms that fit your budget.</p>
          </div>
        </div>
        <a class="btn btn-success btn-lg btn-block" id="pc-apply" href="<?= APP_URL ?>/finance_apply.php" style="display:none"><i class="fas fa-file-signature"></i> Continue to My Full Application</a>
      </div>
    </div>
  </div>

  <!-- ═══ CALCULATOR ═══ -->
  <div class="fp-block">
    <div class="fp-head">
      <span class="ap-label"><i class="fas fa-calculator"></i> Repayment Calculator</span>
      <h2 class="ap-title">What Would My Instalment Be?</h2>
    </div>
    <div class="ap-card fp-calc">
      <div class="ap-card-body">
        <div class="ap-form" style="grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr))">
          <div class="form-group"><label class="form-label" for="fc-price">Vehicle price (R)</label><input class="form-control" id="fc-price" inputmode="decimal" value="150000"></div>
          <div class="form-group"><label class="form-label" for="fc-dep">Deposit (R)</label><input class="form-control" id="fc-dep" inputmode="decimal" value="<?= (int)$minStd ?>"></div>
          <div class="form-group"><label class="form-label" for="fc-type">Vehicle type</label><select class="form-control" id="fc-type"><option value="car">Car / bakkie (to <?= max(array_keys($tiers)) ?> months)</option><option value="heavy">Truck / plant (to <?= $maxTerm ?> months)</option></select></div>
        </div>
        <div style="overflow-x:auto"><table><thead><tr><th>Term</th><th>Rate p/m</th><th>Monthly</th><th>Total repayable</th></tr></thead><tbody id="fc-body"></tbody></table></div>
        <p class="ap-help">Financed amount × (1 + monthly rate × months) ÷ months. Indicative — your quote confirms it. Which terms you can take depends on your assessment.</p>
      </div>
    </div>
  </div>

  <!-- ═══ CREDIT SCORE ═══ -->
  <div class="fp-block" id="credit-score">
    <div class="ap-grid">
      <div class="ap-card">
        <div class="ap-card-head"><i class="fas fa-magnifying-glass-chart"></i> Check Your Credit Score — Free</div>
        <div class="ap-card-body">
          <p class="ap-sub" style="margin-bottom:.5rem">Under the National Credit Act you are entitled to <strong>one free credit report a year from each credit bureau</strong>. Checking your own score never lowers it. Know your score before you apply — and put the exact figure on your application.</p>
          <?= finScoreCheckLinks() ?>
          <p class="ap-help">When you apply you give consent for us to check your credit with a registered bureau. A bureau score replaces your own estimate and can raise or lower your result.</p>
        </div>
      </div>
      <div class="ap-card">
        <div class="ap-card-head gold"><i class="fas fa-ranking-star"></i> The Credit Score You Need</div>
        <div class="ap-card-body">
          <p class="ap-help" style="margin:0 0 .7rem">Scores on TransUnion's 0–999 scale. For <strong>standard approval</strong> (grade A, <?= e($R($minStd)) ?> deposit) you need <strong><?= (int)$rules['min_credit'] ?> or more</strong>. Below that you can still qualify — with a start-up deposit.</p>
          <table class="fp-bands">
            <?php foreach (FIN_CREDIT_BANDS as $k => [$label, $pts, $from]): if ($k === 'unknown') continue; $ok = $from >= $rules['min_credit']; ?>
              <tr><td style="background:<?= ['excellent' => '#1a9e4e', 'good' => '#4caf50', 'favourable' => '#8bc34a', 'average' => '#e8a000', 'below_average' => '#ef6c00', 'unfavourable' => '#e53935', 'poor' => '#a30000'][$k] ?>"></td>
                <td><strong><?= e($label) ?></strong><br><small style="color:<?= $ok ? '#1a9e4e' : '#a35a00' ?>"><?= $ok ? 'Standard approval possible' : 'Start-up deposit needed' ?></small></td>
                <td style="text-align:right"><?= $pts ?> / 25 pts</td></tr>
            <?php endforeach; ?>
            <tr><td style="background:#5a0000"></td><td>Debt review / judgments</td><td style="text-align:right">− 8 / − 6 pts</td></tr>
          </table>
          <p class="ap-help" style="margin-top:.6rem">Experian, XDS and Compuscan use their own scales, so the same person can score differently at each bureau. We assess on the bureau report we pull with your consent.</p>
          <p class="ap-help" style="margin-top:.8rem"><strong>Improve your result:</strong> a bigger deposit (up to 15 points), paying down store cards and loans (frees up budget), and keeping accounts up to date.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ PROCESS ═══ -->
  <div class="fp-block">
    <div class="fp-head">
      <span class="ap-label"><i class="fas fa-route"></i> From Application to Driving Away</span>
      <h2 class="ap-title">Six Steps, All Online Until You Collect</h2>
    </div>
    <div class="fp-cards">
      <?php foreach ([['fa-file-signature', '#005BA1', '1. Apply & get your score', 'Complete the application — vehicle, details, income, expenses and credit. Your score, grade and quote appear instantly.'],
                      ['fa-calendar-days', '#0078d7', '2. Choose your term', 'Pick any repayment term that fits your budget, from 6 to ' . $maxTerm . ' months.'],
                      ['fa-file-arrow-up', '#8b5cf6', '3. Documents & credit check', 'Upload your documents on your application page. With your consent we confirm your score with a credit bureau.'],
                      ['fa-coins', '#e8a000', '4. Pay your (start-up) deposit', 'Your deposit invoice comes by email. Once it reflects, your finance is approved and the vehicle is reserved.'],
                      ['fa-id-badge', '#25D366', '5. Buyer Code & test drive', 'Your Buyer Code is your access pass. Book your test drive at head office.'],
                      ['fa-key', '#ef4444', '6. Sign & drive away', 'Sign your instalment sale agreement, set up your debit order and drive away.']] as [$ic, $c, $t, $d]): ?>
        <div class="fp-card" style="--c:<?= $c ?>"><div class="ic"><i class="fas <?= $ic ?>"></i></div><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ═══ DOCUMENTS + TERMS ═══ -->
  <div class="fp-block">
    <div class="ap-grid">
      <div class="ap-card">
        <div class="ap-card-head"><i class="fas fa-folder-open"></i> Documents You Need</div>
        <div class="ap-card-body">
          <ol class="ap-mini-steps">
            <?php foreach (FIN_DOCS as $k => [$label, $hint]): if ($k === 'payment') continue; ?><li><strong><?= e($label) ?></strong> — <?= e($hint) ?></li><?php endforeach; ?>
            <li><strong>Proof of deposit payment</strong> — after your deposit invoice</li>
          </ol>
        </div>
      </div>
      <div class="ap-card">
        <div class="ap-card-head gold"><i class="fas fa-file-contract"></i> Key Terms</div>
        <div class="ap-card-body">
          <ul style="margin:0;padding-left:1.1rem;font-size:.87rem;color:#445;line-height:1.75">
            <li>Every application is assessed for affordability and credit (National Credit Act).</li>
            <li>Approval may require a start-up deposit — it is part of the price, not a fee.</li>
            <li>Instalment at most <?= (int)$rules['afford_pct'] ?>% of take-home pay; only terms that fit your budget are offered.</li>
            <li>Quotes are valid 7 days, while the vehicle has no auction bid.</li>
            <li>Instalment sale: the vehicle is ours until paid in full; comprehensive insurance is required.</li>
            <li>Debit order on your pay day. Settle early any time.</li>
            <li>Your information is protected under POPIA.</li>
          </ul>
          <p class="ap-help">The full terms are shown in the application and in your signed agreement.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ FAQ ═══ -->
  <div class="fp-block ap-faq" style="max-width:860px;margin-left:auto;margin-right:auto">
    <div class="fp-head"><span class="ap-label"><i class="fas fa-circle-question"></i> Finance FAQ</span></div>
    <?php foreach ([
        ['I am blacklisted / under debt review — can I still get finance?', 'You can apply. Judgments and debt review lower your credit-profile points, which usually means a start-up deposit for approval. The pre-check above shows you the amount before you apply.'],
        ['What is a start-up deposit?', 'When your score is below grade A, or the instalment at the minimum deposit does not fit your budget, approval is conditional on a larger deposit — the start-up deposit. It comes off the price, so you finance less and pay lower instalments.'],
        ['What credit score do I need?', 'For standard approval (grade A, ' . $R($minStd) . ' deposit) you need a score of ' . (int)$rules['min_credit'] . ' or more on TransUnion\'s 0–999 scale ("Favourable" or better), plus a budget that fits the instalment. With a lower score you can still be approved with a start-up deposit.'],
        ['How do I check my credit score?', 'Free, online: TransUnion and Experian give you one free credit report a year, and ClearScore shows a free score updated monthly. Checking your own score never lowers it. Enter the exact number on your application for the most accurate result.'],
        ['Do you check my credit?', 'Yes — with your consent on the application, we confirm your score with a registered credit bureau. That bureau score replaces the estimate you gave us.'],
        ['How is affordability worked out?', 'Take-home pay plus other income, less rent / bond, living costs (at least ' . $R($rules['norm_base']) . ' plus ' . $R($rules['norm_dep']) . ' per dependant), loan repayments and other fixed costs. Your instalment may be at most ' . (int)$rules['afford_pct'] . '% of income and must fit in what is left.'],
        ['Can I improve my result after applying?', 'Yes. Offer a bigger deposit, or send us a better bureau report — our team re-scores your application and emails a new quote.'],
        ['What if the vehicle receives a bid?', 'Finance applies until a vehicle\'s first auction bid. After that it goes to the auction floor — you can register and bid, or choose another vehicle.'],
        ['Is the deposit refundable?', 'Your deposit secures the vehicle once your finance is approved. If we decline your application after you paid, it is refunded in full.'],
    ] as [$q, $a]): ?>
      <details><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details>
    <?php endforeach; ?>
  </div>

  <div class="ap-card" style="text-align:center">
    <div class="ap-card-body" style="padding:2.2rem 1.4rem">
      <h2 class="ap-title">Ready When You Are</h2>
      <p class="ap-sub" style="max-width:620px;margin:0 auto 1.2rem">About 7 minutes online. Your score, start-up deposit (if any) and quote appear instantly — and you can follow every step on your own application page.</p>
      <div class="ap-row" style="justify-content:center">
        <a class="btn btn-success btn-lg" href="<?= APP_URL ?>/finance_apply.php"><i class="fas fa-file-signature"></i> Apply for Finance</a>
        <a class="btn btn-outline btn-lg" href="https://wa.me/<?= e(preg_replace('/\D/', '', COMPANY_WHATSAPP)) ?>?text=<?= rawurlencode('Hi AHG, I have a question about finance.') ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Ask on WhatsApp</a>
      </div>
      <p class="ap-help">Paying cash? <a href="<?= APP_URL ?>/preauction.php">Secure a vehicle before the auction</a>.</p>
    </div>
  </div>

</div>
</section>
<script src="<?= APP_URL ?>/assets/js/fin-result.js"></script>
<script>
(function () {
  var R = function (v) { return 'R' + Math.round(v).toLocaleString('en-ZA', {maximumFractionDigits: 0}); };
  var num = function (el) { return +String(el.value).replace(/[^\d.]/g, '') || 0; };

  // Calculator
  var TIERS = <?= json_encode($tiers) ?>, TIERS_H = <?= json_encode($tiersH) ?>;
  var MIN = <?= (int)$minStd ?>, MIN_H = <?= (int)$minHvy ?>;
  var fp = document.getElementById('fc-price'), fd = document.getElementById('fc-dep'), ft = document.getElementById('fc-type');
  function calc() {
    var heavy = ft.value === 'heavy', t = heavy ? TIERS_H : TIERS, price = num(fp), dep = Math.max(num(fd), heavy ? MIN_H : MIN);
    var fin = Math.max(0, price - dep), h = '';
    Object.keys(t).forEach(function (m) { var mo = fin * (1 + t[m] / 100 * m) / m; h += '<tr><td>' + m + ' months</td><td>' + t[m] + '%</td><td class="m">' + R(mo) + '</td><td>' + R(mo * m) + '</td></tr>'; });
    document.getElementById('fc-body').innerHTML = fin > 0 ? h : '<tr><td colspan="4">Your deposit covers the price.</td></tr>';
  }
  [fp, fd, ft].forEach(function (el) { el.addEventListener('input', calc); el.addEventListener('change', calc); }); calc();

  // Pre-check
  var form = document.getElementById('pc'), veh = document.getElementById('pc-vehicle'), out = document.getElementById('pc-result');
  function vehUi() {
    var o = veh.options[veh.selectedIndex];
    document.getElementById('pc-price-wrap').style.display = veh.value ? 'none' : '';
    if (veh.value && num(document.getElementById('pc-deposit')) < +o.dataset.min) document.getElementById('pc-deposit').value = o.dataset.min;
  }
  veh.addEventListener('change', vehUi); vehUi();
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!num(document.getElementById('pc-net'))) { document.getElementById('pc-net').focus(); out.innerHTML = '<div class="alert alert-warning" style="margin:0"><i class="fas fa-triangle-exclamation"></i><span>Enter your take-home pay.</span></div>'; return; }
    if (!num(document.getElementById('pc-living'))) { document.getElementById('pc-living').focus(); out.innerHTML = '<div class="alert alert-warning" style="margin:0"><i class="fas fa-triangle-exclamation"></i><span>Enter your monthly living costs.</span></div>'; return; }
    var btn = document.getElementById('pc-go'); btn.disabled = true;
    out.innerHTML = '<div class="fr-loading"><i class="fas fa-spinner fa-spin"></i> Working out your result…</div>';
    var data = new FormData(form);
    if (data.get('debt_review') !== 'yes') data.set('debt_review', 'no');
    if (data.get('judgments') !== 'yes') data.set('judgments', 'no');
    fetch('<?= APP_URL ?>/finance_check.php', {method: 'POST', body: data, credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (!d.ok) { out.innerHTML = '<div class="alert alert-warning" style="margin:0"><i class="fas fa-triangle-exclamation"></i><span>' + (d.error || 'Please check your figures.').replace(/</g, '&lt;') + '</span></div>'; return; }
        out.innerHTML = window.ahgFinResult(d, 'Indicative, based on your own figures. The full application, your documents and a credit bureau check confirm it.');
        // Carry the figures to the application (this browser tab only)
        var keep = {}; data.forEach(function (v, k) { if (['price', 'years_address', 'bank_years'].indexOf(k) < 0) keep[k] = v; });
        try { sessionStorage.setItem('ahgFinPrecheck', JSON.stringify(keep)); } catch (x) {}
        var a = document.getElementById('pc-apply');
        a.href = '<?= APP_URL ?>/finance_apply.php' + (veh.value ? '?id=' + encodeURIComponent(veh.value) : '');
        a.style.display = d.grade === 'R' ? 'none' : '';
        document.getElementById('pc-result-card').scrollIntoView({behavior: 'smooth', block: 'start'});
      })
      .catch(function () { btn.disabled = false; out.innerHTML = '<p class="ap-help">We could not check right now — please try again, or go straight to the application.</p>'; });
  });
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
