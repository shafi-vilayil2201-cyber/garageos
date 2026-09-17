#!/usr/bin/env bash
#
# GarageOS installer — run once on a fresh Ubuntu/Debian VPS to stand up
# a brand-new, independent GarageOS instance: its own PostgreSQL database,
# its own nginx + php-fpm, its own .env, its own admin user.
#
# Nothing here talks to any other customer's server or database. Run this
# script again on a different machine and you get a second, completely
# isolated installation.
#
# Usage:
#   sudo ./scripts/install-garageos.sh
#
# Run this from inside the extracted GarageOS release you want to install
# (i.e. this script's own directory is <release>/scripts/).

set -euo pipefail

RELEASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_ROOT="/var/www/garageos"
VERSION_FILE="$RELEASE_DIR/app/Support/Version.php"

log()  { echo -e "\n\033[1;32m==>\033[0m $1"; }
fail() { echo -e "\n\033[1;31mERROR:\033[0m $1" >&2; exit 1; }

# ---------------------------------------------------------------------------
# 1. Validate OS
# ---------------------------------------------------------------------------

if [ "$(id -u)" -ne 0 ]; then
    fail "Run this as root (sudo ./scripts/install-garageos.sh)."
fi

if ! command -v apt-get >/dev/null 2>&1; then
    fail "This installer supports Debian/Ubuntu (apt-get) only."
fi

if [ ! -f "$VERSION_FILE" ]; then
    fail "Can't find $VERSION_FILE — run this script from inside a GarageOS release."
fi

GARAGEOS_VERSION=$(grep -oP "GARAGEOS_VERSION\s*=\s*'\K[^']+" "$VERSION_FILE" || echo "unknown")
[ "$GARAGEOS_VERSION" = "unknown" ] && fail "Could not read GARAGEOS_VERSION from $VERSION_FILE."
log "Installing GarageOS $GARAGEOS_VERSION"

# ---------------------------------------------------------------------------
# 2. Gather configuration
# ---------------------------------------------------------------------------

read -rp "Domain for this installation (e.g. highwaymotors.example.com): " APP_DOMAIN
[ -z "$APP_DOMAIN" ] && fail "A domain is required."

read -rp "Admin email for HTTPS certificate notices: " CERT_EMAIL
[ -z "$CERT_EMAIL" ] && fail "An email is required for Let's Encrypt."

DB_NAME="garageos"
DB_USER="garageos_app"
DB_PASSWORD=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 32)

# ---------------------------------------------------------------------------
# 3. Install system dependencies (skips anything already installed)
# ---------------------------------------------------------------------------

log "Installing system packages"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
    nginx \
    postgresql postgresql-contrib \
    php-fpm php-pgsql php-cli php-common php-gd \
    certbot python3-certbot-nginx \
    openssl >/dev/null

PHP_FPM_SERVICE=$(systemctl list-units --type=service --all --no-legend 'php*-fpm.service' | awk '{print $1}' | head -1)
[ -z "$PHP_FPM_SERVICE" ] && fail "Could not detect the php-fpm service name."

# ---------------------------------------------------------------------------
# 3b. Harden PHP for production — a visitor must never see a PHP error or
#     the PHP version, only server logs should. Written as its own conf.d
#     file (not sed-edited into php.ini) so re-running this installer is
#     safe and idempotent — it just overwrites the same small file.
# ---------------------------------------------------------------------------

log "Hardening PHP for production"

PHP_CONF_DIR=$(find /etc/php -maxdepth 3 -type d -path '*/fpm/conf.d' 2>/dev/null | head -1)
[ -z "$PHP_CONF_DIR" ] && fail "Could not find the php-fpm conf.d directory."

cat > "$PHP_CONF_DIR/99-garageos-production.ini" <<'PHPINI'
; GarageOS production hardening — errors are logged, never shown.
display_errors = Off
log_errors = On
expose_php = Off
PHPINI

systemctl restart "$PHP_FPM_SERVICE"

# ---------------------------------------------------------------------------
# 3c. Firewall — allow only SSH, HTTP and HTTPS. PostgreSQL is never
#     opened here; it stays reachable only on 127.0.0.1 (config/database.php
#     always connects to localhost, never a public address).
#
#     SSH is allowed BEFORE the default-deny policy takes effect, so this
#     never locks out the session running the installer itself.
# ---------------------------------------------------------------------------

log "Configuring firewall (UFW)"

command -v ufw >/dev/null 2>&1 || apt-get install -y -qq ufw >/dev/null

ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw --force enable >/dev/null

log "Firewall active — allowed: 22 (SSH), 80 (HTTP), 443 (HTTPS). Everything else, including PostgreSQL's 5432, is denied."

# ---------------------------------------------------------------------------
# 4. Create a dedicated, restricted PostgreSQL database + user
#    (never the superuser — GarageOS only ever needs DML/DDL on its own DB)
# ---------------------------------------------------------------------------

log "Creating PostgreSQL database and restricted application user"

sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_USER}') THEN
        CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASSWORD}';
    END IF;
END
\$\$;

SELECT 'CREATE DATABASE ${DB_NAME} OWNER ${DB_USER}'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_NAME}')\gexec
SQL

# ---------------------------------------------------------------------------
# 5. Lay out the release-directory structure so future updates only ever
#    swap a symlink — this release's files never overwrite a previous one.
#
#    /var/www/garageos/
#        releases/<version>/   <- this release's code
#        shared/.env           <- survives every future release
#        current -> releases/<version>
# ---------------------------------------------------------------------------

log "Deploying application files"

mkdir -p "$APP_ROOT/releases" "$APP_ROOT/shared"

RELEASE_TARGET="$APP_ROOT/releases/$GARAGEOS_VERSION"

if [ -d "$RELEASE_TARGET" ]; then
    fail "Release $GARAGEOS_VERSION is already deployed at $RELEASE_TARGET. Nothing to do."
fi

cp -r "$RELEASE_DIR" "$RELEASE_TARGET"
rm -rf "$RELEASE_TARGET/.git"

if [ ! -f "$APP_ROOT/shared/.env" ]; then
    cat > "$APP_ROOT/shared/.env" <<ENV
APP_ENV=production
APP_URL=https://${APP_DOMAIN}

DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
ENV
    chmod 600 "$APP_ROOT/shared/.env"
fi

ln -sfn "$APP_ROOT/shared/.env" "$RELEASE_TARGET/.env"
ln -sfn "$RELEASE_TARGET" "$APP_ROOT/current"

chown -R www-data:www-data "$APP_ROOT"

# ---------------------------------------------------------------------------
# 6. Run migrations
# ---------------------------------------------------------------------------

log "Running database migrations"

sudo -u www-data php "$APP_ROOT/current/database/migrate.php"

# ---------------------------------------------------------------------------
# 7. Create the first organization + admin user
# ---------------------------------------------------------------------------

log "Set up the workshop's organization and first admin login"

sudo -u www-data php "$APP_ROOT/current/database/onboard-client.php"

# ---------------------------------------------------------------------------
# 8. Configure nginx
# ---------------------------------------------------------------------------

log "Configuring nginx"

cat > /etc/nginx/sites-available/garageos <<NGINX
server {
    listen 80;
    server_name ${APP_DOMAIN};
    root ${APP_ROOT}/current/public;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/${PHP_FPM_SERVICE/.service/}.sock;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINX

ln -sfn /etc/nginx/sites-available/garageos /etc/nginx/sites-enabled/garageos
nginx -t
systemctl reload nginx
systemctl enable --now "$PHP_FPM_SERVICE" postgresql nginx >/dev/null

# ---------------------------------------------------------------------------
# 9. HTTPS via Let's Encrypt
# ---------------------------------------------------------------------------

log "Requesting HTTPS certificate for ${APP_DOMAIN}"

certbot --nginx -d "$APP_DOMAIN" -m "$CERT_EMAIL" --agree-tos --non-interactive --redirect \
    || echo "Certbot failed — confirm ${APP_DOMAIN} already points at this server's IP, then re-run: certbot --nginx -d ${APP_DOMAIN}"

# ---------------------------------------------------------------------------
# 10. Health check
# ---------------------------------------------------------------------------

log "Verifying installation"

sleep 2
HEALTH=$(curl -sk "https://${APP_DOMAIN}/health.php" || curl -s "http://${APP_DOMAIN}/health.php" || echo '{"status":"unreachable"}')
echo "$HEALTH"

if echo "$HEALTH" | grep -q '"status":"ok"'; then
    log "GarageOS $GARAGEOS_VERSION is live at https://${APP_DOMAIN}"
else
    echo -e "\n\033[1;33mWARNING:\033[0m health check did not report OK — check the output above and /var/log/nginx/error.log."
fi

echo -e "\nDatabase: ${DB_NAME}  |  App user: ${DB_USER}  |  Password stored in ${APP_ROOT}/shared/.env (mode 600)"
