<?php

declare(strict_types=1);

namespace App\Video\Superyacht\Locations;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\ScriptSourceService;
use App\Video\Llm\JsonAnswer;
use App\Video\Locations\LocationReferencePlanner;
use App\Video\Locations\LocationWorkflow;
use App\Video\Script\ScriptEvaluation;
use App\Video\Superyacht\Spec\ResolvedPhase;
use App\Video\YachtDesign\YachtDesignCheck;
use RuntimeException;

final class SuperyachtLocations implements LocationWorkflow
{
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

    public function inputsVersion(): string
    {
        return SuperyachtLocationsPayload::VERSION;
    }

    public function settings(): array
    {
        $config = (array) config('video.superyacht.locations');

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
        $stage = VideoPlanningStage::query()->whereKey($script['stage_id'])->where('project_id', $projectId)->firstOrFail();
        $meta = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
        $pinned = json_decode((string) app(PlanningStageStore::class)->payloadOf($stage), true);
        $design = (array) ($meta['source'] ?? []);
        $scriptContext = (array) ($meta['context'] ?? []);

        if (! is_array($pinned) || ! is_array($pinned['yacht_bible'] ?? null) || ! is_array($pinned['anchors'] ?? null)) {
            throw new RuntimeException('Kịch bản đã duyệt không còn payload thiết kế đã ghim — không tạo được địa điểm và bối cảnh.');
        }

        if (! SuperyachtLocationsPayload::spatial($pinned)) {
            throw new RuntimeException('Kịch bản đã duyệt được viết từ bản thiết kế định dạng cũ '.YachtDesignCheck::LEGACY_FORMAT.': các bộ địa điểm và bối cảnh đã có của nó vẫn giữ nguyên để xem và dùng tiếp, nhưng không tạo bộ mới từ nó. Tạo thiết kế mới ('.YachtDesignCheck::FORMAT.'), duyệt model sheet rồi viết và duyệt lại kịch bản.');
        }

        $answer = $this->designAnswer($projectId, $design);
        $built = SuperyachtLocationsPayload::build(
            (array) $script['document']['data'],
            $pinned,
            SuperyachtLocationsPayload::designOf($answer),
            $this->dimensions($answer, (array) ($pinned['yacht_bible']['specs'] ?? [])),
            self::TOUR_BEAT,
            array_values((array) ($scriptContext['source_names'] ?? [])),
        );

        return [
            'payload' => $built['payload'],
            'source' => [
                'script' => $script['token'],
                'design' => $design,
            ],
            'context' => $built['context'] + [
                'format' => $this->format(),
                'forbidden' => array_values((array) ($scriptContext['forbidden'] ?? [])),
                'source_names' => array_values((array) ($scriptContext['source_names'] ?? [])),
                'policy' => (array) config('video.superyacht.locations.check'),
            ],
        ];
    }

    public function systemText(array $parts, array $schemas): string
    {
        $heading = (string) config('video.superyacht.locations.schema_heading');

        return implode("\n\n", [
            ...$parts,
            ...array_map(static fn (array $schema): string => "## {$heading} — {$schema['label']}\n\n```json\n".trim((string) $schema['content'])."\n```", $schemas),
        ]);
    }

    public function completeContext(string $projectId, array $context, array $source): array
    {
        $context['policy'] =(array) ($context['policy'] ?? []) + (array) config('video.superyacht.locations.check');

        if (array_key_exists('innovations', (array) ($context['design'] ?? []))) {
            return $context;
        }

        $stage = VideoPlanningStage::query()->whereKey($source['script']['stage_id'] ?? null)->where('project_id', $projectId)->first()
            ?? throw new RuntimeException('Kịch bản mà bản này ghim không còn — không bổ sung được trường thiết kế.');
        $pinned = json_decode((string) app(PlanningStageStore::class)->payloadOf($stage), true);

        if (! is_array($pinned) || ! is_array($pinned['yacht_bible'] ?? null)) {
            throw new RuntimeException('Kịch bản mà bản này ghim không còn payload thiết kế — không bổ sung được trường thiết kế.');
        }

        $context['design'] = SuperyachtLocationsPayload::designContext($pinned, SuperyachtLocationsPayload::designOf($this->designAnswer($projectId, (array) ($source['design'] ?? []))));

        return $context;
    }

    public function sourceProblem(string $projectId, array $source): ?string
    {
        try {
            $current = $this->scripts->approvedSource($projectId);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return $current['token'] == ($source['script'] ?? null) ? null : 'Kịch bản được chọn hoặc nguồn của nó đã đổi so với lúc tạo bộ địa điểm và bối cảnh này.';
    }

    public function schemaViolations(object $decoded, ResolvedPhase $bundle, string $projectId): array
    {
        return ScriptEvaluation::envelopeViolations($decoded, $bundle, $projectId);
    }

    public function check(?array $response, array $context, array $schema): array
    {
        return SuperyachtLocationsCheck::evaluate($response, $context, $schema);
    }

    public function derive(array $document, array $context): array
    {
        return LocationReferencePlanner::plan($document, $context);
    }

    /**
     * @param  array<string, mixed>  $design  the design source the script pinned
     * @return array<string, mixed> the data of the pinned design answer, once it still matches the hash the script pinned
     */
    private function designAnswer(string $projectId, array $design): array
    {
        $stage = VideoPlanningStage::query()->whereKey($design['design_stage_id'] ?? null)->where('project_id', $projectId)
            ->where('stage', PlanningStageName::YACHT_DESIGN->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();

        if ($stage === null || ! hash_equals((string) ($design['design_raw_sha256'] ?? ''), hash('sha256', (string) $stage->raw_response))) {
            throw new RuntimeException('Bản thiết kế mà kịch bản đã ghim không còn hoặc không còn nguyên vẹn.');
        }

        return (array) (JsonAnswer::object((string) $stage->raw_response)['data'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>  $specs
     * @return array{loa_m: ?float, beam_m: ?float, height_m: ?float}
     */
    private function dimensions(array $answer, array $specs): array
    {
        $canonical = (array) ($answer['canonical']['dimensions'] ?? []);
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
