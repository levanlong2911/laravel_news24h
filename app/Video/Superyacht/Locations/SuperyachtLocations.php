<?php

declare(strict_types=1);

namespace App\Video\Superyacht\Locations;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\OpenAiImageClient;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\ScriptSourceService;
use App\Video\Llm\JsonAnswer;
use App\Video\Locations\LocationReferencePlanner;
use App\Video\Locations\LocationWorkflow;
use RuntimeException;

final class SuperyachtLocations implements LocationWorkflow
{
    private const BIBLE_KEYS = ['name', 'specs', 'decks', 'rooms', 'exterior_zones'];

    private const BEAT_KEYS = ['id', 'bs', 'location', 'time_light', 'rooms', 'visual', 'action', 'camera_family'];

    private const HEIGHT_KEYS = ['air_draft', 'height', 'crest_height'];

    private const TOUR_BEAT = 'B14';

    public function __construct(private readonly ScriptSourceService $scripts) {}

    public function format(): string
    {
        return SuperyachtLocationsCheck::FORMAT;
    }

    public function checkVersion(): string
    {
        return SuperyachtLocationsCheck::VERSION;
    }

    public function plannerVersion(): string
    {
        return LocationReferencePlanner::VERSION;
    }

    public function settings(): array
    {
        $config = (array) config('video.superyacht.locations');

        return [
            'execution' => (string) $config['execution'],
            'model' => (string) $config['model'],
            'effort' => (string) $config['effort'],
            'max_tokens' => (int) $config['max_tokens'],
            'timeout_seconds' => (int) $config['timeout_seconds'],
            'reference' => (array) $config['reference'],
        ];
    }

    public function inputs(VideoProject $project, string $prompt): array
    {
        $projectId = (string) $project->id;
        $script = $this->scripts->approvedSource($projectId);
        $stage = VideoPlanningStage::query()->whereKey($script['stage_id'])->where('project_id', $projectId)->firstOrFail();
        $meta = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $pinned = json_decode((string) ($meta['payload'] ?? ''), true);
        $design = (array) ($meta['source'] ?? []);
        $scriptContext = (array) ($meta['context'] ?? []);

        if (! is_array($pinned) || ! is_array($pinned['yacht_bible'] ?? null) || ! is_array($pinned['anchors'] ?? null)) {
            throw new RuntimeException('Kịch bản đã duyệt không còn payload thiết kế đã ghim — không lập được bối cảnh.');
        }

        $bible = [];

        foreach (self::BIBLE_KEYS as $key) {
            $bible[$key] = $pinned['yacht_bible'][$key] ?? throw new RuntimeException("yacht_bible đã ghim của kịch bản thiếu {$key}.");
        }

        $data = (array) $script['document']['data'];
        $beats = array_map(static fn (array $beat): array => array_intersect_key($beat, array_flip(self::BEAT_KEYS)), (array) $data['script']['beats']);
        $stops = array_values((array) $data['script']['tour']['stops']);
        $dimensions = $this->dimensions($projectId, $design, (array) $bible['specs']);

        return [
            'payload' => [
                'script' => ['beats' => $beats, 'tour' => ['stops' => $stops]],
                'yacht_bible' => $bible,
                'dimensions' => $dimensions,
                'anchors' => [
                    'interior' => $pinned['anchors']['interior'] ?? [],
                    'exterior' => $pinned['anchors']['exterior'] ?? [],
                    'surface_states' => $pinned['anchors']['surface_states'] ?? [],
                ],
                'build_states' => $pinned['build_states'] ?? [],
                'canonical' => $pinned['canonical'] ?? [],
                'avoid_names' => array_values((array) ($scriptContext['source_names'] ?? [])),
            ],
            'source' => [
                'script' => $script['token'],
                'design' => $design,
            ],
            'context' => [
                'format' => $this->format(),
                'rooms' => array_values(array_map(static fn (array $room): string => (string) $room['id'], (array) $bible['rooms'])),
                'exterior_zones' => array_values(array_filter(array_map(static fn (mixed $zone): ?string => is_array($zone) && is_string($zone['id'] ?? null) ? $zone['id'] : null, (array) $bible['exterior_zones']))),
                'beats' => array_map(static fn (array $beat): array => [
                    'id' => (string) $beat['id'],
                    'rooms' => array_map(static fn (array $room): array => ['id' => (string) $room['id'], 'state' => (string) $room['set_state']], (array) ($beat['rooms'] ?? [])),
                ], $beats),
                'tour' => array_map(static fn (array $stop, int $index): array => ['index' => $index, 'source_id' => (string) $stop['source_id'], 'match_bare_beat' => $stop['match_bare_beat'] ?? null], $stops, array_keys($stops)),
                'tour_beat' => self::TOUR_BEAT,
                'yacht' => $dimensions,
                'forbidden' => array_values((array) ($scriptContext['forbidden'] ?? [])),
                'source_names' => array_values((array) ($scriptContext['source_names'] ?? [])),
                'max_sources' => OpenAiImageClient::MAX_SOURCE_IMAGES,
                'policy' => (array) config('video.superyacht.locations.check'),
            ],
        ];
    }

    public function sourceProblem(string $projectId, array $source): ?string
    {
        try {
            $current = $this->scripts->approvedSource($projectId);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return $current['token'] == ($source['script'] ?? null) ? null : 'Kịch bản được chọn hoặc nguồn của nó đã đổi so với lúc lập bối cảnh này.';
    }

    public function check(?array $response, array $context, array $schema): array
    {
        return SuperyachtLocationsCheck::evaluate($response, $context, $schema);
    }

    public function derive(array $document, array $context): array
    {
        return LocationReferencePlanner::plan($document);
    }

    /**
     * @param  array<string, mixed>  $design  the design source the script pinned
     * @param  array<string, mixed>  $specs
     * @return array{loa_m: ?float, beam_m: ?float, height_m: ?float}
     */
    private function dimensions(string $projectId, array $design, array $specs): array
    {
        $stage = VideoPlanningStage::query()->whereKey($design['design_stage_id'] ?? null)->where('project_id', $projectId)
            ->where('stage', PlanningStageName::YACHT_DESIGN->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();

        if ($stage === null || ! hash_equals((string) ($design['design_raw_sha256'] ?? ''), hash('sha256', (string) $stage->raw_response))) {
            throw new RuntimeException('Bản thiết kế mà kịch bản đã ghim không còn hoặc không còn nguyên vẹn.');
        }

        $canonical = (array) (JsonAnswer::object((string) $stage->raw_response)['data']['canonical']['dimensions'] ?? []);
        $value = static fn (mixed $entry): ?float => is_array($entry) && is_numeric($entry['value'] ?? null) ? (float) $entry['value'] : null;
        $height = null;

        foreach (self::HEIGHT_KEYS as $key) {
            $height ??= $value($canonical[$key] ?? null);
        }

        return [
            'loa_m' => $value($canonical['loa'] ?? null) ?? $value($specs['loa'] ?? null),
            'beam_m' => $value($canonical['beam'] ?? null) ?? $value($specs['beam'] ?? null),
            'height_m' => $height,
        ];
    }
}
