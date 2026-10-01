#!/usr/bin/env bash
#
# Security hardening for a GarageOS server — safe to run more than once.
#
#   1. Browser security headers (HSTS, clickjacking, MIME sniffing, referrer)
#      on every GarageOS nginx site.
#   2. If a demo copy exists (install-demo-instance.sh), runs it as its own
#      system user in its own PHP-FPM pool, so the demo — whose login is
#      shared with prospects — can't read the real install's files (its
#      .env holds the client's database password).
#   3. Each GarageOS database only accepts connections from its own app
#      user (PostgreSQL lets every user connect to every database by default).
#
# The real install keeps running exactly as before: same files, same owner
# (www-data), same php-fpm pool, same deploy and backup process. Every nginx
# change is tested before reload and undone if rejected; every site is
# health-checked before and after.
#
# Usage (on the server, from the ~/garageos checkout):
#   sudo ./scripts/harden-server.sh

set -euo pipefail

CLIENT_ROOT="/var/www/garageos"
DEMO_ROOT="/var/www/garageos-demo"
DEMO_USER="garageos-demo"
DEMO_POOL="garageos-demo"
DEMO_SOCKET="/run/php/garageos-demo.sock"
SNIPPET="/etc/nginx/snippets/garageos-security-headers.conf"
SITES_AVAILABLE="/etc/nginx/sites-available"
BACKUP_DIR="/root/garageos-harden-backup-$(date +%Y%m%d-%H%M%S)"

log()  { echo -e "\n\033[1;32m==>\033[0m $1"; }
warn() { echo -e "\033[1;33mWARNING:\033[0m $1"; }
fail() { echo -e "\n\033[1;31mERROR:\033[0m $1" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "Run this as root: sudo $0"
command -v nginx >/dev/null 2>&1 || fail "nginx is not installed — nothing to harden."
nginx -t >/dev/null 2>&1 || fail "nginx's current config already fails 'nginx -t' — fix that first; nothing was changed."

PHP_FPM_SERVICE=$(systemctl list-units --type=service --all --no-legend 'php*-fpm.service' | awk '{print $1}' | head -1)
[ -n "$PHP_FPM_SERVICE" ] || fail "Could not detect the php-fpm service."
PHP_VERSION="${PHP_FPM_SERVICE#php}"
PHP_VERSION="${PHP_VERSION%-fpm.service}"
PHP_FPM_BIN="php-fpm${PHP_VERSION}"
POOL_DIR="/etc/php/${PHP_VERSION}/fpm/pool.d"
[ -d "$POOL_DIR" ] || fail "php-fpm pool directory $POOL_DIR not found."

env_value() { # $1 = .env file, $2 = key
    sed -n "s/^$2=//p" "$1" | head -1
}

# Health URL of an install, read from its own .env (APP_URL).
site_domain() {
    env_value "$1/shared/.env" APP_URL | sed 's#^https\?://##; s#/.*##'
}

# 200 + {"status":"ok"} from the site's health check, asked of this server
# directly (not via public DNS), over HTTPS if it has a certificate.
site_healthy() {
    local domain="$1" body
    body=$(curl -sk -m 10 --resolve "${domain}:443:127.0.0.1" "https://${domain}/health.php" 2>/dev/null) \
        || body=$(curl -s -m 10 -H "Host: ${domain}" "http://127.0.0.1/health.php" 2>/dev/null) || true
    echo "$body" | grep -q '"status":"ok"'
}

INSTALLS=()
[ -f "$CLIENT_ROOT/shared/.env" ] && INSTALLS+=("$CLIENT_ROOT")
[ -f "$DEMO_ROOT/shared/.env" ] && INSTALLS+=("$DEMO_ROOT")
[ ${#INSTALLS[@]} -gt 0 ] || fail "No GarageOS install found under /var/www."

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

declare -A HEALTHY_BEFORE
log "Checking sites before making changes"
for root in "${INSTALLS[@]}"; do
    domain=$(site_domain "$root")
    if site_healthy "$domain"; then HEALTHY_BEFORE[$root]=1; echo "  ok      $domain"
    else HEALTHY_BEFORE[$root]=0; warn "$domain is not healthy right now (before any change)."; fi
done

# ---------------------------------------------------------------------------
# 1. Security headers
# ---------------------------------------------------------------------------

log "Adding browser security headers"

cat > "$SNIPPET" <<'NGINX'
# GarageOS browser security headers (managed by scripts/harden-server.sh).
add_header Strict-Transport-Security "max-age=31536000" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
server_tokens off;
NGINX

for site in garageos garageos-demo; do
    file="$SITES_AVAILABLE/$site"
    [ -f "$file" ] || continue
    cp -a "$file" "$BACKUP_DIR/$site.nginx"
    if ! grep -q "garageos-security-headers.conf" "$file"; then
        sed -i '/^[[:space:]]*server_name[[:space:]]/a\    include snippets/garageos-security-headers.conf;' "$file"
    fi
done

if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx
    echo "  headers enabled"
else
    for site in garageos garageos-demo; do
        [ -f "$BACKUP_DIR/$site.nginx" ] && cp -a "$BACKUP_DIR/$site.nginx" "$SITES_AVAILABLE/$site"
    done
    rm -f "$SNIPPET"
    nginx -t >/dev/null 2>&1 && systemctl reload nginx
    fail "nginx rejected the header change — restored the previous config; nothing else was changed."
fi

# ---------------------------------------------------------------------------
# 2. Demo isolation: its own system user + PHP-FPM pool
# ---------------------------------------------------------------------------

if [ -f "$DEMO_ROOT/shared/.env" ]; then
    log "Isolating the demo under its own system user ($DEMO_USER)"

    if ! id "$DEMO_USER" >/dev/null 2>&1; then
        useradd --system --user-group --no-create-home --home-dir "$DEMO_ROOT" --shell /usr/sbin/nologin "$DEMO_USER"
    fi

    POOL_FILE="$POOL_DIR/$DEMO_POOL.conf"
    [ -f "$POOL_FILE" ] && cp -a "$POOL_FILE" "$BACKUP_DIR/$DEMO_POOL.pool"
    cat > "$POOL_FILE" <<POOL
; GarageOS demo copy — runs as its own user so it can't read the real
; install's files. ondemand: no PHP workers at all while nobody uses the demo.
[$DEMO_POOL]
user = $DEMO_USER
group = $DEMO_USER
listen = $DEMO_SOCKET
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 30s
pm.max_requests = 500
POOL

    if ! "$PHP_FPM_BIN" -t >/dev/null 2>&1; then
        rm -f "$POOL_FILE"
        [ -f "$BACKUP_DIR/$DEMO_POOL.pool" ] && cp -a "$BACKUP_DIR/$DEMO_POOL.pool" "$POOL_FILE"
        fail "php-fpm rejected the demo pool config — removed it; the real install is unaffected."
    fi
    systemctl reload "$PHP_FPM_SERVICE"   # graceful: in-flight requests finish
    for _ in 1 2 3 4 5 6 7 8 9 10; do [ -S "$DEMO_SOCKET" ] && break; sleep 0.5; done
    [ -S "$DEMO_SOCKET" ] || fail "The demo PHP pool did not start (no $DEMO_SOCKET)."

    PREVIOUS_OWNER=$(stat -c '%U' "$DEMO_ROOT/shared")
    chown -R "$DEMO_USER:$DEMO_USER" "$DEMO_ROOT"
    chmod 600 "$DEMO_ROOT/shared/.env"

    DEMO_SITE="$SITES_AVAILABLE/garageos-demo"
    sed -i "s#fastcgi_pass unix:[^;]*;#fastcgi_pass unix:${DEMO_SOCKET};#" "$DEMO_SITE"
    DEMO_DOMAIN=$(site_domain "$DEMO_ROOT")

    if nginx -t >/dev/null 2>&1 && systemctl reload nginx && sleep 1 && site_healthy "$DEMO_DOMAIN"; then
        echo "  demo now runs as $DEMO_USER"
    else
        warn "The demo did not come back healthy — rolling the demo back to its previous setup."
        cp -a "$BACKUP_DIR/garageos-demo.nginx" "$DEMO_SITE"
        if ! grep -q "garageos-security-headers.conf" "$DEMO_SITE"; then
            sed -i '/^[[:space:]]*server_name[[:space:]]/a\    include snippets/garageos-security-headers.conf;' "$DEMO_SITE"
        fi
        chown -R "$PREVIOUS_OWNER:$PREVIOUS_OWNER" "$DEMO_ROOT"
        nginx -t >/dev/null 2>&1 && systemctl reload nginx
        fail "Demo isolation was rolled back. The real install was not touched."
    fi

    # Belt and braces for the real install: nothing outside its own user (and
    # root, which runs the backups) can list or read its shared/ folder —
    # .env, local backups, backup log.
    if [ -d "$CLIENT_ROOT/shared" ]; then
        chmod o-rwx "$CLIENT_ROOT/shared"
    fi
fi

# ---------------------------------------------------------------------------
# 3. Each database accepts only its own app user
# ---------------------------------------------------------------------------

log "Restricting database connections to each app's own user"

for root in "${INSTALLS[@]}"; do
    env_file="$root/shared/.env"
    db=$(env_value "$env_file" DB_DATABASE)
    db_user=$(env_value "$env_file" DB_USERNAME)
    db_pass=$(env_value "$env_file" DB_PASSWORD)
    [[ "$db" =~ ^[a-z_][a-z0-9_]*$ && "$db_user" =~ ^[a-z_][a-z0-9_]*$ ]] \
        || { warn "Skipping $root — unexpected database name/user in its .env."; continue; }

    sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<SQL
GRANT CONNECT ON DATABASE ${db} TO ${db_user};
REVOKE CONNECT ON DATABASE ${db} FROM PUBLIC;
SQL
    if PGPASSWORD="$db_pass" psql -h 127.0.0.1 -U "$db_user" -d "$db" -tAc 'SELECT 1' >/dev/null 2>&1; then
        echo "  $db: only $db_user (and the postgres admin) can connect"
    else
        sudo -u postgres psql -q -c "GRANT CONNECT ON DATABASE ${db} TO PUBLIC;"
        fail "$db_user could not connect to $db after the change — reverted it."
    fi
done

if [ -f "$DEMO_ROOT/shared/.env" ] && [ -f "$CLIENT_ROOT/shared/.env" ]; then
    demo_user=$(env_value "$DEMO_ROOT/shared/.env" DB_USERNAME)
    demo_pass=$(env_value "$DEMO_ROOT/shared/.env" DB_PASSWORD)
    client_db=$(env_value "$CLIENT_ROOT/shared/.env" DB_DATABASE)
    if PGPASSWORD="$demo_pass" psql -h 127.0.0.1 -U "$demo_user" -d "$client_db" -tAc 'SELECT 1' >/dev/null 2>&1; then
        warn "The demo's database user can still connect to ${client_db}."
    else
        echo "  confirmed: the demo's database user cannot connect to ${client_db}"
    fi
fi

# ---------------------------------------------------------------------------
# Final check
# ---------------------------------------------------------------------------

log "Checking sites after the changes"
PROBLEM=0
for root in "${INSTALLS[@]}"; do
    domain=$(site_domain "$root")
    if site_healthy "$domain"; then
        echo "  ok      $domain"
    elif [ "${HEALTHY_BEFORE[$root]}" = "1" ]; then
        PROBLEM=1
        warn "$domain was healthy before but is NOT now. Previous nginx configs are in $BACKUP_DIR."
    else
        warn "$domain still not healthy (it wasn't before either)."
    fi
done

[ "$PROBLEM" -eq 0 ] || fail "Hardening finished with a problem — see the warnings above."
log "Hardening complete. Backups of the previous configs: $BACKUP_DIR"
