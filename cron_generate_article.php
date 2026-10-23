<?php
/**
 * AUCTION HUB GROUP — cron_generate_article.php
 * ----------------------------------------------------------------------------
 * Automated SEO article generator. Picks a topic from `article_topics`, asks
 * Groq (Llama 3.3 70B) to write a full HTML article with tags + internal
 * backlinks, and inserts it into `news_posts` as a DRAFT (is_active = 0).
 *
 * A human approves drafts in admin before they go live — this is deliberate:
 * it keeps quality high and stays clear of Google's "scaled content abuse"
 * policy. Aim for a few great articles per week, not floods of thin ones.
 *
 *   cPanel cron (daily, off-peak):
 *     /usr/local/bin/php /home/<cpaneluser>/public_html/cron_generate_article.php
 *
 *   Browser (manual test):
 *     https://auctionhubgroup.co.za/cron_generate_article.php?key=ahg-gen-2026
 *
 *   Generate more than one in a run:
 *     php cron_generate_article.php --count=2     (CLI)
 *     ...cron_generate_article.php?key=...&count=2 (browser)
 *
 * Requires GROQ_API_KEY in config.php.
 * ----------------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
// Gives us uploadToImageKit() for storing featured images.
require_once __DIR__ . '/imagekit_helper.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && (($_GET['key'] ?? '') !== AHG_CRON_KEY)) {
    http_response_code(403);
    die('Forbidden');
}
$nl = $isCli ? "\n" : "<br>\n";

if (!$isCli) { header('Content-Type: text/html; charset=utf-8'); }

function out(string $msg): void {
    global $nl;
    echo $msg . $nl;
    @flush();
}

// How many articles to draft this run (keep small).
$count = (int)($_GET['count'] ?? ($isCli ? ($argvCount ?? 0) : 0));
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--count=(\d+)$/', $arg, $m)) $count = (int)$m[1];
}
$count = max(1, min(5, $count ?: 1));

if (GROQ_API_KEY === '') {
    out('ERROR: GROQ_API_KEY is empty in config.php. Add your Groq key first.');
    exit(1);
}

// ── Bootstrap tables (idempotent, mirrors news.php style) ───────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS news_tags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        slug VARCHAR(80) NOT NULL UNIQUE,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS news_post_tags (
        post_id INT NOT NULL,
        tag_id  INT NOT NULL,
        PRIMARY KEY (post_id, tag_id),
        INDEX idx_tag (tag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS article_topics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        topic VARCHAR(255) NOT NULL,
        primary_kw VARCHAR(160) DEFAULT NULL,
        category_id INT DEFAULT NULL,
        source VARCHAR(40) NOT NULL DEFAULT 'manual',
        trend_score INT NOT NULL DEFAULT 0,
        status ENUM('queued','drafted','published','skipped') NOT NULL DEFAULT 'queued',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        used_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_status_score (status, trend_score)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    out('Schema bootstrap error: ' . $e->getMessage());
}

// ── Resolve fallback category (Buying Guides) ───────────────────────────────
$defaultCatId = $pdo->query("SELECT id FROM news_categories WHERE slug='buying-guides' LIMIT 1")->fetchColumn() ?: null;

// ── Seed a minimal topic queue if empty ─────────────────────────────────────
$topicCount = (int)$pdo->query("SELECT COUNT(*) FROM article_topics WHERE status='queued'")->fetchColumn();
if ($topicCount === 0) {
    $seed = [
        ['How to read a DEKRA condition report before you bid', 'DEKRA condition report', 50],
        ['Repossessed bakkies in South Africa: Hilux vs Ranger vs D-Max', 'repossessed bakkies South Africa', 60],
        ['What "voetstoots" really means at a car auction', 'voetstoots meaning', 40],
        ['First-time bidder checklist for online car auctions in SA', 'online car auction checklist South Africa', 55],
        ['Cheapest reliable family cars at auction under R150k', 'cheap family cars auction South Africa', 50],
    ];
    $ins = $pdo->prepare("INSERT INTO article_topics (topic, primary_kw, category_id, source, trend_score) VALUES (?,?,?,'manual',?)");
    foreach ($seed as $s) $ins->execute([$s[0], $s[1], $defaultCatId, $s[2]]);
    out('Seeded ' . count($seed) . ' starter topics.');
}

// ── Internal link menu given to the model (real site pages) ─────────────────
$linkMenu = [
    'Browse current vehicles'          => 'auctions.php',
    'Pre-auction fixed-price purchase' => 'preauction.php',
    'In-house vehicle finance'         => 'financing.php',
    'How auctions work / guide'        => 'auction-guide.php',
    'Recently sold vehicles'           => 'sold_vehicles.php',
    'Register as a bidder'             => 'login.php?tab=register',
    'About Auction Hub Group'          => 'about.php',
    'Contact us'                       => 'contact.php',
];

// A few existing published articles, so the model can cross-link real slugs.
$existing = $pdo->query("
    SELECT title, slug FROM news_posts
    WHERE is_active=1 AND post_type IN ('article','review')
    ORDER BY published_at DESC LIMIT 6
")->fetchAll();

// ── Helpers ─────────────────────────────────────────────────────────────────
function ahg_slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-') ?: ('article-' . time());
}

function ahg_unique_slug(PDO $pdo, string $slug): string {
    $base = substr($slug, 0, 280);
    $slug = $base;
    $i = 2;
    $stmt = $pdo->prepare("SELECT 1 FROM news_posts WHERE slug=? LIMIT 1");
    while (true) {
        $stmt->execute([$slug]);
        if (!$stmt->fetchColumn()) return $slug;
        $slug = $base . '-' . $i++;
    }
}

function ahg_tag_id(PDO $pdo, string $name): ?int {
    $name = trim(mb_substr($name, 0, 80));
    if ($name === '') return null;
    $slug = ahg_slugify($name);
    $sel = $pdo->prepare("SELECT id FROM news_tags WHERE slug=? LIMIT 1");
    $sel->execute([$slug]);
    $id = $sel->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO news_tags (name, slug) VALUES (?,?)")->execute([$name, $slug]);
    return (int)$pdo->lastInsertId();
}

/** Call Groq chat completions and return the assistant message string. */
function ahg_groq(string $system, string $user): string {
    $payload = [
        'model'           => GROQ_MODEL,
        'messages'        => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'temperature'     => 0.6,
        'max_tokens'      => 6000,
        'response_format' => ['type' => 'json_object'],
    ];
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . GROQ_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false)        throw new RuntimeException("Groq request failed: $err");
    if ($code !== 200)          throw new RuntimeException("Groq HTTP $code: " . substr($resp, 0, 400));
    $data = json_decode($resp, true);
    $msg  = $data['choices'][0]['message']['content'] ?? '';
    if ($msg === '')            throw new RuntimeException('Groq returned empty content.');
    return $msg;
}

/**
 * Find a relevant real stock photo on Pexels (free API). Returns an image URL
 * or null. Prefers landscape; falls back to the first result.
 */
function ahg_pexels_image(string $query): ?string {
    if (PEXELS_API_KEY === '') return null;
    $url = 'https://api.pexels.com/v1/search?orientation=landscape&per_page=1&query=' . rawurlencode($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . PEXELS_API_KEY],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) return null;
    $data = json_decode($resp, true);
    return $data['photos'][0]['src']['large'] ?? $data['photos'][0]['src']['original'] ?? null;
}

/**
 * Pick a featured image and store it permanently on ImageKit.
 * Source: Pexels (real photos) when PEXELS_API_KEY is set, else Pollinations.ai
 * (free AI, Flux). Returns a usable image URL, or null.
 */
function ahg_featured_image(string $title, string $slug, ?string $kw): ?string {
    if (!AHG_IMAGE_ENABLED) return null;

    // 1) Choose a source image URL.
    $imgUrl = ahg_pexels_image(trim(($kw ?: 'used car') . ' car South Africa'));
    if ($imgUrl) {
        out('  (image) source: Pexels');
    } else {
        $prompt = "Professional editorial photograph for an article titled \"{$title}\". "
                . "Theme: modern vehicles at a car auction in South Africa"
                . ($kw ? ", {$kw}" : '')
                . ". Realistic, high detail, natural daylight, sharp focus, no text, no watermark, no logos.";
        $imgUrl = 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt)
                . '?width=1280&height=720&nologo=true&model=flux&seed=' . random_int(1, 999999);
        out('  (image) source: Pollinations (AI)');
    }

    // 2) Persist on ImageKit (downloaded server-side). Fall back to the raw URL.
    if (imagekitPrivateKey() !== '') {
        try {
            $res = uploadToImageKit($imgUrl, 'pva/news/' . date('Y'), 'news-' . $slug);
            return $res['url'];
        } catch (\Throwable $e) {
            out('  (image) ImageKit store failed, using direct URL: ' . $e->getMessage());
        }
    }
    return $imgUrl;
}

// ── Build the prompts ───────────────────────────────────────────────────────
$linkLines = '';
foreach ($linkMenu as $label => $href) $linkLines .= "- $label  ->  $href\n";
$existingLines = '';
foreach ($existing as $ex) $existingLines .= "- {$ex['title']}  ->  news-post.php?slug={$ex['slug']}\n";
if ($existingLines === '') $existingLines = "- (no published articles yet)\n";

$system =
    "You are a senior automotive SEO writer for Auction Hub Group (auctionhubgroup.co.za), " .
    "a South African vehicle auction house selling bank-repossessed and bank-consigned cars, " .
    "every vehicle DEKRA-inspected, with weekly live/online auctions, a fixed-price pre-auction " .
    "programme (0% buyer's commission), and in-house finance. Audience: South African car buyers. " .
    "Use South African English and the rand (R). Be accurate, helpful and original — never invent " .
    "fake statistics, prices or quotes. Write in clean semantic HTML using <h2>, <h3>, <p>, <ul>, " .
    "<li>, <strong>. Do NOT include <h1>, <html>, <head> or <body> tags. " .
    "Respond ONLY with a single valid JSON object.";

$published = (int)$pdo->query("SELECT COUNT(*) FROM news_posts WHERE is_active=1")->fetchColumn();

// ── Generate loop ───────────────────────────────────────────────────────────
$pick = $pdo->prepare("
    SELECT * FROM article_topics
    WHERE status='queued'
    ORDER BY trend_score DESC, created_at ASC
    LIMIT 1
");

$made = 0;
for ($n = 0; $n < $count; $n++) {
    $pick->execute();
    $topic = $pick->fetch();
    if (!$topic) { out('No more queued topics. Add rows to article_topics.'); break; }

    out("→ Topic #{$topic['id']}: {$topic['topic']}");
    $catId = $topic['category_id'] ?: $defaultCatId;

    $user =
        "Write a complete, original SEO article for this topic:\n" .
        "TOPIC: {$topic['topic']}\n" .
        "PRIMARY KEYWORD (use naturally, incl. in title + first paragraph): {$topic['primary_kw']}\n\n" .
        "REQUIREMENTS:\n" .
        "- 800–1400 words, scannable, with <h2>/<h3> subheadings.\n" .
        "- Include 3 to 6 internal links chosen from THIS menu only (use relative hrefs exactly as shown), " .
        "at least one pointing to auctions.php or preauction.php:\n{$linkLines}" .
        "- You MAY also link to these existing articles where relevant:\n{$existingLines}" .
        "- Include one short FAQ section (2–3 Q&As as <h3> question + <p> answer).\n" .
        "- End with a short call-to-action paragraph linking to auctions.php and preauction.php.\n" .
        "- Assign 3 to 6 tags (make/model, body type, province, or intent like \"repossessed\", \"buying-guide\").\n\n" .
        "Return ONLY this JSON shape:\n" .
        "{\n" .
        "  \"title\": \"compelling <=70 char title containing the keyword\",\n" .
        "  \"slug\": \"kebab-case-slug\",\n" .
        "  \"excerpt\": \"<=220 char summary\",\n" .
        "  \"meta_title\": \"<=60 chars, ends with | Auction Hub Group\",\n" .
        "  \"meta_description\": \"<=155 chars\",\n" .
        "  \"content_html\": \"the article body as HTML\",\n" .
        "  \"tags\": [\"tag one\", \"tag two\", \"tag three\"]\n" .
        "}";

    try {
        $raw  = ahg_groq($system, $user);
        $art  = json_decode($raw, true);
        if (!is_array($art) || empty($art['title']) || empty($art['content_html'])) {
            throw new RuntimeException('Model JSON missing title/content_html.');
        }

        $title    = trim($art['title']);
        $slug     = ahg_unique_slug($pdo, ahg_slugify($art['slug'] ?? $title));
        $excerpt  = trim(mb_substr($art['excerpt'] ?? '', 0, 250));
        $metaT    = trim(mb_substr($art['meta_title'] ?? $title, 0, 200));
        $metaD    = trim(mb_substr($art['meta_description'] ?? $excerpt, 0, 350));
        $body     = trim($art['content_html']);
        $tags     = array_slice(array_filter((array)($art['tags'] ?? [])), 0, 6);

        // Featured image (free AI image → ImageKit). Null-safe.
        $featured = ahg_featured_image($title, $slug, $topic['primary_kw']);
        if ($featured) out("  image: {$featured}");

        // Insert as DRAFT (is_active = 0). Approve in admin to publish.
        $pdo->prepare("INSERT INTO news_posts
            (title, slug, excerpt, content, featured_image_url, category_id, post_type,
             source_name, is_active, is_featured, allow_comments, meta_title, meta_description, published_at)
            VALUES (?,?,?,?,?,?, 'article', 'AHG AI Writer', 0, 0, 1, ?, ?, NOW())")
            ->execute([$title, $slug, $excerpt, $body, $featured, $catId, $metaT, $metaD]);
        $postId = (int)$pdo->lastInsertId();

        // Link tags
        $linkTag = $pdo->prepare("INSERT IGNORE INTO news_post_tags (post_id, tag_id) VALUES (?,?)");
        foreach ($tags as $t) {
            $tid = ahg_tag_id($pdo, (string)$t);
            if ($tid) $linkTag->execute([$postId, $tid]);
        }

        // Mark topic drafted
        $pdo->prepare("UPDATE article_topics SET status='drafted', used_at=NOW() WHERE id=?")
            ->execute([$topic['id']]);

        $made++;
        out("  ✓ DRAFT #{$postId} created: \"{$title}\"  [tags: " . implode(', ', $tags) . "]");
        out("    Review/approve in admin (set is_active=1) before it goes live.");
    } catch (Exception $e) {
        // Skip this topic so a bad one doesn't block the queue forever.
        $pdo->prepare("UPDATE article_topics SET status='skipped', used_at=NOW() WHERE id=?")
            ->execute([$topic['id']]);
        out("  ✗ FAILED (topic marked skipped): " . $e->getMessage());
    }
}

out("Done. {$made} draft(s) created. Approve them in admin to publish.");
