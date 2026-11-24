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
use App\Video\Script\StructureChecked;
use App\Video\Media\VideoModelRegistry;
use App\Video\Shots\ShotWorkflow;
use RuntimeException;

final class MotoShots implements ReleaseBound, ShotWorkflow, StructureChecked
{
    use ChecksJsonStructure;

    /** @var bool whether the project plans shots of a fixed length from events, each with its action timeline and camera path */
    private bool $eventful = false;

    /** @var array<string, mixed> what the release of the bound project adds to the check of a shot plan, pinned in the context of each run made under it */
    private array $releasePolicy = [];

    /** @var bool whether the release designs seven stages with checkpoints, so each shot pins the checkpoints its first and last frame show */
    private bool $checkpointed = false;

    public function bind(VideoProject $project): void
    {
        $this->eventful = MotoPipelineRelease::cinematic($project);
        $this->checkpointed = $this->eventful && MotoPipelineRelease::checkpointed($project);
        $this->releasePolicy = MotoPipelineRelease::policy($project, 'shots');
        $law = MotoPipelineRelease::policy($project, 'scenes')['work_tracking_version'] ?? null;
        $this->law = is_string($law) ? $law : null;
        $film = $this->eventful ? MotoPipelineRelease::filmLimits($project) : null;
        $this->shotLimits = $film === null ? null : ['events' => $film['shot_events_max'], 'people' => $film['shot_people_max']];
        $this->bodyMs = $film === null ? null : $film['body_duration_ms'];
        $this->dossier = $this->eventful && MotoPipelineRelease::dossier($project);
        $this->setDesign = $this->dossier && MotoPipelineRelease::setDesign($project);
        $staging = MotoPipelineRelease::policy($project, 'scenes')['staging_version'] ?? null;
        $this->staging = is_string($staging) ? $staging : null;
    }

    /** @var ?string the staging contract the release of the bound project names for its scenes (MotoScenes::STAGING): the shot step is then handed the pace of each scene and the emotion it opens and ends on; null for a release that names none */
    private ?string $staging = null;

    /** @var bool whether the release plans its places as a set design dossier (moto-locations-v4), whose architecture, objects, plate cameras, lighting plans, set states and continuity the shot step is handed */
    private bool $setDesign = false;

    /** @var bool whether the release plans its places as a location dossier (moto-locations-v3), whose zones, access routes, connections and plate conditions the shot step is handed */
    private bool $dossier = false;

    /** @var ?int the milliseconds the main part of the film runs after the edit under the film limits of the release, pinned with each run so its check can tell when the source shots of the main part cannot reach it; null for a release that sets none */
    private ?int $bodyMs = null;

    /** @var ?array{events: int, people: int} the most different events one shot plays and the most different people it shows under the film limits of the release (MotoPipelineRelease::filmLimits), sent as film.shot_limits and pinned with each run; null for a release that sets none */
    private ?array $shotLimits = null;

    /** @var ?string the law the release of the bound project follows the work of its scene plans under; null for a release that names none */
    private ?string $law = null;

    /** @var list<string> */
    private const BEAT_KEYS = ['id', 'act', 'state', 'time_jump', 'purpose', 'visual', 'location', 'time_light', 'reveal', 'on_screen', 'design_refs', 'in_use', 'configurations', 'situation', 'link', 'sfx'];

    /** @var int the elevation in degrees from which a plan view answers better than a side angle */
    private const PLAN_ELEVATION = 60;

    /** @var array<string, ?string> for each MotoShots major.minor version, the timing a run of it pins: none before 1.2, where the model writes every screen time; MotoShotsCheck::TIMING from 1.2, where code sets whole and closing parts and the shot */
    private const TIMINGS = ['1.0' => null, '1.1' => null, '1.2' => MotoShotsCheck::TIMING, '1.3' => MotoShotsCheck::TIMING, '2.0' => MotoShotsCheck::TIMING_FIXED, '2.1' => MotoShotsCheck::TIMING_FIXED, '2.2' => MotoShotsCheck::TIMING_FIXED, '2.3' => MotoShotsCheck::TIMING_FIXED, '3.0' => MotoShotsCheck::TIMING_FIXED];

    /** @var int the most action steps the shot schema takes in one shot */
    private const STEPS_PER_SHOT = 6;

    /** @var list<string> the fields of a build state the keyframe prompt resolves a part's surface from */
    private const SURFACE_STATE_KEYS = ['id', 'kind', 'model_medium', 'surface', 'surfaces', 'in_progress', 'exceptions'];

    /** @var list<string> the fields of a stage before the physical motorcycle the shot step reads directly from the pinned design */
    private const DESIGN_STAGE_KEYS = ['setting_brief', 'model_medium', 'scale_denominator'];

    public function __construct(
        private readonly SceneSourceService $scenes,
        private readonly ScriptSourceService $scripts,
        private readonly LocationSourceService $locations,
        private readonly ModelSheetService $sheets,
        private readonly PartsDetailService $references,
    ) {}

    public function format(): string
    {
        return match (true) {
            $this->checkpointed => MotoContract::SHOTS_FORMAT_V3,
            $this->eventful => MotoContract::SHOTS_FORMAT_V2,
            default => MotoContract::SHOTS_FORMAT,
        };
    }

    /** @return string the check a new run is told by: every new run pins the continuity reference policy (inputs()) */
    public function checkVersion(): string
    {
        return MotoShotsCheck::VERSION_CONTINUITY;
    }

    /** @return string the plan a new run is told by: every new run pins the continuity reference policy (inputs()) */
    public function plannerVersion(): string
    {
        return MotoShotPlanner::VERSION_CONTINUITY;
    }

    public function inputsVersion(): string
    {
        return match (true) {
            ! $this->eventful => MotoContract::SHOTS_INPUT,
            $this->staging !== null && $this->setDesign && $this->shotLimits !== null => MotoContract::SHOTS_INPUT_V14,
            $this->setDesign && $this->shotLimits !== null => MotoContract::SHOTS_INPUT_V13,
            $this->dossier && $this->shotLimits !== null => MotoContract::SHOTS_INPUT_V12,
            $this->checkpointed && $this->shotLimits !== null => MotoContract::SHOTS_INPUT_V11,
            $this->checkpointed => MotoContract::SHOTS_INPUT_V10,
            ($this->releasePolicy['shot_editing_policy'] ?? null) === MotoShotsCheck::COVERAGE => MotoContract::SHOTS_INPUT_V9,
            $this->law !== null => MotoContract::SHOTS_INPUT_V8,
            isset($this->releasePolicy['shot_editing_policy']) => MotoContract::SHOTS_INPUT_V6,
            default => MotoContract::SHOTS_INPUT_V5,
        };
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

        $planLaw = is_string($scenes['plan']['work_tracking_version'] ?? null) ? $scenes['plan']['work_tracking_version'] : null;

        if ($planLaw !== $this->law) {
            throw new RuntimeException('Bản chia scene đã duyệt theo luật work '.($planLaw ?? 'cũ (không ghi tên)').', bản phát hành của dự án lập shot theo '.($this->law ?? 'luật cũ').' — cần bản chia scene của đúng bản phát hành này.');
        }

        $planStaging = is_string($scenes['plan']['staging_version'] ?? null) ? $scenes['plan']['staging_version'] : null;

        if ($planStaging !== $this->staging) {
            throw new RuntimeException('Bản chia scene đã duyệt '.($planStaging === null ? 'không khai nhịp và cảm xúc của scene' : "theo hợp đồng dàn dựng {$planStaging}").', bản phát hành của dự án lập shot từ bản chia scene '.($this->staging === null ? 'không có hai trường đó' : "theo hợp đồng dàn dựng {$this->staging}").' — cần bản chia scene của đúng bản phát hành này: chia lại scene (Phase 6); bản cũ vẫn xem được trong lịch sử.');
        }

        $design = MotoLocations::pinnedDesign($projectId, (array) ($source['design'] ?? []));
        $budget = isset($this->releasePolicy['shot_editing_policy']) ? (array) ($this->releasePolicy['shot_budget'] ?? []) : null;
        $placesFormat = $text['evaluation']['document']['format'] ?? null;
        $expected = match (true) {
            $this->setDesign => MotoContract::LOCATIONS_FORMAT_V4,
            $this->dossier => MotoContract::LOCATIONS_FORMAT_V3,
            default => null,
        };

        if ($expected !== (in_array($placesFormat, [MotoContract::LOCATIONS_FORMAT_V3, MotoContract::LOCATIONS_FORMAT_V4], true) ? $placesFormat : null)) {
            throw new RuntimeException($this->dossier
                ? 'Bộ bối cảnh đang dùng theo hợp đồng '.json_encode($placesFormat).': các kế hoạch shot đã lập từ nó vẫn dùng được, còn kế hoạch shot mới của bản phát hành này lập từ bộ hồ sơ địa điểm '.$expected.' — lập và duyệt bộ địa điểm mới, chia lại scene rồi lập shot.'
                : 'Bộ bối cảnh đang dùng không theo định dạng địa điểm của bản phát hành này — cần lập lại bộ địa điểm trước khi lập shot.');
        }

        $built = self::build(
            (array) $scenes['plan'],
            $sceneInput,
            (array) $script['document']['data'],
            $this->setDesign ? MotoSetDesign::normalize((array) $text['evaluation']['document']['data']) : (array) $text['evaluation']['document']['data'],
            $design,
            array_keys($this->viewImages($projectId)),
            $this->eventful
                ? self::referenceCapabilities(app(VideoModelRegistry::class)->forTask((string) config('video.moto.shots.clip_task')), (string) ($sceneInput['variables']['aspect_ratio'] ?? ''))
                : self::capabilities(app(VideoModelRegistry::class)->forTask((string) config('video.moto.shots.clip_task')), (string) ($sceneInput['variables']['aspect_ratio'] ?? '')),
            $this->eventful,
            $this->eventful ? $this->filmFps($projectId) : null,
            $budget,
            $planLaw,
            $this->dossier,
            $this->setDesign,
            $planStaging !== null,
        );
        $pinned = array_intersect_key($this->releasePolicy, array_flip(['shot_grouping_policy', 'shot_editing_policy', 'shot_budget'])) + ['continuity_reference_policy' => MotoShotsCheck::CONTINUITY_POLICY];

        if (($unread = MotoShotsCheck::continuityPolicyProblem($pinned)) !== null) {
            throw new RuntimeException('Kiểm trước khi lập shot chưa qua, không gọi AI: '.$unread);
        }

        if (($pinned['shot_editing_policy'] ?? null) === MotoShotsCheck::COVERAGE) {
            $built['payload']['film']['coverage_policy'] = MotoShotsCheck::COVERAGE;
            $built['context']['input']['film']['coverage_policy'] = MotoShotsCheck::COVERAGE;
        }

        if ($this->shotLimits !== null) {
            $built['payload']['film']['shot_limits'] = $this->shotLimits;
            $built['context']['input']['film']['shot_limits'] = $this->shotLimits;
            $pinned['shot_limits'] = $this->shotLimits;
            $pinned['body_duration_ms'] = $this->bodyMs;
        }

        $problems = self::precheck($built['context'] + $pinned);

        if ($problems !== []) {
            throw new RuntimeException('Kiểm trước khi lập shot chưa qua, không gọi AI: '.implode(' · ', $problems));
        }

        return [
            'payload' => $built['payload'],
            'source' => ['scenes' => $scenes['token']],
            'context' => $built['context'] + ['format' => $this->format()] + $pinned
                // The policy of the approved script the scenes and shots are made from, so one chain never mixes two.
                + (is_array($script['validation'] ?? null) && $script['validation']['version'] !== MotoScriptValidation::LEGACY ? [MotoScriptValidation::KEY => $script['validation']] : []),
        ];
    }

    /**
     * @return int the frames per second of the output profile the film policy of the project names, which every card time of a shot plan must fall on
     *
     * @throws RuntimeException when the film policy or its profile cannot be read: a shot plan whose card times cannot be checked against the film is not written
     */
    private function filmFps(string $projectId): int
    {
        try {
            return (int) app(\App\Services\Video\MotoFilmPlanService::class)->contract($projectId)['profile']['fps'];
        } catch (RuntimeException $e) {
            throw new RuntimeException('Chưa đọc được chính sách phim Phase 11 để biết số khung hình mỗi giây cho mốc card — không lập shot: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  int  $fps  frames per second
     * @return int the fewest whole milliseconds that are a whole number of frames
     */
    public static function frameStepMs(int $fps): int
    {
        $a = 1000;
        $b = $fps;

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return intdiv(1000, $a);
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
        $plates = $this->chosenPlates($projectId);
        $resources = [];
        $bindings = $this->references->bindings($projectId, array_values((array) ($plan['shots'] ?? [])), array_values((array) ($plan['finished_states'] ?? [])));

        foreach ((array) ($plan['shots'] ?? []) as $shot) {
            $resources[(string) $shot['id']] = self::readiness($shot, $views, $plates, (array) ($plan['finished_states'] ?? []), $bindings);
        }

        return $resources;
    }

    /**
     * @return array<string, string> the artifact of every chosen background image a shot may take, by background: the images the location step hands on (LocationSourceService::handedImages) whose artifact still belongs to the project — found with one query over all the chosen artifact ids, whatever the number of plates. A set whose plates render on their own hands every choice as before; in a set rendered under the shared reference a plate whose image no longer belongs to the image plan — a variant whose standard image changed or cannot be used — is not among them, so a shot that needs it waits for its background
     */
    public function chosenPlates(string $projectId): array
    {
        $handed = array_filter($this->locations->handedImages($projectId), static fn (mixed $chosen): bool => is_array($chosen) && is_string($chosen['artifact_id'] ?? null));
        $ids = array_values(array_unique(array_column($handed, 'artifact_id')));
        $found = $ids === [] ? [] : array_flip(VideoArtifact::query()->whereIn('id', $ids)->where('project_id', $projectId)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
        $plates = [];

        foreach ($handed as $background => $chosen) {
            if (isset($found[$chosen['artifact_id']])) {
                $plates[(string) $background] = (string) $chosen['artifact_id'];
            }
        }

        return $plates;
    }

    /**
     * @param  array<string, mixed>  $scenePlan  the derived plan of the approved scene plan
     * @param  array<string, mixed>  $sceneInput  the input the scene plan was written from
     * @param  array<string, mixed>  $script  the data of the approved script
     * @param  array<string, mixed>  $locations  the data of the approved background text
     * @param  array<string, mixed>  $design  the data of the design the scene plan pinned
     * @param  list<string>  $available  the model sheet views with an approved image
     * @param  list<array{duration_ms: int, modes: list<string>, tools: array<string, list<string>>}>  $capabilities  the clip lengths and modes code can render
     * @param  ?string  $law  the law the scene plan pinned for its work: the work is followed under it, and it is sent as work_tracking_version and pinned in the check context; null for a scene plan that names none, followed as before
     * @param  bool  $dossier  the locations are a location dossier (moto-locations-v3), as its pinned format says: each place is sent with its setting, coordinate system, zones and access routes, with the connections between places and the conditions of each plate; the check context then also pins those conditions by plate (plate_conditions), the ambient sound with them, apart from what a still image reads, for the video prompt step alone
     * @param  bool  $setDesign  the locations are a set design dossier (moto-locations-v4), given as MotoSetDesign::normalize() reads it: environment() sends its design, plate cameras, lighting plans, set states and continuity, and the conditions pinned for the video prompt step also hold the light and the set state of each plate (MotoSetDesign::conditions)
     * @param  bool  $staged  the scene plan was made under the staging contract (MotoScenes::STAGING): each scene is sent with the pace and the emotion the plan keeps for it; a plan made without it sends neither
     * @return array{payload: array<string, mixed>, context: array<string, mixed>} what a shot list needs: the scenes with their timing, continuity, transitions and work, each work item with the presentation it gives every part it moves or keeps in place before and after it; the beats with their actions, reveal, situation, environment, the ids of the limits that apply to them and cards; the places and plates; the parts with their shapes, sizes and materials; every operational limit with the configurations it governs, the same list the check reads; the build states; the model sheet views; the render capabilities
     */
    public static function build(array $scenePlan, array $sceneInput, array $script, array $locations, array $design, array $available, array $capabilities, bool $eventful = false, ?int $filmFps = null, ?array $budget = null, ?string $law = null, bool $dossier = false, bool $setDesign = false, bool $staged = false): array
    {
        $times = array_column((array) ($sceneInput['beat_times'] ?? []), null, 'beat');
        $sceneKeys = ['id', 'beats', 'start_ms', 'end_ms', 'state', 'places', 'backgrounds', 'characters', 'purpose', 'set_brief', ...($staged ? ['pace', 'emotion'] : []), 'continuity_from', 'continuity_start', 'continuity_end', 'transition_in', 'work', ...($eventful ? ['blocking', 'event_ids'] : [])];
        $assigned = array_column((array) ($sceneInput['locations']['beats'] ?? []), null, 'beat');
        $plates = array_column((array) ($locations['backgrounds'] ?? []), null, 'id');
        $sceneOf = [];
        $scenes = [];

        foreach ((array) $scenePlan['scenes'] as $scene) {
            foreach ((array) $scene['beats'] as $beat) {
                $sceneOf[$beat] = $scene['id'];
            }

            $scenes[] = array_intersect_key($scene, array_flip($sceneKeys));
        }

        $beats = [];
        $beatFacts = [];
        $operational = MotoScriptCheck::facts($design)['limits'];
        $layout = MotoDesignStates::of($design);
        $beatKeys = self::BEAT_KEYS;

        if ($layout->checkpointed()) {
            // A beat of a v3 design keeps the checkpoints it opens and ends on, right after its stage.
            array_splice($beatKeys, (int) array_search('state', $beatKeys, true) + 1, 0, ['checkpoint_start', 'checkpoint_end']);
        }

        foreach ((array) ($script['beats'] ?? []) as $beat) {
            $id = (string) $beat['id'];
            $uses = array_values((array) ($assigned[$id]['uses'] ?? []));
            $unplated = array_values((array) ($assigned[$id]['unplated'] ?? []));
            $mine = array_filter((array) ($script['text_cards'] ?? []), static fn (array $card): bool => ($card['beat'] ?? null) === $id);
            $cards = array_values(array_map(static fn (array $card): array => $eventful ? array_intersect_key($card, array_flip(['id', 'type', 'text', 'event'])) : [
                'type' => $card['type'],
                'text' => $card['text'],
                'offset_ms' => (int) round((float) $card['offset_s'] * 1000),
                'duration_ms' => (int) round((float) $card['sec'] * 1000),
            ], $mine));
            $limits = MotoLocations::limits($beat, $design);
            $row = ['id' => $id, 'scene' => $sceneOf[$id] ?? null]
                + ($eventful ? [] : ['start_ms' => (int) ($times[$id]['start_ms'] ?? 0), 'end_ms' => (int) ($times[$id]['end_ms'] ?? 0), 'duration_ms' => (int) ($times[$id]['end_ms'] ?? 0) - (int) ($times[$id]['start_ms'] ?? 0)])
                + array_combine(array_slice($beatKeys, 1), array_map(static fn (string $key): mixed => $beat[$key] ?? null, array_slice($beatKeys, 1)))
                + ($eventful ? ['events' => array_values((array) ($beat['events'] ?? []))] : [])
                + ['uses' => $uses, 'unplated' => $unplated, 'limits' => array_values(array_column($limits, 'source')), 'cards' => $cards];
            $beats[] = $row;
            $beatFacts[] = ($eventful ? ['events' => array_values(array_map(static fn (array $event): array => array_intersect_key($event, array_flip(['id', 'character_ids', 'operating_start', 'operating_end'])), (array) ($beat['events'] ?? [])))] : []) + [
                'id' => $id,
                'scene' => $row['scene'],
                'start_ms' => $row['start_ms'] ?? 0,
                'end_ms' => $row['end_ms'] ?? 0,
                'state' => $row['state'],
            ] + ($layout->checkpointed() ? ['checkpoint_start' => $row['checkpoint_start'], 'checkpoint_end' => $row['checkpoint_end']] : []) + [
                'time_jump' => (string) $row['time_jump'],
                'location' => $row['location'] ?? null,
                'reveal' => (string) $row['reveal'],
                'on_screen' => array_values((array) $row['on_screen']),
                'design_refs' => array_values((array) $row['design_refs']),
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
                $parts[] = array_intersect_key($part, array_flip(['id', 'name', 'kind', ...($budget === null ? [] : ['origin']), 'description', 'extent', 'faces', 'surfaces'])) + [
                    'configurations' => array_map(static fn (array $configuration): array => array_intersect_key($configuration, array_flip(['id', 'name', 'description'])), (array) ($part['configurations'] ?? [])),
                ];
            }
        }

        $finished = array_values(array_map(static fn (array $state): string => (string) $state['id'], array_filter((array) ($design['build_states'] ?? []), static fn (array $state): bool => ($state['kind'] ?? null) === 'finished')));
        $cameras = (array) config('video.moto.model_sheet.cameras');
        $designViews = array_column((array) ($design['model_sheet']['views'] ?? []), null, 'id');
        $views = array_map(static function (array $view) use ($cameras, $designViews, $available, $finished): array {
            $chosen = $designViews[$view['id']] ?? [];
            $camera = is_array($chosen['camera'] ?? null) ? $chosen['camera'] : (array) ($cameras[$view['id']] ?? []);
            $target = ($cameras[$view['id']]['azimuth_deg'] ?? null) === null && is_string($chosen['target'] ?? null) ? trim($chosen['target']) : '';

            return [
                'id' => $view['id'],
                'view' => $target !== '' ? $target : $view['view'],
                'azimuth_deg' => $camera['azimuth_deg'] ?? null,
                'elevation_deg' => $camera['elevation_deg'] ?? null,
                'projection' => $camera['projection'] ?? null,
                'available' => in_array($view['id'], $available, true),
                'states' => $finished,
            ];
        }, (array) config('video.moto.model_sheet.views'));
        $records = array_column((array) ($design['build_states'] ?? []), null, 'id');
        $states = array_map(static fn (array $state): array => isset($state['present'])
            ? $state
            : $state + array_intersect_key((array) ($records[$state['id']] ?? []), array_flip(self::DESIGN_STAGE_KEYS)), (array) ($sceneInput['design_states']['states'] ?? []));
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

        // The work keeps a part where it left it within a build state; in a v3 design it reads each beat at the checkpoints it runs through.
        $work = MotoWork::follow($law, (array) $scenePlan['scenes'], array_column($beatFacts, 'state', 'id'), $facts, MotoScenes::workDesign($design), $layout->workSpans(array_column($beatFacts, null, 'id')));
        $scenes = array_map(static fn (array $scene): array => array_replace($scene, ['work' => is_array($scene['work'] ?? null) ? array_map(static fn (array $item): array => $item + [
            'presentations' => array_map(static fn (array $place): array => ['start' => $place['start_status'], 'end' => $place['end_status']], $work['items'][$item['id']]['parts'] ?? []),
        ], $scene['work']) : null]), $scenes);
        $referenceLimit = $eventful ? (int) config('video.clips.workflows.'.MotoContract::WORKFLOW.'.reference_limit') : (int) config('video.moto.shots.keyframe_reference_limit');
        $payload = [
            'film' => $eventful ? [
                'shot_duration_ms' => MotoContract::SHOT_MS,
                'aspect_ratio' => $sceneInput['variables']['aspect_ratio'] ?? null,
                'reference_limit' => $referenceLimit,
            ] + ($filmFps === null ? [] : ['fps' => $filmFps, 'card_step_ms' => self::frameStepMs($filmFps)]) + ($budget === null ? [] : ['shot_budget' => $budget]) : [
                'total_ms' => (int) $scenePlan['total_ms'],
                'aspect_ratio' => $sceneInput['variables']['aspect_ratio'] ?? null,
                'keyframe_reference_limit' => $referenceLimit,
            ],
            'story' => [
                'hero_beat' => $script['story']['hero_beat'] ?? null,
                'loops' => array_values((array) ($script['story']['loops'] ?? [])),
                'obstacles' => array_values((array) ($script['story']['obstacles'] ?? [])),
            ],
            'characters' => (array) ($script['characters'] ?? []),
        ] + ($eventful ? ['shot_directives' => array_values((array) ($script['shot_directives'] ?? []))] : []) + [
            'scenes' => $scenes,
            'beats' => $beats,
            ...self::environment($locations, $eventful, $dossier, $setDesign),
            'motorcycle' => array_intersect_key((array) ($design['motorcycle'] ?? []), array_flip(['name', 'silhouette', 'palette', 'materials', ...($eventful ? ['markings'] : [])]))
                + ($eventful ? ['identity_rules' => array_values((array) ($design['identity_rules'] ?? []))] : []),
            'parts' => $parts,
            'features' => array_values(array_map(static fn (array $feature): array => array_intersect_key($feature, array_flip(['id', 'name', 'parts', 'visible_expression', 'effect', 'modes', 'use_sequence'])), (array) ($design['features'] ?? []))),
            'limits' => $operational,
            'design_states' => $states,
            'views' => $views,
            'render_capabilities' => array_map(static fn (array $capability): array => ['duration_ms' => $capability['duration_ms'], 'modes' => $capability['modes']], $capabilities),
        ];

        if ($law !== null) {
            $payload['work_tracking_version'] = $law;
            $payload['motorcycle']['signature_moments'] = array_values((array) ($design['motorcycle']['signature_moments'] ?? []));
        }

        $configurations = [];

        foreach ($parts as $part) {
            foreach ($part['configurations'] as $configuration) {
                $configurations[$configuration['id']] = ['part' => $part['id']] + $configuration;
            }
        }

        $characters = (array) ($script['characters'] ?? []);

        return [
            'payload' => $payload,
            'context' => ($law === null ? [] : ['work_tracking_version' => $law]) + ($eventful ? [
                'cards' => array_column(array_map(static fn (array $card): array => array_intersect_key($card, array_flip(['id', 'beat', 'event', 'type', 'text'])), (array) ($script['text_cards'] ?? [])), null, 'id'),
                'directives' => array_values((array) ($script['shot_directives'] ?? [])),
                'temporary' => array_map(static fn (array $state): array => array_values((array) ($state['temporary'] ?? [])), $records),
                'card_fps' => $filmFps,
            ] : []) + [
                'input' => $payload,
                'beats' => $beatFacts,
                'scenes' => array_map(static fn (array $scene): array => array_intersect_key($scene, array_flip(['id', 'beats', 'start_ms', 'end_ms', 'state', 'places', 'set_brief', 'continuity_from', 'continuity_start', 'continuity_end', 'transition_in', 'work', ...($eventful ? ['blocking'] : [])])), $scenes),
                'states' => $facts,
                'work' => $work,
                'parts' => array_column($parts, 'name', 'id'),
                'part_records' => array_column($parts, null, 'id'),
                'configurations' => $configurations,
                'limits' => $operational,
                'palette' => array_column((array) ($design['motorcycle']['palette'] ?? []), null, 'id'),
                'places' => array_column((array) ($locations['locations'] ?? []), null, 'id'),
                'backgrounds' => array_map(static fn (array $plate): array => ['location' => $plate['location'], 'light' => $plate['light']['description'], 'shot_changes' => array_values((array) $plate['shot_changes'])], $plates),
                ...($dossier ? ['plate_conditions' => $setDesign ? MotoSetDesign::conditions($locations) : self::plateConditions($locations)] : []),
                'characters' => $eventful ? array_column($characters, 'description', 'id') : $characters,
                'motorcycle' => (string) ($design['motorcycle']['name'] ?? ''),
                'loops' => array_values(array_column((array) ($script['story']['loops'] ?? []), 'motif')),
                'capabilities' => $capabilities,
                'reference_limit' => $referenceLimit,
                'aspect_ratio' => $sceneInput['variables']['aspect_ratio'] ?? null,
                'finished_states' => $finished,
                'views' => array_map(static fn (array $view): array => array_intersect_key($view, array_flip(['id', 'azimuth_deg', 'elevation_deg', 'projection'])), $views),
                'total_ms' => (int) $scenePlan['total_ms'],
                'timing' => $eventful ? MotoShotsCheck::TIMING_FIXED : MotoShotsCheck::TIMING,
                'surface_rule' => MotoReferenceSurface::RULE_MATERIAL,
                'state_records' => array_map(static fn (array $state): array => array_intersect_key($state, array_flip(self::SURFACE_STATE_KEYS)), $records),
                'ref_parts' => [
                    ...array_column((array) ($design['features'] ?? []), 'parts', 'id'),
                    ...array_column((array) ($design['systems'] ?? []), 'parts', 'id'),
                    ...array_column((array) ($design['motorcycle']['signature_moments'] ?? []), 'parts', 'id'),
                ],
                'cover_design_refs' => (bool) config('video.moto.shots.check.cover_design_refs'),
            ] + $layout->summary(),
        ];
    }

    /**
     * @param  array<string, mixed>  $locations  the data of the approved background text
     * @param  bool  $eventful  the shots are takes with a moving camera, which read the space of each place
     * @param  bool  $dossier  the locations are a location dossier (moto-locations-v3)
     * @param  bool  $setDesign  the locations are a set design dossier (moto-locations-v4), read through MotoSetDesign::normalize() here whether the caller hands the stated answer or the normalised one: a place carries the one design of its set — architecture, objects with where they stand, what marks the place — and a plate its camera, its lighting plan, what its frame shows and the state of its set by name with what differs from the base state; the continuity of the dossier follows the plates, with what plates of one place keep and what may differ
     * @return array<string, list<array<string, mixed>>> the environment a shot list is handed, in the order it is sent: the places, for a dossier the connections between them, and the plates. A dossier's place carries its setting, coordinate system, zones and access routes, and its plate the subject zone, the landmarks and the conditions of its use; the ambient sound of a plate is for the video step and is not sent here. Places and plates of an earlier answer are sent exactly as before
     */
    public static function environment(array $locations, bool $eventful, bool $dossier, bool $setDesign = false): array
    {
        $plates = array_column((array) ($locations['backgrounds'] ?? []), null, 'id');

        if ($setDesign) {
            $locations = MotoSetDesign::normalize($locations);
            $plates = array_column((array) $locations['backgrounds'], null, 'id');

            return [
                'places' => array_values(array_map(static fn (array $place): array => array_intersect_key($place, array_flip(['id', 'name', 'kind', 'space', 'dimensions', 'setting', 'coordinate_system', 'zones', 'access_routes', 'camera_access', 'set_design'])), (array) $locations['locations'])),
                'connections' => array_values(array_map(static fn (array $connection): array => array_intersect_key($connection, array_flip(['id', 'from', 'to', 'relation', 'route', 'used_by'])), (array) $locations['connections'])),
                'backgrounds' => array_values(array_map(static fn (array $plate): array => array_intersect_key($plate, array_flip(['id', 'location', 'composition', 'subject_zone', 'camera', 'visible', 'time_of_day', 'weather', 'atmosphere', 'ground', 'shot_changes'])) + [
                    'lighting_plan' => array_diff_key((array) $plate['lighting_plan'], ['evidence' => true, 'reason' => true]),
                    'support' => array_values((array) $plate['support']),
                    'set_state' => ['name' => $plate['set']['name'], 'changes' => MotoSetDesign::changeLines($plate['set'])],
                ], $plates)),
                'set_continuity' => array_values(array_map(static fn (array $entry): array => array_diff_key($entry, ['basis' => true, 'evidence' => true]), (array) $locations['continuity'])),
            ];
        }

        return [
            'places' => array_values(array_map(static fn (array $place): array => array_intersect_key($place, array_flip(['id', 'name', 'kind', 'space', 'dimensions', 'surfaces', 'fixed_equipment', ...match (true) {
                $dossier => ['setting', 'spatial_layers', 'coordinate_system', 'zones', 'access_routes', 'camera_access', 'fixed_landmarks'],
                $eventful => ['spatial_layers', 'subject_zone', 'entry_routes', 'camera_access', 'fixed_landmarks'],
                default => [],
            }])), (array) ($locations['locations'] ?? []))),
            ...($dossier ? ['connections' => array_values(array_map(static fn (array $connection): array => array_intersect_key($connection, array_flip(['id', 'from', 'to', 'relation', 'route', 'used_by'])), (array) $locations['connections']))] : []),
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
            ] + ($dossier ? array_intersect_key($plate, array_flip(['subject_zone', 'landmarks', 'time_of_day', 'weather', 'atmosphere'])) + ['light_direction' => $plate['light']['direction'], 'light_quality' => $plate['light']['quality']] : []), $plates)),
        ];
    }

    /**
     * @param  array<string, mixed>  $locations  the data of an approved location dossier
     * @return array<string, array<string, ?string>> for each plate by id, the conditions of its use the video prompt step reads — its time of day, weather, atmosphere and ambient sound — as the dossier states them; pinned with a shot run apart from what a still image is written from, so the ambient sound reaches the video prompt and never an image prompt
     */
    public static function plateConditions(array $locations): array
    {
        return array_map(
            static fn (array $plate): array => array_map(static fn (string $field): mixed => $plate[$field] ?? null, array_combine(MotoClipPrompt::CONDITION_FIELDS, MotoClipPrompt::CONDITION_FIELDS)),
            array_column((array) ($locations['backgrounds'] ?? []), null, 'id'),
        );
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

        if (($context['shot_editing_policy'] ?? null) !== null) {
            $max = $context['shot_budget']['max'] ?? null;

            if (! is_int($max) || $max < 1) {
                $problems[] = 'Bản phát hành ghim chính sách biên tập shot nhưng không khai shot_budget.max là số nguyên dương.';
            } elseif (count((array) $context['scenes']) > $max) {
                $problems[] = 'Bản chia scene có '.count((array) $context['scenes'])." scene nhưng ngân sách chỉ cho {$max} shot — mỗi scene cần ít nhất một shot; đổi ngân sách hoặc bản chia scene.";
            }

            if ($context['shot_editing_policy'] === MotoShotsCheck::COVERAGE) {
                $events = array_sum(array_map(static fn (array $beat): int => count((array) ($beat['events'] ?? [])), (array) $context['beats']));
                $limits = is_array($context['shot_limits'] ?? null) ? $context['shot_limits'] : null;
                $perShot = $limits === null ? self::STEPS_PER_SHOT : min(self::STEPS_PER_SHOT, (int) $limits['events']);
                $room = is_int($max) ? $max * $perShot : 0;

                if ($events > $room) {
                    $problems[] = $limits === null
                        ? "Kịch bản có {$events} sự kiện nhưng {$max} shot chỉ chứa được {$room} bước hành động (".self::STEPS_PER_SHOT.' bước mỗi shot) — không thể diễn đủ mọi sự kiện; đổi ngân sách hoặc kịch bản.'
                        : "Kịch bản có {$events} sự kiện nhưng {$max} shot chỉ diễn được {$room} sự kiện ({$perShot} sự kiện mỗi shot) — không thể diễn đủ mọi sự kiện; sửa kịch bản.";
                }

                foreach ($limits === null ? [] : (array) $context['beats'] as $beat) {
                    foreach ((array) ($beat['events'] ?? []) as $event) {
                        $named = count(array_unique(array_map('strval', (array) ($event['character_ids'] ?? []))));

                        if ($named > (int) $limits['people']) {
                            $problems[] = "{$event['id']} của {$beat['id']} nêu {$named} nhân vật nhưng một shot chỉ hiện tối đa {$limits['people']} người — không shot nào diễn được sự kiện này; sửa kịch bản.";
                        }
                    }
                }

                $problems = [...$problems, ...MotoShotsCheck::unlinkedWorkIssues((array) $context['scenes'])];
            }
        }

        foreach ((array) ($context['directives'] ?? []) as $directive) {
            if (count((array) ($directive['event_anchors'] ?? [])) > self::STEPS_PER_SHOT) {
                $problems[] = "Chỉ dẫn {$directive['id']} chốt ".count((array) $directive['event_anchors']).' mốc cho một shot, hợp đồng shot chỉ nhận '.self::STEPS_PER_SHOT.' bước hành động — sửa chỉ dẫn trong kịch bản trước khi lập shot.';
            }
        }

        // The configurations of a beat are those it ends in: at its build state, or in a v3 design at the checkpoint it ends on.
        $closing = MotoDesignStates::ofFacts($context)->checkpointed() ? 'checkpoint_end' : 'state';

        foreach ((array) $context['beats'] as $beat) {
            $facts = is_string($beat[$closing] ?? null) ? ($context['states'][$beat[$closing]] ?? null) : null;

            foreach ((array) $beat['configurations'] as $configurationId) {
                $configuration = $context['configurations'][$configurationId] ?? null;

                if ($configuration === null) {
                    $problems[] = "{$beat['id']}: cấu hình {$configurationId} không có trong thiết kế.";

                    continue;
                }

                if (is_array($facts) && $facts['physical'] && ! in_array($configuration['part'], (array) $facts['present'], true) && ! in_array($configuration['part'], (array) $facts['in_progress'], true)) {
                    $problems[] = "{$beat['id']}: cấu hình {$configurationId} thuộc {$configuration['part']}, không có ở {$beat['state']}.";
                }
            }
        }

        return $problems;
    }

    /**
     * @param  list<array<string, mixed>>  $entries  the clip models of the registry
     * @return list<array{duration_ms: int, modes: list<string>, tools: array<string, list<string>>}> every clip length a model renders for the film's frame, with the modes some model offers at that length and the models that offer each mode
     */
    public static function referenceCapabilities(array $entries, string $aspectRatio): array
    {
        $tools = [];

        foreach ($entries as $entry) {
            $controls = (array) ($entry['controls'] ?? []);
            $lengths = array_map(static fn (mixed $seconds): int => (int) round((float) $seconds * 1000), (array) ($controls['durations'] ?? []));

            if (in_array($aspectRatio, (array) ($controls['aspect_ratios'] ?? []), true) && in_array(MotoContract::SHOT_MS, $lengths, true) && (int) ($controls['max_reference_images'] ?? 0) > 0) {
                $tools[] = (string) $entry['id'];
            }
        }

        return $tools === [] ? [] : [['duration_ms' => MotoContract::SHOT_MS, 'modes' => [MotoContract::REFERENCE_TO_VIDEO], 'tools' => [MotoContract::REFERENCE_TO_VIDEO => $tools]]];
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

        $inFrame = array_values(array_unique(array_filter(array_column((array) ($shot['parts'] ?? []), 'part'), 'is_string')));
        $asked = array_values(array_filter((array) $shot['reference_needs'], static fn (array $need): bool => $need['kind'] === 'design'));
        $unasked = array_values(array_diff($inFrame, array_column($asked, 'target')));
        $derived = $bindings === null ? [] : array_map(static fn (string $part): array => ['kind' => 'design', 'target' => $part, 'purpose' => ''], $unasked);

        foreach ([...array_values((array) $shot['reference_needs']), ...$derived] as $index => $need) {
            $bound = $bindings[$need['kind'] === 'design' ? MotoReferencePlanner::partNeedId((string) $shot['id'], (string) $need['target']) : $shot['id'].'#'.($index + 1)] ?? null;

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
