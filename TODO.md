# Auction Hub Group — to-do list

Updated 23 Oct 2026. Ticked items are done in the code; "you" items need someone with cPanel/console access.

## 1. Do now (you, in cPanel)

- [ ] **Delete private files from `public_html`.** The manual upload made the customer database dump
      and other private files downloadable. Remove: every `*.sql` file in the site root,
      `Invoice.pdf`, `cipc.pdf`, `gen_hash.php`, `test.php`, `online_swap_vehicle_data.sql`.
      The next git deploy also removes them and blocks them for good.
- [ ] **POPIA:** the database dump (names, emails, phones, ID numbers) was public on 23 Oct 2026.
      Check cPanel → Raw Access logs for downloads of `auctionhubgroupc_db.sql`, and decide with your
      information officer whether to notify the Regulator and the people affected.
- [ ] **Switch to git deploys.** Follow [DEPLOY_CHECKLIST.md](DEPLOY_CHECKLIST.md): connect the repo once, then
      *Update from Remote* → *Deploy HEAD Commit* after every change.
- [ ] **Move `config.secrets.php`** from `public_html` up one folder (to `/home/auctionhubgroupc/`).
- [ ] **Rotate secrets** that were in old files or public git history: `AHG_CRON_KEY`, the database password,
      the `admin@`/`careers@` mailbox passwords, and the Groq, Pexels and Cloudinary keys.
- [ ] **Turn on the cron** (DEPLOY_CHECKLIST → Optional). Without it, lots only close when someone opens them.
- [ ] **Paste the xAI key** in Admin → AI Photos (from console.x.ai) to switch on photo editing.
- [ ] **Sold dates:** Admin → Auctions → Sold → *Spread these dates*. Then add real prices with *Edit Sale*
      where you know them.

## 2. Done in this round

- [x] Photos back on ImageKit; SOLD stamp; sold prices; Recently Sold strip
- [x] Admin: mark as sold, bulk sell/relist/export/delete, status tabs, sales summary, change log
- [x] Bidders: watchlist, alerts bell, My Wins, email settings, saved-search emails
- [x] Auction closing fixed (cron could never run; page views skipped winners; purchase requests crashed)
- [x] Git deploy (`.cpanel.yml` + `deploy.sh`) that also cleans and locks private files
- [x] Sold page: photo galleries, links to each lot, admin edit buttons; date spreading with undo
- [x] Edit Sale works on lots without a price
- [x] Grok (xAI) photo editing: Admin → AI Photos, key pasted in the admin
- [x] Codebase tidy: docs → `docs/`, migrations → `sql/`, one-off tools → `tools/`, dead prototypes removed

## 3. Next features (borrowed from our other sites)

Social-media integration is left out on purpose. Ordered by value for effort.

| # | Feature | What it does | From |
|---|---|---|---|
| 1 | **Price guide** | "What does a 2018 Hilux fetch?" Sold prices by make/model/year. Buyers research before bidding, and it's great SEO. Needs real sold prices (Edit Sale). | bidcotrucks `price-guide.php` |
| 2 | **Auction cost calculator** | On each lot: bid + buyer's fees + VAT = what you actually pay. Fewer surprises at invoice time. | bidcotrucks `includes/auction-calculator.php` |
| 3 | **Finance calculator** | Estimated monthly instalment on the lot page, linking to the financing page. | bidcotrucks `includes/finance-calculator.php` |
| 4 | **Closing soon page** | One shareable link of every lot ending in the next 48 h, with live countdowns. | bidcotrucks `closing-soon.php` |
| 5 | **Continue with Google** | One-tap sign-up; more registrations from ads. | bidcotrucks `google-login.php` |
| 6 | **Google reviews** | Star rating and latest reviews on the homepage, refreshed daily. | bidcotrucks `includes/google-reviews.php` |
| 7 | **Install as an app (PWA)** | Home-screen icon, offline page; groundwork for push notifications of outbids. | bidandwin `manifest.webmanifest`, `sw.js` |
| 8 | **Assisted bidders** | Staff register phone-in customers and enter their bids for them. | bidcotrucks `admin/assisted-bidders.php` |
| 9 | **Price check before publishing** | New stock waits as a draft until a market value and opening bid are set. | bidcotrucks `admin/price-check.php` |
| 10 | **Duplicate finder** | Flags the same vehicle listed twice (same VIN or reg). | bidcotrucks `admin/duplicates.php` |
| 11 | **Sell your vehicle** | Form for owners and dealers to consign stock, landing as an admin enquiry. | bidcotrucks `sell.php` |
| 12 | **Ad catalogue feed** | Product feed for Google/Meta shopping and retargeting ads (ads, not social posting). | bidcotrucks `catalog-feed.php` |
| 13 | **Manual photo editor** | Crop, rotate, brightness and plate blur in the browser, no AI cost. Complements AI Photos. | bidcotrucks `admin/photo-edit.php` |

## 4. Housekeeping

- [ ] `sql/ahg_switch_images_to_imagekit.sql`: rewrite stored Cloudinary links for good (photos already work).
- [ ] Some lot names contain a stray `\/` (e.g. `D\/C`) from an old import; clean with one UPDATE.
- [ ] `cloudinary_helper.php` is now only used for file-name helpers; fold those into `imagekit_helper.php`.
- [ ] `admin_dashboard.php` is over 500 KB in one file; split the tabs into `admin/` includes over time.
