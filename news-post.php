<?php
// ============================================================
// AUCTION HUB GROUP — news-post.php
// Single news article / car review with comments
// ============================================================
require_once 'config.php';
require_once 'vehicle_strip.php';

$slug = trim($_GET['slug'] ?? '');
if (!$slug) { header('Location: ' . APP_URL . '/news.php'); exit; }

// Fetch post
$stmt = $pdo->prepare("
    SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, c.color AS cat_color, c.icon AS cat_icon,
           u.full_name AS author_name, u.email AS author_email
    FROM news_posts p
    LEFT JOIN news_categories c ON p.category_id = c.id
    LEFT JOIN users u ON p.author_id = u.id
    WHERE p.slug = ? AND p.is_active = 1 AND p.published_at <= NOW()
    LIMIT 1
");
$stmt->execute([$slug]);
$post = $stmt->fetch();

if (!$post) { header('Location: ' . APP_URL . '/news.php'); exit; }

// Increment view count
try { $pdo->prepare("UPDATE news_posts SET view_count = view_count+1 WHERE id=?")->execute([$post['id']]); } catch(Exception $e){}

$pageTitle       = $post['meta_title'] ?: $post['title']; // header.php appends the brand
$pageDescription = htmlspecialchars($post['meta_description'] ?: ($post['excerpt'] ?: ''));
$bodyClass       = 'page-news-post';
$canonicalUrl    = CANONICAL_URL . '/news-post.php?slug=' . rawurlencode($slug);

// Open Graph meta
$ogImage = $post['featured_image_url'] ?: '';
if ($ogImage) {
    $pageImage = preg_match('#^https?://#i', $ogImage) ? $ogImage : APP_URL . '/' . ltrim($ogImage, '/');
}

// Handle comment submission
$commentError = '';
$commentOk    = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_comment'])) {
    if (!isset($_SESSION['user_id'])) {
        $commentError = 'You must be logged in to comment.';
    } else {
        $commentContent = trim($_POST['comment_content'] ?? '');
        if (strlen($commentContent) < 3) {
            $commentError = 'Comment is too short.';
        } elseif (strlen($commentContent) > 2000) {
            $commentError = 'Comment exceeds 2000 characters.';
        } elseif (!$post['allow_comments']) {
            $commentError = 'Comments are disabled for this post.';
        } else {
            try {
                $ins = $pdo->prepare("INSERT INTO news_comments (post_id,user_id,content,is_approved) VALUES (?,?,?,1)");
                $ins->execute([$post['id'], $_SESSION['user_id'], $commentContent]);
                $pdo->prepare("UPDATE news_posts SET comment_count = comment_count+1 WHERE id=?")->execute([$post['id']]);
                $commentOk = true;
            } catch (Exception $e) {
                $commentError = 'Failed to post comment. Please try again.';
            }
        }
    }
}

// Fetch approved comments
$comments = $pdo->prepare("
    SELECT nc.*, u.full_name AS user_name
    FROM news_comments nc
    JOIN users u ON nc.user_id = u.id
    WHERE nc.post_id = ? AND nc.is_approved = 1
    ORDER BY nc.created_at ASC
");
$comments->execute([$post['id']]);
$comments = $comments->fetchAll();

// Related posts
$related = $pdo->prepare("
    SELECT p.*, c.color AS cat_color, c.name AS cat_name, c.icon AS cat_icon
    FROM news_posts p
    LEFT JOIN news_categories c ON p.category_id = c.id
    WHERE p.id != ? AND p.is_active=1 AND p.published_at<=NOW()
      AND (p.category_id = ? OR p.post_type = ?)
    ORDER BY p.published_at DESC
    LIMIT 3
");
$related->execute([$post['id'], $post['category_id'], $post['post_type']]);
$related = $related->fetchAll();

function timeAgoPost(?string $ts): string {
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
function postUrlSingle(array $p): string {
    return APP_URL . '/news-post.php?slug=' . urlencode($p['slug']);
}
?>
<!DOCTYPE html>
<html lang="en" prefix="og: http://ogp.me/ns#">
<head>
<?php // Extra OG/Twitter meta injected before header.php loads ?>
<meta property="og:title"       content="<?= htmlspecialchars($post['meta_title']?:$post['title']) ?>">
<meta property="og:description" content="<?= htmlspecialchars($post['meta_description']?:($post['excerpt']?:'')) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>"><?php endif; ?>
<meta property="og:type"        content="article">
<meta property="og:url"         content="<?= APP_URL ?>/news-post.php?slug=<?= urlencode($slug) ?>">
<meta name="twitter:card"       content="summary_large_image">
<!-- JSON-LD Article structured data -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "<?= $post['post_type']==='review'?'Review':'NewsArticle' ?>",
  "headline": <?= json_encode($post['title']) ?>,
  "description": <?= json_encode($post['excerpt']?:'') ?>,
  "datePublished": <?= json_encode($post['published_at']) ?>,
  "dateModified": <?= json_encode($post['updated_at']) ?>,
  "publisher": {
    "@type": "Organization",
    "name": "Auction Hub Group",
    "url": "<?= APP_URL ?>"
  }
  <?php if ($post['author_name']): ?>
  ,"author": {"@type": "Person","name": <?= json_encode($post['author_name']) ?>}
  <?php endif; ?>
  <?php if ($ogImage): ?>
  ,"image": <?= json_encode($ogImage) ?>
  <?php endif; ?>
  ,"mainEntityOfPage": {"@type": "WebPage", "@id": <?= json_encode($canonicalUrl) ?>}
  ,"inLanguage": "en-ZA"
}
</script>
<?php
// ── BreadcrumbList ──────────────────────────────────────────────────────────
// The visible breadcrumb below already exists; this is its machine-readable
// twin. Google replaces the raw URL in the result with the breadcrumb trail,
// which measurably lifts click-through.
$bcTrail = [
    ['name' => 'Home',           'url' => APP_URL . '/index.php'],
    ['name' => 'News & Reviews', 'url' => APP_URL . '/news.php'],
];
if (!empty($post['cat_name'])) {
    $bcTrail[] = ['name' => $post['cat_name'],
                  'url'  => APP_URL . '/news.php?cat=' . urlencode($post['cat_slug'] ?? '')];
}
$bcTrail[] = ['name' => $post['title'], 'url' => $canonicalUrl];

$bcItems = [];
foreach ($bcTrail as $i => $b) {
    $bcItems[] = ['@type' => 'ListItem', 'position' => $i + 1,
                  'name'  => $b['name'], 'item' => $b['url']];
}
echo '<script type="application/ld+json">'
   . json_encode(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
                  'itemListElement' => $bcItems],
                 JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
   . "</script>\n";

// ── FAQPage ─────────────────────────────────────────────────────────────────
// cron_generate_article.php is told to include an FAQ as <h3>question</h3>
// followed by <p>answer</p>. Pull the pairs back out of the stored body rather
// than keeping a separate copy, so the schema is always built from text the
// reader can actually see — which is what Google requires for the rich result.
if (preg_match_all('~<h3[^>]*>(.*?)</h3>\s*<p[^>]*>(.*?)</p>~is',
                   (string)($post['content'] ?? ''), $fm, PREG_SET_ORDER)) {
    $faq = [];
    foreach ($fm as $x) {
        $q = trim(html_entity_decode(strip_tags($x[1]), ENT_QUOTES, 'UTF-8'));
        $a = trim(html_entity_decode(strip_tags($x[2]), ENT_QUOTES, 'UTF-8'));
        if (str_ends_with($q, '?') && mb_strlen($a) > 40) {
            $faq[] = ['@type' => 'Question', 'name' => $q,
                      'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
        }
    }
    if (count($faq) >= 2) {
        echo '<script type="application/ld+json">'
           . json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage',
                          'mainEntity' => array_slice($faq, 0, 10)],
                         JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
           . "</script>\n";
    }
}
?>
<?php require_once 'header.php'; ?>

<!-- Breadcrumb -->
<nav style="background:#f8f9fb;border-bottom:1px solid #eef1f5;padding:.6rem 0;">
    <div class="container" style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;color:#999;flex-wrap:wrap;">
        <a href="<?= APP_URL ?>/index.php" style="color:#005BA1;text-decoration:none;">Home</a>
        <i class="fas fa-chevron-right" style="font-size:.6rem;"></i>
        <a href="<?= APP_URL ?>/news.php" style="color:#005BA1;text-decoration:none;">News &amp; Reviews</a>
        <?php if ($post['cat_name']): ?>
        <i class="fas fa-chevron-right" style="font-size:.6rem;"></i>
        <a href="<?= APP_URL ?>/news.php?cat=<?= urlencode($post['cat_slug']) ?>" style="color:#005BA1;text-decoration:none;"><?= htmlspecialchars($post['cat_name']) ?></a>
        <?php endif; ?>
        <i class="fas fa-chevron-right" style="font-size:.6rem;"></i>
        <span style="color:#334155;"><?= htmlspecialchars(mb_substr($post['title'],0,50)) ?>…</span>
    </div>
</nav>

<!-- Featured Image (full width) — shown only when no video, or as thumbnail above video -->
<?php
$hasVideo = !empty($post['video_url'] ?? '');
$hasImage = !empty($post['featured_image_url'] ?? '');
// Detect YouTube embed vs direct video file
$isYoutube = $hasVideo && str_contains($post['video_url'], 'youtube.com/embed/');
?>

<?php if ($hasVideo): ?>
<!-- Video Player (full width) -->
<div style="width:100%;background:#000;position:relative;">
    <?php if ($isYoutube): ?>
    <div style="position:relative;width:100%;padding-bottom:56.25%;height:0;overflow:hidden;">
        <iframe src="<?= htmlspecialchars($post['video_url']) ?>?rel=0&modestbranding=1&autoplay=0"
                style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowfullscreen loading="lazy"></iframe>
    </div>
    <?php else: ?>
    <video controls preload="metadata"
           style="width:100%;max-height:540px;display:block;background:#000;"
           <?= $hasImage ? 'poster="'.htmlspecialchars($post['featured_image_url']).'"' : '' ?>>
        <source src="<?= htmlspecialchars($post['video_url']) ?>">
        Your browser does not support video playback.
    </video>
    <?php endif; ?>
    <!-- Category badge -->
    <?php if ($post['cat_name']): ?>
    <div style="position:absolute;bottom:1rem;left:1rem;z-index:2;">
        <span style="display:inline-flex;align-items:center;gap:.4rem;background:<?= htmlspecialchars($post['cat_color']) ?>;color:#fff;font-size:.78rem;font-weight:700;padding:.4rem 1rem;border-radius:50px;letter-spacing:.06em;text-transform:uppercase;">
            <i class="fas <?= htmlspecialchars($post['cat_icon']??'fa-tag') ?>"></i>
            <?= htmlspecialchars($post['cat_name']) ?>
        </span>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($hasImage): ?>
<!-- Featured Image (full width, no video) -->
<div style="width:100%;max-height:520px;overflow:hidden;position:relative;background:#001F5C;">
    <img src="<?= htmlspecialchars($post['featured_image_url']) ?>"
         alt="<?= htmlspecialchars($post['title']) ?>"
         style="width:100%;height:520px;object-fit:cover;display:block;opacity:.9;">
    <div style="position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(0,0,0,.6) 100%);pointer-events:none;"></div>
    <?php if ($post['cat_name']): ?>
    <div style="position:absolute;bottom:1.5rem;left:1.5rem;">
        <span style="display:inline-flex;align-items:center;gap:.4rem;background:<?= htmlspecialchars($post['cat_color']) ?>;color:#fff;font-size:.78rem;font-weight:700;padding:.4rem 1rem;border-radius:50px;letter-spacing:.06em;text-transform:uppercase;">
            <i class="fas <?= htmlspecialchars($post['cat_icon']??'fa-tag') ?>"></i>
            <?= htmlspecialchars($post['cat_name']) ?>
        </span>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Article Layout -->
<div class="container" style="padding:2.5rem 1.5rem 4rem;display:grid;grid-template-columns:1fr 320px;gap:2.5rem;align-items:start;" id="article-layout">

<!-- Main Content -->
<article>
    <!-- Title -->
    <h1 style="font-size:clamp(1.6rem,3.5vw,2.4rem);font-weight:800;color:#001F5C;line-height:1.25;margin-bottom:1rem;">
        <?= htmlspecialchars($post['title']) ?>
    </h1>

    <!-- Meta bar -->
    <div style="display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap;font-size:.82rem;color:#888;padding:.75rem 0;border-top:1px solid #eef1f5;border-bottom:1px solid #eef1f5;margin-bottom:2rem;">
        <?php if ($post['author_name']): ?>
        <span style="display:flex;align-items:center;gap:.35rem;color:#334155;font-weight:600;">
            <span style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#001F5C,#005DDB);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:.72rem;font-weight:800;flex-shrink:0;">
                <?= mb_strtoupper(mb_substr($post['author_name'],0,1)) ?>
            </span>
            <?= htmlspecialchars($post['author_name']) ?>
        </span>
        <?php endif; ?>
        <span><i class="fas fa-calendar-alt" style="margin-right:.3rem;color:#005DDB;"></i><?= safeDate('d F Y', $post['published_at']) ?></span>
        <span><i class="fas fa-eye" style="margin-right:.3rem;color:#005DDB;"></i><?= number_format($post['view_count']) ?> views</span>
        <span><i class="fas fa-book-open" style="margin-right:.3rem;color:#005DDB;"></i><?= max(1,intval(str_word_count(strip_tags($post['content']??''))/200)) ?> min read</span>
        <?php if ($post['source_url'] && $post['source_name']): ?>
        <span><i class="fas fa-link" style="margin-right:.3rem;color:#005DDB;"></i>Source: <a href="<?= htmlspecialchars($post['source_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:#005DDB;"><?= htmlspecialchars($post['source_name']) ?></a></span>
        <?php endif; ?>
    </div>

    <!-- Excerpt -->
    <?php if ($post['excerpt']): ?>
    <p style="font-size:1.1rem;color:#4a5568;line-height:1.8;font-weight:500;border-left:4px solid #005DDB;padding-left:1.25rem;margin-bottom:1.75rem;background:#f8f9fb;padding:1rem 1.25rem;border-radius:0 8px 8px 0;">
        <?= htmlspecialchars($post['excerpt']) ?>
    </p>
    <?php endif; ?>

    <!-- Article Body -->
    <?php
    // Auto-format: if content has no block-level HTML, treat as plain text
    function formatArticleBody(string $raw): string {
        $raw = trim($raw);
        if (!$raw) return '';
        // Already has block elements — serve as-is
        if (preg_match('/<(p|div|h[1-6]|ul|ol|blockquote|table|pre|figure|section)\b/i', $raw)) {
            return $raw;
        }
        // Plain text → wrap each double-newline-separated block in <p>
        $raw = htmlspecialchars_decode(strip_tags($raw, '<a><strong><em><b><i><br>'));
        $blocks = preg_split('/\n{2,}/', $raw);
        $out = '';
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block !== '') $out .= '<p>' . nl2br($block) . '</p>';
        }
        return $out;
    }
    ?>
    <?php
    // Drop a live-stock strip after the third paragraph. Articles were
    // pulling readers in and dead-ending; this puts real, clickable lots
    // in front of them while they are still reading, and gives every
    // article internal links into auction.php / auctions.php.
    function injectAfterParagraph(string $html, string $insert, int $after = 3): string {
        if ($insert === '') return $html;
        $parts = preg_split('/(<\/p>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        // $parts alternates [text, '</p>', text, '</p>', …]
        $paras = (int)floor(count($parts) / 2);
        if ($paras <= $after + 1) return $html . $insert;  // too short to interrupt

        $out = '';
        $seen = 0;
        foreach ($parts as $chunk) {
            $out .= $chunk;
            if (strcasecmp($chunk, '</p>') === 0) {
                $seen++;
                if ($seen === $after) $out .= $insert;
            }
        }
        return $out;
    }

    $articleBody = formatArticleBody($post['content'] ?? '');
    $midStrip    = renderVehicleStrip($pdo, [
        'context' => ($post['title'] ?? '') . ' ' . ($post['excerpt'] ?? ''),
        'limit'   => 3,
        'compact' => true,
        'heading' => 'On auction right now',
        'sub'     => 'DEKRA-inspected bank stock, priced at the outstanding balance. No buyer\'s commission.',
    ]);
    ?>
    <div class="article-body">
        <?= injectAfterParagraph($articleBody, $midStrip, 3) ?>
    </div>

    <!-- Closing stock strip — last thing a finished reader sees -->
    <?= renderVehicleStrip($pdo, [
        'context' => ($post['title'] ?? '') . ' ' . ($post['excerpt'] ?? ''),
        'limit'   => 3,
        'heading' => 'Ready to stop reading and start bidding?',
        'sub'     => 'Registration is free. The R10,000 deposit is fully refundable if you do not buy.',
    ]) ?>

    <!-- Tags -->
    <?php
    $postTags = [];
    try {
        $tg = $pdo->prepare("SELECT t.name, t.slug FROM news_tags t
                             JOIN news_post_tags pt ON pt.tag_id = t.id
                             WHERE pt.post_id = ? ORDER BY t.name");
        $tg->execute([$post['id']]);
        $postTags = $tg->fetchAll();
    } catch (Exception $e) { /* tag tables not created yet */ }
    ?>
    <?php if (!empty($postTags)): ?>
    <div style="margin-top:2rem;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;">
        <span style="font-size:.8rem;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:.06em;margin-right:.25rem;">
            <i class="fas fa-tags" style="color:#005DDB;"></i> Tags:
        </span>
        <?php foreach ($postTags as $t): ?>
        <a href="<?= APP_URL ?>/tag.php?slug=<?= urlencode($t['slug']) ?>"
           style="display:inline-flex;align-items:center;padding:.35rem .85rem;border-radius:50px;background:#f0f4f8;color:#005DDB;font-size:.8rem;font-weight:600;text-decoration:none;border:1px solid #dde3ec;transition:.15s;"
           onmouseover="this.style.background='#005DDB';this.style.color='#fff'"
           onmouseout="this.style.background='#f0f4f8';this.style.color='#005DDB'">
            #<?= htmlspecialchars($t['name']) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Share -->
    <div style="margin-top:2.5rem;padding-top:1.5rem;border-top:1px solid #eef1f5;">
        <p style="font-size:.85rem;font-weight:700;color:#334155;margin-bottom:.75rem;text-transform:uppercase;letter-spacing:.08em;">Share this article</p>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode(APP_URL.'/news-post.php?slug='.urlencode($slug)) ?>" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:8px;background:#1877f2;color:#fff;font-size:.82rem;font-weight:600;text-decoration:none;">
                <i class="fab fa-facebook-f"></i> Facebook
            </a>
            <a href="https://twitter.com/intent/tweet?url=<?= urlencode(APP_URL.'/news-post.php?slug='.urlencode($slug)) ?>&text=<?= urlencode($post['title']) ?>" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:8px;background:#1da1f2;color:#fff;font-size:.82rem;font-weight:600;text-decoration:none;">
                <i class="fab fa-twitter"></i> Twitter
            </a>
            <a href="https://wa.me/?text=<?= urlencode($post['title'].' '.APP_URL.'/news-post.php?slug='.urlencode($slug)) ?>" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:8px;background:#25d366;color:#fff;font-size:.82rem;font-weight:600;text-decoration:none;">
                <i class="fab fa-whatsapp"></i> WhatsApp
            </a>
            <button onclick="navigator.clipboard.writeText(location.href);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy Link',2000)"
               style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:8px;background:#f0f4f8;color:#334155;font-size:.82rem;font-weight:600;border:1px solid #dde3ec;cursor:pointer;">
                <i class="fas fa-link"></i> Copy Link
            </button>
        </div>
    </div>

    <!-- Comments -->
    <?php if ($post['allow_comments']): ?>
    <div id="comments" style="margin-top:2.5rem;padding-top:2rem;border-top:1px solid #eef1f5;">
        <h3 style="font-size:1.1rem;font-weight:800;color:#001F5C;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;">
            <i class="fas fa-comments" style="color:#005DDB;"></i>
            Comments (<?= count($comments) ?>)
        </h3>

        <!-- Comment list -->
        <?php if (empty($comments)): ?>
        <p style="color:#999;font-size:.9rem;padding:1.25rem;background:#f8f9fb;border-radius:12px;text-align:center;">
            No comments yet. Be the first to share your thoughts!
        </p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:1rem;margin-bottom:1.75rem;">
            <?php foreach ($comments as $cm): ?>
            <div style="display:flex;gap:.85rem;padding:1rem;background:#f8f9fb;border-radius:12px;border:1px solid #eef1f5;">
                <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#001F5C,#005DDB);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.82rem;font-weight:800;flex-shrink:0;">
                    <?= mb_strtoupper(mb_substr($cm['user_name'],0,1)) ?>
                </div>
                <div style="flex:1;">
                    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.35rem;flex-wrap:wrap;">
                        <strong style="font-size:.88rem;color:#1a2a40;"><?= htmlspecialchars($cm['user_name']) ?></strong>
                        <span style="font-size:.75rem;color:#999;"><?= timeAgoPost($cm['created_at']) ?></span>
                    </div>
                    <p style="color:#4a5568;font-size:.9rem;line-height:1.65;margin:0;"><?= nl2br(htmlspecialchars($cm['content'])) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Comment form -->
        <?php if (isset($_SESSION['user_id'])): ?>
        <?php if ($commentOk): ?>
        <div style="background:rgba(110,255,158,.12);border:1px solid rgba(110,255,158,.3);border-radius:10px;padding:1rem 1.25rem;color:#2e7d32;font-weight:600;font-size:.9rem;margin-bottom:1rem;">
            <i class="fas fa-check-circle"></i> Comment posted successfully!
        </div>
        <?php endif; ?>
        <?php if ($commentError): ?>
        <div style="background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.3);border-radius:10px;padding:1rem 1.25rem;color:#c62828;font-size:.9rem;margin-bottom:1rem;">
            <?= htmlspecialchars($commentError) ?>
        </div>
        <?php endif; ?>
        <div style="background:#fff;border:1px solid #dde3ec;border-radius:14px;padding:1.25rem;">
            <p style="font-size:.85rem;font-weight:700;color:#334155;margin-bottom:.75rem;">Leave a comment</p>
            <form method="POST">
                <textarea name="comment_content" rows="4" placeholder="Share your thoughts…" required
                    style="width:100%;padding:.75rem 1rem;border:1px solid #dde3ec;border-radius:10px;font-size:.9rem;resize:vertical;font-family:inherit;color:#2d3748;line-height:1.6;outline:none;transition:border .2s;"
                    onfocus="this.style.borderColor='#005DDB'" onblur="this.style.borderColor='#dde3ec'"></textarea>
                <div style="display:flex;justify-content:flex-end;margin-top:.75rem;">
                    <button type="submit" name="submit_comment"
                        style="background:linear-gradient(135deg,#001F5C,#005DDB);color:#fff;border:none;padding:.65rem 1.5rem;border-radius:50px;font-weight:700;font-size:.88rem;cursor:pointer;transition:opacity .2s;"
                        onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
                        Post Comment <i class="fas fa-paper-plane" style="margin-left:.3rem;"></i>
                    </button>
                </div>
            </form>
        </div>
        <?php else: ?>
        <div style="background:#f8f9fb;border:1px solid #dde3ec;border-radius:12px;padding:1.25rem;text-align:center;color:#667;font-size:.9rem;">
            <a href="<?= APP_URL ?>/login.php" style="color:#005DDB;font-weight:600;">Log in</a> to leave a comment.
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</article>

<!-- Sidebar -->
<aside style="position:sticky;top:calc(var(--nav-height,72px) + 1rem);">

    <?php if (!empty($related)): ?>
    <div style="background:#fff;border:1px solid #eef1f5;border-radius:16px;padding:1.25rem;margin-bottom:1.25rem;box-shadow:0 2px 12px rgba(0,31,92,.06);">
        <h4 style="font-size:.82rem;font-weight:700;color:#001F5C;text-transform:uppercase;letter-spacing:.08em;margin-bottom:1rem;padding-bottom:.6rem;border-bottom:2px solid #005DDB;">Related Articles</h4>
        <div style="display:flex;flex-direction:column;gap:.85rem;">
            <?php foreach ($related as $r): ?>
            <a href="<?= postUrlSingle($r) ?>" style="display:flex;gap:.75rem;text-decoration:none;align-items:flex-start;padding:.6rem;border-radius:10px;transition:background .15s;" onmouseover="this.style.background='#f8f9fb'" onmouseout="this.style.background='transparent'">
                <img src="<?= $r['featured_image_url'] ? htmlspecialchars($r['featured_image_url']) : 'https://placehold.co/80x60/001F5C/fff?text=AHG' ?>"
                     alt="" style="width:72px;height:54px;object-fit:cover;border-radius:8px;flex-shrink:0;background:#eef1f5;">
                <div>
                    <?php if ($r['cat_name']): ?>
                    <span style="font-size:.68rem;font-weight:700;color:<?= htmlspecialchars($r['cat_color']) ?>;text-transform:uppercase;letter-spacing:.06em;"><?= htmlspecialchars($r['cat_name']) ?></span>
                    <?php endif; ?>
                    <p style="font-size:.85rem;font-weight:600;color:#1a2a40;line-height:1.4;margin:.2rem 0 0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($r['title']) ?></p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- CTA Box -->
    <div style="background:linear-gradient(135deg,#001F5C,#005DDB);border-radius:16px;padding:1.5rem;text-align:center;color:#fff;">
        <i class="fas fa-gavel" style="font-size:1.75rem;color:#ffd54f;margin-bottom:.75rem;display:block;"></i>
        <h4 style="font-size:1.05rem;font-weight:800;margin-bottom:.5rem;color:#fff;">Ready to Bid?</h4>
        <p style="font-size:.82rem;color:rgba(255,255,255,.75);line-height:1.6;margin-bottom:1rem;">Register free and start bidding on DEKRA-inspected vehicles from R10,500 deposit.</p>
        <a href="<?= APP_URL ?>/register-to-bid.php" style="display:block;background:#ffd54f;color:#001F5C;padding:.7rem 1rem;border-radius:50px;font-weight:700;font-size:.88rem;text-decoration:none;margin-bottom:.5rem;transition:opacity .2s;" onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
            Register Free
        </a>
        <a href="<?= APP_URL ?>/auctions.php" style="display:block;color:rgba(255,255,255,.8);font-size:.82rem;text-decoration:none;">View Live Auctions →</a>
    </div>
</aside>

</div><!-- /#article-layout -->

<style>
/* ── Article body typography ── */
.article-body{font-size:1.08rem;line-height:1.9;color:#2d3748;font-family:'Segoe UI','Inter',Arial,sans-serif;}
.article-body>p:first-child{font-size:1.13rem;color:#1a2a40;font-weight:500;line-height:1.85;}
.article-body h2{font-size:1.4rem;font-weight:800;color:#001F5C;margin:2.25rem 0 .85rem;padding-bottom:.5rem;border-bottom:2px solid #e8edf2;letter-spacing:-.01em;}
.article-body h3{font-size:1.12rem;font-weight:700;color:#003070;margin:1.75rem 0 .6rem;}
.article-body h4{font-size:1rem;font-weight:700;color:#334155;margin:1.25rem 0 .4rem;text-transform:uppercase;letter-spacing:.06em;}
.article-body p{margin:0 0 1.4rem;}
.article-body ul,.article-body ol{margin:0 0 1.4rem 1.6rem;line-height:1.85;}
.article-body li{margin-bottom:.45rem;}
.article-body ul li::marker{color:#005DDB;}
.article-body a{color:#005DDB;text-decoration:underline;text-underline-offset:3px;}
.article-body a:hover{color:#001F5C;}
.article-body strong,.article-body b{color:#1a2a40;font-weight:700;}
.article-body blockquote{border-left:4px solid #005DDB;margin:1.75rem 0;padding:1rem 1.5rem;background:linear-gradient(135deg,#f0f6ff,#e8f0ff);border-radius:0 12px 12px 0;color:#334155;font-style:italic;font-size:1.05rem;line-height:1.75;}
.article-body blockquote p{margin:0;}
.article-body img{max-width:100%;height:auto;border-radius:12px;margin:1.5rem 0;box-shadow:0 4px 16px rgba(0,31,92,.1);display:block;}
.article-body figure{margin:1.5rem 0;}
.article-body figcaption{font-size:.82rem;color:#888;text-align:center;margin-top:.35rem;font-style:italic;}
.article-body table{width:100%;border-collapse:collapse;margin:1.5rem 0;font-size:.9rem;box-shadow:0 1px 6px rgba(0,0,0,.06);border-radius:8px;overflow:hidden;}
.article-body th,.article-body td{padding:.7rem 1rem;border:1px solid #e8edf2;text-align:left;}
.article-body th{background:#f0f6ff;font-weight:700;color:#001F5C;}
.article-body tr:nth-child(even) td{background:#f8fafb;}
.article-body code{background:#f0f4f8;padding:.2rem .5rem;border-radius:5px;font-size:.85em;color:#005DDB;font-family:'Courier New',monospace;}
.article-body pre{background:#1a2a40;color:#e0e8f0;padding:1.25rem 1.5rem;border-radius:12px;overflow-x:auto;font-size:.85rem;margin:1.5rem 0;line-height:1.65;}
.article-body hr{border:none;border-top:2px solid #e8edf2;margin:2rem 0;}
/* Pull-quote helper class */
.article-body .pull-quote{font-size:1.3rem;font-weight:700;color:#001F5C;text-align:center;padding:1.5rem 2rem;margin:2rem 0;border-top:3px solid #005DDB;border-bottom:3px solid #005DDB;line-height:1.5;}
/* Key fact box */
.article-body .fact-box{background:#fff3cd;border-left:4px solid #ffc107;padding:1rem 1.25rem;border-radius:0 10px 10px 0;margin:1.5rem 0;}
@media(max-width:900px){
  #article-layout{grid-template-columns:1fr!important}
  aside{position:static!important}
  .article-body{font-size:1rem;}
}
</style>

<?php require_once 'footer.php'; ?>
