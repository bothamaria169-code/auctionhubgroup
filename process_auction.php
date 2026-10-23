<?php
// ============================================================
// AUCTION HUB GROUP — process_auction.php
// Admin-only form POST handler (non-AJAX fallback)
// Actions: add_auction · edit_auction · delete_auction
//          upload_gallery_images · import_csv
// All actions redirect back with flash messages.
// AJAX equivalents live in api.php — this file handles
// traditional form submissions and the CSV import pipeline.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// ── Admin auth guard ──────────────────────────────────────────
$token = getTokenFromRequest();
if (!$token) {
    redirect('login.php', 'Please log in to continue.', 'error');
}

$currentUser = verifyJWT($token);
if (!$currentUser || $currentUser['role'] !== 'admin') {
    redirect('user_dashboard.php', 'Admin access required.', 'error');
}

syncSession($currentUser);

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin_dashboard.php', 'Invalid request method.', 'error');
}

$action = trim($_POST['action'] ?? '');

// ── Response helper ───────────────────────────────────────────
function done(string $url, string $message, string $type = 'success'): void {
    redirect($url, $message, $type);
}

// ── Sanitise integer ─────────────────────────────────────────
function intPost(string $key, int $default = 0): int {
    return (int)($_POST[$key] ?? $default);
}

// ── Sanitise float ───────────────────────────────────────────
function floatPost(string $key, float $default = 0.0): float {
    return (float)($_POST[$key] ?? $default);
}

// ── Sanitise string ──────────────────────────────────────────
function strPost(string $key, string $default = ''): string {
    return trim($_POST[$key] ?? $default);
}

// ============================================================
switch ($action) {
// ============================================================


// ════════════════════════════════════════════════════════════
// ADD AUCTION
// ════════════════════════════════════════════════════════════
case 'add_auction': {

    // ── Collect fields ────────────────────────────────────────
    $itemName      = strPost('item_name');
    $makeId        = intPost('make_id');
    $year          = intPost('year');
    $mileage       = intPost('mileage');
    $fuelType      = strPost('fuel_type');
    $transmission  = strPost('transmission');
    $color         = strPost('color');
    $bodyType      = strPost('body_type');
    $engineSize    = strPost('engine_size');
    $vinNumber     = strPost('vin_number');
    $conditionGrade= strPost('condition_grade');
    $description   = strPost('description');
    $serviceHistory= strPost('service_history');
    $startPrice    = floatPost('start_price');
    $reservePrice  = floatPost('reserve_price');
    $minIncrement  = floatPost('min_increment', 500.00);
    $startTime     = strPost('start_time');
    $endTime       = strPost('end_time');
    $status        = strPost('status', 'upcoming');

    // ── Validation ────────────────────────────────────────────
    $errors = [];
    if (!$itemName)              $errors[] = 'Vehicle name is required.';
    if (!$makeId)                $errors[] = 'Make is required.';
    if ($year < 1980 || $year > (int)date('Y') + 1) $errors[] = 'Valid year required.';
    if ($startPrice <= 0)        $errors[] = 'Start price must be greater than zero.';
    if ($reservePrice <= 0)      $errors[] = 'Reserve price must be greater than zero.';
    if ($minIncrement <= 0)      $errors[] = 'Minimum increment must be greater than zero.';
    if (!$startTime)             $errors[] = 'Start time is required.';
    if (!$endTime)               $errors[] = 'End time is required.';
    if ($endTime <= $startTime)  $errors[] = 'End time must be after start time.';

    $validStatuses = ['upcoming', 'active', 'closed', 'draft'];
    if (!in_array($status, $validStatuses)) $status = 'upcoming';

    if ($errors) {
        done('manage_auction.php', implode(' ', $errors), 'error');
    }

    // ── Handle primary image upload ───────────────────────────
    $imageUrl = null;
    if (!empty($_FILES['primary_image']['name'])) {
        $upload = handleUpload($_FILES['primary_image'], UPLOAD_VEHICLES, ALLOWED_IMAGE_TYPES, 'vehicle');
        if (!$upload['success']) {
            done('manage_auction.php', $upload['message'], 'error');
        }
        $imageUrl = $upload['path'];
    }

    // ── Insert auction ────────────────────────────────────────
    $stmt = $pdo->prepare("
        INSERT INTO auctions (
            item_name, make_id, year, mileage, fuel_type, transmission,
            color, body_type, engine_size, vin_number, condition_grade,
            description, service_history,
            start_price, reserve_price, min_increment, current_price,
            start_time, end_time, status, image_url,
            created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            NOW()
        )
    ");
    $stmt->execute([
        $itemName, $makeId, $year, $mileage, $fuelType, $transmission,
        $color, $bodyType, $engineSize, $vinNumber, $conditionGrade,
        $description, $serviceHistory,
        $startPrice, $reservePrice, $minIncrement, 0,
        $startTime, $endTime, $status, $imageUrl,
    ]);
    $auctionId = (int)$pdo->lastInsertId();

    // ── Insert primary image into gallery ─────────────────────
    if ($imageUrl) {
        $pdo->prepare("
            INSERT INTO auction_gallery (auction_id, file_path, file_name, is_primary, sort_order, uploaded_at)
            VALUES (?, ?, ?, 1, 0, NOW())
        ")->execute([$auctionId, $imageUrl, basename($imageUrl)]);
    }

    // ── Handle multiple gallery images ────────────────────────
    if (!empty($_FILES['gallery_images']['name'][0])) {
        $sortOrder = 1;
        $fileCount = count($_FILES['gallery_images']['name']);

        for ($i = 0; $i < $fileCount; $i++) {
            $file = [
                'name'     => $_FILES['gallery_images']['name'][$i],
                'type'     => $_FILES['gallery_images']['type'][$i],
                'tmp_name' => $_FILES['gallery_images']['tmp_name'][$i],
                'error'    => $_FILES['gallery_images']['error'][$i],
                'size'     => $_FILES['gallery_images']['size'][$i],
            ];

            if ($file['error'] !== UPLOAD_ERR_OK) continue;

            $upload = handleUpload($file, UPLOAD_VEHICLES, ALLOWED_IMAGE_TYPES, 'vehicle');
            if (!$upload['success']) continue;

            $pdo->prepare("
                INSERT INTO auction_gallery (auction_id, file_path, file_name, is_primary, sort_order, uploaded_at)
                VALUES (?, ?, ?, 0, ?, NOW())
            ")->execute([$auctionId, $upload['path'], basename($upload['path']), $sortOrder]);

            $sortOrder++;
        }
    }

    done(
        'manage_auction.php?id=' . $auctionId,
        "Vehicle '{$itemName}' added successfully.",
        'success'
    );
}


// ════════════════════════════════════════════════════════════
// EDIT AUCTION
// ════════════════════════════════════════════════════════════
case 'edit_auction': {

    $auctionId     = intPost('auction_id');
    $itemName      = strPost('item_name');
    $makeId        = intPost('make_id');
    $year          = intPost('year');
    $mileage       = intPost('mileage');
    $fuelType      = strPost('fuel_type');
    $transmission  = strPost('transmission');
    $color         = strPost('color');
    $bodyType      = strPost('body_type');
    $engineSize    = strPost('engine_size');
    $vinNumber     = strPost('vin_number');
    $conditionGrade= strPost('condition_grade');
    $description   = strPost('description');
    $serviceHistory= strPost('service_history');
    $startPrice    = floatPost('start_price');
    $reservePrice  = floatPost('reserve_price');
    $minIncrement  = floatPost('min_increment', 500.00);
    $startTime     = strPost('start_time');
    $endTime       = strPost('end_time');
    $status        = strPost('status', 'upcoming');

    if (!$auctionId) {
        done('admin_dashboard.php?tab=auctions', 'Invalid auction ID.', 'error');
    }

    // Check auction exists
    $stmt = $pdo->prepare("SELECT id, image_url FROM auctions WHERE id = ?");
    $stmt->execute([$auctionId]);
    $auction = $stmt->fetch();
    if (!$auction) {
        done('admin_dashboard.php?tab=auctions', 'Auction not found.', 'error');
    }

    $errors = [];
    if (!$itemName)              $errors[] = 'Vehicle name is required.';
    if (!$makeId)                $errors[] = 'Make is required.';
    if ($startPrice <= 0)        $errors[] = 'Start price must be greater than zero.';
    if ($reservePrice <= 0)      $errors[] = 'Reserve price must be greater than zero.';
    if (!$startTime || !$endTime) $errors[] = 'Start and end times are required.';
    if ($endTime <= $startTime)  $errors[] = 'End time must be after start time.';

    $validStatuses = ['upcoming', 'active', 'closed', 'draft'];
    if (!in_array($status, $validStatuses)) $status = 'upcoming';

    if ($errors) {
        done('manage_auction.php?id=' . $auctionId, implode(' ', $errors), 'error');
    }

    // ── Handle new primary image ──────────────────────────────
    $imageUrl = $auction['image_url']; // keep existing by default
    if (!empty($_FILES['primary_image']['name'])) {
        $upload = handleUpload($_FILES['primary_image'], UPLOAD_VEHICLES, ALLOWED_IMAGE_TYPES, 'vehicle');
        if ($upload['success']) {
            $imageUrl = $upload['path'];
            // Insert new image into gallery as primary
            $pdo->prepare("UPDATE auction_gallery SET is_primary = 0 WHERE auction_id = ?")
                ->execute([$auctionId]);
            $pdo->prepare("
                INSERT INTO auction_gallery (auction_id, file_path, file_name, is_primary, sort_order, uploaded_at)
                VALUES (?, ?, ?, 1, 0, NOW())
            ")->execute([$auctionId, $imageUrl, basename($imageUrl)]);
        }
    }

    // ── Update auction ────────────────────────────────────────
    $stmt = $pdo->prepare("
        UPDATE auctions SET
            item_name       = ?,
            make_id         = ?,
            year            = ?,
            mileage         = ?,
            fuel_type       = ?,
            transmission    = ?,
            color           = ?,
            body_type       = ?,
            engine_size     = ?,
            vin_number      = ?,
            condition_grade = ?,
            description     = ?,
            service_history = ?,
            start_price     = ?,
            reserve_price   = ?,
            min_increment   = ?,
            start_time      = ?,
            end_time        = ?,
            status          = ?,
            image_url       = ?,
            updated_at      = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $itemName, $makeId, $year, $mileage, $fuelType, $transmission,
        $color, $bodyType, $engineSize, $vinNumber, $conditionGrade,
        $description, $serviceHistory,
        $startPrice, $reservePrice, $minIncrement,
        $startTime, $endTime, $status, $imageUrl,
        $auctionId,
    ]);

    // ── Handle additional gallery images ──────────────────────
    if (!empty($_FILES['gallery_images']['name'][0])) {
        $stmt = $pdo->prepare("SELECT MAX(sort_order) AS max_order FROM auction_gallery WHERE auction_id = ?");
        $stmt->execute([$auctionId]);
        $sortOrder = ((int)($stmt->fetchColumn() ?? 0)) + 1;

        $fileCount = count($_FILES['gallery_images']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            $file = [
                'name'     => $_FILES['gallery_images']['name'][$i],
                'type'     => $_FILES['gallery_images']['type'][$i],
                'tmp_name' => $_FILES['gallery_images']['tmp_name'][$i],
                'error'    => $_FILES['gallery_images']['error'][$i],
                'size'     => $_FILES['gallery_images']['size'][$i],
            ];
            if ($file['error'] !== UPLOAD_ERR_OK) continue;
            $upload = handleUpload($file, UPLOAD_VEHICLES, ALLOWED_IMAGE_TYPES, 'vehicle');
            if (!$upload['success']) continue;
            $pdo->prepare("
                INSERT INTO auction_gallery (auction_id, file_path, file_name, is_primary, sort_order, uploaded_at)
                VALUES (?, ?, ?, 0, ?, NOW())
            ")->execute([$auctionId, $upload['path'], basename($upload['path']), $sortOrder]);
            $sortOrder++;
        }
    }

    done(
        'manage_auction.php?id=' . $auctionId,
        "Auction '{$itemName}' updated successfully.",
        'success'
    );
}


// ════════════════════════════════════════════════════════════
// DELETE AUCTION
// ════════════════════════════════════════════════════════════
case 'delete_auction': {

    $auctionId = intPost('auction_id');
    if (!$auctionId) {
        done('admin_dashboard.php?tab=auctions', 'Invalid auction ID.', 'error');
    }

    $stmt = $pdo->prepare("SELECT item_name FROM auctions WHERE id = ?");
    $stmt->execute([$auctionId]);
    $auction = $stmt->fetch();
    if (!$auction) {
        done('admin_dashboard.php?tab=auctions', 'Auction not found.', 'error');
    }

    // Delete gallery images from disk
    $imgs = $pdo->prepare("SELECT file_path FROM auction_gallery WHERE auction_id = ?");
    $imgs->execute([$auctionId]);
    foreach ($imgs->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $full = __DIR__ . '/' . ltrim($path, '/');
        if (file_exists($full)) @unlink($full);
    }

    // Delete main image if not in gallery
    $mainImg = $pdo->prepare("SELECT image_url FROM auctions WHERE id = ?");
    $mainImg->execute([$auctionId]);
    $imgUrl = $mainImg->fetchColumn();
    if ($imgUrl) {
        $full = __DIR__ . '/' . ltrim($imgUrl, '/');
        if (file_exists($full)) @unlink($full);
    }

    // Cascade DB deletes
    $pdo->prepare("DELETE FROM auction_gallery WHERE auction_id = ?")->execute([$auctionId]);
    $pdo->prepare("UPDATE bids SET auction_id = auction_id WHERE auction_id = ?")->execute([$auctionId]); // keep bids for audit
    $pdo->prepare("DELETE FROM auctions WHERE id = ?")->execute([$auctionId]);

    done(
        'admin_dashboard.php?tab=auctions',
        "Auction '{$auction['item_name']}' deleted.",
        'success'
    );
}


// ════════════════════════════════════════════════════════════
// UPLOAD GALLERY IMAGES (standalone, from manage_auction.php)
// ════════════════════════════════════════════════════════════
case 'upload_gallery_images': {

    $auctionId = intPost('auction_id');
    if (!$auctionId) {
        done('admin_dashboard.php?tab=auctions', 'Invalid auction ID.', 'error');
    }

    $stmt = $pdo->prepare("SELECT id FROM auctions WHERE id = ?");
    $stmt->execute([$auctionId]);
    if (!$stmt->fetch()) {
        done('admin_dashboard.php?tab=auctions', 'Auction not found.', 'error');
    }

    if (empty($_FILES['gallery_images']['name'][0])) {
        done('manage_auction.php?id=' . $auctionId, 'No images selected.', 'error');
    }

    $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM auction_gallery WHERE auction_id = ?");
    $stmt->execute([$auctionId]);
    $sortOrder = ((int)$stmt->fetchColumn()) + 1;

    $uploaded = 0;
    $failed   = 0;
    $fileCount = count($_FILES['gallery_images']['name']);

    for ($i = 0; $i < $fileCount; $i++) {
        $file = [
            'name'     => $_FILES['gallery_images']['name'][$i],
            'type'     => $_FILES['gallery_images']['type'][$i],
            'tmp_name' => $_FILES['gallery_images']['tmp_name'][$i],
            'error'    => $_FILES['gallery_images']['error'][$i],
            'size'     => $_FILES['gallery_images']['size'][$i],
        ];
        if ($file['error'] !== UPLOAD_ERR_OK) { $failed++; continue; }

        $upload = handleUpload($file, UPLOAD_VEHICLES, ALLOWED_IMAGE_TYPES, 'vehicle');
        if (!$upload['success']) { $failed++; continue; }

        // Check if this auction has any primary image yet
        $hasPrimary = $pdo->prepare("SELECT COUNT(*) FROM auction_gallery WHERE auction_id = ? AND is_primary = 1");
        $hasPrimary->execute([$auctionId]);
        $isPrimary = ($hasPrimary->fetchColumn() == 0) ? 1 : 0;

        $pdo->prepare("
            INSERT INTO auction_gallery (auction_id, file_path, file_name, is_primary, sort_order, uploaded_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([$auctionId, $upload['path'], basename($upload['path']), $isPrimary, $sortOrder]);

        // If first image and no primary set, update auction image_url
        if ($isPrimary) {
            $pdo->prepare("UPDATE auctions SET image_url = ? WHERE id = ?")
                ->execute([$upload['path'], $auctionId]);
        }

        $sortOrder++;
        $uploaded++;
    }

    $msg = "{$uploaded} image(s) uploaded.";
    if ($failed) $msg .= " {$failed} failed.";
    done('manage_auction.php?id=' . $auctionId, $msg, $failed ? 'warning' : 'success');
}


// ════════════════════════════════════════════════════════════
// CSV IMPORT
// Accepts a CSV file with vehicle data + optional image paths
// Expected columns (order-independent, matched by header name):
//   item_name, make_name, year, mileage, fuel_type, transmission,
//   color, body_type, engine_size, vin_number, condition_grade,
//   description, service_history, start_price, reserve_price,
//   min_increment, start_time, end_time, status,
//   image_url, gallery_images (pipe-separated paths)
// ════════════════════════════════════════════════════════════
case 'import_csv': {

    if (empty($_FILES['csv_file']['name'])) {
        done('admin_dashboard.php?tab=import', 'No CSV file selected.', 'error');
    }

    $upload = handleUpload($_FILES['csv_file'], UPLOAD_CSV, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], 'import');
    if (!$upload['success']) {
        done('admin_dashboard.php?tab=import', $upload['message'], 'error');
    }

    // Defaults from form
    $defaultStatus    = strPost('default_status', 'upcoming');
    $defaultStartTime = strPost('default_start_time', '');
    $defaultEndTime   = strPost('default_end_time', '');
    $defaultIncrement = floatPost('default_increment', 500.00);

    $validStatuses = ['upcoming', 'active', 'closed', 'draft'];
    if (!in_array($defaultStatus, $validStatuses)) $defaultStatus = 'upcoming';

    // ── Parse CSV ─────────────────────────────────────────────
    $filePath = __DIR__ . '/' . $upload['path'];
    $handle   = fopen($filePath, 'r');
    if (!$handle) {
        done('admin_dashboard.php?tab=import', 'Could not open uploaded CSV.', 'error');
    }

    // Read header row
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        done('admin_dashboard.php?tab=import', 'CSV is empty or unreadable.', 'error');
    }

    // Normalise header names: trim, lowercase, underscores
    $headers = array_map(fn($h) => strtolower(trim(preg_replace('/\s+/', '_', $h))), $headers);
    $col     = array_flip($headers); // column name → index

    // Required columns
    $required = ['item_name', 'start_price', 'reserve_price'];
    foreach ($required as $req) {
        if (!isset($col[$req])) {
            fclose($handle);
            done('admin_dashboard.php?tab=import', "Missing required CSV column: '{$req}'.", 'error');
        }
    }

    // ── Pre-load makes for lookup ─────────────────────────────
    $makesRaw = $pdo->query("SELECT id, LOWER(make_name) AS make_name FROM vehicle_makes")->fetchAll();
    $makeMap  = [];
    foreach ($makesRaw as $m) {
        $makeMap[$m['make_name']] = $m['id'];
    }

    // ── Process rows ──────────────────────────────────────────
    $inserted = 0;
    $skipped  = 0;
    $errors   = [];
    $rowNum   = 1; // 1 = first data row after header

    $insertStmt = $pdo->prepare("
        INSERT INTO auctions (
            item_name, make_id, year, mileage, fuel_type, transmission,
            color, body_type, engine_size, vin_number, condition_grade,
            description, service_history,
            start_price, reserve_price, min_increment, current_price,
            start_time, end_time, status, image_url,
            created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, 0,
            ?, ?, ?, ?,
            NOW()
        )
    ");

    $galleryStmt = $pdo->prepare("
        INSERT INTO auction_gallery (auction_id, file_path, file_name, is_primary, sort_order, uploaded_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    /**
     * Helper: get cell value from row using column name, with fallback
     */
    $cell = function(array $row, string $key, string $default = '') use ($col): string {
        if (!isset($col[$key])) return $default;
        return trim($row[$col[$key]] ?? $default);
    };

    while (($row = fgetcsv($handle)) !== false) {
        $rowNum++;

        // Skip blank rows
        if (empty(array_filter($row))) continue;

        // ── Collect fields ────────────────────────────────────
        $itemName       = $cell($row, 'item_name');
        $makeName       = strtolower($cell($row, 'make_name'));
        $year           = (int)$cell($row, 'year', '0');
        $mileage        = (int)$cell($row, 'mileage', '0');
        $fuelType       = $cell($row, 'fuel_type', 'Petrol');
        $transmission   = $cell($row, 'transmission', 'Automatic');
        $color          = $cell($row, 'color');
        $bodyType       = $cell($row, 'body_type');
        $engineSize     = $cell($row, 'engine_size');
        $vinNumber      = $cell($row, 'vin_number');
        $conditionGrade = $cell($row, 'condition_grade');
        $description    = $cell($row, 'description');
        $serviceHistory = $cell($row, 'service_history');
        $startPrice     = (float)str_replace([',', 'R', ' '], '', $cell($row, 'start_price'));
        $reservePrice   = (float)str_replace([',', 'R', ' '], '', $cell($row, 'reserve_price'));
        $minIncrement   = (float)str_replace([',', 'R', ' '], '', $cell($row, 'min_increment', (string)$defaultIncrement));
        $startTime      = $cell($row, 'start_time', $defaultStartTime);
        $endTime        = $cell($row, 'end_time', $defaultEndTime);
        $status         = $cell($row, 'status', $defaultStatus);
        $imageUrl       = $cell($row, 'image_url');
        $galleryRaw     = $cell($row, 'gallery_images');

        // ── Validate required ─────────────────────────────────
        if (!$itemName) {
            $errors[] = "Row {$rowNum}: item_name is empty — skipped.";
            $skipped++;
            continue;
        }

        if ($startPrice <= 0) {
            $errors[] = "Row {$rowNum} ({$itemName}): Invalid start_price — skipped.";
            $skipped++;
            continue;
        }

        if ($reservePrice <= 0) {
            $errors[] = "Row {$rowNum} ({$itemName}): Invalid reserve_price — skipped.";
            $skipped++;
            continue;
        }

        if (!in_array($status, $validStatuses)) $status = $defaultStatus;

        // ── Resolve make_id ───────────────────────────────────
        $makeId = null;
        if ($makeName && isset($makeMap[$makeName])) {
            $makeId = $makeMap[$makeName];
        } elseif ($makeName) {
            // Auto-create the make if not found
            $pdo->prepare("INSERT INTO vehicle_makes (make_name) VALUES (?)")
                ->execute([ucfirst($makeName)]);
            $newMakeId = (int)$pdo->lastInsertId();
            $makeMap[$makeName] = $newMakeId;
            $makeId = $newMakeId;
        }

        // ── Insert auction ────────────────────────────────────
        try {
            $insertStmt->execute([
                $itemName, $makeId, $year ?: null, $mileage ?: null,
                $fuelType ?: null, $transmission ?: null,
                $color ?: null, $bodyType ?: null, $engineSize ?: null,
                $vinNumber ?: null, $conditionGrade ?: null,
                $description ?: null, $serviceHistory ?: null,
                $startPrice, $reservePrice,
                $minIncrement > 0 ? $minIncrement : 500.00,
                $startTime ?: null, $endTime ?: null,
                $status, $imageUrl ?: null,
            ]);
        } catch (\PDOException $e) {
            $errors[] = "Row {$rowNum} ({$itemName}): DB error — " . $e->getMessage();
            $skipped++;
            continue;
        }

        $auctionId = (int)$pdo->lastInsertId();

        // ── Insert primary image into gallery ─────────────────
        if ($imageUrl) {
            try {
                $galleryStmt->execute([$auctionId, $imageUrl, basename($imageUrl), 1, 0]);
            } catch (\PDOException $e) {
                // Non-fatal — image path may be invalid
                $errors[] = "Row {$rowNum}: Could not insert primary image into gallery.";
            }
        }

        // ── Insert pipe-separated gallery images ──────────────
        if ($galleryRaw) {
            $galleryPaths = array_filter(array_map('trim', explode('|', $galleryRaw)));
            $sortOrder = 1;
            foreach ($galleryPaths as $gPath) {
                try {
                    $galleryStmt->execute([$auctionId, $gPath, basename($gPath), 0, $sortOrder]);
                    $sortOrder++;
                } catch (\PDOException $e) {
                    $errors[] = "Row {$rowNum}: Could not insert gallery image '{$gPath}'.";
                }
            }
        }

        $inserted++;
    }

    fclose($handle);

    // ── Log the import ────────────────────────────────────────
    try {
        $pdo->prepare("
            INSERT INTO csv_import_log (admin_id, file_name, rows_inserted, rows_skipped, error_count, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([
            $currentUser['sub'],
            basename($filePath),
            $inserted,
            $skipped,
            count($errors),
        ]);
    } catch (\PDOException $e) {
        // Non-fatal — log table may not exist in older schema versions
    }

    // Store errors in session for display
    if ($errors) {
        $_SESSION['import_errors'] = $errors;
    }

    $type = $skipped > 0 ? 'warning' : 'success';
    done(
        'admin_dashboard.php?tab=import',
        "Import complete. Inserted: {$inserted}, Skipped: {$skipped}" . (count($errors) ? " — {$skipped} row(s) had errors." : '.'),
        $type
    );
}


// ════════════════════════════════════════════════════════════
// UNKNOWN ACTION
// ════════════════════════════════════════════════════════════
default:
    done('admin_dashboard.php', 'Unknown action: ' . htmlspecialchars($action, ENT_QUOTES), 'error');
    break;

} // end switch
