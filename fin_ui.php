<?php
// ============================================================
// AUCTION HUB GROUP — fin_ui.php
// Shared look for the finance / pre-auction pages (finance_apply.php,
// preauction_request.php, my_application.php, my_applications.php, vin-policy.php).
// ============================================================
?>
<style>
.ap-wrap{max-width:1080px;margin:0 auto;padding:2.5rem 1.25rem 3.5rem}
.ap-hero{background:linear-gradient(135deg,#003f73 0%,#005BA1 60%,#0078d7 100%);color:#fff;padding:3.25rem 1.25rem 2.75rem;text-align:center}
.ap-hero h1{color:#fff;font-size:clamp(1.9rem,4.5vw,2.9rem);margin:0 0 .6rem;line-height:1.1}
.ap-hero p{color:rgba(255,255,255,.85);max-width:680px;margin:0 auto;font-size:1.05rem;line-height:1.65}
.ap-hero .ap-eyebrow{display:inline-block;background:rgba(37,211,102,.15);border:1px solid rgba(37,211,102,.4);color:#6eff9e;border-radius:50px;padding:.35rem 1rem;font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:1rem}
.ap-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:1.5rem;align-items:start}
@media(max-width:880px){.ap-grid{grid-template-columns:1fr}}
.ap-card{background:#fff;border:1px solid #e3e9f0;border-radius:14px;padding:1.5rem;box-shadow:0 4px 18px rgba(0,40,90,.05);margin-bottom:1.25rem}
.ap-card h2{color:#003f73;font-size:1.3rem;margin:0 0 .5rem}
.ap-card h3{color:#003f73;font-size:1.05rem;margin:0 0 .5rem}
.ap-help{color:#667;font-size:.86rem;line-height:1.55;margin:.25rem 0 0}
.ap-form{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.ap-form .full{grid-column:1/-1}
@media(max-width:560px){.ap-form{grid-template-columns:1fr}}
.ap-form label{display:block;font-weight:700;color:#003f73;font-size:.85rem;margin-bottom:.3rem}
.ap-input{width:100%;padding:.7rem .85rem;border:1.5px solid #cfd9e4;border-radius:9px;font-size:1rem;background:#fff;color:#222;box-sizing:border-box}
.ap-input:focus{outline:none;border-color:#005BA1;box-shadow:0 0 0 3px rgba(0,91,161,.12)}
.ap-err .ap-input{border-color:#d93025}
.ap-err-text{color:#d93025;font-size:.82rem;display:block;margin-top:.25rem}
.ap-btn{display:inline-flex;align-items:center;gap:.5rem;background:#005BA1;color:#fff;border:0;border-radius:9px;padding:.8rem 1.4rem;font-weight:700;font-size:1rem;cursor:pointer;text-decoration:none}
.ap-btn:hover{background:#003f73;color:#fff}
.ap-btn-sm{padding:.5rem .95rem;font-size:.88rem}
.ap-btn-green{background:#1e9e4a}.ap-btn-green:hover{background:#167a39}
.ap-btn-out{background:#fff;color:#005BA1;border:1.5px solid #005BA1}.ap-btn-out:hover{background:#f0f6fc;color:#003f73}
.ap-btn-red{background:#fff;color:#c62828;border:1.5px solid #e6a5a5}.ap-btn-red:hover{background:#fff4f4;color:#a11}
.ap-btn-wa{background:#25D366}.ap-btn-wa:hover{background:#1da851}
.ap-flash{border-radius:10px;padding:.9rem 1.1rem;margin:0 0 1.25rem;font-weight:600}
.ap-flash-success{background:#e8f6ee;color:#11632f;border:1px solid #b9e3c8}
.ap-flash-error{background:#fdecea;color:#a1271b;border:1px solid #f5c2bd}
.ap-flash-info{background:#eef5fc;color:#0b4a80;border:1px solid #c8dcf0}
.ap-steps{display:flex;gap:.35rem;margin:0 0 1.25rem;flex-wrap:wrap;padding:0;list-style:none}
.ap-steps li{flex:1 1 120px;background:#eef2f6;color:#7a8794;border-radius:8px;padding:.55rem .6rem;font-size:.78rem;font-weight:700;text-align:center;position:relative}
.ap-steps li.done{background:#e3f4ea;color:#167a39}
.ap-steps li.now{background:#005BA1;color:#fff}
.ap-steps li b{display:block;font-size:1rem}
.ap-kv{list-style:none;padding:0;margin:0}
.ap-kv li{display:flex;justify-content:space-between;gap:1rem;padding:.55rem 0;border-bottom:1px solid #eef1f5;font-size:.93rem}
.ap-kv li span:first-child{color:#667}
.ap-kv li span:last-child{font-weight:700;color:#1d2a36;text-align:right}
.ap-table{width:100%;border-collapse:collapse;font-size:.93rem}
.ap-table th{background:#f3f7fb;color:#003f73;text-align:left;padding:.6rem .7rem;font-size:.8rem;text-transform:uppercase;letter-spacing:.04em}
.ap-table td{padding:.65rem .7rem;border-bottom:1px solid #eef1f5}
.ap-plan{display:flex;align-items:center;gap:.75rem;border:1.5px solid #dbe4ee;border-radius:10px;padding:.8rem 1rem;margin-bottom:.55rem;cursor:pointer}
.ap-plan:hover{border-color:#005BA1}
.ap-plan input{accent-color:#005BA1;width:18px;height:18px}
.ap-plan .m{font-weight:800;color:#003f73;min-width:92px}
.ap-plan .p{font-size:1.15rem;font-weight:800;color:#1e9e4a;margin-left:auto}
.ap-plan.chosen{border-color:#1e9e4a;background:#f1fbf5}
.ap-code{font-size:2.2rem;font-weight:900;letter-spacing:.25em;color:#005BA1;text-align:center;background:#f0f6fc;border:2px dashed #9cc2e5;border-radius:12px;padding:.8rem}
.ap-doc{border:1px solid #e3e9f0;border-radius:10px;padding:.9rem 1rem;margin-bottom:.7rem}
.ap-doc-head{display:flex;justify-content:space-between;gap:.75rem;align-items:center;flex-wrap:wrap}
.ap-pill{display:inline-block;border-radius:50px;padding:.18rem .65rem;font-size:.74rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.ap-pill-wait{background:#fff4d6;color:#8a5a00}.ap-pill-ok{background:#e3f4ea;color:#167a39}.ap-pill-bad{background:#fdecea;color:#a1271b}.ap-pill-none{background:#eef2f6;color:#6b7785}
.ap-veh{display:flex;gap:1rem;align-items:center}
.ap-veh img{width:120px;height:80px;object-fit:cover;border-radius:8px;background:#eef2f6}
.ap-note{background:#fff8e6;border-left:4px solid #f5a623;border-radius:0 8px 8px 0;padding:.8rem 1rem;font-size:.9rem;color:#5b4300;margin:1rem 0}
.ap-check{display:flex;gap:.6rem;align-items:flex-start;font-size:.92rem;margin:.5rem 0}
.ap-check input{margin-top:.25rem;accent-color:#005BA1}
.ap-row{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
.ap-faq details{border-bottom:1px solid #eef1f5;padding:.7rem 0}
.ap-faq summary{cursor:pointer;font-weight:700;color:#003f73}
.ap-faq p{color:#556;margin:.5rem 0 0;font-size:.92rem;line-height:1.6}
.ap-log{font-size:.82rem;color:#667;list-style:none;padding:0;margin:0}
.ap-log li{padding:.3rem 0;border-bottom:1px dashed #e6ebf0}
.ap-hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.vin-channels{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.8rem;margin:1rem 0}
.vin-channels>div{background:#f3f7fb;border-radius:10px;padding:1rem}
.vin-channels i{color:#005BA1;font-size:1.3rem;display:block;margin-bottom:.4rem}
.vin-channels strong{display:block;color:#003f73;margin-bottom:.25rem}
.vin-channels span{font-size:.86rem;color:#556;line-height:1.5}
</style>
