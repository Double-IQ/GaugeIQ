#!/usr/bin/env bash
# Safely fast-forward the live GaugeIQ checkout to origin/main.
# Intended for cPanel Cron Jobs; do not run from the public web root.

set -Eeuo pipefail
umask 077

REPO_DIR="/home/bicheveb/GaugeIQ"
BRANCH="main"
HOME_DIR="${HOME:-/home/bicheveb}"
LOG_DIR="${HOME_DIR}/logs"
LOG_FILE="${LOG_DIR}/gaugeiq-auto-update.log"
LOCK_FILE="${HOME_DIR}/.gaugeiq-auto-update.lock"

mkdir -p "$LOG_DIR"
exec >> "$LOG_FILE" 2>&1

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')" "$*"
}

fail() {
    log "ERROR: $*"
    exit 1
}

command -v git >/dev/null 2>&1 || fail "git is not available in this cron environment."
command -v flock >/dev/null 2>&1 || fail "flock is not available; refusing to run without a lock."

[[ -d "$REPO_DIR" ]] || fail "Repository directory not found: $REPO_DIR"
TOP_LEVEL="$(git -C "$REPO_DIR" rev-parse --show-toplevel 2>/dev/null)" \
    || fail "The configured path is not a Git working tree: $REPO_DIR"
[[ "$TOP_LEVEL" == "$REPO_DIR" ]] || fail "Git root mismatch. Expected $REPO_DIR, found $TOP_LEVEL."

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    log "Another GaugeIQ update check is already running; skipping this run."
    exit 0
fi

CURRENT_BRANCH="$(git -C "$REPO_DIR" branch --show-current)"
[[ "$CURRENT_BRANCH" == "$BRANCH" ]] \
    || fail "Checkout is on branch '$CURRENT_BRANCH', not '$BRANCH'; no update applied."

REMOTE_URL="$(git -C "$REPO_DIR" remote get-url origin 2>/dev/null)" \
    || fail "Git remote 'origin' is not configured."
case "${REMOTE_URL,,}" in
    https://github.com/double-iq/gaugeiq.git|https://github.com/double-iq/gaugeiq|\
    git@github.com:double-iq/gaugeiq.git|git@github.com:double-iq/gaugeiq|\
    ssh://git@github.com/double-iq/gaugeiq.git|ssh://git@github.com/double-iq/gaugeiq)
        ;;
    *)
        fail "Origin is not the expected Double-IQ/GaugeIQ GitHub repository (remote: $REMOTE_URL)."
        ;;
esac

# Never overwrite edits to tracked files made directly on the server.
if ! git -C "$REPO_DIR" diff --quiet || ! git -C "$REPO_DIR" diff --cached --quiet; then
    fail "Tracked local changes detected. Commit or revert them before automatic updating."
fi

log "Checking origin/$BRANCH in $REPO_DIR"
git -C "$REPO_DIR" fetch --quiet origin "$BRANCH" \
    || fail "Could not fetch origin/$BRANCH. Check server connectivity and GitHub authentication."

LOCAL_SHA="$(git -C "$REPO_DIR" rev-parse HEAD)"
REMOTE_SHA="$(git -C "$REPO_DIR" rev-parse "origin/$BRANCH")"

if [[ "$LOCAL_SHA" == "$REMOTE_SHA" ]]; then
    log "Already up to date ($LOCAL_SHA)."
    exit 0
fi

# Only fast-forward. If the server checkout has commits that GitHub does not,
# stop and leave the working tree unchanged rather than merging or resetting.
if ! git -C "$REPO_DIR" merge-base --is-ancestor HEAD "origin/$BRANCH"; then
    fail "Local main and origin/main have diverged or local main is ahead. Manual review required; no reset was performed."
fi

log "Update available: $LOCAL_SHA -> $REMOTE_SHA"
git -C "$REPO_DIR" merge --ff-only --no-edit "origin/$BRANCH" \
    || fail "Fast-forward failed. No hard reset was attempted."

NEW_SHA="$(git -C "$REPO_DIR" rev-parse HEAD)"
log "Update completed successfully: $LOCAL_SHA -> $NEW_SHA"
