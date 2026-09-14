#!/usr/bin/env bash
#
# Nightly backup: dumps the database, compresses and encrypts it, and
# uploads it to Google Drive via rclone. Keeps a short local retention
# window purely as a fallback if Drive is briefly unreachable — Drive is
# the real long-term copy, not the local disk.
#
# Run automatically via the systemd timer installed by install-backup.sh,
# or manually any time:
#   sudo ./scripts/backup-garageos.sh

set -euo pipefail

APP_ROOT="/var/www/garageos"
ENV_FILE="$APP_ROOT/shared/.env"
BACKUP_DIR="$APP_ROOT/shared/backups"
LOG_FILE="$APP_ROOT/shared/backup.log"
RCLONE_REMOTE="gdrive:garageos-backups"
RETENTION_DAYS=3

log()  { echo "$(date '+%Y-%m-%d %H:%M:%S') $1" | tee -a "$LOG_FILE" >/dev/null; }
fail() { log "ERROR: $1"; exit 1; }

[ -f "$ENV_FILE" ] || fail "Can't find $ENV_FILE"

# Reads DB_HOST/PORT/DATABASE/USERNAME/PASSWORD and BACKUP_PASSPHRASE from
# the same .env the app itself uses — this file only ever contains
# alphanumeric generated values (see install-garageos.sh / install-backup.sh),
# so sourcing it directly is safe.
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

[ -n "${BACKUP_PASSPHRASE:-}" ] || fail "BACKUP_PASSPHRASE not set in $ENV_FILE — run scripts/install-backup.sh first."

mkdir -p "$BACKUP_DIR"

TIMESTAMP=$(date '+%Y%m%d-%H%M%S')
DUMP_FILE="$BACKUP_DIR/garageos-${TIMESTAMP}.sql"
ENCRYPTED_FILE="${DUMP_FILE}.gz.enc"

log "Starting backup"

PGPASSWORD="$DB_PASSWORD" pg_dump \
    -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" "$DB_DATABASE" \
    > "$DUMP_FILE" \
    || fail "pg_dump failed"

gzip -c "$DUMP_FILE" \
    | openssl enc -aes-256-cbc -pbkdf2 -salt -pass "pass:${BACKUP_PASSPHRASE}" -out "$ENCRYPTED_FILE" \
    || fail "Compression/encryption failed"

rm -f "$DUMP_FILE"

log "Created $(basename "$ENCRYPTED_FILE") ($(du -h "$ENCRYPTED_FILE" | cut -f1))"

if command -v rclone >/dev/null 2>&1; then
    if rclone copy "$ENCRYPTED_FILE" "$RCLONE_REMOTE" >>"$LOG_FILE" 2>&1; then
        log "Uploaded to Google Drive ($RCLONE_REMOTE)"
    else
        log "WARNING: upload to Google Drive failed — backup kept locally only, check rclone config"
    fi
else
    log "WARNING: rclone not installed — backup kept locally only"
fi

# Local retention is a short-term fallback only, not the real archive —
# only ever deletes copies older than RETENTION_DAYS, regardless of
# upload status, so a persistently failing upload needs its own alerting
# rather than relying on this to hold everything forever.
find "$BACKUP_DIR" -name 'garageos-*.sql.gz.enc' -mtime "+${RETENTION_DAYS}" -delete

log "Backup complete"
