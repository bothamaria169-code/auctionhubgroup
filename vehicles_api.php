<?php
/**
 * Public vehicle search endpoint for the AHG chat widget.
 * No authentication required — only exposes public listing fields.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

$q    = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 12;
$off  = ($page - 1) * $per;

try {
    $conditions = ["a.status IN ('active','upcoming')", "a.is_sold = 0"];
    $params     = [];

    if ($q !== '') {
        $conditions[] = "(m.name LIKE ? OR a.item_name LIKE ? OR CAST(a.year AS CHAR) LIKE ? OR a.body_type LIKE ?)";
        $like    = "%{$q}%";
        $params  = [$like, $like, $like, $like];
    }

    $where = implode(' AND ', $conditions);

    $cs = $pdo->prepare("
        SELECT COUNT(*)
        FROM   auctions a
        LEFT JOIN makes m ON m.id = a.make_id
        WHERE  $where
    ");
    $cs->execute($params);
    $total = (int) $cs->fetchColumn();

    $vs = $pdo->prepare("
        SELECT a.id,
               COALESCE(m.name, '') AS make,
               a.item_name,
               a.year,
               a.mileage,
               a.fuel_type,
               a.transmission,
               a.color,
               a.body_type,
               a.condition_grade,
               a.start_price,
               a.current_price,
               a.status,
               a.image_url,
               a.end_time
        FROM   auctions a
        LEFT JOIN makes m ON m.id = a.make_id
        WHERE  $where
        ORDER  BY FIELD(a.status, 'active', 'upcoming'), a.end_time ASC
        LIMIT  ? OFFSET ?
    ");
    $vs->execute(array_merge($params, [$per, $off]));

    echo json_encode([
        'ok'       => true,
        'vehicles' => imgHostDeep($vs->fetchAll(PDO::FETCH_ASSOC)),
        'total'    => $total,
        'page'     => $page,
        'pages'    => max(1, (int) ceil($total / $per)),
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok'       => false,
        'vehicles' => [],
        'total'    => 0,
        'page'     => 1,
        'pages'    => 1,
    ]);
}
