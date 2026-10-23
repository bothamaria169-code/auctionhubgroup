<?php
// ============================================================
// AUCTION HUB GROUP — news-sitemap.php
// Google News sitemap: ONLY articles published in the last 48 hours.
// Served at /news-sitemap.xml (.htaccess rewrite).
// Add this URL in Search Console once you have a Publisher Center
// publication. Max 1000 URLs; articles older than 2 days must be removed
// (this query handles that automatically).
// Ref: https://support.google.com/news/publisher-center/answer/9606710
// ============================================================
// Swallow any stray UTF-8 BOM / whitespace from included files (a BOM before
// <?xml invalidates the sitemap for Google News).
ob_start();
require_once __DIR__ . '/config.php';
ob_end_clean();

header('Content-Type: application/xml; charset=utf-8');

$base = CANONICAL_URL;

$items = [];
try {
    $items = $pdo->query("
        SELECT title, slug, published_at
        FROM news_posts
        WHERE is_active = 1
          AND post_type IN ('article','review','news')
          AND published_at <= NOW()
          AND published_at >= (NOW() - INTERVAL 48 HOUR)
        ORDER BY published_at DESC
        LIMIT 1000
    ")->fetchAll();
} catch (Exception $e) { /* table missing — empty sitemap */ }

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
<?php foreach ($items as $it):
    $url = $base . '/news-post.php?slug=' . rawurlencode($it['slug']);
    $pubDate = date('c', strtotime($it['published_at']));
?>
  <url>
    <loc><?= htmlspecialchars($url, ENT_XML1) ?></loc>
    <news:news>
      <news:publication>
        <news:name>Auction Hub Group</news:name>
        <news:language>en</news:language>
      </news:publication>
      <news:publication_date><?= $pubDate ?></news:publication_date>
      <news:title><?= htmlspecialchars($it['title'], ENT_XML1) ?></news:title>
    </news:news>
  </url>
<?php endforeach; ?>
</urlset>
