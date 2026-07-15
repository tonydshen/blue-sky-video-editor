<?php
/**
 * bsve_upload.php - Blue Sky Video Editor upload receiver.
 *
 * Accepts the clips, captions, transition, sound track, cover and ending from
 * the mobile app, writes them into a job directory, and queues the job. The
 * render itself happens in bsve_worker.php, because it takes minutes; the app
 * gets an immediate acknowledgement and the finished link arrives by email.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once __DIR__ . '/bsve_config.php';

function bsve_fail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/** Strip anything that isn't a safe filename character. */
function bsve_safe_name(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'file';
    return substr(ltrim($name, '.'), 0, 100) ?: 'file';
}

function bsve_post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    bsve_fail('POST required.', 405);
}

// --- Profile. The email is required: it is how the finished video is delivered. ---
$fname = bsve_post('fname');
$lname = bsve_post('lname');
$email = bsve_post('email');
$phone = bsve_post('phone');

if ($fname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    bsve_fail('A first name and a valid email address are required.');
}

// --- Clips. ---
$clipCount = (int) bsve_post('clip_count', '0');
if ($clipCount < 1) {
    bsve_fail('Add at least one video clip.');
}
if ($clipCount > BSVE_MAX_CLIPS) {
    bsve_fail('Too many clips. The limit is ' . BSVE_MAX_CLIPS . '.');
}

// --- Create the job directory. ---
$jobId  = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
$jobDir = BSVE_JOBS_DIR . $jobId;
$srcDir = $jobDir . '/src';

if (!mkdir($srcDir, 0755, true) && !is_dir($srcDir)) {
    bsve_fail('Could not create the job directory on the server.', 500);
}

$maxBytes = BSVE_MAX_FILE_MB * 1024 * 1024;

$clips = [];
for ($i = 0; $i < $clipCount; $i++) {
    $field = "clip_{$i}";
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        bsve_fail("Clip {$i} did not upload correctly.");
    }
    if ($_FILES[$field]['size'] > $maxBytes) {
        bsve_fail("Clip {$i} is larger than " . BSVE_MAX_FILE_MB . ' MB.');
    }

    $dest = $srcDir . '/' . sprintf('clip_%02d_', $i) . bsve_safe_name($_FILES[$field]['name']);
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
        bsve_fail("Could not store clip {$i}.", 500);
    }

    $position = bsve_post("clip_pos_{$i}", 'bottom');
    if (!in_array($position, ['top', 'bottom', 'left', 'right'], true)) {
        $position = 'bottom';
    }

    $clips[] = [
        'index'        => $i,
        'path'         => $dest,
        'text'         => bsve_cut(bsve_post("clip_text_{$i}"), 200),
        'position'     => $position,
        'duration_sec' => 0.0,   // measured by the worker
    ];
}

// --- Optional sound track: music, a song, or a recording. ---
$soundtrack = null;
if (isset($_FILES['soundtrack']) && $_FILES['soundtrack']['error'] === UPLOAD_ERR_OK) {
    if ($_FILES['soundtrack']['size'] > $maxBytes) {
        bsve_fail('The sound track is larger than ' . BSVE_MAX_FILE_MB . ' MB.');
    }
    $dest = $srcDir . '/audio_' . bsve_safe_name($_FILES['soundtrack']['name']);
    if (!move_uploaded_file($_FILES['soundtrack']['tmp_name'], $dest)) {
        bsve_fail('Could not store the sound track.', 500);
    }
    $soundtrack = ['path' => $dest, 'name' => $_FILES['soundtrack']['name']];
}

// --- The job record the worker will pick up. ---
$job = [
    'job_id'        => $jobId,
    'status'        => 'queued',
    'created_at'    => date('c'),
    'project_title' => bsve_cut(bsve_post('project_title'), 100),
    'transition'    => bsve_post('transition', 'wipeleft'),
    'clips'         => $clips,
    'soundtrack'    => $soundtrack,
    'cover'         => [
        'title'    => bsve_cut(bsve_post('cover_title'), 100),
        'subtitle' => bsve_cut(bsve_post('cover_subtitle'), 120),
    ],
    'ending' => [
        'message'    => bsve_cut(bsve_post('ending_message', 'Thanks for watching!'), 120),
        'credits'    => bsve_cut(bsve_post('ending_credits'), 120),
        'copyright'  => bsve_cut(bsve_post('ending_copyright'), 120),
        'disclaimer' => bsve_cut(bsve_post('ending_disclaimer'), 200),
    ],
    'profile' => [
        'fname' => $fname,
        'lname' => $lname,
        'email' => $email,
        'phone' => $phone,
    ],
    'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
];

if ($job['cover']['title'] === '') {
    $job['cover']['title'] = $job['project_title'] !== '' ? $job['project_title'] : 'My Video';
}

file_put_contents(
    $jobDir . '/job.json',
    json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo json_encode([
    'ok'      => true,
    'job_id'  => $jobId,
    'message' => "Your video is being edited. We'll email the link to {$email} when it's ready.",
]);
