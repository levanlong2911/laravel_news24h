<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\ScriptSourceService;
use App\Video\Script\StructureChecked;
use App\Video\Script\NormalizesAnswer;
use App\Video\Llm\JsonAnswer;
use App\Video\Locations\LocationWorkflow;
use RuntimeException;

final class MotoLocations implements LocationWorkflow, ReleaseBound, StructureChecked, NormalizesAnswer
{
    use ChecksJsonStructure;

    /** @var list<string> */
    private const BEAT_KEYS = ['id', 'state', 'duration_s', 'location', 'time_light', 'visual', 'purpose', 'reveal', 'link'];

    /** @var list<string> the fields of a beat that is told as events and has no screen time of its own */
    private const EVENTFUL_BEAT_KEYS = ['id', 'state', 'location', 'time_light', 'visual', 'purpose', 'reveal', 'link', 'on_screen'];

    /** @var list<string> the fields of a beat a location dossier reads: those of an eventful beat with its act, whether it jumps in time and its sound effects */
    private const DOSSIER_BEAT_KEYS = ['id', 'act', 'state', 'time_jump', 'location', 'time_light', 'visual', 'purpose', 'reveal', 'link', 'sfx', 'on_screen'];

    /** @var list<string> the story fields every build state of a themed design carries */
    private const STORY_KEYS = ['text', 'setting_brief', 'participant_roles', 'outcome'];

    private const FIRST_BUILT_STATE = 6;

    private const LAST_STATE = 12;

    /** @var bool whether the project tells its story as events, with screen time set by the shot plan */
    private bool $eventful = false;

    public function __construct(private readonly ScriptSourceService $scripts) {}

    /** @var bool whether the release designs seven stages with checkpoints, so each beat carries the checkpoints it runs through */
    private bool $checkpointed = false;

    /** @var bool whether the release plans its places as a location dossier (moto-locations-v3): the fourth input, and an answer with zones, routes, connections and the conditions of each plate */
    private bool $dossier = false;

    public function bind(VideoProject $project): void
    {
        $this->eventful = MotoPipelineRelease::cinematic($project);
        $this->checkpointed = $this->eventful && MotoPipelineRelease::checkpointed($project);
        $this->dossier = $this->eventful && MotoPipelineRelease::dossier($project);
        $this->setDesign = $this->dossier && MotoPipelineRelease::setDesign($project);
    }

    /** @var bool whether the release plans its places as a set design dossier (moto-locations-v4): the fifth input, and an answer in which each place states the one design of its set and each plate only its use */
    private bool $setDesign = false;

    public function format(): string
    {
        return match (true) {
            $this->setDesign => MotoContract::LOCATIONS_FORMAT_V4,
            $this->dossier => MotoContract::LOCATIONS_FORMAT_V3,
            $this->eventful => MotoContract::LOCATIONS_FORMAT_V2,
            default => MotoContract::LOCATIONS_FORMAT,
        };
    }

    /** A release that took a dossier still reads the sets its projects pinned before: under moto-locations-v2 and, for a set design release, under moto-locations-v3. */
    public function formats(): array
    {
        return match (true) {
            $this->setDesign => [MotoContract::LOCATIONS_FORMAT_V4, MotoContract::LOCATIONS_FORMAT_V3, MotoContract::LOCATIONS_FORMAT_V2],
            $this->dossier => [MotoContract::LOCATIONS_FORMAT_V3, MotoContract::LOCATIONS_FORMAT_V2],
            default => [$this->format()],
        };
    }

    public function checkVersion(): string
    {
        return MotoLocationsCheck::VERSION;
    }

    /**
     * @param  string  $format  the answer format of the set a person wants to render from
     * @return ?string why the plates of that set are never rendered one by one from their words alone, null while they render as before. A set design dossier (moto-locations-v4) states what every frame of one place keeps: rendering its plates each on their own would make two frames of one place two different places. The hold is lifted plate by plate and only by the image plan of the shared reference (MotoLocationImagePlan, LocationSourceService::referenceOf): the standard plate of a place renders from its own prompt, every other plate of the place from the image chosen for that standard. Where no plan can be read for such a set the hold stands, and nothing falls back to the way an earlier format renders
     */
    public static function renderHold(string $format): ?string
    {
        return $format === MotoContract::LOCATIONS_FORMAT_V4
            ? 'Bộ này theo hợp đồng '.MotoContract::LOCATIONS_FORMAT_V4.': các ảnh nền của cùng một địa điểm dựng từ một ảnh chuẩn chung ('.MotoLocationImagePlan::MECHANISM.'), không dựng riêng từng ảnh từ prompt như hợp đồng cũ — mỗi ảnh nền chỉ render khi kế hoạch ảnh của địa điểm cho phép.'
            : null;
    }

    /**
     * @return array{version: string, instruction: array<string, string>, changes: array<string, string>, unstated: list<string>} the fixed words a plate rendered from the standard image of its place is sent with before its approved prompt, written as an edit of that image: the sentences of the instruction, the words for each thing a continuity entry may let change, and what may change between two plates no entry names together (MotoLocationImagePlan::instruction joins them for one pair of plates). The words are part of the render request and of its hash, never of the approved prompt
     *
     * @throws RuntimeException when the configuration names another mechanism, lacks a sentence or lacks the words for a thing that may change; nothing is rendered from a source then
     */
    public static function sharedReference(): array
    {
        $config = (array) config('video.moto.locations.shared_reference');
        $sentences = array_map(static fn (mixed $sentence): string => is_string($sentence) ? trim($sentence) : '', (array) ($config['instruction'] ?? []));
        $changes = array_filter((array) ($config['changes'] ?? []), static fn (mixed $words): bool => is_string($words) && trim($words) !== '');
        $unstated = array_values((array) ($config['unstated'] ?? []));
        $complete = array_diff(MotoLocationImagePlan::SENTENCES, array_keys(array_filter($sentences, static fn (string $sentence): bool => $sentence !== ''))) === []
            && array_diff(MotoLocationImagePlan::MAY_CHANGE, array_keys($changes)) === []
            && array_diff($unstated, MotoLocationImagePlan::MAY_CHANGE) === [];

        if (($config['version'] ?? null) !== MotoLocationImagePlan::MECHANISM || ! $complete) {
            throw new RuntimeException('Cấu hình video.moto.locations.shared_reference không khai đúng cơ chế '.MotoLocationImagePlan::MECHANISM.' hoặc thiếu chỉ dẫn cố định — không render ảnh nền dựng từ ảnh chuẩn.');
        }

        return ['version' => MotoLocationImagePlan::MECHANISM, 'instruction' => $sentences, 'changes' => $changes, 'unstated' => $unstated];
    }

    public function plannerVersion(): string
    {
        return match (true) {
            $this->setDesign => MotoLocationPlanner::SET_DESIGN_VERSION,
            $this->dossier => MotoLocationPlanner::DOSSIER_VERSION,
            default => MotoLocationPlanner::VERSION,
        };
    }

    public function inputsVersion(): string
    {
        return match (true) {
            $this->setDesign => MotoContract::LOCATIONS_INPUT_V5,
            $this->dossier => MotoContract::LOCATIONS_INPUT_V4,
            $this->checkpointed => MotoContract::LOCATIONS_INPUT_V3,
            $this->eventful => MotoContract::LOCATIONS_INPUT_V2,
            default => MotoContract::LOCATIONS_INPUT,
        };
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

        $scriptFormat = match (true) {
            $this->checkpointed => MotoContract::SCRIPT_FORMAT_V3,
            $this->eventful => MotoContract::SCRIPT_FORMAT_V2,
            default => MotoContract::SCRIPT_FORMAT,
        };

        if (($script['document']['format'] ?? null) !== $scriptFormat) {
            throw new RuntimeException('Kịch bản đang chọn không theo định dạng '.$scriptFormat.' — không lập địa điểm moto từ nó.');
        }

        $stage = VideoPlanningStage::query()->whereKey($script['stage_id'])->where('project_id', $projectId)->firstOrFail();
        $meta = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $pinned = json_decode((string) app(PlanningStageStore::class)->payloadOf($stage), true);
        $source = (array) ($meta['source'] ?? []);
        $scriptContext = (array) ($meta['context'] ?? []);

        if (! is_array($pinned) || ! is_array($pinned['variables'] ?? null)) {
            throw new RuntimeException('Kịch bản đã duyệt không còn payload đã ghim — không lập được địa điểm và bối cảnh.');
        }

        $design = self::pinnedDesign($projectId, $source);
        $names = array_values(array_map('strval', (array) ($scriptContext['source_names'] ?? [])));
        $built = $this->dossier
            ? self::build((array) $script['document']['data'], $design, (array) $pinned['variables'], $names, true, array_values(array_filter((array) ($script['document']['flags'] ?? []), 'is_string')), MotoLocationPlanner::style((array) config('video.moto.locations.reference'), $this->plannerVersion()), $this->setDesign)
            : self::build((array) $script['document']['data'], $design, (array) $pinned['variables'], $names, $this->eventful);

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
            ] + (is_array($script['validation'] ?? null) && $script['validation']['version'] !== MotoScriptValidation::LEGACY
                // The policy of the script these locations are made from, so one chain never mixes two.
                ? [MotoScriptValidation::KEY => $script['validation']]
                : []),
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

    public function check(?array $response, array $context, array $schema): array
    {
        return MotoLocationsCheck::evaluate($response, $context, $schema);
    }

    public function derive(array $document, array $context): array
    {
        return MotoLocationPlanner::plan($document, $context);
    }

    public function normalizeAnswer(object $answer, \App\Video\Superyacht\Spec\ResolvedPhase $bundle): array
    {
        return MotoLocationsAnswer::prepare($answer, $bundle->outputSchemas());
    }

    public function systemText(array $parts, array $schemas): string
    {
        $heading = (string) config('video.moto.locations.schema_heading');

        return implode("\n\n", [
            ...$parts,
            ...array_map(static fn (array $schema): string => "## {$heading} — {$schema['label']}\n\n```json\n".MotoContract::compactSchema((string) $schema['content'])."\n```", $schemas),
            ...(MotoLocationsAnswer::applies($schemas) ? [MotoLocationsAnswer::INSTRUCTION] : []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $script  the data of the approved script
     * @param  array<string, mixed>  $design  the data of the design the script pinned
     * @param  array<string, mixed>  $variables  the variables the script was written with
     * @param  list<string>  $names  the source names the script pinned
     * @param  bool  $eventful  whether the script tells its beats as events without screen time, from a design that tells every build state
     * @param  ?list<string>  $flags  for a location dossier (moto-locations-input-v4), what the script reported as unsettled; null for every earlier input, which is built exactly as before
     * @param  array<string, string>  $style  for a location dossier, the look code gives every plate, by plate style
     * @param  bool  $setDesign  a set design dossier (moto-locations-input-v5): the dossier input with temporary_equipment, every temporary item the checkpoints of the design allow in their order, also pinned in the context, and with no subject for a part the design leaves out of the motorcycle at a beat that only represents it (subjects())
     * @return array{payload: array<string, mixed>, context: array<string, mixed>} what a place needs from the script and the design: the beats with the subjects they show and their sizes, the operations they perform and the environment the design sets for them; the setting of each design stage; the surface, work in progress, temporary equipment and exceptions of each build state; no part record, coordinate, limit or model-sheet prompt. A dossier also carries the theme and emotional goal of the story, the characters by role, each beat's act, time jump and sound effects, each event's vehicle motion and the flags of the script
     */
    public static function build(array $script, array $design, array $variables, array $names, bool $eventful = false, ?array $flags = null, array $style = [], bool $setDesign = false): array
    {
        $dossier = $flags !== null;
        $parts = array_column((array) ($design['parts'] ?? []), null, 'id');
        $states = array_column((array) ($design['build_states'] ?? []), null, 'id');
        $roles = array_column((array) ($design['participant_roles'] ?? []), 'name', 'id');
        $layout = MotoDesignStates::of($design);
        $present = self::present($parts, $states, $layout);
        $physical = $layout->physical();
        $finished = self::box(array_intersect_key($parts, $present[$layout->checkpointed() ? $physical[count($physical) - 1] : sprintf('BS%02d', self::LAST_STATE)] ?? []), 'part_boxes');
        $beats = [];
        $keys = match (true) {
            $dossier => self::DOSSIER_BEAT_KEYS,
            $eventful => self::EVENTFUL_BEAT_KEYS,
            default => self::BEAT_KEYS,
        };

        foreach ((array) ($script['beats'] ?? []) as $beat) {
            $situation = is_array($beat['situation'] ?? null)
                ? ['mode' => $beat['situation']['mode'], 'dry_road' => $beat['situation']['dry_road'], 'procedures' => array_values((array) ($beat['situation']['procedures'] ?? []))]
                : null;
            $events = $eventful ? ['events' => array_map(static fn (array $event): array => array_intersect_key($event, array_flip(['id', 'action', 'result', 'character_ids']))
                + ($dossier ? ['vehicle_motion' => [$event['operating_start']['vehicle_motion'] ?? null, $event['operating_end']['vehicle_motion'] ?? null]] : []), (array) ($beat['events'] ?? []))] : [];
            // A beat of a v3 design carries the checkpoints it runs through and only the temporary equipment those checkpoints hold.
            $span = $layout->checkpointed() ? [
                'checkpoint_start' => $beat['checkpoint_start'] ?? null,
                'checkpoint_end' => $beat['checkpoint_end'] ?? null,
                'temporary' => array_values(array_unique(array_merge([], ...array_map(static fn (string $id): array => array_values((array) ($states[$id]['temporary'] ?? [])), $layout->span($beat))))),
            ] : [];
            $beats[] = array_combine($keys, array_map(static fn (string $key): mixed => $beat[$key] ?? null, $keys)) + $span + $events + [
                'situation' => $situation,
                'subjects' => self::subjects($beat, $parts, $states, $present, $finished, $design['motorcycle']['name'] ?? null, $layout, $setDesign),
                'operations' => self::operations($beat, $design),
                'conditions' => self::conditions($beat, $design),
                'limits' => self::limits($beat, $design),
            ];
        }

        $facts = [];
        $shown = [];

        foreach ($states as $id => $state) {
            if (isset($state['participant_roles']) && $roles !== []) {
                $state['participant_roles'] = array_values(array_map(static fn (string $role): string => $roles[$role] ?? $role, (array) $state['participant_roles']));
            }

            if (! isset($state['surfaces'])) {
                $keys = ['id', 'kind', 'setting_brief', 'participant_roles', 'model_medium', 'scale_denominator'];
                $shown[] = array_filter(array_combine($keys, array_map(static fn (string $key): mixed => $state[$key] ?? null, $keys)), static fn (mixed $value): bool => $value !== null);
                $facts[$id] = ['kind' => $state['kind'], 'temporary' => [], 'setting_brief' => (string) $state['setting_brief']];

                continue;
            }

            $named = static fn (array $item, array $keys): array => ['part' => $item['part'], 'name' => $parts[$item['part']]['name'] ?? null] + array_intersect_key($item, array_flip($keys));
            $work = array_map(static fn (array $item): array => $named($item, ['description']), (array) ($state['in_progress'] ?? []));
            // A checkpoint of a v3 design names its stage and what it marks.
            $checkpoint = $layout->checkpointed() ? ['stage' => $state['stage'] ?? null, 'label' => $state['label'] ?? null] : [];
            $shown[] = [
                'id' => $id,
                'kind' => $state['kind'],
            ] + $checkpoint + array_intersect_key($state, array_flip(self::STORY_KEYS)) + [
                'surface' => $state['surface'] ?? null,
                'in_progress' => $work,
                'temporary' => array_values((array) ($state['temporary'] ?? [])),
                'exceptions' => array_map(static fn (array $item): array => $named($item, ['presence', 'description']), (array) ($state['exceptions'] ?? [])),
            ];
            $facts[$id] = ['kind' => $state['kind']] + $checkpoint + ['temporary' => array_values((array) ($state['temporary'] ?? [])), 'surface' => $state['surface'] ?? null, 'in_progress' => array_column($work, 'description')];
        }

        $story = ['hero_beat' => $script['story']['hero_beat'] ?? null, 'loops' => array_values((array) ($script['story']['loops'] ?? []))];

        if ($dossier) {
            $temporary = $setDesign ? ['temporary_equipment' => array_values(array_unique(array_merge([], ...array_map(static fn (array $fact): array => array_values(array_map('strval', (array) ($fact['temporary'] ?? []))), array_values($facts)))))] : [];

            return [
                'payload' => [
                    'variables' => array_intersect_key($variables, array_flip(['aspect_ratio', 'platform'])) + ['visual_style' => $style],
                    'story' => ['theme' => $script['story']['theme'] ?? null, 'emotional_goal' => $script['story']['emotional_goal'] ?? null] + $story,
                    'characters' => array_values(array_map(static fn (array $character): array => ['id' => (string) $character['id'], 'role' => implode(', ', array_map(static fn (string $role): string => $roles[$role] ?? $role, (array) $character['role_ids']))], (array) ($script['characters'] ?? []))),
                    'beats' => $beats,
                    'build_states' => $shown,
                ] + $temporary + [
                    'motorcycle' => ['size' => $finished],
                    'source_flags' => array_values($flags),
                    'avoid_names' => $names,
                ],
                'context' => [
                    'beats' => $beats,
                    'build_states' => $facts,
                    'motorcycle' => ['size' => $finished],
                    'source_flags' => array_values($flags),
                ] + $temporary + ($layout->checkpointed() ? [MotoDesignStates::LAYOUT_KEY => MotoDesignStates::CHECKPOINTS] : []),
            ];
        }

        return [
            'payload' => [
                'variables' => array_intersect_key($variables, array_flip(['aspect_ratio', 'video_length', 'platform'])),
                'story' => $story,
                'beats' => $beats,
                'build_states' => $shown,
                'motorcycle' => ['size' => $finished],
                'avoid_names' => $names,
            ],
            'context' => [
                'beats' => $beats,
                'build_states' => $facts,
            ] + ($layout->checkpointed() ? [MotoDesignStates::LAYOUT_KEY => MotoDesignStates::CHECKPOINTS] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $beat
     * @param  array<string, array<string, mixed>>  $parts
     * @param  array<string, array<string, mixed>>  $states
     * @param  array<string, array<string, true>>  $present
     * @param  array<string, mixed>  $finished  the size of the finished motorcycle
     * @param  bool  $setDesign  a set design input (moto-locations-input-v5); every earlier input lists its subjects exactly as before
     * @return list<array{ref: string, name: ?string, size: array<string, mixed>}> the motorcycle as built at a physical build state, a physical model of a design stage, and every part the beat names, each with its size. In a set design input a part the design leaves out of the motorcycle (present false) is no subject of a beat whose situation mode is representation: there it is only drawn, so nothing of it stands in the place. The same part named by any other beat stays listed with its unknown size, so that the use of a part the design left out is still seen; a part of the design that a representation beat names, the physical model and every subject whose size is unknown are listed as before
     */
    private static function subjects(array $beat, array $parts, array $states, array $present, array $finished, mixed $name, MotoDesignStates $layout, bool $setDesign = false): array
    {
        $state = is_string($beat['state'] ?? null) ? $beat['state'] : null;
        $index = $state !== null && preg_match('/^BS(\d{2})$/', $state, $match) === 1 ? (int) $match[1] : null;
        $built = $layout->checkpointed() ? $state !== null && $layout->physicalStage($state) : $index !== null && $index >= self::FIRST_BUILT_STATE;
        // The motorcycle as it stands where the beat opens: its own state in a legacy design, its opening checkpoint in a v3 design.
        $at = $layout->checkpointed() ? $layout->opening($beat) : $state;
        $subjects = [];

        if (($beat['act'] ?? null) !== 'ending' && $built) {
            $subjects[] = ['ref' => 'motorcycle', 'name' => is_string($name) ? $name : null, 'size' => self::box(array_intersect_key($parts, $present[$at] ?? []), 'part_boxes')];
        } elseif ($state !== null && ($states[$state]['model_medium'] ?? null) === 'physical') {
            $scale = (int) ($states[$state]['scale_denominator'] ?? 0);
            $subjects[] = ['ref' => 'model', 'name' => 'physical model', 'size' => $scale > 1 && $finished['basis'] !== 'unknown' ? [
                'length_m' => round($finished['length_m'] / $scale, 2),
                'width_m' => round($finished['width_m'] / $scale, 2),
                'height_m' => round($finished['height_m'] / $scale, 2),
                'basis' => 'scale_model',
            ] : self::unknown()];
        }

        $drawn = $setDesign && ($beat['situation']['mode'] ?? null) === 'representation';

        foreach ((array) ($beat['design_refs'] ?? []) as $ref) {
            if (isset($parts[$ref]) && ! ($drawn && ($parts[$ref]['present'] ?? null) === false)) {
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
    private static function present(array $parts, array $states, MotoDesignStates $layout): array
    {
        $present = [];
        // A legacy design places a state by its stage number, a v3 design by the order of its checkpoints.
        $order = $layout->checkpointed() ? $layout->order() : null;
        $place = static fn (mixed $state): ?int => ! is_string($state) ? null : ($order === null ? (int) substr($state, 2) : ($order[$state] ?? null));

        foreach ($order ?? array_combine(array_map(static fn (int $index): string => sprintf('BS%02d', $index), range(self::FIRST_BUILT_STATE, self::LAST_STATE)), range(self::FIRST_BUILT_STATE, self::LAST_STATE)) as $id => $index) {
            $present[$id] = [];

            foreach ($parts as $partId => $part) {
                $from = $place($part['from_state'] ?? null);
                $until = $place($part['until_state'] ?? null);

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

        $answer = JsonAnswer::object((string) $stage->raw_response);
        // A legacy design is read as it is; a v3 design by its checkpoints, the way every later step reads the states.
        $data = is_array($answer['data'] ?? null) ? MotoDesignStates::normalize($answer) : null;

        if (! is_array($data) || ! is_array($data['build_states'] ?? null)) {
            throw new RuntimeException('Bản thiết kế mà kịch bản đã ghim không có build_states.');
        }

        return $data;
    }
}
