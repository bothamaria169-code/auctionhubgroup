# Google Ads + SEO — what changed and what to deploy

Companion to the **AHG Google Ads Launch Pack** artifact (all ad copy, keywords,
image specs and the launch order live there). This file is the deployment side:
which files to upload, what to paste into `config.php`, and what to run once.

---

## 1. Files to upload

### New files
| File | What it does |
|---|---|
| `register-to-bid.php` | The Google Ads landing page. All paid traffic lands here. |
| `tracking_helper.php` | Captures `gclid`/UTM on first hit, builds WhatsApp links, gates the tags. |
| `vehicle_strip.php` | Renders live-stock strips inside articles; also owns `lotTitle()`. |
| `ahg_ads_attribution.sql` | Adds `acq_*` columns to `users`. Run once in phpMyAdmin. |
| `ahg_update_reg_fee.sql` | Moves the registration deposit to R10,000. **Must run** — see §3. |
| `google-ads-pack/index.html` | Offline copy of the launch pack. Open `http://localhost/park-villageauctions/google-ads-pack/`. Do not upload to production. |
| `assets/no-image.jpg` | Placeholder that four pages already referenced but was never shipped. |

### Modified files
| File | Change |
|---|---|
| `config.php` | Google Ads + Clarity + WhatsApp constants. **Fill these in — see §2.** |
| `header.php` | **Google Tag Manager** (head + noscript), GA4 + Google Ads gtag, Clarity, sitewide Organization/AutoDealer schema, attribution capture, dataLayer seed. |
| `footer.php` | Floating button is now WhatsApp-first (+27 83 670 9292) with conversion reporting. |
| `style.css` | Styles for the WhatsApp label row. |
| `auth.php` | Stamps the ad click onto the new user row; returns a `conversion` payload. |
| `login.php` | Fires the signup conversion (gtag + dataLayer); title de-branded. |
| `sitemap.php` | Vehicle query no longer dies on missing `updated_at`; adds the landing page. |
| `auction.php` | Adds `seller` to the offer + BreadcrumbList schema. |
| `auctions.php` | ItemList + BreadcrumbList schema; de-duplicated lot titles; new title/description. |
| `news.php`, `news-post.php` | Live-stock strips; sidebar CTA now points at the landing page. |
| `about.php`, `careers.php`, `index.php`, `sold_vehicles.php`, `tag.php`, `team.php`, `testimonials.php`, `sign_contract.php` | Title tag no longer ships the brand name twice. |

---

## 2. Constants to fill in `config.php`

Everything stays dormant while these hold their placeholder values — no tags
render, no conversions fire, nothing breaks. Paste real values to switch it on.

```php
define('GA_MEASUREMENT_ID', 'G-XXXXXXXXXX');   // GA4 → Admin → Data Streams
define('GOOGLE_ADS_ID',     'AW-XXXXXXXXX');   // Ads account tag ID

// From each conversion action's tag: 'AW-123456789/AbC-D_efG'
// Paste ONLY the part after the slash.
define('GADS_LABEL_REGISTRATION', 'XXXXXXXXXXXXXXXXXX');  // primary
define('GADS_LABEL_WHATSAPP',     'XXXXXXXXXXXXXXXXXX');
define('GADS_LABEL_CALL',         'XXXXXXXXXXXXXXXXXX');

define('CLARITY_PROJECT_ID', '');   // optional, free heatmaps
```

Already set, no action needed:

```php
define('GTM_CONTAINER_ID', 'GTM-N5LK7BDP');
```

### Google Tag Manager — pick one measurement path

GTM is installed sitewide via `header.php`, and the site pushes every conversion
to the dataLayer as well as through gtag:

| dataLayer event | Fires when | Carries |
|---|---|---|
| `ahg_registration` | A new bidder account is created | `signup_method`, `value`, `currency`, `transaction_id` |
| `ahg_contact_click` | WhatsApp / call / email CTA clicked | `contact_channel` |

Every page also seeds the dataLayer before GTM loads with `page_type`,
`logged_in`, `bidder_status`, `traffic_source`, `campaign` and `keyword` — so
GTM triggers and audiences can use them from the first pageview.

**Choose one route and stick to it:**

- **Direct** (current, nothing more to do) — leave `GA_MEASUREMENT_ID` and
  `GOOGLE_ADS_ID` filled in, and add **no** GA4 or Ads conversion tags inside GTM.
  Use the container for other vendors only.
- **Via GTM** — blank both constants, then build the GA4 config tag and the Ads
  conversion tags in GTM off the dataLayer events above.

Doing both fires two GA4 configs per pageview and two conversion tags per signup.
Conversion counts and cost-per-registration would both be wrong by about double.

GTM does not load on localhost unless you append `?gtm_debug=1`, so GTM Preview
still works locally without polluting live data.

Already correct, no action needed:

```php
define('COMPANY_WHATSAPP',      '+27 83 670 9292');
define('COMPANY_WHATSAPP_INTL', '27836709292');
define('GADS_REGISTRATION_VALUE', 350);   // what one signup is worth, for Smart Bidding
```

---

## 3. Run once, on production

```
https://auctionhubgroup.co.za/generate_sitemaps.php?key=<AHG_CRON_KEY>
```

Then add it to cPanel cron, daily.

This is the single highest-impact item on the list. Right now the live
`sitemap.xml` is a stale static file listing 14 URLs — no articles, no vehicles.
`sitemap.php` generates 117 URLs including all 88 articles, but Apache serves the
static file whenever it exists, so the generator's output never reaches Google.
`news-sitemap.xml` and `feed.xml` currently 404, and robots.txt points Google at
one of them. Running the generator writes all three as real files, which also
sidesteps the fact that the `.htaccess` rewrites are not applying on this host.

### Then import both SQL files in phpMyAdmin

`ahg_update_reg_fee.sql` **first**. The registration deposit moved from R5,000 to
R10,000, and the `site_settings` table overrides the `REG_FEE_*` constants in
`config.php` — so uploading the PHP alone leaves the live site still quoting
R5,000 on the dashboard, the footer and the bank-details panel.

Then `ahg_ads_attribution.sql`, so new bidder rows record which keyword produced
them.

---

## 4. Verifying it works

| Check | How |
|---|---|
| Tags render | View source on the live site, search for `gtag/js` and `GTM-N5LK7BDP`. Nothing renders on localhost by design. |
| GTM container loads | GTM → Preview, enter the live URL. Container Loaded should fire and the dataLayer should show `page_type` and `traffic_source`. |
| Conversion fires | Register a test bidder with `?gclid=test123` on the URL, then check Ads → Conversions (up to 3 h delay). |
| Attribution lands | `SELECT full_name, acq_source, acq_term FROM users ORDER BY id DESC LIMIT 5;` |
| Deposit reads R10,000 | Check the footer, `login.php` and the dashboard bank panel. If any still say R5,000, the SQL did not run. |
| Sitemap is real | `curl https://auctionhubgroup.co.za/sitemap.xml \| grep -c '<url>'` should be in the hundreds, not 14. |
| Schema is valid | Paste an `auction.php?id=` URL into Google's Rich Results Test. |

---

## 5. Two things deliberately left alone

**Article URLs are still `news-post.php?slug=…`.** Pretty URLs would rank better,
but changing them means 301-redirecting 88 already-indexed URLs, and the
`.htaccess` rewrites are not currently applying on this host — so the rewrite
would have to be proven working first. Worth doing, but as its own piece of work.

**The in-house finance offer stays out of the ads.** "No credit checks" and
"blacklisted welcome" on `financing.php` fall under Google's financial-services
policy and can get an entire account suspended, not just one ad. If you want to
advertise finance, give it a separate campaign and its own landing page so a
disapproval cannot take the auction campaign down with it.
