#!/bin/bash
# 03/30/26
# Gemini revised
# 04/10/26
# Cloude reviewed
# 04/17/26
# updated for bssetdb, a branch of bsset
# Exit immediately if a command exits with a non-zero status
# TS revised to suit blue-sky-post
# 07/14/26
# TS revised to suit blue-sky-video-editor
#
set -e

echo "--- Starting Deployment ---"

# 1. Fetch the latest metadata
git fetch origin main

# 2. Hard Reset: This is the 'Secret Sauce'
# It forces the server to match GitHub exactly, throwing away
# any accidental local edits or merge conflicts.
echo "Syncing code with GitHub (Force Reset)..."
git reset --hard origin/main

# 3. Install the backend into the web root.
# The mobile app posts to https://datacommlab.com/scripts/bsve_upload.php,
# so the PHP lives in /var/www/html/scripts alongside the other Blue Sky scripts.
SCRIPTS_DIR="/var/www/html/scripts"
JOBS_DIR="/var/www/html/tmp/bsve"

echo "Installing backend into $SCRIPTS_DIR ..."
sudo install -d -m 755 "$SCRIPTS_DIR"
sudo install -m 644 backend/bsve_config.php "$SCRIPTS_DIR/"
sudo install -m 644 backend/bsve_lib.php    "$SCRIPTS_DIR/"
sudo install -m 644 backend/bsve_upload.php "$SCRIPTS_DIR/"
sudo install -m 644 backend/bsve_worker.php "$SCRIPTS_DIR/"
sudo install -m 644 backend/composer.json   "$SCRIPTS_DIR/"

# 4. Job directory: uploads and rendered MP4s. Apache must be able to write here
# (uploads) and read here (serving the finished video).
echo "Ensuring job directory $JOBS_DIR ..."
sudo install -d -m 775 -o www-data -g www-data "$JOBS_DIR"

# 5. PHP dependencies (the Anthropic SDK used by the render worker).
echo "Installing Composer dependencies..."
( cd "$SCRIPTS_DIR" && sudo composer install --no-dev --no-interaction --optimize-autoloader )

# 6. Sanity check: the worker needs these to render anything.
for BIN in /usr/bin/ffmpeg /usr/bin/ffprobe; do
    if [ ! -x "$BIN" ]; then
        echo "⚠️  $BIN is missing — renders will fail. See SETUP.md."
    fi
done
if [ ! -r /etc/bsve/config.php ]; then
    echo "⚠️  /etc/bsve/config.php is missing — the AI planner will be skipped."
    echo "    Renders still work, using the user's own settings. See SETUP.md."
fi

echo "--------------------------------------"
echo "✅ Deployment Successful at $(date)"
echo "--------------------------------------"
