# PVA Bidding Fix — Deployment Checklist
## Generated: 08 April 2026

---

## What Was Fixed

### Root Cause
The new `parkvillageauctions_db` schema uses `bid_amount` + `bid_time` column names
in the `bids` table, but `api.php` was written for the old schema which used
`amount` + `created_at`. This caused:
- `place_bid` INSERT to fail silently or throw a PDO exception
- `updates` polling to return no new bids
- `ticker` to show no bid activity
- `user_dashboard` bid history to be empty
- Bid count on `auction.php` always showing 0

### Files Changed (3 total)

| File | What changed |
|------|-------------|
| `api.php` | `place_bid` INSERT now writes to BOTH column variants; `updates`, `ticker`, `get_auction_stats` use COALESCE |
| `auction.php` | Bid query uses COALESCE; broken bid-count logic replaced with single clean query |
| `user_dashboard.php` | Bid history query uses COALESCE for both column names |

---

## Deployment Steps (do in order)

### STEP 1 — Run `bid_schema_fix.sql` in phpMyAdmin

1. Open **phpMyAdmin** → select database `parkvillageauctions_db`
2. Click the **SQL** tab
3. Paste the entire contents of `bid_schema_fix.sql`
4. Click **Go**
5. ✅ Confirm: the verification query at the bottom should return 5 rows showing
   `amount`, `bid_amount`, `bid_time`, `created_at`, `is_winning` all present

> **Why?** The SQL safely adds any missing columns and creates sync triggers so
> inserting into either `amount` or `bid_amount` keeps both in sync automatically.

---

### STEP 2 — Upload `api.php`

Replace the existing `api.php` in your webroot with the new version.

Key changes:
- `place_bid` INSERT: now inserts into `bid_amount, amount, bid_time, created_at`
  (all four columns) — works on any schema variant
- `updates` action: `SELECT COALESCE(b.bid_amount, b.amount) AS amount`
- `ticker` action: same COALESCE fix
- `get_auction_stats`: `SUM(COALESCE(bid_amount, amount))`

---

### STEP 3 — Upload `auction.php`

Replace the existing `auction.php` in your webroot.

Key changes:
- Bid history SELECT uses COALESCE for both column name variants
- `myLastBid` query also uses COALESCE
- Bid count logic completely rewritten — was broken (always returned 0)

---

### STEP 4 — Upload `user_dashboard.php`

Replace the existing `user_dashboard.php` in your webroot.

Key changes:
- Bid history query: `COALESCE(b.bid_amount, b.amount) AS bid_amount`
- Subquery for `top_bid` also uses COALESCE
- Ordering by `b.id DESC` instead of `b.bid_time DESC` (safer if bid_time is null)

---

## Test Flow (after deployment)

1. **Log in** as a user with `bidder_status = 'verified_bidder'`
2. **Go to** an active auction (`auction.php?id=X`)
3. **Verify** the bid panel shows (not the locked message)
4. **Enter** a bid amount ≥ the minimum shown
5. **Click** "Place Bid"
6. ✅ Should see green success message: "Bid placed successfully: R X"
7. ✅ Live feed at bottom should update within 5 seconds
8. ✅ `user_dashboard.php?tab=bids` should show the bid in history

---

## If You Don't Have a Verified Bidder Account Yet

To test bidding without going through the full POP approval flow:

1. In phpMyAdmin, run:
   ```sql
   UPDATE users
   SET bidder_status = 'verified_bidder',
       bidder_number = 'PVA-2026-001'
   WHERE email = 'your-test-email@example.com';
   ```
2. Log out and log back in (forces fresh JWT with new bidder_status)
3. Go to any active auction and test bidding

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| "Unknown column 'bid_amount'" error | `bid_schema_fix.sql` not run yet | Run the SQL in phpMyAdmin |
| Bid panel shows "Login to Bid" when logged in | JWT cookie not being sent | Check browser allows cookies on localhost |
| Bid panel shows "Activate Your Bidder Account" | `bidder_status` ≠ `verified_bidder` | Approve via admin panel or direct SQL update |
| "This auction is not currently active" | Auction `status` = 'upcoming' or 'closed' | Update auction status to 'active' in admin panel |
| Bid placed but feed doesn't update | Polling fetch failing | Open browser DevTools → Network tab → look for `api.php?action=updates` errors |
| Empty bid history in dashboard | Old bids have NULL in COALESCE columns | Run the UPDATE backfill statements in `bid_schema_fix.sql` |
