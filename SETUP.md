# Blue Sky Video Editor — Setup

Two halves, wired together by the existing push/pull pipeline:

- **App** — Expo / React Native (TypeScript), built to an APK with EAS.
- **Server** — PHP on datacommlab.com. `bsve_upload.php` receives the project;
  `bsve_worker.php` renders it with FFmpeg and emails the link.

```
app  --multipart POST-->  scripts/bsve_upload.php  -->  /var/www/html/tmp/bsve/<job>/job.json
                                                              |
                                            cron --> bsve_worker.php
                                                              |
                              Claude plans the edit --> FFmpeg renders --> email the MP4 URL
```

---

## 1. Developer machine (Windows WSL, `tshen@HOUSTON`)

```bash
cd ~/android/blue-sky-video-editor
nvm use          # v20.20.2, per .nvmrc
npm install
npx expo start   # then scan the QR code with Expo Go
```

Build the APK:

```bash
npx eas init     # once — registers the project and writes extra.eas.projectId
npx eas build --platform android --profile production
```

Push to the server:

```bash
./update.sh
```

---

## 2. Server (`tshen@datacommlab.com`)

### One-time prerequisites

```bash
# FFmpeg does the rendering; the fonts are what drawtext writes with.
# php-mbstring is optional (the code falls back without it) but recommended.
sudo apt-get update
sudo apt-get install -y ffmpeg fonts-dejavu-core php-cli php-mbstring php-curl composer

# Confirm the paths bsve_config.php expects:
which ffmpeg ffprobe                                       # /usr/bin/...
ls /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf
```

If any path differs, override the constant in `bsve_config.php`.

### The Anthropic API key

The key is what lets Claude plan the edit. It **never** goes in the repo. Put it
in a file outside the web root:

```bash
sudo mkdir -p /etc/bsve
sudo tee /etc/bsve/config.php >/dev/null <<'PHP'
<?php
return [
    'anthropic_api_key' => 'sk-ant-...',
];
PHP
sudo chown root:www-data /etc/bsve/config.php
sudo chmod 640 /etc/bsve/config.php
```

Only the worker reads it. If the key is missing, renders still succeed — the
worker falls back to the user's own choices and skips the AI planning step.

### Deploy

```bash
cd ~/android/blue-sky-video-editor
./deploy.sh
```

`deploy.sh` copies the PHP into `/var/www/html/scripts/`, creates the job
directory `/var/www/html/tmp/bsve/`, runs `composer install`, and warns about
anything missing.

### PHP upload limits

Video files are large. In `/etc/php/8.x/apache2/php.ini`:

```ini
upload_max_filesize = 500M
post_max_size = 2G
max_file_uploads = 40
max_execution_time = 300
memory_limit = 256M
```

Then `sudo systemctl restart apache2`.

### The render worker (cron)

The worker is a CLI script. It locks itself, so overlapping cron runs are safe.

```bash
sudo crontab -u www-data -e
```

```cron
* * * * * /usr/bin/php /var/www/html/scripts/bsve_worker.php >> /var/log/bsve.log 2>&1
```

---

## 3. Testing a render by hand

```bash
# Show the FFmpeg command a job would run, without rendering:
php /var/www/html/scripts/bsve_worker.php --job=<job-id> --dry-run

# Force one job through, even if it already ran:
php /var/www/html/scripts/bsve_worker.php --job=<job-id>
```

Every job writes `/var/www/html/tmp/bsve/<job-id>/render.log` — the Claude plan,
the validated plan, the exact FFmpeg command, and FFmpeg's own output.

---

## How the AI planning works

`bsve_request_plan()` sends Claude the project (titles, captions, requested
transition, and each clip's measured duration) and asks for a **render plan** as
JSON, constrained by a schema. Claude decides caption wording and placement,
transition timing, cover and ending copy, and audio fades.

The plan is never trusted. `bsve_validate_plan()` runs every field through a
whitelist or a clamp:

- transition style must be one of the known FFmpeg `xfade` names
- transition duration is forced below half the shortest clip
- caption positions must be one of `top` / `bottom` / `left` / `right`
- any clip Claude drops is added back with the user's own settings
- all durations and font sizes are clamped to sane ranges

FFmpeg is then invoked as an **argv array** (no shell), and all user text reaches
`drawtext` through `textfile=` sidecar files — so a caption containing quotes,
colons or commas cannot break, or escape, the filter graph.

Cost: roughly one Opus request per render, on a small prompt. To trade quality
for cost, change `BSVE_MODEL` in `bsve_config.php` to `claude-sonnet-5`.

---

## Known limits (v1)

- **The sound track replaces the clips' own audio.** If no sound track is
  uploaded, the finished video is silent. Mixing original clip audio under the
  music is not implemented yet.
- Clips are never trimmed; each plays in full.
- Rendering is single-threaded and serial — one job at a time, by design, so a
  long render cannot starve the web server.
