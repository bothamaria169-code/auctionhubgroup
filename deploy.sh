#!/bin/bash
# ============================================================
# AUCTION HUB GROUP — deploy.sh
# Run by cPanel Git Version Control (see .cpanel.yml) after a pull:
# copies the site from the server's clone of the GitHub repo into
# public_html. Only ADDS/UPDATES files — never deletes — so uploads/,
# vendor/ and anything that exists only on the server are left alone.
# ============================================================
set -euo pipefail

DEPLOYPATH="$HOME/public_html"
SRC="$(cd "$(dirname "$0")" && pwd)"

echo "Deploying $(git -C "$SRC" log -1 --format='%h %s' 2>/dev/null || echo 'unknown commit') → $DEPLOYPATH"

# Passwords never come from git. Without this file the new config.php
# stops with an error, so refuse to deploy instead of breaking the site.
if [ ! -f "$HOME/config.secrets.php" ] && [ ! -f "$DEPLOYPATH/config.secrets.php" ]; then
    echo "STOPPED: config.secrets.php not found in $HOME or $DEPLOYPATH."
    echo "Upload it to $HOME (the folder above public_html), then deploy again."
    exit 1
fi

# Not copied to the live site:
#   .htaccess      — the live one has host-specific lines; edit it by hand
#   docs, SQL, dev tooling, old prototypes, the secrets template
cd "$SRC"
tar -cf - \
    --exclude='./.git' --exclude='./.gitignore' --exclude='./.gitattributes' \
    --exclude='./.editorconfig' --exclude='./.claude' --exclude='./.cpanel.yml' \
    --exclude='./deploy.sh' --exclude='./.htaccess' \
    --exclude='*.md' --exclude='*.sql' --exclude='*.code-workspace' \
    --exclude='./composer.json' --exclude='./composer.lock' \
    --exclude='./config.secrets.example.php' \
    --exclude='./PVA_Complete_System.html' --exclude='./auction-template.html' \
    --exclude='./pva-chatbot.html' --exclude='./pva-sales-agent.html' --exclude='./pva-sales-agent-2.html' \
    . | tar -xf - -C "$DEPLOYPATH"

echo "Deploy complete."
