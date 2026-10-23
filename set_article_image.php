<?php
/**
 * AUCTION HUB GROUP — set_article_image.php
 * ----------------------------------------------------------------------------
 * Generate and attach a featured image to an existing news article.
 * Uses Pexels (real photos) if PEXELS_API_KEY is set, else Pollinations.ai
 * (free AI), then stores it permanently on ImageKit and updates the DB.
 *
 * Run on PRODUCTION:
 *   Default (the repossessed-car guide):
 *     https://auctionhubgroup.co.za/set_article_image.php?key=<AHG_CRON_KEY>
 *   A specific article:
 *     https://auctionhubgroup.co.za/set_article_image.php?key=<AHG_CRON_KEY>&slug=some-slug
 *   Every article that has no image yet:
 *     https://auctionhubgroup.co.za/set_article_image.php?key=<AHG_CRON_KEY>&all=1
 * ----------------------------------------------------------------------------
 */
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/imagekit_helper.php';
ob_end_clean();

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && (($_GET['key'] ?? '') !== AHG_CRON_KEY)) {
    http_response_code(403);
    die('Forbidden');
}
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
$nl = "\n";

function ahg_img_pexels(string $query): ?string {
    if (PEXELS_API_KEY === '') return null;
    $ch = curl_init('https://api.pexels.com/v1/search?orientation=landscape&per_page=1&query=' . rawurlencode($query));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: ' . PEXELS_API_KEY]]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) return null;
    $d = json_decode($resp, true);
    return $d['photos'][0]['src']['large'] ?? $d['photos'][0]['src']['original'] ?? null;
}

function ahg_img_for(string $title, string $slug): ?string {
    $kw = 'repossessed car auction South Africa';
    $src = ahg_img_pexels($kw);
    if (!$src) {
        $prompt = "Professional editorial photograph: {$title}. Theme: used cars at a vehicle "
                . "auction in South Africa. Realistic, high detail, natural daylight, no text, no watermark.";
        $src = 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt)
             . '?width=1280&height=720&nologo=true&model=flux&seed=' . random_int(1, 999999);
    }
    if (imagekitPrivateKey() !== '') {
        try {
            return uploadToImageKit($src, 'pva/news/' . date('Y'), 'news-' . $slug)['url'];
        } catch (\Throwable $e) { /* fall back to direct URL */ }
    }
    return $src;
}

// Which articles?
$slug = trim($_GET['slug'] ?? 'how-to-buy-a-repossessed-car-in-south-africa');
$all  = !empty($_GET['all']);

if ($all) {
    $rows = $pdo->query("SELECT id, title, slug FROM news_posts
                         WHERE (featured_image_url IS NULL OR featured_image_url = '')
                         AND post_type IN ('article','review') LIMIT 25")->fetchAll();
} else {
    $st = $pdo->prepare("SELECT id, title, slug FROM news_posts WHERE slug = ? LIMIT 1");
    $st->execute([$slug]);
    $rows = $st->fetchAll();
}

if (!$rows) { echo "No matching article(s) found.{$nl}"; exit; }

$upd = $pdo->prepare("UPDATE news_posts SET featured_image_url = ? WHERE id = ?");
foreach ($rows as $r) {
    $img = ahg_img_for($r['title'], $r['slug']);
    if ($img) {
        $upd->execute([$img, $r['id']]);
        echo "OK  #{$r['id']}  {$r['slug']}{$nl}    → {$img}{$nl}";
    } else {
        echo "FAIL #{$r['id']}  {$r['slug']} (no image produced){$nl}";
    }
}
echo "Done.{$nl}";
