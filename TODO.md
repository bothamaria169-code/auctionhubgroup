# Auction Hub Group — to-do list

Updated 24 Oct 2026. Ticked items are done in the code; "you" items need someone with cPanel/console access.

## 1. Do now (you, in cPanel)

- [x] **Private files removed from `public_html`** by the git deploy on 23 Oct 2026, and blocked for good
      (`.sql`, `.md`, `.zip`, secrets file all 403/404; folder listings off).
- [ ] **Move `auctionhubgroup.zip` and the `auctionhubgroup/` folder out of `public_html`.** They're blocked (403),
      but a full backup with every password shouldn't sit in the web folder.
- [ ] **POPIA:** the database dump (names, emails, phones, ID numbers) was public on 23 Oct 2026.
      Check cPanel → Raw Access logs for downloads of `auctionhubgroupc_db.sql`, and decide with your
      information officer whether to notify the Regulator and the people affected.
- [x] **Git deploys work** (account `auctionhubgroupc`). After every change: *Update from Remote* → *Deploy HEAD Commit*.
- [ ] **Move `config.secrets.php`** from `public_html` up one folder (to `/home/auctionhubgroupc/`).
- [ ] **Rotate secrets** that were in old files or public git history: `AHG_CRON_KEY`, the database password,
      the `admin@`/`careers@` mailbox passwords, and the Groq, Pexels and Cloudinary keys.
- [ ] **Turn on the cron** (DEPLOY_CHECKLIST → Optional). Without it, lots only close when someone opens them.
- [ ] **Daily sitemap cron:** `generate_sitemaps.php` (cPanel → Cron Jobs, once a day,
      `/usr/local/bin/php /home/auctionhubgroupc/public_html/generate_sitemaps.php`). It also writes `feed.xml`,
      which is currently missing (404); `feed.php` works.
- [ ] **Paste the xAI key** in Admin → AI Photos (from console.x.ai) to switch on photo editing.
- [ ] **Deploy the popup fix** (Update from Remote → Deploy HEAD Commit), then press **Ctrl+F5** once on the
      admin page. Edit Sale, Add floor bidder, Set Times, Winner, Log Out etc. all open again.
- [ ] **Real sale prices:** Admin → Auctions → **Sold** → *Edit Sale* on each lot: price, date, **Sold at**
      (online / live / floor / pre-auction / private) and buyer. The 86 bulk-marked lots also show under *Needs attention*.
- [ ] **Sold dates** you don't know: Sold tab → *Spread these dates* (undo available).
- [ ] **Floor bidders:** Admin → **Manual Bids & Floor** → *Add floor bidder* for each person registered at a
      floor/live auction (name, cellphone, optional email and ID). Then *Bid* next to them to enter their bids.

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
- [x] Edit Sale: *Sold at* channel (online/live/floor/pre-auction/private), buyer can be a floor bidder,
      errors shown inside the popup; bulk Mark Sold sets the channel too
- [x] Floor & phone bidders (Admin → Manual Bids & Floor): create/edit name, cellphone, email, ID, bidder no.;
      verified at once; can't log in or reset a password; never emailed; one-click *Bid* for them
- [x] **Admin popups fixed:** every popup (14 in the admin, plus the user dashboard's) opened invisible and
      unclickable since before Oct 2026, because CSS hid `.modal-overlay` unless it had the `open` class. Popups
      shown with `display:flex` now count as open; `openModal()` sets the class too. Page titles on the blue
      headers are white again
- [x] **Live Bids** (bottom-centre pill between WhatsApp and the chatbot): cycles the latest real bids; tap for a
      centred panel (bottom sheet on phones) with Latest bids · Ending soon · Most bid, live countdowns, refresh every
      20 s, can be hidden. Bidder numbers masked (••042), no names. Not on admin/account/checkout pages
- [x] Admin menu grouped (Dashboard · Sales & Auctions · Bidders & Payments · Marketing & Content · People),
      same groups on phones; user menu grouped (Bidding · My Account)

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
| 8 | ~~Assisted bidders~~ | Done 24 Oct as *Floor & phone bidders*. | bidcotrucks `admin/assisted-bidders.php` |
| 9 | **Price check before publishing** | New stock waits as a draft until a market value and opening bid are set. | bidcotrucks `admin/price-check.php` |
| 10 | **Duplicate finder** | Flags the same vehicle listed twice (same VIN or reg). | bidcotrucks `admin/duplicates.php` |
| 11 | **Sell your vehicle** | Form for owners and dealers to consign stock, landing as an admin enquiry. | bidcotrucks `sell.php` |
| 12 | **Ad catalogue feed** | Product feed for Google/Meta shopping and retargeting ads (ads, not social posting). | bidcotrucks `catalog-feed.php` |
| 13 | **Manual photo editor** | Crop, rotate, brightness and plate blur in the browser, no AI cost. Complements AI Photos. | bidcotrucks `admin/photo-edit.php` |

## 4. User dashboard: ideas to make bidding easier

Ordered by how much they help a bidder, for the effort.

| # | Idea | Why it helps |
|---|---|---|
| 1 | **Quick re-bid on Outbid cards** (+R500 / +R1,000 buttons with a confirm) | Bid again from the dashboard without opening the lot |
| 2 | **Registration stepper** on Overview: account → deposit paid → POP uploaded → bidder number | New users always know the one next step to start bidding |
| 3 | **Deposit & refund tracker** (R10,000 status: paid / approved / refund requested / refunded) | Cuts the "where is my deposit?" calls and emails |
| 4 | **Collection tracker on My Wins** (invoiced → paid → cleared → ready → collected; staff tick it in admin) | Buyers see where their vehicle is without phoning |
| 5 | **Documents** tab: invoices, POP uploads, signed contracts in one place | No digging through email |
| 6 | **Max bid / auto-bid** (system bids for you up to your limit) | Wins lots while you're busy; standard on auction sites. Bigger build |
| 7 | **"Add to calendar"** for watched lots and auction days (.ics) | Fewer missed endings |
| 8 | **WhatsApp alerts (opt-in)** for outbid and ending soon | Faster than email; most SA bidders live on WhatsApp. Needs a WhatsApp Business API account |
| 9 | **FICA uploads** (ID, proof of address) in My Profile | Compliance done once, before the first win |
| 10 | **Phone bottom bar** (Bids · Watchlist · Alerts · Wins) | One-thumb navigation on mobile |

## 5. Housekeeping

- [ ] `sql/ahg_switch_images_to_imagekit.sql`: rewrite stored Cloudinary links for good (photos already work).
- [ ] Some lot names contain a stray `\/` (e.g. `D\/C`) from an old import; clean with one UPDATE.
- [ ] `cloudinary_helper.php` is now only used for file-name helpers; fold those into `imagekit_helper.php`.
- [ ] `admin_dashboard.php` is over 500 KB in one file; split the tabs into `admin/` includes over time.
