<?php
// ============================================================
// AUCTION HUB GROUP — sitemap.php
// Dynamic XML sitemap served at /sitemap.xml (.htaccess rewrite)
// Includes: static pages · live/upcoming vehicles · news posts
//           · upcoming events
// ============================================================
// Swallow any stray UTF-8 BOM / whitespace an editor may have added to an
// included file (e.g. config.php) — a BOM before <?xml invalidates the feed.
ob_start();
require_once __DIR__ . '/config.php';
ob_end_clean();

header('Content-Type: application/xml; charset=utf-8');

$base = CANONICAL_URL; // always the public production domain

/** One <url> entry. $loc is appended to $base; & is XML-escaped. */
function smUrl(string $loc, ?string $lastmod = null, string $freq = 'weekly', string $priority = '0.6'): string {
    global $base;
    $out  = "  <url>\n";
    $out .= "    <loc>" . htmlspecialchars($base . '/' . ltrim($loc, '/'), ENT_XML1) . "</loc>\n";
    if ($lastmod) {
        $ts = strtotime($lastmod);
        if ($ts) $out .= "    <lastmod>" . date('Y-m-d', $ts) . "</lastmod>\n";
    }
    $out .= "    <changefreq>{$freq}</changefreq>\n";
    $out .= "    <priority>{$priority}</priority>\n";
    $out .= "  </url>\n";
    return $out;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// ── Static pages ─────────────────────────────────────────────
echo smUrl('',                  null, 'daily',   '1.0');
echo smUrl('auctions.php',      null, 'hourly',  '0.9');
echo smUrl('preauction.php',    null, 'daily',   '0.9');
echo smUrl('events.php',        null, 'daily',   '0.8');
echo smUrl('news.php',          null, 'daily',   '0.8');
echo smUrl('sold_vehicles.php', null, 'weekly',  '0.6');
echo smUrl('auction-guide.php', null, 'monthly', '0.7');
echo smUrl('financing.php',     null, 'monthly', '0.7');
echo smUrl('about.php',         null, 'monthly', '0.5');
echo smUrl('team.php',          null, 'monthly', '0.4');
echo smUrl('testimonials.php',  null, 'weekly',  '0.5');
echo smUrl('faq.php',           null, 'monthly', '0.5');
echo smUrl('contact.php',       null, 'monthly', '0.5');
echo smUrl('careers.php',       null, 'monthly', '0.3');
echo smUrl('register-to-bid.php', null, 'weekly',  '0.9');

// ── Vehicles (live + upcoming auctions) ──────────────────────
// Production has drifted from the local schema before: when auctions.updated_at
// is missing, the whole SELECT throws and the catch below silently dropped every
// vehicle URL from the sitemap — which is exactly what happened, leaving hundreds
// of live listings invisible to Google. Try the richer query first, then fall
// back to one that only needs columns guaranteed to exist.
$vehicleRows = [];
try {
    $vehicleRows = $pdo->query("
        SELECT id, updated_at AS lastmod FROM auctions
        WHERE status IN ('active','upcoming')
        ORDER BY id DESC
    ")->fetchAll();
} catch (Exception $e) {
    try {
        $vehicleRows = $pdo->query("
            SELECT id, NULL AS lastmod FROM auctions
            WHERE status IN ('active','upcoming')
            ORDER BY id DESC
        ")->fetchAll();
    } catch (Exception $e2) {
        // auctions table genuinely unavailable — skip the section.
        $vehicleRows = [];
    }
}
foreach ($vehicleRows as $r) {
    echo smUrl('auction.php?id=' . $r['id'], $r['lastmod'], 'hourly', '0.8');
}

// ── News articles ────────────────────────────────────────────
try {
    $rows = $pdo->query("
        SELECT slug, COALESCE(updated_at, published_at) AS lastmod, post_type
        FROM news_posts
        WHERE is_active = 1
        ORDER BY published_at DESC
        LIMIT 1000
    ")->fetchAll();
    foreach ($rows as $r) {
        // Own articles rank higher than syndicated RSS items
        $prio = $r['post_type'] === 'rss' ? '0.4' : '0.7';
        echo smUrl('news-post.php?slug=' . rawurlencode($r['slug']), $r['lastmod'], 'monthly', $prio);
    }
} catch (Exception $e) { /* skip */ }

// ── Tag hub pages (only tags with ≥1 published article) ──────
try {
    $rows = $pdo->query("
        SELECT t.slug, MAX(p.published_at) AS lastmod
        FROM news_tags t
        JOIN news_post_tags pt ON pt.tag_id = t.id
        JOIN news_posts p      ON p.id = pt.post_id
        WHERE p.is_active = 1 AND p.published_at <= NOW()
        GROUP BY t.id
    ")->fetchAll();
    foreach ($rows as $r) {
        echo smUrl('tag.php?slug=' . rawurlencode($r['slug']), $r['lastmod'], 'weekly', '0.5');
    }
} catch (Exception $e) { /* news_tags not created yet — skip */ }

// ── Events (upcoming + live) ─────────────────────────────────
try {
    $rows = $pdo->query("
        SELECT id, updated_at FROM auction_events
        WHERE status IN ('upcoming','live')
        ORDER BY event_date ASC
    ")->fetchAll();
    foreach ($rows as $r) {
        echo smUrl('events.php?id=' . $r['id'], $r['updated_at'], 'daily', '0.7');
    }
} catch (Exception $e) { /* skip */ }

echo '</urlset>' . "\n";
