<?php

declare(strict_types=1);

namespace App\Video\Superyacht\Script;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\ModelSheetService;
use App\Video\DesignDna\DnaCheck;
use App\Video\Llm\JsonAnswer;
use App\Video\Script\ScriptWorkflow;
use RuntimeException;

final class SuperyachtScript implements ScriptWorkflow
{
    private const BIBLE_KEYS = ['name', 'specs', 'decks', 'colors', 'innovations', 'exterior_zones', 'rooms'];

    private const BIBLE_LISTS = ['decks', 'colors', 'innovations', 'exterior_zones', 'rooms'];

    private const ANCHOR_TEXTS = ['yacht', 'geometry', 'finished_surface'];

    private const ANCHOR_MAPS = ['surface_states', 'exterior', 'interior'];

    public function __construct(private readonly ModelSheetService $sheets) {}

    public function format(): string
    {
        return SuperyachtScriptCheck::FORMAT;
    }

    public function checkVersion(): string
    {
        return SuperyachtScriptCheck::VERSION;
    }

    public function projectionVersion(): string
    {
        return ScriptProjection::VERSION;
    }

    public function settings(): array
    {
        $config = (array) config('video.superyacht.script');

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
        [, $canonicalHash] = $this->sheets->approvedSource($projectId);

        $dnaStage = $this->stage($projectId, $design->input_json['dna_stage_id'] ?? null, PlanningStageName::DESIGN_DNA, 'Design DNA của bản thiết kế');
        $dna = JsonAnswer::object((string) ($dnaStage->output_json['raw_text'] ?? ''))['dna'] ?? null;
        $phaseOne = $this->stage($projectId, $dnaStage->input_json['inspiration_stage_id'] ?? null, PlanningStageName::INSPIRATION, 'Phase 1 của Design DNA');
        $identity = JsonAnswer::object((string) ($phaseOne->output_json['raw_text'] ?? ''))['inspiration']['source_identity'] ?? null;

        if (! is_array($dna) || array_is_list($dna) || ! is_array($identity) || array_is_list($identity)) {
            throw new RuntimeException('Không đọc được dna hoặc source_identity trong chuỗi nguồn của bản thiết kế.');
        }

        $bible = $this->bible($data['yacht_bible'] ?? null);
        $anchors = $this->anchors($data['anchors'] ?? null);
        $states = [];

        foreach ((array) config('video.superyacht.states') as $state) {
            $sentence = $data['build_states'][$state] ?? null;
            $states[$state] = is_string($sentence) && trim($sentence) !== '' ? $sentence : throw new RuntimeException("Bản thiết kế nguồn thiếu câu tả build_states.{$state}.");
        }

        $canonical = [];

        foreach (['build_states', 'rooms', 'operational_limits'] as $key) {
            $canonical[$key] = is_array($data['canonical'][$key] ?? null) && array_is_list($data['canonical'][$key])
                ? $data['canonical'][$key]
                : throw new RuntimeException("Canonical của bản thiết kế nguồn thiếu danh sách {$key}.");
        }

        $source = [
            'design_stage_id' => (string) $design->id,
            'design_raw_sha256' => $rawSha256,
            'canonical_hash' => (string) $canonicalHash,
            'model_sheet' => [
                'set_id' => $sheet['set_id'],
                'review_stage_id' => $sheet['review_stage_id'],
                'master_image_id' => $sheet['master_image_id'],
                'master_artifact_id' => $sheet['master_artifact_id'],
                'master_artifact_sha256' => $sheet['master_artifact_sha256'],
            ],
            'dna_stage_id' => (string) $dnaStage->id,
            'inspiration_stage_id' => (string) $phaseOne->id,
        ];

        return [
            'payload' => [
                'variables' => [
                    'video_length' => $brief['video_length'],
                    'aspect_ratio' => $brief['aspect_ratio'],
                    'platform' => $brief['platform'],
                    'tone_notes' => $brief['tone_notes'],
                ],
                'dna' => $dna,
                'yacht_bible' => $bible,
                'anchors' => $anchors,
                'build_states' => $states,
                'canonical' => $canonical,
                'source_identity' => $identity,
                'channel_history' => $history,
            ],
            'source' => $source,
            'context' => [
                'format' => $this->format(),
                'video_length' => $brief['video_length'],
                'rooms' => array_values(array_map(static fn (array $room): string => (string) $room['id'], $bible['rooms'])),
                'exterior_zones' => array_values(array_filter(array_map(static fn (mixed $zone): ?string => is_array($zone) && is_string($zone['id'] ?? null) ? $zone['id'] : null, $bible['exterior_zones']))),
                'source_names' => DnaCheck::sourceNames($identity),
                'forbidden' => SuperyachtScriptCheck::forbiddenWords($prompt),
                'policy' => (array) config('video.superyacht.script.check'),
            ],
        ];
    }

    public function sourceProblem(string $projectId, array $source): ?string
    {
        try {
            $sheet = $this->sheets->approvedSheet($projectId, true);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $now = [
            'design_stage_id' => $sheet['design_stage_id'],
            'review_stage_id' => $sheet['review_stage_id'],
            'master_artifact_sha256' => $sheet['master_artifact_sha256'],
        ];
        $pinned = [
            'design_stage_id' => $source['design_stage_id'] ?? null,
            'review_stage_id' => $source['model_sheet']['review_stage_id'] ?? null,
            'master_artifact_sha256' => $source['model_sheet']['master_artifact_sha256'] ?? null,
        ];

        if ($now !== $pinned) {
            return 'Model sheet đã duyệt hoặc bản thiết kế nguồn đã đổi so với lúc viết kịch bản này.';
        }

        try {
            [, , $rawSha256] = $this->pinnedDesign($projectId, $sheet);
            [, $canonicalHash] = $this->sheets->approvedSource($projectId);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        if (! hash_equals((string) ($source['design_raw_sha256'] ?? ''), $rawSha256) || $canonicalHash !== ($source['canonical_hash'] ?? null)) {
            return 'Bản thiết kế nguồn đã ghim không còn nguyên vẹn.';
        }

        return null;
    }

    public function check(?array $response, array $context, array $schema): array
    {
        return SuperyachtScriptCheck::evaluate($response, $context, $schema, (array) $context['policy']);
    }

    public function derive(array $document): array
    {
        return ScriptProjection::of($document);
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

        if (! is_array($data) || ! is_array($data['canonical'] ?? null)) {
            throw new RuntimeException('Phản hồi gốc của bản thiết kế không có data.canonical.');
        }

        return [$design, $data, $rawSha256];
    }

    private function stage(string $projectId, mixed $stageId, PlanningStageName $name, string $label): VideoPlanningStage
    {
        $stage = is_string($stageId) && $stageId !== '' ? VideoPlanningStage::query()->whereKey($stageId)->where('project_id', $projectId)
            ->where('stage', $name->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first() : null;

        return $stage ?? throw new RuntimeException("Không tìm thấy {$label} đã chạy thành công cho dự án này.");
    }

    /** @return array<string, mixed> */
    private function bible(mixed $bible): array
    {
        if (! is_array($bible) || array_is_list($bible)) {
            throw new RuntimeException('yacht_bible của bản thiết kế nguồn không phải object.');
        }

        $picked = [];

        foreach (self::BIBLE_KEYS as $key) {
            if (! array_key_exists($key, $bible)) {
                throw new RuntimeException("yacht_bible của bản thiết kế nguồn thiếu {$key}.");
            }

            $picked[$key] = $bible[$key];
        }

        if (! is_string($picked['name']) || trim($picked['name']) === '' || ! is_array($picked['specs']) || ($picked['specs'] !== [] && array_is_list($picked['specs']))) {
            throw new RuntimeException('yacht_bible.name phải là chuỗi và yacht_bible.specs phải là object.');
        }

        foreach (self::BIBLE_LISTS as $key) {
            if (! is_array($picked[$key]) || ! array_is_list($picked[$key])) {
                throw new RuntimeException("yacht_bible.{$key} phải là danh sách.");
            }
        }

        foreach ($picked['rooms'] as $index => $room) {
            if (! is_array($room) || ! is_string($room['id'] ?? null) || trim($room['id']) === '') {
                throw new RuntimeException("yacht_bible.rooms[{$index}] thiếu id.");
            }
        }

        foreach ($picked['innovations'] as $index => $innovation) {
            if (! is_array($innovation)) {
                throw new RuntimeException("yacht_bible.innovations[{$index}] không phải object.");
            }

            $visible = $innovation['visible'] ?? null;
            $expression = $innovation['visible_expression'] ?? null;

            if ($visible !== null && $expression !== null && $visible !== $expression) {
                throw new RuntimeException("yacht_bible.innovations[{$index}] có cả visible và visible_expression với nội dung khác nhau — sửa ở bản thiết kế.");
            }

            unset($innovation['visible_expression']);

            if (($visible ?? $expression) !== null) {
                $innovation['visible'] = $visible ?? $expression;
            }

            $picked['innovations'][$index] = $innovation;
        }

        return $picked;
    }

    /** @return array<string, mixed> */
    private function anchors(mixed $anchors): array
    {
        if (! is_array($anchors) || array_is_list($anchors)) {
            throw new RuntimeException('anchors của bản thiết kế nguồn không phải object.');
        }

        $picked = [];

        foreach (self::ANCHOR_TEXTS as $key) {
            $picked[$key] = is_string($anchors[$key] ?? null) && trim($anchors[$key]) !== '' ? $anchors[$key] : throw new RuntimeException("anchors.{$key} của bản thiết kế nguồn phải là chuỗi.");
        }

        foreach (self::ANCHOR_MAPS as $key) {
            $value = $anchors[$key] ?? null;
            $picked[$key] = is_array($value) && ($value === [] || ! array_is_list($value)) ? $value : throw new RuntimeException("anchors.{$key} của bản thiết kế nguồn phải là object.");
        }

        return $picked;
    }
}
