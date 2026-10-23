<?php
// ============================================================
// AUCTION HUB GROUP — feed.php
// Outbound RSS 2.0 feed of published articles.
// Served at /feed.xml (.htaccess rewrite).
// Submit this URL to Google Publisher Center and Opera News Hub.
// Only AHG's own content (article/review/news) — never syndicated RSS.
// ============================================================
// Swallow any stray UTF-8 BOM / whitespace from included files (a BOM before
// <?xml invalidates the feed for Google / Opera).
ob_start();
require_once __DIR__ . '/config.php';
ob_end_clean();

header('Content-Type: application/rss+xml; charset=utf-8');

$base  = CANONICAL_URL;
$build = date(DATE_RSS);

$items = [];
try {
    $items = $pdo->query("
        SELECT title, slug, excerpt, content, featured_image_url, published_at, source_name
        FROM news_posts
        WHERE is_active = 1 AND published_at <= NOW()
          AND post_type IN ('article','review','news')
        ORDER BY published_at DESC
        LIMIT 50
    ")->fetchAll();
} catch (Exception $e) { /* table missing — empty feed */ }

/** Wrap a value in CDATA, escaping any nested terminator. */
function rss_cdata($s): string {
    return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', (string)$s) . ']]>';
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"
     xmlns:atom="http://www.w3.org/2005/Atom"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
  <title>Auction Hub Group — Car News &amp; Buying Guides</title>
  <link><?= $base ?>/news.php</link>
  <atom:link href="<?= $base ?>/feed.xml" rel="self" type="application/rss+xml" />
  <description>Car buying guides, vehicle reviews and auction insights from Auction Hub Group, South Africa.</description>
  <language>en-za</language>
  <lastBuildDate><?= $build ?></lastBuildDate>
<?php foreach ($items as $it):
    $url = $base . '/news-post.php?slug=' . rawurlencode($it['slug']);
    $pub = $it['published_at'] ? date(DATE_RSS, strtotime($it['published_at'])) : $build;
?>
  <item>
    <title><?= rss_cdata($it['title']) ?></title>
    <link><?= htmlspecialchars($url, ENT_XML1) ?></link>
    <guid isPermaLink="true"><?= htmlspecialchars($url, ENT_XML1) ?></guid>
    <pubDate><?= $pub ?></pubDate>
<?php if (!empty($it['source_name'])): ?>
    <dc:creator><?= rss_cdata($it['source_name']) ?></dc:creator>
<?php endif; ?>
    <description><?= rss_cdata($it['excerpt']) ?></description>
    <content:encoded><?= rss_cdata($it['content']) ?></content:encoded>
<?php if (!empty($it['featured_image_url'])): ?>
    <enclosure url="<?= htmlspecialchars($it['featured_image_url'], ENT_XML1) ?>" type="image/jpeg" />
<?php endif; ?>
  </item>
<?php endforeach; ?>
</channel>
</rss>
