# Auction Hub Group — README

**Version:** 1.0.0  
**Stack:** PHP 8.1+ · MySQL 8.0+ · PHPMailer · JWT (custom) · Vanilla JS  
**Hosting:** cPanel shared hosting (tested on LiteSpeed / Apache)

---

## Repository layout & deploying (Oct 2026)

| Path | What |
|---|---|
| site root | The PHP site itself (deployed to `public_html`) |
| `sql/` | Database migrations. Deployed but locked (careers code reads one); the newest tables create themselves via `db_schema.php` |
| `docs/` | Internal guides (Google Ads, article automation, old checklists). **Not** deployed |
| `tools/` | One-off command-line scripts (Cloudinary → ImageKit migration). **Not** deployed |
| `private/` | Your local dumps, invoices, dev scripts. Git-ignored; never deployed |
| `config.secrets.php` | All passwords and keys. Git-ignored; lives on the server one folder above `public_html` |

**Deploy with git, never by uploading this folder.** See [DEPLOY_CHECKLIST.md](DEPLOY_CHECKLIST.md) and the
open items in [TODO.md](TODO.md).

---

## Table of Contents

1. [Folder Structure](#folder-structure)
2. [File Reference](#file-reference)
3. [First-Time Setup](#first-time-setup)
4. [Configuration Checklist](#configuration-checklist)
5. [Database Setup](#database-setup)
6. [PHPMailer / Composer Setup](#phpmailer--composer-setup)
7. [Upload Directory Permissions](#upload-directory-permissions)
8. [CSV Import Format](#csv-import-format)
9. [Admin Account](#admin-account)
10. [Deployment to cPanel](#deployment-to-cpanel)
11. [Security Notes](#security-notes)
12. [Troubleshooting](#troubleshooting)

---

## Folder Structure

```
auctionhubgroup.co.za/          ← web root (public_html on cPanel)
│
├── index.php                        ← Homepage
├── login.php                        ← Login + Register (tabbed)
├── logout.php                       ← Clears JWT cookie + session
├── auctions.php                     ← Browse / filter all auctions
├── auction.php                      ← Single auction + live bidding
├── user_dashboard.php               ← Bidder dashboard (bids, POP, profile)
├── admin_dashboard.php              ← Admin control panel
├── manage_auction.php               ← Add / edit a single vehicle
│
├── api.php                          ← AJAX hub (all fetch() calls route here)
├── auth.php                         ← JWT register / login / logout / check
├── process_auction.php              ← Form POST handler (add, edit, delete, CSV import)
├── email_helper.php                 ← PHPMailer wrappers for all email triggers
├── config.php                       ← DB · SMTP · JWT · app constants
│
├── header.php                       ← Shared <head> + navbar partial
├── footer.php                       ← Shared footer + WhatsApp buttons + scripts partial
│
├── style.css                        ← Single unified stylesheet (PVA brand)
├── app.js                           ← Single unified JS file (all modules)
├── logo.png                         ← PVA logo (used in navbar + emails)
│
├── park_village_auctions.sql        ← Full database schema + seed data
│
├── .htaccess                        ← URL rules, security headers, PHP settings
│
├── vendor/                          ← Composer packages (NOT committed to git)
│   └── phpmailer/
│       └── phpmailer/
│           └── src/
│               ├── PHPMailer.php
│               ├── SMTP.php
│               └── Exception.php
│   └── autoload.php                 ← Composer autoloader (required by config.php)
│
└── uploads/                         ← All user-uploaded files (NOT committed to git)
    ├── vehicles/                    ← Auction vehicle images (jpg, png, webp)
    ├── proof_of_payment/            ← Bidder POP uploads (jpg, png, pdf)
    └── csv_imports/                 ← Admin CSV imports (archived after processing)
```

---

## File Reference

| File | Type | Purpose |
|---|---|---|
| `index.php` | Page | Homepage — hero, live auctions, how it works, CTA |
| `login.php` | Page | Login + registration tabs, JWT auth, EFT info |
| `logout.php` | Page | Invalidates JWT, destroys session, redirects |
| `auctions.php` | Page | Browse + filter all auctions, pagination |
| `auction.php` | Page | Single vehicle listing + live bidding panel |
| `user_dashboard.php` | Page | Bidder stats, bid history, POP upload, profile |
| `admin_dashboard.php` | Page | POP approvals, auction/user management, email, CSV import |
| `manage_auction.php` | Page | Add or edit a single vehicle (gallery, pricing, timing) |
| `api.php` | AJAX | All `fetch()` endpoints — bids, updates, admin actions |
| `auth.php` | Backend | JWT generation, verification, register/login/logout actions |
| `process_auction.php` | Backend | Form POST handler — add, edit, delete auction; CSV import |
| `email_helper.php` | Backend | PHPMailer wrappers — welcome, bid confirm, outbid, POP alerts |
| `config.php` | Backend | DB credentials, SMTP settings, JWT secret, upload paths |
| `header.php` | Partial | `<head>` tags, sticky navbar, ticker bar, flash messages |
| `footer.php` | Partial | Footer columns, WhatsApp floats, `<script>` tags |
| `style.css` | Asset | Full stylesheet — CSS variables, responsive, all components |
| `app.js` | Asset | All JS modules — bidding, timers, gallery, AJAX, admin tools |
| `logo.png` | Asset | PVA logo image |
| `park_village_auctions.sql` | DB | Schema + seed data (makes, admin user, announcements) |

---

## First-Time Setup

### Step 1 — Upload files

Don't upload this folder by hand: it holds files that must never be public. Connect cPanel → Git Version Control to the GitHub repo and deploy as described in [DEPLOY_CHECKLIST.md](DEPLOY_CHECKLIST.md).

### Step 2 — Create the database

1. Log into cPanel → **MySQL Databases**
2. Create a new database: `park_village_auctions`
3. Create a database user and set a strong password
4. Add the user to the database with **All Privileges**
5. Go to **phpMyAdmin**, select the database, click **Import**, and upload `park_village_auctions.sql`

### Step 3 — Install PHPMailer

SSH into your server or use cPanel Terminal:

```bash
cd public_html
composer require phpmailer/phpmailer
```

If Composer is not available on your host, download PHPMailer manually:

1. Download from https://github.com/PHPMailer/PHPMailer
2. Extract and place the `src/` folder at `vendor/phpmailer/phpmailer/src/`
3. Create `vendor/autoload.php` manually — see [PHPMailer / Composer Setup](#phpmailer--composer-setup) below

### Step 4 — Edit config.php

Passwords and API keys are not in `config.php` any more. Copy `config.secrets.example.php` to `config.secrets.php`, fill it in, and put it one folder above `public_html`. Other settings are in `config.php`; see [Configuration Checklist](#configuration-checklist). The xAI (Grok) key for AI Photos is pasted in Admin → AI Photos instead.

### Step 5 — Set folder permissions

```bash
chmod 755 uploads/
chmod 755 uploads/vehicles/
chmod 755 uploads/proof_of_payment/
chmod 755 uploads/csv_imports/
```

Or set via cPanel → File Manager → right-click folder → Permissions → 755.

### Step 6 — Log in as admin

Navigate to `https://auctionhubgroup.co.za/login.php` and log in with:

- **Email:** `info@auctionhubgroup.co.za`
- **Password:** `PVAadmin2026!`

**Change the admin password immediately** from the Profile tab in the admin dashboard.

---

## Configuration Checklist

Open `config.php` and replace every value marked below:

```php
// ── Database ─────────────────────────────────────────────────
define('DB_NAME', 'park_village_auctions');   // ✅ keep as-is if DB name matches
define('DB_USER', 'your_db_user');            // ⚠️ REPLACE — cPanel DB username
define('DB_PASS', 'your_db_password');        // ⚠️ REPLACE — cPanel DB password

// ── SMTP ─────────────────────────────────────────────────────
define('MAIL_PASSWORD', 'your_email_password'); // ⚠️ REPLACE — cPanel email password

// ── JWT ──────────────────────────────────────────────────────
define('JWT_SECRET', 'REPLACE_WITH_64_CHAR_RANDOM_STRING_GENERATED_BY_PHP'); // ⚠️ REPLACE

// ── Bank Details ─────────────────────────────────────────────
define('BANK_ACCOUNT_NO',  '62 0000 00000');  // ⚠️ REPLACE — real FNB account number
define('BANK_BRANCH_CODE', '250 555');        // ⚠️ REPLACE — real branch code

// ── App URL ───────────────────────────────────────────────────
define('APP_URL', 'https://auctionhubgroup.co.za'); // ✅ confirm domain is correct
```

**Generate your JWT secret** (run once via cPanel Terminal or SSH):

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Paste the output as the `JWT_SECRET` value. It must be at least 64 characters.

**Enable HTTPS cookie** once your SSL certificate is active:

```php
// In config.php:
ini_set('session.cookie_secure', 1);   // line ~12

// In auth.php setTokenCookie():
'secure' => true,                       // line ~148
```

---

## Database Setup

The `park_village_auctions.sql` file creates all tables and seeds essential data.

### Tables created

| Table | Purpose |
|---|---|
| `users` | All accounts — bidders + admins |
| `auctions` | Vehicle listings with pricing + timing |
| `bids` | Every bid placed, with winning flag |
| `auction_gallery` | Gallery images per auction (sort order + primary flag) |
| `vehicle_makes` | 47 pre-loaded makes (Toyota, BMW, Mercedes, etc.) |
| `bidder_registrations` | EFT proof of payment submissions |
| `news_announcements` | Ticker bar messages managed by admin |
| `csv_import_log` | Audit trail of bulk vehicle imports |

### After import — verify seed data

Run these in phpMyAdmin to confirm the schema loaded correctly:

```sql
SELECT COUNT(*) FROM vehicle_makes;       -- Should return 47
SELECT email, role FROM users;            -- Should show admin account
SELECT * FROM news_announcements;         -- Should show 6 default messages
```

---

## PHPMailer / Composer Setup

### Option A — Composer (recommended)

```bash
# SSH or cPanel Terminal
cd public_html
composer require phpmailer/phpmailer
```

This creates `vendor/autoload.php` which `config.php` requires automatically.

### Option B — Manual install (no Composer)

1. Download PHPMailer: https://github.com/PHPMailer/PHPMailer/archive/refs/heads/master.zip
2. Extract and copy the `src/` folder to:
   ```
   public_html/vendor/phpmailer/phpmailer/src/
   ```
3. Create `public_html/vendor/autoload.php` with this content:

```php
<?php
// Manual autoloader — PHPMailer only
spl_autoload_register(function ($class) {
    $map = [
        'PHPMailer\\PHPMailer\\PHPMailer'  => __DIR__ . '/phpmailer/phpmailer/src/PHPMailer.php',
        'PHPMailer\\PHPMailer\\SMTP'       => __DIR__ . '/phpmailer/phpmailer/src/SMTP.php',
        'PHPMailer\\PHPMailer\\Exception'  => __DIR__ . '/phpmailer/phpmailer/src/Exception.php',
    ];
    if (isset($map[$class])) require_once $map[$class];
});
```

---

## Upload Directory Permissions

The `uploads/` folder and its subdirectories must be writable by the web server.

```
uploads/                  → 755
uploads/vehicles/         → 755
uploads/proof_of_payment/ → 755
uploads/csv_imports/      → 755
```

These directories are created automatically by `config.php` if they don't exist, but permissions must be set manually on cPanel shared hosting.

### Protect uploads from direct PHP execution

Create `uploads/.htaccess` with this content to prevent anyone from uploading and executing a PHP shell:

```apache
# Deny execution of PHP files inside /uploads
<FilesMatch "\.(php|php3|php4|php5|phtml|pl|py|cgi|sh)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

Options -Indexes
```

---

## CSV Import Format

Used in the admin dashboard → Import tab to bulk-upload vehicle listings.

### Column headers (case-insensitive, order-independent)

| Column | Required | Example | Notes |
|---|---|---|---|
| `item_name` | ✅ Yes | `2019 Toyota Hilux 2.8 GD-6` | Full vehicle title |
| `make_name` | No | `Toyota` | Auto-created if not found |
| `year` | No | `2019` | |
| `mileage` | No | `87500` | Numeric, km |
| `fuel_type` | No | `Diesel` | Petrol / Diesel / Hybrid / Electric |
| `transmission` | No | `Automatic` | Automatic / Manual |
| `color` | No | `White` | |
| `body_type` | No | `Double Cab` | |
| `engine_size` | No | `2.8L` | |
| `vin_number` | No | `AHTEX22G702038123` | |
| `condition_grade` | No | `4` | 1–5 scale |
| `description` | No | `Full service history...` | Long text OK |
| `service_history` | No | `Full` | Full / Partial / None |
| `start_price` | ✅ Yes | `150000` | Strips R and commas automatically |
| `reserve_price` | ✅ Yes | `185000` | |
| `min_increment` | No | `500` | Defaults to R500 if blank |
| `start_time` | No | `2026-05-01 09:00:00` | MySQL datetime format |
| `end_time` | No | `2026-05-01 17:00:00` | |
| `status` | No | `upcoming` | upcoming / active / closed / draft |
| `image_url` | No | `uploads/vehicles/hilux.jpg` | Relative path from web root |
| `gallery_images` | No | `uploads/vehicles/img1.jpg\|img2.jpg` | Pipe-separated `\|` paths |

### Sample CSV row

```csv
item_name,make_name,year,mileage,fuel_type,transmission,start_price,reserve_price,image_url
2019 Toyota Hilux 2.8 GD-6,Toyota,2019,87500,Diesel,Automatic,150000,185000,uploads/vehicles/hilux.jpg
2021 BMW 3 Series 320i,BMW,2021,42000,Petrol,Automatic,320000,380000,uploads/vehicles/bmw320i.jpg
```

Upload vehicle images to `uploads/vehicles/` **before** importing the CSV, then reference them by their relative path in the `image_url` column.

---

## Admin Account

The SQL seed creates one admin account:

| Field | Value |
|---|---|
| Email | `info@auctionhubgroup.co.za` |
| Password | `PVAadmin2026!` |
| Role | `admin` |

**Change this password immediately after first login** via Admin Dashboard → (profile dropdown top-right) → Profile.

To create additional admin accounts, update the `role` column directly in the database:

```sql
UPDATE users SET role = 'admin' WHERE email = 'new-admin@example.com';
```

---

## Deployment to cPanel

### Full deployment checklist

- [ ] Files uploaded to `public_html/` matching the folder structure above
- [ ] `park_village_auctions.sql` imported into MySQL via phpMyAdmin
- [ ] `config.php` updated — DB credentials, SMTP password, JWT secret, bank details
- [ ] `vendor/` folder present (Composer installed or manual PHPMailer placed)
- [ ] `uploads/` directories exist with `755` permissions
- [ ] `uploads/.htaccess` created to block PHP execution in uploads folder
- [ ] SSL certificate active on domain (free via cPanel → Let's Encrypt)
- [ ] `session.cookie_secure` and JWT `'secure' => true` enabled in config after SSL
- [ ] Admin default password changed
- [ ] Test: register a user, upload POP, approve as admin, place a bid

### Recommended `.htaccess` for web root

Create `public_html/.htaccess`:

```apache
# Force HTTPS
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Remove .php extension from URLs (optional)
# RewriteCond %{REQUEST_FILENAME} !-d
# RewriteCond %{REQUEST_FILENAME}.php -f
# RewriteRule ^(.*)$ $1.php [L]

# Protect sensitive files from direct access
<FilesMatch "^(config|auth|email_helper|process_auction)\.php$">
    Order Allow,Deny
    Deny from all
    Allow from 127.0.0.1
</FilesMatch>

# PHP settings for uploads
php_value upload_max_filesize 10M
php_value post_max_size 12M
php_value max_execution_time 120
php_value memory_limit 256M

# Security headers
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set Referrer-Policy "strict-origin-when-cross-origin"

# Hide server info
ServerSignature Off
```

> **Note:** The `<FilesMatch>` block above blocks direct browser access to backend PHP files. They still work normally when called via `require_once` from other PHP files.

---

## Security Notes

**JWT Secret** — Generate a unique 64-character secret per deployment. Never reuse across projects. Changing it invalidates all active sessions site-wide.

**Passwords** — All user passwords are hashed with `password_hash()` using `PASSWORD_BCRYPT`. Never stored in plain text.

**SQL Injection** — All DB queries use PDO prepared statements. No raw user input reaches SQL.

**XSS** — All output is wrapped in the `e()` helper (`htmlspecialchars`). Never use `echo $_POST[...]` directly in HTML.

**File Uploads** — MIME type is verified server-side using `finfo`, not just the file extension. PHP execution inside `uploads/` is blocked by `.htaccess`.

**JWT Revocation** — The `jwt_version` column in `users` is incremented on logout and on account suspend. Any token with a mismatched version is rejected immediately, even if not yet expired.

**Admin-only endpoints** — All admin actions in `api.php` and `process_auction.php` check `$user['role'] === 'admin'` after verifying the JWT. There is no front-end-only gate.

---

## Troubleshooting

**Blank page / 500 error**
Enable PHP error display temporarily in `config.php` top:
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```
Remove before going live.

**"Database connection failed"**
Check `DB_USER`, `DB_PASS`, `DB_NAME` in `config.php`. On cPanel the username is often prefixed, e.g. `cpanelusername_dbuser`.

**Emails not sending**
Verify `MAIL_PASSWORD` in `config.php`. Test SMTP credentials in cPanel → Email Accounts. Ensure port 465 with SSL is not blocked by your host's firewall.

**Uploads not saving**
Check that `uploads/vehicles/` exists and is writable (`chmod 755`). Check `upload_max_filesize` and `post_max_size` in `.htaccess` or `php.ini`.

**JWT "session expired" on every page load**
Your `JWT_SECRET` may have changed. All active tokens are immediately invalidated. Users must log in again. This also happens if two versions of `config.php` with different secrets are deployed simultaneously.

**CSV import — rows skipped**
Check the error detail modal in Admin → Import. Common causes: missing `item_name`, price values formatted as `R 150,000.00` (the importer strips R and commas, but check for extra spaces), or `end_time` before `start_time`.

---

*Auction Hub Group — 80 Sterling Rd, Kosmosdal, Centurion, 0187 · info@auctionhubgroup.co.za · +27 10 516 0683 / +27 10 516 0681*
