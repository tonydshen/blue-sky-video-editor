<?php
/**
 * bsve_config.php - Blue Sky Video Editor shared configuration.
 *
 * The Anthropic API key is NEVER stored in this repo. It is read from
 * /etc/bsve/config.php, which lives outside the web root and should be
 * mode 0640, owned by root:www-data. See SETUP.md.
 */

// Where uploads and rendered videos live on disk, and how they map to URLs.
const BSVE_JOBS_DIR = '/var/www/html/tmp/bsve/';
const BSVE_JOBS_URL = 'https://datacommlab.com/tmp/bsve/';

const BSVE_ADMIN_EMAIL = 'support@datacommlab.com';
const BSVE_FROM_EMAIL  = 'noreply@datacommlab.com';

// Binaries. Override in /etc/bsve/config.php if they live elsewhere.
const BSVE_FFMPEG  = '/usr/bin/ffmpeg';
const BSVE_FFPROBE = '/usr/bin/ffprobe';
const BSVE_FONT    = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

// Render format.
const BSVE_WIDTH  = 1280;
const BSVE_HEIGHT = 720;
const BSVE_FPS    = 30;

// Upload limits.
const BSVE_MAX_CLIPS     = 20;
const BSVE_MAX_FILE_MB   = 500;

// The model that plans the edit. See SETUP.md for cost notes.
const BSVE_MODEL = 'claude-opus-4-8';

/**
 * UTF-8-safe truncation to at most $limit characters. Uses mbstring when it is
 * available, and otherwise falls back to a multibyte-aware regex so a caption
 * is never cut through the middle of a character. This keeps the backend
 * working on PHP installs without the mbstring extension.
 */
function bsve_cut(string $text, int $limit): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $limit);
    }
    if (preg_match('/^.{0,' . $limit . '}/us', $text, $m) === 1) {
        return $m[0];
    }
    return substr($text, 0, $limit);
}

/**
 * Secrets and host-specific overrides. Returns an associative array.
 * Expected keys: anthropic_api_key (required by the worker only).
 */
function bsve_secrets(): array
{
    $path = '/etc/bsve/config.php';
    if (is_readable($path)) {
        $secrets = include $path;
        if (is_array($secrets)) {
            return $secrets;
        }
    }
    // Fall back to the environment, useful for local development.
    $key = getenv('ANTHROPIC_API_KEY');
    return $key ? ['anthropic_api_key' => $key] : [];
}
