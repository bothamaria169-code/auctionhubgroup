-- ============================================================
-- Auction Hub Group — switch stored image links from Cloudinary to ImageKit
-- Run in phpMyAdmin ONLY after upload_to_imagekit.php reports
-- "UPLOAD COMPLETE", and after the ImageKit upload code is live
-- (imagekit_helper.php + api.php), so nothing new lands on Cloudinary.
--
-- Vehicle photos, team photos and newsletter images are plain links, so
-- only the host changes:
--   https://res.cloudinary.com/dkrfbur9f/image/upload/v1781045955/pva/vehicles/2026/lot-990-toyota-2021-03.jpg
--   https://ik.imagekit.io/2f8m5mdk5/pva/vehicles/2026/lot-990-toyota-2021-03.jpg
--
-- Testimonials and event banners were stored as Cloudinary crops of large
-- originals, so they get the same crop from ImageKit (as new uploads do):
--   .../image/upload/c_fill,w_800,h_600,q_auto,f_auto/v1782464454/pva/testimonials/x.jpg
--   https://ik.imagekit.io/2f8m5mdk5/tr:w-800,h-600/pva/testimonials/x.jpg
--   .../image/upload/c_fill,w_1920,h_1080,q_auto,f_auto/v1776518495/pva/events/x.jpg
--   https://ik.imagekit.io/2f8m5mdk5/tr:w-1920,h-1080/pva/events/x.jpg
--
-- A link with any other Cloudinary setting is left untouched and shows up
-- in the check at the end. Safe to run twice: links already on ImageKit
-- are skipped and the backups are only taken the first time.
--
-- Undo: the backup tables created in step 1 hold the original links.
-- ============================================================

-- 1. Keep the original Cloudinary links so this can be reversed.
CREATE TABLE IF NOT EXISTS auctions_image_url_backup_20260914 AS
    SELECT id, image_url FROM auctions;
CREATE TABLE IF NOT EXISTS auction_gallery_path_backup_20260914 AS
    SELECT id, file_path FROM auction_gallery;
CREATE TABLE IF NOT EXISTS testimonials_image_url_backup_20260914 AS
    SELECT id, image_url FROM testimonials;
CREATE TABLE IF NOT EXISTS team_members_photo_url_backup_20260914 AS
    SELECT id, photo_url FROM team_members;
CREATE TABLE IF NOT EXISTS auction_events_banner_backup_20260914 AS
    SELECT id, banner_image FROM auction_events;
CREATE TABLE IF NOT EXISTS newsletter_campaigns_html_backup_20260914 AS
    SELECT id, content_html FROM newsletter_campaigns;

-- 2. Plain links: swap the host (MySQL 8 REGEXP_REPLACE). The pattern only
--    matches when the path follows the upload/ or version segment directly,
--    so a link carrying Cloudinary settings is never half-converted.
UPDATE auctions
SET image_url = REGEXP_REPLACE(
        image_url,
        '^https://res\\.cloudinary\\.com/dkrfbur9f/image/upload/(v[0-9]+/)?pva/',
        'https://ik.imagekit.io/2f8m5mdk5/pva/')
WHERE image_url LIKE 'https://res.cloudinary.com/dkrfbur9f/%';

UPDATE auction_gallery
SET file_path = REGEXP_REPLACE(
        file_path,
        '^https://res\\.cloudinary\\.com/dkrfbur9f/image/upload/(v[0-9]+/)?pva/',
        'https://ik.imagekit.io/2f8m5mdk5/pva/')
WHERE file_path LIKE 'https://res.cloudinary.com/dkrfbur9f/%';

UPDATE team_members
SET photo_url = REGEXP_REPLACE(
        photo_url,
        '^https://res\\.cloudinary\\.com/dkrfbur9f/image/upload/(v[0-9]+/)?pva/',
        'https://ik.imagekit.io/2f8m5mdk5/pva/')
WHERE photo_url LIKE 'https://res.cloudinary.com/dkrfbur9f/%';

-- A testimonial or banner saved without its crop (if Cloudinary skipped it)
UPDATE testimonials
SET image_url = REGEXP_REPLACE(
        image_url,
        '^https://res\\.cloudinary\\.com/dkrfbur9f/image/upload/(v[0-9]+/)?pva/',
        'https://ik.imagekit.io/2f8m5mdk5/pva/')
WHERE image_url LIKE 'https://res.cloudinary.com/dkrfbur9f/%';

UPDATE auction_events
SET banner_image = REGEXP_REPLACE(
        banner_image,
        '^https://res\\.cloudinary\\.com/dkrfbur9f/image/upload/(v[0-9]+/)?pva/',
        'https://ik.imagekit.io/2f8m5mdk5/pva/')
WHERE banner_image LIKE 'https://res.cloudinary.com/dkrfbur9f/%';

-- 3. Cropped links: the same crop, served by ImageKit.
UPDATE testimonials
SET image_url = REGEXP_REPLACE(
        image_url,
        '^https://res\\.cloudinary\\.com/dkrfbur9f/image/upload/c_fill,w_800,h_600,q_auto,f_auto/(v[0-9]+/)?pva/',
        'https://ik.imagekit.io/2f8m5mdk5/tr:w-800,h-600/pva/')
WHERE image_url LIKE 'https://res.cloudinary.com/dkrfbur9f/%';

UPDATE auction_events
SET banner_image = REGEXP_REPLACE(
        banner_image,
        '^https://res\\.cloudinary\\.com/dkrfbur9f/image/upload/c_fill,w_1920,h_1080,q_auto,f_auto/(v[0-9]+/)?pva/',
        'https://ik.imagekit.io/2f8m5mdk5/tr:w-1920,h-1080/pva/')
WHERE banner_image LIKE 'https://res.cloudinary.com/dkrfbur9f/%';

-- 4. Newsletter campaigns embed vehicle photos in their HTML (e.g. the unsent
--    "New Auction Event Absa" draft): swap every plain link inside it.
UPDATE newsletter_campaigns
SET content_html = REGEXP_REPLACE(
        content_html,
        'https://res\\.cloudinary\\.com/dkrfbur9f/image/upload/(v[0-9]+/)?pva/',
        'https://ik.imagekit.io/2f8m5mdk5/pva/')
WHERE content_html LIKE '%res.cloudinary.com/dkrfbur9f/%';

-- 5. Check: every still_on_cloudinary count should be 0.
SELECT 'auctions' AS table_name,
       COUNT(CASE WHEN image_url LIKE '%res.cloudinary.com/dkrfbur9f%' THEN 1 END) AS still_on_cloudinary,
       COUNT(CASE WHEN image_url LIKE 'https://ik.imagekit.io/%' THEN 1 END)        AS on_imagekit
FROM auctions
UNION ALL
SELECT 'auction_gallery',
       COUNT(CASE WHEN file_path LIKE '%res.cloudinary.com/dkrfbur9f%' THEN 1 END),
       COUNT(CASE WHEN file_path LIKE 'https://ik.imagekit.io/%' THEN 1 END)
FROM auction_gallery
UNION ALL
SELECT 'testimonials',
       COUNT(CASE WHEN image_url LIKE '%res.cloudinary.com/dkrfbur9f%' THEN 1 END),
       COUNT(CASE WHEN image_url LIKE 'https://ik.imagekit.io/%' THEN 1 END)
FROM testimonials
UNION ALL
SELECT 'team_members',
       COUNT(CASE WHEN photo_url LIKE '%res.cloudinary.com/dkrfbur9f%' THEN 1 END),
       COUNT(CASE WHEN photo_url LIKE 'https://ik.imagekit.io/%' THEN 1 END)
FROM team_members
UNION ALL
SELECT 'auction_events',
       COUNT(CASE WHEN banner_image LIKE '%res.cloudinary.com/dkrfbur9f%' THEN 1 END),
       COUNT(CASE WHEN banner_image LIKE 'https://ik.imagekit.io/%' THEN 1 END)
FROM auction_events
UNION ALL
SELECT 'newsletter_campaigns',
       COUNT(CASE WHEN content_html LIKE '%res.cloudinary.com/dkrfbur9f%' THEN 1 END),
       COUNT(CASE WHEN content_html LIKE '%ik.imagekit.io/%' THEN 1 END)
FROM newsletter_campaigns;

-- ── UNDO (only if needed, straight after running) ───────────
-- Puts every row's old link back, so any image changed since would be lost.
-- UPDATE auctions a JOIN auctions_image_url_backup_20260914 b ON b.id = a.id SET a.image_url = b.image_url;
-- UPDATE auction_gallery g JOIN auction_gallery_path_backup_20260914 b ON b.id = g.id SET g.file_path = b.file_path;
-- UPDATE testimonials t JOIN testimonials_image_url_backup_20260914 b ON b.id = t.id SET t.image_url = b.image_url;
-- UPDATE team_members m JOIN team_members_photo_url_backup_20260914 b ON b.id = m.id SET m.photo_url = b.photo_url;
-- UPDATE auction_events e JOIN auction_events_banner_backup_20260914 b ON b.id = e.id SET e.banner_image = b.banner_image;
-- UPDATE newsletter_campaigns n JOIN newsletter_campaigns_html_backup_20260914 b ON b.id = n.id SET n.content_html = b.content_html;
