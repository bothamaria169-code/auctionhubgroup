<?php
// ============================================================
// AUCTION HUB GROUP — finance_helper.php
// In-house finance applications and pre-auction (Buy Now) requests,
// ported from BidCo. One table: client_applications (db_schema.php).
//
// IN-HOUSE FINANCE (finance_apply.php → my_application.php → Admin → Finance & Pre-Auction)
//   1 Quotation   client picks a vehicle with no bids + their deposit; the quotation
//                 (every term) is ready at once and emailed.
//   2 Plan        client chooses the monthly plan on their page.
//   3 Review      office approves the deposit (deposit invoice goes out), suggests a
//                 higher deposit (new quotation + letter), or declines (letter).
//   4 Documents   client uploads ID, address, bank statements, income, licence and
//                 proof of payment; office accepts each or asks again.
//   5 Approved    office marks the deposit paid: approval letter + 6-digit Buyer's Code.
//   6 Viewing     client books the head-office test drive; office confirms. Sign & drive.
//
// PRE-AUCTION / BUY NOW (preauction_request.php → my_application.php → Admin)
//   1 Request     vehicle with no bids, commitment 30–50% of the price, viewing time.
//   2 Approval    office approves the %, suggests another (client accepts), or declines.
//   3 Confirm     client reads the commitment letter and confirms with gate-pass details
//                 (or says they are not ready) — only then is the invoice issued.
//   4 Payment     client pays into the account on the invoice and uploads proof.
//   5 Buyer Code  office marks paid: Buyer's Code letter; 1h30 test drive at head office.
//   6 Decision    after the test drive: pay the balance, or ask for the commitment back
//                 (refunded less the R102 bank/admin charge).
//
// VIN POLICY (vin-policy.php): VINs are never shown publicly — only to the office,
// paid-up registered bidders, approved finance clients and paid Buy Now buyers.
// ============================================================

require_once __DIR__ . '/email_helper.php';

const FIN_UPLOAD_DIR   = __DIR__ . '/uploads/applications/';
const FIN_DOC_MAX      = 8 * 1024 * 1024;
const PA_REFUND_FEE    = 102.0;
const FIN_DECISION_DAYS = 3;   // an unpaid invoice lapses after this many days

/** What a client uploads: key => [label, hint, max files]. */
const FIN_DOCS = [
    'payment' => ['Proof of payment', 'The bank\'s confirmation of your deposit / commitment payment', 2],
    'id'      => ['ID or passport', 'A clear copy — both sides of a smart ID card', 2],
    'address' => ['Proof of address', 'Not older than three months: municipal bill, bank statement or lease', 2],
    'bank'    => ['3 months\' bank statements', 'One PDF, or up to three files', 3],
    'income'  => ['Payslip or proof of income', 'Your latest payslip, or proof of income if self-employed', 3],
    'licence' => ['Driver\'s licence', 'Needed before you drive the vehicle', 2],
];
/** Buy Now buyers only upload their proof of payment (+ ID for the gate). */
const PA_DOCS = ['payment', 'id'];

const FIN_STATUS_LABELS = [
    'finance' => [
        'quoted'    => 'Quotation sent — waiting for the client to choose a plan',
        'chosen'    => 'Plan chosen — approve the deposit, suggest another, or decline',
        'invoiced'  => 'Deposit invoice sent — documents & payment',
        'approved'  => 'Approved — Buyer\'s Code issued, test drive',
        'completed' => 'Completed — signed and delivered',
        'declined'  => 'Declined',
        'cancelled' => 'Cancelled by the client',
    ],
    'preauction' => [
        'requested' => 'New request — approve, suggest a % or decline',
        'countered' => 'Suggested another % — waiting for the client',
        'approved'  => 'Approved — commitment letter sent, waiting for the client to confirm',
        'confirmed' => 'Confirmed — commitment invoice sent, waiting for payment',
        'paid'      => 'Paid — Buyer\'s Code issued, test drive',
        'completed' => 'Completed — balance paid, vehicle sold',
        'refund'    => 'Asked for the commitment back — refund',
        'not_ready' => 'Client not ready — pointed to register & bid',
        'declined'  => 'Declined',
        'cancelled' => 'Cancelled',
    ],
];

// ── Rules (Admin → Finance & Pre-Auction → Settings) ──────────

function finSetting(string $key, string $default): string {
    if (!function_exists('leadSetting')) require_once __DIR__ . '/leads_helper.php';
    return leadSetting($key, $default);
}

/** Heavy vehicles (trucks, buses, plant) get a larger minimum deposit and longer terms. */
function finIsHeavy(array $a): bool {
    $t = strtolower(($a['body_type'] ?? '') . ' ' . ($a['item_name'] ?? ''));
    return (bool)preg_match('/\b(truck|horse|tipper|bus|trailer|tractor|loader|excavator|tlb|forklift|plant|crane|lowbed|rigid)\b/', $t);
}

function finMinDeposit(array $a): float {
    return finIsHeavy($a)
        ? (float)finSetting('fin_min_deposit_heavy', '50000')
        : (float)finSetting('fin_min_deposit', '10500');
}

/** Terms offered, with the flat monthly rate (% per month) used by the calculator on financing.php. */
function finTiers(array $a = []): array {
    $defaults = [6 => 3.5, 12 => 3.0, 24 => 2.8, 36 => 2.6, 48 => 2.5, 60 => 2.4, 72 => 2.3];
    $max = finIsHeavy($a) ? 72 : 48;
    $out = [];
    foreach ($defaults as $m => $r) {
        if ($m > $max) continue;
        $out[$m] = max(0.0, min(20.0, (float)finSetting('fin_rate_' . $m, (string)$r)));
    }
    return $out;
}

/** Monthly instalment for each term: financed × (1 + rate × months) ÷ months. */
function finPlans(float $financed, array $tiers): array {
    $plans = [];
    foreach ($tiers as $m => $rate) {
        $monthly = $financed * (1 + $rate / 100 * $m) / $m;
        $total   = round($monthly * $m, 2);
        $plans[] = ['months' => (int)$m, 'rate' => $rate, 'monthly' => round($monthly, 2), 'total' => $total, 'interest' => round($total - $financed, 2)];
    }
    return $plans;
}

/** @return array{0:int,1:int,2:int} min %, max %, default % for the Buy Now commitment. */
function paRange(): array {
    $min = max(1, min(100, (int)finSetting('pa_min_percent', '30')));
    $max = max($min, min(100, (int)finSetting('pa_max_percent', '50')));
    $def = max($min, min($max, (int)finSetting('pa_default_percent', '35')));
    return [$min, $max, $def];
}

// ── Vehicles ──────────────────────────────────────────────────

/** A lot that can be financed / bought before auction: not sold, no bids, has a price. */
function finVehicleSql(): string {
    return "SELECT a.id, a.item_name, a.year, a.mileage, a.transmission, a.fuel_type, a.color, a.body_type,
                   a.engine_size, a.image_url, a.start_price, a.current_price, a.status, a.end_time,
                   a.condition_grade, m.name AS make_name,
                   (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) AS bid_count
            FROM auctions a LEFT JOIN makes m ON m.id = a.make_id";
}

function finVehicle(int $id): ?array {
    global $pdo;
    if ($id <= 0) return null;
    $st = $pdo->prepare(finVehicleSql() . " WHERE a.id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function finVehicleTitle(?array $a): string {
    if (!$a) return 'Your selected vehicle';
    $make = trim((string)($a['make_name'] ?? ''));
    $name = trim((string)($a['item_name'] ?? ''));
    $year = (string)($a['year'] ?? '');
    // Same as auction.php: imported names often already start with the year and make.
    $title = $name;
    if ($make !== '' && stripos($title, $make) === false) $title = $make . ' ' . $title;
    if ($year !== '' && !str_contains($title, $year)) $title = $year . ' ' . $title;
    return trim($title);
}

/** The price we finance / sell on before auction, or 0 when the lot is not eligible. */
function finPrice(array $a): float {
    if ((int)($a['bid_count'] ?? 0) > 0) return 0.0;
    if (!in_array($a['status'] ?? '', ['upcoming', 'active'], true)) return 0.0;
    if (!empty($a['end_time']) && strtotime($a['end_time']) < time()) return 0.0;   // ended: it closes on the next page view
    return (float)($a['start_price'] ?: $a['current_price']);
}

/** Lots reserved by a paid / approved application cannot be applied for again. */
function finReservedIds(): array {
    global $pdo;
    try {
        return array_map('intval', $pdo->query("SELECT DISTINCT auction_id FROM client_applications
            WHERE auction_id IS NOT NULL AND status IN ('approved','paid','completed') AND kind IN ('finance','preauction')
              AND NOT (kind = 'preauction' AND status = 'approved')")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

/** @return list<array> every lot that can be financed / reserved right now. */
function finEligibleLots(): array {
    global $pdo;
    $rows = $pdo->query(finVehicleSql() . " WHERE a.status IN ('upcoming','active') AND COALESCE(a.is_sold,0) = 0
                                            AND (a.end_time IS NULL OR a.end_time > NOW())
                                            AND COALESCE(NULLIF(a.start_price,0), a.current_price) > 0
                                            ORDER BY m.name, a.item_name, a.year DESC LIMIT 600")->fetchAll();
    $reserved = array_flip(finReservedIds());
    return array_values(array_filter($rows, fn($a) => finPrice($a) > 0 && !isset($reserved[(int)$a['id']])));
}

// ── VIN policy ────────────────────────────────────────────────

const FIN_VIN_CORE = '(?=[A-HJ-NPR-Z0-9]*\d)(?=[A-HJ-NPR-Z0-9]*[A-HJ-NPR-Z])[A-HJ-NPR-Z0-9]{17}';

/** Text with any VIN replaced: "VIN 1HGCM82633A004352" → "VIN on request". */
function vinScrub(?string $text): string {
    $text = (string)$text;
    if ($text === '') return '';
    $text = (string)preg_replace('~\b(?:VIN|chassis(?:\s*(?:no|number))?)\s*[:#.\-]?\s*' . FIN_VIN_CORE . '\b~i', 'VIN on request', $text);
    return (string)preg_replace('~\b' . FIN_VIN_CORE . '\b~', 'VIN on request', $text);
}

/** May this visitor see VINs on lot pages? The office, and registered bidders whose deposit is approved. */
function vinVisibleToVisitor(?array $user): bool {
    if (!$user) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    return ($user['bidder_status'] ?? '') === 'verified_bidder';
}

/** The ways to get a VIN, for the page and the lot notice. */
function vinChannels(): array {
    return [
        ['fa-id-card',      'Registered bidders',       'Register and pay your refundable registration deposit — the VIN shows on the lot page once your bidder number is active.'],
        ['fa-handshake',    'Approved finance clients', 'Once your in-house finance is approved, the VIN is on your application page and approval letter.'],
        ['fa-bolt',         'Reserved pre-auction',     'Once your pre-auction commitment is paid, the VIN is on your application page and Buyer\'s Code letter.'],
        ['fa-location-dot', 'On site',                  'Visit us and our team shows you the VIN on the vehicle itself.'],
    ];
}

function vinForAuction(int $id): string {
    global $pdo;
    $st = $pdo->prepare("SELECT vin_number FROM auctions WHERE id = ?");
    $st->execute([$id]);
    return strtoupper(trim((string)$st->fetchColumn()));
}

// ── Storage ───────────────────────────────────────────────────

function finDecode(array $row): array {
    $row['details'] = json_decode((string)($row['details'] ?? ''), true) ?: [];
    return $row;
}

function finFind(string $token): ?array {
    global $pdo;
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || !ahgSchemaReady()) return null;
    $st = $pdo->prepare("SELECT * FROM client_applications WHERE token = ?");
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ? finDecode($row) : null;
}

function finGet(int $id): ?array {
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM client_applications WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ? finDecode($row) : null;
}

/** Saves the columns that change plus the details JSON. */
function finSave(array $app): void {
    global $pdo;
    $pdo->prepare("UPDATE client_applications SET status = ?, amount = ?, percent = ?, months = ?, buyer_code = ?, details = ? WHERE id = ?")
        ->execute([$app['status'], $app['amount'], $app['percent'], $app['months'], $app['buyer_code'] ?: null,
                   json_encode($app['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $app['id']]);
}

/** Something that happened, for the history on both pages. */
function finLog(array &$app, string $what, string $by = 'client'): void {
    $app['details']['log'][] = ['at' => date('Y-m-d H:i:s'), 'by' => $by, 'what' => $what];
}

function finLink(array $app, string $anchor = ''): string {
    return APP_URL . '/my_application.php?t=' . $app['token'] . ($anchor !== '' ? '#' . $anchor : '');
}

function finDocLink(array $app, string $doc): string {
    return APP_URL . '/application_doc.php?t=' . $app['token'] . '&doc=' . $doc;
}

function finRef(array $app): string {
    return ($app['kind'] === 'finance' ? 'AHG-FIN-' : 'AHG-PA-') . str_pad((string)$app['id'], 5, '0', STR_PAD_LEFT);
}

/** The payment reference the client uses: their name and surname plus the reference. */
function finPayRef(array $app): string {
    return mb_strtoupper(mb_substr(trim($app['name']), 0, 20)) . ' ' . finRef($app);
}

/** Creates an application, an enquiry row (so it shows in Admin → Leads) and returns it. */
function finCreate(string $kind, array $person, array $vehicle, float $amount, ?float $percent, array $details): array {
    global $pdo;
    $token = bin2hex(random_bytes(16));
    $price = finPrice($vehicle);
    $status = $kind === 'finance' ? 'quoted' : 'requested';
    $details['vehicle'] = [
        'title' => finVehicleTitle($vehicle), 'year' => $vehicle['year'], 'mileage' => $vehicle['mileage'],
        'transmission' => $vehicle['transmission'], 'fuel' => $vehicle['fuel_type'], 'colour' => $vehicle['color'],
        'body' => $vehicle['body_type'], 'image' => $vehicle['image_url'], 'heavy' => finIsHeavy($vehicle),
    ];
    $pdo->prepare("INSERT INTO client_applications (kind, token, status, name, email, phone, user_id, auction_id, vehicle, price, amount, percent, details, ip)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$kind, $token, $status, $person['name'], $person['email'], $person['phone'], $person['user_id'] ?? null,
                   (int)$vehicle['id'], finVehicleTitle($vehicle), $price, $amount, $percent,
                   json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $_SERVER['REMOTE_ADDR'] ?? null]);
    $app = finGet((int)$pdo->lastInsertId());

    // Admin → Leads picks it up as an enquiry too.
    try {
        $pdo->prepare("INSERT INTO enquiries (type, name, email, phone, auction_id, message, source_page, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$kind === 'finance' ? 'finance' : 'preauction', $person['name'], $person['email'], $person['phone'], (int)$vehicle['id'],
                       ($kind === 'finance' ? 'In-house finance application ' : 'Pre-auction request ') . finRef($app)
                       . ($kind === 'finance' ? ' · deposit ' . formatRand($amount) : ' · commitment ' . (int)$percent . '%'),
                       $kind === 'finance' ? 'finance_apply.php' : 'preauction_request.php', $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) { error_log('[AHG fin] enquiry row: ' . $e->getMessage()); }
    return $app;
}

// ── Finance quotation ─────────────────────────────────────────

/** Works out the quotation at this deposit and stores it on the application. */
function finIssueQuote(array &$app, float $deposit, bool $counter = false, string $note = ''): void {
    $vehicle = finVehicle((int)$app['auction_id']) ?: ['body_type' => $app['details']['vehicle']['body'] ?? '', 'item_name' => $app['vehicle']];
    $price = (float)$app['price'];
    $tiers = finTiers($vehicle);
    $app['amount'] = $deposit;
    $app['months'] = null;
    $app['details']['quote'] = [
        'number'   => 'AHG-FQ-' . date('Ymd') . '-' . str_pad((string)$app['id'], 4, '0', STR_PAD_LEFT) . ($counter ? '-R' . (count($app['details']['quotes_old'] ?? []) + 1) : ''),
        'issued'   => date('Y-m-d H:i:s'),
        'valid'    => date('Y-m-d', strtotime('+7 days')),
        'price'    => $price,
        'deposit'  => $deposit,
        'financed' => max(0.0, $price - $deposit),
        'plans'    => finPlans(max(0.0, $price - $deposit), $tiers),
        'counter'  => $counter,
        'note'     => $note,
    ];
    unset($app['details']['choice']);
}

/** null when the deposit is fine, otherwise what to tell the client. */
function finDepositError(array $vehicle, float $deposit): ?string {
    $min = finMinDeposit($vehicle);
    $price = finPrice($vehicle);
    if ($deposit < $min) return 'The minimum start-up deposit on this vehicle is ' . formatRand($min) . '. You are welcome to put down more — it lowers your instalment.';
    if ($price > 0 && $deposit >= $price) return 'Your deposit covers the full price (' . formatRand($price) . ') — reserve it with a pre-auction purchase instead.';
    return null;
}

function finChosenPlan(array $app): ?array {
    $m = (int)($app['details']['choice']['months'] ?? 0);
    foreach ($app['details']['quote']['plans'] ?? [] as $p) if ((int)$p['months'] === $m) return $p;
    return null;
}

// ── Pre-auction money ─────────────────────────────────────────

function paPercent(array $app): int {
    return (int)round((float)($app['details']['office_percent'] ?? $app['percent']));
}

function paCommitment(array $app): float {
    return round((float)$app['price'] * paPercent($app) / 100, 2);
}

// ── Buyer's Code ──────────────────────────────────────────────

function finIssueBuyerCode(array &$app): string {
    global $pdo;
    if (!empty($app['buyer_code'])) return $app['buyer_code'];
    $check = $pdo->prepare("SELECT 1 FROM client_applications WHERE buyer_code = ?");
    do {
        $code = (string)random_int(100000, 999999);
        $check->execute([$code]);
    } while ($check->fetchColumn());
    return $app['buyer_code'] = $code;
}

// ── Steps (the tracker on the client's page and admin) ────────

function finSteps(string $kind): array {
    return $kind === 'finance'
        ? ['Quotation', 'Choose plan', 'Deposit invoice', 'Documents & payment', 'Approved & Buyer\'s Code', 'Test drive & sign']
        : ['Request', 'Approval', 'Confirm', 'Pay commitment', 'Buyer\'s Code & test drive', 'Your decision'];
}

/** The step the application is on (0-based), for the tracker. */
function finStepIndex(array $app): int {
    $s = $app['status'];
    if ($app['kind'] === 'finance') {
        return match ($s) {
            'quoted' => 1, 'chosen' => 2, 'invoiced' => 3,
            'approved' => !empty($app['details']['viewing']['status']) && $app['details']['viewing']['status'] === 'confirmed' ? 5 : 4,
            'completed' => 6, default => 0,
        };
    }
    return match ($s) {
        'requested', 'countered' => 1, 'approved' => 2, 'confirmed' => 3, 'paid' => 4,
        'completed', 'refund' => 6, default => 0,
    };
}

function finStatusLabel(array $app): string {
    return FIN_STATUS_LABELS[$app['kind']][$app['status']] ?? ucfirst($app['status']);
}

/** True when the application is closed for good. */
function finClosed(array $app): bool {
    return in_array($app['status'], ['declined', 'cancelled', 'not_ready', 'completed', 'refund'], true);
}

// ── Documents ─────────────────────────────────────────────────

function finDocKinds(array $app): array {
    return $app['kind'] === 'finance' ? array_keys(FIN_DOCS) : PA_DOCS;
}

function finUploadDir(int $id): string {
    if (!is_dir(FIN_UPLOAD_DIR)) {
        @mkdir(FIN_UPLOAD_DIR, 0755, true);
    }
    if (!is_file(FIN_UPLOAD_DIR . '.htaccess')) {
        @file_put_contents(FIN_UPLOAD_DIR . '.htaccess', "Require all denied\nDeny from all\n");
    }
    $dir = FIN_UPLOAD_DIR . $id . '/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** Stores one uploaded file privately. Returns ['error' => …] or the stored entry. */
function finDocStore(array $file, int $appId, string $kind): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['error' => 'Please choose a file.'];
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return ['error' => 'The upload did not come through — please try again.'];
    if ($file['size'] > FIN_DOC_MAX) return ['error' => 'That file is over 8 MB. A photo or a smaller PDF works fine.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) return ['error' => 'Please upload a PDF or a photo (JPG, PNG or WebP).'];
    if ($ext === 'pdf' && strncmp((string)file_get_contents($file['tmp_name'], false, null, 0, 5), '%PDF-', 5) !== 0) {
        return ['error' => 'That PDF could not be read — please try another copy.'];
    }
    $name = $kind . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], finUploadDir($appId) . $name)) return ['error' => 'We could not save the file — please try again.'];
    return ['file' => $name, 'original' => mb_substr(basename((string)$file['name']), 0, 120), 'at' => date('Y-m-d H:i:s'), 'mime' => $mime];
}

/** Files for one document from a multi-file input. Returns [saved, error]. */
function finDocUpload(array &$app, string $kind, array $files): array {
    if (!in_array($kind, finDocKinds($app), true)) return [0, 'Unknown document.'];
    $max = FIN_DOCS[$kind][2];
    $list = [];
    if (is_array($files['name'] ?? null)) {
        foreach ($files['name'] as $i => $n) {
            $list[] = ['name' => $n, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
        }
    } elseif ($files) {
        $list[] = $files;
    }
    $list = array_slice(array_filter($list, fn($f) => ($f['error'] ?? 4) !== UPLOAD_ERR_NO_FILE), 0, $max);
    if (!$list) return [0, 'Please choose a file to upload.'];
    $saved = [];
    foreach ($list as $f) {
        $r = finDocStore($f, (int)$app['id'], $kind);
        if (isset($r['error'])) return [count($saved), $r['error']];
        $saved[] = $r;
    }
    $doc = $app['details']['docs'][$kind] ?? ['files' => []];
    // A fresh upload replaces a copy we asked for again.
    if (($doc['status'] ?? '') === 'rejected') $doc['files'] = [];
    $doc['files'] = array_slice(array_merge($doc['files'], $saved), -$max);
    $doc['status'] = 'pending';
    unset($doc['note']);
    $app['details']['docs'][$kind] = $doc;
    finLog($app, 'Uploaded ' . FIN_DOCS[$kind][0]);
    return [count($saved), ''];
}

function finDocsSummary(array $app): array {
    $out = ['total' => 0, 'uploaded' => 0, 'accepted' => 0, 'rejected' => 0];
    foreach (finDocKinds($app) as $k) {
        $out['total']++;
        $d = $app['details']['docs'][$k] ?? null;
        if ($d && !empty($d['files'])) $out['uploaded']++;
        if (($d['status'] ?? '') === 'accepted') $out['accepted']++;
        if (($d['status'] ?? '') === 'rejected') $out['rejected']++;
    }
    return $out;
}

// ── Viewing / test drive ──────────────────────────────────────

function finViewingError(string $date, string $time): ?string {
    $ts = strtotime($date . ' ' . $time);
    if (!$ts || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) return 'Please choose a date and a time.';
    if ($ts < strtotime('tomorrow')) return 'Please choose a day from tomorrow onwards.';
    if ($ts > strtotime('+60 days')) return 'Please choose a day within the next two months.';
    if ((int)date('N', $ts) === 7) return 'We are closed on Sundays — please choose Monday to Saturday.';
    $h = (int)substr($time, 0, 2);
    if ($h < 8 || $h > 15) return 'Please choose a time between 08:00 and 15:00.';
    return null;
}

function finViewingLabel(?array $v): string {
    if (!$v || empty($v['date'])) return '';
    return date('l j F Y', strtotime($v['date'])) . ' at ' . $v['time'];
}

// ── Email ─────────────────────────────────────────────────────

/** Email the client. $bodyHtml goes inside the AHG template, with a button to their page. */
function finMailClient(array $app, string $subject, string $bodyHtml, string $button = 'Open my application', string $anchor = ''): bool {
    try {
        $mail = _buildMailer();
        $mail->addAddress($app['email'], $app['name']);
        $mail->addReplyTo(COMPANY_EMAIL, COMPANY_NAME);
        $mail->Subject = $subject . ' | ' . finRef($app);
        $first = e(explode(' ', trim($app['name']))[0]);
        $body = '<p>Dear ' . $first . ',</p>' . $bodyHtml
              . '<div class="highlight-box"><p><strong>Reference:</strong> ' . e(finRef($app)) . '</p><p><strong>Vehicle:</strong> ' . e($app['vehicle']) . '</p></div>'
              . '<a class="cta-btn" href="' . e(finLink($app, $anchor)) . '">' . e($button) . '</a>'
              . '<p style="font-size:13px;color:#666">This link is private to you — keep it safe. Questions? Call ' . e(COMPANY_PHONE) . ' or WhatsApp ' . e(COMPANY_WHATSAPP) . '.</p>'
              . '<p style="font-size:12px;color:#888">Only ever pay into the bank account on your Auction Hub Group invoice. We will never send you new banking details by email or WhatsApp.</p>';
        $mail->Body = _emailTemplate($subject, $body, true);
        $mail->AltBody = strip_tags(str_replace(['</p>', '<br>', '</li>'], "\n", $bodyHtml)) . "\n\nYour application: " . finLink($app, $anchor);
        return _sendMail($mail, 'fin_' . $app['kind']);
    } catch (Throwable $e) {
        error_log('[AHG fin] client mail: ' . $e->getMessage());
        return false;
    }
}

/** Tell the office something needs doing. */
function finMailOffice(array $app, string $what): void {
    try {
        $mail = _buildMailer();
        $mail->addAddress(MAIL_ADMIN);
        $mail->addReplyTo($app['email'], $app['name']);
        $mail->Subject = ($app['kind'] === 'finance' ? 'Finance: ' : 'Pre-auction: ') . $what . ' — ' . $app['name'] . ' | ' . finRef($app);
        $rows = [['Client', $app['name']], ['Phone', $app['phone']], ['Email', $app['email']], ['Vehicle', $app['vehicle']],
                 ['Price', formatRand((float)$app['price'])],
                 [$app['kind'] === 'finance' ? 'Deposit' : 'Commitment', $app['kind'] === 'finance' ? formatRand((float)$app['amount']) : paPercent($app) . '% = ' . formatRand(paCommitment($app))],
                 ['Where it stands', finStatusLabel($app)]];
        $body = '<h2>' . e($what) . '</h2><table style="width:100%;border-collapse:collapse">';
        foreach ($rows as [$k, $v]) $body .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee;color:#777;width:130px">' . e($k) . '</td><td style="padding:6px 8px;border-bottom:1px solid #eee">' . e($v) . '</td></tr>';
        $body .= '</table><a class="cta-btn" href="' . APP_URL . '/admin_dashboard.php?tab=applications&app=' . (int)$app['id'] . '">Open in admin</a>';
        $mail->Body = _emailTemplate($what, $body);
        $mail->AltBody = $what . ': ' . $app['name'] . ' ' . $app['phone'] . ' — ' . $app['vehicle'];
        _sendMail($mail, 'fin_office');
    } catch (Throwable $e) { error_log('[AHG fin] office mail: ' . $e->getMessage()); }
}

function finRecordMail(array &$app, string $what, bool $ok): void {
    $app['details']['emails'][] = ['at' => date('Y-m-d H:i:s'), 'what' => $what, 'ok' => $ok];
}

/** The quotation email (finance), sent on applying and after a suggested deposit. */
function finSendQuote(array &$app): bool {
    $q = $app['details']['quote'];
    $rows = '';
    foreach ($q['plans'] as $p) {
        $rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee">' . $p['months'] . ' months</td><td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right"><strong>' . formatRand($p['monthly']) . '</strong> / month</td></tr>';
    }
    $intro = !empty($q['counter'])
        ? '<p>Thank you for your patience. We were not able to approve the start-up deposit of ' . formatRand((float)($app['details']['quotes_old'][count($app['details']['quotes_old'] ?? []) - 1]['deposit'] ?? 0)) . ' on this vehicle, but we <strong>can approve a deposit of ' . formatRand($q['deposit']) . '</strong>. Your updated quotation is below and your deposit letter is on your page.</p>'
          . ($q['note'] !== '' ? '<div class="highlight-box"><p>' . nl2br(e($q['note'])) . '</p></div>' : '')
        : '<p>Thank you for applying for <strong>in-house finance</strong> — no credit checks, no ITC, no bureau inquiries. Your personalised quotation is ready.</p>';
    $body = $intro
        . '<div class="highlight-box"><p><strong>Price:</strong> ' . formatRand($q['price']) . '</p><p><strong>Your deposit:</strong> ' . formatRand($q['deposit']) . '</p><p><strong>Amount financed:</strong> ' . formatRand($q['financed']) . '</p></div>'
        . '<table style="width:100%;border-collapse:collapse;margin:10px 0 16px">' . $rows . '</table>'
        . '<p><strong>Next step:</strong> open your page and choose the monthly plan that suits you. We then send your deposit invoice. Quotation valid until ' . date('j F Y', strtotime($q['valid'])) . '.</p>'
        . '<p><a href="' . e(finDocLink($app, 'quote')) . '">View / print your quotation</a></p>';
    $ok = finMailClient($app, !empty($q['counter']) ? 'Your updated finance quotation' : 'Your in-house finance quotation', $body, 'Choose my monthly plan', 'plans');
    finRecordMail($app, 'Quotation ' . $q['number'], $ok);
    return $ok;
}

// ── Office decisions ──────────────────────────────────────────

/** Finance: approve the client's deposit and send the deposit invoice. */
function finApproveDeposit(array &$app, string $by): string {
    if ($app['status'] !== 'chosen') return 'The client has not chosen a plan yet.';
    $app['details']['invoice'] = ['number' => 'AHG-FI-' . date('Ymd') . '-' . str_pad((string)$app['id'], 4, '0', STR_PAD_LEFT),
                                  'issued' => date('Y-m-d H:i:s'), 'due' => date('Y-m-d', strtotime('+' . FIN_DECISION_DAYS . ' days')),
                                  'amount' => (float)$app['amount']];
    $app['status'] = 'invoiced';
    finLog($app, 'Deposit approved — invoice ' . $app['details']['invoice']['number'] . ' sent', $by);
    $plan = finChosenPlan($app);
    $ok = finMailClient($app, 'Your deposit invoice — upload your documents',
        '<p>Good news — your start-up deposit of <strong>' . formatRand((float)$app['amount']) . '</strong> is approved on the '
        . ($plan ? $plan['months'] . '-month plan at <strong>' . formatRand($plan['monthly']) . ' a month</strong>' : 'plan you chose') . '.</p>'
        . '<p><strong>What to do now:</strong></p><ol><li>Open your deposit invoice and pay <strong>only into the account on the invoice</strong>, using the reference <strong>' . e(finPayRef($app)) . '</strong>.</li>'
        . '<li>Upload your proof of payment and your documents (ID, proof of address, 3 months\' bank statements, payslip and driver\'s licence) on your page.</li>'
        . '<li>Once your deposit reflects and your documents are accepted, we send your approval letter and your <strong>Buyer\'s Code</strong> for the test drive.</li></ol>'
        . '<p><a href="' . e(finDocLink($app, 'invoice')) . '">View / print your deposit invoice</a> — please pay within ' . FIN_DECISION_DAYS . ' days.</p>',
        'Upload my documents', 'documents');
    finRecordMail($app, 'Deposit invoice', $ok);
    return '';
}

/** Finance: the office suggests a higher deposit — new quotation, plan choice cleared. */
function finCounterDeposit(array &$app, float $deposit, string $note, string $by): string {
    $vehicle = finVehicle((int)$app['auction_id']) ?: [];
    if ($deposit <= (float)$app['amount']) return 'The suggested deposit should be higher than the client\'s ' . formatRand((float)$app['amount']) . '.';
    if ($deposit >= (float)$app['price']) return 'That covers the whole price — there would be nothing to finance.';
    if ($vehicle && $deposit < finMinDeposit($vehicle)) return 'That is below the minimum deposit for this vehicle.';
    $app['details']['quotes_old'][] = $app['details']['quote'];
    finIssueQuote($app, $deposit, true, $note);
    $app['status'] = 'quoted';
    finLog($app, 'Suggested a deposit of ' . formatRand($deposit), $by);
    finSendQuote($app);
    return '';
}

/** Either kind: decline, with a polite letter. */
function finDecline(array &$app, string $reason, string $by): void {
    $app['status'] = 'declined';
    $app['details']['declined'] = ['at' => date('Y-m-d H:i:s'), 'reason' => $reason];
    finLog($app, 'Declined' . ($reason !== '' ? ': ' . $reason : ''), $by);
    $isFin = $app['kind'] === 'finance';
    $ok = finMailClient($app, $isFin ? 'Your finance application' : 'Your pre-auction request',
        '<p>Thank you for choosing Auction Hub Group and for the time you took with your ' . ($isFin ? 'in-house finance application' : 'pre-auction request') . '. '
        . 'We are sorry to let you know that we are <strong>not able to approve it</strong> on this vehicle.</p>'
        . ($reason !== '' ? '<div class="highlight-box"><p>' . nl2br(e($reason)) . '</p></div>' : '')
        . '<p>This is not a reflection on you, and it does not close the door: new stock arrives every week, and you are always welcome to '
        . '<a href="' . APP_URL . '/auctions.php">register and bid at auction</a> or apply on another vehicle.</p>'
        . (!$isFin && !empty($app['details']['paid']) ? '<p>Any commitment you paid is refunded in full within 3–5 business days, less R' . number_format(PA_REFUND_FEE, 2) . ' for bank and administration charges.</p>' : '')
        . '<p><a href="' . e(finDocLink($app, 'decline')) . '">Your letter</a></p>',
        'See my letter');
    finRecordMail($app, 'Letter of decline', $ok);
}

/** Finance: the deposit has reflected — approve, issue the Buyer's Code and send the approval letter. */
function finApprove(array &$app, string $by): string {
    if ($app['status'] !== 'invoiced') return 'Only an application with a deposit invoice can be approved.';
    $code = finIssueBuyerCode($app);
    $app['status'] = 'approved';
    $app['details']['approved_at'] = date('Y-m-d H:i:s');
    $app['details']['docs']['payment']['status'] = 'accepted';
    finLog($app, 'Deposit paid — approved, Buyer\'s Code ' . $code, $by);
    $ok = finMailClient($app, '✅ Finance approved — your Buyer\'s Code is ' . $code,
        '<p>Congratulations — your deposit has reflected and your <strong>in-house finance is approved</strong>. The vehicle is now reserved in your name.</p>'
        . '<div class="highlight-box" style="text-align:center"><p style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#666;margin:0">Your Buyer\'s Code</p><p style="font-size:28px;font-weight:900;color:#005BA1;letter-spacing:.2em;margin:4px 0">' . e($code) . '</p></div>'
        . '<p><strong>Next:</strong> book your test drive at our head office, ' . e(COMPANY_ADDRESS) . ', on your page. Bring your Buyer\'s Code, your ID and your driver\'s licence. '
        . 'After the test drive you sign your finance agreement and drive away.</p>'
        . '<p><a href="' . e(finDocLink($app, 'approval')) . '">Your approval letter</a> (the VIN is on it).</p>',
        'Book my test drive', 'viewing');
    finRecordMail($app, 'Approval letter + Buyer\'s Code', $ok);
    return '';
}

/** Pre-auction: approve their %, or suggest another. */
function paDecide(array &$app, string $decision, int $percent, string $note, string $by): string {
    [$min, $max] = paRange();
    if (!in_array($app['status'], ['requested', 'countered'], true)) return 'This request has already been decided.';
    if ($decision === 'counter') {
        if ($percent < $min || $percent > $max) return "Choose a percentage between $min% and $max%.";
        $app['details']['office_percent'] = $percent;
        $app['details']['office_note'] = $note;
        $app['status'] = 'countered';
        finLog($app, "Suggested a commitment of $percent%", $by);
        $ok = finMailClient($app, 'Your pre-auction request — we can approve ' . $percent . '%',
            '<p>Thank you for your pre-auction request. We cannot approve a commitment of ' . (int)$app['percent'] . '% on this vehicle, '
            . 'but we <strong>can approve ' . $percent . '%</strong> — a commitment of <strong>' . formatRand(paCommitment($app)) . '</strong> on the price of ' . formatRand((float)$app['price']) . '.</p>'
            . ($note !== '' ? '<div class="highlight-box"><p>' . nl2br(e($note)) . '</p></div>' : '')
            . '<p>If that works for you, accept it on your page. It is never an extra cost — the commitment comes off the price.</p>',
            'Accept or decline', 'decision');
        finRecordMail($app, "Suggested $percent%", $ok);
        return '';
    }
    // approve
    $app['details']['office_percent'] = paPercent($app);
    $app['details']['office_note'] = $note;
    paApproved($app, $by);
    return '';
}

/** Approved (by the office, or the client accepted the suggestion): the commitment letter goes out. */
function paApproved(array &$app, string $by): void {
    $app['status'] = 'approved';
    $app['details']['approved_at'] = date('Y-m-d H:i:s');
    finLog($app, 'Approved at ' . paPercent($app) . '% — commitment letter sent', $by);
    $ok = finMailClient($app, 'Pre-auction approved — please read your commitment letter',
        '<p>Your pre-auction request is <strong>approved at ' . paPercent($app) . '%</strong>: a commitment of <strong>' . formatRand(paCommitment($app)) . '</strong> on '
        . formatRand((float)$app['price']) . '. The balance of ' . formatRand((float)$app['price'] - paCommitment($app)) . ' is paid after your test drive.</p>'
        . '<p><strong>Before we send any invoice</strong>, please read your commitment letter and <strong>confirm on your page</strong> that you are going ahead, with your gate-pass details. '
        . 'Not ready yet? Tell us on the same page — you can still register and bid at auction.</p>'
        . '<p><a href="' . e(finDocLink($app, 'letter')) . '">Read your commitment letter</a></p>',
        'Confirm and get my invoice', 'confirm');
    finRecordMail($app, 'Commitment letter', $ok);
}

/** Pre-auction: the buyer confirms with their gate-pass details — the commitment invoice is issued. */
function paConfirm(array &$app, array $gate): void {
    $app['status'] = 'confirmed';
    $app['amount'] = paCommitment($app);
    $app['percent'] = paPercent($app);
    $app['details']['gate'] = $gate;
    $app['details']['confirmed_at'] = date('Y-m-d H:i:s');
    $app['details']['invoice'] = ['number' => 'AHG-PI-' . date('Ymd') . '-' . str_pad((string)$app['id'], 4, '0', STR_PAD_LEFT),
                                  'issued' => date('Y-m-d H:i:s'), 'due' => date('Y-m-d', strtotime('+' . FIN_DECISION_DAYS . ' days')),
                                  'amount' => paCommitment($app)];
    finLog($app, 'Confirmed going ahead — commitment invoice ' . $app['details']['invoice']['number']);
    $ok = finMailClient($app, 'Your commitment invoice',
        '<p>Thank you for confirming. Your commitment invoice for <strong>' . formatRand(paCommitment($app)) . '</strong> is ready.</p>'
        . '<ol><li>Pay <strong>only into the account on the invoice</strong>, reference <strong>' . e(finPayRef($app)) . '</strong>, within ' . FIN_DECISION_DAYS . ' days.</li>'
        . '<li>Upload your proof of payment on your page.</li><li>Once it reflects, your <strong>Buyer\'s Code</strong> letter comes to you with your test-drive appointment.</li></ol>'
        . '<p><a href="' . e(finDocLink($app, 'invoice')) . '">View / print your invoice</a></p>',
        'Upload proof of payment', 'documents');
    finRecordMail($app, 'Commitment invoice', $ok);
    finMailOffice($app, 'Client confirmed — commitment invoice sent');
}

/** Pre-auction: the commitment has reflected — Buyer's Code letter. */
function paMarkPaid(array &$app, string $by): string {
    if ($app['status'] !== 'confirmed') return 'Only a confirmed request with an invoice can be marked paid.';
    $code = finIssueBuyerCode($app);
    $app['status'] = 'paid';
    $app['details']['paid'] = ['at' => date('Y-m-d H:i:s'), 'by' => $by];
    $app['details']['docs']['payment']['status'] = 'accepted';
    if (!empty($app['details']['viewing']['date']) && ($app['details']['viewing']['status'] ?? '') !== 'confirmed') {
        $app['details']['viewing']['status'] = 'requested';
    }
    finLog($app, 'Commitment paid — Buyer\'s Code ' . $code, $by);
    $v = $app['details']['viewing'] ?? null;
    $ok = finMailClient($app, '✅ Commitment received — your Buyer\'s Code is ' . $code,
        '<p>Your commitment of <strong>' . formatRand((float)$app['amount']) . '</strong> has reflected. The vehicle is now <strong>reserved under your name</strong> — no other buyer can bid on it.</p>'
        . '<div class="highlight-box" style="text-align:center"><p style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#666;margin:0">Your Buyer\'s Code</p><p style="font-size:28px;font-weight:900;color:#005BA1;letter-spacing:.2em;margin:4px 0">' . e($code) . '</p></div>'
        . '<p>You have <strong>1 hour 30 minutes</strong> for the test drive and inspection at ' . e(COMPANY_ADDRESS) . ($v && !empty($v['date']) ? ', requested for <strong>' . e(finViewingLabel($v)) . '</strong> (we confirm the time on your page)' : '') . '. '
        . 'A trusted mechanic is welcome. <strong>No Buyer\'s Code, no entry — no walk-ins.</strong> Bring your ID.</p>'
        . '<p>After the test drive you choose: pay the balance of ' . formatRand((float)$app['price'] - (float)$app['amount']) . ', or ask for your commitment back (refunded less R' . number_format(PA_REFUND_FEE, 2) . ').</p>'
        . '<p><a href="' . e(finDocLink($app, 'code')) . '">Your Buyer\'s Code letter</a> (the VIN is on it).</p>',
        'See my test-drive booking', 'viewing');
    finRecordMail($app, 'Buyer\'s Code letter', $ok);
    return '';
}

/** Office confirms a viewing time (the client's, or another). */
function finConfirmViewing(array &$app, string $date, string $time, string $note, string $by): string {
    if ($err = finViewingError($date, $time)) return $err;
    $app['details']['viewing'] = ['date' => $date, 'time' => $time, 'status' => 'confirmed', 'note' => $note, 'at' => date('Y-m-d H:i:s')];
    finLog($app, 'Test drive confirmed for ' . finViewingLabel($app['details']['viewing']), $by);
    $ok = finMailClient($app, 'Your test drive is booked — ' . date('D j M', strtotime($date)) . ' at ' . $time,
        '<p>Your test drive is confirmed for <strong>' . e(finViewingLabel($app['details']['viewing'])) . '</strong> at our head office, ' . e(COMPANY_ADDRESS) . '.</p>'
        . ($note !== '' ? '<div class="highlight-box"><p>' . nl2br(e($note)) . '</p></div>' : '')
        . '<p>Bring your <strong>Buyer\'s Code (' . e((string)$app['buyer_code']) . ')</strong>, your ID and your driver\'s licence. Please arrive 10 minutes early.</p>',
        'See my booking', 'viewing');
    finRecordMail($app, 'Test drive confirmed', $ok);
    return '';
}

// ── Small things for the pages ────────────────────────────────

function finCsrfToken(): string {
    if (empty($_SESSION['fin_csrf'])) $_SESSION['fin_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['fin_csrf'];
}

function finCsrfOk(): bool {
    return !empty($_POST['csrf']) && !empty($_SESSION['fin_csrf']) && hash_equals($_SESSION['fin_csrf'], (string)$_POST['csrf']);
}

function finCleanPhone(string $p): string {
    return trim(preg_replace('/[^\d+ ]/', '', $p));
}

function finValidPhone(string $p): bool {
    return strlen(preg_replace('/\D/', '', $p)) >= 9;
}

function finMoneyInput(string $v): float {
    return (float)preg_replace('/[^\d.]/', '', str_replace(',', '', $v));
}

/** WhatsApp the office, filled in with who they are and their reference. */
function finWhatsAppUrl(array $app, string $extra = ''): string {
    $msg = 'Hi Auction Hub Group, this is ' . $app['name'] . ' — ' . ($app['kind'] === 'finance' ? 'in-house finance' : 'pre-auction') . ' ' . finRef($app)
         . ' for the ' . $app['vehicle'] . '. ' . $extra;
    return 'https://wa.me/' . COMPANY_WHATSAPP_INTL . '?text=' . rawurlencode(trim($msg));
}

/** The office's WhatsApp to the client: what is next for them, with their link. */
function finAdminWhatsAppUrl(array $app): string {
    $digits = preg_replace('/\D/', '', $app['phone']);
    if (str_starts_with($digits, '0')) $digits = '27' . substr($digits, 1);
    if (strlen($digits) < 9) return '';
    $first = explode(' ', trim($app['name']))[0];
    $next = match ($app['status']) {
        'quoted'    => 'your quotation is ready — please choose your monthly plan',
        'chosen'    => 'we are reviewing your deposit and plan now',
        'invoiced'  => 'your deposit invoice is out — please pay and upload your documents',
        'approved'  => $app['kind'] === 'finance' ? 'you are approved! Your Buyer\'s Code is ' . $app['buyer_code'] . ' — please book your test drive' : 'you are approved — please read your commitment letter and confirm',
        'countered' => 'we can approve ' . paPercent($app) . '% — please accept it on your page',
        'confirmed' => 'your commitment invoice is out — please pay and upload proof of payment',
        'paid'      => 'your commitment is in! Your Buyer\'s Code is ' . $app['buyer_code'],
        default     => 'here is an update on your application',
    };
    $msg = "Hi $first, Auction Hub Group here about the {$app['vehicle']} (" . finRef($app) . "): $next.\n" . finLink($app);
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($msg);
}

/** A signed-in client's / email's applications, newest first. */
function finForEmail(string $email): array {
    global $pdo;
    if ($email === '' || !ahgSchemaReady()) return [];
    $st = $pdo->prepare("SELECT * FROM client_applications WHERE email = ? ORDER BY id DESC LIMIT 50");
    $st->execute([$email]);
    return array_map('finDecode', $st->fetchAll());
}

// ── Page helpers ──────────────────────────────────────────────

/** Renders a vehicle <select> of eligible lots (data-* for the page script). */
function finVehicleSelect(array $lots, ?array $selected, string $name = 'auction_id', string $id = 'ap-vehicle', bool $required = true): string {
    $h = '<select class="ap-input" id="' . $id . '" name="' . $name . '"' . ($required ? ' required' : '') . '><option value="">' . ($required ? 'Choose a vehicle' : 'None') . '</option>';
    foreach ($lots as $lot) {
        $price = finPrice($lot);
        $h .= '<option value="' . (int)$lot['id'] . '" data-price="' . (int)round($price) . '" data-min="' . (int)finMinDeposit($lot) . '" data-max-term="' . max(array_keys(finTiers($lot))) . '"'
            . ($selected && (int)$selected['id'] === (int)$lot['id'] ? ' selected' : '') . '>'
            . e(finVehicleTitle($lot)) . ' — ' . e(formatRand($price)) . '</option>';
    }
    return $h . '</select>';
}

function finFlashHtml(): string {
    $out = '';
    foreach ($_SESSION['fin_flash'] ?? [] as [$type, $msg]) {
        $out .= '<div class="ap-flash ap-flash-' . e($type) . '" role="status">' . $msg . '</div>';
    }
    unset($_SESSION['fin_flash']);
    return $out;
}

function finFlash(string $type, string $htmlMessage): void {
    $_SESSION['fin_flash'][] = [$type, $htmlMessage];
}
