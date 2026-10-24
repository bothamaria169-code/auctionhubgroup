<?php
// ============================================================
// AUCTION HUB GROUP — dealer_stock_helper.php
// Admin → Stock Import → Dealer / branch stock list. Ported from BidCo
// Auctions' Dealer Stock seeder (includes/dealer-stock.php).
//
// Reads a stock list in whatever form a branch or partner dealer has it —
// CSV (comma, semicolon or tab), Excel (.xlsx), XML or JSON, a feed link, or
// their own website (WordPress "Motors" sites via their sitemap; other sites
// by following the stock pages to each vehicle page) — and works out the
// columns from their names. The list waits as a batch; an admin ticks which
// vehicles to take and those become Stock Import drafts (photos downloaded
// into the staging folder), reviewed and published like Facebook imports.
//
// Only for our own branches and dealers who agreed to it. robots.txt is
// respected, and private/local addresses are never fetched.
// Requires: config.php, stock_import_helper.php
// ============================================================

/** A column / element name (lower case, letters and digits only) => our field. */
const DEALER_FIELDS = [
    'stockno' => 'ref', 'stocknumber' => 'ref', 'stockcode' => 'ref', 'stock' => 'ref', 'stockid' => 'ref', 'ref' => 'ref',
    'reference' => 'ref', 'refno' => 'ref', 'id' => 'ref', 'vehicleid' => 'ref', 'listingid' => 'ref', 'advertid' => 'ref',
    'vin' => 'vin', 'vinnumber' => 'vin', 'chassis' => 'vin', 'chassisno' => 'vin',
    'make' => 'make', 'brand' => 'make', 'manufacturer' => 'make',
    'model' => 'model', 'series' => 'model', 'range' => 'model',
    'variant' => 'variant', 'derivative' => 'variant', 'version' => 'variant', 'trim' => 'variant', 'modeldescription' => 'variant',
    'title' => 'title', 'name' => 'title', 'description' => 'description', 'comments' => 'description', 'notes' => 'description',
    'sellerscomments' => 'description', 'extras' => 'description',
    'year' => 'year', 'modelyear' => 'year', 'regyear' => 'year', 'yearmodel' => 'year',
    'mileage' => 'mileage', 'km' => 'mileage', 'kms' => 'mileage', 'odometer' => 'mileage', 'odo' => 'mileage',
    'price' => 'price', 'sellingprice' => 'price', 'retailprice' => 'price', 'askingprice' => 'price', 'amount' => 'price',
    'cashprice' => 'price', 'priceincl' => 'price', 'priceinclvat' => 'price',
    'colour' => 'colour', 'color' => 'colour', 'exteriorcolour' => 'colour', 'exteriorcolor' => 'colour',
    'fuel' => 'fuel', 'fueltype' => 'fuel', 'transmission' => 'transmission', 'gearbox' => 'transmission',
    'body' => 'body', 'bodytype' => 'body', 'bodystyle' => 'body', 'category' => 'category', 'type' => 'category',
    'condition' => 'condition', 'newused' => 'condition',
    'images' => 'images', 'image' => 'images', 'imageurl' => 'images', 'imageurls' => 'images', 'photos' => 'images',
    'photo' => 'images', 'photourl' => 'images', 'pictures' => 'images', 'picture' => 'images', 'media' => 'images',
    'registration' => 'reg', 'regno' => 'reg', 'registrationnumber' => 'reg',
    'kilometres' => 'mileage', 'kilometers' => 'mileage', 'kmdriven' => 'mileage',
    'location' => 'location', 'branch' => 'location', 'city' => 'location', 'town' => 'location',
];

/**
 * A money or distance figure as written by South African sites and exports: "R129.900",
 * "R 129 900", "129,900.00", "84 500 km". A dot or comma followed by exactly three digits is a
 * thousands separator, not decimals.
 */
function dealer_number(string $text): float
{
    $text = preg_replace('/[^\d.,]/', '', $text) ?? '';
    if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $text)) {
        return (float) preg_replace('/[.,]/', '', $text);
    }
    if (preg_match('/^(\d[\d.,]*?)[.,](\d{1,2})$/', $text, $m)) {   // "129900.50" / "129 900,50"
        return (float) (preg_replace('/[.,]/', '', $m[1]) . '.' . $m[2]);
    }
    return (float) preg_replace('/[.,]/', '', $text);
}

function dealer_key(string $name): string
{
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $name) ?? '');
}

// ── Reading a file ────────────────────────────────────────────

/**
 * Turns an export into rows of raw name => value pairs, whatever the format.
 *
 * @return array{rows: list<array<string, string|list<string>>>, format: string, error: string}
 */
function dealer_read(string $contents, string $fileName = ''): array
{
    $trimmed = ltrim($contents, "\xEF\xBB\xBF \t\r\n");
    $result = static fn(array $rows, string $format, string $error = ''): array => ['rows' => $rows, 'format' => $format, 'error' => $error];
    if ($trimmed === '') {
        return $result([], '', 'The file is empty.');
    }
    if (str_starts_with($contents, "PK") || str_ends_with(strtolower($fileName), '.xlsx')) {
        return $result(dealer_read_xlsx($contents), 'Excel');
    }
    if ($trimmed[0] === '{' || $trimmed[0] === '[') {
        $data = json_decode($trimmed, true);
        return is_array($data) ? $result(dealer_read_json($data), 'JSON') : $result([], 'JSON', 'That JSON could not be read.');
    }
    if ($trimmed[0] === '<') {
        // A web page is not a stock feed: say so plainly instead of "no vehicles found".
        if (preg_match('~<!doctype html|<html[\s>]~i', substr($trimmed, 0, 2000))) {
            return $result([], 'web page', 'That link is a web page (a website listing), not a stock feed. Ask the dealer for the feed link '
                . 'or export from their dealer system — the same one that sends their stock to the classifieds.');
        }
        return $result(dealer_read_xml($trimmed), 'XML');
    }
    if (str_ends_with(strtolower($fileName), '.xls')) {
        return $result([], '', 'Old Excel files (.xls) cannot be read — open it in Excel and save it as .xlsx or CSV.');
    }
    return $result(dealer_read_csv($trimmed), 'CSV');
}

/** @return list<array<string, string>> */
function dealer_read_csv(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $first = strtok($text, "\n") ?: '';
    // Excel in South Africa often saves with semicolons; some systems use tabs.
    $delimiter = array_search(max($counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")]), $counts, true);
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $text);
    rewind($handle);
    $headers = array_map(static fn($h) => trim((string) $h), fgetcsv($handle, null, (string) $delimiter) ?: []);
    $rows = [];
    while (($values = fgetcsv($handle, null, (string) $delimiter)) !== false && count($rows) < 2000) {
        if (count(array_filter($values, static fn($v) => trim((string) $v) !== '')) === 0) {
            continue;
        }
        $row = [];
        foreach ($headers as $i => $header) {
            $row[$header] = trim((string) ($values[$i] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

/** The first sheet of an .xlsx, first row as the column names. @return list<array<string, string>> */
function dealer_read_xlsx(string $contents): array
{
    $file = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($file, $contents);
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        @unlink($file);
        return [];
    }
    $strings = [];
    if (($shared = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = @simplexml_load_string($shared);
        foreach ($xml ? $xml->si : [] as $si) {
            $strings[] = trim(isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]') ?: [])));
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($file);
    $xml = $sheet !== false ? @simplexml_load_string($sheet) : false;
    if (!$xml) {
        return [];
    }
    $grid = [];
    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $column = preg_replace('/\d+/', '', (string) $c['r']);
            $index = 0;
            foreach (str_split((string) $column) as $letter) {
                $index = $index * 26 + (ord($letter) - 64);
            }
            $value = (string) $c->v;
            if ((string) $c['t'] === 's') {
                $value = $strings[(int) $value] ?? '';
            } elseif ((string) $c['t'] === 'inlineStr') {
                $value = (string) $c->is->t;
            }
            $cells[$index - 1] = trim($value);
        }
        $grid[] = $cells;
    }
    $headers = array_shift($grid) ?? [];
    $rows = [];
    foreach (array_slice($grid, 0, 2000) as $cells) {
        $row = [];
        foreach ($headers as $i => $header) {
            $row[(string) $header] = (string) ($cells[$i] ?? '');
        }
        if (array_filter($row, static fn($v) => $v !== '')) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/** A list of vehicle objects, wherever in the JSON it sits. @return list<array<string, mixed>> */
function dealer_read_json(array $data): array
{
    if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
        return array_map('dealer_flatten', array_slice($data, 0, 2000));
    }
    foreach ($data as $value) {   // {"vehicles": [...]}, {"data": {"items": [...]}} …
        if (is_array($value) && ($found = dealer_read_json($value))) {
            return $found;
        }
    }
    return [];
}

/** Nested objects become "parent child" keys; lists of photo addresses stay lists. */
function dealer_flatten(array $item, string $prefix = ''): array
{
    $out = [];
    foreach ($item as $key => $value) {
        $name = trim($prefix . ' ' . $key);
        if (is_array($value) && array_is_list($value)) {
            $urls = dealer_urls($value);
            if ($urls) {
                $out[$name] = $urls;
            }
        } elseif (is_array($value)) {
            $out += dealer_flatten($value, (string) $key);
        } else {
            $out[$name] = trim((string) $value);
        }
    }
    return $out;
}

/** Every photo address in a value (string, list, or nested structure). @return list<string> */
function dealer_urls(mixed $value): array
{
    $text = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : (string) $value;
    preg_match_all('~https?://[^\s"\'<>|,;]+~i', (string) $text, $m);
    return array_values(array_unique(array_filter($m[0], static fn($u) => preg_match('~\.(jpe?g|png|webp)(\?|$)|/image|/photo|imagekit|cloudinary|img~i', $u))));
}

/** The repeated element that holds the vehicles, read as flat rows. @return list<array<string, mixed>> */
function dealer_read_xml(string $text): array
{
    $xml = @simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if (!$xml) {
        return [];
    }
    // The vehicles are the most numerous elements that have children.
    $counts = [];
    foreach ($xml->xpath('//*[*]') ?: [] as $node) {
        $counts[$node->getName()] = ($counts[$node->getName()] ?? 0) + 1;
    }
    arsort($counts);
    foreach (array_keys($counts) as $name) {
        $rows = [];
        foreach ($xml->xpath('//*[local-name()="' . $name . '"]') ?: [] as $node) {
            $row = [];
            foreach ($node->attributes() as $attr => $value) {
                $row[(string) $attr] = trim((string) $value);
            }
            foreach ($node->children() as $child) {
                $key = $child->getName();
                $row[$key] = $child->count() > 0 || dealer_urls($child->asXML()) ? (dealer_urls($child->asXML()) ?: trim((string) $child)) : trim((string) $child);
            }
            $rows[] = $row;
        }
        $mapped = array_map('dealer_normalise', array_slice($rows, 0, 3));
        if ($rows && array_filter($mapped, static fn($r) => $r['make'] !== '')) {
            return array_slice($rows, 0, 2000);
        }
    }
    return [];
}

// ── Making sense of a row ─────────────────────────────────────

/**
 * Raw dealer row => the fields we use.
 *
 * @return array{ref: string, vin: string, make: string, model: string, variant: string, year: int, mileage: int, price: float,
 *   colour: string, fuel: string, transmission: string, body: string, category: string, condition: string, description: string,
 *   images: list<string>, reg: string}
 */
function dealer_normalise(array $raw): array
{
    $v = ['ref' => '', 'vin' => '', 'make' => '', 'model' => '', 'variant' => '', 'title' => '', 'year' => '', 'mileage' => '', 'price' => '',
        'colour' => '', 'fuel' => '', 'transmission' => '', 'body' => '', 'category' => '', 'condition' => '', 'description' => '', 'images' => [], 'reg' => '', 'location' => ''];
    foreach ($raw as $name => $value) {
        $key = dealer_key((string) $name);
        $last = dealer_key((string) preg_replace('/^.*\s/', '', (string) $name));   // "specs transmission" → "transmission"
        $field = DEALER_FIELDS[$key] ?? DEALER_FIELDS[preg_replace('/^(vehicle|car|stock|ad)/', '', $key)] ?? DEALER_FIELDS[$last] ?? null;
        if ($field === null) {
            if (dealer_urls($value)) {
                $v['images'] = array_merge($v['images'], dealer_urls($value));   // a photo column with an odd name
            }
            continue;
        }
        if ($field === 'images') {
            $v['images'] = array_merge($v['images'], is_array($value) ? dealer_urls($value) : dealer_urls(str_replace(['|', ';', ' '], ',', (string) $value)));
        } elseif (!is_array($value) && $v[$field] === '') {
            $v[$field] = trim((string) $value);
        }
    }
    // A title like "2021 Toyota Hilux 2.8 GD-6" fills what is missing.
    if (($v['make'] === '' || $v['year'] === '') && $v['title'] !== '' && preg_match('/^(?:(\d{4})\s+)?(\S+)\s+(.+)$/', $v['title'], $m)) {
        $v['year'] = $v['year'] !== '' ? $v['year'] : ($m[1] ?? '');
        $v['make'] = $v['make'] !== '' ? $v['make'] : $m[2];
        $v['model'] = $v['model'] !== '' ? $v['model'] : $m[3];
    }
    $number = static fn(string $s): float => dealer_number($s);
    $v['variant'] = implode(', ', array_unique(array_map('trim', explode(',', $v['variant']))));
    $v['model'] = implode(', ', array_unique(array_map('trim', explode(',', $v['model']))));
    $model = trim($v['model'] . ($v['variant'] !== '' && !str_contains(mb_strtolower($v['model']), mb_strtolower($v['variant'])) ? ' ' . $v['variant'] : ''));
    $text = mb_strtolower($v['body'] . ' ' . $v['category'] . ' ' . $model);
    $category = match (true) {
        (bool) preg_match('/\btractors?\b(?! unit)|massey|john deere|new holland|landini|kubota|case ih|deutz|claas|baler|harvester|planter|plough|mower|sprayer|\bfarm/', $text . ' ' . mb_strtolower($v['make'])) => 'farm',
        (bool) preg_match('/excavator|dozer|crusher|grader|\btlb\b|backhoe|forklift|telehandler|\badt\b/', $text) => 'plant',
        (bool) preg_match('/\blink\b|lowbed|flatdeck|superlink|\btrailers?\b/', $text) => 'trailer',   // "side tipper link" is towed
        (bool) preg_match('/bakkie|pick-?up|pik-? ?up|double cab|single cab|d\/cab|s\/cab|extended cab|super ?cab|hilux|ranger|d-max|navara|amarok|triton|bt-50|np200|np300|ldv/', $text) => 'bakkie',
        (bool) preg_match('/truck|tipper|horse|rigid|tractor unit|canter|npr|nqr|fvz|ftr|frr|fsr|fvr|actros|axor|atego|quon|quester|\bud\s?\d|\b(hino|fuso|faw|scania|iveco|tata|daf|powerstar)\b|\bman tg|volvo f[hm]/', $text . ' ' . mb_strtolower($v['make'])) => 'truck',
        (bool) preg_match('/trailer|superlink|tautliner|flatdeck|lowbed/', $text) => 'trailer',
        (bool) preg_match('/\bford \d{4}\b/', $text . ' ' . mb_strtolower($v['make'] . ' ' . $v['model'])) => 'farm',   // old Ford tractors (Ford 6610)
        (bool) preg_match('/tlb|excavator|loader|grader|forklift|bobcat|backhoe|machine|dozer|crusher|compactor|telehandler/', $text . ' ' . mb_strtolower($v['make'] . ' ' . $v['model'])) => 'plant',
        default => 'car',
    };
    $year = (int) preg_replace('/\D/', '', substr($v['year'], 0, 4));
    return [
        'ref' => mb_substr($v['ref'] !== '' ? $v['ref'] : ($v['vin'] !== '' ? $v['vin'] : ($v['reg'] !== '' ? $v['reg'] : md5(json_encode($raw)))), 0, 80),
        'vin' => $v['vin'],
        'make' => mb_strtoupper(trim($v['make'])),
        'model' => mb_strtoupper(mb_substr($model, 0, 150)),
        'variant' => $v['variant'],
        'year' => $year >= 1950 && $year <= (int) date('Y') + 1 ? $year : 0,
        'mileage' => (int) $number($v['mileage']),
        'price' => $number($v['price']),
        'colour' => mb_substr($v['colour'], 0, 40),
        'fuel' => mb_strtoupper(mb_substr($v['fuel'], 0, 30)),
        'transmission' => mb_strtoupper(mb_substr($v['transmission'], 0, 30)),
        'body' => mb_substr($v['body'], 0, 60),
        'category' => $category,
        'condition' => mb_strtoupper(mb_substr($v['condition'] ?: 'Used', 0, 30)),
        'description' => mb_substr(strip_tags($v['description']), 0, 5000),
        'images' => array_slice(array_values(array_unique($v['images'])), 0, 30),
        'reg' => $v['reg'],
        'location' => mb_substr(trim($v['location']), 0, 80),
    ];
}

// ── Loading and importing ─────────────────────────────────────

/** Where a loaded file waits for the picker (storage is not served to the web). */
function dealer_fetch_feed(string $url, string &$error = ''): ?array
{
    if (!preg_match('~^https?://~i', $url)) {
        $error = 'The link must start with http:// or https://';
        return null;
    }
    if (!dealer_public_url($url)) {
        $error = 'That link points to a private or local address.';
        return null;
    }
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 60,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => APP_NAME . ' stock import']);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (!is_string($body) || $status !== 200) {
        $error = 'The feed did not answer (HTTP ' . $status . ').';
        return null;
    }
    return [$body, basename((string) parse_url($url, PHP_URL_PATH))];
}

/**
 * The location a dealer's link narrows to, e.g. "nelspruit" from
 * …/?taxonomy=location&term=nelspruit or …/location/nelspruit/. '' for all of them.
 */
function dealer_site_location(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    if (($query['taxonomy'] ?? '') === 'location' && !empty($query['term'])) {
        return (string) $query['term'];
    }
    return preg_match('~/location/([a-z0-9-]+)~i', (string) parse_url($url, PHP_URL_PATH), $m) ? $m[1] : '';
}

/**
 * The vehicles on a dealer's own website. Two kinds of site are read:
 *  - the WordPress "Motors" car-dealership plugin (StylemixThemes), common among South African
 *    dealers: its sitemap (listings-sitemap.xml) lists every vehicle page. A link to one
 *    location (?taxonomy=location&term=nelspruit) keeps just the vehicles at that branch.
 *  - any other dealer site whose stock pages link to a page per vehicle (WooCommerce and most
 *    dealer-website builders): the stock pages are followed page by page (page 2, 3…), then each
 *    vehicle page is read — its product data, its spec list and its photo gallery.
 * Vehicle pages are fetched a few at a time, and the site's robots.txt is respected.
 *
 * Use it for dealers who have agreed to their stock being listed on BidCo.
 *
 * @return list<array<string, mixed>> raw rows for dealer_normalise()
 */
function dealer_read_site(string $url, string &$error = ''): array
{
    $parts = parse_url($url);
    $host = (string) ($parts['host'] ?? 'the site');
    $base = ($parts['scheme'] ?? 'https') . '://' . $host;
    $sitemap = dealer_http_get($base . '/listings-sitemap.xml');
    preg_match_all('~<loc>\s*(' . preg_quote($base, '~') . '/listings/[^<\s]+)\s*</loc>~i', (string) $sitemap, $m);
    $pages = array_values(array_unique($m[1] ?? []));
    $motors = (bool) $pages;
    if (!$motors) {
        $pages = dealer_site_vehicle_pages($url, $error);
        if (!$pages) {
            return [];
        }
    }
    $location = $motors ? dealer_site_location($url) : '';
    $rows = [];
    foreach (dealer_fetch_pages(array_slice($pages, 0, 400)) as $pageUrl => $html) {
        $row = $html ? ($motors ? dealer_parse_motors_page($html, $pageUrl) : dealer_parse_vehicle_page($html, $pageUrl)) : null;
        if ($row === null) {
            continue;
        }
        if ($location !== '' && dealer_slug((string) ($row['Location'] ?? '')) !== dealer_slug($location)) {
            continue;   // another branch
        }
        $rows[] = $row;
    }
    if (!$rows) {
        $error = 'Read ' . count($pages) . ' vehicle pages on ' . $host . ', but none ' . ($location !== '' ? 'at ' . $location : 'had a make and model') . '.';
    }
    return dealer_drop_sitewide_prices($rows);
}

/**
 * A price read from a page's text that most of the dealer's pages share is the site's, not the
 * vehicle's (a banner, a filter): each such vehicle takes the next amount on its page, or none.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function dealer_drop_sitewide_prices(array $rows): array
{
    $fromText = array_filter($rows, static fn($r) => isset($r['_price_candidates']));
    if (count($rows) >= 4 && $fromText) {
        $seen = [];
        foreach ($fromText as $r) {
            foreach ($r['_price_candidates'] as $amount) {
                $seen[$amount] = ($seen[$amount] ?? 0) + 1;
            }
        }
        $siteWide = array_keys(array_filter($seen, static fn($n) => $n >= max(3, count($rows) * 0.5)));
        foreach ($rows as $i => $r) {
            if (isset($r['_price_candidates'])) {
                $own = array_values(array_diff($r['_price_candidates'], $siteWide));
                $rows[$i]['Price'] = $own[0] ?? '';
            }
        }
    }
    foreach ($rows as $i => $r) {
        unset($rows[$i]['_price_candidates']);
    }
    return $rows;
}

/**
 * Vehicle pages read in the last hour are kept (storage/dealer-stock/pages), so a second branch
 * of the same site, or the nightly run doing both, does not download everything again.
 *
 * @param list<string> $pages
 * @return array<string, ?string> url => html
 */
function dealer_fetch_pages(array $pages): array
{
    $cacheDir = dealer_dir() . '/pages';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cached = static fn(string $page): string => $cacheDir . '/' . md5($page) . '.html';
    foreach (glob($cacheDir . '/*.html') ?: [] as $old) {
        if (filemtime($old) < time() - 3600) {
            @unlink($old);
        }
    }
    $bodies = [];
    foreach (array_chunk($pages, 8) as $chunk) {
        $missing = [];
        foreach ($chunk as $page) {
            if (is_file($cached($page))) {
                $bodies[$page] = (string) file_get_contents($cached($page));
            } else {
                $missing[] = $page;
            }
        }
        foreach ($missing ? dealer_http_get_many($missing) : [] as $page => $html) {
            $bodies[$page] = $html;
            if ($html) {
                @file_put_contents($cached($page), $html);
            }
        }
    }
    return $bodies;
}

/**
 * The vehicle pages a dealer's stock page links to, following its pages (…/page/2/, ?page=2)
 * to the end. A vehicle page is a link on the same site whose address names a year
 * ("/usedcars/2021-ford-ranger-wildtrak-…/"); the folder most of them share is taken, so menu
 * and blog links are left out.
 *
 * @return list<string>
 */
function dealer_site_vehicle_pages(string $url, string &$error = ''): array
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    $base = (parse_url($url, PHP_URL_SCHEME) ?: 'https') . '://' . $host;
    $blocked = dealer_robots_disallow($base);
    $allowed = static function (string $link) use ($blocked): bool {
        $path = (string) parse_url($link, PHP_URL_PATH) . (parse_url($link, PHP_URL_QUERY) ? '?' . parse_url($link, PHP_URL_QUERY) : '');
        foreach ($blocked as $rule) {
            if (preg_match($rule, $path)) {
                return false;
            }
        }
        return true;
    };
    if (!$allowed($url)) {
        $error = $host . ' asks robots not to read that page (robots.txt). Ask the dealer for the feed link or export from their dealer system.';
        return [];
    }
    $listPath = rtrim((string) preg_replace('~/page/\d+/?$~', '/', (string) parse_url($url, PHP_URL_PATH)), '/');
    $queue = [$url];
    $seen = [];
    $vehicles = [];   // folder => [link => true]
    while ($queue && count($seen) < 60) {
        $page = array_shift($queue);
        $seen[$page] = true;
        $html = dealer_http_get($page);
        if (!$html) {
            continue;
        }
        preg_match_all('~href\s*=\s*["\']([^"\'#]+)["\']~i', $html, $links);
        $before = array_sum(array_map('count', $vehicles));
        foreach (array_unique($links[1]) as $link) {
            $link = html_entity_decode(trim($link), ENT_QUOTES);
            if (str_starts_with($link, '/') && !str_starts_with($link, '//')) {
                $link = $base . $link;
            }
            if ((string) parse_url($link, PHP_URL_HOST) !== $host || !$allowed($link)) {
                continue;
            }
            $path = (string) parse_url($link, PHP_URL_PATH);
            // Another stock page: …/used-cars/page/3/ or …/used-cars/?page=3
            parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
            $isNext = preg_match('~^' . preg_quote($listPath, '~') . '/page/\d+/?$~', $path)
                || ($path !== '' && rtrim($path, '/') === $listPath && (isset($query['page']) || isset($query['paged']) || isset($query['pg'])));
            if ($isNext) {
                if (!isset($seen[$link]) && !in_array($link, $queue, true)) {
                    $queue[] = $link;
                }
                continue;
            }
            $slug = basename(rtrim($path, '/'));
            if (preg_match('/(?:^|[-_])(19[5-9]\d|20[0-4]\d)(?:[-_]|$)/', $slug) && preg_match('/[a-z]{3}/i', $slug) && rtrim($path, '/') !== $listPath) {
                $vehicles[dirname(rtrim($path, '/'))][$base . $path] = true;
            }
        }
        if ($page !== $url && array_sum(array_map('count', $vehicles)) === $before) {
            break;   // a stock page with nothing new: past the last page
        }
    }
    uasort($vehicles, static fn($a, $b) => count($b) <=> count($a));
    $pages = array_keys((array) reset($vehicles));
    if (count($pages) < 2) {
        $error = 'That link is a web page, but no vehicles were found on it. Paste the dealer\'s stock page (the page listing all their vehicles), '
            . 'or ask the dealer for the feed link or export from their dealer system.';
        return [];
    }
    return $pages;
}

/**
 * The paths a site's robots.txt asks every robot to stay out of, as patterns.
 *
 * @return list<string>
 */
function dealer_robots_disallow(string $base): array
{
    $rules = [];
    $applies = false;
    $inRules = false;
    foreach (preg_split('/\R/', (string) dealer_http_get($base . '/robots.txt', 15)) ?: [] as $line) {
        $line = trim((string) preg_replace('/#.*/', '', $line));
        if (!preg_match('/^([a-z-]+)\s*:\s*(.*)$/i', $line, $m)) {
            continue;
        }
        $field = strtolower($m[1]);
        if ($field === 'user-agent') {
            $applies = $inRules ? $m[2] === '*' : ($applies || $m[2] === '*');
            $inRules = false;
        } elseif ($field === 'disallow' || $field === 'allow') {
            $inRules = true;
            if ($applies && $field === 'disallow' && $m[2] !== '') {
                $rules[] = '~^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($m[2], '~')) . '~';
            }
        }
    }
    return $rules;
}

/**
 * One vehicle page of any dealer site → a raw row. Read from, in turn: the page's product data
 * (schema.org Product/Car/Vehicle), its spec list ("Fuel Type: Diesel", "Mileage 84 000 km"),
 * its heading, and its largest photo gallery.
 */
function dealer_parse_vehicle_page(string $html, string $pageUrl): ?array
{
    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $x = new DOMXPath($doc);
    $clean = static fn(string $s): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    $row = [];
    $photos = [];

    // 1. Product data: <script type="application/ld+json"> with a Product, Car or Vehicle.
    foreach ($x->query('//script[@type="application/ld+json"]') ?: [] as $script) {
        $data = json_decode($script->textContent, true);
        $stack = is_array($data) ? [$data] : [];
        while ($stack) {
            $item = array_pop($stack);
            foreach ($item as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
            $types = array_map('strtolower', (array) ($item['@type'] ?? []));
            if (!array_intersect($types, ['product', 'car', 'vehicle', 'motorizedbicycle', 'individualproduct'])) {
                continue;
            }
            $offers = isset($item['offers']['@type']) || isset($item['offers']['price']) ? [$item['offers']] : (array) ($item['offers'] ?? []);
            $prices = [];
            foreach ($offers as $offer) {
                foreach ([$offer['price'] ?? null, $offer['lowPrice'] ?? null, $offer['priceSpecification'][0]['price'] ?? null] as $price) {
                    if (is_scalar($price) && dealer_number((string) $price) > 0) {
                        $prices[] = dealer_number((string) $price);
                    }
                }
            }
            $brand = $item['brand'] ?? $item['manufacturer'] ?? '';
            $row += array_filter([
                'Title' => $clean((string) ($item['name'] ?? '')),
                'Stock No' => (string) ($item['sku'] ?? $item['productID'] ?? $item['vehicleIdentificationNumber'] ?? ''),
                'VIN' => (string) ($item['vehicleIdentificationNumber'] ?? ''),
                'Make' => $clean(is_array($brand) ? (string) ($brand['name'] ?? '') : (string) $brand),
                'Model' => $clean(is_scalar($item['model'] ?? null) ? (string) $item['model'] : (string) ($item['model']['name'] ?? '')),
                'Year' => (string) ($item['vehicleModelDate'] ?? $item['modelDate'] ?? $item['productionDate'] ?? ''),
                'Mileage' => (string) (is_array($item['mileageFromOdometer'] ?? null) ? ($item['mileageFromOdometer']['value'] ?? '') : ($item['mileageFromOdometer'] ?? '')),
                'Colour' => is_scalar($item['color'] ?? null) ? (string) $item['color'] : '',
                'Fuel' => is_scalar($item['fuelType'] ?? null) ? (string) $item['fuelType'] : '',
                'Transmission' => is_scalar($item['vehicleTransmission'] ?? null) ? (string) $item['vehicleTransmission'] : '',
                'Body' => is_scalar($item['bodyType'] ?? null) ? (string) $item['bodyType'] : '',
                'Price' => $prices ? (string) min($prices) : '',
                'Description' => mb_strlen($clean((string) ($item['description'] ?? ''))) > 40 ? $clean((string) $item['description']) : '',
            ], static fn($v) => $v !== '');
            foreach ((array) ($item['image'] ?? []) as $image) {
                $photos[] = is_array($image) ? (string) ($image['url'] ?? $image['contentUrl'] ?? '') : (string) $image;
            }
        }
    }

    // 2. The spec list: a label ("Fuel Type:") followed by its value, in the page's text.
    $labels = ['make' => 'Make', 'brand' => 'Make', 'model' => 'Model', 'variant' => 'Variant', 'derivative' => 'Variant',
        'year' => 'Year', 'modelyear' => 'Year', 'registrationyear' => 'Year', 'mileage' => 'Mileage', 'kilometres' => 'Mileage',
        'kilometers' => 'Mileage', 'odometer' => 'Mileage', 'kms' => 'Mileage', 'km' => 'Mileage', 'fueltype' => 'Fuel', 'fuel' => 'Fuel',
        'transmission' => 'Transmission', 'transmissiontype' => 'Transmission', 'gearbox' => 'Transmission', 'bodytype' => 'Body',
        'body' => 'Body', 'colour' => 'Colour', 'color' => 'Colour', 'exteriorcolour' => 'Colour', 'sku' => 'Stock No',
        'stockno' => 'Stock No', 'stocknumber' => 'Stock No', 'stockcode' => 'Stock No', 'stockid' => 'Stock No', 'reference' => 'Stock No',
        'vin' => 'VIN', 'location' => 'Location', 'branch' => 'Location', 'condition' => 'Condition'];
    $body = $x->query('//body')->item(0);
    $lines = [];
    if ($body) {
        foreach ($x->query('.//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)]', $body) ?: [] as $node) {
            $line = trim(preg_replace('/\s+/u', ' ', $node->nodeValue) ?? '');
            if ($line !== '') {
                $lines[] = $line;
            }
        }
    }
    foreach ($lines as $i => $line) {
        if (mb_strlen($line) > 40) {
            continue;
        }
        // "Fuel Type:" then "Diesel" on the next line, or "Fuel Type: Diesel" on one.
        if (preg_match('/^([A-Za-z .\/]{2,30}?)\s*:\s*(.*)$/u', $line, $m) || isset($labels[dealer_key($line)])) {
            $label = $m[1] ?? $line;
            $value = trim($m[2] ?? '') !== '' ? trim($m[2]) : ($lines[$i + 1] ?? '');
            $column = $labels[dealer_key($label)] ?? null;
            // Not a search filter's heading ("Make", "Model", then "Toyota (12)") or another label.
            if ($column !== null && $value !== '' && mb_strlen($value) <= 80 && !isset($row[$column]) && !preg_match('/:\s*$|\(\d+\)$/', $value)
                && !isset($labels[dealer_key($value)])) {
                $row[$column] = $value;
            }
        }
    }

    // 3. The heading, and what it says: "2021 Ford Ranger Wildtrak 2.0 Bi Turbo…".
    $h1 = $clean((string) ($x->query('//h1')->item(0)?->textContent ?? ''));
    $title = $h1 !== '' && preg_match('/\d{4}|[a-z]{3}/i', $h1) ? $h1 : ($row['Title'] ?? $clean((string) ($x->query('//meta[@property="og:title"]/@content')->item(0)?->nodeValue ?? '')));
    $row['Title'] = $title;
    if (!isset($row['Year']) && preg_match('/\b(19[5-9]\d|20[0-4]\d)\b/', $title . ' ' . basename(rtrim($pageUrl, '/')), $y)) {
        $row['Year'] = $y[1];
    }
    // The price — this vehicle's own, never a figure every page carries (a "finance from R…"
    // banner, a price filter, another vehicle further down):
    $isPrice = static fn(float $n): bool => $n >= 5000 && $n <= 50000000;
    $outside = 'not(ancestor::header) and not(ancestor::footer) and not(ancestor::nav) and not(ancestor::aside) and not(ancestor::form)'
        . ' and not(ancestor::*[contains(@class,"related") or contains(@class,"sidebar") or contains(@class,"widget") or contains(@class,"filter")'
        . ' or contains(@class,"menu") or contains(@class,"footer") or contains(@class,"header") or contains(@class,"similar") or contains(@class,"finance")])';
    // a) price tags (<meta property="product:price:amount">, itemprop="price");
    if (!isset($row['Price'])) {
        foreach ($x->query('//meta[@property="product:price:amount" or @property="og:price:amount" or @itemprop="price"]/@content | //*[@itemprop="price"]/@content') ?: [] as $attr) {
            if ($isPrice(dealer_number($attr->nodeValue))) {
                $row['Price'] = (string) dealer_number($attr->nodeValue);
                break;
            }
        }
    }
    // b) the page's own price element (class "price"), outside headers, sidebars and related vehicles;
    if (!isset($row['Price'])) {
        foreach ($x->query('//*[contains(translate(@class,"PRICE","price"),"price") and ' . $outside . ']') ?: [] as $node) {
            preg_match_all('/R\s?(\d[\d ,.]*\d)/u', $clean($node->textContent), $found);
            $amounts = array_values(array_filter(array_map('dealer_number', $found[1] ?? []), $isPrice));
            if ($amounts) {
                $row['Price'] = (string) min($amounts);   // a sale shows the old and new price: the lower
                break;
            }
        }
    }
    // c) else the rand amounts in the page's main text, in order — dealer_read_site() drops any
    //    that most of the dealer's pages share before taking the first.
    $candidates = [];
    if ($body) {
        $mainLines = [];
        foreach ($x->query('.//text()[not(ancestor::script) and not(ancestor::style) and ' . $outside . ']', $body) ?: [] as $node) {
            $line = trim(preg_replace('/\s+/u', ' ', $node->nodeValue) ?? '');
            if ($line !== '') {
                $mainLines[] = $line;
            }
        }
        foreach ($mainLines as $i => $line) {   // "R 459 950", or "R" then "459 950"
            $amount = preg_match('/^R\s?(\d[\d ,.]*\d)$/u', $line, $p) ? $p[1] : ($line === 'R' && preg_match('/^\d[\d ,.]*\d$/', $mainLines[$i + 1] ?? '') ? $mainLines[$i + 1] : '');
            if ($amount !== '' && $isPrice(dealer_number($amount))) {
                $candidates[] = (string) dealer_number($amount);
            }
        }
    }
    if (!isset($row['Price']) && $candidates) {
        $row['Price'] = $candidates[0];
        $row['_price_candidates'] = array_values(array_unique($candidates));
    }
    if (!isset($row['Mileage'])) {
        foreach ($lines as $line) {
            if (preg_match('/^(\d[\d ,.]*)\s?km$/iu', $line, $km)) {
                $row['Mileage'] = $km[1];
                break;
            }
        }
    }
    if (!isset($row['Make']) && $title !== '') {
        // "2021 Ford Ranger Wildtrak…" → make Ford, model Ranger Wildtrak…; two-word makes kept whole.
        $words = preg_split('/\s+/', trim((string) preg_replace('/^\s*(19|20)\d{2}\s+/', '', $title))) ?: [];
        $two = mb_strtolower(implode(' ', array_slice($words, 0, 2)));
        $take = in_array($two, ['mercedes benz', 'mercedes-benz amg', 'land rover', 'alfa romeo', 'aston martin', 'rolls royce', 'great wall', 'range rover', 'john deere', 'new holland', 'massey ferguson', 'sa truck'], true) ? 2 : 1;
        $row['Make'] = implode(' ', array_slice($words, 0, $take));
        $row['Model'] = $row['Model'] ?? implode(' ', array_slice($words, $take));
    } elseif (isset($row['Make']) && !isset($row['Model']) && $title !== '') {
        $row['Model'] = trim((string) preg_replace('/^.*?\b' . preg_quote($row['Make'], '/') . '\b/i', '', $title)) ?: $title;
    }
    if (empty($row['Make']) || empty($row['Model'])) {
        return null;
    }
    // The shop's categories ("Used Trucks", "Used Machines") tell trucks, trailers and machines apart.
    if (!isset($row['Category'])) {
        $row['Category'] = implode(', ', array_unique(array_filter(array_map(static fn($n) => $clean($n->textContent),
            iterator_to_array($x->query('//*[contains(@class,"posted_in")]//a | //nav[contains(@class,"breadcrumb")]//a') ?: [])),
            static fn($c) => $c !== '' && !preg_match('/^(home|shop|uncategori[sz]ed|product catalogue|used cars|stock)$/i', $c))));
    }
    if (!isset($row['Stock No'])) {
        $short = $x->query('//link[@rel="shortlink"]/@href')->item(0);
        $row['Stock No'] = $short && preg_match('/[?&]p=(\d+)/', $short->nodeValue, $p) ? 'WP' . $p[1] : basename(rtrim($pageUrl, '/'));
    }
    if (!isset($row['Description'])) {
        // The dealer's own write-up: the longest paragraph on the page, else the page summary.
        $paragraphs = array_filter(array_map(static fn($p) => $clean($p->textContent), iterator_to_array($x->query('//p[not(ancestor::footer) and not(ancestor::header)'
            . ' and not(ancestor::nav) and not(ancestor::aside) and not(ancestor::*[contains(@class,"footer") or contains(@class,"related") or contains(@class,"sidebar")])]') ?: [])),
            static fn($p) => mb_strlen($p) >= 150);
        usort($paragraphs, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $row['Description'] = $paragraphs[0] ?? $clean((string) ($x->query('//meta[@property="og:description" or @name="description"]/@content')->item(0)?->nodeValue ?? ''));
    }

    // 4. Photos: the largest set of pictures kept together in one folder is the vehicle's gallery
    //    (logos, icons and other vehicles' thumbnails are single pictures elsewhere).
    foreach ($x->query('//img/@src | //img/@data-src | //img/@data-lazy-src | //img/@data-original | //a/@href | //img/@srcset | //source/@srcset | //*/@data-thumb | //*/@data-image') ?: [] as $attr) {
        foreach (preg_split('/\s*,\s*/', trim($attr->nodeValue)) ?: [] as $candidate) {
            $photos[] = preg_replace('/\s+\d+[wx]$/', '', trim($candidate));
        }
    }
    $folders = [];
    foreach ($photos as $photo) {
        $photo = html_entity_decode((string) $photo, ENT_QUOTES);
        if (str_starts_with($photo, '//')) {
            $photo = 'https:' . $photo;
        } elseif (str_starts_with($photo, '/')) {
            $photo = (parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https') . '://' . parse_url($pageUrl, PHP_URL_HOST) . $photo;
        }
        if (!preg_match('~^https?://[^?#]+\.(jpe?g|png|webp)(\?[^#]*)?$~i', $photo)
            || preg_match('~logo|icon|favicon|button|banner|placeholder|qr-?code|whatsapp|avatar|cropped-|/plugins/|/themes/~i', $photo)) {
            continue;
        }
        $photo = (string) preg_replace('~-\d+x\d+(\.(?:jpe?g|png|webp))$~i', '$1', $photo);   // WordPress thumbnail → full size
        $folders[dirname((string) parse_url($photo, PHP_URL_HOST) . parse_url($photo, PHP_URL_PATH))][$photo] = true;
    }
    uasort($folders, static fn($a, $b) => count($b) <=> count($a));
    $row['Photos'] = array_slice(array_keys((array) reset($folders) ?: []), 0, 30);
    $row['Source page'] = $pageUrl;
    return $row;
}
/** One vehicle page of a Motors site → a raw row (details list, title, price, photos). */
function dealer_parse_motors_page(string $html, string $pageUrl): ?array
{
    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $x = new DOMXPath($doc);
    $text = static fn(?DOMNode $n): string => $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent) ?? '') : '';

    // The details list: <li class="data-list-item"><span class="item-label">Make</span><span title="Volvo">Volvo</span></li>
    $row = [];
    foreach ($x->query('//*[contains(@class,"stm-single-car-listing-data")]//li | //*[contains(@class,"car-listing-data")]//tr') ?: [] as $item) {
        $label = $text($x->query('.//*[contains(@class,"item-label") or contains(@class,"t-label")]', $item)->item(0));
        $valueNode = $x->query('.//*[contains(@class,"heading-font") or contains(@class,"t-value")]', $item)->item(0);
        $value = $valueNode instanceof DOMElement && $valueNode->getAttribute('title') !== '' ? trim($valueNode->getAttribute('title')) : $text($valueNode);
        if ($label !== '' && $value !== '' && !isset($row[$label])) {
            $row[$label] = $value;
        }
    }
    if (!isset($row['Make'])) {
        return null;
    }
    $row['Title'] = $text($x->query('//h1')->item(0));
    // A discounted vehicle shows the old and the sale price together: take the lower one.
    $price = $text($x->query('//*[contains(@class,"single-car-prices") or contains(@class,"single-regular-price")]')->item(0));
    preg_match_all('/R\s?(\d[\d .,]*\d)/u', $price, $amounts);
    $amounts = array_filter(array_map('dealer_number', $amounts[1] ?? []));
    $row['Price'] = $amounts ? (string) min($amounts) : '';
    // Stable stock number: WordPress's own post id (shortlink ?p=123), else the page address.
    $short = $x->query('//link[@rel="shortlink"]/@href')->item(0);
    $row['Stock No'] = $short && preg_match('/[?&]p=(\d+)/', $short->nodeValue, $p) ? 'WP' . $p[1] : basename(rtrim($pageUrl, '/'));
    $row['Description'] = $text($x->query('//*[contains(@class,"stm-car-listing-description") or contains(@class,"listing-description")]')->item(0))
        ?: (string) ($x->query('//meta[@property="og:description"]/@content')->item(0)?->nodeValue ?? '');

    // Photos: the main gallery only (not the logo or other vehicles further down), full size.
    $photos = [];
    foreach ($x->query('//*[contains(@class,"motors-elementor-big-gallery") or contains(@class,"stm-big-car-gallery")]//a/@href'
        . ' | //*[contains(@class,"motors-elementor-big-gallery") or contains(@class,"stm-big-car-gallery")]//img/@src'
        . ' | //*[contains(@class,"motors-elementor-big-gallery") or contains(@class,"stm-big-car-gallery")]//img/@data-src') ?: [] as $attr) {
        $photo = preg_replace('~-\d+x\d+(\.(?:jpe?g|png|webp))$~i', '$1', trim($attr->nodeValue));
        if (preg_match('~/wp-content/uploads/.+\.(jpe?g|png|webp)$~i', $photo)) {
            $photos[] = $photo;
        }
    }
    $row['Photos'] = array_values(array_unique($photos));
    $row['Source page'] = $pageUrl;
    return $row;
}

function dealer_http_get(string $url, int $timeout = 30): ?string
{
    return dealer_http_get_many([$url], $timeout)[$url] ?? null;
}

/**
 * Fetches several pages at once (a handful, to be gentle on the dealer's server).
 *
 * @param list<string> $urls
 * @return array<string, ?string> url => body (null on failure)
 */
function dealer_http_get_many(array $urls, int $timeout = 30): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($urls as $url) {
        if (!dealer_public_url($url)) {
            continue;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ' . APP_NAME . ' stock import; +' . CANONICAL_URL . ')']);
        curl_multi_add_handle($multi, $handle);
        $handles[$url] = $handle;
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 1.0);
    } while ($running > 0);
    $out = array_fill_keys($urls, null);
    foreach ($handles as $url => $handle) {
        $body = curl_multi_getcontent($handle);
        $out[$url] = curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200 && is_string($body) ? $body : null;
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    return $out;
}

// ── Auction Hub Group: batches and drafts ─────────────────────

/** Where loaded lists wait (inside the staging folder, outside public_html) */
function dealer_dir(): string
{
    $dir = stockImportDir() . '/dealer';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function dealer_slug(string $s): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}

/** Only public web addresses are fetched (no localhost, LAN or cloud-metadata hosts) */
function dealer_public_url(string $url): bool
{
    $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
    if ($host === '' || !preg_match('~^https?://~i', $url)) {
        return false;
    }
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** "POLO VIVO 1.4 TRENDLINE" → "Polo Vivo 1.4 Trendline"; codes like GD-6, 128TI, D/C, VW stay as they are */
function dealer_words(string $s): string
{
    return implode(' ', array_map(static fn($w) => preg_match('/^\p{L}{4,}$/u', $w) ? mb_convert_case(mb_strtolower($w), MB_CASE_TITLE) : $w,
        preg_split('/\s+/u', trim($s)) ?: []));
}

function dealer_batch_path(string $batch): string
{
    return dealer_dir() . '/' . preg_replace('/[^a-z0-9-]/', '', $batch) . '.json';
}

/** One dedupe key per branch + stock number, shared by every load of the same list */
function dealer_draft_ref(?int $branchId, string $ref): string
{
    return sha1((int) $branchId . '|' . $ref);
}

/**
 * Reads an uploaded file (or, with $contents null, a link) and keeps its vehicles as a batch
 * for the picker. Returns the batch id; throws RuntimeException with a plain message.
 */
function dealer_load_batch(?int $branchId, ?string $contents, string $fileName, string $link = ''): string
{
    $error = '';
    if ($contents !== null) {
        $read = dealer_read($contents, $fileName);
        if (!$read['rows']) {
            throw new RuntimeException($read['error'] ?: 'No vehicles found in that file.');
        }
        [$rows, $format, $label] = [$read['rows'], $read['format'], $fileName];
    } else {
        $fetched = dealer_fetch_feed($link, $error);
        if ($fetched === null) {
            throw new RuntimeException($error);
        }
        $read = dealer_read($fetched[0], $fetched[1]);
        if ($read['format'] !== 'web page') {
            if (!$read['rows']) {
                throw new RuntimeException($read['error'] ?: 'No vehicles found at that link.');
            }
            [$rows, $format, $label] = [$read['rows'], $read['format'], $fetched[1] ?: $link];
        } else {
            $rows = dealer_read_site($link, $error);
            if (!$rows) {
                throw new RuntimeException($error ?: 'No vehicles found on that website.');
            }
            $loc = dealer_site_location($link);
            [$format, $label] = ['website', (string) parse_url($link, PHP_URL_HOST) . ($loc !== '' ? ' · ' . $loc : '')];
        }
    }
    $vehicles = array_values(array_filter(array_map('dealer_normalise', $rows), static fn($v) => $v['make'] !== '' && $v['model'] !== ''));
    if (!$vehicles) {
        throw new RuntimeException('No vehicles found in that ' . ($format ?: 'file') . ' — each vehicle needs at least a make and a model.');
    }
    // Same safety net as BidCo: one price on most vehicles was not read per vehicle
    $priced = array_filter(array_map(static fn($v) => (int) $v['price'], $vehicles));
    if (count($vehicles) >= 8 && $priced) {
        $counts = array_count_values($priced);
        arsort($counts);
        if (reset($counts) >= count($vehicles) * 0.7) {
            $top = (int) array_key_first($counts);
            foreach ($vehicles as $i => $v) {
                if ((int) $v['price'] === $top) {
                    $vehicles[$i]['price'] = 0;
                }
            }
        }
    }
    $batch = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    file_put_contents(dealer_batch_path($batch), json_encode([
        'branch_id' => $branchId, 'format' => $format, 'label' => mb_substr($label, 0, 120),
        'loaded' => date('Y-m-d H:i:s'), 'vehicles' => array_slice($vehicles, 0, 2000),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    foreach (glob(dealer_dir() . '/*.json') ?: [] as $old) {   // a week is plenty to pick from a list
        if (filemtime($old) < time() - 7 * 86400) {
            @unlink($old);
        }
    }
    return $batch;
}

/** @return array<string, mixed>|null */
function dealer_get_batch(string $batch): ?array
{
    $file = dealer_batch_path($batch);
    return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: null) : null;
}

/** Stock numbers from this batch that are already drafts or published: ref => status */
function dealer_taken(array $batch): array
{
    global $pdo;
    $refs = [];
    foreach ($batch['vehicles'] as $v) {
        $refs[dealer_draft_ref($batch['branch_id'] ?? null, $v['ref'])] = $v['ref'];
    }
    $out = [];
    foreach (array_chunk(array_keys($refs), 500) as $chunk) {
        $st = $pdo->prepare("SELECT source_ref, status FROM stock_drafts WHERE source = 'dealer' AND source_ref IN ("
                            . implode(',', array_fill(0, count($chunk), '?')) . ")");
        $st->execute($chunk);
        foreach ($st as $r) {
            $out[$refs[$r['source_ref']]] = $r['status'];
        }
    }
    return $out;
}

/**
 * Turns the picked vehicles (indexes into the batch) into drafts, downloading their photos
 * into the staging folder. Ones already taken are skipped.
 *
 * @return array{added: int, skipped: int, photos: int}
 */
function dealer_take(array $batch, array $indexes, int $adminId): array
{
    global $pdo;
    $branchId = (int) ($batch['branch_id'] ?? 0) ?: null;
    $makeIds = [];
    $makeNames = $pdo->query("SELECT id, name FROM makes")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($makeNames as $id => $name) {
        $makeIds[dealer_key($name)] = (int) $id;
    }
    $exists = $pdo->prepare("SELECT 1 FROM stock_drafts WHERE source = 'dealer' AND source_ref = ?");
    $insert = $pdo->prepare("
        INSERT INTO stock_drafts (source, source_ref, branch_id, title, description, year, make_id, mileage, price, posted_at, created_by)
        VALUES ('dealer', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ");
    $setPhotos = $pdo->prepare("UPDATE stock_drafts SET photos = ? WHERE id = ?");
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $stats = ['added' => 0, 'skipped' => 0, 'photos' => 0];
    foreach ($indexes as $i) {
        $v = $batch['vehicles'][(int) $i] ?? null;
        if (!$v) {
            continue;
        }
        $ref = dealer_draft_ref($branchId, $v['ref']);
        $exists->execute([$ref]);
        if ($exists->fetchColumn()) {
            $stats['skipped']++;
            continue;
        }
        $makeKey = dealer_key($v['make']);
        $makeId = $makeIds[$makeKey] ?? ($makeKey === 'vw' ? ($makeIds['volkswagen'] ?? null) : null);
        $title = trim(($v['year'] ?: '') . ' ' . ($makeId ? $makeNames[$makeId] : dealer_words($v['make'])) . ' ' . dealer_words($v['model']));
        $specs = array_filter([
            'Stock no' => mb_strlen($v['ref']) <= 30 ? $v['ref'] : '',
            'Colour' => $v['colour'], 'Fuel' => dealer_words($v['fuel']), 'Transmission' => dealer_words($v['transmission']),
            'Body' => $v['body'], 'Condition' => dealer_words($v['condition']), 'Location' => $v['location'],
        ], static fn($x) => (string) $x !== '');
        $lines = [];
        foreach ($specs as $k => $x) {
            $lines[] = "$k: $x";
        }
        $description = trim($v['description'] . ($lines ? "\n\n" . implode("\n", $lines) : ''));
        $insert->execute([$ref, $branchId, mb_substr($title, 0, 255), $description, $v['year'] ?: null, $makeId,
                          $v['mileage'] ?: null, $v['price'] > 0 ? $v['price'] : null, $adminId]);
        $id = (int) $pdo->lastInsertId();
        $dir = stockDraftDir($id);
        $files = [];
        foreach (dealer_http_get_many(array_slice($v['images'], 0, 20), 40) as $bytes) {
            if (!is_string($bytes) || strlen($bytes) < 2000 || strlen($bytes) > MAX_UPLOAD_SIZE) {
                continue;
            }
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$finfo->buffer($bytes)] ?? null;
            if (!$ext) {
                continue;
            }
            $file = sprintf('%02d.%s', count($files) + 1, $ext);
            file_put_contents("$dir/$file", $bytes);
            $files[] = $file;
        }
        $setPhotos->execute([json_encode($files), $id]);
        $stats['added']++;
        $stats['photos'] += count($files);
    }
    return $stats;
}
