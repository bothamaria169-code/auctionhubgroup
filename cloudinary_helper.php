<?php
// ============================================================
// AUCTION HUB GROUP — cloudinary_helper.php
// Reusable Cloudinary upload wrapper + URL builder
//
// Implements: Developer Guide § 1.2 (Cloudinary Setup — Step 4)
//             Developer Guide § 3   (Folder & URL Structure)
//
// Usage:
//   require_once 'cloudinary_helper.php';
//
//   // Upload a local file or remote URL
//   $result = uploadToCloudinary('/path/to/car.jpg', CLOUDINARY_VEHICLE_FOLDER, 'lot-001-toyota-corolla-2022-main');
//   // $result['url']       → stored in auctions.image_url
//   // $result['public_id'] → stored in auctions.cloudinary_public_id
//
//   // Build a sized URL any time (no re-upload needed)
//   $thumb = cloudinaryUrl($result['public_id'], 320, 240);
//   $card  = cloudinaryUrl($result['public_id'], 800, 600);
// ============================================================

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;

// ── SDK Bootstrap ─────────────────────────────────────────────

/**
 * Return a singleton Cloudinary instance configured from config.php constants.
 */
function getCloudinary(): Cloudinary
{
    static $cld = null;

    if (!$cld) {
        $cld = new Cloudinary(
            Configuration::instance([
                'cloud' => [
                    'cloud_name' => CLOUDINARY_CLOUD_NAME,
                    'api_key'    => CLOUDINARY_API_KEY,
                    'api_secret' => CLOUDINARY_API_SECRET,
                ],
                'url' => ['secure' => true],
            ])
        );
    }

    return $cld;
}

// ── Upload ────────────────────────────────────────────────────

/**
 * Upload a local file or remote URL to Cloudinary.
 *
 * Naming convention (public_id):
 *   Main image  → lot-{auction_id}-{make-model-year}-main
 *   Gallery #2  → lot-{auction_id}-{make-model-year}-02
 *   Gallery #3  → lot-{auction_id}-{make-model-year}-03
 *   Import      → import-{old_id}-{make}-main  (migration only)
 *
 * @param  string $source    Local file path OR remote URL
 * @param  string $folder    Cloudinary folder  e.g. 'pva/vehicles/2026'
 * @param  string $publicId  Cloudinary public_id (filename without extension).
 *                           If omitted, Cloudinary auto-generates one.
 * @return array{url: string, public_id: string, width: int, height: int}
 * @throws \Cloudinary\Exception\ApiException on upload failure
 */
function uploadToCloudinary(string $source, string $folder = '', string $publicId = ''): array
{
    if (!$folder) {
        $folder = CLOUDINARY_VEHICLE_FOLDER;
    }

    $options = [
        'folder'        => $folder,
        'resource_type' => 'image',
        'overwrite'     => false,       // never silently overwrite an existing asset
        'transformation' => [
            // Auto-convert to WebP / AVIF on delivery (no extra cost, ~40–70% smaller)
            ['quality' => 'auto', 'fetch_format' => 'auto'],
        ],
    ];

    if ($publicId !== '') {
        // Sanitise: lowercase, hyphens only, no spaces/dots
        $options['public_id'] = cloudinarySlug($publicId);
    }

    $result = getCloudinary()->uploadApi()->upload($source, $options);

    return [
        'url'       => $result['secure_url'],
        'public_id' => $result['public_id'],
        'width'     => $result['width'],
        'height'    => $result['height'],
        'format'    => $result['format'],
        'bytes'     => $result['bytes'],
    ];
}

/**
 * Delete an image from Cloudinary by its public_id.
 * Call this when an auction image is replaced or the auction is deleted.
 *
 * @param  string $publicId  e.g. 'pva/vehicles/2026/lot-001-toyota-corolla-2022-main'
 * @return bool  true on success
 */
function deleteFromCloudinary(string $publicId): bool
{
    try {
        $result = getCloudinary()->uploadApi()->destroy($publicId);
        return ($result['result'] === 'ok');
    } catch (\Exception $e) {
        error_log('[AHG Cloudinary] deleteFromCloudinary failed: ' . $e->getMessage());
        return false;
    }
}

// ── URL Builder ───────────────────────────────────────────────

/**
 * Build a Cloudinary delivery URL with on-the-fly transformations.
 *
 * You never upload multiple sizes — just change the URL.
 *
 * Preset sizes used in PVA:
 *   Thumbnail (listing cards)  : cloudinaryUrl($id, 320,  240)
 *   Card (auctions.php grid)   : cloudinaryUrl($id, 800,  600)
 *   Hero (auction.php detail)  : cloudinaryUrl($id, 1200, 800)
 *   Gallery thumb              : cloudinaryUrl($id, 160,  120)
 *
 * @param  string $publicId  Cloudinary public_id (from auctions.cloudinary_public_id)
 * @param  int    $width     Pixel width
 * @param  int    $height    Pixel height
 * @param  string $crop      Cloudinary crop mode: fill | fit | pad | scale | thumb
 * @return string  Full HTTPS URL
 */
function cloudinaryUrl(string $publicId, int $width = 800, int $height = 600, string $crop = 'fill'): string
{
    return sprintf(
        '%s/c_%s,w_%d,h_%d,q_auto,f_auto/%s',
        CLOUDINARY_BASE_URL,
        $crop,
        $width,
        $height,
        $publicId
    );
}

/**
 * Return a watermarked URL (overlays your logo in the bottom-right corner).
 * Requires you to upload logo.png to Cloudinary once as public_id: 'pva/logo'
 *
 * @param  string $publicId  Vehicle image public_id
 * @param  int    $width
 * @param  int    $height
 * @return string
 */
function cloudinaryWatermarkedUrl(string $publicId, int $width = 800, int $height = 600): string
{
    return sprintf(
        '%s/c_fill,w_%d,h_%d,q_auto,f_auto/l_pva:logo,g_south_east,o_50,w_120/%s',
        CLOUDINARY_BASE_URL,
        $width,
        $height,
        $publicId
    );
}

/**
 * Given an auction row from the DB, return the best available image URL
 * at a given size. Falls back to image_url if no Cloudinary ID is stored.
 *
 * Usage in auction card templates:
 *   <img src="<?= e(auctionImageUrl($auction, 800, 600)) ?>" ...>
 *
 * @param  array  $auction  DB row with keys: cloudinary_public_id, image_url
 * @param  int    $width
 * @param  int    $height
 * @return string
 */
function auctionImageUrl(array $auction, int $width = 800, int $height = 600): string
{
    if (!empty($auction['cloudinary_public_id'])) {
        return cloudinaryUrl($auction['cloudinary_public_id'], $width, $height);
    }

    // Fallback: stored absolute URL or relative path
    $url = $auction['image_url'] ?? '';
    if ($url && !str_starts_with($url, 'http')) {
        $url = APP_URL . '/' . ltrim($url, '/');
    }

    return $url ?: APP_URL . '/assets/no-image.jpg';
}

// ── Naming Helpers ────────────────────────────────────────────

/**
 * Generate a Cloudinary public_id for a new auction main image.
 *
 * Pattern: lot-{auction_id}-{make}-{model-slug}-{year}-main
 * Example: lot-001-toyota-corolla-2022-main
 *
 * @param  int    $auctionId   DB auctions.id
 * @param  string $make        e.g. 'Toyota'
 * @param  string $itemName    e.g. '2022 Toyota Corolla Quest'
 * @param  int    $year        e.g. 2022
 * @return string
 */
function cloudinaryMainId(int $auctionId, string $make, string $itemName, int $year = 0): string
{
    // Strip make + year from item_name to avoid double-prefix
    $slug = $itemName;
    $slug = preg_replace('/\b' . preg_quote($make, '/') . '\b/i', '', $slug);
    $slug = preg_replace('/\b' . preg_quote((string)$year, '/') . '\b/', '', $slug);

    return cloudinarySlug(sprintf(
        'lot-%03d-%s-%s-%d-main',
        $auctionId,
        $make,
        trim($slug),
        $year ?: date('Y')
    ));
}

/**
 * Generate a Cloudinary public_id for a gallery image.
 *
 * Pattern: lot-{auction_id}-{make}-{year}-{sequence:02d}
 * Example: lot-001-toyota-2022-02
 *
 * @param  int    $auctionId
 * @param  string $make
 * @param  int    $year
 * @param  int    $sequence   Gallery position (starts at 2; position 1 = main image)
 * @return string
 */
function cloudinaryGalleryId(int $auctionId, string $make, int $year, int $sequence): string
{
    return cloudinarySlug(sprintf(
        'lot-%03d-%s-%d-%02d',
        $auctionId,
        $make,
        $year,
        $sequence
    ));
}

/**
 * Generate a Cloudinary public_id for an import from the old database.
 *
 * Pattern: import-{old_id}-{make}-main  (main image)
 *          import-{old_id}-{make}-{seq} (gallery)
 *
 * @param  int|string $oldId    Original vehicle ID from old DB
 * @param  string     $make
 * @param  int        $sequence 0 = main image
 * @return string
 */
function cloudinaryImportId(int|string $oldId, string $make, int $sequence = 0): string
{
    $suffix = $sequence === 0 ? 'main' : sprintf('%02d', $sequence);
    return cloudinarySlug("import-{$oldId}-{$make}-{$suffix}");
}

/**
 * Convert any string into a valid Cloudinary public_id segment:
 * lowercase, alphanumeric + hyphens only, no leading/trailing hyphens.
 */
function cloudinarySlug(string $str): string
{
    $str = strtolower($str);
    $str = preg_replace('/[^a-z0-9\/]+/', '-', $str);  // allow / for folder separators
    $str = preg_replace('/-+/', '-', $str);
    return trim($str, '-');
}

// ── Folder Reference (mirrors § 3 of Developer Guide) ────────
/*
 * Cloudinary Folder Structure
 * ───────────────────────────
 * pva/
 * ├── vehicles/
 * │   ├── 2026/
 * │   │   ├── lot-001-toyota-corolla-2022-main   ← main image (stored in auctions.cloudinary_public_id)
 * │   │   ├── lot-001-toyota-corolla-2022-02     ← gallery image (stored in auction_gallery.cloudinary_public_id)
 * │   │   ├── lot-001-toyota-corolla-2022-03
 * │   │   └── lot-002-bmw-320d-2021-main
 * │   └── 2025/
 * │       └── import-423-ford-main               ← migrated from old DB
 * ├── proof-of-payment/                           ← ONLY if truly no sensitive data (prefer local)
 * └── logo                                        ← upload once; used for watermarks
 *
 * URL Examples
 * ────────────
 * Thumbnail (320×240):
 *   https://res.cloudinary.com/{cloud}/image/upload/c_fill,w_320,h_240,q_auto,f_auto/pva/vehicles/2026/lot-001-toyota-corolla-2022-main
 *
 * Card (800×600):
 *   https://res.cloudinary.com/{cloud}/image/upload/c_fill,w_800,h_600,q_auto,f_auto/pva/vehicles/2026/lot-001-toyota-corolla-2022-main
 *
 * Hero (1200×800):
 *   https://res.cloudinary.com/{cloud}/image/upload/c_fill,w_1200,h_800,q_auto,f_auto/pva/vehicles/2026/lot-001-toyota-corolla-2022-main
 *
 * Watermarked (800×600 + logo overlay):
 *   https://res.cloudinary.com/{cloud}/image/upload/c_fill,w_800,h_600,q_auto,f_auto/l_pva:logo,g_south_east,o_50,w_120/pva/vehicles/2026/lot-001-toyota-corolla-2022-main
 */
