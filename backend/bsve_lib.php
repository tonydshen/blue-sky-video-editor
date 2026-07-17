<?php
/**
 * bsve_lib.php - Blue Sky Video Editor: render planning and FFmpeg assembly.
 *
 * The pipeline is:
 *   job.json  ->  Claude produces a render plan (JSON)
 *             ->  bsve_validate_plan() clamps it to known-safe values
 *             ->  bsve_build_ffmpeg_args() turns it into an argv array
 *             ->  bsve_run() executes it (no shell, so nothing is interpolated)
 *
 * Claude's output is never trusted. Every field is whitelisted or clamped in
 * bsve_validate_plan(), and all user text reaches FFmpeg through drawtext
 * textfile= sidecar files rather than through the filter string.
 */

require_once __DIR__ . '/bsve_config.php';

/** Transitions we accept. These are the FFmpeg xfade transition names. */
const BSVE_TRANSITIONS = [
    'fade', 'dissolve', 'wipeleft', 'wiperight', 'wipeup', 'wipedown',
    'slideleft', 'slideright', 'slideup', 'slidedown',
    'circleopen', 'circleclose', 'smoothleft', 'smoothright', 'radial',
];

const BSVE_POSITIONS = ['top', 'bottom', 'left', 'right'];

function bsve_log(string $jobDir, string $msg): void
{
    file_put_contents(
        $jobDir . '/render.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n",
        FILE_APPEND
    );
}

/**
 * Run a command as an argv array. No shell is involved, so no user or model
 * supplied value can ever be interpreted as a shell token.
 *
 * @param string[] $argv
 */
function bsve_run(array $argv, ?string &$output = null, int $timeout = 3600): int
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($argv, $descriptors, $pipes);
    if (!is_resource($proc)) {
        $output = 'failed to start: ' . $argv[0];
        return 127;
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $deadline = time() + $timeout;
    while (true) {
        $status = proc_get_status($proc);
        $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (!$status['running']) {
            break;
        }
        if (time() > $deadline) {
            proc_terminate($proc, 9);
            $output .= "\ntimed out after {$timeout}s";
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return 124;
        }
        usleep(200000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return $status['exitcode'];
}

/** Duration of a media file in seconds, or 0.0 if it cannot be read. */
function bsve_probe_duration(string $file): float
{
    $code = bsve_run([
        BSVE_FFPROBE, '-v', 'error',
        '-show_entries', 'format=duration',
        '-of', 'default=noprint_wrappers=1:nokey=1',
        $file,
    ], $out, 60);

    return $code === 0 ? (float) trim((string) $out) : 0.0;
}

function bsve_clamp(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

/**
 * The plan we fall back to if the Claude call fails for any reason. The user's
 * own choices are honoured verbatim — the render still happens, it just isn't
 * AI-polished.
 */
function bsve_default_plan(array $job): array
{
    $clips = [];
    foreach ($job['clips'] as $i => $clip) {
        $clips[] = [
            'source_index' => $i,
            'text'         => $clip['text'],
            'position'     => $clip['position'],
            'font_size'    => 40,
        ];
    }

    $endingLines = array_values(array_filter([
        $job['ending']['message'],
        $job['ending']['credits'] ? 'Created by ' . $job['ending']['credits'] : '',
        $job['ending']['copyright'],
        $job['ending']['disclaimer'],
    ], static fn ($line) => trim((string) $line) !== ''));

    return [
        'cover' => [
            'title'        => $job['cover']['title'],
            'subtitle'     => $job['cover']['subtitle'],
            'duration_sec' => 3.0,
        ],
        'clips'      => $clips,
        'transition' => ['style' => $job['transition'], 'duration_sec' => 1.0],
        'ending'     => ['lines' => $endingLines, 'duration_sec' => 5.0],
        'audio'      => ['fade_in_sec' => 1.5, 'fade_out_sec' => 2.5],
    ];
}

/**
 * Ask Claude to plan the edit. Returns the raw decoded JSON plan.
 *
 * Claude decides: the on-screen caption wording, where each caption sits, the
 * transition style and length, the cover and ending copy, and the audio fades.
 * It works from what the user typed, plus each clip's measured duration.
 */
function bsve_request_plan(array $job, string $apiKey): array
{
    $client = new Anthropic\Client(apiKey: $apiKey);

    $schema = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => ['cover', 'clips', 'transition', 'ending', 'audio'],
        'properties'           => [
            'cover' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['title', 'subtitle', 'duration_sec'],
                'properties'           => [
                    'title'        => ['type' => 'string'],
                    'subtitle'     => ['type' => 'string'],
                    'duration_sec' => ['type' => 'number'],
                ],
            ],
            'clips' => [
                'type'  => 'array',
                'items' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => ['source_index', 'text', 'position', 'font_size'],
                    'properties'           => [
                        'source_index' => ['type' => 'integer'],
                        'text'         => ['type' => 'string'],
                        'position'     => ['type' => 'string', 'enum' => BSVE_POSITIONS],
                        'font_size'    => ['type' => 'integer'],
                    ],
                ],
            ],
            'transition' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['style', 'duration_sec'],
                'properties'           => [
                    'style'        => ['type' => 'string', 'enum' => BSVE_TRANSITIONS],
                    'duration_sec' => ['type' => 'number'],
                ],
            ],
            'ending' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['lines', 'duration_sec'],
                'properties'           => [
                    'lines'        => ['type' => 'array', 'items' => ['type' => 'string']],
                    'duration_sec' => ['type' => 'number'],
                ],
            ],
            'audio' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['fade_in_sec', 'fade_out_sec'],
                'properties'           => [
                    'fade_in_sec'  => ['type' => 'number'],
                    'fade_out_sec' => ['type' => 'number'],
                ],
            ],
        ],
    ];

    $system = <<<'SYS'
You are the video editor for Blue Sky Video Editor. Users have no editing
experience, so you make the craft decisions they cannot.

Produce a render plan for a short movie assembled from the user's clips.

Rules:
- Keep every clip, in the order given. Set source_index to the clip's index.
- Captions: clean up the user's text (fix typos and casing, trim to something
  readable on screen — aim for under 60 characters). If a clip has no text,
  leave it empty rather than inventing a caption. Never add facts you were not
  given.
- Honour the user's requested caption position unless it would collide with the
  meaning of the shot; the user's choice is a strong default.
- Honour the user's requested transition style. Choose its duration: shorter
  (0.4-0.8s) for short or fast-cut clips, longer (1-1.5s) for slow scenic ones.
  The transition must be shorter than the shortest clip.
- font_size: 32-56. Larger for short captions, smaller for long ones.
- Cover: a title (and subtitle if it helps) that suits the material. Show it
  long enough to read, 2.5-4s.
- Ending: one line per entry. Lead with the closing message, then credits,
  copyright, and any disclaimer the user supplied. Do not invent credits,
  copyright holders, or disclaimers. 3-6s.
- Audio fades: gentle fade in, and a fade out that lands on the ending card.

Return only the render plan.
SYS;

    $userMessage = json_encode([
        'project_title'   => $job['project_title'],
        'requested_transition' => $job['transition'],
        'cover'           => $job['cover'],
        'ending'          => $job['ending'],
        'clips'           => array_map(static fn ($c) => [
            'index'        => $c['index'],
            'text'         => $c['text'],
            'position'     => $c['position'],
            'duration_sec' => round($c['duration_sec'], 2),
        ], $job['clips']),
        'has_soundtrack'  => $job['soundtrack'] !== null,
        'total_video_sec' => round(array_sum(array_column($job['clips'], 'duration_sec')), 2),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $message = $client->messages->create(
        model: BSVE_MODEL,
        maxTokens: 8000,
        system: $system,
        thinking: ['type' => 'adaptive'],
        outputConfig: [
            'effort' => 'medium',
            'format' => ['type' => 'json_schema', 'schema' => $schema],
        ],
        messages: [[
            'role'    => 'user',
            'content' => "Here is the project. Plan the edit.\n\n" . $userMessage,
        ]],
    );

    if ($message->stopReason === 'refusal') {
        throw new RuntimeException('The model declined to plan this project.');
    }

    foreach ($message->content as $block) {
        if (($block->type ?? '') === 'text') {
            $plan = json_decode($block->text, true);
            if (is_array($plan)) {
                return $plan;
            }
        }
    }

    throw new RuntimeException('No render plan in the model response.');
}

/**
 * Clamp an arbitrary decoded plan into something we are willing to execute.
 * Anything missing or out of range falls back to the user's own choice.
 */
function bsve_validate_plan(array $raw, array $job): array
{
    $clipCount = count($job['clips']);
    $shortest  = min(array_column($job['clips'], 'duration_sec'));

    // --- Clips: one entry per uploaded clip, in the plan's order, no dupes. ---
    $clips = [];
    $seen  = [];
    foreach ($raw['clips'] ?? [] as $c) {
        $idx = filter_var($c['source_index'] ?? null, FILTER_VALIDATE_INT);
        if ($idx === false || $idx < 0 || $idx >= $clipCount || isset($seen[$idx])) {
            continue;
        }
        $seen[$idx] = true;

        $position = $c['position'] ?? '';
        if (!in_array($position, BSVE_POSITIONS, true)) {
            $position = $job['clips'][$idx]['position'];
        }

        $clips[] = [
            'source_index' => $idx,
            'text'         => bsve_cut(trim((string) ($c['text'] ?? '')), 120),
            'position'     => $position,
            'font_size'    => (int) bsve_clamp((float) ($c['font_size'] ?? 40), 20, 72),
        ];
    }

    // Any clip the model dropped is appended with the user's own settings —
    // the user uploaded it, so it ships.
    for ($i = 0; $i < $clipCount; $i++) {
        if (!isset($seen[$i])) {
            $clips[] = [
                'source_index' => $i,
                'text'         => bsve_cut($job['clips'][$i]['text'], 120),
                'position'     => $job['clips'][$i]['position'],
                'font_size'    => 40,
            ];
        }
    }

    if ($clips === []) {
        throw new RuntimeException('Plan contains no usable clips.');
    }

    // --- Transition: whitelist the style, and keep it shorter than the
    // shortest clip or xfade will produce garbage. ---
    $style = $raw['transition']['style'] ?? '';
    if (!in_array($style, BSVE_TRANSITIONS, true)) {
        $style = in_array($job['transition'], BSVE_TRANSITIONS, true)
            ? $job['transition']
            : 'wipeleft';
    }
    $transitionMax = max(0.2, min(2.0, $shortest * 0.5));
    $transitionDur = bsve_clamp((float) ($raw['transition']['duration_sec'] ?? 1.0), 0.2, $transitionMax);

    // --- Ending lines. ---
    $lines = [];
    foreach (array_slice((array) ($raw['ending']['lines'] ?? []), 0, 8) as $line) {
        $line = bsve_cut(trim((string) $line), 120);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    if ($lines === []) {
        $lines = ['Thanks for watching!'];
    }

    return [
        'cover' => [
            'title'        => bsve_cut(trim((string) ($raw['cover']['title'] ?? $job['cover']['title'])), 80),
            'subtitle'     => bsve_cut(trim((string) ($raw['cover']['subtitle'] ?? '')), 100),
            'duration_sec' => bsve_clamp((float) ($raw['cover']['duration_sec'] ?? 3.0), 1.5, 10.0),
        ],
        'clips'      => $clips,
        'transition' => ['style' => $style, 'duration_sec' => $transitionDur],
        'ending'     => [
            'lines'        => $lines,
            'duration_sec' => bsve_clamp((float) ($raw['ending']['duration_sec'] ?? 5.0), 2.0, 15.0),
        ],
        'audio' => [
            'fade_in_sec'  => bsve_clamp((float) ($raw['audio']['fade_in_sec'] ?? 1.5), 0.0, 10.0),
            'fade_out_sec' => bsve_clamp((float) ($raw['audio']['fade_out_sec'] ?? 2.5), 0.0, 10.0),
        ],
    ];
}

/**
 * Write text to a sidecar file and return the path. Using drawtext's textfile=
 * option keeps user text out of the filter string entirely, so quotes, colons,
 * commas and newlines in a caption cannot break (or escape) the filter graph.
 */
function bsve_text_file(string $dir, string $name, string $text): string
{
    $path = $dir . '/' . $name . '.txt';
    file_put_contents($path, $text);
    return $path;
}

/** A drawtext filter drawing $text at one of the four named positions. */
function bsve_drawtext(string $textPath, string $position, int $fontSize): string
{
    $margin = 48;
    $xy = match ($position) {
        'top'    => "x=(w-text_w)/2:y={$margin}",
        'left'   => "x={$margin}:y=(h-text_h)/2",
        'right'  => "x=w-text_w-{$margin}:y=(h-text_h)/2",
        default  => "x=(w-text_w)/2:y=h-text_h-{$margin}",
    };

    return sprintf(
        'drawtext=fontfile=%s:textfile=%s:fontcolor=white:fontsize=%d:box=1:boxcolor=black@0.45:boxborderw=14:%s',
        BSVE_FONT,
        $textPath,
        $fontSize,
        $xy
    );
}

/**
 * Turn a validated plan into an FFmpeg argv array.
 *
 * Layout of the filter graph:
 *   cover card -> clip 0 -> clip 1 -> ... -> ending card
 * chained with xfade, each segment first normalised to the same size, frame
 * rate, pixel format and SAR (xfade requires identical inputs).
 *
 * Every segment chain must END with `setpts=PTS-STARTPTS,fps=N`, in that order.
 * setpts rebases each segment's timestamps to zero (xfade needs that to place
 * the crossfades), but it also marks the stream's frame rate as unknown (1/0).
 * FFmpeg 7's xfade rejects a non-CFR input outright — "The inputs needs to be a
 * constant frame rate; current rate of 1/0 is invalid" — so the trailing fps
 * filter must come after setpts to re-declare the rate. FFmpeg 6 tolerated the
 * unknown rate, which is why this only shows up on newer servers.
 *
 * @return string[] argv
 */
function bsve_build_ffmpeg_args(array $plan, array $job, string $workDir, string $outFile): array
{
    $txtDir = $workDir . '/txt';
    if (!is_dir($txtDir)) {
        mkdir($txtDir, 0755, true);
    }

    $w = BSVE_WIDTH;
    $h = BSVE_HEIGHT;
    $fps = BSVE_FPS;
    $card = "color=c=0x0D47A1:s={$w}x{$h}:r={$fps}";

    $args = [BSVE_FFMPEG, '-y'];
    $filters = [];
    $segments = [];   // [label, duration]

    // --- Input 0: the cover card. ---
    $coverDur = $plan['cover']['duration_sec'];
    $args = array_merge($args, ['-f', 'lavfi', '-t', (string) $coverDur, '-i', $card]);

    $coverChain = ["[0:v]format=yuv420p,setsar=1"];
    $coverChain[] = sprintf(
        'drawtext=fontfile=%s:textfile=%s:fontcolor=white:fontsize=64:x=(w-text_w)/2:y=(h-text_h)/2-40',
        BSVE_FONT,
        bsve_text_file($txtDir, 'cover_title', $plan['cover']['title'])
    );
    if ($plan['cover']['subtitle'] !== '') {
        $coverChain[] = sprintf(
            'drawtext=fontfile=%s:textfile=%s:fontcolor=0xBBDEFB:fontsize=34:x=(w-text_w)/2:y=(h-text_h)/2+50',
            BSVE_FONT,
            bsve_text_file($txtDir, 'cover_subtitle', $plan['cover']['subtitle'])
        );
    }
    // setpts last, then fps, to hand xfade a CFR input (see the docblock).
    $coverChain[] = 'setpts=PTS-STARTPTS';
    $coverChain[] = "fps={$fps}";
    $filters[] = implode(',', $coverChain) . '[seg0]';
    $segments[] = ['seg0', $coverDur];

    // --- Inputs 1..n: the clips, in plan order. ---
    foreach ($plan['clips'] as $n => $clip) {
        $src = $job['clips'][$clip['source_index']];
        $args[] = '-i';
        $args[] = $src['path'];

        $input = $n + 1;                 // ffmpeg input index
        $label = 'seg' . ($n + 1);       // segment label

        $chain = [sprintf(
            '[%d:v]scale=%d:%d:force_original_aspect_ratio=decrease,'
            . 'pad=%d:%d:(ow-iw)/2:(oh-ih)/2,setsar=1,format=yuv420p',
            $input, $w, $h, $w, $h
        )];
        if ($clip['text'] !== '') {
            $chain[] = bsve_drawtext(
                bsve_text_file($txtDir, 'clip_' . $n, $clip['text']),
                $clip['position'],
                $clip['font_size']
            );
        }
        // setpts last, then fps, to hand xfade a CFR input (see the docblock).
        $chain[] = 'setpts=PTS-STARTPTS';
        $chain[] = "fps={$fps}";

        $filters[] = implode(',', $chain) . "[{$label}]";
        $segments[] = [$label, $src['duration_sec']];
    }

    // --- Input n+1: the ending card. ---
    $endInput = count($plan['clips']) + 1;
    $endDur   = $plan['ending']['duration_sec'];
    $args = array_merge($args, ['-f', 'lavfi', '-t', (string) $endDur, '-i', $card]);

    $lines = $plan['ending']['lines'];
    $endChain = ["[{$endInput}:v]format=yuv420p,setsar=1"];
    $lineHeight = 56;
    $top = (count($lines) - 1) * $lineHeight / 2;
    foreach ($lines as $i => $line) {
        // First line is the closing message, so give it more weight.
        $size = $i === 0 ? 48 : 30;
        $offset = (int) round($i * $lineHeight - $top);
        $sign = $offset < 0 ? '-' : '+';
        $endChain[] = sprintf(
            'drawtext=fontfile=%s:textfile=%s:fontcolor=white:fontsize=%d:x=(w-text_w)/2:y=(h-text_h)/2%s%d',
            BSVE_FONT,
            bsve_text_file($txtDir, 'ending_' . $i, $line),
            $size,
            $sign,
            abs($offset)
        );
    }
    // setpts last, then fps, to hand xfade a CFR input (see the docblock).
    $endChain[] = 'setpts=PTS-STARTPTS';
    $endChain[] = "fps={$fps}";
    $filters[] = implode(',', $endChain) . '[segE]';
    $segments[] = ['segE', $endDur];

    // --- Chain the segments together with xfade. ---
    // Each xfade overlaps the two inputs by `duration`, so the running length
    // grows by (next segment - transition) each time, and the next crossfade
    // starts `transition` seconds before the running end.
    $t = $plan['transition']['duration_sec'];
    $style = $plan['transition']['style'];

    [$prevLabel, $running] = $segments[0];
    for ($i = 1; $i < count($segments); $i++) {
        [$label, $dur] = $segments[$i];
        $offset = $running - $t;
        $outLabel = 'x' . $i;
        $filters[] = sprintf(
            '[%s][%s]xfade=transition=%s:duration=%s:offset=%s[%s]',
            $prevLabel,
            $label,
            $style,
            rtrim(rtrim(number_format($t, 3, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($offset, 3, '.', ''), '0'), '.'),
            $outLabel
        );
        $prevLabel = $outLabel;
        $running = $running + $dur - $t;
    }

    $totalDur = $running;

    // --- Sound track: loop it to cover the whole movie, then fade. ---
    $hasAudio = $job['soundtrack'] !== null;
    if ($hasAudio) {
        $audioInput = $endInput + 1;
        $args = array_merge($args, [
            '-stream_loop', '-1',
            '-i', $job['soundtrack']['path'],
        ]);

        $fadeIn  = $plan['audio']['fade_in_sec'];
        $fadeOut = min($plan['audio']['fade_out_sec'], $totalDur / 2);
        $fadeOutStart = max(0.0, $totalDur - $fadeOut);

        $filters[] = sprintf(
            '[%d:a]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,'
            . 'atrim=0:%s,asetpts=PTS-STARTPTS,afade=t=in:st=0:d=%s,afade=t=out:st=%s:d=%s[aout]',
            $audioInput,
            number_format($totalDur, 3, '.', ''),
            number_format($fadeIn, 3, '.', ''),
            number_format($fadeOutStart, 3, '.', ''),
            number_format($fadeOut, 3, '.', '')
        );
    }

    $args = array_merge($args, [
        '-filter_complex', implode(';', $filters),
        '-map', "[{$prevLabel}]",
    ]);
    if ($hasAudio) {
        $args = array_merge($args, ['-map', '[aout]', '-c:a', 'aac', '-b:a', '192k']);
    }
    $args = array_merge($args, [
        '-c:v', 'libx264',
        '-preset', 'medium',
        '-crf', '23',
        '-pix_fmt', 'yuv420p',
        '-r', (string) $fps,
        '-movflags', '+faststart',
        $outFile,
    ]);

    return $args;
}
