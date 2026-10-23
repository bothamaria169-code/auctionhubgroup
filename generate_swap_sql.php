<?php
require_once __DIR__ . '/config.php';

// The 211 momentum auction IDs confirmed broken (no recoverable source image)
$brokenIds = [
    324,325,326,327,328,329,330,331,332,333,334,335,336,337,338,339,340,341,342,343,
    344,345,346,347,348,349,350,351,352,353,354,356,357,358,359,361,362,363,364,365,
    366,367,368,369,370,371,372,373,374,375,376,377,378,379,380,381,382,383,384,385,
    386,387,388,389,390,391,392,393,394,395,396,397,398,399,400,401,402,403,404,405,
    406,407,408,409,410,411,412,413,414,415,416,417,418,419,420,421,422,423,424,425,
    426,427,428,429,430,431,432,433,434,435,436,437,438,439,440,441,442,443,444,445,
    446,447,448,449,450,451,452,453,454,455,456,457,458,459,460,461,462,463,464,465,
    466,467,468,469,470,471,472,473,474,475,476,477,478,479,480,481,482,483,484,485,
    486,487,488,489,490,491,492,493,494,495,496,497,498,499,500,501,502,503,504,505,
    507,508,509,510,511,512,513,514,515,516,517,518,519,520,521,522,523,524,525,526,
    527,528,529,530,531,532,546,547,548,549,550
];
echo "Broken ID count: " . count($brokenIds) . "\n";

$momValidFuel = ['petrol','diesel','electric','hybrid'];
$momValidTrans = ['manual','automatic'];

// Fetch 211 real Park Village vehicles as replacements
$stmt = $pdo->query("
    SELECT a.id, a.item_name, m.name AS make_name, a.year, a.mileage, a.fuel_type,
           a.transmission, a.color, a.description, a.start_price, a.image_url
    FROM auctions a
    LEFT JOIN makes m ON m.id = a.make_id
    WHERE a.id BETWEEN 322 AND 723
      AND (
        m.name IN ('Volkswagen','Toyota','Hyundai','Suzuki','Renault','Ford','Chevrolet','BMW','Audi','Kia','Mazda','Haval','Nissan','Omoda','Chery','Land Rover','Mahindra','Jeep','Mitsubishi','GWM')
        OR (m.name = 'Mercedes-Benz' AND a.item_name NOT LIKE '%ACTROS%')
        OR (m.name = 'Isuzu' AND (a.item_name LIKE '%KB%' OR a.item_name LIKE '%D-MAX%' OR a.item_name LIKE '%DMAX%'))
      )
    ORDER BY a.id
    LIMIT " . count($brokenIds)
);
$replacements = $stmt->fetchAll();
echo "Replacement vehicles fetched: " . count($replacements) . "\n";

// Preload gallery images for all candidate replacement vehicles
$galleryByAuction = [];
$galStmt = $pdo->query("
    SELECT auction_id, file_path FROM auction_gallery
    WHERE auction_id BETWEEN 322 AND 723
    ORDER BY auction_id, sort_order
");
foreach ($galStmt->fetchAll() as $g) {
    $galleryByAuction[$g['auction_id']][] = $g['file_path'];
}

if (count($replacements) < count($brokenIds)) {
    die("ERROR: not enough replacement vehicles available.\n");
}

function sqlStr(?string $v): string {
    global $pdo;
    return $v === null ? 'NULL' : $pdo->quote($v);
}
function sqlNum($v): string {
    return ($v === null || $v === '') ? 'NULL' : (string)(float)$v;
}

$makeNames = [];
foreach ($replacements as $r) {
    if (!empty($r['make_name'])) $makeNames[$r['make_name']] = true;
}

$out = [];
$out[] = "-- Fill the 211 unrecoverable momentum listings with real Park Village vehicles (photo + description)";
$out[] = "-- Generated " . date('Y-m-d H:i:s');
$out[] = "";
$out[] = "START TRANSACTION;";
$out[] = "";
$out[] = "-- Ensure makes exist";
foreach (array_keys($makeNames) as $name) {
    $out[] = "INSERT IGNORE INTO makes (name) VALUES (" . sqlStr($name) . ");";
}
$out[] = "";

foreach ($brokenIds as $i => $brokenId) {
    $r = $replacements[$i];

    $fuel = in_array($r['fuel_type'], $momValidFuel, true) ? $r['fuel_type'] : null;
    $trans = in_array($r['transmission'], $momValidTrans, true) ? $r['transmission'] : null;
    $makeSql = $r['make_name']
        ? "(SELECT id FROM makes WHERE name = " . sqlStr($r['make_name']) . " LIMIT 1)"
        : "NULL";

    $out[] = "-- broken auction {$brokenId} <- park-village vehicle {$r['id']}: {$r['item_name']}";
    $out[] = "UPDATE auctions SET"
        . " item_name = " . sqlStr($r['item_name']) . ","
        . " make_id = {$makeSql},"
        . " year = " . sqlNum($r['year']) . ","
        . " mileage = " . sqlNum($r['mileage']) . ","
        . " fuel_type = " . sqlStr($fuel) . ","
        . " transmission = " . sqlStr($trans) . ","
        . " color = " . sqlStr($r['color']) . ","
        . " description = " . sqlStr($r['description']) . ","
        . " start_price = " . sqlNum($r['start_price']) . ","
        . " image_url = " . sqlStr($r['image_url'])
        . " WHERE id = {$brokenId} AND image_url LIKE 'uploads/%';";

    $out[] = "DELETE FROM auction_gallery WHERE auction_id = {$brokenId};";
    foreach ($galleryByAuction[$r['id']] ?? [] as $galUrl) {
        $out[] = "INSERT INTO auction_gallery (auction_id, image_url) VALUES ({$brokenId}, " . sqlStr($galUrl) . ");";
    }
    $out[] = "";
}
$out[] = "";
$out[] = "COMMIT;";

file_put_contents(__DIR__ . '/online_swap_vehicle_data.sql', implode("\n", $out) . "\n");
echo "Wrote online_swap_vehicle_data.sql (" . count($out) . " lines)\n";
