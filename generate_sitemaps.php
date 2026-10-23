<?php
/**
 * AUCTION HUB GROUP — generate_sitemaps.php
 * ----------------------------------------------------------------------------
 * Writes REAL static files to disk so Google can always fetch them, with zero
 * dependency on mod_rewrite or PHP execution at request time:
 *     sitemap.xml   ← from sitemap.php
 *     news-sitemap.xml ← from news-sitemap.php
 *     feed.xml      ← from feed.php
 *
 * Run on PRODUCTION (it must use the production DB):
 *   Browser:  https://auctionhubgroup.co.za/generate_sitemaps.php?key=<AHG_CRON_KEY>
 *   CLI/cron: /usr/local/bin/php /home/<cpaneluser>/public_html/generate_sitemaps.php
 *
 * Schedule it on cPanel cron (e.g. daily) so the static files stay fresh as
 * vehicles and articles change. After it runs, submit sitemap.xml in Search
 * Console — it is now a plain file, so "couldn't fetch / 404" cannot happen.
 * ----------------------------------------------------------------------------
 */

// BOM-safe config load (see sitemap.php) — must happen before any output.
ob_start();
require_once __DIR__ . '/config.php';
ob_end_clean();

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && (($_GET['key'] ?? '') !== AHG_CRON_KEY)) {
    http_response_code(403);
    die('Forbidden');
}

// source script => static output file
$targets = [
    'sitemap.php'      => 'sitemap.xml',
    'news-sitemap.php' => 'news-sitemap.xml',
    'feed.php'         => 'feed.xml',
];

$report = [];
foreach ($targets as $src => $outName) {
    $srcPath = __DIR__ . '/' . $src;
    if (!is_file($srcPath)) { $report[$outName] = "SKIP — {$src} missing"; continue; }

    // Capture the script's XML output (its own ob guard strips the config BOM;
    // header() calls inside are harmless because nothing is flushed yet).
    ob_start();
    include $srcPath;
    $xml = ob_get_clean();
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml); // belt-and-braces BOM strip

    $bytes = @file_put_contents(__DIR__ . '/' . $outName, $xml);
    $urls  = substr_count($xml, '<url>') + substr_count($xml, '<item>');
    $report[$outName] = ($bytes !== false)
        ? number_format($bytes) . " bytes, ~{$urls} entries"
        : 'WRITE FAILED — check folder write permissions';
}

// Also expose the news/articles sitemap under the friendlier name articles.xml
// (identical content) so it can be submitted under either name.
if (is_file(__DIR__ . '/news-sitemap.xml')) {
    @copy(__DIR__ . '/news-sitemap.xml', __DIR__ . '/articles.xml');
    $report['articles.xml'] = 'copy of news-sitemap.xml';
}

if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "=== AHG static sitemap/feed generation ===\n";
foreach ($report as $file => $result) {
    echo str_pad($file, 20) . " : {$result}\n";
}
echo "Done. Submit https://auctionhubgroup.co.za/sitemap.xml in Search Console.\n";
