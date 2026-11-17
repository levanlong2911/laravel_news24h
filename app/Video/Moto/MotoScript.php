<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\ModelSheetService;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Llm\JsonAnswer;
use App\Video\Script\ProjectsScriptDocument;
use App\Video\Script\ScriptWorkflow;
use App\Video\Superyacht\Spec\ResolvedPhase;
use RuntimeException;

final class MotoScript implements ProjectsScriptDocument, ReleaseBound, ScriptWorkflow
{
    public const PROJECTION_VERSION = 'moto-script-timeline-v1';

    public const EVENT_PROJECTION_VERSION = 'moto-script-events-v1';

    public const SHOT_DURATION_MS = 3000;

    /** @var bool whether the project tells its story as events, with screen time set by the shot plan */
    private bool $eventful = false;

    /** @var array<string, mixed> what the release of the project adds to the check policy of a new run; a run keeps the policy it was made with in its context */
    private array $releasePolicy = [];

    /** @var list<string> */
    private const MOTORCYCLE_COORDINATES = ['coordinate_system', 'profile_curve'];

    /** @var list<string> */
    private const PART_COORDINATES = ['extent', 'points'];

    /** @var list<string> */
    private const CONNECTION_COORDINATES = ['point'];

    /** @var list<string> */
    private const RIDER_COORDINATES = ['seat_point', 'grip_points', 'peg_points'];

    public function __construct(private readonly ModelSheetService $sheets) {}

    public function bind(VideoProject $project): void
    {
        $this->eventful = MotoPipelineRelease::cinematic($project);
        $this->releasePolicy = MotoPipelineRelease::policy($project, 'script');
    }

    public function format(): string
    {
        return $this->eventful ? MotoContract::SCRIPT_FORMAT_V2 : MotoContract::SCRIPT_FORMAT;
    }

    public function checkVersion(): string
    {
        return MotoScriptCheck::VERSION;
    }

    public function projectionVersion(): string
    {
        return $this->eventful ? self::EVENT_PROJECTION_VERSION : self::PROJECTION_VERSION;
    }

    public function settings(): array
    {
        $config = (array) config('video.moto.script');

        if ($this->eventful) {
            $config['brief_options'] = array_replace((array) $config['brief_options'], (array) $config['eventful_brief_options']);
        }

        return [
            'execution' => (string) $config['execution'],
            'model' => (string) $config['model'],
            'effort' => (string) $config['effort'],
            'max_tokens' => (int) $config['max_tokens'],
            'timeout_seconds' => (int) $config['timeout_seconds'],
            'brief' => (array) $config['brief'],
            'brief_options' => (array) $config['brief_options'],
            'history_enabled' => (bool) $config['history']['enabled'],
        ];
    }

    public function inputs(VideoProject $project, array $brief, array $history, string $prompt): array
    {
        $projectId = (string) $project->id;
        $sheet = $this->sheets->approvedSheet($projectId, true);
        [$design, $data, $rawSha256] = $this->pinnedDesign($projectId, $sheet);
        [, $designHash] = $this->sheets->approvedSource($projectId);

        $designFormat = $this->eventful ? MotoContract::DESIGN_FORMAT_V2 : MotoContract::DESIGN_FORMAT;

        if ((JsonAnswer::object((string) $design->raw_response)['format'] ?? null) !== $designFormat) {
            throw new RuntimeException('Bản thiết kế đã duyệt không theo định dạng '.$designFormat.' — không viết kịch bản moto từ nó.');
        }

        $dnaStage = $this->stage($projectId, $design->input_json['dna_stage_id'] ?? null, PlanningStageName::DESIGN_DNA, 'Design DNA của bản thiết kế');
        $phaseOne = $this->stage($projectId, $dnaStage->input_json['inspiration_stage_id'] ?? null, PlanningStageName::INSPIRATION, 'Phase 1 của Design DNA');
        $identity = PhaseOneEvidence::inspiration(JsonAnswer::object((string) ($phaseOne->output_json['raw_text'] ?? '')))['source_identity'] ?? null;

        if (! is_array($identity) || array_is_list($identity)) {
            throw new RuntimeException('Không đọc được source_identity trong chuỗi nguồn của bản thiết kế.');
        }

        $selected = self::select($data);
        $facts = MotoScriptCheck::facts($selected);
        $config = (array) config('video.moto.script');
        $baseCard = ! $this->eventful && (bool) $config['base_card'];

        return [
            'payload' => [
                'variables' => ($this->eventful
                    ? ['timing_policy' => 'per_shot', 'shot_duration_ms' => self::SHOT_DURATION_MS, 'target_film_duration_ms' => null]
                    : ['video_length' => $brief['video_length']]) + [
                        'aspect_ratio' => $brief['aspect_ratio'],
                        'platform' => $brief['platform'],
                        'tone_notes' => $brief['tone_notes'],
                        'base_card' => $baseCard,
                    ],
                'design' => $selected,
                'film_facts' => MotoScriptCheck::filmFacts($facts),
                'channel_history' => $history,
            ],
            'source' => [
                'design_stage_id' => (string) $design->id,
                'design_raw_sha256' => $rawSha256,
                'design_hash' => (string) $designHash,
            ],
            'context' => [
                'format' => $this->format(),
                'video_length' => $this->eventful ? null : $brief['video_length'],
                'base_card' => $baseCard,
                'design' => $facts,
                'source_names' => self::sourceNames($identity),
                'policy' => array_replace((array) $config['check'], $this->releasePolicy),
            ] + ($this->eventful ? [MotoScriptValidation::KEY => MotoScriptValidation::current()] : []),
        ];
    }

    public function sourceProblem(string $projectId, array $source): ?string
    {
        try {
            $sheet = $this->sheets->approvedSheet($projectId, true);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        if ($sheet['design_stage_id'] !== ($source['design_stage_id'] ?? null)) {
            return 'Bản thiết kế nguồn đã đổi so với lúc viết kịch bản này.';
        }

        try {
            [, , $rawSha256] = $this->pinnedDesign($projectId, $sheet);
            [, $designHash] = $this->sheets->approvedSource($projectId);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        if (! hash_equals((string) ($source['design_raw_sha256'] ?? ''), $rawSha256) || $designHash !== ($source['design_hash'] ?? null)) {
            return 'Bản thiết kế nguồn đã ghim không còn nguyên vẹn.';
        }

        return null;
    }

    public function schemaViolations(object $decoded, ResolvedPhase $bundle, string $projectId): array
    {
        return array_map(
            static fn (string $error): array => ['code' => 'SCHEMA', 'path' => '', 'message' => $error],
            PhaseOneEvidence::schemaErrors($decoded, MotoContract::technicalSchemas($bundle->outputSchemas())),
        );
    }

    public function check(?array $response, array $context, array $schema): array
    {
        return MotoScriptCheck::evaluate($response, $context, $schema, (array) $context['policy']) + ['technical_schema' => MotoContract::TECHNICAL_SCHEMA_VERSION];
    }

    public function derive(array $document, array $context): array
    {
        if (($context['format'] ?? null) === MotoContract::SCRIPT_FORMAT_V2) {
            $derived = [
                'version' => self::EVENT_PROJECTION_VERSION,
                'duration_s' => null,
                'hero_beat' => (string) ($document['data']['story']['hero_beat'] ?? ''),
                'beats' => array_map(static fn (array $beat): array => ['id' => (string) $beat['id'], 'act' => (string) $beat['act'], 'events' => array_column((array) $beat['events'], 'id')], (array) ($document['data']['beats'] ?? [])),
            ];

            // Only a run that pinned a projecting policy gets one: the derived data of an older run stays as it was stored.
            if (MotoScriptValidation::projectsReferences($context)) {
                $derived['references'] = [
                    'version' => MotoScriptReferences::VERSION,
                    'beats' => MotoScriptReferences::project(array_values((array) ($document['data']['beats'] ?? [])), (array) ($context['design'] ?? []))['references'],
                ];
            }

            return $derived;
        }

        $start = 0.0;
        $beats = [];

        foreach ((array) ($document['data']['beats'] ?? []) as $beat) {
            $end = $start + (float) $beat['duration_s'];
            $beats[] = ['id' => (string) $beat['id'], 'act' => (string) $beat['act'], 'start_s' => round($start, 3), 'end_s' => round($end, 3)];
            $start = $end;
        }

        return [
            'version' => self::PROJECTION_VERSION,
            'duration_s' => round($start, 3),
            'hero_beat' => (string) ($document['data']['story']['hero_beat'] ?? ''),
            'beats' => $beats,
        ];
    }

    public function consumerDocument(array $document, array $derived): array
    {
        $references = $derived['references'] ?? null;

        if (! is_array($references) || ($references['version'] ?? null) !== MotoScriptReferences::VERSION || ! is_array($references['beats'] ?? null)) {
            return $document;
        }

        return MotoScriptReferences::apply($document, $references['beats']);
    }

    public function validation(array $context): array
    {
        return MotoScriptValidation::of($context);
    }

    public function validationInput(array $context): array
    {
        return MotoScriptValidation::input($context);
    }

    public function systemText(array $parts, array $schemas): string
    {
        $heading = (string) config('video.moto.script.schema_heading');

        return implode("\n\n", [
            ...$parts,
            ...array_map(static fn (array $schema): string => "## {$heading} — {$schema['label']}\n\n```json\n".MotoContract::compactSchema((string) $schema['content'])."\n```", $schemas),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  the data block of the approved design
     * @return array<string, mixed> the whole design for the film, without its model sheet and coordinates
     */
    public static function select(array $data): array
    {
        $without = static fn (array $record, array $keys): array => array_diff_key($record, array_flip($keys));

        return array_replace($without($data, ['model_sheet']), [
            'motorcycle' => $without((array) ($data['motorcycle'] ?? []), self::MOTORCYCLE_COORDINATES),
            'parts' => array_map(static fn (array $part): array => $without($part, self::PART_COORDINATES), (array) ($data['parts'] ?? [])),
            'connections' => array_map(static fn (array $connection): array => $without($connection, self::CONNECTION_COORDINATES), (array) ($data['connections'] ?? [])),
            'rider_interface' => $without((array) ($data['rider_interface'] ?? []), self::RIDER_COORDINATES),
        ]);
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return list<string> the names of the source motorcycle, its builder and its designers, as Phase 1 recorded them
     */
    private static function sourceNames(array $identity): array
    {
        $names = [];

        foreach (['name', 'builder', 'designers'] as $field) {
            foreach ((array) (is_array($identity[$field] ?? null) ? ($identity[$field]['value'] ?? null) : ($identity[$field] ?? null)) as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $names[] = trim($value);
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array{set_id: string, design_stage_id: string}  $sheet
     * @return array{0: VideoPlanningStage, 1: array<string, mixed>, 2: string}
     */
    private function pinnedDesign(string $projectId, array $sheet): array
    {
        $design = $this->stage($projectId, $sheet['design_stage_id'], PlanningStageName::YACHT_DESIGN, 'bản thiết kế mà model sheet đã duyệt tham chiếu');
        $set = $this->stage($projectId, $sheet['set_id'], PlanningStageName::MODEL_SHEET, 'bộ model sheet đã duyệt');
        $rawSha256 = hash('sha256', (string) $design->raw_response);

        if (! hash_equals((string) ($set->input_json['design_raw_sha256'] ?? ''), $rawSha256)) {
            throw new RuntimeException('Phản hồi gốc của bản thiết kế không khớp sha256 mà bộ model sheet đã duyệt ghim — nguồn đã duyệt bị hỏng.');
        }

        $data = JsonAnswer::object((string) $design->raw_response)['data'] ?? null;

        if (! is_array($data) || ! is_array($data['motorcycle'] ?? null) || ! is_array($data['parts'] ?? null)) {
            throw new RuntimeException('Phản hồi gốc của bản thiết kế không có data.motorcycle và data.parts.');
        }

        return [$design, $data, $rawSha256];
    }

    private function stage(string $projectId, mixed $stageId, PlanningStageName $name, string $label): VideoPlanningStage
    {
        $stage = is_string($stageId) && $stageId !== '' ? VideoPlanningStage::query()->whereKey($stageId)->where('project_id', $projectId)
            ->where('stage', $name->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first() : null;

        return $stage ?? throw new RuntimeException("Không tìm thấy {$label} đã chạy thành công cho dự án này.");
    }
}
