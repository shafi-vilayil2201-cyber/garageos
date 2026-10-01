#!/usr/bin/env bash
#
# Sets up a separate GarageOS *demo* copy on a server that already runs a
# real client install — for sharing with prospects. It gets its own folder,
# database, database user, nginx site, domain and HTTPS certificate, and is
# filled with sample workshop data. The real install (/var/www/garageos, the
# "garageos" database, the "garageos" nginx site) is never read or modified.
#
# Usage (on the server, from the ~/garageos checkout):
#   sudo ./scripts/install-demo-instance.sh          # first-time setup
#   sudo ./scripts/install-demo-instance.sh --reset  # wipe demo back to fresh sample data
#   sudo ./scripts/install-demo-instance.sh --remove # take the demo off the server
#
# Update the demo's code later with:
#   GARAGEOS_APP_ROOT=/var/www/garageos-demo ./scripts/deploy.sh

set -euo pipefail

DEMO_ROOT="/var/www/garageos-demo"
DB_NAME="garageos_demo"
DB_USER="garageos_demo_app"
SITE_NAME="garageos-demo"

# The real client install — listed only so the checks below can refuse to
# ever point at it.
PROTECTED_ROOT="/var/www/garageos"
PROTECTED_DB="garageos"
PROTECTED_SITE="garageos"

RELEASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$DEMO_ROOT/shared/.env"
LOGIN_FILE="$DEMO_ROOT/shared/demo-login-email"

log()  { echo -e "\n\033[1;32m==>\033[0m $1"; }
fail() { echo -e "\n\033[1;31mERROR:\033[0m $1" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "Run this as root: sudo $0 ${1:-}"

# Belt-and-braces: these are constants, but if anyone ever edits them to
# match the real install, stop before doing anything.
[ "$DEMO_ROOT" != "$PROTECTED_ROOT" ] || fail "Demo folder must not be the real install's folder."
[ "$DB_NAME" != "$PROTECTED_DB" ] || fail "Demo database must not be the real install's database."
[ "$SITE_NAME" != "$PROTECTED_SITE" ] || fail "Demo nginx site must not be the real install's site."

for cmd in nginx psql php rsync curl openssl; do
    command -v "$cmd" >/dev/null 2>&1 || fail "'$cmd' is not installed — this script expects a server already set up by install-garageos.sh."
done

PHP_FPM_SERVICE=$(systemctl list-units --type=service --all --no-legend 'php*-fpm.service' | awk '{print $1}' | head -1)
[ -n "$PHP_FPM_SERVICE" ] || fail "Could not detect the php-fpm service."
PHP_FPM_SOCKET="/run/php/${PHP_FPM_SERVICE%.service}.sock"

db_exists() {
    sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname = '$1'" | grep -q 1
}

# Creates (or re-creates) the demo database, runs migrations and loads the
# sample workshop, then prints the demo login. Shared by install and reset.
seed_demo_database() {
    local admin_email="$1"
    local admin_password
    admin_password=$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 14)

    log "Running database migrations"
    sudo -u www-data php "$DEMO_ROOT/current/database/migrate.php"

    log "Loading sample workshop data"
    local output
    if ! output=$(printf '%s\n%s\n' "$admin_email" "$admin_password" \
        | sudo -u www-data php "$DEMO_ROOT/current/database/seeders/001_create_demo_data.php" 2>&1); then
        echo "$output"; fail "Creating the demo workshop failed."
    fi
    for seeder in 002_seed_service_catalog.php 003_seed_demo_workshop_data.php; do
        if ! output=$(sudo -u www-data php "$DEMO_ROOT/current/database/seeders/$seeder" 2>&1); then
            echo "$output"; fail "Seeder $seeder failed."
        fi
    done

    echo -e "\n\033[1;36mDemo login\033[0m (share this with the prospect):"
    echo "  Email:    $admin_email"
    echo "  Password: $admin_password"
}

# ---------------------------------------------------------------------------
# --reset: wipe the demo back to fresh sample data
# ---------------------------------------------------------------------------

if [ "${1:-}" = "--reset" ]; then
    [ -d "$DEMO_ROOT/current" ] || fail "No demo install found at $DEMO_ROOT — run without --reset first."
    grep -q "^DB_DATABASE=${DB_NAME}$" "$ENV_FILE" \
        || fail "$ENV_FILE does not point at ${DB_NAME} — refusing to reset."

    read -rp "This deletes ALL data in the demo (${DB_NAME}) and creates a fresh login. Type RESET to continue: " CONFIRM
    [ "$CONFIRM" = "RESET" ] || fail "Cancelled — nothing changed."

    log "Recreating the demo database"
    sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<SQL
DROP DATABASE IF EXISTS ${DB_NAME} WITH (FORCE);
CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};
SQL

    log "Clearing demo uploads"
    rm -rf "$DEMO_ROOT/current/public/uploads"
    mkdir -p "$DEMO_ROOT/current/public/uploads"
    chown -R www-data:www-data "$DEMO_ROOT/current/public/uploads"

    seed_demo_database "$(cat "$LOGIN_FILE")"
    log "Demo reset complete"
    exit 0
fi

# ---------------------------------------------------------------------------
# --remove: take the demo off this server completely
# ---------------------------------------------------------------------------

if [ "${1:-}" = "--remove" ]; then
    read -rp "This permanently removes the demo (${DEMO_ROOT}, database ${DB_NAME}, nginx site ${SITE_NAME}). Type REMOVE to continue: " CONFIRM
    [ "$CONFIRM" = "REMOVE" ] || fail "Cancelled — nothing changed."

    DEMO_DOMAIN=""
    [ -f "$ENV_FILE" ] && DEMO_DOMAIN=$(sed -n 's#^APP_URL=https\?://##p' "$ENV_FILE")

    rm -f "/etc/nginx/sites-enabled/$SITE_NAME" "/etc/nginx/sites-available/$SITE_NAME"
    nginx -t && systemctl reload nginx
    if [ -n "$DEMO_DOMAIN" ] && command -v certbot >/dev/null 2>&1; then
        certbot delete --cert-name "$DEMO_DOMAIN" --non-interactive >/dev/null 2>&1 || true
    fi
    sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<SQL
DROP DATABASE IF EXISTS ${DB_NAME} WITH (FORCE);
DROP ROLE IF EXISTS ${DB_USER};
SQL
    rm -rf "$DEMO_ROOT"
    log "Demo removed. The real install was not touched."
    exit 0
fi

[ -z "${1:-}" ] || fail "Unknown option '$1'. Use no option to install, --reset, or --remove."

# ---------------------------------------------------------------------------
# First-time install — refuse if anything already exists
# ---------------------------------------------------------------------------

[ ! -e "$DEMO_ROOT" ] || fail "$DEMO_ROOT already exists. To start the demo over, use --reset."
! db_exists "$DB_NAME" || fail "Database ${DB_NAME} already exists. To start the demo over, use --reset."
[ ! -e "/etc/nginx/sites-available/$SITE_NAME" ] || fail "nginx site '$SITE_NAME' already exists."

read -rp "Demo domain (must already point at this server, e.g. garageos-demo.duckdns.org): " DEMO_DOMAIN
[ -n "$DEMO_DOMAIN" ] || fail "A domain is required."
read -rp "Email for HTTPS certificate notices: " CERT_EMAIL
[ -n "$CERT_EMAIL" ] || fail "An email is required for Let's Encrypt."
read -rp "Demo login email [demo@garageos.demo]: " ADMIN_EMAIL
ADMIN_EMAIL=${ADMIN_EMAIL:-demo@garageos.demo}

DOMAIN_PATTERN="${DEMO_DOMAIN//./\\.}"
if grep -rqsE "^[[:space:]]*server_name([[:space:]][^;]*)?[[:space:]]${DOMAIN_PATTERN}([[:space:];]|$)" /etc/nginx/sites-enabled/; then
    fail "${DEMO_DOMAIN} is already served by another nginx site on this server."
fi

log "Creating the demo database and its own restricted user"
DB_PASSWORD=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 32)
sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_USER}') THEN
        CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASSWORD}';
    ELSE
        ALTER ROLE ${DB_USER} PASSWORD '${DB_PASSWORD}';
    END IF;
END
\$\$;
CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};
SQL

log "Copying application files to $DEMO_ROOT"
mkdir -p "$DEMO_ROOT/releases/initial" "$DEMO_ROOT/shared"
rsync -a --exclude='.git' --exclude='.env' --exclude='public/uploads' \
    "$RELEASE_DIR/" "$DEMO_ROOT/releases/initial/"
mkdir -p "$DEMO_ROOT/releases/initial/public/uploads"

cat > "$ENV_FILE" <<ENV
APP_ENV=production
APP_URL=https://${DEMO_DOMAIN}

DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
ENV
echo "$ADMIN_EMAIL" > "$LOGIN_FILE"

ln -sfn "$ENV_FILE" "$DEMO_ROOT/releases/initial/.env"
ln -sfn "$DEMO_ROOT/releases/initial" "$DEMO_ROOT/current"
chown -R www-data:www-data "$DEMO_ROOT"
chmod 600 "$ENV_FILE"

seed_demo_database "$ADMIN_EMAIL"

log "Configuring nginx site '$SITE_NAME'"
cat > "/etc/nginx/sites-available/$SITE_NAME" <<NGINX
server {
    listen 80;
    server_name ${DEMO_DOMAIN};
    root ${DEMO_ROOT}/current/public;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCKET};
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINX
ln -sfn "/etc/nginx/sites-available/$SITE_NAME" "/etc/nginx/sites-enabled/$SITE_NAME"

# Never leave a broken nginx config behind — that would take the real
# client's site down on the next reload too.
if ! nginx -t 2>/dev/null; then
    rm -f "/etc/nginx/sites-enabled/$SITE_NAME"
    nginx -t
    fail "nginx rejected the demo site config — it has been disabled again; the real site is unaffected."
fi
systemctl reload nginx

log "Requesting HTTPS certificate for ${DEMO_DOMAIN}"
certbot --nginx -d "$DEMO_DOMAIN" -m "$CERT_EMAIL" --agree-tos --non-interactive --redirect \
    || echo "Certbot failed — confirm ${DEMO_DOMAIN} points at this server's IP, then re-run: sudo certbot --nginx -d ${DEMO_DOMAIN}"

log "Verifying the demo"
sleep 2
HEALTH=$(curl -sk "https://${DEMO_DOMAIN}/health.php" || curl -s -H "Host: ${DEMO_DOMAIN}" "http://127.0.0.1/health.php" || echo '{"status":"unreachable"}')
echo "$HEALTH"

echo -e "\nDemo is at https://${DEMO_DOMAIN}"
echo "Reset it between prospects with: sudo ./scripts/install-demo-instance.sh --reset"
