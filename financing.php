<?php
// ============================================================
// AUCTION HUB GROUP — financing.php
// In-House Vehicle Finance — Everyone Qualifies
// ============================================================
$pageTitle       = 'In-House Vehicle Finance — No Credit Checks';
$pageDescription = 'Auction Hub Group offers in-house vehicle finance with no credit checks, no ITC, and no bureau inquiries. Blacklisted? Low credit score? Everyone qualifies. R10,500 deposit.';
$bodyClass       = 'page-financing';
require_once __DIR__ . '/header.php';
?>

<!-- ═══════════ HERO ═══════════ -->
<section style="background:linear-gradient(135deg,#003f73 0%,#005BA1 50%,#0078d7 100%);padding:5rem 1.5rem 4rem;text-align:center;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.05) 1px,transparent 1px);background-size:28px 28px;pointer-events:none;"></div>
    <div class="container" style="position:relative;z-index:1;">
        <div style="display:inline-block;background:rgba(37,211,102,.15);border:1px solid rgba(37,211,102,.4);border-radius:50px;padding:.4rem 1.25rem;margin-bottom:1.25rem;">
            <span style="color:#6eff9e;font-size:.82rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">
                <i class="fas fa-check-circle"></i> Everyone Qualifies
            </span>
        </div>
        <h1 style="color:#fff;font-size:clamp(2.2rem,5vw,3.6rem);line-height:1.1;margin-bottom:1.25rem;">In-House Vehicle Finance</h1>
        <p style="color:rgba(255,255,255,.85);font-size:1.15rem;max-width:680px;margin:0 auto 1.5rem;line-height:1.7;">
            Blacklisted? Low credit score? Rejected by the bank? <strong style="color:#fff;">That's not a problem here.</strong>
            We believe everyone deserves a second chance at driving their own vehicle &mdash; and we back that belief with a finance solution that works <em>for you</em>, not against you.
        </p>
        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:.75rem;margin-bottom:2rem;">
            <span style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:.5rem 1rem;color:#fff;font-size:.85rem;font-weight:600;">
                <i class="fas fa-xmark" style="color:#ff6b6b;margin-right:.3rem;"></i> No Credit Checks
            </span>
            <span style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:.5rem 1rem;color:#fff;font-size:.85rem;font-weight:600;">
                <i class="fas fa-xmark" style="color:#ff6b6b;margin-right:.3rem;"></i> No ITC
            </span>
            <span style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:.5rem 1rem;color:#fff;font-size:.85rem;font-weight:600;">
                <i class="fas fa-xmark" style="color:#ff6b6b;margin-right:.3rem;"></i> No Bureau Inquiries
            </span>
            <span style="background:rgba(37,211,102,.2);border:1px solid rgba(37,211,102,.4);border-radius:8px;padding:.5rem 1rem;color:#6eff9e;font-size:.85rem;font-weight:600;">
                <i class="fas fa-check" style="margin-right:.3rem;"></i> Blacklisted Welcome
            </span>
        </div>
        <nav aria-label="Breadcrumb" style="color:rgba(255,255,255,.5);font-size:.87rem;">
            <a href="<?= APP_URL ?>/index.php" style="color:rgba(255,255,255,.6);text-decoration:none;">Home</a>
            <span style="margin:0 .5rem;">/</span>
            <span style="color:#fff;">In-House Finance</span>
        </nav>
    </div>
</section>

<!-- ═══════════ FULL-WIDTH FINANCE BANNER ═══════════ -->
<div style="width:100%;overflow:hidden;line-height:0;background:#001F5C;">
    <img src="<?= APP_URL ?>/assets/images/finance.jpg"
         alt="In-House Finance — Latest Clients Are Welcome — Auction Hub Group"
         class="ahg-finance-banner"
         style="width:100%;height:auto;display:block;object-fit:cover;object-position:center;"
         loading="lazy">
</div>
<style>
@media (min-width:768px){
  .ahg-finance-banner { height:560px; }
}
</style>

<main style="padding:4rem 1.5rem;background:#f5f7fa;">
<div class="container" style="max-width:1080px;margin:0 auto;">

    <!-- ═══════════ WHO QUALIFIES + DEPOSIT + TERMS GRID ═══════════ -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.5rem;margin-bottom:2.5rem;">

        <!-- WHO QUALIFIES -->
        <div style="background:#fff;border-radius:16px;padding:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);border-top:4px solid #25D366;">
            <div style="width:48px;height:48px;background:linear-gradient(135deg,#25D366,#20bd5a);border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:1.25rem;">
                <i class="fas fa-users" style="color:#fff;font-size:1.2rem;"></i>
            </div>
            <h3 style="color:#003f73;font-size:1.15rem;margin-bottom:1rem;">We Welcome All Clients</h3>
            <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.65rem;">
                <?php
                $clients = [
                    'Blacklisted clients',
                    'Low or no credit score',
                    'Previously declined by banks',
                    'Clients under debt review',
                    'First-time buyers',
                ];
                foreach ($clients as $c): ?>
                <li style="display:flex;gap:.6rem;align-items:center;font-size:.92rem;color:#444;">
                    <i class="fas fa-circle-check" style="color:#25D366;flex-shrink:0;"></i> <?= $c ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- DEPOSIT -->
        <div style="background:linear-gradient(135deg,#005BA1,#003f73);border-radius:16px;padding:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);color:#fff;text-align:center;display:flex;flex-direction:column;justify-content:center;align-items:center;">
            <p style="text-transform:uppercase;letter-spacing:.12em;font-size:.78rem;font-weight:600;color:rgba(255,255,255,.7);margin-bottom:.75rem;">Minimum Deposit</p>
            <div style="font-size:3.2rem;font-weight:800;line-height:1;margin-bottom:.5rem;font-family:'Barlow Condensed',sans-serif;">R10,500</div>
            <p style="color:rgba(255,255,255,.7);font-size:.88rem;margin-bottom:.5rem;">One-time. No credit checks.</p>
            <p style="color:#6eff9e;font-size:.82rem;font-weight:600;margin-bottom:1.25rem;">Pay any amount above R10,500 to reduce your instalments</p>
            <a href="mailto:<?= COMPANY_EMAIL ?>?subject=In-House%20Finance%20Enquiry&body=Hi%20AHG%2C%20I%20am%20interested%20in%20your%20in-house%20vehicle%20finance.%20Please%20send%20me%20more%20details."
               style="background:#005BA1;color:#fff;padding:.75rem 1.75rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.9rem;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="fas fa-envelope"></i> Enquire via Email
            </a>
        </div>

        <!-- FLEXIBLE TERMS -->
        <div style="background:#fff;border-radius:16px;padding:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);border-top:4px solid #e8a000;">
            <div style="width:48px;height:48px;background:linear-gradient(135deg,#e8a000,#f5c842);border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:1.25rem;">
                <i class="fas fa-calendar-check" style="color:#fff;font-size:1.2rem;"></i>
            </div>
            <h3 style="color:#003f73;font-size:1.15rem;margin-bottom:1rem;">Flexible Repayment Plans</h3>
            <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.65rem;">
                <?php
                $plans = [
                    ['6 Months','Lowest total cost'],
                    ['12 Months','Balanced payments'],
                    ['24 Months','Comfortable instalments'],
                    ['36 Months','Lower monthly payments'],
                    ['48 Months','Maximum flexibility'],
                ];
                foreach ($plans as [$term,$label]): ?>
                <li style="display:flex;gap:.6rem;align-items:center;font-size:.92rem;color:#444;">
                    <i class="fas fa-circle-check" style="color:#e8a000;flex-shrink:0;"></i>
                    <span><strong><?= $term ?></strong> &mdash; <?= $label ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- ═══════════ INTERACTIVE FINANCE CALCULATOR ═══════════ -->
    <div id="pva-finance-calc" style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2.5rem;box-shadow:0 2px 20px rgba(0,0,0,.06);border-top:4px solid #005BA1;">
        <h2 style="color:#003f73;font-size:1.5rem;margin-bottom:.35rem;display:flex;align-items:center;gap:.75rem;">
            <i class="fas fa-calculator" style="color:#005BA1;"></i> Finance Calculator
        </h2>
        <p style="color:#777;font-size:.88rem;margin-bottom:2rem;">Enter any vehicle price and deposit to see your estimated monthly instalments across all repayment terms.</p>

        <!-- INPUT ROW -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.25rem;margin-bottom:1.5rem;">
            <!-- Vehicle Price -->
            <div>
                <label for="fc-price" style="display:block;color:#003f73;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem;">Vehicle Price (R)</label>
                <div style="position:relative;">
                    <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#888;font-weight:600;font-size:.95rem;">R</span>
                    <input type="text" id="fc-price" value="60,000" inputmode="numeric"
                        style="width:100%;padding:.75rem .75rem .75rem 28px;border:2px solid #d0d8e4;border-radius:10px;font-size:1.05rem;font-weight:600;color:#003f73;outline:none;transition:border-color .2s;box-sizing:border-box;"
                        onfocus="this.style.borderColor='#005BA1'" onblur="this.style.borderColor='#d0d8e4'">
                </div>
            </div>
            <!-- Deposit -->
            <div>
                <label for="fc-deposit" style="display:block;color:#003f73;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem;">Deposit (R) <span style="font-weight:500;color:#888;text-transform:none;letter-spacing:0;">min R10,500 &mdash; increase to lower instalments</span></label>
                <div style="position:relative;">
                    <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#888;font-weight:600;font-size:.95rem;">R</span>
                    <input type="text" id="fc-deposit" value="10,500" inputmode="numeric"
                        style="width:100%;padding:.75rem .75rem .75rem 28px;border:2px solid #d0d8e4;border-radius:10px;font-size:1.05rem;font-weight:600;color:#003f73;outline:none;transition:border-color .2s;box-sizing:border-box;"
                        onfocus="this.style.borderColor='#005BA1'" onblur="this.style.borderColor='#d0d8e4'">
                </div>
            </div>
            <!-- Financed Amount (read-only) -->
            <div>
                <label style="display:block;color:#003f73;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem;">Amount Financed</label>
                <div id="fc-financed" style="padding:.75rem;border:2px solid #e8f3fc;border-radius:10px;font-size:1.05rem;font-weight:700;color:#005BA1;background:#f0f7ff;box-sizing:border-box;">R49,500</div>
            </div>
        </div>

        <!-- QUICK DEPOSIT BUTTONS -->
        <div style="margin-bottom:1.25rem;">
            <p style="font-size:.78rem;font-weight:700;color:#003f73;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;">
                <i class="fas fa-bolt" style="color:#e8a000;"></i> Quick Deposit — pay more, save more:
            </p>
            <div id="fc-quick-btns" style="display:flex;flex-wrap:wrap;gap:.5rem;">
                <button type="button" onclick="window._fcSetDeposit(10500)" class="fc-quick-btn fc-quick-active"
                    style="padding:.45rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;border:2px solid #005BA1;background:#005BA1;color:#fff;transition:all .2s;">
                    R10,500 <span style="font-weight:400;opacity:.8;">min</span>
                </button>
                <button type="button" onclick="window._fcSetDeposit(15000)" class="fc-quick-btn"
                    style="padding:.45rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;border:2px solid #d0d8e4;background:#fff;color:#003f73;transition:all .2s;">
                    R15,000
                </button>
                <button type="button" onclick="window._fcSetDeposit(20000)" class="fc-quick-btn"
                    style="padding:.45rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;border:2px solid #d0d8e4;background:#fff;color:#003f73;transition:all .2s;">
                    R20,000
                </button>
                <button type="button" onclick="window._fcSetDeposit(25000)" class="fc-quick-btn"
                    style="padding:.45rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;border:2px solid #d0d8e4;background:#fff;color:#003f73;transition:all .2s;">
                    R25,000
                </button>
                <button type="button" onclick="window._fcSetDeposit(30000)" class="fc-quick-btn"
                    style="padding:.45rem 1rem;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;border:2px solid #d0d8e4;background:#fff;color:#003f73;transition:all .2s;">
                    R30,000
                </button>
            </div>
        </div>

        <!-- SAVINGS INDICATOR -->
        <div id="fc-savings" style="display:none;background:linear-gradient(135deg,#e6f9ee,#f0fff5);border:1px solid #b7e2c5;border-radius:10px;padding:.75rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;">
            <div style="width:40px;height:40px;background:#25D366;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-piggy-bank" style="color:#fff;font-size:1rem;"></i>
            </div>
            <div>
                <div style="font-size:.82rem;font-weight:700;color:#155724;">
                    <i class="fas fa-arrow-down"></i> You save <span id="fc-savings-amount" style="color:#25D366;">R0</span>/month compared to minimum deposit
                </div>
                <div style="font-size:.75rem;color:#3d8b5e;" id="fc-savings-total">That's R0 less over the full term</div>
            </div>
        </div>

        <!-- DEPOSIT WARNING -->
        <div id="fc-deposit-warn" style="display:none;background:#fff3cd;border:1px solid #ffe08a;border-radius:8px;padding:.6rem 1rem;margin-bottom:1.25rem;font-size:.84rem;color:#856404;">
            <i class="fas fa-triangle-exclamation"></i> Minimum deposit is <strong>R10,500</strong>. Amount has been adjusted.
        </div>

        <!-- RESULTS TABLE -->
        <div style="overflow-x:auto;">
            <table id="fc-table" style="width:100%;border-collapse:collapse;min-width:560px;">
                <thead>
                    <tr style="background:linear-gradient(135deg,#005BA1,#003f73);">
                        <th style="padding:.85rem 1rem;text-align:left;color:#fff;font-size:.82rem;font-weight:700;letter-spacing:.04em;border-radius:10px 0 0 0;">Term</th>
                        <th style="padding:.85rem 1rem;text-align:center;color:#fff;font-size:.82rem;font-weight:700;letter-spacing:.04em;">Rate</th>
                        <th style="padding:.85rem 1rem;text-align:center;color:#fff;font-size:.82rem;font-weight:700;letter-spacing:.04em;">Monthly Instalment</th>
                        <th style="padding:.85rem 1rem;text-align:center;color:#fff;font-size:.82rem;font-weight:700;letter-spacing:.04em;">Total Repayable</th>
                        <th style="padding:.85rem 1rem;text-align:center;color:#fff;font-size:.82rem;font-weight:700;letter-spacing:.04em;">Total Interest</th>
                        <th style="padding:.85rem 1rem;text-align:center;color:#fff;font-size:.82rem;font-weight:700;letter-spacing:.04em;border-radius:0 10px 0 0;"></th>
                    </tr>
                </thead>
                <tbody id="fc-body">
                    <!-- Populated by JS -->
                </tbody>
            </table>
        </div>

        <p style="color:#999;font-size:.78rem;margin-top:1rem;font-style:italic;">
            * Rates shown are indicative only. Your actual rate will be confirmed in your personalised quote.
        </p>

        <!-- SELECTED PLAN SUMMARY -->
        <div id="fc-summary" style="display:none;margin-top:1.5rem;background:linear-gradient(135deg,#003f73,#005BA1);border-radius:14px;padding:1.75rem;color:#fff;">
            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;">
                <i class="fas fa-circle-check" style="font-size:1.3rem;color:#6eff9e;"></i>
                <h3 style="font-size:1.05rem;margin:0;">Your Selected Plan</h3>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:1.25rem;">
                <div>
                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.6);margin-bottom:.2rem;">Vehicle Price</div>
                    <div id="fc-s-price" style="font-size:1.15rem;font-weight:700;">—</div>
                </div>
                <div>
                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.6);margin-bottom:.2rem;">Deposit</div>
                    <div id="fc-s-deposit" style="font-size:1.15rem;font-weight:700;">—</div>
                </div>
                <div>
                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.6);margin-bottom:.2rem;">Term</div>
                    <div id="fc-s-term" style="font-size:1.15rem;font-weight:700;">—</div>
                </div>
                <div>
                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.6);margin-bottom:.2rem;">Monthly Payment</div>
                    <div id="fc-s-monthly" style="font-size:1.3rem;font-weight:800;color:#6eff9e;">—</div>
                </div>
            </div>
            <a id="fc-s-wa" href="#"
               style="background:#005BA1;color:#fff;padding:.75rem 1.75rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:.9rem;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="fas fa-envelope"></i> Apply for This Plan via Email
            </a>
        </div>
    </div>

    <script>
    (function(){
        const MIN_DEPOSIT = 10500;
        const TIERS = [
            { months: 6,  rate: 0.035, label: '6 Months' },
            { months: 12, rate: 0.030, label: '12 Months', popular: true },
            { months: 24, rate: 0.028, label: '24 Months' },
            { months: 36, rate: 0.026, label: '36 Months' },
            { months: 48, rate: 0.025, label: '48 Months' },
        ];
        const $price   = document.getElementById('fc-price');
        const $deposit = document.getElementById('fc-deposit');
        const $fin     = document.getElementById('fc-financed');
        const $body    = document.getElementById('fc-body');
        const $warn    = document.getElementById('fc-deposit-warn');
        const $summary = document.getElementById('fc-summary');

        function num(v){ return parseFloat(String(v).replace(/[^0-9.]/g,'')) || 0; }
        function R(v){ return 'R' + v.toLocaleString('en-ZA',{minimumFractionDigits:0,maximumFractionDigits:0}); }
        function fmtInput(el){
            const v = num(el.value);
            if(v > 0) el.value = v.toLocaleString('en-ZA',{maximumFractionDigits:0});
        }

        function calc(){
            let price = num($price.value);
            let deposit = num($deposit.value);

            if(price < MIN_DEPOSIT + 1000) price = MIN_DEPOSIT + 1000;

            // enforce min deposit
            if(deposit < MIN_DEPOSIT){
                deposit = MIN_DEPOSIT;
                $deposit.value = MIN_DEPOSIT.toLocaleString('en-ZA');
                $warn.style.display = 'block';
            } else {
                $warn.style.display = 'none';
            }

            // cap deposit at price - 1000
            if(deposit >= price){
                deposit = price - 1000;
                if(deposit < MIN_DEPOSIT) deposit = MIN_DEPOSIT;
                $deposit.value = deposit.toLocaleString('en-ZA');
            }

            const financed = price - deposit;
            $fin.textContent = R(financed);

            let html = '';
            TIERS.forEach(function(t, i){
                const monthly   = (financed * (1 + t.rate * t.months)) / t.months;
                const total     = monthly * t.months + deposit;
                const interest  = total - price;
                const bg        = t.popular ? '#eef6ff' : (i % 2 === 0 ? '#f8fafc' : '#fff');
                const fw        = t.popular ? 'font-weight:600;' : '';

                html += '<tr style="background:'+bg+';'+fw+'cursor:pointer;transition:background .15s;" '
                      + 'onmouseover="this.style.background=\'#e0edff\'" '
                      + 'onmouseout="this.style.background=\''+bg+'\'" '
                      + 'onclick="window._fcSelect('+i+')">'
                      + '<td style="padding:.8rem 1rem;border-bottom:1px solid #eef1f5;color:#003f73;font-weight:600;font-size:.9rem;">'
                      +   t.label
                      +   (t.popular ? ' <span style="background:#005BA1;color:#fff;font-size:.62rem;padding:.12rem .45rem;border-radius:4px;margin-left:.4rem;vertical-align:middle;font-weight:700;">POPULAR</span>' : '')
                      + '</td>'
                      + '<td style="padding:.8rem 1rem;text-align:center;border-bottom:1px solid #eef1f5;color:#555;font-size:.9rem;">'+(t.rate*100).toFixed(1)+'% p/m</td>'
                      + '<td style="padding:.8rem 1rem;text-align:center;border-bottom:1px solid #eef1f5;color:#005BA1;font-weight:700;font-size:.95rem;">'+R(Math.round(monthly))+'</td>'
                      + '<td style="padding:.8rem 1rem;text-align:center;border-bottom:1px solid #eef1f5;color:#555;font-size:.9rem;">'+R(Math.round(total))+'</td>'
                      + '<td style="padding:.8rem 1rem;text-align:center;border-bottom:1px solid #eef1f5;color:#888;font-size:.9rem;">'+R(Math.round(interest))+'</td>'
                      + '<td style="padding:.8rem 1rem;text-align:center;border-bottom:1px solid #eef1f5;">'
                      +   '<span style="color:#005BA1;font-size:.8rem;font-weight:600;cursor:pointer;"><i class="fas fa-hand-pointer"></i> Select</span>'
                      + '</td></tr>';
            });
            $body.innerHTML = html;
        }

        window._fcSelect = function(idx){
            const t = TIERS[idx];
            const price = num($price.value);
            const deposit = num($deposit.value);
            const financed = price - deposit;
            const monthly = (financed * (1 + t.rate * t.months)) / t.months;

            document.getElementById('fc-s-price').textContent   = R(Math.round(price));
            document.getElementById('fc-s-deposit').textContent = R(Math.round(deposit));
            document.getElementById('fc-s-term').textContent    = t.label;
            document.getElementById('fc-s-monthly').textContent = R(Math.round(monthly)) + '/mo';

            const msg = 'Hi AHG, I used your finance calculator and I am interested in the following plan:\n'
                      + '- Vehicle Price: '+R(Math.round(price))+'\n'
                      + '- Deposit: '+R(Math.round(deposit))+'\n'
                      + '- Term: '+t.label+'\n'
                      + '- Est. Monthly: '+R(Math.round(monthly))+'\n'
                      + 'Please help me get started with in-house finance.';
            document.getElementById('fc-s-wa').href = 'mailto:<?= COMPANY_EMAIL ?>?subject='+encodeURIComponent('In-House Finance Application — '+t.label)+'&body='+encodeURIComponent(msg);

            $summary.style.display = 'block';
            $summary.scrollIntoView({behavior:'smooth', block:'nearest'});
        };

        const $savings     = document.getElementById('fc-savings');
        const $savingsAmt  = document.getElementById('fc-savings-amount');
        const $savingsTot  = document.getElementById('fc-savings-total');
        const quickBtns    = document.querySelectorAll('.fc-quick-btn');

        function updateSavings(){
            const price   = num($price.value);
            const deposit = num($deposit.value);
            if(deposit <= MIN_DEPOSIT || price <= deposit){
                $savings.style.display = 'none';
                return;
            }
            // Compare monthly at min deposit vs current deposit (using 12-month popular tier)
            const tier = TIERS[1]; // 12 months
            const finMin = price - MIN_DEPOSIT;
            const finCur = price - deposit;
            const monthlyMin = (finMin * (1 + tier.rate * tier.months)) / tier.months;
            const monthlyCur = (finCur * (1 + tier.rate * tier.months)) / tier.months;
            const savedMonthly = Math.round(monthlyMin - monthlyCur);
            const savedTotal   = savedMonthly * tier.months;
            if(savedMonthly > 0){
                $savings.style.display = 'flex';
                $savingsAmt.textContent = R(savedMonthly);
                $savingsTot.textContent  = 'That\'s '+R(savedTotal)+' less over '+tier.label;
            } else {
                $savings.style.display = 'none';
            }
        }

        // Quick deposit button handler
        window._fcSetDeposit = function(amount){
            $deposit.value = amount.toLocaleString('en-ZA');
            quickBtns.forEach(function(b){
                b.style.background = '#fff';
                b.style.color = '#003f73';
                b.style.borderColor = '#d0d8e4';
            });
            // Highlight the clicked button
            quickBtns.forEach(function(b){
                var btnVal = parseInt(b.textContent.replace(/[^0-9]/g,''));
                if(btnVal === amount){
                    b.style.background = '#005BA1';
                    b.style.color = '#fff';
                    b.style.borderColor = '#005BA1';
                }
            });
            calc();
            updateSavings();
        };

        var origCalc = calc;
        var _calcWrap = function(){ origCalc(); updateSavings(); };

        $price.addEventListener('input', function(){ _calcWrap(); });
        $deposit.addEventListener('input', function(){ _calcWrap(); });
        $price.addEventListener('blur', function(){ fmtInput(this); _calcWrap(); });
        $deposit.addEventListener('blur', function(){ fmtInput(this); _calcWrap(); });

        calc();
        updateSavings();
    })();
    </script>

    <!-- ═══════════ HOW IT WORKS ═══════════ -->
    <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2.5rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
        <h2 style="color:#003f73;font-size:1.5rem;margin-bottom:2rem;display:flex;align-items:center;gap:.75rem;">
            <i class="fas fa-route" style="color:#005BA1;"></i> How Our Finance Works
        </h2>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.5rem;">
            <?php
            $finSteps = [
                ['01','fa-message-dots','Get in Touch','Call or email us to get started. Tell us which vehicle you\'re interested in and we\'ll walk you through the process.','#005BA1'],
                ['02','fa-file-signature','Submit Your Documents','Provide your SA ID, proof of address, 3 months\' bank statements, and a recent payslip or proof of income.','#0078d7'],
                ['03','fa-hand-holding-dollar','Pay Your Deposit','Your minimum deposit of R10,500 secures the vehicle and activates your finance agreement. Pay any amount above R10,500 to lower your monthly instalments. No credit checks. No ITC. No bureau inquiries.','#e8a000'],
                ['04','fa-car-side','Drive Away','Choose your repayment term (6&ndash;48 months), sign your agreement, and drive away in your vehicle. It\'s that simple.','#25D366'],
            ];
            foreach ($finSteps as [$num,$icon,$title,$desc,$color]):
            ?>
            <div style="text-align:center;padding:1.5rem 1rem;">
                <div style="width:56px;height:56px;background:<?= $color ?>;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;position:relative;">
                    <i class="fas <?= $icon ?>" style="color:#fff;font-size:1.2rem;"></i>
                    <span style="position:absolute;top:-6px;right:-6px;background:#003f73;color:#fff;font-size:.65rem;font-weight:800;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><?= $num ?></span>
                </div>
                <h4 style="color:#003f73;font-size:1rem;margin-bottom:.5rem;"><?= $title ?></h4>
                <p style="color:#666;font-size:.88rem;line-height:1.65;"><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ═══════════ DOCUMENTS REQUIRED ═══════════ -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.5rem;margin-bottom:2.5rem;">

        <!-- Documents -->
        <div style="background:#fff;border-radius:16px;padding:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);border-left:5px solid #005BA1;">
            <h3 style="color:#003f73;font-size:1.15rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.65rem;">
                <i class="fas fa-folder-open" style="color:#005BA1;"></i> Documents Required
            </h3>
            <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.85rem;">
                <?php
                $docs = [
                    ['fa-id-card','Copy of valid South African ID document'],
                    ['fa-house','Proof of residential address (not older than 3 months)'],
                    ['fa-building-columns','3 months\' bank statements'],
                    ['fa-file-invoice','Recent payslip or proof of income'],
                ];
                foreach ($docs as [$icon,$doc]): ?>
                <li style="display:flex;gap:.75rem;align-items:flex-start;font-size:.92rem;color:#444;line-height:1.5;">
                    <i class="fas <?= $icon ?>" style="color:#005BA1;margin-top:.15rem;flex-shrink:0;width:18px;text-align:center;"></i>
                    <?= $doc ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Why Choose Us -->
        <div style="background:#fff;border-radius:16px;padding:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);border-left:5px solid #25D366;">
            <h3 style="color:#003f73;font-size:1.15rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.65rem;">
                <i class="fas fa-shield-halved" style="color:#25D366;"></i> Why Our Finance?
            </h3>
            <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.85rem;">
                <?php
                $reasons = [
                    'No credit checks, ITC checks, or bureau inquiries of any kind',
                    'Blacklisted and debt-review clients welcome',
                    'Flexible terms from 6 to 48 months &mdash; you choose',
                    'Low minimum deposit of R10,500 &mdash; pay more to reduce instalments',
                    'DEKRA-inspected vehicles &mdash; total peace of mind',
                    'Trusted auctioneering excellence since 2016',
                ];
                foreach ($reasons as $r): ?>
                <li style="display:flex;gap:.65rem;align-items:flex-start;font-size:.92rem;color:#444;line-height:1.5;">
                    <i class="fas fa-check" style="color:#25D366;margin-top:.15rem;flex-shrink:0;"></i>
                    <?= $r ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- ═══════════ OTHER PAYMENT OPTIONS ═══════════ -->
    <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2.5rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
        <h2 style="color:#003f73;font-size:1.35rem;margin-bottom:.5rem;display:flex;align-items:center;gap:.75rem;">
            <i class="fas fa-credit-card" style="color:#005BA1;"></i> Other Payment Options
        </h2>
        <p style="color:#888;font-size:.88rem;margin-bottom:1.5rem;">Prefer to pay another way? We also accept these methods for auction purchases.</p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;">
            <?php
            $otherOptions = [
                ['fa-piggy-bank','Cash Purchase','Pay the full amount directly and avoid any interest charges. The fastest, simplest way to own your vehicle.','#e6f9ee','#155724','#b7e2c5'],
                ['fa-landmark','Bank Finance (Pre-Approved)','Approach your bank for a pre-approved vehicle finance offer before auction day. Know your budget before you bid.','#ebf4ff','#003f73','#c5d9f0'],
                ['fa-handshake','Third-Party Finance Broker','Independent brokers can shop multiple lenders on your behalf for competitive rates.','#f3f0ff','#3d0066','#d6ceff'],
            ];
            foreach ($otherOptions as [$icon,$title,$desc,$bg,$col,$border]):
            ?>
            <div style="background:<?= $bg ?>;border:1px solid <?= $border ?>;border-radius:12px;padding:1.25rem;display:flex;gap:1rem;align-items:flex-start;">
                <div style="width:40px;height:40px;background:<?= $col ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas <?= $icon ?>" style="color:#fff;font-size:.9rem;"></i>
                </div>
                <div>
                    <h4 style="color:<?= $col ?>;font-size:.95rem;margin-bottom:.35rem;"><?= $title ?></h4>
                    <p style="color:#555;font-size:.85rem;line-height:1.65;"><?= $desc ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ═══════════ FAQ MINI ═══════════ -->
    <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2.5rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
        <h2 style="color:#003f73;font-size:1.35rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;">
            <i class="fas fa-circle-question" style="color:#e8a000;"></i> Finance FAQ
        </h2>
        <div style="display:flex;flex-direction:column;gap:1rem;">
            <?php
            $faqItems = [
                ['Do I need a good credit score?','Absolutely not. Our in-house finance requires NO credit checks, NO ITC checks, and NO bureau inquiries of any kind. Blacklisted clients, clients under debt review, and first-time buyers are all welcome.'],
                ['What deposit do I need?','A minimum deposit of R10,500 is required. You can pay any amount above R10,500 to reduce your monthly instalments. This is not linked to any credit assessment.'],
                ['What repayment terms are available?','Choose from 6, 12, 24, 36, or 48 months. Shorter terms have lower total costs, while longer terms offer lower monthly payments for greater flexibility.'],
                ['What documents do I need?','A valid SA ID, proof of residential address (not older than 3 months), 3 months\' bank statements, and a recent payslip or proof of income.'],
                ['Are the vehicles inspected?','Yes. Every vehicle sold through Auction Hub Group undergoes a professional DEKRA inspection. You receive a full condition report and you are welcome to bring your own mechanic or inspector for additional peace of mind.'],
            ];
            foreach ($faqItems as [$q,$a]):
            ?>
            <div style="background:#f8fafc;border-radius:12px;padding:1.25rem 1.5rem;border:1px solid #e5eaf0;">
                <h4 style="color:#003f73;font-size:.95rem;margin-bottom:.5rem;display:flex;align-items:flex-start;gap:.6rem;">
                    <i class="fas fa-q" style="color:#005BA1;font-size:.8rem;margin-top:.2rem;flex-shrink:0;"></i> <?= $q ?>
                </h4>
                <p style="color:#555;font-size:.88rem;line-height:1.7;padding-left:1.35rem;"><?= $a ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="text-align:center;margin-top:1.5rem;">
            <a href="<?= APP_URL ?>/faq.php" style="color:#005BA1;font-size:.9rem;font-weight:600;text-decoration:none;">
                View All FAQs <i class="fas fa-arrow-right" style="font-size:.8rem;"></i>
            </a>
        </div>
    </div>

    <!-- ═══════════ CTA ═══════════ -->
    <div style="background:linear-gradient(135deg,#003f73 0%,#005BA1 100%);border-radius:16px;padding:3rem 2.5rem;color:#fff;text-align:center;position:relative;overflow:hidden;">
        <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.04) 1px,transparent 1px);background-size:22px 22px;pointer-events:none;"></div>
        <div style="position:relative;z-index:1;">
            <h2 style="font-size:clamp(1.5rem,3vw,2rem);margin-bottom:.75rem;">Ready to Drive Away?</h2>
            <p style="color:rgba(255,255,255,.8);margin-bottom:.5rem;max-width:520px;margin-left:auto;margin-right:auto;font-size:1rem;line-height:1.7;">
                Don't let a credit score decide your future. Contact us today and get behind the wheel of your next vehicle with our hassle-free in-house finance.
            </p>
            <p style="color:rgba(255,255,255,.5);font-size:.85rem;margin-bottom:2rem;">No credit checks. No ITC. No bureau inquiries. From R10,500 deposit &mdash; pay more to lower your instalments.</p>
            <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:.75rem;">
                <a href="mailto:<?= COMPANY_EMAIL ?>?subject=In-House%20Finance%20Application&body=Hi%20AHG%2C%20I%20want%20to%20apply%20for%20in-house%20vehicle%20finance.%20Please%20help%20me%20get%20started."
                   style="background:#005BA1;color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:.5rem;font-size:.95rem;">
                    <i class="fas fa-envelope"></i> Enquire via Email
                </a>
                <a href="tel:<?= COMPANY_PHONE ?>"
                   style="background:rgba(255,255,255,.15);color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;border:1px solid rgba(255,255,255,.3);display:inline-flex;align-items:center;gap:.5rem;font-size:.95rem;">
                    <i class="fas fa-phone"></i> Call Us Now
                </a>
                <a href="<?= APP_URL ?>/auctions.php"
                   style="background:#fff;color:#005BA1;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:.5rem;font-size:.95rem;">
                    <i class="fas fa-gavel"></i> Browse Vehicles
                </a>
            </div>
        </div>
    </div>

</div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
