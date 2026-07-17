<?php
/**
 * bsve_doctor.php - Blue Sky Video Editor preflight check (CLI only).
 *
 * Verifies everything the pipeline needs before a live test, and explains how
 * to fix whatever is missing.
 *
 *   php bsve_doctor.php           checks that cost nothing
 *   php bsve_doctor.php --api     also makes ONE real Claude call (a few cents)
 *
 * Run it as root (or with sudo) so it can check the www-data crontab and the
 * permissions on /etc/bsve/config.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bsve_doctor.php is a command line script.\n");
}

require_once __DIR__ . '/bsve_config.php';
require_once __DIR__ . '/bsve_lib.php';

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

$checkApi = in_array('--api', $argv, true);

$pass = 0;
$warn = 0;
$fail = 0;

function ok(string $label, string $detail = ''): void
{
    global $pass;
    $pass++;
    echo "  \033[32mOK\033[0m    $label" . ($detail ? "  ($detail)" : '') . "\n";
}

function warning(string $label, string $fix): void
{
    global $warn;
    $warn++;
    echo "  \033[33mWARN\033[0m  $label\n        -> $fix\n";
}

function bad(string $label, string $fix): void
{
    global $fail;
    $fail++;
    echo "  \033[31mFAIL\033[0m  $label\n        -> $fix\n";
}

function section(string $name): void
{
    echo "\n$name\n";
}

echo "Blue Sky Video Editor — preflight check\n";
echo str_repeat('=', 60), "\n";

// ---------------------------------------------------------------- PHP runtime
section('PHP runtime');

version_compare(PHP_VERSION, '8.1', '>=')
    ? ok('PHP version', PHP_VERSION)
    : bad('PHP ' . PHP_VERSION . ' is too old', 'BSVE needs PHP 8.1+.');

extension_loaded('curl')
    ? ok('curl extension')
    : bad('curl extension missing', 'sudo apt-get install -y php-curl && sudo systemctl restart apache2');

extension_loaded('mbstring')
    ? ok('mbstring extension')
    : warning('mbstring missing (optional)', 'Falls back safely. sudo apt-get install -y php-mbstring');

// ------------------------------------------------------------------ Binaries
section('Render tools');

foreach (['ffmpeg' => BSVE_FFMPEG, 'ffprobe' => BSVE_FFPROBE] as $name => $path) {
    if (is_executable($path)) {
        bsve_run([$path, '-version'], $v, 15);
        ok($name, strtok((string) $v, "\n"));
    } else {
        bad("$name not executable at $path", 'sudo apt-get install -y ffmpeg (or fix the path in bsve_config.php)');
    }
}

is_readable(BSVE_FONT)
    ? ok('caption font', basename(BSVE_FONT))
    : bad('font missing at ' . BSVE_FONT, 'sudo apt-get install -y fonts-dejavu-core');

// -------------------------------------------------------------------- Secrets
section('Anthropic API key');

$secrets = bsve_secrets();
$key = $secrets['anthropic_api_key'] ?? '';

if ($key === '') {
    warning(
        'No API key found',
        'Renders still work but skip AI planning. Create /etc/bsve/config.php per SETUP.md.'
    );
} else {
    ok('API key present', 'starts ' . substr($key, 0, 7) . '…, length ' . strlen($key));

    // The worker runs as www-data via cron, so www-data must be able to read it.
    $cfg = '/etc/bsve/config.php';
    if (is_file($cfg)) {
        $perms = substr(sprintf('%o', fileperms($cfg)), -4);
        $group = function_exists('posix_getgrgid')
            ? (posix_getgrgid(filegroup($cfg))['name'] ?? '?')
            : '?';
        if ($group === 'www-data' || $perms === '0644') {
            ok('key file readable by www-data', "mode $perms, group $group");
        } else {
            warning(
                "key file mode $perms group $group — www-data may not read it",
                'sudo chown root:www-data ' . $cfg . ' && sudo chmod 640 ' . $cfg
            );
        }
    }
}

// ------------------------------------------------------------------ Composer
section('PHP dependencies');

if (!is_readable($autoload)) {
    bad('vendor/autoload.php missing', 'Run ./deploy.sh, or: cd ' . __DIR__ . ' && composer install --no-dev');
} elseif (!class_exists('Anthropic\Client')) {
    bad('Anthropic\Client not autoloadable', 'composer install --no-dev in ' . __DIR__);
} else {
    ok('Anthropic SDK autoloads');
    // The SDK needs a concrete PSR-17/18 implementation; without one the client
    // constructor throws at runtime.
    class_exists('GuzzleHttp\Client')
        ? ok('HTTP client (Guzzle) present')
        : bad('no PSR-18 HTTP client', 'composer require guzzlehttp/guzzle');
}

// ---------------------------------------------------------------- Job storage
section('Job storage');

if (!is_dir(BSVE_JOBS_DIR)) {
    bad('jobs dir missing: ' . BSVE_JOBS_DIR, 'sudo install -d -m 775 -o www-data -g www-data ' . BSVE_JOBS_DIR);
} else {
    $owner = function_exists('posix_getpwuid')
        ? (posix_getpwuid(fileowner(BSVE_JOBS_DIR))['name'] ?? '?')
        : '?';
    $perms = substr(sprintf('%o', fileperms(BSVE_JOBS_DIR)), -4);
    ok('jobs dir exists', BSVE_JOBS_DIR . " (owner $owner, mode $perms)");

    // Apache writes uploads here; the cron worker writes renders here.
    $probe = BSVE_JOBS_DIR . '.doctor_write_test';
    if (@file_put_contents($probe, 'x') !== false) {
        @unlink($probe);
        ok('jobs dir writable by ' . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'current user'));
    } else {
        warning('jobs dir not writable as the current user', 'Fine if you are not www-data; uploads run as www-data.');
    }

    if ($owner !== 'www-data') {
        warning("jobs dir owned by $owner, not www-data", 'sudo chown -R www-data:www-data ' . BSVE_JOBS_DIR);
    }
}

// ------------------------------------------------------------- Upload limits
section('Upload limits (web SAPI)');

// This script runs under CLI, whose php.ini is NOT the one Apache uses. Read
// Apache's ini directly so the numbers reported are the ones that matter.
$apacheInis = glob('/etc/php/*/apache2/php.ini') ?: [];
$fpmInis    = glob('/etc/php/*/fpm/php.ini') ?: [];
$webInis    = array_merge($apacheInis, $fpmInis);

if ($webInis === []) {
    warning('no Apache/FPM php.ini found', 'Check upload_max_filesize / post_max_size manually.');
} else {
    foreach ($webInis as $ini) {
        $body = (string) @file_get_contents($ini);
        $get = static function (string $k) use ($body): string {
            return preg_match('/^\s*' . preg_quote($k, '/') . '\s*=\s*(\S+)/mi', $body, $m) ? $m[1] : '?';
        };
        $toBytes = static function (string $v): float {
            if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMGkmg]?)/', $v, $m)) return 0;
            $n = (float) $m[1];
            return $n * ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2])];
        };

        $umf = $get('upload_max_filesize');
        $pms = $get('post_max_size');
        $label = basename(dirname($ini)) . ' php.ini';

        $needed = BSVE_MAX_FILE_MB * 1048576;
        $toBytes($umf) >= $needed
            ? ok("$label upload_max_filesize", $umf)
            : warning("$label upload_max_filesize = $umf (< " . BSVE_MAX_FILE_MB . 'M)', "Set upload_max_filesize = " . BSVE_MAX_FILE_MB . "M in $ini, then restart apache2.");

        $toBytes($pms) >= $needed
            ? ok("$label post_max_size", $pms)
            : warning("$label post_max_size = $pms — too small for multi-clip uploads", "Set post_max_size = 2G in $ini, then restart apache2.");

        // max_input_time caps how long PHP will spend RECEIVING the request.
        // A phone uploading a few hundred MB over cellular blows past the
        // common 60s default, and the failure surfaces as a generic upload
        // error. -1 means unlimited (the CLI default; rare for the web SAPI).
        $mit = $get('max_input_time');
        if ($mit === '?') {
            warning("$label max_input_time not set", "Add max_input_time = 600 to $ini — slow mobile uploads time out otherwise.");
        } elseif ((int) $mit === -1 || (int) $mit >= 300) {
            ok("$label max_input_time", $mit);
        } else {
            warning(
                "$label max_input_time = {$mit}s — slow mobile uploads will time out",
                "Set max_input_time = 600 in $ini, then restart apache2."
            );
        }

        // max_execution_time bounds the upload handler itself. bsve_upload.php
        // only moves files and writes job.json (the render is a separate cron
        // worker), but large multipart requests still take time to process.
        $met = $get('max_execution_time');
        if ($met === '?') {
            warning("$label max_execution_time not set", "Add max_execution_time = 300 to $ini.");
        } elseif ((int) $met === 0 || (int) $met >= 120) {
            ok("$label max_execution_time", $met);
        } else {
            warning(
                "$label max_execution_time = {$met}s — may cut off large uploads",
                "Set max_execution_time = 300 in $ini, then restart apache2."
            );
        }
    }
}

// -------------------------------------------------------------------- Mailing
section('Email delivery');

function_exists('mail')
    ? ok('mail() available')
    : bad('mail() disabled', 'The finished-video link is emailed. Enable mail() or install an MTA.');

$sendmail = ini_get('sendmail_path');
$sendmail
    ? ok('sendmail_path', $sendmail)
    : warning('sendmail_path empty', 'Install an MTA (e.g. sudo apt-get install -y postfix) or mail() will fail.');

// ----------------------------------------------------------------- Cron entry
section('Render worker cron');

$cronFound = false;
foreach (['www-data', 'root'] as $user) {
    $out = @shell_exec("crontab -l -u $user 2>/dev/null");
    if ($out && str_contains($out, 'bsve_worker.php')) {
        ok("cron entry present for $user", trim(strtok($out, "\n")) !== '' ? 'see crontab -l -u ' . $user : '');
        $cronFound = true;
    }
}
if (!$cronFound) {
    bad(
        'no bsve_worker.php cron entry found (nothing will ever render)',
        "sudo crontab -u www-data -e   then add:\n           * * * * * /usr/bin/php " . __DIR__ . "/bsve_worker.php >> /var/log/bsve.log 2>&1"
    );
}

// A lock the cron user cannot open stops every render, so check it explicitly.
$legacyLock = sys_get_temp_dir() . '/bsve_worker.lock';
if (is_file($legacyLock)) {
    warning(
        "stale lock from an older version: $legacyLock",
        "No longer used — remove it: sudo rm -f $legacyLock"
    );
}

$lockPath = BSVE_JOBS_DIR . '.worker.lock';
if (!is_file($lockPath)) {
    ok('worker lock absent', 'created on the next run');
} else {
    $lockOwner = function_exists('posix_getpwuid')
        ? (posix_getpwuid(fileowner($lockPath))['name'] ?? '?')
        : '?';
    $lockPerms = substr(sprintf('%o', fileperms($lockPath)), -4);
    if ($lockOwner === 'www-data' || $lockPerms === '0666') {
        ok('worker lock usable by www-data', "owner $lockOwner, mode $lockPerms");
    } else {
        bad(
            "worker lock owned by $lockOwner (mode $lockPerms) — www-data cannot open it, so nothing will render",
            "sudo rm -f $lockPath   (it is recreated automatically)"
        );
    }
}

// The log the cron line writes to must be writable by www-data, or the
// worker's own error output is lost.
$cronLog = '/var/log/bsve.log';
if (!is_file($cronLog)) {
    warning(
        "$cronLog does not exist",
        "cron cannot create it in root-owned /var/log:\n"
        . "           sudo touch $cronLog && sudo chown www-data:www-data $cronLog"
    );
} else {
    $logOwner = function_exists('posix_getpwuid')
        ? (posix_getpwuid(fileowner($cronLog))['name'] ?? '?')
        : '?';
    $logOwner === 'www-data'
        ? ok('cron log writable by www-data', $cronLog)
        : warning(
            "$cronLog owned by $logOwner, not www-data — worker errors will be lost",
            "sudo chown www-data:www-data $cronLog"
        );
}

// ------------------------------------------------------------- Live API check
if ($checkApi) {
    section('Live Claude call (--api)');
    if ($key === '') {
        bad('cannot test: no API key', 'See the API key section above.');
    } elseif (!class_exists('Anthropic\Client')) {
        bad('cannot test: SDK not loaded', 'composer install --no-dev');
    } else {
        try {
            $t0 = microtime(true);
            $client = new Anthropic\Client(apiKey: $key);
            $msg = $client->messages->create(
                model: BSVE_MODEL,
                maxTokens: 16,
                messages: [['role' => 'user', 'content' => 'Reply with the single word: ready']],
            );
            $text = '';
            foreach ($msg->content as $b) {
                if (($b->type ?? '') === 'text') { $text .= $b->text; }
            }
            ok('Claude reachable from this server', sprintf('%s replied "%s" in %.1fs', BSVE_MODEL, trim($text), microtime(true) - $t0));
        } catch (Throwable $e) {
            bad(
                'Claude call failed: ' . $e->getMessage(),
                'Check the API key is valid and that this server has outbound HTTPS to api.anthropic.com.'
            );
        }
    }
} else {
    section('Live Claude call');
    echo "  \033[36mSKIP\033[0m  re-run with --api to make one real call (a few cents)\n";
}

// -------------------------------------------------------------------- Summary
echo "\n", str_repeat('=', 60), "\n";
printf("%d passed, %d warnings, %d failures\n", $pass, $warn, $fail);
if ($fail > 0) {
    echo "\nFix the FAIL items above before the live test.\n";
    exit(1);
}
echo $warn > 0
    ? "\nNo blockers. Review the warnings, then you are good to test.\n"
    : "\nEverything is in place. Ready for the live test.\n";
exit(0);
