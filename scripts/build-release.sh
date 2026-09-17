#!/usr/bin/env bash
#
# Builds a clean, versioned GarageOS release artifact from the current
# working tree — application code only. Never includes .env, .git,
# secrets, or local development artifacts, and refuses to produce a
# package if any of those slip in.
#
# Usage:
#   ./scripts/build-release.sh
#
# Produces:
#   dist/GarageOS-<version>.tar.gz
#   dist/GarageOS-<version>.tar.gz.sha256

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION_FILE="$REPO_ROOT/app/Support/Version.php"
DIST_DIR="$REPO_ROOT/dist"

log()  { echo -e "\n\033[1;32m==>\033[0m $1"; }
fail() { echo -e "\n\033[1;31mERROR:\033[0m $1" >&2; exit 1; }

sha256_tool() {
    if command -v sha256sum >/dev/null 2>&1; then
        echo "sha256sum"
    elif command -v shasum >/dev/null 2>&1; then
        echo "shasum -a 256"
    else
        fail "Neither sha256sum nor shasum is available on this machine."
    fi
}

[ -f "$VERSION_FILE" ] || fail "Can't find $VERSION_FILE."
command -v php >/dev/null 2>&1 || fail "PHP is required to build a release (used to read the version constant)."

# Read the constant via PHP itself rather than a regex — reliable
# regardless of exact formatting, and avoids relying on GNU-only
# `grep -P`, which isn't available on macOS's default grep.
VERSION=$(php -r "require '$VERSION_FILE'; echo GARAGEOS_VERSION;")
[ -n "$VERSION" ] || fail "Could not read GARAGEOS_VERSION from $VERSION_FILE."

RELEASE_NAME="GarageOS-${VERSION}"

STAGE_DIR=$(mktemp -d)
VERIFY_DIR=$(mktemp -d)
cleanup() { rm -rf "$STAGE_DIR" "$VERIFY_DIR"; }
trap cleanup EXIT

log "Building release $RELEASE_NAME"

mkdir -p "$STAGE_DIR/$RELEASE_NAME"

# Copy the working tree, excluding anything that must never ship in a
# release: local secrets, VCS metadata, editor/OS junk, and this script's
# own previous build output.
rsync -a \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='.env' \
    --exclude='.DS_Store' \
    --exclude='.claude' \
    --exclude='dist' \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='public/uploads' \
    --exclude='*.log' \
    "$REPO_ROOT/" "$STAGE_DIR/$RELEASE_NAME/"

if [ -f "$STAGE_DIR/$RELEASE_NAME/.env" ]; then
    fail "Refusing to build: a real .env made it into the staged release."
fi

if [ -d "$STAGE_DIR/$RELEASE_NAME/.git" ]; then
    fail "Refusing to build: .git made it into the staged release."
fi

mkdir -p "$DIST_DIR"

ARTIFACT="$DIST_DIR/${RELEASE_NAME}.tar.gz"
CHECKSUM_FILE="${ARTIFACT}.sha256"

rm -f "$ARTIFACT" "$CHECKSUM_FILE"

tar -czf "$ARTIFACT" -C "$STAGE_DIR" "$RELEASE_NAME"

SHA_TOOL=$(sha256_tool)
( cd "$DIST_DIR" && $SHA_TOOL "$(basename "$ARTIFACT")" > "$(basename "$CHECKSUM_FILE")" )

log "Verifying the generated package"

# 1. The checksum on disk actually matches the artifact on disk.
( cd "$DIST_DIR" && $SHA_TOOL -c "$(basename "$CHECKSUM_FILE")" >/dev/null ) \
    || fail "Checksum verification failed — the artifact does not match its own checksum file."

# 2. Re-extract into a scratch directory and confirm the files a real
#    install actually needs are present, and that nothing that should
#    never ship made it through.
tar -xzf "$ARTIFACT" -C "$VERIFY_DIR"

REQUIRED_FILES=(
    "public/index.php"
    "public/health.php"
    "config/database.php"
    "database/migrate.php"
    "database/onboard-client.php"
    "app/Support/Version.php"
    "app/Support/Env.php"
    "scripts/install-garageos.sh"
    ".env.example"
)

for f in "${REQUIRED_FILES[@]}"; do
    [ -f "$VERIFY_DIR/$RELEASE_NAME/$f" ] || fail "Verification failed: $f is missing from the built release."
done

[ ! -f "$VERIFY_DIR/$RELEASE_NAME/.env" ] || fail "Verification failed: .env is present inside the built release."
[ ! -d "$VERIFY_DIR/$RELEASE_NAME/.git" ] || fail "Verification failed: .git is present inside the built release."

# 3. Every shipped PHP file is at least syntactically valid — catches a
#    broken/partial copy before it's ever handed to a customer's installer.
if command -v php >/dev/null 2>&1; then
    while IFS= read -r -d '' phpFile; do
        php -l "$phpFile" >/dev/null || fail "Verification failed: $phpFile has a PHP syntax error."
    done < <(find "$VERIFY_DIR/$RELEASE_NAME" -name '*.php' -print0)
else
    echo "  (php not available on this machine — skipped PHP syntax verification)"
fi

log "Release verified: $ARTIFACT"
echo "Checksum ($SHA_TOOL): $(cat "$CHECKSUM_FILE")"
