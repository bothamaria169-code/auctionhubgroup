# Deploy checklist — October 2026 release

Takes everything built on 23 Oct 2026 to **auctionhubgroup.co.za**:
image fix, SOLD stamp, sold-vehicle polish, admin sales tools, bidder features
(watchlist, alerts, My Wins, saved searches) and the auction-closing fixes.

Work through the phases **in order**. Allow about an hour, at a quiet time
(not during a live auction). Tick each box as you go.

> **Never upload the whole folder.** Upload only the files listed below.
> Never upload `.git/`, `.claude/`, `.sql` files, `.md` files, `vendor/`,
> `uploads/` or `.htaccess` (the live `.htaccess` has host-specific lines; see Phase 6).

---

## Phase 0 — Back up (10 min)

- [ ] **Database:** cPanel → phpMyAdmin → select `auctionhubgroupc_db` → **Export** → Quick → SQL → Go.
      Save the file on your PC (not on the server).
- [ ] **Files:** cPanel → File Manager → `public_html`. Select every file named in
      Phase 3 → **Compress** → `backup-2026-10-23.zip` → **Download** it, then delete the zip from the server.
- [ ] In File Manager, check `imagekit_helper.php` exists and is dated **21 Sep 2026 or later**.
      If it's older or missing, upload it too in Phase 3, step 2.

---

## Phase 1 — Decide what happens to expired lots (15 min) ⚠️

**Why:** until now no winner was ever processed automatically: the cron script had
a syntax error, and opening an expired lot closed it without picking a winner.
The new code fixes that. On its first run, every lot still marked *live* after its
end time is closed. Each winner whose bid met the reserve is then **marked sold and
emailed "you won"**, even if the auction ended months ago.

- [ ] phpMyAdmin → `auctionhubgroupc_db` → **SQL** tab → paste
      [`ahg_check_expired_lots.sql`](ahg_check_expired_lots.sql). Run queries **1 to 5 one at a time**
      (select the query → **Go**) and note the results. They only read data.
- [ ] Choose, for the lots from query 1:

  | If the lots are… | Do this before Phase 3 |
  |---|---|
  | **Still for sale.** You keep stock "live" and the end dates just ran out. | Admin → **Auctions** → *Set Auction Times (Bulk)* → Apply To **Active Only** → start = now, end = your next closing date (both are required) → **Update Times**. Re-run query 1: `expired_but_live` should be **0**. |
  | **Finished long ago** (bidders shouldn't get a late "you won" email). | Run the **optional UPDATE** at the bottom of the SQL file. It closes them quietly. Afterwards, sell or relist them by hand from Admin → Auctions → **Needs attention**. |
  | **Just ended** (last few days, winners expect to hear). | Leave them. The cron closes them and emails the winners. |

  You can mix these. For example, extend the stock that's still for sale, then
  quietly close the rest that ended more than 7 days ago.
- [ ] Query 5 lists lots the **old** code marked sold whose winner was never emailed.
      Contact those buyers yourself; the new code does not resend them.

---

## Phase 2 — Database changes (5 min)

Both files are safe to run more than once. In phpMyAdmin → **SQL** tab, paste and **Go**:

- [ ] [`ahg_admin_activity_log.sql`](ahg_admin_activity_log.sql): change log for the admin dashboard.
- [ ] [`ahg_user_features.sql`](ahg_user_features.sql): watchlist, alerts, email settings, saved searches.
- [ ] *(Optional, any time later)* [`ahg_switch_images_to_imagekit.sql`](ahg_switch_images_to_imagekit.sql):
      permanently rewrites the old Cloudinary photo links in the database. Photos already work without it,
      because the new `config.php` maps the links on the fly. It backs up the columns first and includes undo statements.

---

## Phase 3 — Upload files (15 min)

cPanel → File Manager → `public_html` → **Upload**. Overwrite when asked.
**Keep this order.** Steps 1 to 3 go back to back, because the new `config.php`
needs the files in steps 1 and 2.

1. [ ] **`config.secrets.php`**: new. It's on your PC in `C:\xampp\htdocs\auctionhubgroup\` (deliberately **not** in GitHub).
   It holds all passwords. Open it first and check the `production` database details are right.
2. [ ] **New helper files:** `user_features.php`, `auction_close.php`, `unsubscribe.php`
3. [ ] **`config.php`**
   - Right after this, open https://auctionhubgroup.co.za. If you see *"Missing config.secrets.php"*,
     step 1 didn't land; re-upload it.
4. [ ] **Core:** `email_helper.php`, `api.php`, `header.php`
5. [ ] **Pages:** `index.php`, `auctions.php`, `auction.php`, `sold_vehicles.php`, `vehicles_api.php`,
   `user_dashboard.php`, `admin_dashboard.php`
6. [ ] **Pages with photo fixes:** `about.php`, `team.php`, `testimonials.php`, `manage_auction.php`,
   `invoice_generator.php`, `invoice_print.php`
7. [ ] **Styles:** `style.css`
8. [ ] **Crons:** `cron_close_auctions.php`, `cron_generate_article.php`, `generate_sitemaps.php`, `set_article_image.php`

> If any page goes white: cPanel → **Errors** (or `public_html/error_log`) shows the cause.
> To roll back, re-upload that file from the Phase 0 backup zip.
> The new database tables don't affect the old files.

---

## Phase 4 — Check the live site (10 min)

Use a private/incognito window for the guest checks.

- [ ] **Homepage:** vehicle photos load. Right-click a photo → *Open image in new tab*; the address
      should start with `https://ik.imagekit.io/2f8m5mdk5/`.
- [ ] **Sold vehicles page:** photos show the red **SOLD** stamp.
- [ ] **A sold lot's page:** "This Vehicle Has Been Sold" panel with the sold price.
- [ ] **Homepage:** *Recently Sold* strip shows (if any lots are sold).
- [ ] **Auctions page as a guest:** tapping ♥ goes to the register page.
- [ ] **Logged in as a bidder:** 🔔 appears in the header. ♥ a vehicle, and it appears under Dashboard →
      **Watchlist**. On the auctions page, filter by a make → **Alert me about new matches** → it shows under
      Dashboard → **Alerts** → *Saved Searches*. Dashboard → My Profile → **Email Settings** saves.
- [ ] **Admin → Auctions:** status tabs with counts. Open **Mark as Sold** on a test lot, then **Cancel**.
- [ ] **Admin → Overview:** *Sales* cards and *Change Log* show. If the Change Log shows a red
      "isn't set up" box, Phase 2 didn't run.
- [ ] cPanel → **Errors**: no new errors from these pages.

---

## Phase 5 — Switch on the cron (10 min)

The cron closes expired lots, processes winners and sends watchlist reminders and
saved-search emails. **Only do this after Phase 1.**

- [ ] cPanel → **Cron Jobs** → *Cron Email*: set it to `admin@auctionhubgroup.co.za` (to see errors).
- [ ] *Add New Cron Job*:
  - **Common settings:** *Once Per Five Minutes*
  - **Command** (one line):

    ```
    /usr/local/bin/php /home/auctionhubgroupc/public_html/cron_close_auctions.php >> /home/auctionhubgroupc/cron_close.log 2>&1
    ```

    If cPanel shows a different PHP path for PHP 8.4 (e.g. `/usr/local/bin/ea-php84`), use that instead.
- [ ] Wait 5 minutes. In File Manager, open `/home/auctionhubgroupc/cron_close.log` (one folder **above**
      `public_html`). The last line should read `Cron complete.` It lists each lot closed, and who it was sold to.
- [ ] Admin → Auctions → **Sold** and **Needs attention** tabs: check the lots look right.
- [ ] **Article cron:** if you already run `cron_generate_article.php` or `generate_sitemaps.php` from cPanel,
      check those jobs still work. They now use the same live/local detection, which fixes them if they ran as commands.

---

## Phase 6 — Security follow-ups (15 min)

Found while preparing this release:

- [ ] **Change `AHG_CRON_KEY`.** Its old value was written in several files that are now on public GitHub.
      Put a new random value in `config.secrets.php` (local and live). Then update any cPanel cron that calls
      a script by URL with `?key=…`. Jobs that run as `/usr/local/bin/php …` don't need a key.
- [ ] **Hide PHP errors from visitors.** The live site currently shows them with the server path
      (e.g. on `cron_close_auctions.php`). cPanel → **MultiPHP INI Editor** → `auctionhubgroup.co.za` →
      `display_errors` **Off** (leave `log_errors` On) → Apply.
- [ ] **Stop folder listings.** https://auctionhubgroup.co.za/uploads/ lists its files publicly.
      Edit the **live** `.htaccess` (don't replace it) and add this near the top:

      ```
      Options -Indexes
      ```

      Reload the `/uploads/` link; it should now say *Forbidden*.
- [ ] **Change passwords** that used to sit in `config.php` and older dumps: database, the `admin@` and
      `careers@` mailboxes, the Groq and Pexels API keys, and the Cloudinary secret.
      Then update `config.secrets.php` on the server.

---

## What's in this release (for reference)

| Area | Files |
|---|---|
| Photos back (Cloudinary → ImageKit mapping) | `config.php`, `api.php`, `vehicles_api.php` + pages in Phase 3 step 6 |
| SOLD stamp, sold prices, Recently Sold | `style.css`, `sold_vehicles.php`, `auctions.php`, `auction.php`, `index.php` |
| Admin: mark sold, bulk tools, tabs, sales, change log | `admin_dashboard.php`, `api.php`, `ahg_admin_activity_log.sql` |
| Bidders: watchlist, alerts, wins, email settings, saved searches | `user_features.php`, `user_dashboard.php`, `header.php`, `unsubscribe.php`, `email_helper.php`, `ahg_user_features.sql` |
| Closing fixes (cron, winners, purchase requests) | `auction_close.php`, `auction.php`, `api.php`, `cron_close_auctions.php`, `config.php` |
| Secrets out of the code | `config.php` + `config.secrets.php` (server only) |
