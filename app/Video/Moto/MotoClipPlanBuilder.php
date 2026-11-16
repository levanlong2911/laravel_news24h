<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Media\VideoModelRegistry;
use App\Video\Superyacht\Support\CanonicalJson;

/**
 * Compiles the clip plan from what the plan service read: pure, with no database and no provider.
 * A shot the first version cannot send stays in the plan with why, and its plan is never changed to make it fit.
 */
final class MotoClipPlanBuilder
{
    public const VERSION = 'moto-clip-plan-builder-v5';

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
     * @return array<string, mixed> the plan, in the order of the shots
     */
    public static function plan(string $projectId, array $contract, array $prompts, array $source, array $keyframes, array $models, array $media, array $inputs = []): array
    {
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
}
