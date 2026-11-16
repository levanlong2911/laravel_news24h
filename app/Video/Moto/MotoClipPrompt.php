<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Script\CheckedWorkflow;
use App\Video\Superyacht\Spec\ResolvedPhase;
use App\Video\Superyacht\Support\CanonicalJson;

final class MotoClipPrompt implements CheckedWorkflow, ReleaseBound
{
    /** @var array<string, string> the source ids a shot with an action timeline adds: its steps, its camera path, the operating state it opens and ends in, and what the shot plan assumed for it, word for word */
    private const TIMELINE_SOURCES = [
        'S16' => 'shot.action_timeline',
        'S17' => 'shot.camera.path',
        'S18' => 'shot.operating_start',
        'S19' => 'shot.operating_end',
        'S20' => 'shot.assumptions',
    ];

    /** @var bool whether the project plans shots with an action timeline and a camera path */
    private bool $timeline = false;

    public function bind(\App\Models\VideoProject $project): void
    {
        $this->timeline = MotoPipelineRelease::cinematic($project);
    }

    public const CONTRACT = 'moto/MotoClipPrompt@1.0.0';

    public const CHECK_VERSION = 'moto-clip-prompt-check-v2';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    /** @var list<string> the fields of a stored shot the model reads as the shot itself */
    private const SHOT_FIELDS = ['purpose', 'state', 'reveal', 'camera', 'action', 'result', 'end_frame', 'people', 'work_items'];

    /** @var list<string> the fields of the motorcycle as a whole the model reads */
    private const MOTORCYCLE_FIELDS = ['name', 'silhouette', 'palette', 'materials'];

    /** @var array<string, string> the source id of every group of the payload and the path of the field it names */
    private const SOURCES = [
        'S01' => 'opening.first_frame',
        'S02' => 'opening.keyframe',
        'S03' => 'shot.camera',
        'S04' => 'shot.action',
        'S05' => 'shot.result',
        'S06' => 'shot.end_frame',
        'S07' => 'timing',
        'S08' => 'operating',
        'S09' => 'continuity',
        'S10' => 'characters',
        'S11' => 'shot.work_items',
        'S12' => 'shot.configurations',
        'S13' => 'design_context',
        'S14' => 'sound_policy',
        'S15' => 'opening.images',
    ];

    /** @var list<string> what the model reads of a reference image beside its label: what it is for and what of it the shot uses. For a person: either the cell of the look the shot uses (cell_code, cell_row, cell_column, items), or reference_mode identity_master with the cells the whole master holds (master_cells) and items as the source states them, null when nothing says; protection_source says whether the source stated them */
    private const IMAGE_FIELDS = ['kind', 'role', 'usage', 'target', 'parts', 'title', 'character', 'view', 'cell_code', 'cell_row', 'cell_column', 'reference_mode', 'items', 'protection_source', 'master_cells'];

    /**
     * @param  array<string, mixed>  $audio  the sound policy of the clip flow as config gives it
     * @return ?array{policy: string, prefix: string, closing: list<string>} the policy every video prompt follows: one line that starts with the prefix, names the sounds of the shot and ends with the closing sentences; null when config does not give one in that shape
     */
    public static function soundPolicy(array $audio): ?array
    {
        $policy = $audio['policy'] ?? null;
        $prefix = $audio['prefix'] ?? null;
        $closing = $audio['closing'] ?? null;

        if (! is_string($policy) || preg_match('/^[a-z][a-z0-9_]*$/', $policy) !== 1 || ! is_string($prefix) || preg_match('/^\S.*:$/', $prefix) !== 1
            || ! is_array($closing) || $closing === [] || ! array_is_list($closing) || array_filter($closing, static fn (mixed $sentence): bool => ! is_string($sentence) || trim($sentence) === '' || trim($sentence) !== $sentence) !== []) {
            return null;
        }

        return ['policy' => $policy, 'prefix' => $prefix, 'closing' => $closing];
    }

    public function format(): string
    {
        return MotoContract::CLIP_PROMPT_FORMAT;
    }

    public function inputsVersion(): string
    {
        return $this->timeline ? MotoContract::CLIP_PROMPT_INPUT_V5 : MotoContract::CLIP_PROMPT_INPUT;
    }

    public function checkVersion(): string
    {
        return self::CHECK_VERSION;
    }

    /** @return array{execution: string, model: string, effort: string, max_tokens: int, timeout_seconds: int, schema_heading: string, image_max_edge: int, image_max_bytes: int, image_jpeg_quality: int, estimate_output_tokens: int, max_prompt_chars: int} */
    public function settings(): array
    {
        $config = (array) config('video.moto.clip_prompt');

        return [
            'execution' => (string) $config['execution'],
            'model' => (string) $config['model'],
            'effort' => (string) $config['effort'],
            'max_tokens' => (int) $config['max_tokens'],
            'timeout_seconds' => (int) $config['timeout_seconds'],
            'schema_heading' => (string) $config['schema_heading'],
            'image_max_edge' => (int) $config['image_max_edge'],
            'image_max_bytes' => (int) $config['image_max_bytes'],
            'image_jpeg_quality' => (int) $config['image_jpeg_quality'],
            'estimate_output_tokens' => (int) $config['estimate_output_tokens'],
            'max_prompt_chars' => (int) $config['max_prompt_chars'],
        ];
    }

    /**
     * @param  list<string>  $parts
     * @param  list<array<string, mixed>>  $schemas
     * @return string the phase text and the schema of the answer only; the clip plan the same phase pins is no part of what the model reads
     */
    public function systemText(array $parts, array $schemas): string
    {
        $heading = (string) config('video.moto.clip_prompt.schema_heading');

        return implode("\n\n", [
            ...$parts,
            ...array_map(static fn (array $schema): string => "## {$heading} — {$schema['label']}\n\n```json\n".MotoContract::compactSchema((string) $schema['content'])."\n```", self::own($schemas)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $shot  the stored shot of the approved plan
     * @param  array<string, mixed>  $pinned  the check context pinned with the shot run
     * @return array{completed_before_shot?: array{event_ids: list<string>, work: list<array{id: string, part: string, operation: string, parts: array<string, array{location: string, status: string}>}>}} for a shot that follows a gap of an edited plan, the events and the work the plan left outside the picture before its first frame, each work item with where it left its parts: the state the take opens on, kept apart from the action of the take; nothing for any other shot
     */
    private static function completedBefore(array $shot, array $pinned): array
    {
        $gaps = array_values((array) ($shot['omitted_before'] ?? []));

        if ($gaps === []) {
            return [];
        }

        $items = (array) ($pinned['work']['items'] ?? []);

        return ['completed_before_shot' => [
            'event_ids' => array_values(array_map('strval', array_merge([], ...array_column($gaps, 'event_ids')))),
            'work' => array_values(array_map(static fn (string $key): array => [
                'id' => $key,
                'part' => (string) ($items[$key]['part'] ?? ''),
                'operation' => (string) ($items[$key]['operation'] ?? ''),
                'parts' => array_map(static fn (array $place): array => ['location' => (string) $place['end'], 'status' => (string) $place['end_status']], (array) ($items[$key]['parts'] ?? [])),
            ], array_map('strval', array_merge([], ...array_column($gaps, 'work_ids'))))),
        ]];
    }

    /**
     * @param  array<string, mixed>  $shot  the stored shot of the approved plan
     * @param  array<string, mixed>  $pinned  the check context pinned with the shot run: its configurations and limits
     * @param  array<string, mixed>  $design  the data of the design the script was written from
     * @param  array<string, mixed>  $script  the data of the approved script
     * @param  array{mode: string, keyframe: ?array<string, mixed>, images: list<array<string, mixed>>}  $opening  how the clip is made: from the approved keyframe of the shot as the clip flow pins it, which is Image 1, or from the reference images it sends, Image 1 onwards in their order
     * @param  array{policy: string, prefix: string, closing: list<string>}  $sound  soundPolicy() of the clip flow
     * @return array{payload: array<string, mixed>, context: array<string, mixed>} what the model reads to write the video prompt of the shot, from the records of the shot alone with a source id for every group, and what the check reads back
     */
    public static function inputs(array $shot, array $pinned, array $design, array $script, array $opening, array $sound, string $fingerprint, int $maxPromptChars): array
    {
        $keyframe = $opening['keyframe'] ?? null;
        $configurations = array_column((array) ($pinned['configurations'] ?? []), null, 'id');
        $partIds = MotoKeyframePrompt::partIds($shot, $configurations);
        $designParts = array_column((array) ($design['parts'] ?? []), null, 'id');
        $named = array_values(array_unique([...$partIds, ...array_values(array_filter(array_map(static fn (string $id): ?string => $designParts[$id]['parent'] ?? null, $partIds), 'is_string'))]));
        $beatIds = array_values(array_unique(array_map(static fn (array $item): string => (string) $item['beat'], (array) ($shot['coverage'] ?? []))));
        $beats = array_values(array_filter((array) ($script['beats'] ?? []), static fn (array $beat): bool => in_array($beat['id'] ?? null, $beatIds, true)));
        $timeline = isset($shot['action_timeline']);
        $characters = $timeline ? array_column((array) ($script['characters'] ?? []), 'description', 'id') : (array) ($script['characters'] ?? []);
        $limits = array_values(array_filter((array) ($pinned['limits'] ?? []), static fn (array $limit): bool => in_array($limit['id'] ?? null, (array) ($shot['limits'] ?? []), true)));
        $records = static fn (array $ids): array => array_values(array_filter(array_map(static fn (string $id): ?array => $configurations[$id] ?? null, array_map('strval', $ids))));
        $shotFields = array_intersect_key($shot, array_flip([...self::SHOT_FIELDS, ...($timeline ? ['action_timeline', 'operating_start', 'operating_end', 'cards', 'assumptions'] : [])]));
        $whole = ($shot['reveal'] ?? null) === 'whole';
        $themed = $timeline ? [
            'markings' => array_values(array_filter((array) ($design['motorcycle']['markings'] ?? []), static fn (array $marking): bool => $whole || in_array($marking['part'] ?? null, $named, true))),
            'identity_rules' => array_values(array_filter((array) ($design['identity_rules'] ?? []), static fn (array $rule): bool => $whole || in_array('MOTORCYCLE', (array) ($rule['scope'] ?? []), true) || array_intersect([...(array) ($rule['scope'] ?? []), ...(array) ($rule['refs'] ?? [])], $named) !== [])),
            'features' => array_values(array_map(static fn (array $feature): array => array_intersect_key($feature, array_flip(['id', 'name', 'parts', 'visible_expression', 'effect', 'modes', 'use_sequence'])), array_filter((array) ($design['features'] ?? []), static fn (array $feature): bool => $whole || array_intersect((array) ($feature['parts'] ?? []), $named) !== []))),
        ] : [];
        $sourceMap = $timeline ? self::SOURCES + self::TIMELINE_SOURCES : self::SOURCES;
        $shotFields['parts'] = array_map(static fn (array $item): array => $item + ['name' => $designParts[$item['part']]['name'] ?? null], (array) ($shot['parts'] ?? []));
        $shotFields['configurations'] = ['start' => $records((array) ($shot['configurations']['start'] ?? [])), 'end' => $records((array) ($shot['configurations']['end'] ?? []))];

        $payload = [
            'input_format' => $timeline ? MotoContract::CLIP_PROMPT_INPUT_V5 : MotoContract::CLIP_PROMPT_INPUT,
            'shot_id' => (string) $shot['id'],
            'source_fingerprint' => $fingerprint,
            'input_mode' => (string) $opening['mode'],
            'opening' => [
                'first_frame' => $shot['first_frame'] ?? null,
                'keyframe' => $keyframe === null ? null : ['image' => 'Image 1', 'artifact_id' => (string) $keyframe['artifact_id'], 'sha256' => (string) $keyframe['sha256']],
                'images' => array_values(array_map(static fn (array $image): array => ['image' => 'Image '.((int) $image['position'] + 1), 'name' => (string) ($image['image_code'] ?? '')]
                    + array_intersect_key($image, array_flip(self::IMAGE_FIELDS)), (array) ($opening['images'] ?? []))),
            ],
            'shot' => $shotFields,
            'timing' => [
                'render_duration_ms' => (int) $shot['render_duration_ms'],
                'used_start_ms' => (int) $shot['used_start_ms'],
                'used_duration_ms' => (int) $shot['used_duration_ms'],
                'coverage' => array_values(array_map(static fn (array $item): array => array_intersect_key($item, array_flip($timeline ? ['beat', 'event_ids'] : ['beat', 'used_duration_ms', 'result_at_ms'])), (array) ($shot['coverage'] ?? []))),
            ],
            'design_context' => $themed + [
                'motorcycle' => array_intersect_key((array) ($design['motorcycle'] ?? []), array_flip(self::MOTORCYCLE_FIELDS)),
                'build_state' => collect((array) ($design['build_states'] ?? []))->firstWhere('id', $shot['state'] ?? null),
                'parts' => array_values(array_map(static fn (array $part): array => array_diff_key($part, ['points' => true]), array_filter($designParts, static fn (array $part): bool => in_array($part['id'], $named, true)))),
                'connections' => array_values(array_filter((array) ($design['connections'] ?? []), static fn (array $connection): bool => array_intersect((array) ($connection['between'] ?? []), $partIds) !== [])),
                'limits' => $limits,
            ],
            'operating' => array_values(array_map(static fn (array $beat): array => ['beat' => (string) $beat['id'], 'situation' => $beat['situation'] ?? null], $beats)),
            'continuity' => [
                'carried_from' => $shot['carried_from'] ?? null,
                'changes' => array_values((array) ($shot['continuity']['changes'] ?? [])),
                'carries' => array_values((array) ($shot['continuity']['carries'] ?? [])),
                'link' => $shot['links'] ?? null,
            ] + self::completedBefore($shot, $pinned),
            'characters' => array_map(static fn (array $person): array => [
                'character' => (string) $person['character'],
                'view' => $person['view'] ?? null,
            ] + ($timeline ? array_intersect_key($person, array_flip(['framing', 'face_visibility'])) : []) + [
                'description' => $characters[$person['character']] ?? null,
            ], (array) ($shot['people'] ?? [])),
            'sound_policy' => $sound,
        ];
        $payload['sources'] = array_map(static fn (string $path): array => ['path' => $path, 'value' => data_get($payload, $path)], $sourceMap);
        $internal = [
            (string) $shot['id'],
            (string) ($shot['scene'] ?? ''),
            (string) ($shot['state'] ?? ''),
            ...$beatIds,
            ...$named,
            ...array_map('strval', [...(array) ($shot['configurations']['start'] ?? []), ...(array) ($shot['configurations']['end'] ?? [])]),
            ...array_map('strval', array_column((array) ($shot['work_items'] ?? []), 'id')),
            ...array_map('strval', (array) ($payload['continuity']['completed_before_shot']['event_ids'] ?? [])),
            ...array_map('strval', array_column((array) ($payload['continuity']['completed_before_shot']['work'] ?? []), 'id')),
            ...array_map('strval', array_column($limits, 'id')),
            ...array_map('strval', array_filter(array_column((array) ($opening['images'] ?? []), 'cell_code'), 'is_string')),
            ...array_keys($sourceMap),
            ...($timeline ? [
                ...array_merge([], ...array_map(static fn (array $item): array => array_map('strval', (array) ($item['event_ids'] ?? [])), (array) ($shot['coverage'] ?? []))),
                ...array_map('strval', array_column((array) $shot['action_timeline'], 'id')),
                ...array_map('strval', array_column((array) ($shot['camera']['path'] ?? []), 'id')),
                ...array_map('strval', array_column((array) ($shot['people'] ?? []), 'character')),
                ...array_map('strval', array_column((array) ($shot['placed_cards'] ?? []), 'card')),
                ...array_map('strval', array_column((array) ($design['participant_roles'] ?? []), 'id')),
            ] : []),
        ];
        $internal = array_values(array_unique(array_filter($internal, static fn (string $id): bool => $id !== '')));

        if ($timeline) {
            $payload['forbidden_prompt_ids'] = $internal;
        }

        return [
            'payload' => $payload,
            'context' => [
                'format' => MotoContract::CLIP_PROMPT_FORMAT,
                'shot_id' => (string) $shot['id'],
                'source_fingerprint' => $fingerprint,
                'source_ids' => array_keys($sourceMap),
                'internal_ids' => $internal,
                'sound_policy' => $sound,
                'max_prompt_chars' => $maxPromptChars,
            ],
        ];
    }

    public function schemaViolations(object $decoded, ResolvedPhase $bundle, string $projectId): array
    {
        return array_map(
            static fn (string $error): array => ['code' => 'SCHEMA', 'path' => '', 'message' => $error],
            PhaseOneEvidence::schemaErrors($decoded, self::own($bundle->outputSchemas())),
        );
    }

    public function check(?array $response, array $context, array $schema): array
    {
        if ($response === null) {
            return self::result(self::UNSCORED, null, 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== MotoContract::CLIP_PROMPT_FORMAT) {
            return self::result(self::UNSCORED, null, 'Phản hồi không theo định dạng '.MotoContract::CLIP_PROMPT_FORMAT.'.');
        }

        $status = is_string($response['status'] ?? null) ? $response['status'] : null;

        if ($schema !== []) {
            return self::result(self::FAIL, $status, null, array_map(static fn (array $violation): string => trim($violation['path'].' '.$violation['message']), $schema));
        }

        if ($status === 'fail') {
            return self::result(self::FAIL, $status, 'Model trả status fail: '.implode('; ', (array) ($response['flags'] ?? [])));
        }

        $issues = [];

        foreach (['project_id', 'prompt_version', 'shot_id', 'source_fingerprint'] as $key) {
            if (($response[$key] ?? null) !== ($context[$key] ?? null)) {
                $issues[] = "{$key} của phản hồi không khớp yêu cầu.";
            }
        }

        $cited = (array) $response['source_refs'];

        foreach ([...(array) $response['gaps'], ...(array) $response['conflicts']] as $item) {
            $cited = [...$cited, ...(array) ($item['source_refs'] ?? [])];
        }

        $unknown = array_values(array_unique(array_diff($cited, (array) $context['source_ids'])));

        if ($unknown !== []) {
            $issues[] = 'Mã nguồn không có trong đầu vào: '.implode(', ', $unknown).'.';
        }

        $prompt = (string) $response['video_prompt'];

        if ($prompt !== '') {
            $issues = is_array($context['sound_policy'] ?? null)
                ? [...$issues, ...self::soundProblems($prompt, $context['sound_policy'])]
                : [...$issues, 'Lượt này viết theo chính sách âm thanh cũ, trước dòng "Sound design:" — viết lại prompt video.'];
            $leaked = array_values(array_filter((array) $context['internal_ids'], static fn (string $id): bool => preg_match('/(?<![A-Za-z0-9_])'.preg_quote($id, '/').'(?![A-Za-z0-9_])/', $prompt) === 1));

            if ($leaked !== []) {
                $issues[] = 'Prompt có mã nội bộ '.implode(', ', $leaked).' — model video không biết mã này; viết lại bằng tên mô tả.';
            }

            if (mb_strlen($prompt) > (int) $context['max_prompt_chars']) {
                $issues[] = 'Prompt dài '.mb_strlen($prompt).' ký tự, vượt giới hạn '.$context['max_prompt_chars'].'.';
            }
        }

        $warnings = [
            ...array_map(static fn (array $gap): string => 'Thiếu dữ kiện: '.$gap['missing'].' — '.$gap['reason'], (array) $response['gaps']),
            ...array_map(static fn (array $conflict): string => 'Mâu thuẫn ('.implode(' / ', (array) $conflict['source_refs']).'): '.$conflict['reason'], (array) $response['conflicts']),
        ];

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, null, [], $issues, $warnings);
    }

    /** @return array{video_prompt: string, prompt_sha256: string} the prompt as the model wrote it, word for word, with its hash */
    public function derive(array $document, array $context): array
    {
        $prompt = (string) ($document['video_prompt'] ?? '');

        return ['video_prompt' => $prompt, 'prompt_sha256' => CanonicalJson::hashString($prompt)];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  string  $contract  ScriptEvaluation::contract() of the pinned bundle
     */
    public function contextProblem(array $context, string $contract): ?string
    {
        if (! str_starts_with($contract, self::CONTRACT.'#')) {
            return "hợp đồng đầu ra là {$contract}; code kiểm Phase 10 cho ".self::CONTRACT.'.';
        }

        return ($context['shot_id'] ?? '') === '' ? 'không có shot để viết prompt.' : null;
    }

    /**
     * @param  array<string, mixed>  $sound  the sound policy the run was written to
     * @return list<string> what breaks the shape of the sound line: one line of its own that starts with the prefix, describes something and ends with the closing sentences; the sounds it names are never judged
     */
    private static function soundProblems(string $prompt, array $sound): array
    {
        $prefix = (string) ($sound['prefix'] ?? '');
        $closing = implode(' ', array_map('strval', (array) ($sound['closing'] ?? [])));
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $prompt) ?: []), static fn (string $line): bool => $prefix !== '' && str_starts_with($line, $prefix)));

        if (count($lines) !== 1) {
            return ['Dòng "'.$prefix.' …" phải đứng riêng một dòng, đúng một lần — prompt có '.count($lines).' dòng.'];
        }

        if ($closing === '' || ! str_ends_with($lines[0], $closing)) {
            return ['Dòng "'.$prefix.' …" phải kết thúc bằng "'.$closing.'".'];
        }

        return trim(substr($lines[0], strlen($prefix), strlen($lines[0]) - strlen($prefix) - strlen($closing))) === ''
            ? ['Dòng "'.$prefix.' …" chưa tả âm thanh nào trước "'.$closing.'".']
            : [];
    }

    /**
     * @param  array<string, mixed>  $check  check()
     * @return bool whether a checked answer can be approved and sent: it passed every check, and the model either gave it freely or held it back with notes for the person who approves — a gap, a conflict — which that person reads and accepts when approving. An answer the model marked as failed is never usable
     */
    public static function usable(array $check): bool
    {
        return ($check['verdict'] ?? null) === self::PASS && in_array($check['status'] ?? null, ['ok', 'needs_review'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $schemas
     * @return list<array<string, mixed>> the schema of the answer among the output schemas the phase pins
     */
    private static function own(array $schemas): array
    {
        return array_values(array_filter($schemas, static fn (array $schema): bool => str_starts_with((string) $schema['label'], 'moto/MotoClipPrompt@')));
    }

    /**
     * @param  list<string>  $schema
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    private static function result(string $verdict, ?string $status, ?string $error, array $schema = [], array $issues = [], array $warnings = []): array
    {
        return [
            'version' => self::CHECK_VERSION,
            'format' => MotoContract::CLIP_PROMPT_FORMAT,
            'verdict' => $verdict,
            'status' => $status,
            'error' => $error,
            'schema' => $schema,
            'issues' => array_values(array_unique($issues)),
            'warnings' => array_values(array_unique($warnings)),
            'judge' => 'not_run',
        ];
    }
}
