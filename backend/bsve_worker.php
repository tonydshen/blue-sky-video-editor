<?php
/**
 * bsve_worker.php - Blue Sky Video Editor render worker (CLI only).
 *
 * Picks up queued jobs, asks Claude for a render plan, renders with FFmpeg,
 * and emails the finished MP4 link to the user.
 *
 *   php bsve_worker.php                 process every queued job
 *   php bsve_worker.php --job=<id>      process one job, even if not queued
 *   php bsve_worker.php --job=<id> --dry-run   print the FFmpeg command only
 *
 * Run it from cron every minute; the lock file keeps runs from overlapping.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bsve_worker.php is a command line script.\n");
}

require_once __DIR__ . '/bsve_config.php';
require_once __DIR__ . '/bsve_lib.php';

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

$options = getopt('', ['job::', 'dry-run']);
$onlyJob = $options['job'] ?? null;
$dryRun  = array_key_exists('dry-run', $options);

// Only one worker at a time.
$lock = fopen(sys_get_temp_dir() . '/bsve_worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // another run is still going; nothing to do
}

/** Load every job we should process, oldest first. */
function bsve_pending_jobs(?string $onlyJob): array
{
    $jobs = [];
    foreach (glob(BSVE_JOBS_DIR . '*/job.json') ?: [] as $file) {
        $job = json_decode((string) file_get_contents($file), true);
        if (!is_array($job)) {
            continue;
        }
        if ($onlyJob !== null) {
            if ($job['job_id'] === $onlyJob) {
                return [$job];
            }
            continue;
        }
        if (($job['status'] ?? '') === 'queued') {
            $jobs[] = $job;
        }
    }
    usort($jobs, static fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
    return $jobs;
}

function bsve_save(array $job): void
{
    file_put_contents(
        BSVE_JOBS_DIR . $job['job_id'] . '/job.json',
        json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function bsve_email_success(array $job, string $url): void
{
    $name = $job['profile']['fname'];
    $title = $job['project_title'] !== '' ? $job['project_title'] : 'your video';

    $body = "Hi {$name},\n\n"
        . "Your edited video is ready.\n\n"
        . "Watch it here:\n{$url}\n\n"
        . "Project: {$title}\n"
        . 'Clips: ' . count($job['clips']) . "\n\n"
        . "Thanks for using Blue Sky Video Editor.\n"
        . "-- datacommlab.com\n";

    $headers = 'From: ' . BSVE_FROM_EMAIL . "\r\n"
        . 'Reply-To: ' . BSVE_ADMIN_EMAIL . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    mail($job['profile']['email'], 'Your Blue Sky video is ready', $body, $headers);
    mail(
        BSVE_ADMIN_EMAIL,
        'BSVE render complete: ' . $job['job_id'],
        "Job {$job['job_id']} finished.\nUser: {$job['profile']['email']}\nURL: {$url}\n",
        $headers
    );
}

function bsve_email_failure(array $job, string $reason): void
{
    $headers = 'From: ' . BSVE_FROM_EMAIL . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    mail(
        $job['profile']['email'],
        'We could not finish your Blue Sky video',
        "Hi {$job['profile']['fname']},\n\n"
        . "Something went wrong while editing your video, and our team has been notified. "
        . "We'll look into it and get back to you.\n\n-- datacommlab.com\n",
        $headers
    );
    mail(
        BSVE_ADMIN_EMAIL,
        'BSVE render FAILED: ' . $job['job_id'],
        "Job {$job['job_id']} failed.\nUser: {$job['profile']['email']}\nReason: {$reason}\n",
        $headers
    );
}

$secrets = bsve_secrets();

foreach (bsve_pending_jobs($onlyJob) as $job) {
    $jobId  = $job['job_id'];
    $jobDir = BSVE_JOBS_DIR . $jobId;

    if (!$dryRun) {
        $job['status'] = 'rendering';
        $job['started_at'] = date('c');
        bsve_save($job);
    }
    bsve_log($jobDir, "=== job {$jobId} ===");

    try {
        // 1. Measure the clips. The planner needs real durations to pace the edit.
        foreach ($job['clips'] as $i => $clip) {
            $duration = bsve_probe_duration($clip['path']);
            if ($duration <= 0.0) {
                throw new RuntimeException("Clip {$i} is not a readable video: {$clip['path']}");
            }
            $job['clips'][$i]['duration_sec'] = $duration;
        }

        // 2. Ask Claude to plan the edit. If that fails for any reason we still
        //    render, using exactly what the user asked for.
        try {
            if (empty($secrets['anthropic_api_key'])) {
                throw new RuntimeException('No Anthropic API key configured.');
            }
            $rawPlan = bsve_request_plan($job, $secrets['anthropic_api_key']);
            bsve_log($jobDir, 'Claude plan: ' . json_encode($rawPlan));
        } catch (Throwable $e) {
            bsve_log($jobDir, 'Planner unavailable (' . $e->getMessage() . '); using the user\'s own settings.');
            $rawPlan = bsve_default_plan($job);
        }

        // 3. Clamp the plan to values we are willing to hand to FFmpeg.
        $plan = bsve_validate_plan($rawPlan, $job);
        $job['plan'] = $plan;
        bsve_log($jobDir, 'Validated plan: ' . json_encode($plan));

        // 4. Render.
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $job['project_title'] ?: 'blue-sky-video');
        $slug = trim(strtolower((string) $slug), '-') ?: 'blue-sky-video';
        $outFile = $jobDir . '/' . $slug . '.mp4';

        $args = bsve_build_ffmpeg_args($plan, $job, $jobDir, $outFile);
        bsve_log($jobDir, 'FFmpeg: ' . implode(' ', array_map('escapeshellarg', $args)));

        if ($dryRun) {
            echo implode(" \\\n  ", array_map('escapeshellarg', $args)), "\n";
            continue;
        }

        $code = bsve_run($args, $ffmpegOut, 3600);
        bsve_log($jobDir, "FFmpeg exit {$code}\n" . $ffmpegOut);

        if ($code !== 0 || !is_file($outFile)) {
            throw new RuntimeException("FFmpeg failed with exit code {$code}.");
        }

        // 5. Publish and notify.
        $url = BSVE_JOBS_URL . $jobId . '/' . rawurlencode(basename($outFile));
        $job['status'] = 'done';
        $job['url'] = $url;
        $job['finished_at'] = date('c');
        bsve_save($job);

        bsve_email_success($job, $url);
        bsve_log($jobDir, "Done: {$url}");
        echo "{$jobId}: done -> {$url}\n";
    } catch (Throwable $e) {
        $reason = $e->getMessage();
        bsve_log($jobDir, 'FAILED: ' . $reason);

        if (!$dryRun) {
            $job['status'] = 'failed';
            $job['error'] = $reason;
            $job['finished_at'] = date('c');
            bsve_save($job);
            bsve_email_failure($job, $reason);
        }
        fwrite(STDERR, "{$jobId}: failed - {$reason}\n");
    }
}

flock($lock, LOCK_UN);
fclose($lock);
