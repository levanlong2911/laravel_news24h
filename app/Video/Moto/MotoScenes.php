<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\LocationSourceService;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\ScriptSourceService;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Scenes\SceneWorkflow;
use App\Video\Superyacht\Spec\ResolvedPhase;
use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

final class MotoScenes implements SceneWorkflow
{
    public const LOCATIONS_TOKEN = 'moto-scenes-locations-token-v1';

    /** @var list<string> */
    private const BEAT_KEYS = ['id', 'act', 'state', 'time_jump', 'duration_s', 'purpose', 'visual', 'location', 'time_light', 'reveal', 'on_screen', 'design_refs', 'in_use', 'configurations', 'situation', 'link', 'sfx'];

    public function __construct(
        private readonly ScriptSourceService $scripts,
        private readonly LocationSourceService $locations,
    ) {}

    public function format(): string
    {
        return MotoContract::SCENES_FORMAT;
    }

    public function checkVersion(): string
    {
        return MotoScenesCheck::VERSION;
    }

    public function plannerVersion(): string
    {
        return MotoScenePlanner::VERSION;
    }

    public function inputsVersion(): string
    {
        return MotoContract::SCENES_INPUT;
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

        if (($script['document']['format'] ?? null) !== MotoContract::SCRIPT_FORMAT) {
            throw new RuntimeException('Kịch bản đang chọn không theo định dạng '.MotoContract::SCRIPT_FORMAT.' — không chia scene moto từ nó.');
        }

        $text = $this->locations->approvedText($projectId);
        $document = (array) $text['evaluation']['document'];

        if (($document['format'] ?? null) !== MotoContract::LOCATIONS_FORMAT) {
            throw new RuntimeException('Bộ bối cảnh đang dùng không theo định dạng '.MotoContract::LOCATIONS_FORMAT.' — không chia scene moto từ nó.');
        }

        $designSource = (array) ($this->locations->source($text['stage'])['design'] ?? []);
        $design = MotoLocations::pinnedDesign($projectId, $designSource);
        $stage = VideoPlanningStage::query()->whereKey($script['stage_id'])->where('project_id', $projectId)->firstOrFail();
        $pinned = json_decode((string) (PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['payload'] ?? ''), true);

        if (! is_array($pinned) || ! is_array($pinned['variables'] ?? null)) {
            throw new RuntimeException('Kịch bản đã duyệt không còn payload đã ghim — không chia được scene.');
        }

        $built = self::build((array) $script['document']['data'], (array) $document['data'], $design, (array) $pinned['variables']);

        return [
            'payload' => $built['payload'],
            'source' => [
                'script' => $script['token'],
                'locations' => $this->locationsToken($text),
                'design' => ['design_stage_id' => (string) ($designSource['design_stage_id'] ?? ''), 'design_raw_sha256' => (string) ($designSource['design_raw_sha256'] ?? '')],
            ],
            'context' => $built['context'] + ['format' => $this->format()],
        ];
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

    public function schemaViolations(object $decoded, ResolvedPhase $bundle, string $projectId): array
    {
        return array_map(
            static fn (string $error): array => ['code' => 'SCHEMA', 'path' => '', 'message' => $error],
            PhaseOneEvidence::schemaErrors($decoded, $bundle->outputSchemas()),
        );
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
     * @return array{payload: array<string, mixed>, context: array<string, mixed>} the beats with their times; the places, plates and uses of every beat with the background text's continuity and conflicts; the parts present and in progress, the exceptions, configurations and lights of the build states the beats use; every part with the part it is mounted on and its first build state on the motorcycle; no full design, image prompt or model-sheet prompt
     */
    public static function build(array $script, array $locations, array $design, array $variables): array
    {
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
            $length = (int) round((float) $beat['duration_s'] * 1000);
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

            $beats[] = array_combine(self::BEAT_KEYS, array_map(static fn (string $key): mixed => $beat[$key] ?? null, self::BEAT_KEYS));
            $times[] = ['beat' => $id, 'start_ms' => $cursor, 'end_ms' => $cursor + $length];
            $assigned[] = ['beat' => $id, 'uses' => $uses, 'unplated' => $unplated];
            $beatFacts[] = [
                'id' => $id,
                'state' => $beat['state'] ?? null,
                'time_jump' => (string) $beat['time_jump'],
                'on_screen' => array_values((array) $beat['on_screen']),
                'start_ms' => $cursor,
                'end_ms' => $cursor + $length,
                'places' => $places,
                'backgrounds' => array_values(array_unique(array_column($uses, 'background'))),
            ];
            $cursor += $length;
        }

        $used = array_values(array_unique(array_filter(array_column($beats, 'state'), 'is_string')));
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
                'places' => array_values(array_map(static fn (array $place): array => ['id' => $place['id'], 'name' => $place['name'], 'kind' => $place['kind']], (array) ($locations['locations'] ?? []))),
                'backgrounds' => array_values(array_map(static fn (array $plate): array => [
                    'id' => $plate['id'],
                    'location' => $plate['location'],
                    'support' => array_values((array) $plate['support']),
                    'ground' => $plate['ground'],
                    'light' => $plate['light']['description'],
                    'shot_changes' => array_values((array) $plate['shot_changes']),
                ], $plates)),
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

        return [
            'payload' => $payload,
            'context' => [
                'input' => $payload,
                'beats' => $beatFacts,
                'states' => $known,
                'lights' => array_column($lights, null, 'id'),
                'parts' => array_column($parts, 'name', 'id'),
                'work_design' => $work,
                'characters' => (array) ($script['characters'] ?? []),
                'source_blocking' => array_values(array_filter((array) ($locations['conflicts'] ?? []), static fn (array $conflict): bool => (bool) $conflict['blocking'])),
                'total_ms' => $cursor,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $design  the data of the pinned design
     * @return array{first_states: array<string, ?string>, parents: array<string, ?string>, finished: list<string>} what the work check reads from the design: the first build state with each present part on the motorcycle, the part each is mounted on and the finished build states
     */
    public static function workDesign(array $design): array
    {
        $parts = array_values(array_filter((array) ($design['parts'] ?? []), static fn (array $part): bool => (bool) ($part['present'] ?? false)));

        return [
            'first_states' => MotoWork::firstStates(array_values(array_column($parts, 'id')), MotoScriptCheck::facts($design)['states']),
            'parents' => array_map(static fn (array $part): ?string => is_string($part['parent'] ?? null) ? $part['parent'] : null, array_column($parts, null, 'id')),
            'finished' => array_values(array_map(static fn (array $state): string => (string) $state['id'], array_filter((array) ($design['build_states'] ?? []), static fn (array $state): bool => ($state['kind'] ?? null) === 'finished'))),
        ];
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
