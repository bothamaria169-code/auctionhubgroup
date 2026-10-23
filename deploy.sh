#!/bin/bash
# ============================================================
# AUCTION HUB GROUP — deploy.sh
# Run by cPanel Git Version Control (see .cpanel.yml) after a pull:
#   1. copies the site from the server's clone of the GitHub repo into
#      public_html (adds/updates only — uploads/, vendor/ and anything that
#      exists only on the server are left alone);
#   2. removes a fixed list of private/dead files that must never be public
#      (database dumps, invoices, dev scripts) if an old upload left them;
#   3. adds a security block to the live .htaccess once (marked, idempotent).
# ============================================================
#
# .cpanel.yml is kept to cPanel's documented example on purpose (plain ASCII,
# no comments): cPanel greys out "Deploy HEAD Commit" if it can't parse it.
# ============================================================
set -euo pipefail

DEPLOYPATH="${DEPLOYPATH:-$HOME/public_html}"
DEPLOYPATH="${DEPLOYPATH%/}"
SRC="$(cd "$(dirname "$0")" && pwd)"

echo "Deploying $(git -C "$SRC" log -1 --format='%h %s' 2>/dev/null || echo 'unknown commit') → $DEPLOYPATH"

# Passwords never come from git. Without this file the new config.php
# stops with an error, so refuse to deploy instead of breaking the site.
if [ ! -f "$HOME/config.secrets.php" ] && [ ! -f "$DEPLOYPATH/config.secrets.php" ]; then
    echo "STOPPED: config.secrets.php not found in $HOME or $DEPLOYPATH."
    echo "Upload it to $HOME (the folder above public_html), then deploy again."
    exit 1
fi

# ── 1. Copy ─────────────────────────────────────────────────────
# Only files tracked in git, listed one by one. Never archive "." itself:
# extracting a "./" entry overwrites public_html's own permissions with the
# clone's (cPanel runs deploys with a strict umask), which made the whole
# site 404 on 23 Oct 2026. Permissions are then set explicitly below.
# Not copied: the root .htaccess (the live one has host lines — step 3
# patches it instead), git/editor files, docs, one-off tools, the secrets
# template. sql/ IS copied (careers code reads a migration from it) and is
# locked by its own .htaccess.
umask 022
cd "$SRC"
FILES="$(mktemp)"
git ls-files | grep -vE '^(\.gitignore|\.gitattributes|\.editorconfig|\.cpanel\.yml|deploy\.sh|\.htaccess|composer\.(json|lock)|config\.secrets\.example\.php|[^/]+\.md)$|^(\.claude|docs|tools|private)/' > "$FILES"
tar -cf - -T "$FILES" | tar -xf - -C "$DEPLOYPATH" --no-same-owner --no-same-permissions
echo "Files copied ($(wc -l < "$FILES") files)."

# Standard cPanel permissions for everything the deploy manages:
# public_html and folders 755, files 644. uploads/, vendor/ etc. untouched.
chmod 755 "$DEPLOYPATH"
while IFS= read -r f; do
    chmod 644 "$DEPLOYPATH/$f"
    d="$(dirname "$f")"
    while [ "$d" != "." ]; do chmod 755 "$DEPLOYPATH/$d"; d="$(dirname "$d")"; done
done < "$FILES"
rm -f "$FILES"
echo "Permissions set (folders 755, files 644)."

# ── 2. Remove private / dead files left by old manual uploads ──────
cd "$DEPLOYPATH"
REMOVE=(
    # private: customer data, company documents, dev helpers
    Invoice.pdf cipc.pdf gen_hash.php test.php config.secrets.example.php
    composer.json composer.lock
    online_swap_vehicle_data.sql
    # removed from the codebase in Oct 2026 (old prototypes, one-off tools)
    PVA_Complete_System.html auction-template.html pva-chatbot.html
    pva-sales-agent.html pva-sales-agent-2.html ParkVillageAuctions_Brochure_v2.pdf
    email-layout-tab.png new-logo.png new-logo-round.png paper.jpg
    park-villageauctions.code-workspace optimize_logo.php generate_swap_sql.php
    export_cloudinary_account.php upload_to_imagekit.php backup_cloudinary_images.php
)
for f in "${REMOVE[@]}"; do
    if [ -f "$f" ]; then rm -f -- "$f"; echo "  removed $f"; fi
done
# Every .sql / .md in the site root (dumps, migrations, docs). The real
# migrations now live in sql/, which is locked.
for f in ./*.sql ./*.md; do
    if [ -f "$f" ]; then rm -f -- "$f"; echo "  removed ${f#./}"; fi
done
for d in google-ads-pack; do
    if [ -d "$d" ]; then rm -rf -- "$d"; echo "  removed $d/"; fi
done
# A full-site backup folder left inside public_html would expose everything
# (secrets, dumps). Lock it instead of deleting it: its own .htaccess is kept
# as .htaccess.orig and replaced with deny-all. Move it out of public_html.
for d in auctionhubgroup backup site-backup; do
    if [ -d "$d" ] && ! grep -qs "AHG-LOCKED" "$d/.htaccess"; then
        [ -f "$d/.htaccess" ] && mv -f -- "$d/.htaccess" "$d/.htaccess.orig"
        printf '# AHG-LOCKED by deploy.sh: backup copy, never public\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n' > "$d/.htaccess"
        echo "  locked $d/ (backup folder: deny-all .htaccess; move it out of public_html)"
    fi
done

# ── 3. Security block in the live .htaccess (added once) ───────────
MARK="# BEGIN AHG-SECURITY"
if [ -f .htaccess ] && ! grep -qF "$MARK" .htaccess; then
    cp .htaccess ".htaccess.bak-$(date +%Y%m%d%H%M%S)"
    cat >> .htaccess <<'HTACCESS'

# BEGIN AHG-SECURITY — added by deploy.sh; keep this block
Options -Indexes
<FilesMatch "\.(sql|md|log|bak|sh|env|ini|lock|yml|zip|code-workspace)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
<FilesMatch "^(config\.secrets.*\.php|composer\.(json|lock)|\.htaccess\.bak-.*)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
# END AHG-SECURITY
HTACCESS
    echo "Security block added to .htaccess (backup saved next to it)."
fi

if [ -f "$DEPLOYPATH/config.secrets.php" ] && [ ! -f "$HOME/config.secrets.php" ]; then
    echo "Tip: move config.secrets.php from public_html to $HOME (safer; config.php finds it there)."
fi

echo "Deploy complete."
