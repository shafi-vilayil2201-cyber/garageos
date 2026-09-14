#!/usr/bin/env bash
#
# Sets up nightly automated backups for an existing GarageOS install:
# installs rclone, generates a local encryption passphrase, walks through
# connecting a Google Drive account, and schedules backup-garageos.sh to
# run every night via systemd.
#
# Run this once, after install-garageos.sh has already set up the app:
#   sudo ./scripts/install-backup.sh

set -euo pipefail

APP_ROOT="/var/www/garageos"
ENV_FILE="$APP_ROOT/shared/.env"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log()  { echo -e "\n\033[1;32m==>\033[0m $1"; }
fail() { echo -e "\n\033[1;31mERROR:\033[0m $1" >&2; exit 1; }

if [ "$(id -u)" -ne 0 ]; then
    fail "Run this as root (sudo ./scripts/install-backup.sh)."
fi

[ -f "$ENV_FILE" ] || fail "Can't find $ENV_FILE — run install-garageos.sh first."

# ---------------------------------------------------------------------------
# 1. Install rclone (the tool that talks to Google Drive)
# ---------------------------------------------------------------------------

if ! command -v rclone >/dev/null 2>&1; then
    log "Installing rclone"
    apt-get update -qq
    apt-get install -y -qq rclone >/dev/null
else
    log "rclone already installed"
fi

# ---------------------------------------------------------------------------
# 2. Generate a backup encryption passphrase, if one doesn't exist yet.
#    This never appears in the script, in logs, or on the command line
#    beyond this one-time setup — it lives only in shared/.env (mode 600).
# ---------------------------------------------------------------------------

if grep -q '^BACKUP_PASSPHRASE=' "$ENV_FILE" 2>/dev/null; then
    log "Backup encryption passphrase already set — leaving it as-is"
else
    log "Generating a backup encryption passphrase"
    PASSPHRASE=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9')
    echo "BACKUP_PASSPHRASE=${PASSPHRASE}" >> "$ENV_FILE"
    echo "Saved to $ENV_FILE — write this down somewhere safe too, outside this server:"
    echo "$PASSPHRASE"
    echo "(Without this passphrase, a backup file cannot be decrypted, even by you.)"
fi

# ---------------------------------------------------------------------------
# 3. Connect Google Drive via rclone — this is the one step that needs you.
# ---------------------------------------------------------------------------

if rclone listremotes 2>/dev/null | grep -q '^gdrive:'; then
    log "Google Drive is already connected (remote 'gdrive' exists)"
else
    log "Connecting Google Drive — this needs your input"
    echo ""
    echo "About to run 'rclone config'. Follow these exact choices:"
    echo "  1. Type: n          (new remote)"
    echo "  2. Name: gdrive"
    echo "  3. Storage: search for 'drive' and pick Google Drive"
    echo "  4. Leave client_id and client_secret blank (press Enter)"
    echo "  5. Scope: choose 1 (full access)"
    echo "  6. Leave root_folder_id and service_account_file blank"
    echo "  7. Edit advanced config? No"
    echo "  8. Use auto config? No   <-- important, this server has no browser"
    echo "  9. It will print a URL — open it on YOUR OWN computer/phone,"
    echo "     sign in to Google, approve access, and copy the code it gives you"
    echo "  10. Paste that code back here when asked"
    echo "  11. Configure as team drive? No"
    echo "  12. Confirm: yes, then quit (q)"
    echo ""
    read -rp "Press Enter to start rclone config..." _
    rclone config
fi

# ---------------------------------------------------------------------------
# 4. Create the Drive folder for backups (harmless if it already exists)
# ---------------------------------------------------------------------------

rclone mkdir gdrive:garageos-backups 2>/dev/null || true

# ---------------------------------------------------------------------------
# 5. Schedule the nightly run via systemd (2 AM every day)
# ---------------------------------------------------------------------------

log "Scheduling nightly backups (2 AM daily)"

chmod +x "$SCRIPT_DIR/backup-garageos.sh"

cat > /etc/systemd/system/garageos-backup.service <<SERVICE
[Unit]
Description=GarageOS nightly backup

[Service]
Type=oneshot
ExecStart=${SCRIPT_DIR}/backup-garageos.sh
SERVICE

cat > /etc/systemd/system/garageos-backup.timer <<TIMER
[Unit]
Description=Run GarageOS backup nightly at 2 AM

[Timer]
OnCalendar=*-*-* 02:00:00
Persistent=true

[Install]
WantedBy=timers.target
TIMER

systemctl daemon-reload
systemctl enable --now garageos-backup.timer >/dev/null

# ---------------------------------------------------------------------------
# 6. Run one backup right now, to prove the whole chain actually works
# ---------------------------------------------------------------------------

log "Running a real backup now to verify everything works end to end"

if "$SCRIPT_DIR/backup-garageos.sh"; then
    log "Backup succeeded — check your Google Drive for a 'garageos-backups' folder"
else
    echo -e "\n\033[1;33mWARNING:\033[0m the test backup failed — check $APP_ROOT/shared/backup.log for details."
fi

echo -e "\nNightly backups are scheduled. Check status any time with:"
echo "  systemctl status garageos-backup.timer"
echo "  cat ${APP_ROOT}/shared/backup.log"
