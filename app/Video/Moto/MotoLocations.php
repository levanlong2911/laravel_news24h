<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\ScriptSourceService;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Llm\JsonAnswer;
use App\Video\Locations\LocationWorkflow;
use App\Video\Superyacht\Spec\ResolvedPhase;
use RuntimeException;

final class MotoLocations implements LocationWorkflow
{
    /** @var list<string> */
    private const BEAT_KEYS = ['id', 'state', 'duration_s', 'location', 'time_light', 'visual', 'purpose', 'reveal', 'link'];

    private const FIRST_BUILT_STATE = 6;

    private const LAST_STATE = 12;

    public function __construct(private readonly ScriptSourceService $scripts) {}

    public function format(): string
    {
        return MotoContract::LOCATIONS_FORMAT;
    }

    public function checkVersion(): string
    {
        return MotoLocationsCheck::VERSION;
    }

    public function plannerVersion(): string
    {
        return MotoLocationPlanner::VERSION;
    }

    public function inputsVersion(): string
    {
        return MotoContract::LOCATIONS_INPUT;
    }

    public function settings(): array
    {
        $config = (array) config('video.moto.locations');

        return [
            'execution' => (string) $config['execution'],
            'model' => (string) $config['model'],
            'effort' => (string) $config['effort'],
            'efforts' => array_values(array_map('strval', (array) $config['efforts'])),
            'max_tokens' => (int) $config['max_tokens'],
            'timeout_seconds' => (int) $config['timeout_seconds'],
            'schema_heading' => (string) $config['schema_heading'],
            'semantic_review' => (string) $config['semantic_review'],
            'reference' => (array) $config['reference'],
        ];
    }

    public function inputs(VideoProject $project, string $prompt): array
    {
        $projectId = (string) $project->id;
        $script = $this->scripts->approvedSource($projectId);

        if (($script['document']['format'] ?? null) !== MotoContract::SCRIPT_FORMAT) {
            throw new RuntimeException('Kịch bản đang chọn không theo định dạng '.MotoContract::SCRIPT_FORMAT.' — không lập địa điểm moto từ nó.');
        }

        $stage = VideoPlanningStage::query()->whereKey($script['stage_id'])->where('project_id', $projectId)->firstOrFail();
        $meta = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $pinned = json_decode((string) ($meta['payload'] ?? ''), true);
        $source = (array) ($meta['source'] ?? []);
        $scriptContext = (array) ($meta['context'] ?? []);

        if (! is_array($pinned) || ! is_array($pinned['variables'] ?? null)) {
            throw new RuntimeException('Kịch bản đã duyệt không còn payload đã ghim — không lập được địa điểm và bối cảnh.');
        }

        $design = self::pinnedDesign($projectId, $source);
        $names = array_values(array_map('strval', (array) ($scriptContext['source_names'] ?? [])));
        $built = self::build((array) $script['document']['data'], $design, (array) $pinned['variables'], $names);

        return [
            'payload' => $built['payload'],
            'source' => [
                'script' => $script['token'],
                'design' => ['design_stage_id' => (string) ($source['design_stage_id'] ?? ''), 'design_raw_sha256' => (string) ($source['design_raw_sha256'] ?? '')],
            ],
            'context' => $built['context'] + [
                'format' => $this->format(),
                'source_names' => $names,
                'base_names' => array_values(array_filter([$design['motorcycle']['base']['family'] ?? null, $design['motorcycle']['base']['model'] ?? null], 'is_string')),
                'motorcycle_name' => is_string($design['motorcycle']['name'] ?? null) ? $design['motorcycle']['name'] : null,
            ],
        ];
    }

    public function completeContext(string $projectId, array $context, array $source): array
    {
        return $context;
    }

    public function sourceProblem(string $projectId, array $source): ?string
    {
        try {
            $current = $this->scripts->approvedSource($projectId);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return $current['token'] == ($source['script'] ?? null) ? null : 'Kịch bản được chọn hoặc nguồn của nó đã đổi so với lúc lập bộ địa điểm và bối cảnh này.';
    }

    public function schemaViolations(object $decoded, ResolvedPhase $bundle, string $projectId): array
    {
        return array_map(
            static fn (string $error): array => ['code' => 'SCHEMA', 'path' => '', 'message' => $error],
            PhaseOneEvidence::schemaErrors($decoded, $bundle->outputSchemas()),
        );
    }

    public function check(?array $response, array $context, array $schema): array
    {
        return MotoLocationsCheck::evaluate($response, $context, $schema);
    }

    public function derive(array $document, array $context): array
    {
        return MotoLocationPlanner::plan($document, $context);
    }

    public function systemText(array $parts, array $schemas): string
    {
        $heading = (string) config('video.moto.locations.schema_heading');

        return implode("\n\n", [
            ...$parts,
            ...array_map(static fn (array $schema): string => "## {$heading} — {$schema['label']}\n\n```json\n".MotoContract::compactSchema((string) $schema['content'])."\n```", $schemas),
        ]);
    }

    /**
     * @param  array<string, mixed>  $script  the data of the approved script
     * @param  array<string, mixed>  $design  the data of the design the script pinned
     * @param  array<string, mixed>  $variables  the variables the script was written with
     * @param  list<string>  $names  the source names the script pinned
     * @return array{payload: array<string, mixed>, context: array<string, mixed>} what a place needs from the script and the design: the beats with the subjects they show and their sizes, the operations they perform and the environment the design sets for them; the setting of each design stage; the surface, work in progress, temporary equipment and exceptions of each build state; no part record, coordinate, limit or model-sheet prompt
     */
    public static function build(array $script, array $design, array $variables, array $names): array
    {
        $parts = array_column((array) ($design['parts'] ?? []), null, 'id');
        $states = array_column((array) ($design['build_states'] ?? []), null, 'id');
        $present = self::present($parts, $states);
        $finished = self::box(array_intersect_key($parts, $present[sprintf('BS%02d', self::LAST_STATE)] ?? []), 'part_boxes');
        $beats = [];

        foreach ((array) ($script['beats'] ?? []) as $beat) {
            $situation = is_array($beat['situation'] ?? null)
                ? ['mode' => $beat['situation']['mode'], 'dry_road' => $beat['situation']['dry_road'], 'procedures' => array_values((array) ($beat['situation']['procedures'] ?? []))]
                : null;
            $beats[] = array_combine(self::BEAT_KEYS, array_map(static fn (string $key): mixed => $beat[$key] ?? null, self::BEAT_KEYS)) + [
                'situation' => $situation,
                'subjects' => self::subjects($beat, $parts, $states, $present, $finished, $design['motorcycle']['name'] ?? null),
                'operations' => self::operations($beat, $design),
                'conditions' => self::conditions($beat, $design),
                'limits' => self::limits($beat, $design),
            ];
        }

        $facts = [];
        $shown = [];

        foreach ($states as $id => $state) {
            if (isset($state['setting_brief'])) {
                $keys = ['id', 'kind', 'setting_brief', 'participant_roles', 'model_medium', 'scale_denominator'];
                $shown[] = array_filter(array_combine($keys, array_map(static fn (string $key): mixed => $state[$key] ?? null, $keys)), static fn (mixed $value): bool => $value !== null);
                $facts[$id] = ['kind' => $state['kind'], 'temporary' => [], 'setting_brief' => (string) $state['setting_brief']];

                continue;
            }

            $named = static fn (array $item, array $keys): array => ['part' => $item['part'], 'name' => $parts[$item['part']]['name'] ?? null] + array_intersect_key($item, array_flip($keys));
            $work = array_map(static fn (array $item): array => $named($item, ['description']), (array) ($state['in_progress'] ?? []));
            $shown[] = [
                'id' => $id,
                'kind' => $state['kind'],
                'surface' => $state['surface'] ?? null,
                'in_progress' => $work,
                'temporary' => array_values((array) ($state['temporary'] ?? [])),
                'exceptions' => array_map(static fn (array $item): array => $named($item, ['presence', 'description']), (array) ($state['exceptions'] ?? [])),
            ];
            $facts[$id] = ['kind' => $state['kind'], 'temporary' => array_values((array) ($state['temporary'] ?? [])), 'surface' => $state['surface'] ?? null, 'in_progress' => array_column($work, 'description')];
        }

        return [
            'payload' => [
                'variables' => array_intersect_key($variables, array_flip(['aspect_ratio', 'video_length', 'platform'])),
                'story' => ['hero_beat' => $script['story']['hero_beat'] ?? null, 'loops' => array_values((array) ($script['story']['loops'] ?? []))],
                'beats' => $beats,
                'build_states' => $shown,
                'motorcycle' => ['size' => $finished],
                'avoid_names' => $names,
            ],
            'context' => [
                'beats' => $beats,
                'build_states' => $facts,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $beat
     * @param  array<string, array<string, mixed>>  $parts
     * @param  array<string, array<string, mixed>>  $states
     * @param  array<string, array<string, true>>  $present
     * @param  array<string, mixed>  $finished  the size of the finished motorcycle
     * @return list<array{ref: string, name: ?string, size: array<string, mixed>}> the motorcycle as built at a physical build state, a physical model of a design stage, and every part the beat names, each with its size
     */
    private static function subjects(array $beat, array $parts, array $states, array $present, array $finished, mixed $name): array
    {
        $state = is_string($beat['state'] ?? null) ? $beat['state'] : null;
        $index = $state !== null && preg_match('/^BS(\d{2})$/', $state, $match) === 1 ? (int) $match[1] : null;
        $subjects = [];

        if (($beat['act'] ?? null) !== 'ending' && $index !== null && $index >= self::FIRST_BUILT_STATE) {
            $subjects[] = ['ref' => 'motorcycle', 'name' => is_string($name) ? $name : null, 'size' => self::box(array_intersect_key($parts, $present[$state] ?? []), 'part_boxes')];
        } elseif ($state !== null && ($states[$state]['model_medium'] ?? null) === 'physical') {
            $scale = (int) ($states[$state]['scale_denominator'] ?? 0);
            $subjects[] = ['ref' => 'model', 'name' => 'physical model', 'size' => $scale > 1 && $finished['basis'] !== 'unknown' ? [
                'length_m' => round($finished['length_m'] / $scale, 2),
                'width_m' => round($finished['width_m'] / $scale, 2),
                'height_m' => round($finished['height_m'] / $scale, 2),
                'basis' => 'scale_model',
            ] : self::unknown()];
        }

        foreach ((array) ($beat['design_refs'] ?? []) as $ref) {
            if (isset($parts[$ref])) {
                $subjects[] = ['ref' => $ref, 'name' => $parts[$ref]['name'] ?? null, 'size' => self::box([$parts[$ref]], 'part_box')];
            }
        }

        return $subjects;
    }

    /**
     * @param  array<string, mixed>  $beat
     * @param  array<string, mixed>  $design
     * @return list<array{source: string, name: ?string, steps: list<string>}> the sequence of use of every feature the beat shows working: the room the operation needs
     */
    private static function operations(array $beat, array $design): array
    {
        $features = array_column((array) ($design['features'] ?? []), null, 'id');
        $operations = [];

        foreach ((array) ($beat['in_use'] ?? []) as $ref) {
            $steps = array_values((array) ($features[$ref]['use_sequence'] ?? []));

            if ($steps !== []) {
                $operations[] = ['source' => "{$ref}.use_sequence", 'name' => $features[$ref]['name'] ?? null, 'steps' => $steps];
            }
        }

        return $operations;
    }

    /**
     * @param  array<string, mixed>  $beat
     * @param  array<string, mixed>  $design
     * @return list<array{source: string, text: string}> the time and light the design gives each signature moment the beat plays, with its source
     */
    private static function conditions(array $beat, array $design): array
    {
        $moments = array_column((array) ($design['motorcycle']['signature_moments'] ?? []), null, 'id');
        $conditions = [];

        foreach ((array) ($beat['design_refs'] ?? []) as $ref) {
            if (is_string($moments[$ref]['time_light'] ?? null)) {
                $conditions[] = ['source' => "{$ref}.time_light", 'text' => $moments[$ref]['time_light']];
            }
        }

        return $conditions;
    }

    /**
     * @param  array<string, mixed>  $beat
     * @param  array<string, mixed>  $design
     * @return list<array{source: string, requires: list<string>, conditions: list<array<string, mixed>>, procedures: list<string>, basis: string}> every operational limit that applies to the beat — through a position it shows, a part, system or feature it shows working (with the parts they work through) or a moment it plays — with its requirements, conditions, procedures and basis verbatim; no meaning is added to a procedure
     */
    public static function limits(array $beat, array $design): array
    {
        $moments = array_column((array) ($design['motorcycle']['signature_moments'] ?? []), null, 'id');
        $features = array_column((array) ($design['features'] ?? []), null, 'id');
        $systems = array_column((array) ($design['systems'] ?? []), null, 'id');
        $working = [];
        $played = [];
        $limits = [];

        foreach ((array) ($beat['in_use'] ?? []) as $ref) {
            $working = [...$working, $ref, ...(array) ($features[$ref]['parts'] ?? $systems[$ref]['parts'] ?? [])];
        }

        foreach ((array) ($beat['design_refs'] ?? []) as $ref) {
            $played = [...$played, ...(array) ($moments[$ref]['limits'] ?? [])];
        }

        foreach ((array) ($design['operational_limits'] ?? []) as $limit) {
            $applies = array_intersect((array) $limit['governs'], (array) ($beat['configurations'] ?? [])) !== []
                || ((array) $limit['governs'] === [] && in_array($limit['applies_to']['id'] ?? null, $working, true))
                || in_array($limit['id'], $played, true);

            if ($applies) {
                $limits[] = [
                    'source' => (string) $limit['id'],
                    'requires' => array_values((array) $limit['requires']),
                    'conditions' => array_values((array) $limit['conditions']),
                    'procedures' => array_values((array) $limit['procedures']),
                    'basis' => (string) ($limit['basis'] ?? ''),
                ];
            }
        }

        return $limits;
    }

    /**
     * @param  array<string, array<string, mixed>>  $parts
     * @param  array<string, array<string, mixed>>  $states
     * @return array<string, array<string, true>> the parts present in every physical build state: by their lifespan, then by the state's exceptions
     */
    private static function present(array $parts, array $states): array
    {
        $present = [];

        for ($index = self::FIRST_BUILT_STATE; $index <= self::LAST_STATE; $index++) {
            $id = sprintf('BS%02d', $index);
            $present[$id] = [];

            foreach ($parts as $partId => $part) {
                $from = is_string($part['from_state'] ?? null) ? (int) substr($part['from_state'], 2) : null;
                $until = is_string($part['until_state'] ?? null) ? (int) substr($part['until_state'], 2) : null;

                if (($part['present'] ?? false) && $from !== null && $from <= $index && ($until === null || $index <= $until)) {
                    $present[$id][$partId] = true;
                }
            }

            foreach ((array) ($states[$id]['exceptions'] ?? []) as $exception) {
                if ($exception['presence'] === 'removed') {
                    unset($present[$id][$exception['part']]);
                } else {
                    $present[$id][$exception['part']] = true;
                }
            }
        }

        return $present;
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $parts
     * @return array{length_m: ?float, width_m: ?float, height_m: ?float, basis: string} the size of the box around the parts' boxes, in metres, from its own lowest to its highest point: a part's own size, and for a motorcycle on its wheels (lowest point on the ground) its height above the ground; unknown when no part has a box
     */
    private static function box(array $parts, string $basis): array
    {
        $boxes = array_values(array_filter(array_map(static fn (array $part): mixed => $part['extent'] ?? null, $parts), 'is_array'));

        if ($boxes === []) {
            return self::unknown();
        }

        return [
            'length_m' => round((max(array_column($boxes, 'x_to')) - min(array_column($boxes, 'x_from'))) / 1000, 2),
            'width_m' => round((max(array_column($boxes, 'y_max')) - min(array_column($boxes, 'y_min'))) / 1000, 2),
            'height_m' => round((max(array_column($boxes, 'z_max')) - min(array_column($boxes, 'z_min'))) / 1000, 2),
            'basis' => $basis,
        ];
    }

    /** @return array{length_m: null, width_m: null, height_m: null, basis: string} */
    private static function unknown(): array
    {
        return ['length_m' => null, 'width_m' => null, 'height_m' => null, 'basis' => 'unknown'];
    }

    /**
     * @param  array<string, mixed>  $source  the design source the script pinned
     * @return array<string, mixed> the data of the pinned design answer, once it still matches the hash the script pinned
     */
    public static function pinnedDesign(string $projectId, array $source): array
    {
        $stage = VideoPlanningStage::query()->whereKey($source['design_stage_id'] ?? null)->where('project_id', $projectId)
            ->where('stage', PlanningStageName::YACHT_DESIGN->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();

        if ($stage === null || ! hash_equals((string) ($source['design_raw_sha256'] ?? ''), hash('sha256', (string) $stage->raw_response))) {
            throw new RuntimeException('Bản thiết kế mà kịch bản đã ghim không còn hoặc không còn nguyên vẹn.');
        }

        $data = JsonAnswer::object((string) $stage->raw_response)['data'] ?? null;

        if (! is_array($data) || ! is_array($data['build_states'] ?? null)) {
            throw new RuntimeException('Bản thiết kế mà kịch bản đã ghim không có build_states.');
        }

        return $data;
    }
}
