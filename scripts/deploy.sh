#!/usr/bin/env bash
#
# Deploys the current git checkout in ~/garageos to the running
# instance at /var/www/garageos/current: syncs code, fixes ownership,
# runs any pending migrations, and restarts php-fpm.
#
# This is deliberately a script, not a one-off command typed into an
# SSH session each time — a hand-typed `rsync --delete` once wiped
# public/uploads/ (a runtime directory, gitignored, never present in
# the git checkout) because --delete makes the destination an exact
# mirror of the source and treats anything not in git as "extra" to
# remove. That deleted every organization's uploaded logo file even
# though the database still pointed at it. --exclude='public/uploads'
# below is what actually prevents that; keeping the whole sequence in
# one checked-in script means that exclude can't be forgotten or
# mistyped on some future deploy the way a hand-typed command can.
#
# Usage (on the production VM, from the ~/garageos checkout):
#   ./scripts/deploy.sh

set -euo pipefail

APP_ROOT="/var/www/garageos"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

log()  { echo -e "\n\033[1;32m==>\033[0m $1"; }
fail() { echo -e "\n\033[1;31mERROR:\033[0m $1" >&2; exit 1; }

[ -d "$APP_ROOT/current" ] || fail "$APP_ROOT/current not found — is this the production VM?"

log "Syncing code to $APP_ROOT/current"

# --delete keeps current/ from accumulating files removed from git over
# time, but it must never be allowed to touch anything that lives only
# on the server and not in git: .env (a symlink to shared/.env, and
# would be deleted outright if synced over) and public/uploads (runtime
# file uploads — see the comment above).
sudo rsync -a --delete \
    --exclude='.env' \
    --exclude='.git' \
    --exclude='public/uploads' \
    "$REPO_ROOT/" "$APP_ROOT/current/"

log "Fixing ownership"
sudo chown -R www-data:www-data "$APP_ROOT"

log "Running database migrations"
sudo -u www-data php "$APP_ROOT/current/database/migrate.php"

log "Restarting php-fpm"
sudo systemctl restart php*-fpm

log "Deploy complete"
