<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\LocationSourceService;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\ScriptSourceService;
use App\Video\Script\StructureChecked;
use App\Video\Scenes\SceneWorkflow;
use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

final class MotoScenes implements ReleaseBound, SceneWorkflow, StructureChecked
{
    use ChecksJsonStructure;

    /** @var string the timing of beats that carry no screen time: each beat counts as one step, only to keep the order of scenes */
    public const ORDINAL = 'ordinal';

    /** @var bool whether the project tells its story as events, with screen time set by the shot plan */
    private bool $eventful = false;

    /** @var ?string the law the release of the bound project follows the work of a scene plan under, MotoWork::LAW_V2; null for a release that names none, whose plans keep the answer, the check and the planner they had */
    private ?string $law = null;

    /** @var string the release of the bound project */
    private string $release = MotoPipelineRelease::LEGACY;

    /** @var bool whether the release designs seven stages with checkpoints, so each beat keeps the checkpoints it runs through */
    private bool $checkpointed = false;

    public function bind(VideoProject $project): void
    {
        $this->eventful = MotoPipelineRelease::cinematic($project);
        $this->checkpointed = $this->eventful && MotoPipelineRelease::checkpointed($project);
        $this->release = MotoPipelineRelease::of($project);
        $law = MotoPipelineRelease::policy($project, 'scenes')['work_tracking_version'] ?? null;
        $this->law = is_string($law) ? $law : null;
        $this->dossier = $this->eventful && MotoPipelineRelease::dossier($project);
        $this->setDesign = $this->dossier && MotoPipelineRelease::setDesign($project);
    }

    /** @var bool whether the release plans its places as a set design dossier (moto-locations-v4), whose objects, set states and continuity the scene step is handed */
    private bool $setDesign = false;

    /** @var bool whether the release plans its places as a location dossier (moto-locations-v3), whose zones, access routes, connections and plate conditions the scene step is handed */
    private bool $dossier = false;

    public const LOCATIONS_TOKEN = 'moto-scenes-locations-token-v1';

    /** @var list<string> */
    private const BEAT_KEYS = ['id', 'act', 'state', 'time_jump', 'duration_s', 'purpose', 'visual', 'location', 'time_light', 'reveal', 'on_screen', 'design_refs', 'in_use', 'configurations', 'situation', 'link', 'sfx'];

    public function __construct(
        private readonly ScriptSourceService $scripts,
        private readonly LocationSourceService $locations,
    ) {}

    public function format(): string
    {
        return match (true) {
            $this->law !== null => MotoContract::SCENES_FORMAT_V3,
            $this->eventful => MotoContract::SCENES_FORMAT_V2,
            default => MotoContract::SCENES_FORMAT,
        };
    }

    public function checkVersion(): string
    {
        return $this->law !== null ? MotoScenesCheck::VERSION_TRACKED : MotoScenesCheck::VERSION;
    }

    public function plannerVersion(): string
    {
        return $this->law !== null ? MotoScenePlanner::VERSION_TRACKED : MotoScenePlanner::VERSION;
    }

    public function inputsVersion(): string
    {
        return match (true) {
            $this->setDesign && $this->law !== null => MotoContract::SCENES_INPUT_V7,
            $this->dossier && $this->law !== null => MotoContract::SCENES_INPUT_V6,
            $this->checkpointed && $this->law !== null => MotoContract::SCENES_INPUT_V5,
            $this->law !== null => MotoContract::SCENES_INPUT_V4,
            $this->eventful => MotoContract::SCENES_INPUT_V3,
            default => MotoContract::SCENES_INPUT,
        };
    }

    public function settings(): array
    {
        $config = (array) config('video.moto.scenes');

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
        $script = $this->scripts->approvedSource($projectId);

        $scriptFormat = match (true) {
            $this->checkpointed => MotoContract::SCRIPT_FORMAT_V3,
            $this->eventful => MotoContract::SCRIPT_FORMAT_V2,
            default => MotoContract::SCRIPT_FORMAT,
        };
        $locationsFormat = match (true) {
            $this->setDesign => MotoContract::LOCATIONS_FORMAT_V4,
            $this->dossier => MotoContract::LOCATIONS_FORMAT_V3,
            $this->eventful => MotoContract::LOCATIONS_FORMAT_V2,
            default => MotoContract::LOCATIONS_FORMAT,
        };

        if (($script['document']['format'] ?? null) !== $scriptFormat) {
            throw new RuntimeException('Kịch bản đang chọn không theo định dạng '.$scriptFormat.' — không chia scene moto từ nó.');
        }

        $text = $this->locations->approvedText($projectId);
        $document = (array) $text['evaluation']['document'];

        if ($this->dossier && ($document['format'] ?? null) !== $locationsFormat && in_array($document['format'] ?? null, [MotoContract::LOCATIONS_FORMAT_V2, MotoContract::LOCATIONS_FORMAT_V3], true)) {
            throw new RuntimeException('Bộ bối cảnh đang dùng theo hợp đồng '.$document['format'].': các bản scene và shot đã lập từ nó vẫn dùng được, còn bản scene mới của bản phát hành này chia từ bộ hồ sơ địa điểm '.$locationsFormat.' — lập và duyệt bộ địa điểm mới rồi chia scene.');
        }

        if (($document['format'] ?? null) !== $locationsFormat) {
            throw new RuntimeException('Bộ bối cảnh đang dùng không theo định dạng '.$locationsFormat.' — không chia scene moto từ nó.');
        }

        $designSource = (array) ($this->locations->source($text['stage'])['design'] ?? []);
        $design = MotoLocations::pinnedDesign($projectId, $designSource);
        $stage = VideoPlanningStage::query()->whereKey($script['stage_id'])->where('project_id', $projectId)->firstOrFail();
        $pinned = json_decode((string) app(PlanningStageStore::class)->payloadOf($stage), true);

        if (! is_array($pinned) || ! is_array($pinned['variables'] ?? null)) {
            throw new RuntimeException('Kịch bản đã duyệt không còn payload đã ghim — không chia được scene.');
        }

        if ($this->law !== null && ! $this->eventful) {
            throw new RuntimeException("Bản phát hành {$this->release} khai luật work {$this->law} nhưng không kể chuyện bằng sự kiện — không chia scene.");
        }

        $built = self::build((array) $script['document']['data'], self::locationData($document, (array) ($text['evaluation']['derived'] ?? [])), $design, (array) $pinned['variables'], $this->eventful, $this->law, $this->dossier, $this->setDesign);

        return [
            'payload' => $built['payload'],
            'source' => [
                'script' => $script['token'],
                'locations' => $this->locationsToken($text),
                'design' => ['design_stage_id' => (string) ($designSource['design_stage_id'] ?? ''), 'design_raw_sha256' => (string) ($designSource['design_raw_sha256'] ?? '')],
            ],
            'context' => $built['context'] + ['format' => $this->format()] + ($this->law === null ? [] : [
                'release' => $this->release,
                'input_format' => $this->inputsVersion(),
                'check_version' => $this->checkVersion(),
                'planner_version' => $this->plannerVersion(),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $document  the approved Phase 5 answer
     * @param  array<string, mixed>  $derived  the plan code derived from it
     * @return array<string, mixed> the data the later steps read: the answer's own, and for a dossier (moto-locations-v3 or moto-locations-v4) its conflicts joined by the ones code found in its numbers (MotoLocationsCheck::usability), so a plate held back for its fit holds its beats back like any blocking conflict of the answer. A set design dossier is given as MotoSetDesign::normalize() reads it: every plate with the light, the support and the state of its set that code makes from its structured fields
     */
    public static function locationData(array $document, array $derived): array
    {
        $data = (array) ($document['data'] ?? []);
        $format = $document['format'] ?? null;

        if ($format !== MotoContract::LOCATIONS_FORMAT_V3 && $format !== MotoContract::LOCATIONS_FORMAT_V4) {
            return $data;
        }

        $data = $format === MotoContract::LOCATIONS_FORMAT_V4 ? MotoSetDesign::normalize($data) : $data;

        return ['conflicts' => [...array_values((array) ($data['conflicts'] ?? [])), ...array_values((array) ($derived['held'] ?? []))]] + $data;
    }

    public function sourceProblem(string $projectId, array $source): ?string
    {
        try {
            $script = $this->scripts->approvedSource($projectId);
        } catch (RuntimeException $e) {
            return 'Kịch bản: '.$e->getMessage();
        }

        if ($script['token'] != ($source['script'] ?? null)) {
            return 'Kịch bản được chọn hoặc nguồn của nó đã đổi so với lúc chia scene này.';
        }

        try {
            $text = $this->locations->approvedText($projectId);
        } catch (RuntimeException $e) {
            return 'Bộ bối cảnh: '.$e->getMessage();
        }

        if ($this->locationsToken($text) != ($source['locations'] ?? null)) {
            return 'Bộ địa điểm và bối cảnh đang dùng đã đổi so với lúc chia scene này.';
        }

        $design = (array) ($this->locations->source($text['stage'])['design'] ?? []);

        return ['design_stage_id' => (string) ($design['design_stage_id'] ?? ''), 'design_raw_sha256' => (string) ($design['design_raw_sha256'] ?? '')] == ($source['design'] ?? null)
            ? null
            : 'Bản thiết kế mà bộ bối cảnh ghim đã đổi so với lúc chia scene này.';
    }

    public function check(?array $response, array $context, array $schema): array
    {
        return MotoScenesCheck::evaluate($response, $context, $schema);
    }

    public function derive(array $document, array $context): array
    {
        return MotoScenePlanner::plan($document, $context);
    }

    public function systemText(array $parts, array $schemas): string
    {
        $heading = (string) config('video.moto.scenes.schema_heading');

        return implode("\n\n", [
            ...$parts,
            ...array_map(static fn (array $schema): string => "## {$heading} — {$schema['label']}\n\n```json\n".MotoContract::compactSchema((string) $schema['content'])."\n```", $schemas),
        ]);
    }

    /**
     * @param  array<string, mixed>  $script  the data of the approved script
     * @param  array<string, mixed>  $locations  the data of the approved background text
     * @param  array<string, mixed>  $design  the data of the design the background text pinned
     * @param  array<string, mixed>  $variables  the variables the script was written with
     * @param  bool  $eventful  whether the script tells its beats as events without screen time: the beats then carry their events, no times are sent, and each beat counts as one step of an ordinal timing
     * @param  ?string  $law  the law the work of the plan is followed under, sent as work_tracking_version and pinned in the check context; null for a plan that names none
     * @param  bool  $dossier  the locations are a location dossier (moto-locations-v3), as its pinned format says: each place is sent with its setting, function, zones and access routes, with the connections between places and the conditions of each plate
     * @param  bool  $setDesign  the locations are a set design dossier (moto-locations-v4) as MotoSetDesign::normalize() reads it: each place is sent with its landmarks and the objects of its set — id, name, kind, role, whether fixed, zone, position and whether present in the base state — and each plate with its atmosphere and the state of its set, by name with what differs from the base state; the continuity is sent as the dossier states it, with what plates of one place keep and what may differ
     * @return array{payload: array<string, mixed>, context: array<string, mixed>} the beats with their times; the places, plates and uses of every beat with the background text's continuity and conflicts; the parts present and in progress, the exceptions, configurations and lights of the build states the beats use; every part with the part it is mounted on and its first build state on the motorcycle; no full design, image prompt or model-sheet prompt
     */
    public static function build(array $script, array $locations, array $design, array $variables, bool $eventful = false, ?string $law = null, bool $dossier = false, bool $setDesign = false): array
    {
        $beatKeys = $eventful ? [...array_values(array_diff(self::BEAT_KEYS, ['duration_s'])), 'events'] : self::BEAT_KEYS;
        $layout = MotoDesignStates::of($design);

        if ($layout->checkpointed()) {
            // A beat of a v3 design keeps the checkpoints it opens and ends on, right after its stage.
            array_splice($beatKeys, (int) array_search('state', $beatKeys, true) + 1, 0, ['checkpoint_start', 'checkpoint_end']);
        }

        $facts = MotoScriptCheck::facts($design);
        $records = array_column((array) ($design['build_states'] ?? []), null, 'id');
        $plates = array_column((array) ($locations['backgrounds'] ?? []), null, 'id');
        $beats = [];
        $times = [];
        $assigned = [];
        $beatFacts = [];
        $cursor = 0;

        foreach ((array) ($script['beats'] ?? []) as $beat) {
            $id = (string) $beat['id'];
            $length = $eventful ? 1 : (int) round((float) $beat['duration_s'] * 1000);
            $uses = [];

            foreach ($plates as $plateId => $plate) {
                foreach ((array) $plate['uses'] as $use) {
                    if ($use['beat'] === $id) {
                        $uses[] = ['part' => $use['part'], 'background' => $plateId];
                    }
                }
            }

            $unplated = array_values(array_map(
                static fn (array $entry): array => array_intersect_key($entry, array_flip(['part', 'location', 'reason', 'handoff'])),
                array_filter((array) ($locations['unplated'] ?? []), static fn (array $entry): bool => $entry['beat'] === $id),
            ));
            $places = array_values(array_unique([
                ...array_map(static fn (array $use): string => (string) $plates[$use['background']]['location'], $uses),
                ...array_filter(array_column($unplated, 'location'), 'is_string'),
            ]));

            $beats[] = array_combine($beatKeys, array_map(static fn (string $key): mixed => $beat[$key] ?? null, $beatKeys));
            $times[] = ['beat' => $id, 'start_ms' => $cursor, 'end_ms' => $cursor + $length];
            $assigned[] = ['beat' => $id, 'uses' => $uses, 'unplated' => $unplated];
            $beatFacts[] = [
                'id' => $id,
                'state' => $beat['state'] ?? null,
            ] + ($layout->checkpointed() ? ['checkpoint_start' => $beat['checkpoint_start'] ?? null, 'checkpoint_end' => $beat['checkpoint_end'] ?? null] : []) + [
                'time_jump' => (string) $beat['time_jump'],
                'on_screen' => array_values((array) $beat['on_screen']),
                'start_ms' => $cursor,
                'end_ms' => $cursor + $length,
                'places' => $places,
                'backgrounds' => array_values(array_unique(array_column($uses, 'background'))),
            ] + ($eventful ? ['events' => array_values(array_column((array) ($beat['events'] ?? []), 'id'))] : []);
            $cursor += $length;
        }

        $used = $layout->checkpointed()
            // The design stages the beats stand on and every checkpoint a beat runs through.
            ? array_values(array_unique(array_merge([], ...array_map(static fn (array $beat): array => is_string($beat['state'] ?? null) && ! $layout->physicalStage($beat['state']) ? [$beat['state']] : $layout->span($beat), $beats))))
            : array_values(array_unique(array_filter(array_column($beats, 'state'), 'is_string')));
        sort($used);
        $progression = self::progression($facts['states'], array_column((array) ($design['parts'] ?? []), 'id'));
        $states = [];
        $known = [];

        foreach ($used as $id) {
            $record = (array) ($records[$id] ?? []);

            if (! isset($facts['states'][$id])) {
                $states[] = ['id' => $id, 'kind' => $record['kind'] ?? null];

                continue;
            }

            $state = [
                'id' => $id,
                'kind' => $record['kind'] ?? null,
            ] + ($layout->checkpointed() ? ['stage' => $record['stage'] ?? null, 'label' => $record['label'] ?? null] : []) + [
                'present' => $facts['states'][$id]['present'],
                'in_progress' => array_values((array) ($record['in_progress'] ?? [])),
            ] + $progression[$id] + [
                'exceptions' => array_values((array) ($record['exceptions'] ?? [])),
                'temporary' => array_values((array) ($record['temporary'] ?? [])),
                'surface' => $record['surface'] ?? null,
            ];
            $states[] = $state;
            $known[$id] = ['in_progress' => array_column($state['in_progress'], 'part')] + array_intersect_key($state, array_flip(['present', ...array_keys($progression[$id])]));
        }

        $parts = [];
        $configurations = [];
        $lights = [];
        $work = self::workDesign($design);

        foreach ((array) ($design['parts'] ?? []) as $part) {
            if (! ($part['present'] ?? false)) {
                continue;
            }

            $parts[] = ['id' => $part['id'], 'name' => $part['name'], 'kind' => $part['kind'], 'parent' => $work['parents'][$part['id']], 'first_state' => $work['first_states'][$part['id']]];

            foreach ((array) ($part['configurations'] ?? []) as $configuration) {
                $configurations[] = ['id' => $configuration['id'], 'part' => $part['id'], 'name' => $configuration['name'], 'default' => $configuration['default']];
            }

            foreach ((array) ($part['lighting'] ?? []) as $light) {
                $lights[] = ['id' => $light['id'], 'part' => $part['id']] + array_intersect_key($light, array_flip(['source', 'position', 'serves', 'installed', 'operable']));
            }
        }

        $payload = [
            'variables' => array_intersect_key($variables, array_flip(['aspect_ratio', 'video_length', 'platform'])),
            'script' => [
                'story' => ['hero_beat' => $script['story']['hero_beat'] ?? null, 'loops' => array_values((array) ($script['story']['loops'] ?? []))],
                'characters' => (array) ($script['characters'] ?? []),
                'beats' => $beats,
            ],
            'beat_times' => $times,
            'locations' => [
                'places' => array_values(array_map(static fn (array $place): array => ['id' => $place['id'], 'name' => $place['name'], 'kind' => $place['kind']] + match (true) {
                    $setDesign => array_intersect_key($place, array_flip(['setting', 'function', 'camera_access'])) + [
                        'landmarks' => MotoSetDesign::landmarks($place),
                        'zones' => array_values(array_map(static fn (array $zone): array => array_intersect_key($zone, array_flip(['id', 'name', 'kind', 'purpose', 'position'])), (array) $place['zones'])),
                        'access_routes' => array_values(array_map(static fn (array $route): array => array_intersect_key($route, array_flip(['id', 'name', 'kind', 'leads_to', 'zone', 'used_by'])), (array) $place['access_routes'])),
                        'objects' => array_values(array_map(static fn (array $object): array => array_intersect_key($object, array_flip(['id', 'name', 'kind', 'role', 'fixed', 'zone', 'position', 'present'])), (array) $place['set_design']['objects'])),
                    ],
                    $dossier => array_intersect_key($place, array_flip(['setting', 'function', 'camera_access', 'fixed_landmarks'])) + [
                        'zones' => array_values(array_map(static fn (array $zone): array => array_intersect_key($zone, array_flip(['id', 'name', 'kind', 'purpose', 'position'])), (array) $place['zones'])),
                        'access_routes' => array_values(array_map(static fn (array $route): array => array_intersect_key($route, array_flip(['id', 'name', 'kind', 'leads_to', 'zone', 'used_by'])), (array) $place['access_routes'])),
                    ],
                    $eventful => array_intersect_key($place, array_flip(['subject_zone', 'entry_routes', 'camera_access', 'fixed_landmarks'])),
                    default => [],
                }, (array) ($locations['locations'] ?? []))),
                ...($dossier ? ['connections' => array_values(array_map(static fn (array $connection): array => array_intersect_key($connection, array_flip(['id', 'from', 'to', 'relation', 'route', 'used_by'])), (array) $locations['connections']))] : []),
                'backgrounds' => array_values(array_map(static fn (array $plate): array => [
                    'id' => $plate['id'],
                    'location' => $plate['location'],
                    'support' => array_values((array) $plate['support']),
                    'ground' => $plate['ground'],
                    'light' => $plate['light']['description'],
                    'shot_changes' => array_values((array) $plate['shot_changes']),
                ] + ($dossier ? array_intersect_key($plate, array_flip(['subject_zone', 'time_of_day', 'weather'])) : [])
                    + ($setDesign ? ['atmosphere' => $plate['atmosphere'], 'set_state' => ['name' => $plate['set']['name'], 'changes' => MotoSetDesign::changeLines($plate['set'])]] : []), $plates)),
                'beats' => $assigned,
                'continuity' => array_values((array) ($locations['continuity'] ?? [])),
                'conflicts' => array_values((array) ($locations['conflicts'] ?? [])),
            ],
            'design_states' => [
                'states' => $states,
                'parts' => $parts,
                'configurations' => $configurations,
                'lights' => $lights,
            ],
        ];

        if ($eventful) {
            unset($payload['beat_times']);
        }

        if ($law !== null) {
            $payload['work_tracking_version'] = $law;
        }

        return [
            'payload' => $payload,
            'context' => ($eventful ? ['timing' => self::ORDINAL] : []) + ($law === null ? [] : ['work_tracking_version' => $law]) + [
                'input' => $payload,
                'beats' => $beatFacts,
                'states' => $known,
                'lights' => array_column($lights, null, 'id'),
                'parts' => array_column($parts, 'name', 'id'),
                'work_design' => $work,
                'characters' => (array) ($script['characters'] ?? []),
                'source_blocking' => array_values(array_filter((array) ($locations['conflicts'] ?? []), static fn (array $conflict): bool => (bool) $conflict['blocking'])),
                'total_ms' => $cursor,
            ] + $layout->summary(),
        ];
    }

    /**
     * @param  array<string, mixed>  $design  the data of the pinned design
     * @return array{first_states: array<string, ?string>, parents: array<string, ?string>, finished: list<string>} what the work check reads from the design: the first build state with each present part on the motorcycle, the part each is mounted on and the finished build states
     */
    public static function workDesign(array $design): array
    {
        $parts = array_values(array_filter((array) ($design['parts'] ?? []), static fn (array $part): bool => (bool) ($part['present'] ?? false)));
        $layout = MotoDesignStates::of($design);

        return [
            'first_states' => MotoWork::firstStates(array_values(array_column($parts, 'id')), MotoScriptCheck::facts($design)['states']),
            'parents' => array_map(static fn (array $part): ?string => is_string($part['parent'] ?? null) ? $part['parent'] : null, array_column($parts, null, 'id')),
            'finished' => array_values(array_map(static fn (array $state): string => (string) $state['id'], array_filter((array) ($design['build_states'] ?? []), static fn (array $state): bool => ($state['kind'] ?? null) === 'finished'))),
        ] + ($layout->checkpointed() ? ['order' => $layout->order()] : []);
    }

    /**
     * @param  array<string, array{present: list<string>, in_progress: list<string>}>  $states  every physical build state of the design, in order
     * @param  list<string>  $order  the part ids in the design's order
     * @return array<string, array{previously_present: list<string>, previously_in_progress: list<string>, installed_later: list<string>}> for every physical build state, the parts off the motorcycle there that an earlier state has on it, that an earlier state works on and this one no longer does (the workflow's convention for a finished part, not proof that the work is done) and that a later state installs
     */
    private static function progression(array $states, array $order): array
    {
        $ids = array_keys($states);
        $progression = [];

        foreach ($ids as $position => $id) {
            $before = array_slice($ids, 0, $position);
            $after = array_slice($ids, $position + 1);
            $absent = static fn (array $parts): array => array_values(array_filter($order, static fn (string $part): bool => in_array($part, $parts, true) && ! in_array($part, $states[$id]['present'], true)));
            $progression[$id] = [
                'previously_present' => $absent(array_merge([], ...array_map(static fn (string $state): array => $states[$state]['present'], $before))),
                'previously_in_progress' => array_values(array_diff($absent(array_merge([], ...array_map(static fn (string $state): array => $states[$state]['in_progress'], $before))), $states[$id]['in_progress'])),
                'installed_later' => $absent(array_merge([], ...array_map(static fn (string $state): array => $states[$state]['present'], $after))),
            ];
        }

        return $progression;
    }

    /**
     * @param  array{stage: VideoPlanningStage, evaluation: array<string, mixed>}  $text  LocationSourceService::approvedText()
     * @return array{version: string, stage_id: string, content_hash: string, source_fingerprint: string} the background text a scene plan stands on: which run, its content and its own sources; a plate image chosen later does not change it
     */
    private function locationsToken(array $text): array
    {
        return [
            'version' => self::LOCATIONS_TOKEN,
            'stage_id' => (string) $text['stage']->id,
            'content_hash' => (string) $text['evaluation']['content_hash'],
            'source_fingerprint' => CanonicalJson::hash($this->locations->source($text['stage'])),
        ];
    }
}
