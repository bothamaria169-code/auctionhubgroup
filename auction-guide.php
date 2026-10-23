<?php
// ============================================================
// AUCTION HUB GROUP — auction-guide.php
// Complete guide: how to buy at AHG (auction + pre-auction)
// ============================================================
$pageTitle       = 'How to Buy a Car at Auction — Step by Step';
$pageDescription = 'Your complete guide to buying a vehicle at Auction Hub Group. Learn how to register, bid, inspect DEKRA reports, and drive away in your next vehicle.';
$bodyClass       = 'page-auction-guide';
require_once __DIR__ . '/header.php';
?>

<!-- ═══════════ HERO ═══════════ -->
<section style="background:linear-gradient(135deg,#003f73 0%,#005BA1 60%,#0078d7 100%);padding:5rem 1.5rem 4rem;text-align:center;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.05) 1px,transparent 1px);background-size:28px 28px;pointer-events:none;"></div>
    <div class="container" style="position:relative;z-index:1;">
        <p style="color:#7ec8ff;font-size:.88rem;letter-spacing:.15em;text-transform:uppercase;margin-bottom:.75rem;font-weight:600;">Your Complete Buying Guide</p>
        <h1 style="color:#fff;font-size:clamp(2.2rem,5vw,3.6rem);line-height:1.1;margin-bottom:1.25rem;">How to Buy a Vehicle at<br>Auction Hub Group</h1>
        <p style="color:rgba(255,255,255,.8);font-size:1.1rem;max-width:680px;margin:0 auto 2rem;line-height:1.7;">
            Whether you're bidding at auction or securing a pre-auction deal, this guide walks you through every step &mdash; from registration to driving away in your new vehicle.
        </p>
        <nav aria-label="Breadcrumb" style="color:rgba(255,255,255,.5);font-size:.87rem;">
            <a href="<?= APP_URL ?>/index.php" style="color:rgba(255,255,255,.6);text-decoration:none;">Home</a>
            <span style="margin:0 .5rem;">/</span>
            <span style="color:#fff;">How to Buy</span>
        </nav>
    </div>
</section>

<!-- ═══════════ TWO WAYS TO BUY TABS ═══════════ -->
<div style="background:#fff;border-bottom:1px solid #e5eaf0;position:sticky;top:72px;z-index:50;">
    <div class="container" style="max-width:1000px;margin:0 auto;display:flex;justify-content:center;gap:0;">
        <button onclick="switchTab('auction')" id="tab-auction" style="flex:1;max-width:280px;padding:1rem 1.5rem;border:none;background:none;cursor:pointer;font-size:.92rem;font-weight:700;color:#005BA1;border-bottom:3px solid #005BA1;transition:all .2s;">
            <i class="fas fa-gavel"></i> Auction Bidding
        </button>
        <button onclick="switchTab('preauction')" id="tab-preauction" style="flex:1;max-width:280px;padding:1rem 1.5rem;border:none;background:none;cursor:pointer;font-size:.92rem;font-weight:600;color:#888;border-bottom:3px solid transparent;transition:all .2s;">
            <i class="fas fa-bolt"></i> Pre-Auction Purchase
        </button>
    </div>
</div>

<main style="padding:4rem 1.5rem;background:#f5f7fa;">
<div class="container" style="max-width:1000px;margin:0 auto;">

    <!-- ═══════════════════════════════════════════════════════
         TAB 1: AUCTION BIDDING
         ═══════════════════════════════════════════════════════ -->
    <div id="panel-auction">

        <!-- INTRO -->
        <div style="background:linear-gradient(135deg,#ebf4ff,#f0f7ff);border:1px solid #c5d9f0;border-radius:16px;padding:2rem;margin-bottom:2.5rem;display:flex;gap:1.25rem;align-items:flex-start;">
            <i class="fas fa-info-circle" style="color:#005BA1;font-size:1.5rem;flex-shrink:0;margin-top:.15rem;"></i>
            <div>
                <h3 style="color:#003f73;font-size:1.05rem;margin-bottom:.4rem;">Compete &amp; Win</h3>
                <p style="color:#444;font-size:.93rem;line-height:1.75;">Our live auctions are fast-paced, transparent, and open to all registered bidders. With hundreds of bank-repossessed vehicles, properties, mining equipment, agricultural assets, trucks and trailers going under the hammer each month &mdash; there's always a deal waiting to be won. Bid via webcast online or attend in person at any of our branch locations.</p>
            </div>
        </div>

        <!-- 5-STEP PROCESS -->
        <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
            <h2 style="color:#003f73;font-size:1.6rem;margin-bottom:2rem;display:flex;align-items:center;gap:.75rem;">
                <i class="fas fa-map" style="color:#005BA1;"></i> How to Register &amp; Bid
            </h2>

            <?php
            $steps = [
                ['fa-user-plus','Register Online','Visit <a href="'.APP_URL.'/login.php?tab=register" style="color:#005BA1;font-weight:600;">auctionhubgroup.co.za</a> and create your bidder profile. Complete your FICA documentation online before auction day.','#005BA1'],
                ['fa-money-bill-transfer','Pay the Registration Deposit','A <strong>refundable deposit of R10,000.00 per vehicle</strong> is required. EFT must reflect on the day, or Credit/Debit Card accepted for deposit. <strong>No cash or cheques.</strong>','#0078d7'],
                ['fa-file-arrow-up','Submit FICA Documents','Valid ID, Proof of residential address (not older than 3 months), CK1/CM1 for companies, company resolution and letter of authority. Originals or certified copies only.','#005BA1'],
                ['fa-search','Browse the Full Catalogue','The auction catalogue is available at least <strong>one week</strong> before auction day. View photos, mileage, start prices, and DEKRA inspection reports online.','#0078d7'],
                ['fa-gavel','Bid Live &amp; Win','Bid in person at our branch locations or online via webcast. Watch real-time bids, stay competitive, and secure your asset at the hammer price.','#005BA1'],
            ];
            foreach ($steps as $i => [$icon,$title,$desc,$color]):
            ?>
            <div style="display:flex;gap:1.5rem;margin-bottom:<?= $i < count($steps)-1 ? '2rem' : '0' ?>;padding-bottom:<?= $i < count($steps)-1 ? '2rem' : '0' ?>;border-bottom:<?= $i < count($steps)-1 ? '1px solid #eef1f5' : 'none' ?>;">
                <div style="flex-shrink:0;">
                    <div style="width:52px;height:52px;background:<?= $color ?>;border-radius:12px;display:flex;align-items:center;justify-content:center;position:relative;">
                        <i class="fas <?= $icon ?>" style="color:#fff;font-size:1.1rem;"></i>
                        <span style="position:absolute;top:-8px;right:-8px;background:#003f73;color:#fff;font-size:.7rem;font-weight:800;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><?= $i+1 ?></span>
                    </div>
                </div>
                <div>
                    <h3 style="color:#003f73;font-size:1.05rem;margin-bottom:.5rem;"><?= $title ?></h3>
                    <p style="color:#555;line-height:1.75;font-size:.93rem;"><?= $desc ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- BIDDING COST BREAKDOWN TABLE -->
        <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
            <h2 style="color:#003f73;font-size:1.4rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;">
                <i class="fas fa-receipt" style="color:#005BA1;"></i> Bidding Cost Breakdown
            </h2>
            <p style="color:#777;font-size:.88rem;margin-bottom:1.25rem;">Here's exactly what a typical purchase looks like &mdash; no hidden fees, no surprises.</p>

            <div style="overflow-x:auto;">
                <table style="width:100%;max-width:520px;border-collapse:collapse;">
                    <?php
                    $costRows = [
                        ['Hammer Price (example)','R60,000.00','#f8fafc','#003f73'],
                        ['+ Admin Fee','R2,600.00','#fff','#555'],
                        ['+ VAT (15%)','R9,390.00','#f8fafc','#555'],
                        ['= Total','R71,990.00','#ebf4ff','#005BA1'],
                        ['&minus; Registration Deposit','(R10,000.00)','#fff','#888'],
                        ['Amount Due After Auction','R66,990.00','linear-gradient(135deg,#005BA1,#003f73)','#fff'],
                    ];
                    foreach ($costRows as $i => [$label,$amount,$bg,$col]):
                        $isTotal = $i === count($costRows) - 1;
                    ?>
                    <tr style="background:<?= $bg ?>;">
                        <td style="padding:<?= $isTotal ? '1rem' : '.85rem' ?> 1.25rem;font-size:<?= $isTotal ? '1rem' : '.93rem' ?>;font-weight:<?= ($i === 3 || $isTotal) ? '700' : '500' ?>;color:<?= $col ?>;border-bottom:<?= $isTotal ? 'none' : '1px solid #eef1f5' ?>;<?= $isTotal ? 'border-radius:0 0 0 10px;' : '' ?>">
                            <?= $label ?>
                        </td>
                        <td style="padding:<?= $isTotal ? '1rem' : '.85rem' ?> 1.25rem;text-align:right;font-size:<?= $isTotal ? '1.1rem' : '.93rem' ?>;font-weight:<?= ($i === 3 || $isTotal) ? '700' : '500' ?>;color:<?= $col ?>;border-bottom:<?= $isTotal ? 'none' : '1px solid #eef1f5' ?>;<?= $isTotal ? 'border-radius:0 0 10px 0;' : '' ?>">
                            <?= $amount ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <!-- ═══════════ IN-HOUSE FINANCE CALCULATOR (compact) ═══════════ -->
        <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);border-top:4px solid #25D366;">
            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.35rem;flex-wrap:wrap;">
                <h2 style="color:#003f73;font-size:1.4rem;margin:0;display:flex;align-items:center;gap:.65rem;">
                    <i class="fas fa-calculator" style="color:#25D366;"></i> In-House Finance Calculator
                </h2>
                <span style="background:rgba(37,211,102,.12);color:#1a8a3e;font-size:.72rem;font-weight:700;padding:.2rem .65rem;border-radius:4px;letter-spacing:.04em;">NO CREDIT CHECKS</span>
            </div>
            <p style="color:#777;font-size:.87rem;margin-bottom:1.5rem;">Need finance? Enter your vehicle price below to see estimated monthly instalments. Minimum deposit R10,500 — increase your deposit to lower your monthly payments.</p>

            <!-- Inputs -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1rem;margin-bottom:1.25rem;">
                <div>
                    <label for="ag-price" style="display:block;color:#003f73;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem;">Vehicle Price</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#888;font-weight:600;font-size:.9rem;">R</span>
                        <input type="text" id="ag-price" value="60,000" inputmode="numeric"
                            style="width:100%;padding:.65rem .65rem .65rem 26px;border:2px solid #d0d8e4;border-radius:8px;font-size:.95rem;font-weight:600;color:#003f73;outline:none;transition:border-color .2s;box-sizing:border-box;"
                            onfocus="this.style.borderColor='#25D366'" onblur="this.style.borderColor='#d0d8e4'">
                    </div>
                </div>
                <div>
                    <label for="ag-deposit" style="display:block;color:#003f73;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem;">Deposit <span style="font-weight:500;color:#888;text-transform:none;letter-spacing:0;">(min R10,500 — pay more to save)</span></label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#888;font-weight:600;font-size:.9rem;">R</span>
                        <input type="text" id="ag-deposit" value="10,500" inputmode="numeric"
                            style="width:100%;padding:.65rem .65rem .65rem 26px;border:2px solid #d0d8e4;border-radius:8px;font-size:.95rem;font-weight:600;color:#003f73;outline:none;transition:border-color .2s;box-sizing:border-box;"
                            onfocus="this.style.borderColor='#25D366'" onblur="this.style.borderColor='#d0d8e4'">
                    </div>
                </div>
                <div>
                    <label style="display:block;color:#003f73;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem;">Financed</label>
                    <div id="ag-financed" style="padding:.65rem;border:2px solid #e8f3fc;border-radius:8px;font-size:.95rem;font-weight:700;color:#005BA1;background:#f0f7ff;box-sizing:border-box;">R49,500</div>
                </div>
            </div>

            <!-- Quick deposit buttons -->
            <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.75rem;">
                <?php foreach ([10500, 15000, 20000, 25000, 30000] as $qd): ?>
                <button type="button" onclick="document.getElementById('ag-deposit').value='<?= number_format($qd) ?>';document.getElementById('ag-deposit').dispatchEvent(new Event('input'));"
                    style="padding:.3rem .65rem;border-radius:6px;font-size:.72rem;font-weight:700;cursor:pointer;border:1.5px solid #d0d8e4;background:#f8fafc;color:#003f73;transition:all .15s;"
                    onmouseover="this.style.borderColor='#25D366';this.style.background='#25D366';this.style.color='#fff'"
                    onmouseout="this.style.borderColor='#d0d8e4';this.style.background='#f8fafc';this.style.color='#003f73'">
                    R<?= number_format($qd) ?>
                </button>
                <?php endforeach; ?>
                <span style="font-size:.7rem;color:#888;align-self:center;"><i class="fas fa-bolt" style="color:#e8a000;"></i> Pay more, save more</span>
            </div>

            <!-- Deposit warning -->
            <div id="ag-warn" style="display:none;background:#fff3cd;border:1px solid #ffe08a;border-radius:6px;padding:.5rem .85rem;margin-bottom:1rem;font-size:.8rem;color:#856404;">
                <i class="fas fa-triangle-exclamation"></i> Minimum deposit is <strong>R10,500</strong>.
            </div>

            <!-- Results cards -->
            <div id="ag-results" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:.75rem;margin-bottom:1rem;">
                <!-- Populated by JS -->
            </div>

            <!-- Selected plan strip -->
            <div id="ag-selected" style="display:none;background:linear-gradient(135deg,#003f73,#005BA1);border-radius:10px;padding:1.15rem 1.5rem;color:#fff;display:none;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;">
                <div>
                    <span style="font-size:.75rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.06em;">Your estimated monthly payment</span>
                    <div id="ag-s-monthly" style="font-size:1.4rem;font-weight:800;color:#6eff9e;margin-top:.1rem;">—</div>
                    <span id="ag-s-detail" style="font-size:.78rem;color:rgba(255,255,255,.65);">—</span>
                </div>
                <div style="display:flex;gap:.5rem;flex-shrink:0;">
                    <a href="<?= APP_URL ?>/financing.php"
                       style="background:#005BA1;color:#fff;padding:.6rem 1.25rem;border-radius:8px;text-decoration:none;font-weight:700;font-size:.82rem;display:inline-flex;align-items:center;gap:.4rem;">
                        <i class="fas fa-arrow-right"></i> Apply Now
                    </a>
                    <a href="<?= APP_URL ?>/financing.php"
                       style="background:rgba(255,255,255,.15);color:#fff;padding:.6rem 1.25rem;border-radius:8px;text-decoration:none;font-weight:600;font-size:.82rem;border:1px solid rgba(255,255,255,.25);display:inline-flex;align-items:center;gap:.4rem;">
                        Full Details <i class="fas fa-arrow-right" style="font-size:.7rem;"></i>
                    </a>
                </div>
            </div>

            <p style="color:#aaa;font-size:.73rem;margin-top:.85rem;font-style:italic;">* Indicative rates only. Actual rate confirmed in your personalised quote. <a href="<?= APP_URL ?>/financing.php" style="color:#005BA1;">View full finance page</a></p>
        </div>

        <script>
        (function(){
            var MIN_DEP = 10500;
            var TIERS = [
                {m:6,  r:0.035, l:'6 mo'},
                {m:12, r:0.030, l:'12 mo', pop:true},
                {m:24, r:0.028, l:'24 mo'},
                {m:36, r:0.026, l:'36 mo'},
                {m:48, r:0.025, l:'48 mo'}
            ];
            var $p = document.getElementById('ag-price'),
                $d = document.getElementById('ag-deposit'),
                $f = document.getElementById('ag-financed'),
                $r = document.getElementById('ag-results'),
                $w = document.getElementById('ag-warn'),
                $sel = document.getElementById('ag-selected');

            function n(v){return parseFloat(String(v).replace(/[^0-9.]/g,''))||0;}
            function R(v){return 'R'+Math.round(v).toLocaleString('en-ZA');}
            function fmt(el){var v=n(el.value);if(v>0)el.value=v.toLocaleString('en-ZA',{maximumFractionDigits:0});}

            function go(){
                var price=n($p.value), dep=n($d.value);
                if(price<MIN_DEP+1000)price=MIN_DEP+1000;
                if(dep<MIN_DEP){dep=MIN_DEP;$d.value=MIN_DEP.toLocaleString('en-ZA');$w.style.display='block';}else{$w.style.display='none';}
                if(dep>=price){dep=price-1000;if(dep<MIN_DEP)dep=MIN_DEP;$d.value=dep.toLocaleString('en-ZA');}
                var fin=price-dep;
                $f.textContent=R(fin);

                var html='';
                TIERS.forEach(function(t,i){
                    var mo=(fin*(1+t.r*t.m))/t.m;
                    var border=t.pop?'border:2px solid #005BA1;':'border:1px solid #e5eaf0;';
                    html+='<div onclick="window._agSel('+i+')" style="background:#f8fafc;border-radius:10px;padding:1rem;text-align:center;cursor:pointer;transition:all .15s;'+border+'"'
                        +' onmouseover="this.style.boxShadow=\'0 4px 16px rgba(0,91,161,.15)\';this.style.transform=\'translateY(-2px)\'"'
                        +' onmouseout="this.style.boxShadow=\'none\';this.style.transform=\'none\'">'
                        +(t.pop?'<div style="font-size:.62rem;font-weight:700;color:#005BA1;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem;">Popular</div>':'')
                        +'<div style="color:#003f73;font-size:.82rem;font-weight:700;margin-bottom:.35rem;">'+t.l+'</div>'
                        +'<div style="color:#005BA1;font-size:1.25rem;font-weight:800;">'+R(mo)+'</div>'
                        +'<div style="color:#888;font-size:.72rem;margin-top:.15rem;">/month</div>'
                        +'<div style="color:#aaa;font-size:.68rem;margin-top:.35rem;">'+(t.r*100).toFixed(1)+'% p/m</div>'
                        +'</div>';
                });
                $r.innerHTML=html;
            }

            window._agSel=function(i){
                var t=TIERS[i], price=n($p.value), dep=n($d.value), fin=price-dep;
                var mo=(fin*(1+t.r*t.m))/t.m;
                var total=mo*t.m+dep;
                document.getElementById('ag-s-monthly').textContent=R(mo)+'/mo';
                document.getElementById('ag-s-detail').textContent=t.l+' | Total: '+R(total)+' | Deposit: '+R(dep);
                // Apply Now button links to financing page
                $sel.style.display='flex';
                $sel.scrollIntoView({behavior:'smooth',block:'nearest'});
            };

            $p.addEventListener('input',go);$d.addEventListener('input',go);
            $p.addEventListener('blur',function(){fmt(this);go();});
            $d.addEventListener('blur',function(){fmt(this);go();});
            go();
        })();
        </script>

        <!-- FICA DOCUMENTS REQUIRED -->
        <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);border-left:5px solid #e8a000;">
            <h2 style="color:#003f73;font-size:1.4rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;">
                <i class="fas fa-file-shield" style="color:#e8a000;"></i> FICA Documents Required
            </h2>
            <p style="color:#666;font-size:.9rem;margin-bottom:1.25rem;">To comply with South African regulations, all bidders must submit the following before auction day:</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
                <?php
                $ficaDocs = [
                    ['fa-id-card','Valid SA ID Document','Original or certified copy of your South African ID document or passport.'],
                    ['fa-house','Proof of Residential Address','Utility bill, bank statement, or municipal account not older than 3 months.'],
                    ['fa-building','Company Documents (If Applicable)','CK1/CM1 registration certificate, company resolution, and letter of authority for company buyers.'],
                ];
                foreach ($ficaDocs as [$icon,$title,$desc]):
                ?>
                <div style="background:#fff8e1;border:1px solid #ffe08a;border-radius:12px;padding:1.25rem;">
                    <div style="color:#e8a000;font-size:1.1rem;margin-bottom:.6rem;"><i class="fas <?= $icon ?>"></i></div>
                    <h4 style="color:#003f73;font-size:.93rem;margin-bottom:.35rem;"><?= $title ?></h4>
                    <p style="color:#666;font-size:.84rem;line-height:1.6;"><?= $desc ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- AUCTION TERMS -->
        <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);border-left:5px solid #dc3545;">
            <h2 style="color:#003f73;font-size:1.4rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;">
                <i class="fas fa-triangle-exclamation" style="color:#dc3545;"></i> Auction Terms &amp; Conditions
            </h2>
            <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.75rem;">
                <?php
                $terms = [
                    'Vehicles are sold <strong>as-is (voetstoots)</strong>. Mileage and descriptions are indicative only.',
                    '<strong>No cancellations allowed.</strong> Failure to pay forfeits your R10,000 deposit and may result in a 10% penalty of the purchase price.',
                    'The vehicle catalogue is subject to change without notice.',
                    'You have <strong>21 days</strong> to register the vehicle in your name. Change of ownership notification is handled by Auction Hub Group.',
                    '<strong>Storage charges</strong> apply if the vehicle is not collected timeously.',
                    'All Rules of Auction &mdash; including announcements made at commencement &mdash; constitute the complete and binding record of auction rules.',
                    'Only <strong>registered, verified bidders</strong> may participate. Bidder cards are non-transferable.',
                    'The <strong>auctioneer\'s decision is final</strong> on all bidding disputes.',
                ];
                foreach ($terms as $term):
                ?>
                <li style="display:flex;gap:.75rem;align-items:flex-start;font-size:.92rem;color:#555;line-height:1.65;">
                    <i class="fas fa-circle-xmark" style="color:#dc3545;margin-top:.15rem;flex-shrink:0;"></i>
                    <span><?= $term ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

    </div><!-- /#panel-auction -->

    <!-- ═══════════════════════════════════════════════════════
         TAB 2: PRE-AUCTION PURCHASE
         ═══════════════════════════════════════════════════════ -->
    <div id="panel-preauction" style="display:none;">

        <!-- INTRO -->
        <div style="background:linear-gradient(135deg,#e6f9ee,#f0faf4);border:1px solid #b7e2c5;border-radius:16px;padding:2rem;margin-bottom:2.5rem;">
            <h3 style="color:#155724;font-size:1.15rem;margin-bottom:.5rem;">
                <i class="fas fa-bolt" style="color:#25D366;"></i> Own a Vehicle for Less Than You Think
            </h3>
            <p style="color:#444;font-size:.93rem;line-height:1.75;">Why pay retail price when you can own the same vehicle for a fraction of the cost? Our Pre-Auction Purchase is your shortcut to driving away in a quality, DEKRA-inspected vehicle at the bank's outstanding balance &mdash; not market value, not inflated dealership pricing. These vehicles come directly from banks as liquidated or insolvency stock &mdash; no middlemen, no markups, no nonsense.</p>
        </div>

        <!-- PRE-AUCTION ADVANTAGES -->
        <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
            <h2 style="color:#003f73;font-size:1.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;">
                <i class="fas fa-star" style="color:#e8a000;"></i> The Pre-Auction Advantage
            </h2>
            <div style="display:flex;flex-direction:column;gap:1rem;">
                <?php
                $advantages = [
                    ['fa-tag','Pay Only the Bank\'s Outstanding Balance','No retail markup. No auction premium. You pay exactly what the bank is owed &mdash; often well below market value.','#005BA1'],
                    ['fa-truck','Free Delivery to Your Door','Can\'t come to us? We\'ll bring the vehicle to you at no extra charge &mdash; anywhere.','#25D366'],
                    ['fa-road','1.5-Hour Test Drive &amp; Full Inspection','Take your time. Drive it. Inspect it. Bring your own mechanic. We have nothing to hide.','#0078d7'],
                    ['fa-rotate-left','100% Refund If You\'re Not Happy','Not satisfied after the test drive? Your full reservation fee is returned immediately &mdash; no questions asked.','#e8a000'],
                    ['fa-ban','No Buyer\'s Commission. No Hidden Fees.','The price you see is the price you pay. Zero surprises at the final step.','#dc3545'],
                    ['fa-shield-halved','Skip the Bidding War','Secure your vehicle 7&ndash;14 days before auction day &mdash; before anyone else gets a chance to compete.','#003f73'],
                ];
                foreach ($advantages as [$icon,$title,$desc,$color]):
                ?>
                <div style="display:flex;gap:1.25rem;align-items:flex-start;background:#f8fafc;border-radius:12px;padding:1.25rem;border:1px solid #e5eaf0;">
                    <div style="width:44px;height:44px;background:<?= $color ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas <?= $icon ?>" style="color:#fff;font-size:1rem;"></i>
                    </div>
                    <div>
                        <h4 style="color:#003f73;font-size:.95rem;margin-bottom:.3rem;"><?= $title ?></h4>
                        <p style="color:#555;font-size:.88rem;line-height:1.65;"><?= $desc ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- PRE-AUCTION 3-STEP PROCESS -->
        <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
            <h2 style="color:#003f73;font-size:1.5rem;margin-bottom:2rem;display:flex;align-items:center;gap:.75rem;">
                <i class="fas fa-route" style="color:#005BA1;"></i> How the Pre-Auction Process Works
            </h2>

            <?php
            $preSteps = [
                ['fa-user-pen','Submit Your Details','Provide your Name &amp; Surname, ID/Passport number, Email address, Physical address, and Mobile number to begin your Pre-Auction Purchase profile.','#005BA1'],
                ['fa-file-signature','Receive Your Offer to Purchase &amp; Pay Commitment Fee','Our legal team drafts a binding Offer to Purchase. A commitment fee of <strong>30&ndash;50%</strong> of the asking price is required to remove the vehicle from the auction list. Upon payment, you instantly receive a <strong>6-digit OTP Buyers Code</strong> via SMS.','#0078d7'],
                ['fa-car-side','Test Drive, Inspect &amp; Pay the Balance','Arrive at our premises (or choose free delivery) and enjoy a <strong>1.5-hour test drive</strong> and full inspection. If satisfied, pay the remaining balance and drive away. If not, your reservation fee is <strong>fully refunded</strong>.','#25D366'],
            ];
            foreach ($preSteps as $i => [$icon,$title,$desc,$color]):
            ?>
            <div style="display:flex;gap:1.5rem;margin-bottom:<?= $i < count($preSteps)-1 ? '2rem' : '0' ?>;padding-bottom:<?= $i < count($preSteps)-1 ? '2rem' : '0' ?>;border-bottom:<?= $i < count($preSteps)-1 ? '1px solid #eef1f5' : 'none' ?>;">
                <div style="flex-shrink:0;">
                    <div style="width:52px;height:52px;background:<?= $color ?>;border-radius:12px;display:flex;align-items:center;justify-content:center;position:relative;">
                        <i class="fas <?= $icon ?>" style="color:#fff;font-size:1.1rem;"></i>
                        <span style="position:absolute;top:-8px;right:-8px;background:#003f73;color:#fff;font-size:.7rem;font-weight:800;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><?= $i+1 ?></span>
                    </div>
                </div>
                <div>
                    <h3 style="color:#003f73;font-size:1.05rem;margin-bottom:.5rem;"><?= $title ?></h3>
                    <p style="color:#555;line-height:1.75;font-size:.93rem;"><?= $desc ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ENTRY REQUIREMENTS WARNING -->
        <div style="background:linear-gradient(135deg,#fff8e1,#fff3cd);border:2px solid #e8a000;border-radius:14px;padding:1.5rem 2rem;margin-bottom:2rem;display:flex;gap:1.25rem;align-items:flex-start;">
            <i class="fas fa-triangle-exclamation" style="color:#e8a000;font-size:1.5rem;flex-shrink:0;margin-top:.15rem;"></i>
            <div>
                <h3 style="color:#7a4f00;font-size:1rem;margin-bottom:.5rem;">Important Entry Requirements</h3>
                <p style="color:#856404;font-size:.92rem;line-height:1.7;margin:0;">
                    <strong>No Buyers Code = No Entry</strong> &nbsp;|&nbsp; <strong>No Appointment = No Entry</strong> &nbsp;|&nbsp; <strong>No Walk-Ins Permitted</strong><br>
                    Please do not lose or delete your Buyers Code &mdash; it is your access key to the premises and test drive venue.
                </p>
            </div>
        </div>

    </div><!-- /#panel-preauction -->


    <!-- ═══════════════════════════════════════════════════════
         DEKRA INSPECTION — SHOWN FOR BOTH TABS
         ═══════════════════════════════════════════════════════ -->
    <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
        <h2 style="color:#003f73;font-size:1.5rem;margin-bottom:.5rem;display:flex;align-items:center;gap:.75rem;">
            <i class="fas fa-clipboard-check" style="color:#005BA1;"></i> DEKRA Vehicle Inspection
        </h2>
        <p style="color:#666;font-size:.92rem;margin-bottom:1.75rem;line-height:1.7;">Every vehicle auctioned through Auction Hub Group undergoes a professional <strong>DEKRA inspection</strong>. You'll know exactly what you're bidding on. You are also welcome to bring your own trusted third-party inspector for complete peace of mind.</p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
            <div style="background:#e8f5e9;border:2px solid #4caf50;border-radius:14px;padding:1.5rem;text-align:center;">
                <div style="width:48px;height:48px;background:#4caf50;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;">
                    <i class="fas fa-circle-check" style="color:#fff;font-size:1.2rem;"></i>
                </div>
                <h4 style="color:#2e7d32;font-size:1rem;margin-bottom:.5rem;">Green Sticker</h4>
                <p style="color:#555;font-size:.84rem;line-height:1.6;">May require specific repairs to pass a roadworthy test. Full DEKRA report provided.</p>
            </div>
            <div style="background:#fff8e1;border:2px solid #f9a825;border-radius:14px;padding:1.5rem;text-align:center;">
                <div style="width:48px;height:48px;background:#f9a825;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;">
                    <i class="fas fa-award" style="color:#fff;font-size:1.2rem;"></i>
                </div>
                <h4 style="color:#f57f17;font-size:1rem;margin-bottom:.5rem;">Gold Sticker</h4>
                <p style="color:#555;font-size:.84rem;line-height:1.6;">No roadworthy issues identified. Vehicle will pass a roadworthy test.</p>
            </div>
            <div style="background:#e3f2fd;border:2px solid #1565c0;border-radius:14px;padding:1.5rem;text-align:center;">
                <div style="width:48px;height:48px;background:#1565c0;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;">
                    <i class="fas fa-crown" style="color:#fff;font-size:1.2rem;"></i>
                </div>
                <h4 style="color:#0d47a1;font-size:1rem;margin-bottom:.5rem;">Platinum Sticker</h4>
                <p style="color:#555;font-size:.84rem;line-height:1.6;">No roadworthy issues and has already passed a roadworthy test. Ready to go.</p>
            </div>
        </div>
    </div>

    <!-- TIPS FOR FIRST-TIME BIDDERS -->
    <div style="background:#fff;border-radius:16px;padding:2.5rem;margin-bottom:2rem;box-shadow:0 2px 20px rgba(0,0,0,.06);">
        <h2 style="color:#003f73;font-size:1.4rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;">
            <i class="fas fa-lightbulb" style="color:#e8a000;"></i> Tips for First-Time Buyers
        </h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;">
            <?php
            $tips = [
                ['fa-magnifying-glass-dollar','Set a Budget','Decide your maximum spend before auction day and stick to it. Factor in admin fees, VAT, and potential repair costs.'],
                ['fa-car-burst','Inspect the Vehicle','Always review the DEKRA inspection report. Attend viewing days and bring a trusted mechanic if possible.'],
                ['fa-clock','Arrive Early','Get to the venue early, pick up your bidder card, and review the lot order so you know when your vehicle comes up.'],
                ['fa-head-side-virus','Stay Calm','Auction adrenaline is real. Don\'t let emotions push you over your budget. There will always be another vehicle.'],
                ['fa-file-contract','Read the Fine Print','Understand the terms before you bid. All vehicles are sold voetstoots. Bids cannot be withdrawn.'],
                ['fa-hand-holding-dollar','Finance First','If you need finance, arrange it before auction day. Check out our <a href="'.APP_URL.'/financing.php" style="color:#005BA1;font-weight:600;">in-house finance</a> &mdash; no credit checks required.'],
            ];
            foreach ($tips as [$icon,$title,$desc]):
            ?>
            <div style="background:#f8fafc;border-radius:12px;padding:1.25rem;border:1px solid #e5eaf0;">
                <div style="color:#e8a000;font-size:1.3rem;margin-bottom:.6rem;"><i class="fas <?= $icon ?>"></i></div>
                <h4 style="color:#003f73;margin-bottom:.4rem;font-size:.93rem;"><?= $title ?></h4>
                <p style="color:#666;font-size:.85rem;line-height:1.65;"><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- CTA -->
    <div style="background:linear-gradient(135deg,#003f73 0%,#005BA1 100%);border-radius:16px;padding:3rem 2.5rem;color:#fff;text-align:center;position:relative;overflow:hidden;">
        <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.04) 1px,transparent 1px);background-size:22px 22px;pointer-events:none;"></div>
        <div style="position:relative;z-index:1;">
            <h2 style="font-size:clamp(1.5rem,3vw,1.8rem);margin-bottom:.75rem;">Ready to Buy Your Next Vehicle?</h2>
            <p style="color:rgba(255,255,255,.8);margin-bottom:2rem;max-width:500px;margin-left:auto;margin-right:auto;">Register today and take the first step toward owning a quality vehicle at a fraction of the retail price.</p>
            <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:.75rem;">
                <a href="<?= APP_URL ?>/login.php?tab=register" style="background:#fff;color:#005BA1;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:.5rem;">
                    <i class="fas fa-user-plus"></i> Register Free
                </a>
                <a href="<?= APP_URL ?>/auctions.php" style="background:rgba(255,255,255,.15);color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;border:1px solid rgba(255,255,255,.3);display:inline-flex;align-items:center;gap:.5rem;">
                    <i class="fas fa-gavel"></i> View Auctions
                </a>
                <a href="<?= APP_URL ?>/financing.php" style="background:#25D366;color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:.5rem;">
                    <i class="fas fa-hand-holding-dollar"></i> In-House Finance
                </a>
            </div>
        </div>
    </div>

</div>
</main>

<script>
function switchTab(tab) {
    const panels = ['auction','preauction'];
    panels.forEach(p => {
        document.getElementById('panel-' + p).style.display = (p === tab) ? 'block' : 'none';
        const btn = document.getElementById('tab-' + p);
        if (p === tab) {
            btn.style.color = '#005BA1';
            btn.style.borderBottom = '3px solid #005BA1';
            btn.style.fontWeight = '700';
        } else {
            btn.style.color = '#888';
            btn.style.borderBottom = '3px solid transparent';
            btn.style.fontWeight = '600';
        }
    });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
