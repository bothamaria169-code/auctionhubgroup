<?php
// ============================================================
// AUCTION HUB GROUP — vehicle_strip.php
// Reusable "live stock" module for content pages.
//
// Why this exists: the news articles were pulling readers in and then
// dead-ending. This drops real, clickable lots into the middle and the
// foot of every article, which does three jobs at once —
//   1. sends article readers into the auction funnel,
//   2. gives every article internal links to auctions.php and to
//      individual auction.php?id= pages, which is what makes Google
//      treat those listing pages as important,
//   3. keeps the article page fresh, because the lots rotate as stock
//      turns over.
//
// Usage:
//   require_once 'vehicle_strip.php';
//   echo renderVehicleStrip($pdo, [
//       'context' => $post['title'] . ' ' . $post['excerpt'],  // for make matching
//       'limit'   => 3,
//       'heading' => 'Available at our next auction',
//   ]);
// ============================================================

if (!defined('AHG_VEHICLE_STRIP')) {
define('AHG_VEHICLE_STRIP', true);

/**
 * Build a clean display title for a lot.
 *
 * item_name is inconsistent in the database: some rows are bare
 * ("1 Series 128ti Auto"), others already carry the year and make
 * ("2021 BMW 1 Series 128ti Auto"). Blindly prefixing year + make
 * produces "2021 BMW 2021 BMW 1 Series 128ti Auto", so only add each
 * part when it is not already there.
 *
 * @param array $lot needs item_name, and optionally year + make_name
 */
function lotTitle(array $lot): string {
    $name = trim((string)($lot['item_name'] ?? ''));
    $make = trim((string)($lot['make_name'] ?? ''));
    $year = trim((string)($lot['year'] ?? ''));

    if ($name === '') {
        return trim($year . ' ' . $make);
    }

    // A name that already opens with a four-digit year is a complete,
    // captured-as-listed title — leave it alone. This also covers rows where
    // item_name abbreviates the make ("2014 VW POLO" against make "Volkswagen"),
    // which a plain substring test would wrongly treat as missing.
    if (preg_match('/^(19|20)\d{2}\b/', $name)) {
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    if ($make !== '' && stripos($name, $make) === false) {
        $name = $make . ' ' . $name;
    }
    if ($year !== '' && strpos($name, $year) === false) {
        $name = $year . ' ' . $name;
    }
    return trim(preg_replace('/\s+/', ' ', $name));
}

/**
 * Find vehicle makes named in a block of text.
 * Lets an article about the Toyota Hilux surface Toyota lots rather
 * than whatever happens to be newest.
 *
 * @return int[] matching make IDs, most specific first
 */
function matchMakesInText(PDO $pdo, string $text): array {
    if (trim($text) === '') return [];
    try {
        $makes = $pdo->query("SELECT id, name FROM makes")->fetchAll();
    } catch (Exception $e) {
        return [];
    }

    $hay   = ' ' . strtolower(strip_tags($text)) . ' ';
    $found = [];
    foreach ($makes as $m) {
        $name = strtolower(trim($m['name']));
        // Word-boundary match so "BMW" does not fire on "bmwx" and
        // "Kia" does not fire inside "Slovakia".
        if ($name !== '' && preg_match('/\b' . preg_quote($name, '/') . '\b/u', $hay)) {
            $found[] = (int)$m['id'];
        }
    }
    return $found;
}

/**
 * Render a strip of live/upcoming lots.
 *
 * Options:
 *   context  string  Text to scan for make names (article title + excerpt)
 *   limit    int     How many lots to show (default 3)
 *   heading  string  Section heading
 *   sub      string  Optional sub-line under the heading
 *   compact  bool    Narrower cards, for use inside an article column
 *
 * Returns '' when there is no stock, so callers can echo it blindly.
 */
function renderVehicleStrip(PDO $pdo, array $opts = []): string {
    $limit   = max(1, min(6, (int)($opts['limit'] ?? 3)));
    $heading = $opts['heading'] ?? 'Available at our next auction';
    $sub     = $opts['sub']     ?? '';
    $compact = !empty($opts['compact']);
    $context = (string)($opts['context'] ?? '');

    // Lots already shown earlier on this page. Two strips on one article
    // showing the identical three cars looks like a bug to the reader and
    // wastes the second placement, so each call skips what came before.
    static $alreadyShown = [];

    $makeIds = matchMakesInText($pdo, $context);
    $lots    = [];

    $baseSelect = "
        SELECT a.id, a.item_name, a.year, a.mileage, a.image_url,
               a.current_price, a.start_price, a.condition_grade,
               m.name AS make_name
        FROM auctions a
        LEFT JOIN makes m ON m.id = a.make_id
        WHERE a.status IN ('active','upcoming') AND a.is_sold = 0
    ";

    // Builds the " AND a.id NOT IN (…)" clause for whatever is already used.
    $notIn = function (array $ids) {
        return $ids
            ? ' AND a.id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            : '';
    };

    // Pass 1 — lots whose make the article actually talks about.
    if ($makeIds) {
        try {
            $ph     = implode(',', array_fill(0, count($makeIds), '?'));
            $params = array_merge($makeIds, $alreadyShown);
            $stmt   = $pdo->prepare($baseSelect . " AND a.make_id IN ($ph)"
                                  . $notIn($alreadyShown)
                                  . " ORDER BY a.created_at DESC LIMIT $limit");
            $stmt->execute($params);
            $lots = $stmt->fetchAll();
        } catch (Exception $e) {
            $lots = [];
        }
    }

    // Pass 2 — top up with newest stock so the strip is never half-empty.
    if (count($lots) < $limit) {
        try {
            $skip = array_merge($alreadyShown, array_column($lots, 'id'));
            $need = $limit - count($lots);
            $stmt = $pdo->prepare($baseSelect . $notIn($skip)
                                . " ORDER BY a.created_at DESC LIMIT $need");
            $stmt->execute($skip);
            $lots = array_merge($lots, $stmt->fetchAll());
        } catch (Exception $e) {}
    }

    $alreadyShown = array_merge($alreadyShown, array_column($lots, 'id'));

    if (!$lots) return '';

    $appUrl = defined('APP_URL') ? APP_URL : '';
    $cls    = $compact ? 'vstrip vstrip-compact' : 'vstrip';

    ob_start(); ?>
    <section class="<?= $cls ?>" aria-label="Vehicles currently on auction">
        <div class="vstrip-head">
            <h3 class="vstrip-title"><?= e($heading) ?></h3>
            <?php if ($sub !== ''): ?><p class="vstrip-sub"><?= e($sub) ?></p><?php endif; ?>
        </div>

        <div class="vstrip-grid">
            <?php foreach ($lots as $lot):
                $title = lotTitle($lot);
                $img   = !empty($lot['image_url']) ? cldImg($lot['image_url'], CLD_CARD) : $appUrl . '/assets/no-image.jpg';
                $price = (float)($lot['current_price'] ?: $lot['start_price']);
            ?>
            <a href="<?= $appUrl ?>/auction.php?id=<?= (int)$lot['id'] ?>" class="vstrip-card">
                <img src="<?= e($img) ?>" alt="<?= e($title) ?> on auction at Auction Hub Group"
                     class="vstrip-img" loading="lazy" width="400" height="260">
                <div class="vstrip-body">
                    <span class="vstrip-name"><?= e($title) ?></span>
                    <span class="vstrip-meta"><?= $lot['mileage'] ? number_format((int)$lot['mileage']) . ' km' : 'Mileage on request' ?></span>
                    <span class="vstrip-price">R<?= number_format($price, 0, '.', ' ') ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="vstrip-cta">
            <a href="<?= $appUrl ?>/auctions.php" class="vstrip-btn-ghost">Browse all auction vehicles</a>
            <a href="<?= $appUrl ?>/register-to-bid.php" class="vstrip-btn-solid">Register free to bid</a>
        </div>
    </section>

    <?php if (!defined('AHG_VSTRIP_CSS_DONE')): define('AHG_VSTRIP_CSS_DONE', true); ?>
    <style>
    .vstrip { background:#f4f7fa; border:1px solid #e3eaf1; border-radius:14px; padding:1.5rem; margin:2.2rem 0; }
    .vstrip-head { margin-bottom:1.1rem; }
    .vstrip-title { font-family:'Barlow Condensed',sans-serif; text-transform:uppercase; font-size:1.3rem; color:#001F5C; margin:0 0 .2rem; letter-spacing:.01em; }
    .vstrip-sub { font-size:.86rem; color:#6b7d8f; margin:0; line-height:1.5; }
    .vstrip-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.9rem; }
    .vstrip-compact .vstrip-grid { gap:.7rem; }
    .vstrip-card { display:block; background:#fff; border:1px solid #e3eaf1; border-radius:10px; overflow:hidden; text-decoration:none; color:inherit; transition:transform .18s, box-shadow .18s; }
    .vstrip-card:hover { transform:translateY(-3px); box-shadow:0 10px 24px rgba(0,31,92,.13); color:inherit; }
    .vstrip-img { width:100%; height:130px; object-fit:cover; display:block; background:#e8eef4; }
    .vstrip-compact .vstrip-img { height:108px; }
    .vstrip-body { padding:.65rem .75rem .8rem; display:flex; flex-direction:column; gap:.15rem; }
    .vstrip-name { font-weight:700; font-size:.86rem; color:#001F5C; line-height:1.3; }
    .vstrip-meta { font-size:.74rem; color:#8095a8; }
    .vstrip-price { font-family:'Barlow Condensed',sans-serif; font-weight:800; font-size:1.1rem; color:#005BA1; margin-top:.2rem; }
    .vstrip-cta { display:flex; gap:.6rem; margin-top:1.2rem; flex-wrap:wrap; }
    .vstrip-btn-ghost, .vstrip-btn-solid { flex:1 1 auto; text-align:center; padding:.65rem 1rem; border-radius:50px; font-weight:700; font-size:.85rem; text-decoration:none; transition:opacity .18s; }
    .vstrip-btn-ghost { border:1.5px solid #005BA1; color:#005BA1; }
    .vstrip-btn-solid { background:linear-gradient(135deg,#ffd54f,#f5a623); color:#001F5C; }
    .vstrip-btn-ghost:hover, .vstrip-btn-solid:hover { opacity:.85; }
    @media (max-width:768px) { .vstrip-grid { grid-template-columns:repeat(2,1fr); } }
    @media (max-width:480px) { .vstrip-grid { grid-template-columns:1fr; } .vstrip { padding:1.1rem; } }
    </style>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}

} // AHG_VEHICLE_STRIP
