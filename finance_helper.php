<?php
// ============================================================
// AUCTION HUB GROUP — finance_helper.php
// Online applications for Finance and Pre-Auction Purchase.
// One table: client_applications (db_schema.php). Each client follows
// their application on a private page (my_application.php?t=…);
// the office works it in Admin → Finance & Pre-Auction.
//
// FINANCE (financing.php → finance_apply.php)
//   1 Assessment &   full application (ID, employment, income & expenses,
//     quote          credit profile, consent) → scored out of 100 (finAssess):
//                    grade A approved in principle; B–D need a start-up deposit;
//                    R (no room in the budget) referred to the office. Instant
//                    quote at the required deposit; only affordable terms open.
//   2 Term           the client chooses 6–48 months (up to 72 on trucks & plant).
//   3 Documents &    office approves the deposit (deposit invoice), suggests a
//     deposit        higher one, or declines. Client uploads SA ID, proof of
//                    address, 3 months' bank statements, payslip, licence and
//                    proof of payment.
//   4 Buyer Code     deposit verified → approval + Buyer Code, vehicle reserved.
//   5 Test drive     booked at head office.   6 Sign & drive away.
//
// PRE-AUCTION PURCHASE (preauction.php → preauction_request.php)
// Follows the 5 steps on preauction.php and uses the existing
// preauction_invoices system (Deal Builder) — nothing duplicated:
//   1 Submit details   vehicle, commitment fee %, ID/passport, address,
//                      alternative vehicle, preferred test-drive time.
//   2 Proforma invoice office issues it here → a preauction_invoices row
//                      (24-hour window, invoice_pop_upload.php link).
//   3 Commitment fee   client pays + uploads POP on invoice_pop_upload.php.
//   4 Buyer Code       approved in Deal Builder (or here) → sendBuyerCodeEmail();
//                      declined → sendDeclinationEmail().
//   5 Test drive       1h30 — proceed, switch to the alternative, or cancel
//                      for a refund less R102.
//
// VIN POLICY (vin-policy.php): VINs are never public — only the office,
// verified bidders, approved finance clients and Presold buyers see them.
// ============================================================

require_once __DIR__ . '/email_helper.php';

const FIN_UPLOAD_DIR = __DIR__ . '/uploads/applications/';
const FIN_DOC_MAX    = 8 * 1024 * 1024;
const PA_REFUND_FEE  = 102.0;      // retained on a cancelled commitment (preauction.php FAQ)
const FIN_INVOICE_DAYS = 3;        // finance deposit invoice: pay within

/** What a finance client uploads: key => [label, hint, max files]. Same list as financing.php. */
const FIN_DOCS = [
    'id'      => ['SA ID document', 'A clear copy — both sides of a smart ID card', 2],
    'address' => ['Proof of residential address', 'Not older than 3 months: municipal bill, bank statement or lease', 2],
    'bank'    => ['3 months\' bank statements', 'One PDF, or up to three files', 3],
    'income'  => ['Payslip or proof of income', 'Your latest payslip, or proof of income if self-employed', 3],
    'licence' => ['Driver\'s licence', 'Needed before you drive away', 2],
    'payment' => ['Proof of payment (deposit)', 'Your bank\'s confirmation of the deposit payment', 2],
];

const FIN_STATUS_LABELS = [
    'finance' => [
        'review'    => 'Referred — assess and call the client',
        'quoted'    => 'Quote sent — client to choose a term',
        'chosen'    => 'Term chosen — approve the deposit',
        'invoiced'  => 'Deposit invoice sent — documents & payment',
        'approved'  => 'Approved — Buyer Code issued',
        'completed' => 'Signed & driven away',
        'declined'  => 'Declined',
        'cancelled' => 'Cancelled by the client',
    ],
    'preauction' => [
        'requested'    => 'New — issue the proforma invoice',
        'invoiced'     => 'Proforma invoice sent — awaiting commitment fee',
        'pop_uploaded' => 'Proof of payment uploaded — verify & approve',
        'expired'      => 'Invoice expired (24 h) — re-issue or close',
        'paid'         => 'Presold — Buyer Code issued, test drive',
        'completed'    => 'Sold — balance paid',
        'refund'       => 'Cancelled after test drive — refund',
        'declined'     => 'Declined',
        'cancelled'    => 'Cancelled by the client',
    ],
];

// ── Rules (Admin → Finance & Pre-Auction → Settings) ──────────

function finSetting(string $key, string $default): string {
    if (!function_exists('leadSetting')) require_once __DIR__ . '/leads_helper.php';
    return leadSetting($key, $default);
}

/** Trucks, buses and plant: larger minimum deposit and longer terms. */
function finIsHeavy(array $a): bool {
    $t = strtolower(($a['body_type'] ?? '') . ' ' . ($a['item_name'] ?? ''));
    return (bool)preg_match('/\b(truck|horse|tipper|bus|trailer|tractor|loader|excavator|tlb|forklift|plant|crane|lowbed|rigid)\b/', $t);
}

function finMinDeposit(array $a): float {
    return finIsHeavy($a)
        ? (float)finSetting('fin_min_deposit_heavy', '50000')
        : (float)finSetting('fin_min_deposit', '10500');
}

/** Terms with the flat monthly rate (% per month) — the same tiers as the calculator on financing.php. */
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

/** Monthly instalment per term, as financing.php: financed × (1 + rate × months) ÷ months. */
function finPlans(float $financed, array $tiers): array {
    $plans = [];
    foreach ($tiers as $m => $rate) {
        $monthly = $financed * (1 + $rate / 100 * $m) / $m;
        $total   = round($monthly * $m, 2);
        $plans[] = ['months' => (int)$m, 'rate' => $rate, 'monthly' => round($monthly, 2), 'total' => $total, 'interest' => round($total - $financed, 2)];
    }
    return $plans;
}

/** @return array{0:int,1:int,2:int} min %, max %, default % commitment fee (preauction.php: 30–50%, invoices default 35%). */
function paRange(): array {
    $min = max(1, min(100, (int)finSetting('pa_min_percent', '30')));
    $max = max($min, min(100, (int)finSetting('pa_max_percent', '50')));
    $def = max($min, min($max, (int)finSetting('pa_default_percent', '35')));
    return [$min, $max, $def];
}

// ── Vehicles ──────────────────────────────────────────────────

function finVehicleSql(): string {
    return "SELECT a.id, a.item_name, a.year, a.mileage, a.transmission, a.fuel_type, a.color, a.body_type,
                   a.engine_size, a.image_url, a.start_price, a.current_price, a.status, a.end_time,
                   a.condition_grade, a.service_history, m.name AS make_name,
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

/** "Year Make Model" without repeating parts already in item_name (as auction.php). */
function finVehicleTitle(?array $a): string {
    if (!$a) return 'Your selected vehicle';
    $make = trim((string)($a['make_name'] ?? ''));
    $title = trim((string)($a['item_name'] ?? ''));
    $year = (string)($a['year'] ?? '');
    if ($make !== '' && stripos($title, $make) === false) $title = $make . ' ' . $title;
    if ($year !== '' && !str_contains($title, $year)) $title = $year . ' ' . $title;
    return trim($title);
}

/** Price for finance / pre-auction, or 0 when the lot is no longer open (bids, ended, sold). */
function finPrice(array $a): float {
    if ((int)($a['bid_count'] ?? 0) > 0) return 0.0;
    if (!in_array($a['status'] ?? '', ['upcoming', 'active'], true)) return 0.0;
    if (!empty($a['end_time']) && strtotime($a['end_time']) < time()) return 0.0;
    return (float)($a['current_price'] ?: $a['start_price']);
}

/** Lots already Presold / approved through an application. */
function finReservedIds(): array {
    global $pdo;
    try {
        return array_map('intval', $pdo->query("SELECT DISTINCT auction_id FROM client_applications
            WHERE auction_id IS NOT NULL AND status IN ('approved','paid','completed')")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

/** @return list<array> lots open for finance / pre-auction right now. */
function finEligibleLots(): array {
    global $pdo;
    $rows = $pdo->query(finVehicleSql() . " WHERE a.status IN ('upcoming','active') AND COALESCE(a.is_sold,0) = 0
                                            AND (a.end_time IS NULL OR a.end_time > NOW())
                                            AND COALESCE(NULLIF(a.current_price,0), a.start_price) > 0
                                            ORDER BY m.name, a.item_name, a.year DESC LIMIT 600")->fetchAll();
    $reserved = array_flip(finReservedIds());
    return array_values(array_filter($rows, fn($a) => finPrice($a) > 0 && !isset($reserved[(int)$a['id']])));
}

// ── VIN policy ────────────────────────────────────────────────

const FIN_VIN_CORE = '(?=[A-HJ-NPR-Z0-9]*\d)(?=[A-HJ-NPR-Z0-9]*[A-HJ-NPR-Z])[A-HJ-NPR-Z0-9]{17}';

/** Text with any VIN replaced: "Chassis no: AHV…" → "VIN on request". */
function vinScrub(?string $text): string {
    $text = (string)$text;
    if ($text === '') return '';
    $text = (string)preg_replace('~\b(?:VIN|chassis(?:\s*(?:no|number))?)\s*[:#.\-]?\s*' . FIN_VIN_CORE . '\b~i', 'VIN on request', $text);
    return (string)preg_replace('~\b' . FIN_VIN_CORE . '\b~', 'VIN on request', $text);
}

/** Lot pages show the VIN to the office and verified bidders only. */
function vinVisibleToVisitor(?array $user): bool {
    if (!$user) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    return ($user['bidder_status'] ?? '') === 'verified_bidder';
}

function vinChannels(): array {
    return [
        ['fa-id-card',      '#005BA1', 'Verified bidders',         'Register and pay your refundable ' . REG_FEE_LABEL . ' deposit — the VIN shows on the vehicle page once your bidder number is active.'],
        ['fa-hand-holding-dollar', '#e8a000', 'Approved finance clients', 'Once your finance is approved, the VIN is on your application page and approval letter.'],
        ['fa-key',          '#25D366', 'Presold to you',           'Once your pre-auction commitment fee is verified, the VIN is on your application page with your Buyer Code.'],
        ['fa-location-dot', '#8b5cf6', 'On site',                  'Visit us at ' . COMPANY_ADDRESS . ' and our team shows you the VIN on the vehicle itself.'],
    ];
}

function vinForAuction(int $id): string {
    global $pdo;
    $st = $pdo->prepare("SELECT vin_number FROM auctions WHERE id = ?");
    $st->execute([$id]);
    return strtoupper(trim((string)$st->fetchColumn()));
}

// ── Storage ───────────────────────────────────────────────────

/** buyer_code was VARCHAR(10) in the first version; AHG Buyer Codes are longer. Widened once. */
function finSchemaFix(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $len = (int)$pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_applications' AND COLUMN_NAME = 'buyer_code'")->fetchColumn();
        if ($len > 0 && $len < 30) $pdo->exec("ALTER TABLE client_applications MODIFY buyer_code VARCHAR(30) NULL");
    } catch (Throwable $e) { error_log('[AHG fin] schema fix: ' . $e->getMessage()); }
}

function finDecode(array $row): array {
    $row['details'] = json_decode((string)($row['details'] ?? ''), true) ?: [];
    return $row;
}

function finFind(string $token): ?array {
    global $pdo;
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || !ahgSchemaReady()) return null;
    finSchemaFix();
    $st = $pdo->prepare("SELECT * FROM client_applications WHERE token = ?");
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ? paSync(finDecode($row)) : null;
}

function finGet(int $id): ?array {
    global $pdo;
    finSchemaFix();
    $st = $pdo->prepare("SELECT * FROM client_applications WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ? paSync(finDecode($row)) : null;
}

function finSave(array $app): void {
    global $pdo;
    $pdo->prepare("UPDATE client_applications SET status = ?, amount = ?, percent = ?, months = ?, buyer_code = ?, details = ? WHERE id = ?")
        ->execute([$app['status'], $app['amount'], $app['percent'], $app['months'], $app['buyer_code'] ?: null,
                   json_encode($app['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $app['id']]);
}

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
    return ($app['kind'] === 'finance' ? 'AHG-FIN-' : 'AHG-PAR-') . str_pad((string)$app['id'], 5, '0', STR_PAD_LEFT);
}

/** EFT reference: the client's name plus the reference. */
function finPayRef(array $app): string {
    return mb_strtoupper(mb_substr(trim($app['name']), 0, 20)) . ' ' . finRef($app);
}

/** Creates an application (+ an enquiry row so it shows in Admin → Leads). */
function finCreate(string $kind, array $person, array $vehicle, float $amount, ?float $percent, array $details): array {
    global $pdo;
    finSchemaFix();
    $token = bin2hex(random_bytes(16));
    $details['vehicle'] = [
        'title' => finVehicleTitle($vehicle), 'year' => $vehicle['year'], 'mileage' => $vehicle['mileage'],
        'transmission' => $vehicle['transmission'], 'fuel' => $vehicle['fuel_type'], 'colour' => $vehicle['color'],
        'body' => $vehicle['body_type'], 'image' => $vehicle['image_url'], 'grade' => $vehicle['condition_grade'],
        'service' => $vehicle['service_history'] ?? '', 'heavy' => finIsHeavy($vehicle),
    ];
    $pdo->prepare("INSERT INTO client_applications (kind, token, status, name, email, phone, user_id, auction_id, vehicle, price, amount, percent, details, ip)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$kind, $token, $kind === 'finance' ? 'quoted' : 'requested', $person['name'], $person['email'], $person['phone'],
                   $person['user_id'] ?? null, (int)$vehicle['id'], finVehicleTitle($vehicle), finPrice($vehicle), $amount, $percent,
                   json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $_SERVER['REMOTE_ADDR'] ?? null]);
    $app = finGet((int)$pdo->lastInsertId());
    try {
        $pdo->prepare("INSERT INTO enquiries (type, name, email, phone, auction_id, message, source_page, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$kind, $person['name'], $person['email'], $person['phone'], (int)$vehicle['id'],
                       ($kind === 'finance' ? 'Finance application ' . finRef($app) . ' · deposit ' . formatRand($amount)
                                            : 'Pre-auction purchase request ' . finRef($app) . ' · commitment fee set by the office'),
                       $kind === 'finance' ? 'finance_apply.php' : 'preauction_request.php', $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) { error_log('[AHG fin] enquiry row: ' . $e->getMessage()); }
    return $app;
}

// ── Finance quote ─────────────────────────────────────────────

function finIssueQuote(array &$app, float $deposit, bool $counter = false, string $note = ''): void {
    $vehicle = finVehicle((int)$app['auction_id']) ?: ['body_type' => $app['details']['vehicle']['body'] ?? '', 'item_name' => $app['vehicle']];
    $price = (float)$app['price'];
    $app['amount'] = $deposit;
    $app['months'] = null;
    $app['details']['quote'] = [
        'number'   => 'AHG-FQ-' . date('Ym') . '-' . str_pad((string)$app['id'], 4, '0', STR_PAD_LEFT) . ($counter ? '-R' . (count($app['details']['quotes_old'] ?? []) + 1) : ''),
        'issued'   => date('Y-m-d H:i:s'),
        'valid'    => date('Y-m-d', strtotime('+7 days')),
        'price'    => $price,
        'deposit'  => $deposit,
        'financed' => max(0.0, $price - $deposit),
        'plans'    => finPlans(max(0.0, $price - $deposit), finTiers($vehicle)),
        'counter'  => $counter,
        'note'     => $note,
    ];
    unset($app['details']['choice']);
}

function finDepositError(array $vehicle, float $deposit): ?string {
    $min = finMinDeposit($vehicle);
    $price = finPrice($vehicle);
    if ($deposit < $min) return 'The minimum deposit on this vehicle is ' . formatRand($min) . '. Pay any amount above it to reduce your instalments.';
    if ($price > 0 && $deposit >= $price) return 'Your deposit covers the full price (' . formatRand($price) . ') — use a Pre-Auction Purchase instead.';
    return null;
}

function finChosenPlan(array $app): ?array {
    $m = (int)($app['details']['choice']['months'] ?? 0);
    foreach ($app['details']['quote']['plans'] ?? [] as $p) if ((int)$p['months'] === $m) return $p;
    return null;
}

// ── Credit & affordability assessment ─────────────────────────
// Every application is scored out of 100 on five things, the same way
// every time (Admin → Finance & Pre-Auction → Settings sets the cut-offs):
//   Affordability 30 · Credit profile 25 · Employment 20 · Deposit 15 · Stability 10
// Grade A  (≥ fin_score_a, 70): approved in principle at the normal minimum deposit.
// Grade B  (≥ fin_score_b, 55): approved with a start-up deposit of fin_startup_b % of the price.
// Grade C  (≥ fin_score_c, 40): start-up deposit of fin_startup_c %.
// Grade D  (below):             start-up deposit of fin_startup_d %.
// Whatever the grade, the instalment must fit the budget: the most we lend is what
// the client can repay on the longest term at fin_afford_pct % of their net income
// (and fin_buffer_pct % of what is left after expenses). Anything above that becomes
// part of the start-up deposit. No room in the budget at all → referred to the office (R).
// A credit bureau score entered by the office replaces the client's own estimate.

const FIN_CREDIT_BANDS = [   // key => [label, points out of 25, typical score]
    'excellent' => ['Excellent (700 and above)', 25, 720],
    'good'      => ['Good (650 – 699)',          21, 670],
    'fair'      => ['Fair (600 – 649)',          16, 620],
    'poor'      => ['Poor (500 – 599)',          10, 550],
    'very_poor' => ['Very poor (below 500)',      5, 450],
    'unknown'   => ["I don't know my score",     12, null],
];
const FIN_EMPLOYMENT = [
    'permanent' => 'Permanently employed',
    'contract'  => 'Contract / temporary',
    'self'      => 'Self-employed / own business',
    'pensioner' => 'Pensioner',
    'other'     => 'Other income (grant, maintenance, rental)',
];
const FIN_RESIDENCE = [
    'own'      => 'I own my home',
    'rent'     => 'I rent',
    'family'   => 'I live with family',
    'employer' => 'Employer-provided housing',
];
const FIN_GRADE_LABELS = [
    'A' => 'Approved in principle',
    'B' => 'Qualifies with a start-up deposit',
    'C' => 'Qualifies with a start-up deposit',
    'D' => 'Qualifies with a larger start-up deposit',
    'R' => 'Referred to our finance team',
];

/** All assessment rules, from settings. */
function finRules(): array {
    $n = fn(string $k, string $d) => (float)finSetting($k, $d);
    return [
        'score_a'    => $n('fin_score_a', '70'),    'score_b'   => $n('fin_score_b', '55'),   'score_c'   => $n('fin_score_c', '40'),
        'startup_b'  => $n('fin_startup_b', '20'),  'startup_c' => $n('fin_startup_c', '35'), 'startup_d' => $n('fin_startup_d', '50'),
        'afford_pct' => $n('fin_afford_pct', '30'), 'buffer_pct' => $n('fin_buffer_pct', '90'),
        'min_income' => $n('fin_min_income', '4000'),
        'norm_base'  => $n('fin_norm_base', '2500'), 'norm_dep' => $n('fin_norm_dependant', '900'),
        'max_age'    => $n('fin_max_age', '70'),
    ];
}

/** Score band for a bureau / known score (0–999 scale used by SA bureaus). */
function finBandForScore(int $score): string {
    return $score >= 700 ? 'excellent' : ($score >= 650 ? 'good' : ($score >= 600 ? 'fair' : ($score >= 500 ? 'poor' : 'very_poor')));
}

/** SA ID number → ['dob','age','gender','citizen'] or null when it is not valid (date + Luhn check digit). */
function finSaId(string $id): ?array {
    $id = preg_replace('/\D/', '', $id);
    if (strlen($id) !== 13) return null;
    $sum = 0;
    for ($i = 0; $i < 13; $i++) {
        $d = (int)$id[12 - $i];
        if ($i % 2 === 1) { $d *= 2; if ($d > 9) $d -= 9; }
        $sum += $d;
    }
    if ($sum % 10 !== 0) return null;
    $yy = (int)substr($id, 0, 2); $mm = (int)substr($id, 2, 2); $dd = (int)substr($id, 4, 2);
    $year = $yy + ($yy > (int)date('y') ? 1900 : 2000);
    if (!checkdate($mm, $dd, $year)) return null;
    $dob = sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    $age = (int)(new DateTime($dob))->diff(new DateTime('today'))->y;
    return ['dob' => $dob, 'age' => $age, 'gender' => (int)$id[6] >= 5 ? 'M' : 'F', 'citizen' => $id[10] === '0'];
}

/** Age from a date of birth (passport applicants). */
function finAgeFromDob(string $dob): ?int {
    $t = strtotime($dob);
    if (!$t || $t > time()) return null;
    return (int)(new DateTime(date('Y-m-d', $t)))->diff(new DateTime('today'))->y;
}

/**
 * Scores an application. $in: age, employment, months_employed, net_income, other_income,
 * exp_housing, exp_living, exp_debt, exp_other, dependants, residence, years_address,
 * bank_years, credit_band, credit_score (own estimate), debt_review, judgments,
 * bureau_score (office). $vehicle needs body_type / item_name (for the term tiers).
 */
function finAssess(array $in, float $price, float $deposit, array $vehicle): array {
    $r = finRules();
    $num = fn(string $k) => max(0.0, (float)($in[$k] ?? 0));
    $tiers = finTiers($vehicle);
    $longest = max(array_keys($tiers));
    $rate = $tiers[$longest] / 100;
    $minDep = finMinDeposit($vehicle);

    // Budget
    $income   = $num('net_income') + $num('other_income');
    $deps     = (int)min(15, $num('dependants'));
    $norm     = $r['norm_base'] + $r['norm_dep'] * $deps;
    $living   = max($num('exp_living'), $norm);
    $expenses = $num('exp_housing') + $living + $num('exp_debt') + $num('exp_other');
    $free     = $income - $expenses;
    $maxInst  = max(0.0, min($income * $r['afford_pct'] / 100, $free * $r['buffer_pct'] / 100));
    $maxLend  = $maxInst > 0 ? $maxInst * $longest / (1 + $rate * $longest) : 0.0;
    $instalFor = fn(float $dep) => max(0.0, $price - $dep) * (1 + $rate * $longest) / $longest;

    $pts = [];
    // 1 Affordability (30): cheapest instalment at the client's deposit vs what fits the budget
    $need  = $instalFor(max($deposit, $minDep));
    $ratio = $maxInst > 0 ? $need / $maxInst : 99;
    $p = $ratio <= .6 ? 30 : ($ratio <= .8 ? 25 : ($ratio <= 1 ? 19 : ($ratio <= 1.3 ? 9 : 0)));
    $pts['afford'] = ['Affordability', $p, 30, $maxInst > 0
        ? 'Instalment from ' . formatRand($need) . ' vs ' . formatRand($maxInst) . ' your budget allows'
        : 'No room in the monthly budget after expenses'];

    // 2 Credit profile (25)
    $bureau = isset($in['bureau_score']) && $in['bureau_score'] !== '' && $in['bureau_score'] !== null ? (int)$in['bureau_score'] : null;
    $own    = isset($in['credit_score']) && (int)$in['credit_score'] > 0 ? (int)$in['credit_score'] : null;
    $band   = $bureau !== null ? finBandForScore($bureau)
            : ($own !== null ? finBandForScore($own) : (isset(FIN_CREDIT_BANDS[$in['credit_band'] ?? '']) ? $in['credit_band'] : 'unknown'));
    $p = FIN_CREDIT_BANDS[$band][1];
    $notes = [($bureau !== null ? 'Bureau score ' . $bureau : ($own !== null ? 'Own estimate ' . $own : 'Own estimate')) . ' — ' . FIN_CREDIT_BANDS[$band][0]];
    if (!empty($in['debt_review'])) { $p -= 8; $notes[] = 'under debt review'; }
    if (!empty($in['judgments']))   { $p -= 6; $notes[] = 'judgments / adverse listings'; }
    $pts['credit'] = ['Credit profile', max(0, $p), 25, implode(', ', $notes)];

    // 3 Employment (20)
    $emp = isset(FIN_EMPLOYMENT[$in['employment'] ?? '']) ? $in['employment'] : 'other';
    $mo  = (int)$num('months_employed');
    $p = match ($emp) {
        'permanent' => 12 + ($mo >= 24 ? 8 : ($mo >= 12 ? 6 : ($mo >= 6 ? 3 : 0))),
        'self'      => 8 + ($mo >= 24 ? 6 : ($mo >= 12 ? 4 : 0)),
        'contract'  => 6 + ($mo >= 12 ? 4 : ($mo >= 6 ? 2 : 0)),
        'pensioner' => 16,
        default     => 4,
    };
    $pts['employment'] = ['Employment', $p, 20, FIN_EMPLOYMENT[$emp] . (in_array($emp, ['permanent', 'self', 'contract'], true) ? ' · ' . $mo . ' months' : '')];

    // 4 Deposit (15)
    $pct = $price > 0 ? $deposit / $price * 100 : 0;
    $p = $pct >= 30 ? 15 : ($pct >= 20 ? 12 : ($pct >= 15 ? 9 : ($pct >= 10 ? 6 : 3)));
    $pts['deposit'] = ['Deposit', $p, 15, formatRand($deposit) . ' = ' . round($pct) . '% of the price'];

    // 5 Stability (10)
    $ya = $num('years_address'); $yb = $num('bank_years');
    $p = ($ya >= 3 ? 5 : ($ya >= 1 ? 3 : 1)) + (['own' => 3, 'rent' => 2, 'employer' => 2, 'family' => 1][$in['residence'] ?? ''] ?? 1)
       + ($yb >= 2 ? 2 : ($yb >= 1 ? 1 : 0));
    $yrs = fn(float $v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
    $pts['stability'] = ['Stability', min(10, $p), 10, $yrs($ya) . ' yrs at address · ' . $yrs($yb) . ' yrs with bank'];

    $score = (int)array_sum(array_column($pts, 1));
    $grade = $score >= $r['score_a'] ? 'A' : ($score >= $r['score_b'] ? 'B' : ($score >= $r['score_c'] ? 'C' : 'D'));
    $gradePct = ['A' => 0, 'B' => $r['startup_b'], 'C' => $r['startup_c'], 'D' => $r['startup_d']][$grade];

    $reasons = [];
    $age = isset($in['age']) && $in['age'] !== '' && $in['age'] !== null ? (int)$in['age'] : null;
    if ($age !== null && $age < 18)            $reasons[] = 'Applicants must be 18 or older.';
    if ($age !== null && $age > $r['max_age']) $reasons[] = 'Applicants older than ' . (int)$r['max_age'] . ' are assessed personally by our finance team.';
    if ($income < $r['min_income'])            $reasons[] = 'Monthly income is below ' . formatRand($r['min_income']) . '.';
    if ($maxInst <= 0)                         $reasons[] = 'After expenses there is no room in the budget for an instalment.';

    // Start-up deposit: the grade's share of the price, and enough that the rest fits the budget
    $required = max($minDep, $price * $gradePct / 100, $price - $maxLend);
    $required = min($price, ceil($required / 500) * 500);
    if (!$reasons && $required >= $price) $reasons[] = 'The instalment on this vehicle does not fit the budget — a cheaper vehicle, or a cash / pre-auction purchase, may suit better.';
    if ($reasons) $grade = 'R';

    return [
        'at' => date('Y-m-d H:i:s'), 'score' => $score, 'grade' => $grade, 'label' => FIN_GRADE_LABELS[$grade],
        'points' => $pts, 'reasons' => $reasons, 'credit_source' => $bureau !== null ? 'bureau' : 'own',
        'income' => round($income, 2), 'expenses' => round($expenses, 2), 'living_norm' => $norm, 'free' => round($free, 2),
        'max_instalment' => round($maxInst, 2), 'max_lend' => round($maxLend, 2), 'longest' => $longest,
        'min_deposit' => $minDep, 'required_deposit' => $grade === 'R' ? null : (float)$required,
        'startup' => $grade !== 'R' && $required > $minDep, 'startup_pct' => $gradePct,
        'qualifies' => $grade !== 'R' && $deposit >= $required,
    ];
}

/** Re-scores a stored application (e.g. after the office enters a bureau score) and keeps the result on it. */
function finReassess(array &$app): array {
    $vehicle = finVehicle((int)$app['auction_id']) ?: ['body_type' => $app['details']['vehicle']['body'] ?? '', 'item_name' => $app['vehicle']];
    $in = $app['details']['finances'] ?? [];
    if (isset($app['details']['bureau']['score'])) $in['bureau_score'] = (int)$app['details']['bureau']['score'];
    // Scored on the deposit the client offered — the start-up deposit is the outcome, not an input
    $offered = (float)($app['details']['quote']['offered'] ?? $app['amount']);
    return $app['details']['assessment'] = finAssess($in, (float)$app['price'], $offered, $vehicle) + ['offered' => $offered];
}

/** Marks each quoted plan affordable or not against the assessment. */
function finMarkAffordable(array &$app): void {
    if (empty($app['details']['quote']['plans']) || empty($app['details']['assessment'])) return;
    $max = (float)$app['details']['assessment']['max_instalment'];
    foreach ($app['details']['quote']['plans'] as &$p) $p['affordable'] = $max > 0 && $p['monthly'] <= $max + 0.5;
    unset($p);
}

/** True when the client may choose this plan (old applications have no assessment: all plans). */
function finPlanAllowed(array $plan): bool {
    return !array_key_exists('affordable', $plan) || $plan['affordable'];
}

/** Grade colour for badges and the gauge. */
function finGradeColour(string $g): string {
    return ['A' => '#1a9e4e', 'B' => '#0078d7', 'C' => '#e8a000', 'D' => '#ef6c00', 'R' => '#a30000'][$g] ?? '#667';
}

/**
 * Prices the quote from the assessment: the deposit becomes the start-up deposit when the
 * client offered less, plans are marked affordable, and the status is quoted (or review
 * when the assessment refers the application to the office).
 */
function finQuoteFromAssessment(array &$app, float $offered, bool $counter = false, string $note = ''): void {
    $as = $app['details']['assessment'];
    // The office's own suggestion (counter) is used as is; the client's offer is raised to the start-up deposit
    $deposit = !$counter && $as['grade'] !== 'R' ? max($offered, (float)$as['required_deposit']) : $offered;
    if ($counter) $app['details']['quotes_old'][] = $app['details']['quote'];
    finIssueQuote($app, $deposit, $counter, $note);
    $app['details']['quote']['offered'] = $counter ? (float)($app['details']['quotes_old'][count($app['details']['quotes_old']) - 1]['offered'] ?? $offered) : $offered;
    $app['details']['quote']['startup'] = !$counter && $deposit > $offered;
    finMarkAffordable($app);
    if ($counter && !array_filter($app['details']['quote']['plans'], 'finPlanAllowed')) {
        foreach ($app['details']['quote']['plans'] as &$p) unset($p['affordable']);   // office override: every term open
        unset($p);
    }
    $app['status'] = $app['details']['assessment']['grade'] === 'R' ? 'review' : 'quoted';
}

// ── Application form (finance_apply.php, finance_check.php) ───

const FIN_TITLES   = ['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Prof'];
const FIN_MARITAL  = ['single' => 'Single', 'married' => 'Married', 'cohabiting' => 'Living together', 'divorced' => 'Divorced', 'widowed' => 'Widowed'];
const FIN_BANKS    = ['ABSA', 'African Bank', 'Bidvest Bank', 'Capitec', 'Discovery Bank', 'FNB', 'Investec', 'Nedbank', 'Old Mutual', 'Standard Bank', 'TymeBank', 'Other'];
const FIN_PAY_DAYS = ['25th', 'Last working day', '15th', '1st', 'Weekly', 'Fortnightly', 'Other'];

/**
 * Reads the application form. Returns [fields, assessment input, errors, error step].
 * $full = false (finance_check.php pre-check) only needs the money and credit fields.
 * Steps: 1 vehicle & deposit · 2 about you · 3 work & income · 4 expenses & credit · 5 consent.
 */
function finReadForm(array $p, bool $full = true): array {
    $t = fn(string $k, int $max = 150) => trim(mb_substr((string)($p[$k] ?? ''), 0, $max));
    $m = fn(string $k) => finMoneyInput((string)($p[$k] ?? ''));
    $f = [
        'title' => in_array($p['title'] ?? '', FIN_TITLES, true) ? $p['title'] : '',
        'first_names' => $t('first_names'), 'surname' => $t('surname'),
        'id_type' => ($p['id_type'] ?? 'sa') === 'passport' ? 'passport' : 'sa',
        'id_number' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $t('id_number', 30))),
        'dob' => $t('dob', 10), 'marital' => isset(FIN_MARITAL[$p['marital'] ?? '']) ? $p['marital'] : '',
        'dependants' => max(0, min(15, (int)($p['dependants'] ?? 0))),
        'phone' => finCleanPhone((string)($p['phone'] ?? '')), 'email' => $t('email', 190),
        'address' => $t('address', 300), 'postal_code' => preg_replace('/\D/', '', $t('postal_code', 4)),
        'residence' => isset(FIN_RESIDENCE[$p['residence'] ?? '']) ? $p['residence'] : '',
        'years_address' => max(0, min(80, (float)($p['years_address'] ?? 0))),
        'employment' => isset(FIN_EMPLOYMENT[$p['employment'] ?? '']) ? $p['employment'] : '',
        'employer' => $t('employer'), 'job_title' => $t('job_title', 100),
        'employer_phone' => finCleanPhone((string)($p['employer_phone'] ?? '')),
        'months_employed' => max(0, min(600, (int)($p['emp_years'] ?? 0) * 12 + (int)($p['emp_months'] ?? 0))),
        'pay_day' => in_array($p['pay_day'] ?? '', FIN_PAY_DAYS, true) ? $p['pay_day'] : '',
        'gross_income' => $m('gross_income'), 'net_income' => $m('net_income'), 'other_income' => $m('other_income'),
        'exp_housing' => $m('exp_housing'), 'exp_living' => $m('exp_living'), 'exp_debt' => $m('exp_debt'), 'exp_other' => $m('exp_other'),
        'bank_name' => in_array($p['bank_name'] ?? '', FIN_BANKS, true) ? $p['bank_name'] : '',
        'bank_years' => max(0, min(80, (float)($p['bank_years'] ?? 0))),
        'credit_band' => isset(FIN_CREDIT_BANDS[$p['credit_band'] ?? '']) ? $p['credit_band'] : 'unknown',
        'credit_score' => ($cs = (int)($p['credit_score'] ?? 0)) >= 100 && $cs <= 999 ? $cs : null,
        'debt_review' => ($p['debt_review'] ?? '') === 'yes', 'judgments' => ($p['judgments'] ?? '') === 'yes',
    ];
    $e = [];
    $age = null;
    if ($f['id_type'] === 'sa') {
        if ($f['id_number'] !== '') {
            $id = finSaId($f['id_number']);
            if ($id) { $age = $id['age']; $f['dob'] = $id['dob']; $f['gender'] = $id['gender']; }
            else $e['id_number'] = [2, 'That is not a valid 13-digit SA ID number — please check it.'];
        } elseif ($full) $e['id_number'] = [2, 'Please enter your SA ID number.'];
    } else {
        if ($full && !preg_match('/^[A-Z0-9]{6,20}$/', $f['id_number'])) $e['id_number'] = [2, 'Please enter your passport number.'];
        $age = $f['dob'] !== '' ? finAgeFromDob($f['dob']) : null;
        if ($full && $age === null) $e['dob'] = [2, 'Please enter your date of birth.'];
    }
    if ($age !== null && $age < 18) $e['id_number'] = [2, 'You must be 18 or older to apply for finance.'];
    $f['age'] = $age;

    if ($full) {
        if ($f['title'] === '')                         $e['title'] = [2, 'Please choose your title.'];
        if (mb_strlen($f['first_names']) < 2)           $e['first_names'] = [2, 'Please enter your first name(s).'];
        if (mb_strlen($f['surname']) < 2)               $e['surname'] = [2, 'Please enter your surname.'];
        if ($f['marital'] === '')                       $e['marital'] = [2, 'Please choose your marital status.'];
        if (!finValidPhone($f['phone']))                $e['phone'] = [2, 'Please enter a number we can call or WhatsApp.'];
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $e['email'] = [2, 'Please enter a valid email — your result and quote are sent there.'];
        if (mb_strlen($f['address']) < 8)               $e['address'] = [2, 'Please enter your residential address.'];
        if ($f['residence'] === '')                     $e['residence'] = [2, 'Please choose where you live.'];
        if (in_array($f['employment'], ['permanent', 'contract', 'self'], true) && mb_strlen($f['employer']) < 2)
            $e['employer'] = [3, $f['employment'] === 'self' ? 'Please enter your business name.' : 'Please enter your employer.'];
        if ($f['pay_day'] === '')                       $e['pay_day'] = [3, 'Please choose when you are paid.'];
        if ($f['bank_name'] === '')                     $e['bank_name'] = [4, 'Please choose your bank.'];
    }
    if ($f['employment'] === '')                        $e['employment'] = [3, 'Please choose your employment status.'];
    if ($f['net_income'] <= 0)                          $e['net_income'] = [3, 'Please enter your take-home pay (after tax and deductions).'];
    if ($f['gross_income'] > 0 && $f['net_income'] > $f['gross_income']) $e['net_income'] = [3, 'Take-home pay cannot be more than your gross salary.'];
    if ($f['exp_living'] <= 0)                          $e['exp_living'] = [4, 'Please enter your monthly living costs (food, transport, school, airtime…).'];

    $in = ['age' => $age] + array_intersect_key($f, array_flip(['employment', 'months_employed', 'net_income', 'other_income', 'exp_housing',
        'exp_living', 'exp_debt', 'exp_other', 'dependants', 'residence', 'years_address', 'bank_years', 'credit_band', 'credit_score', 'debt_review', 'judgments']));
    $step = $e ? min(array_map(fn($x) => $x[0], $e)) : 0;
    return [$f, $in, array_map(fn($x) => $x[1], $e), $step];
}

// ── Pre-auction: the existing preauction_invoices system ──────

function paPercent(array $app): int {
    return (int)round((float)($app['details']['invoice_pct'] ?? $app['percent']));
}

function paCommitment(array $app): float {
    return round((float)$app['price'] * paPercent($app) / 100, 2);
}

function paInvoiceRow(array $app): ?array {
    global $pdo;
    $id = (int)($app['details']['pa_invoice_id'] ?? 0);
    if ($id <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM preauction_invoices WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/** The client's link to view the proforma invoice and upload proof of payment (invoice_pop_upload.php). */
function paInvoiceLink(array $inv): string {
    return APP_URL . '/invoice_pop_upload.php?num=' . urlencode($inv['invoice_number']) . '&token=' . urlencode($inv['upload_token']);
}

/**
 * Follows the linked preauction_invoices row, so approvals and declines made in the
 * Deal Builder (or POP uploads) show on the application too.
 */
function paSync(array $app): array {
    if ($app['kind'] !== 'preauction' || in_array($app['status'], ['completed', 'refund', 'cancelled'], true)) return $app;
    $inv = paInvoiceRow($app);
    if (!$inv) return $app;
    $new = match ($inv['status']) {
        'approved'     => 'paid',
        'declined'     => 'declined',
        'pop_uploaded' => 'pop_uploaded',
        default        => strtotime((string)$inv['expires_at']) < time() ? 'expired' : 'invoiced',
    };
    if ($new === 'paid' && $app['status'] === 'paid') return $app;
    if ($new === $app['status'] && ($new !== 'paid')) return $app;
    $app['status'] = $new;
    if ($new === 'paid') {
        $app['buyer_code'] = $inv['buyer_code'];
        $app['details']['paid'] = ['at' => date('Y-m-d H:i:s')];
        finLog($app, 'Commitment fee verified — Presold, Buyer Code ' . $inv['buyer_code'], 'office');
    } elseif ($new === 'declined') {
        $app['details']['declined'] = ['at' => date('Y-m-d H:i:s'), 'reason' => (string)$inv['declination_reason'], 'institution' => true];
        finLog($app, 'Declined by the financial institution', 'office');
    } elseif ($new === 'pop_uploaded') {
        finLog($app, 'Proof of payment uploaded');
    } elseif ($new === 'expired') {
        finLog($app, 'Proforma invoice expired (24 hours)', 'system');
    }
    try { finSave($app); } catch (Throwable $e) { error_log('[AHG fin] sync: ' . $e->getMessage()); }
    return $app;
}

/** Step 2: the office issues the proforma invoice — a row in preauction_invoices, valid 24 hours. */
function paIssueInvoice(array &$app, int $percent, string $note, int $adminId, string $by): string {
    global $pdo;
    [$min, $max] = paRange();
    if (!in_array($app['status'], ['requested', 'expired'], true)) return 'An invoice has already been issued.';
    if ($percent < $min || $percent > $max) return "Choose a commitment fee between $min% and $max%.";
    $v = finVehicle((int)$app['auction_id']);
    $d = $app['details'];
    $price = (float)$app['price'];
    $fee = round($price * $percent / 100, 2);
    $num = 'AHG-INV-' . date('Ym') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $token = bin2hex(random_bytes(32));
    $notes = 'Online pre-auction request ' . finRef($app)
           . (!empty($d['alt']) ? ' · Alternative vehicle: ' . $d['alt']['title'] . ' (lot ' . $d['alt']['id'] . ')' : '')
           . (!empty($d['viewing']['date']) ? ' · Test drive wanted: ' . finViewingLabel($d['viewing']) : '')
           . (!empty($d['mechanic']) ? ' · Bringing a mechanic' : '')
           . ($note !== '' ? ' · ' . $note : '');
    try {
        $pdo->prepare("INSERT INTO preauction_invoices
                (invoice_number, buyer_name, buyer_email, buyer_phone, buyer_id_passport, buyer_address,
                 vehicle_description, vehicle_year, vehicle_mileage, vehicle_transmission, vehicle_service_history,
                 vehicle_condition, vehicle_body, vehicle_colour, vehicle_fuel, vehicle_image_url,
                 total_price, commitment_fee_pct, commitment_fee_amount, outstanding_amount,
                 upload_token, notes, created_by, auction_id, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$num, $app['name'], $app['email'], $app['phone'], $d['id_number'] ?? null, $d['address'] ?? null,
                       $app['vehicle'], $d['vehicle']['year'] ?: null, !empty($d['vehicle']['mileage']) ? number_format((int)$d['vehicle']['mileage']) . ' km' : null,
                       $d['vehicle']['transmission'] ?? null, !empty($v['service_history']) && stripos((string)$v['service_history'], 'no') !== 0 ? 'Yes' : 'No', 'Used',
                       $d['vehicle']['body'] ?? null, $d['vehicle']['colour'] ?? null, $d['vehicle']['fuel'] ?? null, $d['vehicle']['image'] ?? null,
                       $price, $percent, $fee, round($price - $fee, 2), $token, $notes, $adminId ?: null, (int)$app['auction_id'],
                       date('Y-m-d H:i:s', strtotime('+24 hours'))]);
    } catch (Throwable $e) {
        error_log('[AHG fin] issue invoice: ' . $e->getMessage());
        return 'Could not create the invoice (is the preauction_invoices table set up?).';
    }
    $app['details']['pa_invoice_id'] = (int)$pdo->lastInsertId();
    $app['details']['invoice_pct'] = $percent;
    $app['percent'] = $percent;
    $app['amount'] = $fee;
    $app['status'] = 'invoiced';
    finLog($app, "Proforma invoice $num issued at $percent% (" . formatRand($fee) . ')', $by);
    $inv = paInvoiceRow($app);
    $ok = finMailClient($app, 'Your pre-auction proforma invoice — valid for 24 hours',
        '<p>Thank you for your pre-auction purchase request. Your <strong>proforma invoice ' . e($num) . '</strong> is ready:</p>'
        . '<div class="highlight-box"><p><strong>Total price:</strong> ' . formatRand($price) . '</p>'
        . '<p><strong>Commitment fee (' . $percent . '%):</strong> <span class="gold">' . formatRand($fee) . '</span></p>'
        . '<p><strong>Outstanding after your test drive:</strong> ' . formatRand($price - $fee) . '</p></div>'
        . ($note !== '' ? '<p>' . nl2br(e($note)) . '</p>' : '')
        . '<p>The window is set by the financial institution: please pay the commitment fee and upload your proof of payment <strong>within 24 hours</strong>. '
        . 'Once it is verified the vehicle is marked <strong>Presold</strong> under your name and your <strong>Buyer Code</strong> is emailed to you.</p>'
        . ($inv ? '<p><a class="cta-btn" href="' . e(paInvoiceLink($inv)) . '">View invoice &amp; upload proof of payment</a></p>' : ''),
        'Follow my request');
    finRecordMail($app, 'Proforma invoice ' . $num, $ok);
    return '';
}

/** Step 4 from this tab: same as Deal Builder → Approve (Buyer Code email). */
function paApproveInvoice(array &$app, string $note, string $by): string {
    global $pdo;
    $inv = paInvoiceRow($app);
    if (!$inv || !in_array($inv['status'], ['pending', 'pop_uploaded'], true)) return 'There is no open invoice to approve.';
    $code = 'AHG-PA-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
    $pdo->prepare("UPDATE preauction_invoices SET status = 'approved', buyer_code = ?, date_signed = CURDATE() WHERE id = ?")->execute([$code, $inv['id']]);
    if ($inv['buyer_email']) {
        sendBuyerCodeEmail(['buyer_name' => $inv['buyer_name'], 'buyer_email' => $inv['buyer_email'], 'buyer_code' => $code,
            'invoice_number' => $inv['invoice_number'], 'vehicle_description' => $inv['vehicle_description'],
            'commitment_fee' => $inv['commitment_fee_amount'], 'outstanding' => $inv['outstanding_amount'],
            'total_price' => $inv['total_price'], 'admin_note' => $note]);
    }
    $app = paSync($app);
    finLog($app, 'Approved — Buyer Code emailed', $by);
    return '';
}

/** Decline: an open invoice is declined the AHG way (financial institution email); a request without one gets a polite email. */
function paDecline(array &$app, string $reason, string $by): string {
    global $pdo;
    $inv = paInvoiceRow($app);
    if ($inv && $inv['status'] !== 'approved') {
        if ($reason === '') return 'A reason is required — it goes to the buyer in the financial institution notice.';
        $pdo->prepare("UPDATE preauction_invoices SET status = 'declined', declination_reason = ? WHERE id = ?")->execute([$reason, $inv['id']]);
        if ($inv['buyer_email']) {
            sendDeclinationEmail(['buyer_name' => $inv['buyer_name'], 'buyer_email' => $inv['buyer_email'],
                'invoice_number' => $inv['invoice_number'], 'vehicle_description' => $inv['vehicle_description'], 'reason' => $reason]);
        }
        $app = paSync($app);
        return '';
    }
    finDecline($app, $reason, $by);
    return '';
}

// ── Buyer Code (finance) ──────────────────────────────────────

/** AHG Buyer Code format, as the pre-auction ones: AHG-FN-2026-XXXXXXX. */
function finIssueBuyerCode(array &$app): string {
    if (!empty($app['buyer_code'])) return $app['buyer_code'];
    return $app['buyer_code'] = 'AHG-FN-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
}

// ── Steps ─────────────────────────────────────────────────────

/** [icon, colour, title] — colours as on financing.php / preauction.php. */
function finSteps(string $kind): array {
    return $kind === 'finance'
        ? [['fa-calculator', '#005BA1', 'Assessment & Quote'], ['fa-calendar-days', '#0078d7', 'Choose Your Term'],
           ['fa-file-signature', '#8b5cf6', 'Documents & Deposit'], ['fa-id-badge', '#e8a000', 'Buyer Code'],
           ['fa-car-side', '#ef4444', 'Test Drive'], ['fa-key', '#25D366', 'Sign & Drive Away']]
        : [['fa-user-pen', '#005BA1', 'Submit Your Details'], ['fa-file-invoice-dollar', '#8b5cf6', 'Proforma Invoice'],
           ['fa-coins', '#f59e0b', 'Commitment Fee'], ['fa-id-badge', '#25D366', 'Buyer Code'], ['fa-car-side', '#ef4444', 'Test Drive & Decide']];
}

/** Steps completed so far (0-based index of the current step). */
function finStepIndex(array $app): int {
    $s = $app['status'];
    if ($app['kind'] === 'finance') {
        return match ($s) {
            'review' => 0, 'quoted' => 1, 'chosen', 'invoiced' => 2,
            'approved' => ($app['details']['viewing']['status'] ?? '') === 'confirmed' ? 4 : 3,
            'completed' => 6, default => 0,
        };
    }
    return match ($s) {
        'requested', 'expired' => 1, 'invoiced' => 2, 'pop_uploaded' => 3, 'paid' => 4,
        'completed', 'refund' => 5, default => 0,
    };
}

function finStatusLabel(array $app): string {
    return FIN_STATUS_LABELS[$app['kind']][$app['status']] ?? ucfirst($app['status']);
}

/** AHG badge class for a status. */
function finBadge(array $app): string {
    $s = $app['status'];
    $cls = in_array($s, ['chosen', 'requested', 'pop_uploaded', 'invoiced', 'review'], true) ? 'badge-pending'
        : (in_array($s, ['approved', 'paid', 'completed'], true) ? 'badge-approved'
        : (in_array($s, ['declined', 'cancelled', 'refund', 'expired'], true) ? 'badge-rejected' : 'badge-upcoming'));
    return '<span class="badge ' . $cls . '">' . e(finStatusLabel($app)) . '</span>';
}

function finClosed(array $app): bool {
    return in_array($app['status'], ['declined', 'cancelled', 'completed', 'refund'], true);
}

// ── Finance documents ─────────────────────────────────────────

function finUploadDir(int $id): string {
    if (!is_dir(FIN_UPLOAD_DIR)) @mkdir(FIN_UPLOAD_DIR, 0755, true);
    if (!is_file(FIN_UPLOAD_DIR . '.htaccess')) @file_put_contents(FIN_UPLOAD_DIR . '.htaccess', "Require all denied\nDeny from all\n");
    $dir = FIN_UPLOAD_DIR . $id . '/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

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

function finDocUpload(array &$app, string $kind, array $files): array {
    if (!isset(FIN_DOCS[$kind]) || $app['kind'] !== 'finance') return [0, 'Unknown document.'];
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
    foreach (array_keys(FIN_DOCS) as $k) {
        $out['total']++;
        $d = $app['details']['docs'][$k] ?? null;
        if ($d && !empty($d['files'])) $out['uploaded']++;
        if (($d['status'] ?? '') === 'accepted') $out['accepted']++;
        if (($d['status'] ?? '') === 'rejected') $out['rejected']++;
    }
    return $out;
}

// ── Test drive ────────────────────────────────────────────────

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

// ── Email (AHG template from email_helper.php) ────────────────

function finMailClient(array $app, string $subject, string $bodyHtml, string $button = 'View my application', string $anchor = ''): bool {
    try {
        $mail = _buildMailer();
        $mail->addAddress($app['email'], $app['name']);
        $mail->Subject = $subject . ' | ' . finRef($app);
        $body = '<p>Dear ' . e($app['name']) . ',</p>' . $bodyHtml
              . '<div class="highlight-box"><p><strong>Reference:</strong> ' . e(finRef($app)) . '</p><p><strong>Vehicle:</strong> ' . e($app['vehicle']) . '</p></div>'
              . '<a href="' . e(finLink($app, $anchor)) . '" class="cta-btn">' . e($button) . '</a>'
              . '<p style="font-size:13px;color:#888;">This link is private to you. Questions? Contact us at <a href="mailto:' . COMPANY_EMAIL . '">' . COMPANY_EMAIL . '</a> or ' . COMPANY_PHONE . '.</p>';
        $mail->Body = _emailTemplate($subject, $body);
        $mail->AltBody = strip_tags(str_replace(['</p>', '<br>', '</li>'], "\n", $bodyHtml)) . "\n\nYour application: " . finLink($app, $anchor);
        return _sendMail($mail, 'fin_' . $app['kind']);
    } catch (Throwable $e) {
        error_log('[AHG fin] client mail: ' . $e->getMessage());
        return false;
    }
}

function finMailOffice(array $app, string $what): void {
    try {
        $mail = _buildMailer();
        $mail->addAddress(MAIL_ADMIN);
        $mail->addReplyTo($app['email'], $app['name']);
        $mail->Subject = '🔔 ' . ($app['kind'] === 'finance' ? 'Finance' : 'Pre-Auction') . ': ' . $what . ' — ' . $app['name'] . ' | ' . finRef($app);
        $rows = [['Client', $app['name']], ['Phone', $app['phone']], ['Email', $app['email']], ['Vehicle', $app['vehicle']],
                 ['Price', formatRand((float)$app['price'])],
                 [$app['kind'] === 'finance' ? 'Deposit' : 'Commitment fee', $app['kind'] === 'finance' ? formatRand((float)$app['amount']) : paPercent($app) . '% = ' . formatRand(paCommitment($app))],
                 ['Status', finStatusLabel($app)]];
        $body = '<h2>' . e($what) . '</h2><div class="highlight-box">';
        foreach ($rows as [$k, $v]) $body .= '<p><strong>' . e($k) . ':</strong> ' . e($v) . '</p>';
        $body .= '</div><a class="cta-btn" href="' . APP_URL . '/admin_dashboard.php?tab=applications&app=' . (int)$app['id'] . '">Open in Admin</a>';
        $mail->Body = _emailTemplate($what, $body);
        $mail->AltBody = $what . ': ' . $app['name'] . ' ' . $app['phone'] . ' — ' . $app['vehicle'];
        _sendMail($mail, 'fin_office');
    } catch (Throwable $e) { error_log('[AHG fin] office mail: ' . $e->getMessage()); }
}

function finRecordMail(array &$app, string $what, bool $ok): void {
    $app['details']['emails'][] = ['at' => date('Y-m-d H:i:s'), 'what' => $what, 'ok' => $ok];
}

function finSendQuote(array &$app): bool {
    $q = $app['details']['quote'];
    $review = $app['status'] === 'review';
    $rows = '';
    foreach ($q['plans'] as $p) if (finPlanAllowed($p)) $rows .= '<p><strong>' . $p['months'] . ' months:</strong> ' . formatRand($p['monthly']) . ' / month</p>';
    if ($rows === '') $rows = '<p>None of the terms fit your budget at this deposit yet — our finance team will call you.</p>';
    $intro = !empty($q['counter'])
        ? '<p>Thank you for your patience. We are not able to approve a deposit of ' . formatRand((float)(end($app['details']['quotes_old'])['deposit'] ?? 0))
          . ' on this vehicle, but we <strong>can approve a deposit of ' . formatRand($q['deposit']) . '</strong>. Your updated quote is below.</p>'
          . ($q['note'] !== '' ? '<p>' . nl2br(e($q['note'])) . '</p>' : '')
        : '<p>Thank you for applying for <strong>Vehicle Finance</strong>. We have assessed your application — here is your result and personalised quote:</p>';
    if (!empty($app['details']['assessment'])) {
        $as = $app['details']['assessment'];
        $intro .= '<div class="highlight-box"><p><strong>Your finance score:</strong> ' . (int)$as['score'] . ' / 100 — ' . e($as['label']) . '</p>'
            . (!empty($q['startup']) ? '<p><strong>Start-up deposit for approval:</strong> ' . formatRand($q['deposit']) . ' (you offered ' . formatRand((float)$q['offered']) . ')</p>' : '')
            . '<p><strong>Most your budget allows per month:</strong> ' . formatRand((float)$as['max_instalment']) . '</p></div>';
    }
    $body = $intro
        . '<div class="highlight-box"><p><strong>Vehicle price:</strong> ' . formatRand($q['price']) . '</p><p><strong>Your deposit:</strong> ' . formatRand($q['deposit']) . '</p><p><strong>Amount financed:</strong> ' . formatRand($q['financed']) . '</p></div>'
        . '<h3 style="color:#005BA1;font-size:16px;">Your monthly options</h3><div class="highlight-box">' . $rows . '</div>'
        . ($review ? '<p>Our finance team will call you within one business day to talk through your options.</p>'
                   : '<p><strong>Next:</strong> choose your repayment term on your application page. Quote valid until ' . date('j F Y', strtotime($q['valid'])) . '.</p>');
    $ok = finMailClient($app, $review ? 'Your Finance Application — We Will Call You' : (!empty($q['counter']) ? 'Your Updated Finance Quote' : 'Your Finance Result & Quote'),
        $body, $review ? 'View My Application' : 'Choose My Term', $review ? 'result' : 'plans');
    finRecordMail($app, 'Quote ' . $q['number'], $ok);
    return $ok;
}

// ── Finance: office decisions ─────────────────────────────────

function finApproveDeposit(array &$app, string $by): string {
    if ($app['status'] !== 'chosen') return 'The client has not chosen a term yet.';
    $app['details']['invoice'] = ['number' => 'AHG-FIN-INV-' . date('Ym') . '-' . str_pad((string)$app['id'], 4, '0', STR_PAD_LEFT),
                                  'issued' => date('Y-m-d H:i:s'), 'due' => date('Y-m-d', strtotime('+' . FIN_INVOICE_DAYS . ' days')),
                                  'amount' => (float)$app['amount']];
    $app['status'] = 'invoiced';
    finLog($app, 'Deposit approved — invoice ' . $app['details']['invoice']['number'], $by);
    $plan = finChosenPlan($app);
    $ok = finMailClient($app, 'Deposit Approved — Submit Your Documents',
        '<p>Good news — your deposit of <strong>' . formatRand((float)$app['amount']) . '</strong> is approved'
        . ($plan ? ' on the <strong>' . $plan['months'] . '-month</strong> term at <strong>' . formatRand($plan['monthly']) . ' per month</strong>' : '') . '.</p>'
        . '<h3 style="color:#005BA1;font-size:16px;">What happens next?</h3><ol style="margin:0 0 12px 20px;padding:0;font-size:14px;line-height:2;">'
        . '<li>Upload your SA ID, proof of address, 3 months\' bank statements, payslip and driver\'s licence</li>'
        . '<li>Pay your deposit using reference <strong>' . e(finPayRef($app)) . '</strong> and upload your proof of payment</li>'
        . '<li>Once verified, you receive your <strong>Buyer Code</strong> and book your test drive</li></ol>'
        . '<p><a href="' . e(finDocLink($app, 'invoice')) . '">View your deposit invoice</a> — please pay within ' . FIN_INVOICE_DAYS . ' days.</p>',
        'Upload My Documents', 'documents');
    finRecordMail($app, 'Deposit invoice', $ok);
    return '';
}

function finCounterDeposit(array &$app, float $deposit, string $note, string $by): string {
    $vehicle = finVehicle((int)$app['auction_id']) ?: [];
    if ($deposit <= (float)$app['amount']) return 'The suggested deposit should be higher than the client\'s ' . formatRand((float)$app['amount']) . '.';
    if ($deposit >= (float)$app['price']) return 'That covers the whole price — there would be nothing to finance.';
    if ($vehicle && $deposit < finMinDeposit($vehicle)) return 'That is below the minimum deposit for this vehicle.';
    if (!empty($app['details']['assessment'])) {
        finQuoteFromAssessment($app, $deposit, true, $note);
        if ($app['status'] === 'review') $app['status'] = 'quoted';   // the office decided: let the client choose
    } else {
        $app['details']['quotes_old'][] = $app['details']['quote'];
        finIssueQuote($app, $deposit, true, $note);
        $app['status'] = 'quoted';
    }
    finLog($app, 'Suggested a deposit of ' . formatRand($deposit), $by);
    finSendQuote($app);
    return '';
}

/** Polite decline (finance, or a pre-auction request before any invoice). */
function finDecline(array &$app, string $reason, string $by): void {
    $app['status'] = 'declined';
    $app['details']['declined'] = ['at' => date('Y-m-d H:i:s'), 'reason' => $reason];
    finLog($app, 'Declined' . ($reason !== '' ? ': ' . $reason : ''), $by);
    $isFin = $app['kind'] === 'finance';
    $ok = finMailClient($app, $isFin ? 'Your Finance Application' : 'Your Pre-Auction Purchase Request',
        '<p>Thank you for your trust in Auction Hub Group. We are sorry to let you know that we are <strong>not able to proceed</strong> with your '
        . ($isFin ? 'finance application' : 'pre-auction request') . ' on this vehicle.</p>'
        . ($reason !== '' ? '<div class="highlight-box"><p>' . nl2br(e($reason)) . '</p></div>' : '')
        . '<p>This is not a reflection of your creditworthiness or standing. Our inventory updates regularly and new bank-stock vehicles arrive every week.</p>'
        . '<a href="' . APP_URL . '/preauction.php" class="cta-btn">Browse Available Vehicles</a>',
        'View my application');
    finRecordMail($app, 'Decline', $ok);
}

function finApprove(array &$app, string $by): string {
    if ($app['status'] !== 'invoiced') return 'Only an application with a deposit invoice can be approved.';
    $code = finIssueBuyerCode($app);
    $app['status'] = 'approved';
    $app['details']['approved_at'] = date('Y-m-d H:i:s');
    $app['details']['docs']['payment']['status'] = 'accepted';
    finLog($app, 'Deposit verified — approved, Buyer Code ' . $code, $by);
    $ok = finMailClient($app, '✅ Finance Approved — Your Buyer Code is ' . $code,
        '<h2 class="green">🎉 Congratulations — Your Finance is Approved!</h2>'
        . '<p>Your deposit has been received and verified. The vehicle is now reserved in your name.</p>'
        . '<div class="highlight-box" style="text-align:center;padding:20px;"><p style="margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#666;">Your Buyer Code</p>'
        . '<p style="margin:0;font-size:24px;font-weight:900;color:#005BA1;letter-spacing:.1em;">' . e($code) . '</p></div>'
        . '<h3 style="color:#005BA1;font-size:16px;">What happens next?</h3><ol style="margin:0 0 12px 20px;padding:0;font-size:14px;line-height:2;">'
        . '<li>Book your test drive on your application page</li><li>Present your <strong>Buyer Code</strong> at ' . e(COMPANY_ADDRESS) . '</li>'
        . '<li>Bring your ID and driver\'s licence</li><li>Sign your finance agreement and drive away</li></ol>',
        'Book My Test Drive', 'viewing');
    finRecordMail($app, 'Approval + Buyer Code', $ok);
    return '';
}

function finConfirmViewing(array &$app, string $date, string $time, string $note, string $by): string {
    if ($err = finViewingError($date, $time)) return $err;
    $app['details']['viewing'] = ['date' => $date, 'time' => $time, 'status' => 'confirmed', 'note' => $note, 'at' => date('Y-m-d H:i:s')];
    finLog($app, 'Test drive confirmed for ' . finViewingLabel($app['details']['viewing']), $by);
    $ok = finMailClient($app, 'Test Drive Confirmed — ' . date('D j M', strtotime($date)) . ' at ' . $time,
        '<p>Your test drive is confirmed for <strong>' . e(finViewingLabel($app['details']['viewing'])) . '</strong> at ' . e(COMPANY_ADDRESS) . '.</p>'
        . ($note !== '' ? '<div class="highlight-box"><p>' . nl2br(e($note)) . '</p></div>' : '')
        . '<p>Present your <strong>Buyer Code (' . e((string)$app['buyer_code']) . ')</strong> on arrival and bring your ID and driver\'s licence. You have <strong>1 hour 30 minutes</strong> — you are welcome to bring your own mechanic.</p>',
        'View my booking', 'viewing');
    finRecordMail($app, 'Test drive confirmed', $ok);
    return '';
}

// ── Small things ──────────────────────────────────────────────

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

function finWhatsAppUrl(array $app): string {
    $msg = 'Hi Auction Hub Group, this is ' . $app['name'] . ' — ' . ($app['kind'] === 'finance' ? 'Finance' : 'Pre-Auction') . ' ' . finRef($app) . ' for the ' . $app['vehicle'] . '.';
    return 'https://wa.me/' . COMPANY_WHATSAPP_INTL . '?text=' . rawurlencode($msg);
}

/** The office's WhatsApp to the client with their next step. */
function finAdminWhatsAppUrl(array $app): string {
    $digits = preg_replace('/\D/', '', $app['phone']);
    if (str_starts_with($digits, '0')) $digits = '27' . substr($digits, 1);
    if (strlen($digits) < 9) return '';
    $first = explode(' ', trim($app['name']))[0];
    $next = match ($app['status']) {
        'quoted'       => 'your finance quote is ready — please choose your repayment term',
        'chosen'       => 'we are reviewing your deposit now',
        'invoiced'     => $app['kind'] === 'finance' ? 'your deposit is approved — please upload your documents and proof of payment' : 'your proforma invoice is ready — please pay the commitment fee within 24 hours and upload proof of payment',
        'pop_uploaded' => 'we have your proof of payment and are verifying it',
        'approved'     => 'you are approved! Your Buyer Code is ' . $app['buyer_code'] . ' — please book your test drive',
        'paid'         => 'your vehicle is Presold under your name. Your Buyer Code is ' . $app['buyer_code'],
        default        => 'here is an update on your application',
    };
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode("Hi $first, Auction Hub Group here about the {$app['vehicle']} (" . finRef($app) . "): $next.\n" . finLink($app));
}

function finForEmail(string $email): array {
    global $pdo;
    if ($email === '' || !ahgSchemaReady()) return [];
    $st = $pdo->prepare("SELECT * FROM client_applications WHERE email = ? ORDER BY id DESC LIMIT 50");
    $st->execute([$email]);
    return array_map(fn($r) => paSync(finDecode($r)), $st->fetchAll());
}

// ── Page helpers ──────────────────────────────────────────────

/** Vehicle <select> of open lots (data-* used by the page scripts). */
function finVehicleSelect(array $lots, ?array $selected, string $name = 'auction_id', string $id = 'ap-vehicle', bool $required = true): string {
    $h = '<select class="form-control" id="' . $id . '" name="' . $name . '"' . ($required ? ' required' : '') . '><option value="">' . ($required ? 'Choose a vehicle' : 'None') . '</option>';
    foreach ($lots as $lot) {
        $price = finPrice($lot);
        $h .= '<option value="' . (int)$lot['id'] . '" data-price="' . (int)round($price) . '" data-min="' . (int)finMinDeposit($lot) . '" data-max-term="' . max(array_keys(finTiers($lot))) . '"'
            . ($selected && (int)$selected['id'] === (int)$lot['id'] ? ' selected' : '') . '>'
            . e(finVehicleTitle($lot)) . ' — ' . e(formatRand($price)) . '</option>';
    }
    return $h . '</select>';
}

/** Flash messages in AHG's .alert style. */
function finFlashHtml(): string {
    $out = '';
    $icons = ['success' => 'fa-circle-check', 'error' => 'fa-circle-xmark', 'info' => 'fa-circle-info'];
    foreach ($_SESSION['fin_flash'] ?? [] as [$type, $msg]) {
        $out .= '<div class="alert alert-' . e($type) . '" role="alert"><i class="fas ' . ($icons[$type] ?? 'fa-circle-info') . '"></i><span>' . $msg . '</span></div>';
    }
    unset($_SESSION['fin_flash']);
    return $out;
}

function finFlash(string $type, string $htmlMessage): void {
    $_SESSION['fin_flash'][] = [$type, $htmlMessage];
}
