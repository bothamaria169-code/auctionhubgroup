<?php
// ============================================================
// AUCTION HUB GROUP — finance_check.php
// "Do I qualify?" — runs the finance assessment (finAssess) on the
// figures posted and returns the result as JSON. Nothing is saved.
// Used by the pre-check on financing.php and the "Your Result" step of
// finance_apply.php, so both show exactly what the office will see.
// POST: auction_id or price, deposit, + the application money/credit fields.
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/finance_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = fn(array $a, int $code = 200) => [http_response_code($code), print json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $out(['ok' => false, 'error' => 'POST only.'], 405); exit; }

// Light rate limit per session: 60 checks per 10 minutes
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$now = time();
$_SESSION['fin_checks'] = array_values(array_filter($_SESSION['fin_checks'] ?? [], fn($t) => $t > $now - 600));
if (count($_SESSION['fin_checks']) >= 60) { $out(['ok' => false, 'error' => 'Too many checks — please wait a few minutes.'], 429); exit; }
$_SESSION['fin_checks'][] = $now;

$vehicle = null;
if (($vid = (int)($_POST['auction_id'] ?? 0)) > 0) {
    foreach (finEligibleLots() as $l) if ((int)$l['id'] === $vid) $vehicle = $l;
    if (!$vehicle) { $out(['ok' => false, 'error' => 'That vehicle is no longer available for finance.']); exit; }
    $price = finPrice($vehicle);
} else {
    $price = finMoneyInput((string)($_POST['price'] ?? ''));
    $vehicle = ['body_type' => ($_POST['heavy'] ?? '') === '1' ? 'truck' : '', 'item_name' => ''];
    if ($price < 5000 || $price > 20000000) { $out(['ok' => false, 'error' => 'Please enter the vehicle price.']); exit; }
}
$deposit = finMoneyInput((string)($_POST['deposit'] ?? ''));
$minDep  = finMinDeposit($vehicle);
if ($deposit < $minDep) $deposit = $minDep;
if ($deposit >= $price) { $out(['ok' => false, 'error' => 'Your deposit covers the full price — a Pre-Auction Purchase suits you better.']); exit; }

[$f, $in, $errors] = finReadForm($_POST, false);
if ($errors) { $out(['ok' => false, 'error' => reset($errors), 'errors' => $errors]); exit; }

$as = finAssess($in, $price, $deposit, $vehicle);
$quoted = $as['grade'] !== 'R' ? max($deposit, (float)$as['required_deposit']) : $deposit;
$plans = finPlans($price - $quoted, finTiers($vehicle));
foreach ($plans as &$p) $p['affordable'] = $as['max_instalment'] > 0 && $p['monthly'] <= $as['max_instalment'] + 0.5;
unset($p);

$out([
    'ok' => true,
    'score' => $as['score'], 'grade' => $as['grade'], 'label' => $as['label'], 'colour' => finGradeColour($as['grade']),
    'points' => array_values(array_map(fn($k, $v) => ['key' => $k, 'label' => $v[0], 'pts' => $v[1], 'max' => $v[2], 'note' => $v[3]], array_keys($as['points']), $as['points'])),
    'reasons' => $as['reasons'],
    'price' => $price, 'offered' => $deposit, 'deposit' => $quoted, 'financed' => round($price - $quoted, 2),
    'startup' => $quoted > $deposit, 'min_deposit' => $minDep,
    'income' => $as['income'], 'expenses' => $as['expenses'], 'free' => $as['free'],
    'max_instalment' => $as['max_instalment'], 'plans' => $plans,
]);
