<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Script\CheckedWorkflow;
use App\Video\Script\StructureChecked;
use App\Video\Superyacht\Spec\ResolvedPhase;
use App\Video\Superyacht\Support\CanonicalJson;

final class MotoClipPrompt implements CheckedWorkflow, ReleaseBound, StructureChecked
{
    use ChecksJsonStructure;

    /** @var array<string, string> the source ids a shot with an action timeline adds: its steps, its camera path, the operating state it opens and ends in, and what the shot plan assumed for it, word for word */
    private const TIMELINE_SOURCES = [
        'S16' => 'shot.action_timeline',
        'S17' => 'shot.camera.path',
        'S18' => 'shot.operating_start',
        'S19' => 'shot.operating_end',
        'S20' => 'shot.assumptions',
    ];

    /** @var array<string, string> the source ids the seventh input adds: the composition of the shot, how far it takes each event, the scene it belongs to and the events it plays as the approved script wrote them */
    private const COVERAGE_SOURCES = [
        'S21' => 'shot.composition',
        'S22' => 'shot.event_progress',
        'S23' => 'scene',
        'S24' => 'events',
    ];

    /** @var list<string> the fields of a shot of MotoShots 2.3.0 the seventh input hands on beside the others */
    private const COVERAGE_FIELDS = ['composition', 'event_progress'];

    /** @var list<string> what the seventh input hands on of the scene the shot belongs to, as the shot run pinned it */
    private const SCENE_FIELDS = ['id', 'places', 'set_brief', 'blocking', 'continuity_start', 'continuity_end', 'transition_in'];

    /** @var list<string> what the seventh input hands on of an event the shot plays, word for word from the approved script */
    private const EVENT_FIELDS = ['id', 'action', 'result', 'character_ids', 'design_refs', 'operating_start', 'operating_end'];

    /** @var bool whether the project plans shots with an action timeline and a camera path */
    private bool $timeline = false;

    /** @var bool whether the release of the project plans its shots under MotoShotsCheck::COVERAGE, whose shots carry the fields of the seventh input */
    private bool $coverage = false;

    public function bind(\App\Models\VideoProject $project): void
    {
        $this->timeline = MotoPipelineRelease::cinematic($project);
        $this->coverage = self::inputFormatFor($project) === MotoContract::CLIP_PROMPT_INPUT_V7;
    }

    /** @return string the input format a video prompt of the project is written from: the release of the project decides it, so one prompt revision is read by every shot of the project */
    public static function inputFormatFor(\App\Models\VideoProject $project): string
    {
        return match (true) {
            ! MotoPipelineRelease::cinematic($project) => MotoContract::CLIP_PROMPT_INPUT,
            (MotoPipelineRelease::policy($project, 'shots')['shot_editing_policy'] ?? null) === MotoShotsCheck::COVERAGE => MotoContract::CLIP_PROMPT_INPUT_V7,
            default => MotoContract::CLIP_PROMPT_INPUT_V5,
        };
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
        return match (true) {
            $this->coverage => MotoContract::CLIP_PROMPT_INPUT_V7,
            $this->timeline => MotoContract::CLIP_PROMPT_INPUT_V5,
            default => MotoContract::CLIP_PROMPT_INPUT,
        };
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
    public static function inputs(array $shot, array $pinned, array $design, array $script, array $opening, array $sound, string $fingerprint, int $maxPromptChars, ?string $format = null): array
    {
        $seventh = $format === MotoContract::CLIP_PROMPT_INPUT_V7;
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
        $shotFields += $seventh ? array_map(static fn (string $field): mixed => $shot[$field] ?? null, array_combine(self::COVERAGE_FIELDS, self::COVERAGE_FIELDS)) : [];
        $siblings = array_values(array_filter((array) ($pinned['shot_order'] ?? []), static fn (mixed $row): bool => is_array($row) && ($row['scene'] ?? null) === ($shot['scene'] ?? null)));
        $scene = collect((array) ($pinned['scenes'] ?? []))->firstWhere('id', $shot['scene'] ?? null);
        $whole = ($shot['reveal'] ?? null) === 'whole';
        $themed = $timeline ? [
            'markings' => array_values(array_filter((array) ($design['motorcycle']['markings'] ?? []), static fn (array $marking): bool => $whole || in_array($marking['part'] ?? null, $named, true))),
            'identity_rules' => array_values(array_filter((array) ($design['identity_rules'] ?? []), static fn (array $rule): bool => $whole || in_array('MOTORCYCLE', (array) ($rule['scope'] ?? []), true) || array_intersect([...(array) ($rule['scope'] ?? []), ...(array) ($rule['refs'] ?? [])], $named) !== [])),
            'features' => array_values(array_map(static fn (array $feature): array => array_intersect_key($feature, array_flip(['id', 'name', 'parts', 'visible_expression', 'effect', 'modes', 'use_sequence'])), array_filter((array) ($design['features'] ?? []), static fn (array $feature): bool => $whole || array_intersect((array) ($feature['parts'] ?? []), $named) !== []))),
        ] : [];
        $sourceMap = self::SOURCES + ($timeline ? self::TIMELINE_SOURCES : []) + ($seventh ? self::COVERAGE_SOURCES : []);
        $shotFields['parts'] = array_map(static fn (array $item): array => $item + ['name' => $designParts[$item['part']]['name'] ?? null], (array) ($shot['parts'] ?? []));
        $shotFields['configurations'] = ['start' => $records((array) ($shot['configurations']['start'] ?? [])), 'end' => $records((array) ($shot['configurations']['end'] ?? []))];

        $payload = [
            'input_format' => $format ?? ($timeline ? MotoContract::CLIP_PROMPT_INPUT_V5 : MotoContract::CLIP_PROMPT_INPUT),
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
        ] + ($seventh ? ['scene' => is_array($scene) ? array_intersect_key($scene, array_flip(self::SCENE_FIELDS)) + [
            'shot_position' => ($at = array_search($shot['id'], array_column($siblings, 'id'), true)) === false ? null : $at + 1,
            'shot_count' => $siblings === [] ? null : count($siblings),
        ] : null, 'events' => self::events($shot, $beats)] : []);
        $payload['sources'] =array_map(static fn (string $path): array => ['path' => $path, 'value' => data_get($payload, $path)], $sourceMap);
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
            ...($seventh ? [
                ...array_map('strval', array_merge([], ...array_map(static fn (array $event): array => array_filter((array) ($event['design_refs'] ?? []), 'is_string'), (array) $payload['events']))),
                ...array_map('strval', (array) ($payload['scene']['places'] ?? [])),
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

    /**
     * @param  array<string, mixed>  $shot  the stored shot
     * @param  list<array<string, mixed>>  $beats  the beats of the approved script the shot covers
     * @return list<array<string, mixed>> every event the shot plays, in the order it covers them: its beat, the record of the event as the approved script wrote it and how far this shot takes it; an event the script does not have is left out, never written by code
     */
    private static function events(array $shot, array $beats): array
    {
        $beats = array_column($beats, null, 'id');
        $phases = array_column((array) ($shot['event_progress'] ?? []), 'phase', 'event_id');
        $events = [];

        foreach ((array) ($shot['coverage'] ?? []) as $item) {
            $own = array_column((array) ($beats[$item['beat']]['events'] ?? []), null, 'id');

            foreach (array_map('strval', (array) ($item['event_ids'] ?? [])) as $id) {
                if (is_array($own[$id] ?? null)) {
                    $events[] = ['beat' => (string) $item['beat']] + array_intersect_key($own[$id], array_flip(self::EVENT_FIELDS)) + ['progress' => $phases[$id] ?? null];
                }
            }
        }

        return $events;
    }

    /** The answer is held to its own schema alone among those the run pinned. */
    protected function answerSchemas(ResolvedPhase $bundle): array
    {
        return self::own($bundle->outputSchemas());
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
                $issues[] = Finding::blocking('MotoClipPrompt.check.1', "{$key} của phản hồi không khớp yêu cầu.");
            }
        }

        $cited = (array) $response['source_refs'];

        foreach ([...(array) $response['gaps'], ...(array) $response['conflicts']] as $item) {
            $cited = [...$cited, ...(array) ($item['source_refs'] ?? [])];
        }

        $unknown = array_values(array_unique(array_diff($cited, (array) $context['source_ids'])));

        if ($unknown !== []) {
            $issues[] = Finding::requirement('MotoClipPrompt.check.2', 'Mã nguồn không có trong đầu vào: '.implode(', ', $unknown).'.');
        }

        $prompt = (string) $response['video_prompt'];

        if ($prompt !== '') {
            $issues = is_array($context['sound_policy'] ?? null)
                // How the sound is written is a judgement of the content: shown, never a failure.
                ? [...$issues, ...Finding::each(MotoCheckRules::NOTE, 'MotoClipPrompt.soundProblems', self::soundProblems($prompt, $context['sound_policy']))]
                : [...$issues, Finding::note('MotoClipPrompt.soundPolicy.legacy', 'Lượt này viết theo chính sách âm thanh cũ, trước dòng "Sound design:" — viết lại prompt video.')];
            $leaked = array_values(array_filter((array) $context['internal_ids'], static fn (string $id): bool => preg_match('/(?<![A-Za-z0-9_])'.preg_quote($id, '/').'(?![A-Za-z0-9_])/', $prompt) === 1));

            if ($leaked !== []) {
                $issues[] = Finding::note('MotoClipPrompt.check.3', 'Prompt có mã nội bộ '.implode(', ', $leaked).' — model video không biết mã này; viết lại bằng tên mô tả.');
            }

            if (mb_strlen($prompt) > (int) $context['max_prompt_chars']) {
                $issues[] = Finding::requirement('MotoClipPrompt.check.4', 'Prompt dài '.mb_strlen($prompt).' ký tự, vượt giới hạn '.$context['max_prompt_chars'].'.');
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
        return MotoCheckRules::usable($check) && in_array($check['status'] ?? null, ['ok', 'needs_review'], true);
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
            ...Finding::fields($issues, $warnings),
            'judge' => 'not_run',
        ];
    }
}
