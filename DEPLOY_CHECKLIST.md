# Deploying with Git

The live site pulls code straight from GitHub
(`bothamaria169-code/auctionhubgroup`) using cPanel's **Git Version Control**.
No more uploading files one by one.

What the deploy does by itself:
- **Copies the code into `public_html`.** It never deletes anything, so `uploads/`, `vendor/` and live-only files are safe.
- **Skips `.htaccess`, docs, SQL files and dev files.**
- **Removes private files an old upload left behind** (database dumps, invoices, dev scripts) and lists each one it removed.
- **Locks down the live `.htaccess` once.** It adds a marked block that turns off folder listings and blocks `.sql`, `.md`, the secrets file and similar. The original is backed up first; your existing lines aren't touched.
- **Refuses to run if the passwords file is missing**, so it can't break the site.
- **New database tables create themselves.** There's no SQL to run.
- **Turns off public PHP error messages** (via `.user.ini`).

---

## One-time setup (about 15 minutes)

1. **Back up.** cPanel → **phpMyAdmin** → `auctionhubgroupc_db` → **Export** → Go. Keep the file on your PC.
   The first deploy replaces every code file with the GitHub version, so also do this:
   **File Manager** → select `public_html` → **Compress** → download the zip.

2. **Put the passwords file on the server (once).**
   **File Manager** → click **Home** (the folder *above* `public_html`) → **Upload**
   `C:\xampp\htdocs\auctionhubgroup\config.secrets.php`.
   It never goes to GitHub, and a deploy never touches it.

3. **Connect the repo.** cPanel → **Git™ Version Control** → **Create**:
   - *Clone a Repository*: **on**
   - *Clone URL*: `https://github.com/bothamaria169-code/auctionhubgroup.git`
   - *Repository Path*: `repositories/auctionhubgroup`
   - *Repository Name*: `auctionhubgroup`
   - Click **Create**.

4. **Deploy.** Next to the repo click **Manage** → **Pull or Deploy** tab → **Deploy HEAD Commit**.
   Under *Last Deployment Information* you should see **Deploy complete.**
   If it says *STOPPED: config.secrets.php not found*, redo step 2.

5. **Quick check** (private window): the homepage shows vehicle photos; a sold car shows the red **SOLD** stamp;
   you can log in and open your dashboard.

## If "Deploy HEAD Commit" is greyed out

cPanel shows *The system cannot deploy* when it can't read `.cpanel.yml` or when the server's copy of the repo has changes.
1. Click **Update from Remote** first. The fixed `.cpanel.yml` (commit `a1f7e09` or later) must be on the server.
   *HEAD Commit* on that page should show the newest commit.
2. Still greyed out? cPanel → **Terminal** (or SSH), then run:
   ```
   cd ~/repositories/auctionhubgroup && git status
   ```
   If it lists changed or extra files, reset the server copy to GitHub's version (this only touches the repo copy, not your live site):
   ```
   git fetch origin && git reset --hard origin/main && git clean -fd
   ```
   Then reload the Git Version Control page.
3. No Terminal? In **Git Version Control**, **Remove** the repository (this deletes only `~/repositories/auctionhubgroup`, not `public_html`),
   then redo step 3 to clone it again.

## Every update after that

When I push changes to GitHub, go to cPanel → **Git Version Control** → **Manage** → **Pull or Deploy**:
1. Click **Update from Remote**.
2. Click **Deploy HEAD Commit**.

That's it.

---

## Optional, when you're ready

**Automatic closing and reminders (cron).** This closes auctions when their time runs out, emails winners,
and sends watchlist reminders and saved-search emails.
cPanel → **Cron Jobs** → *Once Per Five Minutes* → command:

```
/usr/local/bin/php /home/auctionhubgroupc/public_html/cron_close_auctions.php >> /home/auctionhubgroupc/cron_close.log 2>&1
```

> ⚠️ Its first run closes **every lot still marked "live" after its end time**.
> Lots that ended more than 3 days ago are closed *without* being sold or emailed.
> They wait under Admin → Auctions → **Needs attention** for you to sell or relist.
> If those lots are meant to stay on sale, first push their end dates forward
> (Admin → Auctions → *Set Auction Times (Bulk)* → Active Only).

**Security tidy-up.**
- Put a new random value for `AHG_CRON_KEY` in `config.secrets.php`. The old one is visible in GitHub history.
- Change the passwords that used to sit in `config.php`: database, the `admin@` and `careers@` mailboxes, and the Groq/Pexels/Cloudinary keys.
- Stop file listings: add the line `Options -Indexes` near the top of the live `.htaccess`.

**Optional data clean-up.**
- `sql/ahg_switch_images_to_imagekit.sql` (phpMyAdmin) permanently rewrites old Cloudinary photo links.
  Photos already work without it.
