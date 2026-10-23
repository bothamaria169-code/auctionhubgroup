<?php
/**
 * One-off: build small, optimised navbar logos from the 2.1 MB logo.png.
 * Produces logo-nav.webp + logo-nav.png at 144px tall (2x of the 72px display).
 * Run once:  php optimize_logo.php   then it can be deleted.
 */
$src = __DIR__ . '/logo.png';
$targetH = 144;

$im = imagecreatefrompng($src);
$w = imagesx($im); $h = imagesy($im);
$newW = (int)round($w * ($targetH / $h));

$out = imagecreatetruecolor($newW, $targetH);
imagealphablending($out, false);
imagesavealpha($out, true);
$transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
imagefill($out, 0, 0, $transparent);
imagecopyresampled($out, $im, 0, 0, 0, 0, $newW, $targetH, $w, $h);

imagepng($out, __DIR__ . '/logo-nav.png', 9);
imagewebp($out, __DIR__ . '/logo-nav.webp', 82);

echo "Source : {$w}x{$h}\n";
echo "Output : {$newW}x{$targetH}\n";
echo "logo-nav.png : " . round(filesize(__DIR__ . '/logo-nav.png') / 1024, 1) . " KB\n";
echo "logo-nav.webp: " . round(filesize(__DIR__ . '/logo-nav.webp') / 1024, 1) . " KB\n";
