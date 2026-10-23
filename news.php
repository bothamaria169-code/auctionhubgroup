<?php
// ============================================================
// AUCTION HUB GROUP — news.php
// News, Car Reviews & Industry Articles listing page
// ============================================================

$pageTitle       = 'Car News, Reviews & Auction Buying Guides';
$pageDescription = 'Latest car news, vehicle reviews, industry updates, and auction insights from Auction Hub Group — South Africa\'s trusted repossessed vehicle auctioneers.';
$bodyClass       = 'page-news';

require_once 'config.php';
require_once 'vehicle_strip.php';

// ── Auto-create required tables ──────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS news_categories (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(100) NOT NULL,
        slug         VARCHAR(100) NOT NULL UNIQUE,
        description  TEXT DEFAULT NULL,
        color        VARCHAR(20) DEFAULT '#005DDB',
        icon         VARCHAR(60) DEFAULT 'fa-newspaper',
        sort_order   INT NOT NULL DEFAULT 0,
        is_active    TINYINT(1) NOT NULL DEFAULT 1,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS news_posts (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        title             VARCHAR(300) NOT NULL,
        slug              VARCHAR(300) NOT NULL UNIQUE,
        excerpt           TEXT DEFAULT NULL,
        content           LONGTEXT DEFAULT NULL,
        featured_image_url VARCHAR(600) DEFAULT NULL,
        video_url          VARCHAR(600) DEFAULT NULL,
        category_id       INT DEFAULT NULL,
        post_type         ENUM('article','review','news','rss') NOT NULL DEFAULT 'article',
        source_url        VARCHAR(600) DEFAULT NULL,
        source_name       VARCHAR(150) DEFAULT NULL,
        author_id         INT DEFAULT NULL,
        is_active         TINYINT(1) NOT NULL DEFAULT 1,
        is_featured       TINYINT(1) NOT NULL DEFAULT 0,
        allow_comments    TINYINT(1) NOT NULL DEFAULT 1,
        meta_title        VARCHAR(200) DEFAULT NULL,
        meta_description  VARCHAR(350) DEFAULT NULL,
        view_count        INT NOT NULL DEFAULT 0,
        comment_count     INT NOT NULL DEFAULT 0,
        published_at      TIMESTAMP NULL DEFAULT NULL,
        created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES news_categories(id) ON DELETE SET NULL,
        FOREIGN KEY (author_id)   REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_slug (slug),
        INDEX idx_pub  (published_at),
        INDEX idx_type (post_type),
        INDEX idx_feat (is_featured)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Migration: add video_url if this is an older install
    $cols = $pdo->query("SHOW COLUMNS FROM news_posts LIKE 'video_url'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE news_posts ADD COLUMN video_url VARCHAR(600) DEFAULT NULL AFTER featured_image_url");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS news_comments (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        post_id     INT NOT NULL,
        user_id     INT NOT NULL,
        content     TEXT NOT NULL,
        is_approved TINYINT(1) NOT NULL DEFAULT 1,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES news_posts(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_post (post_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rss_feeds (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        name                 VARCHAR(150) NOT NULL,
        feed_url             VARCHAR(600) NOT NULL,
        category_id          INT DEFAULT NULL,
        is_active            TINYINT(1) NOT NULL DEFAULT 1,
        fetch_interval_hours INT NOT NULL DEFAULT 6,
        last_fetched_at      TIMESTAMP NULL DEFAULT NULL,
        last_error           TEXT DEFAULT NULL,
        items_imported       INT NOT NULL DEFAULT 0,
        created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES news_categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        email               VARCHAR(200) NOT NULL UNIQUE,
        name                VARCHAR(150) DEFAULT NULL,
        is_verified         TINYINT(1) NOT NULL DEFAULT 0,
        verification_token  VARCHAR(64) DEFAULT NULL,
        subscribed_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        unsubscribed_at     TIMESTAMP NULL DEFAULT NULL,
        is_active           TINYINT(1) NOT NULL DEFAULT 1,
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS newsletter_campaigns (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        subject          VARCHAR(300) NOT NULL,
        preview_text     VARCHAR(200) DEFAULT NULL,
        content_html     LONGTEXT NOT NULL,
        is_sent          TINYINT(1) NOT NULL DEFAULT 0,
        sent_at          TIMESTAMP NULL DEFAULT NULL,
        recipients_count INT NOT NULL DEFAULT 0,
        created_by       INT DEFAULT NULL,
        created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default categories if none exist
    $catCount = $pdo->query("SELECT COUNT(*) FROM news_categories")->fetchColumn();
    if ((int)$catCount === 0) {
        $pdo->exec("INSERT INTO news_categories (name,slug,description,color,icon,sort_order) VALUES
            ('Car Reviews','car-reviews','In-depth reviews of vehicles available at AHG auctions','#005DDB','fa-star',1),
            ('Auction News','auction-news','Updates and announcements from Auction Hub Group','#001F5C','fa-gavel',2),
            ('Industry News','industry-news','South African automotive industry news and trends','#e8a000','fa-newspaper',3),
            ('Buying Guides','buying-guides','Tips, advice and guides for buying repossessed vehicles','#4caf50','fa-book-open',4),
            ('Finance Tips','finance-tips','Vehicle finance advice and in-house finance updates','#9c27b0','fa-piggy-bank',5)
        ");
    }

    // Seed default RSS feeds if none exist
    $rssCount = $pdo->query("SELECT COUNT(*) FROM rss_feeds")->fetchColumn();
    if ((int)$rssCount === 0) {
        $indId = $pdo->query("SELECT id FROM news_categories WHERE slug='industry-news'")->fetchColumn();
        if ($indId) {
            $ins = $pdo->prepare("INSERT INTO rss_feeds (name,feed_url,category_id,is_active) VALUES (?,?,?,1)");
            $ins->execute(['Wheels24','https://www.wheels24.co.za/feed/',$indId]);
            $ins->execute(['Autocar UK','https://www.autocar.co.uk/rss',$indId]);
        }
    }
} catch (Exception $e) { /* Tables exist */ }

require_once 'header.php';

// ── Query parameters ─────────────────────────────────────────
$selectedCat  = trim($_GET['cat'] ?? '');
$selectedType = trim($_GET['type'] ?? '');
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 12;
$offset       = ($page - 1) * $perPage;

// ── Fetch categories ──────────────────────────────────────────
$categories = $pdo->query("SELECT * FROM news_categories WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();

// ── Build post query ──────────────────────────────────────────
$where  = ['p.is_active = 1', 'p.published_at <= NOW()'];
$params = [];

if ($selectedCat) {
    $where[] = 'c.slug = ?';
    $params[] = $selectedCat;
}
if ($selectedType) {
    $where[] = 'p.post_type = ?';
    $params[] = $selectedType;
}
if ($search) {
    $where[] = '(p.title LIKE ? OR p.excerpt LIKE ? OR p.content LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Featured post (first one if no filters active)
$featuredPost = null;
if (!$selectedCat && !$selectedType && !$search && $page === 1) {
    $fStmt = $pdo->prepare("
        SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, c.color AS cat_color, c.icon AS cat_icon,
               u.full_name AS author_name
        FROM news_posts p
        LEFT JOIN news_categories c ON p.category_id = c.id
        LEFT JOIN users u ON p.author_id = u.id
        WHERE p.is_active=1 AND p.is_featured=1 AND p.published_at <= NOW()
        ORDER BY p.published_at DESC
        LIMIT 1
    ");
    $fStmt->execute();
    $featuredPost = $fStmt->fetch() ?: null;
}

// Total count
$cntStmt = $pdo->prepare("
    SELECT COUNT(*) FROM news_posts p
    LEFT JOIN news_categories c ON p.category_id = c.id
    $whereSQL
");
$cntStmt->execute($params);
$total     = (int)$cntStmt->fetchColumn();
$totalPages= (int)ceil($total / $perPage);

// Posts
$postStmt = $pdo->prepare("
    SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, c.color AS cat_color, c.icon AS cat_icon,
           u.full_name AS author_name
    FROM news_posts p
    LEFT JOIN news_categories c ON p.category_id = c.id
    LEFT JOIN users u ON p.author_id = u.id
    $whereSQL
    ORDER BY p.published_at DESC
    LIMIT $perPage OFFSET $offset
");
$postStmt->execute($params);
$posts = $postStmt->fetchAll();

// Increment view count helper
function postUrl(array $p): string {
    return APP_URL . '/news-post.php?slug=' . urlencode($p['slug']);
}
function postImage(array $p, string $size='800x450'): string {
    if ($p['featured_image_url']) return htmlspecialchars($p['featured_image_url']);
    return 'https://placehold.co/' . $size . '/001F5C/fff?text=' . urlencode(mb_substr($p['title'],0,20));
}
function timeAgo(?string $ts): string {
    if (empty($ts)) return '—';
    $t = strtotime($ts);
    if ($t === false || $t <= 0) return '—';
    $diff = time() - $t;
    if ($diff < 60)      return 'Just now';
    if ($diff < 3600)    return floor($diff/60) . 'm ago';
    if ($diff < 86400)   return floor($diff/3600) . 'h ago';
    if ($diff < 604800)  return floor($diff/86400) . 'd ago';
    return date('d M Y', $t);
}
function readingTime(string $content): string {
    $words = str_word_count(strip_tags($content));
    $mins  = max(1, (int)round($words / 200));
    return $mins . ' min read';
}
?>

<!-- Page meta for SEO -->
<?php if ($search): ?>
<title>Search: <?= htmlspecialchars($search) ?> — AHG News</title>
<?php endif; ?>

<!-- Hero -->
<section style="background:linear-gradient(135deg,#001F5C 0%,#003f73 50%,#005BA1 100%);padding:4rem 1.5rem 3rem;text-align:center;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.04) 1px,transparent 1px);background-size:24px 24px;pointer-events:none;"></div>
    <div class="container" style="position:relative;z-index:1;">
        <div style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);border-radius:50px;padding:.4rem 1.25rem;margin-bottom:1.25rem;">
            <i class="fas fa-newspaper" style="color:#ffd54f;font-size:.8rem;"></i>
            <span style="color:rgba(255,255,255,.9);font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;">News &amp; Reviews</span>
        </div>
        <h1 style="color:#fff;font-size:clamp(1.7rem,3.5vw,2.5rem);margin-bottom:.75rem;font-weight:800;">
            Car News &amp; Reviews
        </h1>
        <p style="color:rgba(255,255,255,.78);font-size:1rem;max-width:580px;margin:0 auto 1.75rem;line-height:1.7;">
            Expert reviews, industry news, buying guides, and auction insights — everything you need to make a smart vehicle purchase.
        </p>
        <!-- Search -->
        <form method="GET" style="max-width:480px;margin:0 auto;display:flex;gap:.5rem;">
            <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Search articles..."
                   style="flex:1;padding:.75rem 1.25rem;border-radius:50px;border:none;font-size:.95rem;background:rgba(255,255,255,.95);color:#001F5C;outline:none;">
            <button type="submit" style="background:#ffd54f;color:#001F5C;border:none;padding:.75rem 1.5rem;border-radius:50px;font-weight:700;cursor:pointer;font-size:.9rem;white-space:nowrap;">
                <i class="fas fa-search"></i> Search
            </button>
        </form>
    </div>
</section>

<!-- Category Filter -->
<div style="background:#fff;border-bottom:1px solid #e8edf2;position:sticky;top:var(--nav-height,72px);z-index:100;">
    <div class="container" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <div style="display:flex;gap:.5rem;padding:.75rem 0;white-space:nowrap;align-items:center;">
            <a href="<?= APP_URL ?>/news.php<?= $search ? '?q='.urlencode($search) : '' ?>"
               style="display:inline-flex;align-items:center;gap:.4rem;padding:.4rem 1rem;border-radius:50px;font-size:.82rem;font-weight:600;text-decoration:none;border:2px solid;transition:.15s;
                      <?= !$selectedCat&&!$selectedType ? 'background:#001F5C;color:#fff;border-color:#001F5C' : 'color:#334155;border-color:#dde3ec;background:#fff' ?>">
                <i class="fas fa-th-large"></i> All
            </a>
            <?php foreach ($categories as $cat): ?>
            <a href="<?= APP_URL ?>/news.php?cat=<?= urlencode($cat['slug']) ?><?= $search?'&q='.urlencode($search):'' ?>"
               style="display:inline-flex;align-items:center;gap:.4rem;padding:.4rem 1rem;border-radius:50px;font-size:.82rem;font-weight:600;text-decoration:none;border:2px solid;transition:.15s;
                      <?= $selectedCat===$cat['slug'] ? 'background:'.$cat['color'].';color:#fff;border-color:'.$cat['color'] : 'color:#334155;border-color:#dde3ec;background:#fff' ?>">
                <i class="fas <?= htmlspecialchars($cat['icon']) ?>"></i>
                <?= htmlspecialchars($cat['name']) ?>
            </a>
            <?php endforeach; ?>
            <a href="<?= APP_URL ?>/news.php?type=rss<?= $search?'&q='.urlencode($search):'' ?>"
               style="display:inline-flex;align-items:center;gap:.4rem;padding:.4rem 1rem;border-radius:50px;font-size:.82rem;font-weight:600;text-decoration:none;border:2px solid;transition:.15s;
                      <?= $selectedType==='rss' ? 'background:#e8a000;color:#fff;border-color:#e8a000' : 'color:#334155;border-color:#dde3ec;background:#fff' ?>">
                <i class="fas fa-rss"></i> RSS Imports
            </a>
        </div>
    </div>
</div>

<div class="container" style="padding:2.5rem 1.5rem 4rem;">

<?php if ($search): ?>
<p style="color:#667;font-size:.9rem;margin-bottom:1.5rem;">
    <?= $total ?> result<?= $total!==1?'s':'' ?> for "<strong><?= htmlspecialchars($search) ?></strong>"
    — <a href="<?= APP_URL ?>/news.php" style="color:#005BA1;">Clear search</a>
</p>
<?php endif; ?>

<?php if ($featuredPost): ?>
<!-- Featured Post -->
<div style="margin-bottom:2.5rem;border-radius:20px;overflow:hidden;box-shadow:0 8px 32px rgba(0,31,92,.12);display:grid;grid-template-columns:1fr 1fr;min-height:380px;" class="featured-post-grid">
    <div style="position:relative;overflow:hidden;min-height:280px;">
        <img src="<?= postImage($featuredPost,'1200x700') ?>"
             alt="<?= htmlspecialchars($featuredPost['title']) ?>"
             style="width:100%;height:100%;object-fit:cover;display:block;transition:transform .4s;"
             loading="lazy"
             onmouseover="this.style.transform='scale(1.04)'"
             onmouseout="this.style.transform='scale(1)'">
        <div style="position:absolute;inset:0;background:linear-gradient(to right,rgba(0,0,0,.4),transparent);pointer-events:none;"></div>
        <span style="position:absolute;top:1.25rem;left:1.25rem;background:#ffd54f;color:#001F5C;font-size:.72rem;font-weight:800;padding:.3rem .9rem;border-radius:50px;letter-spacing:.08em;text-transform:uppercase;">
            <i class="fas fa-star"></i> Featured
        </span>
    </div>
    <div style="background:#fff;padding:2.5rem;display:flex;flex-direction:column;justify-content:center;">
        <?php if ($featuredPost['cat_name']): ?>
        <span style="display:inline-flex;align-items:center;gap:.4rem;background:<?= htmlspecialchars($featuredPost['cat_color']) ?>18;color:<?= htmlspecialchars($featuredPost['cat_color']) ?>;font-size:.75rem;font-weight:700;padding:.3rem .85rem;border-radius:50px;margin-bottom:1rem;width:fit-content;border:1px solid <?= htmlspecialchars($featuredPost['cat_color']) ?>40;">
            <i class="fas <?= htmlspecialchars($featuredPost['cat_icon']??'fa-tag') ?>"></i>
            <?= htmlspecialchars($featuredPost['cat_name']) ?>
        </span>
        <?php endif; ?>
        <h2 style="font-size:1.5rem;font-weight:800;color:#001F5C;line-height:1.3;margin-bottom:.75rem;">
            <a href="<?= postUrl($featuredPost) ?>" style="text-decoration:none;color:inherit;transition:color .15s;" onmouseover="this.style.color='#005DDB'" onmouseout="this.style.color='#001F5C'">
                <?= htmlspecialchars($featuredPost['title']) ?>
            </a>
        </h2>
        <?php if ($featuredPost['excerpt']): ?>
        <p style="color:#667;line-height:1.7;font-size:.95rem;margin-bottom:1.25rem;"><?= htmlspecialchars(mb_substr($featuredPost['excerpt'],0,200)) ?>...</p>
        <?php endif; ?>
        <div style="display:flex;align-items:center;gap:1rem;font-size:.8rem;color:#999;margin-bottom:1.25rem;flex-wrap:wrap;">
            <?php if ($featuredPost['author_name']): ?>
            <span><i class="fas fa-user" style="margin-right:.3rem;color:#005DDB;"></i><?= htmlspecialchars($featuredPost['author_name']) ?></span>
            <?php endif; ?>
            <span><i class="fas fa-clock" style="margin-right:.3rem;color:#005DDB;"></i><?= timeAgo($featuredPost['published_at']) ?></span>
            <span><i class="fas fa-book-open" style="margin-right:.3rem;color:#005DDB;"></i><?= readingTime($featuredPost['content']??'') ?></span>
            <span><i class="fas fa-eye" style="margin-right:.3rem;color:#005DDB;"></i><?= number_format($featuredPost['view_count']) ?> views</span>
        </div>
        <a href="<?= postUrl($featuredPost) ?>" style="display:inline-flex;align-items:center;gap:.5rem;background:linear-gradient(135deg,#001F5C,#005DDB);color:#fff;padding:.75rem 1.5rem;border-radius:50px;font-weight:700;font-size:.88rem;text-decoration:none;width:fit-content;transition:opacity .2s;" onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
            Read Article <i class="fas fa-arrow-right"></i>
        </a>
    </div>
</div>
<?php endif; ?>

<?php if (empty($posts) && !$featuredPost): ?>
<!-- Empty state -->
<div style="text-align:center;padding:4rem 1rem;color:#999;">
    <i class="fas fa-newspaper" style="font-size:3rem;color:#dde3ec;margin-bottom:1rem;display:block;"></i>
    <h3 style="color:#334155;margin-bottom:.5rem;">No articles yet</h3>
    <p style="font-size:.92rem;">Check back soon — new car news and reviews are added regularly.</p>
</div>
<?php elseif (!empty($posts)): ?>

<?php if ($featuredPost): ?>
<h2 style="font-size:1.1rem;font-weight:700;color:#001F5C;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;">
    <i class="fas fa-fire" style="color:#e8a000;"></i>
    <?= $selectedCat ? htmlspecialchars(array_column($categories,'name','slug')[$selectedCat]??'Latest') : 'Latest' ?> Articles
</h2>
<?php endif; ?>

<!-- Post Grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.5rem;margin-bottom:2.5rem;">
    <?php foreach ($posts as $post): ?>
    <article style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(0,31,92,.08);border:1px solid #eef1f5;transition:transform .2s,box-shadow .2s;display:flex;flex-direction:column;"
             onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 28px rgba(0,31,92,.15)'"
             onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 2px 12px rgba(0,31,92,.08)'">
        <!-- Image -->
        <a href="<?= postUrl($post) ?>" style="display:block;aspect-ratio:16/9;overflow:hidden;position:relative;">
            <img src="<?= postImage($post,'800x450') ?>"
                 alt="<?= htmlspecialchars($post['title']) ?>"
                 style="width:100%;height:100%;object-fit:cover;transition:transform .3s;"
                 loading="lazy"
                 onmouseover="this.style.transform='scale(1.05)'"
                 onmouseout="this.style.transform='scale(1)'">
            <?php if (!empty($post['video_url'])): ?>
            <span style="position:absolute;top:.75rem;left:.75rem;background:rgba(0,0,0,.75);color:#fff;font-size:.7rem;font-weight:700;padding:.25rem .65rem;border-radius:4px;display:flex;align-items:center;gap:.35rem;backdrop-filter:blur(4px);">
                <i class="fas fa-play-circle" style="color:#ff4d4d;"></i> Video
            </span>
            <?php endif; ?>
            <?php if ($post['post_type'] === 'rss'): ?>
            <span style="position:absolute;top:.75rem;right:.75rem;background:rgba(0,0,0,.7);color:#fff;font-size:.65rem;font-weight:700;padding:.2rem .6rem;border-radius:4px;letter-spacing:.06em;text-transform:uppercase;backdrop-filter:blur(4px);">
                <i class="fas fa-rss"></i> <?= htmlspecialchars($post['source_name']??'RSS') ?>
            </span>
            <?php elseif ($post['post_type'] === 'review'): ?>
            <span style="position:absolute;top:.75rem;right:.75rem;background:linear-gradient(135deg,#e8a000,#ffcc02);color:#001F5C;font-size:.65rem;font-weight:800;padding:.2rem .6rem;border-radius:4px;letter-spacing:.06em;text-transform:uppercase;">
                <i class="fas fa-star"></i> Review
            </span>
            <?php endif; ?>
        </a>
        <!-- Body -->
        <div style="padding:1.25rem;flex:1;display:flex;flex-direction:column;">
            <?php if ($post['cat_name']): ?>
            <a href="<?= APP_URL ?>/news.php?cat=<?= urlencode($post['cat_slug']) ?>"
               style="display:inline-flex;align-items:center;gap:.3rem;font-size:.72rem;font-weight:700;color:<?= htmlspecialchars($post['cat_color']) ?>;text-decoration:none;margin-bottom:.6rem;text-transform:uppercase;letter-spacing:.07em;">
                <i class="fas <?= htmlspecialchars($post['cat_icon']??'fa-tag') ?>"></i>
                <?= htmlspecialchars($post['cat_name']) ?>
            </a>
            <?php endif; ?>
            <h3 style="font-size:1.02rem;font-weight:700;color:#1a2a40;line-height:1.4;margin-bottom:.6rem;flex:1;">
                <a href="<?= postUrl($post) ?>" style="text-decoration:none;color:inherit;transition:color .15s;" onmouseover="this.style.color='#005DDB'" onmouseout="this.style.color='inherit'">
                    <?= htmlspecialchars($post['title']) ?>
                </a>
            </h3>
            <?php if ($post['excerpt']): ?>
            <p style="color:#667;font-size:.84rem;line-height:1.6;margin-bottom:.85rem;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">
                <?= htmlspecialchars($post['excerpt']) ?>
            </p>
            <?php endif; ?>
            <div style="display:flex;align-items:center;justify-content:space-between;font-size:.75rem;color:#999;border-top:1px solid #f0f4f8;padding-top:.75rem;margin-top:auto;">
                <span><?= timeAgo($post['published_at']) ?></span>
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <span><i class="fas fa-eye"></i> <?= number_format($post['view_count']) ?></span>
                    <?php if ($post['allow_comments']): ?>
                    <span><i class="fas fa-comment"></i> <?= $post['comment_count'] ?></span>
                    <?php endif; ?>
                    <span><i class="fas fa-book-open"></i> <?= readingTime($post['content']??'') ?></span>
                </div>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:center;align-items:center;gap:.5rem;flex-wrap:wrap;margin-top:1rem;">
    <?php
    $qBase = '?';
    if ($selectedCat)  $qBase .= 'cat='  . urlencode($selectedCat)  . '&';
    if ($selectedType) $qBase .= 'type=' . urlencode($selectedType) . '&';
    if ($search)       $qBase .= 'q='    . urlencode($search)       . '&';
    ?>
    <?php if ($page > 1): ?>
    <a href="<?= APP_URL ?>/news.php<?= $qBase ?>page=<?= $page-1 ?>" style="padding:.5rem .9rem;border-radius:8px;border:1px solid #dde3ec;color:#334155;text-decoration:none;font-size:.88rem;">← Prev</a>
    <?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
    <a href="<?= APP_URL ?>/news.php<?= $qBase ?>page=<?= $i ?>"
       style="padding:.5rem .9rem;border-radius:8px;border:2px solid;font-size:.88rem;font-weight:600;text-decoration:none;
              <?= $i===$page ? 'background:#001F5C;color:#fff;border-color:#001F5C' : 'color:#334155;border-color:#dde3ec;background:#fff' ?>">
        <?= $i ?>
    </a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
    <a href="<?= APP_URL ?>/news.php<?= $qBase ?>page=<?= $page+1 ?>" style="padding:.5rem .9rem;border-radius:8px;border:1px solid #dde3ec;color:#334155;text-decoration:none;font-size:.88rem;">Next →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Live stock — sends readers from the article index into the auction funnel -->
<?= renderVehicleStrip($pdo, [
    'limit'   => 3,
    'heading' => 'While you are reading, these are on the floor',
    'sub'     => 'DEKRA-inspected bank stock, priced at the outstanding balance. Registration is free.',
]) ?>

<!-- Newsletter Subscribe -->
<div style="background:linear-gradient(135deg,#001F5C,#005DDB);border-radius:20px;padding:2.5rem;text-align:center;margin-top:3rem;position:relative;overflow:hidden;">
    <div style="position:absolute;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.04) 1px,transparent 1px);background-size:20px 20px;pointer-events:none;"></div>
    <div style="position:relative;z-index:1;">
        <i class="fas fa-envelope-open-text" style="font-size:2rem;color:#ffd54f;margin-bottom:.75rem;display:block;"></i>
        <h3 style="color:#fff;font-size:1.3rem;font-weight:800;margin-bottom:.5rem;">Stay in the Loop</h3>
        <p style="color:rgba(255,255,255,.75);margin-bottom:1.5rem;max-width:480px;margin-left:auto;margin-right:auto;font-size:.92rem;">
            Get the latest car news, auction dates, and exclusive deals delivered to your inbox.
        </p>
        <form method="POST" action="<?= APP_URL ?>/api.php" id="newsletterForm" style="display:flex;gap:.5rem;max-width:440px;margin:0 auto;flex-wrap:wrap;">
            <input type="hidden" name="action" value="newsletter_subscribe">
            <input type="email" name="email" required placeholder="Your email address"
                   style="flex:1;min-width:200px;padding:.75rem 1.25rem;border-radius:50px;border:none;font-size:.92rem;color:#001F5C;background:rgba(255,255,255,.95);outline:none;">
            <button type="submit" style="background:#ffd54f;color:#001F5C;border:none;padding:.75rem 1.5rem;border-radius:50px;font-weight:700;font-size:.88rem;cursor:pointer;white-space:nowrap;transition:opacity .2s;" onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
                Subscribe
            </button>
        </form>
        <p style="color:rgba(255,255,255,.45);font-size:.75rem;margin-top:.75rem;">No spam. Unsubscribe anytime.</p>
        <div id="newsletterMsg" style="display:none;margin-top:.75rem;padding:.6rem 1.25rem;border-radius:8px;font-size:.88rem;font-weight:600;"></div>
    </div>
</div>

</div><!-- /.container -->

<style>
@media (max-width:700px){
    .featured-post-grid{grid-template-columns:1fr!important;min-height:auto!important;}
}
</style>
<script>
document.getElementById('newsletterForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const f   = e.target;
    const msg = document.getElementById('newsletterMsg');
    const btn = f.querySelector('button');
    btn.disabled = true;
    btn.textContent = 'Subscribing…';
    try {
        const r  = await fetch(f.action, {method:'POST', body: new FormData(f)});
        const d  = await r.json();
        msg.style.display = 'block';
        if (d.success) {
            msg.style.background = 'rgba(110,255,158,.2)';
            msg.style.color      = '#6eff9e';
            msg.textContent      = d.message || 'Subscribed! Check your email to confirm.';
            f.reset();
        } else {
            msg.style.background = 'rgba(255,107,107,.2)';
            msg.style.color      = '#ff9999';
            msg.textContent      = d.message || 'Something went wrong. Please try again.';
        }
    } catch {
        msg.style.display    = 'block';
        msg.style.background = 'rgba(255,107,107,.2)';
        msg.style.color      = '#ff9999';
        msg.textContent      = 'Network error. Please try again.';
    }
    btn.disabled    = false;
    btn.textContent = 'Subscribe';
});
</script>

<?php require_once 'footer.php'; ?>
