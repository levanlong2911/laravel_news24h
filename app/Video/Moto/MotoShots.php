<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\LocationSourceService;
use App\Services\Video\ModelSheetService;
use App\Services\Video\PartsDetailService;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\SceneSourceService;
use App\Services\Video\ScriptSourceService;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Media\VideoModelRegistry;
use App\Video\Shots\ShotWorkflow;
use App\Video\Superyacht\Spec\ResolvedPhase;
use RuntimeException;

final class MotoShots implements ShotWorkflow
{
    /** @var list<string> */
    private const BEAT_KEYS = ['id', 'act', 'state', 'time_jump', 'purpose', 'visual', 'location', 'time_light', 'reveal', 'on_screen', 'design_refs', 'in_use', 'configurations', 'situation', 'link', 'sfx'];

    /** @var int the elevation in degrees from which a plan view answers better than a side angle */
    private const PLAN_ELEVATION = 60;

    /** @var array<string, ?string> for each MotoShots major.minor version, the timing a run of it pins: none before 1.2, where the model writes every screen time; MotoShotsCheck::TIMING from 1.2, where code sets whole and closing parts and the shot */
    private const TIMINGS = ['1.0' => null, '1.1' => null, '1.2' => MotoShotsCheck::TIMING];

    public function __construct(
        private readonly SceneSourceService $scenes,
        private readonly ScriptSourceService $scripts,
        private readonly LocationSourceService $locations,
        private readonly ModelSheetService $sheets,
        private readonly PartsDetailService $references,
    ) {}

    public function format(): string
    {
        return MotoContract::SHOTS_FORMAT;
    }

    public function checkVersion(): string
    {
        return MotoShotsCheck::VERSION;
    }

    public function plannerVersion(): string
    {
        return MotoShotPlanner::VERSION;
    }

    public function inputsVersion(): string
    {
        return MotoContract::SHOTS_INPUT;
    }

    public function settings(): array
    {
        $config = (array) config('video.moto.shots');

        return [
            'execution' => (string) $config['execution'],
            'model' => (string) $config['model'],
            'effort' => (string) $config['effort'],
            'max_tokens' => (int) $config['max_tokens'],
            'timeout_seconds' => (int) $config['timeout_seconds'],
            'schema_heading' => (string) $config['schema_heading'],
        ];
    }

    public function inputs(VideoProject $project, string $prompt): array
    {
        $projectId = (string) $project->id;
        $scenes = $this->scenes->approvedSource($projectId);
        $stage = VideoPlanningStage::query()->whereKey($scenes['stage_id'])->where('project_id', $projectId)->firstOrFail();
        $sceneInput = json_decode((string) app(PlanningStageStore::class)->payloadOf($stage), true);
        $source = $this->scenes->source($stage);

        if (! is_array($sceneInput) || ! is_array($sceneInput['design_states'] ?? null)) {
            throw new RuntimeException('Bản chia scene đã duyệt không còn payload đã ghim — không lập được shot.');
        }

        $script = $this->scripts->approvedSource($projectId);
        $text = $this->locations->approvedText($projectId);

        if ((string) $text['stage']->id !== (string) ($source['locations']['stage_id'] ?? '')) {
            throw new RuntimeException('Bộ bối cảnh đang dùng không phải bộ mà bản chia scene đã ghim.');
        }

        $design = MotoLocations::pinnedDesign($projectId, (array) ($source['design'] ?? []));
        $built = self::build(
            (array) $scenes['plan'],
            $sceneInput,
            (array) $script['document']['data'],
            (array) $text['evaluation']['document']['data'],
            $design,
            array_keys($this->viewImages($projectId)),
            self::capabilities(app(VideoModelRegistry::class)->forTask((string) config('video.moto.shots.clip_task')), (string) ($sceneInput['variables']['aspect_ratio'] ?? '')),
        );
        $problems = self::precheck($built['context']);

        if ($problems !== []) {
            throw new RuntimeException('Kiểm trước khi lập shot chưa qua, không gọi AI: '.implode(' · ', $problems));
        }

        return [
            'payload' => $built['payload'],
            'source' => ['scenes' => $scenes['token']],
            'context' => $built['context'] + ['format' => $this->format()],
        ];
    }

    public function contextProblem(array $context, string $contract): ?string
    {
        $name = explode('#', $contract)[0];

        if (preg_match('/^moto\/MotoShots@(\d+\.\d+)\.\d+$/', $name, $match) !== 1 || ! array_key_exists($match[1], self::TIMINGS)) {
            return "hợp đồng {$name} không phải bản MotoShots mà code biết";
        }

        $pinned = $context['timing'] ?? null;
        $expected = self::TIMINGS[$match[1]];

        return $pinned === $expected ? null : 'ngữ cảnh kiểm ghim timing '.json_encode($pinned)." nhưng hợp đồng {$name} cần ".json_encode($expected);
    }

    public function sourceProblem(string $projectId, array $source): ?string
    {
        try {
            $current = $this->scenes->approvedSource($projectId);
        } catch (RuntimeException $e) {
            return 'Bản chia scene: '.$e->getMessage();
        }

        return $current['token'] == ($source['scenes'] ?? null) ? null : 'Bản chia scene đã duyệt hoặc nguồn của nó đã đổi so với lúc lập kế hoạch shot này.';
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
        return MotoShotsCheck::evaluate($response, $context, $schema);
    }

    public function derive(array $document, array $context): array
    {
        return MotoShotPlanner::plan($document, $context);
    }

    public function systemText(array $parts, array $schemas): string
    {
        $heading = (string) config('video.moto.shots.schema_heading');

        return implode("\n\n", [
            ...$parts,
            ...array_map(static fn (array $schema): string => "## {$heading} — {$schema['label']}\n\n```json\n".MotoContract::compactSchema((string) $schema['content'])."\n```", $schemas),
        ]);
    }

    public function resources(string $projectId, array $plan): array
    {
        $views = $this->viewImages($projectId);
        $plates = [];

        foreach ((array) ($this->locations->selection($projectId)['images'] ?? []) as $background => $chosen) {
            if (is_string($chosen['artifact_id'] ?? null) && VideoArtifact::query()->whereKey($chosen['artifact_id'])->where('project_id', $projectId)->exists()) {
                $plates[(string) $background] = (string) $chosen['artifact_id'];
            }
        }

        $resources = [];
        $bindings = $this->references->bindings($projectId, array_values((array) ($plan['shots'] ?? [])), array_values((array) ($plan['finished_states'] ?? [])));

        foreach ((array) ($plan['shots'] ?? []) as $shot) {
            $resources[(string) $shot['id']] = self::readiness($shot, $views, $plates, (array) ($plan['finished_states'] ?? []), $bindings);
        }

        return $resources;
    }

    /**
     * @param  array<string, mixed>  $scenePlan  the derived plan of the approved scene plan
     * @param  array<string, mixed>  $sceneInput  the input the scene plan was written from
     * @param  array<string, mixed>  $script  the data of the approved script
     * @param  array<string, mixed>  $locations  the data of the approved background text
     * @param  array<string, mixed>  $design  the data of the design the scene plan pinned
     * @param  list<string>  $available  the model sheet views with an approved image
     * @param  list<array{duration_ms: int, modes: list<string>, tools: array<string, list<string>>}>  $capabilities  the clip lengths and modes code can render
     * @return array{payload: array<string, mixed>, context: array<string, mixed>} what a shot list needs: the scenes with their timing, continuity, transitions and work, each work item with the presentation it gives every part it moves or keeps in place before and after it; the beats with their actions, reveal, situation, environment, the ids of the limits that apply to them and cards; the places and plates; the parts with their shapes, sizes and materials; every operational limit with the configurations it governs, the same list the check reads; the build states; the model sheet views; the render capabilities
     */
    public static function build(array $scenePlan, array $sceneInput, array $script, array $locations, array $design, array $available, array $capabilities): array
    {
        $times = array_column((array) ($sceneInput['beat_times'] ?? []), null, 'beat');
        $assigned = array_column((array) ($sceneInput['locations']['beats'] ?? []), null, 'beat');
        $plates = array_column((array) ($locations['backgrounds'] ?? []), null, 'id');
        $sceneOf = [];
        $scenes = [];

        foreach ((array) $scenePlan['scenes'] as $scene) {
            foreach ((array) $scene['beats'] as $beat) {
                $sceneOf[$beat] = $scene['id'];
            }

            $scenes[] = array_intersect_key($scene, array_flip(['id', 'beats', 'start_ms', 'end_ms', 'state', 'places', 'backgrounds', 'characters', 'purpose', 'set_brief', 'continuity_from', 'continuity_start', 'continuity_end', 'transition_in', 'work']));
        }

        $beats = [];
        $beatFacts = [];
        $operational = MotoScriptCheck::facts($design)['limits'];

        foreach ((array) ($script['beats'] ?? []) as $beat) {
            $id = (string) $beat['id'];
            $uses = array_values((array) ($assigned[$id]['uses'] ?? []));
            $unplated = array_values((array) ($assigned[$id]['unplated'] ?? []));
            $cards = array_values(array_map(static fn (array $card): array => [
                'type' => $card['type'],
                'text' => $card['text'],
                'offset_ms' => (int) round((float) $card['offset_s'] * 1000),
                'duration_ms' => (int) round((float) $card['sec'] * 1000),
            ], array_filter((array) ($script['text_cards'] ?? []), static fn (array $card): bool => ($card['beat'] ?? null) === $id)));
            $limits = MotoLocations::limits($beat, $design);
            $row = ['id' => $id, 'scene' => $sceneOf[$id] ?? null, 'start_ms' => (int) ($times[$id]['start_ms'] ?? 0), 'end_ms' => (int) ($times[$id]['end_ms'] ?? 0), 'duration_ms' => (int) ($times[$id]['end_ms'] ?? 0) - (int) ($times[$id]['start_ms'] ?? 0)]
                + array_combine(array_slice(self::BEAT_KEYS, 1), array_map(static fn (string $key): mixed => $beat[$key] ?? null, array_slice(self::BEAT_KEYS, 1)))
                + ['uses' => $uses, 'unplated' => $unplated, 'limits' => array_values(array_column($limits, 'source')), 'cards' => $cards];
            $beats[] = $row;
            $beatFacts[] = [
                'id' => $id,
                'scene' => $row['scene'],
                'start_ms' => $row['start_ms'],
                'end_ms' => $row['end_ms'],
                'state' => $row['state'],
                'time_jump' => (string) $row['time_jump'],
                'reveal' => (string) $row['reveal'],
                'on_screen' => array_values((array) $row['on_screen']),
                'configurations' => array_values((array) $row['configurations']),
                'situation' => is_array($row['situation']) ? $row['situation'] : null,
                'backgrounds' => array_values(array_unique(array_column($uses, 'background'))),
                'unplated' => $unplated,
                'limits' => $limits,
                'cards' => $cards,
            ];
        }

        $parts = [];

        foreach ((array) ($design['parts'] ?? []) as $part) {
            if ($part['present'] ?? false) {
                $parts[] = array_intersect_key($part, array_flip(['id', 'name', 'kind', 'description', 'extent', 'faces', 'surfaces'])) + [
                    'configurations' => array_map(static fn (array $configuration): array => array_intersect_key($configuration, array_flip(['id', 'name', 'description'])), (array) ($part['configurations'] ?? [])),
                ];
            }
        }

        $finished = array_values(array_map(static fn (array $state): string => (string) $state['id'], array_filter((array) ($design['build_states'] ?? []), static fn (array $state): bool => ($state['kind'] ?? null) === 'finished')));
        $cameras = (array) config('video.moto.model_sheet.cameras');
        $views = array_map(static fn (array $view): array => [
            'id' => $view['id'],
            'view' => $view['view'],
            'azimuth_deg' => $cameras[$view['id']]['azimuth_deg'] ?? null,
            'elevation_deg' => $cameras[$view['id']]['elevation_deg'] ?? null,
            'projection' => $cameras[$view['id']]['projection'] ?? null,
            'available' => in_array($view['id'], $available, true),
            'states' => $finished,
        ], (array) config('video.moto.model_sheet.views'));
        $states = (array) ($sceneInput['design_states']['states'] ?? []);
        $records = array_column((array) ($design['build_states'] ?? []), null, 'id');
        $facts = [];

        foreach ($states as $state) {
            $facts[(string) $state['id']] = isset($state['present']) ? [
                'physical' => true,
                'present' => $state['present'],
                'in_progress' => array_column((array) $state['in_progress'], 'part'),
                'previously_present' => $state['previously_present'],
                'previously_in_progress' => $state['previously_in_progress'],
                'installed_later' => $state['installed_later'],
                'surface' => $state['surface'] ?? null,
                'finishes' => (array) ($records[$state['id']]['surfaces'] ?? []),
                'work' => array_column((array) $state['in_progress'], 'description', 'part'),
                'pinned' => array_filter(array_column((array) ($state['exceptions'] ?? []), 'configuration', 'part'), 'is_string'),
            ] : ['physical' => false];
        }

        $work = MotoWork::trace((array) $scenePlan['scenes'], array_column($beatFacts, 'state', 'id'), $facts, MotoScenes::workDesign($design));
        $scenes = array_map(static fn (array $scene): array => array_replace($scene, ['work' => is_array($scene['work'] ?? null) ? array_map(static fn (array $item): array => $item + [
            'presentations' => array_map(static fn (array $place): array => ['start' => $place['start_status'], 'end' => $place['end_status']], $work['items'][$item['id']]['parts'] ?? []),
        ], $scene['work']) : null]), $scenes);
        $payload = [
            'film' => [
                'total_ms' => (int) $scenePlan['total_ms'],
                'aspect_ratio' => $sceneInput['variables']['aspect_ratio'] ?? null,
                'keyframe_reference_limit' => (int) config('video.moto.shots.keyframe_reference_limit'),
            ],
            'story' => [
                'hero_beat' => $script['story']['hero_beat'] ?? null,
                'loops' => array_values((array) ($script['story']['loops'] ?? [])),
                'obstacles' => array_values((array) ($script['story']['obstacles'] ?? [])),
            ],
            'characters' => (array) ($script['characters'] ?? []),
            'scenes' => $scenes,
            'beats' => $beats,
            'places' => array_values(array_map(static fn (array $place): array => array_intersect_key($place, array_flip(['id', 'name', 'kind', 'space', 'dimensions', 'surfaces', 'fixed_equipment'])), (array) ($locations['locations'] ?? []))),
            'backgrounds' => array_values(array_map(static fn (array $plate): array => [
                'id' => $plate['id'],
                'location' => $plate['location'],
                'composition' => $plate['composition'],
                'light' => $plate['light']['description'],
                'support' => array_values((array) $plate['support']),
                'ground' => $plate['ground'],
                'details' => array_values(array_column((array) $plate['details'], 'text')),
                'must_show' => array_values((array) $plate['must_show']),
                'shot_changes' => array_values((array) $plate['shot_changes']),
            ], $plates)),
            'motorcycle' => array_intersect_key((array) ($design['motorcycle'] ?? []), array_flip(['name', 'silhouette', 'palette', 'materials'])),
            'parts' => $parts,
            'features' => array_values(array_map(static fn (array $feature): array => array_intersect_key($feature, array_flip(['id', 'name', 'parts', 'visible_expression', 'effect', 'modes', 'use_sequence'])), (array) ($design['features'] ?? []))),
            'limits' => $operational,
            'design_states' => $states,
            'views' => $views,
            'render_capabilities' => array_map(static fn (array $capability): array => ['duration_ms' => $capability['duration_ms'], 'modes' => $capability['modes']], $capabilities),
        ];

        $configurations = [];

        foreach ($parts as $part) {
            foreach ($part['configurations'] as $configuration) {
                $configurations[$configuration['id']] = ['part' => $part['id']] + $configuration;
            }
        }

        return [
            'payload' => $payload,
            'context' => [
                'input' => $payload,
                'beats' => $beatFacts,
                'scenes' => array_map(static fn (array $scene): array => array_intersect_key($scene, array_flip(['id', 'beats', 'start_ms', 'end_ms', 'state', 'places', 'set_brief', 'continuity_from', 'continuity_start', 'continuity_end', 'transition_in', 'work'])), $scenes),
                'states' => $facts,
                'work' => $work,
                'parts' => array_column($parts, 'name', 'id'),
                'part_records' => array_column($parts, null, 'id'),
                'configurations' => $configurations,
                'limits' => $operational,
                'palette' => array_column((array) ($design['motorcycle']['palette'] ?? []), null, 'id'),
                'places' => array_column((array) ($locations['locations'] ?? []), null, 'id'),
                'backgrounds' => array_map(static fn (array $plate): array => ['location' => $plate['location'], 'light' => $plate['light']['description'], 'shot_changes' => array_values((array) $plate['shot_changes'])], $plates),
                'characters' => (array) ($script['characters'] ?? []),
                'motorcycle' => (string) ($design['motorcycle']['name'] ?? ''),
                'loops' => array_values(array_column((array) ($script['story']['loops'] ?? []), 'motif')),
                'capabilities' => $capabilities,
                'reference_limit' => (int) config('video.moto.shots.keyframe_reference_limit'),
                'aspect_ratio' => $sceneInput['variables']['aspect_ratio'] ?? null,
                'finished_states' => $finished,
                'views' => array_map(static fn (array $view): array => array_intersect_key($view, array_flip(['id', 'azimuth_deg', 'elevation_deg', 'projection'])), $views),
                'total_ms' => (int) $scenePlan['total_ms'],
                'timing' => MotoShotsCheck::TIMING,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context  the check context build() gives
     * @return list<string> why the approved scene plan cannot carry a shot list, each naming its scene, beat or part: a scene without work data, work that does not fit the build states, the design or its continuity, detached parts that do not match where the work holds a part, a configuration of a beat the design does not have or whose part is not at the beat's build state, and a configuration whose operational limit the beat's situation does not meet
     */
    public static function precheck(array $context): array
    {
        $missing = array_column(array_filter((array) $context['scenes'], static fn (array $scene): bool => ! is_array($scene['work'] ?? null)), 'id');

        if ($missing !== []) {
            return ['bản chia scene đã duyệt chưa khai công việc trên part (work) ở '.implode(', ', $missing).' — cần bản Phase 6 có work rồi duyệt lại'];
        }

        $work = (array) $context['work'];
        $problems = $work['issues'];

        foreach ((array) $context['scenes'] as $scene) {
            if (! is_string($scene['state'] ?? null) || ! ($context['states'][$scene['state']]['physical'] ?? false)) {
                continue;
            }

            foreach (['start' => 'continuity_start', 'end' => 'continuity_end'] as $moment => $key) {
                $problems = [...$problems, ...MotoWork::detachedIssues("{$scene['id']}.{$key}", $scene[$key]['detached_parts'] ?? null, $work['scenes'][$scene['id']][$moment] ?? [])];
            }
        }

        foreach ((array) $context['beats'] as $beat) {
            $facts = is_string($beat['state']) ? ($context['states'][$beat['state']] ?? null) : null;

            foreach ((array) $beat['configurations'] as $configurationId) {
                $configuration = $context['configurations'][$configurationId] ?? null;

                if ($configuration === null) {
                    $problems[] = "{$beat['id']}: cấu hình {$configurationId} không có trong thiết kế.";

                    continue;
                }

                if (is_array($facts) && $facts['physical'] && ! in_array($configuration['part'], (array) $facts['present'], true) && ! in_array($configuration['part'], (array) $facts['in_progress'], true)) {
                    $problems[] = "{$beat['id']}: cấu hình {$configurationId} thuộc {$configuration['part']}, không có ở {$beat['state']}.";
                }

                foreach ((array) $context['limits'] as $limit) {
                    $unmet = in_array($configurationId, (array) $limit['governs'], true) ? MotoScriptCheck::unmet($limit, $beat['situation']) : [];

                    if ($unmet !== []) {
                        $problems[] = "{$beat['id']}: {$configurationId} chịu {$limit['id']} nhưng tình huống của beat chưa đạt: ".implode(', ', $unmet).'.';
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * @param  list<array<string, mixed>>  $entries  the clip models of the registry
     * @return list<array{duration_ms: int, modes: list<string>, tools: array<string, list<string>>}> every clip length a model renders for the film's frame, with the modes some model offers at that length and the models that offer each mode
     */
    public static function capabilities(array $entries, string $aspectRatio): array
    {
        $lengths = [];

        foreach ($entries as $entry) {
            $controls = (array) ($entry['controls'] ?? []);

            if (! in_array($aspectRatio, (array) ($controls['aspect_ratios'] ?? []), true)) {
                continue;
            }

            foreach ((array) ($controls['durations'] ?? []) as $seconds) {
                $ms = (int) round((float) $seconds * 1000);
                $modes = ['image_to_video', ...(($controls['last_frame'] ?? false) === true ? ['first_last'] : [])];

                foreach ($modes as $mode) {
                    $lengths[$ms][$mode][] = (string) $entry['id'];
                }
            }
        }

        ksort($lengths);

        return array_values(array_map(static fn (int $ms, array $tools): array => ['duration_ms' => $ms, 'modes' => array_keys($tools), 'tools' => $tools], array_keys($lengths), $lengths));
    }

    /**
     * @param  array<string, mixed>  $shot  a shot of the derived plan
     * @param  array<string, string>  $views  the artifact of every approved model sheet view
     * @param  array<string, string>  $plates  the artifact of every chosen background image
     * @param  list<string>  $finished  the finished build states the model sheet shows
     * @param  ?array<string, array{candidate: ?array<string, mixed>, problem: ?string}>  $bindings  the image the Reference plan binds to each design and identity need by need id, null when no plan could be built
     * @return array{readiness: string, needs: list<array<string, mixed>>} each reference need with the image that answers it now, or why none does, and whether the shot waits for a background, a reference or nothing
     */
    public static function readiness(array $shot, array $views, array $plates, array $finished, ?array $bindings = null): array
    {
        $needs = [];
        $waitingBackground = ($shot['environment']['mode'] ?? null) === 'request';
        $waitingReference = false;
        $background = $shot['environment']['background'] ?? null;

        if (is_string($background) && ! isset($plates[$background])) {
            $waitingBackground = true;
        }

        foreach (array_values((array) $shot['reference_needs']) as $index => $need) {
            $bound = $bindings[$shot['id'].'#'.($index + 1)] ?? null;

            if (in_array($need['kind'], ['design', 'identity'], true) && $bindings !== null) {
                $needs[] = $need + ['candidate' => $bound['candidate'] ?? null, 'problem' => $bound === null ? 'Reference chưa lập được cho nhu cầu này' : $bound['problem']];
                $waitingReference = $waitingReference || ($bound['candidate'] ?? null) === null;

                continue;
            }
            $candidate = null;
            $problem = null;

            if ($need['kind'] === 'environment') {
                $target = is_string($need['target']) ? $need['target'] : null;
                $candidate = $target !== null && isset($plates[$target]) ? ['source' => $target, 'artifact_id' => $plates[$target]] : null;
                $problem = $candidate === null ? ($target === null ? 'chờ nền bổ sung' : "{$target} chưa có ảnh nền đã duyệt") : null;
                $waitingBackground = $waitingBackground || $candidate === null;
            } elseif ($need['kind'] === 'continuity') {
                $candidate = ['source' => (string) $need['target'], 'artifact_id' => null];
                $problem = null;
            } elseif (! in_array($shot['state'], $finished, true)) {
                $problem = 'chưa có ảnh tham chiếu cho build state '.($shot['state'] ?? 'null').' — model sheet là xe hoàn thiện';
                $waitingReference = true;
            } else {
                $view = self::nearestView((array) $shot['camera'], array_keys($views));
                $candidate = $view === null ? null : ['source' => $view, 'artifact_id' => $views[$view]];
                $problem = $view === null ? 'model sheet chưa có ảnh đã duyệt cho góc này' : null;
                $waitingReference = $waitingReference || $view === null;
            }

            $needs[] = $need + ['candidate' => $candidate, 'problem' => $problem];
        }

        return [
            'readiness' => $waitingBackground ? 'waiting_background' : ($waitingReference ? 'waiting_reference' : 'ready'),
            'needs' => $needs,
        ];
    }

    /**
     * @param  array<string, mixed>  $camera
     * @param  list<string>  $available  the model sheet views with an approved image
     * @return ?string the model sheet view closest to the camera: the plan view from high above, otherwise the side or three-quarter view nearest in azimuth among the whole-motorcycle views; the three-quarter front view when the camera has no azimuth
     */
    public static function nearestView(array $camera, array $available): ?string
    {
        $cameras = (array) config('video.moto.model_sheet.cameras');
        $elevation = is_numeric($camera['elevation_deg'] ?? null) ? (float) $camera['elevation_deg'] : 0.0;

        if ($elevation >= self::PLAN_ELEVATION) {
            return in_array('MS6', $available, true) ? 'MS6' : null;
        }

        $azimuth = is_numeric($camera['azimuth_deg'] ?? null) ? (float) $camera['azimuth_deg'] : null;

        if ($azimuth === null) {
            return in_array('MS1', $available, true) ? 'MS1' : null;
        }

        $best = null;
        $gap = 361.0;

        foreach ($cameras as $id => $view) {
            if (! in_array($id, $available, true) || ! is_numeric($view['azimuth_deg'] ?? null)) {
                continue;
            }

            $difference = abs(fmod($azimuth - (float) $view['azimuth_deg'] + 540.0, 360.0) - 180.0);

            if ($difference < $gap) {
                [$best, $gap] = [(string) $id, $difference];
            }
        }

        return $best;
    }

    /** @return array<string, string> the artifact of every view of the approved model sheet, empty while no model sheet is approved */
    private function viewImages(string $projectId): array
    {
        try {
            $sheet = $this->sheets->approvedSheet($projectId, false);
        } catch (RuntimeException) {
            return [];
        }

        $review = VideoPlanningStage::query()->whereKey($sheet['review_stage_id'])->where('project_id', $projectId)
            ->where('stage', PlanningStageName::MODEL_SHEET_REVIEW->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();

        return collect((array) ($review?->output_json['crops'] ?? []))
            ->filter(static fn (mixed $crop): bool => is_array($crop) && is_string($crop['view_id'] ?? null) && is_string($crop['artifact_id'] ?? null))
            ->mapWithKeys(static fn (array $crop): array => [$crop['view_id'] => $crop['artifact_id']])
            ->all();
    }
}
