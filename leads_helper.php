<?php
// ============================================================
// AUCTION HUB GROUP — leads_helper.php
// Admin → Leads: everyone who got in touch, one row per person.
//
// Sources: website enquiries (Contact page + "Ask about this vehicle"),
// new sign-ups, banking-detail requests, saved searches, newsletter
// sign-ups and leads the field workers bring in. The same person who
// signed up AND sent an enquiry is one row (matched on email, else the
// last 9 digits of the phone number), shown under their most useful lead.
//
// Each person moves New → Contacted → Called → Won / Lost. Ready-made
// emails and WhatsApp messages (editable in Admin → Templates) and a short
// call script per kind of lead sit next to every person.
//
// Ideas ported from Easy Auctions (one row per person, email → call
// progress, call scripts, WhatsApp templates) and BidCo (Today strip,
// buyer database). Requires: config.php, db_schema.php
// ============================================================

const LEAD_TYPES = [
    'enquiry'    => ['Enquiry',          'fa-comment-dots'],
    'bank'       => ['Asked for banking', 'fa-university'],
    'alert'      => ['Saved search',     'fa-bell'],
    'signup'     => ['New sign-up',      'fa-user-plus'],
    'worker'     => ['Field lead',       'fa-id-badge'],
    'newsletter' => ['Newsletter',       'fa-envelope-open-text'],
];

/** When one person is several leads, the most specific one decides the type, the script and the message. */
const LEAD_PRIORITY = ['enquiry', 'bank', 'alert', 'signup', 'worker', 'newsletter'];

const LEAD_STAGES = [
    'new'       => 'New',
    'contacted' => 'Contacted',
    'called'    => 'Called',
    'won'       => 'Won',
    'lost'      => 'Lost',
];

/** Call outcomes and the stage each one moves the person to. */
const LEAD_OUTCOMES = [
    'interested'     => ['Interested — follow up', 'called'],
    'call_back'      => ['Call back later',        'called'],
    'no_answer'      => ['No answer / voicemail',  'called'],
    'registered'     => ['Registered / paid',      'won'],
    'bought'         => ['Bought a vehicle',       'won'],
    'not_interested' => ['Not interested',         'lost'],
    'wrong_number'   => ['Wrong number',           'lost'],
];

const ENQUIRY_TYPES = [
    'general'      => 'General question',
    'vehicle'      => 'A specific vehicle',
    'registration' => 'Registering to bid',
    'finance'      => 'Finance',
    'preauction'   => 'Pre-auction reservation',
    'sell'         => 'Selling a vehicle / fleet',
];

// ── Small helpers ─────────────────────────────────────────────

/** The last 9 digits, so 083 670 9292 and +27 83 670 9292 match. */
function leadPhoneKey(?string $phone): ?string {
    $d = preg_replace('/\D+/', '', (string)$phone);
    return strlen($d) >= 9 ? substr($d, -9) : null;
}

/** International digits for wa.me (South African numbers), '' when unusable. */
function leadWaNumber(?string $phone): string {
    $d = preg_replace('/\D+/', '', (string)$phone);
    if ($d === '') return '';
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (strlen($d) === 10 && $d[0] === '0') $d = '27' . substr($d, 1);
    if (strlen($d) === 9) $d = '27' . $d;
    return strlen($d) >= 11 && strlen($d) <= 15 ? $d : '';
}

function leadCleanEmail(?string $e): ?string {
    $e = strtolower(trim((string)$e));
    return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !str_ends_with($e, '.invalid') ? $e : null;
}

function leadKey(?string $email, ?string $phone, string $fallback): string {
    if ($e = leadCleanEmail($email)) return 'e:' . $e;
    if ($p = leadPhoneKey($phone))   return 'p:' . $p;
    return 'x:' . $fallback;
}

function leadSetting(string $key, string $default = ''): string {
    global $pdo;
    static $all = null;
    if ($all === null) {
        $all = [];
        try {
            foreach ($pdo->query("SELECT setting_key, setting_value FROM site_settings") as $r) $all[$r['setting_key']] = (string)$r['setting_value'];
        } catch (Throwable $e) { /* table missing: defaults */ }
    }
    return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
}

function leadSaveSetting(string $key, ?string $value): void {
    global $pdo;
    if ($value === null) {
        $pdo->prepare("DELETE FROM site_settings WHERE setting_key = ?")->execute([$key]);
        return;
    }
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value]);
}

/** {agent}: the name set in Admin → Templates, else the logged-in staff member's first name. */
function leadAgentName(): string {
    $set = leadSetting('lead_agent_name');
    if ($set !== '') return $set;
    $me = trim(explode(' ', trim((string)($GLOBALS['currentUser']['full_name'] ?? $GLOBALS['admin']['full_name'] ?? '')))[0] ?? '');
    return $me !== '' ? $me : 'your consultant';
}

function leadTimeAgo(string $at): string {
    $s = time() - strtotime($at);
    if ($s < 90)     return 'just now';
    if ($s < 3600)   return floor($s / 60) . ' min ago';
    if ($s < 86400)  return floor($s / 3600) . ' h ago';
    if ($s < 172800) return 'yesterday';
    if ($s < 30 * 86400) return floor($s / 86400) . ' days ago';
    return date('d M Y', strtotime($at));
}

/** "2021 BMW 1 Series" — adds the year only when the lot name does not already start with it. */
function leadVehicleTitle($year, ?string $name): string {
    $name = trim((string)$name);
    $year = trim((string)$year);
    return $year !== '' && !str_starts_with($name, $year) ? trim($year . ' ' . $name) : $name;
}

// ── Lead sources ─────────────────────────────────────────────

/** Raw entries from every source since $since (Y-m-d H:i:s). Each source fails quietly if its table is missing. */
function leadEntries(string $since): array {
    global $pdo;
    $out = [];
    $run = function (string $sql, callable $map) use ($pdo, $since, &$out) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$since]);
            foreach ($st->fetchAll() as $r) $out[] = $map($r);
        } catch (Throwable $e) {
            error_log('[AHG leads] ' . $e->getMessage());
        }
    };
    $statusLabel = [
        'verified_bidder'  => 'Verified bidder',
        'pending_approval' => 'POP waiting for approval',
        'pending_payment'  => 'Registered, deposit not paid',
        'registered'       => 'Registered, deposit not paid',
    ];

    $run("SELECT e.*, a.item_name, a.year AS a_year FROM enquiries e LEFT JOIN auctions a ON a.id = e.auction_id
          WHERE e.created_at >= ? ORDER BY e.id DESC LIMIT 1000",
        fn($r) => [
            'type' => 'enquiry', 'id' => (int)$r['id'], 'at' => $r['created_at'], 'name' => $r['name'],
            'email' => $r['email'], 'phone' => $r['phone'],
            'detail' => (ENQUIRY_TYPES[$r['type']] ?? 'Enquiry') . ($r['item_name'] ? ': ' . leadVehicleTitle($r['a_year'], $r['item_name']) : '')
                      . ($r['message'] ? ' — “' . mb_strimwidth(trim($r['message']), 0, 140, '…') . '”' : ''),
            'vehicle' => $r['item_name'] ? leadVehicleTitle($r['a_year'], $r['item_name']) : '',
            'auction_id' => $r['auction_id'] ? (int)$r['auction_id'] : null,
            'enquiry_type' => $r['type'],
            'is_new' => $r['status'] === 'new',
        ]);

    $run("SELECT b.id, b.created_at, b.status, u.id AS uid, u.full_name, u.email, u.phone, u.bidder_status
          FROM bank_detail_requests b JOIN users u ON u.id = b.user_id WHERE b.created_at >= ? ORDER BY b.id DESC LIMIT 1000",
        fn($r) => [
            'type' => 'bank', 'id' => (int)$r['id'], 'at' => $r['created_at'], 'name' => $r['full_name'],
            'email' => $r['email'], 'phone' => $r['phone'], 'user_id' => (int)$r['uid'],
            'detail' => 'Asked for the banking details to pay the deposit (' . ($r['status'] ?: 'pending') . ')',
            'bidder_status' => $r['bidder_status'],
        ]);

    $run("SELECT s.id, s.label, s.created_at, u.id AS uid, u.full_name, u.email, u.phone, u.bidder_status
          FROM saved_searches s JOIN users u ON u.id = s.user_id WHERE s.created_at >= ? ORDER BY s.id DESC LIMIT 1000",
        fn($r) => [
            'type' => 'alert', 'id' => (int)$r['id'], 'at' => $r['created_at'], 'name' => $r['full_name'],
            'email' => $r['email'], 'phone' => $r['phone'], 'user_id' => (int)$r['uid'],
            'detail' => 'Saved a search: ' . $r['label'], 'criteria' => $r['label'],
            'bidder_status' => $r['bidder_status'],
        ]);

    $run("SELECT id, full_name, email, phone, bidder_status, bidder_number, created_at, acq_source FROM users
          WHERE role = 'user' AND COALESCE(is_floor_bidder, 0) = 0 AND created_at >= ? ORDER BY id DESC LIMIT 1000",
        fn($r) => [
            'type' => 'signup', 'id' => (int)$r['id'], 'at' => $r['created_at'], 'name' => $r['full_name'],
            'email' => $r['email'], 'phone' => $r['phone'], 'user_id' => (int)$r['id'],
            'detail' => 'Signed up · ' . ($statusLabel[$r['bidder_status']] ?? ucfirst(str_replace('_', ' ', (string)$r['bidder_status'])))
                      . ($r['bidder_number'] ? ' · ' . $r['bidder_number'] : '')
                      . ($r['acq_source'] ? ' · via ' . $r['acq_source'] : ''),
            'bidder_status' => $r['bidder_status'],
        ]);

    $run("SELECT l.id, l.lead_name, l.lead_phone, l.submitted_at, w.full_name AS worker FROM worker_leads l
          LEFT JOIN workers w ON w.id = l.worker_id WHERE l.submitted_at >= ? ORDER BY l.id DESC LIMIT 1000",
        fn($r) => [
            'type' => 'worker', 'id' => (int)$r['id'], 'at' => $r['submitted_at'], 'name' => $r['lead_name'],
            'email' => null, 'phone' => $r['lead_phone'],
            'detail' => 'Brought in by ' . ($r['worker'] ?: 'a field worker'),
        ]);

    $run("SELECT id, email, name, subscribed_at FROM newsletter_subscribers WHERE is_active = 1 AND subscribed_at >= ? ORDER BY id DESC LIMIT 1000",
        fn($r) => [
            'type' => 'newsletter', 'id' => (int)$r['id'], 'at' => $r['subscribed_at'], 'name' => $r['name'],
            'email' => $r['email'], 'phone' => null, 'detail' => 'Joined the newsletter',
        ]);

    return $out;
}

/**
 * Everyone who got in touch in the last $days days, one row per person, newest activity first.
 * Each row: key, type, at, name, email, phone, wa, detail, also[], stage, outcome, note,
 * call_back_at, emailed_at, called_at, calls, vehicle, criteria, user_id, registered.
 */
function leadPeople(int $days = 30): array {
    global $pdo;
    $since = date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' days'));
    $entries = leadEntries($since);

    // Union-find style grouping: an entry joins a person by email OR phone.
    $groups = []; $index = [];
    foreach ($entries as $i => $e) {
        $ids = array_filter(['e:' . leadCleanEmail($e['email']), 'p:' . leadPhoneKey($e['phone'])], fn($k) => strlen($k) > 2);
        $g = null;
        foreach ($ids as $k) if (isset($index[$k])) { $g = $index[$k]; break; }
        if ($g === null) { $g = count($groups); $groups[$g] = []; }
        $groups[$g][] = $e;
        foreach ($ids as $k) $index[$k] = $g;
    }

    // Saved progress, read once.
    $status = [];
    try {
        foreach ($pdo->query("SELECT * FROM lead_status") as $r) $status[$r['lead_key']] = $r;
    } catch (Throwable $e) { /* table created on first schema check */ }

    $people = [];
    foreach ($groups as $g => $items) {
        usort($items, fn($a, $b) => array_search($a['type'], LEAD_PRIORITY) <=> array_search($b['type'], LEAD_PRIORITY) ?: strcmp($b['at'], $a['at']));
        $main  = $items[0];
        $email = null; $phone = null; $name = ''; $vehicle = ''; $criteria = ''; $userId = null; $bs = null; $auctionId = null; $isNew = false;
        foreach ($items as $e) {
            $email    = $email    ?: leadCleanEmail($e['email']);
            $phone    = $phone    ?: (leadPhoneKey($e['phone']) ? trim((string)$e['phone']) : null);
            if (mb_strlen(trim((string)$e['name'])) > mb_strlen($name)) $name = trim((string)$e['name']);
            $vehicle  = $vehicle  ?: ($e['vehicle'] ?? '');
            $auctionId = $auctionId ?: ($e['auction_id'] ?? null);
            $criteria = $criteria ?: ($e['criteria'] ?? '');
            $userId   = $userId   ?: ($e['user_id'] ?? null);
            $bs       = $bs       ?: ($e['bidder_status'] ?? null);
            $isNew    = $isNew || !empty($e['is_new']);
        }
        $key = leadKey($email, $phone, $main['type'] . ':' . $main['id']);
        // Progress may have been saved under the phone key before an email was known.
        $s = $status[$key] ?? ($phone ? ($status['p:' . leadPhoneKey($phone)] ?? null) : null) ?? [];

        $also = [];
        foreach (array_slice($items, 1) as $e) {
            $k = $e['type'] . '|' . $e['detail'];
            if (!isset($also[$k])) $also[$k] = ['type' => $e['type'], 'detail' => $e['detail'], 'at' => $e['at'], 'times' => 0];
            $also[$k]['times']++;
        }

        $stage = $s['stage'] ?? 'new';
        // A verified bidder who never had a status is already a win.
        if (!$s && $bs === 'verified_bidder') $stage = 'won';

        $people[] = [
            'key'          => $key,
            'type'         => $main['type'],
            'at'           => max(array_column($items, 'at')),
            'first_at'     => min(array_column($items, 'at')),
            'name'         => $name !== '' ? $name : 'Unknown',
            'email'        => $email,
            'phone'        => $phone,
            'wa'           => leadWaNumber($phone),
            'detail'       => $main['detail'],
            'also'         => array_values($also),
            'vehicle'      => $vehicle,
            'auction_id'   => $auctionId,
            'criteria'     => $criteria,
            'user_id'      => $userId,
            'bidder_status'=> $bs,
            'is_new'       => $isNew,
            'stage'        => $stage,
            'outcome'      => $s['outcome'] ?? null,
            'note'         => $s['note'] ?? '',
            'call_back_at' => $s['call_back_at'] ?? null,
            'emailed_at'   => $s['emailed_at'] ?? null,
            'called_at'    => $s['called_at'] ?? null,
            'calls'        => (int)($s['calls'] ?? 0),
        ];
    }
    usort($people, fn($a, $b) => strcmp($b['at'], $a['at']));
    return $people;
}

/** People still waiting for a first contact in the last 7 days + call-backs due. For the menu badge; cached 60 s. */
function leadWaitingCount(): int {
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['ahg_lead_badge']) && $_SESSION['ahg_lead_badge']['at'] > time() - 60) {
        return (int)$_SESSION['ahg_lead_badge']['n'];
    }
    $n = 0;
    try {
        foreach (leadPeople(7) as $p) {
            if ($p['stage'] === 'new' || ($p['call_back_at'] && $p['call_back_at'] <= date('Y-m-d') && !in_array($p['stage'], ['won', 'lost'], true))) $n++;
        }
    } catch (Throwable $e) { $n = 0; }
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['ahg_lead_badge'] = ['at' => time(), 'n' => $n];
    return $n;
}

/** Writes progress for one person. $fields: stage, outcome, note, call_back_at, emailed (bool), called (bool). */
function leadSaveStatus(string $key, array $fields, string $by): void {
    global $pdo;
    $cur = $pdo->prepare("SELECT * FROM lead_status WHERE lead_key = ?");
    $cur->execute([$key]);
    $row = $cur->fetch() ?: ['stage' => 'new', 'outcome' => null, 'note' => null, 'call_back_at' => null, 'emailed_at' => null, 'called_at' => null, 'calls' => 0];

    if (isset($fields['stage']) && isset(LEAD_STAGES[$fields['stage']])) $row['stage'] = $fields['stage'];
    if (array_key_exists('outcome', $fields)) {
        $o = $fields['outcome'];
        $row['outcome'] = isset(LEAD_OUTCOMES[$o]) ? $o : null;
        if ($row['outcome'] && !isset($fields['stage'])) $row['stage'] = LEAD_OUTCOMES[$o][1];
    }
    if (array_key_exists('note', $fields)) $row['note'] = mb_substr(trim((string)$fields['note']), 0, 5000) ?: null;
    if (array_key_exists('call_back_at', $fields)) {
        $d = (string)$fields['call_back_at'];
        $row['call_back_at'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }
    if (!empty($fields['emailed'])) {
        $row['emailed_at'] = date('Y-m-d H:i:s');
        if ($row['stage'] === 'new') $row['stage'] = 'contacted';
    }
    if (!empty($fields['called'])) {
        $row['called_at'] = date('Y-m-d H:i:s');
        $row['calls'] = (int)$row['calls'] + 1;
    }

    $pdo->prepare("INSERT INTO lead_status (lead_key, stage, outcome, note, call_back_at, emailed_at, called_at, calls, updated_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE stage = VALUES(stage), outcome = VALUES(outcome), note = VALUES(note),
                     call_back_at = VALUES(call_back_at), emailed_at = VALUES(emailed_at), called_at = VALUES(called_at),
                     calls = VALUES(calls), updated_by = VALUES(updated_by)")
        ->execute([$key, $row['stage'], $row['outcome'], $row['note'], $row['call_back_at'], $row['emailed_at'], $row['called_at'], $row['calls'], mb_substr($by, 0, 150)]);
    unset($_SESSION['ahg_lead_badge']);
}

function leadLogMessage(string $key, string $channel, ?string $template, ?string $to, ?string $subject, bool $ok, string $by): void {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO lead_messages (lead_key, channel, template, recipient, subject, ok, sent_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$key, $channel, $template, $to, $subject ? mb_substr($subject, 0, 255) : null, $ok ? 1 : 0, mb_substr($by, 0, 150)]);
    } catch (Throwable $e) { error_log('[AHG leads] log: ' . $e->getMessage()); }
}

/** The last few messages per lead key, for the history line on each card. */
function leadMessageHistory(array $keys): array {
    global $pdo;
    if (!$keys) return [];
    $out = [];
    try {
        $in = implode(',', array_fill(0, count($keys), '?'));
        $st = $pdo->prepare("SELECT lead_key, channel, template, subject, ok, sent_by, created_at FROM lead_messages WHERE lead_key IN ($in) ORDER BY id DESC LIMIT 2000");
        $st->execute(array_values($keys));
        foreach ($st->fetchAll() as $r) {
            if (count($out[$r['lead_key']] ?? []) < 5) $out[$r['lead_key']][] = $r;
        }
    } catch (Throwable $e) { /* none yet */ }
    return $out;
}

// ── Templates ────────────────────────────────────────────────

const LEAD_PLACEHOLDERS = [
    '{name}'         => 'First name',
    '{vehicle}'      => 'Vehicle they asked about',
    '{vehicle_url}'  => 'Link to that vehicle (or the live auctions)',
    '{criteria}'     => 'What their saved search is for',
    '{deposit}'      => 'Registration deposit (e.g. R10,000)',
    '{bank_details}' => 'Banking details (a table in emails, lines on WhatsApp)',
    '{reference}'    => 'Their EFT reference (registered users)',
    '{agent}'        => 'Your name (Templates → Agent name)',
    '{phone}'        => 'Office phone',
    '{whatsapp}'     => 'Office WhatsApp',
    '{email}'        => 'Office email',
    '{register_url}' => 'Register-to-bid page',
    '{auctions_url}' => 'Live auctions page',
    '{finance_url}'  => 'Finance page',
    '{preauction_url}' => 'Pre-auction reservations page',
];

/** key => [label, subject, body] — the wording used until the office saves its own. */
function leadEmailDefaults(): array {
    $sign = "\n\nKind regards,\n{agent}\nAuction Hub Group · {phone} · WhatsApp {whatsapp}";
    return [
        'enquiry_reply' => ['Vehicle enquiry reply', 'Your enquiry about the {vehicle} — Auction Hub Group',
            "Hi {name},\n\nThank you for your interest in the {vehicle}. You can see the photos, bids and closing time here:\n{vehicle_url}\n\n"
            . "To bid on it you need an Auction Hub Group bidder number:\n\n"
            . "1. Register on {register_url}\n2. Pay the refundable {deposit} registration deposit\n3. Upload your proof of payment and we issue your bidder number, usually the same day\n\n"
            . "You are welcome to view the vehicle before you bid. Reply to this email or WhatsApp us and we will arrange it." . $sign],
        'register_steps' => ['How to register to bid', 'How to register to bid at Auction Hub Group',
            "Hi {name},\n\nWelcome to Auction Hub Group — bank-repossessed and fleet vehicles on auction every week.\n\nRegistering to bid takes a few minutes:\n\n"
            . "1. Create your account on {register_url}\n2. Pay the refundable {deposit} registration deposit by EFT\n3. Upload your proof of payment in your dashboard\n4. We issue your bidder number, usually the same working day\n\n"
            . "The deposit is refunded in full if you don't buy, or counts toward the vehicle you win.\n\nSee what is on auction now: {auctions_url}" . $sign],
        'deposit_banking' => ['Banking details for the deposit', 'Banking details for your registration deposit — Auction Hub Group',
            "Hi {name},\n\nAs requested, here are our banking details for the refundable {deposit} registration deposit:\n\n{bank_details}\n\n"
            . "Please use your reference exactly as shown so we can match your payment, then upload the proof of payment in your dashboard.\n\n"
            . "Important: we will never ask you to pay into a different account by email or WhatsApp. If anyone does, call us on {phone} first." . $sign],
        'follow_up' => ['Any questions? (follow-up)', 'Can we help? — Auction Hub Group',
            "Hi {name},\n\nWe noticed you visited Auction Hub Group and wanted to check whether we can help — a vehicle you liked, how bidding works, finance or the registration deposit.\n\n"
            . "See what is on auction now: {auctions_url}\n\nJust reply to this email, call {phone} or WhatsApp {whatsapp} and a consultant will help you." . $sign],
        'details_request' => ['Ask for a phone number', 'Can we give you a quick call? — Auction Hub Group',
            "Hi {name},\n\nThank you for your interest in Auction Hub Group. We'd like to call you about the vehicles you are looking for, but we don't have your number yet.\n\n"
            . "Please reply with:\n\n1. Your full name\n2. Your cell phone number\n3. The best time to call you\n\nYou can also call us on {phone} or WhatsApp {whatsapp}." . $sign],
        'saved_search' => ['Saved search matches', 'Vehicles that match your search — Auction Hub Group',
            "Hi {name},\n\nYou asked us to look out for: {criteria}.\n\nWe have vehicles on auction that match — see them here:\n{auctions_url}\n\n"
            . "If one catches your eye, reply to this email and we'll send you the condition details or arrange a viewing." . $sign],
        'preauction' => ['Pre-auction reservation offer', 'Reserve a vehicle before the auction — Auction Hub Group',
            "Hi {name},\n\nDid you know you can reserve a vehicle at a fixed price before it goes to the live auction? Nobody else can bid on it once it is reserved.\n\n"
            . "How it works: {preauction_url}\n\nYou need your bidder number ({deposit} refundable deposit) in place first. Reply to this email and we'll help you choose a vehicle." . $sign],
        'finance' => ['Finance information', 'Financing your auction vehicle — Auction Hub Group',
            "Hi {name},\n\nThank you for asking about finance. You can read how it works and get an estimate here:\n{finance_url}\n\n"
            . "Send us the vehicle you are interested in and a consultant will take you through the options and the documents needed." . $sign],
        'blank' => ['Blank (write your own)', '', "Hi {name},\n\n" . $sign],
    ];
}

/** key => [label, text] for WhatsApp. *bold* and _italic_ the way WhatsApp shows them. */
function leadWaDefaults(): array {
    $sign = "\n\nKind regards,\n{agent}\n*Auction Hub Group* · {phone}";
    return [
        'enquiry' => ['Vehicle enquiry',
            "Good day {name},\n\nThis is {agent} from *Auction Hub Group*. Thank you for your enquiry about the *{vehicle}*.\n{vehicle_url}\n\nWould you like more photos, a viewing, or help registering to bid?" . $sign],
        'signup' => ['New sign-up (bidder number)',
            "Good day {name},\n\nThis is {agent} from *Auction Hub Group*. Thank you for registering with us.\n\nTo bid you need a bidder number:\n1. Pay the refundable *{deposit}* registration deposit\n2. Upload the proof of payment in your dashboard\n3. We activate your bidder number, usually the same day\n\nWhich vehicle are you looking for? I'll send you the best matches." . $sign],
        'bank' => ['Banking details',
            "Good day {name},\n\nAs requested, here are our banking details for the *{deposit}* refundable registration deposit:\n\n{bank_details}\n\nPlease send the proof of payment here once paid.\n\n_We will never ask you to pay into a different account. If anyone does, call {phone} first._" . $sign],
        'alert' => ['Saved search',
            "Good day {name},\n\nThis is {agent} from *Auction Hub Group*. You asked us to look out for: *{criteria}*.\n\nWe have vehicles on auction that match:\n{auctions_url}\n\nShall I send you the details of the best one?" . $sign],
        'follow_up' => ['Follow-up',
            "Good day {name},\n\nThis is {agent} from *Auction Hub Group*, following up on your interest. How can I help — a specific vehicle, how bidding works, or finance?" . $sign],
        'broadcast' => ['This week\'s auctions (broadcast)',
            "Good day,\n\nNew bank-repossessed and fleet vehicles are on auction at *Auction Hub Group* this week.\n\nSee them here: {auctions_url}\n\nReply to this message and a consultant will help you.\n\n*Auction Hub Group* · {phone}\n_Reply STOP and we will not message you again._"],
    ];
}

/** Which WhatsApp template / email each kind of lead starts with. */
const LEAD_WA_FOR_TYPE    = ['enquiry' => 'enquiry', 'bank' => 'bank', 'alert' => 'alert', 'signup' => 'signup', 'worker' => 'follow_up', 'newsletter' => 'follow_up'];
const LEAD_EMAIL_FOR_TYPE = ['enquiry' => 'enquiry_reply', 'bank' => 'deposit_banking', 'alert' => 'saved_search', 'signup' => 'register_steps', 'worker' => 'follow_up', 'newsletter' => 'follow_up'];

/** All templates with the office's own wording where it was saved. channel: email | wa */
function leadTemplates(string $channel): array {
    $out = [];
    if ($channel === 'email') {
        foreach (leadEmailDefaults() as $k => [$label, $subject, $body]) {
            $sb = leadSetting('tpl_email_' . $k . '_body');
            $out[$k] = ['label' => $label,
                        'subject' => leadSetting('tpl_email_' . $k . '_subject', $subject),
                        'body' => $sb !== '' ? $sb : $body,
                        'edited' => $sb !== '' || leadSetting('tpl_email_' . $k . '_subject') !== ''];
        }
    } else {
        foreach (leadWaDefaults() as $k => [$label, $text]) {
            $saved = leadSetting('tpl_wa_' . $k);
            $out[$k] = ['label' => $label, 'body' => $saved !== '' ? $saved : $text, 'edited' => $saved !== ''];
        }
    }
    return $out;
}

/** The placeholder values that are the same for everyone (bank details as WhatsApp lines). */
function leadCommonVars(): array {
    $bank = getBankSettings();
    return [
        '{deposit}'        => $bank['reg_fee_label'],
        '{agent}'          => leadAgentName(),
        '{phone}'          => COMPANY_PHONE,
        '{whatsapp}'       => defined('COMPANY_WHATSAPP') ? COMPANY_WHATSAPP : COMPANY_PHONE,
        '{email}'          => COMPANY_EMAIL,
        '{register_url}'   => APP_URL . '/register-to-bid.php',
        '{auctions_url}'   => APP_URL . '/auctions.php?status=active',
        '{finance_url}'    => APP_URL . '/financing.php',
        '{preauction_url}' => APP_URL . '/preauction.php',
        '{bank_details}'   => "*Bank:* {$bank['bank_name']}\n*Account name:* {$bank['bank_account_name']}\n*Account no:* {$bank['bank_account_no']}\n*Branch code:* {$bank['bank_branch_code']}\n*Type:* {$bank['bank_account_type']}",
    ];
}

/** Per-person placeholder values. */
function leadPersonVars(array $p): array {
    $first = trim(explode(' ', trim((string)($p['name'] ?? '')))[0] ?? '');
    return [
        '{name}'        => $first !== '' && strtolower($first) !== 'unknown' ? ucfirst($first) : 'there',
        '{vehicle}'     => ($p['vehicle'] ?? '') !== '' ? $p['vehicle'] : 'vehicle',
        '{vehicle_url}' => !empty($p['auction_id']) ? APP_URL . '/auction.php?id=' . (int)$p['auction_id'] : APP_URL . '/auctions.php?status=active',
        '{criteria}'    => ($p['criteria'] ?? '') !== '' ? $p['criteria'] : 'your vehicle',
        '{reference}'   => !empty($p['user_id']) ? eftReference((int)$p['user_id']) : 'your full name',
    ];
}

function leadFill(string $text, array $vars): string {
    return trim(preg_replace("/\n{3,}/", "\n\n", strtr($text, $vars)));
}

/** Plain text (with {bank_details} still in it) → letterhead HTML. URLs become links. */
function leadEmailHtml(string $subject, string $text, ?array $person = null): string {
    $bank = getBankSettings();
    $ref  = $person && !empty($person['user_id']) ? eftReference((int)$person['user_id']) : null;
    $box  = '<div class="highlight-box" style="background:#f0f6fc;border-left:4px solid #005BA1;padding:14px 18px;margin:16px 0;">'
          . '<p style="margin:6px 0"><strong>Bank:</strong> ' . e($bank['bank_name']) . '</p>'
          . '<p style="margin:6px 0"><strong>Account name:</strong> ' . e($bank['bank_account_name']) . '</p>'
          . '<p style="margin:6px 0"><strong>Account number:</strong> ' . e($bank['bank_account_no']) . '</p>'
          . '<p style="margin:6px 0"><strong>Branch code:</strong> ' . e($bank['bank_branch_code']) . '</p>'
          . '<p style="margin:6px 0"><strong>Account type:</strong> ' . e($bank['bank_account_type']) . '</p>'
          . ($ref ? '<p style="margin:10px 0 6px"><strong>Your reference:</strong> <span style="color:#f5a623;font-weight:bold;font-size:17px">' . e($ref) . '</span></p>' : '')
          . '<p style="margin:6px 0"><strong>Amount:</strong> ' . e($bank['reg_fee_label']) . '</p></div>';

    $html = '';
    foreach (preg_split("/\n{2,}/", trim($text)) as $para) {
        if (trim($para) === '{bank_details}') { $html .= $box; continue; }
        $p = e($para);
        $p = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#005BA1">$1</a>', $p);
        $p = str_replace('{bank_details}', '</p>' . $box . '<p>', nl2br($p));
        $html .= '<p style="margin:0 0 14px">' . $p . '</p>';
    }
    return _emailTemplate($subject, $html, true);
}

/** Sends one template email. Returns [ok, message]. */
function leadSendEmail(string $to, string $name, string $subject, string $text, ?array $person = null): array {
    if (!leadCleanEmail($to)) return [false, 'That email address does not look right.'];
    if (trim($subject) === '' || trim($text) === '') return [false, 'Subject and message are needed.'];
    try {
        $mail = _buildMailer();
        $mail->addAddress($to, $name);
        $mail->addReplyTo(COMPANY_EMAIL, 'Auction Hub Group');
        $mail->Subject = $subject;
        $mail->Body    = leadEmailHtml($subject, $text, $person);
        $mail->AltBody = str_replace('{bank_details}', strtr(leadCommonVars()['{bank_details}'], ['*' => '']), $text);
        $ok = _sendMail($mail, 'lead_template');
        if ($ok && !EMAIL_ENABLED) return [true, 'Email sending is switched off on this server — nothing was sent to ' . $to . '.'];
        return [$ok, $ok ? 'Email sent to ' . $to . '.' : 'The email could not be sent — check the mail log.'];
    } catch (Throwable $e) {
        error_log('[AHG leads] send: ' . $e->getMessage());
        return [false, 'The email could not be sent: ' . $e->getMessage()];
    }
}

// ── Call scripts ─────────────────────────────────────────────

/** What the agent says, per kind of lead. [Name], [Agent], [Vehicle], [Criteria], [Deposit] are filled in. */
function leadCallScripts(): array {
    $common = [
        ['Open', '"Hi [Name], this is [Agent] from Auction Hub Group. Am I speaking to the right person? Do you have a quick minute?"'],
    ];
    $close = [
        ['Next step', 'Agree ONE next step: send the banking details, send a vehicle link, book a viewing, or a call-back date. Log it with "Log call".'],
    ];
    return [
        'enquiry' => ['title' => 'Website enquiry', 'steps' => array_merge($common, [
            ['Why I\'m calling', '"You asked us about [Vehicle] on our website — I wanted to make sure you got everything you need."'],
            ['Find out', 'Are they buying for themselves or a business? Cash or finance? When do they need a vehicle?'],
            ['The offer', '"It\'s on auction now. With a bidder number you can bid online, or reserve it before the auction at a fixed price."'],
            ['What they need', 'Account on the website + the refundable [Deposit] deposit + proof of payment → bidder number, usually same day.'],
        ], $close)],
        'bank' => ['title' => 'Asked for banking details', 'steps' => array_merge($common, [
            ['Why I\'m calling', '"You asked for our banking details for the registration deposit — did they come through, and can I help with the payment?"'],
            ['Reassure', 'The [Deposit] deposit is refundable in full if they don\'t buy, and counts toward the vehicle if they win. Use their exact reference.'],
            ['Safety', '"We will never ask you to pay into a different account. If anyone does, phone us first."'],
            ['After payment', 'Upload the proof of payment in the dashboard → bidder number usually the same working day.'],
        ], $close)],
        'alert' => ['title' => 'Saved search', 'steps' => array_merge($common, [
            ['Why I\'m calling', '"You saved a search for [Criteria] — we have vehicles on auction that match. Can I send you the best ones on WhatsApp?"'],
            ['Find out', 'Budget, must-haves, cash or finance, timing.'],
            ['Bidder number', 'If they are not a verified bidder yet: explain the [Deposit] refundable deposit so they are ready before the lot closes.'],
        ], $close)],
        'signup' => ['title' => 'New sign-up', 'steps' => array_merge($common, [
            ['Why I\'m calling', '"Thanks for signing up with Auction Hub Group. I\'m calling to help you get your bidder number so you\'re ready to bid."'],
            ['What they need', 'The refundable [Deposit] deposit by EFT + upload proof of payment → bidder number, usually same day.'],
            ['Find out', 'What vehicle are they looking for? Send matching links on WhatsApp after the call.'],
            ['Pre-auction', 'Mention they can reserve a vehicle at a fixed price before the auction once registered.'],
        ], $close)],
        'worker' => ['title' => 'Field lead', 'steps' => array_merge($common, [
            ['Why I\'m calling', '"One of our team members said you\'re looking for a vehicle — I\'m calling to help you find one at auction."'],
            ['Find out', 'Type of vehicle, budget, cash or finance, timing.'],
            ['What they need', 'Account + refundable [Deposit] deposit → bidder number.'],
        ], $close)],
        'newsletter' => ['title' => 'Newsletter sign-up', 'steps' => array_merge($common, [
            ['Why I\'m calling', '"You joined our newsletter — I wanted to check if you\'re looking for a specific vehicle we can help with."'],
            ['If no phone number', 'Use the "Ask for a phone number" email template.'],
        ], $close)],
    ];
}

/** Quick answers to the questions people ask on every call. */
const LEAD_CALL_ANSWERS = [
    ['Is the deposit refundable?', 'Yes — refunded in full if they don\'t buy, or set off against the vehicle they win.'],
    ['How long for a bidder number?', 'Usually the same working day after the proof of payment is uploaded.'],
    ['Can I view the vehicle?', 'Yes, at the branch where it stands. Registered bidders bring their ID.'],
    ['Are there extra fees?', 'Buyer\'s fees and VAT are shown on each lot and on the invoice. Point them to the lot page.'],
    ['Do you offer finance?', 'Send them the finance page; a consultant explains the options and documents.'],
    ['Is this a scam?', 'Registered company ' . COMPANY_REG_NO . '. Only pay into the account on our website/emails — never one sent by someone else.'],
];

// ── Public enquiry form ──────────────────────────────────────

/** Simple per-IP limit: $max submissions per $window seconds. */
function enquiryRateLimited(int $max = 5, int $window = 600): bool {
    $dir = sys_get_temp_dir() . '/ahg_enquiry_rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . md5($_SERVER['REMOTE_ADDR'] ?? 'x') . '.json';
    $now  = time();
    $hits = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - $window));
    if (count($hits) >= $max) return true;
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return false;
}

/** Validates and stores one enquiry, then emails the office and (if we have an email) the customer. Returns [ok, message]. */
function saveEnquiry(array $in): array {
    global $pdo;
    $name    = trim(mb_substr((string)($in['name'] ?? ''), 0, 150));
    $email   = trim(mb_substr((string)($in['email'] ?? ''), 0, 190));
    $phone   = trim(mb_substr((string)($in['phone'] ?? ''), 0, 30));
    $type    = isset(ENQUIRY_TYPES[$in['type'] ?? '']) ? $in['type'] : 'general';
    $message = trim(mb_substr((string)($in['message'] ?? ''), 0, 3000));
    $auction = (int)($in['auction_id'] ?? 0) ?: null;

    if (mb_strlen($name) < 2)                     return [false, 'Please tell us your name.'];
    if (!leadPhoneKey($phone))                    return [false, 'Please give a phone number we can call or WhatsApp.'];
    if ($email !== '' && !leadCleanEmail($email)) return [false, 'That email address does not look right.'];

    $vehicle = '';
    if ($auction) {
        $st = $pdo->prepare("SELECT id, item_name, year FROM auctions WHERE id = ?");
        $st->execute([$auction]);
        if ($a = $st->fetch()) { $vehicle = leadVehicleTitle($a['year'], $a['item_name']); $type = $type === 'general' ? 'vehicle' : $type; }
        else $auction = null;
    }

    $pdo->prepare("INSERT INTO enquiries (type, name, email, phone, auction_id, message, source_page, attribution, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$type, $name, $email ?: null, $phone, $auction, $message ?: null,
                   mb_substr((string)($in['source_page'] ?? ''), 0, 255) ?: null,
                   function_exists('attributionJson') ? (attributionJson() ?: null) : null,
                   $_SERVER['REMOTE_ADDR'] ?? null]);
    $id = (int)$pdo->lastInsertId();
    unset($_SESSION['ahg_lead_badge']);

    // Office alert
    try {
        $mail = _buildMailer();
        $mail->addAddress(MAIL_ADMIN);
        if ($email) $mail->addReplyTo($email, $name);
        $mail->Subject = 'New enquiry #' . $id . ': ' . $name . ($vehicle ? ' — ' . $vehicle : '');
        $rows = [['Name', $name], ['Phone', $phone], ['Email', $email ?: '—'], ['About', ENQUIRY_TYPES[$type]], ['Vehicle', $vehicle ?: '—'], ['Message', $message ?: '—']];
        $body = '<h2>New website enquiry</h2><table style="width:100%;border-collapse:collapse">';
        foreach ($rows as [$k, $v]) $body .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee;color:#777;width:90px">' . $k . '</td><td style="padding:6px 8px;border-bottom:1px solid #eee">' . nl2br(e($v)) . '</td></tr>';
        $body .= '</table><a class="cta-btn" href="' . APP_URL . '/admin_dashboard.php?tab=leads" style="display:inline-block;margin-top:18px;padding:12px 24px;background:#005BA1;color:#fff;border-radius:6px;text-decoration:none">Open Leads</a>';
        $mail->Body = _emailTemplate('New enquiry', $body);
        $mail->AltBody = "New enquiry #$id from $name, $phone, $email: $message";
        _sendMail($mail, 'enquiry_admin');
    } catch (Throwable $e) { error_log('[AHG enquiry] admin mail: ' . $e->getMessage()); }

    // Customer acknowledgement
    if ($email) {
        try {
            $vars = leadCommonVars() + leadPersonVars(['name' => $name, 'vehicle' => $vehicle, 'auction_id' => $auction]);
            $text = leadFill("Hi {name},\n\nThank you for contacting Auction Hub Group" . ($vehicle ? " about the {vehicle}" : '') . ". We've received your message and a consultant will call or WhatsApp you on " . $phone . " within one business day.\n\n"
                . ($vehicle ? "The vehicle: {vehicle_url}\n\n" : "See what is on auction now: {auctions_url}\n\n")
                . "To be ready to bid, register on {register_url} — the {deposit} registration deposit is refundable.\n\nKind regards,\nAuction Hub Group · {phone} · WhatsApp {whatsapp}", $vars);
            leadSendEmail($email, $name, 'We received your enquiry — Auction Hub Group', $text);
        } catch (Throwable $e) { error_log('[AHG enquiry] ack mail: ' . $e->getMessage()); }
    }
    return [true, 'Thank you, ' . explode(' ', $name)[0] . '! We will call or WhatsApp you within one business day.'];
}
