<?php
// ============================================================
// AUCTION HUB GROUP — 404.php
// "Page not found". Sends a real 404 status so Google drops dead
// links instead of indexing them as copies of the homepage.
// Reached three ways: ErrorDocument 404 in .htaccess, index.php when
// the URL is not the homepage, or directly.
// ============================================================

http_response_code(404);

// Missing images / scripts / styles: a short plain reply, not a whole page.
$ahg404Path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('/\.(jpe?g|png|gif|webp|svg|ico|css|js|map|woff2?|ttf|otf|xml|txt|json|pdf|zip)$/i', $ahg404Path)) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';
    return;
}

$pageTitle       = 'Page Not Found';
$pageDescription = 'This page could not be found. Browse live bank repossessed vehicle auctions at Auction Hub Group.';
$bodyClass       = 'page-404';
$noIndex         = true;

require_once __DIR__ . '/header.php';
?>

<section style="background:linear-gradient(135deg,#003f73 0%,#005BA1 50%,#0078d7 100%);padding:4.5rem 1.5rem 4rem;text-align:center;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.04) 1px,transparent 1px);background-size:24px 24px;pointer-events:none;"></div>
    <div class="container" style="position:relative;z-index:1;max-width:720px;">
        <div style="font-size:clamp(4rem,12vw,7rem);font-weight:900;line-height:1;color:#fff;letter-spacing:-.03em;opacity:.95;">404</div>
        <h1 style="color:#fff;font-size:clamp(1.5rem,3.5vw,2.2rem);margin:.75rem 0;font-weight:800;">
            This page has been sold, moved or never existed
        </h1>
        <p style="color:rgba(255,255,255,.85);font-size:1rem;line-height:1.7;margin:0 auto 1.75rem;max-width:560px;">
            The link may be old or mistyped. The vehicle you were looking for may already be sold.
            Search our current stock below.
        </p>

        <form action="<?= APP_URL ?>/auctions.php" method="get" role="search"
              style="display:flex;gap:.5rem;max-width:520px;margin:0 auto 1.5rem;flex-wrap:wrap;">
            <label for="nf-search" class="sr-only" style="position:absolute;left:-9999px;">Search vehicles</label>
            <input id="nf-search" type="search" name="search" placeholder="Search make or model, e.g. Polo, Hilux"
                   style="flex:1 1 240px;min-width:0;padding:.85rem 1rem;border-radius:10px;border:none;font-size:1rem;">
            <button type="submit" class="btn btn-primary" style="flex:0 0 auto;">
                <i class="fas fa-search" aria-hidden="true"></i> Search
            </button>
        </form>

        <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;">
            <a href="<?= APP_URL ?>/" class="btn btn-white"><i class="fas fa-home" aria-hidden="true"></i> Homepage</a>
            <a href="<?= APP_URL ?>/auctions.php" class="btn btn-white"><i class="fas fa-gavel" aria-hidden="true"></i> Live Auctions</a>
        </div>
    </div>
</section>

<section style="padding:3rem 1.5rem;">
    <div class="container" style="max-width:960px;">
        <h2 style="text-align:center;font-size:1.3rem;margin-bottom:1.5rem;">Popular pages</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
            <?php
            $nfLinks = [
                ['auctions.php',      'fa-gavel',        'Browse Auctions',   'Live and upcoming vehicles'],
                ['sold_vehicles.php', 'fa-tags',         'Sold Prices',       'What vehicles really sold for'],
                ['financing.php',     'fa-hand-holding-usd', 'Vehicle Finance', 'Apply and check your options'],
                ['auction-guide.php', 'fa-book-open',    'How Auctions Work', 'Step-by-step buyer guide'],
                ['faq.php',           'fa-question-circle', 'FAQ',            'Answers to common questions'],
                ['contact.php',       'fa-phone',        'Contact Us',        'Centurion and Boksburg'],
            ];
            foreach ($nfLinks as [$href, $icon, $title, $sub]): ?>
                <a href="<?= APP_URL ?>/<?= $href ?>"
                   style="display:flex;gap:.85rem;align-items:center;padding:1rem 1.1rem;border:1px solid #dbe6f1;border-radius:12px;background:#fff;text-decoration:none;color:inherit;">
                    <i class="fas <?= $icon ?>" aria-hidden="true" style="color:#005BA1;font-size:1.3rem;width:1.6rem;text-align:center;"></i>
                    <span>
                        <strong style="display:block;color:#003f73;"><?= e($title) ?></strong>
                        <small style="color:#5b6b7b;"><?= e($sub) ?></small>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
