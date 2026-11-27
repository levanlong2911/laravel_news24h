<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Media\GeminiOmniVideoClient;
use App\Video\Media\VideoModelRegistry;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

/**
 * Compiles the clip plan from what the plan service read: pure, with no database and no provider.
 * A shot the first version cannot send stays in the plan with why, and its plan is never changed to make it fit.
 */
final class MotoClipPlanBuilder
{
    public const VERSION = 'moto-clip-plan-builder-v5';

    /** @var string the builder of a plan made under an execution policy (MotoContract::CLIP_PLAN_FORMAT_V3); a plan of the earlier formats keeps VERSION */
    public const VERSION_EXECUTION = 'moto-clip-plan-builder-v6';

    /** @var string the file checks a clip of an execution plan pins: the length of the file asked for, the part the film keeps, the audio rule and, when declared, frame rate, frames, codec, pixel format and pixel shape */
    public const POLICY_VERSION_V2 = 'moto-clip-media-policy-v2';

    /** @var string a clip a dynamic crop makes from the master of its keyframe set */
    public const DYNAMIC_CROP = 'dynamic_crop';

    /** @var string the path of a request a local processor answers, never a provider */
    public const LOCAL = 'local';

    /** @var list<string> the ways of moving a clip an execution policy knows */
    public const MOTIONS = [MotoContract::MOTION_AI_VIDEO, MotoContract::MOTION_DYNAMIC_CROP];

    /** @var list<string> what a clip opens from */
    public const INPUTS = ['keyframe', self::REFERENCES];

    /** @var list<string> the technical audio rules of a file */
    public const AUDIO_POLICIES = [MotoContract::AUDIO_ALLOW_SILENT, MotoContract::AUDIO_REQUIRE];

    /** @var array<string, array<string, string>> how an offer hands its images on, by way of moving and what the clip opens from */
    private const SENDS = [
        MotoContract::MOTION_AI_VIDEO => ['keyframe' => 'first_frame', self::REFERENCES => 'references'],
        MotoContract::MOTION_DYNAMIC_CROP => ['keyframe' => 'crop_source'],
    ];

    /** @var list<string> what an execution plan keeps of an image beside how it is sent */
    private const SENT_FIELDS = ['position', 'artifact_id', 'sha256', 'kind', 'role', 'usage', 'target', 'send_as'];

    public const FORMAT = 'moto-clip-plan-v1';

    public const SCHEMA = 'MotoClipPlan@1.6.0';

    public const FORMAT_FIXED = 'moto-clip-plan-v2';

    public const SCHEMA_FIXED = 'MotoClipPlan@2.0.0';

    /**
     * @param  array<string, mixed>  $source  the approved shot plan
     * @return bool whether every shot of the plan is one take of a fixed length made from reference images
     */
    public static function fixed(array $source): bool
    {
        return ($source['plan']['timing'] ?? null) === MotoShotsCheck::TIMING_FIXED;
    }

    /** @var string the way of making a clip that sends reference images and no keyframe */
    public const REFERENCES = 'references';

    /** @var list<string> what the plan keeps of a reference image: where it stands in the request, which file it is and what it is for */
    private const IMAGE_FIELDS = ['position', 'artifact_id', 'sha256', 'kind', 'role', 'usage', 'target'];

    public const POLICY_VERSION = 'moto-clip-media-policy-v1';

    /** @var string the only way of making a clip the first version sends */
    private const MODE = 'image_to_video';

    /** @var string words that often belong to the cutting of the film; a word alone cannot tell a note for the cut from a thing in the scene, such as colour sample cards, so it only ever warns */
    private const EDIT_WORDS = '/\b(match cut|jump cut|smash cut|hard cut|for the cut|cut to the|end cards?|title cards?|cards)\b/i';

    /**
     * @param  array<string, mixed>  $contract  the phase-10 revision the video prompts are written to
     * @param  array<string, array<string, mixed>>  $prompts  the approved video prompt of every shot or why it has none, ClipPromptWriterService::current()
     * @param  array<string, mixed>  $source  the approved shot plan, with its context
     * @param  array<string, array<string, mixed>>  $keyframes  the keyframe of every shot or why it has none
     * @param  list<array<string, mixed>>  $models  the models the flow may send, as the registry describes them
     * @param  array<string, array<string, mixed>>  $media  the file checks by way of calling a model, interactions or long_running, as config gives them now
     * @param  array<string, array<string, mixed>>  $inputs  how the clip of every shot is made, MotoClipSourceService::inputs(); a shot it does not name is made from its keyframe
     * @param  ?array<string, mixed>  $execution  the execution policy the release pins, executionPolicy(): the plan is then a moto-clip-plan-v3 whose every clip says how it is moved, from which images, with which model and for how long; null builds the plan of the earlier formats exactly as before
     * @return array<string, mixed> the plan, in the order of the shots
     */
    public static function plan(string $projectId, array $contract, array $prompts, array $source, array $keyframes, array $models, array $media, array $inputs = [], ?array $execution = null): array
    {
        if ($execution !== null) {
            return self::executionPlan($projectId, $contract, $prompts, $source, $keyframes, $models, $media, $inputs, $execution);
        }

        $ids = self::internalIds($source);
        $clips = [];

        foreach (array_values((array) $source['plan']['shots']) as $index => $row) {
            $clips[] = self::clip($row, $index + 1, $contract, (array) ($prompts[$row['id']] ?? []), $source, (array) ($keyframes[$row['id']] ?? []), $models, $media, $ids, (array) ($inputs[$row['id']] ?? []));
        }

        $fixed = self::fixed($source);

        return [
            'format' => $fixed ? self::FORMAT_FIXED : self::FORMAT,
            'schema' => $fixed ? self::SCHEMA_FIXED : self::SCHEMA,
            'project_id' => $projectId,
            'contract' => $contract,
            'source' => ['planning_stage_id' => $source['stage_id'], 'revision' => $source['revision'], 'token' => $source['token']],
            'total_ms' => (int) $source['plan']['total_ms'],
            'clips' => $clips,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $approved  the approved video prompt of the shot or why it has none
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $keyframe
     * @param  list<array<string, mixed>>  $models
     * @param  array<string, array<string, mixed>>  $media
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $input  how the clip is made: its mode, its reference images and why they cannot be sent
     * @return array<string, mixed>
     */
    private static function clip(array $row, int $order, array $contract, array $approved, array $source, array $keyframe, array $models, array $media, array $ids, array $input): array
    {
        $context = (array) $source['context'];
        $references = ($input['mode'] ?? null) === self::REFERENCES;
        $prompt = is_array($approved['prompt'] ?? null) ? $approved['prompt'] : null;
        $entry = self::model($row, $context, $models);
        $render = [
            'mode' => (string) $row['mode'],
            'model' => $entry === null ? null : (string) $entry['id'],
            'aspect_ratio' => (string) $context['aspect_ratio'],
            'resolution' => $entry === null ? null : (string) $entry['controls']['default_resolution'],
            'duration_ms' => (int) $row['render_duration_ms'],
        ];
        $used = ['start_ms' => (int) $row['used_start_ms'], 'duration_ms' => (int) $row['used_duration_ms']];
        $policy = $entry === null ? null : self::policy($entry, $render, $used, $media);
        $clip = [
            'shot_id' => (string) $row['id'],
            'scene_id' => (string) $row['scene'],
            'order' => $order,
            'input_mode' => $references ? self::REFERENCES : 'keyframe',
            'images' => $references && ($input['blocked']['code'] ?? null) !== 'references_limit' ? array_map(static fn (array $image): array => array_intersect_key($image, array_flip(self::IMAGE_FIELDS)), array_values((array) ($input['images'] ?? []))) : [],
            'render' => $render,
            'used' => $used,
            'media_policy' => $policy,
            'prompt' => $prompt,
            'keyframe' => $references ? null : ($keyframe['keyframe'] ?? null),
            'blocked' => self::fixed($source) && ! $references
                ? ['code' => 'mode_unsupported', 'message' => 'Shot '.$row['id'].' thuộc kế hoạch chỉ làm clip từ ảnh tham chiếu — chọn "Ảnh tham chiếu" ở cột Ảnh đầu vào.']
                : self::blocked($row, $prompt, (array) ($approved['blocked'] ?? []), $ids, $entry, $policy, (array) ($references ? ($input['blocked'] ?? []) : ($keyframe['blocked'] ?? []))),
        ];

        return $clip + ['hash' => CanonicalJson::hash([
            'source' => ['stage_id' => $source['stage_id'], 'content_hash' => $source['token']['content_hash']],
            'contract' => $contract,
            'clip' => $clip,
            'controls' => $entry['controls'] ?? null,
        ])];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  ?array<string, mixed>  $prompt  the approved video prompt of the shot
     * @param  array<string, mixed>  $unapproved  why the shot has no approved video prompt it can send
     * @param  list<string>  $ids
     * @param  ?array<string, mixed>  $entry
     * @param  ?array<string, mixed>  $policy
     * @param  array<string, mixed>  $keyframe  why the opening the clip is made from cannot be used: its keyframe, or its reference images when it is made from those
     * @return ?array{code: string, message: string} the first thing that stops the shot from being sent: its keyframe or its reference images, which its prompt is written from, then its prompt, then what the dispatch checks
     */
    private static function blocked(array $row, ?array $prompt, array $unapproved, array $ids, ?array $entry, ?array $policy, array $keyframe): ?array
    {
        $shot = 'Shot '.$row['id'];
        $timing = MotoShotPrompt::clipProblem($row);
        $text = $prompt === null ? null : (string) $prompt['text'];
        $named = $text === null ? [] : array_values(array_filter($ids, static fn (string $id): bool => preg_match('/(?<![A-Za-z0-9_])'.preg_quote($id, '/').'(?![A-Za-z0-9_])/', $text) === 1));

        return match (true) {
            $keyframe !== [] => ['code' => (string) $keyframe['code'], 'message' => (string) $keyframe['message']],
            $prompt === null => ['code' => (string) ($unapproved['code'] ?? 'prompt_missing'), 'message' => (string) ($unapproved['message'] ?? $shot.' chưa có prompt video đã duyệt.')],
            $named !== [] => ['code' => 'prompt_internal_reference', 'message' => $shot.' có mã nội bộ '.implode(', ', $named).' trong prompt video đã duyệt — model video không biết mã này. Viết lại prompt; không gửi.'],
            ! in_array($row['mode'], [self::MODE, MotoContract::REFERENCE_TO_VIDEO], true) => ['code' => 'mode_unsupported', 'message' => $shot.' cần chế độ '.$row['mode'].'; code chỉ gửi '.self::MODE.' và '.MotoContract::REFERENCE_TO_VIDEO.'.'],
            $timing !== null => ['code' => 'timing_unresolved', 'message' => $timing],
            $entry === null => ['code' => 'duration_unsupported', 'message' => $shot.' cần render '.self::seconds((int) $row['render_duration_ms']).' giây; model được phép không làm được. Kế hoạch shot vẫn giữ nguyên.'],
            $policy === null => ['code' => 'media_policy_missing', 'message' => $shot.': chưa có chính sách kiểm file cho model '.$entry['id'].' ở thiết lập này — không gửi.'],
            default => null,
        };
    }

    /**
     * @return list<string> the words of a prompt that often belong to the cutting of the film, for a person to read before sending; they never block, since a word alone cannot tell a note for the cut from a thing in the scene
     */
    public static function editWarnings(string $text): array
    {
        return preg_match_all(self::EDIT_WORDS, $text, $found) > 0 ? array_values(array_unique(array_map('strtolower', $found[0]))) : [];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $render
     * @param  array{start_ms: int, duration_ms: int}  $used
     * @param  array<string, array<string, mixed>>  $media
     * @return ?array<string, mixed> the checks the file of this clip must pass, read now and pinned with the plan: the page hash covers them and the request freezes these very values; null when the model has none for this setting
     */
    private static function policy(array $entry, array $render, array $used, array $media): ?array
    {
        $interactions = ($entry['method'] ?? null) === VideoModelRegistry::INTERACTIONS;
        $rules = (array) ($media[$interactions ? 'interactions' : 'long_running'] ?? []);
        $size = $interactions ? ($rules['output_sizes'][$render['aspect_ratio']][$render['resolution']] ?? null) : null;

        if (! is_int($rules['duration_tolerance_ms'] ?? null) || ! is_int($rules['av_drift_ms'] ?? null) || ($interactions && (! is_array($size) || count($size) !== 2))) {
            return null;
        }

        return [
            'version' => self::POLICY_VERSION,
            'duration_tolerance_ms' => $rules['duration_tolerance_ms'],
            'output_size' => $size === null ? null : array_map('intval', array_values($size)),
            'av_drift_ms' => $rules['av_drift_ms'],
            'used_end_ms' => $used['start_ms'] + $used['duration_ms'],
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string> every code the plan and its context use for a shot, scene, beat, part, state, configuration, background, place or person: none of them means anything to a video model
     */
    private static function internalIds(array $source): array
    {
        $context = (array) $source['context'];
        $ids = [];

        foreach ((array) $source['plan']['shots'] as $row) {
            $ids[] = $row['id'] ?? null;
            $ids[] = $row['scene'] ?? null;

            foreach ((array) ($row['coverage'] ?? []) as $item) {
                $ids[] = is_array($item) ? ($item['beat'] ?? null) : null;
            }
        }

        foreach (['parts', 'states', 'configurations', 'backgrounds', 'places', 'characters'] as $key) {
            array_push($ids, ...array_keys((array) ($context[$key] ?? [])));
        }

        return array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && preg_match('/^[A-Z]+[0-9]+(?:_[A-Z]+[0-9]+)?$/', $id) === 1)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $models
     * @return ?array<string, mixed> the first allowed model the plan's pinned capabilities name for the length and mode of the shot, and that makes that length in the frame of the film
     */
    private static function model(array $row, array $context, array $models): ?array
    {
        $length = (int) $row['render_duration_ms'];
        $pinned = [];

        foreach ((array) ($context['capabilities'] ?? []) as $capability) {
            if ((int) ($capability['duration_ms'] ?? 0) === $length) {
                $pinned = (array) ($capability['tools'][$row['mode']] ?? []);
            }
        }

        if ($length % 1000 !== 0) {
            return null;
        }

        foreach ($models as $entry) {
            if (in_array($entry['id'], $pinned, true)
                && in_array($length / 1000, (array) $entry['controls']['durations'], true)
                && in_array($context['aspect_ratio'] ?? null, (array) $entry['controls']['aspect_ratios'], true)) {
                return $entry;
            }
        }

        return null;
    }

    private static function seconds(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 3, '.', ''), '0'), '.');
    }

    /**
     * @param  array<string, mixed>  $declared  policy.clip_execution of the release, as config gives it
     * @param  string  $evidenceDir  the folder of the provider evidence files (video.provider_evidence_dir)
     * @return array<string, mixed> the policy with every offer in one shape — the model (null for the processor), how it hands its images on, the task its request names, the processor, the resolutions it may render, those it may not with why, the most images it takes, its evidence files and why it is unavailable (null when it is available) — and both ways of opening listed for each way of moving
     *
     * @throws RuntimeException when the policy is not one this code reads: another version, a length, a way, an audio rule or an offer it does not know, an evidence file the repo does not hold, or a dynamic crop whose frames do not make the content
     */
    public static function executionPolicy(array $declared, string $evidenceDir): array
    {
        $fail = static function (string $why): never {
            throw new RuntimeException('Chính sách thực thi clip của bản phát hành không đọc được: '.$why.' — không lập kế hoạch clip.');
        };
        $unknown = array_diff(array_keys($declared), ['version', 'content_duration_ms', 'default_motion_method', 'default_input_mode', 'audio_policy', 'offers', 'dynamic_crop']);

        if ($unknown !== [] || ($declared['version'] ?? null) !== MotoContract::CLIP_EXECUTION) {
            $fail($unknown === [] ? 'cần version '.MotoContract::CLIP_EXECUTION : 'khoá lạ '.implode(', ', $unknown));
        }

        $content = $declared['content_duration_ms'] ?? null;

        if (! is_int($content) || $content < 1) {
            $fail('content_duration_ms phải là số nguyên dương');
        }

        $motion = $declared['default_motion_method'] ?? null;
        $mode = $declared['default_input_mode'] ?? null;

        if (! is_string($motion) || ! is_string($mode) || ! isset(self::SENDS[$motion][$mode])) {
            $fail('cách làm mặc định '.json_encode([$motion, $mode]).' không phải cách code biết');
        }

        $audio = [];

        foreach (self::MOTIONS as $way) {
            $rule = $declared['audio_policy'][$way] ?? null;

            if (! in_array($rule, self::AUDIO_POLICIES, true)) {
                $fail("audio_policy.{$way} phải là ".implode(' hoặc ', self::AUDIO_POLICIES));
            }

            $audio[$way] = $rule;
        }

        if ($audio[MotoContract::MOTION_DYNAMIC_CROP] === MotoContract::AUDIO_REQUIRE) {
            $fail('Dynamic Crop không tạo âm thanh nên không mặc định require_audio');
        }

        $offers = [];

        foreach (self::MOTIONS as $way) {
            $offers[$way] = ['keyframe' => [], self::REFERENCES => []];

            foreach ((array) ($declared['offers'][$way] ?? []) as $input => $list) {
                if (! isset(self::SENDS[$way][$input]) || ! is_array($list) || ! array_is_list($list)) {
                    $fail("offers.{$way}.{$input} không phải cách làm code biết");
                }

                foreach ($list as $index => $offer) {
                    $offers[$way][$input][] = self::offer(is_array($offer) ? $offer : [], $way, (string) $input, "offers.{$way}.{$input}.{$index}", $evidenceDir, $fail);
                }
            }
        }

        $crop = (array) ($declared['dynamic_crop'] ?? []);
        $whole = static fn (string $key): bool => is_int($crop[$key] ?? null) && $crop[$key] > 0;

        if (($crop['processor'] ?? null) !== MotoContract::DYNAMIC_CROP_PROCESSOR || ! $whole('width') || ! $whole('height') || ! $whole('fps') || ! $whole('frames')
            || ! is_int($crop['duration_tolerance_ms'] ?? null) || $crop['duration_tolerance_ms'] < 0
            || preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', (string) ($crop['sar'] ?? '')) !== 1
            || preg_match('/^[a-z0-9]+$/', (string) ($crop['codec'] ?? '')) !== 1 || preg_match('/^[a-z0-9]+$/', (string) ($crop['pixel_format'] ?? '')) !== 1
            || array_diff(array_keys($crop), ['processor', 'width', 'height', 'fps', 'frames', 'sar', 'codec', 'pixel_format', 'duration_tolerance_ms']) !== []) {
            $fail('dynamic_crop cần processor '.MotoContract::DYNAMIC_CROP_PROCESSOR.', width, height, fps, frames, sar, codec, pixel_format và duration_tolerance_ms hợp lệ');
        }

        if ($crop['frames'] * 1000 !== $content * $crop['fps']) {
            $fail("dynamic_crop {$crop['frames']} frame ở {$crop['fps']} fps không bằng {$content} ms nội dung");
        }

        return [
            'version' => MotoContract::CLIP_EXECUTION,
            'content_duration_ms' => $content,
            'default_motion_method' => $motion,
            'default_input_mode' => $mode,
            'audio_policy' => $audio,
            'offers' => $offers,
            'dynamic_crop' => array_intersect_key($crop, array_flip(['processor', 'width', 'height', 'fps', 'frames', 'sar', 'codec', 'pixel_format', 'duration_tolerance_ms'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $offer  one offer of the policy as config gives it
     * @param  \Closure(string): never  $fail
     * @return array<string, mixed> the offer in the one shape executionPolicy() gives
     */
    private static function offer(array $offer, string $motion, string $input, string $at, string $evidenceDir, \Closure $fail): array
    {
        $crop = $motion === MotoContract::MOTION_DYNAMIC_CROP;
        $model = $offer['model'] ?? null;
        $unavailable = $offer['unavailable'] ?? null;
        $unknown = array_diff(array_keys($offer), ['model', 'send', 'task', 'processor', 'resolutions', 'unavailable_resolutions', 'max_images', 'evidence', 'unavailable']);

        if ($unknown !== []) {
            $fail("{$at} có khoá lạ ".implode(', ', $unknown));
        }

        if ($crop ? $model !== null : (! is_string($model) || preg_match('/^[a-z0-9_-]+:[A-Za-z0-9._-]+$/', $model) !== 1)) {
            $fail("{$at}.model ".($crop ? 'phải là null: Dynamic Crop không gọi model' : 'phải là mã provider:model'));
        }

        if ($unavailable !== null) {
            if (! is_string($unavailable) || trim($unavailable) === '' || array_diff(array_keys($offer), ['model', 'unavailable']) !== []) {
                $fail("{$at}: một lựa chọn chưa dùng được chỉ ghi model và lý do unavailable");
            }

            return ['model' => $model, 'send' => null, 'task' => null, 'processor' => null, 'resolutions' => [], 'unavailable_resolutions' => [], 'max_images' => 0, 'evidence' => [], 'unavailable' => $unavailable];
        }

        $task = $offer['task'] ?? null;
        $resolutions = $offer['resolutions'] ?? null;
        $closed = $offer['unavailable_resolutions'] ?? [];
        $evidence = $offer['evidence'] ?? null;
        $max = $offer['max_images'] ?? null;

        if (($offer['send'] ?? null) !== self::SENDS[$motion][$input]) {
            $fail("{$at}.send phải là ".self::SENDS[$motion][$input]);
        }

        if (($task !== null && (! is_string($task) || preg_match('/^[a-z_]+$/', $task) !== 1)) || ($crop && $task !== null)) {
            $fail("{$at}.task không hợp lệ");
        }

        if (($offer['processor'] ?? null) !== ($crop ? MotoContract::DYNAMIC_CROP_PROCESSOR : null)) {
            $fail("{$at}.processor ".($crop ? 'phải là '.MotoContract::DYNAMIC_CROP_PROCESSOR : 'chỉ dành cho Dynamic Crop'));
        }

        if (! is_array($resolutions) || $resolutions === [] || ! array_is_list($resolutions) || array_filter($resolutions, static fn (mixed $value): bool => ! is_string($value) || preg_match('/^[0-9a-z]+$/', $value) !== 1) !== [] || count(array_unique($resolutions)) !== count($resolutions)) {
            $fail("{$at}.resolutions phải là danh sách resolution không trùng");
        }

        if (! is_array($closed) || ! array_is_list($closed) || array_filter($closed, static fn (mixed $row): bool => ! is_array($row) || array_keys($row) !== ['resolution', 'reason'] || ! is_string($row['resolution']) || preg_match('/^[0-9a-z]+$/', $row['resolution']) !== 1 || in_array($row['resolution'], $resolutions, true) || ! is_string($row['reason']) || trim($row['reason']) === '') !== []) {
            $fail("{$at}.unavailable_resolutions phải là danh sách {resolution, reason} ngoài resolutions");
        }

        if (! is_int($max) || $max < 1 || ($input === 'keyframe' && $max !== 1)) {
            $fail("{$at}.max_images phải là số nguyên dương, đúng 1 khi clip mở từ keyframe");
        }

        if (! is_array($evidence) || ! array_is_list($evidence) || (! $crop && $evidence === [])) {
            $fail("{$at}.evidence phải liệt kê file bằng chứng".($crop ? '' : ' của lựa chọn này'));
        }

        foreach ($evidence as $file) {
            if (! is_string($file) || preg_match('/^[A-Za-z0-9._-]+\.json$/', $file) !== 1 || ! is_file(rtrim($evidenceDir, '/\\').DIRECTORY_SEPARATOR.$file)) {
                $fail("{$at}: file bằng chứng ".json_encode($file).' không có trong '.basename($evidenceDir));
            }
        }

        return ['model' => $model, 'send' => self::SENDS[$motion][$input], 'task' => $task, 'processor' => $crop ? MotoContract::DYNAMIC_CROP_PROCESSOR : null, 'resolutions' => $resolutions, 'unavailable_resolutions' => $closed, 'max_images' => $max, 'evidence' => $evidence, 'unavailable' => null];
    }

    /**
     * @param  array<string, mixed>  $execution  executionPolicy()
     * @param  array<string, mixed>  $settings  the settings of the shot (MotoClipSourceService::inputs()[shot]['settings']): motion_method, input_mode, model, resolution and audio_policy, each null for what the policy gives
     * @param  list<array<string, mixed>>  $models  the registry models the policy offers, as the registry describes them
     * @param  string  $aspect  the frame of the film
     * @param  string  $subject  who the messages are about, such as "Shot SC01_SH01"
     * @return array{motion_method: string, input_mode: string, offer: ?array<string, mixed>, entry: ?array<string, mixed>, render: array<string, mixed>, audio_policy: string, max_images: int, blocked: ?array{code: string, message: string}} what the choice sends: the offer of the policy and the registry entry, how the request goes (render: mode, transport, task, model, adapter, processor, aspect_ratio, resolution and duration_ms, the smallest length the model makes that covers the content), the audio rule, the most images it takes and why it cannot be sent; a choice the policy or the registry does not hold is blocked, never changed for another
     */
    public static function capability(array $execution, array $settings, array $models, string $aspect, string $subject): array
    {
        $motion = in_array($settings['motion_method'] ?? null, self::MOTIONS, true) ? (string) $settings['motion_method'] : (string) $execution['default_motion_method'];
        $mode = in_array($settings['input_mode'] ?? null, self::INPUTS, true) ? (string) $settings['input_mode'] : (string) $execution['default_input_mode'];
        $crop = $motion === MotoContract::MOTION_DYNAMIC_CROP;
        $render = ['mode' => $crop ? self::DYNAMIC_CROP : ($mode === self::REFERENCES ? MotoContract::REFERENCE_TO_VIDEO : self::MODE), 'transport' => null, 'task' => null, 'model' => null, 'adapter' => null, 'processor' => null, 'aspect_ratio' => $aspect, 'resolution' => null, 'duration_ms' => null];
        $audio = in_array($settings['audio_policy'] ?? null, self::AUDIO_POLICIES, true) ? (string) $settings['audio_policy'] : (string) $execution['audio_policy'][$motion];
        $offers = (array) ($execution['offers'][$motion][$mode] ?? []);
        $available = array_values(array_filter($offers, static fn (array $offer): bool => $offer['unavailable'] === null));
        $wanted = is_string($settings['model'] ?? null) ? $settings['model'] : null;
        $result = static fn (array $render, ?array $blocked, ?array $offer = null, ?array $entry = null, int $max = 0): array => ['motion_method' => $motion, 'input_mode' => $mode, 'offer' => $offer, 'entry' => $entry, 'render' => $render, 'audio_policy' => $audio, 'max_images' => $max, 'blocked' => $blocked];
        $block = static fn (string $code, string $message): array => ['code' => $code, 'message' => $subject.': '.$message];

        if ($offers === []) {
            return $result($render, $block('input_unsupported', $crop ? 'Dynamic Crop chỉ làm từ bộ keyframe đã duyệt, không dùng ảnh tham chiếu.' : "chính sách thực thi của bản phát hành không có cách làm {$motion} từ {$mode}."));
        }

        if ($crop && $wanted !== null) {
            return $result($render, $block('model_unsupported', "Dynamic Crop không gọi model video ({$wanted})."));
        }

        $offer = $crop || $wanted === null ? ($available[0] ?? null) : (array_values(array_filter($offers, static fn (array $row): bool => $row['model'] === $wanted))[0] ?? null);

        if ($offer === null) {
            return $result($render, $wanted === null
                ? $block('model_unavailable', 'chưa có model nào dùng được cho cách làm này — '.implode(' · ', array_map(static fn (array $row): string => $row['model'].': '.$row['unavailable'], $offers)))
                : $block('model_unsupported', "model {$wanted} không có trong chính sách thực thi cho cách làm {$motion} từ {$mode}."));
        }

        $render['model'] = $offer['model'];

        if ($offer['unavailable'] !== null) {
            return $result($render, $block('model_unavailable', "model {$offer['model']} chưa dùng được — {$offer['unavailable']}"), $offer);
        }

        $resolution = is_string($settings['resolution'] ?? null) ? $settings['resolution'] : (string) $offer['resolutions'][0];
        $closed = array_column($offer['unavailable_resolutions'], 'reason', 'resolution');
        $name = $offer['model'] ?? (string) $offer['processor'];

        if (isset($closed[$resolution]) || ! in_array($resolution, $offer['resolutions'], true)) {
            return $result($render, $block('resolution_unsupported', "{$name} ở {$resolution} chưa dùng được — ".($closed[$resolution] ?? 'chính sách thực thi không mở resolution này.')), $offer);
        }

        if ($crop) {
            $output = $execution['dynamic_crop'];
            $render = array_replace($render, ['transport' => self::LOCAL, 'adapter' => $offer['processor'], 'processor' => $offer['processor'], 'resolution' => $resolution, 'duration_ms' => (int) $execution['content_duration_ms']]);
            $divisor = self::divisor((int) $output['width'], (int) $output['height']);

            return match (true) {
                $aspect !== intdiv((int) $output['width'], $divisor).':'.intdiv((int) $output['height'], $divisor) => $result($render, $block('aspect_unsupported', "Dynamic Crop xuất {$output['width']}×{$output['height']}, không phải khung {$aspect} của phim."), $offer),
                $audio === MotoContract::AUDIO_REQUIRE => $result($render, $block('audio_unsupported', 'Dynamic Crop không tạo âm thanh — chọn allow_silent hoặc làm clip bằng AI Video.'), $offer),
                default => $result($render, null, $offer, null, 1),
            };
        }

        $entry = array_values(array_filter($models, static fn (array $row): bool => ($row['id'] ?? null) === $offer['model']))[0] ?? null;

        if ($entry === null) {
            return $result($render, $block('model_unsupported', "model {$offer['model']} không có trong registry video, hoặc bằng chứng của registry không đọc được."), $offer);
        }

        $interactions = ($entry['method'] ?? null) === VideoModelRegistry::INTERACTIONS;
        $controls = (array) $entry['controls'];
        $render = array_replace($render, ['transport' => (string) $entry['method'], 'task' => $offer['task'], 'adapter' => $interactions ? GeminiOmniVideoClient::PAYLOAD_VERSION : SceneClipDispatchService::PROVIDER_PAYLOAD_VERSION]);

        if (! in_array($aspect, (array) ($controls['aspect_ratios'] ?? []), true)) {
            return $result($render, $block('aspect_unsupported', "registry không khai khung {$aspect} cho {$offer['model']}."), $offer, $entry);
        }

        if (! in_array($resolution, (array) ($controls['resolutions'] ?? []), true)) {
            return $result($render, $block('resolution_unsupported', "registry không khai resolution {$resolution} cho {$offer['model']}."), $offer, $entry);
        }

        $render['resolution'] = $resolution;
        $render['duration_ms'] = self::sourceDuration($controls, $resolution, (int) $execution['content_duration_ms']);

        if ($render['duration_ms'] === null) {
            return $result($render, $block('duration_unsupported', "{$offer['model']} ở {$resolution} không làm được file dài ít nhất ".self::seconds((int) $execution['content_duration_ms']).' giây. Kế hoạch shot vẫn giữ nguyên.'), $offer, $entry);
        }

        return $result($render, null, $offer, $entry, $interactions ? min((int) $offer['max_images'], (int) ($controls['max_reference_images'] ?? 0)) : (int) $offer['max_images']);
    }

    /**
     * @param  array<string, mixed>  $controls  the controls of a registry model
     * @return ?int the smallest length in milliseconds the model renders at that resolution that holds the content: a resolution the registry ties to one length takes only that length; null when none does
     */
    private static function sourceDuration(array $controls, string $resolution, int $content): ?int
    {
        $seconds = array_values(array_filter((array) ($controls['durations'] ?? []), static fn (mixed $value): bool => is_int($value) && $value > 0));

        if (in_array($resolution, (array) ($controls['long_resolutions'] ?? []), true)) {
            $seconds = array_values(array_intersect($seconds, [$controls['long_resolution_duration'] ?? null]));
        }

        if (is_int($controls['max_seconds'] ?? null)) {
            $seconds = array_values(array_filter($seconds, static fn (int $value): bool => $value <= $controls['max_seconds']));
        }

        $covering = array_values(array_filter($seconds, static fn (int $value): bool => $value * 1000 >= $content));

        return $covering === [] ? null : min($covering) * 1000;
    }

    private static function divisor(int $a, int $b): int
    {
        return $b === 0 ? max(1, $a) : self::divisor($b, $a % $b);
    }

    /**
     * @param  array<string, mixed>  $execution  executionPolicy()
     * @param  list<array<string, mixed>>  $models  the registry models the policy offers
     * @return list<array{motion_method: string, input_mode: string, model: ?string, processor: ?string, label: string, available: bool, reason: ?string, max_images: int, resolutions: list<array{resolution: string, available: bool, duration_ms: ?int, reason: ?string}>}> every offer of the policy for a screen to show: whether it can be chosen, the length of the file each resolution renders, and why a combination cannot be chosen; a combination without evidence is listed with its reason, never left out
     */
    public static function options(array $execution, array $models, string $aspect): array
    {
        $options = [];

        foreach ($execution['offers'] as $motion => $byInput) {
            foreach ($byInput as $mode => $offers) {
                foreach ($offers as $offer) {
                    $choice = ['motion_method' => $motion, 'input_mode' => $mode, 'model' => $motion === MotoContract::MOTION_DYNAMIC_CROP ? null : $offer['model'], 'audio_policy' => MotoContract::AUDIO_ALLOW_SILENT];
                    $resolutions = [];

                    foreach ([...$offer['resolutions'], ...array_column($offer['unavailable_resolutions'], 'resolution')] as $resolution) {
                        $checked = self::capability($execution, ['resolution' => $resolution] + $choice, $models, $aspect, 'Lựa chọn này');
                        $resolutions[] = ['resolution' => (string) $resolution, 'available' => $checked['blocked'] === null, 'duration_ms' => $checked['render']['duration_ms'], 'reason' => $checked['blocked']['message'] ?? null];
                    }

                    $whole = self::capability($execution, $choice, $models, $aspect, 'Lựa chọn này');
                    $entry = array_values(array_filter($models, static fn (array $row): bool => ($row['id'] ?? null) === $offer['model']))[0] ?? null;
                    $options[] = [
                        'motion_method' => (string) $motion,
                        'input_mode' => (string) $mode,
                        'model' => $offer['model'],
                        'processor' => $offer['processor'],
                        'label' => (string) ($entry['label'] ?? $offer['model'] ?? $offer['processor']),
                        'available' => $offer['unavailable'] === null && in_array(true, array_column($resolutions, 'available'), true),
                        'reason' => $offer['unavailable'] ?? ($whole['blocked']['message'] ?? null),
                        'max_images' => (int) $whole['max_images'],
                        'resolutions' => $resolutions,
                    ];
                }
            }
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  array<string, array<string, mixed>>  $prompts
     * @param  array<string, mixed>  $source
     * @param  array<string, array<string, mixed>>  $keyframes
     * @param  list<array<string, mixed>>  $models
     * @param  array<string, array<string, mixed>>  $media
     * @param  array<string, array<string, mixed>>  $inputs
     * @param  array<string, mixed>  $execution
     * @return array<string, mixed> the plan of format moto-clip-plan-v3, in the order of the shots
     */
    private static function executionPlan(string $projectId, array $contract, array $prompts, array $source, array $keyframes, array $models, array $media, array $inputs, array $execution): array
    {
        $ids = self::internalIds($source);
        $clips = [];

        foreach (array_values((array) $source['plan']['shots']) as $index => $row) {
            $clips[] = self::executionClip($row, $index + 1, $contract, (array) ($prompts[$row['id']] ?? []), $source, $keyframes[$row['id']] ?? null, $models, $media, $ids, (array) ($inputs[$row['id']] ?? []), $execution);
        }

        return [
            'format' => MotoContract::CLIP_PLAN_FORMAT_V3,
            'schema' => MotoContract::CLIP_PLAN_SCHEMA_V3,
            'project_id' => $projectId,
            'contract' => $contract,
            'execution_policy' => $execution,
            'source' => ['planning_stage_id' => $source['stage_id'], 'revision' => $source['revision'], 'token' => $source['token']],
            'total_ms' => (int) $source['plan']['total_ms'],
            'clips' => $clips,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $approved  the approved video prompt of the shot or why it has none
     * @param  array<string, mixed>  $source
     * @param  ?array<string, mixed>  $keyframe  MotoClipSourceService::keyframes() of the shot: its keyframe, its set and why it has none
     * @param  list<array<string, mixed>>  $models
     * @param  array<string, array<string, mixed>>  $media
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $input  MotoClipSourceService::inputs() of the shot: its settings, its reference images and why they cannot be sent
     * @param  array<string, mixed>  $execution
     * @return array<string, mixed> the clip of the shot under the execution policy, with the hash of everything its request depends on
     */
    private static function executionClip(array $row, int $order, array $contract, array $approved, array $source, ?array $keyframe, array $models, array $media, array $ids, array $input, array $execution): array
    {
        $shotId = (string) $row['id'];
        $subject = 'Shot '.$shotId;
        $settings = (array) ($input['settings'] ?? []);
        $capability = self::capability($execution, $settings, $models, (string) $source['context']['aspect_ratio'], $subject);
        $crop = $capability['motion_method'] === MotoContract::MOTION_DYNAMIC_CROP;
        $references = $capability['input_mode'] === self::REFERENCES;
        $content = (int) $execution['content_duration_ms'];
        $used = ['start_ms' => 0, 'duration_ms' => $content];
        $set = ! $references && is_array($keyframe['keyframe'] ?? null) && is_array($keyframe['set'] ?? null) ? $keyframe['set'] : null;
        $pin = $set === null ? null : self::keyframePin((array) $keyframe['keyframe'], $set, $crop);
        $images = match (true) {
            $references => array_values(array_map(static fn (array $image): array => array_intersect_key($image + ['send_as' => 'reference'], array_flip(self::SENT_FIELDS)), (array) ($input['images'] ?? []))),
            $pin === null || ($crop && ! $pin['framed']) => [],
            default => [['position' => 0, 'artifact_id' => $pin['artifact_id'], 'sha256' => $pin['sha256'], 'kind' => 'keyframe', 'role' => $crop ? 'keyframe_master' : 'keyframe_delivery', 'usage' => $crop ? 'crop_source' : 'first_frame', 'target' => null, 'send_as' => $crop ? 'crop_source' : 'first_frame']],
        };
        $cropPath = $crop ? self::cropPath($settings['crop_plan'] ?? null, $pin, $execution, $subject) : ['plan' => null, 'problem' => null];
        $prompt = ! $crop && is_array($approved['prompt'] ?? null) ? $approved['prompt'] : null;
        $policy = $capability['blocked'] === null ? self::executionMedia($capability['render'], $capability['audio_policy'], $used, $media, $execution) : null;
        $clip = [
            'shot_id' => $shotId,
            'scene_id' => (string) $row['scene'],
            'order' => $order,
            'execution_policy' => (string) $execution['version'],
            'settings' => ['version' => MotoContract::CLIP_SETTINGS, 'revision' => (int) ($settings['revision'] ?? 0)],
            'motion_method' => $capability['motion_method'],
            'input_mode' => $capability['input_mode'],
            'content_duration_ms' => $content,
            'render' => $capability['render'],
            'used' => $used,
            'keyframe' => $pin,
            'images' => $images,
            'crop_plan' => $cropPath['plan'],
            'audio_policy' => $capability['audio_policy'],
            'media_policy' => $policy,
            'prompt' => $prompt,
            'blocked' => match (true) {
                is_string($settings['problem'] ?? null) => ['code' => 'settings_invalid', 'message' => $subject.': '.$settings['problem']],
                $capability['blocked'] !== null => $capability['blocked'],
                default => self::sourceBlocked($subject, $references, $crop, $keyframe, $set, $pin, $input, $images, $capability['max_images'], $cropPath['problem'])
                    ?? self::executionBlocked($row, $prompt, (array) ($approved['blocked'] ?? []), $ids, $policy, $crop),
            },
        ];

        return $clip + ['hash' => CanonicalJson::hash([
            'source' => ['stage_id' => $source['stage_id'], 'content_hash' => $source['token']['content_hash']],
            'contract' => $contract,
            'execution' => CanonicalJson::hash($execution),
            'clip' => $clip,
            'controls' => $capability['entry']['controls'] ?? null,
            'derivative' => $capability['render']['transport'] === VideoModelRegistry::INTERACTIONS ? ($media['interactions']['reference'] ?? null) : null,
        ])];
    }

    /**
     * @param  array<string, mixed>  $keyframe  the keyframe pin of MotoClipSourceService::keyframes()
     * @param  array<string, mixed>  $set  the approved set it belongs to
     * @return ?array<string, mixed> what the clip pins of its keyframe set: the set, the decision that approved it, its master, its delivery image and crop, and the image this clip is made from — the delivery for an AI video, the master for a dynamic crop; null when no decision approved the set
     */
    private static function keyframePin(array $keyframe, array $set, bool $crop): ?array
    {
        if (! is_string($set['decision_id'] ?? null) || $set['decision_id'] === '') {
            return null;
        }

        $made = $crop ? (array) $set['master'] : (array) $set['delivery'];

        return [
            'image_id' => (string) $set['image_id'],
            'decision_id' => (string) $set['decision_id'],
            'framed' => (bool) $set['framed'],
            'artifact_id' => (string) $made['artifact_id'],
            'sha256' => (string) $made['sha256'],
            'master' => (array) $set['master'],
            'delivery' => (array) $set['delivery'],
            'crop' => $set['crop'],
            'crop_hash' => $set['crop_hash'],
            'source_token' => $keyframe['source_token'],
            'prompt' => $keyframe['prompt'],
        ];
    }

    /**
     * @param  ?array<string, mixed>  $keyframe  keyframes() of the shot
     * @param  ?array<string, mixed>  $set
     * @param  ?array<string, mixed>  $pin
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $images
     * @param  ?array{code: string, message: string}  $crop  why the crop path cannot be used
     * @return ?array{code: string, message: string} why what the clip is made from cannot be used: its reference images, its keyframe set, the master a dynamic crop needs or its crop path
     */
    private static function sourceBlocked(string $subject, bool $references, bool $dynamic, ?array $keyframe, ?array $set, ?array $pin, array $input, array $images, int $max, ?array $crop): ?array
    {
        if ($references) {
            return match (true) {
                is_array($input['blocked'] ?? null) => ['code' => (string) $input['blocked']['code'], 'message' => (string) $input['blocked']['message']],
                $images === [] => ['code' => 'references_missing', 'message' => $subject.' chưa có ảnh tham chiếu nào để gửi — giữ ít nhất một ảnh ở cột Ảnh đầu vào.'],
                count($images) > $max => ['code' => 'references_limit', 'message' => $subject.' gửi '.count($images).' ảnh tham chiếu, vượt giới hạn '.$max.' ảnh của model đã chọn — bỏ bớt ảnh hoặc chọn model khác; không ảnh nào bị bỏ tự động.'],
                default => null,
            };
        }

        return match (true) {
            is_array($keyframe['blocked'] ?? null) => ['code' => (string) $keyframe['blocked']['code'], 'message' => (string) $keyframe['blocked']['message']],
            $keyframe === null || ! is_array($keyframe['keyframe'] ?? null) => ['code' => 'keyframe_missing', 'message' => $subject.' chưa có keyframe đã duyệt ở màn Keyframes — không tự chuyển sang ảnh tham chiếu.'],
            $set === null => ['code' => 'keyframe_link_invalid', 'message' => $subject.': keyframe đã duyệt không phải bộ keyframe màn Keyframes đang giữ cho shot.'],
            $pin === null => ['code' => 'keyframe_unapproved', 'message' => $subject.': bộ keyframe chưa có quyết định duyệt nào còn hiệu lực.'],
            $dynamic && ! $pin['framed'] => ['code' => 'keyframe_master_missing', 'message' => $subject.': bộ keyframe không có ảnh master 16:9 và vùng crop đã duyệt — Dynamic Crop cần bộ ảnh được dựng theo khung.'],
            $dynamic && $crop !== null => $crop,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  ?array<string, mixed>  $prompt  the approved video prompt of an AI video, null for a dynamic crop
     * @param  array<string, mixed>  $unapproved
     * @param  list<string>  $ids
     * @param  ?array<string, mixed>  $policy
     * @return ?array{code: string, message: string} what stops a clip whose sources hold: an AI video without an approved prompt or with an internal id in it, a timeline that does not hold the content, or no file checks for its setting; a dynamic crop needs no prompt
     */
    private static function executionBlocked(array $row, ?array $prompt, array $unapproved, array $ids, ?array $policy, bool $dynamic): ?array
    {
        $shot = 'Shot '.$row['id'];
        $timing = MotoShotPrompt::clipProblem($row);
        $text = $prompt === null ? null : (string) $prompt['text'];
        $named = $text === null ? [] : array_values(array_filter($ids, static fn (string $id): bool => preg_match('/(?<![A-Za-z0-9_])'.preg_quote($id, '/').'(?![A-Za-z0-9_])/', $text) === 1));

        return match (true) {
            ! $dynamic && $prompt === null => ['code' => (string) ($unapproved['code'] ?? 'prompt_missing'), 'message' => (string) ($unapproved['message'] ?? $shot.' chưa có prompt video đã duyệt.')],
            $named !== [] => ['code' => 'prompt_internal_reference', 'message' => $shot.' có mã nội bộ '.implode(', ', $named).' trong prompt video đã duyệt — model video không biết mã này. Viết lại prompt; không gửi.'],
            $timing !== null => ['code' => 'timing_unresolved', 'message' => $timing],
            $policy === null => ['code' => 'media_policy_missing', 'message' => $shot.': chưa có chính sách kiểm file cho thiết lập này — không gửi.'],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $render  capability()['render']
     * @param  array{start_ms: int, duration_ms: int}  $used
     * @param  array<string, array<string, mixed>>  $media
     * @param  array<string, mixed>  $execution
     * @return ?array<string, mixed> the checks the file of the clip must pass, pinned with the plan: the length asked for with its tolerance, the size (always for interactions; for a long-running model the size measured for that frame and resolution when the media rules hold one, else null), the drift of sound and picture, the part the film keeps, the audio rule, and for a dynamic crop the frame rate, frames, codec, pixel format and pixel shape it declares; null when the model has none for this setting
     */
    private static function executionMedia(array $render, string $audio, array $used, array $media, array $execution): ?array
    {
        $kept = ['used_start_ms' => $used['start_ms'], 'used_end_ms' => $used['start_ms'] + $used['duration_ms'], 'audio' => $audio];

        if ($render['transport'] === self::LOCAL) {
            $crop = $execution['dynamic_crop'];

            return ['version' => self::POLICY_VERSION_V2, 'duration_ms' => (int) $render['duration_ms'], 'duration_tolerance_ms' => (int) $crop['duration_tolerance_ms'], 'output_size' => [(int) $crop['width'], (int) $crop['height']], 'av_drift_ms' => null]
                + $kept + ['fps' => (int) $crop['fps'], 'frames' => (int) $crop['frames'], 'codec' => (string) $crop['codec'], 'pixel_format' => (string) $crop['pixel_format'], 'sar' => (string) $crop['sar']];
        }

        $interactions = $render['transport'] === VideoModelRegistry::INTERACTIONS;
        $rules = (array) ($media[$interactions ? 'interactions' : 'long_running'] ?? []);
        $size = $rules['output_sizes'][$render['aspect_ratio']][$render['resolution']] ?? null;

        if (! is_int($render['duration_ms']) || ! is_int($rules['duration_tolerance_ms'] ?? null) || ! is_int($rules['av_drift_ms'] ?? null) || (($interactions || $size !== null) && (! is_array($size) || count($size) !== 2))) {
            return null;
        }

        return ['version' => self::POLICY_VERSION_V2, 'duration_ms' => $render['duration_ms'], 'duration_tolerance_ms' => $rules['duration_tolerance_ms'], 'output_size' => $size === null ? null : array_map('intval', array_values($size)), 'av_drift_ms' => $rules['av_drift_ms']]
            + $kept + ['fps' => null, 'frames' => null, 'codec' => null, 'pixel_format' => null, 'sar' => null];
    }

    /**
     * @param  mixed  $saved  the crop plan saved for the shot: a path of {at_ms, x, y, width, height}, with or without its version, and optionally protected, the region {x, y, width, height} of the subject a person confirmed
     * @param  ?array<string, mixed>  $keyframe  keyframePin() of the shot under a dynamic crop: the size of its master and the crop approved with the set
     * @param  array<string, mixed>  $execution  executionPolicy()
     * @return array{plan: ?array{version: string, path: list<array{at_ms: int, x: int, y: int, width: int, height: int}>, protected: ?array{x: int, y: int, width: int, height: int}, hash: string}, problem: ?array{code: string, message: string}} the path as the clip pins it with the protected region (null when none was confirmed) and the hash of the path, and why it cannot be used: none saved, a point that is not whole pixels and milliseconds, times that do not run forward from 0 within the content, a rectangle off the frame of the output, smaller than the output (no enlarging), outside the master, a first rectangle that is not the approved crop, or a protected region that is no rectangle, leaves the master or is not inside every point. The window moves linearly between two points and holds after the last, so each window of the clip is a blend of two points and holds the region whenever both do. Nothing is rounded or moved
     */
    public static function cropPath(mixed $saved, ?array $keyframe, array $execution, string $subject): array
    {
        $path = is_array($saved) ? ($saved['path'] ?? null) : null;
        $invalid = static fn (string $why): array => ['plan' => null, 'problem' => ['code' => 'crop_plan_invalid', 'message' => $subject.': đường crop '.$why.'.']];

        if (! is_array($path) || $path === []) {
            return ['plan' => null, 'problem' => ['code' => 'crop_plan_missing', 'message' => $subject.' chưa có đường crop — lưu đường crop (mốc thời gian và vùng lấy mẫu) trước khi tạo clip Dynamic Crop.']];
        }

        if (! array_is_list($path) || array_diff(array_keys($saved), ['version', 'path', 'protected']) !== [] || ($saved['version'] ?? MotoContract::DYNAMIC_CROP_PROCESSOR) !== MotoContract::DYNAMIC_CROP_PROCESSOR) {
            return $invalid('không theo cấu trúc '.MotoContract::DYNAMIC_CROP_PROCESSOR);
        }

        $output = $execution['dynamic_crop'];
        $content = (int) $execution['content_duration_ms'];
        $points = [];

        foreach ($path as $index => $point) {
            $at = 'điểm '.($index + 1);

            if (! is_array($point) || array_keys($point) !== ['at_ms', 'x', 'y', 'width', 'height'] || array_filter($point, static fn (mixed $value): bool => ! is_int($value) || $value < 0) !== []) {
                return $invalid("{$at} phải có at_ms, x, y, width, height là số nguyên không âm");
            }

            if ($index === 0 ? $point['at_ms'] !== 0 : $point['at_ms'] <= $points[$index - 1]['at_ms']) {
                return $invalid("{$at} có mốc {$point['at_ms']} ms — đường crop bắt đầu ở 0 ms và mốc tăng dần");
            }

            if ($point['at_ms'] > $content) {
                return $invalid("{$at} có mốc {$point['at_ms']} ms sau {$content} ms nội dung");
            }

            if ($point['width'] * (int) $output['height'] !== $point['height'] * (int) $output['width']) {
                return $invalid("{$at} có vùng {$point['width']}×{$point['height']} không đúng tỷ lệ {$output['width']}×{$output['height']}");
            }

            if ($point['width'] < (int) $output['width'] || $point['height'] < (int) $output['height']) {
                return $invalid("{$at} có vùng {$point['width']}×{$point['height']} nhỏ hơn {$output['width']}×{$output['height']} — không phóng lớn ảnh");
            }

            if ($keyframe !== null && ($point['x'] + $point['width'] > (int) $keyframe['master']['width'] || $point['y'] + $point['height'] > (int) $keyframe['master']['height'])) {
                return $invalid("{$at} vượt biên ảnh master {$keyframe['master']['width']}×{$keyframe['master']['height']}");
            }

            $points[] = $point;
        }

        $crop = is_array($keyframe['crop'] ?? null) ? $keyframe['crop'] : null;

        if ($keyframe !== null && $crop !== null && array_intersect_key($points[0], $crop) != $crop) {
            return $invalid('điểm 1 không phải vùng crop đã duyệt cùng bộ keyframe ('.$crop['x'].', '.$crop['y'].', '.$crop['width'].'×'.$crop['height'].')');
        }

        $protected = $saved['protected'] ?? null;

        if ($protected !== null) {
            if (! is_array($protected) || array_keys($protected) !== ['x', 'y', 'width', 'height'] || array_filter($protected, static fn (mixed $value): bool => ! is_int($value) || $value < 0) !== [] || $protected['width'] < 1 || $protected['height'] < 1) {
                return $invalid('có vùng bảo vệ chủ thể không phải x, y, width, height là số nguyên không âm, rộng và cao ít nhất 1 px');
            }

            $named = '('.$protected['x'].', '.$protected['y'].', '.$protected['width'].'×'.$protected['height'].')';

            if ($keyframe !== null && ($protected['x'] + $protected['width'] > (int) $keyframe['master']['width'] || $protected['y'] + $protected['height'] > (int) $keyframe['master']['height'])) {
                return $invalid("có vùng bảo vệ chủ thể {$named} vượt biên ảnh master {$keyframe['master']['width']}×{$keyframe['master']['height']}");
            }

            foreach ($points as $index => $point) {
                if ($protected['x'] < $point['x'] || $protected['y'] < $point['y'] || $protected['x'] + $protected['width'] > $point['x'] + $point['width'] || $protected['y'] + $protected['height'] > $point['y'] + $point['height']) {
                    return $invalid('để vùng bảo vệ chủ thể '.$named.' ra ngoài vùng của điểm '.($index + 1).' — mọi khung phải giữ trọn vùng này');
                }
            }
        }

        return ['plan' => ['version' => MotoContract::DYNAMIC_CROP_PROCESSOR, 'path' => $points, 'protected' => $protected, 'hash' => CanonicalJson::hash($points)], 'problem' => null];
    }
}
