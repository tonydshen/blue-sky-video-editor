#!/bin/bash
# 03/30/26  Gemini revised
# 04/10/26  Cloude reviewed
# 04/17/26  updated for bssetdb, a branch of bsset
# 07/14/26  TS revised to suit blue-sky-video-editor
# 07/14/26  self-updating (re-exec) + preflight checks
#
# The entire script lives inside main(), which is only called on the last line.
# Bash therefore reads the whole file into memory before running anything, so
# the `git reset` below cannot corrupt this run by rewriting the file underneath
# the interpreter. If the reset changes deploy.sh itself, we re-exec the fresh
# copy once (guarded by BSVE_REEXEC) so the newest deploy steps apply on this
# same run — no need to scp deploy.sh to the server by hand.

set -e

main() {
    echo "--- Starting Deployment ---"

    SCRIPTS_DIR="/var/www/html/scripts"
    JOBS_DIR="/var/www/html/tmp/bsve"

    # 1. Sync the working tree to GitHub (the 'secret sauce' hard reset forces
    #    the server to match origin/main exactly, discarding local drift).
    git fetch origin main
    BEFORE=$(git rev-parse "HEAD:deploy.sh" 2>/dev/null || echo none)
    echo "Syncing code with GitHub (Force Reset)..."
    git reset --hard origin/main
    AFTER=$(git rev-parse "HEAD:deploy.sh" 2>/dev/null || echo none)

    # 2. If this sync updated deploy.sh, re-run the new version once so the
    #    latest deploy logic takes effect immediately.
    if [ "$BEFORE" != "$AFTER" ] && [ "${BSVE_REEXEC:-0}" != "1" ]; then
        echo "deploy.sh was updated — re-running the new version..."
        exec env BSVE_REEXEC=1 bash "$0" "$@"
    fi

    # 3. Preflight: fail fast with a clear message if a required tool is absent,
    #    instead of dying mid-run with a raw 'command not found'.
    MISSING=""
    for bin in git php composer ffmpeg ffprobe; do
        command -v "$bin" >/dev/null 2>&1 || MISSING="$MISSING $bin"
    done
    if [ -n "$MISSING" ]; then
        echo "❌ Missing required tool(s):$MISSING"
        echo "   Install everything the server needs with:"
        echo "     sudo apt-get update && sudo apt-get install -y \\"
        echo "       ffmpeg fonts-dejavu-core php-cli php-mbstring php-curl composer"
        exit 1
    fi

    # 4. Install the backend into the web root. The mobile app posts to
    #    https://datacommlab.com/scripts/bsve_upload.php, so the PHP lives in
    #    /var/www/html/scripts alongside the other Blue Sky scripts.
    echo "Installing backend into $SCRIPTS_DIR ..."
    sudo install -d -m 755 "$SCRIPTS_DIR"
    sudo install -m 644 backend/bsve_config.php "$SCRIPTS_DIR/"
    sudo install -m 644 backend/bsve_lib.php    "$SCRIPTS_DIR/"
    sudo install -m 644 backend/bsve_upload.php "$SCRIPTS_DIR/"
    sudo install -m 644 backend/bsve_worker.php "$SCRIPTS_DIR/"
    sudo install -m 644 backend/composer.json   "$SCRIPTS_DIR/"
    # composer.lock pins the exact, verified dependency versions — copy it so
    # the server installs those rather than resolving fresh ones.
    sudo install -m 644 backend/composer.lock   "$SCRIPTS_DIR/"

    # 5. Job directory: uploads and rendered MP4s. Apache must be able to write
    #    here (uploads) and read here (serving the finished video).
    echo "Ensuring job directory $JOBS_DIR ..."
    sudo install -d -m 775 -o www-data -g www-data "$JOBS_DIR"

    # 6. PHP dependencies (the Anthropic SDK + HTTP client used by the worker).
    #    --no-dev installs only runtime deps; the lock file makes it reproducible.
    echo "Installing Composer dependencies..."
    ( cd "$SCRIPTS_DIR" \
        && sudo env COMPOSER_ALLOW_SUPERUSER=1 composer install \
             --no-dev --no-interaction --optimize-autoloader )

    # 7. Warn (don't fail) about optional runtime prerequisites.
    if [ ! -r /etc/bsve/config.php ]; then
        echo "⚠️  /etc/bsve/config.php is missing — the AI planner will be skipped."
        echo "    Renders still work, using the user's own settings. See SETUP.md."
    fi

    echo "--------------------------------------"
    echo "✅ Deployment Successful at $(date)"
    echo "--------------------------------------"
}

main "$@"
