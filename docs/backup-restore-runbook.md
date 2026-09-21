# Backup & Restore Runbook

How GarageOS backups work, how to check they're healthy, and how to restore one. The restore steps were rehearsed on the production VM on **2026-09-21** (decrypted the latest Drive backup, loaded it into a scratch database, and row counts matched the live database).

## How backups work

A systemd timer (`garageos-backup.timer`) runs `scripts/backup-garageos.sh` every night at **02:00 server time (UTC)**. It:

1. `pg_dump`s the database,
2. gzips it and encrypts it with AES-256 (`openssl enc -aes-256-cbc -pbkdf2`) using `BACKUP_PASSPHRASE` from `/var/www/garageos/shared/.env`,
3. uploads the `.sql.gz.enc` file to Google Drive (`gdrive:garageos-backups`) with rclone,
4. keeps a 3-day local copy in `/var/www/garageos/shared/backups/` as a fallback.

> **The passphrase is the only key.** Without `BACKUP_PASSPHRASE` the backups cannot be read. Keep a copy in a password manager *outside the server* — if the server is lost, the `.env` goes with it.

## Check that backups are healthy (do this occasionally)

```bash
sudo tail -20 /var/www/garageos/shared/backup.log
systemctl list-timers garageos-backup.timer
```

Every night should show `Created …` then `Uploaded to Google Drive`. A `WARNING: upload to Google Drive failed` line means the rclone connection needs re-authorising (`sudo rclone config reconnect gdrive:`). Backups are **not** alerting — nobody is told if an upload fails, so look at this log now and then.

## Restore a backup

Restore into a **scratch database first** to verify a file, or into the live database only when recovering from a real loss.

```bash
# 1. Fetch the backup you want (list them with: sudo rclone ls gdrive:garageos-backups)
sudo mkdir -p /tmp/restore-test && cd /tmp/restore-test
sudo rclone copy gdrive:garageos-backups/garageos-YYYYMMDD-HHMMSS.sql.gz.enc .

# 2. Decrypt + decompress (reads the passphrase from .env — do not type it in)
sudo bash -c 'set -a; source /var/www/garageos/shared/.env; set +a; \
  openssl enc -d -aes-256-cbc -pbkdf2 -pass "pass:$BACKUP_PASSPHRASE" \
  -in garageos-YYYYMMDD-HHMMSS.sql.gz.enc | gunzip > restore.sql'
ls -lh restore.sql        # should be tens of KB or more, not empty

# 3. Load into a scratch database and compare with live
sudo -u postgres createdb restore_test
sudo -u postgres psql -q -d restore_test -f restore.sql 2>&1 | tail -5
for db in restore_test garageos; do echo "== $db"; sudo -u postgres psql -d $db -tAc \
  "SELECT (SELECT count(*) FROM users), (SELECT count(*) FROM customers), (SELECT count(*) FROM job_cards), (SELECT count(*) FROM invoices)"; done

# 4. ALWAYS clean up — the decrypted file holds real customer data
sudo -u postgres dropdb restore_test && cd ~ && sudo rm -rf /tmp/restore-test
```

Only `setval` output (sequence counters being reset) is expected from step 3; any `ERROR` lines mean the backup or restore needs investigating.

## Recovering from real data loss

1. **Stop writes:** `sudo systemctl stop php*-fpm` (or put nginx into maintenance) so no new data arrives mid-restore.
2. **Take a safety copy of what's there now**, even if damaged: `sudo -u postgres pg_dump garageos > ~/pre-restore-$(date +%F).sql`.
3. Decrypt the chosen backup as above, then replace the database: `sudo -u postgres dropdb garageos && sudo -u postgres createdb -O <db_username> garageos && sudo -u postgres psql -q -d garageos -f restore.sql` (`<db_username>` is `DB_USERNAME` in `/var/www/garageos/shared/.env`).
4. Restart php-fpm and check `/health.php` and a real login.
5. Anything entered after the backup's timestamp is lost — nightly backups mean up to ~24 hours of data.
6. Delete the decrypted `restore.sql` when done.
