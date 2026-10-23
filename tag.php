<?php
// ============================================================
// AUCTION HUB GROUP — tag.php
// Tag hub page: lists every published article carrying a tag.
// Powers the "hub & spoke" internal-linking model for SEO.
//   /tag.php?slug=repossessed
// ============================================================
require_once 'config.php';

$slug = trim($_GET['slug'] ?? $_GET['tag'] ?? '');
if ($slug === '') { header('Location: ' . APP_URL . '/news.php'); exit; }

// Fetch the tag (tables may not exist yet on a fresh install)
$tag = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM news_tags WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $tag = $stmt->fetch();
} catch (Exception $e) { /* news_tags not created yet */ }

if (!$tag) { header('Location: ' . APP_URL . '/news.php'); exit; }

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

// Count + fetch posts with this tag
$cnt = $pdo->prepare("
    SELECT COUNT(*)
    FROM news_post_tags pt
    JOIN news_posts p ON p.id = pt.post_id
    WHERE pt.tag_id = ? AND p.is_active = 1 AND p.published_at <= NOW()
");
$cnt->execute([$tag['id']]);
$total      = (int)$cnt->fetchColumn();
$totalPages = (int)ceil($total / $perPage);

$postStmt = $pdo->prepare("
    SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, c.color AS cat_color, c.icon AS cat_icon,
           u.full_name AS author_name
    FROM news_post_tags pt
    JOIN news_posts p        ON p.id = pt.post_id
    LEFT JOIN news_categories c ON p.category_id = c.id
    LEFT JOIN users u        ON p.author_id = u.id
    WHERE pt.tag_id = ? AND p.is_active = 1 AND p.published_at <= NOW()
    ORDER BY p.published_at DESC
    LIMIT $perPage OFFSET $offset
");
$postStmt->execute([$tag['id']]);
$posts = $postStmt->fetchAll();

// Related tags (a small tag cloud → more internal links)
$relTags = $pdo->query("
    SELECT t.name, t.slug, COUNT(pt.post_id) AS n
    FROM news_tags t
    JOIN news_post_tags pt ON pt.tag_id = t.id
    JOIN news_posts p      ON p.id = pt.post_id AND p.is_active = 1
    GROUP BY t.id
    ORDER BY n DESC
    LIMIT 24
")->fetchAll();

$pageTitle       = htmlspecialchars($tag['name']) . ' — Auction & Car Buying Articles';
$pageDescription = 'Articles, buying guides and car reviews tagged "' . htmlspecialchars($tag['name']) .
                   '" from Auction Hub Group — South Africa\'s DEKRA-inspected vehicle auctions.';
$bodyClass       = 'page-tag';
$canonicalUrl    = CANONICAL_URL . '/tag.php?slug=' . rawurlencode($slug);

require_once 'header.php';

function tagPostUrl(array $p): string { return APP_URL . '/news-post.php?slug=' . urlencode($p['slug']); }
function tagPostImage(array $p): string {
    if (!empty($p['featured_image_url'])) return htmlspecialchars($p['featured_image_url']);
    return 'https://placehold.co/800x450/001F5C/fff?text=' . urlencode(mb_substr($p['title'], 0, 20));
}
function tagTimeAgo(?string $ts): string {
    if (empty($ts)) return '—';
    $t = strtotime($ts);
    if ($t === false || $t <= 0) return '—';
    return date('d M Y', $t);
}
?>

<!-- Hero -->
<section style="background:linear-gradient(135deg,#001F5C 0%,#003f73 50%,#005BA1 100%);padding:3.5rem 1.5rem 2.75rem;text-align:center;">
    <div class="container">
        <div style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);border-radius:50px;padding:.4rem 1.25rem;margin-bottom:1rem;">
            <i class="fas fa-tag" style="color:#ffd54f;font-size:.8rem;"></i>
            <span style="color:rgba(255,255,255,.9);font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;">Tag</span>
        </div>
        <h1 style="color:#fff;font-size:clamp(1.6rem,3.5vw,2.4rem);margin-bottom:.5rem;font-weight:800;">
            <?= htmlspecialchars($tag['name']) ?>
        </h1>
        <p style="color:rgba(255,255,255,.78);font-size:.95rem;margin:0;">
            <?= $total ?> article<?= $total !== 1 ? 's' : '' ?> ·
            <a href="<?= APP_URL ?>/news.php" style="color:#ffd54f;text-decoration:none;">All news &amp; reviews</a>
        </p>
    </div>
</section>

<div class="container" style="padding:2.5rem 1.5rem 4rem;">

<?php if (empty($posts)): ?>
<div style="text-align:center;padding:4rem 1rem;color:#999;">
    <i class="fas fa-tag" style="font-size:3rem;color:#dde3ec;margin-bottom:1rem;display:block;"></i>
    <h3 style="color:#334155;margin-bottom:.5rem;">No articles with this tag yet</h3>
    <p style="font-size:.92rem;"><a href="<?= APP_URL ?>/news.php" style="color:#005BA1;">Browse all articles →</a></p>
</div>
<?php else: ?>

<!-- Post Grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.5rem;margin-bottom:2.5rem;">
    <?php foreach ($posts as $post): ?>
    <article style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(0,31,92,.08);border:1px solid #eef1f5;display:flex;flex-direction:column;">
        <a href="<?= tagPostUrl($post) ?>" style="display:block;aspect-ratio:16/9;overflow:hidden;">
            <img src="<?= tagPostImage($post) ?>" alt="<?= htmlspecialchars($post['title']) ?>"
                 style="width:100%;height:100%;object-fit:cover;" loading="lazy">
        </a>
        <div style="padding:1.25rem;flex:1;display:flex;flex-direction:column;">
            <?php if ($post['cat_name']): ?>
            <a href="<?= APP_URL ?>/news.php?cat=<?= urlencode($post['cat_slug']) ?>"
               style="display:inline-flex;align-items:center;gap:.3rem;font-size:.72rem;font-weight:700;color:<?= htmlspecialchars($post['cat_color'] ?? '#005DDB') ?>;text-decoration:none;margin-bottom:.6rem;text-transform:uppercase;letter-spacing:.07em;">
                <i class="fas <?= htmlspecialchars($post['cat_icon'] ?? 'fa-tag') ?>"></i>
                <?= htmlspecialchars($post['cat_name']) ?>
            </a>
            <?php endif; ?>
            <h3 style="font-size:1.02rem;font-weight:700;color:#1a2a40;line-height:1.4;margin-bottom:.6rem;flex:1;">
                <a href="<?= tagPostUrl($post) ?>" style="text-decoration:none;color:inherit;">
                    <?= htmlspecialchars($post['title']) ?>
                </a>
            </h3>
            <?php if ($post['excerpt']): ?>
            <p style="color:#667;font-size:.84rem;line-height:1.6;margin-bottom:.85rem;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">
                <?= htmlspecialchars($post['excerpt']) ?>
            </p>
            <?php endif; ?>
            <div style="font-size:.75rem;color:#999;border-top:1px solid #f0f4f8;padding-top:.75rem;margin-top:auto;">
                <?= tagTimeAgo($post['published_at']) ?> · <i class="fas fa-eye"></i> <?= number_format($post['view_count']) ?>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:center;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:2.5rem;">
    <?php if ($page > 1): ?>
    <a href="<?= APP_URL ?>/tag.php?slug=<?= urlencode($slug) ?>&page=<?= $page-1 ?>" style="padding:.5rem .9rem;border-radius:8px;border:1px solid #dde3ec;color:#334155;text-decoration:none;font-size:.88rem;">← Prev</a>
    <?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
    <a href="<?= APP_URL ?>/tag.php?slug=<?= urlencode($slug) ?>&page=<?= $i ?>"
       style="padding:.5rem .9rem;border-radius:8px;border:2px solid;font-size:.88rem;font-weight:600;text-decoration:none;
              <?= $i===$page ? 'background:#001F5C;color:#fff;border-color:#001F5C' : 'color:#334155;border-color:#dde3ec;background:#fff' ?>">
        <?= $i ?>
    </a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
    <a href="<?= APP_URL ?>/tag.php?slug=<?= urlencode($slug) ?>&page=<?= $page+1 ?>" style="padding:.5rem .9rem;border-radius:8px;border:1px solid #dde3ec;color:#334155;text-decoration:none;font-size:.88rem;">Next →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Related tags (internal-link hub) -->
<?php if (!empty($relTags)): ?>
<div style="border-top:1px solid #eef1f5;padding-top:2rem;">
    <h2 style="font-size:1rem;font-weight:700;color:#001F5C;margin-bottom:1rem;">
        <i class="fas fa-tags" style="color:#e8a000;"></i> Browse by tag
    </h2>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
        <?php foreach ($relTags as $rt): ?>
        <a href="<?= APP_URL ?>/tag.php?slug=<?= urlencode($rt['slug']) ?>"
           style="display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .85rem;border-radius:50px;font-size:.8rem;font-weight:600;text-decoration:none;border:1.5px solid;
                  <?= $rt['slug']===$slug ? 'background:#001F5C;color:#fff;border-color:#001F5C' : 'color:#334155;border-color:#dde3ec;background:#fff' ?>">
            #<?= htmlspecialchars($rt['name']) ?>
            <span style="opacity:.6;font-weight:500;">(<?= (int)$rt['n'] ?>)</span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

</div><!-- /.container -->

<?php require_once 'footer.php'; ?>
