#!/usr/bin/env bash
# Compatibility entry point for the existing cPanel cron job.
# Production updates now install verified GitHub Release packages, not git pulls.

set -Eeuo pipefail
umask 077

REPO_DIR="/home/bicheveb/GaugeIQ"
HOME_DIR="${HOME:-/home/bicheveb}"
LOG_DIR="${HOME_DIR}/logs"
LOG_FILE="${LOG_DIR}/gaugeiq-auto-update.log"
PHP_BIN="${GAUGEIQ_PHP_BIN:-/opt/cpanel/ea-php84/root/usr/bin/php}"

mkdir -p "$LOG_DIR"
exec >> "$LOG_FILE" 2>&1

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')" "$*"
}

fail() {
    log "ERROR: $*"
    exit 1
}

[[ -d "$REPO_DIR" ]] || fail "GaugeIQ repository directory not found: $REPO_DIR"
[[ -f "$REPO_DIR/cron/update-release.php" ]] || fail "The PHP release updater is missing. Deploy the latest GaugeIQ main branch first."

if [[ ! -x "$PHP_BIN" ]]; then
    PHP_BIN="$(command -v php || true)"
fi
[[ -n "$PHP_BIN" && -x "$PHP_BIN" ]] || fail "PHP CLI was not found. Set GAUGEIQ_PHP_BIN to the cPanel PHP CLI executable."

log "Checking and installing the latest stable GaugeIQ GitHub Release."
"$PHP_BIN" "$REPO_DIR/cron/update-release.php"
